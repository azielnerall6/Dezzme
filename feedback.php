<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];

// ── CSRF token (one per session) ───────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$u = $conn->prepare("SELECT username, nickname, profile_pic, user_rank FROM users WHERE id = ?");
$u->bind_param("i", $uid);
$u->execute();
$user = $u->get_result()->fetch_assoc();
$u->close();

$is_admin = ($user['user_rank'] ?? '') === 'Admin';

$success = isset($_GET['sent']);
$error   = '';

/* every colour on this page is red or a shade of red */
$categories = [
    'bug'     => ['icon' => 'ri-bug-line',          'label' => 'Bug Report',       'color' => '#ff2d2d'],
    'feature' => ['icon' => 'ri-lightbulb-line',    'label' => 'Feature Request',  'color' => '#ff6b6b'],
    'general' => ['icon' => 'ri-chat-3-line',       'label' => 'General Feedback', 'color' => '#ff8a8a'],
    'abuse'   => ['icon' => 'ri-shield-cross-line', 'label' => 'Abuse Report',     'color' => '#e01b1b'],
    'other'   => ['icon' => 'ri-more-line',         'label' => 'Other Suggestion', 'color' => '#ffa3a3'],
];

$statuses = [
    'submitted'      => ['label' => 'Submitted',      'icon' => 'ri-send-plane-line',   'color' => '#ffa3a3'],
    'under_review'   => ['label' => 'Under Review',   'icon' => 'ri-eye-line',          'color' => '#ff8a8a'],
    'planned'        => ['label' => 'Planned',        'icon' => 'ri-road-map-line',     'color' => '#ff6b6b'],
    'in_development' => ['label' => 'In Development', 'icon' => 'ri-code-s-slash-line', 'color' => '#ff4545'],
    'completed'      => ['label' => 'Completed',      'icon' => 'ri-check-double-line', 'color' => '#ff2d2d'],
    'declined'       => ['label' => 'Declined',       'icon' => 'ri-close-circle-line', 'color' => '#b32020'],
];

// ── HANDLE SUBMISSION ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $sent_token = (string)($_POST['csrf_token'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $title    = trim($_POST['title'] ?? '');
    $message  = trim($_POST['message'] ?? '');
    $allow_fu = isset($_POST['allow_followup']) ? 1 : 0;

    if ($sent_token === '' || !hash_equals($_SESSION['csrf_token'], $sent_token)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (!array_key_exists($category, $categories) || $title === '' || $message === '') {
        $error = 'Please choose a category and fill in both the title and message.';
    } else {
        $screenshot = null;

        if (!empty($_FILES['screenshot']['name']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $_FILES['screenshot']['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowed, true)) {
                $error = 'Screenshot must be a JPG, PNG, WEBP, or GIF image.';
            } elseif ($_FILES['screenshot']['size'] > 5 * 1024 * 1024) {
                $error = 'Screenshot must be under 5MB.';
            } else {
                $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                $ext   = $ext_map[$mime];
                $fname = 'fb_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (!is_dir('uploads/feedback')) mkdir('uploads/feedback', 0755, true);
                $dest = 'uploads/feedback/' . $fname;
                if (move_uploaded_file($_FILES['screenshot']['tmp_name'], $dest)) {
                    $screenshot = $dest;
                } else {
                    $error = 'Could not upload screenshot. Please try again.';
                }
            }
        }

        if ($error === '') {
            $stmt = $conn->prepare("INSERT INTO feedback (user_id, category, title, message, screenshot, allow_followup, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'submitted', NOW())");
            $stmt->bind_param("issssi", $uid, $category, $title, $message, $screenshot, $allow_fu);
            if ($stmt->execute()) {
                $stmt->close();
                // Post/Redirect/Get — refreshing the page can't re-send the form
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?sent=1');
                exit;
            } else {
                $error = 'Something went wrong submitting your feedback. Please try again.';
            }
            $stmt->close();
        }
    }
}

// ── MY FEEDBACK ─────────────────────────────────────────────
$mine_stmt = $conn->prepare("SELECT * FROM feedback WHERE user_id = ? AND is_archived = 0 ORDER BY created_at DESC");
$mine_stmt->bind_param("i", $uid);
$mine_stmt->execute();
$mine = $mine_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$mine_stmt->close();

// ── FEATURE BOARD (community voting) ───────────────────────
$board_stmt = $conn->prepare("
    SELECT f.*, u.username, u.profile_pic,
           EXISTS(SELECT 1 FROM feedback_votes fv WHERE fv.feedback_id = f.id AND fv.user_id = ?) AS user_voted
    FROM feedback f
    JOIN users u ON f.user_id = u.id
    WHERE f.category = 'feature' AND f.is_archived = 0 AND f.status != 'declined'
    ORDER BY f.is_pinned DESC, f.vote_count DESC, f.created_at DESC
    LIMIT 50
");
$board_stmt->bind_param("i", $uid);
$board_stmt->execute();
$board = $board_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$board_stmt->close();

$mine_count  = count($mine);
$board_count = count($board);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
<title>Feedback &amp; Suggestions | Dezzme</title>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a { text-decoration:none; color:inherit; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --red:      #FF2D2D;
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(255,255,255,0.05);
    --line:     rgba(255,255,255,0.09);
    --line2:    rgba(255,255,255,0.14);
    --text:     #ffffff;
    --muted:    rgba(255,255,255,0.45);
    --sub:      rgba(255,255,255,0.62);

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);
    --r:        5px;

    --act-h:    clamp(25px, 7.2vw, 30px);
    --act-r:    4px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; }

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

.ac-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
    padding-bottom:72px;
}
@media (min-width:801px){
    .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .ac-lane{ max-width:100%; } }

/* ══ TOPBAR ══ */
.ac-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
}
.ac-topbar::after{
    content:''; position:absolute; left:0; right:0; bottom:0;
    height:1px; background:rgba(255,255,255,0.14);
    pointer-events:none; z-index:2;
}
.ac-x{
    width:34px; height:34px;
    background:none; border:none; color:#fff;
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.ac-x:hover{ opacity:1; color:var(--red); }
.ac-x svg{ width:22px; height:22px; display:block; }
.ac-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:#fff; letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
.admin-link{
    width:var(--act-h); height:var(--act-h); border-radius:var(--act-r);
    background:rgba(255,255,255,0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    color:#fff; font-size:.95rem;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
    transition:background .2s, color .2s;
}
.admin-link:hover{ background:rgba(255,255,255,0.2); color:var(--red); }
@media (max-width:490px){
    .ac-x{ width:30px; height:30px; }
    .ac-title{ font-size:.84rem; }
}
@media (max-width:380px){ .ac-title{ font-size:.8rem; } }

/* ══ HERO ══ */
.hero{ padding:clamp(16px, 4.5vw, 24px) var(--pad) clamp(14px, 4vw, 20px); }
.hero-badge{
    display:inline-flex; align-items:center; gap:6px;
    font-size:clamp(.54rem, 1.8vw, .64rem); font-weight:700; color:var(--red);
    letter-spacing:.08em; text-transform:uppercase; margin-bottom:8px;
}
.hero h1{ font-size:clamp(1.05rem, 4.4vw, 1.4rem); font-weight:700; line-height:1.25; letter-spacing:-.3px; margin-bottom:6px; }
.hero-sub{ font-size:clamp(.62rem, 2vw, .74rem); color:var(--muted); line-height:1.7; max-width:440px; }

/* ══ TABS (same as Untracked) ══ */
.tabs-wrap{
    display:flex;
    border-top:1px solid var(--line);
    border-bottom:1px solid var(--line);
    position:sticky; top:var(--topbar-h); z-index:400;
    background:var(--bg); background-attachment:fixed;
}
.tab-btn{
    flex:1; padding:clamp(9px, 2.8vw, 13px) 6px;
    background:none; border:none;
    border-bottom:2px solid transparent;
    color:var(--muted);
    display:flex; flex-direction:column; align-items:center; gap:3px;
    margin-bottom:-1px;
    transition:color .2s, border-color .2s;
}
.tab-count{ font-size:clamp(.8rem, 3vw, 1.05rem); font-weight:700; color:var(--text); line-height:1; min-height:1em; display:flex; align-items:center; }
.tab-count i{ font-size:clamp(.9rem, 3.2vw, 1.1rem); color:var(--red); }
.tab-label{
    font-size:clamp(.5rem, 1.8vw, .62rem); font-weight:600;
    text-transform:uppercase; letter-spacing:.05em;
}
.tab-btn.active{ color:var(--text); border-bottom-color:#fff; }

.tab-panel{ display:none; flex-direction:column; flex:1; }
.tab-panel.active{ display:flex; animation:fadeUp .3s ease both; }
@keyframes fadeUp{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }

/* ══ BANNERS ══ */
.banner{
    display:flex; align-items:center; gap:10px;
    margin:clamp(10px, 3vw, 14px) var(--pad) 0;
    padding:11px 13px; border-radius:var(--act-r);
    background:rgba(255,45,45,0.08); border:1px solid rgba(255,45,45,0.25);
    font-size:clamp(.64rem, 2vw, .74rem); color:#ff8a8a; line-height:1.5;
}
.banner i{ font-size:1rem; color:var(--red); flex-shrink:0; }
.banner.ok{ background:rgba(255,255,255,0.05); border-color:var(--line2); color:#fff; }

/* ══ FORM ══ */
.form-wrap{ padding:0 var(--pad) 8px; }
.form-block{ margin-top:clamp(14px, 4vw, 20px); }
.form-label{
    display:block; font-size:.6rem; font-weight:700;
    letter-spacing:1.5px; text-transform:uppercase;
    color:var(--muted); margin-bottom:9px;
}
.field-error{ display:none; margin-top:7px; font-size:.66rem; color:#ff6b6b; }
.field-error.show{ display:block; }

.cat-grid{ display:grid; grid-template-columns:repeat(2, 1fr); gap:7px; }
.cat-btn{
    display:flex; align-items:center; gap:8px;
    min-height:var(--ctl-h); padding:7px 12px; border-radius:var(--r);
    background:var(--surface); border:1px solid var(--line2);
    font-size:clamp(.64rem, 2vw, .74rem); font-weight:500; color:var(--sub);
    transition:background .2s, border-color .2s, color .2s; text-align:left;
}
.cat-btn i{ font-size:1rem; flex-shrink:0; }
.cat-btn:hover{ background:rgba(255,255,255,0.08); color:#fff; }
.cat-btn.selected{ background:rgba(255,45,45,0.12); border-color:rgba(255,45,45,.7); color:#fff; box-shadow:0 0 0 3px rgba(255,45,45,.1); }
.cat-grid.shake{ animation:shake .35s ease; }
@keyframes shake{ 20%,60%{ transform:translateX(-4px); } 40%,80%{ transform:translateX(4px); } }

.text-input, .textarea-input{
    width:100%; padding:0 12px;
    height:var(--ctl-h);
    background:var(--surface); border:1px solid var(--line2);
    border-radius:var(--r); outline:none;
    font-size:.76rem; color:var(--text);
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.textarea-input{ height:auto; min-height:120px; padding:10px 12px; resize:vertical; line-height:1.6; }
.text-input::placeholder, .textarea-input::placeholder{ color:var(--muted); }
.text-input:focus, .textarea-input:focus{
    border-color:rgba(255,45,45,.7); background:rgba(255,255,255,.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}
@media (max-width:490px){ .text-input, .textarea-input{ font-size:.7rem; } }
.char-count{ font-size:.62rem; color:var(--muted); text-align:right; margin-top:5px; }
.char-count.warn{ color:var(--red); }

.upload-zone{
    display:flex; align-items:center; gap:12px;
    padding:12px 14px; border-radius:var(--r);
    background:var(--surface); border:1px dashed var(--line2);
    transition:border-color .2s, background .2s;
}
.upload-zone:hover{ border-color:rgba(255,45,45,.5); background:rgba(255,255,255,0.07); }
.upload-zone i{ font-size:1.2rem; color:var(--red); flex-shrink:0; }
.upload-zone-title{ font-size:clamp(.68rem, 2.1vw, .76rem); font-weight:600; }
.upload-zone-sub{ font-size:clamp(.56rem, 1.8vw, .64rem); color:var(--muted); margin-top:2px; }
.upload-preview-wrap{ display:none; margin-top:10px; position:relative; }
.upload-preview-wrap.visible{ display:block; }
.upload-preview{ width:100%; max-height:180px; object-fit:cover; border-radius:var(--r); border:1px solid var(--line2); display:block; }
.upload-remove{
    position:absolute; top:8px; right:8px;
    width:28px; height:28px; border-radius:var(--act-r);
    background:rgba(0,0,0,0.7); border:none;
    color:#fff; display:flex; align-items:center; justify-content:center;
    font-size:1rem; transition:background .2s, color .2s;
}
.upload-remove:hover{ background:var(--red); color:#000; }

.check-row{
    display:flex; align-items:flex-start; gap:10px;
    padding:12px 13px; background:var(--surface); border:1px solid var(--line2);
    border-radius:var(--r); transition:background .2s;
}
.check-row:hover{ background:rgba(255,255,255,0.07); }
.check-row input[type="checkbox"]{ width:16px; height:16px; margin-top:2px; accent-color:var(--red); flex-shrink:0; cursor:pointer; }
.check-row-title{ font-size:clamp(.68rem, 2.1vw, .76rem); font-weight:600; margin-bottom:2px; }
.check-row-sub{ font-size:clamp(.58rem, 1.9vw, .66rem); color:var(--muted); line-height:1.5; }

.submit-btn{
    width:100%; height:var(--ctl-h); margin-top:clamp(16px, 4.5vw, 22px);
    background:var(--red); border:none; border-radius:var(--r);
    font-size:.78rem; font-weight:700; color:#000;
    display:flex; align-items:center; justify-content:center; gap:7px;
    transition:background .2s, transform .15s;
}
.submit-btn:hover{ background:#ff1a1a; }
.submit-btn:active{ transform:scale(.98); }
.submit-btn:disabled{ cursor:default; }
.submit-btn i{ font-size:.95rem; }
@media (max-width:490px){ .submit-btn{ font-size:.72rem; } }

.spinner-icon{
    display:inline-flex; align-items:center; justify-content:center;
    gap:3px; height:16px; vertical-align:middle;
}
.spinner-icon span{
    display:block; width:3px; background:currentColor; border-radius:2px;
    animation:btn-bar 1s ease-in-out infinite;
}
.spinner-icon span:nth-child(1){ animation-delay:0s; }
.spinner-icon span:nth-child(2){ animation-delay:.15s; }
.spinner-icon span:nth-child(3){ animation-delay:.3s; }
.spinner-icon span:nth-child(4){ animation-delay:.45s; }
@keyframes btn-bar{
    0%,100%{ height:4px; opacity:.4; }
    50%    { height:10px; opacity:1; }
}

/* ══ STATUS BADGE (all red shades) ══ */
.status-badge{
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 9px; border-radius:var(--act-r);
    font-size:clamp(.52rem, 1.7vw, .62rem); font-weight:600;
    border:1px solid; white-space:nowrap; flex-shrink:0;
}
.status-badge i{ font-size:.78rem; }

/* ══ MY FEEDBACK ══ */
.fb-item{ border-bottom:1px solid var(--line); }
.fb-item-head{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 13px);
    padding:clamp(11px, 3.2vw, 14px) var(--pad); width:100%;
    background:none; border:none; color:inherit; text-align:left;
    transition:background .18s;
}
.fb-item-head:hover{ background:rgba(255,255,255,0.04); }
.fb-icon{
    width:clamp(27px, 7.6vw, 36px); height:clamp(27px, 7.6vw, 36px);
    border-radius:6px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    font-size:clamp(.78rem, 2.6vw, 1rem); flex-shrink:0;
}
.fb-item-info{ flex:1; min-width:0; }
.fb-item-title{ font-size:clamp(.72rem, 2.4vw, .84rem); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.fb-item-meta{ font-size:clamp(.56rem, 1.8vw, .66rem); color:var(--muted); margin-top:3px; }
.fb-chevron{ font-size:1rem; color:var(--muted); flex-shrink:0; transition:transform .25s; }
.fb-item.open .fb-chevron{ transform:rotate(180deg); }
.fb-item-body{ display:none; padding:2px var(--pad) 16px calc(var(--pad) + clamp(27px, 7.6vw, 36px) + 12px); }
.fb-item.open .fb-item-body{ display:block; animation:fadeUp .25s ease both; }
.fb-item-message{ font-size:clamp(.64rem, 2vw, .74rem); color:var(--sub); line-height:1.7; margin-bottom:10px; word-break:break-word; }
.fb-item-shot{ width:100%; max-width:320px; border-radius:var(--r); border:1px solid var(--line2); display:block; margin-bottom:10px; }
.fb-item-vote{ font-size:clamp(.56rem, 1.8vw, .66rem); color:var(--muted); display:flex; align-items:center; gap:5px; }
.fb-item-vote i{ color:var(--red); }

/* ══ FEATURE BOARD ══ */
.board-item{
    display:flex; gap:clamp(10px, 3vw, 14px);
    padding:clamp(11px, 3.2vw, 14px) var(--pad);
    border-bottom:1px solid var(--line);
    transition:background .18s;
}
.board-item:hover{ background:rgba(255,255,255,0.04); }
.vote-btn{
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:2px;
    width:clamp(40px, 11vw, 48px); height:clamp(44px, 12vw, 52px); flex-shrink:0;
    background:rgba(255,255,255,0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; border-radius:var(--act-r); color:#fff;
    transition:background .2s, color .2s, transform .15s;
}
.vote-btn i{ font-size:1.1rem; }
.vote-count{ font-size:.72rem; font-weight:700; }
.vote-btn:hover{ background:rgba(255,255,255,0.2); }
.vote-btn:active{ transform:scale(.95); }
.vote-btn.voted{ background:var(--red); color:#000; }
.vote-btn.voted:hover{ background:#ff1a1a; }
.board-content{ flex:1; min-width:0; }
.board-title{ font-size:clamp(.72rem, 2.4vw, .84rem); font-weight:600; margin-bottom:4px; }
.board-excerpt{ font-size:clamp(.62rem, 2vw, .72rem); color:var(--muted); line-height:1.55; margin-bottom:8px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; word-break:break-word; }
.board-foot{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.board-author{ display:flex; align-items:center; gap:6px; font-size:clamp(.56rem, 1.8vw, .66rem); color:var(--muted); }
.board-author img{ width:18px; height:18px; border-radius:50%; object-fit:cover; border:1px solid var(--red); }
.pin-flag{ color:var(--red); font-size:.8rem; }

/* ══ EMPTY STATE (same as Untracked) ══ */
.empty-state{
    flex:1; min-height:52vh;
    padding:clamp(28px, 8vw, 48px) clamp(20px, 7vw, 36px) clamp(36px, 10vw, 60px);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-align:center;
}
.es-title{ font-size:clamp(.95rem, 3.4vw, 1.1rem); font-weight:700; color:#fff; letter-spacing:-.2px; }
.es-text{ margin-top:6px; max-width:340px; font-size:clamp(.66rem, 2.1vw, .76rem); color:var(--muted); line-height:1.7; }
.es-tips{ width:100%; max-width:360px; margin-top:clamp(16px, 5vw, 24px); display:flex; flex-direction:column; gap:7px; }
.es-tip{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--act-r); background:rgba(255,255,255,0.05); text-align:left; }
.es-tip i{ font-size:1rem; color:var(--red); flex-shrink:0; }
.es-tip span{ font-size:clamp(.62rem, 2vw, .7rem); color:var(--sub); line-height:1.5; }
.es-btn{
    margin-top:clamp(16px, 5vw, 22px);
    display:flex; align-items:center; justify-content:center;
    height:var(--act-h); min-width:150px; padding:0 22px;
    border:none; border-radius:var(--act-r);
    background:var(--red); color:#000; font-size:.72rem; font-weight:700;
    transition:background .2s ease, transform .15s ease;
}
.es-btn:hover{ background:#ff1a1a; }
.es-btn:active{ transform:scale(0.98); }
@media (max-width:490px){ .es-btn{ font-size:.62rem; } }

/* ══ SCROLL TOP ══ */
.scroll-top{
    position:fixed; bottom:20px; right:16px; z-index:200;
    width:var(--act-h); height:var(--act-h); border-radius:var(--act-r);
    background:rgba(255,255,255,0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; color:var(--red); font-size:1.05rem;
    display:flex; align-items:center; justify-content:center;
    opacity:0; pointer-events:none;
    transition:opacity .3s, background .2s;
}
.scroll-top.visible{ opacity:1; pointer-events:all; }
.scroll-top:hover{ background:rgba(255,255,255,0.2); }

/* ══ SKELETON ══ */
#page-skeleton{
    position:fixed; inset:0; z-index:9999;
    background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center;
    opacity:1; transition:opacity .35s ease; pointer-events:none;
}
#page-skeleton.hide{ opacity:0; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
.sk{ position:relative; overflow:hidden; background:rgba(255,255,255,.06); border-radius:6px; }
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; border-bottom:1px solid rgba(255,255,255,0.14); }
.sk-back{ width:20px; height:20px; }
.sk-title{ width:130px; height:14px; }
.sk-hero{ display:flex; flex-direction:column; gap:9px; padding:clamp(16px,4.5vw,24px) var(--pad) clamp(14px,4vw,20px); }
.sk-h1{ width:180px; height:18px; }
.sk-p1{ width:90%; height:10px; }
.sk-p2{ width:65%; height:10px; }
.sk-tabs{ display:flex; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
.sk-tab{ flex:1; height:50px; border-radius:0; }
.sk-tab + .sk-tab{ margin-left:1px; }
.sk-body{ padding:0 var(--pad); display:flex; flex-direction:column; gap:12px; margin-top:16px; }
.sk-cats{ display:grid; grid-template-columns:1fr 1fr; gap:7px; }
.sk-cat{ height:var(--ctl-h); border-radius:var(--r); }
.sk-input{ height:var(--ctl-h); border-radius:var(--r); }
.sk-area{ height:110px; border-radius:var(--r); }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept at the very end so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --ctl-h:clamp(33px, 8.8vw, 39.6px);
        --r:5.5px;
        --act-h:clamp(27.5px, 7.92vw, 33px);
        --act-r:4.4px;
    }

    /* topbar */
    .ac-topbar{ padding:0 11px; }
    .ac-x{ width:37px; height:37px; }
    .ac-x svg{ width:24px; height:24px; }
    .ac-title{ font-size:1.01rem; margin:0 7px 0 11px; }
    .admin-link{ font-size:1.045rem; }

    /* hero */
    .hero{ padding:clamp(17.6px, 4.95vw, 26px) var(--pad) clamp(15px, 4.4vw, 22px); }
    .hero-badge{ gap:6.6px; font-size:clamp(.59rem, 1.98vw, .7rem); margin-bottom:8.8px; }
    .hero h1{ font-size:clamp(1.155rem, 4.84vw, 1.54rem); margin-bottom:6.6px; }
    .hero-sub{ font-size:clamp(.68rem, 2.2vw, .81rem); max-width:484px; }

    /* tabs */
    .tab-btn{ padding:clamp(10px, 3.08vw, 14px) 6.6px; gap:3.3px; }
    .tab-count{ font-size:clamp(.88rem, 3.3vw, 1.155rem); }
    .tab-count i{ font-size:clamp(.99rem, 3.52vw, 1.21rem); }
    .tab-label{ font-size:clamp(.55rem, 1.98vw, .68rem); }

    /* banners */
    .banner{ gap:11px; margin:clamp(11px, 3.3vw, 15px) var(--pad) 0; padding:12px 14.3px; font-size:clamp(.7rem, 2.2vw, .81rem); }
    .banner i{ font-size:1.1rem; }

    /* form */
    .form-wrap{ padding:0 var(--pad) 8.8px; }
    .form-block{ margin-top:clamp(15px, 4.4vw, 22px); }
    .form-label{ font-size:.66rem; letter-spacing:1.65px; margin-bottom:10px; }
    .field-error{ margin-top:7.7px; font-size:.73rem; }
    .cat-grid{ gap:7.7px; }
    .cat-btn{ gap:8.8px; padding:7.7px 13px; font-size:clamp(.7rem, 2.2vw, .81rem); }
    .cat-btn i{ font-size:1.1rem; }
    .text-input, .textarea-input{ padding:0 13px; font-size:.84rem; }
    .textarea-input{ min-height:132px; padding:11px 13px; }
    .char-count{ font-size:.68rem; margin-top:5.5px; }

    /* upload */
    .upload-zone{ gap:13px; padding:13px 15.4px; }
    .upload-zone i{ font-size:1.32rem; }
    .upload-zone-title{ font-size:clamp(.75rem, 2.31vw, .84rem); }
    .upload-zone-sub{ font-size:clamp(.62rem, 1.98vw, .7rem); }
    .upload-preview-wrap{ margin-top:11px; }
    .upload-preview{ max-height:198px; }
    .upload-remove{ top:8.8px; right:8.8px; width:31px; height:31px; font-size:1.1rem; }

    /* checkbox row */
    .check-row{ gap:11px; padding:13px 14.3px; }
    .check-row input[type="checkbox"]{ width:17.6px; height:17.6px; }
    .check-row-title{ font-size:clamp(.75rem, 2.31vw, .84rem); }
    .check-row-sub{ font-size:clamp(.64rem, 2.09vw, .73rem); }

    /* submit */
    .submit-btn{ margin-top:clamp(17.6px, 4.95vw, 24px); font-size:.86rem; gap:7.7px; }
    .submit-btn i{ font-size:1.045rem; }

    /* status badge */
    .status-badge{ gap:5.5px; padding:3.3px 10px; font-size:clamp(.57rem, 1.87vw, .68rem); }
    .status-badge i{ font-size:.86rem; }

    /* my feedback */
    .fb-item-head{ gap:clamp(11px, 3.3vw, 14px); padding:clamp(12px, 3.52vw, 15px) var(--pad); }
    .fb-icon{ width:clamp(30px, 8.36vw, 40px); height:clamp(30px, 8.36vw, 40px); border-radius:6.6px; font-size:clamp(.86rem, 2.86vw, 1.1rem); }
    .fb-item-title{ font-size:clamp(.79rem, 2.64vw, .92rem); }
    .fb-item-meta{ font-size:clamp(.62rem, 1.98vw, .73rem); margin-top:3.3px; }
    .fb-chevron{ font-size:1.1rem; }
    .fb-item-body{ padding:2.2px var(--pad) 17.6px calc(var(--pad) + clamp(30px, 8.36vw, 40px) + 13px); }
    .fb-item-message{ font-size:clamp(.7rem, 2.2vw, .81rem); margin-bottom:11px; }
    .fb-item-shot{ max-width:352px; margin-bottom:11px; }
    .fb-item-vote{ font-size:clamp(.62rem, 1.98vw, .73rem); gap:5.5px; }

    /* feature board */
    .board-item{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(12px, 3.52vw, 15px) var(--pad); }
    .vote-btn{ width:clamp(44px, 12.1vw, 53px); height:clamp(48px, 13.2vw, 57px); gap:2.2px; }
    .vote-btn i{ font-size:1.21rem; }
    .vote-count{ font-size:.79rem; }
    .board-title{ font-size:clamp(.79rem, 2.64vw, .92rem); margin-bottom:4.4px; }
    .board-excerpt{ font-size:clamp(.68rem, 2.2vw, .79rem); margin-bottom:8.8px; }
    .board-foot{ gap:11px; }
    .board-author{ font-size:clamp(.62rem, 1.98vw, .73rem); gap:6.6px; }
    .board-author img{ width:20px; height:20px; }
    .pin-flag{ font-size:.88rem; }

    /* empty state */
    .empty-state{ padding:clamp(31px, 8.8vw, 53px) clamp(22px, 7.7vw, 40px) clamp(40px, 11vw, 66px); }
    .es-title{ font-size:clamp(1.045rem, 3.74vw, 1.21rem); }
    .es-text{ margin-top:6.6px; max-width:374px; font-size:clamp(.73rem, 2.31vw, .84rem); }
    .es-tips{ max-width:396px; margin-top:clamp(18px, 5.5vw, 26px); gap:7.7px; }
    .es-tip{ gap:11px; padding:11px 13px; }
    .es-tip i{ font-size:1.1rem; }
    .es-tip span{ font-size:clamp(.68rem, 2.2vw, .77rem); }
    .es-btn{ margin-top:clamp(18px, 5.5vw, 24px); min-width:165px; padding:0 24px; font-size:.79rem; }

    /* scroll top */
    .scroll-top{ bottom:22px; right:17.6px; font-size:1.155rem; }

    /* skeleton */
    .sk-topbar{ padding:0 17.6px 0 15px; }
    .sk-back{ width:22px; height:22px; }
    .sk-title{ width:143px; height:15.4px; }
    .sk-hero{ gap:10px; padding:clamp(18px, 4.95vw, 26px) var(--pad) clamp(15px, 4.4vw, 22px); }
    .sk-h1{ width:198px; height:20px; }
    .sk-p1, .sk-p2{ height:11px; }
    .sk-tab{ height:55px; }
    .sk-body{ gap:13px; margin-top:17.6px; }
    .sk-cats{ gap:7.7px; }
    .sk-area{ height:121px; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .ac-x{ width:33px; height:33px; }
    .ac-title{ font-size:.92rem; }
    .text-input, .textarea-input{ font-size:.77rem; }
    .submit-btn{ font-size:.79rem; }
    .es-btn{ font-size:.68rem; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .ac-title{ font-size:.88rem; }
}
</style>
</head>
<body>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar">
            <div class="sk sk-back"></div>
            <div class="sk sk-title"></div>
        </div>
        <div class="sk-hero">
            <div class="sk sk-h1"></div>
            <div class="sk sk-p1"></div>
            <div class="sk sk-p2"></div>
        </div>
        <div class="sk-tabs">
            <div class="sk sk-tab"></div>
            <div class="sk sk-tab"></div>
            <div class="sk sk-tab"></div>
        </div>
        <div class="sk-body">
            <div class="sk-cats">
                <?php for($i=0;$i<4;$i++): ?><div class="sk sk-cat"></div><?php endfor; ?>
            </div>
            <div class="sk sk-input"></div>
            <div class="sk sk-area"></div>
            <div class="sk sk-input"></div>
        </div>
    </div>
</div>

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="acClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="ac-title">Feedback &amp; Suggestions</span>
        <?php if ($is_admin): ?>
        <a class="admin-link" href="feedback_manager.php" title="Admin Dashboard" aria-label="Admin Dashboard"><i class="ri-dashboard-3-line"></i></a>
        <?php endif; ?>
    </div>

    <!-- ══ HERO ══ -->
    <section class="hero">
        <div class="hero-badge"><i class="ri-feedback-line"></i> Your Voice Matters</div>
        <h1>Tell us what you actually think</h1>
        <p class="hero-sub">Report bugs, request features, or just tell us what's on your mind. Every submission is read, and you can track exactly what happens to it.</p>
    </section>

    <!-- ══ TABS ══ -->
    <div class="tabs-wrap">
        <button type="button" class="tab-btn active" data-tab="submit">
            <span class="tab-count"><i class="ri-send-plane-fill"></i></span>
            <span class="tab-label">Submit</span>
        </button>
        <button type="button" class="tab-btn" data-tab="mine">
            <span class="tab-count"><?php echo number_format($mine_count); ?></span>
            <span class="tab-label">My Feedback</span>
        </button>
        <button type="button" class="tab-btn" data-tab="board">
            <span class="tab-count"><?php echo number_format($board_count); ?></span>
            <span class="tab-label">Feature Board</span>
        </button>
    </div>

    <!-- ═══ SUBMIT TAB ═══ -->
    <div class="tab-panel active" id="tab-submit">

        <?php if ($success): ?>
        <div class="banner ok"><i class="ri-check-line"></i> Feedback received, thank you! Track it any time in the "My Feedback" tab.</div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="banner"><i class="ri-error-warning-line"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="feedbackForm" class="form-wrap">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-block">
                <label class="form-label">What is this about?</label>
                <div class="cat-grid" id="catGrid">
                    <?php foreach ($categories as $val => $cat): ?>
                    <button type="button" class="cat-btn" data-val="<?php echo $val; ?>" onclick="selectCat(this,'<?php echo $val; ?>')">
                        <i class="<?php echo $cat['icon']; ?>" style="color:<?php echo $cat['color']; ?>"></i>
                        <?php echo $cat['label']; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="category" id="categoryInput" value="<?php echo htmlspecialchars($_POST['category'] ?? ''); ?>">
                <div class="field-error" id="catError">Please pick a category first.</div>
            </div>

            <div class="form-block">
                <label class="form-label" for="titleInput">Give it a headline</label>
                <input class="text-input" type="text" name="title" id="titleInput" placeholder="Short and punchy — what's the main point?" maxlength="150" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
            </div>

            <div class="form-block">
                <label class="form-label" for="messageInput">Tell us more</label>
                <textarea class="textarea-input" name="message" id="messageInput" placeholder="The more detail the better — what happened, what you expected, what would make it better..." maxlength="1000"><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                <div class="char-count" id="charCount">0 / 1000</div>
            </div>

            <div class="form-block">
                <label class="form-label">Screenshot (optional)</label>
                <div class="upload-zone" onclick="document.getElementById('screenshotInput').click()">
                    <i class="ri-image-add-line"></i>
                    <div>
                        <div class="upload-zone-title">Add a screenshot</div>
                        <div class="upload-zone-sub">JPG, PNG, WEBP or GIF — up to 5MB</div>
                    </div>
                </div>
                <input type="file" name="screenshot" id="screenshotInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">
                <div class="upload-preview-wrap" id="previewWrap">
                    <img class="upload-preview" id="previewImg" src="" alt="Screenshot preview">
                    <button type="button" class="upload-remove" id="previewRemove" aria-label="Remove screenshot"><i class="ri-close-line"></i></button>
                </div>
            </div>

            <div class="form-block">
                <label class="check-row">
                    <input type="checkbox" name="allow_followup" <?php echo isset($_POST['allow_followup']) ? 'checked' : ''; ?>>
                    <div>
                        <div class="check-row-title">Allow follow-up</div>
                        <div class="check-row-sub">We may reach out via your Dezzme DMs if we need more context.</div>
                    </div>
                </label>
            </div>

            <button type="submit" name="submit_feedback" value="1" class="submit-btn" id="submitBtn">
                <i class="ri-send-plane-fill"></i> Send Feedback
            </button>
        </form>
    </div>

    <!-- ═══ MY FEEDBACK TAB ═══ -->
    <div class="tab-panel" id="tab-mine">
        <?php if (empty($mine)): ?>
        <div class="empty-state">
            <div class="es-title">No feedback yet</div>
            <p class="es-text">Anything you send us shows up here with its status, so you always know where it stands.</p>
            <div class="es-tips">
                <div class="es-tip"><i class="ri-bug-line"></i><span>Report bugs and tell us exactly what went wrong.</span></div>
                <div class="es-tip"><i class="ri-eye-line"></i><span>Follow each one from Submitted all the way to Completed.</span></div>
            </div>
            <button type="button" class="es-btn" onclick="goTab('submit')">Send feedback</button>
        </div>
        <?php else: ?>
        <div class="fb-list">
            <?php foreach ($mine as $fb):
                $cat = $categories[$fb['category']] ?? $categories['other'];
                $st  = $statuses[$fb['status']] ?? $statuses['submitted'];
            ?>
            <div class="fb-item">
                <button type="button" class="fb-item-head" onclick="this.closest('.fb-item').classList.toggle('open')">
                    <div class="fb-icon"><i class="<?php echo $cat['icon']; ?>" style="color:<?php echo $cat['color']; ?>"></i></div>
                    <div class="fb-item-info">
                        <div class="fb-item-title"><?php echo htmlspecialchars($fb['title']); ?></div>
                        <div class="fb-item-meta"><?php echo $cat['label']; ?> · <?php echo date('M j, Y', strtotime($fb['created_at'])); ?></div>
                    </div>
                    <span class="status-badge" style="color:<?php echo $st['color']; ?>;border-color:<?php echo $st['color']; ?>55;background:<?php echo $st['color']; ?>1a;">
                        <i class="<?php echo $st['icon']; ?>"></i><?php echo $st['label']; ?>
                    </span>
                    <i class="ri-arrow-down-s-line fb-chevron"></i>
                </button>
                <div class="fb-item-body">
                    <div class="fb-item-message"><?php echo nl2br(htmlspecialchars($fb['message'])); ?></div>
                    <?php if (!empty($fb['screenshot'])): ?>
                    <img class="fb-item-shot" src="<?php echo htmlspecialchars($fb['screenshot']); ?>" alt="Screenshot">
                    <?php endif; ?>
                    <?php if ($fb['category'] === 'feature'): ?>
                    <div class="fb-item-vote"><i class="ri-arrow-up-line"></i> <?php echo (int)$fb['vote_count']; ?> community votes</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══ FEATURE BOARD TAB ═══ -->
    <div class="tab-panel" id="tab-board">
        <?php if (empty($board)): ?>
        <div class="empty-state">
            <div class="es-title">No feature requests yet</div>
            <p class="es-text">The Feature Board is where the community votes on what Dezzme builds next. Be the first to suggest something.</p>
            <div class="es-tips">
                <div class="es-tip"><i class="ri-lightbulb-line"></i><span>Pick Feature Request when you submit and it lands here.</span></div>
                <div class="es-tip"><i class="ri-arrow-up-circle-line"></i><span>The most voted ideas rise to the top.</span></div>
            </div>
            <button type="button" class="es-btn" onclick="goTab('submit')">Suggest a feature</button>
        </div>
        <?php else: ?>
        <div class="board-list">
            <?php foreach ($board as $b):
                $st = $statuses[$b['status']] ?? $statuses['submitted'];
            ?>
            <div class="board-item">
                <button type="button" class="vote-btn <?php echo $b['user_voted'] ? 'voted' : ''; ?>" data-id="<?php echo (int)$b['id']; ?>" onclick="toggleVote(this)" aria-label="Vote">
                    <i class="ri-arrow-up-s-line"></i>
                    <span class="vote-count"><?php echo (int)$b['vote_count']; ?></span>
                </button>
                <div class="board-content">
                    <div class="board-title">
                        <?php if ($b['is_pinned']): ?><i class="ri-pushpin-2-fill pin-flag"></i> <?php endif; ?>
                        <?php echo htmlspecialchars($b['title']); ?>
                    </div>
                    <div class="board-excerpt"><?php echo htmlspecialchars($b['message']); ?></div>
                    <div class="board-foot">
                        <span class="status-badge" style="color:<?php echo $st['color']; ?>;border-color:<?php echo $st['color']; ?>55;background:<?php echo $st['color']; ?>1a;">
                            <i class="<?php echo $st['icon']; ?>"></i><?php echo $st['label']; ?>
                        </span>
                        <div class="board-author">
                            <img src="<?php echo htmlspecialchars($b['profile_pic'] ?? 'uploads/me.png'); ?>" onerror="this.src='uploads/me.png'" alt="">
                            @<?php echo htmlspecialchars($b['username']); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.ac-lane -->

<button type="button" class="scroll-top" id="scrollTop" aria-label="Back to top" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="ri-arrow-up-line"></i>
</button>

<script>
var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// ── X: go back, or to settings if there is no history ──
function acClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'settings.php';
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
    setTimeout(hide, 1500);
})();

// ── Tabs ──
function goTab(name){
    var btn = document.querySelector('.tab-btn[data-tab="' + name + '"]');
    if(btn) btn.click();
}
document.querySelectorAll('.tab-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
        document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});
<?php if ($success): ?>
// land on My Feedback after a successful submit so the user sees it land
goTab('mine');
<?php endif; ?>

// ── Category select ──
function selectCat(btn, val){
    document.querySelectorAll('.cat-btn').forEach(function(b){ b.classList.remove('selected'); });
    btn.classList.add('selected');
    document.getElementById('categoryInput').value = val;
    document.getElementById('catError').classList.remove('show');
}
var savedCat = <?php echo json_encode((string)($_POST['category'] ?? '')); ?>;
if (savedCat) {
    var sb = document.querySelector('.cat-btn[data-val="' + savedCat.replace(/[^a-z]/g,'') + '"]');
    if (sb) sb.classList.add('selected');
}

// ── Char count ──
var messageInput = document.getElementById('messageInput');
var charCount = document.getElementById('charCount');
function updateCount(){
    var n = messageInput.value.length;
    charCount.textContent = n + ' / 1000';
    charCount.classList.toggle('warn', n > 900);
}
messageInput.addEventListener('input', updateCount);
updateCount();

// ── Screenshot preview ──
var screenshotInput = document.getElementById('screenshotInput');
var previewWrap = document.getElementById('previewWrap');
var previewImg  = document.getElementById('previewImg');
screenshotInput.addEventListener('change', function(){
    var file = screenshotInput.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e){
        previewImg.src = e.target.result;
        previewWrap.classList.add('visible');
    };
    reader.readAsDataURL(file);
});
document.getElementById('previewRemove').addEventListener('click', function(e){
    e.stopPropagation();
    screenshotInput.value = '';
    previewWrap.classList.remove('visible');
    previewImg.src = '';
});

// ── Form validation + loading state ──
document.getElementById('feedbackForm').addEventListener('submit', function(e){
    if (!document.getElementById('categoryInput').value) {
        e.preventDefault();
        var grid = document.getElementById('catGrid');
        document.getElementById('catError').classList.add('show');
        grid.scrollIntoView({ behavior:'smooth', block:'center' });
        grid.classList.remove('shake'); void grid.offsetWidth; grid.classList.add('shake');
        return;
    }
    var b = document.getElementById('submitBtn');
    b.style.minWidth = b.offsetWidth + 'px';
    setTimeout(function(){
        b.disabled = true;
        b.innerHTML = '<span class="spinner-icon"><span></span><span></span><span></span><span></span></span>';
    }, 0);
});

// ── Vote (Feature Board) — sends the CSRF token too ──
async function toggleVote(btn){
    if (btn.dataset.busy === '1') return;
    btn.dataset.busy = '1';
    var id = btn.dataset.id;
    try {
        var res = await fetch('feedback_actions.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
            body: 'action=vote&feedback_id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(CSRF)
        });
        var data = await res.json();
        if (data.success) {
            btn.classList.toggle('voted', data.voted);
            btn.querySelector('.vote-count').textContent = data.vote_count;
        }
    } catch (err) { /* network hiccup */ }
    btn.dataset.busy = '0';
}

// ── Scroll top ──
window.addEventListener('scroll', function(){
    document.getElementById('scrollTop').classList.toggle('visible', window.scrollY > 300);
}, { passive:true });
</script>
</body>
</html>