<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user){
    header("Location: login.php");
    exit();
}

$profile_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $current_user;
$is_owner   = ($current_user == $profile_id);

// User info
$user = $conn->query("SELECT id, username, profile_pic FROM users WHERE id=$profile_id")->fetch_assoc();

// ── SOUND COLLECTIONS AJAX ──
if(isset($_POST['sc_action'])){
    header('Content-Type: application/json');
    $sc_action = trim($_POST['sc_action']);

    if($sc_action === 'create_folder'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
        $is_public = (int)($_POST['is_public'] ?? 0);
        if(empty($name)){ echo json_encode(['status'=>'error','message'=>'Name required']); exit; }
        $conn->query("INSERT INTO sound_folders (user_id, name, is_public, created_at) VALUES ($current_user, '$name', $is_public, NOW())");
        $new_id = $conn->insert_id;
        echo json_encode(['status'=>'ok','id'=>$new_id,'name'=>$name,'is_public'=>$is_public]);
        exit;
    }

    if($sc_action === 'delete_folder'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid = (int)($_POST['folder_id'] ?? 0);
        $conn->query("DELETE FROM sound_folders WHERE id=$fid AND user_id=$current_user");
        $conn->query("DELETE FROM sound_folder_items WHERE folder_id=$fid");
        echo json_encode(['status'=>'ok']);
        exit;
    }

    if($sc_action === 'rename_folder'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid  = (int)($_POST['folder_id'] ?? 0);
        $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
        if(!$fid || empty($name)){ echo json_encode(['status'=>'error']); exit; }
        $conn->query("UPDATE sound_folders SET name='$name' WHERE id=$fid AND user_id=$current_user");
        echo json_encode(['status'=>'ok']);
        exit;
    }

    if($sc_action === 'set_visibility'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid = (int)($_POST['folder_id'] ?? 0);
        $vis = (int)($_POST['is_public'] ?? 0);
        $conn->query("UPDATE sound_folders SET is_public=$vis WHERE id=$fid AND user_id=$current_user");
        echo json_encode(['status'=>'ok','is_public'=>$vis]);
        exit;
    }

    if($sc_action === 'add_to_folder'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid = (int)($_POST['folder_id'] ?? 0);
        $sid = (int)($_POST['sound_id']  ?? 0);
        $own = $conn->query("SELECT id FROM sound_folders WHERE id=$fid AND user_id=$current_user");
        if(!$own->num_rows){ echo json_encode(['status'=>'error']); exit; }
        $chk = $conn->query("SELECT id FROM sound_folder_items WHERE folder_id=$fid AND sound_id=$sid");
        if($chk->num_rows){ echo json_encode(['status'=>'already']); exit; }
        $conn->query("INSERT INTO sound_folder_items (folder_id, sound_id, added_at) VALUES ($fid, $sid, NOW())");
        $cnt = $conn->query("SELECT COUNT(*) as c FROM sound_folder_items WHERE folder_id=$fid")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','item_count'=>(int)$cnt]);
        exit;
    }

    if($sc_action === 'remove_from_folder'){
        if(!$is_owner){ echo json_encode(['status'=>'error']); exit; }
        $fid = (int)($_POST['folder_id'] ?? 0);
        $sid = (int)($_POST['sound_id']  ?? 0);
        $conn->query("DELETE FROM sound_folder_items WHERE folder_id=$fid AND sound_id=$sid");
        $cnt = $conn->query("SELECT COUNT(*) as c FROM sound_folder_items WHERE folder_id=$fid")->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','item_count'=>(int)$cnt]);
        exit;
    }

    if($sc_action === 'get_folder_items'){
        $fid = (int)($_POST['folder_id'] ?? 0);
        $frow = $conn->query("SELECT user_id, is_public FROM sound_folders WHERE id=$fid")->fetch_assoc();
        if(!$frow || ($frow['user_id'] != $current_user && !$frow['is_public'])){
            echo json_encode(['status'=>'error','items'=>[]]); exit;
        }
        $rows = [];
        $res  = $conn->query("
            SELECT s.id, s.title, s.file_name, u.username, u.profile_pic, u.id AS original_user_id
            FROM sound_folder_items sfi
            JOIN sounds s ON s.id = sfi.sound_id
            JOIN users  u ON u.id = s.original_user_id
            WHERE sfi.folder_id = $fid
            ORDER BY sfi.added_at DESC
        ");
        while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['status'=>'ok','items'=>$rows]);
        exit;
    }

    if($sc_action === 'get_folders'){
        $rows = [];
        if($is_owner){
            $res = $conn->query("SELECT f.id, f.name, f.is_public, f.created_at, (SELECT COUNT(*) FROM sound_folder_items fi WHERE fi.folder_id=f.id) as item_count FROM sound_folders f WHERE f.user_id=$profile_id ORDER BY f.created_at DESC");
        } else {
            $res = $conn->query("SELECT f.id, f.name, f.is_public, f.created_at, (SELECT COUNT(*) FROM sound_folder_items fi WHERE fi.folder_id=f.id) as item_count FROM sound_folders f WHERE f.user_id=$profile_id AND f.is_public=1 ORDER BY f.created_at DESC");
        }
        while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['status'=>'ok','folders'=>$rows]);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'Unknown action']);
    exit;
}

// ── Handle upload (only owner) ──
if(isset($_POST['upload_sound']) && $is_owner){
    if(isset($_FILES['audio']) && $_FILES['audio']['error']==0){
        $title = $conn->real_escape_string($_POST['title']);
        $file  = $_FILES['audio'];
        $ext   = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowed = ['mp3','wav','ogg'];
        if(in_array(strtolower($ext), $allowed)){
            $filename = time().'_'.uniqid().'.'.$ext;
            $target   = "uploads/sounds/".$filename;
            move_uploaded_file($file['tmp_name'], $target);
            $conn->query("INSERT INTO sounds (user_id, original_user_id, title, file_name) VALUES ($current_user,$current_user,'$title','$filename')");
        }
    }
}

// ── Save / Unsave ──
if(isset($_POST['action'])){
    $sound_id = intval($_POST['sound_id']);
    if($_POST['action']=='save')   $conn->query("INSERT IGNORE INTO saved_sounds (user_id,sound_id) VALUES ($current_user,$sound_id)");
    if($_POST['action']=='unsave') $conn->query("DELETE FROM saved_sounds WHERE user_id=$current_user AND sound_id=$sound_id");
    exit();
}

// ── Fetch sounds ──
$my_sounds = $conn->query("
    SELECT s.*, u.username, u.profile_pic
    FROM sounds s JOIN users u ON s.original_user_id=u.id
    WHERE s.user_id=$profile_id ORDER BY s.created_at DESC
");
$added_sounds = $conn->query("
    SELECT s.*, u.username, u.profile_pic
    FROM saved_sounds ss
    JOIN sounds s ON ss.sound_id=s.id
    JOIN users  u ON u.id=s.original_user_id
    WHERE ss.user_id=$profile_id ORDER BY ss.created_at DESC
");

// ── Fetch folders for JS ──
$sound_folders = [];
if($is_owner){
    $fr = $conn->query("SELECT f.id, f.name, f.is_public, (SELECT COUNT(*) FROM sound_folder_items fi WHERE fi.folder_id=f.id) as item_count FROM sound_folders f WHERE f.user_id=$profile_id ORDER BY f.created_at DESC");
} else {
    $fr = $conn->query("SELECT f.id, f.name, f.is_public, (SELECT COUNT(*) FROM sound_folder_items fi WHERE fi.folder_id=f.id) as item_count FROM sound_folders f WHERE f.user_id=$profile_id AND f.is_public=1 ORDER BY f.created_at DESC");
}
while($r=$fr->fetch_assoc()) $sound_folders[]=$r;

$sounds_count   = $conn->query("SELECT COUNT(*) AS c FROM sounds WHERE user_id=$profile_id")->fetch_assoc()['c'] ?? 0;
$added_count    = $conn->query("SELECT COUNT(*) AS c FROM saved_sounds WHERE user_id=$profile_id")->fetch_assoc()['c'] ?? 0;
$folders_count  = $conn->query("SELECT COUNT(*) AS c FROM sound_folders WHERE user_id=$profile_id" . ($is_owner ? '' : ' AND is_public=1'))->fetch_assoc()['c'] ?? 0;

// Who saved panel
$savers_list = [];
if($is_owner){
    $sr = $conn->query("
        SELECT u.id, u.username, u.profile_pic, COUNT(ss.sound_id) AS cnt
        FROM saved_sounds ss
        JOIN sounds s ON ss.sound_id=s.id
        JOIN users u ON ss.user_id=u.id
        WHERE s.user_id=$profile_id
        GROUP BY u.id ORDER BY cnt DESC LIMIT 30
    ");
    if($sr) while($ab=$sr->fetch_assoc()) $savers_list[]=$ab;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=htmlspecialchars($user['username'])?> · Sounds</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --red:      #FF4C4C;
    --red-dim:  rgba(255,76,76,0.14);
    --red-glow: rgba(255,76,76,0.28);
    --gold:     #ffc947;
    --teal:     #00e5cc;
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(255,255,255,0.05);
    --surface2: rgba(255,255,255,0.08);
    --border:   rgba(255,255,255,0.10);
    --border2:  rgba(255,255,255,0.14);
    --text:     #ffffff;
    --muted:    rgba(255,255,255,0.45);
    --divider:  rgba(255,255,255,0.08);
    --radius:   16px;
}

html { scroll-behavior: smooth; }

body {
    font-family: 'DM Sans', sans-serif;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0 0 80px;
}

/* ══ Page wrapper ══ */
.page-wrap {
    width: 100%;
    max-width: 720px;
    padding: 0 16px;
}

/* ══════════════════════════════
   TOPBAR
══════════════════════════════ */
.topbar {
    width: 100%;
    max-width: 720px;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px 16px 14px;
    position: sticky;
    top: 0;
    z-index: 200;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}

.back-btn {
    background: none;
    border: none;
    color: var(--text);
    font-size: 1.4rem;
    cursor: pointer;
    opacity: 0.75;
    padding: 0;
    display: flex;
    align-items: center;
    flex-shrink: 0;
    transition: opacity 0.2s, color 0.2s;
    text-decoration: none;
}
.back-btn:hover { opacity: 1; color: var(--red); }

.topbar-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ══════════════════════════════
   PROFILE HERO
══════════════════════════════ */
.hero {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 8px 0 24px;
}

.hero-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    border: 2.5px solid var(--red);
    flex-shrink: 0;
    transition: transform 0.3s, box-shadow 0.3s;
    cursor: pointer;
}
.hero-avatar:hover {
    transform: scale(1.06);
    box-shadow: 0 0 24px var(--red-glow);
}

.hero-info { flex: 1; min-width: 0; }

.hero-name {
    font-size: 1.15rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}
.hero-name svg { flex-shrink: 0; }

.hero-sub {
    font-size: 0.72rem;
    color: var(--muted);
    font-weight: 400;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    margin-top: 3px;
}

.hero-stats {
    display: flex;
    gap: 0;
    margin-top: 14px;
    background: transparent;
    border: transparent;
    border-radius: 14px;
    overflow: hidden;
}
.hero-stat {
    flex: 1;
    text-align: center;
    padding: 12px 8px;
    position: relative;
}
.hero-stat + .hero-stat::before {
    content: '';
    position: absolute;
    left: 0; top: 20%; bottom: 20%;
    width: 1px;
    background: var(--divider);
}
.hero-stat-num {
    display: block;
    font-size: 1.1rem;
    font-weight: 700;
    line-height: 1;
}
.hero-stat-label {
    display: block;
    font-size: 0.6rem;
    font-weight: 500;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: 3px;
}

/* ══════════════════════════════
   UPLOAD BOX
══════════════════════════════ */
.upload-section {
    margin-bottom: 20px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    position: relative;
    overflow: hidden;
}

.upload-label {
    font-size: 0.68rem;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.upload-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.u-input {
    flex: 1;
    min-width: 140px;
    padding: 9px 14px;
    background: var(--surface2);
    border: 1px solid var(--border2);
    border-radius: 10px;
    color: var(--text);
    font-family: 'Outfit', sans-serif;
    font-size: 0.82rem;
    outline: none;
    transition: border-color 0.2s;
}
.u-input:focus { border-color: rgba(255,76,76,0.5); }
.u-input::placeholder { color: var(--muted); }

.u-file-label {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 9px 14px;
    background: var(--surface2);
    border: 1.5px dashed var(--border2);
    border-radius: 10px;
    color: var(--muted);
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
}
.u-file-label:hover { border-color: var(--red); color: var(--red); background: var(--red-dim); }
.u-file-label input { display: none; }

.u-submit {
    padding: 9px 20px;
    background: var(--red);
    border: none;
    border-radius: 10px;
    color: white;
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 6px;
}
.u-submit:hover { background: #ff2e2e; transform: translateY(-1px); box-shadow: 0 6px 20px var(--red-glow); }

/* ══════════════════════════════
   TABS
══════════════════════════════ */
.tabs-wrap {
    width: 100%;
    max-width: 720px;
    display: flex;
    border-bottom: 1px solid var(--divider);
    position: sticky;
    top: 62px;
    z-index: 190;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    overflow-x: auto;
    scrollbar-width: none;
}
.tabs-wrap::-webkit-scrollbar { display: none; }

.tab-btn {
    flex: 1;
    min-width: 80px;
    padding: 13px 6px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--muted);
    font-family: 'Poppins', sans-serif;
    font-size: 0.72rem;
    font-weight: 500;
    cursor: pointer;
    text-align: center;
    transition: color 0.2s, border-color 0.2s;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    margin-bottom: -1px;
    white-space: nowrap;
}
.tab-btn .tab-count {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text);
    line-height: 1;
}
.tab-btn .tab-label {
    font-size: 0.62rem;
    font-weight: 500;
    letter-spacing: 0.02em;
}
.tab-btn.active { color: var(--text); border-bottom-color: #fff; }
.tab-btn.active .tab-count { color: var(--text); }

/* ══════════════════════════════
   SEARCH
══════════════════════════════ */
.search-wrap {
    padding: 14px 0 4px;
    position: relative;
    display: flex;
    align-items: center;
}
.search-icon {
    position: absolute;
    left: 14px;
    color: var(--muted);
    font-size: 1rem;
    pointer-events: none;
}
.search-input {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 10px 16px 10px 40px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    color: var(--text);
    outline: none;
    transition: border-color 0.2s;
}
.search-input::placeholder { color: var(--muted); }
.search-input:focus { border-color: rgba(255,76,76,0.4); }

/* ══════════════════════════════
   TAB PANELS
══════════════════════════════ */
.tab-panel { display: none; padding-top: 16px; }
.tab-panel.active { display: block; }

/* ══════════════════════════════
   SOUND LIST
══════════════════════════════ */
.sound-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* ── Sound Card ── */
.sound-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    transition: border-color 0.25s, transform 0.25s, box-shadow 0.25s;
}
.sound-card:hover {
    border-color: rgba(255,76,76,0.35);
    transform: translateY(-2px);
    box-shadow: 0 10px 32px rgba(0,0,0,0.4);
}
.sound-card.is-playing {
    border-color: rgba(255,76,76,0.55);
    box-shadow: 0 0 28px rgba(255,76,76,0.15);
}

.sound-card-inner {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px 10px;
}

.sound-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--red-dim);
    border: 1px solid rgba(255,76,76,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
    overflow: hidden;
}
.sound-icon i { font-size: 1.2rem; color: var(--red); }
.sound-icon .eq-bars {
    display: none;
    align-items: flex-end;
    gap: 2px;
    height: 20px;
}
.sound-card.is-playing .sound-icon .eq-bars { display: flex; }
.sound-card.is-playing .sound-icon i { display: none; }
.eq-bar {
    width: 3px;
    border-radius: 2px;
    background: var(--red);
    animation: eq-bounce 0.6s ease infinite alternate;
}
.eq-bar:nth-child(1) { height: 7px;  animation-delay: 0s; }
.eq-bar:nth-child(2) { height: 14px; animation-delay: 0.15s; }
.eq-bar:nth-child(3) { height: 9px;  animation-delay: 0.3s; }
.eq-bar:nth-child(4) { height: 16px; animation-delay: 0.1s; }
.eq-bar:nth-child(5) { height: 7px;  animation-delay: 0.25s; }
@keyframes eq-bounce { from { transform: scaleY(0.4); } to { transform: scaleY(1); } }

.sound-meta { flex: 1; min-width: 0; }
.sound-title {
    font-size: 0.88rem;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sound-uploader {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 3px;
    text-decoration: none;
    color: var(--muted);
    font-size: 0.7rem;
    font-weight: 500;
    transition: color 0.2s;
}
.sound-uploader:hover { color: var(--red); }
.sound-uploader img {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid rgba(255,76,76,0.4);
}

/* Audio player row */
.audio-player {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 16px 14px;
}

.play-btn {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--red);
    border: none;
    color: white;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.2s, transform 0.2s;
}
.play-btn:hover { background: #ff2e2e; transform: scale(1.07); }

.progress-wrap {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.progress-bar {
    width: 100%;
    height: 3px;
    background: rgba(255,255,255,0.1);
    border-radius: 3px;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--red), #ff7070);
    border-radius: 3px;
    width: 0%;
    transition: width 0.1s linear;
}
.progress-times {
    display: flex;
    justify-content: space-between;
    font-size: 0.6rem;
    color: var(--muted);
    font-family: monospace;
}

/* Card actions */
.sound-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px 12px;
    border-top: 1px solid var(--divider);
}

.s-action-btn {
    width: 32px;
    height: 32px;
    border-radius: 9px;
    background: var(--surface2);
    border: 1px solid var(--border);
    color: var(--muted);
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.s-action-btn:hover { background: rgba(255,255,255,0.1); color: var(--text); }
.s-action-btn.danger:hover { background: rgba(255,76,76,0.15); border-color: rgba(255,76,76,0.4); color: var(--red); }
.s-action-btn.folder:hover { background: rgba(255,201,71,0.12); border-color: rgba(255,201,71,0.4); color: var(--gold); }

.s-spacer { flex: 1; }

.s-save-btn {
    padding: 7px 16px;
    border-radius: 20px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.7rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    white-space: nowrap;
    -webkit-tap-highlight-color: transparent;
}
.s-save-btn.save   { background: var(--red); color: white; }
.s-save-btn.save:hover { background: #ff2e2e; }
.s-save-btn.unsave { background: var(--surface2); color: var(--muted); border: 1px solid var(--border2); }
.s-save-btn.unsave:hover { background: rgba(255,76,76,0.12); border-color: rgba(255,76,76,0.35); color: var(--red); }

/* ══════════════════════════════
   FOLDERS
══════════════════════════════ */
.folders-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
}

.folder-card {
    border-radius: var(--radius);
    background: var(--surface);
    border: 1px solid var(--border);
    overflow: hidden;
    cursor: pointer;
    transition: all 0.25s;
    position: relative;
}
.folder-card:hover {
    border-color: rgba(255,76,76,0.4);
    transform: translateY(-3px);
    box-shadow: 0 10px 28px rgba(0,0,0,0.4);
}
.folder-card.is-public { border-color: rgba(255,201,71,0.18); }

.folder-preview {
    width: 100%;
    aspect-ratio: 1;
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: 1fr 1fr;
    gap: 2px;
    background: rgba(255,255,255,0.02);
}
.fp-cell {
    background: rgba(255,255,255,0.04);
    display: flex;
    align-items: center;
    justify-content: center;
}
.fp-cell.filled { background: var(--red-dim); }
.fp-cell i { font-size: 1rem; color: rgba(255,76,76,0.45); }
.fp-cell.empty i { font-size: 0.8rem; color: rgba(255,255,255,0.07); }

.folder-info { padding: 10px 12px; }
.folder-name {
    font-size: 0.82rem;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 3px;
}
.folder-count { font-size: 0.68rem; color: var(--muted); }
.folder-vis {
    font-size: 0.62rem;
    font-weight: 600;
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 3px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.folder-vis.pub  { color: var(--gold); }
.folder-vis.priv { color: var(--muted); }

.folder-opts-btn {
    position: absolute;
    top: 7px; right: 7px;
    width: 26px; height: 26px;
    border-radius: 50%;
    background: rgba(0,0,0,0.65);
    backdrop-filter: blur(8px);
    border: none;
    color: white;
    font-size: 0.82rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.2s;
    z-index: 5;
}
.folder-card:hover .folder-opts-btn { opacity: 1; }

.folder-pub-badge {
    position: absolute;
    top: 7px; left: 7px;
    background: rgba(0,0,0,0.7);
    border: 1px solid rgba(255,201,71,0.5);
    border-radius: 20px;
    padding: 2px 7px;
    font-size: 0.58rem;
    font-weight: 700;
    color: var(--gold);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    pointer-events: none;
}

.new-folder-btn {
    border-radius: var(--radius);
    background: transparent;
    border: 1.5px dashed rgba(255,255,255,0.12);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 150px;
    color: var(--muted);
    font-family: 'Poppins', sans-serif;
    font-size: 0.75rem;
    font-weight: 600;
    transition: all 0.25s;
    width: 100%;
    -webkit-tap-highlight-color: transparent;
}
.new-folder-btn:hover { border-color: rgba(255,76,76,0.5); color: var(--red); background: var(--red-dim); }
.new-folder-btn i { font-size: 1.6rem; }

/* Folder detail view */
.folder-detail { display: none; }
.folder-detail-hdr {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--divider);
    margin-bottom: 16px;
}
.folder-back-btn {
    width: 34px; height: 34px;
    border-radius: 10px;
    background: var(--surface);
    border: 1px solid var(--border2);
    color: var(--text);
    font-size: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}
.folder-back-btn:hover { background: var(--red-dim); border-color: var(--red); color: var(--red); }
.folder-detail-title  { font-size: 1rem; font-weight: 700; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.folder-detail-count  { font-size: 0.72rem; color: var(--muted); font-family: monospace; flex-shrink: 0; }

.s-remove-btn {
    position: absolute;
    top: 10px; right: 10px;
    width: 26px; height: 26px;
    border-radius: 50%;
    background: rgba(180,0,0,0.85);
    border: none;
    color: white;
    font-size: 0.78rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 5;
    transition: all 0.2s;
}
.s-remove-btn:hover { background: var(--red); transform: scale(1.1); }

/* ══════════════════════════════
   OPTS DROPDOWN
══════════════════════════════ */
.sc-opts-dd {
    position: fixed;
    background: rgba(8,9,22,0.98);
    backdrop-filter: blur(20px);
    border: 1px solid var(--border2);
    border-radius: 14px;
    min-width: 175px;
    z-index: 9999999;
    box-shadow: 0 16px 50px rgba(0,0,0,0.75);
    overflow: hidden;
    display: none;
}
.sc-opts-dd.open { display: block; animation: dropIn 0.18s ease forwards; }
@keyframes dropIn { from { opacity:0; transform:translateY(-6px) scale(0.97); } to { opacity:1; transform:none; } }

.sc-opts-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 15px;
    color: var(--muted);
    font-size: 0.82rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s;
    border-bottom: 1px solid var(--divider);
    -webkit-tap-highlight-color: transparent;
}
.sc-opts-item:last-child { border-bottom: none; }
.sc-opts-item:hover { background: var(--surface2); color: var(--text); }
.sc-opts-item.danger   { color: var(--red); }
.sc-opts-item i        { font-size: 0.95rem; color: var(--red); }
.sc-opts-item.vis-item i { color: var(--gold); }

/* ══════════════════════════════
   ADD TO FOLDER MODAL
══════════════════════════════ */
#scAddFolderModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999;
    background: rgba(0,0,0,0.88);
    backdrop-filter: blur(8px);
    align-items: flex-end;
    justify-content: center;
    padding: 0 16px;
}
#scAddFolderModal.open { display: flex; }
.atf-sheet {
    background: #0d0e22;
    border: 1px solid var(--border2);
    border-radius: 22px 22px 18px 18px;
    width: 100%;
    max-width: 480px;
    max-height: 75vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 -8px 40px rgba(0,0,0,0.6);
    animation: sheetUp 0.28s cubic-bezier(0.34,1.56,0.64,1);
}
@keyframes sheetUp { from { transform:translateY(40px); opacity:0; } to { transform:none; opacity:1; } }

.atf-hdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 18px 12px;
    border-bottom: 1px solid var(--divider);
    flex-shrink: 0;
}
.atf-title { font-size: 0.88rem; font-weight: 700; }
.atf-close {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: var(--surface2);
    border: none;
    color: var(--muted);
    font-size: 0.88rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.2s;
}
.atf-close:hover { background: var(--red-dim); color: var(--red); }

.atf-list { overflow-y: auto; flex: 1; padding: 6px 0; }
.atf-list::-webkit-scrollbar       { width: 4px; }
.atf-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }

.atf-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 18px;
    cursor: pointer;
    transition: background 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.atf-item:hover { background: var(--surface); }
.atf-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: var(--red-dim);
    border: 1px solid rgba(255,76,76,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.atf-icon i { font-size: 1.1rem; color: rgba(255,76,76,0.5); }
.atf-info { flex: 1; min-width: 0; }
.atf-name  { font-size: 0.82rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.atf-count { font-size: 0.68rem; color: var(--muted); margin-top: 2px; }
.atf-check {
    width: 22px; height: 22px;
    border-radius: 50%;
    border: 1.5px solid var(--border2);
    background: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.68rem;
    color: transparent;
    transition: all 0.2s;
    flex-shrink: 0;
}
.atf-check.on { background: var(--red); border-color: transparent; color: white; font-weight: 700; }

.atf-new-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 18px;
    border-top: 1px solid var(--divider);
    cursor: pointer;
    color: var(--red);
    font-size: 0.82rem;
    font-weight: 700;
    transition: background 0.15s;
    flex-shrink: 0;
    -webkit-tap-highlight-color: transparent;
}
.atf-new-row:hover { background: var(--red-dim); }
.atf-new-row i { font-size: 1.1rem; }

/* ══════════════════════════════
   NAME MODAL
══════════════════════════════ */
#scNameModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999999;
    background: rgba(0,0,0,0.88);
    backdrop-filter: blur(8px);
    align-items: center;
    justify-content: center;
    padding: 20px;
}
#scNameModal.open { display: flex; }
.sc-nm-box {
    background: #0d0e22;
    border: 1px solid var(--border2);
    border-radius: 20px;
    padding: 24px;
    width: 100%;
    max-width: 340px;
    box-shadow: 0 24px 60px rgba(0,0,0,0.7);
    animation: sheetUp 0.25s ease;
}
.sc-nm-box h3 { font-size: 1rem; font-weight: 700; margin-bottom: 16px; }
.sc-nm-input {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--border2);
    border-radius: 12px;
    padding: 10px 14px;
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    font-size: 0.88rem;
    outline: none;
    margin-bottom: 14px;
    transition: border-color 0.2s;
}
.sc-nm-input:focus { border-color: rgba(255,76,76,0.45); }
.sc-nm-input::placeholder { color: var(--muted); }

.sc-vis-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    padding: 10px 14px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
}
.sc-vis-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--muted);
    display: flex;
    align-items: center;
    gap: 6px;
}
.sc-vis-toggle  { position: relative; width: 38px; height: 21px; flex-shrink: 0; }
.sc-vis-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.sc-vis-slider {
    position: absolute;
    inset: 0;
    border-radius: 21px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.12);
    cursor: pointer;
    transition: background 0.22s;
}
.sc-vis-slider::before {
    content: '';
    position: absolute;
    width: 15px; height: 15px;
    border-radius: 50%;
    background: white;
    top: 2px; left: 2px;
    transition: transform 0.22s;
}
.sc-vis-toggle input:checked + .sc-vis-slider                 { background: rgba(255,201,71,0.7); }
.sc-vis-toggle input:checked + .sc-vis-slider::before          { transform: translateX(17px); }

.sc-nm-btns     { display: flex; gap: 10px; }
.sc-nm-cancel {
    flex: 1; padding: 10px; border-radius: 12px;
    background: var(--surface2);
    border: 1px solid var(--border2);
    color: var(--muted);
    font-size: 0.82rem; font-weight: 700;
    cursor: pointer; font-family: 'Poppins', sans-serif;
    transition: background 0.2s;
}
.sc-nm-cancel:hover { background: rgba(255,255,255,0.1); }
.sc-nm-save {
    flex: 1; padding: 10px; border-radius: 12px;
    background: var(--red); border: none;
    color: white; font-size: 0.82rem; font-weight: 700;
    cursor: pointer; font-family: 'Poppins', sans-serif;
    transition: background 0.2s;
}
.sc-nm-save:hover { background: #ff2e2e; }

/* ══════════════════════════════
   WHO SAVED MODAL
══════════════════════════════ */
.savers-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999;
    background: rgba(0,0,0,0.88);
    backdrop-filter: blur(8px);
    align-items: flex-end;
    justify-content: center;
    padding: 0 16px;
}
.savers-modal-overlay.open { display: flex; }
.savers-sheet {
    background: #0d0e22;
    border: 1px solid var(--border2);
    border-radius: 22px 22px 18px 18px;
    width: 100%;
    max-width: 480px;
    max-height: 70vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 -8px 40px rgba(0,0,0,0.6);
    animation: sheetUp 0.28s cubic-bezier(0.34,1.56,0.64,1);
}
.savers-hdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 18px 12px;
    border-bottom: 1px solid var(--divider);
    flex-shrink: 0;
}
.savers-hdr-title {
    font-size: 0.88rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}
.savers-hdr-title i { color: var(--red); }
.savers-close {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: var(--surface2);
    border: none;
    color: var(--muted);
    font-size: 0.88rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.2s;
}
.savers-close:hover { background: var(--red-dim); color: var(--red); }

.savers-list { overflow-y: auto; flex: 1; padding: 4px 0; }
.savers-list::-webkit-scrollbar       { width: 4px; }
.savers-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }

.saver-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 18px;
    cursor: pointer;
    text-decoration: none;
    color: var(--text);
    transition: background 0.15s;
}
.saver-row:hover { background: var(--surface); }
.saver-avatar {
    width: 40px; height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255,76,76,0.4);
    flex-shrink: 0;
}
.saver-info   { flex: 1; min-width: 0; }
.saver-name   { font-size: 0.85rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.saver-count  { font-size: 0.68rem; color: var(--muted); margin-top: 2px; }

/* ══════════════════════════════
   TOAST
══════════════════════════════ */
.sc-toast {
    position: fixed;
    bottom: 90px; left: 50%;
    transform: translateX(-50%);
    background: rgba(8,9,22,0.97);
    border: 1px solid rgba(255,76,76,0.3);
    color: var(--text);
    padding: 9px 22px;
    border-radius: 50px;
    font-size: 0.75rem;
    font-family: 'Poppins', sans-serif;
    font-weight: 500;
    z-index: 99999999;
    pointer-events: none;
    box-shadow: 0 8px 24px rgba(0,0,0,0.6);
    white-space: nowrap;
    animation: toastIn 0.25s ease forwards;
}
@keyframes toastIn { from { opacity:0; transform:translateX(-50%) translateY(8px); } to { opacity:1; transform:translateX(-50%) translateY(0); } }

/* ══════════════════════════════
   EMPTY STATE
══════════════════════════════ */
.empty-state {
    text-align: center;
    padding: 48px 20px;
    color: var(--muted);
}
.empty-state i { font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.35; }
.empty-state p { font-size: 0.82rem; }

/* ══════════════════════════════
   HISTORY BUTTON
══════════════════════════════ */
.history-btn {
    padding: 8px 16px;
    border-radius: 20px;
    background: var(--surface);
    border: 1px solid var(--border2);
    color: var(--muted);
    font-family: 'Poppins', sans-serif;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    -webkit-tap-highlight-color: transparent;
    margin-bottom: 20px;
}
.history-btn:hover { background: var(--red-dim); border-color: rgba(255,76,76,0.4); color: var(--red); }
.history-btn i { font-size: 0.9rem; }

/* ══════════════════════════════
   SPIN
══════════════════════════════ */
@keyframes spin { to { transform: rotate(360deg); } }


/* ══════════════════════════════════════════════
   RESPONSIVE — 760px
   Tablet landscape / large mobile landscape
══════════════════════════════════════════════ */
@media (max-width: 760px) {
    :root { --radius: 14px; }

    body { padding: 0 0 72px; }

    .page-wrap { padding: 0 14px; }

    /* Topbar */
    .topbar { padding: 16px 14px 12px; gap: 12px; }
    .back-btn { font-size: 1.25rem; }
    .topbar-title { font-size: 0.95rem; }

    /* Hero */
    .hero { gap: 16px; padding: 6px 0 20px; }
    .hero-avatar { width: 72px; height: 72px; }
    .hero-name { font-size: 1.05rem; gap: 6px; }
    .hero-sub { font-size: 0.68rem; }
    .hero-stats { margin-top: 12px; }
    .hero-stat { padding: 10px 6px; }
    .hero-stat-num { font-size: 1rem; }
    .hero-stat-label { font-size: 0.58rem; }

    /* Upload */
    .upload-section { padding: 14px 16px; margin-bottom: 16px; }
    .upload-label { font-size: 0.65rem; margin-bottom: 10px; }
    .u-input { font-size: 0.78rem; padding: 8px 12px; min-width: 120px; }
    .u-file-label { font-size: 0.74rem; padding: 8px 12px; }
    .u-submit { font-size: 0.78rem; padding: 8px 16px; }

    /* Tabs */
    .tabs-wrap { top: 56px; }
    .tab-btn { padding: 11px 5px; min-width: 72px; }
    .tab-btn .tab-count { font-size: 0.88rem; }
    .tab-btn .tab-label { font-size: 0.6rem; }

    /* Search */
    .search-wrap { padding: 12px 0 4px; }
    .search-input { font-size: 0.78rem; padding: 9px 14px 9px 38px; border-radius: 12px; }

    /* Sound card */
    .sound-list { gap: 8px; }
    .sound-card-inner { gap: 12px; padding: 12px 14px 8px; }
    .sound-icon { width: 40px; height: 40px; border-radius: 10px; }
    .sound-icon i { font-size: 1.1rem; }
    .sound-title { font-size: 0.84rem; }
    .sound-uploader { font-size: 0.67rem; }
    .audio-player { gap: 8px; padding: 0 14px 12px; }
    .play-btn { width: 30px; height: 30px; font-size: 0.78rem; }
    .sound-actions { gap: 7px; padding: 8px 14px 10px; }
    .s-action-btn { width: 30px; height: 30px; font-size: 0.85rem; border-radius: 8px; }
    .s-save-btn { font-size: 0.67rem; padding: 6px 14px; }

    /* Folders */
    .folders-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
    .folder-info { padding: 9px 11px; }
    .folder-name { font-size: 0.78rem; }
    .folder-count { font-size: 0.65rem; }
    .new-folder-btn { min-height: 140px; font-size: 0.72rem; }
    .new-folder-btn i { font-size: 1.4rem; }

    /* Dropdowns/modals */
    .sc-opts-item { padding: 11px 14px; font-size: 0.78rem; }
    .atf-hdr { padding: 14px 16px 10px; }
    .atf-item { padding: 10px 16px; gap: 10px; }
    .atf-icon { width: 36px; height: 36px; border-radius: 9px; }
    .atf-name { font-size: 0.78rem; }
    .atf-count { font-size: 0.65rem; }
    .saver-row { padding: 10px 16px; gap: 12px; }
    .saver-avatar { width: 36px; height: 36px; }
    .saver-name { font-size: 0.8rem; }

    /* Name modal */
    .sc-nm-box { padding: 20px; border-radius: 18px; }
    .sc-nm-box h3 { font-size: 0.95rem; margin-bottom: 14px; }
    .sc-nm-input { font-size: 0.82rem; padding: 9px 12px; margin-bottom: 12px; }
    .sc-nm-cancel, .sc-nm-save { font-size: 0.78rem; padding: 9px; }

    /* Toast */
    .sc-toast { bottom: 80px; font-size: 0.72rem; padding: 8px 18px; }

    /* Empty state */
    .empty-state { padding: 40px 16px; }
    .empty-state i { font-size: 1.75rem; }

    /* History btn */
    .history-btn { font-size: 0.68rem; padding: 7px 14px; margin-bottom: 16px; }

    /* Folder opts always visible on touch */
    .folder-opts-btn { opacity: 1; }
}


/* ══════════════════════════════════════════════
   RESPONSIVE — 480px
   Mobile portrait standard
══════════════════════════════════════════════ */
@media (max-width: 480px) {
    :root { --radius: 13px; }

    body { padding: 0 0 68px; }

    .page-wrap { padding: 0 12px; }

    /* Topbar */
    .topbar { padding: 14px 12px 10px; gap: 10px; }
    .back-btn { font-size: 1.15rem; }
    .topbar-title { font-size: 0.88rem; }

    /* Hero */
    .hero { gap: 14px; padding: 4px 0 18px; }
    .hero-avatar { width: 64px; height: 64px; border-width: 2px; }
    .hero-name { font-size: 0.95rem; gap: 5px; }
    .hero-sub { font-size: 0.65rem; letter-spacing: 0.03em; }
    .hero-stats { margin-top: 10px; border-radius: 12px; }
    .hero-stat { padding: 9px 5px; }
    .hero-stat-num { font-size: 0.92rem; }
    .hero-stat-label { font-size: 0.55rem; letter-spacing: 0.04em; margin-top: 2px; }

    /* Upload — stack vertically */
    .upload-section { padding: 12px 14px; margin-bottom: 14px; border-radius: var(--radius); }
    .upload-label { font-size: 0.62rem; margin-bottom: 9px; }
    .upload-form { flex-direction: column; gap: 8px; }
    .u-input { width: 100%; min-width: unset; font-size: 0.76rem; padding: 8px 12px; border-radius: 9px; }
    .u-file-label { width: 100%; justify-content: center; font-size: 0.72rem; padding: 8px 12px; border-radius: 9px; }
    .u-submit { width: 100%; justify-content: center; font-size: 0.76rem; padding: 8px 14px; border-radius: 9px; }

    /* Tabs */
    .tabs-wrap { top: 52px; }
    .tab-btn { padding: 10px 4px; min-width: 60px; }
    .tab-btn .tab-count { font-size: 0.82rem; }
    .tab-btn .tab-label { font-size: 0.57rem; }

    /* Search */
    .search-wrap { padding: 10px 0 4px; }
    .search-input { font-size: 0.75rem; padding: 8px 12px 8px 36px; border-radius: 11px; }
    .search-icon { left: 12px; font-size: 0.9rem; }

    /* Sound card */
    .sound-list { gap: 8px; }
    .tab-panel { padding-top: 12px; }
    .sound-card-inner { gap: 10px; padding: 11px 12px 8px; }
    .sound-icon { width: 38px; height: 38px; border-radius: 10px; }
    .sound-icon i { font-size: 1rem; }
    .sound-title { font-size: 0.8rem; }
    .sound-uploader { font-size: 0.64rem; }
    .sound-uploader img { width: 14px; height: 14px; }
    .audio-player { gap: 8px; padding: 0 12px 11px; }
    .play-btn { width: 28px; height: 28px; font-size: 0.75rem; }
    .progress-times { font-size: 0.56rem; }
    .sound-actions { gap: 6px; padding: 7px 12px 10px; }
    .s-action-btn { width: 28px; height: 28px; font-size: 0.82rem; border-radius: 7px; }
    .s-save-btn { font-size: 0.63rem; padding: 5px 12px; }

    /* Folders — 2 cols */
    .folders-grid { grid-template-columns: repeat(2, 1fr); gap: 9px; }
    .folder-info { padding: 8px 10px; }
    .folder-name { font-size: 0.75rem; margin-bottom: 2px; }
    .folder-count { font-size: 0.62rem; }
    .folder-vis { font-size: 0.58rem; margin-top: 4px; }
    .folder-pub-badge { font-size: 0.54rem; padding: 2px 6px; }
    .folder-opts-btn { width: 24px; height: 24px; font-size: 0.75rem; top: 6px; right: 6px; }
    .new-folder-btn { min-height: 120px; font-size: 0.68rem; gap: 6px; }
    .new-folder-btn i { font-size: 1.3rem; }

    /* Folder detail */
    .folder-detail-title { font-size: 0.92rem; }
    .folder-detail-count { font-size: 0.68rem; }
    .folder-back-btn { width: 30px; height: 30px; font-size: 0.9rem; border-radius: 9px; }

    /* Dropdowns/modals */
    .sc-opts-dd { min-width: 160px; border-radius: 12px; }
    .sc-opts-item { padding: 10px 13px; font-size: 0.76rem; gap: 8px; }
    .sc-opts-item i { font-size: 0.9rem; }

    /* ATF sheet */
    .atf-sheet { border-radius: 20px 20px 16px 16px; max-height: 72vh; }
    .atf-hdr { padding: 13px 15px 10px; }
    .atf-title { font-size: 0.82rem; }
    .atf-close { width: 26px; height: 26px; font-size: 0.82rem; }
    .atf-item { padding: 9px 15px; gap: 9px; }
    .atf-icon { width: 34px; height: 34px; border-radius: 8px; }
    .atf-icon i { font-size: 1rem; }
    .atf-name { font-size: 0.75rem; }
    .atf-count { font-size: 0.62rem; }
    .atf-check { width: 20px; height: 20px; font-size: 0.62rem; }
    .atf-new-row { padding: 11px 15px; font-size: 0.76rem; gap: 8px; }
    .atf-new-row i { font-size: 1rem; }

    /* Name modal */
    .sc-nm-box { padding: 18px; border-radius: 16px; max-width: 310px; }
    .sc-nm-box h3 { font-size: 0.9rem; margin-bottom: 12px; }
    .sc-nm-input { font-size: 0.8rem; padding: 8px 12px; margin-bottom: 11px; border-radius: 10px; }
    .sc-vis-row { padding: 8px 12px; margin-bottom: 12px; border-radius: 10px; }
    .sc-vis-label { font-size: 0.73rem; }
    .sc-vis-toggle { width: 34px; height: 19px; }
    .sc-vis-slider::before { width: 13px; height: 13px; }
    .sc-vis-toggle input:checked + .sc-vis-slider::before { transform: translateX(15px); }
    .sc-nm-btns { gap: 8px; }
    .sc-nm-cancel, .sc-nm-save { font-size: 0.76rem; padding: 8px; border-radius: 10px; }

    /* Savers */
    .savers-sheet { border-radius: 20px 20px 16px 16px; max-height: 68vh; }
    .savers-hdr { padding: 13px 15px 10px; }
    .savers-hdr-title { font-size: 0.82rem; gap: 7px; }
    .savers-close { width: 26px; height: 26px; font-size: 0.82rem; }
    .saver-row { padding: 10px 15px; gap: 11px; }
    .saver-avatar { width: 34px; height: 34px; border-width: 1.5px; }
    .saver-name { font-size: 0.76rem; }
    .saver-count { font-size: 0.62rem; }

    /* Toast */
    .sc-toast { bottom: 76px; font-size: 0.7rem; padding: 7px 16px; }

    /* Empty state */
    .empty-state { padding: 36px 14px; }
    .empty-state i { font-size: 1.6rem; margin-bottom: 8px; }
    .empty-state p { font-size: 0.76rem; }

    /* History btn */
    .history-btn { font-size: 0.65rem; padding: 6px 12px; gap: 5px; margin-bottom: 14px; }
    .history-btn i { font-size: 0.82rem; }
}


/* ══════════════════════════════════════════════
   RESPONSIVE — 360px
   Compact mobile (e.g. Galaxy S series)
══════════════════════════════════════════════ */
@media (max-width: 360px) {
    :root { --radius: 12px; }

    body { padding: 0 0 64px; }

    .page-wrap { padding: 0 10px; }

    /* Topbar */
    .topbar { padding: 12px 10px 9px; gap: 8px; }
    .back-btn { font-size: 1.1rem; }
    .topbar-title { font-size: 0.82rem; }

    /* Hero */
    .hero { gap: 12px; padding: 4px 0 16px; }
    .hero-avatar { width: 58px; height: 58px; }
    .hero-name { font-size: 0.88rem; gap: 4px; }
    .hero-sub { font-size: 0.62rem; }
    .hero-stats { margin-top: 9px; }
    .hero-stat { padding: 8px 4px; }
    .hero-stat-num { font-size: 0.85rem; }
    .hero-stat-label { font-size: 0.52rem; letter-spacing: 0.03em; }

    /* Upload */
    .upload-section { padding: 10px 12px; margin-bottom: 12px; }
    .upload-label { font-size: 0.6rem; margin-bottom: 8px; }
    .u-input { font-size: 0.73rem; padding: 7px 10px; border-radius: 8px; }
    .u-file-label { font-size: 0.7rem; padding: 7px 10px; border-radius: 8px; }
    .u-submit { font-size: 0.73rem; padding: 7px 12px; border-radius: 8px; }

    /* Tabs */
    .tabs-wrap { top: 48px; }
    .tab-btn { padding: 9px 3px; min-width: 54px; font-size: 0.65rem; }
    .tab-btn .tab-count { font-size: 0.78rem; }
    .tab-btn .tab-label { font-size: 0.54rem; }

    /* Search */
    .search-input { font-size: 0.72rem; padding: 7px 10px 7px 34px; border-radius: 10px; }
    .search-icon { left: 11px; font-size: 0.85rem; }

    /* Sound card */
    .sound-card-inner { gap: 8px; padding: 10px 11px 7px; }
    .sound-icon { width: 34px; height: 34px; border-radius: 9px; }
    .sound-icon i { font-size: 0.9rem; }
    .sound-title { font-size: 0.76rem; }
    .sound-uploader { font-size: 0.61rem; }
    .sound-uploader img { width: 13px; height: 13px; }
    .audio-player { gap: 7px; padding: 0 11px 10px; }
    .play-btn { width: 26px; height: 26px; font-size: 0.7rem; }
    .progress-bar { height: 2.5px; }
    .progress-times { font-size: 0.53rem; }
    .sound-actions { gap: 5px; padding: 6px 11px 9px; }
    .s-action-btn { width: 26px; height: 26px; font-size: 0.78rem; border-radius: 7px; }
    .s-save-btn { font-size: 0.6rem; padding: 5px 10px; }

    /* Folders — 2 cols compact */
    .folders-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .folder-info { padding: 7px 9px; }
    .folder-name { font-size: 0.7rem; }
    .folder-count { font-size: 0.58rem; }
    .folder-vis { font-size: 0.55rem; margin-top: 3px; }
    .folder-pub-badge { font-size: 0.52rem; padding: 1.5px 5px; top: 5px; left: 5px; }
    .folder-opts-btn { width: 22px; height: 22px; font-size: 0.7rem; top: 5px; right: 5px; }
    .new-folder-btn { min-height: 108px; font-size: 0.64rem; gap: 5px; }
    .new-folder-btn i { font-size: 1.2rem; }
    .fp-cell i { font-size: 0.9rem; }
    .fp-cell.empty i { font-size: 0.7rem; }

    /* Folder detail */
    .folder-detail-title { font-size: 0.85rem; }
    .folder-back-btn { width: 28px; height: 28px; font-size: 0.85rem; border-radius: 8px; }
    .folder-detail-hdr { gap: 10px; margin-bottom: 12px; padding-bottom: 12px; }

    /* Opts dropdown */
    .sc-opts-dd { min-width: 150px; border-radius: 11px; }
    .sc-opts-item { padding: 9px 12px; font-size: 0.72rem; gap: 7px; }
    .sc-opts-item i { font-size: 0.85rem; }

    /* ATF sheet */
    .atf-hdr { padding: 12px 13px 9px; }
    .atf-title { font-size: 0.78rem; }
    .atf-close { width: 24px; height: 24px; font-size: 0.78rem; }
    .atf-item { padding: 8px 13px; gap: 8px; }
    .atf-icon { width: 30px; height: 30px; border-radius: 7px; }
    .atf-icon i { font-size: 0.9rem; }
    .atf-name { font-size: 0.72rem; }
    .atf-check { width: 18px; height: 18px; font-size: 0.58rem; }
    .atf-new-row { padding: 10px 13px; font-size: 0.72rem; gap: 7px; }

    /* Name modal */
    .sc-nm-box { padding: 16px; border-radius: 14px; }
    .sc-nm-box h3 { font-size: 0.85rem; margin-bottom: 10px; }
    .sc-nm-input { font-size: 0.76rem; padding: 7px 10px; margin-bottom: 10px; border-radius: 9px; }
    .sc-vis-row { padding: 7px 10px; border-radius: 9px; margin-bottom: 10px; }
    .sc-vis-label { font-size: 0.7rem; gap: 5px; }
    .sc-vis-toggle { width: 32px; height: 18px; }
    .sc-vis-slider::before { width: 12px; height: 12px; top: 2px; left: 2px; }
    .sc-vis-toggle input:checked + .sc-vis-slider::before { transform: translateX(14px); }
    .sc-nm-btns { gap: 7px; }
    .sc-nm-cancel, .sc-nm-save { font-size: 0.72rem; padding: 7px; border-radius: 9px; }

    /* Savers */
    .saver-row { padding: 9px 13px; gap: 10px; }
    .saver-avatar { width: 32px; height: 32px; }
    .saver-name { font-size: 0.73rem; }
    .saver-count { font-size: 0.59rem; }
    .savers-hdr { padding: 12px 13px 9px; }
    .savers-hdr-title { font-size: 0.78rem; gap: 6px; }

    /* Toast */
    .sc-toast { bottom: 72px; font-size: 0.67rem; padding: 7px 14px; border-radius: 40px; }

    /* Empty state */
    .empty-state { padding: 32px 12px; }
    .empty-state i { font-size: 1.5rem; }
    .empty-state p { font-size: 0.73rem; }

    /* History btn */
    .history-btn { font-size: 0.62rem; padding: 6px 11px; margin-bottom: 12px; }
}


/* ══════════════════════════════════════════════
   RESPONSIVE — 320px
   Smallest common mobile (iPhone SE gen1, older Androids)
══════════════════════════════════════════════ */
@media (max-width: 320px) {
    :root { --radius: 11px; }

    body { padding: 0 0 60px; }

    .page-wrap { padding: 0 8px; }

    /* Topbar */
    .topbar { padding: 11px 8px 8px; gap: 7px; }
    .back-btn { font-size: 1rem; }
    .topbar-title { font-size: 0.78rem; }

    /* Hero */
    .hero { gap: 10px; padding: 3px 0 14px; }
    .hero-avatar { width: 52px; height: 52px; border-width: 2px; }
    .hero-name { font-size: 0.82rem; gap: 4px; }
    .hero-sub { font-size: 0.58rem; }
    .hero-stat { padding: 7px 3px; }
    .hero-stat-num { font-size: 0.8rem; }
    .hero-stat-label { font-size: 0.5rem; }

    /* Upload */
    .upload-section { padding: 9px 10px; margin-bottom: 10px; }
    .upload-label { font-size: 0.58rem; }
    .u-input { font-size: 0.7rem; padding: 6px 9px; }
    .u-file-label { font-size: 0.67rem; padding: 6px 9px; }
    .u-submit { font-size: 0.7rem; padding: 6px 10px; }

    /* Tabs */
    .tabs-wrap { top: 44px; }
    .tab-btn { padding: 8px 2px; min-width: 48px; }
    .tab-btn .tab-count { font-size: 0.73rem; }
    .tab-btn .tab-label { font-size: 0.5rem; letter-spacing: 0; }

    /* Search */
    .search-input { font-size: 0.7rem; padding: 6px 9px 6px 30px; border-radius: 9px; }
    .search-icon { left: 9px; font-size: 0.8rem; }

    /* Sound card */
    .sound-card-inner { gap: 7px; padding: 9px 10px 6px; }
    .sound-icon { width: 32px; height: 32px; border-radius: 8px; }
    .sound-icon i { font-size: 0.85rem; }
    .eq-bar { width: 2.5px; }
    .sound-title { font-size: 0.72rem; }
    .sound-uploader { font-size: 0.58rem; gap: 4px; }
    .sound-uploader img { width: 12px; height: 12px; }
    .audio-player { gap: 6px; padding: 0 10px 9px; }
    .play-btn { width: 24px; height: 24px; font-size: 0.65rem; }
    .progress-times { font-size: 0.5rem; }
    .sound-actions { gap: 4px; padding: 6px 10px 8px; }
    .s-action-btn { width: 24px; height: 24px; font-size: 0.75rem; border-radius: 6px; }
    .s-save-btn { font-size: 0.58rem; padding: 4px 9px; }

    /* Folders — 2 cols tight */
    .folders-grid { grid-template-columns: repeat(2, 1fr); gap: 7px; }
    .folder-info { padding: 6px 8px; }
    .folder-name { font-size: 0.66rem; }
    .folder-count { font-size: 0.56rem; }
    .folder-vis { font-size: 0.52rem; }
    .folder-pub-badge { font-size: 0.5rem; padding: 1px 5px; }
    .folder-opts-btn { width: 20px; height: 20px; font-size: 0.65rem; }
    .new-folder-btn { min-height: 96px; font-size: 0.6rem; gap: 4px; }
    .new-folder-btn i { font-size: 1.1rem; }
    .fp-cell i { font-size: 0.82rem; }
    .fp-cell.empty i { font-size: 0.65rem; }

    /* Folder detail */
    .folder-detail-title { font-size: 0.8rem; }
    .folder-back-btn { width: 26px; height: 26px; font-size: 0.8rem; border-radius: 7px; }

    /* Opts dropdown */
    .sc-opts-dd { min-width: 140px; border-radius: 10px; }
    .sc-opts-item { padding: 8px 11px; font-size: 0.68rem; gap: 6px; }

    /* ATF sheet */
    .atf-hdr { padding: 11px 12px 8px; }
    .atf-title { font-size: 0.74rem; }
    .atf-item { padding: 7px 12px; gap: 7px; }
    .atf-icon { width: 28px; height: 28px; border-radius: 6px; }
    .atf-icon i { font-size: 0.85rem; }
    .atf-name { font-size: 0.68rem; }
    .atf-count { font-size: 0.58rem; }
    .atf-check { width: 16px; height: 16px; font-size: 0.55rem; }
    .atf-new-row { padding: 9px 12px; font-size: 0.68rem; gap: 6px; }

    /* Name modal */
    .sc-nm-box { padding: 14px; border-radius: 13px; }
    .sc-nm-box h3 { font-size: 0.8rem; margin-bottom: 9px; }
    .sc-nm-input { font-size: 0.72rem; padding: 6px 9px; margin-bottom: 9px; border-radius: 8px; }
    .sc-vis-row { padding: 6px 9px; margin-bottom: 9px; border-radius: 8px; }
    .sc-vis-label { font-size: 0.66rem; }
    .sc-vis-toggle { width: 30px; height: 17px; }
    .sc-vis-slider::before { width: 11px; height: 11px; }
    .sc-vis-toggle input:checked + .sc-vis-slider::before { transform: translateX(13px); }
    .sc-nm-btns { gap: 6px; }
    .sc-nm-cancel, .sc-nm-save { font-size: 0.68rem; padding: 6px; border-radius: 8px; }

    /* Savers */
    .saver-row { padding: 8px 12px; gap: 9px; }
    .saver-avatar { width: 30px; height: 30px; }
    .saver-name { font-size: 0.7rem; }
    .saver-count { font-size: 0.56rem; }
    .savers-hdr { padding: 11px 12px 8px; }
    .savers-hdr-title { font-size: 0.74rem; gap: 5px; }

    /* Toast */
    .sc-toast { bottom: 68px; font-size: 0.64rem; padding: 6px 12px; }

    /* Empty state */
    .empty-state { padding: 28px 10px; }
    .empty-state i { font-size: 1.35rem; margin-bottom: 7px; }
    .empty-state p { font-size: 0.7rem; }

    /* History btn */
    .history-btn { font-size: 0.59rem; padding: 5px 10px; margin-bottom: 11px; gap: 4px; }
    .history-btn i { font-size: 0.75rem; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <a href="profile.php?user_id=<?=$profile_id?>" class="back-btn" aria-label="Go back">
        <i class="ri-arrow-left-s-line"></i>
    </a>
    <span class="topbar-title"><?=htmlspecialchars($user['username'])?> · Sounds</span>
</div>

<div class="page-wrap">

    <!-- HERO -->
    <div class="hero">
        <a href="profile.php?user_id=<?=$profile_id?>">
            <img class="hero-avatar"
                 src="<?=htmlspecialchars($user['profile_pic'])?>"
                 onerror="this.src='uploads/default.jpg'"
                 alt="<?=htmlspecialchars($user['username'])?>">
        </a>
        <div class="hero-info">
            <div class="hero-name">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="12" fill="#FF4C4C"/>
                    <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?=htmlspecialchars($user['username'])?>
            </div>
            <div class="hero-sub"><?=$is_owner ? 'Your Sound Library' : 'Sound Library'?></div>
        </div>
    </div>

  

    <!-- WHO SAVED BUTTON (owner only) -->
    <?php if($is_owner): ?>
    <button class="history-btn" onclick="openSaversModal()">
        <i class="ri-history-line"></i>
        Who saved your sounds
    </button>
    <?php endif; ?>

    <!-- UPLOAD (owner only) -->
    <?php if($is_owner): ?>
    <div class="upload-section" style="margin-bottom:20px;">
        <div class="upload-label"><i class="ri-upload-2-line"></i> Upload Sound</div>
        <form class="upload-form" method="POST" enctype="multipart/form-data">
            <input class="u-input" type="text" name="title" placeholder="Sound title..." required>
            <label class="u-file-label">
                <i class="ri-music-line"></i> Choose File
                <input type="file" name="audio" accept="audio/*" required>
            </label>
            <button class="u-submit" name="upload_sound" type="submit">
                <i class="ri-upload-cloud-2-line"></i> Upload
            </button>
        </form>
    </div>
    <?php endif; ?>

</div>

<!-- TABS -->
<div class="tabs-wrap">
    <button class="tab-btn active" data-tab="panel-uploaded">
        <span class="tab-count"><?=$sounds_count?></span>
        <span class="tab-label"><?=$is_owner ? 'Your Sounds' : 'Sounds'?></span>
    </button>
    <button class="tab-btn" data-tab="panel-added">
        <span class="tab-count"><?=$added_count?></span>
        <span class="tab-label"><?=$is_owner ? 'Saved' : 'Saves'?></span>
    </button>
    <button class="tab-btn" data-tab="panel-folders">
        <span class="tab-count"><?=$folders_count?></span>
        <span class="tab-label">Folders</span>
    </button>
</div>

<div class="page-wrap">

    <!-- SEARCH -->
    <div class="search-wrap" id="searchRow">
        <i class="ri-search-line search-icon"></i>
        <input type="text" class="search-input" id="scSearch" placeholder="Search by title or user...">
    </div>

    <!-- ── TAB: UPLOADED ── -->
    <div class="tab-panel active" id="panel-uploaded">
        <div class="sound-list" id="grid-uploaded">
            <?php if(!$my_sounds || $my_sounds->num_rows===0): ?>
            <div class="empty-state"><i class="ri-music-2-line"></i><p>No sounds uploaded yet</p></div>
            <?php else: ?>
            <?php while($s=$my_sounds->fetch_assoc()): ?>
            <div class="sound-card"
                 data-id="<?=(int)$s['id']?>"
                 data-title="<?=htmlspecialchars(strtolower($s['title']))?>"
                 data-user="<?=htmlspecialchars(strtolower($s['username']))?>">
                <div class="sound-card-inner">
                    <div class="sound-icon">
                        <i class="ri-music-2-line"></i>
                        <div class="eq-bars">
                            <div class="eq-bar"></div><div class="eq-bar"></div>
                            <div class="eq-bar"></div><div class="eq-bar"></div>
                            <div class="eq-bar"></div>
                        </div>
                    </div>
                    <div class="sound-meta">
                        <div class="sound-title"><?=htmlspecialchars($s['title'])?></div>
                        <a class="sound-uploader" href="profile.php?user_id=<?=(int)$s['original_user_id']?>">
                            <img src="<?=htmlspecialchars($s['profile_pic'])?>" onerror="this.src='uploads/default.jpg'">
                            <?=$is_owner ? 'You' : htmlspecialchars($s['username'])?>
                        </a>
                    </div>
                </div>
                <div class="audio-player" data-src="uploads/sounds/<?=htmlspecialchars($s['file_name'])?>">
                    <button class="play-btn" onclick="scTogglePlay(this)"><i class="ri-play-fill"></i></button>
                    <div class="progress-wrap">
                        <div class="progress-bar" onclick="scSeek(this,event)"><div class="progress-fill"></div></div>
                        <div class="progress-times"><span class="pt-cur">0:00</span><span class="pt-dur">0:00</span></div>
                    </div>
                </div>
                <div class="sound-actions">
                    <a class="s-action-btn" href="uploads/sounds/<?=htmlspecialchars($s['file_name'])?>" download title="Download"><i class="ri-download-line"></i></a>
                    <?php if($is_owner): ?>
                    <button class="s-action-btn folder" title="Add to Folder" onclick="scOpenAddToFolder(<?=(int)$s['id']?>)"><i class="ri-folder-add-line"></i></button>
                    <?php endif; ?>
                    <div class="s-spacer"></div>
                    <?php if(!$is_owner): ?>
                    <button class="s-save-btn save" onclick="scSaveSound(<?=(int)$s['id']?>)">+ Save</button>
                    <?php else: ?>
                    <button class="s-action-btn danger" title="Delete" onclick="scDeleteSound(<?=(int)$s['id']?>)"><i class="ri-delete-bin-line"></i></button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── TAB: SAVED ── -->
    <div class="tab-panel" id="panel-added">
        <div class="sound-list" id="grid-added">
            <?php if(!$added_sounds || $added_sounds->num_rows===0): ?>
            <div class="empty-state"><i class="ri-bookmark-line"></i><p>No saved sounds yet</p></div>
            <?php else: ?>
            <?php while($s=$added_sounds->fetch_assoc()): ?>
            <div class="sound-card"
                 data-id="<?=(int)$s['id']?>"
                 data-title="<?=htmlspecialchars(strtolower($s['title']))?>"
                 data-user="<?=htmlspecialchars(strtolower($s['username']))?>">
                <div class="sound-card-inner">
                    <div class="sound-icon">
                        <i class="ri-music-2-line"></i>
                        <div class="eq-bars">
                            <div class="eq-bar"></div><div class="eq-bar"></div>
                            <div class="eq-bar"></div><div class="eq-bar"></div>
                            <div class="eq-bar"></div>
                        </div>
                    </div>
                    <div class="sound-meta">
                        <div class="sound-title"><?=htmlspecialchars($s['title'])?></div>
                        <a class="sound-uploader" href="profile.php?user_id=<?=(int)$s['original_user_id']?>">
                            <img src="<?=htmlspecialchars($s['profile_pic'])?>" onerror="this.src='uploads/default.jpg'">
                            <?=htmlspecialchars($s['username'])?>
                        </a>
                    </div>
                </div>
                <div class="audio-player" data-src="uploads/sounds/<?=htmlspecialchars($s['file_name'])?>">
                    <button class="play-btn" onclick="scTogglePlay(this)"><i class="ri-play-fill"></i></button>
                    <div class="progress-wrap">
                        <div class="progress-bar" onclick="scSeek(this,event)"><div class="progress-fill"></div></div>
                        <div class="progress-times"><span class="pt-cur">0:00</span><span class="pt-dur">0:00</span></div>
                    </div>
                </div>
                <div class="sound-actions">
                    <a class="s-action-btn" href="uploads/sounds/<?=htmlspecialchars($s['file_name'])?>" download title="Download"><i class="ri-download-line"></i></a>
                    <?php if($is_owner): ?>
                    <button class="s-action-btn folder" title="Add to Folder" onclick="scOpenAddToFolder(<?=(int)$s['id']?>)"><i class="ri-folder-add-line"></i></button>
                    <?php endif; ?>
                    <div class="s-spacer"></div>
                    <?php if(!$is_owner): ?>
                    <button class="s-save-btn save" onclick="scSaveSound(<?=(int)$s['id']?>)">+ Save</button>
                    <?php else: ?>
                    <button class="s-save-btn unsave" onclick="scUnsaveSound(<?=(int)$s['id']?>)">Remove</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── TAB: FOLDERS ── -->
    <div class="tab-panel" id="panel-folders">
        <div id="scFoldersList">
            <div class="folders-grid" id="scFoldersGrid">
                <?php if($is_owner): ?>
                <button class="new-folder-btn" onclick="scOpenNameModal('create')">
                    <i class="ri-folder-add-line"></i>
                    New Folder
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="folder-detail" id="scFolderDetail">
            <div class="folder-detail-hdr">
                <button class="folder-back-btn" onclick="scCloseDetail()"><i class="ri-arrow-left-line"></i></button>
                <div class="folder-detail-title" id="scDetailTitle">Folder</div>
                <div class="folder-detail-count" id="scDetailCount">0 items</div>
            </div>
            <div class="sound-list" id="scDetailGrid"></div>
        </div>
    </div>

</div><!-- /page-wrap -->

<!-- WHO SAVED MODAL -->
<div class="savers-modal-overlay" id="saversModal" onclick="if(event.target===this)closeSaversModal()">
    <div class="savers-sheet">
        <div class="savers-hdr">
            <div class="savers-hdr-title"><i class="ri-history-line"></i> Who Saved Your Sounds</div>
            <button class="savers-close" onclick="closeSaversModal()">✕</button>
        </div>
        <div class="savers-list">
            <?php if(count($savers_list) > 0): ?>
                <?php foreach($savers_list as $ab): ?>
                <a class="saver-row" href="profile.php?user_id=<?=(int)$ab['id']?>">
                    <img class="saver-avatar" src="<?=htmlspecialchars($ab['profile_pic'])?>" onerror="this.src='uploads/default.jpg'" alt="">
                    <div class="saver-info">
                        <div class="saver-name"><?=htmlspecialchars($ab['username'])?></div>
                        <div class="saver-count"><?=(int)$ab['cnt']?> sound<?=$ab['cnt']!=1?'s':''?> saved</div>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state"><i class="ri-headphone-line"></i><p>No one has saved your sounds yet</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ADD TO FOLDER MODAL -->
<div id="scAddFolderModal" onclick="if(event.target===this)scCloseAddToFolder()">
    <div class="atf-sheet">
        <div class="atf-hdr">
            <div class="atf-title">Add to Folder</div>
            <button class="atf-close" onclick="scCloseAddToFolder()">✕</button>
        </div>
        <div class="atf-list" id="atfList"></div>
        <div class="atf-new-row" onclick="scOpenNameModal('create');scCloseAddToFolder();">
            <i class="ri-add-circle-line"></i> New Folder
        </div>
    </div>
</div>

<!-- OPTS DROPDOWN -->
<div class="sc-opts-dd" id="scOptsDd">
    <div class="sc-opts-item vis-item" id="scOptsVisItem" onclick="scOptsDo('toggle_vis')">
        <i class="ri-earth-line"></i>
        <span id="scOptsVisLabel">Make Public</span>
    </div>
    <div class="sc-opts-item" onclick="scOptsDo('rename')"><i class="ri-edit-line"></i> Rename</div>
    <div class="sc-opts-item danger" onclick="scOptsDo('delete')"><i class="ri-delete-bin-line"></i> Delete</div>
</div>

<!-- NAME MODAL -->
<div id="scNameModal" onclick="if(event.target===this)scCloseNameModal()">
    <div class="sc-nm-box">
        <h3 id="scNmTitle">New Folder</h3>
        <input type="text" class="sc-nm-input" id="scNmInput" placeholder="e.g. Chill Vibes" maxlength="60"
               onkeydown="if(event.key==='Enter')scSaveName()">
        <div class="sc-vis-row" id="scVisRow">
            <label class="sc-vis-label" for="scVisTog"><i class="ri-earth-line"></i> Make Public</label>
            <label class="sc-vis-toggle"><input type="checkbox" id="scVisTog"><span class="sc-vis-slider"></span></label>
        </div>
        <div class="sc-nm-btns">
            <button class="sc-nm-cancel" onclick="scCloseNameModal()">Cancel</button>
            <button class="sc-nm-save" onclick="scSaveName()">Save</button>
        </div>
    </div>
</div>

<script>
// ═══════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════
var _scIsOwner    = <?=$is_owner?'true':'false'?>;
var _scProfileId  = <?=(int)$profile_id?>;
var _scFolders    = <?=json_encode(array_values($sound_folders))?>;
var _scActiveSoundId  = null;
var _scActiveFolderId = null;
var _scNmMode     = 'create';
var _scOptsId     = null;
var _scOptsName   = null;
var _scOptsPublic = false;
var _scCurrentAudio = null;
var _scCurrentBtn   = null;
var _scCurrentCard  = null;

// ═══════════════════════════════════════════
//  TABS
// ═══════════════════════════════════════════
document.querySelectorAll('.tab-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        var target = btn.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        var panel = document.getElementById(target);
        if(panel) panel.classList.add('active');
        var sr = document.getElementById('searchRow');
        if(sr) sr.style.display = (target === 'panel-folders') ? 'none' : 'flex';
        if(target === 'panel-folders') scRenderFolders();
    });
});

// ═══════════════════════════════════════════
//  SEARCH
// ═══════════════════════════════════════════
document.getElementById('scSearch').addEventListener('input', function(){
    var q = this.value.toLowerCase();
    document.querySelectorAll('#panel-uploaded .sound-card, #panel-added .sound-card').forEach(function(c){
        var t = c.dataset.title||'', u = c.dataset.user||'';
        c.style.display = (!q || t.includes(q) || u.includes(q)) ? '' : 'none';
    });
});

// ═══════════════════════════════════════════
//  WHO SAVED MODAL
// ═══════════════════════════════════════════
function openSaversModal(){ document.getElementById('saversModal').classList.add('open'); }
function closeSaversModal(){ document.getElementById('saversModal').classList.remove('open'); }
document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closeSaversModal(); scCloseAddToFolder(); scCloseNameModal(); } });

// ═══════════════════════════════════════════
//  AUDIO PLAYER
// ═══════════════════════════════════════════
function scFmt(s){ var m=Math.floor(s/60); return m+':'+String(Math.floor(s%60)).padStart(2,'0'); }

function scTogglePlay(btn){
    var player = btn.closest('.audio-player');
    var card   = btn.closest('.sound-card');
    var src    = player.dataset.src;

    if(_scCurrentAudio && !_scCurrentAudio.paused){
        _scCurrentAudio.pause();
        if(_scCurrentBtn) _scCurrentBtn.innerHTML='<i class="ri-play-fill"></i>';
        if(_scCurrentCard) _scCurrentCard.classList.remove('is-playing');
        if(_scCurrentBtn === btn){ _scCurrentAudio=null; _scCurrentBtn=null; _scCurrentCard=null; return; }
    }

    if(!player._audio){
        player._audio = new Audio(src);
        player._audio.addEventListener('timeupdate', function(){
            var fill = player.querySelector('.progress-fill');
            var cur  = player.querySelector('.pt-cur');
            if(fill && this.duration) fill.style.width=(this.currentTime/this.duration*100)+'%';
            if(cur) cur.textContent=scFmt(this.currentTime);
        });
        player._audio.addEventListener('loadedmetadata', function(){
            var dur = player.querySelector('.pt-dur');
            if(dur) dur.textContent=scFmt(this.duration);
        });
        player._audio.addEventListener('ended', function(){
            btn.innerHTML='<i class="ri-play-fill"></i>';
            card.classList.remove('is-playing');
            _scCurrentAudio=null; _scCurrentBtn=null; _scCurrentCard=null;
        });
    }

    player._audio.play();
    btn.innerHTML='<i class="ri-pause-fill"></i>';
    card.classList.add('is-playing');
    _scCurrentAudio=player._audio; _scCurrentBtn=btn; _scCurrentCard=card;
}

function scSeek(bar, e){
    var player = bar.closest('.audio-player');
    if(!player._audio) return;
    var rect=bar.getBoundingClientRect();
    player._audio.currentTime = ((e.clientX-rect.left)/rect.width)*player._audio.duration;
}

// ═══════════════════════════════════════════
//  FETCH HELPER
// ═══════════════════════════════════════════
function _scFetch(body,cb){
    fetch(window.location.href,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:body
    }).then(function(r){return r.json();}).then(cb).catch(function(){scToast('Something went wrong');});
}

// ═══════════════════════════════════════════
//  SAVE / UNSAVE / DELETE
// ═══════════════════════════════════════════
function scSaveSound(id){
    fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=save&sound_id='+id})
    .then(function(){scToast('Saved ✓');setTimeout(function(){location.reload();},700);});
}
function scUnsaveSound(id){
    fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=unsave&sound_id='+id})
    .then(function(){scToast('Removed');setTimeout(function(){location.reload();},700);});
}
function scDeleteSound(id){
    if(!confirm('Delete this sound?')) return;
    fetch('delete_sound.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'sound_id='+id})
    .then(function(r){return r.json();})
    .then(function(d){ if(d.status==='success'){scToast('Deleted');setTimeout(function(){location.reload();},700);} else scToast(d.message||'Error'); })
    .catch(function(){scToast('Error');});
}

// ═══════════════════════════════════════════
//  FOLDERS RENDER
// ═══════════════════════════════════════════
function scRenderFolders(){
    var grid = document.getElementById('scFoldersGrid');
    var fl   = document.getElementById('scFoldersList');
    var det  = document.getElementById('scFolderDetail');
    if(!grid) return;
    if(fl) fl.style.display='block';
    if(det) det.style.display='none';

    Array.from(grid.querySelectorAll('.folder-card')).forEach(function(c){c.remove();});

    if(_scFolders.length===0 && !_scIsOwner){
        var emp=document.createElement('div');
        emp.className='empty-state';
        emp.style.cssText='grid-column:1/-1';
        emp.innerHTML='<i class="ri-folder-open-line"></i><p>No public folders yet</p>';
        var nb=grid.querySelector('.new-folder-btn');
        nb ? grid.insertBefore(emp, nb) : grid.appendChild(emp);
        return;
    }

    _scFolders.forEach(function(f){
        var isP = f.is_public==1;
        var card=document.createElement('div');
        card.className='folder-card'+(isP?' is-public':'');
        card.dataset.fid=f.id;

        var optsBtn = _scIsOwner
            ? '<button class="folder-opts-btn" onclick="event.stopPropagation();scOpenOpts(event,'+f.id+',\''+_scEsc(f.name)+'\','+(isP?'true':'false')+')" title="Options"><i class="ri-more-2-line"></i></button>'
            : '';
        var badge = isP ? '<div class="folder-pub-badge">Public</div>' : '';

        card.innerHTML=
            badge+
            '<div class="folder-preview" id="sfp-'+f.id+'">'+
                '<div class="fp-cell empty"><i class="ri-music-line"></i></div>'.repeat(4)+
            '</div>'+
            '<div class="folder-info">'+
                '<div class="folder-name">'+_scEsc(f.name)+'</div>'+
                '<div class="folder-count" id="sfc-'+f.id+'">'+f.item_count+' track'+(f.item_count==1?'':'s')+'</div>'+
                (_scIsOwner ? '<div class="folder-vis '+(isP?'pub':'priv')+'" id="sfv-'+f.id+'">'+(isP?'<i class="ri-earth-line"></i> Public':'<i class="ri-lock-line"></i> Private')+'</div>':'')
            +'</div>'+
            optsBtn;

        card.addEventListener('click', function(){ scOpenDetail(f.id, f.name); });
        var nb=grid.querySelector('.new-folder-btn');
        grid.insertBefore(card, nb||null);
    });
}

function scOpenDetail(folderId, folderName){
    _scActiveFolderId = folderId;
    var fl  = document.getElementById('scFoldersList');
    var det = document.getElementById('scFolderDetail');
    var dt  = document.getElementById('scDetailTitle');
    var dc  = document.getElementById('scDetailCount');
    var dg  = document.getElementById('scDetailGrid');

    if(fl) fl.style.display='none';
    if(det) det.style.display='block';
    if(dt) dt.textContent=folderName;
    if(dc) dc.textContent='Loading...';
    if(dg) dg.innerHTML='<div class="empty-state"><i class="ri-loader-4-line" style="animation:spin 0.8s linear infinite;font-size:1.5rem;opacity:1;"></i><p>Loading...</p></div>';

    _scFetch('sc_action=get_folder_items&folder_id='+folderId, function(data){
        if(data.status!=='ok'){
            if(dg) dg.innerHTML='<div class="empty-state"><i class="ri-folder-open-line"></i><p>Error loading</p></div>';
            return;
        }
        var items=data.items||[];
        if(dc) dc.textContent=items.length+' track'+(items.length==1?'':'s');
        if(!dg) return;
        dg.innerHTML='';
        if(items.length===0){
            dg.innerHTML='<div class="empty-state"><i class="ri-folder-open-line"></i><p>No sounds yet</p></div>';
            return;
        }
        items.forEach(function(item){
            var card=document.createElement('div');
            card.className='sound-card';
            card.style.position='relative';
            var removeBtn=_scIsOwner
                ? '<button class="s-remove-btn" onclick="event.stopPropagation();scRemoveFromFolder('+item.id+',this)"><i class="ri-close-line"></i></button>'
                : '';
            card.innerHTML=
                removeBtn+
                '<div class="sound-card-inner">'+
                    '<div class="sound-icon"><i class="ri-music-2-line"></i><div class="eq-bars"><div class="eq-bar"></div><div class="eq-bar"></div><div class="eq-bar"></div><div class="eq-bar"></div><div class="eq-bar"></div></div></div>'+
                    '<div class="sound-meta">'+
                        '<div class="sound-title">'+_scEsc(item.title)+'</div>'+
                        '<a class="sound-uploader" href="profile.php?user_id='+item.original_user_id+'">'+
                            '<img src="'+_scEsc(item.profile_pic)+'" onerror="this.src=\'uploads/default.jpg\'">'+
                            _scEsc(item.username)+
                        '</a>'+
                    '</div>'+
                '</div>'+
                '<div class="audio-player" data-src="uploads/sounds/'+_scEsc(item.file_name)+'">'+
                    '<button class="play-btn" onclick="scTogglePlay(this)"><i class="ri-play-fill"></i></button>'+
                    '<div class="progress-wrap">'+
                        '<div class="progress-bar" onclick="scSeek(this,event)"><div class="progress-fill"></div></div>'+
                        '<div class="progress-times"><span class="pt-cur">0:00</span><span class="pt-dur">0:00</span></div>'+
                    '</div>'+
                '</div>'+
                '<div class="sound-actions">'+
                    '<a class="s-action-btn" href="uploads/sounds/'+_scEsc(item.file_name)+'" download><i class="ri-download-line"></i></a>'+
                '</div>';
            dg.appendChild(card);
        });
    });
}

function scCloseDetail(){
    _scActiveFolderId=null;
    document.getElementById('scFoldersList').style.display='block';
    document.getElementById('scFolderDetail').style.display='none';
}

function scRemoveFromFolder(soundId, btn){
    if(!_scActiveFolderId||!_scIsOwner) return;
    _scFetch('sc_action=remove_from_folder&folder_id='+_scActiveFolderId+'&sound_id='+soundId, function(d){
        if(d.status!=='ok'){scToast('Could not remove');return;}
        var card=btn.closest('.sound-card');
        if(card){card.style.transition='opacity 0.3s,transform 0.3s';card.style.opacity='0';card.style.transform='scale(0.95)';setTimeout(function(){card.remove();_scUpdateDetailCount();},300);}
        _scFolders.forEach(function(f){if(f.id==_scActiveFolderId)f.item_count=Math.max(0,f.item_count-1);});
        _scRefreshCount(_scActiveFolderId);
        scToast('Removed from folder');
    });
}

function _scUpdateDetailCount(){
    var dg=document.getElementById('scDetailGrid'),dc=document.getElementById('scDetailCount');
    if(!dg||!dc) return;
    var n=dg.querySelectorAll('.sound-card').length;
    dc.textContent=n+' track'+(n==1?'':'s');
}

function _scRefreshCount(fid){
    var f=_scFolders.find(function(x){return x.id==fid;});
    if(!f) return;
    var el=document.getElementById('sfc-'+fid);
    if(el) el.textContent=f.item_count+' track'+(f.item_count==1?'':'s');
}

// ═══════════════════════════════════════════
//  ADD TO FOLDER SHEET
// ═══════════════════════════════════════════
function scOpenAddToFolder(soundId){
    if(!_scIsOwner){scToast('Log in to use folders');return;}
    _scActiveSoundId=soundId;
    var list=document.getElementById('atfList');
    list.innerHTML='<div style="padding:20px;text-align:center;color:var(--muted);font-size:0.82rem;">Loading...</div>';
    document.getElementById('scAddFolderModal').classList.add('open');

    if(_scFolders.length===0){
        list.innerHTML='<div style="padding:20px;text-align:center;color:var(--muted);font-size:0.82rem;">No folders yet.<br>Create one below.</div>';
        return;
    }
    list.innerHTML='';
    _scFolders.forEach(function(f){
        var item=document.createElement('div');
        item.className='atf-item';
        item.dataset.fid=f.id;
        item.innerHTML=
            '<div class="atf-icon"><i class="ri-folder-music-line"></i></div>'+
            '<div class="atf-info"><div class="atf-name">'+_scEsc(f.name)+'</div><div class="atf-count" id="atfc-'+f.id+'">'+f.item_count+' track'+(f.item_count==1?'':'s')+'</div></div>'+
            '<div class="atf-check" id="atfk-'+f.id+'">✓</div>';
        item.addEventListener('click',function(){scToggleFolderItem(f.id,soundId);});
        list.appendChild(item);
    });
    _scFolders.forEach(function(f){
        _scFetch('sc_action=get_folder_items&folder_id='+f.id,function(d){
            if(d.status!=='ok') return;
            var has=(d.items||[]).some(function(i){return i.id==soundId;});
            var chk=document.getElementById('atfk-'+f.id);
            if(chk) chk.classList.toggle('on',has);
        });
    });
}

function scCloseAddToFolder(){ document.getElementById('scAddFolderModal').classList.remove('open'); _scActiveSoundId=null; }

function scToggleFolderItem(folderId,soundId){
    var chk=document.getElementById('atfk-'+folderId);
    var isAdded=chk&&chk.classList.contains('on');
    if(isAdded){
        _scFetch('sc_action=remove_from_folder&folder_id='+folderId+'&sound_id='+soundId,function(d){
            if(d.status!=='ok'){scToast('Could not remove');return;}
            if(chk)chk.classList.remove('on');
            _scFolders.forEach(function(f){if(f.id==folderId)f.item_count=Math.max(0,f.item_count-1);});
            _scRefreshCount(folderId);
            var ce=document.getElementById('atfc-'+folderId);
            if(ce){var ff=_scFolders.find(function(f){return f.id==folderId;});if(ff)ce.textContent=ff.item_count+' track'+(ff.item_count==1?'':'s');}
            scToast('Removed from folder');
        });
    } else {
        _scFetch('sc_action=add_to_folder&folder_id='+folderId+'&sound_id='+soundId,function(d){
            if(d.status==='already'){if(chk)chk.classList.add('on');scToast('Already in this folder');return;}
            if(d.status!=='ok'){scToast('Could not add');return;}
            if(chk)chk.classList.add('on');
            _scFolders.forEach(function(f){if(f.id==folderId)f.item_count=(d.item_count||f.item_count+1);});
            _scRefreshCount(folderId);
            var ce=document.getElementById('atfc-'+folderId);
            if(ce){var ff=_scFolders.find(function(f){return f.id==folderId;});if(ff)ce.textContent=ff.item_count+' track'+(ff.item_count==1?'':'s');}
            scToast('Added to folder ✓');
        });
    }
}

// ═══════════════════════════════════════════
//  OPTS DROPDOWN
// ═══════════════════════════════════════════
function scOpenOpts(e, fid, fname, isPublic){
    if(!_scIsOwner) return;
    e.stopPropagation();
    _scOptsId=fid; _scOptsName=fname; _scOptsPublic=isPublic;

    var lb=document.getElementById('scOptsVisLabel'), it=document.getElementById('scOptsVisItem');
    if(lb) lb.textContent=isPublic?'Make Private':'Make Public';
    if(it){var ic=it.querySelector('i');if(ic){ic.className=isPublic?'ri-lock-line':'ri-earth-line';ic.style.color=isPublic?'var(--red)':'var(--gold)';}}

    var dd=document.getElementById('scOptsDd');
    if(window.innerWidth<=768){dd.classList.add('open');return;}
    dd.style.visibility='hidden'; dd.style.display='block';
    var ddH=dd.offsetHeight; dd.style.display=''; dd.style.visibility='';
    var rect=e.currentTarget.getBoundingClientRect(), GAP=8;
    var top=rect.top+window.scrollY>=ddH+GAP ? rect.top+window.scrollY-ddH-GAP : rect.bottom+window.scrollY+GAP;
    var left=Math.min(rect.left, window.innerWidth-200);
    dd.style.top=top+'px'; dd.style.left=left+'px';
    dd.classList.add('open');
}

function scOptsDo(action){
    document.getElementById('scOptsDd').classList.remove('open');
    if(action==='rename'){
        scOpenNameModal('rename',_scOptsName);
    } else if(action==='delete'){
        if(!confirm('Delete "'+_scOptsName+'"?\nSounds inside will NOT be deleted.')) return;
        _scFetch('sc_action=delete_folder&folder_id='+_scOptsId,function(d){
            if(d.status!=='ok'){scToast('Could not delete');return;}
            _scFolders=_scFolders.filter(function(f){return f.id!=_scOptsId;});
            scRenderFolders(); scToast('Folder deleted');
        });
    } else if(action==='toggle_vis'){
        var nv=_scOptsPublic?0:1;
        _scFetch('sc_action=set_visibility&folder_id='+_scOptsId+'&is_public='+nv,function(d){
            if(d.status!=='ok'){scToast('Could not update');return;}
            _scFolders.forEach(function(f){if(f.id==_scOptsId)f.is_public=nv;});
            scRenderFolders(); scToast(nv?'Folder is now Public 🌐':'Folder is now Private 🔒');
        });
    }
}

document.addEventListener('click',function(e){
    var dd=document.getElementById('scOptsDd');
    if(dd&&!dd.contains(e.target)) dd.classList.remove('open');
});

// ═══════════════════════════════════════════
//  NAME MODAL
// ═══════════════════════════════════════════
function scOpenNameModal(mode, currentName){
    _scNmMode=mode;
    var inp=document.getElementById('scNmInput'),
        ttl=document.getElementById('scNmTitle'),
        vr=document.getElementById('scVisRow'),
        vt=document.getElementById('scVisTog');
    if(ttl) ttl.textContent=mode==='rename'?'Rename Folder':'New Folder';
    if(inp) inp.value=mode==='rename'?(currentName||''):'';
    if(vr) vr.style.display=mode==='rename'?'none':'flex';
    if(vt&&mode==='create') vt.checked=false;
    document.getElementById('scNameModal').classList.add('open');
    setTimeout(function(){if(inp)inp.focus();},280);
}
function scCloseNameModal(){ document.getElementById('scNameModal').classList.remove('open'); }

function scSaveName(){
    var name=(document.getElementById('scNmInput').value||'').trim();
    if(!name){scToast('Please enter a name');return;}
    if(_scNmMode==='create'){
        var isP=document.getElementById('scVisTog').checked?1:0;
        _scFetch('sc_action=create_folder&name='+encodeURIComponent(name)+'&is_public='+isP,function(d){
            if(d.status!=='ok'){scToast('Could not create');return;}
            _scFolders.unshift({id:d.id,name:d.name,is_public:d.is_public||0,item_count:0});
            scCloseNameModal();
            // switch to folders tab
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            var folderBtn=document.querySelector('[data-tab="panel-folders"]');
            if(folderBtn) folderBtn.classList.add('active');
            var folderPanel=document.getElementById('panel-folders');
            if(folderPanel) folderPanel.classList.add('active');
            document.getElementById('searchRow').style.display='none';
            scRenderFolders();
            scToast('Folder "'+d.name+'" created ✓');
        });
    } else {
        _scFetch('sc_action=rename_folder&folder_id='+_scOptsId+'&name='+encodeURIComponent(name),function(d){
            if(d.status!=='ok'){scToast('Could not rename');return;}
            _scFolders.forEach(function(f){if(f.id==_scOptsId)f.name=name;});
            scCloseNameModal(); scRenderFolders(); scToast('Folder renamed ✓');
        });
    }
}

// ═══════════════════════════════════════════
//  TOAST
// ═══════════════════════════════════════════
function scToast(msg){
    var ex=document.querySelector('.sc-toast'); if(ex) ex.remove();
    var t=document.createElement('div'); t.className='sc-toast'; t.textContent=msg;
    document.body.appendChild(t);
    setTimeout(function(){t.style.transition='opacity 0.3s';t.style.opacity='0';setTimeout(function(){t.remove();},300);},2400);
}

// ═══════════════════════════════════════════
//  ESCAPE HELPER
// ═══════════════════════════════════════════
function _scEsc(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}

// ═══════════════════════════════════════════
//  FILE LABEL FEEDBACK
// ═══════════════════════════════════════════
(function(){
    var fi=document.querySelector('.u-file-label input[type="file"]');
    if(!fi) return;
    fi.addEventListener('change',function(){
        var lb=fi.closest('.u-file-label');
        if(lb) lb.innerHTML='<i class="ri-check-line"></i> '+(fi.files[0]?fi.files[0].name:'File selected')+'<input type="file" name="audio" accept="audio/*" required style="display:none">';
        var ni=lb.querySelector('input');
        if(ni) ni.addEventListener('change',arguments.callee);
    });
})();
</script>
</body>
</html>