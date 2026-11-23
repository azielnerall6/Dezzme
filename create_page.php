<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if (!$current_user) {
    header("Location: login.php");
    exit();
}

$error = '';

/* ─────────────────────────────────────────────
   HANDLE SUBMISSION
   ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $page_name  = trim($_POST['page_name'] ?? '');
    $category   = trim($_POST['category'] ?? '');
    $short_desc = trim($_POST['short_description'] ?? '');
    $long_desc  = trim($_POST['long_description'] ?? '');
    $website    = trim($_POST['website'] ?? '');
    $email      = trim($_POST['contact_email'] ?? '');
    $phone      = trim($_POST['contact_phone'] ?? '');
    $founded    = trim($_POST['founded_date'] ?? '');

    if ($page_name === '') {
        $error = 'Please give your Page a name.';
    } elseif (mb_strlen($page_name) < 3) {
        $error = 'Page name must be at least 3 characters.';
    } else {

        // Build a unique username-style handle from the page name
        $base_handle = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $page_name));
        if ($base_handle === '') $base_handle = 'page';
        $handle = $base_handle;
        $suffix = 1;
        while (true) {
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $chk->bind_param("s", $handle);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) { $chk->close(); break; }
            $chk->close();
            $suffix++;
            $handle = $base_handle . $suffix;
        }

        // Generate a placeholder email/password since pages don't log in independently
        $page_email    = 'page_' . $handle . '_' . time() . '@dezzme.local';
        $random_pass   = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $dz_id         = 'DZ-' . strtoupper(bin2hex(random_bytes(4)));

        /* ── Handle avatar upload ── */
        $profile_pic = 'uploads/mee.jpeg';
        if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $fn = 'page_avatar_' . $handle . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], 'uploads/' . $fn)) {
                    $profile_pic = 'uploads/' . $fn;
                }
            }
        }

        /* ── Handle banner upload ── */
        $cover_photo = null;
        if (!empty($_FILES['banner']['name']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $fn = 'page_banner_' . $handle . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['banner']['tmp_name'], 'uploads/' . $fn)) {
                    $cover_photo = 'uploads/' . $fn;
                }
            }
        }

        /* ── Insert the page as a special user row ── */
        $stmt = $conn->prepare("
            INSERT INTO users (dz_id, username, email, password, profile_pic, cover_photo, nickname, is_page, email_verified)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)
        ");
        $stmt->bind_param("sssssss", $dz_id, $handle, $page_email, $random_pass, $profile_pic, $cover_photo, $page_name);
        $stmt->execute();
        $new_page_id = $conn->insert_id;
        $stmt->close();

        /* ── Insert page_details ── */
        $stmt2 = $conn->prepare("
            INSERT INTO page_details
                (user_id, category, short_description, long_description, website, contact_email, contact_phone, founded_date, created_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $founded_val = $founded !== '' ? $founded : null;
        $stmt2->bind_param("issssssii", $new_page_id, $category, $short_desc, $long_desc, $website, $email, $phone, $founded_val, $current_user);
        $stmt2->execute();
        $stmt2->close();

        /* ── Make the creator the Page owner/admin ── */
        $stmt3 = $conn->prepare("
            INSERT INTO page_admins (page_user_id, admin_user_id, role, appointed_by)
            VALUES (?, ?, 'owner', ?)
        ");
        $stmt3->bind_param("iii", $new_page_id, $current_user, $current_user);
        $stmt3->execute();
        $stmt3->close();

        header("Location: page.php?page_id=" . $new_page_id);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create a Page | Dezzme</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins', sans-serif; }

:root {
    --red: #FF2D2D;
    --ink: #06071A;
    --text: #F0F2FF;
    --text-2: rgba(220,224,255,0.65);
    --text-3: rgba(180,188,255,0.4);
    --radius-md: 16px;
    --radius-lg: 22px;
}

body {
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    min-height: 100vh;
}

nav {
    display:flex; justify-content:space-between; align-items:center;
    padding:20px 40px; position:sticky; top:0; z-index:100;
    backdrop-filter:blur(12px);
}
nav .logo-container { display:flex; align-items:center; gap:15px; }
nav .logo-container img { width:60px; height:60px; border-radius:20px; object-fit:cover; cursor:pointer; }
nav .logo-container .title { font-size:28px; font-weight:600; color:white; letter-spacing:5px; }
nav a.close-btn { color:#fff; font-size:26px; text-decoration:none; }

.cp-container { max-width:640px; margin:0 auto; padding:20px 20px 80px; }

.cp-header { text-align:center; margin-bottom:26px; }
.cp-header h1 { font-size:24px; font-weight:700; }
.cp-header p  { font-size:13px; color:var(--text-2); margin-top:6px; }

.cp-error {
    background: rgba(255,45,45,0.1); border:1px solid rgba(255,45,45,0.35);
    color:#ff8080; padding:12px 16px; border-radius:12px; font-size:13px; margin-bottom:18px;
}

/* ── Banner + avatar live preview, styled exactly like page.php ── */
.cp-banner-wrap {
    position:relative; width:100%; aspect-ratio:2.4/1;
    border-radius: var(--radius-lg); overflow:hidden;
    border:3px solid var(--red); background:rgba(255,255,255,0.04);
    cursor:pointer;
}
.cp-banner-wrap img { width:100%; height:100%; object-fit:cover; display:none; }
.cp-banner-placeholder {
    width:100%; height:100%; display:flex; flex-direction:column; gap:6px;
    align-items:center; justify-content:center; color:rgba(255,255,255,0.3); font-size:13px;
    background: repeating-linear-gradient(45deg, rgba(255,255,255,0.02), rgba(255,255,255,0.02) 10px, rgba(255,255,255,0.04) 10px, rgba(255,255,255,0.04) 20px);
}
.cp-banner-placeholder i { font-size:32px; color:var(--red); }

.cp-header-row { display:flex; align-items:flex-end; gap:16px; margin-top:-52px; padding:0 6px; position:relative; z-index:5; }
.cp-avatar-wrap { position:relative; width:108px; height:108px; flex-shrink:0; cursor:pointer; }
.cp-avatar-wrap img { width:100%; height:100%; border-radius:50%; object-fit:cover; border:4px solid var(--red); background:#0a0a1a; display:none; }
.cp-avatar-placeholder {
    width:100%; height:100%; border-radius:50%; border:4px solid var(--red);
    background:rgba(255,255,255,0.05); display:flex; align-items:center; justify-content:center;
    color:rgba(255,255,255,0.3); font-size:28px;
}
.cp-avatar-edit-tag {
    position:absolute; bottom:0; right:0; width:30px; height:30px; border-radius:50%;
    background:var(--red); border:3px solid var(--ink); display:flex; align-items:center;
    justify-content:center; color:#fff; font-size:13px; pointer-events:none;
}
.cp-hint { font-size:11.5px; color:var(--text-3); margin:8px 0 26px 6px; }

/* ── Form ── */
.cp-field-group { margin-bottom:18px; }
.cp-label { display:block; font-size:12.5px; font-weight:600; color:var(--text-2); margin-bottom:7px; }
.cp-label .req { color:var(--red); }
.cp-input, .cp-select, .cp-textarea {
    width:100%; padding:13px 15px; border-radius:13px;
    background:rgba(255,255,255,0.045); border:1px solid rgba(255,255,255,0.09);
    color:#fff; font-family:'Poppins',sans-serif; font-size:14px; outline:none;
    transition:border-color .2s, background .2s;
}
.cp-input:focus, .cp-select:focus, .cp-textarea:focus {
    border-color: rgba(255,45,45,0.45); background:rgba(255,45,45,0.04);
}
.cp-textarea { min-height:100px; resize:vertical; line-height:1.5; }
.cp-row { display:flex; gap:14px; }
.cp-row .cp-field-group { flex:1; }

.cp-submit-btn {
    width:100%; padding:15px; border-radius:14px; background:var(--red); border:none;
    color:#fff; font-size:14.5px; font-weight:700; cursor:pointer; margin-top:8px;
    transition:background .2s, box-shadow .2s;
}
.cp-submit-btn:hover { background:#e01c1c; box-shadow:0 8px 24px rgba(255,45,45,0.35); }

@media (max-width:600px){
    .cp-row { flex-direction:column; gap:0; }
    .cp-banner-wrap { aspect-ratio:2/1; border-radius:18px; }
    .cp-avatar-wrap { width:84px; height:84px; }
    .cp-header-row { margin-top:-42px; }
    .cp-container { padding:16px 14px 70px; }
}
</style>
</head>
<body>

<nav>
    <div class="logo-container">
        <img src="Dezzme.jpg" onclick="window.location.href='index.php'" alt="Logo">
        <div class="title">Dezzme</div>
    </div>
    <a href="home.php" class="close-btn"><i class="ri-close-line"></i></a>
</nav>

<div class="cp-container">

    <div class="cp-header">
        <h1>Create a Page</h1>
        <p>Pages let brands, communities, and creators build a presence separate from a personal profile.</p>
    </div>

    <?php if ($error): ?>
        <div class="cp-error"><i class="ri-error-warning-line"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="createPageForm">

        <!-- Banner preview / upload -->
        <div class="cp-banner-wrap" onclick="document.getElementById('bannerInput').click()">
            <img id="bannerPreview" alt="Banner preview">
            <div class="cp-banner-placeholder" id="bannerPlaceholder">
                <i class="ri-image-add-line"></i>
                <span>Tap to add a Page banner</span>
            </div>
        </div>
        <input type="file" id="bannerInput" name="banner" accept="image/*" style="display:none;" onchange="previewImg(this,'bannerPreview','bannerPlaceholder')">

        <!-- Avatar preview / upload, overlapping the banner -->
        <div class="cp-header-row">
            <div class="cp-avatar-wrap" onclick="document.getElementById('avatarInput').click()">
                <img id="avatarPreview" alt="Avatar preview">
                <div class="cp-avatar-placeholder" id="avatarPlaceholder"><i class="ri-image-add-line"></i></div>
                <div class="cp-avatar-edit-tag"><i class="ri-camera-line"></i></div>
            </div>
        </div>
        <input type="file" id="avatarInput" name="avatar" accept="image/*" style="display:none;" onchange="previewImg(this,'avatarPreview','avatarPlaceholder')">
        <div class="cp-hint">Both images are optional — you can always add or change them later from the Page.</div>

        <div class="cp-field-group">
            <label class="cp-label">Page Name <span class="req">*</span></label>
            <input type="text" class="cp-input" name="page_name" maxlength="60" placeholder="e.g. Cardi Fan Page" required>
        </div>

        <div class="cp-field-group">
            <label class="cp-label">Category</label>
            <select class="cp-select" name="category">
                <option value="">Select a category</option>
                <option>Meme Page</option>
                <option>Brand / Business</option>
                <option>Public Figure</option>
                <option>Music / Artist</option>
                <option>Community</option>
                <option>Entertainment</option>
                <option>Tech & Dev</option>
                <option>Other</option>
            </select>
        </div>

        <div class="cp-field-group">
            <label class="cp-label">Short Description <span style="font-weight:400;color:var(--text-3);">(max 160 chars)</span></label>
            <input type="text" class="cp-input" name="short_description" maxlength="160" placeholder="A one-line summary of your Page">
        </div>

        <div class="cp-field-group">
            <label class="cp-label">Long Description</label>
            <textarea class="cp-textarea" name="long_description" maxlength="2000" placeholder="Tell people what this Page is about..."></textarea>
        </div>

        <div class="cp-row">
            <div class="cp-field-group">
                <label class="cp-label">Website</label>
                <input type="text" class="cp-input" name="website" placeholder="yourwebsite.com">
            </div>
            <div class="cp-field-group">
                <label class="cp-label">Founded</label>
                <input type="date" class="cp-input" name="founded_date">
            </div>
        </div>

        <div class="cp-row">
            <div class="cp-field-group">
                <label class="cp-label">Contact Email</label>
                <input type="email" class="cp-input" name="contact_email" placeholder="contact@yourpage.com">
            </div>
            <div class="cp-field-group">
                <label class="cp-label">Contact Phone</label>
                <input type="tel" class="cp-input" name="contact_phone" placeholder="+234 800 000 0000">
            </div>
        </div>

        <button type="submit" class="cp-submit-btn"><i class="ri-flag-2-line"></i> Create Page</button>
    </form>

</div>

<script>
function previewImg(input, imgId, placeholderId){
    var file = input.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e){
        var img = document.getElementById(imgId);
        var ph  = document.getElementById(placeholderId);
        img.src = e.target.result;
        img.style.display = 'block';
        ph.style.display = 'none';
    };
    reader.readAsDataURL(file);
}
</script>

</body>
</html>