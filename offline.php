<?php
/* ════════════════════════════════════════════════════════════════════
   offline.php — the "No internet connection · Retry" state, shared by
   home.php, dezztext.php and any other page.

   HOW TO USE IN A PAGE
     1) near the top, after config.php:     require_once 'offline.php';
     2) inside <head>:                      <?php dz_offline_styles(); ?>
     3) right after the opening <body> tag: <?php dz_offline_ui(); ?>
        (pages without .ipv-post, e.g. profile.php / group_page.php: <?php dz_offline_ui('body'); ?> = banner only mid-session)
     4) the page must answer  ?type=ping  with an instant empty 204 (see the top of profile.php / group_page.php)
        (it must come before the page's own scripts so it can watch their requests)

   WHAT IT DOES
     - Shows a red banner at the top ("No internet connection · Retry") when the
       connection drops while the page is open, and a green "Back online" when it returns.
     - Shows a full-screen "No internet connection" card with a Retry button when the
       page is opened without internet, or when there is nothing on screen to look at.
     - Watches every fetch() and XMLHttpRequest of the page. A failed request is only
       treated as "offline" after a quick check to the server confirms it (so one broken
       image or a slow answer never causes a false alarm).
     - Retry checks the connection again. If the page was opened offline it then reloads
       itself; otherwise it just dismisses the message and keeps you where you were.

   NOTE: a page that cannot be downloaded at all (no internet before it ever loads) can only
   be replaced by a service worker. See offline.html and offline-sw-snippet.js for that part.
════════════════════════════════════════════════════════════════════ */

function dz_offline_styles(){
    static $done = false; if($done) return; $done = true;
    echo "<style>\n" . <<<'DZOFFCSS'
/* OFFLINE STATE */
.dzoff-banner{position:fixed;top:0;left:0;right:0;z-index:100060;display:flex;align-items:center;justify-content:center;gap:10px;
  padding:max(8px,env(safe-area-inset-top)) 12px 8px;background:#dc3838;color:#000;
  font-family:'Poppins',system-ui,sans-serif;font-size:calc(.78rem * var(--ccm-s,1));font-weight:600;line-height:1.3;
  transform:translateY(-120%);transition:transform .25s ease,background .25s ease}
.dzoff-banner.show{transform:translateY(0)}
.dzoff-banner.ok{background:#4dde8a}
.dzoff-banner button{height:calc(24px * var(--ccm-s,1));padding:0 calc(12px * var(--ccm-s,1));border:none;border-radius:5px;background:#000;color:#fff;
  font-family:inherit;font-size:calc(.72rem * var(--ccm-s,1));font-weight:700;cursor:pointer;flex-shrink:0}
.dzoff-banner.ok button{display:none}
@media(min-width:769px){.dzoff-banner{left:var(--sidebar-w,100px)}}

.dzoff-state{position:fixed;inset:0;z-index:100050;display:none;align-items:center;justify-content:center;padding:24px;text-align:center;
  background:linear-gradient(-45deg,#010218,#070924,#070924);color:#fff;font-family:'Poppins',system-ui,sans-serif}
.dzoff-state.show{display:flex}
@media(min-width:769px){.dzoff-state{left:var(--sidebar-w,100px)}}
.dzoff-card{width:100%;max-width:340px}
.dzoff-ic{width:calc(64px * var(--ccm-s,1));height:calc(64px * var(--ccm-s,1));margin:0 auto calc(16px * var(--ccm-s,1));border-radius:50%;
  display:flex;align-items:center;justify-content:center;color:#FF2D2D;background:rgba(255,45,45,.14);border:1px solid rgba(255,45,45,.35)}
.dzoff-ic svg{width:48%;height:48%;display:block}
.dzoff-title{font-size:calc(.98rem * var(--ccm-s,1));font-weight:700}
.dzoff-text{margin:8px 0 calc(20px * var(--ccm-s,1));font-size:calc(.74rem * var(--ccm-s,1));line-height:1.7;color:rgba(255,255,255,.62)}
.dzoff-msg{min-height:1.4em;margin:-10px 0 12px;font-size:calc(.7rem * var(--ccm-s,1));color:#ff7d7d}
.dzoff-btn{height:calc(30px * var(--ccm-s,1));min-width:calc(140px * var(--ccm-s,1));padding:0 calc(22px * var(--ccm-s,1));border:none;border-radius:5px;
  background:#dc3838;color:#000;font-family:inherit;font-size:calc(.79rem * var(--ccm-s,1));font-weight:700;cursor:pointer;
  transition:background .2s ease,transform .15s ease}
.dzoff-btn:hover{background:#c92f2f}
.dzoff-btn:active{transform:scale(.98)}
.dzoff-btn:disabled{opacity:.6;cursor:default}
DZOFFCSS
    . "\n</style>\n";
}

function dz_offline_ui($keepSel = '.ipv-post'){
    static $done = false; if($done) return; $done = true;
    ?>
<div class="dzoff-banner" id="dzOffBanner" role="status" aria-live="polite">
    <span id="dzOffBannerText">No internet connection</span>
    <button type="button" id="dzOffBannerBtn" onclick="dzNet.retry()">Retry</button>
</div>
<div class="dzoff-state" id="dzOffState" role="alert">
    <div class="dzoff-card">
        <div class="dzoff-ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
        </div>
        <div class="dzoff-title">No internet connection</div>
        <div class="dzoff-text">Check your Wi-Fi or mobile data, then try again.</div>
        <div class="dzoff-msg" id="dzOffMsg"></div>
        <button type="button" class="dzoff-btn" id="dzOffBtn" onclick="dzNet.retry()">Retry</button>
    </div>
</div>
<script>
var DZOFF_KEEP = <?php echo json_encode((string)$keepSel); ?>;   /* if this exists on the page when the connection drops, only the banner shows (the page stays usable); otherwise the full-screen card */
<?php echo <<<'DZOFFJS'
(function(){
    var $ = function(id){ return document.getElementById(id); };
    var origFetch = window.fetch ? window.fetch.bind(window) : null;
    var startedOffline = (navigator.onLine === false);
    var shown = false, fullShown = false, checking = false, okTimer = null;

    /* is the server reachable? (uses the untouched fetch so it can't loop back into this code) */
    function ping(){
        if(!origFetch) return Promise.resolve(navigator.onLine !== false);
        var ctl = ('AbortController' in window) ? new AbortController() : null;
        var t = setTimeout(function(){ if(ctl) ctl.abort(); }, 5000);
        return origFetch(location.pathname + '?type=ping&_=' + Date.now(), {cache:'no-store', credentials:'same-origin', signal: ctl ? ctl.signal : undefined})
            .then(function(r){ clearTimeout(t); return r.status < 500; })
            .catch(function(){ clearTimeout(t); return false; });
    }

    function show(){
        if(shown) return; shown = true; clearTimeout(okTimer);
        var full = startedOffline || !document.querySelector(DZOFF_KEEP);
        var b = $('dzOffBanner'); b.classList.remove('ok'); $('dzOffBannerText').textContent = 'No internet connection';
        if(full){ fullShown = true; $('dzOffState').classList.add('show'); }
        else { b.classList.add('show'); }
    }
    function hideAll(){ $('dzOffState').classList.remove('show'); $('dzOffBanner').classList.remove('show'); }

    function recovered(){
        shown = false;
        if(startedOffline || fullShown){ location.reload(); return; }   /* page was opened offline → load it fresh */
        var b = $('dzOffBanner');
        $('dzOffState').classList.remove('show');
        b.classList.add('ok','show'); $('dzOffBannerText').textContent = 'Back online';
        okTimer = setTimeout(function(){ b.classList.remove('show'); setTimeout(function(){ b.classList.remove('ok'); }, 300); }, 2200);
    }

    /* a request failed: only believe it after the server check fails too */
    function maybe(){
        if(shown || checking) return;
        if(navigator.onLine === false){ show(); return; }
        checking = true;
        ping().then(function(ok){ checking = false; if(!ok) show(); });
    }

    function retry(){
        var btns = [$('dzOffBtn'), $('dzOffBannerBtn')], msg = $('dzOffMsg');
        btns.forEach(function(b){ b.disabled = true; });
        $('dzOffBtn').textContent = 'Checking…'; $('dzOffBannerBtn').textContent = '…'; msg.textContent = '';
        ping().then(function(ok){
            btns.forEach(function(b){ b.disabled = false; });
            $('dzOffBtn').textContent = 'Retry'; $('dzOffBannerBtn').textContent = 'Retry';
            if(ok) recovered();
            else msg.textContent = 'Still no connection. Please try again.';
        });
    }

    /* watch the page's own requests */
    if(origFetch){
        window.fetch = function(){
            var p = origFetch.apply(null, arguments);
            p.catch(function(){ maybe(); });
            return p;
        };
    }
    if(window.XMLHttpRequest){
        var xsend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function(){
            this.addEventListener('error', maybe);
            return xsend.apply(this, arguments);
        };
    }

    window.addEventListener('offline', show);
    window.addEventListener('online', function(){ if(shown) retry(); });
    document.addEventListener('visibilitychange', function(){ if(!document.hidden && !shown) maybe(); });
    if(startedOffline) document.addEventListener('DOMContentLoaded', show);

    window.dzNet = { retry: retry, maybe: maybe, check: ping };
})();
DZOFFJS;
?>
</script>
<?php
}