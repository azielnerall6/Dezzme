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
   RESPONSE HEADERS (same policy as the login, logout and reset pages)
   connect-src also allows OpenStreetMap's search, used by the
   location pickers (same service as Group Events).
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
    "connect-src 'self' https://nominatim.openstreetmap.org; " .
    "object-src 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self'; " .
    "frame-ancestors 'none'"
);

/* ══════════════════════════════════════════════════════════════
   ABOUT ME LINK
   Where the register answers are saved so they appear in About Me.
   These now match about_me_save.php / about_me_fetch.php, which
   read and write the `user_about` table (key column: user_id).
   Columns written (any that don't exist in your table are skipped):
   full_name, nickname, occupation, education, current_location,
   hometown, relationship_status, date_of_birth, website,
   interests, description
══════════════════════════════════════════════════════════════ */
const RG_ABOUT_TABLE   = 'user_about';
const RG_ABOUT_USERCOL = 'user_id';

/* ══════════════════════════════════════════════════════════════
   CSRF PROTECTION (register)
   1. POST only
   2. Sec-Fetch-Site (when sent) must be same-origin / none
   3. Origin / Referer (when sent) must match this host + port; "null" refused
   4. Secret token in the form, compared with hash_equals()
   5. Token fresh (30 min) and bound to this browser's User-Agent
   6. Second secret in a SameSite=Strict HttpOnly cookie (double-submit)
   7. Single-use: the token is destroyed on every check, pass or fail
══════════════════════════════════════════════════════════════ */
const RG_TOKEN_TTL = 1800;
const RG_COOKIE    = 'dz_rg';

function rg_ua_hash(): string {
    return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}
function rg_cookie_set(string $val, int $expires): void {
    global $IS_HTTPS;
    setcookie(RG_COOKIE, $val, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}
function rg_issue(): void {
    $_SESSION['rg_csrf'] = [
        'token'  => bin2hex(random_bytes(32)),
        'cookie' => bin2hex(random_bytes(32)),
        'iat'    => time(),
        'ua'     => rg_ua_hash(),
    ];
    rg_cookie_set($_SESSION['rg_csrf']['cookie'], 0);
}
function rg_token_usable(): bool {
    $s = $_SESSION['rg_csrf'] ?? null;
    if (!is_array($s) || empty($s['token']) || empty($s['cookie']) || empty($s['iat'])) return false;
    if ((time() - (int)$s['iat']) > RG_TOKEN_TTL) return false;
    $c = $_COOKIE[RG_COOKIE] ?? '';
    return is_string($c) && hash_equals($s['cookie'], $c);
}
function rg_same_origin(): bool {
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
function rg_verify(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (!rg_same_origin()) return false;

    $s    = $_SESSION['rg_csrf'] ?? null;
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    unset($_SESSION['rg_csrf']);   // single-use, whatever the outcome

    if (!is_array($s) || !is_string($sent) || $sent === '') return false;
    if (empty($s['token']) || empty($s['cookie']) || empty($s['iat'])) return false;
    if (!hash_equals($s['token'], $sent)) return false;
    if ((time() - (int)$s['iat']) > RG_TOKEN_TTL) return false;
    if (!hash_equals((string)($s['ua'] ?? ''), rg_ua_hash())) return false;

    $c = $_COOKIE[RG_COOKIE] ?? '';
    return is_string($c) && $c !== '' && hash_equals($s['cookie'], $c);
}

/* ══════════════════════════════════════════════════════════════
   SIGN-UP THROTTLE (per IP, 1 hour, stored in the DB)
══════════════════════════════════════════════════════════════ */
const RG_WINDOW     = 3600;   // seconds
const RG_MAX_PER_IP = 10;
const RG_MIN_PASS   = 8;
const RG_MIN_AGE    = 13;
const RG_DZ_DIGITS  = 10;     // digits after "DZ-" for NEW accounts (existing IDs never change)

function rg_db_ready($conn): bool {
    try {
        return (bool)$conn->query("CREATE TABLE IF NOT EXISTS register_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY k_ip (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { return false; }
}
function rg_wait($conn, string $ip): int {
    try {
        $st = $conn->prepare("SELECT COUNT(*), TIMESTAMPDIFF(SECOND, MIN(created_at), NOW())
                              FROM register_attempts
                              WHERE ip = ? AND created_at > (NOW() - INTERVAL " . RG_WINDOW . " SECOND)");
        $st->bind_param('s', $ip);
        $st->execute();
        $st->bind_result($cnt, $age);
        $st->fetch();
        $st->close();
        if ((int)$cnt >= RG_MAX_PER_IP) return max(0, RG_WINDOW - (int)$age);
    } catch (Throwable $e) {}
    return 0;
}
function rg_record($conn, string $ip): void {
    try {
        $st = $conn->prepare("INSERT INTO register_attempts (ip, created_at) VALUES (?, NOW())");
        $st->bind_param('s', $ip);
        $st->execute();
        $st->close();
        if (random_int(1, 50) === 1) $conn->query("DELETE FROM register_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    } catch (Throwable $e) {}
}

/* ══════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════ */
function rg_exists($conn, string $col, string $val): bool {
    $col = ($col === 'email') ? 'email' : 'username';
    $st = $conn->prepare("SELECT id FROM users WHERE $col = ? LIMIT 1");
    $st->bind_param('s', $val);
    $st->execute();
    $st->store_result();
    $found = $st->num_rows > 0;
    $st->close();
    return $found;
}
/* random string of digits (cryptographically secure) */
function rg_dz_digits(int $n = RG_DZ_DIGITS): string {
    $d = '';
    for ($i = 0; $i < $n; $i++) $d .= random_int(0, 9);
    return $d;
}
/* permanent DZ-XXXXXXXXXX profile id (10 digits). The UNIQUE index on users.dz_id is the real
   guarantee; this check just avoids picking a number that is already taken. */
function rg_unique_dz_id($conn): string {
    for ($i = 0; $i < 30; $i++) {
        $id = 'DZ-' . rg_dz_digits(RG_DZ_DIGITS);
        $st = $conn->prepare("SELECT id FROM users WHERE dz_id = ? LIMIT 1");
        $st->bind_param('s', $id);
        $st->execute();
        $st->store_result();
        $taken = $st->num_rows > 0;
        $st->close();
        if (!$taken) return $id;
    }
    return 'DZ-' . rg_dz_digits(RG_DZ_DIGITS);
}
function rg_link($conn, int $a, int $b): void {
    $st = $conn->prepare("INSERT IGNORE INTO linked_accounts (primary_user_id, linked_user_id) VALUES (?, ?)");
    $st->bind_param('ii', $a, $b);
    $st->execute();
    $st->close();
}
/* Website: accepts "mysite.com" or "https://mysite.com". Returns '' (empty),
   a clean https/http URL, or false when it is not a valid address. */
function rg_site(string $v) {
    $v = trim($v);
    if ($v === '') return '';
    if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
    if (!filter_var($v, FILTER_VALIDATE_URL)) return false;
    $host = (string)parse_url($v, PHP_URL_HOST);
    if ($host === '' || strpos($host, '.') === false) return false;
    return $v;
}
/* Saves the register answers into About Me (the user_about table). Only columns
   that really exist in that table are written, empty answers are stored as NULL
   (same as about_me_save.php), and it never blocks registration if it fails. */
function rg_save_about($conn, int $uid, array $vals): void {
    try {
        $tn = str_replace('`', '', RG_ABOUT_TABLE);
        $cn = str_replace('`', '', RG_ABOUT_USERCOL);
        $t  = '`' . $tn . '`';
        $c  = '`' . $cn . '`';

        // which of our columns exist in the real table?
        $have = [];
        $cs = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $cs->bind_param('s', $tn);
        $cs->execute();
        $cr = $cs->get_result();
        while ($cr && ($r = $cr->fetch_assoc())) $have[strtolower($r['COLUMN_NAME'])] = true;
        $cs->close();

        $cols = []; $data = [];
        foreach ($vals as $col => $v) {
            if (isset($have[strtolower($col)])) {
                $cols[] = $col;
                $data[] = ($v === '' || $v === null) ? null : $v;
            }
        }
        if (!$cols) { error_log('register: no About Me columns matched in ' . $tn); return; }

        $st = $conn->prepare("SELECT 1 FROM $t WHERE $c = ? LIMIT 1");
        $st->bind_param('i', $uid);
        $st->execute();
        $st->store_result();
        $has = $st->num_rows > 0;
        $st->close();

        if ($has) {
            $set = implode(', ', array_map(function ($k) { return '`' . $k . '` = ?'; }, $cols));
            $q = $conn->prepare("UPDATE $t SET $set WHERE $c = ?");
            $types = str_repeat('s', count($data)) . 'i';
            $params = array_merge($data, [$uid]);
        } else {
            $names = implode(', ', array_map(function ($k) { return '`' . $k . '`'; }, $cols));
            $marks = implode(', ', array_fill(0, count($cols), '?'));
            $q = $conn->prepare("INSERT INTO $t ($c, $names) VALUES (?, $marks)");
            $types = 'i' . str_repeat('s', count($data));
            $params = array_merge([$uid], $data);
        }
        $q->bind_param($types, ...$params);
        $q->execute();
        $q->close();
    } catch (Throwable $e) {
        error_log('register: About Me save failed: ' . $e->getMessage());
    }
}

/* ══════════════════════════════════════════════════════════════
   HANDLE REGISTER
══════════════════════════════════════════════════════════════ */
$REL_OPTIONS = ['Single', 'In a relationship', 'Engaged', 'Married', "It's complicated"];

$message = '';
$old = [
    'username'=>'', 'email'=>'', 'phone'=>'', 'dob'=>'', 'location'=>'',
    'full_name'=>'', 'nickname'=>'', 'occupation'=>'', 'education'=>'', 'hometown'=>'',
    'relationship'=>'', 'website'=>'', 'interests'=>'', 'description'=>'',
];
$more_open = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['register'])) {

    if (!rg_verify()) {
        http_response_code(403);
        $message = 'This page expired or could not be verified. Please try again.';
    } else {
        $username     = trim((string)($_POST['username'] ?? ''));
        $email        = trim((string)($_POST['email'] ?? ''));
        $phone        = trim((string)($_POST['phone'] ?? ''));
        $dob          = trim((string)($_POST['date_of_birth'] ?? ''));
        $location     = trim((string)($_POST['current_location'] ?? ''));
        $full_name    = trim((string)($_POST['full_name'] ?? ''));
        $nickname     = trim((string)($_POST['nickname'] ?? ''));
        $occupation   = trim((string)($_POST['occupation'] ?? ''));
        $education    = trim((string)($_POST['education'] ?? ''));
        $hometown     = trim((string)($_POST['hometown'] ?? ''));
        $relationship = trim((string)($_POST['relationship_status'] ?? ''));
        $website_raw  = trim((string)($_POST['website'] ?? ''));
        $interests    = trim((string)($_POST['interests'] ?? ''));
        $description  = trim((string)($_POST['description'] ?? ''));
        $password     = (string)($_POST['password'] ?? '');
        $confirm      = (string)($_POST['confirm_password'] ?? '');

        // tidy interests into "a, b, c"
        $interests = implode(', ', array_values(array_filter(array_map('trim', explode(',', $interests)), 'strlen')));

        $old = [
            'username' => $username, 'email' => $email, 'phone' => $phone, 'dob' => $dob, 'location' => $location,
            'full_name' => $full_name, 'nickname' => $nickname, 'occupation' => $occupation, 'education' => $education,
            'hometown' => $hometown, 'relationship' => $relationship, 'website' => $website_raw,
            'interests' => $interests, 'description' => $description,
        ];
        foreach (['full_name','nickname','occupation','education','hometown','relationship','website','interests','description'] as $k) {
            if ($old[$k] !== '') { $more_open = true; break; }
        }

        // date of birth check
        $dobOk = false; $ageOk = false;
        $d = DateTime::createFromFormat('!Y-m-d', $dob);
        if ($d && $d->format('Y-m-d') === $dob && (int)$d->format('Y') >= 1900) {
            $dobOk = true;
            $ageOk = ($d <= (new DateTime('today'))->modify('-' . RG_MIN_AGE . ' years'));
        }

        $website = rg_site($website_raw);

        if ($username === '' || $email === '' || $dob === '' || $location === '' || $password === '' || $confirm === '') {
            $message = 'Please fill in all required fields.';
        } elseif (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username)) {
            $message = 'Username must be 3 to 30 characters: letters, numbers, dots or underscores.';
        } elseif (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
        } elseif ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) {
            $message = 'Please enter a valid phone number or leave it empty.';
        } elseif (!$dobOk) {
            $message = 'Please enter a valid date of birth.';
        } elseif (!$ageOk) {
            $message = 'You must be at least ' . RG_MIN_AGE . ' years old to join.';
        } elseif (mb_strlen($location) > 100 || mb_strlen($hometown) > 100 || mb_strlen($occupation) > 100 || mb_strlen($full_name) > 100) {
            $message = 'One of your answers is too long. Please shorten it.';
        } elseif (mb_strlen($nickname) > 50 || mb_strlen($education) > 150 || mb_strlen($interests) > 255) {
            $message = 'One of your answers is too long. Please shorten it.';
        } elseif (mb_strlen($description) > 1000) {
            $message = 'Your description is too long (1000 characters maximum).';
        } elseif ($relationship !== '' && !in_array($relationship, $REL_OPTIONS, true)) {
            $message = 'Please choose a valid relationship status.';
        } elseif ($website === false || strlen((string)$website) > 255) {
            $message = 'Please enter a valid website address, like yoursite.com.';
        } elseif (strlen($password) < RG_MIN_PASS) {
            $message = 'Your password must be at least ' . RG_MIN_PASS . ' characters.';
        } elseif (strlen($password) > 1024) {
            $message = 'Your password is too long.';
        } elseif (!hash_equals($password, $confirm)) {
            $message = 'Passwords do not match.';
        } else {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            rg_db_ready($conn);
            $wait = rg_wait($conn, $ip);

            if ($wait > 0) {
                http_response_code(429);
                $mins = max(1, (int)ceil($wait / 60));
                $message = "Too many sign-ups from this connection. Please try again in $mins minute" . ($mins === 1 ? '' : 's') . '.';
            } else {
                rg_record($conn, $ip);

                if (rg_exists($conn, 'username', $username)) {
                    $message = 'Username taken.';
                } elseif (rg_exists($conn, 'email', $email)) {
                    $message = 'Email already registered.';
                } else {
                    $owner = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
                    $blocked = false;

                    if ($owner > 0) {
                        $cs = $conn->prepare("SELECT COUNT(*) FROM linked_accounts WHERE primary_user_id = ?");
                        $cs->bind_param('i', $owner);
                        $cs->execute();
                        $cs->bind_result($cnt);
                        $cs->fetch();
                        $cs->close();
                        if ((int)$cnt >= 4) {
                            $message = 'You have reached the maximum of 5 linked accounts.';
                            $blocked = true;
                        }
                    }

                    if (!$blocked) {
                        try {
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $ins = $conn->prepare("INSERT INTO users (username, email, password, phone) VALUES (?, ?, ?, ?)");
                            $ins->bind_param('ssss', $username, $email, $hashed, $phone);
                            $ok = $ins->execute();
                            $new_id = (int)$conn->insert_id;
                            $ins->close();
                        } catch (Throwable $e) {
                            $ok = false; $new_id = 0;
                            error_log('register: insert failed: ' . $e->getMessage());
                        }

                        if ($ok && $new_id > 0) {
                            // unique permanent DZ profile id (DZ- + 10 digits)
                            $dz_id = rg_unique_dz_id($conn);
                            $du = $conn->prepare("UPDATE users SET dz_id = ? WHERE id = ?");
                            $du->bind_param('si', $dz_id, $new_id);
                            $du->execute();
                            $du->close();

                            // everything typed here shows up in About Me
                            rg_save_about($conn, $new_id, [
                                'full_name'           => $full_name,
                                'nickname'            => $nickname,
                                'occupation'          => $occupation,
                                'education'           => $education,
                                'current_location'    => $location,
                                'hometown'            => $hometown,
                                'relationship_status' => $relationship,
                                'date_of_birth'       => $dob,
                                'website'             => (string)$website,
                                'interests'           => $interests,
                                'description'         => $description,
                            ]);

                            // linked accounts (when registering from a signed-in account)
                            if ($owner > 0) {
                                rg_link($conn, $owner, $new_id);
                                rg_link($conn, $new_id, $owner);
                                $ex = $conn->prepare("SELECT linked_user_id FROM linked_accounts WHERE primary_user_id = ?");
                                $ex->bind_param('i', $owner);
                                $ex->execute();
                                $er = $ex->get_result();
                                $others = [];
                                while ($er && ($row = $er->fetch_assoc())) $others[] = (int)$row['linked_user_id'];
                                $ex->close();
                                foreach ($others as $other) {
                                    if ($other !== $new_id) {
                                        rg_link($conn, $other, $new_id);
                                        rg_link($conn, $new_id, $other);
                                    }
                                }
                            }

                            session_regenerate_id(true);
                            unset($_SESSION['rg_csrf']);
                            rg_cookie_set('', time() - 42000);

                            $_SESSION['user_id']  = $new_id;
                            $_SESSION['accounts'] = [$new_id];
                            $ls = $conn->prepare("SELECT linked_user_id FROM linked_accounts WHERE primary_user_id = ?");
                            $ls->bind_param('i', $new_id);
                            $ls->execute();
                            $lr = $ls->get_result();
                            while ($lr && ($r = $lr->fetch_assoc())) $_SESSION['accounts'][] = (int)$r['linked_user_id'];
                            $ls->close();
                            $_SESSION['accounts'] = array_values(array_unique($_SESSION['accounts']));

                            header('Location: profile.php', true, 303);
                            exit();
                        }

                        // lost a race on a duplicate, or the database failed
                        if (rg_exists($conn, 'username', $username))      $message = 'Username taken.';
                        elseif (rg_exists($conn, 'email', $email))        $message = 'Email already registered.';
                        else                                              $message = 'Something went wrong. Please try again.';
                    }
                }
            }
        }
    }
}

if (!rg_token_usable()) rg_issue();
$csrf_token = $_SESSION['rg_csrf']['token'];
$today      = date('Y-m-d');
$e = function (string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<meta name="theme-color" content="#070924">
<title>Register | Dezzme</title>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Bebas+Neue&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<!-- without JavaScript, show every field (the "See more" button needs JS) -->
<noscript><style nonce="<?php echo $nonce; ?>">.more-fields{display:block !important}.more-btn{display:none !important}</style></noscript>

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
    width:100%; max-width:480px;
    margin:auto 0;
    display:flex; flex-direction:column; gap:14px; align-items:stretch;
}
.panel{
    min-width:0;
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
    line-height:1.65; text-align:center; margin:0 auto 20px; max-width:340px;
}

/* ── Section dividers inside the form ── */
.sec{
    display:flex; align-items:center; gap:10px;
    font-size:.58rem; font-weight:700; text-transform:uppercase; letter-spacing:1.4px;
    color:var(--muted); margin:16px 0 10px;
}
.sec:first-of-type{ margin-top:2px; }
.sec::after{ content:''; flex:1; height:1px; background:var(--line); }

/* ── Form ── */
.form-group{ position:relative; width:100%; margin-bottom:11px; min-width:0; }
.f-label{
    display:block; margin-bottom:5px;
    font-size:.6rem; font-weight:600; letter-spacing:.3px; color:var(--sub);
}
.f-label em{ font-style:normal; color:var(--red-text); }
.f-label small{ font-size:.56rem; font-weight:500; color:var(--muted); margin-left:4px; }

.form-group input,
.form-group select,
.form-group textarea{
    width:100%; height:var(--ctl-h);
    background:var(--surface); border:1px solid var(--line2);
    border-radius:var(--r);
    padding:0 14px; font-size:clamp(.7rem, 2.4vw, .8rem); color:#fff; outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.form-group textarea{
    height:auto; min-height:92px; padding:10px 14px; line-height:1.6; resize:vertical;
}
.form-group select{
    padding-right:34px; color-scheme:dark;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%23ffffff99' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 13px center;
}
.form-group select option{ background:#0d1030; color:#fff; }
.form-group input::placeholder,
.form-group textarea::placeholder{ color:var(--muted); }
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
    border-color:rgba(255,45,45,.7); background-color:rgba(255,255,255,0.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}
.form-group input.bad{ border-color:rgba(255,45,45,.8); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
.form-group input[type="date"]{ color-scheme:dark; text-align:left; min-height:var(--ctl-h); }
.form-group input[type="date"]::-webkit-calendar-picker-indicator{ opacity:.6; cursor:pointer; }

.password-container input{ padding-right:44px; }
.toggle-pass{
    position:absolute; bottom:0; right:0; width:42px; height:var(--ctl-h);
    display:flex; align-items:center; justify-content:center;
    background:none; border:none; color:var(--muted); font-size:1.05rem;
    transition:color .2s;
}
.toggle-pass:hover{ color:#fff; }

.row2{ display:grid; grid-template-columns:1fr 1fr; gap:0 10px; }

.message{
    width:100%; margin-bottom:14px; padding:9px 12px;
    border-radius:4px; text-align:center;
    background:rgba(255,45,45,.1); border:1px solid rgba(255,45,45,.3);
    color:var(--red-text); font-size:.7rem; line-height:1.55;
}

.hint{ font-size:.62rem; color:var(--muted); line-height:1.6; margin:-3px 0 12px; text-align:center; }
.fhint{ font-size:.58rem; color:var(--muted); line-height:1.5; margin-top:5px; }
.ferr{ display:none; font-size:.62rem; color:var(--red-text); line-height:1.5; margin-top:5px; }
.ferr.show{ display:block; }
.privacy-note{ font-size:.64rem; color:var(--muted); line-height:1.6; text-align:center; margin:2px 0 12px; }
.privacy-note a{ color:var(--red-text); font-weight:600; }
.privacy-note a:hover{ color:#fff; }

/* ── Location picker (same idea as Group Events) ── */
.loc{ position:relative; }
.loc-search{ position:relative; }
.loc-search input{ padding-right:38px; }
.loc-spin{
    position:absolute; right:12px; top:50%; transform:translateY(-50%);
    width:15px; height:15px; border-radius:50%;
    border:2px solid rgba(255,255,255,.18); border-top-color:var(--red);
    display:none; animation:locSpin .7s linear infinite;
}
.loc-spin.on{ display:block; }
@keyframes locSpin{ to{ transform:translateY(-50%) rotate(360deg); } }

.loc-sugg{
    position:absolute; left:0; right:0; top:calc(100% + 6px); z-index:40;
    background:#0d1030; border:1px solid var(--line2); border-radius:var(--r);
    max-height:224px; overflow-y:auto; display:none;
    box-shadow:0 14px 30px rgba(0,0,0,.55);
}
.loc-sugg.on{ display:block; }
.loc-item{
    display:flex; align-items:flex-start; gap:9px;
    padding:9px 12px; border-bottom:1px solid var(--line);
    cursor:pointer; transition:background .15s;
}
.loc-item:last-child{ border-bottom:none; }
.loc-item:hover, .loc-item.hl{ background:rgba(255,255,255,.06); }
.loc-item i{ color:var(--red); font-size:.9rem; margin-top:2px; flex-shrink:0; }
.loc-item b{ display:block; font-size:.72rem; font-weight:600; color:#fff; line-height:1.35; }
.loc-item small{ display:block; font-size:.6rem; color:var(--muted); line-height:1.4; margin-top:1px; }
.loc-msg{ padding:12px; font-size:.68rem; color:var(--muted); text-align:center; }

.loc-picked{
    display:none; align-items:center; gap:9px;
    min-height:var(--ctl-h); padding:0 6px 0 12px; border-radius:var(--r);
    background:rgba(255,45,45,.08); border:1px solid rgba(255,45,45,.3);
    font-size:clamp(.7rem, 2.4vw, .78rem); color:#fff;
}
.loc-picked i.pin{ color:var(--red); font-size:.95rem; flex-shrink:0; }
.loc-picked span{ flex:1; min-width:0; line-height:1.35; padding:7px 0; word-break:break-word; }
.loc-picked button{
    width:30px; height:30px; flex-shrink:0; border:none; background:none;
    color:var(--muted); font-size:1rem; display:flex; align-items:center; justify-content:center;
    transition:color .2s;
}
.loc-picked button:hover{ color:#fff; }
.loc.has-value .loc-search{ display:none; }
.loc.has-value .loc-picked{ display:flex; }

/* ── "See more" button + extra fields ── */
.more-btn{
    display:flex; align-items:center; justify-content:center; gap:7px;
    width:100%; height:var(--ctl-h); margin:2px 0 12px;
    border:none; border-radius:var(--r);
    background:rgba(255,255,255,0.08);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:#fff; font-size:clamp(.7rem, 2.4vw, .8rem); font-weight:600; line-height:1;
    transition:background .2s, transform .15s;
}
.more-btn:hover{ background:rgba(255,255,255,0.16); }
.more-btn:active{ transform:scale(.98); }
.more-btn i{ font-size:1rem; transition:transform .25s; }
.more-btn[aria-expanded="true"] i{ transform:rotate(180deg); }
.more-fields{ display:none; }
.more-fields.open{ display:block; animation:pIn .3s cubic-bezier(.22,1,.36,1) both; }
.more-intro{
    font-size:.62rem; color:var(--muted); line-height:1.6; text-align:center; margin:0 0 12px;
}

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

.links{ text-align:center; font-size:.7rem; color:var(--muted); line-height:2.1; width:100%; }
.links a{ color:var(--red-text); font-weight:600; margin:0 6px; transition:color .2s; }
.links a:hover{ color:#fff; }

/* ── Account vs About Me explainer (below the card) ── */
.explain{
    background:var(--base);
    border:1px solid var(--line2);
    border-radius:8px;
    padding:clamp(16px, 4vw, 22px) var(--pad);
    box-shadow:0 2px 10px rgba(0,0,0,.15);
    animation:pIn .4s cubic-bezier(.22,1,.36,1) .08s both;
}
.explain-title{
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px;
    color:var(--muted); text-align:center; margin-bottom:4px;
}
.explain-head{
    font-size:clamp(.82rem, 3vw, .95rem); font-weight:700; text-align:center; margin-bottom:14px;
}
.ex-block{ display:flex; gap:11px; padding:12px 0; border-top:1px solid var(--line); }
.ex-ic{
    width:30px; height:30px; flex-shrink:0; border-radius:var(--r);
    background:rgba(255,45,45,.12); border:1px solid rgba(255,45,45,.3);
    display:flex; align-items:center; justify-content:center;
}
.ex-ic i{ color:var(--red-text); font-size:.95rem; }
.ex-b{ min-width:0; }
.ex-b h3{ font-size:.74rem; font-weight:700; margin-bottom:3px; }
.ex-b p{ font-size:.66rem; color:var(--sub); line-height:1.65; }
.ex-b p + p{ margin-top:5px; }
.ex-b b{ color:#fff; font-weight:600; }
.ex-foot{
    margin-top:2px; padding-top:12px; border-top:1px solid var(--line);
    font-size:.64rem; color:var(--muted); line-height:1.65; text-align:center;
}
.ex-foot b{ color:var(--red-text); font-weight:600; }

/* ── loading bars ── */
.spinner-icon{ display:inline-flex; align-items:center; justify-content:center; gap:3px; height:16px; }
.spinner-icon span{ display:block; width:3px; background:currentColor; border-radius:2px; animation:btn-bar 1s ease-in-out infinite; }
.spinner-icon span:nth-child(2){ animation-delay:.15s; }
.spinner-icon span:nth-child(3){ animation-delay:.3s; }
.spinner-icon span:nth-child(4){ animation-delay:.45s; }
@keyframes btn-bar{ 0%,100%{ height:4px; opacity:.4; } 50%{ height:10px; opacity:1; } }

/* ══════════════════════════════════════
   RESPONSIVE — wordmark pinned at the top, form in the middle of the
   screen (same as the logout and reset pages). If the content is taller
   than the screen, the page simply scrolls.
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
    .page-wrapper{ max-width:460px; margin:0 auto; gap:12px; }
    .panel{ padding:22px 14px 18px; }
    .panel-label{ font-size:.56rem; margin-bottom:5px; }
    .panel-title{ font-size:1rem; margin-bottom:5px; }
    .panel-sub{ font-size:.66rem; margin-bottom:16px; }
    .form-group{ margin-bottom:9px; }
    .btn-main{ margin-bottom:14px; }
    .links{ font-size:.66rem; line-height:2; }
    .explain{ padding:16px 14px; }
}
@media (max-width:490px){
    .panel{ padding:20px 12px 16px; }
    .panel-sub{ margin-bottom:14px; }
    .message{ font-size:.66rem; padding:8px 10px; }
    .row2{ grid-template-columns:1fr; }          /* one field per row on phones */
    .explain{ padding:14px 12px; }
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
    .panel, .explain, .more-fields.open{ animation:none; }
}
</style>
</head>
<body>

<a href="index.php" class="brand">Dezzme</a>

<div class="page-wrapper">

    <section class="panel">
        <div class="panel-label">Join Dezzme</div>
        <h1 class="panel-title">Create your account</h1>
        <p class="panel-sub">Set up your login, then tell us about you. Everything in "About you" shows in your About Me, and you can edit it anytime.</p>

        <?php if ($message): ?>
            <div class="message" role="alert"><?php echo $e($message); ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="registerForm" autocomplete="on" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf_token); ?>">

            <!-- ═════════ ACCOUNT ═════════ -->
            <div class="sec">Account</div>

            <div class="form-group">
                <input type="text" name="username" placeholder="Username"
                       value="<?php echo $e($old['username']); ?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       minlength="3" maxlength="30" required>
            </div>

            <div class="form-group">
                <input type="email" name="email" placeholder="Email address"
                       value="<?php echo $e($old['email']); ?>"
                       autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="254" required>
            </div>

            <div class="form-group">
                <input type="tel" name="phone" placeholder="Phone number (optional)"
                       value="<?php echo $e($old['phone']); ?>"
                       autocomplete="tel" maxlength="20">
            </div>

            <!-- ═════════ ABOUT YOU ═════════ -->
            <div class="sec">About you</div>

            <div class="form-group">
                <label class="f-label" for="dob">Date of birth <em>*</em></label>
                <input type="date" id="dob" name="date_of_birth"
                       value="<?php echo $e($old['dob']); ?>"
                       min="1900-01-01" max="<?php echo $e($today); ?>"
                       autocomplete="bday" required>
            </div>

            <div class="form-group">
                <label class="f-label" for="loc_cur_search">Current location <em>*</em></label>
                <div class="loc<?php echo $old['location'] !== '' ? ' has-value' : ''; ?>" id="loc_cur" data-required="1">
                    <div class="loc-search">
                        <input type="text" id="loc_cur_search" placeholder="Search your city, state or country…" autocomplete="off" maxlength="100">
                        <div class="loc-spin" id="loc_cur_spin"></div>
                        <div class="loc-sugg" id="loc_cur_sugg"></div>
                    </div>
                    <div class="loc-picked" id="loc_cur_picked">
                        <i class="ri-map-pin-fill pin"></i>
                        <span id="loc_cur_text"><?php echo $e($old['location']); ?></span>
                        <button type="button" id="loc_cur_clear" aria-label="Clear location"><i class="ri-close-line"></i></button>
                    </div>
                    <input type="hidden" name="current_location" id="loc_cur_val" value="<?php echo $e($old['location']); ?>">
                </div>
                <div class="fhint">Start typing, then tap a result to pick it.</div>
                <div class="ferr" id="loc_cur_err">Please pick your location from the search results.</div>
            </div>

            <button type="button" class="more-btn" id="moreBtn" aria-expanded="<?php echo $more_open ? 'true' : 'false'; ?>" aria-controls="moreFields">
                <span id="moreLabel"><?php echo $more_open ? 'See less' : 'See more about you'; ?></span>
                <i class="ri-arrow-down-s-line"></i>
            </button>

            <div class="more-fields<?php echo $more_open ? ' open' : ''; ?>" id="moreFields">
                <p class="more-intro">All optional. Add as much or as little as you like.</p>

                <div class="row2">
                    <div class="form-group">
                        <label class="f-label" for="full_name">Full name <small>Optional</small></label>
                        <input type="text" id="full_name" name="full_name" placeholder="Your full name"
                               value="<?php echo $e($old['full_name']); ?>" autocomplete="name" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="f-label" for="nickname">Nickname <small>Optional</small></label>
                        <input type="text" id="nickname" name="nickname" placeholder="What people call you"
                               value="<?php echo $e($old['nickname']); ?>" autocomplete="nickname" maxlength="50">
                    </div>
                </div>

                <div class="row2">
                    <div class="form-group">
                        <label class="f-label" for="occ">Occupation <small>Optional</small></label>
                        <input type="text" id="occ" name="occupation" placeholder="What do you do?"
                               value="<?php echo $e($old['occupation']); ?>" autocomplete="organization-title" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="f-label" for="edu">Education <small>Optional</small></label>
                        <input type="text" id="edu" name="education" placeholder="School / field of study"
                               value="<?php echo $e($old['education']); ?>" autocomplete="off" maxlength="150">
                    </div>
                </div>

                <div class="form-group">
                    <label class="f-label" for="loc_home_search">Hometown <small>Optional</small></label>
                    <div class="loc<?php echo $old['hometown'] !== '' ? ' has-value' : ''; ?>" id="loc_home">
                        <div class="loc-search">
                            <input type="text" id="loc_home_search" placeholder="Where you're from…" autocomplete="off" maxlength="100">
                            <div class="loc-spin" id="loc_home_spin"></div>
                            <div class="loc-sugg" id="loc_home_sugg"></div>
                        </div>
                        <div class="loc-picked" id="loc_home_picked">
                            <i class="ri-map-pin-fill pin"></i>
                            <span id="loc_home_text"><?php echo $e($old['hometown']); ?></span>
                            <button type="button" id="loc_home_clear" aria-label="Clear hometown"><i class="ri-close-line"></i></button>
                        </div>
                        <input type="hidden" name="hometown" id="loc_home_val" value="<?php echo $e($old['hometown']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="f-label" for="rel">Relationship status <small>Optional</small></label>
                    <select id="rel" name="relationship_status">
                        <option value="">Prefer not to say</option>
                        <?php foreach ($REL_OPTIONS as $opt): ?>
                            <option value="<?php echo $e($opt); ?>"<?php echo $old['relationship'] === $opt ? ' selected' : ''; ?>><?php echo $e($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row2">
                    <div class="form-group">
                        <label class="f-label" for="site">Website <small>Optional</small></label>
                        <input type="text" id="site" name="website" placeholder="yourwebsite.com"
                               value="<?php echo $e($old['website']); ?>" autocomplete="url" autocapitalize="none" spellcheck="false" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label class="f-label" for="int">Interests <small>Optional</small></label>
                        <input type="text" id="int" name="interests" placeholder="music, art, gaming"
                               value="<?php echo $e($old['interests']); ?>" autocomplete="off" maxlength="255">
                    </div>
                </div>

                <div class="form-group">
                    <label class="f-label" for="desc">Description <small>Optional</small></label>
                    <textarea id="desc" name="description" rows="4" maxlength="1000"
                              placeholder="Tell people more about yourself..."><?php echo $e($old['description']); ?></textarea>
                </div>
            </div>

            <!-- ═════════ SECURITY ═════════ -->
            <div class="sec">Security</div>

            <div class="form-group password-container">
                <input type="password" id="password" name="password" placeholder="Password"
                       autocomplete="new-password" maxlength="1024" minlength="<?php echo RG_MIN_PASS; ?>" required>
                <button type="button" class="toggle-pass" data-target="password" aria-label="Show password">
                    <i class="ri-eye-line"></i>
                </button>
            </div>

            <div class="form-group password-container">
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password"
                       autocomplete="new-password" maxlength="1024" minlength="<?php echo RG_MIN_PASS; ?>" required>
                <button type="button" class="toggle-pass" data-target="confirm_password" aria-label="Show password">
                    <i class="ri-eye-line"></i>
                </button>
            </div>

            <p class="hint">Use at least <?php echo RG_MIN_PASS; ?> characters.</p>

            <p class="privacy-note">
                By registering you agree to our <a href="dezzme_policy.php">Privacy Policy</a>
            </p>

            <button type="submit" name="register" value="1" class="btn-main" id="registerBtn">Register</button>
        </form>

        <div class="links">
            <p>Already have an account? <a href="login.php">Login</a></p>
        </div>
    </section>

    <!-- ═════════ ACCOUNT vs ABOUT ME ═════════ -->
    <aside class="explain" aria-label="How Account and About Me are different">
        <div class="explain-title">Good to know</div>
        <div class="explain-head">Account vs About Me</div>

        <div class="ex-block">
            <div class="ex-ic"><i class="ri-shield-user-line"></i></div>
            <div class="ex-b">
                <h3>Account: how you sign in</h3>
                <p>Your <b>username</b> is your unique handle on Dezzme. No one else can have it, and it's what people search for and what you can log in with.</p>
                <p>Your <b>email, phone and password</b> keep your account secure and let you recover it. They are never shown in your About Me.</p>
            </div>
        </div>

        <div class="ex-block">
            <div class="ex-ic"><i class="ri-profile-line"></i></div>
            <div class="ex-b">
                <h3>About Me: who you are</h3>
                <p>Your <b>full name</b> is your real name. It isn't unique, so many people can share it, and you can't log in with it.</p>
                <p>Your <b>nickname</b> is what friends actually call you, like "Dee". It can be anything and isn't unique either.</p>
                <p>The rest, like occupation, education, hometown, website, interests and description, tells your followers more about you.</p>
            </div>
        </div>

        <div class="ex-foot">
            Only <b>date of birth</b> and <b>current location</b> are required. Everything else is optional, and you can add or change it later in About Me.
        </div>
    </aside>

</div>

<script nonce="<?php echo $nonce; ?>">
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

// ── "See more about you" ──
(function(){
    var btn = document.getElementById('moreBtn');
    var box = document.getElementById('moreFields');
    var lbl = document.getElementById('moreLabel');
    if (!btn || !box) return;
    btn.addEventListener('click', function(){
        var open = box.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        lbl.textContent = open ? 'See less' : 'See more about you';
    });
})();

// ── Location pickers: worldwide search with OpenStreetMap (same service as Group Events) ──
function dzInitLocation(id){
    var wrap   = document.getElementById(id);
    var input  = document.getElementById(id + '_search');
    var box    = document.getElementById(id + '_sugg');
    var spin   = document.getElementById(id + '_spin');
    var txt    = document.getElementById(id + '_text');
    var hid    = document.getElementById(id + '_val');
    var clear  = document.getElementById(id + '_clear');
    var err    = document.getElementById(id + '_err');
    if (!wrap || !input) return;

    var timer = null, reqId = 0, items = [], hl = -1;

    // short, tidy label: "Place, City, State, Country" (max 100 characters)
    function label(p){
        var a = p.address || {};
        var city = a.city || a.town || a.village || a.municipality || a.suburb || a.county || '';
        var first = p.name || a.road || '';
        var parts = [first, city, a.state || '', a.country || ''].filter(function(x, i, arr){
            return x && arr.indexOf(x) === i;
        });
        var s = parts.length ? parts.join(', ') : (p.display_name || '');
        return s.length > 100 ? s.slice(0, 99) + '\u2026' : s;
    }

    function hideBox(){ box.classList.remove('on'); box.innerHTML = ''; items = []; hl = -1; }

    function render(list){
        box.innerHTML = ''; items = list || []; hl = -1;
        if (!items.length) {
            var m = document.createElement('div');
            m.className = 'loc-msg'; m.textContent = 'No matching places found';
            box.appendChild(m); box.classList.add('on'); return;
        }
        items.forEach(function(p, i){
            var row = document.createElement('div');
            row.className = 'loc-item';
            var ic = document.createElement('i'); ic.className = 'ri-map-pin-2-fill';
            var wrapTx = document.createElement('div');
            var b = document.createElement('b'); b.textContent = label(p);
            var s = document.createElement('small'); s.textContent = p.display_name || '';
            wrapTx.appendChild(b); wrapTx.appendChild(s);
            row.appendChild(ic); row.appendChild(wrapTx);
            row.addEventListener('mousedown', function(ev){ ev.preventDefault(); });
            row.addEventListener('click', function(){ pick(i); });
            box.appendChild(row);
        });
        box.classList.add('on');
    }

    function pick(i){
        var p = items[i]; if (!p) return;
        var v = label(p);
        hid.value = v; txt.textContent = v;
        wrap.classList.add('has-value');
        input.value = ''; input.classList.remove('bad');
        if (err) err.classList.remove('show');
        hideBox();
    }

    function setHl(n){
        var rows = box.querySelectorAll('.loc-item');
        if (!rows.length) return;
        hl = (n + rows.length) % rows.length;
        rows.forEach(function(r, k){ r.classList.toggle('hl', k === hl); });
        rows[hl].scrollIntoView({ block:'nearest' });
    }

    clear.addEventListener('click', function(){
        hid.value = ''; txt.textContent = '';
        wrap.classList.remove('has-value');
        input.focus();
    });

    input.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();                       // never submit the form from the search box
            if (items.length) pick(hl >= 0 ? hl : 0);
        } else if (e.key === 'ArrowDown') { e.preventDefault(); setHl(hl + 1); }
        else if (e.key === 'ArrowUp')     { e.preventDefault(); setHl(hl - 1); }
        else if (e.key === 'Escape')      { hideBox(); }
    });

    input.addEventListener('input', function(){
        var q = input.value.trim();
        clearTimeout(timer);
        input.classList.remove('bad');
        if (err) err.classList.remove('show');
        if (q.length < 3) { hideBox(); spin.classList.remove('on'); return; }
        spin.classList.add('on');
        timer = setTimeout(function(){
            var my = ++reqId;
            var lang = encodeURIComponent((navigator.language || 'en').split('-')[0]);
            var url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=6&accept-language=' + lang + '&q=' + encodeURIComponent(q);
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function(r){ return r.json(); })
                .then(function(data){ if (my !== reqId) return; spin.classList.remove('on'); render(data); })
                .catch(function(){
                    if (my !== reqId) return;
                    spin.classList.remove('on');
                    box.innerHTML = '<div class="loc-msg">Search failed. Try again.</div>';
                    box.classList.add('on');
                });
        }, 450);
    });

    document.addEventListener('click', function(e){
        if (!wrap.contains(e.target)) hideBox();
    });
}
dzInitLocation('loc_cur');
dzInitLocation('loc_home');

// ── Submit: current location must be picked, loading bars, no double submits ──
(function(){
    var f = document.getElementById('registerForm');
    if (!f) return;
    var sent = false;
    f.addEventListener('submit', function(e){
        if (sent){ e.preventDefault(); return; }

        var curVal = document.getElementById('loc_cur_val');
        if (curVal && !curVal.value.trim()) {
            e.preventDefault();
            var si = document.getElementById('loc_cur_search');
            var er = document.getElementById('loc_cur_err');
            if (si) { si.classList.add('bad'); si.focus(); }
            if (er) er.classList.add('show');
            return;
        }

        if (!f.checkValidity()) return;
        sent = true;
        var b = document.getElementById('registerBtn');
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