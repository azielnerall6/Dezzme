<?php
session_start();
include 'config.php';

$current_user = (int)($_SESSION['user_id'] ?? 0);
$switch_to    = (int)($_GET['user_id'] ?? 0);

if ($switch_to && $current_user && $switch_to !== $current_user) {
    // Verify the switch is legitimate via DB, not just session
    $check = $conn->query("
        SELECT 1 FROM linked_accounts 
        WHERE primary_user_id = $current_user 
        AND linked_user_id = $switch_to
    ");
    if ($check && $check->num_rows > 0) {
        $_SESSION['user_id'] = $switch_to;

        // Rebuild accounts for the new active user
        $_SESSION['accounts'] = [$switch_to];
        $linked = $conn->query("SELECT linked_user_id FROM linked_accounts WHERE primary_user_id = $switch_to");
        while ($row = $linked->fetch_assoc()) {
            $_SESSION['accounts'][] = (int)$row['linked_user_id'];
        }
        $_SESSION['accounts'] = array_unique($_SESSION['accounts']);
    }
}

header("Location: profile.php");
exit;
?>