<?php
session_start();
include 'config.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
$unlink_id    = (int)($_GET['user_id'] ?? 0);

if ($current_user && $unlink_id && $unlink_id !== $current_user) {
    // Remove both directions
    $conn->query("DELETE FROM linked_accounts WHERE primary_user_id=$current_user AND linked_user_id=$unlink_id");
    $conn->query("DELETE FROM linked_accounts WHERE primary_user_id=$unlink_id AND linked_user_id=$current_user");

    // Rebuild session accounts
    $_SESSION['accounts'] = [$current_user];
    $linked = $conn->query("SELECT linked_user_id FROM linked_accounts WHERE primary_user_id=$current_user");
    while ($row = $linked->fetch_assoc()) {
        $_SESSION['accounts'][] = (int)$row['linked_user_id'];
    }
    $_SESSION['accounts'] = array_unique($_SESSION['accounts']);
}

header("Location: profile.php");
exit;
?>