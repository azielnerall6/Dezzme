<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user){
    header("Location: login.php");
    exit();
}

// Profile being viewed
$profile_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $current_user;

// Fetch profile info
$user = $conn->query("SELECT id, username, profile_pic FROM users WHERE id=$profile_id")->fetch_assoc();
if(!$user){ die("User not found."); }

// Handle playlist creation (owner only)
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_playlist']) && $current_user === $profile_id){
    $name = $conn->real_escape_string($_POST['playlist_name']);
    $desc = $conn->real_escape_string($_POST['playlist_desc']);
    $privacy = in_array($_POST['privacy'], ['public','private']) ? $_POST['privacy'] : 'private';

    $stmt = $conn->prepare("INSERT INTO playlists (user_id, name, description, privacy) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss",$current_user,$name,$desc,$privacy);
    $stmt->execute();
    $stmt->close();
    header("Location: playlists.php?user_id=$profile_id");
    exit();
}

// Handle adding items to playlist (owner only)
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item']) && $current_user === $profile_id){
    $playlist_id = intval($_POST['playlist_id']);
    $title = $conn->real_escape_string($_POST['item_title']);
    $type = in_array($_POST['item_type'], ['audio','meme','video']) ? $_POST['item_type'] : 'audio';

    if(isset($_FILES['item_file']) && $_FILES['item_file']['error'] === 0){
        $filename = time()."_".basename($_FILES['item_file']['name']);
        $target_dir = "uploads/playlist_items/";
        if(!is_dir($target_dir)) mkdir($target_dir,0755,true);
        $target_file = $target_dir.$filename;
        if(move_uploaded_file($_FILES['item_file']['tmp_name'],$target_file)){
            $stmt = $conn->prepare("INSERT INTO playlist_items (playlist_id, title, type, file_path) VALUES (?,?,?,?)");
            $stmt->bind_param("isss",$playlist_id,$title,$type,$target_file);
            $stmt->execute();
            $stmt->close();
        }
    }
    header("Location: playlists.php?user_id=$profile_id");
    exit();
}

// Handle collecting playlist (visitor only)
if(isset($_GET['collect']) && $current_user !== $profile_id){
    $collect_id = intval($_GET['collect']);
    $stmt = $conn->prepare("INSERT IGNORE INTO playlist_collections (user_id, playlist_id) VALUES (?,?)");
    $stmt->bind_param("ii",$current_user,$collect_id);
    $stmt->execute();
    $stmt->close();
    header("Location: playlists.php?user_id=$profile_id");
    exit();
}

// Fetch playlists
$playlists = $conn->query("
    SELECT p.*, 
        (SELECT COUNT(*) FROM playlist_items pi WHERE pi.playlist_id=p.id) AS item_count,
        (SELECT file_path FROM playlist_items pi2 WHERE pi2.playlist_id=p.id ORDER BY id ASC LIMIT 1) AS cover_path
    FROM playlists p
    WHERE p.user_id=$profile_id
    ORDER BY p.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($user['username']); ?> | Playlists</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
:root{
    --bg:#0b0b1a;
    --glass:rgba(255,255,255,0.05);
    --glass-border:rgba(255,255,255,0.15);
    --neon-blue:#00d2ff;
    --neon-purple:#977DFF;
    --peura-red:#FF4C4C;
    --text-light:#eee;
}
body{
    font-family:'Poppins',sans-serif;
    margin:0;
    background:radial-gradient(circle at top left,#1a1a3a,#0b0b1a);
    color:var(--text-light);
}

/* Container */
.container{max-width:1300px;margin:0 auto;padding:20px;}

/* Back Button */
.back-btn{
    display:inline-block;
    margin-bottom:20px;
    padding:12px 25px;
    border-radius:30px;
    border:1px solid var(--glass-border);
    background:var(--glass);
    color:white;
    text-decoration:none;
    font-weight:600;
    transition:0.3s;
}
.back-btn:hover{
    background:var(--neon-purple);
    box-shadow:0 0 25px var(--neon-purple);
}

/* Header */
.header{
    display:flex;
    align-items:center;
    gap:25px;
    margin-bottom:40px;
}
.header img{
    width:130px;
    height:130px;
    border-radius:20px;
    border:3px solid var(--peura-red);
    object-fit:cover;
    box-shadow:0 0 30px rgba(255,76,76,0.5);
}
.header h1{
    margin:0;
    font-size:2.2rem;
}
.header small{
    opacity:0.6;
    display:block;
    margin-top:8px;
}

/* Create Playlist Form */
.create-playlist{
    background:var(--glass);
    border:1px solid var(--glass-border);
    border-radius:30px;
    padding:25px;
    margin-bottom:50px;
    backdrop-filter:blur(15px);
}
.create-playlist h3{
    margin:0 0 20px 0;
    font-size:1.5rem;
}
.create-playlist form{
    display:flex;
    flex-wrap:wrap;
    gap:20px;
}
.create-playlist input, .create-playlist select{
    padding:12px;
    border-radius:15px;
    border:none;
    background:rgba(255,255,255,0.1);
    color:white;
    flex:1;
    min-width:180px;
}
.create-playlist button{
    padding:12px 20px;
    border-radius:20px;
    border:none;
    background:var(--neon-blue);
    color:#000;
    font-weight:700;
    cursor:pointer;
    transition:0.3s;
}
.create-playlist button:hover{
    background:#00a0d4;
}

/* Playlist Grid */
.playlist-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(300px,1fr));
    gap:30px;
}
.playlist-card{
    background:var(--glass);
    border:1px solid var(--glass-border);
    border-radius:25px;
    overflow:hidden;
    position:relative;
    transition:0.4s;
}
.playlist-card:hover{
    transform:translateY(-6px);
    box-shadow:0 0 40px rgba(0,210,255,0.3);
}

/* Cover Hover Effect */
.playlist-card .cover-container{
    position:relative;
    overflow:hidden;
}
.playlist-card .cover-container::after{
    content:'';
    position:absolute;
    top:0;left:0;width:100%;height:100%;
    background:rgba(255,255,255,0.1);
    opacity:0;
    transition:0.3s;
}
.playlist-card:hover .cover-container::after{
    opacity:1;
}

/* Cover Image */
.playlist-card img{
    width:100%;
    height:200px;
    object-fit:cover;
    display:block;
}

/* Playlist Info */
.playlist-info{
    padding:20px;
}
.playlist-info h3{
    margin:0;
    font-size:1.3rem;
}
.playlist-info p{
    margin:8px 0;
    font-size:0.9rem;
    opacity:0.8;
}
.playlist-info .meta{
    font-size:0.75rem;
    color:#ccc;
    margin-top:6px;
}

/* Action Buttons */
.playlist-actions{
    display:flex;
    gap:12px;
    margin-top:15px;
}
.btn{
    padding:7px 14px;
    border-radius:18px;
    border:none;
    cursor:pointer;
    font-weight:600;
    transition:0.3s;
    font-size:0.85rem;
}
.btn.view{background:var(--neon-blue);color:#000;}
.btn.add{background:var(--peura-red);color:#fff;}
.btn.collect{background:#4caf50;color:#fff;}
.btn:hover{opacity:0.85;transform:scale(1.05);}

/* Modal */
.modal{
    display:none;
    position:fixed;
    top:0;left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.85);
    backdrop-filter:blur(8px);
    justify-content:center;
    align-items:center;
    z-index:999;
}
.modal-content{
    background:var(--glass);
    border-radius:25px;
    width:90%;
    max-width:950px;
    max-height:90%;
    overflow-y:auto;
    padding:25px;
    position:relative;
}
.modal-close{
    position:absolute;
    top:20px;right:25px;
    font-size:1.8rem;
    cursor:pointer;
    color:var(--peura-red);
}

/* Item Grid */
.item-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(160px,1fr));
    gap:18px;
    margin-top:20px;
}
.item-card{
    background:rgba(255,255,255,0.05);
    padding:12px;
    border-radius:15px;
    text-align:center;
    transition:0.3s;
}
.item-card:hover{background:rgba(255,255,255,0.12);transform:translateY(-3px);}
.item-card img{width:100%;height:120px;object-fit:cover;border-radius:10px;margin-bottom:6px;}
.item-card audio{width:100%;margin-top:6px;}
.item-card span{display:block;margin-top:5px;font-size:0.85rem;color:#fff;}

/* Scrollbar for modal */
.modal-content::-webkit-scrollbar{
    width:8px;
}
.modal-content::-webkit-scrollbar-thumb{
    background:var(--neon-purple);
    border-radius:4px;
}
</style>
</head>
<body>

<div class="container">

<a class="back-btn" href="profile.php?user_id=<?php echo $profile_id; ?>"><i class="ri-arrow-left-line"></i> Back to Profile</a>

<div class="header">
    <img src="<?php echo $user['profile_pic'] ?: 'uploads/default.jpg'; ?>">
    <div>
        <h1><?php echo htmlspecialchars($user['username']); ?>'s Sounds</h1>
        <small><?php echo $current_user === $profile_id ? 'Your sounds' : 'Browse Sounds'; ?></small>
    </div>
</div>

<?php if($current_user === $profile_id): ?>
<div class="create-playlist">
<h3>Create New Playlist</h3>
<form method="POST">
    <input type="text" name="playlist_name" placeholder="Playlist Name" required>
    <input type="text" name="playlist_desc" placeholder="Description (optional)">
    <select name="privacy">
        <option value="public">Public</option>
        <option value="private">Private</option>
    </select>
    <button type="submit" name="create_playlist">Create Playlist</button>
</form>
</div>
<?php endif; ?>

<div class="playlist-grid">
<?php while($pl=$playlists->fetch_assoc()): ?>
<div class="playlist-card">
    <div class="cover-container">
        <img src="<?php echo $pl['cover_path'] ?: 'uploads/default_playlist.jpg'; ?>" alt="Cover">
    </div>
    <div class="playlist-info">
        <h3><?php echo htmlspecialchars($pl['name']); ?></h3>
        <p><?php echo htmlspecialchars($pl['description']); ?></p>
        <div class="meta"><?php echo $pl['item_count']; ?> items</div>
        <?php if($current_user !== $profile_id): ?>
        <div class="meta">Owner: <?php echo htmlspecialchars($user['username']); ?></div>
        <?php endif; ?>
        <div class="playlist-actions">
            <button class="btn view" onclick="openModal(<?php echo $pl['id']; ?>)">View</button>
            <?php if($current_user === $profile_id): ?>
            <button class="btn add" onclick="openAddItem(<?php echo $pl['id']; ?>)">Add</button>
            <?php else: ?>
            <a href="?user_id=<?php echo $profile_id; ?>&collect=<?php echo $pl['id']; ?>" class="btn collect">Collect</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endwhile; ?>
</div>

<!-- Modal for Playlist Items -->
<div class="modal" id="playlistModal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle">Playlist Items</h2>
        <div class="item-grid" id="modalItems"></div>
        <?php if($current_user === $profile_id): ?>
        <div id="addItemForm"></div>
        <?php endif; ?>
    </div>
</div>

<script>
function openModal(playlistId){
    fetch(`playlist_items_ajax.php?playlist_id=${playlistId}`)
    .then(res=>res.json())
    .then(data=>{
        document.getElementById('modalTitle').innerText = data.name;
        let itemsDiv = document.getElementById('modalItems');
        itemsDiv.innerHTML='';
        data.items.forEach(item=>{
            let div = document.createElement('div');
            div.className='item-card';
            if(item.type==='audio'){
                div.innerHTML=`<audio controls src="${item.file_path}"></audio><span>${item.title}</span>`;
            } else {
                div.innerHTML=`<img src="${item.file_path}" alt="Item"><span>${item.title}</span>`;
            }
            itemsDiv.appendChild(div);
        });
        document.getElementById('playlistModal').style.display='flex';
    });
}
function closeModal(){document.getElementById('playlistModal').style.display='none';}
</script>

</div>
</body>
</html>