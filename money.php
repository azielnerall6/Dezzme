<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user){ header("Location: login.php"); exit(); }

$success_msg = false;
$tier_prices = ['Standard' => 49.00, 'Premium' => 149.00, 'Exclusive VIP' => 499.00];

if(isset($_POST['submit_request'])){
    $title   = $conn->real_escape_string($_POST['title']);
    $contact = $conn->real_escape_string($_POST['email'] . ' | ' . $_POST['phone']);
    $type    = $_POST['type'];
    $price   = $tier_prices[$type] ?? 49.00;
    $caption = $conn->real_escape_string($_POST['caption']);
    $link    = $conn->real_escape_string($_POST['link']);

    $logo_name = '';
    if(!empty($_FILES['logo']['name'])){
        $logo_name = 'logo_'.time().'_'.preg_replace("/[^a-zA-Z0-9.]/","_",$_FILES['logo']['name']);
        move_uploaded_file($_FILES['logo']['tmp_name'], "uploads/".$logo_name);
    }
    $media_name = '';
    if(!empty($_FILES['meme']['name'])){
        $media_name = 'media_'.time().'_'.preg_replace("/[^a-zA-Z0-9.]/","_",$_FILES['meme']['name']);
        move_uploaded_file($_FILES['meme']['tmp_name'], "uploads/".$media_name);
    }

    $stmt = $conn->prepare("INSERT INTO sponsorships (user_id,title,contact_info,type,price,caption,link,image,meme_image,status) VALUES (?,?,?,?,?,?,?,?,?,'pending')");
    $stmt->bind_param("isssdssss",$current_user,$title,$contact,$type,$price,$caption,$link,$logo_name,$media_name);
    if($stmt->execute()){ $success_msg = true; }
}

$sql = "SELECT *,
        CASE
            WHEN type='Exclusive VIP' THEN 3
            WHEN type='Premium' THEN 2
            ELSE 1
        END as priority
        FROM sponsorships
        WHERE status='approved'
        ORDER BY priority DESC, created_at DESC";
$ads        = $conn->query($sql);
$all_ads    = [];
while($ad   = $ads->fetch_assoc()) $all_ads[] = $ad;
$ad_count   = count($all_ads);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="manifest" href="/Peura/manifest.json">
<meta name="theme-color" content="#FF2D2D">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Peura">
<link rel="apple-touch-icon" href="/Peura/Peura.jpg">
<script>
if('serviceWorker' in navigator){
    window.addEventListener('load',()=>{
        navigator.serviceWorker.register('/Peura/service-worker.js').catch(()=>{});
    });
}
</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title>Launch Partnership | Dezzme</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
* { font-family: 'Poppins', sans-serif; }
button, a { cursor: pointer; outline: none; }

:root {
    --red:      #FF4C4C;
    --vip-gold: #fbbf24;
    --premium:  #818cf8;
    --surface:  rgba(255,255,255,0.05);
    --border:   rgba(255,255,255,0.10);
    --text:     #ffffff;
    --muted:    rgba(255,255,255,0.45);
    --divider:  rgba(255,255,255,0.08);
}

body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0 0 80px;
    overflow-x: hidden;
}

/* ──────────────────────────────────────
   SKELETON
────────────────────────────────────── */
#page-skeleton {
    position: fixed;
    inset: 0;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    opacity: 1;
    transition: opacity 0.35s ease;
    pointer-events: none;
    padding: 0 16px 80px;
    overflow: hidden;
}
#page-skeleton.hide { opacity: 0; }

@keyframes shimmer {
    0%   { background-position: -500px 0; }
    100% { background-position:  500px 0; }
}
.sk {
    background: linear-gradient(90deg,
        rgba(255,255,255,0.04) 25%,
        rgba(255,255,255,0.09) 50%,
        rgba(255,255,255,0.04) 75%);
    background-size: 500px 100%;
    animation: shimmer 1.5s infinite linear;
    border-radius: 8px;
}

.sk-topbar {
    width: 100%; max-width: 760px;
    display: flex; align-items: center; gap: 14px;
    padding: 20px 16px 16px;
}
.sk-back  { width: 22px; height: 22px; border-radius: 4px; flex-shrink: 0; }
.sk-title { width: 160px; height: 18px; border-radius: 6px; }

.sk-header {
    width: 100%; max-width: 760px;
    padding: 8px 0 22px;
    display: flex; flex-direction: column; align-items: center; gap: 8px;
}
.sk-h1 { width: 65%; height: 22px; border-radius: 7px; }
.sk-h2 { width: 45%; height: 12px; border-radius: 6px; }

.sk-feed {
    width: 100%; max-width: 760px;
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 14px; padding: 0 16px;
}
.sk-card { width: 100%; border-radius: 20px; overflow: hidden; border: 1px solid var(--divider); }
.sk-media { width: 100%; aspect-ratio: 4/3; }
.sk-body  { padding: 13px 14px 14px; display: flex; flex-direction: column; gap: 10px; }
.sk-row   { display: flex; align-items: center; gap: 10px; }
.sk-avatar2 { width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0; }
.sk-line1 { width: 120px; height: 12px; border-radius: 5px; }
.sk-line2 { width: 80px;  height: 9px;  border-radius: 5px; }
.sk-cta   { width: 100%; height: 36px; border-radius: 10px; margin-top: 2px; }

.sk-form-panel {
    width: 100%; max-width: 760px;
    display: grid; grid-template-columns: 220px 1fr;
    gap: 16px; padding: 0 16px; margin-top: 8px;
}
.sk-price-box { height: 180px; border-radius: 20px; }
.sk-form-fields { display: flex; flex-direction: column; gap: 12px; }
.sk-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.sk-field { height: 38px; border-radius: 10px; }

/* ──────────────────────────────────────
   TOPBAR
────────────────────────────────────── */
.topbar {
    width: 100%; max-width: 760px;
    display: flex; align-items: center; gap: 14px;
    padding: 20px 16px 14px;
    position: sticky; top: 0; z-index: 100;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}
.back-btn {
    background: none; border: none;
    color: var(--text); font-size: 1.4rem;
    cursor: pointer; opacity: 0.75; padding: 0;
    display: flex; align-items: center; flex-shrink: 0;
    transition: opacity 0.2s, color 0.2s;
    text-decoration: none;
}
.back-btn:hover { opacity: 1; color: var(--red); }
.topbar-title-wrap { flex: 1; min-width: 0; }
.topbar-title {
    font-size: 1rem; font-weight: 600; color: var(--text);
    display: flex; align-items: center; gap: 8px;
}
.topbar-title i { color: var(--red); font-size: 1.1rem; }
.topbar-subtitle { font-size: 0.7rem; color: var(--muted); margin-top: 2px; }

/* ──────────────────────────────────────
   PAGE INTRO
────────────────────────────────────── */
.page-intro {
    width: 100%; max-width: 760px;
    text-align: center; padding: 10px 20px 22px;
}
.page-intro h1 {
    font-size: clamp(1.3rem,5vw,1.7rem);
    font-weight: 700; color: var(--text); letter-spacing: -0.3px;
}
.page-intro p { font-size: 0.76rem; color: var(--muted); margin-top: 7px; line-height: 1.5; }

/* ──────────────────────────────────────
   AD FEED
────────────────────────────────────── */
.ad-feed {
    width: 100%; max-width: 760px;
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 14px; padding: 0 16px;
    align-items: start;
}

/* ──────────────────────────────────────
   AD CARD
────────────────────────────────────── */
.ad-card {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px; overflow: hidden; cursor: pointer;
    transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
    opacity: 0;
    animation: cardIn 0.5s ease forwards;
}
@keyframes cardIn {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}
.ad-card:hover {
    transform: translateY(-3px);
    border-color: rgba(255,76,76,0.3);
    box-shadow: 0 16px 40px rgba(0,0,0,0.45);
}
.ad-card.tier-Exclusive-VIP {
    border-color: rgba(251,191,36,0.35);
    box-shadow: 0 0 24px rgba(251,191,36,0.06);
}
.ad-card.tier-Exclusive-VIP:hover {
    border-color: var(--vip-gold);
    box-shadow: 0 16px 40px rgba(0,0,0,0.45), 0 0 18px rgba(251,191,36,0.18);
}
.ad-card.tier-Premium { border-color: rgba(129,140,248,0.3); }
.ad-card.tier-Premium:hover { border-color: rgba(129,140,248,0.55); }

.card-top { display: flex; align-items: center; gap: 8px; padding: 10px 12px; }
.brand-logo {
    width: 30px; height: 30px; border-radius: 50%; object-fit: cover;
    border: 2px solid var(--red); flex-shrink: 0;
}
.brand-info { flex: 1; min-width: 0; }
.brand-name {
    font-size: 0.78rem; font-weight: 600; color: var(--text);
    display: flex; align-items: center; gap: 5px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.brand-name svg { flex-shrink: 0; }
.tier-label { font-size: 0.6rem; color: var(--muted); margin-top: 1px; }

.vip-pill {
    flex-shrink: 0; display: flex; align-items: center; gap: 3px;
    background: rgba(251,191,36,0.12); border: 1px solid rgba(251,191,36,0.4);
    color: var(--vip-gold); font-size: 0.54rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.4px;
    padding: 3px 8px; border-radius: 50px;
}
.premium-pill {
    flex-shrink: 0; display: flex; align-items: center; gap: 3px;
    background: rgba(129,140,248,0.12); border: 1px solid rgba(129,140,248,0.4);
    color: var(--premium); font-size: 0.54rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.4px;
    padding: 3px 8px; border-radius: 50px;
}

.card-media {
    width: 100%; aspect-ratio: 16/10; overflow: hidden;
    position: relative; background: #000;
}
.card-media img, .card-media video {
    width: 100%; height: 100%; object-fit: cover; display: block;
    transition: transform 0.5s ease;
}
.ad-card:hover .card-media img,
.ad-card:hover .card-media video { transform: scale(1.035); }

.card-body { padding: 10px 12px 12px; }
.ad-caption {
    font-size: 0.74rem; line-height: 1.45;
    color: rgba(220,224,255,0.7); margin-bottom: 9px;
    display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow: hidden;
}
.cta-btn {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    width: 100%; padding: 8px 12px; border-radius: 10px;
    background: var(--red); color: #fff;
    font-weight: 600; font-size: 0.72rem; text-decoration: none;
    transition: background 0.2s, transform 0.2s; letter-spacing: 0.2px;
}
.cta-btn:hover { background: #ff2e2e; transform: translateY(-1px); }
.cta-btn i { font-size: 0.95rem; }

/* ──────────────────────────────────────
   SECTION DIVIDER
────────────────────────────────────── */
.section-divider {
    width: 100%; max-width: 760px;
    display: flex; align-items: center; gap: 14px;
    padding: 36px 16px 24px;
}
.section-divider-line { flex: 1; height: 1px; background: var(--divider); }
.section-divider-label {
    font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1.5px; color: var(--muted);
    display: flex; align-items: center; gap: 6px;
}
.section-divider-label i { color: var(--red); font-size: 0.85rem; }

/* ──────────────────────────────────────
   FORM PANEL
────────────────────────────────────── */
.form-panel {
    width: 100%; max-width: 760px;
    padding: 0 16px;
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 16px;
    align-items: start;
}

/* price card */
.price-showcase {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 24px 20px;
    display: flex; flex-direction: column; align-items: center;
    gap: 10px; text-align: center;
    position: static;
}
.price-tier-badge {
    font-size: 0.58rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1.5px; color: var(--muted);
}
.price-amount {
    font-size: 2.6rem; font-weight: 700; color: var(--text); line-height: 1;
}
.price-period { font-size: 0.65rem; color: var(--muted); }
.price-perks { width: 100%; display: flex; flex-direction: column; gap: 7px; margin-top: 6px; }
.price-perk {
    display: flex; align-items: center; gap: 7px;
    font-size: 0.68rem; color: rgba(255,255,255,0.6); text-align: left;
}
.price-perk i { color: var(--red); font-size: 0.8rem; flex-shrink: 0; }
.price-divider { width: 100%; height: 1px; background: var(--divider); }

/* form itself */
.form-fields {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 22px 20px;
    display: grid; gap: 14px;
}

.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field { display: flex; flex-direction: column; gap: 5px; }
.field label {
    font-size: 0.62rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.8px; color: var(--muted);
}
.field input,
.field select,
.field textarea {
    background: rgba(0,0,0,0.3);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 9px 13px;
    color: var(--text);
    font-size: 0.8rem;
    font-family: 'Poppins', sans-serif;
    transition: border-color 0.2s;
    outline: none;
    width: 100%;
}
.field input::placeholder { color: rgba(255,255,255,0.2); }
.field input:focus,
.field select:focus,
.field textarea:focus { border-color: rgba(255,76,76,0.5); }
.field select { cursor: pointer; }
.field select option { background: #0d0d1a; }
.field textarea { resize: none; height: 70px; line-height: 1.5; }

.field input[type="file"] { padding: 7px 10px; cursor: pointer; }
.field input[type="file"]::-webkit-file-upload-button {
    background: rgba(255,76,76,0.15); border: 1px solid rgba(255,76,76,0.3);
    color: var(--red); font-size: 0.7rem; font-weight: 600;
    padding: 4px 10px; border-radius: 6px; cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: background 0.2s;
}
.field input[type="file"]::-webkit-file-upload-button:hover {
    background: rgba(255,76,76,0.25);
}

.submit-btn {
    width: 100%; padding: 12px;
    background: var(--red); border: none;
    border-radius: 12px; color: #fff;
    font-size: 0.82rem; font-weight: 700;
    font-family: 'Poppins', sans-serif;
    letter-spacing: 0.3px;
    transition: background 0.2s, transform 0.2s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.submit-btn:hover { background: #ff2e2e; transform: translateY(-1px); }
.submit-btn i { font-size: 1rem; }

/* ──────────────────────────────────────
   EMPTY STATE
────────────────────────────────────── */
.empty-state {
    padding: 64px 0 32px; text-align: center; color: var(--muted);
    display: flex; flex-direction: column; align-items: center; gap: 10px;
}
.empty-state i { font-size: 2.2rem; opacity: 0.4; }
.empty-state span { font-size: 0.82rem; line-height: 1.6; max-width: 280px; }

/* ──────────────────────────────────────
   SUCCESS TOAST
────────────────────────────────────── */
.toast-bar {
    position: fixed; top: 24px; left: 50%; transform: translateX(-50%);
    background: rgba(16,185,129,0.15);
    border: 1px solid rgba(16,185,129,0.4);
    color: #34d399;
    padding: 12px 22px; border-radius: 12px; z-index: 99999;
    font-size: 0.8rem; font-weight: 600;
    display: flex; align-items: center; gap: 8px;
    backdrop-filter: blur(12px); white-space: nowrap;
    animation: toastIn 0.3s ease;
}
@keyframes toastIn {
    from { opacity: 0; transform: translateX(-50%) translateY(-10px); }
    to   { opacity: 1; transform: translateX(-50%) translateY(0); }
}

/* ══════════════════════════════════════════════════════════════
   MODAL
══════════════════════════════════════════════════════════════ */
#adModal {
    display: none;
    position: fixed; inset: 0; z-index: 9999999;
    background: rgba(0,0,0,0.92);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    align-items: center; justify-content: center;
    padding: 16px; flex-direction: row; gap: 12px;
}
#adm-card {
    background: rgba(18,17,17,0.97);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 20px; width: 100%; max-width: 420px;
    overflow: hidden; display: flex; flex-direction: column; max-height: 90vh;
}
#adm-inner { display: flex; flex-direction: column; overflow: hidden; flex: 1; }
#adm-arrows {
    display: flex; flex-direction: column; align-items: center; gap: 10px; flex-shrink: 0;
}
#adm-arrows button {
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 50%; width: 40px; height: 40px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; flex-shrink: 0; transition: background 0.2s;
}
#adm-arrows button:hover { background: rgba(255,255,255,0.15); }
.adm-header { display: flex; align-items: center; gap: 10px; padding: 14px 16px; flex-shrink: 0; }
.adm-avatar { width: 40px; height: 40px; border-radius: 50%; border: 2px solid var(--red); object-fit: cover; flex-shrink: 0; }
.adm-name { font-weight: 700; font-size: 14px; color: var(--red); display: flex; align-items: center; gap: 6px; }
.adm-tier { font-size: 10px; color: rgba(255,255,255,0.35); margin-top: 2px; }
.adm-close {
    margin-left: auto; background: rgba(255,255,255,0.06);
    border: none; border-radius: 50%; width: 30px; height: 30px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: rgba(255,255,255,0.5); font-size: 16px; flex-shrink: 0;
}
.adm-media { width: 100%; background: #000; flex-shrink: 0; }
.adm-media img, .adm-media video {
    width: 100%; max-height: 52vh; object-fit: contain; display: block;
}
.adm-caption {
    padding: 10px 16px; color: rgba(255,255,255,0.8); font-size: 13px;
    line-height: 1.55; border-top: 1px solid rgba(255,255,255,0.06);
    word-break: break-word; white-space: pre-wrap;
    overflow-y: auto; max-height: 80px; flex-shrink: 0;
}
.adm-caption::-webkit-scrollbar { width: 4px; }
.adm-caption::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 999px; }
.adm-action-bar {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px; border-top: 1px solid rgba(255,255,255,0.06); flex-shrink: 0;
}
.adm-cta {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    flex: 1; padding: 9px 12px; background: var(--red); color: #fff;
    font-weight: 700; font-size: 0.78em; text-decoration: none;
    border-radius: 10px; transition: background 0.2s; letter-spacing: 0.3px;
}
.adm-cta:hover { background: #ff2e2e; }
.adm-nav-btn {
    display: flex; align-items: center; justify-content: center; gap: 5px;
    background: transparent; border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px; padding: 8px 14px; color: white;
    cursor: pointer; font-size: 12px; font-weight: 600;
    font-family: Poppins, sans-serif; transition: background 0.2s;
}
.adm-nav-btn:hover { background: rgba(255,255,255,0.07); }
.adm-nav-btn i { font-size: 13px; }
.adm-close-bar {
    display: flex; align-items: center; justify-content: center;
    background: transparent; border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px; padding: 8px 14px;
    color: rgba(255,255,255,0.45); cursor: pointer;
    font-size: 12px; font-family: Poppins, sans-serif;
}
.adm-vip {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(251,191,36,0.15); border: 1px solid rgba(251,191,36,0.4);
    color: var(--vip-gold); font-size: 9px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    padding: 2px 7px; border-radius: 50px;
}
#adm-hint {
    position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
    color: rgba(255,255,255,0.18); font-size: 10px;
    white-space: nowrap; pointer-events: none;
}

/* ══════════════════════════════
   RESPONSIVE
══════════════════════════════ */
@media (max-width: 770px) {
    .topbar { padding: 18px 14px 12px; max-width: 480px; }
    .topbar-title { font-size: 0.95rem; }
    .topbar-subtitle { font-size: 0.66rem; }
    .back-btn { font-size: 1.3rem; }
    .page-intro { padding: 8px 16px 22px; max-width: 480px; }
    .page-intro h1 { font-size: 1.25rem; }
    .page-intro p { font-size: 0.72rem; }
    .ad-feed { padding: 0 14px; gap: 14px; max-width: 480px; grid-template-columns: 1fr; }
    .ad-card { border-radius: 16px; }
    .card-media { aspect-ratio: 16/8; }
    .card-top { padding: 9px 12px; gap: 8px; }
    .brand-logo { width: 30px; height: 30px; }
    .brand-name { font-size: 0.78rem; }
    .tier-label { font-size: 0.61rem; }
    .card-body { padding: 9px 12px 11px; }
    .ad-caption { font-size: 0.73rem; margin-bottom: 8px; }
    .cta-btn { font-size: 0.72rem; padding: 8px 12px; }
    .sk-feed { padding: 0 14px; max-width: 480px; grid-template-columns: 1fr; }
    .sk-header { padding: 0 14px 22px; max-width: 480px; }
    .sk-topbar { max-width: 480px; padding: 20px 14px 16px; }
    .sk-form-panel { max-width: 480px; padding: 0 14px; grid-template-columns: 1fr; }
    .sk-price-box { height: 80px; }
    #page-skeleton { padding: 0 0 60px; }
    .section-divider { max-width: 480px; padding: 28px 14px 18px; }
    .form-panel { max-width: 480px; padding: 0 14px; grid-template-columns: 1fr; }
    .price-showcase { position: static; }
    .price-amount { font-size: 2rem; }
}

@media (max-width: 490px) {
    .topbar { padding: 16px 12px 10px; gap: 11px; }
    .topbar-title { font-size: 0.88rem; }
    .topbar-subtitle { font-size: 0.62rem; }
    .back-btn { font-size: 1.2rem; }
    .page-intro { padding: 6px 12px 18px; }
    .page-intro h1 { font-size: 1.12rem; }
    .page-intro p { font-size: 0.68rem; }
    .ad-feed { padding: 0 12px; gap: 12px; }
    .ad-card { border-radius: 14px; }
    .card-media { aspect-ratio: 16/7.5; }
    .card-top { padding: 9px 11px; gap: 7px; }
    .brand-name { font-size: 0.76rem; }
    .tier-label { font-size: 0.6rem; }
    .vip-pill, .premium-pill { font-size: 0.56rem; padding: 3px 8px; }
    .card-body { padding: 11px 12px 12px; }
    .ad-caption { font-size: 0.72rem; margin-bottom: 10px; }
    .cta-btn { font-size: 0.7rem; padding: 8px 12px; border-radius: 10px; }
    .sk-topbar { padding: 16px 0 12px; }
    #page-skeleton { padding: 0 12px 60px; }
    .sk-form-panel { padding: 0 12px; }
    .sk-field-row { grid-template-columns: 1fr; }
    #adModal { padding: 10px; }
    #adm-arrows, #adm-hint { display: none !important; }
    #adm-card { border-radius: 20px 20px 14px 14px; max-height: 88vh; }
    .adm-caption { font-size: 12px; max-height: 60px; }
    .adm-nav-btn { padding: 7px 8px; font-size: 11px; }
    .form-panel { padding: 0 12px; }
    .field-row { grid-template-columns: 1fr; }
    .form-fields { padding: 18px 16px; }
}

@media (max-width: 380px) {
    .topbar { padding: 14px 10px 9px; gap: 9px; }
    .topbar-title { font-size: 0.82rem; }
    .topbar-subtitle { font-size: 0.58rem; }
    .back-btn { font-size: 1.1rem; }
    .page-intro { padding: 6px 10px 16px; }
    .page-intro h1 { font-size: 1.02rem; }
    .page-intro p { font-size: 0.64rem; }
    .ad-feed { padding: 0 10px; gap: 10px; }
    .ad-card { border-radius: 12px; }
    .card-media { aspect-ratio: 16/7; }
    .card-top { padding: 8px 10px; gap: 6px; }
    .brand-logo { width: 27px; height: 27px; }
    .brand-name { font-size: 0.71rem; }
    .tier-label { font-size: 0.56rem; }
    .vip-pill, .premium-pill { font-size: 0.52rem; padding: 3px 7px; }
    .card-body { padding: 10px 11px 11px; }
    .ad-caption { font-size: 0.68rem; margin-bottom: 9px; }
    .cta-btn { font-size: 0.66rem; padding: 7px 10px; border-radius: 9px; }
    #page-skeleton { padding: 0 10px 60px; }
    .adm-caption { font-size: 11px; max-height: 55px; }
    .adm-nav-btn { padding: 6px; font-size: 10px; }
    .form-panel { padding: 0 10px; }
}

@media (max-width: 330px) {
    .topbar { padding: 12px 8px 8px; gap: 8px; }
    .topbar-title { font-size: 0.76rem; }
    .topbar-subtitle { font-size: 0.54rem; }
    .page-intro { padding: 5px 8px 14px; }
    .page-intro h1 { font-size: 0.92rem; }
    .page-intro p { font-size: 0.6rem; }
    .ad-feed { padding: 0 8px; gap: 8px; }
    .ad-card { border-radius: 10px; }
    .card-media { aspect-ratio: 16/6.5; }
    .card-top { padding: 7px 8px; gap: 5px; }
    .brand-logo { width: 25px; height: 25px; }
    .brand-name { font-size: 0.66rem; }
    .vip-pill, .premium-pill { font-size: 0.48rem; padding: 2px 6px; }
    .card-body { padding: 9px 10px 10px; }
    .ad-caption { font-size: 0.64rem; }
    .cta-btn { font-size: 0.62rem; padding: 7px 9px; }
    #page-skeleton { padding: 0 8px 60px; }
    .form-panel { padding: 0 8px; }
}
</style>
</head>
<body>

<?php if($success_msg): ?>
<div class="toast-bar">
    <i class="ri-checkbox-circle-fill"></i> Partnership request sent — we'll review it shortly.
</div>
<script>setTimeout(()=>{ location.href='money.php'; }, 3000);</script>
<?php endif; ?>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-topbar">
        <div class="sk sk-back"></div>
        <div class="sk sk-title"></div>
    </div>
    <div class="sk-header">
        <div class="sk sk-h1"></div>
        <div class="sk sk-h2"></div>
    </div>
    <!-- form skeleton -->
    <div class="sk-form-panel">
        <div class="sk sk-price-box"></div>
        <div class="sk-form-fields">
            <div class="sk-field-row"><div class="sk sk-field"></div><div class="sk sk-field"></div></div>
            <div class="sk-field-row"><div class="sk sk-field"></div><div class="sk sk-field"></div></div>
            <div class="sk-field-row"><div class="sk sk-field"></div><div class="sk sk-field"></div></div>
            <div class="sk sk-cta" style="margin-top:4px;"></div>
        </div>
    </div>
    <!-- cards skeleton -->
    <div class="sk-feed" style="margin-top:24px;">
        <?php for($i=0;$i<2;$i++): ?>
        <div class="sk-card">
            <div class="sk-body">
                <div class="sk-row">
                    <div class="sk sk-avatar2"></div>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <div class="sk sk-line1"></div>
                        <div class="sk sk-line2"></div>
                    </div>
                </div>
            </div>
            <div class="sk sk-media"></div>
            <div class="sk-body">
                <div class="sk sk-cta"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<!-- ══ TOPBAR ══ -->
<div class="topbar">
    <button class="back-btn" onclick="history.back()" aria-label="Go back">
        <i class="ri-arrow-left-s-line"></i>
    </button>
    <div class="topbar-title-wrap">
        <div class="topbar-title"><i class="ri-funds-line"></i> Launch a Partnership</div>
        <div class="topbar-subtitle">Sponsorships &amp; brand partnerships</div>
    </div>
</div>

<!-- ══ PAGE INTRO ══ -->
<div class="page-intro">
    <h1>Launch a Partnership</h1>
    <p>Submit your brand below, then browse active partners from the Dezzme network<?php echo $ad_count ? ' — ' . $ad_count . ' live right now' : ''; ?>.</p>
</div>

<!-- ══ SECTION DIVIDER ══ -->
<div class="section-divider">
    <div class="section-divider-line"></div>
    <div class="section-divider-label">
        <i class="ri-rocket-2-line"></i> Launch Your Brand
    </div>
    <div class="section-divider-line"></div>
</div>

<!-- ══ FORM PANEL ══ -->
<div class="form-panel">

    <!-- Price showcase -->
    <div class="price-showcase">
        <div class="price-tier-badge" id="tier-name">Standard Tier</div>
        <div class="price-amount" id="price-val">$49</div>
        <div class="price-period">one-time placement</div>
        <div class="price-divider"></div>
        <div class="price-perks">
            <div class="price-perk"><i class="ri-checkbox-circle-fill"></i> Listed in the Exchange</div>
            <div class="price-perk"><i class="ri-checkbox-circle-fill"></i> Brand logo &amp; media</div>
            <div class="price-perk"><i class="ri-checkbox-circle-fill"></i> Direct link to your site</div>
            <div class="price-perk" id="perk-priority" style="display:none"><i class="ri-checkbox-circle-fill"></i> Priority placement</div>
            <div class="price-perk" id="perk-vip" style="display:none"><i class="ri-vip-crown-fill"></i> VIP gold badge</div>
        </div>
    </div>

    <!-- Form -->
    <form class="form-fields" method="POST" enctype="multipart/form-data">

        <div class="field-row">
            <div class="field">
                <label>Brand Name</label>
                <input type="text" name="title" required placeholder="e.g. Acme Corp">
            </div>
            <div class="field">
                <label>Tier</label>
                <select name="type" id="t-select" onchange="syncTier()">
                    <option value="Standard">Standard — $49</option>
                    <option value="Premium">Premium — $149</option>
                    <option value="Exclusive VIP">Exclusive VIP — $499</option>
                </select>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label>Contact Email</label>
                <input type="email" name="email" required placeholder="ceo@brand.com">
            </div>
            <div class="field">
                <label>Phone Number</label>
                <input type="tel" name="phone" required placeholder="+1 234 567 8900">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label>Target URL</label>
                <input type="url" name="link" required placeholder="https://yourbrand.com">
            </div>
            <div class="field">
                <label>Ad Caption</label>
                <input type="text" name="caption" maxlength="100" placeholder="The hook that sells it...">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label>Brand Logo</label>
                <input type="file" name="logo" accept="image/*" required>
            </div>
            <div class="field">
                <label>Ad Media (Image or Video)</label>
                <input type="file" name="meme" accept="image/*,video/*" required>
            </div>
        </div>

        <button type="submit" name="submit_request" class="submit-btn">
            <i class="ri-rocket-2-line"></i> Initialize Partnership
        </button>

    </form>
</div>

<!-- ══ SECTION DIVIDER 2 ══ -->
<div class="section-divider">
    <div class="section-divider-line"></div>
    <div class="section-divider-label">
        <i class="ri-store-2-line"></i> Active Partners
    </div>
    <div class="section-divider-line"></div>
</div>

<?php if(!empty($all_ads)): ?>
<!-- ══ AD FEED ══ -->
<div class="ad-feed" id="adFeed">
    <?php foreach($all_ads as $i => $ad):
        $tier_class = 'tier-' . str_replace(' ', '-', $ad['type']);
        $ext        = strtolower(pathinfo($ad['meme_image'], PATHINFO_EXTENSION));
        $is_video   = in_array($ext, ['mp4','webm','mov']);
        $ad_json    = htmlspecialchars(json_encode([
            'title'   => $ad['title'],
            'type'    => $ad['type'],
            'image'   => $ad['image'],
            'media'   => $ad['meme_image'],
            'caption' => $ad['caption'],
            'link'    => $ad['link'],
            'isVideo' => $is_video,
        ]), ENT_QUOTES);
    ?>
    <div class="ad-card <?php echo $tier_class; ?>"
         style="animation-delay:<?php echo ($i * 0.06); ?>s"
         onclick="openAdModal(<?php echo $i; ?>)"
         data-ad="<?php echo $ad_json; ?>">

        <div class="card-top">
            <img src="uploads/<?php echo htmlspecialchars($ad['image']); ?>" class="brand-logo" alt="brand" onerror="this.src='uploads/me.jpeg'">
            <div class="brand-info">
                <div class="brand-name">
                    <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="12" fill="#FF4C4C"/><path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php echo htmlspecialchars($ad['title']); ?>
                </div>
                <div class="tier-label"><?php echo htmlspecialchars($ad['type']); ?> Partner</div>
            </div>
            <?php if($ad['type'] === 'Exclusive VIP'): ?>
            <div class="vip-pill"><i class="ri-vip-crown-fill"></i> VIP</div>
            <?php elseif($ad['type'] === 'Premium'): ?>
            <div class="premium-pill"><i class="ri-star-fill"></i> Premium</div>
            <?php endif; ?>
        </div>

        <div class="card-media">
            <?php if($is_video): ?>
            <video src="uploads/<?php echo htmlspecialchars($ad['meme_image']); ?>" autoplay muted loop playsinline></video>
            <?php else: ?>
            <img src="uploads/<?php echo htmlspecialchars($ad['meme_image']); ?>" alt="<?php echo htmlspecialchars($ad['title']); ?>" loading="lazy">
            <?php endif; ?>
        </div>

        <div class="card-body">
            <div class="ad-caption"><?php echo htmlspecialchars($ad['caption']); ?></div>
            <a href="<?php echo htmlspecialchars($ad['link']); ?>" target="_blank" class="cta-btn" onclick="event.stopPropagation()">
                Visit Brand <i class="ri-arrow-right-up-line"></i>
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<div class="ad-feed">
    <div class="empty-state">
        <i class="ri-radar-line"></i>
        <span>Scanning for active partnerships...<br>Check back soon for new sponsorships.</span>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     AD MODAL
══════════════════════════════════════════════════ -->
<div id="adModal" onclick="if(event.target===this)closeAdModal()">
    <div id="adm-card">
        <div id="adm-inner"></div>
    </div>
    <div id="adm-arrows">
        <button onclick="admNav(-1)">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button onclick="admNav(1)">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>
    <div id="adm-hint">swipe or use arrows ↕</div>
</div>

<script>
/* ── Skeleton removal ── */
window.addEventListener('load', function(){
    var sk = document.getElementById('page-skeleton');
    if(!sk) return;
    setTimeout(function(){
        sk.classList.add('hide');
        setTimeout(function(){ sk.remove(); }, 380);
    }, 280);
});

/* ── Tier price sync ── */
function syncTier(){
    var prices = { 'Standard': '$49', 'Premium': '$149', 'Exclusive VIP': '$499' };
    var labels = { 'Standard': 'Standard Tier', 'Premium': 'Premium Tier', 'Exclusive VIP': 'Exclusive VIP Tier' };
    var v = document.getElementById('t-select').value;
    document.getElementById('price-val').textContent  = prices[v];
    document.getElementById('tier-name').textContent  = labels[v];
    document.getElementById('perk-priority').style.display = (v !== 'Standard') ? 'flex' : 'none';
    document.getElementById('perk-vip').style.display      = (v === 'Exclusive VIP') ? 'flex' : 'none';
}

/* ══ AD MODAL LOGIC ══ */
var _admIndex = 0;
var _admCards = [];

document.querySelectorAll('.ad-card[data-ad]').forEach(function(el){
    try { _admCards.push(JSON.parse(el.getAttribute('data-ad'))); } catch(e){}
});

function openAdModal(index){
    if(_admCards.length === 0) return;
    _admIndex = index;
    _admRender(_admIndex);
    document.getElementById('adModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeAdModal(){
    var inner = document.getElementById('adm-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    document.getElementById('adModal').style.display = 'none';
    document.body.style.overflow = '';
}

function admNav(dir){
    var next = _admIndex + dir;
    if(next < 0 || next >= _admCards.length) return;
    var card = document.getElementById('adm-card');
    card.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
    card.style.transform  = 'translateY('+(dir * -30)+'px)';
    card.style.opacity    = '0';
    setTimeout(function(){
        _admIndex = next;
        _admRender(_admIndex);
        card.style.transition = 'none';
        card.style.transform  = 'translateY('+(dir * 30)+'px)';
        card.style.opacity    = '0';
        requestAnimationFrame(function(){
            card.style.transition = 'transform 0.22s ease, opacity 0.22s ease';
            card.style.transform  = 'translateY(0)';
            card.style.opacity    = '1';
        });
    }, 220);
}

function _admRender(index){
    var ad    = _admCards[index];
    var inner = document.getElementById('adm-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });

    var mediaHtml = ad.isVideo
        ? '<video src="uploads/'+_esc(ad.media)+'" autoplay muted loop playsinline style="width:100%;max-height:52vh;object-fit:contain;display:block;"></video>'
        : '<img src="uploads/'+_esc(ad.media)+'" onerror="this.src=\'uploads/me.jpeg\'" style="width:100%;max-height:52vh;object-fit:contain;display:block;">';

    var vipBadge = (ad.type === 'Exclusive VIP')
        ? '<span class="adm-vip"><i class="ri-vip-crown-fill"></i> VIP</span>'
        : '';

    inner.innerHTML =
        '<div class="adm-header">'+
            '<img src="uploads/'+_esc(ad.image)+'" onerror="this.src=\'uploads/me.jpeg\'" class="adm-avatar">'+
            '<div style="flex:1;min-width:0;">'+
                '<div class="adm-name">'+vipBadge+_esc(ad.title)+'</div>'+
                '<div class="adm-tier">'+_esc(ad.type)+' Partner</div>'+
            '</div>'+
            '<button class="adm-close" onclick="closeAdModal()">✕</button>'+
        '</div>'+
        '<div class="adm-media">'+mediaHtml+'</div>'+
        '<div class="adm-caption">'+_esc(ad.caption)+'</div>'+
        '<div class="adm-action-bar">'+
            '<a href="'+_esc(ad.link)+'" target="_blank" class="adm-cta" onclick="event.stopPropagation()">Visit Brand <i class="ri-arrow-right-up-line"></i></a>'+
            '<button class="adm-nav-btn" onclick="admNav(-1)"><i class="ri-arrow-up-line"></i></button>'+
            '<button class="adm-nav-btn" onclick="admNav(1)"><i class="ri-arrow-down-line"></i></button>'+
            '<button class="adm-close-bar" onclick="closeAdModal()">Close</button>'+
        '</div>';
}

function _esc(str){
    if(!str) return '';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('keydown', function(e){
    if(document.getElementById('adModal').style.display === 'flex'){
        if(e.key === 'Escape')    closeAdModal();
        if(e.key === 'ArrowDown') admNav(1);
        if(e.key === 'ArrowUp')   admNav(-1);
    }
});
document.getElementById('adModal').addEventListener('wheel', function(e){
    e.preventDefault();
    if(e.deltaY > 0) admNav(1); else admNav(-1);
},{passive:false});

var _admTouchY = 0;
document.getElementById('adModal').addEventListener('touchstart', function(e){
    _admTouchY = e.touches[0].clientY;
},{passive:true});
document.getElementById('adModal').addEventListener('touchend', function(e){
    var diff = _admTouchY - e.changedTouches[0].clientY;
    if(Math.abs(diff) > 40){ if(diff > 0) admNav(1); else admNav(-1); }
},{passive:true});
</script>
</body>
</html>