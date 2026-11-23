<?php
/* ══════════════════════════════════════════════════════════════
   profile_verified.php  –  Dezzme Verified (full page)
   Flow: offer → plans → your details (ID + selfie) → pay & upload receipt → done.
   Requests are approved by an admin in admin_verification.php.
══════════════════════════════════════════════════════════════ */
$IS_HTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $IS_HTTPS, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
include 'config.php';
require_once __DIR__ . '/verification_common.php';

$nonce = base64_encode(random_bytes(16));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header(
    "Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; script-src-attr 'unsafe-inline'; " .
    "style-src 'nonce-$nonce' https://fonts.googleapis.com https://cdn.jsdelivr.net; style-src-attr 'unsafe-inline'; " .
    "font-src https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: blob: https:; connect-src 'self'; " .
    "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
);

$me = (int)($_SESSION['user_id'] ?? 0);
if ($me <= 0) { header('Location: login.php'); exit; }

if (empty($_SESSION['st_csrf']) || !is_string($_SESSION['st_csrf'])) $_SESSION['st_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['st_csrf'];

vf_ensure_schema($conn);
vf_sweep_username_changes($conn);   /* drops badges whose username changed after approval */

/* ───────── data ───────── */
$st = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$st->bind_param('i', $me); $st->execute();
$user = $st->get_result()->fetch_assoc(); $st->close();
if (!$user) { echo 'User not found.'; exit; }

$bio = '';
try {
    $st = $conn->prepare("SELECT Bio FROM user_settings WHERE user_id = ? LIMIT 1");
    $st->bind_param('i', $me); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    $bio = trim((string)($r['Bio'] ?? ''));
} catch (Throwable $e) {}

$has_pic = vf_has_pic($user['profile_pic'] ?? '');
$has_bio = $bio !== '';
$theme = (($user['theme'] ?? 'dark') === 'light') ? 'light' : 'dark';   /* the user's saved theme (users.theme) */

/* ───────── submit request (JSON, multipart) ───────── */
if (isset($_POST['submit_verification'])) {
    header('Content-Type: application/json');
    $fail = function ($m, $code = 200) { http_response_code($code); echo json_encode(['success' => false, 'msg' => $m]); exit; };
    $sfs  = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (($sfs !== '' && !in_array($sfs, ['same-origin', 'none'], true)) || !is_string($sent) || !hash_equals($csrf, $sent)) {
        $fail('This page expired. Reload it and try again.', 403);
    }
    if (!empty($user['is_verified'])) $fail('You are already verified.');

    $plan  = $_POST['plan'] ?? '';
    $name  = trim((string)($_POST['full_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $idt   = $_POST['id_type'] ?? '';
    $payer = trim((string)($_POST['payer_name'] ?? ''));
    $age   = ($_POST['age_confirmed'] ?? '') === '1';

    if (!isset(VF_PLANS[$plan]) || !isset(VF_ID_TYPES[$idt]) || !$age) $fail('Invalid request.');
    if (mb_strlen($name) < 3 || mb_strlen($name) > 120)  $fail('Enter your full legal name.');
    if (!preg_match('/^[0-9+\s\-]{7,20}$/', $phone))      $fail('Enter a valid phone number.');
    if (mb_strlen($payer) < 3 || mb_strlen($payer) > 120) $fail("Enter the name on the account you paid from.");
    if (!$has_pic || !$has_bio) $fail('Add a profile picture and a bio before applying.');

    $st = $conn->prepare("SELECT id FROM verification_requests WHERE user_id=? AND status='pending' LIMIT 1");
    $st->bind_param('i', $me); $st->execute();
    $dup = $st->get_result()->num_rows > 0; $st->close();
    if ($dup) $fail('You already have a pending request.');

    $err = null; $idf = $self = $rec = null;
    $idf  = vf_save_upload('id_file', false, 'id', $err);
    $self = $err ? null : vf_save_upload('selfie', false, 'selfie', $err);
    $rec  = $err ? null : vf_save_upload('receipt', true, 'receipt', $err);
    if ($err) { vf_delete_file($idf); vf_delete_file($self); vf_delete_file($rec); $fail($err); }

    $amount = VF_PLANS[$plan]['price'];           /* price always comes from the server */
    $ref    = 'DZV-' . $me;
    $st = $conn->prepare("INSERT INTO verification_requests (user_id, plan, payment_method, age_confirmed, amount, full_name, phone, id_type, id_file, selfie_file, receipt_file, payer_name, payment_ref) VALUES (?,?, 'transfer',1,?,?,?,?,?,?,?,?,?)");
    $st->bind_param('isissssssss', $me, $plan, $amount, $name, $phone, $idt, $idf, $self, $rec, $payer, $ref);
    $ok = $st->execute(); $st->close();
    if (!$ok) { vf_delete_file($idf); vf_delete_file($self); vf_delete_file($rec); $fail('Could not save your request.'); }
    echo json_encode(['success' => true, 'msg' => '']); exit;
}

$tracked_count = 0; $tracked_pics = [];
try {
    $q = $conn->query("SELECT COUNT(*) AS c FROM followers f JOIN users u ON u.id = f.following_id WHERE f.follower_id = $me AND u.is_verified = 1");
    if ($q) $tracked_count = (int)($q->fetch_assoc()['c'] ?? 0);
    if ($tracked_count > 0) {
        $q2 = $conn->query("SELECT u.profile_pic FROM followers f JOIN users u ON u.id = f.following_id WHERE f.follower_id = $me AND u.is_verified = 1 LIMIT 3");
        if ($q2) while ($r = $q2->fetch_assoc()) $tracked_pics[] = $r['profile_pic'] ?: 'uploads/default.jpg';
    }
} catch (Throwable $e) {}

$last = null;
try {
    $q = $conn->query("SELECT status, plan, reject_reason, created_at FROM verification_requests WHERE user_id = $me ORDER BY id DESC LIMIT 1");
    if ($q) $last = $q->fetch_assoc() ?: null;
} catch (Throwable $e) {}

$is_verified  = !empty($user['is_verified']);
$is_pending   = !$is_verified && $last && $last['status'] === 'pending';
$was_rejected = !$is_verified && $last && in_array($last['status'], ['rejected', 'revoked'], true);
$show_rejected = $was_rejected && empty($_GET['apply']);
$uname = (string)($user['username'] ?? '');
$pic   = $user['profile_pic'] ?: 'uploads/default.jpg';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$prices = array_map(fn($p) => $p['price'], VF_PLANS);
$ref = 'DZV-' . $me;
$status_rows = '';
if ($last && (!$is_verified || $last['status'] === 'approved')) {
    $status_rows = '<div class="pv-sum" style="margin-top:6px"><div class="pv-sum-row"><span>Plan</span><b>' . $h(ucfirst($last['plan'])) . '</b></div>' .
        '<div class="pv-sum-row"><span>Submitted</span><b>' . $h(date('j M Y', strtotime($last['created_at']))) . '</b></div></div>';
}

/* Flower badge. $sym: 'r' = filled red · 'f' = filled (text colour) · 'o' = outline (text colour) */
function vb($sym, $cls = '') {
    return '<svg class="vbadge ' . $cls . '" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="#dz-flower-' . $sym . '"/></svg>';
}
?>
<!DOCTYPE html>
<html lang="en"<?= $theme === 'light' ? ' data-theme="light"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= $theme === 'light' ? '#ffffff' : '#070924' ?>">
<title>Dezzme · Verified</title>
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style nonce="<?= $nonce ?>">
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body,button,input,select{font-family:'Poppins',sans-serif}
button,a{cursor:pointer;outline:none;-webkit-tap-highlight-color:transparent}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
[class^="ri-"],[class*=" ri-"],[class^="ri-"]::before,[class*=" ri-"]::before{font-family:'remixicon' !important;font-style:normal;font-weight:normal;line-height:1;speak:none;text-transform:none;-webkit-font-smoothing:antialiased}

:root{
  --accent:#FF2D2D; --accent-hover:#ff1a1a; --accent-soft:rgba(255,45,45,.14); --accent-border:rgba(255,45,45,.4); --accent-text:#ff8a8a;
  --on-accent:#fff; --fg:255,255,255; --ui-text:#fff; --text:#fff;
  --muted:rgba(var(--fg),.45); --sub:rgba(var(--fg),.64);
  --surface:rgba(var(--fg),.05); --border:rgba(var(--fg),.16); --line:rgba(var(--fg),.1);
  --solid:#070924; --ph:#15171f; --ok:#4ade80;
  --purple:#8b3dff; --bounce:cubic-bezier(.34,1.56,.64,1);
  --bg:linear-gradient(-45deg,#010218,#070924,#070924);
  --r:10px; --pad:clamp(14px,4vw,22px);
}
:root[data-theme="light"]{
  color-scheme:light;
  --fg:6,7,26; --ui-text:#0b0d24; --text:#0b0d24;
  --muted:rgba(6,7,26,.52); --sub:rgba(6,7,26,.68);
  --surface:rgba(6,7,26,.045); --border:rgba(6,7,26,.2); --line:rgba(6,7,26,.1);
  --solid:#ffffff; --ph:#eceef5; --accent-text:#d42525; --ok:#16a34a;
  --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
}
body{background:var(--bg);background-attachment:fixed;color:var(--text);min-height:100vh;font-size:14px;-webkit-font-smoothing:antialiased;overflow-x:hidden}

.vbadge{width:1em;height:1em;flex-shrink:0;display:inline-block;vertical-align:-.15em;color:var(--ui-text)}
.vb-sprite{position:absolute;width:0;height:0;overflow:hidden}

@keyframes wordIn{0%{opacity:0;transform:translateY(.7em) rotate(4deg) scale(.8)}60%{opacity:1;transform:translateY(-.12em) rotate(-1deg) scale(1.04)}100%{opacity:1;transform:none}}
@keyframes wordSweep{0%{color:var(--accent)}22%{color:var(--accent)}38%{color:var(--purple)}80%{color:var(--purple)}100%{color:var(--ui-text)}}
@keyframes mosaicSweep{0%,44%{opacity:0;background-position:0 0}56%{opacity:1}78%{opacity:1}96%,100%{opacity:0;background-position:16px 8px}}
.pv-h .w{position:relative;display:inline-block;white-space:pre;animation:wordIn .7s var(--bounce) both,wordSweep 2.4s ease both;animation-delay:var(--d,0ms)}
.pv-h .w::after{content:attr(data-w);position:absolute;left:0;top:0;width:100%;height:100%;white-space:pre;pointer-events:none;background:repeating-conic-gradient(#FF2D2D 0 25%,#8b3dff 0 50%,#ff6fb1 0 75%,#3b1d8f 0 100%);background-size:8px 8px;-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent;opacity:0;animation:mosaicSweep 2.4s linear both;animation-delay:var(--d,0ms)}
@media (prefers-reduced-motion:reduce){.pv-h .w,.pv-h .w::after{animation:none !important}.pv-h .w::after{opacity:0}}

.pv-top{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:2px;height:56px;padding:0 10px;background:var(--solid);border-bottom:1px solid var(--line)}
@media (max-width:490px){.pv-top{height:52px}}
@supports (backdrop-filter:blur(1px)){.pv-top{background:rgba(7,9,36,.82);backdrop-filter:blur(18px)}:root[data-theme="light"] .pv-top{background:rgba(255,255,255,.86)}}
.pv-back{width:38px;height:38px;color:var(--ui-text);display:flex;align-items:center;justify-content:center;opacity:.92;transition:opacity .2s,color .2s;flex-shrink:0;border:none;background:none}
.pv-back:hover{opacity:1;color:var(--accent)}
.pv-back{width:auto;min-width:38px;padding:6px 6px 6px 2px;box-sizing:border-box}
.pv-back .pv-back-svg{height:calc((18px + 4 * clamp(0px, calc((100vw - 330px) / 460), 1px)) * 1.155);width:auto;aspect-ratio:14 / 24;display:block;overflow:visible}
.pv-title{display:flex;align-items:center;gap:7px;font-size:1rem;font-weight:700;color:var(--ui-text);margin-left:10px}

.pv-wrap{max-width:1040px;margin:0 auto;padding:22px var(--pad) 120px;display:grid;grid-template-columns:1fr;gap:20px}
.pv-aside{display:none}
.pv-card{background:var(--surface);border:1px solid var(--line);border-radius:14px;overflow:hidden}
@media (min-width:900px){
  .pv-wrap{grid-template-columns:340px minmax(0,1fr);gap:28px;padding-top:34px;padding-bottom:60px;align-items:start}
  .pv-aside{display:block;position:sticky;top:90px}
  .mob-only{display:none !important}
}

.pv-progress{display:flex;gap:6px;padding:14px clamp(16px,4vw,30px) 0}
.pv-seg{flex:1;height:4px;border-radius:4px;background:rgba(var(--fg),.16);position:relative;overflow:hidden}
.pv-seg::after{content:'';position:absolute;inset:0;background:var(--accent);transform:scaleX(0);transform-origin:left;transition:transform .4s cubic-bezier(.4,0,.2,1)}
.pv-seg.on::after{transform:scaleX(1)}
.pv-body{padding:22px clamp(16px,4vw,30px) 26px}

.pv-me{display:flex;flex-direction:column;align-items:center;gap:8px;text-align:center}
.pv-avatar{position:relative;width:84px;height:84px}
.pv-avatar img{width:100%;height:100%;border-radius:50%;object-fit:cover;background:var(--ph);border:3px solid var(--accent)}
.pv-ov{position:absolute;right:-3px;bottom:-3px;width:28px;height:28px;border-radius:50%;background:var(--solid);display:grid;place-items:center}
.pv-ov .vbadge{width:20px;height:20px;vertical-align:baseline}
.pv-name{display:flex;align-items:center;gap:6px;font-size:1.05rem;font-weight:700;color:var(--ui-text)}
.pv-name .vbadge{vertical-align:baseline}
.pv-tag{font-size:.78rem;color:var(--sub)}
.pv-proof{display:flex;align-items:center;gap:10px;font-size:.78rem;color:var(--sub);line-height:1.5;margin-top:14px;padding:10px 12px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface)}
.pv-stack{display:flex;flex-shrink:0}
.pv-stack img{width:28px;height:28px;border-radius:50%;object-fit:cover;border:2px solid var(--solid);margin-left:-8px}
.pv-stack img:first-child{margin-left:0}
.pv-proof b{color:var(--ui-text)}
.pv-hero{padding:22px 18px;border:1px solid var(--accent-border);border-radius:14px;background:linear-gradient(160deg,var(--accent-soft),rgba(var(--fg),.02))}

.pv-h{font-size:clamp(1.15rem,1rem + .8vw,1.5rem);font-weight:700;text-align:center;letter-spacing:-.2px;color:var(--ui-text);margin-bottom:6px}
.pv-sub{font-size:.82rem;color:var(--sub);text-align:center;line-height:1.65;margin:0 auto 18px;max-width:520px}
.pv-sec{font-size:.68rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin:24px 0 10px;display:flex;align-items:center;gap:10px}
.pv-sec::after{content:'';flex:1;height:1px;background:var(--line)}
.pv-perks{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.pv-perk{display:flex;flex-direction:column;gap:6px;padding:13px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface)}
.pv-perk i{font-size:1.3rem;color:var(--accent-text)}
.pv-perk .vbadge{width:1.3rem;height:1.3rem;vertical-align:baseline}
.pv-perk strong{font-size:.84rem;color:var(--ui-text)}
.pv-perk span{font-size:.74rem;color:var(--sub);line-height:1.55}
@media (max-width:420px){.pv-perks{grid-template-columns:1fr}.pv-perk{flex-direction:row;gap:10px}}
.pv-how{counter-reset:s;list-style:none}
.pv-how li{counter-increment:s;display:flex;gap:12px;align-items:flex-start;padding:8px 0;font-size:.82rem;color:var(--sub);line-height:1.6}
.pv-how li::before{content:counter(s);width:24px;height:24px;border-radius:50%;background:var(--accent);color:var(--on-accent);font-size:.72rem;font-weight:700;display:grid;place-items:center;flex-shrink:0}
.pv-how b{color:var(--ui-text)}

.pv-step{display:none}
.pv-step.active{display:block;animation:pvIn .35s cubic-bezier(.22,1,.36,1)}
@keyframes pvIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

.pv-plans{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;padding:3px 3px 8px;scrollbar-width:none}
.pv-plans::-webkit-scrollbar{display:none}
.pv-plan{flex:0 0 88%;scroll-snap-align:start;border:1px solid var(--border);border-radius:var(--r);padding:16px;background:var(--surface);cursor:pointer;transition:.2s}
@media (min-width:640px){.pv-plans{overflow:visible;display:grid;grid-template-columns:repeat(3,1fr)}.pv-plan{flex:none}.pv-dots{display:none !important}}
.pv-plan.sel{border-color:var(--accent);background:var(--accent-soft);box-shadow:0 0 0 1px var(--accent)}
.pv-plan-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.pv-plan h3{font-size:1.05rem;font-weight:700;color:var(--ui-text)}
.pv-radio{width:20px;height:20px;border-radius:50%;border:2px solid rgba(var(--fg),.32);flex-shrink:0;display:grid;place-items:center;transition:.2s}
.pv-plan.sel .pv-radio{border-color:var(--accent);background:var(--accent)}
.pv-plan.sel .pv-radio::after{content:'';width:7px;height:7px;border-radius:50%;background:#fff}
.pv-price{font-size:1.05rem;font-weight:700;color:var(--ui-text);margin-top:6px}
.pv-price small{font-size:.74rem;font-weight:400;color:var(--sub)}
.pv-rec{display:inline-block;margin-top:10px;padding:2px 10px;border-radius:99px;background:var(--accent-soft);border:1px solid var(--accent-border);color:var(--accent-text);font-size:.66rem;font-weight:600}
.pv-divider{height:1px;background:var(--line);margin:13px 0 0}
.pv-feat-h{font-size:.76rem;font-weight:700;color:var(--ui-text);margin:12px 0 3px}
.pv-feat{display:flex;gap:8px;align-items:flex-start;padding:3px 0;font-size:.76rem;color:var(--text);line-height:1.5}
.pv-feat i{font-size:1rem;flex-shrink:0;color:var(--accent-text)}
.pv-feat .vbadge{width:1rem;height:1rem;vertical-align:baseline;margin-top:.1rem}
.pv-feat small{display:block;color:var(--ok);font-size:.68rem;margin-top:1px}
.pv-dots{display:flex;justify-content:center;gap:6px;margin:6px 0 10px}
.pv-dot{width:6px;height:6px;border-radius:3px;background:rgba(var(--fg),.22);transition:.2s}
.pv-dot.on{background:var(--accent);width:18px}
.pv-fine{font-size:.7rem;color:var(--muted);line-height:1.6;text-align:center}

.pv-check{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)}
.pv-check .ic{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:.95rem;flex-shrink:0}
.pv-check.ok .ic{background:rgba(74,222,128,.17);color:var(--ok)}
.pv-check.todo .ic{background:rgba(var(--fg),.08);color:var(--muted)}
.pv-check div{flex:1;min-width:0}
.pv-check strong{display:block;font-size:.84rem;font-weight:600;color:var(--ui-text)}
.pv-check span{display:block;font-size:.74rem;color:var(--muted);margin-top:1px;line-height:1.5}
.pv-check a{color:var(--accent-text);font-size:.78rem;font-weight:700;flex-shrink:0}
.pv-age{display:flex;align-items:flex-start;gap:11px;margin-top:18px;font-size:.8rem;color:var(--sub);line-height:1.6;cursor:pointer}
.pv-age input{accent-color:var(--accent);width:18px;height:18px;flex-shrink:0;margin-top:1px}

/* form fields */
.pv-field{margin-top:14px}
.pv-field label.l{display:block;font-size:.76rem;font-weight:600;color:var(--ui-text);margin-bottom:6px}
.pv-field label.l em{font-style:normal;font-weight:400;color:var(--muted)}
.pv-input{width:100%;height:46px;padding:0 14px;border-radius:var(--r);border:1px solid var(--border);background:var(--surface);color:var(--ui-text);font-size:.86rem;outline:none;transition:border-color .2s}
.pv-input:focus{border-color:var(--accent)}
select.pv-input{appearance:auto}
select.pv-input option{color:#0b0d24;background:#fff}
.pv-file{position:relative;display:flex;align-items:center;gap:12px;padding:13px 14px;border:1.5px dashed var(--border);border-radius:var(--r);background:var(--surface);cursor:pointer;transition:.2s}
.pv-file:hover,.pv-file:focus-within{border-color:var(--accent)}
.pv-file.has{border-style:solid;border-color:rgba(74,222,128,.5);background:rgba(74,222,128,.07)}
.pv-file input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.pv-file i{font-size:1.4rem;color:var(--accent-text);flex-shrink:0}
.pv-file.has i{color:var(--ok)}
.pv-file div{min-width:0}
.pv-file strong{display:block;font-size:.8rem;color:var(--ui-text)}
.pv-file span{display:block;font-size:.72rem;color:var(--sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pv-lock{display:flex;gap:9px;align-items:flex-start;margin-top:18px;padding:11px 13px;border-radius:var(--r);background:var(--surface);border:1px solid var(--line);font-size:.74rem;color:var(--sub);line-height:1.6}
.pv-lock i{color:var(--ok);font-size:1rem;flex-shrink:0}

.pv-sum{border:1px solid var(--border);border-radius:var(--r);padding:0 16px;background:var(--surface)}
.pv-sum-row{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid var(--line);font-size:.82rem;color:var(--sub)}
.pv-sum-row:last-child{border-bottom:none}
.pv-sum-row b{color:var(--ui-text);text-align:right;display:flex;align-items:center;gap:8px;justify-content:flex-end}
.pv-sum-row.due b{color:var(--ok);font-size:.95rem}
.pv-copy{border:1px solid var(--border);background:none;color:var(--accent-text);border-radius:6px;font-size:.68rem;font-weight:700;padding:3px 8px}

.pv-done{text-align:center;padding:10px 0 2px}
.pv-ring{width:68px;height:68px;border-radius:50%;background:rgba(74,222,128,.14);border:1px solid rgba(74,222,128,.4);display:grid;place-items:center;margin:0 auto 14px;color:var(--ok);font-size:2rem}
.pv-ring.red{background:var(--accent-soft);border-color:var(--accent-border)}
.pv-ring.red .vbadge{width:2.1rem;height:2.1rem;vertical-align:baseline}
.pv-ring.red{color:var(--accent-text)}
.pv-pill{display:inline-block;margin-bottom:10px;padding:4px 14px;border-radius:99px;font-size:.74rem;font-weight:700}
.pv-pill.ok{background:rgba(74,222,128,.15);color:var(--ok);border:1px solid rgba(74,222,128,.4)}
.pv-pill.warn{background:rgba(255,184,77,.14);color:#e69a1f;border:1px solid rgba(255,184,77,.45)}
.pv-pill.bad{background:var(--accent-soft);color:var(--accent-text);border:1px solid var(--accent-border)}
.pv-dual{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:420px){.pv-dual{grid-template-columns:1fr}}
.pv-ring.warn{background:rgba(255,184,77,.14);border-color:rgba(255,184,77,.45);color:#e69a1f}
.pv-alert{margin:0 0 16px;padding:11px 13px;border-radius:var(--r);background:rgba(255,184,77,.12);border:1px solid rgba(255,184,77,.4);font-size:.78rem;color:var(--ui-text);line-height:1.6}

.pv-foot{padding:14px clamp(16px,4vw,30px) 20px;border-top:1px solid var(--line);text-align:center}
.pv-note{font-size:.72rem;color:var(--muted);margin-bottom:9px;line-height:1.5;min-height:16px}
.pv-cta{width:100%;height:48px;border-radius:var(--r);border:none;background:var(--accent);color:var(--on-accent);font-size:.9rem;font-weight:700;transition:background .2s,transform .15s}
.pv-cta:hover{background:var(--accent-hover)}
.pv-cta:active{transform:scale(.985)}
.pv-cta:disabled{opacity:.45;cursor:not-allowed}
.pv-linkbtn{margin-top:6px;background:none;border:none;color:var(--sub);font-size:.8rem;font-weight:600;padding:7px 14px}
.pv-linkbtn:hover{color:var(--ui-text)}
.pv-linkbtn[hidden]{display:none}
@media (max-width:899px){
  .pv-wrap{padding-bottom:0}
  .pv-card{border-radius:0;border-left:none;border-right:none;margin:0 calc(var(--pad) * -1);background:transparent}
  .pv-foot{position:sticky;bottom:0;z-index:15;background:var(--solid);padding-bottom:max(16px,env(safe-area-inset-bottom))}
}

.pv-toast{position:fixed;bottom:max(90px,env(safe-area-inset-bottom));left:50%;transform:translateX(-50%) translateY(10px);padding:10px 18px;border-radius:var(--r);background:var(--ui-text);color:var(--solid);font-size:.8rem;font-weight:500;opacity:0;pointer-events:none;transition:.25s;z-index:60;max-width:90vw;text-align:center}
.pv-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
</style>
</head>
<body>

<!-- Flower badge sprite: o = outline · f = filled (text colour, check cut out) · r = filled red with white check -->
<svg class="vb-sprite" width="0" height="0" aria-hidden="true" focusable="false">
  <defs>
    <path id="dz-fp" fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/>
    <path id="dz-ck" d="M84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/>
  </defs>
  <symbol id="dz-flower-o" viewBox="0 0 256 256">
    <path fill="none" stroke="currentColor" stroke-width="20" stroke-linejoin="round" d="M233.9 128.0L233.5 132.1L232.4 136.2L230.7 140.2L228.3 143.9L225.5 147.4L222.4 150.7L219.0 153.7L215.6 156.5L212.4 159.1L209.3 161.7L206.5 164.2L204.1 166.8L202.1 169.5L200.5 172.4L199.3 175.6L198.3 179.1L197.6 182.8L196.9 186.9L196.3 191.1L195.5 195.5L194.4 199.9L193.1 204.2L191.3 208.3L189.1 212.0L186.4 215.4L183.3 218.2L179.7 220.4L175.8 221.9L171.7 222.8L167.3 223.0L162.9 222.6L158.5 221.8L154.1 220.6L149.9 219.2L145.8 217.6L142.0 216.1L138.3 214.8L134.8 213.8L131.3 213.1L128.0 212.9L124.7 213.1L121.2 213.8L117.7 214.8L114.0 216.1L110.2 217.6L106.1 219.2L101.9 220.6L97.5 221.8L93.1 222.6L88.7 223.0L84.3 222.8L80.2 221.9L76.3 220.4L72.7 218.2L69.6 215.4L66.9 212.0L64.7 208.3L62.9 204.2L61.6 199.9L60.5 195.5L59.7 191.1L59.1 186.9L58.4 182.8L57.7 179.1L56.7 175.6L55.5 172.4L53.9 169.5L51.9 166.8L49.5 164.2L46.7 161.7L43.6 159.1L40.4 156.5L37.0 153.7L33.6 150.7L30.5 147.4L27.7 143.9L25.3 140.2L23.6 136.2L22.5 132.1L22.1 128.0L22.5 123.9L23.6 119.8L25.3 115.8L27.7 112.1L30.5 108.6L33.6 105.3L37.0 102.3L40.4 99.5L43.6 96.9L46.7 94.3L49.5 91.8L51.9 89.2L53.9 86.5L55.5 83.6L56.7 80.4L57.7 76.9L58.4 73.2L59.1 69.1L59.7 64.9L60.5 60.5L61.6 56.1L62.9 51.8L64.7 47.7L66.9 44.0L69.6 40.6L72.7 37.8L76.3 35.6L80.2 34.1L84.3 33.2L88.7 33.0L93.1 33.4L97.5 34.2L101.9 35.4L106.1 36.8L110.2 38.4L114.0 39.9L117.7 41.2L121.2 42.2L124.7 42.9L128.0 43.1L131.3 42.9L134.8 42.2L138.3 41.2L142.0 39.9L145.8 38.4L149.9 36.8L154.1 35.4L158.5 34.2L162.9 33.4L167.3 33.0L171.7 33.2L175.8 34.1L179.7 35.6L183.3 37.8L186.4 40.6L189.1 44.0L191.3 47.7L193.1 51.8L194.4 56.1L195.5 60.5L196.3 64.9L196.9 69.1L197.6 73.2L198.3 76.9L199.3 80.4L200.5 83.6L202.1 86.5L204.1 89.2L206.5 91.8L209.3 94.3L212.4 96.9L215.6 99.5L219.0 102.3L222.4 105.3L225.5 108.6L228.3 112.1L230.7 115.8L232.4 119.8L233.5 123.9Z"/>
    <path fill="none" stroke="currentColor" stroke-width="22" stroke-linecap="round" stroke-linejoin="round" d="M86 130L114 158L172 98"/>
  </symbol>
  <symbol id="dz-flower-f" viewBox="0 0 256 256"><use href="#dz-fp" fill="currentColor"/></symbol>
  <symbol id="dz-flower-r" viewBox="0 0 256 256"><use href="#dz-fp" fill="#d5302a"/><use href="#dz-ck" fill="#ffffff"/></symbol>
</svg>

<header class="pv-top">
  <button type="button" class="pv-back" id="pv-back-top" aria-label="Back">
    <svg class="pv-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linejoin="miter" stroke-linecap="butt"/></svg>
  </button>
  <div class="pv-title"><?= vb('f') ?> Dezzme Verified</div>
</header>

<main class="pv-wrap">

  <aside class="pv-aside">
    <div class="pv-hero">
      <div class="pv-me">
        <div class="pv-avatar"><img src="<?= $h($pic) ?>" onerror="this.src='uploads/default.jpg'" alt=""><span class="pv-ov"><?= vb('r') ?></span></div>
        <div class="pv-name"><?= $h($uname) ?> <?= vb('f') ?></div>
        <div class="pv-tag">Show the world it's really you.</div>
      </div>
      <?php if ($tracked_count > 0): ?>
      <div class="pv-proof">
        <div class="pv-stack"><?php foreach ($tracked_pics as $p): ?><img src="<?= $h($p) ?>" onerror="this.src='uploads/default.jpg'" alt=""><?php endforeach; ?></div>
        <span><b><?= $tracked_count ?></b> <?= $tracked_count === 1 ? 'profile that you track already has' : 'profiles that you track already have' ?> a verified badge.</span>
      </div>
      <?php endif; ?>
    </div>
  </aside>

  <section class="pv-card">
<?php if ($is_verified): ?>
    <div class="pv-body">
      <div class="pv-done">
        <div class="pv-ring red"><?= vb('r') ?></div>
        <span class="pv-pill ok">Approved</span>
        <h1 class="pv-h">You're verified</h1>
        <p class="pv-sub">Your Dezzme Verified badge shows next to your name on your profile, memes and comments.</p>
      </div>
      <?= $status_rows ?>
      <div class="pv-alert" style="margin-top:16px">Your badge is tied to your username. If you change your username, your badge is removed and you'll need to apply again.</div>
    </div>
    <div class="pv-foot"><a class="pv-cta" style="display:grid;place-items:center" href="setting.php">Back to Settings</a></div>

<?php elseif ($is_pending): ?>
    <div class="pv-body">
      <div class="pv-done">
        <div class="pv-ring warn"><i class="ri-time-line"></i></div>
        <span class="pv-pill warn">Under review</span>
        <h1 class="pv-h">We're reviewing your request</h1>
        <p class="pv-sub">We're checking your details and payment. Your badge appears once it's approved.</p>
      </div>
      <?= $status_rows ?>
      <div class="pv-sec">What happens next</div>
      <ol class="pv-how">
        <li><div><b>We check your ID and payment.</b> This can take a little while.</div></li>
        <li><div><b>Your badge appears.</b> It shows on your profile, memes and comments.</div></li>
        <li><div><b>If something's missing,</b> you'll see the reason here and can apply again.</div></li>
      </ol>
    </div>
    <div class="pv-foot"><a class="pv-cta" style="display:grid;place-items:center" href="setting.php">Back to Settings</a></div>

<?php elseif ($show_rejected): ?>
    <div class="pv-body">
      <div class="pv-done">
        <div class="pv-ring red"><i class="ri-close-line"></i></div>
        <span class="pv-pill bad">Rejected</span>
        <h1 class="pv-h"><?= $last['status'] === 'revoked' ? 'Your badge was removed' : 'Request not approved' ?></h1>
        <p class="pv-sub">You can fix the problem and apply again.</p>
      </div>
      <?php if (!empty($last['reject_reason'])): ?><div class="pv-alert"><b>Reason:</b> <?= $h($last['reject_reason']) ?></div><?php endif; ?>
      <?= $status_rows ?>
    </div>
    <div class="pv-foot">
      <a class="pv-cta" style="display:grid;place-items:center" href="?apply=1">Apply again</a>
      <a class="pv-linkbtn" style="display:inline-block" href="setting.php">Back to Settings</a>
    </div>

<?php else: ?>
    <div class="pv-progress" id="pv-progress" aria-hidden="true"><div class="pv-seg"></div><div class="pv-seg"></div><div class="pv-seg"></div><div class="pv-seg"></div><div class="pv-seg"></div></div>
    <div class="pv-body">

      <!-- STEP 1 · OFFER -->
      <section class="pv-step" data-step="1">
        <h1 class="pv-h">Get verified on Dezzme</h1>
        <p class="pv-sub">Get the badge that shows people it's really you.</p>
        <?php if ($was_rejected): ?>
        <div class="pv-alert"><b>Your last request was not approved.</b><?= !empty($last['reject_reason']) ? ' Reason: ' . $h($last['reject_reason']) : '' ?> You can fix this and apply again.</div>
        <?php endif; ?>

        <div class="mob-only">
          <div class="pv-me">
            <div class="pv-avatar"><img src="<?= $h($pic) ?>" onerror="this.src='uploads/default.jpg'" alt=""><span class="pv-ov"><?= vb('r') ?></span></div>
            <div class="pv-name"><?= $h($uname) ?> <?= vb('f') ?></div>
          </div>
          <?php if ($tracked_count > 0): ?>
          <div class="pv-proof">
            <div class="pv-stack"><?php foreach ($tracked_pics as $p): ?><img src="<?= $h($p) ?>" onerror="this.src='uploads/default.jpg'" alt=""><?php endforeach; ?></div>
            <span><b><?= $tracked_count ?></b> <?= $tracked_count === 1 ? 'profile that you track already has' : 'profiles that you track already have' ?> a verified badge.</span>
          </div>
          <?php endif; ?>
        </div>

        <div class="pv-sec">What you get</div>
        <div class="pv-perks">
          <div class="pv-perk"><?= vb('o') ?><div><strong>Verified badge</strong><span>A badge next to your name on your profile, memes and comments, so people know it's really you.</span></div></div>
          <div class="pv-perk"><i class="ri-shield-check-line"></i><div><strong>Impersonation protection</strong><span>We watch for accounts pretending to be you and act on reports faster.</span></div></div>
          <div class="pv-perk"><i class="ri-emotion-happy-line"></i><div><strong>Exclusive stickers</strong><span>Stickers and extras that only verified members can use.</span></div></div>
          <div class="pv-perk"><i class="ri-customer-service-2-line"></i><div><strong>Priority support</strong><span>Get help from the Dezzme team sooner when something goes wrong.</span></div></div>
        </div>

        <div class="pv-sec">How it works</div>
        <ol class="pv-how">
          <li><div><b>Pick a plan.</b> Standard, Plus or Elite.</div></li>
          <li><div><b>Confirm who you are.</b> Add your name, a photo of your ID and a selfie. You must be 18 or older.</div></li>
          <li><div><b>Pay and upload your receipt.</b> Pay by bank transfer, then upload your payment receipt.</div></li>
          <li><div><b>Get verified.</b> We check your details and payment, then add your badge.</div></li>
        </ol>
        <div class="pv-lock"><i class="ri-information-line"></i><span>Your badge is tied to your username. If you change your username later, the badge is removed and you'll need to apply again.</span></div>
      </section>

      <!-- STEP 2 · PLANS -->
      <section class="pv-step" data-step="2">
        <h1 class="pv-h">Choose your plan</h1>
        <p class="pv-sub">Compare the plans, then tap the one you want.</p>
        <div class="pv-plans" id="pv-plans"></div>
        <div class="pv-dots" id="pv-dots"></div>
        <p class="pv-fine">Prices are per month, per profile. Every plan includes the same verified badge.</p>
      </section>

      <!-- STEP 3 · YOUR DETAILS -->
      <section class="pv-step" data-step="3">
        <h1 class="pv-h">Confirm it's you</h1>
        <p class="pv-sub">We use these details to check your identity. A badge only goes to people who are who they say they are.</p>

        <div class="pv-check ok"><span class="ic"><i class="ri-check-line"></i></span><div><strong>Username</strong><span>@<?= $h($uname) ?></span></div></div>
        <div class="pv-check <?= $has_pic ? 'ok' : 'todo' ?>"><span class="ic"><i class="<?= $has_pic ? 'ri-check-line' : 'ri-subtract-line' ?>"></i></span><div><strong>Profile picture</strong><span><?= $has_pic ? 'Your picture is set.' : 'Add a clear picture of yourself first.' ?></span></div><?php if (!$has_pic): ?><a href="setting.php?open=account">Add</a><?php endif; ?></div>
        <div class="pv-check <?= $has_bio ? 'ok' : 'todo' ?>"><span class="ic"><i class="<?= $has_bio ? 'ri-check-line' : 'ri-subtract-line' ?>"></i></span><div><strong>Bio</strong><span><?= $has_bio ? 'Your bio is set.' : 'Tell people who you are in a line or two.' ?></span></div><?php if (!$has_bio): ?><a href="setting.php?open=account">Add</a><?php endif; ?></div>

        <div class="pv-sec">Your details</div>
        <div class="pv-field" style="margin-top:0"><label class="l" for="pv-name-in">Full legal name <em>(as on your ID)</em></label><input class="pv-input" id="pv-name-in" type="text" maxlength="120" autocomplete="name"></div>
        <div class="pv-field"><label class="l" for="pv-phone">Phone number</label><input class="pv-input" id="pv-phone" type="tel" maxlength="20" autocomplete="tel" placeholder="+234…"></div>
        <div class="pv-field"><label class="l" for="pv-idtype">ID type</label>
          <select class="pv-input" id="pv-idtype"><option value="">Choose an ID</option>
            <?php foreach (VF_ID_TYPES as $k => $v): ?><option value="<?= $h($k) ?>"><?= $h($v) ?></option><?php endforeach; ?>
          </select></div>
        <div class="pv-field"><label class="l">Photo of your ID <em>(front, all four corners visible)</em></label>
          <label class="pv-file"><i class="ri-id-card-line"></i><div><strong>Upload ID photo</strong><span class="pv-file-name">JPG, PNG or WebP · max 5 MB</span></div><input type="file" name="id_file" accept="image/jpeg,image/png,image/webp"></label></div>
        <div class="pv-field"><label class="l">Selfie <em>(your face, clearly lit)</em></label>
          <div class="pv-dual">
            <label class="pv-file"><i class="ri-camera-line"></i><div><strong>Take a selfie</strong><span class="pv-file-name">Use your camera</span></div><input type="file" name="selfie_cam" data-key="selfie" accept="image/*" capture="user"></label>
            <label class="pv-file"><i class="ri-upload-2-line"></i><div><strong>Upload a photo</strong><span class="pv-file-name">From your gallery</span></div><input type="file" name="selfie_up" data-key="selfie" accept="image/jpeg,image/png,image/webp"></label>
          </div></div>

        <label class="pv-age"><input type="checkbox" id="pv-age"><span>I confirm that I'm 18 years old or older and that these details are mine.</span></label>
        <div class="pv-lock"><i class="ri-lock-2-line"></i><span>Only the Dezzme review team can see your ID and selfie. We delete them as soon as your request has been reviewed.</span></div>
      </section>

      <!-- STEP 4 · PAY + RECEIPT -->
      <section class="pv-step" data-step="4">
        <h1 class="pv-h">Pay and upload your receipt</h1>
        <p class="pv-sub">Send the amount below by bank transfer, then upload your receipt. We approve your badge once we've confirmed the payment.</p>
        <div class="pv-sum">
          <div class="pv-sum-row"><span>Plan</span><b id="pv-s-plan"></b></div>
          <div class="pv-sum-row due"><span>Amount to pay</span><b id="pv-s-amt"></b></div>
          <div class="pv-sum-row"><span>Bank</span><b><?= $h(VF_PAY['bank']) ?></b></div>
          <div class="pv-sum-row"><span>Account name</span><b><?= $h(VF_PAY['account_name']) ?></b></div>
          <div class="pv-sum-row"><span>Account number</span><b><?= $h(VF_PAY['account_number']) ?> <button type="button" class="pv-copy" data-copy="<?= $h(VF_PAY['account_number']) ?>">Copy</button></b></div>
          <div class="pv-sum-row"><span>Narration / reference</span><b><?= $h($ref) ?> <button type="button" class="pv-copy" data-copy="<?= $h($ref) ?>">Copy</button></b></div>
        </div>
        <p class="pv-fine" style="margin-top:10px">Please put the reference in your transfer narration so we can match your payment.</p>

        <div class="pv-sec">Your receipt</div>
        <div class="pv-field" style="margin-top:0"><label class="l" for="pv-payer">Name on the account you paid from</label><input class="pv-input" id="pv-payer" type="text" maxlength="120"></div>
        <div class="pv-field"><label class="l">Payment receipt <em>(screenshot or PDF)</em></label>
          <label class="pv-file"><i class="ri-receipt-line"></i><div><strong>Upload receipt</strong><span class="pv-file-name">JPG, PNG, WebP or PDF · max 5 MB</span></div><input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf"></label></div>
      </section>

      <!-- STEP 5 · DONE -->
      <section class="pv-step" data-step="5">
        <div class="pv-done">
          <div class="pv-ring"><i class="ri-check-line"></i></div>
          <h1 class="pv-h">Request received</h1>
          <p class="pv-sub">Thanks! We'll check your details and your <b id="pv-d-plan"></b> payment, then add your badge once it's approved.</p>
        </div>
        <div class="pv-sec">What happens next</div>
        <ol class="pv-how">
          <li><div><b>We check your ID and payment.</b> This can take a little while.</div></li>
          <li><div><b>Your badge appears.</b> It shows on your profile, memes and comments.</div></li>
          <li><div><b>If something's missing,</b> you'll see the reason here and can apply again.</div></li>
        </ol>
      </section>
    </div>

    <div class="pv-foot">
      <div class="pv-note" id="pv-note"></div>
      <button type="button" class="pv-cta" id="pv-cta">Get started</button>
      <button type="button" class="pv-linkbtn" id="pv-back" hidden>Back</button>
    </div>
<?php endif; ?>
  </section>
</main>

<div class="pv-toast" id="pv-toast" role="status" aria-live="polite"></div>

<script nonce="<?= $nonce ?>">
(function () {
  var b = document.getElementById('pv-back-top');
  if (b) b.addEventListener('click', function () {
    if (document.referrer && document.referrer.indexOf(location.host) !== -1) history.back(); else location.href = 'setting.php';
  });
  document.querySelectorAll('.pv-h').forEach(function (h) {
    var words = h.textContent.trim().split(/( )/);
    h.textContent = '';
    var n = 0;
    words.forEach(function (w) {
      if (w === ' ') { h.appendChild(document.createTextNode(' ')); return; }
      if (w === '') return;
      var s = document.createElement('span');
      s.className = 'w'; s.setAttribute('data-w', w); s.textContent = w;
      s.style.setProperty('--d', Math.min(n * 60, 1000) + 'ms');
      h.appendChild(s); n++;
    });
  });
})();
</script>

<?php if (!$is_verified && !$is_pending && !$show_rejected): ?>
<script nonce="<?= $nonce ?>">
const CSRF = <?= json_encode($csrf) ?>;
const PRICES = <?= json_encode($prices) ?>;
const PROFILE_OK = <?= json_encode($has_pic && $has_bio) ?>;
const MAX_BYTES = 5 * 1024 * 1024;
const BADGE_O = '<svg class="vbadge" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="#dz-flower-o"/></svg>';
const VF_PLANS = [
  { id: 'standard', name: 'Standard', rec: true, groups: [
    { h: null, items: ['Verified badge'] },
    { h: 'Stand out', items: ['Badge on your profile, memes and comments', 'Exclusive Dezzmer stickers'] },
    { h: 'Protect your account', items: ['Impersonation protection', 'Help when you need it'] } ] },
  { id: 'plus', name: 'Plus', groups: [
    { h: null, items: ['Everything in Standard'] },
    { h: 'Maximise your reach', items: [{ t: 'Featured in suggestions', n: 'Boosted a few times each month' }, { t: 'Add profile links', n: 'Up to 5 links' }] },
    { h: 'Understand your audience', items: ['Advanced analytics', 'Full list of profile views'] },
    { h: 'Priority help', items: ['Priority support'] } ] },
  { id: 'elite', name: 'Elite', groups: [
    { h: null, items: ['Everything in Plus'] },
    { h: 'Go further', items: ['Priority review of impersonation reports', 'Early access to new features', 'Custom profile theme'] },
    { h: 'Dedicated help', items: [{ t: 'Fastest support', n: 'A direct line to the Dezzme team' }] } ] },
].map(p => Object.assign(p, { price: PRICES[p.id] }));

const VF = { step: 1, plan: 'standard', age: false, files: { id_file: null, selfie: null, receipt: null }, busy: false };
const $ = id => document.getElementById(id);
const val = id => ($(id).value || '').trim();
const money = n => 'NGN' + n.toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const plan = () => VF_PLANS.find(p => p.id === VF.plan) || VF_PLANS[0];
function toast(m) { const t = $('pv-toast'); t.textContent = m; t.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.remove('show'), 2800); }

function renderPlans() {
  const box = $('pv-plans'), dots = $('pv-dots');
  box.innerHTML = VF_PLANS.map(p => {
    const feats = p.groups.map(g => (g.h ? '<div class="pv-feat-h">' + g.h + '</div>' : '') + g.items.map(it => {
      const t = typeof it === 'string' ? it : it.t, n = typeof it === 'string' ? '' : it.n;
      const ic = (t === 'Verified badge' || t.indexOf('Everything') === 0) ? BADGE_O : '<i class="ri-check-line"></i>';
      return '<div class="pv-feat">' + ic + '<div>' + t + (n ? '<small>' + n + '</small>' : '') + '</div></div>';
    }).join('')).join('');
    return '<div class="pv-plan' + (p.id === VF.plan ? ' sel' : '') + '" data-plan="' + p.id + '">' +
      '<div class="pv-plan-top"><h3>' + p.name + '</h3><span class="pv-radio"></span></div>' +
      '<div class="pv-price">' + money(p.price) + ' <small>/month per profile</small></div>' +
      (p.rec ? '<span class="pv-rec">Recommended</span>' : '') + '<div class="pv-divider"></div>' + feats + '</div>';
  }).join('');
  dots.innerHTML = VF_PLANS.map((p, i) => '<span class="pv-dot' + (i === 0 ? ' on' : '') + '"></span>').join('');
  box.addEventListener('click', e => {
    const c = e.target.closest('.pv-plan'); if (!c) return;
    VF.plan = c.dataset.plan;
    box.querySelectorAll('.pv-plan').forEach(x => x.classList.toggle('sel', x === c));
    refresh();
  });
  box.addEventListener('scroll', () => {
    const card = box.querySelector('.pv-plan'); if (!card) return;
    const idx = Math.round(box.scrollLeft / (card.offsetWidth + 12));
    dots.querySelectorAll('.pv-dot').forEach((d, i) => d.classList.toggle('on', i === idx));
  }, { passive: true });
}
function go(n) {
  VF.step = n;
  document.querySelectorAll('.pv-step').forEach(x => x.classList.toggle('active', +x.dataset.step === n));
  document.querySelectorAll('#pv-progress .pv-seg').forEach((x, i) => x.classList.toggle('on', i < n));
  window.scrollTo({ top: 0, behavior: 'smooth' });
  refresh();
}
const detailsOk = () => PROFILE_OK && val('pv-name-in').length >= 3 && /^[0-9+\s\-]{7,20}$/.test(val('pv-phone')) &&
  !!val('pv-idtype') && !!VF.files.id_file && !!VF.files.selfie && VF.age;
const payOk = () => val('pv-payer').length >= 3 && !!VF.files.receipt;

function refresh() {
  const p = plan(), cta = $('pv-cta'), note = $('pv-note'), back = $('pv-back');
  const set = (id, v) => { const e = $(id); if (e) e.textContent = v; };
  set('pv-s-plan', p.name); set('pv-s-amt', money(p.price)); set('pv-d-plan', p.name);
  const labels = { 1: 'Get started', 2: 'Continue with ' + p.name, 3: 'Continue', 4: 'Submit for review', 5: 'Done' };
  cta.textContent = VF.busy ? 'Submitting…' : labels[VF.step];
  cta.disabled = VF.busy || (VF.step === 3 && !detailsOk()) || (VF.step === 4 && !payOk());
  const notes = {
    1: 'Dezzme Verified is available for people aged 18+.',
    2: 'You choose how and when to pay in the next steps.',
    3: PROFILE_OK ? 'Fill in every field to continue.' : 'Add a profile picture and a bio first, then come back.',
    4: 'Only upload a receipt for a payment you have made.', 5: ''
  };
  note.textContent = notes[VF.step];
  back.hidden = (VF.step === 1 || VF.step === 5 || VF.busy);
}
function submit() {
  VF.busy = true; refresh();
  const fd = new FormData();
  fd.append('submit_verification', '1'); fd.append('csrf_token', CSRF);
  fd.append('plan', VF.plan); fd.append('full_name', val('pv-name-in')); fd.append('phone', val('pv-phone'));
  fd.append('id_type', val('pv-idtype')); fd.append('payer_name', val('pv-payer')); fd.append('age_confirmed', VF.age ? 1 : 0);
  ['id_file', 'selfie', 'receipt'].forEach(k => fd.append(k, VF.files[k]));
  fetch(window.location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF }, body: fd })
    .then(r => r.json())
    .then(d => { VF.busy = false; if (d.success) go(5); else { toast(d.msg || 'Could not submit'); refresh(); } })
    .catch(() => { VF.busy = false; toast('Network error, try again'); refresh(); });
}

$('pv-cta').addEventListener('click', () => { if (VF.step < 4) go(VF.step + 1); else if (VF.step === 4) submit(); else window.location.href = 'setting.php'; });
$('pv-back').addEventListener('click', () => { if (VF.step > 1 && VF.step < 5) go(VF.step - 1); });
$('pv-age').addEventListener('change', e => { VF.age = e.target.checked; refresh(); });
['pv-name-in', 'pv-phone', 'pv-idtype', 'pv-payer'].forEach(id => { $(id).addEventListener('input', refresh); $(id).addEventListener('change', refresh); });
const DEF = new WeakMap();
const keyOf = i => i.dataset.key || i.name;
document.querySelectorAll('.pv-file input').forEach(inp => {
  const box = inp.closest('.pv-file'), nm = box.querySelector('.pv-file-name');
  DEF.set(inp, nm.textContent);
  const clear = i => { i.value = ''; const b = i.closest('.pv-file'); b.classList.remove('has'); b.querySelector('.pv-file-name').textContent = DEF.get(i); };
  inp.addEventListener('change', () => {
    const key = keyOf(inp), f = inp.files[0];
    document.querySelectorAll('.pv-file input').forEach(o => { if (o !== inp && keyOf(o) === key) clear(o); });
    if (!f) { VF.files[key] = null; clear(inp); refresh(); return; }
    if (f.size > MAX_BYTES) { toast('That file is over 5 MB.'); VF.files[key] = null; clear(inp); refresh(); return; }
    VF.files[key] = f; nm.textContent = f.name; box.classList.add('has'); refresh();
  });
});
document.querySelectorAll('.pv-copy').forEach(b => b.addEventListener('click', () => {
  const v = b.dataset.copy;
  (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(() => toast('Copied'), () => toast('Press and hold to copy: ' + v));
}));
renderPlans(); go(1);
</script>
<?php endif; ?>
</body>
</html>