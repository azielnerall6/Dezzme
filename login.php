<?php
/* ══════════════════════════════════════════════════════════════
   SESSION: hardened cookie (HttpOnly + SameSite=Lax + Secure on HTTPS)
══════════════════════════════════════════════════════════════ */
$IS_HTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
include 'config.php';

/* ══════════════════════════════════════════════════════════════
   RESPONSE HEADERS
══════════════════════════════════════════════════════════════ */
$nonce = base64_encode(random_bytes(16));

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($IS_HTTPS) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'nonce-$nonce'; " .
    "style-src 'nonce-$nonce' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
    "font-src https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
    "worker-src 'self'; " .
    "object-src 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self'; " .
    "frame-ancestors 'none'"
);

/* ══════════════════════════════════════════════════════════════
   LOGIN CSRF PROTECTION
   1. POST only
   2. Sec-Fetch-Site (when sent) must be same-origin / none
   3. Origin / Referer (when sent) must match this host + port; "null" refused
   4. Secret token in the form, compared with hash_equals()
   5. Token fresh (30 min) and bound to this browser's User-Agent
   6. Second secret in a SameSite=Strict HttpOnly cookie (double-submit)
   7. Single-use: the token is destroyed on every check, pass or fail
══════════════════════════════════════════════════════════════ */
const LG_TOKEN_TTL = 1800;
const LG_COOKIE    = 'dz_lg';

function lg_ua_hash(): string {
    return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}
function lg_cookie_set(string $val, int $expires): void {
    global $IS_HTTPS;
    setcookie(LG_COOKIE, $val, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}
function lg_issue(): void {
    $_SESSION['lg_csrf'] = [
        'token'  => bin2hex(random_bytes(32)),
        'cookie' => bin2hex(random_bytes(32)),
        'iat'    => time(),
        'ua'     => lg_ua_hash(),
    ];
    lg_cookie_set($_SESSION['lg_csrf']['cookie'], 0);
}
function lg_token_usable(): bool {
    $s = $_SESSION['lg_csrf'] ?? null;
    if (!is_array($s) || empty($s['token']) || empty($s['cookie']) || empty($s['iat'])) return false;
    if ((time() - (int)$s['iat']) > LG_TOKEN_TTL) return false;
    $c = $_COOKIE[LG_COOKIE] ?? '';
    return is_string($c) && hash_equals($s['cookie'], $c);
}
function lg_same_origin(): bool {
    $sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($sfs !== '' && !in_array($sfs, ['same-origin', 'none'], true)) return false;

    $hostHdr = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $myHost  = (string)parse_url('//' . $hostHdr, PHP_URL_HOST);
    $myPort  = parse_url('//' . $hostHdr, PHP_URL_PORT);

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (!empty($_SERVER[$h])) {
            $raw = (string)$_SERVER[$h];
            if (strtolower($raw) === 'null') return false;
            $srcHost = strtolower((string)parse_url($raw, PHP_URL_HOST));
            $srcPort = parse_url($raw, PHP_URL_PORT);
            if ($srcHost === '' || $srcHost !== $myHost) return false;
            if ($srcPort !== null && $myPort !== null && (int)$srcPort !== (int)$myPort) return false;
            return true;            // first header present decides
        }
    }
    return true;   // some browsers/privacy tools send neither; token layers still apply
}
function lg_verify(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (!lg_same_origin()) return false;

    $s    = $_SESSION['lg_csrf'] ?? null;
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    unset($_SESSION['lg_csrf']);   // single-use, whatever the outcome

    if (!is_array($s) || !is_string($sent) || $sent === '') return false;
    if (empty($s['token']) || empty($s['cookie']) || empty($s['iat'])) return false;
    if (!hash_equals($s['token'], $sent)) return false;
    if ((time() - (int)$s['iat']) > LG_TOKEN_TTL) return false;
    if (!hash_equals((string)($s['ua'] ?? ''), lg_ua_hash())) return false;

    $c = $_COOKIE[LG_COOKIE] ?? '';
    return is_string($c) && $c !== '' && hash_equals($s['cookie'], $c);
}

/* ══════════════════════════════════════════════════════════════
   BRUTE-FORCE THROTTLE (per IP and per account, 15 minutes, stored in the DB)
══════════════════════════════════════════════════════════════ */
const LG_WINDOW       = 900;   // seconds
const LG_MAX_PER_IDENT = 5;
const LG_MAX_PER_IP    = 20;

function lg_db_ready($conn): bool {
    try {
        return (bool)$conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            ident_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY k_ip (ip, created_at),
            KEY k_ident (ident_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { return false; }
}
function lg_wait($conn, string $ip, string $ih): int {
    $wait = 0;
    try {
        foreach ([['ip', $ip, LG_MAX_PER_IP], ['ident_hash', $ih, LG_MAX_PER_IDENT]] as [$col, $val, $max]) {
            $st = $conn->prepare("SELECT COUNT(*), TIMESTAMPDIFF(SECOND, MIN(created_at), NOW())
                                  FROM login_attempts
                                  WHERE $col = ? AND created_at > (NOW() - INTERVAL " . LG_WINDOW . " SECOND)");
            $st->bind_param('s', $val);
            $st->execute();
            $st->bind_result($cnt, $age);
            $st->fetch();
            $st->close();
            if ((int)$cnt >= $max) $wait = max($wait, LG_WINDOW - (int)$age);
        }
    } catch (Throwable $e) {}
    return max(0, $wait);
}
function lg_record_fail($conn, string $ip, string $ih): void {
    try {
        $st = $conn->prepare("INSERT INTO login_attempts (ip, ident_hash, created_at) VALUES (?, ?, NOW())");
        $st->bind_param('ss', $ip, $ih);
        $st->execute();
        $st->close();
        if (random_int(1, 50) === 1) $conn->query("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    } catch (Throwable $e) {}
}
function lg_clear($conn, string $ih): void {
    try {
        $st = $conn->prepare("DELETE FROM login_attempts WHERE ident_hash = ?");
        $st->bind_param('s', $ih);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {}
}

/* ══════════════════════════════════════════════════════════════
   HANDLE LOGIN
══════════════════════════════════════════════════════════════ */
const LG_DUMMY_HASH = '$2y$10$abcdefghijklmnopqrstuu0123456789ABCDEFGHIJKLMNOPQRSTU';

$message  = '';
$prefill  = '';
$notice   = '';   // friendly note (not an error), e.g. after deactivating or deleting an account

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['account'])) {
    if ($_GET['account'] === 'deactivated') $notice = 'Your account is deactivated. Log in to turn it back on.';
    elseif ($_GET['account'] === 'deleted') $notice = 'Your account is scheduled for deletion. Log in within 30 days to cancel it.';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['login'])) {

    if (!lg_verify()) {
        http_response_code(403);
        $message = 'This page expired or could not be verified. Please try again.';
    } else {
        $identifier = trim((string)($_POST['username'] ?? ''));
        $password   = (string)($_POST['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $message = 'Please fill in all fields.';
        } elseif (strlen($identifier) > 254 || strlen($password) > 1024) {
            $message = 'Incorrect login details. Please check and try again.';
        } else {
            $prefill = $identifier;
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $ih = hash('sha256', strtolower($identifier));

            lg_db_ready($conn);
            $wait = lg_wait($conn, $ip, $ih);

            if ($wait > 0) {
                http_response_code(429);
                $mins = max(1, (int)ceil($wait / 60));
                $message = "Too many attempts. Please try again in $mins minute" . ($mins === 1 ? '' : 's') . '.';
            } else {
                $uid = 0;
                $stmt = $conn->prepare("SELECT id, password FROM users WHERE username=? OR email=? OR phone=?");
                $stmt->bind_param('sss', $identifier, $identifier, $identifier);
                $stmt->execute();
                $res = $stmt->get_result();

                if ($res && $res->num_rows) {
                    while ($row = $res->fetch_assoc()) {
                        if (password_verify($password, (string)$row['password'])) {
                            $uid = (int)$row['id'];
                            if (password_needs_rehash((string)$row['password'], PASSWORD_DEFAULT)) {
                                $nh = password_hash($password, PASSWORD_DEFAULT);
                                $up = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                                $up->bind_param('si', $nh, $uid);
                                $up->execute();
                                $up->close();
                            }
                            break;
                        }
                    }
                } else {
                    password_verify($password, LG_DUMMY_HASH);   // same timing whether or not the account exists
                }
                $stmt->close();

                // An account whose 30-day deletion period has already ended is gone: it cannot log in or be restored.
                if ($uid > 0) {
                    try {
                        $dq = $conn->prepare("SELECT delete_after FROM users WHERE id=? LIMIT 1");
                        $dq->bind_param('i', $uid);
                        $dq->execute();
                        $dq->bind_result($delAfter);
                        $dq->fetch();
                        $dq->close();
                        if (!empty($delAfter) && strtotime((string)$delAfter) <= time()) $uid = 0;
                    } catch (Throwable $e) { /* delete_after column not created yet: nothing to check */ }
                }

                if ($uid > 0) {
                    session_regenerate_id(true);
                    unset($_SESSION['lg_csrf']);
                    lg_cookie_set('', time() - 42000);
                    lg_clear($conn, $ih);

                    // Logging in turns a deactivated account back on and cancels a pending deletion
                    try {
                        $re = $conn->prepare("UPDATE users SET deactivated_at=NULL, delete_after=NULL WHERE id=?");
                        $re->bind_param('i', $uid); $re->execute(); $re->close();
                    } catch (Throwable $e) { /* columns not created yet: nothing to reactivate */ }

                    $_SESSION['user_id']  = $uid;
                    $_SESSION['dz_login_at'] = time() . '-' . bin2hex(random_bytes(4));   // lets Zeems show its swipe tutorial once after every login
                    $_SESSION['accounts'] = [$uid];

                    $ls = $conn->prepare("SELECT linked_user_id FROM linked_accounts WHERE primary_user_id = ?");
                    $ls->bind_param('i', $uid);
                    $ls->execute();
                    $lr = $ls->get_result();
                    while ($lr && ($r = $lr->fetch_assoc())) $_SESSION['accounts'][] = (int)$r['linked_user_id'];
                    $ls->close();
                    $_SESSION['accounts'] = array_values(array_unique($_SESSION['accounts']));

                    // A suspended account lands on its own profile, which shows the suspended / appeal screen
                    $dest = 'home.php';
                    try {
                        $sq = $conn->prepare("SELECT user_rank FROM users WHERE id=? LIMIT 1");
                        $sq->bind_param('i', $uid); $sq->execute(); $sq->bind_result($rk); $sq->fetch(); $sq->close();
                        if (strtolower((string)$rk) === 'suspended') $dest = 'profile.php';
                    } catch (Throwable $e) {}
                    header('Location: ' . $dest, true, 303);
                    exit();
                }

                lg_record_fail($conn, $ip, $ih);
                usleep(random_int(250000, 450000));
                $message = 'Incorrect login details. Please check and try again.';
            }
        }
    }
}

if (!lg_token_usable()) lg_issue();
$csrf_token = $_SESSION['lg_csrf']['token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title>Login | Dezzme</title>

<!-- PWA -->
<link rel="manifest" href="/Peura/manifest.json">
<meta name="theme-color" content="#070924">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Peura">
<link rel="apple-touch-icon" href="/Peura/Peura.jpg">

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Bebas+Neue&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style nonce="<?php echo $nonce; ?>">
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; touch-action:manipulation; }
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
    --red:        #FF2D2D;
    --red-hover:  #ff1a1a;
    --red-text:   #ff8a8a;
    --brand-red:  #E03C3C;
    --base:       #070924;
    --surface:    rgba(255,255,255,0.05);
    --line:       rgba(255,255,255,0.09);
    --line2:      rgba(255,255,255,0.14);
    --text:       #ffffff;
    --muted:      rgba(255,255,255,0.45);
    --sub:        rgba(255,255,255,0.62);
    --pad:        clamp(14px, 4vw, 24px);
    --ctl-h:      clamp(34px, 9vw, 42px);
    --r:          5px;
}

html{ scroll-behavior:smooth; background:var(--base); -webkit-text-size-adjust:100%; }

/* RESPONSIVE view: no scrollbars anywhere, scrolling still works */
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}

body{
    background:var(--base);
    color:var(--text);
    min-height:100vh; min-height:100dvh;
    display:flex; flex-direction:column; align-items:center;
    padding:max(26px, env(safe-area-inset-top)) 16px max(32px, env(safe-area-inset-bottom));
    overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}

/* ── Wordmark: centered, red, scrolls away with the page ── */
.brand{
    display:block; width:100%; text-align:center;
    font-family:'Bebas Neue', sans-serif;
    font-size:clamp(24px, 6.5vw, 32px); letter-spacing:clamp(5px, 1.8vw, 8px);
    padding-left:clamp(5px, 1.8vw, 8px);
    color:var(--brand-red); line-height:1;
    margin-bottom:clamp(22px, 6vw, 34px);
    transition:opacity .25s;
}
.brand:hover{ opacity:.75; }

/* ── Cards ── */
.page-wrapper{
    width:100%; max-width:860px;
    margin:auto 0;
    display:flex; gap:clamp(12px, 3vw, 20px);
    align-items:stretch; justify-content:center;
}
.panel{
    position:relative;
    flex:1; min-width:0;
    background:var(--base);
    border:1px solid var(--line2);
    border-radius:8px;
    padding:clamp(24px, 5vw, 34px) var(--pad);
    display:flex; flex-direction:column;
    box-shadow:0 2px 10px rgba(0,0,0,.15);
    animation:pIn .4s cubic-bezier(.22,1,.36,1) both;
}
.panel:nth-child(2){ animation-delay:.06s; }
@keyframes pIn{ from{ opacity:0; transform:translateY(14px); } to{ opacity:1; transform:none; } }

.panel-label{
    font-size:.6rem; font-weight:700; text-transform:uppercase;
    letter-spacing:1.5px; color:var(--muted); text-align:center; margin-bottom:6px;
}
.panel-title{
    font-size:clamp(1rem, 4.4vw, 1.25rem); font-weight:700;
    letter-spacing:-.1px; text-align:center; margin-bottom:6px; color:#fff;
}
.panel-sub{
    font-size:clamp(.64rem, 2.2vw, .74rem); color:var(--sub);
    line-height:1.65; text-align:center; margin:0 auto 20px; max-width:300px;
}

/* ── "Last Used" tag (shared) ── */
.last-used-tag{
    display:none; flex-shrink:0;
    font-size:.56rem; font-weight:700; letter-spacing:.6px; text-transform:uppercase; line-height:1;
    color:var(--red); background:rgba(255,45,45,.14);
    padding:4px 7px; border-radius:4px; white-space:nowrap;
}

/* ── Social buttons ── */
.social-login{ display:flex; flex-direction:column; gap:9px; width:100%; }
.social-pill{
    position:relative; display:flex; align-items:center; gap:11px;
    height:var(--ctl-h); padding:0 14px;
    border-radius:var(--r);
    background:rgba(255,255,255,0.08);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:1px solid transparent;
    color:#fff; font-size:clamp(.68rem, 2.3vw, .78rem); font-weight:600;
    transition:background .2s, border-color .2s, transform .15s;
}
.social-pill:hover{ background:rgba(255,255,255,0.16); }
.social-pill:active{ transform:scale(.98); }
.social-pill svg{ width:17px; height:17px; flex-shrink:0; }
.pill-label{ flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.social-pill.last-used{ border-color:rgba(255,45,45,.35); }
.social-pill.last-used .last-used-tag{ display:inline-block; }

/* Last used = password login: tag sits in the top-right corner of the Login card */
.panel.last-used .last-used-tag.corner{
    display:inline-block; position:absolute; top:12px; right:12px;
}

/* ── Form ── */
.form-group{ position:relative; width:100%; margin-bottom:11px; }
.form-group input{
    width:100%; height:var(--ctl-h);
    background:var(--surface); border:1px solid var(--line2);
    border-radius:var(--r);
    padding:0 14px; font-size:clamp(.7rem, 2.4vw, .8rem); color:#fff; outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.form-group input::placeholder{ color:var(--muted); }
.form-group input:focus{
    border-color:rgba(255,45,45,.7); background:rgba(255,255,255,0.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}
.password-container input{ padding-right:44px; }
.toggle-pass{
    position:absolute; top:0; right:0; width:42px; height:var(--ctl-h);
    display:flex; align-items:center; justify-content:center;
    background:none; border:none; color:var(--muted); font-size:1.05rem;
    transition:color .2s;
}
.toggle-pass:hover{ color:#fff; }

.message{
    width:100%; margin-bottom:14px; padding:9px 12px;
    border-radius:4px; text-align:center;
    background:rgba(255,45,45,.1); border:1px solid rgba(255,45,45,.3);
    color:var(--red-text); font-size:.7rem; line-height:1.55;
}
.message.info{
    background:rgba(255,255,255,.06); border-color:var(--line2);
    color:var(--sub);
}

/* ── Login button ── */
.btn-login{
    display:flex; align-items:center; justify-content:center;
    width:100%; height:var(--ctl-h); margin-top:4px; margin-bottom:18px;
    border:none; border-radius:4px;
    background:var(--red); color:#000;
    font-size:clamp(.78rem, 2.7vw, .9rem); font-weight:700; line-height:1; letter-spacing:.2px;
    transition:background .2s, transform .15s, opacity .2s;
}
.btn-login:hover{ background:var(--red-hover); }
.btn-login:active{ transform:scale(.97); }
.btn-login:disabled{ cursor:default; opacity:.85; }

.links{ text-align:center; font-size:.7rem; color:var(--muted); line-height:2.1; width:100%; }
.links a{ color:var(--red-text); font-weight:600; margin:0 6px; transition:color .2s; }
.links a:hover{ color:#fff; }
.links .sep{ color:var(--line2); }

/* ── loading bars ── */
.spinner-icon{ display:inline-flex; align-items:center; justify-content:center; gap:3px; height:16px; }
.spinner-icon span{ display:block; width:3px; background:currentColor; border-radius:2px; animation:btn-bar 1s ease-in-out infinite; }
.spinner-icon span:nth-child(2){ animation-delay:.15s; }
.spinner-icon span:nth-child(3){ animation-delay:.3s; }
.spinner-icon span:nth-child(4){ animation-delay:.45s; }
@keyframes btn-bar{ 0%,100%{ height:4px; opacity:.4; } 50%{ height:10px; opacity:1; } }

/* ══════════════════════════════════════
   RESPONSIVE — same compact, fluid feel as the profile page
══════════════════════════════════════ */
@media (max-width:768px){
    body{ padding:max(18px, env(safe-area-inset-top)) 12px max(22px, env(safe-area-inset-bottom)); }
    .brand{ font-size:clamp(22px, 6.2vw, 28px); margin-bottom:18px; }
    .page-wrapper{ flex-direction:column; max-width:440px; margin:0 auto; gap:12px; }
    .panel{ padding:20px 14px 18px; }
    .panel-label{ font-size:.56rem; margin-bottom:5px; }
    .panel-title{ font-size:1rem; margin-bottom:5px; }
    .panel-sub{ font-size:.66rem; margin-bottom:16px; }
    .social-login{ gap:8px; }
    .social-pill{ gap:10px; padding:0 12px; }
    .social-pill svg{ width:16px; height:16px; }
    .form-group{ margin-bottom:9px; }
    .btn-login{ margin-bottom:14px; }
    .links{ font-size:.66rem; line-height:2; }
}
@media (max-width:490px){
    .panel{ padding:18px 12px 16px; border-radius:8px; }
    .panel-sub{ margin-bottom:14px; }
    .last-used-tag{ font-size:.52rem; padding:3px 6px; }
    .panel.last-used .last-used-tag.corner{ top:10px; right:10px; }
    .message{ font-size:.66rem; padding:8px 10px; }
}
@media (max-width:380px){
    body{ padding-left:10px; padding-right:10px; }
    .social-pill{ gap:9px; padding:0 10px; }
    .panel{ padding:16px 11px 14px; }
}
@media (max-width:330px){
    .panel-title{ font-size:.92rem; }
    .panel-sub{ font-size:.62rem; }
    .links a{ margin:0 4px; }
}
@media (max-height:520px) and (orientation:landscape){
    body{ padding-top:14px; padding-bottom:14px; }
    .brand{ margin-bottom:12px; }
}
/* ══ RESPONSIVE VIEW (768px and below): everything 10% bigger ══
   html font-size 110% makes every rem size (text, tags, messages) 10% larger.
   The px sizes below (inputs, buttons, wordmark, icons, card padding) are raised by hand to match. */
@media (max-width:768px){
    html{ font-size:110%; }
    :root{ --ctl-h:clamp(37px, 9.9vw, 46px); --pad:clamp(15px, 4.4vw, 26px); }
    .brand{ font-size:clamp(24px, 6.8vw, 31px); }
    .page-wrapper{ max-width:484px; }
    .panel{ padding:22px 15px 20px; }
    .social-pill svg{ width:17.6px; height:17.6px; }
    .form-group input{ padding:0 15px; }
    .password-container input{ padding-right:48px; }
    .toggle-pass{ width:46px; }
    .spinner-icon{ height:17.6px; }
}
@media (max-width:490px){
    .panel{ padding:20px 13px 18px; }
    .panel.last-used .last-used-tag.corner{ top:11px; right:11px; }
}
@media (max-width:380px){
    .panel{ padding:18px 12px 15px; }
}
@media (prefers-reduced-motion: reduce){
    .panel{ animation:none; }
}
</style>
</head>
<body>

<a href="index.php" class="brand">Dezzme</a>

<div class="page-wrapper">

    <!-- ── FIRST CARD: login ── -->
    <section class="panel" id="loginPanel">
        <span class="last-used-tag corner">Last Used</span>

        <div class="panel-label">Welcome back</div>
        <h2 class="panel-title">Login</h2>
        <p class="panel-sub">Enter your username, email or phone number to continue.</p>

        <?php if ($message): ?>
            <div class="message" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php elseif ($notice): ?>
            <div class="message info" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm" autocomplete="on" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">
                <input type="text" name="username" placeholder="Username, email or phone"
                       value="<?php echo htmlspecialchars($prefill, ENT_QUOTES, 'UTF-8'); ?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="254" required>
            </div>

            <div class="form-group password-container">
                <input type="password" id="password" name="password" placeholder="Password"
                       autocomplete="current-password" maxlength="1024" required>
                <button type="button" class="toggle-pass" id="togglePass" aria-label="Show password">
                    <i class="ri-eye-line" id="eyeIcon"></i>
                </button>
            </div>

            <button type="submit" name="login" value="1" class="btn-login" id="loginBtn">Login</button>
        </form>

        <div class="links">
            <p>
                <a href="register.php">Register</a><span class="sep">|</span><a href="password.php">Forgot Password?</a>
            </p>
            <p>
                <a href="dezzme_policy.php">Privacy Policy</a>
            </p>
        </div>
    </section>

    <!-- ── SECOND CARD: social sign-in ── -->
    <section class="panel">
        <div class="panel-label">Quick access</div>
        <h2 class="panel-title">Sign In With</h2>
        <p class="panel-sub">Use an account you already have. No new password to remember.</p>

        <div class="social-login">

            <a href="google_callback.php" class="social-pill" data-provider="google" rel="noopener">
                <svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path fill="#EA4335" d="M24 9.5c3.15 0 5.64 1.08 7.75 2.85l5.77-5.77C33.91 3.39 29.27 1.5 24 1.5 14.95 1.5 7.28 7.07 4.07 14.9l6.73 5.23C12.4 13.59 17.73 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.1 24.55c0-1.64-.15-3.22-.42-4.75H24v9h12.42c-.54 2.9-2.18 5.36-4.64 7.02l7.19 5.59C43.18 37.13 46.1 31.3 46.1 24.55z"/>
                    <path fill="#FBBC05" d="M10.8 28.87A14.5 14.5 0 0 1 9.5 24c0-1.69.29-3.32.8-4.87L3.57 13.9A22.44 22.44 0 0 0 1.5 24c0 3.61.87 7.02 2.4 10.03l6.9-5.16z"/>
                    <path fill="#34A853" d="M24 46.5c5.27 0 9.7-1.75 12.93-4.74l-7.19-5.59c-1.8 1.21-4.1 1.93-5.74 1.93-6.27 0-11.6-4.09-13.2-9.63l-6.9 5.16C7.28 40.93 14.95 46.5 24 46.5z"/>
                </svg>
                <span class="pill-label">Sign in with Google</span>
                <span class="last-used-tag">Last Used</span>
            </a>

            <a href="discord_callback.php" class="social-pill" data-provider="discord" rel="noopener">
                <svg viewBox="0 0 127.14 96.36" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path fill="#5865F2" d="M107.7,8.07A105.15,105.15,0,0,0,81.47,0a72.06,72.06,0,0,0-3.36,6.83A97.68,97.68,0,0,0,49,6.83,72.37,72.37,0,0,0,45.64,0,105.89,105.89,0,0,0,19.39,8.09C2.79,32.65-1.71,56.6.54,80.21h0A105.73,105.73,0,0,0,32.71,96.36,77.7,77.7,0,0,0,39.6,85.25a68.42,68.42,0,0,1-10.85-5.18c.91-.66,1.8-1.34,2.66-2a75.57,75.57,0,0,0,64.32,0c.87.71,1.76,1.39,2.66,2a68.68,68.68,0,0,1-10.87,5.19,77,77,0,0,0,6.89,11.1A105.25,105.25,0,0,0,126.6,80.22h0C129.24,52.84,122.09,29.11,107.7,8.07ZM42.45,65.69C36.18,65.69,31,60,31,53s5-12.74,11.43-12.74S54,46,53.89,53,48.84,65.69,42.45,65.69Zm42.24,0C78.41,65.69,73.25,60,73.25,53s5-12.74,11.44-12.74S96.23,46,96.12,53,91.08,65.69,84.69,65.69Z"/>
                </svg>
                <span class="pill-label">Sign in with Discord</span>
                <span class="last-used-tag">Last Used</span>
            </a>

            <a href="spotify_callback.php" class="social-pill" data-provider="spotify" rel="noopener">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path fill="#1DB954" d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                </svg>
                <span class="pill-label">Sign in with Spotify</span>
                <span class="last-used-tag">Last Used</span>
            </a>

            <a href="github_callback.php" class="social-pill" data-provider="github" rel="noopener">
                <svg viewBox="0 0 98 96" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path fill="#fff" d="M48.854 0C21.839 0 0 22 0 49.217c0 21.756 13.993 40.172 33.405 46.69 2.427.49 3.316-1.059 3.316-2.362 0-1.141-.08-5.052-.08-9.127-13.59 2.934-16.42-5.867-16.42-5.867-2.184-5.704-5.42-7.17-5.42-7.17-4.448-3.015.324-3.015.324-3.015 4.934.326 7.523 5.052 7.523 5.052 4.367 7.496 11.404 5.378 14.235 4.074.404-3.178 1.699-5.378 3.074-6.6-10.839-1.141-22.243-5.378-22.243-24.283 0-5.378 1.94-9.778 5.014-13.2-.485-1.222-2.184-6.275.486-13.038 0 0 4.125-1.304 13.426 5.052a46.97 46.97 0 0 1 12.214-1.63c4.125 0 8.33.571 12.213 1.63 9.302-6.356 13.427-5.052 13.427-5.052 2.67 6.763.97 11.816.485 13.038 3.155 3.422 5.015 7.822 5.015 13.2 0 18.905-11.404 23.06-22.324 24.283 1.78 1.548 3.316 4.481 3.316 9.126 0 6.6-.08 11.897-.08 13.526 0 1.304.89 2.853 3.316 2.364 19.412-6.52 33.405-24.935 33.405-46.691C97.707 22 75.788 0 48.854 0z"/>
                </svg>
                <span class="pill-label">Sign in with GitHub</span>
                <span class="last-used-tag">Last Used</span>
            </a>

        </div>
    </section>

</div>

<script nonce="<?php echo $nonce; ?>">
// ── PWA service worker ──
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function(){
        navigator.serviceWorker.register('/Peura/service-worker.js')
            .then(function(){ console.log('Peura SW registered'); })
            .catch(function(err){ console.log('SW error:', err); });
    });
}

// ── Show / hide password ──
(function(){
    var btn = document.getElementById('togglePass');
    var inp = document.getElementById('password');
    var ico = document.getElementById('eyeIcon');
    if (!btn || !inp) return;
    btn.addEventListener('click', function(){
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        ico.className = show ? 'ri-eye-off-line' : 'ri-eye-line';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
})();

// ── Loading bars on submit (also blocks double submits) ──
(function(){
    var f = document.getElementById('loginForm');
    if (!f) return;
    var sent = false;
    f.addEventListener('submit', function(e){
        if (sent){ e.preventDefault(); return; }
        if (!f.checkValidity()) return;
        sent = true;
        try { localStorage.setItem('dezzme_last_provider', 'password'); } catch(err){}
        var b = document.getElementById('loginBtn');
        b.style.minWidth = b.offsetWidth + 'px';
        setTimeout(function(){
            b.disabled = true;
            b.innerHTML = '<span class="spinner-icon"><span></span><span></span><span></span><span></span></span>';
        }, 0);
    });
})();

// ── Back/forward cache: never show a stale form with a burned token ──
window.addEventListener('pageshow', function(e){
    if (e.persisted) location.reload();
});

// ── Last used sign-in method (social provider or password) ──
(function(){
    var KEY = 'dezzme_last_provider';

    // show the tag on whichever method was used last
    try {
        var last = localStorage.getItem(KEY);
        if (last === 'password') {
            document.getElementById('loginPanel').classList.add('last-used');
        } else if (last) {
            var pill = document.querySelector('.social-pill[data-provider="' + last + '"]');
            if (pill) pill.classList.add('last-used');
        }
    } catch(e){}

    // remember a social provider the moment it is tapped
    document.querySelectorAll('.social-pill').forEach(function(pill){
        pill.addEventListener('click', function(){
            try { localStorage.setItem(KEY, pill.dataset.provider); } catch(e){}
        });
    });
})();
</script>
</body>
</html>