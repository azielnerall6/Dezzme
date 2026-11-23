<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user){ header("Location: login.php"); exit(); }

$user = $conn->query("SELECT * FROM users WHERE id=$current_user")->fetch_assoc();
$profile_pic = $user['profile_pic'] ?? 'uploads/me.jpeg';
$username = $user['username'] ?? 'You';

$total_unread = 0;
if(!empty($_SESSION['accounts'])){
    foreach($_SESSION['accounts'] as $acc_id){
        $acc_id = (int)$acc_id;
        $unread = $conn->query("SELECT COUNT(*) as cnt FROM messages WHERE receiver_id=$acc_id AND seen=0")->fetch_assoc()['cnt'] ?? 0;
        $total_unread += $unread;
    }
}

$status_map = [
    'trending'  => ['label'=>'🔥 Trending',  'class'=>'s-trending'],
    'rising'    => ['label'=>'📈 Rising',    'class'=>'s-rising'],
    'classic'   => ['label'=>'🏛 Classic',   'class'=>'s-classic'],
    'dead'      => ['label'=>'💀 Dead Meme', 'class'=>'s-dead'],
    'revived'   => ['label'=>'♻️ Revived',   'class'=>'s-revived'],
    'legendary' => ['label'=>'👑 Legendary', 'class'=>'s-legendary'],
];

$categories_list = [
    'Image Memes','Video Memes','GIF Memes','Reaction Memes',
    'Audio Memes','Anime Memes','Gaming Memes','Internet Slang',
    'Classic Internet','Brainrot','Viral Challenges','Sports Memes'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Memepedia — Dezzme</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
* { font-family: 'Poppins', sans-serif; }
button, a { cursor: pointer; outline: none; }
html { scroll-behavior: smooth; -webkit-tap-highlight-color: transparent; }

:root{
    --red:      #FF4C4C;
    --red-dim:  rgba(255,76,76,0.12);
    --red-glow: rgba(255,76,76,0.35);
    --red-border: rgba(255,76,76,0.28);
    --surface:  rgba(255,255,255,0.05);
    --surface-2: rgba(255,255,255,0.08);
    --border:   rgba(255,255,255,0.10);
    --border-2: rgba(255,255,255,0.16);
    --text:     #ffffff;
    --muted:    rgba(255,255,255,0.45);
    --muted-2:  rgba(255,255,255,0.28);
    --divider:  rgba(255,255,255,0.08);
    --gold:     #ffc947;
    --gold-dim: rgba(255,201,71,0.12);
    --gold-border: rgba(255,201,71,0.3);
    --blue:     #6ba3ff;
    --blue-dim: rgba(0,87,255,0.1);
    --blue-border: rgba(0,87,255,0.28);
    --r6:6px;--r10:10px;--r14:14px;--r18:18px;--r24:24px;
    --nav-h:64px;
    --sidebar-w:280px;
}

body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
    width: 100%;
}
::selection { background: var(--red-dim); color: var(--red); }
img, video { max-width: 100%; display: block; }
a { color: inherit; text-decoration: none; }

::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,76,76,0.3); border-radius: 99px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,76,76,0.55); }

/* ── Skeleton ── */
#page-skeleton {
    position: fixed; inset: 0;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    z-index: 9999;
    display: flex; flex-direction: column; align-items: center;
    opacity: 1; transition: opacity 0.35s ease;
    pointer-events: none; padding: 0 0 60px; overflow: hidden;
}
#page-skeleton.hide { opacity: 0; }
@keyframes shimmer { 0% { background-position: -500px 0; } 100% { background-position: 500px 0; } }
.sk {
    background: linear-gradient(90deg, rgba(255,255,255,0.04) 25%, rgba(255,255,255,0.09) 50%, rgba(255,255,255,0.04) 75%);
    background-size: 500px 100%;
    animation: shimmer 1.5s infinite linear;
    border-radius: 8px;
}
.sk-topbar { width: 100%; max-width: 1340px; display: flex; align-items: center; gap: 14px; padding: 20px 16px 16px; }
.sk-back { width: 22px; height: 22px; border-radius: 4px; flex-shrink: 0; }
.sk-title { width: 160px; height: 18px; border-radius: 6px; }
.sk-grid { width: 100%; max-width: 1340px; padding: 0 16px; display: grid; grid-template-columns: repeat(auto-fill,minmax(240px,1fr)); gap: 14px; margin-top: 20px; }
.sk-card { border-radius: 18px; overflow: hidden; border: 1px solid var(--divider); }
.sk-media { width: 100%; aspect-ratio: 16/9; }
.sk-body { padding: 14px; display: flex; flex-direction: column; gap: 10px; }
.sk-line1 { width: 70%; height: 14px; border-radius: 5px; }
.sk-line2 { width: 100%; height: 10px; border-radius: 5px; }
.sk-line3 { width: 50%; height: 10px; border-radius: 5px; }

/* ── Topbar ── */
.topbar {
    position: sticky; top: 0; z-index: 300;
    height: var(--nav-h);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 18px;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--divider);
    gap: 10px; width: 100%;
}
.tb-left { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.back-btn {
    background: none; border: none; color: var(--text);
    font-size: 1.5rem; cursor: pointer; opacity: 0.75; padding: 0;
    display: flex; align-items: center; flex-shrink: 0;
    transition: opacity 0.2s, color 0.2s; text-decoration: none;
}
.back-btn:hover { opacity: 1; color: var(--red); }
.tb-logo {
    display: flex; align-items: center; gap: 8px;
    font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1.05rem;
    color: var(--text); letter-spacing: 0.3px; user-select: none;
}
.tb-logo span { color: var(--red); }
.tb-badge {
    font-size: 0.56rem; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase;
    background: var(--gold-dim); border: 1px solid var(--gold-border);
    color: var(--gold); padding: 2px 7px; border-radius: 20px;
}
.tb-center { flex: 1; max-width: 420px; margin: 0 auto; }
.tb-search {
    display: flex; align-items: center; gap: 8px;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r24); padding: 8px 14px; transition: all 0.2s;
}
.tb-search:focus-within { border-color: var(--red-border); background: var(--surface-2); }
.tb-search i { color: var(--muted-2); font-size: 0.95rem; flex-shrink: 0; }
.tb-search input {
    background: transparent; border: none; outline: none;
    color: var(--text); font-size: 0.8rem; font-family: 'Poppins', sans-serif; width: 100%;
}
.tb-search input::placeholder { color: var(--muted-2); }
.tb-right { display: flex; align-items: center; gap: 9px; flex-shrink: 0; }
.tb-btn {
    display: flex; align-items: center; gap: 5px;
    padding: 7px 14px; border-radius: var(--r24);
    border: 1px solid var(--border); background: var(--surface);
    color: rgba(255,255,255,0.6); font-size: 0.72rem; font-weight: 600;
    transition: all 0.2s; white-space: nowrap; font-family: 'Poppins', sans-serif;
}
.tb-btn:hover { background: var(--surface-2); border-color: var(--border-2); color: var(--text); }
.tb-btn.primary { background: var(--red-dim); border-color: var(--red-border); color: var(--red); }
.tb-btn.primary:hover { background: var(--red); color: #fff; box-shadow: 0 4px 16px var(--red-glow); }
.tb-avatar { position: relative; display: inline-block; flex-shrink: 0; }
.tb-avatar img { width: 34px; height: 34px; border-radius: 50%; border: 2px solid var(--red); object-fit: cover; display: block; }
.tb-dot { position: absolute; top: -1px; right: -1px; width: 8px; height: 8px; background: var(--red); border-radius: 50%; border: 1.5px solid #070924; }

/* ── Page shell ── */
.page-shell { display: flex; width: 100%; max-width: 1340px; margin: 0 auto; padding: 0 14px 80px; gap: 18px; align-items: flex-start; }

/* ── Left sidebar ── */
.left-sidebar { width: 220px; flex-shrink: 0; position: sticky; top: calc(var(--nav-h) + 14px); max-height: calc(100vh - var(--nav-h) - 28px); overflow-y: auto; padding: 18px 0; }
.ls-section { margin-bottom: 22px; }
.ls-label { font-size: 0.58rem; font-weight: 700; letter-spacing: 1.8px; text-transform: uppercase; color: var(--muted-2); padding: 0 12px; margin-bottom: 8px; }
.ls-item { display: flex; align-items: center; gap: 9px; padding: 9px 12px; border-radius: var(--r10); font-size: 0.78rem; font-weight: 500; color: rgba(255,255,255,0.55); cursor: pointer; transition: all 0.2s; }
.ls-item i { font-size: 0.92rem; width: 18px; text-align: center; flex-shrink: 0; }
.ls-item:hover, .ls-item.active { background: var(--surface-2); color: var(--text); }
.ls-item.active { color: var(--red); }
.ls-item.active i { color: var(--red); }
.ls-count { margin-left: auto; font-size: 0.62rem; font-weight: 700; background: var(--surface-2); border: 1px solid var(--border); color: var(--muted-2); padding: 1px 7px; border-radius: 20px; }
.ls-divider { height: 1px; background: var(--divider); margin: 8px 12px; }

/* ── Main column ── */
.main-col { flex: 1; min-width: 0; padding: 18px 0; }

.page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 22px; }
.ph-eyebrow { font-size: 0.62rem; font-weight: 700; letter-spacing: 1.8px; text-transform: uppercase; color: var(--red); margin-bottom: 5px; display: flex; align-items: center; gap: 7px; }
.ph-title { font-family: 'Syne', sans-serif; font-size: clamp(1.4rem,4vw,1.9rem); font-weight: 800; color: var(--text); letter-spacing: -0.4px; }
.ph-sub { font-size: 0.78rem; color: var(--muted); margin-top: 5px; font-weight: 300; }
.ph-actions { display: flex; align-items: center; gap: 8px; }

/* ── Toolbar ── */
.toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; padding: 12px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r14); }
.filter-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; flex: 1; }
.chip { display: flex; align-items: center; gap: 5px; padding: 6px 13px; border-radius: var(--r24); border: 1px solid var(--border); background: transparent; color: var(--muted-2); font-size: 0.68rem; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; font-family: 'Poppins', sans-serif; }
.chip:hover { border-color: var(--border-2); color: rgba(255,255,255,0.7); background: var(--surface-2); }
.chip.active { background: var(--red-dim); border-color: var(--red-border); color: var(--red); }
.toolbar-sep { width: 1px; height: 20px; background: var(--divider); flex-shrink: 0; }
.sort-select { background: #0d0d1e; border: 1px solid var(--border); border-radius: 8px; outline: none; color: rgba(255,255,255,0.6); font-size: 0.68rem; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; padding: 6px 28px 6px 10px; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.4)' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 9px center; }
.sort-select option { background: #0d0d1e; color: #fff; }

/* ── Empty state ── */
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 80px 20px; text-align: center; border: 1px dashed var(--border-2); border-radius: var(--r24); position: relative; overflow: hidden; }
.empty-state::before { content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); width: 400px; height: 250px; background: radial-gradient(ellipse, rgba(255,76,76,0.07) 0%, transparent 70%); pointer-events: none; }
.empty-icon { font-size: 3.6rem; margin-bottom: 18px; animation: float 3s ease-in-out infinite; }
@keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
.empty-title { font-family: 'Syne', sans-serif; font-size: 1.3rem; font-weight: 800; color: var(--text); margin-bottom: 10px; }
.empty-sub { font-size: 0.82rem; color: var(--muted); line-height: 1.65; max-width: 420px; font-weight: 300; margin-bottom: 24px; }
.empty-cta { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: var(--r24); background: var(--red); border: none; color: #fff; font-size: 0.8rem; font-weight: 700; transition: all 0.2s; }
.empty-cta:hover { background: #ff2e2e; box-shadow: 0 6px 22px var(--red-glow); transform: translateY(-2px); }

/* ── Article grid ── */
.article-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(250px,100%),1fr)); gap: 14px; width: 100%; }
.article-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r18); overflow: hidden; cursor: pointer; transition: all 0.25s ease; display: flex; flex-direction: column; position: relative; min-width: 0; opacity: 0; animation: cardIn 0.5s ease forwards; }
@keyframes cardIn { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
.article-card:hover { border-color: var(--red-border); background: var(--surface-2); transform: translateY(-4px); box-shadow: 0 18px 40px rgba(0,0,0,0.35); }
.article-card:hover .ac-arrow { opacity: 1; transform: translateX(0); }
.ac-media { width: 100%; aspect-ratio: 16/9; background: rgba(255,255,255,0.03); overflow: hidden; position: relative; flex-shrink: 0; }
.ac-media img, .ac-media video { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
.article-card:hover .ac-media img, .article-card:hover .ac-media video { transform: scale(1.04); }
.ac-media-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, rgba(255,255,255,0.03), rgba(255,255,255,0.06)); font-size: 2.6rem; }
.ac-media-type { position: absolute; top: 8px; left: 8px; font-size: 0.56rem; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; background: rgba(6,7,26,0.75); backdrop-filter: blur(8px); border: 1px solid var(--border-2); color: rgba(255,255,255,0.7); padding: 3px 8px; border-radius: 20px; }
.ac-body { padding: 13px 14px 9px; flex: 1; display: flex; flex-direction: column; gap: 7px; }
.ac-status { font-size: 0.62rem; font-weight: 700; padding: 3px 9px; border-radius: 20px; display: inline-flex; align-items: center; gap: 4px; align-self: flex-start; border: 1px solid; }
.s-trending { background: rgba(255,80,0,0.12); border-color: rgba(255,80,0,0.3); color: #FF8040; }
.s-rising { background: rgba(0,200,80,0.1); border-color: rgba(0,200,80,0.25); color: #40E080; }
.s-classic { background: rgba(100,120,255,0.1); border-color: rgba(100,120,255,0.25); color: #8090FF; }
.s-dead { background: rgba(120,120,160,0.1); border-color: rgba(120,120,160,0.2); color: #9090B0; }
.s-revived { background: rgba(0,220,200,0.1); border-color: rgba(0,220,200,0.25); color: #00DCC8; }
.s-legendary { background: var(--gold-dim); border-color: var(--gold-border); color: var(--gold); }
.ac-title { font-family: 'Syne', sans-serif; font-size: 0.95rem; font-weight: 700; color: var(--text); line-height: 1.3; }
.ac-summary { font-size: 0.74rem; color: var(--muted); line-height: 1.55; font-weight: 300; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.ac-footer { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-top: 1px solid var(--divider); margin-top: auto; }
.ac-author { display: flex; align-items: center; gap: 6px; font-size: 0.68rem; color: var(--muted-2); }
.ac-author img { width: 18px; height: 18px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
.ac-meta { display: flex; align-items: center; gap: 9px; font-size: 0.6rem; color: var(--muted-2); }
.ac-meta span { display: flex; align-items: center; gap: 3px; }
.ac-arrow { position: absolute; top: 12px; right: 12px; width: 28px; height: 28px; border-radius: 50%; background: var(--red); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 0.85rem; opacity: 0; transform: translateX(6px); transition: all 0.2s; }

/* ── Article count bar ── */
.article-count-bar { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r10); margin-bottom: 16px; font-size: 0.72rem; color: var(--muted); }
.article-count-bar strong { color: var(--text); }
.view-toggle { display: flex; align-items: center; gap: 4px; }
.view-btn { width: 30px; height: 30px; border-radius: var(--r6); background: transparent; border: 1px solid var(--border); color: var(--muted-2); font-size: 0.82rem; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; }
.view-btn.active, .view-btn:hover { background: var(--surface-2); color: var(--text); border-color: var(--border-2); }

/* ── List view ── */
.article-list { display: flex; flex-direction: column; gap: 10px; }
.article-list .article-card { flex-direction: row; height: 90px; }
.article-list .ac-media { width: 130px; flex-shrink: 0; aspect-ratio: unset; height: 100%; }
.article-list .ac-body { padding: 10px 12px; justify-content: center; }
.article-list .ac-summary { -webkit-line-clamp: 1; }
.article-list .ac-footer { display: none; }
.article-list .ac-arrow { top: 50%; transform: translateY(-50%) translateX(6px); }
.article-list .article-card:hover .ac-arrow { transform: translateY(-50%) translateX(0); }

/* ── Right sidebar ── */
.right-sidebar { width: var(--sidebar-w); flex-shrink: 0; position: sticky; top: calc(var(--nav-h) + 14px); max-height: calc(100vh - var(--nav-h) - 28px); overflow-y: auto; padding: 18px 0; display: flex; flex-direction: column; gap: 14px; }
.rs-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r14); overflow: hidden; }
.rs-header { padding: 11px 14px; border-bottom: 1px solid var(--divider); font-family: 'Syne', sans-serif; font-size: 0.74rem; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 7px; }
.rs-header i { font-size: 0.85rem; color: var(--red); }
.rs-row { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--divider); cursor: pointer; transition: background 0.2s; }
.rs-row:last-child { border-bottom: none; }
.rs-row:hover { background: var(--surface-2); }
.rs-emoji { font-size: 1.05rem; width: 26px; text-align: center; flex-shrink: 0; }
.rs-info { flex: 1; min-width: 0; }
.rs-name { font-size: 0.72rem; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rs-sub { font-size: 0.6rem; color: var(--muted-2); margin-top: 1px; }
.rs-arrow { font-size: 0.78rem; color: var(--muted-2); flex-shrink: 0; }
.legend-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r14); padding: 14px; }
.legend-title { font-size: 0.58rem; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted-2); margin-bottom: 10px; }
.leg-item { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 0.68rem; color: var(--muted); border-bottom: 1px solid var(--divider); }
.leg-item:last-child { border-bottom: none; }
.contribute-card { background: linear-gradient(135deg, rgba(0,87,255,0.1), rgba(255,76,76,0.08)); border: 1px solid var(--blue-border); border-radius: var(--r14); padding: 16px; text-align: center; }
.contribute-title { font-family: 'Syne', sans-serif; font-size: 0.8rem; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.contribute-sub { font-size: 0.68rem; color: var(--muted); line-height: 1.55; margin-bottom: 12px; }
.contribute-btn { display: inline-flex; align-items: center; gap: 5px; padding: 7px 16px; border-radius: var(--r24); background: var(--blue-dim); border: 1px solid var(--blue-border); color: var(--blue); font-size: 0.68rem; font-weight: 700; cursor: pointer; transition: all 0.2s; }
.contribute-btn:hover { background: rgba(0,87,255,0.25); }

/* ── Article modal overlay ── */
.article-overlay { position: fixed; inset: 0; z-index: 500; background: rgba(2,2,10,0.92); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); display: flex; align-items: flex-start; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; overflow-y: auto; -webkit-overflow-scrolling: touch; }
.article-overlay.open { opacity: 1; pointer-events: all; }
.article-modal { width: 100%; max-width: 860px; min-height: 100vh; min-height: 100dvh; background: #070924; border-left: 1px solid var(--border); border-right: 1px solid var(--border); transform: translateY(30px); transition: transform 0.35s cubic-bezier(.2,.8,.3,1); position: relative; }
.article-overlay.open .article-modal { transform: translateY(0); }

.am-topbar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; background: rgba(7,9,36,0.92); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-bottom: 1px solid var(--divider); gap: 8px; flex-wrap: nowrap; }
.am-close { width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0; background: var(--surface); border: 1px solid var(--border); color: var(--text); font-size: 1.3rem; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; }
.am-close:hover { background: var(--red-dim); border-color: var(--red-border); color: var(--red); }
.am-actions { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; overflow: hidden; }
.am-action-btn { display: flex; align-items: center; gap: 5px; padding: 7px 12px; border-radius: var(--r24); border: 1px solid var(--border); background: var(--surface); color: var(--muted); font-size: 0.7rem; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap; flex-shrink: 0; }
.am-action-btn:hover { background: var(--surface-2); border-color: var(--border-2); color: var(--text); }
.am-action-btn.edit { background: var(--red-dim); border-color: var(--red-border); color: var(--red); }
.am-action-btn.edit:hover { background: var(--red); color: #fff; }
.am-views-badge { font-size: 0.62rem; font-weight: 600; color: var(--muted-2); display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.am-hero-media { width: 100%; aspect-ratio: 16/7; background: rgba(255,255,255,0.03); overflow: hidden; position: relative; }
.am-hero-media img, .am-hero-media video { width: 100%; height: 100%; object-fit: cover; }
.am-hero-media .am-no-media { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 4.5rem; background: linear-gradient(135deg, rgba(255,255,255,0.03), rgba(255,255,255,0.06)); }

.am-body { padding: 26px 22px 64px; }
.am-category-tag { display: inline-flex; align-items: center; gap: 5px; font-size: 0.62rem; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--red); margin-bottom: 10px; }
.am-title { font-family: 'Syne', sans-serif; font-size: clamp(1.4rem,5vw,2.2rem); font-weight: 800; color: var(--text); letter-spacing: -0.4px; line-height: 1.15; margin-bottom: 14px; word-break: break-word; }
.am-meta-row { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; padding-bottom: 18px; border-bottom: 1px solid var(--divider); }
.am-status-pill { font-size: 0.68rem; font-weight: 700; padding: 4px 11px; border-radius: 20px; display: inline-flex; align-items: center; gap: 4px; border: 1px solid; }
.am-sep { width: 1px; height: 14px; background: var(--divider); }
.am-meta-text { font-size: 0.7rem; color: var(--muted-2); }
.am-meta-text strong { color: var(--muted); }
.am-author-chip { display: flex; align-items: center; gap: 7px; font-size: 0.7rem; color: var(--muted); font-weight: 500; margin-left: auto; }
.am-author-chip img { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-2); }

.am-dates { display: flex; align-items: stretch; border: 1px solid var(--border); border-radius: var(--r10); overflow: hidden; margin-bottom: 26px; }
.am-date-cell { flex: 1; padding: 12px 16px; background: var(--surface); }
.am-date-cell + .am-date-cell { border-left: 1px solid var(--divider); }
.am-date-label { font-size: 0.56rem; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted-2); margin-bottom: 4px; }
.am-date-val { font-size: 0.78rem; font-weight: 600; color: var(--text); }
.am-date-time { font-size: 0.6rem; color: var(--muted-2); margin-top: 2px; }
.am-date-cell.updated { background: rgba(255,76,76,0.04); }
.am-date-cell.updated .am-date-label { color: var(--red); }

.am-info-table { display: grid; grid-template-columns: repeat(auto-fill,minmax(125px,1fr)); gap: 1px; background: var(--divider); border: 1px solid var(--border); border-radius: var(--r10); overflow: hidden; margin-bottom: 26px; }
.am-info-cell { background: var(--surface); padding: 11px 13px; }
.am-info-label { font-size: 0.55rem; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; color: var(--muted-2); margin-bottom: 4px; }
.am-info-val { font-size: 0.76rem; font-weight: 600; color: var(--text); word-break: break-word; }

.am-section-label { font-size: 0.6rem; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--red); margin-bottom: 9px; display: flex; align-items: center; gap: 6px; }
.am-section-label::after { content: ''; flex: 1; height: 1px; background: var(--red-dim); }
.am-section { margin-bottom: 30px; }
.am-summary { font-size: 0.88rem; color: var(--muted); line-height: 1.75; font-weight: 300; }
.am-body-content { font-size: 0.82rem; color: var(--muted); line-height: 1.8; font-weight: 300; white-space: pre-wrap; word-break: break-word; }
.am-body-content:empty::before { content: 'No full article written yet. Be the first to expand this entry.'; color: var(--muted-2); font-style: italic; }
.am-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.am-tag { font-size: 0.66rem; font-weight: 500; padding: 4px 11px; border-radius: var(--r24); background: var(--surface-2); border: 1px solid var(--border-2); color: var(--muted); cursor: pointer; transition: all 0.2s; }
.am-tag:hover { border-color: var(--red-border); color: var(--red); }

/* ── Edit history ── */
.edit-history-list { display: flex; flex-direction: column; }
.eh-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--divider); }
.eh-item:last-child { border-bottom: none; }
.eh-avatar { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 1px solid var(--border-2); }
.eh-info { flex: 1; min-width: 0; }
.eh-user { font-size: 0.72rem; font-weight: 600; color: var(--text); }
.eh-summary { font-size: 0.66rem; color: var(--muted); margin-top: 2px; font-weight: 300; }
.eh-time { font-size: 0.6rem; color: var(--muted-2); margin-top: 3px; }
.eh-revert { font-size: 0.6rem; font-weight: 600; color: var(--red); background: var(--red-dim); border: 1px solid var(--red-border); border-radius: 20px; padding: 3px 9px; cursor: pointer; transition: all 0.2s; flex-shrink: 0; }
.eh-revert:hover { background: var(--red); color: #fff; }

/* ── Discussion ── */
.discussion-list { display: flex; flex-direction: column; gap: 12px; }
.disc-item { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r10); padding: 14px; }
.disc-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.disc-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 1px solid var(--border-2); }
.disc-user { font-size: 0.72rem; font-weight: 600; color: var(--text); }
.disc-time { font-size: 0.6rem; color: var(--muted-2); margin-left: auto; }
.disc-text { font-size: 0.78rem; color: var(--muted); line-height: 1.6; font-weight: 300; }
.disc-input-row { display: flex; gap: 10px; align-items: flex-start; padding: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r10); }
.disc-input { flex: 1; background: transparent; border: none; outline: none; color: var(--text); font-size: 0.78rem; font-family: 'Poppins', sans-serif; resize: none; min-height: 38px; line-height: 1.5; }
.disc-input::placeholder { color: var(--muted-2); }
.disc-send { padding: 7px 16px; border-radius: var(--r24); background: var(--red); border: none; color: #fff; font-size: 0.7rem; font-weight: 700; cursor: pointer; transition: all 0.2s; flex-shrink: 0; }
.disc-send:hover { background: #ff2e2e; box-shadow: 0 4px 12px var(--red-glow); }

/* ── Related ── */
.am-related-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(min(170px,100%),1fr)); gap: 10px; }
.am-related-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r10); overflow: hidden; cursor: pointer; transition: all 0.2s; }
.am-related-card:hover { border-color: var(--red-border); background: var(--surface-2); transform: translateY(-2px); }
.am-rel-media { width: 100%; aspect-ratio: 16/9; background: rgba(255,255,255,0.03); overflow: hidden; }
.am-rel-media img { width: 100%; height: 100%; object-fit: cover; }
.am-rel-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; }
.am-rel-body { padding: 10px; }
.am-rel-title { font-size: 0.7rem; font-weight: 700; color: var(--text); line-height: 1.3; }
.am-rel-meta { font-size: 0.6rem; color: var(--muted-2); margin-top: 3px; }

/* ── Tabs ── */
.am-tabs { display: flex; align-items: center; border-bottom: 1px solid var(--divider); margin-bottom: 26px; overflow-x: auto; scrollbar-width: none; }
.am-tabs::-webkit-scrollbar { display: none; }
.am-tab { padding: 12px 16px; font-size: 0.74rem; font-weight: 600; color: var(--muted-2); cursor: pointer; transition: all 0.2s; border-bottom: 2px solid transparent; white-space: nowrap; flex-shrink: 0; background: none; border-left: none; border-right: none; border-top: none; font-family: 'Poppins', sans-serif; }
.am-tab:hover { color: var(--muted); }
.am-tab.active { color: var(--text); border-bottom-color: var(--red); }
.am-tab-panel { display: none; }
.am-tab-panel.active { display: block; }

/* ── Edit mode banner ── */
.edit-mode-banner { display: none; align-items: center; gap: 10px; padding: 10px 14px; background: rgba(255,76,76,0.07); border: 1px solid var(--red-border); border-radius: var(--r10); margin-bottom: 18px; font-size: 0.7rem; color: var(--muted); flex-wrap: wrap; }
.edit-mode-banner.show { display: flex; }
.edit-mode-banner i { color: var(--red); font-size: 0.9rem; flex-shrink: 0; }
.em-save { margin-left: auto; padding: 6px 14px; border-radius: var(--r24); background: var(--red); border: none; color: #fff; font-size: 0.64rem; font-weight: 700; cursor: pointer; transition: background 0.2s; }
.em-save:hover { background: #ff2e2e; }
.em-cancel { padding: 6px 12px; border-radius: var(--r24); background: transparent; border: 1px solid var(--border); color: var(--muted); font-size: 0.64rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
.em-cancel:hover { background: var(--surface); }

/* ── Create modal ── */
.create-overlay { position: fixed; inset: 0; z-index: 600; background: rgba(2,2,10,0.92); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); display: flex; align-items: center; justify-content: center; padding: 20px; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; overflow-y: auto; -webkit-overflow-scrolling: touch; }
.create-overlay.open { opacity: 1; pointer-events: all; }
.create-modal { width: 100%; max-width: 660px; background: #0d0e26; border: 1px solid var(--border-2); border-radius: var(--r18); overflow: hidden; transform: scale(0.96) translateY(20px); transition: transform 0.35s cubic-bezier(.2,.8,.3,1); max-height: 90vh; max-height: 90dvh; overflow-y: auto; }
.create-overlay.open .create-modal { transform: scale(1) translateY(0); }
.cm-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid var(--divider); position: sticky; top: 0; background: #0d0e26; z-index: 2; }
.cm-title { font-family: 'Syne', sans-serif; font-size: 1rem; font-weight: 800; color: var(--text); }
.cm-close { width: 32px; height: 32px; border-radius: 50%; background: var(--surface); border: 1px solid var(--border); color: var(--text); font-size: 1.05rem; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; }
.cm-close:hover { background: var(--red-dim); color: var(--red); }
.cm-body { padding: 22px; }
.cm-field { margin-bottom: 16px; }
.cm-label { display: block; font-size: 0.64rem; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--muted-2); margin-bottom: 8px; }
.cm-input, .cm-textarea, .cm-select { width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--border); border-radius: var(--r10); color: var(--text); font-family: 'Poppins', sans-serif; font-size: 0.8rem; padding: 10px 13px; outline: none; transition: border-color 0.2s; }
.cm-input:focus, .cm-textarea:focus, .cm-select:focus { border-color: var(--red-border); }
.cm-input::placeholder, .cm-textarea::placeholder { color: var(--muted-2); }
.cm-textarea { resize: vertical; min-height: 90px; line-height: 1.6; }
.cm-select { cursor: pointer; appearance: none; -webkit-appearance: none; background-color: #0d0d1e; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.4)' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
.cm-select option { background: #0d0d1e; color: #fff; }
.cm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.cm-hint { font-size: 0.66rem; color: var(--muted-2); margin-top: 6px; line-height: 1.5; }

.media-uploader { border: 2px dashed var(--border-2); border-radius: var(--r14); padding: 26px 18px; text-align: center; cursor: pointer; transition: all 0.2s; position: relative; background: var(--surface); }
.media-uploader:hover, .media-uploader.dragover { border-color: var(--red-border); background: var(--red-dim); }
.media-uploader input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.mu-icon { font-size: 1.9rem; margin-bottom: 8px; color: var(--muted-2); }
.mu-title { font-size: 0.78rem; font-weight: 600; color: var(--text); margin-bottom: 4px; }
.mu-sub { font-size: 0.66rem; color: var(--muted-2); }
.mu-preview { margin-top: 14px; border-radius: var(--r10); overflow: hidden; display: none; position: relative; }
.mu-preview img, .mu-preview video { width: 100%; border-radius: var(--r10); }
.mu-remove { position: absolute; top: 8px; right: 8px; width: 28px; height: 28px; border-radius: 50%; background: rgba(6,7,26,0.8); border: 1px solid var(--border-2); color: #fff; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; }
.mu-remove:hover { background: var(--red); border-color: var(--red); }

.status-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.status-chip { display: flex; align-items: center; gap: 4px; padding: 6px 13px; border-radius: var(--r24); border: 1px solid var(--border); background: transparent; color: var(--muted-2); font-size: 0.68rem; font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: 'Poppins', sans-serif; }
.status-chip:hover { background: var(--surface-2); color: var(--muted); }
.status-chip.selected { background: var(--red-dim); border-color: var(--red-border); color: var(--red); }

.cm-footer { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 16px 22px; border-top: 1px solid var(--divider); position: sticky; bottom: 0; background: #0d0e26; }
.cm-cancel { padding: 10px 20px; border-radius: var(--r24); background: transparent; border: 1px solid var(--border); color: var(--muted); font-size: 0.74rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.cm-cancel:hover { background: var(--surface); color: var(--text); }
.cm-submit { padding: 10px 22px; border-radius: var(--r24); background: var(--red); border: none; color: #fff; font-size: 0.74rem; font-weight: 700; cursor: pointer; transition: all 0.2s; }
.cm-submit:hover { background: #ff2e2e; box-shadow: 0 4px 16px var(--red-glow); }

.dup-warning { background: rgba(255,201,71,0.08); border: 1px solid rgba(255,201,71,0.3); border-radius: var(--r10); padding: 14px; margin-bottom: 16px; display: none; }
.dup-warning.show { display: block; }
.dup-title { font-size: 0.74rem; font-weight: 700; color: var(--gold); margin-bottom: 5px; display: flex; align-items: center; gap: 6px; }
.dup-text { font-size: 0.68rem; color: var(--muted); line-height: 1.5; }
.dup-link { display: inline-flex; align-items: center; gap: 5px; margin-top: 8px; font-size: 0.68rem; font-weight: 700; color: var(--red); cursor: pointer; text-decoration: underline; }

/* ── Toast ── */
.toast-container { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); z-index: 1000; display: flex; flex-direction: column; gap: 8px; align-items: center; pointer-events: none; }
.toast { display: flex; align-items: center; gap: 9px; padding: 10px 20px; border-radius: var(--r24); background: #111122; border: 1px solid var(--border-2); backdrop-filter: blur(20px); font-size: 0.76rem; font-weight: 600; color: var(--text); box-shadow: 0 8px 30px rgba(0,0,0,0.4); animation: toastIn 0.3s ease forwards; white-space: nowrap; }
.toast.success i { color: #40E080; }
.toast.error i { color: var(--red); }
.toast.info i { color: var(--blue); }
@keyframes toastIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

/* ══════════════════════════════
   RESPONSIVE
══════════════════════════════ */
@media (max-width: 1200px) { .left-sidebar { display: none; } .page-shell { max-width: 100%; } }
@media (max-width: 900px)  { .right-sidebar { display: none; } }

@media (max-width: 770px) {
    .topbar { padding: 0 14px; height: 58px; }
    .tb-logo { font-size: 0.95rem; }
    .tb-center { display: none; }
    .tb-btn span { display: none; }
    .tb-btn { padding: 7px 10px; }
    .page-shell { padding: 0 12px 60px; }
    .article-grid { grid-template-columns: repeat(2,1fr); gap: 10px; }
    .page-header { margin-bottom: 16px; }
    .ph-title { font-size: 1.3rem; }
    .ph-sub { font-size: 0.72rem; }
    .toolbar { padding: 10px 12px; gap: 6px; }
    .chip { font-size: 0.64rem; padding: 5px 11px; }
    .am-topbar { padding: 11px 13px; }
    .am-body { padding: 18px 14px 48px; }
    .am-title { font-size: 1.5rem; }
    .am-info-table { grid-template-columns: repeat(2,1fr); }
    .am-related-grid { grid-template-columns: repeat(2,1fr); }
    .am-action-btn span { display: none; }
    .am-action-btn { padding: 7px 10px; }
    .am-views-badge { display: none; }
    .article-modal { border-left: none; border-right: none; }
    .cm-row { grid-template-columns: 1fr; }
    .sk-grid { grid-template-columns: repeat(2,1fr); }
}

@media (max-width: 480px) {
    .topbar { padding: 0 12px; height: 56px; gap: 8px; }
    .back-btn { font-size: 1.3rem; }
    .tb-logo { font-size: 0.85rem; gap: 6px; }
    .tb-logo i { font-size: 1rem !important; }
    .tb-badge { display: none; }
    .page-shell { padding: 0 9px 60px; }
    .article-grid { grid-template-columns: repeat(2,1fr); gap: 8px; }
    .article-card { border-radius: 14px; }
    .ac-body { padding: 9px 9px 7px; gap: 5px; }
    .ac-title { font-size: 0.76rem; }
    .ac-summary { display: none; }
    .ac-status { font-size: 0.56rem; padding: 2px 8px; }
    .ac-footer { padding: 7px 9px; }
    .ac-author span { display: none; }
    .ac-meta { gap: 6px; }
    .toolbar { padding: 9px 10px; gap: 6px; }
    .page-header { margin-bottom: 14px; }
    .ph-title { font-size: 1.1rem; }
    .ph-eyebrow { font-size: 0.56rem; }
    .am-body { padding: 14px 12px 40px; }
    .am-title { font-size: 1.25rem; }
    .am-category-tag { font-size: 0.56rem; }
    .am-meta-row { gap: 6px; }
    .am-status-pill { font-size: 0.62rem; padding: 3px 9px; }
    .am-meta-text { font-size: 0.64rem; }
    .am-dates { flex-direction: column; }
    .am-date-cell + .am-date-cell { border-left: none; border-top: 1px solid var(--divider); }
    .am-info-table { grid-template-columns: 1fr 1fr; }
    .am-related-grid { grid-template-columns: 1fr 1fr; }
    .am-tab { font-size: 0.68rem; padding: 10px 12px; }
    .create-overlay { padding: 0; align-items: flex-end; }
    .create-modal { border-radius: var(--r18) var(--r18) 0 0; max-height: 95dvh; }
    .cm-body { padding: 16px; }
    .cm-header { padding: 14px 16px; }
    .cm-footer { padding: 12px 16px; }
    .article-list .article-card { height: 72px; }
    .article-list .ac-media { width: 100px; }
    .article-count-bar { font-size: 0.66rem; padding: 9px 12px; }
    .sk-grid { grid-template-columns: repeat(2,1fr); gap: 8px; }
}

@media (max-width: 370px) {
    .topbar { padding: 0 9px; height: 52px; }
    .back-btn { font-size: 1.2rem; }
    .tb-logo { font-size: 0.78rem; }
    .page-shell { padding: 0 7px 56px; }
    .article-grid { grid-template-columns: repeat(2,1fr); gap: 6px; }
    .ac-title { font-size: 0.7rem; }
    .ac-status { font-size: 0.52rem; }
    .ph-title { font-size: 1rem; }
    .ph-sub { font-size: 0.66rem; }
    .toolbar { padding: 8px 9px; }
    .chip { font-size: 0.58rem; padding: 4px 9px; }
    .sort-select { font-size: 0.6rem; padding: 5px 22px 5px 8px; }
    .am-body { padding: 12px 10px 36px; }
    .am-title { font-size: 1.1rem; }
    .am-tab { padding: 9px 10px; font-size: 0.64rem; }
    .am-info-table { grid-template-columns: 1fr 1fr; }
    .am-info-cell { padding: 9px 10px; }
    .am-related-grid { grid-template-columns: 1fr 1fr; }
    .am-section { margin-bottom: 22px; }
    .cm-body { padding: 13px; }
    .cm-input, .cm-textarea, .cm-select { font-size: 0.74rem; padding: 9px 11px; }
    .cm-label { font-size: 0.6rem; }
    .status-chip { font-size: 0.62rem; padding: 5px 11px; }
    .sk-grid { gap: 7px; }
}

@media (max-width: 330px) {
    .topbar { padding: 0 7px; height: 50px; }
    .back-btn { font-size: 1.1rem; }
    .tb-logo { font-size: 0.7rem; gap: 5px; }
    .page-shell { padding: 0 6px 50px; }
    .article-grid { grid-template-columns: repeat(2,1fr); gap: 5px; }
    .article-card { border-radius: 11px; }
    .ac-body { padding: 7px 7px 6px; }
    .ac-title { font-size: 0.64rem; }
    .ac-status { font-size: 0.48rem; padding: 2px 7px; }
    .ac-footer { padding: 6px 7px; }
    .ph-title { font-size: 0.92rem; }
    .toolbar { padding: 7px 8px; gap: 5px; }
    .chip { font-size: 0.54rem; padding: 4px 8px; }
    .am-body { padding: 10px 8px 32px; }
    .am-title { font-size: 1rem; }
    .am-tab { padding: 8px 8px; font-size: 0.6rem; }
    .am-info-table { grid-template-columns: 1fr; }
    .am-related-grid { grid-template-columns: 1fr 1fr; }
    .cm-body { padding: 11px; }
    .cm-footer { padding: 10px 12px; }
    .cm-cancel, .cm-submit { font-size: 0.68rem; padding: 9px 16px; }
}
</style>
</head>
<body>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-topbar">
        <div class="sk sk-back"></div>
        <div class="sk sk-title"></div>
    </div>
    <div class="sk-grid">
        <?php for($i=0;$i<8;$i++): ?>
        <div class="sk-card">
            <div class="sk sk-media"></div>
            <div class="sk-body">
                <div class="sk sk-line1"></div>
                <div class="sk sk-line2"></div>
                <div class="sk sk-line3"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<script>
/*
 * FIX: the skeleton above was never being removed.
 * #page-skeleton has z-index:9999 and opacity:1 by default — if nothing ever
 * adds the .hide class, it sits on top of the entire page forever, fully
 * blocking the real content underneath (even though that content is there
 * and loaded fine). This made the page look like it "never loads."
 *
 * This hide function is intentionally self-contained and placed immediately
 * after the skeleton markup, BEFORE the rest of the page's main <script>.
 * That matters: if a syntax error or runtime error ever happens later in
 * your main app script, this code has already run/registered and will still
 * fire — so the skeleton can never get permanently stuck again, no matter
 * what happens elsewhere on the page.
 *
 * It hides on window 'load' (everything finished loading), AND has a hard
 * 3s timeout fallback no matter what, so a slow/hung API call can't trap
 * the user behind the loading screen indefinitely.
 */
(function(){
    var hidden = false;
    function hideSkeleton(){
        if(hidden) return;
        hidden = true;
        var sk = document.getElementById('page-skeleton');
        if(!sk) return;
        sk.classList.add('hide');
        setTimeout(function(){ if(sk && sk.parentNode) sk.parentNode.removeChild(sk); }, 400);
    }
    window.addEventListener('load', hideSkeleton);
    setTimeout(hideSkeleton, 3000); // safety net — never trust a single trigger
})();
</script>

<!-- ══ TOPBAR ══ -->
<nav class="topbar" role="navigation" aria-label="Memepedia navigation">
    <div class="tb-left">
        <a href="javascript:history.back()" class="back-btn" aria-label="Back">
            <i class="ri-arrow-left-s-line"></i>
        </a>
        <div class="tb-logo">
            <i class="ri-book-open-line" style="color:var(--red);font-size:1.15rem;"></i>
            Meme<span>pedia</span>
        </div>
    </div>
    <div class="tb-center">
        <div class="tb-search" role="search">
            <i class="ri-search-line"></i>
            <input type="text" id="topSearch" placeholder="Search entries, slang, trends…"
                   aria-label="Search Memepedia" autocomplete="off" spellcheck="false">
        </div>
    </div>
    <div class="tb-right">
        <button class="tb-btn" onclick="goRandom()">
            <i class="ri-shuffle-line"></i><span>Random</span>
        </button>
        <button class="tb-btn primary" onclick="openCreate()">
            <i class="ri-add-line"></i><span>New Entry</span>
        </button>
        <a href="profile.php" class="tb-avatar" aria-label="Your profile">
            <img src="<?= htmlspecialchars($profile_pic) ?>" onerror="this.src='uploads/me.jpeg'" alt="Profile">
            <?php if($total_unread > 0): ?>
            <span class="tb-dot"></span>
            <?php endif; ?>
        </a>
    </div>
</nav>

<!-- ══ PAGE SHELL ══ -->
<div class="page-shell">

    <!-- LEFT SIDEBAR -->
    <aside class="left-sidebar">
        <div class="ls-section">
            <div class="ls-label">Browse</div>
            <div class="ls-item active" onclick="filterCategory('all')" data-cat="all">
                <i class="ri-apps-line"></i> All Entries
                <span class="ls-count" id="countAll">0</span>
            </div>
            <div class="ls-item" onclick="filterCategory('mine')" data-cat="mine">
                <i class="ri-user-line"></i> My Entries
                <span class="ls-count" id="countMine">0</span>
            </div>
            <div class="ls-item" onclick="filterCategory('recent')" data-cat="recent">
                <i class="ri-time-line"></i> Recently Added
            </div>
            <div class="ls-item" onclick="filterCategory('popular')" data-cat="popular">
                <i class="ri-fire-line"></i> Most Viewed
            </div>
        </div>
        <div class="ls-divider"></div>
        <div class="ls-section">
            <div class="ls-label">Categories</div>
            <?php foreach($categories_list as $cat): ?>
            <div class="ls-item" onclick="filterCategory('<?= htmlspecialchars($cat) ?>')" data-cat="<?= htmlspecialchars($cat) ?>">
                <i class="ri-hashtag"></i> <?= htmlspecialchars($cat) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="ls-divider"></div>
        <div class="ls-section">
            <div class="ls-label">Status</div>
            <div class="ls-item" onclick="filterStatus('trending')"><i class="ri-fire-fill" style="color:#FF8040;"></i> Trending</div>
            <div class="ls-item" onclick="filterStatus('rising')"><i class="ri-arrow-up-line" style="color:#40E080;"></i> Rising</div>
            <div class="ls-item" onclick="filterStatus('classic')"><i class="ri-building-line" style="color:#8090FF;"></i> Classic</div>
            <div class="ls-item" onclick="filterStatus('legendary')"><i class="ri-vip-crown-line" style="color:var(--gold);"></i> Legendary</div>
            <div class="ls-item" onclick="filterStatus('dead')"><i class="ri-skull-line" style="color:#9090B0;"></i> Dead Meme</div>
            <div class="ls-item" onclick="filterStatus('revived')"><i class="ri-refresh-line" style="color:#00DCC8;"></i> Revived</div>
        </div>
    </aside>

    <!-- MAIN COLUMN -->
    <main class="main-col" id="mainCol">

        <div class="page-header">
            <div class="ph-left">
                <div class="ph-eyebrow"><i class="ri-book-open-line"></i> Community Encyclopedia</div>
                <h1 class="ph-title">Memepedia</h1>
                <p class="ph-sub">The free, open encyclopedia of internet meme culture. Written by everyone.</p>
            </div>
            <div class="ph-actions">
                <button class="tb-btn primary" onclick="openCreate()">
                    <i class="ri-add-line"></i><span>New Entry</span>
                </button>
            </div>
        </div>

        <div class="toolbar">
            <div class="filter-chips" id="filterChips">
                <button class="chip active" data-filter="all" onclick="setFilter(this,'all')">
                    <i class="ri-apps-line"></i> All
                </button>
                <button class="chip" data-filter="trending" onclick="setFilter(this,'trending')">🔥 Trending</button>
                <button class="chip" data-filter="rising" onclick="setFilter(this,'rising')">📈 Rising</button>
                <button class="chip" data-filter="classic" onclick="setFilter(this,'classic')">🏛 Classic</button>
                <button class="chip" data-filter="legendary" onclick="setFilter(this,'legendary')">👑 Legendary</button>
                <button class="chip" data-filter="dead" onclick="setFilter(this,'dead')">💀 Dead</button>
            </div>
            <div class="toolbar-sep"></div>
            <select class="sort-select" id="sortSelect" onchange="sortArticles()">
                <option value="newest">Newest First</option>
                <option value="oldest">Oldest First</option>
                <option value="views">Most Viewed</option>
                <option value="edits">Most Edited</option>
                <option value="az">A → Z</option>
            </select>
        </div>

        <div class="article-count-bar">
            <span><strong id="visibleCount">0</strong> entries found</span>
            <div class="view-toggle">
                <button class="view-btn active" id="gridViewBtn" onclick="setView('grid')" title="Grid view">
                    <i class="ri-grid-line"></i>
                </button>
                <button class="view-btn" id="listViewBtn" onclick="setView('list')" title="List view">
                    <i class="ri-list-check"></i>
                </button>
            </div>
        </div>

        <div class="article-grid" id="articleGrid"></div>

        <div class="empty-state" id="emptyState" style="display:none;">
            <div class="empty-icon">📭</div>
            <h2 class="empty-title">No entries yet</h2>
            <p class="empty-sub">Memepedia is blank — but that's the point. Be the first to document a meme, a piece of internet slang, or a viral moment.</p>
            <button class="empty-cta" onclick="openCreate()">
                <i class="ri-edit-line"></i> Write the First Entry
            </button>
        </div>

    </main>

    <!-- RIGHT SIDEBAR -->
    <aside class="right-sidebar">
        <div class="contribute-card">
            <div class="contribute-title">✍️ Preserve Internet Culture</div>
            <div class="contribute-sub">Document memes, slang, and viral moments before they're forgotten.</div>
            <button class="contribute-btn" onclick="openCreate()">
                <i class="ri-add-circle-line"></i> Create Entry
            </button>
        </div>
        <div class="legend-card">
            <div class="legend-title">Entry Status Guide</div>
            <div class="leg-item">🔥 <span style="margin-left:6px;"><strong style="color:#FF8040;">Trending</strong> — Currently viral</span></div>
            <div class="leg-item">📈 <span style="margin-left:6px;"><strong style="color:#40E080;">Rising</strong> — Growing fast</span></div>
            <div class="leg-item">🏛 <span style="margin-left:6px;"><strong style="color:#8090FF;">Classic</strong> — Stood the test of time</span></div>
            <div class="leg-item">💀 <span style="margin-left:6px;"><strong style="color:#9090B0;">Dead Meme</strong> — Peak has passed</span></div>
            <div class="leg-item">♻️ <span style="margin-left:6px;"><strong style="color:#00DCC8;">Revived</strong> — Making a comeback</span></div>
            <div class="leg-item">👑 <span style="margin-left:6px;"><strong style="color:var(--gold);">Legendary</strong> — All-time iconic</span></div>
        </div>
        <div class="rs-card">
            <div class="rs-header"><i class="ri-add-circle-line"></i> Recently Added</div>
            <div id="recentList"><div style="padding:20px;text-align:center;font-size:0.72rem;color:var(--muted-2);">No entries yet</div></div>
        </div>
        <div class="rs-card">
            <div class="rs-header"><i class="ri-trophy-line"></i> Most Viewed</div>
            <div id="popularList"><div style="padding:20px;text-align:center;font-size:0.72rem;color:var(--muted-2);">No entries yet</div></div>
        </div>
    </aside>

</div>

<!-- ══ CREATE MODAL ══ -->
<div class="create-overlay" id="createOverlay" role="dialog" aria-modal="true">
    <div class="create-modal" id="createModal">
        <div class="cm-header">
            <div class="cm-title" id="cmTitle">📝 New Meme Entry</div>
            <button class="cm-close" onclick="closeCreate()"><i class="ri-close-line"></i></button>
        </div>
        <div class="cm-body">
            <div class="dup-warning" id="dupWarning">
                <div class="dup-title"><i class="ri-alert-line"></i> Entry Already Exists</div>
                <div class="dup-text">An entry for "<span id="dupName"></span>" already exists.</div>
                <span class="dup-link" onclick="openDuplicate()"><i class="ri-external-link-line"></i> View and edit that entry instead</span>
            </div>
            <div class="cm-field">
                <label class="cm-label" for="cm_title">Meme Name / Title *</label>
                <input type="text" class="cm-input" id="cm_title" placeholder="e.g. Distracted Boyfriend, Doge, NPC…" oninput="checkDuplicate(this.value)" autocomplete="off" spellcheck="false">
                <div class="cm-hint">This becomes the article title and URL slug.</div>
            </div>
            <div class="cm-field">
                <label class="cm-label">Upload Media</label>
                <div class="media-uploader" id="mediaUploader"
                     ondragover="event.preventDefault();this.classList.add('dragover')"
                     ondragleave="this.classList.remove('dragover')"
                     ondrop="handleDrop(event)">
                    <input type="file" id="cm_media" accept="image/*,video/*,image/gif" onchange="previewMedia(this)">
                    <div class="mu-icon"><i class="ri-upload-cloud-line"></i></div>
                    <div class="mu-title">Drop or click to upload</div>
                    <div class="mu-sub">PNG · JPG · GIF · MP4 · WebM · up to 50MB</div>
                    <div class="mu-preview" id="mediaPreview">
                        <button class="mu-remove" onclick="removeMedia(event)"><i class="ri-close-line"></i></button>
                    </div>
                </div>
            </div>
            <div class="cm-field">
                <label class="cm-label" for="cm_summary">Summary *</label>
                <textarea class="cm-textarea" id="cm_summary" rows="3" placeholder="One to three sentences. What is this meme?"></textarea>
            </div>
            <div class="cm-field">
                <label class="cm-label" for="cm_body">Full Article</label>
                <textarea class="cm-textarea" id="cm_body" rows="6" placeholder="Write the full article here…" style="min-height:140px;"></textarea>
                <div class="cm-hint">Use line breaks for paragraphs.</div>
            </div>
            <div class="cm-row">
                <div class="cm-field">
                    <label class="cm-label" for="cm_year">Origin Year</label>
                    <input type="text" class="cm-input" id="cm_year" placeholder="e.g. 2016" maxlength="4">
                </div>
                <div class="cm-field">
                    <label class="cm-label" for="cm_platform">Origin Platform</label>
                    <input type="text" class="cm-input" id="cm_platform" placeholder="e.g. Reddit, Twitter…">
                </div>
            </div>
            <div class="cm-field">
                <label class="cm-label" for="cm_category">Category</label>
                <select class="cm-select" id="cm_category">
                    <option value="">— Select a category —</option>
                    <?php foreach($categories_list as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cm-field">
                <label class="cm-label">Status</label>
                <div class="status-chips" id="statusChips">
                    <button class="status-chip" data-val="trending" onclick="selectStatus(this)">🔥 Trending</button>
                    <button class="status-chip selected" data-val="rising" onclick="selectStatus(this)">📈 Rising</button>
                    <button class="status-chip" data-val="classic" onclick="selectStatus(this)">🏛 Classic</button>
                    <button class="status-chip" data-val="legendary" onclick="selectStatus(this)">👑 Legendary</button>
                    <button class="status-chip" data-val="dead" onclick="selectStatus(this)">💀 Dead</button>
                    <button class="status-chip" data-val="revived" onclick="selectStatus(this)">♻️ Revived</button>
                </div>
            </div>
            <div class="cm-field">
                <label class="cm-label" for="cm_tags">Tags (comma separated)</label>
                <input type="text" class="cm-input" id="cm_tags" placeholder="e.g. dog, shiba inu, crypto, 2013">
            </div>
            <input type="hidden" id="cm_edit_id" value="">
        </div>
        <div class="cm-footer">
            <button class="cm-cancel" onclick="closeCreate()">Cancel</button>
            <button class="cm-submit" id="cmSubmit" onclick="submitArticle()">
                <i class="ri-check-line"></i> Publish Entry
            </button>
        </div>
    </div>
</div>

<!-- ══ ARTICLE MODAL ══ -->
<div class="article-overlay" id="articleOverlay" role="dialog" aria-modal="true">
    <div class="article-modal" id="articleModal">
        <div class="am-topbar">
            <button class="am-close" onclick="closeArticle()"><i class="ri-arrow-left-s-line"></i></button>
            <div class="am-actions">
                <span class="am-views-badge"><i class="ri-eye-line"></i> <span id="amViewCount">0</span> views</span>
                <button class="am-action-btn" onclick="shareArticle()"><i class="ri-share-line"></i><span> Share</span></button>
                <button class="am-action-btn" onclick="bookmarkArticle()"><i class="ri-bookmark-line"></i><span> Save</span></button>
                <button class="am-action-btn edit" onclick="editCurrentArticle()" id="amEditBtn"><i class="ri-edit-line"></i><span> Edit</span></button>
            </div>
        </div>
        <div class="am-hero-media" id="amHeroMedia">
            <div class="am-no-media" id="amNoMedia">📄</div>
        </div>
        <div class="am-body">
            <div class="am-category-tag"><i class="ri-hashtag"></i> <span id="amCategoryText"></span></div>
            <h1 class="am-title" id="amTitle"></h1>
            <div class="am-meta-row">
                <span class="am-status-pill" id="amStatusPill"></span>
                <span class="am-sep"></span>
                <span class="am-meta-text" id="amYear"></span>
                <span class="am-sep"></span>
                <span class="am-meta-text" id="amPlatform"></span>
                <div class="am-author-chip" id="amAuthorChip">
                    <img src="<?= htmlspecialchars($profile_pic) ?>" alt="Author" onerror="this.src='uploads/me.jpeg'">
                    <span id="amAuthorName"></span>
                </div>
            </div>
            <div class="am-dates">
                <div class="am-date-cell">
                    <div class="am-date-label"><i class="ri-quill-pen-line"></i> Created</div>
                    <div class="am-date-val" id="amCreatedDate"></div>
                    <div class="am-date-time" id="amCreatedTime"></div>
                </div>
                <div class="am-date-cell updated">
                    <div class="am-date-label"><i class="ri-refresh-line"></i> Last Updated</div>
                    <div class="am-date-val" id="amUpdatedDate"></div>
                    <div class="am-date-time" id="amUpdatedTime"></div>
                </div>
            </div>
            <div class="am-info-table" id="amInfoTable"></div>
            <div class="edit-mode-banner" id="editModeBanner">
                <i class="ri-edit-2-line"></i>
                <span>Editing mode — make your changes and save.</span>
                <button class="em-cancel" onclick="cancelEdit()">Cancel</button>
                <button class="em-save" onclick="saveEdit()">Save Changes</button>
            </div>
            <div class="am-tabs" role="tablist">
                <button class="am-tab active" onclick="switchTab(this,'article')">Article</button>
                <button class="am-tab" onclick="switchTab(this,'history')"><i class="ri-history-line"></i> History (<span id="amEditCount">0</span>)</button>
                <button class="am-tab" onclick="switchTab(this,'discussion')"><i class="ri-chat-3-line"></i> Discussion</button>
                <button class="am-tab" onclick="switchTab(this,'related')">Related</button>
            </div>
            <div class="am-tab-panel active" id="tab-article">
                <div class="am-section">
                    <div class="am-section-label">Summary</div>
                    <p class="am-summary" id="amSummary"></p>
                </div>
                <div class="am-section">
                    <div class="am-section-label">Full Article</div>
                    <div class="am-body-content" id="amBodyContent" contenteditable="false"></div>
                </div>
                <div class="am-section">
                    <div class="am-section-label">Tags</div>
                    <div class="am-tags" id="amTags"></div>
                </div>
            </div>
            <div class="am-tab-panel" id="tab-history">
                <div class="am-section">
                    <div class="am-section-label">Edit History</div>
                    <div class="edit-history-list" id="editHistoryList">
                        <div style="padding:20px 0;text-align:center;font-size:0.78rem;color:var(--muted-2);">No edits recorded yet.</div>
                    </div>
                </div>
            </div>
            <div class="am-tab-panel" id="tab-discussion">
                <div class="am-section">
                    <div class="am-section-label">Discussion</div>
                    <div class="discussion-list" id="discussionList" style="margin-bottom:16px;"></div>
                    <div class="disc-input-row">
                        <img src="<?= htmlspecialchars($profile_pic) ?>" alt="You" onerror="this.src='uploads/me.jpeg'"
                             style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid var(--border-2);">
                        <textarea class="disc-input" id="discInput" placeholder="Add to the discussion…" rows="2"></textarea>
                        <button class="disc-send" onclick="postComment()">Post</button>
                    </div>
                </div>
            </div>
            <div class="am-tab-panel" id="tab-related">
                <div class="am-section">
                    <div class="am-section-label">Related Entries</div>
                    <div class="am-related-grid" id="relatedGrid">
                        <div style="font-size:0.78rem;color:var(--muted-2);">No related entries yet.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer" aria-live="polite"></div>


<script>
const CURRENT_USER = {
    id: <?= (int)$current_user ?>,
    username: <?= json_encode($username) ?>,
    avatar: <?= json_encode($profile_pic) ?>
};

let articles      = [];
let activeFilter  = 'all';
let activeSort    = 'newest';
let currentView   = 'grid';
let openArticleId = null;
let dupArticleId  = null;
let editingBody   = false;

const statusMap = {
    trending:  {label:'🔥 Trending', cls:'s-trending'},
    rising:    {label:'📈 Rising',   cls:'s-rising'},
    classic:   {label:'🏛 Classic',  cls:'s-classic'},
    dead:      {label:'💀 Dead Meme',cls:'s-dead'},
    revived:   {label:'♻️ Revived',  cls:'s-revived'},
    legendary: {label:'👑 Legendary',cls:'s-legendary'},
};

async function apiFetch(action, method='GET', body=null){
    try{
        const opts={method,headers:{'Content-Type':'application/json'}};
        if(body) opts.body=JSON.stringify(body);
        const res=await fetch(`api_articles.php?action=${action}`,opts);
        if(!res.ok) throw new Error(`HTTP ${res.status}`);
        return await res.json();
    }catch(err){
        console.error(`API error [${action}]:`,err);
        toast('Server error — please try again','error','ri-error-warning-line');
        return null;
    }
}

async function loadArticles(){
    const data=await apiFetch('list');
    if(data){articles=data;renderGrid();}
}

function toast(msg,type='info',icon='ri-information-line'){
    const c=document.getElementById('toastContainer');
    const t=document.createElement('div');
    t.className='toast '+type;
    t.innerHTML=`<i class="${icon}"></i> ${msg}`;
    c.appendChild(t);
    setTimeout(()=>t.remove(),3200);
}

function checkFade(){
    document.querySelectorAll('.fade-in:not(.visible)').forEach(el=>{
        if(el.getBoundingClientRect().top<window.innerHeight-50) el.classList.add('visible');
    });
}
window.addEventListener('scroll',checkFade,{passive:true});
checkFade();

function getFiltered(){
    let list=[...articles];
    if(activeFilter==='mine'){
        list=list.filter(a=>a.creator_id==CURRENT_USER.id);
    }else if(!['all','recent','popular'].includes(activeFilter)){
        if(['trending','rising','classic','dead','revived','legendary'].includes(activeFilter)){
            list=list.filter(a=>a.status===activeFilter);
        }else{
            list=list.filter(a=>a.category===activeFilter);
        }
    }
    const q=document.getElementById('topSearch').value.trim().toLowerCase();
    if(q) list=list.filter(a=>
        a.title.toLowerCase().includes(q)||
        (a.summary||'').toLowerCase().includes(q)||
        (a.tags||[]).some(t=>t.toLowerCase().includes(q))
    );
    switch(activeSort){
        case'newest':list.sort((a,b)=>new Date(b.created_at)-new Date(a.created_at));break;
        case'oldest':list.sort((a,b)=>new Date(a.created_at)-new Date(b.created_at));break;
        case'views': list.sort((a,b)=>(b.views||0)-(a.views||0));break;
        case'edits': list.sort((a,b)=>(b.edit_count||0)-(a.edit_count||0));break;
        case'az':    list.sort((a,b)=>a.title.localeCompare(b.title));break;
    }
    if(activeFilter==='popular') list.sort((a,b)=>(b.views||0)-(a.views||0));
    if(activeFilter==='recent')  list.sort((a,b)=>new Date(b.created_at)-new Date(a.created_at));
    return list;
}

function renderGrid(){
    const list=getFiltered();
    const grid=document.getElementById('articleGrid');
    const empty=document.getElementById('emptyState');
    document.getElementById('visibleCount').textContent=list.length;
    if(list.length===0){
        grid.style.display='none';grid.innerHTML='';empty.style.display='flex';
    }else{
        empty.style.display='none';
        grid.style.display=currentView==='grid'?'grid':'flex';
        grid.className=currentView==='list'?'article-list fade-in visible':'article-grid fade-in visible';
        grid.innerHTML=list.map(a=>buildCard(a)).join('');
    }
    document.getElementById('countAll').textContent=articles.length;
    document.getElementById('countMine').textContent=articles.filter(a=>a.creator_id==CURRENT_USER.id).length;
    renderSidebars();
}

function buildCard(a){
    const st=statusMap[a.status]||statusMap.rising;
    const mediaHtml=a.media_url
        ?(a.media_type==='video'
            ?`<video src="${escHtml(a.media_url)}" muted playsinline></video>`
            :`<img src="${escHtml(a.media_url)}" alt="${escHtml(a.title)}" loading="lazy">`)
        :`<div class="ac-media-placeholder">${getEmoji(a.title)}</div>`;
    const mediaTypeBadge=a.media_type?`<div class="ac-media-type">${a.media_type.toUpperCase()}</div>`:'';
    const dateStr=formatDate(a.created_at).date;
    const mine=a.creator_id==CURRENT_USER.id;
    return `
    <div class="article-card" onclick="openArticle(${a.id})" data-id="${a.id}">
        <div class="ac-media">${mediaHtml}${mediaTypeBadge}</div>
        <div class="ac-body">
            <span class="ac-status ${st.cls}">${st.label}</span>
            <div class="ac-title">${escHtml(a.title)}</div>
            <div class="ac-summary">${escHtml(a.summary||'')}</div>
        </div>
        <div class="ac-footer">
            <div class="ac-author">
                <img src="${escHtml(a.creator_avatar||CURRENT_USER.avatar)}" alt="" onerror="this.src='uploads/me.jpeg'">
                <span>@${escHtml(a.creator_name||CURRENT_USER.username)}</span>
            </div>
            <div class="ac-meta">
                <span><i class="ri-eye-line"></i> ${fmtNum(a.views||0)}</span>
                <span><i class="ri-edit-line"></i> ${a.edit_count||0}</span>
                <span>${dateStr}</span>
                ${mine?'<span style="color:var(--red);font-weight:700;">✎ Mine</span>':''}
            </div>
        </div>
        <div class="ac-arrow"><i class="ri-arrow-right-s-line"></i></div>
    </div>`;
}

function renderSidebars(){
    const byDate=[...articles].sort((a,b)=>new Date(b.created_at)-new Date(a.created_at)).slice(0,5);
    const byViews=[...articles].sort((a,b)=>(b.views||0)-(a.views||0)).slice(0,5);
    const makeRow=a=>`
        <div class="rs-row" onclick="openArticle(${a.id})">
            <div class="rs-emoji">${getEmoji(a.title)}</div>
            <div class="rs-info">
                <div class="rs-name">${escHtml(a.title)}</div>
                <div class="rs-sub">${timeSince(a.created_at)}</div>
            </div>
            <i class="ri-arrow-right-s-line rs-arrow"></i>
        </div>`;
    const none='<div style="padding:16px;font-size:12px;color:var(--text-3);text-align:center;">No entries yet</div>';
    document.getElementById('recentList').innerHTML=byDate.length?byDate.map(makeRow).join(''):none;
    document.getElementById('popularList').innerHTML=byViews.length?byViews.map(makeRow).join(''):none;
}

async function openArticle(id){
    let a=articles.find(x=>x.id==id);
    if(!a) return;
    openArticleId=id;
    apiFetch('increment_views','POST',{id});
    a.views=(a.views||0)+1;
    const full=await apiFetch(`get&id=${id}`);
    if(full){const idx=articles.findIndex(x=>x.id==id);if(idx!==-1)articles[idx]=full;a=full;}
    document.querySelectorAll('.am-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.am-tab-panel').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.am-tab')[0].classList.add('active');
    document.getElementById('tab-article').classList.add('active');
    const heroMedia=document.getElementById('amHeroMedia');
    const noMedia=document.getElementById('amNoMedia');
    const oldMedia=heroMedia.querySelector('img,video');
    if(oldMedia) oldMedia.remove();
    if(a.media_url){
        noMedia.style.display='none';
        if(a.media_type==='video'){
            const vid=document.createElement('video');
            vid.src=a.media_url;vid.controls=true;
            vid.style.cssText='width:100%;height:100%;object-fit:cover;';
            heroMedia.appendChild(vid);
        }else{
            const img=document.createElement('img');
            img.src=a.media_url;img.alt=a.title;
            img.style.cssText='width:100%;height:100%;object-fit:cover;';
            heroMedia.appendChild(img);
        }
    }else{
        noMedia.style.display='flex';noMedia.textContent=getEmoji(a.title);
    }
    document.getElementById('amCategoryText').textContent=a.category||'Uncategorized';
    document.getElementById('amTitle').textContent=a.title;
    const st=statusMap[a.status]||statusMap.rising;
    const pill=document.getElementById('amStatusPill');
    pill.textContent=st.label;pill.className='am-status-pill '+st.cls;
    document.getElementById('amYear').innerHTML=a.origin_year?`<strong>Est.</strong> ${escHtml(a.origin_year)}`:'';
    document.getElementById('amPlatform').innerHTML=a.origin_platform?`<i class="ri-map-pin-line"></i> ${escHtml(a.origin_platform)}`:'';
    document.getElementById('amAuthorName').textContent='@'+(a.creator_name||CURRENT_USER.username);
    document.querySelector('#amAuthorChip img').src=a.creator_avatar||CURRENT_USER.avatar;
    document.getElementById('amViewCount').textContent=fmtNum(a.views);
    const created=formatDate(a.created_at);const updated=formatDate(a.updated_at||a.created_at);
    document.getElementById('amCreatedDate').textContent=created.date;
    document.getElementById('amCreatedTime').textContent=created.time;
    document.getElementById('amUpdatedDate').textContent=updated.date;
    document.getElementById('amUpdatedTime').textContent=updated.time;
    const infoData=[
        {label:'Type',val:a.media_type?a.media_type.toUpperCase()+' Meme':'Meme'},
        {label:'Origin',val:a.origin_year||'—'},
        {label:'Platform',val:a.origin_platform||'—'},
        {label:'Category',val:a.category||'—'},
        {label:'Status',val:st.label},
        {label:'Views',val:fmtNum(a.views)},
        {label:'Edits',val:a.edit_count||0},
        {label:'Author',val:'@'+(a.creator_name||CURRENT_USER.username)},
    ];
    document.getElementById('amInfoTable').innerHTML=infoData.map(d=>`<div class="am-info-cell"><div class="am-info-label">${d.label}</div><div class="am-info-val">${escHtml(String(d.val))}</div></div>`).join('');
    document.getElementById('amSummary').textContent=a.summary||'';
    const bodyEl=document.getElementById('amBodyContent');
    bodyEl.textContent=a.body||'';bodyEl.contentEditable='false';
    const tags=normalizeTags(a.tags);
    document.getElementById('amTags').innerHTML=tags.length
        ?tags.map(t=>`<span class="am-tag">${escHtml(t)}</span>`).join('')
        :'<span style="font-size:12px;color:var(--text-3);">No tags yet</span>';
    document.getElementById('amEditCount').textContent=(a.edits||[]).length;
    renderEditHistory(a);renderDiscussion(a);renderRelated(a);
    document.getElementById('amEditBtn').style.display='flex';
    cancelEdit();
    document.getElementById('articleOverlay').classList.add('open');
    document.getElementById('articleModal').scrollTop=0;
    document.body.style.overflow='hidden';
}

function closeArticle(){
    document.getElementById('articleOverlay').classList.remove('open');
    document.body.style.overflow='';
    openArticleId=null;cancelEdit();renderGrid();

    // Clean the ?open= param out of the URL so a refresh/back doesn't re-open it
    if (new URLSearchParams(window.location.search).get('open')) {
        const url = new URL(window.location.href);
        url.searchParams.delete('open');
        window.history.replaceState({}, '', url);
    }
}

function switchTab(btn,name){
    document.querySelectorAll('.am-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.am-tab-panel').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-'+name).classList.add('active');
}

function renderEditHistory(a){
    const edits=a.edits||[];
    const el=document.getElementById('editHistoryList');
    if(!edits.length){el.innerHTML='<div style="padding:16px 0;text-align:center;font-size:13px;color:var(--text-3);">No edits recorded yet.</div>';return;}
    el.innerHTML=[...edits].reverse().map((e,i)=>`
        <div class="eh-item">
            <img class="eh-avatar" src="${escHtml(e.editor_avatar||CURRENT_USER.avatar)}" alt="" onerror="this.src='uploads/me.jpeg'">
            <div class="eh-info">
                <div class="eh-user">@${escHtml(e.editor_name||'Unknown')}</div>
                <div class="eh-summary">${escHtml(e.summary||'Updated article content')}</div>
                <div class="eh-time">${formatDate(e.edited_at).date} · ${formatDate(e.edited_at).time}</div>
            </div>
            <button class="eh-revert" onclick="revertTo(${a.id},${edits.length-1-i})">Revert</button>
        </div>`).join('');
}

async function revertTo(articleId,editIndex){
    const a=articles.find(x=>x.id==articleId);
    const edit=(a?.edits||[])[editIndex];
    if(!edit) return;
    if(!confirm('Revert to version from '+formatDate(edit.edited_at).date+'?')) return;
    const result=await apiFetch('revert','POST',{article_id:articleId,edit_index:editIndex,editor_id:CURRENT_USER.id,editor_name:CURRENT_USER.username,editor_avatar:CURRENT_USER.avatar});
    if(result?.success){await loadArticles();openArticle(articleId);toast('Article reverted','success','ri-check-line');}
}

function renderDiscussion(a){
    const comments=a.comments||[];
    const el=document.getElementById('discussionList');
    if(!comments.length){el.innerHTML='<div style="padding:8px 0;font-size:13px;color:var(--text-3);">No comments yet.</div>';return;}
    el.innerHTML=comments.map(c=>`
        <div class="disc-item">
            <div class="disc-header">
                <img class="disc-avatar" src="${escHtml(c.avatar||CURRENT_USER.avatar)}" alt="" onerror="this.src='uploads/me.jpeg'">
                <span class="disc-user">@${escHtml(c.username)}</span>
                <span class="disc-time">${timeSince(c.posted_at)}</span>
            </div>
            <div class="disc-text">${escHtml(c.text)}</div>
        </div>`).join('');
}

async function postComment(){
    if(openArticleId===null) return;
    const input=document.getElementById('discInput');
    const text=input.value.trim();
    if(!text){toast('Write something first','error','ri-error-warning-line');return;}
    const result=await apiFetch('comment','POST',{article_id:openArticleId,text,username:CURRENT_USER.username,avatar:CURRENT_USER.avatar});
    if(result?.success){
        input.value='';
        const full=await apiFetch(`get&id=${openArticleId}`);
        if(full){const idx=articles.findIndex(x=>x.id==openArticleId);if(idx!==-1)articles[idx]=full;renderDiscussion(full);}
        toast('Comment posted','success','ri-check-line');
    }
}

function renderRelated(a){
    const others=articles.filter(x=>x.id!=a.id&&(x.category===a.category||x.status===a.status)).slice(0,6);
    const el=document.getElementById('relatedGrid');
    if(!others.length){el.innerHTML='<div style="font-size:13px;color:var(--text-3);">No related entries yet.</div>';return;}
    el.innerHTML=others.map(r=>`
        <div class="am-related-card" onclick="openArticle(${r.id})">
            <div class="am-rel-media">${r.media_url?`<img src="${escHtml(r.media_url)}" alt="${escHtml(r.title)}" loading="lazy">`:`<div class="am-rel-placeholder">${getEmoji(r.title)}</div>`}</div>
            <div class="am-rel-body">
                <div class="am-rel-title">${escHtml(r.title)}</div>
                <div class="am-rel-meta">${(statusMap[r.status]||statusMap.rising).label} · ${fmtNum(r.views||0)} views</div>
            </div>
        </div>`).join('');
}

function editCurrentArticle(){
    if(openArticleId===null) return;
    editingBody=true;
    document.getElementById('amBodyContent').contentEditable='true';
    document.getElementById('amBodyContent').focus();
    document.getElementById('editModeBanner').classList.add('show');
    document.getElementById('amEditBtn').style.display='none';
    document.querySelectorAll('.am-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.am-tab-panel').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.am-tab')[0].classList.add('active');
    document.getElementById('tab-article').classList.add('active');
    toast('Editing mode on','info','ri-edit-2-line');
}

function cancelEdit(){
    editingBody=false;
    const bodyEl=document.getElementById('amBodyContent');
    bodyEl.contentEditable='false';
    document.getElementById('editModeBanner').classList.remove('show');
    if(openArticleId!==null){const a=articles.find(x=>x.id==openArticleId);if(a) bodyEl.textContent=a.body||'';}
    document.getElementById('amEditBtn').style.display='flex';
}

async function saveEdit(){
    if(openArticleId===null) return;
    const a=articles.find(x=>x.id==openArticleId);if(!a) return;
    const newBody=document.getElementById('amBodyContent').textContent.trim();
    const oldBody=a.body||'';
    if(newBody===oldBody){cancelEdit();toast('No changes detected','info','ri-information-line');return;}
    const summaryPrompt=prompt('Briefly describe your edit:','Updated article content')||'Updated article content';
    const result=await apiFetch('edit_body','POST',{article_id:openArticleId,new_body:newBody,old_body:oldBody,edit_summary:summaryPrompt,editor_id:CURRENT_USER.id,editor_name:CURRENT_USER.username,editor_avatar:CURRENT_USER.avatar});
    if(result?.success){
        a.body=newBody;a.updated_at=new Date().toISOString();a.edit_count=(a.edit_count||0)+1;
        a.edits=a.edits||[];
        a.edits.push({editor_id:CURRENT_USER.id,editor_name:CURRENT_USER.username,editor_avatar:CURRENT_USER.avatar,old_body:oldBody,new_body:newBody,summary:summaryPrompt,edited_at:new Date().toISOString()});
        document.getElementById('amEditCount').textContent=a.edits.length;
        document.getElementById('amUpdatedDate').textContent=formatDate(a.updated_at).date;
        document.getElementById('amUpdatedTime').textContent=formatDate(a.updated_at).time;
        renderEditHistory(a);cancelEdit();toast('Edit saved!','success','ri-check-line');
    }
}

function shareArticle(){
    const a=articles.find(x=>x.id==openArticleId);if(!a) return;
    if(navigator.share){navigator.share({title:a.title+' — Memepedia',text:a.summary,url:window.location.href});}
    else{navigator.clipboard.writeText(window.location.href).then(()=>toast('Link copied','success','ri-link-m'));}
}
function bookmarkArticle(){toast('Entry saved to bookmarks','success','ri-bookmark-fill');}

let selectedStatus='rising',mediaData=null,mediaType=null;

function openCreate(){
    document.getElementById('cmTitle').textContent='📝 New Meme Entry';
    document.getElementById('cmSubmit').innerHTML='<i class="ri-check-line"></i> Publish Entry';
    document.getElementById('cm_edit_id').value='';
    clearCreateForm();
    document.getElementById('createOverlay').classList.add('open');
    document.body.style.overflow='hidden';
    setTimeout(()=>document.getElementById('cm_title').focus(),300);
}
function closeCreate(){
    document.getElementById('createOverlay').classList.remove('open');
    document.body.style.overflow='';clearCreateForm();
}
function clearCreateForm(){
    ['cm_title','cm_summary','cm_body','cm_year','cm_platform','cm_tags'].forEach(id=>{document.getElementById(id).value='';});
    document.getElementById('cm_category').value='';
    document.getElementById('dupWarning').classList.remove('show');
    mediaData=null;mediaType=null;
    document.getElementById('mediaPreview').style.display='none';
    document.getElementById('mediaPreview').innerHTML='<button class="mu-remove" onclick="removeMedia(event)"><i class="ri-close-line"></i></button>';
    document.querySelectorAll('.status-chip').forEach(c=>c.classList.toggle('selected',c.dataset.val==='rising'));
    selectedStatus='rising';
}
function selectStatus(btn){
    document.querySelectorAll('.status-chip').forEach(c=>c.classList.remove('selected'));
    btn.classList.add('selected');selectedStatus=btn.dataset.val;
}
function checkDuplicate(val){
    const v=val.trim().toLowerCase();
    const dup=articles.find(a=>a.title.toLowerCase()===v);
    const warn=document.getElementById('dupWarning');
    if(dup&&document.getElementById('cm_edit_id').value!=dup.id){
        document.getElementById('dupName').textContent=dup.title;dupArticleId=dup.id;warn.classList.add('show');
    }else{warn.classList.remove('show');dupArticleId=null;}
}
function openDuplicate(){closeCreate();if(dupArticleId!==null) openArticle(dupArticleId);}
function previewMedia(input){
    const file=input.files[0];if(!file) return;
    const reader=new FileReader();
    reader.onload=function(e){
        mediaData=e.target.result;mediaType=file.type.startsWith('video')?'video':'image';
        const preview=document.getElementById('mediaPreview');
        const btn='<button class="mu-remove" onclick="removeMedia(event)"><i class="ri-close-line"></i></button>';
        preview.innerHTML=btn+(mediaType==='video'?`<video src="${mediaData}" controls style="width:100%;border-radius:10px;"></video>`:`<img src="${mediaData}" alt="Preview" style="width:100%;border-radius:10px;">`);
        preview.style.display='block';
    };
    reader.readAsDataURL(file);
}
function handleDrop(e){
    e.preventDefault();document.getElementById('mediaUploader').classList.remove('dragover');
    const file=e.dataTransfer.files[0];if(file) previewMedia({files:[file]});
}
function removeMedia(e){
    e.stopPropagation();mediaData=null;mediaType=null;
    document.getElementById('mediaPreview').style.display='none';
    document.getElementById('mediaPreview').innerHTML='<button class="mu-remove" onclick="removeMedia(event)"><i class="ri-close-line"></i></button>';
    document.getElementById('cm_media').value='';
}
async function submitArticle(){
    const title=document.getElementById('cm_title').value.trim();
    const summary=document.getElementById('cm_summary').value.trim();
    const editId=parseInt(document.getElementById('cm_edit_id').value)||null;
    if(!title){toast('Please enter a title','error','ri-error-warning-line');document.getElementById('cm_title').focus();return;}
    if(!summary){toast('Please add a summary','error','ri-error-warning-line');document.getElementById('cm_summary').focus();return;}
    if(!editId){
        const dup=articles.find(a=>a.title.toLowerCase()===title.toLowerCase());
        if(dup){toast(`"${dup.title}" already exists!`,'error','ri-error-warning-line');return;}
    }
    const tags=document.getElementById('cm_tags').value.split(',').map(t=>t.trim()).filter(Boolean);
    const payload={id:editId,title,summary,body:document.getElementById('cm_body').value.trim(),media_data:mediaData,media_type:mediaType,status:selectedStatus,category:document.getElementById('cm_category').value,origin_year:document.getElementById('cm_year').value.trim(),origin_platform:document.getElementById('cm_platform').value.trim(),tags,creator_id:CURRENT_USER.id,creator_name:CURRENT_USER.username,creator_avatar:CURRENT_USER.avatar,editor_id:CURRENT_USER.id,editor_name:CURRENT_USER.username,editor_avatar:CURRENT_USER.avatar};
    const result=await apiFetch(editId?'update':'save','POST',payload);
    if(result?.id||result?.success){
        const newId=result.id||editId;
        await loadArticles();closeCreate();
        toast(`"${title}" ${editId?'updated':'published'}!`,'success','ri-check-line');
        setTimeout(()=>openArticle(newId),350);
    }
}

function setFilter(btn,val){
    activeFilter=val;
    document.querySelectorAll('#filterChips .chip').forEach(c=>c.classList.remove('active'));
    btn.classList.add('active');renderGrid();
}
function filterCategory(val){
    activeFilter=val;
    document.querySelectorAll('.ls-item').forEach(i=>i.classList.toggle('active',i.dataset.cat===val));
    document.querySelectorAll('#filterChips .chip').forEach(c=>c.classList.remove('active'));
    if(val==='all') document.querySelector('#filterChips .chip[data-filter="all"]').classList.add('active');
    renderGrid();
}
function filterStatus(val){
    activeFilter=val;
    document.querySelectorAll('.ls-item').forEach(i=>i.classList.remove('active'));
    document.querySelectorAll('#filterChips .chip').forEach(c=>c.classList.toggle('active',c.dataset.filter===val));
    renderGrid();
}
function sortArticles(){activeSort=document.getElementById('sortSelect').value;renderGrid();}
function setView(v){
    currentView=v;
    document.getElementById('gridViewBtn').classList.toggle('active',v==='grid');
    document.getElementById('listViewBtn').classList.toggle('active',v==='list');
    renderGrid();
}

document.getElementById('topSearch').addEventListener('input',()=>renderGrid());
document.getElementById('topSearch').addEventListener('keydown',e=>{if(e.key==='Escape') e.target.value='';renderGrid();});

function goRandom(){
    if(!articles.length){toast('No entries yet!','info','ri-information-line');return;}
    openArticle(articles[Math.floor(Math.random()*articles.length)].id);
}

document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
        if(editingBody){cancelEdit();return;}
        if(document.getElementById('articleOverlay').classList.contains('open')){closeArticle();return;}
        if(document.getElementById('createOverlay').classList.contains('open')){closeCreate();return;}
    }
    if((e.ctrlKey||e.metaKey)&&e.key==='k'){e.preventDefault();document.getElementById('topSearch').focus();}
});
document.getElementById('articleOverlay').addEventListener('click',e=>{if(e.target===document.getElementById('articleOverlay')) closeArticle();});
document.getElementById('createOverlay').addEventListener('click',e=>{if(e.target===document.getElementById('createOverlay')) closeCreate();});

function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function normalizeTags(tags){if(!tags) return[];if(Array.isArray(tags)) return tags;try{return JSON.parse(tags);}catch{return String(tags).split(',').map(t=>t.trim()).filter(Boolean);}}
function formatDate(iso){
    if(!iso) return{date:'—',time:'—'};
    const d=new Date(iso);
    const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return{date:d.getDate()+' '+m[d.getMonth()]+' '+d.getFullYear(),time:d.getHours().toString().padStart(2,'0')+':'+d.getMinutes().toString().padStart(2,'0')};
}
function timeSince(iso){
    if(!iso) return'';const s=Math.floor((Date.now()-new Date(iso))/1000);
    if(s<60) return'just now';if(s<3600) return Math.floor(s/60)+'m ago';
    if(s<86400) return Math.floor(s/3600)+'h ago';if(s<604800) return Math.floor(s/86400)+'d ago';
    return formatDate(iso).date;
}
function fmtNum(n){if(n>=1e6) return(n/1e6).toFixed(1).replace('.0','')+'M';if(n>=1000) return(n/1000).toFixed(1).replace('.0','')+'K';return String(n);}
const EMOJIS=['😂','🐕','🎵','😤','🧠','🤖','😡','💪','🏆','🎮','🌐','📱','🎬','🔥','👑','✨','🤌','😈','🫠','💀'];
function getEmoji(title){let h=0;for(let i=0;i<title.length;i++) h=title.charCodeAt(i)+((h<<5)-h);return EMOJIS[Math.abs(h)%EMOJIS.length];}

document.addEventListener('DOMContentLoaded', async () => {
    await loadArticles();
    setTimeout(checkFade, 100);

    const openId = new URLSearchParams(window.location.search).get('open');
    if (openId) {
        openArticle(parseInt(openId));
    }
});
</script>
</body>
</html>