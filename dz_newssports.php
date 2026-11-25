<?php
/* Dezzme – news + sports strip feed for the Search page.
   GET dz_newssports.php  ->  {"news":[...],"sports":[...]}
   News   : public RSS feeds (no key).      Sports : ESPN public scoreboards (no key).
   Everything is cached on disk (news 10 min, sports 45 s) so the page never waits on other websites.
   If a source is down the strip simply shows what it has (or hides itself). */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

$NEWS = [
  ['BBC',        'https://feeds.bbci.co.uk/news/rss.xml'],
  ['BBC Tech',   'https://feeds.bbci.co.uk/news/technology/rss.xml'],
  ['BBC Sport',  'https://feeds.bbci.co.uk/sport/rss.xml'],
  ['Al Jazeera', 'https://www.aljazeera.com/xml/rss/all.xml'],
];
$SPORTS = [
  ['Premier League', 'soccer/eng.1'],
  ['Champions League','soccer/uefa.champions'],
  ['NBA', 'basketball/nba'],
  ['NFL', 'football/nfl'],
];

function dzns_cache($key, $ttl, $make){
    $f = sys_get_temp_dir().'/dzns_'.md5($key).'.json';
    if (is_file($f) && (time() - filemtime($f)) < $ttl) { $d = json_decode(@file_get_contents($f), true); if (is_array($d)) return $d; }
    $d = $make();
    if (is_array($d) && $d) { @file_put_contents($f, json_encode($d), LOCK_EX); return $d; }
    /* source failed: serve stale rather than nothing */
    if (is_file($f)) { $o = json_decode(@file_get_contents($f), true); if (is_array($o)) return $o; }
    return [];
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
    /* XAMPP on Windows often has no CA bundle, so every https call fails. These are public read-only feeds, so retry once without certificate checks. */
    if ($verify && !array_filter($out)) return dzns_multi($urls, false);
    return $out;
}
function dzns_url($u){ return (preg_match('#^https?://#i', $u)) ? $u : ''; }

$news = dzns_cache('news1', 600, function() use ($NEWS){
    $raw = dzns_multi(array_column($NEWS, 1)); $items = [];
    foreach ($NEWS as $i => $src) {
        if (empty($raw[$i])) continue;
        $prev = libxml_use_internal_errors(true);
        $x = @simplexml_load_string($raw[$i], 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if (!$x || !isset($x->channel->item)) continue;
        $n = 0;
        foreach ($x->channel->item as $it) {
            if ($n >= 6) break;
            $title = trim(html_entity_decode(strip_tags((string)$it->title), ENT_QUOTES, 'UTF-8'));
            $link  = dzns_url(trim((string)$it->link));
            if ($title === '' || $link === '') continue;
            $media = $it->children('http://search.yahoo.com/mrss/');
            $img = '';
            if (isset($media->thumbnail)) $img = (string)$media->thumbnail->attributes()['url'];
            elseif (isset($media->content))  $img = (string)$media->content->attributes()['url'];
            elseif (isset($it->enclosure))   $img = (string)$it->enclosure->attributes()['url'];
            $img = dzns_url($img);
            $img = preg_replace('#/standard/\d+/#', '/standard/480/', $img);   /* BBC: bigger thumbnail */
            $items[] = ['t'=>mb_substr($title,0,140), 'u'=>$link, 'i'=>$img, 's'=>$src[0], 'ts'=>(int)strtotime((string)$it->pubDate),
                        'k'=>(stripos($src[0],'Sport')!==false?'sport':(stripos($src[0],'Tech')!==false?'tech':'news'))];
            $n++;
        }
    }
    usort($items, function($a,$b){ return $b['ts'] <=> $a['ts']; });
    /* interleave sources so the strip isn't six BBC cards in a row */
    $by = []; foreach ($items as $it) $by[$it['s']][] = $it;
    $mix = []; $more = true;
    while ($more) { $more = false; foreach ($by as $s => &$list) { if ($list) { $mix[] = array_shift($list); $more = true; } } unset($list); }
    return array_slice($mix, 0, 16);
});

$sports = dzns_cache('sports1', 45, function() use ($SPORTS){
    $raw = dzns_multi(array_map(function($s){ return 'https://site.api.espn.com/apis/site/v2/sports/'.$s[1].'/scoreboard'; }, $SPORTS));
    $games = [];
    foreach ($SPORTS as $i => $lg) {
        $j = json_decode($raw[$i] ?? '', true);
        if (!is_array($j) || empty($j['events'])) continue;
        foreach ($j['events'] as $ev) {
            $c = $ev['competitions'][0] ?? null; if (!$c || count($c['competitors'] ?? []) < 2) continue;
            $home = $away = null;
            foreach ($c['competitors'] as $t) { if (($t['homeAway'] ?? '') === 'home') $home = $t; else $away = $t; }
            if (!$home || !$away) continue;
            $st = $ev['status']['type'] ?? [];
            $state = $st['state'] ?? 'pre';                       /* pre | in | post */
            $mk = function($t){ return ['n'=>$t['team']['shortDisplayName'] ?? ($t['team']['displayName'] ?? '?'), 'a'=>$t['team']['abbreviation'] ?? '',
                                        'l'=>dzns_url($t['team']['logo'] ?? ''), 's'=>($t['score'] ?? '')]; };
            $games[] = ['lg'=>$lg[0], 'st'=>$state, 'tx'=>($state==='in' ? ($ev['status']['displayClock'] ?? '').' '.($st['shortDetail'] ?? '') : ($st['shortDetail'] ?? '')),
                        'ts'=>(int)strtotime($ev['date'] ?? ''), 'h'=>$mk($home), 'a'=>$mk($away),
                        'u'=>dzns_url($ev['links'][0]['href'] ?? '')];
        }
    }
    /* live first, then upcoming (soonest), then finished (latest) */
    $rank = ['in'=>0, 'pre'=>1, 'post'=>2];
    usort($games, function($a,$b) use ($rank){ if ($a['st']!==$b['st']) return $rank[$a['st']] <=> $rank[$b['st']];
        return $a['st']==='post' ? $b['ts'] <=> $a['ts'] : $a['ts'] <=> $b['ts']; });
    return array_slice($games, 0, 16);
});

echo json_encode(['news'=>$news, 'sports'=>$sports], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);