<?php
/* ═══════════════════════════════════════════════════════════════
   reset_password.php  —  Step 2: Validate token & set new password
   Dezzme — Production-level secure password reset
   ═══════════════════════════════════════════════════════════════ */

session_start();
include 'config.php';   // $conn

/* ── common password list (top 20 most-used — extend as needed) ── */
$COMMON_PASSWORDS = [
    'password','password1','123456','12345678','qwerty','abc123',
    'letmein','monkey','1234567890','iloveyou','admin','welcome',
    'login','passw0rd','master','hello','shadow','sunshine','princess','dragon'
];

/* ── helpers ──────────────────────────────────────────────────── */
function invalidateToken(mysqli $conn, int $tokenId): void {
    $s = $conn->prepare("UPDATE password_reset_tokens SET used=1 WHERE id=?");
    $s->bind_param('i', $tokenId);
    $s->execute();
    $s->close();
}

function killAllSessions(mysqli $conn, int $userId): void {
    // Remove all stored server-side sessions for this user
    $s = $conn->prepare("DELETE FROM user_sessions WHERE user_id=?");
    $s->bind_param('i', $userId);
    $s->execute();
    $s->close();
}

function isStrongPassword(string $pw, array $common): bool {
    if (strlen($pw) < 8)                        return false;
    if (!preg_match('/[A-Z]/', $pw))            return false;
    if (!preg_match('/[a-z]/', $pw))            return false;
    if (!preg_match('/[0-9]/', $pw))            return false;
    if (!preg_match('/[\W_]/', $pw))            return false;
    if (in_array(strtolower($pw), $common))     return false;
    return true;
}

/* ── CSRF ─────────────────────────────────────────────────────── */
if (empty($_SESSION['csrf_reset'])) {
    $_SESSION['csrf_reset'] = bin2hex(random_bytes(32));
}

/* ── validate token from URL ──────────────────────────────────── */
$rawToken   = trim($_GET['token'] ?? '');
$tokenValid = false;
$tokenRow   = null;
$tokenError = '';

if ($rawToken === '') {
    $tokenError = 'No reset token provided.';
} else {
    $hashToken = hash('sha256', $rawToken);

    $s = $conn->prepare(
        "SELECT id, user_id, expires_at, used
         FROM password_reset_tokens
         WHERE token_hash = ?
         LIMIT 1"
    );
    $s->bind_param('s', $hashToken);
    $s->execute();
    $res = $s->get_result();
    $row = $res->fetch_assoc();
    $s->close();

    if (!$row) {
        $tokenError = 'This reset link is invalid or has already been used.';
    } elseif ($row['used']) {
        $tokenError = 'This reset link has already been used. Please request a new one.';
    } elseif (strtotime($row['expires_at']) < time()) {
        $tokenError = 'This reset link has expired (links are valid for 15 minutes). Please request a new one.';
        // Clean up expired token
        invalidateToken($conn, $row['id']);
    } else {
        $tokenValid = true;
        $tokenRow   = $row;
    }
}

/* ── process new password ─────────────────────────────────────── */
$message     = '';
$messageType = '';
$done        = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {

    // CSRF check
    if (
        empty($_POST['csrf_reset']) ||
        !hash_equals($_SESSION['csrf_reset'], $_POST['csrf_reset'])
    ) {
        http_response_code(403);
        die('Invalid request.');
    }

    $newPw  = $_POST['new_password']     ?? '';
    $confPw = $_POST['confirm_password'] ?? '';

    if (empty($newPw) || empty($confPw)) {
        $message = 'Please fill in both password fields.';
        $messageType = 'error';

    } elseif ($newPw !== $confPw) {
        $message = 'Passwords do not match.';
        $messageType = 'error';

    } elseif (!isStrongPassword($newPw, $COMMON_PASSWORDS)) {
        $message = 'Password must be at least 8 characters and contain uppercase, lowercase, a number, and a special character. Common passwords are not allowed.';
        $messageType = 'error';

    } else {
        // Re-verify token is still valid (race-condition guard)
        $hashToken = hash('sha256', $rawToken);
        $s = $conn->prepare(
            "SELECT id, user_id, used, expires_at
             FROM password_reset_tokens
             WHERE token_hash=? AND used=0
             LIMIT 1"
        );
        $s->bind_param('s', $hashToken);
        $s->execute();
        $res  = $s->get_result();
        $live = $res->fetch_assoc();
        $s->close();

        if (!$live || strtotime($live['expires_at']) < time()) {
            $message = 'Your reset link expired or was already used. Please request a new one.';
            $messageType = 'error';

        } else {
            // 1. Hash new password with PASSWORD_BCRYPT (cost 12)
            $hashed = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);

            // 2. Update user record
            $upd = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $upd->bind_param('si', $hashed, $live['user_id']);
            $success = $upd->execute();
            $upd->close();

            if ($success) {
                // 3. Mark token as used (single-use)
                invalidateToken($conn, $live['id']);

                // 4. Kill ALL active sessions for this user
                killAllSessions($conn, $live['user_id']);

                // 5. Destroy current session & regenerate ID
                $_SESSION = [];
                session_destroy();
                session_start();
                session_regenerate_id(true);

                // 6. Rotate CSRF
                $_SESSION['csrf_reset'] = bin2hex(random_bytes(32));

                $message     = 'Your password has been updated successfully. You can now log in with your new password.';
                $messageType = 'success';
                $done        = true;
            } else {
                $message     = 'A database error occurred. Please try again.';
                $messageType = 'error';
            }
        }
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
        navigator.serviceWorker.register('/Peura/service-worker.js');
    });
}
</script>

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<title>Set New Password | Dezzme</title>
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Bebas+Neue&display=swap" rel="stylesheet">

<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}

body{
    min-height:100vh;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    background:
        radial-gradient(ellipse at 70% 80%,#00AAFF22 0%,transparent 55%),
        radial-gradient(ellipse at 30% 60%,#0055FF18 0%,transparent 50%),
        linear-gradient(to bottom,
            #000000  0%,#00003A 18%,#000880 35%,
            #0022CC 52%,#0044EE 65%,#1A6FFF 78%,
            #5BB0FF 90%,#B8D8FF 100%);
    background-attachment:fixed;
    color:white;
    padding:40px 16px;
}

canvas{position:fixed;inset:0;z-index:0;pointer-events:none;}

/* ── CARD ── */
.card{
    position:relative;z-index:2;
    width:100%;max-width:420px;
    background:rgba(0,10,40,0.55);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border:1px solid rgba(100,160,255,0.18);
    border-radius:28px;
    padding:44px 36px 36px;
    box-shadow:0 0 0 1px rgba(100,160,255,0.08),
               0 8px 40px rgba(0,0,0,0.55),
               0 0 80px rgba(0,60,255,0.12);
    text-align:center;
    animation:cardReveal 0.7s cubic-bezier(0.34,1.36,0.64,1) both;
}
@keyframes cardReveal{
    from{opacity:0;transform:translateY(24px) scale(0.97);}
    to  {opacity:1;transform:translateY(0)    scale(1);}
}

.logo-img{
    width:70px;border-radius:17px;
    filter:drop-shadow(0 6px 20px rgba(60,140,255,0.55));
    margin-bottom:6px;cursor:pointer;transition:transform 0.3s;
}
.logo-img:hover{transform:scale(1.08);}

.card-title{
    font-family:'Bebas Neue',sans-serif;
    font-size:26px;letter-spacing:5px;color:#fff;
    text-shadow:0 0 22px rgba(100,180,255,0.7);
    margin-bottom:4px;
}
.card-sub{
    font-size:12px;font-weight:300;
    color:rgba(180,210,255,0.7);letter-spacing:0.5px;
    margin-bottom:26px;line-height:1.6;
}

/* ── MESSAGE ── */
.message{
    font-size:13px;padding:12px 16px;border-radius:12px;
    margin-bottom:20px;line-height:1.55;text-align:left;
}
.message.success{
    background:rgba(0,200,100,0.15);
    border:1px solid rgba(0,200,100,0.3);color:#7FFFD4;
}
.message.error{
    background:rgba(255,60,60,0.15);
    border:1px solid rgba(255,60,60,0.3);color:#FFB3B3;
}

/* ── INPUT GROUP ── */
.input-group{position:relative;margin-bottom:16px;text-align:left;}
.input-group label{
    font-size:11px;font-weight:500;letter-spacing:1px;
    text-transform:uppercase;color:rgba(150,200,255,0.75);
    display:block;margin-bottom:7px;
}
.input-group i.icon-left{
    position:absolute;left:14px;bottom:13px;
    color:rgba(150,200,255,0.6);font-size:16px;
}
.input-group .eye-btn{
    position:absolute;right:14px;bottom:13px;
    background:none;border:none;padding:0;
    color:rgba(150,200,255,0.6);font-size:16px;
    cursor:pointer;transition:color 0.2s;
}
.input-group .eye-btn:hover{color:#fff;}

.input-group input{
    width:100%;
    padding:13px 42px 13px 42px;
    background:rgba(255,255,255,0.07);
    border:1px solid rgba(100,160,255,0.2);
    border-radius:13px;color:white;font-size:14px;
    outline:none;transition:border 0.25s,box-shadow 0.25s;
}
.input-group input::placeholder{color:rgba(180,210,255,0.4);}
.input-group input:focus{
    border-color:rgba(80,160,255,0.6);
    box-shadow:0 0 0 3px rgba(60,140,255,0.15);
}

/* ── STRENGTH METER ── */
.strength-wrap{margin-top:-8px;margin-bottom:14px;text-align:left;}
.strength-bar{
    height:3px;border-radius:3px;
    background:rgba(255,255,255,0.1);
    overflow:hidden;margin-bottom:4px;
}
.strength-fill{
    height:100%;width:0%;border-radius:3px;
    transition:width 0.4s,background 0.4s;
}
.strength-label{
    font-size:10.5px;color:rgba(180,210,255,0.55);letter-spacing:0.5px;
}

/* ── RULES ── */
.rules{
    font-size:11px;color:rgba(180,210,255,0.5);
    text-align:left;margin-bottom:18px;line-height:1.8;
}
.rules li{list-style:none;display:flex;align-items:center;gap:6px;}
.rules li i{font-size:12px;}
.rules li.ok{color:rgba(100,255,180,0.8);}
.rules li.ok i{color:#4DFFAA;}

/* ── BUTTON ── */
.btn-submit{
    width:100%;padding:14px;border:none;border-radius:14px;
    background:linear-gradient(135deg,#0044FF,#0088FF);
    color:white;font-size:14px;font-weight:600;letter-spacing:1px;
    cursor:pointer;transition:transform 0.25s,box-shadow 0.25s,opacity 0.25s;
    box-shadow:0 4px 20px rgba(0,80,255,0.4);margin-top:4px;
}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,80,255,0.55);}
.btn-submit:active{transform:translateY(0);opacity:0.9;}
.btn-submit:disabled{opacity:0.45;cursor:not-allowed;transform:none;}

/* ── LINKS ── */
.card-links{
    margin-top:22px;font-size:12.5px;
    color:rgba(180,210,255,0.6);
    display:flex;justify-content:center;gap:6px;flex-wrap:wrap;
}
.card-links a{color:rgba(120,180,255,0.85);text-decoration:none;transition:color 0.2s;}
.card-links a:hover{color:#fff;}
.card-links .sep{opacity:0.35;}

.privacy-note{
    font-size:11px;color:rgba(180,210,255,0.45);
    margin-top:18px;line-height:1.6;
}
.privacy-note a{color:rgba(120,180,255,0.7);text-decoration:none;}
.privacy-note a:hover{color:#fff;}

/* ── INVALID TOKEN STATE ── */
.token-error{
    background:rgba(255,60,60,0.12);
    border:1px solid rgba(255,60,60,0.25);
    border-radius:16px;padding:24px 20px;
    margin-bottom:20px;
}
.token-error i{font-size:32px;color:#FF7070;margin-bottom:10px;display:block;}
.token-error p{font-size:13px;color:#FFB3B3;line-height:1.6;}

/* responsive */
@media(max-width:768px){
    .card{max-width:360px;padding:36px 28px 30px;}
    .logo-img{width:60px;}
    .card-title{font-size:22px;}
}
@media(max-width:480px){
    body{padding:30px 12px;}
    .card{max-width:100%;padding:30px 20px 26px;border-radius:22px;}
    .logo-img{width:54px;border-radius:14px;}
    .card-title{font-size:20px;letter-spacing:4px;}
    .input-group input{font-size:13px;}
    .btn-submit{font-size:13px;padding:13px;}
}
@media(max-width:360px){
    .card{padding:26px 16px 22px;border-radius:18px;}
    .logo-img{width:48px;}
    .card-title{font-size:18px;letter-spacing:3px;}
    .input-group input{font-size:12px;}
    .btn-submit{font-size:12.5px;padding:12px;}
}
</style>
</head>
<body>

<canvas id="particles"></canvas>

<div class="card">

    <img src="Peura.jpg" class="logo-img" alt="Dezzme"
         onclick="window.location.href='index.php'">

    <?php if (!$tokenValid): ?>
    <!-- ── INVALID / EXPIRED TOKEN ── -->
    <div class="card-title">Link Invalid</div>
    <div class="token-error">
        <i class="ri-error-warning-line"></i>
        <p><?= htmlspecialchars($tokenError) ?></p>
    </div>
    <a href="password.php" class="btn-submit"
       style="display:block;padding:14px;text-decoration:none;margin-bottom:0;">
        Request New Reset Link
    </a>

    <?php elseif ($done): ?>
    <!-- ── SUCCESS ── -->
    <div class="card-title">Password Set!</div>
    <div class="message success">
        <i class="ri-checkbox-circle-line"></i>
        <?= htmlspecialchars($message) ?>
    </div>
    <a href="login.php" class="btn-submit"
       style="display:block;padding:14px;text-decoration:none;">
        <i class="ri-login-box-line"></i>&nbsp; Go to Login
    </a>

    <?php else: ?>
    <!-- ── RESET FORM ── -->
    <div class="card-title">New Password</div>
    <div class="card-sub">Choose a strong password for your Dezzme account.</div>

    <?php if ($message): ?>
    <div class="message <?= htmlspecialchars($messageType) ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <form method="POST"
          action="reset_password.php?token=<?= urlencode($rawToken) ?>"
          autocomplete="off" novalidate>

        <input type="hidden" name="csrf_reset"
               value="<?= htmlspecialchars($_SESSION['csrf_reset']) ?>">

        <!-- New password -->
        <div class="input-group">
            <label for="new_password">New Password</label>
            <i class="ri-lock-line icon-left"></i>
            <input type="password" id="new_password" name="new_password"
                   placeholder="Min 8 chars, upper, lower, number, symbol"
                   oninput="checkStrength(this.value)"
                   required>
            <button type="button" class="eye-btn" onclick="togglePw('new_password','eye1')">
                <i class="ri-eye-line" id="eye1"></i>
            </button>
        </div>

        <!-- Strength meter -->
        <div class="strength-wrap">
            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
            <div class="strength-label" id="strengthLabel">Enter a password</div>
        </div>

        <!-- Live rules -->
        <ul class="rules" id="rules">
            <li id="r-len"><i class="ri-close-circle-line"></i>At least 8 characters</li>
            <li id="r-upp"><i class="ri-close-circle-line"></i>One uppercase letter</li>
            <li id="r-low"><i class="ri-close-circle-line"></i>One lowercase letter</li>
            <li id="r-num"><i class="ri-close-circle-line"></i>One number</li>
            <li id="r-sym"><i class="ri-close-circle-line"></i>One special character (!@#$…)</li>
        </ul>

        <!-- Confirm password -->
        <div class="input-group">
            <label for="confirm_password">Confirm Password</label>
            <i class="ri-lock-2-line icon-left"></i>
            <input type="password" id="confirm_password" name="confirm_password"
                   placeholder="Re-enter your new password"
                   oninput="checkMatch()"
                   required>
            <button type="button" class="eye-btn" onclick="togglePw('confirm_password','eye2')">
                <i class="ri-eye-line" id="eye2"></i>
            </button>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn" disabled>
            <i class="ri-shield-check-line"></i>&nbsp; Update Password
        </button>

    </form>

    <?php endif; ?>

    <div class="card-links">
        <a href="login.php"><i class="ri-arrow-left-line"></i> Back to Login</a>
        <span class="sep">|</span>
        <a href="https://www.termsfeed.com/live/6c87c7a3-5e35-444f-950d-3517d4b3ba4f"
           target="_blank" rel="noopener">Privacy Policy</a>
    </div>

    <div class="privacy-note">
        By using Dezzme you agree to our
        <a href="https://www.termsfeed.com/live/6c87c7a3-5e35-444f-950d-3517d4b3ba4f"
           target="_blank" rel="noopener">Privacy Policy</a>.
    </div>

</div>

<script>
/* ── password toggle ── */
function togglePw(id, eyeId){
    const f = document.getElementById(id);
    const e = document.getElementById(eyeId);
    if(!f||!e) return;
    if(f.type==='password'){
        f.type='text';
        e.className='ri-eye-off-line';
    } else {
        f.type='password';
        e.className='ri-eye-line';
    }
}

/* ── strength checker ── */
const common = <?= json_encode($COMMON_PASSWORDS) ?>;

function checkStrength(pw){
    const fill   = document.getElementById('strengthFill');
    const label  = document.getElementById('strengthLabel');
    let score = 0;

    const ok = {
        len: pw.length >= 8,
        upp: /[A-Z]/.test(pw),
        low: /[a-z]/.test(pw),
        num: /[0-9]/.test(pw),
        sym: /[\W_]/.test(pw)
    };

    Object.values(ok).forEach(v => { if(v) score++; });
    const isCommon = common.includes(pw.toLowerCase());
    if(isCommon) score = 1;

    // update rule list
    setRule('r-len', ok.len);
    setRule('r-upp', ok.upp);
    setRule('r-low', ok.low);
    setRule('r-num', ok.num);
    setRule('r-sym', ok.sym);

    // bar
    const pct   = score * 20;
    const color = score<=1?'#FF4444':score<=2?'#FF8844':score<=3?'#FFCC44':score===4?'#88FF44':'#44FFAA';
    fill.style.width     = pct + '%';
    fill.style.background = color;

    const labels = ['','Weak','Weak','Fair','Good','Strong'];
    label.textContent = pw.length ? (isCommon ? 'Too common' : (labels[score]||'')) : 'Enter a password';
    label.style.color = pw.length ? color : 'rgba(180,210,255,0.55)';

    checkMatch();
}

function setRule(id, pass){
    const li = document.getElementById(id);
    if(!li) return;
    li.className = pass ? 'ok' : '';
    li.querySelector('i').className = pass ? 'ri-checkbox-circle-line' : 'ri-close-circle-line';
}

function checkMatch(){
    const pw   = document.getElementById('new_password')?.value  || '';
    const conf = document.getElementById('confirm_password')?.value || '';
    const btn  = document.getElementById('submitBtn');
    if(!btn) return;

    const allOk = pw.length>=8 && /[A-Z]/.test(pw) && /[a-z]/.test(pw) &&
                  /[0-9]/.test(pw) && /[\W_]/.test(pw) &&
                  !common.includes(pw.toLowerCase());
    btn.disabled = !(allOk && pw===conf && conf.length>0);
}

/* ── particles ── */
const canvas = document.getElementById('particles');
const ctx    = canvas.getContext('2d');
function resize(){ canvas.width=window.innerWidth; canvas.height=window.innerHeight; }
resize();
window.addEventListener('resize', resize);
const particles = Array.from({length:120},()=>({
    x:Math.random()*window.innerWidth,
    y:Math.random()*window.innerHeight,
    size:Math.random()*1.4+0.3,
    speedX:(Math.random()-0.5)*0.25,
    speedY:(Math.random()-0.5)*0.25,
    opacity:Math.random()*0.5+0.15
}));
function animate(){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    particles.forEach(p=>{
        p.x+=p.speedX; p.y+=p.speedY;
        if(p.x<0||p.x>canvas.width)  p.speedX*=-1;
        if(p.y<0||p.y>canvas.height) p.speedY*=-1;
        ctx.beginPath();
        ctx.arc(p.x,p.y,p.size,0,Math.PI*2);
        ctx.fillStyle=`rgba(200,230,255,${p.opacity})`;
        ctx.fill();
    });
    requestAnimationFrame(animate);
}
animate();
</script>

</body>
</html>