<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // keeps JSON clean (errors still go to your PHP log)
session_start();
include 'config.php';

if(!isset($_SESSION['user_id'])){ header("Location: login.php"); exit(); }
$me = (int)$_SESSION['user_id'];
$current_user = $me;

/* ═══════════════════════════════════════════════════════════
   SETTINGS
═══════════════════════════════════════════════════════════ */
const DL_MAX_MEDIA  = 30;    // media in one Dayleez
const DL_MAX_ACTIVE = 60;    // media a person can have live at once
const DL_MAX_TEXT   = 1000;
const DL_DIR        = 'uploads/dayleez/';
const DL_TEXT_BG    = 'Text.jpg';
const DL_USER_URL   = 'profile.php?user_id=';
const DL_GROUP_URL  = 'group.php?id=';
const DL_PER_PAGE   = 12;
/* categories made for moments that disappear: [name, icon] */
$DL_CATS = [
  ['Right Now','ri-flashlight-fill'],['Day in My Life','ri-sun-line'],['Outfit Check','ri-t-shirt-line'],['Eats','ri-restaurant-line'],
  ['On the Go','ri-map-pin-line'],['Hangouts','ri-group-line'],['Party & Events','ri-cake-3-line'],['Behind the Scenes','ri-clapperboard-line'],
  ['Work & Study','ri-book-open-line'],['Fitness','ri-run-line'],['Music & Vibes','ri-music-2-line'],['Gaming','ri-gamepad-line'],
  ['Art in Progress','ri-brush-line'],['Pets','ri-bear-smile-line'],['Memes','ri-emotion-laugh-line'],['Hot Takes','ri-fire-line'],
  ['Ask Me','ri-question-answer-line'],['Shoutouts','ri-megaphone-line'],['Throwbacks','ri-history-line'],['Sneak Peek','ri-eye-line']
];
$DL_CAT_NAMES = array_map(function($c){ return $c[0]; }, $DL_CATS);
$DL_CAT_ICON  = array_column($DL_CATS,1,0);

/* ═══════════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════════ */
function dl_json($a){ if(isset($a['success']) && !isset($a['ok'])) $a['ok'] = $a['success']; header('Content-Type: application/json'); echo json_encode($a); exit; }
function dl_fail($m){ dl_json(['success'=>false,'msg'=>$m]); }
function dl_text($s,$max){ $s=str_replace(["\r\n","\r"],"\n",(string)$s); $s=trim(strip_tags($s)); return function_exists('mb_substr')?mb_substr($s,0,$max):substr($s,0,$max); }
function dl_unlink($p){ if($p && strpos($p,DL_DIR)===0 && strpos($p,'..')===false) @unlink(__DIR__.'/'.$p); }
function dl_pic($p){ return !empty($p) ? $p : 'uploads/me.png'; }
function dl_count($conn,$sql){ try{ $r=$conn->query($sql); if($r instanceof mysqli_result){ $row=$r->fetch_row(); return (int)($row[0]??0); } }catch(Throwable $e){} return 0; }
function dl_types($kind){
    $img=['image/jpeg'=>['jpg','image'],'image/png'=>['png','image'],'image/gif'=>['gif','image'],'image/webp'=>['webp','image']];
    if($kind==='image') return $img;
    if($kind==='audio'){ $w=['wav','audio']; return ['audio/wav'=>$w,'audio/x-wav'=>$w,'audio/wave'=>$w,'audio/vnd.wave'=>$w]; }
    return $img+['video/mp4'=>['mp4','video'],'video/webm'=>['webm','video'],'video/quicktime'=>['mov','video']];
}
function dl_wav_ok($tmp){
    $h=@file_get_contents($tmp,false,null,0,44);
    if($h===false||strlen($h)<44||substr($h,0,4)!=='RIFF'||substr($h,8,4)!=='WAVE') return false;
    $br=unpack('V',substr($h,28,4))[1]; if($br<=0) return false;
    return ((filesize($tmp)-44)/$br)<=31;
}
function dl_save($f,$allowed,$limit,$prefix,$uid){
    if(!$f||!isset($f['error'])||$f['error']!==UPLOAD_ERR_OK){
        $e=$f['error']??-1;
        return ['ok'=>false,'msg'=>($e===UPLOAD_ERR_INI_SIZE||$e===UPLOAD_ERR_FORM_SIZE)?'File is too large for the server.':'Upload failed.'];
    }
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if(!isset($allowed[$mime])) return ['ok'=>false,'msg'=>'That file type is not allowed.'];
    [$ext,$type]=$allowed[$mime];
    $lim=is_array($limit)?($limit[$type]??0):$limit;
    if($f['size']>$lim) return ['ok'=>false,'msg'=>'File is too large (max '.round($lim/1048576).' MB).'];
    if($type==='audio'&&!dl_wav_ok($f['tmp_name'])) return ['ok'=>false,'msg'=>'Song clips can be 30 seconds at most.'];
    $dir=__DIR__.'/'.DL_DIR; if(!is_dir($dir)) @mkdir($dir,0755,true);
    $name=$prefix.'_'.$uid.'_'.bin2hex(random_bytes(8)).'.'.$ext;
    if(!move_uploaded_file($f['tmp_name'],$dir.$name)) return ['ok'=>false,'msg'=>'Could not save the file.'];
    return ['ok'=>true,'path'=>DL_DIR.$name,'type'=>$type];
}
/* song and cover files are shared by every media of one Dayleez: only delete them when nobody uses them any more */
function dl_unlink_shared($conn,$p){
    if(!$p) return;
    try{
        $st=$conn->prepare("SELECT 1 FROM stories WHERE song_path=? OR cover_path=? LIMIT 1");
        $st->bind_param("ss",$p,$p); $st->execute();
        $used=$st->get_result()->num_rows>0; $st->close();
        if(!$used) dl_unlink($p);
    }catch(Throwable $e){}
}

/* ═══════════════════════════════════════════════════════════
   SCHEMA — builds on the tables we made (stories, story_views,
   story_replies) and adds what multi-media Dayleez needs.
═══════════════════════════════════════════════════════════ */
function dl_schema($conn){
    if(!empty($_SESSION['dl_schema_v4'])) return;
    try{
        $conn->query("CREATE TABLE IF NOT EXISTS stories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
            media_path VARCHAR(255) NOT NULL, media_type ENUM('image','video','text') NOT NULL DEFAULT 'image',
            caption TEXT NULL, category VARCHAR(60) NULL, duration_days TINYINT UNSIGNED NOT NULL DEFAULT 1,
            status ENUM('active','removed') NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL,
            INDEX idx_user_created (user_id, created_at), INDEX idx_expires (expires_at), INDEX idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS story_views (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, story_id INT UNSIGNED NOT NULL, viewer_id INT UNSIGNED NOT NULL,
            viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_story_viewer (story_id, viewer_id), INDEX idx_viewer (viewer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS story_replies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, story_id INT UNSIGNED NOT NULL, sender_id INT UNSIGNED NOT NULL,
            message VARCHAR(500) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_story (story_id, created_at), INDEX idx_sender (sender_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS story_likes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, story_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_like (story_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS story_reply_likes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reply_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            UNIQUE KEY uq_rl (reply_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS story_reply_dislikes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reply_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            UNIQUE KEY uq_rd (reply_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $r=$conn->query("SHOW COLUMNS FROM `story_replies` LIKE 'parent_id'");
        if($r&&$r->num_rows===0) $conn->query("ALTER TABLE `story_replies` ADD COLUMN `parent_id` INT UNSIGNED NOT NULL DEFAULT 0, ADD INDEX idx_parent (parent_id)");
        $need=['batch_id'=>"INT UNSIGNED NOT NULL DEFAULT 0",'audio_path'=>"VARCHAR(255) NULL",'audio_title'=>"VARCHAR(60) NULL",
               'mute_video'=>"TINYINT(1) NOT NULL DEFAULT 0",'song_path'=>"VARCHAR(255) NULL",'song_title'=>"VARCHAR(60) NULL",
               'song_mode'=>"ENUM('whole','each') NOT NULL DEFAULT 'whole'",'cover_path'=>"VARCHAR(255) NULL",'place'=>"VARCHAR(100) NULL",'cap_tf'=>"VARCHAR(40) NULL",'place_tf'=>"VARCHAR(40) NULL"];
        foreach($need as $col=>$def){
            $r=$conn->query("SHOW COLUMNS FROM `stories` LIKE '$col'");
            if($r&&$r->num_rows===0) $conn->query("ALTER TABLE `stories` ADD COLUMN `$col` $def");
        }
        $r=$conn->query("SHOW COLUMNS FROM `stories` LIKE 'place'");
        if($r&&($row=$r->fetch_assoc())&&stripos((string)$row['Type'],'varchar(80)')!==false) $conn->query("ALTER TABLE `stories` MODIFY `place` VARCHAR(100) NULL");
        $r=$conn->query("SHOW COLUMNS FROM `stories` LIKE 'media_type'");
        if($r&&($row=$r->fetch_assoc())&&stripos((string)$row['Type'],"'text'")===false)
            $conn->query("ALTER TABLE `stories` MODIFY `media_type` ENUM('image','video','text') NOT NULL DEFAULT 'image'");
        $r=$conn->query("SHOW COLUMNS FROM `stories` LIKE 'caption'");
        if($r&&($row=$r->fetch_assoc())&&stripos((string)$row['Type'],'varchar')!==false)
            $conn->query("ALTER TABLE `stories` MODIFY `caption` TEXT NULL");
        $r=$conn->query("SHOW COLUMNS FROM `stories` LIKE 'batch_id'");
        if($r&&$r->num_rows) $conn->query("ALTER TABLE `stories` ADD INDEX idx_batch (batch_id)");
        $_SESSION['dl_schema_v4']=1;
    }catch(Throwable $e){}
}
dl_schema($conn);

/* old, long-expired Dayleez are removed now and then (7 days after they expire) */
function dl_cleanup($conn){
    if(mt_rand(1,40)!==1) return;
    try{
        $rs=$conn->query("SELECT id, media_path, audio_path, song_path, cover_path FROM stories WHERE expires_at < (NOW() - INTERVAL 7 DAY) LIMIT 200");
        $ids=[]; $shared=[];
        if($rs) while($r=$rs->fetch_assoc()){
            $ids[]=(int)$r['id']; dl_unlink($r['media_path']); dl_unlink($r['audio_path']);
            if($r['song_path']) $shared[$r['song_path']]=1; if($r['cover_path']) $shared[$r['cover_path']]=1;
        }
        if($ids){
            $in=implode(',',$ids);
            foreach(['story_reply_likes','story_reply_dislikes'] as $t) $conn->query("DELETE FROM $t WHERE reply_id IN (SELECT id FROM story_replies WHERE story_id IN ($in))");
            foreach(['story_views','story_likes','story_replies'] as $t) $conn->query("DELETE FROM $t WHERE story_id IN ($in)");
            $conn->query("DELETE FROM stories WHERE id IN ($in)");
            foreach(array_keys($shared) as $p) dl_unlink_shared($conn,$p);
        }
    }catch(Throwable $e){}
}
dl_cleanup($conn);

/* ═══════════════════════════════════════════════════════════
   WHO CAN SEE WHAT — blocks (both ways) + private accounts (followers only)
═══════════════════════════════════════════════════════════ */
function dl_has_priv($conn){
    static $h=null;
    if($h===null){ try{ $r=$conn->query("SHOW TABLES LIKE 'user_privacy'"); $h=($r&&$r->num_rows>0); }catch(Throwable $e){ $h=false; } }
    return $h;
}
function dl_vis($conn,$me,$a='s'){
    $me=(int)$me;
    $sql=" AND $a.status='active' AND $a.expires_at>NOW()";
    $sql.=" AND NOT EXISTS (SELECT 1 FROM blocks bk WHERE (bk.blocker_id=$a.user_id AND bk.blocked_id=$me) OR (bk.blocker_id=$me AND bk.blocked_id=$a.user_id))";
    if(dl_has_priv($conn)) $sql.=" AND ($a.user_id=$me OR NOT EXISTS (SELECT 1 FROM user_privacy up WHERE up.user_id=$a.user_id AND up.privacy_level IN ('private','full')) OR EXISTS (SELECT 1 FROM followers fv WHERE fv.follower_id=$me AND fv.following_id=$a.user_id))";
    return $sql;
}
function dl_place($s){
    $s=trim(preg_replace('/\s+/',' ',strip_tags((string)$s)));
    return function_exists('mb_substr')?mb_substr($s,0,100):substr($s,0,100);
}
/* position, size and angle of the words / location on a media: "x%,y%,size,angle" */
function dl_tf($raw){
    $p=explode(',',(string)$raw); if(count($p)!==4) return null;
    foreach($p as $v) if(!is_numeric($v)) return null;
    $x=max(0,min(100,(float)$p[0])); $y=max(0,min(100,(float)$p[1])); $sc=max(.3,min(5,(float)$p[2])); $r=max(-360,min(360,(float)$p[3]));
    return round($x,2).','.round($y,2).','.round($sc,3).','.round($r,1);
}
function dl_own($conn,$me,$sid){
    $sid=(int)$sid; if($sid<=0) return null;
    try{ $r=$conn->query("SELECT * FROM stories WHERE id=$sid AND user_id=".(int)$me." AND expires_at>NOW() LIMIT 1"); return $r?($r->fetch_assoc()?:null):null; }catch(Throwable $e){ return null; }
}
function dl_delete($conn,$me,$sid){
    $sid=(int)$sid; $me=(int)$me;
    $st=$conn->prepare("SELECT * FROM stories WHERE id=? AND user_id=? LIMIT 1"); $st->bind_param("ii",$sid,$me); $st->execute();
    $s=$st->get_result()->fetch_assoc(); $st->close();
    if(!$s) return false;
    dl_unlink($s['media_path']); dl_unlink($s['audio_path']);
    foreach(['story_reply_likes','story_reply_dislikes'] as $t) $conn->query("DELETE FROM $t WHERE reply_id IN (SELECT id FROM story_replies WHERE story_id=$sid)");
    foreach(['story_views','story_likes','story_replies'] as $t) $conn->query("DELETE FROM $t WHERE story_id=$sid");
    $conn->query("DELETE FROM stories WHERE id=$sid AND user_id=$me");
    dl_unlink_shared($conn,$s['song_path']); dl_unlink_shared($conn,$s['cover_path']);
    return true;
}
function dl_reply_sql($me,$owner){
    $me=(int)$me; $owner=(int)$owner;
    return "SELECT r.id, r.sender_id, r.message, r.parent_id, u.username, u.profile_pic, TIMESTAMPDIFF(SECOND,r.created_at,NOW()) age_s,
        (SELECT COUNT(*) FROM story_reply_likes l WHERE l.reply_id=r.id) lc,
        EXISTS(SELECT 1 FROM story_reply_likes l2 WHERE l2.reply_id=r.id AND l2.user_id=$me) lk,
        EXISTS(SELECT 1 FROM story_reply_dislikes d WHERE d.reply_id=r.id AND d.user_id=$me) dk,
        EXISTS(SELECT 1 FROM story_reply_likes l3 WHERE l3.reply_id=r.id AND l3.user_id=$owner) ol,
        (SELECT COUNT(*) FROM story_replies c WHERE c.parent_id=r.id) rc,
        (SELECT u3.profile_pic FROM story_replies c2 JOIN users u3 ON u3.id=c2.sender_id WHERE c2.parent_id=r.id ORDER BY c2.id ASC LIMIT 1) rpic
        FROM story_replies r JOIN users u ON u.id=r.sender_id";
}
function dl_reply_fmt($r,$me,$owner){
    $sid=(int)$r['sender_id'];
    return ['id'=>(int)$r['id'],'uid'=>$sid,'name'=>$r['username'],'pic'=>dl_pic($r['profile_pic']),'role'=>($sid===(int)$owner)?'creator':'',
        'text'=>$r['message'],'age'=>(int)$r['age_s'],'likes'=>(int)$r['lc'],'liked'=>(bool)$r['lk'],'disliked'=>(bool)$r['dk'],'ol'=>(bool)$r['ol'],
        'replies'=>(int)$r['rc'],'rpic'=>$r['rpic']?dl_pic($r['rpic']):'','mine'=>($sid===(int)$me),'canDel'=>($sid===(int)$me||(int)$owner===(int)$me),'parent'=>(int)$r['parent_id']];
}
function dl_story($conn,$me,$sid){
    $sid=(int)$sid; if($sid<=0) return null;
    try{
        $r=$conn->query("SELECT s.* FROM stories s WHERE s.id=$sid".dl_vis($conn,$me)." LIMIT 1");
        return $r?($r->fetch_assoc()?:null):null;
    }catch(Throwable $e){ return null; }
}

/* ═══════════════════════════════════════════════════════════
   TAGS & @MENTIONS (same rules as the upload page: a person's
   "Tags and mentions" setting decides who may @mention them)
═══════════════════════════════════════════════════════════ */
function pv_val($conn,$uid,$field){
    static $cache=[]; $uid=(int)$uid;
    if(!isset($cache[$uid])){
        $cache[$uid]=['mention_who'=>'everyone'];
        try{
            $st=$conn->prepare("SELECT mention_who FROM user_privacy WHERE user_id=? LIMIT 1");
            $st->bind_param("i",$uid); $st->execute();
            $r=$st->get_result()->fetch_assoc(); $st->close();
            if($r) foreach($r as $k=>$v) if($v!==null) $cache[$uid][$k]=$v;
        }catch(Throwable $e){}
    }
    return $cache[$uid][$field]??null;
}
function pv_tracks($conn,$a,$b){
    try{ $st=$conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=? LIMIT 1"); $st->bind_param("ii",$a,$b); $st->execute(); $ok=$st->get_result()->num_rows>0; $st->close(); return $ok; }catch(Throwable $e){ return false; }
}
function pv_isblocked($conn,$target,$actor){
    try{ $st=$conn->prepare("SELECT 1 FROM blocks WHERE blocker_id=? AND blocked_id=? LIMIT 1"); $st->bind_param("ii",$target,$actor); $st->execute(); $ok=$st->get_result()->num_rows>0; $st->close(); return $ok; }catch(Throwable $e){ return false; }
}
function pv_reason($conn,$target,$actor){
    $target=(int)$target; $actor=(int)$actor;
    if($target===$actor) return '';
    if(pv_isblocked($conn,$target,$actor)) return 'none';
    $v=pv_val($conn,$target,'mention_who');
    if($v==='none') return 'none';
    if($v==='tracked' && !pv_tracks($conn,$target,$actor)) return 'tracked';
    return '';
}
function pv_names($text){
    $out=[];
    if(preg_match_all('/@([A-Za-z0-9_.]{2,40})/u',(string)$text,$mm)){
        foreach($mm[1] as $n){ $n=rtrim($n,'.'); if(strlen($n)>=2) $out[strtolower($n)]=$n; }
    }
    return array_slice($out,0,30,true);
}
function pv_text_issues($conn,$text,$actor){
    $issues=[];
    foreach(pv_names($text) as $low=>$n){
        try{ $st=$conn->prepare("SELECT id FROM users WHERE username=? OR nickname=? LIMIT 1"); $st->bind_param("ss",$n,$n); $st->execute(); $u=$st->get_result()->fetch_assoc(); $st->close(); }catch(Throwable $e){ $u=null; }
        if(!$u) continue;
        $r=pv_reason($conn,(int)$u['id'],$actor);
        if($r!=='') $issues[]=['name'=>$n,'msg'=>'@'.$n.($r==='tracked'?" doesn't allow @mentions from everyone.":" doesn't allow @mentions.")];
    }
    return $issues;
}
function pv_text_guard($conn,$text,$actor){
    $m=array_map(function($i){ return $i['msg']; },pv_text_issues($conn,$text,$actor));
    return implode(' ',array_slice($m,0,2));
}
/* turns @names and group names inside a text into ids so the viewer can colour + link them */
function dl_resolve($conn,$text){
    $users=[]; $groups=[]; $text=(string)$text;
    foreach(pv_names($text) as $low=>$n){
        try{ $st=$conn->prepare("SELECT id FROM users WHERE username=? OR nickname=? LIMIT 1"); $st->bind_param("ss",$n,$n); $st->execute(); $u=$st->get_result()->fetch_assoc(); $st->close(); if($u) $users[$low]=(int)$u['id']; }catch(Throwable $e){}
    }
    if($text!==''){
        try{
            $st=$conn->prepare("SELECT id, title FROM `groups` WHERE CHAR_LENGTH(title) >= 2 AND LOCATE(LOWER(title), LOWER(?)) > 0 ORDER BY CHAR_LENGTH(title) DESC LIMIT 20");
            $st->bind_param("s",$text); $st->execute(); $res=$st->get_result();
            while($g=$res->fetch_assoc()) $groups[]=['id'=>(int)$g['id'],'name'=>$g['title']];
            $st->close();
        }catch(Throwable $e){ $groups=[]; }
    }
    return ['users'=>(object)$users,'groups'=>$groups];
}

/* ═══════════════════════════════════════════════════════════
   FEED / TRAY / VIEWER DATA
═══════════════════════════════════════════════════════════ */
/* what a person is into: categories of Dayleez they watched, liked or posted */
function dl_interest($conn,$me){
    $me=(int)$me; $cats=[];
    $qs=["SELECT s.category c, COUNT(*) n FROM story_views v JOIN stories s ON s.id=v.story_id WHERE v.viewer_id=$me AND s.category IS NOT NULL AND s.category<>'' GROUP BY s.category",
         "SELECT s.category c, COUNT(*)*3 n FROM story_likes l JOIN stories s ON s.id=l.story_id WHERE l.user_id=$me AND s.category IS NOT NULL AND s.category<>'' GROUP BY s.category",
         "SELECT category c, COUNT(*)*2 n FROM stories WHERE user_id=$me AND category IS NOT NULL AND category<>'' GROUP BY category"];
    foreach($qs as $q){
        try{ $rs=$conn->query($q); if($rs) while($r=$rs->fetch_assoc()) $cats[$r['c']]=($cats[$r['c']]??0)+(int)$r['n']; }catch(Throwable $e){}
    }
    arsort($cats);
    return array_slice(array_keys($cats),0,5);
}
function dl_feed($conn,$me,$tab,$cat,$q,$off,$lim,$seed=1){
    $w="1=1".dl_vis($conn,$me,'s');
    if($tab==='mine') $w.=" AND s.user_id=$me";
    elseif($tab==='discover') $w.=" AND s.user_id<>$me AND s.user_id NOT IN (SELECT following_id FROM followers WHERE follower_id=$me)";
    else $w.=" AND (s.user_id=$me OR s.user_id IN (SELECT following_id FROM followers WHERE follower_id=$me))";
    if($cat!==''&&$cat!=='All') $w.=" AND s.category='".$conn->real_escape_string($cat)."'";
    if($q!=='') $w.=" AND u.username LIKE '%".$conn->real_escape_string(addcslashes($q,'%_'))."%'";
    $isel="0";
    if($tab==='discover'){
        $interest=dl_interest($conn,$me);
        if($interest){ $in=implode(',',array_map(function($c) use($conn){ return "'".$conn->real_escape_string($c)."'"; },$interest)); $isel="MAX(s.category IN ($in))"; }
        /* discover: things in categories you are into first, unseen before seen, then a fresh shuffle on every page load */
        $order="$isel DESC, (SUM(sv.viewer_id IS NULL)>0) DESC, RAND(".(int)$seed.")";
    }else{
        /* following / mine: the ones about to expire come first, the ones with days left come last */
        $order="MIN(s.expires_at) ASC, last_at DESC";
    }
    $sql="SELECT s.user_id, u.username, u.profile_pic, MAX(s.created_at) last_at, MIN(s.expires_at) soon, SUM(sv.viewer_id IS NULL) unseen, $isel interest
          FROM stories s JOIN users u ON u.id=s.user_id
          LEFT JOIN story_views sv ON sv.story_id=s.id AND sv.viewer_id=$me
          WHERE $w GROUP BY s.user_id, u.username, u.profile_pic
          ORDER BY $order
          LIMIT ".((int)$lim+1)." OFFSET ".(int)$off;
    $users=[]; $more=false;
    $rs=$conn->query($sql);
    if($rs) while($r=$rs->fetch_assoc()){ if(count($users)>=$lim){ $more=true; break; } $users[(int)$r['user_id']]=['uid'=>(int)$r['user_id'],'name'=>$r['username'],'pic'=>dl_pic($r['profile_pic']),'flags'=>[],'thumb'=>null,'left'=>null,'cat'=>'','isYou'=>((int)$r['user_id']===$me)]; }
    if($users){
        $in=implode(',',array_keys($users));
        $rs=$conn->query("SELECT s.id, s.user_id, s.media_path, s.media_type, s.caption, s.cover_path, s.category, (sv.viewer_id IS NOT NULL) seen, TIMESTAMPDIFF(SECOND,NOW(),s.expires_at) left_s
            FROM stories s LEFT JOIN story_views sv ON sv.story_id=s.id AND sv.viewer_id=$me
            WHERE s.user_id IN ($in)".dl_vis($conn,$me,'s')." ORDER BY s.id ASC");
        if($rs) while($r=$rs->fetch_assoc()){
            $u=(int)$r['user_id'];
            $users[$u]['flags'][]=(int)$r['seen'];
            $users[$u]['thumb']=['type'=>$r['media_type'],'path'=>($r['cover_path']?:$r['media_path']),'cover'=>!empty($r['cover_path']),'text'=>mb_substr((string)$r['caption'],0,90)];
            $l=(int)$r['left_s']; $users[$u]['left']=($users[$u]['left']===null)?$l:min($users[$u]['left'],$l);
            if(!empty($r['category'])) $users[$u]['cat']=$r['category'];
        }
    }
    foreach($users as &$uu){ if($uu['left']===null) $uu['left']=0; } unset($uu);
    return ['users'=>array_values($users),'more'=>$more];
}
function dl_viewer($conn,$me,$uids){
    $out=[];
    foreach($uids as $uid){
        $uid=(int)$uid; if($uid<=0) continue;
        $st=$conn->prepare("SELECT s.id, s.media_path, s.media_type, s.caption, s.audio_path, s.audio_title, s.mute_video, s.place, s.cap_tf, s.place_tf, s.song_path, s.song_title, s.song_mode,
              TIMESTAMPDIFF(SECOND,s.created_at,NOW()) age_s, TIMESTAMPDIFF(SECOND,NOW(),s.expires_at) left_s,
              (SELECT COUNT(*) FROM story_likes l WHERE l.story_id=s.id) lc, (SELECT COUNT(*) FROM story_replies c WHERE c.story_id=s.id) cc,
              EXISTS(SELECT 1 FROM story_likes l2 WHERE l2.story_id=s.id AND l2.user_id=$me) lk,
              (SELECT COUNT(*) FROM story_views v WHERE v.story_id=s.id) vc,
              EXISTS(SELECT 1 FROM story_views v2 WHERE v2.story_id=s.id AND v2.viewer_id=$me) seen
            FROM stories s WHERE s.user_id=?".dl_vis($conn,$me,'s')." ORDER BY s.id ASC LIMIT 60");
        $st->bind_param("i",$uid); $st->execute(); $res=$st->get_result();
        $media=[];
        while($m=$res->fetch_assoc()){
            $cap=(string)($m['caption']??'');
            $media[]=['id'=>(int)$m['id'],'path'=>$m['media_path'],'type'=>$m['media_type'],'caption'=>$cap,'men'=>$cap!==''?dl_resolve($conn,$cap):null,
                'audio'=>$m['audio_path'],'audioTitle'=>(string)$m['audio_title'],'mute'=>(int)$m['mute_video'],'place'=>(string)($m['place']??''),'ctf'=>(string)($m['cap_tf']??''),'ptf'=>(string)($m['place_tf']??''),
                'song'=>$m['song_path'],'songTitle'=>(string)$m['song_title'],'mode'=>$m['song_mode'],
                'age'=>(int)$m['age_s'],'left'=>(int)$m['left_s'],'likeCount'=>(int)$m['lc'],'comments'=>(int)$m['cc'],'liked'=>(bool)$m['lk'],'views'=>(int)$m['vc'],'seen'=>(bool)$m['seen']];
        }
        $st->close();
        if(!$media) continue;
        $u=$conn->prepare("SELECT username, profile_pic FROM users WHERE id=? LIMIT 1"); $u->bind_param("i",$uid); $u->execute();
        $ur=$u->get_result()->fetch_assoc(); $u->close(); if(!$ur) continue;
        $fol=($uid!==$me)?pv_tracks($conn,$me,$uid):false;
        $out[]=['id'=>$uid,'name'=>$ur['username'],'pic'=>dl_pic($ur['profile_pic']),'isYou'=>($uid===$me),'following'=>$fol,'media'=>$media];
    }
    return $out;
}

/* ═══════════════════════════════════════════════════════════
   CREATE: checks shared by every media of one Dayleez
═══════════════════════════════════════════════════════════ */
function dl_ctx($conn,$me,$cats){
    $bid=(int)($_POST['batch_id']??0);
    $mode=(($_POST['mode']??'')==='each')?'each':'whole';
    if(dl_count($conn,"SELECT COUNT(*) FROM stories WHERE user_id=$me AND status='active' AND expires_at>NOW()")>=DL_MAX_ACTIVE)
        dl_fail('You can have up to '.DL_MAX_ACTIVE.' media live at once. Wait for some to expire or delete one.');
    if($bid>0){
        $st=$conn->prepare("SELECT id, expires_at, duration_days, category, song_mode FROM stories WHERE id=? AND user_id=? AND batch_id=id LIMIT 1");
        $st->bind_param("ii",$bid,$me); $st->execute(); $b=$st->get_result()->fetch_assoc(); $st->close();
        if(!$b) dl_fail('That Dayleez was not found.');
        if(dl_count($conn,"SELECT COUNT(*) FROM stories WHERE batch_id=$bid AND user_id=$me")>=DL_MAX_MEDIA) dl_fail('A Dayleez can hold up to '.DL_MAX_MEDIA.' media.');
        return ['bid'=>$bid,'exp'=>$b['expires_at'],'days'=>(int)$b['duration_days'],'cat'=>$b['category'],'mode'=>$b['song_mode']];
    }
    $days=(int)($_POST['days']??1); if($days<1||$days>3) $days=1;           // 1, 2 or 3 days is the most a Dayleez can last
    $cat=trim((string)($_POST['category']??'')); $cat=in_array($cat,$cats,true)?$cat:null;
    $r=$conn->query("SELECT DATE_ADD(NOW(), INTERVAL $days DAY) e"); $exp=$r->fetch_assoc()['e'];
    return ['bid'=>0,'exp'=>$exp,'days'=>$days,'cat'=>$cat,'mode'=>$mode];
}
function dl_insert($conn,$me,$c,$path,$type,$cap,$mute,$place='',$ctf=null,$ptf=null){
    $st=$conn->prepare("INSERT INTO stories (user_id, media_path, media_type, caption, category, place, cap_tf, place_tf, duration_days, status, expires_at, batch_id, song_mode, mute_video) VALUES (?,?,?,?,?,?,?,?,?,'active',?,?,?,?)");
    $bid=(int)$c['bid'];
    $plv=($place!=='')?$place:null;
    $st->bind_param("isssssssisisi",$me,$path,$type,$cap,$c['cat'],$plv,$ctf,$ptf,$c['days'],$c['exp'],$bid,$c['mode'],$mute);
    $ok=$st->execute(); $nid=$conn->insert_id; $st->close();
    if(!$ok) return null;
    if($bid===0){ $bid=$nid; $conn->query("UPDATE stories SET batch_id=$nid WHERE id=$nid"); }
    return ['id'=>$nid,'bid'=>$bid];
}

/* ═══════════════════════════════════════════════════════════
   AJAX
═══════════════════════════════════════════════════════════ */
if(isset($_POST['dl_action'])){
    try{
        $act=(string)$_POST['dl_action']; $MB=1048576;

        if($act==='feed'){
            $tab=in_array($_POST['tab']??'',['following','discover','mine'],true)?$_POST['tab']:'following';
            $d=dl_feed($conn,$me,$tab,trim((string)($_POST['category']??'')),trim((string)($_POST['search']??'')),max(0,(int)($_POST['offset']??0)),DL_PER_PAGE,(int)($_POST['seed']??1));
            dl_json(['success'=>true,'users'=>$d['users'],'has_more'=>$d['more']]);
        }
        if($act==='tray'){
            $d=dl_feed($conn,$me,'following','','',0,24);
            dl_json(['success'=>true,'users'=>$d['users']]);
        }
        if($act==='viewer'){
            $ids=array_slice(array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_POST['uids']??'')))))),0,30);
            dl_json(['success'=>true,'users'=>dl_viewer($conn,$me,$ids)]);
        }
        if($act==='resolve'){
            $text=dl_text($_POST['text']??'',DL_MAX_TEXT);
            $r=dl_resolve($conn,$text);
            dl_json(['success'=>true,'users'=>$r['users'],'groups'=>$r['groups'],'blocked'=>pv_text_issues($conn,$text,$me)]);
        }

        /* ── create ── */
        if($act==='add_media'){
            $c=dl_ctx($conn,$me,$DL_CAT_NAMES);
            $cap=dl_text($_POST['caption']??'',DL_MAX_TEXT);
            $mg=pv_text_guard($conn,$cap,$me); if($mg!=='') dl_fail($mg);
            $r=dl_save($_FILES['media']??null,dl_types('media'),['image'=>10*$MB,'video'=>50*$MB],'dl',$me);
            if(!$r['ok']) dl_fail($r['msg']);
            $mute=(!empty($_POST['mute'])&&$r['type']==='video')?1:0;
            $n=dl_insert($conn,$me,$c,$r['path'],$r['type'],$cap,$mute,dl_place($_POST['place']??''),dl_tf($_POST['cap_tf']??''),dl_tf($_POST['place_tf']??''));
            if(!$n){ dl_unlink($r['path']); dl_fail('Could not save the file.'); }
            dl_json(['success'=>true,'id'=>$n['id'],'batch_id'=>$n['bid']]);
        }
        if($act==='add_text'){
            $c=dl_ctx($conn,$me,$DL_CAT_NAMES);
            $txt=dl_text($_POST['text']??'',DL_MAX_TEXT);
            if($txt==='') dl_fail('Write some text first.');
            $mg=pv_text_guard($conn,$txt,$me); if($mg!=='') dl_fail($mg);
            $n=dl_insert($conn,$me,$c,'','text',$txt,0,dl_place($_POST['place']??''),dl_tf($_POST['cap_tf']??''),dl_tf($_POST['place_tf']??''));
            if(!$n) dl_fail('Could not save the text.');
            dl_json(['success'=>true,'id'=>$n['id'],'batch_id'=>$n['bid']]);
        }
        if($act==='set_song'||$act==='set_cover'){
            $bid=(int)($_POST['batch_id']??0);
            $st=$conn->prepare("SELECT song_path, cover_path FROM stories WHERE batch_id=? AND user_id=? LIMIT 1");
            $st->bind_param("ii",$bid,$me); $st->execute(); $old=$st->get_result()->fetch_assoc(); $st->close();
            if(!$old) dl_fail('That Dayleez was not found.');
            if($act==='set_song'){
                $r=dl_save($_FILES['song']??null,dl_types('audio'),7*$MB,'song',$me);
                if(!$r['ok']) dl_fail($r['msg']);
                $t=dl_text($_POST['title']??'',60);
                $st=$conn->prepare("UPDATE stories SET song_path=?, song_title=? WHERE batch_id=? AND user_id=?");
                $st->bind_param("ssii",$r['path'],$t,$bid,$me); $st->execute(); $st->close();
                dl_unlink_shared($conn,$old['song_path']);
            }else{
                $r=dl_save($_FILES['cover']??null,dl_types('image'),10*$MB,'cover',$me);
                if(!$r['ok']) dl_fail($r['msg']);
                $st=$conn->prepare("UPDATE stories SET cover_path=? WHERE batch_id=? AND user_id=?");
                $st->bind_param("sii",$r['path'],$bid,$me); $st->execute(); $st->close();
                dl_unlink_shared($conn,$old['cover_path']);
            }
            dl_json(['success'=>true]);
        }
        if($act==='media_song'){
            $sid=(int)($_POST['story_id']??0);
            $st=$conn->prepare("SELECT audio_path FROM stories WHERE id=? AND user_id=? LIMIT 1");
            $st->bind_param("ii",$sid,$me); $st->execute(); $old=$st->get_result()->fetch_assoc(); $st->close();
            if(!$old) dl_fail('Media not found.');
            $r=dl_save($_FILES['song']??null,dl_types('audio'),7*$MB,'msong',$me);
            if(!$r['ok']) dl_fail($r['msg']);
            dl_unlink($old['audio_path']); $t=dl_text($_POST['title']??'',60);
            $st=$conn->prepare("UPDATE stories SET audio_path=?, audio_title=? WHERE id=?"); $st->bind_param("ssi",$r['path'],$t,$sid); $st->execute(); $st->close();
            dl_json(['success'=>true]);
        }

        /* ── watching ── */
        if($act==='view'){
            $s=dl_story($conn,$me,$_POST['story_id']??0);
            if($s && (int)$s['user_id']!==$me){ $st=$conn->prepare("INSERT IGNORE INTO story_views (story_id, viewer_id) VALUES (?,?)"); $sid=(int)$s['id']; $st->bind_param("ii",$sid,$me); $st->execute(); $st->close(); }
            dl_json(['success'=>true]);
        }
        if($act==='like'){
            $s=dl_story($conn,$me,$_POST['story_id']??0); if(!$s) dl_fail('Not available.');
            $sid=(int)$s['id'];
            $st=$conn->prepare("SELECT id FROM story_likes WHERE story_id=? AND user_id=? LIMIT 1"); $st->bind_param("ii",$sid,$me); $st->execute();
            $has=$st->get_result()->num_rows>0; $st->close();
            if($has){ $st=$conn->prepare("DELETE FROM story_likes WHERE story_id=? AND user_id=?"); }
            else    { $st=$conn->prepare("INSERT IGNORE INTO story_likes (story_id, user_id) VALUES (?,?)"); }
            $st->bind_param("ii",$sid,$me); $st->execute(); $st->close();
            dl_json(['success'=>true,'liked'=>!$has,'count'=>dl_count($conn,"SELECT COUNT(*) FROM story_likes WHERE story_id=$sid")]);
        }
        if($act==='comments'){
            $s=dl_story($conn,$me,$_POST['story_id']??0); if(!$s) dl_fail('Not available.');
            $sid=(int)$s['id']; $own=(int)$s['user_id']; $pid=(int)($_POST['parent_id']??0); $off=max(0,(int)($_POST['offset']??0));
            $sort=in_array($_POST['sort']??'',['top','new','old'],true)?$_POST['sort']:'top';
            $lim=$pid>0?10:15;
            if($pid>0||$sort==='old') $order="r.id ASC"; elseif($sort==='new') $order="r.id DESC"; else $order="lc DESC, r.id DESC";
            $rs=$conn->query(dl_reply_sql($me,$own)." WHERE r.story_id=$sid AND r.parent_id=$pid ORDER BY $order LIMIT ".($lim+1)." OFFSET $off");
            $items=[]; $more=false;
            while($rs&&($r=$rs->fetch_assoc())){ if(count($items)>=$lim){ $more=true; break; } $items[]=dl_reply_fmt($r,$me,$own); }
            dl_json(['success'=>true,'items'=>$items,'more'=>$more,'total'=>dl_count($conn,"SELECT COUNT(*) FROM story_replies WHERE story_id=$sid")]);
        }
        if($act==='comment_add'){
            $s=dl_story($conn,$me,$_POST['story_id']??0); if(!$s) dl_fail('Not available.');
            $sid=(int)$s['id']; $own=(int)$s['user_id'];
            $msg=dl_text($_POST['text']??'',500); if($msg==='') dl_fail('Write a comment first.');
            $pid=(int)($_POST['parent_id']??0);
            if($pid>0&&dl_count($conn,"SELECT COUNT(*) FROM story_replies WHERE id=$pid AND story_id=$sid")===0) $pid=0;
            $d=dl_count($conn,"SELECT COALESCE(TIMESTAMPDIFF(SECOND,MAX(created_at),NOW()),99) FROM story_replies WHERE sender_id=$me");
            if($d<2) dl_fail('Slow down a bit.');
            $st=$conn->prepare("INSERT INTO story_replies (story_id, sender_id, message, parent_id) VALUES (?,?,?,?)"); $st->bind_param("iisi",$sid,$me,$msg,$pid);
            $ok=$st->execute(); $nid=$conn->insert_id; $st->close();
            if(!$ok) dl_fail('Could not post.');
            $rs=$conn->query(dl_reply_sql($me,$own)." WHERE r.id=$nid LIMIT 1"); $row=$rs?$rs->fetch_assoc():null;
            dl_json(['success'=>true,'item'=>$row?dl_reply_fmt($row,$me,$own):null,'total'=>dl_count($conn,"SELECT COUNT(*) FROM story_replies WHERE story_id=$sid")]);
        }
        if($act==='comment_like'||$act==='comment_dislike'){
            $rid=(int)($_POST['comment_id']??0);
            $r=$conn->query("SELECT story_id FROM story_replies WHERE id=$rid LIMIT 1"); $row=$r?$r->fetch_assoc():null; if(!$row) dl_fail('Not found.');
            $s=dl_story($conn,$me,(int)$row['story_id']); if(!$s) dl_fail('Not available.'); $own=(int)$s['user_id'];
            $lk=dl_count($conn,"SELECT COUNT(*) FROM story_reply_likes WHERE reply_id=$rid AND user_id=$me")>0;
            $dk=dl_count($conn,"SELECT COUNT(*) FROM story_reply_dislikes WHERE reply_id=$rid AND user_id=$me")>0;
            if($act==='comment_like'){
                if($lk) $conn->query("DELETE FROM story_reply_likes WHERE reply_id=$rid AND user_id=$me");
                else { $conn->query("INSERT IGNORE INTO story_reply_likes (reply_id, user_id) VALUES ($rid,$me)"); $conn->query("DELETE FROM story_reply_dislikes WHERE reply_id=$rid AND user_id=$me"); }
            }else{
                if($dk) $conn->query("DELETE FROM story_reply_dislikes WHERE reply_id=$rid AND user_id=$me");
                else { $conn->query("INSERT IGNORE INTO story_reply_dislikes (reply_id, user_id) VALUES ($rid,$me)"); $conn->query("DELETE FROM story_reply_likes WHERE reply_id=$rid AND user_id=$me"); }
            }
            dl_json(['success'=>true,
                'liked'=>dl_count($conn,"SELECT COUNT(*) FROM story_reply_likes WHERE reply_id=$rid AND user_id=$me")>0,
                'disliked'=>dl_count($conn,"SELECT COUNT(*) FROM story_reply_dislikes WHERE reply_id=$rid AND user_id=$me")>0,
                'likes'=>dl_count($conn,"SELECT COUNT(*) FROM story_reply_likes WHERE reply_id=$rid"),
                'ol'=>dl_count($conn,"SELECT COUNT(*) FROM story_reply_likes WHERE reply_id=$rid AND user_id=$own")>0]);
        }
        if($act==='comment_del'){
            $rid=(int)($_POST['comment_id']??0);
            $r=$conn->query("SELECT story_id, sender_id FROM story_replies WHERE id=$rid LIMIT 1"); $row=$r?$r->fetch_assoc():null; if(!$row) dl_fail('Not found.');
            $s=dl_story($conn,$me,(int)$row['story_id']); if(!$s) dl_fail('Not available.');
            if((int)$row['sender_id']!==$me&&(int)$s['user_id']!==$me) dl_fail('Not allowed.');
            $ids=[$rid]; $front=[$rid];
            while($front&&count($ids)<500){
                $rs=$conn->query("SELECT id FROM story_replies WHERE parent_id IN (".implode(',',$front).")"); $front=[];
                while($rs&&($x=$rs->fetch_assoc())){ $ids[]=(int)$x['id']; $front[]=(int)$x['id']; }
            }
            $in=implode(',',$ids);
            $conn->query("DELETE FROM story_reply_likes WHERE reply_id IN ($in)"); $conn->query("DELETE FROM story_reply_dislikes WHERE reply_id IN ($in)"); $conn->query("DELETE FROM story_replies WHERE id IN ($in)");
            dl_json(['success'=>true,'total'=>dl_count($conn,"SELECT COUNT(*) FROM story_replies WHERE story_id=".(int)$s['id'])]);
        }
        if($act==='viewers'){
            $sid=(int)($_POST['story_id']??0);
            $st=$conn->prepare("SELECT u.id, u.username, u.profile_pic FROM story_views v JOIN stories s ON s.id=v.story_id JOIN users u ON u.id=v.viewer_id WHERE v.story_id=? AND s.user_id=? ORDER BY v.id DESC LIMIT 100");
            $st->bind_param("ii",$sid,$me); $st->execute(); $res=$st->get_result(); $list=[];
            while($r=$res->fetch_assoc()) $list[]=['id'=>(int)$r['id'],'name'=>$r['username'],'pic'=>dl_pic($r['profile_pic'])];
            $st->close(); dl_json(['success'=>true,'viewers'=>$list]);
        }
        /* ── edit my live Dayleez ── */
        if($act==='mine'){
            $rs=$conn->query("SELECT s.id, s.batch_id, s.media_path, s.media_type, s.caption, s.place, s.cap_tf, s.place_tf, s.status, s.mute_video, s.audio_path, s.audio_title, s.song_path, s.song_title, s.song_mode, s.duration_days,
                TIMESTAMPDIFF(SECOND,s.created_at,NOW()) age_s, TIMESTAMPDIFF(SECOND,NOW(),s.expires_at) left_s,
                (SELECT COUNT(*) FROM story_views v WHERE v.story_id=s.id) vc, (SELECT COUNT(*) FROM story_likes l WHERE l.story_id=s.id) lc
                FROM stories s WHERE s.user_id=$me AND s.expires_at>NOW() ORDER BY s.batch_id DESC, s.id ASC LIMIT 200");
            $list=[];
            while($rs&&($r=$rs->fetch_assoc())) $list[]=['id'=>(int)$r['id'],'batch'=>(int)$r['batch_id'],'path'=>$r['media_path'],'type'=>$r['media_type'],'caption'=>(string)$r['caption'],'place'=>(string)($r['place']??''),'ctf'=>(string)($r['cap_tf']??''),'ptf'=>(string)($r['place_tf']??''),
                'status'=>$r['status'],'mute'=>(int)$r['mute_video'],'audio'=>$r['audio_path'],'audioTitle'=>(string)$r['audio_title'],'song'=>$r['song_path'],'songTitle'=>(string)$r['song_title'],'mode'=>$r['song_mode'],
                'days'=>(int)$r['duration_days'],'age'=>(int)$r['age_s'],'left'=>(int)$r['left_s'],'views'=>(int)$r['vc'],'likes'=>(int)$r['lc']];
            dl_json(['success'=>true,'items'=>$list]);
        }
        if($act==='set_caption'){
            $sid=(int)($_POST['story_id']??0); $s=dl_own($conn,$me,$sid); if(!$s) dl_fail('Not found.');
            $txt=dl_text($_POST['text']??'',DL_MAX_TEXT);
            if($s['media_type']==='text'&&$txt==='') dl_fail('A text Dayleez needs some text.');
            $mg=pv_text_guard($conn,$txt,$me); if($mg!=='') dl_fail($mg);
            $st=$conn->prepare("UPDATE stories SET caption=? WHERE id=? AND user_id=?"); $st->bind_param("sii",$txt,$sid,$me); $st->execute(); $st->close();
            dl_json(['success'=>true]);
        }
        if($act==='set_place'){
            $pl=dl_place($_POST['place']??''); $plv=($pl!=='')?$pl:null; $sid=(int)($_POST['story_id']??0); $bid=(int)($_POST['batch_id']??0);
            if($sid>0) $st=$conn->prepare("UPDATE stories SET place=? WHERE id=? AND user_id=? AND expires_at>NOW()");
            else { $st=$conn->prepare("UPDATE stories SET place=? WHERE batch_id=? AND user_id=? AND expires_at>NOW()"); $sid=$bid; }
            $st->bind_param("sii",$plv,$sid,$me); $st->execute(); $st->close();
            dl_json(['success'=>true]);
        }
        if($act==='set_mute'){
            $sid=(int)($_POST['story_id']??0); $m=!empty($_POST['mute'])?1:0;
            $st=$conn->prepare("UPDATE stories SET mute_video=? WHERE id=? AND user_id=? AND media_type='video'"); $st->bind_param("iii",$m,$sid,$me); $st->execute(); $st->close();
            dl_json(['success'=>true]);
        }
        if($act==='media_song_rm'){
            $sid=(int)($_POST['story_id']??0); $s=dl_own($conn,$me,$sid); if(!$s) dl_fail('Not found.');
            dl_unlink($s['audio_path']);
            $st=$conn->prepare("UPDATE stories SET audio_path=NULL, audio_title=NULL WHERE id=? AND user_id=?"); $st->bind_param("ii",$sid,$me); $st->execute(); $st->close();
            dl_json(['success'=>true]);
        }
        if($act==='rm_song'){
            $bid=(int)($_POST['batch_id']??0);
            $st=$conn->prepare("SELECT song_path FROM stories WHERE batch_id=? AND user_id=? LIMIT 1"); $st->bind_param("ii",$bid,$me); $st->execute();
            $old=$st->get_result()->fetch_assoc(); $st->close(); if(!$old) dl_fail('Not found.');
            $st=$conn->prepare("UPDATE stories SET song_path=NULL, song_title=NULL WHERE batch_id=? AND user_id=?"); $st->bind_param("ii",$bid,$me); $st->execute(); $st->close();
            dl_unlink_shared($conn,$old['song_path']);
            dl_json(['success'=>true]);
        }
        if($act==='set_tf'){
            $sid=(int)($_POST['story_id']??0); $s=dl_own($conn,$me,$sid); if(!$s) dl_fail('Not found.');
            $ct=dl_tf($_POST['cap_tf']??''); $pt=dl_tf($_POST['place_tf']??'');
            $st=$conn->prepare("UPDATE stories SET cap_tf=?, place_tf=? WHERE id=? AND user_id=?"); $st->bind_param("ssii",$ct,$pt,$sid,$me); $st->execute(); $st->close();
            dl_json(['success'=>true]);
        }
        if($act==='set_status'){
            $v=(($_POST['status']??'')==='removed')?'removed':'active'; $sid=(int)($_POST['story_id']??0); $bid=(int)($_POST['batch_id']??0);
            if($sid>0) $st=$conn->prepare("UPDATE stories SET status=? WHERE id=? AND user_id=? AND expires_at>NOW()");
            else { $st=$conn->prepare("UPDATE stories SET status=? WHERE batch_id=? AND user_id=? AND expires_at>NOW()"); $sid=$bid; }
            $st->bind_param("sii",$v,$sid,$me); $st->execute(); $st->close();
            dl_json(['success'=>true]);
        }
        if($act==='set_days'){
            $bid=(int)($_POST['batch_id']??0); $days=(int)($_POST['days']??1); if($days<1||$days>3) $days=1;
            $r=$conn->query("SELECT DATE_ADD(created_at, INTERVAL $days DAY) e, (DATE_ADD(created_at, INTERVAL $days DAY)>NOW()) ok FROM stories WHERE batch_id=$bid AND user_id=$me ORDER BY id ASC LIMIT 1");
            $row=$r?$r->fetch_assoc():null; if(!$row) dl_fail('Not found.');
            if(empty($row['ok'])) dl_fail('That time has already passed. Pick a longer one.');
            $e=$row['e'];
            $st=$conn->prepare("UPDATE stories SET duration_days=?, expires_at=? WHERE batch_id=? AND user_id=?"); $st->bind_param("isii",$days,$e,$bid,$me); $st->execute(); $st->close();
            dl_json(['success'=>true]);
        }
        if($act==='del'){
            dl_delete($conn,$me,(int)($_POST['story_id']??0))?dl_json(['success'=>true]):dl_fail('Not found.');
        }
        if($act==='del_batch'){
            $bid=(int)($_POST['batch_id']??0); $n=0;
            $rs=$conn->query("SELECT id FROM stories WHERE batch_id=$bid AND user_id=$me");
            while($rs&&($r=$rs->fetch_assoc())){ if(dl_delete($conn,$me,(int)$r['id'])) $n++; }
            $n>0?dl_json(['success'=>true]):dl_fail('Not found.');
        }
        if($act==='follow'){
            $uid=(int)($_POST['user_id']??0);
            if($uid<=0||$uid===$me) dl_fail('Not allowed.');
            if(pv_tracks($conn,$me,$uid)){
                $st=$conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?"); $st->bind_param("ii",$me,$uid); $st->execute(); $st->close();
                try{ $st=$conn->prepare("INSERT INTO unfollow_log (unfollowed_by, user_id) VALUES (?,?)"); $st->bind_param("ii",$me,$uid); $st->execute(); $st->close(); }catch(Throwable $e){}
                dl_json(['success'=>true,'following'=>false]);
            }
            $st=$conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?,?)"); $st->bind_param("ii",$me,$uid); $st->execute(); $st->close();
            dl_json(['success'=>true,'following'=>true]);
        }
        dl_fail('Unknown action.');
    }catch(Throwable $e){ dl_fail('Something went wrong.'); }
}

/* ═══════════════════════════════════════════════════════════
   PAGE DATA (right-hand column)
═══════════════════════════════════════════════════════════ */
$act_sql = "s.user_id=$me AND s.status='active' AND s.expires_at>NOW()";
$st_active  = dl_count($conn,"SELECT COUNT(*) FROM stories s WHERE $act_sql");
$st_views   = dl_count($conn,"SELECT COUNT(*) FROM story_views v JOIN stories s ON s.id=v.story_id WHERE $act_sql");
$st_likes   = dl_count($conn,"SELECT COUNT(*) FROM story_likes v JOIN stories s ON s.id=v.story_id WHERE $act_sql");
$st_replies = dl_count($conn,"SELECT COUNT(*) FROM story_replies v JOIN stories s ON s.id=v.story_id WHERE $act_sql");
$creators=[];
try{
    $rs=$conn->query("SELECT s.user_id, u.username, u.profile_pic, COUNT(*) n FROM stories s JOIN users u ON u.id=s.user_id WHERE s.user_id<>$me".dl_vis($conn,$me,'s')." GROUP BY s.user_id, u.username, u.profile_pic ORDER BY n DESC, MAX(s.id) DESC LIMIT 4");
    if($rs) while($r=$rs->fetch_assoc()) $creators[]=$r;
}catch(Throwable $e){}
$interest = dl_interest($conn,$me);
$top_cats = [];
try{
    $rs=$conn->query("SELECT s.category, COUNT(*) n FROM stories s WHERE s.category IS NOT NULL AND s.category<>''".dl_vis($conn,$me,'s')." GROUP BY s.category ORDER BY n DESC LIMIT 6");
    if($rs) while($r=$rs->fetch_assoc()) $top_cats[]=$r;
}catch(Throwable $e){}
$rand_seed = mt_rand(1,1000000);
$my_pic = 'uploads/me.png';
try{ $r=$conn->query("SELECT profile_pic FROM users WHERE id=$me LIMIT 1"); $row=$r?$r->fetch_assoc():null; if(!empty($row['profile_pic'])) $my_pic=$row['profile_pic']; }catch(Throwable $e){}
$nav_pic = $my_pic;
$open_u = (int)($_GET['u'] ?? 0);
$open_d = (int)($_GET['d'] ?? 0);
$search_svg = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle class="s-ring" cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/><path class="s-handle" fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#010218">
<title>Dayleez | Dezzme</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js" defer></script>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a { color:inherit; text-decoration:none; }
img { max-width:100%; display:block; }
[class^="ri-"], [class*=" ri-"], [class^="ri-"]::before, [class*=" ri-"]::before, [class^="ri-"]::after, [class*=" ri-"]::after {
    font-family:'remixicon' !important; font-style:normal; font-weight:normal; line-height:1; speak:none; text-transform:none; -webkit-font-smoothing:antialiased;
}
:root{
    --accent:#FF2D2D; --accent-hover:#e32424; --accent-l:#ff7d7d; --accent-soft:rgba(255,45,45,.16); --accent-border:rgba(255,45,45,.4);
    --accent-focus:rgba(255,45,45,.7); --accent-ring:rgba(255,45,45,.14); --on-accent:#fff;
    --solid:#070924; --surface:rgba(255,255,255,.05); --border:rgba(255,255,255,.14); --line:rgba(255,255,255,.09);
    --text:#fff; --muted:rgba(255,255,255,.45); --muted-2:rgba(255,255,255,.3); --sub:rgba(255,255,255,.62);
    --bg:linear-gradient(-45deg,#010218,#070924,#070924);
    --max-w:580px; --topbar-h:54px; --pad:clamp(9px,3vw,16px); --ctl-h:clamp(30px,8vw,36px); --r:5px;
    --act-h:clamp(25px,7.2vw,30px); --act-r:4px;
    --md-s:1;   /* size multiplier used by the viewer */
    --tb-ink:#fff;   /* colour of the title in the top bar */
}
html{ scroll-behavior:smooth; -webkit-text-size-adjust:100%; }
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; }
}
::selection{ background:rgba(255,45,45,.4); color:#fff; }
body{ background:var(--bg); background-attachment:fixed; color:var(--text); min-height:100vh; overflow-x:hidden; -webkit-font-smoothing:antialiased; font-size:.8rem; }
@media (min-width:769px){ body{ padding-left:100px; } }
@media (max-width:768px){ body{ padding-bottom:72px; } }

/* ══ SHELL: main lane + info column ══ */
.cp-shell{ width:100%; display:flex; align-items:flex-start; justify-content:center; }
.cp-side{ display:none; }
@media (min-width:1100px){
    .cp-shell{ justify-content:flex-start; max-width:1240px; margin:0 auto; padding-left:22px; }
    .cp-side{ display:block; flex:1 1 0; min-width:0; position:sticky; top:0; height:100vh; overflow-y:auto; overflow-x:hidden; padding:0 22px 30px 20px; scrollbar-width:thin; scrollbar-color:rgba(255,45,45,.5) transparent; }
    .cp-side::-webkit-scrollbar{ width:5px; } .cp-side::-webkit-scrollbar-thumb{ background:rgba(255,45,45,.5); border-radius:99px; }
}
.ac-lane{ width:100%; max-width:var(--max-w); min-height:100vh; align-self:stretch; display:flex; flex-direction:column; padding-bottom:48px; }
@media (min-width:801px){ .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .ac-lane{ max-width:100%; } }
@media (min-width:1100px){ .ac-lane{ flex:0 0 var(--max-w); width:var(--max-w); } }

/* ══ TOPBAR: title left, icons right ══ */
.sticky-head{ position:sticky; top:0; z-index:200; background:var(--bg); background-attachment:fixed; }
.cm-topbar{ position:relative; display:flex; align-items:center; gap:12px; padding:14px 16px 10px; }
.cm-topbar::after{ content:''; position:absolute; left:0; right:0; bottom:0; height:1px; background:rgba(255,255,255,.14); pointer-events:none; }
@media (max-width:800px){ .cm-topbar::after{ display:none; } }
.tb-title{ font-size:1.411rem; font-weight:700; color:var(--tb-ink); letter-spacing:3px; white-space:nowrap; margin-right:auto; }
.tb-right{ display:flex; align-items:center; gap:16px; }
.hft-btn, .rp-tb-btn{ -webkit-appearance:none; appearance:none; background:none; border:0; border-radius:0; box-shadow:none; color:#fff; line-height:1; display:flex; align-items:center; justify-content:center; padding:2px; opacity:.9; transition:opacity .2s,color .2s; flex-shrink:0; }
.hft-btn{ font-size:1.5rem; } .rp-tb-btn{ font-size:1.55rem; }
.hft-btn:hover, .rp-tb-btn:hover, .rp-tb-btn.on{ opacity:1; color:var(--accent); }
.rp-tb-btn svg{ width:1.25em; height:1.25em; display:block; } .hft-btn svg{ width:1.3em; height:1.3em; display:block; }
.s-ring{ stroke-width:2.5; transition:stroke-width .15s ease; } .s-handle{ stroke:currentColor; stroke-width:0; stroke-linejoin:round; transition:stroke-width .15s ease; }
.rp-tb-btn.on .s-ring{ stroke-width:3.4; } .rp-tb-btn.on .s-handle{ stroke-width:1.2; }
@media (max-width:790px){ .cm-topbar{ padding:14px 15px 10px; } .tb-title{ font-size:1.31rem; } }
@media (max-width:490px){ .cm-topbar{ padding:12px 12px 8px; } .tb-title{ font-size:1.21rem; letter-spacing:2px; } .hft-btn,.rp-tb-btn{ font-size:1.35rem; } .tb-right{ gap:14px; } }
@media (max-width:390px){ .cm-topbar{ padding-left:10px; padding-right:10px; } .tb-title{ font-size:1.058rem; letter-spacing:1.5px; } .hft-btn,.rp-tb-btn{ font-size:1.25rem; } }
@media (max-width:330px){ .tb-title{ font-size:.958rem; letter-spacing:1px; } .hft-btn,.rp-tb-btn{ font-size:1.15rem; } }
@media (max-width:768px){ @supports (zoom:1){ .cm-topbar{ zoom:1.1; } } }

/* ══ TOOLBAR: tabs + category chips ══ */
.toolbar{ position:relative; margin-top:-4px; padding:10px var(--pad); background:var(--solid); border-bottom:1px solid var(--line); display:flex; flex-direction:column; gap:8px; }
.tab-row{ display:flex; gap:6px; }
.chip{ display:flex; align-items:center; justify-content:center; gap:5px; flex-shrink:0; height:var(--act-h); padding:0 12px; border-radius:var(--act-r); border:none; background:rgba(255,255,255,.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); color:rgba(255,255,255,.75); font-size:.7rem; font-weight:600; transition:background .2s,color .2s,transform .15s; white-space:nowrap; }
.chip:hover{ background:rgba(255,255,255,.2); color:#fff; } .chip:active{ transform:scale(.96); }
.chip.active{ background:var(--accent); color:#fff; font-weight:700; }
.tab-row .chip{ flex:1; }
.cat-bar{ display:flex; align-items:center; gap:6px; }
.cat-arrow{ width:var(--act-h); height:var(--act-h); flex-shrink:0; border:none; border-radius:var(--act-r); background:rgba(255,255,255,.1); color:#fff; font-size:1rem; display:flex; align-items:center; justify-content:center; transition:background .2s; }
.cat-arrow:hover{ background:var(--accent); }
@media (max-width:768px){ .cat-arrow{ display:none !important; } .cat-bar{ margin:0 calc(var(--pad) * -1); } .cat-scrollbar{ padding:0 var(--pad); } }
.cat-scroll-wrap{ flex:1; min-width:0; overflow:hidden; }
.cat-scrollbar{ display:flex; gap:6px; overflow-x:auto; white-space:nowrap; scrollbar-width:none; -ms-overflow-style:none; -webkit-overflow-scrolling:touch; }
.cat-scrollbar::-webkit-scrollbar{ display:none; }

/* search bar (slides open) */
.search-wrap{ display:grid; grid-template-rows:0fr; transition:grid-template-rows .28s ease; }
.search-wrap.open{ grid-template-rows:1fr; }
.search-inner{ min-height:0; overflow:hidden; } .search-wrap:not(.open) .search-inner{ visibility:hidden; }
.search-pad{ padding:12px var(--pad) 4px; }
.search-input-wrap{ display:flex; align-items:center; gap:8px; height:var(--ctl-h); background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:0 12px; transition:border-color .2s,background .2s,box-shadow .2s; }
.search-input-wrap:focus-within{ border-color:var(--accent-focus); background:rgba(255,255,255,.07); box-shadow:0 0 0 3px var(--accent-ring); }
.search-input-wrap > svg{ width:1.05rem; height:1.05rem; color:var(--muted); flex-shrink:0; display:block; }
.search-input-wrap input{ background:transparent; border:none; outline:none; flex:1; min-width:0; color:var(--text); font-size:.76rem; }
.search-input-wrap input::placeholder{ color:var(--muted); }
.search-clear-x{ background:none; border:none; color:var(--muted); font-size:1.05rem; display:none; flex-shrink:0; } .search-clear-x.show{ display:flex; }

/* ══ TRAY (round avatars with a broken ring: one piece per media) ══ */
.tray{ display:flex; gap:12px; overflow-x:auto; padding:14px var(--pad) 12px; border-bottom:1px solid var(--line); scrollbar-width:none; -ms-overflow-style:none; -webkit-overflow-scrolling:touch; min-height:104px; }
.tray::-webkit-scrollbar{ display:none; }
.tr-item{ display:flex; flex-direction:column; align-items:center; gap:6px; width:68px; flex-shrink:0; background:none; border:none; color:#fff; padding:0; }
.tr-item span.nm{ font-size:.66rem; font-weight:500; color:rgba(255,255,255,.82); max-width:68px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tr-item.seen span.nm{ color:rgba(255,255,255,.5); }
.rg{ position:relative; display:block; flex-shrink:0; }
.rg svg{ position:absolute; inset:0; width:100%; height:100%; overflow:visible; }
.rg img{ position:absolute; object-fit:cover; background:#15183a; }
.rg-plus{ position:absolute; right:-4.5px; bottom:-4.5px; width:25.5px; height:25.5px; border-radius:50%; background:var(--accent); border:2.5px solid #070924; color:#000; display:flex; align-items:center; justify-content:center; font-size:19px; }
.rg-dash{ position:absolute; inset:0; border-radius:30%; border:2px dashed rgba(255,255,255,.35); }
.tr-sk{ width:68px; flex-shrink:0; display:flex; flex-direction:column; align-items:center; gap:7px; }
.tr-sk i{ width:60px; height:60px; border-radius:30%; } .tr-sk b{ width:44px; height:8px; border-radius:5px; }

/* ══ CARDS GRID ══ */
.grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px; padding:14px var(--pad); }
.dl-card{ position:relative; width:100%; aspect-ratio:61/100; border-radius:var(--r); overflow:hidden; background:#10122e; border:none; padding:0; text-align:left; color:#fff; opacity:0; animation:rowIn .4s ease forwards; }
@keyframes rowIn{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:none; } }
.dl-card .bg{ position:absolute; inset:0; }
.dl-card .bg img, .dl-card .bg video{ width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; transition:transform .35s ease; }
.dl-card:hover .bg img, .dl-card:hover .bg video{ transform:scale(1.05); }
.dl-card .bg.txt{ background:#0d1030 url('<?php echo DL_TEXT_BG; ?>') center/cover no-repeat; }
.dl-card .bg .tx{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; padding:34px 10px 54px; text-align:center; font-size:.68rem; font-weight:600; line-height:1.45; background:rgba(0,0,0,.35); word-break:break-word; }
.dl-card .bg .tx span{ display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:7; overflow:hidden; }
.dl-card .shade{ position:absolute; inset:0; background:linear-gradient(180deg,rgba(0,0,0,.45),transparent 30%,transparent 52%,rgba(0,0,0,.78)); pointer-events:none; }
.segs{ position:absolute; top:8px; left:8px; right:8px; display:flex; gap:3px; }
.segs i{ flex:1; min-width:3px; height:3px; border-radius:2px; background:var(--accent); } .segs i.s{ background:rgba(255,255,255,.35); }
.cd-user{ position:absolute; top:17px; left:8px; right:8px; display:flex; align-items:center; gap:6px; }
.cd-user b{ font-size:.7rem; font-weight:600; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.7); }
.cd-bot{ position:absolute; left:9px; right:9px; bottom:9px; display:flex; align-items:center; gap:5px; font-size:.62rem; color:rgba(255,255,255,.9); text-shadow:0 1px 4px rgba(0,0,0,.7); }
.cd-bot i{ font-size:.8rem; }
.cd-new{ margin-left:auto; padding:1px 7px; border-radius:4px; background:var(--accent); font-weight:700; font-size:.56rem; }
.sk-shimmer{ background:linear-gradient(100deg,rgba(255,255,255,.05) 30%,rgba(255,255,255,.13) 50%,rgba(255,255,255,.05) 70%); background-size:250% 100%; animation:shim 1.5s ease-in-out infinite; }
@keyframes shim{ 0%{ background-position:150% 0; } 100%{ background-position:-50% 0; } }
.sk-card{ aspect-ratio:61/100; border-radius:var(--r); }

/* empty state */
.empty-state{ flex:1; min-height:44vh; display:none; padding:clamp(28px,8vw,48px) clamp(20px,7vw,36px) clamp(36px,10vw,60px); flex-direction:column; align-items:center; justify-content:center; text-align:center; }
.es-title{ font-size:clamp(.95rem,3.4vw,1.1rem); font-weight:700; letter-spacing:-.2px; }
.es-text{ margin-top:6px; max-width:340px; font-size:clamp(.66rem,2.1vw,.76rem); color:var(--muted); line-height:1.7; }
.es-tips{ width:100%; max-width:360px; margin-top:clamp(16px,5vw,24px); display:flex; flex-direction:column; gap:7px; }
.es-tip{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--act-r); background:rgba(255,255,255,.05); text-align:left; }
.es-tip i{ font-size:1rem; color:var(--accent); flex-shrink:0; } .es-tip span{ font-size:clamp(.62rem,2vw,.7rem); color:var(--sub); line-height:1.5; }
.es-btn{ margin-top:clamp(16px,5vw,22px); display:flex; align-items:center; justify-content:center; gap:6px; height:var(--act-h); min-width:150px; padding:0 22px; border:none; border-radius:var(--act-r); background:var(--accent); color:#fff; font-size:.72rem; font-weight:700; transition:background .2s,transform .15s; }
.es-btn:hover{ background:var(--accent-hover); } .es-btn:active{ transform:scale(.98); }

/* ═════════ RIGHT COLUMN ═════════ */
.gs-cards{ padding:18px 0 2px; } .gs-card{ margin-bottom:12px; }
.gs-bar{ display:flex; align-items:center; gap:7px; width:100%; height:30px; padding:0 12px; border:none; border-radius:var(--act-r); background:rgba(255,255,255,.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); color:#fff; font-size:.72rem; font-weight:600; line-height:1; text-align:left; transition:background .2s,transform .15s; }
.gs-bar:hover{ background:rgba(255,255,255,.2); } .gs-bar:active{ transform:scale(.99); }
.gs-bar-ic{ font-size:14px; color:var(--accent); flex-shrink:0; } .gs-bar-t{ flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-bar-chev{ font-size:18px; color:rgba(255,255,255,.75); flex-shrink:0; transition:transform .25s ease; } .gs-card.collapsed .gs-bar-chev{ transform:rotate(-90deg); }
.gs-body{ display:grid; grid-template-rows:1fr; transition:grid-template-rows .3s ease; } .gs-inner{ min-height:0; overflow:hidden; } .gs-pad{ padding-top:8px; } .gs-card.collapsed .gs-body{ grid-template-rows:0fr; }
.gs-row{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:9px 2px; border-bottom:1px solid rgba(255,255,255,.06); } .gs-row:last-child{ border-bottom:none; }
.gs-row-l{ font-size:.66rem; color:rgba(255,255,255,.5); font-weight:500; white-space:nowrap; } .gs-row-v{ font-size:.74rem; font-weight:600; text-align:right; }
.gs-p{ font-size:.72rem; line-height:1.65; color:rgba(255,255,255,.7); padding:2px 2px 4px; } .gs-p b{ color:#fff; font-weight:600; }
.gs-callout{ display:flex; gap:9px; margin:8px 0 4px; padding:10px 11px; border-radius:var(--act-r); background:var(--accent-soft); border:1px solid var(--accent-border); font-size:.67rem; line-height:1.6; color:rgba(255,255,255,.8); }
.gs-callout i{ color:var(--accent); font-size:1rem; flex-shrink:0; margin-top:1px; } .gs-callout b{ color:#fff; }
.gs-step{ display:flex; gap:11px; padding:10px 2px; border-bottom:1px solid rgba(255,255,255,.06); } .gs-step:last-child{ border-bottom:none; }
.gs-sn{ width:22px; height:22px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.62rem; font-weight:700; color:#fff; background:var(--accent); }
.gs-st b{ display:block; font-size:.72rem; margin-bottom:2px; } .gs-st span{ display:block; font-size:.65rem; line-height:1.55; color:rgba(255,255,255,.58); }
.gs-th{ display:flex; align-items:center; gap:10px; width:100%; padding:9px 2px; border:none; border-bottom:1px solid rgba(255,255,255,.06); background:none; color:inherit; text-align:left; } .gs-th:last-child{ border-bottom:none; } .gs-th:hover{ opacity:.8; }
.gs-th-tx{ flex:1; min-width:0; } .gs-th b{ display:block; font-size:.72rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; } .gs-th small{ display:block; font-size:.62rem; color:var(--muted); margin-top:1px; }
.gs-note{ font-size:.68rem; color:rgba(255,255,255,.5); line-height:1.55; padding:4px 2px; }

/* ══ SHEETS (create / text / clip) — same lane as the page ══ */
.sheet{ position:fixed; inset:0; background:var(--bg); background-attachment:fixed; z-index:9000000; display:flex; flex-direction:column; opacity:0; visibility:hidden; pointer-events:none; transition:opacity .22s ease,visibility 0s linear .22s; box-shadow:0 0 0 200vmax rgba(1,2,24,.62); }
.sheet.open{ opacity:1; visibility:visible; pointer-events:all; transition:opacity .22s ease; }
@media (min-width:769px){ .sheet{ left:100px; border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (min-width:801px){ .sheet{ right:auto; width:580px; left:calc(100px + (100% - 100px - 580px)/2); } }
@media (min-width:1100px){ .sheet{ left:calc(100px + 22px); } }
#sh-ht{ z-index:9000100; } #sh-loc, #sh-paint, #sh-arr{ z-index:9000150; } #sh-clip{ z-index:9000200; }
.sheet-head .in{ display:flex; align-items:center; padding:max(8px,env(safe-area-inset-top)) 10px 8px; height:54px; }
.close-btn{ width:34px; height:34px; background:none; border:none; color:#fff; display:flex; align-items:center; justify-content:center; opacity:.9; transition:.2s; padding:0; flex-shrink:0; }
.close-btn:hover{ color:var(--accent); opacity:1; } .close-btn svg{ width:22px; height:22px; display:block; }
.sheet-title{ flex:1; text-align:center; font-size:.92rem; font-weight:700; margin-right:34px; }
.sheet-body{ flex:1; overflow-y:auto; overscroll-behavior:contain; scrollbar-width:thin; scrollbar-color:rgba(255,45,45,.4) transparent; }
.sheet-in{ padding:12px var(--pad) 24px; }
.sec{ margin-top:18px; padding-top:14px; border-top:1px solid var(--line); } .sec:first-child{ margin-top:0; padding-top:0; border-top:none; }
.sec-h{ font-size:.64rem; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--sub); margin-bottom:9px; }
.note{ color:var(--sub); font-size:.68rem; line-height:1.65; } .note p+p{ margin-top:8px; }
.hint{ font-size:.66rem; color:var(--muted); margin-top:6px; line-height:1.5; }
.btn{ display:flex; align-items:center; justify-content:center; gap:6px; width:100%; height:var(--act-h); padding:0 14px; border-radius:var(--act-r); border:none; background:var(--accent); color:#fff; font-size:.79rem; font-weight:700; line-height:1; white-space:nowrap; transition:background .2s,transform .15s; }
.btn:hover{ background:var(--accent-hover); } .btn:active{ transform:scale(.98); } .btn:disabled{ opacity:.5; cursor:not-allowed; }
.btn.ghost{ background:rgba(255,255,255,.1); backdrop-filter:blur(10px); } .btn.ghost:hover{ background:rgba(255,255,255,.2); }
.seg{ display:flex; padding:3px; border-radius:var(--act-r); background:var(--surface); border:1px solid var(--line); gap:3px; }
.seg button{ flex:1; height:calc(var(--act-h) - 2px); padding:0 6px; border:none; border-radius:3px; background:none; color:var(--sub); font-size:.79rem; font-weight:600; transition:.2s; white-space:nowrap; }
.seg button.on{ background:var(--accent); color:#fff; }
.f-input{ width:100%; height:var(--act-h); min-height:var(--act-h); padding:0 11px; border-radius:var(--act-r); border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:.76rem; transition:.2s; -webkit-appearance:none; appearance:none; resize:none; }
.f-input:focus{ border-color:var(--accent-focus); box-shadow:0 0 0 3px var(--accent-ring); } .f-input::placeholder{ color:var(--muted); }
select.f-input{ color-scheme:dark; cursor:pointer; padding-right:30px; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.5)' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; }
select.f-input option{ background:#070924; }
textarea.f-input{ height:auto; min-height:110px; padding:9px 11px; line-height:1.6; }
.cnt{ font-size:.66rem; color:var(--muted); text-align:right; margin-top:5px; }
.status{ font-size:.68rem; color:var(--sub); min-height:16px; line-height:1.4; } .status.err{ color:#ff8a8a; }
.stack{ display:flex; flex-direction:column; gap:12px; }
.btns{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }

/* create modal pieces */
.dur{ display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.dur button{ padding:11px 6px; border-radius:var(--r); border:1px solid var(--line); background:var(--surface); color:#fff; text-align:center; transition:.2s; }
.dur button b{ display:block; font-size:.9rem; } .dur button span{ display:block; font-size:.62rem; color:var(--muted); margin-top:2px; }
.dur button.on{ border-color:var(--accent); background:var(--accent-soft); } .dur button.on span{ color:var(--accent-l); }
.cr-pick{ display:flex; align-items:center; gap:11px; padding:10px 12px; border-radius:8px; border:1px dashed var(--border); background:var(--surface); transition:.2s; color:var(--sub); font-size:.72rem; width:100%; text-align:left; position:relative; }
.cr-pick:hover{ border-color:var(--accent-border); background:var(--accent-soft); }
.cr-pick > i{ font-size:20px; color:var(--accent); flex-shrink:0; } .cr-pick input[type=file]{ display:none; }
.cr-pt{ flex:1; min-width:0; line-height:1.45; } .cr-pt strong{ display:block; font-size:.76rem; font-weight:600; color:var(--text); } .cr-pt span{ display:block; font-size:.66rem; color:var(--muted); }
.cr-prev{ width:42px; height:42px; border-radius:7px; object-fit:cover; background:#15183a; flex-shrink:0; display:none; } .cr-prev.show{ display:block; }
.cr-x{ margin-left:auto; width:24px; height:24px; border-radius:5px; border:none; background:rgba(255,255,255,.1); color:var(--sub); flex-shrink:0; display:none; align-items:center; justify-content:center; font-size:13px; } .cr-x.show{ display:flex; }
.cr-x:hover{ background:var(--accent); color:#fff; }
.cr-rows{ max-height:300px; overflow-y:auto; display:flex; flex-direction:column; gap:6px; }
.cr-row{ display:flex; align-items:center; gap:9px; padding:7px 9px; border:1px solid var(--line); border-radius:8px; background:var(--surface); }
.cr-th{ width:38px; height:38px; border-radius:6px; overflow:hidden; background:#15183a; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:var(--sub); font-size:17px; } .cr-th img{ width:100%; height:100%; object-fit:cover; }
.cr-inf{ flex:1; min-width:0; } .cr-inf strong{ display:block; font-size:.7rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.acts{ display:flex; flex-wrap:wrap; gap:4px; margin-top:5px; }
.mini{ display:inline-flex; align-items:center; gap:3px; height:22px; padding:0 8px; border-radius:5px; border:none; background:rgba(255,255,255,.1); color:var(--sub); font-size:.68rem; font-weight:600; max-width:100%; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; transition:.2s; }
.mini.on{ background:var(--accent-soft); color:var(--accent-l); } .mini:hover{ background:rgba(255,255,255,.2); }
.cr-foot{ flex-shrink:0; background:var(--solid); border-top:1px solid var(--line); padding:10px var(--pad) max(12px,env(safe-area-inset-bottom)); }
.ht-view{ position:relative; width:min(100%,280px); aspect-ratio:4/5; margin:0 auto; border-radius:10px; overflow:hidden; background:#000; border:1px solid var(--line); }
.ht-view > img, .ht-view > video{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
#ht-capbox{ position:absolute; inset:0; } .ht-view .hl-cap-wrap{ padding:16px; } .cap-empty{ opacity:.6; font-weight:500; }
.cp-times{ display:flex; justify-content:space-between; font-size:.7rem; color:var(--sub); margin:14px 0 6px; } .cp-times b{ color:#fff; }
#cp-start{ width:100%; accent-color:var(--accent); }
.audio-pill{ display:flex; align-items:center; gap:8px; padding:7px 10px; border-radius:var(--act-r); background:var(--accent-soft); border:1px solid var(--accent-border); }

/* progress overlay + toast */
.pg{ position:fixed; inset:0; z-index:9500000; background:rgba(1,2,24,.78); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:20px; opacity:0; visibility:hidden; transition:opacity .2s; }
.pg.show{ opacity:1; visibility:visible; }
.pg-card{ width:100%; max-width:320px; background:#0d1030; border:1px solid var(--border); border-radius:12px; padding:20px 18px; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,.7); }
.pg-ic{ width:48px; height:48px; border-radius:50%; background:var(--accent-soft); border:1px solid var(--accent-border); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:22px; margin:0 auto 12px; }
.pg-ic.ok{ background:rgba(77,222,138,.13); border-color:rgba(77,222,138,.35); color:#4dde8a; } .pg-ic.bad{ background:rgba(255,45,45,.13); border-color:rgba(255,45,45,.35); color:#ff8a8a; }
.pg-title{ font-size:.88rem; font-weight:600; }
.pg-bar{ height:5px; border-radius:4px; background:rgba(255,255,255,.09); overflow:hidden; margin:14px 0 8px; } .pg-bar span{ display:block; height:100%; width:0; background:var(--accent); border-radius:4px; transition:width .15s ease; }
.pg-row{ display:flex; justify-content:space-between; font-size:.68rem; color:var(--sub); gap:10px; } .pg-row b{ color:#fff; }
.pg-close{ margin-top:14px; display:none; }
.toast{ position:fixed; bottom:90px; left:50%; transform:translateX(-50%) translateY(8px); background:#0b0d2a; border:1px solid var(--accent-border); color:#fff; padding:8px 16px; border-radius:5px; font-size:.72rem; font-weight:500; z-index:9600000; pointer-events:none; text-align:center; line-height:1.45; width:max-content; max-width:min(92vw,380px); opacity:0; transition:opacity .2s,transform .2s; }
.toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

/* ═════════ RESPONSIVE (800px and below): everything about 10% bigger ═════════ */
@media (max-width:800px){
    :root{ --topbar-h:59px; --pad:clamp(10px,3.3vw,17.6px); --ctl-h:clamp(33px,8.8vw,39.6px); --act-h:clamp(27.5px,7.92vw,33px); }
    body{ font-size:.88rem; }
    .chip{ font-size:clamp(.715rem,2.2vw,.77rem); padding:0 clamp(.62rem,2.2vw,.825rem); }
    .search-input-wrap input{ font-size:.836rem; }
    .tr-item span.nm{ font-size:.72rem; }
    .cd-user b{ font-size:.77rem; } .cd-bot{ font-size:.68rem; }
    .es-title{ font-size:clamp(1.045rem,3.74vw,1.21rem); } .es-text{ font-size:clamp(.726rem,2.31vw,.836rem); } .es-tip span{ font-size:clamp(.682rem,2.2vw,.77rem); } .es-btn{ font-size:.79rem; }
    .btn{ font-size:.85rem; } .seg button{ font-size:.85rem; } .f-input{ font-size:.84rem; }
    .cr-pt strong{ font-size:.84rem; } .cr-pt span, .hint, .note{ font-size:.73rem; }
    .sheet-title{ font-size:1.01rem; } .sheet-head .in{ height:59px; }
}

/* ── words + location on a media (shared by the create sheets and the viewer) ── */
.hl-cap-layer{ position:absolute; inset:0; z-index:4; pointer-events:none; }
.hl-cap-wrap{ position:absolute; inset:0; pointer-events:none; }
.hl-cap{ pointer-events:auto; max-width:100%; min-height:0; padding:calc(10px * var(--md-s)) calc(13px * var(--md-s)); border-radius:calc(12px * var(--md-s)); background:rgba(0,0,0,.34); color:#fff; font-size:calc(15px * var(--md-s)); font-weight:600; line-height:1.5; text-shadow:0 1px 6px rgba(0,0,0,.85); word-break:break-word; overflow-wrap:anywhere; -webkit-user-select:text; user-select:text; }
.hl-cap.clamp{ display:-webkit-box; -webkit-line-clamp:4; -webkit-box-orient:vertical; overflow:hidden; }
.hl-cap:not(.clamp){ max-height:100%; overflow-y:auto; scrollbar-width:none; } .hl-cap:not(.clamp)::-webkit-scrollbar{ display:none; }
.hl-more{ pointer-events:auto; flex-shrink:0; background:rgba(0,0,0,.5); border:1px solid rgba(255,255,255,.25); border-radius:99px; color:#fff; font-size:calc(11px * var(--md-s)); font-weight:600; padding:calc(3px * var(--md-s)) calc(12px * var(--md-s)); }
.hl-men{ font-weight:700; text-decoration:none; text-shadow:0 1px 5px rgba(0,0,0,.9); } .hl-men.user{ color:#ff3b4e; } .hl-men.group{ color:#3ea6ff; } .hl-men:hover{ text-decoration:underline; }
/* ── v2 additions ── */
.chip i{ font-size:.9rem; color:var(--accent); } .chip.active i{ color:#fff; }
.cd-cat{ padding:1px 7px; border-radius:4px; background:rgba(255,255,255,.2); font-size:.56rem; font-weight:600; max-width:46%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hl-place{ display:inline-flex; align-items:center; gap:calc(4px * var(--md-s)); margin-top:calc(7px * var(--md-s)); padding:calc(3px * var(--md-s)) calc(9px * var(--md-s)); border-radius:calc(8px * var(--md-s)); background:rgba(0,0,0,.42); color:#fff; font-size:calc(10.5px * var(--md-s)); font-weight:600; max-width:calc(100% - 8px); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-place:empty{ display:none; } .hl-place i{ color:#ff5a5a; font-size:calc(12px * var(--md-s)); }
.catpick{ display:flex; flex-wrap:wrap; gap:6px; } .catpick .chip{ flex:0 0 auto; }
.gs-topic{ display:flex; align-items:center; gap:10px; width:100%; padding:9px 2px; border:none; border-bottom:1px solid rgba(255,255,255,.06); background:none; color:inherit; text-align:left; } .gs-topic:last-child{ border-bottom:none; }
.gs-topic:hover .gs-tn b{ text-decoration:underline; }
.gs-ti{ width:28px; height:28px; border-radius:var(--act-r); display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; background:var(--accent-soft); color:var(--accent); }
.gs-tn{ display:flex; align-items:center; gap:7px; flex:1; min-width:0; } .gs-tn b{ font-size:.72rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-tn small{ font-size:.56rem; font-weight:600; color:var(--muted); padding:1px 7px; border-radius:4px; background:rgba(255,255,255,.07); flex-shrink:0; }
.mini.danger{ color:#ff9a9a; }
.ed-card{ border:1px solid var(--line); border-radius:10px; background:var(--surface); padding:10px; margin-bottom:12px; }
.ed-head{ display:flex; justify-content:space-between; align-items:center; gap:8px; font-size:.74rem; margin-bottom:8px; } .ed-head span{ color:var(--accent-l); font-weight:600; }
.ed-dur{ margin-bottom:8px; } .ed-dur button{ padding:7px 6px; }
.ed-row{ display:flex; gap:9px; padding:9px 0 2px; margin-top:8px; border-top:1px solid var(--line); } .ed-row.off{ opacity:.6; }
.ed-th{ width:46px; height:58px; border-radius:7px; overflow:hidden; background:#15183a; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:var(--sub); font-weight:700; position:relative; }
.ed-th img, .ed-th video{ width:100%; height:100%; object-fit:cover; display:block; }
.ed-meta{ font-size:.62rem; color:var(--muted); margin-top:2px; line-height:1.4; }
.ed-badge{ display:inline-block; margin-left:6px; padding:0 6px; border-radius:4px; background:rgba(255,255,255,.12); font-size:.56rem; font-weight:700; color:#fff; }
.ep-wrap{ display:flex; justify-content:center; background:#000; border-radius:10px; overflow:hidden; min-height:120px; }
.ep-tools{ display:flex; flex-wrap:wrap; gap:6px; margin:12px 0 10px; }
.ep-colors{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.ep-sw{ width:28px; height:28px; border-radius:8px; border:2px solid rgba(255,255,255,.2); padding:0; } .ep-sw.on{ border-color:#fff; box-shadow:0 0 0 2px var(--accent); }
.ep-size{ display:flex; align-items:center; gap:10px; font-size:.68rem; color:var(--sub); margin-bottom:10px; } .ep-size input{ flex:1; accent-color:var(--accent); }
.ep-stickers{ display:flex; flex-wrap:wrap; gap:2px; max-height:120px; overflow-y:auto; padding:6px; border:1px solid var(--line); border-radius:8px; background:var(--surface); }
.ep-st{ font-size:24px; background:none; border:none; padding:4px; line-height:1; }
/* ── v3: movable / resizable / rotatable words + location, place search ── */
.hl-cap-layer, #ht-capbox{ container-type:inline-size; }
.hl-cap-box, .hl-place-box{ position:absolute; display:flex; flex-direction:column; align-items:center; gap:6px; max-width:86%; text-align:center; transform-origin:center; pointer-events:none; }
.hl-cap{ font-size:15px; font-size:4cqw; }
.hl-cap:not(.clamp){ max-height:90cqw; }
.hl-pl{ display:inline-flex; align-items:center; gap:.4em; padding:.4em .85em; border-radius:.9em; background:rgba(0,0,0,.52); color:#fff; font-weight:700; font-size:13px; font-size:3.5cqw; line-height:1.3; white-space:nowrap; max-width:84cqw; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 5px rgba(0,0,0,.8); }
.hl-pl i{ color:#ff5a5a; font-size:1.1em; }
.arr-stage{ position:relative; width:min(100%,270px); aspect-ratio:9/16; margin:0 auto; background:#000; border-radius:12px; overflow:hidden; touch-action:none; border:1px solid var(--line); -webkit-user-select:none; user-select:none; }
.arr-bg{ position:absolute; inset:0; } .arr-bg img, .arr-bg video{ width:100%; height:100%; object-fit:contain; display:block; pointer-events:none; }
.arr-stage .hl-cap-layer{ z-index:3; }
.arr-el{ pointer-events:auto !important; touch-action:none; cursor:grab; } .arr-el:active{ cursor:grabbing; }
.arr-el.sel{ outline:1.5px dashed rgba(255,255,255,.9); outline-offset:5px; border-radius:4px; }
.arr-el .hl-cap{ pointer-events:none; }
.loc-tag{ display:inline-flex; align-items:center; gap:8px; padding:6px 6px 6px 11px; border-radius:99px; background:var(--accent-soft); border:1px solid var(--accent-border); font-size:.72rem; font-weight:600; max-width:100%; }
.loc-tag i{ color:var(--accent); } .loc-tag span{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.loc-tag button{ width:20px; height:20px; border-radius:50%; border:none; background:rgba(255,255,255,.16); color:#fff; font-size:11px; flex-shrink:0; }
.loc-list{ margin-top:10px; display:flex; flex-direction:column; gap:6px; }
.loc-row{ display:flex; align-items:flex-start; gap:9px; width:100%; padding:9px 10px; border-radius:8px; background:var(--surface); border:1px solid var(--line); color:#fff; text-align:left; font-size:.72rem; line-height:1.45; }
.loc-row i{ color:var(--accent); flex-shrink:0; margin-top:1px; } .loc-row:hover{ border-color:var(--accent-border); }
.loc-msg{ padding:14px 4px; text-align:center; font-size:.7rem; color:var(--muted); }
/* the dark layer with the name when the viewer opens is removed */
.hl-intro{ display:none !important; }
/* words on every red button / active chip are black; grey buttons keep white words */
.chip.active, .chip.active i, .btn, .es-btn, .seg button.on, .cd-new, .gs-sn, .cat-arrow:hover, .cr-x:hover{ color:#000 !important; }
.btn.ghost{ color:#fff !important; }
@media(prefers-reduced-motion:reduce){ *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="cp-shell">

<!-- ═════════════════ MAIN LANE ═════════════════ -->
<div class="ac-lane">

    <div class="sticky-head">
        <div class="cm-topbar">
            <div class="tb-title">Dayleez</div>
            <div class="tb-right">
                <button type="button" class="hft-btn" onclick="dcOpen()" aria-label="Create Dayleez" title="Create Dayleez"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 4.5v15M4.5 12h15"/></svg></button>
                <button type="button" class="rp-tb-btn" id="searchToggleBtn" onclick="toggleSearch()" aria-label="Search" title="Search"><?php echo $search_svg; ?></button>
            </div>
        </div>
        <div class="toolbar">
            <div class="tab-row">
                <button type="button" class="chip active" id="tabBtn-following" onclick="switchTab('following')">Following</button>
                <button type="button" class="chip" id="tabBtn-discover" onclick="switchTab('discover')">Discover</button>
                <button type="button" class="chip" id="tabBtn-mine" onclick="switchTab('mine')">My Dayleez</button>
            </div>
            <div class="cat-bar">
                <button type="button" class="cat-arrow" id="catLeft" aria-label="Scroll left"><i class="ri-arrow-left-s-line"></i></button>
                <div class="cat-scroll-wrap"><div class="cat-scrollbar" id="catScrollbar">
                    <button type="button" class="chip active" data-cat="All" onclick="selectCategory(this.dataset.cat)">All</button>
                    <?php foreach($DL_CATS as $c): ?><button type="button" class="chip" data-cat="<?php echo htmlspecialchars($c[0]); ?>" onclick="selectCategory(this.dataset.cat)"><i class="<?php echo $c[1]; ?>"></i><?php echo htmlspecialchars($c[0]); ?></button><?php endforeach; ?>
                </div></div>
                <button type="button" class="cat-arrow" id="catRight" aria-label="Scroll right"><i class="ri-arrow-right-s-line"></i></button>
            </div>
        </div>
    </div>

    <div class="search-wrap" id="searchWrap"><div class="search-inner"><div class="search-pad">
        <div class="search-input-wrap">
            <?php echo $search_svg; ?>
            <input type="text" id="searchInput" placeholder="Search by username" autocomplete="off" spellcheck="false">
            <button type="button" class="search-clear-x" id="searchClearBtn" onclick="clearSearch()" aria-label="Clear search"><i class="ri-close-line"></i></button>
        </div>
    </div></div></div>

    <div class="tray" id="tray"></div>
    <div class="grid" id="grid"></div>
    <div class="empty-state" id="emptyState"></div>
    <div id="scrollSentinel"></div>

</div>

<!-- ═════════════════ RIGHT COLUMN ═════════════════ -->
<aside class="cp-side" aria-label="About Dayleez"><div class="gs-cards">

    <div class="gs-card">
        <button type="button" class="gs-bar" onclick="gsToggle(this)"><i class="ri-time-line gs-bar-ic"></i><span class="gs-bar-t">About Dayleez</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
        <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
            <p class="gs-p"><b>Dayleez</b> are photos, videos and text that disappear on their own. You choose how long yours stay: <b>1, 2 or 3 days</b>.</p>
            <div class="gs-callout"><i class="ri-information-line"></i><span>Each media gets its own piece in the ring around a photo. A grey piece means you already watched it.</span></div>
        </div></div></div>
    </div>

    <div class="gs-card">
        <button type="button" class="gs-bar" onclick="gsToggle(this)"><i class="ri-bar-chart-2-line gs-bar-ic"></i><span class="gs-bar-t">Your Dayleez</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
        <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
            <div class="gs-row"><span class="gs-row-l">Live now</span><span class="gs-row-v"><?php echo number_format($st_active); ?></span></div>
            <div class="gs-row"><span class="gs-row-l">Views</span><span class="gs-row-v"><?php echo number_format($st_views); ?></span></div>
            <div class="gs-row"><span class="gs-row-l">Likes</span><span class="gs-row-v"><?php echo number_format($st_likes); ?></span></div>
            <div class="gs-row"><span class="gs-row-l">Messages</span><span class="gs-row-v"><?php echo number_format($st_replies); ?></span></div>
        </div></div></div>
    </div>

    <?php if(!empty($interest) || !empty($top_cats)): ?>
    <div class="gs-card">
        <button type="button" class="gs-bar" onclick="gsToggle(this)"><i class="ri-price-tag-3-line gs-bar-ic"></i><span class="gs-bar-t">Discover by category</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
        <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
            <?php if(!empty($interest)): ?><div class="gs-note">Picked for you, from what you watch and like</div>
            <?php foreach($interest as $ic): ?>
            <button type="button" class="gs-topic" data-cat="<?php echo htmlspecialchars($ic); ?>" onclick="pickCategory(this.dataset.cat)"><span class="gs-ti"><i class="<?php echo htmlspecialchars($DL_CAT_ICON[$ic] ?? 'ri-hashtag'); ?>"></i></span><span class="gs-tn"><b><?php echo htmlspecialchars($ic); ?></b></span></button>
            <?php endforeach; endif; ?>
            <?php if(!empty($top_cats)): ?><div class="gs-note" style="margin-top:6px">Busy right now</div>
            <?php foreach($top_cats as $tc): ?>
            <button type="button" class="gs-topic" data-cat="<?php echo htmlspecialchars($tc['category']); ?>" onclick="pickCategory(this.dataset.cat)"><span class="gs-ti"><i class="<?php echo htmlspecialchars($DL_CAT_ICON[$tc['category']] ?? 'ri-hashtag'); ?>"></i></span><span class="gs-tn"><b><?php echo htmlspecialchars($tc['category']); ?></b><small><?php echo (int)$tc['n']; ?> live</small></span></button>
            <?php endforeach; endif; ?>
        </div></div></div>
    </div>
    <?php endif; ?>

    <?php if(!empty($creators)): ?>
    <div class="gs-card">
        <button type="button" class="gs-bar" onclick="gsToggle(this)"><i class="ri-user-star-line gs-bar-ic"></i><span class="gs-bar-t">Posting now</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
        <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
            <?php foreach($creators as $cr): ?>
            <button type="button" class="gs-th" onclick="dlOpen(<?php echo (int)$cr['user_id']; ?>,[<?php echo (int)$cr['user_id']; ?>])">
                <span class="rg" style="width:38px;height:38px"><img src="<?php echo htmlspecialchars(dl_pic($cr['profile_pic'])); ?>" alt="" style="inset:0;width:100%;height:100%;border:2px solid var(--accent);border-radius:30%" onerror="this.src='uploads/me.png'"></span>
                <span class="gs-th-tx"><b><?php echo htmlspecialchars($cr['username']); ?></b><small><?php echo (int)$cr['n']; ?> live</small></span>
            </button>
            <?php endforeach; ?>
        </div></div></div>
    </div>
    <?php endif; ?>

    <div class="gs-card">
        <button type="button" class="gs-bar" onclick="gsToggle(this)"><i class="ri-compass-3-line gs-bar-ic"></i><span class="gs-bar-t">How it works</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
        <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
            <div class="gs-step"><span class="gs-sn">1</span><span class="gs-st"><b>Tap +</b><span>Add photos, videos or text, pick a song, and choose 1, 2 or 3 days.</span></span></div>
            <div class="gs-step"><span class="gs-sn">2</span><span class="gs-st"><b>Share it</b><span>People you track see it first. Everyone else finds it in Discover.</span></span></div>
            <div class="gs-step"><span class="gs-sn">3</span><span class="gs-st"><b>It disappears</b><span>When the time is up it is gone. You can delete it sooner.</span></span></div>
        </div></div></div>
    </div>

</div></aside>
</div><!-- /.cp-shell -->

<!-- ══════════════ CREATE DAYLEEZ (full screen, same lane) ══════════════ -->
<div class="sheet" id="sh-create">
    <div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="dcClose()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title" id="dc-title">Create Dayleez</div></div></div>
    <div class="sheet-body"><div class="sheet-in">
      <div class="seg" id="dc-tabs" style="margin-bottom:14px"><button type="button" class="on" data-t="new" onclick="dcTab('new')">New Dayleez</button><button type="button" data-t="edit" onclick="dcTab('edit')">Edit live</button></div>
      <div id="dc-pane-edit" style="display:none">
        <p class="hint" style="margin:0 0 12px">Your live Dayleez. Changes save right away. <b>Remove</b> hides a media until you restore it. <b>Delete</b> erases it for good. Both work any time before it expires.</p>
        <div id="ed-list"></div>
      </div>
      <div class="stack" id="dc-pane-new">

        <div><div class="sec-h">How long should it stay?</div>
            <div class="dur" id="dc-dur">
                <button type="button" class="on" data-d="1" onclick="dcSetDays(1)"><b>1 day</b><span>24 hours</span></button>
                <button type="button" data-d="2" onclick="dcSetDays(2)"><b>2 days</b><span>48 hours</span></button>
                <button type="button" data-d="3" onclick="dcSetDays(3)"><b>3 days</b><span>72 hours</span></button>
            </div>
            <p class="hint" id="dc-exp"></p></div>

        <label class="cr-pick" for="dc-files">
            <img class="cr-prev" id="dc-pick-prev" alt=""><i class="ri-image-add-line" id="dc-pick-icon"></i>
            <div class="cr-pt"><strong id="dc-pick-title">Choose photos or videos</strong><span id="dc-pick-sub">Add 1 to <?php echo DL_MAX_MEDIA; ?> per Dayleez.</span></div>
            <input type="file" id="dc-files" accept="image/*,video/*" multiple></label>
        <button type="button" class="cr-pick" onclick="dcAddText()"><i class="ri-text"></i><div class="cr-pt"><strong>Add a text Dayleez</strong><span>Words on a Text.jpg background. You can also write on any media below.</span></div></button>
        <div id="dc-items"></div>

        <label class="cr-pick" for="dc-cover-file">
            <img class="cr-prev" id="dc-cv-prev" alt=""><i class="ri-image-line" id="dc-cv-icon"></i>
            <div class="cr-pt"><strong id="dc-cv-title">Cover image (optional)</strong><span id="dc-cv-sub">Skip it and your first media becomes the cover.</span></div>
            <button type="button" class="cr-x" id="dc-cv-x" aria-label="Remove cover" onclick="event.preventDefault();event.stopPropagation();dcCoverClear()"><i class="ri-close-line"></i></button>
            <input type="file" id="dc-cover-file" accept="image/*"></label>

        <div><div class="sec-h">Location (optional)</div>
            <input class="f-input" id="dc-place" readonly maxlength="100" placeholder="Search a place" autocomplete="off" onclick="dcPickPlace()" style="cursor:pointer">
            <p class="hint">Shows as a pin on every media. You can set a different place on each one below, and move, resize and rotate it with Arrange.</p></div>

        <div><div class="sec-h">Category (optional)</div>
            <input type="hidden" id="dc-cat" value=""><div class="catpick" id="dc-catpick"></div><p class="hint">Helps the right people find it in Discover.</p></div>

        <div class="seg" id="dc-mode"><button type="button" class="on" data-mode="whole" onclick="dcSetMode('whole')">One song for all</button><button type="button" data-mode="each" onclick="dcSetMode('each')">Song per media</button></div>
        <p class="hint" id="dc-mode-help" style="margin:-6px 0 0">One song clip plays across every media in this Dayleez.</p>
        <div id="dc-song-wrap"><div class="cr-pick" onclick="dcSongPick()" role="button" tabindex="0"><i class="ri-music-2-line"></i>
            <div class="cr-pt"><strong id="dc-song-title">Song clip (optional)</strong><span id="dc-song-sub">Pick the part of a song you want, up to 30 seconds.</span></div>
            <button type="button" class="cr-x" id="dc-song-x" aria-label="Remove song" onclick="event.stopPropagation();dcSongClear()"><i class="ri-close-line"></i></button></div></div>

        <div class="status" id="dc-status" aria-live="polite"></div>
        <div class="note sec"><p>Draw and add stickers on photos and text slides with the pencil on each item (videos stay as they are). A Dayleez can hold up to <?php echo DL_MAX_MEDIA; ?> photos, videos and text slides, and you can have <?php echo DL_MAX_ACTIVE; ?> live at once. Tag people with @username and pods by name. People who don't allow @mentions from you can't be mentioned.</p></div>
    </div></div></div>
    <div class="cr-foot" id="dc-foot"><button type="button" class="btn" id="dc-post" onclick="dcUpload()"><i class="ri-send-plane-fill"></i> Post Dayleez</button></div>
</div>

<!-- text sheet -->
<div class="sheet" id="sh-ht"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="htClose()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Dayleez text</div></div></div>
  <div class="sheet-body"><div class="sheet-in">
    <textarea class="f-input" id="ht-input" maxlength="1000" placeholder="Write something. Tag people with @username, and pods by their name." oninput="htUpdate()"></textarea>
    <div class="cnt" id="ht-count">0/1000</div><div class="status" id="ht-status"></div>
    <div class="sec"><div class="sec-h">Preview</div><div id="ht-preview"></div></div>
    <div class="btns"><button type="button" class="btn ghost" id="ht-remove" onclick="htRemove()" style="display:none;width:auto;padding:0 18px">Remove text</button><button type="button" class="btn" onclick="htDone()" style="flex:1">Done</button></div>
    <div class="note sec"><p>Your text appears in the middle of the media. Long text is shortened with a "more" button. @usernames turn red and pod names turn blue.</p></div>
  </div></div></div>

<!-- song clip sheet -->
<div class="sheet" id="sh-clip"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="cpClose()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Choose a song clip</div></div></div>
  <div class="sheet-body"><div class="sheet-in">
    <label class="cr-pick" for="cp-file"><i class="ri-music-2-line"></i><div class="cr-pt"><strong id="cp-file-title">Choose a song</strong><span>MP3, M4A, WAV and more. Only the part you pick is kept.</span></div><input type="file" id="cp-file" accept="audio/*"></label>
    <div id="cp-editor" style="display:none">
      <input type="text" class="f-input" id="cp-title" maxlength="60" placeholder="Song name (optional)" style="margin-top:12px">
      <div class="cp-times"><span>Starts at <b id="cp-start-lbl">0:00</b></span><span>Ends at <b id="cp-end-lbl">0:15</b></span></div>
      <input type="range" id="cp-start" min="0" max="100" step="0.1" value="0" oninput="cpUpdate()">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 0"><span class="sec-h" style="margin:0">Clip length</span>
        <select class="f-input" id="cp-len" style="width:auto" onchange="cpUpdate()"><option value="10">10 seconds</option><option value="15" selected>15 seconds</option><option value="20">20 seconds</option><option value="30">30 seconds</option></select></div>
      <button type="button" class="btn ghost" onclick="cpPreview()" style="margin-bottom:8px"><i class="ri-play-circle-line"></i> <span id="cp-prev-txt">Preview clip</span></button>
      <button type="button" class="btn" id="cp-use" onclick="cpUse(this)">Use this clip</button></div>
    <div class="status" id="cp-status" style="margin-top:10px"></div>
    <div class="note sec"><p>Drag the slider to the part you want, choose the length (up to 30 seconds) and preview it. Only that short clip is uploaded.</p></div>
  </div></div></div>

<!-- location sheet -->
<div class="sheet" id="sh-loc"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="locClose()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Add location</div></div></div>
  <div class="sheet-body"><div class="sheet-in">
    <div id="loc-tagwrap" style="display:none;margin-bottom:10px"></div>
    <input type="text" class="f-input" id="loc-input" maxlength="80" placeholder="Search a place (3+ letters)" autocomplete="off" spellcheck="false" oninput="locInput()">
    <div class="loc-list" id="loc-list"></div>
    <div class="btns"><button type="button" class="btn ghost" onclick="locClear()" style="width:auto;padding:0 18px">Remove location</button><button type="button" class="btn" onclick="locDone()" style="flex:1">Done</button></div>
    <div class="note sec"><p>Search a place and tap it. It shows as a pin on the media, and you can move, resize and rotate it with Arrange. Places come from OpenStreetMap, and only the place name is saved.</p></div>
  </div></div></div>

<!-- draw + stickers -->
<div class="sheet" id="sh-paint"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="epClose()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Draw &amp; stickers</div></div></div>
  <div class="sheet-body"><div class="sheet-in">
    <div class="ep-wrap" id="ep-wrap"></div>
    <div class="ep-tools">
      <button type="button" class="mini on" id="ep-b-draw" onclick="epMode('draw')"><i class="ri-pencil-line"></i>Draw</button>
      <button type="button" class="mini" id="ep-b-move" onclick="epMode('move')"><i class="ri-drag-move-2-line"></i>Move</button>
      <button type="button" class="mini" onclick="epUndo()"><i class="ri-arrow-go-back-line"></i>Undo</button>
      <button type="button" class="mini" onclick="epDelSel()"><i class="ri-delete-bin-line"></i>Delete</button>
    </div>
    <div class="ep-colors" id="ep-colors"></div>
    <div class="ep-size"><span>Brush</span><input type="range" id="ep-size" min="2" max="28" value="6" oninput="epSize(this.value)"></div>
    <div class="sec-h">Stickers</div>
    <div class="ep-stickers" id="ep-stickers"></div>
    <div class="btns"><button type="button" class="btn ghost" onclick="epClose()" style="width:auto;padding:0 18px">Cancel</button><button type="button" class="btn" id="ep-done" onclick="epDone()" style="flex:1">Done</button></div>
  </div></div></div>

<!-- arrange words + location -->
<div class="sheet" id="sh-arr"><div class="sheet-head"><div class="in"><button type="button" class="close-btn" onclick="arrClose()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button><div class="sheet-title">Arrange</div></div></div>
  <div class="sheet-body"><div class="sheet-in">
    <div class="arr-stage" id="arr-stage"><div class="arr-bg" id="arr-bg"></div><div class="hl-cap-layer" id="arr-layer"></div></div>
    <div class="seg" id="arr-seg" style="margin-top:14px"><button type="button" class="on" data-k="cap" onclick="arrSelect('cap')">Words</button><button type="button" data-k="place" onclick="arrSelect('place')">Location</button></div>
    <div class="ep-size" style="margin-top:12px"><span style="width:44px">Size</span><input type="range" id="arr-size" min="40" max="400" value="100" oninput="arrSlider()"><b id="arr-size-v" style="width:44px;text-align:right;color:#fff">100%</b></div>
    <div class="ep-size"><span style="width:44px">Angle</span><input type="range" id="arr-rot" min="-180" max="180" value="0" oninput="arrSlider()"><b id="arr-rot-v" style="width:44px;text-align:right;color:#fff">0°</b></div>
    <p class="hint">Drag to move. Use two fingers to resize and turn it any way you like, or use the sliders.</p>
    <div class="btns"><button type="button" class="btn ghost" onclick="arrReset()" style="width:auto;padding:0 18px">Reset</button><button type="button" class="btn" onclick="arrDone()" style="flex:1">Done</button></div>
  </div></div></div>

<!-- progress + toast -->
<div class="pg" id="pg"><div class="pg-card">
  <div class="pg-ic" id="pg-ic"><i class="ri-upload-cloud-2-line" id="pg-i"></i></div>
  <div class="pg-title" id="pg-title">Posting…</div>
  <div class="pg-bar"><span id="pg-fill"></span></div>
  <div class="pg-row"><span id="pg-sub">Starting…</span><b id="pg-pct">0%</b></div>
  <button type="button" class="btn pg-close" id="pg-close" onclick="PG.hide()">Close</button>
</div></div>
<div class="toast" id="toast" role="status"></div>


<script>
/* ═══════════════════════════════════════════════════════════
   SHARED
═══════════════════════════════════════════════════════════ */
const $=id=>document.getElementById(id);
const esc=v=>String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const shortT=(v,n)=>{v=String(v||'');return v.length>n?v.slice(0,n-1)+'…':v};
const DL_TEXT_BG=<?= json_encode(DL_TEXT_BG) ?>,DL_USER_URL=<?= json_encode(DL_USER_URL) ?>,DL_GROUP_URL=<?= json_encode(DL_GROUP_URL) ?>;
const DL_MAX=<?= (int)DL_MAX_MEDIA ?>,IMG_MB=10,VID_MB=50;
const ME=<?= (int)$me ?>,MY_PIC=<?= json_encode($my_pic) ?>;
const RAND_SEED=<?= (int)$rand_seed ?>,DL_CATS=<?= json_encode($DL_CATS) ?>;
const OPEN_U=<?= (int)$open_u ?>,OPEN_D=<?= (int)$open_d ?>;
function toast(m){const t=$('toast');t.textContent=m;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3200)}
function gsToggle(b){const c=b.closest('.gs-card');if(c)c.classList.toggle('collapsed')}
function fmtDur(s){s=Math.max(0,s|0);if(s<60)return'now';const m=Math.floor(s/60);if(m<60)return m+'m';const h=Math.floor(m/60);if(h<24)return h+'h';return Math.floor(h/24)+'d'}
const ageLabel=s=>s<60?'Just now':fmtDur(s)+' ago';
const leftLabel=s=>s<60?'ending soon':fmtDur(s)+' left';

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
async function dlCall(action,fields,files,onp){
  const fd=new FormData();fd.append('dl_action',action);
  Object.keys(fields||{}).forEach(k=>fd.append(k,fields[k]));
  Object.keys(files||{}).forEach(k=>fd.append(k,files[k],files[k].name||'clip.wav'));
  const d=await xhrSend(fd,onp);
  if(!d.success)throw new Error(d.msg||'Something went wrong.');
  return d;
}
function dlPost(body){
  return fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).then(r=>r.json());
}

/* @red people + blue pods inside a text (the server tells us which names are real) */
function dlRich(text,map){
  text=String(text||'');map=map||{};const users=map.users||{};
  const groups=(map.groups||[]).slice().sort((a,b)=>b.name.length-a.name.length);
  const gid={};groups.forEach(g=>gid[g.name.toLowerCase()]=g.id);
  let re='(@[A-Za-z0-9_.]+)';
  if(groups.length)re+='|(^|[^\\w@])('+groups.map(g=>g.name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')).join('|')+')(?![\\w])';
  const rx=new RegExp(re,'gi');let out='',last=0,m;
  while((m=rx.exec(text))!==null){
    if(m[1]){
      const core=m[1].slice(1).replace(/\.+$/,''),id=users[core.toLowerCase()];if(!id)continue;
      out+=esc(text.slice(last,m.index))+'<a class="hl-men user" href="'+esc(DL_USER_URL+id)+'">@'+esc(core)+'</a>';last=m.index+1+core.length;rx.lastIndex=last;
    }else{
      const st=m.index+m[2].length,id=gid[m[3].toLowerCase()];if(!id)continue;
      out+=esc(text.slice(last,st))+'<a class="hl-men group" href="'+esc(DL_GROUP_URL+id)+'">'+esc(m[3])+'</a>';last=st+m[3].length;
    }
  }
  return(out+esc(text.slice(last))).replace(/\n/g,'<br>');
}
/* position / size / angle of the words and the location on a media */
const TF_CAP={x:50,y:50,s:1,r:0},TF_PLACE={x:50,y:80,s:1,r:0};
function dlTf(str,def){const p=String(str||'').split(',').map(Number);if(p.length!==4||p.some(isNaN))return def;return{x:p[0],y:p[1],s:p[2],r:p[3]}}
const tfStr=t=>[t.x,t.y,t.s,t.r].map(v=>Math.round(v*100)/100).join(',');
const tfCss=t=>'left:'+t.x+'%;top:'+t.y+'%;transform:translate(-50%,-50%) rotate('+t.r+'deg) scale('+t.s+')';
function dlCapHTML(text,map,tf){
  const t=tf||TF_CAP,long=text.length>120||(text.match(/\n/g)||[]).length>=3;
  return '<div class="hl-cap-wrap"><div class="hl-cap-box" style="'+tfCss(t)+'"><div class="hl-cap'+(long?' clamp':'')+'">'+dlRich(text,map)+'</div>'+(long?'<button type="button" class="hl-more" onclick="hlMore(this)">more</button>':'')+'</div></div>';
}
function dlPlaceHTML(place,tf){
  const t=tf||TF_PLACE;
  return '<div class="hl-cap-wrap"><div class="hl-place-box" style="'+tfCss(t)+'"><div class="hl-pl"><i class="ri-map-pin-2-fill"></i>'+esc(place)+'</div></div></div>';
}
const resolveCache={};
async function dlResolve(text){
  const t=String(text||'').trim();if(!t)return{users:{},groups:[],blocked:[]};
  if(resolveCache[t])return resolveCache[t];
  try{const d=await dlCall('resolve',{text:t});return resolveCache[t]={users:d.users||{},groups:d.groups||[],blocked:d.blocked||[]}}
  catch(e){return{users:{},groups:[],blocked:[]}}
}
const mentionBad=(map,old)=>{
  const prev=new Set((String(old||'').match(/@[A-Za-z0-9_.]{2,40}/g)||[]).map(s=>s.slice(1).replace(/\.+$/,'').toLowerCase()));
  return((map&&map.blocked)||[]).filter(b=>!prev.has(String(b.name).toLowerCase()));
};

/* ═══════════════════════════════════════════════════════════
   THE BROKEN RING: one piece per media, grey when watched
═══════════════════════════════════════════════════════════ */
function ringHTML(flags,S,sw,pic,extra){
  /* rounded-square ring: every media is one piece. The line keeps the same thickness and the same gap size
     no matter how many pieces there are. */
  var n=flags.length,rad=Math.round(S*.3),half=sw/2,w=S-sw,rx=Math.max(2,rad-half);
  var per=4*(w-2*rx)+2*Math.PI*rx,p='';
  var attr='x="'+half+'" y="'+half+'" width="'+w+'" height="'+w+'" rx="'+rx+'" fill="none" stroke-width="'+sw+'" stroke-linecap="butt"';
  if(!n)p='<rect '+attr+' stroke="rgba(255,255,255,.35)" stroke-dasharray="4 4"/>';
  else if(n===1)p='<rect '+attr+' stroke="'+(flags[0]?'rgba(255,255,255,.3)':'#FF2D2D')+'"/>';
  else{
    var unit=per/n,gap=Math.min(Math.max(4,sw*1.6),unit*.5),len=unit-gap,start=(w/2-rx);
    flags.forEach(function(seen,i){
      p+='<rect '+attr+' stroke="'+(seen?'rgba(255,255,255,.3)':'#FF2D2D')+'" stroke-dasharray="'+len.toFixed(2)+' '+(per-len).toFixed(2)+'" stroke-dashoffset="'+(-(start+i*unit+gap/2)).toFixed(2)+'"/>';
    });
  }
  var off=sw+3,sz=S-off*2,ir=Math.round(sz*.3);
  return '<span class="rg" style="width:'+S+'px;height:'+S+'px"><svg viewBox="0 0 '+S+' '+S+'" aria-hidden="true">'+p+'</svg><img src="'+esc(pic)+'" alt="" style="left:'+off+'px;top:'+off+'px;width:'+sz+'px;height:'+sz+'px;border-radius:'+ir+'px" onerror="this.onerror=null;this.src=\'uploads/me.png\'">'+(extra||'')+'</span>';
}

/* ═══════════════════════════════════════════════════════════
   FEED: tabs, categories, search, tray, cards
═══════════════════════════════════════════════════════════ */
let tab='following',cat='All',search='',offset=0,hasMore=true,loading=false,token=0,searchT=null;
let trayUsers=[],cardUsers=[];

function switchTab(t){tab=t;document.querySelectorAll('.tab-row .chip').forEach(b=>b.classList.remove('active'));$('tabBtn-'+t).classList.add('active');resetAndLoad()}
function selectCategory(c){
  cat=c;document.querySelectorAll('#catScrollbar .chip').forEach(p=>p.classList.toggle('active',p.dataset.cat===c));
  const a=document.querySelector('#catScrollbar .chip.active');if(a){try{a.scrollIntoView({inline:'nearest',block:'nearest'})}catch(e){}}
  resetAndLoad();
}
function pickCategory(c){
  if(tab!=='discover'){tab='discover';document.querySelectorAll('.tab-row .chip').forEach(b=>b.classList.remove('active'));$('tabBtn-discover').classList.add('active')}
  selectCategory(c);window.scrollTo({top:0,behavior:'smooth'});
}
function toggleSearch(){
  const w=$('searchWrap'),b=$('searchToggleBtn'),i=$('searchInput'),open=!w.classList.contains('open');
  w.classList.toggle('open',open);b.classList.toggle('on',open);
  if(open){window.scrollTo({top:0,behavior:'smooth'});setTimeout(()=>i.focus(),280)}
  else if(i.value.trim()!==''||search!==''){i.value='';$('searchClearBtn').classList.remove('show');search='';resetAndLoad()}
}
$('searchInput').addEventListener('input',function(){
  clearTimeout(searchT);const v=this.value;$('searchClearBtn').classList.toggle('show',v.trim()!=='');
  searchT=setTimeout(()=>{search=v.trim();resetAndLoad()},350);
});
function clearSearch(){const i=$('searchInput');i.value='';$('searchClearBtn').classList.remove('show');search='';resetAndLoad();i.focus()}
(function(){const b=$('catScrollbar'),l=$('catLeft'),r=$('catRight');l.addEventListener('click',()=>b.scrollBy({left:-200,behavior:'smooth'}));r.addEventListener('click',()=>b.scrollBy({left:200,behavior:'smooth'}))})();

function resetAndLoad(){token++;loading=false;offset=0;hasMore=true;cardUsers=[];$('grid').innerHTML='';$('emptyState').style.display='none';loadCards()}

function skCards(n){let h='';for(let i=0;i<n;i++)h+='<div class="sk-card sk-shimmer skel"></div>';$('grid').insertAdjacentHTML('beforeend',h)}
function clearSk(){document.querySelectorAll('#grid .skel').forEach(e=>e.remove())}

function cardHTML(u,i){
  const segs=u.flags.map(f=>'<i class="'+(f?'s':'')+'"></i>').join('');
  const t=u.thumb||{type:'text',path:'',text:''};
  let bg;
  if(t.type==='text')bg='<div class="bg txt"><div class="tx"><span>'+esc(t.text)+'</span></div></div>';
  else if(t.type==='video'&&!t.cover)bg='<div class="bg"><video src="'+esc(t.path)+'#t=0.1" muted playsinline preload="metadata"></video></div>';
  else bg='<div class="bg"><img src="'+esc(t.path)+'" alt="" loading="lazy" onerror="this.style.display=\'none\'"></div>';
  const fresh=u.flags.some(f=>!f);
  return '<button type="button" class="dl-card" style="animation-delay:'+((i%12)*30)+'ms" onclick="dlOpen('+u.uid+')" aria-label="Open '+esc(u.name)+'">'+bg+'<span class="shade"></span><span class="segs">'+segs+'</span>'+
    '<span class="cd-user">'+ringHTML(u.flags,28,2,u.pic)+'<b>'+esc(u.isYou?'Your Dayleez':u.name)+'</b></span>'+
    '<span class="cd-bot"><i class="ri-time-line"></i>'+leftLabel(u.left)+(tab==='discover'&&u.cat?'<span class="cd-cat">'+esc(u.cat)+'</span>':'')+(fresh?'<span class="cd-new">NEW</span>':'')+'</span></button>';
}
function tip(ic,t){return '<div class="es-tip"><i class="'+ic+'"></i><span>'+t+'</span></div>'}
function showEmpty(){
  const filtered=cat!=='All'||search!=='';
  let title,desc,tips,btn;
  if(filtered){title='Nothing found';desc='No Dayleez match your filters. Try another category or name.';tips=tip('ri-price-tag-3-line','Pick another category, or choose All.')+tip('ri-search-line','Check the spelling of your search.');btn='<button type="button" class="es-btn" onclick="clearFilters()"><i class="ri-close-line"></i> Clear filters</button>'}
  else if(tab==='mine'){title="You haven't posted a Dayleez";desc='Share a moment. It stays for 1, 2 or 3 days, then disappears.';tips=tip('ri-time-line','You choose how long it stays.')+tip('ri-music-2-line','Add a song clip to any media.');btn='<button type="button" class="es-btn" onclick="dcOpen()"><i class="ri-add-line"></i> Create Dayleez</button>'}
  else if(tab==='discover'){title='Nothing new to discover';desc='When people you do not track post a Dayleez, it shows up here.';tips=tip('ri-compass-3-line','Check back a little later.')+tip('ri-add-circle-line','Post one yourself to get things going.');btn='<button type="button" class="es-btn" onclick="dcOpen()"><i class="ri-add-line"></i> Create Dayleez</button>'}
  else{title='No Dayleez from your Trackers';desc='When someone you track posts a Dayleez, it shows up here.';tips=tip('ri-user-add-line','Track more people to fill this up.')+tip('ri-compass-3-line','Browse Discover to find people to track.');btn='<button type="button" class="es-btn" onclick="switchTab(\'discover\')"><i class="ri-compass-3-line"></i> Open Discover</button>'}
  const e=$('emptyState');e.innerHTML='<div class="es-title">'+title+'</div><p class="es-text">'+desc+'</p><div class="es-tips">'+tips+'</div>'+btn;e.style.display='flex';
}
function clearFilters(){search='';$('searchInput').value='';$('searchClearBtn').classList.remove('show');selectCategory('All')}

function loadCards(){
  if(loading||!hasMore)return;loading=true;const my=token;
  skCards(offset===0?6:3);
  dlPost('dl_action=feed&tab='+tab+'&category='+encodeURIComponent(cat)+'&search='+encodeURIComponent(search)+'&offset='+offset+'&seed='+RAND_SEED).then(d=>{
    if(my!==token)return;clearSk();
    if(!d.success)throw 0;
    if(!d.users.length&&offset===0)showEmpty();
    else{let h='';d.users.forEach((u,i)=>{h+=cardHTML(u,i);cardUsers.push(u)});$('grid').insertAdjacentHTML('beforeend',h)}
    hasMore=!!d.has_more;offset+=d.users.length;loading=false;
  }).catch(()=>{if(my!==token)return;clearSk();loading=false;toast('Could not load Dayleez. Try again.')});
}
new IntersectionObserver(es=>{es.forEach(e=>{if(e.isIntersecting)loadCards()})},{rootMargin:'400px'}).observe($('scrollSentinel'));

/* tray */
function trayHTML(){
  const mine=trayUsers.find(u=>u.isYou);
  let h='';
  if(mine){
    h+='<button type="button" class="tr-item'+(mine.flags.every(f=>f)?' seen':'')+'" onclick="dlOpen('+ME+',trayIds())">'+ringHTML(mine.flags,64,3,mine.pic,'<span class="rg-plus" onclick="event.stopPropagation();dcOpen()"><svg viewBox="0 0 24 24" width="15.5" height="15.5" fill="none" stroke="#000" stroke-width="4.4" stroke-linecap="round" aria-hidden="true"><path d="M12 4.5v15M4.5 12h15"/></svg></span>')+'<span class="nm">Your Dayleez</span></button>';
  }else{
    h+='<button type="button" class="tr-item" onclick="dcOpen()"><span class="rg" style="width:64px;height:64px"><span class="rg-dash"></span><img src="'+esc(MY_PIC)+'" alt="" style="left:5px;top:5px;width:54px;height:54px;opacity:.85;border-radius:24%" onerror="this.onerror=null;this.src=\'uploads/me.png\'"><span class="rg-plus"><svg viewBox="0 0 24 24" width="15.5" height="15.5" fill="none" stroke="#000" stroke-width="4.4" stroke-linecap="round" aria-hidden="true"><path d="M12 4.5v15M4.5 12h15"/></svg></span></span><span class="nm">Your Dayleez</span></button>';
  }
  trayUsers.filter(u=>!u.isYou).forEach(u=>{h+='<button type="button" class="tr-item'+(u.flags.every(f=>f)?' seen':'')+'" onclick="dlOpen('+u.uid+',trayIds())">'+ringHTML(u.flags,64,3,u.pic)+'<span class="nm">'+esc(u.name)+'</span></button>'});
  $('tray').innerHTML=h;
}
const trayIds=()=>trayUsers.map(u=>u.uid);
function loadTray(){
  dlPost('dl_action=tray').then(d=>{if(d.success){trayUsers=d.users;trayHTML()}}).catch(()=>{});
}
$('tray').innerHTML='<div class="tr-sk"><i class="sk-shimmer"></i><b class="sk-shimmer"></b></div><div class="tr-sk"><i class="sk-shimmer"></i><b class="sk-shimmer"></b></div><div class="tr-sk"><i class="sk-shimmer"></i><b class="sk-shimmer"></b></div><div class="tr-sk"><i class="sk-shimmer"></i><b class="sk-shimmer"></b></div>';
/* called when the viewer closes so the rings update */
window.dlReload=function(){loadTray();resetAndLoad()};

/* open the viewer: a card opens inside the cards you can see, a tray item inside the tray */
function dlOpen(uid,ids){
  if(!ids||!ids.length){ids=cardUsers.map(u=>u.uid);if(ids.indexOf(uid)<0)ids=[uid]}
  window.openDayleez(uid,ids.slice(0,30));
}

/* ═══════════════════════════════════════════════════════════
   CREATE DAYLEEZ
═══════════════════════════════════════════════════════════ */
let dcItems=[],dcMode='whole',dcDays=1,dcCover=null,dcSong=null,dcBusy=false;

function dcOpen(){openSheet('sh-create');dcTab('new');dcExpiry()}
function dcClose(){
  if(dcBusy)return;
  if(dcTabNow==='new'&&dcItems.length&&!confirm('Close without posting? Your media will be lost.'))return;
  if(dcTabNow==='new')dcReset();
  closeSheet('sh-create');
  if(edChanged){edChanged=false;window.dlReload()}
}
function dcReset(){
  dcItems.forEach(i=>{if(i.url)URL.revokeObjectURL(i.url)});
  dcItems=[];dcCover=null;dcSong=null;dcMode='whole';dcDays=1;dcPickCat('');$('dc-place').value='';dcSetMode('whole');dcSetDays(1);dcRenderPick();dcRenderItems();dcRenderExtras();dcStatus('');
}
function openSheet(id){const e=$(id);e.classList.add('open');document.body.style.overflow='hidden';const b=e.querySelector('.sheet-body');if(b)b.scrollTop=0}
function closeSheet(id){
  if(id==='sh-clip'){cpClose();return}
  if(id==='sh-ht'){htClose();return}
  $(id).classList.remove('open');if(!document.querySelector('.sheet.open'))document.body.style.overflow='';
}
document.addEventListener('keydown',e=>{
  const hm=$('hlModal');if(e.key!=='Escape'||(hm&&hm.classList.contains('open')))return;
  const o=[...document.querySelectorAll('.sheet.open')];if(!o.length)return;
  const id=o[o.length-1].id;if(id==='sh-create')dcClose();else closeSheet(id);
});
function dcStatus(m,err){const s=$('dc-status');s.textContent=m||'';s.className='status'+(err?' err':'')}
function dcSetDays(d){
  dcDays=d;document.querySelectorAll('#dc-dur button').forEach(b=>b.classList.toggle('on',+b.dataset.d===d));dcExpiry();
}
function dcExpiry(){
  const e=new Date(Date.now()+dcDays*86400000);
  $('dc-exp').textContent='It will disappear on '+e.toLocaleDateString(undefined,{weekday:'short',month:'short',day:'numeric'})+' at '+e.toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'})+'.';
}
function dcFilter(list,max){
  let ok=[],skipped=0;
  list.forEach(f=>{const i=f.type.startsWith('image/'),v=f.type.startsWith('video/');if(!i&&!v){skipped++;return}if(f.size>(i?IMG_MB:VID_MB)*1048576){skipped++;return}ok.push(f)});
  let trimmed=false;if(ok.length>max){ok=ok.slice(0,max);trimmed=true}
  return{ok,skipped,trimmed};
}
const dcMake=files=>files.map(f=>({file:f,kind:f.type.startsWith('video/')?'video':'image',text:'',song:null,mute:false,place:'',ctf:'',ptf:'',url:URL.createObjectURL(f)}));

$('dc-files').addEventListener('change',function(){
  const texts=dcItems.filter(i=>i.kind==='text');
  const r=dcFilter(Array.from(this.files||[]),Math.max(0,DL_MAX-texts.length));
  let msg='';if(r.trimmed)msg='Only the first '+DL_MAX+' media were kept.';
  if(r.skipped)msg=(msg?msg+' ':'')+r.skipped+' file'+(r.skipped===1?' was':'s were')+' skipped (wrong type or too large).';
  dcItems.filter(i=>i.kind!=='text').forEach(i=>URL.revokeObjectURL(i.url));
  dcItems=dcMake(r.ok).concat(texts);dcRenderPick();dcRenderItems();dcStatus(msg,!!msg&&!dcItems.length);this.value='';
});
$('dc-cover-file').addEventListener('change',function(){
  const f=this.files&&this.files[0];this.value='';if(!f)return;
  if(!f.type.startsWith('image/')||f.size>IMG_MB*1048576){dcStatus('The cover must be an image under '+IMG_MB+' MB.',true);return}
  dcCover=f;dcRenderExtras();dcStatus('');
});
function dcRenderPick(){
  const prev=$('dc-pick-prev'),icon=$('dc-pick-icon');
  if(prev._url){URL.revokeObjectURL(prev._url);prev._url=null}
  if(!dcItems.length){$('dc-pick-title').textContent='Choose photos or videos';$('dc-pick-sub').textContent='Add 1 to '+DL_MAX+' per Dayleez.';prev.classList.remove('show');icon.style.display='';icon.className='ri-image-add-line';return}
  $('dc-pick-title').textContent=dcItems.length+' of '+DL_MAX+' selected';$('dc-pick-sub').textContent='Tap to change your photos and videos.';
  const f=dcItems[0];
  if(f.kind==='image'){prev._url=URL.createObjectURL(f.file);prev.src=prev._url;prev.classList.add('show');icon.style.display='none'}
  else{prev.classList.remove('show');icon.style.display='';icon.className=f.kind==='video'?'ri-video-line':'ri-text'}
}
function dcRenderExtras(){
  const p=$('dc-cv-prev'),ic=$('dc-cv-icon');if(p._url){URL.revokeObjectURL(p._url);p._url=null}
  if(dcCover){p._url=URL.createObjectURL(dcCover);p.src=p._url;p.classList.add('show');ic.style.display='none';$('dc-cv-title').textContent='Cover image added';$('dc-cv-sub').textContent=shortT(dcCover.name,34)}
  else{p.classList.remove('show');ic.style.display='';$('dc-cv-title').textContent='Cover image (optional)';$('dc-cv-sub').textContent='Skip it and your first media becomes the cover.'}
  $('dc-cv-x').classList.toggle('show',!!dcCover);
  $('dc-song-title').textContent=dcSong?'♪ '+shortT(dcSong.title||'Song clip',30):'Song clip (optional)';
  $('dc-song-sub').textContent=dcSong?Math.round(dcSong.secs)+' second clip. Tap to change it.':'Pick the part of a song you want, up to 30 seconds.';
  $('dc-song-x').classList.toggle('show',!!dcSong);
}
function dcCoverClear(){dcCover=null;dcRenderExtras()}
function dcSongClear(){dcSong=null;dcRenderExtras()}
function dcSongPick(){cpOpen(r=>{dcSong=r;dcRenderExtras()})}
function dcSetMode(m){
  dcMode=m;document.querySelectorAll('#dc-mode button').forEach(b=>b.classList.toggle('on',b.dataset.mode===m));
  $('dc-song-wrap').style.display=m==='whole'?'':'none';
  $('dc-mode-help').textContent=m==='whole'?'One song clip plays across every media in this Dayleez.':'Each media can have its own song clip. Choose your media, then tap Add song on the ones you want.';
  dcRenderItems();
}
function dcRenderItems(){
  const box=$('dc-items');
  if(!dcItems.length){box.innerHTML='';return}
  box.innerHTML='<p class="hint" style="margin:0 0 8px">Tap Add text to write words on a media (tag people with @name).'+(dcMode==='each'?' Tap Add song on any media that should have its own song.':'')+(dcItems.some(i=>i.kind==='video')?' Videos: Sound keeps the video\'s audio, Muted lets only the song play.':'')+'</p><div class="cr-rows">'+
  dcItems.map((it,i)=>{
    let a='<button type="button" class="mini'+(it.text?' on':'')+'" onclick="dcItemText('+i+')"><i class="ri-text"></i>'+(it.text?'Edit text':'Add text')+'</button>';
    a+='<button type="button" class="mini'+(it.place?' on':'')+'" onclick="dcItemPlace('+i+')"><i class="ri-map-pin-2-line"></i>'+(it.place?esc(shortT(it.place,14)):'Location')+'</button>';
    if(it.text||it.place||$('dc-place').value.trim())a+='<button type="button" class="mini'+((it.ctf||it.ptf)?' on':'')+'" onclick="arrOpenItem('+i+')"><i class="ri-drag-move-2-line"></i>Arrange</button>';
    if(it.kind!=='video')a+='<button type="button" class="mini" onclick="epOpen('+i+')"><i class="ri-pencil-line"></i>Draw &amp; stickers</button>';
    if(dcMode==='each'){
      a+='<button type="button" class="mini'+(it.song?' on':'')+'" onclick="dcItemSong('+i+')"><i class="ri-music-2-line"></i>'+(it.song?esc(shortT(it.song.title||'Song',12)):'Add song')+'</button>';
      if(it.song)a+='<button type="button" class="mini" aria-label="Remove song" onclick="dcItemSongRm('+i+')"><i class="ri-close-line"></i></button>';
    }
    if(it.kind==='video')a+='<button type="button" class="mini'+(it.mute?' on':'')+'" onclick="dcItemMute('+i+')"><i class="'+(it.mute?'ri-volume-mute-line':'ri-volume-up-line')+'"></i>'+(it.mute?'Muted':'Sound')+'</button>';
    const th=it.kind==='image'?'<img src="'+it.url+'" alt="">':'<i class="'+(it.kind==='video'?'ri-video-line':'ri-text')+'"></i>';
    const nm=it.kind==='text'?'Text: '+shortT(it.text.replace(/\s+/g,' '),24):shortT(it.file.name,28);
    return '<div class="cr-row"><div class="cr-th">'+th+'</div><div class="cr-inf"><strong>'+esc(nm)+'</strong><div class="acts">'+a+'</div></div><button type="button" class="cr-x show" aria-label="Remove" onclick="dcItemDel('+i+')"><i class="ri-close-line"></i></button></div>';
  }).join('')+'</div>';
}
function dcItemSong(i){cpOpen(r=>{if(dcItems[i]){dcItems[i].song=r;dcRenderItems()}})}
function dcItemSongRm(i){if(dcItems[i]){dcItems[i].song=null;dcRenderItems()}}
function dcItemMute(i){if(dcItems[i]){dcItems[i].mute=!dcItems[i].mute;dcRenderItems()}}
function dcItemDel(i){const it=dcItems[i];if(!it)return;if(it.url)URL.revokeObjectURL(it.url);dcItems.splice(i,1);dcRenderPick();dcRenderItems()}
function dcItemText(i){
  const it=dcItems[i];if(!it)return;
  htOpen({src:it.url,type:it.kind,text:it.text,required:it.kind==='text',allowRemove:it.kind!=='text'&&!!it.text},t=>{if(dcItems[i]){dcItems[i].text=t;dcRenderPick();dcRenderItems()}});
}
function dcAddText(){
  if(dcItems.length>=DL_MAX){toast('A Dayleez can hold up to '+DL_MAX+' media.');return}
  htOpen({src:null,type:'text',text:'',required:true,allowRemove:false},t=>{dcItems.push({file:null,kind:'text',text:t,song:null,mute:false,place:'',ctf:'',ptf:'',url:null});dcRenderPick();dcRenderItems()});
}

async function dcUpload(){
  if(dcBusy)return;
  if(!dcItems.length){dcStatus('Choose at least one photo or video, or add a text Dayleez.',true);return}
  dcBusy=true;$('dc-post').disabled=true;dcStatus('');
  const useSong=dcMode==='whole'&&dcSong,items=dcItems.slice();
  const steps=items.length+(dcCover?1:0)+(useSong?1:0);let stepNo=0,done=0,failed=0,lastErr='',extraErr='',bid=0;
  const prog=f=>PG.set(Math.min(97,(stepNo+f)/steps*100));
  PG.show('Posting your Dayleez…');
  try{
    const base={days:dcDays,mode:dcMode,category:$('dc-cat').value};
    for(let i=0;i<items.length;i++){
      const it=items[i];PG.set((stepNo/steps)*100,'Uploading '+(i+1)+' of '+items.length+'…');
      try{
        const f=Object.assign({batch_id:bid},base);f.place=(it.place||$('dc-place').value||'').trim();f.cap_tf=it.ctf||'';f.place_tf=it.ptf||'';
        let r;
        if(it.kind==='text'){f.text=it.text;r=await dlCall('add_text',f,null,prog)}
        else{f.caption=it.text||'';f.mute=it.mute?1:0;r=await dlCall('add_media',f,{media:it.file},prog)}
        bid=r.batch_id;done++;
        if(dcMode==='each'&&it.song){try{await dlCall('media_song',{story_id:r.id,title:it.song.title||''},{song:it.song.blob})}catch(e){extraErr='Some songs did not upload ('+e.message+')'}}
      }catch(e){failed++;lastErr=e.message;if(!bid&&i===0&&/live at once/.test(e.message))break}
      stepNo++;
    }
    if(done===0)throw new Error(lastErr||'No files could be uploaded.');
    if(dcCover){PG.set((stepNo/steps)*100,'Uploading cover…');try{await dlCall('set_cover',{batch_id:bid},{cover:dcCover})}catch(e){extraErr+=(extraErr?' ':'')+'Cover: '+e.message}stepNo++}
    if(useSong){PG.set((stepNo/steps)*100,'Uploading song clip…');try{await dlCall('set_song',{batch_id:bid,title:dcSong.title||''},{song:dcSong.blob})}catch(e){extraErr+=(extraErr?' ':'')+'Song: '+e.message}stepNo++}
    PG.done('Dayleez posted',extraErr||(failed?failed+' item'+(failed===1?'':'s')+' failed to upload.':''));
    setTimeout(()=>{PG.hide();dcBusy=false;$('dc-post').disabled=false;dcReset();closeSheet('sh-create');if(tab!=='mine'&&tab!=='following')switchTab('following');window.dlReload()},extraErr?1800:1000);
  }catch(e){PG.fail(e.message||'Upload failed.');dcBusy=false;$('dc-post').disabled=false}
}

/* ── text sheet ── */
const HT={cb:null,o:null,timer:null,seq:0,map:null};
function htOpen(o,cb){
  HT.cb=cb;HT.o=o;HT.map=null;$('ht-input').value=o.text||'';$('ht-status').textContent='';$('ht-status').className='status';
  $('ht-remove').style.display=o.allowRemove?'':'none';
  let bg;
  if(o.type==='image'&&o.src)bg='<img src="'+esc(o.src)+'" alt="">';
  else if(o.type==='video'&&o.src)bg='<video src="'+esc(o.src)+'" muted playsinline loop autoplay></video>';
  else bg='<img src="'+esc(DL_TEXT_BG)+'" alt="">';
  $('ht-preview').innerHTML='<div class="ht-view">'+bg+'<div id="ht-capbox"></div></div>';
  openSheet('sh-ht');htUpdate(true);setTimeout(()=>$('ht-input').focus({preventScroll:true}),80);
}
function htRender(t){const b=$('ht-capbox');if(!b)return;b.innerHTML=t.trim()?dlCapHTML(t,HT.map):'<div class="hl-cap-wrap"><div class="hl-cap-box" style="left:50%;top:50%;transform:translate(-50%,-50%)"><div class="hl-cap cap-empty">Your text appears here</div></div></div>'}
function htUpdate(now){
  const t=$('ht-input').value;$('ht-count').textContent=t.length+'/1000';htRender(t);clearTimeout(HT.timer);
  HT.timer=setTimeout(async()=>{
    const s=++HT.seq;const m=await dlResolve(t);if(s!==HT.seq)return;
    HT.map=m;htRender($('ht-input').value);
    const bad=mentionBad(m,HT.o&&HT.o.text),st=$('ht-status');
    if(bad.length){st.textContent=bad[0].msg;st.className='status err'}
    else if(st.className.indexOf('err')>-1&&st.textContent.indexOf('@')===0){st.textContent='';st.className='status'}
  },now?0:350);
}
async function htDone(){
  const t=$('ht-input').value.trim(),st=$('ht-status');
  if(HT.o&&HT.o.required&&!t){st.textContent='Write something first.';st.className='status err';return}
  const bad=mentionBad(await dlResolve(t),HT.o&&HT.o.text);
  if(bad.length){st.textContent=bad[0].msg;st.className='status err';return}
  const cb=HT.cb;htClose();if(cb)cb(t);
}
function htRemove(){const cb=HT.cb;htClose();if(cb)cb('')}
function htClose(){clearTimeout(HT.timer);HT.seq++;HT.cb=null;$('sh-ht').classList.remove('open');$('ht-preview').innerHTML='';if(!document.querySelector('.sheet.open'))document.body.style.overflow=''}

/* ── song clip picker ── */
const CP={file:null,url:null,dur:0,cb:null,audio:null,timer:null};
const cpFmt=t=>{t=Math.max(0,t);return Math.floor(t/60)+':'+String(Math.floor(t%60)).padStart(2,'0')};
const cpLen=()=>Math.min(+$('cp-len').value,CP.dur||0);
function cpOpen(cb){CP.cb=cb;cpReset();openSheet('sh-clip')}
function cpReset(){
  cpStop();if(CP.url)URL.revokeObjectURL(CP.url);CP.file=null;CP.url=null;CP.dur=0;CP.audio=null;
  $('cp-editor').style.display='none';$('cp-file-title').textContent='Choose a song';$('cp-title').value='';cpStatus('');$('cp-use').disabled=false;$('cp-use').textContent='Use this clip';
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

/* ═══════════════════════════════════════════════════════════
   START
═══════════════════════════════════════════════════════════ */
/* ═══════════════════════════════════════════════════════════
   CREATE: category picker, location, draw + stickers
═══════════════════════════════════════════════════════════ */
function dcBuildCats(){
  $('dc-catpick').innerHTML=DL_CATS.map(c=>'<button type="button" class="chip" data-c="'+esc(c[0])+'" onclick="dcPickCat(this.dataset.c)"><i class="'+c[1]+'"></i>'+esc(c[0])+'</button>').join('');
}
function dcPickCat(n){
  const v=(n===$('dc-cat').value)?'':n;$('dc-cat').value=v;
  document.querySelectorAll('#dc-catpick .chip').forEach(b=>b.classList.toggle('active',b.dataset.c===v));
}
const LOC={cb:null,val:'',timer:null,seq:0,rows:[],q:''};
function cutAtComma(t,max){t=String(t||'').trim();if(t.length<=max)return t;const c=t.lastIndexOf(',',max);return(c>0?t.slice(0,c):t.slice(0,max)).trim()}
function locTag(){
  const w=$('loc-tagwrap');
  if(!LOC.val){w.style.display='none';w.innerHTML='';return}
  w.style.display='block';w.innerHTML='<span class="loc-tag"><i class="ri-map-pin-2-fill"></i><span>'+esc(LOC.val)+'</span><button type="button" aria-label="Remove location" onclick="locUnset()">✕</button></span>';
}
function locHint(m){$('loc-list').innerHTML='<div class="loc-msg">'+esc(m)+'</div>'}
function locOpen(v,cb){
  LOC.cb=cb;LOC.val=v||'';LOC.seq++;clearTimeout(LOC.timer);$('loc-input').value='';locTag();locHint('Type at least 3 letters to search.');
  openSheet('sh-loc');setTimeout(()=>$('loc-input').focus({preventScroll:true}),80);
}
function locUnset(){LOC.val='';locTag()}
/* OpenStreetMap search (Nominatim), straight from the browser: 3+ letters, 0.45s pause, up to 8 places, old answers ignored */
function locInput(){
  clearTimeout(LOC.timer);const q=$('loc-input').value.trim(),seq=++LOC.seq;LOC.q=q;
  if(q.length<3){locHint('Type at least 3 letters to search.');return}
  LOC.timer=setTimeout(()=>{
    locHint('Searching…');
    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=8&q='+encodeURIComponent(q)).then(r=>{if(!r.ok)throw 0;return r.json()}).then(rows=>{
      if(seq!==LOC.seq)return;
      LOC.rows=(rows||[]).filter(r=>r&&r.display_name);
      let h=LOC.rows.map((r,i)=>'<button type="button" class="loc-row" data-i="'+i+'"><i class="ri-map-pin-2-line"></i><span>'+esc(r.display_name)+'</span></button>').join('');
      if(!h)h='<div class="loc-msg">No matching places found</div>';
      h+='<button type="button" class="loc-row" data-custom="1"><i class="ri-edit-line"></i><span>Use “'+esc(q)+'”</span></button>';
      $('loc-list').innerHTML=h;
    }).catch(()=>{if(seq!==LOC.seq)return;locHint('Search failed — try again')});
  },450);
}
$('loc-list').addEventListener('click',e=>{
  const b=e.target.closest('.loc-row');if(!b)return;
  const name=b.dataset.custom?LOC.q:(LOC.rows[+b.dataset.i]||{}).display_name;if(!name)return;
  LOC.val=cutAtComma(name,100);LOC.seq++;clearTimeout(LOC.timer);$('loc-input').value='';locTag();locHint('Type at least 3 letters to search.');
});
function locClose(){clearTimeout(LOC.timer);LOC.seq++;LOC.cb=null;$('sh-loc').classList.remove('open');if(!document.querySelector('.sheet.open'))document.body.style.overflow=''}
function locDone(){const cb=LOC.cb,v=LOC.val;locClose();if(cb)cb(v)}
function locClear(){const cb=LOC.cb;locClose();if(cb)cb('')}
function dcPickPlace(){locOpen($('dc-place').value,p=>{$('dc-place').value=p;dcRenderItems()})}
function dcItemPlace(i){const it=dcItems[i];if(!it)return;locOpen(it.place,p=>{if(dcItems[i]){dcItems[i].place=p;dcRenderItems()}})}

const EP={fc:null,idx:-1,color:'#FF2D2D',size:6,nat:{w:1,h:1},cw:1};
const EP_COLORS=['#FF2D2D','#ffffff','#000000','#FFD400','#2DE2A6','#3EA6FF','#B26BFF','#FF7AC8'];
const EP_STICKERS=['😂','🔥','😍','💀','👀','😭','💯','🎉','❤️','✨','😎','🙌','🍕','☕','🎶','📍','🌴','🌙','⭐','💥','🫶','😴','🤝','🚀','👑','🍿'];
function epBuildUI(){
  $('ep-colors').innerHTML=EP_COLORS.map(c=>'<button type="button" class="ep-sw'+(c===EP.color?' on':'')+'" style="background:'+c+'" data-c="'+c+'" aria-label="Colour '+c+'" onclick="epColor(this.dataset.c)"></button>').join('');
  $('ep-stickers').innerHTML=EP_STICKERS.map(e=>'<button type="button" class="ep-st" onclick="epSticker(this.textContent)">'+e+'</button>').join('');
}
function epOpen(i){
  const it=dcItems[i];if(!it||it.kind==='video')return;
  if(typeof fabric==='undefined'){toast('The editor is still loading. Try again in a second.');return}
  EP.idx=i;openSheet('sh-paint');
  const src=it.kind==='text'?DL_TEXT_BG:it.url,img=new Image();
  img.onload=()=>{
    const wrap=$('ep-wrap'),maxW=Math.min(wrap.clientWidth||420,460),maxH=Math.round(window.innerHeight*.56);
    const sc=Math.min(maxW/img.naturalWidth,maxH/img.naturalHeight),w=Math.round(img.naturalWidth*sc),h=Math.round(img.naturalHeight*sc);
    EP.nat={w:img.naturalWidth,h:img.naturalHeight};EP.cw=w;
    if(EP.fc){try{EP.fc.dispose()}catch(e){}EP.fc=null}
    wrap.innerHTML='<canvas id="ep-canvas"></canvas>';
    const fc=new fabric.Canvas('ep-canvas',{width:w,height:h,isDrawingMode:true,selection:true,preserveObjectStacking:true});
    fabric.Image.fromURL(src,im=>{im.set({scaleX:w/im.width,scaleY:h/im.height,selectable:false,evented:false});fc.setBackgroundImage(im,fc.renderAll.bind(fc))});
    fc.freeDrawingBrush=new fabric.PencilBrush(fc);fc.freeDrawingBrush.color=EP.color;fc.freeDrawingBrush.width=EP.size;
    EP.fc=fc;epMode('draw');
  };
  img.onerror=()=>{toast('Could not open this photo');closeSheet('sh-paint')};
  img.src=src;
}
function epMode(m){
  if(!EP.fc)return;EP.fc.isDrawingMode=(m==='draw');
  if(m==='draw'){EP.fc.discardActiveObject();EP.fc.requestRenderAll()}
  $('ep-b-draw').classList.toggle('on',m==='draw');$('ep-b-move').classList.toggle('on',m!=='draw');
}
function epColor(c){EP.color=c;if(EP.fc)EP.fc.freeDrawingBrush.color=c;document.querySelectorAll('#ep-colors .ep-sw').forEach(b=>b.classList.toggle('on',b.dataset.c===c))}
function epSize(v){EP.size=+v;if(EP.fc)EP.fc.freeDrawingBrush.width=EP.size}
function epSticker(e){
  if(!EP.fc)return;
  const t=new fabric.Text(e,{left:EP.cw/2,top:EP.fc.getHeight()/2,originX:'center',originY:'center',fontSize:Math.round(EP.cw*.22)});
  EP.fc.add(t);epMode('move');EP.fc.setActiveObject(t);EP.fc.requestRenderAll();
}
function epUndo(){if(!EP.fc)return;const o=EP.fc.getObjects();if(o.length){EP.fc.remove(o[o.length-1]);EP.fc.requestRenderAll()}}
function epDelSel(){if(!EP.fc)return;EP.fc.getActiveObjects().forEach(o=>EP.fc.remove(o));EP.fc.discardActiveObject();EP.fc.requestRenderAll()}
function epClose(){if(EP.fc){try{EP.fc.dispose()}catch(e){}EP.fc=null}$('ep-wrap').innerHTML='';closeSheet('sh-paint')}
async function epDone(){
  const fc=EP.fc,it=dcItems[EP.idx];if(!fc||!it){epClose();return}
  const btn=$('ep-done');btn.disabled=true;
  try{
    fc.discardActiveObject();fc.renderAll();
    const mult=Math.max(1,Math.min(EP.nat.w/EP.cw,2000/Math.max(EP.cw,fc.getHeight())));
    const blob=await (await fetch(fc.toDataURL({format:'jpeg',quality:.9,multiplier:mult}))).blob();
    if(blob.size>IMG_MB*1048576){toast('That picture is too big. Try fewer drawings.');btn.disabled=false;return}
    const file=new File([blob],'dayleez_'+Date.now()+'.jpg',{type:'image/jpeg'});
    if(it.url)URL.revokeObjectURL(it.url);
    it.file=file;it.url=URL.createObjectURL(file);it.kind='image';
    dcRenderPick();dcRenderItems();epClose();
  }catch(e){toast('Could not save your drawing')}
  btn.disabled=false;
}

/* ═══════════════════════════════════════════════════════════
   EDIT MY LIVE DAYLEEZ (every change saves right away)
═══════════════════════════════════════════════════════════ */
let dcTabNow='new',edItems=[],edChanged=false;
function dcTab(t){
  dcTabNow=t;document.querySelectorAll('#dc-tabs button').forEach(b=>b.classList.toggle('on',b.dataset.t===t));
  $('dc-pane-new').style.display=t==='new'?'':'none';$('dc-pane-edit').style.display=t==='edit'?'':'none';
  $('dc-foot').style.display=t==='new'?'':'none';$('dc-title').textContent=t==='new'?'Create Dayleez':'Edit my Dayleez';
  if(t==='edit')edLoad();
}
async function edLoad(){
  const box=$('ed-list');
  if(!edItems.length)box.innerHTML='<div class="sk-card sk-shimmer" style="aspect-ratio:auto;height:96px"></div>';
  try{edItems=(await dlCall('mine',{})).items}catch(e){box.innerHTML='<p class="hint">Could not load your Dayleez. '+esc(e.message)+'</p>';return}
  edRender();
}
const edFind=id=>edItems.find(x=>x.id===id);
function edRender(){
  const box=$('ed-list');
  if(!edItems.length){box.innerHTML='<div class="empty-state" style="display:flex;min-height:30vh"><div class="es-title">Nothing live right now</div><p class="es-text">When you post a Dayleez it shows up here until it expires, so you can change or remove it.</p><button type="button" class="es-btn" onclick="dcTab(\'new\')"><i class="ri-add-line"></i> Create Dayleez</button></div>';return}
  const groups=[],idx={};
  edItems.forEach(it=>{if(idx[it.batch]===undefined){idx[it.batch]=groups.length;groups.push([])}groups[idx[it.batch]].push(it)});
  box.innerHTML=groups.map(g=>{
    const f=g[0],b=f.batch,soon=Math.min.apply(null,g.map(x=>x.left)),allOff=g.every(x=>x.status==='removed');
    let h='<div class="ed-card"><div class="ed-head"><b>Posted '+ageLabel(f.age)+'</b><span>'+leftLabel(soon)+'</span></div>';
    h+='<div class="dur ed-dur">'+[1,2,3].map(d=>'<button type="button" class="'+(f.days===d?'on':'')+'" onclick="edDays('+b+','+d+')"><b>'+d+(d===1?' day':' days')+'</b></button>').join('')+'</div>';
    h+='<div class="acts">'+
      '<button type="button" class="mini" onclick="edPlaceAll('+b+')"><i class="ri-map-pin-2-line"></i>Location for all</button>'+
      '<button type="button" class="mini" onclick="edStatusAll('+b+',\''+(allOff?'active':'removed')+'\')"><i class="'+(allOff?'ri-eye-line':'ri-eye-off-line')+'"></i>'+(allOff?'Restore all':'Remove all')+'</button>'+
      (f.mode==='whole'?'<button type="button" class="mini'+(f.song?' on':'')+'" onclick="edSongAll('+b+')"><i class="ri-music-2-line"></i>'+(f.song?esc(shortT(f.songTitle||'Song',14)):'Add song')+'</button>'+(f.song?'<button type="button" class="mini" aria-label="Remove song" onclick="edSongAllRm('+b+')"><i class="ri-close-line"></i></button>':''):'')+
      '<button type="button" class="mini danger" onclick="edDelAll('+b+')"><i class="ri-delete-bin-line"></i>Delete all</button></div>';
    h+=g.map(it=>{
      const off=it.status==='removed';
      const th=it.type==='video'?'<video src="'+esc(it.path)+'#t=0.1" muted playsinline preload="metadata"></video>':(it.type==='text'?'Aa':'<img src="'+esc(it.path)+'" alt="" loading="lazy">');
      let a='<button type="button" class="mini'+(it.caption?' on':'')+'" onclick="edText('+it.id+')"><i class="ri-text"></i>'+(it.caption?'Edit text':'Add text')+'</button>'+
        '<button type="button" class="mini'+(it.place?' on':'')+'" onclick="edPlace('+it.id+')"><i class="ri-map-pin-2-line"></i>'+(it.place?esc(shortT(it.place,14)):'Location')+'</button>';
      if(it.caption||it.place)a+='<button type="button" class="mini'+((it.ctf||it.ptf)?' on':'')+'" onclick="edArrange('+it.id+')"><i class="ri-drag-move-2-line"></i>Arrange</button>';
      if(it.type==='video')a+='<button type="button" class="mini'+(it.mute?' on':'')+'" onclick="edMute('+it.id+','+(it.mute?0:1)+')"><i class="'+(it.mute?'ri-volume-mute-line':'ri-volume-up-line')+'"></i>'+(it.mute?'Muted':'Sound')+'</button>';
      if(it.mode==='each'){a+='<button type="button" class="mini'+(it.audio?' on':'')+'" onclick="edSong('+it.id+')"><i class="ri-music-2-line"></i>'+(it.audio?esc(shortT(it.audioTitle||'Song',10)):'Add song')+'</button>';if(it.audio)a+='<button type="button" class="mini" aria-label="Remove song" onclick="edSongRm('+it.id+')"><i class="ri-close-line"></i></button>'}
      a+='<button type="button" class="mini" onclick="edStatus('+it.id+',\''+(off?'active':'removed')+'\')"><i class="'+(off?'ri-eye-line':'ri-eye-off-line')+'"></i>'+(off?'Restore':'Remove')+'</button>'+
         '<button type="button" class="mini danger" onclick="edDel('+it.id+')"><i class="ri-delete-bin-line"></i>Delete</button>';
      return '<div class="ed-row'+(off?' off':'')+'"><div class="ed-th">'+th+'</div><div class="cr-inf"><strong>'+esc(it.caption?shortT(it.caption.replace(/\s+/g,' '),40):(it.type==='video'?'Video':'Photo'))+(off?'<span class="ed-badge">Hidden</span>':'')+'</strong><div class="ed-meta">'+fmtNumShort(it.views)+' views · '+fmtNumShort(it.likes)+' likes'+(it.place?' · '+esc(it.place):'')+'</div><div class="acts">'+a+'</div></div></div>';
    }).join('');
    return h+'</div>';
  }).join('');
}
const fmtNumShort=n=>{n=+n||0;return n>=1e3?(n/1e3).toFixed(1).replace(/\.0$/,'')+'K':n};
async function edRun(fn,msg){try{await fn();edChanged=true;if(msg)toast(msg);await edLoad()}catch(e){toast(e.message||'Something went wrong')}}
function edDays(b,d){edRun(()=>dlCall('set_days',{batch_id:b,days:d}),'Time updated')}
function edStatus(id,s){edRun(()=>dlCall('set_status',{story_id:id,status:s}),s==='removed'?'Removed. Restore it any time before it expires.':'Restored')}
function edStatusAll(b,s){edRun(()=>dlCall('set_status',{batch_id:b,status:s}),s==='removed'?'Removed. Restore it any time before it expires.':'Restored')}
function edDel(id){if(!confirm('Delete this media for good? This cannot be undone.'))return;edRun(()=>dlCall('del',{story_id:id}),'Deleted')}
function edDelAll(b){if(!confirm('Delete this whole Dayleez for good? This cannot be undone.'))return;edRun(()=>dlCall('del_batch',{batch_id:b}),'Deleted')}
function edMute(id,m){edRun(()=>dlCall('set_mute',{story_id:id,mute:m}))}
function edText(id){const it=edFind(id);if(!it)return;htOpen({src:it.path,type:it.type,text:it.caption||'',required:it.type==='text',allowRemove:it.type!=='text'&&!!it.caption},t=>edRun(()=>dlCall('set_caption',{story_id:id,text:t}),'Saved'))}
function edPlace(id){const it=edFind(id);if(!it)return;locOpen(it.place||'',p=>edRun(()=>dlCall('set_place',{story_id:id,place:p}),'Saved'))}
function edPlaceAll(b){locOpen('',p=>edRun(()=>dlCall('set_place',{batch_id:b,place:p}),p?'Location added to all':'Location removed from all'))}
function edSong(id){cpOpen(r=>edRun(()=>dlCall('media_song',{story_id:id,title:r.title||''},{song:r.blob}),'Song saved'))}
function edSongRm(id){edRun(()=>dlCall('media_song_rm',{story_id:id}),'Song removed')}
function edSongAll(b){cpOpen(r=>edRun(()=>dlCall('set_song',{batch_id:b,title:r.title||''},{song:r.blob}),'Song saved'))}
function edSongAllRm(b){edRun(()=>dlCall('rm_song',{batch_id:b}),'Song removed')}

/* ═══════════════════════════════════════════════════════════
   ARRANGE: move, resize and rotate the words and the location
═══════════════════════════════════════════════════════════ */
const ARR={cb:null,o:null,tf:{cap:null,place:null},has:{cap:false,place:false},sel:'cap',ptrs:new Map(),base:null};
const arrClamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const arrNorm=a=>{a=((a+180)%360+360)%360-180;return a};
function arrOpen(o,cb){
  ARR.cb=cb;ARR.o=o;ARR.has={cap:!!o.text,place:!!o.place};ARR.ptrs.clear();ARR.base=null;
  ARR.tf={cap:dlTf(o.ctf,Object.assign({},TF_CAP)),place:dlTf(o.ptf,Object.assign({},TF_PLACE))};
  let bg='';
  if(o.type==='image'&&o.src)bg='<img src="'+esc(o.src)+'" alt="">';
  else if(o.type==='video'&&o.src)bg='<video src="'+esc(o.src)+'#t=0.1" muted playsinline preload="metadata"></video>';
  else bg='<img src="'+esc(DL_TEXT_BG)+'" alt="">';
  $('arr-bg').innerHTML=bg;
  document.querySelectorAll('#arr-seg button').forEach(b=>b.style.display=ARR.has[b.dataset.k]?'':'none');
  ARR.sel=ARR.has.cap?'cap':'place';
  arrRender();openSheet('sh-arr');
}
function arrRender(){
  let h='';
  if(ARR.has.cap)h+='<div class="hl-cap-box arr-el" data-k="cap" style="'+tfCss(ARR.tf.cap)+'"><div class="hl-cap">'+dlRich(ARR.o.text,resolveCache[String(ARR.o.text).trim()]||{})+'</div></div>';
  if(ARR.has.place)h+='<div class="hl-place-box arr-el" data-k="place" style="'+tfCss(ARR.tf.place)+'"><div class="hl-pl"><i class="ri-map-pin-2-fill"></i>'+esc(ARR.o.place)+'</div></div>';
  $('arr-layer').innerHTML=h;arrSelect(ARR.sel);
}
function arrSelect(k){
  if(!ARR.has[k])return;ARR.sel=k;
  document.querySelectorAll('#arr-layer .arr-el').forEach(e=>e.classList.toggle('sel',e.dataset.k===k));
  document.querySelectorAll('#arr-seg button').forEach(b=>b.classList.toggle('on',b.dataset.k===k));
  arrSync();
}
function arrSync(){
  const t=ARR.tf[ARR.sel];if(!t)return;
  $('arr-size').value=Math.round(t.s*100);$('arr-size-v').textContent=Math.round(t.s*100)+'%';
  $('arr-rot').value=Math.round(t.r);$('arr-rot-v').textContent=Math.round(t.r)+'°';
}
function arrApply(){
  const el=$('arr-layer').querySelector('[data-k="'+ARR.sel+'"]'),t=ARR.tf[ARR.sel];
  if(el&&t)el.style.cssText=tfCss(t);arrSync();
}
function arrSlider(){
  const t=ARR.tf[ARR.sel];if(!t)return;
  t.s=arrClamp(+$('arr-size').value/100,.4,4);t.r=+$('arr-rot').value;arrApply();
}
function arrReset(){ARR.tf[ARR.sel]=Object.assign({},ARR.sel==='cap'?TF_CAP:TF_PLACE);arrApply()}
function arrBase(){
  const t=ARR.tf[ARR.sel],pts=[...ARR.ptrs.values()];
  if(!t||!pts.length){ARR.base=null;return}
  const b={x:t.x,y:t.y,s:t.s,r:t.r};
  if(pts.length>=2){b.d=Math.hypot(pts[0].x-pts[1].x,pts[0].y-pts[1].y)||1;b.a=Math.atan2(pts[1].y-pts[0].y,pts[1].x-pts[0].x)*180/Math.PI;b.px=(pts[0].x+pts[1].x)/2;b.py=(pts[0].y+pts[1].y)/2}
  else{b.px=pts[0].x;b.py=pts[0].y}
  ARR.base=b;
}
(function(){
  const st=$('arr-stage');
  st.addEventListener('pointerdown',e=>{
    const el=e.target.closest('.arr-el');if(!el)return;
    arrSelect(el.dataset.k);try{st.setPointerCapture(e.pointerId)}catch(_){}
    ARR.ptrs.set(e.pointerId,{x:e.clientX,y:e.clientY});arrBase();e.preventDefault();
  });
  st.addEventListener('pointermove',e=>{
    if(!ARR.ptrs.has(e.pointerId)||!ARR.base)return;
    ARR.ptrs.set(e.pointerId,{x:e.clientX,y:e.clientY});
    const t=ARR.tf[ARR.sel],b=ARR.base,pts=[...ARR.ptrs.values()],r=st.getBoundingClientRect();
    if(pts.length>=2&&b.d){
      const d=Math.hypot(pts[0].x-pts[1].x,pts[0].y-pts[1].y),a=Math.atan2(pts[1].y-pts[0].y,pts[1].x-pts[0].x)*180/Math.PI;
      const mx=(pts[0].x+pts[1].x)/2,my=(pts[0].y+pts[1].y)/2;
      t.s=arrClamp(b.s*d/b.d,.4,4);t.r=arrNorm(b.r+(a-b.a));
      t.x=arrClamp(b.x+(mx-b.px)/r.width*100,3,97);t.y=arrClamp(b.y+(my-b.py)/r.height*100,3,97);
    }else if(pts.length===1&&!b.d){
      t.x=arrClamp(b.x+(pts[0].x-b.px)/r.width*100,3,97);t.y=arrClamp(b.y+(pts[0].y-b.py)/r.height*100,3,97);
    }
    arrApply();
  });
  const up=e=>{ARR.ptrs.delete(e.pointerId);arrBase()};
  st.addEventListener('pointerup',up);st.addEventListener('pointercancel',up);
  st.addEventListener('wheel',e=>{
    const t=ARR.tf[ARR.sel];if(!t)return;e.preventDefault();
    if(e.shiftKey)t.r=arrNorm(t.r+(e.deltaY>0?5:-5));else t.s=arrClamp(t.s*(e.deltaY>0?.95:1.05),.4,4);
    arrApply();
  },{passive:false});
})();
function arrClose(){ARR.cb=null;ARR.ptrs.clear();$('sh-arr').classList.remove('open');$('arr-layer').innerHTML='';$('arr-bg').innerHTML='';if(!document.querySelector('.sheet.open'))document.body.style.overflow=''}
function arrDone(){
  const cb=ARR.cb,r={ctf:ARR.has.cap?tfStr(ARR.tf.cap):'',ptf:ARR.has.place?tfStr(ARR.tf.place):''};
  arrClose();if(cb)cb(r);
}
function arrOpenItem(i){
  const it=dcItems[i];if(!it)return;
  arrOpen({type:it.kind,src:it.url,text:it.text,place:it.place||$('dc-place').value.trim(),ctf:it.ctf,ptf:it.ptf},r=>{if(dcItems[i]){dcItems[i].ctf=r.ctf;dcItems[i].ptf=r.ptf;dcRenderItems()}});
}
function edArrange(id){
  const it=edFind(id);if(!it)return;
  arrOpen({type:it.type,src:it.path,text:it.caption,place:it.place,ctf:it.ctf,ptf:it.ptf},r=>edRun(()=>dlCall('set_tf',{story_id:id,cap_tf:r.ctf,place_tf:r.ptf}),'Saved'));
}

dcBuildCats();epBuildUI();
loadTray();
loadCards();
if(OPEN_U>0)setTimeout(function(){window.openDayleez(OPEN_U,[OPEN_U])},500);
</script>

<?php /* the viewer (modal people watch Dayleez in) lives in its own file. It must stay AFTER the script above. */ include 'dayleez_modal.php'; ?>
</body>
</html>