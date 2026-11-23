<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user){
    header("Location: login.php");
    exit();
}

$profile_id = isset($_GET['user_id']) && !empty($_GET['user_id']) ? intval($_GET['user_id']) : $current_user;

$user = $conn->query("SELECT username, profile_pic, created_at FROM users WHERE id=$profile_id")->fetch_assoc();

$memes_count = $conn->query("SELECT COUNT(*) as cnt FROM memes WHERE uploaded_by=$profile_id")->fetch_assoc()['cnt'];

$followers_count = $conn->query("SELECT COUNT(*) AS c FROM followers WHERE following_id=$profile_id")->fetch_assoc()['c'] ?? 0;
$following_count = $conn->query("SELECT COUNT(*) AS c FROM followers WHERE follower_id=$profile_id")->fetch_assoc()['c'] ?? 0;

// Username change count (from unfollow_log concept — using a username_history table if it exists, else count = 0)
// We'll simulate a username history count query — adjust if you have a username_history table
$username_change_count = 0;
$username_history_result = $conn->query("SHOW TABLES LIKE 'username_history'");
if($username_history_result && $username_history_result->num_rows > 0){
    $username_change_count = $conn->query("SELECT COUNT(*) as cnt FROM username_history WHERE user_id=$profile_id")->fetch_assoc()['cnt'] ?? 0;
}

// Shared followers
$shared_followers_count = 0;
$am_following = false;
if ($current_user != $profile_id) {
    $am_following = $conn->query("SELECT 1 FROM followers WHERE follower_id = $current_user AND following_id = $profile_id")->num_rows > 0;
    if ($am_following) {
        $shared_followers_count = $conn->query("
            SELECT COUNT(*) as cnt
            FROM followers f1
            JOIN followers f2 ON f1.follower_id = f2.follower_id
            WHERE f1.following_id = $current_user AND f2.following_id = $profile_id
        ")->fetch_assoc()['cnt'] ?? 0;
    }
}

$mutuals_count = 0;
if ($current_user != $profile_id && $am_following) {
    $mutuals_count = $conn->query("
        SELECT COUNT(*) as cnt
        FROM followers f1
        JOIN followers f2 ON f1.follower_id = f2.following_id
        JOIN followers f3 ON f1.follower_id = f3.follower_id
        WHERE f1.following_id = $current_user
          AND f2.follower_id = $current_user
          AND f3.following_id = $profile_id
    ")->fetch_assoc()['cnt'] ?? 0;
}

// User country from user_about
$user_location = null;
$about_result = $conn->query("SELECT current_location, hometown FROM user_about WHERE user_id=$profile_id");
if($about_result && $about_result->num_rows > 0){
    $about = $about_result->fetch_assoc();
    $user_location = $about['current_location'] ?: $about['hometown'] ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($user['username']); ?> | About This Account</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --red: #FF4C4C;
    --red-glow: rgba(255,76,76,0.18);
    --bg: #060612;
    --surface: rgba(255,255,255,0.05);
    --border: rgba(255,255,255,0.10);
    --text: #ffffff;
    --muted: rgba(255,255,255,0.45);
    --divider: rgba(255,255,255,0.08);
}

body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 60px 16px 60px;
}

/* ── Back button (desktop ghost style, no border box) ── */
.back-btn {
    position: fixed;
    top: 20px;
    left: 20px;
    display: flex;
    align-items: center;
    gap: 6px;
    background: none;
    border: none;
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    font-size: 0.88rem;
    font-weight: 500;
    cursor: pointer;
    opacity: 0.75;
    transition: opacity 0.2s;
    z-index: 100;
    padding: 0;
}
.back-btn:hover { opacity: 1; color: var(--red); }
.back-btn i { font-size: 1.15rem; }

/* ── Mobile back (icon only, no border) ── */
.back-btn-mobile {
    display: none;
    position: fixed;
    top: 16px;
    left: 14px;
    background: none;
    border: none;
    color: var(--text);
    font-size: 1.4rem;
    cursor: pointer;
    z-index: 100;
    opacity: 0.75;
    transition: opacity 0.2s, color 0.2s;
    padding: 0;
}
.back-btn-mobile:hover { opacity: 1; color: var(--red); }

/* ── Main card ── */
.card {
    width: 100%;
    max-width: 480px;
    background: transparent;
    border: none;
    border-radius: 0;
    overflow: visible;
}

/* ── Header section ── */
.card-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 32px 24px 24px;
    text-align: center;
}

.avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid var(--red);
    margin-bottom: 14px;
    transition: transform 0.3s, box-shadow 0.3s;
}
.avatar:hover {
    transform: scale(1.06);
    box-shadow: 0 0 22px rgba(255,76,76,0.55);
}

.card-header h1 {
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 2px;
}

.card-header h1 .badge {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.page-label {
    font-size: 0.75rem;
    color: var(--muted);
    font-weight: 400;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    margin-top: 4px;
}

/* ── Divider ── */
.divider {
    width: 100%;
    border: none;
    border-top: 1px solid var(--divider);
    margin: 0;
}

/* ── Info rows ── */
.info-list {
    display: flex;
    flex-direction: column;
}

.info-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 18px 24px;
    transition: background 0.18s;
    cursor: default;
}

.info-row.clickable {
    cursor: pointer;
}
.info-row.clickable:hover {
    background: rgba(255,255,255,0.045);
}

.info-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: var(--surface);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--red);
    font-size: 1rem;
}

.info-content {
    flex: 1;
    min-width: 0;
}

.info-label {
    font-size: 0.8rem;
    color: var(--muted);
    font-weight: 400;
    line-height: 1.2;
}

.info-value {
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--text);
    line-height: 1.4;
    margin-top: 1px;
    word-break: break-word;
}

.chevron {
    color: var(--muted);
    font-size: 1rem;
    flex-shrink: 0;
    margin-left: 4px;
}

/* ── Stats section ── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
    padding: 20px 24px;
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 10px 6px;
}

.stat-item .num {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text);
    line-height: 1;
}

.stat-item .lbl {
    font-size: 0.65rem;
    color: var(--muted);
    margin-top: 4px;
    font-weight: 400;
    letter-spacing: 0.02em;
}

.stat-sep {
    width: 1px;
    background: var(--divider);
    margin: 8px 0;
}

/* ── Extra info for non-self ── */
.mutual-note {
    padding: 16px 24px;
    font-size: 0.72rem;
    color: var(--muted);
    line-height: 1.6;
}

.mutual-note + .mutual-note {
    padding-top: 0;
}

/* ── Modal ── */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.72);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: none;
    align-items: flex-end;
    justify-content: center;
    z-index: 999;
    padding: 0 16px 24px;
}
.modal-overlay.show {
    display: flex;
}

.modal {
    background: #111122;
    border: 1px solid var(--border);
    border-radius: 20px;
    width: 100%;
    max-width: 420px;
    padding: 28px 24px 24px;
    position: relative;
    animation: slideUp 0.28s cubic-bezier(0.34,1.56,0.64,1);
}

@keyframes slideUp {
    from { transform: translateY(60px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}

.modal-close {
    position: absolute;
    top: 14px;
    right: 16px;
    background: none;
    border: none;
    color: var(--muted);
    font-size: 1.2rem;
    cursor: pointer;
    padding: 4px;
    transition: color 0.2s;
}
.modal-close:hover { color: var(--red); }

.modal h3 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal h3 i { color: var(--red); }

.modal p {
    font-size: 0.84rem;
    color: var(--muted);
    line-height: 1.65;
}

.modal p strong {
    color: var(--text);
    font-weight: 500;
}

/* ── Responsive ── */
@media (max-width: 780px) {
    body { padding: 54px 14px 54px; }
    .back-btn { display: none; }
    .back-btn-mobile { display: block; }
    .card { border-radius: 18px; }
    .card-header { padding: 28px 20px 20px; }
    .avatar { width: 75px; height: 75px; }
    .card-header h1 { font-size: 1rem; }
    .info-row { padding: 15px 20px; gap: 13px; }
    .info-icon { width: 32px; height: 32px; font-size: 0.9rem; border-radius: 9px; }
    .info-label { font-size: 0.74rem; }
    .info-value { font-size: 0.85rem; }
    .stats-grid { padding: 16px 20px; }
    .stat-item .num { font-size: 1.1rem; }
    .stat-item .lbl { font-size: 0.62rem; }
    .mutual-note { padding: 12px 20px; font-size: 0.7rem; }
}

@media (max-width: 480px) {
    body { padding: 48px 10px 48px; }
    .card { border-radius: 16px; }
    .card-header { padding: 24px 16px 18px; }
    .avatar { width: 90px; height: 90px; border-width: 2px; }
    .card-header h1 { font-size: 0.92rem; gap: 5px; }
    .page-label { font-size: 0.68rem; }
    .info-row { padding: 13px 16px; gap: 11px; }
    .info-icon { width: 30px; height: 30px; font-size: 0.82rem; border-radius: 8px; }
    .info-label { font-size: 0.68rem; }
    .info-value { font-size: 0.8rem; }
    .stats-grid { padding: 14px 16px; grid-template-columns: repeat(3,1fr); }
    .stat-item .num { font-size: 1rem; }
    .stat-item .lbl { font-size: 0.58rem; }
    .mutual-note { padding: 10px 16px; font-size: 0.66rem; }
    .modal { padding: 22px 18px 20px; border-radius: 16px; }
    .modal h3 { font-size: 0.92rem; }
    .modal p { font-size: 0.78rem; }
}

@media (max-width: 380px) {
    body { padding: 44px 8px 44px; }
    .card { border-radius: 14px; }
    .card-header { padding: 20px 14px 16px; }
    .avatar { width: 80px; height: 80px; }
    .card-header h1 { font-size: 0.85rem; }
    .info-row { padding: 12px 14px; gap: 10px; }
    .info-icon { width: 28px; height: 28px; font-size: 0.78rem; border-radius: 7px; }
    .info-label { font-size: 0.64rem; }
    .info-value { font-size: 0.75rem; }
    .stats-grid { padding: 12px 14px; }
    .stat-item .num { font-size: 0.9rem; }
    .stat-item .lbl { font-size: 0.55rem; }
    .mutual-note { padding: 8px 14px; font-size: 0.62rem; }
}

@media (max-width: 320px) {
    body { padding: 40px 6px 40px; }
    .back-btn-mobile { font-size: 1.2rem; top: 12px; left: 10px; }
    .card { border-radius: 12px; }
    .card-header { padding: 18px 12px 14px; }
    .avatar { width: 55px; height: 55px; }
    .card-header h1 { font-size: 0.8rem; }
    .info-row { padding: 11px 12px; gap: 9px; }
    .info-icon { width: 26px; height: 26px; font-size: 0.72rem; }
    .info-label { font-size: 0.6rem; }
    .info-value { font-size: 0.7rem; }
    .stats-grid { padding: 10px 12px; }
    .stat-item .num { font-size: 0.85rem; }
    .stat-item .lbl { font-size: 0.52rem; }
    .mutual-note { padding: 8px 12px; font-size: 0.58rem; }
}

.transparency-note {
    font-size: 0.68rem;
    color: var(--muted);
    font-weight: 400;
    line-height: 1.65;
    margin-top: 10px;
    max-width: 320px;
    text-align: center;
}

@media (max-width: 780px) {
    .transparency-note {
        font-size: 0.65rem;
        max-width: 290px;
        margin-top: 9px;
        line-height: 1.6;
    }
}

@media (max-width: 480px) {
    .transparency-note {
        font-size: 0.62rem;
        max-width: 260px;
        margin-top: 8px;
        line-height: 1.55;
    }
}

@media (max-width: 390px) {
    .transparency-note {
        font-size: 0.58rem;
        max-width: 230px;
        margin-top: 7px;
        line-height: 1.5;
    }
}

@media (max-width: 320px) {
    .transparency-note {
        font-size: 0.55rem;
        max-width: 200px;
        margin-top: 6px;
        line-height: 1.45;
    }
}
</style>
</head>
<body>

<!-- Desktop back -->
<button class="back-btn" onclick="location.href='profile.php?user_id=<?php echo $profile_id; ?>'">
    <i class="ri-arrow-left-s-line"></i> Back
</button>

<!-- Mobile back -->
<button class="back-btn-mobile" onclick="history.back()" aria-label="Go back">
    <i class="ri-arrow-left-s-line"></i>
</button>

<div class="card">

    <!-- Header -->
    <div class="card-header">
    <img
        class="avatar"
        src="<?php echo $user['profile_pic'] ?: 'uploads/default.jpg'; ?>"
        alt="Profile Picture"
    >
    <h1>
        <?php echo $profile_id == $current_user ? 'You' : htmlspecialchars($user['username']); ?>
    </h1>
    <p class="page-label">About This Account</p>
    <p class="transparency-note">
        Dezzme shows this page to help the community understand who they're interacting with. Account age, username history, and location give you the context to decide whether an account is genuine before you connect, follow, or share with them. <a href="help.php#transparency" style="color:var(--red);text-decoration:none;font-weight:500;">Learn More</a>
    </p>
</div>

    <hr class="divider">

    <div class="info-list">

        <!-- Date Joined -->
        <div class="info-row">
            <div class="info-icon"><i class="ri-calendar-line"></i></div>
            <div class="info-content">
                <div class="info-label">Date Joined</div>
                <div class="info-value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
            </div>
        </div>

        <hr class="divider">

        <!-- Account Based In -->
        <?php if($user_location): ?>
        <div class="info-row">
            <div class="info-icon"><i class="ri-map-pin-2-line"></i></div>
            <div class="info-content">
                <div class="info-label">Account Based In</div>
                <div class="info-value"><?php echo htmlspecialchars($user_location); ?></div>
            </div>
        </div>
        <hr class="divider">
        <?php else: ?>
        <div class="info-row">
            <div class="info-icon"><i class="ri-map-pin-2-line"></i></div>
            <div class="info-content">
                <div class="info-label">Account Based In</div>
                <div class="info-value" style="color:var(--muted)">Not set</div>
            </div>
        </div>
        <hr class="divider">
        <?php endif; ?>

        <!-- Former Usernames -->
        <div class="info-row clickable" onclick="openUsernameModal()">
            <div class="info-icon"><i class="ri-at-line"></i></div>
            <div class="info-content">
                <div class="info-label">Former Usernames</div>
                <div class="info-value">
                    <?php
                    $times = $username_change_count;
                    if($times == 0){
                        echo '<span style="color:var(--muted)">No changes</span>';
                    } else {
                        echo $times . ' ' . ($times == 1 ? 'change' : 'changes');
                    }
                    ?>
                </div>
            </div>
            <i class="ri-arrow-right-s-line chevron"></i>
        </div>

        <hr class="divider">

        <!-- Memes Posted -->
        <div class="info-row">
            <div class="info-icon"><i class="ri-image-2-line"></i></div>
            <div class="info-content">
                <div class="info-label">Memes Posted</div>
                <div class="info-value"><?php echo number_format($memes_count); ?></div>
            </div>
        </div>

        <hr class="divider">

        <!-- Trackers & Tracking -->
        <div class="stats-grid">
            <div class="stat-item">
                <div class="num"><?php echo number_format($followers_count); ?></div>
                <div class="lbl">Trackers</div>
            </div>
            <div class="stat-item" style="border-left:1px solid var(--divider);border-right:1px solid var(--divider);">
                <div class="num"><?php echo number_format($following_count); ?></div>
                <div class="lbl">Tracking</div>
            </div>
            <div class="stat-item">
                <div class="num"><?php echo number_format($memes_count); ?></div>
                <div class="lbl">Posts</div>
            </div>
        </div>

        <?php if($current_user != $profile_id): ?>
        <hr class="divider">

        <!-- Shared Mutual Trackers -->
        <div class="info-row">
            <div class="info-icon"><i class="ri-group-line"></i></div>
            <div class="info-content">
                <div class="info-label">Shared Mutual Trackers</div>
                <div class="info-value"><?php echo number_format($shared_followers_count); ?> in common</div>
            </div>
        </div>

        <hr class="divider">

        <!-- Mutuals -->
        <div class="info-row">
            <div class="info-icon"><i class="ri-exchange-line"></i></div>
            <div class="info-content">
                <div class="info-label">Mutuals</div>
                <div class="info-value"><?php echo number_format($mutuals_count); ?> mutual<?php echo $mutuals_count == 1 ? '' : 's'; ?></div>
            </div>
        </div>

        <hr class="divider">

        <div class="mutual-note">
            <strong style="color:rgba(255,255,255,0.7)">Shared Mutual Trackers</strong> are users who track both you and this account.
        </div>
        <div class="mutual-note" style="padding-top:0;">
            <strong style="color:rgba(255,255,255,0.7)">Mutuals</strong> are people you track who also track you back, and who also track this account.
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Username History Modal -->
<div class="modal-overlay" id="usernameModal" onclick="closeUsernameModalOutside(event)">
    <div class="modal">
        <button class="modal-close" onclick="closeUsernameModal()"><i class="ri-close-line"></i></button>
        <h3><i class="ri-history-line"></i> Username History</h3>
        <p>
            <?php
            $uname = htmlspecialchars($user['username']);
            $count = $username_change_count;
            if($count == 0){
                echo "<strong>$uname</strong> has never changed their username.";
            } elseif($count == 1){
                echo "<strong>$uname</strong> has changed their username <strong>1 time</strong>.";
            } else {
                echo "<strong>$uname</strong> has changed their username <strong>$count times</strong>.";
            }
            ?>
        </p>
    </div>
</div>

<script>
function openUsernameModal(){
    document.getElementById('usernameModal').classList.add('show');
}
function closeUsernameModal(){
    document.getElementById('usernameModal').classList.remove('show');
}
function closeUsernameModalOutside(e){
    if(e.target === document.getElementById('usernameModal')) closeUsernameModal();
}
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeUsernameModal();
});
</script>

</body>
</html>