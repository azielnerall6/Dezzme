<?php
include 'config.php';

// In a real app, this would be $_SESSION['user_id']
$current_user_id = 1; 

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = mysqli_real_escape_with_str($conn, $_POST['name']);
    $description = mysqli_real_escape_with_str($conn, $_POST['description']);
    $privacy = $_POST['privacy']; // 'public' or 'private'

    // Insert the new playlist
    $sql = "INSERT INTO playlists (user_id, name, description, privacy) 
            VALUES ('$current_user_id', '$name', '$description', '$privacy')";

    if ($conn->query($sql)) {
        // Redirect back to the main playlists page after success
        header("Location: playlists.php?user_id=" . $current_user_id);
        exit();
    } else {
        $message = "Error: " . $conn->error;
    }
}

// Helper function to sanitize input
function mysqli_real_escape_with_str($conn, $data) {
    return mysqli_real_escape_string($conn, htmlspecialchars($data));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Peura | Create New Vault</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css">
    <style>
        :root {
            --bg: #0b0b1a;
            --glass: rgba(255, 255, 255, 0.07);
            --neon-blue: #00d2ff;
            --neon-purple: #9d50bb;
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
            background-image: radial-gradient(circle at center, #1a1a3a 0%, #0b0b1a 100%);
        }

        .form-card {
            background: var(--glass);
            backdrop-filter: blur(20px);
            padding: 40px;
            border-radius: 30px;
            border: 1px solid rgba(255,255,255,0.1);
            width: 100%;
            max-width: 450px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        }

        h2 {
            margin-top: 0;
            text-align: center;
            letter-spacing: 2px;
            background: linear-gradient(to right, var(--neon-blue), var(--neon-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .input-group { margin-bottom: 20px; }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.8rem;
            color: var(--neon-blue);
            text-transform: uppercase;
            font-weight: bold;
        }

        input, textarea, select {
            width: 100%;
            padding: 12px;
            background: rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            color: #fff;
            outline: none;
            transition: 0.3s;
        }

        input:focus, textarea:focus {
            border-color: var(--neon-blue);
            box-shadow: 0 0 10px rgba(0, 210, 255, 0.2);
        }

        .btn-submit {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 15px;
            background: linear-gradient(to right, var(--neon-blue), var(--neon-purple));
            color: white;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
            margin-top: 10px;
        }

        .btn-submit:hover {
            transform: scale(1.02);
            box-shadow: 0 0 20px rgba(157, 80, 187, 0.4);
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #555;
            text-decoration: none;
            font-size: 0.8rem;
        }

        .back-link:hover { color: #fff; }
    </style>
</head>
<body>

    <div class="form-card">
        <h2><i class="ri-folder-add-line"></i> NEW VAULT</h2>
        
        <?php if($message) echo "<p style='color:red; text-align:center;'>$message</p>"; ?>
<form action="" method="POST">
            <div class="input-group">
                <label>Vault Name</label>
                <input type="text" name="name" placeholder="e.g. Scary Memes" required>
            </div>

            <div class="input-group">
                <label>Description</label>
                <textarea name="description" rows="3" placeholder="What's inside this collection?"></textarea>
            </div>

            <div class="input-group">
                <label>Privacy</label>
                <select name="privacy">
                    <option value="public">Public (Everyone can see)</option>
                    <option value="private">Private (Only me)</option>
                </select>
            </div>

            <button type="submit" class="btn-submit">INITIALIZE VAULT</button>
        </form>

        <a href="playlists.php" class="back-link">Cancel and go back</a>
    </div>

</body>
</html>