<?php
/* ============================================================
   report_group.php — Dezzme Pod Reporting AJAX Handler
   POST  group_id     : the Pod being reported
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

$reporter_id = (int)$_SESSION['user_id'];
$group_id    = (int)($_POST['group_id'] ?? 0);
$reason      = trim($_POST['reason'] ?? '');
$description = trim($_POST['description'] ?? '');

// ── Validate ──────────────────────────────────────────────────
if (!$group_id || empty($reason)) {
    echo json_encode(['success' => false, 'msg' => 'Missing required fields']);
    exit;
}

// Make sure the group actually exists
$grp_chk = $conn->query("SELECT id FROM groups WHERE id=$group_id LIMIT 1");
if (!$grp_chk || $grp_chk->num_rows === 0) {
    echo json_encode(['success' => false, 'msg' => 'Pod not found']);
    exit;
}

if (mb_strlen($description) > 500) {
    $description = mb_substr($description, 0, 500);
}

// ── Sanitize ──────────────────────────────────────────────────
$reason      = $conn->real_escape_string($reason);
$description = $conn->real_escape_string($description);
$desc_val    = empty($description) ? 'NULL' : "'$description'";

// ── Prevent duplicate active report ──────────────────────────
$dup = $conn->query(
    "SELECT id FROM pod_reports
     WHERE reporter_id=$reporter_id AND group_id=$group_id
       AND status='pending' LIMIT 1"
);
if ($dup && $dup->num_rows > 0) {
    echo json_encode(['success' => true, 'msg' => 'already_reported']);
    exit;
}

// ── Save ──────────────────────────────────────────────────────
try {
    $conn->query(
        "INSERT INTO pod_reports
           (reporter_id, group_id, reason, description)
         VALUES
           ($reporter_id, $group_id, '$reason', $desc_val)"
    );

    if ($conn->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Database error: ' . $conn->error]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
}