<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];

$u = $conn->prepare("SELECT u.username, u.nickname, u.profile_pic FROM users u WHERE u.id = ?");
$u->bind_param("i", $uid);
$u->execute();
$user = $u->get_result()->fetch_assoc();
$u->close();

// Fetch user's own memes (downloadable content)
$memes_stmt = $conn->prepare("
    SELECT id, file_name, caption, type, created_at
    FROM memes
    WHERE uploaded_by = ? AND file_name != '' AND file_name != '0' AND type != 'text'
    ORDER BY created_at DESC
");
$memes_stmt->bind_param("i", $uid);
$memes_stmt->execute();
$memes = $memes_stmt->get_result();
$memes_stmt->close();

// Fetch favourited memes (saved from others)
$favs_stmt = $conn->prepare("
    SELECT m.id, m.file_name, m.caption, m.type, m.created_at,
           u.username AS author, u.profile_pic AS author_pic
    FROM favorites f
    JOIN memes m ON m.id = f.meme_id
    JOIN users u ON u.id = m.uploaded_by
    WHERE f.user_id = ? AND m.file_name != '' AND m.file_name != '0' AND m.type != 'text'
    ORDER BY f.created_at DESC
");
$favs_stmt->bind_param("i", $uid);
$favs_stmt->execute();
$favs = $favs_stmt->get_result();
$favs_stmt->close();

// Counts
$own_count  = $memes->num_rows ?? 0;
$favs_count = $favs->num_rows ?? 0;

// Re-fetch since we used num_rows
$memes_stmt = $conn->prepare("
    SELECT id, file_name, caption, type, created_at
    FROM memes
    WHERE uploaded_by = ? AND file_name != '' AND file_name != '0' AND type != 'text'
    ORDER BY created_at DESC
");
$memes_stmt->bind_param("i", $uid);
$memes_stmt->execute();
$memes = $memes_stmt->get_result();
$memes_stmt->close();

$favs_stmt = $conn->prepare("
    SELECT m.id, m.file_name, m.caption, m.type, m.created_at,
           u.username AS author, u.profile_pic AS author_pic
    FROM favorites f
    JOIN memes m ON m.id = f.meme_id
    JOIN users u ON u.id = m.uploaded_by
    WHERE f.user_id = ? AND m.file_name != '' AND m.file_name != '0' AND m.type != 'text'
    ORDER BY f.created_at DESC
");
$favs_stmt->bind_param("i", $uid);
$favs_stmt->execute();
$favs = $favs_stmt->get_result();
$favs_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Downloads — Dezzme</title>
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:       #06050a;
  --bg2:      #0e0c14;
  --bg3:      #141220;
  --surface:  rgba(255,255,255,0.03);
  --border:   rgba(255,255,255,0.07);
  --border2:  rgba(255,255,255,0.13);
  --red:      #e63030;
  --red2:     #ff4444;
  --red-glow: rgba(230,48,48,0.22);
  --red-soft: rgba(230,48,48,0.08);
  --text:     #ece8e1;
  --muted:    rgba(236,232,225,0.38);
  --r:        13px;
}

*,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior:smooth; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Outfit', sans-serif;
  font-size: 14px;
  min-height: 100vh;
  overflow-x: hidden;
}

body::before {
  content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
  background:
    radial-gradient(ellipse at 15% 0%, rgba(230,48,48,.07) 0%, transparent 55%),
    radial-gradient(ellipse at 85% 100%, rgba(230,48,48,.05) 0%, transparent 50%);
}

body::after {
  content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
}

a { text-decoration:none; color:inherit; }

/* ── TOP BAR ── */
.topbar {
  position:sticky; top:0; z-index:100;
  display:flex; align-items:center; gap:14px;
  padding:0 24px; height:62px;
  background:rgba(6,5,10,.88);
  backdrop-filter:blur(32px);
  -webkit-backdrop-filter:blur(32px);
  border-bottom:1px solid var(--border);
  position:relative; z-index:10;
}
.topbar-back {
  width:36px; height:36px; border-radius:9px;
  background:var(--surface); border:1px solid var(--border);
  display:flex; align-items:center; justify-content:center;
  font-size:20px; color:var(--muted);
  transition:all .2s; flex-shrink:0;
}
.topbar-back:hover { border-color:var(--red); color:var(--red); }

.topbar-title {
  font-family:'Cormorant Garamond', serif;
  font-size:22px; font-weight:600;
  letter-spacing:.5px; flex:1;
  font-style:italic;
}
.topbar-user {
  display:flex; align-items:center; gap:8px;
  font-size:13px; color:var(--muted);
}
.topbar-user img {
  width:30px; height:30px; border-radius:50%;
  object-fit:cover; border:2px solid var(--red);
}

/* ── HERO ── */
.hero {
  padding:48px 24px 36px;
  position:relative; z-index:1;
  border-bottom:1px solid var(--border);
}
.hero-eyebrow {
  display:inline-flex; align-items:center; gap:6px;
  font-size:10px; font-weight:600; letter-spacing:2px;
  text-transform:uppercase; color:var(--red);
  margin-bottom:14px;
}
.hero h1 {
  font-family:'Cormorant Garamond', serif;
  font-size:clamp(36px,5vw,56px);
  font-weight:700; letter-spacing:-.5px;
  line-height:1.05; margin-bottom:10px;
  color:var(--text);
}
.hero h1 em { font-style:italic; color:var(--red); }
.hero-sub {
  font-size:14px; color:var(--muted);
  max-width:480px; line-height:1.6; margin-bottom:24px;
}

/* stats strip */
.hero-stats {
  display:flex; gap:24px; flex-wrap:wrap;
}
.h-stat {
  display:flex; align-items:center; gap:8px;
  padding:8px 16px; border-radius:50px;
  background:var(--surface); border:1px solid var(--border);
  font-size:13px;
}
.h-stat strong { color:var(--red); font-weight:700; font-size:16px; }

/* ── TABS ── */
.tabs {
  display:flex; gap:0;
  border-bottom:1px solid var(--border);
  padding:0 24px;
  position:relative; z-index:1;
  background:rgba(6,5,10,.5);
  backdrop-filter:blur(20px);
  position:sticky; top:62px; z-index:9;
}
.tab-btn {
  padding:14px 20px;
  font-family:'Outfit',sans-serif;
  font-size:13px; font-weight:500;
  color:var(--muted); cursor:pointer;
  border:none; background:none;
  border-bottom:2px solid transparent;
  transition:all .2s; white-space:nowrap;
  display:flex; align-items:center; gap:6px;
  position:relative; bottom:-1px;
}
.tab-btn i { font-size:16px; }
.tab-btn .tc {
  font-size:10px; font-weight:600;
  padding:1px 6px; border-radius:20px;
  background:rgba(255,255,255,.07);
}
.tab-btn:hover { color:var(--text); }
.tab-btn.active {
  color:var(--text);
  border-bottom-color:var(--red);
}
.tab-btn.active .tc { background:var(--red-soft); color:var(--red); }

/* ── PAGE CONTENT ── */
.page-content {
  padding:28px 24px 80px;
  max-width:1100px;
  position:relative; z-index:1;
}

/* ── SECTIONS ── */
.dl-section { display:none; }
.dl-section.active { display:block; }

/* ── GRID ── */
.dl-grid {
  display:grid;
  grid-template-columns:repeat(auto-fill, minmax(180px,1fr));
  gap:12px;
}

/* ── CARD ── */
.dl-card {
  background:var(--bg2);
  border:1px solid var(--border);
  border-radius:var(--r);
  overflow:hidden;
  display:flex; flex-direction:column;
  transition:border-color .2s, transform .2s, box-shadow .2s;
  animation:fadeUp .35s ease both;
}
.dl-card:hover {
  border-color:rgba(230,48,48,.3);
  transform:translateY(-3px);
  box-shadow:0 10px 32px rgba(0,0,0,.5);
}
@keyframes fadeUp {
  from { opacity:0; transform:translateY(12px); }
  to   { opacity:1; transform:translateY(0); }
}
.dl-card:nth-child(1)  { animation-delay:.03s }
.dl-card:nth-child(2)  { animation-delay:.06s }
.dl-card:nth-child(3)  { animation-delay:.09s }
.dl-card:nth-child(4)  { animation-delay:.12s }
.dl-card:nth-child(5)  { animation-delay:.15s }
.dl-card:nth-child(6)  { animation-delay:.18s }

/* media */
.dl-media {
  width:100%; aspect-ratio:1/1; overflow:hidden;
  background:#0a090f; position:relative;
}
.dl-media img,
.dl-media video {
  width:100%; height:100%; object-fit:cover;
  display:block; transition:transform .3s;
}
.dl-card:hover .dl-media img,
.dl-card:hover .dl-media video { transform:scale(1.04); }
.dl-type-badge {
  position:absolute; top:8px; left:8px;
  background:rgba(0,0,0,.7); backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.1);
  border-radius:6px; padding:2px 8px;
  font-size:10px; font-weight:600; color:rgba(255,255,255,.8);
  display:flex; align-items:center; gap:4px;
}
.dl-type-badge i { font-size:12px; }

/* card body */
.dl-body {
  padding:12px; flex:1; display:flex; flex-direction:column; gap:6px;
}
.dl-caption {
  font-size:12px; color:var(--muted);
  line-height:1.4;
  display:-webkit-box; -webkit-line-clamp:2;
  -webkit-box-orient:vertical; overflow:hidden;
}
.dl-author {
  display:flex; align-items:center; gap:6px; margin-top:2px;
}
.dl-author img {
  width:16px; height:16px; border-radius:50%;
  object-fit:cover; border:1px solid var(--red);
}
.dl-author span { font-size:11px; color:var(--muted); }

/* download btn */
.dl-btn {
  display:flex; align-items:center; justify-content:center; gap:6px;
  padding:9px; margin:0 12px 12px;
  background:var(--red-soft);
  border:1px solid rgba(230,48,48,.25);
  border-radius:8px;
  font-family:'Outfit',sans-serif;
  font-size:12px; font-weight:600;
  color:var(--red); cursor:pointer;
  text-decoration:none;
  transition:all .2s;
}
.dl-btn:hover {
  background:rgba(230,48,48,.18);
  border-color:rgba(230,48,48,.45);
  color:var(--red2);
}
.dl-btn i { font-size:15px; }

/* ── EMPTY STATE ── */
.empty {
  text-align:center; padding:72px 24px; color:var(--muted);
}
.empty i {
  font-size:56px; display:block; margin-bottom:16px;
  color:rgba(230,48,48,.2);
}
.empty h3 {
  font-family:'Cormorant Garamond',serif;
  font-size:22px; font-weight:600; color:rgba(255,255,255,.2);
  margin-bottom:8px;
}
.empty p { font-size:13px; line-height:1.6; }
.empty-link {
  display:inline-flex; align-items:center; gap:6px;
  margin-top:16px; padding:10px 20px; border-radius:50px;
  background:var(--red-soft); border:1px solid rgba(230,48,48,.25);
  color:var(--red); font-size:13px; font-weight:500;
  transition:all .2s;
}
.empty-link:hover { background:rgba(230,48,48,.15); }

/* ── SCROLL TOP ── */
.scroll-top {
  position:fixed; bottom:28px; right:20px; z-index:200;
  width:42px; height:42px; border-radius:50%;
  background:rgba(230,48,48,.12);
  border:1px solid rgba(230,48,48,.3);
  color:var(--red); font-size:20px;
  display:flex; align-items:center; justify-content:center;
  cursor:pointer; opacity:0; pointer-events:none;
  transition:opacity .3s, transform .3s;
}
.scroll-top.visible { opacity:1; pointer-events:all; }
.scroll-top:hover { transform:translateY(-3px); }

/* ── RESPONSIVE ── */
@media(max-width:768px) {
  .hero { padding:32px 16px 24px; }
  .topbar { padding:0 16px; }
  .tabs { padding:0 14px; }
  .tab-btn { padding:12px 14px; font-size:12px; }
  .tab-btn span.tab-label { display:none; }
  .page-content { padding:20px 14px 60px; }
  .dl-grid { grid-template-columns:repeat(2,1fr); gap:8px; }
}

@media(max-width:480px) {
  .hero h1 { font-size:32px; }
  .dl-grid { grid-template-columns:repeat(2,1fr); gap:8px; }
  .topbar-user span { display:none; }
  .hero-stats { gap:10px; }
  .h-stat { padding:6px 12px; font-size:12px; }
}
</style>
</head>
<body>

<header class="topbar">
  <a href="setting.php" class="topbar-back"><i class="ri-arrow-left-s-line"></i></a>
  <span class="topbar-title">Downloads</span>
  <div class="topbar-user">
    <img src="<?php echo htmlspecialchars($user['profile_pic'] ?? 'uploads/me.png'); ?>"
         onerror="this.src='uploads/me.png'">
    <span>@<?php echo htmlspecialchars($user['username']); ?></span>
  </div>
</header>

<section class="hero">
  <div class="hero-eyebrow"><i class="ri-download-cloud-2-line"></i> Your Library</div>
  <h1>Everything you've<br><em>saved on Peura</em></h1>
  <p class="hero-sub">Your uploaded memes and favourited content — all in one place, ready to download anytime.</p>
  <div class="hero-stats">
    <div class="h-stat"><strong><?php echo $own_count; ?></strong> Your Uploads</div>
    <div class="h-stat"><strong><?php echo $favs_count; ?></strong> Favourited</div>
    <div class="h-stat"><strong><?php echo $own_count + $favs_count; ?></strong> Total Files</div>
  </div>
</section>

<nav class="tabs">
  <button class="tab-btn active" data-tab="own" onclick="switchTab('own',this)">
    <i class="ri-folder-image-line"></i>
    <span class="tab-label">My Uploads</span>
    <span class="tc"><?php echo $own_count; ?></span>
  </button>
  <button class="tab-btn" data-tab="favs" onclick="switchTab('favs',this)">
    <i class="ri-bookmark-line"></i>
    <span class="tab-label">Favourites</span>
    <span class="tc"><?php echo $favs_count; ?></span>
  </button>
</nav>

<div class="page-content">

  <!-- OWN UPLOADS -->
  <div class="dl-section active" id="sec-own">
    <?php if ($memes && $memes->num_rows > 0): ?>
    <div class="dl-grid">
      <?php while ($m = $memes->fetch_assoc()):
        $ext  = strtolower(pathinfo($m['file_name'], PATHINFO_EXTENSION));
        $is_v = in_array($ext, ['mp4','webm','mov']);
        $src  = 'uploads/' . htmlspecialchars($m['file_name']);
        $cap  = htmlspecialchars(mb_substr($m['caption'] ?? '', 0, 60));
      ?>
      <div class="dl-card">
        <div class="dl-media">
          <?php if ($is_v): ?>
            <video src="<?php echo $src; ?>" muted playsinline preload="metadata"></video>
          <?php else: ?>
            <img src="<?php echo $src; ?>" loading="lazy" onerror="this.style.display='none'">
          <?php endif; ?>
          <div class="dl-type-badge">
            <i class="<?php echo $is_v ? 'ri-video-line' : 'ri-image-line'; ?>"></i>
            <?php echo $is_v ? 'Video' : 'Image'; ?>
          </div>
        </div>
        <div class="dl-body">
          <?php if ($cap): ?>
          <div class="dl-caption"><?php echo $cap; ?></div>
          <?php endif; ?>
          <div style="font-size:10px;color:var(--muted);"><?php echo date('M j, Y', strtotime($m['created_at'])); ?></div>
        </div>
        <a href="<?php echo $src; ?>" download class="dl-btn">
          <i class="ri-download-2-line"></i> Download
        </a>
      </div>
      <?php endwhile; ?>
    </div>
    <?php else: ?>
    <div class="empty">
      <i class="ri-folder-open-line"></i>
      <h3>Nothing uploaded yet</h3>
      <p>Your image and video memes will appear here once you upload them.</p>
      <a href="upload.php" class="empty-link"><i class="ri-upload-cloud-line"></i> Upload a Meme</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- FAVOURITES -->
  <div class="dl-section" id="sec-favs">
    <?php if ($favs && $favs->num_rows > 0): ?>
    <div class="dl-grid">
      <?php while ($f = $favs->fetch_assoc()):
        $ext  = strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION));
        $is_v = in_array($ext, ['mp4','webm','mov']);
        $src  = 'uploads/' . htmlspecialchars($f['file_name']);
        $cap  = htmlspecialchars(mb_substr($f['caption'] ?? '', 0, 60));
      ?>
      <div class="dl-card">
        <div class="dl-media">
          <?php if ($is_v): ?>
            <video src="<?php echo $src; ?>" muted playsinline preload="metadata"></video>
          <?php else: ?>
            <img src="<?php echo $src; ?>" loading="lazy" onerror="this.style.display='none'">
          <?php endif; ?>
          <div class="dl-type-badge">
            <i class="<?php echo $is_v ? 'ri-video-line' : 'ri-image-line'; ?>"></i>
            <?php echo $is_v ? 'Video' : 'Image'; ?>
          </div>
        </div>
        <div class="dl-body">
          <?php if ($cap): ?>
          <div class="dl-caption"><?php echo $cap; ?></div>
          <?php endif; ?>
          <div class="dl-author">
            <img src="<?php echo htmlspecialchars($f['author_pic'] ?? 'uploads/me.png'); ?>"
                 onerror="this.src='uploads/me.png'">
            <span>@<?php echo htmlspecialchars($f['author']); ?></span>
          </div>
        </div>
        <a href="<?php echo $src; ?>" download class="dl-btn">
          <i class="ri-download-2-line"></i> Download
        </a>
      </div>
      <?php endwhile; ?>
    </div>
    <?php else: ?>
    <div class="empty">
      <i class="ri-bookmark-line"></i>
      <h3>No favourites yet</h3>
      <p>Memes you bookmark from other Peurmers will appear here for download.</p>
    </div>
    <?php endif; ?>
  </div>

</div>

<button class="scroll-top" id="scrollTop" onclick="window.scrollTo({top:0,behavior:'smooth'})">
  <i class="ri-arrow-up-line"></i>
</button>

<script>
function switchTab(name, btn) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.dl-section').forEach(s => s.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('sec-' + name).classList.add('active');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

window.addEventListener('scroll', () => {
  document.getElementById('scrollTop').classList.toggle('visible', window.scrollY > 300);
});

// video hover play
document.addEventListener('mouseover', e => {
  const card = e.target.closest('.dl-card');
  if (!card) return;
  const v = card.querySelector('video');
  if (v) v.play().catch(() => {});
});
document.addEventListener('mouseout', e => {
  const card = e.target.closest('.dl-card');
  if (!card) return;
  const v = card.querySelector('video');
  if (v) { v.pause(); v.currentTime = 0; }
});
</script>
</body>
</html>