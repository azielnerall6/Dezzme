<?php
/* ============================================================
   report_user.php  — Dezzme Reporting AJAX Handler
   POST  type         : user | content | pod
   POST  reported_id  : target user / content / group id
   POST  reason       : report reason string
   POST  description  : optional free-text (Other)
   POST  content_type : meme | comment | message  (for content)
   POST  user_id_hint : optional User ID string entered by reporter
   ============================================================ */

session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Not logged in']);
    exit;
}

include 'config.php';

$reporter_id  = (int)$_SESSION['user_id'];
$type         = $_POST['type']         ?? 'user';
$reported_id  = (int)($_POST['reported_id'] ?? 0);
$reason       = trim($_POST['reason']  ?? '');
$description  = trim($_POST['description'] ?? '');
$content_type = trim($_POST['content_type'] ?? '');
$user_id_hint = trim($_POST['user_id_hint'] ?? '');   // ← NEW: optional User ID from modal

// ── Validate ──────────────────────────────────────────────────
if (!$reported_id || empty($reason)) {
    echo json_encode(['success' => false, 'msg' => 'Missing required fields']);
    exit;
}

if (mb_strlen($description) > 500) {
    $description = mb_substr($description, 0, 500);
}

// ── Sanitize ──────────────────────────────────────────────────
$reason       = $conn->real_escape_string($reason);
$description  = $conn->real_escape_string($description);
$user_id_hint = $conn->real_escape_string(mb_substr($user_id_hint, 0, 30)); // max 30 chars
$desc_val     = empty($description) ? 'NULL' : "'$description'";

// ── Save ──────────────────────────────────────────────────────
try {
    if ($type === 'user') {

        // Grab username from DB
        $urow  = $conn->query("SELECT username FROM users WHERE id=$reported_id LIMIT 1");
        $uname = $urow ? ($urow->fetch_assoc()['username'] ?? '') : '';
        $uname = $conn->real_escape_string($uname);

        // If reporter typed a User ID hint, try to resolve it to a real user
        // and override reported_id if it differs (optional — remove if unwanted)
        if (!empty($user_id_hint)) {
            // Try matching dz_id column (e.g. "DZ-10E34AFAD")
            $hint_row = $conn->query(
                "SELECT id, username FROM users
                 WHERE dz_id = '$user_id_hint'
                    OR id    = " . ((int)$user_id_hint) . "
                 LIMIT 1"
            );
            if ($hint_row && $hint_row->num_rows > 0) {
                $hint_data  = $hint_row->fetch_assoc();
                $reported_id = (int)$hint_data['id'];
                $uname       = $conn->real_escape_string($hint_data['username']);
            }
            // If no match found we still use the original reported_id —
            // the hint is stored in description as extra context (see below)
        }

        // Prevent duplicate active report for the same user
        $dup = $conn->query(
            "SELECT id FROM user_reports
             WHERE reporter_id=$reporter_id AND reported_user_id=$reported_id
               AND status='pending' LIMIT 1"
        );
        if ($dup && $dup->num_rows > 0) {
            echo json_encode(['success' => true, 'msg' => 'already_reported']);
            exit;
        }

        // Append the typed User ID hint to description so admins can see it
        if (!empty($user_id_hint)) {
            $hint_note   = 'Reporter provided User ID: ' . $user_id_hint;
            $description = $description
                ? $hint_note . ' | ' . $description
                : $hint_note;
            $description = $conn->real_escape_string(mb_substr($description, 0, 530));
            $desc_val    = "'$description'";
        }

        $conn->query(
            "INSERT INTO user_reports
               (reporter_id, reported_user_id, reported_username, reason, description)
             VALUES
               ($reporter_id, $reported_id, '$uname', '$reason', $desc_val)"
        );

    } elseif ($type === 'content') {

        $allowed_ct = ['meme', 'comment', 'message'];
        if (!in_array($content_type, $allowed_ct)) {
            echo json_encode(['success' => false, 'msg' => 'Invalid content type']);
            exit;
        }
        $content_type = $conn->real_escape_string($content_type);

        // Try to find content owner
        $owner_id = 'NULL';
        if ($content_type === 'meme') {
            $ow = $conn->query("SELECT uploaded_by FROM memes WHERE id=$reported_id LIMIT 1");
            if ($ow && $ow->num_rows) $owner_id = (int)$ow->fetch_assoc()['uploaded_by'];
        } elseif ($content_type === 'message') {
            $ow = $conn->query("SELECT sender_id FROM messages WHERE id=$reported_id LIMIT 1");
            if ($ow && $ow->num_rows) $owner_id = (int)$ow->fetch_assoc()['sender_id'];
        }

        $conn->query(
            "INSERT INTO content_reports
               (reporter_id, content_type, content_id, content_owner_id, reason, description)
             VALUES
               ($reporter_id, '$content_type', $reported_id, $owner_id, '$reason', $desc_val)"
        );

    } elseif ($type === 'pod') {

        $conn->query(
            "INSERT INTO pod_reports
               (reporter_id, group_id, reason, description)
             VALUES
               ($reporter_id, $reported_id, '$reason', $desc_val)"
        );

    } else {
        echo json_encode(['success' => false, 'msg' => 'Unknown report type']);
        exit;
    }

    if ($conn->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Database error: ' . $conn->error]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
}