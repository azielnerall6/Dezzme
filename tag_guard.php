<?php
/* ═══════════════════════════════════════════════════════════
   TAG GUARD — one place that enforces the "Tags and mentions"
   choices saved by settings.php (table user_privacy):
     tag_who      everyone | tracked | none
     mention_who  everyone | tracked | none
     tag_approve  1 = a tag stays 'pending' until the person accepts
     mention_feature + privacy level = can a meme that mentions them be featured

   Two ways to use this file:
   1) require_once 'tag_guard.php';   from group.php (or any page): only the functions load.
   2) The group post modal calls this file directly with fetch():
        tag_action=search          -> people you may tag (greyed out when not allowed)
        tag_action=mention_check   -> @mentions in a text that are not allowed
   Same rules as upload.php, so both pages always agree.
═══════════════════════════════════════════════════════════ */
$tg_direct = isset($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME']);
if ($tg_direct) {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ob_start();                                   /* catch any stray output (notices, whitespace) from config.php */
    if (session_status() === PHP_SESSION_NONE) session_start();
    include_once __DIR__ . '/config.php';
}

if (!function_exists('pv_val')) {

function tag_ensure_schema($conn){
    if(!empty($_SESSION['tag_schema_v2'])) return;
    try{
        $conn->query("CREATE TABLE IF NOT EXISTS meme_tags (id INT AUTO_INCREMENT PRIMARY KEY, meme_id INT NOT NULL, tagged_user_id INT NOT NULL, tagged_by INT NOT NULL, status ENUM('pending','approved') NOT NULL DEFAULT 'approved', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_tag (meme_id, tagged_user_id), INDEX (tagged_user_id, status))");
        $r=$conn->query("SHOW COLUMNS FROM `memes` LIKE 'feature_ok'");
        if($r&&$r->num_rows===0) $conn->query("ALTER TABLE `memes` ADD COLUMN `feature_ok` TINYINT(1) NOT NULL DEFAULT 1");
        $_SESSION['tag_schema_v2']=1;
    }catch(Throwable $e){}
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
// mentions in $text that the person does not allow. Mentions already in $old are skipped.
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
// FEATURING (set in Settings > Tags and mentions > Reach): a meme that @mentions someone can only be
// featured if they allow it AND their level is Public or Limited. Nothing is blocked, we only save the result.
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

// ── SAVE HELPERS: call these right after the post (meme) row was inserted ──
// tags are saved as 'pending' for people who turned on "Manually approve tags"
function pv_save_tags($conn,$meme_id,$ids,$actor){
    if(empty($ids)||!$meme_id) return;
    tag_ensure_schema($conn);
    try{
        $ts=$conn->prepare("INSERT IGNORE INTO meme_tags (meme_id, tagged_user_id, tagged_by, status) VALUES (?,?,?,?)");
        foreach($ids as $tid){
            $tid=(int)$tid; $stt=pv_tag_status($conn,$tid);
            $ts->bind_param("iiis",$meme_id,$tid,$actor,$stt); $ts->execute();
        }
    }catch(Throwable $e){}
}
// saves memes.feature_ok (1 = every @mentioned person allows featuring, or nobody is mentioned)
function pv_save_feature_ok($conn,$meme_id,$text,$actor){
    if(!$meme_id) return;
    tag_ensure_schema($conn);
    $ok=count(pv_feature_issues($conn,$text,$actor))===0?1:0;
    try{
        $f=$conn->prepare("UPDATE memes SET feature_ok=? WHERE id=? AND uploaded_by=?");
        $f->bind_param("iii",$ok,$meme_id,$actor); $f->execute(); $f->close();
    }catch(Throwable $e){}
}

} // end function_exists guard

/* ═══ DIRECT CALLS (fetch from the group post modal) ═══ */
if ($tg_direct) {
    $tg_json = function ($data) {
        while (ob_get_level() > 0) ob_end_clean();      /* throw away anything config.php printed */
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };

    $me = (int)($_SESSION['user_id'] ?? 0);
    if (!$me || !isset($_POST['tag_action'])) $tg_json(['success'=>false,'msg'=>'Not allowed.']);

    try {
        $ta = (string)$_POST['tag_action'];

        if ($ta === 'search') {
            $q = ltrim(trim((string)($_POST['q'] ?? '')), '@'); $out = [];
            if (strlen($q) >= 2) {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $st = $conn->prepare("SELECT id, username, nickname, profile_pic FROM users WHERE (username LIKE ? OR nickname LIKE ?) AND id<>? ORDER BY username ASC LIMIT 12");
                $st->bind_param("ssi", $like, $like, $me); $st->execute();
                $res = $st->get_result();
                while ($u = $res->fetch_assoc()) {
                    $r = pv_reason($conn, (int)$u['id'], $me, 'tag_who');
                    $out[] = ['id'=>(int)$u['id'], 'username'=>$u['username'], 'nickname'=>(string)($u['nickname'] ?? ''),
                              'pic'=>(string)($u['profile_pic'] ?? ''), 'ok'=>($r === ''), 'why'=>($r === '' ? '' : pv_msg($u['username'], $r, 'tag'))];
                }
                $st->close();
            }
            $tg_json(['success'=>true, 'users'=>$out]);
        }

        if ($ta === 'mention_check') {
            $text = trim((string)($_POST['text'] ?? ''));
            $text = function_exists('mb_substr') ? mb_substr($text, 0, 2200) : substr($text, 0, 2200);
            $tg_json(['success'=>true, 'blocked'=>pv_text_issues($conn, $text, $me)]);
        }

        $tg_json(['success'=>false,'msg'=>'Unknown action.']);
    } catch (Throwable $e) {
        error_log('tag_guard: ' . $e->getMessage());
        $tg_json(['success'=>false,'msg'=>'Something went wrong.']);
    }
}