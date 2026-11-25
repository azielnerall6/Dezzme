<?php
/* FAST START: this page only needs the FIRST post — the player fetches its own run (60 posts at a time) in the background.
   So the page builds a small first page (8 posts, not 150), skips the home-only "people you track" bar, and shares its
   ranking session (seed + asof) with the player below, so the player's first request reuses the ranking instead of redoing it. */
$DZ_PAGE='home'; $DZ_TEXT_ONLY=false; $DZ_LEAN=true;
if(!defined('DZ_FEED_PAGE')) define('DZ_FEED_PAGE', 8);
require_once 'shared_core.php';
?>
<?php include 'timeout.php'; ?>
<?php
/* ================================================================
   zeems.php — the Zeems player as a page of its own (like Instagram Reels / TikTok)

   There is nothing here but the player: no feed, no cards. It uses exactly the same feed, ranking, likes, reposts,
   saves, comments, share and menus as home.php (shared_core.php does all of that), and opens zeems_modal.php on the
   first post as soon as the page loads. After that the player runs its own endless run of posts.
   Closing the player (the back arrow) takes you back to where you came from.

   Needs on the server: shared_core.php, timeout.php, shared_modals.php, comment_section.php, zeems_modal.php (same as home.php)
   ================================================================ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Zeems | Dezzme</title>
<!-- connect to the font / icon hosts right away, and fetch the icon + theme CSS before the page has finished loading -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<link rel="stylesheet" href="theme_css.php?v=<?= @filemtime(__DIR__.'/theme_css.php') ?>"> <!-- shared theme: colours, captions, feed (same as home.php) -->
<style>
*,*::before,*::after{ box-sizing:border-box; }
html,body{ margin:0; padding:0; height:100%; background:#000; color:#fff; font-family:'Poppins',sans-serif; -webkit-font-smoothing:antialiased; overflow:hidden; }
/* shown only for the split second before the player opens, or when there is nothing to play */
#zpLoad{ position:fixed; inset:0; z-index:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; background:#000; text-align:center; padding:0 24px; }
#zpLoad .zp-spin{ width:34px; height:34px; border-radius:50%; border:3px solid rgba(255,255,255,.18); border-top-color:#FF2D2D; animation:zpSpin .8s linear infinite; }
@keyframes zpSpin{ to{ transform:rotate(360deg); } }
#zpLoad .zp-msg{ font-size:.95rem; color:rgba(255,255,255,.72); }
#zpLoad a{ display:none; margin-top:4px; padding:10px 22px; border-radius:6px; background:#FF2D2D; color:#fff; font-weight:600; font-size:.9rem; text-decoration:none; }
#zpLoad.empty .zp-spin{ display:none; }
#zpLoad.empty a{ display:inline-block; }

/* ── pull down on the first post: the spinning wheel (refresh) ── */
#zpPtr{ position:fixed; left:50%; top:0; z-index:2147483100; width:40px; height:40px; margin-left:-20px; border-radius:50%; background:rgba(30,30,34,.92); box-shadow:0 4px 16px rgba(0,0,0,.5); display:flex; align-items:center; justify-content:center; opacity:0; pointer-events:none; transform:translateY(-60px); will-change:transform,opacity; }
#zpPtr svg{ width:22px; height:22px; display:block; }
#zpPtr circle{ fill:none; stroke:#FF2D2D; stroke-width:3; stroke-linecap:round; }
#zpPtr.spin svg{ animation:zpSpin .75s linear infinite; }
#zpPtr.snap{ transition:transform .22s cubic-bezier(.22,1,.36,1), opacity .22s ease; }
/* ── first visit: a short film that shows how to scroll ── */
#zpHint{ position:fixed; inset:0; z-index:2147483050; pointer-events:none; display:flex; flex-direction:column; align-items:center; justify-content:center; background:radial-gradient(ellipse at center, rgba(0,0,0,.55), rgba(0,0,0,.72)); opacity:0; transition:opacity .35s ease; }
#zpHint.on{ opacity:1; }
#zpHint .zh-stage{ position:relative; width:90px; height:230px; }
#zpHint .zh-track{ position:absolute; left:50%; top:8px; bottom:8px; width:2px; margin-left:-1px; background:linear-gradient(to top, rgba(255,255,255,.0), rgba(255,255,255,.35)); border-radius:2px; }
#zpHint .zh-dot{ position:absolute; left:50%; bottom:14px; width:58px; height:58px; margin-left:-29px; border-radius:50%; background:rgba(255,255,255,.92); box-shadow:0 0 0 10px rgba(255,255,255,.18), 0 8px 24px rgba(0,0,0,.5); animation:zhSwipe 1.9s cubic-bezier(.45,0,.2,1) infinite; }
#zpHint .zh-chev{ position:absolute; left:50%; width:22px; height:22px; margin-left:-11px; border-left:3px solid #fff; border-top:3px solid #fff; transform:rotate(45deg); opacity:0; animation:zhChev 1.9s ease-in-out infinite; }
#zpHint .zh-chev.c1{ top:6px; } #zpHint .zh-chev.c2{ top:28px; animation-delay:.12s; }
#zpHint .zh-txt{ margin-top:26px; font-size:1.05rem; font-weight:600; color:#fff; text-align:center; text-shadow:0 2px 10px rgba(0,0,0,.6); }
#zpHint .zh-sub{ margin-top:6px; font-size:.82rem; color:rgba(255,255,255,.7); text-align:center; }
@keyframes zhSwipe{ 0%{ transform:translateY(0) scale(.82); opacity:0; } 12%{ transform:translateY(0) scale(1); opacity:1; } 72%{ transform:translateY(-150px) scale(1); opacity:1; } 100%{ transform:translateY(-170px) scale(.9); opacity:0; } }
@keyframes zhChev{ 0%,30%{ opacity:0; } 60%{ opacity:.9; } 100%{ opacity:0; } }
/* ── bottom bar (nav.php) on this page: its normal colours, just kept above the player ── */
body{ padding:0 !important; }
html body .sidebar{ z-index:2147483000 !important; }
.dz-menu{ z-index:2147483200 !important; }
</style>
</head>
<body>

<div id="zpPtr" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-dasharray="42 60"/></svg></div>
<div id="zpHint" aria-hidden="true"><div class="zh-stage"><div class="zh-track"></div><i class="zh-chev c1"></i><i class="zh-chev c2"></i><div class="zh-dot"></div></div><div class="zh-txt">Swipe up for the next Zeem</div><div class="zh-sub">Swipe down to go back</div></div>
<div id="zpLoad">
    <div class="zp-spin"></div>
    <div class="zp-msg" id="zpMsg">Loading Zeems…</div>
    <a href="index.php">Go back</a>
</div>

<?php include 'nav.php'; /* bottom bar: put right at the top so it is on screen from the very first moment (it used to appear only after the player had loaded) */ ?>

<!-- ═══ FEED DATA — must sit BEFORE the zeems modal include (it reads peuraFeed) ═══ -->
<script>
var peuraFeed = <?= json_encode($_peura_feed_data, $_json_flags) ?>;
var currentUserId = <?= (int)$user_id ?>;
window.kebabData = <?= json_encode($_kebab_data, $_json_flags | JSON_FORCE_OBJECT) ?>;
window.CCM_PAGE_NAV = true;    /* the bottom bar (nav.php) is shown under the player */
window.CCM_PAGE_MODE = true;   /* this page IS the player: no back arrow, "Zeems" word at the left */
window.CCM_ENDPOINT = 'zeems.php';   /* the player sends its like / repost / save / track buttons to this page (shared_core.php answers them) */

/* people suggestions shown between posts */
window._suggPromise = fetch('zeems.php?type=sugg', {credentials:'same-origin'}).then(function(r){ return r.json(); })
    .then(function(u){ window.cmSuggUsers = Array.isArray(u) ? u : []; return window.cmSuggUsers; })
    .catch(function(){ window.cmSuggUsers = []; return []; });
</script>

<?php
/* ── the first post's picture (or video poster) starts downloading NOW, while the rest of the page is still being read ── */
$__zp0 = (is_array($_peura_feed_data) && !empty($_peura_feed_data[0]) && is_array($_peura_feed_data[0])) ? $_peura_feed_data[0] : null;
if ($__zp0 && !empty($__zp0['file']) && is_string($__zp0['file']) && in_array(strtolower((string)($__zp0['type'] ?? '')), ['image', 'gif', 'sticker'], true)) {
    echo '<link rel="preload" as="image" href="' . htmlspecialchars($__zp0['file'], ENT_QUOTES) . '" fetchpriority="high">' . "\n";
}
?>

<?php
/* ═══ The menu (⋯) / Save / Report sheets and the comment sheet are NOT needed to show the first post ═══
   They are put aside inside an inert <template> (the browser does not parse or run them), the player opens straight away,
   and they are switched on a moment later — or instantly on the first touch, so a tap on ⋯ or the comment icon always finds them ready. */
ob_start();
include 'shared_modals.php';    /* post menu (⋯), Save to Collection sheet, Report modal */
include 'comment_section.php';  /* comments sheet */
$__zp_lazy = ob_get_clean();
?>
<template id="zpLazy"><?= $__zp_lazy ?></template>
<script>
(function(){
    var done = false;
    window.zpWake = function(){
        if(done) return; done = true;
        var t = document.getElementById('zpLazy'); if(!t || !t.content) return;
        var frag = t.content.cloneNode(true);
        /* scripts inside a <template> must be re-created to run, in the same order */
        Array.prototype.slice.call(frag.querySelectorAll('script')).forEach(function(old){
            var s = document.createElement('script');
            for(var i = 0; i < old.attributes.length; i++) s.setAttribute(old.attributes[i].name, old.attributes[i].value);
            if(old.src) s.async = false; else s.text = old.textContent;
            old.parentNode.replaceChild(s, old);
        });
        document.body.appendChild(frag);
    };
    ['pointerdown','touchstart','keydown','mousedown'].forEach(function(ev){ window.addEventListener(ev, window.zpWake, { capture:true, once:true, passive:true }); });
})();
</script>
<?php include 'zeems_modal.php'; /* the player itself */ ?>
<script>
/* hand the page's ranking session to the player: its first background request (page 1) then hits the ranking the server already remembers */
try{ if(window._ccmOwn && !window._ccmOwn.seed){ _ccmOwn.seed = <?= (int)($feed_seed ?? 0) ?>; _ccmOwn.asof = <?= (int)($feed_asof ?? 0) ?>; } }catch(e){}
</script>

<script>
(function(){
    var opened = false, leaving = false;

    /* go back to where the person came from; if there is nowhere to go back to, home */
    function leave(){
        if(leaving) return; leaving = true;
        if(history.length > 1){
            history.back();
            /* the browser stayed on this page (nothing to go back to): go home instead */
            setTimeout(function(){ if(leaving && !document.hidden) location.replace('home.php'); }, 700);
        } else location.replace('home.php');
    }
    document.addEventListener('zeems:closed', leave);

    function empty(){
        var l = document.getElementById('zpLoad'); if(l) l.classList.add('empty');
        var m = document.getElementById('zpMsg'); if(m) m.textContent = 'There are no Zeems to show right now.';
    }

    /* open on the first post; the player then keeps feeding itself */
    function start(tries){
        if(opened) return;
        if(!peuraFeed || !peuraFeed.length){ empty(); return; }
        if(typeof openCardModal !== 'function'){
            if((tries || 0) < 40){ setTimeout(function(){ start((tries || 0) + 1); }, 100); } else { empty(); }
            return;
        }
        opened = true;
        var p = peuraFeed[0];
        try{
            openCardModal(p.file, p.type, p.pic, p.userId, p.username, p.caption, p.id);
            var l = document.getElementById('zpLoad'); if(l) requestAnimationFrame(function(){ requestAnimationFrame(function(){ l.style.display = 'none'; }); });
        }catch(e){ opened = false; if(window.console) console.error('zeems.php: could not open the player', e); empty(); }
    }
    /* this script sits at the very end of the page, so everything above it is already here: open the player NOW (no waiting for DOMContentLoaded) */
    start(0);
    /* a moment after the first post is up, switch on the menu / comment sheets in the background */
    (window.requestIdleCallback ? function(f){ requestIdleCallback(f, { timeout: 1200 }); } : function(f){ setTimeout(f, 600); })(function(){ if(window.zpWake) window.zpWake(); });

    /* coming back to this page from the browser's back/forward cache: the player was closed on the way out, open it again */
    window.addEventListener('pageshow', function(e){ if(e.persisted){ leaving = false; opened = false; start(0); } });
})();
</script>
<script>
/* pull down on the FIRST post = refresh (spinning wheel) — it only watches the finger, it never changes how Zeems swipes */
(function(){
    var TH = 110, st = null, el = document.getElementById('zpPtr'), busy = false;
    function ok(t){
        if(busy || typeof _ccmIndex === 'undefined' || _ccmIndex !== 0) return false;
        var m = document.getElementById('cardCaptionModal'); if(!m || !m.classList.contains('ccm-open')) return false;
        if(m.classList.contains('ccm-fsview') || m.classList.contains('ccm-peeking')) return false;
        if(document.documentElement.classList.contains('cs-open')) return false;
        try{ if(window._ccmHStack && _ccmHStack.length) return false; }catch(e){}
        try{ if(window._ccmCaptionExpanded) return false; }catch(e){}
        if(t && t.closest && t.closest('.ccm-ai-qs, .ccm-video-progress, .ccm-caption-wrap, .ccm-center-caption-wrap, input, textarea, [contenteditable="true"], .ccm-fs-bar, .idx-sheet, #indexSheetPanel, #csSheet, #csOverlay')) return false;
        return true;
    }
    function put(p){ el.style.opacity = String(Math.min(1, p * 1.4)); el.style.transform = 'translateY(' + (Math.min(p, 1) * 96 + 8 - 60) + 'px) rotate(' + (p * 300) + 'deg)'; }
    function reset(){ el.classList.add('snap'); el.style.opacity = '0'; el.style.transform = 'translateY(-60px)'; setTimeout(function(){ el.classList.remove('snap'); }, 260); }
    document.addEventListener('touchstart', function(e){
        st = null;
        if(!e.touches || e.touches.length !== 1 || !ok(e.target)) return;
        st = { x:e.touches[0].clientX, y:e.touches[0].clientY, on:false, dead:false, dy:0 };
    }, { capture:true, passive:true });
    document.addEventListener('touchmove', function(e){
        if(!st || st.dead) return;
        if(e.touches.length !== 1 || !ok(null)){ st.dead = true; if(st.on) reset(); return; }
        var dx = e.touches[0].clientX - st.x, dy = e.touches[0].clientY - st.y;
        if(!st.on){
            if(dy < -8 || Math.abs(dx) > 14 && Math.abs(dx) > Math.abs(dy)){ st.dead = true; return; }   /* up or sideways: not a pull */
            if(dy > 14 && dy > 1.7 * Math.abs(dx)){ st.on = true; el.classList.remove('snap'); } else return;
        }
        st.dy = dy; put(dy / TH);
    }, { capture:true, passive:true });
    function end(cancel){
        if(!st) return; var s = st; st = null;
        if(!s.on) return;
        if(!cancel && s.dy >= TH){
            busy = true; el.classList.add('snap'); el.style.opacity = '1'; el.style.transform = 'translateY(52px)'; el.classList.add('spin');
            try{ if(window.dzHaptic && dzHaptic.light) dzHaptic.light(); }catch(e){}
            setTimeout(function(){ location.reload(); }, 450);
        } else reset();
    }
    document.addEventListener('touchend', function(){ end(false); }, { capture:true, passive:true });
    document.addEventListener('touchcancel', function(){ end(true); }, { capture:true, passive:true });
})();

/* first Zeems visit after each login: a short film shows how to scroll, then it is never shown again */
(function(){
    /* shown once for every login: the token changes each time this account logs in, so it plays again after the next login */
    var key = 'dz_zp_hint_v2_<?= (int)$user_id ?>', tok = <?= json_encode((string)($_SESSION['dz_login_at'] ?? substr(sha1(session_id()), 0, 16))) ?>, seen = false;
    try{ seen = localStorage.getItem(key) === tok; }catch(e){}
    var h = document.getElementById('zpHint'); if(!h || seen) { if(h) h.parentNode.removeChild(h); return; }
    var shown = false, timer = null;
    function done(){
        if(!shown) return; shown = false; clearTimeout(timer);
        try{ localStorage.setItem(key, tok); }catch(e){}
        h.classList.remove('on'); setTimeout(function(){ if(h.parentNode) h.parentNode.removeChild(h); }, 400);
        ['touchstart','pointerdown','mousedown','keydown','wheel'].forEach(function(ev){ window.removeEventListener(ev, done, true); });
    }
    function show(){
        if(typeof peuraFeed === 'undefined' || !peuraFeed || peuraFeed.length < 2) return;
        shown = true; h.classList.add('on');
        /* any touch ends it, and the touch still goes to the player, so scrolling works straight away */
        ['touchstart','pointerdown','mousedown','keydown','wheel'].forEach(function(ev){ window.addEventListener(ev, done, { capture:true, passive:true }); });
        timer = setTimeout(done, 7000);
    }
    setTimeout(show, 1300);
})();
</script>
</body>
</html>