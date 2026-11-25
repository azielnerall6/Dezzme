<?php
/* ═══════════════════════════════════════════════════════════════════
   ADMIN — AD APPROVAL QUEUE  (admin_ads.php)

   SECURITY MODEL (all server-side — nothing here trusts the client):
     1. Must have a valid logged-in session (user_id).
     2. That user_id must have is_admin = 1 in the `users` table —
        checked fresh from the DB on every single request, never
        cached in the session and never trusted from a cookie/GET/POST.
     3. Every query is a prepared statement — no string concatenation
        of user input into SQL anywhere on this page.
     4. Approve/Reject are POST-only and require a per-session CSRF
        token, so a crafted link or another site can't trigger an
        approval on an admin's behalf.
     5. Ad IDs coming from the form are cast to (int) and re-validated
        against the database before anything is updated.
     6. All dynamic output is passed through htmlspecialchars().

   ── REQUIRED MIGRATION (run once) ──
     ALTER TABLE users     ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0;
     ALTER TABLE meme_ads  ADD COLUMN reviewed_by INT NULL;
     ALTER TABLE meme_ads  ADD COLUMN reviewed_at DATETIME NULL;

     -- then make yourself an admin:
     UPDATE users SET is_admin = 1 WHERE id = <your_user_id>;

   ── CHANGE LOG (this revision) ──
     Fixed: ad posts with multiple photos/videos (uploaded via upload.php's
     "extra_media[]" gallery) were only ever showing their FIRST (cover)
     item here, because this page's query only ever selected from `memes`
     and never joined `meme_media` — even though upload.php was already
     writing every extra item into meme_media for ad posts, same as a
     normal post. This revision adds a second query that pulls all
     meme_media rows for the ads on the current page, and renders them as
     a horizontal thumb strip on each ad card (cover first, "+N" overflow
     badge if there are more than 4 items).

     NEW (this revision): ad posts can have an attached soundtrack (saved
     into meme_audio/audios by upload.php, exactly like a normal post).
     This page never surfaced that at all — admins reviewing an ad had no
     way to know it had audio attached. Added a batched query (one query
     for every ad on the page, not one per ad) that joins meme_audio +
     audios for the ads currently listed, and renders an "Audio attached"
     badge with the track title/artist on any ad card that has one.
     Approving/rejecting still only ever touches meme_ads.status — this
     is read-only, informational, and doesn't change what gets approved.
═══════════════════════════════════════════════════════════════════ */

error_reporting(E_ALL);
ini_set('display_errors', 0); // never leak errors on an admin/security page
session_start();
include 'config.php';
if (is_file(__DIR__ . '/dz_audience.php')) require_once __DIR__ . '/dz_audience.php';

/* ── 1. Must be logged in ── */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = (int)$_SESSION['user_id'];

/* ── 2. Must be an admin — verified fresh from the DB every load ── */
$admin_stmt = $conn->prepare("SELECT id, username, is_admin FROM users WHERE id = ? LIMIT 1");
$admin_stmt->bind_param("i", $user_id);
$admin_stmt->execute();
$admin_row = $admin_stmt->get_result()->fetch_assoc();

if (!$admin_row || (int)$admin_row['is_admin'] !== 1) {
    http_response_code(403);
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Forbidden</title>
    <style>
        body{background:#070924;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;flex-direction:column;gap:10px}
        h1{color:#FF4C4C}
    </style></head><body>
    <h1>403 — Forbidden</h1>
    <p style='color:rgba(255,255,255,0.5)'>You don't have permission to view this page.</p>
    <a href='home.php' style='color:#FF4C4C'>Go home</a>
    </body></html>";
    exit();
}
$admin_name = $admin_row['username'] ?? 'Admin';

/* ── 3. CSRF token (per session) ── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$flash = "";
$flash_type = "success";

/* ── 4. Handle Approve / Reject (POST only, CSRF-checked) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $flash = "Security check failed. Please try again.";
        $flash_type = "error";
    } else {
        $action = $_POST['action'];
        $ad_id  = (int)($_POST['ad_id'] ?? 0);

        if (!in_array($action, ['approve', 'reject'], true) || $ad_id <= 0) {
            $flash = "Invalid request.";
            $flash_type = "error";
        } else {
            // Confirm this ad actually exists and is still pending before touching it
            $check = $conn->prepare("SELECT id FROM meme_ads WHERE id = ? AND status = 'pending' LIMIT 1");
            $check->bind_param("i", $ad_id);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();

            if (!$exists) {
                $flash = "That ad is no longer pending (already reviewed, or doesn't exist).";
                $flash_type = "error";
            } else {
                $new_status = $action === 'approve' ? 'approved' : 'rejected';
                $upd = $conn->prepare("UPDATE meme_ads SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                $upd->bind_param("sii", $new_status, $user_id, $ad_id);
                if ($upd->execute()) {
                    $flash = $action === 'approve' ? "Ad #$ad_id approved — it will now show in the feed." : "Ad #$ad_id rejected.";
                    $flash_type = "success";
                } else {
                    $flash = "Database error while updating the ad.";
                    $flash_type = "error";
                }
            }
        }
    }
}

/* ── Tab filter (whitelisted, never inserted raw into SQL) ── */
$valid_tabs = ['pending', 'approved', 'rejected'];
$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, $valid_tabs, true)) $tab = 'pending';

/* ── Counts for the tab badges ── */
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$cres = $conn->query("SELECT status, COUNT(*) c FROM meme_ads GROUP BY status");
if ($cres) while ($r = $cres->fetch_assoc()) {
    if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['c'];
}

/* ── Fetch ads for the current tab, newest first ── */
$list_stmt = $conn->prepare("
    SELECT
        ma.id AS ad_id, ma.cta_text, ma.cta_url, ma.cta_color,
        ma.duration_days, ma.price, ma.status, ma.reviewed_at,
        m.id AS meme_id, m.file_name, m.type AS media_type, m.caption, m.created_at,
        u.username, u.id AS uploader_id
    FROM meme_ads ma
    JOIN memes m ON m.id = ma.meme_id
    JOIN users u ON u.id = m.uploaded_by
    WHERE ma.status = ?
    ORDER BY m.created_at DESC
");
$list_stmt->bind_param("s", $tab);
$list_stmt->execute();
$ads = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Gallery items — pull every extra meme_media row for the ads on
   this page in ONE query (not one query per ad), keyed by meme_id.
   This is what upload.php already wrote for multi-item ad posts; this
   page just wasn't reading it before. Cover item (meme.file_name)
   stays first, these are items 2..20. ── */
$extra_by_meme = [];
if (!empty($ads)) {
    $meme_ids = array_values(array_unique(array_column($ads, 'meme_id')));
    $placeholders = implode(',', array_fill(0, count($meme_ids), '?'));
    $types = str_repeat('i', count($meme_ids));

    $mm_stmt = $conn->prepare("
        SELECT meme_id, file_name, type, position
        FROM meme_media
        WHERE meme_id IN ($placeholders)
        ORDER BY meme_id ASC, position ASC
    ");
    $mm_stmt->bind_param($types, ...$meme_ids);
    $mm_stmt->execute();
    $mm_res = $mm_stmt->get_result();
    while ($row = $mm_res->fetch_assoc()) {
        $extra_by_meme[$row['meme_id']][] = $row;
    }
}

/* ── Attached audio — pull every meme_audio + audios row for the ads
   on this page in ONE query (not one query per ad), keyed by meme_id.
   Ad posts can have a soundtrack attached exactly like a normal post
   (upload.php writes the same meme_audio row for both) — this page
   just never surfaced it. Purely informational: doesn't change what
   gets approved/rejected, just shows the admin what's attached. ── */
$audio_by_meme = [];
if (!empty($ads)) {
    $meme_ids = array_values(array_unique(array_column($ads, 'meme_id')));
    $placeholders = implode(',', array_fill(0, count($meme_ids), '?'));
    $types = str_repeat('i', count($meme_ids));

    $au_stmt = $conn->prepare("
        SELECT ma2.meme_id, a.id AS audio_id, a.title, a.artist, a.file_name, ma2.is_original
        FROM meme_audio ma2
        JOIN audios a ON a.id = ma2.audio_id
        WHERE ma2.meme_id IN ($placeholders)
    ");
    $au_stmt->bind_param($types, ...$meme_ids);
    $au_stmt->execute();
    $au_res = $au_stmt->get_result();
    while ($row = $au_res->fetch_assoc()) {
        // one attached track per meme — first match wins if somehow more than one row exists
        if (!isset($audio_by_meme[$row['meme_id']])) {
            $audio_by_meme[$row['meme_id']] = $row;
        }
    }
}

/* ── Audience targeting — set on upload.php ("Target your ad"). One batched
   load for every ad on this page, keyed by meme_id. Read-only: it only
   shows the reviewer who the advertiser aimed the ad at. ── */
$aud_by_meme = [];
if (!empty($ads) && function_exists('dz_aud_load_posts')) {
    try { $aud_by_meme = dz_aud_load_posts($conn, array_column($ads, 'meme_id')); } catch (Throwable $e) { $aud_by_meme = []; }
}

/* ── Info card — the picture, line and tappable questions the advertiser put above the button
   (ad_faq, saved by upload.php). One batched load for every ad on this page. Read-only: it shows
   the reviewer exactly what viewers will see and be able to tap before the ad goes live. ── */
$faq_by_meme = [];
if (!empty($ads)) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS ad_faq (meme_id INT NOT NULL PRIMARY KEY, info_pic VARCHAR(255) NULL, info_text VARCHAR(255) NULL, questions TEXT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4");
        $fids = array_values(array_unique(array_map('intval', array_column($ads, 'meme_id'))));
        $fph = implode(',', array_fill(0, count($fids), '?'));
        $fq = $conn->prepare("SELECT meme_id, info_pic, info_text, questions FROM ad_faq WHERE meme_id IN ($fph)");
        $fq->bind_param(str_repeat('i', count($fids)), ...$fids);
        $fq->execute();
        $fr = $fq->get_result();
        while ($row = $fr->fetch_assoc()) {
            $qs = json_decode((string)$row['questions'], true);
            $row['qs'] = is_array($qs) ? $qs : [];
            $faq_by_meme[(int)$row['meme_id']] = $row;
        }
    } catch (Throwable $e) { $faq_by_meme = []; }
}

/* Attach each ad's extra gallery items + audio info (null/empty if none) */
foreach ($ads as &$ad) {
    $ad['faq'] = $faq_by_meme[(int)$ad['meme_id']] ?? null;
    $ad['aud'] = $aud_by_meme[(int)$ad['meme_id']] ?? null;
    $ad['aud_reach'] = null;
    if ($ad['aud'] && function_exists('dz_aud_estimate')) {
        try { $est = dz_aud_estimate($conn, $ad['aud'], (int)$ad['uploader_id']); if (is_array($est) && !empty($est['known'])) $ad['aud_reach'] = (int)$est['count']; } catch (Throwable $e) {}
    }
    $ad['extra_media'] = $extra_by_meme[$ad['meme_id']] ?? [];
    $ad['audio']       = $audio_by_meme[$ad['meme_id']] ?? null;
}
unset($ad);

function thumb_url($file_name, $media_type) {
    $safe = htmlspecialchars($file_name);
    if ($media_type === 'text') return "uploads/Text.jpg";
    return "uploads/" . $safe;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ad Approvals | Dezzme Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
* { font-family: 'Poppins', sans-serif; }
button, a { cursor: pointer; }

:root {
    --red: #FF4C4C;
    --green: #4dde8a;
    --gold: #f5c451;
    --surface: rgba(255,255,255,0.05);
    --surface-2: rgba(255,255,255,0.08);
    --border: rgba(255,255,255,0.10);
    --text: #ffffff;
    --muted: rgba(255,255,255,0.5);
    --muted-2: rgba(255,255,255,0.3);
}

body {
    background: radial-gradient(circle at 20% 0%, #10123a 0%, #070924 45%, #010218 100%);
    background-attachment: fixed;
    color: var(--text);
    min-height: 100vh;
    padding: 32px 20px 80px;
}

.wrap { max-width: 980px; margin: 0 auto; }

/* Header */
.header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 26px;
}
.header-title { display: flex; align-items: center; gap: 12px; }
.header-title .icon-badge {
    width: 46px; height: 46px; border-radius: 14px;
    background: linear-gradient(135deg, var(--red), #b91c1c);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; box-shadow: 0 8px 24px rgba(255,76,76,0.35);
}
.header-title h1 { font-size: 1.4rem; font-weight: 700; }
.header-title p { font-size: 0.8rem; color: var(--muted); margin-top: 2px; }
.admin-chip {
    display: flex; align-items: center; gap: 8px;
    background: var(--surface); border: 1px solid var(--border);
    padding: 8px 14px; border-radius: 50px; font-size: 0.78rem; color: var(--muted);
}
.admin-chip strong { color: #fff; }
.admin-chip i { color: var(--gold); }

/* Flash message */
.flash {
    border-radius: 12px; padding: 12px 16px; margin-bottom: 20px;
    font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px;
}
.flash.success { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3); color: #86efac; }
.flash.error   { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }

/* Tabs */
.tabs { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
.tab-link {
    text-decoration: none;
    display: flex; align-items: center; gap: 8px;
    padding: 10px 18px; border-radius: 12px;
    background: var(--surface); border: 1px solid var(--border);
    color: var(--muted); font-size: 0.82rem; font-weight: 600;
    transition: 0.2s;
}
.tab-link:hover { border-color: rgba(255,76,76,0.4); color: #fff; }
.tab-link.active { background: var(--red); border-color: var(--red); color: #fff; }
.tab-count {
    background: rgba(0,0,0,0.25); padding: 1px 8px; border-radius: 20px; font-size: 0.72rem;
}
.tab-link.active .tab-count { background: rgba(0,0,0,0.3); }

/* Ad cards */
.ad-grid { display: flex; flex-direction: column; gap: 16px; }
.ad-card {
    display: grid;
    grid-template-columns: 130px 1fr;
    gap: 18px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 16px;
    transition: border-color 0.2s;
}
.ad-card:hover { border-color: rgba(255,255,255,0.18); }

/* ── Media column: cover thumb + (if multi-item) a small strip of the
   rest underneath, so admins can see the whole gallery before approving ── */
.ad-media-col { display: flex; flex-direction: column; gap: 8px; width: 130px; flex-shrink: 0; }
.ad-thumb {
    width: 130px; height: 130px; border-radius: 14px; overflow: hidden;
    background: #000; flex-shrink: 0; position: relative;
}
.ad-thumb img, .ad-thumb video { width: 100%; height: 100%; object-fit: cover; }
.ad-thumb .media-tag {
    position: absolute; bottom: 6px; left: 6px;
    background: rgba(0,0,0,0.65); font-size: 8.5px; font-weight: 700;
    padding: 3px 7px; border-radius: 6px; letter-spacing: 0.5px; text-transform: uppercase;
}
.ad-thumb .cover-tag {
    position: absolute; top: 6px; left: 6px;
    background: rgba(255,76,76,0.85); font-size: 8px; font-weight: 800;
    padding: 3px 7px; border-radius: 6px; letter-spacing: 0.5px; text-transform: uppercase;
}

.ad-gallery-strip { display: flex; gap: 6px; flex-wrap: wrap; }
.ad-gallery-thumb {
    width: 28px; height: 28px; border-radius: 7px; overflow: hidden;
    background: #000; position: relative; border: 1px solid var(--border);
}
.ad-gallery-thumb img, .ad-gallery-thumb video { width: 100%; height: 100%; object-fit: cover; }
.ad-gallery-thumb.is-video::after {
    content: "\ea1c"; /* remix video/play glyph fallback */
    font-family: 'remixicon';
    position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    font-size: 12px; color: #fff; background: rgba(0,0,0,0.25);
}
.ad-gallery-more {
    width: 28px; height: 28px; border-radius: 7px;
    background: rgba(0,0,0,0.4); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 9.5px; font-weight: 700; color: var(--muted);
}
.ad-gallery-count {
    font-size: 9.5px; color: var(--muted-2); display: flex; align-items: center; gap: 4px;
}
.ad-gallery-count i { color: var(--red); font-size: 11px; }

/* ── Attached audio badge — sits under the gallery strip (or right
   under the cover thumb if there's no gallery). Purely informational:
   shows the admin the post has a soundtrack, with its title/artist,
   and whether it's the poster's original audio or a library track. ── */
.ad-audio-badge {
    display: flex; align-items: center; gap: 6px;
    background: rgba(255,76,76,0.1); border: 1px solid rgba(255,76,76,0.28);
    border-radius: 8px; padding: 6px 9px;
    font-size: 9.5px; color: rgba(255,255,255,0.8);
}
.ad-audio-badge i { color: var(--red); font-size: 12px; flex-shrink: 0; }
.ad-audio-badge .ad-audio-title {
    font-weight: 700; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ad-audio-badge .ad-audio-sub { color: var(--muted-2); }

.ad-body { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.ad-top-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.ad-poster { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; }
.ad-poster i { color: var(--red); }
.ad-meta { font-size: 0.72rem; color: var(--muted-2); }

.price-tag {
    display: flex; align-items: center; gap: 6px;
    background: rgba(245,196,81,0.12); border: 1px solid rgba(245,196,81,0.3);
    color: var(--gold); font-weight: 700; font-size: 0.95rem;
    padding: 6px 14px; border-radius: 50px; white-space: nowrap;
}
.price-tag i { font-size: 1rem; }

.ad-caption {
    font-size: 0.82rem; color: rgba(255,255,255,0.75);
    line-height: 1.4;
    max-height: 3.4em; overflow: hidden; text-overflow: ellipsis;
}

.ad-details { display: flex; flex-wrap: wrap; gap: 8px; }
.detail-pill {
    display: flex; align-items: center; gap: 6px;
    background: var(--surface-2); border: 1px solid var(--border);
    padding: 5px 11px; border-radius: 8px; font-size: 0.72rem; color: var(--muted);
}
.detail-pill i { font-size: 0.85rem; color: var(--red); }
.detail-pill.cta-preview { color: #fff; font-weight: 600; }

.faq-box { border: 1px solid var(--border); background: var(--surface-2); border-radius: 12px; padding: 10px 12px; }
.faq-head { display: flex; align-items: center; gap: 7px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #fff; }
.faq-head i { color: var(--red); font-size: 0.95rem; }
.faq-row { display: flex; gap: 10px; align-items: center; margin-top: 9px; }
.faq-pic { width: 46px; height: 46px; border-radius: 6px; object-fit: cover; background: #000; flex-shrink: 0; }
.faq-text { font-size: 0.8rem; line-height: 1.4; color: #fff; min-width: 0; overflow-wrap: anywhere; }
.faq-qs { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 9px; }
.faq-q { background: rgba(255,255,255,0.12); border: 1px solid var(--border); border-radius: 8px; padding: 5px 11px; font-size: 0.74rem; font-weight: 600; color: #fff; }
.faq-note { margin-top: 6px; font-size: 0.72rem; color: var(--muted); line-height: 1.45; }

/* ── Audience panel ── */
.aud-box { border: 1px solid rgba(255,76,76,0.35); background: rgba(255,76,76,0.07); border-radius: 12px; padding: 10px 12px; }
.aud-box.aud-none { border-color: rgba(245,196,81,0.4); background: rgba(245,196,81,0.08); }
.aud-head { display: flex; align-items: center; gap: 7px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #fff; }
.aud-head i { color: var(--red); font-size: 0.95rem; }
.aud-none .aud-head i { color: var(--gold); }
.aud-reach { margin-left: auto; font-size: 0.7rem; font-weight: 600; text-transform: none; letter-spacing: 0; color: var(--muted); }
.aud-rows { display: flex; flex-direction: column; gap: 5px; margin-top: 8px; }
.aud-row { display: flex; gap: 8px; font-size: 0.76rem; line-height: 1.4; }
.aud-row b { flex: 0 0 82px; color: var(--muted); font-weight: 600; }
.aud-row span { color: #fff; min-width: 0; overflow-wrap: anywhere; }
.aud-note { margin-top: 6px; font-size: 0.72rem; color: var(--muted); line-height: 1.45; }

.ad-actions { display: flex; gap: 10px; margin-top: 4px; flex-wrap: wrap; }
.btn {
    border: none; padding: 10px 20px; border-radius: 10px;
    font-weight: 700; font-size: 0.78rem; font-family: 'Poppins', sans-serif;
    display: flex; align-items: center; gap: 6px; transition: 0.2s;
}
.btn-approve { background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff; }
.btn-approve:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(34,197,94,0.3); }
.btn-reject { background: var(--surface-2); border: 1px solid var(--border); color: rgba(255,255,255,0.75); }
.btn-reject:hover { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.4); color: #fca5a5; }
.btn-visit { background: var(--surface-2); border: 1px solid var(--border); color: var(--muted); text-decoration: none; }
.btn-visit:hover { border-color: rgba(255,255,255,0.3); color: #fff; }

.status-pill {
    font-size: 0.7rem; font-weight: 700; padding: 5px 12px; border-radius: 50px;
    display: inline-flex; align-items: center; gap: 5px; width: fit-content;
}
.status-pill.approved { background: rgba(34,197,94,0.14); color: #86efac; border: 1px solid rgba(34,197,94,0.3); }
.status-pill.rejected { background: rgba(239,68,68,0.14); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }

.empty-state {
    text-align: center; padding: 60px 20px; color: var(--muted-2);
    background: var(--surface); border: 1px dashed var(--border); border-radius: 18px;
}
.empty-state i { font-size: 36px; display: block; margin-bottom: 10px; color: rgba(255,76,76,0.25); }

@media (max-width: 600px) {
    .ad-card { grid-template-columns: 1fr; }
    .ad-media-col { width: 100%; }
    .ad-thumb { width: 100%; height: 180px; }
}
</style>
</head>
<body>
<div class="wrap">

    <div class="header">
        <div class="header-title">
            <div class="icon-badge"><i class="ri-shield-star-line"></i></div>
            <div>
                <h1>Ad Approval Queue</h1>
                <p>Review Elevate Brand submissions before they go live</p>
            </div>
        </div>
        <div class="admin-chip"><i class="ri-verified-badge-fill"></i> Signed in as <strong>&nbsp;<?= htmlspecialchars($admin_name) ?></strong></div>
    </div>

    <?php if ($flash): ?>
    <div class="flash <?= $flash_type ?>">
        <i class="<?= $flash_type === 'success' ? 'ri-checkbox-circle-line' : 'ri-error-warning-line' ?>"></i>
        <?= htmlspecialchars($flash) ?>
    </div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?tab=pending" class="tab-link <?= $tab === 'pending' ? 'active' : '' ?>">
            <i class="ri-time-line"></i> Pending <span class="tab-count"><?= $counts['pending'] ?></span>
        </a>
        <a href="?tab=approved" class="tab-link <?= $tab === 'approved' ? 'active' : '' ?>">
            <i class="ri-checkbox-circle-line"></i> Approved <span class="tab-count"><?= $counts['approved'] ?></span>
        </a>
        <a href="?tab=rejected" class="tab-link <?= $tab === 'rejected' ? 'active' : '' ?>">
            <i class="ri-close-circle-line"></i> Rejected <span class="tab-count"><?= $counts['rejected'] ?></span>
        </a>
    </div>

    <div class="ad-grid">
        <?php if (empty($ads)): ?>
            <div class="empty-state">
                <i class="ri-inbox-line"></i>
                Nothing here right now.
            </div>
        <?php else: foreach ($ads as $ad):
            $extra = $ad['extra_media']; // items 2..20, position ASC (empty for single-item posts)
            $total_items = 1 + count($extra);
            $strip_limit = 4; // how many extra thumbs to show before "+N"
            $audio = $ad['audio']; // null if this ad has no attached track
        ?>
            <div class="ad-card">
                <div class="ad-media-col">
                    <div class="ad-thumb">
                        <?php if ($ad['media_type'] === 'video'): ?>
                            <video src="<?= htmlspecialchars(thumb_url($ad['file_name'], $ad['media_type'])) ?>" muted></video>
                        <?php else: ?>
                            <img src="<?= htmlspecialchars(thumb_url($ad['file_name'], $ad['media_type'])) ?>" alt="ad media">
                        <?php endif; ?>
                        <span class="media-tag"><?= htmlspecialchars(strtoupper($ad['media_type'])) ?></span>
                        <?php if ($total_items > 1): ?>
                            <span class="cover-tag">Cover</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($total_items > 1): ?>
                    <div class="ad-gallery-count">
                        <i class="ri-stack-line"></i> <?= (int)$total_items ?> items in this post
                    </div>
                    <div class="ad-gallery-strip">
                        <?php
                        $shown = array_slice($extra, 0, $strip_limit);
                        $remaining = count($extra) - count($shown);
                        foreach ($shown as $item):
                        ?>
                            <div class="ad-gallery-thumb<?= $item['type'] === 'video' ? ' is-video' : '' ?>">
                                <?php if ($item['type'] === 'video'): ?>
                                    <video src="<?= htmlspecialchars(thumb_url($item['file_name'], $item['type'])) ?>" muted></video>
                                <?php else: ?>
                                    <img src="<?= htmlspecialchars(thumb_url($item['file_name'], $item['type'])) ?>" alt="gallery item">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($remaining > 0): ?>
                            <div class="ad-gallery-more">+<?= (int)$remaining ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($audio): ?>
                    <div class="ad-audio-badge">
                        <i class="ri-music-2-fill"></i>
                        <div style="min-width:0;overflow:hidden;">
                            <?php if (!empty($audio['is_original'])): ?>
                                <div class="ad-audio-title">Original audio</div>
                            <?php else: ?>
                                <div class="ad-audio-title"><?= htmlspecialchars($audio['title']) ?></div>
                                <?php if (!empty($audio['artist'])): ?>
                                    <div class="ad-audio-sub"><?= htmlspecialchars($audio['artist']) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="ad-body">
                    <div class="ad-top-row">
                        <div>
                            <div class="ad-poster"><i class="ri-user-3-fill"></i> <?= htmlspecialchars($ad['username']) ?></div>
                            <div class="ad-meta">Posted <?= htmlspecialchars(date('M j, Y · g:i A', strtotime($ad['created_at']))) ?></div>
                        </div>
                        <div class="price-tag"><i class="ri-price-tag-3-fill"></i> $<?= number_format((float)$ad['price'], 2) ?></div>
                    </div>

                    <?php if (!empty($ad['caption'])): ?>
                    <div class="ad-caption"><?= htmlspecialchars($ad['caption']) ?></div>
                    <?php endif; ?>

                    <?php $faq = $ad['faq']; ?>
                    <div class="faq-box">
                        <div class="faq-head"><i class="ri-question-answer-line"></i> Info card</div>
                        <?php if ($faq && ($faq['info_pic'] !== '' || $faq['info_text'] !== '' || $faq['qs'])): ?>
                            <?php if (!empty($faq['info_pic']) || !empty($faq['info_text'])): ?>
                            <div class="faq-row">
                                <?php if (!empty($faq['info_pic'])): ?><img class="faq-pic" src="<?= htmlspecialchars($faq['info_pic']) ?>" alt="info card picture"><?php endif; ?>
                                <?php if (!empty($faq['info_text'])): ?><div class="faq-text"><?= htmlspecialchars($faq['info_text']) ?></div><?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($faq['qs']): ?>
                            <div class="faq-qs"><?php foreach ($faq['qs'] as $fqq): ?><span class="faq-q"><?= htmlspecialchars((string)$fqq) ?></span><?php endforeach; ?></div>
                            <div class="faq-note">Viewers can tap these. Each one is sent to the advertiser's messages as a question.</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="faq-note">No info card. The ad shows just its button.</div>
                        <?php endif; ?>
                    </div>

                    <?php $aud = $ad['aud']; ?>
                    <?php if ($aud): ?>
                    <div class="aud-box">
                        <div class="aud-head"><i class="ri-focus-3-line"></i> Target audience
                            <?php if ($ad['aud_reach'] !== null): ?><span class="aud-reach">≈ <?= number_format($ad['aud_reach']) ?> people match</span><?php endif; ?>
                        </div>
                        <div class="aud-rows">
                            <?php if ($aud['age_min'] !== null || $aud['age_max'] !== null): ?>
                            <div class="aud-row"><b>Age</b><span><?php
                                if ($aud['age_min'] !== null && $aud['age_max'] !== null) echo (int)$aud['age_min'] . '–' . (int)$aud['age_max'];
                                elseif ($aud['age_min'] !== null) echo (int)$aud['age_min'] . '+';
                                else echo 'Up to ' . (int)$aud['age_max'];
                            ?></span></div>
                            <?php endif; ?>
                            <?php foreach (['locations' => 'Location', 'interests' => 'Interests', 'occupations' => 'Occupation'] as $k => $lbl): if (!empty($aud[$k])): ?>
                            <div class="aud-row"><b><?= $lbl ?></b><span><?= htmlspecialchars(implode(', ', $aud[$k])) ?></span></div>
                            <?php endif; endforeach; ?>
                        </div>
                        <div class="aud-note">Everyone can still see this ad. People who match are shown it much more often.</div>
                    </div>
                    <?php else: ?>
                    <div class="aud-box aud-none">
                        <div class="aud-head"><i class="ri-group-line"></i> Target audience</div>
                        <div class="aud-note">No targeting set. This ad is shown evenly to everyone.</div>
                    </div>
                    <?php endif; ?>

                    <div class="ad-details">
                        <div class="detail-pill"><i class="ri-calendar-line"></i> <?= (int)$ad['duration_days'] ?> day boost</div>
                        <div class="detail-pill">
                            <i class="ri-link"></i>
                            <a href="<?= htmlspecialchars($ad['cta_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">
                                <?= htmlspecialchars(parse_url($ad['cta_url'], PHP_URL_HOST) ?: $ad['cta_url']) ?>
                            </a>
                        </div>
                        <div class="detail-pill cta-preview" style="background:<?= htmlspecialchars($ad['cta_color']) ?>;border-color:<?= htmlspecialchars($ad['cta_color']) ?>;">
                            <i class="ri-cursor-line"></i> <?= htmlspecialchars($ad['cta_text']) ?>
                        </div>
                        <?php if ($audio): ?>
                        <div class="detail-pill">
                            <i class="ri-music-2-fill"></i> Has audio
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($tab === 'pending'): ?>
                    <div class="ad-actions">
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this ad? It will start showing in the feed immediately.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="ad_id" value="<?= (int)$ad['ad_id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-approve"><i class="ri-check-line"></i> Approve</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Reject this ad? The poster will not be charged/shown.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="ad_id" value="<?= (int)$ad['ad_id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn btn-reject"><i class="ri-close-line"></i> Reject</button>
                        </form>
                    </div>
                    <?php else: ?>
                        <span class="status-pill <?= htmlspecialchars($ad['status']) ?>">
                            <i class="<?= $ad['status'] === 'approved' ? 'ri-checkbox-circle-fill' : 'ri-close-circle-fill' ?>"></i>
                            <?= ucfirst(htmlspecialchars($ad['status'])) ?>
                            <?php if (!empty($ad['reviewed_at'])): ?>
                                · <?= htmlspecialchars(date('M j, g:i A', strtotime($ad['reviewed_at']))) ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

</div>
</body>
</html>