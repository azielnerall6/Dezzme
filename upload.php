<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // keeps JSON responses clean (errors still go to your PHP log)
session_start();
include 'config.php';
require_once __DIR__ . '/dz_privacy.php';
require_once __DIR__ . '/dz_collab.php';
require_once __DIR__ . '/dz_vision.php';   // lets a new post be checked by the media AI right after it is saved

/* ================================================================
   VIDEO FIX — every uploaded video is made to play everywhere, and to START FAST
   • H.264 video + AAC audio in an .mp4 (what every phone and browser plays; phone HEVC / .mov is what gave "audio but no picture")
   • the index moved to the front of the file (faststart), so it starts playing before it has all downloaded
   • big videos are shrunk to 720p; a video that is already H.264 + AAC is only re-wrapped (fast, no quality loss)
   Needs ffmpeg on the server. Without it nothing changes: the file is kept exactly as uploaded.
   dz_video_fix('1700000000_1234.mov') → '1700000000_1234.mp4'  (or the same name when it could not be converted)
================================================================ */
if(!function_exists('dz_video_fix')){
    function dz_ffmpeg_path(){
        static $f = null; if($f !== null) return $f;
        $f = '';
        if(!function_exists('exec')) return $f;
        foreach(['ffmpeg', 'C:\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\xampp\\ffmpeg\\bin\\ffmpeg.exe', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $c){
            $o = []; $rc = 1; @exec('"' . $c . '" -version 2>&1', $o, $rc);
            if($rc === 0){ $f = $c; break; }
        }
        return $f;
    }
    function dz_video_fix($name, $dir = null){
        $dir = $dir ?: (__DIR__ . '/uploads/');
        $src = $dir . $name;
        if(!is_file($src)) return $name;
        $ff = dz_ffmpeg_path(); if($ff === '') return $name;
        @set_time_limit(600);
        $o = []; @exec('"' . $ff . '" -hide_banner -i ' . escapeshellarg($src) . ' 2>&1', $o);
        $info = implode("\n", $o);
        $isH264 = (bool)preg_match('/Video: h264/i', $info);
        $hasAud = (bool)preg_match('/Audio:/i', $info);
        $isAac  = !$hasAud || (bool)preg_match('/Audio: aac/i', $info);
        $big    = (bool)preg_match('/, (\d{4,5})x(\d{3,5})/', $info, $m) && max((int)$m[1], (int)$m[2]) > 1290;
        $new = preg_replace('/\.[A-Za-z0-9]+$/', '', $name) . '.mp4';
        $tmp = $dir . 'fix_' . $new;
        if($isH264 && $isAac && !$big) $args = '-c copy -movflags +faststart';
        else $args = '-c:v libx264 -preset veryfast -crf 24 -pix_fmt yuv420p -vf "scale=\'if(gt(iw,ih),min(1280,iw),-2)\':\'if(gt(iw,ih),-2,min(1280,ih))\'" -c:a aac -b:a 128k -ac 2 -movflags +faststart';
        $o = []; $rc = 1;
        @exec('"' . $ff . '" -y -hide_banner -loglevel error -i ' . escapeshellarg($src) . ' ' . $args . ' ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
        if($rc !== 0 || !is_file($tmp) || filesize($tmp) < 1024){ @unlink($tmp); return $name; }
        if($new !== $name) @unlink($src);
        @rename($tmp, $dir . $new);
        return $new;
    }
}

if(!isset($_SESSION['user_id'])){ header("Location: login.php"); exit(); }
$user_id = (int)$_SESSION['user_id'];
$current_user = $user_id;
$message = "";

/* ═══ SUSPENDED / BANNED ACCOUNTS: nothing to work with — blocks the page, and every upload/save request sent straight to it ═══ */
$__rank = '';
try {
    $__st = $conn->prepare("SELECT user_rank FROM users WHERE id=? LIMIT 1");
    $__st->bind_param("i", $user_id); $__st->execute();
    $__r = $__st->get_result()->fetch_assoc(); $__st->close();
    $__rank = strtolower((string)($__r['user_rank'] ?? ''));
} catch (Throwable $e) {}
if ($__rank === 'suspended' || $__rank === 'banned') {
    $__msg = $__rank === 'banned' ? "Your account is banned, so you can't upload." : "Your account is suspended, so you can't upload.";
    $__ajax = $_SERVER['REQUEST_METHOD'] === 'POST' || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    http_response_code(403);
    if ($__ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'ok'=>false,'status'=>'error','suspended'=>true,'msg'=>$__msg,'message'=>$__msg,'error'=>$__msg]);
        exit;
    }
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><title>Upload unavailable</title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',system-ui,sans-serif}
:root{--bg:#070924;--text:#fff;--sub:rgba(255,255,255,.62);--ac:#FF2D2D}
:root[data-theme="light"]{--bg:#f4f5fb;--text:#0b0d24;--sub:rgba(6,7,26,.62)}
body{min-height:100vh;min-height:100dvh;background:var(--bg);color:var(--text);display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
.box{width:100%;max-width:380px;text-align:center}
.ic{width:84px;height:84px;margin:0 auto 18px;border-radius:50%;background:rgba(255,45,45,.12);border:2px solid var(--ac);display:flex;align-items:center;justify-content:center}
.ic svg{width:38px;height:38px;fill:none;stroke:var(--ac);stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
h1{font-size:1.15rem;font-weight:700;margin-bottom:8px}
p{font-size:.85rem;line-height:1.6;color:var(--sub)}
a{display:inline-flex;align-items:center;justify-content:center;height:44px;padding:0 26px;margin-top:22px;border-radius:5px;background:var(--ac);color:#000;font-size:.85rem;font-weight:700;text-decoration:none}
</style></head><body>
<div class="box">
  <div class="ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg></div>
  <h1>Upload not available</h1>
  <p><?= htmlspecialchars($__msg, ENT_QUOTES, 'UTF-8') ?> You can still browse, but posting, highlights and Zeems are turned off until the suspension ends.</p>
  <a href="home.php">Back to home</a>
</div></body></html>
<?php
    exit;
}

/* ═══════════════════════════════════════════════════════════
   HIGHLIGHT HELPERS + HANDLERS (same backend as settings.php,
   so highlights made here and there are the same thing)
═══════════════════════════════════════════════════════════ */
const HL_MAX_MEDIA = 100;
const HL_MAX_TEXT  = 1000;
const HL_DIR = 'uploads/highlights/';
const HL_TEXT_BG = 'Text.jpg';
const HL_USER_URL = 'profile.php?user_id=';
const HL_GROUP_URL = 'group.php?id=';
function hl_json($a){ header('Content-Type: application/json'); echo json_encode($a); exit; }
function hl_fail($m){ hl_json(['success'=>false,'msg'=>$m]); }
function hl_title($s,$max){ $s=trim(strip_tags((string)$s)); return function_exists('mb_substr')?mb_substr($s,0,$max):substr($s,0,$max); }
function hl_text($s,$max){ $s=str_replace(["\r\n","\r"],"\n",(string)$s); $s=trim(strip_tags($s)); return function_exists('mb_substr')?mb_substr($s,0,$max):substr($s,0,$max); }
function hl_unlink($p){ if($p && strpos($p,HL_DIR)===0) @unlink(__DIR__.'/'.$p); }
function hl_fetch($conn,$hid,$uid){ $st=$conn->prepare("SELECT * FROM highlights WHERE id=? AND user_id=? LIMIT 1"); $st->bind_param("ii",$hid,$uid); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r?:null; }
function hl_media($conn,$mid,$hid){ $st=$conn->prepare("SELECT * FROM highlight_media WHERE id=? AND highlight_id=? LIMIT 1"); $st->bind_param("ii",$mid,$hid); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r?:null; }
function hl_count($conn,$hid){ $st=$conn->prepare("SELECT COUNT(*) AS c FROM highlight_media WHERE highlight_id=?"); $st->bind_param("i",$hid); $st->execute(); $c=(int)($st->get_result()->fetch_assoc()['c']??0); $st->close(); return $c; }
function hl_types($kind){
    $img=['image/jpeg'=>['jpg','image'],'image/png'=>['png','image'],'image/gif'=>['gif','image'],'image/webp'=>['webp','image']];
    if($kind==='image') return $img;
    if($kind==='audio'){ $w=['wav','audio']; return ['audio/wav'=>$w,'audio/x-wav'=>$w,'audio/wave'=>$w,'audio/vnd.wave'=>$w]; }
    return $img+['video/mp4'=>['mp4','video'],'video/webm'=>['webm','video'],'video/quicktime'=>['mov','video']];
}
function hl_wav_ok($tmp){
    $h=@file_get_contents($tmp,false,null,0,44);
    if($h===false||strlen($h)<44||substr($h,0,4)!=='RIFF'||substr($h,8,4)!=='WAVE') return false;
    $br=unpack('V',substr($h,28,4))[1]; if($br<=0) return false;
    return ((filesize($tmp)-44)/$br)<=31;
}
function hl_save($f,$allowed,$limit,$prefix,$uid){
    if(!$f||!isset($f['error'])||$f['error']!==UPLOAD_ERR_OK){
        $e=$f['error']??-1;
        return ['ok'=>false,'msg'=>($e===UPLOAD_ERR_INI_SIZE||$e===UPLOAD_ERR_FORM_SIZE)?'File is too large for the server.':'Upload failed.'];
    }
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if(!isset($allowed[$mime])) return ['ok'=>false,'msg'=>'That file type is not allowed.'];
    [$ext,$type]=$allowed[$mime];
    $lim=is_array($limit)?($limit[$type]??0):$limit;
    if($f['size']>$lim) return ['ok'=>false,'msg'=>'File is too large (max '.round($lim/1048576).' MB).'];
    if($type==='audio'&&!hl_wav_ok($f['tmp_name'])) return ['ok'=>false,'msg'=>'Song clips can be 30 seconds at most.'];
    $dir=__DIR__.'/'.HL_DIR; if(!is_dir($dir)) @mkdir($dir,0755,true);
    $name=$prefix.'_'.$uid.'_'.bin2hex(random_bytes(8)).'.'.$ext;
    if(!move_uploaded_file($f['tmp_name'],$dir.$name)) return ['ok'=>false,'msg'=>'Could not save the file.'];
    return ['ok'=>true,'path'=>HL_DIR.$name,'type'=>$type];
}
function hl_ensure_schema($conn){
    if(!empty($_SESSION['hl_schema_v4'])) return;
    try{
        $conn->query("CREATE TABLE IF NOT EXISTS highlights (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(30) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (user_id, created_at))");
        $conn->query("CREATE TABLE IF NOT EXISTS highlight_media (id INT AUTO_INCREMENT PRIMARY KEY, highlight_id INT NOT NULL, user_id INT NOT NULL, file_path VARCHAR(255) NOT NULL, media_type ENUM('image','video','text') NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (highlight_id, id))");
        $need=[
            'highlights'=>['cover_path'=>"VARCHAR(255) NULL",'song_path'=>"VARCHAR(255) NULL",'song_title'=>"VARCHAR(60) NULL",'song_mode'=>"ENUM('whole','each') NOT NULL DEFAULT 'whole'"],
            'highlight_media'=>['audio_path'=>"VARCHAR(255) NULL",'audio_title'=>"VARCHAR(60) NULL",'mute_video'=>"TINYINT(1) NOT NULL DEFAULT 0",'caption'=>"TEXT NULL"],
        ];
        foreach($need as $tbl=>$cols) foreach($cols as $col=>$def){
            $r=$conn->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'");
            if($r&&$r->num_rows===0) $conn->query("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
        }
        $r=$conn->query("SHOW COLUMNS FROM `highlight_media` LIKE 'media_type'");
        if($r&&($row=$r->fetch_assoc())&&stripos((string)$row['Type'],"'text'")===false)
            $conn->query("ALTER TABLE `highlight_media` MODIFY `media_type` ENUM('image','video','text') NOT NULL");
        $_SESSION['hl_schema_v4']=1;
    }catch(Throwable $e){}
}
hl_ensure_schema($conn);

/* ── Polls: reading the form, validating, saving. (Voting + showing polls lives in shared_core.php; same tables.) ── */
const DZ_POLL_MIN=2, DZ_POLL_MAX=5, DZ_POLL_LABEL_MAX=60;
const DZ_POLL_SECONDS=['1h'=>3600,'6h'=>21600,'1d'=>86400,'3d'=>259200,'7d'=>604800,'forever'=>0];
function dz_poll_ensure($conn){
    if(!empty($_SESSION['dz_poll_schema_v1'])) return;
    try{
        $conn->query("CREATE TABLE IF NOT EXISTS polls (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, meme_id INT NOT NULL, ends_at DATETIME NULL DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_polls_meme (meme_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS poll_options (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, poll_id INT UNSIGNED NOT NULL, position TINYINT UNSIGNED NOT NULL, label VARCHAR(60) NOT NULL, KEY k_opt_poll (poll_id, position)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS poll_votes (poll_id INT UNSIGNED NOT NULL, user_id INT NOT NULL, option_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (poll_id, user_id), KEY k_vote_option (option_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_SESSION['dz_poll_schema_v1']=1;
    }catch(Throwable $e){ error_log('[dz poll] schema: '.$e->getMessage()); }
}
/* form fields: poll_options[] (2–5 choices, empty boxes ignored) + poll_duration → ['ok'=>true,'none'=>true] | ['ok'=>true,'options'=>[..],'seconds'=>n] | ['ok'=>false,'error'=>'..'] */
function dz_poll_parse_input($src){
    $raw=$src['poll_options']??[]; if(!is_array($raw)) $raw=[];
    $labels=[]; $seen=[];
    foreach($raw as $o){
        if(!is_string($o)) continue;
        $o=trim(preg_replace('/\s+/u',' ',$o)); if($o==='') continue;
        if(mb_strlen($o,'UTF-8')>DZ_POLL_LABEL_MAX) return ['ok'=>false,'error'=>'Poll choices can be at most '.DZ_POLL_LABEL_MAX.' characters.'];
        $k=mb_strtolower($o,'UTF-8');
        if(isset($seen[$k])) return ['ok'=>false,'error'=>'Poll choices must be different from each other.'];
        $seen[$k]=true; $labels[]=$o;
    }
    if(!$labels) return ['ok'=>true,'none'=>true];
    if(count($labels)<DZ_POLL_MIN) return ['ok'=>false,'error'=>'A poll needs at least '.DZ_POLL_MIN.' choices.'];
    if(count($labels)>DZ_POLL_MAX) return ['ok'=>false,'error'=>'A poll can have at most '.DZ_POLL_MAX.' choices.'];
    $d=$src['poll_duration']??'forever';
    if(!is_string($d)||!array_key_exists($d,DZ_POLL_SECONDS)) return ['ok'=>false,'error'=>'Pick how long the poll stays open.'];
    return ['ok'=>true,'options'=>$labels,'seconds'=>DZ_POLL_SECONDS[$d]];
}
function dz_poll_create($conn,$meme_id,array $labels,$seconds){
    $meme_id=(int)$meme_id; $seconds=max(0,(int)$seconds);
    if($meme_id<=0||count($labels)<DZ_POLL_MIN||count($labels)>DZ_POLL_MAX) return 0;
    try{
        dz_poll_ensure($conn);
        if($seconds>0){ $st=$conn->prepare("INSERT INTO polls (meme_id, ends_at) VALUES (?, DATE_ADD(NOW(), INTERVAL ? SECOND))"); $st->bind_param("ii",$meme_id,$seconds); }
        else { $st=$conn->prepare("INSERT INTO polls (meme_id, ends_at) VALUES (?, NULL)"); $st->bind_param("i",$meme_id); }
        $st->execute(); $pid=(int)$conn->insert_id; $st->close();
        if($pid<=0) return 0;
        $os=$conn->prepare("INSERT INTO poll_options (poll_id, position, label) VALUES (?, ?, ?)");
        foreach(array_values($labels) as $i=>$lab){ $pos=$i+1; $lab=mb_substr((string)$lab,0,DZ_POLL_LABEL_MAX,'UTF-8'); $os->bind_param("iis",$pid,$pos,$lab); $os->execute(); }
        $os->close();
        return $pid;
    }catch(Throwable $e){ error_log('[dz poll] create failed: '.$e->getMessage()); return 0; }
}

/* ═══════════════════════════════════════════════════════════
   POST COVER PHOTO
   An optional custom thumbnail for any post that is NOT a text
   post. Saved in uploads/ as cover_<user>_<random>.<ext> and
   stored in memes.cover_image.
═══════════════════════════════════════════════════════════ */
function cover_save($f,$uid){
    if(!$f||!isset($f['error'])||$f['error']!==UPLOAD_ERR_OK){
        $e=$f['error']??-1;
        return ['ok'=>false,'msg'=>($e===UPLOAD_ERR_INI_SIZE||$e===UPLOAD_ERR_FORM_SIZE)?'The cover photo is too large for the server.':'The cover photo upload failed.'];
    }
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $map=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if(!isset($map[$mime])) return ['ok'=>false,'msg'=>'The cover photo must be a JPG, PNG, GIF or WebP image.'];
    if($f['size']>10*1048576) return ['ok'=>false,'msg'=>'The cover photo must be under 10 MB.'];
    $dir=__DIR__.'/uploads/'; if(!is_dir($dir)) @mkdir($dir,0755,true);
    $name='cover_'.(int)$uid.'_'.bin2hex(random_bytes(8)).'.'.$map[$mime];
    if(!move_uploaded_file($f['tmp_name'],$dir.$name)) return ['ok'=>false,'msg'=>'Could not save the cover photo.'];
    return ['ok'=>true,'name'=>$name];
}
function cover_unlink($n){
    $n=basename((string)$n);
    if($n!==''&&strpos($n,'cover_')===0) @unlink(__DIR__.'/uploads/'.$n);
}

/* ═══════════════════════════════════════════════════════════
   TAGS & MENTIONS ENFORCEMENT
   settings.php only SAVES the choices in `user_privacy`
   (tag_who, mention_who, tag_approve). This is where they are
   actually checked, every time someone tags or @mentions a person:
     - 'everyone' → allowed
     - 'tracked'  → only people that person tracks (they follow you back)
     - 'none'     → nobody
   Blocked accounts can never tag or mention the person who blocked them.
   You can always tag / mention yourself.
═══════════════════════════════════════════════════════════ */
function tag_ensure_schema($conn){
    if(!empty($_SESSION['tag_schema_v7'])) return;
    try{
        $conn->query("CREATE TABLE IF NOT EXISTS meme_tags (id INT AUTO_INCREMENT PRIMARY KEY, meme_id INT NOT NULL, tagged_user_id INT NOT NULL, tagged_by INT NOT NULL, status ENUM('pending','approved') NOT NULL DEFAULT 'approved', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_tag (meme_id, tagged_user_id), INDEX (tagged_user_id, status))");
        $r=$conn->query("SHOW COLUMNS FROM `memes` LIKE 'feature_ok'");
        if($r&&$r->num_rows===0) $conn->query("ALTER TABLE `memes` ADD COLUMN `feature_ok` TINYINT(1) NOT NULL DEFAULT 1");
        $r=$conn->query("SHOW COLUMNS FROM `memes` LIKE 'cover_image'");
        if($r&&$r->num_rows===0) $conn->query("ALTER TABLE `memes` ADD COLUMN `cover_image` VARCHAR(255) NULL DEFAULT NULL");
        // hide-count switches (0 = show, 1 = hide). Other pages must check these when they show counts.
        foreach(['hide_likes','hide_reposts','hide_saves','hide_shares'] as $hc){
            $r=$conn->query("SHOW COLUMNS FROM `memes` LIKE '$hc'");
            if($r&&$r->num_rows===0) $conn->query("ALTER TABLE `memes` ADD COLUMN `$hc` TINYINT(1) NOT NULL DEFAULT 0");
        }
        // comments switch (0 = people can comment, 1 = comments turned off by the poster)
        $r=$conn->query("SHOW COLUMNS FROM `memes` LIKE 'comments_off'");
        if($r&&$r->num_rows===0) $conn->query("ALTER TABLE `memes` ADD COLUMN `comments_off` TINYINT(1) NOT NULL DEFAULT 0");
        $_SESSION['tag_schema_v7']=1;
    }catch(Throwable $e){}
}
tag_ensure_schema($conn);

// which memes belong to the current person (their own posts)
function dz_scope($a=''){
    global $current_user;
    $p = $a!=='' ? $a.'.' : '';
    return "{$p}uploaded_by=".(int)$current_user;
}
// which posts the current person may edit or delete (their own)
function dz_edit_scope($a=''){
    return dz_scope($a);
}

// ONE place that removes a post and everything attached to it (files, media, sound, ad, tags, likes, reposts, saves, comments, group posts, media-AI scores and topic words).
if(!function_exists('dz_remove_post')){
function dz_remove_post($conn,$pid,$row=null){
    $pid=(int)$pid;
    try{
        if($row===null){
            $r=$conn->query("SELECT file_name, cover_image FROM memes WHERE id=$pid LIMIT 1");
            $row=$r?$r->fetch_assoc():null;
        }
        if(!$row) return false;
        $files=[];
        if(!empty($row['file_name'])) $files[]=$row['file_name'];
        try{
            $rs=$conn->query("SELECT file_name FROM meme_media WHERE meme_id=$pid");
            if($rs) while($x=$rs->fetch_assoc()) $files[]=$x['file_name'];
        }catch(Throwable $e){}
        foreach(['meme_media','meme_audio','meme_ads','meme_tags','likes','reposts','favorites','comments','group_posts','dz_media_analysis','dz_meme_terms'] as $tb){
            try{ $conn->query("DELETE FROM `$tb` WHERE meme_id=$pid"); }catch(Throwable $e){}
        }
        try{ $conn->query("UPDATE memes SET connected_meme_id=NULL, connected_label=NULL WHERE connected_meme_id=$pid"); }catch(Throwable $e){}
        $conn->query("DELETE FROM memes WHERE id=$pid");
        foreach(array_unique($files) as $f){
            $bn=basename((string)$f);
            if($bn!==''&&strcasecmp($bn,'Text.jpg')!==0) @unlink(__DIR__.'/uploads/'.$bn);
        }
        cover_unlink($row['cover_image']??'');
        return true;
    }catch(Throwable $e){ return false; }
}
}

// one person's saved choice (defaults to 'everyone' if they never changed it or the table does not exist yet)
function pv_val($conn,$uid,$field){
    static $cache=[];
    $uid=(int)$uid;
    if(!isset($cache[$uid])){
        $cache[$uid]=['tag_who'=>'everyone','mention_who'=>'everyone','tag_approve'=>0,'mention_feature'=>0];
        try{
            $st=$conn->prepare("SELECT tag_who, mention_who, tag_approve, mention_feature FROM user_privacy WHERE user_id=? LIMIT 1");
            $st->bind_param("i",$uid); $st->execute();
            $r=$st->get_result()->fetch_assoc(); $st->close();
            if($r) foreach($r as $k=>$v) if($v!==null) $cache[$uid][$k]=$v;
        }catch(Throwable $e){}
    }
    return $cache[$uid][$field]??null;
}
// does $a track $b?
function pv_tracks($conn,$a,$b){
    try{
        $st=$conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=? LIMIT 1");
        $st->bind_param("ii",$a,$b); $st->execute();
        $ok=$st->get_result()->num_rows>0; $st->close();
        return $ok;
    }catch(Throwable $e){ return false; }
}
// did $target block $actor?
function pv_isblocked($conn,$target,$actor){
    try{
        $st=$conn->prepare("SELECT 1 FROM blocks WHERE blocker_id=? AND blocked_id=? LIMIT 1");
        $st->bind_param("ii",$target,$actor); $st->execute();
        $ok=$st->get_result()->num_rows>0; $st->close();
        return $ok;
    }catch(Throwable $e){ return false; }
}
// '' = allowed, 'tracked' = only people they track, 'none' = nobody. $field = tag_who | mention_who
function pv_reason($conn,$target,$actor,$field){
    $target=(int)$target; $actor=(int)$actor;
    if($target===$actor) return '';
    if(pv_isblocked($conn,$target,$actor)) return 'none';
    $v=pv_val($conn,$target,$field);
    if($v==='none') return 'none';
    if($v==='tracked' && !pv_tracks($conn,$target,$actor)) return 'tracked';
    return '';
}
function pv_msg($name,$reason,$kind){
    $what=($kind==='tag')?'tags':'@mentions';
    return '@'.$name.($reason==='tracked'?" doesn't allow $what from everyone.":" doesn't allow $what.");
}
// every @name inside a text (lowercase name => name as typed)
function pv_names($text){
    $out=[];
    if(preg_match_all('/@([A-Za-z0-9_.]{2,40})/u',(string)$text,$mm)){
        foreach($mm[1] as $n){ $n=rtrim($n,'.'); if(strlen($n)>=2) $out[strtolower($n)]=$n; }
    }
    return array_slice($out,0,30,true);
}
// mentions in $text that the person does not allow. Mentions already in $old are skipped,
// so editing an old caption never breaks because someone changed their settings later.
function pv_text_issues($conn,$text,$actor,$old=''){
    $issues=[]; $skip=pv_names($old);
    foreach(pv_names($text) as $low=>$n){
        if(isset($skip[$low])) continue;
        try{
            $st=$conn->prepare("SELECT id FROM users WHERE username=? OR nickname=? LIMIT 1");
            $st->bind_param("ss",$n,$n); $st->execute();
            $u=$st->get_result()->fetch_assoc(); $st->close();
        }catch(Throwable $e){ $u=null; }
        if(!$u) continue;
        $r=pv_reason($conn,(int)$u['id'],$actor,'mention_who');
        if($r!=='') $issues[]=['name'=>$n,'msg'=>pv_msg($n,$r,'mention')];
    }
    return $issues;
}
function pv_text_guard($conn,$text,$actor,$old=''){
    $msgs=array_map(function($i){ return $i['msg']; },pv_text_issues($conn,$text,$actor,$old));
    return implode(' ',array_slice($msgs,0,2));
}
function pv_ids($raw){
    return array_slice(array_values(array_unique(array_filter(array_map('intval',explode(',',(string)$raw))))),0,20);
}
// tags: returns the ids that may be tagged + an error text for the ones that may not.
// People already tagged ($existing) are kept as they are.
function pv_tag_check($conn,$ids,$actor,$existing=[]){
    $ok=[]; $err=[];
    foreach($ids as $id){
        $id=(int)$id;
        if($id<=0||$id===(int)$actor) continue;
        if(in_array($id,$existing,true)){ $ok[]=$id; continue; }
        $st=$conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
        $st->bind_param("i",$id); $st->execute();
        $u=$st->get_result()->fetch_assoc(); $st->close();
        if(!$u) continue;
        $r=pv_reason($conn,$id,$actor,'tag_who');
        if($r!==''){ $err[]=pv_msg($u['username'],$r,'tag'); continue; }
        $ok[]=$id;
    }
    return ['ids'=>$ok,'err'=>implode(' ',array_slice($err,0,2))];
}
function pv_tag_status($conn,$tid){ return ((int)pv_val($conn,$tid,'tag_approve')===1)?'pending':'approved'; }

// privacy level of a person (same logic as settings.php): public | limited | private | full
function pv_level($conn,$uid){
    $uid=(int)$uid; $lv=null; $flag=0;
    try{
        $st=$conn->prepare("SELECT privacy_level FROM user_privacy WHERE user_id=? LIMIT 1");
        $st->bind_param("i",$uid); $st->execute();
        $r=$st->get_result()->fetch_assoc(); $st->close();
        if($r&&$r['privacy_level']!==null) $lv=(string)$r['privacy_level'];
    }catch(Throwable $e){}
    try{
        $st=$conn->prepare("SELECT is_fully_private FROM user_settings WHERE user_id=? LIMIT 1");
        $st->bind_param("i",$uid); $st->execute();
        $r=$st->get_result()->fetch_assoc(); $st->close();
        $flag=!empty($r['is_fully_private'])?1:0;
    }catch(Throwable $e){}
    if($flag&&!in_array($lv,['private','full'],true)) $lv='full';
    if(!$flag&&in_array($lv,['private','full'],true)) $lv='public';
    return $lv?:($flag?'full':'public');
}
// FEATURING (set in Settings > Tags and mentions > Reach, nothing to switch on here):
// upload.php only works out whether a post CAN be featured and saves it in memes.feature_ok.
function pv_feature_issues($conn,$text,$actor,$old=''){
    $msgs=[]; $skip=pv_names($old);
    foreach(pv_names($text) as $low=>$n){
        if(isset($skip[$low])) continue;
        try{
            $st=$conn->prepare("SELECT id FROM users WHERE username=? OR nickname=? LIMIT 1");
            $st->bind_param("ss",$n,$n); $st->execute();
            $u=$st->get_result()->fetch_assoc(); $st->close();
        }catch(Throwable $e){ $u=null; }
        if(!$u) continue;
        $id=(int)$u['id'];
        if($id===(int)$actor) continue;
        if((int)pv_val($conn,$id,'mention_feature')!==1) $msgs[]='@'.$n." doesn't allow memes that mention them to be featured.";
        elseif(!in_array(pv_level($conn,$id),['public','limited'],true)) $msgs[]='@'.$n." has a private profile, so memes that mention them can't be featured.";
    }
    return $msgs;
}
function pv_feature_guard($conn,$text,$actor,$old=''){
    return implode(' ',array_slice(pv_feature_issues($conn,$text,$actor,$old),0,2));
}

function pv_pending_tags($conn,$uid){
    $out=[];
    try{
        $st=$conn->prepare("SELECT t.id, t.meme_id, u.username, u.profile_pic, m.file_name, m.type, m.caption FROM meme_tags t JOIN users u ON u.id=t.tagged_by JOIN memes m ON m.id=t.meme_id WHERE t.tagged_user_id=? AND t.status='pending' ORDER BY t.id DESC LIMIT 50");
        $st->bind_param("i",$uid); $st->execute(); $rs=$st->get_result();
        while($r=$rs->fetch_assoc()) $out[]=['id'=>(int)$r['id'],'meme_id'=>(int)$r['meme_id'],'by'=>$r['username'],'pic'=>(string)($r['profile_pic']??''),'file'=>(string)$r['file_name'],'type'=>(string)$r['type'],'caption'=>(string)($r['caption']??'')];
        $st->close();
    }catch(Throwable $e){}
    return $out;
}

/* ═══ TAG PEOPLE: search + review (Tag people sheet and Review tags) ═══ */
dz_collab_handle_request($conn,$user_id);
/* the user's saved default post settings pre-fill the switches below */
$DZ_DEF=['def_hide_likes'=>0,'def_hide_reposts'=>0,'def_hide_saves'=>0,'def_hide_shares'=>0,'def_ai_label'=>0,'def_allow_download'=>1];
try{ dz_priv_ensure($conn); $r=$conn->query("SELECT def_hide_likes,def_hide_reposts,def_hide_saves,def_hide_shares,def_ai_label,def_allow_download FROM user_settings WHERE user_id=".(int)$user_id." LIMIT 1"); if($r instanceof mysqli_result&&($d=$r->fetch_assoc())) $DZ_DEF=array_map('intval',$d); }catch(Throwable $e){}
if(isset($_POST['tag_action'])){
    try{
        if((string)$_POST['tag_action']==='search'){
            $q=ltrim(trim((string)($_POST['q']??'')),'@'); $out=[];
            if(strlen($q)>=2){
                $like='%'.addcslashes($q,'%_\\').'%';
                $st=$conn->prepare("SELECT id, username, nickname, profile_pic FROM users WHERE (username LIKE ? OR nickname LIKE ?) AND id<>? ORDER BY username ASC LIMIT 12");
                $st->bind_param("ssi",$like,$like,$current_user); $st->execute();
                $res=$st->get_result();
                while($u=$res->fetch_assoc()){
                    $r=pv_reason($conn,(int)$u['id'],$current_user,'tag_who');
                    $out[]=['id'=>(int)$u['id'],'username'=>$u['username'],'nickname'=>(string)($u['nickname']??''),
                        'pic'=>(string)($u['profile_pic']??''),'ok'=>($r===''),'why'=>($r===''?'':pv_msg($u['username'],$r,'tag'))];
                }
                $st->close();
            }
            hl_json(['success'=>true,'users'=>$out]);
        }
        // REVIEW TAGS: tags other people made of YOU that wait for your approval
        if((string)$_POST['tag_action']==='pending'){
            hl_json(['success'=>true,'tags'=>pv_pending_tags($conn,$current_user)]);
        }
        if((string)$_POST['tag_action']==='accept'||(string)$_POST['tag_action']==='remove'){
            $tid=(int)($_POST['tag_id']??0);
            if((string)$_POST['tag_action']==='accept') $st=$conn->prepare("UPDATE meme_tags SET status='approved' WHERE id=? AND tagged_user_id=?");
            else $st=$conn->prepare("DELETE FROM meme_tags WHERE id=? AND tagged_user_id=?");
            $st->bind_param("ii",$tid,$current_user); $st->execute();
            $n=$st->affected_rows; $st->close();
            $n>0?hl_json(['success'=>true]):hl_fail('That tag is no longer waiting.');
        }
        hl_fail('Unknown action.');
    }catch(Throwable $e){ hl_fail('Something went wrong.'); }
}

if(isset($_POST['hl_action'])){
    try{
        $act=(string)$_POST['hl_action']; $MB=1048576;
        if($act==='create'){
            $title=hl_title($_POST['title']??'',30);
            if($title==='') hl_fail('Give your highlight a name.');
            $mode=(($_POST['mode']??'')==='each')?'each':'whole';
            $st=$conn->prepare("INSERT INTO highlights (user_id, title, song_mode) VALUES (?,?,?)");
            $st->bind_param("iss",$current_user,$title,$mode);
            $ok=$st->execute(); $new_id=$conn->insert_id; $st->close();
            $ok?hl_json(['success'=>true,'id'=>$new_id]):hl_fail('Could not create highlight.');
        }
        if($act==='resolve'){
            $text=hl_text($_POST['text']??'',HL_MAX_TEXT); $users=[]; $groups=[];
            if(preg_match_all('/@([A-Za-z0-9_.]{2,40})/u',$text,$mm)){
                $names=[]; foreach($mm[1] as $n){ $n=rtrim($n,'.'); if(strlen($n)>=2) $names[strtolower($n)]=$n; }
                foreach(array_slice($names,0,30,true) as $low=>$n){
                    $st=$conn->prepare("SELECT id FROM users WHERE username=? OR nickname=? LIMIT 1");
                    $st->bind_param("ss",$n,$n); $st->execute(); $u=$st->get_result()->fetch_assoc(); $st->close();
                    if($u) $users[$low]=(int)$u['id'];
                }
            }
            if($text!==''){
                try{
                    $st=$conn->prepare("SELECT id, title FROM `groups` WHERE CHAR_LENGTH(title) >= 2 AND LOCATE(LOWER(title), LOWER(?)) > 0 ORDER BY CHAR_LENGTH(title) DESC LIMIT 20");
                    $st->bind_param("s",$text); $st->execute(); $res=$st->get_result();
                    while($g=$res->fetch_assoc()) $groups[]=['id'=>(int)$g['id'],'name'=>$g['title']];
                    $st->close();
                }catch(Throwable $e){ $groups=[]; }
            }
            // mentions this person is not allowed to make (from the other person's Tags and mentions settings)
            $blocked=pv_text_issues($conn,$text,$current_user);
            hl_json(['success'=>true,'users'=>(object)$users,'groups'=>$groups,'blocked'=>$blocked]);
        }
        $hid=(int)($_POST['highlight_id']??0);
        $H=$hid?hl_fetch($conn,$hid,$current_user):null;
        if(!$H) hl_fail('Highlight not found.');
        $mid=(int)($_POST['media_id']??0);
        $M=$mid?hl_media($conn,$mid,$hid):null;

        switch($act){
            case 'get':
                $st=$conn->prepare("SELECT id, file_path, media_type, caption, audio_path, audio_title, mute_video, created_at FROM highlight_media WHERE highlight_id=? ORDER BY id ASC");
                $st->bind_param("i",$hid); $st->execute(); $res=$st->get_result(); $media=[];
                while($m=$res->fetch_assoc()) $media[]=['id'=>(int)$m['id'],'path'=>$m['file_path'],'type'=>$m['media_type'],'caption'=>(string)($m['caption']??''),'audio'=>$m['audio_path'],'audio_title'=>$m['audio_title'],'mute'=>(int)$m['mute_video'],'added'=>date('j M Y',strtotime($m['created_at']))];
                $st->close();
                hl_json(['success'=>true,'h'=>['id'=>(int)$H['id'],'title'=>$H['title'],'cover'=>$H['cover_path'],'song'=>$H['song_path'],'song_title'=>$H['song_title'],'mode'=>($H['song_mode']??'whole'),'media'=>$media]]);
            case 'set_mode':
                $mode=(($_POST['mode']??'')==='each')?'each':'whole';
                $st=$conn->prepare("UPDATE highlights SET song_mode=? WHERE id=?"); $st->bind_param("si",$mode,$hid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'rename':
                $title=hl_title($_POST['title']??'',30);
                if($title==='') hl_fail('Give your highlight a name.');
                $st=$conn->prepare("UPDATE highlights SET title=? WHERE id=?"); $st->bind_param("si",$title,$hid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'add_media':
                if(hl_count($conn,$hid)>=HL_MAX_MEDIA) hl_fail('A highlight can hold up to '.HL_MAX_MEDIA.' media.');
                // check @mentions BEFORE the file is saved
                $cap=hl_text($_POST['caption']??'',HL_MAX_TEXT);
                $mg=pv_text_guard($conn,$cap,$current_user); if($mg!=='') hl_fail($mg);
                $r=hl_save($_FILES['media']??null,hl_types('media'),['image'=>10*$MB,'video'=>50*$MB],'hl',$current_user);
                if(!$r['ok']) hl_fail($r['msg']);
                $st=$conn->prepare("INSERT INTO highlight_media (highlight_id, user_id, file_path, media_type, caption) VALUES (?,?,?,?,?)");
                $st->bind_param("iisss",$hid,$current_user,$r['path'],$r['type'],$cap);
                $ok=$st->execute(); $nid=$conn->insert_id; $st->close();
                if(!$ok){ hl_unlink($r['path']); hl_fail('Could not save the file.'); }
                hl_json(['success'=>true,'id'=>$nid]);
            case 'add_text':
                if(hl_count($conn,$hid)>=HL_MAX_MEDIA) hl_fail('A highlight can hold up to '.HL_MAX_MEDIA.' media.');
                $txt=hl_text($_POST['text']??'',HL_MAX_TEXT);
                if($txt==='') hl_fail('Write some text first.');
                $mg=pv_text_guard($conn,$txt,$current_user); if($mg!=='') hl_fail($mg);
                $st=$conn->prepare("INSERT INTO highlight_media (highlight_id, user_id, file_path, media_type, caption) VALUES (?,?,'','text',?)");
                $st->bind_param("iis",$hid,$current_user,$txt); $ok=$st->execute(); $nid=$conn->insert_id; $st->close();
                if(!$ok) hl_fail('Could not save the text.');
                hl_json(['success'=>true,'id'=>$nid]);
            case 'set_caption':
                if(!$M) hl_fail('Media not found.');
                $txt=hl_text($_POST['text']??'',HL_MAX_TEXT);
                if($M['media_type']==='text'&&$txt==='') hl_fail('A text media needs some text.');
                // only NEW @mentions are checked, people already in the old text stay
                $mg=pv_text_guard($conn,$txt,$current_user,(string)($M['caption']??'')); if($mg!=='') hl_fail($mg);
                $st=$conn->prepare("UPDATE highlight_media SET caption=? WHERE id=?"); $st->bind_param("si",$txt,$mid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'del_media':
                if(!$M) hl_fail('Media not found.');
                if(hl_count($conn,$hid)<=1) hl_fail('A highlight needs at least one media. Delete the highlight instead.');
                hl_unlink($M['file_path']); hl_unlink($M['audio_path']);
                $st=$conn->prepare("DELETE FROM highlight_media WHERE id=? AND highlight_id=?"); $st->bind_param("ii",$mid,$hid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'set_cover':
                $r=hl_save($_FILES['cover']??null,hl_types('image'),10*$MB,'cover',$current_user);
                if(!$r['ok']) hl_fail($r['msg']);
                hl_unlink($H['cover_path']);
                $st=$conn->prepare("UPDATE highlights SET cover_path=? WHERE id=?"); $st->bind_param("si",$r['path'],$hid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'rm_cover':
                hl_unlink($H['cover_path']);
                $st=$conn->prepare("UPDATE highlights SET cover_path=NULL WHERE id=?"); $st->bind_param("i",$hid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'set_song':
                $r=hl_save($_FILES['song']??null,hl_types('audio'),7*$MB,'song',$current_user);
                if(!$r['ok']) hl_fail($r['msg']);
                hl_unlink($H['song_path']); $t=hl_title($_POST['title']??'',60);
                $st=$conn->prepare("UPDATE highlights SET song_path=?, song_title=? WHERE id=?"); $st->bind_param("ssi",$r['path'],$t,$hid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'rm_song':
                hl_unlink($H['song_path']);
                $st=$conn->prepare("UPDATE highlights SET song_path=NULL, song_title=NULL WHERE id=?"); $st->bind_param("i",$hid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'media_song':
                if(!$M) hl_fail('Media not found.');
                $r=hl_save($_FILES['song']??null,hl_types('audio'),7*$MB,'msong',$current_user);
                if(!$r['ok']) hl_fail($r['msg']);
                hl_unlink($M['audio_path']); $t=hl_title($_POST['title']??'',60);
                $st=$conn->prepare("UPDATE highlight_media SET audio_path=?, audio_title=? WHERE id=?"); $st->bind_param("ssi",$r['path'],$t,$mid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'media_song_rm':
                if(!$M) hl_fail('Media not found.');
                hl_unlink($M['audio_path']);
                $st=$conn->prepare("UPDATE highlight_media SET audio_path=NULL, audio_title=NULL WHERE id=?"); $st->bind_param("i",$mid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'media_mute':
                if(!$M) hl_fail('Media not found.');
                $mute=!empty($_POST['mute'])?1:0;
                $st=$conn->prepare("UPDATE highlight_media SET mute_video=? WHERE id=?"); $st->bind_param("ii",$mute,$mid); $st->execute(); $st->close();
                hl_json(['success'=>true]);
            case 'delete':
                $st=$conn->prepare("SELECT file_path, audio_path FROM highlight_media WHERE highlight_id=?");
                $st->bind_param("i",$hid); $st->execute(); $res=$st->get_result();
                while($r=$res->fetch_assoc()){ hl_unlink($r['file_path']); hl_unlink($r['audio_path']); }
                $st->close(); hl_unlink($H['cover_path']); hl_unlink($H['song_path']);
                $st=$conn->prepare("DELETE FROM highlight_media WHERE highlight_id=?"); $st->bind_param("i",$hid); $st->execute(); $st->close();
                $st=$conn->prepare("DELETE FROM highlights WHERE id=? AND user_id=?"); $st->bind_param("ii",$hid,$current_user); $st->execute(); $st->close();
                hl_json(['success'=>true]);
        }
        hl_fail('Unknown action.');
    }catch(Throwable $e){ hl_fail('Something went wrong.'); }
}

/* ═══════════════════════════════════════════════════════════
   EDIT EXISTING POST — edits a post that is already on
   your profile grid: caption, tagged people, paid partnership,
   AI label, cover photo and the connected Zeem.
   Only the owner of the post.
═══════════════════════════════════════════════════════════ */
if(isset($_POST['post_action'])){
    try{
        $pact=(string)$_POST['post_action'];
        $pid=(int)($_POST['post_id']??0);
        $st=$conn->prepare("SELECT * FROM memes WHERE id=? AND ".dz_edit_scope()." LIMIT 1");
        $st->bind_param("i",$pid); $st->execute(); $prow=$st->get_result()->fetch_assoc(); $st->close();
        if(!$prow) hl_fail('Post not found.');

        if($pact==='edit'){
            $cap=trim((string)($_POST['caption']??''));
            $cap=function_exists('mb_substr')?mb_substr($cap,0,2200):substr($cap,0,2200);
            if($prow['type']==='text'&&$cap==='') hl_fail('A text post needs some text.');

            // @mentions: only NEW ones are checked (people already in the old caption stay)
            $mg=pv_text_guard($conn,$cap,$current_user,(string)($prow['caption']??''));
            if($mg!=='') hl_fail($mg);

            // can this post be featured? only if everyone @mentioned allows it right now
            $feat_ok=count(pv_feature_issues($conn,$cap,$current_user))===0?1:0;

            // tags: only people newly added are checked, people already tagged stay
            $tags_sent=isset($_POST['tagged_users']);
            $tag_existing=[]; $tg=['ids'=>[],'err'=>''];
            if($tags_sent){
                try{
                    $st=$conn->prepare("SELECT tagged_user_id FROM meme_tags WHERE meme_id=?");
                    $st->bind_param("i",$pid); $st->execute(); $rs=$st->get_result();
                    while($x=$rs->fetch_assoc()) $tag_existing[]=(int)$x['tagged_user_id'];
                    $st->close();
                }catch(Throwable $e){}
                $tg=pv_tag_check($conn,pv_ids($_POST['tagged_users']),$current_user,$tag_existing);
                if($tg['err']!=='') hl_fail($tg['err']);
            }

            $isp=(!empty($_POST['is_partnership'])&&$_POST['is_partnership']==='1')?1:0;
            $brand=$isp?trim((string)($_POST['partner_brand']??'')):'';
            $brand=function_exists('mb_substr')?mb_substr($brand,0,100):substr($brand,0,100);
            $ai=(!empty($_POST['is_ai_generated'])&&$_POST['is_ai_generated']==='1')?1:0;
            $hl_=(($_POST['hide_likes']??'')==='1')?1:0; $hr_=(($_POST['hide_reposts']??'')==='1')?1:0;
            $hs_=(($_POST['hide_saves']??'')==='1')?1:0; $hsh_=(($_POST['hide_shares']??'')==='1')?1:0;
            $co_=(($_POST['comments_off']??'')==='1')?1:0;

            // connected Zeem must be one of my own posts
            $cid=(int)($_POST['connected_meme_id']??0); $clabel=null;
            if($cid>0&&$cid!==$pid){
                $cc=$conn->prepare("SELECT id FROM memes WHERE id=? AND ".dz_scope()." LIMIT 1");
                $cc->bind_param("i",$cid); $cc->execute();
                $okc=$cc->get_result()->num_rows>0; $cc->close();
                if($okc){
                    $raw=trim((string)($_POST['connected_label']??''));
                    if($raw!=='') $clabel=mb_substr($raw,0,40);
                }else $cid=0;
            }else $cid=0;
            $cidv=$cid>0?$cid:null;

            // COVER PHOTO (never for text posts): a new file replaces the old one, remove_cover=1 deletes it
            $new_cover=null; $rm_cover=false;
            if($prow['type']!=='text'){
                if(isset($_FILES['cover_file'])&&($_FILES['cover_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                    $cr=cover_save($_FILES['cover_file'],$current_user);
                    if(!$cr['ok']) hl_fail($cr['msg']);
                    $new_cover=$cr['name'];
                }elseif(!empty($_POST['remove_cover'])&&$_POST['remove_cover']==='1'){
                    $rm_cover=true;
                }
            }

            // the post was already checked against dz_edit_scope() above, so the updates only need the id
            $up=$conn->prepare("UPDATE memes SET caption=?, is_partnership=?, partner_brand=?, is_ai_generated=?, connected_meme_id=?, connected_label=? WHERE id=?");
            $up->bind_param("sisiisi",$cap,$isp,$brand,$ai,$cidv,$clabel,$pid);
            $ok=$up->execute(); $up->close();
            $final_cover=(string)($prow['cover_image']??'');
            if($ok){
                try{ $f=$conn->prepare("UPDATE memes SET feature_ok=? WHERE id=?"); $f->bind_param("ii",$feat_ok,$pid); $f->execute(); $f->close(); }catch(Throwable $e){}
                try{ $co=$conn->prepare("UPDATE memes SET comments_off=? WHERE id=?"); $co->bind_param("ii",$co_,$pid); $co->execute(); $co->close(); }catch(Throwable $e){}
                try{ $h=$conn->prepare("UPDATE memes SET hide_likes=?, hide_reposts=?, hide_saves=?, hide_shares=? WHERE id=?"); $h->bind_param("iiiii",$hl_,$hr_,$hs_,$hsh_,$pid); $h->execute(); $h->close(); }catch(Throwable $e){}
                if($new_cover!==null||$rm_cover){
                    try{
                        $nv=($new_cover!==null)?$new_cover:null;
                        $c=$conn->prepare("UPDATE memes SET cover_image=? WHERE id=?");
                        $c->bind_param("si",$nv,$pid); $c->execute(); $c->close();
                        cover_unlink($prow['cover_image']??'');
                        $final_cover=($nv!==null)?$nv:'';
                    }catch(Throwable $e){ if($new_cover!==null) cover_unlink($new_cover); }
                }
            }elseif($new_cover!==null){
                cover_unlink($new_cover);
            }

            if($ok&&$tags_sent){
                try{
                    foreach($tag_existing as $eid){
                        if(!in_array($eid,$tg['ids'],true)){
                            $d=$conn->prepare("DELETE FROM meme_tags WHERE meme_id=? AND tagged_user_id=?");
                            $d->bind_param("ii",$pid,$eid); $d->execute(); $d->close();
                        }
                    }
                    foreach($tg['ids'] as $tid){
                        if(in_array($tid,$tag_existing,true)) continue;
                        $stt=pv_tag_status($conn,$tid);
                        $i=$conn->prepare("INSERT IGNORE INTO meme_tags (meme_id, tagged_user_id, tagged_by, status) VALUES (?,?,?,?)");
                        $i->bind_param("iiis",$pid,$tid,$current_user,$stt); $i->execute(); $i->close();
                    }
                }catch(Throwable $e){}
            }
            /* media AI: the caption changed, so refresh the caption's topic words and raise the scores if the new caption has flagged words */
            if($ok&&function_exists('dz_index_caption')&&function_exists('dz_text_flags')&&function_exists('dz_tokens')){
                try{
                    dz_index_caption($conn,$pid,$cap);
                    $fl=dz_text_flags($cap); $fs=(int)$fl['sensitive']; $fp=(int)$fl['political'];
                    if($fs>0||$fp>0){
                        $u2=$conn->prepare("UPDATE dz_media_analysis SET `sensitive`=GREATEST(`sensitive`,?), political=GREATEST(political,?) WHERE meme_id=?");
                        $u2->bind_param("iii",$fs,$fp,$pid); $u2->execute(); $u2->close();
                    }
                }catch(Throwable $e){}
            }
            $ok?hl_json(['success'=>true,'cover'=>$final_cover]):hl_fail('Could not save your changes.');
        }
        if($pact==='media_remove'||$pact==='media_add'){
            if($prow['type']==='text'||!empty($prow['is_ad'])) hl_fail('This post has no media to change.');
            $allowed_m=['jpg','jpeg','png','gif','mp4','webm','mov'];
            $mtype=function($ext) use($allowed_m){ return in_array($ext,['mp4','webm','mov'])?'video':(($ext==='gif')?'gif':'image'); };
            $load=function() use($conn,$pid,$prow){
                $list=[['file'=>(string)$prow['file_name'],'type'=>(string)$prow['type'],'position'=>0]];
                $q=$conn->query("SELECT file_name, type, position FROM meme_media WHERE meme_id=".(int)$pid." ORDER BY position ASC");
                if($q) while($x=$q->fetch_assoc()) $list[]=['file'=>(string)$x['file_name'],'type'=>(string)$x['type'],'position'=>(int)$x['position']];
                return $list;
            };
            $list=$load();
            if($pact==='media_remove'){
                $ix=(int)($_POST['index']??-1);
                if(count($list)<2) hl_fail('A post needs at least one photo or video.');
                if($ix<0||$ix>=count($list)) hl_fail('That media was not found.');
                if($ix===0){
                    $nx=$list[1];
                    $u=$conn->prepare("UPDATE memes SET file_name=?, type=? WHERE id=?"); $u->bind_param("ssi",$nx['file'],$nx['type'],$pid); $u->execute(); $u->close();
                    $d=$conn->prepare("DELETE FROM meme_media WHERE meme_id=? AND position=?"); $d->bind_param("ii",$pid,$nx['position']); $d->execute(); $d->close();
                    @unlink(__DIR__.'/uploads/'.basename($list[0]['file']));
                }else{
                    $gone=$list[$ix];
                    $d=$conn->prepare("DELETE FROM meme_media WHERE meme_id=? AND position=?"); $d->bind_param("ii",$pid,$gone['position']); $d->execute(); $d->close();
                    @unlink(__DIR__.'/uploads/'.basename($gone['file']));
                }
                // keep the extra positions 1..n in order
                $q=$conn->query("SELECT position FROM meme_media WHERE meme_id=".(int)$pid." ORDER BY position ASC"); $n=1;
                if($q) while($x=$q->fetch_assoc()){ $np=$n++; if((int)$x['position']!==$np){ $conn->query("UPDATE meme_media SET position=$np WHERE meme_id=".(int)$pid." AND position=".(int)$x['position']); } }
            }else{
                $room=20-count($list); $added=0; $pos=count($list);   // main file + up to 19 more
                if($room<=0) hl_fail('This post already has the maximum of 20 photos and videos.');
                if(!empty($_FILES['add_media']['name'][0])){
                    $cnt=count($_FILES['add_media']['name']);
                    for($i=0;$i<$cnt&&$added<$room;$i++){
                        if(($_FILES['add_media']['error'][$i]??1)!==0) continue;
                        $ext=strtolower(pathinfo($_FILES['add_media']['name'][$i],PATHINFO_EXTENSION));
                        if(!in_array($ext,$allowed_m)) continue;
                        $en=time().'_'.rand(1000,9999).'_'.$pos.'.'.$ext;
                        if(!move_uploaded_file($_FILES['add_media']['tmp_name'][$i],__DIR__.'/uploads/'.$en)) continue;
                        if(in_array($ext,['mp4','webm','mov'])) $en=dz_video_fix($en);
                        $m=$conn->prepare("INSERT INTO meme_media (meme_id, file_name, type, position) VALUES (?,?,?,?)"); $ty=$mtype($ext);
                        $m->bind_param("issi",$pid,$en,$ty,$pos); $m->execute(); $m->close();
                        $pos++; $added++;
                    }
                }
                if(!$added) hl_fail('No valid photos or videos were added.');
            }
            $prow2=$conn->query("SELECT file_name, type FROM memes WHERE id=".(int)$pid)->fetch_assoc();
            $out=[['file'=>(string)$prow2['file_name'],'type'=>(string)$prow2['type']]];
            $q=$conn->query("SELECT file_name, type FROM meme_media WHERE meme_id=".(int)$pid." ORDER BY position ASC");
            if($q) while($x=$q->fetch_assoc()) $out[]=['file'=>(string)$x['file_name'],'type'=>(string)$x['type']];
            hl_json(['success'=>true,'media'=>$out,'file'=>$out[0]['file'],'ptype'=>$out[0]['type']]);
        }
        if($pact==='delete'){
            // removes the post + files, media, sound, ad, tags, likes, reposts, saves, comments, group posts
            if(!dz_remove_post($conn,$pid,$prow)) hl_fail('Could not delete this post.');
            hl_json(['success'=>true]);
        }
        hl_fail('Unknown action.');
    }catch(Throwable $e){ hl_fail('Something went wrong.'); }
}

/* ═══ ADS: only profiles with a Professional mode type (user_settings.profile_type) may create ads ═══ */
$pro_type='';
try{
    $pt_=$conn->prepare("SELECT profile_type FROM user_settings WHERE user_id=? LIMIT 1");
    if($pt_){ $pt_->bind_param("i",$user_id); $pt_->execute(); $pr_=$pt_->get_result()->fetch_assoc(); $pro_type=trim((string)($pr_['profile_type']??'')); $pt_->close(); }
}catch(Throwable $e){ $pro_type=''; }
$ad_allowed=($pro_type!=='');

/* ═══ PRESELECTED AUDIO (from audio.php "Use Sound") ═══ */
$preselected_audio = null;
if(isset($_GET['audio_id'])){
    $paid=(int)$_GET['audio_id'];
    if($paid>0){
        $par=$conn->prepare("SELECT id, title, artist, file_name FROM audios WHERE id=?");
        $par->bind_param("i",$paid); $par->execute(); $ares=$par->get_result();
        if($ares&&$ares->num_rows) $preselected_audio=$ares->fetch_assoc();
    }
}

/* ═══ ZEEM CONNECT: my own Zeems ═══ */
$my_zeems=[];
try{
    $zr=$conn->query("
        SELECT m.id, m.file_name, m.type, m.caption,
               1 + (SELECT COUNT(*) FROM meme_media mm WHERE mm.meme_id = m.id) AS media_count
        FROM memes m WHERE ".dz_scope('m')." ORDER BY m.created_at DESC LIMIT 50");
    if($zr) while($z=$zr->fetch_assoc()) $my_zeems[]=$z;
}catch(Throwable $e){}

/* ═══════════════════════════════════════════════════════════
   POST UPLOAD HANDLER — answers JSON when sent with ajax=1
   so the page can show a live progress bar.
═══════════════════════════════════════════════════════════ */
if(isset($_POST['upload'])){
    $is_ajax=!empty($_POST['ajax']); $post_ok=false;
    $caption=$_POST['caption_hidden']??""; $post_type=$_POST['post_type']??'image';
    $file_name=""; $type="";

    /* TAGS & MENTIONS: checked first, before any file is saved.
       If someone does not allow it, nothing is uploaded. */
    $tg=pv_tag_check($conn,pv_ids($_POST['tagged_users']??''),$user_id);
    $guard=pv_text_guard($conn,$caption,$user_id);
    if($guard==='') $guard=$tg['err'];
    $feat_ok=count(pv_feature_issues($conn,$caption,$user_id))===0?1:0;
    if($guard!==''){
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'msg'=>$guard]); exit;
    }

    /* COVER PHOTO: any post except a text post. Saved before the rest so a bad cover stops the upload early. */
    $cover_image=null;
    if($post_type!=='text'&&isset($_FILES['cover_file'])&&($_FILES['cover_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
        $cr=cover_save($_FILES['cover_file'],$user_id);
        if(!$cr['ok']){
            header('Content-Type: application/json');
            echo json_encode(['success'=>false,'msg'=>$cr['msg']]); exit;
        }
        $cover_image=$cr['name'];
    }

    $is_partnership=(isset($_POST['is_partnership'])&&$_POST['is_partnership']=='1')?1:0;
    $partner_brand=$is_partnership?trim($_POST['partner_brand']??''):'';
    $is_ai_generated=(isset($_POST['is_ai_generated'])&&$_POST['is_ai_generated']=='1')?1:0;
    $comments_off=(($_POST['comments_off']??'')==='1')?1:0;
    $hide_likes=(($_POST['hide_likes']??'')==='1')?1:0;
    $hide_reposts=(($_POST['hide_reposts']??'')==='1')?1:0;
    $hide_saves=(($_POST['hide_saves']??'')==='1')?1:0;
    $hide_shares=(($_POST['hide_shares']??'')==='1')?1:0;

    $is_ad=(isset($_POST['is_ad'])&&$_POST['is_ad']=='1')?1:0;
    $ad_cta_text=$is_ad?trim($_POST['ad_cta_text']??''):'';
    $ad_cta_url=$is_ad?trim($_POST['ad_cta_url']??''):'';
    $ad_cta_color=$is_ad?trim($_POST['ad_cta_color']??'#FF4C4C'):'';
    $ad_duration_days=$is_ad?(int)($_POST['ad_duration_days']??0):0;
    $ad_pricing=[1=>5.00,3=>12.00,7=>25.00,14=>45.00,30=>80.00];
    $ad_price=($is_ad&&isset($ad_pricing[$ad_duration_days]))?$ad_pricing[$ad_duration_days]:0;
    if($is_ad&&!$ad_allowed){   /* ads need a Professional mode type — checked on the server, whatever the browser sent */
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'msg'=>'Ads are only for profiles with a Professional mode type. Set yours on your profile first.']); exit;
    }

    // connected Zeem must be one of my own posts
    $connected_meme_id=null; $connected_label=null;
    if(!empty($_POST['connected_meme_id'])){
        $cand=(int)$_POST['connected_meme_id'];
        if($cand>0){
            $cchk=$conn->prepare("SELECT id FROM memes WHERE id=? AND ".dz_scope()." LIMIT 1");
            $cchk->bind_param("i",$cand); $cchk->execute(); $cres=$cchk->get_result();
            if($cres&&$cres->num_rows){
                $connected_meme_id=$cand;
                $raw=trim($_POST['connected_label']??'');
                if($raw!=='') $connected_label=mb_substr($raw,0,40);
            }
        }
    }

    $allowed=['jpg','jpeg','png','gif','mp4','webm','mov']; $extra_items=[];

    if($post_type=="text"){ $type="text"; $file_name="Text.jpg"; }
    else{
        $edited=$_POST['edited_image_data']??"";
        if(!empty($edited)&&$post_type=="image"){
            $data=base64_decode(preg_replace('#^data:image/\w+;base64,#i','',$edited));
            $newname=time().'_'.rand(1000,9999).'.jpg';
            file_put_contents("uploads/".$newname,$data);
            $file_name=$newname; $type="image";
            if(is_file(__DIR__.'/dz_thumb.php')){ require_once __DIR__.'/dz_thumb.php'; dz_thumb_url('uploads/'.$newname,480); }
        }elseif(!empty($_FILES['meme_file'])&&$_FILES['meme_file']['error']==0){
            $file=$_FILES['meme_file']; $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
            if(in_array($ext,$allowed)){
                $newname=time().'_'.rand(1000,9999).'.'.$ext;
                move_uploaded_file($file['tmp_name'],"uploads/".$newname);
                if(in_array($ext,['mp4','webm','mov'])) $newname=dz_video_fix($newname);   /* H.264 + AAC, fast start */
                $file_name=$newname;
                if(is_file(__DIR__.'/dz_thumb.php')){ require_once __DIR__.'/dz_thumb.php'; dz_thumb_url('uploads/'.$newname,480); }
                $type=in_array($ext,['mp4','webm','mov'])?'video':(($ext==='gif')?'gif':'image');
            }else $message="Invalid file type.";
        }else $message="Upload failed.";

        if(!$message&&!empty($_FILES['extra_media'])&&!empty($_FILES['extra_media']['name'][0])){
            $position=1; $count=count($_FILES['extra_media']['name']);
            for($i=0;$i<$count;$i++){
                if($position>19) break;
                if($_FILES['extra_media']['error'][$i]!==0) continue;
                $ext=strtolower(pathinfo($_FILES['extra_media']['name'][$i],PATHINFO_EXTENSION));
                if(!in_array($ext,$allowed)) continue;
                $en=time().'_'.rand(1000,9999).'_'.$position.'.'.$ext;
                move_uploaded_file($_FILES['extra_media']['tmp_name'][$i],"uploads/".$en);
                if(in_array($ext,['mp4','webm','mov'])) $en=dz_video_fix($en);
                $extra_items[]=['file_name'=>$en,'type'=>in_array($ext,['mp4','webm','mov'])?'video':(($ext==='gif')?'gif':'image'),'position'=>$position];
                $position++;
            }
        }
    }

    $allowed_audio=['mp3','wav','m4a','ogg','aac'];
    $audio_mode=$_POST['audio_mode']??'none'; $audio_id_to_link=null;
    $music_volume=isset($_POST['music_volume'])?max(0,min(100,(int)$_POST['music_volume'])):80;
    $video_audio_volume=isset($_POST['video_audio_volume'])?max(0,min(100,(int)$_POST['video_audio_volume'])):40;

    if(!$message&&$audio_mode==='upload'&&!empty($_FILES['audio_file'])&&$_FILES['audio_file']['error']==0){
        $af=$_FILES['audio_file']; $aext=strtolower(pathinfo($af['name'],PATHINFO_EXTENSION));
        if(in_array($aext,$allowed_audio)){
            $an='audio_'.time().'_'.rand(1000,9999).'.'.$aext;
            move_uploaded_file($af['tmp_name'],"uploads/".$an);
            $atitle=pathinfo($af['name'],PATHINFO_FILENAME);
            $as=$conn->prepare("INSERT INTO audios (uploader_id, title, file_name, is_original, visibility) VALUES (?,?,?,1,'public')");
            $as->bind_param("iss",$user_id,$atitle,$an);
            if($as->execute()) $audio_id_to_link=$conn->insert_id;
        }
    }elseif($audio_mode==='library'&&!empty($_POST['selected_audio_id'])){
        $audio_id_to_link=(int)$_POST['selected_audio_id'];
    }

    $poll_in=['ok'=>true,'none'=>true];
    if(!$message&&!$is_ad){ $poll_in=dz_poll_parse_input($_POST); if(empty($poll_in['ok'])) $message=$poll_in['error']; }

    if(!$message&&$is_ad){
        if(empty($ad_cta_text)||empty($ad_cta_url)||!filter_var($ad_cta_url,FILTER_VALIDATE_URL)||!isset($ad_pricing[$ad_duration_days]))
            $message="Please complete all Create an AD fields, including a valid destination link.";
    }

    if(!$message){
        /* Needs: memes.is_partnership, partner_brand, is_ad, is_ai_generated, connected_meme_id, connected_label, cover_image + meme_ads table. */
        $stmt=$conn->prepare("INSERT INTO memes (file_name,type,uploaded_by,caption,is_partnership,partner_brand,is_ad,is_ai_generated,connected_meme_id,connected_label,cover_image) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssisisiiiss",$file_name,$type,$user_id,$caption,$is_partnership,$partner_brand,$is_ad,$is_ai_generated,$connected_meme_id,$connected_label,$cover_image);
        $ok=$stmt->execute();
        $message=$ok?($is_ad?"Ad submitted — pending approval!":"Post uploaded successfully!"):"Database error: ".$conn->error;
        $new_meme_id=$ok?$conn->insert_id:null; $post_ok=$ok;
        if($ok){
            try{ $f=$conn->prepare("UPDATE memes SET feature_ok=? WHERE id=? AND uploaded_by=?"); $f->bind_param("iii",$feat_ok,$new_meme_id,$user_id); $f->execute(); $f->close(); }catch(Throwable $e){}
            if($comments_off){ try{ $co=$conn->prepare("UPDATE memes SET comments_off=1 WHERE id=? AND uploaded_by=?"); $co->bind_param("ii",$new_meme_id,$user_id); $co->execute(); $co->close(); }catch(Throwable $e){} }
            try{ $h=$conn->prepare("UPDATE memes SET hide_likes=?, hide_reposts=?, hide_saves=?, hide_shares=? WHERE id=? AND uploaded_by=?"); $h->bind_param("iiiiii",$hide_likes,$hide_reposts,$hide_saves,$hide_shares,$new_meme_id,$user_id); $h->execute(); $h->close(); }catch(Throwable $e){}
        }

        if($ok){
            try{ $ad=(int)$DZ_DEF['def_allow_download']; $dq=$conn->prepare("UPDATE memes SET allow_download=? WHERE id=? AND uploaded_by=?"); $dq->bind_param("iii",$ad,$new_meme_id,$user_id); $dq->execute(); $dq->close(); }catch(Throwable $e){}
            if(!$is_ad&&!empty($poll_in['options'])) dz_poll_create($conn,(int)$new_meme_id,$poll_in['options'],$poll_in['seconds']);
            if(!$is_ad&&!empty($_POST['collab_users'])){
                try{ $cids=array_slice(array_filter(array_map('intval',explode(',',(string)$_POST['collab_users']))),0,DZ_COLLAB_MAX); if($cids) dz_collab_invite($conn,$user_id,(int)$new_meme_id,$cids); }catch(Throwable $e){}
            }
        }

        if($ok&&!empty($extra_items)){
            $ms=$conn->prepare("INSERT INTO meme_media (meme_id, file_name, type, position) VALUES (?,?,?,?)");
            foreach($extra_items as $it){ $ms->bind_param("issi",$new_meme_id,$it['file_name'],$it['type'],$it['position']); $ms->execute(); }
        }
        if($ok&&!empty($tg['ids'])){
            /* tagged people: saved as 'pending' when they turned on "Manually approve tags" */
            try{
                $ts=$conn->prepare("INSERT IGNORE INTO meme_tags (meme_id, tagged_user_id, tagged_by, status) VALUES (?,?,?,?)");
                foreach($tg['ids'] as $tid){
                    $stt=pv_tag_status($conn,$tid);
                    $ts->bind_param("iiis",$new_meme_id,$tid,$user_id,$stt); $ts->execute();
                }
            }catch(Throwable $e){}
        }
        if($ok&&$audio_id_to_link){
            $ls=$conn->prepare("INSERT INTO meme_audio (meme_id, audio_id, music_volume, video_audio_volume) VALUES (?,?,?,?)");
            $ls->bind_param("iiii",$new_meme_id,$audio_id_to_link,$music_volume,$video_audio_volume); $ls->execute();
        }
        if($ok&&$is_ad){
            $ads=$conn->prepare("INSERT INTO meme_ads (meme_id, cta_text, cta_url, cta_color, duration_days, price, status) VALUES (?,?,?,?,?,?,'pending')");
            $ads->bind_param("isssid",$new_meme_id,$ad_cta_text,$ad_cta_url,$ad_cta_color,$ad_duration_days,$ad_price); $ads->execute();
        }
        /* media AI: check the new post within seconds (runs after the response has been sent, so the upload never waits for it) */
        if($ok&&!empty($new_meme_id)&&function_exists('dz_vision_after_response')&&function_exists('dz_tokens')){
            dz_vision_after_response($conn,(int)$new_meme_id);
        }
    }
    // post did not go through: do not leave an orphan cover file behind
    if(!$post_ok&&$cover_image!==null) cover_unlink($cover_image);
    if($is_ajax){ header('Content-Type: application/json'); echo json_encode(['success'=>$post_ok,'msg'=>$message]); exit; }
}

/* ═══ Audio library ═══ */
/* use counts are worked out ONCE in a single pass (the old version re-counted meme_audio for every public sound before sorting, which got very slow as sounds grew) */
$library_audios_res=$conn->query("SELECT a.*, COALESCE(u.c,0) AS uses_count FROM audios a LEFT JOIN (SELECT audio_id, COUNT(*) AS c FROM meme_audio GROUP BY audio_id) u ON u.audio_id=a.id WHERE a.visibility='public' ORDER BY uses_count DESC, a.created_at DESC LIMIT 20");
$library_audios=[]; if($library_audios_res) while($a=$library_audios_res->fetch_assoc()) $library_audios[]=$a;

/* ═══ My highlights ═══ */
$my_highlights=[];
try{
    $st=$conn->prepare("SELECT h.id, h.title, h.cover_path, h.song_path,
        (SELECT COUNT(*) FROM highlight_media m WHERE m.highlight_id = h.id) AS media_count,
        (SELECT m2.file_path FROM highlight_media m2 WHERE m2.highlight_id = h.id ORDER BY m2.id ASC LIMIT 1) AS first_path,
        (SELECT m3.media_type FROM highlight_media m3 WHERE m3.highlight_id = h.id ORDER BY m3.id ASC LIMIT 1) AS first_type,
        (SELECT m4.caption FROM highlight_media m4 WHERE m4.highlight_id = h.id ORDER BY m4.id ASC LIMIT 1) AS first_caption
        FROM highlights h WHERE h.user_id = ? HAVING media_count > 0 ORDER BY h.created_at DESC, h.id DESC");
    $st->bind_param("i",$current_user); $st->execute(); $res=$st->get_result();
    while($row=$res->fetch_assoc()) $my_highlights[]=$row; $st->close();
}catch(Throwable $e){}

/* ═══ My posts (for the "Edit posts" tab + right-hand column) ═══ */
$my_posts=[];
try{
    $rs0=$conn->query("SELECT * FROM memes WHERE ".dz_edit_scope()." ORDER BY created_at DESC, id DESC LIMIT 60");
    if($rs0) while($r=$rs0->fetch_assoc()){
        $my_posts[]=[
            'id'=>(int)$r['id'],
            'file'=>(string)$r['file_name'],
            'type'=>(string)$r['type'],
            'caption'=>(string)($r['caption']??''),
            'is_partnership'=>(int)$r['is_partnership'],
            'partner_brand'=>(string)($r['partner_brand']??''),
            'is_ai_generated'=>(int)$r['is_ai_generated'],
            'connected_meme_id'=>(int)($r['connected_meme_id']??0),
            'connected_label'=>(string)($r['connected_label']??''),
            'is_ad'=>(int)$r['is_ad'],
            'cover'=>(string)($r['cover_image']??''),
            'comments_off'=>(int)($r['comments_off']??0),
            'hide_likes'=>(int)($r['hide_likes']??0),
            'hide_reposts'=>(int)($r['hide_reposts']??0),
            'hide_saves'=>(int)($r['hide_saves']??0),
            'hide_shares'=>(int)($r['hide_shares']??0),
            'tags'=>[],
            'media'=>[['file'=>(string)$r['file_name'],'type'=>(string)$r['type']]],
        ];
    }
}catch(Throwable $e){}


/* every photo / video of those posts (main file first, then the extras in order) */
if(!empty($my_posts)){
    try{
        $idm=[]; foreach($my_posts as $i=>$p) $idm[$p['id']]=$i;
        $rs=$conn->query("SELECT meme_id, file_name, type FROM meme_media WHERE meme_id IN (".implode(',',array_map('intval',array_keys($idm))).") ORDER BY meme_id, position ASC");
        if($rs) while($x=$rs->fetch_assoc()){ $i=$idm[(int)$x['meme_id']]??null; if($i!==null) $my_posts[$i]['media'][]=['file'=>(string)$x['file_name'],'type'=>(string)$x['type']]; }
    }catch(Throwable $e){}
}

/* tagged people on each of those posts */
if(!empty($my_posts)){
    try{
        $idx=[]; foreach($my_posts as $i=>$p) $idx[$p['id']]=$i;
        $in=implode(',',array_map('intval',array_keys($idx)));
        $rs=$conn->query("SELECT t.meme_id, u.id AS uid, u.username, u.profile_pic FROM meme_tags t JOIN users u ON u.id=t.tagged_user_id WHERE t.meme_id IN ($in) ORDER BY t.id ASC");
        if($rs) while($t=$rs->fetch_assoc()){
            $i=$idx[(int)$t['meme_id']]??null;
            if($i!==null) $my_posts[$i]['tags'][]=['id'=>(int)$t['uid'],'username'=>$t['username'],'pic'=>(string)($t['profile_pic']??'')];
        }
    }catch(Throwable $e){}
}

// tags other people made of YOU that wait for your approval
$pending_tags=pv_pending_tags($conn,$current_user);

/* ?mode=highlights  → opens the Highlight tab   ?mode=edit  → opens Edit posts
   ?edit=123         → opens the editor for post 123 straight away */
$open_mode='post';
if(($_GET['mode']??'')==='highlights') $open_mode='highlight';
if(($_GET['mode']??'')==='edit')       $open_mode='edit';
$open_edit_id=(int)($_GET['edit']??0);
if($open_edit_id>0) $open_mode='edit';
$ad_prices_list=[[1,5],[3,12],[7,25],[14,45],[30,80]];
/* Page-load speed: every <img> that is not already lazy (the ones inside closed menus and sheets especially) is told to load only when it is
   about to be seen, so the browser's blue load bar finishes as soon as the visible page is ready. Skips <script>/<style> text and the main avatar. */
if (!function_exists('dz_lazy_imgs')) {
    function dz_lazy_imgs($seg) {
        if ($seg === '' || stripos($seg, '<img') === false) return $seg;
        return preg_replace('~<img(?=\s)(?![^>]*\b(?:loading|fetchpriority)\s*=)~i', '<img loading="lazy" decoding="async"', $seg);
    }
}
$dz_srv_ms=(int)round((microtime(true)-($_SERVER['REQUEST_TIME_FLOAT']??microtime(true)))*1000);   /* shown as an HTML comment at the top of the page: View Source → first line */
/* gzip the page (about a quarter of the size) and lazy-load images — only for the normal page view, never for the JSON answers above */
if(PHP_SAPI!=='cli'&&!headers_sent()){
    if(extension_loaded('zlib')&&!ini_get('zlib.output_compression')){ @ob_start('ob_gzhandler'); }
    @ob_start(function($html){
        /* walk the page once: lazify the plain HTML, copy every <script>/<style>/<textarea>/comment through untouched */
        $out=''; $pos=0; $n=strlen($html);
        while($pos<$n&&preg_match('~<(script|style|textarea)\b|<!--~i',$html,$mm,PREG_OFFSET_CAPTURE,$pos)){
            $start=$mm[0][1];
            $close=($mm[0][0]==='<!--')?'-->':'</'.strtolower($mm[1][0]);
            $e=stripos($html,$close,$start+1);
            if($e===false) break;
            $gt=strpos($html,'>',$e); $end=($gt===false)?$n:$gt+1;
            $out.=dz_lazy_imgs(substr($html,$pos,$start-$pos)).substr($html,$start,$end-$start);
            $pos=$end;
        }
        return $out.dz_lazy_imgs(substr($html,$pos));
    });
}
?>
<!-- upload.php: the server needed <?= (int)$dz_srv_ms ?> ms before sending this page. Under ~300 ms the server is fine and any wait is the browser downloading things. -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#010218" id="metaTheme">
<title>Upload | Dezzme</title>
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
<script>try{if(localStorage.getItem('dz_theme')==='light'){document.documentElement.setAttribute('data-theme','light');document.getElementById('metaTheme').setAttribute('content','#f4f5fb');}}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<!-- fabric.js (the cover-photo editor, ~300 KB) is loaded only when you pick a photo — see loadFabric() — so it no longer slows the page -->
<style>
:root{
  --fg:255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
  --ui-text:#ffffff;
  --bg:#070924; --bg-grad:linear-gradient(-45deg,#010218,#070924,#070924);
  --card:rgba(var(--fg),.05); --card2:rgba(var(--fg),.08); --line:rgba(var(--fg),.09); --line2:rgba(var(--fg),.14);
  --text:#ffffff; --sub:rgba(var(--fg),.62); --mut:rgba(var(--fg),.45);
  --accent:#FF2D2D; --accent-l:#ff7d7d; --accent-dim:rgba(255,45,45,.16); --accent-glow:rgba(255,45,45,.3);
  --btn:#dc3838; --btn-h:#c92f2f;
  --green:#4dde8a; --red:#dc3838; --danger-t:#f87171;
  --panel:#0d1030; --panel2:#15183a; --pop:#0b0d2a;   /* cards, avatar/placeholder fills, toast + dropdown background */
  --gap:rgba(0,0,0,.25);
  --scrim-big:rgba(1,2,24,.62); --pg-scrim:rgba(1,2,24,.78);
  --scroll-thumb:rgba(var(--fg),.22);
  --max-w:620px; --sidebar-w:100px; --tr:.2s cubic-bezier(.4,0,.2,1);
  /* same proportions as the profile page */
  --pad:clamp(9px,3vw,16px);
  --act-h:clamp(25px,7.2vw,30px);   /* height of every rectangular button */
  --act-r:5px;                      /* corner radius of every button */
  --fs:.8rem;
}
@media (max-width:490px){:root{--fs:.74rem}}
@media (max-width:390px){:root{--fs:.7rem}}
@media (min-width:490px) and (max-width:800px){:root{--max-w:100%}}
@media (min-width:801px){:root{--max-w:580px}}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
body{background:var(--bg-grad);background-attachment:fixed;color:var(--text);min-height:100vh;display:flex;flex-direction:column;align-items:center;overflow-x:hidden;-webkit-font-smoothing:antialiased;font-size:var(--fs);padding:0}
button,input,select,textarea{font-family:inherit}
button,a{cursor:pointer;-webkit-tap-highlight-color:transparent}
button,a,input,select,textarea{outline:none}
[class^="ri-"],[class*=" ri-"],[class^="ri-"]::before,[class*=" ri-"]::before,[class^="ri-"]::after,[class*=" ri-"]::after{font-family:'remixicon'!important;font-style:normal;font-weight:normal;line-height:1;speak:none;text-transform:none;-webkit-font-smoothing:antialiased}

/* The sidebar itself comes from nav.php. This only leaves room for it on desktop. */
@media (min-width:769px){body{padding-left:var(--sidebar-w);align-items:flex-start}}

/* ══ SHELL: centred lane on the LEFT + right-hand column ══ */
.pf-shell{width:100%;display:flex;align-items:flex-start;justify-content:center}
.rp-page{width:100%;max-width:var(--max-w);margin:0 auto;min-height:100vh;display:flex;flex-direction:column}
@media (min-width:769px){.rp-page{border-left:1px solid var(--line);border-right:1px solid var(--line)}}
.pf-side{display:none}
@media (min-width:1100px){
  .pf-shell{justify-content:flex-start;padding-left:22px}
  .rp-page{margin:0;flex:0 0 var(--max-w);width:var(--max-w)}
  .pf-side{display:block;flex:1 1 0;min-width:0;max-width:720px;position:sticky;top:0;height:100vh;overflow-y:auto;overflow-x:hidden;padding:14px 26px 40px 22px;scrollbar-width:thin;scrollbar-color:var(--scroll-thumb) transparent}
  .pf-side::-webkit-scrollbar{width:5px}
  .pf-side::-webkit-scrollbar-thumb{background:var(--scroll-thumb);border-radius:99px}
}

/* ── Skeleton ── */
#page-skeleton{position:fixed;top:0;bottom:0;left:0;right:0;background:var(--bg-grad);background-attachment:fixed;z-index:9999;display:flex;justify-content:center;align-items:flex-start;opacity:1;transition:opacity .3s ease;pointer-events:none;overflow:hidden}
@media (min-width:769px){#page-skeleton{left:var(--sidebar-w)}}
@media (min-width:1100px){#page-skeleton{justify-content:flex-start;padding-left:22px}}
#page-skeleton.hide{opacity:0}
@keyframes shimmer{0%{background-position:-500px 0}100%{background-position:500px 0}}
.sk{background:linear-gradient(90deg,rgba(var(--fg),.04) 25%,rgba(var(--fg),.09) 50%,rgba(var(--fg),.04) 75%);background-size:500px 100%;animation:shimmer 1.5s infinite linear;border-radius:6px}
.sk-lane{width:100%;max-width:var(--max-w);min-height:100vh;display:flex;flex-direction:column}
@media (min-width:769px){.sk-lane{border-left:1px solid var(--line);border-right:1px solid var(--line)}}
@media (min-width:1100px){.sk-lane{flex:0 0 var(--max-w);width:var(--max-w)}}
.sk-top{height:54px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sk-body{padding:0 var(--pad);display:flex;flex-direction:column;gap:12px}
.sk-side{display:none}
@media (min-width:1100px){.sk-side{display:block;flex:1 1 0;min-width:0;max-width:720px;padding:14px 26px 0 22px}}
.sk-sgrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:4px;margin:8px 0 16px}
.sk-sgrid .sk{aspect-ratio:1;border-radius:4px}

/* ── Topbar: X on the left, title CENTERED ── */
.topbar{position:sticky;top:0;z-index:60;width:100%;height:var(--topbar-h,54px);display:flex;align-items:center;padding:0 10px;background:var(--bg-grad);background-attachment:fixed;backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}
.tb-side{flex:0 0 38px;display:flex;align-items:center}
.close-btn{background:none;border:none;color:var(--ui-text);font-size:1.5rem;display:flex;align-items:center;justify-content:center;opacity:.9;transition:var(--tr);padding:4px;text-decoration:none}
.close-btn:hover{color:var(--accent);opacity:1}
.close-btn svg{width:22px;height:22px;display:block;flex-shrink:0}
.tb-side .close-btn{width:34px;height:34px;color:var(--ui-text);opacity:1}
@media (min-width:769px){.tb-side .close-btn{display:none}}
.topbar-title{flex:1;text-align:center;font-size:.92rem;font-weight:700;color:var(--ui-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media (max-width:490px){.topbar{--topbar-h:48px}.close-btn{font-size:1.35rem}.topbar-title{font-size:.84rem}}
@media (max-width:380px){.topbar{--topbar-h:44px}.topbar-title{font-size:.8rem}}

.wrap{width:100%;flex:1;padding:0 var(--pad) 12px}

/* ── Action bar (inside the lane, docks above the bottom nav on phones) ── */
.actionbar{position:sticky;bottom:0;z-index:70;background:var(--bg-grad);background-attachment:fixed;padding:8px var(--pad) 10px;display:flex;margin-top:auto}

/* ── Buttons: rectangular, same size as the profile page action buttons ── */
.btn{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;height:var(--act-h);padding:0 14px;border-radius:var(--act-r);border:none;background:var(--btn);color:#000;font-size:.79rem;font-weight:700;line-height:1;white-space:nowrap;transition:background .2s ease,transform .15s ease}
.btn:hover{background:var(--btn-h)}
.btn:active{transform:scale(.98)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn.main{min-width:0}
.btn.sm{width:auto;padding:0 12px;font-size:.75rem}
.btn.ghost{background:rgba(var(--fg),.1);color:var(--ui-text);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
.btn.ghost:hover{background:rgba(var(--fg),.2)}
.btn.danger{background:rgba(220,56,56,.12);color:var(--danger-t);border:1px solid rgba(220,56,56,.3)}
.btn.danger:hover{background:rgba(220,56,56,.25)}
@media (max-width:490px){.btn{font-size:.68rem;padding:0 10px;gap:4px}.btn.sm{font-size:.66rem}}

/* ── Segmented switches ── */
.seg{display:flex;padding:3px;border-radius:var(--act-r);background:var(--card);border:1px solid var(--line);gap:3px;margin:12px 0}
.seg button{flex:1;height:calc(var(--act-h) - 2px);padding:0 6px;border:none;border-radius:4px;background:none;color:var(--sub);font-size:.79rem;font-weight:600;display:flex;align-items:center;justify-content:center;gap:5px;transition:var(--tr);white-space:nowrap}
.seg button i{font-size:13px}
.seg button.on{background:var(--btn);color:#000}
.seg.tight{margin:0}
@media (max-width:490px){.seg button{font-size:.68rem;gap:4px}.seg button i{font-size:12px}}
.pane{display:none;animation:fadeIn .3s ease}
.pane.on{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

/* ── Dropzone / thumbs ── */
.drop{border:1.5px dashed var(--line2);border-radius:10px;padding:22px 14px;text-align:center;transition:var(--tr);cursor:pointer;display:block;background:var(--card)}
.drop:hover,.drop.over{border-color:var(--accent);background:var(--accent-dim)}
.drop i{font-size:26px;color:var(--accent);display:block;margin-bottom:6px}
.drop p{font-size:.78rem;color:var(--text);font-weight:500}
.drop small{display:block;color:var(--mut);font-size:.66rem;margin-top:3px}
#mediaStrip{display:none;margin-top:10px}
#mediaStripInner{display:flex;gap:7px;overflow-x:auto;padding:4px 2px;scrollbar-width:none}
#mediaStripInner::-webkit-scrollbar{display:none}
.media-thumb{position:relative;flex-shrink:0;width:58px;height:58px;border-radius:8px;overflow:hidden;border:2px solid var(--line2);cursor:grab;background:#000}
.media-thumb.cover{border-color:var(--accent)}
.media-thumb img,.media-thumb video{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}
.media-thumb-badge{position:absolute;top:3px;left:3px;background:var(--btn);font-size:7.5px;font-weight:800;color:#000;padding:1px 5px;border-radius:4px}
.media-thumb-remove{position:absolute;top:3px;right:3px;width:17px;height:17px;border-radius:4px;background:rgba(0,0,0,.8);color:#fff;border:none;font-size:9px;display:flex;align-items:center;justify-content:center}
.media-thumb-add{flex-shrink:0;width:58px;height:58px;border-radius:8px;border:2px dashed var(--line2);background:var(--card);display:flex;align-items:center;justify-content:center;color:var(--sub);font-size:20px;cursor:pointer}
.media-thumb-add:hover{border-color:var(--accent);color:var(--accent)}
.hint{font-size:.66rem;color:var(--mut);margin-top:6px;line-height:1.5}
.hint strong{color:var(--sub)}
@media (max-width:490px){.media-thumb,.media-thumb-add{width:50px;height:50px}.drop{padding:18px 12px}.drop i{font-size:22px}}

/* ── Preview (picture / video previews keep their own dark overlays) ── */
.preview{position:relative;width:min(100%,250px);margin:16px auto 0;aspect-ratio:4/5;border-radius:10px;overflow:hidden;background:var(--card);border:1px solid var(--line);display:flex;align-items:center;justify-content:center}
.preview img,.preview video{width:100%;height:100%;object-fit:cover}
.preview-label{position:absolute;bottom:8px;left:8px;background:rgba(0,0,0,.65);backdrop-filter:blur(8px);border-radius:5px;padding:3px 8px;font-size:8.5px;font-weight:700;color:rgba(255,255,255,.75);letter-spacing:.6px;text-transform:uppercase}
.preview-ph{color:var(--mut);font-size:.7rem;text-align:center;padding:18px}
.preview-ph i{font-size:28px;display:block;margin-bottom:6px;color:rgba(255,45,45,.35)}
.preview-audio-badge{position:absolute;top:8px;left:8px;width:fit-content;max-width:calc(100% - 16px);background:rgba(255,45,45,.92);border-radius:5px;padding:3px 8px;font-size:9.5px;font-weight:700;color:#000;display:flex;align-items:center;gap:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;z-index:2}
@media (max-width:490px){.preview{width:min(100%,210px)}}

/* ── Editor (the canvas itself stays black: it is the picture you are editing) ── */
#editorPanel{display:none;border-radius:10px;overflow:hidden;border:1px solid var(--line);background:var(--card);margin-top:12px}
.editor-toolbar{display:flex;flex-wrap:wrap;gap:6px;padding:9px 10px;border-bottom:1px solid var(--line);align-items:flex-end}
.editor-toolbar label{font-size:9px;color:var(--mut);display:flex;flex-direction:column;gap:3px}
.editor-toolbar input[type=text],.editor-toolbar select{background:var(--card2);border:1px solid var(--line2);border-radius:5px;color:var(--ui-text);padding:4px 7px;font-size:10.5px;max-width:92px;height:var(--act-h)}
.editor-toolbar select option{background:var(--pop);color:var(--ui-text)}
.editor-toolbar input[type=color]{width:30px;height:var(--act-h);border:none;border-radius:5px;background:none;padding:0}
.editor-toolbar input[type=range]{width:60px;accent-color:var(--accent)}
.tool-btn{height:var(--act-h);padding:0 9px;border-radius:var(--act-r);border:none;background:rgba(var(--fg),.1);color:var(--ui-text);font-size:.73rem;font-weight:600;display:flex;align-items:center;gap:4px;white-space:nowrap;transition:var(--tr)}
.tool-btn:hover{background:rgba(var(--fg),.2)}
.tool-btn.danger{color:var(--danger-t)}
.canvas-wrapper{width:100%;background:#000;display:flex;justify-content:center;align-items:center;min-height:170px;max-height:360px;overflow:hidden}
#fabricCanvas{display:block;max-width:100%}
.sticker-panel{display:none;flex-wrap:wrap;gap:5px;padding:8px 10px;border-top:1px solid var(--line);max-height:95px;overflow-y:auto}
.sticker-panel.open{display:flex}
.sticker-btn{font-size:22px;padding:3px;border-radius:5px;background:none;border:none;line-height:1}
.crop-controls{display:none;gap:7px;padding:8px 10px;border-top:1px solid var(--line);align-items:center;flex-wrap:wrap}
.crop-controls.open{display:flex}
.crop-controls span{font-size:.68rem;color:var(--sub)}
.done-edit{width:calc(100% - 20px);margin:10px}

/* ── Caption + chips ── */
.caption{width:100%;min-height:88px;padding:16px 0 8px;color:var(--text);font-size:.82rem;line-height:1.55;outline:none;word-break:break-word}
.caption:empty:before{content:"Add a caption… (don't forget a #hashtag)";color:var(--mut)}
.chips{display:flex;gap:7px;overflow-x:auto;padding:6px 0 14px;scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chip{flex-shrink:0;display:flex;align-items:center;gap:6px;height:var(--act-h);padding:0 12px;border-radius:var(--act-r);background:rgba(var(--fg),.1);border:none;color:var(--ui-text);font-size:.79rem;font-weight:600;transition:var(--tr)}
.chip:hover{background:rgba(var(--fg),.2)}
.chip i{font-size:14px;color:var(--accent)}
@media (max-width:490px){.chip{font-size:.68rem;padding:0 9px;gap:4px}.chip i{font-size:12px}}

/* ── Rows ── */
.rows{border-top:1px solid var(--line)}
.row{display:flex;align-items:center;gap:12px;padding:12px 0;cursor:pointer;color:var(--text);transition:var(--tr)}
.row+.row{border-top:1px solid rgba(var(--fg),.04)}
.row>i:first-child{font-size:20px;width:24px;text-align:center;flex-shrink:0}
.row .lbl{flex:1;min-width:0;font-size:.8rem}
.row .lbl small{display:block;font-size:.66rem;color:var(--sub);line-height:1.5;margin-top:1px}
.row .val{max-width:140px;font-size:.72rem;color:var(--sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.row .chev{font-size:19px;color:var(--mut);flex-shrink:0}
.row:hover .chev{color:var(--sub)}
.gap{height:8px;background:var(--gap);margin:0 calc(var(--pad) * -1)}
.switch{position:relative;display:inline-block;width:40px;height:23px;flex-shrink:0}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;inset:0;background:rgba(var(--fg),.18);border-radius:34px;transition:.25s}
.slider::before{position:absolute;content:"";height:17px;width:17px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 3px rgba(0,0,0,.25)}
input:checked+.slider{background:var(--btn)}
input:checked+.slider::before{transform:translateX(17px);background:#000}
.subfield{padding:0 0 12px 36px;animation:fadeIn .2s ease}
.f-input{width:100%;height:var(--act-h);min-height:var(--act-h);padding:0 11px;border-radius:var(--act-r);border:1px solid var(--line2);background:var(--card);color:var(--ui-text);font-size:.76rem;transition:var(--tr);-webkit-appearance:none;appearance:none;resize:none}
.f-input:focus{border-color:rgba(255,45,45,.6);box-shadow:0 0 0 3px rgba(255,45,45,.12)}
.f-input::placeholder{color:var(--mut)}
select.f-input option{background:var(--pop);color:var(--ui-text)}
textarea.f-input{height:auto;min-height:110px;padding:9px 11px;line-height:1.6}
@media (max-width:490px){.row{gap:10px;padding:10px 0}.row>i:first-child{font-size:18px;width:21px}.row .lbl{font-size:.74rem}.row .val{font-size:.66rem;max-width:100px}.switch{width:36px;height:21px}.slider::before{height:15px;width:15px}input:checked+.slider::before{transform:translateX(15px)}.subfield{padding-left:0}.f-input{font-size:.7rem}}

/* ── Sheets ── */
.sheet{position:fixed;top:0;bottom:0;left:0;right:0;background:var(--bg-grad);background-attachment:fixed;z-index:1050;display:flex;flex-direction:column;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .22s ease,visibility 0s linear .22s;box-shadow:0 0 0 200vmax var(--scrim-big)}
.sheet::before{content:'';position:fixed;inset:0;z-index:-1;background:transparent}
.sheet.open{opacity:1;visibility:visible;pointer-events:all;transition:opacity .22s ease}
/* modals sit in the SAME lane as the upload page, with the same two grey lines */
@media (min-width:769px){.sheet{left:var(--sidebar-w);border-left:1px solid var(--line);border-right:1px solid var(--line)}}
@media (min-width:801px){.sheet{right:auto;width:580px;left:calc(var(--sidebar-w) + (100% - var(--sidebar-w) - 580px)/2)}}
@media (min-width:1100px){.sheet{left:calc(var(--sidebar-w) + 22px)}}
#sh-hl-edit,#sh-pe{z-index:1100}#sh-zeem{z-index:1150}#sh-tag{z-index:1160}#sh-clip{z-index:1200}#sh-ht{z-index:1300}
.sheet-head{flex-shrink:0}
.sheet-head .in{max-width:var(--max-w);margin:0 auto;display:flex;align-items:center;padding:max(8px,env(safe-area-inset-top)) 10px 8px;height:54px}
.sheet-title{flex:1;text-align:center;font-size:.92rem;font-weight:700;margin-right:38px}
.sheet-body{flex:1;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:rgba(255,45,45,.35) transparent}
.sheet-in{max-width:var(--max-w);margin:0 auto;padding:12px var(--pad) max(30px,env(safe-area-inset-bottom))}
@media (max-width:490px){.sheet-title{font-size:.84rem}.sheet-head .in{height:48px}}
.sec{margin-top:18px;padding-top:14px;border-top:1px solid var(--line)}
.sec:first-child{margin-top:0;padding-top:0;border-top:none}
.sec-h{font-size:.64rem;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--sub);margin-bottom:9px}
.note{color:var(--sub);font-size:.68rem;line-height:1.65}
.note p+p{margin-top:8px}

/* ── Audio ── */
.audio-list{max-height:280px;overflow-y:auto;display:flex;flex-direction:column;gap:6px}
.audio-item{display:flex;align-items:center;gap:10px;padding:8px;border-radius:8px;background:var(--card);border:1px solid var(--line);cursor:pointer;transition:var(--tr)}
.audio-item:hover{border-color:rgba(255,45,45,.4);background:var(--accent-dim)}
.audio-ic{width:34px;height:34px;border-radius:6px;background:var(--accent-dim);display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:15px;flex-shrink:0}
.audio-info{flex:1;min-width:0}
.audio-t{font-size:.74rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.audio-s{font-size:.64rem;color:var(--mut)}
.audio-pill{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:var(--act-r);background:var(--accent-dim);border:1px solid rgba(255,45,45,.3);margin-top:12px}
.audio-pill span{flex:1;font-size:.72rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pill-btn{width:24px;height:24px;border-radius:5px;border:none;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.pill-btn.play{background:var(--btn);color:#000}
.pill-btn.rm{background:var(--card2);color:var(--sub)}
.vol{margin-top:12px;display:flex;flex-direction:column;gap:9px;padding:11px;background:var(--card);border-radius:8px;border:1px solid var(--line)}
.vol-row{display:flex;align-items:center;gap:8px;font-size:.66rem;color:var(--sub)}
.vol-row span:first-child{width:56px;flex-shrink:0}
.vol-row input{flex:1;accent-color:var(--accent)}
.vol-row span:last-child{width:26px;text-align:right;font-weight:700;color:var(--ui-text)}
.empty-mini{padding:16px 0;text-align:center;color:var(--mut);font-size:.72rem}
.empty-mini i{font-size:24px;display:block;margin-bottom:6px;color:rgba(255,45,45,.35)}

/* ── Zeem connect ── */
.zeem-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}
.zg-item{display:flex;flex-direction:column;text-align:left;padding:0;border-radius:8px;overflow:hidden;background:var(--card);border:1px solid var(--line);color:var(--ui-text);transition:var(--tr)}
.zg-item:hover{border-color:rgba(255,45,45,.5)}
.zg-item.sel{border-color:var(--accent);box-shadow:0 0 0 2px var(--accent-glow)}
.zg-media{position:relative;display:block;width:100%;aspect-ratio:4/5;background:var(--panel);overflow:hidden}
.zg-media img,.zg-media video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;pointer-events:none}
.zg-media.txt{background:var(--panel) url('uploads/Text.jpg') center/cover no-repeat}
.zg-txt{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:8px;text-align:center;background:rgba(0,0,0,.45);color:#fff;font-size:.6rem;line-height:1.4;font-weight:500;word-break:break-word}
.zg-txt span{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:6;overflow:hidden}
.zg-flag{position:absolute;top:5px;right:5px;padding:2px 6px;border-radius:4px;background:rgba(0,0,0,.6);color:#fff;font-size:.58rem;font-weight:700;display:flex;gap:3px;align-items:center}
.zg-check{position:absolute;bottom:5px;right:5px;width:20px;height:20px;border-radius:5px;background:var(--btn);color:#000;display:flex;align-items:center;justify-content:center;font-size:13px}
.zg-cap{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;padding:6px 7px 7px;font-size:.62rem;line-height:1.4;color:rgba(var(--fg),.85);min-height:3em}
.zeem-th{width:42px;height:42px;border-radius:6px;overflow:hidden;background:var(--panel);flex-shrink:0}
.zeem-th img,.zeem-th video{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}
.badge{display:inline-block;margin-left:6px;font-size:8.5px;font-weight:700;color:var(--sub);background:var(--card2);padding:1px 6px;border-radius:4px}
.zeem-conn{display:flex;align-items:center;gap:10px;padding:9px;border-radius:8px;background:rgba(255,45,45,.08);border:1px solid rgba(255,45,45,.3)}
.zeem-conn .zeem-th{width:52px;height:65px}
.zlabel{margin-top:10px}
.zprev{margin-top:8px;font-size:.68rem;font-weight:600;color:var(--sub)}
.zprev span{color:var(--accent-l)}

/* ── Tag people ── */
.tg-chips{display:flex;flex-wrap:wrap;gap:6px}
.tg-chip{display:inline-flex;align-items:center;gap:6px;height:var(--act-h);padding:0 4px 0 4px;border-radius:99px;background:var(--accent-dim);border:1px solid rgba(255,45,45,.3);color:var(--ui-text);font-size:.72rem;font-weight:600;max-width:100%}
.tg-chip img{width:calc(var(--act-h) - 8px);height:calc(var(--act-h) - 8px);border-radius:50%;object-fit:cover;background:var(--panel2);flex-shrink:0}
.tg-chip span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px}
.tg-chip button{width:20px;height:20px;border-radius:50%;border:none;background:rgba(var(--fg),.14);color:var(--ui-text);font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tg-chip button:hover{background:var(--red);color:#fff}
.tg-list{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.tg-row{display:flex;align-items:center;gap:10px;width:100%;padding:8px 10px;border-radius:8px;background:var(--card);border:1px solid var(--line);color:var(--ui-text);text-align:left;transition:var(--tr)}
.tg-row:hover:not(:disabled){border-color:rgba(255,45,45,.5);background:var(--accent-dim)}
.tg-row:disabled{opacity:.6;cursor:not-allowed}
.tg-av{width:36px;height:36px;border-radius:50%;object-fit:cover;background:var(--panel2);flex-shrink:0}
.tg-inf{flex:1;min-width:0}
.tg-inf strong{display:block;font-size:.74rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tg-inf span{display:block;font-size:.64rem;color:var(--mut);margin-top:1px;line-height:1.4}
.tg-inf span.no{color:var(--danger-t)}
.tg-end{font-size:.66rem;font-weight:700;color:var(--accent-l);flex-shrink:0}

/* ── Elevate ── */
.ad-tick{display:flex;align-items:center;gap:10px;font-size:.78rem;font-weight:600;cursor:pointer}
.ad-tick input{accent-color:var(--accent);width:16px;height:16px}
#adMediaStrip{display:none;margin-top:10px}
#adMediaStripInner{display:flex;gap:7px;overflow-x:auto;padding:4px 2px;scrollbar-width:none}
#adMediaStripInner::-webkit-scrollbar{display:none}
.ad-field{margin-top:14px}
.ad-field>label{display:block;font-size:.62rem;font-weight:700;color:var(--sub);text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px}
.ad-colors{display:flex;flex-wrap:wrap;gap:9px}
.ad-sw{width:28px;height:28px;border-radius:50%;cursor:pointer;border:2px solid rgba(var(--fg),.15);position:relative;transition:.15s}
.ad-sw.sel{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow)}
.ad-sw.sel::after{content:"✓";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff}
.ad-dur{display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:7px}
.ad-card{padding:9px 6px;border-radius:8px;text-align:center;cursor:pointer;background:var(--card);border:1px solid var(--line);transition:.15s}
.ad-card.sel{background:var(--accent-dim);border-color:var(--accent)}
.ad-card b{display:block;font-size:.72rem}
.ad-card span{font-size:.68rem;font-weight:600;color:var(--accent-l)}

/* ── Highlights ── */
.hl-pick{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:8px;border:1px dashed var(--line2);background:var(--card);cursor:pointer;transition:var(--tr);color:var(--sub);font-size:.72rem;width:100%;text-align:left;position:relative}
.hl-pick:hover{border-color:rgba(255,45,45,.5);background:var(--accent-dim)}
.hl-pick>i{font-size:20px;color:var(--accent);flex-shrink:0}
.hl-pick input[type=file]{display:none}
.hl-pt{flex:1;min-width:0;line-height:1.45}
.hl-pt strong{display:block;font-size:.76rem;font-weight:600;color:var(--text)}
.hl-pt span{display:block;font-size:.66rem;color:var(--mut)}
.hl-prev{width:42px;height:42px;border-radius:7px;object-fit:cover;background:var(--panel2);flex-shrink:0;display:none}
.hl-prev.show{display:block}
.hl-x{margin-left:auto;width:24px;height:24px;border-radius:5px;border:none;background:rgba(var(--fg),.1);color:var(--sub);flex-shrink:0;display:none;align-items:center;justify-content:center;font-size:13px}
.hl-x.show{display:flex}
.hl-x:hover{background:var(--red);color:#fff}
.stack{display:flex;flex-direction:column;gap:10px}
.hl-rows{max-height:290px;overflow-y:auto;display:flex;flex-direction:column;gap:6px}
.hl-row{display:flex;align-items:center;gap:9px;padding:7px 9px;border:1px solid var(--line);border-radius:8px;background:var(--card)}
.hl-th{width:38px;height:38px;border-radius:6px;overflow:hidden;background:var(--panel2);flex-shrink:0;display:flex;align-items:center;justify-content:center;color:var(--sub);font-size:17px}
.hl-th img{width:100%;height:100%;object-fit:cover}
.hl-inf{flex:1;min-width:0}
.hl-inf strong{display:block;font-size:.7rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.acts{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
.mini{display:inline-flex;align-items:center;gap:3px;height:22px;padding:0 8px;border-radius:5px;border:none;background:rgba(var(--fg),.1);color:var(--sub);font-size:.68rem;font-weight:600;max-width:100%;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;transition:var(--tr)}
.mini.on{background:var(--accent-dim);color:var(--accent-l)}
.mini:hover{background:rgba(var(--fg),.2)}
.list-head{display:flex;align-items:baseline;justify-content:space-between;margin:24px 0 12px;padding-top:16px;border-top:1px solid var(--line)}
.list-head b{font-size:.84rem}.list-head span{font-size:.68rem;color:var(--mut)}
.hl-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 10px}
.hl-sq{position:relative;width:100%;aspect-ratio:1/1;border-radius:10px;overflow:hidden;background:var(--panel2);border:1px solid var(--line)}
.hl-sq img,.hl-sq video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;pointer-events:none}
.hl-vid,.hl-song{position:absolute;bottom:6px;width:20px;height:20px;border-radius:5px;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;font-size:11px;color:#fff}
.hl-vid{left:6px}.hl-song{right:6px}
.hl-edit{position:absolute;top:5px;right:5px;width:24px;height:24px;border-radius:5px;border:none;background:rgba(0,0,0,.65);color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center;z-index:2;transition:var(--tr)}
.hl-edit:hover{background:var(--btn);color:#000}
.hl-name{margin-top:6px;font-size:.7rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hl-cnt{font-size:.64rem;color:var(--mut)}
.hl-empty{grid-column:1/-1;text-align:center;padding:14px 10px;color:var(--mut)}
.hl-empty b{display:block;color:var(--text);font-size:.78rem;margin-bottom:4px}
.tilecap{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:7px;text-align:center;font-size:9.5px;font-weight:600;line-height:1.35;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.85);overflow:hidden;background:rgba(0,0,0,.18);word-break:break-word}
.ed-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px 9px}
.ed-cover{display:flex;align-items:center;gap:12px}
.ed-cover .hl-sq{width:72px;flex-shrink:0;border-radius:8px}
.file-btn{position:relative;overflow:hidden}
.file-btn input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;font-size:0}
.btns{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.status{font-size:.68rem;color:var(--sub);min-height:16px;line-height:1.4}
.status.err{color:var(--danger-t)}

/* text sheet + captions on media (they sit on top of a picture, so they stay white) */
.hl-view{position:relative;width:min(100%,280px);aspect-ratio:4/5;margin:0 auto;border-radius:10px;overflow:hidden;background:#000;border:1px solid var(--line)}
.hl-view>img,.hl-view>video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
#ht-capbox{position:absolute;inset:0}
.cap-wrap{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:16px;text-align:center}
.cap{max-width:100%;padding:8px 11px;border-radius:8px;background:rgba(0,0,0,.36);color:#fff;font-size:.82rem;font-weight:600;line-height:1.5;text-shadow:0 1px 6px rgba(0,0,0,.85);word-break:break-word;overflow-wrap:anywhere}
.cap.clamp{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
.cap:not(.clamp){max-height:100%;overflow-y:auto;scrollbar-width:none}
.cap-empty{opacity:.6;font-weight:500}
.more{background:rgba(0,0,0,.55);border:1px solid rgba(255,255,255,.25);border-radius:5px;color:#fff;font-size:.64rem;font-weight:600;padding:3px 11px}
.men{font-weight:700;text-decoration:none}
.men.user{color:#ff3b4e}.men.group{color:#3ea6ff}
.cnt{font-size:.66rem;color:var(--mut);text-align:right;margin-top:5px}
.cp-times{display:flex;justify-content:space-between;font-size:.7rem;color:var(--sub);margin:14px 0 6px}
.cp-times b{color:var(--ui-text)}
#cp-start{width:100%;accent-color:var(--accent)}

/* ══ EDIT POSTS ══ */
.pe-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:2.5px}
.pe-tile{position:relative;display:block;width:100%;aspect-ratio:4/5;overflow:hidden;padding:0;border:none;border-radius:0;background:var(--panel);text-align:left}
.pe-tile img,.pe-tile video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;pointer-events:none;transition:transform .3s ease}
.pe-tile:hover img,.pe-tile:hover video{transform:scale(1.05)}
.pe-tile.txt{background:var(--panel) url('uploads/Text.jpg') center/cover no-repeat}
.pe-tile.txt::before{content:'';position:absolute;inset:0;background:rgba(0,0,0,.45)}
.pe-cap{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:22px 9px 14px;text-align:center;font-size:.6rem;line-height:1.45;font-weight:500;color:#fff;word-break:break-word}
.pe-cap span{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:7;overflow:hidden}
.pe-ov{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:5px;background:rgba(2,3,20,.55);color:#fff;font-size:.68rem;font-weight:700;opacity:0;transition:opacity .2s ease}
.pe-tile:hover .pe-ov{opacity:1}
.pe-flag{position:absolute;top:6px;left:6px;z-index:2;padding:2px 6px;border-radius:4px;background:rgba(0,0,0,.6);color:#fff;font-size:.56rem;font-weight:700;letter-spacing:.3px}
.pe-mgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(78px,1fr));gap:8px;margin-bottom:10px}
.pe-mi{position:relative;aspect-ratio:1/1;border-radius:6px;overflow:hidden;background:var(--panel);border:1px solid var(--line)}
.pe-mi img,.pe-mi video{width:100%;height:100%;object-fit:cover;display:block}
.pe-mi .pe-mx{position:absolute;top:4px;right:4px;width:24px;height:24px;border-radius:50%;border:none;background:rgba(0,0,0,.72);color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.pe-mi .pe-mn{position:absolute;left:4px;bottom:4px;font-size:10px;font-weight:700;padding:1px 6px;border-radius:99px;background:rgba(0,0,0,.65);color:#fff}
.pe-prev{width:min(100%,180px);aspect-ratio:4/5;margin:0 auto 14px;border-radius:8px;overflow:hidden;position:relative;background:var(--panel);border:1px solid var(--line)}
.pe-prev.txt{background:var(--panel) url('uploads/Text.jpg') center/cover no-repeat}
.pe-prev img,.pe-prev video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.pe-more{margin-top:8px}

/* ══ RIGHT-HAND COLUMN ══ */
.pf-cards{display:flex;flex-direction:column}
.pf-card{margin-bottom:14px}
.pf-bar{display:flex;align-items:center;gap:7px;width:100%;height:var(--act-h);padding:0 12px;border:none;border-radius:var(--act-r);background:rgba(var(--fg),.1);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);color:var(--ui-text);font-size:.79rem;font-weight:600;line-height:1;text-align:left;transition:background .2s ease,transform .15s ease}
.pf-bar:hover{background:rgba(var(--fg),.2)}
.pf-bar:active{transform:scale(.99)}
.pf-bar-ic{font-size:14px;color:var(--accent);flex-shrink:0}
.pf-bar-t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pf-bar-c{font-size:.64rem;font-weight:600;color:rgba(var(--fg),.55);flex-shrink:0}
.pf-bar-chev{font-size:18px;color:rgba(var(--fg),.75);flex-shrink:0;transition:transform .25s ease}
.pf-card.collapsed .pf-bar-chev{transform:rotate(-90deg)}
.pf-body{display:grid;grid-template-rows:1fr;transition:grid-template-rows .3s ease}
.pf-inner{min-height:0;overflow:hidden}
.pf-pad{padding-top:8px}
.pf-card.collapsed .pf-body{grid-template-rows:0fr}
.pf-more{display:flex;align-items:center;justify-content:center;width:100%;height:var(--act-h);margin-top:8px;padding:0 12px;border:none;border-radius:var(--act-r);background:rgba(var(--fg),.1);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);color:var(--ui-text);font-size:.77rem;font-weight:600;line-height:1;transition:background .2s ease,transform .15s ease}
.pf-more:hover{background:rgba(var(--fg),.2)}
.pf-more:active{transform:scale(.98)}
.pf-more.red{color:#000;background:var(--btn);font-weight:700}
.pf-more.red:hover{background:var(--btn-h)}
.pf-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:4px}
.pf-grid .pe-tile{aspect-ratio:1;border-radius:4px}
.pf-row{display:flex;align-items:center;gap:10px;padding:8px 2px;color:var(--ui-text);text-decoration:none;border:none;background:none;width:100%;text-align:left;border-bottom:1px solid rgba(var(--fg),.06)}
.pf-row:last-child{border-bottom:none}
button.pf-row:hover .pf-row-t,a.pf-row:hover .pf-row-t{text-decoration:underline}
.pf-th{width:34px;height:34px;border-radius:6px;overflow:hidden;background:var(--panel2);flex-shrink:0}
.pf-th img,.pf-th video{width:100%;height:100%;object-fit:cover;display:block}
.pf-row-info{flex:1;min-width:0}
.pf-row-t{font-size:.76rem;font-weight:600;color:var(--ui-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pf-row-s{font-size:.64rem;color:rgba(var(--fg),.5);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pf-row-end{font-size:.8rem;font-weight:700;color:var(--accent-l);flex-shrink:0}
.pf-note{font-size:.68rem;color:rgba(var(--fg),.55);line-height:1.6;padding:4px 2px}
.pf-note p+p{margin-top:7px}
.pf-h{display:flex;align-items:center;gap:7px;font-size:.76rem;font-weight:700;color:var(--ui-text);margin:10px 0 9px}
.pf-h i{font-size:15px;color:var(--accent)}
.pf-steps{list-style:none;counter-reset:st;display:flex;flex-direction:column;gap:9px}
.pf-steps li{counter-increment:st;position:relative;padding-left:27px;font-size:.68rem;line-height:1.65;color:rgba(var(--fg),.66)}
.pf-steps li::before{content:counter(st);position:absolute;left:0;top:1px;width:18px;height:18px;border-radius:5px;background:var(--accent-dim);color:var(--accent-l);font-size:.62rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.pf-steps li b{color:var(--ui-text);font-weight:600}
.pf-note b{font-weight:600;color:var(--ui-text)}
.pf-div{height:1px;background:rgba(var(--fg),.08);margin:16px 0 4px}

/* ── Progress overlay ── */
.pg{position:fixed;inset:0;z-index:5000;background:var(--pg-scrim);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;visibility:hidden;transition:opacity .2s}
.pg.show{opacity:1;visibility:visible}
.pg-card{width:100%;max-width:320px;background:var(--panel);border:1px solid var(--line2);border-radius:12px;padding:20px 18px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.7)}
.pg-ic{width:48px;height:48px;border-radius:50%;background:var(--accent-dim);border:1px solid rgba(255,45,45,.3);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:22px;margin:0 auto 12px}
.pg-ic.ok{background:rgba(77,222,138,.13);border-color:rgba(77,222,138,.35);color:var(--green)}
.pg-ic.bad{background:rgba(220,56,56,.13);border-color:rgba(220,56,56,.35);color:var(--danger-t)}
.pg-title{font-size:.88rem;font-weight:600;color:var(--ui-text)}
.pg-bar{height:5px;border-radius:4px;background:rgba(var(--fg),.09);overflow:hidden;margin:14px 0 8px}
.pg-bar span{display:block;height:100%;width:0;background:var(--btn);border-radius:4px;transition:width .15s ease}
.pg-row{display:flex;justify-content:space-between;font-size:.68rem;color:var(--sub);gap:10px}
.pg-row b{color:var(--ui-text)}
.pg-close{margin-top:14px;display:none}

.toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%) translateY(8px);background:var(--pop);border:1px solid rgba(255,45,45,.4);color:var(--ui-text);padding:8px 16px;border-radius:5px;font-size:.72rem;font-weight:500;z-index:6000;pointer-events:none;white-space:normal;text-align:center;line-height:1.45;width:max-content;max-width:min(92vw,380px);opacity:0;transition:opacity .2s,transform .2s;box-shadow:0 8px 24px rgba(0,0,0,.35)}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

@media (max-width:330px){
  .hl-grid,.ed-grid,.zeem-list{grid-template-columns:repeat(2,minmax(0,1fr))}
  .row .val{display:none}
}
@media (prefers-reduced-motion:reduce){*{transition-duration:.01ms!important;animation-duration:.01ms!important}}

/* ══ RESPONSIVE BOOST: everything about 10% bigger on phones/tablets (screens up to 768px).
   Laptop / normal view (769px and up) is NOT touched. ══ */
@media (max-width:768px){
  :root{--fs:.88rem;--act-h:clamp(27.5px,7.9vw,33px);--pad:clamp(10px,3.3vw,17.6px)}
  .topbar{--topbar-h:59px}
  .topbar-title,.sheet-title{font-size:1.01rem}
  .sheet-head .in{height:59px}
  .btn{font-size:.87rem}.btn.sm{font-size:.83rem}
  .seg button{font-size:.87rem}.seg button i{font-size:14px}
  .drop i{font-size:29px}.drop p{font-size:.86rem}.drop small{font-size:.73rem}
  .hint{font-size:.73rem}
  .media-thumb,.media-thumb-add{width:64px;height:64px}
  .preview{width:min(100%,275px)}
  .caption{font-size:.9rem}
  .chip{font-size:.87rem}.chip i{font-size:15px}
  .row>i:first-child{font-size:22px;width:26px}
  .row .lbl{font-size:.88rem}.row .lbl small{font-size:.73rem}
  .row .val{font-size:.79rem}.row .chev{font-size:21px}
  .switch{width:44px;height:25px}
  .slider::before{height:19px;width:19px}
  input:checked+.slider::before{transform:translateX(19px)}
  .f-input{font-size:.84rem}
  .note{font-size:.75rem}.sec-h{font-size:.7rem}
  .audio-t{font-size:.81rem}.audio-s{font-size:.7rem}
  .mini{font-size:.75rem;height:24px}
  .hl-pick{font-size:.79rem}
  .hl-pt strong{font-size:.84rem}.hl-pt span{font-size:.73rem}
  .hl-name{font-size:.77rem}.hl-cnt{font-size:.7rem}
  .tg-chip{font-size:.79rem}
  .tg-inf strong{font-size:.81rem}.tg-inf span{font-size:.7rem}
  .zg-cap{font-size:.68rem}
  .pe-cap{font-size:.66rem}
  .pe-prev{width:min(100%,198px)}
  .pg-title{font-size:.97rem}
  .toast{font-size:.79rem}
}
@media (max-width:490px){
  :root{--fs:.81rem}
  .topbar{--topbar-h:53px}
  .topbar-title,.sheet-title{font-size:.92rem}
  .sheet-head .in{height:53px}
  .close-btn{font-size:1.49rem}
  .btn{font-size:.75rem}.btn.sm{font-size:.73rem}
  .seg button{font-size:.75rem}.seg button i{font-size:13px}
  .chip{font-size:.75rem}.chip i{font-size:13px}
  .row>i:first-child{font-size:20px;width:23px}
  .row .lbl{font-size:.81rem}
  .row .val{font-size:.73rem;max-width:110px}
  .switch{width:40px;height:23px}
  .slider::before{height:17px;width:17px}
  input:checked+.slider::before{transform:translateX(17px)}
  .f-input{font-size:.77rem}
  .media-thumb,.media-thumb-add{width:55px;height:55px}
  .preview{width:min(100%,231px)}
}
@media (max-width:390px){:root{--fs:.77rem}}
@media (max-width:380px){.topbar{--topbar-h:48px}.topbar-title{font-size:.88rem}}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the Community, Games and Algorithm pages. The red accent stays the same in both themes.
   Pictures, videos, the photo editor canvas and captions written on top of media keep their own dark layers. */
:root[data-theme="light"]{
  color-scheme:light;
  --fg:6,7,26;
  --ui-text:#0b0d24;
  --text:#0b0d24;
  --bg:#f4f5fb;
  --bg-grad:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
  --card:rgba(6,7,26,.05); --card2:rgba(6,7,26,.08);
  --line:rgba(6,7,26,.1); --line2:rgba(6,7,26,.2);
  --sub:rgba(6,7,26,.66); --mut:rgba(6,7,26,.52);
  --accent-l:#d92b2b;
  --green:#15a34a;
  --danger-t:#d92b2b;
  --panel:#eceef5; --panel2:#e3e6f0; --pop:#ffffff;
  --gap:rgba(6,7,26,.06);
  --scrim-big:rgba(6,7,26,.38); --pg-scrim:rgba(6,7,26,.45);
  --scroll-thumb:rgba(42,95,216,.4);
}
[data-theme="light"] .pg-card{box-shadow:0 20px 60px rgba(0,0,0,.22)}
[data-theme="light"] .sheet{box-shadow:0 0 0 200vmax var(--scrim-big)}
</style>
</head>
<body>

<!-- SKELETON -->
<div id="page-skeleton">
  <div class="sk-lane">
    <div class="sk-top"><div class="sk" style="height:16px;width:130px"></div></div>
    <div class="sk-body">
      <div class="sk" style="height:34px;border-radius:5px"></div>
      <div class="sk" style="height:34px;border-radius:5px"></div>
      <div class="sk" style="height:130px;border-radius:10px"></div>
      <div class="sk" style="height:200px;width:min(100%,220px);border-radius:10px;margin:4px auto"></div>
      <div class="sk" style="height:12px;width:55%"></div>
      <div style="display:flex;gap:7px"><div class="sk" style="height:30px;width:96px;border-radius:5px"></div><div class="sk" style="height:30px;width:110px;border-radius:5px"></div><div class="sk" style="height:30px;width:104px;border-radius:5px"></div></div>
      <div class="sk" style="height:38px"></div><div class="sk" style="height:38px"></div><div class="sk" style="height:38px"></div>
    </div>
  </div>
  <div class="sk-side">
    <div class="sk" style="height:30px;border-radius:5px"></div><div class="sk-sgrid"><div class="sk"></div><div class="sk"></div><div class="sk"></div><div class="sk"></div><div class="sk"></div><div class="sk"></div></div>
    <div class="sk" style="height:30px;border-radius:5px"></div><div class="sk-sgrid"><div class="sk"></div><div class="sk"></div><div class="sk"></div></div>
    <div class="sk" style="height:30px;border-radius:5px"></div><div class="sk-sgrid"><div class="sk"></div><div class="sk"></div><div class="sk"></div></div>
  </div>
</div>

<!-- SIDEBAR: now comes from nav.php -->
<?php include 'nav.php'; ?>

<div class="pf-shell">
<div class="rp-page">

<!-- TOPBAR: X (close) on the left, title centered -->
<div class="topbar">
  <div class="tb-side"><button class="close-btn" onclick="dzClose()" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button></div>
  <div class="topbar-title" id="topbarTitle">Upload Post</div>
  <div class="tb-side"></div>
</div>

<div class="wrap">

  <div class="seg" id="modeSeg">
    <button type="button" class="on" data-m="post" onclick="setMode('post')"><i class="ri-image-add-line"></i>Post</button>
    <button type="button" data-m="highlight" onclick="setMode('highlight')"><i class="ri-star-smile-line"></i>Highlight</button>
    <button type="button" data-m="edit" onclick="setMode('edit')"><i class="ri-edit-2-line"></i>Edit posts</button>
  </div>

  <!-- ═══════════ POST PANE ═══════════ -->
  <div class="pane on" id="pane-post">
  <form id="uploadForm" method="POST" enctype="multipart/form-data" onsubmit="return false">
    <div class="seg tight" id="typeSeg" style="margin-bottom:14px">
      <button type="button" id="imageBtn" class="on" onclick="setType('image')"><i class="ri-image-line"></i>Image</button>
      <button type="button" id="videoBtn" onclick="setType('video')"><i class="ri-video-line"></i>Video</button>
      <button type="button" id="textBtn" onclick="setType('text')"><i class="ri-text"></i>Text</button>
    </div>
    <input type="hidden" name="post_type" id="post_type" value="image">
    <input type="hidden" name="edited_image_data" id="edited_image_data" value="">

    <div id="fileSection">
      <label for="fileInput" class="drop" id="dropzone">
        <i class="ri-image-add-line"></i>
        <p>Select up to 20 photos/videos or drag and drop</p>
        <small>JPG, PNG, GIF, MP4, WebM, MOV · 1–20 items per post</small>
        <input type="file" id="fileInput" onchange="handleFiles(this.files)" style="display:none" accept="image/*,video/*,.gif" multiple>
      </label>
      <div id="mediaStrip">
        <div id="mediaStripInner"></div>
        <div class="hint"><span id="mediaCountLabel">0</span>/20 · first item is the <strong>cover</strong> · drag to reorder · tap ✕ to remove</div>
      </div>
    </div>

    <div id="editorPanel">
      <div class="editor-toolbar">
        <label>Content<input type="text" id="textInput" placeholder="Add text…"></label>
        <label>Font<select id="fontFamily"><option>Poppins</option><option>Impact</option><option>Arial Black</option><option>Comic Sans MS</option><option>Georgia</option><option>Courier New</option></select></label>
        <label>Size<input type="range" id="fontSize" min="12" max="120" value="36"></label>
        <label>Color<input type="color" id="fontColor" value="#ffffff"></label>
        <label>Stroke<input type="color" id="strokeColor" value="#000000"></label>
        <button type="button" class="tool-btn" onclick="addText()"><i class="ri-text"></i> Add</button>
        <button type="button" class="tool-btn" onclick="toggleStickers()"><i class="ri-emotion-line"></i> Stickers</button>
        <button type="button" class="tool-btn" onclick="toggleCrop()"><i class="ri-crop-line"></i> Crop</button>
        <button type="button" class="tool-btn danger" onclick="deleteSelected()"><i class="ri-delete-bin-line"></i> Del</button>
        <button type="button" class="tool-btn danger" onclick="resetEditor()"><i class="ri-refresh-line"></i> Reset</button>
      </div>
      <div class="canvas-wrapper" id="canvasWrapper"><canvas id="fabricCanvas"></canvas></div>
      <div class="sticker-panel" id="stickerPanel">
        <?php foreach(['😂','💀','🔥','👀','😭','🤣','💯','🫡','😤','🤡','👏','😈','🥶','💅','🤌','😎','🥴','🫠','🗿','⚡','❤️‍🔥','🎭','👾','🤯','🤦','🫣','😏','🧠','🦋'] as $s) echo "<button type='button' class='sticker-btn' onclick='addSticker(\"$s\")'>$s</button>"; ?>
      </div>
      <div class="crop-controls" id="cropControls"><span>Drag to reposition. Use handles to scale.</span>
        <button type="button" class="tool-btn" onclick="applyCrop()"><i class="ri-check-line"></i> Apply</button>
        <button type="button" class="tool-btn" onclick="cancelCrop()">Cancel</button></div>
      <button type="button" class="btn done-edit" id="doneEdit" onclick="finalizeEdit()"><i class="ri-check-double-line"></i> Done Editing — Use This Cover</button>
    </div>

    <div class="preview" id="mediaPreview"><div class="preview-ph"><i class="ri-image-2-line"></i>Your post preview appears here</div></div>

    <div class="caption" id="captionContainer" contenteditable="true" oninput="syncHiddenCaption()"></div>
    <input type="hidden" name="caption_hidden" id="caption_hidden">

    <div class="chips">
      <button type="button" class="chip" onclick="insertHash()"><i class="ri-hashtag"></i>Hashtags</button>
      <button type="button" class="chip" onclick="openTagSheet('post')"><i class="ri-user-add-line"></i>Tag people</button>
      <button type="button" class="chip cover-only" onclick="openSheet('sh-cover')"><i class="ri-image-edit-line"></i>Cover photo</button>
      <button type="button" class="chip" onclick="openSheet('sh-sound')"><i class="ri-music-2-line"></i>Add sound</button>
      <button type="button" class="chip" onclick="openZeemSheet()"><i class="ri-link"></i>Connect Zeem</button>
      <button type="button" class="chip" onclick="openSheet('sh-poll')"><i class="ri-bar-chart-horizontal-line"></i>Poll</button>
      <button type="button" class="chip" onclick="openSheet('sh-elevate')"><i class="ri-rocket-2-line"></i>Create an AD<?= $ad_allowed?'':' 🔒' ?></button>
    </div>

    <div class="rows">
      <div class="row" onclick="openTagSheet('post')"><i class="ri-user-add-line"></i><span class="lbl">Tag people</span><span class="val" id="tagVal">None</span><i class="ri-arrow-right-s-line chev"></i></div>
      <div class="row" onclick="openCollabSheet()"><i class="ri-team-line"></i><span class="lbl">Invite collaborators</span><span class="val" id="collabVal">None</span><i class="ri-arrow-right-s-line chev"></i></div>
      <div class="row cover-only" onclick="openSheet('sh-cover')"><i class="ri-image-edit-line"></i><span class="lbl">Cover photo</span><span class="val" id="cvVal">None</span><i class="ri-arrow-right-s-line chev"></i></div>
      <div class="row" onclick="openSheet('sh-sound')"><i class="ri-music-2-line"></i><span class="lbl">Add sound</span><span class="val" id="sndVal">None</span><i class="ri-arrow-right-s-line chev"></i></div>
      <div class="row" onclick="openSheet('sh-poll')"><i class="ri-bar-chart-horizontal-line"></i><span class="lbl">Poll</span><span class="val" id="pollVal">None</span><i class="ri-arrow-right-s-line chev"></i></div>
      <div class="row" onclick="openZeemSheet()"><i class="ri-link"></i><span class="lbl">Connect a Zeem</span><span class="val" id="zmVal">None</span><i class="ri-arrow-right-s-line chev"></i></div>
      <div id="zeemConnectedBlock" style="display:none;padding:0 0 14px">
        <div class="zeem-conn"><div class="zeem-th" id="zcThumb"></div>
          <div class="audio-info"><div class="audio-t" id="zcCap">Connected Zeem</div><div style="font-size:.64rem;font-weight:700;color:var(--accent-l)"><i class="ri-checkbox-circle-fill"></i> Connected</div></div>
          <button type="button" class="pill-btn rm" onclick="clearZeem()">✕</button></div>
        <div class="zlabel"><input type="text" id="zeemLabelInput" class="f-input" maxlength="40" placeholder="Button label (optional) e.g. Part 2" oninput="syncZeemLabel()">
          <div class="zprev">▶ View · <span id="zeemLabelPreview">Connected Zeem</span></div></div>
      </div>
      <input type="hidden" name="connected_meme_id" id="connected_meme_id">
      <input type="hidden" name="connected_label" id="connected_label">
      <input type="hidden" name="tagged_users" id="tagged_users" value="">
      <input type="hidden" name="collab_users" id="collab_users" value="">
    </div>
    <div class="gap"></div>
    <div class="rows" style="border-top:none">
      <div class="row" style="cursor:default"><i class="ri-hand-coin-line"></i><span class="lbl">Paid partnership</span>
        <label class="switch"><input type="checkbox" id="partnershipCheck" onchange="togglePartnership()"><span class="slider"></span></label></div>
      <input type="hidden" name="is_partnership" id="is_partnership" value="0">
      <div class="subfield" id="partnershipBrandArea" style="display:none">
        <input type="text" class="f-input" id="partnerBrandInput" name="partner_brand" placeholder="Brand name (optional)">
        <div class="hint">Tags your post as a paid partnership. Leave the name blank if you'd rather not show it.</div></div>
      <div class="row" style="cursor:default"><i class="ri-sparkling-2-line"></i><span class="lbl">Add AI label<small>We ask you to label realistic content made with AI.</small></span>
        <label class="switch"><input type="checkbox" id="aiCheck" onchange="toggleAiTag()"<?php if(!empty($DZ_DEF['def_ai_label'])) echo ' checked'; ?>><span class="slider"></span></label></div>
      <input type="hidden" name="is_ai_generated" id="is_ai_generated" value="<?php echo !empty($DZ_DEF['def_ai_label'])?1:0; ?>">
      <div class="row" style="cursor:default"><i class="ri-chat-off-line"></i><span class="lbl">Turn off commenting<small>Nobody can comment on this post. You can change it later in Edit posts.</small></span><label class="switch"><input type="checkbox" name="comments_off" value="1"><span class="slider"></span></label></div>
      <div class="row" style="cursor:default"><i class="ri-heart-line"></i><span class="lbl">Hide like count</span><label class="switch"><input type="checkbox" name="hide_likes" value="1"<?php if(!empty($DZ_DEF['def_hide_likes'])) echo ' checked'; ?>><span class="slider"></span></label></div>
      <div class="row" style="cursor:default"><i class="ri-repeat-line"></i><span class="lbl">Hide repost count</span><label class="switch"><input type="checkbox" name="hide_reposts" value="1"<?php if(!empty($DZ_DEF['def_hide_reposts'])) echo ' checked'; ?>><span class="slider"></span></label></div>
      <div class="row" style="cursor:default"><i class="ri-bookmark-line"></i><span class="lbl">Hide save count</span><label class="switch"><input type="checkbox" name="hide_saves" value="1"<?php if(!empty($DZ_DEF['def_hide_saves'])) echo ' checked'; ?>><span class="slider"></span></label></div>
      <div class="row" style="cursor:default"><i class="ri-share-forward-line"></i><span class="lbl">Hide share count</span><label class="switch"><input type="checkbox" name="hide_shares" value="1"<?php if(!empty($DZ_DEF['def_hide_shares'])) echo ' checked'; ?>><span class="slider"></span></label></div>
      <div class="subfield" style="padding-bottom:6px"><div class="hint" style="margin-top:0">Turn these on to hide the number from other people. You can still change it later in Edit posts.</div></div>
      <div class="row" onclick="openSheet('sh-elevate')"><i class="ri-rocket-2-line"></i><span class="lbl">Create an AD</span><span class="val" id="adVal"><?= $ad_allowed?'Off':'Pro only' ?></span><i class="ri-arrow-right-s-line chev"></i></div>
    </div>

    <!-- SHEET: cover photo -->
    <div class="sheet" id="sh-cover"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="closeSheet('sh-cover')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Cover photo</div></div></div>
      <div class="sheet-body"><div class="sheet-in">
        <label class="hl-pick" for="coverFileInput">
          <img class="hl-prev" id="cvPrev" alt=""><i class="ri-image-add-line" id="cvIcon"></i>
          <div class="hl-pt"><strong id="cvTitle">Choose a cover photo</strong><span id="cvSub">JPG, PNG, GIF or WebP · up to 10 MB</span></div>
          <button type="button" class="hl-x" id="cvX" aria-label="Remove cover photo" onclick="event.preventDefault();event.stopPropagation();coverClear()"><i class="ri-close-line"></i></button>
          <input type="file" id="coverFileInput" name="cover_file" accept="image/jpeg,image/png,image/gif,image/webp" onchange="coverPick(this)">
        </label>
        <div class="note sec"><p>A cover photo is the picture people see for your post on profiles and in feeds, instead of the first photo or video. Viewers still see all of your media when they open the post.</p>
          <p>It works for image, video and GIF posts. Text posts don't have a cover photo. Skip it and your first media is used.</p></div>
        <div class="btns"><button type="button" class="btn" onclick="closeSheet('sh-cover')">Done</button></div>
      </div></div></div>

    <!-- SHEET: poll -->
    <div class="sheet" id="sh-poll"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="closeSheet('sh-poll')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Poll</div></div></div>
      <div class="sheet-body"><div class="sheet-in">
        <div id="pollOpts">
          <input type="text" class="f-input" name="poll_options[]" maxlength="60" placeholder="Choice 1" oninput="pollSync()" style="margin-bottom:8px">
          <input type="text" class="f-input" name="poll_options[]" maxlength="60" placeholder="Choice 2" oninput="pollSync()" style="margin-bottom:8px">
        </div>
        <div class="btns" style="margin-top:2px"><button type="button" class="btn" id="pollAddBtn" onclick="pollAdd()">Add choice</button></div>
        <div style="font-size:.7rem;font-weight:700;color:var(--sub);margin:16px 0 6px">Poll length</div>
        <select class="f-input" name="poll_duration" id="pollDuration" onchange="pollSync()">
          <option value="forever" selected>No limit (vote anytime)</option>
          <option value="1h">1 hour</option><option value="6h">6 hours</option><option value="1d">1 day</option><option value="3d">3 days</option><option value="7d">1 week</option>
        </select>
        <div style="font-size:.64rem;color:var(--mut);margin-top:8px;line-height:1.5">2 to 5 choices. People can change their vote while the poll is open. A poll can't be edited after you post.</div>
        <div class="btns"><button type="button" class="btn" onclick="closeSheet('sh-poll')">Done</button><button type="button" class="btn" onclick="pollClear()">Remove poll</button></div>
      </div></div>
    </div>

    <!-- SHEET: sound -->
    <div class="sheet" id="sh-sound"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="closeSheet('sh-sound')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Add sound</div></div></div>
      <div class="sheet-body"><div class="sheet-in">
        <div class="seg tight" id="audioTabs">
          <button type="button" class="on" data-mode="none" onclick="setAudioMode('none')">No sound</button>
          <button type="button" data-mode="original" id="audioOriginalBtn" onclick="setAudioMode('original')" style="display:none">Original</button>
          <button type="button" data-mode="upload" onclick="setAudioMode('upload')">Upload</button>
          <button type="button" data-mode="library" onclick="setAudioMode('library')">Library</button>
        </div>
        <input type="hidden" name="audio_mode" id="audio_mode" value="none">
        <input type="hidden" name="selected_audio_id" id="selected_audio_id">
        <div id="audioUploadArea" style="display:none;margin-top:14px">
          <label for="audioFileInput" class="drop"><i class="ri-mic-line"></i><p>Select an MP3, WAV, M4A, OGG or AAC file</p><small>One track per post</small>
            <input type="file" id="audioFileInput" name="audio_file" accept=".mp3,.wav,.m4a,.ogg,.aac,audio/*" style="display:none" onchange="handleAudioFile(this.files)"></label></div>
        <div id="audioLibraryArea" style="display:none;margin-top:14px">
          <?php if(empty($library_audios)): ?><div class="empty-mini"><i class="ri-music-2-line"></i>No library tracks yet — be the first to upload one!</div>
          <?php else: ?><div class="audio-list"><?php foreach($library_audios as $a): $ll=$a['title'].(!empty($a['artist'])?' — '.$a['artist']:''); ?>
            <div class="audio-item" onclick='selectLibraryAudio(<?= (int)$a["id"] ?>, <?= json_encode($ll) ?>, <?= json_encode("uploads/".$a["file_name"]) ?>)'>
              <div class="audio-ic"><i class="ri-music-2-fill"></i></div><div class="audio-info"><div class="audio-t"><?= htmlspecialchars($ll) ?></div><div class="audio-s"><?= (int)$a['uses_count'] ?> uses</div></div><i class="ri-play-circle-line" style="font-size:20px;color:var(--mut)"></i></div>
          <?php endforeach; ?></div><?php endif; ?></div>
        <div class="audio-pill" id="audioSelectedPill" style="display:none"><i class="ri-music-2-fill" style="color:var(--accent)"></i><span id="audioPillText">Track</span>
          <audio id="audioPreviewPlayer" style="display:none"></audio>
          <button type="button" class="pill-btn play" onclick="toggleAudioPreview()"><i class="ri-play-fill" id="audioPreviewIcon"></i></button>
          <button type="button" class="pill-btn rm" onclick="setAudioMode('none')">✕</button></div>
        <div class="vol" id="audioVolumeControls" style="display:none">
          <div class="vol-row"><span>🎵 Music</span><input type="range" name="music_volume" min="0" max="100" value="80" oninput="this.nextElementSibling.textContent=this.value"><span>80</span></div>
          <div class="vol-row" id="videoAudioVolumeRow" style="display:none"><span>🎬 Video</span><input type="range" name="video_audio_volume" min="0" max="100" value="40" oninput="this.nextElementSibling.textContent=this.value"><span>40</span></div></div>
        <div class="note sec"><p>Add a track to play with your post. Upload your own or pick a popular one from the library. Tap the play button to preview it before you post.</p></div>
        <div class="btns"><button type="button" class="btn" onclick="closeSheet('sh-sound')">Done</button></div>
      </div></div></div>

    <!-- SHEET: elevate -->
    <div class="sheet" id="sh-elevate"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="closeSheet('sh-elevate')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Create an AD</div></div></div>
      <div class="sheet-body"><div class="sheet-in">
        <?php if(!$ad_allowed): ?>
        <div class="note"><p><b>Ads are for Professional mode profiles.</b> Set a Professional mode type on your profile (like Photographer, Meme Creator or Business) and the ad tools open up here.</p></div>
        <div class="btns"><a class="btn" href="profile.php?user_id=<?= (int)$user_id ?>" style="text-decoration:none">Go to my profile</a></div>
        <?php else: ?>
        <div class="ad-field" style="margin-top:0"><label>Ad photos &amp; videos</label>
          <label for="adFileInput" class="drop" id="adDrop">
            <i class="ri-image-add-line"></i>
            <p>Add the photos or videos for your ad</p>
            <small>JPG, PNG, GIF, MP4, WebM, MOV · 1–20 items · separate from your normal post</small>
            <input type="file" id="adFileInput" onchange="adHandleFiles(this.files)" style="display:none" accept="image/*,video/*,.gif" multiple>
          </label>
          <div id="adMediaStrip"><div id="adMediaStripInner"></div>
            <div class="hint"><span id="adMediaCount">0</span>/20 · first item is the <strong>cover</strong> · tap ✕ to remove</div></div>
        </div>
        <div class="ad-field"><label>Ad caption</label><textarea class="f-input" id="adCaption" maxlength="2200" placeholder="Write what your ad says…" style="min-height:84px"></textarea></div>
        <div class="ad-field"><label>Call-to-action button text</label>
          <select class="f-input" id="adCtaText" onchange="syncCtaText()"><option value="">Select CTA…</option><option>Learn More</option><option>Visit Brand</option><option>Shop Now</option><option>Download Now</option><option value="__custom">Custom…</option></select>
          <input type="text" class="f-input" id="adCtaTextCustom" placeholder="Type your own CTA text" maxlength="24" style="display:none;margin-top:8px" oninput="document.getElementById('ad_cta_text_hidden').value=this.value"></div>
        <div class="ad-field"><label>Destination link</label><input type="url" class="f-input" id="adCtaUrl" placeholder="https://yourbrand.com"></div>
        <div class="ad-field"><label>CTA button color</label><div class="ad-colors" id="adColorGrid"></div></div>
        <div class="ad-field"><label>Boost duration &amp; price</label><div class="ad-dur" id="adDurationGrid"></div></div>
        <div class="rows" style="border-top:none;margin-top:10px">
          <div class="row" onclick="openTagSheet('ad')"><i class="ri-user-add-line"></i><span class="lbl">Tag people</span><span class="val" id="adTagVal">None</span><i class="ri-arrow-right-s-line chev"></i></div>
          <div class="row" style="cursor:default"><i class="ri-hand-coin-line"></i><span class="lbl">Paid partnership</span>
            <label class="switch"><input type="checkbox" id="adPartnerCheck" onchange="$('adPartnerArea').style.display=this.checked?'block':'none';if(!this.checked)$('adPartnerBrand').value=''"><span class="slider"></span></label></div>
          <div class="subfield" id="adPartnerArea" style="display:none">
            <input type="text" class="f-input" id="adPartnerBrand" placeholder="Brand name (optional)">
            <div class="hint">Tags your ad as a paid partnership. Leave the name blank if you'd rather not show it.</div></div>
          <div class="row" style="cursor:default"><i class="ri-sparkling-2-line"></i><span class="lbl">Add AI label<small>We ask you to label realistic content made with AI.</small></span>
            <label class="switch"><input type="checkbox" id="adAiCheck"><span class="slider"></span></label></div>
          <div class="row" style="cursor:default"><i class="ri-heart-line"></i><span class="lbl">Hide like count</span><label class="switch"><input type="checkbox" id="adHideLikes"><span class="slider"></span></label></div>
          <div class="row" style="cursor:default"><i class="ri-bookmark-line"></i><span class="lbl">Hide save count</span><label class="switch"><input type="checkbox" id="adHideSaves"><span class="slider"></span></label></div>
          <div class="row" style="cursor:default"><i class="ri-share-forward-line"></i><span class="lbl">Hide share count</span><label class="switch"><input type="checkbox" id="adHideShares"><span class="slider"></span></label></div>
        </div>
        <input type="hidden" name="is_ad" id="is_ad" value="0"><input type="hidden" name="ad_cta_text" id="ad_cta_text_hidden"><input type="hidden" name="ad_cta_url" id="ad_cta_url_hidden">
        <input type="hidden" name="ad_cta_color" id="ad_cta_color_hidden" value="#E63946"><input type="hidden" name="ad_duration_days" id="ad_duration_days_hidden"><input type="hidden" name="ad_price" id="ad_price_hidden">
        <div class="note sec"><p>Ads are reviewed before they go live. Once you submit, your ad stays pending until it is approved, and the button only shows to viewers after that. Your normal post above is not affected.</p></div>
        <div class="btns"><button type="button" class="btn" id="adSubmitBtn" onclick="submitAd()"><i class="ri-rocket-2-line"></i> Submit ad for review</button></div>
        <?php endif; ?>
      </div></div></div>

  </form>
  </div>

  <!-- ═══════════ HIGHLIGHT PANE ═══════════ -->
  <div class="pane" id="pane-highlight">
    <div class="stack">
      <input type="text" class="f-input" id="hl-title" maxlength="30" placeholder="Highlight name" autocomplete="off">
      <label class="hl-pick" for="hl-files">
        <img class="hl-prev" id="hl-cover-prev" alt=""><i class="ri-image-add-line" id="hl-pick-icon"></i>
        <div class="hl-pt"><strong id="hl-pick-title">Choose photos or videos</strong><span id="hl-pick-sub">Add 1 to 100 per highlight.</span></div>
        <input type="file" id="hl-files" accept="image/*,video/*" multiple></label>
      <button type="button" class="hl-pick" onclick="hlAddText()"><i class="ri-text"></i><div class="hl-pt"><strong>Add a text highlight</strong><span>Words on a Text.jpg background. You can also write on any media below.</span></div></button>
      <div id="hl-items"></div>
      <label class="hl-pick" for="hl-cover-file">
        <img class="hl-prev" id="hl-cv-prev" alt=""><i class="ri-image-line" id="hl-cv-icon"></i>
        <div class="hl-pt"><strong id="hl-cv-title">Cover image (optional)</strong><span id="hl-cv-sub">Skip it and your first media becomes the cover.</span></div>
        <button type="button" class="hl-x" id="hl-cv-x" aria-label="Remove cover" onclick="event.preventDefault();event.stopPropagation();hlCoverClear()"><i class="ri-close-line"></i></button>
        <input type="file" id="hl-cover-file" accept="image/*"></label>
      <div class="seg tight" id="hl-mode"><button type="button" class="on" data-mode="whole" onclick="hlSetMode('whole')">One song for all</button><button type="button" data-mode="each" onclick="hlSetMode('each')">Song per media</button></div>
      <p class="hint" id="hl-mode-help" style="margin:-4px 0 0">One song clip plays across every media in this highlight.</p>
      <div id="hl-song-wrap"><div class="hl-pick" onclick="hlSongPick()" role="button" tabindex="0"><i class="ri-music-2-line"></i>
        <div class="hl-pt"><strong id="hl-song-title">Song clip (optional)</strong><span id="hl-song-sub">Pick the part of a song you want, up to 30 seconds.</span></div>
        <button type="button" class="hl-x" id="hl-song-x" aria-label="Remove song" onclick="event.stopPropagation();hlSongClear()"><i class="ri-close-line"></i></button></div></div>
      <div class="status" id="hl-status" aria-live="polite"></div>
    </div>

    <div class="list-head"><b>Your highlights</b><span id="hl-total"><?= count($my_highlights) ?></span></div>
    <div class="hl-grid" id="hl-grid">
      <?php if(empty($my_highlights)): ?><div class="hl-empty"><b>No highlights yet</b>Name your first highlight and add some media above.</div>
      <?php else: foreach($my_highlights as $h): ?>
      <div class="hl-item"><div class="hl-sq">
        <?php if(!empty($h['cover_path'])): ?><img src="<?= htmlspecialchars($h['cover_path']) ?>" alt="" loading="lazy">
        <?php elseif($h['first_type']==='text'): ?><img src="<?= htmlspecialchars(HL_TEXT_BG) ?>" alt="" loading="lazy"><span class="tilecap"><?= htmlspecialchars(mb_substr((string)$h['first_caption'],0,90)) ?></span>
        <?php elseif($h['first_type']==='video'): ?><video src="<?= htmlspecialchars($h['first_path']) ?>#t=0.1" muted playsinline preload="metadata"></video><span class="hl-vid"><i class="ri-play-fill"></i></span>
        <?php else: ?><img src="<?= htmlspecialchars($h['first_path']) ?>" alt="" loading="lazy"><?php endif; ?>
        <?php if(!empty($h['song_path'])): ?><span class="hl-song"><i class="ri-music-2-fill"></i></span><?php endif; ?>
        <button class="hl-edit" type="button" aria-label="Edit highlight" onclick="hlEdit(<?= (int)$h['id'] ?>)"><i class="ri-pencil-line"></i></button></div>
        <div class="hl-name"><?= htmlspecialchars($h['title']) ?></div><div class="hl-cnt"><?= (int)$h['media_count'] ?> media</div></div>
      <?php endforeach; endif; ?>
    </div>
    <div class="note sec"><p>Highlights are collections of photos, videos, GIFs and text that sit on your profile. Name it, add 1 to 100 media, and optionally add a cover and a song clip. With no cover, your first media is the cover.</p>
      <p>Tap the pencil on a tile to rename it, change the cover or songs, add or remove media, or write on a media. Tag people with @username and groups by name. People who don't allow @mentions from you can't be mentioned.</p></div>
  </div>

  <!-- ═══════════ EDIT POSTS PANE ═══════════ -->
  <div class="pane" id="pane-edit">
    <div id="ptBox" style="display:none;margin-bottom:16px"><div class="sec-h">Tags waiting for your approval · <span id="ptCount">0</span></div>
      <div class="tg-list" id="ptList"></div>
      <p class="hint">These people tagged you and you have Manually approve tags turned on. Accepted tags show on your profile, removed ones never appear.</p></div>
    <p class="hint" style="margin:0 0 10px">Tap a post from your profile grid to edit its caption, tagged people, cover photo, paid partnership, AI label and connected Zeem, or to delete it.</p>
    <div class="pe-grid" id="peGrid"></div>
    <div class="empty-mini" id="peEmpty" style="display:none"><i class="ri-image-2-line"></i>You haven't posted anything yet.</div>
    <button type="button" class="btn ghost pe-more" id="peMore" style="display:none" onclick="peShowMore()">See more</button>
    <div class="note sec"><p>Photos and videos can't be swapped after a post is live. To change them, post again. Edits show up on your profile grid right away.</p></div>
  </div>
</div>

<!-- ACTION BAR (hidden in Edit posts) -->
<div class="actionbar" id="actionbar">
  <button type="button" class="btn main" id="mainBtn" onclick="mainAction()"><i class="ri-send-plane-fill"></i><span id="mainBtnTxt">Post Content</span></button>
</div>

</div><!-- /.rp-page -->

<!-- ═════════ RIGHT-HAND COLUMN (laptop / normal view ≥1100px) ═════════ -->
<aside class="pf-side" aria-label="Upload shortcuts">
 <div class="pf-cards">

  <section class="pf-card">
    <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-edit-2-line pf-bar-ic"></i><span class="pf-bar-t">Edit a recent post</span><span class="pf-bar-c" id="sideCount"><?= count($my_posts) ?></span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
    <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
      <div class="pf-grid" id="sideRecent"></div>
      <div class="pf-note" id="sideRecentEmpty" style="display:none">No posts yet. Your recent posts will show up here.</div>
      <button type="button" class="pf-more" onclick="setMode('edit')">Edit all posts</button>
    </div></div></div>
  </section>

  <section class="pf-card">
    <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-star-smile-line pf-bar-ic"></i><span class="pf-bar-t">Your highlights</span><span class="pf-bar-c"><?= count($my_highlights) ?></span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
    <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
      <?php if(empty($my_highlights)): ?>
        <div class="pf-note">You have no highlights yet. Group your best photos, videos and text on your profile.</div>
      <?php else: foreach(array_slice($my_highlights,0,5) as $h):
          $th = !empty($h['cover_path']) ? $h['cover_path'] : ($h['first_type']==='text' ? HL_TEXT_BG : $h['first_path']);
          $isv = empty($h['cover_path']) && $h['first_type']==='video'; ?>
        <button type="button" class="pf-row" onclick="hlEdit(<?= (int)$h['id'] ?>)">
          <span class="pf-th"><?php if($isv): ?><video src="<?= htmlspecialchars($th) ?>#t=0.1" muted playsinline preload="metadata"></video><?php else: ?><img src="<?= htmlspecialchars($th) ?>" alt="" loading="lazy"><?php endif; ?></span>
          <span class="pf-row-info"><span class="pf-row-t" style="display:block"><?= htmlspecialchars($h['title']) ?></span><span class="pf-row-s" style="display:block"><?= (int)$h['media_count'] ?> media</span></span>
          <i class="ri-pencil-line pf-row-end"></i>
        </button>
      <?php endforeach; endif; ?>
      <button type="button" class="pf-more" onclick="setMode('highlight')">New highlight</button>
    </div></div></div>
  </section>

  <section class="pf-card">
    <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-rocket-2-line pf-bar-ic"></i><span class="pf-bar-t">Advertisement price for creators</span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
    <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
      <?php foreach($ad_prices_list as $ap): ?>
        <div class="pf-row"><div class="pf-row-info"><div class="pf-row-t"><?= (int)$ap[0] ?> day<?= $ap[0]>1?'s':'' ?></div></div><span class="pf-row-end">$<?= number_format($ap[1],2) ?></span></div>
      <?php endforeach; ?>
      <div class="pf-note" style="margin-top:6px">Ads are reviewed before they go live.</div>
    </div></div></div>
  </section>

  <section class="pf-card">
    <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-at-line pf-bar-ic"></i><span class="pf-bar-t">Tags and mentions</span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
    <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
      <div class="pf-note">
        <p>Before anything is posted, saved or edited here, Dezzme checks each person you <b>tag</b> or <b>@mention</b> against their <b>Tags and mentions</b> settings.</p>
        <p><b>Everyone:</b> anyone can tag or mention them. <b>People they track:</b> only accounts they track back. <b>No one:</b> nobody can. Blocked accounts can never tag or mention the person who blocked them.</p>
        <p>If someone doesn't allow it, you see a short message and nothing is posted. If they turned on <b>Manually approve tags</b>, the tag waits as pending until they accept it.</p>
        <p>You can always tag and mention yourself, and people already in an old caption stay when you edit it.</p>
        <p><b>Featuring</b> is set in Settings (Tags and mentions, Reach). When you post or edit, Dezzme only checks whether everyone you @mention allows it and has a Public or Limited profile, and saves the result. Tags waiting for your approval show at the top of <b>Edit posts</b>.</p>
      </div>
    </div></div></div>
  </section>

  <section class="pf-card">
    <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-lightbulb-line pf-bar-ic"></i><span class="pf-bar-t">Tips: how everything works</span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
    <div class="pf-body"><div class="pf-inner"><div class="pf-pad">

      <div class="pf-h"><i class="ri-image-add-line"></i>Upload a post</div>
      <ol class="pf-steps">
        <li>Stay on the <b>Post</b> tab at the top (it is selected when the page opens). Pick <b>Image</b>, <b>Video</b> or <b>Text</b>. Use Text when your post is only words, they are written on the Text.jpg background.</li>
        <li>Tap the dotted box and choose up to <b>20</b> photos or videos, or drag and drop them in. The <b>first one is your cover</b>. Drag the small thumbnails to change the order and tap the ✕ on a thumbnail to remove it.</li>
        <li>If your cover is a single photo, an editor opens where you can add text, stickers and crop. When you like it, press <b>Done Editing</b> so the edited version is the one that gets posted.</li>
        <li>Write your caption. It <b>must contain at least one #hashtag</b>, or the post will not go out. The <b>Hashtags</b> button types a # for you.</li>
        <li>Optional extras: <b>Tag people</b> (search a username or nickname, people who don't allow tags are greyed out), <b>Cover photo</b> (pick your own thumbnail for any image, video or GIF post, not available for text posts), <b>Add sound</b> (upload your own or pick from the library), <b>Connect Zeem</b> (link Part 1 and Part 2), <b>Paid partnership</b>, <b>AI label</b>, the <b>Hide like / repost / save / share count</b> switches, and <b>Create an AD</b> if the post is an advert.</li>
        <li>Press <b>Post Content</b> at the bottom. A progress bar shows the upload and the page refreshes when it is done.</li>
      </ol>

      <div class="pf-div"></div>
      <div class="pf-h"><i class="ri-edit-2-line"></i>Edit a post that is already up</div>
      <ol class="pf-steps">
        <li>Tap the <b>Edit posts</b> tab at the top, or tap any tile in the <b>Edit a recent post</b> card on this side.</li>
        <li>The grid shows up, newest first. Tap <b>See more</b> at the bottom to load older posts.</li>
        <li>Tap the post you want to change. The <b>Edit post</b> window opens with its current details already filled in.</li>
        <li>Change the <b>caption</b> (it still needs a #hashtag, and a text post can never be empty), add or remove <b>tagged people</b>, add, change or remove the <b>cover photo</b> (not for text posts), turn <b>Paid partnership</b> or the <b>AI label</b> on or off, or connect a Zeem: tap <b>Connect a Zeem</b>, look through the posts as pictures and tap the one you want (the ✕ next to it removes it).</li>
        <li>Press <b>Save changes</b>. The new version shows straight away. Press the X instead if you changed your mind, nothing is saved.</li>
        <li>Want a post gone? Scroll to the bottom of the same window, press <b>Delete this post</b> and confirm. Deleting is permanent and also removes its likes, reposts and comments.</li>
      </ol>
      <div class="pf-note" style="margin-top:6px"><p>Good to know: you cannot swap the photo or video of a post that is already live. To change the media, post it again. Ad settings such as price and link cannot be changed here either.</p></div>

      <div class="pf-div"></div>
      <div class="pf-h"><i class="ri-star-smile-line"></i>Create a highlight</div>
      <ol class="pf-steps">
        <li>Tap the <b>Highlight</b> tab at the top.</li>
        <li>Type a <b>name</b> (up to 30 characters). This is the name that sits under the square on your profile.</li>
        <li>Press <b>Choose photos or videos</b> and pick <b>1 to 100</b> files (photos up to 10 MB, videos up to 50 MB). Or press <b>Add a text highlight</b> to make a slide that is only words.</li>
        <li>Under your files, tap <b>Add text</b> to write on any photo or video. Type @username to tag a person (shown in red) or a pod name (shown in blue). Videos have a <b>Sound / Muted</b> button.</li>
        <li><b>Cover image</b> is optional. If you skip it, your first media becomes the cover.</li>
        <li>Choose your music. <b>One song for all</b> plays one clip over the whole highlight. <b>Song per media</b> lets each photo or video have its own clip. To make a clip, choose a song, drag the slider to the part you like, pick 10 to 30 seconds, press <b>Preview clip</b>, then <b>Use this clip</b>. Only that short part is uploaded.</li>
        <li>Press <b>Create highlight</b> at the bottom and wait for the progress bar to finish.</li>
      </ol>

      <div class="pf-div"></div>
      <div class="pf-h"><i class="ri-pencil-line"></i>Edit a highlight</div>
      <ol class="pf-steps">
        <li>Open the <b>Highlight</b> tab and scroll down to <b>Your highlights</b>, or use the <b>Your highlights</b> card on this side, and tap the <b>pencil</b>.</li>
        <li>In the window you can <b>rename</b> it, <b>change or remove the cover</b>, <b>add or remove songs</b>, <b>add more media</b> (up to 100 in total), <b>write or edit text</b> on any media, mute a video, or remove a single media with its ✕.</li>
        <li>Every change <b>saves instantly</b>, there is no Save button. When you are finished, press the X and the page refreshes with your changes.</li>
        <li>The <b>Delete this highlight</b> button at the very bottom removes the highlight and everything inside it for good.</li>
      </ol>
      <div class="pf-note" style="margin-top:6px"><p>A highlight must always keep at least one media. To get rid of the last one, delete the whole highlight.</p></div>

      <div class="pf-div"></div>
      <div class="pf-h"><i class="ri-information-line"></i>Quick reminders</div>
      <div class="pf-note">
        <p>The <b>X</b> at the top leaves this page. Anything you have not posted yet is lost.</p>
        <p>The tabs at the top (<b>Post</b>, <b>Highlight</b>, <b>Edit posts</b>) are how you move between uploading, creating highlights and fixing old posts.</p>
        <p>A post holds up to 20 media. A highlight holds up to 100.</p>
      </div>

    </div></div></div>
  </section>

 </div>
</aside>

</div><!-- /.pf-shell -->

<!-- SHEET: zeem -->
    <div class="sheet" id="sh-zeem"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="closeSheet('sh-zeem')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Connect a Zeem</div></div></div>
      <div class="sheet-body"><div class="sheet-in">
        <input type="text" id="zeemSearchInput" class="f-input" placeholder="Search your Zeems…" oninput="filterZeemList()">
        <div class="zeem-list" id="zeemLibList"></div>
        <div class="empty-mini" id="zeemLibEmpty" style="display:none"><i class="ri-links-line"></i>No matching Zeems found.</div>
        <div class="note sec"><p>Link this post to one of your own Zeems — Part 1 / Part 2, a reaction or a follow-up. Viewers get a button on the media that opens it. Nothing is merged: both posts stay independent.</p></div>
      </div></div></div>

<!-- SHEET: tag people -->
<div class="sheet" id="sh-tag"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="closeSheet('sh-tag')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Tag people</div></div></div>
  <div class="sheet-body"><div class="sheet-in">
    <div class="sec-h">Tagged</div>
    <div class="tg-chips" id="tagSel"></div>
    <div class="sec">
      <input type="text" id="tagSearchInput" class="f-input" placeholder="Search a username or nickname…" autocomplete="off" oninput="tagSearch()">
      <div class="tg-list" id="tagResults"></div>
    </div>
    <div class="note sec"><p>Tagged people are linked to your post. Anyone who only allows tags from people they track, or from no one, is greyed out and can't be tagged.</p>
      <p>If someone turned on Manually approve tags, the tag stays pending until they accept it. You can tag up to 20 people.</p></div>
    <div class="btns"><button type="button" class="btn" onclick="closeSheet('sh-tag')">Done</button></div>
  </div></div></div>

<!-- SHEET: collaborators -->
<div class="sheet" id="sh-collab" style="z-index:1170"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="closeSheet('sh-collab')" aria-label="Close">✕</button></div></div>
  <div class="sheet-body"><div class="sheet-in">
    <div class="sec-h">Collaborators</div>
    <div class="tg-chips" id="coSel"></div>
    <div class="sec">
      <input type="text" id="coSearchInput" class="f-input" placeholder="Search a username…" autocomplete="off" oninput="coSearch()">
      <div class="tg-list" id="coResults"></div>
    </div>
    <div class="note sec"><p>Collaborators are invited when you post. The post shows on their profile too once they accept. You can invite up to 14 people.</p></div>
    <div class="btns"><button type="button" class="btn" onclick="closeSheet('sh-collab')">Done</button></div>
  </div></div></div>

<!-- SHEET: edit existing post -->
<div class="sheet" id="sh-pe"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="closeSheet('sh-pe')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Edit post</div></div></div>
  <div class="sheet-body"><div class="sheet-in">
    <div class="pe-prev" id="pe-prev"></div>
    <div class="sec-h">Caption</div>
    <textarea class="f-input" id="pe-cap" maxlength="2200" placeholder="Add a caption… (don't forget a #hashtag)" oninput="$('pe-cnt').textContent=this.value.length+'/2200'"></textarea>
    <div class="cnt" id="pe-cnt">0/2200</div>
    <div class="sec"><div class="sec-h">Tagged people</div>
      <div class="tg-chips" id="pe-tagchips"></div>
      <div class="btns"><button type="button" class="btn sm ghost" onclick="openTagSheet('edit')"><i class="ri-user-add-line"></i> Tag people</button></div>
      <p class="hint">People who don't allow tags from you can't be added. People already tagged stay until you remove them.</p></div>

    <div class="sec" id="pe-media-sec"><div class="sec-h">Photos &amp; videos</div>
      <div class="pe-mgrid" id="pe-mgrid"></div>
      <div class="btns"><button type="button" class="btn sm ghost" id="pe-madd" onclick="$('pe-min').click()"><i class="ri-add-line"></i> Add photos or videos</button></div>
      <input type="file" id="pe-min" accept="image/*,video/*" multiple hidden onchange="peAddMedia(this)">
      <p class="hint">Changes to photos and videos are saved right away. A post keeps at least one.</p></div>

    <div class="sec" id="pe-co-sec"><div class="sec-h">Collaborators</div>
      <div class="tg-chips" id="pe-cochips"></div>
      <div class="btns"><button type="button" class="btn sm ghost" onclick="openCollabSheet('edit')"><i class="ri-team-line"></i> Invite collaborators</button></div>
      <p class="hint">Up to 14 people. Invites and removals are saved right away. The post shows on their profile once they accept.</p></div>

    <div class="sec" id="pe-cover-sec"><div class="sec-h">Cover photo</div>
      <div class="ed-cover"><div class="hl-sq" id="pe-cover-box"></div>
        <div class="hl-pt"><strong id="pe-cover-t">Using your first media</strong><span id="pe-cover-s">Upload an image to use a different cover.</span>
          <div class="btns" style="margin-top:8px">
            <span class="btn sm file-btn" style="display:inline-flex;width:auto"><span id="pe-cover-btn">Upload cover</span><input type="file" id="pe-cover-file" accept="image/jpeg,image/png,image/gif,image/webp" onchange="peCoverPick(this)"></span>
            <button type="button" class="btn sm danger" id="pe-cover-rm" onclick="peCoverRm()" style="display:none">Remove</button></div></div></div>
      <p class="hint">The cover is the picture shown for this post on profiles and in feeds. Text posts don't have one.</p></div>

    <div class="rows" style="margin-top:12px">
      <div class="row" style="cursor:default"><i class="ri-hand-coin-line"></i><span class="lbl">Paid partnership</span>
        <label class="switch"><input type="checkbox" id="pe-partner" onchange="$('pe-brand-wrap').style.display=this.checked?'block':'none'"><span class="slider"></span></label></div>
      <div class="subfield" id="pe-brand-wrap" style="display:none"><input type="text" class="f-input" id="pe-brand" maxlength="100" placeholder="Brand name (optional)"></div>
      <div class="row" style="cursor:default"><i class="ri-sparkling-2-line"></i><span class="lbl">Add AI label<small>We ask you to label realistic content made with AI.</small></span>
        <label class="switch"><input type="checkbox" id="pe-ai"><span class="slider"></span></label></div>
      <div class="row" style="cursor:default"><i class="ri-chat-off-line"></i><span class="lbl">Turn off commenting<small>Nobody can comment on this post.</small></span><label class="switch"><input type="checkbox" id="pe-coff"><span class="slider"></span></label></div>
      <div class="row" style="cursor:default"><i class="ri-heart-line"></i><span class="lbl">Hide like count</span><label class="switch"><input type="checkbox" id="pe-hlikes"><span class="slider"></span></label></div>
      <div class="row" style="cursor:default"><i class="ri-repeat-line"></i><span class="lbl">Hide repost count</span><label class="switch"><input type="checkbox" id="pe-hreposts"><span class="slider"></span></label></div>
      <div class="row" style="cursor:default"><i class="ri-bookmark-line"></i><span class="lbl">Hide save count</span><label class="switch"><input type="checkbox" id="pe-hsaves"><span class="slider"></span></label></div>
      <div class="row" style="cursor:default"><i class="ri-share-forward-line"></i><span class="lbl">Hide share count</span><label class="switch"><input type="checkbox" id="pe-hshares"><span class="slider"></span></label></div>
          </div>

    <div class="sec"><div class="sec-h">Connected Zeem</div>
      <div class="hl-pick" id="pe-zeem-none" onclick="openZeemSheet('edit')" role="button" tabindex="0"><i class="ri-link"></i>
        <div class="hl-pt"><strong>Connect a Zeem</strong><span>See your posts as pictures and tap the one to link</span></div></div>
      <div id="pe-zeem-on" style="display:none">
        <div class="zeem-conn"><div class="zeem-th" id="pe-zthumb"></div>
          <div class="audio-info"><div class="audio-t" id="pe-zcap">Connected Zeem</div><div style="font-size:.64rem;font-weight:700;color:var(--accent-l)"><i class="ri-checkbox-circle-fill"></i> Connected</div></div>
          <button type="button" class="btn sm ghost" onclick="openZeemSheet('edit')">Change</button>
          <button type="button" class="pill-btn rm" onclick="peClearZeem()" aria-label="Remove connected Zeem">✕</button></div>
        <div class="zlabel"><input type="text" id="pe-zlabel" class="f-input" maxlength="40" placeholder="Button label (optional) e.g. Part 2"></div>
      </div>
      <p class="hint">Viewers get a button on the media that opens the connected Zeem.</p></div>

    <div class="btns"><button type="button" class="btn" id="pe-save" onclick="peSave()">Save changes</button></div>
    <div class="sec"><button type="button" class="btn danger" id="pe-del" onclick="peDelete()"><i class="ri-delete-bin-line"></i> Delete this post</button>
      <p class="hint" style="margin-top:8px">Permanently deletes the post and its media from your profile. This cannot be undone.</p></div>
    <div class="note sec"><p>Photos and videos can't be changed after posting. To change them, post again and delete this one.</p></div>
  </div></div></div>

<!-- SHEET: highlight editor -->
<div class="sheet" id="sh-hl-edit"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="closeSheet('sh-hl-edit')" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Edit highlight</div></div></div>
  <div class="sheet-body"><div class="sheet-in" id="hle-root"></div></div></div>

<!-- SHEET: text -->
<div class="sheet" id="sh-ht"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="htClose()" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Highlight text</div></div></div>
  <div class="sheet-body"><div class="sheet-in">
    <textarea class="f-input" id="ht-input" maxlength="1000" placeholder="Write something. Tag people with @username or @nickname, and groups by their name." oninput="htUpdate()"></textarea>
    <div class="cnt" id="ht-count">0/1000</div><div class="status" id="ht-status"></div>
    <div class="sec"><div class="sec-h">Preview</div><div id="ht-preview"></div></div>
    <div class="btns"><button type="button" class="btn ghost" id="ht-remove" onclick="htRemove()" style="display:none;width:auto;padding:0 18px">Remove text</button><button type="button" class="btn" onclick="htDone()" style="flex:1">Done</button></div>
    <div class="note sec"><p>Your text appears in the middle of the media. Long text is shortened with a "more" button. @usernames turn red and group names turn blue; tap one to open its page.</p>
      <p>If someone doesn't allow @mentions from you, you'll see a message here and the text can't be saved until you remove their @name.</p></div>
  </div></div></div>

<!-- SHEET: song clip -->
<div class="sheet" id="sh-clip"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="cpClose()" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Choose a song clip</div></div></div>
  <div class="sheet-body"><div class="sheet-in">
    <label class="hl-pick" for="cp-file"><i class="ri-music-2-line"></i><div class="hl-pt"><strong id="cp-file-title">Choose a song</strong><span>MP3, M4A, WAV and more. Only the part you pick is kept.</span></div><input type="file" id="cp-file" accept="audio/*"></label>
    <div id="cp-editor" style="display:none">
      <input type="text" class="f-input" id="cp-title" maxlength="60" placeholder="Song name (optional)" style="margin-top:12px">
      <div class="cp-times"><span>Starts at <b id="cp-start-lbl">0:00</b></span><span>Ends at <b id="cp-end-lbl">0:15</b></span></div>
      <input type="range" id="cp-start" min="0" max="100" step="0.1" value="0" oninput="cpUpdate()">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 0"><span class="sec-h" style="margin:0">Clip length</span>
        <select class="f-input" id="cp-len" style="width:auto" onchange="cpUpdate()"><option value="10">10 seconds</option><option value="15" selected>15 seconds</option><option value="20">20 seconds</option><option value="30">30 seconds</option></select></div>
      <button type="button" class="btn ghost" id="cp-prev-btn" onclick="cpPreview()" style="margin-bottom:8px"><i class="ri-play-circle-line"></i> <span id="cp-prev-txt">Preview clip</span></button>
      <button type="button" class="btn" id="cp-use" onclick="cpUse(this)">Use this clip</button></div>
    <div class="status" id="cp-status" style="margin-top:10px"></div>
    <div class="note sec"><p>Drag the slider to the part you want, choose the length (up to 30 seconds) and preview it. Only that short clip is uploaded — the full song stays on your device.</p></div>
  </div></div></div>

<!-- PROGRESS OVERLAY -->
<div class="pg" id="pg"><div class="pg-card">
  <div class="pg-ic" id="pg-ic"><i class="ri-upload-cloud-2-line" id="pg-i"></i></div>
  <div class="pg-title" id="pg-title">Uploading…</div>
  <div class="pg-bar"><span id="pg-fill"></span></div>
  <div class="pg-row"><span id="pg-sub">Starting…</span><b id="pg-pct">0%</b></div>
  <button type="button" class="btn pg-close" id="pg-close" onclick="PG.hide()">Close</button>
</div></div>
<div class="toast" id="toast" role="status"></div>

<script>
const $=id=>document.getElementById(id);
const esc=v=>String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const shortT=(v,n)=>{v=String(v||'');return v.length>n?v.slice(0,n-1)+'…':v};
function toast(m){const t=$('toast');t.textContent=m;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3400)}

/* keep the theme in sync if it is switched in another tab */
window.addEventListener('storage',e=>{
  if(e.key!=='dz_theme')return;
  const light=e.newValue==='light';
  if(light)document.documentElement.setAttribute('data-theme','light');else document.documentElement.removeAttribute('data-theme');
  const mt=$('metaTheme');if(mt)mt.setAttribute('content',light?'#f4f5fb':'#010218');
});

(function(){
  let gone=false;
  function hideSk(){if(gone)return;gone=true;const k=$('page-skeleton');if(!k)return;k.classList.add('hide');setTimeout(()=>k.remove(),380)}
  document.addEventListener('DOMContentLoaded',()=>setTimeout(hideSk,350));
  window.addEventListener('load',()=>setTimeout(hideSk,120));
  setTimeout(hideSk,2500);   // safety net so it can never get stuck
})();

/* X button: back if we came from this site, else to the profile */
function dzClose(){
  if(document.referrer&&document.referrer.indexOf(location.host)!==-1&&history.length>1)history.back();
  else location.href='profile.php';
}
/* right-hand column cards collapse */
function pfToggle(bar){const c=bar.closest('.pf-card');if(!c)return;const col=c.classList.toggle('collapsed');bar.setAttribute('aria-expanded',col?'false':'true')}

/* ── Sheets ── */
function pollInputs(){return [...document.querySelectorAll('#pollOpts input')]}
function pollSync(){
  const f=pollInputs().filter(i=>i.value.trim()).length;
  const d=$('pollDuration'),dl=d.options[d.selectedIndex].text;
  $('pollVal').textContent=f>=2?(f+' choices · '+(d.value==='forever'?'No limit':dl)):(f===1?'Needs 2 choices':'None');
  $('pollAddBtn').style.display=pollInputs().length>=5?'none':'';
}
function pollAdd(){
  const n=pollInputs().length;if(n>=5)return;
  const i=document.createElement('input');i.type='text';i.className='f-input';i.name='poll_options[]';i.maxLength=60;i.placeholder='Choice '+(n+1);i.style.marginBottom='8px';i.oninput=pollSync;
  $('pollOpts').appendChild(i);i.focus();pollSync();
}
function pollClear(){
  const box=$('pollOpts');[...box.children].slice(2).forEach(e=>e.remove());
  pollInputs().forEach(i=>i.value='');$('pollDuration').value='forever';pollSync();closeSheet('sh-poll');
}
function openSheet(id){const e=$(id);e.classList.add('open');document.body.style.overflow='hidden';const b=e.querySelector('.sheet-body');if(b)b.scrollTop=0}
function closeSheet(id){
  if(id==='sh-clip'){cpClose();return}
  if(id==='sh-ht'){htClose();return}
  $(id).classList.remove('open');
  if(!document.querySelector('.sheet.open'))document.body.style.overflow='';
  if(id==='sh-hl-edit'&&hlDirty){hlDirty=false;location.href=location.pathname+'?mode=highlights'}
}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){const o=[...document.querySelectorAll('.sheet.open')];if(o.length)closeSheet(o[o.length-1].id)}});

/* ── Progress overlay ── */
const PG={
  show(t){$('pg-ic').className='pg-ic';$('pg-i').className='ri-upload-cloud-2-line';$('pg-title').textContent=t;$('pg-close').style.display='none';this.set(0,'Starting…');$('pg').classList.add('show')},
  set(p,s){p=Math.max(0,Math.min(100,p));$('pg-fill').style.width=p+'%';$('pg-pct').textContent=Math.round(p)+'%';if(s)$('pg-sub').textContent=s},
  done(t,s){$('pg-ic').className='pg-ic ok';$('pg-i').className='ri-check-line';$('pg-title').textContent=t;this.set(100,s||'')},
  fail(t){$('pg-ic').className='pg-ic bad';$('pg-i').className='ri-error-warning-line';$('pg-title').textContent='Something went wrong';$('pg-sub').textContent=t;$('pg-close').style.display='block'},
  hide(){$('pg').classList.remove('show')}
};
function xhrSend(fd,onp){
  return new Promise((res,rej)=>{
    const x=new XMLHttpRequest();x.open('POST',location.pathname);
    x.upload.onprogress=e=>{if(e.lengthComputable&&onp)onp(e.loaded/e.total)};
    x.onload=()=>{try{res(JSON.parse(x.responseText))}catch(e){rej(new Error('Upload failed. The file may be too large for the server.'))}};
    x.onerror=()=>rej(new Error('Network error. Please try again.'));
    x.send(fd);
  });
}

/* ── Mode: Post / Highlight / Edit posts ── */
let mode='post';
const MODE_TITLES={post:'Upload Post',highlight:'Upload Highlight',edit:'Edit Posts'};
function setMode(m){
  mode=m;
  $('topbarTitle').textContent=MODE_TITLES[m];
  document.querySelectorAll('#modeSeg button').forEach(b=>b.classList.toggle('on',b.dataset.m===m));
  $('pane-post').classList.toggle('on',m==='post');
  $('pane-highlight').classList.toggle('on',m==='highlight');
  $('pane-edit').classList.toggle('on',m==='edit');
  $('actionbar').style.display=m==='edit'?'none':'';
  $('mainBtnTxt').textContent=m==='post'?'Post Content':'Create highlight';
  window.scrollTo({top:0});
}
function mainAction(){mode==='post'?submitPost():hlUpload()}

/* ═══ PRESELECTED AUDIO ═══ */
var _preselectedAudio=<?= $preselected_audio?json_encode(['id'=>(int)$preselected_audio['id'],'label'=>$preselected_audio['title'].(!empty($preselected_audio['artist'])?' — '.$preselected_audio['artist']:''),'url'=>'uploads/'.$preselected_audio['file_name']]):'null' ?>;

/* ═══ FABRIC EDITOR (cover image) ═══ */
let fabricCanvas=null,originalImageObj=null,isCropping=false,cropRect=null,editFinalized=false;
function initFabricCanvas(url){
  const w=$('canvasWrapper'),maxW=w.clientWidth||440,maxH=380;
  if(fabricCanvas){fabricCanvas.dispose();fabricCanvas=null}
  const c=$('fabricCanvas');c.width=maxW;c.height=maxH;
  fabricCanvas=new fabric.Canvas('fabricCanvas',{selection:true,preserveObjectStacking:true});
  fabric.Image.fromURL(url,img=>{
    const s=Math.min(maxW/img.width,maxH/img.height);
    img.set({scaleX:s,scaleY:s,left:(maxW-img.width*s)/2,top:(maxH-img.height*s)/2,selectable:false,evented:false});
    fabricCanvas.add(img);fabricCanvas.sendToBack(img);fabricCanvas.renderAll();originalImageObj=img;
  },{crossOrigin:'anonymous'});
}
function addText(){
  if(!fabricCanvas)return;
  const size=parseInt($('fontSize').value)||36;
  const t=new fabric.IText($('textInput').value||'Meme Text',{left:fabricCanvas.width/2,top:fabricCanvas.height/2,originX:'center',originY:'center',fontSize:size,fill:$('fontColor').value,fontFamily:$('fontFamily').value,stroke:$('strokeColor').value,strokeWidth:size>40?2:1,fontWeight:'bold',shadow:'rgba(0,0,0,0.6) 2px 2px 4px',editable:true});
  fabricCanvas.add(t);fabricCanvas.setActiveObject(t);fabricCanvas.renderAll();
}
function toggleStickers(){$('stickerPanel').classList.toggle('open');$('cropControls').classList.remove('open');if(isCropping)cancelCrop()}
function addSticker(e){
  if(!fabricCanvas)return;
  const t=new fabric.Text(e,{left:fabricCanvas.width/2,top:fabricCanvas.height/2,originX:'center',originY:'center',fontSize:60});
  fabricCanvas.add(t);fabricCanvas.setActiveObject(t);fabricCanvas.renderAll();
}
function deleteSelected(){if(!fabricCanvas)return;const a=fabricCanvas.getActiveObject();if(a&&a!==originalImageObj){fabricCanvas.remove(a);fabricCanvas.renderAll()}}
function toggleCrop(){
  if(!fabricCanvas)return;if(isCropping){cancelCrop();return}
  $('stickerPanel').classList.remove('open');isCropping=true;$('cropControls').classList.add('open');
  cropRect=new fabric.Rect({left:40,top:40,width:fabricCanvas.width-80,height:fabricCanvas.height-80,fill:'rgba(0,0,0,0)',stroke:'#FF2D2D',strokeWidth:2,strokeDashArray:[6,4],hasRotatingPoint:false,cornerColor:'#FF2D2D',cornerSize:10,transparentCorners:false});
  fabricCanvas.add(cropRect);fabricCanvas.setActiveObject(cropRect);fabricCanvas.renderAll();
}
function applyCrop(){
  if(!fabricCanvas||!cropRect)return;const r=cropRect.getBoundingRect();
  const d=fabricCanvas.toDataURL({format:'jpeg',quality:.92,left:r.left,top:r.top,width:r.width,height:r.height});
  fabricCanvas.remove(cropRect);cropRect=null;isCropping=false;$('cropControls').classList.remove('open');
  initFabricCanvas(d);updatePreviewFromCanvas(d);
}
function cancelCrop(){
  $('cropControls').classList.remove('open');
  if(fabricCanvas&&cropRect){fabricCanvas.remove(cropRect);fabricCanvas.renderAll()}
  cropRect=null;isCropping=false;
}
function resetEditor(){
  if(!originalImageObj)return;
  const src=originalImageObj.getSrc?originalImageObj.getSrc():originalImageObj._element.src;
  editFinalized=false;$('edited_image_data').value='';initFabricCanvas(src);
  $('stickerPanel').classList.remove('open');$('cropControls').classList.remove('open');isCropping=false;cropRect=null;
}
function finalizeEdit(){
  if(!fabricCanvas)return;fabricCanvas.discardActiveObject();fabricCanvas.renderAll();
  const d=fabricCanvas.toDataURL({format:'jpeg',quality:.92});
  $('edited_image_data').value=d;editFinalized=true;updatePreviewFromCanvas(d);
  if(selectedMedia.length&&selectedMedia[0].type==='image'){selectedMedia[0].editedUrl=d;renderMediaStrip()}
  const b=$('doneEdit');b.innerHTML='<i class="ri-check-line"></i> Cover ready — you can post now!';
  setTimeout(()=>{b.innerHTML='<i class="ri-check-double-line"></i> Done Editing — Use This Cover'},2400);
}
function updatePreviewFromCanvas(d){
  // a custom cover photo always wins in the preview
  if(coverUrl&&$('post_type').value!=='text'){updateLivePreview();return}
  const p=$('mediaPreview');
  p.innerHTML='<img src="'+d+'"><span class="preview-label">Edited'+(selectedMedia.length>1?' · 1/'+selectedMedia.length:'')+'</span>';
  updateAudioPreviewBadge();
}

/* ═══ MULTI-MEDIA (1–20) ═══ */
let selectedMedia=[];
function handleFiles(list){
  const inc=Array.from(list);if(!inc.length)return;
  const remaining=20-selectedMedia.length;
  if(remaining<=0){flashHint('Max 20 items per post');$('fileInput').value='';return}
  if($('post_type').value==='text')setType('image');
  const wasEmpty=selectedMedia.length===0;
  inc.slice(0,remaining).forEach(f=>{
    const ext=(f.name.split('.').pop()||'').toLowerCase();
    selectedMedia.push({file:f,url:URL.createObjectURL(f),type:['mp4','webm','mov'].includes(ext)?'video':(ext==='gif'?'gif':'image')});
  });
  if(inc.length>remaining)flashHint('Only added '+remaining+' — max 20 per post');
  const hasVid=selectedMedia.some(m=>m.type==='video');
  setTypeTabs(hasVid?'video':'image');
  renderMediaStrip();
  if(hasVid)$('editorPanel').style.display='none';else if(wasEmpty)syncCoverIntoEditor();
  updateAudioOriginalVisibility();$('fileInput').value='';
}
function setTypeTabs(t){$('post_type').value=t;document.querySelectorAll('#typeSeg button').forEach(b=>b.classList.remove('on'));$(t+'Btn').classList.add('on')}
function flashHint(m){const h=document.querySelector('#mediaStrip .hint');if(!h)return;const o=h.innerHTML;h.innerHTML='<span style="color:var(--accent);font-weight:700">'+m+'</span>';setTimeout(()=>h.innerHTML=o,2200)}
function removeMedia(i){
  URL.revokeObjectURL(selectedMedia[i].url);const wasVid=selectedMedia[i].type==='video';selectedMedia.splice(i,1);
  if(i===0){editFinalized=false;$('edited_image_data').value=''}
  if(wasVid&&!selectedMedia.some(m=>m.type==='video')&&selectedMedia.length&&selectedMedia[0].type!=='video')setTypeTabs('image');
  renderMediaStrip();syncCoverIntoEditor();updateAudioOriginalVisibility();
}
function renderMediaStrip(){
  const strip=$('mediaStrip'),inner=$('mediaStripInner');$('mediaCountLabel').textContent=selectedMedia.length;
  if(!selectedMedia.length){strip.style.display='none';updateLivePreview();return}
  strip.style.display='block';
  inner.innerHTML=selectedMedia.map((m,i)=>{
    const u=m.editedUrl||m.url;
    return '<div class="media-thumb'+(i===0?' cover':'')+'" draggable="true" data-idx="'+i+'">'+(m.type==='video'?'<video src="'+u+'" muted></video>':'<img src="'+u+'">')+(i===0?'<span class="media-thumb-badge">COVER</span>':'')+'<button type="button" class="media-thumb-remove" onclick="removeMedia('+i+')">✕</button></div>';
  }).join('');
  if(selectedMedia.length<20)inner.innerHTML+='<div class="media-thumb-add" onclick="$(\'fileInput\').click()"><i class="ri-add-line"></i></div>';
  let drag=null;
  inner.querySelectorAll('.media-thumb').forEach(el=>{
    el.addEventListener('dragstart',()=>{drag=+el.dataset.idx});
    el.addEventListener('dragover',e=>e.preventDefault());
    el.addEventListener('drop',e=>{e.preventDefault();const to=+el.dataset.idx;if(drag===null||drag===to)return;const[m]=selectedMedia.splice(drag,1);selectedMedia.splice(to,0,m);renderMediaStrip();syncCoverIntoEditor()});
  });
  updateLivePreview();
}
let _fabricLoading=false;
function loadFabric(){
  if(typeof fabric!=='undefined'||_fabricLoading)return;
  _fabricLoading=true;
  const s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js';s.async=true;
  s.onerror=()=>{_fabricLoading=false};document.head.appendChild(s);
}
function syncCoverIntoEditor(){
  if(selectedMedia.length&&selectedMedia[0].type==='image')loadFabric();
  const p=$('editorPanel');
  if(!selectedMedia.length||selectedMedia[0].type!=='image'||selectedMedia.some(m=>m.type==='video')){p.style.display='none';return}
  p.style.display='block';editFinalized=false;$('edited_image_data').value='';
  const wait=()=>{if(typeof fabric==='undefined')return setTimeout(wait,120);initFabricCanvas(selectedMedia[0].url)};wait();
}
function updateLivePreview(){
  const b=$('mediaPreview');
  // a custom cover photo is what the post preview shows (never for text posts)
  if(coverUrl&&$('post_type').value!=='text'){
    b.innerHTML='<img src="'+coverUrl+'" alt=""><span class="preview-label"><i class="ri-image-edit-line"></i> Cover photo'+(selectedMedia.length>1?' · '+selectedMedia.length+' media':'')+'</span>';
    updateAudioPreviewBadge();return;
  }
  if(!selectedMedia.length){b.innerHTML='<div class="preview-ph"><i class="ri-image-2-line"></i>Your post preview appears here</div>';updateAudioPreviewBadge();return}
  const f=selectedMedia[0],u=f.editedUrl||f.url;
  b.innerHTML=(f.type==='video'?'<video src="'+u+'" muted loop autoplay playsinline></video>':'<img src="'+u+'">')+(selectedMedia.length>1?'<span class="preview-label"><i class="ri-stack-line"></i> 1/'+selectedMedia.length+' · Gallery</span>':'<span class="preview-label">'+(f.type==='video'?'Video':'Image')+'</span>');
  updateAudioPreviewBadge();
}
(function(){
  const dz=$('dropzone');
  ['dragenter','dragover'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.add('over')}));
  ['dragleave','drop'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.remove('over')}));
  dz.addEventListener('drop',ev=>{if(ev.dataTransfer&&ev.dataTransfer.files.length)handleFiles(ev.dataTransfer.files)});
})();

/* ═══ COVER PHOTO (post form) — image / video / GIF posts only ═══ */
let coverFile=null,coverUrl=null;
function coverRender(){
  const prev=$('cvPrev'),icon=$('cvIcon');
  if(coverUrl){
    prev.src=coverUrl;prev.classList.add('show');icon.style.display='none';
    $('cvTitle').textContent='Cover photo added';$('cvSub').textContent=shortT(coverFile?coverFile.name:'',34)+' · tap to change';
  }else{
    prev.removeAttribute('src');prev.classList.remove('show');icon.style.display='';
    $('cvTitle').textContent='Choose a cover photo';$('cvSub').textContent='JPG, PNG, GIF or WebP · up to 10 MB';
  }
  $('cvX').classList.toggle('show',!!coverUrl);
  $('cvVal').textContent=coverUrl?'Added':'None';
}
function coverPick(input){
  const f=input.files&&input.files[0];
  if(!f)return;   // dialog cancelled: keep what we already have (re-attached on submit)
  if(!/^image\/(jpeg|png|gif|webp)$/.test(f.type)){toast('The cover must be a JPG, PNG, GIF or WebP image');input.value='';return}
  if(f.size>10*1048576){toast('The cover must be under 10 MB');input.value='';return}
  if(coverUrl)URL.revokeObjectURL(coverUrl);
  coverFile=f;coverUrl=URL.createObjectURL(f);
  coverRender();updateLivePreview();
}
function coverClear(){
  if(coverUrl)URL.revokeObjectURL(coverUrl);
  coverFile=null;coverUrl=null;$('coverFileInput').value='';
  coverRender();updateLivePreview();
}

/* ═══ Partnership / AI ═══ */
function togglePartnership(){
  const on=$('partnershipCheck').checked;$('is_partnership').value=on?'1':'0';
  $('partnershipBrandArea').style.display=on?'block':'none';
  if(!on)$('partnerBrandInput').value='';else $('partnerBrandInput').focus();
}
function toggleAiTag(){$('is_ai_generated').value=$('aiCheck').checked?'1':'0'}

/* ═══ Audio ═══ */
let selectedAudio=null,previewPlaying=false;
function setAudioMode(m){
  document.querySelectorAll('#audioTabs button').forEach(b=>b.classList.toggle('on',b.dataset.mode===m));
  $('audio_mode').value=m;
  $('audioUploadArea').style.display=m==='upload'?'block':'none';
  $('audioLibraryArea').style.display=m==='library'?'block':'none';
  if(m==='none'||m==='original')clearAudioSelectionOnly();
  updateVolumeVisibility();updateAudioPreviewBadge();updateSoundRow();
}
function handleAudioFile(f){if(!f||!f.length)return;const file=f[0];selectedAudio={mode:'upload',file,url:URL.createObjectURL(file),title:file.name};$('selected_audio_id').value='';showAudioPill(file.name);updateVolumeVisibility();updateAudioPreviewBadge();updateSoundRow()}
function selectLibraryAudio(id,title,url){selectedAudio={mode:'library',id,url,title};$('selected_audio_id').value=id;showAudioPill(title);updateVolumeVisibility();updateAudioPreviewBadge();updateSoundRow()}
function showAudioPill(t){
  $('audioSelectedPill').style.display='flex';$('audioPillText').textContent=t;
  const p=$('audioPreviewPlayer');p.pause();p.src=selectedAudio.url;previewPlaying=false;$('audioPreviewIcon').className='ri-play-fill';
}
function toggleAudioPreview(){
  const p=$('audioPreviewPlayer');if(!p.src)return;
  previewPlaying?p.pause():p.play();previewPlaying=!previewPlaying;
  $('audioPreviewIcon').className=previewPlaying?'ri-pause-fill':'ri-play-fill';
  p.onended=()=>{previewPlaying=false;$('audioPreviewIcon').className='ri-play-fill'};
}
function clearAudioSelectionOnly(){
  if(selectedAudio&&selectedAudio.mode==='upload'&&selectedAudio.url)URL.revokeObjectURL(selectedAudio.url);
  selectedAudio=null;$('selected_audio_id').value='';$('audioSelectedPill').style.display='none';
  const p=$('audioPreviewPlayer');p.pause();p.src='';previewPlaying=false;$('audioFileInput').value='';
}
function updateVolumeVisibility(){
  const m=$('audio_mode').value,has=(m==='upload'||m==='library')&&selectedAudio;
  $('audioVolumeControls').style.display=has?'flex':'none';
  $('videoAudioVolumeRow').style.display=(has&&$('post_type').value==='video')?'flex':'none';
}
function updateAudioPreviewBadge(){
  const b=$('mediaPreview'),o=b.querySelector('.preview-audio-badge');if(o)o.remove();
  const m=$('audio_mode').value;
  if((m==='upload'||m==='library')&&selectedAudio){const d=document.createElement('div');d.className='preview-audio-badge';d.innerHTML='<i class="ri-music-2-fill"></i> '+esc(selectedAudio.title);b.appendChild(d)}
}
function updateAudioOriginalVisibility(){
  const has=$('post_type').value==='video'||selectedMedia.some(m=>m.type==='video');
  $('audioOriginalBtn').style.display=has?'':'none';
  if(!has&&$('audio_mode').value==='original')setAudioMode('none');
  updateVolumeVisibility();
}
function updateSoundRow(){
  const m=$('audio_mode').value;
  $('sndVal').textContent=m==='original'?'Original audio':(selectedAudio?selectedAudio.title:'None');
}


/* ═══ Elevate / AD ═══ */
const AD_COLORS=['#E63946','#2A9D5C','#1D6FD1','#7B2FBE','#D97706','#DB2777','#0D9488','#CA8A04','#F5F5F0','#6B1E3F','#7A0C2E','#0B3D2E','#0F2A5C','#3B0764','#7C2D12','#831843','#134E4A','#713F12','#1E1B4B','#4C0519'];
const AD_DURATIONS=[{days:1,price:5},{days:3,price:12},{days:7,price:25},{days:14,price:45},{days:30,price:80}];
/* ── the ad has its OWN media, caption and submit — nothing here touches the normal post above ── */
let adMedia=[];
function adRefreshVal(){const v=$('adVal');if(v&&<?= $ad_allowed?'true':'false' ?>)v.textContent=adMedia.length?(adMedia.length+' media'):'Off'}
function adHandleFiles(list){
  const inc=Array.from(list);if(!inc.length)return;
  const room=20-adMedia.length;
  if(room<=0){toast('Max 20 items per ad');$('adFileInput').value='';return}
  inc.slice(0,room).forEach(f=>{
    const ext=(f.name.split('.').pop()||'').toLowerCase();
    if(!['jpg','jpeg','png','gif','webp','mp4','webm','mov'].includes(ext))return;
    adMedia.push({file:f,url:URL.createObjectURL(f),type:['mp4','webm','mov'].includes(ext)?'video':(ext==='gif'?'gif':'image')});
  });
  if(inc.length>room)toast('Only added '+room+' — max 20 per ad');
  $('adFileInput').value='';adRenderStrip();
}
function adRemove(i){URL.revokeObjectURL(adMedia[i].url);adMedia.splice(i,1);adRenderStrip()}
function adRenderStrip(){
  const strip=$('adMediaStrip'),inner=$('adMediaStripInner');if(!strip)return;
  $('adMediaCount').textContent=adMedia.length;adRefreshVal();
  if(!adMedia.length){strip.style.display='none';inner.innerHTML='';return}
  strip.style.display='block';
  inner.innerHTML=adMedia.map((m,i)=>'<div class="media-thumb'+(i===0?' cover':'')+'" style="cursor:default">'+(m.type==='video'?'<video src="'+m.url+'" muted></video>':'<img src="'+m.url+'">')+(i===0?'<span class="media-thumb-badge">COVER</span>':'')+'<button type="button" class="media-thumb-remove" onclick="adRemove('+i+')" aria-label="Remove">✕</button></div>').join('')
    +(adMedia.length<20?'<div class="media-thumb-add" onclick="$(\'adFileInput\').click()"><i class="ri-add-line"></i></div>':'');
}
(function(){
  const dz=$('adDrop');if(!dz)return;
  ['dragenter','dragover'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.add('over')}));
  ['dragleave','drop'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.remove('over')}));
  dz.addEventListener('drop',ev=>{if(ev.dataTransfer&&ev.dataTransfer.files.length)adHandleFiles(ev.dataTransfer.files)});
})();
function adValidate(){
  if(!adMedia.length){toast('Add at least one photo or video for your ad');return false}
  if(!$('ad_cta_text_hidden').value.trim()){toast('Choose or type a CTA button text');return false}
  const u=$('adCtaUrl').value.trim();if(!u){toast('Enter a destination link for your ad');return false}
  try{new URL(u)}catch(_){toast('Enter a full link, like https://yourbrand.com');return false}
  $('ad_cta_url_hidden').value=u;
  if(!$('ad_duration_days_hidden').value){toast('Choose a boost duration and price');return false}
  return true;
}
async function submitAd(){
  if(posting||!adValidate())return;
  posting=true;const btn=$('adSubmitBtn');btn.disabled=true;
  const cap=($('adCaption').value||'').trim();
  const mb=await mentionBlock(cap,'');
  if(mb){toast(mb);posting=false;btn.disabled=false;return}
  const fd=new FormData();
  fd.append('upload','1');fd.append('ajax','1');fd.append('is_ad','1');
  fd.append('post_type',adMedia.some(m=>m.type==='video')?'video':'image');
  fd.append('caption_hidden',cap);
  fd.append('tagged_users',adTags.map(u=>u.id).join(','));
  fd.append('is_partnership',$('adPartnerCheck').checked?'1':'0');
  fd.append('partner_brand',$('adPartnerCheck').checked?$('adPartnerBrand').value.trim():'');
  fd.append('is_ai_generated',$('adAiCheck').checked?'1':'0');
  [['hide_likes','adHideLikes'],['hide_saves','adHideSaves'],['hide_shares','adHideShares']].forEach(a=>{if($(a[1]).checked)fd.append(a[0],'1')});
  fd.append('ad_cta_text',$('ad_cta_text_hidden').value.trim());fd.append('ad_cta_url',$('ad_cta_url_hidden').value);
  fd.append('ad_cta_color',$('ad_cta_color_hidden').value);fd.append('ad_duration_days',$('ad_duration_days_hidden').value);fd.append('ad_price',$('ad_price_hidden').value);
  fd.append('meme_file',adMedia[0].file);
  adMedia.slice(1).forEach(m=>fd.append('extra_media[]',m.file));
  closeSheet('sh-elevate');
  PG.show('Submitting your ad…');
  try{
    const d=await xhrSend(fd,f=>PG.set(f*96,f<1?'Uploading '+Math.round(f*100)+'%':'Finishing up…'));
    if(!d.success)throw new Error(d.msg||'Could not submit the ad.');
    PG.done('Ad submitted',d.msg||'');
    setTimeout(()=>{location.href=location.pathname},1100);
  }catch(e){PG.fail(e.message);posting=false;btn.disabled=false}
}
function syncCtaText(){
  const s=$('adCtaText'),c=$('adCtaTextCustom');
  if(s.value==='__custom'){c.style.display='block';c.focus();$('ad_cta_text_hidden').value=c.value}else{c.style.display='none';$('ad_cta_text_hidden').value=s.value}
}
if($('adColorGrid'))$('adColorGrid').innerHTML=AD_COLORS.map((c,i)=>'<div class="ad-sw'+(i===0?' sel':'')+'" style="background:'+c+'" onclick="selAdColor(\''+c+'\',this)"></div>').join('');
function selAdColor(c,el){document.querySelectorAll('.ad-sw').forEach(s=>s.classList.remove('sel'));el.classList.add('sel');$('ad_cta_color_hidden').value=c}
if($('adDurationGrid'))$('adDurationGrid').innerHTML=AD_DURATIONS.map(d=>'<div class="ad-card" onclick="selAdDur('+d.days+','+d.price+',this)"><b>'+d.days+' day'+(d.days>1?'s':'')+'</b><span>$'+d.price.toFixed(2)+'</span></div>').join('');
function selAdDur(d,p,el){document.querySelectorAll('.ad-card').forEach(c=>c.classList.remove('sel'));el.classList.add('sel');$('ad_duration_days_hidden').value=d;$('ad_price_hidden').value=p}

/* ═══ Zeem connect ═══ */
var myZeems=<?= json_encode(array_map(function($z){return ['id'=>(int)$z['id'],'type'=>$z['type'],'caption'=>$z['caption']?:'','thumb'=>($z['type']==='text')?'uploads/Text.jpg':'uploads/'.$z['file_name'],'media_count'=>(int)$z['media_count']];},$my_zeems),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
let zeemTarget='post',peZeemId=0;
function zeemInfo(id){
  const z=myZeems.find(x=>x.id===id);if(z)return z;
  const p=myPosts.find(x=>x.id===id);
  if(p){
    const useCover=!peIsText(p)&&p.cover;
    return{id:p.id,type:useCover?'image':p.type,caption:p.caption||'',thumb:peIsText(p)?'uploads/Text.jpg':'uploads/'+(useCover?p.cover:p.file),media_count:1};
  }
  return null;
}
function zeemMedia(z){
  if(z.type==='video')return '<video src="'+esc(z.thumb)+'#t=0.1" muted playsinline preload="metadata"></video>';
  return '<img src="'+esc(z.thumb)+'" alt="" loading="lazy" onerror="this.style.display=\'none\'">';
}
function zeemPool(){return(zeemTarget==='edit'&&peCur)?myZeems.filter(z=>z.id!==peCur.id):myZeems}
function openZeemSheet(t){zeemTarget=t==='edit'?'edit':'post';openSheet('sh-zeem');$('zeemSearchInput').value='';renderZeemList(zeemPool())}
function renderZeemList(list){
  const c=$('zeemLibList'),e=$('zeemLibEmpty');
  if(!list.length){c.innerHTML='';e.style.display='block';return}
  e.style.display='none';
  const sel=zeemTarget==='edit'?peZeemId:(parseInt($('connected_meme_id').value,10)||0);
  c.innerHTML=list.map(z=>{
    const txt=z.type==='text',cap=z.caption.trim()?esc(shortT(z.caption.trim(),60)):'(no caption)';
    const media=txt?'<span class="zg-txt"><span>'+esc(shortT(z.caption.trim(),90))+'</span></span>':zeemMedia(z);
    const flag=z.media_count>1?'<span class="zg-flag"><i class="ri-stack-line"></i> '+z.media_count+'</span>':(z.type==='video'?'<span class="zg-flag"><i class="ri-play-fill"></i></span>':'');
    const on=z.id===sel;
    return '<button type="button" class="zg-item'+(on?' sel':'')+'" onclick="selectZeem('+z.id+')"><span class="zg-media'+(txt?' txt':'')+'">'+media+flag+(on?'<span class="zg-check"><i class="ri-check-line"></i></span>':'')+'</span><span class="zg-cap">'+cap+'</span></button>';
  }).join('');
}
function filterZeemList(){const q=$('zeemSearchInput').value.trim().toLowerCase();const pool=zeemPool();renderZeemList(q?pool.filter(z=>(z.caption||'').toLowerCase().includes(q)):pool)}
function selectZeem(id){
  const z=zeemInfo(id);if(!z)return;
  if(zeemTarget==='edit'){peSetZeem(id);closeSheet('sh-zeem');return}
  $('connected_meme_id').value=z.id;
  $('zcThumb').innerHTML=zeemMedia(z);
  $('zcCap').textContent=z.caption.trim()?shortT(z.caption.trim(),60):'(no caption)';
  $('zeemConnectedBlock').style.display='block';$('zmVal').textContent='Connected';
  $('zeemLabelInput').value='';$('connected_label').value='';$('zeemLabelPreview').textContent='Connected Zeem';
  closeSheet('sh-zeem');
}
function clearZeem(){$('connected_meme_id').value='';$('connected_label').value='';$('zeemLabelInput').value='';$('zeemConnectedBlock').style.display='none';$('zmVal').textContent='None'}
function peRenderZeem(){
  const z=peZeemId?zeemInfo(peZeemId):null;
  $('pe-zeem-none').style.display=peZeemId?'none':'';
  $('pe-zeem-on').style.display=peZeemId?'block':'none';
  if(!peZeemId)return;
  $('pe-zthumb').innerHTML=z?zeemMedia(z):'';
  $('pe-zcap').textContent=z?(z.caption.trim()?shortT(z.caption.trim(),60):'(no caption)'):'Zeem #'+peZeemId;
}
function peSetZeem(id){peZeemId=id;peRenderZeem()}
function peClearZeem(){peZeemId=0;$('pe-zlabel').value='';peRenderZeem()}
function syncZeemLabel(){const v=$('zeemLabelInput').value.trim();$('connected_label').value=v;$('zeemLabelPreview').textContent=v||'Connected Zeem'}

/* ═══════════════════════════════════════════════════════════
   TAG PEOPLE + @MENTION CHECKS
   The server is what really enforces them (a person's Tags and
   mentions settings). These helpers only give an early, friendly
   message so you don't wait for an upload to find out.
═══════════════════════════════════════════════════════════ */
let tagTarget='post',tagPost=[],adTags=[],peTags=[],tagTimer=null,tagSeq=0,tagLast=[];
const tagArr=()=>tagTarget==='edit'?peTags:(tagTarget==='ad'?adTags:tagPost);
const tagPic=u=>u&&u.pic?u.pic:'mee.jpg';
function tagChipsHTML(arr,which){
  return arr.map(u=>'<span class="tg-chip"><img src="'+esc(tagPic(u))+'" alt="" onerror="this.src=\'mee.jpg\'"><span>@'+esc(u.username)+'</span><button type="button" aria-label="Remove tag" onclick="tagRemove('+u.id+',\''+which+'\')">✕</button></span>').join('');
}
function renderTagSel(){
  $('tagSel').innerHTML=tagArr().length?tagChipsHTML(tagArr(),tagTarget):'<span class="hint" style="margin:0">Nobody tagged yet.</span>';
  $('pe-tagchips').innerHTML=peTags.length?tagChipsHTML(peTags,'edit'):'<span class="hint" style="margin:0">Nobody tagged.</span>';
  $('tagged_users').value=tagPost.map(u=>u.id).join(',');
  const atv=$('adTagVal');if(atv)atv.textContent=adTags.length?(adTags.length===1?'@'+adTags[0].username:adTags.length+' people'):'None';
  $('tagVal').textContent=tagPost.length?(tagPost.length===1?'@'+tagPost[0].username:tagPost.length+' people'):'None';
  tagRenderResults();
}
function openTagSheet(t){
  tagTarget=t==='edit'?'edit':(t==='ad'?'ad':'post');
  openSheet('sh-tag');$('tagSearchInput').value='';tagLast=[];
  tagHint('Search a username or nickname to tag someone. Their @username is added to your caption where your cursor was.');renderTagSel();
}
/* ── collaborators ── */
let coSel=[],coEdit=[],coLast=[],coTimer=null,coSeq=0,coMode='post';
const coList=()=>coMode==='edit'?coEdit:coSel;
function coRender(){
  const L=coList();
  if(coMode==='edit'){peCoRender();$('coSel').innerHTML=L.length?L.map(u=>'<span class="tg-chip"><img src="'+esc(tagPic(u))+'" alt="" onerror="this.src=\'mee.jpg\'"><span>@'+esc(u.username)+(u.status==='pending'?' · invited':'')+'</span><button type="button" aria-label="Remove" onclick="coRemove('+u.id+')">✕</button></span>').join(''):'<span class="hint" style="margin:0">Nobody invited yet.</span>';coResults();return}
  $('coSel').innerHTML=coSel.length?coSel.map(u=>'<span class="tg-chip"><img src="'+esc(tagPic(u))+'" alt="" onerror="this.src=\'mee.jpg\'"><span>@'+esc(u.username)+'</span><button type="button" aria-label="Remove" onclick="coRemove('+u.id+')">✕</button></span>').join(''):'<span class="hint" style="margin:0">Nobody invited yet.</span>';
  $('collab_users').value=coSel.map(u=>u.id).join(',');
  $('collabVal').textContent=coSel.length?(coSel.length===1?'@'+coSel[0].username:coSel.length+' people'):'None';
  coResults();
}
function coHint(m){$('coResults').innerHTML='<div class="empty-mini"><i class="ri-user-search-line"></i>'+esc(m)+'</div>'}
function openCollabSheet(m){coMode=(m==='edit'&&peCur)?'edit':'post';openSheet('sh-collab');$('coSearchInput').value='';coLast=[];coHint('Search a username to invite.');coRender()}
function coSearch(){
  clearTimeout(coTimer);const q=$('coSearchInput').value.trim().replace(/^@/,'');
  if(q.length<2){coLast=[];coHint('Type at least 2 letters.');return}
  coTimer=setTimeout(async()=>{
    const s=++coSeq,fd=new FormData();fd.append('action','collab_search');fd.append('q',q);
    try{const r=await fetch(location.pathname,{method:'POST',body:fd}),d=JSON.parse((await r.text()).trim().replace(/^[^{]*/,''));
      if(s!==coSeq)return;
      if(!d.users||!d.users.length){coLast=[];coHint('No one found.');return}
      coLast=d.users;coResults();
    }catch(e){if(s===coSeq)coHint('Could not search. Try again.')}
  },300);
}
function coResults(){
  if(!coLast.length)return;const have=new Set(coList().map(u=>u.id));
  $('coResults').innerHTML=coLast.map(u=>'<button type="button" class="tg-row"'+(have.has(u.id)?' disabled':'')+' onclick="coAdd('+u.id+')"><img class="tg-av" src="'+esc(tagPic(u))+'" alt="" onerror="this.src=\'mee.jpg\'"><span class="tg-inf"><strong>@'+esc(u.username)+'</strong></span></button>').join('');
}
async function coAdd(id){
  const u=coLast.find(x=>x.id===id);if(!u||coList().some(x=>x.id===id))return;
  if(coList().length>=14){toast('You can invite up to 14 people');return}
  if(coMode==='edit'){
    const fd=new FormData();fd.append('action','collab_invite');fd.append('meme_id',peCur.id);fd.append('user_ids',String(id));
    try{const r=await fetch(location.pathname,{method:'POST',body:fd}),d=JSON.parse((await r.text()).trim().replace(/^[^{]*/,''));
      if(d.status!=='ok'){toast(d.message||'Could not invite them');return}
      if(d.sent&&d.sent.length===0&&d.skipped){toast('They cannot be invited');return}
      coEdit.push({id:u.id,username:u.username,pic:u.pic,status:'pending'});coRender();
    }catch(e){toast('Connection problem')}
    return;
  }
  coSel.push({id:u.id,username:u.username,pic:u.pic});coRender();
}
async function coRemove(id){
  if(coMode==='edit'){
    const fd=new FormData();fd.append('action','collab_remove');fd.append('meme_id',peCur.id);fd.append('user_id',String(id));
    try{const r=await fetch(location.pathname,{method:'POST',body:fd}),d=JSON.parse((await r.text()).trim().replace(/^[^{]*/,''));
      if(d.status!=='ok'){toast(d.message||'Could not remove them');return}
      coEdit=coEdit.filter(x=>x.id!==id);coRender();peCoRender();
    }catch(e){toast('Connection problem')}
    return;
  }
  coSel=coSel.filter(x=>x.id!==id);coRender();
}
function tagHint(m){$('tagResults').innerHTML='<div class="empty-mini"><i class="ri-user-search-line"></i>'+esc(m)+'</div>'}
function tagSearch(){
  clearTimeout(tagTimer);
  const q=$('tagSearchInput').value.trim().replace(/^@/,'');
  if(q.length<2){tagLast=[];tagHint('Type at least 2 letters.');return}
  tagTimer=setTimeout(async()=>{
    const s=++tagSeq,fd=new FormData();fd.append('tag_action','search');fd.append('q',q);
    try{
      const r=await fetch(location.pathname,{method:'POST',body:fd}),d=await r.json();
      if(s!==tagSeq)return;
      if(!d.success||!d.users||!d.users.length){tagLast=[];tagHint('No one found.');return}
      tagLast=d.users;tagRenderResults();
    }catch(e){if(s===tagSeq)tagHint('Could not search. Try again.')}
  },300);
}
function tagRenderResults(){
  if(!tagLast.length)return;
  const have=new Set(tagArr().map(u=>u.id));
  $('tagResults').innerHTML=tagLast.map(u=>{
    const added=have.has(u.id),dis=added||!u.ok;
    const sub=!u.ok?'<span class="no">'+esc(u.why)+'</span>':(u.nickname?'<span>'+esc(u.nickname)+'</span>':'');
    return '<button type="button" class="tg-row"'+(dis?' disabled':'')+' onclick="tagAdd('+u.id+')"><img class="tg-av" src="'+esc(tagPic(u))+'" alt="" onerror="this.src=\'mee.jpg\'"><span class="tg-inf"><strong>@'+esc(u.username)+'</strong>'+sub+'</span>'+(added?'<span class="tg-end">Added</span>':(u.ok?'<span class="tg-end">Tag</span>':''))+'</button>';
  }).join('');
}
function tagAdd(id){
  const u=tagLast.find(x=>x.id===id);if(!u||!u.ok)return;
  const arr=tagArr();
  if(arr.some(x=>x.id===id))return;
  if(arr.length>=20){toast('You can tag up to 20 people');return}
  arr.push({id:u.id,username:u.username,pic:u.pic});renderTagSel();
  capMention(tagTarget,u.username,true);
}
function tagRemove(id,which){
  const arr=which==='edit'?peTags:(which==='ad'?adTags:tagPost),i=arr.findIndex(x=>x.id===id);
  const gone=i>-1?arr[i]:null;
  if(i>-1)arr.splice(i,1);renderTagSel();
  if(gone)capMention(which==='edit'?'edit':(which==='ad'?'ad':'post'),gone.username,false);
}
/* ── tagging a person also writes their @username into the caption, at the cursor (after any word you chose) ── */
let capRange=null;
document.addEventListener('selectionchange',()=>{
  const c=$('captionContainer'),s=window.getSelection();
  if(c&&s&&s.rangeCount&&c.contains(s.anchorNode))capRange=s.getRangeAt(0).cloneRange();
});
function capTA(target){return $(target==='edit'?'pe-cap':'adCaption')}
function capHas(text,u){return new RegExp('(^|[^A-Za-z0-9_.])@'+u.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'(?![A-Za-z0-9_])','i').test(text)}
function capMention(target,username,add){
  const tag='@'+username;
  if(target==='post'){
    const c=$('captionContainer');if(!c)return;
    const cur=c.innerText;
    if(!add){
      const re=new RegExp('[ \\t]?@'+username.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'(?![A-Za-z0-9_])[ \\t]?','i');
      if(re.test(cur)){c.innerText=cur.replace(re,' ').replace(/[ \t]{2,}/g,' ').trim();capRange=null;syncHiddenCaption()}
      return;
    }
    if(capHas(cur,username))return;
    c.focus();
    const s=window.getSelection();s.removeAllRanges();
    let r=capRange&&c.contains(capRange.startContainer)?capRange:null;
    if(!r){r=document.createRange();r.selectNodeContents(c);r.collapse(false)}
    s.addRange(r);
    const pre=document.createRange();pre.selectNodeContents(c);pre.setEnd(r.startContainer,r.startOffset);
    const before=pre.toString().slice(-1);
    const post=document.createRange();post.selectNodeContents(c);post.setStart(r.endContainer,r.endOffset);
    const after=post.toString().charAt(0);
    document.execCommand('insertText',false,((before&&!/\s/.test(before))?' ':'')+tag+((after&&/\s/.test(after))?'':' '));
    if(s.rangeCount)capRange=s.getRangeAt(0).cloneRange();
    syncHiddenCaption();
    return;
  }
  const ta=capTA(target);if(!ta)return;
  const v=ta.value;
  if(!add){
    const re=new RegExp('[ \\t]?@'+username.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'(?![A-Za-z0-9_])[ \\t]?','i');
    if(re.test(v)){ta.value=v.replace(re,' ').replace(/[ \t]{2,}/g,' ').trim();ta.dispatchEvent(new Event('input'))}
    return;
  }
  if(capHas(v,username))return;
  const a=Math.min(ta.selectionStart==null?v.length:ta.selectionStart,v.length),b=Math.min(ta.selectionEnd==null?a:ta.selectionEnd,v.length);
  const before=v.slice(0,a).slice(-1),after=v.charAt(b),ins=((before&&!/\s/.test(before))?' ':'')+tag+((after&&/\s/.test(after))?'':' ');
  ta.value=v.slice(0,a)+ins+v.slice(b);
  const pos=a+ins.length;ta.selectionStart=ta.selectionEnd=pos;
  ta.dispatchEvent(new Event('input'));
}
// @mentions the server would refuse. Names already in `old` are ignored, same as on the server.
function mentionBad(map,old){
  const prev=new Set((String(old||'').match(/@[A-Za-z0-9_.]{2,40}/g)||[]).map(s=>s.slice(1).replace(/\.+$/,'').toLowerCase()));
  return((map&&map.blocked)||[]).filter(b=>!prev.has(String(b.name).toLowerCase()));
}
async function mentionBlock(text,old){
  const bad=mentionBad(await hlResolve(text),old);
  return bad.length?bad[0].msg:'';
}

/* ═══ Post form logic ═══ */
function setType(t){
  $('post_type').value=t;document.querySelectorAll('#typeSeg button').forEach(b=>b.classList.remove('on'));$(t+'Btn').classList.add('on');
  const isText=t==='text';
  $('fileSection').style.display=isText?'none':'block';
  // cover photo does not apply to text posts: hide its controls and drop any chosen cover
  document.querySelectorAll('.cover-only').forEach(e=>e.style.display=isText?'none':'');
  if(isText&&coverFile)coverClear();
  if(isText){$('editorPanel').style.display='none';updatePreview()}
  else if(t==='video'){$('editorPanel').style.display='none';updateLivePreview()}
  else{if(selectedMedia.length&&selectedMedia[0].type==='image'&&!selectedMedia.some(m=>m.type==='video'))$('editorPanel').style.display='block';updateLivePreview()}
  updateAudioOriginalVisibility();
}
function syncHiddenCaption(){$('caption_hidden').value=$('captionContainer').innerText;if($('post_type').value==='text')updatePreview()}
function insertHash(){const c=$('captionContainer');c.focus();document.execCommand('insertText',false,(c.innerText.trim()&&!/\s$/.test(c.innerText)?' ':'')+'#');syncHiddenCaption()}
function updatePreview(){
  if($('post_type').value!=='text')return;
  const p=$('mediaPreview');
  p.innerHTML='<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:url(\'uploads/Text.jpg\') center/cover;padding:20px;text-align:center;font-size:15px;font-weight:600;color:#fff;text-shadow:0 2px 8px rgba(0,0,0,.8);word-break:break-word">'+esc($('caption_hidden').value)+'</div><span class="preview-label">Text post</span>';
  updateAudioPreviewBadge();
}
function validatePost(){
  syncHiddenCaption();
  if(!/#\w+/.test($('caption_hidden').value.trim())){toast('Please add at least one #hashtag');return false}
  const t=$('post_type').value;
  if(t!=='text'&&!selectedMedia.length){toast('Please select at least one photo or video');return false}
  const m=$('audio_mode').value;
  if(m==='upload'&&!selectedAudio){toast('Select an audio file, or switch to "No sound"');return false}
  if(m==='library'&&!$('selected_audio_id').value){toast('Pick a library track, or switch to "No sound"');return false}
  return true;
}
function prepareFiles(){
  const t=$('post_type').value;
  document.querySelectorAll('.injected-media-input').forEach(e=>e.remove());
  if(t==='image'&&$('editorPanel').style.display!=='none'&&fabricCanvas&&!editFinalized){
    fabricCanvas.discardActiveObject();fabricCanvas.renderAll();$('edited_image_data').value=fabricCanvas.toDataURL({format:'jpeg',quality:.92});
  }
  if(t!=='text'&&selectedMedia.length){
    if(!(t==='image'&&$('edited_image_data').value)){const dt=new DataTransfer();dt.items.add(selectedMedia[0].file);$('fileInput').files=dt.files;$('fileInput').name='meme_file'}
    if(selectedMedia.length>1){
      const x=document.createElement('input');x.type='file';x.name='extra_media[]';x.multiple=true;x.className='injected-media-input';x.style.display='none';
      const dt=new DataTransfer();selectedMedia.slice(1).forEach(m=>dt.items.add(m.file));x.files=dt.files;$('uploadForm').appendChild(x);
    }
  }
  // cover photo: re-attach the chosen file so it is always sent (never for text posts)
  const ci=$('coverFileInput');
  if(t!=='text'&&coverFile){const dc=new DataTransfer();dc.items.add(coverFile);ci.files=dc.files}
  else if(t!=='text'&&autoCoverFile){const dc=new DataTransfer();dc.items.add(autoCoverFile);ci.files=dc.files}   // no cover chosen: a frame of the video is used
  else ci.value='';
}
/* AUTO COVER: when the first media is a video and no cover photo was chosen, grab a still frame in the browser
   and send it as the cover, so profiles/feeds show a light picture instead of loading the video. Never replaces a chosen cover. */
let autoCoverFile=null;
function videoFrameCover(file){
  return new Promise(res=>{
    let done=false,u=null;const v=document.createElement('video');
    const fin=f=>{if(done)return;done=true;try{v.removeAttribute('src');v.load()}catch(e){}if(u)URL.revokeObjectURL(u);res(f)};
    const to=setTimeout(()=>fin(null),8000);
    v.muted=true;v.playsInline=true;v.preload='auto';
    v.onerror=()=>{clearTimeout(to);fin(null)};
    v.onloadedmetadata=()=>{const d=v.duration||0;try{v.currentTime=d>0?Math.min(1,d*0.1):0.1}catch(e){clearTimeout(to);fin(null)}};
    v.onseeked=()=>{
      try{
        const w0=v.videoWidth,h0=v.videoHeight;if(!w0||!h0){clearTimeout(to);return fin(null)}
        const sc=Math.min(1,720/Math.max(w0,h0)),c=document.createElement('canvas');c.width=Math.round(w0*sc);c.height=Math.round(h0*sc);
        c.getContext('2d').drawImage(v,0,0,c.width,c.height);
        c.toBlob(b=>{clearTimeout(to);fin(b?new File([b],'cover.jpg',{type:'image/jpeg'}):null)},'image/jpeg',.82);
      }catch(e){clearTimeout(to);fin(null)}
    };
    u=URL.createObjectURL(file);v.src=u;
  });
}
let posting=false;
async function submitPost(){
  if(posting||!validatePost())return;
  posting=true;$('mainBtn').disabled=true;
  // early check: does anyone @mentioned in the caption not allow it?
  const mb=await mentionBlock($('caption_hidden').value,'');
  if(mb){toast(mb);posting=false;$('mainBtn').disabled=false;return}
  autoCoverFile=null;
  if($('post_type').value!=='text'&&!coverFile&&selectedMedia.length&&selectedMedia[0].type==='video'){
    try{autoCoverFile=await videoFrameCover(selectedMedia[0].file)}catch(e){autoCoverFile=null}
  }
  prepareFiles();
  const fd=new FormData($('uploadForm'));fd.append('upload','1');fd.append('ajax','1');
  PG.show('Posting…');
  try{
    const d=await xhrSend(fd,f=>PG.set(f*96,f<1?'Uploading '+Math.round(f*100)+'%':'Finishing up…'));
    if(!d.success)throw new Error(d.msg||'Could not post.');
    PG.done('Posted!',d.msg||'');
    setTimeout(()=>{location.href=location.pathname},1100);
  }catch(e){PG.fail(e.message);posting=false;$('mainBtn').disabled=false}
}

/* ═══════════════════════════════════════════════════════════
   EDIT POSTS — edit a post that is already live
═══════════════════════════════════════════════════════════ */
var myPosts=<?= json_encode($my_posts,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
let peCur=null,peShown=12,peBusy=false;
let peCoverFile=null,peCoverUrl=null,peCoverRemove=false;
const PE_STEP=12;
const peIsText=p=>p.type==='text'||p.file===''||/text\.jpg/i.test(p.file);
function peTile(p,fn){
  const txt=peIsText(p);
  let inner;
  if(txt)inner='<div class="pe-cap"><span>'+esc(p.caption)+'</span></div>';
  else if(p.cover)inner='<img src="uploads/'+esc(p.cover)+'" alt="" loading="lazy" onerror="this.src=\'uploads/'+esc(p.file)+'\'">';
  else if(p.type==='video')inner='<video src="uploads/'+esc(p.file)+'#t=0.1" muted playsinline preload="metadata"></video>';
  else inner='<img src="uploads/'+esc(p.file)+'" alt="" loading="lazy" onerror="this.src=\'uploads/Text.jpg\'">';
  const flag=p.is_ad?'<span class="pe-flag">AD</span>':'';
  return '<button type="button" class="pe-tile'+(txt?' txt':'')+'" onclick="'+fn+'('+p.id+')" aria-label="Edit post">'+inner+flag+'<span class="pe-ov"><i class="ri-edit-2-line"></i> Edit</span></button>';
}
function peRender(){
  const g=$('peGrid');
  g.innerHTML=myPosts.slice(0,peShown).map(p=>peTile(p,'peOpen')).join('');
  $('peEmpty').style.display=myPosts.length?'none':'block';
  const sc=$('sideCount');if(sc)sc.textContent=myPosts.length;
  $('peMore').style.display=myPosts.length>peShown?'flex':'none';
  const side=$('sideRecent');
  if(side){
    side.innerHTML=myPosts.slice(0,6).map(p=>peTile(p,'peFromSide')).join('');
    $('sideRecentEmpty').style.display=myPosts.length?'none':'block';
  }
}
function peShowMore(){peShown+=PE_STEP;peRender()}
function peFromSide(id){setMode('edit');peOpen(id)}

/* cover photo inside the Edit post window */
function peCoverReset(){
  if(peCoverUrl)URL.revokeObjectURL(peCoverUrl);
  peCoverFile=null;peCoverUrl=null;peCoverRemove=false;$('pe-cover-file').value='';
}
function peCoverRender(){
  const p=peCur;if(!p)return;
  const txt=peIsText(p);
  $('pe-cover-sec').style.display=txt?'none':'';
  if(txt)return;
  let box,has=false;
  if(peCoverUrl){box='<img src="'+peCoverUrl+'" alt="">';has=true}
  else if(p.cover&&!peCoverRemove){box='<img src="uploads/'+esc(p.cover)+'" alt="">';has=true}
  else box=(p.type==='video'?'<video src="uploads/'+esc(p.file)+'#t=0.1" muted playsinline preload="metadata"></video>':'<img src="uploads/'+esc(p.file)+'" alt="">');
  $('pe-cover-box').innerHTML=box;
  $('pe-cover-t').textContent=has?'Custom cover photo':'Using your first media';
  $('pe-cover-s').textContent=peCoverUrl?'New cover. Press Save changes to apply it.':(has?'Shown on profiles and in feeds.':(peCoverRemove?'The cover will be removed when you save.':'Upload an image to use a different cover.'));
  $('pe-cover-btn').textContent=has?'Change cover':'Upload cover';
  $('pe-cover-rm').style.display=has?'':'none';
}
function peCoverPick(input){
  const f=input.files&&input.files[0];input.value='';if(!f)return;
  if(!/^image\/(jpeg|png|gif|webp)$/.test(f.type)){toast('The cover must be a JPG, PNG, GIF or WebP image');return}
  if(f.size>10*1048576){toast('The cover must be under 10 MB');return}
  if(peCoverUrl)URL.revokeObjectURL(peCoverUrl);
  peCoverFile=f;peCoverUrl=URL.createObjectURL(f);peCoverRemove=false;peCoverRender();
}
function peCoverRm(){
  if(peCoverFile){if(peCoverUrl)URL.revokeObjectURL(peCoverUrl);peCoverFile=null;peCoverUrl=null}
  else if(peCur&&peCur.cover)peCoverRemove=true;
  peCoverRender();
}

/* ── edit: photos & videos (saved right away) ── */
function peMediaRender(){
  const txt=peCur&&(peIsText(peCur)||peCur.is_ad);$('pe-media-sec').style.display=txt?'none':'';
  if(txt)return;
  const m=peCur.media||[];
  $('pe-mgrid').innerHTML=m.map((x,i)=>'<div class="pe-mi">'+(x.type==='video'?'<video src="uploads/'+esc(x.file)+'#t=0.1" muted playsinline preload="metadata"></video>':'<img src="uploads/'+esc(x.file)+'" alt="">')+
    (m.length>1?'<button type="button" class="pe-mx" aria-label="Remove" onclick="peRemoveMedia('+i+')">✕</button>':'')+'<span class="pe-mn">'+(i+1)+'</span></div>').join('');
  $('pe-madd').style.display=m.length>=20?'none':'';
}
function peMediaApply(d){
  peCur.media=d.media||peCur.media;peCur.file=d.file||peCur.file;peCur.type=d.ptype||peCur.type;
  peMediaRender();
  const pv=$('pe-prev'),p=peCur;
  if(!p.cover)pv.innerHTML=p.type==='video'?'<video src="uploads/'+esc(p.file)+'#t=0.1" muted playsinline preload="metadata"></video>':'<img src="uploads/'+esc(p.file)+'" alt="">';
  peRender();
}
async function peRemoveMedia(i){
  if(!peCur||peBusy)return;
  if(!confirm('Remove this photo or video from the post?'))return;
  const fd=new FormData();fd.append('post_action','media_remove');fd.append('post_id',peCur.id);fd.append('index',i);
  peBusy=true;PG.show('Removing…');
  try{const d=await xhrSend(fd);if(!d.success)throw new Error(d.msg||'Could not remove it.');PG.hide();peMediaApply(d)}
  catch(e){PG.fail(e.message)}finally{peBusy=false}
}
async function peAddMedia(inp){
  const files=[...(inp.files||[])];inp.value='';
  if(!files.length||!peCur||peBusy)return;
  const room=20-(peCur.media||[]).length;if(room<=0){toast('This post already has 20 photos and videos');return}
  const fd=new FormData();fd.append('post_action','media_add');fd.append('post_id',peCur.id);
  files.slice(0,room).forEach(f=>fd.append('add_media[]',f,f.name));
  peBusy=true;PG.show('Adding…');
  try{const d=await xhrSend(fd,f=>PG.set(f*96,'Uploading…'));if(!d.success)throw new Error(d.msg||'Could not add them.');PG.done('Added','Your post was updated.');setTimeout(()=>PG.hide(),500);peMediaApply(d)}
  catch(e){PG.fail(e.message)}finally{peBusy=false}
}
/* ── edit: collaborators (saved right away) ── */
async function peCoLoad(){
  $('pe-cochips').innerHTML='<span class="hint" style="margin:0">Loading…</span>';
  const fd=new FormData();fd.append('action','collab_manage');fd.append('meme_id',peCur.id);
  try{const r=await fetch(location.pathname,{method:'POST',body:fd}),d=JSON.parse((await r.text()).trim().replace(/^[^{]*/,''));
    coEdit=(d.list||[]).map(u=>({id:u.id,username:u.username,pic:u.pic,status:u.status}))}catch(e){coEdit=[]}
  peCoRender();
}
function peCoRender(){
  $('pe-cochips').innerHTML=coEdit.length?coEdit.map(u=>'<span class="tg-chip"><img src="'+esc(tagPic(u))+'" alt="" onerror="this.src=\'mee.jpg\'"><span>@'+esc(u.username)+(u.status==='pending'?' · invited':'')+'</span><button type="button" aria-label="Remove" onclick="coMode=\'edit\';coRemove('+u.id+')">✕</button></span>').join(''):'<span class="hint" style="margin:0">No collaborators.</span>';
}
function peOpen(id){
  const p=myPosts.find(x=>x.id===id);if(!p)return;peCur=p;
  const txt=peIsText(p),pv=$('pe-prev');
  pv.className='pe-prev'+(txt?' txt':'');
  pv.innerHTML=txt?'<div class="pe-cap" style="background:rgba(0,0,0,.45)"><span>'+esc(p.caption)+'</span></div>'
    :(p.cover?'<img src="uploads/'+esc(p.cover)+'" alt="">'
    :(p.type==='video'?'<video src="uploads/'+esc(p.file)+'#t=0.1" muted playsinline preload="metadata"></video>':'<img src="uploads/'+esc(p.file)+'" alt="">'));
  $('pe-cap').value=p.caption||'';$('pe-cnt').textContent=(p.caption||'').length+'/2200';
  $('pe-partner').checked=!!p.is_partnership;$('pe-brand-wrap').style.display=p.is_partnership?'block':'none';$('pe-brand').value=p.partner_brand||'';
  $('pe-ai').checked=!!p.is_ai_generated;
  $('pe-coff').checked=!!p.comments_off;
  $('pe-hlikes').checked=!!p.hide_likes;$('pe-hreposts').checked=!!p.hide_reposts;$('pe-hsaves').checked=!!p.hide_saves;$('pe-hshares').checked=!!p.hide_shares;
  peZeemId=(p.connected_meme_id&&p.connected_meme_id!==p.id)?p.connected_meme_id:0;
  $('pe-zlabel').value=p.connected_label||'';peRenderZeem();
  peTags=(p.tags||[]).map(t=>({id:t.id,username:t.username,pic:t.pic}));renderTagSel();
  peCoverReset();peCoverRender();
  peMediaRender();peCoLoad();
  openSheet('sh-pe');
}
async function peSave(){
  if(!peCur||peBusy)return;
  const cap=$('pe-cap').value.trim();
  if(peIsText(peCur)&&!cap){toast('A text post needs some text');return}
  if(!/#\w+/.test(cap)){toast('Please keep at least one #hashtag');return}
  // early check: only NEW @mentions count, people already in the old caption stay
  const mb=await mentionBlock(cap,peCur.caption);
  if(mb){toast(mb);return}
  const fd=new FormData();
  fd.append('post_action','edit');fd.append('post_id',peCur.id);fd.append('caption',cap);
  fd.append('is_partnership',$('pe-partner').checked?'1':'0');
  fd.append('partner_brand',$('pe-partner').checked?$('pe-brand').value.trim():'');
  fd.append('is_ai_generated',$('pe-ai').checked?'1':'0');
  fd.append('comments_off',$('pe-coff').checked?'1':'0');
  fd.append('hide_likes',$('pe-hlikes').checked?'1':'0');fd.append('hide_reposts',$('pe-hreposts').checked?'1':'0');fd.append('hide_saves',$('pe-hsaves').checked?'1':'0');fd.append('hide_shares',$('pe-hshares').checked?'1':'0');
  fd.append('tagged_users',peTags.map(t=>t.id).join(','));
  const zid=String(peZeemId||0);
  fd.append('connected_meme_id',zid);fd.append('connected_label',zid!=='0'?$('pe-zlabel').value.trim():'');
  // cover photo (never sent for text posts)
  if(!peIsText(peCur)){
    if(peCoverFile)fd.append('cover_file',peCoverFile,peCoverFile.name);
    else if(peCoverRemove)fd.append('remove_cover','1');
  }
  peBusy=true;$('pe-save').disabled=true;PG.show('Saving changes…');
  try{
    const d=await xhrSend(fd,f=>PG.set(f*96,'Saving…'));
    if(!d.success)throw new Error(d.msg||'Could not save your changes.');
    peCur.caption=cap;peCur.is_partnership=$('pe-partner').checked?1:0;
    peCur.partner_brand=peCur.is_partnership?$('pe-brand').value.trim():'';
    peCur.is_ai_generated=$('pe-ai').checked?1:0;
    peCur.comments_off=$('pe-coff').checked?1:0;
    peCur.hide_likes=$('pe-hlikes').checked?1:0;peCur.hide_reposts=$('pe-hreposts').checked?1:0;peCur.hide_saves=$('pe-hsaves').checked?1:0;peCur.hide_shares=$('pe-hshares').checked?1:0;
    peCur.connected_meme_id=parseInt(zid,10)||0;
    peCur.connected_label=peCur.connected_meme_id?$('pe-zlabel').value.trim().slice(0,40):'';
    peCur.tags=peTags.map(t=>({id:t.id,username:t.username,pic:t.pic}));
    if(!peIsText(peCur)&&typeof d.cover==='string')peCur.cover=d.cover;
    peCoverReset();
    PG.done('Saved','Your post was updated.');
    peRender();
    setTimeout(()=>{PG.hide();closeSheet('sh-pe')},650);
  }catch(e){PG.fail(e.message)}
  finally{peBusy=false;$('pe-save').disabled=false}
}

async function peDelete(){
  if(!peCur||peBusy)return;
  if(!confirm('Delete this post permanently? Its likes, reposts and comments go with it. This cannot be undone.'))return;
  const fd=new FormData();fd.append('post_action','delete');fd.append('post_id',peCur.id);
  peBusy=true;PG.show('Deleting post…');
  try{
    const d=await xhrSend(fd,f=>PG.set(f*96,'Deleting…'));
    if(!d.success)throw new Error(d.msg||'Could not delete this post.');
    const id=peCur.id;
    myPosts=myPosts.filter(x=>x.id!==id);
    myZeems=myZeems.filter(x=>x.id!==id);
    myPosts.forEach(x=>{if(x.connected_meme_id===id){x.connected_meme_id=0;x.connected_label=''}});
    PG.done('Deleted','Your post was removed.');
    peRender();
    setTimeout(()=>{PG.hide();closeSheet('sh-pe')},650);
  }catch(e){PG.fail(e.message)}
  finally{peBusy=false}
}

/* ═══════════════════════════════════════════════════════════
   HIGHLIGHTS
═══════════════════════════════════════════════════════════ */
const HL_MAX=100,HL_IMG_MB=10,HL_VID_MB=50;
const HL_TEXT_BG=<?= json_encode(HL_TEXT_BG) ?>,HL_USER_URL=<?= json_encode(HL_USER_URL) ?>,HL_GROUP_URL=<?= json_encode(HL_GROUP_URL) ?>;
let hlItems=[],hlMode='whole',hlCover=null,hlSong=null,hlCur=null,hlDirty=false,hlBusy=false,hlProg=null;

async function hlCall(action,fields,files){
  const fd=new FormData();fd.append('hl_action',action);
  Object.keys(fields||{}).forEach(k=>fd.append(k,fields[k]));
  Object.keys(files||{}).forEach(k=>fd.append(k,files[k],files[k].name||'clip.wav'));
  const d=await xhrSend(fd,hlProg);
  if(!d.success)throw new Error(d.msg||'Something went wrong.');
  return d;
}
const hlResolveCache={};
async function hlResolve(text){
  const t=String(text||'').trim();if(!t)return{users:{},groups:[],blocked:[]};
  if(hlResolveCache[t])return hlResolveCache[t];
  const keep=hlProg;hlProg=null;
  try{const d=await hlCall('resolve',{text:t});return hlResolveCache[t]={users:d.users||{},groups:d.groups||[],blocked:d.blocked||[]}}
  catch(e){return{users:{},groups:[],blocked:[]}}finally{hlProg=keep}
}
function hlRichText(text,map){
  text=String(text||'');map=map||{};const users=map.users||{};
  const groups=(map.groups||[]).slice().sort((a,b)=>b.name.length-a.name.length);
  const gid={};groups.forEach(g=>gid[g.name.toLowerCase()]=g.id);
  let re='(@[A-Za-z0-9_.]+)';
  if(groups.length)re+='|(^|[^\\w@])('+groups.map(g=>g.name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')).join('|')+')(?![\\w])';
  const rx=new RegExp(re,'gi');let out='',last=0,m;
  while((m=rx.exec(text))!==null){
    if(m[1]){
      const core=m[1].slice(1).replace(/\.+$/,''),id=users[core.toLowerCase()];if(!id)continue;
      out+=esc(text.slice(last,m.index))+'<a class="men user" href="'+esc(HL_USER_URL+id)+'">@'+esc(core)+'</a>';last=m.index+1+core.length;rx.lastIndex=last;
    }else{
      const st=m.index+m[2].length,id=gid[m[3].toLowerCase()];if(!id)continue;
      out+=esc(text.slice(last,st))+'<a class="men group" href="'+esc(HL_GROUP_URL+id)+'">'+esc(m[3])+'</a>';last=st+m[3].length;
    }
  }
  return(out+esc(text.slice(last))).replace(/\n/g,'<br>');
}
function hlCapHTML(text,map){
  const long=text.length>120||(text.match(/\n/g)||[]).length>=3;
  return '<div class="cap-wrap"><div class="cap'+(long?' clamp':'')+'">'+hlRichText(text,map)+'</div>'+(long?'<button type="button" class="more" onclick="hlMore(this)">more</button>':'')+'</div>';
}
function hlMore(b){const c=b.previousElementSibling,col=c.classList.toggle('clamp');b.textContent=col?'more':'less'}

function hlFilterFiles(list,max){
  let ok=[],skipped=0;
  list.forEach(f=>{const i=f.type.startsWith('image/'),v=f.type.startsWith('video/');if(!i&&!v){skipped++;return}if(f.size>(i?HL_IMG_MB:HL_VID_MB)*1048576){skipped++;return}ok.push(f)});
  let trimmed=false;if(ok.length>max){ok=ok.slice(0,max);trimmed=true}
  return{ok,skipped,trimmed};
}
const hlMake=files=>files.map(f=>({file:f,kind:f.type.startsWith('video/')?'video':'image',text:'',song:null,mute:false,url:URL.createObjectURL(f)}));
const hlFree=a=>a.forEach(i=>{if(i.url)URL.revokeObjectURL(i.url)});
function hlSetStatus(m,err){const s=$('hl-status');s.textContent=m||'';s.className='status'+(err?' err':'')}

$('hl-files').addEventListener('change',function(){
  const texts=hlItems.filter(i=>i.kind==='text');
  const r=hlFilterFiles(Array.from(this.files||[]),Math.max(0,HL_MAX-texts.length));
  let msg='';if(r.trimmed)msg='Only the first '+HL_MAX+' media were kept.';
  if(r.skipped)msg=(msg?msg+' ':'')+r.skipped+' file'+(r.skipped===1?' was':'s were')+' skipped (wrong type or too large).';
  hlFree(hlItems.filter(i=>i.kind!=='text'));hlItems=hlMake(r.ok).concat(texts);
  hlRenderPick();hlRenderItems();hlSetStatus(msg,!!msg&&!hlItems.length);this.value='';
});
$('hl-cover-file').addEventListener('change',function(){
  const f=this.files&&this.files[0];this.value='';if(!f)return;
  if(!f.type.startsWith('image/')||f.size>HL_IMG_MB*1048576){hlSetStatus('The cover must be an image under '+HL_IMG_MB+' MB.',true);return}
  hlCover=f;hlRenderExtras();hlSetStatus('');
});
function hlRenderPick(){
  const prev=$('hl-cover-prev'),icon=$('hl-pick-icon');
  if(prev._url){URL.revokeObjectURL(prev._url);prev._url=null}
  if(!hlItems.length){$('hl-pick-title').textContent='Choose photos or videos';$('hl-pick-sub').textContent='Add 1 to 100 per highlight.';prev.classList.remove('show');icon.style.display='';icon.className='ri-image-add-line';return}
  $('hl-pick-title').textContent=hlItems.length+' of '+HL_MAX+' selected';$('hl-pick-sub').textContent='Tap to change your photos and videos.';
  const f=hlItems[0];
  if(f.kind==='image'){prev._url=URL.createObjectURL(f.file);prev.src=prev._url;prev.classList.add('show');icon.style.display='none'}
  else{prev.classList.remove('show');icon.style.display='';icon.className=f.kind==='video'?'ri-video-line':'ri-text'}
}
function hlRenderExtras(){
  const p=$('hl-cv-prev'),ic=$('hl-cv-icon');if(p._url){URL.revokeObjectURL(p._url);p._url=null}
  if(hlCover){p._url=URL.createObjectURL(hlCover);p.src=p._url;p.classList.add('show');ic.style.display='none';$('hl-cv-title').textContent='Cover image added';$('hl-cv-sub').textContent=shortT(hlCover.name,34)}
  else{p.classList.remove('show');ic.style.display='';$('hl-cv-title').textContent='Cover image (optional)';$('hl-cv-sub').textContent='Skip it and your first media becomes the cover.'}
  $('hl-cv-x').classList.toggle('show',!!hlCover);
  $('hl-song-title').textContent=hlSong?'♪ '+shortT(hlSong.title||'Song clip',30):'Song clip (optional)';
  $('hl-song-sub').textContent=hlSong?Math.round(hlSong.secs)+' second clip. Tap to change it.':'Pick the part of a song you want, up to 30 seconds.';
  $('hl-song-x').classList.toggle('show',!!hlSong);
}
function hlCoverClear(){hlCover=null;hlRenderExtras()}
function hlSongClear(){hlSong=null;hlRenderExtras()}
function hlSongPick(){cpOpen(r=>{hlSong=r;hlRenderExtras()})}
function hlSetMode(m){
  hlMode=m;document.querySelectorAll('#hl-mode button').forEach(b=>b.classList.toggle('on',b.dataset.mode===m));
  $('hl-song-wrap').style.display=m==='whole'?'':'none';
  $('hl-mode-help').textContent=m==='whole'?'One song clip plays across every media in this highlight.':'Each media can have its own song clip. Choose your media, then tap Add song on the ones you want.';
  hlRenderItems();
}
function hlRenderItems(){
  const box=$('hl-items');
  if(!hlItems.length){box.innerHTML='';return}
  box.innerHTML='<p class="hint" style="margin:0 0 8px">Tap Add text to write words on a media (tag people with @name).'+(hlMode==='each'?' Tap Add song on any media that should have its own song.':'')+(hlItems.some(i=>i.kind==='video')?' Videos: Sound keeps the video\'s audio, Muted lets only the song play.':'')+'</p><div class="hl-rows">'+
  hlItems.map((it,i)=>{
    let a='<button type="button" class="mini'+(it.text?' on':'')+'" onclick="hlItemText('+i+')"><i class="ri-text"></i>'+(it.text?'Edit text':'Add text')+'</button>';
    if(hlMode==='each'){
      a+='<button type="button" class="mini'+(it.song?' on':'')+'" onclick="hlItemSong('+i+')"><i class="ri-music-2-line"></i>'+(it.song?esc(shortT(it.song.title||'Song',12)):'Add song')+'</button>';
      if(it.song)a+='<button type="button" class="mini" aria-label="Remove song" onclick="hlItemSongRm('+i+')"><i class="ri-close-line"></i></button>';
    }
    if(it.kind==='video')a+='<button type="button" class="mini'+(it.mute?' on':'')+'" onclick="hlItemMute('+i+')"><i class="'+(it.mute?'ri-volume-mute-line':'ri-volume-up-line')+'"></i>'+(it.mute?'Muted':'Sound')+'</button>';
    const th=it.kind==='image'?'<img src="'+it.url+'" alt="">':'<i class="'+(it.kind==='video'?'ri-video-line':'ri-text')+'"></i>';
    const nm=it.kind==='text'?'Text: '+shortT(it.text.replace(/\s+/g,' '),24):shortT(it.file.name,28);
    return '<div class="hl-row"><div class="hl-th">'+th+'</div><div class="hl-inf"><strong>'+esc(nm)+'</strong><div class="acts">'+a+'</div></div><button type="button" class="hl-x show" aria-label="Remove" onclick="hlItemDel('+i+')"><i class="ri-close-line"></i></button></div>';
  }).join('')+'</div>';
}
function hlItemSong(i){cpOpen(r=>{if(hlItems[i]){hlItems[i].song=r;hlRenderItems()}})}
function hlItemSongRm(i){if(hlItems[i]){hlItems[i].song=null;hlRenderItems()}}
function hlItemMute(i){if(hlItems[i]){hlItems[i].mute=!hlItems[i].mute;hlRenderItems()}}
function hlItemDel(i){const it=hlItems[i];if(!it)return;if(it.url)URL.revokeObjectURL(it.url);hlItems.splice(i,1);hlRenderPick();hlRenderItems()}
function hlItemText(i){
  const it=hlItems[i];if(!it)return;
  htOpen({src:it.url,type:it.kind,text:it.text,required:it.kind==='text',allowRemove:it.kind!=='text'&&!!it.text},t=>{if(hlItems[i]){hlItems[i].text=t;hlRenderPick();hlRenderItems()}});
}
function hlAddText(){
  if(hlItems.length>=HL_MAX){toast('A highlight can hold up to '+HL_MAX+' media.');return}
  htOpen({src:null,type:'text',text:'',required:true,allowRemove:false},t=>{hlItems.push({file:null,kind:'text',text:t,song:null,mute:false,url:null});hlRenderPick();hlRenderItems()});
}

async function hlUpload(){
  if(hlBusy)return;
  const name=$('hl-title').value.trim();
  if(!name){hlSetStatus('Give your highlight a name.',true);$('hl-title').focus();return}
  if(!hlItems.length){hlSetStatus('Choose at least one photo or video, or add a text highlight.',true);return}
  hlBusy=true;$('mainBtn').disabled=true;hlSetStatus('');
  const useSong=hlMode==='whole'&&hlSong,items=hlItems.slice();
  const steps=1+items.length+(hlCover?1:0)+(useSong?1:0);let stepNo=0,done=0,failed=0,lastErr='',extraErr='',hid=0;
  const label=t=>t;
  hlProg=f=>PG.set(Math.min(97,(stepNo+f)/steps*100));
  PG.show('Creating highlight…');
  try{
    PG.set(0,'Creating highlight…');
    hid=(await hlCall('create',{title:name,mode:hlMode})).id;stepNo++;
    for(let i=0;i<items.length;i++){
      const it=items[i];PG.set((stepNo/steps)*100,label('Uploading '+(i+1)+' of '+items.length+'…'));
      try{
        const r=it.kind==='text'?await hlCall('add_text',{highlight_id:hid,text:it.text}):await hlCall('add_media',{highlight_id:hid,caption:it.text||''},{media:it.file});
        done++;
        if(hlMode==='each'&&it.song){try{await hlCall('media_song',{highlight_id:hid,media_id:r.id,title:it.song.title||''},{song:it.song.blob})}catch(e){extraErr='Some songs did not upload ('+e.message+')'}}
        if(it.kind==='video'&&it.mute){try{await hlCall('media_mute',{highlight_id:hid,media_id:r.id,mute:1})}catch(e){}}
      }catch(e){failed++;lastErr=e.message}
      stepNo++;
    }
    if(done===0){try{await hlCall('delete',{highlight_id:hid})}catch(e){}throw new Error(lastErr||'No files could be uploaded.')}
    if(hlCover){PG.set((stepNo/steps)*100,'Uploading cover…');try{await hlCall('set_cover',{highlight_id:hid},{cover:hlCover})}catch(e){extraErr+=(extraErr?' ':'')+'Cover: '+e.message}stepNo++}
    if(useSong){PG.set((stepNo/steps)*100,'Uploading song clip…');try{await hlCall('set_song',{highlight_id:hid,title:hlSong.title||''},{song:hlSong.blob})}catch(e){extraErr+=(extraErr?' ':'')+'Song: '+e.message}stepNo++}
    hlProg=null;PG.done('Highlight created',extraErr||(failed?failed+' item'+(failed===1?'':'s')+' failed to upload.':''));
    setTimeout(()=>{location.href=location.pathname+'?mode=highlights'},extraErr?1800:1000);
  }catch(e){hlProg=null;PG.fail(e.message||'Upload failed.');hlBusy=false;$('mainBtn').disabled=false}
}

/* ── Text sheet ── */
const HT={cb:null,o:null,timer:null,seq:0,map:null};
function htOpen(o,cb){
  HT.cb=cb;HT.o=o;HT.map=null;$('ht-input').value=o.text||'';$('ht-status').textContent='';$('ht-status').className='status';
  $('ht-remove').style.display=o.allowRemove?'':'none';
  let bg;
  if(o.type==='image'&&o.src)bg='<img src="'+esc(o.src)+'" alt="">';
  else if(o.type==='video'&&o.src)bg='<video src="'+esc(o.src)+'" muted playsinline loop autoplay></video>';
  else bg='<img src="'+esc(HL_TEXT_BG)+'" alt="">';
  $('ht-preview').innerHTML='<div class="hl-view">'+bg+'<div id="ht-capbox"></div></div>';
  openSheet('sh-ht');htUpdate(true);setTimeout(()=>$('ht-input').focus({preventScroll:true}),80);
}
function htRender(t){const b=$('ht-capbox');if(!b)return;b.innerHTML=t.trim()?hlCapHTML(t,HT.map):'<div class="cap-wrap"><div class="cap cap-empty">Your text appears here</div></div>'}
function htUpdate(now){
  const t=$('ht-input').value;$('ht-count').textContent=t.length+'/1000';htRender(t);clearTimeout(HT.timer);
  HT.timer=setTimeout(async()=>{
    const s=++HT.seq;const m=await hlResolve(t);if(s!==HT.seq)return;
    HT.map=m;htRender($('ht-input').value);
    // live warning: someone @mentioned does not allow it
    const bad=mentionBad(m,HT.o&&HT.o.text),st=$('ht-status');
    if(bad.length){st.textContent=bad[0].msg;st.className='status err'}
    else if(st.className.indexOf('err')>-1&&st.textContent.indexOf('@')===0){st.textContent='';st.className='status'}
  },now?0:350);
}
async function htDone(){
  const t=$('ht-input').value.trim(),st=$('ht-status');
  if(HT.o&&HT.o.required&&!t){st.textContent='Write something first.';st.className='status err';return}
  const bad=mentionBad(await hlResolve(t),HT.o&&HT.o.text);
  if(bad.length){st.textContent=bad[0].msg;st.className='status err';return}
  const cb=HT.cb;htClose();if(cb)cb(t);
}
function htRemove(){const cb=HT.cb;htClose();if(cb)cb('')}
function htClose(){clearTimeout(HT.timer);HT.seq++;HT.cb=null;$('sh-ht').classList.remove('open');$('ht-preview').innerHTML='';if(!document.querySelector('.sheet.open'))document.body.style.overflow=''}

/* ── Song clip picker ── */
const CP={file:null,url:null,dur:0,cb:null,audio:null,timer:null};
const cpFmt=t=>{t=Math.max(0,t);return Math.floor(t/60)+':'+String(Math.floor(t%60)).padStart(2,'0')};
const cpLen=()=>Math.min(+$('cp-len').value,CP.dur||0);
function cpOpen(cb){CP.cb=cb;cpReset();openSheet('sh-clip')}
function cpReset(){
  cpStop();if(CP.url)URL.revokeObjectURL(CP.url);CP.file=null;CP.url=null;CP.dur=0;CP.audio=null;
  $('cp-editor').style.display='none';$('cp-file-title').textContent='Choose a song';$('cp-title').value='';cpStatus('');
  $('cp-use').disabled=false;$('cp-use').textContent='Use this clip';
}
function cpClose(){cpReset();CP.cb=null;$('sh-clip').classList.remove('open');if(!document.querySelector('.sheet.open'))document.body.style.overflow=''}
function cpStatus(m,err){const s=$('cp-status');s.textContent=m||'';s.className='status'+(err?' err':'')}
$('cp-file').addEventListener('change',function(){
  const f=this.files&&this.files[0];this.value='';if(!f)return;
  if(!f.type.startsWith('audio/')&&!/\.(mp3|m4a|aac|wav|ogg|flac|opus)$/i.test(f.name)){cpStatus('Please choose an audio file.',true);return}
  if(f.size>40*1048576){cpStatus('That file is too large (40 MB max).',true);return}
  cpStop();if(CP.url)URL.revokeObjectURL(CP.url);CP.file=f;CP.url=URL.createObjectURL(f);
  const a=new Audio();a.preload='metadata';a.src=CP.url;CP.audio=a;cpStatus('Loading song…');
  a.addEventListener('loadedmetadata',()=>{
    if(!isFinite(a.duration)||a.duration<1){cpStatus('Could not read that song.',true);return}
    CP.dur=a.duration;$('cp-file-title').textContent=shortT(f.name,34);$('cp-title').value=f.name.replace(/\.[^.]+$/,'').slice(0,60);
    $('cp-start').value=0;$('cp-editor').style.display='block';cpStatus('');cpUpdate();
  },{once:true});
  a.addEventListener('error',()=>cpStatus('This browser cannot play that file. Try MP3, M4A or WAV.',true),{once:true});
});
function cpUpdate(){
  if(!CP.dur)return;const len=cpLen(),sl=$('cp-start');sl.max=Math.max(0,CP.dur-len);if(+sl.value>+sl.max)sl.value=sl.max;
  const st=+sl.value;$('cp-start-lbl').textContent=cpFmt(st);$('cp-end-lbl').textContent=cpFmt(st+len);
  if(CP.audio&&!CP.audio.paused)cpStop();
}
function cpStop(){clearInterval(CP.timer);if(CP.audio)CP.audio.pause();const t=$('cp-prev-txt');if(t)t.textContent='Preview clip'}
function cpPreview(){
  const a=CP.audio;if(!a||!CP.dur)return;if(!a.paused){cpStop();return}
  const st=+$('cp-start').value,len=cpLen();a.currentTime=st;a.play().catch(()=>cpStatus('Could not play the preview.',true));
  $('cp-prev-txt').textContent='Stop preview';clearInterval(CP.timer);
  CP.timer=setInterval(()=>{if(a.currentTime>=st+len||a.ended)cpStop()},100);
}
async function cpBuildClip(file,start,len){
  const AC=window.AudioContext||window.webkitAudioContext,ctx=new AC();let buf;
  try{buf=await ctx.decodeAudioData(await file.arrayBuffer())}finally{if(ctx.close)ctx.close()}
  const sr=buf.sampleRate,ch=Math.min(2,buf.numberOfChannels),from=Math.floor(start*sr),to=Math.min(buf.length,Math.floor((start+len)*sr)),frames=to-from;
  if(frames<sr)throw new Error('That clip is too short.');
  const RATE=32000,off=new OfflineAudioContext(ch,Math.max(1,Math.ceil(frames*RATE/sr)),RATE),slice=off.createBuffer(ch,frames,sr);
  for(let c=0;c<ch;c++)slice.copyToChannel(buf.getChannelData(c).subarray(from,to),c);
  const src=off.createBufferSource();src.buffer=slice;src.connect(off.destination);src.start();
  return cpWav(await off.startRendering());
}
function cpWav(b){
  const ch=b.numberOfChannels,n=b.length,sr=b.sampleRate,bytes=n*ch*2,ab=new ArrayBuffer(44+bytes),v=new DataView(ab);
  const w=(o,t)=>{for(let i=0;i<t.length;i++)v.setUint8(o+i,t.charCodeAt(i))};
  w(0,'RIFF');v.setUint32(4,36+bytes,true);w(8,'WAVE');w(12,'fmt ');v.setUint32(16,16,true);v.setUint16(20,1,true);v.setUint16(22,ch,true);
  v.setUint32(24,sr,true);v.setUint32(28,sr*ch*2,true);v.setUint16(32,ch*2,true);v.setUint16(34,16,true);w(36,'data');v.setUint32(40,bytes,true);
  const fade=Math.min(Math.floor(sr*.15),Math.floor(n/2)),d=[];for(let c=0;c<ch;c++)d.push(b.getChannelData(c));
  let o=44;
  for(let i=0;i<n;i++){const g=i<fade?i/fade:(i>n-fade?(n-i)/fade:1);for(let c=0;c<ch;c++){const x=Math.max(-1,Math.min(1,d[c][i]*g));v.setInt16(o,x<0?x*0x8000:x*0x7fff,true);o+=2}}
  return new Blob([ab],{type:'audio/wav'});
}
async function cpUse(btn){
  if(!CP.file||!CP.dur)return;
  const len=cpLen(),start=+$('cp-start').value,title=$('cp-title').value.trim();
  cpStop();btn.disabled=true;btn.textContent='Preparing clip…';cpStatus('');
  try{
    const blob=await cpBuildClip(CP.file,start,len);
    if(blob.size>6.5*1048576)throw new Error('That clip is too big. Try a shorter length.');
    const cb=CP.cb;await cb({blob,title,secs:len});cpClose();
  }catch(e){btn.disabled=false;btn.textContent='Use this clip';cpStatus(e&&e.message?e.message:'Could not prepare that clip.',true)}
}

/* ── Highlight editor (changes apply right away) ── */
async function hlRun(label,fn,files){
  if(hlBusy)return;hlBusy=true;
  let n=0;PG.show(label);
  hlProg=f=>PG.set(f*96,'Uploading…');
  try{await fn();hlDirty=true;PG.done('Saved');setTimeout(PG.hide,500);await hlRefresh()}
  catch(e){PG.fail(e.message)}
  finally{hlProg=null;hlBusy=false}
}
async function hlEdit(id){
  hlDirty=false;hlCur=null;$('hle-root').innerHTML='<div class="empty-mini">Loading…</div>';openSheet('sh-hl-edit');
  try{hlCur=(await hlCall('get',{highlight_id:id})).h;hlRenderEdit()}catch(e){toast(e.message);closeSheet('sh-hl-edit')}
}
async function hlRefresh(){hlCur=(await hlCall('get',{highlight_id:hlCur.id})).h;hlRenderEdit()}
function hlThumb(p,t,text){
  let h=t==='video'?'<video src="'+esc(p)+'#t=0.1" muted playsinline preload="metadata"></video><span class="hl-vid"><i class="ri-play-fill"></i></span>':t==='text'?'<img src="'+esc(HL_TEXT_BG)+'" alt="">':'<img src="'+esc(p)+'" alt="" loading="lazy">';
  if(text)h+='<span class="tilecap">'+esc(shortT(text.replace(/\s+/g,' '),90))+'</span>';return h;
}
function hlRenderEdit(){
  const h=hlCur,each=h.mode==='each',first=h.media[0];
  const cover=h.cover?'<img src="'+esc(h.cover)+'" alt="">':(first?hlThumb(first.path,first.type,''):'<img src="'+esc(HL_TEXT_BG)+'" alt="">');
  const tiles=h.media.map(m=>'<div><div class="hl-sq">'+hlThumb(m.path,m.type,m.caption)+'<button class="hl-edit" type="button" aria-label="Remove media" onclick="hlMediaDel('+m.id+')"><i class="ri-close-line"></i></button></div>'+
    '<div class="hl-cnt" style="margin-top:5px">Added '+esc(m.added)+'</div><div class="acts">'+
    '<button type="button" class="mini'+(m.caption?' on':'')+'" onclick="hlMediaText('+m.id+')"><i class="ri-text"></i>'+(m.caption?'Edit text':'Add text')+'</button>'+
    (each?'<button type="button" class="mini'+(m.audio?' on':'')+'" onclick="hlMediaSong('+m.id+')"><i class="ri-music-2-line"></i>'+(m.audio?esc(shortT(m.audio_title||'Song',9)):'Add song')+'</button>'+(m.audio?'<button type="button" class="mini" onclick="hlMediaSongRm('+m.id+')"><i class="ri-close-line"></i></button>':''):'')+
    (m.type==='video'?'<button type="button" class="mini'+(m.mute?' on':'')+'" onclick="hlMediaMute('+m.id+','+(m.mute?0:1)+')"><i class="'+(m.mute?'ri-volume-mute-line':'ri-volume-up-line')+'"></i>'+(m.mute?'Muted':'Sound')+'</button>':'')+'</div></div>').join('');
  let music;
  if(!each){
    music=h.song?'<div class="audio-pill" style="margin-top:0"><i class="ri-music-2-fill" style="color:var(--accent)"></i><span>'+esc(h.song_title||'Song clip')+'</span></div><audio controls preload="none" src="'+esc(h.song)+'" style="width:100%;height:32px;margin-top:8px"></audio><div class="btns"><button class="btn sm ghost" onclick="hlSongSetE()">Change song</button><button class="btn sm danger" onclick="hlSongRmE()">Remove song</button></div>'
      :'<p class="hint">No song yet. One clip plays across every media.</p><div class="btns"><button class="btn sm" onclick="hlSongSetE()">Add a song clip</button></div>';
  }else music='<p class="hint">Each media has its own song. Tap Add song under a media below. Media with no song stay silent.</p>';
  const count=h.media.length;
  $('hle-root').innerHTML=
   '<div class="sec"><div class="sec-h">Name</div><div style="display:flex;gap:8px"><input type="text" class="f-input" id="hle-title" maxlength="30" value="'+esc(h.title)+'"><button class="btn sm" style="flex-shrink:0" onclick="hlRename()">Save</button></div></div>'+
   '<div class="sec"><div class="sec-h">Cover</div><div class="ed-cover"><div class="hl-sq">'+cover+'</div><div class="hl-pt"><strong>'+(h.cover?'Custom cover':'Using your first media')+'</strong><span>'+(h.cover?'Not part of the media in this highlight.':'Upload an image to use a different cover.')+'</span><div class="btns" style="margin-top:8px"><span class="btn sm file-btn" style="display:inline-flex;width:auto">'+(h.cover?'Change cover':'Upload cover')+'<input type="file" accept="image/*" onchange="hlCoverPick(this)"></span>'+(h.cover?'<button class="btn sm danger" onclick="hlCoverRmE()">Remove</button>':'')+'</div></div></div></div>'+
   '<div class="sec"><div class="sec-h">Music</div><div class="seg tight"><button type="button" class="'+(each?'':'on')+'" onclick="hlModeE(\'whole\')">One song for all</button><button type="button" class="'+(each?'on':'')+'" onclick="hlModeE(\'each\')">Song per media</button></div><div style="margin-top:12px">'+music+'</div></div>'+
   '<div class="sec"><div class="sec-h">Media · '+count+' of '+HL_MAX+'</div><div class="ed-grid">'+tiles+'</div>'+
   (count<HL_MAX?'<div class="btns"><span class="btn sm file-btn" style="display:inline-flex;width:auto">Add more media<input type="file" accept="image/*,video/*" multiple onchange="hlAddMore(this)"></span><button class="btn sm ghost" onclick="hlAddTextE()">Add text highlight</button></div>':'<p class="hint">This highlight is full ('+HL_MAX+' media).</p>')+'</div>'+
   '<div class="sec"><button class="btn danger" onclick="hlDeleteAll()">Delete this highlight</button><p class="hint" style="margin-top:8px">Permanently deletes the highlight, its media, cover and songs.</p></div>';
}
function hlRename(){const t=$('hle-title').value.trim();if(!t){toast('Give your highlight a name.');return}hlRun('Saving name…',()=>hlCall('rename',{highlight_id:hlCur.id,title:t}))}
function hlCoverPick(i){const f=i.files&&i.files[0];i.value='';if(!f)return;if(!f.type.startsWith('image/')||f.size>HL_IMG_MB*1048576){toast('The cover must be an image under '+HL_IMG_MB+' MB.');return}hlRun('Uploading cover…',()=>hlCall('set_cover',{highlight_id:hlCur.id},{cover:f}))}
function hlCoverRmE(){hlRun('Removing cover…',()=>hlCall('rm_cover',{highlight_id:hlCur.id}))}
function hlModeE(m){if(m===hlCur.mode)return;hlRun('Saving…',()=>hlCall('set_mode',{highlight_id:hlCur.id,mode:m}))}
function hlSongSetE(){cpOpen(r=>hlRun('Uploading song clip…',()=>hlCall('set_song',{highlight_id:hlCur.id,title:r.title||''},{song:r.blob})))}
function hlSongRmE(){hlRun('Removing song…',()=>hlCall('rm_song',{highlight_id:hlCur.id}))}
function hlMediaSong(mid){cpOpen(r=>hlRun('Uploading song…',()=>hlCall('media_song',{highlight_id:hlCur.id,media_id:mid,title:r.title||''},{song:r.blob})))}
function hlMediaSongRm(mid){hlRun('Removing song…',()=>hlCall('media_song_rm',{highlight_id:hlCur.id,media_id:mid}))}
function hlMediaMute(mid,v){hlRun('Saving…',()=>hlCall('media_mute',{highlight_id:hlCur.id,media_id:mid,mute:v}))}
function hlMediaText(mid){
  const m=hlCur.media.find(x=>x.id===mid);if(!m)return;
  htOpen({src:m.path,type:m.type,text:m.caption||'',required:m.type==='text',allowRemove:m.type!=='text'&&!!m.caption},t=>hlRun('Saving text…',()=>hlCall('set_caption',{highlight_id:hlCur.id,media_id:mid,text:t})));
}
function hlMediaDel(mid){
  if(hlCur.media.length<=1){toast('A highlight needs at least one media. Delete the highlight instead.');return}
  if(!confirm('Remove this media from the highlight?'))return;
  hlRun('Removing media…',()=>hlCall('del_media',{highlight_id:hlCur.id,media_id:mid}));
}
function hlAddTextE(){htOpen({src:null,type:'text',text:'',required:true,allowRemove:false},t=>hlRun('Adding text…',()=>hlCall('add_text',{highlight_id:hlCur.id,text:t})))}
function hlAddMore(input){
  const room=HL_MAX-hlCur.media.length,r=hlFilterFiles(Array.from(input.files||[]),Math.max(0,room));input.value='';
  if(!r.ok.length){toast(room<=0?'This highlight is full.':'No valid files selected');return}
  hlRun('Adding media…',async()=>{
    let i=0;const total=r.ok.length;
    for(const f of r.ok){
      hlProg=fr=>PG.set(((i+fr)/total)*96,'Uploading '+(i+1)+' of '+total+'…');
      await hlCall('add_media',{highlight_id:hlCur.id},{media:f});i++;
    }
  });
}
function hlDeleteAll(){
  if(!confirm('Delete this highlight and everything in it?'))return;
  hlBusy=true;
  hlCall('delete',{highlight_id:hlCur.id}).then(()=>{hlBusy=false;hlDirty=true;toast('Highlight deleted');closeSheet('sh-hl-edit')}).catch(e=>{hlBusy=false;toast(e.message)});
}

/* ── Review tags (tags of YOU waiting for approval) ── */
var pendingTags=<?= json_encode($pending_tags,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
function ptRender(){
  $('ptBox').style.display=pendingTags.length?'':'none';$('ptCount').textContent=pendingTags.length;
  $('ptList').innerHTML=pendingTags.map(t=>'<div class="tg-row" style="cursor:default"><img class="tg-av" src="'+esc(t.pic||'mee.jpg')+'" alt="" onerror="this.src=\'mee.jpg\'"><span class="tg-inf"><strong>@'+esc(t.by)+' tagged you</strong><span>'+esc(shortT((t.caption||'').replace(/\s+/g,' '),70)||'(no caption)')+'</span></span><button type="button" class="mini on" onclick="ptAct('+t.id+',\'accept\')">Accept</button><button type="button" class="mini" onclick="ptAct('+t.id+',\'remove\')">Remove</button></div>').join('');
}
async function ptAct(id,act){
  const fd=new FormData();fd.append('tag_action',act);fd.append('tag_id',id);
  try{
    const r=await fetch(location.pathname,{method:'POST',body:fd}),d=await r.json();
    if(!d.success)throw new Error(d.msg||'Could not save.');
    pendingTags=pendingTags.filter(x=>x.id!==id);ptRender();toast(act==='accept'?'Tag accepted':'Tag removed');
  }catch(e){toast(e.message)}
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded',()=>{
  $('fileInput').setAttribute('name','meme_file');
  peRender();renderTagSel();ptRender();coverRender();
  if(_preselectedAudio){setAudioMode('library');selectLibraryAudio(_preselectedAudio.id,_preselectedAudio.label,_preselectedAudio.url)}
  const startMode=<?= json_encode($open_mode) ?>,startEdit=<?= (int)$open_edit_id ?>;
  if(startMode!=='post')setMode(startMode);
  if(startEdit>0)peOpen(startEdit);
});
</script>
</body>
</html>