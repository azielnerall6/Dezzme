<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

$user_id  = (int)($_SESSION['user_id'] ?? 0);
$group_id = (int)($_GET['group_id'] ?? 0);

if (!$user_id || !$group_id) {
    echo json_encode(['muted' => false, 'notifs_off' => false]);
    exit;
}

$muted_res = $conn->query("SELECT 1 FROM muted_groups WHERE user_id=$user_id AND group_id=$group_id");
$muted     = ($muted_res && $muted_res->num_rows > 0);

$notif_res  = $conn->query("SELECT 1 FROM group_notif_off WHERE user_id=$user_id AND group_id=$group_id");
$notifs_off = ($notif_res && $notif_res->num_rows > 0);

echo json_encode(['muted' => $muted, 'notifs_off' => $notifs_off]);