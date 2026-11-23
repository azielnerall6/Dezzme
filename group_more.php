<?php
session_start();
include 'config.php';
require_once __DIR__ . '/pod_id.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
if(!$current_user){ header("Location: login.php"); exit(); }

$group_id = (int)($_GET['id'] ?? $_GET['group_id'] ?? 0);
if(!$group_id){ header("Location: community.php"); exit(); }

// ── ENSURE group_name_history TABLE EXISTS ────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS group_name_history (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id INT UNSIGNED NOT NULL,
        old_title VARCHAR(100) NOT NULL,
        changed_by INT UNSIGNED NOT NULL DEFAULT 0,
        changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_group (group_id)
    ) ENGINE=InnoDB
");

// ── Group + creator info ───────────────────────────────────────────────────
$group = $conn->query("
    SELECT g.*, u.id AS creator_id, u.username AS creator_name, u.profile_pic AS creator_pic
    FROM groups g
    JOIN users u ON g.created_by = u.id
    WHERE g.id = $group_id
")->fetch_assoc();

if(!$group){ echo "Pod not found"; exit(); }

// ── Permanent Pod ID (stored in DB, never changes) ─────────────────────────
$pd_id = (string)($group['pd_id'] ?? '');
if($pd_id === ''){ $pd_id = pd_assign_id($conn, $group_id); }   // safety net for pods that somehow have none yet
$pd_digits = substr($pd_id, 3);                                  // everything after "PD-"

$is_owner  = ($group['created_by'] == $current_user);
$is_admin  = false;
$admin_chk = $conn->query("SELECT 1 FROM group_admins WHERE group_id=$group_id AND user_id=$current_user");
if($admin_chk && $admin_chk->num_rows > 0) $is_admin = true;

$is_member = $conn->query("SELECT 1 FROM group_members WHERE group_id=$group_id AND user_id=$current_user")->num_rows > 0;

// ── Stats ───────────────────────────────────────────────────────────────────
$member_count   = (int)($conn->query("SELECT COUNT(*) c FROM group_members WHERE group_id=$group_id")->fetch_assoc()['c'] ?? 0);
$total_posts    = (int)($conn->query("SELECT COUNT(*) c FROM group_posts WHERE group_id=$group_id")->fetch_assoc()['c'] ?? 0);
$admin_count    = (int)($conn->query("SELECT COUNT(*) c FROM group_admins WHERE group_id=$group_id")->fetch_assoc()['c'] ?? 0);
$disc_count     = 0;
$disc_replies   = 0;
$tbl = $conn->query("SHOW TABLES LIKE 'pod_discussions'");
if($tbl && $tbl->num_rows > 0){
    $disc_count = (int)($conn->query("SELECT COUNT(*) c FROM pod_discussions WHERE group_id=$group_id")->fetch_assoc()['c'] ?? 0);
    $disc_replies = (int)($conn->query("
        SELECT COUNT(*) c FROM pod_discussion_replies pdr
        JOIN pod_discussions pd ON pd.id = pdr.discussion_id
        WHERE pd.group_id=$group_id
    ")->fetch_assoc()['c'] ?? 0);
}

$total_likes = (int)($conn->query("
    SELECT COUNT(*) c FROM likes l JOIN group_posts gp ON gp.meme_id=l.meme_id WHERE gp.group_id=$group_id
")->fetch_assoc()['c'] ?? 0);
$total_reposts = (int)($conn->query("
    SELECT COUNT(*) c FROM reposts r JOIN group_posts gp ON gp.meme_id=r.meme_id WHERE gp.group_id=$group_id
")->fetch_assoc()['c'] ?? 0);

$posts_today = (int)($conn->query("
    SELECT COUNT(*) c FROM group_posts WHERE group_id=$group_id AND DATE(created_at)=CURDATE()
")->fetch_assoc()['c'] ?? 0);

$new_members_week = (int)($conn->query("
    SELECT COUNT(*) c FROM group_members WHERE group_id=$group_id AND joined_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetch_assoc()['c'] ?? 0);

$active_now = (int)($conn->query("
    SELECT COUNT(*) c FROM group_members gm JOIN users u ON u.id=gm.user_id
    WHERE gm.group_id=$group_id AND u.last_seen >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
")->fetch_assoc()['c'] ?? 0);

$peak_hour = 'N/A';
$ph = $conn->query("SELECT HOUR(created_at) hr, COUNT(*) cnt FROM group_posts WHERE group_id=$group_id GROUP BY hr ORDER BY cnt DESC LIMIT 1");
if($ph && ($row = $ph->fetch_assoc())){
    $peak_hour = sprintf('%d:00–%d:00', (int)$row['hr'], (int)$row['hr']+1);
}

// ── Name history ────────────────────────────────────────────────────────────
$name_history = [];
$nh = $conn->query("SELECT old_title, changed_at FROM group_name_history WHERE group_id=$group_id ORDER BY changed_at DESC");
if($nh){ while($r = $nh->fetch_assoc()) $name_history[] = $r; }
$name_change_count = count($name_history);

// ── Admins list ─────────────────────────────────────────────────────────────
$admins = [];
$ar = $conn->query("SELECT u.id, u.username, u.profile_pic, u.user_rank FROM group_admins ga JOIN users u ON u.id=ga.user_id WHERE ga.group_id=$group_id");
if($ar){ while($r = $ar->fetch_assoc()) $admins[] = $r; }

// ── Popular tags ────────────────────────────────────────────────────────────
$tag_counts = [];
$tr = $conn->query("SELECT m.caption FROM group_posts gp JOIN memes m ON m.id=gp.meme_id WHERE gp.group_id=$group_id AND m.caption IS NOT NULL LIMIT 100");
if($tr){
    while($row = $tr->fetch_assoc()){
        preg_match_all('/#(\w+)/u', $row['caption'], $mx);
        foreach($mx[1] as $tg){ $t = strtolower($tg); $tag_counts[$t] = ($tag_counts[$t] ?? 0) + 1; }
    }
}
arsort($tag_counts);
$top_tags = array_slice(array_keys($tag_counts), 0, 10);

// ── Image path ──────────────────────────────────────────────────────────────
$group_pic_raw = $group['group_pic'] ?? '';
$group_img = empty($group_pic_raw) ? 'uploads/default_group.png' :
    (strpos($group_pic_raw, 'uploads/') === 0 ? $group_pic_raw : 'uploads/' . $group_pic_raw);

$is_adult_pod = !empty($group['is_adult']);
$pod_suspended = !empty($group['is_suspended']);   /* set from the reports centre: plain tile instead of the picture, "Suspended" instead of the audience flag */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($group['title']); ?> | About This Pod</title>
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --fg:       255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text:  #ffffff;
    --blue:     #1a56c4;
    --blue-text:#5b9cf0;       /* blue used for text on the page (lighter in dark mode, deeper in light mode) */
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(var(--fg),0.05);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.14);
    --text:     #ffffff;
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.62);
    --card-bg:  rgba(12,12,28,0.98);   /* modal card */
    --pop-bg:   rgba(8,8,22,.97);      /* "Pod ID copied" toast */
    --scrim:    rgba(0,0,0,0.7);
    --ph:       #1a1a1a;               /* avatar placeholder */
    --ok:       #4ade80;
    --danger-t: #f87171;               /* red text (18+ flag) */
    --danger-t2:#ff8a8a;               /* red text (strike system) */

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);
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

/* ══════════════════════════════════════
   TOPBAR — X on the left, "About This Pod" on the right (no icon)
   The bottom line belongs to the topbar itself, so it stays
   visible (sticky) while the page scrolls.
══════════════════════════════════════ */
.ac-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
    border-bottom:1px solid var(--line);
}
.ac-x{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.ac-x:hover{ opacity:1; color:var(--blue); }
.ac-x svg{ width:22px; height:22px; display:block; }
.ac-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){
    .ac-x{ width:30px; height:30px; }
    .ac-title{ font-size:.84rem; }
}
@media (max-width:380px){ .ac-title{ font-size:.8rem; } }

/* ── Pod header ── */
.profile-header{
    display:flex; flex-direction:column; align-items:center;
    padding:clamp(14px, 4vw, 22px) var(--pad) clamp(16px, 4.5vw, 24px);
    text-align:center;
}
.avatar{
    width:clamp(64px, 19vw, 90px); height:clamp(64px, 19vw, 90px);
    border-radius:16%; object-fit:cover; display:block;
    border:3.5px solid var(--blue);
    box-shadow:0 4px 18px rgba(26,86,196,0.28);
    transition:transform .3s, box-shadow .3s;
    margin-bottom:12px;
}
.avatar:hover{ transform:scale(1.05); box-shadow:0 0 22px rgba(26,86,196,0.5); }
.avatar.avatar-susp{ background:rgba(var(--fg),.1); }   /* a slightly lighter shade of the page background, same blue border */
.pod-susp-flag{ margin-top:10px; font-size:clamp(.7rem, 2.2vw, .8rem); font-weight:700; color:var(--blue-text); }
.person-av-susp{ display:block; width:clamp(38px, 11vw, 50px); height:clamp(38px, 11vw, 50px); border-radius:50%; border:2px solid var(--blue); background:rgba(var(--fg),.1); }
.profile-name{
    font-size:clamp(.88rem, 3.4vw, 1.05rem); font-weight:700;
    display:flex; align-items:center; gap:6px; justify-content:center;
    margin-bottom:3px; max-width:100%; word-break:break-word;
}
.profile-label{
    font-size:clamp(.58rem, 1.9vw, .68rem); color:var(--muted); font-weight:500;
    text-transform:uppercase; letter-spacing:.08em;
}
.transparency-note a{ color:var(--blue-text); text-decoration:none; font-weight:600; }
.transparency-note{
    font-size:clamp(.6rem, 1.9vw, .68rem); color:var(--muted);
    line-height:1.65; margin-top:12px; max-width:340px; text-align:center;
}

.adult-flag{
    display:inline-flex; align-items:center; gap:5px;
    margin-top:10px; padding:4px 11px 4px 8px;
    border-radius:var(--r); font-size:clamp(.58rem, 1.9vw, .68rem); font-weight:700;
    letter-spacing:.3px;
}
.adult-flag.yes{ background:rgba(255,45,45,.14); color:var(--danger-t); border:1px solid rgba(255,45,45,.32); }
.adult-flag.no{ background:rgba(26,86,196,.14); color:var(--blue-text); border:1px solid rgba(26,86,196,.3); }

/* ── Stats bar ── */
.stats-bar{
    display:grid; grid-template-columns:repeat(3, 1fr);
    border-top:1px solid var(--line); border-bottom:1px solid var(--line);
}
.stat-item{ display:flex; flex-direction:column; align-items:center; padding:clamp(11px, 3.4vw, 16px) 6px; text-align:center; }
.stat-item + .stat-item{ border-left:1px solid var(--line); }
.stat-num{ font-size:clamp(.88rem, 3.2vw, 1.2rem); font-weight:700; color:var(--text); line-height:1; }
.stat-lbl{ font-size:clamp(.48rem, 1.7vw, .6rem); color:var(--muted); margin-top:4px; letter-spacing:.03em; text-transform:uppercase; }

/* ── Section label ── */
.section-label{
    padding:clamp(14px, 4vw, 20px) var(--pad) 6px;
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted);
}

/* ── Info list ── */
.info-list{ display:flex; flex-direction:column; }
.info-list a{ text-decoration:none; color:inherit; display:block; }
.divider{ width:100%; border:none; border-top:1px solid var(--line); margin:0; }
.info-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:clamp(11px, 3.2vw, 16px) var(--pad);
    transition:background .18s;
}
.info-row.clickable{ cursor:pointer; }
.info-row.clickable:hover{ background:rgba(var(--fg),0.04); }
.info-icon{
    width:clamp(27px, 7.6vw, 36px); height:clamp(27px, 7.6vw, 36px);
    border-radius:6px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; color:var(--blue); font-size:clamp(.78rem, 2.6vw, 1rem);
    transition:background .2s;
}
.info-row.clickable:hover .info-icon{ background:rgba(26,86,196,0.15); }
.info-content{ flex:1; min-width:0; }
.info-label{
    font-size:clamp(.54rem, 1.8vw, .66rem); color:var(--muted); font-weight:400;
    line-height:1.2; text-transform:uppercase; letter-spacing:.05em;
}
.info-value{
    font-size:clamp(.72rem, 2.4vw, .86rem); font-weight:500; color:var(--text);
    line-height:1.4; margin-top:2px; word-break:break-word;
}
.chevron{ color:var(--muted); font-size:1rem; flex-shrink:0; }

/* ── Pod ID ── */
.profile-id-value{
    font-size:clamp(.7rem, 2.3vw, .86rem); font-weight:400; color:var(--text);
    line-height:1.3; margin-top:3px; letter-spacing:.06em;
    font-variant-numeric:tabular-nums;
}
.profile-id-value .pid-prefix{ color:var(--ui-text); }
.profile-id-value .pid-digits{ color:var(--blue-text); }

.copy-btn{
    display:flex; align-items:center; justify-content:center; gap:5px;
    height:clamp(25px, 7.2vw, 30px); padding:0 12px; flex-shrink:0;
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; border-radius:4px;
    color:var(--ui-text); font-size:.7rem; font-weight:600; white-space:nowrap;
    transition:background .2s, color .2s, transform .15s;
}
.copy-btn:hover{ background:rgba(var(--fg),0.2); }
.copy-btn:active{ transform:scale(.97); }
.copy-btn.copied{ background:rgba(74,222,128,0.14); color:var(--ok); }
.copy-btn i{ font-size:.82rem; }
@media (max-width:490px){ .copy-btn{ font-size:.62rem; padding:0 9px; gap:4px; } }
@media (max-width:380px){ .copy-btn{ font-size:.58rem; padding:0 7px; } }

/* Copy toast */
#copy-toast{
    position:fixed; bottom:88px; left:50%;
    transform:translateX(-50%) translateY(12px);
    background:var(--pop-bg); border:1px solid rgba(74,222,128,.3);
    color:var(--ok); font-size:.74rem; font-weight:500;
    padding:9px 18px; border-radius:var(--r);
    opacity:0; pointer-events:none;
    transition:opacity .22s ease, transform .22s ease;
    z-index:999999; white-space:nowrap;
    box-shadow:0 8px 24px rgba(0,0,0,.6);
}
#copy-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

/* ── Notes ── */
.mutual-notes{ padding:0 var(--pad) 24px; display:flex; flex-direction:column; gap:8px; }
.mutual-note{ font-size:clamp(.6rem, 1.9vw, .7rem); color:var(--muted); line-height:1.65; }
.mutual-note strong{ color:rgba(var(--fg),0.7); font-weight:600; }

/* ── Description / guidelines boxes ── */
.text-block{ padding:0 var(--pad) 6px; }
.desc-box{
    background:var(--surface);
    border:1px solid var(--line);
    border-radius:var(--r); padding:clamp(11px, 3.4vw, 14px) clamp(11px, 3.4vw, 16px);
    font-size:clamp(.7rem, 2.3vw, .8rem); color:var(--sub);
    line-height:1.65; word-break:break-word; white-space:pre-wrap;
}
.desc-box.empty{ color:var(--muted); font-style:italic; }

/* ── Rules ── */
.rule-row{
    display:flex; align-items:flex-start; gap:clamp(10px, 3vw, 12px);
    padding:clamp(10px, 3vw, 12px) 0; border-bottom:1px solid var(--line);
    font-size:clamp(.68rem, 2.2vw, .78rem); color:var(--sub); line-height:1.55;
}
.rule-icon{
    width:clamp(25px, 7vw, 30px); height:clamp(25px, 7vw, 30px); border-radius:6px;
    background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; margin-top:1px; color:var(--blue); font-size:clamp(.72rem, 2.4vw, .88rem);
}

.strike-card{
    background:rgba(255,45,45,0.05);
    border:1px solid rgba(255,45,45,0.2);
    border-radius:var(--r); padding:14px 14px 6px; margin:14px 0 6px;
}
.strike-head{ display:flex; align-items:center; gap:7px; font-size:.75rem; font-weight:700; color:var(--danger-t2); margin-bottom:10px; }
.strike-head i{ font-size:15px; color:#ef4444; }
.strike-row{ display:flex; align-items:flex-start; gap:11px; padding:8px 0; border-bottom:1px solid var(--line); }
.strike-row:last-child{ border-bottom:none; }
.strike-num{
    width:22px; height:22px; border-radius:50%;
    background:rgba(var(--fg),0.08); border:1px solid var(--line2);
    color:var(--sub); font-size:.68rem; font-weight:700;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.strike-row.danger .strike-num{ background:rgba(255,45,45,.25); color:var(--danger-t2); border-color:rgba(255,45,45,.4); }
.strike-text{ font-size:clamp(.64rem, 2vw, .72rem); color:var(--sub); line-height:1.5; padding-top:1px; }
.strike-text strong{ color:var(--danger-t2); }

/* ── People row ── */
.people-row{
    display:flex; gap:14px; overflow-x:auto; scrollbar-width:none;
    padding:6px var(--pad) 4px;
}
.people-row::-webkit-scrollbar{ display:none; }
.person{
    display:flex; flex-direction:column; align-items:center; gap:6px;
    flex-shrink:0; min-width:60px; text-decoration:none; transition:.2s;
}
.person:hover{ transform:translateY(-3px); }
.person img{ width:clamp(38px, 11vw, 50px); height:clamp(38px, 11vw, 50px); border-radius:50%; object-fit:cover; border:2px solid var(--blue); display:block; background:var(--ph); }
.person-name{ font-size:.66rem; font-weight:600; color:rgba(var(--fg),.8); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:64px; text-align:center; }
.person-role{ font-size:.56rem; color:var(--blue-text); font-weight:700; letter-spacing:.04em; }

/* ── Tags ── */
.tags-wrap{ display:flex; flex-wrap:wrap; gap:8px; padding:6px var(--pad) 4px; }
.tag-pill{
    display:inline-flex; align-items:center; gap:4px;
    padding:5px 12px; border-radius:var(--r);
    background:rgba(26,86,196,.15); border:1px solid rgba(26,86,196,.28);
    color:var(--blue-text); font-size:clamp(.64rem, 2vw, .72rem); font-weight:600;
}

/* ══════════════════════════════════════
   MODAL — centered (desktop) / bottom sheet (responsive)
══════════════════════════════════════ */
.modal-overlay{
    position:fixed; inset:0; z-index:99999;
    background:var(--scrim);
    backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
    display:none; align-items:flex-end; justify-content:center;
}
.modal-overlay.show{ display:flex; animation:acFade .18s ease both; }
@keyframes acFade{ from{ opacity:0; } to{ opacity:1; } }
@keyframes acSlideUp{ from{ transform:translateY(60px); opacity:0; } to{ transform:none; opacity:1; } }

.modal{
    position:relative; width:100%; max-width:480px;
    background:var(--card-bg);
    border:1px solid var(--line); border-bottom:none;
    border-radius:10px 10px 0 0;
    padding:0 18px calc(env(safe-area-inset-bottom, 0px) + 18px);
    max-height:78vh; overflow-y:auto;
    animation:acSlideUp .28s cubic-bezier(.32,.72,0,1);
}
.modal::before{
    content:''; display:block; flex-shrink:0;
    width:36px; height:4px; background:rgba(var(--fg),.18);
    border-radius:4px; margin:10px auto 2px;
}
.modal-close{
    position:absolute; top:12px; right:12px;
    width:28px; height:28px; flex-shrink:0;
    background:rgba(var(--fg),.08); border:none; border-radius:4px;
    color:var(--sub); font-size:1.05rem;
    display:flex; align-items:center; justify-content:center;
    transition:background .2s, color .2s;
}
.modal-close:hover{ background:rgba(var(--fg),.18); color:var(--ui-text); }
.modal h3{ font-size:.9rem; font-weight:700; margin:6px 34px 10px 0; display:flex; align-items:center; gap:8px; color:var(--ui-text); }
.modal h3 i{ color:var(--blue); }
.modal-empty-note{ font-size:.78rem; color:var(--muted); line-height:1.65; }
.name-hist-row{
    display:flex; align-items:center; gap:10px;
    padding:10px 0; border-bottom:1px solid var(--line);
    font-size:.78rem; color:var(--sub);
}
.name-hist-row:last-child{ border-bottom:none; }
.name-hist-row i{ color:var(--blue); font-size:.9rem; flex-shrink:0; }
.name-hist-date{ margin-left:auto; color:var(--muted); font-size:.68rem; flex-shrink:0; }

@media (min-width:600px){
    .modal-overlay{ align-items:center; padding:20px; }
    .modal{ border-radius:8px; border-bottom:1px solid var(--line); max-width:400px; padding:20px; }
    .modal::before{ display:none; }
    .modal h3{ margin-top:0; }
}
@media (max-width:490px){
    .modal{ padding:0 14px calc(env(safe-area-inset-bottom, 0px) + 14px); }
    .modal h3{ font-size:.84rem; }
    .name-hist-row{ font-size:.72rem; }
}
@media (max-width:380px){
    .modal{ padding:0 12px calc(env(safe-area-inset-bottom, 0px) + 12px); }
}

/* ══════════════════════════════════════
   SKELETON — same lane, same lines
══════════════════════════════════════ */
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
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; border-bottom:1px solid var(--line); }
.sk-back{ width:20px; height:20px; }
.sk-title{ width:130px; height:14px; }
.sk-avatar-block{ display:flex; flex-direction:column; align-items:center; gap:10px; padding:20px 0; }
.sk-avatar-circle{ width:clamp(64px,19vw,90px); height:clamp(64px,19vw,90px); border-radius:16%; }
.sk-name{ width:130px; height:14px; }
.sk-sub{ width:90px; height:10px; }
.sk-row-item{ display:flex; align-items:center; gap:14px; padding:clamp(11px,3.2vw,16px) var(--pad); border-top:1px solid var(--line); }
.sk-icon{ width:clamp(27px,7.6vw,36px); height:clamp(27px,7.6vw,36px); border-radius:6px; flex-shrink:0; }
.sk-text{ display:flex; flex-direction:column; gap:6px; }
.sk-lbl{ width:80px; height:9px; }
.sk-val{ width:140px; height:12px; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Kept near the end so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --ctl-h:clamp(33px, 8.8vw, 39.6px);
        --r:5.5px;
    }

    /* topbar */
    .ac-topbar{ padding:0 11px; }
    .ac-x{ width:37px; height:37px; }
    .ac-x svg{ width:24px; height:24px; }
    .ac-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* pod header */
    .profile-header{ padding:clamp(15px, 4.4vw, 24px) var(--pad) clamp(18px, 5vw, 26px); }
    .avatar{ width:clamp(70px, 20.9vw, 99px); height:clamp(70px, 20.9vw, 99px); border-width:3.85px; margin-bottom:13px; }
    .profile-name{ font-size:clamp(.97rem, 3.74vw, 1.155rem); gap:6.6px; margin-bottom:3.3px; }
    .profile-label{ font-size:clamp(.64rem, 2.09vw, .75rem); }
    .transparency-note{ font-size:clamp(.66rem, 2.09vw, .75rem); margin-top:13px; max-width:374px; }
    .adult-flag{ gap:5.5px; margin-top:11px; padding:4.4px 12px 4.4px 8.8px; font-size:clamp(.64rem, 2.09vw, .75rem); }

    /* stats bar */
    .stat-item{ padding:clamp(12px, 3.74vw, 17.6px) 6.6px; }
    .stat-num{ font-size:clamp(.97rem, 3.52vw, 1.32rem); }
    .stat-lbl{ font-size:clamp(.53rem, 1.87vw, .66rem); margin-top:4.4px; }

    /* section label */
    .section-label{ padding:clamp(15px, 4.4vw, 22px) var(--pad) 6.6px; font-size:.66rem; letter-spacing:1.65px; }

    /* info rows */
    .info-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(12px, 3.52vw, 17.6px) var(--pad); }
    .info-icon{ width:clamp(30px, 8.36vw, 39.6px); height:clamp(30px, 8.36vw, 39.6px); border-radius:6.6px; font-size:clamp(.86rem, 2.86vw, 1.1rem); }
    .info-label{ font-size:clamp(.59rem, 1.98vw, .73rem); }
    .info-value{ font-size:clamp(.79rem, 2.64vw, .95rem); margin-top:2.2px; }
    .chevron{ font-size:1.1rem; }

    /* pod id + copy */
    .profile-id-value{ font-size:clamp(.77rem, 2.53vw, .95rem); margin-top:3.3px; }
    .copy-btn{ height:clamp(27.5px, 7.92vw, 33px); padding:0 13px; gap:5.5px; font-size:.77rem; border-radius:4.4px; }
    .copy-btn i{ font-size:.9rem; }
    #copy-toast{ bottom:97px; font-size:.81rem; padding:10px 20px; }

    /* notes */
    .mutual-notes{ padding:0 var(--pad) 26px; gap:8.8px; }
    .mutual-note{ font-size:clamp(.66rem, 2.09vw, .77rem); }

    /* description boxes */
    .text-block{ padding:0 var(--pad) 6.6px; }
    .desc-box{ padding:clamp(12px, 3.74vw, 15px) clamp(12px, 3.74vw, 17.6px); font-size:clamp(.77rem, 2.53vw, .88rem); }

    /* rules */
    .rule-row{ gap:clamp(11px, 3.3vw, 13px); padding:clamp(11px, 3.3vw, 13px) 0; font-size:clamp(.75rem, 2.42vw, .86rem); }
    .rule-icon{ width:clamp(27.5px, 7.7vw, 33px); height:clamp(27.5px, 7.7vw, 33px); border-radius:6.6px; font-size:clamp(.79rem, 2.64vw, .97rem); }
    .strike-card{ padding:15.4px 15.4px 6.6px; margin:15.4px 0 6.6px; }
    .strike-head{ gap:7.7px; font-size:.83rem; margin-bottom:11px; }
    .strike-head i{ font-size:16.5px; }
    .strike-row{ gap:12px; padding:8.8px 0; }
    .strike-num{ width:24px; height:24px; font-size:.75rem; }
    .strike-text{ font-size:clamp(.7rem, 2.2vw, .79rem); }

    /* people row */
    .people-row{ gap:15.4px; padding:6.6px var(--pad) 4.4px; }
    .person{ gap:6.6px; min-width:66px; }
    .person img, .person-av-susp{ width:clamp(42px, 12.1vw, 55px); height:clamp(42px, 12.1vw, 55px); border-width:2.2px; }
    .person-name{ font-size:.73rem; max-width:70px; }
    .person-role{ font-size:.62rem; }

    /* tags */
    .tags-wrap{ gap:8.8px; padding:6.6px var(--pad) 4.4px; }
    .tag-pill{ gap:4.4px; padding:5.5px 13px; font-size:clamp(.7rem, 2.2vw, .79rem); }

    /* name-history modal */
    .modal{ padding:0 20px calc(env(safe-area-inset-bottom, 0px) + 20px); }
    .modal::before{ width:40px; height:4.4px; margin:11px auto 2.2px; }
    .modal-close{ top:13px; right:13px; width:31px; height:31px; font-size:1.155rem; }
    .modal h3{ font-size:.99rem; margin:6.6px 37px 11px 0; gap:8.8px; }
    .modal-empty-note{ font-size:.86rem; }
    .name-hist-row{ gap:11px; padding:11px 0; font-size:.86rem; }
    .name-hist-row i{ font-size:.99rem; }
    .name-hist-date{ font-size:.75rem; }

    /* skeleton */
    .sk-topbar{ padding:0 17.6px 0 15px; }
    .sk-back{ width:22px; height:22px; }
    .sk-title{ width:143px; height:15.4px; }
    .sk-avatar-block{ gap:11px; padding:22px 0; }
    .sk-avatar-circle{ width:clamp(70px, 20.9vw, 99px); height:clamp(70px, 20.9vw, 99px); }
    .sk-name{ width:143px; height:15.4px; }
    .sk-sub{ width:99px; height:11px; }
    .sk-row-item{ gap:15px; padding:clamp(12px, 3.52vw, 17.6px) var(--pad); }
    .sk-icon{ width:clamp(30px, 8.36vw, 39.6px); height:clamp(30px, 8.36vw, 39.6px); border-radius:6.6px; }
    .sk-text{ gap:6.6px; }
    .sk-lbl{ width:88px; height:10px; }
    .sk-val{ width:154px; height:13px; }
}

/* centered-card range: modal stays a card, a bit wider */
@media (min-width:600px) and (max-width:800px){
    .modal{ max-width:440px; padding:22px; }
    .modal h3{ margin-top:0; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .ac-x{ width:33px; height:33px; }
    .ac-title{ font-size:.92rem; }
    .copy-btn{ font-size:.68rem; padding:0 10px; gap:4.4px; }
    .modal{ padding:0 15.4px calc(env(safe-area-inset-bottom, 0px) + 15.4px); }
    .modal h3{ font-size:.92rem; }
    .name-hist-row{ font-size:.79rem; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .ac-title{ font-size:.88rem; }
    .copy-btn{ font-size:.64rem; padding:0 8px; }
    .modal{ padding:0 13px calc(env(safe-area-inset-bottom, 0px) + 13px); }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the other Dezzme pages. The blue accent of pods stays the same in both themes. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --blue-text:#1a56db;
    --card-bg:#ffffff;
    --pop-bg:#ffffff;
    --scrim:rgba(6,7,26,.45);
    --ph:#eceef5;
    --ok:#15a34a;
    --danger-t:#d92b2b;
    --danger-t2:#d92b2b;
}
[data-theme="light"] #copy-toast{ border-color:rgba(21,163,74,.35); box-shadow:0 8px 24px rgba(0,0,0,.18); }
[data-theme="light"] .copy-btn.copied{ background:rgba(21,163,74,.12); }
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
        <div class="sk-avatar-block">
            <div class="sk sk-avatar-circle"></div>
            <div class="sk sk-name"></div>
            <div class="sk sk-sub"></div>
        </div>
        <?php for($i=0;$i<5;$i++): ?>
        <div class="sk-row-item">
            <div class="sk sk-icon"></div>
            <div class="sk-text"><div class="sk sk-lbl"></div><div class="sk sk-val"></div></div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="acClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="ac-title">About This Pod</span>
    </div>

    <!-- ══ POD HEADER ══ -->
    <div class="profile-header">
        <?php if($pod_suspended): ?>
        <div class="avatar avatar-susp" role="img" aria-label="Suspended pod"></div>
        <?php else: ?>
        <img class="avatar" src="<?php echo htmlspecialchars($group_img); ?>" onerror="this.src='uploads/default_group.png'" alt="Pod picture">
        <?php endif; ?>
        <div class="profile-name"><?php echo htmlspecialchars($group['title']); ?></div>
        <div class="profile-label">About This Pod</div>

        <?php if($pod_suspended): ?>
        <span class="pod-susp-flag">Suspended</span>
        <?php else: ?>
        <span class="adult-flag <?php echo $is_adult_pod ? 'yes' : 'no'; ?>">
            <i class="<?php echo $is_adult_pod ? 'ri-error-warning-fill' : 'ri-shield-check-fill'; ?>"></i>
            <?php echo $is_adult_pod ? '18+ / NSFW Pod' : 'General Audience'; ?>
        </span>
        <?php endif; ?>

        <p class="transparency-note">
            Dezzme shows this page to help the community understand what a pod is really about before joining, posting, or engaging. Creation date, name history, and activity give you the context to judge whether a pod is what it claims to be.
            <a href="help.php#transparency">Learn More</a>
        </p>
    </div>

    <!-- ══ STATS BAR ══ -->
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-num"><?php echo number_format($member_count); ?></div>
            <div class="stat-lbl">Members</div>
        </div>
        <div class="stat-item">
            <div class="stat-num"><?php echo number_format($total_posts); ?></div>
            <div class="stat-lbl">Posts</div>
        </div>
        <div class="stat-item">
            <div class="stat-num"><?php echo number_format($admin_count); ?></div>
            <div class="stat-lbl">Admins</div>
        </div>
    </div>

    <!-- ══ POD INFO ══ -->
    <div class="section-label">Pod Info</div>
    <div class="info-list">
        <hr class="divider">

        <div class="info-row">
            <div class="info-icon"><i class="ri-calendar-check-line"></i></div>
            <div class="info-content">
                <div class="info-label">Date Created</div>
                <div class="info-value"><?php echo date('F j, Y', strtotime($group['created_at'])); ?></div>
            </div>
        </div>

        <hr class="divider">

        <!-- Pod ID -->
        <div class="info-row">
            <div class="info-icon"><i class="ri-fingerprint-line"></i></div>
            <div class="info-content">
                <div class="info-label">Pod ID</div>
                <div class="profile-id-value" id="pd-pid"><span class="pid-prefix">PD-</span><span class="pid-digits"><?php echo htmlspecialchars($pd_digits); ?></span></div>
            </div>
            <button type="button" class="copy-btn" id="copy-pid-btn" onclick="copyPodId()" aria-label="Copy Pod ID">
                <i class="ri-file-copy-line"></i> Copy
            </button>
        </div>

        <hr class="divider">

        <a href="profile.php?user_id=<?php echo (int)$group['creator_id']; ?>">
            <div class="info-row clickable">
                <div class="info-icon"><i class="ri-vip-crown-line"></i></div>
                <div class="info-content">
                    <div class="info-label">Pod Creator</div>
                    <div class="info-value"><?php echo htmlspecialchars($group['creator_name']); ?></div>
                </div>
                <i class="ri-arrow-right-s-line chevron"></i>
            </div>
        </a>

        <hr class="divider">

        <div class="info-row <?php echo $name_change_count > 0 ? 'clickable' : ''; ?>" <?php echo $name_change_count > 0 ? 'onclick="openNameHistoryModal()"' : ''; ?>>
            <div class="info-icon"><i class="ri-history-line"></i></div>
            <div class="info-content">
                <div class="info-label">Former Pod Names</div>
                <div class="info-value">
                    <?php
                    if($name_change_count == 0) echo '<span style="color:var(--muted)">No changes</span>';
                    elseif($name_change_count == 1) echo '1 change';
                    else echo $name_change_count . ' changes';
                    ?>
                </div>
            </div>
            <?php if($name_change_count > 0): ?><i class="ri-arrow-right-s-line chevron"></i><?php endif; ?>
        </div>

        <hr class="divider">

        <div class="info-row">
            <div class="info-icon"><i class="ri-sun-line"></i></div>
            <div class="info-content">
                <div class="info-label">Posts Made Today</div>
                <div class="info-value"><?php echo number_format($posts_today); ?></div>
            </div>
        </div>

        <hr class="divider">

        <div class="info-row">
            <div class="info-icon"><i class="ri-user-add-line"></i></div>
            <div class="info-content">
                <div class="info-label">New Members This Week</div>
                <div class="info-value"><?php echo number_format($new_members_week); ?></div>
            </div>
        </div>

        <hr class="divider">

        <div class="info-row">
            <div class="info-icon"><i class="ri-pulse-line"></i></div>
            <div class="info-content">
                <div class="info-label">Online Right Now</div>
                <div class="info-value" style="color:<?php echo $active_now > 0 ? 'var(--ok)' : 'inherit'; ?>"><?php echo number_format($active_now); ?> member<?php echo $active_now == 1 ? '' : 's'; ?></div>
            </div>
        </div>

        <hr class="divider">

        <div class="info-row">
            <div class="info-icon"><i class="ri-time-line"></i></div>
            <div class="info-content">
                <div class="info-label">Peak Posting Hour</div>
                <div class="info-value"><?php echo $peak_hour; ?></div>
            </div>
        </div>

        <hr class="divider">

        <div class="info-row">
            <div class="info-icon"><i class="ri-heart-line"></i></div>
            <div class="info-content">
                <div class="info-label">Total Likes Across Pod</div>
                <div class="info-value"><?php echo number_format($total_likes); ?></div>
            </div>
        </div>

        <hr class="divider">

        <div class="info-row">
            <div class="info-icon"><i class="ri-repeat-line"></i></div>
            <div class="info-content">
                <div class="info-label">Total Reposts Across Pod</div>
                <div class="info-value"><?php echo number_format($total_reposts); ?></div>
            </div>
        </div>

        <hr class="divider">

        <div class="info-row">
            <div class="info-icon"><i class="ri-discuss-line"></i></div>
            <div class="info-content">
                <div class="info-label">Discussions &amp; Replies</div>
                <div class="info-value"><?php echo number_format($disc_count); ?> discussions · <?php echo number_format($disc_replies); ?> replies</div>
            </div>
        </div>

        <hr class="divider">
    </div>

    <!-- ══ DESCRIPTION ══ -->
    <div class="section-label">About This Pod</div>
    <div class="text-block">
        <div class="desc-box"><?php echo nl2br(htmlspecialchars($group['description'] ?? 'No description set.')); ?></div>
    </div>

    <!-- ══ ADMINS ══ -->
    <?php if(!empty($admins)): ?>
    <div class="section-label">Admins (<?php echo count($admins); ?>)</div>
    <div class="people-row">
        <?php foreach($admins as $adm):
            $ap = !empty($adm['profile_pic']) ? htmlspecialchars($adm['profile_pic']) : 'mee.jpg';
            $adm_susp = (strtolower((string)($adm['user_rank'] ?? '')) === 'suspended');
        ?>
        <a href="profile.php?user_id=<?php echo (int)$adm['id']; ?>" class="person">
            <?php if($adm_susp): ?><span class="person-av-susp"></span><?php else: ?>
            <img src="<?php echo $ap; ?>" onerror="this.src='mee.jpg'" alt="">
            <?php endif; ?>
            <span class="person-name"><?php echo htmlspecialchars($adm['username']); ?></span>
            <span class="person-role"><?php echo $adm_susp ? 'SUSPENDED' : 'ADMIN'; ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══ POPULAR TOPICS ══ -->
    <?php if(!empty($top_tags)): ?>
    <div class="section-label">Popular Topics</div>
    <div class="tags-wrap">
        <?php foreach($top_tags as $tag): ?>
        <span class="tag-pill"><i class="ri-hashtag" style="font-size:11px;"></i><?php echo htmlspecialchars($tag); ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══ UNIVERSAL RULES ══ -->
    <div class="section-label">Universal Pod Rules</div>
    <div class="text-block">
        <div class="rule-row">
            <div class="rule-icon"><i class="ri-focus-3-line"></i></div>
            <span>Post only content relevant to the pod's genre/theme — off-topic posts get removed immediately</span>
        </div>
        <div class="rule-row">
            <div class="rule-icon"><i class="ri-links-line"></i></div>
            <span>Tag the original creator when remixing content</span>
        </div>
        <div class="rule-row">
            <div class="rule-icon"><i class="ri-spam-2-line"></i></div>
            <span>No spam — repeated posting of the same meme is an instant removal</span>
        </div>
        <div class="rule-row">
            <div class="rule-icon"><i class="ri-user-forbid-line"></i></div>
            <span>No impersonating other users or creators</span>
        </div>
        <div class="rule-row" style="border-bottom:none;">
            <div class="rule-icon"><i class="ri-emotion-line"></i></div>
            <span>Respect the pod's tone — every pod has its own culture</span>
        </div>

        <div class="strike-card">
            <div class="strike-head"><i class="ri-alarm-warning-line"></i> Strike System</div>
            <div class="strike-row">
                <span class="strike-num">1</span>
                <span class="strike-text">Warning</span>
            </div>
            <div class="strike-row">
                <span class="strike-num">2</span>
                <span class="strike-text">Temporary removal</span>
            </div>
            <div class="strike-row danger">
                <span class="strike-num">3</span>
                <span class="strike-text">Permanent ban — you can <strong>NEVER</strong> rejoin that pod or ANY pod created by that creator, ever</span>
            </div>
        </div>
    </div>

    <!-- ══ CREATOR GUIDELINES ══ -->
    <div class="section-label">Creator's Additional Guidelines</div>
    <div class="text-block" style="padding-bottom:24px;">
        <?php if(!empty($group['custom_guidelines'])): ?>
            <div class="desc-box"><?php echo nl2br(htmlspecialchars($group['custom_guidelines'])); ?></div>
        <?php else: ?>
            <div class="desc-box empty">No additional guidelines set by the creator.</div>
        <?php endif; ?>
    </div>

    <!-- ══ DEFINITIONS ══ -->
    <div class="mutual-notes">
        <div class="mutual-note">
            <strong>Pod IDs</strong> are permanent identifiers used for authenticity, moderation, reporting, and pod recognition across Dezzme. Even if a pod's name changes many times, its Pod ID stays the same forever — making it easier to identify pods and adding another layer of transparency and trust within the Dezzme community.
        </div>
    </div>

</div><!-- /.ac-lane -->

<!-- ══ COPY TOAST ══ -->
<div id="copy-toast"><i class="ri-check-line" style="margin-right:5px;"></i>Pod ID copied.</div>

<!-- ══ NAME HISTORY MODAL ══ -->
<div class="modal-overlay" id="nameHistoryModal" onclick="closeNameHistoryModalOutside(event)">
    <div class="modal">
        <button type="button" class="modal-close" onclick="closeNameHistoryModal()" aria-label="Close"><i class="ri-close-line"></i></button>
        <h3><i class="ri-history-line"></i> Pod Name History</h3>
        <?php if(empty($name_history)): ?>
            <p class="modal-empty-note">This pod has never had its name changed before.</p>
        <?php else: ?>
            <?php foreach($name_history as $nh_row): ?>
            <div class="name-hist-row">
                <i class="ri-arrow-left-right-line"></i>
                <span>"<?php echo htmlspecialchars($nh_row['old_title']); ?>"</span>
                <span class="name-hist-date"><?php echo date('d M Y', strtotime($nh_row['changed_at'])); ?></span>
            </div>
            <?php endforeach; ?>
            <div class="name-hist-row" style="border-top:1px solid rgba(26,86,196,0.2);margin-top:6px;padding-top:12px;">
                <i class="ri-check-double-line" style="color:var(--ok);"></i>
                <span style="color:var(--ui-text);font-weight:600;">"<?php echo htmlspecialchars($group['title']); ?>"</span>
                <span class="name-hist-date">Current</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ── keep the theme in sync if it is switched in another tab ──
window.addEventListener('storage', function(e){
    if(e.key !== 'dz_theme') return;
    if(e.newValue === 'light') document.documentElement.setAttribute('data-theme','light');
    else document.documentElement.removeAttribute('data-theme');
});

// ── X: go back, or to the pod if there is no history ──
function acClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'group.php?id=<?php echo (int)$group_id; ?>';
}

// ── Skeleton ──────────────────────────────────────────────
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

// ── Copy Pod ID ───────────────────────────────────────────
var _copyToastTimer = null;
function copyPodId(){
    var pid   = 'PD-' + document.querySelector('#pd-pid .pid-digits').textContent.trim();
    var btn   = document.getElementById('copy-pid-btn');
    var toast = document.getElementById('copy-toast');

    if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(pid).then(function(){ showCopied(btn, toast); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = pid;
        ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        try { document.execCommand('copy'); } catch(e){}
        document.body.removeChild(ta);
        showCopied(btn, toast);
    }
}
function showCopied(btn, toast){
    btn.classList.add('copied');
    btn.innerHTML = '<i class="ri-check-line"></i> Copied';
    toast.classList.add('show');
    clearTimeout(_copyToastTimer);
    _copyToastTimer = setTimeout(function(){
        btn.classList.remove('copied');
        btn.innerHTML = '<i class="ri-file-copy-line"></i> Copy';
        toast.classList.remove('show');
    }, 2200);
}

// ── Name history modal ────────────────────────────────────
function openNameHistoryModal(){
    document.getElementById('nameHistoryModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeNameHistoryModal(){
    document.getElementById('nameHistoryModal').classList.remove('show');
    document.body.style.overflow = '';
}
function closeNameHistoryModalOutside(e){ if(e.target === document.getElementById('nameHistoryModal')) closeNameHistoryModal(); }
document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeNameHistoryModal(); });
</script>
</body>
</html>