<?php
include 'config.php';

$playlist_id = isset($_GET['playlist_id']) ? intval($_GET['playlist_id']) : 0;

if ($playlist_id === 0) {
    die("Invalid Playlist ID");
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $type = $_POST['type']; // 'audio' or 'meme'
    
    // File Upload Logic
    $target_dir = "uploads/";
    $file_name = time() . "_" . basename($_FILES["file"]["name"]);
    $target_file = $target_dir . $file_name;

    if (move_uploaded_file($_FILES["file"]["tmp_name"], $target_file)) {
        
        // 1. Insert into playlist_items
        $insert_sql = "INSERT INTO playlist_items (playlist_id, title, type, file_path) 
                       VALUES ('$playlist_id', '$title', '$type', '$file_name')";
        
        if ($conn->query($insert_sql)) {
            // 2. SMART COVER LOGIC: 
            // If this is the first item, set it as the playlist cover
            $check_cover = "SELECT cover_path FROM playlists WHERE id = $playlist_id";
            $res = $conn->query($check_cover);
            $row = $res->fetch_assoc();

            if (empty($row['cover_path']) && $type == 'meme') {
                $update_cover = "UPDATE playlists SET cover_path = 'uploads/$file_name' WHERE id = $playlist_id";
                $conn->query($update_cover);
            }

            header("Location: playlists.php");
            exit();
        }
    } else {
        $message = "Upload failed.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Peura | Add to Vault</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css">
    <style>
        :root {
            --bg: #0b0b1a;
            --glass: rgba(255, 255, 255, 0.08);
            --neon-blue: #00d2ff;
        }
        body {
            background: var(--bg);
            color: #fff;
            font-family: 'Segoe UI', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .upload-card {
            background: var(--glass);
            backdrop-filter: blur(20px);
            padding: 30px;
            border-radius: 25px;
            border: 1px solid rgba(255,255,255,0.1);
            width: 100%;
            max-width: 400px;
        }
        input, select, button {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border-radius: 12px;
            border: none;
            outline: none;
        }
        input, select { background: rgba(0,0,0,0.4); color: #fff; border: 1px solid rgba(255,255,255,0.1); }
        .btn-up { background: var(--neon-blue); color: #000; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>

    <div class="upload-card">
        <h2 style="text-align:center; color:var(--neon-blue);">ADD TO VAULT</h2>
        <form action="" method="POST" enctype="multipart/form-data">
            <label style="font-size: 0.7rem; opacity: 0.6;">ITEM TITLE</label>
            <input type="text" name="title" placeholder="e.g. Scary Laugh Sound" required>
            
            <label style="font-size: 0.7rem; opacity: 0.6;">CONTENT TYPE</label>
            <select name="type">
                <option value="audio">Audio / Sound</option>
                <option value="meme">Meme / Visual</option>
            </select>

            <label style="font-size: 0.7rem; opacity: 0.6;">SELECT FILE</label>
            <input type="file" name="file" required>

            <button type="submit" class="btn-up">SECURE IN VAULT</button>
        </form>
        <a href="playlists.php" style="color:#555; text-decoration:none; display:block; text-align:center; margin-top:10px; font-size:0.8rem;">Go Back</a>
    </div>

</body>
</html>