<?php
/* shrink every response (page + ajax) before it is sent */
if(!ini_get('zlib.output_compression') && function_exists('ob_gzhandler') && !headers_sent()) ob_start('ob_gzhandler');
/* ══════════════════════════════════════════════════════════════
   SESSION — hardened cookie (same as tracks.php)
══════════════════════════════════════════════════════════════ */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    /* PHP's default "no-store" stops the browser from keeping the page, so Back can't restore it instantly */
    session_cache_limiter('');
    session_start();
}
if(!headers_sent() && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') && empty($_POST['action'])) header('Cache-Control: private, no-cache, max-age=0');
include 'config.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ header("Location: login.php"); exit(); }

const SP_MAX_SHOPS    = 5;                       // shops per user
const SP_MAX_PRODUCTS = 50;                      // products per shop
const SP_MAX_MEDIA    = 30;                      // photos + videos per product
const SP_MAX_CATS     = 12;
const SP_MAX_COLS     = 20;                      // collections per shop
const SP_MAX_IMG_SIZE = 5 * 1024 * 1024;         // 5 MB per photo
const SP_MAX_VID_SIZE = 50 * 1024 * 1024;        // 50 MB per video
const SP_UPLOAD_DIR   = __DIR__ . '/uploads/products/';
const SP_UPLOAD_URL   = 'uploads/products/';
const SP_MSG_URL      = 'messages.php';

/* Accent presets: [main, hover, light text] — every main colour takes BLACK text. */
const SP_ACCENTS = [
    'red'    => ['#FF2D2D', '#ff1a1a', '#ff8a8a'],
    'orange' => ['#FF8A1F', '#ef7a10', '#ffbb7a'],
    'gold'   => ['#FFC21A', '#eeb100', '#ffe08a'],
    'green'  => ['#2ED47A', '#22bd69', '#8cf0b9'],
    'teal'   => ['#19C6C0', '#10b0aa', '#7ee8e4'],
    'sky'    => ['#3BA3FF', '#2a90ec', '#8dc9ff'],
    'violet' => ['#A47BFF', '#9065f0', '#cdb6ff'],
    'pink'   => ['#FF5FA2', '#f04b90', '#ffa6cc'],
    'darkblue' => ['#1a56c4', '#2a68de', '#7fa0ff'],
    'magenta' => ['#EE3DEB', '#dc2cd9', '#f7a0f5'],
    'neon'   => ['#C8FF2E', '#b8ef1c', '#e4ffa0'],
    'sand'   => ['#DDC297', '#cdb083', '#eedfc3'],
    'cocoa'  => ['#C98C5C', '#b87a4a', '#e3bd9c'],
    'silver' => ['#E5E7EB', '#d1d5db', '#f3f4f6'],
];

/* Every currency (ISO 4217) a seller can price in. */
const SP_CURRENCIES = [
 'USD'=>'US Dollar','EUR'=>'Euro','GBP'=>'British Pound','CAD'=>'Canadian Dollar','AUD'=>'Australian Dollar','NZD'=>'New Zealand Dollar',
 'JPY'=>'Japanese Yen','CNY'=>'Chinese Yuan','INR'=>'Indian Rupee','MXN'=>'Mexican Peso','BRL'=>'Brazilian Real','CHF'=>'Swiss Franc',
 'SEK'=>'Swedish Krona','NOK'=>'Norwegian Krone','DKK'=>'Danish Krone','PLN'=>'Polish Zloty','CZK'=>'Czech Koruna','HUF'=>'Hungarian Forint',
 'RON'=>'Romanian Leu','BGN'=>'Bulgarian Lev','ISK'=>'Icelandic Krona','TRY'=>'Turkish Lira','RUB'=>'Russian Ruble','UAH'=>'Ukrainian Hryvnia',
 'ILS'=>'Israeli Shekel','AED'=>'UAE Dirham','SAR'=>'Saudi Riyal','QAR'=>'Qatari Riyal','KWD'=>'Kuwaiti Dinar','BHD'=>'Bahraini Dinar',
 'OMR'=>'Omani Rial','JOD'=>'Jordanian Dinar','LBP'=>'Lebanese Pound','EGP'=>'Egyptian Pound','MAD'=>'Moroccan Dirham','TND'=>'Tunisian Dinar',
 'DZD'=>'Algerian Dinar','LYD'=>'Libyan Dinar','NGN'=>'Nigerian Naira','GHS'=>'Ghanaian Cedi','KES'=>'Kenyan Shilling','UGX'=>'Ugandan Shilling',
 'TZS'=>'Tanzanian Shilling','RWF'=>'Rwandan Franc','ETB'=>'Ethiopian Birr','ZAR'=>'South African Rand','ZMW'=>'Zambian Kwacha','BWP'=>'Botswana Pula',
 'NAD'=>'Namibian Dollar','MZN'=>'Mozambican Metical','AOA'=>'Angolan Kwanza','XOF'=>'West African CFA Franc','XAF'=>'Central African CFA Franc',
 'CDF'=>'Congolese Franc','MUR'=>'Mauritian Rupee','SCR'=>'Seychellois Rupee','MGA'=>'Malagasy Ariary','MWK'=>'Malawian Kwacha','SLE'=>'Sierra Leonean Leone',
 'GMD'=>'Gambian Dalasi','LRD'=>'Liberian Dollar','GNF'=>'Guinean Franc','SDG'=>'Sudanese Pound','SSP'=>'South Sudanese Pound','SOS'=>'Somali Shilling',
 'DJF'=>'Djiboutian Franc','ERN'=>'Eritrean Nakfa','CVE'=>'Cape Verdean Escudo','STN'=>'Sao Tome Dobra','KMF'=>'Comorian Franc','LSL'=>'Lesotho Loti',
 'SZL'=>'Swazi Lilangeni','ZWG'=>'Zimbabwe Gold','MRU'=>'Mauritanian Ouguiya','SGD'=>'Singapore Dollar','HKD'=>'Hong Kong Dollar','TWD'=>'New Taiwan Dollar',
 'KRW'=>'South Korean Won','THB'=>'Thai Baht','VND'=>'Vietnamese Dong','IDR'=>'Indonesian Rupiah','MYR'=>'Malaysian Ringgit','PHP'=>'Philippine Peso',
 'PKR'=>'Pakistani Rupee','BDT'=>'Bangladeshi Taka','LKR'=>'Sri Lankan Rupee','NPR'=>'Nepalese Rupee','BTN'=>'Bhutanese Ngultrum','MVR'=>'Maldivian Rufiyaa',
 'MMK'=>'Myanmar Kyat','KHR'=>'Cambodian Riel','LAK'=>'Lao Kip','BND'=>'Brunei Dollar','MNT'=>'Mongolian Tugrik','KZT'=>'Kazakhstani Tenge',
 'UZS'=>'Uzbekistani Som','KGS'=>'Kyrgyzstani Som','TJS'=>'Tajikistani Somoni','TMT'=>'Turkmenistani Manat','AZN'=>'Azerbaijani Manat','GEL'=>'Georgian Lari',
 'AMD'=>'Armenian Dram','BYN'=>'Belarusian Ruble','MDL'=>'Moldovan Leu','RSD'=>'Serbian Dinar','MKD'=>'Macedonian Denar','ALL'=>'Albanian Lek',
 'BAM'=>'Bosnia-Herzegovina Mark','AFN'=>'Afghan Afghani','IRR'=>'Iranian Rial','IQD'=>'Iraqi Dinar','SYP'=>'Syrian Pound','YER'=>'Yemeni Rial',
 'PGK'=>'Papua New Guinean Kina','FJD'=>'Fijian Dollar','WST'=>'Samoan Tala','TOP'=>'Tongan Paanga','VUV'=>'Vanuatu Vatu','SBD'=>'Solomon Islands Dollar',
 'XPF'=>'CFP Franc','ARS'=>'Argentine Peso','CLP'=>'Chilean Peso','COP'=>'Colombian Peso','PEN'=>'Peruvian Sol','UYU'=>'Uruguayan Peso',
 'PYG'=>'Paraguayan Guarani','BOB'=>'Bolivian Boliviano','VES'=>'Venezuelan Bolivar','GTQ'=>'Guatemalan Quetzal','HNL'=>'Honduran Lempira','NIO'=>'Nicaraguan Cordoba',
 'CRC'=>'Costa Rican Colon','PAB'=>'Panamanian Balboa','DOP'=>'Dominican Peso','JMD'=>'Jamaican Dollar','TTD'=>'Trinidad & Tobago Dollar','BBD'=>'Barbadian Dollar',
 'BSD'=>'Bahamian Dollar','BZD'=>'Belize Dollar','GYD'=>'Guyanese Dollar','SRD'=>'Surinamese Dollar','HTG'=>'Haitian Gourde','CUP'=>'Cuban Peso',
 'AWG'=>'Aruban Florin','ANG'=>'Netherlands Antillean Guilder','XCD'=>'East Caribbean Dollar','KYD'=>'Cayman Islands Dollar','BMD'=>'Bermudian Dollar',
 'FKP'=>'Falkland Islands Pound','GIP'=>'Gibraltar Pound','SHP'=>'Saint Helena Pound','MOP'=>'Macanese Pataca',
];
const SP_SYMBOLS = ['USD'=>'$','EUR'=>'€','GBP'=>'£','JPY'=>'¥','CNY'=>'CN¥','INR'=>'₹','NGN'=>'₦','KRW'=>'₩','CAD'=>'CA$','AUD'=>'A$','MXN'=>'MX$','BRL'=>'R$','GHS'=>'GH₵','KES'=>'KSh','ZAR'=>'R','PHP'=>'₱','TRY'=>'₺','ILS'=>'₪','VND'=>'₫','UAH'=>'₴','RUB'=>'₽'];

function sp_can_sell($conn, int $uid): bool { return true; }

/* ══════════════════════════════════════════════════════════════
   CSRF — identical to tracks.php
══════════════════════════════════════════════════════════════ */
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function csrf_same_origin(): bool {
    $host = strtolower((string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (!empty($_SERVER[$h])) {
            $src = strtolower((string)parse_url($_SERVER[$h], PHP_URL_HOST));
            return ($src !== '' && $src === $host);
        }
    }
    return true;
}
function csrf_verify(): bool {
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || $sent === '') return false;
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) return false;
    return csrf_same_origin();
}
function csrf_fail(): void {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'csrf']);
    exit();
}

/* ══════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════ */
function sp_json(array $a): void { echo json_encode($a); exit(); }

function sp_q(mysqli $c, string $sql, string $types = '', ...$p){
    $st = $c->prepare($sql);
    if(!$st) return false;
    if($types !== '') $st->bind_param($types, ...$p);
    $st->execute();
    return $st;
}
function sp_one(mysqli $c, string $sql, string $types = '', ...$p): ?array {
    $st = sp_q($c, $sql, $types, ...$p);
    if(!$st) return null;
    $r = $st->get_result();
    $row = $r ? $r->fetch_assoc() : null;
    return $row ?: null;
}
function sp_all(mysqli $c, string $sql, string $types = '', ...$p): array {
    $st = sp_q($c, $sql, $types, ...$p);
    if(!$st) return [];
    $r = $st->get_result();
    $out = [];
    if($r) while($x = $r->fetch_assoc()) $out[] = $x;
    return $out;
}
function sp_has_col(mysqli $c, string $t, string $col): bool {
    $r = sp_one($c, "SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", 'ss', $t, $col);
    return (int)($r['n'] ?? 0) > 0;
}
function sp_cur($c): string {
    $c = strtoupper(trim((string)$c));
    return isset(SP_CURRENCIES[$c]) ? $c : 'USD';
}
function sp_money($v, string $cur = 'USD'): string {
    $v = (float)$v; $cur = sp_cur($cur);
    $whole = fmod($v, 1) == 0;
    if(class_exists('NumberFormatter')){
        try {
            $f = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
            if($whole){ $f->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0); }
            $s = $f->formatCurrency($v, $cur);
            if($s !== false) return $s;
        } catch (Throwable $e) {}
    }
    $sym = SP_SYMBOLS[$cur] ?? ($cur . ' ');
    return $sym . number_format($v, $whole ? 0 : 2);
}
function sp_cur_options(string $sel = 'USD'): string {
    $o = '<optgroup label="Popular">';
    foreach(['USD','EUR','GBP','CAD','AUD','MXN'] as $c){ $o .= '<option value="'.$c.'"'.($c===$sel?' selected':'').'>'.$c.' — '.htmlspecialchars(SP_CURRENCIES[$c]).'</option>'; }
    $o .= '</optgroup><optgroup label="All currencies">';
    $all = SP_CURRENCIES; ksort($all);
    foreach($all as $c => $n){ $o .= '<option value="'.$c.'"'.($c===$sel?' selected':'').'>'.$c.' — '.htmlspecialchars($n).'</option>'; }
    return $o . '</optgroup>';
}
function sp_tz_options(string $sel = 'America/New_York'): string {
    $us = ['America/New_York'=>'Eastern (New York)','America/Chicago'=>'Central (Chicago)','America/Denver'=>'Mountain (Denver)','America/Phoenix'=>'Arizona (Phoenix)','America/Los_Angeles'=>'Pacific (Los Angeles)','America/Anchorage'=>'Alaska (Anchorage)','Pacific/Honolulu'=>'Hawaii (Honolulu)'];
    $o = '<optgroup label="United States">';
    foreach($us as $k => $n){ $o .= '<option value="'.$k.'"'.($k===$sel?' selected':'').'>'.$n.'</option>'; }
    $o .= '</optgroup><optgroup label="Everywhere else">';
    foreach(DateTimeZone::listIdentifiers() as $z){ if(isset($us[$z])) continue; $o .= '<option value="'.htmlspecialchars($z).'"'.($z===$sel?' selected':'').'>'.htmlspecialchars(str_replace('_',' ',$z)).'</option>'; }
    return $o . '</optgroup>';
}
function sp_ago($ts): string {
    if(!$ts) return '';
    $d = time() - (int)$ts;
    if($d < 60) return 'Just now';
    if($d < 3600) return floor($d / 60) . ' min ago';
    if($d < 86400){ $h = floor($d / 3600); return $h . ' hour' . ($h > 1 ? 's' : '') . ' ago'; }
    if($d < 2592000){ $n = floor($d / 86400); return $n . ' day' . ($n > 1 ? 's' : '') . ' ago'; }
    if($d < 31536000){ $n = floor($d / 2592000); return $n . ' month' . ($n > 1 ? 's' : '') . ' ago'; }
    $n = floor($d / 31536000); return $n . ' year' . ($n > 1 ? 's' : '') . ' ago';
}
function sp_rgba(string $hex, float $a): string {
    $hex = ltrim($hex, '#');
    return 'rgba(' . hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2)) . ',' . $a . ')';
}
function sp_clean_url(?string $url): ?string {
    $url = trim((string)$url);
    if($url === '' || !preg_match('~^https?://~i', $url)) return null;
    if(!filter_var($url, FILTER_VALIDATE_URL)) return null;
    return mb_substr($url, 0, 500);
}
function sp_files(string $key): array {
    $out = [];
    if(empty($_FILES[$key]) || !is_array($_FILES[$key]['name'])) return $out;
    foreach($_FILES[$key]['name'] as $i => $n){
        if($_FILES[$key]['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name'=>$n, 'tmp_name'=>$_FILES[$key]['tmp_name'][$i], 'error'=>$_FILES[$key]['error'][$i], 'size'=>$_FILES[$key]['size'][$i]];
    }
    return $out;
}
/* returns [ext, 'image'|'video', error] */
function sp_media_info(array $f, bool $allowVideo = true): array {
    if($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return [null, null, 'Upload failed — the file may be too large for the server'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $img = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
    $vid = ['video/mp4'=>'mp4', 'video/webm'=>'webm', 'video/quicktime'=>'mov'];
    if(isset($img[$mime])){
        if($f['size'] > SP_MAX_IMG_SIZE) return [null, null, 'Each photo must be under 5MB'];
        return [$img[$mime], 'image', null];
    }
    if($allowVideo && isset($vid[$mime])){
        if($f['size'] > SP_MAX_VID_SIZE) return [null, null, 'Each video must be under 50MB'];
        return [$vid[$mime], 'video', null];
    }
    return [null, null, $allowVideo ? 'Only JPG, PNG, WEBP, MP4, WEBM or MOV files' : 'Only JPG, PNG or WEBP images'];
}
function sp_save_file(array $f, string $ext): ?string {
    if(!is_dir(SP_UPLOAD_DIR)) @mkdir(SP_UPLOAD_DIR, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    return move_uploaded_file($f['tmp_name'], SP_UPLOAD_DIR . $name) ? $name : null;
}
function sp_unlink(?string $name): void {
    if(!$name) return;
    $p = SP_UPLOAD_DIR . basename($name);
    if(is_file($p)) @unlink($p);
}
/* one optional image field (banner / avatar / collection cover): [newName|oldName|null, error|null] */
function sp_single_img(string $key, ?string $cur, string $rm): array {
    if(!empty($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE){
        [$ext, $type, $err] = sp_media_info($_FILES[$key], false);
        if($err) return [$cur, $err];
        $saved = sp_save_file($_FILES[$key], $ext);
        if(!$saved) return [$cur, 'Image upload failed'];
        sp_unlink($cur);
        return [$saved, null];
    }
    if(!empty($_POST[$rm]) && $cur){ sp_unlink($cur); return [null, null]; }
    return [$cur, null];
}
/* returns category id, null (none) or false (limit reached) */
function sp_resolve_cat(mysqli $conn, int $uid){
    $new = mb_substr(trim($_POST['category_new'] ?? ''), 0, 40);
    if($new !== ''){
        $r = sp_one($conn, "SELECT id FROM shop_categories WHERE user_id=? AND name=? LIMIT 1", 'is', $uid, $new);
        if($r) return (int)$r['id'];
        $c = sp_one($conn, "SELECT COUNT(*) AS c FROM shop_categories WHERE user_id=?", 'i', $uid);
        if((int)($c['c'] ?? 0) >= SP_MAX_CATS) return false;
        sp_q($conn, "INSERT INTO shop_categories (user_id,name) VALUES (?,?)", 'is', $uid, $new);
        return (int)$conn->insert_id;
    }
    $cid = (int)($_POST['category_id'] ?? 0);
    if(!$cid) return null;
    $r = sp_one($conn, "SELECT id FROM shop_categories WHERE id=? AND user_id=? LIMIT 1", 'ii', $cid, $uid);
    return $r ? (int)$r['id'] : null;
}

/* ── business hours ── */
const SP_DAYS = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
function sp_default_hours(): array {
    $h = [];
    for($i = 0; $i < 7; $i++) $h[] = ['o' => $i < 5 ? 1 : 0, 's' => '09:00', 'e' => '17:00'];
    $h[5] = ['o' => 1, 's' => '10:00', 'e' => '14:00'];
    return $h;
}
function sp_hours_decode($json): array {
    $d = sp_default_hours();
    $a = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);
    if(!is_array($a) || count($a) !== 7) return $d;
    $out = [];
    foreach(array_values($a) as $i => $x){
        $s = (is_array($x) && isset($x['s']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$x['s'])) ? $x['s'] : $d[$i]['s'];
        $e = (is_array($x) && isset($x['e']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$x['e'])) ? $x['e'] : $d[$i]['e'];
        $out[] = ['o' => (is_array($x) && !empty($x['o'])) ? 1 : 0, 's' => $s, 'e' => $e];
    }
    return $out;
}
function sp_fmt_time(string $t): string { return date('g:i A', strtotime('2000-01-01 ' . $t)); }
function sp_hours_status(array $shop): ?array {
    if(empty($shop['hours_enabled'])) return null;
    $h = $shop['hours_arr'];
    try { $tz = new DateTimeZone($shop['tz']); } catch (Throwable $e) { $tz = new DateTimeZone('America/New_York'); }
    $now = new DateTime('now', $tz);
    $dow = (int)$now->format('N') - 1;
    $cur = $now->format('H:i');
    $d = $h[$dow];
    if($d['o']){
        if($d['s'] <= $d['e']){ if($cur >= $d['s'] && $cur < $d['e']) return [true, 'Open now · Closes ' . sp_fmt_time($d['e'])]; }
        elseif($cur >= $d['s']) return [true, 'Open now · Closes ' . sp_fmt_time($d['e'])];
    }
    $p = $h[($dow + 6) % 7];
    if($p['o'] && $p['s'] > $p['e'] && $cur < $p['e']) return [true, 'Open now · Closes ' . sp_fmt_time($p['e'])];
    for($i = 0; $i < 8; $i++){
        $idx = ($dow + $i) % 7; $x = $h[$idx];
        if(!$x['o']) continue;
        if($i === 0 && $cur >= $x['s']) continue;
        $label = $i === 0 ? 'today' : ($i === 1 ? 'tomorrow' : substr(SP_DAYS[$idx], 0, 3));
        return [false, 'Closed · Opens ' . $label . ' ' . sp_fmt_time($x['s'])];
    }
    return [false, 'Closed'];
}

/* ── shops ── */
const SP_MAX_FAQ = 8;
const SP_SECS   = ['header','announce','about','featured','collections','favs','products'];
const SP_HIDEABLE = ['announce','about','featured','collections','favs'];
function sp_look_norm($raw): array {
    $d = ['order' => SP_SECS, 'hidden' => [], 'banner' => 1, 'avshow' => 1, 'bh' => 'normal', 'avpos' => 'right', 'avshape' => 'circle',
          'avsize' => 'md', 'head' => 'plain'];
    $a = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    $pick = function($v, array $ok, $def){ return in_array($v, $ok, true) ? $v : $def; };
    $d['banner']  = (isset($a['banner']) && !(int)$a['banner']) ? 0 : 1;
    $d['avshow']  = (isset($a['avshow']) && !(int)$a['avshow']) ? 0 : 1;
    $d['bh']      = $pick($a['bh'] ?? '', ['short','normal','tall'], 'normal');
    $d['avpos']   = $pick($a['avpos'] ?? '', ['left','center','right'], 'right');
    $d['avshape'] = $pick($a['avshape'] ?? '', ['circle','rounded','square'], 'circle');
    $d['avsize']  = $pick($a['avsize'] ?? '', ['sm','md','lg'], 'md');
    $d['head']    = $pick($a['head'] ?? '', ['plain','caps','line'], 'plain');
    $ord = [];
    foreach((array)($a['order'] ?? []) as $k){ if(in_array($k, SP_SECS, true) && !in_array($k, $ord, true)) $ord[] = $k; }
    foreach(SP_SECS as $k){ if(!in_array($k, $ord, true)) $ord[] = $k; }
    $hi = array_search('header', $ord, true);          /* the header may only sit in the 1st or 2nd place */
    if($hi > 1){ array_splice($ord, $hi, 1); array_unshift($ord, 'header'); }
    $d['order'] = $ord;
    $d['hidden'] = array_values(array_intersect((array)($a['hidden'] ?? []), SP_HIDEABLE));
    return $d;
}
function sp_policies_norm($raw): array {
    $a = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    $o = [];
    foreach(['shipping','returns','payment'] as $k) $o[$k] = mb_substr(trim((string)($a[$k] ?? '')), 0, 800);
    return $o;
}
function sp_faq_norm($raw): array {
    $a = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    $o = [];
    foreach($a as $it){
        if(!is_array($it)) continue;
        $q = mb_substr(trim((string)($it['q'] ?? '')), 0, 120); $ans = mb_substr(trim((string)($it['a'] ?? '')), 0, 500);
        if($q !== '' && $ans !== '') $o[] = ['q' => $q, 'a' => $ans];
        if(count($o) >= SP_MAX_FAQ) break;
    }
    return $o;
}
function sp_shop_norm(array $r): array {
    $r['policies_arr'] = sp_policies_norm($r['policies'] ?? null);
    $r['faq_arr'] = sp_faq_norm($r['faq'] ?? null);
    $r['look'] = sp_look_norm($r['look'] ?? null);
    $r['id'] = (int)$r['id']; $r['user_id'] = (int)$r['user_id'];
    if(!isset(SP_ACCENTS[$r['accent']])) $r['accent'] = 'red';
    if(!in_array($r['layout'], ['grid','compact','list'], true)) $r['layout'] = 'grid';
    if(!in_array($r['corners'], ['soft','square'], true)) $r['corners'] = 'soft';
    $r['show_favs'] = (int)$r['show_favs']; $r['is_active'] = (int)$r['is_active']; $r['hours_enabled'] = (int)$r['hours_enabled'];
    $r['currency'] = sp_cur($r['currency']);
    $r['hours_arr'] = sp_hours_decode($r['hours'] ?? null);
    if(empty($r['tz'])) $r['tz'] = 'America/New_York';
    return $r;
}
function sp_my_shop(mysqli $c, int $uid, int $sid): ?array {
    $r = sp_one($c, "SELECT * FROM shops WHERE id=? AND user_id=? LIMIT 1", 'ii', $sid, $uid);
    return $r ? sp_shop_norm($r) : null;
}
/* returns this user's shops; moves an old single-shop setup into the first shop automatically */
function sp_ensure_shops(mysqli $c, int $uid, bool $create): array {
    $rows = sp_all($c, "SELECT * FROM shops WHERE user_id=? ORDER BY id", 'i', $uid);
    if($rows) return $rows;
    $legacy = null;
    try { $legacy = sp_one($c, "SELECT * FROM shop_settings WHERE user_id=? LIMIT 1", 'i', $uid); } catch (Throwable $e) {}
    $o = sp_one($c, "SELECT COUNT(*) AS n FROM products WHERE user_id=? AND shop_id IS NULL", 'i', $uid);
    $hasOrph = (int)($o['n'] ?? 0) > 0;
    if(!$legacy && !$hasOrph && !$create) return [];
    $L = $legacy ?: [];
    $cur = $hasOrph ? 'NGN' : 'USD';
    sp_q($c, "INSERT INTO shops (user_id,shop_name,tagline,about,location,announcement,banner,accent,layout,corners,show_favs,currency,website,whatsapp,instagram) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        'isssssssssissss', $uid, (string)($L['shop_name'] ?? ''), (string)($L['tagline'] ?? ''), (string)($L['about'] ?? ''), (string)($L['location'] ?? ''),
        (string)($L['announcement'] ?? ''), ($L['banner'] ?? null), (string)($L['accent'] ?? 'red'), (string)($L['layout'] ?? 'grid'), (string)($L['corners'] ?? 'soft'),
        (int)($L['show_favs'] ?? 1), $cur, (string)($L['website'] ?? ''), (string)($L['whatsapp'] ?? ''), (string)($L['instagram'] ?? ''));
    $sid = (int)$c->insert_id;
    if($sid){
        sp_q($c, "UPDATE products SET shop_id=? WHERE user_id=? AND shop_id IS NULL", 'ii', $sid, $uid);
        if(!empty($L['featured_id'])) sp_q($c, "UPDATE products SET is_featured=1 WHERE id=? AND user_id=?", 'ii', (int)$L['featured_id'], $uid);
    }
    return sp_all($c, "SELECT * FROM shops WHERE user_id=? ORDER BY id", 'i', $uid);
}

/* ══════════════════════════════════════════════════════════════
   TABLES — created / upgraded automatically (once per login session)
══════════════════════════════════════════════════════════════ */
if(empty($_SESSION['sp_schema_v6'])){
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS shops (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
            shop_name VARCHAR(60) NOT NULL DEFAULT '', tagline VARCHAR(100) NOT NULL DEFAULT '', about VARCHAR(400) NOT NULL DEFAULT '',
            location VARCHAR(255) NOT NULL DEFAULT '', announcement VARCHAR(140) NOT NULL DEFAULT '', banner VARCHAR(120) NULL, avatar VARCHAR(120) NULL,
            accent VARCHAR(12) NOT NULL DEFAULT 'red', layout VARCHAR(10) NOT NULL DEFAULT 'grid', corners VARCHAR(10) NOT NULL DEFAULT 'soft',
            show_favs TINYINT NOT NULL DEFAULT 1, currency CHAR(3) NOT NULL DEFAULT 'USD', website VARCHAR(500) NOT NULL DEFAULT '',
            whatsapp VARCHAR(20) NOT NULL DEFAULT '', instagram VARCHAR(40) NOT NULL DEFAULT '', hours_enabled TINYINT NOT NULL DEFAULT 0,
            hours TEXT NULL, tz VARCHAR(40) NOT NULL DEFAULT 'America/New_York', is_active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS shop_collections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            name VARCHAR(60) NOT NULL, description VARCHAR(200) NOT NULL DEFAULT '', cover VARCHAR(120) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_shop (shop_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS shop_collection_items (
            collection_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (collection_id, product_id), INDEX idx_product (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS shop_reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL DEFAULT 0,
            user_id INT UNSIGNED NOT NULL, rating TINYINT NOT NULL, body VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_review (shop_id, product_id, user_id), INDEX idx_product (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS shop_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(40) NOT NULL,
            INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, shop_id INT UNSIGNED NULL, category_id INT UNSIGNED NULL,
            title VARCHAR(120) NOT NULL, price DECIMAL(14,2) NOT NULL DEFAULT 0, old_price DECIMAL(14,2) NULL, currency CHAR(3) NOT NULL DEFAULT 'USD',
            in_stock TINYINT NOT NULL DEFAULT 1, is_featured TINYINT NOT NULL DEFAULT 0, description TEXT NULL,
            discount_pct TINYINT NOT NULL DEFAULT 0, off_sale TINYINT NOT NULL DEFAULT 0,
            cta_type VARCHAR(10) NOT NULL DEFAULT 'message', cta_url VARCHAR(500) NULL, is_active TINYINT NOT NULL DEFAULT 1,
            clicks INT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), INDEX idx_shop (shop_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS product_images (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, image VARCHAR(120) NOT NULL,
            media_type VARCHAR(5) NOT NULL DEFAULT 'image', sort_order INT NOT NULL DEFAULT 0,
            INDEX idx_product (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS product_saves (
            user_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, PRIMARY KEY (user_id, product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS product_reports (
            product_id INT UNSIGNED NOT NULL, reporter_id INT UNSIGNED NOT NULL, reason VARCHAR(20) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (product_id, reporter_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* upgrade tables that existed before this version */
        if(!sp_has_col($conn, 'products', 'shop_id'))     $conn->query("ALTER TABLE products ADD COLUMN shop_id INT UNSIGNED NULL, ADD INDEX idx_shop (shop_id)");
        if(!sp_has_col($conn, 'products', 'currency')){   $conn->query("ALTER TABLE products ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'NGN'"); $conn->query("ALTER TABLE products ALTER COLUMN currency SET DEFAULT 'USD'"); }
        if(!sp_has_col($conn, 'products', 'in_stock'))    $conn->query("ALTER TABLE products ADD COLUMN in_stock TINYINT NOT NULL DEFAULT 1");
        if(!sp_has_col($conn, 'products', 'is_featured')) $conn->query("ALTER TABLE products ADD COLUMN is_featured TINYINT NOT NULL DEFAULT 0");
        if(!sp_has_col($conn, 'products', 'clicks'))      $conn->query("ALTER TABLE products ADD COLUMN clicks INT UNSIGNED NOT NULL DEFAULT 0");
        if(!sp_has_col($conn, 'shops', 'look')) $conn->query("ALTER TABLE shops ADD COLUMN look TEXT NULL");
        if(!sp_has_col($conn, 'products', 'discount_pct')) $conn->query("ALTER TABLE products ADD COLUMN discount_pct TINYINT NOT NULL DEFAULT 0");
        if(!sp_has_col($conn, 'products', 'off_sale'))     $conn->query("ALTER TABLE products ADD COLUMN off_sale TINYINT NOT NULL DEFAULT 0");
        if(!sp_has_col($conn, 'products', 'sale_ends'))    $conn->query("ALTER TABLE products ADD COLUMN sale_ends INT UNSIGNED NULL");
        if(!sp_has_col($conn, 'shops', 'policies'))        $conn->query("ALTER TABLE shops ADD COLUMN policies TEXT NULL");
        if(!sp_has_col($conn, 'shops', 'faq'))             $conn->query("ALTER TABLE shops ADD COLUMN faq TEXT NULL");
        $conn->query("ALTER TABLE products MODIFY description TEXT NULL");
        if(!sp_has_col($conn, 'product_images', 'media_type')) $conn->query("ALTER TABLE product_images ADD COLUMN media_type VARCHAR(5) NOT NULL DEFAULT 'image'");
        $_SESSION['sp_schema_v6'] = 1;
    } catch (Throwable $e) {}
}

$profile_id = (isset($_GET['user_id']) && (int)$_GET['user_id'] > 0) ? (int)$_GET['user_id'] : $current_user;
$is_own_profile = ($current_user === $profile_id);

$user = sp_one($conn, "SELECT id, username, profile_pic, nickname FROM users WHERE id=? LIMIT 1", 'i', $profile_id);
if(!$user){ header("Location: 404.php"); exit(); }

/* ══════════════════════════════════════════════════════════════
   MESSAGE RULES (same as profile.php)
   - hidden if either side blocked the other
   - hidden if the owner turned "Allow messages" off
   - Public profiles: anyone can message; Limited / Private / Full Private: only people who track them
══════════════════════════════════════════════════════════════ */
$is_blocked_by_owner = false; $is_blocked = false; $dz_follows = false;
if ($current_user && $current_user != $profile_id) {
    try {
        $st = $conn->prepare("SELECT blocker_id FROM blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?)");
        $st->bind_param("iiii", $profile_id, $current_user, $current_user, $profile_id);
        $st->execute();
        $br = $st->get_result();
        while ($b = $br->fetch_assoc()) {
            if ((int)$b['blocker_id'] === $profile_id)   $is_blocked_by_owner = true;
            if ((int)$b['blocker_id'] === $current_user) $is_blocked = true;
        }
        $st->close();
    } catch (Throwable $e) {}
    try {
        $fq = $conn->prepare("SELECT 1 FROM followers WHERE follower_id=? AND following_id=? LIMIT 1");
        $fq->bind_param("ii", $current_user, $profile_id);
        $fq->execute();
        $dz_follows = $fq->get_result()->num_rows > 0;
        $fq->close();
    } catch (Throwable $e) {}
}
if (!function_exists('dz_privacy_load')) {
    function dz_privacy_load($uid) {
        global $conn;
        static $cache = [];
        $uid = (int)$uid;
        if (isset($cache[$uid])) return $cache[$uid];
        $out = ['level' => 'public', 'is_private' => false, 'allow_messages' => true, 'show_online' => true];
        if ($uid <= 0) return $cache[$uid] = $out;
        $flag = false; $lvl = '';
        try { $r = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id = $uid LIMIT 1");
              if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) $flag = !empty($row['is_fully_private']); } catch (Throwable $e) {}
        try { $r = $conn->query("SELECT allow_messages, show_online_status FROM user_settings WHERE user_id = $uid LIMIT 1");
              if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) {
                  if ($row['allow_messages'] !== null)     $out['allow_messages'] = ((int)$row['allow_messages'] === 1);
                  if ($row['show_online_status'] !== null) $out['show_online']    = ((int)$row['show_online_status'] === 1);
              } } catch (Throwable $e) {}
        try { $r = $conn->query("SELECT privacy_level FROM user_privacy WHERE user_id = $uid LIMIT 1");
              if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) $lvl = (string)$row['privacy_level']; } catch (Throwable $e) {}
        $needs = in_array($lvl, ['private','full'], true);
        if ($flag && !$needs)     $lvl = 'full';
        elseif (!$flag && $needs) $lvl = 'public';
        elseif (!in_array($lvl, ['public','limited','private','full'], true)) $lvl = 'public';
        $out['level'] = $lvl;
        $out['is_private'] = in_array($lvl, ['private','full'], true);
        return $cache[$uid] = $out;
    }
}
$dz_priv        = dz_privacy_load($profile_id);
$dz_msg_enabled = !empty($dz_priv['allow_messages']);
$dz_msg_open    = ($dz_priv['level'] === 'public') || $dz_follows;
$can_message    = ($current_user != $profile_id) && !$is_blocked && !$is_blocked_by_owner && $dz_msg_enabled && $dz_msg_open;

/* ══════════════════════════════════════════════════════════════
   POST ACTIONS (all behind the CSRF gate; writes always use $current_user)
══════════════════════════════════════════════════════════════ */
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if(empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) sp_json(['status'=>'error','msg'=>'That upload was too big for the server. Try fewer or smaller files at a time.']);
    if(!csrf_verify()){ csrf_fail(); }

    $action = $_POST['action'] ?? '';

    /* ── create another shop (max 5) ── */
    if($action === 'create_shop'){
        if(!sp_can_sell($conn, $current_user)) sp_json(['status'=>'error','msg'=>'Shop is not available for your account']);
        $c = sp_one($conn, "SELECT COUNT(*) AS c, MIN(currency) AS cur FROM shops WHERE user_id=?", 'i', $current_user);
        if((int)($c['c'] ?? 0) >= SP_MAX_SHOPS) sp_json(['status'=>'error','msg'=>'You can have up to '.SP_MAX_SHOPS.' shops']);
        sp_q($conn, "INSERT INTO shops (user_id,currency,hours) VALUES (?,?,?)", 'iss', $current_user, sp_cur($c['cur'] ?? 'USD'), json_encode(sp_default_hours()));
        sp_json(['status'=>'ok','id'=>(int)$conn->insert_id]);
    }

    /* ── turn a shop off / on (only the owner sees an off shop) ── */
    if($action === 'set_active'){
        $shop = sp_my_shop($conn, $current_user, (int)($_POST['shop_id'] ?? 0));
        if(!$shop) sp_json(['status'=>'error','msg'=>'Shop not found']);
        sp_q($conn, "UPDATE shops SET is_active=? WHERE id=? AND user_id=?", 'iii', !empty($_POST['active']) ? 1 : 0, $shop['id'], $current_user);
        sp_json(['status'=>'ok']);
    }

    /* ── delete a whole shop ── */
    if($action === 'delete_shop'){
        $shop = sp_my_shop($conn, $current_user, (int)($_POST['shop_id'] ?? 0));
        if(!$shop) sp_json(['status'=>'error','msg'=>'Shop not found']);
        $sid = $shop['id'];
        foreach(sp_all($conn, "SELECT id FROM products WHERE shop_id=? AND user_id=?", 'ii', $sid, $current_user) as $pr){
            $pid = (int)$pr['id'];
            foreach(sp_all($conn, "SELECT image FROM product_images WHERE product_id=?", 'i', $pid) as $im) sp_unlink($im['image']);
            sp_q($conn, "DELETE FROM product_images WHERE product_id=?", 'i', $pid);
            sp_q($conn, "DELETE FROM product_saves WHERE product_id=?", 'i', $pid);
            sp_q($conn, "DELETE FROM products WHERE id=?", 'i', $pid);
        }
        foreach(sp_all($conn, "SELECT id, cover FROM shop_collections WHERE shop_id=?", 'i', $sid) as $col){
            sp_unlink($col['cover']);
            sp_q($conn, "DELETE FROM shop_collection_items WHERE collection_id=?", 'i', (int)$col['id']);
        }
        sp_q($conn, "DELETE FROM shop_collections WHERE shop_id=?", 'i', $sid);
        sp_q($conn, "DELETE FROM shop_reviews WHERE shop_id=?", 'i', $sid);
        sp_unlink($shop['banner']); sp_unlink($shop['avatar']);
        sp_q($conn, "DELETE FROM shops WHERE id=? AND user_id=?", 'ii', $sid, $current_user);
        sp_json(['status'=>'ok']);
    }

    /* ── add / edit product (details only; photos & videos upload in batches afterwards) ── */
    if($action === 'save_product'){
        if(!sp_can_sell($conn, $current_user)) sp_json(['status'=>'error','msg'=>'Shop is not available for your account']);
        $shop = sp_my_shop($conn, $current_user, (int)($_POST['shop_id'] ?? 0));
        if(!$shop) sp_json(['status'=>'error','msg'=>'Shop not found']);

        $id    = (int)($_POST['id'] ?? 0);
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 120);
        $info  = mb_substr(trim($_POST['description'] ?? ''), 0, 1000);
        $price = round((float)($_POST['price'] ?? 0), 2);
        $disc  = max(0, min(90, (int)($_POST['discount_pct'] ?? 0)));
        $offSale = !empty($_POST['off_sale']) ? 1 : 0;
        $cur   = sp_cur($_POST['currency'] ?? $shop['currency']);
        $inStock = !empty($_POST['in_stock']) ? 1 : 0;
        $feat    = !empty($_POST['is_featured']) ? 1 : 0;
        $cta   = (($_POST['cta_type'] ?? 'message') === 'link') ? 'link' : 'message';
        $url   = $cta === 'link' ? sp_clean_url($_POST['cta_url'] ?? '') : null;
        $newCount = max(0, (int)($_POST['new_count'] ?? 0));

        if($title === '') sp_json(['status'=>'error','msg'=>'Product name is required']);
        if($price < 0 || $price > 999999999) sp_json(['status'=>'error','msg'=>'Invalid price']);
        /* the price typed in is the regular price; a discount lowers it and keeps the regular price as the struck-through one */
        $old = null;
        if($disc > 0 && $price > 0){ $old = $price; $price = round($price * (1 - $disc / 100), 2); } else { $disc = 0; }
        if($cta === 'link' && !$url) sp_json(['status'=>'error','msg'=>'Enter a valid http/https link']);
        /* flash sale: optional end time (unix seconds), only with a discount */
        $saleEnds = (int)($_POST['sale_ends'] ?? 0);
        if($disc > 0 && $saleEnds > 0){
            if($saleEnds <= time() + 30) sp_json(['status'=>'error','msg'=>'The sale end time must be in the future']);
            if($saleEnds > time() + 90 * 86400) sp_json(['status'=>'error','msg'=>'A sale can run for up to 90 days']);
        } else $saleEnds = 0;
        $saleEndsDb = $saleEnds > 0 ? $saleEnds : null;

        $catId = sp_resolve_cat($conn, $current_user);
        if($catId === false) sp_json(['status'=>'error','msg'=>'Category limit reached ('.SP_MAX_CATS.')']);

        $remaining = 0; $toRemove = [];
        if($id){
            $own = sp_one($conn, "SELECT id FROM products WHERE id=? AND user_id=? AND shop_id=? AND is_active=1", 'iii', $id, $current_user, $shop['id']);
            if(!$own) sp_json(['status'=>'error','msg'=>'Product not found']);
            $rm = array_filter(array_map('intval', explode(',', (string)($_POST['remove_images'] ?? ''))));
            foreach(sp_all($conn, "SELECT id, image FROM product_images WHERE product_id=?", 'i', $id) as $im){
                if(in_array((int)$im['id'], $rm, true)) $toRemove[] = $im; else $remaining++;
            }
        } else {
            $c = sp_one($conn, "SELECT COUNT(*) AS c FROM products WHERE shop_id=? AND user_id=? AND is_active=1", 'ii', $shop['id'], $current_user);
            if((int)($c['c'] ?? 0) >= SP_MAX_PRODUCTS) sp_json(['status'=>'error','msg'=>'This shop is full ('.SP_MAX_PRODUCTS.' products)']);
        }
        $total = $remaining + $newCount;
        if($total < 1) sp_json(['status'=>'error','msg'=>'Add at least one photo or video']);
        if($total > SP_MAX_MEDIA) sp_json(['status'=>'error','msg'=>'Max '.SP_MAX_MEDIA.' photos and videos per product']);

        if($id){
            sp_q($conn, "UPDATE products SET title=?, price=?, old_price=?, currency=?, in_stock=?, is_featured=?, category_id=?, description=?, cta_type=?, cta_url=?, discount_pct=?, off_sale=?, sale_ends=? WHERE id=? AND user_id=?",
                 'sddsiiisssiiiii', $title, $price, $old, $cur, $inStock, $feat, $catId, $info, $cta, $url, $disc, $offSale, $saleEndsDb, $id, $current_user);
            foreach($toRemove as $im){
                sp_q($conn, "DELETE FROM product_images WHERE id=? AND product_id=?", 'ii', (int)$im['id'], $id);
                sp_unlink($im['image']);
            }
        } else {
            /* new products stay hidden until their media finished uploading (publish_product) */
            sp_q($conn, "INSERT INTO products (user_id,shop_id,category_id,title,price,old_price,currency,in_stock,is_featured,description,cta_type,cta_url,discount_pct,off_sale,sale_ends,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)",
                 'iiisddsiisssiii', $current_user, $shop['id'], $catId, $title, $price, $old, $cur, $inStock, $feat, $info, $cta, $url, $disc, $offSale, $saleEndsDb);
            $id = (int)$conn->insert_id;
        }
        sp_json(['status'=>'ok','id'=>$id]);
    }

    /* ── upload a batch of photos / videos to a product ── */
    if($action === 'upload_media'){
        $pid = (int)($_POST['product_id'] ?? 0);
        $p = sp_one($conn, "SELECT id FROM products WHERE id=? AND user_id=?", 'ii', $pid, $current_user);
        if(!$p) sp_json(['status'=>'error','msg'=>'Product not found']);
        $files = sp_files('media');
        if(!$files) sp_json(['status'=>'error','msg'=>'No files received']);
        $c = sp_one($conn, "SELECT COUNT(*) AS c, COALESCE(MAX(sort_order),-1) AS m FROM product_images WHERE product_id=?", 'i', $pid);
        $have = (int)($c['c'] ?? 0); $ord = (int)($c['m'] ?? -1) + 1;
        $saved = 0; $first = '';
        foreach($files as $f){
            if($have + $saved >= SP_MAX_MEDIA){ $first = $first ?: 'Max '.SP_MAX_MEDIA.' photos and videos per product'; break; }
            [$ext, $type, $err] = sp_media_info($f, true);
            if($err){ $first = $first ?: $err; continue; }
            $name = sp_save_file($f, $ext);
            if(!$name){ $first = $first ?: 'Upload failed'; continue; }
            sp_q($conn, "INSERT INTO product_images (product_id,image,media_type,sort_order) VALUES (?,?,?,?)", 'issi', $pid, $name, $type, $ord++);
            $saved++;
        }
        sp_json(['status'=>$saved ? 'ok' : 'error', 'saved'=>$saved, 'msg'=>$first]);
    }

    /* ── go live once the media is in ── */
    if($action === 'publish_product'){
        $pid = (int)($_POST['product_id'] ?? 0);
        $p = sp_one($conn, "SELECT id, is_active FROM products WHERE id=? AND user_id=?", 'ii', $pid, $current_user);
        if(!$p) sp_json(['status'=>'error','msg'=>'Product not found']);
        $n = sp_one($conn, "SELECT COUNT(*) AS c FROM product_images WHERE product_id=?", 'i', $pid);
        if((int)($n['c'] ?? 0) > 0){
            sp_q($conn, "UPDATE products SET is_active=1 WHERE id=? AND user_id=?", 'ii', $pid, $current_user);
            sp_json(['status'=>'ok']);
        }
        if(!(int)$p['is_active']) sp_q($conn, "DELETE FROM products WHERE id=? AND user_id=?", 'ii', $pid, $current_user);
        sp_json(['status'=>'error','msg'=>'None of your files could be uploaded. Check their size and format.']);
    }

    /* ── save the shop design / settings ── */
    if($action === 'save_shop'){
        if(!sp_can_sell($conn, $current_user)) sp_json(['status'=>'error','msg'=>'Shop is not available for your account']);
        $old = sp_my_shop($conn, $current_user, (int)($_POST['shop_id'] ?? 0));
        if(!$old) sp_json(['status'=>'error','msg'=>'Shop not found']);

        $name = mb_substr(trim($_POST['shop_name'] ?? ''), 0, 60);
        $tag  = mb_substr(trim($_POST['tagline'] ?? ''), 0, 100);
        $about= mb_substr(trim($_POST['about'] ?? ''), 0, 400);
        $loc  = mb_substr(trim($_POST['location'] ?? ''), 0, 255);
        $ann  = mb_substr(trim($_POST['announcement'] ?? ''), 0, 140);
        $accent = (string)($_POST['accent'] ?? 'red');   if(!isset(SP_ACCENTS[$accent])) $accent = 'red';
        $layout = (string)($_POST['layout'] ?? 'grid');  if(!in_array($layout, ['grid','compact','list'], true)) $layout = 'grid';
        $corners = (string)($_POST['corners'] ?? 'soft'); if(!in_array($corners, ['soft','square'], true)) $corners = 'soft';
        $showFavs = !empty($_POST['show_favs']) ? 1 : 0;
        $cur = sp_cur($_POST['currency'] ?? 'USD');

        $website = (string)(sp_clean_url($_POST['website'] ?? '') ?? '');
        if(trim($_POST['website'] ?? '') !== '' && $website === '') sp_json(['status'=>'error','msg'=>'Enter a valid website link (http/https)']);
        $wa = substr(preg_replace('/\D/', '', (string)($_POST['whatsapp'] ?? '')), 0, 15);
        if($wa !== '' && strlen($wa) < 7) sp_json(['status'=>'error','msg'=>'Enter a valid WhatsApp number with country code']);
        $ig = (string)($old['instagram'] ?? '');

        $lookJson = isset($_POST['look']) ? json_encode(sp_look_norm(json_decode((string)$_POST['look'], true))) : json_encode($old['look']);
        $polJson = isset($_POST['policies']) ? json_encode(sp_policies_norm(json_decode((string)$_POST['policies'], true))) : json_encode($old['policies_arr']);
        $faqJson = isset($_POST['faq']) ? json_encode(sp_faq_norm(json_decode((string)$_POST['faq'], true))) : json_encode($old['faq_arr']);
        $hoursOn = !empty($_POST['hours_enabled']) ? 1 : 0;
        $hoursJson = json_encode(sp_hours_decode($_POST['hours'] ?? null));
        $tz = (string)($_POST['tz'] ?? 'America/New_York');
        if(!in_array($tz, DateTimeZone::listIdentifiers(), true)) $tz = 'America/New_York';

        [$banner, $e1] = sp_single_img('banner', $old['banner'], 'remove_banner');
        if($e1) sp_json(['status'=>'error','msg'=>$e1]);
        [$avatar, $e2] = sp_single_img('avatar', $old['avatar'], 'remove_avatar');
        if($e2) sp_json(['status'=>'error','msg'=>$e2]);

        sp_q($conn, "UPDATE shops SET shop_name=?, tagline=?, about=?, location=?, announcement=?, banner=?, avatar=?, accent=?, layout=?, corners=?,
                show_favs=?, currency=?, website=?, whatsapp=?, instagram=?, hours_enabled=?, hours=?, tz=?, look=?, policies=?, faq=? WHERE id=? AND user_id=?",
            'ssssssssssissssisssssii', $name, $tag, $about, $loc, $ann, $banner, $avatar, $accent, $layout, $corners,
            $showFavs, $cur, $website, $wa, $ig, $hoursOn, $hoursJson, $tz, $lookJson, $polJson, $faqJson, $old['id'], $current_user);
        sp_json(['status'=>'ok']);
    }

    /* ── collections ── */
    if($action === 'save_collection'){
        $shop = sp_my_shop($conn, $current_user, (int)($_POST['shop_id'] ?? 0));
        if(!$shop) sp_json(['status'=>'error','msg'=>'Shop not found']);
        $id   = (int)($_POST['id'] ?? 0);
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 60);
        $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 200);
        if($name === '') sp_json(['status'=>'error','msg'=>'Give your collection a name']);
        $want = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['product_ids'] ?? ''))))));
        if(!$want) sp_json(['status'=>'error','msg'=>'Pick at least one product']);
        $okIds = [];
        foreach(sp_all($conn, "SELECT id FROM products WHERE shop_id=? AND user_id=? AND is_active=1", 'ii', $shop['id'], $current_user) as $r) $okIds[(int)$r['id']] = true;
        $want = array_values(array_filter($want, fn($x) => isset($okIds[$x])));
        if(!$want) sp_json(['status'=>'error','msg'=>'Pick at least one product']);

        $col = null;
        if($id){
            $col = sp_one($conn, "SELECT * FROM shop_collections WHERE id=? AND shop_id=? AND user_id=?", 'iii', $id, $shop['id'], $current_user);
            if(!$col) sp_json(['status'=>'error','msg'=>'Collection not found']);
        } else {
            $c = sp_one($conn, "SELECT COUNT(*) AS c FROM shop_collections WHERE shop_id=?", 'i', $shop['id']);
            if((int)($c['c'] ?? 0) >= SP_MAX_COLS) sp_json(['status'=>'error','msg'=>'Collection limit reached ('.SP_MAX_COLS.')']);
        }
        [$cover, $err] = sp_single_img('cover', $col['cover'] ?? null, 'remove_cover');
        if($err) sp_json(['status'=>'error','msg'=>$err]);

        if($id){
            sp_q($conn, "UPDATE shop_collections SET name=?, description=?, cover=? WHERE id=? AND user_id=?", 'sssii', $name, $desc, $cover, $id, $current_user);
        } else {
            sp_q($conn, "INSERT INTO shop_collections (shop_id,user_id,name,description,cover) VALUES (?,?,?,?,?)", 'iisss', $shop['id'], $current_user, $name, $desc, $cover);
            $id = (int)$conn->insert_id;
        }
        sp_q($conn, "DELETE FROM shop_collection_items WHERE collection_id=?", 'i', $id);
        foreach($want as $i => $pid) sp_q($conn, "INSERT IGNORE INTO shop_collection_items (collection_id,product_id,sort_order) VALUES (?,?,?)", 'iii', $id, $pid, $i);
        sp_json(['status'=>'ok','id'=>$id]);
    }
    if($action === 'delete_collection'){
        $col = sp_one($conn, "SELECT id, cover FROM shop_collections WHERE id=? AND user_id=?", 'ii', (int)($_POST['id'] ?? 0), $current_user);
        if(!$col) sp_json(['status'=>'error']);
        sp_unlink($col['cover']);
        sp_q($conn, "DELETE FROM shop_collection_items WHERE collection_id=?", 'i', (int)$col['id']);
        sp_q($conn, "DELETE FROM shop_collections WHERE id=? AND user_id=?", 'ii', (int)$col['id'], $current_user);
        sp_json(['status'=>'ok']);
    }

    /* ── delete (soft) ── */
    if($action === 'delete_product'){
        $id = (int)($_POST['product_id'] ?? 0);
        $st = sp_q($conn, "UPDATE products SET is_active=0 WHERE id=? AND user_id=?", 'ii', $id, $current_user);
        sp_json(['status' => ($st && $st->affected_rows > 0) ? 'ok' : 'error']);
    }

    /* ── bookmark ── */
    if($action === 'toggle_save'){
        $id = (int)($_POST['product_id'] ?? 0);
        $p = sp_one($conn, "SELECT id, user_id FROM products WHERE id=? AND is_active=1", 'i', $id);
        if(!$p || (int)$p['user_id'] === $current_user) sp_json(['status'=>'error']);
        $has = sp_one($conn, "SELECT 1 AS x FROM product_saves WHERE user_id=? AND product_id=?", 'ii', $current_user, $id);
        if($has){ sp_q($conn, "DELETE FROM product_saves WHERE user_id=? AND product_id=?", 'ii', $current_user, $id); sp_json(['status'=>'ok','saved'=>false]); }
        sp_q($conn, "INSERT IGNORE INTO product_saves (user_id,product_id) VALUES (?,?)", 'ii', $current_user, $id);
        sp_json(['status'=>'ok','saved'=>true]);
    }

    /* ── click counter (Ask / Visit) — feeds "Popular" ── */
    if($action === 'track_click'){
        $id = (int)($_POST['product_id'] ?? 0);
        sp_q($conn, "UPDATE products SET clicks=clicks+1 WHERE id=? AND is_active=1 AND user_id<>?", 'ii', $id, $current_user);
        sp_json(['status'=>'ok']);
    }

    /* ── report ── */
    if($action === 'report_product'){
        $id = (int)($_POST['product_id'] ?? 0);
        $reason = $_POST['reason'] ?? '';
        if(!in_array($reason, ['scam','prohibited','misleading','other'], true)) sp_json(['status'=>'error']);
        $p = sp_one($conn, "SELECT id, user_id FROM products WHERE id=? AND is_active=1", 'i', $id);
        if(!$p || (int)$p['user_id'] === $current_user) sp_json(['status'=>'error']);
        sp_q($conn, "INSERT IGNORE INTO product_reports (product_id,reporter_id,reason) VALUES (?,?,?)", 'iis', $id, $current_user, $reason);
        sp_json(['status'=>'ok']);
    }

    /* ── customer reviews (product_id 0 = the whole shop) ── */
    if($action === 'save_review'){
        $sid = (int)($_POST['shop_id'] ?? 0); $pid = (int)($_POST['product_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        $body = mb_substr(trim($_POST['body'] ?? ''), 0, 500);
        $shop = sp_one($conn, "SELECT id, user_id, is_active FROM shops WHERE id=?", 'i', $sid);
        if(!$shop || !(int)$shop['is_active']) sp_json(['status'=>'error','msg'=>'Shop not found']);
        if((int)$shop['user_id'] === $current_user) sp_json(['status'=>'error','msg'=>'You can’t review your own shop']);
        if($rating < 1 || $rating > 5) sp_json(['status'=>'error','msg'=>'Tap a star rating first']);
        if($pid){
            $ok = sp_one($conn, "SELECT id FROM products WHERE id=? AND shop_id=? AND is_active=1", 'ii', $pid, $sid);
            if(!$ok) sp_json(['status'=>'error','msg'=>'Product not found']);
        }
        sp_q($conn, "INSERT INTO shop_reviews (shop_id,product_id,user_id,rating,body) VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE rating=VALUES(rating), body=VALUES(body), updated_at=NOW()", 'iiiis', $sid, $pid, $current_user, $rating, $body);
        $r = sp_one($conn, "SELECT id FROM shop_reviews WHERE shop_id=? AND product_id=? AND user_id=?", 'iii', $sid, $pid, $current_user);
        sp_json(['status'=>'ok','id'=>(int)($r['id'] ?? 0)]);
    }
    if($action === 'delete_review'){
        $st = sp_q($conn, "DELETE FROM shop_reviews WHERE shop_id=? AND product_id=? AND user_id=?", 'iii', (int)($_POST['shop_id'] ?? 0), (int)($_POST['product_id'] ?? 0), $current_user);
        sp_json(['status' => ($st && $st->affected_rows > 0) ? 'ok' : 'error']);
    }

    sp_json(['status'=>'error','msg'=>'Unknown action']);
}

/* ══════════════════════════════════════════════════════════════
   PRIVACY — private / fully private profiles hide the shop from non-trackers
══════════════════════════════════════════════════════════════ */
$shop_locked = false;
if(!$is_own_profile){
    $is_priv = false;
    try {
        $r = sp_one($conn, "SELECT is_fully_private FROM user_settings WHERE user_id=? LIMIT 1", 'i', $profile_id);
        if($r && !empty($r['is_fully_private'])) $is_priv = true;
        $r = sp_one($conn, "SELECT privacy_level FROM user_privacy WHERE user_id=? LIMIT 1", 'i', $profile_id);
        if($r && in_array($r['privacy_level'], ['private','full'], true)) $is_priv = true;
    } catch (Throwable $e) {}
    if($is_priv){
        $t = sp_one($conn, "SELECT 1 AS x FROM followers WHERE follower_id=? AND following_id=? LIMIT 1", 'ii', $current_user, $profile_id);
        $shop_locked = !$t;
    }
}

/* ══════════════════════════════════════════════════════════════
   LOAD SHOPS → SELECTED SHOP → PRODUCTS, COLLECTIONS, REVIEWS
══════════════════════════════════════════════════════════════ */
$all_shops = []; $shop = null; $shop_none = false;
if(!$shop_locked){
    $all_shops = array_map('sp_shop_norm', sp_ensure_shops($conn, $profile_id, $is_own_profile));
    if(!$is_own_profile) $all_shops = array_values(array_filter($all_shops, fn($s) => $s['is_active'] === 1));
    if($all_shops){
        $sel = (int)($_GET['shop'] ?? 0);
        $shop = $all_shops[0];
        foreach($all_shops as $s){ if($s['id'] === $sel){ $shop = $s; break; } }
    } else $shop_none = true;
}

$look = $shop ? $shop['look'] : sp_look_norm(null);
$accent_key = $shop['accent'] ?? 'red';
$corners    = $shop['corners'] ?? 'soft';
$layout     = $shop['layout'] ?? 'grid';
$shop_cur   = $shop['currency'] ?? 'USD';

$products = []; $my_cats = []; $collections = []; $reviews = [];
$shop_rating = 0.0; $shop_rcount = 0;

if($shop){
    $sid = $shop['id'];
    /* flash sales that have ended go back to the regular price */
    try {
        $nowTs = time();
        sp_q($conn, "UPDATE products SET price=old_price, old_price=NULL, discount_pct=0, sale_ends=NULL WHERE shop_id=? AND sale_ends IS NOT NULL AND sale_ends<=? AND old_price IS NOT NULL", 'ii', $sid, $nowTs);
        sp_q($conn, "UPDATE products SET sale_ends=NULL WHERE shop_id=? AND sale_ends IS NOT NULL AND sale_ends<=?", 'ii', $sid, $nowTs);
    } catch (Throwable $e) {}
    $products = sp_all($conn, "
        SELECT p.*, c.name AS cat_name,
               (SELECT COUNT(*) FROM product_saves s  WHERE s.product_id = p.id) AS saves,
               (SELECT COUNT(*) FROM product_saves s2 WHERE s2.product_id = p.id AND s2.user_id = ?) AS saved_me
        FROM products p
        LEFT JOIN shop_categories c ON c.id = p.category_id
        WHERE p.shop_id = ? AND p.user_id = ? AND p.is_active = 1
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT " . SP_MAX_PRODUCTS, 'iii', $current_user, $sid, $profile_id);

    $media = [];
    if($products){
        $ids = implode(',', array_map(fn($p) => (int)$p['id'], $products));
        foreach(sp_all($conn, "SELECT id, product_id, image, media_type FROM product_images WHERE product_id IN ($ids) ORDER BY sort_order, id") as $im){
            $media[(int)$im['product_id']][] = ['id' => (int)$im['id'], 'url' => SP_UPLOAD_URL . basename($im['image']), 'type' => $im['media_type'] === 'video' ? 'video' : 'image'];
        }
    }
    foreach($products as $k => $p){
        $products[$k]['imgs'] = $media[(int)$p['id']] ?? [];
        $products[$k]['pop']  = (int)$p['saves'] * 3 + (int)$p['clicks'];
        $products[$k]['ord']  = $k;
        $ts = !empty($p['created_at']) ? strtotime($p['created_at']) : 0;
        $products[$k]['ts']   = $ts;
        $products[$k]['ago']  = sp_ago($ts);
        $products[$k]['currency'] = sp_cur($p['currency']);
    }
    $by_id = [];
    foreach($products as $p) $by_id[(int)$p['id']] = $p;

    if($is_own_profile) $my_cats = sp_all($conn, "SELECT id, name FROM shop_categories WHERE user_id=? ORDER BY name", 'i', $current_user);

    /* collections */
    $items = [];
    foreach(sp_all($conn, "SELECT ci.collection_id, ci.product_id FROM shop_collection_items ci
                           JOIN shop_collections c ON c.id = ci.collection_id
                           WHERE c.shop_id = ? ORDER BY ci.sort_order, ci.product_id", 'i', $sid) as $r){
        if(isset($by_id[(int)$r['product_id']])) $items[(int)$r['collection_id']][] = (int)$r['product_id'];
    }
    foreach(sp_all($conn, "SELECT * FROM shop_collections WHERE shop_id=? ORDER BY id DESC", 'i', $sid) as $c){
        $pids = $items[(int)$c['id']] ?? [];
        if(!$pids && !$is_own_profile) continue;
        $cover = ''; $ctype = 'image';
        if(!empty($c['cover'])) $cover = SP_UPLOAD_URL . basename($c['cover']);
        elseif($pids && !empty($by_id[$pids[0]]['imgs'][0])){ $cover = $by_id[$pids[0]]['imgs'][0]['url']; $ctype = $by_id[$pids[0]]['imgs'][0]['type']; }
        $collections[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'desc' => $c['description'], 'cover' => $cover, 'ctype' => $ctype,
                          'custom_cover' => !empty($c['cover']), 'pids' => $pids];
    }

    /* reviews */
    $sum = 0;
    foreach(sp_all($conn, "SELECT r.id, r.product_id, r.user_id, r.rating, r.body, UNIX_TIMESTAMP(r.created_at) AS ts, u.username, u.profile_pic
                           FROM shop_reviews r JOIN users u ON u.id = r.user_id
                           WHERE r.shop_id = ? ORDER BY r.created_at DESC LIMIT 400", 'i', $sid) as $r){
        $reviews[] = ['id' => (int)$r['id'], 'pid' => (int)$r['product_id'], 'uid' => (int)$r['user_id'], 'name' => $r['username'],
                      'pic' => $r['profile_pic'] ?: 'uploads/me.jpeg', 'rating' => (int)$r['rating'], 'body' => $r['body'],
                      'ago' => sp_ago($r['ts']), 'mine' => ((int)$r['user_id'] === $current_user)];
        if((int)$r['product_id'] === 0){ $sum += (int)$r['rating']; $shop_rcount++; }
    }
    $shop_rating = $shop_rcount ? round($sum / $shop_rcount, 1) : 0.0;
}

$me = sp_one($conn, "SELECT username, profile_pic FROM users WHERE id=? LIMIT 1", 'i', $current_user) ?: ['username' => 'You', 'profile_pic' => ''];

/* categories actually used (chip) */
$used_cats = [];
foreach($products as $p){
    if(!empty($p['category_id'])){
        $cid = (int)$p['category_id'];
        if(!isset($used_cats[$cid])) $used_cats[$cid] = ['id'=>$cid, 'name'=>$p['cat_name'], 'n'=>0];
        $used_cats[$cid]['n']++;
    }
}
$has_offers  = (bool)array_filter($products, fn($p) => $p['old_price'] !== null && (float)$p['old_price'] > (float)$p['price']);
$has_popular = (bool)array_filter($products, fn($p) => $p['pop'] > 0);
$total_saves = array_sum(array_map(fn($p) => (int)$p['saves'], $products));

/* popularity ranking → "Customer favourites" */
$ranked = $products;
usort($ranked, fn($a, $b) => ($b['pop'] <=> $a['pop']) ?: ($a['ord'] <=> $b['ord']));
$fav = [];
if($shop && $shop['show_favs'] && count($products) > 2){
    foreach($ranked as $i => $p){ if($p['pop'] > 0 && count($fav) < 6) $fav[] = ['p' => $p, 'rank' => $i + 1]; }
}
$featured = array_values(array_filter($products, fn($p) => (int)$p['is_featured'] === 1));

$can_add   = $shop && $is_own_profile && sp_can_sell($conn, $current_user) && count($products) < SP_MAX_PRODUCTS;
$who_name  = htmlspecialchars($user['username']);
$shop_title_raw = ($shop && $shop['shop_name'] !== '') ? $shop['shop_name'] : ($shop && count($all_shops) > 1 && $shop['id'] !== $all_shops[0]['id'] ? 'Shop ' . (array_search($shop['id'], array_column($all_shops, 'id')) + 1) : $user['username']);
$shop_title = htmlspecialchars($shop_title_raw);
$profile_pic = $user['profile_pic'] ?: 'uploads/me.jpeg';
$av_src    = ($shop && !empty($shop['avatar'])) ? SP_UPLOAD_URL . basename($shop['avatar']) : $profile_pic;
$banner_url = ($shop && $shop['banner']) ? SP_UPLOAD_URL . basename($shop['banner']) : '';
$ac = SP_ACCENTS[$accent_key];
$hours_status = $shop ? sp_hours_status($shop) : null;
$X_SVG = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>';
$page_url = 'profile_shop.php?user_id=' . (int)$profile_id . ($shop ? '&shop=' . (int)$shop['id'] : '');

/* data for the JS side */
$js_products = [];
foreach($products as $p){
    $js_products[(int)$p['id']] = [
        'id' => (int)$p['id'], 'title' => $p['title'], 'price' => (float)$p['price'],
        'old_price' => $p['old_price'] !== null ? (float)$p['old_price'] : null, 'currency' => $p['currency'],
        'desc' => (string)$p['description'], 'cat_id' => $p['category_id'] ? (int)$p['category_id'] : 0,
        'cat_name' => $p['cat_name'] ?? '', 'cta_type' => $p['cta_type'], 'cta_url' => $p['cta_url'] ?? '',
        'in_stock' => (int)$p['in_stock'], 'featured' => (int)$p['is_featured'],
        'discount' => (int)($p['discount_pct'] ?? 0), 'off_sale' => (int)($p['off_sale'] ?? 0), 'sale_ends' => !empty($p['sale_ends']) ? (int)$p['sale_ends'] : 0,
        'images' => $p['imgs'], 'saved' => (bool)$p['saved_me'], 'saves' => (int)$p['saves'], 'ago' => $p['ago'],
    ];
}
$js_settings = ($is_own_profile && $shop) ? [
    'shop_name' => $shop['shop_name'], 'tagline' => $shop['tagline'], 'about' => $shop['about'], 'location' => $shop['location'],
    'look' => $shop['look'], 'policies' => $shop['policies_arr'], 'faq' => $shop['faq_arr'], 'announcement' => $shop['announcement'], 'accent' => $shop['accent'], 'layout' => $shop['layout'], 'corners' => $shop['corners'],
    'show_favs' => $shop['show_favs'], 'currency' => $shop['currency'], 'website' => $shop['website'],
    'whatsapp' => $shop['whatsapp'], 'instagram' => $shop['instagram'], 'banner_url' => $banner_url,
    'avatar_custom' => !empty($shop['avatar']), 'avatar_url' => !empty($shop['avatar']) ? $av_src : '',
    'hours_enabled' => $shop['hours_enabled'], 'hours' => $shop['hours_arr'], 'tz' => $shop['tz'], 'is_active' => $shop['is_active'],
] : null;
$js_data = [
    'own' => $is_own_profile, 'owner_id' => $profile_id, 'owner_name' => $shop_title_raw, 'owner_pic' => $av_src, 'profile_pic' => $profile_pic,
    'uid' => $current_user, 'can_msg' => $can_message, 'me' => ['name' => $me['username'], 'pic' => $me['profile_pic'] ?: 'uploads/me.jpeg'],
    'shop_id' => $shop ? $shop['id'] : 0, 'shop_count' => count($all_shops), 'max_shops' => SP_MAX_SHOPS,
    'currency' => $shop_cur, 'msg_url' => SP_MSG_URL, 'max_media' => SP_MAX_MEDIA,
    'about' => $shop ? $shop['about'] : '', 'products' => $js_products, 'collections' => $collections, 'reviews' => $reviews,
    'settings' => $js_settings, 'accents' => SP_ACCENTS, 'page_url' => $page_url,
];

/* card renderer */
function sp_card(array $p, ?int $rank, bool $own, int $i = 0): void {
    $id = (int)$p['id'];
    $offer = $p['old_price'] !== null && (float)$p['old_price'] > (float)$p['price'];
    $pct = $offer ? ((int)($p['discount_pct'] ?? 0) ?: (int)round((1 - (float)$p['price'] / (float)$p['old_price']) * 100)) : 0;
    $off = !empty($p['off_sale']);
    $out = !(int)$p['in_stock'] || $off;
    $isNew = !$offer && !$out && !empty($p['ts']) && (time() - (int)$p['ts']) < 7 * 86400;
    $cv = $p['imgs'][0] ?? null;
?>
    <div class="sp-card<?php echo $out ? ' out' : ''; ?>" data-id="<?php echo $id; ?>" data-cat="<?php echo (int)$p['category_id']; ?>"
         data-offer="<?php echo $offer ? 1 : 0; ?>" data-pop="<?php echo (int)$p['pop']; ?>" data-ord="<?php echo (int)$p['ord']; ?>"
         data-price="<?php echo (float)$p['price']; ?>" data-ts="<?php echo (int)$p['ts']; ?>" data-stock="<?php echo $out ? 0 : 1; ?>"
         data-name="<?php echo htmlspecialchars(mb_strtolower($p['title']), ENT_QUOTES); ?>"
         style="--i:<?php echo min($i, 12); ?>" onclick="spOpen(<?php echo $id; ?>)">
        <div class="sp-img">
            <?php if($cv && $cv['type'] === 'video'): ?>
                <video data-src="<?php echo htmlspecialchars($cv['url']); ?>#t=0.1" preload="none" muted playsinline></video><span class="sp-vid"><i class="ri-play-fill"></i></span>
            <?php elseif($cv): ?><img src="<?php echo htmlspecialchars($cv['url']); ?>" alt="" loading="lazy" decoding="async" onerror="this.style.display='none'">
            <?php else: ?><i class="ri-store-2-line sp-img-ph"></i><?php endif; ?>
            <?php if($off): ?><span class="sp-badge out">Out of sale</span>
            <?php elseif($out): ?><span class="sp-badge out">Sold out</span>
            <?php elseif($offer && $pct > 0): ?><span class="sp-badge">-<?php echo $pct; ?>%</span>
            <?php elseif($isNew): ?><span class="sp-badge new">New</span><?php endif; ?>
            <?php if($rank !== null): ?><span class="sp-pill">#<?php echo $rank; ?></span>
            <?php elseif($own): ?><span class="sp-pill"><i class="ri-bookmark-fill"></i> <?php echo (int)$p['saves']; ?></span><?php endif; ?>
        </div>
        <div class="sp-info">
            <div class="sp-name"><?php echo htmlspecialchars($p['title']); ?></div>
            <div class="sp-pr">
                <b><?php echo htmlspecialchars(sp_money($p['price'], $p['currency'])); ?></b>
                <?php if($offer): ?><s><?php echo htmlspecialchars(sp_money($p['old_price'], $p['currency'])); ?></s><?php endif; ?>
            </div>
            <?php if($offer && !$out && !empty($p['sale_ends']) && (int)$p['sale_ends'] > time()): ?>
            <div class="sp-flash" data-end="<?php echo (int)$p['sale_ends']; ?>"><i class="ri-flashlight-fill"></i><span class="t notranslate"></span></div>
            <?php endif; ?>
        </div>
        <?php if(!$own): ?>
        <button type="button" class="sp-bm<?php echo $p['saved_me'] ? ' on' : ''; ?>" data-id="<?php echo $id; ?>"
                onclick="event.stopPropagation(); spSave(<?php echo $id; ?>)" aria-label="Save">
            <i class="<?php echo $p['saved_me'] ? 'ri-bookmark-fill' : 'ri-bookmark-line'; ?>"></i>
        </button>
        <?php endif; ?>
    </div>
<?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="referrer" content="same-origin">
<title><?php echo $shop_title; ?> · Shop</title>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a{ color:inherit; text-decoration:none; }
button, input, textarea, select{ font-family:inherit; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --ac:#FF2D2D; --ac-h:#ff1a1a; --ac-l:#ff8a8a;
    --ac-dim:rgba(255,45,45,.16); --ac-bd:rgba(255,45,45,.4); --ac-glow:rgba(255,45,45,.34);
    --on-ac:#000;
    --bg:linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:rgba(255,255,255,0.05); --surface2:rgba(255,255,255,0.08);
    --line:rgba(255,255,255,0.09); --line2:rgba(255,255,255,0.14);
    --fg:255,255,255; --text:#fff; --muted:rgba(255,255,255,0.45); --sub:rgba(255,255,255,0.62);
    --max-w:580px; --topbar-h:54px; --pad:clamp(9px,3vw,16px);
    --act-h:clamp(25px,7.2vw,30px); --in-h:clamp(30px,8vw,34px);
    --r:5px; --card-r:16px; --fs:.8rem; --avs:clamp(66px,20vw,84px);
}
body.cr-square{ --card-r:4px; }
@media (max-width:490px){ :root{ --topbar-h:48px; --fs:.74rem; } }
@media (max-width:380px){ :root{ --topbar-h:44px; --fs:.7rem; } }

html{ scroll-behavior:smooth; }
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}
body{
    background:var(--bg); background-attachment:fixed; color:var(--text);
    min-height:100vh; display:flex; justify-content:center; overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}
body.sp-lock{ overflow:hidden; }

.sp-lane{ width:100%; max-width:var(--max-w); min-height:100vh; display:flex; flex-direction:column; padding-bottom:30px; }
@media (min-width:801px){ .sp-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sp-lane{ max-width:100%; } }

/* ── TOPBAR ── */
.sp-topbar{
    position:sticky; top:0; z-index:500; height:var(--topbar-h);
    display:flex; align-items:center; gap:2px; padding:0 10px;
    background:var(--bg); background-attachment:fixed; border-bottom:1px solid var(--line);
}
.sp-x{
    width:34px; height:34px; background:none; border:none; color:var(--ui-text,#fff);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s,color .2s; padding:0; flex-shrink:0; font-size:1.2rem;
}
.sp-x:hover{ opacity:1; color:var(--ac); }
.sp-x svg{ width:22px; height:22px; display:block; }
.sp-title{
    flex:1; min-width:0; text-align:right; margin:0 6px 0 10px;
    font-size:.92rem; font-weight:700; letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.sp-tr{ flex-shrink:0; height:28px; padding:0 13px; margin-left:2px; border:1px solid var(--ac); background:transparent; color:var(--ac); font:inherit; font-size:.7rem; font-weight:600; letter-spacing:.2px; line-height:1; border-radius:4px; cursor:pointer; white-space:nowrap; max-width:42vw; overflow:hidden; text-overflow:ellipsis; transition:background .2s,color .2s; }
.sp-tr:hover, .sp-tr.on{ background:var(--ac); color:#fff; }
.sp-tr.busy{ opacity:.6; pointer-events:none; }
/* hide Google's own banner / tooltip */
.skiptranslate iframe.skiptranslate, iframe.goog-te-banner-frame, .goog-te-banner-frame{ display:none !important; }
body{ top:0 !important; }
#goog-gt-tt, .goog-tooltip, .goog-tooltip:hover{ display:none !important; }
.goog-text-highlight{ background:none !important; box-shadow:none !important; }
/* menu modal */
.sp-favhow{ padding:12px 0 6px; }
.sp-favhow h3{ font-size:.8rem; font-weight:700; margin-bottom:7px; display:flex; align-items:center; gap:7px; }
.sp-favhow h3 i{ color:var(--ac); font-size:16px; }
.sp-favhow p{ font-size:.72rem; line-height:1.7; color:var(--sub); margin-bottom:8px; }
.sp-favhow ul{ list-style:none; margin:0 0 12px; padding:0; }
.sp-favhow li{ display:flex; gap:9px; align-items:flex-start; font-size:.7rem; line-height:1.6; color:var(--sub); margin-bottom:6px; }
.sp-favhow li i{ color:var(--ac); font-size:14px; flex-shrink:0; margin-top:2px; }
.sp-favhow b{ color:var(--ui-text,#fff); font-weight:700; }
.sp-aboutp{ padding:14px var(--pad) 22px; }
.sp-aboutp h3{ font-size:.8rem; font-weight:700; margin-bottom:8px; display:flex; align-items:center; gap:7px; }
.sp-aboutp h3 i{ color:var(--ac); font-size:16px; }
.sp-aboutp p{ font-size:.72rem; line-height:1.7; color:var(--sub); margin-bottom:9px; }
.sp-aboutp ul{ list-style:none; margin:0 0 4px; padding:0; }
.sp-aboutp li{ display:flex; gap:9px; align-items:flex-start; font-size:.7rem; line-height:1.6; color:var(--sub); margin-bottom:7px; }
.sp-aboutp li i{ color:var(--ac); font-size:14px; flex-shrink:0; margin-top:2px; }
.sp-menubtn{ margin-left:2px; }
.sp-menubtn i{ font-size:22px; }
@media (max-width:490px){ .sp-x{ width:30px; height:30px; } .sp-title{ font-size:.84rem; } }
@media (max-width:380px){ .sp-title{ font-size:.8rem; } }

/* ── BUTTONS ── */
.sp-btn{
    display:flex; align-items:center; justify-content:center; gap:6px;
    flex-shrink:0; min-width:0; height:var(--act-h); padding:0 14px;
    border:none; border-radius:var(--r);
    font-size:.79rem; font-weight:700; line-height:1; color:var(--on-ac); background:var(--ac);
    white-space:nowrap; transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.sp-btn:hover{ background:var(--ac-h); box-shadow:0 4px 16px var(--ac-glow); }
.sp-btn:active{ transform:scale(.98); }
.sp-btn:disabled{ opacity:.5; cursor:not-allowed; box-shadow:none; pointer-events:none; }
.sp-btn i{ font-size:14px; }
.sp-btn.ghost{ background:rgba(var(--fg),.1); color:var(--ui-text,#fff); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); box-shadow:none; }
.sp-btn.ghost:hover{ background:rgba(var(--fg),.2); }
.sp-btn.danger{ background:var(--ac-dim); color:var(--ac-l); box-shadow:none; }
.sp-btn.danger:hover{ background:var(--ac-bd); color:#fff; }
.sp-btn.sm{ padding:0 12px; font-size:.72rem; }
.sp-icon{
    width:var(--act-h); height:var(--act-h); flex-shrink:0; padding:0;
    display:flex; align-items:center; justify-content:center;
    border:none; border-radius:var(--r); background:rgba(var(--fg),.1); color:var(--ui-text,#fff); font-size:15px;
    transition:background .2s, color .2s, transform .15s;
}
.sp-icon:hover{ background:rgba(var(--fg),.2); }
.sp-icon:active{ transform:scale(.94); }
.sp-icon.on{ color:var(--ac); }
@media (max-width:490px){ .sp-btn{ font-size:.68rem; padding:0 10px; gap:4px; } .sp-btn i{ font-size:12px; } .sp-btn.sm{ font-size:.66rem; } }

/* ══════════ SHOP SWITCHER ══════════ */
.sp-shops{ display:flex; gap:8px; overflow-x:auto; padding:10px var(--pad); border-bottom:1px solid var(--line); scrollbar-width:none; -webkit-overflow-scrolling:touch; }
.sp-shops::-webkit-scrollbar{ display:none; }
.sp-shopchip{
    flex:0 0 auto; display:flex; align-items:center; gap:7px; height:calc(var(--act-h) + 4px); padding:0 12px 0 5px;
    border-radius:var(--r); background:rgba(var(--fg),.08); color:var(--ui-text,#fff); font-size:.7rem; font-weight:600; white-space:nowrap; border:1px solid transparent; transition:.2s;
}
.sp-shopchip:hover{ background:rgba(var(--fg),.16); }
.sp-shopchip.active{ background:var(--ac-dim); border-color:var(--ac-bd); }
.sp-shopchip img{ width:20px; height:20px; border-radius:50%; object-fit:cover; }
.sp-shopchip i{ font-size:13px; color:var(--muted); }
.sp-shopchip.add{ padding:0 12px; color:var(--ac-l); background:transparent; border:1px dashed var(--ac-bd); }
.sp-shopchip.add i{ color:var(--ac); }

/* ══════════ BANNER + SHOP IDENTITY ══════════ */
.sp-banner{
    position:relative; width:100%; height:clamp(104px,30vw,150px); overflow:hidden;
    background:
        radial-gradient(120% 140% at 0% 0%, var(--ac-glow), transparent 58%),
        radial-gradient(90% 120% at 100% 100%, var(--ac-dim), transparent 62%),
        linear-gradient(135deg,#0d1030,#171b46);
    background-size:cover; background-position:center;
}
.sp-banner.has-img::after{ content:''; position:absolute; inset:0; background:linear-gradient(to bottom, rgba(1,2,24,.05), rgba(7,9,36,.55)); }
.sp-banner:not(.has-img)::before{
    content:''; position:absolute; inset:0; opacity:.5;
    background-image:radial-gradient(rgba(255,255,255,.12) 1px, transparent 1px); background-size:18px 18px;
    -webkit-mask-image:linear-gradient(to bottom, #000, transparent); mask-image:linear-gradient(to bottom, #000, transparent);
}
.sp-banner-ic{ position:absolute; left:14px; bottom:-8px; font-size:clamp(70px,22vw,110px); color:var(--ac); opacity:.14; transform:rotate(8deg); }

/* profile picture overlaps the banner; the name sits on the page below it */
.sp-id{ display:flex; flex-direction:row-reverse; align-items:flex-start; gap:12px; padding:0 var(--pad); margin-top:calc(var(--avs) / -2); position:relative; z-index:2; }
.sp-av{
    width:var(--avs); height:var(--avs);
    border-radius:var(--avr,50%); object-fit:cover; flex-shrink:0; border:3px solid var(--ac);
    background:var(--ph,#0d1030); box-shadow:0 0 0 4px var(--solid,#070924);
}
.sp-av-link{ display:block; flex-shrink:0; line-height:0; border-radius:var(--avr,50%); }
a.sp-handle{ display:block; }
a.sp-handle:hover{ color:var(--ac-l); }
.sp-id-main{ min-width:0; flex:1; padding-top:calc(var(--avs) / 2 + 7px); }
.sp-shop-name{ font-size:clamp(.8rem,3vw,.92rem); font-weight:700; letter-spacing:-.1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sp-handle{ font-size:.64rem; color:var(--muted); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sp-tag{ padding:10px var(--pad) 0; font-size:.76rem; line-height:1.55; color:rgba(var(--fg),.86); word-break:break-word; }
.sp-facts{ display:flex; flex-wrap:wrap; gap:6px; padding:10px var(--pad) 0; }
.sp-fact{
    display:inline-flex; align-items:center; gap:5px; height:calc(var(--act-h) - 4px); padding:0 10px; max-width:100%;
    border-radius:var(--r); background:var(--surface2); color:var(--sub); font-size:.66rem; font-weight:600; white-space:nowrap; border:none;
}
.sp-fact span{ overflow:hidden; text-overflow:ellipsis; min-width:0; }
.sp-fact i{ font-size:12px; color:var(--ac); flex-shrink:0; }
a.sp-fact:hover, button.sp-fact:hover{ background:rgba(var(--fg),.14); color:var(--ui-text,#fff); }
.sp-fact.open i, .sp-fact.open{ color:#4dde8a; } .sp-fact.open i{ color:#4dde8a; }
.sp-acts{ display:flex; gap:8px; padding:14px var(--pad) 0; align-items:center; }
.sp-acts .sp-btn{ flex:1; }
.sp-acts .sp-btn.main{ flex:1.5; }

.sp-ann{
    display:flex; align-items:center; gap:9px; margin:14px var(--pad) 0; padding:8px 11px;
    border-radius:var(--r); background:var(--ac-dim); border:1px solid var(--ac-bd);
    font-size:.7rem; line-height:1.5; color:var(--ui-text,#fff); word-break:break-word;
}
.sp-ann i{ font-size:15px; color:var(--ac); flex-shrink:0; animation:spWiggle 3.2s ease-in-out infinite; transform-origin:70% 70%; }
@keyframes spWiggle{ 0%,86%,100%{ transform:rotate(0); } 90%{ transform:rotate(-14deg); } 94%{ transform:rotate(10deg); } 97%{ transform:rotate(-6deg); } }
.sp-off{ display:flex; align-items:center; gap:10px; margin:14px var(--pad) 0; padding:9px 11px; border-radius:var(--r); background:rgba(var(--fg),.07); border:1px dashed var(--line2); font-size:.68rem; line-height:1.5; color:var(--sub); }
.sp-off i{ font-size:16px; color:var(--muted); flex-shrink:0; }
.sp-off span{ flex:1; }

.sp-about{ padding:12px var(--pad) 0; font-size:.72rem; line-height:1.65; color:var(--sub); word-break:break-word; white-space:pre-wrap; }
/* "more" / "less" ALWAYS sits right beside the last word on the last line */
.sp-more{ display:inline; background:none; border:none; padding:0; margin:0; color:var(--ac-l); font-size:inherit; font-weight:700; line-height:inherit; }
.sp-more:hover{ color:var(--ui-text,#fff); }

/* ── HORIZONTAL SECTIONS (featured / collections / favourites) ── */
.sp-hs{ display:flex; gap:12px; overflow-x:auto; padding-bottom:4px; scrollbar-width:none; scroll-snap-type:x proximity; -webkit-overflow-scrolling:touch; }
.sp-hs::-webkit-scrollbar{ display:none; }
.sp-more{ gap:9px; }
.sp-mc{ flex:0 0 clamp(96px,28vw,118px); scroll-snap-align:start; cursor:pointer; min-width:0; }
.sp-mc-img{ position:relative; aspect-ratio:1/1; border-radius:min(var(--card-r),8px); overflow:hidden; background:var(--ph,#12153a); border:1px solid var(--line); display:flex; align-items:center; justify-content:center; color:var(--ac); font-size:1.4rem; }
.sp-mc-img img, .sp-mc-img video{ width:100%; height:100%; object-fit:cover; display:block; }
.sp-mc-tag{ position:absolute; top:5px; left:5px; background:rgba(0,0,0,.7); color:#fff; font-size:.54rem; font-weight:700; padding:2px 5px; border-radius:3px; }
.sp-mc-tag.d{ background:var(--ac); }
.sp-mc-n{ font-size:.68rem; font-weight:600; margin-top:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sp-mc-p{ font-size:.66rem; color:var(--sub); margin-top:1px; }
.sp-hs .sp-card{ flex:0 0 clamp(120px,36vw,148px); scroll-snap-align:start; }
/* featured: big hero cards, swipe sideways when there are several */
.sp-hs.feat{ scroll-snap-type:x mandatory; }
.sp-feat{
    position:relative; flex:0 0 88%; scroll-snap-align:start; border-radius:var(--card-r); overflow:hidden;
    aspect-ratio:16/10; cursor:pointer; border:1px solid var(--line2); background:var(--ph,#12153a);
}
.sp-hs.feat.one .sp-feat{ flex-basis:100%; }
.sp-feat-oos{ position:absolute; top:9px; right:9px; z-index:2; background:rgba(0,0,0,.68); color:#fff; font-size:.58rem; font-weight:700; padding:3px 7px; border-radius:3px; }
.sp-feat-oos.disc{ background:var(--ac); }
.sp-feat img, .sp-feat video{ width:100%; height:100%; object-fit:cover; display:block; transition:transform .5s ease; }
.sp-feat:hover img, .sp-feat:hover video{ transform:scale(1.04); }
.sp-feat::after{ content:''; position:absolute; inset:0; background:linear-gradient(to top, rgba(1,2,24,.92), rgba(1,2,24,.1) 62%); pointer-events:none; }
.sp-feat-tag{
    position:absolute; top:10px; left:10px; z-index:2; display:inline-flex; align-items:center; gap:4px;
    background:var(--ac); color:var(--on-ac); font-size:.6rem; font-weight:700; padding:3px 8px; border-radius:4px;
}
.sp-feat-info{ position:absolute; left:12px; right:12px; bottom:10px; z-index:2; }
.sp-feat-name{ font-size:.88rem; font-weight:700; line-height:1.35; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; text-shadow:0 1px 6px rgba(0,0,0,.6); }
.sp-feat-pr{ display:flex; align-items:baseline; gap:8px; margin-top:3px; }
.sp-feat-pr b{ font-size:.86rem; color:var(--ac-l); }
.sp-feat-pr s{ font-size:.68rem; color:var(--muted); }

/* ── COLLECTIONS: stacked look ── */
.sp-col{ flex:0 0 clamp(150px,44vw,188px); scroll-snap-align:start; cursor:pointer; min-width:0; animation:spRise .5s cubic-bezier(.22,1,.36,1) both; }
.sp-stack{ position:relative; width:100%; aspect-ratio:1/1; }
.sp-sl{ position:absolute; border-radius:min(var(--card-r), 6px); transition:transform .3s ease; }
.sp-s3{ left:14%; right:14%; top:0; bottom:16px; background:#272935; border:1px solid rgba(255,255,255,.07); }
.sp-s2{ left:7%; right:7%; top:7px; bottom:8px; background:#383a48; border:1px solid rgba(255,255,255,.1); }
.sp-s1{ border:1px solid var(--line2); background:linear-gradient(135deg,#171b46,#12153a); }
.sp-s1{ left:0; right:0; top:14px; bottom:0; overflow:hidden; box-shadow:0 10px 24px rgba(0,0,0,.45); }
.sp-s1 img, .sp-s1 video{ width:100%; height:100%; object-fit:cover; display:block; transition:transform .45s ease; }
.sp-col:hover .sp-s3{ transform:translateY(-4px); }
.sp-col:hover .sp-s2{ transform:translateY(-2px); }
.sp-col:hover .sp-s1 img{ transform:scale(1.05); }
.sp-s1 .ph{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:1.8rem; color:var(--ac); opacity:.4; }
.sp-col-name{ font-size:.74rem; font-weight:600; margin-top:9px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sp-col-n{ font-size:.62rem; color:var(--muted); margin-top:1px; }
.sp-s1::after{ content:''; position:absolute; left:0; right:0; bottom:0; height:55%; background:linear-gradient(to top,rgba(0,0,0,.72),transparent); pointer-events:none; z-index:1; }
.sp-col-btn{ position:absolute; z-index:2; left:8px; bottom:8px; width:auto; padding:6px 11px; border:0; background:#fff; color:#111; font:inherit; font-size:.58rem; font-weight:800; line-height:1.3; border-radius:3px; text-align:left; cursor:pointer; white-space:nowrap; transition:opacity .2s; }
.sp-col-btn:hover{ opacity:.85; }

/* ── CHIPS ── */
.sp-chipsw{
    position:sticky; top:calc(var(--topbar-h) - 1px); z-index:400; margin-top:16px;
    background:var(--bg); background-attachment:fixed;
    border-top:1px solid var(--line); border-bottom:1px solid var(--line);
}
.sp-chips{
    display:flex; gap:8px; overflow-x:auto; padding:10px var(--pad);
    scrollbar-width:none; -webkit-overflow-scrolling:touch;
}
/* scroll arrows — laptops/desktops only, never on phones */
.sp-scr{ position:relative; width:100%; min-width:0; }
.sp-arr{ display:none; }
@media (min-width:801px){
    .sp-arr{
        position:absolute; top:50%; transform:translateY(-50%); z-index:50; width:36px; height:36px; border-radius:50%;
        border:1px solid rgba(255,255,255,.25); background:rgba(1,2,24,.92); color:#fff; font-size:22px;
        align-items:center; justify-content:center; box-shadow:0 4px 16px rgba(0,0,0,.6); transition:background .2s, color .2s;
    }
    .sp-arr.show{ display:flex; }
    .sp-arr.l{ left:4px; }
    .sp-arr.r{ right:4px; }
    .sp-arr:hover{ background:var(--ac); color:#000; }
}
.sp-chips::-webkit-scrollbar{ display:none; }
.sp-chip{
    flex:0 0 auto; display:flex; align-items:center; gap:4px;
    background:rgba(var(--fg),0.1); color:var(--ui-text,#fff); border:1px solid transparent;
    border-radius:var(--r); height:var(--act-h); padding:0 13px; font-size:.72rem; font-weight:600; white-space:nowrap;
    transition:background .2s, border-color .2s;
}
.sp-chip:hover{ background:rgba(var(--fg),0.18); }
.sp-chip.active{ background:var(--ac-dim); border-color:var(--ac-bd); color:var(--ui-text,#fff); }
.sp-chip i{ font-size:14px; }
@media (max-width:490px){ .sp-chip{ font-size:.66rem; padding:0 11px; } }

/* ── SECTIONS ── */
.sp-sec{ padding:18px var(--pad) 0; }
body.sp-focus #spFavSec, body.sp-focus #spFeatSec, body.sp-focus #spColSec{ display:none !important; }
.sp-main{ scroll-margin-top:calc(var(--topbar-h) + 56px); }
.sp-sec-h{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:12px; }
.sp-sec-h h2{ font-size:.92rem; font-weight:700; display:flex; align-items:center; gap:7px; }
.sp-sec-h h2 i{ color:var(--ac); font-size:16px; }
.sp-sec-h h2 em{ font-style:normal; font-size:.66rem; font-weight:600; color:var(--muted); background:var(--surface2); border-radius:4px; padding:2px 7px; }
.sp-sec-h a{ font-size:.7rem; font-weight:600; color:var(--ac-l); }
.sp-sec-h a:hover{ color:var(--ui-text,#fff); }
@media (max-width:490px){ .sp-sec-h h2{ font-size:.84rem; } }

.sp-sr{ display:flex; gap:8px; margin-bottom:14px; }
.sp-search{ position:relative; flex:1; min-width:0; }
.sp-search i{ position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:14px; pointer-events:none; }
.sp-search input{
    width:100%; height:var(--in-h); padding:0 12px 0 32px; border-radius:var(--r);
    border:1px solid var(--line2); background:var(--surface); color:var(--ui-text,#fff); font-size:.74rem; outline:none; -webkit-appearance:none; appearance:none;
    transition:border-color .2s, box-shadow .2s;
}
.sp-search input::placeholder{ color:var(--muted); }
.sp-search input:focus{ border-color:var(--ac); box-shadow:0 0 0 3px var(--ac-dim); }
.sp-filter{ position:relative; width:var(--in-h); height:var(--in-h); flex-shrink:0; border:none; border-radius:var(--r); background:rgba(var(--fg),.1); color:var(--ui-text,#fff); font-size:16px; display:flex; align-items:center; justify-content:center; transition:.2s; }
.sp-filter:hover{ background:rgba(var(--fg),.2); }
.sp-filter.on{ background:var(--ac-dim); color:var(--ac-l); }
.sp-filter.on::after{ content:''; position:absolute; top:5px; right:5px; width:7px; height:7px; border-radius:50%; background:var(--ac); }

/* ── PRODUCT GRID / LAYOUTS ── */
.sp-grid{ display:grid; gap:18px 12px; }
.sp-grid.lay-grid{ grid-template-columns:1fr 1fr; }
.sp-grid.lay-compact{ grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px 8px; }
.sp-grid.lay-list{ grid-template-columns:1fr; gap:10px; }
@media (min-width:801px){ .sp-grid.lay-grid{ gap:22px 14px; } }

.sp-card{ position:relative; min-width:0; cursor:pointer; animation:spRise .5s cubic-bezier(.22,1,.36,1) both; animation-delay:calc(var(--i,0) * 45ms); }
@keyframes spRise{ from{ opacity:0; transform:translateY(14px); } to{ opacity:1; transform:none; } }
.sp-img{
    position:relative; width:100%; aspect-ratio:4/5; border-radius:var(--card-r); overflow:hidden;
    background:linear-gradient(135deg,#12153a,#1a1e4a); border:1px solid var(--line);
    transition:transform .3s ease, border-color .3s ease, box-shadow .3s ease;
}
.sp-card:hover .sp-img{ transform:translateY(-3px); border-color:var(--ac-bd); box-shadow:0 10px 26px rgba(0,0,0,.45); }
.sp-img img, .sp-img video{ width:100%; height:100%; object-fit:cover; display:block; transition:transform .45s ease; }
.sp-card:hover .sp-img img{ transform:scale(1.05); }
.sp-card.out .sp-img img, .sp-card.out .sp-img video{ opacity:.45; }
.sp-img-ph{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:2rem; color:var(--ac); opacity:.4; }
.sp-vid{ position:absolute; right:8px; bottom:8px; width:22px; height:22px; border-radius:50%; background:rgba(1,2,24,.7); display:flex; align-items:center; justify-content:center; font-size:12px; color:#fff; }
.sp-badge{ position:absolute; top:8px; left:8px; background:var(--ac); color:var(--on-ac); font-size:.6rem; font-weight:700; padding:3px 7px; border-radius:5px; }
.sp-badge.new{ background:#fff; }
.sp-badge.out{ background:rgba(1,2,24,.85); color:#fff; border:1px solid var(--line2); }
.sp-pill{
    position:absolute; left:8px; bottom:8px; display:inline-flex; align-items:center; gap:3px;
    background:rgba(1,2,24,.72); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    color:#fff; font-size:.62rem; font-weight:700; padding:3px 7px; border-radius:5px;
}
.sp-pill i{ font-size:11px; color:var(--ac); }
.sp-bm{
    position:absolute; top:8px; right:8px; width:28px; height:28px; display:flex; align-items:center; justify-content:center;
    border:none; border-radius:50%; background:rgba(1,2,24,.62); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    color:#fff; font-size:14px; transition:transform .15s, color .2s, background .2s;
}
.sp-bm:hover{ background:rgba(1,2,24,.85); }
.sp-bm:active{ transform:scale(.85); }
.sp-bm.on{ color:var(--ac); }
.sp-info{ padding:8px 2px 0; min-width:0; }
.sp-name{ font-size:.76rem; line-height:1.4; color:var(--ui-text,#fff); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.sp-pr{ display:flex; align-items:baseline; gap:6px; margin-top:3px; flex-wrap:wrap; }
.sp-pr b{ font-size:.78rem; font-weight:700; color:var(--ac-l); }
.sp-pr s{ font-size:.64rem; color:var(--muted); }
@media (max-width:490px){ .sp-name{ font-size:.7rem; } .sp-pr b{ font-size:.72rem; } }

.lay-compact .sp-img{ aspect-ratio:1/1; }
.lay-compact .sp-name{ font-size:.66rem; -webkit-line-clamp:1; }
.lay-compact .sp-pr b{ font-size:.68rem; }
.lay-compact .sp-pr s, .lay-compact .sp-pill{ display:none; }
.lay-compact .sp-bm{ width:24px; height:24px; font-size:12px; top:6px; right:6px; }

.lay-list .sp-card{ display:flex; align-items:center; gap:12px; padding:10px; border:1px solid var(--line); border-radius:var(--card-r); background:var(--surface); transition:background .2s, border-color .2s; }
.lay-list .sp-card:hover{ background:var(--surface2); border-color:var(--ac-bd); }
.lay-list .sp-img{ width:clamp(78px,24vw,104px); flex-shrink:0; aspect-ratio:1/1; border-radius:calc(var(--card-r) * .7); }
.lay-list .sp-card:hover .sp-img{ transform:none; box-shadow:none; }
.lay-list .sp-info{ flex:1; padding:0 34px 0 0; }
.lay-list .sp-name{ font-size:.8rem; }
.lay-list .sp-pr b{ font-size:.82rem; }
.lay-list .sp-pill{ display:none; }
.lay-list .sp-bm{ top:50%; transform:translateY(-50%); right:10px; background:none; backdrop-filter:none; -webkit-backdrop-filter:none; }
.lay-list .sp-bm:active{ transform:translateY(-50%) scale(.85); }

/* ── warm closing note ── */
.sp-learn{ color:var(--ac); font-size:inherit; font-weight:600; }
.sp-learn:hover{ text-decoration:underline; }
.sp-rev-av{ display:block; flex-shrink:0; line-height:0; border-radius:50%; }
.sp-rev-top b a:hover{ color:var(--ac-l); }
.sp-note{
    display:flex; flex-direction:column; gap:11px; align-items:flex-start; margin:26px var(--pad) 8px; padding:14px 14px;
    border-radius:10px; background:var(--surface); border:1px solid var(--line);
    font-size:.67rem; line-height:1.75; color:var(--sub);
}
.sp-note > i{ font-size:19px; color:var(--ac); flex-shrink:0; margin-top:1px; }
.sp-note b{ display:block; color:var(--ui-text,#fff); font-size:.76rem; margin-bottom:3px; }

/* ══════════ REVIEWS ══════════ */
.sp-stars{ display:inline-flex; gap:1px; color:#FFC21A; font-size:13px; }
.sp-stars i{ font-size:inherit; }
.sp-rsum{ display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; background:var(--surface); border:1px solid var(--line); margin-bottom:6px; }
.sp-rbig{ font-size:1.5rem; font-weight:700; line-height:1; }
.sp-rsum .m{ flex:1; min-width:0; }
.sp-rsum .m small{ display:block; font-size:.64rem; color:var(--muted); margin-top:3px; }
.sp-rev{ padding:13px 0; border-bottom:1px solid var(--line); }
.sp-rev:last-child{ border-bottom:none; }
.sp-hs.revs{ gap:10px; margin-top:6px; }
.sp-rcard{ flex:0 0 clamp(230px,72vw,270px); scroll-snap-align:start; padding:12px; border:1px solid var(--line); border-radius:10px; background:var(--surface); align-self:flex-start; }
.sp-rcard:last-child{ border-bottom:1px solid var(--line); }
.sp-rev-top{ display:flex; align-items:center; gap:9px; }
.sp-rev-top img{ width:28px; height:28px; border-radius:50%; object-fit:cover; flex-shrink:0; border:1.5px solid var(--ac); }
.sp-rev-top .n{ flex:1; min-width:0; }
.sp-rev-top b{ display:block; font-size:.72rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sp-rev-top small{ font-size:.6rem; color:var(--muted); }
.sp-rev-body{ margin-top:7px; font-size:.72rem; line-height:1.65; color:var(--sub); white-space:pre-wrap; word-break:break-word; }
.sp-rev-act{ display:flex; gap:14px; margin-top:6px; }
.sp-rev-act button{ background:none; border:none; font-size:.64rem; font-weight:600; color:var(--muted); }
.sp-rev-act button:hover{ color:var(--ac-l); }
.sp-rempty{ font-size:.72rem; color:var(--muted); text-align:center; padding:16px 0 6px; line-height:1.6; }
.sp-seeall{ display:block; margin:8px auto 0; background:none; border:none; color:var(--ac-l); font-size:.7rem; font-weight:600; }
.sp-seeall:hover{ color:var(--ui-text,#fff); }
.sp-starpick{ display:flex; gap:8px; justify-content:center; padding:10px 0 6px; }
.sp-starpick button{ background:none; border:none; font-size:30px; color:rgba(var(--fg),.22); padding:0; transition:transform .15s, color .15s; }
.sp-starpick button.on{ color:#FFC21A; }
.sp-starpick button:active{ transform:scale(.88); }

/* ══════════ EMPTY STATES ══════════ */
.empty-state{
    flex:1; min-height:46vh;
    padding:clamp(28px,8vw,48px) clamp(20px,7vw,36px) clamp(36px,10vw,60px);
    display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center;
}
.es-title{ font-size:clamp(.95rem,3.4vw,1.1rem); font-weight:700; color:var(--ui-text,#fff); letter-spacing:-.2px; }
.es-text{ margin-top:6px; max-width:340px; font-size:clamp(.66rem,2.1vw,.76rem); color:var(--muted); line-height:1.7; }
.es-tips{ width:100%; max-width:360px; margin-top:clamp(16px,5vw,24px); display:flex; flex-direction:column; gap:7px; }
.es-tip{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:4px; background:rgba(var(--fg),0.05); text-align:left; }
.es-tip i{ font-size:1rem; color:var(--ac); flex-shrink:0; }
.es-tip span{ font-size:clamp(.62rem,2vw,.7rem); color:var(--sub); line-height:1.5; }
.es-btn{
    margin-top:clamp(16px,5vw,22px); display:flex; align-items:center; justify-content:center; gap:6px;
    height:var(--act-h); min-width:150px; padding:0 22px; border:none; border-radius:4px;
    background:var(--ac); color:var(--on-ac); font-size:.72rem; font-weight:700;
    transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.es-btn:hover{ background:var(--ac-h); box-shadow:0 4px 16px var(--ac-glow); }
.es-btn:active{ transform:scale(0.98); }
.es-btn.ghost{ background:rgba(var(--fg),0.1); color:var(--ui-text,#fff); box-shadow:none; }
.es-btn.ghost:hover{ background:rgba(var(--fg),0.2); }
@media (max-width:490px){ .es-btn{ font-size:.62rem; } }
.no-results{ display:none; flex-direction:column; align-items:center; gap:8px; padding:36px 0 24px; text-align:center; color:var(--muted); font-size:.74rem; }
.no-results i{ font-size:1.8rem; opacity:.35; }
.no-results.show{ display:flex; }
.sp-tipbox{ display:flex; align-items:center; gap:10px; padding:11px 12px; border-radius:var(--r); background:var(--surface); border:1px dashed var(--line2); font-size:.68rem; line-height:1.5; color:var(--sub); }
.sp-tipbox i{ font-size:17px; color:var(--ac); flex-shrink:0; }
.sp-tipbox span{ flex:1; }

/* ══════════ FULL-SCREEN LANE MODALS ══════════ */
.sp-ov{
    display:none; position:fixed; inset:0; z-index:9000;
    background:var(--bg); background-attachment:fixed; justify-content:center; overflow:hidden; color:var(--ui-text,#fff);
}
.sp-ov.show{ display:flex; animation:spSlideIn .28s cubic-bezier(.22,1,.36,1); }
.sp-ov.out{ animation:spSlideOut .2s ease forwards; }
.sp-ov.over{ z-index:9100; }
@keyframes spSlideIn{ from{ opacity:0; transform:translateY(20px); } to{ opacity:1; transform:none; } }
@keyframes spSlideOut{ to{ opacity:0; transform:translateY(16px); } }
.sp-ovlane{ width:100%; max-width:var(--max-w); height:100%; display:flex; flex-direction:column; min-height:0; }
@media (min-width:801px){ .sp-ovlane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sp-ovlane{ max-width:100%; } }
.sp-ovtop{ flex-shrink:0; display:flex; align-items:center; min-height:var(--topbar-h); padding:env(safe-area-inset-top) 10px 0; }
.sp-tb-side{ flex:0 0 38px; display:flex; align-items:center; }
.sp-tb-side.right{ justify-content:flex-end; }
.sp-ovtitle{ flex:1; min-width:0; text-align:center; font-size:.92rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
@media (max-width:490px){ .sp-ovtitle{ font-size:.84rem; } }
.sp-ovbody{ flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch; padding:4px var(--pad) 18px; scrollbar-width:thin; scrollbar-color:var(--ac-bd) transparent; }
.sp-ovbody::-webkit-scrollbar{ width:4px; }
.sp-ovbody::-webkit-scrollbar-thumb{ background:var(--ac-bd); border-radius:99px; }
.sp-actionbar{ flex-shrink:0; display:flex; gap:8px; align-items:center; padding:8px var(--pad) calc(10px + env(safe-area-inset-bottom)); }
.sp-actionbar:empty{ display:none; }
.sp-actionbar .sp-btn{ flex:1; }
.sp-actionbar .sp-btn.main{ flex:1.6; }

.sp-msec{ margin-top:16px; padding-top:14px; border-top:1px solid var(--line); }
.sp-msec:first-child{ margin-top:6px; padding-top:0; border-top:none; }
.sp-msec-h{ display:flex; align-items:center; gap:6px; margin-bottom:9px; font-size:.64rem; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--sub); }
.sp-msec-h i{ font-size:14px; color:var(--ac); }
.sp-opt{ margin-left:auto; font-size:.58rem; font-weight:600; letter-spacing:.4px; text-transform:uppercase; color:var(--muted); background:rgba(var(--fg),.07); padding:2px 8px; border-radius:4px; }
.sp-hint{ font-size:.66rem; color:var(--muted); margin-top:6px; line-height:1.5; }

.sp-f{ display:block; margin-bottom:10px; }
.sp-f > label{ display:block; font-size:.66rem; color:var(--muted); margin-bottom:4px; font-weight:500; }
.sp-in{
    width:100%; height:var(--in-h); background:var(--surface); border:1px solid var(--line2); border-radius:var(--r);
    padding:0 11px; font-size:.76rem; color:var(--ui-text,#fff); outline:none; transition:border-color .2s, box-shadow .2s; -webkit-appearance:none; appearance:none;
}
.sp-in::placeholder{ color:var(--muted); }
.sp-in:focus{ border-color:var(--ac); box-shadow:0 0 0 3px var(--ac-dim); }
input[type="time"].sp-in{ color-scheme:dark; }
select.sp-in, .sp-cvsel{ -webkit-appearance:none; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath stroke='%23000' stroke-width='.9' stroke-linejoin='round' d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 11px center; background-size:12px 12px; padding-right:30px; text-overflow:ellipsis; }
select.sp-in option, select.sp-in optgroup{ background:var(--sheet-solid,#0a0c22); color:var(--ui-text,#fff); }
textarea.sp-in{ height:auto; min-height:76px; padding:9px 11px; line-height:1.6; resize:none; font-size:.78rem; }
.sp-row2{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.sp-count{ text-align:right; font-size:.62rem; color:var(--muted); margin-top:4px; }
.sp-err{ color:#ff7a7a; font-size:.7rem; min-height:16px; margin:6px 0 2px; }
@media (max-width:490px){ .sp-in{ font-size:.7rem; } textarea.sp-in{ font-size:.74rem; } }

.sp-seg{ display:flex; padding:3px; gap:3px; border-radius:var(--r); background:var(--surface); border:1px solid var(--line); }
.sp-seg button{
    flex:1; min-width:0; height:calc(var(--act-h) - 2px); padding:0 6px; border:none; border-radius:4px; background:none; color:var(--sub);
    font-size:.72rem; font-weight:600; display:flex; align-items:center; justify-content:center; gap:5px; white-space:nowrap; transition:.2s;
}
.sp-seg button i{ font-size:13px; }
.sp-seg button.active{ background:var(--ac); color:var(--on-ac); }
@media (max-width:490px){ .sp-seg button{ font-size:.64rem; } }

.sp-row{ display:flex; align-items:center; gap:12px; padding:6px 0; }
.sp-row > i{ font-size:19px; width:22px; text-align:center; color:var(--ac); flex-shrink:0; }
.sp-row .lbl{ flex:1; min-width:0; font-size:.76rem; }
.sp-row .lbl small{ display:block; font-size:.62rem; color:var(--muted); margin-top:1px; }
.sp-switch{ position:relative; display:inline-block; width:40px; height:23px; flex-shrink:0; }
.sp-switch input{ opacity:0; width:0; height:0; }
.sp-slider{ position:absolute; cursor:pointer; inset:0; background:rgba(var(--fg),.18); border-radius:34px; transition:.25s; }
.sp-slider::before{ content:""; position:absolute; height:17px; width:17px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.25s; }
.sp-switch input:checked + .sp-slider{ background:var(--ac); }
.sp-switch input:checked + .sp-slider::before{ transform:translateX(17px); background:#000; }
@media (max-width:490px){ .sp-switch{ width:36px; height:21px; } .sp-slider::before{ height:15px; width:15px; } .sp-switch input:checked + .sp-slider::before{ transform:translateX(15px); } }

/* media thumbs (product form) */
.sp-thumbs{ display:flex; gap:8px; flex-wrap:wrap; }
.sp-th{ position:relative; width:58px; height:58px; border-radius:8px; overflow:hidden; background:#222; border:2px solid var(--line2); }
.sp-th.cover{ border-color:var(--ac); }
.sp-th img, .sp-th video{ width:100%; height:100%; object-fit:cover; display:block; }
.sp-th .vi{ position:absolute; left:3px; bottom:3px; font-size:11px; color:#fff; text-shadow:0 1px 3px #000; }
.sp-th button{ position:absolute; top:3px; right:3px; width:17px; height:17px; border-radius:4px; background:rgba(0,0,0,.8); color:#fff; border:none; font-size:10px; display:flex; align-items:center; justify-content:center; padding:0; }
.sp-th button:hover{ background:var(--ac); color:#000; }
.sp-th .cv{ position:absolute; bottom:0; left:0; right:0; background:var(--ac); color:#000; font-size:.5rem; font-weight:800; text-align:center; padding:1px 0; }
.sp-add-img{ width:58px; height:58px; border-radius:8px; border:2px dashed var(--line2); background:var(--surface); color:var(--sub); font-size:1.3rem; display:flex; align-items:center; justify-content:center; padding:0; transition:.2s; }
.sp-add-img:hover{ border-color:var(--ac); color:var(--ac); }

/* design modal bits */
.sp-bprev{
    position:relative; width:100%; height:clamp(80px,24vw,110px); border-radius:10px; overflow:hidden; border:1px solid var(--line2);
    background:radial-gradient(120% 140% at 0% 0%, var(--ac-glow), transparent 58%), linear-gradient(135deg,#0d1030,#171b46); background-size:cover; background-position:center;
    display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:.68rem;
}
.sp-bbtns{ display:flex; gap:8px; margin-top:8px; }
.sp-bbtns .sp-btn{ flex:1; }
.sp-avrow{ display:flex; align-items:center; gap:12px; }
.sp-avrow img{ width:56px; height:56px; border-radius:50%; object-fit:cover; border:2px solid var(--ac); flex-shrink:0; }
.sp-avrow .sp-bbtns{ margin:0; flex:1; flex-wrap:wrap; }
.sp-sws{ display:flex; flex-wrap:wrap; gap:9px; }
.sp-sw{
    width:clamp(30px,8.4vw,36px); height:clamp(30px,8.4vw,36px); border-radius:50%; border:2px solid transparent; background:var(--c);
    display:flex; align-items:center; justify-content:center; color:#000; font-size:16px; padding:0; transition:transform .15s, box-shadow .2s;
    box-shadow:0 0 0 2px var(--solid,#070924);
}
.sp-sw i{ opacity:0; transition:opacity .15s; }
.sp-sw.active{ box-shadow:0 0 0 2px var(--solid,#070924), 0 0 0 4px var(--c); }
.sp-sw.active i{ opacity:1; }
.sp-sw:active{ transform:scale(.9); }
.sp-lnk{ display:flex; align-items:center; gap:9px; margin-bottom:8px; }
.sp-lnk > i{ font-size:17px; color:var(--ac); width:22px; text-align:center; flex-shrink:0; }
.sp-lnk .sp-in{ flex:1; }

/* location search (same as group events) */
.sp-locwrap{ position:relative; }
.sp-locwrap .sp-in{ padding-right:36px; }
.sp-locspin{ position:absolute; right:11px; top:calc(var(--in-h) / 2); transform:translateY(-50%); width:15px; height:15px; border-radius:50%; border:2px solid rgba(255,255,255,.18); border-top-color:var(--ac); display:none; animation:spLoc .7s linear infinite; }
.sp-locspin.active{ display:block; }
@keyframes spLoc{ to{ transform:translateY(-50%) rotate(360deg); } }
.sp-locsug{ position:absolute; left:0; right:0; top:calc(100% + 6px); z-index:40; background:rgba(12,12,28,.98); border:1px solid var(--line); border-radius:var(--r); max-height:230px; overflow-y:auto; display:none; box-shadow:0 14px 30px rgba(0,0,0,.5); }
.sp-locsug.active{ display:block; }
.sp-locitem{ display:flex; align-items:flex-start; gap:9px; padding:10px 12px; border-bottom:1px solid var(--line); font-size:.72rem; color:rgba(var(--fg),.85); line-height:1.4; cursor:pointer; }
.sp-locitem:last-child{ border-bottom:none; }
.sp-locitem:hover{ background:rgba(var(--fg),.06); }
.sp-locitem i{ color:var(--ac); font-size:.9rem; margin-top:2px; flex-shrink:0; }
.sp-locempty{ padding:12px; font-size:.72rem; color:var(--muted); text-align:center; }
.sp-locpicked{ display:none; align-items:center; gap:8px; margin-top:8px; padding:9px 11px; border-radius:var(--r); background:var(--ac-dim); font-size:.7rem; color:var(--ui-text,#fff); }
.sp-locpicked.active{ display:flex; }
.sp-locpicked > i{ color:var(--ac-l); font-size:.95rem; flex-shrink:0; }
.sp-locpicked span{ flex:1; line-height:1.4; word-break:break-word; }
.sp-locpicked button{ background:none; border:none; color:rgba(var(--fg),.55); font-size:.95rem; flex-shrink:0; }
.sp-locpicked button:hover{ color:#ff7a7a; }

/* business hours */
.sp-hrow{ display:flex; align-items:center; gap:8px; padding:6px 0; }
.sp-hrow .d{ width:34px; font-size:.7rem; font-weight:600; color:var(--sub); flex-shrink:0; }
.sp-hrow .t{ display:flex; align-items:center; gap:6px; flex:1; min-width:0; }
.sp-hrow .t .sp-in{ flex:1; min-width:0; padding:0 6px; }
.sp-hrow .t em{ font-style:normal; color:var(--muted); font-size:.7rem; }
.sp-hrow .cl{ flex:1; font-size:.7rem; color:var(--muted); }
.sp-hday{ display:flex; align-items:center; gap:10px; padding:13px var(--pad); border-bottom:1px solid var(--line); font-size:.78rem; }
.sp-hday.today{ background:var(--ac-dim); }
.sp-hday b{ flex:1; font-weight:600; }
.sp-hday span{ color:var(--sub); font-size:.74rem; }
.sp-hday.off span{ color:var(--muted); }

/* product detail */
.sp-dcar{ position:relative; margin:6px 0 0; border-radius:var(--card-r); overflow:hidden; background:var(--ph,#12153a); border:1px solid var(--line); }
.sp-car{ display:flex; overflow-x:auto; scroll-snap-type:x mandatory; scrollbar-width:none; -webkit-overflow-scrolling:touch; }
.sp-car::-webkit-scrollbar{ display:none; }
.sp-car img, .sp-car video{ flex:0 0 100%; width:100%; aspect-ratio:1/1; max-height:62vh; object-fit:cover; scroll-snap-align:center; display:block; background:#000; }
.sp-ctr{ position:absolute; top:10px; right:10px; background:rgba(1,2,24,.72); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); color:#fff; font-size:.62rem; font-weight:700; padding:3px 8px; border-radius:5px; }
.sp-dots{ display:flex; justify-content:center; flex-wrap:wrap; gap:5px; padding:10px 0 2px; }
.sp-dots span{ width:6px; height:6px; border-radius:50%; background:rgba(var(--fg),.25); transition:background .2s, width .2s; }
.sp-dots span.on{ background:var(--ac); width:16px; border-radius:4px; }
.sp-dcat{ font-size:.66rem; color:var(--ac-l); font-weight:600; margin:12px 0 4px; }
.sp-dtitle{ font-size:1rem; font-weight:700; line-height:1.35; word-break:break-word; }
.sp-dprices{ display:flex; align-items:center; gap:9px; margin:7px 0 4px; flex-wrap:wrap; }
.sp-dprice{ font-size:1.05rem; font-weight:700; color:var(--ac-l); }
.sp-dold{ font-size:.8rem; color:var(--muted); text-decoration:line-through; }
.sp-doff{ background:var(--ac); color:#000; font-size:.62rem; font-weight:700; padding:2px 7px; border-radius:4px; }
.sp-dout{ background:rgba(var(--fg),.1); color:var(--ui-text,#fff); font-size:.62rem; font-weight:700; padding:2px 7px; border-radius:4px; }
.sp-dago{ display:flex; align-items:center; gap:5px; font-size:.66rem; color:var(--muted); margin-bottom:12px; }
.sp-dago i{ font-size:12px; }
.sp-ddesc{ font-size:.76rem; line-height:1.7; color:var(--sub); white-space:pre-wrap; word-break:break-word; }
.sp-seller{ display:flex; align-items:center; gap:10px; margin-top:16px; padding:9px 10px; border-radius:8px; background:var(--surface); border:1px solid var(--line); }
.sp-seller img{ width:clamp(34px,9vw,40px); height:clamp(34px,9vw,40px); border-radius:50%; object-fit:cover; border:2px solid var(--ac); flex-shrink:0; }
.sp-seller div{ min-width:0; flex:1; }
.sp-seller b{ display:block; font-size:.76rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sp-seller small{ font-size:.62rem; color:var(--muted); }
.sp-safe{ display:flex; gap:9px; align-items:flex-start; margin-top:12px; font-size:.64rem; line-height:1.6; color:var(--muted); }
.sp-safe i{ color:var(--ac); font-size:15px; flex-shrink:0; margin-top:1px; }
.sp-report{ display:block; margin:14px auto 0; background:none; border:none; color:var(--muted); font-size:.66rem; }
.sp-report:hover{ color:var(--ac-l); }

/* collection view */
.sp-cvcover{ position:relative; width:calc(100% + 2 * var(--pad)); margin:-4px calc(-1 * var(--pad)) 0; aspect-ratio:16/9; border-radius:0; overflow:hidden; background:var(--ph,#12153a); border:0; }
.sp-cvcover img, .sp-cvcover video{ width:100%; height:100%; object-fit:cover; display:block; }
.sp-cvtools{ display:flex; align-items:center; gap:6px; margin:2px 0 12px; }
.sp-cvtools .sp-search input{ height:34px; font-size:.7rem; }
.sp-cvsel{ height:34px; flex-shrink:0; max-width:42%; padding:0 8px; border-radius:var(--r); border:1px solid var(--line2); background:var(--surface); color:var(--ui-text,#fff); font:inherit; font-size:.68rem; outline:none; }
.sp-cvsel option{ background:var(--ph,#12153a); color:var(--ui-text,#fff); }
.sp-cvstk{ height:34px; flex-shrink:0; padding:0 10px; border-radius:var(--r); border:1px solid var(--line2); background:var(--surface); color:var(--sub); font:inherit; font-size:.66rem; font-weight:600; white-space:nowrap; transition:.2s; }
.sp-cvstk.on{ background:var(--ac); border-color:var(--ac); color:var(--on-ac); }
.sp-cvnone{ display:none; text-align:center; font-size:.7rem; color:var(--muted); padding:22px 0; }
.sp-cvname{ font-size:1rem; font-weight:700; margin-top:14px; word-break:break-word; }
.sp-cvn{ font-size:.66rem; color:var(--muted); margin:3px 0 8px; }
.sp-cvdesc{ font-size:.74rem; line-height:1.65; color:var(--sub); white-space:pre-wrap; word-break:break-word; margin-bottom:14px; }
.sp-pickrow{ display:flex; align-items:center; gap:11px; width:100%; padding:9px var(--pad); background:transparent; border:none; border-bottom:1px solid var(--line); color:var(--ui-text,#fff); text-align:left; transition:background .2s; }
.sp-pickrow:hover{ background:var(--ac-dim); }
.sp-pickrow.sel{ background:var(--ac-dim); }
.sp-pickrow .pt{ width:42px; height:42px; border-radius:8px; overflow:hidden; background:var(--ph,#12153a); flex-shrink:0; }
.sp-pickrow .pt img, .sp-pickrow .pt video{ width:100%; height:100%; object-fit:cover; display:block; }
.sp-pickrow .tx{ flex:1; min-width:0; font-size:.74rem; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sp-pickrow .tx small{ display:block; font-size:.62rem; color:var(--muted); font-weight:400; }
.sp-pickrow .ck{ width:20px; height:20px; border-radius:5px; border:1.5px solid rgba(var(--fg),.25); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; color:transparent; flex-shrink:0; transition:.2s; }
.sp-pickrow.sel .ck{ background:var(--ac); border-color:var(--ac); color:#000; }

/* list rows in modals (categories / report / sort) */
.sp-lrow{
    display:flex; align-items:center; gap:12px; width:100%; margin:0; padding:13px var(--pad);
    background:transparent; border:none; border-bottom:1px solid var(--line); color:var(--ui-text,#fff); text-align:left; transition:background .2s;
}
.sp-lrow:hover{ background:var(--ac-dim); }
.sp-lrow .ic{ width:24px; text-align:center; font-size:19px; color:var(--ac); flex-shrink:0; }
.sp-lrow .tx{ flex:1; min-width:0; font-size:.8rem; font-weight:500; }
.sp-lrow .tx small{ display:block; font-size:.64rem; color:var(--muted); margin-top:2px; font-weight:400; }
.sp-lrow .en{ font-size:.66rem; color:var(--muted); font-weight:600; }
.sp-lrow .ck{ width:20px; height:20px; border-radius:5px; border:1.5px solid rgba(var(--fg),.25); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; color:transparent; flex-shrink:0; transition:.2s; }
.sp-lrow.danger .tx, .sp-lrow.danger .tx small, .sp-lrow.danger .ic{ color:#ff4d4d; }
.sp-lrow.sel{ background:var(--ac-dim); }
.sp-lrow.sel .ck{ background:var(--ac); border-color:var(--ac); color:#000; }
.sp-lbl{ padding:12px var(--pad) 10px; font-size:.64rem; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--sub); border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
.sp-pad{ padding:12px var(--pad); }
.sp-done{ display:none; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:60px 24px; }
.sp-done i{ width:64px; height:64px; border-radius:50%; background:rgba(77,222,138,.12); border:1px solid rgba(77,222,138,.4); display:flex; align-items:center; justify-content:center; font-size:28px; color:#4dde8a; margin-bottom:16px; }
.sp-done b{ font-size:.95rem; margin-bottom:8px; }
.sp-done p{ font-size:.74rem; color:var(--sub); line-height:1.7; max-width:290px; }

/* confirm dialog */
.sp-ov.dlg{ z-index:9200; background:rgba(1,2,24,.78); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); align-items:center; padding:20px; }
.sp-dlgcard{ width:100%; max-width:330px; background:var(--sheet-solid,#0d1030); border:1px solid var(--line2); border-radius:8px; padding:18px 16px 14px; box-shadow:0 24px 70px rgba(0,0,0,.7); animation:spRise .25s cubic-bezier(.22,1,.36,1); }
.sp-dlgcard h3{ font-size:.9rem; margin-bottom:6px; }
.sp-dlgcard p{ font-size:.72rem; color:var(--sub); line-height:1.65; margin-bottom:16px; }
.sp-dlgcard .btns{ display:flex; gap:8px; }
.sp-dlgcard .btns .sp-btn{ flex:1; }

.sp-toast{
    position:fixed; left:50%; bottom:calc(30px + env(safe-area-inset-bottom)); transform:translateX(-50%) translateY(14px);
    background:var(--sheet-solid,#0d1030); border:1px solid var(--ac-bd); color:var(--ui-text,#fff); font-size:.72rem; font-weight:500;
    padding:8px 16px; border-radius:5px; z-index:99999; opacity:0; pointer-events:none; transition:all .25s;
    box-shadow:0 8px 28px rgba(0,0,0,.65); white-space:nowrap; max-width:92vw; overflow:hidden; text-overflow:ellipsis;
}
.sp-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
@keyframes spShake{ 0%,100%{transform:translateX(0);} 20%{transform:translateX(-6px);} 40%{transform:translateX(6px);} 60%{transform:translateX(-4px);} 80%{transform:translateX(4px);} }

/* ── SKELETON ── */
#page-skeleton{ position:fixed; top:var(--topbar-h); left:0; right:0; bottom:0; z-index:9999; background:var(--bg); background-attachment:fixed; display:flex; justify-content:center; opacity:1; transition:opacity .35s ease; pointer-events:none; }
#page-skeleton{ animation:spSkOut .35s ease 2s forwards; }
@keyframes spSkOut{ to{ opacity:0; visibility:hidden; } }
#page-skeleton.hide{ opacity:0; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),0.06); border-radius:6px; }
.sk::after{ content:''; position:absolute; inset:0; background:linear-gradient(90deg,transparent,rgba(var(--fg),0.09),transparent); transform:translateX(-100%); animation:skShimmer 1.5s infinite; }
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; border-bottom:1px solid var(--line); }
.sk-banner{ height:clamp(104px,30vw,150px); border-radius:0; }
.sk-id{ display:flex; flex-direction:row-reverse; align-items:flex-end; gap:12px; padding:0 var(--pad); margin-top:-42px; position:relative; }
.sk-av{ width:clamp(66px,20vw,84px); height:clamp(66px,20vw,84px); border-radius:50%; flex-shrink:0; }
.sk-chips{ display:flex; gap:8px; padding:10px var(--pad); margin-top:16px; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
.sk-chip{ width:84px; height:var(--act-h); border-radius:var(--r); }
.sk-grid{ display:grid; grid-template-columns:1fr 1fr; gap:18px 12px; padding:18px var(--pad); }
.sk-card{ aspect-ratio:4/5; border-radius:16px; }

@media (prefers-reduced-motion:reduce){ *{ animation-duration:.01ms !important; transition-duration:.01ms !important; } }

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched; text over photos keeps its dark scrim + white text) ═══════════ */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --surface:rgba(6,7,26,.05); --surface2:rgba(6,7,26,.08);
    --line:rgba(6,7,26,.1); --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52); --sub:rgba(6,7,26,.66);
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --solid:#ffffff; --sheet:rgba(255,255,255,.99); --sheet-solid:#ffffff; --ph:#eceef5;
}
[data-theme="light"] body{ color:#0b0d24; }
</style>

<style>
/* this shop's accent (chosen by the seller — red by default) */
:root{
    --ac:<?php echo $ac[0]; ?>; --ac-h:<?php echo $ac[1]; ?>; --ac-l:<?php echo $ac[2]; ?>;
    --ac-dim:<?php echo sp_rgba($ac[0], .16); ?>; --ac-bd:<?php echo sp_rgba($ac[0], .4); ?>; --ac-glow:<?php echo sp_rgba($ac[0], .34); ?>;
}
</style>
<style>
/* pick / filter / menu modals: medium size on phones (kept last so it wins) */
@media (max-width:600px){
  .sp-ovtop{ min-height:46px; }
  .sp-ovtitle{ font-size:.82rem; }
  .sp-ov .sp-lrow{ padding:10px var(--pad); gap:10px; }
  .sp-ov .sp-lrow .ic{ font-size:17px; width:20px; }
  .sp-ov .sp-lrow .tx{ font-size:.74rem; }
  .sp-ov .sp-lrow .tx small{ font-size:.6rem; margin-top:1px; }
  .sp-ov .sp-lrow .en{ font-size:.64rem; }
  .sp-ov .sp-lrow .ck{ width:18px; height:18px; font-size:10px; border-radius:4px; }
  .sp-ov .sp-lbl{ padding:9px var(--pad) 8px; font-size:.6rem; }
  .sp-ov .sp-pad{ padding:10px var(--pad); }
  .sp-ov .sp-search input{ height:36px; font-size:.74rem; padding-left:31px; }
  .sp-ov .sp-search i{ font-size:14px; left:10px; }
  .sp-ov .sp-seg button{ font-size:.66rem; height:calc(var(--act-h) - 2px); }
  .sp-ov .sp-in{ font-size:.74rem; height:38px; }
  .sp-ov textarea.sp-in{ height:auto; font-size:.76rem; }
  .sp-ov .sp-f label{ font-size:.66rem; }
  .sp-ov .sp-btn{ font-size:.7rem; }
  .sp-aboutp h3{ font-size:.78rem; }
  .sp-aboutp p, .sp-aboutp li{ font-size:.7rem; }
  .sp-cvsel, .sp-cvstk{ height:35px; font-size:.68rem; }
  .sp-cvtools .sp-search input{ height:35px; font-size:.7rem; }
}

/* ── seller's own look (chosen in Design shop) ── */
body.lk-avs-sm{ --avs:clamp(52px,16vw,64px); }
body.lk-avs-lg{ --avs:clamp(84px,27vw,108px); }
body.lk-avsh-rounded{ --avr:22%; }
body.lk-avsh-square{ --avr:6px; }
body.lk-bh-short .sp-banner{ height:clamp(66px,19vw,96px); }
body.lk-bh-tall .sp-banner{ height:clamp(160px,46vw,240px); }
body.lk-nob .sp-banner{ display:none; }
body.lk-nob .sp-id{ margin-top:0; padding-top:16px; align-items:center; }
body.lk-nob .sp-id-main{ padding-top:0; }
body.lk-av-left .sp-id{ flex-direction:row; }
body.lk-av-center .sp-id{ flex-direction:column; align-items:center; gap:7px; text-align:center; }
body.lk-av-center .sp-id-main{ padding-top:0; width:100%; }
body.lk-av-center .sp-tag, body.lk-av-center .sp-about, body.lk-av-center .sp-ann{ text-align:center; }
body.lk-av-center .sp-facts, body.lk-av-center .sp-acts{ justify-content:center; }
body.lk-hd-caps .sp-sec-h h2{ text-transform:uppercase; letter-spacing:1.2px; font-size:.78rem; }
body.lk-hd-line .sp-sec-h{ border-bottom:2px solid var(--ac); padding-bottom:7px; }
.sp-prodwrap{ display:block; }
/* design panel: section order */
.sp-secrow{ display:flex; align-items:center; gap:8px; padding:7px 9px; margin-bottom:6px; border-radius:var(--r); background:var(--surface); border:1px solid var(--line); }
.sp-secrow .nm{ flex:1; min-width:0; font-size:.72rem; font-weight:600; }
.sp-secrow .nm small{ display:block; font-size:.58rem; font-weight:400; color:var(--muted); margin-top:1px; }
.sp-secrow.off .nm{ opacity:.45; }
.sp-secrow button{ width:28px; height:28px; flex-shrink:0; border-radius:var(--r); border:1px solid var(--line2); background:transparent; color:#fff; font-size:15px; display:flex; align-items:center; justify-content:center; }
.sp-secrow button:hover{ border-color:var(--ac); color:var(--ac); }
.sp-secrow button:disabled{ opacity:.25; pointer-events:none; }
.sp-secrow .num{ width:18px; font-size:.62rem; color:var(--muted); text-align:center; flex-shrink:0; }
.sp-lkgrp{ margin-top:14px; }
.sp-lkgrp .sp-seg{ flex-wrap:wrap; }
.sp-lkgrp .sp-seg{ padding:2px; gap:2px; }
.sp-lkgrp .sp-seg button, .sp-ov .sp-lkgrp .sp-seg button{ flex:1 1 28%; height:calc(var(--act-h) - 4px); min-height:0; padding:0 6px; font-size:.66rem; line-height:1; }

/* policies, faq, qr, flash sale */
.sp-polsec{ padding:14px 0; border-bottom:1px solid var(--line); }
.sp-polsec h3{ display:flex; align-items:center; gap:7px; font-size:.78rem; font-weight:700; margin-bottom:7px; }
.sp-polsec h3 i{ color:var(--ac); font-size:16px; }
.sp-polsec p{ font-size:.72rem; line-height:1.7; color:var(--sub); word-break:break-word; }
.sp-polnote{ font-size:.64rem; line-height:1.6; color:var(--muted); padding:14px 0 4px; }
.sp-faqi{ border-bottom:1px solid var(--line); }
.sp-faqi summary{ list-style:none; cursor:pointer; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 0; font-size:.76rem; font-weight:600; }
.sp-faqi summary::-webkit-details-marker{ display:none; }
.sp-faqi summary i{ font-size:20px; color:var(--ac); flex-shrink:0; transition:transform .2s; }
.sp-faqi[open] summary i{ transform:rotate(180deg); }
.sp-faqi p{ font-size:.72rem; line-height:1.7; color:var(--sub); padding:0 0 14px; word-break:break-word; }
.sp-qrbox{ display:flex; flex-direction:column; align-items:center; padding:18px 0 6px; }
.sp-qrbox canvas{ width:min(260px,70vw); height:min(260px,70vw); background:#fff; border-radius:6px; }
.sp-qrname{ margin-top:14px; font-size:.82rem; font-weight:700; text-align:center; }
.sp-qrurl{ margin-top:4px; font-size:.62rem; color:var(--muted); text-align:center; word-break:break-all; max-width:100%; }
.sp-qrerr{ font-size:.72rem; color:var(--muted); padding:30px 0; text-align:center; }
.sp-qrbox .sp-bbtns{ width:100%; margin-top:16px; }
.sp-qrbox .sp-hint{ margin-top:12px; }
.sp-flash{ display:flex; align-items:center; gap:4px; margin-top:4px; font-size:.58rem; font-weight:700; color:var(--ac-l); letter-spacing:.2px; }
.sp-flash i{ font-size:11px; }
.sp-flash .t{ font-variant-numeric:tabular-nums; }
.sp-flash.big{ display:inline-flex; gap:7px; margin:2px 0 12px; padding:6px 10px; border:1px solid var(--ac-bd); background:var(--ac-dim); border-radius:5px; font-size:.7rem; }
.sp-flash.big i{ font-size:14px; }
.sp-flash.big span{ font-weight:600; color:var(--sub); }
.lay-compact .sp-flash{ font-size:.52rem; }
.sp-faqe{ margin-bottom:10px; padding:10px; border:1px solid var(--line); border-radius:6px; }
.sp-faqe-top{ display:flex; gap:8px; margin-bottom:8px; }
.sp-faqe-top .sp-in{ flex:1; min-width:0; }
.sp-faqe-x{ width:36px; flex-shrink:0; background:transparent; border:1px solid var(--line); border-radius:6px; color:var(--sub); font-size:18px; display:flex; align-items:center; justify-content:center; }
.sp-faqe-x:hover{ color:#ff5a5a; border-color:#ff5a5a; }

/* profile picture: hide completely, and the full-size viewer */
body.lk-noav .sp-av-link{ display:none; }
body.lk-noav .sp-id{ margin-top:0; padding-top:14px; }
body.lk-noav .sp-id-main{ padding-top:0; }
.sp-avbody{ display:flex; align-items:center; justify-content:center; padding:10px; cursor:zoom-out; }
.sp-avbody img{ max-width:100%; max-height:100%; width:auto; height:auto; object-fit:contain; border-radius:6px; display:block; }

/* phones and small screens only: everything 10% bigger (desktop view is untouched) */
@media (max-width:600px){
    html{ zoom:1.1; }
    body, .sp-lane{ min-height:calc(100vh / 1.1); }
    .sp-car img, .sp-car video{ max-height:calc(62vh / 1.1); }
    .sp-toast{ max-width:calc(92vw / 1.1); }
    .empty-state{ min-height:calc(46vh / 1.1); }
}
</style>
</head>
<body class="<?php echo trim(($corners === 'square' ? 'cr-square ' : '') . 'lk-av-' . $look['avpos'] . ' lk-avs-' . $look['avsize'] . ' lk-avsh-' . $look['avshape'] . ' lk-bh-' . $look['bh'] . ' lk-hd-' . $look['head'] . ($look['banner'] ? '' : ' lk-nob') . ($look['avshow'] ? '' : ' lk-noav')); ?>">

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk sk-banner"></div>
        <div class="sk-id"><div class="sk sk-av"></div><div class="sk" style="width:120px;height:13px"></div></div>
        <div class="sk-chips"><div class="sk sk-chip"></div><div class="sk sk-chip"></div><div class="sk sk-chip"></div></div>
        <div class="sk-grid"><?php for($i=0;$i<4;$i++): ?><div class="sk sk-card"></div><?php endfor; ?></div>
    </div>
</div>

<script>
(function(){ function g(){ var k = document.getElementById('page-skeleton'); if (k){ k.style.opacity = '0'; k.style.visibility = 'hidden'; setTimeout(function(){ if (k.parentNode) k.parentNode.removeChild(k); }, 450); } }
  document.addEventListener('DOMContentLoaded', function(){ setTimeout(g, 250); }); setTimeout(g, 1500); window.addEventListener('load', g); })();
</script>
<div class="sp-lane">

<!-- ══ TOPBAR ══ -->
    <div class="sp-topbar">
        <button type="button" class="sp-x" onclick="spClose()" aria-label="Close"><?php echo $X_SVG; ?></button>
        <span class="sp-title"><?php echo $is_own_profile ? 'Your Shop' : ($shop_none ? 'Shop' : $shop_title); ?></span>
        <button type="button" class="sp-x sp-menubtn" onclick="spOpenOv('spMenu')" aria-label="Menu"><i class="ri-menu-line"></i></button>
    </div>
    <div id="spGT" class="notranslate" style="position:absolute;left:-9999px;top:0;width:1px;height:1px;overflow:hidden" aria-hidden="true"></div>

<?php if($shop_locked): ?>
    <div class="sp-id" style="margin-top:0;padding-top:18px">
        <img class="sp-av" src="<?php echo htmlspecialchars($profile_pic); ?>" onerror="this.onerror=null;this.src='uploads/me.jpeg'" onclick="spAvView(this.src)" style="cursor:zoom-in" alt="">
        <div class="sp-id-main"><div class="sp-shop-name"><?php echo $who_name; ?></div></div>
    </div>
    <div class="empty-state">
        <div class="es-title"><?php echo $who_name; ?>’s shop is private</div>
        <p class="es-text">Only people who track <?php echo $who_name; ?> can see their products.</p>
        <div class="es-tips">
            <div class="es-tip"><i class="ri-lock-line"></i><span>Shops follow the privacy of the profile they belong to.</span></div>
            <div class="es-tip"><i class="ri-user-follow-line"></i><span>Track <?php echo $who_name; ?> and the shop opens right up.</span></div>
        </div>
        <a class="es-btn ghost" href="profile.php?user_id=<?php echo (int)$profile_id; ?>">View profile to track</a>
    </div>

<?php elseif($shop_none): ?>
    <div class="empty-state" style="padding-top:clamp(60px,18vw,110px)">
        <div class="es-title">This shop is not available</div>
        <p class="es-text">It isn’t open to visitors right now. Please check back later.</p>
        <a class="es-btn ghost" href="profile.php?user_id=<?php echo (int)$profile_id; ?>">Back to profile</a>
    </div>

<?php else: $SEC = []; ob_start(); ?>

    <?php if($is_own_profile || count($all_shops) > 1): ?>
    <div class="sp-shops">
        <?php foreach($all_shops as $i => $s):
            $sname = $s['shop_name'] !== '' ? $s['shop_name'] : ($i === 0 ? $user['username'] : 'Shop ' . ($i + 1));
            $sav = !empty($s['avatar']) ? SP_UPLOAD_URL . basename($s['avatar']) : $profile_pic; ?>
        <a class="sp-shopchip<?php echo $s['id'] === $shop['id'] ? ' active' : ''; ?>" href="profile_shop.php?user_id=<?php echo (int)$profile_id; ?>&shop=<?php echo (int)$s['id']; ?>">
            <img src="<?php echo htmlspecialchars($sav); ?>" alt="" onerror="this.onerror=null;this.src='uploads/me.jpeg'"><?php echo htmlspecialchars(mb_strimwidth($sname, 0, 22, '…')); ?><?php if(!$s['is_active']): ?> <i class="ri-eye-off-line"></i><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if($is_own_profile && count($all_shops) < SP_MAX_SHOPS): ?>
        <button type="button" class="sp-shopchip add" onclick="spNewShop()"><i class="ri-add-line"></i> New shop</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══ BANNER ══ -->
    <div class="sp-banner<?php echo $banner_url ? ' has-img' : ''; ?>"<?php echo $banner_url ? ' style="background-image:url(\'' . htmlspecialchars($banner_url, ENT_QUOTES) . '\')"' : ''; ?>>
        <?php if(!$banner_url): ?><i class="ri-store-2-fill sp-banner-ic"></i><?php endif; ?>
    </div>

    <!-- ══ IDENTITY (sits under the banner, never on it) ══ -->
    <div class="sp-id">
        <a class="sp-av-link" href="profile.php?user_id=<?php echo (int)$profile_id; ?>" aria-label="View profile picture" onclick="spAvView(this.querySelector('img').src); return false;"><img class="sp-av" src="<?php echo htmlspecialchars($av_src); ?>" onerror="this.onerror=null;this.src='uploads/me.jpeg'" alt=""></a>
        <div class="sp-id-main">
            <div class="sp-shop-name"><?php echo $shop_title; ?></div>
            <a class="sp-handle" href="profile.php?user_id=<?php echo (int)$profile_id; ?>">@<?php echo $who_name; ?></a>
        </div>
    </div>
    <?php if($shop['tagline'] !== ''): ?><p class="sp-tag"><?php echo htmlspecialchars($shop['tagline']); ?></p><?php endif; ?>

    <div class="sp-facts">
        <span class="sp-fact"><i class="ri-price-tag-3-line"></i><span><?php echo count($products); ?> product<?php echo count($products) === 1 ? '' : 's'; ?></span></span>
        <?php if($total_saves > 0): ?><span class="sp-fact"><i class="ri-bookmark-line"></i><span><?php echo (int)$total_saves; ?> save<?php echo $total_saves === 1 ? '' : 's'; ?></span></span><?php endif; ?>
        <?php if($hours_status): ?><button type="button" class="sp-fact<?php echo $hours_status[0] ? ' open' : ''; ?>" onclick="spOpenOv('spHours')"><i class="ri-time-line"></i><span><?php echo htmlspecialchars($hours_status[1]); ?></span></button><?php endif; ?>
        <?php if($shop['location'] !== ''): ?><a class="sp-fact" href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($shop['location']); ?>" target="_blank" rel="noopener noreferrer"><i class="ri-map-pin-2-line"></i><span><?php echo htmlspecialchars($shop['location']); ?></span></a><?php endif; ?>
    </div>

    <div class="sp-acts">
        <?php if($is_own_profile): ?>
            <button type="button" class="sp-btn main" onclick="spDesign()"><i class="ri-paint-brush-line"></i> Design shop</button>
            <?php if($can_add && $products): ?><button type="button" class="sp-btn ghost" onclick="spForm(0)"><i class="ri-add-line"></i> Add product</button><?php endif; ?>
        <?php else: ?>
            <?php if($can_message): ?>
            <button type="button" class="sp-btn main" onclick="spMsg()"><i class="ri-message-3-line"></i> Message</button>
            <button type="button" class="sp-btn ghost" onclick="spShare()"><i class="ri-share-forward-line"></i> Share</button>
            <?php else: ?>
            <button type="button" class="sp-btn main" onclick="spShare()"><i class="ri-share-forward-line"></i> Share</button>
            <?php endif; ?>
        <?php endif; ?>
        <?php if($shop['website'] !== ''): ?><a class="sp-icon" href="<?php echo htmlspecialchars($shop['website']); ?>" target="_blank" rel="noopener noreferrer nofollow" aria-label="Website"><i class="ri-global-line"></i></a><?php endif; ?>
        <?php if($shop['whatsapp'] !== ''): ?><a class="sp-icon" href="https://wa.me/<?php echo htmlspecialchars($shop['whatsapp']); ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><i class="ri-whatsapp-line"></i></a><?php endif; ?>
    </div>

    <?php if($is_own_profile && !$shop['is_active']): ?>
    <div class="sp-off"><i class="ri-eye-off-line"></i><span>This shop is deactivated. Only you can see it right now.</span><button type="button" class="sp-btn sm" onclick="spSetActive(1)">Reactivate</button></div>
    <?php endif; ?>

<?php $SEC['header'] = ob_get_clean(); ob_start(); ?>
    <?php if($shop['announcement'] !== ''): ?>
    <div class="sp-ann"><i class="ri-megaphone-line"></i><span><?php echo htmlspecialchars($shop['announcement']); ?></span></div>
    <?php endif; ?>

<?php $SEC['announce'] = ob_get_clean(); ob_start(); ?>
    <?php if($shop['about'] !== ''): ?><div class="sp-about" id="spAbout"></div><?php endif; ?>
<?php $SEC['about'] = ob_get_clean(); ?>

<?php if(!$products): ob_start(); ?>
    <div class="empty-state">
        <?php if($is_own_profile): ?>
            <div class="es-title">This shop is empty</div>
            <p class="es-text">Add your first product and your shop goes live. Design it any way you like, it’s your own mini website. Not selling right now? You can switch the shop off from “Design shop”.</p>
            <div class="es-tips">
                <div class="es-tip"><i class="ri-image-add-line"></i><span>Up to <?php echo SP_MAX_PRODUCTS; ?> products per shop, each with up to <?php echo SP_MAX_MEDIA; ?> photos and videos.</span></div>
                <div class="es-tip"><i class="ri-message-3-line"></i><span>Buyers tap “Ask about this” and a chat opens with you.</span></div>
                <div class="es-tip"><i class="ri-stack-line"></i><span>Group products into collections once you have a few.</span></div>
            </div>
            <?php if($can_add): ?><button type="button" class="es-btn" onclick="spForm(0)"><i class="ri-add-line"></i> Add your first product</button><?php endif; ?>
            <button type="button" class="es-btn ghost" style="margin-top:8px" onclick="spDesign()"><i class="ri-paint-brush-line"></i> Design shop</button>
        <?php else: ?>
            <div class="es-title">No products yet</div>
            <p class="es-text"><?php echo $who_name; ?> hasn’t listed anything here yet. Send a message to ask what’s coming.</p>
            <div class="es-tips">
                <div class="es-tip"><i class="ri-message-3-line"></i><span>Messages open a private chat with <?php echo $who_name; ?>.</span></div>
                <div class="es-tip"><i class="ri-bookmark-line"></i><span>Saved products show up here once they’re listed.</span></div>
            </div>
            <?php if($can_message): ?><button type="button" class="es-btn" onclick="spMsg()"><i class="ri-message-3-line"></i> Message <?php echo $who_name; ?></button><?php endif; ?>
        <?php endif; ?>
    </div>

<?php $SEC['products'] = ob_get_clean(); else: ob_start(); ?>

    <?php if($featured): ?>
    <div class="sp-sec" id="spFeatSec">
        <div class="sp-sec-h"><h2><i class="ri-star-fill"></i> Featured <em><?php echo count($featured); ?></em></h2></div>
        <div class="sp-hs feat<?php echo count($featured) === 1 ? ' one' : ''; ?>">
            <?php foreach($featured as $f):
                $fcv = $f['imgs'][0] ?? null;
                $foffer = $f['old_price'] !== null && (float)$f['old_price'] > (float)$f['price']; ?>
            <div class="sp-feat" onclick="spOpen(<?php echo (int)$f['id']; ?>)">
                <?php if($fcv && $fcv['type'] === 'video'): ?><video data-src="<?php echo htmlspecialchars($fcv['url']); ?>#t=0.1" preload="none" muted playsinline></video>
                <?php elseif($fcv): ?><img src="<?php echo htmlspecialchars($fcv['url']); ?>" alt="" loading="lazy" decoding="async" onerror="this.style.display='none'"><?php endif; ?>
                <span class="sp-feat-tag"><i class="ri-star-fill"></i> Featured</span>
                <?php if(!empty($f['off_sale'])): ?><span class="sp-feat-oos">Out of sale</span>
                <?php elseif($foffer): ?><span class="sp-feat-oos disc">-<?php echo ((int)($f['discount_pct'] ?? 0) ?: (int)round((1 - (float)$f['price'] / (float)$f['old_price']) * 100)); ?>%</span><?php endif; ?>
                <div class="sp-feat-info">
                    <div class="sp-feat-name"><?php echo htmlspecialchars($f['title']); ?></div>
                    <div class="sp-feat-pr">
                        <b><?php echo htmlspecialchars(sp_money($f['price'], $f['currency'])); ?></b>
                        <?php if($foffer): ?><s><?php echo htmlspecialchars(sp_money($f['old_price'], $f['currency'])); ?></s><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

<?php $SEC['featured'] = ob_get_clean(); ob_start(); ?>
    <?php if($collections || $is_own_profile): ?>
    <div class="sp-sec" id="spColSec">
        <div class="sp-sec-h"><h2><i class="ri-stack-line"></i> Collections <?php if($collections): ?><em><?php echo count($collections); ?></em><?php endif; ?></h2>
            <?php if($is_own_profile): ?><a href="javascript:void(0)" onclick="spColForm(0)">+ New</a><?php endif; ?></div>
        <?php if($collections): ?>
        <div class="sp-hs">
            <?php foreach($collections as $k => $c): ?>
            <div class="sp-col" style="--i:<?php echo min($k, 12); ?>" onclick="spCol(<?php echo (int)$c['id']; ?>)" role="button" tabindex="0" onkeydown="if(event.key==='Enter')spCol(<?php echo (int)$c['id']; ?>)">
                <div class="sp-stack">
                    <div class="sp-sl sp-s3"></div><div class="sp-sl sp-s2"></div>
                    <div class="sp-sl sp-s1">
                        <?php if($c['cover'] && $c['ctype'] === 'video'): ?><video data-src="<?php echo htmlspecialchars($c['cover']); ?>#t=0.1" preload="none" muted playsinline></video>
                        <?php elseif($c['cover']): ?><img src="<?php echo htmlspecialchars($c['cover']); ?>" alt="" loading="lazy">
                        <?php else: ?><i class="ri-stack-line ph"></i><?php endif; ?>
                        <button type="button" class="sp-col-btn" onclick="event.stopPropagation();spCol(<?php echo (int)$c['id']; ?>)">View Collection</button>
                    </div>
                </div>
                <div class="sp-col-name"><?php echo htmlspecialchars($c['name']); ?></div>
                <div class="sp-col-n"><?php echo count($c['pids']); ?> item<?php echo count($c['pids']) === 1 ? '' : 's'; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="sp-tipbox"><i class="ri-stack-line"></i><span>Put products together, like “Summer drop” or “Under $25”, and customers can browse them as a set.</span><button type="button" class="sp-btn sm" onclick="spColForm(0)">Create</button></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php $SEC['collections'] = ob_get_clean(); ob_start(); ?>
    <!-- ══ CHIPS ══ -->
    <div class="sp-chipsw"><div class="sp-chips" id="spChips">
        <?php if($used_cats): ?>
        <button type="button" class="sp-chip" data-f="cat" onclick="spOpenOv('spCats')"><span id="spCatLabel">Categories</span> <i class="ri-arrow-down-s-line"></i></button>
        <?php endif; ?>
        <?php if($has_popular): ?><button type="button" class="sp-chip" data-f="popular" onclick="spFilter('popular')"><i class="ri-fire-line"></i> Popular</button><?php endif; ?>
        <?php if($has_offers): ?><button type="button" class="sp-chip" data-f="offers" onclick="spFilter('offers')"><i class="ri-price-tag-3-line"></i> Offers</button><?php endif; ?>
        <button type="button" class="sp-chip active" data-f="all" onclick="spFilter('all')">All products</button>
        <button type="button" class="sp-chip" data-f="reviews" onclick="spFilter('reviews')"><i class="ri-chat-smile-3-line"></i> Reviews</button>
        <?php if(!$is_own_profile): ?><button type="button" class="sp-chip" data-f="saved" onclick="spFilter('saved')"><i class="ri-bookmark-line"></i> Saved</button><?php endif; ?>
    </div></div>

<?php $chipsHtml = ob_get_clean(); ob_start(); ?>
    <?php if($fav): ?>
    <div class="sp-sec" id="spFavSec">
        <div class="sp-sec-h"><h2><i class="ri-heart-3-line"></i> Customer favourites</h2><a href="javascript:void(0)" onclick="spFavAll()">See all</a></div>
        <div class="sp-hs">
            <?php foreach($fav as $k => $f) sp_card($f['p'], $f['rank'], $is_own_profile, $k); ?>
        </div>
    </div>
    <?php endif; ?>

<?php $SEC['favs'] = ob_get_clean(); ob_start(); ?>
    <div class="sp-sec sp-main" id="spMain">
        <div class="sp-sec-h"><h2><span id="spMainTitle">All products</span> <em id="spMainCount"><?php echo count($products); ?></em></h2></div>
        <div class="sp-sr" id="spSR">
            <div class="sp-search"><i class="ri-search-line"></i><input type="text" id="spQ" placeholder="Search this shop…" oninput="spSearch(this.value)" autocomplete="off"></div>
            <button type="button" class="sp-filter" id="spFilterBtn" onclick="spOpenOv('spSort')" aria-label="Sort and filter"><i class="ri-filter-3-line"></i></button>
        </div>
        <div class="sp-grid lay-<?php echo htmlspecialchars($layout); ?>" id="spGrid">
            <?php foreach($products as $k => $p) sp_card($p, null, $is_own_profile, $k); ?>
        </div>
        <div id="spRevGrid" style="display:none"></div>
        <div class="no-results" id="spNone"><i class="ri-search-line"></i><span>Nothing here yet</span></div>
    </div>
<?php $SEC['products'] = '<div class="sp-prodwrap">' . $chipsHtml . ob_get_clean() . '</div>'; endif; ?>
<?php foreach($look['order'] as $secKey){ if(in_array($secKey, $look['hidden'], true)) continue; echo $SEC[$secKey] ?? ''; } ?>

    <div class="sp-note">
        <div>Dezzme does not take part in transactions. We don’t process payments, we don’t hold or move anyone’s money, we don’t handle shipping or delivery, and we can’t guarantee, refund or step in on any purchase, sale or agreement made between people. Anything you decide to buy, sell or arrange with someone is entirely between you and them, so please take your time, ask questions, check the details and the reviews, and only go ahead when you feel comfortable. Thank you for understanding. <a class="sp-learn" href="help.php">Learn More</a></div>
    </div>
<?php endif; ?>

</div><!-- /.sp-lane -->

<?php if($shop): ?>
<!-- ══ PRODUCT DETAIL ══ -->
<div class="sp-ov" id="spDetail">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spDetail')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Product</div>
        <div class="sp-tb-side right"><button type="button" class="sp-x" onclick="spShare(window._cur)" aria-label="Share"><i class="ri-share-forward-line"></i></button></div>
    </div>
    <div class="sp-ovbody" id="spDetailBody"></div>
    <div class="sp-actionbar" id="spDetailBar"></div>
 </div>
</div>

<!-- ══ COLLECTION VIEW ══ -->
<div class="sp-ov" id="spColView">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spColView')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle" id="spCvTitle">Collection</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody" id="spCvBody"></div>
    <div class="sp-actionbar" id="spCvBar"></div>
 </div>
</div>

<!-- ══ BUSINESS HOURS ══ -->
<div class="sp-ov over" id="spHours">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spHours')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Business hours</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody" style="padding:0">
        <?php if($shop['hours_enabled']):
            try { $tzo = new DateTimeZone($shop['tz']); } catch (Throwable $e) { $tzo = new DateTimeZone('America/New_York'); }
            $today_i = (int)(new DateTime('now', $tzo))->format('N') - 1; ?>
        <div class="sp-lbl" style="border-top:none"><?php echo htmlspecialchars(str_replace('_', ' ', $shop['tz'])); ?> time</div>
        <?php foreach($shop['hours_arr'] as $i => $d): ?>
        <div class="sp-hday<?php echo $i === $today_i ? ' today' : ''; ?><?php echo $d['o'] ? '' : ' off'; ?>">
            <b><?php echo SP_DAYS[$i]; ?></b>
            <span><?php echo $d['o'] ? htmlspecialchars(sp_fmt_time($d['s']) . ' – ' . sp_fmt_time($d['e'])) : 'Closed'; ?></span>
        </div>
        <?php endforeach; ?>
        <div class="sp-pad" style="font-size:.66rem;color:var(--muted);line-height:1.6">You can message the seller any time. Replies may take a little longer outside these hours.</div>
        <?php endif; ?>
    </div>
 </div>
</div>

<!-- ══ CATEGORIES ══ -->
<div class="sp-ov over" id="spCats">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spCats')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Categories</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody" style="padding:0">
        <div class="sp-pad"><div class="sp-search"><i class="ri-search-line"></i><input type="text" id="spCatQ" placeholder="Search categories…" oninput="spCatSearch(this.value)" autocomplete="off"></div></div>
        <div class="sp-lbl" style="border-top:1px solid var(--line)">Pick a category</div>
        <button type="button" class="sp-lrow" data-n="all categories" onclick="spFilter('all')"><span class="ic"><i class="ri-apps-line"></i></span><span class="tx">All categories</span><i class="ri-arrow-right-s-line en"></i></button>
        <?php foreach($used_cats as $c): ?>
        <button type="button" class="sp-lrow" onclick="spPickCat(<?php echo (int)$c['id']; ?>, this.dataset.name)" data-name="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>" data-n="<?php echo htmlspecialchars(mb_strtolower($c['name']), ENT_QUOTES); ?>">
            <span class="ic"><i class="ri-price-tag-3-line"></i></span>
            <span class="tx"><?php echo htmlspecialchars($c['name']); ?></span>
            <span class="en"><?php echo (int)$c['n']; ?></span>
        </button>
        <?php endforeach; ?>
    </div>
 </div>
</div>

<!-- ══ CUSTOMER FAVOURITES ══ -->
<div class="sp-ov over" id="spFavs">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spFavs')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Customer favourites</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody" id="spFavBody"></div>
 </div>
</div>

<!-- ══ MENU ══ -->
<div class="sp-ov over" id="spMenu">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spMenu')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Menu</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody" style="padding:0">
        <button type="button" class="sp-lrow" onclick="spCopyLink()"><span class="ic"><i class="ri-links-line"></i></span><span class="tx">Copy shop link<small>Share this shop anywhere</small></span></button>
        <button type="button" class="sp-lrow" onclick="spCloseOv('spMenu'); spTranslate()"><span class="ic"><i class="ri-translate-2"></i></span><span class="tx">Translate this shop<small>Read it in your own language</small></span><span class="en notranslate" id="spTrLbl"></span></button>
        <?php $pol_arr = $shop ? $shop['policies_arr'] : []; $faq_arr = $shop ? $shop['faq_arr'] : [];
              $has_pol = (bool)array_filter($pol_arr, fn($x) => $x !== ''); ?>
        <?php if($has_pol): ?>
        <button type="button" class="sp-lrow" onclick="spCloseOv('spMenu'); spOpenOv('spPol')"><span class="ic"><i class="ri-file-list-3-line"></i></span><span class="tx">Shop policies<small>Shipping, returns and payment</small></span></button>
        <?php endif; ?>
        <?php if($faq_arr): ?>
        <button type="button" class="sp-lrow" onclick="spCloseOv('spMenu'); spOpenOv('spFaq')"><span class="ic"><i class="ri-question-answer-line"></i></span><span class="tx">FAQ<small>Answers to common questions</small></span></button>
        <?php endif; ?>
        <?php if($shop): ?>
        <button type="button" class="sp-lrow" onclick="spCloseOv('spMenu'); spQr()"><span class="ic"><i class="ri-qr-code-line"></i></span><span class="tx">QR code<small>Scan or print it to open this shop</small></span></button>
        <?php endif; ?>
        <?php if(!$is_own_profile): ?>
        <button type="button" class="sp-lrow danger" onclick="spReportUser()"><span class="ic"><i class="ri-flag-line"></i></span><span class="tx">Report<small>Tell us if something is wrong with this seller</small></span></button>
        <?php endif; ?>
        <div class="sp-lbl">About this page</div>
        <div class="sp-aboutp">
            <h3><i class="ri-store-2-line"></i> What is a shop page?</h3>
            <p>This is a shop on Dezzme. It works like a small website that belongs to one person or business, where they show what they offer and let you get in touch.</p>
            <ul>
                <li><i class="ri-shopping-bag-3-line"></i><span>Browse products, photos and videos, and open any item to read its Product Info.</span></li>
                <li><i class="ri-stack-line"></i><span>Collections group related products together so they are easier to find.</span></li>
                <li><i class="ri-price-tag-3-line"></i><span>Tags on a product show a discount, or when it is sold out or out of sale.</span></li>
                <li><i class="ri-chat-smile-3-line"></i><span>Read customer reviews, and leave your own once you have dealt with the seller.</span></li>
                <li><i class="ri-message-3-line"></i><span>Message the seller to ask questions, and bookmark products you like.</span></li>
            </ul>
            <p style="margin-bottom:0">Dezzme does not take part in transactions. Take your time and only pay when you feel good about the deal.</p>
        </div>
    </div>
 </div>
</div>

<!-- ══ POLICIES ══ -->
<div class="sp-ov over" id="spPol">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spPol')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Shop policies</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody">
        <?php foreach([['shipping','ri-truck-line','Shipping'],['returns','ri-arrow-go-back-line','Returns &amp; refunds'],['payment','ri-bank-card-line','Payment']] as $pp):
              $txt = $pol_arr[$pp[0]] ?? ''; if($txt === '') continue; ?>
        <div class="sp-polsec">
            <h3><i class="<?php echo $pp[1]; ?>"></i> <?php echo $pp[2]; ?></h3>
            <p><?php echo nl2br(htmlspecialchars($txt)); ?></p>
        </div>
        <?php endforeach; ?>
        <div class="sp-polnote">These are the seller’s own terms. Dezzme does not take part in transactions, so check anything unclear with the seller before you pay.</div>
    </div>
 </div>
</div>

<!-- ══ FAQ ══ -->
<div class="sp-ov over" id="spFaq">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spFaq')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">FAQ</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody">
        <?php foreach($faq_arr as $fq): ?>
        <details class="sp-faqi">
            <summary><span><?php echo htmlspecialchars($fq['q']); ?></span><i class="ri-arrow-down-s-line"></i></summary>
            <p><?php echo nl2br(htmlspecialchars($fq['a'])); ?></p>
        </details>
        <?php endforeach; ?>
    </div>
 </div>
</div>

<!-- ══ QR CODE ══ -->
<div class="sp-ov over" id="spQr">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spQr')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">QR code</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody">
        <div class="sp-qrbox">
            <canvas id="spQrCv" width="300" height="300"></canvas>
            <div class="sp-qrname"><?php echo $shop_title; ?></div>
            <div class="sp-qrurl notranslate" id="spQrUrl"></div>
            <div class="sp-qrerr" id="spQrErr" style="display:none">This link is too long for a QR code.</div>
            <div class="sp-bbtns">
                <button type="button" class="sp-btn main" onclick="spQrDl()"><i class="ri-download-2-line"></i> Download image</button>
                <button type="button" class="sp-btn ghost" onclick="spCopyLink()"><i class="ri-links-line"></i> Copy link</button>
            </div>
            <div class="sp-hint" style="text-align:center">Print it for your packaging, flyers or shop window, or share the image.</div>
        </div>
    </div>
 </div>
</div>

<!-- ══ TRANSLATE: pick your language ══ -->
<div class="sp-ov over notranslate" id="spLang">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spLang')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Translate this page</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody" style="padding:0">
        <div class="sp-pad"><div class="sp-search"><i class="ri-search-line"></i><input type="text" id="spLangQ" placeholder="Search your language…" oninput="spLangSearch(this.value)" autocomplete="off"></div></div>
        <div class="sp-lbl" style="border-top:1px solid var(--line)">Translate into</div>
        <div id="spLangList"></div>
    </div>
 </div>
</div>

<!-- ══ SORT & FILTER ══ -->
<div class="sp-ov over" id="spSort">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spSort')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Sort &amp; filter</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody" style="padding:0">
        <div class="sp-lbl" style="border-top:none">Sort by</div>
        <button type="button" class="sp-lrow sel" data-s="new" onclick="spSortPick('new')"><span class="ic"><i class="ri-sort-desc"></i></span><span class="tx">Newest to oldest</span><span class="ck">✓</span></button>
        <button type="button" class="sp-lrow" data-s="old" onclick="spSortPick('old')"><span class="ic"><i class="ri-sort-asc"></i></span><span class="tx">Oldest to newest</span><span class="ck"></span></button>
        <button type="button" class="sp-lrow" data-s="lh" onclick="spSortPick('lh')"><span class="ic"><i class="ri-arrow-up-line"></i></span><span class="tx">Price: low to high</span><span class="ck"></span></button>
        <button type="button" class="sp-lrow" data-s="hl" onclick="spSortPick('hl')"><span class="ic"><i class="ri-arrow-down-line"></i></span><span class="tx">Price: high to low</span><span class="ck"></span></button>
        <div class="sp-lbl">Availability</div>
        <div class="sp-pad"><div class="sp-seg" id="spStockSeg">
            <button type="button" data-v="0" class="active" onclick="spStockPick(0)">All</button>
            <button type="button" data-v="1" onclick="spStockPick(1)">In stock only</button>
        </div></div>
        <div class="sp-lbl">Price range</div>
        <div class="sp-pad">
            <div class="sp-row2">
                <div class="sp-f" style="margin:0"><label>Min</label><input class="sp-in" id="spMin" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0"></div>
                <div class="sp-f" style="margin:0"><label>Max</label><input class="sp-in" id="spMax" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Any"></div>
            </div>
            <div class="sp-hint">Matches the number on each product, in the currency it was priced in.</div>
        </div>
    </div>
    <div class="sp-actionbar">
        <button type="button" class="sp-btn ghost" onclick="spSortReset()">Reset</button>
        <button type="button" class="sp-btn main" onclick="spSortApply()"><i class="ri-check-line"></i> Show results</button>
    </div>
 </div>
</div>

<!-- ══ REPORT ══ -->
<div class="sp-ov over" id="spReport">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spReport')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Report product</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody" style="padding:0" id="spRepBody">
        <div class="sp-lbl" style="border-top:none">Select a reason</div>
        <div id="spRepList">
            <button type="button" class="sp-lrow" data-r="scam" onclick="spRepPick('scam')"><span class="ic"><i class="ri-spam-2-line"></i></span><span class="tx">Scam or fraud<small>Takes money without delivering, or asks for payment up front</small></span><span class="ck"></span></button>
            <button type="button" class="sp-lrow" data-r="prohibited" onclick="spRepPick('prohibited')"><span class="ic"><i class="ri-prohibited-line"></i></span><span class="tx">Prohibited item<small>Illegal, dangerous or not allowed on Dezzme</small></span><span class="ck"></span></button>
            <button type="button" class="sp-lrow" data-r="misleading" onclick="spRepPick('misleading')"><span class="ic"><i class="ri-error-warning-line"></i></span><span class="tx">Misleading or fake<small>Photos, price or description don't match</small></span><span class="ck"></span></button>
            <button type="button" class="sp-lrow" data-r="other" onclick="spRepPick('other')"><span class="ic"><i class="ri-question-line"></i></span><span class="tx">Something else</span><span class="ck"></span></button>
            <div style="display:flex;gap:10px;align-items:flex-start;padding:16px var(--pad)">
                <i class="ri-lock-2-line" style="color:var(--ac);font-size:18px;width:24px;text-align:center;flex-shrink:0"></i>
                <div style="font-size:.66rem;color:var(--muted);line-height:1.6"><b style="display:block;color:var(--ui-text,#fff);font-size:.74rem;margin-bottom:3px">Reports are private</b>The seller is never told who reported them. Every report is reviewed.</div>
            </div>
        </div>
        <div class="sp-done" id="spRepDone"><i class="ri-shield-check-line"></i><b>Report submitted</b><p>Thank you. Your report has been sent for review and the seller won't know it was you.</p><button type="button" class="sp-btn" style="margin-top:20px;padding:0 40px" onclick="spCloseOv('spReport')">Done</button></div>
    </div>
    <div class="sp-actionbar" id="spRepBar">
        <button type="button" class="sp-btn ghost" onclick="spCloseOv('spReport')">Cancel</button>
        <button type="button" class="sp-btn main" id="spRepSend" onclick="spRepSubmit()">Submit report</button>
    </div>
 </div>
</div>

<!-- ══ ALL REVIEWS ══ -->
<div class="sp-ov over" id="spRevAllOv">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spRevAllOv')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Reviews</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody" id="spRevAllBody"></div>
 </div>
</div>

<!-- ══ WRITE A REVIEW ══ -->
<div class="sp-ov over" id="spRevForm">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spRevForm')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle" id="spRevFormTitle">Write a review</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody">
        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-star-line"></i> Your rating</div>
            <div class="sp-starpick" id="spStarPick"></div>
        </div>
        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-chat-1-line"></i> Your review <span class="sp-opt">Optional</span></div>
            <textarea class="sp-in" id="rv_body" maxlength="500" rows="4" placeholder="How was the quality, the communication, the delivery? Your words help the next shopper." oninput="spCount(this,'rv_c',500)"></textarea>
            <div class="sp-count" id="rv_c">0 / 500</div>
        </div>
        <div class="sp-err" id="rv_err"></div>
    </div>
    <div class="sp-actionbar">
        <button type="button" class="sp-btn danger" id="rv_del" style="display:none" onclick="spRevDelete()"><i class="ri-delete-bin-line"></i> Delete</button>
        <button type="button" class="sp-btn main" id="rv_save" onclick="spRevSubmit()"><i class="ri-check-line"></i> Post review</button>
    </div>
 </div>
</div>
<?php endif; ?>

<?php if($is_own_profile && $shop): ?>
<!-- ══ ADD / EDIT PRODUCT ══ -->
<div class="sp-ov" id="spFormOv">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spFormOv')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle" id="spFormTitle">Add product</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody">
        <input type="hidden" id="sf_id">
        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-image-add-line"></i> Photos &amp; videos</div>
            <div class="sp-thumbs" id="sfThumbs"></div>
            <input type="file" id="sfFile" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" multiple style="display:none">
            <div class="sp-hint">Up to <?php echo SP_MAX_MEDIA; ?> photos and videos · the first one is the cover · photos under 5MB, videos under 50MB.</div>
        </div>
        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-price-tag-3-line"></i> Details</div>
            <div class="sp-f"><label>Product name</label><input class="sp-in" id="sf_title" maxlength="120" placeholder="e.g. Hand-Poured Soy Candle, Cedar &amp; Sage"></div>
            <div class="sp-f"><label>Currency</label><select class="sp-in" id="sf_cur"><?php echo sp_cur_options($shop_cur); ?></select></div>
            <div class="sp-row2">
                <div class="sp-f"><label>Price</label><input class="sp-in" id="sf_price" type="number" min="0" step="0.01" inputmode="decimal" placeholder="24.00" oninput="spDiscPrev()"></div>
                <div class="sp-f"><label>Discount % <span style="opacity:.7">(optional)</span></label><input class="sp-in" id="sf_disc" type="number" min="0" max="90" step="1" inputmode="numeric" placeholder="0" oninput="spDiscPrev()"></div>
            </div>
            <div class="sp-hint" id="sf_discp" style="display:none;font-size:.66rem;color:var(--muted);margin:-4px 0 12px"></div>
            <div class="sp-f" id="sf_endwrap" style="display:none"><label>Flash sale ends <span style="opacity:.7">(optional, shows a countdown)</span></label><input class="sp-in" id="sf_end" type="datetime-local"><div class="sp-hint" style="margin-top:5px">When the time runs out the product goes back to its regular price by itself.</div></div>
            <div class="sp-f">
                <label>Category</label>
                <select class="sp-in" id="sf_cat" onchange="$('sf_catnew').style.display = this.value==='__new' ? 'block' : 'none'">
                    <option value="0">No category</option>
                    <?php foreach($my_cats as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                    <option value="__new">+ New category…</option>
                </select>
                <input class="sp-in" id="sf_catnew" maxlength="40" placeholder="e.g. Home &amp; Living" style="display:none;margin-top:8px">
            </div>
            <div class="sp-f" style="margin-bottom:0"><label>Product Info</label><textarea class="sp-in" id="sf_desc" maxlength="1000" rows="4" placeholder="Size, materials, condition, shipping &amp; returns…" oninput="spCount(this,'sf_dc',1000)"></textarea><div class="sp-count" id="sf_dc">0 / 1000</div></div>
        </div>
        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-settings-3-line"></i> Display</div>
            <div class="sp-row"><i class="ri-checkbox-circle-line"></i><div class="lbl">In stock<small>Turn off to mark it Sold out</small></div><label class="sp-switch"><input type="checkbox" id="sf_stock" checked><span class="sp-slider"></span></label></div>
            <div class="sp-row"><i class="ri-forbid-line"></i><div class="lbl">Out of sale<small>Shows an “Out of sale” tag on the product and turns off buying</small></div><label class="sp-switch"><input type="checkbox" id="sf_off"><span class="sp-slider"></span></label></div>
            <div class="sp-row"><i class="ri-star-line"></i><div class="lbl">Feature this product<small>Shows in the Featured row at the top of your shop</small></div><label class="sp-switch"><input type="checkbox" id="sf_feat"><span class="sp-slider"></span></label></div>
        </div>
        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-cursor-line"></i> Button on the product</div>
            <div class="sp-seg" id="sfCtaSeg">
                <button type="button" data-v="message" class="active" onclick="spCta('message')"><i class="ri-message-3-line"></i> Ask in chat</button>
                <button type="button" data-v="link" onclick="spCta('link')"><i class="ri-external-link-line"></i> Visit link</button>
            </div>
            <input class="sp-in" id="sf_url" maxlength="500" placeholder="https://… (your Shopify, Etsy or checkout link)" style="display:none;margin-top:8px">
            <div class="sp-hint" id="sfCtaHint">Buyers tap the button and a chat with you opens in Messages with this product attached.</div>
        </div>
        <div class="sp-err" id="sf_err"></div>
    </div>
    <div class="sp-actionbar">
        <button type="button" class="sp-btn ghost" onclick="spCloseOv('spFormOv')">Cancel</button>
        <button type="button" class="sp-btn main" id="sf_save" onclick="spSubmit()"><i class="ri-check-line"></i> Save product</button>
    </div>
 </div>
</div>

<!-- ══ ADD / EDIT COLLECTION ══ -->
<div class="sp-ov over" id="spColForm">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spColForm')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle" id="spColFormTitle">New collection</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody" style="padding-left:0;padding-right:0">
        <div style="padding:0 var(--pad)">
            <input type="hidden" id="cf_id">
            <div class="sp-msec">
                <div class="sp-msec-h"><i class="ri-image-line"></i> Cover photo <span class="sp-opt">Optional</span></div>
                <div class="sp-bprev" id="cfCoverPrev"><span id="cfCoverTxt">No cover · the first product’s photo is used</span></div>
                <input type="file" id="cfCoverFile" accept="image/jpeg,image/png,image/webp" style="display:none">
                <div class="sp-bbtns">
                    <button type="button" class="sp-btn ghost sm" onclick="$('cfCoverFile').click()"><i class="ri-upload-2-line"></i> Upload cover</button>
                    <button type="button" class="sp-btn danger sm" id="cfCoverRm" onclick="spCoverRemove()" style="display:none"><i class="ri-delete-bin-line"></i> Remove</button>
                </div>
            </div>
            <div class="sp-msec">
                <div class="sp-msec-h"><i class="ri-stack-line"></i> Details</div>
                <div class="sp-f"><label>Collection name</label><input class="sp-in" id="cf_name" maxlength="60" placeholder="e.g. Fall Favorites"></div>
                <div class="sp-f" style="margin-bottom:0"><label>Description <span style="opacity:.7">(optional)</span></label><textarea class="sp-in" id="cf_desc" maxlength="200" rows="2" placeholder="e.g. Cozy picks for the first cold snap" oninput="spCount(this,'cf_dc',200)"></textarea><div class="sp-count" id="cf_dc">0 / 200</div></div>
            </div>
            <div class="sp-msec" style="border-top:none;padding-top:0">
                <div class="sp-msec-h"><i class="ri-shopping-bag-line"></i> Products in this collection <span class="sp-opt" id="cfCount">0 picked</span></div>
            </div>
        </div>
        <div id="cfPick" style="border-top:1px solid var(--line)"></div>
        <div class="sp-err" id="cf_err" style="padding:0 var(--pad)"></div>
    </div>
    <div class="sp-actionbar">
        <button type="button" class="sp-btn ghost" onclick="spCloseOv('spColForm')">Cancel</button>
        <button type="button" class="sp-btn main" id="cf_save" onclick="spColSubmit()"><i class="ri-check-line"></i> Save collection</button>
    </div>
 </div>
</div>

<!-- ══ DESIGN YOUR SHOP ══ -->
<div class="sp-ov" id="spDesignOv">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spDesignCancel()" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Design your shop</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody">
        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-image-line"></i> Banner <span class="sp-opt">Optional</span></div>
            <div class="sp-bprev" id="dBannerPrev"><span id="dBannerTxt">No banner · your accent colour is used</span></div>
            <input type="file" id="dBannerFile" accept="image/jpeg,image/png,image/webp" style="display:none">
            <div class="sp-bbtns">
                <button type="button" class="sp-btn ghost sm" onclick="$('dBannerFile').click()"><i class="ri-upload-2-line"></i> Upload banner</button>
                <button type="button" class="sp-btn danger sm" id="dBannerRm" onclick="spBannerRemove()" style="display:none"><i class="ri-delete-bin-line"></i> Remove</button>
            </div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-user-smile-line"></i> Shop picture</div>
            <div class="sp-avrow">
                <img id="dAvPrev" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" alt="" onerror="this.onerror=null;this.src='uploads/me.jpeg'">
                <div class="sp-bbtns">
                    <button type="button" class="sp-btn ghost sm" onclick="$('dAvFile').click()"><i class="ri-upload-2-line"></i> Change picture</button>
                    <button type="button" class="sp-btn danger sm" id="dAvRm" onclick="spAvatarReset()" style="display:none"><i class="ri-user-line"></i> Use profile picture</button>
                </div>
            </div>
            <input type="file" id="dAvFile" accept="image/jpeg,image/png,image/webp" style="display:none">
            <div class="sp-hint">By default your shop uses your profile picture.</div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-store-2-line"></i> Shop identity</div>
            <div class="sp-f"><label>Shop name</label><input class="sp-in" id="d_name" maxlength="60" placeholder="Defaults to your username"></div>
            <div class="sp-f"><label>Tagline</label><input class="sp-in" id="d_tag" maxlength="100" placeholder="e.g. Handmade candles, shipped from Austin, TX"></div>
            <div class="sp-f">
                <label>Location</label>
                <div class="sp-locwrap">
                    <input class="sp-in" id="dl_search" type="text" placeholder="Search a venue, street, city, state or country..." autocomplete="off">
                    <div class="sp-locspin" id="dl_spin"></div>
                    <div class="sp-locsug" id="dl_sug"></div>
                </div>
                <div class="sp-locpicked" id="dl_picked"><i class="ri-map-pin-fill"></i><span id="dl_text"></span><button type="button" onclick="spLocClear()" aria-label="Clear location"><i class="ri-close-line"></i></button></div>
                <div class="sp-hint">Start typing to search anywhere in the world, then tap a result to pick it.</div>
            </div>
            <div class="sp-f" style="margin-bottom:0"><label>About your shop</label><textarea class="sp-in" id="d_about" maxlength="400" rows="3" placeholder="Tell people who you are and what you sell…" oninput="spCount(this,'d_ac',400)"></textarea><div class="sp-count" id="d_ac">0 / 400</div></div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-money-dollar-circle-line"></i> Currency</div>
            <div class="sp-f" style="margin-bottom:0"><select class="sp-in" id="d_cur"><?php echo sp_cur_options('USD'); ?></select></div>
            <div class="sp-hint">New products start with this currency. You can still pick a different one on any product.</div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-palette-line"></i> Accent colour</div>
            <div class="sp-sws" id="dSws">
                <?php foreach(SP_ACCENTS as $k => $c): ?>
                <button type="button" class="sp-sw" data-k="<?php echo $k; ?>" style="--c:<?php echo $c[0]; ?>" aria-label="<?php echo ucfirst($k); ?>" onclick="spPickAccent('<?php echo $k; ?>')"><i class="ri-check-line"></i></button>
                <?php endforeach; ?>
            </div>
            <div class="sp-hint">Buttons, badges and highlights across this shop use this colour. You can see it change live.</div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-layout-grid-line"></i> Layout</div>
            <div class="sp-seg" id="dLayout">
                <button type="button" data-v="grid" onclick="spSeg('layout','grid')"><i class="ri-layout-grid-line"></i> Grid</button>
                <button type="button" data-v="compact" onclick="spSeg('layout','compact')"><i class="ri-apps-line"></i> Compact</button>
                <button type="button" data-v="list" onclick="spSeg('layout','list')"><i class="ri-list-unordered"></i> List</button>
            </div>
            <div class="sp-msec-h" style="margin-top:14px"><i class="ri-shape-line"></i> Corners</div>
            <div class="sp-seg" id="dCorners">
                <button type="button" data-v="soft" onclick="spSeg('corners','soft')">Soft</button>
                <button type="button" data-v="square" onclick="spSeg('corners','square')">Square</button>
            </div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-layout-masonry-line"></i> Page layout</div>
            <div class="sp-hint" style="margin:0 0 10px">Put your sections in any order you like, or hide the ones you don’t need. The header can only be first or second so it never gets buried. The new order shows once you save.</div>
            <div id="dSecList"></div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-magic-line"></i> Page style</div>
            <?php
            $lkGroups = [
                ['banner',  'Banner',                [['1','Show'],['0','Hide']]],
                ['bh',      'Banner height',         [['short','Short'],['normal','Normal'],['tall','Tall']]],
                ['avshow',  'Profile picture',       [['1','Show'],['0','Hide']]],
                ['avpos',   'Profile picture position', [['left','Left'],['center','Centre'],['right','Right']]],
                ['avshape', 'Profile picture shape', [['circle','Circle'],['rounded','Rounded'],['square','Square']]],
                ['avsize',  'Profile picture size',  [['sm','Small'],['md','Medium'],['lg','Large']]],
                ['head',    'Section titles',        [['plain','Plain'],['caps','Capitals'],['line','Underlined']]],
            ];
            foreach($lkGroups as $g): ?>
            <div class="sp-lkgrp">
                <div class="sp-hint" style="margin:0 0 6px;font-weight:600"><?php echo $g[1]; ?></div>
                <div class="sp-seg" id="dl_<?php echo $g[0]; ?>">
                    <?php foreach($g[2] as $o): ?><button type="button" data-v="<?php echo $o[0]; ?>" onclick="spLook('<?php echo $g[0]; ?>','<?php echo $o[0]; ?>')"><?php echo $o[1]; ?></button><?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-time-line"></i> Business hours <span class="sp-opt">Optional</span></div>
            <div class="sp-row">
                <i class="ri-store-3-line"></i>
                <div class="lbl">Show business hours<small>Customers see if you’re open right now</small></div>
                <label class="sp-switch"><input type="checkbox" id="d_hon" onchange="$('dHoursBox').style.display=this.checked?'block':'none'"><span class="sp-slider"></span></label>
            </div>
            <div id="dHoursBox" style="display:none;margin-top:6px">
                <div class="sp-f"><label>Time zone</label><select class="sp-in" id="d_tz"><?php echo sp_tz_options('America/New_York'); ?></select></div>
                <div id="dHours"></div>
            </div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-star-line"></i> Highlights</div>
            <div class="sp-row">
                <i class="ri-heart-3-line"></i>
                <div class="lbl">Customer favourites<small>Show your most saved products in a row</small></div>
                <label class="sp-switch"><input type="checkbox" id="d_favs"><span class="sp-slider"></span></label>
            </div>
            <div class="sp-favhow" style="padding:2px 0 10px">
                <h3><i class="ri-heart-3-line"></i> How Customer favourites works</h3>
                <p>A row at the top of your shop that shows the products customers love most, ranked from most to least popular. “See all” opens the full list.</p>
                <ul>
                    <li><i class="ri-bookmark-line"></i><span>Each time a customer <b>saves</b> a product, it earns <b>3 points</b>.</span></li>
                    <li><i class="ri-message-3-line"></i><span>Each time someone taps <b>Ask about this</b> or <b>Visit link</b>, it earns <b>1 point</b>.</span></li>
                    <li><i class="ri-refresh-line"></i><span>It updates by itself. Products with no saves or taps yet are not listed, and the row appears once your shop has 3 or more products.</span></li>
                </ul>
            </div>
            <div class="sp-f" style="margin:10px 0 0"><label>Announcement <span style="opacity:.7">(sale, shipping days, holiday…)</span></label><input class="sp-in" id="d_ann" maxlength="140" placeholder="e.g. Free shipping on orders over $50 this week"></div>
            <div class="sp-hint">To feature products, switch on “Feature this product” when you add or edit one.</div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-file-list-3-line"></i> Policies <span class="sp-opt">Optional</span></div>
            <div class="sp-hint" style="margin:0 0 10px">Customers open these from the menu button. Leave a box empty to skip it.</div>
            <div class="sp-f"><label>Shipping</label><textarea class="sp-in" id="d_pol_ship" rows="3" maxlength="800" placeholder="Where you ship, how long it takes, delivery cost…"></textarea></div>
            <div class="sp-f"><label>Returns &amp; refunds</label><textarea class="sp-in" id="d_pol_ret" rows="3" maxlength="800" placeholder="How many days to return, what is not accepted…"></textarea></div>
            <div class="sp-f" style="margin-bottom:0"><label>Payment</label><textarea class="sp-in" id="d_pol_pay" rows="3" maxlength="800" placeholder="Ways you accept payment, deposits, when payment is due…"></textarea></div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-question-answer-line"></i> FAQ <span class="sp-opt">Optional</span></div>
            <div class="sp-hint" style="margin:0 0 10px">Up to <?php echo SP_MAX_FAQ; ?> questions. Customers see them as a list that opens and closes.</div>
            <div id="dFaq"></div>
            <button type="button" class="sp-btn ghost sm" id="dFaqAdd" onclick="faqAdd()"><i class="ri-add-line"></i> Add a question</button>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-links-line"></i> Contact links <span class="sp-opt">Optional</span></div>
            <div class="sp-lnk"><i class="ri-global-line"></i><input class="sp-in" id="d_web" maxlength="500" placeholder="https://yourshop.com"></div>
            <div class="sp-lnk"><i class="ri-whatsapp-line"></i><input class="sp-in" id="d_wa" maxlength="20" inputmode="tel" placeholder="15125551234 (with country code)"></div>
        </div>

        <div class="sp-msec">
            <div class="sp-msec-h"><i class="ri-shield-line"></i> Shop status</div>
            <div class="sp-bbtns" style="margin-top:0">
                <button type="button" class="sp-btn ghost sm" id="dActiveBtn" onclick="spToggleActive()"><i class="ri-eye-off-line"></i> Deactivate shop</button>
                <button type="button" class="sp-btn danger sm" onclick="spDeleteShop()"><i class="ri-delete-bin-line"></i> Delete shop</button>
            </div>
            <div class="sp-hint">Deactivating hides this shop from everyone but you, so you can pause without losing anything. Deleting removes it for good, with its products, collections and reviews.</div>
        </div>
        <div class="sp-err" id="d_err"></div>
    </div>
    <div class="sp-actionbar">
        <button type="button" class="sp-btn ghost" onclick="spDesignCancel()">Cancel</button>
        <button type="button" class="sp-btn main" id="d_save" onclick="spDesignSubmit()"><i class="ri-check-line"></i> Save design</button>
    </div>
 </div>
</div>
<?php endif; ?>

<!-- ══ PROFILE PICTURE VIEWER ══ -->
<div class="sp-ov over" id="spAv">
 <div class="sp-ovlane">
    <div class="sp-ovtop">
        <div class="sp-tb-side"><button type="button" class="sp-x" onclick="spCloseOv('spAv')" aria-label="Close"><?php echo $X_SVG; ?></button></div>
        <div class="sp-ovtitle">Profile picture</div>
        <div class="sp-tb-side right"></div>
    </div>
    <div class="sp-ovbody sp-avbody" onclick="spCloseOv('spAv')"><img id="spAvImg" alt=""></div>
    <div class="sp-actionbar"><button type="button" class="sp-btn main" onclick="location.href='profile.php?user_id=<?php echo (int)$profile_id; ?>'"><i class="ri-user-line"></i> View profile</button></div>
 </div>
</div>

<!-- ══ CONFIRM ══ -->
<div class="sp-ov dlg" id="spConfirm" onclick="if(event.target===this) spCloseOv('spConfirm')">
    <div class="sp-dlgcard">
        <h3 id="spCfT"></h3><p id="spCfP"></p>
        <div class="btns">
            <button type="button" class="sp-btn ghost" onclick="spCloseOv('spConfirm')">Cancel</button>
            <button type="button" class="sp-btn" id="spCfOk">OK</button>
        </div>
    </div>
</div>

<div class="sp-toast" id="spToast"></div>

<script>
var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
var S = <?php echo json_encode($js_data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR); ?>;
var P = S.products, C = S.collections;
var DAYS = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
function $(id){ return document.getElementById(id); }
function h(tag, cls, txt){ var e = document.createElement(tag); if(cls) e.className = cls; if(txt != null) e.textContent = txt; return e; }
function money(v, c){
    v = Number(v); c = c || S.currency;
    try { return new Intl.NumberFormat('en-US', {style:'currency', currency:c, minimumFractionDigits:(v % 1 === 0 ? 0 : undefined)}).format(v); }
    catch(e){ return c + ' ' + v.toLocaleString('en-US', {maximumFractionDigits:2}); }
}
function mediaEl(m, cls){
    var e;
    if (m.type === 'video'){ e = h('video'); e.src = m.url + '#t=0.1'; e.muted = true; e.playsInline = true; e.preload = 'metadata'; }
    else { e = h('img'); e.src = m.url; e.alt = ''; e.loading = 'lazy'; }
    return e;
}

/* "more" / "less" — always sits right beside the last word of the last line */
function spMore(el, text, limit){
    el.innerHTML = '';
    var span = h('span'), btn = h('button', 'sp-more', 'more'); btn.type = 'button';
    if (text.length <= limit){ span.textContent = text; el.appendChild(span); return; }
    var cut = text.substring(0, limit).replace(/\s+\S*$/, '');
    var short = (cut.length > limit * .5 ? cut : text.substring(0, limit)).replace(/\s+$/, '') + '…';
    var open = false;
    function paint(){ span.textContent = open ? text : short; btn.textContent = open ? 'less' : 'more'; }
    btn.onclick = function(e){ e.stopPropagation(); open = !open; paint(); };
    paint(); el.appendChild(span); el.appendChild(document.createTextNode(' ')); el.appendChild(btn);
}

/* ── close (X) / basics ── */
function spClose(){
    /* back to the profile page this shop was opened from; any other way in goes straight to the profile */
    var fromProfile = false;
    try { var r = new URL(document.referrer); fromProfile = r.host === location.host && /\/profile\.php$/.test(r.pathname) && history.length > 1; } catch (e) {}
    if (fromProfile) history.back();
    else location.href = 'profile.php?user_id=' + S.owner_id;
}
function spAvView(src){
    var im = $('spAvImg');
    im.onerror = function(){ this.onerror = null; this.src = 'uploads/me.jpeg'; };
    im.src = src; spOpenOv('spAv');
}
function spMsg(){ if (!S.can_msg) return; location.href = S.msg_url + '?user=' + S.owner_id + '&user_id=' + S.owner_id; }
function spCount(el, id, max){ var c = $(id); if(c) c.textContent = el.value.length + ' / ' + (max || 500); }

/* ── overlays (stack) / toast / confirm ── */
var stack = [], zTop = 9000;
function lockBody(){ document.body.classList.toggle('sp-lock', stack.length > 0); }
function spOpenOv(id){
    var el = $(id); if(!el) return;
    el.style.zIndex = ++zTop;   /* whatever opens last is always on top */
    el.classList.remove('out'); el.classList.add('show');
    if(stack.indexOf(id) < 0) stack.push(id);
    lockBody();
    var b = el.querySelector('.sp-ovbody'); if(b) b.scrollTop = 0;
}
function spCloseOv(id){
    var el = $(id); if(!el || !el.classList.contains('show')) return;
    var i = stack.indexOf(id); if(i > -1) stack.splice(i, 1);
    lockBody();
    el.classList.add('out');
    el.querySelectorAll('video').forEach(function(v){ try{ v.pause(); }catch(e){} });
    setTimeout(function(){ if(stack.indexOf(id) === -1) el.classList.remove('show', 'out'); }, 200);
    if(id === 'spDesignOv' && S.settings) spApplyAccent(S.settings.accent);
}
document.addEventListener('keydown', function(e){
    if(e.key !== 'Escape' || !stack.length) return;
    spCloseOv(stack[stack.length - 1]);
});
var toastT;
function toast(m){ var t = $('spToast'); t.textContent = m; t.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(function(){ t.classList.remove('show'); }, 2400); }
function spConfirm(title, text, okLabel, cb){
    $('spCfT').textContent = title; $('spCfP').textContent = text; $('spCfOk').textContent = okLabel;
    $('spCfOk').onclick = function(){ spCloseOv('spConfirm'); cb(); };
    spOpenOv('spConfirm');
}

/* ── POST helper (CSRF in body + header) ── */
function post(fd){
    fd.append('csrf_token', CSRF);
    return fetch('profile_shop.php', { method:'POST', body:fd, credentials:'same-origin', headers:{'X-CSRF-Token':CSRF} })
        .then(function(r){ return r.json().catch(function(){ return {status:'error', msg:'Server error — try fewer or smaller files'}; }); })
        .then(function(d){ if (d.msg === 'csrf') location.reload(); return d; });
}
function simple(action, id, extra){
    var fd = new FormData(); fd.append('action', action); fd.append('product_id', id);
    if (extra) for (var k in extra) fd.append(k, extra[k]);
    return post(fd);
}

/* ── share ── */
function spShare(pid){
    var u = location.origin + location.pathname + '?user_id=' + S.owner_id + '&shop=' + S.shop_id + (pid ? '&p=' + pid : '');
    var title = pid && P[pid] ? P[pid].title : S.owner_name;
    if(navigator.share){ navigator.share({ title: title, url: u }).catch(function(){}); return; }
    if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(u).then(function(){ toast('Link copied'); }).catch(function(){ toast('Could not copy'); });
    } else toast('Could not copy');
}

/* ── bookmark ── */
function paintSave(id, on){
    document.querySelectorAll('.sp-bm[data-id="'+id+'"], .sp-icon[data-bm="'+id+'"]').forEach(function(b){
        b.classList.toggle('on', on);
        var i = b.querySelector('i'); if (i) i.className = on ? 'ri-bookmark-fill' : 'ri-bookmark-line';
    });
}
/* ══════════ TRANSLATE (the visitor picks their language) ══════════ */
var TR_LANGS = [['en','English','English'],['fr','French','Français'],['es','Spanish','Español'],['pt','Portuguese','Português'],['de','German','Deutsch'],['it','Italian','Italiano'],['nl','Dutch','Nederlands'],['ar','Arabic','العربية'],['zh-CN','Chinese (Simplified)','简体中文'],['zh-TW','Chinese (Traditional)','繁體中文'],['hi','Hindi','हिन्दी'],['bn','Bengali','বাংলা'],['ur','Urdu','اردو'],['ru','Russian','Русский'],['uk','Ukrainian','Українська'],['ja','Japanese','日本語'],['ko','Korean','한국어'],['tr','Turkish','Türkçe'],['vi','Vietnamese','Tiếng Việt'],['th','Thai','ไทย'],['id','Indonesian','Bahasa Indonesia'],['ms','Malay','Bahasa Melayu'],['tl','Filipino','Filipino'],['sw','Swahili','Kiswahili'],['yo','Yoruba','Yorùbá'],['ig','Igbo','Igbo'],['ha','Hausa','Hausa'],['zu','Zulu','isiZulu'],['xh','Xhosa','isiXhosa'],['af','Afrikaans','Afrikaans'],['am','Amharic','አማርኛ'],['so','Somali','Soomaali'],['rw','Kinyarwanda','Kinyarwanda'],['mg','Malagasy','Malagasy'],['pl','Polish','Polski'],['cs','Czech','Čeština'],['sk','Slovak','Slovenčina'],['hu','Hungarian','Magyar'],['ro','Romanian','Română'],['bg','Bulgarian','Български'],['el','Greek','Ελληνικά'],['sr','Serbian','Српски'],['hr','Croatian','Hrvatski'],['sl','Slovenian','Slovenščina'],['sv','Swedish','Svenska'],['no','Norwegian','Norsk'],['da','Danish','Dansk'],['fi','Finnish','Suomi'],['is','Icelandic','Íslenska'],['et','Estonian','Eesti'],['lv','Latvian','Latviešu'],['lt','Lithuanian','Lietuvių'],['he','Hebrew','עברית'],['fa','Persian','فارسی'],['ta','Tamil','தமிழ்'],['te','Telugu','తెలుగు'],['ml','Malayalam','മലയാളം'],['kn','Kannada','ಕನ್ನಡ'],['mr','Marathi','मराठी'],['gu','Gujarati','ગુજરાતી'],['pa','Punjabi','ਪੰਜਾਬੀ'],['ne','Nepali','नेपाली'],['si','Sinhala','සිංහල'],['my','Burmese','မြန်မာ'],['km','Khmer','ខ្មែរ'],['lo','Lao','ລາວ'],['ka','Georgian','ქართული'],['hy','Armenian','Հայերեն'],['az','Azerbaijani','Azərbaycan'],['kk','Kazakh','Қазақша'],['uz','Uzbek','Oʻzbek'],['mn','Mongolian','Монгол'],['ht','Haitian Creole','Kreyòl ayisyen'],['ca','Catalan','Català'],['gl','Galician','Galego'],['eu','Basque','Euskara'],['cy','Welsh','Cymraeg'],['ga','Irish','Gaeilge'],['sq','Albanian','Shqip'],['mk','Macedonian','Македонски'],['bs','Bosnian','Bosanski'],['be','Belarusian','Беларуская'],['mt','Maltese','Malti'],['la','Latin','Latina']];
var trState = { loaded:false, code:'' };
function spTranslate(){
    var q = $('spLangQ'); if (q) q.value = '';
    spLangBuild('');
    spOpenOv('spLang');
}
function spLangSearch(v){ spLangBuild((v || '').trim().toLowerCase()); }
function spLangBuild(v){
    var box = $('spLangList'); box.innerHTML = '';
    if (trState.code && !v){
        var o = h('button', 'sp-lrow'); o.type = 'button';
        o.innerHTML = '<span class="ic"><i class="ri-arrow-go-back-line"></i></span><span class="tx">Show original</span>';
        o.onclick = function(){ spCloseOv('spLang'); trApply(''); };
        box.appendChild(o);
    }
    TR_LANGS.forEach(function(L){
        if (v && (L[1] + ' ' + L[2]).toLowerCase().indexOf(v) === -1) return;
        var r = h('button', 'sp-lrow' + (trState.code === L[0] ? ' sel' : '')); r.type = 'button';
        r.innerHTML = '<span class="ic"><i class="ri-translate-2"></i></span><span class="tx"></span><span class="en"></span>';
        r.querySelector('.tx').textContent = L[2]; r.querySelector('.en').textContent = L[1];
        r.onclick = function(){ spCloseOv('spLang'); trApply(L[0]); };
        box.appendChild(r);
    });
}
function trCookie(code){
    var past = 'expires=Thu, 01 Jan 1970 00:00:00 GMT';
    var host = location.hostname, parts = host.split('.'), doms = [''];
    if (parts.length > 1){ doms.push(';domain=' + host); doms.push(';domain=.' + parts.slice(-2).join('.')); }
    doms.forEach(function(d){
        document.cookie = 'googtrans=' + (code ? '/auto/' + code : '') + ';path=/' + d + (code ? '' : ';' + past);
    });
}
function trLabel(code){
    var b = $('spTrLbl'); if (!b) return;
    if (!code){ b.textContent = ''; return; }
    for (var i = 0; i < TR_LANGS.length; i++) if (TR_LANGS[i][0] === code){ b.textContent = TR_LANGS[i][2]; return; }
}
/* choose a language (or '' for the original): the page reloads straight into it, right where you were */
function trApply(code){
    try { sessionStorage.setItem('spY', String(window.scrollY || 0)); } catch (e) {}
    trCookie(code);
    toast(code ? 'Translating…' : 'Showing original…');
    setTimeout(function(){ location.reload(); }, 120);
}
(function(){
    try { var y = sessionStorage.getItem('spY'); if (y !== null){ sessionStorage.removeItem('spY'); window.addEventListener('load', function(){ setTimeout(function(){ window.scrollTo(0, +y || 0); }, 150); }); } } catch (e) {}
    var m = document.cookie.match(/(?:^|;\s*)googtrans=\/[^\/]*\/([A-Za-z\-]+)/);
    if (!m || !m[1]) return;
    var code = m[1];
    if (!TR_LANGS.some(function(L){ return L[0] === code; })) return;
    trState.code = code;
    document.addEventListener('DOMContentLoaded', function(){
        trLabel(code);
        window.spGTInit = function(){ new google.translate.TranslateElement({ pageLanguage:'auto', autoDisplay:false }, 'spGT'); };
        var sc = document.createElement('script'); sc.src = 'https://translate.google.com/translate_a/element.js?cb=spGTInit'; document.head.appendChild(sc);
    });
})();
function spFavAll(){
    var b = $('spFavBody'); b.innerHTML = '';
    var cards = Array.prototype.slice.call(document.querySelectorAll('#spGrid .sp-card')).filter(function(c){ return +c.dataset.pop > 0; });
    cards.sort(function(x, y){ return (y.dataset.pop - x.dataset.pop) || (x.dataset.ord - y.dataset.ord); });
    if (!cards.length){ b.appendChild(h('div', 'sp-rempty', 'No favourites yet. Products show up here once customers start saving them.')); spOpenOv('spFavs'); return; }
    b.appendChild(h('div', 'sp-msec-h', cards.length + ' favourite' + (cards.length === 1 ? '' : 's')));
    var grid = h('div', 'sp-grid lay-grid');
    cards.forEach(function(c, i){
        var cl = c.cloneNode(true); cl.style.animation = 'none'; cl.style.display = '';
        var old = cl.querySelector('.sp-pill'); if (old) old.remove();
        var pill = h('span', 'sp-pill', '#' + (i + 1)); var im = cl.querySelector('.sp-img'); if (im) im.appendChild(pill);
        grid.appendChild(cl);
    });
    b.appendChild(grid);
    spOpenOv('spFavs');
}
function spReportUser(){
    spCloseOv('spMenu');
    if (typeof window.openReportModal === 'function'){ window.openReportModal({userId: S.owner_id}); return; }
    location.href = 'profile.php?user_id=' + S.owner_id + '&report=1';
}
function spCopyLink(){
    var u = spShopUrl();
    var ok = function(){ spCloseOv('spMenu'); toast('Link copied'); };
    var fb = function(){
        var t = document.createElement('textarea'); t.value = u; t.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); ok(); } catch (e) { toast('Could not copy'); }
        document.body.removeChild(t);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(u).then(ok).catch(fb); else fb();
}
/* tiny QR encoder: byte mode, error correction M, versions 1-10 */
var SpQR = (function(){
    var ECC = [null,[10,1,16,0,0],[16,1,28,0,0],[26,1,44,0,0],[18,2,32,0,0],[24,2,43,0,0],[16,4,27,0,0],[18,4,31,0,0],[22,2,38,2,39],[22,3,36,2,37],[26,4,43,1,44]];
    var ALIGN = [null,[],[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50]];
    var EXP = [], LOG = [], i, x = 1;
    for (i = 0; i < 255; i++){ EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 256) x ^= 0x11d; }
    for (i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
    function mul(a, b){ return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]]; }
    function rsRem(data, n){
        var g = [1], j, k;
        for (k = 0; k < n; k++){
            var ng = []; for (j = 0; j <= g.length; j++) ng.push(0);
            for (j = 0; j < g.length; j++){ ng[j] ^= g[j]; ng[j + 1] ^= mul(g[j], EXP[k]); }
            g = ng;
        }
        var res = []; for (k = 0; k < n; k++) res.push(0);
        data.forEach(function(b){
            var f = b ^ res.shift(); res.push(0);
            for (var q = 0; q < n; q++) res[q] ^= mul(g[q + 1], f);
        });
        return res;
    }
    function utf8(s){
        var o = [], c, k;
        s = unescape(encodeURIComponent(s));
        for (k = 0; k < s.length; k++){ c = s.charCodeAt(k); o.push(c); }
        return o;
    }
    function make(text){
        var bytes = utf8(text), v, info, dataCw;
        for (v = 1; v <= 10; v++){
            info = ECC[v]; dataCw = info[1] * info[2] + info[3] * info[4];
            if (dataCw * 8 >= 4 + (v < 10 ? 8 : 16) + bytes.length * 8) break;
        }
        if (v > 10) return null;
        var bits = [];
        function put(val, len){ for (var b = len - 1; b >= 0; b--) bits.push((val >>> b) & 1); }
        put(4, 4); put(bytes.length, v < 10 ? 8 : 16);
        bytes.forEach(function(b){ put(b, 8); });
        var cap = dataCw * 8;
        put(0, Math.min(4, cap - bits.length));
        while (bits.length % 8) bits.push(0);
        for (var pad = 0xEC; bits.length < cap; pad ^= 0xEC ^ 0x11) put(pad, 8);
        var cw = []; for (i = 0; i < bits.length; i += 8){ var by = 0; for (var q = 0; q < 8; q++) by = (by << 1) | bits[i + q]; cw.push(by); }
        /* blocks */
        var ecLen = info[0], blocks = [], pos = 0, n, b2;
        for (n = 0; n < info[1]; n++){ blocks.push(cw.slice(pos, pos + info[2])); pos += info[2]; }
        for (n = 0; n < info[3]; n++){ blocks.push(cw.slice(pos, pos + info[4])); pos += info[4]; }
        var ecs = blocks.map(function(b){ return rsRem(b, ecLen); });
        var out = [], maxLen = Math.max(info[2], info[4]);
        for (n = 0; n < maxLen; n++) blocks.forEach(function(b){ if (n < b.length) out.push(b[n]); });
        for (n = 0; n < ecLen; n++) ecs.forEach(function(e){ out.push(e[n]); });
        /* matrix */
        var size = 17 + 4 * v, m = [], fn = [], r, c;
        for (r = 0; r < size; r++){ m.push(new Array(size).fill(0)); fn.push(new Array(size).fill(false)); }
        function setF(xx, yy, d){ m[yy][xx] = d ? 1 : 0; fn[yy][xx] = true; }
        function finder(cx, cy){
            for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++){
                var d = Math.max(Math.abs(dx), Math.abs(dy)), xx = cx + dx, yy = cy + dy;
                if (xx >= 0 && xx < size && yy >= 0 && yy < size) setF(xx, yy, d !== 2 && d !== 4);
            }
        }
        for (i = 0; i < size; i++){ setF(6, i, i % 2 === 0); setF(i, 6, i % 2 === 0); }
        finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
        var al = ALIGN[v];
        for (r = 0; r < al.length; r++) for (c = 0; c < al.length; c++){
            if ((r === 0 && c === 0) || (r === 0 && c === al.length - 1) || (r === al.length - 1 && c === 0)) continue;
            for (var dy = -2; dy <= 2; dy++) for (var dx = -2; dx <= 2; dx++) setF(al[c] + dx, al[r] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
        }
        function formatBits(mask){
            var d = (0 << 3) | mask, rem = d;
            for (var k = 0; k < 10; k++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
            var bb = ((d << 10) | rem) ^ 0x5412;
            function bt(k){ return ((bb >>> k) & 1) !== 0; }
            for (var k = 0; k <= 5; k++) setF(8, k, bt(k));
            setF(8, 7, bt(6)); setF(8, 8, bt(7)); setF(7, 8, bt(8));
            for (k = 9; k < 15; k++) setF(14 - k, 8, bt(k));
            for (k = 0; k < 8; k++) setF(size - 1 - k, 8, bt(k));
            for (k = 8; k < 15; k++) setF(8, size - 15 + k, bt(k));
            setF(8, size - 8, true);
        }
        formatBits(0);
        if (v >= 7){
            var rv = v;
            for (i = 0; i < 12; i++) rv = (rv << 1) ^ ((rv >>> 11) * 0x1F25);
            var vb = (v << 12) | rv;
            for (i = 0; i < 18; i++){ var bt2 = ((vb >>> i) & 1) !== 0, a = size - 11 + i % 3, bb2 = Math.floor(i / 3); setF(a, bb2, bt2); setF(bb2, a, bt2); }
        }
        /* data */
        var idx = 0, total = out.length * 8, right, vert, j, up, yy2, xx2;
        for (right = size - 1; right >= 1; right -= 2){
            if (right === 6) right = 5;
            for (vert = 0; vert < size; vert++) for (j = 0; j < 2; j++){
                xx2 = right - j; up = ((right + 1) & 2) === 0; yy2 = up ? size - 1 - vert : vert;
                if (!fn[yy2][xx2] && idx < total){ m[yy2][xx2] = (out[idx >>> 3] >>> (7 - (idx & 7))) & 1; idx++; }
            }
        }
        function maskFn(k, xx, yy){
            switch (k){
                case 0: return (xx + yy) % 2 === 0; case 1: return yy % 2 === 0; case 2: return xx % 3 === 0; case 3: return (xx + yy) % 3 === 0;
                case 4: return (Math.floor(xx / 3) + Math.floor(yy / 2)) % 2 === 0; case 5: return xx * yy % 2 + xx * yy % 3 === 0;
                case 6: return (xx * yy % 2 + xx * yy % 3) % 2 === 0; default: return ((xx + yy) % 2 + xx * yy % 3) % 2 === 0;
            }
        }
        function applyMask(k){ for (var yy = 0; yy < size; yy++) for (var xx = 0; xx < size; xx++) if (!fn[yy][xx] && maskFn(k, xx, yy)) m[yy][xx] ^= 1; }
        function penalty(){
            var p = 0, rr, cc, run, dark = 0, line;
            for (rr = 0; rr < size; rr++) for (cc = 0; cc < size; cc++) if (m[rr][cc]) dark++;
            for (var dir = 0; dir < 2; dir++){
                for (rr = 0; rr < size; rr++){
                    line = []; for (cc = 0; cc < size; cc++) line.push(dir ? m[cc][rr] : m[rr][cc]);
                    run = 1;
                    for (cc = 1; cc <= size; cc++){
                        if (cc < size && line[cc] === line[cc - 1]) run++; else { if (run >= 5) p += 3 + (run - 5); run = 1; }
                    }
                    var s = line.join('');
                    var pats = ['000010111010', '010111010000'], pi, from;
                    for (pi = 0; pi < 2; pi++){ from = s.indexOf(pats[pi]); while (from !== -1){ p += 40; from = s.indexOf(pats[pi], from + 1); } }
                }
            }
            for (rr = 0; rr < size - 1; rr++) for (cc = 0; cc < size - 1; cc++){ var cv = m[rr][cc]; if (cv === m[rr][cc + 1] && cv === m[rr + 1][cc] && cv === m[rr + 1][cc + 1]) p += 3; }
            p += (Math.ceil(Math.abs(dark * 20 - size * size * 10) / (size * size)) - 1) * 10;
            return p;
        }
        var best = 0, bestP = 1e9, k;
        for (k = 0; k < 8; k++){
            applyMask(k); formatBits(k);
            var pp = penalty(); if (pp < bestP){ bestP = pp; best = k; }
            applyMask(k);
        }
        applyMask(best); formatBits(best);
        return m;
    }
    function draw(canvas, text, px){
        var m = make(text); if (!m) return false;
        var n = m.length, q = 4, cell = Math.max(2, Math.floor(px / (n + q * 2))), W = cell * (n + q * 2);
        canvas.width = W; canvas.height = W;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, W); ctx.fillStyle = '#000';
        for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (m[r][c]) ctx.fillRect((c + q) * cell, (r + q) * cell, cell, cell);
        return true;
    }
    return { make: make, draw: draw };
})();


/* videos only start downloading when they are about to be seen */
(function(){
    var vids = document.querySelectorAll('video[data-src]');
    function load(v){ var u = v.getAttribute('data-src'); if (!u) return; v.removeAttribute('data-src'); v.preload = 'metadata'; v.src = u; }
    if (!('IntersectionObserver' in window)){ for (var i = 0; i < vids.length; i++) load(vids[i]); return; }
    var io = new IntersectionObserver(function(es){ es.forEach(function(e){ if (e.isIntersecting){ io.unobserve(e.target); load(e.target); } }); }, {rootMargin: '250px'});
    for (var j = 0; j < vids.length; j++) io.observe(vids[j]);
})();

/* shop link, QR code */
function spShopUrl(){ return location.origin + location.pathname + '?user_id=' + S.owner_id + '&shop=' + S.shop_id; }
function spQr(){
    var u = spShopUrl(), cv = $('spQrCv');
    $('spQrUrl').textContent = u;
    var ok = SpQR.draw(cv, u, 1000);
    $('spQrErr').style.display = ok ? 'none' : '';
    cv.style.display = ok ? '' : 'none';
    spOpenOv('spQr');
}
function spQrDl(){
    var cv = $('spQrCv');
    if (!cv || cv.style.display === 'none') return;
    var go = function(url){ var a = document.createElement('a'); a.href = url; a.download = 'shop-qr-code.png'; document.body.appendChild(a); a.click(); document.body.removeChild(a); };
    if (cv.toBlob) cv.toBlob(function(b){ if (!b){ toast('Could not save the image'); return; } var url = URL.createObjectURL(b); go(url); setTimeout(function(){ URL.revokeObjectURL(url); }, 4000); }, 'image/png');
    else go(cv.toDataURL('image/png'));
}

/* flash sale countdown */
function spFmtLeft(sec){
    var d = Math.floor(sec / 86400), hh = Math.floor(sec % 86400 / 3600), mm = Math.floor(sec % 3600 / 60), ss = sec % 60, z = function(n){ return (n < 10 ? '0' : '') + n; };
    return (d > 0 ? d + 'd ' : '') + z(hh) + ':' + z(mm) + ':' + z(ss);
}
var spEndedOnce = false;
function spTick(){
    var now = Math.floor(Date.now() / 1000), els = document.querySelectorAll('.sp-flash'), keys = [];
    for (var i = 0; i < els.length; i++){
        var el = els[i], end = +el.getAttribute('data-end'), left = end - now, t = el.querySelector('.t');
        if (left > 0){ if (t) t.textContent = spFmtLeft(left); continue; }
        var k = 'spEnd' + end, tried = false;
        try { tried = !!sessionStorage.getItem(k); } catch (e) {}
        if (tried) el.style.display = 'none'; else keys.push(k);
    }
    if (keys.length && !spEndedOnce){
        spEndedOnce = true;
        keys.forEach(function(k){ try { sessionStorage.setItem(k, '1'); } catch (e) {} });
        setTimeout(function(){ location.reload(); }, 1200);
    }
}
setInterval(spTick, 1000); spTick();

/* FAQ editor (design modal) */
function faqRow(q, a){
    var r = h('div', 'sp-faqe');
    var top = h('div', 'sp-faqe-top');
    var qi = h('input', 'sp-in'); qi.type = 'text'; qi.maxLength = 120; qi.placeholder = 'Question, e.g. Do you deliver on weekends?'; qi.value = q || '';
    var rm = h('button', 'sp-faqe-x'); rm.type = 'button'; rm.setAttribute('aria-label', 'Remove'); rm.innerHTML = '<i class="ri-close-line"></i>';
    rm.onclick = function(){ r.parentNode.removeChild(r); faqCount(); };
    top.appendChild(qi); top.appendChild(rm);
    var ai = h('textarea', 'sp-in'); ai.rows = 2; ai.maxLength = 500; ai.placeholder = 'Answer'; ai.value = a || '';
    r.appendChild(top); r.appendChild(ai);
    return r;
}
function faqCount(){ var n = $('dFaq').children.length; $('dFaqAdd').style.display = n >= <?php echo SP_MAX_FAQ; ?> ? 'none' : ''; }
function faqBuild(list){
    var box = $('dFaq'); box.innerHTML = '';
    (list || []).forEach(function(it){ box.appendChild(faqRow(it.q, it.a)); });
    faqCount();
}
function faqAdd(){ var box = $('dFaq'); if (box.children.length >= <?php echo SP_MAX_FAQ; ?>) return; box.appendChild(faqRow('', '')); faqCount(); }
function faqCollect(){
    var out = [], rows = $('dFaq').children;
    for (var i = 0; i < rows.length; i++){
        var q = rows[i].querySelector('input').value.trim(), a = rows[i].querySelector('textarea').value.trim();
        if (q || a) out.push({q: q, a: a});
    }
    return out;
}

function spSave(id){
    var p = P[id]; if (!p || S.own) return;
    var nv = !p.saved; p.saved = nv; paintSave(id, nv);
    simple('toggle_save', id).then(function(d){
        if (d.status === 'ok'){ p.saved = !!d.saved; paintSave(id, p.saved); if(p.saved) toast('Saved. Find it under the Saved tab'); if (fl.f === 'saved') spApply(false); }
        else { p.saved = !nv; paintSave(id, p.saved); }
    }).catch(function(){ p.saved = !nv; paintSave(id, p.saved); });
}

/* ══════════ REVIEWS ══════════ */
function revsFor(pid){ return S.reviews.filter(function(r){ return r.pid === pid; }); }
function avgOf(list){ if(!list.length) return 0; var s = 0; list.forEach(function(r){ s += r.rating; }); return Math.round(s / list.length * 10) / 10; }
function starsEl(n){
    var s = h('span', 'sp-stars');
    for (var i = 1; i <= 5; i++){ var ic = h('i'); ic.className = i <= Math.round(n) ? 'ri-star-fill' : 'ri-star-line'; s.appendChild(ic); }
    return s;
}
function buildRevBlock(pid, box, limit, horiz){
    box.innerHTML = '';
    var list = revsFor(pid), mine = null;
    list.forEach(function(r){ if (r.mine) mine = r; });
    var what = pid ? 'product' : 'shop';
    if (list.length){
        var sum = h('div', 'sp-rsum');
        sum.appendChild(h('div', 'sp-rbig', avgOf(list).toFixed(1)));
        var m = h('div', 'm'); m.appendChild(starsEl(avgOf(list)));
        m.appendChild(h('small', '', list.length + ' review' + (list.length === 1 ? '' : 's')));
        sum.appendChild(m);
        box.appendChild(sum);
    }
    if (!S.own){
        var wb = h('button', 'sp-btn ghost sm'); wb.type = 'button'; wb.style.margin = '8px 0 4px';
        wb.innerHTML = '<i class="ri-edit-line"></i> ' + (mine ? 'Edit your review' : 'Write a review');
        wb.onclick = function(){ spRevForm(pid); };
        box.appendChild(wb);
    }
    if (!list.length){
        box.appendChild(h('div', 'sp-rempty', S.own ? 'No reviews yet. Happy customers will leave theirs here.' : 'No reviews yet. Be the first to review this ' + what + '.'));
        return;
    }
    var lst = box;
    if (horiz){ lst = h('div', 'sp-hs revs'); box.appendChild(lst); }
    list.slice(0, limit).forEach(function(r){
        var row = h('div', 'sp-rev' + (horiz ? ' sp-rcard' : '')), top = h('div', 'sp-rev-top');
        var profHref = 'profile.php?user_id=' + r.uid;
        var pa = h('a', 'sp-rev-av'); pa.href = profHref; pa.setAttribute('aria-label', 'View ' + r.name + '’s profile');
        var im = h('img'); im.loading = 'lazy'; im.src = r.pic; im.alt = ''; im.onerror = function(){ this.onerror = null; this.src = 'uploads/me.jpeg'; }; pa.appendChild(im); top.appendChild(pa);
        var n = h('div', 'n'), nb = h('b'), na = h('a', '', r.name); na.href = profHref; nb.appendChild(na);
        n.appendChild(nb); n.appendChild(h('small', '', r.ago)); top.appendChild(n);
        top.appendChild(starsEl(r.rating)); row.appendChild(top);
        if (r.body){ var b = h('div', 'sp-rev-body'); spMore(b, r.body, horiz ? 90 : 140); row.appendChild(b); }
        if (r.mine){
            var act = h('div', 'sp-rev-act');
            var e = h('button', null, 'Edit'); e.type = 'button'; e.onclick = function(){ spRevForm(pid); };
            var d = h('button', null, 'Delete'); d.type = 'button'; d.onclick = function(){ spConfirm('Delete your review?', 'This removes your rating and comment.', 'Delete', function(){ spRevDelete(pid); }); };
            act.appendChild(e); act.appendChild(d); row.appendChild(act);
        }
        lst.appendChild(row);
    });
    if (horiz) spArrows();
    if (list.length > limit){
        var all = h('button', 'sp-seeall', 'See all ' + list.length + ' reviews'); all.type = 'button';
        all.onclick = function(){ spRevAll(pid); }; box.appendChild(all);
    }
}
var revAllPid = null;
function spRevAll(pid){ revAllPid = pid; buildRevBlock(pid, $('spRevAllBody'), 9999); spOpenOv('spRevAllOv'); }
function refreshReviews(){
    var box = $('spRevBox'); if (box) buildRevBlock(0, box, 9999, true);
    var rg2 = $('spRevGrid'); if (rg2 && rg2.style.display !== 'none') buildRevBlock(0, rg2, 9999, true);
    var pb = $('spProdRev'); if (pb && window._cur) buildRevBlock(window._cur, pb, 3);
    if ($('spRevAllOv').classList.contains('show') && revAllPid !== null) buildRevBlock(revAllPid, $('spRevAllBody'), 9999);
}
var rvPid = 0, rvRating = 0;
function drawStarPick(){
    var box = $('spStarPick'); box.innerHTML = '';
    for (var i = 1; i <= 5; i++){
        (function(i){
            var b = h('button', i <= rvRating ? 'on' : ''); b.type = 'button'; b.setAttribute('aria-label', i + ' star' + (i > 1 ? 's' : ''));
            b.innerHTML = '<i class="' + (i <= rvRating ? 'ri-star-fill' : 'ri-star-line') + '"></i>';
            b.onclick = function(){ rvRating = i; drawStarPick(); };
            box.appendChild(b);
        })(i);
    }
}
function spRevForm(pid){
    rvPid = pid;
    var mine = null; revsFor(pid).forEach(function(r){ if (r.mine) mine = r; });
    rvRating = mine ? mine.rating : 0;
    $('spRevFormTitle').textContent = mine ? 'Edit your review' : (pid ? 'Review this product' : 'Review this shop');
    $('rv_body').value = mine ? mine.body : ''; spCount($('rv_body'), 'rv_c', 500);
    $('rv_err').textContent = ''; $('rv_del').style.display = mine ? '' : 'none';
    var sv = $('rv_save'); sv.disabled = false; sv.innerHTML = '<i class="ri-check-line"></i> ' + (mine ? 'Save changes' : 'Post review');
    drawStarPick(); spOpenOv('spRevForm');
}
function spRevSubmit(){
    if (!rvRating){ $('rv_err').textContent = 'Tap a star rating first'; return; }
    var fd = new FormData(); fd.append('action', 'save_review'); fd.append('shop_id', S.shop_id); fd.append('product_id', rvPid);
    fd.append('rating', rvRating); fd.append('body', $('rv_body').value.trim());
    var b = $('rv_save'); b.disabled = true;
    post(fd).then(function(d){
        if (d.status !== 'ok'){ $('rv_err').textContent = d.msg || 'Could not post'; b.disabled = false; return; }
        var body = $('rv_body').value.trim(), found = false;
        S.reviews.forEach(function(r){ if (r.pid === rvPid && r.mine){ r.rating = rvRating; r.body = body; r.ago = 'Just now'; found = true; } });
        if (!found) S.reviews.unshift({id:d.id, pid:rvPid, uid:S.uid, name:S.me.name, pic:S.me.pic, rating:rvRating, body:body, ago:'Just now', mine:true});
        refreshReviews(); spCloseOv('spRevForm'); toast('Thanks for your review!');
    }).catch(function(){ $('rv_err').textContent = 'Network error'; b.disabled = false; });
}
function spRevDelete(pid){
    var target = (typeof pid === 'number') ? pid : rvPid;
    var fd = new FormData(); fd.append('action', 'delete_review'); fd.append('shop_id', S.shop_id); fd.append('product_id', target);
    post(fd).then(function(d){
        if (d.status !== 'ok'){ toast('Could not delete'); return; }
        S.reviews = S.reviews.filter(function(r){ return !(r.pid === target && r.mine); });
        refreshReviews(); spCloseOv('spRevForm'); toast('Review deleted');
    });
}

/* ── product detail ── */
function spOpen(id){
    var p = P[id]; if (!p) return;
    window._cur = id;
    var b = $('spDetailBody'); b.innerHTML = '';
    var media = p.images || [];

    var wrap = h('div', 'sp-dcar'), car = h('div', 'sp-car');
    media.forEach(function(m){
        var e;
        if (m.type === 'video'){ e = h('video'); e.src = m.url; e.controls = true; e.playsInline = true; e.preload = 'metadata'; }
        else { e = h('img'); e.src = m.url; e.alt = ''; e.loading = 'lazy'; e.onerror = function(){ this.style.visibility = 'hidden'; }; }
        car.appendChild(e);
    });
    wrap.appendChild(car);
    var ctr = null;
    if (media.length > 1){ ctr = h('span', 'sp-ctr', '1/' + media.length); wrap.appendChild(ctr); }
    b.appendChild(wrap);
    if (media.length > 1){
        var dots = null;
        if (true){
            dots = h('div', 'sp-dots');
            media.forEach(function(_, k){ dots.appendChild(h('span', k === 0 ? 'on' : '')); });
            b.appendChild(dots);
        }
        car.addEventListener('scroll', function(){
            var k = Math.round(car.scrollLeft / car.clientWidth);
            if (dots) Array.prototype.forEach.call(dots.children, function(d, j){ d.classList.toggle('on', j === k); });
            ctr.textContent = (k + 1) + '/' + media.length;
        });
    }

    if (p.cat_name) b.appendChild(h('div', 'sp-dcat', p.cat_name));
    else b.appendChild(h('div', '')).style.height = '12px';
    b.appendChild(h('div', 'sp-dtitle', p.title));
    var pr = h('div', 'sp-dprices');
    pr.appendChild(h('span', 'sp-dprice', money(p.price, p.currency)));
    if (p.old_price && p.old_price > p.price){
        pr.appendChild(h('span', 'sp-dold', money(p.old_price, p.currency)));
        pr.appendChild(h('span', 'sp-doff', '-' + (p.discount || Math.round((1 - p.price / p.old_price) * 100)) + '%'));
    }
    if (p.off_sale) pr.appendChild(h('span', 'sp-dout', 'Out of sale'));
    else if (!p.in_stock) pr.appendChild(h('span', 'sp-dout', 'Sold out'));
    b.appendChild(pr);
    if (p.sale_ends && p.old_price && p.old_price > p.price && p.sale_ends > Math.floor(Date.now() / 1000) && !p.off_sale && p.in_stock){
        var fl = h('div', 'sp-flash big'); fl.setAttribute('data-end', p.sale_ends);
        fl.innerHTML = '<i class="ri-flashlight-fill"></i><span>Flash sale ends in</span><b class="t notranslate"></b>';
        b.appendChild(fl);
    }
    if (p.ago){ var ag = h('div', 'sp-dago'); ag.innerHTML = '<i class="ri-time-line"></i>'; ag.appendChild(document.createTextNode('Listed ' + p.ago.toLowerCase())); b.appendChild(ag); }
    if (p.desc){
        var pl = h('div', 'sp-msec-h', 'Product Info'); pl.style.marginTop = '4px'; b.appendChild(pl);
        var dd = h('div', 'sp-ddesc'); spMore(dd, p.desc, 180); b.appendChild(dd);
    }
    var others = Object.keys(P).map(function(k){ return P[k]; }).filter(function(o){ return o.id !== id; }).slice(0, 14);
    if (others.length){
        var ms = h('div', 'sp-msec'); ms.style.marginTop = '14px';
        ms.appendChild(h('div', 'sp-msec-h', 'More from this shop'));
        var row = h('div', 'sp-hs sp-more');
        others.forEach(function(o){
            var c = h('div', 'sp-mc'); c.onclick = function(){ spOpen(o.id); };
            var im = h('div', 'sp-mc-img'), m0 = o.images && o.images[0];
            if (m0 && m0.type === 'video'){ var v = h('video'); v.src = m0.url + '#t=0.1'; v.muted = true; v.preload = 'metadata'; v.setAttribute('playsinline', ''); im.appendChild(v); }
            else if (m0){ var i2 = h('img'); i2.src = m0.url; i2.alt = ''; i2.loading = 'lazy'; i2.onerror = function(){ this.style.display = 'none'; }; im.appendChild(i2); }
            else im.innerHTML = '<i class="ri-store-2-line"></i>';
            if (o.off_sale) im.appendChild(h('span', 'sp-mc-tag', 'Out of sale'));
            else if (!o.in_stock) im.appendChild(h('span', 'sp-mc-tag', 'Sold out'));
            else if (o.old_price && o.old_price > o.price) im.appendChild(h('span', 'sp-mc-tag d', '-' + (o.discount || Math.round((1 - o.price / o.old_price) * 100)) + '%'));
            c.appendChild(im);
            c.appendChild(h('div', 'sp-mc-n', o.title));
            c.appendChild(h('div', 'sp-mc-p', money(o.price, o.currency)));
            row.appendChild(c);
        });
        ms.appendChild(row); b.appendChild(ms);
    }

    var rv = h('div', 'sp-msec'); rv.appendChild(h('div', 'sp-msec-h', 'Reviews'));
    var rvb = h('div'); rvb.id = 'spProdRev'; rv.appendChild(rvb); b.appendChild(rv);
    buildRevBlock(id, rvb, 3);

    var sl = h('div', 'sp-seller');
    var av = h('img'); av.src = S.owner_pic; av.alt = ''; av.onerror = function(){ this.onerror = null; this.src = 'uploads/me.jpeg'; };
    sl.appendChild(av);
    var sd = h('div'); sd.appendChild(h('b', '', S.owner_name)); sd.appendChild(h('small', '', 'Seller')); sl.appendChild(sd);
    b.appendChild(sl);
    var sf = h('div', 'sp-safe'); sf.innerHTML = '<i class="ri-shield-check-line"></i>';
    sf.appendChild(h('span', '', 'Take your time and ask the seller anything. Dezzme doesn’t process payments or guarantee transactions, so only pay when you feel good about the deal.'));
    b.appendChild(sf);

    var bar = $('spDetailBar'); bar.innerHTML = '';
    if (S.own){
        var e = h('button', 'sp-btn ghost'); e.type = 'button'; e.innerHTML = '<i class="ri-edit-line"></i> Edit';
        e.onclick = function(){ spCloseOv('spDetail'); spForm(id); }; bar.appendChild(e);
        var d = h('button', 'sp-btn danger'); d.type = 'button'; d.innerHTML = '<i class="ri-delete-bin-line"></i> Delete';
        d.onclick = function(){ spConfirm('Delete this product?', 'It will be removed from your shop.', 'Delete', function(){
            simple('delete_product', id).then(function(r){ if (r.status === 'ok') location.reload(); else toast('Could not delete'); });
        }); };
        bar.appendChild(d);
    } else {
        var m = h('button', 'sp-btn main'); m.type = 'button';
        if (p.off_sale){ m.innerHTML = '<i class="ri-forbid-line"></i> Out of sale'; m.disabled = true; m.style.opacity = '.55'; }
        else if (p.cta_type === 'link' && p.cta_url){ m.innerHTML = '<i class="ri-external-link-line"></i> Visit link'; m.onclick = function(){ spVisit(p); }; }
        else if (S.can_msg){ m.innerHTML = '<i class="ri-message-3-line"></i> Ask about this'; m.onclick = function(){ spAsk(p); }; }
        else { m.innerHTML = '<i class="ri-share-forward-line"></i> Share'; m.onclick = function(){ spShare(id); }; }
        bar.appendChild(m);
        if (p.cta_type === 'link' && p.cta_url && S.can_msg){
            var mm = h('button', 'sp-icon'); mm.type = 'button'; mm.setAttribute('aria-label', 'Message seller'); mm.innerHTML = '<i class="ri-message-3-line"></i>';
            mm.onclick = function(){ spAsk(p); }; bar.appendChild(mm);
        }
        var bm = h('button', 'sp-icon' + (p.saved ? ' on' : '')); bm.type = 'button'; bm.setAttribute('data-bm', id); bm.setAttribute('aria-label', 'Save');
        bm.innerHTML = '<i class="' + (p.saved ? 'ri-bookmark-fill' : 'ri-bookmark-line') + '"></i>';
        bm.onclick = function(){ spSave(id); }; bar.appendChild(bm);
        var rp = h('button', 'sp-report', 'Report this product'); rp.type = 'button';
        rp.onclick = function(){ spReport(id); }; b.appendChild(rp);
    }
    spArrows();
    spOpenOv('spDetail');
}
function spAsk(p){
    if (!S.can_msg) return;
    simple('track_click', p.id);
    location.href = S.msg_url + '?user=' + S.owner_id + '&user_id=' + S.owner_id + '&send=' + encodeURIComponent('[product:' + p.id + ']');
}
function spVisit(p){
    spConfirm('You’re leaving Dezzme', 'Dezzme doesn’t process payments or guarantee transactions. Only continue if you trust this seller.', 'Continue', function(){
        simple('track_click', p.id);
        window.open(p.cta_url, '_blank', 'noopener,noreferrer');
    });
}

/* ── report ── */
var repReason = null;
function spReport(id){
    window._rp = id; repReason = null;
    document.querySelectorAll('#spRepList .sp-lrow').forEach(function(r){ r.classList.remove('sel'); r.querySelector('.ck').textContent = ''; });
    $('spRepList').style.display = ''; $('spRepDone').style.display = 'none'; $('spRepBar').style.display = '';
    $('spRepSend').disabled = false; $('spRepSend').textContent = 'Submit report';
    spOpenOv('spReport');
}
function spRepPick(r){
    repReason = r;
    document.querySelectorAll('#spRepList .sp-lrow').forEach(function(row){
        var s = row.dataset.r === r; row.classList.toggle('sel', s); row.querySelector('.ck').textContent = s ? '✓' : '';
    });
}
function spRepSubmit(){
    if(!repReason){
        var l = $('spRepList'); l.style.animation = 'none'; void l.offsetWidth; l.style.animation = 'spShake .35s ease';
        toast('Please select a reason first'); return;
    }
    var b = $('spRepSend'); b.disabled = true; b.textContent = 'Submitting…';
    simple('report_product', window._rp, {reason: repReason}).then(function(d){
        if(d.status === 'ok'){ $('spRepList').style.display = 'none'; $('spRepBar').style.display = 'none'; $('spRepDone').style.display = 'flex'; }
        else { b.disabled = false; b.textContent = 'Submit report'; toast('Could not send report'); }
    }).catch(function(){ b.disabled = false; b.textContent = 'Submit report'; toast('Network error'); });
}

/* ══════════ FILTERS / SORT / SEARCH ══════════ */
var fl = {f:'all', cat:0, q:'', label:'', sort:'new', stock:0, min:'', max:''};
function spPickCat(id, name){ fl.cat = id; spFilter('cat', name); }
function spFilter(f, label){
    spCloseOv('spCats');
    fl.f = f; if (f !== 'cat') fl.cat = 0;
    fl.label = (f === 'cat' && label) ? label : '';
    spApply(true);
}
function spSearch(v){ fl.q = (v || '').trim().toLowerCase(); spApply(false); }
function spCatSearch(v){
    v = (v || '').trim().toLowerCase();
    document.querySelectorAll('#spCats .sp-lrow').forEach(function(r){ r.style.display = (!v || (r.dataset.n || '').indexOf(v) > -1) ? '' : 'none'; });
}
function spSortPick(s){
    fl.sort = s;
    document.querySelectorAll('#spSort .sp-lrow').forEach(function(r){ var on = r.dataset.s === s; r.classList.toggle('sel', on); r.querySelector('.ck').textContent = on ? '✓' : ''; });
}
function spStockPick(v){
    fl.stock = v;
    document.querySelectorAll('#spStockSeg button').forEach(function(b){ b.classList.toggle('active', +b.dataset.v === v); });
}
function spSortReset(){ spSortPick('new'); spStockPick(0); $('spMin').value = ''; $('spMax').value = ''; }
function spSortApply(){ fl.min = $('spMin').value; fl.max = $('spMax').value; spCloseOv('spSort'); spApply(true); }
function spApply(scroll){
    var grid = $('spGrid'); if (!grid) return;
    /* hide Featured / Collections / Favourites first, so nothing below can stop it */
    var _hide = (fl.f !== 'all' && fl.f !== 'reviews') || !!fl.q || fl.sort !== 'new' || !!fl.stock || fl.min !== '' || fl.max !== '';
    document.body.classList.toggle('sp-focus', _hide);
    ['spFavSec', 'spFeatSec', 'spColSec'].forEach(function(id){ var e = $(id); if (e) e.style.display = _hide ? 'none' : ''; });
    document.querySelectorAll('#spChips .sp-chip').forEach(function(c){ c.classList.toggle('active', c.dataset.f === fl.f); });
    var cl = $('spCatLabel'); if (cl) cl.textContent = fl.label || 'Categories';
    var minV = fl.min === '' ? null : +fl.min, maxV = fl.max === '' ? null : +fl.max;
    var filtersOn = fl.sort !== 'new' || fl.stock || minV !== null || maxV !== null;
    $('spFilterBtn').classList.toggle('on', !!filtersOn);
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.sp-card'));
    cards.sort(function(a, b){
        if (fl.sort === 'old') return (a.dataset.ts - b.dataset.ts) || (b.dataset.ord - a.dataset.ord);
        if (fl.sort === 'lh') return (a.dataset.price - b.dataset.price) || (a.dataset.ord - b.dataset.ord);
        if (fl.sort === 'hl') return (b.dataset.price - a.dataset.price) || (a.dataset.ord - b.dataset.ord);
        if (fl.f === 'popular') return (b.dataset.pop - a.dataset.pop) || (a.dataset.ord - b.dataset.ord);
        return a.dataset.ord - b.dataset.ord;
    });
    var shown = 0;
    cards.forEach(function(c){
        grid.appendChild(c);
        var ok = fl.f === 'all' || fl.f === 'popular' || (fl.f === 'offers' && c.dataset.offer === '1') || (fl.f === 'cat' && +c.dataset.cat === fl.cat) || (fl.f === 'saved' && P[c.dataset.id] && P[c.dataset.id].saved);
        if (ok && fl.q && c.dataset.name.indexOf(fl.q) === -1) ok = false;
        if (ok && fl.stock && c.dataset.stock !== '1') ok = false;
        var pv = +c.dataset.price;
        if (ok && minV !== null && pv < minV) ok = false;
        if (ok && maxV !== null && pv > maxV) ok = false;
        c.style.display = ok ? '' : 'none'; if (ok) shown++;
    });
    var isRev = fl.f === 'reviews';
    $('spMainTitle').textContent = isRev ? 'Reviews' : fl.f === 'popular' ? 'Popular' : fl.f === 'offers' ? 'Offers' : fl.f === 'saved' ? 'Saved' : (fl.f === 'cat' && fl.label) ? fl.label : 'All products';
    $('spMainCount').textContent = isRev ? revsFor(0).length : shown;
    $('spNone').classList.toggle('show', !isRev && !shown);
    grid.style.display = isRev ? 'none' : '';
    var srow = $('spSR'); if (srow) srow.style.display = isRev ? 'none' : '';
    var rg = $('spRevGrid');
    if (rg){ rg.style.display = isRev ? '' : 'none'; if (isRev) buildRevBlock(0, rg, 9999, true); }
    if (scroll && window.scrollY > 120) $('spMain').scrollIntoView({behavior:'smooth', block:'start'});
}

/* ══════════ COLLECTIONS ══════════ */
function colById(id){ for (var i = 0; i < C.length; i++) if (C[i].id === id) return C[i]; return null; }
function spCol(id){
    var c = colById(id); if (!c) return;
    $('spCvTitle').textContent = c.name;
    var b = $('spCvBody'); b.innerHTML = '';
    if (c.cover){
        var cv = h('div', 'sp-cvcover');
        cv.appendChild(mediaEl({url:c.cover, type:c.ctype})); b.appendChild(cv);
    }
    b.appendChild(h('div', 'sp-cvname', c.name));
    b.appendChild(h('div', 'sp-cvn', c.pids.length + ' item' + (c.pids.length === 1 ? '' : 's')));
    if (c.desc){ var d = h('div', 'sp-cvdesc'); spMore(d, c.desc, 140); b.appendChild(d); }
    var grid = h('div', 'sp-grid lay-grid'), cn = 0;
    c.pids.forEach(function(pid, k){
        var src = document.querySelector('#spGrid .sp-card[data-id="' + pid + '"]'); if (!src) return;
        var cl = src.cloneNode(true); cl.style.animation = 'none'; cl.style.display = ''; cl.dataset.ci = k; grid.appendChild(cl); cn++;
    });
    if (!c.pids.length) b.appendChild(h('div', 'sp-rempty', 'No products in this collection yet.'));
    var none = h('div', 'sp-cvnone', 'No products match.');
    if (cn > 1){
        var st = { q:'', sort:'def', stock:false };
        var tools = h('div', 'sp-cvtools');
        var sw = h('div', 'sp-search'); sw.innerHTML = '<i class="ri-search-line"></i>';
        var qi = h('input'); qi.type = 'text'; qi.placeholder = 'Search this collection'; qi.autocomplete = 'off'; sw.appendChild(qi);
        var ss = h('select', 'sp-cvsel');
        [['def','Default'],['new','Newest'],['old','Oldest'],['lh','Price: low'],['hl','Price: high']].forEach(function(o){ var op = h('option', '', o[1]); op.value = o[0]; ss.appendChild(op); });
        var sk = h('button', 'sp-cvstk', 'In stock'); sk.type = 'button';
        tools.appendChild(sw); tools.appendChild(ss); tools.appendChild(sk);
        b.appendChild(tools);
        var apply = function(){
            var cards = Array.prototype.slice.call(grid.querySelectorAll('.sp-card'));
            cards.sort(function(x, y){
                if (st.sort === 'new') return y.dataset.ts - x.dataset.ts;
                if (st.sort === 'old') return x.dataset.ts - y.dataset.ts;
                if (st.sort === 'lh') return x.dataset.price - y.dataset.price;
                if (st.sort === 'hl') return y.dataset.price - x.dataset.price;
                return x.dataset.ci - y.dataset.ci;
            });
            var shown = 0;
            cards.forEach(function(cd){
                grid.appendChild(cd);
                var ok = (!st.q || (cd.dataset.name || '').indexOf(st.q) > -1) && (!st.stock || cd.dataset.stock === '1');
                cd.style.display = ok ? '' : 'none'; if (ok) shown++;
            });
            none.style.display = shown ? '' : 'block';
        };
        qi.oninput = function(){ st.q = qi.value.trim().toLowerCase(); apply(); };
        ss.onchange = function(){ st.sort = ss.value; apply(); };
        sk.onclick = function(){ st.stock = !st.stock; sk.classList.toggle('on', st.stock); apply(); };
    }
    b.appendChild(none);
    b.appendChild(grid);
    var bar = $('spCvBar'); bar.innerHTML = '';
    if (S.own){
        var e = h('button', 'sp-btn ghost'); e.type = 'button'; e.innerHTML = '<i class="ri-edit-line"></i> Edit';
        e.onclick = function(){ spColForm(id); }; bar.appendChild(e);
        var x = h('button', 'sp-btn danger'); x.type = 'button'; x.innerHTML = '<i class="ri-delete-bin-line"></i> Delete';
        x.onclick = function(){ spConfirm('Delete this collection?', 'Your products stay in the shop. Only the collection is removed.', 'Delete', function(){
            var fd = new FormData(); fd.append('action', 'delete_collection'); fd.append('id', id);
            post(fd).then(function(r){ if (r.status === 'ok') location.reload(); else toast('Could not delete'); });
        }); };
        bar.appendChild(x);
    } else {
        var s = h('button', 'sp-btn ghost'); s.type = 'button'; s.innerHTML = '<i class="ri-share-forward-line"></i> Share'; s.onclick = function(){ spShare(); }; bar.appendChild(s);
    }
    spOpenOv('spColView');
}
var cfSel = [], cfCover = {file:null, remove:false, url:''};
function cfPaintCover(){
    var has = !!cfCover.url && !cfCover.remove;
    $('cfCoverPrev').style.backgroundImage = has ? 'url("' + cfCover.url + '")' : '';
    $('cfCoverTxt').style.display = has ? 'none' : '';
    $('cfCoverRm').style.display = has ? '' : 'none';
}
function spCoverRemove(){ cfCover.file = null; cfCover.remove = true; cfPaintCover(); }
(function(){
    var f = $('cfCoverFile'); if (!f) return;
    f.addEventListener('change', function(){
        var file = f.files[0]; f.value = ''; if (!file) return;
        if (file.size > 5 * 1024 * 1024){ toast('Cover must be under 5MB'); return; }
        cfCover.file = file; cfCover.remove = false; cfCover.url = URL.createObjectURL(file); cfPaintCover();
    });
})();
function cfDrawPick(){
    var box = $('cfPick'); box.innerHTML = '';
    Object.keys(P).forEach(function(k){
        var p = P[k], id = +k;
        var row = h('button', 'sp-pickrow' + (cfSel.indexOf(id) > -1 ? ' sel' : '')); row.type = 'button';
        var pt = h('div', 'pt'); if (p.images[0]) pt.appendChild(mediaEl(p.images[0])); row.appendChild(pt);
        var tx = h('div', 'tx', p.title); tx.appendChild(h('small', '', money(p.price, p.currency))); row.appendChild(tx);
        row.appendChild(h('span', 'ck', '✓'));
        row.onclick = function(){
            var i = cfSel.indexOf(id); if (i > -1) cfSel.splice(i, 1); else cfSel.push(id);
            row.classList.toggle('sel', i === -1); $('cfCount').textContent = cfSel.length + ' picked';
        };
        box.appendChild(row);
    });
}
function spColForm(id){
    if (!S.own) return;
    if (!Object.keys(P).length){ toast('Add a product first'); return; }
    var c = id ? colById(id) : null;
    $('spColFormTitle').textContent = c ? 'Edit collection' : 'New collection';
    $('cf_id').value = c ? c.id : '';
    $('cf_name').value = c ? c.name : ''; $('cf_desc').value = c ? c.desc : ''; spCount($('cf_desc'), 'cf_dc', 200);
    cfCover = {file:null, remove:false, url: (c && c.custom_cover) ? c.cover : ''};
    cfPaintCover();
    cfSel = c ? c.pids.slice() : [];
    $('cfCount').textContent = cfSel.length + ' picked';
    cfDrawPick();
    $('cf_err').textContent = '';
    var sv = $('cf_save'); sv.disabled = false; sv.innerHTML = '<i class="ri-check-line"></i> Save collection';
    spOpenOv('spColForm');
}
function spColSubmit(){
    var err = $('cf_err'); err.textContent = '';
    var name = $('cf_name').value.trim();
    if (!name){ err.textContent = 'Give your collection a name'; return; }
    if (!cfSel.length){ err.textContent = 'Pick at least one product'; return; }
    var fd = new FormData();
    fd.append('action', 'save_collection'); fd.append('shop_id', S.shop_id); fd.append('id', $('cf_id').value);
    fd.append('name', name); fd.append('description', $('cf_desc').value.trim()); fd.append('product_ids', cfSel.join(','));
    if (cfCover.remove) fd.append('remove_cover', '1');
    if (cfCover.file) fd.append('cover', cfCover.file);
    var btn = $('cf_save'); btn.disabled = true; btn.textContent = 'Saving…';
    function reset(){ btn.disabled = false; btn.innerHTML = '<i class="ri-check-line"></i> Save collection'; }
    post(fd).then(function(d){
        if (d.status === 'ok'){ location.reload(); return; }
        err.textContent = d.msg || 'Something went wrong'; reset();
    }).catch(function(){ err.textContent = 'Network error'; reset(); });
}

/* ══════════ ADD / EDIT PRODUCT ══════════ */
var fExisting = [], fRemoved = [], fNew = [], fCta = 'message';
function spCta(v){
    fCta = v;
    document.querySelectorAll('#sfCtaSeg button').forEach(function(b){ b.classList.toggle('active', b.dataset.v === v); });
    $('sf_url').style.display = v === 'link' ? 'block' : 'none';
    $('sfCtaHint').textContent = v === 'link'
        ? 'Buyers will be sent to your link. They can still message you from the product.'
        : 'Buyers tap the button and a chat with you opens in Messages with this product attached.';
}
function spDiscPrev(){
    var pr = parseFloat($('sf_price').value), d = parseInt($('sf_disc').value, 10), el = $('sf_discp');
    if (pr > 0 && d > 0 && d <= 90){
        el.style.display = ''; el.textContent = 'Customers will see ' + money(Math.round(pr * (100 - d)) / 100, $('sf_cur').value) + ' instead of ' + money(pr, $('sf_cur').value) + ' (' + d + '% off)';
    } else el.style.display = 'none';
    $('sf_endwrap').style.display = (d > 0 && d <= 90) ? '' : 'none';
}
function spLocalDT(ts){
    var d = new Date(ts * 1000), z = function(n){ return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()) + 'T' + z(d.getHours()) + ':' + z(d.getMinutes());
}
function spForm(id){
    if (!S.own) return;
    var p = id ? P[id] : null;
    $('spFormTitle').textContent = p ? 'Edit product' : 'Add product';
    $('sf_id').value = p ? p.id : '';
    $('sf_title').value = p ? p.title : '';
    $('sf_cur').value = p ? p.currency : S.currency;
    $('sf_price').value = p ? ((p.discount > 0 && p.old_price) ? p.old_price : p.price) : '';
    $('sf_disc').value = p && p.discount > 0 ? p.discount : '';
    $('sf_off').checked = p ? !!p.off_sale : false;
    $('sf_end').value = (p && p.sale_ends) ? spLocalDT(p.sale_ends) : '';
    spDiscPrev();
    $('sf_desc').value = p ? p.desc : ''; spCount($('sf_desc'), 'sf_dc', 1000);
    $('sf_cat').value = p && p.cat_id ? String(p.cat_id) : '0';
    $('sf_catnew').value = ''; $('sf_catnew').style.display = 'none';
    $('sf_stock').checked = p ? !!p.in_stock : true;
    $('sf_feat').checked = p ? !!p.featured : false;
    $('sf_url').value = p ? p.cta_url : '';
    spCta(p ? p.cta_type : 'message');
    $('sf_err').textContent = '';
    var sv = $('sf_save'); sv.disabled = false; sv.innerHTML = '<i class="ri-check-line"></i> Save product';
    fExisting = p ? p.images.slice() : []; fRemoved = []; fNew = [];
    drawThumbs();
    spOpenOv('spFormOv');
}
function drawThumbs(){
    var box = $('sfThumbs'); box.innerHTML = '';
    var n = 0;
    function add(m, onRemove){
        var t = h('div', 'sp-th' + (n === 0 ? ' cover' : ''));
        t.appendChild(mediaEl(m));
        if (m.type === 'video') t.appendChild(h('i', 'vi ri-video-fill'));
        if (n === 0) t.appendChild(h('div', 'cv', 'COVER'));
        var x = h('button', null, '✕'); x.type = 'button'; x.setAttribute('aria-label', 'Remove'); x.onclick = onRemove; t.appendChild(x);
        box.appendChild(t); n++;
    }
    fExisting.forEach(function(im, k){ add(im, function(){ fRemoved.push(im.id); fExisting.splice(k, 1); drawThumbs(); }); });
    fNew.forEach(function(f, k){ add({url: URL.createObjectURL(f), type: f.type.indexOf('video') === 0 ? 'video' : 'image'}, function(){ fNew.splice(k, 1); drawThumbs(); }); });
    if (n < S.max_media){
        var a = h('button', 'sp-add-img'); a.type = 'button'; a.setAttribute('aria-label', 'Add photos or videos'); a.innerHTML = '<i class="ri-add-line"></i>';
        a.onclick = function(){ $('sfFile').click(); }; box.appendChild(a);
    }
}
(function(){
    var f = $('sfFile'); if (!f) return;
    f.addEventListener('change', function(){
        var skipped = 0;
        Array.prototype.forEach.call(f.files, function(file){
            if (fExisting.length + fNew.length >= S.max_media){ skipped++; return; }
            var vid = file.type.indexOf('video') === 0;
            if (vid && file.size > 50 * 1024 * 1024){ toast('Each video must be under 50MB'); return; }
            if (!vid && file.size > 5 * 1024 * 1024){ toast('Each photo must be under 5MB'); return; }
            fNew.push(file);
        });
        if (skipped) toast('Max ' + S.max_media + ' photos and videos');
        f.value = ''; drawThumbs();
    });
})();
function uploadAll(pid, btn){
    var queue = fNew.slice(), total = queue.length, done = 0;
    function next(){
        if (!queue.length) return Promise.resolve();
        var batch = [], size = 0;
        while (queue.length && batch.length < 5){
            if (batch.length && size + queue[0].size > 24 * 1048576) break;
            var f = queue.shift(); batch.push(f); size += f.size;
        }
        var fd = new FormData(); fd.append('action', 'upload_media'); fd.append('product_id', pid);
        batch.forEach(function(f){ fd.append('media[]', f); });
        btn.textContent = 'Uploading ' + done + '/' + total + '…';
        return post(fd).then(function(r){
            if (r.status !== 'ok') throw new Error(r.msg || 'Upload failed');
            done += batch.length;
            return next();
        });
    }
    return next();
}
function spSubmit(){
    var err = $('sf_err'); err.textContent = '';
    var title = $('sf_title').value.trim();
    if (!title){ err.textContent = 'Product name is required'; return; }
    if ($('sf_price').value === '' || +$('sf_price').value < 0){ err.textContent = 'Enter a valid price'; return; }
    var total = fExisting.length + fNew.length;
    if (total < 1){ err.textContent = 'Add at least one photo or video'; return; }
    if (total > S.max_media){ err.textContent = 'Max ' + S.max_media + ' photos and videos'; return; }
    if (fCta === 'link' && !/^https?:\/\//i.test($('sf_url').value.trim())){ err.textContent = 'Enter a link starting with http:// or https://'; return; }

    var fd = new FormData();
    fd.append('action', 'save_product');
    fd.append('shop_id', S.shop_id);
    fd.append('id', $('sf_id').value);
    fd.append('title', title);
    fd.append('currency', $('sf_cur').value);
    fd.append('price', $('sf_price').value);
    fd.append('discount_pct', $('sf_disc').value || '0');
    var endV = $('sf_end').value, endTs = endV ? Math.floor(new Date(endV).getTime() / 1000) : 0;
    if (endV && (!endTs || endTs <= Math.floor(Date.now() / 1000) + 30) && parseInt($('sf_disc').value, 10) > 0){ toast('Pick a sale end time in the future'); return; }
    fd.append('sale_ends', (parseInt($('sf_disc').value, 10) > 0 && endTs) ? String(endTs) : '');
    fd.append('off_sale', $('sf_off').checked ? '1' : '');
    fd.append('description', $('sf_desc').value);
    fd.append('in_stock', $('sf_stock').checked ? '1' : '');
    fd.append('is_featured', $('sf_feat').checked ? '1' : '');
    fd.append('cta_type', fCta);
    fd.append('cta_url', $('sf_url').value.trim());
    var cat = $('sf_cat').value;
    if (cat === '__new') fd.append('category_new', $('sf_catnew').value.trim()); else fd.append('category_id', cat);
    fd.append('remove_images', fRemoved.join(','));
    fd.append('new_count', fNew.length);

    var btn = $('sf_save'); btn.disabled = true; btn.textContent = 'Saving…';
    function reset(){ btn.disabled = false; btn.innerHTML = '<i class="ri-check-line"></i> Save product'; }
    post(fd).then(function(d){
        if (d.status !== 'ok') throw new Error(d.msg || 'Something went wrong');
        var pid = d.id;
        return uploadAll(pid, btn).then(function(){
            var f2 = new FormData(); f2.append('action', 'publish_product'); f2.append('product_id', pid);
            return post(f2);
        });
    }).then(function(r){
        if (r && r.status === 'ok'){ location.reload(); return; }
        throw new Error((r && r.msg) || 'Could not finish');
    }).catch(function(e){ err.textContent = e.message || 'Network error'; reset(); });
}

/* ══════════ DESIGN YOUR SHOP ══════════ */
var dState = {accent:'red', layout:'grid', corners:'soft', bannerFile:null, removeBanner:false, bannerUrl:'', avFile:null, removeAv:false, avCustom:false, avUrl:'', loc:'', active:1};
function hexRgb(hex){ hex = hex.replace('#', ''); return parseInt(hex.substr(0,2),16) + ',' + parseInt(hex.substr(2,2),16) + ',' + parseInt(hex.substr(4,2),16); }
function spApplyAccent(key){
    var a = S.accents[key] || S.accents.red, r = document.documentElement.style;
    r.setProperty('--ac', a[0]); r.setProperty('--ac-h', a[1]); r.setProperty('--ac-l', a[2]);
    r.setProperty('--ac-dim', 'rgba(' + hexRgb(a[0]) + ',.16)');
    r.setProperty('--ac-bd', 'rgba(' + hexRgb(a[0]) + ',.4)');
    r.setProperty('--ac-glow', 'rgba(' + hexRgb(a[0]) + ',.34)');
}
function spPickAccent(k){
    dState.accent = k; spApplyAccent(k);
    document.querySelectorAll('#dSws .sp-sw').forEach(function(s){ s.classList.toggle('active', s.dataset.k === k); });
}
function spSeg(group, v){
    dState[group] = v;
    var box = $(group === 'layout' ? 'dLayout' : 'dCorners');
    box.querySelectorAll('button').forEach(function(b){ b.classList.toggle('active', b.dataset.v === v); });
    if(group === 'layout'){ var g = $('spGrid'); if(g) g.className = 'sp-grid lay-' + v; }
    else document.body.classList.toggle('cr-square', v === 'square');
}
function paintBanner(){
    var pv = $('dBannerPrev'), has = !!dState.bannerUrl && !dState.removeBanner;
    pv.style.backgroundImage = has ? 'url("' + dState.bannerUrl + '")' : '';
    $('dBannerTxt').style.display = has ? 'none' : '';
    $('dBannerRm').style.display = has ? '' : 'none';
}
function spBannerRemove(){ dState.bannerFile = null; dState.removeBanner = true; paintBanner(); }
function paintAvatar(){
    var custom = !!dState.avFile || (dState.avCustom && !dState.removeAv);
    $('dAvPrev').src = dState.avFile ? dState.avUrl : (custom ? dState.avUrl : S.profile_pic);
    $('dAvRm').style.display = custom ? '' : 'none';
}
function spAvatarReset(){ dState.avFile = null; dState.removeAv = true; paintAvatar(); }
(function(){
    var f = $('dBannerFile');
    if (f) f.addEventListener('change', function(){
        var file = f.files[0]; f.value = ''; if(!file) return;
        if(file.size > 5 * 1024 * 1024){ toast('Banner must be under 5MB'); return; }
        dState.bannerFile = file; dState.removeBanner = false; dState.bannerUrl = URL.createObjectURL(file); paintBanner();
    });
    var a = $('dAvFile');
    if (a) a.addEventListener('change', function(){
        var file = a.files[0]; a.value = ''; if(!file) return;
        if(file.size > 5 * 1024 * 1024){ toast('Picture must be under 5MB'); return; }
        dState.avFile = file; dState.removeAv = false; dState.avUrl = URL.createObjectURL(file); paintAvatar();
    });
})();

/* location search — same OpenStreetMap lookup as group events */
function spLocSet(text){
    dState.loc = text || '';
    $('dl_text').textContent = dState.loc;
    $('dl_picked').classList.toggle('active', !!dState.loc);
}
function spLocClear(){ spLocSet(''); }
(function(){
    var input = $('dl_search'); if (!input) return;
    var sug = $('dl_sug'), spin = $('dl_spin'), timer = null, reqId = 0;
    function render(items){
        sug.innerHTML = '';
        if (!items || !items.length){ sug.innerHTML = '<div class="sp-locempty">No matching places found</div>'; sug.classList.add('active'); return; }
        items.forEach(function(pl){
            var row = h('div', 'sp-locitem'); row.innerHTML = '<i class="ri-map-pin-2-fill"></i><span></span>';
            row.querySelector('span').textContent = pl.display_name;
            row.addEventListener('click', function(){ spLocSet(pl.display_name); input.value = ''; sug.classList.remove('active'); sug.innerHTML = ''; });
            sug.appendChild(row);
        });
        sug.classList.add('active');
    }
    input.addEventListener('input', function(){
        var q = input.value.trim(); clearTimeout(timer);
        if (q.length < 3){ sug.classList.remove('active'); sug.innerHTML = ''; spin.classList.remove('active'); return; }
        spin.classList.add('active');
        timer = setTimeout(function(){
            var id = ++reqId;
            fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=8&q=' + encodeURIComponent(q), {headers:{'Accept':'application/json'}})
                .then(function(r){ return r.json(); })
                .then(function(d){ if (id !== reqId) return; spin.classList.remove('active'); render(d); })
                .catch(function(){ if (id !== reqId) return; spin.classList.remove('active'); sug.innerHTML = '<div class="sp-locempty">Search failed — try again</div>'; sug.classList.add('active'); });
        }, 450);
    });
    document.addEventListener('click', function(e){ if (!sug.contains(e.target) && e.target !== input) sug.classList.remove('active'); });
})();

/* business hours editor */
function buildHours(hours){
    var box = $('dHours'); box.innerHTML = '';
    hours.forEach(function(d, i){
        var row = h('div', 'sp-hrow');
        row.appendChild(h('span', 'd', DAYS[i].substring(0, 3)));
        var sw = h('label', 'sp-switch'); sw.innerHTML = '<input type="checkbox" class="hh-o"><span class="sp-slider"></span>';
        var chk = sw.querySelector('input'); chk.checked = !!d.o; row.appendChild(sw);
        var t = h('div', 't');
        var s = h('input', 'sp-in hh-s'); s.type = 'time'; s.value = d.s;
        var e = h('input', 'sp-in hh-e'); e.type = 'time'; e.value = d.e;
        t.appendChild(s); t.appendChild(h('em', '', '–')); t.appendChild(e);
        var cl = h('span', 'cl', 'Closed');
        row.appendChild(t); row.appendChild(cl);
        function sync(){ t.style.display = chk.checked ? '' : 'none'; cl.style.display = chk.checked ? 'none' : ''; }
        chk.addEventListener('change', sync); sync();
        box.appendChild(row);
    });
}
function collectHours(){
    var out = [];
    document.querySelectorAll('#dHours .sp-hrow').forEach(function(r){
        out.push({o: r.querySelector('.hh-o').checked ? 1 : 0, s: r.querySelector('.hh-s').value || '09:00', e: r.querySelector('.hh-e').value || '17:00'});
    });
    return out;
}


/* ── page layout & style ── */
var LK_NAMES = {header:['Header','Banner, name, buttons'], announce:['Announcement','The highlighted notice'], about:['About','Your shop description'], featured:['Featured products',''], collections:['Collections',''], favs:['Customer favourites',''], products:['Products and reviews','Filters, search and the product grid']};
var LK_HIDE = ['announce','about','featured','collections','favs'];
function lkClone(o){ return JSON.parse(JSON.stringify(o)); }
function applyLook(){
    var L = dState.look; if (!L) return;
    var cl = document.body.className.split(/\s+/).filter(function(c){ return c && c.indexOf('lk-') !== 0; });
    cl.push('lk-av-' + L.avpos, 'lk-avs-' + L.avsize, 'lk-avsh-' + L.avshape, 'lk-bh-' + L.bh, 'lk-hd-' + L.head);
    if (!+L.banner) cl.push('lk-nob');
    if (!+L.avshow) cl.push('lk-noav');
    document.body.className = cl.join(' ');
}
function lkSync(){
    ['banner','avshow','bh','avpos','avshape','avsize','head'].forEach(function(k){
        var box = $('dl_' + k); if (!box) return;
        box.querySelectorAll('button').forEach(function(b){ b.classList.toggle('active', b.dataset.v === String(dState.look[k])); });
    });
}
function spLook(k, v){ dState.look[k] = (k === 'banner' || k === 'avshow') ? +v : v; lkSync(); applyLook(); }
function renderSecList(){
    var box = $('dSecList'); box.innerHTML = '';
    var L = dState.look;
    L.order.forEach(function(key, i){
        var row = h('div', 'sp-secrow' + (L.hidden.indexOf(key) > -1 ? ' off' : ''));
        row.appendChild(h('span', 'num', String(i + 1)));
        var nm = h('div', 'nm'); nm.appendChild(document.createTextNode(LK_NAMES[key][0]));
        if (LK_NAMES[key][1]) nm.appendChild(h('small', '', LK_NAMES[key][1]));
        row.appendChild(nm);
        var up = h('button'); up.type = 'button'; up.innerHTML = '<i class="ri-arrow-up-s-line"></i>'; up.setAttribute('aria-label', 'Move up'); up.disabled = i === 0;
        up.onclick = function(){ lkMove(i, -1); };
        var dn = h('button'); dn.type = 'button'; dn.innerHTML = '<i class="ri-arrow-down-s-line"></i>'; dn.setAttribute('aria-label', 'Move down'); dn.disabled = i === L.order.length - 1;
        dn.onclick = function(){ lkMove(i, 1); };
        row.appendChild(up); row.appendChild(dn);
        if (LK_HIDE.indexOf(key) > -1){
            var on = L.hidden.indexOf(key) === -1;
            var ey = h('button'); ey.type = 'button'; ey.innerHTML = '<i class="' + (on ? 'ri-eye-line' : 'ri-eye-off-line') + '"></i>'; ey.setAttribute('aria-label', on ? 'Hide section' : 'Show section');
            ey.onclick = function(){ var j = L.hidden.indexOf(key); if (j > -1) L.hidden.splice(j, 1); else L.hidden.push(key); renderSecList(); };
            row.appendChild(ey);
        } else {
            var lc = h('button'); lc.type = 'button'; lc.disabled = true; lc.innerHTML = '<i class="ri-lock-line"></i>'; row.appendChild(lc);
        }
        box.appendChild(row);
    });
}
function lkMove(i, d){
    var o = dState.look.order, j = i + d; if (j < 0 || j >= o.length) return;
    var t = o[i]; o[i] = o[j]; o[j] = t;
    if (o.indexOf('header') > 1){ o[j] = o[i]; o[i] = t; toast('The header has to stay first or second so it never gets buried'); return; }
    renderSecList();
}
function spDesignCancel(){
    if (dState.lookOrig){ dState.look = lkClone(dState.lookOrig); applyLook(); }
    spCloseOv('spDesignOv');
}
function spDesign(){
    if(!S.own || !S.settings) return;
    var s = S.settings;
    $('d_name').value = s.shop_name; $('d_tag').value = s.tagline;
    $('d_about').value = s.about; spCount($('d_about'), 'd_ac', 400);
    $('d_ann').value = s.announcement; $('d_web').value = s.website; $('d_wa').value = s.whatsapp;
    $('d_favs').checked = !!(+s.show_favs);
    var pl = s.policies || {}; $('d_pol_ship').value = pl.shipping || ''; $('d_pol_ret').value = pl.returns || ''; $('d_pol_pay').value = pl.payment || '';
    faqBuild(s.faq || []);
    $('d_cur').value = s.currency;
    $('dl_search').value = ''; $('dl_sug').classList.remove('active'); spLocSet(s.location);
    $('d_hon').checked = !!(+s.hours_enabled); $('dHoursBox').style.display = (+s.hours_enabled) ? 'block' : 'none';
    $('d_tz').value = s.tz; buildHours(s.hours);
    dState.bannerFile = null; dState.removeBanner = false; dState.bannerUrl = s.banner_url || ''; paintBanner();
    dState.avFile = null; dState.removeAv = false; dState.avCustom = !!s.avatar_custom; dState.avUrl = s.avatar_url || ''; paintAvatar();
    dState.active = +s.is_active;
    var ab = $('dActiveBtn');
    ab.innerHTML = dState.active ? '<i class="ri-eye-off-line"></i> Deactivate shop' : '<i class="ri-eye-line"></i> Reactivate shop';
    spPickAccent(s.accent); spSeg('layout', s.layout); spSeg('corners', s.corners);
    dState.look = lkClone(s.look); dState.lookOrig = lkClone(s.look); lkSync(); renderSecList(); applyLook();
    $('d_err').textContent = '';
    var sv = $('d_save'); sv.disabled = false; sv.innerHTML = '<i class="ri-check-line"></i> Save design';
    spOpenOv('spDesignOv');
}
function spDesignSubmit(){
    var err = $('d_err'); err.textContent = '';
    var web = $('d_web').value.trim(), wa = $('d_wa').value.replace(/\D/g, '');
    if (web && !/^https?:\/\//i.test(web)){ err.textContent = 'Website must start with http:// or https://'; return; }
    if (wa && wa.length < 7){ err.textContent = 'Enter your WhatsApp number with country code'; return; }
    var fd = new FormData();
    fd.append('action', 'save_shop'); fd.append('shop_id', S.shop_id);
    fd.append('shop_name', $('d_name').value.trim());
    fd.append('tagline', $('d_tag').value.trim());
    fd.append('location', dState.loc);
    fd.append('about', $('d_about').value.trim());
    fd.append('announcement', $('d_ann').value.trim());
    fd.append('currency', $('d_cur').value);
    var faqs = faqCollect();
    for (var fi = 0; fi < faqs.length; fi++){ if ((faqs[fi].q && !faqs[fi].a) || (!faqs[fi].q && faqs[fi].a)){ err.textContent = 'Each FAQ needs both a question and an answer'; return; } }
    fd.append('policies', JSON.stringify({shipping: $('d_pol_ship').value.trim(), returns: $('d_pol_ret').value.trim(), payment: $('d_pol_pay').value.trim()}));
    fd.append('faq', JSON.stringify(faqs.filter(function(x){ return x.q && x.a; })));
    fd.append('look', JSON.stringify(dState.look));
    fd.append('accent', dState.accent); fd.append('layout', dState.layout); fd.append('corners', dState.corners);
    fd.append('show_favs', $('d_favs').checked ? '1' : '');
    fd.append('hours_enabled', $('d_hon').checked ? '1' : '');
    fd.append('hours', JSON.stringify(collectHours())); fd.append('tz', $('d_tz').value);
    fd.append('website', web); fd.append('whatsapp', wa);
    if (dState.removeBanner) fd.append('remove_banner', '1');
    if (dState.bannerFile) fd.append('banner', dState.bannerFile);
    if (dState.removeAv) fd.append('remove_avatar', '1');
    if (dState.avFile) fd.append('avatar', dState.avFile);

    var btn = $('d_save'); btn.disabled = true; btn.textContent = 'Saving…';
    function reset(){ btn.disabled = false; btn.innerHTML = '<i class="ri-check-line"></i> Save design'; }
    post(fd).then(function(d){
        if (d.status === 'ok'){ location.reload(); return; }
        err.textContent = d.msg || 'Something went wrong'; reset();
    }).catch(function(){ err.textContent = 'Network error'; reset(); });
}

/* shops: new / deactivate / delete */
function spNewShop(){
    var fd = new FormData(); fd.append('action', 'create_shop');
    post(fd).then(function(d){
        if (d.status === 'ok') location.href = 'profile_shop.php?user_id=' + S.owner_id + '&shop=' + d.id + '&design=1';
        else toast(d.msg || 'Could not create shop');
    });
}
function spSetActive(on){
    var fd = new FormData(); fd.append('action', 'set_active'); fd.append('shop_id', S.shop_id); fd.append('active', on ? '1' : '');
    post(fd).then(function(d){ if (d.status === 'ok') location.reload(); else toast(d.msg || 'Could not update'); });
}
function spToggleActive(){
    if (dState.active){
        spConfirm('Deactivate this shop?', 'Nobody but you will be able to see it. Your products, collections and reviews stay safe, and you can turn it back on any time.', 'Deactivate', function(){ spSetActive(0); });
    } else spSetActive(1);
}
function spDeleteShop(){
    spConfirm('Delete this shop?', 'This permanently removes the shop with all its products, photos, videos, collections and reviews. This can’t be undone.', 'Delete forever', function(){
        var fd = new FormData(); fd.append('action', 'delete_shop'); fd.append('shop_id', S.shop_id);
        post(fd).then(function(d){ if (d.status === 'ok') location.href = 'profile_shop.php?user_id=' + S.owner_id; else toast(d.msg || 'Could not delete'); });
    });
}

/* scroll arrows on every sideways-scrolling row (laptops only; CSS hides them on phones) */
function spArrows(){
    document.querySelectorAll('.sp-hs, .sp-chips, .sp-shops, .sp-car').forEach(function(sc){
        if (sc.parentNode.classList.contains('sp-scr')) return;
        var w = h('div', 'sp-scr'); sc.parentNode.insertBefore(w, sc); w.appendChild(sc);
        var L = h('button', 'sp-arr l'), R = h('button', 'sp-arr r');
        L.type = R.type = 'button';
        L.setAttribute('aria-label', 'Scroll left'); R.setAttribute('aria-label', 'Scroll right');
        L.innerHTML = '<i class="ri-arrow-left-s-line"></i>'; R.innerHTML = '<i class="ri-arrow-right-s-line"></i>';
        w.appendChild(L); w.appendChild(R);
        function upd(){
            var max = sc.scrollWidth - sc.clientWidth;
            L.classList.toggle('show', sc.scrollLeft > 4);
            R.classList.toggle('show', sc.scrollLeft < max - 4);
        }
        L.onclick = function(e){ e.stopPropagation(); sc.scrollBy({left: -sc.clientWidth * .8, behavior:'smooth'}); };
        R.onclick = function(e){ e.stopPropagation(); sc.scrollBy({left: sc.clientWidth * .8, behavior:'smooth'}); };
        sc.addEventListener('scroll', upd, {passive:true});
        window.addEventListener('resize', upd);
        window.addEventListener('load', upd);
        upd(); setTimeout(upd, 400); setTimeout(upd, 1500);
    });
}

/* ── startup: about text, reviews, arrows, skeleton, deep links ── */
(function(){
    try { var a = $('spAbout'); if (a && S.about) spMore(a, S.about, 140); } catch (e) {}
    try { if ($('spRevBox')) refreshReviews(); } catch (e) {}
    try { spArrows(); } catch (e) {}
})();
window.addEventListener('load', function(){
    var q = new URLSearchParams(location.search);
    var pid = parseInt(q.get('p'), 10);
    if (pid && P[pid]) setTimeout(function(){ spOpen(pid); }, 420);
    if (q.get('design') === '1' && S.own) setTimeout(function(){ spDesign(); }, 450);
});
</script>
</body>
</html>