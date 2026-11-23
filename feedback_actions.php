<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$uid = (int)$_SESSION['user_id'];

$u = $conn->prepare("SELECT user_rank FROM users WHERE id = ?");
$u->bind_param("i", $uid);
$u->execute();
$me = $u->get_result()->fetch_assoc();
$u->close();

$is_admin = ($me['user_rank'] ?? '') === 'Admin';

$action = $_POST['action'] ?? '';

$statuses = ['submitted', 'under_review', 'planned', 'in_development', 'completed', 'declined'];
$status_labels = [
    'submitted'      => 'Submitted',
    'under_review'   => 'Under Review',
    'planned'        => 'Planned',
    'in_development' => 'In Development',
    'completed'      => 'Completed',
    'declined'       => 'Declined',
];

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

switch ($action) {

    // ── VOTE (any logged-in user, feature requests only) ──────
    case 'vote': {
        $fid = (int)($_POST['feedback_id'] ?? 0);
        if ($fid <= 0) fail('Missing feedback_id');

        $check = $conn->prepare("SELECT id, category FROM feedback WHERE id = ?");
        $check->bind_param("i", $fid);
        $check->execute();
        $fb = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$fb) fail('Feedback not found', 404);
        if ($fb['category'] !== 'feature') fail('Only feature requests can be voted on');

        $existing = $conn->prepare("SELECT id FROM feedback_votes WHERE feedback_id = ? AND user_id = ?");
        $existing->bind_param("ii", $fid, $uid);
        $existing->execute();
        $row = $existing->get_result()->fetch_assoc();
        $existing->close();

        if ($row) {
            $del = $conn->prepare("DELETE FROM feedback_votes WHERE id = ?");
            $del->bind_param("i", $row['id']);
            $del->execute();
            $del->close();
            $voted = false;
        } else {
            $ins = $conn->prepare("INSERT INTO feedback_votes (feedback_id, user_id, created_at) VALUES (?, ?, NOW())");
            $ins->bind_param("ii", $fid, $uid);
            $ins->execute();
            $ins->close();
            $voted = true;
        }

        $upd = $conn->prepare("UPDATE feedback SET vote_count = (SELECT COUNT(*) FROM feedback_votes WHERE feedback_id = ?) WHERE id = ?");
        $upd->bind_param("ii", $fid, $fid);
        $upd->execute();
        $upd->close();

        $cnt = $conn->prepare("SELECT vote_count FROM feedback WHERE id = ?");
        $cnt->bind_param("i", $fid);
        $cnt->execute();
        $vote_count = $cnt->get_result()->fetch_assoc()['vote_count'] ?? 0;
        $cnt->close();

        echo json_encode(['success' => true, 'voted' => $voted, 'vote_count' => (int)$vote_count]);
        break;
    }

    // ── EVERYTHING BELOW REQUIRES ADMIN ────────────────────────
    case 'update_status': {
        if (!$is_admin) fail('Admin only', 403);
        $fid = (int)($_POST['feedback_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';
        if ($fid <= 0 || !in_array($new_status, $statuses, true)) fail('Invalid request');

        $cur = $conn->prepare("SELECT user_id, title, status FROM feedback WHERE id = ?");
        $cur->bind_param("i", $fid);
        $cur->execute();
        $row = $cur->get_result()->fetch_assoc();
        $cur->close();
        if (!$row) fail('Feedback not found', 404);

        $old_status = $row['status'];

        $upd = $conn->prepare("UPDATE feedback SET status = ? WHERE id = ?");
        $upd->bind_param("si", $new_status, $fid);
        $upd->execute();
        $upd->close();

        if ($old_status !== $new_status) {
            $log = $conn->prepare("INSERT INTO feedback_status_log (feedback_id, old_status, new_status, changed_by, created_at) VALUES (?, ?, ?, ?, NOW())");
            $log->bind_param("issi", $fid, $old_status, $new_status, $uid);
            $log->execute();
            $log->close();

            $label = $status_labels[$new_status] ?? $new_status;
            $msg = 'Your feedback "' . $row['title'] . '" is now ' . $label;
            $notif = $conn->prepare("INSERT INTO notifications (user_id, actor_id, type, ref_id, message, is_read, created_at) VALUES (?, ?, 'feedback_status', ?, ?, 0, NOW())");
            $notif->bind_param("iiis", $row['user_id'], $uid, $fid, $msg);
            $notif->execute();
            $notif->close();
        }

        echo json_encode(['success' => true, 'status' => $new_status, 'status_label' => $status_labels[$new_status]]);
        break;
    }

    case 'save_notes': {
        if (!$is_admin) fail('Admin only', 403);
        $fid = (int)($_POST['feedback_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        if ($fid <= 0) fail('Invalid request');

        $upd = $conn->prepare("UPDATE feedback SET admin_notes = ? WHERE id = ?");
        $upd->bind_param("si", $notes, $fid);
        $upd->execute();
        $upd->close();

        echo json_encode(['success' => true]);
        break;
    }

    case 'toggle_pin': {
        if (!$is_admin) fail('Admin only', 403);
        $fid = (int)($_POST['feedback_id'] ?? 0);
        if ($fid <= 0) fail('Invalid request');

        $upd = $conn->prepare("UPDATE feedback SET is_pinned = NOT is_pinned WHERE id = ?");
        $upd->bind_param("i", $fid);
        $upd->execute();
        $upd->close();

        $get = $conn->prepare("SELECT is_pinned FROM feedback WHERE id = ?");
        $get->bind_param("i", $fid);
        $get->execute();
        $pinned = (bool)($get->get_result()->fetch_assoc()['is_pinned'] ?? 0);
        $get->close();

        echo json_encode(['success' => true, 'pinned' => $pinned]);
        break;
    }

    case 'mark_duplicate': {
        if (!$is_admin) fail('Admin only', 403);
        $fid = (int)($_POST['feedback_id'] ?? 0);
        $dup_of = $_POST['duplicate_of'] ?? '';
        $dup_of = ($dup_of === '' ? null : (int)$dup_of);
        if ($fid <= 0) fail('Invalid request');

        $upd = $conn->prepare("UPDATE feedback SET is_duplicate_of = ? WHERE id = ?");
        $upd->bind_param("ii", $dup_of, $fid);
        $upd->execute();
        $upd->close();

        echo json_encode(['success' => true, 'duplicate_of' => $dup_of]);
        break;
    }

    case 'toggle_archive': {
        if (!$is_admin) fail('Admin only', 403);
        $fid = (int)($_POST['feedback_id'] ?? 0);
        if ($fid <= 0) fail('Invalid request');

        $upd = $conn->prepare("UPDATE feedback SET is_archived = NOT is_archived WHERE id = ?");
        $upd->bind_param("i", $fid);
        $upd->execute();
        $upd->close();

        $get = $conn->prepare("SELECT is_archived FROM feedback WHERE id = ?");
        $get->bind_param("i", $fid);
        $get->execute();
        $archived = (bool)($get->get_result()->fetch_assoc()['is_archived'] ?? 0);
        $get->close();

        echo json_encode(['success' => true, 'archived' => $archived]);
        break;
    }

    default:
        fail('Unknown action');
}