<?php
session_start();
include 'config.php';

$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'You';

// Get original meme info
$meme_id = intval($_GET['meme_id'] ?? 0);
$original = $conn->query("
    SELECT memes.*, users.username, users.profile_pic 
    FROM memes 
    JOIN users ON memes.uploaded_by=users.id 
    WHERE memes.id=$meme_id
")->fetch_assoc();

$original_username = $original['username'];
$original_user_id  = $original['uploaded_by'];
$original_file     = $original['file_name'];
$original_type     = $original['type'];
$original_caption  = $original['caption'] ?? '';
$original_pic      = $original['profile_pic'] ?? 'uploads/mee.jpeg';

// Fetch remixer profile
$me = $conn->query("SELECT username, nickname, profile_pic FROM users WHERE id=$user_id")->fetch_assoc();
$my_username = $me['username'];
$my_nickname = $me['nickname'] ?? '';
$my_pic      = $me['profile_pic'] ?? 'uploads/mee.jpeg';

// Remix chain depth
$remix_depth = 0;
$chain_res = $conn->query("SELECT COUNT(*) as c FROM remix WHERE root_id=$meme_id");
if($chain_res) $remix_depth = $chain_res->fetch_assoc()['c'] ?? 0;

// Other remixes of same post
$other_remixes_res = $conn->query("
    SELECT m.id, m.file_name, m.type, m.caption, u.username, u.profile_pic
    FROM memes m
    JOIN users u ON m.uploaded_by=u.id
    WHERE m.remix_of=$meme_id AND m.uploaded_by != $user_id
    ORDER BY m.created_at DESC LIMIT 6
");
$other_remixes = [];
if($other_remixes_res) while($r=$other_remixes_res->fetch_assoc()) $other_remixes[] = $r;

// Original post stats
$orig_likes    = $conn->query("SELECT COUNT(*) as c FROM likes WHERE meme_id=$meme_id")->fetch_assoc()['c'] ?? 0;
$orig_reposts  = $conn->query("SELECT COUNT(*) as c FROM reposts WHERE meme_id=$meme_id")->fetch_assoc()['c'] ?? 0;
$orig_favs     = $conn->query("SELECT COUNT(*) as c FROM favorites WHERE meme_id=$meme_id")->fetch_assoc()['c'] ?? 0;
$orig_comments = $conn->query("SELECT COUNT(*) as c FROM comments WHERE meme_id=$meme_id")->fetch_assoc()['c'] ?? 0;

// My hashtags
$my_tags_res = $conn->query("
    SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(caption, '#', -1), ' ', 1) as tag, COUNT(*) as cnt
    FROM memes WHERE uploaded_by=$user_id AND caption LIKE '%#%'
    GROUP BY tag HAVING tag != '' AND LENGTH(tag) > 1
    ORDER BY cnt DESC LIMIT 10
");
$my_tags = [];
if($my_tags_res) while($t=$my_tags_res->fetch_assoc()) $my_tags[] = $t;

// Trending hashtags
$trending_tags_res = $conn->query("
    SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(caption, '#', -1), ' ', 1) as tag, COUNT(*) as cnt
    FROM memes WHERE caption LIKE '%#%' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY tag HAVING tag != '' AND LENGTH(tag) > 1
    ORDER BY cnt DESC LIMIT 8
");
$trending_tags = [];
if($trending_tags_res) while($t=$trending_tags_res->fetch_assoc()) $trending_tags[] = $t;

// My recent posts for inspiration grid
$my_posts = $conn->query("SELECT * FROM memes WHERE uploaded_by=$user_id ORDER BY created_at DESC LIMIT 9");

// Followers/Following counts for remixer
$fc = $conn->query("SELECT COUNT(*) as c FROM followers WHERE following_id=$user_id")->fetch_assoc()['c'] ?? 0;
$fg = $conn->query("SELECT COUNT(*) as c FROM followers WHERE follower_id=$user_id")->fetch_assoc()['c'] ?? 0;
$pc = $conn->query("SELECT COUNT(*) as c FROM memes WHERE uploaded_by=$user_id")->fetch_assoc()['c'] ?? 0;

$success_message = "";
$error_message   = "";

if(isset($_POST['upload'])){
    $caption    = trim($_POST['caption_hidden'] ?? '');
    $remix_note = trim($_POST['remix_note'] ?? '');
    $is_remix   = 1;

    $requiredTag      = "↩️ ";
    $requiredUsername = "@$original_username";

    if(substr($caption, 0, strlen($requiredTag)) !== $requiredTag)
        $caption = $requiredTag . ltrim($caption, "↩️ ");

    if(strpos($caption, $requiredUsername) === false)
        $error_message = "You must include the original creator @$original_username in your caption.";

    if(!preg_match('/#\w+/', $caption))
        $error_message = "Please include at least one #hashtag in your caption.";

    if(empty($error_message)){
        $edited_data = $_POST['edited_image_data'] ?? '';
        $file_name   = $original_file;
        $type        = $original_type;

        // If user edited the image
        if(!empty($edited_data) && $original_type === 'image'){
            $data    = preg_replace('#^data:image/\w+;base64,#i','', $edited_data);
            $data    = base64_decode($data);
            $newname = time().'_remix_'.rand(1000,9999).'.jpg';
            file_put_contents("uploads/".$newname, $data);
            $file_name = $newname;
        }
        // If user uploaded a new file on top
        if(isset($_FILES['remix_file']) && $_FILES['remix_file']['error'] === 0){
            $file = $_FILES['remix_file'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','mp4','webm','mov'];
            if(in_array($ext, $allowed)){
                $newname = time().'_remix_'.rand(1000,9999).'.'.$ext;
                move_uploaded_file($file['tmp_name'], "uploads/".$newname);
                $file_name = $newname;
                $type = in_array($ext, ['mp4','webm','mov']) ? 'video' : 'image';
            }
        }

        $stmt = $conn->prepare("INSERT INTO remix (user_id,post_id,parent_id,root_id,content,caption) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("iiiiss", $user_id, $meme_id, $meme_id, $meme_id, $file_name, $caption);
        $stmt->execute();

        $stmt2 = $conn->prepare("INSERT INTO memes (file_name,type,caption,uploaded_by,remix_of) VALUES (?,?,?,?,?)");
        $stmt2->bind_param("sssis", $file_name, $type, $caption, $user_id, $meme_id);
        $stmt2->execute();

        $success_message = "Remix posted successfully! 🎉";
    }
}

function shortNum($n){
    if($n>=1000000000) return round($n/1000000000,1).'B';
    if($n>=1000000)    return round($n/1000000,1).'M';
    if($n>=1000)       return round($n/1000,1).'K';
    return $n;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Remix by <?php echo htmlspecialchars($my_username); ?> | Dezzme</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
<style>
/* ═══════════════════════════════════════
   BASE & THEME
═══════════════════════════════════════ */
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
:root{
    --red:#FF2D2D;--red-dim:rgba(255,45,45,0.18);--red-glow:rgba(255,45,45,0.35);
    --ink:#06071A;--ink-2:#0C0E28;
    --surface:rgba(255,255,255,0.04);--surface-2:rgba(255,255,255,0.07);
    --border:rgba(255,255,255,0.07);--border-2:rgba(255,255,255,0.13);
    --text:#F0F2FF;--text-2:rgba(220,224,255,0.65);--text-3:rgba(180,188,255,0.4);
    --remix-accent:#FF2D55;--remix-glow:rgba(255,45,85,0.35);
    --remix-chain:#ff9500;
}

html{scroll-behavior:smooth;}
body{
    background-color:var(--ink);
    background-image:
        radial-gradient(ellipse 80% 60% at 10% 0%,rgba(0,60,255,0.18) 0%,transparent 60%),
        radial-gradient(ellipse 40% 40% at 50% 50%,rgba(0,30,120,0.12) 0%,transparent 70%);
    color:var(--text);
    min-height:100vh;
    overflow-x:hidden;
}

/* ═══════════════════════════════════════
   PARTICLES
═══════════════════════════════════════ */
#particles{position:fixed;width:100%;height:100%;overflow:hidden;top:0;left:0;z-index:-1;pointer-events:none;}
.particle{position:absolute;background:white;border-radius:50%;pointer-events:none;box-shadow:0 0 10px rgba(255,255,255,0.6);animation:floatParticle linear infinite;}
@keyframes floatParticle{
    0%{transform:translate(0,0) scale(1);opacity:0;}
    10%{opacity:1;}
    50%{transform:translate(var(--x-move),var(--y-move)) scale(1.2);}
    100%{transform:translate(calc(var(--x-move)*-1),calc(var(--y-move)*-1)) scale(0.8);opacity:0;}
}

/* ═══════════════════════════════════════
   REMIX PAGE HEADER STRIP
═══════════════════════════════════════ */
.remix-topbar{
    display:flex;
    align-items:center;
    gap:12px;
    padding:14px 20px;
    background:rgba(255,45,85,0.07);
    border-bottom:1px solid rgba(255,45,85,0.15);
    backdrop-filter:blur(20px);
    position:sticky;
    top:0;
    z-index:100;
}
.back-pill{
    display:flex;align-items:center;justify-content:center;
    width:36px;height:36px;border-radius:50%;
    border:none;background:rgba(255,255,255,0.07);
    color:white;font-size:20px;cursor:pointer;
    transition:all 0.2s;flex-shrink:0;
    -webkit-tap-highlight-color:transparent;
}
.back-pill:hover{background:rgba(255,45,85,0.3);transform:scale(1.08);}
.back-pill:active{transform:scale(0.92);}

.remix-badge-pill{
    display:flex;align-items:center;gap:6px;
    padding:6px 14px;border-radius:30px;
    background:rgba(255,45,85,0.15);
    border:1px solid rgba(255,45,85,0.35);
    font-size:12px;font-weight:700;color:#FF2D55;
    letter-spacing:0.5px;
}
.remix-badge-pill i{font-size:14px;}

.chain-pill{
    display:flex;align-items:center;gap:5px;
    padding:5px 11px;border-radius:20px;
    background:rgba(255,149,0,0.1);
    border:1px solid rgba(255,149,0,0.25);
    font-size:10px;font-weight:700;color:var(--remix-chain);
    margin-left:auto;
}

/* ═══════════════════════════════════════
   THREE-COLUMN LAYOUT
═══════════════════════════════════════ */
.remix-layout{
    display:grid;
    grid-template-columns:270px 1fr 260px;
    gap:18px;
    max-width:1380px;
    margin:18px auto 30px;
    padding:0 16px 80px;
    align-items:start;
}

/* ═══════════════════════════════════════
   SHARED PANEL CARD
═══════════════════════════════════════ */
.panel-card{
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:20px;
    overflow:hidden;
    backdrop-filter:blur(20px);
}
.panel-hdr{
    display:flex;align-items:center;gap:8px;
    padding:13px 15px 11px;
    border-bottom:1px solid rgba(255,255,255,0.06);
    font-size:11px;font-weight:700;
    color:rgba(255,255,255,0.45);
    text-transform:uppercase;letter-spacing:1px;
}
.panel-hdr i{color:#FF2D55;font-size:15px;}
.mob-collapse-hdr{cursor:pointer;-webkit-tap-highlight-color:transparent;user-select:none;}
.mob-collapse-hdr .mob-collapse-icon{margin-left:auto;transition:transform 0.25s;font-size:17px;color:rgba(255,255,255,0.3);}
.mob-collapse-icon.open{transform:rotate(180deg);}
.mob-collapse-body{display:block;}

/* ═══════════════════════════════════════
   LEFT PANEL
═══════════════════════════════════════ */
.left-panel{position:sticky;top:76px;display:flex;flex-direction:column;gap:13px;}

/* ── CREATOR COMPARISON CARD ── */
.creator-compare{padding:14px;}
.compare-row{
    display:flex;align-items:center;gap:0;
    justify-content:center;position:relative;
}
.compare-user{
    display:flex;flex-direction:column;align-items:center;gap:6px;
    flex:1;text-align:center;
}
.compare-avatar{
    position:relative;display:inline-block;line-height:0;
}
.compare-avatar img{
    width:56px;height:56px;border-radius:50%;
    object-fit:cover;
    display:block;
}
.compare-avatar.original img{border:2.5px solid #ff9500;box-shadow:0 0 16px rgba(255,149,0,0.35);}
.compare-avatar.remixer img{border:2.5px solid #FF2D55;box-shadow:0 0 16px rgba(255,45,85,0.4);}
.compare-role{
    font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:1px;
    padding:2px 8px;border-radius:20px;
}
.compare-role.orig{background:rgba(255,149,0,0.15);color:#ff9500;border:1px solid rgba(255,149,0,0.3);}
.compare-role.remx{background:rgba(255,45,85,0.15);color:#FF2D55;border:1px solid rgba(255,45,85,0.3);}
.compare-name{font-size:11px;font-weight:700;color:white;}
.compare-handle{font-size:9px;color:rgba(255,255,255,0.35);}

.compare-arrow{
    display:flex;flex-direction:column;align-items:center;gap:3px;
    padding:0 8px;flex-shrink:0;
}
.compare-arrow-icon{
    width:28px;height:28px;border-radius:50%;
    background:rgba(255,45,85,0.15);border:1px solid rgba(255,45,85,0.3);
    display:flex;align-items:center;justify-content:center;
    font-size:13px;color:#FF2D55;
}
.compare-arrow-label{font-size:8px;color:rgba(255,255,255,0.3);font-weight:600;letter-spacing:.3px;}

/* ── ORIGINAL STATS ── */
.orig-stats-grid{
    display:grid;grid-template-columns:repeat(4,1fr);gap:5px;
    padding:10px 13px 13px;
}
.orig-stat{
    background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);
    border-radius:10px;padding:7px 5px;text-align:center;
}
.orig-stat-val{font-size:13px;font-weight:800;color:#fff;}
.orig-stat-lbl{font-size:8px;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:.3px;margin-top:1px;}

/* ── REMIX CHAIN VISUALIZER ── */
.chain-wrap{padding:12px 14px;}
.chain-step{
    display:flex;align-items:flex-start;gap:9px;
    padding:6px 0;
    position:relative;
}
.chain-step:not(:last-child)::after{
    content:'';
    position:absolute;left:11px;top:28px;
    width:1px;height:calc(100% - 10px);
    background:rgba(255,149,0,0.25);
}
.chain-dot{
    width:22px;height:22px;border-radius:50%;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    font-size:10px;font-weight:800;
}
.chain-dot.root{background:rgba(255,149,0,0.2);border:1.5px solid #ff9500;color:#ff9500;}
.chain-dot.you{background:rgba(255,45,85,0.2);border:1.5px solid #FF2D55;color:#FF2D55;}
.chain-info{font-size:10px;color:rgba(255,255,255,0.55);line-height:1.5;}
.chain-info strong{color:rgba(255,255,255,0.85);display:block;font-size:10.5px;}
.chain-count-badge{
    margin-left:auto;font-size:9px;font-weight:700;padding:2px 7px;border-radius:12px;
    background:rgba(255,149,0,0.1);border:1px solid rgba(255,149,0,0.2);color:#ff9500;
    flex-shrink:0;align-self:center;
}

/* ── MY HASHTAGS ── */
.hashtag-chips{display:flex;flex-wrap:wrap;gap:5px;padding:11px 13px 13px;}
.htag-chip{
    padding:4px 10px;border-radius:20px;
    background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);
    color:rgba(255,255,255,0.6);font-size:10px;font-weight:600;
    cursor:pointer;transition:all 0.2s;-webkit-tap-highlight-color:transparent;
    border:none;
}
.htag-chip:hover{background:rgba(255,45,85,0.15);border:1px solid rgba(255,45,85,0.3);color:#FF2D55;}

/* ── OTHER REMIXES GRID ── */
.other-remix-grid{
    display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;padding:10px;
}
.other-remix-thumb{
    aspect-ratio:1;border-radius:8px;overflow:hidden;cursor:pointer;
    background:rgba(255,255,255,0.05);position:relative;transition:0.2s;
}
.other-remix-thumb:hover{transform:scale(1.06);box-shadow:0 4px 14px rgba(255,45,85,0.3);}
.other-remix-thumb img,.other-remix-thumb video{width:100%;height:100%;object-fit:cover;display:block;}
.other-remix-user{
    position:absolute;bottom:0;left:0;right:0;
    background:linear-gradient(0deg,rgba(0,0,0,0.75),transparent);
    padding:12px 5px 4px;font-size:8px;font-weight:700;color:rgba(255,255,255,0.8);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.no-remixes{padding:18px 14px;text-align:center;font-size:11px;color:rgba(255,255,255,0.2);}
.no-remixes i{font-size:22px;display:block;margin-bottom:5px;color:rgba(255,45,85,0.2);}

/* ── REMIX TIPS ── */
.tip-card{display:flex;align-items:flex-start;gap:10px;padding:13px 14px;}
.tip-icon{
    width:30px;height:30px;border-radius:8px;
    background:rgba(255,45,85,0.1);border:1px solid rgba(255,45,85,0.2);
    display:flex;align-items:center;justify-content:center;
    flex-shrink:0;font-size:14px;color:#FF2D55;
}
.tip-text{font-size:11px;color:rgba(255,255,255,0.5);line-height:1.5;}
.tip-text strong{color:rgba(255,255,255,0.85);display:block;margin-bottom:2px;font-size:11px;}
.tip-nav{display:flex;gap:5px;padding:0 13px 12px;}
.tip-nav button{
    flex:1;padding:5px;border-radius:8px;border:1px solid rgba(255,255,255,0.1);
    background:rgba(255,255,255,0.04);color:rgba(255,255,255,0.4);
    font-size:10.5px;cursor:pointer;transition:0.2s;
}
.tip-nav button:hover{background:rgba(255,255,255,0.09);color:white;}

/* ── TRENDING TAGS ── */
.trending-tag-item{
    display:flex;align-items:center;gap:8px;
    padding:6px 8px;border-radius:8px;cursor:pointer;transition:0.2s;
    -webkit-tap-highlight-color:transparent;
}
.trending-tag-item:hover{background:rgba(255,255,255,0.04);}
.trending-tag-name{font-size:11px;font-weight:600;color:#4dabff;}
.trending-tag-cnt{font-size:9px;color:rgba(255,255,255,0.3);margin-left:auto;}
.trending-tags-wrap{padding:8px 10px 12px;}

/* ═══════════════════════════════════════
   CENTER PANEL
═══════════════════════════════════════ */
.center-panel{display:flex;flex-direction:column;gap:16px;}

/* ── ORIGINAL PREVIEW CARD ── */
.original-preview-card{
    border-radius:20px;overflow:hidden;
    border:1px solid rgba(255,149,0,0.25);
    background:rgba(255,149,0,0.04);
    backdrop-filter:blur(20px);
}
.original-preview-top{
    display:flex;align-items:center;gap:10px;
    padding:12px 16px;
    border-bottom:1px solid rgba(255,149,0,0.1);
}
.orig-avatar-sm{
    width:36px;height:36px;border-radius:50%;object-fit:cover;
    border:2px solid #ff9500;flex-shrink:0;
}
.orig-meta{flex:1;min-width:0;}
.orig-meta-name{font-size:12px;font-weight:700;color:white;}
.orig-meta-label{font-size:9px;color:#ff9500;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.original-media-wrap{
    width:100%;max-height:280px;overflow:hidden;
    display:flex;align-items:center;justify-content:center;
    background:rgba(0,0,0,0.3);position:relative;
}
.original-media-wrap img,.original-media-wrap video{
    max-width:100%;max-height:280px;object-fit:contain;display:block;
}
.orig-caption-strip{
    padding:10px 16px;
    font-size:11px;color:rgba(255,255,255,0.5);
    border-top:1px solid rgba(255,149,0,0.08);
    font-style:italic;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.orig-stats-strip{
    display:flex;gap:14px;padding:8px 16px 12px;
}
.orig-stat-s{display:flex;align-items:center;gap:4px;font-size:10px;color:rgba(255,255,255,0.35);}
.orig-stat-s i{font-size:12px;color:#FF2D55;}
.orig-stat-s span{font-weight:700;color:rgba(255,255,255,0.55);}

/* ── REMIX UPLOAD CARD ── */
.remix-card{
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,45,85,0.15);
    border-radius:22px;
    padding:20px;
    backdrop-filter:blur(25px);
    box-shadow:0 20px 50px rgba(0,0,0,0.35);
}

.remix-card-title{
    display:flex;align-items:center;gap:8px;
    margin-bottom:16px;
    padding-bottom:12px;
    border-bottom:1px solid rgba(255,45,85,0.1);
}
.remix-card-title i{font-size:18px;color:#FF2D55;}
.remix-card-title h3{font-size:14px;font-weight:700;color:white;}
.remix-card-title span{font-size:10px;color:rgba(255,255,255,0.3);margin-left:auto;}

/* Media mode tabs */
.media-tabs{
    display:flex;background:rgba(0,0,0,0.3);padding:4px;
    border-radius:12px;margin-bottom:16px;gap:3px;
}
.media-tabs button{
    flex:1;padding:9px 6px;border-radius:9px;border:none;cursor:pointer;
    font-weight:600;font-size:12px;background:transparent;
    color:rgba(255,255,255,0.4);transition:0.25s;font-family:'Poppins',sans-serif;
}
.media-tabs button.active{background:#FF2D55;color:white;box-shadow:0 4px 12px rgba(255,45,85,0.35);}

/* Upload drop zone */
.remix-upload-area{
    border:2px dashed rgba(255,45,85,0.25);
    border-radius:16px;padding:28px 16px;text-align:center;
    transition:0.3s;margin-bottom:16px;cursor:pointer;
    background:rgba(255,45,85,0.03);
}
.remix-upload-area:hover{border-color:#FF2D55;background:rgba(255,45,85,0.07);}
.remix-upload-area i{font-size:28px;color:#FF2D55;margin-bottom:8px;display:block;}
.remix-upload-area p{font-size:12px;color:rgba(255,255,255,0.4);}
.remix-upload-area small{font-size:10px;color:rgba(255,255,255,0.2);display:block;margin-top:3px;}

/* Caption contenteditable */
#captionContainer{
    width:100%;border-radius:12px;padding:13px;
    background:rgba(0,0,0,0.22);border:1px solid rgba(255,255,255,0.06);
    color:white;min-height:90px;font-size:13px;margin-bottom:12px;outline:none;
    transition:border-color 0.2s;
}
#captionContainer:focus{border-color:rgba(255,45,85,0.4);}
#captionContainer:empty:before{content:"Write your remix caption… ↩️ @original + #hashtag";color:rgba(255,255,255,0.22);}

/* Remix note (optional) */
.remix-note-input{
    width:100%;padding:10px 13px;border-radius:12px;
    border:1px solid rgba(255,255,255,0.06);background:rgba(0,0,0,0.18);
    color:rgba(255,255,255,0.75);font-size:12px;font-family:'Poppins',sans-serif;
    outline:none;margin-bottom:14px;transition:border-color 0.2s;resize:none;height:50px;
}
.remix-note-input:focus{border-color:rgba(255,45,85,0.3);}
.remix-note-input::placeholder{color:rgba(255,255,255,0.2);}

/* Caption requirements hint */
.caption-rules{
    display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;
}
.cap-rule{
    display:flex;align-items:center;gap:4px;
    font-size:10px;padding:3px 9px;border-radius:14px;
    background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.35);
    border:1px solid rgba(255,255,255,0.08);
    transition:all 0.3s;
}
.cap-rule i{font-size:11px;}
.cap-rule.done{background:rgba(77,222,138,0.1);border-color:rgba(77,222,138,0.3);color:#4dde8a;}
.cap-rule.done i{color:#4dde8a;}

/* Post button */
.post-remix-btn{
    width:100%;background:linear-gradient(135deg,#FF2D55,#ff1744);
    border:none;padding:15px;border-radius:13px;
    font-weight:800;color:white;cursor:pointer;transition:0.3s;
    font-size:14px;font-family:'Poppins',sans-serif;
    display:flex;align-items:center;justify-content:center;gap:8px;
    letter-spacing:0.5px;
}
.post-remix-btn:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(255,45,85,0.4);}
.post-remix-btn:active{transform:translateY(0);}
.post-remix-btn i{font-size:16px;}

/* ── IMAGE EDITOR ── */
#editorPanel{display:none;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,0.08);background:rgba(0,0,0,0.28);margin-bottom:14px;}
.editor-toolbar{display:flex;flex-wrap:wrap;gap:6px;padding:10px 12px;background:rgba(0,0,0,0.35);border-bottom:1px solid rgba(255,255,255,0.07);align-items:center;}
.editor-toolbar label{font-size:9.5px;color:rgba(255,255,255,0.4);display:flex;flex-direction:column;gap:2px;}
.editor-toolbar input[type="text"],.editor-toolbar select{background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:7px;color:white;padding:4px 7px;font-size:11px;font-family:'Poppins',sans-serif;outline:none;max-width:100px;}
.editor-toolbar input[type="color"]{width:30px;height:26px;border:none;border-radius:6px;cursor:pointer;background:none;padding:0;}
.editor-toolbar input[type="range"]{width:70px;accent-color:#FF2D55;}
.tool-btn{padding:5px 10px;border-radius:7px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.06);color:white;font-size:10.5px;font-family:'Poppins',sans-serif;cursor:pointer;transition:0.2s;display:flex;align-items:center;gap:3px;white-space:nowrap;}
.tool-btn:hover{background:rgba(255,45,85,0.2);border-color:#FF2D55;}
.tool-btn.danger{background:rgba(255,45,85,0.1);border-color:rgba(255,45,85,0.25);}
.tool-btn.danger:hover{background:rgba(255,45,85,0.28);}
.tool-btn.confirm{background:rgba(77,222,138,0.1);border-color:rgba(77,222,138,0.25);}
.tool-btn.confirm:hover{background:rgba(77,222,138,0.25);}
.editor-section-label{font-size:8.5px;font-weight:700;color:rgba(255,255,255,0.25);text-transform:uppercase;letter-spacing:1px;padding:0 3px;align-self:center;}
.canvas-wrapper{position:relative;width:100%;background:#000;display:flex;justify-content:center;align-items:center;min-height:160px;max-height:360px;overflow:hidden;}
#fabricCanvas{display:block;max-width:100%;}
.sticker-panel{display:none;flex-wrap:wrap;gap:4px;padding:8px 11px;background:rgba(0,0,0,0.2);border-top:1px solid rgba(255,255,255,0.05);max-height:90px;overflow-y:auto;}
.sticker-panel.open{display:flex;}
.sticker-btn{font-size:22px;cursor:pointer;padding:2px;border-radius:6px;transition:0.15s;background:none;border:none;line-height:1;}
.sticker-btn:hover{background:rgba(255,255,255,0.08);transform:scale(1.2);}
.crop-controls{display:none;gap:6px;padding:8px 12px;background:rgba(0,0,0,0.32);border-top:1px solid rgba(255,255,255,0.05);}
.crop-controls.open{display:flex;flex-wrap:wrap;align-items:center;}
.done-edit-btn{width:calc(100% - 22px);margin:9px 11px;padding:9px;background:linear-gradient(135deg,#FF2D55,#ff1744);border:none;border-radius:10px;color:white;font-weight:700;font-size:11.5px;cursor:pointer;transition:0.3s;font-family:'Poppins',sans-serif;}
.done-edit-btn:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(255,45,85,0.35);}

/* ── LIVE PREVIEW ── */
.preview-card{
    border-radius:18px;overflow:hidden;width:100%;aspect-ratio:4/5;
    border:1px solid rgba(255,45,85,0.12);
    background:rgba(0,0,0,0.22);
    display:flex;align-items:center;justify-content:center;position:relative;
}
.preview-card img,.preview-card video{width:100%;height:100%;object-fit:cover;}
.preview-label{
    position:absolute;bottom:10px;left:10px;
    background:rgba(0,0,0,0.6);backdrop-filter:blur(8px);
    border-radius:8px;padding:3px 9px;font-size:9px;font-weight:700;
    color:rgba(255,255,255,0.5);letter-spacing:0.8px;text-transform:uppercase;
    border:1px solid rgba(255,255,255,0.1);
}
.preview-placeholder{color:rgba(255,255,255,0.1);font-size:12px;text-align:center;padding:20px;}
.preview-placeholder i{font-size:30px;display:block;margin-bottom:8px;color:rgba(255,45,85,0.18);}

/* ── MESSAGES ── */
.success-msg{
    background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);
    border-radius:12px;padding:11px 15px;color:#86efac;font-size:12px;font-weight:600;
    text-align:center;margin-bottom:12px;display:flex;align-items:center;justify-content:center;gap:7px;
}
.error-msg{
    background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);
    border-radius:12px;padding:11px 15px;color:#fca5a5;font-size:12px;font-weight:600;
    text-align:center;margin-bottom:12px;display:flex;align-items:center;justify-content:center;gap:7px;
}

/* ═══════════════════════════════════════
   RIGHT PANEL
═══════════════════════════════════════ */
.right-panel{position:sticky;top:76px;display:flex;flex-direction:column;gap:13px;}

/* ── REMIXER PROFILE ── */
.remixer-widget{
    display:flex;flex-direction:column;align-items:center;
    padding:18px 14px 14px;text-align:center;gap:8px;
}
.remixer-pic-wrap{position:relative;display:inline-block;line-height:0;}
.remixer-pic-wrap img{
    width:72px;height:72px;border-radius:50%;object-fit:cover;
    border:3px solid #FF2D55;
    box-shadow:0 0 20px rgba(255,45,85,0.4),0 8px 24px rgba(0,0,0,0.5);
    display:block;cursor:pointer;transition:0.3s;
}
.remixer-pic-wrap img:hover{transform:scale(1.07);box-shadow:0 0 28px rgba(255,45,85,0.7);}
.remixer-status-dot{
    position:absolute;bottom:3px;right:3px;
    width:13px;height:13px;border-radius:50%;
    background:#c52222;border:2px solid var(--ink);
    box-shadow:0 0 7px rgba(197,34,34,0.6);
}
.remixer-name{font-size:14px;font-weight:700;color:white;}
.remixer-handle{font-size:10px;color:rgba(255,255,255,0.3);margin-top:-2px;}
.remixer-stats-row{display:flex;align-items:center;gap:8px;margin-top:4px;}
.remixer-stats-row span{display:flex;flex-direction:column;align-items:center;gap:1px;text-decoration:none;}
.remixer-stats-row a{text-decoration:none;color:inherit;display:flex;flex-direction:column;align-items:center;gap:1px;}
.remixer-stats-row .sv{font-size:13px;font-weight:700;color:white;}
.remixer-stats-row .sl{font-size:8.5px;color:rgba(255,255,255,0.35);font-weight:600;text-transform:uppercase;letter-spacing:.3px;}
.remixer-divider{color:rgba(255,255,255,0.12);font-size:12px;}
.view-profile-btn{
    display:inline-flex;align-items:center;gap:5px;
    padding:7px 16px;border-radius:20px;
    background:rgba(255,45,85,0.12);border:1px solid rgba(255,45,85,0.3);
    color:#FF2D55;font-size:10.5px;font-weight:700;text-decoration:none;
    transition:all 0.2s;font-family:'Poppins',sans-serif;
}
.view-profile-btn:hover{background:rgba(255,45,85,0.22);color:white;}

/* ── MY POSTS MINI GRID ── */
.my-posts-grid{
    display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;padding:9px;
}
.my-post-thumb{
    aspect-ratio:1;border-radius:8px;overflow:hidden;cursor:pointer;
    background:rgba(255,255,255,0.05);position:relative;transition:0.2s;
}
.my-post-thumb:hover{transform:scale(1.06);box-shadow:0 4px 14px rgba(255,45,85,0.3);}
.my-post-thumb img,.my-post-thumb video{width:100%;height:100%;object-fit:cover;display:block;}
.my-post-thumb .type-badge{
    position:absolute;bottom:3px;right:3px;
    background:rgba(0,0,0,0.7);border-radius:4px;
    padding:1px 4px;font-size:7.5px;color:rgba(255,255,255,0.7);font-weight:700;
}
.posts-empty{padding:18px 14px;text-align:center;font-size:11px;color:rgba(255,255,255,0.2);}
.posts-empty i{font-size:22px;display:block;margin-bottom:5px;color:rgba(255,45,85,0.18);}

/* ── QUICK ACCESS ── */
.quick-access-grid{
    display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;padding:10px;
}
.qa-btn{
    display:flex;flex-direction:column;align-items:center;gap:3px;
    padding:9px 3px;border-radius:11px;
    background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);
    text-decoration:none;color:rgba(255,255,255,0.55);font-size:9px;font-weight:600;
    text-align:center;transition:all 0.2s;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.qa-btn i{font-size:17px;color:#FF2D55;margin-bottom:1px;}
.qa-btn:hover{background:rgba(255,45,85,0.1);border-color:rgba(255,45,85,0.3);color:white;transform:translateY(-2px);}
.qa-btn:hover i{color:#ff7070;}

/* ── REMIX ETIQUETTE ── */
.etiquette-item{
    display:flex;align-items:flex-start;gap:9px;
    padding:9px 13px;
    border-bottom:1px solid rgba(255,255,255,0.04);
}
.etiquette-item:last-child{border-bottom:none;}
.etiquette-icon{
    width:28px;height:28px;border-radius:7px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-size:13px;
}
.etiquette-icon.green{background:rgba(77,222,138,0.1);border:1px solid rgba(77,222,138,0.2);color:#4dde8a;}
.etiquette-icon.red{background:rgba(255,45,85,0.1);border:1px solid rgba(255,45,85,0.2);color:#FF2D55;}
.etiquette-icon.yellow{background:rgba(250,204,21,0.1);border:1px solid rgba(250,204,21,0.2);color:#facc15;}
.etiquette-text{font-size:10px;color:rgba(255,255,255,0.5);line-height:1.4;}
.etiquette-text strong{color:rgba(255,255,255,0.8);display:block;font-size:10px;margin-bottom:1px;}

/* ═══════════════════════════════════════
   RESPONSIVE — 760px
═══════════════════════════════════════ */
@media (max-width: 760px) {

  /* LAYOUT: collapse to single column */
  .remix-layout {
    grid-template-columns: 1fr;
    gap: 12px;
    margin: 12px auto 20px;
    padding: 0 12px 70px;
  }

  /* LEFT & RIGHT panels: unstick, no side-by-side */
  .left-panel,
  .right-panel {
    position: static;
    gap: 10px;
  }

  /* TOPBAR */
  .remix-topbar {
    padding: 11px 14px;
    gap: 9px;
  }
  .back-pill { width: 32px; height: 32px; font-size: 17px; }
  .remix-badge-pill { font-size: 11px; padding: 5px 12px; }
  .remix-badge-pill i { font-size: 13px; }
  .chain-pill { font-size: 9px; padding: 4px 9px; }

  /* CREATOR COMPARE */
  .compare-avatar img { width: 50px; height: 50px; }
  .compare-name { font-size: 10px; }
  .compare-handle { font-size: 8.5px; }
  .compare-role { font-size: 7.5px; }
  .compare-arrow-icon { width: 24px; height: 24px; font-size: 11px; }
  .compare-arrow-label { font-size: 7.5px; }

  /* ORIG STATS GRID */
  .orig-stats-grid { gap: 4px; padding: 9px 11px 11px; }
  .orig-stat-val { font-size: 12px; }
  .orig-stat-lbl { font-size: 7.5px; }

  /* CHAIN */
  .chain-wrap { padding: 10px 12px; }
  .chain-dot { width: 20px; height: 20px; font-size: 9px; }
  .chain-info { font-size: 9.5px; }
  .chain-info strong { font-size: 10px; }
  .chain-count-badge { font-size: 8.5px; }

  /* HASHTAG CHIPS */
  .hashtag-chips { padding: 9px 11px 11px; gap: 4px; }
  .htag-chip { font-size: 9.5px; padding: 3px 9px; }

  /* OTHER REMIX GRID */
  .other-remix-grid { gap: 3px; padding: 8px; }
  .other-remix-user { font-size: 7.5px; }

  /* TIP CARD */
  .tip-card { padding: 11px 12px; gap: 8px; }
  .tip-icon { width: 27px; height: 27px; font-size: 13px; }
  .tip-text { font-size: 10.5px; }
  .tip-text strong { font-size: 10.5px; }
  .tip-nav { padding: 0 11px 10px; }
  .tip-nav button { font-size: 10px; }

  /* TRENDING */
  .trending-tags-wrap { padding: 6px 8px 10px; }
  .trending-tag-name { font-size: 10.5px; }
  .trending-tag-cnt { font-size: 8.5px; }

  /* ORIGINAL PREVIEW */
  .original-preview-top { padding: 10px 13px; gap: 8px; }
  .orig-avatar-sm { width: 32px; height: 32px; }
  .orig-meta-name { font-size: 11px; }
  .orig-meta-label { font-size: 8.5px; }
  .original-media-wrap { max-height: 240px; }
  .orig-caption-strip { font-size: 10.5px; padding: 9px 13px; }
  .orig-stats-strip { padding: 7px 13px 10px; gap: 11px; }
  .orig-stat-s { font-size: 9.5px; }
  .orig-stat-s i { font-size: 11px; }

  /* REMIX CARD */
  .remix-card { padding: 16px; border-radius: 18px; }
  .remix-card-title h3 { font-size: 13px; }
  .remix-card-title i { font-size: 16px; }
  .remix-card-title span { font-size: 9.5px; }
  .media-tabs button { font-size: 11px; padding: 8px 5px; }
  .remix-upload-area { padding: 22px 13px; }
  .remix-upload-area i { font-size: 25px; }
  .remix-upload-area p { font-size: 11.5px; }
  .remix-upload-area small { font-size: 9.5px; }
  #captionContainer { font-size: 12px; padding: 11px; min-height: 80px; }
  .remix-note-input { font-size: 11.5px; }
  .cap-rule { font-size: 9.5px; padding: 3px 8px; }
  .post-remix-btn { font-size: 13px; padding: 13px; }
  .post-remix-btn i { font-size: 15px; }

  /* EDITOR */
  .editor-toolbar { gap: 5px; padding: 8px 10px; }
  .editor-toolbar label { font-size: 9px; }
  .editor-toolbar input[type="text"],
  .editor-toolbar select { font-size: 10.5px; max-width: 88px; }
  .tool-btn { font-size: 10px; padding: 4px 9px; }
  .done-edit-btn { font-size: 11px; }

  /* PREVIEW CARD */
  .preview-card { aspect-ratio: 4/5; }
  .preview-label { font-size: 8.5px; }
  .preview-placeholder { font-size: 11px; }
  .preview-placeholder i { font-size: 27px; }

  /* REMIXER WIDGET */
  .remixer-widget { padding: 15px 12px 12px; gap: 7px; }
  .remixer-pic-wrap img { width: 64px; height: 64px; }
  .remixer-status-dot { width: 11px; height: 11px; }
  .remixer-name { font-size: 13px; }
  .remixer-handle { font-size: 9.5px; }
  .remixer-stats-row .sv { font-size: 12px; }
  .remixer-stats-row .sl { font-size: 8px; }
  .view-profile-btn { font-size: 10px; padding: 6px 14px; }

  /* MY POSTS / QUICK ACCESS */
  .my-posts-grid { gap: 3px; padding: 8px; }
  .my-post-thumb .type-badge { font-size: 7px; }
  .posts-empty { font-size: 10.5px; }
  .posts-empty i { font-size: 20px; }
  .quick-access-grid { gap: 4px; padding: 8px; }
  .qa-btn { font-size: 8.5px; padding: 8px 3px; }
  .qa-btn i { font-size: 15px; }

  /* ETIQUETTE */
  .etiquette-item { padding: 8px 11px; gap: 8px; }
  .etiquette-icon { width: 25px; height: 25px; font-size: 12px; }
  .etiquette-text { font-size: 9.5px; }
  .etiquette-text strong { font-size: 9.5px; }

  /* PANEL HEADERS */
  .panel-hdr { font-size: 10px; padding: 11px 13px 9px; }
  .panel-hdr i { font-size: 13px; }

  /* MESSAGES */
  .success-msg, .error-msg { font-size: 11.5px; padding: 10px 13px; }
}


/* ═══════════════════════════════════════
   RESPONSIVE — 480px
═══════════════════════════════════════ */
@media (max-width: 480px) {

  .remix-layout {
    gap: 10px;
    margin: 10px auto 16px;
    padding: 0 10px 60px;
  }

  /* TOPBAR */
  .remix-topbar { padding: 9px 12px; gap: 7px; }
  .back-pill { width: 29px; height: 29px; font-size: 15px; }
  .remix-badge-pill { font-size: 10px; padding: 4px 10px; gap: 5px; }
  .remix-badge-pill i { font-size: 12px; }
  .chain-pill { font-size: 8.5px; padding: 4px 8px; }

  /* CREATOR COMPARE */
  .compare-avatar img { width: 44px; height: 44px; }
  .compare-name { font-size: 9.5px; }
  .compare-handle { font-size: 8px; }
  .compare-role { font-size: 7px; padding: 2px 7px; }
  .compare-arrow-icon { width: 22px; height: 22px; font-size: 10px; }
  .compare-arrow-label { font-size: 7px; }

  /* ORIG STATS GRID */
  .orig-stats-grid { gap: 3px; padding: 7px 9px 9px; }
  .orig-stat { padding: 6px 4px; border-radius: 8px; }
  .orig-stat-val { font-size: 11px; }
  .orig-stat-lbl { font-size: 7px; }

  /* CHAIN */
  .chain-wrap { padding: 8px 10px; }
  .chain-dot { width: 18px; height: 18px; font-size: 8.5px; }
  .chain-info { font-size: 9px; }
  .chain-info strong { font-size: 9.5px; }
  .chain-count-badge { font-size: 8px; padding: 2px 6px; }

  /* HASHTAG CHIPS */
  .hashtag-chips { padding: 8px 9px 10px; gap: 4px; }
  .htag-chip { font-size: 9px; padding: 3px 8px; }

  /* OTHER REMIX GRID */
  .other-remix-grid { padding: 6px; }
  .other-remix-user { font-size: 7px; }

  /* TIP CARD */
  .tip-card { padding: 9px 10px; gap: 7px; }
  .tip-icon { width: 24px; height: 24px; font-size: 12px; border-radius: 7px; }
  .tip-text { font-size: 10px; }
  .tip-text strong { font-size: 10px; }
  .tip-nav { padding: 0 9px 9px; gap: 4px; }
  .tip-nav button { font-size: 9.5px; padding: 4px; }

  /* TRENDING */
  .trending-tag-name { font-size: 10px; }
  .trending-tag-cnt { font-size: 8px; }
  .trending-tag-item { padding: 5px 6px; gap: 6px; }

  /* ORIGINAL PREVIEW */
  .original-preview-top { padding: 9px 11px; gap: 7px; }
  .orig-avatar-sm { width: 28px; height: 28px; }
  .orig-meta-name { font-size: 10.5px; }
  .orig-meta-label { font-size: 8px; }
  .original-media-wrap { max-height: 200px; }
  .orig-caption-strip { font-size: 10px; padding: 8px 11px; }
  .orig-stats-strip { padding: 6px 11px 9px; gap: 9px; }
  .orig-stat-s { font-size: 9px; }
  .orig-stat-s i { font-size: 10px; }

  /* REMIX CARD */
  .remix-card { padding: 13px; border-radius: 16px; }
  .remix-card-title { margin-bottom: 13px; padding-bottom: 10px; }
  .remix-card-title h3 { font-size: 12px; }
  .remix-card-title i { font-size: 15px; }
  .remix-card-title span { font-size: 9px; }
  .media-tabs { padding: 3px; gap: 2px; border-radius: 10px; }
  .media-tabs button { font-size: 10.5px; padding: 7px 5px; border-radius: 8px; }
  .remix-upload-area { padding: 18px 11px; border-radius: 13px; margin-bottom: 13px; }
  .remix-upload-area i { font-size: 22px; margin-bottom: 6px; }
  .remix-upload-area p { font-size: 10.5px; }
  .remix-upload-area small { font-size: 9px; }
  #captionContainer { font-size: 11.5px; padding: 10px; min-height: 72px; border-radius: 10px; margin-bottom: 10px; }
  .remix-note-input { font-size: 11px; padding: 8px 11px; height: 44px; border-radius: 10px; margin-bottom: 11px; }
  .caption-rules { gap: 5px; margin-bottom: 11px; }
  .cap-rule { font-size: 9px; padding: 2px 7px; }
  .cap-rule i { font-size: 10px; }
  .post-remix-btn { font-size: 12.5px; padding: 12px; border-radius: 11px; gap: 6px; }
  .post-remix-btn i { font-size: 14px; }

  /* EDITOR */
  .editor-toolbar { gap: 4px; padding: 7px 9px; }
  .editor-toolbar label { font-size: 8.5px; }
  .editor-toolbar input[type="text"],
  .editor-toolbar select { font-size: 10px; max-width: 76px; padding: 3px 6px; }
  .editor-toolbar input[type="range"] { width: 58px; }
  .editor-toolbar input[type="color"] { width: 26px; height: 22px; }
  .tool-btn { font-size: 9.5px; padding: 4px 8px; border-radius: 6px; }
  .editor-section-label { font-size: 8px; }
  .sticker-btn { font-size: 19px; }
  .done-edit-btn { font-size: 10.5px; padding: 8px; }

  /* PREVIEW */
  .preview-label { font-size: 8px; padding: 2px 7px; }
  .preview-placeholder { font-size: 10.5px; }
  .preview-placeholder i { font-size: 24px; }

  /* REMIXER WIDGET */
  .remixer-widget { padding: 13px 10px 10px; gap: 6px; }
  .remixer-pic-wrap img { width: 56px; height: 56px; }
  .remixer-status-dot { width: 10px; height: 10px; }
  .remixer-name { font-size: 12px; }
  .remixer-handle { font-size: 9px; }
  .remixer-stats-row { gap: 6px; }
  .remixer-stats-row .sv { font-size: 11.5px; }
  .remixer-stats-row .sl { font-size: 7.5px; }
  .remixer-divider { font-size: 10px; }
  .view-profile-btn { font-size: 9.5px; padding: 5px 12px; }

  /* MY POSTS / QUICK ACCESS */
  .my-posts-grid { gap: 3px; padding: 7px; }
  .quick-access-grid { gap: 3px; padding: 7px; }
  .qa-btn { font-size: 8px; padding: 7px 2px; border-radius: 9px; }
  .qa-btn i { font-size: 14px; }

  /* ETIQUETTE */
  .etiquette-item { padding: 7px 10px; gap: 7px; }
  .etiquette-icon { width: 23px; height: 23px; font-size: 11px; border-radius: 6px; }
  .etiquette-text { font-size: 9px; }
  .etiquette-text strong { font-size: 9px; }

  /* PANEL HEADERS */
  .panel-hdr { font-size: 9.5px; padding: 10px 11px 8px; letter-spacing: 0.8px; }
  .panel-hdr i { font-size: 12px; }

  /* MESSAGES */
  .success-msg, .error-msg { font-size: 11px; padding: 9px 12px; border-radius: 10px; gap: 6px; }
}


/* ═══════════════════════════════════════
   RESPONSIVE — 360px
═══════════════════════════════════════ */
@media (max-width: 360px) {

  .remix-layout {
    gap: 8px;
    margin: 8px auto 12px;
    padding: 0 8px 54px;
  }

  /* TOPBAR */
  .remix-topbar { padding: 8px 10px; gap: 6px; }
  .back-pill { width: 26px; height: 26px; font-size: 13px; }
  .remix-badge-pill { font-size: 9px; padding: 3px 9px; gap: 4px; }
  .remix-badge-pill i { font-size: 11px; }
  .chain-pill { font-size: 8px; padding: 3px 7px; gap: 4px; }

  /* CREATOR COMPARE */
  .creator-compare { padding: 11px; }
  .compare-avatar img { width: 40px; height: 40px; }
  .compare-name { font-size: 9px; }
  .compare-handle { font-size: 7.5px; }
  .compare-role { font-size: 6.5px; padding: 1px 6px; }
  .compare-arrow-icon { width: 20px; height: 20px; font-size: 9px; }
  .compare-arrow-label { font-size: 6.5px; }
  .compare-arrow { padding: 0 5px; }

  /* ORIG STATS GRID — 4 col stays but smaller */
  .orig-stats-grid { padding: 6px 8px 8px; gap: 3px; }
  .orig-stat { padding: 5px 3px; border-radius: 7px; }
  .orig-stat-val { font-size: 10.5px; }
  .orig-stat-lbl { font-size: 6.5px; letter-spacing: 0.2px; }

  /* CHAIN */
  .chain-wrap { padding: 7px 9px; }
  .chain-step { gap: 7px; padding: 5px 0; }
  .chain-dot { width: 17px; height: 17px; font-size: 8px; }
  .chain-info { font-size: 8.5px; }
  .chain-info strong { font-size: 9px; }
  .chain-count-badge { font-size: 7.5px; padding: 1px 5px; }

  /* HASHTAG CHIPS */
  .hashtag-chips { padding: 7px 8px 9px; gap: 3px; }
  .htag-chip { font-size: 8.5px; padding: 2px 7px; }

  /* OTHER REMIX GRID */
  .other-remix-grid { padding: 5px; gap: 3px; }
  .other-remix-user { font-size: 6.5px; }

  /* TIP CARD */
  .tip-card { padding: 8px 9px; gap: 6px; }
  .tip-icon { width: 22px; height: 22px; font-size: 11px; border-radius: 6px; }
  .tip-text { font-size: 9.5px; }
  .tip-text strong { font-size: 9.5px; }
  .tip-nav { padding: 0 8px 8px; gap: 3px; }
  .tip-nav button { font-size: 9px; padding: 4px; }

  /* TRENDING */
  .trending-tags-wrap { padding: 5px 7px 9px; }
  .trending-tag-item { padding: 4px 5px; gap: 5px; }
  .trending-tag-name { font-size: 9.5px; }
  .trending-tag-cnt { font-size: 7.5px; }

  /* ORIGINAL PREVIEW */
  .original-preview-top { padding: 8px 10px; gap: 6px; }
  .orig-avatar-sm { width: 26px; height: 26px; }
  .orig-meta-name { font-size: 10px; }
  .orig-meta-label { font-size: 7.5px; }
  .original-media-wrap { max-height: 176px; }
  .orig-caption-strip { font-size: 9.5px; padding: 7px 10px; }
  .orig-stats-strip { padding: 5px 10px 8px; gap: 8px; }
  .orig-stat-s { font-size: 8.5px; }
  .orig-stat-s i { font-size: 9.5px; }

  /* REMIX CARD */
  .remix-card { padding: 11px; border-radius: 14px; }
  .remix-card-title { margin-bottom: 11px; padding-bottom: 9px; }
  .remix-card-title h3 { font-size: 11.5px; }
  .remix-card-title i { font-size: 14px; }
  .remix-card-title span { font-size: 8.5px; }
  .media-tabs { padding: 3px; gap: 2px; border-radius: 9px; }
  .media-tabs button { font-size: 10px; padding: 6px 4px; border-radius: 7px; }
  .remix-upload-area { padding: 15px 10px; border-radius: 11px; margin-bottom: 11px; }
  .remix-upload-area i { font-size: 20px; margin-bottom: 5px; }
  .remix-upload-area p { font-size: 10px; }
  .remix-upload-area small { font-size: 8.5px; }
  #captionContainer { font-size: 11px; padding: 9px; min-height: 64px; border-radius: 9px; margin-bottom: 9px; }
  .remix-note-input { font-size: 10.5px; padding: 7px 10px; height: 40px; border-radius: 9px; margin-bottom: 10px; }
  .caption-rules { gap: 4px; margin-bottom: 10px; }
  .cap-rule { font-size: 8.5px; padding: 2px 6px; }
  .cap-rule i { font-size: 9.5px; }
  .post-remix-btn { font-size: 12px; padding: 11px; border-radius: 10px; gap: 6px; }
  .post-remix-btn i { font-size: 13px; }

  /* EDITOR */
  .editor-toolbar { gap: 3px; padding: 6px 8px; }
  .editor-toolbar label { font-size: 8px; }
  .editor-toolbar input[type="text"],
  .editor-toolbar select { font-size: 9.5px; max-width: 68px; padding: 3px 5px; }
  .editor-toolbar input[type="range"] { width: 50px; }
  .editor-toolbar input[type="color"] { width: 24px; height: 20px; }
  .tool-btn { font-size: 9px; padding: 3px 7px; border-radius: 5px; gap: 2px; }
  .sticker-btn { font-size: 17px; }
  .done-edit-btn { font-size: 10px; padding: 7px; margin: 7px 9px; }

  /* PREVIEW */
  .preview-label { font-size: 7.5px; padding: 2px 6px; border-radius: 6px; }
  .preview-placeholder { font-size: 10px; padding: 15px; }
  .preview-placeholder i { font-size: 22px; }

  /* REMIXER WIDGET */
  .remixer-widget { padding: 11px 9px 9px; gap: 5px; }
  .remixer-pic-wrap img { width: 50px; height: 50px; }
  .remixer-status-dot { width: 9px; height: 9px; }
  .remixer-name { font-size: 11.5px; }
  .remixer-handle { font-size: 8.5px; }
  .remixer-stats-row { gap: 5px; }
  .remixer-stats-row .sv { font-size: 11px; }
  .remixer-stats-row .sl { font-size: 7px; }
  .remixer-divider { font-size: 9.5px; }
  .view-profile-btn { font-size: 9px; padding: 5px 11px; }

  /* MY POSTS / QUICK ACCESS */
  .my-posts-grid { gap: 2px; padding: 6px; }
  .my-post-thumb .type-badge { font-size: 6.5px; }
  .posts-empty { font-size: 10px; }
  .posts-empty i { font-size: 18px; }
  .quick-access-grid { gap: 3px; padding: 6px; }
  .qa-btn { font-size: 7.5px; padding: 6px 2px; border-radius: 8px; }
  .qa-btn i { font-size: 13px; }

  /* ETIQUETTE */
  .etiquette-item { padding: 6px 9px; gap: 6px; }
  .etiquette-icon { width: 21px; height: 21px; font-size: 10px; }
  .etiquette-text { font-size: 8.5px; }
  .etiquette-text strong { font-size: 8.5px; }

  /* PANEL HEADERS */
  .panel-hdr { font-size: 9px; padding: 9px 10px 7px; letter-spacing: 0.7px; gap: 6px; }
  .panel-hdr i { font-size: 11.5px; }

  /* MESSAGES */
  .success-msg, .error-msg { font-size: 10.5px; padding: 8px 11px; }

  /* PANEL CARD */
  .panel-card { border-radius: 16px; }
}


/* ═══════════════════════════════════════
   RESPONSIVE — 320px
═══════════════════════════════════════ */
@media (max-width: 320px) {

  .remix-layout {
    gap: 7px;
    margin: 7px auto 10px;
    padding: 0 7px 48px;
  }

  /* TOPBAR */
  .remix-topbar { padding: 7px 9px; gap: 5px; }
  .back-pill { width: 24px; height: 24px; font-size: 12px; }
  .remix-badge-pill { font-size: 8.5px; padding: 3px 8px; gap: 3px; }
  .remix-badge-pill i { font-size: 10px; }
  .chain-pill { font-size: 7.5px; padding: 3px 6px; gap: 3px; }

  /* CREATOR COMPARE */
  .creator-compare { padding: 9px; }
  .compare-avatar img { width: 36px; height: 36px; }
  .compare-name { font-size: 8.5px; }
  .compare-handle { font-size: 7px; }
  .compare-role { font-size: 6px; padding: 1px 5px; }
  .compare-arrow-icon { width: 18px; height: 18px; font-size: 8px; }
  .compare-arrow-label { font-size: 6px; }
  .compare-arrow { padding: 0 3px; gap: 2px; }

  /* ORIG STATS — switch to 2 col at 320 */
  .orig-stats-grid { grid-template-columns: repeat(2, 1fr); padding: 6px 7px 8px; gap: 4px; }
  .orig-stat-val { font-size: 10px; }
  .orig-stat-lbl { font-size: 6px; }

  /* CHAIN */
  .chain-wrap { padding: 6px 8px; }
  .chain-step { gap: 6px; padding: 4px 0; }
  .chain-dot { width: 16px; height: 16px; font-size: 7.5px; }
  .chain-info { font-size: 8px; }
  .chain-info strong { font-size: 8.5px; }
  .chain-count-badge { font-size: 7px; padding: 1px 5px; }

  /* HASHTAG CHIPS */
  .hashtag-chips { padding: 6px 7px 8px; gap: 3px; }
  .htag-chip { font-size: 8px; padding: 2px 6px; }

  /* OTHER REMIX GRID */
  .other-remix-grid { padding: 4px; gap: 2px; }
  .other-remix-user { font-size: 6px; }

  /* TIP CARD */
  .tip-card { padding: 7px 8px; gap: 5px; }
  .tip-icon { width: 20px; height: 20px; font-size: 10px; }
  .tip-text { font-size: 9px; }
  .tip-text strong { font-size: 9px; }
  .tip-nav { padding: 0 7px 7px; gap: 3px; }
  .tip-nav button { font-size: 8.5px; padding: 3px; }

  /* TRENDING */
  .trending-tags-wrap { padding: 4px 6px 8px; }
  .trending-tag-item { padding: 4px 4px; gap: 4px; border-radius: 6px; }
  .trending-tag-name { font-size: 9px; }
  .trending-tag-cnt { font-size: 7px; }

  /* ORIGINAL PREVIEW */
  .original-preview-top { padding: 7px 9px; gap: 5px; }
  .orig-avatar-sm { width: 24px; height: 24px; border-width: 1.5px; }
  .orig-meta-name { font-size: 9.5px; }
  .orig-meta-label { font-size: 7px; }
  .original-media-wrap { max-height: 155px; }
  .orig-caption-strip { font-size: 9px; padding: 6px 9px; }
  .orig-stats-strip { padding: 5px 9px 7px; gap: 7px; }
  .orig-stat-s { font-size: 8px; gap: 3px; }
  .orig-stat-s i { font-size: 9px; }

  /* REMIX CARD */
  .remix-card { padding: 10px; border-radius: 13px; }
  .remix-card-title { margin-bottom: 10px; padding-bottom: 8px; }
  .remix-card-title h3 { font-size: 11px; }
  .remix-card-title i { font-size: 13px; }
  .remix-card-title span { font-size: 8px; }
  .media-tabs button { font-size: 9.5px; padding: 6px 3px; border-radius: 6px; }
  .remix-upload-area { padding: 13px 9px; border-radius: 10px; margin-bottom: 10px; }
  .remix-upload-area i { font-size: 18px; margin-bottom: 4px; }
  .remix-upload-area p { font-size: 9.5px; }
  .remix-upload-area small { font-size: 8px; }
  #captionContainer { font-size: 10.5px; padding: 8px; min-height: 58px; border-radius: 8px; margin-bottom: 8px; }
  .remix-note-input { font-size: 10px; padding: 6px 9px; height: 37px; border-radius: 8px; margin-bottom: 9px; }
  .caption-rules { gap: 3px; margin-bottom: 9px; }
  .cap-rule { font-size: 8px; padding: 2px 5px; }
  .cap-rule i { font-size: 9px; }
  .post-remix-btn { font-size: 11.5px; padding: 10px; border-radius: 9px; gap: 5px; }
  .post-remix-btn i { font-size: 12px; }

  /* EDITOR */
  .editor-toolbar { gap: 3px; padding: 5px 7px; }
  .editor-toolbar label { font-size: 7.5px; }
  .editor-toolbar input[type="text"],
  .editor-toolbar select { font-size: 9px; max-width: 62px; padding: 2px 5px; }
  .editor-toolbar input[type="range"] { width: 44px; }
  .editor-toolbar input[type="color"] { width: 22px; height: 18px; }
  .tool-btn { font-size: 8.5px; padding: 3px 6px; }
  .sticker-btn { font-size: 15px; }
  .done-edit-btn { font-size: 9.5px; padding: 7px; margin: 6px 8px; }

  /* PREVIEW */
  .preview-label { font-size: 7px; padding: 2px 5px; border-radius: 5px; }
  .preview-placeholder { font-size: 9.5px; padding: 13px; }
  .preview-placeholder i { font-size: 20px; }

  /* REMIXER WIDGET */
  .remixer-widget { padding: 10px 8px 8px; gap: 4px; }
  .remixer-pic-wrap img { width: 44px; height: 44px; border-width: 2px; }
  .remixer-status-dot { width: 8px; height: 8px; }
  .remixer-name { font-size: 11px; }
  .remixer-handle { font-size: 8px; }
  .remixer-stats-row { gap: 4px; }
  .remixer-stats-row .sv { font-size: 10.5px; }
  .remixer-stats-row .sl { font-size: 6.5px; }
  .view-profile-btn { font-size: 8.5px; padding: 4px 10px; }

  /* MY POSTS / QUICK ACCESS */
  .my-posts-grid { gap: 2px; padding: 5px; }
  .quick-access-grid { gap: 2px; padding: 5px; }
  .qa-btn { font-size: 7px; padding: 5px 2px; border-radius: 7px; }
  .qa-btn i { font-size: 12px; }

  /* ETIQUETTE */
  .etiquette-item { padding: 5px 8px; gap: 5px; }
  .etiquette-icon { width: 19px; height: 19px; font-size: 9px; }
  .etiquette-text { font-size: 8px; }
  .etiquette-text strong { font-size: 8px; }

  /* PANEL HEADERS */
  .panel-hdr { font-size: 8.5px; padding: 8px 9px 6px; letter-spacing: 0.6px; gap: 5px; }
  .panel-hdr i { font-size: 10.5px; }

  /* MESSAGES */
  .success-msg, .error-msg { font-size: 10px; padding: 7px 10px; }

  /* PANEL CARD */
  .panel-card { border-radius: 13px; }

  /* NO REMIXES / POSTS EMPTY */
  .no-remixes i, .posts-empty i { font-size: 16px; }
  .no-remixes, .posts-empty { font-size: 9.5px; padding: 14px 10px; }
}
</style>
</head>
<body>

<!-- PARTICLES -->
<div id="particles"></div>

<!-- ═══ REMIX TOP BAR ═══ -->
<div class="remix-topbar">
    <button class="back-pill" onclick="history.back()">
        <i class="ri-arrow-left-s-line"></i>
    </button>
    <div class="remix-badge-pill">
        <i class="ri-repeat-2-line"></i>
        Remix Studio
    </div>
    <span style="font-size:11px;color:rgba(255,255,255,0.3);font-weight:500;">
        Remixing <strong style="color:#ff9500;">@<?php echo htmlspecialchars($original_username); ?></strong>
    </span>
    <div class="chain-pill">
        <i class="ri-links-line" style="font-size:12px;"></i>
        <?php echo $remix_depth; ?> remix<?php echo $remix_depth != 1 ? 'es' : ''; ?> so far
    </div>
</div>

<!-- ═══ THREE-COLUMN LAYOUT ═══ -->
<div class="remix-layout">

    <!-- ═══════════════════
         LEFT PANEL
    ═══════════════════ -->
    <div class="left-panel">

        <!-- CREATOR COMPARISON -->
        <div class="panel-card">
            <div class="panel-hdr mob-collapse-hdr" onclick="toggleCollapse('compareBody','compareIcon')">
                <span><i class="ri-user-follow-line"></i> &nbsp;Creator Pair</span>
                <i class="ri-arrow-down-s-line mob-collapse-icon" id="compareIcon"></i>
            </div>
            <div class="mob-collapse-body open" id="compareBody">
                <div class="creator-compare">
                    <div class="compare-row">
                        <div class="compare-user">
                            <div class="compare-avatar original">
                                <img src="<?php echo htmlspecialchars($original_pic); ?>" onerror="this.src='uploads/mee.jpeg'" alt="<?php echo htmlspecialchars($original_username); ?>">
                            </div>
                            <div class="compare-role orig">Original</div>
                            <div class="compare-name"><?php echo htmlspecialchars($original_username); ?></div>
                            <div class="compare-handle">@<?php echo htmlspecialchars($original_username); ?></div>
                        </div>
                        <div class="compare-arrow">
                            <div class="compare-arrow-icon"><i class="ri-repeat-2-line"></i></div>
                            <div class="compare-arrow-label">remixed<br>by</div>
                        </div>
                        <div class="compare-user">
                            <div class="compare-avatar remixer">
                                <img src="<?php echo htmlspecialchars($my_pic); ?>" onerror="this.src='uploads/mee.jpeg'" alt="<?php echo htmlspecialchars($my_username); ?>">
                            </div>
                            <div class="compare-role remx">You</div>
                            <div class="compare-name"><?php echo htmlspecialchars($my_username); ?></div>
                            <div class="compare-handle">@<?php echo htmlspecialchars($my_nickname ?: $my_username); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Original post stats -->
                <div style="padding:0 13px 5px;font-size:9px;color:rgba(255,255,255,0.3);font-weight:700;text-transform:uppercase;letter-spacing:.8px;border-top:1px solid rgba(255,255,255,0.05);padding-top:10px;">
                    Original Post Performance
                </div>
                <div class="orig-stats-grid">
                    <div class="orig-stat">
                        <div class="orig-stat-val"><?php echo shortNum($orig_likes); ?></div>
                        <div class="orig-stat-lbl">Likes</div>
                    </div>
                    <div class="orig-stat">
                        <div class="orig-stat-val"><?php echo shortNum($orig_reposts); ?></div>
                        <div class="orig-stat-lbl">Reposts</div>
                    </div>
                    <div class="orig-stat">
                        <div class="orig-stat-val"><?php echo shortNum($orig_favs); ?></div>
                        <div class="orig-stat-lbl">Saves</div>
                    </div>
                    <div class="orig-stat">
                        <div class="orig-stat-val"><?php echo shortNum($orig_comments); ?></div>
                        <div class="orig-stat-lbl">Comments</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- REMIX CHAIN -->
        <div class="panel-card">
            <div class="panel-hdr mob-collapse-hdr" onclick="toggleCollapse('chainBody','chainIcon')">
                <span><i class="ri-links-line"></i> &nbsp;Remix Chain</span>
                <i class="ri-arrow-down-s-line mob-collapse-icon" id="chainIcon"></i>
            </div>
            <div class="mob-collapse-body open" id="chainBody">
                <div class="chain-wrap">
                    <div class="chain-step">
                        <div class="chain-dot root">1</div>
                        <div class="chain-info">
                            <strong><?php echo htmlspecialchars($original_username); ?></strong>
                            Posted the original
                        </div>
                        <div class="chain-count-badge"><?php echo $remix_depth; ?> remixes</div>
                    </div>
                    <?php if($remix_depth > 0): ?>
                    <div class="chain-step">
                        <div class="chain-dot root" style="background:rgba(0,87,255,0.15);border-color:#4dabff;color:#4dabff;">…</div>
                        <div class="chain-info">
                            <strong><?php echo $remix_depth; ?> remix<?php echo $remix_depth != 1 ? 'es' : ''; ?></strong>
                            from other creators
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="chain-step">
                        <div class="chain-dot you">★</div>
                        <div class="chain-info">
                            <strong>You (<?php echo htmlspecialchars($my_username); ?>)</strong>
                            Adding your remix now
                        </div>
                    </div>
                </div>
                <div style="padding:0 13px 12px;font-size:10px;color:rgba(255,255,255,0.25);">
                    Always credit @<?php echo htmlspecialchars($original_username); ?> in your caption
                </div>
            </div>
        </div>

        <!-- OTHER REMIXES -->
        <div class="panel-card">
            <div class="panel-hdr mob-collapse-hdr" onclick="toggleCollapse('otherRemixBody','otherRemixIcon')">
                <span><i class="ri-grid-line"></i> &nbsp;Other Remixes</span>
                <i class="ri-arrow-down-s-line mob-collapse-icon" id="otherRemixIcon"></i>
            </div>
            <div class="mob-collapse-body open" id="otherRemixBody">
                <?php if(empty($other_remixes)): ?>
                <div class="no-remixes">
                    <i class="ri-repeat-2-line"></i>
                    No remixes yet.<br>Be the first!
                </div>
                <?php else: ?>
                <div class="other-remix-grid">
                    <?php foreach($other_remixes as $or):
                        $src = ($or['type']==='text'||empty($or['file_name'])) ? 'uploads/Text.jpg' : 'uploads/'.htmlspecialchars($or['file_name']);
                    ?>
                    <div class="other-remix-thumb" title="by @<?php echo htmlspecialchars($or['username']); ?>">
                        <?php if($or['type']==='video'): ?>
                            <video src="<?php echo $src; ?>" muted></video>
                        <?php else: ?>
                            <img src="<?php echo $src; ?>" onerror="this.src='uploads/Text.jpg'">
                        <?php endif; ?>
                        <div class="other-remix-user">@<?php echo htmlspecialchars($or['username']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- MY HASHTAGS -->
        <div class="panel-card">
            <div class="panel-hdr mob-collapse-hdr" onclick="toggleCollapse('htagsBody','htagsIcon')">
                <span><i class="ri-hashtag"></i> &nbsp;My Hashtags</span>
                <i class="ri-arrow-down-s-line mob-collapse-icon" id="htagsIcon"></i>
            </div>
            <div class="mob-collapse-body open" id="htagsBody">
                <?php if(empty($my_tags)): ?>
                <div class="no-remixes">
                    <i class="ri-hashtag"></i>No hashtags yet
                </div>
                <?php else: ?>
                <div class="hashtag-chips">
                    <?php foreach($my_tags as $tag): ?>
                    <button class="htag-chip" onclick="insertHashtag('#<?php echo htmlspecialchars($tag['tag']); ?>')">#<?php echo htmlspecialchars($tag['tag']); ?></button>
                    <?php endforeach; ?>
                </div>
                <div style="padding:0 13px 10px;font-size:9px;color:rgba(255,255,255,0.2);">Tap to add to caption</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TRENDING TAGS -->
        <div class="panel-card">
            <div class="panel-hdr mob-collapse-hdr" onclick="toggleCollapse('trendBody','trendIcon')">
                <span><i class="ri-trending-up-line"></i> &nbsp;Trending Tags</span>
                <i class="ri-arrow-down-s-line mob-collapse-icon" id="trendIcon"></i>
            </div>
            <div class="mob-collapse-body open" id="trendBody">
                <div class="trending-tags-wrap">
                    <?php if(empty($trending_tags)): ?>
                    <div style="text-align:center;padding:10px;font-size:10px;color:rgba(255,255,255,0.2);">No trends this week</div>
                    <?php else: ?>
                    <?php foreach($trending_tags as $i=>$tag):
                        if(empty($tag['tag'])) continue; ?>
                    <div class="trending-tag-item" onclick="insertHashtag('#<?php echo htmlspecialchars($tag['tag']); ?>')">
                        <span style="font-size:9.5px;color:rgba(255,255,255,0.22);font-weight:700;min-width:15px;"><?php echo $i+1; ?></span>
                        <span class="trending-tag-name">#<?php echo htmlspecialchars($tag['tag']); ?></span>
                        <span class="trending-tag-cnt"><?php echo $tag['cnt']; ?> posts</span>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- REMIX TIPS -->
        <div class="panel-card">
            <div class="panel-hdr mob-collapse-hdr" onclick="toggleCollapse('tipsBody','tipsIcon')">
                <span><i class="ri-lightbulb-line"></i> &nbsp;Remix Tips</span>
                <i class="ri-arrow-down-s-line mob-collapse-icon" id="tipsIcon"></i>
            </div>
            <div class="mob-collapse-body" id="tipsBody">
                <div class="tip-card" id="tipCard">
                    <div class="tip-icon"><i class="ri-lightbulb-flash-line"></i></div>
                    <div class="tip-text">
                        <strong id="tipTitle"></strong>
                        <span id="tipBody"></span>
                    </div>
                </div>
                <div class="tip-nav">
                    <button onclick="rotateTip(-1)">← Prev</button>
                    <button onclick="rotateTip(1)">Next →</button>
                </div>
            </div>
        </div>

    </div><!-- /left-panel -->


    <!-- ═══════════════════
         CENTER PANEL
    ═══════════════════ -->
    <div class="center-panel">

        <?php if($success_message): ?>
        <div class="success-msg"><i class="ri-check-circle-line"></i><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if($error_message): ?>
        <div class="error-msg"><i class="ri-error-warning-line"></i><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <!-- ORIGINAL POST CARD -->
        <div class="original-preview-card">
            <div class="original-preview-top">
                <img class="orig-avatar-sm"
                     src="<?php echo htmlspecialchars($original_pic); ?>"
                     onerror="this.src='uploads/mee.jpeg'"
                     alt="<?php echo htmlspecialchars($original_username); ?>">
                <div class="orig-meta">
                    <div class="orig-meta-name"><?php echo htmlspecialchars($original_username); ?></div>
                    <div class="orig-meta-label">↩️ Original you're remixing</div>
                </div>
                <a href="profile.php?user_id=<?php echo $original_user_id; ?>"
                   style="font-size:10px;color:rgba(255,149,0,0.7);font-weight:700;text-decoration:none;flex-shrink:0;padding:5px 10px;border-radius:14px;border:1px solid rgba(255,149,0,0.25);background:rgba(255,149,0,0.08);">
                    View Profile
                </a>
            </div>
            <div class="original-media-wrap">
                <?php if($original_type === 'image'): ?>
                    <img src="uploads/<?php echo htmlspecialchars($original_file); ?>" alt="Original">
                <?php elseif($original_type === 'video'): ?>
                    <video src="uploads/<?php echo htmlspecialchars($original_file); ?>" autoplay muted loop playsinline></video>
                <?php endif; ?>
            </div>
            <?php if(!empty($original_caption)): ?>
            <div class="orig-caption-strip"><?php echo htmlspecialchars(mb_substr($original_caption, 0, 120)); ?></div>
            <?php endif; ?>
            <div class="orig-stats-strip">
                <div class="orig-stat-s"><i class="ri-heart-3-fill"></i><span><?php echo shortNum($orig_likes); ?></span> Likes</div>
                <div class="orig-stat-s"><i class="ri-repeat-line"></i><span><?php echo shortNum($orig_reposts); ?></span> Reposts</div>
                <div class="orig-stat-s"><i class="ri-bookmark-fill"></i><span><?php echo shortNum($orig_favs); ?></span> Saved</div>
                <div class="orig-stat-s"><i class="ri-chat-3-line"></i><span><?php echo shortNum($orig_comments); ?></span> Comments</div>
            </div>
        </div>

        <!-- REMIX FORM CARD -->
        <div class="remix-card">
            <div class="remix-card-title">
                <i class="ri-repeat-2-line"></i>
                <h3>Create Your Remix</h3>
                <span>Use original · Edit it · Or upload your own</span>
            </div>

            <form id="remixForm" method="POST" enctype="multipart/form-data" onsubmit="return handleRemixSubmit()">
                <input type="hidden" name="caption_hidden" id="caption_hidden">
                <input type="hidden" name="edited_image_data" id="edited_image_data">
                <input type="hidden" name="is_remix" value="1">

                <!-- MEDIA MODE TABS -->
                <div class="media-tabs">
                    <button type="button" id="useOriginalBtn" class="active" onclick="setMediaMode('original')">
                        <i class="ri-image-line" style="margin-right:4px;"></i>Use Original
                    </button>
                    <button type="button" id="editItBtn" onclick="setMediaMode('edit')">
                        <i class="ri-edit-line" style="margin-right:4px;"></i>Edit It
                    </button>
                    <button type="button" id="uploadNewBtn" onclick="setMediaMode('upload')">
                        <i class="ri-upload-cloud-line" style="margin-right:4px;"></i>Upload New
                    </button>
                </div>

                <!-- UPLOAD NEW FILE AREA (hidden by default) -->
                <div id="uploadSection" style="display:none;">
                    <label for="remixFileInput" class="remix-upload-area">
                        <i class="ri-add-circle-line"></i>
                        <p id="fileStatus">Drop your image, GIF or video here</p>
                        <small>JPG, PNG, GIF, MP4, WebM, MOV • Max 50MB</small>
                        <input type="file" name="remix_file" id="remixFileInput" onchange="handleFileSelect()" style="display:none;" accept="image/*,video/*,.gif">
                    </label>
                </div>

                <!-- EDITOR PANEL (hidden by default) -->
                <div id="editorPanel">
                    <div class="editor-toolbar">
                        <span class="editor-section-label">Text</span>
                        <label>Content<input type="text" id="textInput" placeholder="Add text…"></label>
                        <label>Font
                            <select id="fontFamily">
                                <option value="Poppins">Poppins</option>
                                <option value="Impact">Impact</option>
                                <option value="Arial Black">Arial Black</option>
                                <option value="Comic Sans MS">Comic Sans</option>
                                <option value="Georgia">Georgia</option>
                                <option value="Courier New">Courier</option>
                            </select>
                        </label>
                        <label>Size<input type="range" id="fontSize" min="12" max="120" value="36"></label>
                        <label>Color<input type="color" id="fontColor" value="#ffffff"></label>
                        <label>Stroke<input type="color" id="strokeColor" value="#000000"></label>
                        <button type="button" class="tool-btn" onclick="addText()"><i class="ri-text"></i> Add</button>
                        <span class="editor-section-label" style="margin-left:4px;">Tools</span>
                        <button type="button" class="tool-btn" onclick="toggleStickers()"><i class="ri-emotion-line"></i> Stickers</button>
                        <button type="button" class="tool-btn" onclick="toggleCrop()"><i class="ri-crop-line"></i> Crop</button>
                        <button type="button" class="tool-btn danger" onclick="deleteSelected()"><i class="ri-delete-bin-line"></i> Del</button>
                        <button type="button" class="tool-btn danger" onclick="resetEditor()"><i class="ri-refresh-line"></i> Reset</button>
                    </div>
                    <div class="canvas-wrapper" id="canvasWrapper">
                        <canvas id="fabricCanvas"></canvas>
                    </div>
                    <div class="sticker-panel" id="stickerPanel">
                        <?php
                        $stickers=['😂','💀','🔥','👀','😭','🤣','💯','🫡','😤','🤡','👏','😈','🥶','💅','🤌','😎','🥴','🫠','🗿','⚡','❤️‍🔥','🎭','👾','🤯','🤦','🫣','😏','🧠','🦋','✨'];
                        foreach($stickers as $s) echo "<button type='button' class='sticker-btn' onclick='addSticker(\"$s\")'>$s</button>";
                        ?>
                    </div>
                    <div class="crop-controls" id="cropControls">
                        <span style="font-size:10px;color:rgba(255,255,255,0.4);">Drag to reposition. Scale with handles.</span>
                        <button type="button" class="tool-btn confirm" onclick="applyCrop()"><i class="ri-check-line"></i> Apply</button>
                        <button type="button" class="tool-btn" onclick="cancelCrop()">Cancel</button>
                    </div>
                    <button type="button" class="done-edit-btn" onclick="finalizeEdit()">
                        <i class="ri-check-double-line"></i> Done Editing — Use This Version
                    </button>
                </div>

                <!-- CAPTION -->
                <div id="captionContainer" contenteditable="true" oninput="syncCaption()"></div>

                <!-- CAPTION REQUIREMENTS -->
                <div class="caption-rules">
                    <div class="cap-rule" id="ruleIcon"><i class="ri-arrow-go-back-line"></i> Starts with ↩️</div>
                    <div class="cap-rule" id="ruleTag"><i class="ri-at-line"></i> @<?php echo htmlspecialchars($original_username); ?></div>
                    <div class="cap-rule" id="ruleHash"><i class="ri-hashtag"></i> #hashtag</div>
                </div>

                <!-- REMIX NOTE -->
                <textarea class="remix-note-input" name="remix_note" id="remix_note" placeholder="Add a note about your remix (optional — shows only to original creator)…"></textarea>

                <!-- POST BUTTON -->
                <button type="submit" name="upload" class="post-remix-btn">
                    <i class="ri-send-plane-fill"></i>
                    Post Remix
                </button>
            </form>
        </div>

        <!-- LIVE PREVIEW -->
        <div class="remix-card" style="padding:16px;">
            <div style="font-size:10.5px;font-weight:700;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:1px;margin-bottom:11px;display:flex;align-items:center;gap:6px;">
                <i class="ri-eye-line" style="color:#FF2D55;font-size:14px;"></i> Live Preview
            </div>
            <div id="mediaPreview" class="preview-card">
                <div class="preview-placeholder">
                    <i class="ri-repeat-2-line"></i>
                    Your remix preview appears here
                </div>
            </div>
        </div>

    </div><!-- /center-panel -->


    <!-- ═══════════════════
         RIGHT PANEL
    ═══════════════════ -->
    <div class="right-panel">

        <!-- REMIXER PROFILE CARD -->
        <div class="panel-card">
            <div class="remixer-widget">
                <div class="remixer-pic-wrap">
                    <img src="<?php echo htmlspecialchars($my_pic); ?>"
                         onerror="this.src='uploads/mee.jpeg'"
                         alt="<?php echo htmlspecialchars($my_username); ?>"
                         onclick="window.location.href='profile.php'">
                    <div class="remixer-status-dot"></div>
                </div>
                <div class="remixer-name"><?php echo htmlspecialchars($my_username); ?></div>
                <div class="remixer-handle">@<?php echo htmlspecialchars($my_nickname ?: $my_username); ?></div>
                <div class="remixer-stats-row">
                    <a href="tracks.php?user_id=<?php echo $user_id; ?>&type=followers">
                        <span class="sv"><?php echo shortNum($fc); ?></span>
                        <span class="sl">Trackers</span>
                    </a>
                    <span class="remixer-divider">|</span>
                    <a href="tracks.php?user_id=<?php echo $user_id; ?>&type=following">
                        <span class="sv"><?php echo shortNum($fg); ?></span>
                        <span class="sl">Tracking</span>
                    </a>
                    <span class="remixer-divider">|</span>
                    <a href="meme_uploads.php?user_id=<?php echo $user_id; ?>">
                        <span class="sv"><?php echo shortNum($pc); ?></span>
                        <span class="sl">Posts</span>
                    </a>
                </div>
                <a href="profile.php" class="view-profile-btn">
                    <i class="ri-user-3-line"></i> Your Profile
                </a>
            </div>
        </div>

        <!-- REMIX ETIQUETTE -->
        <div class="panel-card">
            <div class="panel-hdr mob-collapse-hdr" onclick="toggleCollapse('etiquetteBody','etiquetteIcon')">
                <span><i class="ri-shield-star-line"></i> &nbsp;Remix Etiquette</span>
                <i class="ri-arrow-down-s-line mob-collapse-icon" id="etiquetteIcon"></i>
            </div>
            <div class="mob-collapse-body open" id="etiquetteBody">
                <div class="etiquette-item">
                    <div class="etiquette-icon green"><i class="ri-check-line"></i></div>
                    <div class="etiquette-text">
                        <strong>Always credit the original</strong>
                        Tag @<?php echo htmlspecialchars($original_username); ?> in your caption — it's both respectful and required.
                    </div>
                </div>
                <div class="etiquette-item">
                    <div class="etiquette-icon green"><i class="ri-check-line"></i></div>
                    <div class="etiquette-text">
                        <strong>Add your own twist</strong>
                        A great remix adds something new — a caption flip, edit, or reaction.
                    </div>
                </div>
                <div class="etiquette-item">
                    <div class="etiquette-icon yellow"><i class="ri-information-line"></i></div>
                    <div class="etiquette-text">
                        <strong>↩️ icon is mandatory</strong>
                        Your caption must start with ↩️ so it's clear it's a remix.
                    </div>
                </div>
                <div class="etiquette-item">
                    <div class="etiquette-icon red"><i class="ri-close-line"></i></div>
                    <div class="etiquette-text">
                        <strong>Don't strip attribution</strong>
                        Removing the original creator's tag violates community rules.
                    </div>
                </div>
            </div>
        </div>

        <!-- QUICK ACCESS -->
        <div class="panel-card">
            <div class="panel-hdr mob-collapse-hdr" onclick="toggleCollapse('qaBody','qaIcon')">
                <span><i class="ri-apps-line"></i> &nbsp;Quick Access</span>
                <i class="ri-arrow-down-s-line mob-collapse-icon" id="qaIcon"></i>
            </div>
            <div class="mob-collapse-body open" id="qaBody">
                <div class="quick-access-grid">
                    <a href="upload.php" class="qa-btn"><i class="ri-add-circle-line"></i>Upload</a>
                    <a href="home.php" class="qa-btn"><i class="ri-home-5-line"></i>Home</a>
                    <a href="profile.php" class="qa-btn"><i class="ri-user-3-line"></i>Profile</a>
                    <a href="search.php" class="qa-btn"><i class="ri-search-line"></i>Search</a>
                    <a href="community.php" class="qa-btn"><i class="ri-team-line"></i>Pods</a>
                    <a href="setting.php" class="qa-btn"><i class="ri-settings-3-line"></i>Settings</a>
                </div>
            </div>
        </div>

        <!-- MY RECENT POSTS (for inspiration) -->
        <div class="panel-card">
            <div class="panel-hdr mob-collapse-hdr" onclick="toggleCollapse('myPostsBody','myPostsIcon')">
                <span><i class="ri-grid-line"></i> &nbsp;Your Recent Posts</span>
                <i class="ri-arrow-down-s-line mob-collapse-icon" id="myPostsIcon"></i>
            </div>
            <div class="mob-collapse-body open" id="myPostsBody">
                <?php if(!$my_posts || $my_posts->num_rows === 0): ?>
                <div class="posts-empty">
                    <i class="ri-folder-open-line"></i>No posts yet
                </div>
                <?php else: ?>
                <div class="my-posts-grid">
                    <?php while($p = $my_posts->fetch_assoc()):
                        $fn  = $p['file_name'];
                        $pt  = $p['type'];
                        $src = ($pt==='text'||empty($fn)||$fn==='0') ? 'uploads/Text.jpg' : 'uploads/'.htmlspecialchars($fn);
                    ?>
                    <div class="my-post-thumb" onclick="window.location.href='profile.php'" title="<?php echo htmlspecialchars(mb_substr($p['caption'],0,30)); ?>">
                        <?php if($pt === 'video'): ?>
                            <video src="<?php echo $src; ?>" muted></video>
                            <div class="type-badge"><i class="ri-video-fill"></i></div>
                        <?php else: ?>
                            <img src="<?php echo $src; ?>" onerror="this.src='uploads/Text.jpg'" alt="">
                            <?php if($pt==='text'): ?><div class="type-badge">TXT</div><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                </div>
                <div style="padding:0 9px 11px;text-align:center;">
                    <a href="meme_uploads.php?user_id=<?php echo $user_id; ?>"
                       style="font-size:10px;color:rgba(255,45,85,0.65);font-weight:600;text-decoration:none;">
                        View all posts →
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- WHAT MAKES A GREAT REMIX -->
        <div class="panel-card">
            <div class="panel-hdr mob-collapse-hdr" onclick="toggleCollapse('greatRemixBody','greatRemixIcon')">
                <span><i class="ri-sparkling-line"></i> &nbsp;Great Remix Formula</span>
                <i class="ri-arrow-down-s-line mob-collapse-icon" id="greatRemixIcon"></i>
            </div>
            <div class="mob-collapse-body open" id="greatRemixBody">
                <div style="padding:13px 14px;display:flex;flex-direction:column;gap:9px;">
                    <?php
                    $steps = [
                        ['ri-image-edit-line','Add a visual twist','Overlay text, crop to focus, or sticker-react to the original media.'],
                        ['ri-chat-quote-line','Flip the caption','Subvert the original meaning with your own take — this gets the most engagement.'],
                        ['ri-hashtag','Use trending tags','Attach 3-5 hashtags — mixing trending + niche drives the best discovery.'],
                        ['ri-at-line','Mention creators','Beyond @<?php echo htmlspecialchars($original_username); ?>, tag anyone relevant to boost cross-reach.'],
                    ];
                    foreach($steps as $i=>$step): ?>
                    <div style="display:flex;align-items:flex-start;gap:9px;">
                        <div style="width:22px;height:22px;border-radius:50%;background:rgba(255,45,85,0.15);border:1px solid rgba(255,45,85,0.25);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#FF2D55;flex-shrink:0;"><?php echo $i+1; ?></div>
                        <div>
                            <div style="font-size:11px;font-weight:700;color:rgba(255,255,255,0.8);margin-bottom:1px;"><?php echo $step[1]; ?></div>
                            <div style="font-size:10px;color:rgba(255,255,255,0.38);line-height:1.4;"><?php echo $step[2]; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div><!-- /right-panel -->

</div><!-- /remix-layout -->

<script>
/* ═══════════════════════
   PARTICLES
═══════════════════════ */
const container = document.getElementById('particles');
for(let i=0;i<70;i++){
    const p=document.createElement('div');
    p.className='particle';
    const size=Math.random()*2+3;
    p.style.width=size+'px'; p.style.height=size+'px';
    p.style.left=Math.random()*100+'vw'; p.style.top=Math.random()*100+'vh';
    p.style.setProperty('--x-move',(Math.random()*200-100)+'px');
    p.style.setProperty('--y-move',(Math.random()*-300-100)+'px');
    p.style.animationDuration=(Math.random()*15+10)+'s';
    p.style.opacity=Math.random()*0.5+0.2;
    // Slightly pinkish tint for remix page particles
    p.style.background = Math.random()>0.7 ? 'rgba(255,45,85,0.9)' : 'white';
    container.appendChild(p);
}

/* ═══════════════════════
   COLLAPSE
═══════════════════════ */
function toggleCollapse(bodyId,iconId){
    const body=document.getElementById(bodyId);
    const icon=document.getElementById(iconId);
    if(!body)return;
    body.classList.toggle('open');
    if(icon)icon.classList.toggle('open');
}

/* ═══════════════════════
   MEDIA MODE
═══════════════════════ */
let currentMode = 'original';
let fabricCanvas = null, originalImageObj = null, isCropping = false, cropRect = null, editFinalized = false;

const originalSrc = 'uploads/<?php echo addslashes($original_file); ?>';
const originalType = '<?php echo $original_type; ?>';

function setMediaMode(mode){
    currentMode = mode;
    document.querySelectorAll('.media-tabs button').forEach(b=>b.classList.remove('active'));
    document.getElementById('useOriginalBtn').classList[mode==='original'?'add':'remove']('active');
    document.getElementById('editItBtn').classList[mode==='edit'?'add':'remove']('active');
    document.getElementById('uploadNewBtn').classList[mode==='upload'?'add':'remove']('active');

    document.getElementById('uploadSection').style.display = mode==='upload' ? 'block' : 'none';
    document.getElementById('editorPanel').style.display   = mode==='edit'   ? 'block' : 'none';

    if(mode==='edit' && !fabricCanvas && originalType==='image'){
        initFabricCanvas(originalSrc);
    }
    if(mode==='original'){
        document.getElementById('edited_image_data').value = '';
        editFinalized = false;
        updateOriginalPreview();
    }
}

function updateOriginalPreview(){
    const preview = document.getElementById('mediaPreview');
    preview.innerHTML = '';
    if(originalType==='video'){
        const v = document.createElement('video');
        v.src = originalSrc; v.autoplay=true; v.muted=true; v.loop=true; v.playsInline=true;
        preview.appendChild(v);
    } else {
        const img = document.createElement('img');
        img.src = originalSrc; img.onerror = ()=>{ img.src='uploads/Text.jpg'; };
        preview.appendChild(img);
    }
    preview.innerHTML += '<span class="preview-label">ORIGINAL</span>';
}

// Show original on load
updateOriginalPreview();

/* ═══════════════════════
   FILE SELECT
═══════════════════════ */
function handleFileSelect(){
    const file = document.getElementById('remixFileInput').files[0];
    if(!file)return;
    document.getElementById('fileStatus').innerText = file.name;
    const url = URL.createObjectURL(file);
    const preview = document.getElementById('mediaPreview');
    preview.innerHTML = '';
    if(file.type.startsWith('video')){
        const v=document.createElement('video');
        v.src=url; v.autoplay=true; v.muted=true; v.loop=true; v.playsInline=true;
        preview.appendChild(v);
        preview.innerHTML += '<span class="preview-label">YOUR VIDEO</span>';
    } else {
        const img=document.createElement('img');
        img.src=url;
        preview.appendChild(img);
        preview.innerHTML += '<span class="preview-label">YOUR IMAGE</span>';
    }
}

/* ═══════════════════════
   FABRIC EDITOR
═══════════════════════ */
function initFabricCanvas(imageUrl){
    const wrapper=document.getElementById('canvasWrapper');
    const maxW=wrapper.clientWidth||480, maxH=360;
    if(fabricCanvas){fabricCanvas.dispose();fabricCanvas=null;}
    const el=document.getElementById('fabricCanvas');
    el.width=maxW; el.height=maxH;
    fabricCanvas=new fabric.Canvas('fabricCanvas',{selection:true,preserveObjectStacking:true});
    fabric.Image.fromURL(imageUrl,function(img){
        const scale=Math.min(maxW/img.width,maxH/img.height);
        img.set({scaleX:scale,scaleY:scale,left:(maxW-img.width*scale)/2,top:(maxH-img.height*scale)/2,selectable:false,evented:false});
        fabricCanvas.add(img);fabricCanvas.sendToBack(img);fabricCanvas.renderAll();
        originalImageObj=img;
        // Update preview too
        updateEditorPreview();
    },{crossOrigin:'anonymous'});
}

function addText(){
    if(!fabricCanvas)return;
    const txt=document.getElementById('textInput').value||'My Remix';
    const size=parseInt(document.getElementById('fontSize').value)||36;
    const color=document.getElementById('fontColor').value;
    const stroke=document.getElementById('strokeColor').value;
    const font=document.getElementById('fontFamily').value;
    const text=new fabric.IText(txt,{left:fabricCanvas.width/2,top:fabricCanvas.height/2,originX:'center',originY:'center',fontSize:size,fill:color,fontFamily:font,stroke:stroke,strokeWidth:size>40?2:1,fontWeight:'bold',shadow:'rgba(0,0,0,0.6) 2px 2px 4px',editable:true});
    fabricCanvas.add(text);fabricCanvas.setActiveObject(text);fabricCanvas.renderAll();
}

function toggleStickers(){
    document.getElementById('stickerPanel').classList.toggle('open');
    document.getElementById('cropControls').classList.remove('open');
    if(isCropping)cancelCrop();
}
function addSticker(emoji){
    if(!fabricCanvas)return;
    const text=new fabric.Text(emoji,{left:fabricCanvas.width/2,top:fabricCanvas.height/2,originX:'center',originY:'center',fontSize:56,selectable:true});
    fabricCanvas.add(text);fabricCanvas.setActiveObject(text);fabricCanvas.renderAll();
}
function deleteSelected(){
    if(!fabricCanvas)return;
    const active=fabricCanvas.getActiveObject();
    if(active&&active!==originalImageObj){fabricCanvas.remove(active);fabricCanvas.renderAll();}
}
function toggleCrop(){
    if(!fabricCanvas)return;
    if(isCropping){cancelCrop();return;}
    document.getElementById('stickerPanel').classList.remove('open');
    isCropping=true;
    document.getElementById('cropControls').classList.add('open');
    cropRect=new fabric.Rect({left:40,top:40,width:fabricCanvas.width-80,height:fabricCanvas.height-80,fill:'rgba(0,0,0,0)',stroke:'#FF2D55',strokeWidth:2,strokeDashArray:[6,4],selectable:true,hasRotatingPoint:false,cornerColor:'#FF2D55',cornerSize:10,transparentCorners:false});
    fabricCanvas.add(cropRect);fabricCanvas.setActiveObject(cropRect);fabricCanvas.renderAll();
}
function applyCrop(){
    if(!fabricCanvas||!cropRect)return;
    const rect=cropRect.getBoundingRect();
    const dataURL=fabricCanvas.toDataURL({format:'jpeg',quality:0.92,left:rect.left,top:rect.top,width:rect.width,height:rect.height});
    fabricCanvas.remove(cropRect);cropRect=null;isCropping=false;
    document.getElementById('cropControls').classList.remove('open');
    initFabricCanvas(dataURL);
}
function cancelCrop(){
    if(!fabricCanvas||!cropRect){isCropping=false;document.getElementById('cropControls').classList.remove('open');return;}
    fabricCanvas.remove(cropRect);cropRect=null;isCropping=false;
    document.getElementById('cropControls').classList.remove('open');fabricCanvas.renderAll();
}
function resetEditor(){
    if(!originalImageObj)return;
    const src=originalImageObj.getSrc?originalImageObj.getSrc():originalImageObj._element.src;
    editFinalized=false;
    document.getElementById('edited_image_data').value='';
    initFabricCanvas(src);
    document.getElementById('stickerPanel').classList.remove('open');
    document.getElementById('cropControls').classList.remove('open');
    isCropping=false;cropRect=null;
    const btn=document.querySelector('.done-edit-btn');
    btn.innerHTML='<i class="ri-check-double-line"></i> Done Editing — Use This Version';
    btn.style.background='';
}
function updateEditorPreview(){
    if(!fabricCanvas)return;
    const preview=document.getElementById('mediaPreview');
    fabricCanvas.discardActiveObject();fabricCanvas.renderAll();
    const dataURL=fabricCanvas.toDataURL({format:'jpeg',quality:0.85});
    preview.innerHTML='<span class="preview-label">EDITED</span>';
    const img=document.createElement('img');img.src=dataURL;preview.insertBefore(img,preview.firstChild);
}
function finalizeEdit(){
    if(!fabricCanvas)return;
    fabricCanvas.discardActiveObject();fabricCanvas.renderAll();
    const dataURL=fabricCanvas.toDataURL({format:'jpeg',quality:0.92});
    document.getElementById('edited_image_data').value=dataURL;
    editFinalized=true;
    const preview=document.getElementById('mediaPreview');
    preview.innerHTML='<span class="preview-label">YOUR EDIT</span>';
    const img=document.createElement('img');img.src=dataURL;preview.insertBefore(img,preview.firstChild);
    const btn=document.querySelector('.done-edit-btn');
    btn.innerHTML='<i class="ri-check-line"></i> Edit locked in — Ready to post!';
    btn.style.background='linear-gradient(135deg,#22c55e,#16a34a)';
    setTimeout(()=>{btn.innerHTML='<i class="ri-check-double-line"></i> Done Editing — Use This Version';btn.style.background='';},3000);
}

/* ═══════════════════════
   CAPTION LOGIC
═══════════════════════ */
const REQUIRED_ICON = '↩️ ';
const REQUIRED_TAG  = '@<?php echo addslashes($original_username); ?>';
const captionEl     = document.getElementById('captionContainer');

// Pre-fill caption
captionEl.innerText = REQUIRED_ICON;
setCursorToEnd(captionEl);

captionEl.addEventListener('input', function(){
    if(!captionEl.innerText.startsWith(REQUIRED_ICON)){
        captionEl.innerText = REQUIRED_ICON + captionEl.innerText.replace(/^↩️\s?/,'');
        setCursorToEnd(captionEl);
    }
    syncCaption();
    updateRules();
});

function setCursorToEnd(el){
    const range=document.createRange();
    const sel=window.getSelection();
    range.selectNodeContents(el);
    range.collapse(false);
    sel.removeAllRanges();
    sel.addRange(range);
}

function syncCaption(){
    document.getElementById('caption_hidden').value = captionEl.innerText;
    updateRules();
}

function updateRules(){
    const txt = captionEl.innerText;
    document.getElementById('ruleIcon').classList.toggle('done', txt.startsWith(REQUIRED_ICON));
    document.getElementById('ruleTag').classList.toggle('done', txt.includes(REQUIRED_TAG));
    document.getElementById('ruleHash').classList.toggle('done', /#\w+/.test(txt));
}
updateRules();

function insertHashtag(tag){
    captionEl.focus();
    const range=document.createRange();
    const sel=window.getSelection();
    range.selectNodeContents(captionEl);
    range.collapse(false);
    sel.removeAllRanges();
    sel.addRange(range);
    const current=captionEl.innerText.trimEnd();
    const sep = current.length>0 ? ' ' : '';
    document.execCommand('insertText',false,sep+tag);
    syncCaption();
    captionEl.style.borderColor='rgba(255,45,85,0.5)';
    setTimeout(()=>{ captionEl.style.borderColor=''; },600);
}

/* ═══════════════════════
   FORM SUBMIT
═══════════════════════ */
function handleRemixSubmit(){
    // Append @original_username if not present
    let cap = document.getElementById('caption_hidden').value;
    if(!cap.includes(REQUIRED_TAG)){
        cap = cap + ' ' + REQUIRED_TAG;
        captionEl.innerText = cap;
        document.getElementById('caption_hidden').value = cap;
    }
    cap = document.getElementById('caption_hidden').value;

    if(!cap.startsWith(REQUIRED_ICON)){
        alert('Caption must start with ↩️');
        return false;
    }
    if(!/#\w+/.test(cap)){
        alert('Please add at least one #hashtag!');
        return false;
    }
    // If in edit mode and not finalized, finalize automatically
    if(currentMode==='edit' && fabricCanvas && !editFinalized){
        fabricCanvas.discardActiveObject();fabricCanvas.renderAll();
        document.getElementById('edited_image_data').value=fabricCanvas.toDataURL({format:'jpeg',quality:0.92});
    }
    return true;
}

/* ═══════════════════════
   REMIX TIPS ROTATOR
═══════════════════════ */
const remixTips = [
    {title:'Keep the ↩️ Icon', body:'The ↩️ icon is how people on Dezzme identify remixes at a glance. Never remove it.'},
    {title:'GIFs Get More Loops', body:'If remixing a still image, consider converting it to a GIF — they loop and catch more eyes.'},
    {title:'Add Stickers Sparingly', body:'1-2 stickers placed well beats 10 cluttering the composition.'},
    {title:'Use the Crop Tool', body:'Tight crops create tension and punch. Cut to what matters most.'},
    {title:'Remix Chains = Reach', body:'Being in a remix chain means your post surfaces whenever the original does.'},
    {title:'Bold Text Placement', body:'Bottom-third text (like a subtitle) is more readable than centre-floating text.'},
    {title:'Hashtag Mix Strategy', body:'Use 1 mega-tag (e.g. #funny), 2 mid-range, and 1-2 niche tags for best discovery.'},
    {title:'Tag Related Creators', body:'Mentioning 2-3 relevant creators in your caption dramatically widens reach.'},
];
let tipIdx = 0;
function showTip(){
    document.getElementById('tipTitle').textContent = remixTips[tipIdx].title;
    document.getElementById('tipBody').textContent  = remixTips[tipIdx].body;
}
function rotateTip(dir){
    tipIdx=(tipIdx+dir+remixTips.length)%remixTips.length;
    showTip();
}
showTip();
setInterval(()=>rotateTip(1), 9000);
</script>

</body>
</html>