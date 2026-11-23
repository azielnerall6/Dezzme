<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if(!$current_user){
    header("Location: login.php");
    exit();
}

// FETCH CURRENT USER INFO
$stmt_user = $conn->prepare("SELECT username, profile_pic FROM users WHERE id=?");
$stmt_user->bind_param("i", $current_user);
$stmt_user->execute();
$current_user_info = $stmt_user->get_result()->fetch_assoc();

// FETCH ALL USERS THAT THE CURRENT USER IS SUBSCRIBED TO
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.username, u.profile_pic, t.tier_name
    FROM subscriptions s
    JOIN users u ON s.owner_id = u.id
    LEFT JOIN subscription_tiers t ON s.tier_id = t.id
    WHERE s.subscriber_id = ? AND s.approved = 1
");
$stmt->bind_param("i", $current_user);
$stmt->execute();
$my_subscriptions = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Subscriptions | Peura</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">
    <style>
    /* BODY & BACKGROUND */
    *{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
    body{
        min-height:100vh;
        color:white;
        overflow-x:hidden;
        background:linear-gradient(-45deg,#010218,#030521,#030415,#040237,#06051b,#00032a,#00033D,#030414);
        background-size:500% 500%;
        animation:gradientShift 20s ease infinite;
    }
    @keyframes gradientShift{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}

    .container{max-width:1200px;margin:50px auto;padding:0 25px;}

    /* TOP BAR: USER + HOME + SEARCH */
    .top-bar{
        display:flex;
        align-items:center;
        justify-content:space-between;
        margin-bottom:30px;
        flex-wrap:wrap;
        gap:15px;
    }

    .user-info{
        display:flex;
        align-items:center;
        gap:10px;
        cursor:pointer;
        text-decoration:none;
        color:#fff;
        transition:0.3s;
    }
    .user-info img{
        width:50px;
        height:50px;
        border-radius:50%;
        border:2px solid rgba(255,76,76,0.5);
        transition:0.3s;
    }
    .user-info:hover img{
        transform:scale(1.1);
        border-color:#FF4C4C;
    }
    .user-info span{
        font-weight:600;
        font-size:1rem;
    }

    .btn-home-top{
        display:inline-block;
        color:#fff;
        font-size:0.9rem;
        opacity:0.8;
        text-decoration:none;
        padding:8px 15px;
        border-radius:15px;
        background:rgba(255,255,255,0.05);
        transition:0.3s;
    }
    .btn-home-top:hover{
        color:#FF4C4C;
        background:rgba(255,76,76,0.2);
        transform:scale(1.05);
    }

    .search-bar{
        flex:1;
        max-width:400px;
        position:relative;
    }
    .search-bar input{
        width:100%;
        padding:10px 15px;
        border-radius:25px;
        border:none;
        outline:none;
        background:rgba(255,255,255,0.05);
        color:#fff;
        font-size:0.9rem;
    }
    .search-bar input::placeholder{color:rgba(255,255,255,0.6);}

    h1{
        font-size:2.2rem;
        color:#FF4C4C;
        margin-bottom:20px;
        display:flex;
        align-items:center;
        gap:15px;
        text-shadow:0 4px 10px rgba(0,0,0,0.4);
    }

    /* GRID LAYOUT */
    .subs-grid{
        display:grid;
        grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
        gap:25px;
        padding-bottom:50px;
    }

    /* SUBSCRIPTION CARD */
    .sub-card{
        background:rgba(255,255,255,0.05);
        border:1px solid rgba(255,255,255,0.06);
        border-radius:25px;
        padding:25px;
        display:flex;
        flex-direction:column;
        align-items:center;
        text-decoration:none;
        color:#fff;
        transition:all 0.35s ease;
        backdrop-filter:blur(18px);
        position:relative;
        overflow:hidden;
    }
    .sub-card:hover{
        transform:translateY(-8px) scale(1.02);
        background:rgba(255,76,76,0.15);
        border-color:#FF4C4C;
        box-shadow:0 20px 50px rgba(255,76,76,0.3),0 0 20px rgba(255,255,255,0.1);
    }

    .sub-card img{
        width:120px;
        height:120px;
        border-radius:20px;
        border:3px solid rgba(255,76,76,0.3);
        object-fit:cover;
        margin-bottom:15px;
        transition:0.5s ease;
    }
    .sub-card:hover img{
        transform:scale(1.2);
        border-color:#FF4C4C;
        box-shadow:0 0 25px rgba(255,76,76,0.5);
    }

    .sub-card h3{
        font-size:1.3rem;
        margin-bottom:10px;
        text-align:center;
        transition:0.3s;
    }
    .sub-card:hover h3{
        color:#FF4C4C;
    }

    .tier-badge{
        font-size:0.8rem;
        background:linear-gradient(135deg,#FF4C4C,#ff6b6b);
        padding:5px 16px;
        border-radius:25px;
        font-weight:600;
        text-transform:uppercase;
        letter-spacing:1px;
        transition:0.3s;
    }

    /* EMPTY STATE */
    .empty-state{
        text-align:center;
        padding:50px;
        background: rgba(255,255,255,0.05);
        border-radius:30px;
        opacity:0.6;
        grid-column:1/-1;
        font-size:1rem;
    }
    .empty-state i{
        font-size:3rem;
        margin-bottom:20px;
        color:#FF4C4C;
    }
    .btn-action{
        display:inline-block;
        margin-top:20px;
        background: linear-gradient(135deg,#FF4C4C,#ff6b6b);
        color:#fff;
        padding:12px 25px;
        border-radius:20px;
        text-decoration:none;
        font-weight:600;
        transition:0.3s;
    }
    .btn-action:hover{
        transform:scale(1.05);
        box-shadow:0 10px 25px rgba(255,76,76,0.4);
    }

    #particles{
        position:fixed;
        width:100%;
        height:100%;
        overflow:hidden;
        top:0;
        left:0;
        z-index:-1;
    }
    .particle{
        position:absolute;
        background:white;
        border-radius:50%;
        pointer-events:none;
        box-shadow:0 0 10px rgba(255,255,255,0.6);
        animation: floatParticle linear infinite;
    }
    @keyframes floatParticle{
        0%{transform:translate(0,0) scale(1);opacity:0;}
        10%{opacity:1;}
        50%{transform:translate(var(--x-move),var(--y-move)) scale(1.2);}
        100%{transform:translate(calc(var(--x-move)*-1),calc(var(--y-move)*-1)) scale(0.8);opacity:0;}
    }

    
/* =========================
   TABLET (iPad / small laptops)
   ========================= */
@media (max-width: 768px) {

    .container {
        margin: 30px auto;
        padding: 0 18px;
    }

    .top-bar {
        gap: 12px;
    }

    .user-info img {
        width: 42px;
        height: 42px;
    }

    .user-info span {
        font-size: 0.9rem;
    }

    /* SEARCH becomes full width */
    .search-bar {
        max-width: 100%;
        width: 100%;
    }

    h1 {
        font-size: 1.8rem;
    }

    .subs-grid {
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 18px;
    }

    .sub-card {
        padding: 18px;
        border-radius: 20px;
    }

    .sub-card img {
        width: 95px;
        height: 95px;
    }

    .sub-card h3 {
        font-size: 1.1rem;
    }

    /* ❌ HIDE HOME BUTTON ON MOBILE (your request) */
    .btn-home-top {
        display: none !important;
    }
}


/* =========================
   PHONE (MAIN PREMIUM FIX)
   ========================= */
@media (max-width: 480px) {

    .container {
        margin: 20px auto;
        padding: 0 12px;
    }

    .top-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    /* USER INFO smaller + cleaner */
    .user-info img {
        width: 36px;
        height: 36px;
    }

    .user-info span {
        font-size: 0.8rem;
    }

    /* SEARCH becomes full width */
    .search-bar {
        max-width: 100%;
        width: 100%;
    }

    .search-bar input {
        padding: 8px 12px;
        font-size: 0.8rem;
    }

    /* ❌ HIDE HOME BUTTON ON MOBILE (your request) */
    .btn-home-top {
        display: none !important;
    }

    h1 {
        font-size: 1.4rem;
        gap: 10px;
    }

    /* GRID becomes single column (clean feed style) */
    .subs-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .sub-card {
        padding: 15px;
        border-radius: 18px;
    }

    .sub-card img {
        width: 80px;
        height: 80px;
        border-radius: 16px;
    }

    .sub-card h3 {
        font-size: 0.95rem;
    }

    .tier-badge {
        font-size: 0.7rem;
        padding: 4px 12px;
    }

    .empty-state {
        padding: 25px;
        font-size: 0.85rem;
    }

    .btn-action {
        padding: 10px 18px;
        font-size: 0.85rem;
    }
}


/* =========================
   VERY SMALL PHONES
   ========================= */
@media (max-width: 360px) {

    h1 {
        font-size: 1.2rem;
    }

    .sub-card img {
        width: 70px;
        height: 70px;
    }

    .sub-card h3 {
        font-size: 0.85rem;
    }
}

/* Hide mobile btn on desktop */
.back-btn-mobile {
    display: none;
}

@media (max-width: 768px) {
    /* Hide desktop btn */
    .back-btn {
        display: none;
    }

    .back-btn-mobile {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        border: none;
        color: #fff;
        font-size: 22px;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        cursor: pointer;
        transition: 0.3s;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        position: absolute;
        left: 10px;
    }

    .back-btn-mobile:hover {
        background: rgba(255, 0, 0, 0.7);
        box-shadow: 0 4px 15px rgba(255,0,0,0.35);
    }

    .back-btn-mobile:active {
        transform: scale(0.93);
    }
}

@media (max-width: 480px) {
    .back-btn-mobile {
        font-size: 20px;
        width: 30px;
        height: 30px;
    }
}

@media (max-width: 768px) {

    .top-bar {
        flex-direction: column;
        align-items: center;
        gap: 18px; /* creates space BELOW back button naturally */
        padding-top: 25px; /* ensures back button doesn't overlap */
    }

    .user-info {
        margin-top: 10px; /* pushes it LOWER than back button */
    }

    .user-info img {
        width: 42px;
        height: 42px;
    }

    .user-info span {
        font-size: 0.9rem;
    }
}

@media (max-width: 480px) {

    .top-bar {
        gap: 14px;
        padding-top: 20px;
    }

    .user-info {
        margin-top: 14px; /* more separation from back button */
    }

    .user-info img {
        width: 36px;
        height: 36px;
    }

    .user-info span {
        font-size: 0.8rem;
    }
}
    </style>
</head>
<body>

<div id="particles"></div>

<div class="container">
 <!-- Mobile/tablet back button -->
<button class="back-btn-mobile" onclick="history.back()">
    <i class="ri-arrow-left-s-line"></i>
</button>

    <!-- TOP BAR -->
    <div class="top-bar">
        <?php
// Determine which user’s page we are on
$view_user_id = $_GET['user_id'] ?? $current_user;

// Fetch that user’s info
$stmt_user = $conn->prepare("SELECT username, profile_pic FROM users WHERE id=?");
$stmt_user->bind_param("i", $view_user_id);
$stmt_user->execute();
$view_user_info = $stmt_user->get_result()->fetch_assoc();
$view_username = $view_user_info['username'];
$view_profile_pic = $view_user_info['profile_pic'];

// Determine the display text
$display_text = ($view_user_id == $current_user) ? "Your Perks" : htmlspecialchars($view_username) . "'s Perks Offer";
?>

<a href="subscriptions.php?user_id=<?php echo $view_user_id; ?>" class="user-info">
    <img src="<?php echo htmlspecialchars($view_profile_pic); ?>" alt="Profile">
    <span><?php echo $display_text; ?></span>
</a>

        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search subscriptions...">
        </div>

        <a href="profile.php?user_id=<?php echo $view_user_id; ?>" class="btn-home-top">
    <i class="ri-user-3-line"></i> Profile
</a>
    </div>

   <?php
// Determine if viewing own page or another user's subscriptions
$view_user_id = $_GET['user_id'] ?? $current_user; // If ?user_id= is in URL, else current user
$stmt_view = $conn->prepare("SELECT username FROM users WHERE id=?");
$stmt_view->bind_param("i", $view_user_id);
$stmt_view->execute();
$view_user_info = $stmt_view->get_result()->fetch_assoc();
$view_username = $view_user_info['username'];
?>

<h1>
    <i class="ri-article-line"></i> 
    <?php 
        if($view_user_id == $current_user){
            echo "Your Subscriptions";
        } else {
            echo htmlspecialchars($view_username) . "'s Subs List";
        }
    ?>
</h1>

<?php
$profile_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $current_user;

// Fetch subscriptions of the page owner only
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.username, u.profile_pic, t.tier_name
    FROM subscriptions s
    JOIN users u ON s.owner_id = u.id
    LEFT JOIN subscription_tiers t ON s.tier_id = t.id
    WHERE s.subscriber_id = ? AND s.approved = 1
");
$stmt->bind_param("i", $profile_id); // <-- Use $profile_id, not $current_user
$stmt->execute();
$my_subscriptions = $stmt->get_result();

// Fetch info of the page owner
$view_user_info = $conn->query("SELECT username, profile_pic FROM users WHERE id=$profile_id")->fetch_assoc();
?>
    <div class="subs-grid" id="subsGrid">
    <?php if($my_subscriptions->num_rows>0): ?>
        <?php while($row=$my_subscriptions->fetch_assoc()): ?>
            <a href="subscriptions.php?user_id=<?php echo $row['id']; ?>" class="sub-card">
                <img src="<?php echo htmlspecialchars($row['profile_pic']); ?>" alt="Profile">
                <h3><svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24">
  <circle cx="12" cy="12" r="12" fill="#FF0000"/>
  <path d="M9 12l2 2 4-4" stroke="white" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
</svg>   <?php echo htmlspecialchars($row['username']); ?></h3>
                <span class="tier-badge"><?php echo htmlspecialchars($row['tier_name'] ?? 'Premium'); ?></span>
            </a>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-state">
            <i class="ri-ghost-line" style="font-size: 3rem; margin-bottom: 10px;"></i>
            <p><?php 
                if($current_user == $profile_id){
                    echo "You haven't subscribed to anyone yet.";
                } else {
                    echo htmlspecialchars($view_user_info['username']) . " hasn't subscribed to anyone yet.";
                }
            ?></p>
            <?php if($current_user == $profile_id): ?>
            <a href="search.php" class="btn-action" style="margin-top:10px;">Explore Creators</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// GENERATE PARTICLES
const container = document.getElementById('particles');
for(let i=0;i<70;i++){
    const p=document.createElement('div');
    p.className='particle';
    const size=Math.random()*3+3;
    p.style.width=size+'px';
    p.style.height=size+'px';
    p.style.left=Math.random()*100+'vw';
    p.style.top=Math.random()*100+'vh';
    p.style.setProperty('--x-move',(Math.random()*200-100)+'px');
    p.style.setProperty('--y-move',(Math.random()*-300-100)+'px');
    p.style.animationDuration=(Math.random()*30+10)+'s';
    p.style.opacity=Math.random()*0.5+0.2;
    container.appendChild(p);
}

// SEARCH FUNCTIONALITY
const searchInput = document.getElementById('searchInput');
const subsGrid = document.getElementById('subsGrid');
searchInput.addEventListener('input', function(){
    const query = this.value.toLowerCase();
    const cards = subsGrid.getElementsByClassName('sub-card');
    Array.from(cards).forEach(card=>{
        const username = card.querySelector('h3').textContent.toLowerCase();
        card.style.display = username.includes(query) ? 'flex' : 'none';
    });
});
</script>

</body>
</html>