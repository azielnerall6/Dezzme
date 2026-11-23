<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

$current_user_id = $_SESSION['user_id'] ?? 0;
if (!$current_user_id) { echo json_encode(['success'=>false, 'message'=>'Not logged in']); exit; }

$action     = $_POST['action']     ?? '';
$profile_id = (int)($_POST['profile_id'] ?? 0);
$vibe       = trim($_POST['vibe']  ?? '');

if (!$profile_id) { echo json_encode(['success'=>false, 'message'=>'No profile id']); exit; }

function fetchVibeData($conn, $profile_id, $current_user_id) {
    $stmt = $conn->prepare("SELECT vibe, COUNT(*) as cnt FROM vibe_votes WHERE profile_id=? GROUP BY vibe ORDER BY cnt DESC LIMIT 1");
    $stmt->bind_param("i", $profile_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $top_vibe = $r['vibe'] ?? null;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM vibe_votes WHERE profile_id=?");
    $stmt->bind_param("i", $profile_id);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT vibe FROM vibe_votes WHERE voter_id=? AND profile_id=?");
    $stmt->bind_param("ii", $current_user_id, $profile_id);
    $stmt->execute();
    $my = $stmt->get_result()->fetch_assoc();
    $my_vote = $my['vibe'] ?? null;
    $stmt->close();

    return ['top_vibe'=>$top_vibe, 'total'=>$total, 'my_vote'=>$my_vote];
}

if ($action === 'fetch') {
    $data = fetchVibeData($conn, $profile_id, $current_user_id);
    echo json_encode(['success'=>true] + $data);
    exit;
}

if ($action === 'vote') {
    if (empty($vibe)) { echo json_encode(['success'=>false, 'message'=>'No vibe']); exit; }
    if ((int)$current_user_id === $profile_id) {
        echo json_encode(['success'=>false, 'message'=>'Cannot vibe check yourself']);
        exit;
    }
    $stmt = $conn->prepare("INSERT INTO vibe_votes (voter_id, profile_id, vibe) VALUES (?,?,?) ON DUPLICATE KEY UPDATE vibe=VALUES(vibe)");
    $stmt->bind_param("iis", $current_user_id, $profile_id, $vibe);
    $stmt->execute();
    $stmt->close();

    $data = fetchVibeData($conn, $profile_id, $current_user_id);
    echo json_encode(['success'=>true] + $data);
    exit;
}

echo json_encode(['success'=>false, 'message'=>'Invalid action']);