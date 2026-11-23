<?php
/* "I understand" on a warning overlay. Only the warned user, or the owner of the warned pod, can acknowledge it. */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
include_once __DIR__ . '/config.php';
header('Content-Type: application/json');
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/dz_warnings.php';
$me = (int)($_SESSION['user_id'] ?? 0);
$id = (int)($_POST['id'] ?? 0);
if (!$me || !$id || $_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'msg'=>'Not allowed']); exit; }
dz_warn_ensure($conn);
$r = $conn->query("SELECT user_id, group_id FROM account_warnings WHERE id=$id AND acknowledged_at IS NULL LIMIT 1");
$w = ($r instanceof mysqli_result) ? $r->fetch_assoc() : null;
if (!$w) { echo json_encode(['ok'=>true]); exit; }   // already done
$allowed = false;
if ((int)$w['group_id'] > 0) {
    $g = $conn->query("SELECT created_by FROM `groups` WHERE id=" . (int)$w['group_id'] . " LIMIT 1");
    $gr = ($g instanceof mysqli_result) ? $g->fetch_assoc() : null;
    $allowed = $gr && (int)$gr['created_by'] === $me;
} else {
    $allowed = ((int)$w['user_id'] === $me);
}
if (!$allowed) { echo json_encode(['ok'=>false,'msg'=>'Not allowed']); exit; }
$conn->query("UPDATE account_warnings SET acknowledged_at=NOW() WHERE id=$id");
$next = dz_warn_pending($conn, (int)$w['user_id'], (int)$w['group_id']);
echo json_encode(['ok'=>true,'next'=>$next ? true : false]);