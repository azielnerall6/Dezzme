<?php
/* ============================================================
   report_content.php  — Dezzme Content Reporting AJAX Handler
   POST  reported_id  : content id (meme / comment / message)
   POST  content_type : meme | comment | message
   POST  reason       : report reason string
   POST  description  : optional free-text (Other)
   ============================================================ */

session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Not logged in']);
    exit;
}

include 'config.php';

$reporter_id  = (int)$_SESSION['user_id'];
$reported_id  = (int)($_POST['reported_id'] ?? 0);
$content_type = trim($_POST['content_type'] ?? '');
$reason       = trim($_POST['reason'] ?? '');
$description  = trim($_POST['description'] ?? '');

// ── Validate ──────────────────────────────────────────────────
$allowed_ct = ['meme', 'comment', 'message'];

if (!$reported_id || empty($reason)) {
    echo json_encode(['success' => false, 'msg' => 'Missing required fields']);
    exit;
}

if (!in_array($content_type, $allowed_ct, true)) {
    echo json_encode(['success' => false, 'msg' => 'Invalid content type']);
    exit;
}

if (mb_strlen($description) > 500) {
    $description = mb_substr($description, 0, 500);
}

// ── Sanitize ──────────────────────────────────────────────────
$reason       = $conn->real_escape_string($reason);
$description  = $conn->real_escape_string($description);
$content_type = $conn->real_escape_string($content_type);
$desc_val     = $description === '' ? 'NULL' : "'$description'";

try {

    // ── Resolve content owner (so admins know who posted it) ──
    $owner_id = 'NULL';
    if ($content_type === 'meme') {
        $ow = $conn->query("SELECT uploaded_by FROM memes WHERE id=$reported_id LIMIT 1");
        if ($ow && $ow->num_rows) $owner_id = (int)$ow->fetch_assoc()['uploaded_by'];
    } elseif ($content_type === 'message') {
        $ow = $conn->query("SELECT sender_id FROM messages WHERE id=$reported_id LIMIT 1");
        if ($ow && $ow->num_rows) $owner_id = (int)$ow->fetch_assoc()['sender_id'];
    } elseif ($content_type === 'comment') {
        $ow = $conn->query("SELECT user_id FROM comments WHERE id=$reported_id LIMIT 1");
        if ($ow && $ow->num_rows) $owner_id = (int)$ow->fetch_assoc()['user_id'];
    }

    // Bail if the content doesn't actually exist
    if ($owner_id === 'NULL') {
        echo json_encode(['success' => false, 'msg' => 'Content not found']);
        exit;
    }

    // ── Prevent duplicate active report for the same content ──
    $dup = $conn->query(
        "SELECT id FROM content_reports
         WHERE reporter_id=$reporter_id
           AND content_type='$content_type'
           AND content_id=$reported_id
           AND status='pending'
         LIMIT 1"
    );
    if ($dup && $dup->num_rows > 0) {
        echo json_encode(['success' => true, 'msg' => 'already_reported']);
        exit;
    }

    // ── Insert ──────────────────────────────────────────────
    $conn->query(
        "INSERT INTO content_reports
           (reporter_id, content_type, content_id, content_owner_id, reason, description)
         VALUES
           ($reporter_id, '$content_type', $reported_id, $owner_id, '$reason', $desc_val)"
    );

    if ($conn->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Database error: ' . $conn->error]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
}