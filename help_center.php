<?php
session_start();
include 'config.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) {
    header('Location: login.php');
    exit;
}

/* ─────────────────────────────────────────────────────────────
   SUPPORT TABLE (created automatically the first time)
───────────────────────────────────────────────────────────── */
$conn->query("CREATE TABLE IF NOT EXISTS support_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    center VARCHAR(20) NOT NULL,
    category VARCHAR(60) NOT NULL,
    reference VARCHAR(255) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) CHARACTER SET utf8mb4");

if (empty($_SESSION['hc_csrf'])) $_SESSION['hc_csrf'] = bin2hex(random_bytes(16));

/* ─────────────────────────────────────────────────────────────
   CATEGORIES (whitelist used by both the page and the server)
───────────────────────────────────────────────────────────── */
$CATS = [
    'help' => ['Account or login', 'Appeal a removal', 'Report a bug', 'Verification or payment', 'Ads', 'Pods', 'Something else'],
    'safety' => ['Scam or fake giveaway', 'Impersonation', 'Hacked account', 'Phishing link', 'Privacy problem', 'Fake verification offer', 'Payment fraud'],
];

/* ─────────────────────────────────────────────────────────────
   SEND A REQUEST (AJAX)
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    header('Content-Type: application/json; charset=utf-8');
    $fail = function ($m) { echo json_encode(['ok' => false, 'error' => $m]); exit; };

    if (!hash_equals($_SESSION['hc_csrf'], (string)($_POST['csrf'] ?? ''))) $fail('Session expired. Refresh the page and try again.');

    $center   = (string)($_POST['center'] ?? '');
    $category = trim((string)($_POST['category'] ?? ''));
    $ref      = trim((string)($_POST['reference'] ?? ''));
    $message  = trim((string)($_POST['message'] ?? ''));

    if (!isset($CATS[$center]) || !in_array($category, $CATS[$center], true)) $fail('Pick a topic first.');
    $len = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($len < 10)   $fail('Please tell us a little more (at least 10 characters).');
    if ($len > 2000) $fail('Your message is too long (2000 characters max).');
    if (strlen($ref) > 255) $ref = substr($ref, 0, 255);

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM support_requests WHERE user_id=? AND created_at > (NOW() - INTERVAL 1 HOUR)");
    $stmt->bind_param('i', $current_user);
    $stmt->execute();
    $recent = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($recent >= 5) $fail('You have sent a few requests already. Please wait a while before sending another.');

    $stmt = $conn->prepare("INSERT INTO support_requests (user_id, center, category, reference, message) VALUES (?,?,?,?,?)");
    $stmt->bind_param('issss', $current_user, $center, $category, $ref, $message);
    if (!$stmt->execute()) { $stmt->close(); $fail('Could not send your request. Please try again.'); }
    $new_id = (int)$stmt->insert_id;
    $stmt->close();

    echo json_encode(['ok' => true, 'id' => $new_id, 'category' => $category, 'date' => date('M j, Y')]);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   PAGE DATA
───────────────────────────────────────────────────────────── */
$u = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
$u->bind_param('i', $current_user);
$u->execute();
$username = $u->get_result()->fetch_assoc()['username'] ?? 'there';
$u->close();

$start_tab = (isset($_GET['tab']) && isset($CATS[$_GET['tab']])) ? $_GET['tab'] : 'help';

$my = ['help' => [], 'safety' => []];
$q = $conn->prepare("SELECT id, center, category, status, created_at FROM support_requests WHERE user_id=? ORDER BY id DESC LIMIT 30");
$q->bind_param('i', $current_user);
$q->execute();
$res = $q->get_result();
while ($r = $res->fetch_assoc()) {
    if (isset($my[$r['center']]) && count($my[$r['center']]) < 5) $my[$r['center']][] = $r;
}
$q->close();

$topics = [
    'help' => [
        ['Account recovery, login and password', 'help.php?tab=start'],
        ['Posting and upload problems', 'help.php?tab=posting'],
        ['Ads: review, prices and rejections', 'help.php?tab=ads'],
        ['Verification and subscriptions', 'help.php?tab=money'],
        ['Pods: roles, bans and files', 'help.php?tab=pods'],
        ['Notifications not working', 'help.php?tab=notifs'],
        ['Troubleshooting and quick fixes', 'help.php?tab=trouble'],
    ],
    'safety' => [
        ['Common scams on Dezzme', 'help.php?tab=safety'],
        ['Protect your account', 'help.php?tab=settings'],
        ['Privacy: who can see what', 'help.php?tab=privacy'],
        ['Blocking, muting and restricting', 'help.php?tab=tracking'],
        ['Fake verification offers', 'help.php?tab=money'],
        ['Reporting a post, Pod or Dezzmer', 'help.php?tab=safety'],
        ['My account was hacked', 'help.php?tab=trouble'],
    ],
];
$copy = [
    'help' => [
        'title' => 'Help Centre',
        'sub' => 'Account problems, appeals, bugs, payments and anything the guide did not answer.',
        'ph' => 'Ask a question or describe your issue…',
        'topics' => 'Featured topics',
    ],
    'safety' => [
        'title' => 'Scam and Privacy Protection Centre',
        'sub' => 'Report a scam, a fake account, a hacked account or a privacy problem.',
        'ph' => 'Describe the scam or privacy problem…',
        'topics' => 'Protect yourself',
    ],
];
$tab_names = ['help' => 'Help Centre', 'safety' => 'Scam and Privacy'];
$tab_icons = ['help' => 'ri-lifebuoy-line', 'safety' => 'ri-shield-keyhole-line'];
$status_label = ['pending' => 'Pending', 'review' => 'Under review', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Centre — Dezzme</title>
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a { text-decoration:none; color:inherit; }
[class^="ri-"], [class*=" ri-"], [class^="ri-"]::before, [class*=" ri-"]::before {
    font-family:'remixicon' !important; font-style:normal; font-weight:normal; line-height:1; speak:none; text-transform:none; -webkit-font-smoothing:antialiased;
}
:root{
    --fg:       255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text:  #ffffff;
    --red:      #FF2D2D;
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --ink:      #ffffff;
    --ink2:     rgba(var(--fg),.62);
    --ink3:     rgba(var(--fg),.45);
    --wline:    rgba(var(--fg),.09);
    --wsoft:    rgba(var(--fg),.05);
    --wsoft2:   rgba(var(--fg),.1);
    --dline:    rgba(var(--fg),.14);
    --field:    rgba(var(--fg),.05);
    --field-bd: rgba(var(--fg),.2);
    --send-off: rgba(var(--fg),.18);
    --shadow:   0 6px 22px rgba(0,0,0,.35);
    --link:     #4aa8ff;
    --err-bg:rgba(255,45,45,.12);  --err-fg:#ff8a80; --err-bd:rgba(255,45,45,.3);
    --ok-bg:rgba(74,222,128,.1);   --ok-fg:#4ade80;  --ok-bd:rgba(74,222,128,.3);
    --warn-bg:rgba(250,190,40,.1); --warn-fg:#f5c451; --warn-bd:rgba(250,190,40,.3);
    --rev-bg:rgba(74,168,255,.16); --rev-fg:#6cb8ff;
    --res-bg:rgba(74,222,128,.14); --res-fg:#4ade80;

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(12px,4vw,20px);
    --r:        12px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }
html{ scroll-behavior:smooth; }
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; }
}
body{ background:var(--bg); background-attachment:fixed; color:var(--ink); min-height:100vh; display:flex; flex-direction:column; overflow-x:hidden; -webkit-font-smoothing:antialiased; }
body.loading{ overflow:hidden; }

/* TOPBAR — grey line is its own layer so it shows on every screen size */
.hc-topbar{ position:sticky; top:0; z-index:500; width:100%; max-width:var(--max-w); margin:0 auto; height:var(--topbar-h); background:var(--bg); background-attachment:fixed; }
.hc-topbar::after{ content:''; position:absolute; left:0; right:0; bottom:0; height:1px; background:var(--dline); pointer-events:none; z-index:2; }
@media (min-width:801px){ .hc-topbar{ border-left:1px solid var(--wline); border-right:1px solid var(--wline); } }
@media (max-width:800px){ .hc-topbar{ max-width:100%; } }
.hc-topbar-in{ height:100%; display:flex; align-items:center; gap:2px; padding:0 10px; }
.hc-x{ width:34px; height:34px; background:none; border:none; color:var(--ui-text); display:flex; align-items:center; justify-content:center; opacity:.9; transition:opacity .2s,color .2s; padding:0; flex-shrink:0; }
.hc-x:hover{ opacity:1; color:var(--red); }
.hc-x svg{ width:22px; height:22px; display:block; }
.hc-title{ flex:1; min-width:0; font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-align:right; margin:0 6px 0 10px; }
@media (max-width:490px){ .hc-x{ width:30px; height:30px; } .hc-title{ font-size:.84rem; } }
@media (max-width:380px){ .hc-title{ font-size:.8rem; } }

/* CENTRE LANE — follows the theme (dark or light) */
.hc-wrap{ flex:1; display:flex; justify-content:center; }
.hc-lane{ width:100%; max-width:var(--max-w); color:var(--ink); min-height:calc(100vh - var(--topbar-h)); padding-bottom:72px; }
@media (min-width:801px){ .hc-lane{ border-left:1px solid var(--wline); border-right:1px solid var(--wline); } }
@media (max-width:800px){ .hc-lane{ max-width:100%; } }

/* reveal */
.rv{ opacity:0; }
body.ready .rv{ animation:hcIn .45s cubic-bezier(.22,1,.36,1) both; animation-delay:calc(var(--i,0) * 50ms); }
@keyframes hcIn{ from{ opacity:0; transform:translateY(8px); } to{ opacity:1; transform:none; } }
@media (prefers-reduced-motion:reduce){ body.ready .rv{ animation:none; opacity:1; } }

/* TABS */
.hc-tabs{ position:sticky; top:calc(var(--topbar-h) - 1px); z-index:400; display:grid; grid-template-columns:1fr 1fr; background:var(--bg); background-attachment:fixed; border-bottom:1px solid var(--wline); }
.hc-tab{ display:flex; align-items:center; justify-content:center; gap:7px; height:clamp(38px,10vw,44px); background:none; border:none; border-bottom:2.5px solid transparent; font-size:clamp(.66rem,2.2vw,.78rem); font-weight:600; color:var(--ink3); white-space:nowrap; transition:color .2s,border-color .2s,background .2s; padding:0 6px; }
.hc-tab i{ font-size:1rem; }
.hc-tab:hover{ background:var(--wsoft); color:var(--ink); }
.hc-tab.active{ color:var(--ui-text); border-bottom-color:var(--red); }
.hc-tab.active i{ color:var(--red); }
@media (max-width:380px){ .hc-tab{ font-size:.62rem; gap:5px; } .hc-tab i{ font-size:.9rem; } }

/* PANELS */
.hc-panel{ display:none; padding:0 var(--pad); }
.hc-panel.active{ display:block; animation:hcIn .35s cubic-bezier(.22,1,.36,1) both; }

/* HERO */
.hc-hero{ display:flex; flex-direction:column; align-items:center; text-align:center; padding:clamp(26px,8vw,44px) 0 clamp(14px,4vw,20px); }
@keyframes spin{ to{ transform:rotate(360deg); } }
.hero-ic{ width:clamp(46px,13vw,58px); height:clamp(46px,13vw,58px); border-radius:12px; background:linear-gradient(145deg,#ff5a5a,#FF2D2D 55%,#d1162f); color:#fff; display:flex; align-items:center; justify-content:center; font-size:clamp(1.4rem,4.6vw,1.8rem); margin-bottom:clamp(12px,3.6vw,16px); box-shadow:0 8px 22px rgba(255,45,45,.28); }
.hc-hero h1{ font-size:clamp(1.05rem,5vw,1.5rem); font-weight:700; line-height:1.25; letter-spacing:-.3px; color:var(--ink); }
.hc-hero h1 span{ color:var(--red); }
.hc-sub{ font-size:clamp(.66rem,2.2vw,.78rem); color:var(--ink2); line-height:1.6; margin-top:8px; max-width:420px; }

/* CATEGORY CHIPS */
.cat-label{ font-size:.58rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--ink3); margin:4px 0 8px; }
.cat-row{ display:flex; gap:6px; overflow-x:auto; scrollbar-width:none; -ms-overflow-style:none; padding-bottom:2px; margin:0 calc(var(--pad) * -1); padding-left:var(--pad); padding-right:var(--pad); }
.cat-row::-webkit-scrollbar{ display:none; }
.cat{ flex-shrink:0; height:clamp(25px,7vw,29px); padding:0 12px; background:var(--wsoft); border:1px solid var(--wline); border-radius:4px; font-size:clamp(.62rem,2vw,.7rem); font-weight:600; color:var(--ink2); white-space:nowrap; transition:background .2s,color .2s,border-color .2s,transform .15s; }
.cat:hover{ background:var(--wsoft2); color:var(--ink); }
.cat:active{ transform:scale(.97); }
.cat.active{ background:var(--red); border-color:var(--red); color:#000; }

/* COMPOSER */
.composer{ margin-top:12px; border:1.5px solid var(--field-bd); border-radius:var(--r); background:var(--field); box-shadow:var(--shadow); transition:border-color .2s,box-shadow .2s; }
.composer:focus-within{ border-color:rgba(255,45,45,.7); box-shadow:0 0 0 3px rgba(255,45,45,.12), var(--shadow); }
.cmp-row{ display:flex; align-items:flex-end; gap:10px; padding:10px 10px 10px 14px; }
.cmp-text{ flex:1; min-width:0; min-height:40px; max-height:160px; resize:none; border:none; outline:none; background:none; font-size:clamp(.74rem,2.4vw,.84rem); color:var(--ink); line-height:1.5; padding:9px 0; -webkit-appearance:none; appearance:none; }
.cmp-text::placeholder{ color:var(--ink3); }
.cmp-send{ flex-shrink:0; width:36px; height:36px; border-radius:50%; border:none; background:var(--send-off); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.1rem; transition:background .2s,transform .15s; }
.cmp-send.ready{ background:var(--red); }
.cmp-send.ready:hover{ transform:scale(1.06); }
.cmp-send:disabled{ cursor:default; }
.cmp-send.busy i{ animation:spin .8s linear infinite; }
.cmp-ref{ display:none; border-top:1px solid var(--wline); }
.hc-panel[data-center="safety"] .cmp-ref{ display:block; }
.cmp-ref input{ width:100%; height:38px; border:none; outline:none; background:none; padding:0 14px; font-size:clamp(.68rem,2.2vw,.76rem); color:var(--ink); border-radius:0 0 var(--r) var(--r); }
.cmp-ref input::placeholder{ color:var(--ink3); }
.cmp-count{ text-align:right; font-size:.58rem; color:var(--ink3); padding:0 4px; margin-top:5px; }
.cmp-msg{ display:none; margin-top:10px; padding:10px 12px; border-radius:6px; font-size:clamp(.64rem,2.1vw,.74rem); line-height:1.55; }
.cmp-msg.show{ display:block; }
.cmp-msg.err{ background:var(--err-bg); color:var(--err-fg); border:1px solid var(--err-bd); }
.cmp-msg.ok{ background:var(--ok-bg); color:var(--ok-fg); border:1px solid var(--ok-bd); }
.legal{ text-align:center; font-size:clamp(.56rem,1.8vw,.64rem); color:var(--ink3); line-height:1.6; margin:12px auto 0; max-width:420px; }
.legal a{ color:var(--ink); font-weight:600; }
.warn{ display:flex; gap:9px; align-items:flex-start; margin-top:14px; padding:10px 12px; background:var(--warn-bg); border:1px solid var(--warn-bd); border-radius:6px; font-size:clamp(.62rem,2vw,.7rem); color:var(--warn-fg); line-height:1.55; }
.warn i{ font-size:1rem; flex-shrink:0; margin-top:1px; }

/* SECTIONS */
.hc-sec{ margin-top:clamp(22px,6vw,32px); }
.hc-sec h2{ font-size:clamp(.9rem,3vw,1.05rem); font-weight:700; color:var(--ink); margin-bottom:6px; }
.topic-list{ display:flex; flex-direction:column; }
.topic{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:clamp(11px,3vw,14px) 0; border-bottom:1px solid var(--wline); font-size:clamp(.74rem,2.4vw,.86rem); font-weight:600; color:var(--link); line-height:1.4; }
.topic:last-child{ border-bottom:none; }
.topic i{ color:var(--ink3); font-size:1.05rem; flex-shrink:0; transition:transform .2s,color .2s; }
.topic:hover{ text-decoration:underline; }
.topic:hover i{ transform:translateX(3px); color:var(--link); }

.req-list{ border:1px solid var(--wline); border-radius:8px; overflow:hidden; }
.req{ display:flex; align-items:center; gap:10px; padding:11px 12px; border-bottom:1px solid var(--wline); }
.req:last-child{ border-bottom:none; }
.req-ic{ width:30px; height:30px; border-radius:6px; background:rgba(255,45,45,.1); color:var(--red); display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.95rem; }
.req-main{ flex:1; min-width:0; }
.req-cat{ font-size:clamp(.7rem,2.3vw,.8rem); font-weight:600; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.req-date{ font-size:clamp(.56rem,1.8vw,.64rem); color:var(--ink3); margin-top:1px; }
.badge{ flex-shrink:0; height:20px; padding:0 8px; display:flex; align-items:center; border-radius:4px; font-size:.58rem; font-weight:700; letter-spacing:.03em; text-transform:uppercase; background:var(--wsoft2); color:var(--ink2); }
.badge.review{ background:var(--rev-bg); color:var(--rev-fg); }
.badge.resolved{ background:var(--res-bg); color:var(--res-fg); }
.badge.dismissed{ background:var(--wsoft); color:var(--ink3); }
.req-empty{ padding:16px 12px; font-size:clamp(.64rem,2.1vw,.74rem); color:var(--ink3); text-align:center; }

.hc-foot{ margin:30px var(--pad) 0; padding-top:16px; border-top:1px solid var(--wline); text-align:center; font-size:.66rem; color:var(--ink3); line-height:1.7; }
.hc-foot a{ color:var(--link); font-weight:600; }

/* LOADER */
#page-loader{ position:fixed; inset:0; z-index:9999; background:var(--bg); background-attachment:fixed; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; opacity:1; transition:opacity .4s ease,visibility .4s ease; }
#page-loader.hide{ opacity:0; visibility:hidden; pointer-events:none; }
.frag{ position:relative; width:28px; height:28px; }
.frag i{ position:absolute; left:50%; top:0; width:2.5px; height:7px; margin-left:-1.25px; border-radius:2px; background:var(--red); transform-origin:50% 14px; transform:rotate(calc(var(--n) * 30deg)); opacity:.14; animation:fragFade 1.2s linear infinite; animation-delay:calc(var(--n) * -0.1s); }
@keyframes fragFade{ 0%{ opacity:1; } 100%{ opacity:.14; } }
.loader-label{ font-size:.64rem; font-weight:500; color:var(--ink3); letter-spacing:.02em; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Same scale as the About This Account and Help and Support pages. Kept near the end so it wins.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(13px, 4.4vw, 22px);
        --r:13px;
    }

    /* topbar */
    .hc-topbar-in{ padding:0 11px; gap:2px; }
    .hc-x{ width:37px; height:37px; }
    .hc-x svg{ width:24px; height:24px; }
    .hc-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* tabs */
    .hc-tab{ height:clamp(42px, 11vw, 48.4px); gap:7.7px; font-size:clamp(.73rem, 2.42vw, .86rem); }
    .hc-tab i{ font-size:1.1rem; }

    /* hero */
    .hc-hero{ padding:clamp(29px, 8.8vw, 48.4px) 0 clamp(15px, 4.4vw, 22px); }
    .hero-ic{ width:clamp(51px, 14.3vw, 63.8px); height:clamp(51px, 14.3vw, 63.8px); border-radius:13px; font-size:clamp(1.54rem, 5.06vw, 1.98rem); margin-bottom:clamp(13px, 4vw, 17.6px); }
    .hc-hero h1{ font-size:clamp(1.16rem, 5.5vw, 1.65rem); }
    .hc-sub{ font-size:clamp(.73rem, 2.42vw, .86rem); margin-top:8.8px; max-width:462px; }

    /* category chips */
    .cat-label{ font-size:.64rem; margin:4.4px 0 8.8px; }
    .cat-row{ gap:6.6px; }
    .cat{ height:clamp(27.5px, 7.7vw, 32px); padding:0 13px; font-size:clamp(.68rem, 2.2vw, .77rem); border-radius:4.4px; }

    /* composer */
    .composer{ margin-top:13px; }
    .cmp-row{ gap:11px; padding:11px 11px 11px 15px; }
    .cmp-text{ min-height:44px; font-size:clamp(.81rem, 2.64vw, .92rem); padding:10px 0; }
    .cmp-send{ width:40px; height:40px; font-size:1.2rem; }
    .cmp-ref input{ height:42px; padding:0 15px; font-size:clamp(.75rem, 2.42vw, .84rem); }
    .cmp-count{ font-size:.64rem; margin-top:5.5px; }
    .cmp-msg{ margin-top:11px; padding:11px 13px; border-radius:6.6px; font-size:clamp(.7rem, 2.31vw, .81rem); }
    .legal{ font-size:clamp(.62rem, 1.98vw, .7rem); margin-top:13px; max-width:462px; }
    .warn{ gap:10px; margin-top:15px; padding:11px 13px; border-radius:6.6px; font-size:clamp(.68rem, 2.2vw, .77rem); }
    .warn i{ font-size:1.1rem; }

    /* sections */
    .hc-sec{ margin-top:clamp(24px, 6.6vw, 35px); }
    .hc-sec h2{ font-size:clamp(.99rem, 3.3vw, 1.155rem); margin-bottom:6.6px; }
    .topic{ gap:11px; padding:clamp(12px, 3.3vw, 15.4px) 0; font-size:clamp(.81rem, 2.64vw, .95rem); }
    .topic i{ font-size:1.155rem; }

    .req-list{ border-radius:8.8px; }
    .req{ gap:11px; padding:12px 13px; }
    .req-ic{ width:33px; height:33px; border-radius:6.6px; font-size:1.05rem; }
    .req-cat{ font-size:clamp(.77rem, 2.53vw, .88rem); }
    .req-date{ font-size:clamp(.62rem, 1.98vw, .7rem); margin-top:1.1px; }
    .badge{ height:22px; padding:0 8.8px; font-size:.64rem; }
    .req-empty{ padding:17.6px 13px; font-size:clamp(.7rem, 2.31vw, .81rem); }

    .hc-foot{ margin-top:33px; padding-top:17.6px; font-size:.73rem; }

    /* loader */
    .frag{ width:31px; height:31px; }
    .loader-label{ font-size:.7rem; }
}
/* re-assert the smaller-screen steps after the 10% block, same as the other pages */
@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .hc-x{ width:33px; height:33px; }
    .hc-title{ font-size:.92rem; }
}
@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .hc-title{ font-size:.88rem; }
    .hc-tab{ font-size:.68rem; gap:5.5px; }
    .hc-tab i{ font-size:.99rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the About, Help, Community, Games, Algorithm and Upload pages. The red accent stays the same in both themes. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --ink:#0b0d24;
    --ink2:rgba(6,7,26,.66);
    --ink3:rgba(6,7,26,.52);
    --wline:rgba(6,7,26,.1);
    --wsoft:rgba(6,7,26,.05);
    --wsoft2:rgba(6,7,26,.09);
    --dline:rgba(6,7,26,.14);
    --field:#ffffff;
    --field-bd:#d6d9e0;
    --send-off:#d3d6dd;
    --shadow:0 6px 22px rgba(15,17,21,.06);
    --link:#0a7fd8;
    --err-bg:#fff1f1; --err-fg:#b42318; --err-bd:#ffd0d0;
    --ok-bg:#effaf3;  --ok-fg:#157a3d;  --ok-bd:#c6ecd4;
    --warn-bg:#fff8e6; --warn-fg:#7a5a05; --warn-bd:#f6e3ae;
    --rev-bg:#e8f3fd; --rev-fg:#0a6cbd;
    --res-bg:#e6f7ec; --res-fg:#157a3d;
}
</style>
<noscript><style>.rv{opacity:1 !important}#page-loader{display:none}body.loading{overflow:auto}.hc-panel{display:block}</style></noscript>
</head>
<body class="loading">

<div id="page-loader" role="status" aria-label="Loading">
    <div class="frag" aria-hidden="true"><?php for ($n = 0; $n < 12; $n++): ?><i style="--n:<?php echo $n; ?>"></i><?php endfor; ?></div>
    <div class="loader-label">Loading</div>
</div>

<header class="hc-topbar">
    <div class="hc-topbar-in">
        <button type="button" class="hc-x" onclick="hcClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="hc-title" id="hcTitle"><?php echo htmlspecialchars($copy[$start_tab]['title']); ?></span>
    </div>
</header>

<div class="hc-wrap">
<div class="hc-lane">

    <nav class="hc-tabs rv" style="--i:0;" aria-label="Support centres">
        <?php foreach ($tab_names as $id => $label): ?>
        <button type="button" class="hc-tab<?php echo $id === $start_tab ? ' active' : ''; ?>" data-tab="<?php echo $id; ?>" onclick="hcTab('<?php echo $id; ?>')"><i class="<?php echo $tab_icons[$id]; ?>"></i><span><?php echo $label; ?></span></button>
        <?php endforeach; ?>
    </nav>

    <?php foreach (['help', 'safety'] as $id): $c = $copy[$id]; ?>
    <section class="hc-panel<?php echo $id === $start_tab ? ' active' : ''; ?>" id="panel-<?php echo $id; ?>" data-center="<?php echo $id; ?>" data-title="<?php echo htmlspecialchars($c['title']); ?>">

        <div class="hc-hero rv" style="--i:1;">
            <div class="hero-ic" aria-hidden="true"><i class="<?php echo $id === 'safety' ? 'ri-shield-keyhole-fill' : 'ri-customer-service-2-fill'; ?>"></i></div>
            <h1>Hey <span><?php echo htmlspecialchars($username); ?></span>, how can we help?</h1>
            <p class="hc-sub"><?php echo htmlspecialchars($c['sub']); ?></p>
        </div>

        <div class="rv" style="--i:2;">
            <div class="cat-label">What is it about</div>
            <div class="cat-row">
                <?php foreach ($CATS[$id] as $i => $cat): ?>
                <button type="button" class="cat<?php echo $i === 0 ? ' active' : ''; ?>" onclick="hcCat(this)"><?php echo htmlspecialchars($cat); ?></button>
                <?php endforeach; ?>
            </div>

            <div class="composer">
                <div class="cmp-row">
                    <textarea class="cmp-text" rows="1" maxlength="2000" placeholder="<?php echo htmlspecialchars($c['ph']); ?>" oninput="hcInput(this)"></textarea>
                    <button type="button" class="cmp-send" aria-label="Send" onclick="hcSend(this)" disabled><i class="ri-arrow-up-line"></i></button>
                </div>
                <div class="cmp-ref"><input type="text" class="cmp-reference" maxlength="255" placeholder="Link or DZ-ID involved (optional)" autocomplete="off"></div>
            </div>
            <div class="cmp-count"><span class="cmp-n">0</span>/2000</div>
            <div class="cmp-msg" role="status"></div>

            <p class="legal">Your message goes straight to the Dezzme team. Please do not include your password. We reply through your Activity. <a href="help.php?tab=safety">Safety tips</a></p>
            <?php if ($id === 'safety'): ?>
            <div class="warn"><i class="ri-alarm-warning-line"></i><span>Dezzme staff will never ask for your password, a reset link or any payment. If someone does, report them here.</span></div>
            <?php endif; ?>
        </div>

        <div class="hc-sec rv" style="--i:3;">
            <h2><?php echo htmlspecialchars($c['topics']); ?></h2>
            <div class="topic-list">
                <?php foreach ($topics[$id] as $t): ?>
                <a class="topic" href="<?php echo htmlspecialchars($t[1]); ?>"><span><?php echo htmlspecialchars($t[0]); ?></span><i class="ri-arrow-right-s-line"></i></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="hc-sec rv" style="--i:4;">
            <h2>Your requests</h2>
            <div class="req-list" id="reqs-<?php echo $id; ?>">
                <?php if (empty($my[$id])): ?>
                <div class="req-empty">Nothing sent yet. Your requests will show here.</div>
                <?php else: foreach ($my[$id] as $r):
                    $st = isset($status_label[$r['status']]) ? $r['status'] : 'pending'; ?>
                <div class="req">
                    <div class="req-ic"><i class="<?php echo $tab_icons[$id]; ?>"></i></div>
                    <div class="req-main"><div class="req-cat"><?php echo htmlspecialchars($r['category']); ?></div><div class="req-date">#<?php echo (int)$r['id']; ?> · <?php echo date('M j, Y', strtotime($r['created_at'])); ?></div></div>
                    <span class="badge <?php echo $st; ?>"><?php echo $status_label[$st]; ?></span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

    </section>
    <?php endforeach; ?>

    <div class="hc-foot">
        Looking for guides first? Open the <a href="help.php">Help and Support Center</a>.
    </div>

</div>
</div>

<script>
var HC_CSRF = <?php echo json_encode($_SESSION['hc_csrf']); ?>;
var HC_ICONS = { help:'ri-lifebuoy-line', safety:'ri-shield-keyhole-line' };

// ── keep the theme in sync if it is switched in another tab ──
window.addEventListener('storage', function(e){
    if(e.key !== 'dz_theme') return;
    if(e.newValue === 'light') document.documentElement.setAttribute('data-theme','light');
    else document.documentElement.removeAttribute('data-theme');
});

function hcClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'help.php';
}

/* loader */
(function(){
    var el = document.getElementById('page-loader'), shownAt = Date.now(), done = false, MIN = 450;
    function reveal(){
        if (done) return; done = true;
        setTimeout(function(){
            document.body.classList.remove('loading');
            document.body.classList.add('ready');
            if (el){ el.classList.add('hide'); setTimeout(function(){ if (el.parentNode) el.remove(); }, 450); }
        }, Math.max(0, MIN - (Date.now() - shownAt)));
    }
    if (document.readyState === 'complete') reveal(); else window.addEventListener('load', reveal);
    setTimeout(reveal, 3000);
})();

/* tabs */
function hcTab(id){
    var panel = document.getElementById('panel-' + id);
    if (!panel) return;
    document.querySelectorAll('.hc-panel').forEach(function(p){ p.classList.remove('active'); });
    panel.classList.add('active');
    document.querySelectorAll('.hc-tab').forEach(function(t){ t.classList.toggle('active', t.dataset.tab === id); });
    document.getElementById('hcTitle').textContent = panel.dataset.title;
    try { history.replaceState(null, '', '?tab=' + id); } catch(e){}
    window.scrollTo({ top:0, behavior:'smooth' });
}

/* category chips */
function hcCat(btn){
    btn.parentNode.querySelectorAll('.cat').forEach(function(c){ c.classList.remove('active'); });
    btn.classList.add('active');
}

/* composer */
function hcInput(ta){
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
    var panel = ta.closest('.hc-panel');
    var len = ta.value.trim().length;
    panel.querySelector('.cmp-n').textContent = ta.value.length;
    var send = panel.querySelector('.cmp-send');
    var ok = len >= 10;
    send.classList.toggle('ready', ok);
    send.disabled = !ok;
    var m = panel.querySelector('.cmp-msg'); m.className = 'cmp-msg';
}

function hcShow(panel, type, text){
    var m = panel.querySelector('.cmp-msg');
    m.className = 'cmp-msg show ' + type;
    m.textContent = text;
}

function hcSend(btn){
    var panel = btn.closest('.hc-panel');
    var ta = panel.querySelector('.cmp-text');
    if (btn.disabled || btn.classList.contains('busy')) return;
    var cat = panel.querySelector('.cat.active');
    var body = new URLSearchParams({
        action:'send', csrf:HC_CSRF, center:panel.dataset.center,
        category: cat ? cat.textContent.trim() : '',
        reference: panel.querySelector('.cmp-reference').value,
        message: ta.value
    });
    btn.classList.add('busy'); btn.disabled = true;
    btn.innerHTML = '<i class="ri-loader-4-line"></i>';

    fetch('help_center.php', { method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body: body.toString(), credentials:'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.ok){ hcShow(panel, 'err', d.error || 'Something went wrong.'); return; }
        ta.value = ''; hcInput(ta);
        panel.querySelector('.cmp-reference').value = '';
        hcShow(panel, 'ok', 'Sent. Your request #' + d.id + ' is with the Dezzme team. We will reply in your Activity.');
        var list = document.getElementById('reqs-' + panel.dataset.center);
        var empty = list.querySelector('.req-empty'); if (empty) empty.remove();
        var row = document.createElement('div'); row.className = 'req';
        row.innerHTML = '<div class="req-ic"><i class="' + HC_ICONS[panel.dataset.center] + '"></i></div>'
            + '<div class="req-main"><div class="req-cat"></div><div class="req-date"></div></div>'
            + '<span class="badge pending">Pending</span>';
        row.querySelector('.req-cat').textContent = d.category;
        row.querySelector('.req-date').textContent = '#' + d.id + ' · ' + d.date;
        list.insertBefore(row, list.firstChild);
        while (list.children.length > 5) list.removeChild(list.lastChild);
    })
    .catch(function(){ hcShow(panel, 'err', 'Connection problem. Please try again.'); })
    .then(function(){
        btn.classList.remove('busy');
        btn.innerHTML = '<i class="ri-arrow-up-line"></i>';
        var ready = ta.value.trim().length >= 10;
        btn.classList.toggle('ready', ready); btn.disabled = !ready;
    });
}

document.querySelectorAll('.cmp-text').forEach(function(ta){
    ta.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)){ e.preventDefault(); hcSend(ta.closest('.hc-panel').querySelector('.cmp-send')); }
    });
});
</script>
</body>
</html>