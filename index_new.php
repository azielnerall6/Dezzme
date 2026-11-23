<?php
session_start();
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
<title>Dezzme</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Bebas+Neue&display=swap" rel="stylesheet">

<style>

/* ── RESET ── */
*, *::before, *::after {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

/* ── BODY ── */
body {
    height: 100vh;
    width: 100vw;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;

    /*
     * Static deep-space blue gradient — exact match to uploaded images:
     * pure black at top → deep navy → electric royal blue → sky blue/white at base
     */
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
}

/* ── PARTICLE CANVAS ── */
canvas {
    position: fixed;
    top: 0;
    left: 0;
    z-index: 0;
    pointer-events: none;
}

/* ── PAGE FADE-OUT TO LOGIN ── */
body.fade-out {
    opacity: 0;
    transition: opacity 1.2s ease;
}

/* ── SPLASH WRAPPER ── */
.splash {
    position: relative;
    z-index: 1;
    width: 100%;
    height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

/* ── LOGO BLOCK ── */
.logo-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    animation: logoReveal 0.9s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}

@keyframes logoReveal {
    0%   { opacity: 0; transform: scale(0.5); }
    100% { opacity: 1; transform: scale(1); }
}

.logo {
    width: 90px;
    height: 90px;
    object-fit: contain;
    border-radius: 22px;
    filter: drop-shadow(0 8px 28px rgba(0, 100, 255, 0.55));
    animation: floatLogo 5s ease-in-out infinite;
    animation-delay: 0.9s;
}

@keyframes floatLogo {
    0%   { transform: translateY(0); }
    50%  { transform: translateY(-10px); }
    100% { transform: translateY(0); }
}

/* ── BOTTOM BLOCK ── */
.bottom-block {
    position: absolute;
    bottom: 48px;
    left: 0;
    right: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    animation: fadeUp 0.9s ease 0.5s both;
}

@keyframes fadeUp {
    0%   { opacity: 0; transform: translateY(16px); }
    100% { opacity: 1; transform: translateY(0); }
}

/* ── DEZZME WORDMARK ── */
.brand-name {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 36px;
    color: #000000;
    letter-spacing: 10px;
    text-transform: uppercase;

    /* glowing electric-blue text shine */
    text-shadow:
        0 0 18px rgba(100, 180, 255, 0.9),
        0 0 40px rgba(60, 140, 255, 0.55),
        0 2px 6px rgba(0, 0, 0, 0.5);
}

/* ── TAGLINE ── */
.tagline-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: 280px;
}

/* thin glowing lines flanking the text */
.tagline-wrap::before,
.tagline-wrap::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(to right, transparent, rgba(100,180,255,0.6), transparent);
    min-width: 20px;
}

.tagline {
    font-family: 'Poppins', sans-serif;
    font-size: 11px;
    font-weight: 300;
    color: rgba(200, 230, 255, 0.85);
    letter-spacing: 1.8px;
    text-align: center;
    line-height: 1.6;
    text-transform: uppercase;

    /* soft blue glow on text */
    text-shadow:
        0 0 12px rgba(100, 180, 255, 0.5),
        0 1px 4px rgba(0, 0, 0, 0.4);
}

/* ── PROGRESS BAR (auto-redirect indicator) ── */
.progress-bar-wrap {
    width: 48px;
    height: 2px;
    background: rgba(255,255,255,0.15);
    border-radius: 2px;
    overflow: hidden;
    margin-top: 4px;
}

.progress-bar {
    height: 100%;
    width: 0%;
    background: linear-gradient(to right, #4499FF, #AADDFF);
    border-radius: 2px;
    box-shadow: 0 0 8px rgba(100,180,255,0.8);
    animation: fillBar 8s linear 1s both;
}

@keyframes fillBar {
    0%   { width: 0%; }
    100% { width: 100%; }
}

/* ══════════════════════════════
   TABLET  ≤ 768px
══════════════════════════════ */
@media (max-width: 768px) {

    .logo {
        width: 80px;
        height: 80px;
        border-radius: 20px;
    }

    .brand-name {
        font-size: 30px;
        letter-spacing: 8px;
    }

    .tagline {
        font-size: 10.5px;
        letter-spacing: 1.5px;
    }

    .tagline-wrap {
        max-width: 240px;
    }

    .bottom-block {
        bottom: 40px;
    }
}

/* ══════════════════════════════
   MOBILE  ≤ 480px
══════════════════════════════ */
@media (max-width: 480px) {

    body {
        overflow-y: auto;
    }

    .logo {
        width: 72px;
        height: 72px;
        border-radius: 18px;
    }

    .brand-name {
        font-size: 26px;
        letter-spacing: 7px;
    }

    .tagline {
        font-size: 9.5px;
        letter-spacing: 1.3px;
    }

    .tagline-wrap {
        max-width: 210px;
    }

    .bottom-block {
        bottom: 32px;
        gap: 6px;
    }
}

/* ══════════════════════════════
   SMALL PHONES  ≤ 360px
══════════════════════════════ */
@media (max-width: 360px) {

    .logo {
        width: 60px;
        height: 60px;
        border-radius: 14px;
    }

    .brand-name {
        font-size: 22px;
        letter-spacing: 5px;
    }

    .tagline {
        font-size: 9px;
        letter-spacing: 1px;
    }

    .tagline-wrap {
        max-width: 185px;
    }

    .bottom-block {
        bottom: 24px;
        gap: 5px;
    }
}

</style>
</head>

<body>

<canvas id="particles"></canvas>

<!-- SPLASH -->
<div class="splash">

    <!-- CENTER: logo -->
    <div class="logo-block">
        <img src="Dezzme.jpg" class="logo" alt="Dezzme">
    </div>

    <!-- BOTTOM: wordmark + styled tagline + progress bar -->
    <div class="bottom-block">

        <div class="brand-name">Dezzme</div>

        <div class="tagline-wrap">
            <span class="tagline">Number one destination for memes and internet culture</span>
        </div>

        <!-- thin glowing progress bar — fills over 8s then redirects -->
        <div class="progress-bar-wrap">
            <div class="progress-bar"></div>
        </div>

    </div>

</div>

<script>
/* ── AUTO-REDIRECT after 8s ── */
setTimeout(() => {
    document.body.classList.add('fade-out');
    setTimeout(() => {
        window.location.href = 'login.php';
    }, 1200); /* matches transition duration */
}, 8000);

/* ── PARTICLES (tiny white star-like dots — matches the starfield in the images) ── */
const canvas = document.getElementById('particles');
const ctx    = canvas.getContext('2d');

function resize() {
    canvas.width  = window.innerWidth;
    canvas.height = window.innerHeight;
}
resize();
window.addEventListener('resize', resize);

/* more particles, tiny sizes — looks like stars against dark space */
const particles = Array.from({ length: 120 }, () => ({
    x:      Math.random() * window.innerWidth,
    y:      Math.random() * window.innerHeight,
    size:   Math.random() * 1.4 + 0.3,
    speedX: (Math.random() - 0.5) * 0.25,
    speedY: (Math.random() - 0.5) * 0.25,
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
</script>

</body>
</html>