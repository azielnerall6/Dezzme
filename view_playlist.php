<?php
include 'config.php';

$playlist_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($playlist_id === 0) {
    die("Error: Vault ID missing.");
}

// 1. FETCH PLAYLIST DETAILS & OWNER
$pl_sql = "SELECT p.*, u.username, u.profile_pic 
           FROM playlists p 
           JOIN users u ON p.user_id = u.id 
           WHERE p.id = $playlist_id";
$pl_res = $conn->query($pl_sql);
$playlist = $pl_res->fetch_assoc();

if (!$playlist) { die("Vault not found."); }

// 2. FETCH ALL ITEMS IN THIS PLAYLIST
$items_sql = "SELECT * FROM playlist_items WHERE playlist_id = $playlist_id ORDER BY added_at DESC";
$items_res = $conn->query($items_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dezzme | Viewing <?php echo $playlist['name']; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css">
    <style>
        :root {
            --bg: #0b0b1a;
            --glass: rgba(255, 255, 255, 0.05);
            --neon-blue: #00d2ff;
            --neon-purple: #9d50bb;
        }

        body {
            background: var(--bg);
            color: #fff;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            padding: 40px 20px;
            background-image: radial-gradient(circle at top right, #1a1a3a, #0b0b1a);
        }

        .container { max-width: 800px; margin: 0 auto; }

        .vault-header {
            text-align: center;
            margin-bottom: 50px;
        }

        .vault-cover {
            width: 180px; height: 180px;
            border-radius: 30px;
            object-fit: cover;
            border: 2px solid var(--neon-blue);
            box-shadow: 0 0 30px rgba(0, 210, 255, 0.2);
            margin-bottom: 20px;
        }

        /* GRID FOR ITEMS */
        .items-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .item-card {
            background: var(--glass);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            backdrop-filter: blur(10px);
        }

        /* AUDIO STYLING */
        .audio-player {
            flex: 1;
            height: 35px;
            filter: invert(1) hue-rotate(180deg); /* Makes standard player look "Cyber" */
            opacity: 0.8;
        }

        /* MEME STYLING */
        .meme-preview {
            width: 60px; height: 60px;
            border-radius: 12px;
            object-fit: cover;
        }

        .item-info { flex: 1; }
        .item-info strong { display: block; font-size: 1rem; }
        .item-info span { font-size: 0.7rem; color: #666; text-transform: uppercase; }

        /* OWNER FOOTER */
        .vault-footer {
            margin-top: 60px;
            padding: 30px;
            border-top: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }

        .owner-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--glass);
            padding: 10px 20px;
            border-radius: 50px;
            text-decoration: none;
            color: white;
            border: 1px solid var(--neon-purple);
        }

        .owner-chip img { width: 30px; height: 30px; border-radius: 50%; }

        .btn-back {
            text-decoration: none;
            color: var(--neon-blue);
            font-size: 0.9rem;
            margin-bottom: 20px;
            display: inline-block;
        }
    </style>
</head>
<body>

<div class="container">
    <a href="playlists.php" class="btn-back"><i class="ri-arrow-left-line"></i> Back to Vaults</a>

    <div class="vault-header">
        <?php if($playlist['cover_path']): ?>
            <img src="<?php echo $playlist['cover_path']; ?>" class="vault-cover">
        <?php else: ?>
<div class="vault-cover" style="display:flex; align-items:center; justify-content:center; margin: 0 auto 20px auto; background: #111;">
                <i class="ri-folder-zip-line" style="font-size: 4rem; color: #333;"></i>
            </div>
        <?php endif; ?>
        
        <h1 style="margin:0;"><?php echo htmlspecialchars($playlist['name']); ?></h1>
        <p style="opacity: 0.6;"><?php echo htmlspecialchars($playlist['description']); ?></p>
    </div>

    <div class="items-list">
        <?php if($items_res->num_rows > 0): ?>
            <?php while($item = $items_res->fetch_assoc()): ?>
                <div class="item-card">
                    
                    <?php if($item['type'] == 'audio'): ?>
                        <div class="item-icon"><i class="ri-volume-up-fill" style="color:var(--neon-blue); font-size: 1.5rem;"></i></div>
                        <div class="item-info">
                            <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                            <span>Audio Archive</span>
                        </div>
                        <audio controls class="audio-player">
                            <source src="uploads/<?php echo $item['file_path']; ?>" type="audio/mpeg">
                        </audio>

                    <?php else: ?>
                        <img src="uploads/<?php echo $item['file_path']; ?>" class="meme-preview">
                        <div class="item-info">
                            <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                            <span>Visual Stash</span>
                        </div>
                        <a href="uploads/<?php echo $item['file_path']; ?>" target="_blank" style="color:var(--neon-blue);"><i class="ri-external-link-line"></i></a>
                    <?php endif; ?>

                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="text-align:center; opacity:0.3; padding: 50px;">
                <i class="ri-inbox-line" style="font-size: 3rem;"></i>
                <p>This Vault is currently empty.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="vault-footer">
        <p style="font-size: 0.8rem; color: #555; margin-bottom: 15px;">VAULT AUTHENTICATED BY</p>
        <a href="profile.php?user_id=<?php echo $playlist['user_id']; ?>" class="owner-chip">
            <img src="uploads/<?php echo $playlist['profile_pic']; ?>">
            <span>@<?php echo htmlspecialchars($playlist['username']); ?></span>
        </a>
    </div>
</div>

</body>
</html>