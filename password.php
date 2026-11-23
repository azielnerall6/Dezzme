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
   REQUIRED IN config.php (add these once):

   define('OTP_SECRET',     'long-random-string-64+chars');  // server secret for hashing codes
   define('SMTP_HOST',      'smtp-relay.brevo.com');         // your mail provider's SMTP host
   define('SMTP_PORT',      587);                            // 587 (STARTTLS) or 465 (SSL)
   define('SMTP_USER',      '...');
   define('SMTP_PASS',      '...');
   define('MAIL_FROM',      'no-reply@yourdomain.com');      // must be on a domain with SPF + DKIM
   define('MAIL_FROM_NAME', 'Dezzme');

   Install PHPMailer:  composer require phpmailer/phpmailer
   (or drop the PHPMailer folder next to this file, so PHPMailer/src/PHPMailer.php exists)
══════════════════════════════════════════════════════════════ */

/* ══════════════════════════════════════════════════════════════
   RESPONSE HEADERS (same policy as the login and logout pages)
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
   CSRF PROTECTION (password reset)
   1. POST only
   2. Sec-Fetch-Site (when sent) must be same-origin / none
   3. Origin / Referer (when sent) must match this host + port; "null" refused
   4. Secret token in the form, compared with hash_equals()
   5. Token fresh (30 min) and bound to this browser's User-Agent
   6. Second secret in a SameSite=Strict HttpOnly cookie (double-submit)
   7. Single-use: the token is destroyed on every check, pass or fail
══════════════════════════════════════════════════════════════ */
const PW_TOKEN_TTL = 1800;
const PW_COOKIE    = 'dz_pw';

function pw_ua_hash(): string {
    return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}
function pw_cookie_set(string $val, int $expires): void {
    global $IS_HTTPS;
    setcookie(PW_COOKIE, $val, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}
function pw_issue(): void {
    $_SESSION['pw_csrf'] = [
        'token'  => bin2hex(random_bytes(32)),
        'cookie' => bin2hex(random_bytes(32)),
        'iat'    => time(),
        'ua'     => pw_ua_hash(),
    ];
    pw_cookie_set($_SESSION['pw_csrf']['cookie'], 0);
}
function pw_token_usable(): bool {
    $s = $_SESSION['pw_csrf'] ?? null;
    if (!is_array($s) || empty($s['token']) || empty($s['cookie']) || empty($s['iat'])) return false;
    if ((time() - (int)$s['iat']) > PW_TOKEN_TTL) return false;
    $c = $_COOKIE[PW_COOKIE] ?? '';
    return is_string($c) && hash_equals($s['cookie'], $c);
}
function pw_same_origin(): bool {
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
function pw_verify(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (!pw_same_origin()) return false;

    $s    = $_SESSION['pw_csrf'] ?? null;
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    unset($_SESSION['pw_csrf']);   // single-use, whatever the outcome

    if (!is_array($s) || !is_string($sent) || $sent === '') return false;
    if (empty($s['token']) || empty($s['cookie']) || empty($s['iat'])) return false;
    if (!hash_equals($s['token'], $sent)) return false;
    if ((time() - (int)$s['iat']) > PW_TOKEN_TTL) return false;
    if (!hash_equals((string)($s['ua'] ?? ''), pw_ua_hash())) return false;

    $c = $_COOKIE[PW_COOKIE] ?? '';
    return is_string($c) && $c !== '' && hash_equals($s['cookie'], $c);
}

/* ══════════════════════════════════════════════════════════════
   BRUTE-FORCE THROTTLE (per IP and per account, 15 minutes, stored in the DB)
   Same reset_attempts table as before. "req:" and "ver:" prefixes keep
   code-requests and code-guesses in separate buckets per account.
══════════════════════════════════════════════════════════════ */
const PW_WINDOW         = 900;   // seconds
const PW_MAX_REQ_IDENT  = 3;     // code emails per account per window
const PW_MAX_VER_IDENT  = 8;     // wrong code guesses per account per window
const PW_MAX_PER_IP     = 20;    // combined, per IP per window
const PW_MIN_LEN        = 8;

/* ── OTP settings ── */
const PW_CODE_TTL_MIN   = 10;    // minutes a code stays valid
const PW_CODE_MAX_TRIES = 5;     // wrong guesses before a code is destroyed
const PW_RESEND_GAP     = 60;    // seconds before "resend" is allowed
const PW_FLOW_TTL       = 900;   // seconds the step-2 screen stays alive

function pw_db_ready($conn): bool {
    try {
        return (bool)$conn->query("CREATE TABLE IF NOT EXISTS reset_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            ident_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY k_ip (ip, created_at),
            KEY k_ident (ident_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { return false; }
}
function pw_wait($conn, string $ip, string $ih, int $maxIdent, int $maxIp = PW_MAX_PER_IP): int {
    $wait = 0;
    try {
        foreach ([['ip', $ip, $maxIp], ['ident_hash', $ih, $maxIdent]] as [$col, $val, $max]) {
            $st = $conn->prepare("SELECT COUNT(*), TIMESTAMPDIFF(SECOND, MIN(created_at), NOW())
                                  FROM reset_attempts
                                  WHERE $col = ? AND created_at > (NOW() - INTERVAL " . PW_WINDOW . " SECOND)");
            $st->bind_param('s', $val);
            $st->execute();
            $st->bind_result($cnt, $age);
            $st->fetch();
            $st->close();
            if ((int)$cnt >= $max) $wait = max($wait, PW_WINDOW - (int)$age);
        }
    } catch (Throwable $e) {}
    return max(0, $wait);
}
function pw_record($conn, string $ip, string $ih): void {
    try {
        $st = $conn->prepare("INSERT INTO reset_attempts (ip, ident_hash, created_at) VALUES (?, ?, NOW())");
        $st->bind_param('ss', $ip, $ih);
        $st->execute();
        $st->close();
        if (random_int(1, 50) === 1) $conn->query("DELETE FROM reset_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    } catch (Throwable $e) {}
}
function pw_clear($conn, string $ih): void {
    try {
        $st = $conn->prepare("DELETE FROM reset_attempts WHERE ident_hash = ?");
        $st->bind_param('s', $ih);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {}
}
function pw_wait_msg(int $wait): string {
    $mins = max(1, (int)ceil($wait / 60));
    return "Too many attempts. Please try again in $mins minute" . ($mins === 1 ? '' : 's') . '.';
}
function pw_ih_req(string $ident): string { return hash('sha256', 'req:' . strtolower($ident)); }
function pw_ih_ver(int $uid, string $fallback): string {
    return hash('sha256', 'ver:' . ($uid > 0 ? 'u' . $uid : $fallback));
}

/* ══════════════════════════════════════════════════════════════
   EMAIL (PHPMailer over SMTP)
══════════════════════════════════════════════════════════════ */
function dz_mail_ready(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $auto = __DIR__ . '/vendor/autoload.php';
    $dir  = __DIR__ . '/PHPMailer/src/';
    if (is_file($auto)) {
        require_once $auto;
    } elseif (is_file($dir . 'PHPMailer.php')) {
        require_once $dir . 'Exception.php';
        require_once $dir . 'PHPMailer.php';
        require_once $dir . 'SMTP.php';
    }
    return $ok = class_exists('PHPMailer\\PHPMailer\\PHPMailer')
        && defined('SMTP_HOST') && defined('SMTP_PORT') && defined('SMTP_USER')
        && defined('SMTP_PASS') && defined('MAIL_FROM');
}
function dz_send_mail(string $to, string $subject, string $html, string $text): bool {
    if (!dz_mail_ready()) { error_log('Dezzme mail: PHPMailer or SMTP constants missing'); return false; }
    try {
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host       = SMTP_HOST;
        $m->Port       = (int)SMTP_PORT;
        $m->SMTPAuth   = true;
        $m->Username   = SMTP_USER;
        $m->Password   = SMTP_PASS;
        $m->SMTPSecure = ((int)SMTP_PORT === 465)
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $m->Timeout    = 10;
        $m->CharSet    = 'UTF-8';
        $m->setFrom(MAIL_FROM, defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Dezzme');
        $m->addAddress($to);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body    = $html;
        $m->AltBody = $text;
        $m->send();
        return true;
    } catch (Throwable $e) {
        error_log('Dezzme mail error: ' . $e->getMessage());
        return false;
    }
}
function dz_mail_wrap(string $title, string $bodyHtml): string {
    return '<div style="background:#070924;padding:32px 16px;font-family:Arial,Helvetica,sans-serif;">'
         . '<div style="max-width:420px;margin:0 auto;background:#0d0f33;border:1px solid #23265a;border-radius:8px;padding:28px 24px;color:#ffffff;">'
         . '<div style="font-size:22px;letter-spacing:6px;color:#E03C3C;font-weight:700;text-align:center;margin-bottom:18px;">DEZZME</div>'
         . '<div style="font-size:16px;font-weight:700;text-align:center;margin-bottom:12px;">' . $title . '</div>'
         . $bodyHtml
         . '</div></div>';
}

/* ══════════════════════════════════════════════════════════════
   ONE-TIME CODES (table: email_codes)
   Only an HMAC of the code is stored, never the code itself.
══════════════════════════════════════════════════════════════ */
function pw_code_hash(string $code, int $uid): string {
    return hash_hmac('sha256', $code . '|' . $uid . '|reset', (string)OTP_SECRET);
}

/**
 * Looks the account up (username OR email), creates a fresh code and emails it.
 * Returns the user id, or 0 when no usable account exists. The caller must show
 * the SAME message either way so nobody can probe which accounts exist.
 */
function pw_send_code($conn, string $ident): int {
    $uid = 0; $email = '';
    try {
        $st = $conn->prepare("SELECT id, email FROM users WHERE username=? OR email=? ORDER BY id ASC LIMIT 1");
        $st->bind_param('ss', $ident, $ident);
        $st->execute();
        $res = $st->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $uid   = (int)$row['id'];
            $email = trim((string)($row['email'] ?? ''));
        }
        $st->close();
    } catch (Throwable $e) { $uid = 0; }

    if ($uid > 0 && defined('OTP_SECRET') && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = pw_code_hash($code, $uid);

            // kill any older unused code for this account
            $kill = $conn->prepare("UPDATE email_codes SET used=1 WHERE user_id=? AND purpose='reset' AND used=0");
            $kill->bind_param('i', $uid);
            $kill->execute();
            $kill->close();

            $ins = $conn->prepare("INSERT INTO email_codes (user_id, purpose, code_hash, attempts, expires_at, used)
                                   VALUES (?, 'reset', ?, 0, NOW() + INTERVAL " . PW_CODE_TTL_MIN . " MINUTE, 0)");
            $ins->bind_param('is', $uid, $hash);
            $ins->execute();
            $ins->close();

            if (random_int(1, 50) === 1) $conn->query("DELETE FROM email_codes WHERE expires_at < (NOW() - INTERVAL 1 DAY)");

            $ttl  = PW_CODE_TTL_MIN;
            $html = dz_mail_wrap('Your verification code',
                '<p style="font-size:13px;color:#b9bbd6;text-align:center;line-height:1.6;margin:0 0 18px;">Use this code to reset your Dezzme password. It expires in ' . $ttl . ' minutes.</p>'
              . '<div style="font-size:32px;letter-spacing:10px;font-weight:700;text-align:center;background:#070924;border:1px solid #23265a;border-radius:6px;padding:14px 0 14px 10px;margin-bottom:18px;">' . $code . '</div>'
              . '<p style="font-size:12px;color:#8b8eb3;text-align:center;line-height:1.6;margin:0;">Didn\'t ask for this? You can ignore this email. Your password will not change. Never share this code with anyone.</p>');
            $text = "Your Dezzme password reset code is $code. It expires in $ttl minutes.\n\nDidn't ask for this? Ignore this email. Never share this code with anyone.";

            if (!dz_send_mail($email, 'Your Dezzme verification code', $html, $text)) {
                error_log('Dezzme: reset code email failed for user ' . $uid);
            }
            return $uid;
        } catch (Throwable $e) {
            error_log('Dezzme OTP error: ' . $e->getMessage());
            return $uid;
        }
    }

    // Unknown account (or no email on file): spend similar time so responses look alike
    usleep(random_int(600000, 1400000));
    return 0;
}

/**
 * Checks a submitted code. Attempt counter is incremented atomically BEFORE the
 * comparison, and a correct code is claimed atomically, so it can only ever work once.
 */
function pw_check_code($conn, int $uid, string $code): bool {
    if ($uid <= 0 || !defined('OTP_SECRET')) return false;
    try {
        $st = $conn->prepare("SELECT id, code_hash, attempts FROM email_codes
                              WHERE user_id=? AND purpose='reset' AND used=0 AND expires_at > NOW()
                              ORDER BY id DESC LIMIT 1");
        $st->bind_param('i', $uid);
        $st->execute();
        $st->bind_result($id, $stored, $tries);
        $found = ($st->fetch() === true);
        $st->close();
        if (!$found) return false;

        if ((int)$tries >= PW_CODE_MAX_TRIES) {
            $k = $conn->prepare("UPDATE email_codes SET used=1 WHERE id=?");
            $k->bind_param('i', $id);
            $k->execute();
            $k->close();
            return false;
        }

        $max = PW_CODE_MAX_TRIES;
        $up  = $conn->prepare("UPDATE email_codes SET attempts = attempts + 1 WHERE id=? AND attempts < ?");
        $up->bind_param('ii', $id, $max);
        $up->execute();
        $n = $up->affected_rows;
        $up->close();
        if ($n !== 1) return false;

        if (!hash_equals((string)$stored, pw_code_hash($code, $uid))) return false;

        $cl = $conn->prepare("UPDATE email_codes SET used=1 WHERE id=? AND used=0");
        $cl->bind_param('i', $id);
        $cl->execute();
        $claimed = $cl->affected_rows;
        $cl->close();
        if ($claimed !== 1) return false;

        // a successful reset also voids every other code for this account
        $all = $conn->prepare("UPDATE email_codes SET used=1 WHERE user_id=? AND purpose='reset'");
        $all->bind_param('i', $uid);
        $all->execute();
        $all->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ══════════════════════════════════════════════════════════════
   FLOW STATE (which step the visitor is on)
══════════════════════════════════════════════════════════════ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['restart'])) {
    unset($_SESSION['pw_flow']);
    header('Location: password.php', true, 303);
    exit();
}

$flow = $_SESSION['pw_flow'] ?? null;
if (!is_array($flow) || (time() - (int)($flow['iat'] ?? 0)) > PW_FLOW_TTL) {
    unset($_SESSION['pw_flow']);
    $flow = null;
}

/* ══════════════════════════════════════════════════════════════
   HANDLE REQUESTS
══════════════════════════════════════════════════════════════ */
$message = '';
$msgOk   = false;      // true = green message
$done    = false;      // password changed
$prefill = '';

$isAction = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (isset($_POST['send_code']) || isset($_POST['resend_code']) || isset($_POST['change_password']));

if ($isAction) {

    if (!pw_verify()) {
        http_response_code(403);
        $message = 'This page expired or could not be verified. Please try again.';
    } else {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        pw_db_ready($conn);

        /* ───────── STEP 1: send (or resend) the code ───────── */
        if (isset($_POST['send_code']) || isset($_POST['resend_code'])) {

            $isResend = isset($_POST['resend_code']);
            $ident    = $isResend ? (string)($flow['ident'] ?? '') : trim((string)($_POST['identifier'] ?? ''));
            $prefill  = $ident;

            if ($isResend && !$flow) {
                $message = 'Your session expired. Please start again.';
            } elseif ($ident === '') {
                $message = 'Please enter your username or email.';
            } elseif (strlen($ident) > 254) {
                $message = 'Could not send a code. Please check your details.';
            } elseif ($isResend && (time() - (int)($flow['sent_at'] ?? 0)) < PW_RESEND_GAP) {
                $message = 'Please wait a moment before requesting another code.';
            } else {
                $ihr  = pw_ih_req($ident);
                $wait = pw_wait($conn, $ip, $ihr, PW_MAX_REQ_IDENT);

                if ($wait > 0) {
                    http_response_code(429);
                    $message = pw_wait_msg($wait);
                } else {
                    pw_record($conn, $ip, $ihr);
                    $uid = pw_send_code($conn, $ident);

                    $_SESSION['pw_flow'] = [
                        'uid'     => $uid,          // 0 when no such account (never shown to the visitor)
                        'ident'   => $ident,
                        'ih'      => $ihr,
                        'iat'     => time(),
                        'sent_at' => time(),
                    ];
                    $flow    = $_SESSION['pw_flow'];
                    $message = 'If an account matches, we sent a 6-digit code to its email. It expires in ' . PW_CODE_TTL_MIN . ' minutes.';
                    $msgOk   = true;
                    $prefill = '';
                }
            }

        /* ───────── STEP 2: verify the code and set the password ───────── */
        } elseif (isset($_POST['change_password'])) {

            if (!$flow) {
                $message = 'Your session expired. Please start again.';
            } else {
                $code    = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
                $new     = (string)($_POST['new_password'] ?? '');
                $confirm = (string)($_POST['confirm_password'] ?? '');

                if (strlen($code) !== 6) {
                    $message = 'Enter the 6-digit code from your email.';
                } elseif ($new === '' || $confirm === '') {
                    $message = 'Please fill in all fields.';
                } elseif (strlen($new) > 1024) {
                    $message = 'Could not reset the password. Please check your details.';
                } elseif (strlen($new) < PW_MIN_LEN) {
                    $message = 'Your new password must be at least ' . PW_MIN_LEN . ' characters.';
                } elseif (!hash_equals($new, $confirm)) {
                    $message = 'Passwords do not match.';
                } else {
                    $uid  = (int)($flow['uid'] ?? 0);
                    $ihv  = pw_ih_ver($uid, (string)($flow['ih'] ?? ''));
                    $wait = pw_wait($conn, $ip, $ihv, PW_MAX_VER_IDENT);

                    if ($wait > 0) {
                        http_response_code(429);
                        $message = pw_wait_msg($wait);
                    } elseif (!pw_check_code($conn, $uid, $code)) {
                        pw_record($conn, $ip, $ihv);
                        if ($uid <= 0) usleep(random_int(250000, 450000));
                        $message = 'That code is incorrect or has expired. You can request a new one below.';
                    } else {
                        $hashed = password_hash($new, PASSWORD_DEFAULT);
                        $up = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                        $up->bind_param('si', $hashed, $uid);
                        $ok = $up->execute();
                        $up->close();

                        if ($ok) {
                            pw_clear($conn, $ihv);
                            pw_clear($conn, (string)$flow['ih']);
                            unset($_SESSION['pw_flow']);
                            $flow = null;
                            session_regenerate_id(true);

                            // heads-up email so the real owner notices if this wasn't them
                            try {
                                $q = $conn->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
                                $q->bind_param('i', $uid);
                                $q->execute();
                                $q->bind_result($mailTo);
                                $q->fetch();
                                $q->close();
                                if (!empty($mailTo) && filter_var($mailTo, FILTER_VALIDATE_EMAIL)) {
                                    dz_send_mail(
                                        (string)$mailTo,
                                        'Your Dezzme password was changed',
                                        dz_mail_wrap('Password changed',
                                            '<p style="font-size:13px;color:#b9bbd6;text-align:center;line-height:1.6;margin:0;">Your Dezzme password was just changed. If this was you, no action is needed. If it wasn\'t, reset your password again right away and contact support.</p>'),
                                        "Your Dezzme password was just changed. If this wasn't you, reset your password again right away and contact support."
                                    );
                                }
                            } catch (Throwable $e) {}

                            $done    = true;
                            $msgOk   = true;
                            $message = 'Password updated successfully. You can log in now.';
                        } else {
                            $message = 'Something went wrong. Please request a new code and try again.';
                        }
                    }
                }
            }
        }
    }
}

$step       = $done ? 'done' : ($flow ? 'verify' : 'request');
$resendWait = ($step === 'verify') ? max(0, PW_RESEND_GAP - (time() - (int)($flow['sent_at'] ?? 0))) : 0;

if (!pw_token_usable()) pw_issue();
$csrf_token = $_SESSION['pw_csrf']['token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title>Reset Password | Dezzme</title>

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
    --green:      #4ade80;
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
    flex:1; min-width:0;
    background:var(--base);
    border:1px solid var(--line2);
    border-radius:8px;
    padding:clamp(24px, 5vw, 34px) var(--pad);
    display:flex; flex-direction:column;
    box-shadow:0 2px 10px rgba(0,0,0,.15);
    animation:pIn .4s cubic-bezier(.22,1,.36,1) both;
}
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
.form-group input.code-input{
    text-align:center; letter-spacing:10px; padding:0 14px 0 24px;
    font-size:clamp(.95rem, 4vw, 1.1rem); font-weight:600;
}
.form-group input.code-input::placeholder{ letter-spacing:6px; font-weight:400; }
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
.message.success{
    background:rgba(74,222,128,.1); border-color:rgba(74,222,128,.3);
    color:var(--green);
}

.hint{ font-size:.62rem; color:var(--muted); line-height:1.6; margin:-3px 0 12px; text-align:center; }

/* ── Buttons ── */
.btn-main{
    display:flex; align-items:center; justify-content:center;
    width:100%; height:var(--ctl-h); margin-top:4px; margin-bottom:18px;
    border:none; border-radius:4px;
    background:var(--red); color:#000;
    font-size:clamp(.78rem, 2.7vw, .9rem); font-weight:700; line-height:1; letter-spacing:.2px;
    transition:background .2s, transform .15s, opacity .2s;
}
.btn-main:hover{ background:var(--red-hover); }
.btn-main:active{ transform:scale(.97); }
.btn-main:disabled{ cursor:default; opacity:.85; }

.link-btn{
    background:none; border:none; padding:0;
    color:var(--red-text); font-size:inherit; font-weight:600;
    transition:color .2s;
}
.link-btn:hover{ color:#fff; }
.link-btn:disabled{ color:var(--muted); cursor:default; }

.links{ text-align:center; font-size:.7rem; color:var(--muted); line-height:2.1; width:100%; }
.links a{ color:var(--red-text); font-weight:600; margin:0 6px; transition:color .2s; }
.links a:hover{ color:#fff; }
.links .sep{ color:var(--line2); }
.links form{ display:inline; }

/* ── loading bars ── */
.spinner-icon{ display:inline-flex; align-items:center; justify-content:center; gap:3px; height:16px; }
.spinner-icon span{ display:block; width:3px; background:currentColor; border-radius:2px; animation:btn-bar 1s ease-in-out infinite; }
.spinner-icon span:nth-child(2){ animation-delay:.15s; }
.spinner-icon span:nth-child(3){ animation-delay:.3s; }
.spinner-icon span:nth-child(4){ animation-delay:.45s; }
@keyframes btn-bar{ 0%,100%{ height:4px; opacity:.4; } 50%{ height:10px; opacity:1; } }

/* ══════════════════════════════════════
   RESPONSIVE — the wordmark stays pinned at the top and the card sits
   in the exact middle of the screen (same as the logout page).
   Equal top and bottom padding keeps the card truly centred and stops it
   from ever touching the wordmark on short screens.
══════════════════════════════════════ */
@media (max-width:768px){
    body{
        justify-content:center;
        padding:
            calc(max(18px, env(safe-area-inset-top)) + 46px)
            12px
            calc(max(18px, env(safe-area-inset-top)) + 46px);
    }
    .brand{
        position:absolute; left:0; right:0;
        top:max(18px, env(safe-area-inset-top));
        width:auto; margin:0;
        font-size:clamp(22px, 6.2vw, 28px);
    }
    .page-wrapper{ max-width:440px; margin:0 auto; }   /* centred in the page */
    .panel{ padding:22px 14px 18px; }
    .panel-label{ font-size:.56rem; margin-bottom:5px; }
    .panel-title{ font-size:1rem; margin-bottom:5px; }
    .panel-sub{ font-size:.66rem; margin-bottom:16px; }
    .form-group{ margin-bottom:9px; }
    .btn-main{ margin-bottom:14px; }
    .links{ font-size:.66rem; line-height:2; }
}
@media (max-width:490px){
    .panel{ padding:20px 12px 16px; border-radius:8px; }
    .panel-sub{ margin-bottom:14px; }
    .message{ font-size:.66rem; padding:8px 10px; }
}
@media (max-width:380px){
    body{ padding-left:10px; padding-right:10px; }
    .panel{ padding:18px 11px 14px; }
}
@media (max-width:330px){
    .panel-title{ font-size:.92rem; }
    .panel-sub{ font-size:.62rem; }
    .links a{ margin:0 4px; }
}
/* short landscape screens: wordmark goes back in the flow so nothing overlaps */
@media (max-height:520px) and (orientation:landscape){
    body{ justify-content:flex-start; padding-top:14px; padding-bottom:14px; }
    .brand{ position:static; width:100%; margin-bottom:12px; }
    .page-wrapper{ margin:auto; }
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

<?php if ($step === 'request'): ?>
        <div class="panel-label">Account recovery &middot; Step 1 of 2</div>
        <h1 class="panel-title">Reset Password</h1>
        <p class="panel-sub">Enter your username or email and we'll send you a 6-digit verification code.</p>
<?php elseif ($step === 'verify'): ?>
        <div class="panel-label">Account recovery &middot; Step 2 of 2</div>
        <h1 class="panel-title">Check Your Email</h1>
        <p class="panel-sub">Enter the 6-digit code, then choose a new password.</p>
<?php else: ?>
        <div class="panel-label">All set</div>
        <h1 class="panel-title">Password Updated</h1>
        <p class="panel-sub">Your password has been changed.</p>
<?php endif; ?>

        <?php if ($message): ?>
            <div class="message<?php echo $msgOk ? ' success' : ''; ?>" role="<?php echo $msgOk ? 'status' : 'alert'; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

<?php if ($step === 'request'): ?>

        <form method="POST" action="" id="sendForm" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">
                <input type="text" name="identifier" placeholder="Username or email"
                       value="<?php echo htmlspecialchars($prefill, ENT_QUOTES, 'UTF-8'); ?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="254" required>
            </div>

            <button type="submit" name="send_code" value="1" class="btn-main" id="sendBtn">Send Code</button>
        </form>

        <div class="links">
            <p>
                <a href="login.php">Back to Login</a><span class="sep">|</span><a href="dezzme_policy.php">Privacy Policy</a>
            </p>
        </div>

<?php elseif ($step === 'verify'): ?>

        <form method="POST" action="" id="resetForm" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">
                <input type="text" id="code" name="code" class="code-input" placeholder="------"
                       inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code"
                       maxlength="6" aria-label="6-digit code" required>
            </div>

            <div class="form-group password-container">
                <input type="password" id="new_password" name="new_password" placeholder="New password"
                       autocomplete="new-password" maxlength="1024" minlength="<?php echo PW_MIN_LEN; ?>" required>
                <button type="button" class="toggle-pass" data-target="new_password" aria-label="Show password">
                    <i class="ri-eye-line"></i>
                </button>
            </div>

            <div class="form-group password-container">
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password"
                       autocomplete="new-password" maxlength="1024" minlength="<?php echo PW_MIN_LEN; ?>" required>
                <button type="button" class="toggle-pass" data-target="confirm_password" aria-label="Show password">
                    <i class="ri-eye-line"></i>
                </button>
            </div>

            <p class="hint">Use at least <?php echo PW_MIN_LEN; ?> characters.</p>

            <button type="submit" name="change_password" value="1" class="btn-main" id="resetBtn">Reset Password</button>
        </form>

        <div class="links">
            <div>
                Didn't get it?
                <form method="POST" action="" id="resendForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="resend_code" value="1" class="link-btn" id="resendBtn" data-wait="<?php echo (int)$resendWait; ?>">Resend code</button>
                </form>
                <span class="sep">|</span>
                <a href="password.php?restart=1">Start over</a>
            </div>
            <p>
                <a href="login.php">Back to Login</a><span class="sep">|</span><a href="dezzme_policy.php">Privacy Policy</a>
            </p>
        </div>

<?php else: ?>

        <a href="login.php" class="btn-main">Go to Login</a>

        <div class="links">
            <p><a href="dezzme_policy.php">Privacy Policy</a></p>
        </div>

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

// ── Show / hide password (both fields) ──
document.querySelectorAll('.toggle-pass').forEach(function(btn){
    btn.addEventListener('click', function(){
        var inp = document.getElementById(btn.dataset.target);
        var ico = btn.querySelector('i');
        if (!inp) return;
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        ico.className = show ? 'ri-eye-off-line' : 'ri-eye-line';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
});

// ── Code box: digits only (works with paste and autofill too) ──
(function(){
    var c = document.getElementById('code');
    if (!c) return;
    c.addEventListener('input', function(){
        var v = c.value.replace(/\D+/g, '').slice(0, 6);
        if (c.value !== v) c.value = v;
    });
    c.focus();
})();

// ── Resend countdown ──
(function(){
    var b = document.getElementById('resendBtn');
    if (!b) return;
    var w = parseInt(b.getAttribute('data-wait') || '0', 10);
    var label = 'Resend code';
    (function tick(){
        if (w > 0) {
            b.disabled = true;
            b.textContent = label + ' (' + w + 's)';
            w--;
            setTimeout(tick, 1000);
        } else {
            b.disabled = false;
            b.textContent = label;
        }
    })();
})();

// ── Loading bars on submit (also blocks double submits) ──
['sendForm', 'resetForm'].forEach(function(id){
    var f = document.getElementById(id);
    if (!f) return;
    var sent = false;
    f.addEventListener('submit', function(e){
        if (sent){ e.preventDefault(); return; }
        if (!f.checkValidity()) return;
        sent = true;
        var b = f.querySelector('.btn-main');
        b.style.minWidth = b.offsetWidth + 'px';
        setTimeout(function(){
            b.disabled = true;
            b.innerHTML = '<span class="spinner-icon"><span></span><span></span><span></span><span></span></span>';
        }, 0);
    });
});

// ── Back/forward cache: never show a stale form with a burned token ──
window.addEventListener('pageshow', function(e){
    if (e.persisted) location.reload();
});
</script>
</body>
</html>