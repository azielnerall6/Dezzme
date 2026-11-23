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

/* ══════════════════════════════════════════════════════════════
   RESPONSE HEADERS (same policy as the login page)
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
   CSRF PROTECTION (logout)
   1. POST method only
   2. Sec-Fetch-Site (when sent) must be same-origin
   3. Origin / Referer (when sent) must match this host; "null" is refused
   4. Secret token in the form, compared with hash_equals()
   5. Token fresh (30 min) and bound to this browser's User-Agent
   6. Second secret in a SameSite=Strict HttpOnly cookie (double-submit)
   7. Token is single-use: destroyed once checked, pass or fail
══════════════════════════════════════════════════════════════ */
const LO_TOKEN_TTL = 1800;
const LO_COOKIE    = 'dz_lo';

function lo_ua_hash(): string {
    return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function lo_issue(): void {
    global $IS_HTTPS;
    $_SESSION['lo_csrf'] = [
        'token'  => bin2hex(random_bytes(32)),
        'cookie' => bin2hex(random_bytes(32)),
        'iat'    => time(),
        'ua'     => lo_ua_hash(),
    ];
    setcookie(LO_COOKIE, $_SESSION['lo_csrf']['cookie'], [
        'expires'  => 0,
        'path'     => '/',
        'secure'   => $IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function lo_token_usable(): bool {
    $s = $_SESSION['lo_csrf'] ?? null;
    if (!is_array($s) || empty($s['token']) || empty($s['cookie']) || empty($s['iat'])) return false;
    if ((time() - (int)$s['iat']) > LO_TOKEN_TTL) return false;
    $c = $_COOKIE[LO_COOKIE] ?? '';
    return is_string($c) && hash_equals($s['cookie'], $c);
}

function lo_same_origin(): bool {
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
            return true;              // first header present decides
        }
    }
    return true;   // some browsers/privacy tools send neither; the token layers still apply
}

function lo_verify(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (!lo_same_origin()) return false;

    $s    = $_SESSION['lo_csrf'] ?? null;
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    unset($_SESSION['lo_csrf']);   // single-use, whatever the outcome

    if (!is_array($s) || !is_string($sent) || $sent === '') return false;
    if (empty($s['token']) || empty($s['cookie']) || empty($s['iat'])) return false;
    if (!hash_equals($s['token'], $sent)) return false;
    if ((time() - (int)$s['iat']) > LO_TOKEN_TTL) return false;
    if (!hash_equals((string)($s['ua'] ?? ''), lo_ua_hash())) return false;

    $c = $_COOKIE[LO_COOKIE] ?? '';
    if (!is_string($c) || $c === '' || !hash_equals($s['cookie'], $c)) return false;

    return true;
}

/* ══════════════════════════════════════════════════════════════
   WHAT TO SHOW
   confirm = logged in, asking before logging out
   done    = session is gone
══════════════════════════════════════════════════════════════ */
$logged_in = !empty($_SESSION['user_id']);
$error     = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $logged_in) {
    if (lo_verify()) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $c = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $c['path'],
                'domain'   => $c['domain'],
                'secure'   => $c['secure'],
                'httponly' => $c['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        setcookie(LO_COOKIE, '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'secure'   => $IS_HTTPS,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_destroy();
        if ($IS_HTTPS) header('Clear-Site-Data: "cache", "cookies"');

        // Post/Redirect/Get: refreshing the page can never re-submit the form
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'logout.php', '?'), true, 303);
        exit();
    }

    http_response_code(403);
    $error = 'This request expired or could not be verified. Please try again.';
}

$state = $logged_in ? 'confirm' : 'done';

if ($state === 'confirm') {
    if (!lo_token_usable()) lo_issue();
    $csrf_token = $_SESSION['lo_csrf']['token'];
} else {
    $csrf_token = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?php echo $state === 'done' ? 'Logged Out' : 'Log Out'; ?> | Dezzme</title>

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
    --r:          3px;
    --card-r:     4px;
}

html{ scroll-behavior:smooth; background:var(--base); -webkit-text-size-adjust:100%; }

/* RESPONSIVE view: no scrollbars anywhere, scrolling still works */
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}

body{
    position:relative;
    background:var(--base);
    color:var(--text);
    min-height:100vh; min-height:100dvh;
    display:flex; flex-direction:column; align-items:center;
    padding:max(26px, env(safe-area-inset-top)) 16px max(32px, env(safe-area-inset-bottom));
    overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}

/* ── Wordmark ── */
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

/* ── Card ── */
.page-wrapper{
    width:100%; max-width:420px;
    margin:auto 0;
    display:flex; justify-content:center;
}
.panel{
    position:relative;
    flex:1; min-width:0;
    background:var(--base);
    border:1px solid var(--line2);
    border-radius:var(--card-r);
    padding:clamp(26px, 6vw, 36px) var(--pad) clamp(22px, 5vw, 30px);
    display:flex; flex-direction:column; align-items:center; text-align:center;
    box-shadow:0 2px 10px rgba(0,0,0,.15);
    animation:pIn .4s cubic-bezier(.22,1,.36,1) both;
}
@keyframes pIn{ from{ opacity:0; transform:translateY(14px); } to{ opacity:1; transform:none; } }

.lo-icon{
    width:clamp(58px, 17vw, 76px); height:clamp(58px, 17vw, 76px);
    margin:0 auto 16px;
    border-radius:50%;
    border:2.6px solid var(--red);
    box-shadow:0 4px 18px rgba(255,45,45,0.25);
    display:flex; align-items:center; justify-content:center;
    color:var(--red); font-size:clamp(1.5rem, 5.4vw, 2rem);
    background:var(--surface);
    transition:transform .3s, box-shadow .3s;
}
.lo-icon:hover{ transform:scale(1.05); box-shadow:0 0 22px rgba(255,45,45,0.5); }

.panel-label{
    font-size:.6rem; font-weight:700; text-transform:uppercase;
    letter-spacing:1.5px; color:var(--muted); margin-bottom:6px;
}
.panel-title{
    font-size:clamp(1rem, 4.4vw, 1.25rem); font-weight:700;
    letter-spacing:-.1px; margin-bottom:6px; color:#fff;
}
.panel-sub{
    font-size:clamp(.64rem, 2.2vw, .74rem); color:var(--sub);
    line-height:1.65; margin:0 auto 22px; max-width:300px;
}

.message{
    width:100%; margin-bottom:16px; padding:9px 12px;
    border-radius:var(--r); text-align:center;
    background:rgba(255,45,45,.1); border:1px solid rgba(255,45,45,.3);
    color:var(--red-text); font-size:.7rem; line-height:1.55;
}

/* ── Buttons ── */
.lo-actions{ display:flex; flex-direction:column; gap:9px; width:100%; }
.lo-btn{
    display:flex; align-items:center; justify-content:center; gap:7px;
    width:100%; height:var(--ctl-h); padding:0 14px;
    border:none; border-radius:var(--r);
    font-size:clamp(.78rem, 2.7vw, .9rem); font-weight:700; line-height:1; letter-spacing:.2px;
    white-space:nowrap;
    transition:background .2s ease, transform .15s ease, opacity .2s;
}
.lo-btn:active{ transform:scale(.97); }
.lo-btn:disabled{ cursor:default; opacity:.85; }

.lo-btn.primary{ background:var(--red); color:#000; }
.lo-btn.primary:hover{ background:var(--red-hover); }

.lo-btn.ghost{
    background:rgba(255,255,255,0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:#fff; font-weight:600;
}
.lo-btn.ghost:hover{ background:rgba(255,255,255,0.2); }

.lo-note{ margin-top:18px; font-size:.66rem; color:var(--muted); line-height:1.6; }

/* ── loading bars ── */
.spinner-icon{ display:inline-flex; align-items:center; justify-content:center; gap:3px; height:16px; }
.spinner-icon span{ display:block; width:3px; background:currentColor; border-radius:2px; animation:btn-bar 1s ease-in-out infinite; }
.spinner-icon span:nth-child(2){ animation-delay:.15s; }
.spinner-icon span:nth-child(3){ animation-delay:.3s; }
.spinner-icon span:nth-child(4){ animation-delay:.45s; }
@keyframes btn-bar{ 0%,100%{ height:4px; opacity:.4; } 50%{ height:10px; opacity:1; } }

/* ══════════════════════════════════════
   RESPONSIVE — the wordmark stays pinned at the top and the card sits
   in the exact middle of the screen.
══════════════════════════════════════ */
@media (max-width:768px){
    body{
        justify-content:center;
        padding:max(18px, env(safe-area-inset-top)) 12px max(18px, env(safe-area-inset-bottom));
    }
    .brand{
        position:absolute; left:0; right:0;
        top:max(18px, env(safe-area-inset-top));
        width:auto; margin:0;
        font-size:clamp(22px, 6.2vw, 28px);
    }
    .page-wrapper{ max-width:460px; margin:0 auto; }   /* centered in the page */
    .panel{ padding:24px 16px 20px; }
    .lo-icon{ width:56px; height:56px; font-size:1.5rem; margin-bottom:12px; border-width:2.4px; }
    .panel-label{ font-size:.56rem; margin-bottom:5px; }
    .panel-title{ font-size:1rem; margin-bottom:5px; }
    .panel-sub{ font-size:.66rem; margin-bottom:16px; }
    .lo-actions{ gap:8px; }
    .lo-note{ margin-top:14px; font-size:.62rem; }
}
@media (max-width:490px){
    .panel{ padding:22px 14px 18px; }
    .lo-icon{ width:52px; height:52px; font-size:1.4rem; }
    .message{ font-size:.66rem; padding:8px 10px; }
}
@media (max-width:380px){
    body{ padding-left:10px; padding-right:10px; }
    .panel{ padding:20px 12px 16px; }
    .lo-icon{ width:48px; height:48px; font-size:1.3rem; }
}
@media (max-width:330px){
    .panel-title{ font-size:.92rem; }
    .panel-sub{ font-size:.62rem; }
}
/* short landscape screens: wordmark goes back in the flow so nothing overlaps */
@media (max-height:520px) and (orientation:landscape){
    body{ justify-content:flex-start; }
    .brand{ position:static; width:100%; margin-bottom:12px; }
    .page-wrapper{ margin:auto; }
    .lo-icon{ display:none; }
}
@media (prefers-reduced-motion: reduce){
    .panel{ animation:none; }
}
</style>
</head>
<body>

<a href="index.php" class="brand">Dezzme</a>

<div class="page-wrapper">
    <section class="panel">

<?php if ($state === 'confirm'): ?>

        <div class="lo-icon"><i class="ri-logout-box-r-line"></i></div>
        <div class="panel-label">Leaving so soon?</div>
        <h1 class="panel-title">Log out of Dezzme?</h1>
        <p class="panel-sub">You'll need to sign in again to see your feed, trackers and messages.</p>

        <?php if ($error): ?>
        <div class="message" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="" id="logoutForm" class="lo-actions" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="lo-btn primary" id="logoutBtn">Log Out</button>
            <button type="button" class="lo-btn ghost" id="cancelBtn">Cancel</button>
        </form>

<?php else: ?>

        <div class="lo-icon"><i class="ri-check-line"></i></div>
        <div class="panel-label">Signed out</div>
        <h1 class="panel-title">You've been logged out</h1>
        <p class="panel-sub">Thank you for using Dezzme. You can log back in anytime and continue where you left off.</p>

        <div class="lo-actions">
            <a href="login.php" class="lo-btn primary">Go to Login</a>
        </div>
        <div class="lo-note">For your security, close this tab if you're on a shared device.</div>

<?php endif; ?>

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

// ── Cancel: go back, or to the profile if there is no history ──
(function(){
    var c = document.getElementById('cancelBtn');
    if (!c) return;
    c.addEventListener('click', function(){
        if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
        else location.href = 'profile.php';
    });
})();

// ── Loading bars while the logout request is sent (also blocks double submits) ──
(function(){
    var f = document.getElementById('logoutForm');
    if (!f) return;
    var sent = false;
    f.addEventListener('submit', function(e){
        if (sent){ e.preventDefault(); return; }
        sent = true;
        var b = document.getElementById('logoutBtn');
        if (!b) return;
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
</script>
</body>
</html>