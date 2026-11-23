<?php
/* ═══════════════════════════════════════════════════════════
   IMAGE PROXY HELPERS — runs BEFORE login/session so image requests are fast
   (images are served from YOUR domain, so hotlink rules / CSP / referrer
   problems on the publishers' side can no longer hide them)
═══════════════════════════════════════════════════════════ */
function nw_cache_dir(){
    $dir = __DIR__ . '/cache';
    if(!is_dir($dir)) @mkdir($dir, 0755, true);
    if(!is_dir($dir) || !is_writable($dir)){
        $dir = sys_get_temp_dir() . '/dezzme_news_cache';
        if(!is_dir($dir)) @mkdir($dir, 0755, true);
    }
    return $dir;
}

/* secret used to sign image links, so the proxy can only fetch URLs this page created */
function nw_secret(){
    static $s = null;
    if($s !== null) return $s;
    $f = nw_cache_dir() . '/.nw_secret';
    $s = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    if(strlen($s) < 32){
        $s = bin2hex(random_bytes(24));
        if(@file_put_contents($f, $s, LOCK_EX) === false) $s = md5(__FILE__ . php_uname());
    }
    return $s;
}

function nw_b64e($s){ return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function nw_b64d($s){ return base64_decode(strtr($s, '-_', '+/')); }

function nw_sig($url){ return substr(hash_hmac('sha256', $url, nw_secret()), 0, 24); }

function nw_img_url($url){
    if($url === '') return '';
    return 'News.php?img=' . nw_b64e($url) . '&sig=' . nw_sig($url);
}

/* is this address a public host (blocks localhost / private networks) */
function nw_public_host($url){
    $host = parse_url($url, PHP_URL_HOST);
    if(!$host) return false;
    $ips = @gethostbynamel($host);
    if(!$ips) return false;
    foreach($ips as $ip){
        if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

function nw_img_fail($code = 404){
    http_response_code($code);
    header('Cache-Control: no-store');
    exit;
}

if(isset($_GET['img'])){
    $url = (string)nw_b64d((string)$_GET['img']);
    $sig = (string)($_GET['sig'] ?? '');
    if($url === '' || !preg_match('#^https?://#i', $url) || !hash_equals(nw_sig($url), $sig)) nw_img_fail(403);

    $base = nw_cache_dir() . '/img_' . md5($url);
    $meta = $base . '.type';
    $bin  = $base . '.bin';

    /* cached copy (7 days) */
    if(is_file($bin) && is_file($meta) && (time() - filemtime($bin)) < 604800){
        $type = trim((string)@file_get_contents($meta));
        header('Content-Type: ' . $type);
        header('Cache-Control: public, max-age=604800');
        header('Content-Length: ' . filesize($bin));
        readfile($bin);
        exit;
    }

    if(!nw_public_host($url)) nw_img_fail(403);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; DezzmeNewsReader/1.0)',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER     => ['Accept: image/avif,image/webp,image/jpeg,image/png,image/*,*/*;q=0.8'],
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => function($r, $dlTotal, $dlNow){ return ($dlNow > 6 * 1024 * 1024) ? 1 : 0; },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    curl_close($ch);

    if($body === false || $code < 200 || $code >= 300 || strlen($body) < 100) nw_img_fail(404);

    /* octet-stream / missing type: detect it from the picture itself */
    if(strpos($type, 'image/') !== 0){
        $fi = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $type = $fi ? (string)finfo_buffer($fi, $body) : '';
    }
    if(strpos($type, 'image/') !== 0 || $type === 'image/svg+xml') nw_img_fail(404);

    @file_put_contents($bin, $body, LOCK_EX);
    @file_put_contents($meta, $type, LOCK_EX);

    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

/* ═══════════════════════════════════════════════════════════
   NORMAL PAGE
═══════════════════════════════════════════════════════════ */
session_start();
include 'config.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if(!$user_id){ header("Location: login.php"); exit(); }
/* feeds can take a few seconds to fetch: release the session lock so other pages don't wait on this one */
session_write_close();

/* ═══════════════════════════════════════════════════════════
   CATEGORIES + RSS FEEDS  (add / remove feeds here, nothing else to touch)
   Each category = label + a list of [source name, feed URL]
═══════════════════════════════════════════════════════════ */
$NW_CATS = [
    'top' => ['Top Stories', [
        ['BBC News',   'https://feeds.bbci.co.uk/news/rss.xml'],
        ['Al Jazeera', 'https://www.aljazeera.com/xml/rss/all.xml'],
        ['The Guardian','https://www.theguardian.com/international/rss'],
    ]],
    'world' => ['World', [
        ['BBC News',   'https://feeds.bbci.co.uk/news/world/rss.xml'],
        ['DW',         'https://rss.dw.com/xml/rss-en-all'],
        ['The Guardian','https://www.theguardian.com/world/rss'],
    ]],
    'africa' => ['Africa', [
        ['BBC News',   'https://feeds.bbci.co.uk/news/world/africa/rss.xml'],
        ['The Guardian','https://www.theguardian.com/world/africa/rss'],
    ]],
    'business' => ['Business', [
        ['BBC News',   'https://feeds.bbci.co.uk/news/business/rss.xml'],
        ['The Guardian','https://www.theguardian.com/uk/business/rss'],
    ]],
    'tech' => ['Tech', [
        ['BBC News',   'https://feeds.bbci.co.uk/news/technology/rss.xml'],
        ['The Guardian','https://www.theguardian.com/technology/rss'],
    ]],
    'sports' => ['Sports', [
        ['BBC Sport',  'https://feeds.bbci.co.uk/sport/rss.xml'],
        ['The Guardian','https://www.theguardian.com/sport/rss'],
    ]],
    'entertainment' => ['Entertainment', [
        ['BBC News',   'https://feeds.bbci.co.uk/news/entertainment_and_arts/rss.xml'],
        ['The Guardian','https://www.theguardian.com/culture/rss'],
    ]],
    'science' => ['Science', [
        ['BBC News',   'https://feeds.bbci.co.uk/news/science_and_environment/rss.xml'],
        ['The Guardian','https://www.theguardian.com/science/rss'],
    ]],
    'health' => ['Health', [
        ['BBC News',   'https://feeds.bbci.co.uk/news/health/rss.xml'],
        ['The Guardian','https://www.theguardian.com/society/health/rss'],
    ]],
];

const NW_CACHE_TTL   = 1200;   // seconds a category is cached (20 min) — feeds are NOT called on every visit
const NW_MAX_ITEMS   = 48;     // stories kept per category
const NW_LATEST_SHOW = 12;     // "Latest" rows visible before the Load more button

/* ═══════════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════════ */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function nw_clean($s, $len = 0){
    $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if($len > 0 && mb_strlen($s) > $len) $s = rtrim(mb_substr($s, 0, $len - 1)) . '…';
    return $s;
}

function nw_safe_url($u){
    $u = trim((string)$u);
    if(strpos($u, '//') === 0) $u = 'https:' . $u;
    if(!preg_match('#^https?://#i', $u)) return '';
    return $u;
}

function nw_ago($ts){
    $d = time() - $ts;
    if($d < 60)        return 'Just now';
    if($d < 3600)      return floor($d / 60) . ' min ago';
    if($d < 86400)     { $n = floor($d / 3600);   return $n . ' hour' . ($n > 1 ? 's' : '') . ' ago'; }
    if($d < 2592000)   { $n = floor($d / 86400);  return $n . ' day'  . ($n > 1 ? 's' : '') . ' ago'; }
    if($d < 31536000)  { $n = floor($d / 2592000);return $n . ' month'. ($n > 1 ? 's' : '') . ' ago'; }
    $n = floor($d / 31536000); return $n . ' year' . ($n > 1 ? 's' : '') . ' ago';
}

/* download several feeds at the same time */
function nw_fetch_all(array $feeds){
    $mh = curl_multi_init();
    $handles = [];
    foreach($feeds as $i => $f){
        $ch = curl_init($f[1]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; DezzmeNewsReader/1.0)',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => ['Accept: application/rss+xml, application/xml, text/xml, */*'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if($running) curl_multi_select($mh, 1.0);
    } while($running && $status === CURLM_OK);

    $out = [];
    foreach($handles as $i => $ch){
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        if($code >= 200 && $code < 300 && $body) $out[$i] = $body;
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    return $out;
}

/* collect image candidates [url, width] from a <media:*> parent node */
function nw_media_candidates($media, array &$cands){
    foreach($media->thumbnail as $t){
        $u = nw_safe_url((string)$t['url']);
        if($u) $cands[] = [$u, (int)$t['width']];
    }
    foreach($media->content as $c){
        $u  = nw_safe_url((string)$c['url']);
        $ty = (string)$c['type'];
        $md = (string)$c['medium'];
        if($u && ($md === 'image' || strpos($ty, 'image') === 0 || preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $u))){
            $cands[] = [$u, (int)$c['width']];
        }
    }
}

/* turn one feed (RSS 2.0 / RDF / Atom) into a clean list of stories */
function nw_parse($xml, $source){
    $items = [];
    libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if(!$x) return $items;

    $nodes = [];
    if(isset($x->channel->item))  foreach($x->channel->item as $n) $nodes[] = $n;
    elseif(isset($x->item))       foreach($x->item as $n)          $nodes[] = $n;
    elseif(isset($x->entry))      foreach($x->entry as $n)         $nodes[] = $n;

    foreach($nodes as $it){
        $title = nw_clean($it->title, 220);
        if($title === '') continue;

        /* link (RSS = text, Atom = href) */
        $link = nw_safe_url((string)$it->link);
        if($link === '' && isset($it->link)){
            foreach($it->link as $l){
                $rel = (string)$l['rel'];
                if($rel === '' || $rel === 'alternate'){ $link = nw_safe_url((string)$l['href']); break; }
            }
        }
        if($link === '') continue;

        /* description + full content (used for the snippet and as an image fallback) */
        $desc = (string)($it->description ?? '');
        if($desc === '') $desc = (string)($it->summary ?? '');
        $content = (string)($it->children('http://purl.org/rss/1.0/modules/content/')->encoded ?? '');

        /* date */
        $d = (string)($it->pubDate ?? '');
        if($d === '') $d = (string)($it->children('http://purl.org/dc/elements/1.1/')->date ?? '');
        if($d === '') $d = (string)($it->published ?? '');
        if($d === '') $d = (string)($it->updated ?? '');
        $ts = $d !== '' ? (int)strtotime($d) : 0;
        if($ts <= 0 || $ts > time() + 3600) $ts = time();

        /* image: media:thumbnail / media:content / enclosure / first <img> in the text */
        $cands = [];
        $media = $it->children('http://search.yahoo.com/mrss/');
        nw_media_candidates($media, $cands);
        foreach($media->group as $g) nw_media_candidates($g, $cands);
        if(isset($it->enclosure)){
            $eu = nw_safe_url((string)$it->enclosure['url']);
            if($eu && strpos((string)$it->enclosure['type'], 'image') === 0) $cands[] = [$eu, 0];
        }
        if(!$cands){
            foreach([$content, $desc] as $html){
                if($html && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)){
                    $u = nw_safe_url(html_entity_decode($m[1]));
                    if($u){ $cands[] = [$u, 0]; break; }
                }
            }
        }
        $img = ''; $fb = ''; $bestW = -1;
        foreach($cands as $c){ if($c[1] > $bestW){ $bestW = $c[1]; $img = $c[0]; } }
        $img = str_replace('http://', 'https://', $img);
        /* BBC gives 240px thumbnails — ask for the bigger version, keep the original as fallback */
        if($img !== '' && strpos($img, 'ichef.bbci.co.uk') !== false && preg_match('#/(standard|news)/\d+/#', $img)){
            $fb  = $img;
            $img = preg_replace('#/(standard|news)/\d+/#', '/$1/800/', $img);
        }

        $items[] = [
            't'   => $title,
            'u'   => $link,
            's'   => nw_clean($desc, 150),
            'ts'  => $ts,
            'img' => $img,
            'fb'  => $fb,
            'src' => $source,
        ];
    }
    return $items;
}

/* get one category: fresh cache → use it · stale / missing → refetch · fetch failed → fall back to the stale copy */
function nw_get($cat, array $cats){
    $file = nw_cache_dir() . '/news_' . $cat . '.json';
    $have = is_file($file);
    $age  = $have ? time() - filemtime($file) : PHP_INT_MAX;

    if($have && $age < NW_CACHE_TTL){
        $j = json_decode(@file_get_contents($file), true);
        if(is_array($j) && !empty($j['items'])) return $j;
    }

    $feeds  = $cats[$cat][1];
    $bodies = nw_fetch_all($feeds);
    $items  = [];
    foreach($bodies as $i => $xml){
        foreach(nw_parse($xml, $feeds[$i][0]) as $row) $items[] = $row;
    }

    if($items){
        /* remove duplicate headlines, newest first */
        usort($items, function($a, $b){ return $b['ts'] <=> $a['ts']; });
        $seen = []; $uniq = [];
        foreach($items as $row){
            $k = md5(mb_strtolower($row['t']));
            if(isset($seen[$k])) continue;
            $seen[$k] = 1;
            $uniq[] = $row;
            if(count($uniq) >= NW_MAX_ITEMS) break;
        }
        $data = ['updated' => time(), 'items' => $uniq];
        $tmp = $file . '.' . getmypid() . '.tmp';
        if(@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) @rename($tmp, $file);
        return $data;
    }

    if($have){
        $j = json_decode(@file_get_contents($file), true);
        if(is_array($j) && !empty($j['items'])) return $j;
    }
    return ['updated' => 0, 'items' => []];
}

/* image block — the placeholder is always underneath, so a broken image just reveals it.
   Load order: our own proxy (big) → proxy (small) → publisher direct (big) → publisher direct (small) → placeholder */
function nw_media($it){
    $o = '<span class="nw-media"><span class="nw-ph"><i class="ri-newspaper-line"></i></span>';
    if($it['img'] !== ''){
        $chain = [];
        if($it['fb'] !== '') $chain[] = nw_img_url($it['fb']);
        $chain[] = $it['img'];
        if($it['fb'] !== '') $chain[] = $it['fb'];
        $chain = array_values(array_unique($chain));

        $o .= '<img src="' . h(nw_img_url($it['img'])) . '" data-fb="' . h(implode('|', $chain)) . '"'
            . ' loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="" onerror="nwImgErr(this)">';
    }
    return $o . '</span>';
}

/* ═══════════════════════════════════════════════════════════
   PAGE DATA
═══════════════════════════════════════════════════════════ */
$cat = $_GET['cat'] ?? 'top';
if(!isset($NW_CATS[$cat])) $cat = 'top';
$cat_label = $NW_CATS[$cat][0];

$data    = nw_get($cat, $NW_CATS);
$items   = $data['items'];
$updated = (int)$data['updated'];

$ticker = array_slice($items, 0, 8);
$pool   = $items;

/* main story = newest story that has a picture */
$hero = null;
foreach($pool as $k => $it){
    if($it['img'] !== ''){ $hero = $it; unset($pool[$k]); break; }
}
if(!$hero && $pool){ $hero = array_shift($pool); }
$pool = array_values($pool);

$trending = array_slice($pool, 0, 5);
$pool     = array_slice($pool, 5);

$featured = [];
foreach($pool as $k => $it){
    if($it['img'] !== '' && count($featured) < 4){ $featured[] = $it; unset($pool[$k]); }
}
$latest = array_values($pool);

function nw_tag($it, $cat, $cat_label){ return $cat === 'top' ? $it['src'] : $cat_label; }

$source_names = [];
foreach($NW_CATS[$cat][1] as $f) $source_names[] = $f[0];
$source_names = array_unique($source_names);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($cat_label); ?> News | Dezzme</title>
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a{ color:inherit; text-decoration:none; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --fg:       255,255,255;
    --ui-text:  #ffffff;
    --red:      #FF2D2D;
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(var(--fg),0.05);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.14);
    --text:     #ffffff;
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.62);
    --ph:       #14162e;

    --max-w:    1080px;
    --topbar-h: 54px;
    --pad:      clamp(10px, 3vw, 18px);
    --ctl-h:    clamp(32px, 8vw, 38px);
    --r:        5px;
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

/* ── centered lane + grey side lines ── */
.nw-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
    padding-bottom:72px;
}
@media (min-width:801px){
    .nw-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .nw-lane{ max-width:100%; } }

/* ══════════════ TOPBAR — X on the left, title on the right ══════════════ */
.nw-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg); background-attachment:fixed;
}
.nw-topbar::after{
    content:''; position:absolute; left:0; right:0; bottom:0;
    height:1px; background:rgba(var(--fg),0.14);
    pointer-events:none; z-index:2;
}
.nw-x{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.nw-x:hover{ opacity:1; color:var(--red); }
.nw-x svg{ width:22px; height:22px; display:block; }
.nw-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){ .nw-x{ width:30px; height:30px; } .nw-title{ font-size:.84rem; } }

/* ══════════════ CATEGORY TABS ══════════════ */
.nw-cats{
    position:sticky; top:var(--topbar-h); z-index:400;
    display:flex; overflow-x:auto;
    background:var(--bg); background-attachment:fixed;
    border-bottom:1px solid var(--line);
    scrollbar-width:none;
}
.nw-cats::-webkit-scrollbar{ display:none; }
.nw-cat{
    flex:0 0 auto;
    padding:clamp(11px, 3vw, 14px) clamp(12px, 3.4vw, 18px);
    font-size:clamp(.62rem, 2vw, .72rem); font-weight:600;
    text-transform:uppercase; letter-spacing:.06em;
    color:var(--muted); white-space:nowrap;
    border-bottom:2px solid transparent; margin-bottom:-1px;
    transition:color .2s, border-color .2s;
}
.nw-cat:hover{ color:var(--text); }
.nw-cat.active{ color:var(--text); border-bottom-color:var(--ui-text); }

/* ══════════════ SEARCH ══════════════ */
.nw-search-wrap{ padding:clamp(9px, 2.6vw, 12px) var(--pad); border-bottom:1px solid var(--line); }
.nw-search{ position:relative; width:100%; }
.nw-search i{
    position:absolute; left:12px; top:50%; transform:translateY(-50%);
    color:var(--muted); font-size:.95rem; pointer-events:none;
}
.nw-search input{
    width:100%; height:var(--ctl-h);
    background:var(--surface); border:1px solid var(--line2); border-radius:var(--r);
    padding:0 12px 0 34px; font-size:.76rem; color:var(--ui-text); outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.nw-search input::placeholder{ color:var(--muted); }
.nw-search input:focus{
    border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}

/* ══════════════ BREAKING TICKER ══════════════ */
.nw-ticker{
    display:flex; align-items:stretch; height:40px;
    border-bottom:1px solid var(--line); overflow:hidden;
}
.nw-ticker-label{
    flex-shrink:0; display:flex; align-items:center; gap:7px;
    padding:0 clamp(10px, 3vw, 16px);
    background:var(--red); color:#fff;
    font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em;
    position:relative; z-index:2;
}
.nw-ticker-label i{ font-size:.85rem; }
.nw-ticker-view{ flex:1; min-width:0; overflow:hidden; position:relative; }
.nw-ticker-track{
    display:flex; align-items:center; width:max-content; height:100%;
    animation:nwTick 70s linear infinite;
}
.nw-ticker-view:hover .nw-ticker-track{ animation-play-state:paused; }
@keyframes nwTick{ to{ transform:translateX(-50%); } }
.nw-ticker-track a{
    display:flex; align-items:center; gap:10px; padding:0 22px;
    font-size:.72rem; font-weight:500; color:var(--text); white-space:nowrap;
    transition:color .2s;
}
.nw-ticker-track a::before{ content:''; width:5px; height:5px; border-radius:50%; background:var(--red); flex-shrink:0; }
.nw-ticker-track a:hover{ color:var(--red); }
@media (prefers-reduced-motion:reduce){ .nw-ticker-track{ animation:none; } }

/* ══════════════ SECTION TITLE ══════════════ */
.nw-sec{
    display:flex; align-items:center; gap:10px;
    padding:clamp(16px, 4.4vw, 24px) var(--pad) 10px;
    font-size:clamp(.72rem, 2.3vw, .84rem); font-weight:700; color:var(--ui-text);
}
.nw-sec::before{ content:''; width:22px; height:3px; background:var(--red); border-radius:2px; flex-shrink:0; }
.nw-sec::after{ content:''; flex:1; height:1px; background:var(--line); }
.nw-sec.first{ padding-top:0; }

/* ══════════════ IMAGE BLOCK (shared) ══════════════ */
.nw-media{ position:relative; display:block; overflow:hidden; background:var(--ph); }
.nw-media img{ position:absolute; inset:0; z-index:1; width:100%; height:100%; object-fit:cover; display:block; transition:transform .5s ease; }
.nw-ph{
    position:absolute; inset:0; z-index:0; display:flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg, rgba(255,45,45,.14), rgba(var(--fg),.04));
    color:rgba(var(--fg),.3); font-size:1.6rem;
}
.nw-item:hover .nw-media img{ transform:scale(1.04); }
.nw-item.hidden{ display:none !important; }

.nw-tag{ font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--red); }
.nw-meta{ font-size:clamp(.58rem, 1.8vw, .66rem); color:var(--muted); display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.nw-meta i{ font-size:.78rem; }
.nw-clamp2, .nw-clamp3{ display:-webkit-box; -webkit-box-orient:vertical; overflow:hidden; }
.nw-clamp2{ -webkit-line-clamp:2; }
.nw-clamp3{ -webkit-line-clamp:3; }

/* ══════════════ HERO + TRENDING ══════════════ */
.nw-top{
    display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:18px;
    padding:clamp(14px, 4vw, 20px) var(--pad) 4px;
}
@media (max-width:900px){ .nw-top{ grid-template-columns:1fr; gap:6px; } }

.nw-hero{
    position:relative; display:block; border-radius:8px; overflow:hidden; color:#fff;
    min-height:clamp(250px, 54vw, 440px);
    border:1px solid var(--line);
}
.nw-hero .nw-media{ position:absolute; inset:0; }
.nw-hero::after{
    content:''; position:absolute; inset:0; pointer-events:none; z-index:1;
    background:linear-gradient(to top, rgba(2,3,20,.92) 0%, rgba(2,3,20,.55) 38%, rgba(2,3,20,0) 72%);
}
.nw-hero-body{
    position:absolute; left:0; right:0; bottom:0; z-index:2;
    padding:clamp(14px, 3.6vw, 24px);
    display:flex; flex-direction:column; gap:9px;
}
.nw-hero-tag{
    align-self:flex-start; background:var(--red); color:#fff;
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em;
    padding:4px 9px; border-radius:3px;
}
.nw-hero-title{ font-size:clamp(1.02rem, 3.6vw, 1.7rem); font-weight:700; line-height:1.3; letter-spacing:-.2px; }
.nw-hero-snip{ font-size:clamp(.66rem, 1.9vw, .78rem); color:rgba(255,255,255,.78); line-height:1.6; }
.nw-hero .nw-meta{ color:rgba(255,255,255,.7); }
@media (max-width:490px){ .nw-hero-snip{ display:none; } }

.nw-trend{ display:flex; flex-direction:column; min-width:0; }
.nw-trend .nw-sec{ padding:0 0 10px; }
@media (max-width:900px){ .nw-trend .nw-sec{ padding-top:clamp(16px, 4.4vw, 24px); } }
.nw-tr{
    display:flex; gap:12px; align-items:flex-start; padding:11px 0; border-top:1px solid var(--line);
    transition:background .18s;
}
.nw-tr:first-of-type{ border-top:none; padding-top:2px; }
.nw-tr .nw-media{ width:76px; height:62px; flex-shrink:0; border-radius:5px; }
.nw-tr .nw-ph{ font-size:1.1rem; }
.nw-num{
    position:absolute; left:0; bottom:0; z-index:3;
    min-width:20px; height:20px; padding:0 5px;
    display:flex; align-items:center; justify-content:center;
    background:var(--red); color:#fff; font-size:.62rem; font-weight:700; border-top-right-radius:5px;
}
.nw-tr-body{ flex:1; min-width:0; display:flex; flex-direction:column; gap:5px; }
.nw-tr-title{ font-size:clamp(.74rem, 2.3vw, .8rem); font-weight:600; line-height:1.45; color:var(--text); }
.nw-tr:hover .nw-tr-title, .nw-card:hover .nw-card-title, .nw-row:hover .nw-row-title{ color:var(--red); }
.nw-tr-title, .nw-card-title, .nw-row-title{ transition:color .2s; }

/* ══════════════ FEATURED GRID ══════════════ */
.nw-grid{
    display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:14px;
    padding:0 var(--pad);
}
@media (max-width:900px){ .nw-grid{ grid-template-columns:repeat(2, minmax(0,1fr)); } }
@media (max-width:400px){ .nw-grid{ grid-template-columns:1fr; } }
.nw-card{
    display:flex; flex-direction:column;
    background:var(--surface); border:1px solid var(--line); border-radius:var(--r); overflow:hidden;
    transition:border-color .2s, transform .2s;
}
.nw-card:hover{ border-color:var(--line2); transform:translateY(-2px); }
.nw-card .nw-media{ aspect-ratio:16/10; }
.nw-card-body{ padding:11px 12px 13px; display:flex; flex-direction:column; gap:7px; flex:1; }
.nw-card-title{ font-size:clamp(.74rem, 2.3vw, .82rem); font-weight:600; line-height:1.45; color:var(--text); }
.nw-card .nw-meta{ margin-top:auto; }

/* ══════════════ LATEST LIST ══════════════ */
.nw-list{
    display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px;
    padding:0 var(--pad);
}
@media (max-width:800px){ .nw-list{ grid-template-columns:1fr; } }
.nw-row{
    display:flex; gap:12px; align-items:stretch; padding:10px;
    background:var(--surface); border:1px solid var(--line); border-radius:var(--r);
    transition:border-color .2s;
}
.nw-row:hover{ border-color:var(--line2); }
.nw-row .nw-media{ width:clamp(92px, 26vw, 124px); aspect-ratio:4/3; flex-shrink:0; border-radius:4px; align-self:flex-start; }
.nw-row-body{ flex:1; min-width:0; display:flex; flex-direction:column; gap:6px; }
.nw-row-title{ font-size:clamp(.74rem, 2.3vw, .82rem); font-weight:600; line-height:1.45; color:var(--text); }
.nw-row-snip{ font-size:.66rem; color:var(--muted); line-height:1.55; }
@media (max-width:490px){ .nw-row-snip{ display:none; } }
.nw-row.more{ display:none; }
.nw-lane.searching .nw-row.more{ display:flex; }

.nw-more-wrap{ display:flex; justify-content:center; padding:18px var(--pad) 0; }
.nw-btn{
    display:flex; align-items:center; justify-content:center; gap:6px;
    height:clamp(30px, 8vw, 36px); min-width:160px; padding:0 22px;
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; border-radius:4px;
    color:var(--ui-text); font-size:.72rem; font-weight:600;
    transition:background .2s, transform .15s;
}
.nw-btn:hover{ background:rgba(var(--fg),0.2); }
.nw-btn:active{ transform:scale(.98); }
.nw-lane.searching .nw-more-wrap{ display:none; }

/* ══════════════ FOOTER NOTE ══════════════ */
.nw-foot{
    margin-top:clamp(22px, 6vw, 34px); padding:clamp(14px, 4vw, 20px) var(--pad) 0;
    border-top:1px solid var(--line);
    font-size:clamp(.58rem, 1.8vw, .66rem); color:var(--muted); line-height:1.7; text-align:center;
}
.nw-foot strong{ color:rgba(var(--fg),.7); font-weight:600; }

/* ══════════════ EMPTY / NO RESULTS ══════════════ */
.nw-empty{
    flex:1; min-height:52vh; padding:40px 24px;
    display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center;
}
.nw-empty-title{ font-size:clamp(.95rem, 3.4vw, 1.1rem); font-weight:700; color:var(--ui-text); }
.nw-empty-text{ margin-top:6px; max-width:340px; font-size:clamp(.66rem, 2.1vw, .76rem); color:var(--muted); line-height:1.7; }
.nw-empty .nw-btn{ margin-top:20px; text-decoration:none; }
.nw-nores{
    display:none; flex-direction:column; align-items:center; gap:8px;
    padding:40px 0 24px; text-align:center; color:var(--muted); font-size:.78rem;
}
.nw-nores i{ font-size:1.8rem; opacity:.35; }
.nw-nores.show{ display:flex; }

/* ══════════════ SKELETON ══════════════ */
#page-skeleton{
    position:fixed; inset:0; z-index:9999;
    background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center;
    opacity:1; transition:opacity .35s ease; pointer-events:none;
}
#page-skeleton.hide{ opacity:0; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; overflow:hidden; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; border-bottom:1px solid rgba(var(--fg),0.14); }
.sk-back{ width:20px; height:20px; }
.sk-ttl{ width:90px; height:14px; }
.sk-cats{ display:flex; gap:10px; padding:12px var(--pad); border-bottom:1px solid var(--line); overflow:hidden; }
.sk-cat{ width:70px; height:14px; flex-shrink:0; }
.sk-top{ display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:18px; padding:18px var(--pad); }
@media (max-width:900px){ .sk-top{ grid-template-columns:1fr; } .sk-side{ display:none !important; } }
.sk-hero{ height:clamp(250px, 54vw, 440px); border-radius:8px; }
.sk-side{ display:flex; flex-direction:column; gap:14px; }
.sk-tr{ display:flex; gap:12px; }
.sk-tr-img{ width:76px; height:62px; flex-shrink:0; }
.sk-tr-txt{ flex:1; display:flex; flex-direction:column; gap:8px; padding-top:4px; }
.sk-l1{ height:11px; width:100%; } .sk-l2{ height:11px; width:70%; }
.sk-row{ display:flex; gap:12px; padding:12px var(--pad); border-top:1px solid var(--line); }
.sk-row-img{ width:clamp(92px,26vw,124px); aspect-ratio:4/3; flex-shrink:0; }

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════ */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.04);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --ph:#eceef5;
}
/* text on top of photos stays white in both themes */
[data-theme="light"] .nw-hero, [data-theme="light"] .nw-ticker-label, [data-theme="light"] .nw-hero-tag, [data-theme="light"] .nw-num{ color:#fff; }
[data-theme="light"] .nw-hero .nw-meta{ color:rgba(255,255,255,.75); }
</style>
</head>
<body>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar"><div class="sk sk-back"></div><div class="sk sk-ttl"></div></div>
        <div class="sk-cats">
            <?php for($i=0;$i<7;$i++): ?><div class="sk sk-cat"></div><?php endfor; ?>
        </div>
        <div class="sk-top">
            <div class="sk sk-hero"></div>
            <div class="sk-side">
                <?php for($i=0;$i<4;$i++): ?>
                <div class="sk-tr"><div class="sk sk-tr-img"></div><div class="sk-tr-txt"><div class="sk sk-l1"></div><div class="sk sk-l2"></div></div></div>
                <?php endfor; ?>
            </div>
        </div>
        <?php for($i=0;$i<3;$i++): ?>
        <div class="sk-row"><div class="sk sk-row-img"></div><div class="sk-tr-txt"><div class="sk sk-l1"></div><div class="sk sk-l1"></div><div class="sk sk-l2"></div></div></div>
        <?php endfor; ?>
    </div>
</div>

<div class="nw-lane" id="nwLane">

    <!-- ══ TOPBAR ══ -->
    <div class="nw-topbar">
        <button type="button" class="nw-x" onclick="nwClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="nw-title">News</span>
    </div>

    <!-- ══ CATEGORY TABS ══ -->
    <nav class="nw-cats" id="nwCats" aria-label="News categories">
        <?php foreach($NW_CATS as $key => $c): ?>
        <a class="nw-cat<?php echo $key === $cat ? ' active' : ''; ?>" href="News.php?cat=<?php echo h($key); ?>"><?php echo h($c[0]); ?></a>
        <?php endforeach; ?>
    </nav>

<?php if(!$hero): ?>

    <!-- ══ EMPTY ══ -->
    <div class="nw-empty">
        <div class="nw-empty-title">News is taking a break</div>
        <p class="nw-empty-text">We couldn't reach the news sources just now. Give it a moment and try again.</p>
        <a class="nw-btn" href="News.php?cat=<?php echo h($cat); ?>">Try again</a>
    </div>

<?php else: ?>

    <!-- ══ SEARCH ══ -->
    <div class="nw-search-wrap">
        <div class="nw-search">
            <i class="ri-search-line"></i>
            <input type="text" id="nwSearch" placeholder="Search <?php echo h(strtolower($cat_label)); ?> headlines…" oninput="nwFilter(this)" autocomplete="off">
        </div>
    </div>

    <!-- ══ BREAKING TICKER ══ -->
    <div class="nw-ticker">
        <div class="nw-ticker-label"><i class="ri-flashlight-fill"></i> Breaking</div>
        <div class="nw-ticker-view">
            <div class="nw-ticker-track">
                <?php foreach($ticker as $t): ?>
                <a href="<?php echo h($t['u']); ?>" target="_blank" rel="noopener noreferrer"><?php echo h($t['t']); ?></a>
                <?php endforeach; ?>
                <?php foreach($ticker as $t): ?>
                <a href="<?php echo h($t['u']); ?>" target="_blank" rel="noopener noreferrer" aria-hidden="true" tabindex="-1"><?php echo h($t['t']); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ══ MAIN STORY + TRENDING ══ -->
    <div class="nw-top">
        <a class="nw-item nw-hero" data-t="<?php echo h(mb_strtolower($hero['t'])); ?>" href="<?php echo h($hero['u']); ?>" target="_blank" rel="noopener noreferrer">
            <?php echo nw_media($hero); ?>
            <div class="nw-hero-body">
                <span class="nw-hero-tag"><?php echo h(nw_tag($hero, $cat, $cat_label)); ?></span>
                <div class="nw-hero-title"><?php echo h($hero['t']); ?></div>
                <?php if($hero['s'] !== ''): ?><p class="nw-hero-snip nw-clamp2"><?php echo h($hero['s']); ?></p><?php endif; ?>
                <div class="nw-meta"><span><?php echo h($hero['src']); ?></span><span>·</span><span><?php echo h(nw_ago($hero['ts'])); ?></span></div>
            </div>
        </a>

        <?php if($trending): ?>
        <aside class="nw-trend">
            <div class="nw-sec">Trending Now</div>
            <?php foreach($trending as $n => $t): ?>
            <a class="nw-item nw-tr" data-t="<?php echo h(mb_strtolower($t['t'])); ?>" href="<?php echo h($t['u']); ?>" target="_blank" rel="noopener noreferrer">
                <?php
                $m = nw_media($t);
                echo preg_replace('#</span>$#', '<span class="nw-num">' . ($n + 1) . '</span></span>', $m);
                ?>
                <div class="nw-tr-body">
                    <div class="nw-tr-title nw-clamp3"><?php echo h($t['t']); ?></div>
                    <div class="nw-meta"><span><?php echo h($t['src']); ?></span><span>·</span><span><?php echo h(nw_ago($t['ts'])); ?></span></div>
                </div>
            </a>
            <?php endforeach; ?>
        </aside>
        <?php endif; ?>
    </div>

    <!-- ══ FEATURED ══ -->
    <?php if($featured): ?>
    <div class="nw-sec">Featured</div>
    <div class="nw-grid">
        <?php foreach($featured as $f): ?>
        <a class="nw-item nw-card" data-t="<?php echo h(mb_strtolower($f['t'])); ?>" href="<?php echo h($f['u']); ?>" target="_blank" rel="noopener noreferrer">
            <?php echo nw_media($f); ?>
            <div class="nw-card-body">
                <span class="nw-tag"><?php echo h(nw_tag($f, $cat, $cat_label)); ?></span>
                <div class="nw-card-title nw-clamp3"><?php echo h($f['t']); ?></div>
                <div class="nw-meta"><span><?php echo h($f['src']); ?></span><span>·</span><span><?php echo h(nw_ago($f['ts'])); ?></span></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══ LATEST ══ -->
    <?php if($latest): ?>
    <div class="nw-sec">Latest</div>
    <div class="nw-list">
        <?php foreach($latest as $n => $l): ?>
        <a class="nw-item nw-row<?php echo $n >= NW_LATEST_SHOW ? ' more' : ''; ?>" data-t="<?php echo h(mb_strtolower($l['t'])); ?>" href="<?php echo h($l['u']); ?>" target="_blank" rel="noopener noreferrer">
            <?php echo nw_media($l); ?>
            <div class="nw-row-body">
                <span class="nw-tag"><?php echo h(nw_tag($l, $cat, $cat_label)); ?></span>
                <div class="nw-row-title nw-clamp3"><?php echo h($l['t']); ?></div>
                <?php if($l['s'] !== ''): ?><div class="nw-row-snip nw-clamp2"><?php echo h($l['s']); ?></div><?php endif; ?>
                <div class="nw-meta"><span><?php echo h($l['src']); ?></span><span>·</span><span><?php echo h(nw_ago($l['ts'])); ?></span></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php if(count($latest) > NW_LATEST_SHOW): ?>
    <div class="nw-more-wrap"><button type="button" class="nw-btn" id="nwMore" onclick="nwLoadMore()">Load more</button></div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="nw-nores" id="nwNoRes"><i class="ri-search-line"></i><span>No headlines found</span></div>

    <!-- ══ FOOTER NOTE ══ -->
    <div class="nw-foot">
        Headlines from <strong><?php echo h(implode(', ', $source_names)); ?></strong><?php echo $updated ? ' · Updated ' . h(nw_ago($updated)) : ''; ?>.<br>
        Stories, images and headlines belong to their publishers — tapping one opens the original article.
    </div>

<?php endif; ?>

</div><!-- /.nw-lane -->

<script>
// ── keep the theme in sync if it is switched in another tab ──
window.addEventListener('storage', function(e){
    if(e.key !== 'dz_theme') return;
    if(e.newValue === 'light') document.documentElement.setAttribute('data-theme','light');
    else document.documentElement.removeAttribute('data-theme');
});

// ── X: go back, or home if there is no history ──
function nwClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'home.php';
}

// ── Skeleton ──
(function(){
    var sk = document.getElementById('page-skeleton');
    if(!sk) return;
    var done = false;
    function hide(){
        if(done) return; done = true;
        sk.classList.add('hide');
        setTimeout(function(){ if(sk.parentNode) sk.remove(); }, 380);
    }
    window.addEventListener('load', function(){ setTimeout(hide, 280); });
    setTimeout(hide, 1500);   // safety net
})();

// ── bring the active category tab into view ──
(function(){
    var bar = document.getElementById('nwCats');
    var act = bar && bar.querySelector('.active');
    if(act) bar.scrollLeft = act.offsetLeft - (bar.clientWidth - act.offsetWidth) / 2;
})();

// ── broken picture: walk through the fallback list (data-fb, "|" separated), then show the placeholder ──
function nwImgErr(img){
    var list = (img.getAttribute('data-fb') || '').split('|').filter(Boolean);
    if(list.length){
        var next = list.shift();
        img.setAttribute('data-fb', list.join('|'));
        img.src = next;
        return;
    }
    img.remove();
}

// ── Load more ──
function nwLoadMore(){
    var hidden = document.querySelectorAll('.nw-row.more');
    for(var i = 0; i < hidden.length && i < 10; i++) hidden[i].classList.remove('more');
    if(!document.querySelector('.nw-row.more')){
        var b = document.getElementById('nwMore');
        if(b) b.parentNode.style.display = 'none';
    }
}

// ── Search (filters the headlines on screen) ──
function nwFilter(input){
    var q = input.value.trim().toLowerCase();
    var lane = document.getElementById('nwLane');
    lane.classList.toggle('searching', q.length > 0);
    var vis = 0;
    document.querySelectorAll('.nw-item').forEach(function(el){
        var ok = el.dataset.t.indexOf(q) > -1;
        el.classList.toggle('hidden', !ok);
        if(ok) vis++;
    });
    var nr = document.getElementById('nwNoRes');
    if(nr) nr.classList.toggle('show', q.length > 0 && vis === 0);
}
</script>
</body>
</html>