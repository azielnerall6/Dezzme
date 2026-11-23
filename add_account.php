<?php
session_start();

// preserve existing accounts before going to register
// session stays alive so accounts array is kept
header("Location: register.php?add_account=1");
exit;
?>