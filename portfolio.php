<?php
session_start();
include 'config.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ header("Location: login.php"); exit(); }

$profile_id = (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0) ? (int)$_GET['user_id'] : $current_user;
$is_own = ($current_user === $profile_id);

/* ══════════ CONSTANTS ══════════ */
const MAX_PROJECTS   = 12;
const MAX_PROJ_MEDIA = 10;
const MAX_PLINKS     = 5;
const MAX_LINKS      = 8;
const MAX_EXP        = 8;
const MAX_VIDEO_MB   = 40;
/* If your users table keeps the profile picture in a column this list doesn't know, put its name here */
const PF_USER_PIC_COL = '';
const PF_SECS       = ['header','about','skills','experience','work','links'];
const PF_HIDEABLE   = ['about','skills','experience','links'];

const PF_ACCENTS = [
    'red'=>['#FF2D2D','#ff1a1a','#ff8a8a'], 'orange'=>['#FF8A1F','#ef7a10','#ffbb7a'], 'gold'=>['#FFC21A','#eeb100','#ffe08a'],
    'green'=>['#2ED47A','#22bd69','#8cf0b9'], 'teal'=>['#19C6C0','#10b0aa','#7ee8e4'], 'sky'=>['#3BA3FF','#2a90ec','#8dc9ff'],
    'violet'=>['#A47BFF','#9065f0','#cdb6ff'], 'pink'=>['#FF5FA2','#f04b90','#ffa6cc'], 'darkblue'=>['#1a56c4','#2a68de','#7fa0ff'],
    'magenta'=>['#EE3DEB','#dc2cd9','#f7a0f5'], 'neon'=>['#C8FF2E','#b8ef1c','#e4ffa0'], 'sand'=>['#DDC297','#cdb083','#eedfc3'],
    'cocoa'=>['#C98C5C','#b87a4a','#e3bd9c'], 'silver'=>['#E5E7EB','#d1d5db','#f3f4f6'],
    'gb'=>['#0f380f','#0b2b0b','#306230','#9bbc0f'],
];
/* page + top bar colour: [name, dark gradient, dark solid, light gradient, light solid, text mode ('' = follows light/dark theme)] */
const PF_BGS = [
    'default'=>['Night',  'linear-gradient(-45deg,#010218,#070924,#070924)','#070924','linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb)','#ffffff',''],
    'black'  =>['Black',  'linear-gradient(-45deg,#000,#0b0b0b,#0b0b0b)','#0b0b0b','linear-gradient(-45deg,#ffffff,#f2f2f2,#f2f2f2)','#ffffff',''],
    'slate'  =>['Slate',  'linear-gradient(-45deg,#0f172a,#1e293b,#1e293b)','#1e293b','linear-gradient(-45deg,#f8fafc,#e2e8f0,#e2e8f0)','#f8fafc',''],
    'forest' =>['Forest', 'linear-gradient(-45deg,#02140b,#06261a,#06261a)','#06261a','linear-gradient(-45deg,#f4fcf7,#dff3e7,#dff3e7)','#f4fcf7',''],
    'wine'   =>['Wine',   'linear-gradient(-45deg,#1a0309,#2d0813,#2d0813)','#2d0813','linear-gradient(-45deg,#fff5f6,#f8dde2,#f8dde2)','#fff5f6',''],
    'ocean'  =>['Ocean',  'linear-gradient(-45deg,#021426,#052a4a,#052a4a)','#052a4a','linear-gradient(-45deg,#f3f9fe,#d9eafa,#d9eafa)','#f3f9fe',''],
    'plum'   =>['Plum',   'linear-gradient(-45deg,#14061f,#26093d,#26093d)','#26093d','linear-gradient(-45deg,#f9f3fe,#ead9fa,#ead9fa)','#f9f3fe',''],
    'ember'  =>['Ember',  'linear-gradient(-45deg,#1a0c02,#331704,#331704)','#331704','linear-gradient(-45deg,#fff8f0,#fbe6d0,#fbe6d0)','#fff8f0',''],
    'paper'  =>['Paper',  '#fffbe6','#fffbe6','#fffbe6','#fffbe6','dark'],
    'classic'=>['Classic grey','#c0c0c0','#c0c0c0','#c0c0c0','#c0c0c0','dark'],
    'sky'    =>['Sky blue','#9ecbff','#9ecbff','#9ecbff','#9ecbff','dark'],
    'navy'   =>['Navy',   '#000080','#000080','#000080','#000080','light'],
    'cyber'  =>['Cyber black','#000000','#000000','#000000','#000000','light'],
    'gameboy'=>['Game Boy','#9bbc0f','#9bbc0f','#9bbc0f','#9bbc0f','dark'],
    'arcade' =>['Arcade','#14082b','#14082b','#14082b','#14082b','light'],
    'pixsky' =>['Pixel sky','#5c94fc','#5c94fc','#5c94fc','#5c94fc','dark'],
];
const PF_DEF = ['tpl'=>'stack','era'=>'modern','font'=>'sans','space'=>'normal','nsz'=>'md','bh'=>'normal','avpos'=>'auto','avshape'=>'circle','avsize'=>'md',
    'head'=>'plain','proj'=>'list','skl'=>'cols','exp'=>'timeline','lnk'=>'rows','corners'=>'soft','anim'=>'rise','hover'=>'lift','amb'=>'none','pat'=>'none',
    'cd'=>'soft','wd'=>'auto','bt'=>'default','hc'=>'normal','hf'=>'same','fz'=>'md','dv'=>'none','cv'=>'none','al'=>'center','txm'=>'auto','cdm'=>'auto','px'=>'none'];
const PF_OPTS = [
    'tpl'=>['stack','hero','side','edit','bento','resume','split','arcade'], 'era'=>['modern','retro','pixel'], 'font'=>['sans','grotesk','serif','mono','rounded','pixel'],
    'space'=>['tight','normal','airy'], 'nsz'=>['md','lg','xl'], 'bh'=>['short','normal','tall'], 'avpos'=>['auto','left','center','right'],
    'avshape'=>['circle','rounded','square'], 'avsize'=>['sm','md','lg'], 'head'=>['plain','caps','line','bar','big','pixel'],
    'proj'=>['list','grid','grid3','rows','masonry','index','carousel'], 'skl'=>['cols','list','text','big','boxes'], 'exp'=>['timeline','table','cards'],
    'lnk'=>['rows','text','icons'], 'corners'=>['soft','square'], 'anim'=>['none','fade','rise','slide','zoom','blur','flip','pop','drop','wipe'],
    'hover'=>['none','lift','glow','grow','tilt','shine','tilt3d','spot'], 'amb'=>['none','pan','float','aurora','marquee','pulse','stars','scan'],
    'pat'=>['none','dots','grid','diag','check','lines','cross','bigdots','pixgrid'],
    'cd'=>['soft','flat','outline','glass','shadow','none','pixel'], 'wd'=>['auto','narrow','wide','full'], 'bt'=>['default','pill','square','outline'], 'hc'=>['normal','upper','lower'],
    'hf'=>['same','serif','mono','grotesk','rounded','pixel'], 'fz'=>['sm','md','lg'], 'dv'=>['none','line','dashed','dots','thick'], 'cv'=>['none','dim','mono','tint','vivid'],
    'al'=>['center','left'], 'txm'=>['auto','custom'], 'cdm'=>['auto','custom'], 'px'=>['none','fine','chunky'],
];
const PF_PFX = ['tpl'=>'tpl-','era'=>'era-','font'=>'ft-','space'=>'sp-','nsz'=>'nsz-','bh'=>'lk-bh-','avpos'=>'lk-av-','avshape'=>'lk-avsh-','avsize'=>'lk-avs-',
    'head'=>'lk-hd-','proj'=>'lk-pj-','skl'=>'lk-sk-','exp'=>'lk-ex-','lnk'=>'lk-ln-','anim'=>'an-','hover'=>'hv-','amb'=>'am-','pat'=>'pt-',
    'cd'=>'cd-','wd'=>'wd-','bt'=>'bt-','hc'=>'hc-','hf'=>'hf-','fz'=>'fz-','dv'=>'dv-','cv'=>'cv-','al'=>'al-','txm'=>'txm-','cdm'=>'cdm-','px'=>'px-'];

/* ══════════ HELPERS ══════════ */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function safe_url($u){ return preg_match('~^https?://~i', (string)$u) ? $u : ''; }
function clean_url($u){ $u = trim((string)$u); return (preg_match('~^https?://~i', $u) && filter_var($u, FILTER_VALIDATE_URL)) ? mb_substr($u, 0, 255) : ''; }
function pf_hex($v){ return preg_match('/^#[0-9a-f]{6}$/i', (string)$v) ? strtolower($v) : ''; }
function pf_rgba($hex, $a){ $hex = ltrim($hex, '#'); return 'rgba(' . hexdec(substr($hex,0,2)) . ',' . hexdec(substr($hex,2,2)) . ',' . hexdec(substr($hex,4,2)) . ',' . $a . ')'; }
function pf_lum($hex){
    $hex = ltrim($hex, '#'); $f = function($c){ $c /= 255; return $c <= .03928 ? $c / 12.92 : pow(($c + .055) / 1.055, 2.4); };
    return .2126 * $f(hexdec(substr($hex,0,2))) + .7152 * $f(hexdec(substr($hex,2,2))) + .0722 * $f(hexdec(substr($hex,4,2)));
}
function pf_mix($hex, $to, $amt){
    $hex = ltrim($hex, '#'); $o = '#';
    for($i = 0; $i < 3; $i++){ $c = hexdec(substr($hex, $i*2, 2)); $o .= sprintf('%02x', (int)round($c + ($to - $c) * $amt)); }
    return $o;
}
function pf_acc_preset($key){ $a = PF_ACCENTS[$key]; if(count($a) < 4) $a[] = '#000'; return $a; }
/* accent: preset key or a custom #hex → [main, hover, light, text-on-accent] */
function pf_accent($key){
    if(isset(PF_ACCENTS[$key])) return pf_acc_preset($key);
    $x = pf_hex($key);
    if(!$x) return pf_acc_preset('red');
    return [$x, pf_mix($x, 0, .12), pf_mix($x, 255, .45), pf_lum($x) > .45 ? '#000' : '#fff'];
}
function pf_bg($key){
    if(isset(PF_BGS[$key])) return PF_BGS[$key];
    $x = pf_hex($key);
    if($x) return ['Custom', $x, $x, $x, $x, pf_lum($x) > .4 ? 'dark' : 'light'];
    return PF_BGS['default'];
}
function pf_has_col($c, $t, $col){
    $st = $c->prepare("SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->bind_param('ss', $t, $col); $st->execute();
    return (int)$st->get_result()->fetch_assoc()['n'] > 0;
}
/* the picture from the user's normal profile — the portfolio falls back to it and can always go back to it */
function pf_user_pic($c, $uid){
    if(!isset($_SESSION['pf_pic_cols'])){
        $cands = PF_USER_PIC_COL !== '' ? [PF_USER_PIC_COL] : ['profile_pic','profile_picture','profile_image','profile_photo','avatar','pfp','photo','picture','image','img'];
        $found = [];
        foreach($cands as $col){ if(preg_match('/^[a-z_]+$/i', $col) && pf_has_col($c, 'users', $col)) $found[] = $col; }
        $_SESSION['pf_pic_cols'] = $found;
    }
    foreach($_SESSION['pf_pic_cols'] as $col){
        $st = $c->prepare("SELECT `$col` AS v FROM users WHERE id=? LIMIT 1");
        $st->bind_param('i', $uid); $st->execute();
        $r = $st->get_result()->fetch_assoc(); $v = trim((string)($r['v'] ?? ''));
        if($v !== '') return $v;
    }
    return '';
}
function pf_upload(array $f, &$err){
    if($f['error'] !== UPLOAD_ERR_OK){ $err = 'Upload failed. The file may be too large for the server.'; return ''; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
    if(!$ext || $f['size'] > 5*1024*1024 || !@getimagesize($f['tmp_name'])){ $err = 'Images must be JPG, PNG or WebP under 5 MB.'; return ''; }
    $dir = __DIR__ . '/uploads/portfolio/';
    if(!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if(!move_uploaded_file($f['tmp_name'], $dir . $name)){ $err = 'Image upload failed. Try again.'; return ''; }
    return 'uploads/portfolio/' . $name;
}
/* project media: photos (JPG/PNG/WebP, 5 MB) or short videos (MP4/WebM) */
function pf_upload_media(array $f, &$err, &$kind){
    if($f['error'] !== UPLOAD_ERR_OK){ $err = 'Upload failed. The file may be too large for the server (videos up to ' . MAX_VIDEO_MB . ' MB).'; return ''; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $imgs = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']; $vids = ['video/mp4'=>'mp4','video/webm'=>'webm'];
    if(isset($imgs[$mime])){
        $kind = 'image'; $ext = $imgs[$mime];
        if($f['size'] > 5*1024*1024 || !@getimagesize($f['tmp_name'])){ $err = 'Photos must be JPG, PNG or WebP under 5 MB.'; return ''; }
    } elseif(isset($vids[$mime])){
        $kind = 'video'; $ext = $vids[$mime];
        if($f['size'] > MAX_VIDEO_MB*1024*1024){ $err = 'Videos must be under ' . MAX_VIDEO_MB . ' MB.'; return ''; }
    } else { $err = 'Use JPG, PNG, WebP, MP4 or WebM files.'; return ''; }
    $dir = __DIR__ . '/uploads/portfolio/';
    if(!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if(!move_uploaded_file($f['tmp_name'], $dir . $name)){ $err = 'Upload failed. Try again.'; return ''; }
    return 'uploads/portfolio/' . $name;
}
function pf_unlink($p){ if($p && strpos($p, 'uploads/portfolio/') === 0 && strpos($p, '..') === false) @unlink(__DIR__ . '/' . $p); }
function pf_files($key){
    $out = [];
    if(empty($_FILES[$key]) || !is_array($_FILES[$key]['name'])) return $out;
    foreach($_FILES[$key]['name'] as $i => $n){
        if($_FILES[$key]['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name'=>$n,'tmp_name'=>$_FILES[$key]['tmp_name'][$i],'error'=>$_FILES[$key]['error'][$i],'size'=>$_FILES[$key]['size'][$i]];
    }
    return $out;
}
/* YouTube / Vimeo links → safe embed address (+ thumbnail when one exists) */
function pf_embed($u){
    $u = trim((string)$u);
    if(!preg_match('~^https?://~i', $u)) return null;
    $p = parse_url($u); $host = strtolower(preg_replace('~^(www\.|m\.)~', '', (string)($p['host'] ?? ''))); $id = '';
    if($host === 'youtu.be') $id = trim((string)($p['path'] ?? ''), '/');
    elseif($host === 'youtube.com'){
        parse_str((string)($p['query'] ?? ''), $q); $id = (string)($q['v'] ?? '');
        if($id === '' && preg_match('~^/(embed|shorts|live)/([\w-]{6,15})~', (string)($p['path'] ?? ''), $m)) $id = $m[2];
    } elseif($host === 'vimeo.com'){
        if(preg_match('~/(\d{5,12})~', (string)($p['path'] ?? ''), $m)) return ['src'=>'https://player.vimeo.com/video/' . $m[1], 'thumb'=>''];
        return null;
    } else return null;
    if(!preg_match('/^[\w-]{6,15}$/', $id)) return null;
    return ['src'=>'https://www.youtube-nocookie.com/embed/' . $id, 'thumb'=>'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg'];
}
function pf_look_norm($raw){
    $d = PF_DEF + ['avpx'=>'','bhpx'=>'','avo'=>'','nspx'=>'','avbd'=>'','txc'=>'#e8eaf6','cdc'=>'#1b1f4a','order'=>PF_SECS, 'hidden'=>[], 'banner'=>1, 'avshow'=>1];
    $a = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    foreach(PF_OPTS as $k => $ok){ if(isset($a[$k]) && in_array($a[$k], $ok, true)) $d[$k] = $a[$k]; }
    $d['txc'] = pf_hex($a['txc'] ?? '') ?: '#e8eaf6'; $d['cdc'] = pf_hex($a['cdc'] ?? '') ?: '#1b1f4a';
    foreach(['avpx'=>[30,320],'bhpx'=>[0,600],'avo'=>[-60,160],'nspx'=>[12,90],'avbd'=>[0,14]] as $fk => $rg){
        $d[$fk] = (isset($a[$fk]) && $a[$fk] !== '' && is_numeric($a[$fk])) ? max($rg[0], min($rg[1], (int)$a[$fk])) : '';
    }
    $d['banner'] = (isset($a['banner']) && !(int)$a['banner']) ? 0 : 1;
    $d['avshow'] = (isset($a['avshow']) && !(int)$a['avshow']) ? 0 : 1;
    $ord = [];
    foreach((array)($a['order'] ?? []) as $k){ if(in_array($k, PF_SECS, true) && !in_array($k, $ord, true)) $ord[] = $k; }
    foreach(PF_SECS as $k){ if(!in_array($k, $ord, true)) $ord[] = $k; }
    $hi = array_search('header', $ord, true);
    if($hi > 1){ array_splice($ord, $hi, 1); array_unshift($ord, 'header'); }
    $d['order']  = $ord;
    $d['hidden'] = array_values(array_intersect((array)($a['hidden'] ?? []), PF_HIDEABLE));
    return $d;
}
function pf_custom_style($look){
    $o = '';
    if($look['avpx'] !== '') $o .= '--avs:min(' . (int)$look['avpx'] . 'px,70vw);';
    if($look['bhpx'] !== '') $o .= '--bhpx:min(' . (int)$look['bhpx'] . 'px,70vh);';
    if($look['avo'] !== '')  $o .= '--avo:' . ((int)$look['avo'] / 100) . ';';
    if($look['nspx'] !== '') $o .= '--nspx:min(' . (int)$look['nspx'] . 'px,14vw);';
    if($look['avbd'] !== '') $o .= '--avbd:' . (int)$look['avbd'] . 'px;';
    if(($look['txm'] ?? '') === 'custom' && pf_hex($look['txc'])){
        $t = $look['txc']; $rgb = implode(',', sscanf($t, '#%02x%02x%02x'));
        $o .= "--text:$t;--ui-text:$t;--fg:$rgb;--muted:rgba($rgb,.55);--sub:rgba($rgb,.75);--surface:rgba($rgb,.06);--surface2:rgba($rgb,.1);--line:rgba($rgb,.14);--line2:rgba($rgb,.24);";
    }
    if(($look['cdm'] ?? '') === 'custom' && pf_hex($look['cdc'])){
        $c = $look['cdc']; $o .= "--surface:$c;--surface2:" . pf_mix($c, 255, .1) . ";";
    }
    return $o;
}
function pf_body_cls($look, $bgc, $has_pic){
    $c = [];
    foreach(PF_PFX as $k => $p) $c[] = $p . $look[$k];
    if($look['corners'] === 'square') $c[] = 'cr-square';
    if(!$look['banner']) $c[] = 'lk-nob';
    if(!$look['avshow']) $c[] = 'lk-noav';
    foreach(['avpx'=>'fx-av','bhpx'=>'fx-bh','avo'=>'fx-ao','nspx'=>'fx-ns','avbd'=>'fx-bd'] as $fk => $fc) if($look[$fk] !== '') $c[] = $fc;
    if(!$has_pic) $c[] = 'no-pic';
    if(!empty($bgc[5])) $c[] = 'tx-' . $bgc[5];
    return implode(' ', $c);
}
function pf_links_norm($raw){
    $a = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    $o = [];
    foreach($a as $it){
        if(!is_array($it)) continue;
        $u = clean_url($it['u'] ?? '');
        if($u === '') continue;
        $l = mb_substr(trim((string)($it['l'] ?? '')), 0, 40);
        if($l === '') $l = preg_replace('~^www\.~i', '', (string)parse_url($u, PHP_URL_HOST));
        $o[] = ['l'=>$l, 'u'=>$u];
        if(count($o) >= MAX_LINKS) break;
    }
    return $o;
}
/* project links typed one per line:  "Label | https://…"  or just  "https://…" */
function pf_plinks_parse($raw){
    $o = [];
    foreach(preg_split('~\R~', (string)$raw) as $ln){
        $ln = trim($ln); if($ln === '') continue;
        $l = ''; $u = $ln;
        if(strpos($ln, '|') !== false){ $parts = array_map('trim', explode('|', $ln, 2)); $l = $parts[0]; $u = $parts[1]; }
        $u = clean_url($u); if($u === '') continue;
        $l = mb_substr($l, 0, 40);
        if($l === '') $l = preg_replace('~^www\.~i', '', (string)parse_url($u, PHP_URL_HOST));
        $o[] = ['l'=>$l, 'u'=>$u];
        if(count($o) >= MAX_PLINKS) break;
    }
    return $o;
}
function pf_exp_norm($raw){
    $a = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    $o = [];
    foreach($a as $it){
        if(!is_array($it)) continue;
        $r = mb_substr(trim((string)($it['r'] ?? '')), 0, 80);
        if($r === '') continue;
        $o[] = ['r'=>$r, 'o'=>mb_substr(trim((string)($it['o'] ?? '')), 0, 80), 'p'=>mb_substr(trim((string)($it['p'] ?? '')), 0, 40), 't'=>mb_substr(trim((string)($it['t'] ?? '')), 0, 400)];
        if(count($o) >= MAX_EXP) break;
    }
    return $o;
}
function pf_link_icon($u){
    $host = strtolower((string)parse_url($u, PHP_URL_HOST));
    $map = ['instagram'=>'ri-instagram-line','github'=>'ri-github-line','linkedin'=>'ri-linkedin-box-line','twitter'=>'ri-twitter-x-line','x.com'=>'ri-twitter-x-line',
            'youtube'=>'ri-youtube-line','tiktok'=>'ri-tiktok-line','behance'=>'ri-behance-line','dribbble'=>'ri-dribbble-line','facebook'=>'ri-facebook-circle-line',
            'wa.me'=>'ri-whatsapp-line','whatsapp'=>'ri-whatsapp-line','spotify'=>'ri-spotify-line','twitch'=>'ri-twitch-line','pinterest'=>'ri-pinterest-line','medium'=>'ri-medium-line'];
    foreach($map as $k => $ic) if(strpos($host, $k) !== false) return $ic;
    return 'ri-link';
}
function pf_seg($name, $opts, $cur){
    $o = '<div class="seg">';
    foreach($opts as $v => $l) $o .= '<label><input type="radio" name="' . $name . '" value="' . h($v) . '"' . ((string)$v === (string)$cur ? ' checked' : '') . '><span>' . h($l) . '</span></label>';
    return $o . '</div>';
}

if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* ══════════ TABLES (upgraded once per session) ══════════ */
if(empty($_SESSION['pf_schema_v4'])){
    try {
        $add = ['banner'=>"VARCHAR(255) NULL", 'avatar'=>"VARCHAR(255) NULL", 'accent'=>"VARCHAR(12) NOT NULL DEFAULT 'red'", 'bg'=>"VARCHAR(12) NOT NULL DEFAULT 'default'",
                'look'=>"TEXT NULL", 'links'=>"TEXT NULL", 'experience'=>"TEXT NULL", 'pname'=>"VARCHAR(60) NOT NULL DEFAULT ''",
                'location'=>"VARCHAR(120) NOT NULL DEFAULT ''", 'status'=>"VARCHAR(60) NOT NULL DEFAULT ''"];
        foreach($add as $col => $def) if(!pf_has_col($conn, 'portfolios', $col)) $conn->query("ALTER TABLE portfolios ADD COLUMN $col $def");
        $addp = ['p_role'=>"VARCHAR(80) NOT NULL DEFAULT ''", 'p_year'=>"VARCHAR(20) NOT NULL DEFAULT ''", 'p_client'=>"VARCHAR(80) NOT NULL DEFAULT ''",
                 'p_tools'=>"VARCHAR(200) NOT NULL DEFAULT ''", 'story'=>"TEXT NULL", 'plinks'=>"TEXT NULL"];
        foreach($addp as $col => $def) if(!pf_has_col($conn, 'portfolio_projects', $col)) $conn->query("ALTER TABLE portfolio_projects ADD COLUMN $col $def");
        $conn->query("CREATE TABLE IF NOT EXISTS portfolio_project_images (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, project_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            image VARCHAR(255) NOT NULL, sort_order INT NOT NULL DEFAULT 0, INDEX idx_p (project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if(!pf_has_col($conn, 'portfolio_project_images', 'kind')) $conn->query("ALTER TABLE portfolio_project_images ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'image'");
        $_SESSION['pf_schema_v4'] = 1;
    } catch (Throwable $e) {}
}

/* ══════════ EDIT ACTIONS (own portfolio only) — post → redirect → overlay reopens with a message ══════════ */
if($_SERVER['REQUEST_METHOD'] === 'POST' && $is_own){
    if(!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')){ http_response_code(403); exit('Invalid request.'); }
    $action = $_POST['action'] ?? '';
    $ok = ''; $bad = ''; $tab = 'details';

    /* hands-on Arrange mode: saves only the layout bits (order, hidden sections, sizes) */
    if($action === 'save_layout'){
        $st = $conn->prepare("SELECT look FROM portfolios WHERE user_id=? LIMIT 1");
        $st->bind_param('i', $current_user); $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if(!$row){ $bad = 'Save your portfolio details first.'; }
        else {
            $base = json_decode((string)$row['look'], true) ?: [];
            $in = json_decode((string)($_POST['layout'] ?? ''), true) ?: [];
            foreach(['order','hidden','avpx','bhpx','avo','nspx','avbd'] as $k){ if(array_key_exists($k, $in)) $base[$k] = $in[$k]; }
            $lookJ = json_encode(pf_look_norm($base));
            $st = $conn->prepare("UPDATE portfolios SET look=? WHERE user_id=?");
            $st->bind_param('si', $lookJ, $current_user); $st->execute();
            $ok = 'Layout saved.';
        }
        if(isset($_POST['ajax'])){
            if($ok) $_SESSION['pf_flash'] = ['ok'=>$ok, 'bad'=>'', 'tab'=>'toast'];
            header('Content-Type: application/json');
            echo json_encode(['ok'=>$ok !== '', 'error'=>$bad]); exit();
        }
        $tab = 'design';
    }

    if($action === 'save_profile'){
        $tab = ($_POST['from_tab'] ?? '') === 'design' ? 'design' : 'details';
        $pname    = mb_substr(trim($_POST['pname'] ?? ''), 0, 60);
        $location = mb_substr(trim($_POST['location'] ?? ''), 0, 120);
        $status   = mb_substr(trim($_POST['status'] ?? ''), 0, 60);
        $headline = mb_substr(trim($_POST['headline'] ?? ''), 0, 120);
        $bio      = mb_substr(trim($_POST['bio'] ?? ''), 0, 2000);
        $skills_s = mb_substr(trim($_POST['skills'] ?? ''), 0, 300);
        $website  = clean_url($_POST['website'] ?? '');
        $email    = trim($_POST['contact_email'] ?? '');
        if($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $public   = isset($_POST['is_public']) ? 1 : 0;

        $accent = (string)($_POST['accent'] ?? 'red');
        if($accent === 'custom') $accent = pf_hex($_POST['accent_custom'] ?? '') ?: 'red';
        elseif(!isset(PF_ACCENTS[$accent])) $accent = 'red';
        $bg = (string)($_POST['bg'] ?? 'default');
        if($bg === 'custom') $bg = pf_hex($_POST['bg_custom'] ?? '') ?: 'default';
        elseif(!isset(PF_BGS[$bg])) $bg = 'default';

        $in = ['banner'=>$_POST['lk_banner'] ?? 1, 'avshow'=>$_POST['lk_avshow'] ?? 1,
               'order'=>explode(',', (string)($_POST['order'] ?? '')), 'hidden'=>explode(',', (string)($_POST['hidden'] ?? ''))];
        foreach(PF_OPTS as $k => $_) $in[$k] = $_POST['lk_' . $k] ?? '';
        foreach(['avpx','bhpx','avo','nspx','avbd'] as $fk) $in[$fk] = $_POST['lk_' . $fk] ?? '';
        $in['txc'] = $_POST['lk_txc'] ?? ''; $in['cdc'] = $_POST['lk_cdc'] ?? '';
        $look  = pf_look_norm($in);
        $links = pf_links_norm($_POST['links_json'] ?? '');
        $exp   = pf_exp_norm($_POST['exp_json'] ?? '');

        $st = $conn->prepare("SELECT banner, avatar FROM portfolios WHERE user_id=? LIMIT 1");
        $st->bind_param('i', $current_user); $st->execute();
        $cur = $st->get_result()->fetch_assoc() ?: ['banner'=>null, 'avatar'=>null];
        $banner = $cur['banner']; $avatar = $cur['avatar'];
        $newBanner = ''; $newAvatar = '';

        if(!empty($_FILES['banner']['name'])) $newBanner = pf_upload($_FILES['banner'], $bad);
        if($bad === '' && !empty($_FILES['avatar']['name'])) $newAvatar = pf_upload($_FILES['avatar'], $bad);

        if($bad !== ''){ pf_unlink($newBanner); pf_unlink($newAvatar); $tab = 'design'; }
        else {
            if($newBanner !== ''){ pf_unlink($banner); $banner = $newBanner; }
            elseif(!empty($_POST['remove_banner']) && $banner){ pf_unlink($banner); $banner = null; }
            if($newAvatar !== ''){ pf_unlink($avatar); $avatar = $newAvatar; }
            elseif(!empty($_POST['remove_avatar']) && $avatar){ pf_unlink($avatar); $avatar = null; }
            $lookJ = json_encode($look); $linksJ = json_encode($links); $expJ = json_encode($exp);
            $st = $conn->prepare("INSERT INTO portfolios (user_id, headline, bio, skills, website, contact_email, is_public, banner, avatar, accent, bg, look, links, experience, pname, location, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE headline=VALUES(headline), bio=VALUES(bio), skills=VALUES(skills),
                website=VALUES(website), contact_email=VALUES(contact_email), is_public=VALUES(is_public), banner=VALUES(banner), avatar=VALUES(avatar),
                accent=VALUES(accent), bg=VALUES(bg), look=VALUES(look), links=VALUES(links), experience=VALUES(experience),
                pname=VALUES(pname), location=VALUES(location), status=VALUES(status)");
            $st->bind_param('isssssi' . str_repeat('s', 10), $current_user, $headline, $bio, $skills_s, $website, $email, $public, $banner, $avatar, $accent, $bg, $lookJ, $linksJ, $expJ, $pname, $location, $status);
            $st->execute();
            $ok = 'Portfolio saved.';
        }
    }

    if($action === 'save_project'){
        $tab = 'projects';
        $pid     = (int)($_POST['project_id'] ?? 0);
        $title   = mb_substr(trim($_POST['title'] ?? ''), 0, 100);
        $desc    = mb_substr(trim($_POST['description'] ?? ''), 0, 600);
        $story   = mb_substr(trim($_POST['story'] ?? ''), 0, 6000);
        $prole   = mb_substr(trim($_POST['p_role'] ?? ''), 0, 80);
        $pyear   = mb_substr(trim($_POST['p_year'] ?? ''), 0, 20);
        $pclient = mb_substr(trim($_POST['p_client'] ?? ''), 0, 80);
        $ptools  = mb_substr(trim($_POST['p_tools'] ?? ''), 0, 200);
        $plinks  = pf_plinks_parse($_POST['plinks'] ?? '');
        $link    = $plinks[0]['u'] ?? ''; $plJ = json_encode($plinks);
        $embeds  = [];
        foreach(preg_split('~\R~', (string)($_POST['embeds'] ?? '')) as $ln){ $ln = clean_url($ln); if($ln !== '' && pf_embed($ln)) $embeds[] = $ln; }
        $rm      = array_map('strval', (array)($_POST['remove_img'] ?? []));
        $row     = null; $extras = []; $newFiles = pf_files('media'); $items = [];

        if($pid){
            $st = $conn->prepare("SELECT id, image FROM portfolio_projects WHERE id=? AND user_id=?");
            $st->bind_param('ii', $pid, $current_user); $st->execute();
            $row = $st->get_result()->fetch_assoc();
            if(!$row) $bad = 'Project not found.';
            else {
                $st = $conn->prepare("SELECT id, image, sort_order FROM portfolio_project_images WHERE project_id=? AND user_id=?");
                $st->bind_param('ii', $pid, $current_user); $st->execute();
                $extras = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        } else {
            $st = $conn->prepare("SELECT COUNT(*) c FROM portfolio_projects WHERE user_id=?");
            $st->bind_param('i', $current_user); $st->execute();
            if((int)$st->get_result()->fetch_assoc()['c'] >= MAX_PROJECTS) $bad = 'You can add up to ' . MAX_PROJECTS . ' projects. Delete one to add another.';
        }
        if($bad === '' && $title === '') $bad = 'Give your project a title.';
        if($bad === ''){
            $keep = 0;
            if($row && $row['image'] && !in_array('c', $rm, true)) $keep++;
            foreach($extras as $x) if(!in_array((string)$x['id'], $rm, true)) $keep++;
            if($keep + count($newFiles) + count($embeds) > MAX_PROJ_MEDIA) $bad = 'A project can have up to ' . MAX_PROJ_MEDIA . ' photos, videos and video links in total.';
        }
        if($bad === ''){
            foreach($newFiles as $f){ $k = ''; $p = pf_upload_media($f, $bad, $k); if($bad !== '') break; $items[] = ['p'=>$p, 'k'=>$k]; }
            if($bad !== '') foreach($items as $it) pf_unlink($it['p']);
        }
        if($bad === ''){
            foreach($embeds as $e) $items[] = ['p'=>$e, 'k'=>'embed'];
            $so = 0; foreach($extras as $x) $so = max($so, (int)$x['sort_order'] + 1);
            if($pid){
                $st = $conn->prepare("UPDATE portfolio_projects SET title=?, description=?, link=?, story=?, p_role=?, p_year=?, p_client=?, p_tools=?, plinks=? WHERE id=? AND user_id=?");
                $st->bind_param('sssssssssii', $title, $desc, $link, $story, $prole, $pyear, $pclient, $ptools, $plJ, $pid, $current_user); $st->execute();
                $coverEmpty = (!$row['image'] || in_array('c', $rm, true));
                if($row['image'] && in_array('c', $rm, true)){
                    pf_unlink($row['image']);
                    $st = $conn->prepare("UPDATE portfolio_projects SET image='' WHERE id=? AND user_id=?");
                    $st->bind_param('ii', $pid, $current_user); $st->execute();
                }
                foreach($extras as $x) if(in_array((string)$x['id'], $rm, true)){
                    pf_unlink($x['image']);
                    $st = $conn->prepare("DELETE FROM portfolio_project_images WHERE id=? AND user_id=?");
                    $xid = (int)$x['id']; $st->bind_param('ii', $xid, $current_user); $st->execute();
                }
                $ok = 'Project updated.';
            } else {
                $empty = '';
                $st = $conn->prepare("INSERT INTO portfolio_projects (user_id, title, description, link, image, story, p_role, p_year, p_client, p_tools, plinks) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $st->bind_param('issssssssss', $current_user, $title, $desc, $link, $empty, $story, $prole, $pyear, $pclient, $ptools, $plJ); $st->execute();
                $pid = (int)$conn->insert_id; $coverEmpty = true;
                $ok = 'Project added.';
            }
            foreach($items as $it){
                if($coverEmpty && $it['k'] === 'image'){
                    $st = $conn->prepare("UPDATE portfolio_projects SET image=? WHERE id=? AND user_id=?");
                    $st->bind_param('sii', $it['p'], $pid, $current_user); $st->execute(); $coverEmpty = false;
                } else {
                    $st = $conn->prepare("INSERT INTO portfolio_project_images (project_id, user_id, image, kind, sort_order) VALUES (?,?,?,?,?)");
                    $st->bind_param('iissi', $pid, $current_user, $it['p'], $it['k'], $so); $st->execute(); $so++;
                }
            }
        }
    }

    if($action === 'delete_project'){
        $tab = 'projects';
        $pid = (int)($_POST['project_id'] ?? 0);
        $st = $conn->prepare("SELECT image FROM portfolio_projects WHERE id=? AND user_id=?");
        $st->bind_param('ii', $pid, $current_user); $st->execute();
        if($row = $st->get_result()->fetch_assoc()){
            pf_unlink($row['image']);
            $st = $conn->prepare("SELECT image FROM portfolio_project_images WHERE project_id=? AND user_id=?");
            $st->bind_param('ii', $pid, $current_user); $st->execute();
            foreach($st->get_result()->fetch_all(MYSQLI_ASSOC) as $x) pf_unlink($x['image']);
            $st = $conn->prepare("DELETE FROM portfolio_project_images WHERE project_id=? AND user_id=?");
            $st->bind_param('ii', $pid, $current_user); $st->execute();
            $st = $conn->prepare("DELETE FROM portfolio_projects WHERE id=? AND user_id=?");
            $st->bind_param('ii', $pid, $current_user); $st->execute();
            $ok = 'Project deleted.';
        }
    }

    $_SESSION['pf_flash'] = ['ok'=>$ok, 'bad'=>$bad, 'tab'=>$tab];
    header("Location: portfolio.php"); exit();
}
$flash = $_SESSION['pf_flash'] ?? null; unset($_SESSION['pf_flash']);

/* ══════════ LOAD ══════════ */
$st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
$st->bind_param('i', $profile_id); $st->execute();
$user = $st->get_result()->fetch_assoc();
if(!$user){ header("Location: 404.php"); exit(); }

$st = $conn->prepare("SELECT * FROM portfolios WHERE user_id=? LIMIT 1");
$st->bind_param('i', $profile_id); $st->execute();
$pf = $st->get_result()->fetch_assoc();
$visible = $pf && ($pf['is_public'] || $is_own);
$can_arrange = $is_own && $pf;

$projects = [];
if($visible || $is_own){
    $st = $conn->prepare("SELECT id, title, description, link, image, p_role, p_year, p_client, p_tools, story, plinks FROM portfolio_projects WHERE user_id=? ORDER BY id DESC");
    $st->bind_param('i', $profile_id); $st->execute();
    $projects = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $extras = [];
    if($projects){
        $ids = implode(',', array_map('intval', array_column($projects, 'id')));
        $r = $conn->query("SELECT id, project_id, image, kind FROM portfolio_project_images WHERE project_id IN ($ids) ORDER BY sort_order, id");
        if($r) while($x = $r->fetch_assoc()) $extras[(int)$x['project_id']][] = $x;
    }
    foreach($projects as $k => $p){
        $g = [];
        if($p['image']) $g[] = ['k'=>'c', 'kind'=>'image', 'src'=>$p['image'], 'thumb'=>''];
        foreach($extras[(int)$p['id']] ?? [] as $x){
            $kind = in_array($x['kind'] ?? 'image', ['image','video','embed'], true) ? $x['kind'] : 'image';
            if($kind === 'embed'){ $e = pf_embed($x['image']); if(!$e) continue; $g[] = ['k'=>(string)$x['id'], 'kind'=>'embed', 'src'=>$e['src'], 'thumb'=>$e['thumb']]; }
            else $g[] = ['k'=>(string)$x['id'], 'kind'=>$kind, 'src'=>$x['image'], 'thumb'=>''];
        }
        $pl = pf_links_norm($p['plinks']);
        if(!$pl && clean_url($p['link'])) $pl = [['l'=>preg_replace('~^www\.~i', '', (string)parse_url($p['link'], PHP_URL_HOST)), 'u'=>$p['link']]];
        $projects[$k]['gal'] = $g; $projects[$k]['pl'] = $pl;
    }
}

$pf_form = $pf ?: ['pname'=>'','location'=>'','status'=>'','headline'=>'','bio'=>'','skills'=>'','website'=>'','contact_email'=>'','is_public'=>1,'banner'=>null,'avatar'=>null,'accent'=>'red','bg'=>'default','look'=>null,'links'=>null,'experience'=>null];
$accent_key = (isset(PF_ACCENTS[$pf_form['accent'] ?? '']) || pf_hex($pf_form['accent'] ?? '')) ? $pf_form['accent'] : 'red';
$bg_key     = (isset(PF_BGS[$pf_form['bg'] ?? '']) || pf_hex($pf_form['bg'] ?? '')) ? $pf_form['bg'] : 'default';
$acc_custom = !isset(PF_ACCENTS[$accent_key]); $bg_custom = !isset(PF_BGS[$bg_key]);
$look  = pf_look_norm($pf_form['look'] ?? null);
$links = pf_links_norm($pf_form['links'] ?? null);
$exps  = pf_exp_norm($pf_form['experience'] ?? null);
$ac = pf_accent($accent_key); $bgc = pf_bg($bg_key);

$skills = $pf ? array_values(array_filter(array_map('trim', explode(',', (string)$pf['skills'])))) : [];
$disp_raw = trim((string)($pf_form['pname'] ?? '')) !== '' ? $pf_form['pname'] : $user['username'];
$display_name = h($disp_raw);
$own_av   = (string)($pf_form['avatar'] ?? '');
$prof_pic = pf_user_pic($conn, $profile_id);
$av_src   = $own_av !== '' ? $own_av : $prof_pic;
$has_pic  = $av_src !== '';
$banner_url = (string)($pf_form['banner'] ?? '');
$GIF = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

$body_cls = pf_body_cls($look, $bgc, $has_pic);
$ordmap = array_flip($look['order']);
function pf_open($k, $look, $ordmap){ return '<div class="pf-s' . (in_array($k, $look['hidden'], true) ? ' off' : '') . '" data-k="' . $k . '" style="order:' . ((int)$ordmap[$k] + 1) . '">'; }

$accents_js = []; foreach(PF_ACCENTS as $k => $a) $accents_js[$k] = pf_acc_preset($k);
$pj = [];
foreach($projects as $p){
    $pj[] = ['id'=>(int)$p['id'], 't'=>$p['title'], 'd'=>$p['description'], 's'=>(string)$p['story'], 'r'=>$p['p_role'], 'y'=>$p['p_year'], 'c'=>$p['p_client'],
             'tools'=>array_values(array_filter(array_map('trim', explode(',', (string)$p['p_tools'])))), 'l'=>$p['pl'], 'g'=>$p['gal']];
}
$js = [
    'accents'=>$accents_js, 'bgs'=>PF_BGS, 'look'=>$look, 'links'=>$links, 'exp'=>$exps, 'pfx'=>PF_PFX,
    'maxLinks'=>MAX_LINKS, 'maxExp'=>MAX_EXP, 'pj'=>$pj, 'pid'=>$profile_id, 'csrf'=>$_SESSION['csrf'],
    'avSrc'=>$av_src, 'profPic'=>$prof_pic, 'banner'=>$banner_url, 'gif'=>$GIF,
    'names'=>['header'=>['Header','Name, picture and buttons'],'about'=>['About','Your bio'],'skills'=>['Skills',''],'experience'=>['Experience','Roles and timeline'],'work'=>['Work','Your projects'],'links'=>['Links','Your extra links']],
    'hideable'=>PF_HIDEABLE,
];

/* design controls: group → [key, label, options] */
$CTRL = [
 'Look and feel'=>[
  ['era','Aesthetic',['modern'=>'Slick 2020s','retro'=>'Web 1.0','pixel'=>'Pixel art']],
  ['px','Pixelate pictures',['none'=>'Off','fine'=>'Fine','chunky'=>'Chunky']],
  ['tpl','Page layout',['stack'=>'Stack','hero'=>'Big hero','side'=>'Sidebar','edit'=>'Editorial','bento'=>'Tiles','resume'=>'Résumé','split'=>'Split screen','arcade'=>'Arcade windows']],
  ['font','Typeface',['sans'=>'Modern','grotesk'=>'Techy','serif'=>'Serif','mono'=>'Mono','rounded'=>'Soft','pixel'=>'Pixel']],
  ['space','Spacing',['tight'=>'Tight','normal'=>'Normal','airy'=>'Airy']],
  ['corners','Corners',['soft'=>'Soft','square'=>'Square']],
  ['pat','Background pattern',['none'=>'None','dots'=>'Dots','grid'=>'Grid','diag'=>'Stripes','check'=>'Checks','lines'=>'Lines','cross'=>'Crosses','bigdots'=>'Big dots','pixgrid'=>'Pixel squares']],
 ],
 'Page and cards'=>[
  ['wd','Page width (computers)',['auto'=>'Auto','narrow'=>'Narrow','wide'=>'Wide','full'=>'Full']],
  ['fz','Text size',['sm'=>'Smaller','md'=>'Normal','lg'=>'Bigger']],
  ['cd','Card style',['soft'=>'Soft','flat'=>'Flat','outline'=>'Outlined','glass'=>'Glass','shadow'=>'Hard shadow','none'=>'No cards','pixel'=>'Pixel frame']],
  ['bt','Buttons',['default'=>'Default','pill'=>'Pill','square'=>'Square','outline'=>'Outline']],
  ['dv','Dividers between sections',['none'=>'None','line'=>'Line','dashed'=>'Dashed','dots'=>'Dots','thick'=>'Thick']],
  ['hf','Heading typeface',['same'=>'Same as text','serif'=>'Serif','mono'=>'Mono','grotesk'=>'Techy','rounded'=>'Soft','pixel'=>'Pixel']],
  ['hc','Heading letters',['normal'=>'As written','upper'=>'UPPERCASE','lower'=>'lowercase']],
  ['txm','Text colour',['auto'=>'Automatic','custom'=>'My colour']],
  ['@txc','',[]],
  ['cdm','Card colour',['auto'=>'Automatic','custom'=>'My colour']],
  ['@cdc','',[]],
 ],
 'Header'=>[
  ['al','Text alignment',['center'=>'Centre','left'=>'Left']],
  ['cv','Banner effect',['none'=>'None','dim'=>'Darken','mono'=>'Black and white','tint'=>'Colour tint','vivid'=>'Vivid']],
  ['banner','Banner',['1'=>'Show','0'=>'Hide']],
  ['bh','Banner height',['short'=>'Short','normal'=>'Normal','tall'=>'Tall']],
  ['avshow','Picture',['1'=>'Show','0'=>'Hide']],
  ['avpos','Picture position',['auto'=>'Auto','left'=>'Left','center'=>'Centre','right'=>'Right']],
  ['avshape','Picture shape',['circle'=>'Circle','rounded'=>'Rounded','square'=>'Square']],
  ['avsize','Picture size',['sm'=>'Small','md'=>'Medium','lg'=>'Large']],
  ['nsz','Name size',['md'=>'Normal','lg'=>'Large','xl'=>'Huge']],
 ],
 'Section styles'=>[
  ['head','Section titles',['plain'=>'Plain','caps'=>'Coloured','line'=>'Underlined','bar'=>'Side bar','big'=>'Big','pixel'=>'Pixel tag']],
  ['skl','Skills',['cols'=>'Two columns','list'=>'List','text'=>'Sentence','big'=>'Big words','boxes'=>'Boxes']],
  ['exp','Experience',['timeline'=>'Timeline','table'=>'Table','cards'=>'Cards']],
  ['lnk','Links',['rows'=>'Rows','text'=>'Plain text','icons'=>'Icons only']],
  ['proj','Projects',['list'=>'Big cards','grid'=>'2 columns','grid3'=>'3 columns','rows'=>'Rows','masonry'=>'Masonry','index'=>'Index','carousel'=>'Carousel']],
 ],
 'Motion'=>[
  ['anim','Entrance animation',['none'=>'None','fade'=>'Fade','rise'=>'Rise','slide'=>'Slide','zoom'=>'Zoom','blur'=>'Blur in','flip'=>'Flip','pop'=>'Pop','drop'=>'Drop','wipe'=>'Wipe']],
  ['hover'=>'x','hover','Hover effect',['none'=>'None','lift'=>'Lift','glow'=>'Glow','grow'=>'Grow','tilt'=>'Tilt','shine'=>'Shine','tilt3d'=>'3D tilt','spot'=>'Spotlight']],
  ['amb','Always moving',['none'=>'None','pan'=>'Slow banner pan','float'=>'Floating picture','aurora'=>'Moving background','marquee'=>'Scrolling headline','pulse'=>'Pulsing button','stars'=>'Twinkling stars','scan'=>'Retro scanlines']],
 ],
];
/* tidy the hover row (kept explicit so the option list is easy to read) */
$CTRL['Motion'][1] = ['hover','Hover effect',['none'=>'None','lift'=>'Lift','glow'=>'Glow','grow'=>'Grow','tilt'=>'Tilt','shine'=>'Shine','tilt3d'=>'3D tilt','spot'=>'Spotlight']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?php echo $display_name; ?> | Portfolio</title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Nunito:wght@400;600;700&family=Pixelify+Sans:wght@400;600;700&family=Playfair+Display:wght@400;600;700&family=Poppins:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;font-family:var(--font)}
button,a{cursor:pointer;outline:none;-webkit-tap-highlight-color:transparent}
a{color:inherit;text-decoration:none}
button,input,textarea,select{font-family:inherit}
/* ICON FIX: the rule above sets the text font on every ::before, and icon glyphs live in ::before — put the icon font back on them */
[class^="ri-"],[class*=" ri-"],[class^="ri-"]::before,[class*=" ri-"]::before,[class^="ri-"]::after,[class*=" ri-"]::after{
  font-family:'remixicon'!important;font-style:normal;font-weight:normal;line-height:1;speak:none;text-transform:none;-webkit-font-smoothing:antialiased}

:root{
  --font:'Poppins',sans-serif;
  --fg:255,255,255;--ui-text:#fff;--text:#fff;
  --ac:#FF2D2D;--ac-h:#ff1a1a;--ac-l:#ff8a8a;--ac-dim:rgba(255,45,45,.16);--ac-bd:rgba(255,45,45,.4);--ac-glow:rgba(255,45,45,.34);--on-ac:#000;
  --pbg:linear-gradient(-45deg,#010218,#070924,#070924);--solid:#070924;
  --surface:rgba(255,255,255,.05);--surface2:rgba(255,255,255,.08);--line:rgba(255,255,255,.09);--line2:rgba(255,255,255,.14);
  --muted:rgba(255,255,255,.45);--sub:rgba(255,255,255,.62);--ph:#1a1d3d;--ok:#4ade80;
  --max-w:580px;--topbar-h:54px;--pad:clamp(10px,3.3vw,17px);--r:5px;--card-r:14px;--avs:clamp(70px,21vw,92px);--gap:22px;
}
:root[data-theme="light"]{
  color-scheme:light;--fg:6,7,26;--ui-text:#0b0d24;--text:#0b0d24;
  --surface:rgba(6,7,26,.05);--surface2:rgba(6,7,26,.08);--line:rgba(6,7,26,.1);--line2:rgba(6,7,26,.2);
  --muted:rgba(6,7,26,.52);--sub:rgba(6,7,26,.66);--ph:#eceef5;--ok:#15a34a;
}
body.tx-dark{--fg:6,7,26;--ui-text:#0b0d24;--text:#0b0d24;--surface:rgba(6,7,26,.05);--surface2:rgba(6,7,26,.08);--line:rgba(6,7,26,.12);--line2:rgba(6,7,26,.22);--muted:rgba(6,7,26,.55);--sub:rgba(6,7,26,.7);--ph:#e5e7ee}
body.tx-light{--fg:255,255,255;--ui-text:#fff;--text:#fff;--surface:rgba(255,255,255,.06);--surface2:rgba(255,255,255,.1);--line:rgba(255,255,255,.12);--line2:rgba(255,255,255,.2);--muted:rgba(255,255,255,.5);--sub:rgba(255,255,255,.7);--ph:#1a1d3d}
/* the edit sheet keeps its own colours whatever the page looks like */
.sh-overlay{--fg:255,255,255;--ui-text:#fff;--text:#fff;--surface:rgba(255,255,255,.05);--surface2:rgba(255,255,255,.08);--line:rgba(255,255,255,.09);--line2:rgba(255,255,255,.14);--muted:rgba(255,255,255,.45);--sub:rgba(255,255,255,.62);--ph:#1a1d3d;--font:'Poppins',sans-serif;color:var(--ui-text)}
:root[data-theme="light"] .sh-overlay{--fg:6,7,26;--ui-text:#0b0d24;--text:#0b0d24;--surface:rgba(6,7,26,.05);--surface2:rgba(6,7,26,.08);--line:rgba(6,7,26,.1);--line2:rgba(6,7,26,.2);--muted:rgba(6,7,26,.52);--sub:rgba(6,7,26,.66);--ph:#eceef5}

body.ft-grotesk{--font:'Space Grotesk',sans-serif}
body.ft-serif{--font:'Playfair Display',Georgia,serif}
body.ft-mono{--font:'DM Mono',ui-monospace,monospace}
body.ft-rounded{--font:'Nunito',sans-serif}
body.ft-pixel{--font:'Pixelify Sans','Courier New',monospace}
body.cr-square{--card-r:3px;--r:2px}
body.sp-tight{--gap:12px}
body.sp-airy{--gap:44px}

html{scroll-behavior:smooth}
body{--pxb:rgba(var(--fg),.9);--pxs:0 -4px 0 0 var(--pxb),0 4px 0 0 var(--pxb),-4px 0 0 0 var(--pxb),4px 0 0 0 var(--pxb);
  background:var(--pbg);background-attachment:fixed;color:var(--text);min-height:100vh;overflow-x:hidden;-webkit-font-smoothing:antialiased}
body.pf-lock{overflow:hidden}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0}
body.pt-dots::before{background-image:radial-gradient(rgba(var(--fg),.16) 1px,transparent 1.6px);background-size:18px 18px}
body.pt-grid::before{background-image:linear-gradient(rgba(var(--fg),.07) 1px,transparent 1px),linear-gradient(90deg,rgba(var(--fg),.07) 1px,transparent 1px);background-size:28px 28px}
body.pt-diag::before{background-image:repeating-linear-gradient(45deg,rgba(var(--fg),.06) 0 2px,transparent 2px 13px)}
body.pt-check::before{background-image:repeating-conic-gradient(rgba(var(--fg),.06) 0 25%,transparent 0 50%);background-size:32px 32px}
body.pt-pixgrid::before{background-image:repeating-conic-gradient(rgba(var(--fg),.07) 0 25%,transparent 0 50%);background-size:16px 16px}
.lane{position:relative;z-index:1;width:100%;max-width:var(--max-w);margin:0 auto;min-height:calc(100vh - var(--topbar-h));padding-bottom:72px}
@media(min-width:801px){.lane{border-left:1px solid var(--line);border-right:1px solid var(--line)}}
body.tpl-hero{--max-w:860px}body.tpl-side{--max-w:1120px}body.tpl-edit{--max-w:900px}body.tpl-bento{--max-w:1000px}body.tpl-resume{--max-w:680px}body.tpl-split{--max-w:1240px}body.tpl-arcade{--max-w:980px}

/* topbar — follows the page colour */
.topbar{position:sticky;top:0;z-index:500;height:var(--topbar-h);background:var(--pbg);background-attachment:fixed}
.tb-in{display:flex;align-items:center;gap:6px;height:100%;max-width:var(--max-w);margin:0 auto;padding:0 10px;border-bottom:1px solid rgba(var(--fg),.14)}
@media(min-width:801px){.tb-in{border-left:1px solid var(--line);border-right:1px solid var(--line)}}
.tb-x{width:36px;height:36px;background:none;border:none;color:var(--ui-text);display:flex;align-items:center;justify-content:center;opacity:.9;transition:color .2s}
.tb-x:hover{color:var(--ac)}
.tb-x svg{width:22px;height:22px}
.tb-title{flex:1;min-width:0;font-size:.92rem;font-weight:700;color:var(--ui-text);text-align:right;margin:0 6px 0 10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tb-btn{height:30px;padding:0 12px;border:none;border-radius:var(--r);background:rgba(var(--fg),.1);color:var(--ui-text);font-size:.72rem;font-weight:600;display:inline-flex;align-items:center;gap:5px;transition:background .2s}
.tb-btn:hover{background:rgba(var(--fg),.2)}
.tb-btn i{font-size:14px}
.tb-btn.on{background:var(--ac);color:var(--on-ac)}
.pf-prog{position:absolute;left:0;bottom:-1px;height:3px;width:100%;transform-origin:left;transform:scaleX(0);background:var(--ac);box-shadow:0 0 10px var(--ac-glow);pointer-events:none}

.pill{height:32px;padding:0 14px;border-radius:var(--r);border:none;background:rgba(var(--fg),.1);color:var(--ui-text);font-size:.74rem;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:background .2s,box-shadow .2s,transform .25s}
.pill i{font-size:15px}
.pill:hover{background:rgba(var(--fg),.2)}
.pill.red{background:var(--ac);color:var(--on-ac)}
.pill.red:hover{background:var(--ac-h);box-shadow:0 4px 16px var(--ac-glow)}

.page{display:flex;flex-direction:column}
.pf-s{width:100%}
.pf-s.off{display:none}

/* ── header ── */
.pf-hd{position:relative}
.pf-banner{position:relative;width:100%;height:clamp(110px,31vw,160px);overflow:hidden;background:radial-gradient(120% 140% at 0% 0%,var(--ac-glow),transparent 58%),radial-gradient(90% 120% at 100% 100%,var(--ac-dim),transparent 62%),linear-gradient(135deg,#0d1030,#171b46);background-size:cover;background-position:center}
.pf-banner.has-img::after{content:'';position:absolute;inset:0;background:linear-gradient(to bottom,rgba(1,2,24,.05),rgba(7,9,36,.5))}
.pf-banner:not(.has-img)::before{content:'';position:absolute;inset:0;opacity:.5;background-image:radial-gradient(rgba(255,255,255,.12) 1px,transparent 1px);background-size:18px 18px;-webkit-mask-image:linear-gradient(to bottom,#000,transparent);mask-image:linear-gradient(to bottom,#000,transparent)}
.pf-banner-ic{position:absolute;left:14px;bottom:-8px;font-size:clamp(70px,22vw,110px);color:var(--ac);opacity:.16;transform:rotate(8deg)}
.pf-id{position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;text-align:center;gap:10px;padding:0 var(--pad);margin-top:calc(var(--avs) / -2)}
.pf-av-link{display:block;flex-shrink:0;line-height:0;border-radius:var(--avr,50%)}
.pf-av{width:var(--avs);height:var(--avs);border-radius:var(--avr,50%);object-fit:cover;border:3px solid var(--ac);background:var(--ph);box-shadow:0 0 0 4px var(--solid)}
.pf-idmain{min-width:0;max-width:100%}
.pf-name{font-size:clamp(1.05rem,3.8vw,1.25rem);font-weight:700;display:flex;align-items:center;gap:.3em;justify-content:center;max-width:100%;line-height:1.15}
.pf-name .nm{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pf-status{display:inline-flex;align-items:center;gap:7px;margin-top:8px;padding:4px 11px;border-radius:99px;border:1px solid var(--ac-bd);background:var(--ac-dim);font-size:.66rem;font-weight:600}
.pf-status::before{content:'';width:7px;height:7px;border-radius:50%;background:var(--ac);box-shadow:0 0 0 3px var(--ac-dim)}
.pf-headline{font-size:.86rem;color:var(--sub);margin-top:6px;line-height:1.5;word-break:break-word}
.pf-loc{font-size:.7rem;color:var(--muted);margin-top:6px;display:flex;align-items:center;gap:4px;justify-content:center}
.pf-loc i{color:var(--ac);font-size:13px}
.pf-actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;padding-top:14px}
.pf-actions:empty{display:none}

body.nsz-lg .pf-name{font-size:clamp(1.7rem,6vw,2.4rem)}
body.nsz-xl .pf-name{font-size:clamp(2.3rem,9vw,3.9rem);line-height:1.02;letter-spacing:-.02em}
body.nsz-lg .pf-name .nm,body.nsz-xl .pf-name .nm{white-space:normal;overflow:visible}
body.lk-avs-sm{--avs:clamp(54px,16vw,66px)}
body.lk-avs-lg{--avs:clamp(90px,28vw,120px)}
body.lk-avsh-rounded{--avr:22%}
body.lk-avsh-square{--avr:6px}
body.lk-bh-short .pf-banner{height:clamp(68px,20vw,100px)}
body.lk-bh-tall .pf-banner{height:clamp(170px,48vw,260px)}
body.lk-nob .pf-banner{display:none}
body.lk-nob .pf-id,body.lk-noav .pf-id,body.no-pic .pf-id{margin-top:0;padding-top:22px}
body.lk-noav .pf-av-link,body.no-pic .pf-av-link{display:none}
body.tpl-stack.lk-av-left .pf-id,body.tpl-stack.lk-av-right .pf-id,body.tpl-bento.lk-av-left .pf-id,body.tpl-arcade.lk-av-left .pf-id,body.tpl-bento.lk-av-right .pf-id,body.tpl-arcade.lk-av-right .pf-id{align-items:flex-start;text-align:left;gap:14px}
body.tpl-stack.lk-av-left .pf-id,body.tpl-bento.lk-av-left .pf-id,body.tpl-arcade.lk-av-left .pf-id{flex-direction:row}
body.tpl-stack.lk-av-right .pf-id,body.tpl-bento.lk-av-right .pf-id,body.tpl-arcade.lk-av-right .pf-id{flex-direction:row-reverse}
body.tpl-stack.lk-av-left .pf-idmain,body.tpl-stack.lk-av-right .pf-idmain,body.tpl-bento.lk-av-left .pf-idmain,body.tpl-arcade.lk-av-left .pf-idmain,body.tpl-bento.lk-av-right .pf-idmain,body.tpl-arcade.lk-av-right .pf-idmain{flex:1;padding-top:calc(var(--avs) / 2 + 6px)}
body.tpl-stack.lk-av-left .pf-name,body.tpl-stack.lk-av-right .pf-name,body.tpl-stack.lk-av-left .pf-loc,body.tpl-stack.lk-av-right .pf-loc,body.tpl-stack.lk-av-left .pf-actions,body.tpl-stack.lk-av-right .pf-actions,body.tpl-bento.lk-av-left .pf-name,body.tpl-arcade.lk-av-left .pf-name,body.tpl-bento.lk-av-right .pf-name,body.tpl-arcade.lk-av-right .pf-name,body.tpl-bento.lk-av-left .pf-loc,body.tpl-arcade.lk-av-left .pf-loc,body.tpl-bento.lk-av-right .pf-loc,body.tpl-arcade.lk-av-right .pf-loc,body.tpl-bento.lk-av-left .pf-actions,body.tpl-arcade.lk-av-left .pf-actions,body.tpl-bento.lk-av-right .pf-actions,body.tpl-arcade.lk-av-right .pf-actions{justify-content:flex-start}
body.tpl-stack.lk-nob.lk-av-left .pf-idmain,body.tpl-stack.lk-nob.lk-av-right .pf-idmain,body.tpl-stack.lk-noav .pf-idmain,body.tpl-stack.no-pic .pf-idmain,body.tpl-bento.lk-nob.lk-av-left .pf-idmain,body.tpl-arcade.lk-nob.lk-av-left .pf-idmain,body.tpl-bento.lk-nob.lk-av-right .pf-idmain,body.tpl-arcade.lk-nob.lk-av-right .pf-idmain,body.tpl-bento.lk-noav .pf-idmain,body.tpl-arcade.lk-noav .pf-idmain,body.tpl-bento.no-pic .pf-idmain,body.tpl-arcade.no-pic .pf-idmain{padding-top:0}
body.tpl-stack.lk-noav .pf-id,body.tpl-stack.no-pic .pf-id,body.tpl-bento.lk-noav .pf-id,body.tpl-arcade.lk-noav .pf-id,body.tpl-bento.no-pic .pf-id,body.tpl-arcade.no-pic .pf-id{flex-direction:column}

/* ── layout templates ── */
body.tpl-side .pf-id,body.tpl-edit .pf-id,body.tpl-resume .pf-id{align-items:flex-start;text-align:left}
body.tpl-side .pf-name,body.tpl-edit .pf-name,body.tpl-resume .pf-name,body.tpl-side .pf-loc,body.tpl-edit .pf-loc,body.tpl-resume .pf-loc,body.tpl-side .pf-actions,body.tpl-edit .pf-actions,body.tpl-resume .pf-actions{justify-content:flex-start}
body.tpl-edit .pf-id,body.tpl-resume .pf-id{margin-top:0;padding-top:30px;padding-bottom:6px}
body.tpl-edit .pf-name{font-size:clamp(2.3rem,8vw,4.4rem);line-height:1;letter-spacing:-.03em}
body.tpl-edit .pf-name .nm{white-space:normal;overflow:visible}
body.tpl-resume .pf-id{border-bottom:1px solid var(--line2);padding-bottom:20px;margin-bottom:6px}
body.tpl-resume:not(.lk-avs-sm):not(.lk-avs-lg){--avs:64px}
body.tpl-hero .pf-banner,body.tpl-split .pf-banner{display:block;height:clamp(330px,66vw,520px)}
body.tpl-hero .pf-banner::after,body.tpl-split .pf-banner::after{content:'';position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.82),rgba(0,0,0,.08) 72%)}
body.tpl-hero .pf-id,body.tpl-split .pf-id{position:absolute;left:0;right:0;bottom:0;margin:0;padding:0 var(--pad) 28px;gap:12px}
body.tpl-hero .pf-name,body.tpl-hero .pf-headline,body.tpl-hero .pf-loc,body.tpl-hero .pf-status,body.tpl-split .pf-name,body.tpl-split .pf-headline,body.tpl-split .pf-loc,body.tpl-split .pf-status{color:#fff}
body.tpl-hero .pf-name{font-size:clamp(1.8rem,7vw,3.2rem);letter-spacing:-.02em}
body.tpl-split .pf-name{font-size:clamp(1.7rem,5vw,2.8rem)}
body.tpl-hero .pf-name .nm,body.tpl-split .pf-name .nm{white-space:normal;overflow:visible}
body.tpl-hero .pill,body.tpl-split .pill{background:rgba(255,255,255,.2);color:#fff;backdrop-filter:blur(8px)}
body.tpl-hero .pill.red,body.tpl-split .pill.red{background:var(--ac);color:var(--on-ac)}
@media(min-width:900px){
  body.tpl-side .page,body.tpl-split .page{display:grid;row-gap:0;align-items:start}
  body.tpl-side .page{grid-template-columns:330px minmax(0,1fr);column-gap:10px}
  body.tpl-split .page{grid-template-columns:minmax(0,5fr) minmax(0,6fr)}
  body.tpl-side .pf-s,body.tpl-split .pf-s{grid-column:2}
  body.tpl-side .pf-s[data-k=header],body.tpl-split .pf-s[data-k=header]{grid-column:1;grid-row:1/span 30;position:sticky;top:calc(var(--topbar-h) + 6px)}
  body.tpl-split .pf-s[data-k=header]{top:var(--topbar-h);height:calc(100vh - var(--topbar-h))}
  body.tpl-split .pf-hd,body.tpl-split .pf-banner{height:100%}
  body.tpl-split .pf-s:not([data-k=header]){padding:0 14px}
  body.tpl-side .pf-banner{height:150px}
}
@media(min-width:760px){
  body.tpl-edit .pf-s:not([data-k=header]){display:grid;grid-template-columns:150px minmax(0,1fr);column-gap:10px}
  body.tpl-edit .sec-label{grid-column:1;padding-right:0}
  body.tpl-edit .sec-label~*{grid-column:2;grid-row:1;padding-left:0;padding-right:var(--pad)}
  body.tpl-bento .page,body.tpl-arcade .page{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:14px;padding:14px var(--pad) 0}
  body.tpl-bento .pf-s,body.tpl-arcade .pf-s{grid-column:span 6}
  body.tpl-bento .pf-s[data-k=about]{grid-column:span 4}
  body.tpl-bento .pf-s[data-k=skills]{grid-column:span 2}
  body.tpl-bento .pf-s[data-k=experience],body.tpl-bento .pf-s[data-k=links]{grid-column:span 3}
  body.tpl-arcade .pf-s[data-k=about],body.tpl-arcade .pf-s[data-k=skills],body.tpl-arcade .pf-s[data-k=experience],body.tpl-arcade .pf-s[data-k=links]{grid-column:span 3}
  body.tpl-bento .pf-s[data-k=skills] .skills{column-count:1}
}
/* Picture position + text alignment work in EVERY layout (before, only Stack obeyed them).
   Stack / Tiles / Arcade put the picture beside the text (rules above); the other layouts stack picture over text, so they move the whole block. */
body.tpl-hero.lk-av-left .pf-id,body.tpl-split.lk-av-left .pf-id,body.tpl-side.lk-av-left .pf-id,body.tpl-edit.lk-av-left .pf-id,body.tpl-resume.lk-av-left .pf-id{align-items:flex-start;text-align:left}
body.tpl-hero.lk-av-right .pf-id,body.tpl-split.lk-av-right .pf-id,body.tpl-side.lk-av-right .pf-id,body.tpl-edit.lk-av-right .pf-id,body.tpl-resume.lk-av-right .pf-id{align-items:flex-end;text-align:right}
body.tpl-hero.lk-av-center .pf-id,body.tpl-split.lk-av-center .pf-id,body.tpl-side.lk-av-center .pf-id,body.tpl-edit.lk-av-center .pf-id,body.tpl-resume.lk-av-center .pf-id{align-items:center;text-align:center}
body.tpl-hero.lk-av-left .pf-name,body.tpl-hero.lk-av-left .pf-loc,body.tpl-hero.lk-av-left .pf-actions,body.tpl-split.lk-av-left .pf-name,body.tpl-split.lk-av-left .pf-loc,body.tpl-split.lk-av-left .pf-actions,
body.tpl-side.lk-av-left .pf-name,body.tpl-side.lk-av-left .pf-loc,body.tpl-side.lk-av-left .pf-actions,body.tpl-edit.lk-av-left .pf-name,body.tpl-edit.lk-av-left .pf-loc,body.tpl-edit.lk-av-left .pf-actions,
body.tpl-resume.lk-av-left .pf-name,body.tpl-resume.lk-av-left .pf-loc,body.tpl-resume.lk-av-left .pf-actions{justify-content:flex-start}
body.tpl-hero.lk-av-right .pf-name,body.tpl-hero.lk-av-right .pf-loc,body.tpl-hero.lk-av-right .pf-actions,body.tpl-split.lk-av-right .pf-name,body.tpl-split.lk-av-right .pf-loc,body.tpl-split.lk-av-right .pf-actions,
body.tpl-side.lk-av-right .pf-name,body.tpl-side.lk-av-right .pf-loc,body.tpl-side.lk-av-right .pf-actions,body.tpl-edit.lk-av-right .pf-name,body.tpl-edit.lk-av-right .pf-loc,body.tpl-edit.lk-av-right .pf-actions,
body.tpl-resume.lk-av-right .pf-name,body.tpl-resume.lk-av-right .pf-loc,body.tpl-resume.lk-av-right .pf-actions{justify-content:flex-end}
body.tpl-hero.lk-av-center .pf-name,body.tpl-hero.lk-av-center .pf-loc,body.tpl-hero.lk-av-center .pf-actions,body.tpl-split.lk-av-center .pf-name,body.tpl-split.lk-av-center .pf-loc,body.tpl-split.lk-av-center .pf-actions,
body.tpl-side.lk-av-center .pf-name,body.tpl-side.lk-av-center .pf-loc,body.tpl-side.lk-av-center .pf-actions,body.tpl-edit.lk-av-center .pf-name,body.tpl-edit.lk-av-center .pf-loc,body.tpl-edit.lk-av-center .pf-actions,
body.tpl-resume.lk-av-center .pf-name,body.tpl-resume.lk-av-center .pf-loc,body.tpl-resume.lk-av-center .pf-actions{justify-content:center}
/* banner height and name size now also work in the big-banner layouts and Editorial */
body.tpl-hero.lk-bh-short .pf-banner,body.tpl-split.lk-bh-short .pf-banner{height:clamp(230px,48vw,380px)}
body.tpl-hero.lk-bh-tall .pf-banner,body.tpl-split.lk-bh-tall .pf-banner{height:clamp(430px,90vw,680px)}
body.tpl-edit.nsz-lg .pf-name{font-size:clamp(3rem,10vw,5.6rem)}
body.tpl-edit.nsz-xl .pf-name{font-size:clamp(3.6rem,13vw,7rem)}
body.tpl-bento .pf-s:not([data-k=header]){background:var(--surface);border:1px solid var(--line);border-radius:var(--card-r);padding-bottom:18px;overflow:hidden}
body.tpl-bento .pf-s[data-k=header]{border-radius:var(--card-r);overflow:hidden;border:1px solid var(--line)}
body.tpl-bento .page{padding-top:12px}
body.tpl-bento .proj{background:rgba(var(--fg),.04)}
body.tpl-resume .proj,body.tpl-resume .lnk,body.tpl-edit .lnk{background:none}
@media(max-width:759px){
  body.tpl-bento .page,body.tpl-arcade .page{padding-left:0;padding-right:0}
  body.tpl-bento .pf-s,body.tpl-arcade .pf-s{width:auto;margin:0 var(--pad) 12px}
}

/* ── section headings + body ── */
.sec-label{padding:var(--gap) var(--pad) 10px;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted)}
body.lk-hd-caps .sec-label{color:var(--ac);font-size:.74rem;letter-spacing:2px}
body.lk-hd-line .sec-label{margin:var(--gap) var(--pad) 12px;padding:0 0 7px;border-bottom:2px solid var(--ac);color:var(--ui-text);font-size:.74rem}
body.lk-hd-bar .sec-label{margin:var(--gap) var(--pad) 12px;padding:2px 0 2px 10px;border-left:4px solid var(--ac);color:var(--ui-text);font-size:.78rem}
body.lk-hd-big .sec-label{font-size:clamp(1.4rem,5vw,2rem);text-transform:none;letter-spacing:-.02em;color:var(--ui-text);padding-bottom:12px}
.bio{padding:0 var(--pad);font-size:.86rem;line-height:1.75;color:var(--sub);white-space:pre-line;word-break:break-word}

.skills{list-style:none;padding:0 var(--pad);font-size:.86rem}
.skills li{padding:5px 0;color:var(--text);break-inside:avoid}
body.lk-sk-cols .skills{column-count:2;column-gap:20px}
body.lk-sk-cols .skills li,body.lk-sk-list .skills li{display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--line)}
body.lk-sk-cols .skills li::before,body.lk-sk-list .skills li::before{content:'';width:10px;height:2px;background:var(--ac);flex-shrink:0}
body.lk-sk-text .skills{font-size:1rem;line-height:1.9}
body.lk-sk-text .skills li{display:inline;padding:0}
body.lk-sk-text .skills li+li::before{content:' / ';color:var(--ac);font-weight:700}
body.lk-sk-big .skills li{font-size:clamp(1.5rem,6vw,2.3rem);font-weight:700;line-height:1.15;padding:2px 0;letter-spacing:-.02em}
body.lk-sk-big .skills li:nth-child(even){color:var(--ac)}
body.lk-sk-boxes .skills{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}
body.lk-sk-boxes .skills li{padding:12px;border:1px solid var(--line2);border-radius:var(--r);font-size:.78rem;font-weight:500;text-align:center}

.exps{padding:0 var(--pad);display:flex;flex-direction:column}
.exp{display:flex;flex-direction:column}
.exp-p{font-size:.66rem;color:var(--muted);font-weight:600}
.exp-r{font-size:.86rem;font-weight:600}
.exp-o{font-size:.74rem;color:var(--ac-l);margin-top:2px}
:root[data-theme="light"] .exp-o,body.tx-dark .exp-o{color:var(--ui-text);opacity:.7}
body.tx-light .exp-o{color:var(--ac-l);opacity:1}
.exp-t{font-size:.78rem;color:var(--sub);line-height:1.65;margin-top:6px;word-break:break-word;white-space:pre-line}
body.lk-ex-timeline .exp{position:relative;padding:0 0 18px 20px;border-left:2px solid var(--line2);margin-left:5px}
body.lk-ex-timeline .exp:last-child{padding-bottom:2px;border-left-color:transparent}
body.lk-ex-timeline .exp::before{content:'';position:absolute;left:-7px;top:3px;width:12px;height:12px;border-radius:50%;background:var(--ac);box-shadow:0 0 0 3px var(--solid)}
body.lk-ex-timeline .exp-p{order:-1;margin-bottom:2px}
body.lk-ex-table .exp{display:grid;grid-template-columns:110px minmax(0,1fr);gap:14px;padding:14px 0;border-bottom:1px solid var(--line)}
body.lk-ex-table .exp-m{min-width:0}
body.lk-ex-cards .exp{padding:14px;margin-bottom:10px;background:var(--surface);border:1px solid var(--line);border-radius:var(--card-r)}
body.lk-ex-cards .exp-p{order:-1;margin-bottom:3px;color:var(--ac-l)}

/* projects (cards open the full work page) */
.projects{display:flex;flex-direction:column;gap:14px;padding:0 var(--pad)}
.proj{background:var(--surface);border-radius:var(--card-r);overflow:hidden;border:1px solid var(--line);min-width:0;position:relative;cursor:pointer}
.proj:focus-visible{outline:2px solid var(--ac);outline-offset:3px}
.proj-gal{position:relative}
.proj-strip{display:flex}
.proj-img{flex:0 0 100%;width:100%;aspect-ratio:16/9;object-fit:cover;display:block;background:var(--ph)}
.proj-ph{display:flex;align-items:center;justify-content:center;color:var(--ac);font-size:2.4rem;background:radial-gradient(circle at 30% 20%,var(--ac-glow),transparent 60%),var(--ph)}
.proj-ct{position:absolute;top:8px;right:8px;background:rgba(1,2,24,.72);color:#fff;font-size:.6rem;font-weight:700;padding:3px 8px;border-radius:5px;display:flex;align-items:center;gap:4px}
.proj-ct i{font-size:11px}
.proj-body{padding:14px;min-width:0}
.proj-title{font-size:.92rem;font-weight:600;word-break:break-word}
.proj-meta{font-size:.64rem;font-weight:600;color:var(--ac-l);margin-top:3px;letter-spacing:.3px}
:root[data-theme="light"] .proj-meta,body.tx-dark .proj-meta{color:var(--ui-text);opacity:.65}
body.tx-light .proj-meta{color:var(--ac-l);opacity:1}
.proj-desc{font-size:.78rem;color:var(--sub);line-height:1.65;margin-top:5px;word-break:break-word;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.proj-link{display:inline-flex;align-items:center;gap:5px;margin-top:10px;font-size:.74rem;font-weight:600;color:var(--ac-l)}
:root[data-theme="light"] .proj-link,body.tx-dark .proj-link{color:var(--ui-text);text-decoration:underline;text-decoration-color:var(--ac)}
body.tx-light .proj-link{color:var(--ac-l);text-decoration:none}
.proj-link i{transition:transform .25s}
.proj:hover .proj-link i{transform:translateX(4px)}
body.lk-pj-grid .projects,body.lk-pj-grid3 .projects{display:grid;gap:12px}
body.lk-pj-grid .projects{grid-template-columns:1fr 1fr}
body.lk-pj-grid3 .projects{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
body.lk-pj-grid .proj-img{aspect-ratio:4/5}body.lk-pj-grid3 .proj-img{aspect-ratio:1/1}
body.lk-pj-grid .proj-body{padding:10px}body.lk-pj-grid3 .proj-body{padding:8px}
body.lk-pj-grid .proj-title{font-size:.78rem}body.lk-pj-grid3 .proj-title{font-size:.68rem}
body.lk-pj-grid .proj-desc,body.lk-pj-grid3 .proj-desc{font-size:.68rem;-webkit-line-clamp:3}
body.lk-pj-grid3 .proj-desc,body.lk-pj-grid3 .proj-link,body.lk-pj-grid3 .proj-ct,body.lk-pj-grid3 .proj-meta{display:none}
body.lk-pj-rows .proj{display:flex;align-items:stretch}
body.lk-pj-rows .proj-gal{flex:0 0 40%;max-width:190px}
body.lk-pj-rows .proj-img{aspect-ratio:1/1;height:100%}
body.lk-pj-rows .proj-body{flex:1}
body.lk-pj-masonry .projects{display:block;column-count:2;column-gap:12px}
body.lk-pj-masonry .proj{break-inside:avoid;margin-bottom:12px}
body.lk-pj-masonry .proj-img{aspect-ratio:auto;height:auto}
body.lk-pj-masonry .proj-ph{aspect-ratio:4/3}
body.lk-pj-masonry .proj-title{font-size:.8rem}
body.lk-pj-index .projects{gap:0}
body.lk-pj-index .proj{display:flex;align-items:center;gap:14px;padding:12px 0;background:none;border:none;border-bottom:1px solid var(--line);border-radius:0}
body.lk-pj-index .proj-gal{flex:0 0 62px}
body.lk-pj-index .proj-strip{overflow:hidden;border-radius:var(--r)}
body.lk-pj-index .proj-img{aspect-ratio:1/1}
body.lk-pj-index .proj-ct{display:none}
body.lk-pj-index .proj-body{padding:0;flex:1}
body.lk-pj-index .proj-desc{-webkit-line-clamp:1;margin-top:2px}
body.lk-pj-index .proj-link{margin-top:4px}
body.lk-pj-carousel .projects{flex-direction:row;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;padding-bottom:6px}
body.lk-pj-carousel .projects::-webkit-scrollbar{display:none}
body.lk-pj-carousel .proj{flex:0 0 78%;scroll-snap-align:start}

/* links */
.lnks{display:flex;flex-direction:column;gap:8px;padding:0 var(--pad)}
.lnk{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:var(--card-r);background:var(--surface);border:1px solid var(--line);font-size:.82rem;font-weight:500;position:relative;overflow:hidden;transition:border-color .2s,background .2s,transform .25s,box-shadow .25s}
.lnk:hover{border-color:var(--ac-bd);background:var(--surface2)}
.lnk i:first-child{font-size:1.15rem;color:var(--ac);flex-shrink:0}
.lnk span{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lnk i:last-child{color:var(--muted);font-size:1rem}
body.lk-ln-text .lnks{gap:2px}
body.lk-ln-text .lnk{background:none;border:none;padding:6px 0;border-radius:0;text-decoration:underline;text-decoration-color:var(--ac);text-underline-offset:4px}
body.lk-ln-text .lnk i{display:none}
body.lk-ln-icons .lnks{flex-direction:row;flex-wrap:wrap}
body.lk-ln-icons .lnk{width:48px;height:48px;padding:0;justify-content:center}
body.lk-ln-icons .lnk span,body.lk-ln-icons .lnk i:last-child{display:none}

.empty{padding:44px 24px;text-align:center;color:var(--muted);font-size:.8rem;display:flex;flex-direction:column;align-items:center;gap:10px}
.empty>i{font-size:2rem;color:var(--ac);opacity:.9}
.retro-only{display:none}

/* ── 2020s polish ── */
body.era-modern .proj,body.era-modern .lnk,body.era-modern.lk-ex-cards .exp{backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 10px 30px rgba(0,0,0,.16)}
:root[data-theme="light"] body.era-modern .proj,:root[data-theme="light"] body.era-modern .lnk{box-shadow:0 10px 30px rgba(20,24,60,.08)}
body.era-modern .pill.red{box-shadow:0 6px 22px var(--ac-glow)}
body.era-modern .pf-av{box-shadow:0 0 0 4px var(--solid),0 10px 30px var(--ac-glow)}
body.lk-pj-index.era-modern .proj,body.tpl-resume.era-modern .proj,body.lk-ln-text.era-modern .lnk{box-shadow:none;backdrop-filter:none}

/* ── Web 1.0 ── */
body.era-retro{--r:0px;--card-r:0px;--lk:#8ab4ff;background:var(--solid)}
:root[data-theme="light"] body.era-retro,body.era-retro.tx-dark{--lk:#0000ee}
body.era-retro.tx-light{--lk:#8ab4ff}
body.era-retro.ft-sans{--font:Verdana,Geneva,sans-serif}
body.era-retro.ft-grotesk{--font:Arial,Helvetica,sans-serif}
body.era-retro.ft-serif{--font:'Times New Roman',Times,serif}
body.era-retro.ft-mono{--font:'Courier New',Courier,monospace}
body.era-retro.ft-rounded,body.era-retro.ft-pixel{--font:'Comic Sans MS','Comic Sans',cursive}
body.era-retro .topbar{background:var(--solid)}
body.era-retro .tb-in{border-bottom:2px groove rgba(128,128,128,.8)}
body.era-retro .pill,body.era-retro .tb-btn,body.era-retro .pill.red{background:#d4d0c8;color:#000;border:2px outset #fff;border-radius:0;box-shadow:none;font-weight:400;backdrop-filter:none}
body.era-retro .pill:hover,body.era-retro .tb-btn:hover{background:#e4e0d8;box-shadow:none}
body.era-retro .pill:active{border-style:inset}
body.era-retro .sec-label{font-size:1.15rem;font-weight:700;text-transform:none;letter-spacing:0;color:var(--ac);border:none;border-bottom:1px solid var(--ui-text);margin:var(--gap) var(--pad) 12px;padding:0 0 3px}
body.era-retro .proj,body.era-retro .lnk,body.era-retro.lk-ex-cards .exp,body.era-retro.tpl-bento .pf-s:not([data-k=header]),body.era-retro.lk-sk-boxes .skills li{border:2px ridge rgba(128,128,128,.75);background:transparent;box-shadow:none;backdrop-filter:none;border-radius:0}
body.era-retro .lnk,body.era-retro .proj-link,body.era-retro .exp-o{color:var(--lk);text-decoration:underline;opacity:1}
body.era-retro .lnk i:first-child{color:var(--lk)}
body.era-retro .pf-av{border:3px ridge rgba(128,128,128,.85);box-shadow:none}
body.era-retro .pf-banner{border-bottom:2px groove rgba(128,128,128,.8)}
body.era-retro .pf-status{border-radius:0;border:1px dashed var(--ac);background:transparent}
body.era-retro .pf-status::before{content:'★';width:auto;height:auto;background:none;box-shadow:none;color:var(--ac)}
body.era-retro.lk-ex-timeline .exp::before{border-radius:0;box-shadow:none}
body.era-retro .skills li::before{content:'• '!important;width:auto!important;height:auto!important;background:none!important;color:var(--ac)}
body.era-retro.lk-sk-text .skills li+li::before{content:' | '!important}
body.era-retro .chip{border-radius:0}
body.era-retro.lk-hd-line .sec-label,body.era-retro.lk-hd-bar .sec-label,body.era-retro.lk-hd-big .sec-label{border-left:none;border-bottom:1px solid var(--ui-text);padding:0 0 3px;font-size:1.15rem;color:var(--ac)}
body.era-retro hr{border:none;border-top:2px groove gray}
body.era-retro .retro-only{display:block;text-align:center;font-size:.72rem;padding:30px 12px 0;color:var(--sub)}
body.era-retro .retro-only a{color:var(--lk);text-decoration:underline}

/* ── photo viewer ── */
.lb{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.94);display:none;align-items:center;justify-content:center}
.lb.open{display:flex}
.lb img{max-width:100%;max-height:100%;object-fit:contain}
.lb button{position:absolute;background:rgba(255,255,255,.12);border:none;color:#fff;width:40px;height:40px;border-radius:50%;font-size:22px;display:flex;align-items:center;justify-content:center}
.lb button:hover{background:var(--ac);color:#000}
.lb .x{top:14px;right:14px}.lb .p{left:12px;top:50%;transform:translateY(-50%)}.lb .n{right:12px;top:50%;transform:translateY(-50%)}

/* ══ MOTION ══ */
.rv{opacity:0;transition:opacity .8s ease var(--d,0ms),transform .8s cubic-bezier(.22,1,.36,1) var(--d,0ms),filter .8s ease var(--d,0ms),clip-path .8s cubic-bezier(.22,1,.36,1) var(--d,0ms)}
body.an-rise .rv{transform:translateY(28px)}
body.an-slide .rv{transform:translateX(-44px)}
body.an-zoom .rv{transform:scale(.9)}
body.an-blur .rv{filter:blur(12px);transform:translateY(10px)}
body.an-flip .rv{transform:perspective(700px) rotateX(-32deg) translateY(18px);transform-origin:top}
body.an-pop .rv{transform:scale(.7);transition-timing-function:ease,cubic-bezier(.34,1.56,.64,1),ease,ease}
body.an-drop .rv{transform:translateY(-34px);transition-timing-function:ease,cubic-bezier(.34,1.56,.64,1),ease,ease}
body.an-wipe .rv{clip-path:inset(0 100% 0 0);opacity:1}
.rv.in{opacity:1!important;transform:none!important;filter:none!important;clip-path:inset(0 0 0 0)!important}
.proj,.lnk,.pill,.skills li{transition:transform .28s cubic-bezier(.22,1,.36,1),box-shadow .28s,border-color .2s,background .2s}
body.hv-lift .proj:hover,body.hv-lift .lnk:hover,body.hv-lift .pill:hover{transform:translateY(-5px);box-shadow:0 14px 34px rgba(0,0,0,.28)}
body.hv-glow .proj:hover,body.hv-glow .lnk:hover,body.hv-glow .pill:hover{box-shadow:0 0 0 1px var(--ac),0 0 28px var(--ac-glow);border-color:var(--ac)}
body.hv-grow .proj:hover,body.hv-grow .lnk:hover,body.hv-grow .pill:hover{transform:scale(1.035)}
body.hv-tilt .proj:hover,body.hv-tilt .lnk:hover,body.hv-tilt .pill:hover{transform:rotate(-1.4deg) scale(1.02)}
body.hv-tilt3d .proj,body.hv-tilt3d .lnk{transform:perspective(700px) rotateX(var(--rx,0deg)) rotateY(var(--ry,0deg));transition:transform .15s ease-out,box-shadow .25s}
body.hv-tilt3d .proj:hover,body.hv-tilt3d .lnk:hover{box-shadow:0 18px 40px rgba(0,0,0,.3)}
body.hv-spot .proj::before,body.hv-spot .lnk::before{content:'';position:absolute;inset:0;z-index:1;pointer-events:none;opacity:0;transition:opacity .25s;background:radial-gradient(260px circle at var(--mx,50%) var(--my,50%),var(--ac-dim),transparent 70%)}
body.hv-spot .proj:hover::before,body.hv-spot .lnk:hover::before{opacity:1}
body.hv-shine .proj::after{content:'';position:absolute;top:0;left:-70%;width:45%;height:100%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.22),transparent);transform:skewX(-18deg);pointer-events:none}
body.hv-shine .proj:hover::after{left:130%;transition:left .8s ease}
@keyframes pfPan{0%{background-size:112%;background-position:0% 40%}100%{background-size:130%;background-position:100% 60%}}
@keyframes pfFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}
@keyframes pfAur{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
@keyframes pfMq{0%{transform:translateX(100%)}100%{transform:translateX(-100%)}}
@keyframes pfPulse{0%,100%{box-shadow:0 0 0 0 var(--ac-glow)}50%{box-shadow:0 0 0 10px transparent}}
@keyframes pfTw{0%{opacity:.25}100%{opacity:1}}
body.am-pan .pf-banner.has-img{animation:pfPan 18s ease-in-out infinite alternate}
body.am-float .pf-av{animation:pfFloat 4s ease-in-out infinite}
body.am-aurora{background:linear-gradient(120deg,var(--solid),var(--ac-dim),var(--solid),var(--ac-dim),var(--solid));background-size:320% 320%;animation:pfAur 16s ease infinite}
body.am-aurora .topbar{background:var(--solid)}
body.am-marquee .pf-headline{overflow:hidden;white-space:nowrap;max-width:100%}
body.am-marquee .pf-headline span{display:inline-block;padding-left:100%;animation:pfMq 14s linear infinite}
body.am-pulse .pill.red{animation:pfPulse 2s ease-out infinite}
body.am-stars::after{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;animation:pfTw 3.2s ease-in-out infinite alternate;
  background-image:radial-gradient(2px 2px at 12% 18%,rgba(var(--fg),.9),transparent),radial-gradient(1.5px 1.5px at 78% 12%,rgba(var(--fg),.8),transparent),radial-gradient(2px 2px at 46% 40%,rgba(var(--fg),.7),transparent),radial-gradient(1.5px 1.5px at 88% 58%,rgba(var(--fg),.9),transparent),radial-gradient(2px 2px at 22% 72%,rgba(var(--fg),.8),transparent),radial-gradient(1.5px 1.5px at 62% 86%,rgba(var(--fg),.7),transparent),radial-gradient(1.5px 1.5px at 34% 92%,rgba(var(--fg),.8),transparent)}
body.am-scan::after{content:'';position:fixed;inset:0;pointer-events:none;z-index:900;background:repeating-linear-gradient(0deg,rgba(0,0,0,.2) 0 1px,transparent 1px 3px)}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition-duration:.01ms!important}.rv{opacity:1!important;transform:none!important;filter:none!important;clip-path:none!important}}

/* ══ edit sheet ══ */
.field{margin-bottom:12px}
.field>label{display:block;font-size:.64rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px}
.field input[type=text],.field input[type=url],.field input[type=email],.field textarea,.field input[type=file]{width:100%;background:var(--surface);border:1px solid var(--line2);border-radius:5px;padding:10px 12px;font-size:.82rem;color:var(--ui-text);outline:none;transition:border-color .2s,box-shadow .2s}
.field textarea{resize:vertical;min-height:100px;line-height:1.6}
.field input:focus,.field textarea:focus{border-color:var(--ac);box-shadow:0 0 0 3px var(--ac-dim)}
.field input[type=file]{padding:8px;font-size:.74rem}
.field small,.hint{display:block;font-size:.64rem;color:var(--muted);margin-top:5px;line-height:1.5}
.two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.toggle{display:flex;align-items:center;gap:10px;font-size:.8rem;margin-bottom:12px}
.toggle input{width:18px;height:18px;accent-color:var(--ac)}
.btn{width:100%;height:38px;border:none;border-radius:5px;background:var(--ac);color:var(--on-ac);font-size:.8rem;font-weight:700;transition:background .2s,box-shadow .2s;display:flex;align-items:center;justify-content:center;gap:6px}
.btn:hover{background:var(--ac-h);box-shadow:0 4px 16px var(--ac-glow)}
.btn.ghost{background:rgba(var(--fg),.1);color:var(--ui-text);box-shadow:none}
.btn.ghost:hover{background:rgba(var(--fg),.2)}
.btn.sm{width:auto;height:30px;padding:0 12px;font-size:.7rem}
.grp{margin-top:20px;padding-top:16px;border-top:1px solid var(--line)}
.grp:first-child{margin-top:0;padding-top:0;border-top:none}
.grp-h{display:flex;align-items:center;gap:7px;margin-bottom:10px;font-size:.66rem;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--sub)}
.grp-h i{font-size:15px;color:var(--ac)}
.grp-h em{margin-left:auto;font-style:normal;font-size:.58rem;font-weight:600;color:var(--muted);background:rgba(var(--fg),.07);padding:2px 8px;border-radius:4px;letter-spacing:.3px;text-transform:uppercase}
.sub-h{font-size:.66rem;font-weight:600;color:var(--sub);margin:14px 0 6px}
.dg{border-top:1px solid var(--line);margin-top:14px}
.dg>summary{list-style:none;display:flex;align-items:center;gap:8px;padding:13px 0;font-size:.7rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;cursor:pointer;color:var(--ui-text)}
.dg>summary::-webkit-details-marker{display:none}
.dg>summary i{color:var(--ac);font-size:16px}
.dg>summary::after{content:'+';margin-left:auto;font-size:1.1rem;color:var(--muted)}
.dg[open]>summary::after{content:'–'}
.dg>div{padding-bottom:6px}
.seg{display:flex;flex-wrap:wrap;gap:3px;padding:3px;border-radius:5px;background:var(--surface);border:1px solid var(--line)}
.seg label{flex:1 1 29%;min-width:0}
.seg input{display:none}
.seg span{display:flex;align-items:center;justify-content:center;height:30px;padding:0 6px;border-radius:4px;font-size:.66rem;font-weight:600;color:var(--sub);cursor:pointer;text-align:center;line-height:1.15;transition:.2s}
.seg input:checked+span{background:var(--ac);color:var(--on-ac)}
.sws{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.sw{position:relative}
.sw input{position:absolute;opacity:0;width:0;height:0}
.sw span{display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:var(--c);color:#000;font-size:16px;cursor:pointer;box-shadow:0 0 0 2px var(--sh-card,#090b29);transition:transform .15s}
.sw span i{opacity:0}
.sw input:checked+span{box-shadow:0 0 0 2px var(--sh-card,#090b29),0 0 0 4px var(--ui-text)}
.sw input:checked+span i{opacity:1}
.sw span:active{transform:scale(.9)}
.sws.bgs .sw span{width:46px;height:34px;border-radius:8px;border:1px solid var(--line2);color:#fff}
.sw.cu span{background:conic-gradient(red,yellow,lime,cyan,blue,magenta,red)}
.cpick{width:42px;height:34px;padding:0;border:1px solid var(--line2);border-radius:6px;background:none;cursor:pointer}
.pv{position:relative;width:100%;height:92px;border-radius:10px;overflow:hidden;border:1px solid var(--line2);background:radial-gradient(120% 140% at 0% 0%,var(--ac-glow),transparent 58%),linear-gradient(135deg,#0d1030,#171b46);background-size:cover;background-position:center;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:.68rem}
.avrow{display:flex;align-items:center;gap:12px}
.avrow img{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--ac);flex-shrink:0;background:var(--ph)}
.avrow .acts{display:flex;flex-direction:column;gap:6px;flex:1;min-width:0}
.fbtn{position:relative;overflow:hidden;display:inline-flex}
.fbtn input{position:absolute;inset:0;opacity:0;cursor:pointer;font-size:0}
.rmchk{display:flex;align-items:center;gap:7px;font-size:.7rem;color:var(--sub);margin-top:7px}
.rmchk input{accent-color:var(--ac)}
.secrow{display:flex;align-items:center;gap:8px;padding:7px 9px;margin-bottom:6px;border-radius:5px;background:var(--surface);border:1px solid var(--line)}
.secrow .num{width:16px;font-size:.62rem;color:var(--muted);text-align:center;flex-shrink:0}
.secrow .nm{flex:1;min-width:0;font-size:.72rem;font-weight:600}
.secrow .nm small{display:block;font-size:.58rem;font-weight:400;color:var(--muted);margin-top:1px}
.secrow.off .nm{opacity:.45}
.secrow button{width:28px;height:28px;flex-shrink:0;border-radius:5px;border:1px solid var(--line2);background:transparent;color:var(--ui-text);font-size:15px;display:flex;align-items:center;justify-content:center}
.secrow button:hover{border-color:var(--ac);color:var(--ac)}
.secrow button:disabled{opacity:.25;pointer-events:none}
.rowed{margin-bottom:10px;padding:10px;border:1px solid var(--line);border-radius:6px}
.rowed .top{display:flex;gap:8px;margin-bottom:8px}
.rowed .top:last-child{margin-bottom:0}
.rowed input,.rowed textarea{flex:1;min-width:0;width:100%;background:var(--surface);border:1px solid var(--line2);border-radius:5px;padding:9px 11px;font-size:.78rem;color:var(--ui-text);outline:none}
.rowed textarea{resize:none;line-height:1.5}
.rowed input:focus,.rowed textarea:focus{border-color:var(--ac)}
.rowed .x{width:36px;flex-shrink:0;background:transparent;border:1px solid var(--line);border-radius:6px;color:var(--sub);font-size:18px;display:flex;align-items:center;justify-content:center}
.rowed .x:hover{color:#ff5a5a;border-color:#ff5a5a}
.notice{padding:10px 14px;border-radius:5px;font-size:.76rem}
.notice.ok{background:rgba(74,222,128,.12);color:var(--ok)}
.notice.err{background:rgba(255,45,45,.12);color:#ff6b6b}
.thumbs{display:flex;flex-wrap:wrap;gap:8px;margin:2px 0 8px}
.thumb{position:relative;width:58px;height:58px;border-radius:7px;overflow:hidden;border:2px solid var(--line2);background:var(--ph);display:flex;align-items:center;justify-content:center;color:var(--ac);font-size:22px}
.thumb img,.thumb video{width:100%;height:100%;object-fit:cover;display:block}
.thumb label{position:absolute;top:3px;right:3px;width:19px;height:19px;border-radius:4px;background:rgba(0,0,0,.78);color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.thumb input{display:none}
.thumb.gone{opacity:.35}
.pedit{border-top:1px solid var(--line)}
.pedit summary{list-style:none;display:flex;align-items:center;gap:12px;padding:10px 0;cursor:pointer}
.pedit summary::-webkit-details-marker{display:none}
.pedit summary img,.pedit summary .noimg{width:52px;height:52px;border-radius:6px;object-fit:cover;background:var(--ph);flex-shrink:0;display:flex;align-items:center;justify-content:center;color:var(--muted)}
.pedit .t{flex:1;min-width:0;font-size:.82rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pedit .t small{display:block;font-size:.62rem;font-weight:400;color:var(--muted)}
.pedit summary>i{color:var(--muted);font-size:18px;transition:transform .2s}
.pedit[open] summary>i{transform:rotate(180deg)}
.pedit form{padding:4px 0 8px}
.pdel{display:flex;justify-content:flex-end;padding-bottom:12px}
.pdel .btn{background:rgba(255,45,45,.12);color:#ff6b6b;box-shadow:none}
.pdel .btn:hover{background:rgba(255,45,45,.24)}
.addbox{padding:12px;border:1px solid var(--line);border-radius:8px;margin-bottom:14px;background:var(--surface)}

.sh-overlay{--sh-lane:#070924;--sh-card:#090b29;position:fixed;inset:0;z-index:99999;display:none;align-items:flex-end;justify-content:center;background:rgba(0,0,0,.6);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px)}
:root[data-theme="light"] .sh-overlay{--sh-lane:#fff;--sh-card:#eef1f7;background:rgba(6,7,26,.45)}
.sh-overlay.open{display:flex;animation:shFade .18s ease both}
@keyframes shFade{from{opacity:0}to{opacity:1}}
@keyframes shUp{from{transform:translateY(100%)}to{transform:none}}
.sh-card{width:100%;max-width:600px;max-height:92vh;max-height:92dvh;display:flex;flex-direction:column;gap:9px;background:var(--sh-lane);padding:6px 11px calc(env(safe-area-inset-bottom,0px) + 11px);overflow:hidden;animation:shUp .3s cubic-bezier(.32,.72,0,1) both}
@media(min-width:801px){.sh-card{border:1px solid var(--line);border-bottom:0}}
.sh-handle{width:33px;height:3.3px;border-radius:2px;background:rgba(var(--fg),.22);margin:3px auto 0;flex-shrink:0}
.sh-inner{background:var(--sh-card);border-radius:7px;display:flex;flex-direction:column;min-height:0;flex:1 1 auto;overflow:hidden}
.sh-title{font-size:9.5px;color:rgba(var(--fg),.4);letter-spacing:1px;text-transform:uppercase;padding:9px 13px 4px}
.sh-tabs{display:flex;gap:6px;padding:6px 13px 8px}
.sh-tab{flex:1;height:30px;border:none;border-radius:5px;background:rgba(var(--fg),.06);color:var(--sub);font-size:.72rem;font-weight:600;transition:background .2s,color .2s;white-space:nowrap}
.sh-tab.on{background:var(--ac);color:var(--on-ac)}
.sh-body{overflow-y:auto;flex:1;min-height:0;padding:4px 13px 16px;scrollbar-width:thin;scrollbar-color:rgba(var(--fg),.15) transparent}
.sh-pane{display:none}
.sh-pane.on{display:block}
.sh-pane>.notice{margin-bottom:12px}
.sh-pane .save{margin-top:18px}
.sh-close{width:100%;flex-shrink:0;padding:11px;background:var(--sh-card);border:0;border-radius:7px;color:rgba(var(--fg),.85);font-size:.8rem;font-weight:600;transition:color .2s}
.sh-close:hover{color:var(--ac)}
.sh-overlay *{font-family:'Poppins',sans-serif}
.sh-overlay [class^="ri-"],.sh-overlay [class*=" ri-"],.sh-overlay [class^="ri-"]::before,.sh-overlay [class*=" ri-"]::before{font-family:'remixicon'!important}

/* ═══ EXTRA CUSTOMISATION ═══ */
@media(min-width:900px){
  body.wd-narrow{--max-w:640px!important}
  body.wd-wide{--max-w:1180px!important}
  body.wd-full{--max-w:1500px!important}
}
body.fz-sm .lane{zoom:.92}
body.fz-lg .lane{zoom:1.1}
body.cd-flat .proj,body.cd-flat .lnk,body.cd-flat.lk-ex-cards .exp,body.cd-flat.tpl-bento .pf-s:not([data-k=header]),body.cd-flat .pm-card{border-color:transparent;box-shadow:none}
body.cd-outline .proj,body.cd-outline .lnk,body.cd-outline.lk-ex-cards .exp,body.cd-outline.tpl-bento .pf-s:not([data-k=header]),body.cd-outline .pm-card{background:transparent;border:2px solid var(--line2)}
body.cd-glass .proj,body.cd-glass .lnk,body.cd-glass.lk-ex-cards .exp,body.cd-glass.tpl-bento .pf-s:not([data-k=header]),body.cd-glass .pm-card{background:rgba(var(--fg),.07);border:1px solid rgba(var(--fg),.18);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px)}
body.cd-shadow .proj,body.cd-shadow .lnk,body.cd-shadow.lk-ex-cards .exp,body.cd-shadow.tpl-bento .pf-s:not([data-k=header]),body.cd-shadow .pm-card{border:2px solid var(--ui-text);box-shadow:5px 5px 0 var(--ac)}
body.cd-none .proj,body.cd-none .lnk,body.cd-none.lk-ex-cards .exp,body.cd-none.tpl-bento .pf-s:not([data-k=header]),body.cd-none .pm-card{background:none;border:none;box-shadow:none;border-radius:0}
body.bt-pill .pill,body.bt-pill .btn{border-radius:99px}
body.bt-square .pill,body.bt-square .btn{border-radius:0}
body.bt-outline .pill,body.bt-outline .pill.red{background:transparent;color:var(--ui-text);box-shadow:none;border:2px solid var(--ac)}
body.bt-outline .pill:hover,body.bt-outline .pill.red:hover{background:var(--ac-dim);box-shadow:none}
body:not(.tpl-bento):not(.dv-none) .pf-s:not([data-k=header]){padding-bottom:6px}
body.dv-line:not(.tpl-bento) .pf-s:not([data-k=header]){border-bottom:1px solid var(--line2)}
body.dv-dashed:not(.tpl-bento) .pf-s:not([data-k=header]){border-bottom:2px dashed var(--line2)}
body.dv-dots:not(.tpl-bento) .pf-s:not([data-k=header]){border-bottom:4px dotted var(--ac)}
body.dv-thick:not(.tpl-bento) .pf-s:not([data-k=header]){border-bottom:5px solid var(--ac)}
body.hf-serif{--hfont:'Playfair Display',Georgia,serif}body.hf-mono{--hfont:'DM Mono',ui-monospace,monospace}
body.hf-grotesk{--hfont:'Space Grotesk',sans-serif}body.hf-rounded{--hfont:'Nunito',sans-serif}body.hf-pixel{--hfont:'Pixelify Sans',monospace}
body[class*=" hf-"]:not(.hf-same) .sec-label,body[class*=" hf-"]:not(.hf-same) .pf-name,body[class*=" hf-"]:not(.hf-same) .pf-name .nm,body[class*=" hf-"]:not(.hf-same) .proj-title,body[class*=" hf-"]:not(.hf-same) .pf-headline,body[class*=" hf-"]:not(.hf-same) .pm-title{font-family:var(--hfont)}
body.hc-upper .sec-label,body.hc-upper .pf-name,body.hc-upper .proj-title,body.hc-upper .pm-title{text-transform:uppercase}
body.hc-lower .sec-label,body.hc-lower .pf-name,body.hc-lower .proj-title,body.hc-lower .pm-title{text-transform:lowercase}
body.cv-dim .pf-banner::after{content:'';position:absolute;inset:0;background:rgba(0,0,0,.5)}
body.cv-mono .pf-banner.has-img{filter:grayscale(1) contrast(1.1)}
body.cv-tint .pf-banner::after{content:'';position:absolute;inset:0;background:var(--ac);mix-blend-mode:multiply;opacity:.6}
body.cv-vivid .pf-banner.has-img{filter:saturate(1.9) contrast(1.12)}
body.al-left:not(.lk-av-center):not(.lk-av-right) .pf-id{align-items:flex-start;text-align:left}
body.al-left:not(.lk-av-center):not(.lk-av-right) .pf-name,body.al-left:not(.lk-av-center):not(.lk-av-right) .pf-loc,body.al-left:not(.lk-av-center):not(.lk-av-right) .pf-actions{justify-content:flex-start}
body.pt-lines::before{background-image:repeating-linear-gradient(0deg,rgba(var(--fg),.07) 0 1px,transparent 1px 9px)}
body.pt-cross::before{background-image:linear-gradient(rgba(var(--fg),.12) 1px,transparent 1px),linear-gradient(90deg,rgba(var(--fg),.12) 1px,transparent 1px);background-size:44px 44px;background-position:center}
body.pt-bigdots::before{background-image:radial-gradient(rgba(var(--fg),.14) 4px,transparent 4.5px);background-size:38px 38px}

/* starter looks */
.presets{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.presets .pv{position:relative;height:auto;padding:0;display:flex;flex-direction:column;overflow:hidden;text-align:left;background:var(--surface);border:1px solid var(--line2);border-radius:10px;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:border-color .2s,box-shadow .2s,transform .15s}
.presets .pv .pvm{position:relative;display:block;height:82px;width:100%;overflow:hidden;border-bottom:1px solid var(--line)}
.pvm i{position:absolute;display:block}
.presets .pv .pvn{display:block;text-align:left;padding:9px 11px 10px;font-size:.7rem;font-weight:600;line-height:1.2;color:var(--ui-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.presets .pv:active{transform:scale(.97)}
.presets .pv.on{border-color:var(--ac);box-shadow:0 0 0 2px var(--ac)}
.presets .pv.on::after{content:'\2713';font-family:system-ui,sans-serif;font-weight:800;position:absolute;top:6px;right:6px;width:20px;height:20px;border-radius:50%;background:var(--ac);color:var(--on-ac);display:flex;align-items:center;justify-content:center;font-size:12px;line-height:1}
@media(hover:hover){.presets .pv:hover{border-color:var(--ac)}}
@media(max-width:560px){.presets{grid-template-columns:repeat(2,minmax(0,1fr))}.presets .pv .pvn{font-size:.76rem;padding:10px 12px 11px}}

/* ═══ MOBILE POLISH ═══ */
button,a,label,summary{touch-action:manipulation}
@media(max-width:800px){
  .lane{padding-bottom:calc(84px + env(safe-area-inset-bottom,0px));overflow-x:clip}
  .pill{height:42px;padding:0 18px;font-size:.8rem}
  .tb-btn{height:36px;padding:0 12px;font-size:.74rem}
  .seg span{height:40px;font-size:.72rem}
  .secrow{padding:9px 10px}.secrow button{width:38px;height:38px}
  .sw span{width:40px;height:40px}.sws.bgs .sw span{width:52px;height:40px}
  .dg>summary{padding:16px 0;font-size:.74rem}
  .btn{height:46px;font-size:.86rem}
  .field input[type=text],.field input[type=url],.field input[type=email],.field textarea,.rowed input,.rowed textarea{font-size:16px;padding:12px 13px}
  .pf-name .nm{white-space:normal;overflow:visible;text-align:center;overflow-wrap:anywhere}
  .pf-headline{font-size:.92rem}
  .bio{font-size:.92rem;line-height:1.7}
  .proj-title{font-size:1rem}.proj-desc{font-size:.84rem;line-height:1.6}
  .exp-t{font-size:.82rem}
  body.lk-pj-carousel .projects{gap:12px;scroll-padding-left:var(--pad);padding-left:var(--pad);padding-right:var(--pad);-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain}
  body.lk-pj-carousel .proj{flex:0 0 84%}
  .sh-card{max-height:94vh;max-height:94dvh;overscroll-behavior:contain;border-radius:18px 18px 0 0}
  body[class*=" hv-"] .proj:hover,body[class*=" hv-"] .lnk:hover,body[class*=" hv-"] .pill:hover{transform:none;box-shadow:none}
  body.hv-lift .proj:active,body.hv-lift .lnk:active,body.hv-glow .proj:active,body.hv-glow .lnk:active,body.hv-grow .proj:active,body.hv-grow .lnk:active,body.hv-tilt .proj:active,body.hv-tilt .lnk:active,body.hv-shine .proj:active,body.hv-tilt3d .proj:active,body.hv-spot .proj:active{transform:scale(.975);transition:transform .12s}
}
@media(max-width:360px){.pill{padding:0 14px}.presets{grid-template-columns:repeat(2,1fr)}}

/* explainer + public/private note */
.pf-why{background:var(--surface);border:1px solid var(--line2);border-radius:12px;padding:14px 15px;color:var(--sub);font-size:.78rem;line-height:1.65}
.pf-why p{margin:0 0 8px}.pf-why p:last-child{margin-bottom:0}
.pf-why b{color:var(--ui-text)}
.pf-why-h{display:flex;align-items:center;gap:7px;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ui-text);margin-bottom:8px}
.pf-why-h i,.pf-why-d>summary i{color:var(--ac);font-size:16px}
.pf-why-d{margin:0 0 14px}
.pf-why-d>summary{list-style:none;display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.72rem;font-weight:700;color:var(--ui-text);padding:11px 13px;background:var(--surface);border:1px solid var(--line2);border-radius:10px}
.pf-why-d>summary::-webkit-details-marker{display:none}
.pf-why-d>summary::after{content:'+';margin-left:auto;font-size:1.1rem;color:var(--muted)}
.pf-why-d[open]>summary::after{content:'–'}
.pf-why-d[open]>summary{border-bottom-left-radius:0;border-bottom-right-radius:0}
.pf-why-d .pf-why{border-top:0;border-top-left-radius:0;border-top-right-radius:0}
.pf-why-d .pf-why-h{display:none}
.pub-note{margin-top:10px}
.pub-note .pn{display:none;gap:9px;align-items:flex-start;padding:10px 12px;border-radius:8px;font-size:.72rem;line-height:1.55;color:var(--sub);border:1px solid var(--line2);background:var(--surface)}
.pub-note .pn i{font-size:16px;margin-top:1px;flex-shrink:0}
.pub-note .pn b{color:var(--ui-text)}
.pub-note .pn.on i{color:var(--ok)}
.pub-note .pn.off i{color:var(--muted)}
#pubChk:checked~.pub-note .on,.pub-note.is-on .on{display:flex}
.pub-note:not(.is-on) .off{display:flex}

/* ═══ MOBILE POLISH — edit sheet ═══ */
.sh-foot{display:flex;gap:8px;flex-shrink:0}
.sh-peek{flex:0 0 auto;display:none;align-items:center;justify-content:center;gap:6px;padding:0 16px;border:0;border-radius:7px;background:var(--sh-card);color:rgba(var(--fg),.9);font-size:.8rem;font-weight:600;white-space:nowrap}
.sh-peek i{color:var(--ac);font-size:16px}
.sh-foot .sh-close{flex:1 1 auto;width:auto}
.sh-overlay.peek{background:transparent;backdrop-filter:none;-webkit-backdrop-filter:none;pointer-events:none}
.sh-overlay.peek .sh-card{pointer-events:auto;animation:none;box-shadow:0 -6px 24px rgba(0,0,0,.35)}
.sh-overlay.peek .sh-inner,.sh-overlay.peek .sh-handle{display:none}
@media(max-width:800px){
  .sh-peek{display:inline-flex}
  .sh-tab{height:38px}
  .sh-body{-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
  /* Save stays in reach instead of sitting at the bottom of a very long list */
  .sh-pane .save{position:sticky;bottom:-16px;z-index:5;width:100%;margin:14px 0 0;box-shadow:0 0 0 8px var(--sh-card)}
  /* starter looks: one swipeable row instead of eleven rows of cards */
  .presets{display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain;margin:0 -13px;padding:2px 13px 12px;scrollbar-width:none}
  .presets::-webkit-scrollbar{display:none}
  .presets .pv{flex:0 0 148px;scroll-snap-align:start}
  .ar-b{min-width:40px;justify-content:center}
}
@media(max-width:360px){.presets .pv{flex-basis:132px}}


body.fx-bh .pf-banner{height:var(--bhpx)!important;min-height:0!important}
body.fx-bh.lk-nob .pf-banner{display:none}
body.fx-ao .pf-id{margin-top:calc(var(--avs) * var(--avo) * -1)!important}
body.fx-ns .pf-name{font-size:var(--nspx)!important}
body.fx-bd .pf-av{border-width:var(--avbd)!important}
.tune{margin-bottom:14px}
.tune .th{display:flex;align-items:center;gap:8px;font-size:.64rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.tune .th b{margin-left:auto;color:var(--ui-text);font-size:.72rem;letter-spacing:0;text-transform:none}
.tune .th button{border:1px solid var(--line2);background:transparent;color:var(--ui-text);border-radius:5px;height:24px;padding:0 9px;font-size:.62rem;font-weight:600}
.tune input[type=range]{width:100%;height:34px;accent-color:var(--ac);background:none}

/* ═══ PROJECT (WORK) PAGE — a full-screen page styled by the portfolio's own look ═══ */
.pm-ov{position:fixed;inset:0;z-index:9000;display:none;justify-content:center;align-items:flex-start;background:var(--solid);background-image:var(--pbg);color:var(--text);overflow-y:auto;overscroll-behavior:contain}
.pm-ov.open{display:flex;animation:pmIn .4s cubic-bezier(.22,1,.36,1) both}
@keyframes pmIn{from{opacity:0;transform:translateY(34px) scale(.985)}to{opacity:1;transform:none}}
@keyframes pmUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.pm-lane{width:100%;max-width:640px;min-height:100%;display:flex;flex-direction:column;position:relative}
@media(min-width:801px){.pm-lane{border-left:1px solid var(--line);border-right:1px solid var(--line)}}
.pm-bar{position:sticky;top:0;z-index:60;height:54px;margin-bottom:-54px;flex-shrink:0;pointer-events:none}
.pm-bar::before{content:'';position:absolute;inset:0;background:var(--solid);border-bottom:1px solid var(--line);opacity:0;transition:opacity .28s}
.pm-bar.scrolled::before{opacity:1}
.pm-ib{position:absolute;top:7px;width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.35);border:0;border-radius:50%;color:#fff;font-size:1.25rem;pointer-events:auto;z-index:2;transition:background .2s,transform .15s,color .2s;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.pm-ib:hover{background:var(--ac);color:var(--on-ac)}.pm-ib:active{transform:scale(.9)}
.pm-ib.back{left:8px}.pm-ib.share{right:8px}
.pm-bar.scrolled .pm-ib{background:rgba(var(--fg),.1);color:var(--ui-text);backdrop-filter:none}
.pm-bar.scrolled .pm-ib:hover{background:var(--ac);color:var(--on-ac)}
.pm-bar-t{position:absolute;top:0;left:56px;right:56px;height:54px;display:flex;align-items:center;font-size:.88rem;font-weight:700;opacity:0;transform:translateY(4px);transition:opacity .28s,transform .28s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;pointer-events:none;z-index:1}
.pm-bar-t.show{opacity:1;transform:none}
.pm-hero{position:relative;flex-shrink:0;background:#0b0b12;overflow:hidden}
.pm-slides{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;-webkit-overflow-scrolling:touch;aspect-ratio:4/3;max-height:78vh;width:100%}
.pm-slides::-webkit-scrollbar{display:none}
.pm-slide{flex:0 0 100%;scroll-snap-align:center;display:flex;align-items:center;justify-content:center;height:100%}
.pm-slide img,.pm-slide video{width:100%;height:100%;object-fit:contain;display:block;background:#0b0b12}
.pm-slide img{cursor:zoom-in}
.pm-emb{width:100%;height:100%}.pm-emb iframe{width:100%;height:100%;border:0;display:block}
.pm-noimg{height:clamp(220px,60vw,320px);display:flex;align-items:center;justify-content:center;background:radial-gradient(circle at 25% 20%,var(--ac-glow),transparent 55%),radial-gradient(circle at 80% 85%,var(--ac-dim),transparent 50%),#0d1030}
.pm-noimg span{font-size:5rem;font-weight:700;color:var(--ac);line-height:1}
.pm-arr{position:absolute;top:50%;width:40px;height:40px;margin-top:-20px;border:0;border-radius:50%;background:rgba(0,0,0,.45);color:#fff;font-size:1.3rem;display:flex;align-items:center;justify-content:center;z-index:5;transition:background .2s,transform .15s;backdrop-filter:blur(6px)}
.pm-arr:hover{background:var(--ac);color:var(--on-ac)}.pm-arr:active{transform:scale(.9)}
.pm-arr.p{left:10px}.pm-arr.n{right:10px}
@media(hover:none){.pm-arr{display:none}}
.pm-count{position:absolute;right:12px;bottom:12px;padding:3px 10px;border-radius:99px;background:rgba(0,0,0,.62);color:#fff;font-size:.66rem;font-weight:600}
.pm-dots{position:absolute;left:0;right:0;bottom:14px;display:flex;justify-content:center;gap:5px;pointer-events:none}
.pm-dots i{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.45);transition:background .2s,transform .2s}
.pm-dots i.on{background:#fff;transform:scale(1.3)}
.pm-thumbs{display:flex;gap:8px;overflow-x:auto;padding:10px var(--pad);scrollbar-width:none}
.pm-thumbs::-webkit-scrollbar{display:none}
.pm-th{flex:0 0 60px;height:60px;border-radius:var(--r);overflow:hidden;border:2px solid transparent;opacity:.55;background:var(--ph);padding:0;display:flex;align-items:center;justify-content:center;color:var(--ac);font-size:22px;transition:opacity .2s,border-color .2s,transform .15s}
.pm-th img{width:100%;height:100%;object-fit:cover;display:block}
.pm-th.on{opacity:1;border-color:var(--ac)}.pm-th:active{transform:scale(.94)}
.pm-body{padding:clamp(14px,4vw,22px) var(--pad) 48px}
.pm-body>*{animation:pmUp .55s cubic-bezier(.22,1,.36,1) both;animation-delay:calc(var(--i,0) * 55ms)}
.pm-kick{font-size:.66rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--ac);margin-bottom:6px}
.pm-title{font-size:clamp(1.3rem,5.2vw,1.8rem);font-weight:700;line-height:1.15;letter-spacing:-.3px;overflow-wrap:anywhere;margin-bottom:12px}
.pm-chips{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px}
.pm-chip{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 11px;border:1px solid var(--line2);border-radius:var(--r);font-size:.72rem;color:var(--sub);white-space:nowrap}
.pm-chip i{color:var(--ac);font-size:.85rem}
.pm-acts{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.pm-acts .pill{height:40px;flex:1;justify-content:center;min-width:120px}
.pm-lead{font-size:.9rem;line-height:1.65;color:var(--text);margin-bottom:6px;overflow-wrap:anywhere}
.pm-h{display:flex;align-items:center;gap:8px;margin:24px 0 10px;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted)}
.pm-h i{color:var(--ac);font-size:.95rem}
.pm-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--card-r);overflow:hidden}
.pm-story{padding:16px;font-size:.86rem;line-height:1.8;color:var(--sub);white-space:pre-line;overflow-wrap:anywhere}
.pm-story.clamp{display:-webkit-box;-webkit-line-clamp:9;-webkit-box-orient:vertical;overflow:hidden}
.pm-more{display:block;width:100%;padding:11px;border:0;border-top:1px solid var(--line);background:none;color:var(--ui-text);font-size:.74rem;font-weight:700;letter-spacing:.3px}
.pm-more:hover{color:var(--ac)}
.pm-nav{display:flex;gap:10px;margin-top:28px}
.pm-nb{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px;padding:12px 14px;border:1px solid var(--line);border-radius:var(--card-r);background:var(--surface);color:var(--ui-text);text-align:left;transition:border-color .2s,transform .2s}
.pm-nb:hover{border-color:var(--ac);transform:translateY(-2px)}
.pm-nb small{font-size:.58rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted)}
.pm-nb b{font-size:.78rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pm-nb.n{text-align:right}
.pm-nb[disabled]{opacity:.3;pointer-events:none}

/* ═══ PIXEL ART (readable pixel font, stepped frames, hard shadows) ═══ */
body.era-pixel{--r:0px;--card-r:0px;--avr:0;--font:'Pixelify Sans','Courier New',monospace}
body.era-pixel .pf-av{image-rendering:pixelated;border-width:4px;box-shadow:var(--pxs)}
body.era-pixel .pf-banner{border-bottom:4px solid var(--pxb)}
body.era-pixel .proj,body.era-pixel .lnk,body.era-pixel.lk-ex-cards .exp,body.era-pixel.lk-sk-boxes .skills li,body.era-pixel .pm-card,body.era-pixel .pm-nb,
body.cd-pixel .proj,body.cd-pixel .lnk,body.cd-pixel.lk-ex-cards .exp,body.cd-pixel.tpl-bento .pf-s:not([data-k=header]),body.cd-pixel .pm-card{
  border:none;border-radius:0;background:var(--surface);backdrop-filter:none;-webkit-backdrop-filter:none;box-shadow:var(--pxs),8px 8px 0 0 rgba(0,0,0,.3);margin:4px}
body.era-pixel .pm-card,body.cd-pixel .pm-card{margin:4px 4px 8px}
body.era-pixel .pill,body.era-pixel .tb-btn,body.era-pixel .btn,body.era-pixel .pm-ib{border-radius:0;box-shadow:var(--pxs);margin:3px;backdrop-filter:none}
body.era-pixel .pill:active,body.era-pixel .tb-btn:active{transform:translate(3px,3px);box-shadow:none}
body.era-pixel .pill.red:hover{box-shadow:var(--pxs),0 0 18px var(--ac-glow)}
body.era-pixel .sec-label,body.era-pixel .pm-h{color:var(--ac);font-size:.92rem;letter-spacing:2px}
body.era-pixel .sec-label::before{content:'> '}
body.era-pixel .pf-status{border-radius:0;border:none;box-shadow:var(--pxs);margin:4px}
body.era-pixel .pf-status::before{border-radius:0;box-shadow:none}
body.era-pixel.lk-ex-timeline .exp::before{border-radius:0;box-shadow:none}
body.era-pixel .pm-chip{border-radius:0;border:none;box-shadow:var(--pxs);margin:3px}
body.era-pixel .pm-th{border-radius:0}
body.lk-hd-pixel .sec-label{display:table;margin:var(--gap) var(--pad) 14px;padding:5px 12px;background:var(--ac);color:var(--on-ac);box-shadow:var(--pxs);font-size:.74rem;letter-spacing:1px}
body.era-pixel.lk-hd-pixel .sec-label{color:var(--on-ac);font-size:.9rem}
body.era-pixel.lk-hd-pixel .sec-label::before{content:''}
body.px-fine .pf-banner,body.px-fine .pf-av,body.px-fine .proj-img,body.px-fine .pm-slide img,body.px-chunky .pf-banner,body.px-chunky .pf-av,body.px-chunky .proj-img,body.px-chunky .pm-slide img{image-rendering:pixelated}
/* Arcade windows: every section is a pixel window with a title bar */
body.tpl-arcade .pf-s:not([data-k=header]){background:var(--surface);box-shadow:var(--pxs),8px 8px 0 0 rgba(0,0,0,.3);margin:4px;padding-bottom:18px;overflow:hidden}
body.tpl-arcade .pf-s[data-k=header]{box-shadow:var(--pxs),8px 8px 0 0 rgba(0,0,0,.3);margin:4px;overflow:hidden}
body.tpl-arcade .sec-label{background:var(--ac);color:var(--on-ac);margin:0 0 14px;padding:6px 12px;font-size:.72rem;display:block}
body.tpl-arcade .sec-label::before{content:'■ '}

/* ═══ ARRANGE MODE (owner only, hands-on) ═══ */
body.arranging .page{display:flex!important;flex-direction:column;gap:0!important;padding:10px 0 0!important}
body.arranging .pf-s{position:relative!important;grid-column:auto!important;width:auto!important;margin:0 10px 16px!important;outline:2px dashed var(--ac-bd);outline-offset:3px}
body.arranging .pf-s:not([data-k=header]){padding-top:38px!important}
body.arranging .pf-s[data-k=header]{top:auto!important;height:auto!important}
body.arranging .pf-hd{height:auto!important}
body.arranging.tpl-split .pf-banner{height:clamp(330px,66vw,520px)}
body.arranging.fx-bh .pf-banner{height:var(--bhpx)!important}
body.arranging .pf-s.off{display:block!important;opacity:.4}
body.arranging .pf-s.dragging{z-index:70!important;opacity:.96;outline-style:solid;box-shadow:0 26px 60px rgba(0,0,0,.5);transition:none!important}
body.arranging .rv{opacity:1!important;transform:none!important;clip-path:none!important;filter:none!important}
body.arranging .proj,body.arranging .lnk,body.arranging .pill{cursor:default}
body.ar-drag{user-select:none;-webkit-user-select:none;cursor:grabbing}
body.ar-drag *{cursor:grabbing!important}
.ar-bar{position:absolute;top:0;left:0;z-index:50;display:flex;align-items:center;gap:4px;padding:4px;background:var(--ac);color:var(--on-ac);font-size:.64rem;font-weight:700;letter-spacing:.4px;text-transform:uppercase;border-radius:0 0 10px 0}
.ar-bar .ar-nm{padding:0 8px 0 4px}
.ar-bar button{width:32px;height:32px;border:0;border-radius:8px;background:rgba(0,0,0,.18);color:inherit;font-size:16px;display:flex;align-items:center;justify-content:center;touch-action:none}
.ar-bar button:hover{background:rgba(0,0,0,.32)}
.ar-bar button:disabled{opacity:.3;pointer-events:none}
.ar-bar .ar-grip{cursor:grab;width:38px}
.ar-h{position:absolute;z-index:45;display:flex;align-items:center;gap:5px;height:30px;padding:0 10px;border:2px solid var(--on-ac);border-radius:99px;background:var(--ac);color:var(--on-ac);font-size:.62rem;font-weight:700;letter-spacing:.4px;text-transform:uppercase;box-shadow:0 6px 18px rgba(0,0,0,.35);touch-action:none;cursor:grab;line-height:1}
.ar-h i{font-size:14px}
.ar-h.drag{transform:scale(1.08)}
.ar-bh{right:12px;left:auto}
.ar-ns{top:0;right:-6px}
.ar-av{right:-14px;bottom:-10px;width:30px;padding:0;justify-content:center;border-radius:50%;cursor:nwse-resize}
.ar-mv{position:absolute;inset:0;z-index:40;border-radius:var(--avr,50%);cursor:ns-resize;touch-action:none;background:rgba(0,0,0,.18);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;opacity:.85}
body.arranging .pf-av-link,body.arranging .pf-idmain{position:relative}
.ar-dock{position:fixed;left:50%;bottom:calc(14px + env(safe-area-inset-bottom,0px));transform:translate(-50%,140%);z-index:800;display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:16px;background:var(--solid);color:var(--ui-text);border:1px solid var(--line2);box-shadow:0 16px 50px rgba(0,0,0,.5);transition:transform .4s cubic-bezier(.22,1,.36,1);max-width:calc(100vw - 20px)}
.ar-dock.show{transform:translate(-50%,0)}
.ar-dock .ar-msg{font-size:.68rem;font-weight:600;color:var(--sub);padding:0 6px;line-height:1.25;max-width:150px}
.ar-b{height:38px;padding:0 14px;border:0;border-radius:10px;background:rgba(var(--fg),.1);color:var(--ui-text);font-size:.74rem;font-weight:700;display:inline-flex;align-items:center;gap:5px}
.ar-b:hover{background:rgba(var(--fg),.2)}
.ar-b.red{background:var(--ac);color:var(--on-ac)}.ar-b.red:hover{background:var(--ac-h)}
.ar-b:disabled{opacity:.5;pointer-events:none}
.pf-toast{position:fixed;left:50%;top:calc(var(--topbar-h) + 12px);transform:translate(-50%,-30px);opacity:0;z-index:99998;padding:10px 16px;border-radius:99px;background:var(--ac);color:var(--on-ac);font-size:.76rem;font-weight:700;box-shadow:0 10px 30px rgba(0,0,0,.4);transition:transform .35s cubic-bezier(.22,1,.36,1),opacity .3s;pointer-events:none;max-width:calc(100vw - 24px);text-align:center}
.pf-toast.show{transform:translate(-50%,0);opacity:1}
</style>
<style>
/* this portfolio's colours (chosen by the owner) */
:root{
  --ac:<?php echo $ac[0]; ?>;--ac-h:<?php echo $ac[1]; ?>;--ac-l:<?php echo $ac[2]; ?>;--on-ac:<?php echo $ac[3]; ?>;
  --ac-dim:<?php echo pf_rgba($ac[0], .16); ?>;--ac-bd:<?php echo pf_rgba($ac[0], .4); ?>;--ac-glow:<?php echo pf_rgba($ac[0], .34); ?>;
  --pbg:<?php echo $bgc[1]; ?>;--solid:<?php echo $bgc[2]; ?>;
}
:root[data-theme="light"]{--pbg:<?php echo $bgc[3]; ?>;--solid:<?php echo $bgc[4]; ?>}
</style>
</head>
<body class="<?php echo h($body_cls); ?>" id="pfBody"<?php $cs = pf_custom_style($look); echo $cs ? ' style="' . h($cs) . '"' : ''; ?>>

<div class="topbar"><div class="tb-in">
    <button type="button" class="tb-x" onclick="history.length>1?history.back():location.href='profile.php?user_id=<?php echo $profile_id; ?>'" aria-label="Close">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg>
    </button>
    <span class="tb-title">Portfolio</span>
    <?php if($is_own): ?>
        <?php if($can_arrange): ?><button type="button" class="tb-btn" id="arBtn"><i class="ri-drag-move-2-line"></i> Arrange</button><?php endif; ?>
        <button type="button" class="tb-btn" onclick="openEdit()"><i class="ri-edit-line"></i> Edit</button>
    <?php endif; ?>
    <div class="pf-prog" id="pfProg"></div>
</div></div>

<div class="lane"><div class="page">
<?php if(!$visible): ?>
    <div class="empty" style="margin-top:40px">
        <i class="<?php echo $pf ? 'ri-lock-line' : 'ri-briefcase-line'; ?>"></i>
        <span><?php
            if($is_own) echo 'You haven’t created your portfolio yet. Add your work, pick a look and arrange it your way.';
            elseif($pf) echo 'This portfolio is private.';
            else echo 'No portfolio here yet.';
        ?></span>
        <?php if($is_own): ?><button type="button" class="pill red" onclick="openEdit()"><i class="ri-add-line"></i> Create portfolio</button>
        <?php echo str_replace('class="pf-why"', 'class="pf-why" style="text-align:left;max-width:460px;margin-top:14px"', '<div class="pf-why">    <div class="pf-why-h"><i class="ri-lightbulb-flash-line"></i> Why this page exists</div>    <p>A portfolio website normally means paying for a domain name, paying for hosting every month, and paying a designer or developer to build it and keep it updated. That is out of reach for a lot of talented people.</p>    <p><b>Dezzme Portfolio is built for them.</b> You get a proper, good-looking portfolio page without paying for hosting or hiring anyone: write your story, add your work, pick a look (or start from a ready-made one) and arrange it your way. No code, no bills.</p>    <p>Share the link with clients, employers or collaborators, and your Dezzme profile can point straight to it.</p></div>'); ?><?php endif; ?>
    </div>
<?php else: ?>

    <!-- HEADER -->
    <?php echo pf_open('header', $look, $ordmap); ?>
      <div class="pf-hd">
        <div class="pf-banner<?php echo $banner_url ? ' has-img' : ''; ?>" id="pfBanner"<?php echo $banner_url ? ' style="background-image:url(\'' . h($banner_url) . '\')"' : ''; ?>>
            <?php if(!$banner_url): ?><i class="ri-briefcase-4-fill pf-banner-ic"></i><?php endif; ?>
        </div>
        <div class="pf-id">
            <div class="pf-av-link"><img class="pf-av" id="pfAv" src="<?php echo $has_pic ? h($av_src) : $GIF; ?>" alt=""></div>
            <div class="pf-idmain">
                <div class="pf-name"><span class="nm"><?php echo $display_name; ?></span></div>
                <?php if(($pf['status'] ?? '') !== ''): ?><div class="pf-status"><?php echo h($pf['status']); ?></div><?php endif; ?>
                <?php if(($pf['headline'] ?? '') !== ''): ?><p class="pf-headline"><span><?php echo h($pf['headline']); ?></span></p><?php endif; ?>
                <?php if(($pf['location'] ?? '') !== ''): ?><div class="pf-loc"><i class="ri-map-pin-2-line"></i><?php echo h($pf['location']); ?></div><?php endif; ?>
                <div class="pf-actions">
                    <?php if(($pf['contact_email'] ?? '') !== ''): ?><a class="pill red" href="mailto:<?php echo h($pf['contact_email']); ?>"><i class="ri-mail-line"></i> Contact</a><?php endif; ?>
                    <?php if(safe_url($pf['website'] ?? '')): ?><a class="pill" href="<?php echo h($pf['website']); ?>" target="_blank" rel="noopener noreferrer"><i class="ri-global-line"></i> Website</a><?php endif; ?>
                </div>
            </div>
        </div>
      </div>
    </div>

    <!-- ABOUT -->
    <?php if(trim((string)($pf['bio'] ?? '')) !== ''): echo pf_open('about', $look, $ordmap); ?>
        <div class="sec-label">About</div>
        <p class="bio"><?php echo h($pf['bio']); ?></p>
    </div><?php endif; ?>

    <!-- SKILLS -->
    <?php if($skills): echo pf_open('skills', $look, $ordmap); ?>
        <div class="sec-label">Skills</div>
        <ul class="skills"><?php foreach($skills as $s): ?><li><?php echo h($s); ?></li><?php endforeach; ?></ul>
    </div><?php endif; ?>

    <!-- EXPERIENCE -->
    <?php if($exps): echo pf_open('experience', $look, $ordmap); ?>
        <div class="sec-label">Experience</div>
        <div class="exps">
        <?php foreach($exps as $e): ?>
            <div class="exp">
                <?php if($e['p'] !== ''): ?><div class="exp-p"><?php echo h($e['p']); ?></div><?php endif; ?>
                <div class="exp-m">
                    <div class="exp-r"><?php echo h($e['r']); ?></div>
                    <?php if($e['o'] !== ''): ?><div class="exp-o"><?php echo h($e['o']); ?></div><?php endif; ?>
                    <?php if($e['t'] !== ''): ?><p class="exp-t"><?php echo h($e['t']); ?></p><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div><?php endif; ?>

    <!-- WORK -->
    <?php echo pf_open('work', $look, $ordmap); ?>
        <div class="sec-label">Work</div>
        <?php if(!$projects): ?>
            <div class="empty"><i class="ri-folder-open-line"></i><span><?php echo $is_own ? 'Add your first project so people can see your work.' : 'No projects yet.'; ?></span>
            <?php if($is_own): ?><button type="button" class="pill red" onclick="openEdit('projects')"><i class="ri-add-line"></i> Add a project</button><?php endif; ?></div>
        <?php else: ?>
            <div class="projects">
            <?php foreach($projects as $p): $n = count($p['gal']); $c0 = $p['gal'][0] ?? null;
                $meta = trim($p['p_role'] . ($p['p_role'] !== '' && $p['p_year'] !== '' ? ' · ' : '') . $p['p_year']); ?>
                <article class="proj" data-pj="<?php echo (int)$p['id']; ?>" tabindex="0" role="button" aria-label="Open <?php echo h($p['title']); ?>">
                    <?php if($c0): ?>
                    <div class="proj-gal">
                        <div class="proj-strip">
                        <?php if($c0['kind'] === 'image'): ?><img class="proj-img" src="<?php echo h($c0['src']); ?>" loading="lazy" alt="<?php echo h($p['title']); ?>">
                        <?php elseif($c0['kind'] === 'video'): ?><video class="proj-img" src="<?php echo h($c0['src']); ?>#t=0.1" muted playsinline preload="metadata"></video>
                        <?php elseif($c0['thumb'] !== ''): ?><img class="proj-img" src="<?php echo h($c0['thumb']); ?>" loading="lazy" alt="<?php echo h($p['title']); ?>">
                        <?php else: ?><div class="proj-img proj-ph"><i class="ri-play-circle-line"></i></div><?php endif; ?>
                        </div>
                        <?php if($n > 1): ?><span class="proj-ct"><i class="ri-image-2-line"></i><?php echo $n; ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="proj-body">
                        <h3 class="proj-title"><?php echo h($p['title']); ?></h3>
                        <?php if($meta !== ''): ?><div class="proj-meta"><?php echo h($meta); ?></div><?php endif; ?>
                        <?php if($p['description'] !== ''): ?><p class="proj-desc"><?php echo h($p['description']); ?></p><?php endif; ?>
                        <span class="proj-link">View project <i class="ri-arrow-right-line"></i></span>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- LINKS (optional) -->
    <?php if($links): echo pf_open('links', $look, $ordmap); ?>
        <div class="sec-label">Links</div>
        <div class="lnks">
        <?php foreach($links as $l): ?>
            <a class="lnk" title="<?php echo h($l['l']); ?>" href="<?php echo h($l['u']); ?>" target="_blank" rel="noopener noreferrer nofollow"><i class="<?php echo pf_link_icon($l['u']); ?>"></i><span><?php echo h($l['l']); ?></span><i class="ri-arrow-right-up-line"></i></a>
        <?php endforeach; ?>
        </div>
    </div><?php endif; ?>

<?php endif; ?>
</div>
<div class="retro-only"><hr><p>★ This page is best viewed in any browser ★</p><p><a href="#" onclick="window.scrollTo(0,0);return false">Back to top</a></p></div>
</div>

<!-- work page (filled by script) -->
<div class="pm-ov" id="pmOv" role="dialog" aria-modal="true" aria-label="Project"><div class="pm-lane" id="pmLane"></div></div>

<!-- photo viewer -->
<div class="lb" id="lb" onclick="if(event.target===this)pfLbClose()">
    <button type="button" class="x" onclick="pfLbClose()" aria-label="Close"><i class="ri-close-line"></i></button>
    <button type="button" class="p" onclick="pfLbStep(-1)" aria-label="Previous"><i class="ri-arrow-left-s-line"></i></button>
    <img id="lbImg" alt="">
    <button type="button" class="n" onclick="pfLbStep(1)" aria-label="Next"><i class="ri-arrow-right-s-line"></i></button>
</div>
<div class="pf-toast" id="pfToast"></div>

<?php if($is_own): ?>
<?php if($can_arrange): ?>
<div class="ar-dock" id="arDock">
    <span class="ar-msg">Drag to arrange. Save when you’re done.</span>
    <button type="button" class="ar-b" id="arReset"><i class="ri-restart-line"></i></button>
    <button type="button" class="ar-b" id="arCancel">Cancel</button>
    <button type="button" class="ar-b red" id="arSave"><i class="ri-check-line"></i> Save</button>
</div>
<?php endif; ?>

<!-- ══ EDIT OVERLAY ══ -->
<div class="sh-overlay" id="editSheet" onclick="if(event.target===this)closeEdit()">
    <div class="sh-card" role="dialog" aria-modal="true" aria-label="Edit portfolio">
        <div class="sh-handle"></div>
        <div class="sh-inner">
            <div class="sh-title">Edit portfolio</div>
            <div class="sh-tabs">
                <button type="button" class="sh-tab on" data-tab="details" onclick="editTab('details')">Details</button>
                <button type="button" class="sh-tab" data-tab="design" onclick="editTab('design')">Design</button>
                <button type="button" class="sh-tab" data-tab="projects" onclick="editTab('projects')">Projects <?php echo count($projects); ?>/<?php echo MAX_PROJECTS; ?></button>
            </div>
            <div class="sh-body">

              <form method="post" enctype="multipart/form-data" id="pfForm" onsubmit="return pfCollect()">
                <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
                <input type="hidden" name="action" value="save_profile">
                <input type="hidden" name="from_tab" id="fromTab" value="details">
                <input type="hidden" name="links_json" id="linksJson">
                <input type="hidden" name="exp_json" id="expJson">
                <input type="hidden" name="order" id="orderIn">
                <input type="hidden" name="hidden" id="hiddenIn">
                <input type="hidden" name="remove_avatar" id="rmAv" value="">

                <!-- DETAILS -->
                <div class="sh-pane on" id="pane-details">
                    <details class="pf-why-d"><summary><i class="ri-lightbulb-flash-line"></i> Why this page exists</summary><div class="pf-why">
    <div class="pf-why-h"><i class="ri-lightbulb-flash-line"></i> Why this page exists</div>
    <p>A portfolio website normally means paying for a domain name, paying for hosting every month, and paying a designer or developer to build it and keep it updated. That is out of reach for a lot of talented people.</p>
    <p><b>Dezzme Portfolio is built for them.</b> You get a proper, good-looking portfolio page without paying for hosting or hiring anyone: write your story, add your work, pick a look (or start from a ready-made one) and arrange it your way. No code, no bills.</p>
    <p>Share the link with clients, employers or collaborators, and your Dezzme profile can point straight to it.</p>
</div></details>
                    <?php if($flash && $flash['tab'] === 'details'): ?>
                        <?php if($flash['ok']): ?><div class="notice ok"><?php echo h($flash['ok']); ?></div><?php endif; ?>
                        <?php if($flash['bad']): ?><div class="notice err"><?php echo h($flash['bad']); ?></div><?php endif; ?>
                    <?php endif; ?>
                    <div class="grp">
                        <div class="grp-h"><i class="ri-briefcase-line"></i> About you and your work</div>
                        <div class="field"><label for="f_pname">Name or studio</label>
                            <input type="text" id="f_pname" name="pname" maxlength="60" value="<?php echo h($pf_form['pname']); ?>" placeholder="<?php echo h($user['username']); ?>">
                            <small>Shown at the top of your portfolio. Leave empty to use your username.</small></div>
                        <div class="field"><label for="f_headline">Headline</label>
                            <input type="text" id="f_headline" name="headline" maxlength="120" value="<?php echo h($pf_form['headline']); ?>" placeholder="Photographer, welder, tutor, developer… whatever you are"></div>
                        <div class="field"><label for="f_status">Status tag <span style="text-transform:none;opacity:.7">(optional)</span></label>
                            <input type="text" id="f_status" name="status" maxlength="60" value="<?php echo h($pf_form['status']); ?>" placeholder="Open to work · Booking for December"></div>
                        <div class="field"><label for="f_location">Location <span style="text-transform:none;opacity:.7">(optional)</span></label>
                            <input type="text" id="f_location" name="location" maxlength="120" value="<?php echo h($pf_form['location']); ?>" placeholder="Lagos, Nigeria"></div>
                        <div class="field"><label for="f_bio">Bio</label>
                            <textarea id="f_bio" name="bio" maxlength="2000" placeholder="What you do, who you work with, and what you’re looking for."><?php echo h($pf_form['bio']); ?></textarea></div>
                        <div class="field" style="margin-bottom:0"><label for="f_skills">Skills</label>
                            <input type="text" id="f_skills" name="skills" maxlength="300" value="<?php echo h($pf_form['skills']); ?>" placeholder="Branding, Welding, Public speaking, Python">
                            <small>Separate skills with commas. Pick how they look in the Design tab.</small></div>
                    </div>
                    <div class="grp">
                        <div class="grp-h"><i class="ri-mail-send-line"></i> Contact <em>Optional</em></div>
                        <div class="field"><label for="f_website">Website</label>
                            <input type="url" id="f_website" name="website" value="<?php echo h($pf_form['website']); ?>" placeholder="https://"></div>
                        <div class="field" style="margin-bottom:0"><label for="f_email">Contact email</label>
                            <input type="email" id="f_email" name="contact_email" value="<?php echo h($pf_form['contact_email']); ?>" placeholder="you@example.com">
                            <small>Shown publicly on your portfolio.</small></div>
                    </div>
                    <div class="grp">
                        <div class="grp-h"><i class="ri-history-line"></i> Experience <em>Optional</em></div>
                        <div id="expBox"></div>
                        <button type="button" class="btn ghost sm" id="expAddBtn"><i class="ri-add-line"></i> Add a role</button>
                    </div>
                    <div class="grp">
                        <div class="grp-h"><i class="ri-links-line"></i> Links <em>Optional</em></div>
                        <div class="hint" style="margin:0 0 10px">Instagram, GitHub, Behance, YouTube, anything you like. Up to <?php echo MAX_LINKS; ?>. You don’t have to add any.</div>
                        <div id="lnkBox"></div>
                        <button type="button" class="btn ghost sm" id="lnkAddBtn"><i class="ri-add-line"></i> Add a link</button>
                    </div>
                    <div class="grp">
                        <label class="toggle" style="margin:0"><input type="checkbox" name="is_public" id="pubChk" <?php echo $pf_form['is_public'] ? 'checked' : ''; ?>> Make my portfolio public</label>
                        <div class="pub-note" id="pubNote">
                            <div class="pn on"><i class="ri-eye-line"></i><span><b>Public:</b> anyone can open your portfolio, and a <b>View Portfolio Page</b> button appears on your Dezzme profile (under your location) that takes people straight to it.</span></div>
                            <div class="pn off"><i class="ri-eye-off-line"></i><span><b>Private:</b> only you can see your portfolio. No button is shown on your profile, and anyone who opens the link sees that it is private.</span></div>
                        </div>
                    </div>
                    <button class="btn save" type="submit" onclick="$('fromTab').value='details'"><i class="ri-check-line"></i> Save changes</button>
                </div>

                <!-- DESIGN -->
                <div class="sh-pane" id="pane-design">
                    <?php if($flash && $flash['tab'] === 'design'): ?>
                        <?php if($flash['ok']): ?><div class="notice ok"><?php echo h($flash['ok']); ?></div><?php endif; ?>
                        <?php if($flash['bad']): ?><div class="notice err"><?php echo h($flash['bad']); ?></div><?php endif; ?>
                    <?php endif; ?>

                    <div class="grp">
                        <div class="grp-h"><i class="ri-magic-line"></i> Starter looks</div>
                        <div class="presets" id="presetBox"></div>
                        <div class="hint">Swipe to browse, tap one to start, then change anything below. Tap Preview to see the page change, and Close without saving to undo. Want to move things with your hands? Close this and tap <b>Arrange</b> at the top.</div>
                    </div>

                    <details class="dg" open>
                        <summary><i class="ri-image-line"></i> Banner and picture</summary>
                        <div>
                            <div class="sub-h" style="margin-top:0">Banner <span style="font-weight:400;opacity:.7">(optional)</span></div>
                            <div class="pv" id="pvBanner"<?php echo $banner_url ? ' style="background-image:url(\'' . h($banner_url) . '\')"' : ''; ?>><?php if(!$banner_url): ?><span>No banner · your accent colour is used</span><?php endif; ?></div>
                            <div style="display:flex;gap:8px;margin-top:8px"><span class="btn ghost sm fbtn"><i class="ri-upload-2-line"></i> Upload banner<input type="file" name="banner" id="bannerFile" accept="image/jpeg,image/png,image/webp"></span></div>
                            <?php if($banner_url): ?><label class="rmchk"><input type="checkbox" name="remove_banner" value="1"> Remove my banner when I save</label><?php endif; ?>
                            <div class="sub-h">Picture</div>
                            <div class="avrow">
                                <img id="pvAv" src="<?php echo $has_pic ? h($av_src) : $GIF; ?>" alt="">
                                <div class="acts">
                                    <span class="btn ghost sm fbtn" style="align-self:flex-start"><i class="ri-upload-2-line"></i> <?php echo $own_av !== '' ? 'Change picture' : 'Use a different picture'; ?><input type="file" name="avatar" id="avFile" accept="image/jpeg,image/png,image/webp"></span>
                                    <button type="button" class="btn ghost sm" id="avReset" style="align-self:flex-start<?php echo $own_av !== '' ? '' : ';display:none'; ?>"><i class="ri-user-line"></i> Use my profile picture</button>
                                </div>
                            </div>
                            <div class="hint">Your portfolio uses your profile picture until you pick a different one here. You can switch back to it any time. JPG, PNG or WebP, up to 5 MB.</div>
                        </div>
                    </details>

                    <details class="dg" open>
                        <summary><i class="ri-palette-line"></i> Colours</summary>
                        <div>
                            <div class="sub-h" style="margin-top:0">Accent colour</div>
                            <div class="sws" id="swAc">
                                <?php foreach(PF_ACCENTS as $k => $c): ?>
                                <label class="sw" title="<?php echo ucfirst($k); ?>"><input type="radio" name="accent" value="<?php echo $k; ?>"<?php echo $k === $accent_key ? ' checked' : ''; ?>><span style="--c:<?php echo $c[0]; ?>"><i class="ri-check-line"></i></span></label>
                                <?php endforeach; ?>
                                <label class="sw cu" title="Your own colour"><input type="radio" name="accent" value="custom"<?php echo $acc_custom ? ' checked' : ''; ?>><span><i class="ri-check-line"></i></span></label>
                                <input type="color" class="cpick" id="accCustom" name="accent_custom" value="<?php echo $acc_custom ? h($accent_key) : '#ff2d2d'; ?>" title="Pick your own accent">
                            </div>
                            <div class="sub-h">Page and top bar colour</div>
                            <div class="sws bgs" id="swBg">
                                <?php foreach(PF_BGS as $k => $b): ?>
                                <label class="sw" title="<?php echo h($b[0]); ?>"><input type="radio" name="bg" value="<?php echo $k; ?>"<?php echo $k === $bg_key ? ' checked' : ''; ?>><span style="--c:<?php echo $b[2]; ?>"><i class="ri-check-line"></i></span></label>
                                <?php endforeach; ?>
                                <label class="sw cu" title="Your own colour"><input type="radio" name="bg" value="custom"<?php echo $bg_custom ? ' checked' : ''; ?>><span style="width:46px;height:34px;border-radius:8px"><i class="ri-check-line"></i></span></label>
                                <input type="color" class="cpick" id="bgCustom" name="bg_custom" value="<?php echo $bg_custom ? h($bg_key) : '#101828'; ?>" title="Pick your own background">
                            </div>
                            <div class="hint">The top bar changes with the page colour. Light or dark text switches by itself so it always stays readable.</div>
                        </div>
                    </details>

                    <?php foreach($CTRL as $gname => $items): ?>
                    <details class="dg"<?php echo $gname === 'Look and feel' ? ' open' : ''; ?>>
                        <summary><i class="<?php echo ['Look and feel'=>'ri-layout-masonry-line','Header'=>'ri-user-smile-line','Section styles'=>'ri-list-settings-line','Page and cards'=>'ri-pages-line','Motion'=>'ri-movie-2-line'][$gname]; ?>"></i> <?php echo $gname; ?></summary>
                        <div>
                        <?php foreach($items as $i => $c): if($c[0][0] === '@'): $ck = substr($c[0], 1); ?>
                            <div class="hint" style="margin:6px 0 0;display:flex;align-items:center;gap:10px"><input type="color" class="cpick" id="lk_<?php echo $ck; ?>" name="lk_<?php echo $ck; ?>" value="<?php echo h($look[$ck]); ?>"> Pick your colour, then choose “My colour” above.</div>
                        <?php continue; endif; ?>
                            <div class="sub-h"<?php echo $i === 0 ? ' style="margin-top:0"' : ''; ?>><?php echo h($c[1]); ?></div>
                            <?php echo pf_seg('lk_' . $c[0], $c[2], $look[$c[0]]); ?>
                        <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endforeach; ?>

                    <details class="dg">
                        <summary><i class="ri-equalizer-line"></i> Fine tuning</summary>
                        <div>
                            <div class="hint" style="margin:0 0 12px">Drag to make things exactly the size you want. These always win over starter looks and layouts. Tap “Auto” to go back to the layout’s own size. You can also do all of this by hand in Arrange mode.</div>
                            <?php foreach([['avpx','Picture size',30,320,'px'],['avo','Picture overlap on banner',-60,160,'%'],['bhpx','Banner height',0,600,'px'],['nspx','Name size',12,90,'px'],['avbd','Picture border',0,14,'px']] as $t): ?>
                            <div class="tune" data-init="<?php echo h($look[$t[0]]); ?>" data-k="<?php echo $t[0]; ?>" data-u="<?php echo $t[4]; ?>">
                                <div class="th"><?php echo $t[1]; ?> <b></b><button type="button">Auto</button></div>
                                <input type="range" min="<?php echo $t[2]; ?>" max="<?php echo $t[3]; ?>" step="1" value="<?php echo $look[$t[0]] !== '' ? (int)$look[$t[0]] : (int)(($t[2] + $t[3]) / 2); ?>">
                                <input type="hidden" name="lk_<?php echo $t[0]; ?>" value="<?php echo h($look[$t[0]]); ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <details class="dg">
                        <summary><i class="ri-drag-move-2-line"></i> Section order</summary>
                        <div>
                            <div class="hint" style="margin:0 0 10px">Put your sections in any order, or hide the ones you don’t need. The header can only be first or second.</div>
                            <div id="secList"></div>
                        </div>
                    </details>
                    <button class="btn save" type="submit" onclick="$('fromTab').value='design'"><i class="ri-check-line"></i> Save design</button>
                </div>
              </form>

                <!-- PROJECTS -->
                <div class="sh-pane" id="pane-projects">
                    <?php if($flash && $flash['tab']==='projects'): ?>
                        <?php if($flash['ok']): ?><div class="notice ok"><?php echo h($flash['ok']); ?></div><?php endif; ?>
                        <?php if($flash['bad']): ?><div class="notice err"><?php echo h($flash['bad']); ?></div><?php endif; ?>
                    <?php endif; ?>
                    <form class="addbox" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
                        <input type="hidden" name="action" value="save_project">
                        <div class="grp-h"><i class="ri-add-circle-line"></i> New project</div>
                        <div class="field"><label>Title</label><input type="text" name="title" maxlength="100" required></div>
                        <div class="field"><label>Short description</label><textarea name="description" maxlength="600" style="min-height:70px" placeholder="One or two lines that show on the card."></textarea></div>
                        <div class="field"><label>The story <span style="text-transform:none;opacity:.7">(optional)</span></label><textarea name="story" maxlength="6000" style="min-height:130px" placeholder="What the job was, what you did, how it went, what you’re proud of."></textarea></div>
                        <div class="two">
                            <div class="field"><label>Your role</label><input type="text" name="p_role" maxlength="80" placeholder="Lead designer"></div>
                            <div class="field"><label>Year</label><input type="text" name="p_year" maxlength="20" placeholder="2026"></div>
                        </div>
                        <div class="field"><label>Client or place</label><input type="text" name="p_client" maxlength="80" placeholder="Optional"></div>
                        <div class="field"><label>Tools and materials</label><input type="text" name="p_tools" maxlength="200" placeholder="Figma, Oak, Arc welder, Premiere"><small>Separate with commas.</small></div>
                        <div class="field"><label>Links <span style="text-transform:none;opacity:.7">(up to <?php echo MAX_PLINKS; ?>)</span></label><textarea name="plinks" style="min-height:70px" placeholder="Live site | https://example.com&#10;https://github.com/you/repo"></textarea><small>One per line. Type a label, a “|”, then the link, or just paste the link.</small></div>
                        <div class="field"><label>Photos and videos</label><input type="file" name="media[]" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" multiple><small>Up to <?php echo MAX_PROJ_MEDIA; ?> items. The first photo is the cover. Photos up to 5 MB, videos (MP4 or WebM) up to <?php echo MAX_VIDEO_MB; ?> MB.</small></div>
                        <div class="field"><label>YouTube or Vimeo links <span style="text-transform:none;opacity:.7">(optional)</span></label><textarea name="embeds" style="min-height:56px" placeholder="https://youtu.be/…"></textarea><small>One per line. They play inside the project page.</small></div>
                        <button class="btn" type="submit"><i class="ri-add-line"></i> Add project</button>
                    </form>
                    <?php foreach($projects as $p): $cv = $p['gal'][0] ?? null;
                        $plText = ''; foreach($p['pl'] as $l) $plText .= $l['l'] . ' | ' . $l['u'] . "\n"; ?>
                    <details class="pedit">
                        <summary>
                            <?php if($cv && $cv['kind'] === 'image'): ?><img src="<?php echo h($cv['src']); ?>" alt=""><?php elseif($cv && $cv['thumb'] !== ''): ?><img src="<?php echo h($cv['thumb']); ?>" alt=""><?php else: ?><span class="noimg"><i class="<?php echo $cv ? 'ri-movie-line' : 'ri-image-line'; ?>"></i></span><?php endif; ?>
                            <span class="t"><?php echo h($p['title']); ?><small><?php echo count($p['gal']); ?> item<?php echo count($p['gal']) === 1 ? '' : 's'; ?> · tap to edit</small></span>
                            <i class="ri-arrow-down-s-line"></i>
                        </summary>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
                            <input type="hidden" name="action" value="save_project">
                            <input type="hidden" name="project_id" value="<?php echo (int)$p['id']; ?>">
                            <div class="field"><label>Title</label><input type="text" name="title" maxlength="100" required value="<?php echo h($p['title']); ?>"></div>
                            <div class="field"><label>Short description</label><textarea name="description" maxlength="600" style="min-height:70px"><?php echo h($p['description']); ?></textarea></div>
                            <div class="field"><label>The story</label><textarea name="story" maxlength="6000" style="min-height:130px"><?php echo h($p['story']); ?></textarea></div>
                            <div class="two">
                                <div class="field"><label>Your role</label><input type="text" name="p_role" maxlength="80" value="<?php echo h($p['p_role']); ?>"></div>
                                <div class="field"><label>Year</label><input type="text" name="p_year" maxlength="20" value="<?php echo h($p['p_year']); ?>"></div>
                            </div>
                            <div class="field"><label>Client or place</label><input type="text" name="p_client" maxlength="80" value="<?php echo h($p['p_client']); ?>"></div>
                            <div class="field"><label>Tools and materials</label><input type="text" name="p_tools" maxlength="200" value="<?php echo h($p['p_tools']); ?>"></div>
                            <div class="field"><label>Links</label><textarea name="plinks" style="min-height:70px" placeholder="Live site | https://example.com"><?php echo h(rtrim($plText)); ?></textarea><small>One per line: label | link</small></div>
                            <?php if($p['gal']): ?>
                            <div class="field"><label>Photos and videos · tap ✕ to remove</label>
                                <div class="thumbs">
                                <?php foreach($p['gal'] as $g): ?>
                                    <div class="thumb">
                                        <?php if($g['kind'] === 'image'): ?><img src="<?php echo h($g['src']); ?>" alt="">
                                        <?php elseif($g['kind'] === 'video'): ?><i class="ri-movie-line"></i>
                                        <?php elseif($g['thumb'] !== ''): ?><img src="<?php echo h($g['thumb']); ?>" alt="">
                                        <?php else: ?><i class="ri-play-circle-line"></i><?php endif; ?>
                                        <label><input type="checkbox" name="remove_img[]" value="<?php echo h($g['k']); ?>" onchange="this.closest('.thumb').classList.toggle('gone',this.checked)"><i class="ri-close-line"></i></label>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="field"><label>Add photos and videos</label><input type="file" name="media[]" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" multiple><small>Up to <?php echo MAX_PROJ_MEDIA; ?> items in total.</small></div>
                            <div class="field"><label>Add YouTube or Vimeo links</label><textarea name="embeds" style="min-height:56px" placeholder="https://youtu.be/…"></textarea></div>
                            <button class="btn" type="submit"><i class="ri-check-line"></i> Save project</button>
                        </form>
                        <form class="pdel" method="post" onsubmit="return confirm('Delete this project and its media?')" style="margin-top:8px">
                            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
                            <input type="hidden" name="action" value="delete_project">
                            <input type="hidden" name="project_id" value="<?php echo (int)$p['id']; ?>">
                            <button class="btn sm" type="submit"><i class="ri-delete-bin-line"></i> Delete project</button>
                        </form>
                    </details>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>
        <div class="sh-foot"><button type="button" class="sh-peek" id="pfPeekBtn" onclick="pfPeek()"><i class="ri-eye-line"></i><span>Preview</span></button><button type="button" class="sh-close" onclick="closeEdit()">Close</button></div>
    </div>
</div>
<?php endif; ?>

<script>
var PF = <?php echo json_encode($js, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR); ?>;
function $(id){ return document.getElementById(id); }
function mk(tag, cls, txt){ var e = document.createElement(tag); if(cls) e.className = cls; if(txt != null) e.textContent = txt; return e; }
function isArr(){ return document.body.classList.contains('arranging'); }
var toastT = null;
function toast(m){ var t = $('pfToast'); t.textContent = m; t.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(function(){ t.classList.remove('show'); }, 2600); }

/* ══ entrance animations (for everyone) ══ */
var REV_SEL = '.pf-banner,.pf-av-link,.pf-idmain,.pf-s:not([data-k=header]) .sec-label,.bio,.skills li,.exp,.proj,.lnk';
var revIO = null;
function revInit(){
    var els = document.querySelectorAll(REV_SEL);
    if(revIO){ revIO.disconnect(); revIO = null; }
    [].forEach.call(els, function(el){ el.classList.remove('rv', 'in'); });
    var off = /\ban-none\b/.test(document.body.className) || !('IntersectionObserver' in window) || (window.matchMedia && matchMedia('(prefers-reduced-motion:reduce)').matches);
    if(off) return;
    revIO = new IntersectionObserver(function(es){
        es.forEach(function(e){
            if(!e.isIntersecting) return;
            var el = e.target; revIO.unobserve(el); void el.offsetWidth; el.classList.add('in');
            setTimeout(function(){ el.classList.remove('rv', 'in'); }, 1500 + (parseInt(el.style.getPropertyValue('--d')) || 0));
        });
    }, {threshold: .08});
    [].forEach.call(els, function(el){
        var idx = [].indexOf.call(el.parentNode.children, el);
        el.style.setProperty('--d', Math.min(idx, 8) * 70 + 'ms');
        el.classList.add('rv'); revIO.observe(el);
    });
}
revInit();

/* ══ pixelate pictures (still clearly visible) ══ */
var PXW = {fine: 110, chunky: 46}, pxCache = {};
function pxMake(src, w, cb){
    var k = src + '|' + w; if(pxCache[k]) return cb(pxCache[k]);
    var im = new Image(); im.crossOrigin = 'anonymous';
    im.onload = function(){
        try{
            var h = Math.max(1, Math.round(w * im.naturalHeight / Math.max(im.naturalWidth, 1))), c = document.createElement('canvas');
            c.width = w; c.height = h; c.getContext('2d').drawImage(im, 0, 0, w, h);
            pxCache[k] = c.toDataURL('image/png'); cb(pxCache[k]);
        }catch(e){ cb(null); }
    };
    im.onerror = function(){ cb(null); }; im.src = src;
}
function pxApply(){
    var m = /\bpx-(fine|chunky)\b/.exec(document.body.className), w = m ? PXW[m[1]] : 0;
    [].forEach.call(document.querySelectorAll('#pfBanner.has-img,#pfAv,img.proj-img'), function(el){
        var bg = el.id === 'pfBanner', orig = el.dataset.o;
        if(!orig){ orig = bg ? el.style.backgroundImage.replace(/^url\(["']?|["']?\)$/g, '') : el.getAttribute('src'); el.dataset.o = orig || ''; }
        if(!orig || orig.indexOf('data:') === 0) return;
        var set = function(u){ if(bg) el.style.backgroundImage = 'url("' + u + '")'; else el.src = u; };
        if(!w) set(orig); else pxMake(orig, w, function(u){ if(u) set(u); });
    });
}
pxApply();

/* ══ photo viewer ══ */
var lbList = [], lbI = 0;
function pfLbOpen(list, i){
    lbList = list; lbI = i || 0; $('lbImg').src = lbList[lbI];
    var many = lbList.length > 1;
    document.querySelector('#lb .p').style.display = many ? '' : 'none'; document.querySelector('#lb .n').style.display = many ? '' : 'none';
    $('lb').classList.add('open');
}
function pfLbStep(d){ lbI = (lbI + d + lbList.length) % lbList.length; $('lbImg').src = lbList[lbI]; }
function pfLbClose(){ $('lb').classList.remove('open'); }

/* ══ progress bar, banner parallax, tilt + spotlight ══ */
(function(){
    var tick = false, prog = $('pfProg');
    function frame(){
        tick = false;
        var max = document.documentElement.scrollHeight - innerHeight, y = scrollY;
        prog.style.transform = 'scaleX(' + (max > 0 ? Math.min(1, y / max) : 0) + ')';
        var b = $('pfBanner');
        if(b && b.classList.contains('has-img') && !isArr() && !/\bam-pan\b/.test(document.body.className) && !(window.matchMedia && matchMedia('(prefers-reduced-motion:reduce)').matches))
            b.style.backgroundPosition = 'center ' + (50 + Math.min(y, 500) * .05) + '%';
    }
    addEventListener('scroll', function(){ if(!tick){ tick = true; requestAnimationFrame(frame); } }, {passive: true}); frame();
    document.addEventListener('pointermove', function(e){
        if(e.pointerType !== 'mouse') return;
        var c = e.target.closest && e.target.closest('.proj,.lnk'); if(!c) return;
        var r = c.getBoundingClientRect(), x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height;
        c.style.setProperty('--mx', (x * 100) + '%'); c.style.setProperty('--my', (y * 100) + '%');
        if(/\bhv-tilt3d\b/.test(document.body.className)){ c.style.setProperty('--ry', ((x - .5) * 14) + 'deg'); c.style.setProperty('--rx', ((.5 - y) * 14) + 'deg'); }
    }, {passive: true});
    document.addEventListener('pointerout', function(e){
        var c = e.target.closest && e.target.closest('.proj,.lnk');
        if(c && !c.contains(e.relatedTarget)){ c.style.removeProperty('--rx'); c.style.removeProperty('--ry'); }
    });
})();

/* ══ WORK PAGE (project modal) ══ */
var PJ = PF.pj || [], pmI = -1;
function pfIcon(u){
    var m = {instagram:'ri-instagram-line',github:'ri-github-line',linkedin:'ri-linkedin-box-line','x.com':'ri-twitter-x-line',twitter:'ri-twitter-x-line',youtube:'ri-youtube-line',tiktok:'ri-tiktok-line',behance:'ri-behance-line',dribbble:'ri-dribbble-line',facebook:'ri-facebook-circle-line','wa.me':'ri-whatsapp-line',spotify:'ri-spotify-line',twitch:'ri-twitch-line',pinterest:'ri-pinterest-line',medium:'ri-medium-line'}, h = '';
    try{ h = new URL(u).hostname.toLowerCase(); }catch(e){}
    for(var k in m) if(h.indexOf(k) > -1) return m[k];
    return 'ri-link';
}
function pmChip(icon, txt){ var c = mk('span', 'pm-chip'); c.appendChild(mk('i', icon)); c.appendChild(document.createTextNode(txt)); return c; }
function pmHead(icon, txt){ var h = mk('div', 'pm-h'); h.appendChild(mk('i', icon)); h.appendChild(document.createTextNode(txt)); return h; }
function pmShare(p){
    var url = location.origin + location.pathname + '?user_id=' + PF.pid + '#work-' + p.id;
    if(navigator.share){ navigator.share({title: p.t, url: url}).catch(function(){}); return; }
    if(navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(function(){ toast('Link copied'); }, function(){ prompt('Copy this link', url); });
    else prompt('Copy this link', url);
}
function pmBuild(i){
    var p = PJ[i], lane = $('pmLane'), g = p.g || [], n = 0; lane.innerHTML = ''; pmI = i;
    var bar = mk('div', 'pm-bar'), bx = mk('button', 'pm-ib back'); bx.type = 'button'; bx.innerHTML = '<i class="ri-arrow-left-line"></i>'; bx.setAttribute('aria-label', 'Back'); bx.onclick = pmClose;
    var sh = mk('button', 'pm-ib share'); sh.type = 'button'; sh.innerHTML = '<i class="ri-share-forward-line"></i>'; sh.setAttribute('aria-label', 'Share'); sh.onclick = function(){ pmShare(p); };
    bar.appendChild(bx); bar.appendChild(mk('span', 'pm-bar-t', p.t)); bar.appendChild(sh); lane.appendChild(bar);

    var hero = mk('div', 'pm-hero'); lane.appendChild(hero);
    if(!g.length){ var ni = mk('div', 'pm-noimg'); ni.appendChild(mk('span', '', (p.t || '?').charAt(0).toUpperCase())); hero.appendChild(ni); }
    else {
        var sl = mk('div', 'pm-slides'), srcs = g.filter(function(m){ return m.kind === 'image'; }).map(function(m){ return m.src; });
        g.forEach(function(m){
            var s = mk('div', 'pm-slide');
            if(m.kind === 'image'){ var im = mk('img'); im.src = m.src; im.alt = p.t; im.onclick = function(){ pfLbOpen(srcs, srcs.indexOf(m.src)); }; s.appendChild(im); }
            else if(m.kind === 'video'){ var v = mk('video'); v.src = m.src; v.controls = true; v.playsInline = true; v.preload = 'metadata'; s.appendChild(v); }
            else { var w = mk('div', 'pm-emb'), f = mk('iframe'); f.dataset.src = m.src; f.title = p.t; f.allowFullscreen = true; f.setAttribute('allow', 'accelerometer; encrypted-media; picture-in-picture; fullscreen'); f.referrerPolicy = 'strict-origin-when-cross-origin'; w.appendChild(f); s.appendChild(w); }
            sl.appendChild(s);
        });
        hero.appendChild(sl);
        var cnt = mk('span', 'pm-count'), dots = mk('div', 'pm-dots'), th = null, thEls = [];
        if(g.length > 1){
            hero.appendChild(cnt); hero.appendChild(dots);
            g.forEach(function(){ dots.appendChild(mk('i')); });
            ['p', 'n'].forEach(function(d){
                var a = mk('button', 'pm-arr ' + d); a.type = 'button'; a.innerHTML = '<i class="ri-arrow-' + (d === 'p' ? 'left' : 'right') + '-s-line"></i>'; a.setAttribute('aria-label', d === 'p' ? 'Previous' : 'Next');
                a.onclick = function(){ sl.scrollTo({left: Math.round(sl.scrollLeft / sl.clientWidth + (d === 'p' ? -1 : 1)) * sl.clientWidth, behavior: 'smooth'}); }; hero.appendChild(a);
            });
            th = mk('div', 'pm-thumbs');
            g.forEach(function(m, k){
                var b = mk('button', 'pm-th'); b.type = 'button'; b.setAttribute('aria-label', 'Show item ' + (k + 1));
                if(m.kind === 'image'){ var ti = mk('img'); ti.src = m.src; ti.alt = ''; b.appendChild(ti); }
                else if(m.thumb){ var te = mk('img'); te.src = m.thumb; te.alt = ''; b.appendChild(te); }
                else b.appendChild(mk('i', m.kind === 'video' ? 'ri-movie-line' : 'ri-play-circle-line'));
                b.onclick = function(){ sl.scrollTo({left: k * sl.clientWidth, behavior: 'smooth'}); };
                th.appendChild(b); thEls.push(b);
            });
        }
        var act = function(){
            var k = Math.round(sl.scrollLeft / Math.max(sl.clientWidth, 1));
            cnt.textContent = (k + 1) + ' / ' + g.length;
            [].forEach.call(dots.children, function(d, j){ d.classList.toggle('on', j === k); });
            thEls.forEach(function(b, j){ b.classList.toggle('on', j === k); if(j === k && th) th.scrollTo({left: b.offsetLeft - 60, behavior: 'smooth'}); });
            [].forEach.call(sl.children, function(s, j){
                var v = s.querySelector('video'); if(v && j !== k) v.pause();
                var f = s.querySelector('iframe'); if(f){ if(j === k){ if(f.getAttribute('src') !== f.dataset.src) f.src = f.dataset.src; } else if(f.getAttribute('src')) f.removeAttribute('src'); }
            });
        };
        sl.addEventListener('scroll', function(){ clearTimeout(sl._t); sl._t = setTimeout(act, 60); }, {passive: true}); act();
        if(th) lane.appendChild(th);
    }

    var b = mk('div', 'pm-body'); lane.appendChild(b);
    var add = function(node){ node.style.setProperty('--i', n++); b.appendChild(node); };
    add(mk('div', 'pm-kick', 'Project ' + (i + 1) + ' of ' + PJ.length));
    add(mk('h2', 'pm-title', p.t));
    if(p.r || p.c || p.y){
        var ch = mk('div', 'pm-chips');
        if(p.r) ch.appendChild(pmChip('ri-user-star-line', p.r)); if(p.c) ch.appendChild(pmChip('ri-building-line', p.c)); if(p.y) ch.appendChild(pmChip('ri-calendar-line', p.y));
        add(ch);
    }
    var ac = mk('div', 'pm-acts');
    if(p.l && p.l.length){ var vp = mk('a', 'pill red'); vp.href = p.l[0].u; vp.target = '_blank'; vp.rel = 'noopener noreferrer'; vp.innerHTML = '<i class="ri-external-link-line"></i> '; vp.appendChild(document.createTextNode('Visit project')); ac.appendChild(vp); }
    var sb = mk('button', 'pill'); sb.type = 'button'; sb.innerHTML = '<i class="ri-share-forward-line"></i> '; sb.appendChild(document.createTextNode('Share')); sb.onclick = function(){ pmShare(p); }; ac.appendChild(sb);
    add(ac);
    if(p.d) add(mk('p', 'pm-lead', p.d));
    if(p.s){
        add(pmHead('ri-article-line', 'The story'));
        var card = mk('div', 'pm-card'), st = mk('div', 'pm-story', p.s); card.appendChild(st);
        if(p.s.length > 520){
            st.classList.add('clamp');
            var mb = mk('button', 'pm-more', 'Read more'); mb.type = 'button';
            mb.onclick = function(){ var c = st.classList.toggle('clamp'); mb.textContent = c ? 'Read more' : 'Show less'; };
            card.appendChild(mb);
        }
        add(card);
    }
    if(p.tools && p.tools.length){
        add(pmHead('ri-tools-line', 'Made with'));
        var tc = mk('div', 'pm-chips'); p.tools.forEach(function(t){ tc.appendChild(pmChip('ri-checkbox-circle-line', t)); }); add(tc);
    }
    if(p.l && p.l.length){
        add(pmHead('ri-links-line', 'Links'));
        var lw = mk('div', 'lnks'); lw.style.padding = '0';
        p.l.forEach(function(l){ var a = mk('a', 'lnk'); a.href = l.u; a.target = '_blank'; a.rel = 'noopener noreferrer nofollow'; a.appendChild(mk('i', pfIcon(l.u))); a.appendChild(mk('span', '', l.l)); a.appendChild(mk('i', 'ri-arrow-right-up-line')); lw.appendChild(a); });
        add(lw);
    }
    if(PJ.length > 1){
        var nv = mk('div', 'pm-nav');
        [[i - 1, 'Previous', 'p'], [i + 1, 'Next', 'n']].forEach(function(x){
            var nb = mk('button', 'pm-nb ' + x[2]); nb.type = 'button'; var q = PJ[x[0]];
            nb.appendChild(mk('small', '', x[1])); nb.appendChild(mk('b', '', q ? q.t : '—'));
            if(!q) nb.disabled = true; else nb.onclick = function(){ pmBuild(x[0]); $('pmOv').scrollTop = 0; setHash(q.id); };
            nv.appendChild(nb);
        });
        add(nv);
    }
}
function setHash(id){ try{ history.replaceState(null, '', location.pathname + location.search + '#work-' + id); }catch(e){} }
function pmOpen(id){
    for(var i = 0; i < PJ.length; i++) if(PJ[i].id === id){
        var ov = $('pmOv'); pmBuild(i); ov.classList.add('open'); ov.scrollTop = 0; document.documentElement.style.overflow = 'hidden'; setHash(id); return true;
    }
    return false;
}
function pmClose(){
    var ov = $('pmOv'); if(!ov.classList.contains('open')) return;
    ov.classList.remove('open'); $('pmLane').innerHTML = ''; pmI = -1; document.documentElement.style.overflow = '';
    try{ history.replaceState(null, '', location.pathname + location.search); }catch(e){}
}
$('pmOv').addEventListener('scroll', function(){
    var ov = this, hero = ov.querySelector('.pm-hero'), bar = ov.querySelector('.pm-bar'), t = ov.querySelector('.pm-title'), bt = ov.querySelector('.pm-bar-t');
    if(!hero || !bar) return;
    bar.classList.toggle('scrolled', ov.scrollTop > Math.max(hero.offsetHeight - 50, 0));
    if(t && bt) bt.classList.toggle('show', t.getBoundingClientRect().bottom <= 54);
}, {passive: true});
document.addEventListener('click', function(e){
    var c = e.target.closest && e.target.closest('[data-pj]');
    if(!c || isArr()) return;
    pmOpen(parseInt(c.dataset.pj, 10));
});
document.addEventListener('keydown', function(e){
    var c = e.target.closest && e.target.closest('[data-pj]');
    if(c && (e.key === 'Enter' || e.key === ' ') && !isArr()){ e.preventDefault(); pmOpen(parseInt(c.dataset.pj, 10)); return; }
    if($('pmOv').classList.contains('open')){
        if(e.key === 'Escape'){ if($('lb').classList.contains('open')) pfLbClose(); else pmClose(); }
        else if(e.key === 'ArrowLeft' || e.key === 'ArrowRight'){
            var sl = document.querySelector('#pmOv .pm-slides');
            if(sl) sl.scrollTo({left: Math.round(sl.scrollLeft / sl.clientWidth + (e.key === 'ArrowLeft' ? -1 : 1)) * sl.clientWidth, behavior: 'smooth'});
        }
    }
});
(function(){ var m = /^#work-(\d+)$/.exec(location.hash); if(m) pmOpen(parseInt(m[1], 10)); })();
</script>

<?php if($is_own): ?>
<script>
function editTab(t){
    document.querySelectorAll('.sh-tab').forEach(function(b){ b.classList.toggle('on', b.dataset.tab === t); });
    document.querySelectorAll('.sh-pane').forEach(function(p){ p.classList.toggle('on', p.id === 'pane-' + t); });
    $('fromTab').value = t === 'design' ? 'design' : 'details';
    document.querySelector('.sh-body').scrollTop = 0;
}
function openEdit(t){ if(isArr()) return; pmClose(); editTab(t || 'details'); $('editSheet').classList.add('open'); document.body.classList.add('pf-lock'); }
function closeEdit(){ var es = $('editSheet'); es.classList.remove('open', 'peek'); document.body.classList.remove('pf-lock'); pfPeekLabel(false); pfRevert(); }
/* Preview: slide the editor out of the way so you can see the page change, then come back to editing */
function pfPeekLabel(on){ var b = $('pfPeekBtn'); if(!b) return; b.querySelector('span').textContent = on ? 'Back to editing' : 'Preview'; b.querySelector('i').className = on ? 'ri-edit-2-line' : 'ri-eye-line'; }
(function(){ var c = document.getElementById('pubChk'), n = document.getElementById('pubNote'); if(!c || !n) return;
    function sync(){ n.classList.toggle('is-on', c.checked); } c.addEventListener('change', sync); sync(); })();
function pfPeek(){
    var es = $('editSheet'), on = !es.classList.contains('peek');
    es.classList.toggle('peek', on);
    document.body.classList.toggle('pf-lock', !on);   /* while previewing the page can scroll */
    pfPeekLabel(on);
}
document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && $('editSheet').classList.contains('open') && !$('lb').classList.contains('open')) closeEdit(); });

/* editable rows (links, experience) — buttons are wired with addEventListener so nothing in the form can shadow them */
function rowEditor(boxId, addId, max, fields, list){
    var box = $(boxId), add = $(addId);
    function count(){ add.style.display = box.children.length >= max ? 'none' : ''; }
    function row(v){
        var r = mk('div', 'rowed'), top = mk('div', 'top'), x = mk('button', 'x'); x.type = 'button'; x.setAttribute('aria-label', 'Remove'); x.innerHTML = '<i class="ri-close-line"></i>';
        x.onclick = function(){ box.removeChild(r); count(); };
        fields.forEach(function(f, i){
            var el = f.area ? mk('textarea') : mk('input'); if(f.area) el.rows = 2; else el.type = f.type || 'text';
            el.placeholder = f.ph; el.maxLength = f.max; el.value = (v && v[f.k]) || ''; el.dataset.k = f.k;
            if(i === 0){ top.appendChild(el); top.appendChild(x); r.appendChild(top); }
            else { var t = mk('div', 'top'); t.appendChild(el); r.appendChild(t); }
        });
        return r;
    }
    (list || []).forEach(function(v){ box.appendChild(row(v)); });
    count();
    add.addEventListener('click', function(){ if(box.children.length >= max) return; var r = row(); box.appendChild(r); count(); var f = r.querySelector('input,textarea'); if(f) f.focus(); });
    return {
        collect: function(){
            var out = [];
            [].forEach.call(box.children, function(r){
                var o = {}, any = false;
                [].forEach.call(r.querySelectorAll('[data-k]'), function(el){ o[el.dataset.k] = el.value.trim(); if(el.value.trim()) any = true; });
                if(any) out.push(o);
            });
            return out;
        }
    };
}
var LE = rowEditor('lnkBox', 'lnkAddBtn', PF.maxLinks, [{k:'l', ph:'Label, e.g. Instagram', max:40}, {k:'u', ph:'https://…', max:255, type:'url'}], PF.links);
var EE = rowEditor('expBox', 'expAddBtn', PF.maxExp, [{k:'r', ph:'Role, e.g. Brand designer', max:80}, {k:'o', ph:'Company or client', max:80}, {k:'p', ph:'Years, e.g. 2022 – Now', max:40}, {k:'t', ph:'What you did (optional)', max:400, area:true}], PF.exp);

/* ══ live design ══ */
var L = JSON.parse(JSON.stringify(PF.look)), L0 = JSON.parse(JSON.stringify(PF.look));
var rootCss = document.documentElement.style;
function isLight(){ return document.documentElement.getAttribute('data-theme') === 'light'; }
function hexRgb(x){ x = x.replace('#', ''); return parseInt(x.substr(0,2),16) + ',' + parseInt(x.substr(2,2),16) + ',' + parseInt(x.substr(4,2),16); }
function mix(hex, to, amt){ hex = hex.replace('#', ''); var o = '#'; for(var i = 0; i < 3; i++){ var c = parseInt(hex.substr(i*2, 2), 16), v = Math.round(c + (to - c) * amt); o += (v < 16 ? '0' : '') + v.toString(16); } return o; }
function lum(hex){ hex = hex.replace('#', ''); var f = function(c){ c /= 255; return c <= .03928 ? c / 12.92 : Math.pow((c + .055) / 1.055, 2.4); }; return .2126 * f(parseInt(hex.substr(0,2),16)) + .7152 * f(parseInt(hex.substr(2,2),16)) + .0722 * f(parseInt(hex.substr(4,2),16)); }
function val(n){ var e = document.querySelector('#pfForm input[name="' + n + '"]:checked'); return e ? e.value : ''; }
function paintAccent(){
    var k = val('accent'), a;
    if(k === 'custom'){ var x = $('accCustom').value; a = [x, mix(x, 0, .12), mix(x, 255, .45), lum(x) > .45 ? '#000' : '#fff']; }
    else a = PF.accents[k] || PF.accents.red;
    rootCss.setProperty('--ac', a[0]); rootCss.setProperty('--ac-h', a[1]); rootCss.setProperty('--ac-l', a[2]); rootCss.setProperty('--on-ac', a[3]);
    rootCss.setProperty('--ac-dim', 'rgba(' + hexRgb(a[0]) + ',.16)'); rootCss.setProperty('--ac-bd', 'rgba(' + hexRgb(a[0]) + ',.4)'); rootCss.setProperty('--ac-glow', 'rgba(' + hexRgb(a[0]) + ',.34)');
}
function paintBg(){
    var k = val('bg'), b;
    if(k === 'custom'){ var x = $('bgCustom').value; b = ['Custom', x, x, x, x, lum(x) > .4 ? 'dark' : 'light']; }
    else b = PF.bgs[k] || PF.bgs['default'];
    rootCss.setProperty('--pbg', isLight() ? b[3] : b[1]); rootCss.setProperty('--solid', isLight() ? b[4] : b[2]);
    return b[5] || '';
}
var KEEP = /^(tpl|era|ft|sp|nsz|lk|an|hv|am|pt|tx|cd|wd|bt|hc|hf|fz|dv|cv|al|txm|cdm|fx|px)-/;
function paintLook(){
    var cl = document.body.className.split(/\s+/).filter(function(c){ return c && !KEEP.test(c) && c !== 'cr-square'; });
    for(var k in PF.pfx){ var v = val('lk_' + k); if(v) L[k] = v; cl.push(PF.pfx[k] + L[k]); }
    L.banner = +(val('lk_banner') || L.banner); L.avshow = +(val('lk_avshow') || L.avshow); L.corners = val('lk_corners') || L.corners;
    if(L.corners === 'square') cl.push('cr-square');
    if(!L.banner) cl.push('lk-nob'); if(!L.avshow) cl.push('lk-noav');
    var tx = paintBg(); if(tx) cl.push('tx-' + tx);
    var FX = {avpx:'fx-av', bhpx:'fx-bh', avo:'fx-ao', nspx:'fx-ns', avbd:'fx-bd'}, fxv = {};
    document.querySelectorAll('.tune').forEach(function(t){
        var k = t.dataset.k, hid = t.querySelector('input[type=hidden]'), rg = t.querySelector('input[type=range]'), auto = hid.value === '';
        t.querySelector('b').textContent = auto ? 'Auto' : hid.value + t.dataset.u;
        L[k] = auto ? '' : +hid.value;
        if(!auto){ rg.value = hid.value; cl.push(FX[k]); fxv[k] = +hid.value; }
    });
    var bs = document.body.style;
    ['--avs','--bhpx','--avo','--nspx','--avbd'].forEach(function(v){ bs.removeProperty(v); });
    if('avpx' in fxv) bs.setProperty('--avs', 'min(' + fxv.avpx + 'px,70vw)');
    if('bhpx' in fxv) bs.setProperty('--bhpx', 'min(' + fxv.bhpx + 'px,70vh)');
    if('avo' in fxv) bs.setProperty('--avo', fxv.avo / 100);
    if('nspx' in fxv) bs.setProperty('--nspx', 'min(' + fxv.nspx + 'px,14vw)');
    if('avbd' in fxv) bs.setProperty('--avbd', fxv.avbd + 'px');
    var tm = val('lk_txm') === 'custom', cm = val('lk_cdm') === 'custom';
    ['--text','--ui-text','--fg','--muted','--sub','--surface','--surface2','--line','--line2'].forEach(function(v){ bs.removeProperty(v); });
    if(tm){ var t = $('lk_txc').value, r = hexRgb(t);
        bs.setProperty('--text', t); bs.setProperty('--ui-text', t); bs.setProperty('--fg', r);
        bs.setProperty('--muted', 'rgba(' + r + ',.55)'); bs.setProperty('--sub', 'rgba(' + r + ',.75)');
        bs.setProperty('--surface', 'rgba(' + r + ',.06)'); bs.setProperty('--surface2', 'rgba(' + r + ',.1)');
        bs.setProperty('--line', 'rgba(' + r + ',.14)'); bs.setProperty('--line2', 'rgba(' + r + ',.24)'); }
    if(cm){ var c = $('lk_cdc').value; bs.setProperty('--surface', c); bs.setProperty('--surface2', mix(c, 255, .1)); }
    var prev = document.body.className;
    document.body.className = cl.join(' ');
    var an = /\ban-\w+/.exec(prev), an2 = /\ban-\w+/.exec(document.body.className);
    if(!an || !an2 || an[0] !== an2[0]) revInit();
    var px = /\bpx-\w+/.exec(prev), px2 = /\bpx-\w+/.exec(document.body.className);
    if(!px || !px2 || px[0] !== px2[0]) pxApply();
}
function paintAll(){ paintAccent(); paintLook(); }
var paintQ = false;
function schedPaint(){ if(paintQ) return; paintQ = true; requestAnimationFrame(function(){ paintQ = false; paintAll(); if(isArr()) arPos(); }); }
function paintOrder(){
    document.querySelectorAll('.pf-s').forEach(function(s){ var k = s.dataset.k; s.style.order = L.order.indexOf(k) + 1; s.classList.toggle('off', L.hidden.indexOf(k) > -1); });
    $('orderIn').value = L.order.join(','); $('hiddenIn').value = L.hidden.join(',');
}
function renderSecs(){
    var box = $('secList'); box.innerHTML = '';
    L.order.forEach(function(key, i){
        var off = L.hidden.indexOf(key) > -1, row = mk('div', 'secrow' + (off ? ' off' : ''));
        row.appendChild(mk('span', 'num', String(i + 1)));
        var nm = mk('div', 'nm'); nm.appendChild(document.createTextNode(PF.names[key][0])); if(PF.names[key][1]) nm.appendChild(mk('small', '', PF.names[key][1])); row.appendChild(nm);
        var up = mk('button'); up.type = 'button'; up.innerHTML = '<i class="ri-arrow-up-s-line"></i>'; up.disabled = i === 0; up.setAttribute('aria-label', 'Move up'); up.onclick = function(){ move(i, -1); };
        var dn = mk('button'); dn.type = 'button'; dn.innerHTML = '<i class="ri-arrow-down-s-line"></i>'; dn.disabled = i === L.order.length - 1; dn.setAttribute('aria-label', 'Move down'); dn.onclick = function(){ move(i, 1); };
        row.appendChild(up); row.appendChild(dn);
        var ey = mk('button'); ey.type = 'button';
        if(PF.hideable.indexOf(key) > -1){
            ey.innerHTML = '<i class="' + (off ? 'ri-eye-off-line' : 'ri-eye-line') + '"></i>'; ey.setAttribute('aria-label', off ? 'Show section' : 'Hide section');
            ey.onclick = function(){ var j = L.hidden.indexOf(key); if(j > -1) L.hidden.splice(j, 1); else L.hidden.push(key); renderSecs(); paintOrder(); };
        } else { ey.disabled = true; ey.innerHTML = '<i class="ri-lock-line"></i>'; }
        row.appendChild(ey); box.appendChild(row);
    });
}
function move(i, d){
    var o = L.order, j = i + d; if(j < 0 || j >= o.length) return;
    var t = o[i]; o[i] = o[j]; o[j] = t;
    if(o.indexOf('header') > 1){ o[j] = o[i]; o[i] = t; alert('The header has to stay first or second.'); return; }
    renderSecs(); paintOrder();
}

/* profile picture helpers */
function setAv(u){
    var a = $('pfAv'), p = $('pvAv'); if(p) p.src = u;
    if(a){ a.dataset.o = u; a.src = u; }
    document.body.classList.toggle('no-pic', u === PF.gif);
    pxApply();
}
function pfRevert(){
    L = JSON.parse(JSON.stringify(L0));
    $('pfForm').reset(); $('rmAv').value = '';
    document.querySelectorAll('.tune').forEach(function(t){ t.querySelector('input[type=hidden]').value = t.dataset.init; });
    setAv(PF.avSrc || PF.gif); $('avReset').style.display = PF.avSrc && PF.avSrc !== PF.profPic ? '' : 'none';
    var pb = $('pvBanner'), b = $('pfBanner');
    if(PF.banner){ pb.style.backgroundImage = 'url("' + PF.banner + '")'; if(b){ b.dataset.o = PF.banner; b.style.backgroundImage = 'url("' + PF.banner + '")'; } }
    paintAll(); renderSecs(); paintOrder();
}
$('pfForm').addEventListener('change', function(e){
    var n = e.target.name;
    if(n === 'accent' || n === 'bg' || (n && n.indexOf('lk_') === 0)) paintAll();
});
document.querySelectorAll('.tune').forEach(function(t){
    var hid = t.querySelector('input[type=hidden]'), rg = t.querySelector('input[type=range]');
    rg.addEventListener('input', function(){ hid.value = rg.value; paintAll(); });
    t.querySelector('button').onclick = function(){ hid.value = ''; paintAll(); };
});
['lk_txc','lk_cdc'].forEach(function(id){ $(id).addEventListener('input', function(){ setRadio(id === 'lk_txc' ? 'lk_txm' : 'lk_cdm', 'custom'); paintAll(); }); });
$('accCustom').addEventListener('input', function(){ document.querySelector('input[name="accent"][value="custom"]').checked = true; paintAll(); });
$('bgCustom').addEventListener('input', function(){ document.querySelector('input[name="bg"][value="custom"]').checked = true; paintAll(); });
function pfCollect(){ $('linksJson').value = JSON.stringify(LE.collect()); $('expJson').value = JSON.stringify(EE.collect()); paintOrder(); return true; }

/* ══ starter looks ══ */
var PRESETS = {
  ordinary: {tpl:'stack',era:'modern',font:'sans',space:'normal',nsz:'md',head:'plain',skl:'cols',exp:'timeline',lnk:'rows',proj:'list',anim:'rise',hover:'lift',amb:'none',pat:'none',accent:'red',bg:'default',cd:'soft',bt:'default',hc:'normal',hf:'same',cv:'none',dv:'none',al:'center',corners:'soft',wd:'auto',fz:'md',px:'none'},
  slick:    {cd:'glass',bt:'pill',al:'center',cv:'vivid',era:'modern',tpl:'hero',font:'grotesk',space:'normal',nsz:'xl',head:'caps',skl:'big',exp:'table',lnk:'rows',proj:'grid',anim:'blur',hover:'glow',amb:'pan',pat:'none',accent:'neon',bg:'black'},
  editorial:{cd:'none',dv:'line',hf:'serif',hc:'normal',wd:'auto',era:'modern',tpl:'edit',font:'serif',space:'airy',nsz:'xl',head:'line',skl:'list',exp:'table',lnk:'text',proj:'rows',anim:'fade',hover:'none',amb:'none',pat:'none',accent:'red',bg:'paper'},
  web1:     {era:'retro',tpl:'stack',font:'serif',space:'normal',nsz:'lg',head:'line',skl:'list',exp:'table',lnk:'text',proj:'list',anim:'none',hover:'none',amb:'marquee',pat:'none',accent:'darkblue',bg:'classic'},
  terminal: {cd:'outline',bt:'square',hc:'lower',hf:'mono',era:'retro',tpl:'resume',font:'mono',space:'normal',nsz:'md',head:'plain',skl:'text',exp:'table',lnk:'text',proj:'index',anim:'none',hover:'none',amb:'none',pat:'none',accent:'neon',bg:'cyber'},
  tiles:    {cd:'shadow',bt:'square',era:'modern',tpl:'bento',font:'sans',space:'normal',nsz:'lg',head:'bar',skl:'boxes',exp:'cards',lnk:'rows',proj:'masonry',anim:'pop',hover:'lift',amb:'none',pat:'dots',accent:'sky',bg:'slate'},
  resume:   {era:'modern',tpl:'resume',font:'serif',space:'normal',nsz:'lg',head:'caps',skl:'cols',exp:'table',lnk:'text',proj:'index',anim:'fade',hover:'none',amb:'none',pat:'none',accent:'cocoa',bg:'paper'},
  side:     {era:'modern',tpl:'side',font:'sans',space:'normal',nsz:'lg',head:'bar',skl:'list',exp:'timeline',lnk:'rows',proj:'grid',anim:'slide',hover:'lift',amb:'float',pat:'none',accent:'teal',bg:'ocean'},
  split:    {era:'modern',tpl:'split',font:'grotesk',space:'airy',nsz:'lg',head:'big',skl:'big',exp:'cards',lnk:'icons',proj:'carousel',anim:'zoom',hover:'shine',amb:'none',pat:'none',accent:'pink',bg:'plum'},
  soft:     {cd:'soft',bt:'pill',hf:'rounded',cv:'tint',era:'modern',tpl:'stack',font:'rounded',space:'airy',nsz:'lg',head:'caps',skl:'boxes',exp:'cards',lnk:'rows',proj:'grid',anim:'rise',hover:'grow',amb:'aurora',pat:'none',accent:'pink',bg:'wine'},
  neon:     {tpl:'hero',font:'grotesk',nsz:'xl',head:'caps',skl:'big',exp:'table',proj:'grid',anim:'blur',hover:'glow',amb:'aurora',pat:'grid',accent:'neon',bg:'cyber',cd:'outline',bt:'outline',hc:'upper'},
  sunset:   {tpl:'stack',font:'rounded',space:'airy',nsz:'lg',head:'caps',skl:'boxes',exp:'cards',proj:'grid',anim:'rise',hover:'grow',amb:'float',accent:'orange',bg:'ember',cd:'glass',bt:'pill',cv:'tint'},
  brutal:   {tpl:'bento',font:'mono',nsz:'lg',head:'bar',skl:'boxes',exp:'cards',proj:'masonry',anim:'pop',hover:'lift',accent:'red',bg:'paper',cd:'shadow',bt:'square',hc:'upper',corners:'square'},
  minimal:  {tpl:'resume',font:'sans',space:'airy',nsz:'lg',head:'plain',skl:'text',exp:'table',lnk:'text',proj:'index',anim:'fade',hover:'none',accent:'silver',bg:'black',cd:'none',dv:'line'},
  forest:   {tpl:'side',font:'serif',space:'normal',nsz:'lg',head:'line',skl:'list',exp:'timeline',proj:'grid',anim:'slide',hover:'lift',pat:'dots',accent:'green',bg:'forest',cd:'flat',bt:'pill'},
  royal:    {tpl:'edit',font:'serif',space:'airy',nsz:'xl',head:'line',skl:'list',exp:'table',lnk:'text',proj:'rows',anim:'fade',hover:'none',accent:'gold',bg:'navy',cd:'outline',hc:'upper',hf:'serif',dv:'thick'},
  pop:      {tpl:'split',font:'grotesk',nsz:'xl',head:'big',skl:'big',exp:'cards',lnk:'icons',proj:'carousel',anim:'zoom',hover:'tilt',pat:'check',accent:'magenta',bg:'sky',cd:'shadow',bt:'pill'},
  blueprint:{tpl:'stack',font:'mono',space:'normal',nsz:'lg',head:'line',skl:'list',exp:'table',lnk:'rows',proj:'rows',anim:'fade',hover:'glow',pat:'grid',accent:'sky',bg:'ocean',cd:'outline',bt:'outline',hc:'lower'},
  arcade:   {era:'pixel',tpl:'arcade',font:'pixel',nsz:'lg',head:'pixel',skl:'boxes',exp:'cards',lnk:'rows',proj:'grid',anim:'pop',hover:'lift',amb:'scan',pat:'pixgrid',accent:'neon',bg:'arcade',cd:'pixel',bt:'square',hc:'upper',px:'fine'},
  gameboy:  {era:'pixel',tpl:'stack',font:'pixel',nsz:'lg',head:'bar',skl:'list',exp:'table',lnk:'rows',proj:'list',anim:'fade',hover:'none',amb:'none',pat:'pixgrid',accent:'gb',bg:'gameboy',cd:'pixel',bt:'square',hc:'upper',px:'chunky'},
  quest:    {era:'pixel',tpl:'side',font:'pixel',nsz:'lg',head:'pixel',skl:'boxes',exp:'timeline',lnk:'rows',proj:'grid',anim:'slide',hover:'tilt3d',amb:'stars',pat:'none',accent:'gold',bg:'arcade',cd:'pixel',bt:'square',px:'fine'},
  pixelpop: {era:'pixel',tpl:'bento',font:'pixel',nsz:'lg',head:'pixel',skl:'boxes',exp:'cards',lnk:'rows',proj:'masonry',anim:'drop',hover:'spot',amb:'none',pat:'pixgrid',accent:'pink',bg:'pixsky',cd:'pixel',bt:'square',px:'chunky'}
};
function setRadio(name, v){ var r = document.querySelector('#pfForm input[name="' + name + '"][value="' + v + '"]'); if(r) r.checked = true; }
var PNAMES = {ordinary:'Ordinary (plain)',slick:'Slick studio',editorial:'Editorial',web1:'Web 1.0',terminal:'Terminal',tiles:'Tiles',resume:'Résumé',side:'Sidebar',split:'Split screen',soft:'Soft pastel',neon:'Neon night',sunset:'Sunset',brutal:'Brutalist',minimal:'Minimal',forest:'Forest calm',royal:'Royal',pop:'Pop art',blueprint:'Blueprint',arcade:'Pixel arcade',gameboy:'Game Boy',quest:'Pixel quest',pixelpop:'Pixel pop'};
var PRESET_RESET = {avpos:'auto',cd:'soft',wd:'auto',bt:'default',hc:'normal',hf:'same',fz:'md',dv:'none',cv:'none',al:'center',txm:'auto',cdm:'auto',corners:'soft',lnk:'rows',space:'normal',pat:'none',amb:'none',hover:'none',era:'modern',px:'none'};
function preset(n){
    var p = PRESETS[n]; if(!p) return;
    for(var r in PRESET_RESET) setRadio('lk_' + r, PRESET_RESET[r]);
    for(var k in p){ if(k === 'accent' || k === 'bg') setRadio(k, p[k]); else setRadio('lk_' + k, p[k]); }
    if(n === 'ordinary'){
        var tn = document.querySelectorAll('#pfForm .tune input[type=hidden]');
        for(var i = 0; i < tn.length; i++) tn[i].value = '';
    }
    paintAll(); revInit();
}
/* mini previews drawn from each starter look's own settings */
function pvHtml(p){
    var b = PF.bgs[p.bg] || PF.bgs['default'], a = (PF.accents[p.accent] || PF.accents.red)[0];
    var bg = b[2], tc = b[5] === 'dark' ? '#111' : '#fff';
    var cr = (p.corners === 'square' || p.era === 'retro' || p.era === 'pixel') ? 0 : 4;
    var cs = {outline: 'border:1.5px solid ' + tc + ';opacity:.8', shadow: 'border:1.5px solid ' + tc + ';box-shadow:2px 2px 0 ' + a, pixel: 'border:2px solid ' + tc + ';box-shadow:2px 2px 0 rgba(0,0,0,.4)', glass: 'background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3)', none: 'opacity:.4;border-bottom:1px solid ' + tc, flat: 'background:rgba(128,128,128,.28)'}[p.cd] || 'background:rgba(128,128,128,.28);border:1px solid rgba(128,128,128,.25)';
    var btn = p.bt === 'pill' ? 99 : ((p.bt === 'square' || p.era === 'pixel') ? 0 : 3);
    var ff = {serif: 'Georgia,serif', mono: 'monospace', rounded: 'Nunito,sans-serif', grotesk: 'Space Grotesk,sans-serif', pixel: 'Pixelify Sans,monospace'}[p.font] || 'Poppins,sans-serif';
    var t = p.tpl, h = '';
    var line = function(x, y, w, o){ return '<i style="left:' + x + '%;top:' + y + 'px;width:' + w + '%;height:3px;border-radius:2px;background:' + tc + ';opacity:' + (o || .45) + '"></i>'; };
    var card = function(x, y, w, hh){ return '<i style="left:' + x + '%;top:' + y + 'px;width:' + w + '%;height:' + hh + 'px;border-radius:' + cr + 'px;' + cs + '"></i>'; };
    var av = function(x, y, d){ return '<i style="left:' + x + '%;top:' + y + 'px;width:' + d + 'px;height:' + d + 'px;border-radius:' + (p.era === 'pixel' ? 0 : 50) + '%;background:' + a + ';border:2px solid ' + bg + '"></i>'; };
    var bar = function(x, y, w, hh){ return '<i style="left:' + x + '%;top:' + y + 'px;width:' + w + '%;height:' + hh + 'px;background:linear-gradient(135deg,' + a + ',' + bg + ');opacity:.85"></i>'; };
    var bt = function(x, y){ return '<i style="left:' + x + '%;top:' + y + 'px;width:16%;height:7px;border-radius:' + btn + 'px;background:' + a + '"></i>'; };
    var tb = function(x, y, w){ return '<i style="left:' + x + '%;top:' + y + 'px;width:' + w + '%;height:6px;background:' + a + '"></i>'; };
    if(t === 'hero'){ h = bar(0, 0, 100, 38) + av(8, 26, 20) + line(34, 42, 36, .9) + line(34, 50, 24) + bt(34, 58) + card(6, 66, 28, 12) + card(36, 66, 28, 12) + card(66, 66, 28, 12); }
    else if(t === 'side'){ h = '<i style="left:0;top:0;width:30%;height:82px;background:' + a + ';opacity:.22"></i>' + av(7, 10, 18) + line(5, 34, 20, .9) + line(5, 42, 16) + bt(5, 52) + card(36, 8, 28, 28) + card(68, 8, 28, 28) + card(36, 42, 60, 12) + line(36, 62, 55) + line(36, 70, 40); }
    else if(t === 'bento'){ h = card(4, 5, 56, 32) + card(63, 5, 33, 32) + card(4, 41, 30, 34) + card(37, 41, 30, 34) + card(70, 41, 26, 34) + av(8, 12, 16) + line(30, 14, 22, .9) + line(30, 22, 16); }
    else if(t === 'arcade'){ h = card(4, 5, 92, 26) + av(9, 11, 14) + line(28, 14, 34, .9) + line(28, 22, 22) + card(4, 37, 44, 38) + card(52, 37, 44, 38) + tb(4, 37, 44) + tb(52, 37, 44) + line(8, 52, 30) + line(56, 52, 30); }
    else if(t === 'split'){ h = '<i style="left:0;top:0;width:46%;height:82px;background:' + a + ';opacity:.5"></i>' + av(14, 26, 24) + line(52, 12, 38, .9) + line(52, 20, 30) + card(52, 32, 40, 14) + card(52, 52, 40, 14) + bt(52, 72); }
    else if(t === 'edit'){ h = line(8, 10, 60, .95) + line(8, 18, 40, .6) + '<i style="left:8%;top:27px;width:84%;height:2px;background:' + a + '"></i>' + line(8, 36, 40) + line(55, 36, 36) + line(8, 44, 40) + line(55, 44, 30) + line(8, 52, 40) + line(55, 52, 36) + bt(8, 66); }
    else if(t === 'resume'){ h = line(8, 10, 44, .95) + line(8, 18, 26, .6) + '<i style="left:8%;top:28px;width:84%;height:1px;background:' + tc + ';opacity:.4"></i>' + line(8, 36, 25, .8) + line(40, 36, 52) + line(8, 46, 25, .8) + line(40, 46, 44) + line(8, 56, 25, .8) + line(40, 56, 50) + line(8, 66, 25, .8) + line(40, 66, 30); }
    else { h = bar(0, 0, 100, 26) + av(38, 14, 24) + line(30, 44, 40, .9) + line(36, 52, 28) + bt(42, 60) + card(6, 70, 42, 10) + card(52, 70, 42, 10); }
    return '<span class="pvm" style="background:' + bg + ';font-family:' + ff + '">' + h + '</span>';
}
(function(){
    var box = $('presetBox'); if(!box) return;
    for(var n in PRESETS){
        var bt = mk('button', 'pv'); bt.type = 'button';
        bt.onclick = (function(k){ return function(){ preset(k); var all = box.querySelectorAll('.pv'); for(var i = 0; i < all.length; i++) all[i].classList.remove('on'); this.classList.add('on'); }; })(n);
        bt.innerHTML = pvHtml(PRESETS[n]) + '<span class="pvn">' + (PNAMES[n] || n) + '</span>';
        box.appendChild(bt);
    }
})();

/* live preview of a newly chosen banner / picture, and going back to the profile picture */
$('bannerFile').addEventListener('change', function(){
    var f = this.files[0]; if(!f) return;
    if(f.size > 5*1024*1024){ alert('Banner must be under 5 MB'); this.value = ''; return; }
    var u = URL.createObjectURL(f), pv = $('pvBanner'); pv.style.backgroundImage = 'url("' + u + '")'; pv.innerHTML = '';
    var b = $('pfBanner');
    if(b){ b.classList.add('has-img'); b.dataset.o = u; b.style.backgroundImage = 'url("' + u + '")'; var ic = b.querySelector('.pf-banner-ic'); if(ic) ic.style.display = 'none'; pxApply(); }
});
$('avFile').addEventListener('change', function(){
    var f = this.files[0]; if(!f) return;
    if(f.size > 5*1024*1024){ alert('Picture must be under 5 MB'); this.value = ''; return; }
    $('rmAv').value = ''; setAv(URL.createObjectURL(f)); $('avReset').style.display = '';
});
$('avReset').addEventListener('click', function(){
    $('rmAv').value = '1'; $('avFile').value = '';
    setAv(PF.profPic || PF.gif); this.style.display = 'none';
    toast(PF.profPic ? 'Back to your profile picture. Save to keep it.' : 'Picture removed. Save to keep it.');
});

paintAll(); renderSecs(); paintOrder();

/* ══════════ ARRANGE MODE — move things with your hands, then save ══════════ */
(function(){
    var btn = $('arBtn'); if(!btn) return;
    var dock = $('arDock'), dirty = false, chrome = [];
    function clamp(v, a, b){ return Math.max(a, Math.min(b, v)); }
    function exists(k){ return !!document.querySelector('.pf-s[data-k="' + k + '"]'); }
    function secs(){ return [].slice.call(document.querySelectorAll('.pf-s')); }
    function arPos(){
        var ban = $('pfBanner'), bh = document.querySelector('.ar-bh');
        if(bh && ban) bh.style.top = Math.max(8, ban.offsetHeight - 42) + 'px';
    }
    window.arPos = arPos;
    function setFx(k, v){
        L[k] = v; var h = document.querySelector('#pfForm input[name="lk_' + k + '"]'); if(h) h.value = v;
        dirty = true; schedPaint();
    }
    /* smooth slide of the other sections when the order changes */
    function flip(fn, skip){
        var els = secs(), first = els.map(function(e){ return e.getBoundingClientRect().top; });
        fn();
        els.forEach(function(e, i){
            if(e === skip) return;
            var d = first[i] - e.getBoundingClientRect().top; if(!d) return;
            e.style.transition = 'none'; e.style.transform = 'translateY(' + d + 'px)'; void e.offsetWidth;
            e.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)'; e.style.transform = '';
            setTimeout(function(){ e.style.transition = ''; }, 320);
        });
    }
    function nudge(key, d){
        var rk = L.order.filter(exists), i = rk.indexOf(key), j = i + d;
        if(j < 0 || j >= rk.length) return;
        var o = L.order.slice(), a = o.indexOf(key), b = o.indexOf(rk[j]); o[a] = rk[j]; o[b] = key;
        if(o.indexOf('header') > 1){ toast('The header has to stay first or second'); return; }
        flip(function(){ L.order = o; paintOrder(); }, null); dirty = true; renderSecs(); arPos();
    }
    function startDrag(e, s){
        e.preventDefault(); e.stopPropagation();
        var g = e.currentTarget, key = s.dataset.k; g.setPointerCapture(e.pointerId);
        var grab = e.clientY - s.getBoundingClientRect().top, y = e.clientY, raf = 0;
        s.classList.add('dragging'); document.body.classList.add('ar-drag');
        function place(){
            s.style.transform = 'none';
            var others = secs().filter(function(o){ return o !== s; }).sort(function(a, b){ return L.order.indexOf(a.dataset.k) - L.order.indexOf(b.dataset.k); });
            var idx = 0; others.forEach(function(o){ var r = o.getBoundingClientRect(); if(r.top + r.height / 2 < y) idx++; });
            var nk = others.map(function(o){ return o.dataset.k; }); nk.splice(idx, 0, key);
            if(nk.join() !== L.order.filter(exists).join()){
                var j = 0, o2 = L.order.map(function(k){ return exists(k) ? nk[j++] : k; });
                if(o2.indexOf('header') <= 1){ flip(function(){ L.order = o2; paintOrder(); }, s); dirty = true; }
            }
            s.style.transform = 'translateY(' + (y - grab - s.getBoundingClientRect().top) + 'px)';
        }
        function loop(){
            var sp = 0;
            if(y < 90) sp = -Math.round((90 - y) / 4); else if(y > innerHeight - 90) sp = Math.round((y - (innerHeight - 90)) / 4);
            if(sp){ scrollBy(0, sp); place(); }
            raf = requestAnimationFrame(loop);
        }
        function mv(ev){ y = ev.clientY; place(); }
        function up(){
            cancelAnimationFrame(raf);
            g.removeEventListener('pointermove', mv); g.removeEventListener('pointerup', up); g.removeEventListener('pointercancel', up);
            var off = s.style.transform; s.classList.remove('dragging'); document.body.classList.remove('ar-drag');
            s.style.transition = 'none'; s.style.transform = off; void s.offsetWidth;
            s.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)'; s.style.transform = '';
            setTimeout(function(){ s.style.transition = ''; }, 320);
            renderSecs(); arPos();
        }
        g.addEventListener('pointermove', mv); g.addEventListener('pointerup', up); g.addEventListener('pointercancel', up);
        raf = requestAnimationFrame(loop); place();
    }
    /* generic round handle: begin() returns the start value, move() turns finger distance into a new value */
    function dragger(h, begin, move){
        h.addEventListener('pointerdown', function(e){
            e.preventDefault(); e.stopPropagation(); h.setPointerCapture(e.pointerId);
            var sx = e.clientX, sy = e.clientY, st = begin(); h.classList.add('drag'); document.body.classList.add('ar-drag');
            function mv(ev){ move(ev.clientX - sx, ev.clientY - sy, st); }
            function up(){ h.removeEventListener('pointermove', mv); h.removeEventListener('pointerup', up); h.removeEventListener('pointercancel', up); h.classList.remove('drag'); document.body.classList.remove('ar-drag'); }
            h.addEventListener('pointermove', mv); h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up);
        });
    }
    function build(){
        secs().forEach(function(s){
            var k = s.dataset.k, bar = mk('div', 'ar-bar'), g = mk('button', 'ar-grip');
            g.type = 'button'; g.innerHTML = '<i class="ri-drag-move-2-line"></i>'; g.setAttribute('aria-label', 'Drag to move ' + PF.names[k][0]);
            g.addEventListener('pointerdown', function(e){ startDrag(e, s); });
            bar.appendChild(g); bar.appendChild(mk('span', 'ar-nm', PF.names[k][0]));
            var up = mk('button'); up.type = 'button'; up.innerHTML = '<i class="ri-arrow-up-s-line"></i>'; up.setAttribute('aria-label', 'Move up'); up.onclick = function(){ nudge(k, -1); };
            var dn = mk('button'); dn.type = 'button'; dn.innerHTML = '<i class="ri-arrow-down-s-line"></i>'; dn.setAttribute('aria-label', 'Move down'); dn.onclick = function(){ nudge(k, 1); };
            bar.appendChild(up); bar.appendChild(dn);
            if(PF.hideable.indexOf(k) > -1){
                var ey = mk('button'); ey.type = 'button';
                var ic = function(){ ey.innerHTML = '<i class="' + (L.hidden.indexOf(k) > -1 ? 'ri-eye-off-line' : 'ri-eye-line') + '"></i>'; ey.setAttribute('aria-label', L.hidden.indexOf(k) > -1 ? 'Show section' : 'Hide section'); };
                ic();
                ey.onclick = function(){ var j = L.hidden.indexOf(k); if(j > -1) L.hidden.splice(j, 1); else L.hidden.push(k); dirty = true; paintOrder(); ic(); renderSecs(); };
                bar.appendChild(ey);
            }
            s.insertBefore(bar, s.firstChild); chrome.push(bar);
        });
        var hd = document.querySelector('.pf-hd'), ban = $('pfBanner'), av = $('pfAv'), avl = document.querySelector('.pf-av-link'), idm = document.querySelector('.pf-idmain'), nm = document.querySelector('.pf-name');
        if(!hd) return;
        var bh = mk('button', 'ar-h ar-bh'); bh.type = 'button'; bh.innerHTML = '<i class="ri-arrow-up-down-line"></i> Height'; hd.appendChild(bh); chrome.push(bh);
        dragger(bh, function(){ return ban.offsetHeight; }, function(dx, dy, st){ setFx('bhpx', Math.round(clamp(st + dy, 40, 600))); });
        var rs = mk('button', 'ar-h ar-av'); rs.type = 'button'; rs.innerHTML = '<i class="ri-aspect-ratio-line"></i>'; rs.setAttribute('aria-label', 'Resize picture'); avl.appendChild(rs); chrome.push(rs);
        dragger(rs, function(){ return av.offsetWidth; }, function(dx, dy, st){ setFx('avpx', Math.round(clamp(st + (dx + dy) / 1.2, 30, 320))); });
        var mvr = mk('div', 'ar-mv'); mvr.innerHTML = '<i class="ri-arrow-up-down-line"></i>'; avl.appendChild(mvr); chrome.push(mvr);
        dragger(mvr, function(){ return {o: (L.avo === '' ? 50 : L.avo), s: av.offsetWidth}; }, function(dx, dy, st){ setFx('avo', Math.round(clamp(st.o - dy / st.s * 100, -60, 160))); });
        var ns = mk('button', 'ar-h ar-ns'); ns.type = 'button'; ns.innerHTML = '<i class="ri-text"></i> Name'; idm.appendChild(ns); chrome.push(ns);
        dragger(ns, function(){ return parseFloat(getComputedStyle(nm).fontSize); }, function(dx, dy, st){ setFx('nspx', Math.round(clamp(st + dx * .4, 12, 90))); });
        arPos();
    }
    function teardown(){ chrome.forEach(function(n){ if(n.parentNode) n.parentNode.removeChild(n); }); chrome = []; }
    function enter(){
        if($('editSheet').classList.contains('open')) closeEdit();
        pmClose(); L = JSON.parse(JSON.stringify(L0)); dirty = false;
        document.body.classList.add('arranging'); btn.classList.add('on'); btn.innerHTML = '<i class="ri-close-line"></i> Exit';
        paintAll(); paintOrder(); build(); dock.classList.add('show'); scrollTo(0, 0);
        toast('Drag the grips to move sections. Drag the round handles to resize.');
    }
    function exit(){
        teardown(); document.body.classList.remove('arranging', 'ar-drag'); btn.classList.remove('on'); btn.innerHTML = '<i class="ri-drag-move-2-line"></i> Arrange';
        dock.classList.remove('show'); secs().forEach(function(s){ s.style.transform = ''; s.style.transition = ''; s.classList.remove('dragging'); });
        revInit();
    }
    function cancel(){
        if(dirty && !confirm('Throw away your changes?')) return;
        L = JSON.parse(JSON.stringify(L0));
        document.querySelectorAll('.tune').forEach(function(t){ t.querySelector('input[type=hidden]').value = t.dataset.init; });
        dirty = false; exit(); paintAll(); paintOrder(); renderSecs();
    }
    btn.addEventListener('click', function(){ if(isArr()) cancel(); else enter(); });
    $('arCancel').addEventListener('click', cancel);
    $('arReset').addEventListener('click', function(){
        L.order = ['header','about','skills','experience','work','links']; L.hidden = [];
        ['avpx','bhpx','avo','nspx','avbd'].forEach(function(k){ L[k] = ''; var h = document.querySelector('#pfForm input[name="lk_' + k + '"]'); if(h) h.value = ''; });
        dirty = true; teardown(); paintOrder(); renderSecs(); paintAll(); build();
    });
    $('arSave').addEventListener('click', function(){
        var b = this; b.disabled = true;
        var fd = new FormData(); fd.append('csrf', PF.csrf); fd.append('action', 'save_layout'); fd.append('ajax', '1');
        fd.append('layout', JSON.stringify({order: L.order, hidden: L.hidden, avpx: L.avpx, bhpx: L.bhpx, avo: L.avo, nspx: L.nspx, avbd: L.avbd}));
        fetch('portfolio.php', {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(j){ if(j.ok){ dirty = false; location.reload(); } else { b.disabled = false; toast(j.error || 'Could not save. Try again.'); } })
            .catch(function(){ b.disabled = false; toast('Network error. Try again.'); });
    });
    addEventListener('beforeunload', function(e){ if(isArr() && dirty){ e.preventDefault(); e.returnValue = ''; } });
    /* while arranging, links and buttons on the page should not fire */
    document.addEventListener('click', function(e){
        if(!isArr()) return;
        var t = e.target.closest && e.target.closest('.pf-s a, .pf-s .pill');
        if(t) e.preventDefault();
    }, true);
})();

<?php if($flash): if($flash['tab'] === 'toast'): ?>toast(<?php echo json_encode($flash['ok']); ?>);<?php else: ?>openEdit(<?php echo json_encode($flash['tab']); ?>);<?php endif; endif; ?>
</script>
<?php endif; ?>
</body>
</html>