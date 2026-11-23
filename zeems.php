<?php $DZ_PAGE='home';     $DZ_TEXT_ONLY=false; require_once 'shared_core.php'; ?>
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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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

/* ── bottom bar (nav.php) on this page: its normal colours, just kept above the player ── */
body{ padding:0 !important; }
html body .sidebar{ z-index:2147483000 !important; }
.dz-menu{ z-index:2147483200 !important; }
</style>
</head>
<body>

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

<?php include 'shared_modals.php'; /* post menu (⋯), Save to Collection sheet, Report modal */ ?>
<?php include 'comment_section.php'; /* comments sheet */ ?>
<?php include 'zeems_modal.php'; /* the player itself */ ?>

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
            var l = document.getElementById('zpLoad'); if(l) setTimeout(function(){ l.style.display = 'none'; }, 400);
        }catch(e){ opened = false; if(window.console) console.error('zeems.php: could not open the player', e); empty(); }
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){ start(0); });
    else start(0);

    /* coming back to this page from the browser's back/forward cache: the player was closed on the way out, open it again */
    window.addEventListener('pageshow', function(e){ if(e.persisted){ leaving = false; opened = false; start(0); } });
})();
</script>
</body>
</html>