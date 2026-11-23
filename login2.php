<?php
session_start();
include 'config.php';

$message = "";

// Handle login
if(isset($_POST['login'])){
    $identifier = trim($_POST['username'] ?? "");
    $password   = $_POST['password'] ?? "";

    if($identifier && $password){
        $stmt = $conn->prepare("SELECT id, password FROM users WHERE username=? OR email=? OR phone=?");
        $stmt->bind_param("sss", $identifier, $identifier, $identifier);
        $stmt->execute();
        $res = $stmt->get_result();
        if($res->num_rows){
            $user = $res->fetch_assoc();
            if(password_verify($password, $user['password'])){
                $uid = (int)$user['id'];
                $_SESSION['user_id'] = $uid;

                $linked = $conn->query("SELECT linked_user_id FROM linked_accounts WHERE primary_user_id = $uid");
                $_SESSION['accounts'] = [$uid];
                while ($row = $linked->fetch_assoc()) {
                    $_SESSION['accounts'][] = (int)$row['linked_user_id'];
                }
                $_SESSION['accounts'] = array_unique($_SESSION['accounts']);

                header("Location: home.php");
                exit;
            } else {
                $message = "Incorrect password.";
            }
        } else {
            $message = "No account found with that username, email or phone.";
        }
    } else {
        $message = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<!-- PWA -->
<link rel="manifest" href="/Peura/manifest.json">
<meta name="theme-color" content="#0033CC">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Peura">
<link rel="apple-touch-icon" href="/Peura/Peura.jpg">
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/Peura/service-worker.js')
            .then(reg => console.log('Peura SW registered'))
            .catch(err => console.log('SW error:', err));
    });
}
</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title>Login | Dezzme</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Bebas+Neue&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* ── RESET ── */
*, *::before, *::after {
    margin: 0; padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

/* ── BODY — exact index.php background ── */
body {
    min-height: 100vh;
    width: 100vw;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background:
        radial-gradient(ellipse at 70% 80%, #00AAFF22 0%, transparent 55%),
        radial-gradient(ellipse at 30% 60%, #0055FF18 0%, transparent 50%),
        linear-gradient(
            to bottom,
            #000000   0%,
            #00003A  18%,
            #000880  35%,
            #0022CC  52%,
            #0044EE  65%,
            #1A6FFF  78%,
            #5BB0FF  90%,
            #B8D8FF 100%
        );
    background-attachment: fixed;
    overflow-x: hidden;
    padding: 40px 16px;
}

/* ── PARTICLE CANVAS ── */
canvas {
    position: fixed;
    top: 0; left: 0;
    z-index: 0;
    pointer-events: none;
}

/* ── BACK LINK ── */
.back-link {
    position: fixed;
    top: 22px;
    left: 28px;
    z-index: 10;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 22px;
    letter-spacing: 6px;
    color: #ffffff;
    text-decoration: none;
    transition: opacity 0.3s;
}
.back-link:hover { opacity: 0.7; }

/* ── MAIN WRAPPER ── */
.page-wrapper {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 860px;
    display: flex;
    gap: 20px;
    align-items: stretch;
    justify-content: center;
}

/* ── SHARED PANEL BASE ── */
.panel {
    flex: 1;
    min-width: 0;
    background: rgba(0, 8, 60, 0.45);
    backdrop-filter: blur(22px);
    -webkit-backdrop-filter: blur(22px);
    border: 1px solid rgba(100, 180, 255, 0.18);
    border-radius: 28px;
    padding: 36px 28px;
    display: flex;
    flex-direction: column;
    align-items: center;
    box-shadow:
        0 8px 40px rgba(0,0,0,0.5),
        inset 0 1px 0 rgba(255,255,255,0.08);
    transition: transform 0.3s, box-shadow 0.3s;
}
.panel:hover {
    transform: translateY(-3px);
    box-shadow:
        0 16px 50px rgba(0,0,0,0.6),
        inset 0 1px 0 rgba(255,255,255,0.1);
}

/* ── PANEL TITLE ── */
.panel-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 26px;
    letter-spacing: 5px;
    color: #000000;
    margin-bottom: 24px;
    text-align: center;
}

/* ── SOCIAL PILLS ── */
.social-login {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
}

.social-pill {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 16px;
    border-radius: 30px;
    border: 1px solid rgba(100, 180, 255, 0.2);
    background: rgba(0, 20, 80, 0.4);
    backdrop-filter: blur(10px);
    color: rgba(255,255,255,0.9);
    font-size: 0.82em;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.3s ease;
    overflow: hidden;
}
.social-pill:hover {
    background: rgba(0, 40, 120, 0.6);
    border-color: rgba(100, 180, 255, 0.45);
    transform: scale(1.03);
    box-shadow: 0 6px 22px rgba(0, 80, 255, 0.25);
}
.social-pill svg {
    width: 17px; height: 17px;
    flex-shrink: 0;
}
.pill-label { flex: 1; }

/* Last-used badge */
.last-used-tag {
    font-size: 0.62em;
    font-weight: 600;
    letter-spacing: 0.8px;
    color: #4c82ff;
    background: rgba(76, 112, 255, 0.15);
    border: 1px solid rgba(76, 130, 255, 0.35);
    padding: 2px 8px;
    border-radius: 20px;
    white-space: nowrap;
    display: none; /* shown via JS */
}
.social-pill.last-used .last-used-tag { display: inline-block; }
.social-pill.last-used {
    border-color: rgba(76, 115, 255, 0.35);
    background: rgba(0, 18, 40, 0.45);
}

/* ── FORM FIELDS ── */
.form-group {
    width: 100%;
    margin-bottom: 14px;
}
.form-group input {
    width: 100%;
    padding: 12px 16px;
    background: rgba(0, 20, 80, 0.4);
    border: 1px solid rgba(100,180,255,0.2);
    border-radius: 14px;
    color: #fff;
    font-size: 0.9em;
    outline: none;
    transition: border-color 0.3s, background 0.3s;
}
.form-group input::placeholder { color: rgba(200,220,255,0.5); }
.form-group input:focus {
    border-color: rgba(100,180,255,0.55);
    background: rgba(0, 30, 100, 0.5);
}

.password-container { position: relative; width: 100%; }
.password-container input { padding-right: 44px; }
.toggle-pass {
    position: absolute; top: 50%; right: 14px;
    transform: translateY(-50%);
    cursor: pointer; color: rgba(255,255,255,0.6);
    font-size: 1.05em;
    transition: color 0.2s;
}
.toggle-pass:hover { color: #fff; }

/* ── MESSAGE ── */
.message {
    color: #FFBABA;
    background: rgba(255,76,76,0.1);
    border: 1px solid rgba(255,76,76,0.25);
    border-radius: 10px;
    padding: 9px 14px;
    margin-bottom: 16px;
    text-align: center;
    font-size: 0.82em;
    width: 100%;
}

/* ── LOGIN BUTTON ── */
.btn-login {
    width: 100%;
    padding: 13px;
    border: none;
    border-radius: 20px;
    background: #021f66 ;
    color: white;
    font-weight: 600;
    font-size: 0.95em;
    letter-spacing: 1px;
    cursor: pointer;
    transition: all 0.35s ease;
    margin-bottom: 18px;
}
.btn-login:hover {
    background: linear-gradient(135deg, #051b76 0%, #557bee 100%);
    transform: scale(1.04);
}

/* ── WORD LINKS ── */
.links {
    text-align: center;
    font-size: 0.85em;
    color: rgba(200,220,255,0.7);
    line-height: 2;
    width: 100%;
}
.links a {
    color: #ea4b4b;
    text-decoration: none;
    margin: 0 5px;
    transition: color 0.3s;
}
.links a:hover { color: #066bbd; }

/* ── DIVIDER ── */
.divider {
    width: 100%;
    height: 1px;
    background: linear-gradient(to right, transparent, rgba(100,180,255,0.3), transparent);
    margin: 20px 0;
}

/* ══════════════════════════════
   TABLET ≤ 768px — stack vertically
══════════════════════════════ */
@media (max-width: 768px) {
    body { padding: 56px 14px 32px; }
    .page-wrapper {
        flex-direction: column;
        max-width: 400px;
        gap: 16px;
    }
    .panel { padding: 28px 20px; border-radius: 22px; }
    .panel-title { font-size: 22px; letter-spacing: 4px; margin-bottom: 18px; }
    .social-pill { font-size: 0.78em; padding: 9px 13px; }
    .social-pill svg { width: 15px; height: 15px; }
    .back-link { font-size: 18px; letter-spacing: 4px; top: 16px; left: 16px; }
}

@media (max-width: 480px) {
    body { padding: 52px 10px 28px; }
    .panel { padding: 22px 16px; border-radius: 18px; }
    .panel-title { font-size: 19px; letter-spacing: 3px; }
    .social-pill { font-size: 0.73em; }
    .form-group input { font-size: 0.83em; padding: 10px 13px; }
    .btn-login { font-size: 0.86em; padding: 11px; }
    .links { font-size: 0.78em; }
}

@media (max-width: 360px) {
    .panel { padding: 18px 12px; }
    .panel-title { font-size: 17px; }
    .social-pill { font-size: 0.68em; padding: 8px 10px; }
}

.toggle-pass i {
    color: rgba(200, 220, 255, 0.7);
    font-size: 1.1em;
    line-height: 1;
    display: block;
}
.toggle-pass:hover i { color: #fff; }
</style>
</head>
<body>

<canvas id="particles"></canvas>

<!-- Back to splash -->
<a href="index.php" class="back-link">Dezzme</a>

<div class="page-wrapper">

    <!-- ── LEFT PANEL: Social Sign-In ── -->
    <div class="panel">
        <div class="panel-title">Sign In With</div>

        <div class="social-login">

            <!-- Google -->
            <a href="google_callback.php" class="social-pill" data-provider="google">
                <svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#EA4335" d="M24 9.5c3.15 0 5.64 1.08 7.75 2.85l5.77-5.77C33.91 3.39 29.27 1.5 24 1.5 14.95 1.5 7.28 7.07 4.07 14.9l6.73 5.23C12.4 13.59 17.73 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.1 24.55c0-1.64-.15-3.22-.42-4.75H24v9h12.42c-.54 2.9-2.18 5.36-4.64 7.02l7.19 5.59C43.18 37.13 46.1 31.3 46.1 24.55z"/>
                    <path fill="#FBBC05" d="M10.8 28.87A14.5 14.5 0 0 1 9.5 24c0-1.69.29-3.32.8-4.87L3.57 13.9A22.44 22.44 0 0 0 1.5 24c0 3.61.87 7.02 2.4 10.03l6.9-5.16z"/>
                    <path fill="#34A853" d="M24 46.5c5.27 0 9.7-1.75 12.93-4.74l-7.19-5.59c-1.8 1.21-4.1 1.93-5.74 1.93-6.27 0-11.6-4.09-13.2-9.63l-6.9 5.16C7.28 40.93 14.95 46.5 24 46.5z"/>
                    <path fill="none" d="M0 0h48v48H0z"/>
                </svg>
                <span class="pill-label">Sign in with Google</span>
                <span class="last-used-tag">Last Used</span>
            </a>

            <!-- Discord -->
            <a href="discord_callback.php" class="social-pill" data-provider="discord">
                <svg viewBox="0 0 127.14 96.36" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#5865F2" d="M107.7,8.07A105.15,105.15,0,0,0,81.47,0a72.06,72.06,0,0,0-3.36,6.83A97.68,97.68,0,0,0,49,6.83,72.37,72.37,0,0,0,45.64,0,105.89,105.89,0,0,0,19.39,8.09C2.79,32.65-1.71,56.6.54,80.21h0A105.73,105.73,0,0,0,32.71,96.36,77.7,77.7,0,0,0,39.6,85.25a68.42,68.42,0,0,1-10.85-5.18c.91-.66,1.8-1.34,2.66-2a75.57,75.57,0,0,0,64.32,0c.87.71,1.76,1.39,2.66,2a68.68,68.68,0,0,1-10.87,5.19,77,77,0,0,0,6.89,11.1A105.25,105.25,0,0,0,126.6,80.22h0C129.24,52.84,122.09,29.11,107.7,8.07ZM42.45,65.69C36.18,65.69,31,60,31,53s5-12.74,11.43-12.74S54,46,53.89,53,48.84,65.69,42.45,65.69Zm42.24,0C78.41,65.69,73.25,60,73.25,53s5-12.74,11.44-12.74S96.23,46,96.12,53,91.08,65.69,84.69,65.69Z"/>
                </svg>
                <span class="pill-label">Sign in with Discord</span>
                <span class="last-used-tag">Last Used</span>
            </a>

            <!-- Spotify -->
            <a href="spotify_callback.php" class="social-pill" data-provider="spotify">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#1DB954" d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                </svg>
                <span class="pill-label">Sign in with Spotify</span>
                <span class="last-used-tag">Last Used</span>
            </a>

            <!-- GitHub -->
            <a href="github_callback.php" class="social-pill" data-provider="github">
                <svg viewBox="0 0 98 96" xmlns="http://www.w3.org/2000/svg">
                    <path fill="white" d="M48.854 0C21.839 0 0 22 0 49.217c0 21.756 13.993 40.172 33.405 46.69 2.427.49 3.316-1.059 3.316-2.362 0-1.141-.08-5.052-.08-9.127-13.59 2.934-16.42-5.867-16.42-5.867-2.184-5.704-5.42-7.17-5.42-7.17-4.448-3.015.324-3.015.324-3.015 4.934.326 7.523 5.052 7.523 5.052 4.367 7.496 11.404 5.378 14.235 4.074.404-3.178 1.699-5.378 3.074-6.6-10.839-1.141-22.243-5.378-22.243-24.283 0-5.378 1.94-9.778 5.014-13.2-.485-1.222-2.184-6.275.486-13.038 0 0 4.125-1.304 13.426 5.052a46.97 46.97 0 0 1 12.214-1.63c4.125 0 8.33.571 12.213 1.63 9.302-6.356 13.427-5.052 13.427-5.052 2.67 6.763.97 11.816.485 13.038 3.155 3.422 5.015 7.822 5.015 13.2 0 18.905-11.404 23.06-22.324 24.283 1.78 1.548 3.316 4.481 3.316 9.126 0 6.6-.08 11.897-.08 13.526 0 1.304.89 2.853 3.316 2.364 19.412-6.52 33.405-24.935 33.405-46.691C97.707 22 75.788 0 48.854 0z"/>
                </svg>
                <span class="pill-label">Sign in with GitHub</span>
                <span class="last-used-tag">Last Used</span>
            </a>

        </div>
    </div>

    <!-- ── RIGHT PANEL: Normal Login ── -->
    <div class="panel">
        <div class="panel-title">Login</div>

        <?php if($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" style="width:100%;">
            <div class="form-group">
                <input type="text" name="username" placeholder="Username, email or phone" required>
            </div>

            <div class="form-group password-container">
                <input type="password" id="password" name="password" placeholder="Password" required>
               <span class="toggle-pass" onclick="togglePassword()">
    <i id="eye-show" class="fa-solid fa-eye"></i>
    <i id="eye-hide" class="fa-solid fa-eye-slash" style="display:none;"></i>
</span>
            </div>

            <button type="submit" name="login" class="btn-login">Login</button>
        </form>

        <div class="links">
            <p>
                <a href="register.php">Register</a> &nbsp;|&nbsp;
                <a href="password.php">Forgot Password?</a>
            </p>
            <p>
                <a href="https://www.termsfeed.com/live/6c87c7a3-5e35-444f-950d-3517d4b3ba4f" target="_blank">Privacy Policy</a>
            </p>
        </div>

    </div>

</div>

<script>
/* ── PARTICLES (same as index.php) ── */
const canvas = document.getElementById('particles');
const ctx    = canvas.getContext('2d');

function resize() {
    canvas.width  = window.innerWidth;
    canvas.height = window.innerHeight;
}
resize();
window.addEventListener('resize', resize);

const particles = Array.from({ length: 120 }, () => ({
    x:       Math.random() * window.innerWidth,
    y:       Math.random() * window.innerHeight,
    size:    Math.random() * 1.4 + 0.3,
    speedX:  (Math.random() - 0.5) * 0.25,
    speedY:  (Math.random() - 0.5) * 0.25,
    opacity: Math.random() * 0.5 + 0.15
}));

function animate() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    particles.forEach(p => {
        p.x += p.speedX;
        p.y += p.speedY;
        if (p.x < 0 || p.x > canvas.width)  p.speedX *= -1;
        if (p.y < 0 || p.y > canvas.height) p.speedY *= -1;
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(200, 230, 255, ${p.opacity})`;
        ctx.fill();
    });
    requestAnimationFrame(animate);
}
animate();

/* ── PASSWORD TOGGLE ── */
function togglePassword() {
    const pass = document.getElementById('password');
    const show = document.getElementById('eye-show');
    const hide = document.getElementById('eye-hide');
    if (pass.type === 'password') {
        pass.type = 'text';
        show.style.display = 'none';
        hide.style.display = 'inline';
    } else {
        pass.type = 'password';
        show.style.display = 'inline';
        hide.style.display = 'none';
    }
}

/* ── LAST USED — tracks which social provider was clicked last ── */
(function() {
    const lastUsed = localStorage.getItem('dezzme_last_provider');
    if (lastUsed) {
        const pill = document.querySelector(`.social-pill[data-provider="${lastUsed}"]`);
        if (pill) pill.classList.add('last-used');
    }

    document.querySelectorAll('.social-pill').forEach(pill => {
        pill.addEventListener('click', () => {
            localStorage.setItem('dezzme_last_provider', pill.dataset.provider);
        });
    });
})();
</script>

</body>
</html>