<?php
/* report_media.php — reports for Highlights, Pod Highlights and Longform videos.
   (report_content.php only knows posts / comments / messages; these three live in their own tables.)
   POST: reported_id, content_type = highlight | group_highlight | longform, reason, description (optional)
   Saves into the same content_reports table, so they show up in the Reports Center "Content" section. */
session_start();
include 'config.php';
header('Content-Type: application/json; charset=utf-8');
function rm_out($a){ echo json_encode($a); exit; }

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) rm_out(['success' => false, 'msg' => 'Please log in again.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rm_out(['success' => false, 'msg' => 'Bad request.']);

$type   = (string)($_POST['content_type'] ?? '');
$cid    = (int)($_POST['reported_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$desc   = trim((string)($_POST['description'] ?? ''));
if (!in_array($type, ['highlight', 'group_highlight', 'longform'], true)) rm_out(['success' => false, 'msg' => 'Invalid content type']);
if ($cid <= 0 || $reason === '') rm_out(['success' => false, 'msg' => 'Missing details']);
$reason = function_exists('mb_substr') ? mb_substr($reason, 0, 100) : substr($reason, 0, 100);
$desc   = function_exists('mb_substr') ? mb_substr(strip_tags($desc), 0, 500) : substr(strip_tags($desc), 0, 500);

try {
    /* who owns it (this also proves it exists) */
    $owner = 0; $found = false;
    $sql = ['highlight'       => "SELECT user_id    AS o FROM highlights       WHERE id=? LIMIT 1",
            'group_highlight' => "SELECT created_by AS o FROM group_highlights WHERE id=? LIMIT 1",
            'longform'        => "SELECT user_id    AS o FROM longform_posts   WHERE id=? LIMIT 1"][$type];
    $st = $conn->prepare($sql); $st->bind_param("i", $cid); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if ($row) { $found = true; $owner = (int)$row['o']; }
    if (!$found) rm_out(['success' => false, 'msg' => 'Content not found']);
    if ($owner === $uid) rm_out(['success' => false, 'msg' => 'You cannot report your own content']);

    /* the table must be able to hold these types (an old ENUM('meme','comment','message') would refuse them) */
    $c = $conn->query("SHOW COLUMNS FROM content_reports LIKE 'content_type'");
    if ($c instanceof mysqli_result && ($cr = $c->fetch_assoc()) && stripos((string)$cr['Type'], 'enum') === 0) {
        $conn->query("ALTER TABLE content_reports MODIFY content_type VARCHAR(30) NOT NULL DEFAULT 'meme'");
    }

    /* one open report per person per item */
    $st = $conn->prepare("SELECT 1 FROM content_reports WHERE reporter_id=? AND content_type=? AND content_id=? AND status IN ('pending','under_review') LIMIT 1");
    $st->bind_param("isi", $uid, $type, $cid); $st->execute();
    $dup = $st->get_result()->num_rows > 0; $st->close();
    if ($dup) rm_out(['success' => false, 'msg' => 'already_reported']);

    $st = $conn->prepare("INSERT INTO content_reports (reporter_id, content_type, content_id, content_owner_id, reason, description, status) VALUES (?,?,?,?,?,?, 'pending')");
    $st->bind_param("isiiss", $uid, $type, $cid, $owner, $reason, $desc);
    $st->execute(); $st->close();
    rm_out(['success' => true]);
} catch (Throwable $e) {
    rm_out(['success' => false, 'msg' => 'Could not save the report']);
}