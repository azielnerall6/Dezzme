<?php
/* ================================================================
   algorithm_settings.php — "Your Algorithm"
   Topics (any word, not only hashtags), creators and formats a person wants more / less of,
   sensitive + political content controls, how adventurous the feed is,
   what the feed has learned (and a way to forget it), and resets.

   v8: your choices outrank what was learned, "Less" means suppression (not exclusion),
       "Not interested" posts can be reviewed and brought back one by one,
       "Reset what my feed has learned" now also makes the ranker ignore older history (forget_since).
   v9: "Forget" (one learned item) and "Bring back" (one hidden post) now also clear the feed cache,
       so a feed tab that is already open does not keep serving the old ranking.
   v10: shorter page. Everything below "Less of" is now a categorised list (rows with a > at the end);
        tapping a row opens that area in a sheet. The hero line sweeps red > purple > mosaic, then settles to white.

   v11: Undo bar after any change to your choices or dials (6 seconds), "Share or copy settings" (one short code you can
        copy, paste on another account, or keep for after a reset), and light haptics (switch in Feed controls, saved on this device).

   v12: light mode (<html data-theme="light">, same dz_theme setting and palette as the Community and Games pages).

   v13: full haptics like the home page: a light tap on every touch, a tap in time with every bounce animation,
        and a top-strength buzz that follows the "Recently you've been into…" red > purple > mosaic sweep
        (tap the headline to replay it). Error toasts get their own buzz.

   v14: - Sweep haptic is strong again: nothing else can interrupt it while it plays (touches, bounces, slider ticks are
          muted for its duration), the pattern uses long near-continuous bursts (phone motors only reach full power on
          longer pulses), and it lasts as long as the colour wave across all the words. iPhone gets a tick every ~35 ms.
        - Topic chip dropdown (Why you're seeing this / Move to see more or less / Remove interest / Back) now has its
          own click handler on the menu itself, so it no longer depends on the page-wide handler.

   v15: - Normal haptics are back the moment the sweep buzz is over. The old 10 ms taps were too short for most phone
          motors to feel at all, so every touch, bounce, switch and slider tick now uses a pulse long enough to be felt
          (touch 20 ms, bounce 15 ms, confirm 25 ms, error double tap). The sweep itself is untouched in spirit: the longest
          near-continuous full-power bursts a browser allows.
        - The "nothing else may vibrate" lock now ends exactly when the sweep pattern ends (a timer clears it), and it is only
          set when the browser actually accepted the vibration, so a blocked sweep can never leave buttons silent.
        - FIX: topic menu (Why you're seeing this / Move to see less / Remove interest) did nothing after the haptics were added.
          The iPhone haptic tick is a synthetic click on a hidden switch; the "tap outside closes the menu" handler took it for an
          outside tap and closed the menu before the real tap landed. Those synthetic clicks are now swallowed before any page handler.
        - Replaying the sweep by tapping the headline cancels any running vibration first, so the new buzz starts cleanly.

   Needs: config.php ($conn = mysqli), dz_rank.php (v8) + dz_algo_prefs.php (v8), tables from dezzme_migration_v4.sql / v5.sql
          (dz_algo_ensure_schema() creates them itself if they are missing).
   NOTE: `sensitive` is a reserved word in MariaDB/MySQL: in SQL it must always be written `sensitive`.
================================================================ */
session_start();
include 'config.php';
require_once 'dz_rank.php';          /* also loads dz_algo_prefs.php */
require_once 'dz_local_content.php'; /* About me location + the "show local content" helpers */

$uid = (int)($_SESSION['user_id'] ?? 0);
$is_ajax = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']));
if(!$uid){
    if($is_ajax){ header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Please log in again.']); exit; }
    header("Location: login.php"); exit();
}

dz_algo_ensure_schema($conn);
dz_local_ensure_column($conn);      /* adds dz_user_algo_settings.local_content (0 / 1) the first time */
if(empty($_SESSION['dz_algo_csrf'])) $_SESSION['dz_algo_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['dz_algo_csrf'];

$TYPE_KEYS = ['image','video','gif','sticker','text'];
$DEFAULT_PIC = 'uploads/me.png';

function alg_fail($msg){ echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

/* a topic is a #hashtag or any normal word / short phrase: "#memes", "glutes", "leg day".
   A leading # is kept (hashtags have no spaces); everything else is lower-cased and cleaned. */
function alg_norm_tag($s){
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $hash = ($s !== '' && $s[0] === '#');
    $s = ltrim($s, '#');
    $s = preg_replace('/[^\p{L}\p{N}_\- ]/u', '', $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    $s = trim($s);
    if($hash) $s = str_replace(' ', '', $s);
    $s = mb_substr($s, 0, $hash ? 39 : 40, 'UTF-8');
    return ($s === '') ? '' : ($hash ? '#'.$s : $s);
}

/* ================================================================
   AJAX (POST act=…)
================================================================ */
if($is_ajax){
    header('Content-Type: application/json');
    if(!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) alg_fail('Your session expired. Refresh the page and try again.');
    $act = (string)$_POST['act'];

    /* ── add / switch a choice ── */
    if($act === 'add_pref'){
        $kind = (string)($_POST['kind'] ?? ''); $pref = (string)($_POST['pref'] ?? ''); $item = trim((string)($_POST['item'] ?? ''));
        if(!in_array($kind, ['tag','creator','type'], true) || !in_array($pref, ['more','less'], true)) alg_fail('Something went wrong. Try again.');
        $label = ''; $pic = '';
        if($kind === 'tag'){
            $item = alg_norm_tag($item);
            if($item === '') alg_fail('Type a word or a short phrase. Letters and numbers only.');
            $label = $item;
        } elseif($kind === 'creator'){
            $cid = (int)$item;
            if($cid <= 0 || $cid === $uid) alg_fail('Pick someone else.');
            $st = $conn->prepare("SELECT username, profile_pic FROM users WHERE id=? AND deactivated_at IS NULL LIMIT 1");
            $st->bind_param("i", $cid); $st->execute();
            $u = $st->get_result()->fetch_assoc(); $st->close();
            if(!$u) alg_fail('That account is not available.');
            $item = (string)$cid; $label = $u['username']; $pic = $u['profile_pic'] ?: $DEFAULT_PIC;
        } else {
            $item = strtolower($item);
            if(!in_array($item, $TYPE_KEYS, true)) alg_fail('Unknown format.');
            $label = $item;
        }
        /* cap: 120 choices per person (changing an existing one is always allowed) */
        $st = $conn->prepare("SELECT COUNT(*) c FROM dz_user_algo_prefs WHERE user_id=?");
        $st->bind_param("i", $uid); $st->execute();
        $cnt = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
        $st = $conn->prepare("SELECT 1 FROM dz_user_algo_prefs WHERE user_id=? AND kind=? AND item=? LIMIT 1");
        $st->bind_param("iss", $uid, $kind, $item); $st->execute();
        $exists = $st->get_result()->num_rows > 0; $st->close();
        if(!$exists && $cnt >= 120) alg_fail('You have reached the limit of 120 choices. Remove one to add another.');

        $st = $conn->prepare("INSERT INTO dz_user_algo_prefs (user_id, kind, item, pref) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE pref=VALUES(pref)");
        $st->bind_param("isss", $uid, $kind, $item, $pref); $st->execute(); $st->close();
        unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);   /* feed cache is stale now */
        echo json_encode(['ok'=>true, 'row'=>['kind'=>$kind,'item'=>$item,'pref'=>$pref,'label'=>$label,'pic'=>$pic]]); exit;
    }

    /* ── remove a choice ── */
    if($act === 'remove_pref'){
        $kind = (string)($_POST['kind'] ?? ''); $item = (string)($_POST['item'] ?? '');
        if(!in_array($kind, ['tag','creator','type'], true)) alg_fail('Something went wrong. Try again.');
        if($kind === 'tag') $item = alg_norm_tag($item);
        $st = $conn->prepare("DELETE FROM dz_user_algo_prefs WHERE user_id=? AND kind=? AND item=?");
        $st->bind_param("iss", $uid, $kind, $item); $st->execute(); $st->close();
        unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);
        echo json_encode(['ok'=>true]); exit;
    }

    /* ── dials and switches ── */
    if($act === 'save_setting'){
        $ranges = ['adventure'=>[0,100], 'follow_level'=>[0,2], 'novelty_level'=>[0,2], 'personalize'=>[0,1], 'use_watch_time'=>[0,1],
                   'sensitive_level'=>[0,2], 'political_level'=>[0,2], 'local_content'=>[0,1]];
        $f = (string)($_POST['field'] ?? ''); $v = (int)($_POST['value'] ?? -1);
        if(!isset($ranges[$f]) || $v < $ranges[$f][0] || $v > $ranges[$f][1]) alg_fail('That setting could not be saved.');
        $st = $conn->prepare("INSERT INTO dz_user_algo_settings (user_id, `$f`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `$f` = VALUES(`$f`)");
        $st->bind_param("ii", $uid, $v); $st->execute(); $st->close();
        unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);
        echo json_encode(['ok'=>true]); exit;
    }

    /* ── people search for the creator picker (empty search = people you track) ── */
    if($act === 'search_users'){
        $q = trim((string)($_POST['q'] ?? '')); $like = '%'.$q.'%';
        $st = $conn->prepare("
            SELECT u.id, u.username, u.nickname, u.profile_pic,
                   (SELECT COUNT(*) FROM followers f WHERE f.follower_id = ? AND f.following_id = u.id) AS fol
            FROM users u
            WHERE u.id <> ? AND u.deactivated_at IS NULL
              AND u.id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)
              AND u.id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)
              AND u.id NOT IN (SELECT muted_id FROM muted_users WHERE muter_id = ?)
              AND (? = '' OR u.username LIKE ? OR u.nickname LIKE ?)
            HAVING (? <> '' OR fol > 0)
            ORDER BY fol DESC, u.username ASC
            LIMIT 20
        ");
        $st->bind_param("iiiiissss", $uid, $uid, $uid, $uid, $uid, $q, $like, $like, $q);
        $st->execute();
        $rs = $st->get_result(); $out = [];
        while($r = $rs->fetch_assoc()) $out[] = ['id'=>(int)$r['id'], 'label'=>$r['username'], 'nick'=>(string)($r['nickname'] ?? ''), 'pic'=>$r['profile_pic'] ?: $DEFAULT_PIC, 'following'=>(int)$r['fol'] > 0];
        $st->close();
        echo json_encode(['ok'=>true, 'users'=>$out]); exit;
    }

    /* ── topic suggestions: words from recent posts (captions + what the media shows) and #hashtags ── */
    if($act === 'suggest_tags'){
        $raw = trim((string)($_POST['q'] ?? ''));
        $isHash = (strpos($raw, '#') === 0);
        $q = ltrim(alg_norm_tag($raw), '#'); $like = $q.'%';
        $out = []; $seen = [];
        if(!$isHash){
            try{
                $st = $conn->prepare("
                    SELECT term, MAX(shown) AS shown, COUNT(*) c FROM dz_meme_terms
                    WHERE meme_id >= (SELECT COALESCE(MAX(id),0) - 20000 FROM memes) AND (term LIKE ? OR shown LIKE ?)
                    GROUP BY term ORDER BY c DESC LIMIT 14
                ");
                if($st){
                    $st->bind_param("ss", $like, $like); $st->execute();
                    $rs = $st->get_result();
                    while($r = $rs->fetch_assoc()){
                        $w = alg_norm_tag((string)$r['shown']);
                        if($w === '' || isset($seen[$w])) continue;
                        $seen[$w] = 1; $out[] = ['tag'=>$w, 'n'=>(int)$r['c']];
                    }
                    $st->close();
                }
            }catch(Throwable $e){}
        }
        try{
            $st = $conn->prepare("
                SELECT t.tag, COUNT(*) c FROM dz_meme_tags t
                WHERE t.meme_id >= (SELECT COALESCE(MAX(id),0) - 20000 FROM memes) AND t.tag LIKE ?
                GROUP BY t.tag ORDER BY c DESC LIMIT 14
            ");
            if($st){
                $st->bind_param("s", $like); $st->execute();
                $rs = $st->get_result();
                while($r = $rs->fetch_assoc()){
                    $w = alg_norm_tag('#'.$r['tag']);
                    if($w === '' || isset($seen[$w])) continue;
                    $seen[$w] = 1; $out[] = ['tag'=>$w, 'n'=>(int)$r['c']];
                }
                $st->close();
            }
        }catch(Throwable $e){}
        usort($out, function($a, $b){ return $b['n'] <=> $a['n']; });
        echo json_encode(['ok'=>true, 'tags'=>array_slice($out, 0, 14)]); exit;
    }

    /* ── forget one thing the feed learned ── */
    if($act === 'forget'){
        $kind = (string)($_POST['kind'] ?? ''); $item = (string)($_POST['item'] ?? '');
        if(!in_array($kind, ['tag','creator','type'], true)) alg_fail('Something went wrong. Try again.');
        $st = $conn->prepare("DELETE FROM dz_user_affinity WHERE user_id=? AND kind=? AND item=?");
        $st->bind_param("iss", $uid, $kind, $item); $st->execute(); $st->close();
        unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);   /* v9: an open feed tab must not keep the old ranking */
        echo json_encode(['ok'=>true]); exit;
    }

    /* ── resets ── */
    if($act === 'reset_taste'){
        $st = $conn->prepare("DELETE FROM dz_user_affinity WHERE user_id=?");
        $st->bind_param("i", $uid); $st->execute(); $st->close();
        if(function_exists('dz_algo_set_forget')) dz_algo_set_forget($conn, $uid);   /* every reader of raw interactions now ignores older history */
        unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);
        echo json_encode(['ok'=>true]); exit;
    }
    if($act === 'reset_choices'){
        $st = $conn->prepare("DELETE FROM dz_user_algo_prefs WHERE user_id=?");
        $st->bind_param("i", $uid); $st->execute(); $st->close();
        /* UPDATE (not DELETE) so forget_since survives. Columns + values come from the defaults list. */
        $defs = (dz_algo_defaults() + ['local_content'=>0]);
        $sets = []; $vals = []; $types = '';
        foreach($defs as $col=>$val){
            if(!preg_match('/^[a-z_]+$/', (string)$col)) continue;
            $sets[] = "`$col`=?"; $vals[] = (int)$val; $types .= 'i';
        }
        $st = $conn->prepare("UPDATE dz_user_algo_settings SET ".implode(',', $sets)." WHERE user_id=?");
        $types .= 'i'; $vals[] = $uid;
        $st->bind_param($types, ...$vals); $st->execute(); $st->close();
        unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);
        echo json_encode(['ok'=>true, 'settings'=>$defs]); exit;
    }
    if($act === 'undo_not_interested'){
        $st = $conn->prepare("DELETE FROM user_interactions WHERE user_id=? AND interaction_type='not_interested'");
        $st->bind_param("i", $uid); $st->execute();
        $n = (int)$st->affected_rows; $st->close();
        unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);
        echo json_encode(['ok'=>true, 'restored'=>$n]); exit;
    }

    /* ── posts marked "Not interested": list them, bring one back ── */
    if($act === 'list_hidden'){
        $st = $conn->prepare("
            SELECT m.id, m.caption, m.type, m.file_name, u.username, MAX(ui.created_at) AS hidden_at
            FROM user_interactions ui
            JOIN memes m ON m.id = ui.meme_id
            JOIN users u ON u.id = m.uploaded_by
            WHERE ui.user_id = ? AND ui.interaction_type = 'not_interested'
            GROUP BY m.id, m.caption, m.type, m.file_name, u.username
            ORDER BY hidden_at DESC LIMIT 40
        ");
        $st->bind_param("i", $uid); $st->execute();
        $rs = $st->get_result(); $out = [];
        while($r = $rs->fetch_assoc()){
            $type = strtolower((string)$r['type']);
            $cap  = trim(preg_replace('/\s+/u', ' ', (string)$r['caption']));
            if(mb_strlen($cap, 'UTF-8') > 90) $cap = mb_substr($cap, 0, 90, 'UTF-8') . '…';
            $thumb = (in_array($type, ['image','gif','sticker','stickers'], true) && !empty($r['file_name'])) ? 'uploads/' . $r['file_name'] : '';
            $out[] = ['id'=>(int)$r['id'], 'caption'=>$cap, 'type'=>$type, 'thumb'=>$thumb, 'user'=>$r['username']];
        }
        $st->close();
        echo json_encode(['ok'=>true, 'posts'=>$out]); exit;
    }
    if($act === 'restore_hidden'){
        $mid = (int)($_POST['meme_id'] ?? 0);
        if($mid <= 0) alg_fail('Something went wrong. Try again.');
        $st = $conn->prepare("DELETE FROM user_interactions WHERE user_id=? AND meme_id=? AND interaction_type='not_interested'");
        $st->bind_param("ii", $uid, $mid); $st->execute();
        $n = (int)$st->affected_rows; $st->close();
        /* give the small taste penalty that post caused back (same damped amounts it was taken with) */
        if($n > 0 && function_exists('dz_affinity_bump')) dz_affinity_bump($conn, $uid, $mid, -dz_affinity_weight('not_interested'), true, true);
        unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);   /* v9: the post can come back in an open feed tab */
        echo json_encode(['ok'=>true, 'restored'=>$n]); exit;
    }

    /* ── use a settings code (this is also how Undo works): replaces choices + dials with the ones in the code ── */
    if($act === 'import_settings'){
        $code = preg_replace('/\s+/', '', (string)($_POST['code'] ?? ''));
        if($code === '' || strlen($code) > 30000) alg_fail('Paste a settings code first.');
        if(stripos($code, 'DZA1-') === 0) $code = substr($code, 5);
        $code = strtr($code, '-_', '+/');
        $code .= str_repeat('=', (4 - strlen($code) % 4) % 4);
        $raw = base64_decode($code, true);
        $j = ($raw === false) ? null : json_decode($raw, true);
        if(!is_array($j) || (int)($j['v'] ?? 0) !== 1) alg_fail('That code did not work. Check you copied all of it.');

        $ranges = ['adventure'=>[0,100], 'follow_level'=>[0,2], 'novelty_level'=>[0,2], 'personalize'=>[0,1], 'use_watch_time'=>[0,1],
                   'sensitive_level'=>[0,2], 'political_level'=>[0,2], 'local_content'=>[0,1]];
        $newSet = [];
        $sIn = (isset($j['s']) && is_array($j['s'])) ? $j['s'] : [];
        foreach($ranges as $f => $rg){
            if(isset($sIn[$f]) && is_numeric($sIn[$f])){ $v = (int)$sIn[$f]; if($v >= $rg[0] && $v <= $rg[1]) $newSet[$f] = $v; }
        }

        $want = []; $creatorIds = [];
        $pIn = (isset($j['p']) && is_array($j['p'])) ? $j['p'] : [];
        foreach($pIn as $e){
            if(!is_array($e) || count($e) < 3) continue;
            $kind = (string)$e[0]; $item = trim((string)$e[1]); $pref = (string)$e[2];
            if(!in_array($pref, ['more','less'], true)) continue;
            if($kind === 'tag'){ $item = alg_norm_tag($item); if($item === '') continue; }
            elseif($kind === 'type'){ $item = strtolower($item); if(!in_array($item, $TYPE_KEYS, true)) continue; }
            elseif($kind === 'creator'){ $cid = (int)$item; if($cid <= 0 || $cid === $uid) continue; $item = (string)$cid; $creatorIds[$cid] = 1; }
            else continue;
            $want[$kind . "\0" . $item] = [$kind, $item, $pref];
            if(count($want) >= 120) break;
        }
        $umap2 = [];
        if($creatorIds){
            $r = $conn->query("SELECT id, username, profile_pic FROM users WHERE deactivated_at IS NULL AND id IN (" . implode(',', array_map('intval', array_keys($creatorIds))) . ")");
            if($r) while($u = $r->fetch_assoc()) $umap2[(int)$u['id']] = $u;
        }
        $final = [];
        foreach($want as $w){ if($w[0] === 'creator' && !isset($umap2[(int)$w[1]])) continue; $final[] = $w; }

        $st = $conn->prepare("DELETE FROM dz_user_algo_prefs WHERE user_id=?");
        $st->bind_param("i", $uid); $st->execute(); $st->close();
        if($final){
            $st = $conn->prepare("INSERT INTO dz_user_algo_prefs (user_id, kind, item, pref) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE pref=VALUES(pref)");
            foreach($final as $w){ $k = $w[0]; $i = $w[1]; $pf = $w[2]; $st->bind_param("isss", $uid, $k, $i, $pf); $st->execute(); }
            $st->close();
        }
        if($newSet){
            $cols = array_keys($newSet); $vals = array_values($newSet);
            $ins = '`' . implode('`,`', $cols) . '`';
            $ph  = implode(',', array_fill(0, count($cols), '?'));
            $upd = implode(',', array_map(function($c){ return "`$c`=VALUES(`$c`)"; }, $cols));
            $st = $conn->prepare("INSERT INTO dz_user_algo_settings (user_id, $ins) VALUES (?, $ph) ON DUPLICATE KEY UPDATE $upd");
            $types = 'i' . str_repeat('i', count($cols));
            $st->bind_param($types, $uid, ...$vals); $st->execute(); $st->close();
        }
        $outP = [];
        foreach($final as $w){
            if($w[0] === 'creator'){ $u = $umap2[(int)$w[1]]; $outP[] = ['kind'=>'creator','item'=>$w[1],'pref'=>$w[2],'label'=>$u['username'],'pic'=>$u['profile_pic'] ?: $DEFAULT_PIC]; }
            else $outP[] = ['kind'=>$w[0],'item'=>$w[1],'pref'=>$w[2],'label'=>$w[1],'pic'=>''];
        }
        unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);
        echo json_encode(['ok'=>true, 'prefs'=>$outP, 'settings'=>(dz_algo_settings($conn, $uid) + ['local_content' => (int)dz_local_is_on($conn, $uid)])]); exit;
    }

    alg_fail('Unknown action.');
}

/* ================================================================
   PAGE DATA
================================================================ */
$prefs = [];
try{
    $r = $conn->query("SELECT kind, item, pref FROM dz_user_algo_prefs WHERE user_id = $uid ORDER BY updated_at DESC");
    if($r) while($x = $r->fetch_assoc()) $prefs[] = ['kind'=>$x['kind'], 'item'=>(string)$x['item'], 'pref'=>$x['pref']];
}catch(Throwable $e){}

$learned = dz_algo_learned($conn, $uid, 60);

/* usernames + pictures for every creator id we are about to show */
$cids = [];
foreach($prefs as $p)   if($p['kind'] === 'creator') $cids[(int)$p['item']] = 1;
foreach($learned as $l) if($l['kind'] === 'creator') $cids[(int)$l['item']] = 1;
$umap = [];
if($cids){
    $r = $conn->query("SELECT id, username, profile_pic FROM users WHERE deactivated_at IS NULL AND id IN (" . implode(',', array_map('intval', array_keys($cids))) . ")");
    if($r) while($u = $r->fetch_assoc()) $umap[(int)$u['id']] = $u;
}
$prefs_out = [];
foreach($prefs as $p){
    if($p['kind'] === 'creator'){
        $u = $umap[(int)$p['item']] ?? null; if(!$u) continue;
        $p['label'] = $u['username']; $p['pic'] = $u['profile_pic'] ?: $DEFAULT_PIC;
    } else { $p['label'] = $p['item']; $p['pic'] = ''; }
    $prefs_out[] = $p;
}
$learned_out = [];
foreach($learned as $l){
    if($l['kind'] === 'creator'){
        $u = $umap[(int)$l['item']] ?? null; if(!$u) continue;
        $l['label'] = $u['username']; $l['pic'] = $u['profile_pic'] ?: $DEFAULT_PIC;
    } else { $l['label'] = $l['item']; $l['pic'] = ''; }
    $learned_out[] = $l;
}

$hidden_n = 0;
try{
    $r = $conn->query("SELECT COUNT(DISTINCT meme_id) c FROM user_interactions WHERE user_id = $uid AND interaction_type = 'not_interested'");
    if($r) $hidden_n = (int)($r->fetch_assoc()['c'] ?? 0);
}catch(Throwable $e){}

$weights = [
    ['Repost',          dz_affinity_weight('repost')],
    ['Save',            dz_affinity_weight('favorite')],
    ['Share',           dz_affinity_weight('share')],
    ['Comment',         dz_affinity_weight('comment')],
    ['Like',            dz_affinity_weight('like')],
    ['Interested',      dz_affinity_weight('interested')],
    ['Open a post',     dz_affinity_weight('view')],
    ['Not interested',  dz_affinity_weight('not_interested')],
];

$boot = [
    'csrf'     => $csrf,
    'prefs'    => $prefs_out,
    'learned'  => $learned_out,
    'settings' => (dz_algo_settings($conn, $uid) + ['local_content' => (int)dz_local_is_on($conn, $uid)]),
    'location' => dz_about_location($conn, $uid),
    'hidden'   => $hidden_n,
    'weights'  => $weights,
];
$boot_json = json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<script>document.documentElement.classList.add('is-loading'); window.__t0 = Date.now();</script>
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<title>Your Algorithm | Dezzme</title>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
button{ border:none; background:none; color:inherit; }
button:focus-visible, input:focus-visible{ outline:2px solid rgba(255,45,45,.8); outline-offset:2px; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --fg:       255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text:  #ffffff;
    --solid:    #070924;
    --red:      #FF2D2D;
    --purple:   #8b3dff;
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(var(--fg),0.05);
    --surface2: rgba(var(--fg),0.09);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.16);
    --text:     #ffffff;
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.65);
    --card-bg:  rgba(12,12,28,0.98);   /* sheets / modals */
    --pop-bg:   rgba(8,8,22,.97);      /* toast + undo bar */
    --scrim:    rgba(0,0,0,0.7);
    --ph:       #1a1a1a;               /* avatar placeholder */
    --ok:       #4ade80;
    --danger:   #ff6b6b;
    --danger2:  #ff6b7a;

    --max-w:    580px;
    --topbar-h: 56px;
    --pad:      clamp(14px, 4.4vw, 22px);
    --r:        6px;
}
@media (max-width:490px){ :root{ --topbar-h:52px; } }

html{ scroll-behavior:smooth; }
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; }
}
body{
    background:var(--bg); background-attachment:fixed; color:var(--text);
    min-height:100vh; display:flex; justify-content:center; overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}

/* ── swipe-back: the page follows your finger ── */
.ac-lane, .u-modal-card{ touch-action:pan-y pinch-zoom; }
#swipeShade{ position:fixed; inset:0; background:#000; opacity:0; pointer-events:none; z-index:0; }
.ac-lane.dragging{ position:relative; z-index:1; background:var(--solid); box-shadow:-14px 0 36px rgba(0,0,0,.55); will-change:transform; }

/* ── lane + topbar (same as the account page) ── */
.ac-lane{ width:100%; max-width:var(--max-w); min-height:100vh; display:flex; flex-direction:column; padding-bottom:90px; }
@media (min-width:801px){ .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .ac-lane{ max-width:100%; } }

.ac-topbar{
    position:sticky; top:0; z-index:500; height:var(--topbar-h);
    display:flex; align-items:center; gap:2px; padding:0 10px;
    background:var(--solid);   /* the page's own colour, so the bar and the page read as one surface */
}
.ac-x{ width:38px; height:38px; color:var(--ui-text); display:flex; align-items:center; justify-content:center; opacity:.92; transition:opacity .2s, color .2s; flex-shrink:0; }
.ac-x:hover{ opacity:1; color:var(--red); }
/* back arrow: the exact same thin, tall chevron and size as the Zeems modal's (same 14x24 shape, same size formula) */
.ac-x{ width:auto; min-width:38px; padding:6px 6px 6px 2px; box-sizing:border-box; }
.ac-x .ac-back-svg{ height:calc((18px + 4 * clamp(0px, calc((100vw - 330px) / 460), 1px)) * 1.155); width:auto; aspect-ratio:14 / 24; display:block; overflow:visible; }
.ac-title{ flex:1; min-width:0; font-size:1.1rem; font-weight:700; letter-spacing:-.1px; text-align:right; margin:0 8px 0 10px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* ── hero ── */
.hero{ padding:clamp(22px, 6vw, 34px) var(--pad) clamp(18px, 5vw, 26px); }
.hero h1{ font-size:clamp(1.55rem, 6.6vw, 2.15rem); font-weight:800; line-height:1.2; letter-spacing:-.6px; cursor:pointer; }
.hero p{ margin-top:12px; font-size:clamp(.74rem, 2.4vw, .84rem); color:var(--muted); line-height:1.65; max-width:440px; }

/* ── sections ── */
.blk{ padding:clamp(20px, 5.4vw, 28px) var(--pad); border-top:1px solid var(--line); }
.blk-h{ font-size:clamp(.98rem, 3.6vw, 1.12rem); font-weight:700; letter-spacing:-.2px; }
.blk-sub{ margin-top:4px; font-size:clamp(.72rem, 2.3vw, .8rem); color:var(--muted); line-height:1.55; display:flex; align-items:center; gap:2px; text-align:left; }
button.blk-sub:hover{ color:var(--sub); }
.blk-sub i{ font-size:1.05rem; }
.blk-body{ margin-top:16px; }

/* ── chips (rectangular buttons) ── */
.chips{ display:flex; flex-wrap:wrap; gap:10px; }
.chip{
    display:inline-flex; align-items:center; gap:8px; max-width:100%;
    min-height:clamp(40px, 10.6vw, 46px); padding:0 clamp(14px, 4vw, 19px);
    border-radius:var(--r); background:rgba(var(--fg),.07);
    font-size:clamp(.78rem, 2.6vw, .88rem); font-weight:600; color:var(--ui-text);
    transition:background .18s, transform .12s, border-color .18s;
}
.chip span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.chip i{ font-size:1.05rem; flex-shrink:0; }
.chip:hover{ background:rgba(var(--fg),.12); }
.chip:active{ transform:scale(.97); }
.chip:not(.out) i{ color:var(--muted); margin-right:-4px; }
.chip:not(.out):hover i{ color:var(--red); }
.chip.out{ background:transparent; border:1.5px solid var(--line2); }
.chip.out i{ color:var(--red); }
.chip.out:hover{ border-color:rgba(255,45,45,.65); background:rgba(255,45,45,.07); }
.chips-note{ font-size:.72rem; color:var(--muted); margin:2px 0 12px; line-height:1.6; }

/* ── rows (creators, formats, learned) ── */
.rows{ display:flex; flex-direction:column; }
.row{ display:flex; align-items:center; gap:12px; padding:12px 0; border-top:1px solid var(--line); }
.row:first-child{ border-top:none; }
.row-pic{ width:clamp(38px, 10.4vw, 46px); height:clamp(38px, 10.4vw, 46px); border-radius:50%; object-fit:cover; border:2px solid var(--red); background:var(--ph); flex-shrink:0; }
.row-ic{ width:clamp(34px, 9.4vw, 40px); height:clamp(34px, 9.4vw, 40px); border-radius:7px; background:var(--surface); color:var(--red); display:flex; align-items:center; justify-content:center; font-size:1.05rem; flex-shrink:0; }
.row-info{ flex:1; min-width:0; }
.row-name{ font-size:clamp(.8rem, 2.7vw, .9rem); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.row-sub{ font-size:.68rem; color:var(--muted); margin-top:2px; }
.x-btn{ width:30px; height:30px; border-radius:5px; background:var(--surface); color:var(--sub); display:flex; align-items:center; justify-content:center; font-size:1.05rem; flex-shrink:0; transition:background .2s, color .2s; }
.x-btn:hover{ background:rgba(255,45,45,.15); color:var(--red); }
.empty{ font-size:.78rem; color:var(--muted); line-height:1.65; padding:4px 0 10px; }

/* ── segmented control ── */
.seg{ display:inline-flex; background:var(--surface); border:1px solid var(--line); border-radius:7px; padding:3px; gap:2px; flex-shrink:0; }
.seg button{ min-width:clamp(46px, 12vw, 56px); height:clamp(29px, 7.8vw, 33px); padding:0 11px; border-radius:5px; font-size:.72rem; font-weight:600; color:var(--sub); display:flex; align-items:center; justify-content:center; gap:4px; transition:background .18s, color .18s; }
.seg button:hover{ color:var(--ui-text); }
.seg button.on{ background:var(--red); color:#fff; }
.seg.sm button{ min-width:34px; padding:0 8px; }
.seg.full{ display:flex; width:100%; }
.seg.full button{ flex:1; }

/* ── option list (Instagram-style radio rows) ── */
.opts{ display:flex; flex-direction:column; gap:8px; }
.opt{ width:100%; display:flex; align-items:center; gap:12px; padding:13px 14px; text-align:left; border:1.5px solid var(--line2); border-radius:var(--r); background:transparent; transition:border-color .18s, background .18s; }
.opt:hover{ background:rgba(var(--fg),.05); }
.opt.on{ border-color:var(--red); background:rgba(255,45,45,.07); }
.opt-txt{ flex:1; min-width:0; }
.opt-txt b{ display:block; font-size:.86rem; font-weight:600; }
.opt-txt small{ display:block; margin-top:2px; font-size:.72rem; color:var(--muted); line-height:1.5; }
.radio{ width:20px; height:20px; border-radius:50%; border:2px solid var(--line2); flex-shrink:0; position:relative; transition:border-color .18s; }
.opt.on .radio{ border-color:var(--red); }
.opt.on .radio::after{ content:''; position:absolute; inset:3px; border-radius:50%; background:var(--red); }

/* ── adventure slider ── */
.adv-val{ display:flex; align-items:baseline; justify-content:space-between; margin-bottom:14px; gap:10px; }
.adv-val b{ font-size:1.05rem; font-weight:700; }
.adv-val span{ font-size:.74rem; color:var(--muted); text-align:right; line-height:1.5; }
.range{ -webkit-appearance:none; appearance:none; width:100%; height:6px; border-radius:3px; outline:none; background:linear-gradient(90deg, var(--red) 0, var(--red) var(--p,50%), rgba(var(--fg),.14) var(--p,50%), rgba(var(--fg),.14) 100%); }
.range::-webkit-slider-thumb{ -webkit-appearance:none; width:26px; height:26px; border-radius:50%; background:#fff; border:5px solid var(--red); box-shadow:0 2px 10px rgba(0,0,0,.5); cursor:pointer; }
.range::-moz-range-thumb{ width:17px; height:17px; border-radius:50%; background:#fff; border:5px solid var(--red); box-shadow:0 2px 10px rgba(0,0,0,.5); cursor:pointer; }
.range-ends{ display:flex; justify-content:space-between; margin-top:10px; font-size:.68rem; color:var(--muted); font-weight:500; }

/* ── control rows (switches / segmented) ── */
.ctl{ padding:15px 0; border-top:1px solid var(--line); }
.ctl:first-child{ border-top:none; padding-top:0; }
.ctl-head{ display:flex; align-items:center; gap:12px; }
.ctl-txt{ flex:1; min-width:0; }
.ctl-title{ font-size:clamp(.8rem, 2.7vw, .9rem); font-weight:600; }
.ctl-desc{ font-size:.72rem; color:var(--muted); line-height:1.55; margin-top:2px; }
.ctl .seg{ margin-top:12px; }
.sw{ position:relative; width:46px; height:27px; border-radius:6px; background:rgba(var(--fg),.16); flex-shrink:0; transition:background .2s; }
.sw::after{ content:''; position:absolute; top:3px; left:3px; width:21px; height:21px; border-radius:4px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.25); transition:transform .2s; }
.sw.on{ background:var(--red); }
.sw.on::after{ transform:translateX(19px); }

/* ── learned chips ── */
.lgroup + .lgroup{ margin-top:20px; }
.lgroup-h{ font-size:.74rem; font-weight:600; color:var(--sub); margin-bottom:10px; }
.lchips{ display:flex; flex-wrap:wrap; gap:8px; }
.lchip{ display:inline-flex; align-items:center; gap:8px; max-width:100%; background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:6px 6px 6px 13px; font-size:.76rem; font-weight:500; }
.lchip img{ width:20px; height:20px; border-radius:50%; object-fit:cover; margin-left:-6px; border:1.5px solid var(--red); }
.lchip .lc{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.meter{ width:30px; height:4px; border-radius:2px; background:rgba(var(--fg),.12); overflow:hidden; flex-shrink:0; }
.meter b{ display:block; height:100%; background:var(--red); border-radius:2px; }
.lchip.neg .meter b{ background:rgba(var(--fg),.55); }
.lchip button{ width:22px; height:22px; border-radius:4px; background:rgba(var(--fg),.08); color:var(--sub); display:flex; align-items:center; justify-content:center; font-size:.9rem; transition:background .2s, color .2s; }
.lchip button:hover{ background:rgba(255,45,45,.2); color:var(--red); }

/* ── reset buttons (inside the Reset sheet) ── */
.act{ width:100%; display:flex; align-items:center; gap:12px; padding:13px 0; border-top:1px solid var(--line); text-align:left; }
.act:first-child{ border-top:none; padding-top:0; }
.act:hover .row-name{ color:var(--red); }
.act .chev{ color:var(--muted); font-size:1.05rem; }

/* ── LIST MENU (category rows with a > at the end) ── */
.mgroup + .mgroup{ margin-top:22px; }
.mgroup-h{ font-size:.74rem; font-weight:600; color:var(--muted); margin-bottom:4px; letter-spacing:.2px; }
.mrow{ width:100%; display:flex; align-items:center; gap:13px; padding:13px 0; border-top:1px solid var(--line); text-align:left; transition:transform .3s var(--bounce); }
.mrow:first-of-type{ border-top:none; }
.mrow .chev{ color:var(--muted); font-size:1.25rem; flex-shrink:0; transition:transform .3s var(--bounce), color .18s; }
.mrow:hover .row-name{ color:var(--ui-text); }
.mrow:hover .chev{ transform:translateX(3px); color:var(--red); }
.mrow:active{ transform:scale(.98); }
.mrow .row-ic{ width:clamp(37px, 10.3vw, 44px); height:clamp(37px, 10.3vw, 44px); font-size:1.16rem; }
.mrow .row-name{ font-size:clamp(.88rem, 3vw, .99rem); }
.mrow .row-sub{ font-size:.79rem; margin-top:3px; }
.mrow .chev{ font-size:1.38rem; }
.mgroup-h{ font-size:.81rem; }

/* ── modals (same family as the account page) ── */
.u-modal-overlay{ position:fixed; inset:0; z-index:999999; background:var(--scrim); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); display:none; align-items:flex-end; justify-content:center; }
.u-modal-overlay.open{ display:flex; animation:acFade .18s ease both; }
@keyframes acFade{ from{ opacity:0; } to{ opacity:1; } }
@keyframes acSlideUp{ from{ transform:translateY(60px); opacity:0; } to{ transform:none; opacity:1; } }
.u-modal-card{
    position:relative; width:100%; max-width:480px; max-height:82vh; display:flex; flex-direction:column; overflow:hidden;
    background:var(--card-bg); border:1px solid var(--line); border-bottom:none; border-radius:10px 10px 0 0;
    animation:acSlideUp .28s cubic-bezier(.32,.72,0,1);
}
.u-modal-card.tall{ max-height:90vh; }
.u-modal-card::before{ content:''; display:block; flex-shrink:0; width:40px; height:4px; background:rgba(var(--fg),.18); border-radius:4px; margin:11px auto 2px; }
.u-modal-header{ display:flex; align-items:center; justify-content:space-between; padding:11px var(--pad); border-bottom:1px solid var(--line); flex-shrink:0; gap:10px; }
.u-modal-title{ font-size:.95rem; font-weight:700; display:flex; align-items:center; gap:8px; }
.u-modal-close{ width:30px; height:30px; flex-shrink:0; background:rgba(var(--fg),.08); border-radius:5px; color:var(--sub); font-size:1.1rem; display:flex; align-items:center; justify-content:center; transition:background .2s, color .2s; }
.u-modal-close:hover{ background:rgba(var(--fg),.18); color:var(--ui-text); }
.u-modal-search-wrap{ padding:11px var(--pad) 8px; flex-shrink:0; position:relative; }
.u-modal-search-icon{ position:absolute; left:calc(var(--pad) + 12px); top:50%; transform:translateY(-50%); color:var(--muted); font-size:1rem; pointer-events:none; margin-top:1px; }
.u-modal-search{ width:100%; height:40px; background:var(--surface); border:1px solid var(--line2); border-radius:var(--r); padding:0 13px 0 38px; font-size:.84rem; color:var(--ui-text); outline:none; transition:border-color .2s, background .2s, box-shadow .2s; -webkit-appearance:none; appearance:none; }
.u-modal-search::placeholder{ color:var(--muted); }
.u-modal-search:focus{ border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
.u-modal-list{ overflow-y:auto; flex:1; padding:2px 0 26px; }
.u-modal-body{ overflow-y:auto; flex:1; padding:16px var(--pad) 26px; font-size:.82rem; color:var(--sub); line-height:1.7; }
.u-modal-body p + p{ margin-top:10px; }
.u-modal-body strong{ color:var(--ui-text); font-weight:600; }
.pane-scroll{ overflow-y:auto; flex:1; padding:16px var(--pad) 30px; }
.pane[hidden]{ display:none !important; }
.pane-sub{ font-size:.78rem; color:var(--muted); line-height:1.6; margin-bottom:16px; }
.pk-row{ display:flex; align-items:center; gap:13px; padding:12px var(--pad); cursor:pointer; transition:background .15s; width:100%; text-align:left; }
.pk-row:hover{ background:rgba(var(--fg),.05); }
.pk-row .row-name{ font-size:.86rem; }
.pk-add{ color:var(--red); font-size:1.15rem; flex-shrink:0; }
.pk-empty{ padding:36px 20px; text-align:center; color:var(--muted); font-size:.8rem; }
.wt{ margin:14px 0; border:1px solid var(--line); border-radius:8px; overflow:hidden; }
.wt div{ display:flex; justify-content:space-between; padding:9px 13px; font-size:.8rem; }
.wt div + div{ border-top:1px solid var(--line); }
.wt b{ color:var(--ui-text); font-weight:600; }
.wt .pos{ color:var(--ok); font-weight:600; }
.wt .neg{ color:var(--red); font-weight:600; }
.cf-actions{ display:flex; gap:10px; margin-top:18px; }
.cf-actions button{ flex:1; height:42px; border-radius:var(--r); font-size:.82rem; font-weight:600; transition:background .2s; }
.cf-cancel{ background:rgba(var(--fg),.09); }
.cf-cancel:hover{ background:rgba(var(--fg),.17); }
.cf-ok{ background:var(--red); color:#fff; }
.cf-ok:hover{ background:#e02222; }

/* ── hidden posts list ── */
.hd-row{ display:flex; align-items:center; gap:12px; padding:12px var(--pad); border-top:1px solid var(--line); }
.hd-row:first-child{ border-top:none; }
.hd-th{ width:46px; height:46px; border-radius:7px; object-fit:cover; background:var(--surface); flex-shrink:0; display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:1.1rem; }
.hd-txt{ flex:1; min-width:0; }
.hd-cap{ font-size:.78rem; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hd-by{ font-size:.68rem; color:var(--muted); margin-top:2px; }
.hd-back{ height:32px; padding:0 12px; border-radius:5px; border:1.5px solid var(--line2); font-size:.72rem; font-weight:600; flex-shrink:0; transition:border-color .18s, color .18s, transform .3s var(--bounce); }
.hd-back:hover{ border-color:rgba(255,45,45,.65); color:var(--red); }
.hd-back:active{ transform:scale(.92); }
.hd-foot{ padding:14px var(--pad) 0; }
.hd-foot button{ width:100%; height:42px; border-radius:var(--r); background:rgba(var(--fg),.09); font-size:.82rem; font-weight:600; transition:background .2s; }
.hd-foot button:hover{ background:rgba(var(--fg),.17); }

@media (min-width:600px){
    .u-modal-overlay{ align-items:center; padding:20px; }
    .u-modal-card{ border-radius:8px; border-bottom:1px solid var(--line); max-width:420px; }
    .u-modal-card::before{ display:none; }
}

/* toast */
#toast{
    position:fixed; bottom:92px; left:50%; transform:translateX(-50%) translateY(12px);
    background:var(--pop-bg); border:1px solid rgba(74,222,128,.3); color:var(--ok);
    font-size:.78rem; font-weight:500; padding:10px 18px; border-radius:var(--r);
    opacity:0; pointer-events:none; transition:opacity .22s ease, transform .22s ease;
    z-index:9999999; max-width:calc(100vw - 32px); text-align:center; box-shadow:0 8px 24px rgba(0,0,0,.6);
}
#toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
#toast.err{ border-color:rgba(255,45,45,.4); color:var(--danger); }

/* ── skeleton loader ── */
#skel{ display:none; }
.is-loading #skel{ display:block; }
.is-loading #real{ display:none; }
.is-ready #real{ animation:realIn .35s ease both; }
@keyframes realIn{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }
.sk{ display:block; border-radius:var(--r); background:linear-gradient(90deg, rgba(var(--fg),.06) 25%, rgba(var(--fg),.14) 37%, rgba(var(--fg),.06) 63%); background-size:400% 100%; animation:skShim 1.3s ease infinite; }
@keyframes skShim{ 0%{ background-position:100% 50%; } 100%{ background-position:0 50%; } }
.sk-hero{ padding:clamp(22px, 6vw, 34px) var(--pad) clamp(18px, 5vw, 26px); }
.sk-t1{ height:30px; width:88%; } .sk-t2{ height:30px; width:56%; margin-top:10px; }
.sk-blk{ padding:clamp(20px, 5.4vw, 28px) var(--pad); border-top:1px solid var(--line); }
.sk-h{ height:18px; width:58%; } .sk-p{ height:11px; margin-top:10px; }
.sk-chips{ display:flex; flex-wrap:wrap; gap:10px; margin-top:18px; }
.sk-chip{ height:42px; }
.sk-row{ display:flex; align-items:center; gap:12px; margin-top:18px; }
.sk-ic{ width:38px; height:38px; border-radius:7px; flex-shrink:0; }
.sk-lines{ flex:1; min-width:0; }
.sk-glance{ display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; padding:0 var(--pad) 18px; }
.sk-gl{ height:62px; }

/* ═════════ PLAYFUL, BOUNCY MOTION ═════════ */
:root{ --bounce:cubic-bezier(.34,1.56,.64,1); --bounce-soft:cubic-bezier(.3,1.35,.5,1); }
@keyframes popIn{ 0%{ opacity:0; transform:scale(.55) translateY(10px); } 55%{ opacity:1; transform:scale(1.09) translateY(-2px); } 78%{ transform:scale(.97); } 100%{ opacity:1; transform:none; } }
@keyframes blkIn{ 0%{ opacity:0; transform:translateY(26px) scale(.98); } 60%{ opacity:1; transform:translateY(-4px); } 100%{ opacity:1; transform:none; } }
@keyframes wordIn{ 0%{ opacity:0; transform:translateY(.7em) rotate(4deg) scale(.8); } 60%{ opacity:1; transform:translateY(-.12em) rotate(-1deg) scale(1.04); } 100%{ opacity:1; transform:none; } }
@keyframes segPop{ 0%{ transform:scale(.8); } 55%{ transform:scale(1.12); } 100%{ transform:scale(1); } }
@keyframes radioPop{ 0%{ transform:scale(0); } 60%{ transform:scale(1.5); } 100%{ transform:scale(1); } }
@keyframes menuPop{ 0%{ opacity:0; transform:scale(.7) translateY(-8px); } 60%{ opacity:1; transform:scale(1.04); } 100%{ opacity:1; transform:none; } }
@keyframes wiggle{ 0%,100%{ transform:rotate(0); } 25%{ transform:rotate(-3deg); } 75%{ transform:rotate(3deg); } }

/* hero words: pop in, then sweep red > purple > mosaic, then settle back to the normal text colour */
@keyframes wordSweep{
    0%   { color:var(--red); }
    22%  { color:var(--red); }
    38%  { color:var(--purple); }
    80%  { color:var(--purple); }
    100% { color:var(--ui-text); }
}
@keyframes mosaicSweep{
    0%, 44% { opacity:0; background-position:0 0; }
    56%     { opacity:1; }
    78%     { opacity:1; }
    96%,100%{ opacity:0; background-position:16px 8px; }
}
.is-ready #real{ animation:none; }
.is-ready #real > section, .is-ready #real > .hero{ animation:blkIn .75s var(--bounce) both; }
.is-ready #real > section:nth-of-type(1){ animation-delay:55ms; }
.is-ready #real > section:nth-of-type(2){ animation-delay:110ms; }
.is-ready #real > section:nth-of-type(3){ animation-delay:165ms; }
.is-ready #real > section:nth-of-type(4){ animation-delay:220ms; }
.hero h1 .w{ position:relative; display:inline-block; white-space:pre; animation:wordIn .7s var(--bounce) both, wordSweep 2.4s ease both; animation-delay:var(--d, 0ms); }
.hero h1 .w::after{
    content:attr(data-w); position:absolute; left:0; top:0; width:100%; height:100%; white-space:pre; pointer-events:none;
    background:repeating-conic-gradient(#FF2D2D 0 25%, #8b3dff 0 50%, #ff6fb1 0 75%, #3b1d8f 0 100%);
    background-size:8px 8px;
    -webkit-background-clip:text; background-clip:text;
    -webkit-text-fill-color:transparent; color:transparent;
    opacity:0; animation:mosaicSweep 2.4s linear both; animation-delay:var(--d, 0ms);
}
.chip{ transition:background .18s, transform .35s var(--bounce), border-color .18s, box-shadow .2s; }
@media (hover:hover){ .chip:hover{ transform:translateY(-2px) scale(1.03); } }
.chip:active{ transform:scale(.9); transition-duration:.08s; }
.chip.pop{ animation:popIn .65s var(--bounce) both; }
.stagger .chip{ animation:popIn .6s var(--bounce) both; }
.stagger .chip:nth-child(1){ animation-delay:45ms; }
.stagger .chip:nth-child(2){ animation-delay:90ms; }
.stagger .chip:nth-child(3){ animation-delay:135ms; }
.stagger .chip:nth-child(4){ animation-delay:180ms; }
.stagger .chip:nth-child(5){ animation-delay:225ms; }
.stagger .chip:nth-child(6){ animation-delay:270ms; }
.stagger .chip:nth-child(7){ animation-delay:315ms; }
.stagger .chip:nth-child(8){ animation-delay:360ms; }
.stagger .chip:nth-child(9){ animation-delay:405ms; }
.stagger .chip:nth-child(10){ animation-delay:450ms; }
.stagger .chip:nth-child(11){ animation-delay:495ms; }
.stagger .chip:nth-child(12){ animation-delay:540ms; }
.stagger .chip:nth-child(13){ animation-delay:585ms; }
.stagger .chip:nth-child(14){ animation-delay:630ms; }
.stagger .chip:nth-child(15){ animation-delay:675ms; }
.chip.sel i{ transition:transform .3s var(--bounce); }
.chip.sel:hover i{ animation:wiggle .5s ease; }
.sw::after{ transition:transform .4s var(--bounce), width .2s; }
.sw:active::after{ width:27px; }
.sw.on:active::after{ transform:translateX(13px); }
.seg button{ transition:background .18s, color .18s, transform .3s var(--bounce); }
.seg button:active{ transform:scale(.88); }
.seg button.on{ animation:segPop .45s var(--bounce); }
.opt{ transition:border-color .18s, background .18s, transform .35s var(--bounce); }
.opt:active{ transform:scale(.975); }
.opt.on .radio::after{ animation:radioPop .45s var(--bounce); }
.range::-webkit-slider-thumb{ transition:transform .3s var(--bounce); }
.range:active::-webkit-slider-thumb{ transform:scale(1.3); }
.u-modal-card{ animation:acSlideUp .55s var(--bounce); }
@keyframes acSlideUp{ from{ transform:translateY(90px) scale(.96); opacity:0; } to{ transform:none; opacity:1; } }
#toast{ transition:opacity .22s ease, transform .5s var(--bounce); transform:translateX(-50%) translateY(30px) scale(.9); }
#toast.show{ transform:translateX(-50%) translateY(0) scale(1); }
.row{ transition:transform .3s var(--bounce); }
.x-btn{ transition:background .2s, color .2s, transform .3s var(--bounce); }
.x-btn:active{ transform:scale(.8) rotate(8deg); }
.lchip{ animation:popIn .55s var(--bounce) both; }

/* ═════════ SMART SUMMARY, FEED STYLES ═════════ */
#real > section{ scroll-margin-top:calc(var(--topbar-h) + 8px); }
.glance{ display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; padding:0 var(--pad) 18px; }
.gl{ background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:10px 6px 9px; text-align:center; animation:popIn .65s var(--bounce) both; }
.gl:nth-child(2){ animation-delay:.07s; } .gl:nth-child(3){ animation-delay:.14s; } .gl:nth-child(4){ animation-delay:.21s; }
.gl b{ display:block; font-size:clamp(1.05rem, 4.6vw, 1.35rem); font-weight:800; letter-spacing:-.4px; line-height:1.1; }
.gl span{ display:block; margin-top:3px; font-size:.62rem; color:var(--muted); font-weight:600; line-height:1.3; }
.styles{ display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; }
.sty{ text-align:left; padding:13px 11px 12px; border-radius:var(--r); background:var(--surface); border:1.5px solid var(--line2); display:flex; flex-direction:column; gap:6px; transition:transform .35s var(--bounce), border-color .18s, background .18s; }
.sty i{ font-size:1.25rem; color:var(--muted); transition:transform .4s var(--bounce), color .18s; }
.sty b{ font-size:.84rem; font-weight:700; }
.sty small{ font-size:.64rem; line-height:1.45; color:var(--muted); }
.sty:hover{ transform:translateY(-3px); }
.sty:active{ transform:scale(.93); }
.sty.on{ border-color:var(--red); background:rgba(255,45,45,.08); }
.sty.on i{ color:var(--red); transform:scale(1.2) rotate(-8deg); }

/* ═════════ TOPIC CHIP MENU (tap a picked topic) ═════════ */
#chipMenu{ position:absolute; z-index:9000; width:min(268px, calc(100vw - 24px)); background:var(--solid); border:1px solid var(--line2); border-radius:3px; padding:4px; box-shadow:0 18px 50px rgba(0,0,0,.65); display:none; transform-origin:top left; pointer-events:auto; touch-action:manipulation; }
#chipMenu.open{ display:block; animation:menuPop .45s var(--bounce); }
#chipMenu button{ position:relative; z-index:1; width:100%; display:flex; align-items:center; gap:14px; padding:13px 14px; border-radius:2px; text-align:left; font-size:.86rem; font-weight:600; color:var(--ui-text); transition:background .15s, transform .3s var(--bounce); touch-action:manipulation; }
#chipMenu button i{ font-size:1.25rem; flex-shrink:0; pointer-events:none; }
#chipMenu button:hover{ background:rgba(var(--fg),.07); }
#chipMenu button:active{ transform:scale(.95); }
#chipMenu button.danger{ color:var(--danger2); }
#chipMenu .cm-why{ padding:12px 14px 8px; font-size:.78rem; line-height:1.6; color:var(--sub); }
#chipMenu .cm-why b{ color:var(--ui-text); }

/* ── undo bar ── */
#undoBar{
    position:fixed; bottom:92px; left:50%; transform:translateX(-50%) translateY(30px) scale(.9);
    width:min(360px, calc(100vw - 32px)); display:flex; align-items:center; gap:12px; overflow:hidden;
    padding:9px 9px 11px 16px; background:var(--pop-bg); border:1px solid var(--line2); border-radius:var(--r);
    box-shadow:0 8px 24px rgba(0,0,0,.6); opacity:0; pointer-events:none; z-index:9999999;
    transition:opacity .22s ease, transform .5s var(--bounce);
}
#undoBar.show{ opacity:1; pointer-events:auto; transform:translateX(-50%) translateY(0) scale(1); }
#undoBar #ubMsg{ flex:1; min-width:0; font-size:.78rem; font-weight:500; color:var(--ui-text); line-height:1.4; }
#undoBar button{ height:32px; padding:0 16px; border-radius:5px; background:var(--red); color:#fff; font-size:.76rem; font-weight:700; flex-shrink:0; transition:transform .3s var(--bounce), background .2s; }
#undoBar button:hover{ background:#e02222; }
#undoBar button:active{ transform:scale(.9); }
#undoBar #ubTime{ position:absolute; left:0; bottom:0; height:3px; width:100%; background:var(--red); transform-origin:left center; }
#undoBar.show #ubTime{ animation:ubShrink 6s linear forwards; }
@keyframes ubShrink{ from{ transform:scaleX(1); } to{ transform:scaleX(0); } }

/* ── share / copy settings ── */
.code-box{ display:block; width:100%; margin-top:10px; padding:12px 13px; resize:none; background:var(--surface); border:1px solid var(--line2); border-radius:var(--r); color:var(--ui-text); font-size:.76rem; line-height:1.55; word-break:break-all; outline:none; transition:border-color .2s, box-shadow .2s; -webkit-appearance:none; appearance:none; }
.code-box::placeholder{ color:var(--muted); }
.code-box:focus{ border-color:rgba(255,45,45,.7); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
.code-actions{ display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }

/* phones zoom the page when a text box under 16px gets focus; 16px stops that (pinch-zoom itself still works) */
@media (max-width:768px){ .u-modal-search, input[type="text"], input[type="search"], textarea, select{ font-size:16px; } }

@media (prefers-reduced-motion:reduce){ *{ animation:none !important; transition:none !important; } html{ scroll-behavior:auto; } }

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the Community and Games pages. The red accent stays the same in both themes. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --solid:#ffffff;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.05);
    --surface2:rgba(6,7,26,.09);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --card-bg:#ffffff;
    --pop-bg:#ffffff;
    --scrim:rgba(6,7,26,.45);
    --ph:#eceef5;
    --ok:#15a34a;
    --danger:#d92b2b;
    --danger2:#d92b2b;
}
[data-theme="light"] #toast, [data-theme="light"] #undoBar, [data-theme="light"] #chipMenu{ box-shadow:0 8px 24px rgba(0,0,0,.18); }
[data-theme="light"] #toast{ border-color:rgba(21,163,74,.35); }
[data-theme="light"] #toast.err{ border-color:rgba(217,43,43,.4); }
[data-theme="light"] .ac-lane.dragging{ box-shadow:-14px 0 36px rgba(0,0,0,.18); }
</style>
</head>
<body>

<div id="swipeShade" aria-hidden="true"></div>

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" data-do="back" aria-label="Back">
            <svg class="ac-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linejoin="miter" stroke-linecap="butt"/></svg>
        </button>
        <span class="ac-title">Your Algorithm</span>
    </div>

    <!-- ══ SKELETON (shown while the page gets ready) ══ -->
    <div id="skel" aria-hidden="true">
        <div class="sk-hero"><span class="sk sk-t1"></span><span class="sk sk-t2"></span><span class="sk sk-p" style="width:92%;margin-top:18px"></span><span class="sk sk-p" style="width:68%"></span></div>
        <div class="sk-glance"><span class="sk sk-gl"></span><span class="sk sk-gl"></span><span class="sk sk-gl"></span><span class="sk sk-gl"></span></div>
        <div class="sk-blk"><span class="sk sk-h"></span><span class="sk sk-p" style="width:74%"></span><div class="sk-chips"><span class="sk sk-chip" style="width:92px"></span><span class="sk sk-chip" style="width:118px"></span><span class="sk sk-chip" style="width:84px"></span><span class="sk sk-chip" style="width:104px"></span><span class="sk sk-chip" style="width:70px"></span></div></div>
        <div class="sk-blk"><span class="sk sk-h" style="width:50%"></span><span class="sk sk-p" style="width:82%"></span><div class="sk-chips"><span class="sk sk-chip" style="width:100px"></span><span class="sk sk-chip" style="width:76px"></span><span class="sk sk-chip" style="width:112px"></span></div></div>
        <div class="sk-blk"><span class="sk sk-h" style="width:36%"></span>
            <div class="sk-row"><span class="sk sk-ic"></span><div class="sk-lines"><span class="sk sk-p" style="width:42%;margin:0"></span><span class="sk sk-p" style="width:28%;margin-top:8px"></span></div></div>
            <div class="sk-row"><span class="sk sk-ic"></span><div class="sk-lines"><span class="sk sk-p" style="width:36%;margin:0"></span><span class="sk sk-p" style="width:22%;margin-top:8px"></span></div></div>
            <div class="sk-row"><span class="sk sk-ic"></span><div class="sk-lines"><span class="sk sk-p" style="width:46%;margin:0"></span><span class="sk sk-p" style="width:30%;margin-top:8px"></span></div></div>
            <div class="sk-row"><span class="sk sk-ic"></span><div class="sk-lines"><span class="sk sk-p" style="width:32%;margin:0"></span><span class="sk sk-p" style="width:24%;margin-top:8px"></span></div></div>
            <div class="sk-row"><span class="sk sk-ic"></span><div class="sk-lines"><span class="sk sk-p" style="width:40%;margin:0"></span><span class="sk sk-p" style="width:26%;margin-top:8px"></span></div></div>
        </div>
    </div>

    <div id="real">

    <!-- ══ HERO ══ -->
    <div class="hero">
        <h1 id="heroText"></h1>
        <p>These choices only change your own feed. Nobody else sees them, and every change saves the moment you make it.</p>
    </div>

    <!-- ══ AT A GLANCE ══ -->
    <div class="glance" id="glance"></div>

    <!-- ══ MORE OF ══ -->
    <section class="blk" id="secMore">
        <h2 class="blk-h">What you want to see more of</h2>
        <button type="button" class="blk-sub" data-do="modal" data-m="howModal">A #hashtag or any word, like glutes, football or skincare <i class="ri-arrow-right-s-line"></i></button>
        <div class="blk-body"><div class="chips" id="moreChips"></div></div>
    </section>

    <!-- ══ LESS OF ══ -->
    <section class="blk" id="secLess">
        <h2 class="blk-h">What you want to see less of</h2>
        <div class="blk-sub">Your choice beats what you liked before. These topics show up much less, but they are not blocked, so you may still see the odd one.</div>
        <div class="blk-body"><div class="chips" id="lessChips"></div></div>
    </section>

    <!-- ══ EVERYTHING ELSE: CATEGORISED LIST (tap a row to open it) ══ -->
    <section class="blk" id="secMenu">
        <div id="menuBox"></div>
    </section>

    </div><!-- /#real -->
</div>

<!-- ══ AREA SHEET (opens when a list row is tapped; each pane keeps the controls for that area) ══ -->
<div class="u-modal-overlay" id="paneModal" data-do="backdrop">
    <div class="u-modal-card tall">
        <div class="u-modal-header">
            <span class="u-modal-title" id="paneTitle"></span>
            <button type="button" class="u-modal-close" data-do="close" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="pane-scroll" id="paneScroll">

            <!-- Feed style -->
            <div class="pane" id="pane_style" hidden>
                <div class="pane-sub">One tap sets the dials for you. You can still fine-tune everything else.</div>
                <div class="styles" id="styleBox"></div>
            </div>

            <!-- Creators -->
            <div class="pane" id="pane_creators" hidden>
                <div class="pane-sub">Show more of the people you love, or quieter feeds from the ones you don't.</div>
                <div class="rows" id="creatorRows"></div>
                <div class="chips" style="margin-top:6px"><button type="button" class="chip out" data-do="picker" data-k="creator" data-p="more"><i class="ri-add-line"></i><span>Add creator</span></button></div>
            </div>

            <!-- Formats -->
            <div class="pane" id="pane_formats" hidden>
                <div class="pane-sub">Lean your feed towards the kind of posts you enjoy most.</div>
                <div class="rows" id="typeRows"></div>
            </div>

            <!-- Sensitive content -->
            <div class="pane" id="pane_sensitive" hidden>
                <div class="pane-sub">Posts that may be sexually suggestive, graphic, or about things like drugs and self-harm.</div>
                <div class="opts" id="opt_sensitive_level">
                    <button type="button" class="opt" data-do="seg" data-f="sensitive_level" data-v="0"><span class="opt-txt"><b>Less</b><small>Fewer of these posts in your feed.</small></span><span class="radio"></span></button>
                    <button type="button" class="opt" data-do="seg" data-f="sensitive_level" data-v="1"><span class="opt-txt"><b>Standard</b><small>Some of these posts can show up. This is the default.</small></span><span class="radio"></span></button>
                    <button type="button" class="opt" data-do="seg" data-f="sensitive_level" data-v="2"><span class="opt-txt"><b>More</b><small>You may see more of these posts.</small></span><span class="radio"></span></button>
                </div>
            </div>

            <!-- Political content -->
            <div class="pane" id="pane_political" hidden>
                <div class="pane-sub">Posts about elections, politicians, parties, protests and government policy.</div>
                <div class="opts" id="opt_political_level">
                    <button type="button" class="opt" data-do="seg" data-f="political_level" data-v="0"><span class="opt-txt"><b>Less</b><small>Fewer political posts in your feed.</small></span><span class="radio"></span></button>
                    <button type="button" class="opt" data-do="seg" data-f="political_level" data-v="1"><span class="opt-txt"><b>Standard</b><small>Political posts show up like any other topic. This is the default.</small></span><span class="radio"></span></button>
                    <button type="button" class="opt" data-do="seg" data-f="political_level" data-v="2"><span class="opt-txt"><b>More</b><small>You may see more political posts.</small></span><span class="radio"></span></button>
                </div>
            </div>

            <!-- Adventure -->
            <div class="pane" id="pane_adventure" hidden>
                <div class="pane-sub">Choose between more of what you already like and genuinely different finds: other topics, other creators and other formats.</div>
                <div class="adv-val"><b id="advLabel">Balanced</b><span id="advDesc"></span></div>
                <input type="range" class="range" id="advRange" min="0" max="100" step="1" value="50" aria-label="How adventurous your feed is">
                <div class="range-ends"><span>Familiar</span><span>Balanced</span><span>Adventurous</span></div>
            </div>

            <!-- Feed controls -->
            <div class="pane" id="pane_controls" hidden>
                <div class="ctl">
                    <div class="ctl-head">
                        <div class="ctl-txt"><div class="ctl-title">Haptics</div><div class="ctl-desc">A vibration on every touch and every bounce, and a big buzz when the headline sweeps in colour. Saved on this device only. Android phones vibrate fully. iPhone only allows a light tick in the browser.</div></div>
                        <button type="button" class="sw" id="sw_fx" role="switch" data-do="fxsw" aria-label="Haptics"></button>
                    </div>
                </div>
                <div class="ctl">
                    <div class="ctl-head">
                        <div class="ctl-txt"><div class="ctl-title">Show more local content</div><div class="ctl-desc" id="localDesc">Lift posts from people in the same place as you.</div></div>
                        <button type="button" class="sw" id="sw_local_content" role="switch" data-do="sw" data-f="local_content" aria-label="Show more local content"></button>
                    </div>
                </div>
                <div class="ctl">
                    <div class="ctl-head">
                        <div class="ctl-txt"><div class="ctl-title">Learn from my likes, saves and reposts</div><div class="ctl-desc">When this is off, your feed stops learning new taste from what you do. Your choices still count.</div></div>
                        <button type="button" class="sw" id="sw_personalize" role="switch" data-do="sw" data-f="personalize" aria-label="Learn from my likes, saves and reposts"></button>
                    </div>
                </div>
                <div class="ctl">
                    <div class="ctl-head">
                        <div class="ctl-txt"><div class="ctl-title">Learn from time spent on posts</div><div class="ctl-desc">Posts you scroll past in a second count as a little less of that. Posts you stay on count as a little more.</div></div>
                        <button type="button" class="sw" id="sw_use_watch_time" role="switch" data-do="sw" data-f="use_watch_time" aria-label="Learn from time spent on posts"></button>
                    </div>
                </div>
                <div class="ctl">
                    <div class="ctl-title">People you track</div>
                    <div class="ctl-desc">Less lowers their posts a little. Balanced and More lift them.</div>
                    <div class="seg full" id="seg_follow_level">
                        <button type="button" data-do="seg" data-f="follow_level" data-v="0">Less</button>
                        <button type="button" data-do="seg" data-f="follow_level" data-v="1">Balanced</button>
                        <button type="button" data-do="seg" data-f="follow_level" data-v="2">More</button>
                    </div>
                </div>
                <div class="ctl">
                    <div class="ctl-title">Posts you've already seen</div>
                    <div class="ctl-desc">Whether posts you have scrolled past can come back.</div>
                    <div class="seg full" id="seg_novelty_level">
                        <button type="button" data-do="seg" data-f="novelty_level" data-v="0">Can return</button>
                        <button type="button" data-do="seg" data-f="novelty_level" data-v="1">Balanced</button>
                        <button type="button" data-do="seg" data-f="novelty_level" data-v="2">Fresh only</button>
                    </div>
                </div>
            </div>

            <!-- Learned -->
            <div class="pane" id="pane_learned" hidden>
                <div class="pane-sub">What your feed has picked up so far. Your own choices always outrank this. Tap × to make it forget something.</div>
                <div id="learnedBox"></div>
            </div>

            <!-- Share or copy settings -->
            <div class="pane" id="pane_share" hidden>
                <div class="pane-sub">Your topics, creators, formats and dials as one short code. Keep it to get your setup back after a reset, or paste it on a second account. It does not include what your feed has learned.</div>
                <div class="ctl-title">Your code</div>
                <textarea class="code-box" id="codeOut" rows="3" readonly spellcheck="false" aria-label="Your settings code"></textarea>
                <div class="code-actions">
                    <button type="button" class="chip" data-do="copycode"><i class="ri-file-copy-line"></i><span>Copy code</span></button>
                    <button type="button" class="chip out" id="shareBtn" data-do="sharecode" hidden><i class="ri-share-forward-line"></i><span>Share</span></button>
                </div>
                <div class="ctl-title" style="margin-top:26px">Use a code</div>
                <div class="ctl-desc">Paste a code to replace your current choices and dials with the ones in it.</div>
                <textarea class="code-box" id="codeIn" rows="3" placeholder="Paste a code here" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="Paste a settings code"></textarea>
                <div class="code-actions">
                    <button type="button" class="chip out" data-do="applycode"><i class="ri-download-2-line"></i><span>Apply code</span></button>
                </div>
            </div>

            <!-- Reset -->
            <div class="pane" id="pane_reset" hidden>
                <button type="button" class="act" data-do="reset" data-w="taste">
                    <span class="row-ic"><i class="ri-restart-line"></i></span>
                    <span class="row-info"><span class="row-name">Reset what my feed has learned</span><div class="row-sub">Clears the taste picked up from your activity. Your choices stay.</div></span>
                    <i class="ri-arrow-right-s-line chev"></i>
                </button>
                <button type="button" class="act" data-do="reset" data-w="choices">
                    <span class="row-ic"><i class="ri-equalizer-line"></i></span>
                    <span class="row-info"><span class="row-name">Reset my choices</span><div class="row-sub">Removes every topic, creator and format you picked and puts all dials back to the middle.</div></span>
                    <i class="ri-arrow-right-s-line chev"></i>
                </button>
                <button type="button" class="act" id="undoNi" data-do="hidden">
                    <span class="row-ic"><i class="ri-eye-off-line"></i></span>
                    <span class="row-info"><span class="row-name">Posts I marked "Not interested"</span><div class="row-sub" id="niSub"></div></span>
                    <i class="ri-arrow-right-s-line chev"></i>
                </button>
            </div>

        </div>
    </div>
</div>

<!-- ══ PICKER MODAL (add topic / creator) ══ -->
<div class="u-modal-overlay" id="pickerModal" data-do="backdrop">
    <div class="u-modal-card">
        <div class="u-modal-header">
            <span class="u-modal-title" id="pkTitle">Add</span>
            <button type="button" class="u-modal-close" data-do="close" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="u-modal-search-wrap">
            <i class="ri-search-line u-modal-search-icon"></i>
            <input type="text" class="u-modal-search" id="pkInput" autocomplete="off" autocapitalize="off" spellcheck="false">
        </div>
        <div class="u-modal-list" id="pkList"></div>
    </div>
</div>

<!-- ══ HOW IT LEARNS MODAL ══ -->
<div class="u-modal-overlay" id="howModal" data-do="backdrop">
    <div class="u-modal-card">
        <div class="u-modal-header">
            <span class="u-modal-title"><i class="ri-information-line" style="color:var(--red)"></i> How your feed learns</span>
            <button type="button" class="u-modal-close" data-do="close" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="u-modal-body">
            <p>Your feed learns from what you like, save, repost and spend time on. <strong>The topics, creators and formats you pick here always count more than what you did before.</strong></p>
            <p>If you pick <strong>Less</strong> of a topic, posts about it show up much less often, but they are not blocked. You may still see the odd one.</p>
            <p><strong>Interested</strong> (the button that sometimes pops up under a suggested post) tells your feed to show you more like it, about as strongly as a like.</p>
            <p><strong>Not interested</strong> lowers similar posts a little, and that fades after a week or two. One post never switches off a whole topic, and you can bring any post back from the Reset area.</p>
            <p>Old taste fades over a few weeks, so your feed follows what you like now. Discovery posts come from topics, creators and formats you don't usually see. Only you see these choices.</p>
            <div class="wt" id="wtBox" hidden></div>
        </div>
    </div>
</div>

<!-- ══ HIDDEN POSTS MODAL ══ -->
<div class="u-modal-overlay" id="hiddenModal" data-do="backdrop">
    <div class="u-modal-card">
        <div class="u-modal-header">
            <span class="u-modal-title">Posts you marked "Not interested"</span>
            <button type="button" class="u-modal-close" data-do="close" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="u-modal-list" id="hdList"></div>
        <div class="hd-foot" id="hdFoot" hidden><button type="button" data-do="reset" data-w="ni">Bring all of them back</button></div>
    </div>
</div>

<!-- ══ CONFIRM MODAL (keep last, so it always sits on top) ══ -->
<div class="u-modal-overlay" id="confirmModal" data-do="backdrop">
    <div class="u-modal-card">
        <div class="u-modal-header">
            <span class="u-modal-title" id="cfTitle"></span>
            <button type="button" class="u-modal-close" data-do="close" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="u-modal-body">
            <p id="cfText"></p>
            <div class="cf-actions"><button type="button" class="cf-cancel" data-do="close">Cancel</button><button type="button" class="cf-ok" id="cfOk">Confirm</button></div>
        </div>
    </div>
</div>

<div id="chipMenu" role="menu" aria-label="Topic options"></div>
<div id="toast" role="status" aria-live="polite"></div>
<div id="undoBar" role="status" aria-live="polite"><span id="ubMsg"></span><button type="button" data-do="undo">Undo</button><i id="ubTime"></i></div>

<script>
/* keep the theme in sync if it is switched in another tab */
window.addEventListener('storage', function(e){
    if(e.key !== 'dz_theme') return;
    if(e.newValue === 'light') document.documentElement.setAttribute('data-theme','light');
    else document.documentElement.removeAttribute('data-theme');
});

var D = <?php echo $boot_json; ?>;
var CSRF = D.csrf, PREFS = D.prefs, LEARNED = D.learned, SET = D.settings, HIDDEN = D.hidden, LOCATION = D.location || '';
if(SET.local_content == null) SET.local_content = 0;
if(SET.sensitive_level == null) SET.sensitive_level = 1;
if(SET.political_level == null) SET.political_level = 1;
var TYPES = [
    ['image',  'ri-image-line',         'Images'],
    ['video',  'ri-movie-2-line',       'Videos'],
    ['gif',    'ri-file-gif-line',      'GIFs'],
    ['sticker','ri-emotion-happy-line', 'Stickers'],
    ['text',   'ri-text',               'Text posts']
];
var DEFAULT_PIC = 'uploads/me.png';

function $(id){ return document.getElementById(id); }
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function cleanTopic(s){
    s = String(s || '').toLowerCase().trim();
    var hash = s.charAt(0) === '#';
    s = s.replace(/^#+/, '').replace(/[^\p{L}\p{N}_\- ]/gu, '').replace(/\s+/g, ' ').trim();
    if(hash) s = s.replace(/ /g, '');
    s = s.slice(0, hash ? 39 : 40);
    return s === '' ? '' : (hash ? '#' + s : s);
}
function typeName(k){ var t = TYPES.filter(function(x){ return x[0] === k; })[0]; return t ? t[2] : k; }

/* ── server calls ── */
function api(act, data){
    var fd = new FormData();
    fd.append('act', act); fd.append('csrf', CSRF);
    for(var k in (data || {})) fd.append(k, data[k]);
    return fetch(location.pathname, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .catch(function(){ return { ok:false, error:'No connection. Check your internet and try again.' }; });
}

/* ── HAPTICS (vibration only, no sounds): every touch, every bounce, and a crazy-strong buzz for the hero colour sweep ──
   One switch (Feed controls → Haptics) controls all of it, saved on this device.

   v15 rules:
   1) While the hero sweep buzz is playing, NOTHING else may vibrate (a new vibrate() call replaces the running one).
   2) The moment the sweep pattern ends, a timer lifts that lock, so every touch / bounce / switch / slider tick vibrates again.
   3) The lock is only set when the browser really accepted the sweep vibration, so a blocked sweep can never leave buttons silent.
   4) Normal taps are 15-35 ms: 10 ms pulses are too short for most phone motors to be felt at all. */
var FX = { on:true, last:0, heroUntil:0, heroTimer:null, ios:null, touching:false };
try{ FX.on = (localStorage.getItem('dz_fx') !== '0'); }catch(e){}

/* iPhone Safari has no vibrate(): iOS 17.4+ gives a small tick when a hidden switch is clicked. Best effort, light only. */
function iosTick(){
    try{
        if(!FX.ios){
            var l = document.createElement('label'), i = document.createElement('input');
            l.setAttribute('aria-hidden', 'true');
            l.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none';
            i.type = 'checkbox'; i.setAttribute('switch', '');
            l.appendChild(i); document.body.appendChild(l); FX.ios = l;
        }
        FX.ios.click();
    }catch(e){}
}
/* THE MENU BUG FIX: the iPhone tick above works by clicking a hidden switch, and that is a REAL click event that reaches the page.
   The "tap anywhere else closes the topic menu" handler saw it as an outside tap and closed the menu a moment before your finger
   lifted, so Why / Move / Remove never ran. This listener is registered first and swallows every click that comes from the haptic switch. */
document.addEventListener('click', function(e){
    if(FX.ios && e.target && (e.target === FX.ios || FX.ios.contains(e.target))){ e.stopImmediatePropagation(); e.stopPropagation(); }
}, true);
/* p = a number (ms) or a pattern [on, off, on, off…]. Pulses under 10 ms are lifted to 10 so phones actually feel them.
   force = true is only used by the hero sweep itself (and error buzzes); everything else is muted while the sweep plays.
   Returns true when the browser accepted the vibration (always true on iPhone, where it is best effort). */
function buzz(p, force){
    if(!FX.on) return false;
    if(!force && Date.now() < FX.heroUntil) return false;
    if(typeof p === 'number') p = [p];
    var q = p.map(function(n, i){ return (i % 2 === 0) ? Math.max(n, 10) : n; });
    FX.last = Date.now();
    if(navigator.vibrate){
        var ok = false;
        try{ ok = navigator.vibrate(q); }catch(e){ ok = false; }
        return !!ok;
    }
    /* iPhone: one tick every ~35 ms across each "on" pulse, so a long pulse feels like a long rumble */
    var t = 0, ticks = 0, cap = force ? 120 : 40;
    q.forEach(function(n, i){
        if(i % 2 === 0){ for(var s = 0; s < n && ticks < cap; s += 35){ ticks++; setTimeout(iosTick, t + s); } }
        t += n;
    });
    return true;
}
var HP = {            /* normal haptics: short, but long enough to really be felt. Only the headline sweep is the strong one. */
    press:  [20],
    tick:   [20],
    pop:    [25],
    down:   [25],
    heavy:  [35],
    success:[25],
    bounce: [15],
    error:  [30, 60, 30]     /* a small double tap for error messages */
};
function fx(kind){
    var k = HP[kind] ? kind : 'tick';
    if(k !== 'error' && FX.touching) return;   /* the finger-down tap already happened: no second buzz for the same touch */
    buzz(HP[k], k === 'error');
}
/* lifts the "sweep is playing" lock (called by the timer at the end of the sweep, and by a replay before it starts) */
function heroRelease(){
    clearTimeout(FX.heroTimer); FX.heroTimer = null;
    FX.heroUntil = 0; FX.touching = false;
}

/* ── THE BIG ONE: "Recently you've been into…" ──
   Runs the moment the first word starts to pop in, so it is in time with the animation (2.4 s per word, words 60 ms apart):
   1) ~0.7 s   words pop in while red: firm rolling taps
   2) ~0.3 s   red turns purple: two hard punches
   3) the mosaic wave: long, almost unbroken full-power bursts for as long as the wave takes to cross all the words
   4) settle to white: fading pulses
   When it is over a timer lifts the lock and all normal haptics work again. */
function heroHaptics(){
    if(!FX.on) return;
    heroRelease();
    if(navigator.vibrate){ try{ navigator.vibrate(0); }catch(e){} }      /* stop anything still running so the sweep starts clean */
    var n = document.querySelectorAll('#heroText .w').length;
    var maxD = Math.min(Math.max(n - 1, 0) * 60, 1000), P = [], i;
    function z(on, off){ P.push(on, off); }
    for(i = 0; i < 8; i++) z(60, 30);                     /* 1) red: rolling taps (~720 ms) */
    z(130, 30); z(130, 30);                               /* 2) purple: two hard punches (~320 ms) */
    var wave = 1240 + maxD;                               /* 3) mosaic: continuous, as long as the colour wave */
    while(wave > 0){ var on = Math.min(600, wave); z(on, 15); wave -= on + 15; }
    z(140, 45); z(100, 45); z(70, 45); z(45, 45); z(25, 0);   /* 4) settle */
    if(P.length > 98) P.length = 98;
    var total = P.reduce(function(a, b){ return a + b; }, 0);
    var accepted = buzz(P, true);
    if(!accepted) return;                                  /* browser refused it (no touch yet): do not lock anything */
    FX.heroUntil = Date.now() + total;
    FX.heroTimer = setTimeout(heroRelease, total + 30);    /* the sweep is over: every touch and bounce vibrates again */
}

/* every touch on a button / row / chip: a tap the moment the finger lands (touch screens only) */
document.addEventListener('pointerdown', function(e){
    if(e.pointerType === 'mouse' || !FX.on) return;
    var t = e.target.closest && e.target.closest('button, a, [data-do], .chip, .opt, .mrow, .sty, .pk-row, .act');
    if(!t || t.disabled || t.id === 'advRange') return;
    FX.touching = true; setTimeout(function(){ FX.touching = false; }, 450);
    buzz(HP.press);
}, true);

/* every bounce: a tap in time with the animation (chips popping, segments, radios, menus, sheets, sections) */
var ANIM_H = { popIn:HP.bounce, segPop:HP.bounce, radioPop:HP.bounce, menuPop:HP.bounce, blkIn:HP.bounce, acSlideUp:HP.bounce };
document.addEventListener('animationstart', function(e){
    var n = e.animationName, el = e.target;
    if(n === 'wordIn' && el.classList && el.classList.contains('w') && !el.previousElementSibling){ heroHaptics(); return; }
    var h = ANIM_H[n]; if(!h || !FX.on) return;
    if(Date.now() < FX.heroUntil) return;                               /* never cut the hero buzz short */
    if(n === 'popIn' && el.classList && el.classList.contains('gl')) return;   /* the 4 counters re-pop on every change: too busy */
    var now = Date.now(); if(now - FX.last < 55) return;
    buzz(h);
}, true);

/* tap the headline to replay the animation + buzz (also the way to start it if the phone wanted a tap first) */
document.addEventListener('click', function(e){
    var h = e.target.closest && e.target.closest('#heroText'); if(!h) return;
    HERO_ANIM = true; h.dataset.t = ''; renderHero();
});

/* ── settings code (also what Undo restores from) ── */
var SETKEYS = ['adventure', 'follow_level', 'novelty_level', 'personalize', 'use_watch_time', 'sensitive_level', 'political_level', 'local_content'];
function makeCode(prefs, set){
    var s = {};
    SETKEYS.forEach(function(k){ if(set[k] != null) s[k] = set[k]; });
    var json = JSON.stringify({ v:1, s:s, p:prefs.map(function(p){ return [p.kind, p.item, p.pref]; }) });
    var b64 = btoa(unescape(encodeURIComponent(json))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    return 'DZA1-' + b64;
}
function applyState(r){
    PREFS = r.prefs || []; SET = r.settings || SET;
    if(SET.local_content == null) SET.local_content = 0;
    if(SET.sensitive_level == null) SET.sensitive_level = 1;
    if(SET.political_level == null) SET.political_level = 1;
    renderAll();
}

/* ── undo bar: a change remembers how things were just before it; the next success message becomes "…  Undo" ── */
var PENDING = null, UB = { t:null, code:null };
function beginChange(){ PENDING = { code:makeCode(PREFS, SET), t:Date.now() }; }
function showUndo(msg, code){
    var b = $('undoBar'); UB.code = code; $('ubMsg').textContent = msg;
    $('toast').className = '';
    b.classList.remove('show'); void b.offsetWidth; b.classList.add('show');
    clearTimeout(UB.t); UB.t = setTimeout(hideUndo, 6000);
}
function hideUndo(){ clearTimeout(UB.t); $('undoBar').classList.remove('show'); UB.code = null; }
function doUndo(){
    var c = UB.code; hideUndo(); if(!c) return;
    PENDING = null; fx('pop');
    api('import_settings', { code:c }).then(function(r){
        if(!r.ok){ toast(r.error || 'Could not undo that.', true); return; }
        applyState(r); toast('Undone.');
    });
}

var _tt = null;
function toast(msg, bad){
    if(bad) fx('error');
    var p = PENDING; PENDING = null;
    if(!bad && p && Date.now() - p.t < 8000){ showUndo(msg, p.code); return; }
    hideUndo();
    var t = $('toast'); t.textContent = msg; t.className = bad ? 'err show' : 'show';
    clearTimeout(_tt); _tt = setTimeout(function(){ t.className = bad ? 'err' : ''; }, 2200);
}

/* ── state helpers ── */
function getPref(kind, item){
    for(var i = 0; i < PREFS.length; i++) if(PREFS[i].kind === kind && PREFS[i].item === item) return PREFS[i];
    return null;
}
function setPrefLocal(row){
    var ex = getPref(row.kind, row.item);
    if(ex){ ex.pref = row.pref; ex.label = row.label || ex.label; ex.pic = row.pic || ex.pic; }
    else PREFS.unshift(row);
}
function delPrefLocal(kind, item){
    PREFS = PREFS.filter(function(p){ return !(p.kind === kind && p.item === item); });
}
/* shows the change at once, then confirms with the server (and undoes it if the server says no) */
function addPref(kind, item, pref, label, pic){
    beginChange(); fx(kind === 'tag' ? 'pop' : 'tick');
    var ex = getPref(kind, item);
    var snap = ex ? ex.pref : null;
    setPrefLocal({ kind:kind, item:item, pref:pref, label:label || (ex && ex.label) || item, pic:pic || (ex && ex.pic) || '' });
    renderAll();
    return api('add_pref', { kind:kind, item:item, pref:pref }).then(function(r){
        if(!r.ok){
            if(snap){ var c = getPref(kind, item); if(c) c.pref = snap; } else delPrefLocal(kind, item);
            renderAll(); toast(r.error || 'Could not save that.', true); return false;
        }
        if(r.row.item !== item) delPrefLocal(kind, item);
        setPrefLocal(r.row); renderAll(); return r.row;
    });
}
function removePref(kind, item, msg){
    beginChange(); fx('down');
    var old = getPref(kind, item);
    delPrefLocal(kind, item); renderAll();
    return api('remove_pref', { kind:kind, item:item }).then(function(r){
        if(!r.ok){ if(old) PREFS.unshift(old); renderAll(); toast(r.error || 'Could not remove that.', true); }
        else toast(msg || 'Removed.');
    });
}

/* ── hero: words pop in, sweep red > purple > mosaic (CSS), then settle back to the normal text colour ── */
function joinList(a){ return a.length < 2 ? (a[0] || '') : a.slice(0, -1).join(', ') + ' and ' + a[a.length - 1]; }
var HERO_ANIM = true;
function heroItems(){
    var out = [], seen = {};
    function add(t){ var k = String(t).toLowerCase(); if(t && !seen[k] && out.length < 4){ seen[k] = 1; out.push(t); } }
    LEARNED.filter(function(l){ return l.kind === 'tag' && l.eff > 0; }).forEach(function(l){ add(l.label); });
    LEARNED.filter(function(l){ return l.kind === 'creator' && l.eff > 0; }).forEach(function(l){ add(l.label); });
    LEARNED.filter(function(l){ return l.kind === 'type' && l.eff > 0; }).forEach(function(l){ add(typeName(l.item).toLowerCase()); });
    PREFS.filter(function(p){ return p.pref === 'more' && p.kind !== 'type'; }).forEach(function(p){ add(p.label); });
    return out;
}
function renderHero(){
    var h = $('heroText'), items = heroItems(), txt;
    if(items.length) txt = 'Recently you\u2019ve been into ' + joinList(items) + '.';
    else txt = 'Your feed is still getting to know you. Like, save and follow, or pick topics below.';
    if(h.dataset.t === txt) return;
    h.dataset.t = txt;
    if(!HERO_ANIM){ h.textContent = txt; return; }
    HERO_ANIM = false;
    h.innerHTML = txt.split(/( )/).map(function(w, i){ return w === ' ' ? ' ' : '<span class="w" data-w="' + esc(w) + '">' + esc(w) + '</span>'; }).join('');
    h.querySelectorAll('.w').forEach(function(el, n){ el.style.setProperty('--d', Math.min(n * 60, 1000) + 'ms'); });
}

/* ── topic chips ── */
function suggestions(pref){
    var have = {}; PREFS.forEach(function(p){ if(p.kind === 'tag') have[p.item.replace(/^#/, '')] = 1; });
    return LEARNED.filter(function(l){ return l.kind === 'tag' && !have[l.item] && (pref === 'more' ? l.eff > 0 : l.eff < 0); }).slice(0, 6);
}
var POP = null, STAGGER = true;   /* POP = the topic that was just added / moved (it bounces in) */
function renderChips(pref, boxId){
    var h = '';
    PREFS.forEach(function(p){
        if(p.kind === 'tag' && p.pref === pref){
            var pop = (POP && POP === p.item) ? ' pop' : '';
            h += '<button type="button" class="chip sel' + pop + '" data-do="chipmenu" data-k="tag" data-i="' + esc(p.item) + '" data-p="' + pref + '" aria-haspopup="menu"><i class="ri-pencil-line"></i><span>' + esc(p.label) + '</span></button>';
        }
    });
    suggestions(pref).forEach(function(s){
        h += '<button type="button" class="chip out" data-do="add" data-k="tag" data-i="' + esc(s.item) + '" data-p="' + pref + '"><i class="ri-add-line"></i><span>' + esc(s.label) + '</span></button>';
    });
    h += '<button type="button" class="chip out" data-do="picker" data-k="tag" data-p="' + pref + '"><i class="ri-add-line"></i><span>Add</span></button>';
    var box = $(boxId); box.innerHTML = h;
    box.classList.toggle('stagger', STAGGER);
}

/* ── menu on a picked topic: why it is here, move to the other side, remove ──
   v14: the menu has its OWN click handler (below), so its buttons no longer depend on the page-wide handler. */
var CM = null;
function closeChipMenu(){ var m = $('chipMenu'); m.classList.remove('open'); CM = null; }
function chipMenuHtml(){
    var other = CM.p === 'more' ? 'less' : 'more';
    return '<button type="button" data-cm="why"><i class="ri-information-line"></i>Why you\u2019re seeing this</button>' +
           '<button type="button" data-cm="move"><i class="' + (CM.p === 'more' ? 'ri-arrow-down-line' : 'ri-arrow-up-line') + '"></i>Move to see ' + other + '</button>' +
           '<button type="button" class="danger" data-cm="rm"><i class="ri-delete-bin-line"></i>Remove interest</button>';
}
function openChipMenu(btn){
    var m = $('chipMenu');
    CM = { k:btn.dataset.k, i:btn.dataset.i, p:btn.dataset.p, el:btn, t:Date.now(), w:window.innerWidth };
    m.innerHTML = chipMenuHtml();
    placeChipMenu(btn, true);
}
/* the menu sits in page coordinates (position:absolute), so it stays glued to its chip while the page scrolls */
function placeChipMenu(btn, animate){
    var m = $('chipMenu');
    var sx = window.pageXOffset || 0, sy = window.pageYOffset || 0;
    var r = btn.getBoundingClientRect(), w = Math.min(268, window.innerWidth - 24);
    var left = Math.max(12, Math.min(r.left, window.innerWidth - w - 12)) + sx;
    var top = r.bottom + 8 + sy;
    m.classList.remove('open'); m.style.animation = 'none';
    m.style.left = left + 'px'; m.style.top = top + 'px'; m.style.transformOrigin = 'top left';
    m.style.display = 'block';
    var mh = m.offsetHeight;
    if(r.bottom + 8 + mh > window.innerHeight - 12){
        top = Math.max(12, r.top - mh - 8) + sy;
        m.style.top = top + 'px'; m.style.transformOrigin = 'bottom left';
    }
    m.style.display = '';
    void m.offsetWidth;
    m.style.animation = animate ? '' : 'none';
    m.classList.add('open');
}
function chipWhy(){
    if(!CM) return;
    var p = getPref(CM.k, CM.i); if(!p) return;
    var bare = String(p.item).replace(/^#/, '');
    var l = LEARNED.filter(function(x){ return x.kind === 'tag' && x.item === bare; })[0];
    var t = '<div class="cm-why"><b>' + esc(p.label) + '</b> is here because <b>you added it</b> to the topics you want to see ' + (p.pref === 'more' ? 'more' : 'less') + ' of. ' +
            (p.pref === 'more' ? 'Posts about it get a lift in your feed.' : 'Posts about it show up much less, but they are not blocked. You may still see the odd one.');
    if(l) t += ' ' + ((l.eff > 0) === (p.pref === 'more') ? 'Your recent activity points the same way, so it is working with what you do.' : 'Your recent activity points the other way, but your choice comes first.');
    t += '</div><button type="button" data-cm="back"><i class="ri-arrow-left-line"></i>Back</button>';
    $('chipMenu').innerHTML = t;
}
/* the menu's own click handler */
$('chipMenu').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-cm]') : null;
    if(!b) return;
    e.stopPropagation();
    if(!CM) return;
    var a = b.getAttribute('data-cm');
    if(a === 'why'){ chipWhy(); return; }
    if(a === 'back'){ $('chipMenu').innerHTML = chipMenuHtml(); return; }
    var cur = { k:CM.k, i:CM.i, p:CM.p };
    closeChipMenu();
    if(a === 'move'){
        var to = cur.p === 'more' ? 'less' : 'more';
        POP = cur.i;
        addPref('tag', cur.i, to).then(function(row){
            if(row) toast(to === 'more' ? 'Moved to what you want to see more of.' : 'Moved to what you want to see less of.');
            POP = null;
        });
    } else if(a === 'rm'){
        removePref('tag', cur.i, 'Interest removed.');
    }
});
/* tapping anywhere else closes the menu */
document.addEventListener('click', function(e){
    if(!CM) return;
    if(e.target.closest('#chipMenu') || e.target.closest('[data-do="chipmenu"]')) return;
    if(Date.now() - CM.t < 400) return;
    closeChipMenu();
}, true);
window.addEventListener('resize', function(){ if(CM && Math.abs(window.innerWidth - CM.w) > 40) closeChipMenu(); });

/* ── creators ── */
function renderCreators(){
    var list = PREFS.filter(function(p){ return p.kind === 'creator'; });
    if(!list.length){
        $('creatorRows').innerHTML = '<div class="empty">No creators picked yet. Add someone and choose whether you want more or less of them.</div>';
        return;
    }
    $('creatorRows').innerHTML = list.map(function(p){
        return '<div class="row">' +
            '<img class="row-pic" src="' + esc(p.pic || DEFAULT_PIC) + '" onerror="this.src=\'' + DEFAULT_PIC + '\'" alt="">' +
            '<div class="row-info"><div class="row-name">' + esc(p.label) + '</div><div class="row-sub">' + (p.pref === 'more' ? 'Showing more of them' : 'Showing less of them') + '</div></div>' +
            '<div class="seg sm">' +
                '<button type="button" class="' + (p.pref === 'less' ? 'on' : '') + '" data-do="add" data-k="creator" data-i="' + esc(p.item) + '" data-p="less" aria-label="Less of ' + esc(p.label) + '"><i class="ri-arrow-down-line"></i></button>' +
                '<button type="button" class="' + (p.pref === 'more' ? 'on' : '') + '" data-do="add" data-k="creator" data-i="' + esc(p.item) + '" data-p="more" aria-label="More of ' + esc(p.label) + '"><i class="ri-arrow-up-line"></i></button>' +
            '</div>' +
            '<button type="button" class="x-btn" data-do="rm" data-k="creator" data-i="' + esc(p.item) + '" aria-label="Remove ' + esc(p.label) + '"><i class="ri-close-line"></i></button>' +
        '</div>';
    }).join('');
}

/* ── formats ── */
function renderTypes(){
    $('typeRows').innerHTML = TYPES.map(function(t){
        var p = getPref('type', t[0]); var cur = p ? p.pref : 'normal';
        return '<div class="row">' +
            '<span class="row-ic"><i class="' + t[1] + '"></i></span>' +
            '<div class="row-info"><div class="row-name">' + t[2] + '</div></div>' +
            '<div class="seg">' +
                '<button type="button" class="' + (cur === 'less' ? 'on' : '') + '" data-do="type" data-i="' + t[0] + '" data-p="less">Less</button>' +
                '<button type="button" class="' + (cur === 'normal' ? 'on' : '') + '" data-do="type" data-i="' + t[0] + '" data-p="normal">Normal</button>' +
                '<button type="button" class="' + (cur === 'more' ? 'on' : '') + '" data-do="type" data-i="' + t[0] + '" data-p="more">More</button>' +
            '</div></div>';
    }).join('');
}

/* ── adventure + controls ── */
function advInfo(v){
    if(v < 34) return ['Familiar', 'Mostly what you already like. Fewer surprises.'];
    if(v < 67) return ['Balanced', 'A mix of your favourites and a few really different finds.'];
    return ['Adventurous', 'More posts from topics, creators and formats you don\u2019t usually see.'];
}
function paintAdv(v){
    var i = advInfo(v);
    $('advLabel').textContent = i[0]; $('advDesc').textContent = i[1];
    $('advRange').style.setProperty('--p', v + '%');
}
function renderControls(){
    paintLocal();
    var fb = $('sw_fx'); if(fb){ fb.classList.toggle('on', FX.on); fb.setAttribute('aria-checked', FX.on ? 'true' : 'false'); }
    $('advRange').value = SET.adventure; paintAdv(SET.adventure);
    ['local_content', 'personalize', 'use_watch_time'].forEach(function(f){
        var b = $('sw_' + f); b.classList.toggle('on', !!SET[f]); b.setAttribute('aria-checked', SET[f] ? 'true' : 'false');
    });
    ['follow_level', 'novelty_level'].forEach(function(f){
        document.querySelectorAll('#seg_' + f + ' button').forEach(function(b){ b.classList.toggle('on', parseInt(b.dataset.v, 10) === SET[f]); });
    });
    ['sensitive_level', 'political_level'].forEach(function(f){
        document.querySelectorAll('#opt_' + f + ' button').forEach(function(b){ b.classList.toggle('on', parseInt(b.dataset.v, 10) === SET[f]); });
    });
}
function paintLocal(){
    var d = $('localDesc'); if(!d) return;
    if(LOCATION){
        d.textContent = SET.local_content
            ? 'Your feed leans towards posts from people in ' + LOCATION + ' (the location in your About me).'
            : 'Lift posts from people in ' + LOCATION + ' (the location in your About me).';
    } else {
        d.textContent = 'Add your current location in About me and your feed can lean towards people near you.';
    }
}
function saveSetting(field, value){
    var old = SET[field];
    if(old === value) return;
    beginChange();
    SET[field] = value; renderControls(); paintLocal(); renderMenu();
    api('save_setting', { field:field, value:value }).then(function(r){
        if(!r.ok){ SET[field] = old; renderControls(); renderMenu(); toast(r.error || 'Could not save that.', true); }
        else toast('Saved.');
    });
}

/* ── learned ── */
function lchip(l){
    var pct = Math.max(8, Math.min(100, Math.round(Math.abs(l.eff) / 20 * 100)));
    var ic = l.kind === 'creator' ? '<img src="' + esc(l.pic || DEFAULT_PIC) + '" onerror="this.src=\'' + DEFAULT_PIC + '\'" alt="">' : '';
    var lab = l.kind === 'type' ? typeName(l.item) : l.label;
    return '<span class="lchip ' + (l.eff < 0 ? 'neg' : '') + '">' + ic + '<span class="lc">' + esc(lab) + '</span><span class="meter"><b style="width:' + pct + '%"></b></span>' +
           '<button type="button" data-do="forget" data-k="' + l.kind + '" data-i="' + esc(l.item) + '" aria-label="Forget ' + esc(lab) + '"><i class="ri-close-line"></i></button></span>';
}
function renderLearned(){
    var pos = LEARNED.filter(function(l){ return l.eff > 0; }).slice(0, 14);
    var neg = LEARNED.filter(function(l){ return l.eff < 0; }).slice(0, 10);
    var h = '';
    if(!pos.length && !neg.length){
        h = '<div class="empty">Nothing yet. As you like, save, repost and browse, you\u2019ll see what your feed picks up here.</div>';
    } else {
        if(pos.length) h += '<div class="lgroup"><div class="lgroup-h">Leaning towards</div><div class="lchips">' + pos.map(lchip).join('') + '</div></div>';
        if(neg.length) h += '<div class="lgroup"><div class="lgroup-h">Leaning away from</div><div class="lchips">' + neg.map(lchip).join('') + '</div></div>';
    }
    $('learnedBox').innerHTML = h;
}

/* ── at a glance ── */
function renderGlance(){
    var tm = PREFS.filter(function(p){ return p.kind === 'tag' && p.pref === 'more'; }).length;
    var tl = PREFS.filter(function(p){ return p.kind === 'tag' && p.pref === 'less'; }).length;
    var cr = PREFS.filter(function(p){ return p.kind === 'creator'; }).length;
    var ln = LEARNED.length;
    var cells = [[tm, 'Topics you want more of', 'up'], [tl, 'Topics you want less of', 'down'], [cr, 'Creators picked', ''], [ln, 'Things learned', '']];
    $('glance').innerHTML = cells.map(function(c){ return '<div class="gl ' + c[2] + '"><b>' + c[0] + '</b><span>' + c[1] + '</span></div>'; }).join('');
}

/* ── feed style: one tap sets several dials ── */
var STYLES = [
    { k:'familiar', n:'Familiar', ic:'ri-heart-3-line',    d:'More of what you love and the people you track.',        v:{ adventure:15, follow_level:2, novelty_level:0 } },
    { k:'everyday', n:'Everyday', ic:'ri-sun-line',        d:'A healthy mix of favourites and a few new things.',      v:{ adventure:50, follow_level:1, novelty_level:1 } },
    { k:'explore',  n:'Explore',  ic:'ri-compass-3-line',  d:'Really different topics, creators and formats.',         v:{ adventure:88, follow_level:0, novelty_level:2 } }
];
function currentStyle(){
    var best = null;
    STYLES.forEach(function(st){
        if(SET.follow_level === st.v.follow_level && SET.novelty_level === st.v.novelty_level && Math.abs(SET.adventure - st.v.adventure) <= 14) best = st.k;
    });
    return best;
}
function renderStyles(){
    var cur = currentStyle();
    $('styleBox').innerHTML = STYLES.map(function(st){
        return '<button type="button" class="sty' + (cur === st.k ? ' on' : '') + '" data-do="style" data-k="' + st.k + '" aria-pressed="' + (cur === st.k) + '"><i class="' + st.ic + '"></i><b>' + st.n + '</b><small>' + st.d + '</small></button>';
    }).join('');
}
function applyStyle(k){
    var st = STYLES.filter(function(x){ return x.k === k; })[0]; if(!st) return;
    beginChange(); fx('pop');
    var old = { adventure:SET.adventure, follow_level:SET.follow_level, novelty_level:SET.novelty_level };
    Object.keys(st.v).forEach(function(f){ SET[f] = st.v[f]; });
    renderAll();
    var fields = Object.keys(st.v), bad = false;
    fields.reduce(function(chain, f){
        return chain.then(function(){ return api('save_setting', { field:f, value:st.v[f] }).then(function(r){ if(!r.ok) bad = true; }); });
    }, Promise.resolve()).then(function(){
        if(bad){ Object.keys(old).forEach(function(f){ SET[f] = old[f]; }); renderAll(); toast('Could not change your feed style.', true); }
        else toast(st.n + ' feed style is on.');
    });
}

/* ── CATEGORISED LIST: each row opens its area in a sheet ── */
var PANES = {
    style:     { t:'Your feed style',          ic:'ri-magic-line' },
    creators:  { t:'Creators',                 ic:'ri-group-line' },
    formats:   { t:'Post formats',             ic:'ri-layout-grid-line' },
    adventure: { t:'How adventurous?',         ic:'ri-compass-3-line' },
    sensitive: { t:'Sensitive content',        ic:'ri-alert-line' },
    political: { t:'Political content',        ic:'ri-government-line' },
    controls:  { t:'Feed controls',            ic:'ri-equalizer-line' },
    learned:   { t:'What your activity says',  ic:'ri-lightbulb-flash-line' },
    share:     { t:'Share or copy settings',   ic:'ri-share-forward-line' },
    reset:     { t:'Reset & hidden posts',     ic:'ri-restart-line' }
};
var LEVEL3 = ['Less', 'Standard', 'More'];
function menuSummary(k){
    switch(k){
        case 'style':
            var cs = currentStyle(), st = STYLES.filter(function(x){ return x.k === cs; })[0];
            return st ? st.n : 'Custom';
        case 'creators':
            var n = PREFS.filter(function(p){ return p.kind === 'creator'; }).length;
            return n ? n + (n === 1 ? ' creator picked' : ' creators picked') : 'None picked';
        case 'formats':
            var f = PREFS.filter(function(p){ return p.kind === 'type'; }).length;
            return f ? f + ' adjusted' : 'All normal';
        case 'adventure': return advInfo(SET.adventure)[0];
        case 'sensitive': return LEVEL3[SET.sensitive_level] || 'Standard';
        case 'political': return LEVEL3[SET.political_level] || 'Standard';
        case 'controls':
            var on = ['local_content', 'personalize', 'use_watch_time'].filter(function(x){ return !!SET[x]; }).length;
            return on + ' of 3 switches on';
        case 'share': return 'Copy or paste a code';
        case 'learned': return LEARNED.length ? LEARNED.length + (LEARNED.length === 1 ? ' thing learned' : ' things learned') : 'Nothing yet';
        case 'reset': return HIDDEN > 0 ? HIDDEN + (HIDDEN === 1 ? ' hidden post' : ' hidden posts') : 'Nothing hidden';
    }
    return '';
}
var MENU_GROUPS = [
    { h:'Shape your feed',     keys:['style', 'creators', 'formats', 'adventure'] },
    { h:'Content you see',     keys:['sensitive', 'political'] },
    { h:'Controls & learning', keys:['controls', 'learned'] },
    { h:'Back up',             keys:['share'] },
    { h:'Reset',               keys:['reset'] }
];
function renderMenu(){
    $('menuBox').innerHTML = MENU_GROUPS.map(function(g){
        return '<div class="mgroup"><div class="mgroup-h">' + g.h + '</div>' + g.keys.map(function(k){
            var p = PANES[k];
            return '<button type="button" class="mrow" data-do="pane" data-p="' + k + '">' +
                '<span class="row-ic"><i class="' + p.ic + '"></i></span>' +
                '<span class="row-info"><span class="row-name">' + esc(p.t) + '</span><div class="row-sub">' + esc(menuSummary(k)) + '</div></span>' +
                '<i class="ri-arrow-right-s-line chev"></i></button>';
        }).join('') + '</div>';
    }).join('');
}
function openPane(k){
    var p = PANES[k]; if(!p) return;
    $('paneTitle').textContent = p.t;
    document.querySelectorAll('#paneScroll .pane').forEach(function(el){ el.hidden = (el.id !== 'pane_' + k); });
    $('paneScroll').scrollTop = 0;
    openModal('paneModal');
}

function renderShare(){
    var o = $('codeOut'); if(o) o.value = makeCode(PREFS, SET);
    var sb = $('shareBtn'); if(sb) sb.hidden = !navigator.share;
}
function copyCode(){
    var txt = makeCode(PREFS, SET), o = $('codeOut');
    var done = function(){ PENDING = null; toast('Code copied.'); };
    var fail = function(){ PENDING = null; toast('Could not copy. Press and hold the code to copy it.', true); };
    if(navigator.clipboard && window.isSecureContext){ navigator.clipboard.writeText(txt).then(done, fail); return; }
    try{ o.focus(); o.select(); o.setSelectionRange(0, txt.length); if(document.execCommand('copy')) done(); else fail(); }catch(e){ fail(); }
}
function applyCode(){
    var code = ($('codeIn').value || '').trim();
    if(!code){ PENDING = null; toast('Paste a code first.', true); return; }
    confirmAct('Use this code?', 'This replaces your current topics, creators, formats and dials with the ones in the code. What your feed has learned stays. You can undo it right after.', 'Apply', function(){
        beginChange();
        api('import_settings', { code:code }).then(function(r){
            if(!r.ok){ PENDING = null; toast(r.error || 'That code did not work.', true); return; }
            applyState(r); $('codeIn').value = ''; fx('pop'); toast('Settings applied.');
        });
    });
}

function renderAll(){
    renderShare(); renderHero(); renderGlance(); renderStyles();
    renderChips('more', 'moreChips'); renderChips('less', 'lessChips');
    renderCreators(); renderTypes(); renderControls(); renderLearned(); renderMenu();
    $('niSub').textContent = HIDDEN > 0 ? (HIDDEN + (HIDDEN === 1 ? ' post is' : ' posts are') + ' hidden from your feed. Tap to review or bring them back.') : 'You haven\u2019t hidden any posts.';
}

/* ── modals (each one closes on its own, so a sheet stays open behind a picker or a confirm) ── */
function openModal(id){ $(id).classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeOverlay(el){
    if(!el) return;
    el.classList.remove('open');
    if(!document.querySelector('.u-modal-overlay.open')) document.body.style.overflow = '';
}
function closeTopOverlay(){
    var open = document.querySelectorAll('.u-modal-overlay.open');
    if(open.length) closeOverlay(open[open.length - 1]);
}

/* ── hidden posts ("Not interested"): review them and bring them back one by one ── */
function hdSkel(){
    var h = '';
    for(var i = 0; i < 4; i++) h += '<div class="hd-row"><span class="sk" style="width:46px;height:46px;border-radius:7px;flex-shrink:0"></span><span class="hd-txt"><span class="sk" style="height:12px;width:' + (60 - i * 8) + '%"></span><span class="sk" style="height:10px;width:30%;margin-top:8px"></span></span></div>';
    return h;
}
function openHidden(){
    if(HIDDEN <= 0){ toast('You haven\u2019t hidden any posts.'); return; }
    openModal('hiddenModal');
    $('hdFoot').hidden = true;
    $('hdList').innerHTML = hdSkel();
    api('list_hidden').then(function(r){
        if(!r.ok){ $('hdList').innerHTML = '<div class="pk-empty">' + esc(r.error || 'Could not load your hidden posts.') + '</div>'; return; }
        var ps = r.posts || [];
        if(!ps.length){ $('hdList').innerHTML = '<div class="pk-empty">You haven\u2019t hidden any posts.</div>'; return; }
        $('hdList').innerHTML = ps.map(function(p){
            var th = p.thumb ? '<img class="hd-th" src="' + esc(p.thumb) + '" onerror="this.style.visibility=\'hidden\'" alt="">'
                             : '<span class="hd-th"><i class="' + (p.type === 'video' ? 'ri-movie-2-line' : 'ri-text') + '"></i></span>';
            return '<div class="hd-row" data-id="' + p.id + '">' + th +
                '<span class="hd-txt"><div class="hd-cap">' + esc(p.caption || (p.type ? typeName(p.type) : 'Post')) + '</div><div class="hd-by">@' + esc(p.user) + '</div></span>' +
                '<button type="button" class="hd-back" data-do="unhide" data-i="' + p.id + '">Bring back</button></div>';
        }).join('');
        $('hdFoot').hidden = false;
    });
}
function unhide(id, btn){
    btn.disabled = true;
    api('restore_hidden', { meme_id:id }).then(function(r){
        if(!r.ok){ btn.disabled = false; toast(r.error || 'Could not bring that back.', true); return; }
        var row = btn.closest('.hd-row'); if(row) row.remove();
        HIDDEN = Math.max(0, HIDDEN - 1); renderAll();
        toast('Brought back. It can show up in your feed again.');
        if(!document.querySelector('#hdList .hd-row')){ $('hdList').innerHTML = '<div class="pk-empty">You haven\u2019t hidden any posts.</div>'; $('hdFoot').hidden = true; }
    });
}

/* ── picker (topics + creators) ── */
var PK = { kind:'', pref:'', timer:null, seq:0 };
function openPicker(kind, pref){
    PK.kind = kind; PK.pref = pref;
    $('pkTitle').textContent = kind === 'creator' ? 'Add a creator' : (pref === 'more' ? 'Add a topic you want more of' : 'Add a topic you want less of');
    var inp = $('pkInput'); inp.value = '';
    inp.placeholder = kind === 'creator' ? 'Search people\u2026' : 'Type a word or a #hashtag\u2026';
    openModal('pickerModal'); loadPicker('');
    setTimeout(function(){ inp.focus(); }, 150);
}
function pkSkel(){
    var w = [56, 38, 64, 46, 52], h = '';
    for(var i = 0; i < w.length; i++) h += '<div class="pk-row"><span class="sk" style="width:40px;height:40px;border-radius:' + (PK.kind === 'creator' ? '50%' : '7px') + ';flex-shrink:0"></span><span class="row-info"><span class="sk" style="height:12px;width:' + w[i] + '%"></span></span></div>';
    return h;
}
function loadPicker(q){
    var seq = ++PK.seq;
    $('pkList').innerHTML = pkSkel();
    if(PK.kind === 'creator'){
        api('search_users', { q:q }).then(function(r){
            if(seq !== PK.seq) return;
            var us = (r.users || []);
            if(!us.length){ $('pkList').innerHTML = '<div class="pk-empty">' + (q ? 'No people found.' : 'Search for a person to add.') + '</div>'; return; }
            $('pkList').innerHTML = us.map(function(u){
                return '<button type="button" class="pk-row" data-do="pickc" data-i="' + u.id + '" data-l="' + esc(u.label) + '" data-pic="' + esc(u.pic) + '">' +
                    '<img class="row-pic" src="' + esc(u.pic) + '" onerror="this.src=\'' + DEFAULT_PIC + '\'" alt="">' +
                    '<span class="row-info"><span class="row-name">' + esc(u.label) + '</span>' + (u.nick ? '<div class="row-sub">@' + esc(u.nick) + '</div>' : (u.following ? '<div class="row-sub">You track them</div>' : '')) + '</span>' +
                    '<i class="ri-add-line pk-add"></i></button>';
            }).join('');
        });
    } else {
        api('suggest_tags', { q:q }).then(function(r){
            if(seq !== PK.seq) return;
            var clean = cleanTopic(q);
            var tags = (r.tags || []), h = '';
            var exact = tags.some(function(t){ return t.tag === clean; });
            if(clean && !exact) h += '<button type="button" class="pk-row" data-do="pickt" data-i="' + esc(clean) + '"><span class="row-ic"><i class="ri-price-tag-3-line"></i></span><span class="row-info"><span class="row-name">Add \u201c' + esc(clean) + '\u201d</span></span><i class="ri-add-line pk-add"></i></button>';
            h += tags.map(function(t){
                return '<button type="button" class="pk-row" data-do="pickt" data-i="' + esc(t.tag) + '"><span class="row-ic"><i class="ri-price-tag-3-line"></i></span><span class="row-info"><span class="row-name">' + esc(t.tag) + '</span></span><i class="ri-add-line pk-add"></i></button>';
            }).join('');
            $('pkList').innerHTML = h || '<div class="pk-empty">Type a word or a #hashtag to add it.</div>';
        });
    }
}
$('pkInput').addEventListener('input', function(){
    var v = this.value.trim();
    clearTimeout(PK.timer); PK.timer = setTimeout(function(){ loadPicker(v); }, 220);
});
$('pkInput').addEventListener('keydown', function(e){
    if(e.key === 'Enter' && PK.kind === 'tag'){
        var clean = cleanTopic(this.value);
        if(clean){ e.preventDefault(); pickTag(clean); }
    }
});
function pickTag(tag){
    var pref = PK.pref;
    closeOverlay($('pickerModal'));
    addPref('tag', tag, pref, tag, '').then(function(row){
        if(row) toast(pref === 'more' ? 'You\u2019ll see more of ' + row.label + '.' : 'You\u2019ll see less of ' + row.label + '.');
    });
}

/* ── confirm ── */
var CF = null;
function confirmAct(title, text, okLabel, fn){
    $('cfTitle').textContent = title; $('cfText').textContent = text; $('cfOk').textContent = okLabel; CF = fn;
    openModal('confirmModal');
}
$('cfOk').addEventListener('click', function(){ var f = CF; CF = null; closeOverlay($('confirmModal')); if(f) f(); });

function doReset(which){
    if(which === 'taste'){
        confirmAct('Reset what your feed has learned?', 'Your feed forgets the taste it picked up from your likes, saves, reposts and viewing, and starts again from now. The topics, creators and formats you picked stay.', 'Reset', function(){
            api('reset_taste').then(function(r){
                if(r.ok){ LEARNED = []; renderAll(); toast('Your feed has started fresh.'); } else toast(r.error || 'Could not reset.', true);
            });
        });
    } else if(which === 'choices'){
        confirmAct('Reset your choices?', 'This removes every topic, creator and format you picked and puts all the dials back to the middle.', 'Reset', function(){
            beginChange();
            api('reset_choices').then(function(r){
                if(r.ok){ PREFS = []; SET = r.settings; if(SET.sensitive_level == null) SET.sensitive_level = 1; if(SET.political_level == null) SET.political_level = 1; renderAll(); toast('Your choices were reset.'); } else toast(r.error || 'Could not reset.', true);
            });
        });
    } else {
        if(HIDDEN <= 0){ toast('You haven\u2019t hidden any posts.'); return; }
        confirmAct('Bring back all hidden posts?', 'Posts you marked "Not interested" can show up in your feed again. The small taste penalty they caused fades by itself.', 'Bring back', function(){
            api('undo_not_interested').then(function(r){
                if(r.ok){ HIDDEN = 0; renderAll(); closeOverlay($('hiddenModal')); toast(r.restored + (r.restored === 1 ? ' post' : ' posts') + ' can show up again.'); } else toast(r.error || 'Could not do that.', true);
            });
        });
    }
}

/* ── going back (the < button and the swipe both use this) ── */
function goBack(){
    if(document.referrer && document.referrer.indexOf(location.host) !== -1) history.back(); else location.href = 'home.php';
}

/* ── swipe left to right = back, app style: the page follows your finger ──
   Let go past a third of the screen (or flick quickly) and it slides away and goes back; otherwise it springs back.
   With a sheet open, the sheet follows your finger and closes instead. */
(function(){
    var lane = document.querySelector('.ac-lane'), shade = $('swipeShade'), S = null;
    var EASE_OUT = 'transform .26s cubic-bezier(.2,.8,.2,1)', SPRING = 'transform .38s cubic-bezier(.34,1.56,.64,1)';

    function blocked(el){
        /* leave sliders, text boxes and sideways-scrolling rows alone */
        while(el && el !== document.body){
            if(el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') return true;
            if(el.scrollWidth > el.clientWidth + 4){
                var ox = getComputedStyle(el).overflowX;
                if(ox === 'auto' || ox === 'scroll') return true;
            }
            el = el.parentElement;
        }
        return false;
    }
    function pick(){
        var o = document.querySelectorAll('.u-modal-overlay.open');
        if(o.length){ var ov = o[o.length - 1]; return { el:ov.querySelector('.u-modal-card'), ov:ov }; }
        return { el:lane, ov:null };
    }
    function reset(tg){
        tg.el.style.transition = ''; tg.el.style.transform = '';
        if(!tg.ov){ lane.classList.remove('dragging'); shade.style.transition = ''; shade.style.opacity = '0'; }
    }

    document.addEventListener('touchstart', function(e){
        if(e.touches.length !== 1 || blocked(e.target)){ S = null; return; }
        var t = e.touches[0], tg = pick();
        if(!tg.el){ S = null; return; }
        S = { x:t.clientX, y:t.clientY, lx:t.clientX, lt:Date.now(), v:0, lock:0, tg:tg, W:window.innerWidth || 360 };
    }, { passive:true });

    document.addEventListener('touchmove', function(e){
        if(!S || S.lock === -1) return;
        var t = e.touches[0], dx = t.clientX - S.x, dy = t.clientY - S.y;
        if(S.lock === 0){
            if(Math.abs(dx) < 24 && Math.abs(dy) < 24) return;
            if(dx > 0 && dx > Math.abs(dy) * 1.5){
                S.lock = 1;
                if(CM) closeChipMenu();
                S.tg.el.style.transition = 'none';
                if(!S.tg.ov) lane.classList.add('dragging');
            } else { S.lock = -1; return; }
        }
        if(e.cancelable) e.preventDefault();
        var x = Math.max(0, dx), now = Date.now();
        S.tg.el.style.transform = 'translate3d(' + x + 'px,0,0)';
        if(!S.tg.ov) shade.style.opacity = (0.6 * (1 - Math.min(1, x / S.W))).toFixed(3);
        S.v = (t.clientX - S.lx) / Math.max(1, now - S.lt); S.lx = t.clientX; S.lt = now;
        S.x2 = x;
    }, { passive:false });

    function end(cancelled){
        var st = S; S = null;
        if(!st || st.lock !== 1) return;
        var tg = st.tg, x = st.x2 || 0;
        var go = !cancelled && (x > st.W * 0.35 || (st.v > 0.5 && x > 50));
        if(go){
            tg.el.style.transition = EASE_OUT;
            tg.el.style.transform = 'translate3d(' + st.W + 'px,0,0)';
            if(!tg.ov){ shade.style.transition = 'opacity .26s'; shade.style.opacity = '0'; }
            setTimeout(function(){
                if(tg.ov){ closeOverlay(tg.ov); reset(tg); }
                else { goBack(); setTimeout(function(){ reset(tg); }, 900); }   /* if the page is still here (no history), bring it back */
            }, 250);
        } else {
            tg.el.style.transition = SPRING;
            tg.el.style.transform = 'translate3d(0,0,0)';
            if(!tg.ov){ shade.style.transition = 'opacity .3s'; shade.style.opacity = '0'; }
            setTimeout(function(){ reset(tg); }, 400);
        }
    }
    document.addEventListener('touchend', function(){ end(false); }, { passive:true });
    document.addEventListener('touchcancel', function(){ end(true); }, { passive:true });
    window.addEventListener('pageshow', function(){ reset({ el:lane, ov:null }); });   /* coming back to this page from the cache */
})();

/* ── one click handler for everything (the topic chip menu has its own handler above) ── */
document.addEventListener('click', function(e){
    var t = e.target.closest('[data-do]'); if(!t) return;
    var d = t.dataset;
    switch(d['do']){
        case 'back': goBack(); break;
        case 'modal': openModal(d.m); break;
        case 'pane': openPane(d.p); break;
        case 'close': closeOverlay(t.closest('.u-modal-overlay')); break;
        case 'backdrop': if(e.target === t) closeOverlay(t); break;
        case 'picker': openPicker(d.k, d.p); break;
        case 'pickt': pickTag(d.i); break;
        case 'pickc':
            closeOverlay($('pickerModal'));
            addPref('creator', d.i, 'more', d.l, d.pic).then(function(row){ if(row) toast('You\u2019ll see more of ' + row.label + '.'); });
            break;
        case 'add':
            addPref(d.k, d.i, d.p).then(function(row){
                if(!row) return;
                toast(d.p === 'more' ? 'You\u2019ll see more of ' + row.label + '.' : 'You\u2019ll see less of ' + row.label + '.');
            });
            break;
        case 'rm': removePref(d.k, d.i); break;
        case 'chipmenu':
            if(CM && CM.el === t){ if(Date.now() - CM.t > 400) closeChipMenu(); }
            else openChipMenu(t);
            break;
        case 'style': applyStyle(d.k); break;
        case 'type':
            var tn = typeName(d.i).toLowerCase();
            if(d.p === 'normal'){
                if(getPref('type', d.i)) removePref('type', d.i, 'Back to normal for ' + tn + '.');
            } else {
                addPref('type', d.i, d.p).then(function(row){
                    if(row) toast(d.p === 'more' ? 'You\u2019ll see more ' + tn + '.' : 'You\u2019ll see fewer ' + tn + '.');
                });
            }
            break;
        case 'sw': fx('tick'); saveSetting(d.f, SET[d.f] ? 0 : 1); break;
        case 'seg': fx('tick'); saveSetting(d.f, parseInt(d.v, 10)); break;
        case 'fxsw':
            FX.on = !FX.on;
            try{ localStorage.setItem('dz_fx', FX.on ? '1' : '0'); }catch(e){}
            renderControls();
            if(FX.on) fx('pop');
            break;
        case 'undo': doUndo(); break;
        case 'copycode': copyCode(); break;
        case 'sharecode':
            if(navigator.share) navigator.share({ title:'My Dezzme feed settings', text:makeCode(PREFS, SET) }).catch(function(){});
            break;
        case 'applycode': applyCode(); break;
        case 'forget':
            PENDING = null;
            var k = d.k, i = d.i, old = LEARNED.filter(function(l){ return l.kind === k && l.item === i; })[0];
            LEARNED = LEARNED.filter(function(l){ return !(l.kind === k && l.item === i); }); renderAll();
            api('forget', { kind:k, item:i }).then(function(r){
                if(!r.ok){ if(old) LEARNED.push(old); LEARNED.sort(function(a, b){ return Math.abs(b.eff) - Math.abs(a.eff); }); renderAll(); toast(r.error || 'Could not forget that.', true); }
                else toast('Forgotten.');
            });
            break;
        case 'hidden': openHidden(); break;
        case 'unhide': unhide(parseInt(d.i, 10), t); break;
        case 'reset': doReset(d.w); break;
    }
});

/* adventure slider: live label while dragging, save when released */
var _advTick = -1;
$('advRange').addEventListener('input', function(){
    var v = parseInt(this.value, 10); paintAdv(v);
    var tk = Math.floor(v / 10); if(tk !== _advTick){ _advTick = tk; buzz(HP.tick); }   /* a tap every 10 steps */
});
$('advRange').addEventListener('change', function(){ fx('tick'); saveSetting('adventure', parseInt(this.value, 10)); });

document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ if(CM) closeChipMenu(); else closeTopOverlay(); } });

/* explainer weights */
$('wtBox').hidden = false;
$('wtBox').innerHTML = D.weights.map(function(w){
    var v = w[1]; return '<div><b>' + esc(w[0]) + '</b><span class="' + (v < 0 ? 'neg' : 'pos') + '">' + (v > 0 ? '+' : '') + v + '</span></div>';
}).join('');

renderAll();
setTimeout(function(){ STAGGER = false; document.querySelectorAll('.chips.stagger').forEach(function(c){ c.classList.remove('stagger'); }); }, 1800);

/* skeleton → real page: waits for fonts and icons (max 1.8 s), shows the shimmer for at least half a second */
(function(){
    var fin = false;
    function done(){
        if(fin) return; fin = true;
        var wait = Math.max(0, 500 - (Date.now() - (window.__t0 || Date.now())));
        setTimeout(function(){ var c = document.documentElement.classList; c.remove('is-loading'); c.add('is-ready'); }, wait);
    }
    var fonts = (document.fonts && document.fonts.ready) ? Promise.race([document.fonts.ready, new Promise(function(res){ setTimeout(res, 1800); })]) : Promise.resolve();
    fonts.then(done);
    setTimeout(done, 3000);
})();
</script>
</body>
</html>