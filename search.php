<?php
/* ============ news / sports / entertainment strip: data layer (cached, never blocks the page) ============ */
if (!function_exists('dzns_feed')) {
    function dzns_file($key){ return sys_get_temp_dir().'/dzns_'.md5($key).'.json'; }
    function dzns_peek($key, $ttl){        /* -> [data|null, fresh?]  (stale data is still returned) */
        $f = dzns_file($key); if (!is_file($f)) return [null, false];
        $d = json_decode(@file_get_contents($f), true); if (!is_array($d)) return [null, false];
        return [$d, (time() - filemtime($f)) < $ttl];
    }
    function dzns_multi($urls, $verify = true){            /* parallel GET, 3 s ceiling */
        $mh = curl_multi_init(); $ch = [];
        foreach ($urls as $k => $u) {
            $c = curl_init($u);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_TIMEOUT=>3, CURLOPT_CONNECTTIMEOUT=>2,
                CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; Dezzme/1.0)', CURLOPT_SSL_VERIFYPEER=>$verify, CURLOPT_SSL_VERIFYHOST=>$verify ? 2 : 0]);
            curl_multi_add_handle($mh, $c); $ch[$k] = $c;
        }
        do { curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 0.2); } while ($run);
        $out = [];
        foreach ($ch as $k => $c) { $out[$k] = (curl_getinfo($c, CURLINFO_HTTP_CODE) == 200) ? curl_multi_getcontent($c) : ''; curl_multi_remove_handle($mh, $c); curl_close($c); }
        curl_multi_close($mh);
        if ($verify && !array_filter($out)) return dzns_multi($urls, false);   /* XAMPP often has no CA bundle: public read-only feeds, retry once */
        return $out;
    }
    function dzns_url($u){ return (preg_match('#^https?://#i', (string)$u)) ? $u : ''; }
    function dzns_make_news(){
        $SRC = [['BBC','https://feeds.bbci.co.uk/news/rss.xml','news'], ['BBC Tech','https://feeds.bbci.co.uk/news/technology/rss.xml','tech'],
                ['BBC Sport','https://feeds.bbci.co.uk/sport/rss.xml','sport'], ['Al Jazeera','https://www.aljazeera.com/xml/rss/all.xml','news'],
                ['BBC Entertainment','https://feeds.bbci.co.uk/news/entertainment_and_arts/rss.xml','ent'],
                ['BBC Africa','https://feeds.bbci.co.uk/news/world/africa/rss.xml','news'], ['Guardian','https://www.theguardian.com/world/rss','news'],
                ['Guardian Sport','https://www.theguardian.com/sport/rss','sport'], ['Guardian Culture','https://www.theguardian.com/culture/rss','ent'],
                ['Guardian Football','https://www.theguardian.com/football/rss','sport']];
        $raw = dzns_multi(array_column($SRC, 1)); $items = [];
        foreach ($SRC as $i => $src) {
            if (empty($raw[$i])) continue;
            $prev = libxml_use_internal_errors(true);
            $x = @simplexml_load_string($raw[$i], 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            libxml_use_internal_errors($prev);
            if (!$x || !isset($x->channel->item)) continue;
            $n = 0;
            foreach ($x->channel->item as $it) {
                if ($n >= 8) break;
                $title = trim(html_entity_decode(strip_tags((string)$it->title), ENT_QUOTES, 'UTF-8'));
                $link  = dzns_url(trim((string)$it->link));
                if ($title === '' || $link === '') continue;
                $img = '';
                foreach (['.//*[local-name()="thumbnail"][@url]', './/*[local-name()="content"][@url]', './enclosure[@url]'] as $xp) {
                    $f = @$it->xpath($xp);
                    if ($f) { foreach ($f as $node) { $u = (string)$node['url']; $ty = (string)$node['type']; if ($u !== '' && ($ty === '' || stripos($ty, 'image') === 0)) { $img = $u; break 2; } } }
                }
                if ($img === '') {
                    $html = (string)$it->description . ' ' . (string)(@$it->xpath('.//*[local-name()="encoded"]')[0] ?? '');
                    if (preg_match('#<img[^>]+src=["\']([^"\']+)#i', $html, $mm)) $img = html_entity_decode($mm[1]);
                }
                $img = dzns_url($img);
                $img = preg_replace('#/(standard|news)/(\d+)/#', '/$1/480/', $img);
                $items[] = ['t'=>mb_substr($title,0,140), 'u'=>$link, 'i'=>$img, 's'=>$src[0], 'ts'=>(int)strtotime((string)$it->pubDate), 'k'=>$src[2]];
                $n++;
            }
        }
        usort($items, function($a,$b){ return $b['ts'] <=> $a['ts']; });
        $by = []; foreach ($items as $it) $by[$it['s']][] = $it;
        $mix = []; $more = true;
        while ($more) { $more = false; foreach ($by as $s => &$list) { if ($list) { $mix[] = array_shift($list); $more = true; } } unset($list); }
        return array_slice($mix, 0, 60);
    }
    function dzns_make_sports(){
        $SP = [['Premier League','soccer/eng.1'], ['Champions League','soccer/uefa.champions'], ['NBA','basketball/nba'], ['NFL','football/nfl'], ['La Liga','soccer/esp.1'], ['Serie A','soccer/ita.1'], ['Bundesliga','soccer/ger.1'], ['MLB','baseball/mlb'], ['NHL','hockey/nhl']];
        $raw = dzns_multi(array_map(function($s){ return 'https://site.api.espn.com/apis/site/v2/sports/'.$s[1].'/scoreboard'; }, $SP));
        $games = [];
        foreach ($SP as $i => $lg) {
            $j = json_decode($raw[$i] ?? '', true);
            if (!is_array($j) || empty($j['events'])) continue;
            foreach ($j['events'] as $ev) {
                $c = $ev['competitions'][0] ?? null; if (!$c || count($c['competitors'] ?? []) < 2) continue;
                $home = $away = null;
                foreach ($c['competitors'] as $t) { if (($t['homeAway'] ?? '') === 'home') $home = $t; else $away = $t; }
                if (!$home || !$away) continue;
                $st = $ev['status']['type'] ?? []; $state = $st['state'] ?? 'pre';
                $mk = function($t){ return ['n'=>$t['team']['shortDisplayName'] ?? ($t['team']['displayName'] ?? '?'), 'a'=>$t['team']['abbreviation'] ?? '', 'l'=>dzns_url($t['team']['logo'] ?? ''), 's'=>($t['score'] ?? '')]; };
                $games[] = ['lg'=>$lg[0], 'st'=>$state, 'tx'=>($state==='in' ? ($ev['status']['displayClock'] ?? '').' '.($st['shortDetail'] ?? '') : ($st['shortDetail'] ?? '')),
                            'ts'=>(int)strtotime($ev['date'] ?? ''), 'h'=>$mk($home), 'a'=>$mk($away), 'u'=>dzns_url($ev['links'][0]['href'] ?? '')];
            }
        }
        $rank = ['in'=>0, 'pre'=>1, 'post'=>2];
        usort($games, function($a,$b) use ($rank){ if ($a['st']!==$b['st']) return $rank[$a['st']] <=> $rank[$b['st']];
            return $a['st']==='post' ? $b['ts'] <=> $a['ts'] : $a['ts'] <=> $b['ts']; });
        return array_slice($games, 0, 40);
    }
    /* $fetch=false: only read what is already cached (instant). $fetch=true: refresh anything stale (may wait on other sites). */
    function dzns_feed($fetch){
        $res = []; $stale = false;
        foreach (['news'=>['news3',600,'dzns_make_news'], 'sports'=>['sports3',45,'dzns_make_sports']] as $k => $cfg) {
            list($d, $fresh) = dzns_peek($cfg[0], $cfg[1]);
            if (!$fresh && $fetch) {
                $new = call_user_func($cfg[2]);
                if ($new) { @file_put_contents(dzns_file($cfg[0]), json_encode($new), LOCK_EX); $d = $new; $fresh = true; }
            }
            if (!$fresh) $stale = true;
            $res[$k] = $d ?: [];
        }
        $res['stale'] = $stale;
        return $res;
    }
    /* Dezzme posts about news / sports / entertainment: a hashtag (#news, #sports, #movies …) OR the word in the caption.
       Category: sports > entertainment > news. On a database error the message is kept in $GLOBALS['dzns_perr']. */
    function dzns_posts($conn){
        $out = [];
        $GLOBALS['dzns_perr'] = '';
        $SP = ['sports','sport','football','soccer','nba','nfl','basketball']; $EN = ['entertainment','movies','movie','music','celebrity','celebrities','showbiz','nollywood','film','tv'];
        $NW = ['news','breaking','headlines'];
        $tags = array_merge($SP, $EN, $NW);
        $tagList = "'" . implode("','", $tags) . "'";
        $likes = ['news','sport','football','entertainment','movie','music','celebrity','showbiz','nollywood'];
        $likeSql = implode(' OR ', array_map(function($w){ return "m.caption LIKE '%$w%'"; }, $likes));
        try {
            $sql = "SELECT m.id, m.caption, m.file_name AS pfile, m.type AS ptype, u.username, u.profile_pic,
                       (SELECT mm.file_name FROM meme_media mm WHERE mm.meme_id = m.id ORDER BY mm.position ASC LIMIT 1) AS mfile,
                       (SELECT mm.type FROM meme_media mm WHERE mm.meme_id = m.id ORDER BY mm.position ASC LIMIT 1) AS mtype,
                       (SELECT GROUP_CONCAT(t.tag) FROM dz_meme_tags t WHERE t.meme_id = m.id) AS tg
                    FROM memes m JOIN users u ON u.id = m.uploaded_by
                    WHERE m.is_archived = 0 AND COALESCE(m.is_ad,0) = 0 AND u.deactivated_at IS NULL
                      AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')
                      AND NOT EXISTS (SELECT 1 FROM group_posts gpx JOIN `groups` gx ON gx.id = gpx.group_id WHERE gpx.meme_id = m.id AND gx.is_suspended = 1)
                      AND (EXISTS (SELECT 1 FROM dz_meme_tags t WHERE t.meme_id = m.id AND t.tag IN ($tagList)) OR $likeSql)
                    ORDER BY m.id DESC LIMIT 80";
            $r = $conn->query($sql);
            while ($r && ($row = $r->fetch_assoc())) {
                /* the post's own picture/video is memes.file_name; meme_media only holds the extra items of multi-media posts */
                $mf = ''; $mt = '';
                $okT = ['image','video','gif','sticker','stickers'];
                if (!empty($row['pfile']) && $row['pfile'] !== '0' && in_array(strtolower((string)$row['ptype']), $okT, true)) { $mf = (string)$row['pfile']; $mt = strtolower((string)$row['ptype']); }
                elseif (!empty($row['mfile']) && $row['mfile'] !== '0' && in_array(strtolower((string)$row['mtype']), $okT, true)) { $mf = (string)$row['mfile']; $mt = strtolower((string)$row['mtype']); }
                $img = ''; $vid = '';
                if ($mf !== '') {
                    if ($mt === 'video') { $img = function_exists('dz_thumb_url') ? (string)dz_thumb_url('uploads/'.$mf) : ''; $vid = 'uploads/'.$mf; }
                    else $img = 'uploads/'.$mf;
                }
                $cap = trim(strip_tags((string)$row['caption'])); $low = strtolower($cap);
                $tg = array_filter(explode(',', strtolower((string)$row['tg'])));
                $has = function($list) use ($tg, $low){ foreach ($list as $w) { if (strpos($low, $w) !== false) return true; foreach ($tg as $t) { if (strpos($t, $w) !== false) return true; } } return false; };
                $k = $has(['sport','football','soccer','nba','nfl','basketball']) ? 'sports'
                   : ($has(['entertainment','movie','music','celebrit','showbiz','nollywood','film']) ? 'ent'
                   : ($has($NW) ? 'news' : ''));
                if ($k === '') continue;
                $out[] = ['id'=>(int)$row['id'], 'c'=>mb_substr($cap, 0, 140), 'i'=>$img, 'v'=>$vid, 'n'=>(string)$row['username'], 'p'=>(string)($row['profile_pic'] ?: 'mee.jpg'), 'k'=>$k];
            }
        } catch (Throwable $e) { $out = []; $GLOBALS['dzns_perr'] = $e->getMessage(); }
        shuffle($out);   /* a different mix every visit */
        return $out;
    }
}
/* refresh call from the page (search.php?dz_ns=1): updates the cache, returns the new data */
if (isset($_GET['dz_ns'])) {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $o = dzns_feed(true); $o['curl'] = function_exists('curl_init');
    echo json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
}
?>
<?php $DZ_PAGE='search'; require_once 'shared_core.php';
/* strip data, read straight from the cache so it is in the page's first paint (no waiting on other websites) */
$DZNS = null;
if (($tag ?? 'all') === 'all' || ($tag ?? '') === '') { $DZNS = dzns_feed(false); $DZNS['posts'] = dzns_posts($conn); $DZNS['perr'] = $GLOBALS['dzns_perr'] ?? ''; $DZNS['curl'] = function_exists('curl_init'); }
?>
<?php
/* Server-rendered captions arrive as ready-made HTML (hashtag / mention links). Whatever they contain, only harmless
   formatting survives: <a> (http/relative href only), <span>, <b>, <i>, <strong>, <em>, <br>. Scripts, event handlers
   (onclick=…), javascript: links and every other tag are removed. */
if (!function_exists('dz_clean_caption_html')) {
    function dz_clean_caption_html($html) {
        $html = (string)$html;
        if (strpos($html, '<') === false) return $html;
        if (!class_exists('DOMDocument')) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $d = new DOMDocument('1.0', 'UTF-8');
        $d->loadHTML('<?xml encoding="UTF-8"><div id="dzroot">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $root = $d->getElementById('dzroot');
        if (!$root) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $allowed = ['a' => 1, 'span' => 1, 'b' => 1, 'i' => 1, 'strong' => 1, 'em' => 1, 'br' => 1];
        $walk = function ($node) use (&$walk, $allowed, $d) {
            $kids = [];
            foreach ($node->childNodes as $c) $kids[] = $c;
            foreach ($kids as $c) {
                if ($c->nodeType !== XML_ELEMENT_NODE) continue;
                $tag = strtolower($c->nodeName);
                if (!isset($allowed[$tag])) {                       // unknown tag → keep its TEXT only
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) { $node->removeChild($c); continue; }
                    $node->replaceChild($d->createTextNode($c->textContent), $c); continue;
                }
                $drop = [];
                foreach ($c->attributes as $at) {
                    $n = strtolower($at->name); $v = $at->value;
                    $ok = ($n === 'class' && preg_match('/^[\w\- ]{0,60}$/', $v))
                       || ($tag === 'a' && $n === 'href' && (preg_match('#^(https?:)?//#i', trim($v)) || !preg_match('/^[a-z][a-z0-9+.\-]*:/i', trim($v))));
                    if (!$ok) $drop[] = $at->name;
                }
                foreach ($drop as $dn) $c->removeAttribute($dn);
                if ($tag === 'a') $c->setAttribute('rel', 'noopener nofollow');
                $walk($c);
            }
        };
        $walk($root);
        $out = '';
        foreach ($root->childNodes as $c) $out .= $d->saveHTML($c);
        return $out;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Dezzme | Search</title>
<script>/* only http(s) / relative links are allowed for user-supplied URLs (blocks javascript: and data: links) */
/* black words on a light button colour, white words on a dark one */
function dzCtaInk(c){
    c=String(c||'').trim().toLowerCase();var r,g,b,m;
    if((m=/^#([0-9a-f]{3})$/.exec(c))){r=parseInt(m[1][0]+m[1][0],16);g=parseInt(m[1][1]+m[1][1],16);b=parseInt(m[1][2]+m[1][2],16);}
    else if((m=/^#([0-9a-f]{6})/.exec(c))){r=parseInt(m[1].slice(0,2),16);g=parseInt(m[1].slice(2,4),16);b=parseInt(m[1].slice(4,6),16);}
    else if((m=/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/.exec(c))){r=+m[1];g=+m[2];b=+m[3];}
    else if(c==='white')return '#000';
    else return '#fff';
    return (0.2126*r+0.7152*g+0.0722*b)>160?'#000':'#fff';
}
window.dzSafeUrl = function(u){ u=String(u==null?'':u).trim(); if(/^(https?:)?\/\//i.test(u)) return u; if(/^[a-z][a-z0-9+.\-]*:/i.test(u)) return '#'; return u; };</script>
<style>
/* mute / unmute icon (custom speaker) */
.dz-snd{display:inline-block;width:1.12em;height:1em;background-color:currentColor;-webkit-mask:var(--dz-snd) center/contain no-repeat;mask:var(--dz-snd) center/contain no-repeat;vertical-align:middle;font-style:normal}
.dz-snd.on{--dz-snd:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%22261%20218%20681%20603%22%3E%3Cpath%20fill-rule%3D%22evenodd%22%20d%3D%22M576.2%2C820.0C560.6%2C817.2%20551.8%2C812.4%20515.6%2C787.7C500.1%2C777.2%20474.4%2C759.8%20458.5%2C749.0C442.6%2C738.3%20425.4%2C726.5%20420.5%2C722.9C415.6%2C719.3%20405.2%2C712.2%20397.5%2C707.0C381.1%2C696.0%20346.4%2C671.6%20333.5%2C661.9C314.1%2C647.4%20292.3%2C621.5%20281.6%2C600.2C258.9%2C555.2%20255.1%2C512.3%20270.0%2C467.9C277.7%2C444.5%20282.6%2C435.5%20299.8%2C412.0C309.3%2C399.1%20335.0%2C374.5%20346.8%2C367.0C365.5%2C355.1%20391.4%2C337.8%20401.0%2C330.6C412.7%2C322.0%20438.1%2C304.3%20460.9%2C289.0C470.3%2C282.7%20487.8%2C270.8%20499.8%2C262.6C559.6%2C221.4%20567.2%2C217.6%20589.5%2C217.6C628.4%2C217.6%20651.1%2C243.1%20654.0%2C290.0C655.3%2C310.7%20655.3%2C742.7%20654.0%2C756.0C649.5%2C802.0%20618.5%2C827.6%20576.2%2C820.0ZM747.9%2C729.0C732.2%2C726.4%20723.6%2C712.1%20725.3%2C691.7C727.0%2C671.8%20735.1%2C664.0%20760.3%2C658.0C794.4%2C649.8%20822.2%2C631.8%20843.7%2C603.9C862.9%2C578.9%20876.0%2C533.5%20872.1%2C504.8C869.5%2C485.1%20856.5%2C452.0%20846.5%2C439.5C819.9%2C406.3%20796.9%2C390.9%20760.7%2C382.0C736.8%2C376.1%20727.9%2C367.8%20724.9%2C348.7C721.1%2C324.7%20735.0%2C309.3%20758.5%2C311.4C807.1%2C315.8%20864.9%2C348.4%20895.2%2C388.5C915.9%2C416.0%20920.6%2C425.4%20936.6%2C471.0C943.6%2C491.1%20944.3%2C540.8%20938.0%2C565.7C923.1%2C623.6%20884.6%2C673.9%20832.7%2C702.9C803.4%2C719.3%20763.6%2C731.6%20747.9%2C729.0ZM738.0%2C584.8C732.1%2C581.6%20727.6%2C574.3%20726.0%2C565.2C724.3%2C556.1%20724.4%2C479.3%20726.1%2C472.4C737.4%2C425.3%20805.6%2C472.9%20801.7%2C525.2C799.0%2C559.4%20759.6%2C596.3%20738.0%2C584.8Z%22%2F%3E%3C%2Fsvg%3E)}
.dz-snd.off{--dz-snd:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%22261%20218%20681%20603%22%3E%3Cpath%20d%3D%22M576.2%2C820.0C560.6%2C817.2%20551.8%2C812.4%20515.6%2C787.7C500.1%2C777.2%20474.4%2C759.8%20458.5%2C749.0C442.6%2C738.3%20425.4%2C726.5%20420.5%2C722.9C415.6%2C719.3%20405.2%2C712.2%20397.5%2C707.0C381.1%2C696.0%20346.4%2C671.6%20333.5%2C661.9C314.1%2C647.4%20292.3%2C621.5%20281.6%2C600.2C258.9%2C555.2%20255.1%2C512.3%20270.0%2C467.9C277.7%2C444.5%20282.6%2C435.5%20299.8%2C412.0C309.3%2C399.1%20335.0%2C374.5%20346.8%2C367.0C365.5%2C355.1%20391.4%2C337.8%20401.0%2C330.6C412.7%2C322.0%20438.1%2C304.3%20460.9%2C289.0C470.3%2C282.7%20487.8%2C270.8%20499.8%2C262.6C559.6%2C221.4%20567.2%2C217.6%20589.5%2C217.6C628.4%2C217.6%20651.1%2C243.1%20654.0%2C290.0C655.3%2C310.7%20655.3%2C742.7%20654.0%2C756.0C649.5%2C802.0%20618.5%2C827.6%20576.2%2C820.0Z%22%2F%3E%3Cpath%20d%3D%22M730%20400L910%20620M910%20400L730%20620%22%20stroke%3D%22%23000%22%20stroke-width%3D%2264%22%20stroke-linecap%3D%22round%22%20fill%3D%22none%22%2F%3E%3C%2Fsvg%3E)}
/* post collaboration: one poster-sized box, pictures shrink as people join (2 = diagonal, 3 = trio) — same as the Zeems modal */
.av-wrap.ipv-collab{position:relative;display:block;flex:none;width:var(--cw,46px);height:var(--cw,46px)}
.av-wrap.ipv-collab .on-dot{display:none}
.av-wrap.ipv-collab img.ipv-avatar{position:absolute;box-sizing:border-box;width:66%;height:66%;margin:0}
.av-wrap.ipv-collab.n1 img.ipv-avatar:not(.ipv-co-pic){left:0;bottom:0;z-index:2}
.av-wrap.ipv-collab.n1 img.ipv-co-pic{right:0;top:0;z-index:1}
.av-wrap.ipv-collab.n2 img.ipv-avatar{width:58%;height:58%}
.av-wrap.ipv-collab.n2 img.ipv-avatar:not(.ipv-co-pic){left:21%;top:0;z-index:3}
.av-wrap.ipv-collab.n2 img.ipv-co-pic.c1{left:0;bottom:0;z-index:2}
.av-wrap.ipv-collab.n2 img.ipv-co-pic.c2{right:0;bottom:0;z-index:1}
.ipv-username.ipv-co-name{cursor:pointer}
/* list view: poster + collaborators side by side, slightly overlapping, so a collab post is obvious */
.post-co-stack{display:inline-flex;align-items:center;flex-shrink:0;cursor:pointer}
.post-co-stack .post-avatar{position:relative}
.post-co-stack .post-avatar + .post-avatar{margin-left:-8px}
.post-co-stack .post-avatar:nth-child(1){z-index:3}.post-co-stack .post-avatar:nth-child(2){z-index:2}.post-co-stack .post-avatar:nth-child(3){z-index:1}
.post-co-name{cursor:pointer}
</style>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<link rel="stylesheet" href="theme_css.php?v=<?= @filemtime(__DIR__.'/theme_css.php') ?>">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
* { font-family:'Poppins', sans-serif; }
a { color:inherit; text-decoration:none; }
img, video { display:block; }
button{
    -webkit-appearance:none; appearance:none;
    border:0; background:none; color:inherit; font:inherit;
    cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent;
}
a{ cursor:pointer; outline:none; }

:root{
    --bg:linear-gradient(-45deg,var(--bg-start),var(--bg-end),var(--bg-end));
    --muted-2:rgba(var(--fg),.32);
    --divider:rgba(var(--fg),.09);
    --purple:#8b7cff;
    --bar-h:36px;
    --thumb:112px;
    --pad-x:16px;
    --ccm-dot-ring:#000;
}
:root[data-theme="light"]{ --undo-bg:rgba(var(--fg),.09); }
/* light mode: the search bar stands out from the page (white box, clear border) */
:root[data-theme="light"] .sbar{ background:#fff; border-color:rgba(var(--fg),.24); box-shadow:0 1px 3px rgba(0,0,0,.07); }
:root[data-theme="light"] .sbar:hover{ border-color:rgba(var(--fg),.4); }
:root[data-theme="light"] .sbar input::placeholder{ color:rgba(var(--fg),.5); }
:root[data-theme="light"] .sbar-icon{ color:rgba(var(--fg),.5); }   /* "Post hidden · Undo" button in light mode */

/* green dot — exact same size / ring as home.php (32% of the avatar, 9–16px, page-coloured ring) */

html{ -webkit-tap-highlight-color:transparent; }
body{
    font-family:'Poppins',sans-serif;
    background:var(--bg);
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    overflow-x:hidden;
    display:flex; flex-direction:column; align-items:center;
    padding:0 0 76px;
}

@media (min-width:769px){
    body{ padding-left:var(--sidebar-w); align-items:flex-start; }
}

.page-shell{
    display:flex;
    justify-content:center;
    align-items:flex-start;
    width:100%;
}
.page{ width:100%; max-width:var(--lane-w); min-width:0; }
@media (min-width:769px){
    .page{ border-left:1px solid rgba(var(--fg),.1); border-right:1px solid rgba(var(--fg),.1); }
}

.sticky-head{
    position:sticky; top:0; z-index:200;
    background:var(--bg); background-attachment:fixed;
    padding-top:12px; padding-bottom:2px;
    transition:transform .28s ease;
    will-change:transform;
}
/* scrolling down the results hides the top bar + search bar; scrolling back up brings them back */
.sticky-head.head-hidden{ transform:translateY(-100%); }

.topbar{ display:flex; align-items:center; gap:12px; padding:14px var(--pad-x) 10px; }
.tb-title{ font-size:1.4rem; font-weight:700; color:var(--ui-text,#fff); letter-spacing:3px; white-space:nowrap; margin-right:auto; }
.tb-right{ display:flex; align-items:center; gap:16px; position:relative; }
.hft-btn{
    color:var(--ui-text,#fff); font-size:1.5rem; display:flex; align-items:center; justify-content:center;
    padding:2px; opacity:.9; transition:opacity .2s,color .2s; flex-shrink:0;
}
.hft-btn:hover,.hft-btn:active{ opacity:1; color:#FF2D2D; }
/* Your Algorithm button — same icon size as the nav bar: 24px on desktop, 28px on phones (the page is zoomed 1.1 there, so 25.5px shows as 28px) — then reduced 3% */
/* Algorithm icon = same look as the bell: same on-screen line weight (2px + 3%), same visible size, 3% dimmer */
.hft-btn .hft-algo-ico{ width:1.86rem; height:1.86rem; margin:-.155rem; display:block; stroke-width:1.72; filter:brightness(.97); }   /* drawn 1.2× bigger because the glyph fills less of its box than the bell does; line weight stays the same on screen */

.rp-tb-btn{background:none;border:none;color:var(--ui-text,#fff);font-size:1.55rem;display:flex;align-items:center;justify-content:center;opacity:.9;transition:opacity .2s,color .2s;text-decoration:none;padding:2px;position:relative;}
.rp-tb-btn:hover{opacity:1;color:var(--red);}
.rp-tb-dot{position:absolute;top:2px;right:2px;width:8px;height:8px;background:var(--red);border-radius:50%;border:1.5px solid var(--lane);}
.topbar .notif-bell{font-size:1.55rem;padding:2px;opacity:.9;}
.topbar .notif-bell:hover{opacity:1;}

.hft-sort-dropdown{
    display:none; position:absolute; top:38px; right:0;
    background:rgba(14,15,40,.97); backdrop-filter:blur(15px); -webkit-backdrop-filter:blur(15px);
    border-radius:12px; min-width:190px; flex-direction:column;
    z-index:210; box-shadow:0 8px 25px rgba(0,0,0,.35);
    border:1px solid rgba(255,255,255,.1); overflow:hidden;
}
.hft-sort-dropdown.show{ display:flex; animation:hftFadeDown .25s ease forwards; }
@keyframes hftFadeDown{ 0%{ opacity:0; transform:translateY(-6px); } 100%{ opacity:1; transform:translateY(0); } }
.hft-sort-dropdown a{
    display:flex; align-items:center; gap:10px; padding:12px 16px;
    color:#fff; font-size:13px; font-weight:500;
    border-bottom:1px solid rgba(255,255,255,.06); transition:background .2s,color .2s;
}
.hft-sort-dropdown a:last-child{ border-bottom:none; }
.hft-sort-dropdown a i{ color:#ff2a2a; width:16px; text-align:center; }
.hft-sort-dropdown a:hover{ background:rgba(255,255,255,.08); color:#ff2626; }
.hft-sort-dropdown a.active{ background:rgba(255,42,42,.2); box-shadow:inset 0 0 10px rgba(255,42,42,.4); }

.search-row{ display:flex; align-items:center; gap:10px; padding:2px 6px 10px; }   /* wider bar: less side padding */
.sbar{
    flex:1; min-width:0; display:flex; align-items:center;
    background:rgba(var(--fg),.07); border:1px solid rgba(var(--fg),.1);
    border-radius:4px; overflow:hidden; height:var(--sbar-h,40.3px);   /* sharper corners; thicker bar (own height, the filter pills keep --bar-h) */
    cursor:pointer; user-select:none; transition:border-color .2s;
}
.sbar:hover{ border-color:rgba(var(--fg),.2); }
.sbar-icon{ padding:0 6px 0 13px; color:rgba(var(--fg),.4); font-size:14px; flex-shrink:0; display:flex; align-items:center; }
.sbar-icon svg{ width:1.25em; height:1.25em; display:block; }   /* same search icon as the nav bar, smaller */
.sbar input{
    flex:1; min-width:0; padding:0 10px; height:100%;
    background:transparent; border:none; outline:none;
    color:var(--ui-text,#fff); font-size:13px; font-family:'Poppins',sans-serif;
    cursor:pointer; pointer-events:none; text-overflow:ellipsis;
}
.sbar input::placeholder{ color:rgba(var(--fg),.35); }
.sbar-clear{ color:rgba(var(--fg),.45); padding:0 12px; font-size:16px; line-height:1; display:flex; align-items:center; flex-shrink:0; transition:color .2s; }
.sbar-clear:hover{ color:var(--ui-text,#fff); }

/* hashtag bar CSS now lives in hashtags.php */

.posts{ padding:0 var(--pad-x); min-width:0; }
.post-row{
    position:relative; display:flex; align-items:flex-start; gap:14px;
    margin:0 calc(var(--pad-x) * -1);
    padding:16px var(--pad-x); border-bottom:5px solid var(--post-sep);
    cursor:pointer; transition:background .2s;
    opacity:0; animation:rowIn .4s ease forwards;
}
.post-row:last-child{ border-bottom:none; }
.post-row:hover{ background:rgba(var(--fg),.04); }
@keyframes rowIn{ from{ opacity:0; transform:translateY(8px); } to{ opacity:1; transform:translateY(0); } }

.post-media-col{ display:flex; flex-direction:column; align-items:flex-start; gap:6px; width:var(--thumb); flex-shrink:0; }
.post-thumb{ width:var(--thumb); height:var(--thumb); flex-shrink:0; border-radius:10px; overflow:hidden; position:relative; background:var(--surface-deep,#15171f); }
.post-thumb img,.post-thumb video{ width:100%; height:100%; object-fit:cover; }
.post-ad-tag{
    position:absolute; right:calc(var(--pad-x) + 6px); bottom:18px;
    font-size:12.5px; font-weight:800; color:#FF3B30; letter-spacing:.03em; text-transform:uppercase; line-height:1;
    user-select:none; pointer-events:none;
}
.thumb-badge{
    position:absolute; top:5px; right:5px; display:flex; align-items:center; gap:3px;
    background:rgba(4,6,18,.72); backdrop-filter:blur(3px); color:#fff;
    font-size:.58rem; font-weight:700; padding:2px 6px; border-radius:5px;
}
.thumb-badge i{ font-size:.7rem; }

.post-info{ flex:1; min-width:0; }
.post-head{ display:flex; align-items:center; gap:7px; min-width:0; margin-bottom:6px; padding-right:34px; }
.post-avatar{ width:24px; height:24px; border-radius:50%; object-fit:cover; border:2px solid red; flex-shrink:0; }
.post-username{ font-size:.82rem; font-weight:700; color:var(--ui-text,#fff); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
.post-dot{ color:rgba(var(--fg),.35); font-size:.8rem; flex-shrink:0; }
.post-nick{ font-size:.74rem; font-weight:500; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
.post-caption{
    font-size:.84rem; line-height:1.55; color:var(--cap, rgba(var(--fg),.85));
    word-break:break-word; margin-bottom:9px;
    display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical;
    overflow:hidden;
}
.cap-tag{ color:var(--gw, rgba(var(--fg),.65)); font-weight:600; }
.cap-mention{ color:var(--red); font-weight:600; }
.cap-tag:hover,.cap-mention:hover{ text-decoration:underline; }
.cap-more{ color:var(--cap, rgba(var(--fg),.85)); margin-left:1px; }

.post-stats{ display:flex; align-items:center; gap:18px; flex-wrap:wrap; }
.stat{ display:inline-flex; align-items:center; gap:5px; font-size:.74rem; font-weight:600; color:var(--ui-text,#fff); cursor:pointer; -webkit-tap-highlight-color:transparent; }
.sp-ic svg{ stroke-width:2; }
.stat.active .sp-fillable svg, .active .sp-fillable svg{ fill:currentColor; }
.sp-ic{ transition:color .2s, transform .12s ease; }
.sp-rp{ display:inline-block; width:1.166em; height:1.166em; margin:-.083em; line-height:0; font-style:normal; vertical-align:middle; }
.sp-rp svg{ display:block; width:100%; height:100%; overflow:visible; }
/* comments turned off for a post: the same "chat-off" icon as the Upload page instead of the speech bubble, and no number (the button still opens the sheet) */
.comment-btn svg.cm-off-ic{ display:none !important; }
.comment-btn.cm-off svg:not(.cm-off-ic){ display:none !important; }
.comment-btn.cm-off svg.cm-off-ic{ display:block !important; }      /* a thin stroke icon like the others (same bubble + one slash) */
.comment-count[data-off="1"]{ display:none !important; }
.stat i{ font-size:15.66px; line-height:1; color:var(--ui-text,#fff); transition:color .2s,transform .12s ease; text-rendering:geometricPrecision; -webkit-font-smoothing:antialiased; }
.stat:active i{ transform:scale(1.22); }
.stat.active i{ color:#ff2b55; }
/* outline icons like home — the filled one only shows once liked / saved */
.stat .ipv-ic-on{ display:none; }
.stat.active .ipv-ic-on{ display:inline; }
.stat.active .ipv-ic-off{ display:none; }
.stat.active{ color:var(--ui-text,#fff); }
@keyframes spRepostSwap{0%{transform:rotate(0) scale(1);}35%{transform:rotate(140deg) scale(1.15);}50%{transform:rotate(180deg) scale(1.2);}65%{transform:rotate(220deg) scale(1.15);}100%{transform:rotate(360deg) scale(1);}}
.stat.arc-swap-active i{ animation:spRepostSwap .6s cubic-bezier(.33,1,.68,1); transition:none !important; }

.post-kebab{
    position:absolute; right:calc(var(--pad-x) - 4px); top:12px; width:34px; height:34px;
    display:flex; align-items:center; justify-content:center; color:var(--ui-text,#fff); font-size:20px;
    border-radius:10px; transition:background .2s,transform .12s ease;
}
.post-kebab:hover{ background:rgba(var(--fg),.12); }
.post-kebab:active{ transform:scale(1.1); }

/* ══════════ AD CARDS — identical to the home page post card ══════════ */
.sp-adcard{ margin:0 calc(var(--pad-x) * -1); cursor:default; min-width:0; overflow:hidden; }

@keyframes ipvEq{0%{transform:scaleY(.35);}25%{transform:scaleY(1);}50%{transform:scaleY(.5);}75%{transform:scaleY(.85);}100%{transform:scaleY(.35);}}

/* ── BADGES — identical to the immersive modal (clamp() sizes × --ccm-s, same colours, same corners) ── */
.ipv-top-left-tags{ position:absolute; top:calc(clamp(7px, 6.55px + 0.35vw, 10px) * var(--ccm-s)); left:calc(clamp(7px, 6.55px + 0.35vw, 10px) * var(--ccm-s)); z-index:6; display:flex; flex-direction:column; align-items:flex-start; gap:calc(clamp(4px, 3.47px + 0.24vw, 6px) * var(--ccm-s)); pointer-events:none; }
.ipv-top-left-tags > *{ pointer-events:all; flex:none; width:max-content; }

.ipv-group-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(40,42,50,.78); border-radius:7px;
    padding:5px 9px 5px 7px; text-decoration:none;
    font-size:10px; font-weight:600; color:rgba(255,255,255,.85);
    max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ipv-group-tag i{ font-size:12px; color:rgba(255,255,255,.55); flex-shrink:0; }
.ipv-group-tag b{ color:#ff5f5f; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
@media (max-width:768px){ .ipv-group-tag{ transform:translateY(13%) scale(1.03); transform-origin:left top; } }   /* responsive: lowered 13% of its height, 3% bigger */

.ipv-ai-tag{ display:flex; align-items:center; gap:5px; background:rgba(59,130,246,.18); border:1px solid rgba(59,130,246,.35); border-radius:7px; padding:calc(clamp(3px, 2.24px + 0.26vw, 5px) * var(--ccm-s)) calc(clamp(6px, 5.01px + 0.43vw, 9px) * var(--ccm-s)) calc(clamp(3px, 2.24px + 0.26vw, 5px) * var(--ccm-s)) calc(clamp(4.5px, 3.78px + 0.31vw, 7px) * var(--ccm-s)); font-size:calc(clamp(8px, 7.51px + 0.3vw, 10px) * var(--ccm-s)); font-weight:600; color:#fff; backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); white-space:nowrap; }
.ipv-ai-tag i{ font-size:calc(clamp(9px, 8.01px + 0.43vw, 12.5px) * var(--ccm-s)); color:#7fb2ff; }
.ipv-top-right-tags{ position:absolute; top:calc(clamp(7px, 6.55px + 0.35vw, 10px) * var(--ccm-s)); right:calc(clamp(7px, 6.55px + 0.35vw, 10px) * var(--ccm-s)); z-index:6; display:flex; flex-direction:column; align-items:flex-end; gap:calc(clamp(4px, 3.47px + 0.24vw, 6px) * var(--ccm-s)); pointer-events:none; }
.ipv-media-counter{
    background:rgba(22,22,24,.78); color:#fff;
    font-size:10px; font-weight:800; letter-spacing:.3px;
    padding:4px 8px; border-radius:6px;
}
.ipv-type-tag{ display:flex; align-items:center; gap:4px; background:rgba(22,22,24,.78); border-radius:6px; padding:calc(clamp(3px, 3px + 0.16vw, 4px) * var(--ccm-s)) calc(clamp(6px, 5.01px + 0.43vw, 8px) * var(--ccm-s)) calc(clamp(3px, 3px + 0.16vw, 4px) * var(--ccm-s)) calc(clamp(4.5px, 3.78px + 0.31vw, 6px) * var(--ccm-s)); }
.ipv-type-tag i{ font-size:calc(clamp(9px, 8.01px + 0.43vw, 12px) * var(--ccm-s)); color:#fff; }
.ipv-type-tag span{ font-size:calc(clamp(7.5px, 7.01px + 0.3vw, 10px) * var(--ccm-s)); font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.3px; white-space:nowrap; }
.ipv-partnership-tag{ position:absolute; z-index:7; left:calc(clamp(8px, 7.02px + 0.59vw, 12px) * var(--ccm-s)); bottom:calc(clamp(22px, 18.05px + 1.73vw, 34px) * var(--ccm-s)); display:inline-flex; align-items:center; gap:calc(clamp(4px, 3.47px + 0.24vw, 6px) * var(--ccm-s)); padding:calc(clamp(3px, 2.51px + 0.3vw, 5px) * var(--ccm-s)) calc(clamp(6px, 5.02px + 0.59vw, 10px) * var(--ccm-s)); border-radius:calc(clamp(7px, 7.54px + 0.08vw, 8px) * var(--ccm-s)); background:rgba(0,0,0,0.55); color:#ff4c4c; font-size:calc(clamp(9px, 8.51px + 0.3vw, 11px) * var(--ccm-s)); font-weight:600; max-width:calc(100% - 24px); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); pointer-events:none; transition:opacity .25s ease, visibility .25s ease; }
.ipv-partnership-tag i{ font-size:calc(clamp(10px, 9.51px + 0.45vw, 13px) * var(--ccm-s)); flex-shrink:0; }
.ipv-partnership-tag b{ color:#fff; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* ad button: a full-width bar under the picture (same as home) */
.ipv-post .ipv-cta-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;box-sizing:border-box;width:100%;min-height:44px;padding:0 16px;background:var(--cta-color,#FF4C4C);color:var(--cta-ink,#fff);text-decoration:none;font-weight:600;font-size:14px;line-height:1.2;-webkit-tap-highlight-color:transparent;transition:filter .15s ease}
.ipv-post .ipv-cta-bar:active{filter:brightness(.92)}
.ipv-post .ipv-cta-bar-text{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ipv-post .ipv-cta-bar svg{flex:none}
.ipv-cta-pill{ position:absolute; left:calc(clamp(8px, 7.02px + 0.59vw, 12px) * var(--ccm-s)); bottom:calc(clamp(22px, 18.05px + 1.73vw, 30px) * var(--ccm-s)); z-index:6; display:flex; align-items:center; justify-content:flex-start; padding:calc(clamp(6px, 5.01px + 0.43vw, 9px) * var(--ccm-s)) calc(clamp(9px, 8.02px + 0.59vw, 14px) * var(--ccm-s)); border-radius:8px; background:rgba(30,30,34,.55); border:1px solid rgba(255,255,255,.28); color:#fff; font-weight:700; font-size:calc(clamp(9px, 8.51px + 0.3vw, 11.5px) * var(--ccm-s)); text-decoration:none; box-shadow:0 4px 14px rgba(0,0,0,.3); white-space:nowrap; overflow:hidden; max-width:calc(100% - 24px); transition:padding .4s cubic-bezier(.22,1,.36,1), background .4s ease, border-color .4s ease,
               box-shadow .4s ease, min-width .4s cubic-bezier(.22,1,.36,1), opacity .25s ease, visibility .25s ease; }
.ipv-cta-pill.ipv-sp-raised{ bottom:76px; font-size:calc(clamp(10.5px, 10.01px + 0.3vw, 13px) * var(--ccm-s)); }
.ipv-cta-pill-text{ position:relative; z-index:1; overflow:hidden; text-overflow:ellipsis; }
.ipv-cta-pill-chevron{
    display:inline-block; font-size:0; opacity:0; max-width:0;
    overflow:hidden; margin-left:0; flex-shrink:0;
    transition:opacity .3s ease, max-width .3s ease, margin-left .3s ease, font-size .3s ease;
}
.ipv-cta-pill.ipv-cta-pill-expanded{ background:var(--cta-color, #FF4C4C); border-color:transparent; box-shadow:0 6px 22px rgba(0,0,0,.45); padding:calc(clamp(7px, 6.02px + 0.59vw, 12px) * var(--ccm-s)) calc(clamp(11px, 9.56px + 0.94vw, 18px) * var(--ccm-s)); min-width:min(300px, calc(100% - 24px)); justify-content:space-between; }
.ipv-cta-pill.ipv-cta-pill-expanded .ipv-cta-pill-chevron{
    opacity:1; max-width:20px; margin-left:auto; padding-left:8px; font-size:15px;
}

@keyframes ipvSpFloat{0%,100%{transform:translateY(0);}50%{transform:translateY(-6px);}}

.ipv-mute-btn{ width:calc(clamp(22px, 20.64px + 1.05vw, 28px) * var(--ccm-s)); height:calc(clamp(22px, 20.64px + 1.05vw, 28px) * var(--ccm-s)); border-radius:50%; background:rgba(0,0,0,.5); border:none; color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.ipv-mute-btn i{ font-size:calc(clamp(11px, 10.32px + 0.53vw, 14px) * var(--ccm-s)); }

.ipv-text-bg{position:absolute;inset:0;background-size:cover;background-position:center;}
.ipv-text-overlay{position:absolute;inset:0;background:rgba(0,0,0,.45);}

.ipv-center-caption-text::-webkit-scrollbar{display:none;}

@keyframes ipv-repost-arc-swap{0%{transform:rotate(0) scale(1);}35%{transform:rotate(140deg) scale(1.15);}50%{transform:rotate(180deg) scale(1.2);}65%{transform:rotate(220deg) scale(1.15);}100%{transform:rotate(360deg) scale(1);}}

.ipv-ad-tag{ position:absolute; right:calc(clamp(6px, 5.02px + 0.59vw, 9px) * var(--ccm-s)); top:50%; transform:translateY(-50%); display:flex; align-items:center; font-size:calc(clamp(10.5px, 10.01px + 0.3vw, 12.5px) * var(--ccm-s)); font-weight:800; color:#FF3B30; letter-spacing:.03em; text-transform:uppercase; pointer-events:none; user-select:none; }

@keyframes ipvHeartBurst{0%{transform:translate(-50%,-50%) scale(0) rotate(-28deg);opacity:0;}14%{transform:translate(-50%,-50%) scale(1.35) rotate(10deg);opacity:1;}28%{transform:translate(-50%,-50%) scale(.88) rotate(-6deg);opacity:1;}42%{transform:translate(-50%,-50%) scale(1.14) rotate(4deg);opacity:1;}58%{transform:translate(-50%,-50%) scale(.97);opacity:1;}75%{transform:translate(-50%,-50%) scale(1.05);opacity:1;}100%{transform:translate(-50%,-50%) scale(1.15) translateY(-40px);opacity:0;}}

@keyframes ipvRingPulse{0%{transform:scale(.25);opacity:.9;border-width:4px;}100%{transform:scale(2.3);opacity:0;border-width:1px;}}

@keyframes ipvParticleFly{0%{transform:translate(-50%,-50%) scale(0);opacity:0;}12%{opacity:1;}100%{transform:translate(calc(-50% + var(--tx)),calc(-50% + var(--ty))) scale(1) rotate(var(--tr));opacity:0;}}
@keyframes ipvPostFadeIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}

@media(max-width:790px){
.ipv-partnership-tag{max-width:calc(100% - 22px);}}
@media(max-width:490px){
.ipv-partnership-tag{max-width:calc(100% - 20px);}}
@media(max-width:390px){
    .ipv-partnership-tag{max-width:calc(100% - 18px);}}
@media(max-width:330px){
    .ipv-partnership-tag{max-width:calc(100% - 16px);}}
.sp-load-more{ display:flex; align-items:center; justify-content:center; padding:24px 0; }
.sp-load-dot{ width:6px; height:6px; border-radius:50%; background:rgba(var(--fg),.35); margin:0 3px; animation:spLoadDot 1.1s ease-in-out infinite; }
.sp-load-dot:nth-child(2){ animation-delay:.15s; }
.sp-load-dot:nth-child(3){ animation-delay:.3s; }
@keyframes spLoadDot{ 0%,80%,100%{ transform:scale(.6); opacity:.4; } 40%{ transform:scale(1); opacity:1; } }
.sp-item.sp-hidden{ display:none; }
/* "Post hidden · Undo" row — words on the left, Undo button on the right. One clamp() per size instead of a media query per screen width */
.sp-nint-ph{
    display:flex; align-items:center; gap:clamp(11px,6.1px + 1vw,14px);
    margin:0 calc(var(--pad-x) * -1); padding:clamp(14px,10.7px + .67vw,16px) var(--pad-x); border-bottom:5px solid var(--post-sep);
    box-sizing:border-box; animation:nintIn .28s ease both;
}
.sp-nint-ph:last-child{ border-bottom:none; }
@keyframes nintIn{ from{ opacity:0; } to{ opacity:1; } }
.sp-nint-body{ flex:1; display:flex; flex-direction:column; justify-content:center; min-width:0; gap:2px; }
.sp-nint-body b{ color:var(--ui-text,#fff); font-size:clamp(.74rem,.64rem + .49vw,.88rem); font-weight:600; line-height:1.3; }
.sp-nint-body span{ color:rgba(var(--fg),.5); font-size:clamp(.64rem,.55rem + .42vw,.76rem); line-height:1.45; }
.sp-nint-undo{
    flex-shrink:0; background:var(--undo-bg,#2b2e3b); color:var(--ui-text,#fff); border:0; border-radius:5px;
    padding:0 clamp(12px,5px + 2.1vw,22px); height:var(--bar-h); font-family:'Poppins',sans-serif; font-weight:600;
    font-size:clamp(.7rem,.61rem + .42vw,.82rem); cursor:pointer; transition:background .2s,transform .15s;
}
.sp-nint-undo:hover,.sp-nint-undo:active{ background:#363a49; }
.sp-nint-undo:active{ transform:scale(.97); }
.sp-nint-ph.sp-nint-done .sp-nint-body b{ color:rgba(var(--fg),.75); }

.posts-skel{ padding:0 var(--pad-x); }
.sk-row{ display:flex; gap:14px; padding:16px 0; border-bottom:5px solid var(--post-sep); }
.sk-box,.sk-line{
    background:linear-gradient(100deg,rgba(var(--fg),.05) 30%,rgba(var(--fg),.13) 50%,rgba(var(--fg),.05) 70%);
    background-size:250% 100%; animation:skShimmer 1.5s ease-in-out infinite;
}
@keyframes skShimmer{ 0%{ background-position:150% 0; } 100%{ background-position:-50% 0; } }
.sk-box{ width:var(--thumb); height:var(--thumb); border-radius:10px; flex-shrink:0; }
.sk-lines{ flex:1; min-width:0; display:flex; flex-direction:column; gap:10px; padding-top:3px; }
.sk-line{ height:11px; border-radius:6px; }

.follow-btn,.group-btn{
    background:rgba(255,255,255,.15); color:#fff; padding:6px 14px; border-radius:25px;
    transition:all .3s ease; backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px);
    font-weight:600; position:relative; overflow:hidden; min-width:80px;
}
.follow-btn:hover{ background:#FF4C4C; transform:translateY(-2px) scale(1.05); box-shadow:0 10px 25px rgba(255,76,76,.3); }
.follow-btn:active,.group-btn:active{ transform:scale(.95); }
.follow-btn.following{ color:#FF4C4C; border:2px solid #FF4C4C; }
.follow-btn.following:hover{ background:#FF4C4C; color:#fff; }
.group-btn:hover{ background:#0673f0; transform:translateY(-2px) scale(1.05); box-shadow:0 10px 25px rgba(6,115,240,.3); }
.group-btn.joined{ color:#0673f0; border:2px solid #0673f0; }
.group-btn.joined:hover{ background:#0673f0; color:#fff; }
.follow-btn::before,.group-btn::before{ content:""; position:absolute; top:0; left:-80%; width:50%; height:100%; background:linear-gradient(120deg,transparent,rgba(255,255,255,.4),transparent); transform:skewX(-20deg); transition:.6s; }
.follow-btn:hover::before,.group-btn:hover::before{ left:120%; }

.sp-sugg-block{
    margin:0 calc(var(--pad-x) * -1);
    padding:16px var(--pad-x) 4px;
    border-top:1px solid rgba(var(--fg),.13);
    border-bottom:1px solid rgba(var(--fg),.13);
}
.sp-sugg-block:empty,.sp-mp-block:empty{ display:none; }
.sp-sugg-title{ font-size:.98rem; font-weight:800; color:var(--ui-text,#fff); letter-spacing:.1px; }
.sp-sugg-sub{ font-size:.68rem; color:rgba(var(--fg),.4); margin-top:2px; margin-bottom:12px; }
.sp-sugg-row{
    display:flex; align-items:center; gap:12px;
    padding:9px 0; border-bottom:1px solid rgba(var(--fg),.055);
}
.sp-sugg-row:last-of-type{ border-bottom:none; }
.sp-sugg-avatar{ width:42px; height:42px; border-radius:50%; object-fit:cover; border:2px solid var(--red); flex-shrink:0; }
.sp-sugg-info{ flex:1; min-width:0; }
.sp-sugg-name{ font-size:.83rem; font-weight:700; color:var(--ui-text,#fff); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }
.sp-sugg-reason{ font-size:.7rem; color:rgba(var(--fg),.42); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sp-sugg-track-btn{
    flex-shrink:0; padding:7px 13px;
    background:var(--red); color:#000;
    font-weight:700; font-size:.7rem; letter-spacing:.2px;
    border-radius:5px;
    transition:opacity .2s, background .2s, color .2s;
    -webkit-tap-highlight-color:transparent;
}
.sp-sugg-track-btn:active{ opacity:.75; }
.sp-sugg-track-btn.following{
    background:transparent; border:1.5px solid var(--red); color:var(--red);
}
.sp-sugg-more-row{ display:flex; align-items:center; gap:16px; padding:10px 0 6px; }
.sp-sugg-more-btn,.sp-sugg-less-btn{
    display:flex; align-items:center; gap:3px;
    font-size:.74rem; font-weight:700; color:var(--red);
    -webkit-tap-highlight-color:transparent;
}
.sp-sugg-more-btn i,.sp-sugg-less-btn i{ font-size:.9rem; }
.hp-coll{ margin:0 calc(var(--pad-x) * -1); padding:12px 0 4px; border-top:1px solid rgba(255,255,255,.1); border-bottom:1px solid rgba(255,255,255,.1); }
.hp-coll-head{ padding:0 var(--pad-x) 10px; }
.hp-coll-kicker{ font-size:.62rem; color:rgba(255,255,255,.42); line-height:1.2; }
.hp-coll-title{ font-size:.95rem; font-weight:600; color:#fff; line-height:1.3; }
.hp-coll-row{ display:flex; align-items:center; gap:12px; padding:9px var(--pad-x); border-bottom:1px solid rgba(255,255,255,.055); cursor:pointer; transition:background .15s; }
.hp-coll-row:last-of-type{ border-bottom:none; }
.hp-coll-row:hover{ background:rgba(255,255,255,.03); }
.hp-coll-thumb{ width:48px; height:48px; border-radius:9px; object-fit:cover; background:rgba(255,255,255,.08); border:1.5px solid rgba(255,255,255,.14); flex-shrink:0; display:block; }
.hp-coll-thumb-empty{ display:flex; align-items:center; justify-content:center; color:rgba(255,255,255,.32); font-size:19px; }
.hp-coll-info{ flex:1; min-width:0; }
.hp-coll-name{ font-size:.83rem; font-weight:700; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hp-coll-meta{ font-size:.7rem; color:rgba(255,255,255,.42); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:5px; }
.hp-coll-count{ color:#fff; font-weight:600; }
.hp-coll-owner{ color:rgba(255,255,255,.42); }
.hp-coll-vis{ font-size:.62rem; font-weight:600; padding:1px 6px; border-radius:5px; border:1px solid rgba(255,255,255,.16); color:rgba(255,255,255,.5); flex-shrink:0; }
.hp-coll-chevron{ color:rgba(255,255,255,.3); font-size:20px; flex-shrink:0; }
.hp-coll-more-row{ display:flex; align-items:center; gap:16px; padding:10px var(--pad-x) 8px; }
.hp-coll-more-btn,.hp-coll-less-btn{ display:flex; align-items:center; gap:3px; color:var(--red); font-size:.74rem; font-weight:700; -webkit-tap-highlight-color:transparent; }
.hp-coll-skel-row{ display:flex; align-items:center; gap:12px; padding:9px var(--pad-x); }
.hp-coll-skel-thumb{ width:48px; height:48px; border-radius:9px; flex-shrink:0; }
.hp-coll-skel-info{ flex:1; min-width:0; display:flex; flex-direction:column; gap:6px; }
.hp-coll-skel-line{ height:10px; border-radius:5px; }
.hp-coll-skel-line.w1{ width:55%; }
.hp-coll-skel-line.w2{ width:35%; height:8px; }
.hp-coll-skel-thumb,.hp-coll-skel-line{
    background:linear-gradient(90deg,rgba(255,255,255,.05) 25%,rgba(255,255,255,.13) 37%,rgba(255,255,255,.05) 63%);
    background-size:400% 100%;
    animation:skShimmer 1.4s ease-in-out infinite;
}
.sp-mp-block{
    margin:0 calc(var(--pad-x) * -1);
    padding:14px 0 2px;
    border-top:1px solid rgba(var(--fg),.1);
    border-bottom:1px solid rgba(var(--fg),.1);
}
.sp-mp-head{ display:flex; align-items:center; justify-content:space-between; padding:0 var(--pad-x) 4px; }
.sp-mp-kicker{ font-size:.62rem; color:rgba(var(--fg),.45); line-height:1.2; }
.sp-mp-title{ font-size:.95rem; font-weight:600; color:var(--ui-text,#fff); line-height:1.3; display:flex; align-items:center; gap:6px; }
.sp-mp-title i{ color:var(--red); }
.sp-mp-x{ color:rgba(var(--fg),.7); font-size:22px; display:flex; padding:2px; }
.sp-mp-pills{ display:flex; gap:7px; overflow-x:auto; padding:8px var(--pad-x) 10px; scrollbar-width:none; -ms-overflow-style:none; }
.sp-mp-pills::-webkit-scrollbar{ display:none; }
.sp-mp-pill{
    flex-shrink:0; padding:6px 14px; border-radius:6px;
    border:1px solid rgba(var(--fg),.18); background:rgba(var(--fg),.05);
    color:var(--ui-text,#fff); font-size:.72rem; font-weight:600; white-space:nowrap;
    transition:all .2s; -webkit-tap-highlight-color:transparent;
}
.sp-mp-pill:hover{ background:rgba(var(--fg),.1); color:var(--ui-text,#fff); }
.sp-mp-pill.active{ background:var(--red); border-color:var(--red); color:#fff; }
.sp-mp-scroll-wrap{ position:relative; }
.sp-mp-scroll{
    display:flex; gap:10px; overflow-x:auto; padding:0 var(--pad-x) 16px;
    scroll-padding-left:var(--pad-x); scroll-snap-type:x proximity;
    scrollbar-width:none; -ms-overflow-style:none; -webkit-overflow-scrolling:touch;
}
.sp-mp-scroll::-webkit-scrollbar{ display:none; }
.sp-mp-scroll::after{ content:""; flex:0 0 6px; }
.sp-mp-arrow{
    position:absolute; top:calc(50% + 10px); transform:translateY(-50%); z-index:10;
    width:34px; height:34px; border-radius:8px; padding:0;
    background:rgba(0,0,0,.7); border:1.5px solid rgba(255,255,255,.25);
    color:#fff; display:flex; align-items:center; justify-content:center;
    opacity:0; pointer-events:none;
    transition:opacity .25s ease, background .2s ease;
    font-size:24px; line-height:1;
}
.sp-mp-arrow i{ display:block; font-size:24px; line-height:1; }
.sp-mp-arrow-l{ left:6px; } .sp-mp-arrow-r{ right:6px; }
.sp-mp-arrow:hover{ background:rgba(255,45,45,.85); }
.sp-mp-scroll-wrap:hover .sp-mp-arrow:not(.hidden),
.sp-mp-scroll-wrap.show-arrows .sp-mp-arrow:not(.hidden){ opacity:1; pointer-events:auto; }
.sp-mp-arrow.hidden{ opacity:0 !important; pointer-events:none !important; }
@media(max-width:768px){ .sp-mp-arrow{ display:none !important; } }
.sp-mp-card{
    flex:0 0 260px; scroll-snap-align:start; border-radius:6px; overflow:hidden;
    position:relative; background:var(--surface-2,#111226); border:1px solid rgba(var(--fg),.1);
    cursor:pointer; -webkit-tap-highlight-color:transparent; transition:transform .15s ease;
}
.sp-mp-card:active{ transform:scale(.97); }
.sp-mp-thumb{ width:100%; aspect-ratio:16/13; object-fit:cover; display:block; background:linear-gradient(135deg,#262b3d,#171a24); }
.sp-mp-thumb-empty{ width:100%; aspect-ratio:16/13; display:flex; align-items:center; justify-content:center; font-size:1.8rem; background:linear-gradient(135deg,#262b3d,#171a24); }
.sp-mp-badge{
    position:absolute; top:8px; left:8px; display:flex; align-items:center; gap:4px;
    padding:3px 9px; border-radius:6px; background:rgba(10,11,30,.75); backdrop-filter:blur(4px);
    font-size:.62rem; font-weight:700; color:#a9bdf5; border:1px solid rgba(120,150,255,.35);
}
.sp-mp-badge i{ font-size:.68rem; }
.sp-mp-info{ padding:9px 10px 11px; }
.sp-mp-card-title{
    font-size:.78rem; font-weight:700; color:var(--ui-text,#fff); line-height:1.32;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:4px;
}
.sp-mp-card-meta{ font-size:.62rem; color:rgba(var(--fg),.4); display:flex; align-items:center; gap:8px; }
.sp-mp-card-meta i{ font-size:.68rem; }
.sp-mp-skel-pill{ flex-shrink:0; width:70px; height:26px; border-radius:6px; }
.sp-mp-skel-card{ flex:0 0 260px; border-radius:6px; overflow:hidden; background:var(--surface-2,#111226); }
.sp-mp-skel-thumb{ width:100%; aspect-ratio:16/13; }
.sp-mp-skel-line{ height:9px; border-radius:5px; margin:9px 10px 0; }
.sp-mp-skel-pill,.sp-mp-skel-thumb,.sp-mp-skel-line{
    background:linear-gradient(90deg,rgba(var(--fg),.05) 25%,rgba(var(--fg),.13) 37%,rgba(var(--fg),.05) 63%);
    background-size:400% 100%; animation:skShimmer 1.4s ease-in-out infinite;
}

@media(min-width:1100px){
    .page-shell{justify-content:flex-start;padding-left:22px;}
    .page{margin:0;flex:0 0 var(--lane-w);width:var(--lane-w);}

}

.pf-card{margin-bottom:14px;}
.pf-bar{
    display:flex;align-items:center;gap:7px;width:100%;
    height:var(--rp-action-h);padding:0 12px;
    border:none;border-radius:var(--rp-action-radius);
    background:rgba(255,255,255,0.1);
    backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);
    color:#fff;font-size:.72rem;font-weight:600;line-height:1;text-align:left;
    transition:background .2s ease,transform .15s ease;
}
.pf-bar:hover{background:rgba(255,255,255,0.2);}
.pf-bar:active{transform:scale(.99);}
.pf-bar-ic{font-size:14px;color:var(--red);flex-shrink:0;}
.pf-bar-t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pf-bar-c{font-size:.64rem;font-weight:600;color:rgba(255,255,255,.55);flex-shrink:0;}
.pf-bar-chev{font-size:18px;color:rgba(255,255,255,.75);flex-shrink:0;transition:transform .25s ease;}
.pf-card.collapsed .pf-bar-chev{transform:rotate(-90deg);}
.pf-body{display:grid;grid-template-rows:1fr;transition:grid-template-rows .3s ease;}
.pf-inner{min-height:0;overflow:hidden;}
.pf-pad{padding-top:8px;}
.pf-card.collapsed .pf-body{grid-template-rows:0fr;}

.pf-more{
    display:flex;align-items:center;justify-content:center;width:100%;
    height:var(--rp-action-h);margin-top:8px;padding:0 12px;
    border:none;border-radius:var(--rp-action-radius);
    background:rgba(255,255,255,0.1);
    backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);
    color:#fff;font-size:.7rem;font-weight:600;text-decoration:none;line-height:1;
    transition:background .2s ease,transform .15s ease;
}
.pf-more:hover{background:rgba(255,255,255,0.2);}
.pf-more:active{transform:scale(.98);}

.pf-row{display:flex;align-items:center;gap:10px;padding:8px 2px;color:#fff;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.06);}
.pf-row:last-of-type{border-bottom:none;}
.pf-row.clickable{cursor:pointer;}
.pf-row.clickable:hover .pf-row-t{text-decoration:underline;}
.pf-hold{display:none;}
.pf-in{animation:pfRowIn .3s cubic-bezier(.22,1,.36,1) both;}
@keyframes pfRowIn{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}
.pf-av{width:34px;height:34px;border-radius:50%;object-fit:cover;border:1.5px solid var(--red);flex-shrink:0;display:block;background:#0d1030;}
.pf-av.sq{border-radius:8px;border-color:rgba(255,255,255,.14);}

.pf-row-info{flex:1;min-width:0;}
.pf-row-t{display:block;font-size:.76rem;font-weight:600;color:#fff;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

.pf-row-s{font-size:.64rem;color:rgba(255,255,255,.5);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pf-track{flex-shrink:0;height:24px;padding:0 13px;border-radius:4px;background:var(--red);color:#000;font-size:.66rem;font-weight:700;line-height:1;transition:background .2s;}
.pf-track:hover{background:#ff1a1a;}
.pf-track.following{background:rgba(255,255,255,.1);color:#fff;}

.pf-chips{display:flex;flex-wrap:wrap;gap:6px;}
.pf-chip{
    display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:var(--rp-action-radius);
    background:rgba(255,255,255,.07);color:#fff;font-size:.68rem;font-weight:600;text-decoration:none;line-height:1;
    transition:background .2s ease;
}

.pf-chip.on{background:var(--red);color:#000;}

.pf-find{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
.pf-find-item{display:flex;align-items:center;gap:8px;padding:8px 9px;background:rgba(255,255,255,.05);border-radius:var(--rp-action-radius);min-width:0;}
.pf-find-item i{font-size:15px;color:var(--red);flex-shrink:0;}
.pf-find-item div{min-width:0;}
.pf-find-item b{display:block;font-size:.7rem;font-weight:600;color:#fff;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pf-find-item small{display:block;font-size:.58rem;color:rgba(255,255,255,.5);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pf-cta{
    display:flex;align-items:center;justify-content:center;gap:6px;width:100%;
    height:var(--rp-action-h);margin-top:8px;padding:0 12px;
    border-radius:var(--rp-action-radius);background:var(--red);color:#000;
    font-size:.7rem;font-weight:700;line-height:1;transition:background .2s ease,transform .15s ease;
}
.pf-cta:hover{background:#ff1a1a;}
.pf-cta:active{transform:scale(.98);}

.pf-tag-h{width:26px;height:26px;border-radius:var(--rp-action-radius);flex-shrink:0;background:rgba(255,255,255,.07);color:var(--purple);display:flex;align-items:center;justify-content:center;font-size:12px;}
.pf-tag-f{flex-shrink:0;font-size:.56rem;font-weight:700;color:var(--red);background:rgba(255,45,45,.12);padding:3px 7px;border-radius:var(--rp-action-radius);}
.pf-tag-chev{color:rgba(255,255,255,.3);font-size:16px;flex-shrink:0;}
.pf-row.pf-tag-on{background:rgba(255,45,45,.1);}
.pf-row.pf-tag-on .pf-tag-h{background:var(--red);color:#000;}
a.pf-row:hover .pf-row-t{text-decoration:underline;}
.pf-row-t.two{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.3;}

.pf-about{padding:4px 2px 2px;}
.pf-about p{font-size:.72rem;line-height:1.7;color:rgba(255,255,255,.72);margin-bottom:9px;}
.pf-about p:last-child{margin-bottom:0;}
.pf-about b{color:#fff;font-weight:600;}

/* ── Responsive sizing: one clamp() per value (smallest .. fluid .. largest) instead of a media query per screen width.
      Only screens up to 790px wide change; wider screens keep the normal sizes above. ── */
@media (max-width:790px){

    .ipv-cta-pill.ipv-sp-raised{ bottom:clamp(48px,40.522px + 3.043vw,62px); }
    
    .ipv-group-tag{ font-size:clamp(9px,8.183px + 0.167vw,9.5px); }
    .ipv-media-counter{ font-size:clamp(9px,8.183px + 0.167vw,9.5px); padding:4px clamp(7px,3.733px + 0.667vw,8px); }

    .ipv-cta-pill{ gap:4px; }
    .sp-sugg-block{ padding:clamp(14px,7.467px + 1.333vw,16px) var(--pad-x) clamp(3px,0.667vw - 0.267px,4px); }
    .sp-sugg-title{ font-size:clamp(0.9rem,0.639rem + 0.853vw,0.98rem); }
    .sp-sugg-sub{ font-size:clamp(0.64rem,0.509rem + 0.427vw,0.68rem); margin-bottom:clamp(10px,3.467px + 1.333vw,12px); }
    .sp-sugg-avatar{ width:clamp(34px,21.92px + 3.2vw,42px); height:clamp(34px,21.92px + 3.2vw,42px); }
    .sp-sugg-name{ font-size:clamp(0.74rem,0.602rem + 0.576vw,0.83rem); }
    .sp-sugg-reason{ font-size:clamp(0.62rem,0.499rem + 0.512vw,0.7rem); }
    .sp-sugg-track-btn{ padding:clamp(5.5px,1.084px + 1.003vw,7px) clamp(10px,1.167px + 2.006vw,13px); font-size:clamp(0.62rem,0.499rem + 0.512vw,0.7rem); border-radius:clamp(4px,2vw - 3.8px,5px); }
    .sp-sugg-more-btn{ font-size:clamp(0.7rem,0.569rem + 0.427vw,0.74rem); }
    .sp-sugg-less-btn{ font-size:clamp(0.7rem,0.569rem + 0.427vw,0.74rem); }
    .hp-coll-kicker{ font-size:clamp(0.56rem,0.364rem + 0.64vw,0.62rem); }
    .hp-coll-title{ font-size:clamp(0.82rem,0.395rem + 1.387vw,0.95rem); }
    .hp-coll-thumb{ width:clamp(42px,22.4px + 4vw,48px); height:clamp(42px,22.4px + 4vw,48px); }
    .hp-coll-name{ font-size:clamp(0.76rem,0.531rem + 0.747vw,0.83rem); }
    .hp-coll-meta{ font-size:clamp(0.63rem,0.401rem + 0.747vw,0.7rem); }
    .hp-coll-row{ gap:clamp(10px,3.467px + 1.333vw,12px); padding-top:clamp(8px,4.733px + 0.667vw,9px); padding-bottom:clamp(8px,4.733px + 0.667vw,9px); }
    .sp-mp-title{ font-size:clamp(0.82rem,0.395rem + 1.387vw,0.95rem); }
    .sp-mp-kicker{ font-size:clamp(0.56rem,0.364rem + 0.64vw,0.62rem); }
    .sp-mp-card-title{ font-size:clamp(0.72rem,0.524rem + 0.64vw,0.78rem); }
    .sp-mp-card-meta{ font-size:clamp(0.58rem,0.449rem + 0.427vw,0.62rem); }
    .sp-mp-pill{ font-size:clamp(0.66rem,0.464rem + 0.64vw,0.72rem); padding:clamp(5px,1.733px + 0.667vw,6px) clamp(11px,1.2px + 2vw,14px); }
    :root{ --sbar-h:clamp(40.3px,35.04px + 1.87vw,48px); --bar-h:clamp(32px,27.87px + 1.739vw,40px); --thumb:clamp(68px,51.043px + 6.087vw,96px); --pad-x:clamp(8px,3.761px + 1.522vw,15px); }
    .sbar{ border-radius:clamp(3px,1.3px + 0.5vw,5px); }
    .sbar-icon{ font-size:18.5px; padding:0 6px 0 clamp(12px,8.733px + 0.667vw,13px); }
    .sbar input{ font-size:clamp(12.5px,10.8px + .65vw,15px); }
    .tb-title{ font-size:clamp(0.95rem,0.763rem + 1.217vw,1.3rem); letter-spacing:clamp(1px,0.622vw - 0.989px,3px); }
    .topbar{ padding:clamp(12px,5.467px + 1.333vw,14px) var(--pad-x) clamp(8px,1.467px + 1.333vw,10px); }
    .hft-btn{ font-size:clamp(1.15rem,0.794rem + 1.806vw,1.5rem); }
    .rp-tb-btn{ font-size:clamp(1.15rem,0.752rem + 1.99vw,1.55rem); }
    .topbar .notif-bell{ font-size:clamp(1.15rem,0.752rem + 1.99vw,1.55rem); }
    .hft-btn .hft-algo-ico{ width:clamp(1.38rem,0.902rem + 2.39vw,1.86rem); height:clamp(1.38rem,0.902rem + 2.39vw,1.86rem); margin:clamp(-.155rem,-.0995rem - .2vw,-.115rem); }
    .tb-right{ gap:clamp(14px,7.467px + 1.333vw,16px); }
    .post-row{ gap:clamp(11px,1.2px + 2vw,14px); padding-top:clamp(14px,7.467px + 1.333vw,16px); padding-bottom:clamp(14px,7.467px + 1.333vw,16px); }
    .post-avatar{ width:clamp(21px,11.2px + 2vw,24px); height:clamp(21px,11.2px + 2vw,24px); }
    .post-username{ font-size:clamp(0.78rem,0.649rem + 0.427vw,0.82rem); }
    .post-nick{ font-size:clamp(0.7rem,0.569rem + 0.427vw,0.74rem); }
    .post-caption{ font-size:clamp(0.72rem,0.601rem + 0.619vw,0.84rem); margin-bottom:clamp(8px,4.733px + 0.667vw,9px); }
    .post-stats{ gap:clamp(12px,4.013vw - 5.666px,18px); }
    .stat{ font-size:clamp(0.66rem,0.574rem + 0.413vw,0.74rem); }
    .stat i{ font-size:15.66px; }
    .post-ad-tag{ font-size:clamp(10.5px,8.511px + 0.622vw,12.5px); bottom:clamp(16px,9.467px + 1.333vw,18px); }
    .post-kebab{ width:clamp(30px,16.933px + 2.667vw,34px); height:clamp(30px,16.933px + 2.667vw,34px); font-size:clamp(18px,11.467px + 1.333vw,20px); top:clamp(8px,2.667vw - 5.067px,12px); }
    .sp-mp-card{ flex-basis:clamp(210px,46.667px + 33.333vw,260px); }
    .sp-mp-skel-card{ flex-basis:clamp(210px,46.667px + 33.333vw,260px); }
}

@media(max-width:768px){
    html, body, *{scrollbar-width:none !important;-ms-overflow-style:none !important;}
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{display:none !important;width:0 !important;height:0 !important;background:transparent !important;}
}
@media (max-width:768px){
    @supports (zoom:1){
        .page-shell{ zoom:1.1; }
        #searchModal .sm-in,
        #searchModal #smResultsInner{ zoom:1.1; }
    }
}
/* the phone page is zoomed 1.1, which made every ad post 10% bigger than on home / text feed: cancel the zoom on the ad post only,
   so caption, liked-by, date, rail and badges come out exactly the same size as home */
@media (max-width:768px){
    @supports (zoom:1){
        .page-shell .sp-adcard{ zoom:.9091; }
    }
}
</style>
<?php dz_offline_styles(); ?>
<!-- Dezzme verified flower + NEW word: same design/placement as the immersive modal -->
<style id="dz-vbnew-css">
.ipv-username-row > .dz-vb{ width:var(--dzvb,13px); height:var(--dzvb,13px); margin-left:-2px; color:var(--text,#F0F2FF); position:relative; top:calc(var(--dzvb,13px) * -0.11); }
.ipv-post.ipv-long-header .ipv-username-row > .dz-vb{ color:#fff; filter:drop-shadow(0 1px 4px rgba(0,0,0,.5)); }
.dz-vb{ flex-shrink:0; display:inline-block; vertical-align:-.15em; }
.ipv-actions{ position:relative; }
.dz-newtag{ position:absolute; right:calc(clamp(6px, 5.02px + 0.59vw, 9px) * var(--ccm-s, 1)); top:50%; transform:translateY(-50%); display:flex; align-items:center; font-size:calc(clamp(10.5px, 10.01px + 0.3vw, 12.5px) * var(--ccm-s, 1)); font-weight:800; color:var(--red,#FF2D2D); letter-spacing:.03em; text-transform:uppercase; pointer-events:none; user-select:none; }
.ipv-ad-tag.dz-flip{ display:grid; perspective:140px; }
.dz-flip .dz-fa, .dz-flip .dz-fb{ grid-area:1/1; justify-self:end; backface-visibility:hidden; -webkit-backface-visibility:hidden; }
.dz-flip .dz-fa{ color:#FF3B30; animation:dzFlipA 16s infinite; }
.dz-flip .dz-fb{ color:var(--red,#FF2D2D); animation:dzFlipB 16s infinite; }
@keyframes dzFlipA{ 0%,47%{ transform:rotateX(0) } 50%,97%{ transform:rotateX(-180deg) } 100%{ transform:rotateX(-360deg) } }
@keyframes dzFlipB{ 0%,47%{ transform:rotateX(180deg) } 50%,97%{ transform:rotateX(0) } 100%{ transform:rotateX(-180deg) } }
.post-head > .dz-vb-list{ width:calc(.82rem * .9); height:calc(.82rem * .9); margin-left:-3px; color:var(--red,#FF2D2D); position:relative; top:-1px; }
@media(max-width:900px){ .post-head > .dz-vb-list{ width:clamp(.7rem,.58rem + .38vw,.74rem); height:clamp(.7rem,.58rem + .38vw,.74rem); } }
</style>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><symbol id="dz-vb-sym" viewBox="0 0 256 256"><path fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></symbol></svg>
<script>
window.DZ_VB='<svg class="dz-vb" viewBox="0 0 256 256" aria-label="Verified" fill="currentColor"><path fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></svg>';
window.dzAdTag=function(p,isNew){
  if(p.isAd) return isNew ? '<div class="ipv-ad-tag dz-flip"><span class="dz-fa">Ad</span><span class="dz-fb">New</span></div>' : '<div class="ipv-ad-tag">Ad</div>';
  return isNew ? '<div class="dz-newtag">New</div>' : '';
};
(function(){
  var raf=0;
  function sz(){ raf=0; try{ var u=document.querySelector('.ipv-username'); if(u){ var f=parseFloat(getComputedStyle(u).fontSize); if(f>0) document.documentElement.style.setProperty('--dzvb', f+'px'); } }catch(e){} }
  function q(){ if(!raf) raf=requestAnimationFrame(sz); }
  document.addEventListener('DOMContentLoaded',function(){ q(); try{ new MutationObserver(q).observe(document.body,{childList:true,subtree:true}); }catch(e){} window.addEventListener('resize',q); });
})();
</script>
<style id="dzPartnerFlip">
/* "Paid partnership" flips under the username like the nickname / audio: a little smaller than the nickname, all white, a touch bolder */
.ipv-sub-line.ipv-partner-line{ color:#fff; font-weight:500; }
.ipv-sub-line.ipv-partner-line span{ font-size:.92em; }
.ipv-sub-line.ipv-partner-line b{ color:#fff; font-weight:600; }
</style>
</head>
<body>
<?php dz_offline_ui('body'); ?>
<script>try{ if('serviceWorker' in navigator) navigator.serviceWorker.register('service-worker.js').catch(function(){}); }catch(e){}</script>

<?php include 'nav.php'; /* sidebar (desktop) / bottom bar (mobile) */ ?>

<div class="page-shell">
<div class="page">

    <div class="sticky-head">

        <div class="search-row">
            <div class="sbar" id="searchTrigger" onclick="openSearchModal()">
                <span class="sbar-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/><path fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/></svg></span>
                <input type="text" readonly placeholder="Search users, posts, sounds, DZ-ID..." value="<?= htmlspecialchars($search_query ?? '') ?>">
                <?php if(!empty($search_query)): ?>
                <a href="search.php" class="sbar-clear" onclick="event.stopPropagation()" aria-label="Clear search"><i class="ri-close-line"></i></a>
                <?php endif; ?>
            </div>
        </div>

    </div>


    <?php if (($tag ?? 'all') === 'all' || ($tag ?? '') === ''): /* news + sports strip: default Search page only, never inside a hashtag view */ ?>
    <section class="dzns" id="dzNs" hidden aria-label="News and sports">
        <div class="dzns-tabs" role="tablist">
            <button type="button" class="dzns-tab on" data-f="all">Top</button>
            <button type="button" class="dzns-tab" data-f="news">News</button>
            <button type="button" class="dzns-tab" data-f="sports">Sports</button>
            <button type="button" class="dzns-tab" data-f="ent">Entertainment</button>
        </div>
        <div class="dzns-rw"><button type="button" class="dzns-arr l" id="dzNsL" aria-label="Scroll left" hidden><i class="ri-arrow-left-s-line"></i></button><div class="dzns-row" id="dzNsRow"></div><button type="button" class="dzns-arr r" id="dzNsR" aria-label="Scroll right" hidden><i class="ri-arrow-right-s-line"></i></button></div>
    </section>
    <script>window.DZNS=<?= json_encode($DZNS, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
    <style>
    .dzns{ margin:0 0 10px; padding-bottom:10px; }
    .dzns[hidden]{ display:none; }
    .dzns-tabs{ display:flex; gap:8px; padding:4px var(--pad-x) 10px; }
    .dzns-tab{ flex:1 0 auto; display:flex; align-items:center; justify-content:center; padding:4.5px 10px; border-radius:5px; font-size:11.84px; font-weight:500; letter-spacing:.2px; line-height:1.3; white-space:nowrap;
        color:var(--ui-sub2,rgba(255,255,255,.8)); background:rgba(var(--fg),.07); }   /* same look as the hashtag pills */
    .dzns-tab[data-f="all"]{ flex-grow:3; } .dzns-tab[data-f="news"]{ flex-grow:4; } .dzns-tab[data-f="sports"]{ flex-grow:6; } .dzns-tab[data-f="ent"]{ flex-grow:13; }   /* wider word = wider button */
    .dzns-tab.on{ background:var(--red,#FF2D2D); color:#000; font-weight:600; }   /* same red as the Track button */
    .dzns-dot{ width:7px; height:7px; border-radius:50%; background:#ff3b4e; animation:dznsPulse 1.4s ease-in-out infinite; }
    @keyframes dznsPulse{ 0%,100%{ box-shadow:0 0 0 0 rgba(255,59,78,.55); } 50%{ box-shadow:0 0 0 5px rgba(255,59,78,0); } }
    .dzns-rw{ position:relative; }
    .dzns-arr{ display:none; position:absolute; top:50%; transform:translateY(-50%); z-index:3; width:32px; height:32px; border-radius:8px; padding:0; align-items:center; justify-content:center; flex-shrink:0;
        border:1px solid rgba(var(--fg,255,255,255),.12); background:rgba(var(--fg,255,255,255),.14); -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);
        color:var(--ui-text,#fff); transition:.2s; }   /* same button as the hashtag arrows on the home page */
    .dzns-arr i{ display:block; font-size:22px; line-height:1; }
    .dzns-arr.l{ left:6px; } .dzns-arr.r{ right:6px; }
    .dzns-arr:hover{ background:rgba(255,45,45,.7); border-color:transparent; color:#fff; }
    @media (hover:hover) and (pointer:fine){ .dzns-arr:not([hidden]){ display:flex; } }   /* mouse users only; touch screens just swipe */
    .dzns-row::-webkit-scrollbar{ display:none; }
    .dzns-row{ display:flex; gap:8px; padding:2px var(--pad-x) 2px; scroll-padding-inline:var(--pad-x); overflow-x:auto; scroll-snap-type:x proximity; scrollbar-width:none; -webkit-overflow-scrolling:touch; overscroll-behavior-x:contain; }
    .dzns-card{ position:relative; flex:none; scroll-snap-align:start; width:232px; border-radius:8px; overflow:hidden; display:flex; flex-direction:column;
        background:rgba(var(--fg),.07); color:inherit; text-decoration:none; cursor:pointer; text-align:left; }
    .dzns-card:active{ transform:scale(.985); }
    .dzns-img{ height:116px; background:rgba(var(--fg),.08); position:relative; overflow:hidden; }
    .dzns-img img{ width:100%; height:100%; object-fit:cover; display:block; }
    .dzns-img.ph{ background:linear-gradient(135deg,#8b7cff,#ff5fa2); }
    .dzns-img.tx{ background:linear-gradient(135deg,#2b2350,#8b7cff); display:flex; align-items:center; padding:12px; font-size:14px; font-weight:700; color:#fff; line-height:1.25; }
    .dzns-tag{ position:absolute; left:8px; top:8px; font-size:10.5px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; padding:3px 7px; border-radius:4px; background:rgba(0,0,0,.62); color:#fff; }
    .dzns-tag.dz{ background:var(--purple); }
    .dzns-body{ padding:10px 12px 12px; display:flex; flex-direction:column; gap:8px; flex:1; }
    .dzns-t{ font-size:14.5px; line-height:1.28; font-weight:700; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
    .dzns-meta{ font-size:12px; color:rgba(var(--fg),.55); display:flex; align-items:center; gap:6px; margin-top:auto; min-width:0; }
    .dzns-img video{ width:100%; height:100%; object-fit:cover; display:block; }
    .dzns-av{ width:22px; height:22px; border-radius:50%; object-fit:cover; flex:none; border:2px solid red; box-sizing:border-box; }   /* red ring like post avatars */
    .dzns-meta span{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .dzns-talk{ margin-left:auto; font-size:12px; font-weight:800; color:var(--purple); flex:none; }
    /* sports score card */
    .dzns-card.sp{ width:208px; padding:12px; gap:10px; background:rgba(var(--fg),.07); }
    .dzns-lg{ display:flex; align-items:center; gap:6px; font-size:11.5px; font-weight:800; letter-spacing:.03em; color:rgba(var(--fg),.6); text-transform:uppercase; }
    .dzns-st{ margin-left:auto; text-transform:none; letter-spacing:0; font-weight:700; }
    .dzns-st.in{ color:#ff3b4e; display:inline-flex; align-items:center; gap:5px; }
    .dzns-tm{ display:flex; align-items:center; gap:9px; font-size:15px; font-weight:700; }
    .dzns-tm img{ width:26px; height:26px; object-fit:contain; flex:none; }
    .dzns-tm .lg0{ width:26px; height:26px; border-radius:50%; background:rgba(var(--fg),.12); flex:none; }
    .dzns-tm b{ margin-left:auto; font-size:18px; font-variant-numeric:tabular-nums; }
    .dzns-tm.lose{ opacity:.55; }
    .dzns-card.sp .dzns-talk{ margin:auto 0 0; align-self:flex-start; }
    </style>
    <script>
    (function(){
        var box=document.getElementById('dzNs'), row=document.getElementById('dzNsRow'); if(!box||!row) return;
        var data=null, posts=[], filter='all';
        function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
        function safe(u){ return /^https?:\/\//i.test(u||'')?u:''; }
        function ago(ts){ if(!ts) return ''; var m=Math.max(1,Math.round((Date.now()/1000-ts)/60)); if(m<60) return m+'m'; var h=Math.round(m/60); return h<24?h+'h':Math.round(h/24)+'d'; }
        function talk(q){ return 'search.php?focus=1&mq='+encodeURIComponent(q); }   /* the Dezzme twist: see what Dezzmers are saying */
        function newsCard(n){
            var img=safe(n.i);
            return '<a class="dzns-card" href="'+esc(safe(n.u))+'" target="_blank" rel="noopener">'+
                '<div class="dzns-img'+(img?'':' ph')+'">'+(img?'<img src="'+esc(img)+'" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentNode.classList.add(\'ph\');this.remove()">':'')+'<span class="dzns-tag">'+esc(LABEL[n.k]||'News')+'</span></div>'+
                '<div class="dzns-body"><div class="dzns-t">'+esc(n.t)+'</div><div class="dzns-meta"><span>'+esc(n.s)+'</span><span>·</span><span>'+ago(n.ts)+'</span>'+
                '<span class="dzns-talk" onclick="event.preventDefault();event.stopPropagation();location.href=\''+talk(n.t.split(/\s+/).slice(0,3).join(' '))+'\'">Talk about it</span></div></div></a>';
        }
        /* a Dezzme post about news / sports: opens in Zeems like every other post on this page */
        function postCard(q){
            var img=q.i||'', vid=q.v||'';
            var media=img?'<img src="'+esc(img)+'" alt="" loading="lazy" onerror="this.parentNode.classList.add(\'tx\');this.parentNode.textContent=this.getAttribute(\'data-c\')" data-c="'+esc(q.c)+'">'
                     :(vid?'<video src="'+esc(vid)+'#t=0.1" muted playsinline preload="metadata"></video>':'');
            var hasMedia=!!media;
            return '<div class="dzns-card" role="button" tabindex="0" onclick="dznsOpen('+(q.id|0)+')">'+
                '<div class="dzns-img'+(hasMedia?'':' tx')+'">'+(hasMedia?media:esc(q.c))+'<span class="dzns-tag dz">'+(q.k==='sports'?'Sports':(q.k==='ent'?'Entertainment':'News'))+' · Dezzme</span></div>'+
                '<div class="dzns-body">'+(hasMedia&&q.c?'<div class="dzns-t">'+esc(q.c)+'</div>':'')+'<div class="dzns-meta"><img class="dzns-av" src="'+esc(q.p)+'" alt="" onerror="this.src=\'mee.jpg\'"><span>'+esc(q.n)+'</span></div></div></div>';
        }
        window.dznsOpen=function(id){ if(typeof hpOpenPost==='function') hpOpenPost(id); };
        function teamRow(t,lose){
            return '<div class="dzns-tm'+(lose?' lose':'')+'">'+(safe(t.l)?'<img src="'+esc(t.l)+'" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">':'<span class="lg0"></span>')+'<span>'+esc(t.n)+'</span><b>'+esc(t.s)+'</b></div>';
        }
        function gameCard(g){
            var hs=parseFloat(g.h.s), as=parseFloat(g.a.s), done=g.st==='post';
            var when=g.st==='pre'?new Date(g.ts*1000).toLocaleString([], {weekday:'short',hour:'numeric',minute:'2-digit'}):g.tx;
            var tag=g.st==='in'?'<span class="dzns-st in"><i class="dzns-dot"></i>'+esc(g.tx||'Live')+'</span>':'<span class="dzns-st">'+esc(when)+'</span>';
            return '<a class="dzns-card sp" href="'+esc(safe(g.u)||'#')+'" target="_blank" rel="noopener">'+
                '<div class="dzns-lg">'+esc(g.lg)+tag+'</div>'+
                teamRow(g.a, done&&as<hs)+teamRow(g.h, done&&hs<as)+
                '<span class="dzns-talk" onclick="event.preventDefault();event.stopPropagation();location.href=\''+talk(g.h.n+' '+g.a.n)+'\'">Talk about it</span></a>';
        }
        var LABEL={news:'News',tech:'Tech',sport:'Sport',ent:'Entertainment'};
        function draw(keep){
            if(!data) return; var h='';
            var N=data.news||[], S=data.sports||[];
            var NN=N.filter(function(n){return n.k==='news'||n.k==='tech';}), NS=N.filter(function(n){return n.k==='sport';}), NE=N.filter(function(n){return n.k==='ent';});
            var PN=posts.filter(function(q){return q.k==='news';}), PS=posts.filter(function(q){return q.k==='sports';}), PE=posts.filter(function(q){return q.k==='ent';});
            function mix(items,pp,games){ items=items.slice(0,12); pp=pp.slice(0,8); games=games.slice(0,10); var o='',i=0,j=0,g=0; while(i<items.length||j<pp.length||g<games.length){ if(g<games.length) o+=gameCard(games[g++]); if(j<pp.length) o+=postCard(pp[j++]); if(g<games.length) o+=gameCard(games[g++]); if(i<items.length) o+=newsCard(items[i++]); if(i<items.length&&!games.length) o+=newsCard(items[i++]); } return o; }
            if(filter==='news') h=mix(NN,PN,[]);
            else if(filter==='sports') h=mix(NS,PS,S);
            else if(filter==='ent') h=mix(NE,PE,[]);
            else h=mix(N,posts,S);
            if(!h) h='<div style="padding:16px 4px;color:rgba(var(--fg),.55);font-size:14px">Nothing here yet.</div>';
            var c=0; h=h.replace(/ loading="lazy"/g,function(){ return (++c>6)?' loading="lazy"':''; });   /* first cards load at once */
            var sl=keep?row.scrollLeft:0; row.innerHTML=h; row.scrollLeft=sl; arrows(); setTimeout(arrows,300);
        }
        box.querySelectorAll('.dzns-tab').forEach(function(b){ b.addEventListener('click',function(){
            box.querySelectorAll('.dzns-tab').forEach(function(x){x.classList.toggle('on',x===b);}); filter=b.dataset.f; draw(); }); });
        function shuf(a){ a=a.slice(); for(var i=a.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)), t=a[i]; a[i]=a[j]; a[j]=t; } return a; }
        function mixUp(d){   /* different stories every visit: live games stay first, the rest is shuffled */
            var S=d.sports||[], live=S.filter(function(g){return g.st==='in';}), rest=shuf(S.filter(function(g){return g.st!=='in';}));
            return {news:shuf(d.news||[]), sports:live.concat(rest)};
        }
        var aL=document.getElementById('dzNsL'), aR=document.getElementById('dzNsR');
        function arrows(){ if(!aL||!aR) return; aL.hidden = row.scrollLeft < 8; aR.hidden = row.scrollLeft + row.clientWidth >= row.scrollWidth - 8; }
        if(aL&&aR){
            aL.addEventListener('click',function(){ row.scrollBy({left:-Math.round(row.clientWidth*.8),behavior:'smooth'}); });
            aR.addEventListener('click',function(){ row.scrollBy({left:Math.round(row.clientWidth*.8),behavior:'smooth'}); });
            row.addEventListener('scroll',arrows,{passive:true}); window.addEventListener('resize',arrows);
        }
        function show(d, keep){
            if(data && keep){ data={news:(data.news&&data.news.length?data.news:shuf(d.news||[])), sports:mixUp({sports:d.sports}).sports}; }   /* refresh: only the scores move, stories stay put */
            else data=mixUp(d);
            box.hidden=false; draw(keep);
        }
        function load(){
            fetch('search.php?dz_ns=1',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
                if(!d) return;
                var any=(d.news&&d.news.length)||(d.sports&&d.sports.length);
                if(any) show(d, !!data);
                else if(!data && !posts.length){ box.hidden=false; row.innerHTML='<div style="padding:14px 4px;color:rgba(var(--fg),.55);font-size:13.5px">News and scores could not load right now'+(d.curl===false?' (PHP curl is turned off on this server)':' (this server could not reach the news sources)')+'.</div>'; }
                if(d.sports&&d.sports.some(function(g){return g.st==='in';})) setTimeout(load,60000);   /* live scores refresh */
            }).catch(function(){});
        }
        /* first paint: everything already came with the page (cached on the server), so it shows instantly like the posts */
        var D=window.DZNS||null;
        if(D&&D.perr&&window.console) console.warn('Dezzme news strip - posts query failed:', D.perr);
        if(D){ posts=D.posts||[]; if((D.news&&D.news.length)||(D.sports&&D.sports.length)||posts.length) show({news:D.news||[],sports:D.sports||[]}, false); }
        if(!D||D.stale||!data){ setTimeout(load, data?800:0); }          /* cache empty or old: refresh quietly in the background */
        else if(D.sports&&D.sports.some(function(g){return g.st==='in';})) setTimeout(load,60000);
    })();
    </script>
    <?php endif; ?>

    <div class="posts-skel" id="postSkel">
        <?php for($i = 0; $i < 6; $i++): ?>
        <div class="sk-row">
            <div class="sk-box"></div>
            <div class="sk-lines">
                <div class="sk-line" style="width:45%;"></div>
                <div class="sk-line" style="width:92%;"></div>
                <div class="sk-line" style="width:70%;"></div>
                <div class="sk-line" style="width:38%;height:9px;"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>

    <script>
    /* safety net: show the posts even if a later script on the page fails or the page is cut short */
    (function(){
        function show(){
            var sk = document.getElementById('postSkel'), l = document.getElementById('postList');
            if(!l || l.style.display === 'block') return;
            if(sk) sk.style.display = 'none';
            l.style.display = 'block';
            try{ if(typeof watchVideos === 'function') watchVideos(); }catch(e){}
            try{ if(typeof spInitInfinite === 'function') spInitInfinite(); }catch(e){}
        }
        /* post videos are given their source AFTER the page has finished loading, so the browser's blue loading line
           completes at the same speed all the way instead of crawling while every video downloads */
        function loadVids(){
            document.querySelectorAll('video[data-src]').forEach(function(v){
                v.preload = 'metadata'; v.src = v.getAttribute('data-src'); v.removeAttribute('data-src');
            });
            try{ if(typeof spResumeVideos === 'function') spResumeVideos(); }catch(e){}
        }
        if(document.readyState === 'complete') setTimeout(loadVids, 50);
        else window.addEventListener('load', function(){ setTimeout(loadVids, 50); });
        window.addEventListener('DOMContentLoaded', function(){ setTimeout(show, 600); });
        setTimeout(show, 3000);
    })();
    </script>

    <div class="posts" id="postList" style="display:none;">
        <?php if(empty($_list)): ?>
        <div class="empty-state">
            <div class="es-title">Nothing to show here</div>
            <p class="es-text">No posts match this tag or filter yet. Try another one, or be the first to post something.</p>
            <div class="es-tips">
                <div class="es-tip"><i class="ri-hashtag"></i><span>Pick a different tag from the tag bar.</span></div>
                <div class="es-tip"><i class="ri-menu-3-line"></i><span>Change how the feed is sorted from the menu at the top.</span></div>
            </div>
            <?php if($tag !== 'all'): ?>
            <a class="es-btn" href="<?= htmlspecialchars(spUrl($filter, 'all')) ?>">Show all posts</a>
            <?php else: ?>
            <a class="es-btn" href="upload.php">Upload a post</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        $_real_post_count = 0;
        $_sugg_inserted   = false;
        $_coll_inserted   = false;
        $_mp_seq          = 0;
        ?>
        <?php foreach($_list as $p): ?>

            <?php if($p['isAd']): ?>
            <div class="sp-item sp-adcard meme-tile" data-id="<?= (int)$p['id'] ?>" data-uploader="<?= (int)$p['uid'] ?>"></div>
            <?php else: ?>
            <div class="post-row sp-item meme-tile" data-id="<?= (int)$p['id'] ?>" data-uploader="<?= (int)$p['uid'] ?>" onclick="hpOpenPost(<?= (int)$p['id'] ?>)">

                <?php if($p['thumb']): ?>
                <div class="post-media-col">
                    <?php $tf = 'uploads/'.htmlspecialchars($p['thumb']['file']); ?>
                    <div class="post-thumb">
                        <?php if(strtolower($p['thumb']['type']) === 'video'): ?>
                            <video data-src="<?= $tf ?>" muted loop playsinline preload="none" class="sp-video"></video>
                        <?php else: ?>
                            <img src="<?= $tf ?>" loading="lazy" alt="" onerror="this.src='uploads/Text.jpg'">
                        <?php endif; ?>
                        <?php if($p['count'] > 1): ?>
                        <span class="thumb-badge"><i class="ri-stack-line"></i><?= (int)$p['count'] ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="post-info">
                    <div class="post-head">
                        <?php $p_co = !empty($p['collab']['list']) ? $p['collab']['list'] : null; ?>
                        <?php if($p_co): $p_cj = htmlspecialchars(json_encode($p['collab']), ENT_QUOTES); ?>
                        <span class="post-co-stack" onclick="event.stopPropagation();dzCollabSheet(<?= (int)$p['id'] ?>, <?= $p_cj ?>)">
                            <img class="post-avatar" src="<?= htmlspecialchars($p['pic']) ?>" onerror="this.src='mee.jpg'" alt="">
                            <?php foreach(array_slice($p_co, 0, 2) as $p_c): ?><img class="post-avatar" src="<?= htmlspecialchars($p_c['pic']) ?>" onerror="this.src='mee.jpg'" alt=""><?php endforeach; ?>
                        </span>
                        <a href="#" onclick="event.preventDefault();event.stopPropagation();dzCollabSheet(<?= (int)$p['id'] ?>, <?= $p_cj ?>)" class="post-username post-co-name"><?= htmlspecialchars($p['uid'] == (int)$current_user ? 'You' : $p['username']) ?> and <?= count($p_co) === 1 ? htmlspecialchars($p_co[0]['username']) : count($p_co) . ' others' ?></a>
                        <?php else: ?>
                        <a href="profile.php?user_id=<?= (int)$p['uid'] ?>" onclick="event.stopPropagation()" style="display:flex;flex-shrink:0;">
                            <span class="av-wrap" data-uid="<?= (int)$p['uid'] ?>"><img class="post-avatar" src="<?= htmlspecialchars($p['pic']) ?>" onerror="this.src='mee.jpg'" alt=""><?php if(!empty($p['online'])): ?><span class="on-dot"></span><?php endif; ?></span>
                        </a>
                        <a href="profile.php?user_id=<?= (int)$p['uid'] ?>" onclick="event.stopPropagation()" class="post-username"><?= htmlspecialchars($p['uid'] == (int)$current_user ? 'You' : $p['username']) ?></a>
                        <?php if(!empty($p['isVerified'])): ?><svg class="dz-vb dz-vb-list" viewBox="0 0 256 256" aria-label="Verified" fill="currentColor"><use href="#dz-vb-sym"/></svg><?php endif; ?>
                        <?php endif; ?>
                        <?php if(!$p_co && $p['nickname'] !== ''): ?>
                        <span class="post-dot">·</span>
                        <span class="post-nick">@<?= htmlspecialchars($p['nickname']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if($p['caption'] !== ''): ?>
                    <div class="post-caption"><?= dz_clean_caption_html($p['caption']) ?></div>
                    <?php endif; ?>

                    <div class="post-stats">
                        <span class="stat like-btn<?= $p['liked'] ? ' active' : '' ?>" data-id="<?= (int)$p['id'] ?>" onclick="spAction(event,<?= (int)$p['id'] ?>,'like',this)"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i><span class="count" data-hidden="<?= $p['hideLikes'] ? '1' : '0' ?>"><?= $p['hideLikes'] ? '' : spFmt($p['likes']) ?></span></span>
                        <span class="stat repost-btn<?= $p['reposted'] ? ' active' : '' ?>" data-id="<?= (int)$p['id'] ?>" onclick="spAction(event,<?= (int)$p['id'] ?>,'repost',this)"><i class="sp-rp"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg></i><span class="count" data-hidden="<?= $p['hideReposts'] ? '1' : '0' ?>"><?= $p['hideReposts'] ? '' : spFmt($p['reposts']) ?></span></span>
                        <span class="stat comment-btn" data-id="<?= (int)$p['id'] ?>" onclick="spOpenComments(event,<?= (int)$p['id'] ?>)"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg><svg class="cm-off-ic" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/><path d="M3.5 3.5l17 17"/></svg></i><span class="count comment-count" data-id="<?= (int)$p['id'] ?>" data-hidden="0">0</span></span>
                        <span class="stat fav-btn<?= $p['faved'] ? ' active' : '' ?>" data-id="<?= (int)$p['id'] ?>" onclick="spAction(event,<?= (int)$p['id'] ?>,'fav',this)"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21l-7-5-7 5V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1z"/></svg></i><span class="count" data-hidden="<?= $p['hideSaves'] ? '1' : '0' ?>"><?= $p['hideSaves'] ? '' : spFmt($p['saves']) ?></span></span>
                        <span class="stat share-btn" data-id="<?= (int)$p['id'] ?>" onclick="event.stopPropagation();openShareModal(<?= (int)$p['id'] ?>)"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i><span class="count share-count" data-hidden="<?= $p['hideShares'] ? '1' : '0' ?>"><?= $p['hideShares'] ? '' : spFmt($p['shares']) ?></span></span>
                    </div>
                </div>

                <button type="button" class="post-kebab index-kebab-btn" data-id="<?= (int)$p['id'] ?>" aria-label="More options" onclick="event.stopPropagation();openIndexSheet(<?= (int)$p['id'] ?>)"><i class="ri-menu-line"></i></button>
            </div>
            <?php endif; ?>

            <?php $_real_post_count++; ?>

            <?php if(!$_sugg_inserted && $_real_post_count === 3): ?>
                <div class="sp-item sp-sugg-block" id="suggDezzmersBlock"></div>
                <?php $_sugg_inserted = true; ?>
            <?php endif; ?>

            <?php if(!$_coll_inserted && $_real_post_count === 14): ?>
                <div class="sp-item hp-coll sp-coll-slot" id="spCollBlock"></div>
                <?php $_coll_inserted = true; ?>
            <?php endif; ?>

            <?php if($_real_post_count > 0 && $_real_post_count % 12 === 0): ?>
                <?php $_mp_seq++; ?>
                <div class="sp-item sp-mp-block" id="spMpBlock<?= $_mp_seq ?>" data-mp-id="mp<?= $_mp_seq ?>"></div>
            <?php endif; ?>

        <?php endforeach; ?>
    </div>

</div><!-- /.page -->

<!-- RIGHT-HAND COLUMN — laptop / normal desktop view only (≥1100px). -->
<aside class="hp-right-rail" aria-label="Explore">

    <section class="pf-card" id="rsAdsSection" data-k="ads">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-megaphone-line pf-bar-ic"></i><span class="pf-bar-t">Sponsored</span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
            <div class="rs-ads-list" id="rsAdsList">
                <div class="rs-ad-skel"><div class="rs-ad-skel-thumb"></div><div class="rs-ad-skel-lines"><div class="rs-ad-skel-line w1"></div><div class="rs-ad-skel-line w2"></div><div class="rs-ad-skel-line w3"></div></div></div>
                <div class="rs-ad-skel"><div class="rs-ad-skel-thumb"></div><div class="rs-ad-skel-lines"><div class="rs-ad-skel-line w1"></div><div class="rs-ad-skel-line w2"></div><div class="rs-ad-skel-line w3"></div></div></div>
                <div class="rs-ad-skel"><div class="rs-ad-skel-thumb"></div><div class="rs-ad-skel-lines"><div class="rs-ad-skel-line w1"></div><div class="rs-ad-skel-line w2"></div><div class="rs-ad-skel-line w3"></div></div></div>
            </div>
        </div></div></div>
    </section>

    <section class="pf-card" data-k="find">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-search-eye-line pf-bar-ic"></i><span class="pf-bar-t">What you can search</span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
            <div class="pf-find">
                <div class="pf-find-item"><i class="ri-user-3-line"></i><div><b>Dezzmers</b><small>Name, nickname, DZ-ID</small></div></div>
                <div class="pf-find-item"><i class="ri-image-2-line"></i><div><b>Posts</b><small>Captions &amp; #hashtags</small></div></div>
                <div class="pf-find-item"><i class="ri-team-line"></i><div><b>Pods</b><small>Community groups</small></div></div>
                <div class="pf-find-item"><i class="ri-music-2-line"></i><div><b>Sounds</b><small>Tracks &amp; sound folders</small></div></div>
                <div class="pf-find-item"><i class="ri-folder-3-line"></i><div><b>Collections</b><small>Public collections</small></div></div>
                <div class="pf-find-item"><i class="ri-book-open-line"></i><div><b>Memepedia</b><small>Meme entries</small></div></div>
            </div>
            <button type="button" class="pf-cta" onclick="openSearchModal()"><svg viewBox="0 0 24 24" aria-hidden="true" style="width:1.15em;height:1.15em;vertical-align:-.2em;margin-right:4px"><circle cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="2.5"/><path fill="currentColor" d="M15.37 14.23L21.41 18.59A2 2 0 0 1 18.59 21.41L14.23 15.37Z"/></svg> Start searching</button>
        </div></div></div>
    </section>

    <?php if(!empty($ex_tags)): ?>
    <section class="pf-card" data-k="tags">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-hashtag pf-bar-ic"></i><span class="pf-bar-t">Hashtags</span><span class="pf-bar-c"><?= count($ex_tags) ?></span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
            <div class="pf-list" data-init="8" data-step="<?= count($ex_tags) ?>" data-shown="8">
                <?php foreach($ex_tags as $i => $t):
                    $isOn  = ($tag === $t);
                    $isAff = in_array(strtolower($t), $lower_top);
                ?>
                <a class="pf-row<?= $isOn ? ' pf-tag-on' : '' ?><?= $i >= 8 ? ' pf-hold' : '' ?>" href="<?= htmlspecialchars(spUrl($filter, $t)) ?>">
                    <span class="pf-tag-h"><i class="ri-hashtag"></i></span>
                    <span class="pf-row-info"><span class="pf-row-t"><?= htmlspecialchars($t) ?></span></span>
                    <?php if($isAff): ?><span class="pf-tag-f">For you</span><?php endif; ?>
                    <i class="ri-arrow-right-s-line pf-tag-chev"></i>
                </a>
                <?php endforeach; ?>
            </div>
            <?php if(count($ex_tags) > 8): ?>
            <button type="button" class="pf-more" data-mode="more" onclick="pfMore(this)">See more</button>
            <?php endif; ?>
        </div></div></div>
    </section>
    <?php endif; ?>

    <section class="pf-card" id="rsPeopleCard" data-k="people" style="display:none;">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-user-add-line pf-bar-ic"></i><span class="pf-bar-t">Dezzmers to track</span><span class="pf-bar-c" id="rsPeopleCount"></span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad" id="rsPeopleBody"></div></div></div>
    </section>

    <section class="pf-card" id="rsMpCard" data-k="memepedia" style="display:none;">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-book-open-line pf-bar-ic"></i><span class="pf-bar-t">Memepedia featured</span><span class="pf-bar-c" id="rsMpCount"></span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad" id="rsMpBody"></div></div></div>
    </section>

    <div class="rs-divider"></div>
    <div class="rs-links">
        <a href="messages.php" class="rs-link-row"><i class="ri-wechat-line"></i><span>Messages</span></a>
        <a href="profile_longform.php" class="rs-link-row"><i class="ri-vidicon-line"></i><span>Your videos</span></a>
    </div>
</aside>
</div><!-- /.page-shell -->

<!-- ══ DATA + HOST SETUP FOR zeems_modal.php (must come BEFORE the include) ══ -->
<script>
var peuraFeed = <?= json_encode($_peura_feed_data, $_json_flags) ?>;
var currentUserId = <?= (int)$current_user ?>;
window.kebabData = <?= json_encode($_kebab_data, $_json_flags | JSON_FORCE_OBJECT) ?>;
/* paging: page 1 is printed above; further pages are fetched from ?feed_json=1&page=N&seed=S when the reader reaches the end */
window.spPaging = { page: <?= (int)($page ?? 1) ?>, hasMore: <?= !empty($has_more) ? 'true' : 'false' ?>, seed: <?= (int)($feed_seed ?? 0) ?>, asof: <?= (int)($feed_asof ?? 0) ?>, fetching:false, fails:0 };
window.suggUsersData = [];
window.mpArticles = [];
/* fetched after first paint — nothing here blocks the page */
window._suggPromise = fetch('?type=sugg').then(function(r){ return r.json(); })
    .then(function(u){ u = Array.isArray(u) ? u : []; window.suggUsersData = u; window.cmSuggUsers = u; return u; })
    .catch(function(){ window.cmSuggUsers = []; return []; });
window._mpPromise = fetch('?type=memepedia').then(function(r){ return r.json(); })
    .then(function(a){ a = Array.isArray(a) ? a : []; window.mpArticles = a; return a; })
    .catch(function(){ return []; });

var _appTopZIndex = 2147483000;
function bringModalToFront(el){ if(el) el.style.zIndex = ++_appTopZIndex; }

window.CCM_ENDPOINT = 'search.php';

if(typeof showToast !== 'function'){
    window.showToast = function(msg){
        var old = document.getElementById('sp-toast'); if(old) old.remove();
        var t = document.createElement('div'); t.id = 'sp-toast'; t.textContent = msg;
        t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:rgba(20,20,25,.95);border:1px solid rgba(255,45,45,.3);color:#fff;padding:8px 20px;border-radius:50px;font-size:12px;z-index:2147483647;pointer-events:none;white-space:nowrap;';
        document.body.appendChild(t);
        setTimeout(function(){ t.remove(); }, 2200);
    };
}

window.openSearchModal = window.openSearchModal || function(){ location.href = 'search.php?focus=1'; };
</script>

<?php include 'zeems_modal.php'; ?>
<?php include 'Search_Modal.php'; /* the search modal lives in its own file */ ?>



<!-- ══ PAGE SCRIPT ══ -->
<script>
function hftToggleSort(e){
    e.stopPropagation();
    document.getElementById('hftSortDropdown').classList.toggle('show');
}
document.addEventListener('click', function(){
    var dd = document.getElementById('hftSortDropdown');
    if(dd) dd.classList.remove('show');
});

function dzBellClick(e, a){
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
    setTimeout(go, 700);
}

/* Ad posts can't use sound pages — same message as the home page */
function dzAdSound(e){ if(e&&e.stopPropagation) e.stopPropagation(); showToast('Sorry, this feature doesn’t work for Ad posts'); return false; }

/* ── skeleton → list ── */
(function(){
    var sk = document.getElementById('postSkel');
    var list = document.getElementById('postList');
    function reveal(){
        if(!sk || !list || list.style.display === 'block') return;
        sk.style.transition = 'opacity .35s ease';
        sk.style.opacity = '0';
        setTimeout(function(){
            sk.style.display = 'none';
            list.style.display = 'block';
            watchVideos();
            spInitInfinite();
        }, 350);
    }
    /* don't wait for every image/video to finish loading (window 'load') — reveal as soon as the HTML is ready, like home.php */
    setTimeout(reveal, 250);
})();

/* ── infinite scroll: 4 sp-items at a time ── */
var SP_BATCH = 4, _spInfInit = false, _spSentinelObs = null, _spLoadingBatch = false;
function spInitInfinite(){
    if(_spInfInit) return;
    _spInfInit = true;
    var list = document.getElementById('postList');
    if(!list) return;
    var rows = list.querySelectorAll('.sp-item');
    rows.forEach(function(r, i){ if(i >= SP_BATCH) r.classList.add('sp-hidden'); });
    if(window.spHydrateAds) spHydrateAds();
    if(window.spObserveImps) spObserveImps();
    spEnsureSentinel();
}
function spLoadVids(){
    document.querySelectorAll('#postList .sp-item:not(.sp-hidden) video[data-src]').forEach(function(v){
        v.preload = 'metadata'; v.src = v.getAttribute('data-src'); v.removeAttribute('data-src');
    });
}
function spRemainingRows(){
    return document.querySelectorAll('#postList .sp-item.sp-hidden');
}
function spEnsureSentinel(){
    var list = document.getElementById('postList');
    var hidden = spRemainingRows();
    var s = document.getElementById('spSentinel');
    var _more = !!(window.spPaging && window.spPaging.hasMore);
    if(!hidden.length && !_more){
        if(s) s.remove();
        if(_spSentinelObs){ _spSentinelObs.disconnect(); _spSentinelObs = null; }
        return;
    }
    if(!s){
        s = document.createElement('div');
        s.id = 'spSentinel';
        s.className = 'sp-load-more';
        s.innerHTML = '<div class="sp-load-dot"></div><div class="sp-load-dot"></div><div class="sp-load-dot"></div>';
        list.appendChild(s);
    }
    if(!('IntersectionObserver' in window)){
        if(hidden.length) spRevealBatch(hidden.length);
        else if(window.spFetchMore) window.spFetchMore();
        return;
    }
    if(_spSentinelObs) _spSentinelObs.disconnect();
    _spSentinelObs = new IntersectionObserver(function(entries){
        entries.forEach(function(e){
            if(!e.isIntersecting || _spLoadingBatch) return;
            if(spRemainingRows().length){
                _spLoadingBatch = true;
                setTimeout(function(){ spRevealBatch(SP_BATCH); _spLoadingBatch = false; }, 110);
            } else if(window.spFetchMore){
                window.spFetchMore();      /* end of this page → fetch the next one */
            }
        });
    }, { rootMargin:'600px 0px 600px 0px' });
    _spSentinelObs.observe(s);
}
function spRevealBatch(n){
    var hidden = spRemainingRows();
    for(var i = 0; i < n && i < hidden.length; i++){
        hidden[i].classList.remove('sp-hidden');
    }
    spLoadVids();
    watchVideos();
    if(window.spObserveImps) spObserveImps();
    if(window.spHydrateAds) spHydrateAds();
    spEnsureSentinel();
}

var _spVideoObs = null;
function watchVideos(){
    if(!('IntersectionObserver' in window)) return;
    if(!_spVideoObs){
        _spVideoObs = new IntersectionObserver(function(entries){
            entries.forEach(function(e){
                if(e.isIntersecting) e.target.play().catch(function(){});
                else e.target.pause();
            });
        }, { threshold:.4 });
    }
    document.querySelectorAll('.sp-video').forEach(function(v){ _spVideoObs.observe(v); });
}
function spPauseVideos(){ document.querySelectorAll('.sp-video').forEach(function(v){ v.pause(); }); }
function spResumeVideos(){
    document.querySelectorAll('.sp-video').forEach(function(v){
        var r = v.getBoundingClientRect();
        if(r.bottom > 0 && r.top < window.innerHeight) v.play().catch(function(){});
    });
}

function hpOpenPost(memeId){
    if(typeof _buildCcmFeed !== 'function') return;
    /* zeems shows this post first, then its own fresh run … */
    if(window.ccmOwnPrepare){ window.ccmOwnPrepare(memeId, function(){ hpOpenPostNow(memeId); }); return; }
    hpOpenPostNow(memeId);
}
function hpOpenPostNow(memeId){
    if(window.spDwPause) spDwPause();
    spPauseVideos();
    if(!ccmFeed.length) _buildCcmFeed();
    var idx = ccmFeed.findIndex(function(s){
        return s.type === 'post' && String(s.data.id) === String(memeId);
    });
    _ccmVSwipeLocked = false;
    _ccmMountTrack(idx >= 0 ? idx : 0);
    _ccmInitVerticalDrag();
    var m = document.getElementById('cardCaptionModal');
    m.classList.add('ccm-open');
    bringModalToFront(m);
    ccmLockBodyScroll();
}

window.closeDiscoverModal = function(){ closeCardCaptionModal(); };

document.addEventListener('zeems:closed', function(){
    if(window.spDwResume) spDwResume();
    setTimeout(function(){
        spResumeVideos();
        if(window.spSyncAdCards) spSyncAdCards();
        if(window.spAdResume) spAdResume();
    }, 60);
});

/* ══ ACTION BUTTONS ON THE LIST ROWS — like / save / repost work in place.
   A count the post hides (data-hidden="1") is never filled in for other viewers. ══ */
function spFmtNum(n){
    n = parseInt(n) || 0;
    if(n >= 1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'') + 'M';
    if(n >= 1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'') + 'K';
    return String(n);
}
var _spLocks = {};
var _SP_MAP = {
    like:   { a:'toggle_like',     c:'likeCount',   s:'liked'    },
    repost: { a:'toggle_repost',   c:'repostCount', s:'reposted' },
    fav:    { a:'toggle_favorite', c:'favCount',    s:'faved'    }
};
/* ── comments: the comment button opens the shared comment sheet; counts are fetched for every card on the page ── */
function spOpenComments(e, id){
    if(e){ e.stopPropagation(); e.preventDefault(); }
    if(window.dzHaptic) dzHaptic.light();
    if(window.CommentSection && typeof window.CommentSection.open === 'function') window.CommentSection.open(id);
}
(function(){
    var cache = {}, pending = {}, timer = 0;
    var off = {};
    function paint(id){
        var n = cache[id]; if(n === undefined) return;
        document.querySelectorAll('.comment-btn[data-id="'+id+'"]').forEach(function(b){ b.classList.toggle('cm-off', !!off[id]); });
        var txt = n >= 1e6 ? (Math.round(n/1e5)/10).toString().replace(/\.0$/,'') + 'M' : n >= 1e3 ? (Math.round(n/100)/10).toString().replace(/\.0$/,'') + 'K' : String(n);
        document.querySelectorAll('.comment-count[data-id="'+id+'"]').forEach(function(el){ el.textContent = off[id] ? '' : txt; el.dataset.ld = '1'; if(off[id]) el.setAttribute('data-off','1'); else el.removeAttribute('data-off'); });
    }
    function scan(){
        if(!(window.CommentSection && window.CommentSection.counts)) return;
        var need = [];
        document.querySelectorAll('.comment-count').forEach(function(el){
            var id = el.dataset.id;
            if(cache[id] !== undefined){ if(el.dataset.ld !== '1') paint(id); }
            else if(!pending[id] && need.indexOf(id) === -1){ need.push(id); pending[id] = 1; }
        });
        for(var i = 0; i < need.length; i += 50){
            (function(batch){
                (window.CommentSection.status ? window.CommentSection.status(batch) : window.CommentSection.counts(batch).then(function(c){ return { counts:c, off:{} }; })).then(function(r){
                    var c = r.counts || {};
                    batch.forEach(function(id){ cache[id] = +c[id] || 0; off[id] = !!(r.off || {})[id]; delete pending[id]; paint(id); });
                }).catch(function(){ batch.forEach(function(id){ delete pending[id]; }); });
            })(need.slice(i, i + 50));
        }
    }
    function later(){ clearTimeout(timer); timer = setTimeout(scan, 250); }
    document.addEventListener('DOMContentLoaded', later);
    window.addEventListener('load', later);
    if(window.MutationObserver) new MutationObserver(later).observe(document.documentElement, {childList:true, subtree:true});
    window.addEventListener('cs:changed', function(e){
        var d = e.detail || {}; if(!d.meme) return;
        cache[d.meme] = Math.max(0, (cache[d.meme] || 0) + d.delta); paint(d.meme);
    });
})();

function spAction(e, memeId, type, btn){
    if(e){ e.stopPropagation(); e.preventDefault(); }
    var key = memeId + ':' + type;
    if(_spLocks[key]) return;
    _spLocks[key] = true;

    var m       = _SP_MAP[type];
    var item    = peuraFeed.find(function(x){ return String(x.id) === String(memeId); });
    var cntEl   = btn.querySelector('.count');
    var hid     = !!(cntEl && cntEl.dataset.hidden === '1');
    var was     = btn.classList.contains('active');
    var next    = !was;
    if(window.dzHaptic){ if(type === 'fav') dzHaptic.light(); else if(next) dzHaptic.medium(); else dzHaptic.light(); }
    var prev    = item ? (item[m.c] || 0) : 0;

    btn.classList.toggle('active', next);
    if(cntEl && !hid) cntEl.textContent = spFmtNum(next ? prev + 1 : Math.max(0, prev - 1));

    if(type === 'repost'){
        var ic = btn.querySelector('i');
        btn.classList.remove('arc-swap-active'); void ic.offsetWidth; btn.classList.add('arc-swap-active');
        ic.addEventListener('animationend', function h(){ btn.classList.remove('arc-swap-active'); ic.removeEventListener('animationend', h); });
    }
    if(type === 'fav'){
        if(next && typeof ccmShowSavedToast === 'function') ccmShowSavedToast(memeId);
        else if(!next && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
    }

    fetch('search.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=' + m.a + '&meme_id=' + memeId
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        delete _spLocks[key];
        if(d.count === undefined){
            btn.classList.toggle('active', was);
            if(cntEl && !hid) cntEl.textContent = spFmtNum(prev);
            if(type === 'fav' && next && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
            return;
        }
        if(cntEl && !hid && d.count !== null) cntEl.textContent = spFmtNum(d.count);
        if(item){ if(d.count !== null) item[m.c] = parseInt(d.count); item[m.s] = next; }
        if(typeof ccmFeed !== 'undefined'){
            ccmFeed.forEach(function(s){
                if(s.type === 'post' && String(s.data.id) === String(memeId)){
                    if(d.count !== null) s.data[m.c] = parseInt(d.count);
                    s.data[m.s] = next;
                }
            });
        }
    })
    .catch(function(){
        delete _spLocks[key];
        btn.classList.toggle('active', was);
        if(cntEl && !hid) cntEl.textContent = spFmtNum(prev);
        if(type === 'fav' && next && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
    });
}

/* ══════════════════════════════════════════════════════════════
   TRACK DEZZMERS YOU'LL LIKE — list-view suggested users section.
══════════════════════════════════════════════════════════════ */
(function(){
    var ALL = [];
    window._spSuggOrder = ALL;
    var visibleCount = 3;

    function esc(s){
        if(s === null || s === undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function rowHtml(u){
        var following = parseInt(u.is_following) > 0;
        var priv      = parseInt(u.is_private_any) > 0;
        var pic = u.profile_pic || 'mee.jpg';
        var reason = u.reason ? esc(u.reason) : 'Suggested for you';
        var btn = (!priv || following)
            ? '<button type="button" class="sp-sugg-track-btn'+(following ? ' following' : '')+'" data-user="'+u.id+'" onclick="spSuggToggleTrack(this)">'+(following ? 'Untrack' : 'Track')+'</button>'
            : '';
        return '<div class="sp-sugg-row">'+
            '<a href="profile.php?user_id='+u.id+'"><span class="av-wrap" data-uid="'+u.id+'"><img class="sp-sugg-avatar" src="'+esc(pic)+'" onerror="this.src=\'mee.jpg\'">'+(parseInt(u.is_online)>0?'<span class="on-dot"></span>':'')+'</span></a>'+
            '<div class="sp-sugg-info">'+
                '<a href="profile.php?user_id='+u.id+'" class="sp-sugg-name">'+esc(u.username)+'</a>'+
                '<div class="sp-sugg-reason">'+reason+'</div>'+
            '</div>'+
            btn+
        '</div>';
    }

    function render(){
        var block = document.getElementById('suggDezzmersBlock');
        if(!block) return;
        if(!ALL.length){ block.remove(); return; }

        var visible = ALL.slice(0, visibleCount);
        var html = '<div class="sp-sugg-title">Track Dezzmers You\'ll Like</div>'+
            '<div class="sp-sugg-sub">People you might want to follow</div>'+
            visible.map(rowHtml).join('');

        var hasMore    = visibleCount < ALL.length;
        var canCollapse = visibleCount > 3;

        if(hasMore || canCollapse){
            html += '<div class="sp-sugg-more-row">';
            if(hasMore){
                html += '<button type="button" class="sp-sugg-more-btn" onclick="spSuggShowMore()"><i class="ri-arrow-down-s-line"></i> See more</button>';
            }
            if(canCollapse){
                html += '<button type="button" class="sp-sugg-less-btn" onclick="spSuggShowLess()"><i class="ri-arrow-up-s-line"></i> See less</button>';
            }
            html += '</div>';
        }

        block.innerHTML = html;
    }

    window.spSuggShowMore = function(){
        visibleCount = Math.min(ALL.length, visibleCount + 3);
        render();
    };
    window.spSuggShowLess = function(){
        visibleCount = 3;
        render();
    };
    window.spSuggToggleTrack = function(btn){
        var uid = btn.dataset.user;
        if(window.dzHaptic){ if(btn.classList.contains('following')) dzHaptic.light(); else dzHaptic.medium(); }
        btn.disabled = true;
        fetch('search.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=toggle_follow&user_id=' + encodeURIComponent(uid)
        })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false;
            if(d.status === 'ok'){
                var following = !!d.following;
                ALL.forEach(function(u){ if(String(u.id) === String(uid)) u.is_following = following ? 1 : 0; });
                document.querySelectorAll('.sp-sugg-track-btn[data-user="'+uid+'"]').forEach(function(b){
                    b.textContent = following ? 'Untrack' : 'Track';
                    b.classList.toggle('following', following);
                });
                document.querySelectorAll('.track-btn[data-user="'+uid+'"]').forEach(function(b){
                    b.textContent = following ? 'Untrack' : 'Track';
                    b.classList.toggle('following', following);
                });
            }
        })
        .catch(function(){ btn.disabled = false; });
    };

    function init(){
        (window._suggPromise || Promise.resolve([])).then(function(u){
            var a = (u || []).slice();
            for(var i = a.length - 1; i > 0; i--){
                var j = Math.floor(Math.random() * (i + 1));
                var t = a[i]; a[i] = a[j]; a[j] = t;
            }
            ALL.push.apply(ALL, a);   /* same array the right-hand column reads */
            render();
        });
    }
    if(document.readyState !== 'loading') init();
    else document.addEventListener('DOMContentLoaded', init);
})();

/* ══════════════════════════════════════════════════════════════
   MEMEPEDIA FEATURED — carousel logic ported 1:1 from home.php
══════════════════════════════════════════════════════════════ */
var mpStatusMeta = {
    all:       { label:'All',       icon:'ri-apps-2-line'    },
    trending:  { label:'Trending',  icon:'ri-fire-fill'      },
    rising:    { label:'Rising',    icon:'ri-arrow-up-line'  },
    classic:   { label:'Classic',   icon:'ri-building-line'  },
    legendary: { label:'Legendary', icon:'ri-vip-crown-line' },
    dead:      { label:'Dead',      icon:'ri-skull-line'     },
    revived:   { label:'Revived',   icon:'ri-refresh-line'   }
};
(function(){
    var MP_COUNT = 8;
    var mpShownIds = {}, mpLastOrder = {};
    window._mpSkeletonShown = window._mpSkeletonShown || false;

    function esc(s){
        if(s === null || s === undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtNum(n){
        n = parseInt(n) || 0;
        if(n >= 1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'') + 'M';
        if(n >= 1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'') + 'K';
        return n;
    }

    function mpShuffle(a){
        a = a.slice();
        for(var i = a.length - 1; i > 0; i--){
            var j = Math.floor(Math.random() * (i + 1)), t = a[i];
            a[i] = a[j]; a[j] = t;
        }
        return a;
    }
    function mpPick(rowId, status){
        var pool = (window.mpArticles || []).filter(function(a){ return status === 'all' || a.status === status; });
        if(!pool.length) return [];
        var want = Math.min(MP_COUNT, pool.length);
        if(!mpShownIds[rowId]) mpShownIds[rowId] = {};
        var shown = mpShownIds[rowId];
        var fresh = pool.filter(function(a){ return !shown[a.id+':'+status]; });
        if(fresh.length < want){ shown = {}; mpShownIds[rowId] = shown; fresh = pool; }
        var pick = [], order = '', tries = 0;
        do{
            pick = mpShuffle(fresh).slice(0, want);
            if(pick.length < want){
                var ids = {}; pick.forEach(function(a){ ids[a.id] = 1; });
                mpShuffle(pool).forEach(function(a){ if(pick.length < want && !ids[a.id]){ pick.push(a); ids[a.id] = 1; } });
            }
            order = pick.map(function(a){ return a.id; }).join(',');
            tries++;
        } while(pool.length > 1 && order === mpLastOrder[rowId+':'+status] && tries < 8);
        mpLastOrder[rowId+':'+status] = order;
        pick.forEach(function(a){ shown[a.id+':'+status] = true; });
        return pick;
    }
    function mpCardHtml(a){
        var meta = mpStatusMeta[a.status] || mpStatusMeta.trending;
        var thumb;
        if(a.mediaUrl){
            var mt = (a.mediaType || '').toLowerCase();
            if(mt === 'video'){
                thumb = '<video class="sp-mp-thumb" src="'+esc(a.mediaUrl)+'" autoplay muted loop playsinline disablepictureinpicture preload="auto" onerror="this.outerHTML=\'<div class=&quot;sp-mp-thumb-empty&quot;>\uD83D\uDCD6</div>\'"></video>';
            } else {
                thumb = '<img class="sp-mp-thumb" src="'+esc(a.mediaUrl)+'" alt="'+esc(a.title)+'" loading="lazy" onerror="this.outerHTML=\'<div class=&quot;sp-mp-thumb-empty&quot;>\uD83D\uDCD6</div>\'">';
            }
        } else {
            thumb = '<div class="sp-mp-thumb-empty">\uD83D\uDCD6</div>';
        }
        return '<div class="sp-mp-card" onclick="mpOpen('+a.id+')">'+
            thumb+
            '<div class="sp-mp-badge"><i class="'+meta.icon+'"></i>'+meta.label+'</div>'+
            '<div class="sp-mp-info">'+
                '<div class="sp-mp-card-title">'+esc(a.title)+'</div>'+
                '<div class="sp-mp-card-meta"><span><i class="ri-eye-line"></i> '+fmtNum(a.views||0)+'</span></div>'+
            '</div>'+
        '</div>';
    }
    function mpSkeletonCards(n){
        var one = '<div class="sp-mp-skel-card"><div class="sp-mp-skel-thumb"></div><div class="sp-mp-skel-line" style="width:80%;"></div><div class="sp-mp-skel-line" style="width:45%;margin-bottom:10px;"></div></div>';
        return new Array(n + 1).join(one);
    }
    function mpBlockHtml(rowId){
        var status = 'all';
        var picked = mpPick(rowId, status);
        var pillsHtml = Object.keys(mpStatusMeta).map(function(k){
            return '<button type="button" class="sp-mp-pill'+(k === status ? ' active' : '')+'" data-status="'+k+'" onclick="mpSwitchStatus(this,\''+rowId+'\',\''+k+'\')">'+mpStatusMeta[k].label+'</button>';
        }).join('');
        var cardsHtml = picked.length ? picked.map(mpCardHtml).join('') : '<div style="padding:20px 4px;color:rgba(var(--fg),.4);font-size:.72rem;">Nothing here yet.</div>';
        return '<div class="sp-mp-head"><div><div class="sp-mp-kicker">Memepedia Featured</div>'+
                '<div class="sp-mp-title"><i class="ri-book-open-line"></i>Explore the culture</div></div>'+
                '<button type="button" class="sp-mp-x" aria-label="Hide" onclick="mpCloseRow(this)"><i class="ri-close-line"></i></button></div>'+
            '<div class="sp-mp-pills">'+pillsHtml+'</div>'+
            '<div class="sp-mp-scroll-wrap">'+
                '<button type="button" class="sp-mp-arrow sp-mp-arrow-l hidden" aria-label="Scroll left" onclick="mpScroll(this,-1)"><i class="ri-arrow-left-s-line"></i></button>'+
                '<div class="sp-mp-scroll" onscroll="mpArrows(this)">'+cardsHtml+'</div>'+
                '<button type="button" class="sp-mp-arrow sp-mp-arrow-r" aria-label="Scroll right" onclick="mpScroll(this,1)"><i class="ri-arrow-right-s-line"></i></button>'+
            '</div>';
    }
    function fillMpBlocks(){
        document.querySelectorAll('.sp-mp-block[data-mp-id]:not([data-filled])').forEach(function(block){
            block.dataset.filled = '1';
            var rowId = block.dataset.mpId;
            if(!(window.mpArticles || []).length){ block.remove(); return; }
            block.innerHTML = mpBlockHtml(rowId);
        });
        requestAnimationFrame(function(){
            document.querySelectorAll('.sp-mp-scroll').forEach(function(s){
                if(!s.dataset.init){ s.dataset.init = '1'; s.scrollLeft = 0; }
                mpArrows(s);
            });
            document.querySelectorAll('.sp-mp-scroll-wrap').forEach(function(wrap){
                if(wrap.dataset.hoverBound) return;
                wrap.dataset.hoverBound = '1';
                var sc = wrap.querySelector('.sp-mp-scroll');
                if(!sc) return;
                var t;
                wrap.addEventListener('mouseenter', function(){ wrap.classList.add('show-arrows'); });
                wrap.addEventListener('mouseleave', function(){ wrap.classList.remove('show-arrows'); });
                sc.addEventListener('touchstart', function(){
                    wrap.classList.add('show-arrows');
                    clearTimeout(t);
                    t = setTimeout(function(){ wrap.classList.remove('show-arrows'); }, 2800);
                }, { passive:true });
            });
        });
    }
    window.mpOpen = function(id){ location.href = 'memepedia.php?open=' + id; };
    window.mpCloseRow = function(btn){ var s = btn.closest('.sp-mp-block'); if(s) s.remove(); };
    window.mpArrows = function(scroller){
        if(!scroller) return;
        var wrap = scroller.closest('.sp-mp-scroll-wrap'); if(!wrap) return;
        var l = wrap.querySelector('.sp-mp-arrow-l'), r = wrap.querySelector('.sp-mp-arrow-r');
        if(l) l.classList.toggle('hidden', scroller.scrollLeft <= 4);
        if(r) r.classList.toggle('hidden', scroller.scrollLeft >= scroller.scrollWidth - scroller.clientWidth - 4);
    };
    window.mpScroll = function(btn, dir){
        var wrap = btn.closest('.sp-mp-scroll-wrap'); if(!wrap) return;
        var sc = wrap.querySelector('.sp-mp-scroll'); if(!sc) return;
        sc.scrollBy({ left: dir * Math.max(200, sc.clientWidth * 0.75), behavior: 'smooth' });
    };
    window.mpSwitchStatus = function(btn, rowId, status){
        var row = btn.closest('.sp-mp-block'); if(!row) return;
        row.querySelectorAll('.sp-mp-pill').forEach(function(p){ p.classList.toggle('active', p === btn); });
        var doSwap = function(){
            var picked = mpPick(rowId, status);
            var sc = row.querySelector('.sp-mp-scroll');
            if(!sc) return;
            sc.innerHTML = picked.length ? picked.map(mpCardHtml).join('') : '<div style="padding:20px 4px;color:rgba(var(--fg),.4);font-size:.72rem;">Nothing here yet.</div>';
            sc.scrollLeft = 0; mpArrows(sc);
        };
        if(!window._mpSkeletonShown){
            window._mpSkeletonShown = true;
            var sc = row.querySelector('.sp-mp-scroll');
            if(sc) sc.innerHTML = mpSkeletonCards(4);
            setTimeout(doSwap, 420);
        } else {
            doSwap();
        }
    };

    function init(){ (window._mpPromise || Promise.resolve([])).then(fillMpBlocks); }
    if(document.readyState !== 'loading') init();
    else document.addEventListener('DOMContentLoaded', init);

    window.addEventListener('resize', function(){ document.querySelectorAll('.sp-mp-scroll').forEach(mpArrows); });
})();

/* ══════════════════════════════════════════════════════════════
   RIGHT COLUMN — Sponsored ads (list, no actions) — same as home.php
══════════════════════════════════════════════════════════════ */
(function(){
    var listEl = document.getElementById('rsAdsList');
    var sectionEl = document.getElementById('rsAdsSection');
    if(!listEl) return;

    function esc3(s){
        if(s===null||s===undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function cardHtml(a){
        var type = (a.type||'').toLowerCase();
        var media = (type === 'video')
            ? '<video class="rs-ad-thumb" src="'+esc3(a.file)+'" muted autoplay loop playsinline disablepictureinpicture onerror="this.style.visibility=\'hidden\'"></video>'
            : '<img class="rs-ad-thumb" src="'+esc3(a.file)+'" alt="" onerror="this.style.visibility=\'hidden\'">';
        var caption = a.caption ? a.caption : (a.ctaText || 'Sponsored');
        return '<a class="rs-ad-card" href="'+esc3(a.ctaUrl||'#')+'" target="_blank" rel="noopener sponsored">'+
            media+
            '<div class="rs-ad-info">'+
                '<div class="rs-ad-caption">'+esc3(caption)+'</div>'+
                '<div class="rs-ad-tag">Ad</div>'+
            '</div>'+
        '</a>';
    }

    fetch('?type=sidebar_ads').then(function(r){ return r.json(); }).then(function(list){
        list = Array.isArray(list) ? list.slice(0,3) : [];
        if(!list.length){ if(sectionEl) sectionEl.style.display='none'; return; }
        listEl.innerHTML = list.map(cardHtml).join('');
    }).catch(function(){ if(sectionEl) sectionEl.style.display='none'; });
})();

/* ═══════════ RIGHT COLUMN — collapse cards, See more / See less, people / memepedia cards ═══════════ */
function pfToggle(bar){
    var c = bar && bar.closest ? bar.closest('.pf-card') : null;
    if(!c) return;
    var collapsed = c.classList.toggle('collapsed');
    bar.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
}

function pfMore(btn){
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

(function(){
    function esc(s){
        if(s === null || s === undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtNum(n){
        n = parseInt(n) || 0;
        if(n >= 1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'') + 'M';
        if(n >= 1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'') + 'K';
        return n;
    }
    function shuffleArr(a){
        a=a.slice();
        for(var i=a.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)),t=a[i]; a[i]=a[j]; a[j]=t; }
        return a;
    }
    function fillCard(cardId, bodyId, countId, rowsHtml, init, step){
        var card = document.getElementById(cardId), body = document.getElementById(bodyId), cnt = document.getElementById(countId);
        if(!card || !body) return;
        if(!rowsHtml.length){ card.style.display='none'; return; }
        var html = '<div class="pf-list" data-init="'+init+'" data-step="'+step+'" data-shown="'+init+'">'+
            rowsHtml.map(function(r, i){ return i >= init ? r.replace('class="pf-row', 'class="pf-row pf-hold') : r; }).join('')+
            '</div>';
        if(rowsHtml.length > init) html += '<button type="button" class="pf-more" data-mode="more" onclick="pfMore(this)">See more</button>';
        body.innerHTML = html;
        if(cnt) cnt.textContent = String(rowsHtml.length);
        card.style.display = '';
    }

    function peopleRow(u){
        var uid = parseInt(u.id, 10), following = parseInt(u.is_following) > 0, priv = parseInt(u.is_private_any) > 0;
        var btn = (!priv || following)
            ? '<button type="button" class="track-btn pf-track'+(following?' following':'')+'" data-user="'+uid+'" onclick="handleTrackBtn(this)">'+(following?'Untrack':'Track')+'</button>'
            : '';
        return '<div class="pf-row">'+
            '<a href="profile.php?user_id='+uid+'"><span class="av-wrap" data-uid="'+uid+'"><img class="pf-av" src="'+esc(u.profile_pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'" alt="">'+(parseInt(u.is_online)>0?'<span class="on-dot"></span>':'')+'</span></a>'+
            '<div class="pf-row-info"><a class="pf-row-t" href="profile.php?user_id='+uid+'">'+esc(u.username)+'</a>'+
            '<div class="pf-row-s">'+esc(u.reason||'Suggested for you')+'</div></div>'+btn+'</div>';
    }
    (window._suggPromise || Promise.resolve([])).then(function(){
        var pool = (window._spSuggOrder && window._spSuggOrder.length ? window._spSuggOrder : (window.suggUsersData || [])).slice();
        var people = (pool.length >= 7 ? pool.slice(3) : pool).slice(0, 8);
        fillCard('rsPeopleCard', 'rsPeopleBody', 'rsPeopleCount', people.map(peopleRow), 4, people.length);
    });

    function mpRow(a){
        var meta = mpStatusMeta[a.status] || mpStatusMeta.trending;
        var mt = String(a.mediaType || '').toLowerCase(), thumb;
        if(a.mediaUrl && mt === 'video') thumb = '<video class="pf-av sq" src="'+esc(a.mediaUrl)+'#t=0.1" muted playsinline preload="metadata"></video>';
        else if(a.mediaUrl) thumb = '<img class="pf-av sq" src="'+esc(a.mediaUrl)+'" alt="" loading="lazy" onerror="this.outerHTML=\'<div class=&quot;pf-av sq&quot;><i class=&quot;ri-book-open-line&quot;></i></div>\'">';
        else thumb = '<div class="pf-av sq"><i class="ri-book-open-line"></i></div>';
        return '<div class="pf-row clickable" onclick="mpOpen('+a.id+')">'+thumb+
            '<div class="pf-row-info"><div class="pf-row-t two">'+esc(a.title)+'</div>'+
            '<div class="pf-row-s">'+esc(meta.label)+' · '+fmtNum(a.views||0)+' views</div></div></div>';
    }
    (window._mpPromise || Promise.resolve([])).then(function(all){
        var arts = shuffleArr(all || []).slice(0, 3);
        fillCard('rsMpCard', 'rsMpBody', 'rsMpCount', arts.map(mpRow), 3, 3);
    });
})();

/* ══ AD CARDS — same card as the home page (renderPost ported 1:1: no repost button on ads,
      hidden counts stay hidden, green dot, ad sounds disabled) ══ */
(function(){
    var FEED = peuraFeed;
    var visibility = {}, actionLock = {}, curAudioPost = null, trackMuted = true, postObs = null;

    if(!document.getElementById('ipvAudioPlayer')){
        var au = document.createElement('audio'); au.id = 'ipvAudioPlayer'; au.loop = true; au.muted = true; au.style.display = 'none';
        document.body.appendChild(au);
    }

    function esc(s){ if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;'); }
    function fmtNum(n){ n=parseInt(n)||0; if(n>=1e9) return (n/1e9).toFixed(1).replace(/\.0$/,'')+'B'; if(n>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M'; if(n>=1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'')+'K'; return n; }
    function cntHtml(cls,hidden,n){ return '<span class="ipv-action-count '+cls+'" data-hidden="'+(hidden?'1':'0')+'">'+(hidden?'':fmtNum(n||0))+'</span>'; }
    function linkify(raw){
        return esc(raw||'')
            .replace(/#([a-zA-Z0-9_]+)/g,function(m,tag){ return '<a href="search.php?focus=1&mq='+encodeURIComponent('#'+tag)+'" onclick="event.stopPropagation()" style="color:var(--gw, rgba(255,255,255,.65));font-weight:600;text-decoration:none;">'+m+'</a>'; })
            .replace(/@([a-zA-Z0-9_]+)/g,function(m,u){ return '<a href="profile.php?username='+encodeURIComponent(u)+'" onclick="return spMention(event,\''+u+'\')" style="color:var(--red);font-weight:600;text-decoration:none;">'+m+'</a>'; });
    }
    /* tap @username → looks the user up (deactivated people are not found) */
    window.spMention=function(e,u){
        e.stopPropagation();
        if(e.ctrlKey||e.metaKey||e.shiftKey||e.button===1) return true;
        fetch('?type=user&u='+encodeURIComponent(u)).then(function(r){ return r.json(); }).then(function(d){
            if(d && d.id) location.href='profile.php?user_id='+d.id; else showToast('User not found');
        }).catch(function(){ location.href='profile.php?username='+encodeURIComponent(u); });
        return false;
    };
    function typeTagInfo(t){
        t=(t||'').toLowerCase();
        return ({image:{icon:'ri-file-copy-line',label:'Photo'},video:{icon:'ri-vidicon-line',label:'Video'},gif:{icon:'ri-file-gif-line',label:'GIF'},sticker:{icon:'ri-shapes-line',label:'Sticker'},stickers:{icon:'ri-shapes-line',label:'Sticker'},text:{icon:'ri-file-text-line',label:'Text'}})[t]||{icon:'ri-file-copy-line',label:'Photo'};
    }
    function fullDate(s){
        if(!s) return ''; var d=new Date(String(s).replace(' ','T')); if(isNaN(d)) return s;
        var D=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return D[d.getDay()]+', '+d.getDate()+' '+M[d.getMonth()]+' '+d.getFullYear()+' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
    }
    function hpCollabOf(id){var a=[].concat((typeof peuraFeed!=='undefined'&&peuraFeed)||[],window._risePool||[],window.SP_LIST||[]);for(var i=0;i<a.length;i++){if(String(a[i].id)===String(id))return a[i].collab||null}return null}
    function dzCollabFit(){document.querySelectorAll('.av-wrap.ipv-collab:not([data-fit])').forEach(function(w){var h=w.closest('.ipv-header')||w.parentNode;var pr=document.createElement('span');pr.className='av-wrap';pr.style.cssText='position:absolute;visibility:hidden;left:-9999px;top:0';pr.innerHTML='<img class="ipv-avatar" alt="">';h.appendChild(pr);var s=pr.firstChild.getBoundingClientRect().width;h.removeChild(pr);if(s>8){w.style.setProperty('--cw',Math.round(s*1.45)+'px');w.setAttribute('data-fit','1');}});}
    (function(){var t=0;function q(){if(t)return;t=requestAnimationFrame(function(){t=0;dzCollabFit();});}try{new MutationObserver(q).observe(document.documentElement,{childList:true,subtree:true});}catch(e){}window.addEventListener('resize',function(){document.querySelectorAll('.av-wrap.ipv-collab[data-fit]').forEach(function(w){w.removeAttribute('data-fit');});q();});q();})();
    function isNewPost(s){ if(!s) return false; var d=new Date(String(s).replace(' ','T')); return !isNaN(d) && (Date.now()-d.getTime())<=172800*1000; }
    function likesText(p){
        var proof=Array.isArray(p.socialProof)?p.socialProof:[];
        var lp=proof.filter(function(sp){ return sp.action_type==='like'; });
        var c=p.likeCount||0, rc=p.repostCount||0, base='';
        if(p.hideLikes){ if(lp.length) base='<span class="ipv-likes-dim">Liked by</span> <b>'+esc(lp[0].username||'')+'</b>'; }
        else if(c>0){
            if(lp.length){
                var rest=c-1, first=esc(lp[0].username||'');
                base = rest>0 ? '<span class="ipv-likes-dim">Liked by</span> <b>'+first+'</b> <span class="ipv-likes-dim">and</span> <b>'+fmtNum(rest)+' other'+(rest===1?'':'s')+'</b>'
                              : '<span class="ipv-likes-dim">Liked by</span> <b>'+first+'</b>';
            } else base='<b>'+fmtNum(c)+' like'+(c===1?'':'s')+'</b>';
        }
        if(!p.hideReposts && rc>0){ var rp='<b>'+fmtNum(rc)+' repost'+(rc===1?'':'s')+'</b>'; base=base?base+' · '+rp:rp; }
        return base;
    }
    function socialRowHtml(p){
        var lp=Array.isArray(p.socialProof)?p.socialProof.filter(function(sp){ return sp.action_type==='like'; }):[];
        var av=lp.length?'<div class="ipv-social-avatars">'+lp.slice(0,3).map(function(sp){ return '<img src="'+esc(sp.profile_pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'">'; }).join('')+'</div>':'';
        var tx=likesText(p);
        if(!av && !tx) return '';
        return '<div class="ipv-social-row">'+av+(tx?'<div class="ipv-likes-text">'+tx+'</div>':'')+'</div>';
    }

    function renderPost(p){
        var media=(Array.isArray(p.media)&&p.media.length)?p.media:[{file:p.file,type:p.type}];
        var mc=media.length;
        var isOwn=(p.isYou===true);
        var mediaSet=['image','video','gif','sticker','stickers'];
        var firstType=((media[0].type)||'').toLowerCase();
        var isTextOnly=(mc===1 && mediaSet.indexOf(firstType)===-1);
        var hasVideo=media.some(function(m){ return (m.type||'').toLowerCase()==='video'; });
        var mediaInner;

        if(isTextOnly){
            var words=(p.caption||'').trim().split(/\s+/).filter(Boolean);
            var trunc=words.length>40
                ? linkify(words.slice(0,40).join(' '))+' <span class="ipv-more-wrap" onclick="event.stopPropagation();hpToggleCenterCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>'
                : linkify(p.caption||'');
            mediaInner='<div class="ipv-textonly-clickable" onclick="hpMediaTap(event,this,'+p.id+')">'+
                '<div class="ipv-text-bg" style="background-image:url(\'uploads/Text.jpg\')"></div><div class="ipv-text-overlay"></div>'+
                '<div class="ipv-center-caption-wrap"><div class="ipv-center-caption-text" data-full="'+esc(p.caption||'')+'">'+trunc+'</div></div></div>';
        } else {
            mediaInner='<div class="ipv-media-track">'+media.map(function(m,i){
                var t=(m.type||'').toLowerCase();
                if(t==='video'){
                    return '<div class="ipv-media-item" data-idx="'+i+'" data-type="video"><video src="'+esc(m.file)+'" muted loop playsinline preload="metadata" class="ipv-video" data-idx="'+i+'" onloadedmetadata="hpMeasure(this)" onclick="event.stopPropagation();hpMediaTap(event,this,'+p.id+')"></video></div>';
                }
                var src=(t==='text'||!m.file)?'uploads/Text.jpg':m.file;
                return '<div class="ipv-media-item" data-idx="'+i+'" data-type="'+(t||'image')+'"><img src="'+esc(src)+'" data-idx="'+i+'" loading="lazy" onerror="this.src=\'uploads/Text.jpg\'" onload="hpMeasure(this)" onclick="event.stopPropagation();hpMediaTap(event,this,'+p.id+')"></div>';
            }).join('')+'</div>';
        }

        var dotsHtml=(!isTextOnly&&mc>1)?'<div class="ipv-dots">'+media.slice(0,20).map(function(_,i){ return '<span class="ipv-dot'+(i===0?' active':'')+'"></span>'; }).join('')+'</div>':'';
        var counterHtml=(!isTextOnly&&mc>1)?'<div class="ipv-media-counter">1/'+mc+'</div>':'';
        var topRight=counterHtml?'<div class="ipv-top-right-tags">'+counterHtml+'</div>':'';
        var arrows=(!isTextOnly&&mc>1)?'<button type="button" class="ipv-media-arrow ipv-arrow-left" onclick="event.stopPropagation();hpArrowNav(this,-1)"><i class="ri-arrow-left-s-line"></i></button><button type="button" class="ipv-media-arrow ipv-arrow-right" onclick="event.stopPropagation();hpArrowNav(this,1)"><i class="ri-arrow-right-s-line"></i></button>':'';

        var tl='';
        if(p.groupTitle) tl+='<a href="group.php?id='+p.groupId+'" onclick="event.stopPropagation()" class="ipv-group-tag">From&nbsp;<b>'+esc(p.groupTitle)+'</b></a>';
        if(p.isAiGenerated) tl+='<div class="ipv-ai-tag"><i class="ri-sparkling-2-fill"></i><span>AI Generated</span></div>';
        var topLeft=tl?'<div class="ipv-top-left-tags">'+tl+'</div>':'';

        var partner='';
        /* paid partnership moved into the username flip */

        var strip='';
        if(!p.isAd && Array.isArray(p.socialProof) && p.socialProof.length){
            strip='<div class="ipv-social-proof-strip'+''+'">'+p.socialProof.slice(0,4).map(function(sp){
                var like=sp.action_type==='like';
                return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="ipv-sp-avatar-wrap"><img src="'+esc(sp.profile_pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'" class="ipv-sp-avatar"><span class="ipv-sp-badge" style="background:'+(like?'#f24455':'#7a4aff')+';display:flex;align-items:center;justify-content:center;">'+(like?'<i class="ri-heart-3-fill"></i>':'<svg fill="none" stroke="#fff" stroke-width="2.02" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true" style="width:66%;height:66%;display:block;flex:none;margin:auto"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg>')+'</span></a>';
            }).join('')+'</div>';
        }

        var cta='';
        if(p.isAd && p.adCtaUrl){
            var _ctaCol=p.adCtaColor||'#FF4C4C';
        cta='<a href="'+esc(dzSafeUrl(p.adCtaUrl))+'" target="_blank" rel="noopener" class="ipv-cta-bar" data-meme-id="'+p.id+'" style="--cta-color:'+esc(_ctaCol)+';--cta-ink:'+dzCtaInk(_ctaCol)+';" onclick="event.stopPropagation()"><span class="ipv-cta-bar-text">'+esc(p.adCtaText||'Learn more')+'</span><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></a>';
        }

        var controls='';
        /* same rule as the immersive modal: ads never play a music track — only the video's own sound, one button */
        var _bn=function(u){ return String(u||'').split('?')[0].split('/').pop().toLowerCase(); };
        var audioIsVideoFile=!!p.audioFile && media.some(function(m){ return (m.type||'').toLowerCase()==='video' && _bn(m.file)===_bn(p.audioFile); });
        var useTrack=!!p.audioFile && !p.isAd && !(p.audioIsOriginal && hasVideo) && !audioIsVideoFile;
        if(!isTextOnly && (hasVideo||p.audioFile)){
            controls='<div class="ipv-media-controls">';
            if(hasVideo) controls+='<button type="button" class="ipv-mute-btn ipv-video-mute-btn"'+((mc>1&&firstType!=='video'&&!useTrack)?' style="display:none"':'')+' onclick="event.stopPropagation();hpToggleVideoMute(this)"><i class="dz-snd off"></i></button>';
            else controls+='<button type="button" class="ipv-mute-btn ipv-track-mute-btn" onclick="'+(p.isAd?'dzAdSound(event)':'event.stopPropagation();hpToggleTrackMute()')+'"><i class="dz-snd off"></i></button>';
            controls+='</div>';
        }

        var displayName=isOwn?'You':esc(p.username||'');
        var newTag='';var isNewP=isNewPost(p.time);
        var canTrack=!isOwn && (!p.hideTrack || p.isFollowing);
        var trackBtn=canTrack?'<span class="ipv-dot-sep-red">•</span><button type="button" class="ipv-track-btn track-btn'+(p.isFollowing?' following':'')+'" data-user="'+p.userId+'" onclick="event.stopPropagation();handleTrackBtn(this)">'+(p.isFollowing?'Untrack':'Track')+'</button>':'';

        var nick=(!isOwn&&p.nickname)?'@'+esc(p.nickname):'';
        var _co=(p.collab&&p.collab.list&&p.collab.list.length)?p.collab:null,coName=displayName,coPics='',coOpen='';
        if(_co){
            coOpen='event.stopPropagation();dzCollabSheet('+p.id+',hpCollabOf('+p.id+'))';
            coName=displayName+' and '+(_co.list.length===1?esc(_co.list[0].username):_co.list.length+' others');
            nick='';trackBtn='';
            _co.list.slice(0,2).forEach(function(c,i){coPics+='<img class="ipv-avatar ipv-co-pic c'+(i+1)+'" src="'+esc(c.pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'" onclick="'+coOpen+'">';});
        }

        var audioHtml='';
        if(p.audioId!==undefined&&p.audioId!==null){
            var label=p.audioIsOriginal?((isOwn?p.username:displayName)+' · Original audio'):((p.audioTitle||'Audio')+(p.audioArtist?' — '+p.audioArtist:''));
            audioHtml='<div class="ipv-audio-tag" onclick="event.stopPropagation();ccmOpenSoundsPage('+p.userId+','+p.audioId+','+(p.isAd?'true':'false')+')" style="cursor:pointer;"><span class="ipv-audio-icon"><span class="ipv-eq-bar"></span><span class="ipv-eq-bar"></span><span class="ipv-eq-bar"></span></span><span class="ipv-audio-text">'+esc(label)+'</span></div>';
        }
        var partnerHtml=p.isPartnership?'<div class="ipv-sub-line ipv-partner-line"><span>Paid partnership'+(p.partnerBrand?' with <b>'+esc(p.partnerBrand)+'</b>':'')+'</span></div>':'';
        var panes=[];
        if(nick) panes.push('<div class="ipv-sub-line">'+nick+'</div>');
        if(partnerHtml) panes.push(partnerHtml);
        if(audioHtml) panes.push(audioHtml);
        var switcher='';
        if(panes.length>1) switcher='<div class="ipv-info-switcher" data-switcher="1">'+panes.map(function(h,i){ return '<div class="ipv-switch-pane'+(i===0?' active':'')+'">'+h+'</div>'; }).join('')+'</div>';
        else if(panes.length===1) switcher=panes[0];

        var headerCap='',cap='';
        if(!isTextOnly){
            var w=(p.caption||'').trim().split(/\s+/).filter(Boolean), capHtml;
            if(w.length>14){
                capHtml='<span class="ipv-cap-short" data-full="'+esc(p.caption||'')+'" data-expanded="0">'+linkify(w.slice(0,14).join(' '))+'<span class="ipv-more-wrap" onclick="event.stopPropagation();hpToggleCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span></span>';
            } else capHtml=linkify(p.caption||'');
            headerCap='<div class="ipv-header-caption">'+capHtml+'</div>';
            cap='<div class="ipv-caption"><b class="ipv-cap-user">'+displayName+'</b>'+capHtml+'</div>';
        }

        var wrapClass='ipv-media-wrap'+((!isTextOnly&&mc>1)?' ipv-multi':'')+(isTextOnly?' ipv-textonly':'');
        var audioAttrs=useTrack?' data-audio="'+esc(p.audioFile)+'" data-music-vol="'+(p.musicVolume!=null?p.musicVolume:80)+'" data-video-vol="'+(p.videoAudioVolume!=null?p.videoAudioVolume:40)+'"':'';

        return '<div class="ipv-post" data-id="'+p.id+'" data-user="'+p.userId+'"'+audioAttrs+'>'+
            '<div class="ipv-header">'+
                '<span class="av-wrap'+(_co?' ipv-collab n'+Math.min(2,_co.list.length):'')+'" data-uid="'+p.userId+'"><img class="ipv-avatar" src="'+esc(p.pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'" onclick="'+(_co?coOpen:'location.href=\'profile.php?user_id='+p.userId+'\'')+'">'+coPics+((p.online&&!isOwn)?'<span class="on-dot"></span>':'')+'</span>'+
                '<div class="ipv-header-info"><div class="ipv-username-row"><span class="ipv-username'+(_co?' ipv-co-name':'')+'" onclick="'+(_co?coOpen:'location.href=\'profile.php?user_id='+p.userId+'\'')+'">'+coName+'</span>'+((p.isVerified&&!_co)?(window.DZ_VB||''):'')+newTag+trackBtn+'</div>'+switcher+'</div>'+
                '<button type="button" class="ipv-kebab index-kebab-btn" data-id="'+p.id+'" onclick="event.stopPropagation();openIndexSheet('+p.id+')"><i class="ri-menu-line"></i></button>'+
            '</div>'+
            headerCap+
            '<div class="'+wrapClass+'" data-count="'+mc+'" data-active-idx="0">'+topLeft+topRight+strip+mediaInner+arrows+controls+partner+dotsHtml+'</div>'+cta+
            '<div class="ipv-actions"><div class="ipv-actions-left">'+
                '<div class="ipv-action-group"><button type="button" class="ipv-action-btn like-btn'+(p.liked?' active':'')+'" data-id="'+p.id+'" onclick="event.stopPropagation();spAdAction('+p.id+',\'like\',this)"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i></button>'+cntHtml('like-count',p.hideLikes,p.likeCount)+'</div>'+
                (p.isAd?'':'<div class="ipv-action-group"><button type="button" class="ipv-action-btn repost-btn'+(p.reposted?' active':'')+'" data-id="'+p.id+'" onclick="event.stopPropagation();spAdAction('+p.id+',\'repost\',this)"><i class="sp-rp"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg></i></button>'+cntHtml('repost-count',p.hideReposts,p.repostCount)+'</div>')+
                '<div class="ipv-action-group"><button type="button" class="ipv-action-btn comment-btn" data-id="'+p.id+'" aria-label="Comments" onclick="event.stopPropagation();spOpenComments(event,'+p.id+')"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg><svg class="cm-off-ic" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/><path d="M3.5 3.5l17 17"/></svg></i></button><span class="ipv-action-count comment-count" data-id="'+p.id+'" data-hidden="0">0</span></div>'+
                '<div class="ipv-action-group"><button type="button" class="ipv-action-btn fav-btn'+(p.faved?' active':'')+'" data-id="'+p.id+'" onclick="event.stopPropagation();spAdAction('+p.id+',\'fav\',this)"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21l-7-5-7 5V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1z"/></svg></i></button>'+cntHtml('fav-count',p.hideSaves,p.favCount)+'</div>'+
                '<div class="ipv-action-group"><button type="button" class="ipv-action-btn share-btn" data-id="'+p.id+'" onclick="event.stopPropagation();openShareModal('+p.id+')"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i></button>'+cntHtml('share-count',p.hideShares,p.shareCount)+'</div>'+
            '</div>'+dzAdTag(p,isNewP)+'</div>'+
            socialRowHtml(p)+cap+
            (p.time?'<div class="ipv-time">'+esc(fullDate(p.time))+'</div>':'')+
        '</div>';
    }

    window.hpToggleCaption=function(el){
        var wrap=el.closest('.ipv-cap-short'); if(!wrap) return;
        var open=wrap.dataset.expanded==='1', full=wrap.dataset.full||'';
        if(!open){ wrap.dataset.expanded='1'; wrap.innerHTML=linkify(full)+' <span class="ipv-more-toggle" onclick="event.stopPropagation();hpToggleCaption(this)"> less</span>'; }
        else { wrap.dataset.expanded='0'; wrap.innerHTML=linkify(full.trim().split(/\s+/).slice(0,14).join(' '))+'<span class="ipv-more-wrap" onclick="event.stopPropagation();hpToggleCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>'; }
    };
    window.hpToggleCenterCaption=function(el){
        var t=el.closest('.ipv-center-caption-text'); if(!t) return;
        var full=t.dataset.full||'';
        if(t.dataset.open!=='1'){ t.dataset.open='1'; t.innerHTML=linkify(full)+' <span class="ipv-more-toggle" onclick="event.stopPropagation();hpToggleCenterCaption(this)"> less</span>'; }
        else { t.dataset.open='0'; t.innerHTML=linkify(full.trim().split(/\s+/).slice(0,40).join(' '))+'<span class="ipv-more-wrap" onclick="event.stopPropagation();hpToggleCenterCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>'; }
    };

    /* ONE sound at a time (same as the modal): only the video on screen can be heard; without a music track it follows the person's
       unmute choice (post.dataset.vsound='1'), so it carries on from video to video without tapping again */
    function activeVideo(post){
        var wrap=post.querySelector('.ipv-media-wrap');
        if(wrap){ var i=parseInt(wrap.dataset.activeIdx||'0',10)||0; return wrap.querySelector('.ipv-media-item[data-idx="'+i+'"] video'); }
        return post.querySelector('.ipv-video');
    }
    function applyVideoSound(post){
        if(!post) return;
        var act=activeVideo(post), on=post.dataset.vsound==='1';
        post.querySelectorAll('video.ipv-video').forEach(function(v){ if(v!==act){ if(!v.paused) v.pause(); v.muted=true; } });
        if(act && !post.dataset.audio) act.muted=!on;
        var b=post.querySelector('.ipv-video-mute-btn i');
        if(b && !post.dataset.audio) b.className=on?'dz-snd on':'dz-snd off';
    }

    function observePosts(posts){
        if(!('IntersectionObserver' in window)) return;
        if(!postObs){
            postObs=new IntersectionObserver(function(es){
                es.forEach(function(e){
                    var post=e.target; visibility[post.dataset.id]=e.intersectionRatio;
                    var v=activeVideo(post);
                    if(e.isIntersecting&&e.intersectionRatio>.6){ applyVideoSound(post); if(v) v.play().catch(function(){}); }
                    else post.querySelectorAll('video.ipv-video').forEach(function(x){ x.pause(); x.muted=true; });   /* off screen = silent */
                });
                syncAudioFocus();
            },{threshold:[0,.6,1]});
        }
        posts.forEach(function(p){ postObs.observe(p); });
    }
    function syncAudioFocus(){
        var bestId=null,best=0;
        Object.keys(visibility).forEach(function(id){ if(visibility[id]>best){ best=visibility[id]; bestId=id; } });
        var a=document.getElementById('ipvAudioPlayer');
        if(best<.6||!bestId){ stopAudio(); return; }
        if(bestId===curAudioPost) return;
        var post=document.querySelector('#postList .ipv-post[data-id="'+bestId+'"]'); if(!post) return;
        var url=post.dataset.audio;
        curAudioPost=bestId;
        if(!url){ a.pause(); return; }
        if(a.getAttribute('src')!==url){ a.src=url; a.currentTime=0; }
        a.muted=trackMuted; a.volume=(parseInt(post.dataset.musicVol,10)||80)/100;
        a.play().catch(function(){});
        var vv=(parseInt(post.dataset.videoVol,10)||40)/100;
        post.querySelectorAll('video.ipv-video').forEach(function(v){ v.volume=vv; v.muted=trackMuted; });   /* video + music: both follow the one button */
        updateTrackMuteIcons();
    }
    function stopAudio(){ var a=document.getElementById('ipvAudioPlayer'); if(a) a.pause(); curAudioPost=null; }
    function pauseAll(){ document.querySelectorAll('#postList .ipv-post video').forEach(function(v){ v.pause(); }); stopAudio(); }
    window.spAdResume=function(){
        var mid=window.innerHeight/2;
        document.querySelectorAll('#postList .ipv-post').forEach(function(post){
            var r=post.getBoundingClientRect();
            if(r.top<mid&&r.bottom>mid){ var v=activeVideo(post); applyVideoSound(post); if(v) v.play().catch(function(){}); }
        });
        syncAudioFocus();
    };

    function initSwitchers(posts){
        posts.forEach(function(post){
            var sw=post.querySelector('[data-switcher="1"]'); if(!sw) return;
            var panes=sw.querySelectorAll('.ipv-switch-pane'); if(panes.length<2) return;
            var i=0;
            setInterval(function(){ panes[i].classList.remove('active'); i=(i+1)%panes.length; panes[i].classList.add('active'); },5000);
        });
    }
    function initCtaCycles(posts){ /* the ad button is now a steady bar under the picture: nothing to cycle */ }

    var MULTI_RATIO=3.5/4, MULTI_TOL=.15, LONG_RATIO=1.2;
    window.hpMeasure=function(el){
        var wrap=el.closest('.ipv-media-wrap'); if(!wrap) return;
        var w=el.tagName==='VIDEO'?el.videoWidth:el.naturalWidth, h=el.tagName==='VIDEO'?el.videoHeight:el.naturalHeight;
        if(!w||!h) return;
        var ratio=h/w; el.dataset.ratio=ratio;
        if(wrap.classList.contains('ipv-multi')){
            el.classList.toggle('ipv-fit-cover',Math.abs(ratio-MULTI_RATIO)/MULTI_RATIO<=MULTI_TOL);
            return;
        }
        if(parseInt(el.dataset.idx||'0',10)===parseInt(wrap.dataset.activeIdx||'0',10)) applyAspect(wrap,ratio);
    };
    function applyAspect(wrap,ratio){
        var c=Math.max(.55,Math.min(ratio,1.6)), width=wrap.clientWidth||wrap.offsetWidth; if(!width) return;
        wrap.style.aspectRatio='auto'; wrap.style.height=Math.round(width*c)+'px';
        var post=wrap.closest('.ipv-post'); if(post) post.classList.toggle('ipv-long-header',c>=LONG_RATIO);
    }
    window.addEventListener('resize',function(){
        document.querySelectorAll('#postList .ipv-media-wrap:not(.ipv-multi):not(.ipv-textonly)').forEach(function(wrap){
            var i=parseInt(wrap.dataset.activeIdx||'0',10);
            var el=wrap.querySelector('.ipv-media-item[data-idx="'+i+'"] img, .ipv-media-item[data-idx="'+i+'"] video');
            if(el&&el.dataset.ratio) applyAspect(wrap,parseFloat(el.dataset.ratio));
        });
    });

    function bindCarousels(posts){
        posts.forEach(function(post){
            var wrap=post.querySelector('.ipv-media-wrap[data-count]'); if(!wrap) return;
            var count=parseInt(wrap.dataset.count,10); if(count<=1) return;
            var track=wrap.querySelector('.ipv-media-track'), dots=wrap.querySelectorAll('.ipv-dot'), counter=wrap.querySelector('.ipv-media-counter');
            var idx=0,startX=0,dragging=false;
            function goTo(n){
                idx=Math.max(0,Math.min(n,count-1)); wrap.dataset.activeIdx=String(idx);
                track.style.transform='translateX(-'+(idx*100)+'%)';
                dots.forEach(function(d,i){ d.classList.toggle('active',i===idx); });
                if(counter) counter.textContent=(idx+1)+'/'+count;
                wrap.querySelectorAll('.ipv-media-item video').forEach(function(v){ if(parseInt(v.dataset.idx,10)!==idx) v.pause(); });
                applyVideoSound(post);          /* new slide follows the unmute choice; every other video stays silent */
                wrap.querySelectorAll('.ipv-media-item video').forEach(function(v){ if(parseInt(v.dataset.idx,10)===idx && (visibility[post.dataset.id]||0)>.6) v.play().catch(function(){}); });
                var vm=wrap.querySelector('.ipv-video-mute-btn');
                if(vm) vm.style.display=(wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"] video')||post.dataset.audio)?'flex':'none';
                var tt=wrap.querySelector('.ipv-type-tag'), it=wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"]');
                if(tt&&it){ var info=typeTagInfo(it.dataset.type||'image'); tt.innerHTML='<i class="'+info.icon+'"></i><span>'+info.label+'</span>'; }
            }
            wrap._ipvGoTo=function(d){ goTo(idx+d); };
            wrap.addEventListener('touchstart',function(e){ startX=e.touches[0].clientX; dragging=true; track.style.transition='none'; },{passive:true});
            wrap.addEventListener('touchmove',function(e){ if(!dragging) return; track.style.transform='translateX(calc(-'+(idx*100)+'% + '+(e.touches[0].clientX-startX)+'px))'; },{passive:true});
            wrap.addEventListener('touchend',function(e){
                if(!dragging) return; dragging=false; track.style.transition='transform .28s cubic-bezier(.22,1,.36,1)';
                var dx=e.changedTouches[0].clientX-startX, n=idx;
                if(Math.abs(dx)>45) n=dx<0?idx+1:idx-1;
                goTo(n);
            },{passive:true});
        });
    }
    window.hpArrowNav=function(btn,dir){ var w=btn.closest('.ipv-media-wrap'); if(w&&w._ipvGoTo) w._ipvGoTo(dir); };

    window.hpToggleVideoMute=function(btn){
        var wrap=btn.closest('.ipv-media-wrap'), i=parseInt(wrap.dataset.activeIdx||'0',10);
        var v=wrap.querySelector('.ipv-media-item[data-idx="'+i+'"] video'), post=btn.closest('.ipv-post');
        var hasTrack=!!(post&&post.dataset.audio), nowMuted;
        if(v){ v.muted=!v.muted; nowMuted=v.muted; }
        else if(hasTrack){ nowMuted=!trackMuted; }      /* gallery slide that is a photo */
        else return;
        if(!hasTrack&&post){ post.dataset.vsound=nowMuted?'0':'1'; applyVideoSound(post); }   /* remembered for the next video of this post */
        if(hasTrack){                                      /* video + music: one button, both sounds */
            trackMuted=nowMuted; var a=document.getElementById('ipvAudioPlayer'); if(a) a.muted=trackMuted;
            wrap.querySelectorAll('video').forEach(function(x){ x.muted=nowMuted; }); updateTrackMuteIcons();
        }
        btn.querySelector('i').className=nowMuted?'dz-snd off':'dz-snd on';
    };
    window.hpToggleTrackMute=function(){
        var a=document.getElementById('ipvAudioPlayer'); if(!a) return;
        trackMuted=!trackMuted; a.muted=trackMuted; updateTrackMuteIcons();
    };
    function updateTrackMuteIcons(){
        document.querySelectorAll('#postList .ipv-track-mute-btn i, #postList .ipv-post[data-audio] .ipv-video-mute-btn i').forEach(function(i){ i.className=trackMuted?'dz-snd off':'dz-snd on'; });
    }

    window.hpMediaTap=function(e,el,memeId){
        var box=el.closest('.ipv-media-wrap')||el, now=Date.now(), dt=now-(box._lastTap||0);
        if(dt>0&&dt<320){
            box._lastTap=0; if(box._tapTimer){ clearTimeout(box._tapTimer); box._tapTimer=null; }
            doubleTapLike(box,e,memeId);
        } else {
            box._lastTap=now;
            box._tapTimer=setTimeout(function(){ pauseAll(); hpOpenPost(memeId); },260);
        }
    };
    function doubleTapLike(box,e,memeId){
        if(window.dzHaptic) dzHaptic.medium();      /* felt at the tap itself; the like that lands a moment later stays silent */
        var r=box.getBoundingClientRect();
        var cx=(e&&typeof e.clientX==='number'&&e.clientX!==0)?e.clientX:(r.left+r.width/2);
        var cy=(e&&typeof e.clientY==='number'&&e.clientY!==0)?e.clientY:(r.top+r.height/2);
        var post=box.closest('.ipv-post'), b=post?post.querySelector('.like-btn'):null;
        var go=function(){ if(b&&!b.classList.contains('active')){ window._dzHapSkip=true; window.spAdAction(memeId,'like',b); window._dzHapSkip=false; } };
        /* same animation as the Zeems modal: the heart pops where you tapped, jumps to this post's like button, and the like happens when it lands */
        if(typeof ccmShowHeartBurst==='function'){ ccmShowHeartBurst(box,cx-r.left,cy-r.top,go,b?b.querySelector('i'):null); }
        else { heartBurst(box,cx-r.left,cy-r.top); go(); }
    }
    function heartBurst(box,x,y){
        box.querySelectorAll('.ipv-double-tap-heart,.ipv-heart-ring,.ipv-heart-particle').forEach(function(el){ el.remove(); });
        var ring=document.createElement('div'); ring.className='ipv-heart-ring'; ring.style.left=x+'px'; ring.style.top=y+'px'; box.appendChild(ring);
        requestAnimationFrame(function(){ ring.classList.add('show'); }); setTimeout(function(){ ring.remove(); },800);
        var h=document.createElement('div'); h.className='ipv-double-tap-heart'; h.style.left=x+'px'; h.style.top=y+'px'; h.innerHTML='<i class="ri-heart-3-fill"></i>'; box.appendChild(h);
        requestAnimationFrame(function(){ h.classList.add('show'); }); setTimeout(function(){ h.remove(); },950);
        for(var i=0;i<8;i++)(function(i){
            var rad=((360/8)*i+(Math.random()*20-10))*Math.PI/180, dist=60+Math.random()*42;
            var p=document.createElement('div'); p.className='ipv-heart-particle'; p.style.left=x+'px'; p.style.top=y+'px';
            p.style.setProperty('--tx',Math.cos(rad)*dist+'px'); p.style.setProperty('--ty',Math.sin(rad)*dist+'px'); p.style.setProperty('--tr',(Math.random()*80-40)+'deg');
            p.innerHTML='<i class="ri-heart-3-fill" style="font-size:'+(12+Math.random()*10)+'px;"></i>'; box.appendChild(p);
            setTimeout(function(){ requestAnimationFrame(function(){ p.classList.add('show'); }); },40+i*15); setTimeout(function(){ p.remove(); },950+i*15);
        })(i);
    }

    var MAP={like:{a:'toggle_like',c:'likeCount',s:'liked'},repost:{a:'toggle_repost',c:'repostCount',s:'reposted'},fav:{a:'toggle_favorite',c:'favCount',s:'faved'}};
    window.spAdAction=function(memeId,type,btn){
        var key=memeId+':'+type; if(actionLock[key]) return; actionLock[key]=true;
        var m=MAP[type], was=btn.classList.contains('active'), next=!was;
        if(window._dzHapSkip){ window._dzHapSkip=false; } else if(window.dzHaptic){ if(type==='fav') dzHaptic.light(); else if(next) dzHaptic.medium(); else dzHaptic.light(); }
        var cntEl=btn.parentNode.querySelector('.ipv-action-count');
        var hid=!!(cntEl && cntEl.dataset.hidden==='1');
        var item=FEED.find(function(x){ return String(x.id)===String(memeId); });
        var prev=item?(item[m.c]||0):0;
        btn.classList.toggle('active',next);
        if(cntEl && !hid) cntEl.textContent=fmtNum(next?prev+1:Math.max(0,prev-1));
        if(type==='repost'){
            var ic=btn.querySelector('i'); btn.classList.remove('arc-swap-active'); void ic.offsetWidth; btn.classList.add('arc-swap-active');
            ic.addEventListener('animationend',function h(){ btn.classList.remove('arc-swap-active'); ic.removeEventListener('animationend',h); });
        }
        if(type==='fav'){ if(next&&typeof ccmShowSavedToast==='function') ccmShowSavedToast(memeId); else if(!next&&typeof ccmHideSavedToast==='function') ccmHideSavedToast(); }

        fetch('search.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action='+m.a+'&meme_id='+memeId})
        .then(function(r){ return r.json(); }).then(function(d){
            delete actionLock[key];
            if(d.count===undefined){ btn.classList.toggle('active',was); if(cntEl && !hid) cntEl.textContent=fmtNum(prev); if(type==='fav'&&next&&typeof ccmHideSavedToast==='function') ccmHideSavedToast(); return; }
            if(item){ if(d.count!==null) item[m.c]=parseInt(d.count); item[m.s]=next; }
            if(cntEl && !hid && d.count!==null) cntEl.textContent=fmtNum(d.count);
            if(type==='like'||type==='repost'){ var post=btn.closest('.ipv-post'); if(post&&item) updateSocialRow(post,item); }
        }).catch(function(){
            delete actionLock[key]; btn.classList.toggle('active',was); if(cntEl && !hid) cntEl.textContent=fmtNum(prev);
            if(type==='fav'&&next&&typeof ccmHideSavedToast==='function') ccmHideSavedToast();
        });
    };
    function updateSocialRow(post,p){
        var ex=post.querySelector('.ipv-social-row'); if(ex) ex.remove();
        var html=socialRowHtml(p); if(html){ var a=post.querySelector('.ipv-actions'); if(a) a.insertAdjacentHTML('afterend',html); }
    }
    window.spSyncAdCards=function(){
        document.querySelectorAll('#postList .ipv-post').forEach(function(post){
            var p=FEED.find(function(x){ return String(x.id)===String(post.dataset.id); }); if(!p) return;
            [['.like-btn','.like-count','liked','likeCount'],['.repost-btn','.repost-count','reposted','repostCount'],['.fav-btn','.fav-count','faved','favCount']].forEach(function(t){
                var b=post.querySelector(t[0]), c=post.querySelector(t[1]);
                if(b) b.classList.toggle('active',!!p[t[2]]); if(c && c.dataset.hidden!=='1') c.textContent=fmtNum(p[t[3]]||0);
            });
            var sc=post.querySelector('.share-count'); if(sc && sc.dataset.hidden!=='1') sc.textContent=fmtNum(p.shareCount||0);
            updateSocialRow(post,p);
        });
    };

    window.handleTrackBtn=function(btn){
        if(window.dzHaptic){ if(btn.classList.contains('following')) dzHaptic.light(); else dzHaptic.medium(); }
        var uid=btn.dataset.user;
        fetch('search.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=toggle_follow&user_id='+uid})
        .then(function(r){ return r.json(); }).then(function(d){
            if(d.status!=='ok') return;
            document.querySelectorAll('.track-btn[data-user="'+uid+'"]').forEach(function(b){
                b.textContent=d.following?'Untrack':'Track'; b.classList.toggle('following',!!d.following); b.classList.toggle('tracking',!!d.following);
            });
            document.querySelectorAll('.sp-sugg-track-btn[data-user="'+uid+'"]').forEach(function(b){
                b.textContent=d.following?'Untrack':'Track'; b.classList.toggle('following',!!d.following);
            });
            FEED.forEach(function(p){ if(String(p.userId)===String(uid)) p.isFollowing=!!d.following; });
        });
    };

    window.spHydrateAds=function(){
        var hosts=document.querySelectorAll('#postList .sp-adcard:not(.sp-hidden):not([data-done])');
        var newPosts=[];
        hosts.forEach(function(h){
            h.dataset.done='1';
            var p=FEED.find(function(x){ return String(x.id)===String(h.dataset.id); });
            if(!p){ h.remove(); return; }
            h.innerHTML=renderPost(p);
            var post=h.querySelector('.ipv-post');
            post.classList.add('ipv-post-enter');
            newPosts.push(post);
        });
        if(newPosts.length){ bindCarousels(newPosts); initSwitchers(newPosts); initCtaCycles(newPosts); observePosts(newPosts); }
    };
})();


/* ═══════════ PAGING — load the next page of results when the reader reaches the end (same endpoint as home) ═══════════ */
(function(){
    var P = window.spPaging;
    if(!P) return;
    var feedIds = {}; peuraFeed.forEach(function(p){ feedIds[String(p.id)] = 1; });

    function esc(s){ if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    /* caption → 25-word cut, #tags and @mentions (same as spCaptionHtml in PHP) */
    function capHtml(raw){
        var c = String(raw||'').replace(/[\r\n]+/g,' ').trim();
        if(!c) return '';
        var words = c.split(/\s+/), cut = false;
        if(words.length > 25){ words = words.slice(0,25); cut = true; }
        var t = esc(words.join(' '));
        t = t.replace(/([#@])([a-zA-Z0-9_]+)/g, function(m,k,n){
            return k === '#'
                ? '<a class="cap-tag" href="search.php?focus=1&amp;mq='+encodeURIComponent('#'+n)+'" onclick="event.stopPropagation()">#'+n+'</a>'
                : '<a class="cap-mention" href="profile.php?username='+encodeURIComponent(n)+'" onclick="event.stopPropagation()">@'+n+'</a>';
        });
        return t + (cut ? '<span class="cap-more">...</span>' : '');
    }
    var SP_IC={'ri-heart-3-fill':'<i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i>','ri-bookmark-fill':'<i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21l-7-5-7 5V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1z"/></svg></i>'};
    function stat(cls, id, type, icon, hidden, n, extra){
        return '<span class="stat '+cls+'" data-id="'+id+'" onclick="spAction(event,'+id+',\''+type+'\',this)">'+(SP_IC[icon]?SP_IC[icon]:(/-fill$/.test(icon)?'<i class="'+icon.replace(/-fill$/,'-line')+' ipv-ic-off"></i><i class="'+icon+' ipv-ic-on"></i>':(icon==='ri-repeat-line'?'<i class="sp-rp"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg></i>':'<i class="'+icon+'"></i>')))+'<span class="count" data-hidden="'+(hidden?'1':'0')+'">'+(hidden?'':spFmtNum(n||0))+'</span></span>';
    }
    var THUMB = {image:1,video:1,gif:1,sticker:1,stickers:1};
    function rowHtml(p){
        var id = parseInt(p.id,10), uid = p.userId;
        var media = Array.isArray(p.media) ? p.media : [], thumb = null;
        for(var i=0;i<media.length;i++){ var m = media[i]; if(THUMB[String(m.type||'').toLowerCase()] && m.file && m.file !== 'uploads/0'){ thumb = m; break; } }
        var h = '<div class="post-row sp-item meme-tile sp-hidden" data-id="'+id+'" data-uploader="'+esc(uid)+'" onclick="hpOpenPost('+id+')">';
        if(thumb){
            h += '<div class="post-media-col"><div class="post-thumb">'+
                (String(thumb.type).toLowerCase() === 'video'
                    ? '<video data-src="'+esc(thumb.file)+'" muted loop playsinline preload="none" class="sp-video"></video>'
                    : '<img src="'+esc(thumb.file)+'" loading="lazy" alt="" onerror="this.src=\'uploads/Text.jpg\'">')+
                (media.length > 1 ? '<span class="thumb-badge"><i class="ri-stack-line"></i>'+media.length+'</span>' : '')+
                '</div></div>';
        }
        var nick = String(p.nickname||'').trim(), cap = capHtml(p.caption);
        h += '<div class="post-info"><div class="post-head">'+
            '<a href="profile.php?user_id='+esc(uid)+'" onclick="event.stopPropagation()" style="display:flex;flex-shrink:0;"><span class="av-wrap" data-uid="'+esc(uid)+'"><img class="post-avatar" src="'+esc(p.pic||'mee.jpg')+'" onerror="this.src=\'mee.jpg\'" alt="">'+(p.online?'<span class="on-dot"></span>':'')+'</span></a>'+
            '<a href="profile.php?user_id='+esc(uid)+'" onclick="event.stopPropagation()" class="post-username">'+esc(p.isYou ? 'You' : p.username)+'</a>'+
            (p.isVerified ? (window.DZ_VB||'').replace('class="dz-vb"','class="dz-vb dz-vb-list"') : '')+
            (nick ? '<span class="post-dot">·</span><span class="post-nick">@'+esc(nick)+'</span>' : '')+
            '</div>'+
            (cap ? '<div class="post-caption">'+cap+'</div>' : '')+
            '<div class="post-stats">'+
                stat('like-btn'+(p.liked?' active':''), id, 'like', 'ri-heart-3-fill', p.hideLikes, p.likeCount)+
                stat('repost-btn'+(p.reposted?' active':''), id, 'repost', 'ri-repeat-line', p.hideReposts, p.repostCount)+
                '<span class="stat comment-btn" data-id="'+id+'" onclick="spOpenComments(event,'+id+')"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg><svg class="cm-off-ic" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/><path d="M3.5 3.5l17 17"/></svg></i><span class="count comment-count" data-id="'+id+'" data-hidden="0">0</span></span>'+
                stat('fav-btn'+(p.faved?' active':''), id, 'fav', 'ri-bookmark-fill', p.hideSaves, p.favCount)+
                '<span class="stat share-btn" data-id="'+id+'" onclick="event.stopPropagation();openShareModal('+id+')"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i><span class="count share-count" data-hidden="'+(p.hideShares?'1':'0')+'">'+(p.hideShares?'':spFmtNum(p.shareCount||0))+'</span></span>'+
            '</div></div>'+
            '<button type="button" class="post-kebab index-kebab-btn" data-id="'+id+'" aria-label="More options" onclick="event.stopPropagation();openIndexSheet('+id+')"><i class="ri-menu-line"></i></button></div>';
        return h;
    }
    function adHtml(p){
        return '<div class="sp-item sp-adcard meme-tile sp-hidden" data-id="'+parseInt(p.id,10)+'" data-uploader="'+esc(p.userId)+'"></div>';
    }

    window.spFetchMore = function(){
        if(P.fetching || !P.hasMore) return;
        P.fetching = true;
        var qs = new URLSearchParams(location.search);
        qs.delete('type');
        qs.set('feed_json','1'); qs.set('page', String(P.page + 1)); qs.set('seed', String(P.seed)); qs.set('asof', String(P.asof));
        fetch(location.pathname + '?' + qs.toString(), {credentials:'same-origin', headers:{'Accept':'application/json'}})
        .then(function(r){ if(!r.ok) throw new Error('http '+r.status); return r.json(); })
        .then(function(d){
            P.fetching = false; P.fails = 0;
            /* safety: if the server did not move on to the next page (it answered with the same page again), stop instead of looping */
            if(d.page && d.page <= P.page){ P.hasMore = false; spEnsureSentinel(); return; }
            P.page    = d.page || (P.page + 1);
            P.hasMore = !!d.hasMore;
            /* the ⋯ menu reads kebabData — add the new posts */
            if(d.kebab && window.kebabData){ Object.keys(d.kebab).forEach(function(k){ window.kebabData[k] = d.kebab[k]; }); }
            var list = document.getElementById('postList'), sent = document.getElementById('spSentinel'), html = '', added = 0;
            (d.feed || []).forEach(function(p){
                if(feedIds[String(p.id)]) return;             /* already shown */
                feedIds[String(p.id)] = 1; peuraFeed.push(p); added++;
                html += p.isAd ? adHtml(p) : rowHtml(p);
            });
            if(added && list){
                var tmp = document.createElement('div'); tmp.innerHTML = html;
                var frag = document.createDocumentFragment();
                while(tmp.firstChild) frag.appendChild(tmp.firstChild);
                if(sent) list.insertBefore(frag, sent); else list.appendChild(frag);
                spRevealBatch(SP_BATCH);                       /* shows the first few and re-arms the sentinel */
            } else if(P.hasMore){
                window.spFetchMore();                          /* a whole page of already-seen posts → next page */
            } else {
                spEnsureSentinel();                            /* really the end */
            }
        })
        .catch(function(){
            P.fetching = false; P.fails++;
            if(P.fails < 3) setTimeout(spEnsureSentinel, 1500 * P.fails);   /* retry a couple of times, then stop quietly */
            else { P.hasMore = false; spEnsureSentinel(); }
        });
    };
})();


/* ═══════════ IMPRESSIONS + DWELL TIME — same rules as home, applied to the result rows (ads are never counted).
      "Seen"   : a result counts as seen once ≥60% of it has been on screen for 1 second.
      "Skip"   : it was on screen (≥60%) for 0.25–1 s and you moved on without reacting to it.
      "Linger" : it stayed on screen for 3 s or more.
      Reported in small batches; the server uses it to avoid repeating posts and to learn what works.
      Set SP_IMPRESSIONS to false to switch all of this off for search. ═══════════ */
(function(){
    var SP_IMPRESSIONS = true;
    var noop = function(){};
    window.spObserveImps = noop; window.spDwPause = noop; window.spDwResume = noop;
    if(!SP_IMPRESSIONS || !('IntersectionObserver' in window)) return;
    var impTimers = {}, impQueue = [], impSent = {};
    var visStart = {}, dwDone = {}, dwQueue = [], visRatio = {};
    var DW_MIN = 250, DW_SKIP_MAX = 1000, DW_LINGER = 3000;     /* milliseconds — same thresholds as home / the server */

    function rowById(id){ return document.querySelector('#postList .post-row[data-id="'+id+'"]'); }
    function dwLeave(row, noSkip){
        var id = row.dataset.id, t = visStart[id];
        if(!t) return; delete visStart[id];
        var ms = Date.now() - t;
        if(ms < DW_MIN || (ms >= DW_SKIP_MAX && ms < DW_LINGER)) return;      /* nothing worth reporting */
        if(ms < DW_SKIP_MAX){
            if(noSkip) return;                                                /* switching tabs is not skipping */
            if(row.querySelector('.like-btn.active,.repost-btn.active,.fav-btn.active')) return;   /* you reacted → not a skip */
        }
        dwDone[id] = 1; dwQueue.push(id + ':' + Math.min(ms, 60000));
        if(dwQueue.length >= 10) flush(false);
    }
    function impVisible(row, on){
        var id = row.dataset.id;
        if(!id || row.classList.contains('sp-adcard')) return;
        if(on){ if(!visStart[id] && !dwDone[id]) visStart[id] = Date.now(); }
        else dwLeave(row, false);
        if(impSent[id]) return;
        if(on){
            if(!impTimers[id]) impTimers[id] = setTimeout(function(){
                delete impTimers[id]; impSent[id] = 1; impQueue.push(id);
                if(impQueue.length >= 10) flush(false);
            }, 1000);
        } else if(impTimers[id]){ clearTimeout(impTimers[id]); delete impTimers[id]; }
    }
    /* opening a result in the zeems modal is interest, not a skip, and time inside the modal is not list time */
    window.spDwPause = function(){ visStart = {}; };
    window.spDwResume = function(){
        var now = Date.now();
        document.querySelectorAll('#postList .post-row').forEach(function(r){
            var id = r.dataset.id;
            if(id && (visRatio[id] || 0) >= .6 && !visStart[id] && !dwDone[id]) visStart[id] = now;
        });
    };
    function dwFinalizeAll(){
        Object.keys(visStart).forEach(function(id){
            var r = rowById(id);
            if(r) dwLeave(r, true); else delete visStart[id];
        });
    }
    function flush(useBeacon){
        while(impQueue.length || dwQueue.length){
            var body = 'action=impressions&ids=' + encodeURIComponent(impQueue.splice(0,50).join(','));
            if(dwQueue.length) body += '&dw=' + encodeURIComponent(dwQueue.splice(0,50).join(','));
            try{
                if(useBeacon && navigator.sendBeacon) navigator.sendBeacon('search.php', new Blob([body], {type:'application/x-www-form-urlencoded'}));
                else fetch('search.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body, keepalive:true}).catch(function(){});
            }catch(err){}
        }
    }
    var obs = new IntersectionObserver(function(es){
        es.forEach(function(e){
            visRatio[e.target.dataset.id] = e.intersectionRatio;
            impVisible(e.target, e.isIntersecting && e.intersectionRatio >= .6);
        });
    }, {threshold:[0,.6,1]});
    window.spObserveImps = function(){
        document.querySelectorAll('#postList .post-row').forEach(function(r){ obs.observe(r); });
    };
    setInterval(function(){ flush(false); }, 5000);
    document.addEventListener('visibilitychange', function(){
        if(document.hidden){ dwFinalizeAll(); flush(true); } else window.spDwResume();
    });
    window.addEventListener('pagehide', function(){ dwFinalizeAll(); flush(true); });
})();


/* the search bar stays fixed at the top (no hide-on-scroll), like the home page */


/* ═══════════ SHARE MODAL ON TOP — whatever opens the share sheet gets the highest z-index on the page ═══════════ */
(function(){
    var TOP = '2147483647';
    function lift(){
        document.querySelectorAll('[id*="share" i],[class*="share" i]').forEach(function(el){
            if(el.tagName === 'BUTTON' || el.tagName === 'A' || el.tagName === 'I' || el.tagName === 'SVG') return;
            if(el.closest('.ipv-action-group,.post-stats')) return;                       /* the little share buttons on posts */
            var cs = getComputedStyle(el);
            if(cs.position === 'fixed' || cs.position === 'absolute') el.style.setProperty('z-index', TOP, 'important');
        });
    }
    function wrap(){
        var orig = window.openShareModal;
        if(typeof orig !== 'function' || orig._spTop) return;
        var w = function(){
            var r = orig.apply(this, arguments);
            lift(); setTimeout(lift, 60); setTimeout(lift, 300);
            return r;
        };
        w._spTop = true;
        window.openShareModal = w;
    }
    wrap();
    /* the sheet may be created later by another script — lift it the moment it is added to the page */
    new MutationObserver(function(muts){
        for(var i=0;i<muts.length;i++){
            var added = muts[i].addedNodes;
            for(var j=0;j<added.length;j++){
                var n = added[j];
                if(n.nodeType === 1 && ((n.id||'') + ' ' + (n.className && n.className.baseVal === undefined ? n.className : '')).toLowerCase().indexOf('share') > -1){ lift(); return; }
            }
        }
    }).observe(document.body, {childList:true, subtree:false});
})();


/* ═══════════ POST MENU ON TOP — the "⋯" options sheet gets a very high z-index too (just under the share sheet) ═══════════ */
(function(){
    var MENU = '2147483637', BACK = '2147483636';
    var HIT  = /(index[-_]?sheet|index[-_]?menu|index[-_]?overlay|index[-_]?backdrop|post[-_]?menu|post[-_]?sheet|kebab[-_]?(menu|sheet)|options[-_]?(sheet|menu))/i;
    var SCRIM = /(overlay|backdrop|scrim|mask|dim)/i;
    function tag(el){ return ((el.id || '') + ' ' + (typeof el.className === 'string' ? el.className : '')); }
    function lift(){
        Array.prototype.forEach.call(document.body.children, function(el){
            if(el.nodeType !== 1 || el.tagName === 'SCRIPT' || el.tagName === 'STYLE') return;
            var t = tag(el);
            if(/share/i.test(t) || !HIT.test(t)) return;                    /* the share sheet keeps the very top */
            var cs = getComputedStyle(el);
            if(cs.position !== 'fixed' && cs.position !== 'absolute') return;
            el.style.setProperty('z-index', SCRIM.test(t) && !/sheet|menu/i.test(t.replace(SCRIM, '')) ? BACK : MENU, 'important');
            Array.prototype.forEach.call(el.querySelectorAll('[class]'), function(c){      /* a sheet inside an overlay wrapper */
                var ct = tag(c);
                if(/share/i.test(ct) || !HIT.test(ct)) return;
                var ccs = getComputedStyle(c);
                if(ccs.position === 'fixed' || ccs.position === 'absolute') c.style.setProperty('z-index', SCRIM.test(ct) ? BACK : MENU, 'important');
            });
        });
    }
    function wrap(){
        var orig = window.openIndexSheet;
        if(typeof orig !== 'function' || orig._mnTop) return;
        var w = function(){
            var r = orig.apply(this, arguments);
            lift(); setTimeout(lift, 60); setTimeout(lift, 300);
            return r;
        };
        w._mnTop = true;
        window.openIndexSheet = w;
    }
    wrap(); setTimeout(wrap, 0); window.addEventListener('load', wrap);
    new MutationObserver(function(muts){
        for(var i = 0; i < muts.length; i++){
            var added = muts[i].addedNodes;
            for(var j = 0; j < added.length; j++){
                var n = added[j];
                if(n.nodeType === 1 && HIT.test(tag(n))){ lift(); setTimeout(lift, 60); wrap(); return; }
            }
        }
    }).observe(document.body, {childList:true, subtree:false});
})();


/* ═══════════ POST MENU HELPERS (called by shared_modals.php) ═══════════ */

/* NOT INTERESTED — the post is replaced, right in the feed, by a "Post hidden · Undo" row in the same spot.
   Tap Undo and the post comes back. Nothing is saved until you leave / refresh the page (or the page has been left alone
   for a minute), so Undo is free. The save is action=not_interested, which the server remembers: after a refresh the post
   is completely gone and never comes back in your feed. */
(function(){
    var COMMIT_MS = 60000, pending = {};      /* memeId → {rows, ph, timer} */

    function rowsFor(id){
        return Array.prototype.slice.call(document.querySelectorAll('#postList .sp-item[data-id="'+id+'"]'));
    }
    function dropFromFeeds(id){
        try{
            for(var i = peuraFeed.length - 1; i >= 0; i--){ if(String(peuraFeed[i].id) === String(id)) peuraFeed.splice(i, 1); }
            if(typeof ccmFeed !== 'undefined' && Array.isArray(ccmFeed)){
                for(var j = ccmFeed.length - 1; j >= 0; j--){
                    var it = ccmFeed[j];
                    if(it && it.type === 'post' && it.data && String(it.data.id) === String(id)) ccmFeed.splice(j, 1);
                }
            }
        }catch(e){}
    }
    function send(id, beacon){
        var body = 'action=not_interested&meme_id=' + encodeURIComponent(id);
        try{
            if(beacon && navigator.sendBeacon) navigator.sendBeacon('search.php', new Blob([body], {type:'application/x-www-form-urlencoded'}));
            else fetch('search.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body, keepalive:true, credentials:'same-origin'}).catch(function(){});
        }catch(e){}
    }
    function makePlaceholder(row, id){
        var ph = document.createElement('div');
        ph.className = 'sp-item sp-nint-ph';
        ph.dataset.ph = id;
        ph.innerHTML = '<div class="sp-nint-body"><b>Post hidden</b><span>You won\u2019t see this post again.</span></div>'+
            '<button type="button" class="sp-nint-undo" onclick="event.stopPropagation();spUndoNotInterested('+id+')">Undo</button>';
        return ph;
    }
    /* the hiding is final: save it, drop the Undo button, and remove the post for good */
    function commit(id, beacon){
        var p = pending[id]; if(!p) return;
        clearTimeout(p.timer); delete pending[id];
        send(id, beacon);
        p.rows.forEach(function(r){ if(r.parentNode) r.parentNode.removeChild(r); });
        if(p.ph){
            var u = p.ph.querySelector('.sp-nint-undo'); if(u) u.remove();
            p.ph.classList.add('sp-nint-done');
            var sp = p.ph.querySelector('.sp-nint-body span'); if(sp) sp.textContent = 'Removed from your feed.';
        }
        dropFromFeeds(id);
        if(window.spEnsureSentinel) spEnsureSentinel();
    }

    window.notInterested = function(memeId, el){
        var id = String(memeId);
        if(pending[id]) return;
        var rows = rowsFor(id);
        if(!rows.length){ send(id, false); dropFromFeeds(id); return; }   /* not in the list (e.g. opened from the viewer) → just save it */
        var ph = makePlaceholder(rows[0], id);
        rows[0].parentNode.insertBefore(ph, rows[0]);
        rows.forEach(function(r){
            r.dataset.nint = '1'; r.style.display = 'none';
            r.querySelectorAll('video').forEach(function(v){ try{ v.pause(); }catch(e){} });
        });
        pending[id] = { rows: rows, ph: ph, timer: setTimeout(function(){ commit(id, false); }, COMMIT_MS) };
    };
    window.spUndoNotInterested = function(id){
        id = String(id);
        var p = pending[id]; if(!p) return;
        clearTimeout(p.timer); delete pending[id];
        if(p.ph && p.ph.parentNode) p.ph.parentNode.removeChild(p.ph);
        p.rows.forEach(function(r){ delete r.dataset.nint; r.style.display = ''; });
        if(window.watchVideos) watchVideos();
    };
    /* leaving, refreshing or switching away saves it, so it does not come back */
    function flushAll(){ Object.keys(pending).forEach(function(id){ commit(id, true); }); }
    window.addEventListener('pagehide', flushAll);
    document.addEventListener('visibilitychange', function(){ if(document.hidden) flushAll(); });
})();

/* BLOCK USER — hides everything from that person right away */
function blockUser(userId){
    if(!confirm("Block this user? You won't see their content anymore.")) return;
    fetch('search.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=block_user&target_id='+encodeURIComponent(userId), credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(!d || d.status !== 'ok'){ showToast('Could not block this user'); return; }
        document.querySelectorAll('#postList .sp-item[data-uploader="'+userId+'"]').forEach(function(p){
            p.style.transition = 'opacity .3s'; p.style.opacity = '0';
            setTimeout(function(){ if(p.parentNode) p.parentNode.removeChild(p); }, 300);
        });
        showToast('User blocked');
    })
    .catch(function(){ showToast('Something went wrong'); });
}

/* SHARE POST (from the ⋯ menu) */
function shareMeme(memeId){
    var base = location.origin + location.pathname.replace(/[^\/]*$/, '');
    var url = base + 'index.php?meme=' + memeId;
    if(navigator.share){ navigator.share({title:'Check this on Dezzme', url:url}).catch(function(){}); }
    else if(navigator.clipboard){ navigator.clipboard.writeText(url).then(function(){ showToast('Link copied!'); }); }
    else { showToast(url); }
}

/* ═══════════ GREEN DOT — keep "Active now" fresh every 60s (also acts as a heartbeat) ═══════════ */
(function(){
    function poll(){
        if(document.hidden) return;
        var wraps=document.querySelectorAll('.av-wrap[data-uid]'), seen={}, ids=[];
        wraps.forEach(function(w){ var id=w.dataset.uid; if(id && !seen[id] && ids.length<300){ seen[id]=1; ids.push(id); } });
        fetch('?type=online&ids='+ids.join(',')).then(function(r){ return r.json(); }).then(function(list){
            var on={}; (list||[]).forEach(function(i){ on[String(i)]=1; });
            wraps.forEach(function(w){
                var dot=w.querySelector('.on-dot'), isOn=!!on[w.dataset.uid];
                if(isOn && !dot) w.insertAdjacentHTML('beforeend','<span class="on-dot"></span>');
                else if(!isOn && dot) dot.remove();
            });
        }).catch(function(){});
    }
    setInterval(poll,60000);
    document.addEventListener('visibilitychange',function(){ if(!document.hidden) poll(); });
})();

/* (hashtag bar arrows + active-tag scroll now live in hashtags.php) */
</script>
<?php dz_hashtags_script(); /* shared hashtag bar script (hashtags.php) */ ?>
<?php include 'shared_modals.php'; /* post menu (⋯), Save to Collection and Report — shared with the other pages */ ?>
<script>
/* safety net for the ⋯ menu: if a post has no menu data yet (e.g. it arrived on a later page), build it from the feed so the sheet always opens */
(function(){
    var orig = window.openIndexSheet;
    if(typeof orig !== 'function') { console.warn('search.php: openIndexSheet not found — is shared_modals.php in the same folder?'); return; }
    window.openIndexSheet = function(memeId){
        try{
            window.kebabData = window.kebabData || {};
            if(!window.kebabData[memeId]){
                var p = (window.peuraFeed || []).find(function(x){ return String(x.id) === String(memeId); });
                if(p){
                    var f = (p.media && p.media[0] && p.media[0].file) ? p.media[0].file.replace(/^uploads\//,'') : '0';
                    window.kebabData[memeId] = { id:p.id, fileName:f, type:(p.media && p.media[0] && p.media[0].type) || p.type || 'text', uploadedBy:p.userId,
                        remixOf:null, caption:p.caption || '', isAd:!!p.isAd, posterPic:p.pic || '', posterUsername:p.username || '' };
                }
            }
            if(!window.kebabData[memeId]) console.warn('search.php: no menu data for post', memeId);
        }catch(e){ console.warn(e); }
        return orig.apply(this, arguments);
    };
})();
</script>
<script>
/* ═══════════ SWIPE: the page follows your finger, then opens the next page ═══════════
     • finger moves RIGHT → LEFT  →  community.php
     • finger moves LEFT → RIGHT  →  text_feed.php
   Same drag logic as text_feed.php.
   Starts only on a clearly sideways swipe; vertical scrolling, sideways scrollers (rows, tag bar),
   photo carousels that still have more slides in that direction, and any open sheet / modal are left alone. */
(function(){
    var URL_LEFT  = 'community.php';   /* swipe right → left */
    var URL_RIGHT = 'text_feed.php';   /* swipe left → right */
    var st = null, busy = false;
    function target(){ return document.querySelector('.page-shell'); }
    function blocked(t){
        if(document.body.style.overflow === 'hidden') return true;                         /* a modal / sheet is open */
        if(document.querySelector('.ipv-likers-overlay.ipv-open')) return true;
        if(t.closest && t.closest('input,textarea,select,[contenteditable]')) return true;
        for(var el = t; el && el !== document.body; el = el.parentElement){                /* any sideways scroller */
            if(el.scrollWidth > el.clientWidth + 2){
                var ox = getComputedStyle(el).overflowX;
                if(ox === 'auto' || ox === 'scroll') return true;
            }
        }
        return false;
    }
    /* a photo carousel that still has more slides in the swipe direction keeps the swipe for itself */
    function carouselWants(t, dir){
        var mb = t.closest ? t.closest('.ipv-media-wrap[data-count]') : null;
        if(!mb) return false;
        var c = parseInt(mb.dataset.count || '1', 10), i = parseInt(mb.dataset.activeIdx || '0', 10);
        if(c <= 1) return false;
        return dir < 0 ? (i < c - 1) : (i > 0);
    }
    function reset(el){
        el.style.transition = 'none'; el.style.transform = ''; el.style.opacity = ''; el.style.willChange = '';
        void el.offsetWidth; el.style.transition = '';
    }
    document.addEventListener('touchstart', function(e){
        st = null;
        if(busy || e.touches.length !== 1) return;
        var el = target(); if(!el || !el.contains(e.target)) return;
        if(blocked(e.target)) return;
        var t = e.touches[0];
        st = { x:t.clientX, y:t.clientY, el:el, tg:e.target, dir:0, on:false, dead:false, lx:t.clientX, lt:Date.now(), v:0, w:(el.getBoundingClientRect().width || window.innerWidth) };
    }, { capture:true, passive:true });

    document.addEventListener('touchmove', function(e){
        if(!st || st.dead) return;
        var t = e.touches[0], dx = t.clientX - st.x, dy = t.clientY - st.y;
        if(!st.on){
            if(Math.abs(dy) > 10 && Math.abs(dy) > Math.abs(dx) * 1.7){ st.dead = true; return; }   /* scrolling up / down */
            if(!(Math.abs(dx) > 8 && Math.abs(dy) <= Math.abs(dx) * 1.7)) return;                  /* wait for a clear sideways swipe */
            st.dir = dx < 0 ? -1 : 1;
            if(carouselWants(st.tg, st.dir)){ st.dead = true; return; }
            st.on = true;
            st.el.style.transition = 'none';
            st.el.style.willChange = 'transform';
        }
        e.stopPropagation();
        if(e.cancelable) e.preventDefault();
        var x = st.dir < 0 ? Math.min(0, dx) : Math.max(0, dx);
        st.el.style.transform = 'translate3d(' + x + 'px,0,0)';
        st.el.style.opacity = String(1 - Math.min(1, Math.abs(x) / (st.w * 1.2)) * 0.5);
        var now = Date.now(), dt = now - st.lt;
        if(dt > 0){ st.v = Math.abs(st.lx - t.clientX) / dt; st.lx = t.clientX; st.lt = now; }
    }, { capture:true, passive:false });

    function finish(e, cancelled){
        if(!st) return;
        var s = st; st = null;
        if(!s.on) return;
        if(e && e.stopPropagation) e.stopPropagation();
        var el = s.el, ct = e && e.changedTouches && e.changedTouches[0];
        var dist = ct ? Math.max(0, s.dir < 0 ? (s.x - ct.clientX) : (ct.clientX - s.x)) : 0;   /* how far it moved in the swipe direction */
        var go = !cancelled && (dist > s.w * 0.33 || (s.v > 0.5 && dist > 40));
        busy = true;
        if(go){
            el.style.transition = 'transform .24s cubic-bezier(.22,.8,.3,1), opacity .24s ease';
            el.style.transform = 'translate3d(' + (s.dir * s.w * 1.05) + 'px,0,0)';
            el.style.opacity = '0';
            var url = s.dir < 0 ? URL_LEFT : URL_RIGHT;
            setTimeout(function(){ location.href = url; }, 235);
            setTimeout(function(){ reset(el); busy = false; }, 1500);   /* safety: if the page did not leave */
        } else {
            el.style.transition = 'transform .26s cubic-bezier(.22,1,.36,1), opacity .26s ease';
            el.style.transform = 'translate3d(0,0,0)';
            el.style.opacity = '1';
            setTimeout(function(){ reset(el); busy = false; }, 280);
        }
    }
    document.addEventListener('touchend',    function(e){ finish(e, false); }, { capture:true, passive:true });
    document.addEventListener('touchcancel', function(e){ finish(e, true);  }, { capture:true, passive:true });
    /* coming back with the browser's back button: make sure the page is in place */
    window.addEventListener('pageshow', function(e){ var el = target(); if(el){ reset(el); } busy = false; });
})();
</script>
<?php if(is_file(__DIR__ . '/tv_nav.php')) include __DIR__ . '/tv_nav.php'; ?>
</body>
</html>