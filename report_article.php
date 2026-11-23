<?php
/* ============================================================
   report_article.php — a user reports a Memepedia entry.
   Called by memepedia.php (POST: article_id, reason, description).
   Reports land in `article_reports` and show up in reports_center.php → "Articles".
   ============================================================ */
session_start();
include 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

/* ── Table that holds the Memepedia entries ── */
const RA_ARTICLES_TABLE = 'meme_articles';

/* must be identical to MPR_REASONS in memepedia.php */
$RA_REASONS = [
    'Inaccurate or Wrong Information', 'Misinformation or Fake Info', 'Made-up or Fake Meme',
    'Vandalism or Spam', 'Hate Speech or Discrimination', 'Harassment or Personal Attacks',
    'Personal Information (Doxxing)', 'Graphic or Sexual Content', 'Copyright Infringement',
    'Promotion or Advertising', 'Duplicate Entry', 'Illegal or Dangerous Content',
    'Child Safety Concern', 'Other'
];
const RA_MAX_PER_DAY = 20;   // reports one person can send in 24 hours

function ra_out($ok, $msg = '', $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => $ok, 'msg' => $msg]);
    exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) ra_out(false, 'Please log in again.', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ra_out(false, 'Invalid request.', 405);

$aid    = (int)($_POST['article_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$desc   = trim(strip_tags((string)($_POST['description'] ?? '')));
$desc   = function_exists('mb_substr') ? mb_substr($desc, 0, 500) : substr($desc, 0, 500);

if ($aid <= 0)                              ra_out(false, 'Entry not found.');
if (!in_array($reason, $RA_REASONS, true))  ra_out(false, 'Please select a reason.');
if ($reason === 'Other' && $desc === '')    ra_out(false, 'Please describe the issue.');

try {
    /* the entry must exist */
    $st = $conn->prepare("SELECT id, title, creator_id FROM `" . RA_ARTICLES_TABLE . "` WHERE id=? LIMIT 1");
    $st->bind_param('i', $aid);
    $st->execute();
    $art = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$art) ra_out(false, 'This entry no longer exists.');

    $owner = (int)($art['creator_id'] ?? 0);
    if ($owner === $uid) ra_out(false, "You can't report your own entry.");

    /* already reported by this person? */
    $st = $conn->prepare("SELECT id FROM article_reports WHERE article_id=? AND reporter_id=? LIMIT 1");
    $st->bind_param('ii', $aid, $uid);
    $st->execute();
    $dup = $st->get_result()->num_rows > 0;
    $st->close();
    if ($dup) ra_out(false, 'already_reported');

    /* daily limit */
    $st = $conn->prepare("SELECT COUNT(*) c FROM article_reports WHERE reporter_id=? AND created_at > (NOW() - INTERVAL 1 DAY)");
    $st->bind_param('i', $uid);
    $st->execute();
    $cnt = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    $st->close();
    if ($cnt >= RA_MAX_PER_DAY) ra_out(false, 'You have sent too many reports today. Try again tomorrow.', 429);

    /* save it */
    $title = (string)($art['title'] ?? '');
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 255) : substr($title, 0, 255);
    $st = $conn->prepare("INSERT INTO article_reports (article_id, article_title, article_owner_id, reporter_id, reason, description) VALUES (?,?,?,?,?,?)");
    $st->bind_param('isiiss', $aid, $title, $owner, $uid, $reason, $desc);
    $ok = $st->execute();
    $st->close();

    ra_out((bool)$ok, $ok ? '' : 'Could not save your report. Please try again.');
} catch (Throwable $e) {
    error_log('report_article.php: ' . $e->getMessage());
    ra_out(false, 'Something went wrong. Please try again.', 500);
}