<?php
/* ================================================================
   mute_restrict_api.php
   Handles: mute, unmute, restrict, unrestrict + status checks
   Include this file at the top of profile.php (before HTML output)
   ================================================================ */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// include 'config.php'; // already included in profile.php

// ── Ensure tables exist ──────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS `muted_users` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `muter_id` int(10) UNSIGNED NOT NULL,
        `muted_id` int(10) UNSIGNED NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_mute` (`muter_id`,`muted_id`),
        KEY `fk_muted_id` (`muted_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS `restricted_users` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `restrictor_id` int(10) UNSIGNED NOT NULL,
        `restricted_id` int(10) UNSIGNED NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_restrict` (`restrictor_id`,`restricted_id`),
        KEY `fk_restricted_id` (`restricted_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// ── Handle AJAX actions ──────────────────────────────────────────
if (isset($_POST['mr_action']) && !empty($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    $current_uid = (int)$_SESSION['user_id'];
    $target_uid  = (int)($_POST['target_id'] ?? 0);
    $mr_action   = $_POST['mr_action'];

    if (!$target_uid || $target_uid === $current_uid) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid target']);
        exit;
    }

    if ($mr_action === 'mute') {
        $stmt = $conn->prepare("INSERT IGNORE INTO muted_users (muter_id, muted_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $current_uid, $target_uid);
        $stmt->execute();
        echo json_encode(['status' => 'ok', 'muted' => true]);
        exit;
    }

    if ($mr_action === 'unmute') {
        $stmt = $conn->prepare("DELETE FROM muted_users WHERE muter_id=? AND muted_id=?");
        $stmt->bind_param("ii", $current_uid, $target_uid);
        $stmt->execute();
        echo json_encode(['status' => 'ok', 'muted' => false]);
        exit;
    }

    if ($mr_action === 'restrict') {
        $stmt = $conn->prepare("INSERT IGNORE INTO restricted_users (restrictor_id, restricted_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $current_uid, $target_uid);
        $stmt->execute();
        echo json_encode(['status' => 'ok', 'restricted' => true]);
        exit;
    }

    if ($mr_action === 'unrestrict') {
        $stmt = $conn->prepare("DELETE FROM restricted_users WHERE restrictor_id=? AND restricted_id=?");
        $stmt->bind_param("ii", $current_uid, $target_uid);
        $stmt->execute();
        echo json_encode(['status' => 'ok', 'restricted' => false]);
        exit;
    }

    if ($mr_action === 'get_status') {
        $is_muted = false;
        $is_restricted = false;

        $s1 = $conn->prepare("SELECT id FROM muted_users WHERE muter_id=? AND muted_id=?");
        $s1->bind_param("ii", $current_uid, $target_uid);
        $s1->execute();
        $is_muted = $s1->get_result()->num_rows > 0;

        $s2 = $conn->prepare("SELECT id FROM restricted_users WHERE restrictor_id=? AND restricted_id=?");
        $s2->bind_param("ii", $current_uid, $target_uid);
        $s2->execute();
        $is_restricted = $s2->get_result()->num_rows > 0;

        echo json_encode(['status' => 'ok', 'muted' => $is_muted, 'restricted' => $is_restricted]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

// ── Helper: is user muted by current viewer? ─────────────────────
function is_muted_by_viewer($conn, $muter_id, $muted_id) {
    if (!$muter_id || !$muted_id) return false;
    $stmt = $conn->prepare("SELECT id FROM muted_users WHERE muter_id=? AND muted_id=?");
    $stmt->bind_param("ii", $muter_id, $muted_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// ── Helper: is user restricted by current viewer? ────────────────
function is_restricted_by_viewer($conn, $restrictor_id, $restricted_id) {
    if (!$restrictor_id || !$restricted_id) return false;
    $stmt = $conn->prepare("SELECT id FROM restricted_users WHERE restrictor_id=? AND restricted_id=?");
    $stmt->bind_param("ii", $restrictor_id, $restricted_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}