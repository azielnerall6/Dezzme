<?php
include 'config.php';

if(isset($_GET['file'])){
    $file = basename($_GET['file']); // sanitize input
    $filepath = "uploads/" . $file;

    if(file_exists($filepath)){
        // Force download headers
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($filepath).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        flush();
        readfile($filepath);
        exit;
    } else {
        die("File not found.");
    }
} else {
    die("No file specified.");
}
?>