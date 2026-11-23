<?php if (!isset($conn)) { http_response_code(403); exit; } ?>
<!-- ================================================================
     IMMERSIVE POST VIEWER  (Instagram-style single-post scroll feed)
     v5 — updates on top of v4:
       - NEW: double-tap-to-like on post media (image/video/text-only),
         with the same red heart-burst + expanding ring + scattering
         mini-hearts animation used in the reel modal. Single tap still
         opens the reel modal (with a short delay so it can tell single
         from double tap); double tap only ever LIKES (never unlikes)
         and never opens the reel.
       - FIX: "Liked by ..." bottom sheet now fetches the FULL like and
         repost lists for the post via AJAX (new `get_meme_social`
         action — add the PHP block noted at the bottom of this file to
         profile.php) instead of reusing each post's `socialProof`
         array. socialProof is a mutuals-only, likes-first, max-4-total
         list built for the small floating avatar strip on the media —
         it was never a full list, which is why reposters almost never
         showed up in the Reposts tab. The sheet still shows the old
         capped data instantly for a snappy open, then swaps in the
         full lists the moment the fetch resolves.
     ----------------------------------------------------------------
     WHERE THIS GOES: paste this ENTIRE block right before </body>
     in profile.php — after every other <script> on the page.
     ================================================================ -->
<script>
/* Immersive post viewer is LAZY: its markup and script wait in inert <template>s and are activated the first time a post is opened (openMemeModal). Its css stays inline so the cascade order is unchanged. */
(function(){
  var loaded = false, O = null, C = null, stubOpen = null, stubClose = null;
  function activate(){
    if (loaded) return;
    loaded = true;
    /* give the viewer the REAL reel functions to wrap (not my stubs) — it installs its own hooks the moment its script runs */
    if (O) window.openMemeModal  = O;
    if (C) window.closeMemeModal = C;
    var h = document.getElementById('dzIpvTplHtml'), j = document.getElementById('dzIpvTplJs');
    if (h) document.body.appendChild(h.content.cloneNode(true));
    if (j) {
      var scripts = j.content.querySelectorAll('script');
      Array.prototype.forEach.call(scripts, function(old){
        var s = document.createElement('script'); s.text = old.textContent; document.body.appendChild(s);
      });
    }
  }
  function install(){
    if (typeof window.openMemeModal !== 'function' || typeof window.closeMemeModal !== 'function') { setTimeout(install, 50); return; }
    if (loaded || window._ipvHooksInstalled) return;
    O = window.openMemeModal; C = window.closeMemeModal;
    stubOpen = function(){
      var a = arguments;
      activate();
      if (window.openMemeModal && window.openMemeModal !== stubOpen) return window.openMemeModal.apply(this, a);
      return O.apply(this, a);                       /* safety net: viewer failed to hook → plain reel modal */
    };
    stubClose = function(){ return (loaded && window.closeMemeModal !== stubClose ? window.closeMemeModal : C).apply(this, arguments); };
    window.openMemeModal = stubOpen; window.closeMemeModal = stubClose;
  }
  install();
  window.dzIpvEnsure = activate;
})();
</script>
<template id="dzIpvTplHtml">
<div id="ipvOverlay" class="ipv-overlay">
    <div class="ipv-topbar">
        <button class="ipv-back-btn" onclick="ipvClose()" aria-label="Back">
            <svg class="dz-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="miter" stroke-linecap="butt"/></svg>
        </button>
        <div class="ipv-topbar-title" id="ipvTopbarTitle">Posts</div>
        <div class="ipv-topbar-action" id="ipvTopbarAction"></div>
    </div>
    <div class="ipv-list" id="ipvList"></div>
    <audio id="ipvAudioPlayer" loop muted style="display:none"></audio>
</div>

<!-- likers bottom sheet -->
<div id="ipvLikersOverlay" class="ipv-likers-overlay" onclick="if(event.target===this)ipvCloseLikersSheet()">
    <div class="ipv-likers-panel">
        <div class="ipv-likers-handle"></div>
        <div class="ipv-likers-tabs" id="ipvLikersTabs">
            <button class="ipv-likers-tab active" data-tab="likes" onclick="ipvSwitchLikersTab(this,'likes')">Likes</button>
            <button class="ipv-likers-tab" data-tab="reposts" onclick="ipvSwitchLikersTab(this,'reposts')">Reposts</button>
        </div>
        <div class="ipv-likers-list" id="ipvLikersList"></div>
    </div>
</div>
</template>

<style>
/* phone size multiplier for the immersive + meme modals: 1 on desktop, 1.1 x 1.05 = 1.155 on screens up to 768px */
:root{ --ccm-s:1; --md-s:1; }
@media (max-width:768px){ :root{ --ccm-s:1.155; --md-s:1.1; } }

/* ============================================================
   IMMERSIVE POST VIEWER — styles
   Colours now match the home page:
     lane (post column + top bar) = var(--lane,#070924)
     page behind the lane         = same gradient as home body
     dark line between posts      = var(--post-sep,#02030e), 3px
   ============================================================ */
.ipv-overlay,
.ipv-likers-overlay{
    --ipv-lane:var(--lane,#070924);
    --ipv-sep:var(--post-sep,#02030e);
}

.ipv-overlay{
    display:none;
    position:fixed; inset:0;
    z-index:999998;
    background:linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    overflow-y:scroll;
    -webkit-overflow-scrolling:touch;
    opacity:0;
    transform:translateY(14px);
    transition:opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1);
    font-family:'Poppins',sans-serif;
    scrollbar-width:auto;
    scrollbar-color:rgba(255,255,255,.35) rgba(255,255,255,.05);
}
.ipv-overlay.ipv-open{ display:block; opacity:1; transform:translateY(0); }
.ipv-overlay::-webkit-scrollbar{ width:9px; }
.ipv-overlay::-webkit-scrollbar-track{ background:rgba(255,255,255,.04); }
.ipv-overlay::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.28); border-radius:10px; border:2px solid transparent; background-clip:padding-box; }
.ipv-overlay::-webkit-scrollbar-thumb:hover{ background:rgba(255,255,255,.5); background-clip:padding-box; }

/* top bar — exactly the same colour as the feed background (no blur, no border) */
.ipv-topbar{
    display:flex; align-items:center; justify-content:space-between;
    padding:7px 16px;
    /* every variant (your own posts, someone else's grids, with or without the Track button) is the same height: the one the old icon-font back arrow gave */
    min-height:calc(clamp(26px, 24.03px + 1.18vw, 32px) * var(--ccm-s) + 18px); box-sizing:border-box;
    position:sticky; top:0; z-index:50;
    background:var(--ipv-lane);
    max-width:470px;
    margin:0 auto;
}
.ipv-back-btn{
    background:none; border:none; color:var(--text,#F0F2FF); display:flex; align-items:center;
    cursor:pointer; padding:2px; margin-left:-6px; -webkit-tap-highlight-color:transparent;
}
/* thin, tall chevron — the same arrow as the zeems modal and the profile top bar */
.ipv-back-btn .dz-back-svg{ height:calc(clamp(18px, 16px + 0.6vw, 22px) * var(--ccm-s)); width:auto; aspect-ratio:14 / 24; display:block; overflow:visible; margin-left:calc(8px * var(--ccm-s)); }
.ipv-topbar-title{ font-size:calc(clamp(12px, 11.01px + 0.43vw, 15px) * var(--ccm-s)); font-weight:700; color:var(--text,#F0F2FF); letter-spacing:-.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:70vw; }

.ipv-topbar-title-two-line{
    display:flex; flex-direction:row; align-items:center; gap:5px;
    max-width:70vw; min-width:0; overflow:hidden;
}
.ipv-topbar-username-sm{ font-size:calc(clamp(11px, 10.51px + 0.3vw, 13.5px) * var(--ccm-s)); font-weight:600; color:rgba(220,224,255,.6); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; flex:0 1 auto; }
.ipv-topbar-sep{ color:rgba(var(--fg,255,255,255),.32); font-size:calc(clamp(9.5px, 9.01px + 0.3vw, 12px) * var(--ccm-s)); flex-shrink:0; line-height:1; }
.ipv-topbar-noun{ font-size:calc(clamp(11px, 10.51px + 0.3vw, 13.5px) * var(--ccm-s)); font-weight:700; color:var(--text,#F0F2FF); letter-spacing:-.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex-shrink:0; }

.ipv-topbar-action{ min-width:calc(clamp(18px, 16.03px + 1.18vw, 26px) * var(--ccm-s)); flex-shrink:0; display:flex; align-items:center; justify-content:flex-end; }

.ipv-track-btn.ipv-topbar-track-btn{
    /* same Track / Untrack button as the profile page: solid red, black bold text, thin, 4px corners */
    display:flex; align-items:center; justify-content:center;
    background:#FF2D2D; border:1.5px solid #FF2D2D;
    color:#000;
    /* EXACTLY the profile page's Track button: same word size, height, width and corner (the profile page's own scale variables, so the two always match) */
    font-size:calc(clamp(.58rem, .5rem + .6vw, .72rem) * var(--hd-s, 1) * var(--act-t, 1)); font-weight:700; line-height:1;
    height:var(--rp-action-h, calc(clamp(25px, 7.2vw, 30px) * var(--hd-s, 1)));
    padding:0 calc(24px * var(--hd-s, 1)); min-width:calc(92px * var(--hd-s, 1));
    border-radius:var(--rp-action-radius, calc(4px * var(--hd-s, 1)));
    cursor:pointer; font-family:inherit;
    text-decoration:none !important;
    position:static; top:auto;
    white-space:nowrap;
    transition:background .2s, border-color .2s, transform .15s;
    -webkit-tap-highlight-color:transparent;
}
.ipv-track-btn.ipv-topbar-track-btn:hover{ background:#ff1a1a; border-color:#ff1a1a; }
@media (max-width:768px){ .ipv-track-btn.ipv-topbar-track-btn{ text-shadow:0 0 .35px currentColor; } }   /* the same soft word thickening the profile's action buttons get on phones */
/* Untrack state — grey glass with white text, exactly like the Untrack pill on the profile page */
.ipv-track-btn.ipv-topbar-track-btn.ipv-untrack,
.ipv-track-btn.ipv-topbar-track-btn.ipv-untrack:hover{
    background:rgba(var(--fg,255,255,255),0.1); border:none; color:var(--text,#F0F2FF); font-weight:700;
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
}
.ipv-track-btn.ipv-topbar-track-btn.ipv-untrack:hover{ background:rgba(var(--fg,255,255,255),0.16); }
.ipv-track-btn.ipv-topbar-track-btn:active{ transform:scale(.94); }
/* the same bouncing bars the profile's Track button shows while it works (self-contained so they animate wherever this modal is used) */
.ipv-track-btn .spinner-icon{ display:inline-flex; align-items:center; justify-content:center; gap:3px; height:calc(15px * var(--hd-s, 1)); vertical-align:middle; }
.ipv-track-btn .spinner-icon span{ display:block; width:3px; background:currentColor; border-radius:2px; animation:btn-bar 1s ease-in-out infinite; }
.ipv-track-btn .spinner-icon span:nth-child(2){ animation-delay:.15s; }
.ipv-track-btn .spinner-icon span:nth-child(3){ animation-delay:.3s; }
.ipv-track-btn .spinner-icon span:nth-child(4){ animation-delay:.45s; }
@keyframes btn-bar{ 0%,100%{ height:4px; opacity:.4; } 50%{ height:10px; opacity:1; } }

/* the post column — same lane colour as the home feed */
.ipv-list{
    max-width:470px; margin:0 auto; padding-bottom:50px;
    background:var(--ipv-lane);
    min-height:calc(100vh - 47px);
}

/* desktop: thin borders either side of the column, like the home page */
@media(min-width:769px){
    .ipv-topbar,
    .ipv-list{
        border-left:1px solid var(--border,rgba(var(--fg,255,255,255),.07));
        border-right:1px solid var(--border,rgba(var(--fg,255,255,255),.07));
    }
}

/* ── POST + DARK SEPARATOR LINES (same system as home) ── */
.ipv-post{
    border-bottom:3px solid var(--ipv-sep);
    padding-bottom:12px;
    margin-bottom:0;
    scroll-margin-top:48px;
}
/* dark line above the FIRST post (under the top bar) — skipped when that post is a long one whose header floats on the media */
.ipv-list>.ipv-post:first-child:not(.ipv-long-header),
.ipv-list>.ipv-skel-post:first-child{ border-top:3px solid var(--ipv-sep); }
/* long posts (header sits on the media): no dark line above them — the line belongs to the post right before */
.ipv-post:has(+ .ipv-post.ipv-long-header){ border-bottom:none; }

.ipv-header{ display:flex; align-items:center; gap:calc(clamp(6px, 5.02px + 0.59vw, 10px) * var(--ccm-s)); padding:calc(clamp(7px, 6.02px + 0.59vw, 11px) * var(--ccm-s)) calc(clamp(8px, 7.02px + 0.59vw, 12px) * var(--ccm-s)); }
.ipv-avatar{ width:calc(clamp(25px, 22.04px + 1.46vw, 34px) * var(--ccm-s)); height:calc(clamp(25px, 22.04px + 1.46vw, 34px) * var(--ccm-s)); border-radius:50%; object-fit:cover; border:1.6px solid var(--red,#FF2D2D); cursor:pointer; flex-shrink:0; }
/* collab post header: poster + collaborators in one avatar cluster; "Name and Sam" opens the collaborators sheet */
.av-wrap.ipv-collab{position:relative;display:block;flex:none;width:var(--cw,46px);height:var(--cw,46px)}
.av-wrap.ipv-collab .on-dot{display:none}
.av-wrap.ipv-collab img.ipv-avatar{position:absolute;box-sizing:border-box;width:66%;height:66%;margin:0}
.av-wrap.ipv-collab.n1 img.ipv-avatar:not(.ipv-co-pic){left:0;bottom:0;z-index:2}
.av-wrap.ipv-collab.n1 img.ipv-co-pic{right:0;top:0;z-index:1}
.av-wrap.ipv-collab.n2 img.ipv-avatar{width:58%;height:58%}
.av-wrap.ipv-collab.n2 img.ipv-avatar:not(.ipv-co-pic){left:21%;top:0;z-index:3}
.av-wrap.ipv-collab.n2 img.ipv-co-pic.c1{left:0;bottom:0;z-index:2}
.av-wrap.ipv-collab.n2 img.ipv-co-pic.c2{right:0;bottom:0;z-index:1}
.ipv-username.ipv-co-name{cursor:pointer}
.ipv-header .av-wrap{ position:relative; display:inline-flex; flex-shrink:0; line-height:0; text-decoration:none; }
.ipv-header .av-wrap .on-dot{ position:absolute; right:2%; bottom:2%; width:32%; min-width:9px; max-width:16px; aspect-ratio:1; border-radius:50%; background:var(--green,#22c55e); border:calc(clamp(1.5px,.5vw,2.5px) * var(--ccm-s,1)) solid var(--lane,#070924); box-sizing:border-box; pointer-events:none; z-index:3; }
.ipv-header-info{ flex:1; min-width:0; display:flex; flex-direction:column; }
.ipv-username-row{ display:flex; align-items:center; gap:6px; min-width:0; margin-bottom:2px; }
/* Dezzme Verified flower badge: same size + colour as the username, no badge on profile pics */
.ipv-username-row > .dz-vb{ width:calc(clamp(11px, 10.51px + 0.3vw, 13px) * var(--ccm-s)); height:calc(clamp(11px, 10.51px + 0.3vw, 13px) * var(--ccm-s)); margin-left:-2px; color:var(--text,#F0F2FF); }
.ipv-post.ipv-long-header .ipv-username-row > .dz-vb{ color:#fff; filter:drop-shadow(0 1px 4px rgba(0,0,0,.5)); }
.dz-vb{ flex-shrink:0; display:inline-block; vertical-align:-.15em; }
/* badge inside text lines ("Liked by …", caption username, likers sheet): 1em, takes the text colour */
.ipv-likes-text .dz-vb, .ipv-caption .dz-vb, .ipv-liker-name .dz-vb{ width:1em; height:1em; margin-left:.28em; }
.ipv-caption b.ipv-cap-user .dz-vb{ margin-left:.22em; }
.ipv-username{ font-size:calc(clamp(11px, 10.51px + 0.3vw, 13px) * var(--ccm-s)); font-weight:700; color:var(--text,#F0F2FF); cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ipv-new-tag{ color:var(--red,#FF2D2D); font-size:11px; font-weight:800; flex-shrink:0; }
.ipv-dot-sep{ color:rgba(255,255,255,.35); font-size:12px; flex-shrink:0; }
.ipv-dot-sep-red{ color:var(--red,#FF2D2D); font-size:13px; flex-shrink:0; line-height:1; }

.ipv-track-btn{ background:none; border:none; padding:0; color:var(--red,#FF2D2D); font-size:calc(clamp(10px, 9.01px + 0.43vw, 12px) * var(--ccm-s)); font-weight:700; cursor:pointer; font-family:inherit; flex-shrink:0; text-decoration:underline; text-decoration-color:var(--red,#FF2D2D); text-underline-offset:2px; text-decoration-thickness:1.5px; position:relative; top:-1px; }

.ipv-info-switcher{ position:relative; min-height:14px; }
.ipv-switch-pane{ position:absolute; left:0; top:0; right:0; opacity:0; pointer-events:none; transition:opacity .35s ease; }
.ipv-switch-pane.active{ position:relative; opacity:1; pointer-events:all; }
.ipv-sub-line{ font-size:calc(clamp(10.1px, 9.93px + 0.046vw, 10.6px) * var(--ccm-s)); color:rgba(220,224,255,.55); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ipv-sub-line.ipv-partner-line{ color:#fff; font-weight:500; }
.ipv-sub-line.ipv-partner-line span{ font-size:.92em; }
.ipv-sub-line.ipv-partner-line b{ color:#fff; font-weight:600; }
.ipv-sub-line a{ color:var(--red,#FF2D2D); text-decoration:none; font-weight:600; }
.ipv-audio-tag{ display:flex; align-items:center; gap:6px; }
.ipv-audio-icon{ display:inline-flex; align-items:flex-end; gap:2px; height:9px; flex-shrink:0; }
.ipv-eq-bar{ width:2px; border-radius:2px; background:rgba(var(--fg,255,255,255),.65); display:inline-block; transform-origin:bottom; animation:ipvEq .9s ease-in-out infinite; }
.ipv-eq-bar:nth-child(1){ height:9px; animation-delay:-.6s; }
.ipv-eq-bar:nth-child(2){ height:9px; animation-delay:-.2s; }
.ipv-eq-bar:nth-child(3){ height:9px; animation-delay:-.9s; }
@keyframes ipvEq{ 0%{transform:scaleY(.35);} 25%{transform:scaleY(1);} 50%{transform:scaleY(.5);} 75%{transform:scaleY(.85);} 100%{transform:scaleY(.35);} }
.ipv-audio-text{ font-size:calc(clamp(10.1px, 9.93px + 0.046vw, 10.6px) * var(--ccm-s)); font-weight:500; color:rgba(220,224,255,.6); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:200px; }
@media (max-width:768px){ .ipv-sub-line, .ipv-audio-text{ font-size:calc(10.04px * var(--ccm-s)); } }

.ipv-kebab{ background:none; border:none; color:var(--text,#F0F2FF); font-size:calc(clamp(18px, 17.02px + 0.59vw, 22px) * var(--ccm-s)); cursor:pointer; padding:calc(clamp(4px, 3.55px + 0.35vw, 6px) * var(--ccm-s)) calc(clamp(3px, 2.01px + 0.27vw, 4px) * var(--ccm-s)) calc(clamp(4px, 3.55px + 0.35vw, 6px) * var(--ccm-s)) calc(clamp(6px, 5.02px + 0.59vw, 10px) * var(--ccm-s)); margin-right:-6px; flex-shrink:0; -webkit-tap-highlight-color:transparent; position:relative; top:-3px; display:flex; align-items:center; justify-content:center; line-height:1; }
.ipv-kebab i{ display:inline-block; transform:scaleX(1.18); }
.ipv-kebab:active{ transform:scale(1.08); }

/* Facebook-style caption under the header (default for normal posts) */
.ipv-header-caption{
    padding:0 12px 9px; font-size:12.5px; line-height:1.5; color:var(--cap, rgba(255,255,255,.85)); word-break:break-word;
}
.ipv-post.ipv-long-header .ipv-header-caption{ display:none; }

.ipv-post.ipv-long-header{ position:relative; }
.ipv-post.ipv-long-header .ipv-header{
    position:absolute; top:0; left:0; right:0; z-index:8;
    padding-bottom:22px;
    background:linear-gradient(180deg, rgba(0,0,0,.68) 0%, rgba(0,0,0,.34) 55%, transparent 100%);
}
.ipv-post.ipv-long-header .ipv-username,
.ipv-post.ipv-long-header .ipv-sub-line,
.ipv-post.ipv-long-header .ipv-audio-text,
.ipv-post.ipv-long-header .ipv-kebab{ color:#fff; text-shadow:0 1px 4px rgba(0,0,0,.5); }

.ipv-post.ipv-long-header .ipv-top-left-tags,
.ipv-post.ipv-long-header .ipv-top-right-tags{ top:58px; }

/* ── media carousel ──
   touch-action:pan-y → the browser only handles UP/DOWN scrolling on the media.
   Sideways gestures are left to the carousel script (dead-zone + lock),
   so a thumb that is scrolling the feed can never drag the slides around. */
.ipv-media-wrap{
    position:relative; width:100%; background:#000; overflow:hidden;
    aspect-ratio:4/4.2;
    transition:height .18s ease;
    touch-action:pan-y;
}
.ipv-media-track{ display:flex; height:100%; transition:transform .28s cubic-bezier(.22,1,.36,1); will-change:transform; }
.ipv-media-item{ flex:0 0 100%; height:100%; position:relative; background:#000; }
.ipv-media-item img,
.ipv-media-item video{
    width:100%; height:100%; object-fit:cover; display:block; cursor:pointer;
    -webkit-user-drag:none; user-select:none; -webkit-user-select:none;
}

.ipv-media-wrap.ipv-multi{ aspect-ratio:4/3.5; }

/* multi-media posts: each slide FITS inside the fixed box (contain); JS adds .ipv-fit-cover when close enough to fill */
.ipv-media-wrap.ipv-multi .ipv-media-item{ display:flex; align-items:center; justify-content:center; }
.ipv-media-wrap.ipv-multi .ipv-media-item img,
.ipv-media-wrap.ipv-multi .ipv-media-item video{
    width:100%; height:100%;
    object-fit:contain;
    background:#000;
}
.ipv-media-wrap.ipv-multi .ipv-media-item img.ipv-fit-cover,
.ipv-media-wrap.ipv-multi .ipv-media-item video.ipv-fit-cover{
    object-fit:cover;
}

/* ── TEXT POSTS (same design as home) ──
   Text sits on the lane colour. The "Text" tag is in the right corner of the text area;
   a small invisible spacer (.ipv-tag-gap) floats in the right corner of the FIRST line only,
   so a word never runs into the tag. Line 2 onwards is full width.
   Avatars / CTA / partnership tag sit UNDER the text, in the flow. */
.ipv-media-wrap.ipv-textonly{
    aspect-ratio:auto; min-height:130px;
    background:var(--ipv-lane);
    display:flex; flex-direction:column;
}
.ipv-media-wrap.ipv-textonly .ipv-social-proof-strip,
.ipv-media-wrap.ipv-textonly .ipv-cta-pill,
.ipv-media-wrap.ipv-textonly .ipv-partnership-tag{
    position:relative; left:auto; bottom:auto; align-self:flex-start; margin:0 18px 20px;
}
.ipv-textonly-clickable{
    flex:1 1 auto; display:flex; align-items:flex-start; justify-content:flex-start;
    width:100%; padding:14px 18px 18px; cursor:pointer;
}
.ipv-media-wrap.ipv-textonly.ipv-tl .ipv-textonly-clickable{ padding-top:70px; } /* room for the group / AI tags on the top-left */
.ipv-media-wrap.ipv-textonly .ipv-top-right-tags{ top:calc(clamp(10px, 8.02px + 0.55vw, 12px) * var(--ccm-s)); right:calc(clamp(12px, 10.02px + 0.55vw, 14px) * var(--ccm-s)); }
.ipv-center-caption-wrap{ width:100%; display:block; text-align:left; }
.ipv-tag-gap{ display:block; float:right; width:70px; height:1em; font-size:12.5px; pointer-events:none; }
.ipv-center-caption-text{
    color:var(--cap, rgba(255,255,255,.85)); font-size:12.5px; font-weight:400; line-height:1.6;
    max-width:100%; word-break:break-word; text-align:left;
}
.ipv-center-caption-text.ipv-t-s{ font-size:calc(clamp(.95rem, 2.8vw + .1rem, 1.15rem) * var(--ccm-s)); font-weight:700; line-height:1.4; }
.ipv-center-caption-text.ipv-t-m{ font-size:calc(clamp(.86rem, 1.5vw + .4rem, .95rem) * var(--ccm-s)); font-weight:600; line-height:1.5; }
.ipv-center-caption-text.ipv-t-l{ font-size:calc(clamp(.8rem, .8vw + .55rem, .86rem) * var(--ccm-s)); font-weight:500; line-height:1.6; }
.ipv-hl{ color:var(--gw, rgba(255,255,255,.65)); font-weight:600; text-decoration:none; }
/* hairline grey lines on text posts — under the header and above the like / repost / save row */
.ipv-post.ipv-post-text .ipv-header{ border-bottom:1px solid rgba(var(--fg,255,255,255),.13); }
.ipv-post.ipv-post-text .ipv-actions{ border-top:1px solid rgba(var(--fg,255,255,255),.13); }

.ipv-dots{
    position:absolute; left:0; right:0; bottom:10px; z-index:6;
    display:flex; align-items:center; justify-content:center;
    gap:5px; padding:0; flex-wrap:wrap; row-gap:5px;
}
.ipv-dot{ width:calc(clamp(4.5px, 4.23px + 0.12vw, 5px) * var(--ccm-s)); height:calc(clamp(4.5px, 4.23px + 0.12vw, 5px) * var(--ccm-s)); border-radius:50%; background:rgba(255,255,255,.35); box-shadow:0 0 3px rgba(0,0,0,.65); transition:background .18s, transform .18s; flex-shrink:0; }
.ipv-dot.active{ background:var(--red,#FF2D2D); transform:scale(1.3); }

.ipv-media-arrow{ position:absolute; top:50%; transform:translateY(-50%); z-index:6; width:calc(clamp(26px, 23.02px + 0.82vw, 32px) * var(--ccm-s)); height:calc(clamp(26px, 23.02px + 0.82vw, 32px) * var(--ccm-s)); border-radius:50%; background:rgba(0,0,0,.42); border:none; color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.ipv-media-arrow i{ font-size:calc(clamp(16px, 14.02px + 0.55vw, 20px) * var(--ccm-s)); }
.ipv-arrow-left{ left:10px; }
.ipv-arrow-right{ right:10px; }

.ipv-social-proof-strip{
    position:absolute; left:12px; bottom:30px; z-index:6;
    display:flex; flex-direction:row-reverse; gap:7px; pointer-events:none;
    transition:opacity .25s ease, visibility .25s ease;
}
.ipv-social-proof-strip.ipv-sp-raised{ bottom:76px; }
.ipv-sp-avatar-wrap{ position:relative; pointer-events:all; display:inline-block; text-decoration:none; }
.ipv-sp-avatar-wrap:nth-child(1){ animation:ipvSpFloat 3s ease-in-out infinite; }
.ipv-sp-avatar-wrap:nth-child(2){ animation:ipvSpFloat 3s ease-in-out infinite .4s; }
.ipv-sp-avatar-wrap:nth-child(3){ animation:ipvSpFloat 3s ease-in-out infinite .8s; }
.ipv-sp-avatar-wrap:nth-child(4){ animation:ipvSpFloat 3s ease-in-out infinite 1.2s; }
@keyframes ipvSpFloat{ 0%,100%{ transform:translateY(0); } 50%{ transform:translateY(-6px); } }
.ipv-sp-avatar{ width:calc(clamp(34px, 27.91px + 2.06vw, 46px) * var(--ccm-s)); height:calc(clamp(34px, 27.91px + 2.06vw, 46px) * var(--ccm-s)); border-radius:50%; object-fit:cover; border:2.5px solid var(--red,#FF2D2D); display:block; box-shadow:0 4px 12px rgba(0,0,0,.7); }
.ipv-sp-badge{
    position:absolute; bottom:-2px; right:-2px;
    width:16px; height:16px; border-radius:50%;
    display:flex; align-items:center; justify-content:center; z-index:2;
}
.ipv-sp-badge i{ font-size:8px; color:#fff; line-height:1; }
/* LIKE badge = a real heart (not a heart in a circle): red, outlined in the feed background so it stands out from the avatar's red border */
/* both badges (heart + repost) are the size of the like button in the immersive modal */
.ipv-sp-badge.ipv-sp-heart, .ipv-sp-badge.ipv-sp-rep{ --spb:calc(clamp(17px, 15.02px + 0.87vw, 22px) * var(--ccm-s)); width:var(--spb); height:var(--spb); }
.ipv-sp-badge.ipv-sp-heart{ background:none; bottom:calc(-5px * var(--ccm-s)); right:calc(-6px * var(--ccm-s)); }
.ipv-sp-badge.ipv-sp-rep{ width:calc(var(--spb) * .88); height:calc(var(--spb) * .88); bottom:calc(-4px * var(--ccm-s)); right:calc(-5px * var(--ccm-s)); box-shadow:0 0 0 calc(2px * var(--ccm-s)) var(--ipv-lane, var(--lane,#070924)); }
.ipv-sp-badge.ipv-sp-rep i{ font-size:calc(var(--spb) * .6); color:#fff; line-height:1; display:block; }
.ipv-sp-heart svg{ width:100%; height:100%; display:block; overflow:visible; }
.ipv-sp-heart .sp-ring{ fill:var(--ipv-lane, var(--lane,#070924)); stroke:var(--ipv-lane, var(--lane,#070924)); }
.ipv-sp-heart .sp-fill{ fill:#FF2D2D; }

.ipv-media-controls{ position:absolute; bottom:12px; right:12px; z-index:6; display:flex; flex-direction:column; gap:8px; }
.ipv-mute-btn{ width:calc(clamp(22px, 20.64px + 1.05vw, 28px) * var(--ccm-s)); height:calc(clamp(22px, 20.64px + 1.05vw, 28px) * var(--ccm-s)); border-radius:50%; background:rgba(0,0,0,.5); border:none; color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.ipv-mute-btn i{ font-size:calc(clamp(11px, 10.32px + 0.53vw, 14px) * var(--ccm-s)); }
/* mute / unmute icon (custom speaker) */
.dz-snd{display:inline-block;width:1.12em;height:1em;background-color:currentColor;-webkit-mask:var(--dz-snd) center/contain no-repeat;mask:var(--dz-snd) center/contain no-repeat;vertical-align:middle;font-style:normal}
.dz-snd.on{--dz-snd:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%22261%20218%20681%20603%22%3E%3Cpath%20fill-rule%3D%22evenodd%22%20d%3D%22M576.2%2C820.0C560.6%2C817.2%20551.8%2C812.4%20515.6%2C787.7C500.1%2C777.2%20474.4%2C759.8%20458.5%2C749.0C442.6%2C738.3%20425.4%2C726.5%20420.5%2C722.9C415.6%2C719.3%20405.2%2C712.2%20397.5%2C707.0C381.1%2C696.0%20346.4%2C671.6%20333.5%2C661.9C314.1%2C647.4%20292.3%2C621.5%20281.6%2C600.2C258.9%2C555.2%20255.1%2C512.3%20270.0%2C467.9C277.7%2C444.5%20282.6%2C435.5%20299.8%2C412.0C309.3%2C399.1%20335.0%2C374.5%20346.8%2C367.0C365.5%2C355.1%20391.4%2C337.8%20401.0%2C330.6C412.7%2C322.0%20438.1%2C304.3%20460.9%2C289.0C470.3%2C282.7%20487.8%2C270.8%20499.8%2C262.6C559.6%2C221.4%20567.2%2C217.6%20589.5%2C217.6C628.4%2C217.6%20651.1%2C243.1%20654.0%2C290.0C655.3%2C310.7%20655.3%2C742.7%20654.0%2C756.0C649.5%2C802.0%20618.5%2C827.6%20576.2%2C820.0ZM747.9%2C729.0C732.2%2C726.4%20723.6%2C712.1%20725.3%2C691.7C727.0%2C671.8%20735.1%2C664.0%20760.3%2C658.0C794.4%2C649.8%20822.2%2C631.8%20843.7%2C603.9C862.9%2C578.9%20876.0%2C533.5%20872.1%2C504.8C869.5%2C485.1%20856.5%2C452.0%20846.5%2C439.5C819.9%2C406.3%20796.9%2C390.9%20760.7%2C382.0C736.8%2C376.1%20727.9%2C367.8%20724.9%2C348.7C721.1%2C324.7%20735.0%2C309.3%20758.5%2C311.4C807.1%2C315.8%20864.9%2C348.4%20895.2%2C388.5C915.9%2C416.0%20920.6%2C425.4%20936.6%2C471.0C943.6%2C491.1%20944.3%2C540.8%20938.0%2C565.7C923.1%2C623.6%20884.6%2C673.9%20832.7%2C702.9C803.4%2C719.3%20763.6%2C731.6%20747.9%2C729.0ZM738.0%2C584.8C732.1%2C581.6%20727.6%2C574.3%20726.0%2C565.2C724.3%2C556.1%20724.4%2C479.3%20726.1%2C472.4C737.4%2C425.3%20805.6%2C472.9%20801.7%2C525.2C799.0%2C559.4%20759.6%2C596.3%20738.0%2C584.8Z%22%2F%3E%3C%2Fsvg%3E)}
.dz-snd.off{--dz-snd:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%22261%20218%20681%20603%22%3E%3Cpath%20d%3D%22M576.2%2C820.0C560.6%2C817.2%20551.8%2C812.4%20515.6%2C787.7C500.1%2C777.2%20474.4%2C759.8%20458.5%2C749.0C442.6%2C738.3%20425.4%2C726.5%20420.5%2C722.9C415.6%2C719.3%20405.2%2C712.2%20397.5%2C707.0C381.1%2C696.0%20346.4%2C671.6%20333.5%2C661.9C314.1%2C647.4%20292.3%2C621.5%20281.6%2C600.2C258.9%2C555.2%20255.1%2C512.3%20270.0%2C467.9C277.7%2C444.5%20282.6%2C435.5%20299.8%2C412.0C309.3%2C399.1%20335.0%2C374.5%20346.8%2C367.0C365.5%2C355.1%20391.4%2C337.8%20401.0%2C330.6C412.7%2C322.0%20438.1%2C304.3%20460.9%2C289.0C470.3%2C282.7%20487.8%2C270.8%20499.8%2C262.6C559.6%2C221.4%20567.2%2C217.6%20589.5%2C217.6C628.4%2C217.6%20651.1%2C243.1%20654.0%2C290.0C655.3%2C310.7%20655.3%2C742.7%20654.0%2C756.0C649.5%2C802.0%20618.5%2C827.6%20576.2%2C820.0Z%22%2F%3E%3Cpath%20d%3D%22M730%20400L910%20620M910%20400L730%20620%22%20stroke%3D%22%23000%22%20stroke-width%3D%2264%22%20stroke-linecap%3D%22round%22%20fill%3D%22none%22%2F%3E%3C%2Fsvg%3E)}

.ipv-actions{ display:flex; align-items:center; justify-content:space-between; padding:calc(clamp(7px, .65vw + 3.8px, 9px) * var(--ccm-s)) calc(clamp(8px, .65vw + 4.8px, 10px) * var(--ccm-s)) 0; position:relative; }
.ipv-actions-left{ display:flex; align-items:center; gap:calc(clamp(9px, 1.9vw + 2.7px, 18px) * var(--ccm-s)); }
.ipv-action-group{ display:flex; align-items:center; gap:6px; }
/* comments turned off for a post: the same "chat-off" icon as home instead of the speech bubble, and no number (the button still opens the sheet) */
.ipv-comment-btn .cm-off-ic{ display:none; }
.ipv-comment-btn.cm-off svg{ display:none !important; }
.ipv-comment-btn.cm-off .cm-off-ic{ display:block; width:100%; height:100%; font-size:1.166em; line-height:1; text-align:center; font-style:normal; -webkit-text-stroke:0; }
[data-cm-count][data-off="1"]{ display:none !important; }
.ipv-action-btn{ background:none; border:none; color:var(--text,#F0F2FF); font-size:calc(clamp(17px, 1.06vw + 13.5px, 22px) * var(--ccm-s)); cursor:pointer; padding:2px; display:flex; align-items:center; -webkit-tap-highlight-color:transparent; transition:transform .12s ease; }
.ipv-action-btn:active{ transform:scale(1.22); }
/* rail icons + repost badge drawn exactly like the home page: even-line SVGs */
.sp-rp{ display:inline-block; width:1.166em; height:1.166em; margin:-.083em; line-height:0; font-style:normal; vertical-align:middle; }
.sp-rp svg{ display:block; width:100%; height:100%; overflow:visible; }
.sp-ring{ stroke:#000; }
:root[data-theme="light"] .sp-ring{ stroke:#fff; }
.ipv-action-btn.liked .sp-fillable svg, .ipv-action-btn.faved .sp-fillable svg{ fill:currentColor; }
.ipv-action-btn.liked{ color:var(--red,#FF2D2D); }
.ipv-action-btn.faved{ color:var(--red,#FF2D2D); }
.ipv-action-count[data-hidden="1"], .ccm-rail-count[data-hidden="1"]{ display:none !important; }   /* counts the poster chose to hide */
.ipv-action-count{ font-size:calc(clamp(11px, .9vw + 6.6px, 12px) * var(--ccm-s)); font-weight:700; color:#fff; }

@keyframes ipv-repost-arc-swap {
    0%   { transform: rotate(0deg) scale(1); }
    35%  { transform: rotate(140deg) scale(1.15); }
    50%  { transform: rotate(180deg) scale(1.2); }
    65%  { transform: rotate(220deg) scale(1.15); }
    100% { transform: rotate(360deg) scale(1); }
}
.ipv-action-btn.arc-swap-active i {
    animation: ipv-repost-arc-swap 0.6s cubic-bezier(.33,1,.68,1);
    transition: none !important;
}

.ipv-social-row{ display:flex; align-items:center; gap:7px; padding:6px 12px 0; cursor:pointer; }
.ipv-social-avatars{ display:flex; flex-shrink:0; }
.ipv-social-avatars img{ width:calc(clamp(16px, 14.48px + 0.52vw, 20px) * var(--ccm-s)); height:calc(clamp(16px, 14.48px + 0.52vw, 20px) * var(--ccm-s)); border-radius:50%; object-fit:cover; border:1.6px solid rgba(200,203,214,.65); margin-left:-7px; }
.ipv-social-avatars img:first-child{ margin-left:0; }
.ipv-likes-text{ font-size:calc(clamp(11px, 9.74px + 0.39vw, 12.5px) * var(--ccm-s)); color:var(--text,#F0F2FF); }
.ipv-likes-text b{ font-weight:700; }
.ipv-likes-dim{ color:rgba(220,224,255,.72); font-weight:400; }

/* caption below media: ONLY shown for long-header posts (header floats over the media) */
.ipv-caption{ display:none; padding:5px 12px 0; font-size:12.5px; line-height:1.5; color:var(--cap, rgba(255,255,255,.85)); word-break:break-word; }
.ipv-post.ipv-long-header .ipv-caption{ display:block; }
.ipv-caption b.ipv-cap-user{ margin-right:5px; color:var(--red,#FF2D2D); }
.ipv-more-toggle{ color:var(--more, #fff); cursor:pointer; font-weight:700; }
.ipv-more-wrap{ cursor:pointer; }
.ipv-ellipsis{ color:var(--cap, rgba(255,255,255,.85)); font-weight:400; }

.ipv-partnership-tag{ position:absolute; z-index:7; left:calc(clamp(8px, 7.02px + 0.59vw, 12px) * var(--ccm-s)); bottom:calc(clamp(22px, 18.05px + 1.73vw, 34px) * var(--ccm-s)); display:inline-flex; align-items:center; gap:calc(clamp(4px, 3.47px + 0.24vw, 6px) * var(--ccm-s)); padding:calc(clamp(3px, 2.51px + 0.3vw, 5px) * var(--ccm-s)) calc(clamp(6px, 5.02px + 0.59vw, 10px) * var(--ccm-s)); border-radius:calc(clamp(7px, 7.54px + 0.08vw, 8px) * var(--ccm-s)); background:rgba(0,0,0,0.55); color:#ff4c4c; font-size:calc(clamp(9px, 8.51px + 0.3vw, 11px) * var(--ccm-s)); font-weight:600; max-width:calc(100% - 24px); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); pointer-events:none; transition:opacity .25s ease, visibility .25s ease; }
.ipv-partnership-tag i{ font-size:calc(clamp(10px, 9.51px + 0.45vw, 13px) * var(--ccm-s)); flex-shrink:0; }
.ipv-partnership-tag b{ color:var(--text,#F0F2FF); font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.ipv-time{ padding:6px 12px 0; font-size:calc(clamp(8.5px, 8.01px + 0.3vw, 10px) * var(--ccm-s)); color:rgba(220,224,255,.4); letter-spacing:.3px; }
.ipv-time.ipv-time-row{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
.ipv-time-left{ display:flex; align-items:center; flex-wrap:wrap; gap:2px 10px; min-width:0; }
.ipv-zeem-hint{ color:var(--red,#FF2D2D); font-weight:300; font-size:10px; letter-spacing:.2px; }

/* when a caption is expanded ("more"), the overlays on the media step out of the way */
.ipv-post.ipv-cap-open .ipv-social-proof-strip,
.ipv-post.ipv-cap-open .ipv-cta-pill,
.ipv-post.ipv-cap-open .ipv-partnership-tag{ opacity:0; visibility:hidden; pointer-events:none; }
.ipv-post.ipv-cap-open .ipv-textonly .ipv-social-proof-strip,
.ipv-post.ipv-cap-open .ipv-textonly .ipv-cta-pill,
.ipv-post.ipv-cap-open .ipv-textonly .ipv-partnership-tag{ display:none; }

/* ============================================================
   DOUBLE-TAP LIKE HEART BURST
   ============================================================ */
.ipv-double-tap-heart{
    position:absolute; z-index:14; pointer-events:none;
    transform:translate(-50%,-50%) scale(0) rotate(0deg);
    opacity:0;
}
.ipv-double-tap-heart i{
    font-size:84px; color:#ff2d4f;
    filter:drop-shadow(0 6px 20px rgba(255,45,79,.6));
    -webkit-text-stroke:1.3px rgba(255,255,255,.32);
}
.ipv-double-tap-heart.show{
    animation: ipvHeartBurst .95s cubic-bezier(.17,.89,.32,1.28) forwards;
}
@keyframes ipvHeartBurst{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(-28deg); opacity:0; }
    14%{  transform:translate(-50%,-50%) scale(1.35) rotate(10deg);  opacity:1; }
    28%{  transform:translate(-50%,-50%) scale(.88)  rotate(-6deg);  opacity:1; }
    42%{  transform:translate(-50%,-50%) scale(1.14) rotate(4deg);   opacity:1; }
    58%{  transform:translate(-50%,-50%) scale(.97)  rotate(-1deg);  opacity:1; }
    75%{  transform:translate(-50%,-50%) scale(1.05) rotate(0deg);   opacity:1; }
    100%{ transform:translate(-50%,-50%) scale(1.15) rotate(0deg) translateY(-40px); opacity:0; }
}
.ipv-heart-ring{
    position:absolute; z-index:13; pointer-events:none;
    width:95px; height:95px;
    margin-left:-47.5px; margin-top:-47.5px;
    border-radius:50%;
    border:3px solid rgba(255,45,79,.62);
    transform:scale(.25);
    opacity:0;
}
.ipv-heart-ring.show{
    animation: ipvRingPulse .75s cubic-bezier(.15,.6,.35,1) forwards;
}
@keyframes ipvRingPulse{
    0%{   transform:scale(.25); opacity:.9; border-width:4px; }
    100%{ transform:scale(2.3); opacity:0;  border-width:1px; }
}
.ipv-heart-particle{
    position:absolute; z-index:12; pointer-events:none;
    left:0; top:0;
    transform:translate(-50%,-50%) scale(0);
    opacity:0;
    color:#ff2d4f;
}
.ipv-heart-particle i{ filter:drop-shadow(0 2px 7px rgba(255,45,79,.5)); }
.ipv-heart-particle.show{
    animation: ipvParticleFly .8s cubic-bezier(.2,.7,.3,1) forwards;
}
@keyframes ipvParticleFly{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(0deg);        opacity:0; }
    12%{  opacity:1; }
    100%{ transform:translate(calc(-50% + var(--tx)), calc(-50% + var(--ty))) scale(1) rotate(var(--tr)); opacity:0; }
}

/* ============================================================
   LIKERS BOTTOM SHEET
   ============================================================ */
.ipv-likers-overlay{
    display:none; position:fixed; inset:0; z-index:999999;
    background:rgba(0,0,0,.55);
    align-items:flex-end; justify-content:center;
    opacity:0; transition:opacity .2s ease;
}
.ipv-likers-overlay.ipv-open{ display:flex; opacity:1; }
.ipv-likers-panel{
    width:100%; max-width:470px; max-height:70vh;
    background:var(--ipv-lane); border-radius:16px 16px 0 0;
    border:1px solid var(--border,rgba(var(--fg,255,255,255),.08)); border-bottom:none;
    padding:10px 0 18px; display:flex; flex-direction:column;
    transform:translateY(100%); transition:transform .28s cubic-bezier(.22,1,.36,1);
    font-family:'Poppins',sans-serif;
}
.ipv-likers-overlay.ipv-open .ipv-likers-panel{ transform:translateY(0); }
.ipv-likers-handle{ width:36px; height:4px; border-radius:3px; background:rgba(var(--fg,255,255,255),.25); margin:0 auto 12px; }
.ipv-likers-title{ font-size:14px; font-weight:700; color:var(--text,#F0F2FF); padding:0 16px 10px; border-bottom:1px solid var(--border,rgba(var(--fg,255,255,255),.07)); }
.ipv-likers-list{ overflow-y:auto; padding:2px 0 6px; }
.ipv-likers-tabs{ display:flex; gap:6px; padding:0 16px 10px; border-bottom:1px solid var(--border,rgba(var(--fg,255,255,255),.07)); }
.ipv-likers-tab{
    background:none; border:none; font-family:inherit; font-size:12.5px; font-weight:700;
    color:rgba(220,224,255,.5); padding:6px 14px; border-radius:20px; cursor:pointer;
    -webkit-tap-highlight-color:transparent; transition:color .15s ease, background .15s ease;
}
.ipv-likers-tab.active{ color:var(--text,#F0F2FF); background:rgba(255,45,45,.14); }
.ipv-liker-row{ display:flex; align-items:center; gap:10px; padding:10px 16px; border-bottom:1px solid var(--border,rgba(var(--fg,255,255,255),.07)); }
.ipv-liker-avatar{ width:38px; height:38px; border-radius:50%; object-fit:cover; border:1.5px solid var(--red,#FF2D2D); flex-shrink:0; }
.ipv-liker-name{ font-size:13px; font-weight:600; color:var(--text,#F0F2FF); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ipv-liker-icon{ font-size:14px; color:var(--red,#FF2D2D); flex-shrink:0; }
.ipv-likers-empty{ padding:24px 16px; text-align:center; font-size:12.5px; color:rgba(220,224,255,.5); }

/* ============================================================
   TOP-RIGHT TAGS
   ============================================================ */
.ipv-top-right-tags{ position:absolute; top:calc(clamp(7px, 6.55px + 0.35vw, 10px) * var(--ccm-s)); right:calc(clamp(7px, 6.55px + 0.35vw, 10px) * var(--ccm-s)); z-index:6; display:flex; flex-direction:column; align-items:flex-end; gap:calc(clamp(4px, 3.47px + 0.24vw, 6px) * var(--ccm-s)); pointer-events:none; }
.ipv-media-counter{
    background:rgba(22,22,24,.78); color:#fff;
    font-size:10px; font-weight:800; letter-spacing:.3px;
    padding:4px 8px; border-radius:6px;
}
.ipv-type-tag{ display:flex; align-items:center; gap:4px; background:rgba(22,22,24,.78); border-radius:6px; padding:calc(clamp(3px, 3px + 0.16vw, 4px) * var(--ccm-s)) calc(clamp(6px, 5.01px + 0.43vw, 8px) * var(--ccm-s)) calc(clamp(3px, 3px + 0.16vw, 4px) * var(--ccm-s)) calc(clamp(4.5px, 3.78px + 0.31vw, 6px) * var(--ccm-s)); }
.ipv-type-tag i{ font-size:calc(clamp(9px, 8.01px + 0.43vw, 12px) * var(--ccm-s)); color:var(--text,#F0F2FF); }
.ipv-type-tag span{ font-size:calc(clamp(7.5px, 7.01px + 0.3vw, 10px) * var(--ccm-s)); font-weight:700; color:var(--text,#F0F2FF); text-transform:uppercase; letter-spacing:.3px; white-space:nowrap; }

/* ============================================================
   TOP-LEFT TAG STACK
   ============================================================ */
.ipv-top-left-tags{ position:absolute; top:calc(clamp(7px, 6.55px + 0.35vw, 10px) * var(--ccm-s)); left:calc(clamp(7px, 6.55px + 0.35vw, 10px) * var(--ccm-s)); z-index:6; display:flex; flex-direction:column; align-items:flex-start; gap:calc(clamp(4px, 3.47px + 0.24vw, 6px) * var(--ccm-s)); pointer-events:none; }
.ipv-top-left-tags > *{ pointer-events:all; flex:none; width:max-content; }

.ipv-group-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(40,42,50,.78); border-radius:7px;
    padding:5px 9px 5px 7px; text-decoration:none;
    font-size:10px; font-weight:600; color:rgba(255,255,255,.85);
    max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ipv-group-tag i{ font-size:12px; color:rgba(var(--fg,255,255,255),.55); flex-shrink:0; }
.ipv-group-tag b{ color:#ff5f5f; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
@media (max-width:768px){ .ipv-group-tag{ transform:translateY(13%) scale(1.03); transform-origin:left top; } }   /* responsive: lowered 13% of its height, 3% bigger */

.ipv-ai-tag{ display:flex; align-items:center; gap:5px; background:rgba(59,130,246,.18); border:1px solid rgba(59,130,246,.35); border-radius:7px; padding:calc(clamp(3px, 2.24px + 0.26vw, 5px) * var(--ccm-s)) calc(clamp(6px, 5.01px + 0.43vw, 9px) * var(--ccm-s)) calc(clamp(3px, 2.24px + 0.26vw, 5px) * var(--ccm-s)) calc(clamp(4.5px, 3.78px + 0.31vw, 7px) * var(--ccm-s)); font-size:calc(clamp(8px, 7.51px + 0.3vw, 10px) * var(--ccm-s)); font-weight:600; color:var(--text,#F0F2FF); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); white-space:nowrap; }
.ipv-ai-tag i{ font-size:calc(clamp(9px, 8.01px + 0.43vw, 12.5px) * var(--ccm-s)); color:#7fb2ff; }

/* ============================================================
   POST-AS-AD CTA PILL
   ============================================================ */
.ipv-cta-pill{ position:absolute; left:calc(clamp(8px, 7.02px + 0.59vw, 12px) * var(--ccm-s)); bottom:calc(clamp(22px, 18.05px + 1.73vw, 30px) * var(--ccm-s)); z-index:6; display:flex; align-items:center; justify-content:flex-start; padding:calc(clamp(6px, 5.01px + 0.43vw, 9px) * var(--ccm-s)) calc(clamp(9px, 8.02px + 0.59vw, 14px) * var(--ccm-s)); border-radius:8px; background:rgba(30,30,34,.55); border:1px solid rgba(var(--fg,255,255,255),.28); color:#fff; font-weight:700; font-size:calc(clamp(9px, 8.51px + 0.3vw, 11.5px) * var(--ccm-s)); text-decoration:none; box-shadow:0 4px 14px rgba(0,0,0,.3); white-space:nowrap; overflow:hidden; max-width:calc(100% - 24px); transition:padding .4s cubic-bezier(.22,1,.36,1), background .4s ease, border-color .4s ease,
               box-shadow .4s ease, min-width .4s cubic-bezier(.22,1,.36,1), opacity .25s ease, visibility .25s ease; }
.ipv-cta-pill.ipv-sp-raised{ bottom:76px; font-size:calc(clamp(10.5px, 10.01px + 0.3vw, 13px) * var(--ccm-s)); }
.ipv-cta-pill-text{ position:relative; z-index:1; overflow:hidden; text-overflow:ellipsis; }
.ipv-cta-pill-chevron{
    display:inline-block; font-size:0; opacity:0; max-width:0;
    overflow:hidden; margin-left:0; flex-shrink:0;
    transition:opacity .3s ease, max-width .3s ease, margin-left .3s ease, font-size .3s ease;
}
.ipv-cta-pill.ipv-cta-pill-expanded{ background:var(--cta-color, #FF4C4C); border-color:transparent; box-shadow:0 6px 22px rgba(0,0,0,.45); padding:calc(clamp(7px, 6.02px + 0.59vw, 12px) * var(--ccm-s)) calc(clamp(11px, 9.56px + 0.94vw, 18px) * var(--ccm-s)); min-width:min(300px, calc(100% - 24px)); justify-content:space-between; }
.ipv-cta-pill.ipv-cta-pill-expanded .ipv-cta-pill-chevron{
    opacity:1; max-width:20px; margin-left:auto; padding-left:8px; font-size:15px;
}

.ipv-ad-tag{ position:absolute; right:calc(clamp(6px, 5.02px + 0.59vw, 9px) * var(--ccm-s)); top:50%; transform:translateY(-50%); display:flex; align-items:center; font-size:calc(clamp(10.5px, 10.01px + 0.3vw, 12.5px) * var(--ccm-s)); font-weight:800; color:#FF3B30; letter-spacing:.03em; text-transform:uppercase; pointer-events:none; user-select:none; }
.ipv-ad-tag.ipv-new-flag{ color:var(--red,#FF2D2D); }
/* fresh ad: AD and NEW take turns in the same spot — each shows 8s, then flips */
.ipv-ad-tag.dz-flip{ display:grid; perspective:140px; }
.dz-flip .dz-fa, .dz-flip .dz-fb{ grid-area:1/1; justify-self:end; backface-visibility:hidden; -webkit-backface-visibility:hidden; }
.dz-flip .dz-fa{ color:#FF3B30; animation:dzFlipA 16s infinite; }
.dz-flip .dz-fb{ color:var(--red,#FF2D2D); animation:dzFlipB 16s infinite; }
@keyframes dzFlipA{ 0%,47%{ transform:rotateX(0) } 50%,97%{ transform:rotateX(-180deg) } 100%{ transform:rotateX(-360deg) } }
@keyframes dzFlipB{ 0%,47%{ transform:rotateX(180deg) } 50%,97%{ transform:rotateX(0) } 100%{ transform:rotateX(-180deg) } }

/* ============================================================
   SKELETON LOADER (same 3px dark separators as the posts)
   ============================================================ */
.ipv-skel-post{
    border-bottom:3px solid var(--ipv-sep);
    padding-bottom:12px;
    margin-bottom:0;
}
.ipv-skel-header{ display:flex; align-items:center; gap:10px; padding:11px 12px; }
.ipv-skel-avatar{ width:34px; height:34px; border-radius:50%; flex-shrink:0; }
.ipv-skel-header-lines{ flex:1; display:flex; flex-direction:column; gap:6px; }
.ipv-skel-line{ height:10px; border-radius:5px; }
.ipv-skel-line.w1{ width:120px; }
.ipv-skel-line.w2{ width:75px; height:8px; }
.ipv-skel-media{ width:100%; aspect-ratio:4/3.5; }
.ipv-skel-actions{ display:flex; gap:18px; padding:9px 12px 0; }
.ipv-skel-icon{ width:22px; height:22px; border-radius:6px; }
.ipv-skel-cap{ margin:11px 12px 0; height:10px; border-radius:5px; width:82%; }
.ipv-skel-cap2{ margin:6px 12px 0; height:10px; border-radius:5px; width:52%; }

.ipv-skel-avatar,.ipv-skel-line,.ipv-skel-media,.ipv-skel-icon,.ipv-skel-cap,.ipv-skel-cap2{
    background: linear-gradient(90deg, rgba(255,255,255,.05) 25%, rgba(255,255,255,.13) 37%, rgba(255,255,255,.05) 63%);
    background-size:400% 100%;
    animation: ipvSkelShimmer 1.4s ease-in-out infinite;
}
@keyframes ipvSkelShimmer{ 0%{background-position:100% 50%;} 100%{background-position:0% 50%;} }

.ipv-skel-post:nth-child(2) .ipv-skel-avatar,.ipv-skel-post:nth-child(2) .ipv-skel-line,
.ipv-skel-post:nth-child(2) .ipv-skel-media,.ipv-skel-post:nth-child(2) .ipv-skel-icon,
.ipv-skel-post:nth-child(2) .ipv-skel-cap,.ipv-skel-post:nth-child(2) .ipv-skel-cap2{ animation-delay:.08s; }
.ipv-skel-post:nth-child(3) .ipv-skel-avatar,.ipv-skel-post:nth-child(3) .ipv-skel-line,
.ipv-skel-post:nth-child(3) .ipv-skel-media,.ipv-skel-post:nth-child(3) .ipv-skel-icon,
.ipv-skel-post:nth-child(3) .ipv-skel-cap,.ipv-skel-post:nth-child(3) .ipv-skel-cap2{ animation-delay:.16s; }
.ipv-skel-post:nth-child(4) .ipv-skel-avatar,.ipv-skel-post:nth-child(4) .ipv-skel-line,
.ipv-skel-post:nth-child(4) .ipv-skel-media,.ipv-skel-post:nth-child(4) .ipv-skel-icon,
.ipv-skel-post:nth-child(4) .ipv-skel-cap,.ipv-skel-post:nth-child(4) .ipv-skel-cap2{ animation-delay:.24s; }

/* batch-loading sentinel */
.ipv-load-more{ display:flex; align-items:center; justify-content:center; padding:24px 0; }
.ipv-load-more-dot{ width:6px; height:6px; border-radius:50%; background:rgba(255,255,255,.35); margin:0 3px; animation:ipvLoadDot 1.1s ease-in-out infinite; }
.ipv-load-more-dot:nth-child(2){ animation-delay:.15s; }
.ipv-load-more-dot:nth-child(3){ animation-delay:.3s; }
@keyframes ipvLoadDot{ 0%,80%,100%{ transform:scale(.6); opacity:.4; } 40%{ transform:scale(1); opacity:1; } }

@keyframes ipvPostFadeIn{ from{ opacity:0; transform:translateY(10px); } to{ opacity:1; transform:translateY(0); } }
.ipv-post-enter{ animation: ipvPostFadeIn .45s cubic-bezier(.22,1,.36,1) both; }

/* ============================================================
   RESPONSIVE — 790 / 490 / 390 / 330
   ============================================================ */
@media (max-width:790px){
    .ipv-overlay{ scrollbar-width:none; -ms-overflow-style:none; }
    .ipv-overlay::-webkit-scrollbar{ display:none; width:0; height:0; }

    .ipv-topbar{ padding:6px 14px; }   
      
     

    .ipv-kebab{ margin-right:-5px; }
      
    
    .ipv-social-row,.ipv-caption,.ipv-header-caption,.ipv-time{ padding-left:11px; padding-right:11px; } .ipv-caption,.ipv-header-caption{ font-size:12px; }
    .ipv-social-proof-strip.ipv-sp-raised{ bottom:62px; }
    .ipv-partnership-tag{ max-width:calc(100% - 22px); }
    
    .ipv-group-tag,.ipv-media-counter{ font-size:9.5px; }
    
    
    
    
    
    .ipv-cta-pill.ipv-sp-raised{ bottom:62px; }
    
     
    .ipv-post.ipv-long-header .ipv-top-right-tags,
    .ipv-post.ipv-long-header .ipv-top-left-tags{ top:54px; }
    
    .ipv-social-avatars img{ margin-left:-6px; }
     
    .ipv-double-tap-heart i{ font-size:70px; }
}
@media (max-width:490px){
    .ipv-topbar{ padding:5px 12px; }   
      

    .ipv-kebab{ margin-right:-4px; }
     
     
    
     
    
    .ipv-social-proof-strip.ipv-sp-raised{ bottom:58px; }
    .ipv-social-avatars img{ margin-left:-6px; } 
    .ipv-caption,.ipv-header-caption,.ipv-time{ padding-left:10px; padding-right:10px; } .ipv-caption,.ipv-header-caption{ font-size:11.5px; } 
    .ipv-partnership-tag{ max-width:calc(100% - 20px); }
    
    .ipv-group-tag,.ipv-media-counter{ font-size:9px; padding:4px 7px; }
    
    
    
    
    
    
    .ipv-cta-pill.ipv-sp-raised{ bottom:58px; }
    
    
     
    .ipv-post.ipv-long-header .ipv-top-right-tags,
    .ipv-post.ipv-long-header .ipv-top-left-tags{ top:50px; }
     
    .ipv-double-tap-heart i{ font-size:60px; }
}
@media (max-width:390px){
    .ipv-topbar{ padding:5px 11px; }   
      
     

    .ipv-kebab{ margin-right:-3px; }
     
     
    
    
    .ipv-social-proof-strip.ipv-sp-raised{ bottom:54px; }
    .ipv-social-avatars img{ margin-left:-5.5px; } 
    .ipv-caption,.ipv-header-caption,.ipv-time{ padding-left:9px; padding-right:9px; } .ipv-caption,.ipv-header-caption{ font-size:11px; line-height:1.45; } 
    .ipv-partnership-tag{ max-width:calc(100% - 18px); }
    
    
    
    
    
    .ipv-cta-pill{ gap:4px; }
    .ipv-cta-pill.ipv-sp-raised{ bottom:54px; }
    
    
     
    .ipv-post.ipv-long-header .ipv-top-right-tags,
    .ipv-post.ipv-long-header .ipv-top-left-tags{ top:46px; }
    .ipv-double-tap-heart i{ font-size:52px; }
}
@media (max-width:330px){
    .ipv-topbar{ padding:4px 9px; }   
      
     

    .ipv-kebab{ margin-right:-3px; }
     
      
    
    .ipv-social-proof-strip.ipv-sp-raised{ bottom:48px; }
    .ipv-caption,.ipv-header-caption,.ipv-time{ padding-left:8px; padding-right:8px; } .ipv-caption,.ipv-header-caption{ font-size:10.3px; } 
    .ipv-partnership-tag{ max-width:calc(100% - 16px); }
    
    
    
    
    
    .ipv-cta-pill{ gap:4px; }
    .ipv-cta-pill.ipv-sp-raised{ bottom:48px; }
    
    
     
    .ipv-post.ipv-long-header .ipv-top-right-tags,
    .ipv-post.ipv-long-header .ipv-top-left-tags{ top:42px; }
    .ipv-double-tap-heart i{ font-size:46px; }
}
/* app-style swipe-to-close: the browser keeps vertical scrolling, but never takes a sideways drag (no history-back swipe, no sideways pan) */
#memeModal, #cardCaptionModal, #ipvOverlay{ touch-action:pan-y; overscroll-behavior-x:none; }
/* media-post captions (not text-only): 11 to 12.5px fluid, x the phone multiplier, line height 1.5 */
.ipv-caption, .ipv-header-caption{ font-size:calc(clamp(11px, .37vw + 9.6px, 12.5px) * var(--ccm-s)); line-height:1.5; }
</style>

<template id="dzIpvTplJs">
<script>
(function(){

/* ============================================================
   HOOK INTO YOUR EXISTING REEL MODAL
   ============================================================ */
function ipvInstallHooks(){
    if(typeof window.openMemeModal !== 'function' || typeof window.closeMemeModal !== 'function'){
        setTimeout(ipvInstallHooks, 50);
        return;
    }
    if(window._ipvHooksInstalled) return;
    window._ipvHooksInstalled = true;

    window._ccmOpenReel  = window.openMemeModal;
    window._ccmCloseReel = window.closeMemeModal;

    window.openMemeModal = function(src, type, caption, pic, username, userId, feedName, memeId){
        ipvOpen(feedName, memeId);
    };

    window.closeMemeModal = function(){
        var playback = (typeof window.ccmGetCurrentPlayback === 'function') ? window.ccmGetCurrentPlayback() : null;
        window._ccmCloseReel();
        if(_ipvActive){
            document.body.style.overflow = 'hidden';

            var landingMemeId = (playback && playback.memeId) ? playback.memeId : _ipvReelOriginMemeId;

            if(playback && playback.memeId){
                _ipvApplyPlaybackToPost(playback);
            }

            if(landingMemeId != null){
                var landingPost = document.querySelector('#ipvList .ipv-post[data-id="'+landingMemeId+'"]');
                if(landingPost) landingPost.scrollIntoView({ block:'start' });
            }

            _ipvResumeFocusedVideo();
            _ipvReelOriginMemeId = null;
            _ipvReelOriginFeed   = null;
        }
    };
}
ipvInstallHooks();

/* ============================================================
   STATE
   ============================================================ */
var _ipvActive        = false;
var _ipvFeedName      = null;
var _ipvFeedArray     = [];
var _ipvObserver      = null;
var _ipvActionLock    = {};
var _ipvSwitchers     = {};
var _ipvCtaPillTimers = {};
var _ipvVisibility    = {};
var _ipvCurrentAudioPostId = null;
var _ipvPendingShare  = null;
var _ipvTrackMuted    = true;

var _ipvReelOriginMemeId = null;
var _ipvReelOriginFeed   = null;

/* batched infinite-scroll state */
var _ipvBatchSize        = 4;
var _ipvRenderedCount    = 0;
var _ipvSentinelObserver = null;
var _ipvSkeletonTimer    = null;
var _ipvLoadingBatch     = false;

/* the shimmer skeleton only appears ONCE per page load */
var _ipvSkeletonShownOnce = false;

/* ============================================================
   HELPERS
   ============================================================ */
var IPV_HEART_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path class="sp-ring" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" stroke-width="3.67" stroke-linejoin="round"/><path class="sp-fill" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>';
function ipvEsc(str){
    if(str === null || str === undefined) return '';
    /* escapes quotes too, so it is safe inside an HTML attribute */
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
/* only http(s) / relative links are allowed for user-supplied URLs (blocks javascript: and data: links) */
function ipvSafeUrl(u){
    u = String(u == null ? '' : u).trim();
    if(/^(https?:)?\/\//i.test(u) || /^[\w\-./?#=&%+~,:@]*$/.test(u) && !/^[a-z][a-z0-9+.\-]*:/i.test(u)) return u;
    return '#';
}

function ipvFmtNum(n){
    n = parseInt(n) || 0;
    if(n >= 1000000000) return (n/1000000000).toFixed(1).replace(/\.0$/,'')+'B';
    if(n >= 1000000)    return (n/1000000).toFixed(1).replace(/\.0$/,'')+'M';
    if(n >= 1000)       return (n/1000).toFixed(1).replace(/\.0$/,'')+'K';
    return n;
}

function _ipvTypeTagInfo(rawType){
    var type = (rawType || '').toLowerCase();
    var map = {
        image:    { icon:'ri-file-copy-line', label:'Photo'   },
        video:    { icon:'ri-vidicon-line',   label:'Video'   },
        gif:      { icon:'ri-file-gif-line',  label:'GIF'     },
        sticker:  { icon:'ri-shapes-line',    label:'Sticker' },
        stickers: { icon:'ri-shapes-line',    label:'Sticker' },
        text:     { icon:'ri-file-text-line', label:'Text'    }
    };
    return map[type] || { icon:'ri-file-copy-line', label:'Photo' };
}

function ipvLinkify(caption){
    caption = caption || '';
    return caption
        .replace(/#([a-zA-Z0-9_]+)/g, function(m, tag){
            var mq = encodeURIComponent('#'+tag);
            return '<a href="search.php?focus=1&mq='+mq+
                   '" class="ipv-hl" onclick="event.stopPropagation()">'+m+'</a>';
        })
        .replace(/@([a-zA-Z0-9_]+)/g, function(m, u){
            return '<a href="profile.php?username='+encodeURIComponent(u)+
                   '" onclick="event.stopPropagation()" style="color:var(--red,#FF2D2D);font-weight:600;text-decoration:none;">'+m+'</a>';
        });
}

function ipvFormatFullDate(timeStr){
    if(!timeStr) return '';
    var d = new Date((timeStr||'').replace(' ','T'));
    if(isNaN(d)) return timeStr;
    var days   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return days[d.getDay()]+', '+d.getDate()+' '+months[d.getMonth()]+' '+d.getFullYear()+
           ' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}

function ipvIsNew(timeStr){
    if(!timeStr) return false;
    var d = new Date((timeStr||'').replace(' ','T'));
    if(isNaN(d)) return false;
    return (Date.now() - d.getTime()) <= (172800*1000);
}

function ipvLikesText(p){
    var proof = Array.isArray(p.socialProof) ? p.socialProof : [];
    var likeProof = proof.filter(function(sp){ return sp.action_type === 'like'; });
    var count = p.hideLikes ? 0 : (p.likeCount || 0);
    var repostCount = p.hideReposts ? 0 : (p.repostCount || 0);

    var base = '';
    if(count > 0){
        if(likeProof.length > 0){
            var first = ipvEsc(likeProof[0].username || '') + (likeProof[0].isVerified ? (window.DZ_VB||'') : '');
            var rest  = count - 1;
            base = rest > 0
                ? '<span class="ipv-likes-dim">Liked by</span> <b>'+first+'</b> <span class="ipv-likes-dim">and</span> <b>'+ipvFmtNum(rest)+' other'+(rest===1?'':'s')+'</b>'
                : '<span class="ipv-likes-dim">Liked by</span> <b>'+first+'</b>';
        } else {
            base = '<b>'+ipvFmtNum(count)+' like'+(count===1?'':'s')+'</b>';
        }
    }
    if(repostCount > 0){
        var repostPart = '<b>'+ipvFmtNum(repostCount)+' repost'+(repostCount===1?'':'s')+'</b>';
        base = base ? base+' · '+repostPart : repostPart;
    }
    return base;
}

function _ipvEscapeHtml(str){ return ipvEsc(str); }

/* ============================================================
   RENDER A SINGLE POST CARD
   ============================================================ */
/* ── comments: open the sheet + real comment count for the posts in this viewer (self-contained) ── */
window.ipvOpenComments = function(id){
    dzHp('light');
    if(window.CommentSection && typeof window.CommentSection.open === 'function'){ window.CommentSection.open(id); return; }
    if(typeof showToast === 'function') showToast('Comments are not available on this page');
};
var _ipvCmPending = {};
function _ipvCmFmt(n){ n = +n || 0; return n >= 1e6 ? (n/1e6).toFixed(1).replace('.0','')+'M' : n >= 1e3 ? (n/1e3).toFixed(1).replace('.0','')+'K' : String(n); }
function _ipvLoadCommentCounts(root){
    var els = (root || document).querySelectorAll('[data-cm-count]:not([data-n])'); if(!els.length) return;
    var ids = [], seen = {};
    Array.prototype.forEach.call(els, function(el){
        var id = el.getAttribute('data-cm-count');
        if(id && !seen[id] && !_ipvCmPending[id] && ids.length < 50){ seen[id] = 1; ids.push(id); }
    });
    if(!ids.length) return;
    ids.forEach(function(id){ _ipvCmPending[id] = 1; });
    var req = (window.CommentSection && typeof window.CommentSection.status === 'function')
        ? window.CommentSection.status(ids)
        : (window.CommentSection && typeof window.CommentSection.counts === 'function')
            ? window.CommentSection.counts(ids).then(function(c){ return {counts:c, off:{}}; })
            : fetch('comment_section.php?action=count&ids=' + ids.join(','), {credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(j){ return {counts:j.counts || {}, off:j.off || {}}; });
    req.then(function(r){
        var m = (r && r.counts) || {}, off = (r && r.off) || {};
        ids.forEach(function(id){
            var n = (m && m[id]) || 0, isOff = !!off[id];
            Array.prototype.forEach.call(document.querySelectorAll('[data-cm-count="'+id+'"]'), function(el){
                el.setAttribute('data-n', n); el.textContent = isOff ? '' : _ipvCmFmt(n);
                if(isOff) el.setAttribute('data-off', '1'); else el.removeAttribute('data-off');
                var g = el.closest ? el.closest('.ipv-action-group') : null, b = g && g.querySelector('.ipv-comment-btn');
                if(b) b.classList.toggle('cm-off', isOff);
            });
        });
    }).catch(function(){}).then(function(){ ids.forEach(function(id){ delete _ipvCmPending[id]; }); });
}
if(!window.__ipvCmListen){
    window.__ipvCmListen = true;
    window.addEventListener('cs:changed', function(e){
        var d = e.detail || {};
        Array.prototype.forEach.call(document.querySelectorAll('[data-cm-count="'+d.meme+'"]'), function(el){
            var n = Math.max(0, (parseInt(el.getAttribute('data-n'), 10) || 0) + (d.delta || 0)); el.setAttribute('data-n', n); el.textContent = _ipvCmFmt(n);
        });
    });
}

window.hpCollabOf=function(id,feed){var a=[].concat((feed&&window[feed])||[],window._risePool||[]);for(var i=0;i<a.length;i++){if(String(a[i].id)===String(id))return a[i].collab||null}return null};
window.dzCollabFit=function(){document.querySelectorAll('.av-wrap.ipv-collab:not([data-fit])').forEach(function(w){var h=w.closest('.ipv-header')||w.parentNode;var pr=document.createElement('span');pr.className='av-wrap';pr.style.cssText='position:absolute;visibility:hidden;left:-9999px;top:0';pr.innerHTML='<img class="ipv-avatar" alt="">';h.appendChild(pr);var s=pr.firstChild.getBoundingClientRect().width;h.removeChild(pr);if(s>8){w.style.setProperty('--cw',Math.round(s*1.45)+'px');w.setAttribute('data-fit','1');}});};
(function(){var t=0;function q(){if(t)return;t=requestAnimationFrame(function(){t=0;dzCollabFit();});}try{new MutationObserver(q).observe(document.documentElement,{childList:true,subtree:true});}catch(e){}window.addEventListener('resize',function(){document.querySelectorAll('.av-wrap.ipv-collab[data-fit]').forEach(function(w){w.removeAttribute('data-fit');});q();});q();})();

function ipvRenderPost(p, feedName){
    var media = (Array.isArray(p.media) && p.media.length) ? p.media : [{file:p.file, type:p.type}];
    var mediaCount = media.length;
    var isOwn = (p.isYou === true);

    var mediaSet   = ['image','video','gif','sticker','stickers'];
    var firstType  = ((media[0].type)||'').toLowerCase();
    var isTextOnly = mediaCount === 1 && mediaSet.indexOf(firstType) === -1;
    var hasVideo   = media.some(function(m){ return (m.type||'').toLowerCase() === 'video'; });

    var mediaInner;
    if(isTextOnly){
        /* TEXT POST — sits on the lane colour, tag-gap keeps the first line clear of the "Text" tag */
        var full  = ipvLinkify(p.caption || '');
        var words = (p.caption||'').trim().split(/\s+/).filter(Boolean);
        var trunc = words.length > 40 ? ipvLinkify(words.slice(0,40).join(' '))+
            ' <span class="ipv-more-wrap" onclick="event.stopPropagation();ipvToggleCenterCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>' : full;
        mediaInner =
            '<div class="ipv-textonly-clickable" onclick="ipvHandleMediaTap(event,this,\''+feedName+'\','+p.id+')">'+
                '<div class="ipv-center-caption-wrap">'+
                    '<span class="ipv-tag-gap"></span>'+
                    '<div class="ipv-center-caption-text ipv-t-'+((p.caption||'').trim().length<=90?'s':((p.caption||'').trim().length<=220?'m':'l'))+'" data-full="'+ipvEsc(p.caption||'')+'">'+trunc+'</div>'+
                '</div>'+
            '</div>';
    } else {
        mediaInner = '<div class="ipv-media-track">'+media.map(function(m, i){
            var t = (m.type||'').toLowerCase();
            if(t === 'video'){
                return '<div class="ipv-media-item" data-idx="'+i+'" data-type="video">'+
                    '<video src="'+ipvEsc(m.file)+'" muted loop playsinline preload="metadata" '+(i===0?'autoplay':'')+
                    ' class="ipv-video" data-idx="'+i+'" '+
                    'onloadedmetadata="ipvMeasureAspect(this)" '+
                    'onclick="event.stopPropagation();ipvHandleMediaTap(event,this,\''+feedName+'\','+p.id+')"></video>'+
                '</div>';
            }
            var src = (t === 'text' || !m.file) ? 'uploads/Text.jpg' : m.file;
            return '<div class="ipv-media-item" data-idx="'+i+'" data-type="'+(t||'image')+'">'+
                '<img src="'+ipvEsc(src)+'" data-idx="'+i+'" loading="lazy" draggable="false" onerror="this.onerror=null;this.src=\'uploads/Text.jpg\'" '+
                'onload="ipvMeasureAspect(this)" '+
                'onclick="event.stopPropagation();ipvHandleMediaTap(event,this,\''+feedName+'\','+p.id+')">'+
            '</div>';
        }).join('')+'</div>';
    }

    var dotsHtml = (!isTextOnly && mediaCount > 1)
        ? '<div class="ipv-dots">'+media.slice(0,20).map(function(_,i){return '<span class="ipv-dot'+(i===0?' active':'')+'"></span>';}).join('')+'</div>'
        : '';
    var counterHtml = (!isTextOnly && mediaCount > 1) ? '<div class="ipv-media-counter">1/'+mediaCount+'</div>' : '';

    var typeInfo     = _ipvTypeTagInfo(isTextOnly ? 'text' : firstType);
    var typeTagHtml  = '';   /* media type tag removed */
    var topRightHtml = '<div class="ipv-top-right-tags">'+counterHtml+typeTagHtml+'</div>';

    var arrowsHtml = (!isTextOnly && mediaCount > 1)
        ? '<button class="ipv-media-arrow ipv-arrow-left" onclick="event.stopPropagation();ipvArrowNav(this,-1)"><i class="ri-arrow-left-s-line"></i></button>'+
          '<button class="ipv-media-arrow ipv-arrow-right" onclick="event.stopPropagation();ipvArrowNav(this,1)"><i class="ri-arrow-right-s-line"></i></button>'
        : '';

    var topLeftInner = '';
    if(p.groupTitle){
        topLeftInner += '<a href="group.php?id='+p.groupId+'" onclick="event.stopPropagation()" class="ipv-group-tag">'+
              'From&nbsp;<b>'+ipvEsc(p.groupTitle)+'</b>'+
          '</a>';
    }
    var topLeftHtml = topLeftInner ? '<div class="ipv-top-left-tags">'+topLeftInner+'</div>' : '';

    var partnershipHtml = '';   /* "Paid partnership" now flips under the username (see _ipvLines) */

    var socialStripHtml = ipvStripHtml(p);

    var ctaPillHtml = '';
    if(p.isAd && p.adCtaUrl){
        var ctaClass = 'ipv-cta-pill' + '';
        var ctaColor = p.adCtaColor || '#FF4C4C';
        var ctaLabel = p.adCtaText || 'Learn more';
        ctaPillHtml = '<a href="'+ipvEsc(ipvSafeUrl(p.adCtaUrl))+'" target="_blank" rel="noopener" id="ipv-cta-pill-'+p.id+'" class="'+ctaClass+'" '+
            'data-meme-id="'+p.id+'" style="--cta-color:'+ipvEsc(ctaColor)+';" onclick="event.stopPropagation()">'+
            '<span class="ipv-cta-pill-text">'+_ipvEscapeHtml(ctaLabel)+'</span>'+
            '<i class="ri-arrow-right-s-line ipv-cta-pill-chevron"></i>'+
        '</a>';
    }

    /* ONE sound button per post. A video's "Original audio" row points at the video file itself, so it is never also played as a separate track
       (that played the same sound twice and each button only controlled one copy). Ads never get a track. */
    /* the "track" must never be the video's own sound: flagged original, OR the very same file as one of the post's videos (that played the sound twice, a beat apart = the echo) */
    var _ipvBase = function(u){ return String(u||'').split('?')[0].split('/').pop().toLowerCase(); };
    var audioIsVideoFile = !!p.audioFile && media.some(function(m){ return (m.type||'').toLowerCase()==='video' && _ipvBase(m.file)===_ipvBase(p.audioFile); });
    var useTrack = !!p.audioFile && !p.isAd && !(p.audioIsOriginal && hasVideo) && !audioIsVideoFile;
    var controlsHtml = '';
    if(!isTextOnly && (hasVideo || p.audioFile)){
        controlsHtml = '<div class="ipv-media-controls">';
        if(hasVideo){
            var firstIsVideo = firstType === 'video';
            var hideStyle = (mediaCount > 1 && !firstIsVideo && !useTrack) ? ' style="display:none"' : '';
            controlsHtml += '<button class="ipv-mute-btn ipv-video-mute-btn"'+hideStyle+' onclick="event.stopPropagation();ipvToggleVideoMute(this)"><i class="dz-snd off"></i></button>';
        } else {
            controlsHtml += '<button class="ipv-mute-btn ipv-track-mute-btn" onclick="event.stopPropagation();ipvToggleTrackMute(this)"><i class="dz-snd off"></i></button>';
        }
        controlsHtml += '</div>';
    }

    var isPostsTab = (!p.context || p.context === 'Uploads');
    var trackBtn = (!isOwn && !isPostsTab)
        ? '<span class="ipv-dot-sep-red">•</span><button class="ipv-track-btn" data-user="'+p.userId+'" onclick="event.stopPropagation();ipvToggleTrack(this)">'+(window.dzFollowing(p.userId,p.isFollowing)?'Untrack':'Track')+'</button>'
        : '';

    var displayName = isOwn ? 'You' : ipvEsc(p.username || '');
    var isNew = ipvIsNew(p.time);
    var newTag = '';   /* the NEW word now sits at the right end of the action row, in the spot where AD shows (see adTagHtml) */

    var likeIcon = p.liked ? 'ri-heart-3-fill' : 'ri-heart-3-line';
    var favIcon  = p.faved ? 'ri-bookmark-fill' : 'ri-bookmark-line';

    var nicknameHtml = (!isOwn && p.nickname) ? '@'+ipvEsc(p.nickname) : '';
    var _co=(p.collab&&p.collab.list&&p.collab.list.length)?p.collab:null,coName=displayName,coPics='',coOpen='';
    if(_co){
      coOpen='event.stopPropagation();dzCollabSheet('+p.id+',hpCollabOf('+p.id+',\''+feedName+'\'))';
      coName=displayName+' and '+(_co.list.length===1?ipvEsc(_co.list[0].username):_co.list.length+' others');
      nicknameHtml='';trackBtn='';
      _co.list.slice(0,2).forEach(function(c,i){coPics+='<img class="ipv-avatar ipv-co-pic c'+(i+1)+'" src="'+ipvEsc(c.pic||'mee.jpg')+'" onerror="this.onerror=null;this.src=\'mee.jpg\'" onclick="'+coOpen+'">';});
    }

    var audioHtml = '';
    if(p.audioId !== undefined && p.audioId !== null){
        var audioLabel;
        if(p.audioIsOriginal){
            audioLabel = (isOwn ? p.username : displayName) + ' · Original audio';
        } else {
            audioLabel = (p.audioTitle || 'Audio') + (p.audioArtist ? ' — '+p.audioArtist : '');
        }

        audioHtml = '<div class="ipv-audio-tag" onclick="event.stopPropagation();ccmOpenSoundsPage('+p.userId+','+p.audioId+','+(p.isAd?'true':'false')+')" style="cursor:pointer;">'+
            '<span class="ipv-audio-icon"><span class="ipv-eq-bar"></span><span class="ipv-eq-bar"></span><span class="ipv-eq-bar"></span></span>'+
            '<span class="ipv-audio-text">'+ipvEsc(audioLabel)+'</span>'+
        '</div>';
    }

    /* nickname, audio and "AI Generated" take turns in the same spot (fades every 5s); a lone line just stays put */
    var _ipvLines = [];
    if(nicknameHtml) _ipvLines.push('<div class="ipv-sub-line">'+nicknameHtml+'</div>');
    if(p.isPartnership) _ipvLines.push('<div class="ipv-sub-line ipv-partner-line"><span>Paid partnership'+(p.partnerBrand?' with <b>'+ipvEsc(p.partnerBrand)+'</b>':'')+'</span></div>');
    if(audioHtml)    _ipvLines.push(audioHtml);
    if(p.isAiGenerated) _ipvLines.push('<div class="ipv-sub-line">AI Generated</div>');
    var switcherHtml = '';
    if(_ipvLines.length > 1){
        switcherHtml = '<div class="ipv-info-switcher" data-switcher="1">'+
            _ipvLines.map(function(h, i){ return '<div class="ipv-switch-pane'+(i===0?' active':'')+'">'+h+'</div>'; }).join('')+
        '</div>';
    } else if(_ipvLines.length){
        switcherHtml = _ipvLines[0];
    }

    var socialAvatars = '';
    var likeProofForRow = Array.isArray(p.socialProof) ? p.socialProof.filter(function(sp){ return sp.action_type === 'like'; }) : [];
    if(likeProofForRow.length){
        socialAvatars = '<div class="ipv-social-avatars">'+
            likeProofForRow.slice(0,3).map(function(sp){
                return '<img src="'+ipvEsc(sp.profile_pic||'mee.jpg')+'" onerror="this.onerror=null;this.src=\'mee.jpg\'">';
            }).join('')+
        '</div>';
    }
    var likesText = ipvLikesText(p);
    var socialRow = (socialAvatars || likesText)
        ? '<div class="ipv-social-row" onclick="event.stopPropagation();ipvOpenLikersSheet('+p.id+',\''+feedName+'\')">'+socialAvatars+(likesText?'<div class="ipv-likes-text">'+likesText+'</div>':'')+'</div>'
        : '';

    /* Caption: rendered twice from the same markup —
       header caption (under the avatar row) shows by default;
       the below-media caption (with red username) only shows on long-header posts. CSS decides. */
    var headerCaptionBlock = '';
    var captionBlock = '';
    if(!isTextOnly){
        var words2  = (p.caption || '').trim().split(/\s+/).filter(Boolean);
        var isLong  = words2.length > 14;
        var full2   = ipvLinkify(p.caption || '');
        var capHtml;
        if(isLong){
            var trunc2 = ipvLinkify(words2.slice(0,14).join(' '));
            capHtml = '<span class="ipv-cap-short" data-full="'+ipvEsc(p.caption||'')+'" data-expanded="0">'+trunc2+
                '<span class="ipv-more-wrap" onclick="event.stopPropagation();ipvToggleCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span></span>';
        } else {
            capHtml = full2;
        }
        headerCaptionBlock = '<div class="ipv-header-caption">'+capHtml+'</div>';
        captionBlock = '<div class="ipv-caption"><b class="ipv-cap-user">'+displayName+(p.isVerified ? (window.DZ_VB||'') : '')+'</b>'+capHtml+'</div>';
    }

    /* time row — text posts get the "Click Text to zeem" hint like home */
    var timeHtml = '';
    if(isTextOnly){
        timeHtml = '<div class="ipv-time ipv-time-row"><div class="ipv-time-left"><span>'+(p.time ? ipvEsc(ipvFormatFullDate(p.time)) : '')+'</span>'+
                   '<span class="ipv-zeem-hint">Click Text to zeem</span></div></div>';
    } else if(p.time){
        timeHtml = '<div class="ipv-time">'+ipvEsc(ipvFormatFullDate(p.time))+'</div>';
    }

    var wrapClass = 'ipv-media-wrap' + (!isTextOnly && mediaCount > 1 ? ' ipv-multi' : '') +
                    (isTextOnly ? ' ipv-textonly' + (topLeftInner ? ' ipv-tl' : '') : '');

    var shareCount = p.shareCount !== undefined ? p.shareCount : 0;

    /* right end of the action row: AD for ads, NEW for fresh posts; a fresh ad flips AD <-> NEW every 8 seconds */
    var adTagHtml = p.isAd
        ? (isNew ? '<div class="ipv-ad-tag dz-flip"><span class="dz-fa">Ad</span><span class="dz-fb">New</span></div>' : '<div class="ipv-ad-tag">Ad</div>')
        : (isNew ? '<div class="ipv-ad-tag ipv-new-flag">New</div>' : '');

    /* text posts: tags first, then the text, then avatars / CTA / partnership UNDER it (in the flow) */
    var ipvSpSlot = '<div class="ipv-sp-slot" style="display:contents">'+socialStripHtml+'</div>';
    var wrapInner = isTextOnly
        ? (topLeftHtml+topRightHtml+mediaInner+ipvSpSlot+ctaPillHtml+partnershipHtml)
        : (topLeftHtml+topRightHtml+ipvSpSlot+ctaPillHtml+mediaInner+arrowsHtml+controlsHtml+partnershipHtml+dotsHtml);

    return (
        '<div class="ipv-post'+(isTextOnly?' ipv-post-text':'')+'" data-id="'+p.id+'" data-feed="'+feedName+'" '+
             (useTrack ? 'data-audio="'+ipvEsc(p.audioFile)+'" data-music-vol="'+(p.musicVolume!==undefined&&p.musicVolume!==null?p.musicVolume:80)+'" data-video-vol="'+(p.videoAudioVolume!==undefined&&p.videoAudioVolume!==null?p.videoAudioVolume:40)+'"' : '')+
        '>'+
            '<div class="ipv-header">'+
                '<span class="av-wrap'+(_co?' ipv-collab n'+Math.min(2,_co.list.length):'')+'">'+'<img class="ipv-avatar" src="'+ipvEsc(p.pic||'mee.jpg')+'" onerror="this.onerror=null;this.src=\'mee.jpg\'" onclick="'+(_co?coOpen:'location.href=\'profile.php?user_id='+p.userId+'\'')+'">'+coPics+(p.online?'<span class="on-dot"></span>':'')+'</span>'+
                '<div class="ipv-header-info">'+
                    '<div class="ipv-username-row">'+
                        '<span class="ipv-username'+(_co?' ipv-co-name':'')+'" onclick="'+(_co?coOpen:'location.href=\'profile.php?user_id='+p.userId+'\'')+'">'+coName+'</span>'+
                        ((p.isVerified&&!_co) ? (window.DZ_VB||'') : '')+
                        newTag+
                        trackBtn+
                    '</div>'+
                    switcherHtml+
                '</div>'+
                '<button class="ipv-kebab" onclick="event.stopPropagation();ipvOpenMenu('+p.id+',\''+feedName+'\')"><i class="ri-menu-line"></i></button>'+
            '</div>'+

            headerCaptionBlock+

            '<div class="'+wrapClass+'" data-count="'+mediaCount+'" data-active-idx="0">'+
                wrapInner+
            '</div>'+

            '<div class="ipv-actions">'+
                '<div class="ipv-actions-left">'+
                    '<div class="ipv-action-group">'+
                        '<button class="ipv-action-btn ipv-like-btn'+(p.liked?' liked':'')+'" onclick="event.stopPropagation();ipvAction('+p.id+',\'like\',this,\''+feedName+'\')"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i></button>'+
                        '<span class="ipv-action-count" data-count="like"'+(p.hideLikes?' data-hidden="1"':'')+'>'+ipvFmtNum(p.likeCount||0)+'</span>'+
                    '</div>'+
                    (p.isAd?'':('<div class="ipv-action-group">'+
                        '<button class="ipv-action-btn ipv-repost-btn'+(p.reposted?' liked':'')+'" onclick="event.stopPropagation();ipvAction('+p.id+',\'repost\',this,\''+feedName+'\')"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg></i></button>'+
                        '<span class="ipv-action-count" data-count="repost"'+(p.hideReposts?' data-hidden="1"':'')+'>'+ipvFmtNum(p.repostCount||0)+'</span>'+
                    '</div>'))+
                    (('<div class="ipv-action-group">'+
                        '<button class="ipv-action-btn ipv-comment-btn" aria-label="Comments" onclick="event.stopPropagation();ipvOpenComments('+p.id+')"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg><i class="ri-chat-off-line cm-off-ic" aria-hidden="true"></i></i></button>'+
                        '<span class="ipv-action-count" data-count="comment" data-cm-count="'+p.id+'">0</span>'+
                    '</div>'))+
                    '<div class="ipv-action-group">'+
                        '<button class="ipv-action-btn ipv-fav-btn'+(p.faved?' faved':'')+'" onclick="event.stopPropagation();ipvAction('+p.id+',\'fav\',this,\''+feedName+'\')"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21l-7-5-7 5V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1z"/></svg></i></button>'+
                        '<span class="ipv-action-count" data-count="fav"'+(p.hideSaves?' data-hidden="1"':'')+'>'+ipvFmtNum(p.favCount||0)+'</span>'+
                    '</div>'+
                    '<div class="ipv-action-group">'+
                        '<button class="ipv-action-btn ipv-share-btn" onclick="event.stopPropagation();ipvShareAction('+p.id+',this,\''+feedName+'\')"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i></button>'+
                        '<span class="ipv-action-count" data-count="share"'+(p.hideShares?' data-hidden="1"':'')+'>'+ipvFmtNum(shareCount)+'</span>'+
                    '</div>'+
                '</div>'+
                adTagHtml+
            '</div>'+

            socialRow+
            captionBlock+
            timeHtml+
        '</div>'
    );
}

/* ============================================================
   SKELETON LOADER MARKUP
   ============================================================ */
function _ipvSkeletonHTML(n){
    var one = '<div class="ipv-skel-post">'+
        '<div class="ipv-skel-header"><div class="ipv-skel-avatar"></div>'+
        '<div class="ipv-skel-header-lines"><div class="ipv-skel-line w1"></div><div class="ipv-skel-line w2"></div></div></div>'+
        '<div class="ipv-skel-media"></div>'+
        '<div class="ipv-skel-actions"><div class="ipv-skel-icon"></div><div class="ipv-skel-icon"></div><div class="ipv-skel-icon"></div><div class="ipv-skel-icon"></div></div>'+
        '<div class="ipv-skel-cap"></div><div class="ipv-skel-cap2"></div>'+
    '</div>';
    var out = '';
    for(var i=0;i<n;i++) out += one;
    return out;
}

/* ============================================================
   CAPTION EXPAND / COLLAPSE TOGGLES
   While any caption on a post is expanded ("more"), the post gets
   .ipv-cap-open, which hides the avatars, CTA pill and partnership
   tag on the media. They return when the caption is collapsed.
   ============================================================ */
function _ipvSyncCapState(post){
    if(!post) return;
    var open = post.querySelector('.ipv-cap-short[data-expanded="1"], .ipv-center-caption-text[data-expanded-now="1"]');
    post.classList.toggle('ipv-cap-open', !!open);
}

window.ipvToggleCaption = function(el){
    var wrap = el.closest('.ipv-cap-short');
    if(!wrap) return;
    var post = el.closest('.ipv-post');
    var expanded = wrap.dataset.expanded === '1';
    if(!expanded){
        wrap.dataset.expanded = '1';
        wrap.innerHTML = ipvLinkify(wrap.dataset.full) +
            ' <span class="ipv-more-toggle" onclick="event.stopPropagation();ipvToggleCaption(this)"> less</span>';
    } else {
        wrap.dataset.expanded = '0';
        var words = (wrap.dataset.full||'').trim().split(/\s+/).filter(Boolean);
        var trunc = ipvLinkify(words.slice(0,14).join(' '));
        wrap.innerHTML = trunc + '<span class="ipv-more-wrap" onclick="event.stopPropagation();ipvToggleCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>';
    }
    _ipvSyncCapState(post);
};

window.ipvToggleCenterCaption = function(el){
    var textEl = el.closest('.ipv-center-caption-text');
    if(!textEl) return;
    var post = el.closest('.ipv-post');
    var expanded = textEl.dataset.expandedNow === '1';
    if(!expanded){
        textEl.dataset.expandedNow = '1';
        textEl.innerHTML = ipvLinkify(textEl.dataset.full) +
            ' <span class="ipv-more-toggle" onclick="event.stopPropagation();ipvToggleCenterCaption(this)"> less</span>';
    } else {
        textEl.dataset.expandedNow = '0';
        var words = (textEl.dataset.full||'').trim().split(/\s+/).filter(Boolean);
        textEl.innerHTML = ipvLinkify(words.slice(0,40).join(' ')) +
            '<span class="ipv-more-wrap" onclick="event.stopPropagation();ipvToggleCenterCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>';
    }
    _ipvSyncCapState(post);
};

/* ============================================================
   OPEN / CLOSE THE IMMERSIVE VIEWER
   ============================================================ */
function _ipvGetProfileOwnerInfo(){
    var isOwner = false;
    try {
        if (typeof currentUserId !== 'undefined' && typeof profileOwnerId !== 'undefined') {
            isOwner = (parseInt(currentUserId, 10) === parseInt(profileOwnerId, 10));
        }
    } catch(e){}

    var username = '';
    var el = document.getElementById('profileMainUsername');
    if (el) {
        username = (el.textContent || '').replace(/\s+/g, ' ').trim();
    }
    if (!username) {
        var tb = document.querySelector('.dz-pt-username-text');
        if (tb) username = (tb.textContent || '').trim();
    }
    if (!username && document.title) {
        username = document.title.split('|')[0].trim();
    }

    return { isOwner: isOwner, username: username };
}

function _ipvComputeTitleParts(arr, focusMemeId){
    var post = focusMemeId ? arr.find(function(x){ return String(x.id)===String(focusMemeId); }) : null;
    if(!post) post = arr[0];
    if(!post) return { isOwner:true, text:'Posts' };

    var labelMap = {
        'Reposted':    'Reposts',
        'Saved':       'Saves',
        'Tagged':      'Tagged Posts',
        'Liked':       'Likes',
        'Uploads':     'Posts',
        'Collections': 'Collection'
    };
    var noun = labelMap[post.context] || 'Posts';

    /* the top bar always names the PROFILE being viewed (never the post's author) */
    var owner = _ipvGetProfileOwnerInfo();
    if(owner.isOwner) return { isOwner:true, text:'Your ' + noun };
    return { isOwner:false, username: ipvEsc(owner.username||'User'), noun: noun };
}

function _ipvRenderTopbarTitle(arr, focusMemeId){
    var titleEl = document.getElementById('ipvTopbarTitle');
    if(!titleEl) return;
    var parts = _ipvComputeTitleParts(arr, focusMemeId);
    if(parts.isOwner){
        titleEl.textContent = parts.text;
    } else {
        titleEl.innerHTML =
            '<span class="ipv-topbar-title-two-line">'+
                '<span class="ipv-topbar-username-sm">'+parts.username+'</span>'+
                '<span class="ipv-topbar-sep">·</span>'+
                '<span class="ipv-topbar-noun">'+parts.noun+'</span>'+
            '</span>';
    }
}

function _ipvComputeTopbarTrack(arr, focusMemeId){
    var post = focusMemeId ? arr.find(function(x){ return String(x.id)===String(focusMemeId); }) : null;
    if(!post) post = arr[0];
    if(!post) return { show:false };

    /* Track / Untrack belongs to the profile owner, and never shows on my own profile */
    if(_ipvGetProfileOwnerInfo().isOwner) return { show:false };
    return { show:true, userId:profileOwnerId, isFollowing:window.dzFollowing(profileOwnerId, profileOwnerFollowing) };
}

function _ipvRenderTopbarAction(arr, focusMemeId){
    var actionEl = document.getElementById('ipvTopbarAction');
    if(!actionEl) return;
    var info = _ipvComputeTopbarTrack(arr, focusMemeId);
    if(info.show){
        actionEl.innerHTML = '<button class="ipv-track-btn ipv-topbar-track-btn'+(info.isFollowing?' ipv-untrack':'')+'" data-user="'+info.userId+'" onclick="event.stopPropagation();ipvToggleTrack(this)">'+(info.isFollowing?'Untrack':'Track')+'</button>';
    } else {
        actionEl.innerHTML = '';
    }
}

function _ipvInitAllCtaPillCycles(root){
    Object.keys(_ipvCtaPillTimers).forEach(function(k){ clearInterval(_ipvCtaPillTimers[k]); });
    _ipvCtaPillTimers = {};

    root.querySelectorAll('.ipv-cta-pill[data-meme-id]').forEach(function(pill){
        var memeId = pill.dataset.memeId;
        pill.classList.remove('ipv-cta-pill-expanded');
        var expanded = false;
        _ipvCtaPillTimers[memeId] = setInterval(function(){
            expanded = !expanded;
            pill.classList.toggle('ipv-cta-pill-expanded', expanded);
        }, 3000);
    });
}

/* ============================================================
   BATCHED RENDERING — groups of 4, next 4 load as the sentinel
   scrolls into view.
   ============================================================ */
function _ipvEnsureSentinel(){
    var listEl = document.getElementById('ipvList');
    var sentinel = document.getElementById('ipvSentinel');
    if(!sentinel){
        sentinel = document.createElement('div');
        sentinel.id = 'ipvSentinel';
        sentinel.className = 'ipv-load-more';
        sentinel.innerHTML = '<div class="ipv-load-more-dot"></div><div class="ipv-load-more-dot"></div><div class="ipv-load-more-dot"></div>';
        listEl.appendChild(sentinel);
    }
    if(_ipvSentinelObserver) _ipvSentinelObserver.disconnect();
    if(!('IntersectionObserver' in window)) return;
    _ipvSentinelObserver = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
            if(entry.isIntersecting && !_ipvLoadingBatch && _ipvRenderedCount < _ipvFeedArray.length){
                _ipvLoadingBatch = true;
                setTimeout(function(){
                    _ipvRenderBatch(_ipvFeedName, _ipvBatchSize, true);
                    _ipvLoadingBatch = false;
                }, 110);
            }
        });
    }, { rootMargin: '600px 0px 600px 0px', root: document.getElementById('ipvOverlay') });
    _ipvSentinelObserver.observe(sentinel);
}

function _ipvRenderBatch(feedName, count, markEnter){
    var listEl = document.getElementById('ipvList');
    var end = Math.min(_ipvRenderedCount + count, _ipvFeedArray.length);
    var html = '';
    for(var i=_ipvRenderedCount; i<end; i++){
        html += ipvRenderPost(_ipvFeedArray[i], feedName);
    }

    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var frag = document.createDocumentFragment();
    var newPosts = Array.prototype.slice.call(tmp.children);
    newPosts.forEach(function(node){
        if(markEnter) node.classList.add('ipv-post-enter');
        frag.appendChild(node);
    });

    var sentinel = document.getElementById('ipvSentinel');
    if(sentinel) listEl.insertBefore(frag, sentinel);
    else listEl.appendChild(frag);

    _ipvRenderedCount = end;

    _ipvBindCarousels(listEl);
    _ipvInitAllSwitchers(listEl);
    _ipvInitAllCtaPillCycles(listEl);
    _ipvInitObserver();
    _ipvLoadCommentCounts(listEl);

    if(_ipvRenderedCount >= _ipvFeedArray.length){
        if(sentinel) sentinel.remove();
        if(_ipvSentinelObserver){ _ipvSentinelObserver.disconnect(); _ipvSentinelObserver = null; }
    } else {
        _ipvEnsureSentinel();
    }
}

/* Profile grid filter (video / text / search): the reel modals only show the posts the grid still shows. */
window._dzFilterFeed = function(arr){
    if(!Array.isArray(arr) || !arr.length) return arr;
    var grids = document.querySelectorAll('.dz-searching');
    if(!grids.length) return arr;
    var hidden = {};
    Array.prototype.forEach.call(grids, function(g){
        Array.prototype.forEach.call(g.querySelectorAll('.meme-card.dz-sr-hide'), function(c){ hidden[c.getAttribute('data-id')] = 1; });
    });
    var out = arr.filter(function(x){ return !hidden[String(x.id)]; });
    return out.length ? out : arr;
};


/* ============================================================
   APP-STYLE "HERO" OPEN / CLOSE
   • open  → the viewer grows out of the grid card you tapped (that card's spot stays empty while it's open)
   • close → back button, Esc or swipe-right: the viewer shrinks into the grid card of the post you are
             CURRENTLY looking at (even after scrolling to another post) and the card pops back in.
   No card on screen (or reduced motion) → it just closes the way it always did.
   ============================================================ */
var _ipvHeroCard = null, _ipvHeroBusy = false;
var _ipvHeroReduce = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
function _ipvHeroFindCard(id){
    if(!id) return null;
    var ov = document.getElementById('ipvOverlay'), best = null;
    var list = document.querySelectorAll('.meme-card[data-id="'+id+'"], .gp-tile[data-id="'+id+'"]');
    for(var i=0;i<list.length;i++){
        var el = list[i];
        if(ov && ov.contains(el)) continue;
        var r = el.getBoundingClientRect();
        if(r.width < 20 || r.height < 20) continue;
        if(getComputedStyle(el).visibility === 'hidden' && el !== _ipvHeroCard) continue;
        var vis = (r.bottom > 0 && r.top < innerHeight) ? 1 : 0;
        if(!best || vis > best.v) best = { el: el, v: vis };
    }
    return best ? best.el : null;
}
function _ipvHeroGeom(from, to){
    var sc = Math.max(to.width / from.width, to.height / from.height);
    return { tx: (to.left + to.width/2) - (from.left + from.width/2), ty: (to.top + to.height/2) - (from.top + from.height/2), s: sc,
             ix: Math.max(0, (from.width - to.width / sc) / 2), iy: Math.max(0, (from.height - to.height / sc) / 2), r: 10 / sc };
}
function _ipvHeroHide(el){ if(_ipvHeroCard && _ipvHeroCard !== el) _ipvHeroShow(_ipvHeroCard, false); if(el){ el.style.visibility = 'hidden'; _ipvHeroCard = el; } }
function _ipvHeroShow(el, pop){
    if(!el) return;
    el.style.visibility = '';
    if(_ipvHeroCard === el) _ipvHeroCard = null;
    if(pop && el.animate && !_ipvHeroReduce) el.animate([{ transform:'scale(.93)' }, { transform:'scale(1.035)', offset:.6 }, { transform:'scale(1)' }], { duration:320, easing:'cubic-bezier(.2,.8,.3,1)' });
}
function _ipvHeroCurrentId(){
    var bestId = null, bestRatio = 0;
    Object.keys(_ipvVisibility || {}).forEach(function(id){ if(_ipvVisibility[id] > bestRatio){ bestRatio = _ipvVisibility[id]; bestId = id; } });
    if(bestId && bestRatio > 0.05 && document.querySelector('#ipvList .ipv-post[data-id="'+bestId+'"]')) return bestId;
    var posts = document.querySelectorAll('#ipvList .ipv-post'), pick = null, pd = 1e9;
    for(var i=0;i<posts.length;i++){ var r = posts[i].getBoundingClientRect(); if(r.bottom <= 0 || r.top >= innerHeight) continue; var d = Math.abs(r.top); if(d < pd){ pd = d; pick = posts[i]; } }
    return pick ? pick.dataset.id : null;
}
function _ipvHeroOpen(memeId){
    var ov = document.getElementById('ipvOverlay');
    if(!ov || _ipvHeroReduce || !memeId || !ov.animate) return;
    var card = _ipvHeroFindCard(memeId); if(!card) return;
    var cr = _ipvHeroBox(card).getBoundingClientRect();
    if(cr.bottom < 0 || cr.top > innerHeight) return;
    ov.style.transition = 'none';
    var sr = ov.getBoundingClientRect(); if(!sr.width || !sr.height){ ov.style.transition = ''; return; }
    var g = _ipvHeroGeom(sr, cr);
    _ipvHeroHide(card); _ipvHeroBusy = true;
    ov.style.willChange = 'transform, clip-path';
    var a = ov.animate([
        { transform:'translate('+g.tx+'px,'+g.ty+'px) scale('+g.s+')', clipPath:'inset('+g.iy+'px '+g.ix+'px round '+g.r+'px)' },
        { transform:'translate(0,0) scale(1)', clipPath:'inset(0px 0px round 0px)' }
    ], { duration: 380, easing: 'cubic-bezier(.22,.9,.28,1)' });
    var done = function(){ _ipvHeroBusy = false; ov.style.willChange = ''; ov.style.transition = ''; };
    a.onfinish = done; a.oncancel = done;
}
/* returns true when a hero close was started; `from` = the transform the swipe left the viewer in, `after` runs once it has landed */
function _ipvHeroThumb(card){
    /* what the grid card actually shows (its picture) — that is what flies back, so what lands is exactly the card */
    var box = card.querySelector('.media-box') || card;
    var img = box.querySelector('img');
    var src = img && (img.currentSrc || img.src);
    if(!src){
        var v = box.querySelector('video');
        if(v){
            if(v.poster) src = v.poster;
            else { try{ if(v.readyState >= 2 && v.videoWidth){ var cv = document.createElement('canvas'); cv.width = Math.min(480, v.videoWidth); cv.height = Math.round(cv.width * v.videoHeight / v.videoWidth); cv.getContext('2d').drawImage(v, 0, 0, cv.width, cv.height); src = cv.toDataURL('image/jpeg', .8); } }catch(e){} }
        }
    }
    return src || '';
}
/* the picture area of a grid card (the card also has a top bar with the uploader's avatar + name — that part isn't flown) */
function _ipvHeroBox(card){ return card.querySelector('.media-box') || card; }
function _ipvHeroClose(from, after){
    var ov = document.getElementById('ipvOverlay');
    if(!ov || _ipvHeroBusy || _ipvHeroReduce || !ov.animate || !ov.classList.contains('ipv-open')) return false;
    var id = _ipvHeroCurrentId();
    var card = _ipvHeroFindCard(id);
    if(!card) return false;
    if(_ipvHeroCard && _ipvHeroCard !== card) _ipvHeroShow(_ipvHeroCard, false);
    var r0 = card.getBoundingClientRect();
    if(r0.bottom < 0 || r0.top > innerHeight){ card.scrollIntoView({ block:'center', behavior:'instant' }); }
    var cr = _ipvHeroBox(card).getBoundingClientRect();

    /* where the post's picture is on screen right now (including any swipe-drag transform) */
    var post = document.querySelector('#ipvList .ipv-post[data-id="'+id+'"]');
    var mediaEl = post && (post.querySelector('.ipv-media-wrap') || post);
    var ovr = ov.getBoundingClientRect();
    var mr = mediaEl ? mediaEl.getBoundingClientRect() : null;
    var src = _ipvHeroThumb(card);
    var L = 0, T = 0, W = 0, H = 0;
    if(mr){
        L = Math.max(mr.left, ovr.left, 0); T = Math.max(mr.top, ovr.top, 0);
        W = Math.min(mr.right, ovr.right, innerWidth) - L; H = Math.min(mr.bottom, ovr.bottom, innerHeight) - T;
    }
    var canFly = !!(mr && W > 40 && H > 40);
    _ipvHeroHide(card);
    _ipvHeroBusy = true;
    var rad = parseInt(ov.style.borderRadius, 10) || 0;
    var anim, flyer = null, landed = false, dur = 340;

    function finish(){
        if(landed) return; landed = true;
        _ipvCloseReal();                                   /* the viewer's own close: hides it, stops media, etc. */
        requestAnimationFrame(function(){
            ov.getAnimations().forEach(function(a){ a.cancel(); });
            ov.style.transition = ''; ov.style.willChange = ''; ov.style.pointerEvents = '';
            _ipvHeroBusy = false;
            _ipvHeroShow(card, false);
            if(flyer && flyer.parentNode) flyer.parentNode.removeChild(flyer);
            if(typeof after === 'function') after();
        });
    }

    if(canFly){
        /* the card's own picture flies from where the post's media was to where the card is; the viewer fades out behind it */
        flyer = document.createElement('div');
        flyer.style.cssText = 'position:fixed;z-index:999999;pointer-events:none;overflow:hidden;left:'+L+'px;top:'+T+'px;width:'+W+'px;height:'+H+'px;border-radius:'+rad+'px;'
            + 'background:' + (getComputedStyle(_ipvHeroBox(card)).backgroundColor || '#111') + ';will-change:left,top,width,height';
        if(src){ var im = document.createElement('img'); im.src = src; im.alt = ''; im.draggable = false; im.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block'; flyer.appendChild(im); }
        document.body.appendChild(flyer);
        ov.style.pointerEvents = 'none';
        ov.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 230, easing: 'ease-out', fill: 'forwards' });
        flyer.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 130, easing: 'ease-out', fill: 'forwards' });
        anim = flyer.animate([
            { left:L+'px', top:T+'px', width:W+'px', height:H+'px', borderRadius:rad+'px' },
            { left:cr.left+'px', top:cr.top+'px', width:cr.width+'px', height:cr.height+'px', borderRadius:'6px' }
        ], { duration: dur, easing: 'cubic-bezier(.22,.9,.28,1)', fill:'forwards' });
    } else {
        /* no picture to fly (text post / off-screen): shrink the viewer into the card's spot */
        ov.style.transform = ''; ov.style.boxShadow = ''; ov.style.borderRadius = ''; ov.style.transition = 'none';
        var sr = ov.getBoundingClientRect();
        var g = _ipvHeroGeom(sr, cr);
        anim = ov.animate([
            { transform: from || 'translate(0,0) scale(1)', clipPath:'inset(0px 0px round '+rad+'px)' },
            { transform:'translate('+g.tx+'px,'+g.ty+'px) scale('+g.s+')', clipPath:'inset('+g.iy+'px '+g.ix+'px round '+g.r+'px)' }
        ], { duration: dur, easing: 'cubic-bezier(.22,.9,.28,1)', fill:'forwards' });
    }
    anim.onfinish = finish; anim.oncancel = finish;
    setTimeout(finish, 900);
    return true;
}
window._ipvHeroClose = _ipvHeroClose;

window.ipvOpen = function(feedName, focusMemeId){
    var arr = window._dzFilterFeed(window[feedName]);
    if(!Array.isArray(arr) || !arr.length) return;

    _ipvFeedName      = feedName;
    _ipvFeedArray     = arr;
    _ipvActive        = true;
    _ipvRenderedCount = 0;
    _ipvLoadingBatch  = false;

    _ipvRenderTopbarTitle(arr, focusMemeId);
    _ipvRenderTopbarAction(arr, focusMemeId);

    var listEl = document.getElementById('ipvList');

    document.getElementById('ipvOverlay').classList.add('ipv-open');
    document.body.style.overflow = 'hidden';
    try{ _ipvHeroOpen(focusMemeId); }catch(e){}

    var focusIdx = focusMemeId ? arr.findIndex(function(x){ return String(x.id)===String(focusMemeId); }) : 0;
    if(focusIdx < 0) focusIdx = 0;
    var initialCount = Math.max(4, Math.ceil((focusIdx+1)/4)*4);
    initialCount = Math.min(initialCount, arr.length);

    function ipvDoInitialRender(){
        listEl.innerHTML = '';
        _ipvRenderBatch(feedName, initialCount, true);

        requestAnimationFrame(function(){
            var target = focusMemeId ? listEl.querySelector('.ipv-post[data-id="'+focusMemeId+'"]') : null;
            if(target) target.scrollIntoView({ block:'start' });
        });

        _ipvUpdateTrackMuteIcons();
    }

    if(!_ipvSkeletonShownOnce){
        listEl.innerHTML = _ipvSkeletonHTML(4);
        if(_ipvSkeletonTimer) clearTimeout(_ipvSkeletonTimer);
        _ipvSkeletonTimer = setTimeout(function(){
            _ipvSkeletonShownOnce = true;
            ipvDoInitialRender();
        }, 520);
    } else {
        ipvDoInitialRender();
    }
};

window._ipvCloseReal = function(){
    dzHp('light');
    _ipvActive = false;
    document.getElementById('ipvOverlay').classList.remove('ipv-open');
    document.body.style.overflow = '';
    document.querySelectorAll('#ipvList video').forEach(function(v){ v.pause(); });
    if(_ipvObserver){ _ipvObserver.disconnect(); _ipvObserver = null; }
    if(_ipvSentinelObserver){ _ipvSentinelObserver.disconnect(); _ipvSentinelObserver = null; }
    if(_ipvSkeletonTimer){ clearTimeout(_ipvSkeletonTimer); _ipvSkeletonTimer = null; }
    _ipvLoadingBatch = false;
    Object.keys(_ipvSwitchers).forEach(function(k){ clearInterval(_ipvSwitchers[k]); });
    _ipvSwitchers = {};
    Object.keys(_ipvCtaPillTimers).forEach(function(k){ clearInterval(_ipvCtaPillTimers[k]); });
    _ipvCtaPillTimers = {};
    _ipvVisibility = {};
    _ipvStopAudio();
    ipvCloseLikersSheet();
    if(!_ipvHeroBusy && _ipvHeroCard) _ipvHeroShow(_ipvHeroCard, false);    /* closed some other way: never leave an empty spot */
};
/* every way of closing (back button, Esc, swipe) goes through the hero first */
window.ipvClose = function(){
    var likers = document.getElementById('ipvLikersOverlay');
    if(likers && likers.classList.contains('ipv-open')){ ipvCloseLikersSheet(); return; }
    if(!_ipvHeroClose(null, null)) _ipvCloseReal();
};

/* ============================================================
   opening the reel modal from a post, and returning to the exact
   post + slide + playback position when it closes.
   ============================================================ */
window.ipvOpenReelFromPost = function(feedName, memeId){
    var startIdx = 0, startTime = 0, startAudioTime = 0, posterDataUrl = null;
    var postEl = document.querySelector('#ipvList .ipv-post[data-id="'+memeId+'"]');
    if(postEl){
        var wrap = postEl.querySelector('.ipv-media-wrap[data-count]');
        if(wrap){
            startIdx = parseInt(wrap.dataset.activeIdx || '0', 10) || 0;
            var activeVideo = wrap.querySelector('.ipv-media-item[data-idx="'+startIdx+'"] video');
            if(activeVideo && !isNaN(activeVideo.currentTime)) startTime = activeVideo.currentTime || 0;

            if(activeVideo && activeVideo.readyState >= 2 && activeVideo.videoWidth){
                try{
                    var cvs = document.createElement('canvas');
                    cvs.width  = activeVideo.videoWidth;
                    cvs.height = activeVideo.videoHeight;
                    cvs.getContext('2d').drawImage(activeVideo, 0, 0, cvs.width, cvs.height);
                    posterDataUrl = cvs.toDataURL('image/jpeg', 0.72);
                }catch(e){ posterDataUrl = null; }
            }
        }

        var audioEl = document.getElementById('ipvAudioPlayer');
        if(audioEl && postEl.dataset.audio &&
           audioEl.getAttribute('src') === postEl.dataset.audio &&
           String(_ipvCurrentAudioPostId) === String(memeId) &&
           !isNaN(audioEl.currentTime)){
            startAudioTime = audioEl.currentTime || 0;
        }
    }

    _ipvReelOriginMemeId = memeId;
    _ipvReelOriginFeed   = feedName;

    _ipvPauseAllVideos();
    _ipvStopAudio();
    var arr = window[feedName] || [];
    var p = arr.find(function(x){ return String(x.id) === String(memeId); });
    if(!p) return;
    var mainType = (p.type === 'text') ? 'image' : p.type;
    window._ccmOpenReel(p.file, mainType, p.caption, p.pic, p.username, p.userId, feedName, p.id, startIdx, startTime, startAudioTime, posterDataUrl);
};

function _ipvApplyPlaybackToPost(playback){
    var post = document.querySelector('#ipvList .ipv-post[data-id="'+playback.memeId+'"]');
    if(!post) return;

    var wrap = post.querySelector('.ipv-media-wrap[data-count]');
    if(wrap){
        var count = parseInt(wrap.dataset.count || '1', 10) || 1;
        var curIdx = parseInt(wrap.dataset.activeIdx || '0', 10) || 0;
        var targetIdx = Math.max(0, Math.min(playback.mediaIndex || 0, count - 1));

        if(targetIdx !== curIdx && typeof wrap._ipvGoTo === 'function'){
            wrap._ipvGoTo(targetIdx - curIdx);
        }

        if(playback.currentTime > 0){
            var video = wrap.querySelector('.ipv-media-item[data-idx="'+targetIdx+'"] video');
            if(video){
                var setTime = function(){
                    try{ video.currentTime = playback.currentTime; }catch(e){}
                };
                if(video.readyState >= 1){ setTime(); }
                else { video.addEventListener('loadedmetadata', setTime, { once:true }); }
            }
        }
    }

    if(playback.audioTime > 0 && post.dataset.audio){
        var audioEl = document.getElementById('ipvAudioPlayer');
        if(audioEl){
            if(audioEl.getAttribute('src') === post.dataset.audio){
                try{ audioEl.currentTime = playback.audioTime; }catch(e){}
            } else {
                post.dataset.pendingAudioTime = String(playback.audioTime);
            }
        }
    }
}

/* the video of a post that is ON SCREEN (a gallery has several; never the first one by default) */
function _ipvActiveVideo(post){
    if(!post) return null;
    var wrap = post.querySelector('.ipv-media-wrap[data-count]');
    if(wrap){
        var idx = parseInt(wrap.dataset.activeIdx || '0', 10) || 0;
        return wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"] video');
    }
    return post.querySelector('.ipv-video');
}
/* ONE sound at a time: only the video on screen can be heard, and for a post without music it follows the person's unmute choice
   (post.dataset.vsound = '1' after they tapped unmute), so it carries on from video to video without tapping again */
function _ipvApplyVideoSound(post){
    if(!post) return;
    var act = _ipvActiveVideo(post);
    var on  = post.dataset.vsound === '1';
    post.querySelectorAll('video.ipv-video').forEach(function(v){
        if(v !== act){ if(!v.paused) v.pause(); v.muted = true; }
    });
    if(act && !post.dataset.audio) act.muted = !on;
    var b = post.querySelector('.ipv-video-mute-btn i');
    if(b && !post.dataset.audio) b.className = on ? 'dz-snd on' : 'dz-snd off';
}

function _ipvPauseAllVideos(){ document.querySelectorAll('#ipvList video').forEach(function(v){ v.pause(); }); }
function _ipvResumeFocusedVideo(){
    var posts = document.querySelectorAll('#ipvList .ipv-post');
    var mid = window.innerHeight / 2;
    posts.forEach(function(post){
        var r = post.getBoundingClientRect();
        if(r.top < mid && r.bottom > mid){
            var v = _ipvActiveVideo(post);
            _ipvApplyVideoSound(post);
            if(v) v.play().catch(function(){});
        }
    });
    _ipvSyncAudioFocus();
}

/* ============================================================
   AUTOPLAY-ON-SCROLL + ATTACHED-TRACK SYNC
   ============================================================ */
function _ipvInitObserver(){
    if(_ipvObserver) _ipvObserver.disconnect();
    if(!('IntersectionObserver' in window)) return;
    _ipvObserver = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
            var post = entry.target;
            var id = post.dataset.id;
            _ipvVisibility[id] = entry.intersectionRatio;
            var v = _ipvActiveVideo(post);
            if(entry.isIntersecting && entry.intersectionRatio > 0.6){
                _ipvApplyVideoSound(post);
                if(v) v.play().catch(function(){});
            } else {
                post.querySelectorAll('video.ipv-video').forEach(function(x){ x.pause(); });
            }
        });
        _ipvSyncAudioFocus();
    }, { threshold:[0,.6,1] });
    document.querySelectorAll('#ipvList .ipv-post').forEach(function(post){ _ipvObserver.observe(post); });
}

function _ipvSyncAudioFocus(){
    var bestId = null, bestRatio = 0;
    Object.keys(_ipvVisibility).forEach(function(id){
        if(_ipvVisibility[id] > bestRatio){ bestRatio = _ipvVisibility[id]; bestId = id; }
    });
    var audioEl = document.getElementById('ipvAudioPlayer');
    if(bestRatio < 0.6 || !bestId){ _ipvStopAudio(); return; }
    if(bestId === _ipvCurrentAudioPostId) return;

    var postEl = document.querySelector('.ipv-post[data-id="'+bestId+'"]');
    if(!postEl) return;
    var audioUrl = postEl.dataset.audio;

    if(!audioUrl){
        if(audioEl){ audioEl.pause(); }
        _ipvCurrentAudioPostId = bestId;
        return;
    }

    _ipvCurrentAudioPostId = bestId;
    if(audioEl.getAttribute('src') !== audioUrl){
        audioEl.src = audioUrl;
        var pending = parseFloat(postEl.dataset.pendingAudioTime || '0');
        audioEl.currentTime = pending > 0 ? pending : 0;
        delete postEl.dataset.pendingAudioTime;
    }
    audioEl.muted = _ipvTrackMuted;
    audioEl.volume = (parseInt(postEl.dataset.musicVol,10) || 80) / 100;
    audioEl.play().catch(function(){});

    var vidVol = (parseInt(postEl.dataset.videoVol,10) || 40) / 100;
    postEl.querySelectorAll('video.ipv-video').forEach(function(v){ v.volume = vidVol; v.muted = _ipvTrackMuted; });   /* video + music: both follow the one button */

    _ipvUpdateTrackMuteIcons();
}

function _ipvStopAudio(){
    var audioEl = document.getElementById('ipvAudioPlayer');
    if(audioEl){ audioEl.pause(); }
    _ipvCurrentAudioPostId = null;
}

/* ============================================================
   MEDIA ASPECT RATIO
   ============================================================ */
var IPV_MULTI_BOX_RATIO     = 3.5 / 4;
var IPV_MULTI_FIT_TOLERANCE = 0.15;

window.ipvMeasureAspect = function(el){
    var wrap = el.closest('.ipv-media-wrap');
    if(!wrap) return;

    var w = el.tagName === 'VIDEO' ? el.videoWidth  : el.naturalWidth;
    var h = el.tagName === 'VIDEO' ? el.videoHeight : el.naturalHeight;
    if(!w || !h) return;
    var ratio = h / w;
    el.dataset.ratio = ratio;

    if(wrap.classList.contains('ipv-multi')){
        var diff = Math.abs(ratio - IPV_MULTI_BOX_RATIO) / IPV_MULTI_BOX_RATIO;
        el.classList.toggle('ipv-fit-cover', diff <= IPV_MULTI_FIT_TOLERANCE);
        return;
    }

    var idx = parseInt(el.dataset.idx || '0', 10);
    var activeIdx = parseInt(wrap.dataset.activeIdx || '0', 10);
    if(idx === activeIdx) _ipvApplyAspect(wrap, ratio);
};

var IPV_LONG_MEDIA_RATIO = 1.2;

function _ipvApplyAspect(wrap, ratio){
    var clamped = Math.max(0.55, Math.min(ratio, 1.6));
    var width = wrap.clientWidth || wrap.offsetWidth;
    if(!width) return;
    wrap.style.aspectRatio = 'auto';
    wrap.style.height = Math.round(width * clamped) + 'px';

    var post = wrap.closest('.ipv-post');
    if(post) post.classList.toggle('ipv-long-header', clamped >= IPV_LONG_MEDIA_RATIO);
}

/* ============================================================
   NICKNAME / ATTACHED-AUDIO CROSS-FADE SWITCHER
   ============================================================ */
function _ipvInitAllSwitchers(root){
    root.querySelectorAll('.ipv-post').forEach(function(post){
        var switcher = post.querySelector('[data-switcher="1"]');
        if(!switcher) return;
        var postId = post.dataset.id;
        var panes = switcher.querySelectorAll('.ipv-switch-pane');
        if(panes.length < 2) return;
        var activeIdx = 0;
        panes.forEach(function(p,i){ p.classList.toggle('active', i===0); });
        if(_ipvSwitchers[postId]) clearInterval(_ipvSwitchers[postId]);
        _ipvSwitchers[postId] = setInterval(function(){
            panes[activeIdx].classList.remove('active');
            activeIdx = (activeIdx+1) % panes.length;
            panes[activeIdx].classList.add('active');
        }, 5000);
    });
}

/* ============================================================
   PER-POST MEDIA CAROUSEL (swipe + arrows) — ANTI-SWAY

   A thumb that is scrolling the feed always wiggles sideways a
   little. So now:
     1. we wait for a 12px "dead zone" before deciding anything
     2. then LOCK the gesture:
          - clearly sideways (horizontal > 2x vertical) -> slides follow the finger
          - anything else -> it is a page scroll, the slides never move
     3. a swipe must travel ~20% of the width (max 90px) to change
        slide, otherwise it snaps back
     4. rubber-band resistance at the first / last slide
   A dataset guard means a wrap already bound in an earlier batch
   is never re-bound when a later batch runs over the whole list.
   ============================================================ */
function _ipvBindCarousels(root){
    root.querySelectorAll('.ipv-media-wrap[data-count]').forEach(function(wrap){
        if(wrap.dataset.ipvBound) return;
        var count = parseInt(wrap.dataset.count, 10);
        if(count <= 1) return;
        wrap.dataset.ipvBound = '1';

        var post  = wrap.closest('.ipv-post');
        var track = wrap.querySelector('.ipv-media-track');
        var dots  = wrap.querySelectorAll('.ipv-dot');
        var counterEl = wrap.querySelector('.ipv-media-counter');
        var idx = 0, startX = 0, startY = 0, dx = 0, dragging = false, lock = null;
        var DEAD = 12;

        function goTo(newIdx){
            newIdx = Math.max(0, Math.min(newIdx, count-1));
            idx = newIdx;
            wrap.dataset.activeIdx = String(idx);
            track.style.transform = 'translateX(-'+(idx*100)+'%)';
            dots.forEach(function(d,i){ d.classList.toggle('active', i===idx); });
            if(counterEl) counterEl.textContent = (idx+1)+'/'+count;

            /* only the visible slide's video plays */
            wrap.querySelectorAll('.ipv-media-item video').forEach(function(v){
                var vi = parseInt(v.dataset.idx, 10);
                if(vi !== idx) v.pause();
            });
            _ipvApplyVideoSound(post);          /* muted state of the new slide follows the unmute choice; every other video stays silent */
            wrap.querySelectorAll('.ipv-media-item video').forEach(function(v){
                if(parseInt(v.dataset.idx, 10) === idx && post && (_ipvVisibility[post.dataset.id] || 0) > 0.6) v.play().catch(function(){});
            });

            var videoMuteBtn = wrap.querySelector('.ipv-video-mute-btn');
            if(videoMuteBtn){
                var activeHasVideo = !!wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"] video');
                videoMuteBtn.style.display = (activeHasVideo || (post && post.dataset.audio)) ? 'flex' : 'none';   /* keep the only sound button on photo slides when the post has music */
            }

            var typeTagEl = wrap.querySelector('.ipv-type-tag');
            if(typeTagEl){
                var activeItem = wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"]');
                var activeType = activeItem ? (activeItem.dataset.type || 'image') : 'image';
                var info = _ipvTypeTagInfo(activeType);
                typeTagEl.innerHTML = '<i class="'+info.icon+'"></i><span>'+info.label+'</span>';
            }
        }
        wrap._ipvGoTo = function(delta){ goTo(idx + delta); };

        wrap.addEventListener('touchstart', function(e){
            if(e.touches.length !== 1){ dragging = false; lock = null; return; } // pinch etc. -> ignore
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            dx = 0; lock = null; dragging = true;
        }, { passive:true });

        wrap.addEventListener('touchmove', function(e){
            if(!dragging) return;
            var rawX = e.touches[0].clientX - startX;
            var rawY = e.touches[0].clientY - startY;

            if(lock === null){
                if(Math.abs(rawX) < DEAD && Math.abs(rawY) < DEAD) return;   // still inside the dead zone
                lock = (Math.abs(rawX) > Math.abs(rawY) * 2) ? 'h' : 'v';    // only a clearly sideways swipe counts
                if(lock === 'h') track.style.transition = 'none';
            }
            if(lock !== 'h') return;                                          // vertical scroll -> slides stay put

            dx = rawX > 0 ? rawX - DEAD : rawX + DEAD;                        // start from 0 so there is no jump
            var atEdge = (idx === 0 && dx > 0) || (idx === count-1 && dx < 0);
            track.style.transform = 'translateX(calc(-'+(idx*100)+'% + '+(atEdge ? dx*0.25 : dx)+'px))';
        }, { passive:true });

        function endTouch(){
            if(!dragging) return;
            dragging = false;
            track.style.transition = 'transform '+CCM_SLIDE_MS+'ms '+CCM_SLIDE_EASE;
            var next = idx;
            if(lock === 'h'){
                var need = Math.min(90, (wrap.clientWidth || 300) * 0.2);
                if(Math.abs(dx) > need) next = dx < 0 ? idx+1 : idx-1;
            }
            lock = null; dx = 0;
            goTo(next);                                                       // always re-settle (snaps back if too small)
        }
        wrap.addEventListener('touchend', endTouch, { passive:true });
        wrap.addEventListener('touchcancel', endTouch, { passive:true });
    });
}

window.ipvArrowNav = function(btn, dir){
    var wrap = btn.closest('.ipv-media-wrap');
    if(wrap && wrap._ipvGoTo) wrap._ipvGoTo(dir);
};

/* ============================================================
   DOUBLE-TAP TO LIKE (media area)
   ============================================================ */
window.ipvHandleMediaTap = function(e, el, feedName, memeId){
    var mediaBox = el.closest('.ipv-media-wrap') || el;
    var now = Date.now();
    var lastTap = mediaBox._ipvLastTap || 0;
    var dt = now - lastTap;

    if(dt > 0 && dt < 320){
        mediaBox._ipvLastTap = 0;
        if(mediaBox._ipvTapTimer){ clearTimeout(mediaBox._ipvTapTimer); mediaBox._ipvTapTimer = null; }
        ipvDoubleTapLike(mediaBox, e, memeId, feedName);
    } else {
        mediaBox._ipvLastTap = now;
        mediaBox._ipvTapTimer = setTimeout(function(){
            ipvOpenReelFromPost(feedName, memeId);
        }, 260);
    }
};

function ipvDoubleTapLike(mediaBox, e, memeId, feedName){
    dzHp('medium');
    var rect = mediaBox.getBoundingClientRect();
    var cx = (e && typeof e.clientX === 'number' && e.clientX !== 0) ? e.clientX : (rect.left + rect.width/2);
    var cy = (e && typeof e.clientY === 'number' && e.clientY !== 0) ? e.clientY : (rect.top + rect.height/2);
    var post = mediaBox.closest('.ipv-post');
    var likeBtn = post ? post.querySelector('.ipv-like-btn') : null;
    var done = false;
    var go = function(){
        if(done) return; done = true;
        if(likeBtn && !likeBtn.classList.contains('liked')) ipvAction(memeId, 'like', likeBtn, feedName);
    };
    /* same animation as the home page / Zeems modal: the heart pops where you tapped, jumps to this post's like button, and the like happens when it lands */
    if(typeof ccmShowHeartBurst === 'function'){
        ccmShowHeartBurst(mediaBox, cx - rect.left, cy - rect.top, go, likeBtn ? likeBtn.querySelector('i') : null);
        setTimeout(go, 1800);   /* safety: if the modal file is an older one that doesn't call back, the like still happens */
    } else {
        ipvShowHeartBurst(mediaBox, cx - rect.left, cy - rect.top);
        go();
    }
}

function ipvShowHeartBurst(mediaBox, x, y){
    mediaBox.querySelectorAll('.ipv-double-tap-heart, .ipv-heart-ring, .ipv-heart-particle')
        .forEach(function(el){ el.remove(); });

    var ring = document.createElement('div');
    ring.className = 'ipv-heart-ring';
    ring.style.left = x+'px';
    ring.style.top  = y+'px';
    mediaBox.appendChild(ring);
    requestAnimationFrame(function(){ ring.classList.add('show'); });
    setTimeout(function(){ ring.remove(); }, 800);

    var heart = document.createElement('div');
    heart.className = 'ipv-double-tap-heart';
    heart.style.left = x+'px';
    heart.style.top  = y+'px';
    heart.innerHTML = '<i class="ri-heart-3-fill"></i>';
    mediaBox.appendChild(heart);
    requestAnimationFrame(function(){ heart.classList.add('show'); });
    setTimeout(function(){ heart.remove(); }, 950);

    var particleCount = 8;
    for(var i = 0; i < particleCount; i++){
        (function(i){
            var angle    = (360 / particleCount) * i + (Math.random()*20 - 10);
            var rad      = angle * Math.PI / 180;
            var distance = 60 + Math.random()*42;
            var tx       = Math.cos(rad) * distance;
            var ty       = Math.sin(rad) * distance;
            var rot      = (Math.random()*80 - 40);
            var size     = 12 + Math.random()*10;

            var p = document.createElement('div');
            p.className = 'ipv-heart-particle';
            p.style.left = x+'px';
            p.style.top  = y+'px';
            p.style.setProperty('--tx', tx+'px');
            p.style.setProperty('--ty', ty+'px');
            p.style.setProperty('--tr', rot+'deg');
            p.innerHTML = '<i class="ri-heart-3-fill" style="font-size:'+size+'px;"></i>';
            mediaBox.appendChild(p);

            setTimeout(function(){
                requestAnimationFrame(function(){ p.classList.add('show'); });
            }, 40 + i*15);

            setTimeout(function(){ p.remove(); }, 950 + i*15);
        })(i);
    }
}

/* ============================================================
   MUTE — video's own audio, and the attached-track player.
   ============================================================ */
window.ipvToggleVideoMute = function(btn){
    var wrap = btn.closest('.ipv-media-wrap');
    var idx = parseInt(wrap.dataset.activeIdx || '0', 10);
    var video = wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"] video');
    var post = btn.closest('.ipv-post');
    var hasTrack = !!(post && post.dataset.audio), nowMuted;
    if(video){ video.muted = !video.muted; nowMuted = video.muted; }
    else if(hasTrack){ nowMuted = !_ipvTrackMuted; }       /* gallery slide that is a photo */
    else return;
    if(!hasTrack && post){ post.dataset.vsound = nowMuted ? '0' : '1'; _ipvApplyVideoSound(post); }   /* remember it for the next video of this post */
    if(hasTrack){                                           /* video + music: one button, both sounds */
        _ipvTrackMuted = nowMuted;
        var audioEl = document.getElementById('ipvAudioPlayer');
        if(audioEl) audioEl.muted = _ipvTrackMuted;
        wrap.querySelectorAll('video').forEach(function(x){ x.muted = nowMuted; });
        _ipvUpdateTrackMuteIcons();
    }
    btn.querySelector('i').className = nowMuted ? 'dz-snd off' : 'dz-snd on';
};

window.ipvToggleTrackMute = function(btn){
    var audioEl = document.getElementById('ipvAudioPlayer');
    if(!audioEl) return;
    _ipvTrackMuted = !_ipvTrackMuted;
    audioEl.muted = _ipvTrackMuted;
    _ipvUpdateTrackMuteIcons();
};

function _ipvUpdateTrackMuteIcons(){
    document.querySelectorAll('#ipvList .ipv-track-mute-btn, #ipvList .ipv-post[data-audio] .ipv-video-mute-btn').forEach(function(btn){
        var icon = btn.querySelector('i');
        if(icon) icon.className = _ipvTrackMuted ? 'dz-snd off' : 'dz-snd on';
    });
}

/* ============================================================
   LIKE / REPOST / FAVORITE
   ============================================================ */
function ipvStripHtml(p){
    if(!p.isAd && Array.isArray(p.socialProof) && p.socialProof.length){
        var ipvSpClass = 'ipv-social-proof-strip' + '';
        return '<div class="'+ipvSpClass+'">'+
            p.socialProof.slice(0,4).map(function(sp){
                    var isLike = sp.action_type === 'like';
                    var icon   = isLike ? 'ri-heart-3-fill' : 'ri-repeat-line';
                    var color  = isLike ? '#f24455' : '#7a4aff';
                    return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="ipv-sp-avatar-wrap">'+
                        '<img src="'+ipvEsc(sp.profile_pic||'mee.jpg')+'" onerror="this.onerror=null;this.src=\'mee.jpg\'" class="ipv-sp-avatar">'+
                        (isLike ? '<span class="ipv-sp-badge ipv-sp-heart">'+IPV_HEART_SVG+'</span>'
                                : '<span class="ipv-sp-badge ipv-sp-rep" style="background:none;box-shadow:none;border:0;"><svg viewBox="0 0 100 100" aria-hidden="true" style="display:block;width:100%;height:100%;overflow:visible"><circle class="sp-ring" cx="50" cy="50" r="55.75" fill="none" stroke-width="11.5"/><circle cx="50" cy="50" r="50" fill="#7a4aff"/><g transform="translate(18.8 18.8) scale(2.6)" fill="none" stroke="#fff" stroke-width="2.04" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></g></svg></span>')+
                    '</a>';
                }).join('')+
            '</div>';
    }
    return '';
}

/* my repost takes a slot in the strip; un-repost gives the slot back to the one it displaced — instantly, no refresh */
var _ipvSpBak = {};
function _ipvSpItems(memeId){
    var out = [];
    Object.keys(window).forEach(function(k){
        if(!/Feed$/.test(k) && !/^colFeed_/.test(k)) return;
        var arr = window[k]; if(!Array.isArray(arr)) return;
        arr.forEach(function(it){ if(it && String(it.id) === String(memeId) && out.indexOf(it) < 0) out.push(it); });
    });
    return out;
}
window.ipvSpApply = function(it, active){
    var me = window.dzMe || {};
    var meId = String(me.id || (typeof currentUserId !== 'undefined' ? currentUserId : 0));
    var key = function(x){ return x.id + '|' + x.action_type; };
    var strip = (Array.isArray(it.socialProof) ? it.socialProof : []).filter(function(x){ return !(String(x.id) === meId && x.action_type === 'repost'); });
    var spare = (Array.isArray(it.socialSpare) ? it.socialSpare : []).filter(function(x){ return String(x.id) !== meId || x.action_type !== 'repost'; });
    if(active){
        strip.unshift({ id: parseInt(meId,10), username: me.username || '', profile_pic: me.profile_pic || 'mee.jpg', action_type: 'repost', isVerified: !!me.isVerified });
        var cut = strip.splice(4);
        var have = {}; cut.forEach(function(x){ have[key(x)] = 1; });
        spare = cut.concat(spare.filter(function(x){ return !have[key(x)]; }));
    } else {
        var h = {}; strip.forEach(function(x){ h[key(x)] = 1; });
        while(strip.length < 4 && spare.length){ var n = spare.shift(); if(!h[key(n)] && String(n.id) !== meId){ strip.push(n); h[key(n)] = 1; } }
    }
    it.socialProof = strip; it.socialSpare = spare;
};
window.ipvRepaintStrip = function(memeId, it){
    var src = it || (_ipvSpItems(memeId)[0]); if(!src) return;
    document.querySelectorAll('#ipvList .ipv-post[data-id="'+memeId+'"] .ipv-sp-slot').forEach(function(sl){ sl.innerHTML = ipvStripHtml(src); });
};
function ipvSpOptimistic(memeId, active){
    var items = _ipvSpItems(memeId); if(!items.length) return;
    _ipvSpBak[memeId] = items.map(function(it){ return { it: it, p: it.socialProof, s: it.socialSpare, c: it.repostCount }; });
    items.forEach(function(it){ ipvSpApply(it, active); it.repostCount = Math.max(0, (it.repostCount || 0) + (active ? 1 : -1)); });
    ipvRepaintStrip(memeId, items[0]);
}
function ipvSpRestore(memeId){
    var bak = _ipvSpBak[memeId]; if(!bak) return;
    bak.forEach(function(b){ b.it.socialProof = b.p; b.it.socialSpare = b.s; b.it.repostCount = b.c; });
    delete _ipvSpBak[memeId];
    ipvRepaintStrip(memeId, bak[0].it);
}

window.dzHp = window.dzHp || function(k){ try{ if(window.dzHaptic && typeof window.dzHaptic[k]==='function') window.dzHaptic[k](); }catch(e){} };
/* hold on a grid card: buzz when the hold fires; on iPhone (no navigator.vibrate) a call from a timer is silent, so buzz again from the release event, which is a real tap-time event */
window.dzHpHold = function(){ dzHp('longPress'); if(!navigator.vibrate) window._dzHoldPend = Date.now(); };
(function(){
    function rel(){ if(window._dzHoldPend && Date.now() - window._dzHoldPend < 10000){ window._dzHoldPend = 0; dzHp('longPress'); } }
    ['pointerup','touchend'].forEach(function(t){ document.addEventListener(t, rel, true); });
})();
window.ipvAction = function(memeId, type, btn, feedName){
    var lockKey = memeId+':'+type;
    if(_ipvActionLock[lockKey]) return;
    _ipvActionLock[lockKey] = true;

    var wasActive = btn.classList.contains('liked') || btn.classList.contains('faved');
    var next = !wasActive;
    dzHp(type === 'fav' ? 'light' : (next ? 'medium' : 'light'));
    if(type === 'repost') ipvSpOptimistic(memeId, next);
    var group = btn.closest('.ipv-action-group');
    var countEl = group ? group.querySelector('.ipv-action-count') : null;
    var prevCount = countEl ? parseInt((countEl.textContent||'0').replace(/[^\d.]/g,''))||0 : 0;

    if(type === 'like'){ btn.classList.toggle('liked', next); /* icon fill follows the .liked / .faved class (CSS) */ }
    if(type === 'repost'){ btn.classList.toggle('liked', next); }
    if(type === 'fav'){ btn.classList.toggle('faved', next); /* icon fill follows the .liked / .faved class (CSS) */ }
    if(countEl) countEl.textContent = ipvFmtNum(next ? prevCount+1 : Math.max(0,prevCount-1));

    if(type === 'repost'){
        var actionIcon = btn.querySelector('i');
        if(actionIcon){
            btn.classList.remove('arc-swap-active');
            void actionIcon.offsetWidth;
            btn.classList.add('arc-swap-active');
            actionIcon.addEventListener('animationend', function handler(){
                btn.classList.remove('arc-swap-active');
                actionIcon.removeEventListener('animationend', handler);
            });
        }
    }

    if(type === 'fav'){
        if(next && typeof ccmShowSavedToast === 'function') ccmShowSavedToast(memeId);
        else if(!next && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
    }

    fetch('profile.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action='+type+'&meme_id='+memeId
    }).then(function(r){ return r.json(); }).then(function(data){
        delete _ipvActionLock[lockKey];
        if(!data.ok){
            dzHp('error');
            if(type === 'repost') ipvSpRestore(memeId);
            if(type==='like'){ btn.classList.toggle('liked', wasActive); /* icon fill follows the .liked / .faved class (CSS) */ }
            if(type==='fav'){
                btn.classList.toggle('faved', wasActive);
                /* icon fill follows the .liked / .faved class (CSS) */
                if(next && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
            }
            if(countEl) countEl.textContent = ipvFmtNum(prevCount);
            return;
        }
        var stateKey = { like:'liked', repost:'reposted', fav:'faved' }[type];
        var cntField = { like:'likeCount', repost:'repostCount', fav:'favCount' }[type];
        var active = data[stateKey];
        var arr = window[feedName] || [];
        var item = arr.find(function(x){ return String(x.id)===String(memeId); });
        if(item){ item[stateKey] = active; item[cntField] = data.count; }
        if(type === 'repost'){ delete _ipvSpBak[memeId]; if(!!active !== next){ _ipvSpItems(memeId).forEach(function(it){ ipvSpApply(it, !!active); }); } ipvRepaintStrip(memeId, item); }
        if(countEl) countEl.textContent = ipvFmtNum(data.count);
        if(typeof _gSyncAllFeedsForMeme === 'function'){
            _gSyncAllFeedsForMeme(memeId, cntField, data.count, stateKey, active);
        }
        if(type === 'like'){
            var post = btn.closest('.ipv-post');
            var row = post.querySelector('.ipv-social-row .ipv-likes-text');
            if(row && item) row.innerHTML = ipvLikesText(item);
        }
        if(type === 'fav' && active !== next){
            if(active && typeof ccmShowSavedToast === 'function') ccmShowSavedToast(memeId);
            else if(!active && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
        }
    }).catch(function(){
        delete _ipvActionLock[lockKey];
        if(type === 'repost') ipvSpRestore(memeId);
        if(type === 'fav' && next && typeof ccmHideSavedToast === 'function') ccmHideSavedToast();
    });
};

/* ============================================================
   TRACK / UNTRACK
   ============================================================ */
window.ipvToggleTrack = function(btn){
    if(btn.dataset.busy === '1') return;
    var userId = btn.dataset.user;
    var wasFollowing = window.dzFollowing(userId, btn.textContent.trim() === 'Untrack');
    dzHp(wasFollowing ? 'light' : 'medium');
    var isTop = btn.classList.contains('ipv-topbar-track-btn');
    var oldText = btn.textContent;
    /* same spinner the page's own Track buttons show while it works */
    btn.dataset.busy = '1';
    btn.style.minWidth = btn.offsetWidth + 'px';
    btn.style.pointerEvents = 'none';
    var spinT0 = Date.now(), spinHold = isTop ? 600 : 0;   // bouncing bars stay long enough to be seen
    var clr = '';
    try{ clr = getComputedStyle(btn).color; }catch(e){}
    btn.innerHTML = '<span class="spinner-icon" style="color:'+clr+';"><span></span><span></span><span></span><span></span></span>';
    function done(following){
        btn.dataset.busy = '';
        btn.style.pointerEvents = '';
        btn.style.minWidth = '';
        window.dzSetFollowState(userId, following);   // updates every Track button + the page itself
    }
    function revert(){
        btn.dataset.busy = '';
        btn.style.pointerEvents = '';
        btn.style.minWidth = '';
        btn.textContent = oldText;
        if(isTop) btn.classList.toggle('ipv-untrack', wasFollowing);
    }
    fetch('profile.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=toggle_follow&user_id='+userId
    }).then(function(r){ return r.json(); }).then(function(d){
        if(d.status !== 'ok'){ revert(); return; }
        setTimeout(function(){ done(!!d.following); }, Math.max(0, spinHold - (Date.now() - spinT0)));
    }).catch(revert);
};

/* ============================================================
   SHARE
   ============================================================ */
window.ipvShareAction = function(memeId, btn, feedName){
    dzHp('light');
    _ipvPendingShare = { memeId: memeId, feedName: feedName };

    if(typeof window.openShareModal === 'function'){
        window.openShareModal(memeId);
        _ipvWatchShareModalClose();
    } else {
        ipvShare(memeId);
    }
};

function _ipvWatchShareModalClose(){
    var panel = document.getElementById('shareModalPanel');
    if(!panel || panel._ipvWatching) return;
    panel._ipvWatching = true;

    var mo = new MutationObserver(function(){
        if(!panel.classList.contains('show')){
            mo.disconnect();
            panel._ipvWatching = false;
            _ipvRefreshShareCountFromFeed();
        }
    });
    mo.observe(panel, { attributes:true, attributeFilter:['class'] });
}

function _ipvRefreshShareCountFromFeed(){
    if(!_ipvPendingShare) return;
    var memeId   = _ipvPendingShare.memeId;
    var feedName = _ipvPendingShare.feedName;
    _ipvPendingShare = null;

    var arr = window[feedName] || [];
    var item = arr.find(function(x){ return String(x.id) === String(memeId); });
    if(!item) return;

    var post = document.querySelector('#ipvList .ipv-post[data-id="'+memeId+'"]');
    if(!post) return;
    var countEl = post.querySelector('.ipv-share-btn').closest('.ipv-action-group').querySelector('.ipv-action-count');
    if(countEl && item.shareCount !== undefined) countEl.textContent = ipvFmtNum(item.shareCount);
}

window.ipvSyncActionCount = function(memeId, type, active, count){
    var post = document.querySelector('#ipvList .ipv-post[data-id="'+memeId+'"]');
    if(!post) return;

    var btnClass = { like:'.ipv-like-btn', repost:'.ipv-repost-btn', fav:'.ipv-fav-btn' }[type];
    var btn = post.querySelector(btnClass);
    if(!btn) return;

    if(type === 'like'){
        btn.classList.toggle('liked', active);
        /* icon fill follows the .liked / .faved class (CSS) */
    } else if(type === 'repost'){
        btn.classList.toggle('liked', active);
    } else if(type === 'fav'){
        btn.classList.toggle('faved', active);
        /* icon fill follows the .liked / .faved class (CSS) */
    }

    var countEl = btn.closest('.ipv-action-group').querySelector('.ipv-action-count');
    if(countEl) countEl.textContent = ipvFmtNum(count);

    if(type === 'like'){
        var row = post.querySelector('.ipv-social-row .ipv-likes-text');
        var feedName = post.dataset.feed;
        var arr = feedName ? (window[feedName]||[]) : [];
        var item = arr.find(function(x){ return String(x.id)===String(memeId); });
        if(row && item) row.innerHTML = ipvLikesText(item);
    }
};

window.ipvSyncShareCount = function(memeId, count){
    var post = document.querySelector('#ipvList .ipv-post[data-id="'+memeId+'"]');
    if(!post) return;
    var btn = post.querySelector('.ipv-share-btn');
    if(!btn) return;
    var countEl = btn.closest('.ipv-action-group').querySelector('.ipv-action-count');
    if(countEl) countEl.textContent = ipvFmtNum(count);
    var feedName = post.dataset.feed;
    var arr = feedName ? (window[feedName]||[]) : [];
    var item = arr.find(function(x){ return String(x.id)===String(memeId); });
    if(item) item.shareCount = count;
};

window.ipvShare = function(memeId){
    var url = window.location.origin + '/profile.php?meme=' + memeId;
    if(navigator.share){
        navigator.share({ title:'Check this on Dezzme', url:url });
    } else {
        navigator.clipboard.writeText(url).then(function(){ dzHp('success'); ipvToast('Link copied!'); });
    }
};

window.ipvToast = function(msg){
    if(typeof gToast === 'function'){ gToast(msg); return; }
    var ex = document.getElementById('ipv-toast'); if(ex) ex.remove();
    var t = document.createElement('div');
    t.id = 'ipv-toast';
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:rgba(20,20,25,.95);border:1px solid rgba(255,45,45,.3);color:#fff;padding:8px 20px;border-radius:50px;font-size:12px;z-index:99999999;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.6);transition:opacity .3s;white-space:nowrap;';
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.remove(); },300); }, 2200);
};

/* ============================================================
   LIKERS BOTTOM SHEET
   ============================================================ */
var _ipvLikersData = { likes:[], reposts:[] };
var _ipvLikersFetchToken = 0;

window.ipvOpenLikersSheet = function(memeId, feedName){
    dzHp('light');
    var arr = window[feedName] || [];
    var p = arr.find(function(x){ return String(x.id)===String(memeId); });
    var proof = (p && Array.isArray(p.socialProof)) ? p.socialProof : [];

    _ipvLikersData.likes   = proof.filter(function(sp){ return sp.action_type === 'like'; });
    _ipvLikersData.reposts = proof.filter(function(sp){ return sp.action_type === 'repost'; });

    var tabsEl = document.getElementById('ipvLikersTabs');
    if(tabsEl){
        tabsEl.querySelectorAll('.ipv-likers-tab').forEach(function(b){ b.classList.toggle('active', b.dataset.tab === 'likes'); });
    }
    _ipvRenderLikersTab('likes');
    document.getElementById('ipvLikersOverlay').classList.add('ipv-open');

    var myToken = ++_ipvLikersFetchToken;
    fetch('profile.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=get_meme_social&meme_id='+memeId
    }).then(function(r){ return r.json(); }).then(function(data){
        if(myToken !== _ipvLikersFetchToken) return;
        if(!data || !data.ok) return;

        _ipvLikersData.likes = Array.isArray(data.likes) ? data.likes.map(function(u){
            return { id:u.id, username:u.username, profile_pic:u.profile_pic, action_type:'like', isVerified:!!(+u.is_verified) };
        }) : _ipvLikersData.likes;

        _ipvLikersData.reposts = Array.isArray(data.reposts) ? data.reposts.map(function(u){
            return { id:u.id, username:u.username, profile_pic:u.profile_pic, action_type:'repost', isVerified:!!(+u.is_verified) };
        }) : _ipvLikersData.reposts;

        var activeBtn = tabsEl ? tabsEl.querySelector('.ipv-likers-tab.active') : null;
        _ipvRenderLikersTab(activeBtn ? activeBtn.dataset.tab : 'likes');
    }).catch(function(){});
};

function _ipvRenderLikersTab(tab){
    var listEl = document.getElementById('ipvLikersList');
    if(!listEl) return;
    var list = _ipvLikersData[tab] || [];
    if(!list.length){
        listEl.innerHTML = '<div class="ipv-likers-empty">No '+tab+' yet</div>';
        return;
    }
    listEl.innerHTML = list.map(function(sp){
        var isLike = sp.action_type === 'like';
        var icon = tab === 'reposts' ? 'ri-repeat-line' : (isLike ? 'ri-heart-3-fill' : 'ri-repeat-line');
        return '<a href="profile.php?user_id='+sp.id+'" class="ipv-liker-row" style="text-decoration:none;">'+
            '<img class="ipv-liker-avatar" src="'+ipvEsc(sp.profile_pic||'mee.jpg')+'" onerror="this.onerror=null;this.src=\'mee.jpg\'">'+
            '<span class="ipv-liker-name">'+ipvEsc(sp.username||'')+(sp.isVerified ? (window.DZ_VB||'') : '')+'</span>'+
            '<i class="ipv-liker-icon '+icon+'"></i>'+
        '</a>';
    }).join('');
}

window.ipvSwitchLikersTab = function(btn, tab){
    dzHp('selection');
    document.querySelectorAll('#ipvLikersTabs .ipv-likers-tab').forEach(function(b){ b.classList.toggle('active', b===btn); });
    _ipvRenderLikersTab(tab);
};

window.ipvCloseLikersSheet = function(){
    var el = document.getElementById('ipvLikersOverlay');
    if(el) el.classList.remove('ipv-open');
};

/* ESC closes the topmost layer */
document.addEventListener('keydown', function(e){
    if(e.key !== 'Escape') return;
    var likers = document.getElementById('ipvLikersOverlay');
    if(likers && likers.classList.contains('ipv-open')){ ipvCloseLikersSheet(); return; }
    var reel = document.getElementById('cardCaptionModal');
    if(reel && reel.classList.contains('ccm-open')) return;
    if(_ipvActive) ipvClose();
});

/* recalc single-media aspect ratios (and long-header state) on resize */
window.addEventListener('resize', function(){
    if(!_ipvActive) return;
    document.querySelectorAll('#ipvList .ipv-media-wrap:not(.ipv-multi):not(.ipv-textonly)').forEach(function(wrap){
        var idx = parseInt(wrap.dataset.activeIdx || '0', 10);
        var activeEl = wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"] img, .ipv-media-item[data-idx="'+idx+'"] video');
        if(activeEl && activeEl.dataset.ratio) _ipvApplyAspect(wrap, parseFloat(activeEl.dataset.ratio));
    });
});


/* SWIPE-TO-CLOSE for the immersive viewer: drag it to the RIGHT to close. A multi-photo post keeps its own sideways swipe (from the 2nd photo on, only a drag that starts at the left edge closes). */
var _ipvSwipeClose = function(cfg){
    var st = null, busy = false;
    var SKIP = 'input,textarea,select,[contenteditable],.ccm-video-progress,.gfd-video-progress,.ipv-video-progress,.hl-seek';
    function reset(el){
        el.style.transition = 'none'; el.style.transform = ''; el.style.boxShadow = ''; el.style.willChange = '';
        el.style.borderRadius = ''; el.style.overflow = st_ov;
        void el.offsetWidth;
        el.style.transition = '';
    }
    var st_ov = '';
    function paint(s, x, y){
        /* the modal follows the finger on both axes, shrinking a little and rounding its corners the further it travels (like closing an app card) */
        var d = Math.min(1, Math.sqrt(x * x + y * y) / (s.w * 0.9));
        var sc = 1 - d * 0.10;
        s.el.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0) scale(' + sc + ')';
        s.el.style.borderRadius = Math.round(d * 28) + 'px';
        s.el.style.boxShadow = '-14px 0 40px rgba(0,0,0,' + (0.5 * Math.min(1, d * 3)) + ')';
    }
    document.addEventListener('touchstart', function(e){
        st = null;
        if(busy || e.touches.length !== 1) return;
        var el = document.getElementById(cfg.id);
        if(!el || !el.contains(e.target) || !cfg.isOpen(el)) return;
        if(e.target.closest && e.target.closest(SKIP)) return;
        if(cfg.blocked && cfg.blocked(e.target)) return;
        var t = e.touches[0];
        var mb = e.target.closest ? e.target.closest(cfg.mediaBox) : null;
        var inLaterSlide = !!(mb && parseInt(mb.dataset.count || '1', 10) > 1 && cfg.mediaIndex(mb) > 0);
        st = { x:t.clientX, y:t.clientY, el:el, on:false, dead:(inLaterSlide && t.clientX > 24),
               lx:t.clientX, lt:Date.now(), v:0, w:(el.getBoundingClientRect().width || window.innerWidth) };
    }, { capture:true, passive:true });

    document.addEventListener('touchmove', function(e){
        if(!st || st.dead) return;
        var t = e.touches[0], dx = t.clientX - st.x, dy = t.clientY - st.y;
        if(!st.on){
            /* mostly up / down → that's moving between posts (or scrolling): hands off */
            if(Math.abs(dy) > 10 && Math.abs(dy) > Math.abs(dx) * 1.7){ st.dead = true; return; }
            if(dx < -10 && Math.abs(dx) > Math.abs(dy)){ st.dead = true; return; }
            /* right, straight or up-right / down-right diagonal → the close drag */
            if(!(dx > 8 && Math.abs(dy) <= dx * 1.7)) return;
            st.on = true;
            if(typeof window._ccmVDragAbort === 'function') window._ccmVDragAbort();   /* cancel a vertical drag that may have started a moment earlier */
            st_ov = st.el.style.overflow;
            st.el.style.transition = 'none';
            st.el.style.willChange = 'transform';
            st.el.style.overflow = 'hidden';
        }
        e.stopPropagation();
        if(e.cancelable) e.preventDefault();
        paint(st, Math.max(0, dx), dy * 0.85);
        var now = Date.now(), dt = now - st.lt;
        if(dt > 0){ st.v = (t.clientX - st.lx) / dt; st.lx = t.clientX; st.lt = now; }
    }, { capture:true, passive:false });

    function finish(e, cancelled){
        if(!st){ return; }
        var s = st; st = null;
        if(!s.on) return;
        if(e && e.stopPropagation) e.stopPropagation();
        var el = s.el, ct = e && e.changedTouches && e.changedTouches[0];
        var dx = ct ? Math.max(0, ct.clientX - s.x) : 0;
        var dy = ct ? (ct.clientY - s.y) * 0.85 : 0;
        var close = !cancelled && (dx > s.w * 0.33 || (s.v > 0.5 && dx > 40));
        var g = el.querySelector('[id$="media-gallery"]');
        if(g) g.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';   /* the media swipe was skipped for this touch */
        busy = true;
        if(close){
            if(cfg.hero && cfg.hero(el, el.style.transform, function(){ reset(el); busy = false; })) return;      /* shrinks into the post's card in the grid */
            el.style.transition = 'transform .24s cubic-bezier(.22,.8,.3,1), border-radius .24s ease';
            el.style.transform = 'translate3d(' + (s.w * 1.05) + 'px,' + dy + 'px,0) scale(.9)';
            setTimeout(function(){
                try{ cfg.close(); }catch(err){}
                setTimeout(function(){ reset(el); busy = false; }, 30);
            }, 245);
        } else {
            el.style.transition = 'transform .26s cubic-bezier(.22,1,.36,1), box-shadow .26s ease, border-radius .26s ease';
            el.style.transform = 'translate3d(0,0,0) scale(1)';
            el.style.borderRadius = '0px';
            el.style.boxShadow = '-14px 0 40px rgba(0,0,0,0)';
            setTimeout(function(){ reset(el); busy = false; }, 280);
        }
    }
    document.addEventListener('touchend',    function(e){ finish(e, false); }, { capture:true, passive:true });
    document.addEventListener('touchcancel', function(e){ finish(e, true);  }, { capture:true, passive:true });
};

_ipvSwipeClose({
    id:'ipvOverlay',
    isOpen:function(el){ var m = document.getElementById('cardCaptionModal'); return el.classList.contains('ipv-open') && !(m && m.classList.contains('ccm-open')); },
    mediaBox:'.ipv-media-wrap[data-count]',
    mediaIndex:function(mb){ return parseInt(mb.dataset.activeIdx || '0', 10) || 0; },
    hero:function(el, from, after){ return _ipvHeroClose(from, after); },
    close:function(){ window.ipvClose(); }
});

})();
</script>
</template>


<!-- ================================================================
     MEME / REEL MODAL — now the shared zeems_modal.php (same file home / text feed use).
     Everything below it in this block is profile-only glue.
     ================================================================ -->
<script>window.CCM_ENDPOINT = 'profile.php';</script>
<?php include __DIR__ . '/zeems_modal.php'; ?>
<script>
/* ---- profile-only state ---- */
const profileOwnerId    = <?php echo (int)$profile_id; ?>;
const profileOwnerFollowing = <?php echo $follow_check ? 'true' : 'false'; ?>;

/* ONE source of truth for "do I track this person?" — every Track button (page, topbar, profile viewer,
   immersive, collections viewer) reads and writes it, so they can never disagree. */
window.dzFollowState = window.dzFollowState || {};
if(typeof window.dzFollowState[String(profileOwnerId)] === 'undefined') window.dzFollowState[String(profileOwnerId)] = !!profileOwnerFollowing;
window.dzFollowing = function(userId, fallback){
    var v = window.dzFollowState[String(userId)];
    return (typeof v === 'undefined') ? !!fallback : !!v;
};
window.dzSetFollowState = function(userId, following, fromPage){
    userId = String(userId); following = !!following;
    window.dzFollowState[userId] = following;
    document.querySelectorAll('.ipv-track-btn[data-user="'+userId+'"]').forEach(function(b){
        b.textContent = following ? 'Untrack' : 'Track';
        if(b.classList.contains('ipv-topbar-track-btn')) b.classList.toggle('ipv-untrack', following);
    });
    document.querySelectorAll('.ccm-track-btn[data-user="'+userId+'"]').forEach(function(b){
        b.textContent = following ? 'Untrack' : 'Track';
        b.classList.toggle('following', following);
    });
    /* the profile page's own buttons (only when this is the profile being viewed, and the page isn't the one calling) */
    if(!fromPage && typeof profileOwnerId !== 'undefined' && userId === String(profileOwnerId) && typeof window.dzSyncPageTrack === 'function'){
        window.dzSyncPageTrack(following);
    }
};

/* ---- keep the profile's grids / feeds in step when a post is liked / reposted / saved in the modal ---- */
function _gSyncAllFeedsForMeme(memeId, cntField, count, stateKey, active){
    Object.keys(window).forEach(function(key){
        if(!/Feed$/.test(key) && !/^colFeed_/.test(key)) return;
        var arr = window[key];
        if(!Array.isArray(arr)) return;
        arr.forEach(function(item){
            if(item && String(item.id) === String(memeId)){
                item[cntField] = count;
                item[stateKey] = active;
            }
        });
    });
}

function _gBuildDropdownCard(p, feedVarName, contextLabel){
    var mid = p.id;
    var pinBtn = '';
    if(typeof _gIsOwner !== 'undefined' && _gIsOwner){
        pinBtn = '<button class="card-pin-btn" data-meme-id="'+mid+'" title="Pin to profile" '+
            'onclick="event.stopPropagation();gTogglePin('+mid+',this)"><i class="ri-pushpin-line"></i></button>';
    }
    var addColBtn = '<button class="card-add-col-btn" title="Add to Collection" '+
        'onclick="event.stopPropagation();gOpenAddToCol('+mid+')"><i class="ri-folder-add-line"></i></button>';

    /* values that go into an inline onclick="…" are JS-string-escaped first, then the whole attribute is HTML-escaped (a caption or
       username with quotes or newlines can no longer break out of the attribute — stored XSS) */
    function _jsStr(v){ return String(v == null ? '' : v).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/\r/g,'').replace(/\n/g,'\\n').replace(/\u2028/g,'\\u2028').replace(/\u2029/g,'\\u2029').replace(/</g,'\\x3c'); }
    var safeCaption = _jsStr(p.caption);
    var typeForOpen = p.type === 'text' ? 'image' : p.type;
    var openArgs = "'"+_jsStr(p.file)+"','"+_jsStr(typeForOpen)+"','"+safeCaption+"','"+_jsStr(p.pic)+"','"+
                   _jsStr(p.username)+"',"+(parseInt(p.userId,10)||0)+",'"+_jsStr(feedVarName)+"',"+(parseInt(mid,10)||0);

    var mediaInner;
    if(p.type === 'image'){
        mediaInner = '<img src="'+ipvEsc(p.file)+'" onclick="openMemeModal('+ipvEsc(openArgs)+')">';
    } else if(p.type === 'video'){
        mediaInner = '<video src="'+ipvEsc(p.file)+'" autoplay muted loop onclick="openMemeModal('+ipvEsc(openArgs)+')"></video>';
    } else {
        mediaInner = '<img src="uploads/Text.jpg" onclick="openMemeModal('+ipvEsc("'uploads/Text.jpg','image','"+safeCaption+"','"+_jsStr(p.pic)+"','"+_jsStr(p.username)+"',"+(parseInt(p.userId,10)||0)+",'"+_jsStr(feedVarName)+"',"+(parseInt(mid,10)||0))+')">';
    }

    var iconMap = { Reposted:'ri-repeat-line', Saved:'ri-bookmark-line', Liked:'ri-heart-3-fill' };
    var capIcon = iconMap[contextLabel] ? '<i class="'+iconMap[contextLabel]+'" style="color:#FF4C4C;"></i>' : '';

    var downloadName = p.file.replace('uploads/','');
    var card = document.createElement('div');
    card.className = 'meme-card';
    card.dataset.id = mid;
    card.dataset.ts = Math.floor(Date.now()/1000);
    card.dataset.pinned = '0';
    card.innerHTML =
        '<div class="top-bar">'+
            '<img src="'+p.pic+'" onclick="window.location.href=\'profile.php?user_id='+p.userId+'\'">'+
            '<div class="username" onclick="window.location.href=\'profile.php?user_id='+p.userId+'\'">'+
                (p.isYou ? 'You' : (p.username||'')) +
                (p.isVerified ? (window.DZ_VB||'').replace('class="dz-vb"','class="dz-vb" style="width:1em;height:1em;margin-left:.3em"') : '') +
            '</div>'+
        '</div>'+
        '<div class="media-box" style="position:relative;">'+mediaInner+addColBtn+'</div>'+
        '<div class="icon-bar">'+
            '<a href="download.php?file='+encodeURIComponent(downloadName)+'" title="Download"><i class="ri-download-line"></i></a>'+
            pinBtn+
        '</div>'+
        '<div class="caption">'+capIcon+(p.caption||'')+'</div>';
    return card;
}

function _gRemoveFromGrid(grid, memeId, emptyIcon, emptyMsg){
    var card = grid.querySelector('.meme-card[data-id="'+memeId+'"]');
    if(card) card.remove();
    if(grid.querySelectorAll('.meme-card').length === 0){
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;">'+
            '<i class="'+emptyIcon+'" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>'+emptyMsg+'</div>';
    }
}

function _gInsertIntoFlatGrid(grid, p, feedVarName, contextLabel){
    if(grid.querySelector('.meme-card[data-id="'+p.id+'"]')) return;
    var placeholder = grid.querySelector(':scope > div');
    if(placeholder && !placeholder.classList.contains('meme-card') && !placeholder.classList.contains('dezz-grid-loader')){
        placeholder.remove();
    }
    var newCard = _gBuildDropdownCard(p, feedVarName, contextLabel);
    grid.insertBefore(newCard, grid.firstChild);
}

function _gSyncFeedArray(feedVarName, active, p, contextLabel){
    if(!window[feedVarName]) window[feedVarName] = [];
    if(active){
        if(!window[feedVarName].some(function(f){ return f.id == p.id; })){
            var entry = Object.assign({}, p, { context: contextLabel });
            window[feedVarName].unshift(entry);
        }
    } else {
        window[feedVarName] = window[feedVarName].filter(function(f){ return f.id != p.id; });
    }
}

function _gSyncDropdownOnAction(type, active, p){
    if(type === 'repost'){
        var grid = document.getElementById('repostsGrid');
        if(grid){
            if(active) _gInsertIntoFlatGrid(grid, p, 'repostFeed', 'Reposted');
            else       _gRemoveFromGrid(grid, p.id, 'ri-repeat-line', 'No reposts yet');
        }
        _gSyncFeedArray('repostFeed', active, p, 'Reposted');

    } else if(type === 'fav'){
        var grid = document.getElementById('favsGrid');
        if(grid){
            if(active) _gInsertIntoFlatGrid(grid, p, 'favsFeed', 'Saved');
            else       _gRemoveFromGrid(grid, p.id, 'ri-bookmark-line', 'No favorites yet');
        }
        _gSyncFeedArray('favsFeed', active, p, 'Saved');

    } else if(type === 'like'){
        var likesGrid = document.getElementById('likesGrid');
        if(likesGrid){
            if(active){
                _gSyncFeedArray('likesFeed', true, p, 'Liked');
                var firstGroup = likesGrid.querySelector('.likes-date-group');
                var firstLabel = firstGroup ? firstGroup.querySelector('.likes-date-label-text') : null;
                var innerGrid;
                if(firstGroup && firstLabel && firstLabel.textContent.trim() === 'Today'){
                    innerGrid = firstGroup.querySelector('.grid');
                } else {
                    var group = document.createElement('div');
                    group.className = 'likes-date-group';
                    group.innerHTML =
                        '<div class="likes-date-label">'+
                            '<span class="likes-date-label-text">Today</span>'+
                            '<div class="likes-date-label-line"></div>'+
                            '<span class="likes-date-label-count">1 post</span>'+
                        '</div><div class="grid"></div>';
                    var emptyMsg = likesGrid.querySelector(':scope > div:not(.likes-date-group)');
                    if(emptyMsg) emptyMsg.remove();
                    likesGrid.insertBefore(group, likesGrid.firstChild);
                    innerGrid = group.querySelector('.grid');
                }
                if(innerGrid && !innerGrid.querySelector('.meme-card[data-id="'+p.id+'"]')){
                    innerGrid.insertBefore(_gBuildDropdownCard(p, 'likesFeed', 'Liked'), innerGrid.firstChild);
                }
            } else {
                _gSyncFeedArray('likesFeed', false, p, 'Liked');
                var card = likesGrid.querySelector('.meme-card[data-id="'+p.id+'"]');
                if(card){
                    var group = card.closest('.likes-date-group');
                    card.remove();
                    if(group && group.querySelectorAll('.meme-card').length === 0) group.remove();
                }
                if(likesGrid.querySelectorAll('.meme-card').length === 0){
                    likesGrid.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#aaa;font-size:0.95rem;">'+
                        '<i class="ri-heart-line" style="font-size:2.5rem;display:block;margin-bottom:10px;color:#FF4C4C;"></i>No liked posts yet</div>';
                }
            }
        }
    }
}

function showToast(msg){
    if(typeof gToast === 'function'){ gToast(msg); return; }

    var existing = document.getElementById('peura-toast');
    if(existing) existing.remove();

    var t = document.createElement('div');
    t.id = 'peura-toast';
    t.textContent = msg;
    Object.assign(t.style, {
        position: 'fixed',
        bottom: '90px',
        left: '50%',
        transform: 'translateX(-50%)',
        background: 'rgba(20,20,25,.95)',
        border: '1px solid rgba(255,45,45,.3)',
        color: '#fff',
        padding: '8px 20px',
        borderRadius: '50px',
        fontSize: '12px',
        zIndex: '9999999',
        pointerEvents: 'none',
        boxShadow: '0 8px 24px rgba(0,0,0,.6)',
        transition: 'opacity .3s',
        whiteSpace: 'nowrap'
    });
    document.body.appendChild(t);
    setTimeout(function(){
        t.style.opacity = '0';
        setTimeout(function(){ t.remove(); }, 300);
    }, 2200);
}

/* ---- openMemeModal / closeMemeModal: the names the rest of the profile page (and the immersive viewer) already call ---- */
window.openMemeModal = function(src, type, caption, pic, username, userId, feedName, memeId, startIdx, startTime, startAudioTime, posterDataUrl){
    var raw  = window[feedName] || (typeof profileFeed !== 'undefined' ? profileFeed : []);
    var feed = (typeof window._dzFilterFeed === 'function') ? window._dzFilterFeed(raw) : raw;
    openCardModal({
        id: memeId, userId: userId, caption: caption, feed: feed,
        mediaIndex: startIdx, startTime: startTime, startAudioTime: startAudioTime, posterDataUrl: posterDataUrl
    });
};
(function(){
    var realClose = closeCardCaptionModal;                      /* the shared modal's own close */
    window.closeMemeModal = function(){ realClose(); };
    /* back button, swipe-down and backdrop taps inside the modal call closeCardCaptionModal — send them through closeMemeModal so the immersive viewer's hook still runs */
    window.closeCardCaptionModal = function(){ return window.closeMemeModal.apply(this, arguments); };
})();

/* after like / repost / save the shared modal announces it — mirror it into the profile's own lists */
document.addEventListener('zeems:action', function(e){
    var d = e.detail || {};
    var f = { like:['likeCount','liked'], repost:['repostCount','reposted'], fav:['favCount','faved'] }[d.type];
    if(!f) return;
    if(d.count !== null && d.count !== undefined) _gSyncAllFeedsForMeme(d.memeId, f[0], d.count, f[1], d.active);
    if(typeof window.ipvSyncActionCount === 'function') window.ipvSyncActionCount(d.memeId, d.type, d.active, d.count);
    if(d.item) _gSyncDropdownOnAction(d.type, d.active, d.item);
    /* my repost takes a slot in the "liked / reposted by" strip. When I repost, the person it pushed out goes to the spare list;
       when I un-repost, my avatar leaves and the first spare takes the slot straight back. */
    if(d.type === 'repost' && d.item && Array.isArray(d.item.socialProof)){
        var meId = String((window.dzMe && window.dzMe.id) || (typeof currentUserId !== 'undefined' ? currentUserId : 0));
        var key = function(x){ return x.id + '|' + x.action_type; };
        var fresh = d.item.socialProof.slice();
        Object.keys(window).forEach(function(k){
            if(!/Feed$/.test(k) && !/^colFeed_/.test(k)) return;
            var arr = window[k]; if(!Array.isArray(arr)) return;
            arr.forEach(function(it){
                if(!it || String(it.id) !== String(d.memeId)) return;
                var old = Array.isArray(it.socialProof) ? it.socialProof : [];
                var spare = Array.isArray(it.socialSpare) ? it.socialSpare.slice() : (Array.isArray(d.item.socialSpare) ? d.item.socialSpare.slice() : []);
                var strip = fresh.slice();
                if(d.active){
                    /* anyone who fell out of the strip becomes the first spare */
                    var inNew = {}; strip.forEach(function(x){ inNew[key(x)] = 1; });
                    var pushed = old.filter(function(x){ return !inNew[key(x)] && String(x.id) !== meId; });
                    spare = pushed.concat(spare.filter(function(s){ return !pushed.some(function(x){ return key(x) === key(s); }); }));
                } else {
                    strip = strip.filter(function(x){ return !(String(x.id) === meId && x.action_type === 'repost'); });
                    var have = {}; strip.forEach(function(x){ have[key(x)] = 1; });
                    while(strip.length < 4 && spare.length){ var s = spare.shift(); if(!have[key(s)] && String(s.id) !== meId){ strip.push(s); have[key(s)] = 1; } }
                }
                it.socialProof = strip; it.socialSpare = spare;
                if(it !== d.item){ d.item.socialProof = strip; d.item.socialSpare = spare; }
            });
        });
        /* let a strip drawn on a card or in the modal repaint from the corrected data */
        if(typeof window.ipvRepaintStrip === 'function') window.ipvRepaintStrip(d.memeId, d.item);
        document.dispatchEvent(new CustomEvent('zeems:strip', { detail: { memeId: d.memeId, socialProof: d.item.socialProof, socialSpare: d.item.socialSpare || [] } }));
    }
});
</script>

<?php /* ═══════════════════════════════════════════════════════════════════════════
   POST MENU (⋯) + SAVE-TO-COLLECTION — now the SHARED sheets (shared_modals.php).
   Only those two are used here; this page's Report modal stays where it is
   (#dzReportOverlay is not rendered a second time).
   ═══════════════════════════════════════════════════════════════════════════ */
$dz_sm_sections = ['save', 'index'];
include __DIR__ . '/shared_modals.php'; ?>
<?php /* Comments sheet (opened by the Zeems rail comment button) */ if (!function_exists('cs_api') && is_file(__DIR__ . '/comment_section.php')) { include __DIR__ . '/comment_section.php'; } ?>

<script>
/* ── what the shared sheets expect from the host page ── */
var currentUserId = <?php echo (int)($current_user ?? 0); ?>;
<?php
$__dzme = ['id' => (int)($current_user ?? 0), 'username' => '', 'profile_pic' => ''];
if (!empty($__dzme['id']) && isset($conn)) { $__q = @$conn->prepare('SELECT username, profile_pic FROM users WHERE id=? LIMIT 1'); if ($__q) { $__q->bind_param('i', $__dzme['id']); $__q->execute(); $__r = $__q->get_result()->fetch_assoc(); if ($__r) { $__dzme['username'] = (string)$__r['username']; $__dzme['profile_pic'] = (string)($__r['profile_pic'] ?? ''); } $__q->close(); } }
?>
window.dzMe = window.dzMe || <?php echo json_encode($__dzme); ?>;
window.SVSH_ENDPOINT = window.SVSH_ENDPOINT || 'collections_api.php';
if(typeof window.kebabData === 'undefined') window.kebabData = {};

/* a post of the immersive viewer / collection viewer → the shape the shared menu reads */
/* one safe entry point: if something is missing the user SEES why instead of "nothing happens" */
function dzOpenPostMenu(p){
    if(!p) return showToast('Menu: post not found');
    if(typeof openIndexSheet !== 'function') return showToast('Menu sheet missing — upload shared_modals.php next to profile_modals.php');
    try{ dzKebabFromPost(p); openIndexSheet(p.id); }
    catch(e){ showToast('Menu error: ' + (e && e.message ? e.message : e)); if(window.console) console.error(e); }
}
function dzKebabFromPost(p){
    var f = String(p.file || '').replace(/^uploads\//, '');
    window.kebabData[p.id] = {
        id: p.id, caption: p.caption || '', type: p.type || 'image',
        fileName: (p.type === 'text') ? '' : f,
        uploadedBy: p.userId, remixOf: p.remixOf || 0, isAd: !!p.isAd,
        posterUsername: p.username || '', posterPic: p.pic || 'mee.jpg',
        allowDownload: (p.allowDownload === undefined || p.allowDownload === null) ? 1 : p.allowDownload,
        collab: p.collab || null,
        media: Array.isArray(p.media) ? p.media.map(function(m){ return { file: String((m && m.file) || ''), type: (m && m.type) || '' }; }) : []   /* every file of a multi-media post (Stats for nerds follows the one on screen) */
    };
    return window.kebabData[p.id];
}

/* openIndexSheet(id) can be called straight from the viewer (id only) — look the post up in the viewer's feed */
window.dzKebabResolve = function(id){
    var sid = String(id), hit = null;
    function chk(x){ var p = x && (x.type === 'post' && x.data ? x.data : x); if(p && String(p.id) === sid) hit = p; }
    try{ if(typeof _ccmHStack !== 'undefined') for(var i=_ccmHStack.length-1;i>=0 && !hit;i--) chk(_ccmHStack[i].item); }catch(e){}
    try{ if(!hit && typeof ccmFeed !== 'undefined') for(var j=0;j<ccmFeed.length && !hit;j++) chk(ccmFeed[j]); }catch(e){}
    return hit ? dzKebabFromPost(hit) : null;
};

/* actions the shared menu rows call */
window.shareMeme = function(memeId){
    if(typeof window.openShareModal === 'function'){ window.openShareModal(memeId); return; }
    var url = window.location.origin + '/profile.php?meme=' + memeId;
    if(navigator.share){ navigator.share({ title: 'Check this on Dezzme', url: url }); }
    else { try{ navigator.clipboard.writeText(url); }catch(e){} showToast('Link copied!'); }
};
window.blockUser = function(userId){
    if(!confirm("Block this user? You won't see their content anymore.")) return;
    fetch('home.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=block_user&target_id=' + encodeURIComponent(userId) })
    .then(function(r){ return r.json(); })
    .then(function(d){ if(d && d.status === 'ok') showToast('User blocked'); })
    .catch(function(){ showToast('Something went wrong'); });
};
window.notInterested = function(memeId){
    fetch('home.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=not_interested&meme_id=' + encodeURIComponent(memeId) }).catch(function(){});
    showToast("Got it — you'll see fewer posts like this");
};

/* ── the viewers' "⋯" buttons → the shared menu (these replace this page's old private menu sheet) ── */
function openCcmMenuSheet(){
    var item = (typeof ccmFeed !== 'undefined') ? ccmFeed[_ccmIndex] : null;
    var p = (typeof _ccmSheetPost === 'function') ? _ccmSheetPost() : (item && item.type === 'post' ? item.data : null);
    dzOpenPostMenu(p);
}
function closeCcmMenuSheet(){ if(typeof closeIndexSheet === "function") closeIndexSheet(); }
function ccmMenuTap(postId){
    var p = (typeof _ccmSheetPost === 'function') ? _ccmSheetPost() : null;
    if(!p || String(p.id) !== String(postId)){ openCcmMenuSheet(); return; }
    dzOpenPostMenu(p);
}
window.ipvOpenMenu = function(memeId, feedName){
    dzHp('light');
    var arr = (feedName && window[feedName]) || [];
    var p = arr.find(function(x){ return String(x.id) === String(memeId); });
    if(!p && typeof profileFeed !== 'undefined' && Array.isArray(profileFeed)) p = profileFeed.find(function(x){ return String(x.id) === String(memeId); });
    dzOpenPostMenu(p);
};
</script>

<?php
/* ══════════════════════════════════════════════════════════════
   PROFILE KEBAB MODAL — ONE modal for both cases
   • own profile   → the old "kebabFullModal" buttons
   • other profile → the old "profileKebabFullModal" buttons
   ══════════════════════════════════════════════════════════════ */
if (isset($is_own_profile)) {
    $pkbm_is_own = (bool)$is_own_profile;
} else {
    $pkbm_is_own = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$profile_id;
}

$pkbm_username = isset($user['username']) ? $user['username'] : '';
$pkbm_title    = $pkbm_is_own ? 'Menu' : $pkbm_username;
$pkbm_rows     = $pkbm_is_own ? 9 : 11;

/* Builds href + onclick for rows that need the "blocked / must track" gate */
if (!function_exists('pkbm_gate')) {
    function pkbm_gate($url, $blocked, $can_view, $blockedMsg, $trackMsg) {
        if (!empty($blocked)) {
            return 'href="#" onclick="alert(\'' . $blockedMsg . '\'); return false;"';
        }
        if (empty($can_view)) {
            return 'href="#" onclick="alert(\'' . $trackMsg . '\'); return false;"';
        }
        return 'href="' . $url . '"';
    }
}
?>

<!-- ══════════════════════════════════════
     PROFILE KEBAB MODAL (full screen, centered column on desktop)
══════════════════════════════════════ -->
<div id="profileKebabModal" class="pkbm-overlay">
    <div class="pkbm-col">

        <div class="pkbm-topbar">
            <button type="button" class="pkbm-back-btn" onclick="closeProfileKebabModal()" aria-label="Close">
                <i class="ri-arrow-left-s-line"></i>
            </button>
            <div class="pkbm-topbar-title"><?php echo htmlspecialchars($pkbm_title); ?></div>
            <div class="pkbm-topbar-spacer"></div>
        </div>

        <div class="pkbm-body">

            <!-- ══════════ SKELETON LOADER ══════════ -->
            <div id="pkbmSkeleton" class="pkbm-skeleton" aria-hidden="true">
                <?php for ($i = 0; $i < $pkbm_rows; $i++): ?>
                <div class="pkbm-skel-row">
                    <div class="pkbm-skel-icon"></div>
                    <div class="pkbm-skel-text" style="width: <?php echo rand(55, 85); ?>%;"></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- ══════════ REAL MENU CONTENT ══════════ -->
<?php $pkbm_theme_row = <<<'HTML'
                <!-- ═════════ DARK MODE TOGGLE (on by default) ═════════ -->
                <div class="pkbm-row pkbm-theme-row" id="pkbmThemeRow" role="switch" tabindex="0" aria-checked="true" onclick="dzToggleTheme()" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();dzToggleTheme();}">
                    <i class="ri-moon-line"></i> <span class="pkbm-theme-lbl">Dark mode</span>
                    <span class="pkbm-sw" aria-hidden="true"><span class="pkbm-sw-knob"></span></span>
                </div>
HTML;
?>
            <div id="pkbmContent" class="pkbm-content">



            <?php if ($pkbm_is_own): ?>
                <!-- ═════════ CURRENT USER BUTTONS ═════════ -->
                <a href="setting.php" class="pkbm-row">
                    <i class="ri-settings-3-line"></i> Your Settings
                </a>
                <a href="account.php?user_id=<?php echo (int)$profile_id; ?>" class="pkbm-row">
                    <i class="ri-user-line"></i> About Account
                </a>
                <a href="search.php?focus=1" class="pkbm-row">
                    <i class="ri-search-line"></i> Search
                </a>
                <?php echo $pkbm_theme_row; ?>
                <a href="audio.php?user_id=<?php echo (int)$profile_id; ?>" class="pkbm-row">
                    <i class="ri-play-list-line"></i> Your Sounds
                </a>
                <a href="profile_longform.php?user_id=<?php echo (int)$profile_id; ?>" class="pkbm-row">
                    <i class="ri-video-line"></i> Your Videos
                </a>
                <a href="links.php?user_id=<?php echo (int)$profile_id; ?>" class="pkbm-row">
                    <i class="ri-link-m"></i> Your Links
                </a>
                <a href="profile_shop.php?user_id=<?php echo (int)$profile_id; ?>" class="pkbm-row">
                    <i class="ri-store-2-line"></i> Your Shop
                </a>
                <a href="portfolio.php?user_id=<?php echo (int)$profile_id; ?>" class="pkbm-row">
                    <i class="ri-briefcase-4-line"></i> Your Portfolio
                </a>
                <a href="profile.php?view_as=1" class="pkbm-row">
                    <i class="ri-eye-line"></i> View As
                </a>
                <a href="#" onclick="closeProfileKebabModal(); if(typeof dzToggleUntrackedBtn === 'function') dzToggleUntrackedBtn(); return false;" class="pkbm-row">
                    <i class="ri-eye-off-line" id="pkbmUntrackedIc"></i> <span id="pkbmUntrackedLbl">Remove Untracked button</span>
                </a>
                <a href="#" onclick="closeProfileKebabModal(); if(typeof dzCtaOpen === 'function') dzCtaOpen(); return false;" class="pkbm-row">
                    <i class="ri-megaphone-line"></i> Profile CTA pill
                </a>
                <a href="#" onclick="closeProfileKebabModal(); if(typeof dzFvxOwnOpen === 'function') dzFvxOwnOpen('gb'); return false;" class="pkbm-row">
                    <i class="ri-book-open-line"></i> Guest book requests
                </a>
                <a href="earth.php" class="pkbm-row">
                    <i class="ri-earth-line"></i> DezzMap
                </a>
                <a href="#" onclick="closeProfileKebabModal(); shareProfile(<?php echo (int)$profile_id; ?>); return false;" class="pkbm-row">
                    <i class="ri-share-line"></i> Share Profile
                </a>
                <a href="#" onclick="closeProfileKebabModal(); copyProfileLinkPK(<?php echo (int)$profile_id; ?>); return false;" class="pkbm-row">
                    <i class="ri-file-copy-line"></i> Copy Profile Link
                </a>
                <a href="#" onclick="closeProfileKebabModal(); if(typeof openProfileQr === 'function') openProfileQr(); return false;" class="pkbm-row">
                    <i class="ri-qr-code-line"></i> QR Code
                </a>

            <?php else: ?>
                <!-- ═════════ OTHER USER BUTTONS ═════════ -->
                <?php if (!$is_blocked): ?>

                <a <?php echo pkbm_gate('audio.php?user_id=' . (int)$profile_id, $blocked_check, $can_view,
                        'You are blocked, you cannot view this.', 'Track this Dezzmer to view their playlist.'); ?> class="pkbm-row">
                    <i class="ri-play-list-line"></i> User Sounds
                </a>

                <a <?php echo pkbm_gate('profile_longform.php?user_id=' . (int)$profile_id, $blocked_check, $can_view,
                        'You are blocked, you cannot view this.', 'Track this Dezzmer to view their videos.'); ?> class="pkbm-row">
                    <i class="ri-video-line"></i> User Videos
                </a>

                <a <?php echo pkbm_gate('account.php?user_id=' . (int)$profile_id, $blocked_check, $can_view,
                        'You are blocked, you cannot view this account.', 'Track this Dezzmer to view their account.'); ?> class="pkbm-row">
                    <i class="ri-user-line"></i> About Account
                </a>

                <a <?php echo pkbm_gate('links.php?user_id=' . (int)$profile_id, $blocked_check, $can_view,
                        'You are blocked, you cannot view this user\\\'s links.', 'Track this Dezzmer to view their links.'); ?> class="pkbm-row">
                    <i class="ri-link-m"></i> Links
                </a>

                <a <?php echo pkbm_gate('portfolio.php?user_id=' . (int)$profile_id, $blocked_check, $can_view,
                        'You are blocked, you cannot view this.', 'Track this Dezzmer to view their portfolio.'); ?> class="pkbm-row">
                    <i class="ri-briefcase-4-line"></i> Their Portfolio
                </a>


                <a href="#" onclick="closeProfileKebabModal(); if(typeof dzFvxGb === 'function') dzFvxGb(); return false;" class="pkbm-row">
                    <i class="ri-book-open-line"></i> Guest book
                </a>
                <a href="#" onclick="closeProfileKebabModal(); if(typeof dzFvxTranslate === 'function') dzFvxTranslate(); return false;" class="pkbm-row">
                    <i class="ri-translate-2"></i> Translate profile
                </a>

                <a href="search.php?focus=1" class="pkbm-row">
                    <i class="ri-search-line"></i> Search
                </a>

                <a href="#" onclick="closeProfileKebabModal(); shareProfile(<?php echo (int)$profile_id; ?>); return false;" class="pkbm-row">
                    <i class="ri-share-line"></i> Share Profile
                </a>

                <a href="profile_share.php?user_id=<?php echo (int)$profile_id; ?>" class="pkbm-row">
                    <i class="ri-links-line"></i> View Shared Activities
                </a>

                <a href="#" onclick="closeProfileKebabModal(); copyProfileLinkPK(<?php echo (int)$profile_id; ?>); return false;" class="pkbm-row">
                    <i class="ri-file-copy-line"></i> Copy <span style="color:#FF2D2D;font-weight:600;"><?php echo htmlspecialchars($pkbm_username); ?>'s</span> Profile Link
                </a>
                <a href="#" onclick="closeProfileKebabModal(); if(typeof openProfileQr === 'function') openProfileQr(); return false;" class="pkbm-row">
                    <i class="ri-qr-code-line"></i> QR Code
                </a>
                <?php endif; ?>

                <!-- Block / Unblock -->
                <form method="POST" style="margin:0;">
                    <?php echo dz_csrf_field(); ?>
                    <?php if ($is_blocked): ?>
                        <button type="submit" name="unblock" class="pkbm-row pkbm-form-row pkbm-row-danger">
                            <i class="ri-user-unfollow-line"></i> Unblock
                        </button>
                    <?php else: ?>
                        <?php /* same confirm popup as the Block button on the page (#blkModal) — "Block" there submits this form */ ?>
                        <button type="submit" name="block" class="pkbm-row pkbm-form-row" data-name="<?php echo htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES); ?>" onclick="var r = (typeof dzBlockAsk === 'function') ? dzBlockAsk(event, this) : true; if (typeof closeProfileKebabModal === 'function' && r === false) closeProfileKebabModal(); return r;">
                            <i class="ri-user-add-line"></i> Block
                        </button>
                    <?php endif; ?>
                </form>

                <!-- Report → opens the report modal straight on "Reporting a User" reasons -->
                <a href="#" onclick="closeProfileKebabModal(); openReportModal({userId: <?php echo (int)$profile_id; ?>}); return false;" class="pkbm-row pkbm-row-danger">
                    <i class="ri-flag-line"></i> Report
                </a>

                <a href="Help.php" class="pkbm-row">
                    <i class="ri-question-line"></i> Help and Feedback
                </a>
            <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php /* ═══════════ PROFILE QR CODE — "Dezzme Code" ═══════════ */
$dzqr_pic  = !empty($user['profile_pic']) ? (string)$user['profile_pic'] : 'mee.jpg';
$dzqr_name = (string)($user['username'] ?? '');
$dzqr_vb   = function_exists('dz_vb_flag') && dz_vb_flag($conn, (int)$profile_id);
?>
<div id="dzQrOv" class="dzqr-ov" role="dialog" aria-modal="true" aria-label="Profile QR code" aria-hidden="true">
  <div class="dzqr-card" id="dzQrCard">
    <button type="button" class="dzqr-x" onclick="closeProfileQr()" aria-label="Close"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
    <div class="dzqr-eyebrow"><i></i>DEZZME CODE<i></i></div>
    <div class="dzqr-tile">
      <div class="dzqr-svgwrap" id="dzQrSvg"><div class="dzqr-load">Making your code…</div></div>
    </div>
    <div class="dzqr-name"><span><?php echo htmlspecialchars($dzqr_name); ?></span><?php if ($dzqr_vb && function_exists('dz_vb_svg')) echo dz_vb_svg(); ?></div>
    <div class="dzqr-sub">Scan to open this profile on Dezzme</div>
    <div class="dzqr-acts">
      <button type="button" class="dzqr-btn" id="dzQrSave"><i class="ri-download-2-line"></i> Save</button>
      <button type="button" class="dzqr-btn red" id="dzQrShare"><i class="ri-share-forward-line"></i> Share</button>
    </div>
  </div>
</div>
<style>
.dzqr-ov{position:fixed;inset:0;z-index:999999999999;display:none;align-items:center;justify-content:center;padding:0;background:linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));background-attachment:fixed;opacity:0;transform:translateY(18px);transition:opacity .25s ease,transform .28s cubic-bezier(.22,1,.36,1);font-family:'Poppins',sans-serif;-webkit-font-smoothing:antialiased;overflow:hidden}
.dzqr-ov.show{display:flex}.dzqr-ov.in{opacity:1;transform:none}
.dzqr-card{position:relative;width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 22px 28px;color:#fff;text-align:center;background:none;box-shadow:none;overflow-y:auto}
.dzqr-x{position:absolute;top:12px;right:12px;width:34px;height:34px;border:0;border-radius:6px;background:rgba(255,255,255,.08);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:3}
.dzqr-x:hover{background:rgba(255,255,255,.16)}
.dzqr-eyebrow{display:flex;align-items:center;justify-content:center;gap:9px;margin:0 0 18px;font-size:.66rem;font-weight:700;letter-spacing:.32em;color:rgba(255,255,255,.6)}
.dzqr-eyebrow i{display:block;width:22px;height:1px;background:rgba(255,255,255,.25)}
.dzqr-tile{position:relative;width:min(80vw,360px,52vh);aspect-ratio:1;padding:16px;border-radius:12px;background:linear-gradient(145deg,#fff7f4,#ffe9e4)}
.dzqr-svgwrap{position:relative;width:100%;height:100%}
.dzqr-svgwrap svg{display:block;width:100%;height:100%}
.dzqr-load{display:flex;align-items:center;justify-content:center;height:100%;font-size:.7rem;color:#8a1a2a}
.dzqr-name{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;font-size:1.05rem;font-weight:700;letter-spacing:-.2px;color:#fff}
.dzqr-name span{max-width:80vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dzqr-name .dz-vb{width:1em;height:1em;flex:none;color:#FF2D2D;fill:#FF2D2D}
.dzqr-sub{margin-top:3px;font-size:.68rem;color:rgba(255,255,255,.55)}
.dzqr-acts{display:flex;gap:8px;margin-top:20px;width:min(80vw,360px)}
.dzqr-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;height:38px;border:0;border-radius:5px;background:rgba(255,255,255,.1);color:#fff;font:inherit;font-size:.76rem;font-weight:600;cursor:pointer;transition:background .2s,transform .15s}
.dzqr-btn:hover{background:rgba(255,255,255,.18)}.dzqr-btn:active{transform:scale(.97)}
.dzqr-btn.red{background:#FF2D2D;color:#000;font-weight:700}.dzqr-btn.red:hover{background:#ff1a1a}
@media (prefers-reduced-motion:reduce){.dzqr-ov{transition:none}}
</style>
<script>
(function(){
  var PROFILE_ID = <?php echo (int)$profile_id; ?>;
  var NAME = <?php echo json_encode($dzqr_name); ?>;
  var PIC  = <?php echo json_encode($dzqr_pic); ?>;
  var LIB  = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js';
  var ov = document.getElementById('dzQrOv'), box = document.getElementById('dzQrSvg'), built = null;
  function hp(k){ try{ if(window.dzHp) dzHp(k); else if(window.dzHaptic && dzHaptic[k]) dzHaptic[k](); }catch(e){} }
  function toast(m){ if(typeof showToast === 'function') showToast(m); }
  function link(){ return window.location.origin + '/profile.php?user_id=' + PROFILE_ID; }
  function loadLib(cb){
    if(window.qrcode) return cb(true);
    var s = document.createElement('script'); s.src = LIB; s.async = true;
    s.onload = function(){ cb(!!window.qrcode); }; s.onerror = function(){ cb(false); };
    document.head.appendChild(s);
  }
  /* Draws the code as rounded dots with fancy eyes (error correction H, so the centre photo is safe). Dark dots on a light tile scan on every phone. */
  function makeSvg(text, picHref){
    var qr = qrcode(0, 'H'); qr.addData(text); qr.make();
    var n = qr.getModuleCount(), q = 0, S = n + q * 2, c = n / 2, hole = n * 0.125 + 0.6, d = '';
    function eye(x, y){
      return '<path fill-rule="evenodd" fill="url(#dzqg2)" d="M'+(x+2)+' '+y+'h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-3a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2zM'+(x+2.4)+' '+(y+1)+'h2.2a1.4 1.4 0 0 1 1.4 1.4v2.2a1.4 1.4 0 0 1-1.4 1.4h-2.2a1.4 1.4 0 0 1-1.4-1.4v-2.2a1.4 1.4 0 0 1 1.4-1.4z" transform="translate(0 0)"/>';
    }
    function inEye(r, k){ return (r < 7 && k < 7) || (r < 7 && k >= n - 7) || (r >= n - 7 && k < 7); }
    var dots = '';
    for(var r = 0; r < n; r++) for(var k = 0; k < n; k++){
      if(!qr.isDark(r, k) || inEye(r, k)) continue;
      var dx = k + .5 - c, dy = r + .5 - c; if(dx*dx + dy*dy < hole*hole) continue;
      dots += '<circle cx="'+(k+.5+q)+'" cy="'+(r+.5+q)+'" r=".47"/>';
    }
    function eyes(x, y){
      x += q; y += q;
      return '<circle cx="'+(x+3.5)+'" cy="'+(y+3.5)+'" r="3" fill="none" stroke="#e4162f" stroke-width="1"/>' +
             '<circle cx="'+(x+3.5)+'" cy="'+(y+3.5)+'" r="1.6" fill="#e4162f"/>';
    }
    var pr = hole - .35, svg =
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+S+' '+S+'" shape-rendering="geometricPrecision">' +
      '<defs><linearGradient id="dzqg" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="'+S+'" y2="'+S+'"><stop offset="0" stop-color="#14163f"/><stop offset=".55" stop-color="#5a1230"/><stop offset="1" stop-color="#c4001f"/></linearGradient>' +
      '<linearGradient id="dzqg2" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="'+S+'" y2="'+S+'"><stop offset="0" stop-color="#0d0f33"/><stop offset="1" stop-color="#3a0d2a"/></linearGradient>' +
      '<clipPath id="dzqc"><circle cx="'+(c+q)+'" cy="'+(c+q)+'" r="'+(pr-.5)+'"/></clipPath></defs>' +
      '<g fill="#e4162f">'+dots+'</g>' + eyes(0,0) + eyes(n-7,0) + eyes(0,n-7) +
      '<circle cx="'+(c+q)+'" cy="'+(c+q)+'" r="'+pr+'" fill="#fff7f4"/>' +
      '<circle cx="'+(c+q)+'" cy="'+(c+q)+'" r="'+(pr-.2)+'" fill="none" stroke="#FF2D2D" stroke-width=".55"/>' +
      (picHref ? '<image href="'+picHref+'" x="'+(c+q-pr+.5)+'" y="'+(c+q-pr+.5)+'" width="'+((pr-.5)*2)+'" height="'+((pr-.5)*2)+'" preserveAspectRatio="xMidYMid slice" clip-path="url(#dzqc)"/>' : '') +
      '</svg>';
    return svg;
  }
  function toDataUrl(src){
    return fetch(src, {credentials:'same-origin'}).then(function(r){ if(!r.ok) throw 0; return r.blob(); }).then(function(b){
      return new Promise(function(res){ var fr = new FileReader(); fr.onload = function(){ res(fr.result); }; fr.readAsDataURL(b); });
    }).catch(function(){ return ''; });
  }
  function render(){
    if(built) return;
    loadLib(function(ok){
      if(!ok){ box.innerHTML = '<div class="dzqr-load">Couldn\'t load the code generator. Check your connection.</div>'; return; }
      toDataUrl(PIC).then(function(du){
        built = { text: link(), pic: du };
        box.innerHTML = makeSvg(built.text, du);
      });
    });
  }
  window.openProfileQr = function(){
    hp('light'); ov.classList.add('show'); ov.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden';
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ ov.classList.add('in'); }); });
    render();
  };
  window.closeProfileQr = function(){
    ov.classList.remove('in'); ov.setAttribute('aria-hidden', 'true'); document.body.style.overflow = '';
    setTimeout(function(){ if(!ov.classList.contains('in')) ov.classList.remove('show'); }, 240);
  };
  ov.addEventListener('click', function(e){ if(e.target === ov) closeProfileQr(); });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && ov.classList.contains('show')) closeProfileQr(); });

  /* PNG poster: dark card, glowing tile, the code, the name */
  function poster(){
    return new Promise(function(resolve, reject){
      if(!built) return reject();
      var W = 1080, H = 1350, cv = document.createElement('canvas'); cv.width = W; cv.height = H; var x = cv.getContext('2d');
      var g = x.createLinearGradient(0, 0, W, H); g.addColorStop(0, '#0d0f33'); g.addColorStop(.55, '#070924'); g.addColorStop(1, '#04051a'); x.fillStyle = g; x.fillRect(0, 0, W, H);
      x.fillStyle = 'rgba(255,255,255,.07)'; for(var i = 20; i < W; i += 40) for(var j = 20; j < 520; j += 40){ x.beginPath(); x.arc(i, j, 1.6, 0, 7); x.fill(); }
      x.textAlign = 'center'; x.fillStyle = 'rgba(255,255,255,.6)'; x.font = '700 30px Poppins, sans-serif';
      if('letterSpacing' in x) x.letterSpacing = '10px'; x.fillText('DEZZME CODE', W/2, 150); if('letterSpacing' in x) x.letterSpacing = '0px';
      var T = 760, tx = (W - T) / 2, ty = 215;
      x.save(); x.fillStyle = '#fff3ef';
      x.beginPath(); x.roundRect ? x.roundRect(tx, ty, T, T, 40) : x.rect(tx, ty, T, T); x.fill(); x.restore();
      var img = new Image();
      img.onload = function(){
        x.drawImage(img, tx + 40, ty + 40, T - 80, T - 80);
                x.fillStyle = '#fff'; x.font = '700 54px Poppins, sans-serif'; x.fillText(NAME, W/2, ty + T + 120);
        x.fillStyle = 'rgba(255,255,255,.55)'; x.font = '400 30px Poppins, sans-serif'; x.fillText('Scan to open this profile on Dezzme', W/2, ty + T + 175);
        cv.toBlob(function(b){ b ? resolve(b) : reject(); }, 'image/png');
      };
      img.onerror = reject;
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(makeSvg(built.text, built.pic).replace('<svg ', '<svg width="1000" height="1000" '));
    });
  }
  document.getElementById('dzQrSave').addEventListener('click', function(){
    hp('light');
    poster().then(function(b){
      var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'dezzme-' + (NAME || 'profile') + '-code.png';
      document.body.appendChild(a); a.click(); a.remove(); setTimeout(function(){ URL.revokeObjectURL(a.href); }, 4000);
      hp('success'); toast('Code saved');
    }).catch(function(){ hp('error'); toast('Couldn\'t save the image'); });
  });
  document.getElementById('dzQrShare').addEventListener('click', function(){
    hp('light');
    var url = link();
    poster().then(function(b){
      var f = new File([b], 'dezzme-code.png', {type:'image/png'});
      if(navigator.canShare && navigator.canShare({files:[f]})) return navigator.share({files:[f], title: NAME + ' on Dezzme', url: url});
      throw 0;
    }).catch(function(e){
      if(e && e.name === 'AbortError') return;
      if(navigator.share) return navigator.share({title: NAME + ' on Dezzme', url: url}).catch(function(){});
      if(navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(function(){ toast('Profile link copied!'); });
    });
  });
})();
</script>

<style>
/* ═══════════════════════════════════════════════════════════
   PROFILE KEBAB MODAL — same design language as Post / About Me
   • full-screen overlay, content in a CENTERED LANE
   • two grey lines separate the lane from the empty space
   • X at the top-left, title centered
   • icons sit inside small rounded red-tinted boxes, white words beside them
   • RED (#FF2D2D) + black theme, lightly rounded 5px shapes, fluid sizing
   Keeps every original class name, so your existing markup works as-is.
═══════════════════════════════════════════════════════════ */
.pkbm-overlay {
    --pk-red:#FF2D2D; --pk-red-dim:rgba(255,45,45,.14); --pk-red-line:rgba(255,45,45,.35);
    --pk-line:rgba(var(--fg,255,255,255),.09); --pk-line2:rgba(var(--fg,255,255,255),.14);
    --pk-sub:rgba(var(--fg,255,255,255),.62); --pk-mut:rgba(var(--fg,255,255,255),.45);
    --pk-pad:calc(clamp(9px,3vw,16px) * var(--md-s));
    --pk-r:5px;
    --pk-line-gap:calc(6px * var(--md-s));                  /* space between each grey row line and the vertical lane lines */
    --pk-row-h:calc(clamp(40px,6.2vh,52px) * var(--md-s));/* height of every option row */
    --pk-fs:calc(.8rem * var(--md-s));
    --pk-tb-h:calc(54px * var(--md-s));
    --pk-ic:28px;

    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999999;
    font-family: 'Poppins', sans-serif;
    font-size: var(--pk-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
    background: linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    background-attachment: fixed;
    overflow: hidden;            /* the scrolling now happens INSIDE the lane */
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);

}
@media (max-width:490px){ .pkbm-overlay{ --pk-fs:calc(.74rem * var(--md-s)); --pk-tb-h:calc(48px * var(--md-s)); --pk-ic:26px; --pk-row-h:calc(45px * var(--md-s)); } }
@media (max-width:390px){ .pkbm-overlay{ --pk-fs:calc(.7rem * var(--md-s));  --pk-tb-h:calc(44px * var(--md-s)); --pk-ic:24px; --pk-row-h:calc(41px * var(--md-s)); } }

.pkbm-overlay.pkbm-open { display: block; opacity: 1; transform: translateY(0); }

.pkbm-overlay *, .pkbm-overlay *::before, .pkbm-overlay *::after { box-sizing: border-box; }
.pkbm-overlay button { font-family: 'Poppins', sans-serif; cursor: pointer; -webkit-tap-highlight-color: transparent; outline: none; }
.pkbm-overlay a { outline: none; -webkit-tap-highlight-color: transparent; }

/* ── the lane + two grey lines ── */
.pkbm-col {
    width: 100%;
    max-width: 580px;
    margin: 0 auto;
    height: 100%;
    display: flex;
    flex-direction: column;
}
@media (min-width: 801px) {
    .pkbm-col { border-left: 1px solid var(--pk-line); border-right: 1px solid var(--pk-line); }
}
@media (max-width: 800px) { .pkbm-col { max-width: 100%; } }

/* ── Top bar: X left · title centered ── */
.pkbm-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: calc(6px * var(--md-s));
    min-height: var(--pk-tb-h);
    padding: env(safe-area-inset-top) calc(10px * var(--md-s)) 0;
    flex-shrink: 0;
    position: relative;
    z-index: 3;
    background: var(--lane,#070924);          /* single solid colour, no gradient, no line */
}
.pkbm-back-btn {
    width: calc(34px * var(--md-s)); height: calc(34px * var(--md-s)); padding: 0; margin: 0;
    background: none; border: none; color: var(--text,#F0F2FF);
    display: flex; align-items: center; justify-content: center;
    font-size: 0; line-height: 1; opacity: .9; flex-shrink: 0; transition: .2s;
}
.pkbm-back-btn:hover { color: var(--pk-red); opacity: 1; }
.pkbm-back-btn svg { width: calc(22px * var(--md-s)); height: calc(22px * var(--md-s)); display: block; }
.pkbm-back-btn > i { display: none; }            /* old arrow icon is replaced by the X */
.pkbm-topbar-title {
    flex: 1; min-width: 0; text-align: right; padding-right: calc(6px * var(--md-s));
    font-size: calc(.92rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pkbm-topbar-spacer { display: none; }
@media (max-width:490px){ .pkbm-topbar-title{ font-size:calc(.84rem * var(--md-s)); } .pkbm-back-btn{ width:calc(30px * var(--md-s)); } .pkbm-back-btn{ height:calc(30px * var(--md-s)); } }
@media (max-width:390px){ .pkbm-topbar-title{ font-size:calc(.8rem * var(--md-s)); } }

/* ── Body + rows ── */
.pkbm-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    padding: 0 0 env(safe-area-inset-bottom, 0px);
    display: flex;
    flex-direction: column;
    position: relative;
    /* scrollbar hidden on responsive sizes */
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.pkbm-body::-webkit-scrollbar { display: none; width: 0; height: 0; }
/* normal (desktop) view: thin grey scrollbar sitting on the lane's right line */
@media (min-width: 801px) {
    .pkbm-body { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.28) transparent; -ms-overflow-style: auto; scrollbar-gutter: stable both-edges; }
    .pkbm-body::-webkit-scrollbar { display: block; width: calc(4px * var(--md-s)); }
    .pkbm-body::-webkit-scrollbar-track { background: transparent; }
    .pkbm-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,.28); border-radius: calc(99px * var(--md-s)); }
    .pkbm-body::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.45); }
}

.pkbm-row {
    display: flex;
    align-items: center;
    gap: calc(14px * var(--md-s));
    width: 100%;
    position: relative;
    padding: calc(13px * var(--md-s)) calc(22px * var(--md-s));
    background: none;
    border: none;
    border-radius: 0;
    color: var(--text,#F0F2FF);
    text-decoration: none;
    text-align: left;
    font-family: 'Poppins', sans-serif;
    font-size: var(--pk-fs);
    font-weight: 500;
    line-height: 1.35;
    transition: background .2s;
}
/* thin grey line, stops just short of the two vertical side lines */
.pkbm-row::after, .pkbm-skel-row::after {
    content: ''; position: absolute; left: var(--pk-line-gap); right: var(--pk-line-gap); bottom: 0;
    height: 1px; background: var(--pk-line); pointer-events: none;
}
/* ── Dark mode toggle row ── */
.pkbm-theme-row{ cursor:pointer; user-select:none; -webkit-tap-highlight-color:transparent; }
.pkbm-theme-lbl{ flex:1; min-width:0; line-height:inherit; }
.pkbm-theme-row{ min-height:0; }
.pkbm-sw{ flex-shrink:0; margin-left:auto; width:calc(34px * var(--md-s)); height:calc(18px * var(--md-s)); border-radius:99px; background:var(--red,#FF2D2D); position:relative; transition:background .2s; }
.pkbm-sw-knob{ position:absolute; top:50%; left:calc(100% - 2px); transform:translate(-100%,-50%); width:calc(14px * var(--md-s)); height:calc(14px * var(--md-s)); border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.35); transition:left .2s, transform .2s; }
.pkbm-theme-row[aria-checked="false"] .pkbm-sw{ background:rgba(var(--fg,255,255,255),.22); }
.pkbm-theme-row[aria-checked="false"] .pkbm-sw-knob{ left:2px; transform:translate(0,-50%); }
.pkbm-row:hover { background: rgba(var(--fg,255,255,255),.05); color: var(--text,#F0F2FF); }
.pkbm-row:active { background: rgba(var(--fg,255,255,255),.08); }

/* icons: plain red glyph, no box (original look) */
.pkbm-row > i:first-child {
    font-size: calc(17px * var(--md-s));
    color: #FF2D2D;
    flex-shrink: 0;
    width: calc(20px * var(--md-s));
    text-align: center;
    transition: transform .2s;
}
.pkbm-label { min-width: 0; flex: 1 1 auto; display: flex; flex-wrap: wrap; align-items: baseline; column-gap: .55em; }
.pkbm-row:hover > i:first-child { transform: scale(1.2) rotate(10deg); }

.pkbm-row-danger { color: var(--pk-red); }
.pkbm-row-danger:hover { color: var(--pk-red); background: var(--pk-red-dim); }

.pkbm-form-row { margin: 0; }
.pkbm-form-row .pkbm-row { width: 100%; }

/* ══════════ SKELETON LOADER ══════════ */
.pkbm-content {
    opacity: 0;
    transform: translateY(4px);
    pointer-events: none;
    transition: opacity .35s ease, transform .35s ease;
}
.pkbm-content.pkbm-content-ready { opacity: 1; transform: translateY(0); pointer-events: auto; }

.pkbm-skeleton { display: flex; flex-direction: column; transition: opacity .25s ease; }
.pkbm-skeleton.pkbm-skel-hide { opacity: 0; pointer-events: none; position: absolute; inset: 0; }

.pkbm-skel-row {
    display: flex;
    align-items: center;
    gap: calc(14px * var(--md-s));
    position: relative;
    padding: calc(13px * var(--md-s)) calc(22px * var(--md-s));
}
.pkbm-skel-icon, .pkbm-skel-text {
    background: linear-gradient(90deg, rgba(255,255,255,.05) 25%, rgba(255,255,255,.14) 37%, rgba(255,255,255,.05) 63%);
    background-size: 400% 100%;
    animation: pkbmShimmer 1.4s ease-in-out infinite;
}
.pkbm-skel-icon { width: calc(17px * var(--md-s)); height: calc(17px * var(--md-s)); flex-shrink: 0; border-radius: calc(6px * var(--md-s)); margin: 0 1.5px; }
.pkbm-skel-text { height: calc(10px * var(--md-s)); border-radius: calc(4px * var(--md-s)); }
@keyframes pkbmShimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }

.pkbm-skel-row:nth-child(1)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(1)  .pkbm-skel-text { animation-delay: 0s; }
.pkbm-skel-row:nth-child(2)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(2)  .pkbm-skel-text { animation-delay: .05s; }
.pkbm-skel-row:nth-child(3)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(3)  .pkbm-skel-text { animation-delay: .1s; }
.pkbm-skel-row:nth-child(4)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(4)  .pkbm-skel-text { animation-delay: .15s; }
.pkbm-skel-row:nth-child(5)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(5)  .pkbm-skel-text { animation-delay: .2s; }
.pkbm-skel-row:nth-child(6)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(6)  .pkbm-skel-text { animation-delay: .25s; }
.pkbm-skel-row:nth-child(7)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(7)  .pkbm-skel-text { animation-delay: .3s; }
.pkbm-skel-row:nth-child(8)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(8)  .pkbm-skel-text { animation-delay: .35s; }
.pkbm-skel-row:nth-child(9)  .pkbm-skel-icon, .pkbm-skel-row:nth-child(9)  .pkbm-skel-text { animation-delay: .4s; }
.pkbm-skel-row:nth-child(10) .pkbm-skel-icon, .pkbm-skel-row:nth-child(10) .pkbm-skel-text { animation-delay: .45s; }
.pkbm-skel-row:nth-child(11) .pkbm-skel-icon, .pkbm-skel-row:nth-child(11) .pkbm-skel-text { animation-delay: .5s; }
.pkbm-skel-row:nth-child(12) .pkbm-skel-icon, .pkbm-skel-row:nth-child(12) .pkbm-skel-text { animation-delay: .55s; }

/* row spacing (gap between options) — each step reduced by 2px */
@media (max-width: 768px) { .pkbm-row, .pkbm-skel-row { padding: calc(11px * var(--md-s)) calc(18px * var(--md-s)); } }
@media (max-width: 480px) { .pkbm-row, .pkbm-skel-row { padding: calc(10px * var(--md-s)) calc(16px * var(--md-s)); } }
@media (max-width: 380px) { .pkbm-row, .pkbm-skel-row { padding: calc(8px * var(--md-s)) calc(13px * var(--md-s)); } }
@media (max-width: 330px) { .pkbm-row, .pkbm-skel-row { padding: calc(6px * var(--md-s)) calc(10px * var(--md-s)); } }

/* icon-to-label gap: 14px, 18px on small phones (390px and below) */
@media (max-width: 390px) { .pkbm-row, .pkbm-skel-row { gap: calc(18px * var(--md-s)); } }

/* responsive text + icon sizes — reduced a little (top bar X + username untouched) */
@media (max-width: 768px) { .pkbm-row { font-size: calc(12.25px * var(--md-s)); } .pkbm-row > i:first-child { font-size: calc(15.5px * var(--md-s)); width: calc(20px * var(--md-s)); } }
@media (max-width: 480px) { .pkbm-row { font-size: calc(11.75px * var(--md-s)); } .pkbm-row > i:first-child { font-size: calc(15px * var(--md-s));   width: calc(20px * var(--md-s)); } }
@media (max-width: 380px) { .pkbm-row { font-size: calc(11.25px * var(--md-s)); } .pkbm-row > i:first-child { font-size: calc(13.5px * var(--md-s)); width: calc(17px * var(--md-s)); } }
@media (max-width: 360px) { .pkbm-row { font-size: calc(10.75px * var(--md-s)); } .pkbm-row > i:first-child { font-size: calc(13px * var(--md-s));   width: calc(16px * var(--md-s)); } }
@media (max-width: 330px) { .pkbm-row { font-size: calc(10.25px * var(--md-s)); } .pkbm-row > i:first-child { font-size: calc(12.5px * var(--md-s)); width: calc(15px * var(--md-s)); } }

/* every option row is EXACTLY the same height, so the grey lines are evenly spaced.
   Block / form rows are left as they were (a form holding one row-button) and just follow the same height. */
.pkbm-row, .pkbm-skel-row { height: var(--pk-row-h); }
.pkbm-content, .pkbm-skeleton:not(.pkbm-skel-hide) { flex: 0 0 auto; display: flex; flex-direction: column; min-height: 0; }
.pkbm-content > .pkbm-row,
.pkbm-content > .pkbm-form-row,
.pkbm-skeleton:not(.pkbm-skel-hide) > .pkbm-skel-row { flex: 0 0 var(--pk-row-h); height: var(--pk-row-h); min-height: 0; }
.pkbm-content > .pkbm-form-row { display: flex; }
.pkbm-content > .pkbm-form-row > .pkbm-row,
.pkbm-content > .pkbm-form-row > button { flex: 1 1 auto; height: 100%; }

/* desktop: rows scale with the window height so everything fits without a scrollbar */
@media (min-width: 801px) {
    .pkbm-row, .pkbm-skel-row { padding-top: calc(clamp(5px, 1.4vh, 13px) * var(--md-s)); padding-bottom: calc(clamp(5px, 1.4vh, 13px) * var(--md-s)); }
}

@media (prefers-reduced-motion: reduce) {
    .pkbm-overlay, .pkbm-content, .pkbm-skeleton, .pkbm-skel-icon, .pkbm-skel-text { transition: none; animation-duration: .01ms !important; }
}
</style>

<script>
var pkbmSkeletonShown = false; // tracks whether skeleton already played this page load

/* Put each row's text in one label span, so the flex gap only sits between icon and text
   (otherwise multi-part labels like "Copy <name>'s link" get the gap between their words). */
function pkbmWrapLabels() {
    document.querySelectorAll('.pkbm-row').forEach(function (row) {
        if (row.querySelector(':scope > .pkbm-label')) return;
        var icon = row.querySelector(':scope > i');
        var label = document.createElement('span');
        label.className = 'pkbm-label';
        Array.prototype.slice.call(row.childNodes).forEach(function (n) {
            if (n !== icon) label.appendChild(n);
        });
        if (label.textContent.trim() === '' && !label.querySelector('*')) return;
        row.appendChild(label);
    });
}

/* Replace the old back arrow with the same X used by the other modals */
document.addEventListener('DOMContentLoaded', function () {
    pkbmWrapLabels();
    document.querySelectorAll('.pkbm-back-btn').forEach(function (b) {
        b.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>';
        b.setAttribute('aria-label', 'Close');
    });
});

function openProfileKebabModal() {
    dzHp('light');
    var m = document.getElementById('profileKebabModal');
    if (!m) return;

    pkbmWrapLabels();
    var skeleton = document.getElementById('pkbmSkeleton');
    var content  = document.getElementById('pkbmContent');

    m.style.display = 'block';
    m.scrollTop = 0;
    var scroller = m.querySelector('.pkbm-body');
    if (scroller) scroller.scrollTop = 0;
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () {
        requestAnimationFrame(function () { m.classList.add('pkbm-open'); });
    });

    if (pkbmSkeletonShown) {
        // Already loaded once this page — skip straight to content, no shimmer
        if (skeleton) skeleton.classList.add('pkbm-skel-hide');
        if (content)  content.classList.add('pkbm-content-ready');
        return;
    }

    // First open this page load — show the skeleton
    if (skeleton) skeleton.classList.remove('pkbm-skel-hide');
    if (content)  content.classList.remove('pkbm-content-ready');

    var minDelay = 450;
    setTimeout(function () {
        if (skeleton) skeleton.classList.add('pkbm-skel-hide');
        if (content)  content.classList.add('pkbm-content-ready');
        pkbmSkeletonShown = true;
    }, minDelay);
}

/* ── Dark / light theme (dark = default). Saved in this browser; the pages read it before they paint. ── */
(function(){
    function isLight(){ return document.documentElement.getAttribute('data-theme')==='light'; }
    function sync(){ var r=document.getElementById('pkbmThemeRow'); if(r) r.setAttribute('aria-checked', isLight()?'false':'true'); }
    window.dzApplyTheme=function(light){
        if(light) document.documentElement.setAttribute('data-theme','light'); else document.documentElement.removeAttribute('data-theme');
        try{ localStorage.setItem('dz_theme', light?'light':'dark'); }catch(e){}
        try{ var fd=new FormData(); fd.set('theme', light?'light':'dark'); fetch('theme_css.php?set=1',{method:'POST',body:fd,credentials:'same-origin',keepalive:true}).catch(function(){}); }catch(e){}
        try{ document.cookie='dz_theme='+(light?'light':'dark')+'; path=/; max-age=31536000; SameSite=Lax'; }catch(e){}
        try{ var m=document.querySelector('meta[name="theme-color"]'); if(m) m.setAttribute('content', light?'#ffffff':'#070924'); }catch(e){}
        sync();
    };
    window.dzToggleTheme=function(){ window.dzApplyTheme(!isLight()); };
    document.addEventListener('DOMContentLoaded', sync);
    sync();
})();
function closeProfileKebabModal() {
    var m = document.getElementById('profileKebabModal');
    if (!m) return;
    m.classList.remove('pkbm-open');
    setTimeout(function () {
        m.style.display = 'none';
        document.body.style.overflow = '';
    }, 270);
}

/* Backwards-compatible names, in case old buttons still call them */
function openKebabModal()  { openProfileKebabModal(); }
function closeKebabModal() { closeProfileKebabModal(); }

function copyProfileLinkPK(profileId) {
    dzHp('success');
    var link = window.location.origin + '/profile.php?user_id=' + profileId;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(function () {
            if (typeof showToast === 'function') {
                showToast('Profile link copied!');
            } else {
                alert('Profile link copied!');
            }
        }).catch(function () {
            alert('Could not copy link. Long-press the URL bar to copy manually.');
        });
    } else {
        var tempInput = document.createElement('input');
        tempInput.value = link;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        document.body.removeChild(tempInput);
        alert('Profile link copied!');
    }
}
function copyProfileLink(profileId) { copyProfileLinkPK(profileId); } // old name

function shareProfile(userId) {
    dzHp('light');
    const url = window.location.origin + '/profile.php?user_id=' + userId;

    if (navigator.share) {
        navigator.share({
            title: 'Check out this profile on Peura',
            url: url
        }).catch(err => console.log(err));
    } else {
        // fallback: copy to clipboard
        navigator.clipboard.writeText(url).then(() => {
            let notif = document.createElement('div');
            notif.innerText = 'Profile link copied to clipboard!';
            notif.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: #e83939;
                color: #fff;
                padding: 12px 20px;
                border-radius: 12px;
                font-weight: 600;
                z-index: 3000000000000;
                box-shadow: 0 4px 15px rgba(0,0,0,0.3);
                opacity: 0;
                transition: 0.3s;
            `;
            document.body.appendChild(notif);
            setTimeout(() => { notif.style.opacity = 1; }, 50);
            setTimeout(() => { notif.style.opacity = 0; notif.remove(); }, 2500);
        });
    }
}

/* NOTE: the old reportUser() that lived here was removed on purpose.
   Reporting now goes through the report modal: openReportModal({userId: id})
   (the report modal also defines window.reportUser(id) for old callers). */

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeProfileKebabModal();
});
</script>

<?php /* ── Highlights modal is LAZY: its markup, css and script sit inside this inert <template> (parsed by the browser but never run / never painted) and are activated the first time openHighlightsModal() is called, or when the page opens with ?hl=ID. Same PHP scope as before, so nothing inside changed. ── */ ?>
<script>
(function(){
  var loaded = false, loading = false, waiting = [];
  function activate(){
    var tpl = document.getElementById('dzHlTpl');
    if (!tpl || loaded) return;
    loaded = true;
    var frag = tpl.content.cloneNode(true);
    var scripts = Array.prototype.slice.call(frag.querySelectorAll('script'));
    scripts.forEach(function(s){ s.parentNode.removeChild(s); });
    document.body.appendChild(frag);
    scripts.forEach(function(old){          /* scripts made by cloning do not run — recreate them in order */
      var s = document.createElement('script');
      s.text = old.textContent;
      document.body.appendChild(s);
    });
  }
  var stub = function(id){
    if (!loaded) activate();
    if (window.openHighlightsModal && window.openHighlightsModal !== stub) return window.openHighlightsModal(id);
    console.error('Highlights modal failed to load');
  };
  window.openHighlightsModal = stub;
  window.dzHlEnsure = activate;
  /* deep link ?hl=ID — the real script only registers its own DOMContentLoaded handler if it loads before that event, so the stub covers it */
  document.addEventListener('DOMContentLoaded', function(){
    var id = new URLSearchParams(location.search).get('hl');
    if (id) setTimeout(function(){ window.openHighlightsModal(id); }, 600);
  });
})();
</script>
<template id="dzHlTpl">
<?php /* ════════════════════════════════════════════════════════════════
   PART 2 — HTML + CSS + JS
   PASTE right before </body> in profile.php (replaces the old modal).
   ════════════════════════════════════════════════════════════════ */ ?>

<div id="hlModal" onclick="if(event.target===this) hlClose()">
 <div id="hlScroll" onclick="if(event.target===this) hlClose()">
  <div id="hlWrap">

    <!-- prev / next highlight arrows (normal view only; first / middle / last aware) -->
    <button type="button" class="hl-nav hl-nav-prev off" id="hlNavPrev" onclick="hlGoHl(-1)" aria-label="Previous highlight"><i class="ri-arrow-left-s-line"></i></button>
    <button type="button" class="hl-nav hl-nav-next off" id="hlNavNext" onclick="hlGoHl(1)" aria-label="Next highlight"><i class="ri-arrow-right-s-line"></i></button>

    <div id="hlStage">

      <!-- 3D scene: the live screen and the incoming highlight sit on the faces of a cube -->
      <div id="hlScene">
        <div id="hlCube">
          <div id="hlFace">

            <div id="hlMedia"></div>
            <div class="hl-shade-top"></div>
            <div class="hl-shade-bottom"></div>

            <!-- tap zones -->
            <div class="hl-zone hl-zone-prev" onclick="dzHp('light');hlPrev()"></div>
            <div class="hl-zone hl-zone-next" onclick="dzHp('light');hlNext()"></div>

            <!-- text written on the media (or a text-only highlight on Text.jpg) -->
            <div id="hlCapLayer" class="hl-cap-layer"></div>

            <!-- the highlight's name: shows over a dark layer for a moment when it opens, then fades away (like TikTok / Instagram) -->
            <div id="hlIntro" class="hl-intro" aria-hidden="true"><span id="hlIntroTxt" class="hl-intro-txt"></span></div>

            <!-- top: dashes + header -->
            <div class="hl-top">
              <div class="hl-dashes" id="hlDashes"></div>
              <div class="hl-head">
                <div class="hl-av-col">
                  <img id="hlAvatar" class="hl-avatar" src="mee.jpg" alt="" onerror="this.onerror=null;this.src='mee.jpg'">
                </div>
                <div class="hl-head-info">
                  <div class="hl-title-row">
                    <span id="hlTitle" class="hl-title"></span>
                    <span id="hlDate" class="hl-date"></span>
                  </div>
                </div>
                <button type="button" class="hl-icon-btn" id="hlSnd" onclick="hlSound()" aria-label="Sound" style="display:none"><i class="dz-snd on"></i></button>
                <button type="button" class="hl-icon-btn hl-menu" onclick="hlOpenSheet()" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M2 6h20M2 12h20M2 18h20"/></svg></button>
                <div class="hl-x-col">
                  <button type="button" class="hl-icon-btn hl-x" onclick="hlClose()" aria-label="Close"><i class="ri-close-line"></i></button>
                  <span id="hlCount" class="hl-count"></span>
                </div>
              </div>
              <div id="hlSong" class="hl-songline"></div>
            </div>

            <!-- popping messages -->
            <div id="hlPops"></div>

            <!-- every media of this highlight: scroll sideways, tap one to jump to it, ✕ to close -->
            <div id="hlMediaPanel" class="hl-mpanel" aria-hidden="true">
              <div class="hl-mp-head">
                <span id="hlMpTitle" class="hl-mp-title"></span>
                <button type="button" class="hl-mp-x" onclick="hlMediaClose()" aria-label="Close"><i class="ri-close-line"></i></button>
              </div>
              <div id="hlMpStrip" class="hl-mp-strip"></div>
            </div>

            <!-- bottom: message bar + like + share -->
            <div class="hl-bottom">
              <div class="hl-topcm" id="hlTopCm" onclick="hlOpenTopComment()" role="button" tabindex="0" style="display:none"><span class="hl-topcm-av"><img id="hlTopCmAv" src="mee.jpg" alt=""></span><span class="hl-topcm-tx" id="hlTopCmTx"></span></div>
              <div class="hl-bar" onclick="hlOpenComments(true)"><span class="hl-fake" id="hlBarTxt">Add a comment…</span></div>
              <button type="button" id="hlLikeBtn" class="ipv-action-btn hl-ab" onclick="hlLike()" aria-label="Like">
                <i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i><span id="hlLikeCount" class="ipv-action-count"></span>
              </button>
              <button type="button" id="hlMediaBtn" class="ipv-action-btn hl-ab" onclick="hlMediaToggle()" aria-label="All media in this highlight">
                <i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="7" width="13" height="14" rx="2.6"/><path d="M8 3.4h11.2A2.8 2.8 0 0 1 22 6.2V17"/></svg></i><span id="hlMediaCount" class="ipv-action-count"></span>
              </button>
              <button type="button" id="hlShareBtn" class="ipv-action-btn hl-ab" onclick="hlShare()" aria-label="Share">
                <i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i>
              </button>
            </div>

          </div><!-- /#hlFace -->
        </div><!-- /#hlCube -->
      </div><!-- /#hlScene -->

      <div id="hlLoader"><div class="hl-spin"></div></div>

      <!-- bottom sheet -->
      <div id="hlSheetOv" onclick="hlCloseSheet()"></div>
      <div id="hlSheet">
        <div class="dzm-handle"><span></span></div>
        <div class="dzm-card"><div class="dzm-scroll">
          <div class="dzm-header">Highlight</div>
          <div id="hlMyCm"></div>
          <div id="hlSheetList"></div>
        </div></div>
        <button type="button" class="dzm-close-btn" onclick="hlCloseSheet()">Cancel</button>
      </div>

      <div id="hlCmOv" onclick="hlCloseComments()"></div>
    <!-- ═════ COMMENTS bottom sheet (opened from the bottom bar or the ⋯ menu) ═════ -->
      <section id="hlComments" aria-label="Comments">
        <div class="hl-cm-top">
          <div class="dzm-handle"><span></span></div>
          <div class="hl-cm-head">
            <div class="hl-cm-title"><i class="ri-question-answer-fill"></i>Comments<span id="hlCmCount" class="hl-cm-pill"></span></div>
            <div class="hl-cm-sort">
              <button type="button" class="hl-cm-sortbtn" id="hlCmSortBtn"><span id="hlCmSortLbl">Newest</span><i class="ri-arrow-down-s-line"></i><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h11M4 17h6"/></svg></button>
              <div class="hl-cm-sortmenu" id="hlCmSortMenu">
                <button type="button" class="active" data-sort="new">Newest first</button>
                <button type="button" data-sort="old">Oldest first</button>
              </div>
            </div>
            <button type="button" class="hl-cm-hb" onclick="hlCloseComments()" aria-label="Close"><i class="ri-close-line"></i></button>
          </div>
        </div>
        <div class="hl-cm-note"><i class="ri-shield-check-line"></i><span>Remember to keep comments respectful.</span></div>
        <div id="hlCmList"></div>
        <div id="hlCmCompose" class="hl-cm-composer">
          <div class="hl-reply-chip" id="hlReplyChip"><span>Replying to</span><b></b><button type="button" class="hl-reply-x" data-cancel-reply="1" aria-label="Cancel reply"><i class="ri-close-line"></i></button></div>
          <div class="hl-emo" id="hlEmo"><span>❤️</span><span>🙌</span><span>🔥</span><span>👏</span><span>😢</span><span>😍</span><span>😮</span><span>😂</span></div>
          <div class="hl-compose-row"><span class="hl-cmy"><img id="hlCmMyPic" src="mee.jpg" alt="" onerror="this.onerror=null;this.src='mee.jpg'"></span>
            <input type="text" id="hlCIn" maxlength="200" placeholder="Add a comment…" autocomplete="off" enterkeyhint="send">
            <button type="button" class="hl-csend" id="hlCSend" aria-label="Post comment" disabled><svg viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M236.2,218.31A15.88,15.88,0,0,1,224,224a16.22,16.22,0,0,1-5.37-.92l-79.95-27a4,4,0,0,1-2.72-3.79V120a8,8,0,0,0-8.53-8,8.19,8.19,0,0,0-7.47,8.26v72a4,4,0,0,1-2.72,3.79l-79.95,27a16,16,0,0,1-19.26-22.92L114,32.13a16,16,0,0,1,27.89,0L237.9,200.1A15.89,15.89,0,0,1,236.2,218.31Z"/></svg></button>
          </div>
        </div>
        <div class="hl-cm-menu" id="hlCmMenu" role="menu">
          <button type="button" data-act="delete" class="danger" role="menuitem"><i class="ri-delete-bin-line"></i><span>Delete comment</span></button>
        </div>
      </section>

    </div><!-- /#hlStage -->

  </div><!-- /#hlWrap -->
 </div><!-- /#hlScroll -->
</div>

<style>
#hlModal{
  display:none; position:fixed; inset:0; z-index:9999999;
  background:rgba(0,0,0,.94); -webkit-backdrop-filter:blur(14px); backdrop-filter:blur(14px);
  opacity:0; transition:opacity .15s ease; font-family:'Poppins',sans-serif;
}
#hlModal.open{ display:block; }
/* the whole modal scrolls: box on top, comments underneath (same as the group highlights viewer) */
#hlScroll{ position:absolute; inset:0; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; scrollbar-width:thin; scrollbar-color:rgba(255,45,85,.45) transparent; }
#hlScroll::-webkit-scrollbar{ width:calc(5px * var(--md-s)); }
#hlScroll::-webkit-scrollbar-thumb{ background:rgba(255,45,85,.45); border-radius:calc(99px * var(--md-s)); }
#hlModal.vis{ opacity:1; }

/* wrapper: holds the box AND the side arrows (arrows sit outside the box, so the box can clip its own corners) */
#hlWrap{
  --hl-stage-h:calc(100vh - calc(64px * var(--md-s)));
  position:relative; width:100%; max-width:480px; margin:0 auto;
  background:var(--lane,#070924);
  transform:scale(.985) translateY(6px); transition:transform .18s cubic-bezier(.25,.1,.25,1);
}
@supports (height:100dvh){ #hlWrap{ --hl-stage-h:calc(100dvh - calc(64px * var(--md-s))); } }
#hlModal.vis #hlWrap{ transform:none; }

#hlStage{
  position:relative; width:100%; height:var(--hl-stage-h);
  background:#000; overflow:hidden; touch-action:pan-y;
  -webkit-user-select:none; user-select:none;
}
@media(min-width:791px){
  #hlWrap{ --hl-stage-h:min(86vh,900px); margin:3vh auto; border-radius:calc(22px * var(--md-s));
    box-shadow:0 25px 90px rgba(0,0,0,.65),0 0 0 1px rgba(var(--fg,255,255,255),.07); }
  #hlStage{ border-radius:calc(22px * var(--md-s)) calc(22px * var(--md-s)) 0 0;
    clip-path:inset(0 round 22px 22px 0 0); -webkit-clip-path:inset(0 round 22px 22px 0 0); }
}

/* ═══ PREV / NEXT HIGHLIGHT ARROWS (normal view only) — grey, grey on hover ═══ */
.hl-nav{
  position:absolute; top:calc(var(--hl-stage-h) / 2); transform:translateY(-50%); z-index:20;
  width:calc(44px * var(--md-s)); height:calc(44px * var(--md-s)); padding:0; border-radius:50%;
  border:1px solid rgba(255,255,255,.12); background:rgba(36,36,40,.92);
  -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);
  color:#fff; display:flex; align-items:center; justify-content:center; font-size:calc(26px * var(--md-s)); cursor:pointer;
  box-shadow:0 6px 20px rgba(0,0,0,.5);
  transition:opacity .2s ease, background .2s ease, transform .15s ease;
  -webkit-tap-highlight-color:transparent;
}
.hl-nav i{ line-height:1; }
.hl-nav:hover{ background:rgba(66,66,72,.96); }
.hl-nav:active{ transform:translateY(-50%) scale(.92); background:rgba(80,80,86,.96); }
.hl-nav.off{ opacity:0; pointer-events:none; }
.hl-nav-prev{ left:calc(-58px * var(--md-s)); }
.hl-nav-next{ right:calc(-58px * var(--md-s)); }

/* ═══ 3D CUBE (flip between highlights) ═══
   Normally nothing is transformed. While flipping, JS adds .flip and places
   the live screen and the incoming highlight on two faces of a cube, then
   rotates the cube (following your finger, or automatically). */
#hlScene{ position:absolute; inset:0; z-index:1; perspective:1000px; -webkit-perspective:1000px; overflow:hidden; background:#000; }
#hlCube{ position:absolute; inset:0; transform-origin:50% 50%; }
#hlCube.flip{ transform-style:preserve-3d; -webkit-transform-style:preserve-3d; will-change:transform; }
#hlCube.flip > *{ backface-visibility:hidden; -webkit-backface-visibility:hidden; }
#hlFace{ position:absolute; inset:0; overflow:hidden; background:#000; }
.hl-peek{ position:absolute; inset:0; overflow:hidden; background:#000; pointer-events:none; }

/* media box: smart-fit, plain black behind */
#hlMedia,.hl-peek-media{ position:absolute; inset:0; background:#000; overflow:hidden; }
.hl-media-item{ position:relative; z-index:1; width:100%; height:100%; object-fit:contain; display:block; background:transparent; }

.hl-shade-top,.hl-shade-bottom{ position:absolute; left:0; right:0; pointer-events:none; z-index:2; }
.hl-shade-top{ top:0; height:calc(120px * var(--md-s)); background:linear-gradient(to bottom,rgba(0,0,0,.65),transparent); }
.hl-shade-bottom{ bottom:0; height:calc(160px * var(--md-s)); background:linear-gradient(to top,rgba(0,0,0,.75),transparent); }

.hl-zone{ position:absolute; top:calc(84px * var(--md-s)); bottom:calc(74px * var(--md-s)); z-index:3; -webkit-tap-highlight-color:transparent; }
.hl-zone-prev{ left:0; width:35%; }
.hl-zone-next{ right:0; width:65%; }

/* ═══ TEXT ON MEDIA (@user red, group blue, more / less) ═══ */
.hl-cap-layer{ position:absolute; inset:0; z-index:4; pointer-events:none; }
.hl-cap-wrap{ position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:calc(6px * var(--md-s)); padding:calc(104px * var(--md-s)) calc(18px * var(--md-s)) calc(84px * var(--md-s)); text-align:center; min-height:0; pointer-events:none; }
.hl-cap{ pointer-events:auto; max-width:100%; min-height:0; padding:calc(10px * var(--md-s)) calc(13px * var(--md-s)); border-radius:calc(12px * var(--md-s)); background:rgba(0,0,0,.34); color:#fff; font-size:calc(15px * var(--md-s)); font-weight:600; line-height:1.5; text-shadow:0 1px 6px rgba(0,0,0,.85); word-break:break-word; overflow-wrap:anywhere; -webkit-user-select:text; user-select:text; }
.hl-cap.clamp{ display:-webkit-box; -webkit-line-clamp:4; -webkit-box-orient:vertical; overflow:hidden; }
.hl-cap:not(.clamp){ max-height:100%; overflow-y:auto; scrollbar-width:none; }
.hl-cap:not(.clamp)::-webkit-scrollbar{ display:none; }
.hl-more{ pointer-events:auto; flex-shrink:0; background:rgba(0,0,0,.5); border:1px solid rgba(255,255,255,.25); border-radius:calc(99px * var(--md-s)); color:#fff; font-family:inherit; font-size:calc(11px * var(--md-s)); font-weight:600; padding:calc(3px * var(--md-s)) calc(12px * var(--md-s)); cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-more:hover{ background:rgba(0,0,0,.7); }
.hl-men{ font-weight:700; text-decoration:none; cursor:pointer; text-shadow:0 1px 5px rgba(0,0,0,.9); }
.hl-men.user{ color:#ff3b4e; }
.hl-men.group{ color:#3ea6ff; }
.hl-men:hover{ text-decoration:underline; }

.hl-top{ position:absolute; top:0; left:0; right:0; z-index:5; padding:calc(8px * var(--md-s)) calc(10px * var(--md-s)) 0; pointer-events:none; }
.hl-top > *{ pointer-events:auto; }

/* dashes / progress (images AND videos) */
.hl-dashes{ display:flex; gap:calc(3px * var(--md-s)); }
.hl-dash{ flex:1 1 0; height:calc(3px * var(--md-s)); border-radius:2px; background:rgba(255,255,255,.3); overflow:hidden; }
.hl-dash-fill{ height:100%; width:0; background:#ff2b2b; border-radius:2px; }
.hl-dash.done .hl-dash-fill{ width:100%; }

/* header: count under the avatar, original pill look */
.hl-head{ display:flex; align-items:flex-start; gap:calc(9px * var(--md-s)); margin-top:calc(9px * var(--md-s)); }
.hl-av-col{ display:flex; flex-direction:column; align-items:center; gap:calc(5px * var(--md-s)); flex-shrink:0; }
.hl-avatar{ width:calc(34.6px * var(--md-s)); height:calc(34.6px * var(--md-s)); border-radius:50%; object-fit:cover; border:2.5px solid #ff2b2b; }
.hl-songline{ margin-top:calc(-8px * var(--md-s)); max-width:calc(100% - calc(56px * var(--md-s))); color:rgba(255,255,255,.8); font-size:calc(10.1px * var(--md-s)); font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-songline:empty{ display:none; }
.hl-x-col{ display:flex; flex-direction:column; align-items:center; gap:calc(4px * var(--md-s)); flex-shrink:0; }
.hl-count{ background:rgba(22,22,24,.7); color:#fff; font-size:calc(10px * var(--md-s)); font-weight:800; padding:2px calc(7px * var(--md-s)); border-radius:calc(6px * var(--md-s)); }
.hl-head-info{ flex:1; min-width:0; padding-top:1px; }
/* highlight name intro: dark layer + big name, fades in, holds, fades out; never blocks a tap */
.hl-intro{ position:absolute; inset:0; z-index:4; display:flex; align-items:center; justify-content:center; padding:0 12%; background:rgba(0,0,0,.58); opacity:0; pointer-events:none; text-align:center; }
.hl-intro.show{ animation:hlIntroAnim 2.2s ease forwards; }
.hl-intro-txt{ color:#fff; font-weight:700; font-size:calc(clamp(20px, 4.2vw + 8px, 30px) * var(--md-s)); line-height:1.25; letter-spacing:-.2px; text-shadow:0 2px 12px rgba(0,0,0,.6); word-break:break-word; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
@keyframes hlIntroAnim{ 0%{ opacity:0; } 14%{ opacity:1; } 72%{ opacity:1; } 100%{ opacity:0; } }
.hl-title-row{ display:flex; flex-direction:column; align-items:flex-start; gap:1px; min-width:0; }
.hl-title{ min-width:0; max-width:100%; color:#fff; font-weight:600; font-size:calc(12.5px * var(--md-s)); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-date{ flex-shrink:0; color:rgba(255,255,255,.72); font-size:calc(10.1px * var(--md-s)); white-space:nowrap; text-shadow:0 1px 4px rgba(0,0,0,.6); }

/* menu + close: same box, X glyph scaled so it looks as big as the menu lines */
.hl-icon-btn{ --hl-ic:calc(21px * var(--md-s)); background:none; border:none; color:#fff; cursor:pointer; width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); padding:0; display:flex; align-items:center; justify-content:center; -webkit-tap-highlight-color:transparent; }
.hl-icon-btn i{ font-size:var(--hl-ic); line-height:1; filter:drop-shadow(0 2px 5px rgba(0,0,0,.7)); }
.hl-icon-btn svg{ width:var(--hl-ic); height:var(--hl-ic); display:block; filter:drop-shadow(0 2px 5px rgba(0,0,0,.7)); }
.hl-icon-btn.hl-x i{ font-size:calc(var(--hl-ic) * 1.4); }
/* menu icon: same wide three-line icon as the group highlights */
.hl-icon-btn.hl-menu svg{ width:calc(var(--hl-ic) * 1.02); height:calc(var(--hl-ic) * 1.02); }
.hl-icon-btn:active{ transform:scale(.88); opacity:.7; }

/* popping comments: avatar + name, then the comment in a dark grey bubble */
#hlPops{ position:absolute; left:calc(12px * var(--md-s)); right:calc(12px * var(--md-s)); top:calc(110px * var(--md-s)); bottom:calc(84px * var(--md-s)); z-index:6; pointer-events:none; }
.hl-pop{
  position:absolute; display:flex; flex-direction:column; align-items:flex-start; gap:calc(4px * var(--md-s)); max-width:75%;
  color:#fff; animation:hlPopIn .4s cubic-bezier(.2,1.5,.4,1) both;
}
.hl-pop.out{ animation:hlPopOut .55s ease-in forwards; }
.hl-pop-who{ display:flex; align-items:center; gap:calc(6px * var(--md-s)); text-shadow:0 1px 5px rgba(0,0,0,.85); }
.hl-pop-who img{ width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); box-sizing:border-box; border-radius:50%; object-fit:cover; border:2px solid #ff2b2b; flex-shrink:0; }
.hl-pop-who b{ font-weight:700; font-size:.92em; }
.hl-pop-bub{ position:relative; margin-left:calc(8px * var(--md-s)); background:rgba(48,48,54,.72); border-radius:0 calc(14px * var(--md-s)) calc(14px * var(--md-s)) calc(14px * var(--md-s)); }
.hl-pop-bub::before{ content:''; position:absolute; top:0; left:calc(-8px * var(--md-s)); width:0; height:0; border-top:10px solid rgba(48,48,54,.72); border-left:8px solid transparent; }
.hl-pop-txt{ padding:calc(6px * var(--md-s)) calc(11px * var(--md-s)); line-height:1.35; font-weight:500; word-break:break-word; display:-webkit-box; -webkit-line-clamp:6; -webkit-box-orient:vertical; overflow:hidden; }
@keyframes hlPopIn{ from{ opacity:0; transform:translateY(10px) scale(.4) rotate(var(--r,0deg)); } to{ opacity:1; transform:rotate(var(--r,0deg)); } }
@keyframes hlPopOut{ to{ opacity:0; transform:translateY(-26px) scale(.94) rotate(var(--r,0deg)); } }

/* bottom: message bar + like + share */
.hl-bottom{ position:absolute; left:0; right:0; bottom:0; z-index:7; display:flex; align-items:center; gap:calc(10px * var(--md-s)); padding:calc(10px * var(--md-s)) calc(12px * var(--md-s)) calc(calc(12px * var(--md-s)) + env(safe-area-inset-bottom)); }
.hl-bar{ flex:1; min-width:0; display:flex; align-items:center; height:calc(38px * var(--md-s)); padding:0 calc(4px * var(--md-s)) 0 calc(14px * var(--md-s)); border-radius:999px; background:rgba(255,255,255,.12); -webkit-backdrop-filter:blur(12px); backdrop-filter:blur(12px); border:none; transition:background .2s; }
.hl-bar:focus-within{ background:rgba(255,255,255,.18); }
#hlInput{ flex:1; min-width:0; background:none; border:none; outline:none; color:var(--text,#F0F2FF); font-family:inherit; font-size:calc(12.5px * var(--md-s)); }
#hlInput::placeholder{ color:rgba(var(--fg,255,255,255),.55); }
.hl-fake{ flex:1; min-width:0; color:rgba(255,255,255,.55); font-size:calc(12.5px * var(--md-s)); white-space:nowrap; overflow:hidden; }
.hl-send{ width:calc(32px * var(--md-s)); height:calc(32px * var(--md-s)); border:none; background:none; color:#ff2d55; display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0; opacity:0; transform:scale(.6); pointer-events:none; transition:opacity .15s, transform .15s; flex-shrink:0; -webkit-tap-highlight-color:transparent; }
.hl-send.show{ opacity:1; transform:none; pointer-events:auto; }
.hl-send:active{ transform:scale(.85); }
.hl-send i{ font-size:calc(24px * var(--md-s)); line-height:1; }

.hl-act{ background:none; border:none; color:#fff; cursor:pointer; display:flex; align-items:center; gap:calc(4px * var(--md-s)); font-family:inherit; font-size:calc(12px * var(--md-s)); font-weight:800; -webkit-tap-highlight-color:transparent; text-shadow:0 1px 4px rgba(0,0,0,.7); padding:0; transition:color .18s ease; }
.hl-act i{ font-size:calc(25px * var(--md-s)); filter:drop-shadow(0 2px 5px rgba(0,0,0,.6)); transition:transform .18s ease, color .18s ease; }
.hl-act:active i{ transform:scale(1.25); }
.hl-act.liked, .hl-act.liked i{ color:#ff2d55; }
.hl-act svg{ width:calc(23px * var(--md-s)); height:calc(23px * var(--md-s)); flex-shrink:0; filter:drop-shadow(0 2px 5px rgba(0,0,0,.6)); transition:transform .18s ease; }
.hl-act:active svg{ transform:scale(1.2); }
.hl-act.on, .hl-act.on svg{ color:#fff; }
.hl-act.on svg{ filter:drop-shadow(0 0 6px rgba(255,255,255,.55)); }

/* ═══ ALL MEDIA PANEL — sits just above the message bar ═══ */
.hl-mpanel{
  position:absolute; left:0; right:0; bottom:calc(calc(60px * var(--md-s)) + env(safe-area-inset-bottom)); z-index:9;
  padding:calc(10px * var(--md-s)) 0 calc(12px * var(--md-s));
  background:linear-gradient(to top, rgba(0,0,0,.78), rgba(0,0,0,.62) 70%, rgba(0,0,0,0));
  -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px);
  opacity:0; transform:translateY(14px); pointer-events:none;
  transition:opacity .2s ease, transform .22s cubic-bezier(.22,1,.36,1);
}
.hl-mpanel.show{ opacity:1; transform:none; pointer-events:auto; }
.hl-mp-head{ display:flex; align-items:center; justify-content:space-between; padding:0 calc(12px * var(--md-s)) calc(8px * var(--md-s)) calc(14px * var(--md-s)); }
.hl-mp-title{ color:#fff; font-size:calc(12px * var(--md-s)); font-weight:600; letter-spacing:.2px; text-shadow:0 1px 4px rgba(0,0,0,.7); }
.hl-mp-x{ width:calc(26px * var(--md-s)); height:calc(26px * var(--md-s)); border:none; border-radius:50%; background:rgba(255,255,255,.14); color:#fff; font-size:calc(17px * var(--md-s)); display:flex; align-items:center; justify-content:center; cursor:pointer; padding:0; -webkit-tap-highlight-color:transparent; }
.hl-mp-x:active{ background:rgba(255,255,255,.26); }
.hl-mp-strip{
  display:flex; gap:calc(8px * var(--md-s)); padding:2px calc(14px * var(--md-s)); overflow-x:auto; overflow-y:hidden;
  -webkit-overflow-scrolling:touch; scrollbar-width:none; -ms-overflow-style:none; overscroll-behavior-x:contain; touch-action:pan-x;
}
.hl-mp-strip::-webkit-scrollbar{ display:none; width:0; height:0; }
.hl-mp-item{
  position:relative; flex:0 0 auto; width:calc(clamp(56px, 16vw, 70px) * var(--md-s)); aspect-ratio:9/14; padding:0;
  border:2px solid transparent; border-radius:calc(8px * var(--md-s)); overflow:hidden; background:#10122e; cursor:pointer;
  -webkit-tap-highlight-color:transparent; transition:transform .15s ease, border-color .15s ease, opacity .15s ease; opacity:.75;
}
.hl-mp-item:active{ transform:scale(.95); }
.hl-mp-item.cur{ border-color:#fff; opacity:1; }
.hl-mp-item img, .hl-mp-item video{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; }
.hl-mp-num{ position:absolute; left:calc(4px * var(--md-s)); bottom:calc(3px * var(--md-s)); font-size:calc(10px * var(--md-s)); font-weight:700; color:#fff; text-shadow:0 1px 3px rgba(0,0,0,.9); }
.hl-mp-vid{ position:absolute; right:calc(4px * var(--md-s)); top:calc(4px * var(--md-s)); font-size:calc(13px * var(--md-s)); color:#fff; filter:drop-shadow(0 1px 3px rgba(0,0,0,.8)); line-height:1; }
.hl-mp-txt{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:calc(15px * var(--md-s)); font-weight:800; color:#fff; text-shadow:0 1px 4px rgba(0,0,0,.8); }
#hlLikeBtn.liked, #hlLikeBtn.liked i, #hlLikeBtn.liked span{ color:#ff2d55; }
#hlLikeBtn i.pop{ animation:hlHeart .35s ease; }
@keyframes hlHeart{ 0%{transform:scale(.6);} 60%{transform:scale(1.35);} 100%{transform:scale(1);} }

/* loader */
#hlLoader{ position:absolute; inset:0; z-index:9; background:#0c0c10; display:none; align-items:center; justify-content:center; }
#hlLoader.show{ display:flex; }
.hl-spin{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); border-radius:50%; border:3px solid rgba(255,255,255,.15); border-top-color:#fff; animation:hlSpin .8s linear infinite; }
@keyframes hlSpin{ to{ transform:rotate(360deg); } }

/* bottom sheet */
#hlSheetOv{ position:absolute; inset:0; z-index:10; background:rgba(0,0,0,.6); opacity:0; pointer-events:none; transition:opacity .25s ease; }
#hlSheetOv.show{ opacity:1; pointer-events:all; }
#hlSheet{
  position:absolute; left:0; right:0; bottom:0; z-index:11;
  background:rgba(12,12,28,.98); border-top:1px solid rgba(var(--fg,255,255,255),.08);
  border-radius:calc(18px * var(--md-s)) calc(18px * var(--md-s)) 0 0; padding:calc(8px * var(--md-s)) 0 max(calc(10px * var(--md-s)),env(safe-area-inset-bottom));
  transform:translateY(100%); transition:transform .3s cubic-bezier(.32,.72,0,1);
}
#hlSheet.show{ transform:translateY(0); }
.hl-sheet-handle{ width:calc(32px * var(--md-s)); height:calc(3px * var(--md-s)); border-radius:999px; background:rgba(255,255,255,.2); margin:0 auto calc(4px * var(--md-s)); }
.hl-sheet-item{ display:flex; align-items:center; gap:calc(10px * var(--md-s)); padding:calc(10px * var(--md-s)) calc(16px * var(--md-s)); color:#fff; font-size:calc(12.5px * var(--md-s)); font-weight:500; cursor:pointer; border-bottom:1px solid rgba(255,255,255,.06); -webkit-tap-highlight-color:transparent; }
.hl-sheet-item:last-child{ border-bottom:none; }
.hl-sheet-item:active{ background:rgba(255,255,255,.06); }
.hl-sheet-item i{ font-size:calc(15px * var(--md-s)); color:#f24455; width:calc(18px * var(--md-s)); text-align:center; }
.hl-sheet-item.danger{ color:#ff4c4c; }
/* my comments inside the sheet */
.hl-mc-head{ padding:calc(6px * var(--md-s)) calc(16px * var(--md-s)) calc(4px * var(--md-s)); color:rgba(255,255,255,.55); font-size:calc(11px * var(--md-s)); font-weight:600; }
.hl-mc-head span{ color:#ff2d55; margin-left:calc(3px * var(--md-s)); }
.hl-mc-list{ max-height:min(34vh,260px); overflow-y:auto; -webkit-overflow-scrolling:touch; border-bottom:6px solid rgba(255,255,255,.05); }
.hl-mc-row{ display:flex; align-items:flex-start; gap:calc(10px * var(--md-s)); padding:calc(9px * var(--md-s)) calc(16px * var(--md-s)); border-bottom:1px solid rgba(255,255,255,.06); }
.hl-mc-row:last-child{ border-bottom:none; }
.hl-mc-main{ flex:1; min-width:0; color:#fff; font-size:calc(12.5px * var(--md-s)); line-height:1.45; word-break:break-word; }
.hl-mc-date{ display:block; margin-top:2px; color:rgba(255,255,255,.4); font-size:calc(10px * var(--md-s)); }
.hl-mc-more{ background:none; border:none; padding:0 0 0 calc(5px * var(--md-s)); color:#ff2d55; font-family:inherit; font-size:calc(11.5px * var(--md-s)); font-weight:700; cursor:pointer; }
.hl-mc-del{ flex-shrink:0; min-width:calc(30px * var(--md-s)); height:calc(28px * var(--md-s)); padding:0 calc(6px * var(--md-s)); border-radius:calc(8px * var(--md-s)); border:none; background:none; color:#ff4c4c; display:flex; align-items:center; justify-content:center; font-family:inherit; font-size:calc(11px * var(--md-s)); font-weight:700; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-mc-del i{ font-size:calc(17px * var(--md-s)); }
.hl-mc-del.confirm{ background:rgba(255,76,76,.16); }
.hl-mc-del:disabled{ opacity:.5; }
.hl-mc-row.gone{ opacity:0; transform:translateX(16px); transition:opacity .2s, transform .2s; }
.hl-sheet-cancel{ display:block; width:calc(100% - calc(24px * var(--md-s))); margin:calc(6px * var(--md-s)) calc(12px * var(--md-s)) 0; padding:calc(9px * var(--md-s)); background:rgba(255,255,255,.06); border:none; border-radius:calc(12px * var(--md-s)); color:rgba(255,255,255,.7); font-weight:600; font-size:calc(12px * var(--md-s)); font-family:inherit; cursor:pointer; }

.hl-toast{ position:absolute; left:50%; bottom:calc(90px * var(--md-s)); transform:translateX(-50%); z-index:12; background:rgba(20,20,25,.95); border:1px solid rgba(255,45,45,.3); color:#fff; padding:calc(7px * var(--md-s)) calc(18px * var(--md-s)); border-radius:calc(50px * var(--md-s)); font-size:calc(11.5px * var(--md-s)); white-space:nowrap; pointer-events:none; transition:opacity .3s; }

/* responsive (gets smaller as the screen shrinks) */
@media(max-width:790px){
  /* no highlight arrows on responsive */
  .hl-nav{ display:none!important; }
}
@media(max-width:490px){
  .hl-avatar{ width:calc(32.6px * var(--md-s)); height:calc(32.6px * var(--md-s)); }
  .hl-title{ font-size:calc(12px * var(--md-s)); } .hl-date{ font-size:calc(10.1px * var(--md-s)); }
  .hl-icon-btn{ --hl-ic:calc(19px * var(--md-s)); }
  .hl-act i{ font-size:calc(23px * var(--md-s)); } .hl-act{ font-size:calc(11.5px * var(--md-s)); }
  .hl-bar{ height:calc(36px * var(--md-s)); } #hlInput,.hl-fake{ font-size:calc(12px * var(--md-s)); }
  .hl-cap{ font-size:calc(14px * var(--md-s)); }
}
@media(max-width:390px){
  .hl-avatar{ width:calc(30.7px * var(--md-s)); height:calc(30.7px * var(--md-s)); }
  .hl-head-info{ padding-top:1px; }
  .hl-title{ font-size:calc(11.5px * var(--md-s)); } .hl-count{ font-size:calc(9px * var(--md-s)); }
  .hl-bottom{ gap:calc(8px * var(--md-s)); padding-left:calc(10px * var(--md-s)); padding-right:calc(10px * var(--md-s)); }
  .hl-bar{ height:calc(34px * var(--md-s)); }
  .hl-sheet-item{ font-size:calc(12px * var(--md-s)); }
}
@media(max-width:330px){
  .hl-avatar{ width:calc(27.8px * var(--md-s)); height:calc(27.8px * var(--md-s)); border-width:2px; }
  .hl-head-info{ padding-top:1px; }
  .hl-title{ font-size:calc(11px * var(--md-s)); } .hl-date{ font-size:calc(10.1px * var(--md-s)); }
  .hl-icon-btn{ --hl-ic:calc(17px * var(--md-s)); width:calc(28px * var(--md-s)); height:calc(28px * var(--md-s)); }
  .hl-act i{ font-size:calc(21px * var(--md-s)); }
}
@media(prefers-reduced-motion:reduce){
  .hl-pop,.hl-pop.out{ animation-duration:.01s; }
}
/* ═════════ COMMENTS BELOW THE BOX — detailed, blue, responsive ═════════ */
#hlComments{
  --cm-bg:var(--lane,#070924);
  --cm-px:18px; --csav:34px; --csgap:calc(11px * var(--md-s)); --cm-fs:calc(13.5px * var(--md-s)); --cm-meta:11.5px; --thread:rgba(255,90,125,.28);
  position:relative; display:flex; flex-direction:column;
  background:var(--lane,#070924);
  /* fills the whole screen on responsive, so the top bar and the composer sit on the screen edges with nothing around them */
  min-height:100vh; min-height:100dvh;
}
@media(min-width:791px){ #hlComments{ min-height:min(62vh,560px); border-radius:0 0 calc(8px * var(--md-s)) calc(8px * var(--md-s)); } }

/* top bar: solid #070924, covers whatever scrolls under it, no line, no shadow */
.hl-cm-top{ position:sticky; top:0; z-index:7; background:var(--lane,#070924); border:none; box-shadow:none; }
.hl-cm-head{ display:flex; align-items:center; gap:calc(6px * var(--md-s)); padding:calc(var(--cm-px) * .65) calc(var(--cm-px) * .6) calc(var(--cm-px) * .55) var(--cm-px); }
.hl-cm-title{ flex:1; min-width:0; display:flex; align-items:center; gap:calc(8px * var(--md-s)); font-size:calc(var(--cm-fs) + 1px); font-weight:700; letter-spacing:-.1px; }
.hl-cm-title i{ color:#ff5a7d; font-size:1.15em; }
.hl-cm-pill{ padding:0; border:none; background:none; border-radius:0; color:rgba(255,255,255,.6); font-size:calc(var(--cm-fs) + 1px); font-weight:600; }
.hl-cm-pill:empty{ display:none; }
.hl-cm-sort{ position:relative; }
.hl-cm-sortbtn{ display:flex; align-items:center; gap:2px; padding:calc(5px * var(--md-s)) calc(9px * var(--md-s)); border:none; border-radius:calc(8px * var(--md-s)); background:rgba(255,255,255,.07); color:rgba(255,255,255,.88); font-size:var(--cm-meta); font-weight:600; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-cm-sortbtn:hover{ background:rgba(255,255,255,.13); }
.hl-cm-sortmenu{ position:absolute; top:calc(100% + calc(6px * var(--md-s))); right:0; z-index:9; display:none; min-width:calc(150px * var(--md-s)); background:#0c0e2a; border:1px solid rgba(255,255,255,.1); border-radius:calc(12px * var(--md-s)); padding:calc(6px * var(--md-s)); box-shadow:0 10px 30px rgba(0,0,0,.55); }
.hl-cm-sortmenu.show{ display:block; }
.hl-cm-sortmenu button{ display:block; width:100%; text-align:left; padding:calc(8px * var(--md-s)) calc(10px * var(--md-s)); border:none; background:none; color:#fff; font-size:calc(12.5px * var(--md-s)); border-radius:calc(8px * var(--md-s)); cursor:pointer; }
.hl-cm-sortmenu button:hover{ background:rgba(255,255,255,.07); }
.hl-cm-sortmenu button.active{ color:#ff5a7d; font-weight:600; }
.hl-cm-hb{ width:calc(34px * var(--md-s)); height:calc(34px * var(--md-s)); border:none; border-radius:50%; background:rgba(255,255,255,.07); color:#fff; font-size:calc(20px * var(--md-s)); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .2s,transform .15s; -webkit-tap-highlight-color:transparent; flex-shrink:0; }
.hl-cm-hb:hover{ background:rgba(255,255,255,.14); }
.hl-cm-hb:active{ transform:scale(.9); }
/* V (down) while you're on the post, chevron up once you're in the comments */
#hlComments:not(.cm-on) .hl-nv-up{ display:none; }
#hlComments.cm-on .hl-nv-dn{ display:none; }

.hl-cm-note{ display:flex; align-items:center; gap:calc(8px * var(--md-s)); margin:calc(12px * var(--md-s)) var(--cm-px) 2px; padding:calc(8px * var(--md-s)) calc(12px * var(--md-s)); border-radius:calc(12px * var(--md-s)); background:rgba(255,255,255,.045); border:1px solid rgba(255,255,255,.08); font-size:calc(var(--cm-meta) + .5px); line-height:1.3; color:rgba(255,255,255,.86); }
.hl-cm-note i{ font-size:calc(1rem * var(--md-s)); line-height:1; color:#ff5a7d; flex-shrink:0; }

#hlCmList{ flex:1 0 auto; padding:2px var(--cm-px) calc(20px * var(--md-s)); -webkit-user-select:text; user-select:text; }

/* one comment (any depth) */
.hl-ci{ --csav:34px; --csgap:calc(11px * var(--md-s)); padding:calc(12px * var(--md-s)) 0; }
.hl-ci.fresh{ animation:hlCmIn .3s ease both; }
@keyframes hlCmIn{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }
.hl-ci-main{ display:flex; gap:var(--csgap); }
.hl-ci-avcol{ display:flex; flex-direction:column; align-items:center; flex-shrink:0; width:var(--csav); }
.hl-ci-av{ width:var(--csav); height:var(--csav); border-radius:50%; overflow:hidden; display:block; flex-shrink:0; border:2px solid #ff2d55; background:var(--surface-2,#0d1030); }
.hl-ci-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-ci-line{ display:none; flex:1; width:2px; margin-top:calc(6px * var(--md-s)); border-radius:2px; background:var(--thread); }
.hl-ci.has-thread > .hl-ci-main .hl-ci-line{ display:block; }
.hl-ci.has-thread > .hl-ci-main .hl-ci-body{ padding-bottom:calc(12px * var(--md-s)); }
.hl-ci-body{ flex:1; min-width:0; }
/* username, Owner/Admin tag and time: one line, same baseline, never wraps */
.hl-ci-l1{ display:flex; flex-direction:row; flex-wrap:nowrap; align-items:baseline; gap:0 calc(8px * var(--md-s)); min-width:0; }
.hl-ci-un{ display:block; flex:0 1 auto; min-width:0; font-size:calc(var(--cm-fs) - .5px); font-weight:600; line-height:1.4; color:rgba(255,255,255,.74); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.hl-ci-un:hover{ text-decoration:underline; }
.hl-ci-l1 > .hl-bdg{ flex:0 0 auto; line-height:1.4; }
.hl-ci-t{ font-size:var(--cm-meta); line-height:1.4; color:rgba(255,255,255,.4); flex:0 0 auto; white-space:nowrap; }
.hl-ci-t::before{ content:'·'; margin-right:calc(6px * var(--md-s)); }
.hl-ci-more{ align-self:center; margin-left:auto; margin-right:calc(-6px * var(--md-s)); width:calc(28px * var(--md-s)); height:calc(28px * var(--md-s)); flex-shrink:0; border:none; background:none; color:rgba(255,255,255,.45); font-size:calc(1.15rem * var(--md-s)); border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:color .2s,background .2s; -webkit-tap-highlight-color:transparent; }
.hl-ci-more:hover{ color:#fff; background:rgba(255,255,255,.08); }
.hl-ci-tx{ font-size:var(--cm-fs); line-height:1.55; color:rgba(255,255,255,.92); margin-top:2px; word-break:break-word; overflow-wrap:anywhere; }
.hl-ci-tx .hl-full{ display:none; }
.hl-ci-tx.expanded .hl-full{ display:inline; }
.hl-ci-tx.expanded .hl-short{ display:none; }
.hl-rm{ border:none; background:none; padding:0; margin-left:2px; font-family:inherit; font-size:inherit; font-weight:400; color:rgba(255,255,255,.7); cursor:pointer; }
.hl-rm:hover{ color:#fff; }
.hl-mention{ color:#ff5a7d; font-weight:600; }
.hl-link{ color:#ff5a7d; font-weight:500; word-break:break-all; }
.hl-link:hover{ text-decoration:underline; }
.hl-ci-r2{ display:flex; align-items:center; gap:calc(18px * var(--md-s)); margin-top:calc(8px * var(--md-s)); }
.hl-ci-like,.hl-ci-dis,.hl-ci-reply{ display:flex; align-items:center; gap:calc(6px * var(--md-s)); border:none; background:none; padding:0; color:rgba(255,255,255,.85); font-size:calc(var(--cm-meta) + .5px); font-weight:500; cursor:pointer; transition:color .2s,transform .12s; -webkit-tap-highlight-color:transparent; }
.hl-ci-like i,.hl-ci-dis i{ font-size:calc(1.15rem * var(--md-s)); display:block; line-height:1; }
.hl-ci-like:active,.hl-ci-dis:active,.hl-ci-reply:active{ transform:scale(.9); }
.hl-ci-like.on,.hl-ci-dis.on{ color:#ff3b5c; }
.hl-ci-like i.pop,.hl-ci-dis i.pop{ animation:hlPop .34s ease; }
.hl-ci-reply{ color:rgba(255,255,255,.5); }
.hl-ci-reply:hover{ color:#fff; }
.hl-crl{ position:relative; display:inline-flex; width:calc(16px * var(--md-s)); height:calc(16px * var(--md-s)); flex-shrink:0; margin-left:-2px; }
.hl-crl-av{ width:calc(16px * var(--md-s)); height:calc(16px * var(--md-s)); border-radius:50%; overflow:hidden; border:1.5px solid #ff2d55; background:var(--surface-2,#0d1030); display:block; }
.hl-crl-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-crl-b{ position:absolute; right:calc(-5px * var(--md-s)); bottom:calc(-4px * var(--md-s)); font-size:calc(.68rem * var(--md-s)); line-height:1; color:#ff3b5c; filter:drop-shadow(0 0 1.5px var(--lane,#070924)) drop-shadow(0 0 1px var(--lane,#070924)); }

/* "· 9 replies ›" toggle and the nested thread under it */
.hl-ci-tg{ position:relative; display:flex; align-items:center; gap:calc(7px * var(--md-s)); height:calc(30px * var(--md-s)); margin-left:calc(var(--csav) + var(--csgap)); border:none; background:none; padding:0; color:#fff; font-size:calc(var(--cm-meta) + .5px); font-weight:600; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-ci-tg::before{ content:''; position:absolute; top:0; height:50%; box-sizing:border-box; left:calc(-1 * (var(--csav) / 2 + var(--csgap) + 1px)); width:calc(var(--csav) / 2 + var(--csgap) + 1px); border-left:2px solid var(--thread); border-bottom:2px solid var(--thread); border-bottom-left-radius:12px; }
.hl-ci-tgav{ width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); border-radius:50%; overflow:hidden; flex-shrink:0; border:1.5px solid #ff2d55; background:var(--surface-2,#0d1030); display:block; }
.hl-ci-tgav img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-ci-tgd{ opacity:.7; }
.hl-ci-chev{ font-size:calc(1.1rem * var(--md-s)); line-height:1; transition:transform .2s ease; }
.hl-ci-tg.open .hl-ci-chev{ transform:rotate(90deg); }
.hl-sub{ margin-left:calc(var(--csav) + var(--csgap)); margin-top:2px; }
.hl-sub .hl-ci{ --csav:28px; --csgap:calc(9px * var(--md-s)); padding:calc(10px * var(--md-s)) 0; }
.hl-sub .hl-sub .hl-sub{ margin-left:0; }
.hl-ci-sm{ display:block; margin:2px 0 calc(6px * var(--md-s)); padding:0; border:none; background:none; color:rgba(255,255,255,.7); font-size:calc(var(--cm-meta) + .2px); font-weight:600; cursor:pointer; }
.hl-ci-sm:hover{ color:#fff; }

.hl-cm-empty{ padding:calc(46px * var(--md-s)) calc(20px * var(--md-s)); text-align:center; color:rgba(255,255,255,.5); font-size:var(--cm-meta); line-height:1.6; }
.hl-cm-empty i{ width:calc(58px * var(--md-s)); height:calc(58px * var(--md-s)); border-radius:50%; background:rgba(255,45,85,.14); border:1px solid rgba(255,45,85,.35); display:flex; align-items:center; justify-content:center; margin:0 auto calc(12px * var(--md-s)); font-size:calc(26px * var(--md-s)); color:#ff5a7d; }
.hl-cm-sent{ display:none; justify-content:center; padding:calc(10px * var(--md-s)) 0 calc(18px * var(--md-s)); }
.hl-cm-sent.show{ display:flex; }
.hl-cm-spin{ width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); border-radius:50%; border:3px solid rgba(255,255,255,.15); border-top-color:#ff5a7d; animation:hlSpin .8s linear infinite; }
.hl-sk{ background:linear-gradient(90deg,rgba(255,255,255,.05) 25%,rgba(255,255,255,.13) 50%,rgba(255,255,255,.05) 75%); background-size:200% 100%; animation:hlSh 1.3s linear infinite; border-radius:calc(6px * var(--md-s)); }
.hl-ci-skel{ padding:calc(12px * var(--md-s)) 0; display:flex; gap:calc(11px * var(--md-s)); }

/* the ⋯ menu */
.hl-cm-menu{ position:absolute; z-index:8; display:none; min-width:calc(180px * var(--md-s)); background:#0c0e2a; border:1px solid rgba(255,255,255,.1); border-radius:calc(12px * var(--md-s)); padding:calc(6px * var(--md-s)); box-shadow:0 10px 30px rgba(0,0,0,.55); }
.hl-cm-menu.show{ display:block; }
.hl-cm-menu button{ display:flex; align-items:center; gap:calc(10px * var(--md-s)); width:100%; text-align:left; padding:calc(10px * var(--md-s)); border:none; background:none; color:#fff; font-size:calc(12.5px * var(--md-s)); border-radius:calc(8px * var(--md-s)); cursor:pointer; }
.hl-cm-menu button i{ font-size:calc(1.1rem * var(--md-s)); opacity:.85; }
.hl-cm-menu button:hover{ background:rgba(255,255,255,.07); }
.hl-cm-menu button.danger{ color:#ff6b6b; }

/* composer pinned to the bottom: solid #070924, covers what scrolls under it, no line */
.hl-cm-composer{ position:sticky; bottom:0; z-index:6; background:var(--lane,#070924); border:none; box-shadow:none; padding:calc(10px * var(--md-s)) var(--cm-px) calc(calc(10px * var(--md-s)) + env(safe-area-inset-bottom,0px)); }
.hl-cm-composer:empty{ display:none; }
.hl-reply-chip{ display:none; align-items:center; gap:calc(8px * var(--md-s)); margin:0 0 calc(8px * var(--md-s)); padding:calc(5px * var(--md-s)) calc(6px * var(--md-s)) calc(5px * var(--md-s)) calc(12px * var(--md-s)); border-radius:calc(8px * var(--md-s)); background:rgba(255,45,85,.14); border:1px solid rgba(255,45,85,.42); font-size:var(--cm-meta); color:rgba(255,255,255,.72); }
.hl-reply-chip.show{ display:flex; animation:hlCmIn .2s ease both; }
.hl-reply-chip b{ flex:1; min-width:0; color:#9db9f5; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hl-reply-x{ width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); border:none; border-radius:50%; background:rgba(255,255,255,.1); color:#fff; display:flex; align-items:center; justify-content:center; font-size:calc(14px * var(--md-s)); cursor:pointer; flex-shrink:0; }
.hl-reply-x:hover{ background:rgba(255,255,255,.2); }
.hl-compose-row{ display:flex; align-items:center; gap:calc(10px * var(--md-s)); }
.hl-cmy{ width:calc(32px * var(--md-s)); height:calc(32px * var(--md-s)); border-radius:50%; overflow:hidden; flex-shrink:0; border:2px solid #ff2d55; background:var(--surface-2,#0d1030); display:block; }
.hl-cmy img{ width:100%; height:100%; object-fit:cover; display:block; }
#hlCIn{ flex:1; min-width:0; border:1px solid rgba(var(--fg,255,255,255),.1); outline:none; background:rgba(var(--fg,255,255,255),.07); color:var(--text,#F0F2FF); padding:calc(9px * var(--md-s)) calc(14px * var(--md-s)); border-radius:999px; font-size:var(--cm-fs); -webkit-user-select:text; user-select:text; transition:border-color .2s,box-shadow .2s,background .2s; }
#hlCIn:focus{ border-color:rgba(255,90,125,.9); background:rgba(var(--fg,255,255,255),.1); box-shadow:0 0 0 3px rgba(255,45,85,.22); }
#hlCIn::placeholder{ color:rgba(var(--fg,255,255,255),.4); }
.hl-csend{ width:calc(34px * var(--md-s)); height:calc(34px * var(--md-s)); border:none; border-radius:50%; padding:0; flex-shrink:0; background:linear-gradient(135deg,#ff2d55,#ff5a7d); color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .2s,color .2s,opacity .2s,transform .15s; -webkit-tap-highlight-color:transparent; }
.hl-csend svg{ width:calc(18px * var(--md-s)); height:calc(18px * var(--md-s)); display:block; }
.hl-csend:not(:disabled):active{ transform:scale(.88); }
.hl-csend:disabled{ background:rgba(255,255,255,.08); color:rgba(255,255,255,.4); cursor:default; }
.hl-nocm{ display:flex; align-items:center; justify-content:center; gap:calc(7px * var(--md-s)); font-size:var(--cm-meta); color:rgba(255,255,255,.55); }

/* responsive: the box leaves a strip at the bottom where only the comments top bar shows; the composer appears once you scroll in */
@media(max-width:790px){
  #hlWrap{ --hl-stage-h:calc(100vh - calc(48px * var(--md-s))); }
  @supports (height:100dvh){ #hlWrap{ --hl-stage-h:calc(100dvh - calc(48px * var(--md-s))); } }
  .hl-cm-composer{ opacity:0; visibility:hidden; pointer-events:none; transition:opacity .15s ease; }
  #hlComments.cm-on .hl-cm-composer{ opacity:1; visibility:visible; pointer-events:auto; }
  #hlScroll{ scrollbar-width:none; -ms-overflow-style:none; }
  #hlScroll::-webkit-scrollbar{ display:none; width:0; height:0; }
  .hl-nav{ display:none!important; }
  #hlComments{ --cm-px:16px; --csav:32px; }
  .hl-cm-top{ padding-top:env(safe-area-inset-top,0px); }
}
@media(max-width:490px){ #hlComments{ --cm-px:14px; --csav:30px; --csgap:calc(9px * var(--md-s)); --cm-fs:calc(13px * var(--md-s)); --cm-meta:11px; } }
/* the old chat bubbles on the video are gone */
#hlPops{ display:none !important; }
.hl-bar{ cursor:pointer; }
.hl-bar .hl-fake{ flex:1; min-width:0; color:rgba(255,255,255,.65); font-size:calc(12.5px * var(--md-s)); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#hlCmList .hl-ci-l1 > .dz-vb{ width:calc(var(--cm-fs) + .5px); height:calc(var(--cm-fs) + .5px); margin:0 calc(5px * var(--md-s)) calc(var(--cm-fs) * -.16) calc(-3px * var(--md-s)); color:#FF2D2D; flex-shrink:0; align-self:baseline; }
/* LIGHT MODE: theme_css.php forces #hlModal and everything in it to the always-dark colour set; the comments sit on the page-coloured lane, so they get the light set back */
:root[data-theme="light"] #hlModal .hl-cm-top,
:root[data-theme="light"] #hlModal .hl-cm-top *,
:root[data-theme="light"] #hlModal .hl-cm-note,
:root[data-theme="light"] #hlModal .hl-cm-note *,
:root[data-theme="light"] #hlModal #hlCmList,
:root[data-theme="light"] #hlModal #hlCmList *,
:root[data-theme="light"] #hlModal #hlCmCompose,
:root[data-theme="light"] #hlModal #hlCmCompose *,
:root[data-theme="light"] #hlModal .hl-cm-sent,
:root[data-theme="light"] #hlModal .hl-cm-sent *,
:root[data-theme="light"] #hlModal .hl-cm-menu,
:root[data-theme="light"] #hlModal .hl-cm-menu *{
  --text:#0b0d24; --fg:6,7,26; --ui-text:#0b0d24; --ui-sub:#52566a; --ui-sub2:#42465a; --ui-mute:#5f647a;
  --cap:rgba(0,0,0,.86); --gw:rgba(0,0,0,.6); --more:#000; --muted:rgba(0,0,0,.5); --sub:rgba(0,0,0,.66);
}
:root[data-theme="light"] .hl-cm-pill{ color:rgba(var(--fg),.6); }
:root[data-theme="light"] .hl-cm-sortbtn{ background:rgba(var(--fg),.07); color:rgba(var(--fg),.8); }
:root[data-theme="light"] .hl-cm-sortbtn:hover{ background:rgba(var(--fg),.13); }
:root[data-theme="light"] .hl-cm-sortmenu, .hl-cm-menu{ background:var(--lane,#fff); border-color:rgba(var(--fg),.14); box-shadow:0 10px 30px rgba(6,7,26,.18); }
:root[data-theme="light"] .hl-cm-sortmenu button, .hl-cm-menu button{ color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-cm-sortmenu button:hover, .hl-cm-menu button:hover{ background:rgba(var(--fg),.07); }
:root[data-theme="light"] .hl-cm-sortmenu button.active{ color:#d6264f; }
:root[data-theme="light"] .hl-cm-menu button.danger{ color:#d62f2f; }
:root[data-theme="light"] .hl-cm-hb{ background:rgba(var(--fg),.07); color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-cm-hb:hover{ background:rgba(var(--fg),.14); }
:root[data-theme="light"] .hl-cm-title, .hl-cm-title b{ color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-cm-title i{ color:#d6264f; }
:root[data-theme="light"] .hl-cm-note{ background:rgba(var(--fg),.045); border-color:rgba(var(--fg),.1); color:rgba(var(--fg),.86); }
:root[data-theme="light"] .hl-cm-note i{ color:#d6264f; }
:root[data-theme="light"] .hl-ci-un{ color:rgba(var(--fg),.82); }
:root[data-theme="light"] .hl-ci-t{ color:rgba(var(--fg),.5); }
:root[data-theme="light"] .hl-ci-more{ color:rgba(var(--fg),.5); }
:root[data-theme="light"] .hl-ci-more:hover{ color:rgb(var(--fg)); background:rgba(var(--fg),.08); }
:root[data-theme="light"] .hl-ci-tx{ color:rgba(var(--fg),.92); }
:root[data-theme="light"] .hl-rm, .hl-ci-sm{ color:rgba(var(--fg),.7); }
:root[data-theme="light"] .hl-rm:hover, .hl-ci-sm:hover, .hl-ci-reply:hover{ color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-mention{ color:#d6264f; }
:root[data-theme="light"] .hl-link{ color:#d6264f; }
:root[data-theme="light"] .hl-ci-like, .hl-ci-dis{ color:rgba(var(--fg),.85); }
:root[data-theme="light"] .hl-ci-like.on, .hl-ci-dis.on, .hl-crl-b{ color:#d6264f; }
:root[data-theme="light"] .hl-ci-reply{ color:rgba(var(--fg),.55); }
:root[data-theme="light"] .hl-ci-tg{ color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-ci-line{ background:rgba(var(--fg),.14); }
:root[data-theme="light"] .hl-cm-empty{ color:rgba(var(--fg),.55); }
:root[data-theme="light"] .hl-cm-empty i{ color:#d6264f; }
:root[data-theme="light"] .hl-cm-spin{ border-color:rgba(var(--fg),.15); border-top-color:#d6264f; }
:root[data-theme="light"] .hl-sk{ background:linear-gradient(90deg,rgba(var(--fg),.05) 25%,rgba(var(--fg),.12) 50%,rgba(var(--fg),.05) 75%); background-size:200% 100%; }
:root[data-theme="light"] .hl-reply-chip{ color:rgba(var(--fg),.75); background:rgba(255,45,85,.1); }
:root[data-theme="light"] .hl-reply-x{ background:rgba(var(--fg),.1); color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-reply-x:hover{ background:rgba(var(--fg),.2); }
:root[data-theme="light"] .hl-csend:disabled{ background:rgba(var(--fg),.08); color:rgba(var(--fg),.4); }
:root[data-theme="light"] .hl-nocm{ color:rgba(var(--fg),.6); }
:root[data-theme="light"] .hl-cm-composer{ border-top:1px solid rgba(var(--fg),.08); }


</style>
<style>
/* ═══ highlight viewer back to one screen: no scrolling page; comments live in a bottom sheet (same look as the profile's bottom sheets) ═══ */
#hlModal.open{ display:flex; align-items:center; justify-content:center; }
#hlScroll{ position:absolute; inset:0; overflow:hidden; display:flex; align-items:center; justify-content:center; }
#hlWrap{ height:100%; max-width:480px; margin:0; background:none; }
#hlStage{ height:100%; }
.hl-nav{ top:50%; }
@media(min-width:791px){
  #hlWrap{ height:92vh; max-height:940px; margin:0; border-radius:calc(22px * var(--md-s)); box-shadow:0 25px 90px rgba(0,0,0,.65),0 0 0 1px rgba(var(--fg,255,255,255),.07); }
  #hlStage{ border-radius:calc(22px * var(--md-s)); clip-path:inset(0 round 22px); -webkit-clip-path:inset(0 round 22px); }
}
/* same proportions as the Zeems box (zeems_modal.php): desktop = tall box, 95% of the screen height, width = 68% of that height, 10px corners; phone = full screen up to 480px wide */
@media(min-width:791px){
  #hlWrap{ --hl-h:min(95vh,1000px); height:var(--hl-h); width:calc(var(--hl-h) * .68); max-width:none; border-radius:10px; }
  #hlStage{ border-radius:10px; clip-path:inset(0 round 10px); -webkit-clip-path:inset(0 round 10px); }
}
#hlCmOv{ position:absolute; inset:0; z-index:10; background:rgba(0,0,0,.55); opacity:0; pointer-events:none; transition:opacity .25s ease; }
#hlCmOv.show{ opacity:1; pointer-events:all; }
#hlComments{
  position:absolute; left:0; right:0; bottom:0; z-index:11; min-height:0; height:min(76%,620px);
  border-radius:16px 16px 0 0; border:1px solid rgba(var(--fg,255,255,255),.08); border-bottom:none; overflow:hidden;
  transform:translateY(105%); visibility:hidden; transition:transform .28s cubic-bezier(.22,1,.36,1), visibility 0s linear .28s;
  -webkit-user-select:text; user-select:text; font-family:'Poppins',sans-serif;
}
#hlComments.show{ transform:translateY(0); visibility:visible; transition:transform .28s cubic-bezier(.22,1,.36,1), visibility 0s; }
#hlComments .hl-cm-top{ position:relative; flex:0 0 auto; padding-top:calc(10px * var(--md-s)); }
#hlComments .hl-cm-head{ padding-top:0; }
#hlComments #hlCmList{ flex:1 1 auto; min-height:0; overflow-y:auto; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; }
#hlComments .hl-cm-composer{ position:relative; flex:0 0 auto; opacity:1 !important; visibility:visible !important; pointer-events:auto !important; padding-bottom:max(calc(10px * var(--md-s)),env(safe-area-inset-bottom)); }
#hlComments .hl-cm-note{ flex:0 0 auto; }
.hl-sheet-handle{ width:36px; height:4px; border-radius:3px; background:rgba(var(--fg,255,255,255),.25); margin:0 auto 10px; }
#hlComments .hl-sheet-handle{ margin-bottom:4px; }
/* ⋯ menu sheet: the profile page's bottom-sheet look */
#hlSheet{
  background:var(--lane,#070924); border:1px solid rgba(var(--fg,255,255,255),.08); border-bottom:none;
  border-radius:16px 16px 0 0; padding:10px 0 max(18px,env(safe-area-inset-bottom)); font-family:'Poppins',sans-serif;
}
#hlSheet .hl-sheet-handle{ margin-bottom:6px; }
.hl-sheet-item{ gap:12px; padding:13px 16px; font-size:13.5px; font-weight:600; color:var(--text,#F0F2FF); border-bottom:1px solid rgba(var(--fg,255,255,255),.07); }
.hl-sheet-item i{ font-size:18px; width:22px; color:var(--red,#FF2D2D); }
.hl-sheet-item .hl-sheet-n{ margin-left:auto; font-size:12px; font-weight:600; color:rgba(var(--fg,255,255,255),.5); }
.hl-sheet-item:active{ background:rgba(var(--fg,255,255,255),.06); }
.hl-sheet-cancel{ display:none; }
:root[data-theme="light"] #hlModal #hlSheet,
:root[data-theme="light"] #hlModal #hlSheet *{
  --text:#0b0d24; --fg:6,7,26; --ui-text:#0b0d24; --ui-sub:#52566a; --cap:rgba(0,0,0,.86); --muted:rgba(0,0,0,.5);
}
:root[data-theme="light"] #hlModal .hl-sheet-item{ color:#0b0d24; }
:root[data-theme="light"] #hlModal .hl-sheet-item.danger{ color:#d62f2f; }
</style>
<style>
/* bottom sheets = the profile page's .dzm-* look (same as the Tracking / Untrack sheet) */
#hlSheet, #hlComments{
  background:var(--lane,#070924); border:none; border-radius:0; box-shadow:none;
  padding:calc(5px * var(--bs-s,1)) calc(10px * var(--bs-s,1)) max(calc(10px * var(--bs-s,1)),env(safe-area-inset-bottom));
  display:flex; flex-direction:column; gap:calc(8px * var(--bs-s,1));
}
#hlSheet{ max-height:60%; }
#hlSheet .dzm-handle, #hlComments .dzm-handle{ display:flex; justify-content:center; padding:2px 0; flex-shrink:0; }
#hlSheet .dzm-handle span, #hlComments .dzm-handle span{ width:calc(30px * var(--bs-s,1)); height:calc(3px * var(--bs-s,1)); border-radius:2px; background:rgba(var(--fg,255,255,255),.22); display:block; }
#hlSheet .dzm-card{ background:var(--surface-3,#090b29); border-radius:calc(6px * var(--bs-s,1)); overflow:hidden; flex-shrink:1; min-height:0; display:flex; flex-direction:column; }
#hlSheet .dzm-scroll{ overflow-y:auto; min-height:0; }
#hlSheet .dzm-header{ padding:calc(7px * var(--bs-s,1)) calc(12px * var(--bs-s,1)) calc(3px * var(--bs-s,1)); font-size:calc(8.5px * var(--bs-s,1)); color:rgba(var(--fg,255,255,255),.35); letter-spacing:1px; text-transform:uppercase; }
#hlSheet .dzm-close-btn{ display:flex; align-items:center; justify-content:center; width:100%; padding:calc(10px * var(--bs-s,1)); background:var(--surface-3,#090b29); border:none; border-radius:calc(6px * var(--bs-s,1)); color:rgba(var(--fg,255,255,255),.85); font-size:calc(12px * var(--bs-s,1)); font-weight:600; cursor:pointer; font-family:inherit; flex-shrink:0; }
#hlSheet .mr-row:first-child{ border-top:none; }
#hlComments .hl-cm-top{ padding-top:0; background:transparent; }
#hlComments .hl-cm-head{ padding-left:calc(4px * var(--bs-s,1)); padding-right:0; }
#hlComments .hl-cm-title{ font-size:calc(8.5px * var(--bs-s,1)); font-weight:400; letter-spacing:1px; text-transform:uppercase; color:rgba(var(--fg,255,255,255),.45); }
#hlComments .hl-cm-title i{ display:none; }
#hlComments .hl-cm-pill{ font-size:calc(8.5px * var(--bs-s,1)); }
#hlComments #hlCmList{ background:var(--surface-3,#090b29); border-radius:calc(6px * var(--bs-s,1)); }
#hlComments .hl-cm-note{ margin-left:0; margin-right:0; }
#hlComments .hl-cm-composer{ background:transparent; padding-left:0; padding-right:0; border-top:none; }
:root[data-theme="light"] #hlModal #hlSheet .dzm-header{ color:rgba(6,7,26,.45); }
:root[data-theme="light"] #hlModal #hlSheet .dzm-close-btn{ color:#0b0d24; }
:root[data-theme="light"] #hlModal #hlSheet .dzm-handle span, :root[data-theme="light"] #hlModal #hlComments .dzm-handle span{ background:rgba(6,7,26,.22); }
:root[data-theme="light"] #hlModal #hlComments .hl-cm-title{ color:rgba(6,7,26,.5); }
</style>
<style>
/* like / media / share: the immersive viewer's outline icons, same sizes, red + filled heart when liked */
#hlModal .hl-bottom .hl-ab{ gap:calc(6px * var(--md-s)); color:#fff; filter:drop-shadow(0 2px 5px rgba(0,0,0,.55)); }
#hlModal .hl-bottom .hl-ab.liked{ color:var(--red,#FF2D2D); }
#hlModal .hl-bottom .hl-ab.liked .sp-fillable svg{ fill:currentColor; }
#hlModal .hl-bottom .hl-ab .ipv-action-count{ color:#fff; text-shadow:none; }
#hlLikeBtn i.pop{ animation:hlHeart .35s ease; }
#hlModal .hl-bottom .hl-ab.hl-off{ display:none; }
.hl-bar.hl-bar-off{ opacity:.6; }
</style>
<style>
/* ═══ highlight comments = the comment sheet's design (comment_section.php): same tokens, ring avatars, thread lines, right-hand heart, pill composer ═══ */
#hlComments{
  --cs-bg:rgba(12,12,28,.98); --cs-tx:#f5f5f5; --cs-sub:rgba(255,255,255,.55); --cs-line:rgba(255,255,255,.08);
  --cs-field:rgba(255,255,255,.07); --cs-handle:rgba(255,255,255,.2); --cs-chip:rgba(255,255,255,.09);
  --cs-mention:#6aa8ff; --cs-red:#ff2d2d; --cs-thread:rgba(255,255,255,.24); --cs-fs:14.3px;
  background:var(--cs-bg); -webkit-backdrop-filter:blur(20px); backdrop-filter:blur(20px);
  border-top:1px solid var(--cs-line); color:var(--cs-tx); box-shadow:0 -10px 40px rgba(0,0,0,.5);
  padding:0; gap:0; font:var(--cs-fs)/1.35 Poppins,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; height:min(78%,660px);
  --csav:2.6em; --csgap:.75em;
}
:root[data-theme="light"] #hlModal #hlComments{
  --cs-bg:rgba(255,255,255,.99); --cs-tx:#14171f; --cs-sub:rgba(20,23,31,.55); --cs-line:rgba(15,20,40,.10);
  --cs-field:rgba(15,20,40,.06); --cs-handle:rgba(15,20,40,.18); --cs-chip:rgba(15,20,40,.07);
  --cs-mention:#2457c5; --cs-thread:rgba(15,20,40,.22); box-shadow:0 -10px 40px rgba(15,20,40,.18);
}
@media(max-width:790px){ #hlComments{ --cs-fs:13.75px; } }
@media(max-width:490px){ #hlComments{ --cs-fs:13.2px; } }
@media(max-width:390px){ #hlComments{ --cs-fs:12.65px; } }
@media(max-width:330px){ #hlComments{ --cs-fs:11.55px; } }
#hlComments .dzm-handle{ padding:.9em 0 .6em; }
#hlComments .dzm-handle span{ width:2.5em; height:.27em; border-radius:99px; background:var(--cs-handle); }
#hlComments .hl-cm-top{ background:transparent; padding:0; }
#hlComments .hl-cm-head{ padding:.15em .6em .6em 1.05em; border-bottom:1px solid var(--cs-line); gap:.4em; }
#hlComments .hl-cm-title{ font-size:1.12em; font-weight:700; letter-spacing:0; text-transform:none; color:var(--cs-tx); }
#hlComments .hl-cm-pill{ font-size:1em; font-weight:600; color:var(--cs-sub); }
#hlComments .hl-cm-sortbtn{ background:none; padding:.3em; color:var(--cs-tx); border-radius:0; }
#hlComments .hl-cm-sortbtn:hover{ background:none; }
#hlComments #hlCmSortLbl, #hlComments .hl-cm-sortbtn > i{ display:none; }
#hlComments .hl-cm-sortbtn svg{ width:1.7em; height:1.7em; fill:none; stroke:currentColor; stroke-width:2.2; stroke-linecap:round; display:block; }
#hlComments .hl-cm-sortmenu, #hlComments .hl-cm-menu{ background:var(--cs-bg); border:1px solid var(--cs-line); border-radius:0; box-shadow:0 8px 28px rgba(0,0,0,.4); padding:.3em 0; min-width:11em; }
#hlComments .hl-cm-sortmenu button, #hlComments .hl-cm-menu button{ padding:.65em 1.1em; font-size:.95em; color:var(--cs-tx); border-radius:0; background:none; }
#hlComments .hl-cm-sortmenu button:hover, #hlComments .hl-cm-menu button:hover{ background:var(--cs-chip); }
#hlComments .hl-cm-sortmenu button.active{ color:var(--cs-red); font-weight:700; }
#hlComments .hl-cm-menu button.danger{ color:var(--cs-red); }
#hlComments .hl-cm-hb{ width:2.3em; height:2.3em; font-size:1.3em; background:none; color:var(--cs-tx); }
#hlComments .hl-cm-hb:hover{ background:var(--cs-chip); }
#hlComments .hl-cm-note{ display:none; }
#hlComments #hlCmScroll{ background:transparent; border-radius:0; }
#hlComments #hlCmList{ background:transparent !important; border-radius:0; padding:.4em 1.05em .7em; }
#hlComments .hl-cm-empty{ color:var(--cs-sub); }
#hlComments .hl-ci{ padding:.95em 0; border-bottom:1px solid var(--cs-line); }
#hlComments #hlCmList > .hl-ci:last-child{ border-bottom:0; }
#hlComments .hl-sub .hl-ci{ --csav:2.3em; padding:.75em 0 .15em; border-bottom:0; }
#hlComments .hl-ci-av{ width:var(--csav); height:var(--csav); border:2px solid #ff2d2d; box-sizing:border-box; background:var(--cs-chip); }
#hlComments .hl-ci-line{ background:var(--cs-thread); margin-top:.5em; }
#hlComments .hl-ci-body{ position:relative; }
#hlComments .hl-ci-un{ font-size:.92em; font-weight:600; color:var(--cs-tx); }
#hlComments .hl-ci-t{ font-size:.92em; color:var(--cs-sub); }
#hlComments .hl-ci-more{ color:var(--cs-sub); }
#hlComments .hl-ci-more:hover{ color:var(--cs-tx); background:var(--cs-chip); }
#hlComments .hl-ci-tx{ font-size:1em; line-height:1.35; color:var(--cs-tx); margin-top:.15em; padding-right:2.3em; }
#hlComments .hl-mention{ color:var(--cs-red); font-weight:600; }
#hlComments .hl-link{ color:var(--cs-mention); }
#hlComments .hl-rm{ color:var(--cs-red); font-weight:600; }
#hlComments .hl-ci-r2{ margin-top:.3em; gap:1.1em; font-size:.92em; color:var(--cs-sub); }
#hlComments .hl-ci-dis, #hlComments .hl-ci-reply{ color:var(--cs-sub); font-size:1em; }
#hlComments .hl-ci-dis:hover, #hlComments .hl-ci-reply:hover{ color:var(--cs-tx); }
#hlComments .hl-ci-dis.on{ color:var(--cs-tx); }
#hlComments .hl-ci-like{ position:absolute; right:0; top:1.9em; display:flex; flex-direction:row; align-items:center; gap:.3em; color:var(--cs-sub); font-size:.88em; }
#hlComments .hl-ci-like i{ font-size:1.5em; }
#hlComments .hl-ci-like.on{ color:var(--cs-red); }
#hlComments .hl-ci-like span{ font-size:.95em; font-weight:500; min-width:1em; font-variant-numeric:tabular-nums; }
#hlComments .hl-ci-tg{ height:2.4em; margin-left:calc(var(--csav) + var(--csgap)); gap:.55em; color:var(--cs-tx); font-weight:600; font-size:1em; }
#hlComments .hl-ci-tg::before{ left:calc(-1 * (var(--csav) / 2 + var(--csgap) + 1px)); width:calc(var(--csav) / 2 + var(--csgap) + 1px); border-left:2px solid var(--cs-thread); border-bottom:2px solid var(--cs-thread); border-bottom-left-radius:.9em; }
#hlComments .hl-ci-tgav{ width:1.7em; height:1.7em; border:1.5px solid #ff2d2d; box-sizing:border-box; background:var(--cs-chip); }
#hlComments .hl-ci-tgd{ opacity:.6; }
#hlComments .hl-ci-chev{ font-size:1.2em; }
#hlComments .hl-sub{ margin-left:calc(var(--csav) + var(--csgap)); }
#hlComments .hl-ci-sm{ color:var(--cs-sub); font-size:.88em; font-weight:600; }
#hlComments .hl-ci-sm:hover{ color:var(--cs-tx); }
#hlComments .hl-cm-composer{ background:transparent; border-top:1px solid var(--cs-line); padding:.6em 1em max(.9em,env(safe-area-inset-bottom)); }
#hlComments .hl-compose-row{ display:flex; align-items:center; gap:.7em; }
#hlComments .hl-cmy{ width:2.75em; height:2.75em; border:2px solid #ff2d2d; box-sizing:border-box; background:var(--cs-chip); }
#hlComments #hlCIn{ background:var(--cs-field); border:0; border-radius:.8em; padding:.7em .8em; color:var(--cs-tx); font-size:1em; box-shadow:none; }
#hlComments #hlCIn:focus{ border:0; box-shadow:none; background:var(--cs-field); }
#hlComments #hlCIn::placeholder{ color:var(--cs-sub); }
@media (pointer:coarse){ #hlComments #hlCIn{ font-size:16px; } }
#hlComments .hl-send, #hlComments .hl-csend{ width:auto; height:auto; padding:0 .15em; border-radius:0; background:none; color:var(--cs-red); }
#hlComments .hl-send svg, #hlComments .hl-csend svg{ width:1.56em; height:1.56em; fill:currentColor; stroke:none; display:block; }
#hlComments .hl-send:disabled, #hlComments .hl-csend:disabled{ background:none; color:var(--cs-red); opacity:.4; }
#hlComments .hl-reply-chip{ background:var(--cs-chip); color:var(--cs-sub); border:0; border-radius:.6em; }
#hlComments .hl-reply-chip b{ color:var(--cs-red); }
#hlComments .hl-nocm{ color:var(--cs-sub); }
/* like / comment / share / media buttons on the video: sized like the immersive viewer (the sheet's CSS isn't loaded on every page) */
#hlModal .hl-bottom .hl-ab{ display:flex; align-items:center; background:none; border:none; cursor:pointer; padding:2px; font-size:calc(22px * var(--md-s)); color:#fff; -webkit-tap-highlight-color:transparent; }
#hlModal .hl-bottom .hl-ab i{ display:inline-block; width:1.166em; height:1.166em; margin:-.083em; line-height:0; font-style:normal; font-size:inherit; }
#hlModal .hl-bottom .hl-ab svg{ display:block; width:100%; height:100%; overflow:visible; stroke-width:2; }
#hlModal .hl-bottom .hl-ab .ipv-action-count{ font-size:calc(12px * var(--md-s)); font-weight:700; color:#fff; }
#hlModal .hl-bottom .hl-ab .ipv-action-count:empty{ display:none; }
</style>
<style>
/* tweaks: badge no bigger than the name, 8% smaller action icons, bigger send arrow, emoji row, reply chip */
#hlComments .hl-ci-l1 > .dz-vb{ width:.84em; height:.84em; margin:0 .3em 0 -.05em; flex-shrink:0; }
#hlModal .hl-bottom .hl-ab{ font-size:calc(20.24px * var(--md-s)); }
#hlComments .hl-send svg, #hlComments .hl-csend svg{ width:2.15em; height:2.15em; }
#hlComments .hl-send, #hlComments .hl-csend{ padding:0 .05em; }
#hlComments .hl-emo{ display:flex; justify-content:space-between; padding:.1em .3em .55em; }
#hlComments .hl-emo span{ font-size:1.65em; line-height:1.2; cursor:pointer; user-select:none; -webkit-user-select:none; -webkit-tap-highlight-color:transparent; }
#hlComments .hl-emo span:active{ transform:scale(.85); }
@media (max-height:520px) and (max-width:790px){ #hlComments .hl-emo{ display:none; } }
#hlComments .hl-reply-chip{ display:none; align-items:center; gap:.6em; margin:0 0 .5em; padding:.4em .6em .4em .8em; font-size:.9em; }
#hlComments .hl-reply-chip.show{ display:flex; }
#hlComments .hl-reply-chip b{ flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#hlComments .hl-reply-x{ all:unset; cursor:pointer; display:flex; font-size:1.3em; color:var(--cs-sub); }
</style>
<style>
/* text bars: same height as the send arrow / the outline icons */
#hlComments #hlCIn{ height:2.2em; padding:0 .9em; line-height:1.2; }
#hlModal .hl-bar{ height:calc(30px * var(--md-s)); }
#hlModal .hl-bar .hl-fake{ line-height:1; }
</style>
<style>
/* one comment floating above the text bar (like the story comments, but a single one); tap = open it in the comments sheet */
.hl-topcm{ position:absolute; left:calc(12px * var(--md-s)); bottom:100%; margin-bottom:calc(-2px * var(--md-s)); z-index:8; max-width:calc(100% - 24px * var(--md-s) - 70px);
  display:flex; align-items:center; gap:calc(7px * var(--md-s)); padding:calc(4px * var(--md-s)) calc(12px * var(--md-s)) calc(4px * var(--md-s)) calc(4px * var(--md-s));
  border-radius:999px; background:rgba(28,28,30,.62); -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px); color:#fff; cursor:pointer;
  font-size:calc(12.5px * var(--md-s)); font-weight:500; -webkit-tap-highlight-color:transparent; animation:hlCmIn .25s ease both; }
.hl-topcm:active{ transform:scale(.97); }
.hl-topcm-av{ width:calc(26px * var(--md-s)); height:calc(26px * var(--md-s)); border-radius:50%; overflow:hidden; flex:none; display:block; box-sizing:border-box; border:2px solid #ff2d2d; background:rgba(255,255,255,.15); }
.hl-topcm-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-topcm-tx{ min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.25; }
#hlComments .hl-ci.hl-flash{ animation:hlFlash 1.8s ease both; }
@keyframes hlFlash{ 0%,60%{ background:rgba(255,45,45,.16); } 100%{ background:transparent; } }
</style>








<script>
(function(){
  var PROFILE_ID = <?= (int)$profile_id ?>;
  var ENDPOINT   = 'profile.php?user_id=' + PROFILE_ID;
  var LOGGED     = <?= $current_user ? 'true' : 'false' ?>;
  var IMG_MS     = 20000;  // image stays 20s before auto-advance
  var POLL_MS    = 3000;   // check for new messages
  var POP_MIN = 2600, POP_MAX = 4400;   // a comment stays for a random time in this range (ms)

  /* where things live: change these if your pages are named differently */
  var HL_TEXT_BG   = 'Text.jpg';               // background of text-only highlights (next to profile.php)
  var HL_USER_URL  = 'profile.php?user_id=';   // a tagged @username / @nickname opens this
  var HL_GROUP_URL = 'group.php?id=';          // a tagged group name opens this

  var H = null, owner = null;
  var hi = 0, mi = 0, cache = false;
  var raf = null, elapsed = 0, lastTs = null, curFill = null, renderTok = 0;
  var pollT = null, cycleT = null, lastMsgId = 0, shown = {}, sending = false, pool = [], recent = [], liveQ = [];
  var offscreen = false, ioDone = false, cmSort = 'new', cmHid = 0, cmList = [], cmTok = 0, cmMenuId = 0, avTok = 0, frameCache = {};
  var aud = new Audio(), audSrc = '', soundOn = true;   // highlight music
  var capOpen = false;          // long text is expanded (timer waits while you read)
  var flip = null, flipping = false, mediaOK = false, readyCb = null;
  aud.preload = 'auto';

  function $(id){ return document.getElementById(id); }
  function esc(s){
    return String(s==null?'':s).replace(/[&<>"']/g,function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function post(body){
    return fetch(ENDPOINT,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
      .then(function(r){return r.json();});
  }
  function fmtDate(s){
    var d = new Date(String(s||'').replace(' ','T'));
    if(isNaN(d)) return '';
    var m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate()+' '+m[d.getMonth()]+' '+d.getFullYear();
  }
  function fmtNum(n){ n=+n||0; return n>=1e6?(n/1e6).toFixed(1).replace(/\.0$/,'')+'M':n>=1e3?(n/1e3).toFixed(1).replace(/\.0$/,'')+'K':n; }
  function toast(msg){
    var old=$('hlStage').querySelector('.hl-toast'); if(old) old.remove();
    var t=document.createElement('div'); t.className='hl-toast'; t.textContent=msg;
    $('hlStage').appendChild(t);
    setTimeout(function(){t.style.opacity='0'; setTimeout(function(){t.remove();},300);},1800);
  }
  function poke(){ elapsed = 0; }   // any activity restarts the 20s image timer

  /* ══════════════════ prev / next highlight arrows (shown on normal view only, CSS hides them on responsive) ══════════════════ */
  function updateNav(){
    var p=$('hlNavPrev'), n=$('hlNavNext');
    var noPrev = !H || hi<=0;                 // first highlight  → no left arrow
    var noNext = !H || hi>=H.length-1;        // last highlight   → no right arrow
    p.classList.toggle('off',noPrev); p.disabled=noPrev;
    n.classList.toggle('off',noNext); n.disabled=noNext;
  }
  window.hlGoHl = function(dir){
    dzHp('light');
    if(!H || flipping || $('hlSheet').classList.contains('show')) return;
    var idx = hi + dir;
    if(idx < 0 || idx >= H.length) return;
    autoFlip(dir, dir > 0 ? 0 : H[idx].media.length - 1);
  };

  /* ══════════════════ TEXT ON MEDIA ══════════════════
     Turns the words into safe HTML: real @usernames / @nicknames become red links,
     real group names become blue links. `map` comes from the server. */
  function richText(text, map){
    text = String(text || ''); map = map || {};
    var users = map.users || {};
    var groups = (map.groups || []).slice().sort(function(a,b){ return b.name.length - a.name.length; });
    var gid = {}; groups.forEach(function(g){ gid[g.name.toLowerCase()] = g.id; });
    var re = '(@[A-Za-z0-9_.]+)';
    if(groups.length) re += '|(^|[^\\w@])(' + groups.map(function(g){ return g.name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); }).join('|') + ')(?![\\w])';
    var rx = new RegExp(re, 'gi');
    var out = '', last = 0, m;
    while((m = rx.exec(text)) !== null){
      if(m[1]){
        var core = m[1].slice(1).replace(/\.+$/, ''), id = users[core.toLowerCase()];
        if(!id) continue;
        out += esc(text.slice(last, m.index)) + '<a class="hl-men user" href="' + esc(HL_USER_URL + id) + '">@' + esc(core) + '</a>';
        last = m.index + 1 + core.length;
        rx.lastIndex = last;
      } else {
        var start = m.index + m[2].length, gi = gid[m[3].toLowerCase()];
        if(!gi) continue;
        out += esc(text.slice(last, start)) + '<a class="hl-men group" href="' + esc(HL_GROUP_URL + gi) + '">' + esc(m[3]) + '</a>';
        last = start + m[3].length;
      }
    }
    return (out + esc(text.slice(last))).replace(/\n/g, '<br>');
  }
  /* the words shown in the middle of a media. Long text is clamped: "more" opens it, "less" folds it back. */
  function capHTML(text, map){
    var long = text.length > 120 || (text.match(/\n/g) || []).length >= 3;
    return '<div class="hl-cap-wrap"><div class="hl-cap' + (long ? ' clamp' : '') + '">' + richText(text, map) + '</div>' +
      (long ? '<button type="button" class="hl-more" onclick="hlMore(this)">more</button>' : '') + '</div>';
  }
  window.hlMore = function(btn){
    var cap = btn.previousElementSibling, collapsed = cap.classList.toggle('clamp');
    btn.textContent = collapsed ? 'more' : 'less';
    capOpen = !collapsed; poke();
  };

  /* ══════════════════ SMART OBJECT-FIT ══════════════════ */
  function hlFixFit(el){
    var box = el.parentNode;
    if(!box) return;
    var bg  = box.querySelector('.hl-bg');
    function setBg(on){ if(bg) bg.style.opacity = on ? 1 : 0; }

    var mW = el.tagName === 'VIDEO' ? el.videoWidth  : el.naturalWidth;
    var mH = el.tagName === 'VIDEO' ? el.videoHeight : el.naturalHeight;
    if(!mW || !mH) return;
    var cW = box.clientWidth, cH = box.clientHeight;
    if(!cW || !cH) return;

    var mediaAspect = mW / mH;
    var boxAspect   = cW / cH;
    el.style.transform = ''; el.style.marginTop = ''; el.style.height = '';

    if(mediaAspect < boxAspect){
      /* taller/narrower than the box: never crop it. Widen it to the full width so the whole
         picture stays visible (only extremely skinny ones keep black sides). */
      var TALL_MIN_FILL = 0.55;
      var widthFill = mediaAspect / boxAspect;
      el.style.objectFit = (widthFill >= TALL_MIN_FILL) ? 'fill' : 'contain';
      setBg(false); return;
    }

    var scaledHeight = cW / mediaAspect;
    var fillRatio    = scaledHeight / cH;
    var FILL_THRESHOLD = 0.84;

    if(fillRatio >= FILL_THRESHOLD){ el.style.objectFit = 'fill'; setBg(false); }
    else { el.style.objectFit = 'contain'; setBg(true); }
  }
  window.addEventListener('resize',function(){
    var el=$('hlMedia').querySelector('.hl-media-item');
    if(el) hlFixFit(el);
  });

  /* ══════════════════ pause state ══════════════════ */
  function isPaused(){
    return flipping || capOpen || $('hlSheet').classList.contains('show') || $('hlMediaPanel').classList.contains('show') || offscreen || document.activeElement===$('hlCIn');
  }
  function syncVideo(){          // pauses / resumes the video AND the music together
    var paused=isPaused();
    var v=$('hlMedia').querySelector('video');
    if(v){ if(paused) v.pause(); else v.play().catch(function(){}); }
    if(audSrc){ if(paused) aud.pause(); else aud.play().catch(function(){}); }
  }
  function stopAudio(){ aud.pause(); audSrc=''; aud.removeAttribute('src'); aud.load(); }
  function playSong(path, loop, restart){
    if(!path){ if(audSrc) stopAudio(); return; }
    var changed = audSrc!==path;
    if(changed){ aud.src=path; audSrc=path; }
    if(changed || restart){ try{ aud.currentTime=0; }catch(e){} }
    aud.loop=loop; aud.muted=!soundOn;
    if(!isPaused()) aud.play().catch(function(){});
  }
  function updateSoundBtn(m){
    var has = !!audSrc || (m.type==='video' && !m.mute);
    var b=$('hlSnd'); b.style.display = has ? '' : 'none';
    b.querySelector('i').className = soundOn ? 'dz-snd on' : 'dz-snd off';
  }
  window.hlSound = function(){
    soundOn=!soundOn; aud.muted=!soundOn;
    var m=H[hi].media[mi], v=$('hlMedia').querySelector('video');
    if(v) v.muted = !soundOn || !!m.mute;
    updateSoundBtn(m);
  };
  /* browsers may block music until the first tap, so the first touch starts it */
  $('hlStage').addEventListener('pointerdown', function(){
    if(audSrc && aud.paused && !isPaused()) aud.play().catch(function(){});
  }, {passive:true});

  /* ══════════════════ open / close ══════════════════ */
  window.openHighlightsModal = function(id){
    var m=$('hlModal');
    introFor = null;
    m.classList.add('open'); void m.offsetHeight; m.classList.add('vis');
    document.body.style.overflow='hidden';
    $('hlLoader').classList.add('show');
    updateNav();
    setupInput();
    offscreen = false; cmHid = 0; $('hlScroll').scrollTop = 0; $('hlComments').classList.remove('cm-on'); setupObserver();

    function go(){
      var idx=-1;
      H.forEach(function(h,i){ if(String(h.id)===String(id)) idx=i; });
      if(idx<0){ close(); return; }
      $('hlLoader').classList.remove('show');
      hi=idx; mi=0; render();
    }
    if(cache){ go(); return; }
    post('action=get_profile_highlights&profile_id='+PROFILE_ID).then(function(d){
      if(!d||!d.ok||!d.highlights.length){ close(); return; }
      H=d.highlights; owner=d.owner; cache=true; go();
    }).catch(function(){ close(); });
  };

  function close(){
    introFor = null; var _in=$('hlIntro'); if(_in) _in.classList.remove('show');
    renderTok++; cleanupFlip(); stopMedia(); stopAudio(); stopPoll(); hlCloseSheet();
    $('hlMediaPanel').classList.remove('show'); $('hlMediaBtn').classList.remove('on');
    capOpen=false;
    var _ci=$('hlCIn'); if(_ci) _ci.blur();
    cmSheetReset(); cmHid=0;
    $('hlPops').innerHTML='';
    $('hlNavPrev').classList.add('off'); $('hlNavNext').classList.add('off');
    var m=$('hlModal'); m.classList.remove('vis');
    setTimeout(function(){ m.classList.remove('open'); $('hlMedia').innerHTML=''; $('hlCapLayer').innerHTML=''; $('hlLoader').classList.remove('show'); },150);
    document.body.style.overflow='';
  }
  window.hlClose = close;

  function stopMedia(){
    if(raf){ cancelAnimationFrame(raf); raf=null; }
    var v=$('hlMedia').querySelector('video');
    if(v){ v.pause(); v.removeAttribute('src'); v.load(); }
  }

  /* ══════════════════ progress loop (same bar for images + videos) ══════════════════ */
  function runProgress(fill, video){
    elapsed = 0; lastTs = null; fill.style.width = '0%';
    function tick(ts){
      if(lastTs==null) lastTs = ts;
      var p;
      if(video){
        p = video.duration ? video.currentTime / video.duration : 0;   // ends via the 'ended' event
      } else {
        if(!isPaused()) elapsed += ts - lastTs;
        p = elapsed / IMG_MS;
      }
      lastTs = ts;
      fill.style.width = Math.min(100, p*100) + '%';
      if(!video && p >= 1){ raf = null; hlNext(); return; }
      raf = requestAnimationFrame(tick);
    }
    raf = requestAnimationFrame(tick);
  }

  /* first frame of the current media is on screen (used to end the cube flip without a black flash) */
  function markReady(){
    mediaOK = true;
    if(readyCb){ var c = readyCb; readyCb = null; c(); }
  }

  /* ══════════════════ render current highlight + media ══════════════════ */
  /* the highlight's name over a dark layer for a moment — only when a highlight opens or you move to another one */
  var introFor = null;
  function showIntro(h){
    var el = $('hlIntro'), tx = $('hlIntroTxt');
    if(!el || !tx) return;
    el.classList.remove('show');
    if(!h || !h.title) return;
    tx.textContent = h.title;
    void el.offsetWidth;                      // restart the animation
    el.classList.add('show');
  }
  function render(){
    stopMedia();
    var tok = ++renderTok;
    var h=H[hi], m=h.media[mi];
    if(introFor !== h.id){ introFor = h.id; showIntro(h); }
    mediaOK = false; capOpen = false;
    updateNav();

    setAvatar($('hlAvatar'), h);
    $('hlTitle').textContent=h.title;
    $('hlCount').textContent=(mi+1)+'/'+h.media.length;
    $('hlDate').textContent=fmtDate(h.created_at);
    setLike(!!m.liked);
    applyOpts(h);
    mediaPanelUpdate();

    /* words written on this media (red @people, blue groups) */
    $('hlCapLayer').innerHTML = m.caption ? capHTML(m.caption, m.men) : '';

    /* music: one song across the whole highlight, or a song for each media */
    if(h.mode==='each') playSong(m.audio, false, true);   // restarts on every media
    else playSong(h.song, true, false);                   // keeps playing across media, loops
    var sTitle = (h.mode==='each') ? (m.audio ? m.audioTitle : '') : (h.song ? h.songTitle : '');
    var SONG_MAX = 40;   // song names longer than this many letters are cut and end with …
    var sChars = Array.from(String(sTitle || '').trim());
    if(sChars.length > SONG_MAX) sTitle = sChars.slice(0, SONG_MAX).join('').replace(/\s+$/,'') + '…';
    $('hlSong').textContent = sTitle ? '♪ '+sTitle : '';

    var ds='';
    h.media.forEach(function(x,i){
      ds+='<div class="hl-dash'+(i<mi?' done':'')+'"><div class="hl-dash-fill"></div></div>';
    });
    $('hlDashes').innerHTML=ds;
    curFill=$('hlDashes').children[mi].firstChild;
    var fill=curFill;

    var box=$('hlMedia'); box.innerHTML='';

    if(m.type==='video'){
      var v=document.createElement('video');
      v.className='hl-media-item'; v.setAttribute('playsinline','');
      box.appendChild(v);
      v.addEventListener('loadedmetadata',function(){ hlFixFit(v); });
      v.addEventListener('loadeddata',markReady);
      v.addEventListener('ended',function(){ if(tok===renderTok) hlNext(); });
      v.src=m.path;
      v.muted = !!m.mute || !soundOn;   // 'Muted' videos let only the song play
      if(!isPaused()) v.play().catch(function(){ v.muted=true; v.play().catch(function(){}); });
      runProgress(fill, v);
    } else {
      var im=document.createElement('img'); im.className='hl-media-item'; im.alt='';
      box.appendChild(im);

      var begun=false;
      function begin(){
        if(begun || tok!==renderTok) return;
        begun=true; hlFixFit(im); markReady(); runProgress(fill, null);   // timer starts once the image is actually visible
      }
      im.addEventListener('load',begin);
      im.onerror=function(){ im.onerror=null; im.src=HL_TEXT_BG; };
      im.src = (m.type==='text') ? HL_TEXT_BG : m.path;   // text-only media sit on Text.jpg
      if(im.complete && im.naturalWidth) begin();
    }
    updateSoundBtn(m);
    preload();
    startPoll();
  }

  function preload(){
    var h=H[hi], n = h.media[mi+1] || (H[hi+1] && H[hi+1].media[0]);
    if(n && n.type!=='video'){ var i=new Image(); i.src=(n.type==='text'?HL_TEXT_BG:n.path); }
    var p = H[hi-1] && H[hi-1].media[0];
    if(p && p.type!=='video'){ var j=new Image(); j.src=(p.type==='text'?HL_TEXT_BG:p.path); }
  }

  /* ══════════════════ 3D CUBE FLIP between highlights ══════════════════
     The live screen is one face of a cube. A light copy of the next (or previous) highlight
     is the neighbouring face. While you drag, the cube turns with your finger; on release it
     finishes the turn or snaps back. The same turn plays when a highlight ends by itself,
     and when you press the side arrows. */
  var cube = $('hlCube'), face = $('hlFace');

  function setCube(angle, W){ cube.style.transform = 'translateZ(' + (-W/2) + 'px) rotateY(' + angle + 'deg)'; }

  var MENU_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M2 6h20M2 12h20M2 18h20"/></svg>';

  function buildPeek(idx, tmi){
    var h = H[idx], m = h.media[tmi], body;
    if(m.type==='video') body = '<video class="hl-media-item" src="'+esc(m.path)+'#t=0.1" muted playsinline preload="metadata"></video>';
    else body = '<img class="hl-media-item" alt="" src="'+esc(m.type==='text' ? HL_TEXT_BG : m.path)+'">';
    var dashes = '';
    h.media.forEach(function(x,i){ dashes += '<div class="hl-dash'+(i<tmi?' done':'')+'"><div class="hl-dash-fill"></div></div>'; });
    var el = document.createElement('div');
    el.className = 'hl-peek';
    el.innerHTML =
      '<div class="hl-peek-media">'+body+'</div>' +
      '<div class="hl-shade-top"></div><div class="hl-shade-bottom"></div>' +
      '<div class="hl-cap-layer">'+(m.caption ? capHTML(m.caption, m.men) : '')+'</div>' +
      '<div class="hl-top"><div class="hl-dashes">'+dashes+'</div>' +
        '<div class="hl-head"><div class="hl-av-col"><img class="hl-avatar" src="'+esc(owner.pic)+'" alt="" onerror="this.onerror=null;this.src=\'mee.jpg\'"></div>' +
        '<div class="hl-head-info"><div class="hl-title-row"><span class="hl-title">'+esc(h.title)+'</span><span class="hl-date">'+esc(fmtDate(h.created_at))+'</span></div></div>' +
        '<span class="hl-icon-btn hl-menu">'+MENU_SVG+'</span>' +
        '<div class="hl-x-col"><span class="hl-icon-btn hl-x"><i class="ri-close-line"></i></span><span class="hl-count">'+(tmi+1)+'/'+h.media.length+'</span></div></div></div>' +
      '<div class="hl-bottom"><div class="hl-bar"><span class="hl-fake">Send a message…</span></div>' +
        '<span class="ipv-action-btn hl-ab'+(h.liked?' liked':'')+'"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i></span>' +
        (h.sharesOff?'':'<span class="ipv-action-btn hl-ab"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i></span>') + '</div>';
    return el;
  }

  function flipBegin(dir, idx, tmi){
    var W = $('hlStage').clientWidth;
    var peek = buildPeek(idx, tmi);
    flip = { dir: dir, idx: idx, tmi: tmi, peek: peek, W: W };
    flipping = true;
    cube.classList.add('flip');
    cube.style.transition = 'none';
    face.style.transform = 'translateZ(' + (W/2) + 'px)';
    peek.style.transform = 'rotateY(' + (dir*90) + 'deg) translateZ(' + (W/2) + 'px)';
    cube.appendChild(peek);
    setCube(0, W);
    var mm = peek.querySelector('.hl-media-item');
    if(mm){
      if(mm.tagName === 'VIDEO') mm.addEventListener('loadedmetadata', function(){ hlFixFit(mm); });
      else { mm.addEventListener('load', function(){ hlFixFit(mm); }); if(mm.complete && mm.naturalWidth) hlFixFit(mm); }
    }
    syncVideo();
  }
  function flipMove(dx){
    if(!flip) return;
    var p = Math.max(0, Math.min(1, (-flip.dir * dx) / flip.W));
    setCube(-flip.dir * p * 90, flip.W);
  }
  function cleanupFlip(){
    if(!flip) { flipping = false; return; }
    cube.style.transition = 'none';
    cube.style.transform = '';
    cube.classList.remove('flip');
    face.style.transform = '';
    if(flip.peek.parentNode) flip.peek.parentNode.removeChild(flip.peek);
    flip = null; flipping = false;
    if($('hlModal').classList.contains('open')) syncVideo();
  }
  function flipEnd(commit){
    if(!flip) return;
    var f = flip, done = false;
    void cube.offsetWidth;   // lock in the starting angle before animating
    cube.style.transition = 'transform .42s cubic-bezier(.22,.75,.28,1)';
    setCube(commit ? -f.dir * 90 : 0, f.W);
    function onEnd(e){ if(e.target === cube) fin(); }
    function fin(){
      if(done) return; done = true;
      cube.removeEventListener('transitionend', onEnd);
      if(flip !== f) return;                       // closed or reset meanwhile
      if(!commit){ cleanupFlip(); return; }
      hi = f.idx; mi = f.tmi;
      readyCb = null;
      render();                                    // real content goes on the live face (still turned away)
      var settled = false;
      function settle(){ if(settled) return; settled = true; readyCb = null; requestAnimationFrame(cleanupFlip); }
      if(mediaOK) settle(); else { readyCb = settle; setTimeout(settle, 450); }
    }
    cube.addEventListener('transitionend', onEnd);
    setTimeout(fin, 560);
  }
  /* plays the whole turn by itself. Returns false when there is no highlight in that direction. */
  function autoFlip(dir, tmi){
    var idx = hi + dir;
    if(!H || idx < 0 || idx >= H.length) return false;
    flipBegin(dir, idx, tmi);
    flipEnd(true);
    return true;
  }

  /* ══════════════════ navigation ══════════════════ */
  window.hlNext = function(){
    if(flipping || $('hlSheet').classList.contains('show')) return;
    var h=H[hi];
    if(mi<h.media.length-1){ mi++; render(); }
    else if(hi<H.length-1){ autoFlip(1, 0); }
    else close();
  };
  window.hlPrev = function(){
    if(flipping || $('hlSheet').classList.contains('show')) return;
    if(mi>0){ mi--; render(); }
    else if(hi>0){ autoFlip(-1, H[hi-1].media.length-1); }
    else render();
  };

  /* ══════════════════ ALL MEDIA of this highlight ══════════════════
     The button beside Like opens a strip with every media of the highlight you are in.
     Scroll it sideways, tap one to jump straight to it, tap ✕ (or the button again) to close. */
  function mediaPanelOpen(){ return $('hlMediaPanel').classList.contains('show'); }
  function mediaPanelUpdate(){
    if(!H || !H[hi]) return;
    var h = H[hi], n = h.media.length;
    $('hlMediaCount').textContent = n > 1 ? n : '';
    if(!mediaPanelOpen()) return;
    $('hlMpTitle').textContent = (mi+1) + ' of ' + n + (n === 1 ? ' media' : ' media');
    var strip = $('hlMpStrip');
    if(strip.getAttribute('data-hl') !== String(hi) || strip.children.length !== n){
      strip.setAttribute('data-hl', String(hi));
      var html = '';
      h.media.forEach(function(x, i){
        var inner;
        if(x.type === 'video') inner = '<video src="'+esc(x.path)+'#t=0.1" muted playsinline preload="metadata"></video><i class="ri-play-fill hl-mp-vid"></i>';
        else if(x.type === 'text') inner = '<img alt="" src="'+esc(HL_TEXT_BG)+'"><span class="hl-mp-txt">Aa</span>';
        else inner = '<img alt="" loading="lazy" src="'+esc(x.path)+'" onerror="this.onerror=null;this.src=\''+esc(HL_TEXT_BG)+'\'">';
        html += '<button type="button" class="hl-mp-item" data-i="'+i+'" onclick="hlMediaGo('+i+')" aria-label="Media '+(i+1)+'">'+inner+'<span class="hl-mp-num">'+(i+1)+'</span></button>';
      });
      strip.innerHTML = html;
    }
    Array.prototype.forEach.call(strip.children, function(el, i){ el.classList.toggle('cur', i === mi); });
    var cur = strip.children[mi];
    if(cur){
      var left = cur.offsetLeft - (strip.clientWidth - cur.offsetWidth) / 2;
      try{ strip.scrollTo({ left: Math.max(0, left), behavior: 'smooth' }); }catch(_){ strip.scrollLeft = Math.max(0, left); }
    }
  }
  window.hlMediaToggle = function(){
    if(!H || flipping) return;
    dzHp('light');
    if(mediaPanelOpen()){ hlMediaClose(); return; }
    var p = $('hlMediaPanel');
    p.classList.add('show'); p.setAttribute('aria-hidden','false');
    $('hlMediaBtn').classList.add('on');
    syncVideo();            // pauses the video + music while you browse
    mediaPanelUpdate();
  };
  window.hlMediaClose = function(){
    var p = $('hlMediaPanel');
    if(!p.classList.contains('show')) return;
    p.classList.remove('show'); p.setAttribute('aria-hidden','true');
    $('hlMediaBtn').classList.remove('on');
    poke();
    if($('hlModal').classList.contains('open')) syncVideo();
  };
  window.hlMediaGo = function(i){
    if(!H || flipping) return;
    var h = H[hi];
    if(i < 0 || i >= h.media.length) return;
    if(i !== mi){ mi = i; render(); }
    else mediaPanelUpdate();
  };

  /* ══════════════════ like ══════════════════ */
  /* owner switches: comments off / sharing off (per highlight) */
  function applyOpts(h){
    var cOff=!!(h&&h.commentsOff), sOff=!!(h&&h.sharesOff);
    var sb=$('hlShareBtn'); if(sb) sb.classList.toggle('hl-off',sOff);
    if(typeof paintTopCm==='function') paintTopCm();
    var bar=document.querySelector('#hlModal .hl-bar'); if(bar) bar.classList.toggle('hl-bar-off',cOff&&!(owner&&owner.isYou));
    var t=$('hlBarTxt'); if(t&&LOGGED) t.textContent=(cOff&&!(owner&&owner.isYou))?'Comments are turned off':(cOff?'Comments are off for others':'Add a comment…');
    var inp=$('hlCIn'); if(inp&&LOGGED){ var blocked=cOff&&!(owner&&owner.isYou); inp.disabled=blocked; inp.placeholder=blocked?'Comments are turned off':'Add a comment…'; }
  }
  window.hlSetOpt=function(kind){
    var h=H&&H[hi]; if(!h||!owner||!owner.isYou) return;
    dzHp('light');
    var was={c:!!h.commentsOff,s:!!h.sharesOff};
    if(kind==='comments') h.commentsOff=!h.commentsOff; else h.sharesOff=!h.sharesOff;
    applyOpts(h); hlCloseSheet();
    post('action=hl_set_opts&highlight_id='+h.id+'&comments_off='+(h.commentsOff?1:0)+'&shares_off='+(h.sharesOff?1:0)).then(function(d){
      if(d&&d.ok){ dzHp('success'); toast(kind==='comments'?(h.commentsOff?'Comments turned off':'Comments turned on'):(h.sharesOff?'Sharing turned off':'Sharing turned on')); }
      else { dzHp('error'); h.commentsOff=was.c; h.sharesOff=was.s; applyOpts(h); toast('Could not save'); }
    }).catch(function(){ dzHp('error'); h.commentsOff=was.c; h.sharesOff=was.s; applyOpts(h); toast('Network error'); });
  };
  /* one like per MEDIA (media 1 and 3 can be liked while 2 is not). No counts are shown. Painting the state never animates — only a tap does. */
  function setLike(liked){
    var b=$('hlLikeBtn'); b.classList.toggle('liked',!!liked);
    var c=$('hlLikeCount'); if(c) c.textContent='';
  }
  window.hlLike = function(){
    if(!LOGGED){ toast('Log in to like'); return; }
    var h=H[hi], m=h&&h.media[mi]; if(!m||!m.id) return;
    var was=!!m.liked;
    dzHp(was ? 'light' : 'medium');
    poke();
    m.liked=!was; setLike(m.liked);
    if(m.liked){ var i=$('hlLikeBtn').querySelector('i'); i.classList.remove('pop'); void i.offsetWidth; i.classList.add('pop'); }
    var isHere=function(){ return H[hi]===h && h.media[mi]===m; };
    post('action=hl_like&highlight_id='+h.id+'&media_id='+m.id).then(function(d){
      if(!d||!d.ok){ dzHp('error'); m.liked=was; if(isHere()) setLike(m.liked); return; }
      m.liked=!!d.liked; if(isHere()) setLike(m.liked);
    }).catch(function(){ m.liked=was; if(isHere()) setLike(m.liked); });
  };

  /* ══════════════════ messages (pop up + disappear) ══════════════════ */
  /* ══════════════════ header picture = the highlight's cover ══════════════════
     cover photo / first image / the text image come from the highlight's tile on the profile (same pick it makes);
     a video shows its first frame; a poll or text-only highlight shows the default text image */
  function drawFrame(v){
    var c=document.createElement('canvas'); c.width=v.videoWidth||240; c.height=v.videoHeight||240;
    c.getContext('2d').drawImage(v,0,0,c.width,c.height);
    return c.toDataURL('image/jpeg',.8);
  }
  function videoFrame(src,cb,tileVid){
    if(frameCache[src]){ cb(frameCache[src]); return; }
    /* the profile tile already decoded this video's first frame: copy it straight away */
    if(tileVid && tileVid.readyState>=2 && tileVid.videoWidth){
      try{ frameCache[src]=drawFrame(tileVid); cb(frameCache[src]); return; }catch(e){}
    }
    var v=document.createElement('video'); v.muted=true; v.defaultMuted=true; v.playsInline=true; v.preload='auto';
    v.setAttribute('muted',''); v.setAttribute('playsinline','');
    v.style.cssText='position:fixed;left:-9999px;top:0;width:2px;height:2px;opacity:0;pointer-events:none';
    var done=false, seeked=false;
    function finish(ok){
      if(done) return; done=true;
      if(ok){ try{ frameCache[src]=drawFrame(v); cb(frameCache[src]); }catch(e){} }
      try{ v.pause(); v.removeAttribute('src'); v.load(); }catch(e){}
      if(v.parentNode) v.parentNode.removeChild(v);
    }
    function seek(){ if(seeked) return; seeked=true; try{ v.currentTime=Math.min(0.1,(v.duration||1)/2); }catch(e){ finish(true); } }
    v.addEventListener('loadedmetadata',seek);
    v.addEventListener('loadeddata',function(){ seek(); setTimeout(function(){ if(v.readyState>=2) finish(true); },700); });
    v.addEventListener('seeked',function(){ finish(true); });
    v.addEventListener('error',function(){ finish(false); });
    setTimeout(function(){ finish(v.readyState>=2); },8000);
    document.body.appendChild(v);
    v.src=src;
    try{ v.load(); }catch(e){}
  }
  function setAvatar(av,h){
    var tok=++avTok;
    function use(u){ if(tok===avTok&&u) av.src=u; }
    var tile=document.querySelector('.rp-hl[data-hl-id="'+h.id+'"]');
    var im=tile&&tile.querySelector('img'), vd=tile&&tile.querySelector('video');
    var m0=(h.media&&h.media[0])||null;
    var imSrc=im?(im.getAttribute('src')||im.getAttribute('data-src')||''):'';
    if(imSrc){ use(imSrc); return; }
    var vSrc=vd?String(vd.getAttribute('src')||vd.getAttribute('data-src')||'').split('#')[0]:'';
    if(!vSrc && m0 && m0.type==='video') vSrc=m0.path;
    if(vSrc){ use(HL_TEXT_BG); videoFrame(vSrc,use,vd); return; }
    if(m0&&m0.type==='image'&&m0.path){ use(m0.path); return; }
    use(HL_TEXT_BG);
  }

  /* ══════════════════ comments section (below the box) ══════════════════ */
  function setupObserver(){
    return;
    if(ioDone||!('IntersectionObserver' in window)) return;
    ioDone=true;
    new IntersectionObserver(function(es){
      es.forEach(function(e){
        offscreen=e.intersectionRatio<0.5;
        $('hlComments').classList.toggle('cm-on',offscreen);
        if($('hlModal').classList.contains('open')) syncVideo();
      });
    },{root:$('hlScroll'),threshold:[0,.25,.5,.75,1]}).observe($('hlStage'));
  }
  window.hlToTop=function(){};
  window.hlOpenComments=function(focus){
    if(!H||!H[hi]) return;
    if(H[hi].commentsOff && !(owner&&owner.isYou)){ dzHp('error'); hlCloseSheet(); toast('Comments are turned off'); return; }
    dzHp('light');
    hlCloseSheet();
    cmCloseMenu();
    $('hlCmOv').classList.add('show'); $('hlComments').classList.add('show');
    offscreen=true; syncVideo();
    if(focus){ setTimeout(function(){ var i=$('hlCIn'); if(i&&!i.disabled) i.focus({preventScroll:true}); },340); }
  };
  function cmSheetReset(){ $('hlCmOv').classList.remove('show'); $('hlComments').classList.remove('show'); offscreen=false; var i=$('hlCIn'); if(i) i.blur(); cmCloseMenu(); }
  window.hlCloseComments=function(){
    dzHp('light'); cmSheetReset();
    if($('hlModal').classList.contains('open')) syncVideo();
  };
  window.hlGoComments=function(focus){ window.hlOpenComments(focus); };
  window.hlCmNav=function(){ window.hlOpenComments(false); };

  function ago(s){
    var d=new Date(String(s||'').replace(' ','T')); if(isNaN(d)) return '';
    var t=Math.max(0,(Date.now()-d.getTime())/1000);
    if(t<60) return 'now'; if(t<3600) return Math.floor(t/60)+'m'; if(t<86400) return Math.floor(t/3600)+'h';
    if(t<604800) return Math.floor(t/86400)+'d'; if(t<2592000) return Math.floor(t/604800)+'w';
    if(t<31536000) return Math.floor(t/2592000)+'mo'; return Math.floor(t/31536000)+'y';
  }
  var cmReply=null, cmOpen={}, topPick=0, topPickFor=0, topLast={};
  function repLabel(n){ return n===1?'1 reply':n+' replies'; }
  function cmItem(c,fresh,kids){
    var name=c.mine?'You':c.name, hasK=kids&&kids.length;
    var h=H&&H[hi], canR=LOGGED && h && (!h.commentsOff || (owner&&owner.isYou));
    var rootId=c.parent||c.id;
    var main='<div class="hl-ci-main">'+
      '<div class="hl-ci-avcol"><a class="hl-ci-av" href="'+HL_USER_URL+c.uid+'"><img src="'+esc(c.pic)+'" alt="" loading="lazy" onerror="this.onerror=null;this.src=\'mee.jpg\'"></a><span class="hl-ci-line"></span></div>'+
      '<div class="hl-ci-body"><div class="hl-ci-l1"><a class="hl-ci-un" href="'+HL_USER_URL+c.uid+'">'+esc(name)+'</a>'+(c.verified?(window.DZ_VB||''):'')+'<span class="hl-ci-t">'+esc(ago(c.at))+'</span>'+
      (c.mine?'<button type="button" class="hl-ci-more" data-more="'+c.id+'" aria-label="More"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="16" height="16"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></button>':'')+'</div>'+
      '<div class="hl-ci-tx">'+esc(c.text)+'</div>'+
      (canR?'<div class="hl-ci-r2"><button type="button" class="hl-ci-reply" data-reply="'+rootId+'" data-name="'+esc(c.name)+'">Reply</button></div>':'')+
      '</div></div>';
    var thread='';
    if(hasK){
      var open=!!cmOpen[c.id];
      thread='<button type="button" class="hl-ci-tg'+(open?' open':'')+'" data-tg="'+c.id+'"><span class="hl-ci-tgav"><img src="'+esc(kids[0].pic)+'" alt="" onerror="this.onerror=null;this.src=\'mee.jpg\'"></span><span class="hl-ci-tgd">·</span><span>'+repLabel(kids.length)+'</span><i class="ri-arrow-right-s-line hl-ci-chev"></i></button>';
      if(open) thread+='<div class="hl-sub">'+kids.map(function(k){ return cmItem(k,fresh&&k.id===fresh,null); }).join('')+'</div>';
    }
    return '<div class="hl-ci'+(hasK?' has-thread':'')+(fresh?' fresh':'')+'" data-id="'+c.id+'">'+main+thread+'</div>';
  }
  function cmRender(fresh){
    var box=$('hlCmList'), n=cmList.length;
    $('hlCmCount').textContent=n?fmtNum(n):'';
    if(!n){ box.innerHTML='<div class="hl-cm-empty"><i class="ri-chat-smile-3-line"></i>No comments yet. Be the first!</div>'; paintTopCm(); return; }
    var kids={}, roots=[];
    cmList.forEach(function(c){ if(c.parent){ (kids[c.parent]=kids[c.parent]||[]).push(c); } else roots.push(c); });
    Object.keys(kids).forEach(function(k){ kids[k].sort(function(a,b2){ return a.id-b2.id; }); });
    box.innerHTML=roots.map(function(c){ return cmItem(c, fresh&&c.id===fresh, kids[c.id]||null); }).join('');
    paintTopCm();
  }
  /* the single comment above the text bar: the newest one */
  function paintTopCm(){
    var el=$('hlTopCm'); if(!el) return;
    var h=H&&H[hi], off=h&&h.commentsOff&&!(owner&&owner.isYou), best=null;
    if(!off&&h&&cmList.length){
      /* a different comment each time the highlight is opened: random pick, never the one shown last time (when there is another) */
      if(topPickFor===h.id) cmList.forEach(function(c){ if(c.id===topPick) best=c; });
      if(!best){
        var pool=cmList.filter(function(c){ return c.id!==topLast[h.id]; });
        if(!pool.length) pool=cmList;
        best=pool[Math.floor(Math.random()*pool.length)];
        topPick=best.id; topPickFor=h.id; topLast[h.id]=best.id;
      }
    }
    if(!best){ el.style.display='none'; el.removeAttribute('data-id'); return; }
    var img=$('hlTopCmAv'); if(img){ img.onerror=function(){ this.onerror=null; this.src='mee.jpg'; }; img.src=best.pic||'mee.jpg'; }
    $('hlTopCmTx').textContent=String(best.text||'').replace(/\s+/g,' ');
    if(el.getAttribute('data-id')!==String(best.id)){ el.setAttribute('data-id',best.id); el.style.animation='none'; void el.offsetWidth; el.style.animation=''; }
    el.style.display='flex';
  }
  window.hlOpenTopComment=function(){
    var el=$('hlTopCm'), id=el?+el.getAttribute('data-id'):0; if(!id) return;
    var pc=null; cmList.forEach(function(x){ if(x.id===id) pc=x; });
    if(pc&&pc.parent) cmOpen[pc.parent]=true;
    cmRender();
    hlOpenComments(false);
    setTimeout(function(){
      var n=$('hlCmList').querySelector('.hl-ci[data-id="'+id+'"]'); if(!n) return;
      var box=$('hlCmList'); box.scrollTop=Math.max(0,n.offsetTop-box.clientHeight/3);
      n.classList.add('hl-flash'); setTimeout(function(){ n.classList.remove('hl-flash'); },1900);
    },340);
  };
  function paintReplyChip(){
    var chip=$('hlReplyChip'), inp=$('hlCIn'); if(!chip||!inp) return;
    if(cmReply){ chip.querySelector('b').textContent='@'+cmReply.name; chip.classList.add('show'); inp.placeholder='Reply to @'+cmReply.name+'…'; }
    else { chip.classList.remove('show'); inp.placeholder='Add a comment…'; }
  }
  function cmLoad(fresh){
    var h=H&&H[hi]; if(!h) return;
    var tok=++cmTok, hid=h.id;
    if(!cmList.length) $('hlCmList').innerHTML='<div class="hl-cm-empty"><span class="hl-cm-spin"></span></div>';
    post('action=hl_list&highlight_id='+hid+'&sort='+cmSort).then(function(d){
      if(tok!==cmTok||!d||!d.ok) return;
      cmList=d.comments||[]; cmRender(fresh);
    }).catch(function(){ if(tok===cmTok) $('hlCmList').innerHTML='<div class="hl-cm-empty">Could not load comments</div>'; });
  }
  function cmCloseMenu(){ var mn=$('hlCmMenu'); if(mn) mn.classList.remove('show'); var sm=$('hlCmSortMenu'); if(sm) sm.classList.remove('show'); }

  function setupInput(){
    var inp=$('hlCIn'); if(!inp||inp._ready) return; inp._ready=true;
    var send=$('hlCSend');
    if(!LOGGED){ inp.placeholder='Log in to comment'; inp.disabled=true; $('hlBarTxt').textContent='Log in to comment'; }
    var _mp=window.HL_ME_PIC||(window.dzMe&&window.dzMe.profile_pic)||''; if(_mp) $('hlCmMyPic').src=_mp;
    inp.addEventListener('focus',function(){ syncVideo(); });
    inp.addEventListener('blur', function(){ syncVideo(); });
    inp.addEventListener('input',function(){ send.disabled=!inp.value.trim(); });
    inp.addEventListener('keydown',function(e){ e.stopPropagation(); if(e.key==='Enter'){ e.preventDefault(); hlSend(); } });
    send.addEventListener('click',function(){ hlSend(); });
    var chipX=$('hlReplyChip'); if(chipX) chipX.addEventListener('click',function(e){ if(e.target.closest('[data-cancel-reply]')){ cmReply=null; paintReplyChip(); if(inp.value.trim().charAt(0)==='@') { inp.value=''; send.disabled=true; } } });
    var emo=$('hlEmo'); if(emo) emo.addEventListener('click',function(e){
      var sp=e.target.closest('span'); if(!sp||inp.disabled) return;
      dzHp('selection');
      var st=inp.selectionStart==null?inp.value.length:inp.selectionStart, en=inp.selectionEnd==null?st:inp.selectionEnd;
      inp.value=inp.value.slice(0,st)+sp.textContent+inp.value.slice(en);
      var pos=st+sp.textContent.length; send.disabled=!inp.value.trim();
      try{ inp.setSelectionRange(pos,pos); }catch(_){}
    });
    /* sort menu + the ⋯ menu on my own comments */
    $('hlCmSortBtn').addEventListener('click',function(e){ e.stopPropagation(); var sm=$('hlCmSortMenu'), on=!sm.classList.contains('show'); cmCloseMenu(); if(on) sm.classList.add('show'); });
    $('hlCmSortMenu').addEventListener('click',function(e){
      var b=e.target.closest('button[data-sort]'); if(!b) return;
      cmSort=b.getAttribute('data-sort'); $('hlCmSortLbl').textContent=cmSort==='old'?'Oldest':'Newest';
      [].forEach.call($('hlCmSortMenu').querySelectorAll('button'),function(x){ x.classList.toggle('active',x===b); });
      cmCloseMenu(); cmLoad();
    });
    $('hlCmList').addEventListener('click',function(e){
      var tg=e.target.closest('[data-tg]');
      if(tg){ var tid=+tg.getAttribute('data-tg'); cmOpen[tid]=!cmOpen[tid]; dzHp('light'); cmRender(); return; }
      var rb=e.target.closest('[data-reply]');
      if(rb){
        cmReply={ id:+rb.getAttribute('data-reply'), name:rb.getAttribute('data-name') };
        dzHp('light'); paintReplyChip();
        inp.value='@'+cmReply.name+' '; send.disabled=false;
        inp.focus({preventScroll:true}); try{ inp.setSelectionRange(inp.value.length,inp.value.length); }catch(_){}
        return;
      }
      var mb=e.target.closest('[data-more]'); if(!mb) return;
      e.stopPropagation(); cmCloseMenu();
      cmMenuId=+mb.getAttribute('data-more');
      var mn=$('hlCmMenu'), r=mb.getBoundingClientRect(), wr=$('hlComments').getBoundingClientRect();
      mn.style.top=(r.bottom-wr.top+4)+'px'; mn.style.right=Math.max(8,wr.right-r.right)+'px'; mn.style.left='auto';
      mn.classList.add('show');
    });
    $('hlCmMenu').addEventListener('click',function(e){
      var b=e.target.closest('button[data-act="delete"]'); if(!b) return;
      var id=cmMenuId; cmCloseMenu(); if(!id) return;
      post('action=hl_delete_comment&comment_id='+id).then(function(d){
        if(d&&d.ok){ cmList=cmList.filter(function(c){ return c.id!==id && c.parent!==id; }); if(cmReply&&cmReply.id===id){ cmReply=null; paintReplyChip(); } cmRender(); toast('Comment deleted'); } else toast('Could not delete');
      }).catch(function(){ toast('Network error'); });
    });
    document.addEventListener('click',cmCloseMenu);
    $('hlScroll').addEventListener('scroll',cmCloseMenu,{passive:true});
  }

  window.hlSend = function(){
    if(!LOGGED||sending) return;
    var inp=$('hlCIn'), txt=inp.value.trim();
    if(!txt) return;
    dzHp('light');
    sending=true; poke();
    var h=H[hi];
    var rp=cmReply;
    post('action=hl_comment&highlight_id='+h.id+'&message='+encodeURIComponent(txt)+(rp?'&parent_id='+rp.id:'')).then(function(d){
      sending=false;
      if(d&&d.ok){
        inp.value=''; $('hlCSend').disabled=true;
        if(rp){ cmOpen[rp.id]=true; cmReply=null; paintReplyChip(); }
        if(H[hi]===h){ cmLoad(d.id); if(!rp) $('hlCmList').scrollTop=0; }
      } else { if(d&&d.error==='off'&&H[hi]){ H[hi].commentsOff=true; applyOpts(H[hi]); } toast(d&&d.error==='slow'?'Slow down a bit':(d&&d.error==='off'?'Comments are turned off':'Could not send')); }
    }).catch(function(){ sending=false; toast('Network error'); });
  };

  /* comments load once per highlight (no more pop-up chat bubbles on the video) */
  function startPoll(){
    var h=H&&H[hi]; if(!h||cmHid===h.id) return;
    cmHid=h.id; cmList=[]; cmTok++; cmReply=null; cmOpen={}; topPick=0; topPickFor=0; paintReplyChip(); cmRender();
    $('hlPops').innerHTML='';
    cmLoad();
  }
  function stopPoll(){ clearTimeout(pollT); pollT=null; clearTimeout(cycleT); cycleT=null; }

  /* ══════════════════ share ══════════════════ */
  function link(){ return location.origin+'/profile.php?user_id='+PROFILE_ID+'&hl='+H[hi].id; }
  window.hlShare = function(){
    if(H[hi]&&H[hi].sharesOff){ toast('Sharing is turned off'); return; }
    dzHp('light');
    if(navigator.share){ navigator.share({title:H[hi].title,url:link()}).catch(function(){}); }
    else hlCopy();
  };
  window.hlCopy = function(){
    hlCloseSheet();
    if(H[hi]&&H[hi].sharesOff){ toast('Sharing is turned off'); return; }
    var u=link();
    if(navigator.clipboard&&navigator.clipboard.writeText){
      navigator.clipboard.writeText(u).then(function(){dzHp('success');toast('Link copied');}).catch(function(){dzHp('error');toast('Could not copy');});
    } else { dzHp('error'); toast('Could not copy'); }
  };

  /* ══════════════════ bottom sheet ══════════════════ */
  window.hlOpenSheet = function(){
    dzHp('light');
    function row(on,ic,label,sub,red,badge){
      return '<div class="mr-row" onclick="'+on+'"><div class="mr-icon"><i class="'+ic+'"></i></div><div class="dz-fx1"><div class="mr-label'+(red?' red':'')+'">'+label+'</div><div class="mr-sub">'+sub+'</div></div>'+(badge?'<span class="mr-state-badge on-mute">'+badge+'</span>':'')+'</div>';
    }
    var hh=H[hi]||{}, isOwn=!!(owner&&owner.isYou);
    var html='';
    if(!hh.commentsOff||isOwn) html+=row('hlOpenComments(false)','ri-chat-3-line','Comments',hh.commentsOff?'Off for everyone else':'Read and add comments',false,cmList.length?fmtNum(cmList.length):'');
    if(!owner.isYou){
      html+=owner.following ? row('hlToggleTrack()','ri-user-unfollow-line','Untrack','Stop tracking their posts and updates',true)
                            : row('hlToggleTrack()','ri-user-add-line','Track','See their posts and updates',false);
    }
    if(!hh.sharesOff) html+=row('hlCopy()','ri-file-copy-line','Copy Link','Copy the link to this highlight',false);
    if(isOwn){
      html+=row('hlSetOpt(\'comments\')',hh.commentsOff?'ri-chat-check-line':'ri-chat-off-line',hh.commentsOff?'Turn on comments':'Turn off comments',hh.commentsOff?'Let people comment again':'Nobody can comment on this highlight',false);
      html+=row('hlSetOpt(\'shares\')',hh.sharesOff?'ri-share-forward-line':'ri-forbid-2-line',hh.sharesOff?'Turn on sharing':'Turn off sharing',hh.sharesOff?'Let people share this highlight again':'Hides Share and Copy Link',false);
    }
    if(!owner.isYou){
      html+=row('hlReport()','ri-flag-line','Report','Tell us what is wrong with this highlight',true);
    }
    $('hlSheetList').innerHTML=html;
    $('hlMyCm').innerHTML='';
    $('hlSheetOv').classList.add('show'); $('hlSheet').classList.add('show');
    syncVideo();
  };

  /* ── my comments (see them, read long ones, delete only my own) ── */
  var MC_WORDS = 20;
  function loadMyComments(){
    if(!LOGGED) return;
    var h=H[hi];
    post('action=hl_my_comments&highlight_id='+h.id).then(function(d){
      if(!d||!d.ok||!d.comments||!d.comments.length) return;
      if(H[hi]!==h || !$('hlSheet').classList.contains('show')) return;
      renderMyComments(d.comments);
    }).catch(function(){});
  }

  function purgeComment(id){
    id=String(id);
    pool=pool.filter(function(m){ return String(m.id)!==id; });
    liveQ=liveQ.filter(function(m){ return String(m.id)!==id; });
    if(cmList.length){ cmList=cmList.filter(function(c){ return String(c.id)!==id; }); cmRender(); }
    [].slice.call($('hlPops').children).forEach(function(el){
      if(el.dataset.mid===id){ el.classList.add('out'); setTimeout(function(){ if(el.parentNode) el.parentNode.removeChild(el); },580); }
    });
  }

  function renderMyComments(list){
    var box=$('hlMyCm');
    box.innerHTML='<div class="hl-mc-head">Your comments<span id="hlMcN"></span></div><div class="hl-mc-list"></div>';
    var listEl=box.querySelector('.hl-mc-list');
    var n=list.length;
    function setN(){ $('hlMcN').textContent=n; }
    setN();

    list.forEach(function(c){
      var words=String(c.text).trim().split(/\s+/);
      var isLong=words.length>MC_WORDS;
      var shortTxt=isLong ? words.slice(0,MC_WORDS).join(' ')+'…' : c.text;
      var open=false;

      var row=document.createElement('div'); row.className='hl-mc-row';
      var main=document.createElement('div'); main.className='hl-mc-main';
      var body=document.createElement('span'); body.textContent=shortTxt;
      main.appendChild(body);
      if(isLong){
        var more=document.createElement('button'); more.type='button'; more.className='hl-mc-more'; more.textContent='More';
        more.onclick=function(){
          open=!open;
          body.textContent=open ? c.text : shortTxt;
          more.textContent=open ? 'Close' : 'More';
        };
        main.appendChild(more);
      }
      var dt=document.createElement('span'); dt.className='hl-mc-date'; dt.textContent=fmtDate(c.at);
      main.appendChild(dt);

      var del=document.createElement('button'); del.type='button'; del.className='hl-mc-del'; del.setAttribute('aria-label','Delete comment');
      function resetDel(){ del.classList.remove('confirm'); del.disabled=false; del.innerHTML='<i class="ri-delete-bin-line"></i>'; }
      resetDel();
      del.onclick=function(){
        if(!del.classList.contains('confirm')){          // first tap asks, second tap deletes
          del.classList.add('confirm'); del.textContent='Delete?';
          clearTimeout(del._t); del._t=setTimeout(resetDel,3000);
          return;
        }
        clearTimeout(del._t); del.disabled=true;
        post('action=hl_delete_comment&comment_id='+c.id).then(function(d){
          if(d&&d.ok){
            purgeComment(c.id);
            row.classList.add('gone');
            setTimeout(function(){
              if(row.parentNode) row.parentNode.removeChild(row);
              n--; if(n<=0) $('hlMyCm').innerHTML=''; else setN();
            },200);
            toast('Comment deleted');
          } else { resetDel(); toast('Could not delete'); }
        }).catch(function(){ resetDel(); toast('Network error'); });
      };

      row.appendChild(main); row.appendChild(del);
      listEl.appendChild(row);
    });
  }
  window.hlCloseSheet = function(){
    $('hlSheetOv').classList.remove('show'); $('hlSheet').classList.remove('show');
    if($('hlModal').classList.contains('open')) syncVideo();
  };

  window.hlToggleTrack = function(){
    dzHp(owner.following ? 'light' : 'medium');
    post('action=toggle_follow&user_id='+owner.id).then(function(d){
      if(d&&d.status==='ok'){ owner.following=d.following; toast(d.following?'Now tracking':'Untracked'); }
      else toast('Something went wrong');
      hlCloseSheet();
    }).catch(function(){ toast('Network error'); hlCloseSheet(); });
  };

  window.hlReport = function(){
    var id=H[hi].id;
    hlCloseSheet();
    if(typeof openReportModal==='function') openReportModal({contentId:id,contentType:'highlight'});
    else toast('Report unavailable');
  };

  /* ══════════════════ swipe = the cube follows your finger ══════════════════
     Drag left → next highlight, drag right → previous. Let go past ~28% of the width
     (or flick fast) and it finishes the turn, otherwise it snaps back.
     With no highlight in that direction, a long drag closes the viewer. */
  function gotoHighlight(dir){
    if(!H || flipping || $('hlSheet').classList.contains('show')) return;
    var idx = hi + dir;
    if(idx < 0 || idx >= H.length) return;
    autoFlip(dir, dir > 0 ? 0 : H[idx].media.length - 1);
  }
  (function(){
    var st = $('hlStage'), sx = 0, sy = 0, pid = null, tracking = false, dragOn = false, dragDir = 0, t0 = 0, lastWheel = 0, suppress = false;

    st.addEventListener('pointerdown', function(e){
      if(!H || flipping || $('hlSheet').classList.contains('show')) return;
      if(e.pointerType === 'mouse' && e.button !== 0) return;
      if(e.target.closest('.hl-bottom') || e.target.closest('#hlSheet') || e.target.closest('.hl-icon-btn') || e.target.closest('#hlMediaPanel')) return;
      tracking = true; dragOn = false; pid = e.pointerId; sx = e.clientX; sy = e.clientY; t0 = Date.now();
    });
    st.addEventListener('pointermove', function(e){
      if(!tracking || e.pointerId !== pid) return;
      var dx = e.clientX - sx, dy = e.clientY - sy;
      if(!dragOn){
        if(Math.abs(dx) < 12 || Math.abs(dx) < Math.abs(dy) * 1.3){ if(Math.abs(dy) > 26) tracking = false; return; }
        dragDir = dx < 0 ? 1 : -1;
        var idx = hi + dragDir;
        if(idx < 0 || idx >= H.length) dragOn = 'edge';          // nothing that way
        else { flipBegin(dragDir, idx, 0); dragOn = true; }
        try{ st.setPointerCapture(pid); }catch(_){}
      }
      if(dragOn === true) flipMove(dx);
    });
    function endPtr(e){
      if(!tracking || e.pointerId !== pid) return;
      tracking = false;
      if(!dragOn) return;
      var mode = dragOn; dragOn = false;
      suppress = true; setTimeout(function(){ suppress = false; }, 80);   // a drag must not count as a tap
      var dx = e.clientX - sx;
      if(mode === 'edge'){ if(e.type === 'pointerup' && Math.abs(dx) > 80) close(); return; }
      var W = flip ? flip.W : st.clientWidth, v = Math.abs(dx) / Math.max(1, Date.now() - t0);
      var sameWay = (dx < 0 ? 1 : -1) === dragDir;
      var commit = e.type === 'pointerup' && sameWay && (Math.abs(dx) > W * 0.28 || (v > 0.5 && Math.abs(dx) > 40));
      flipEnd(commit);
    }
    st.addEventListener('pointerup', endPtr);
    st.addEventListener('pointercancel', endPtr);
    st.addEventListener('click', function(e){ if(suppress){ e.stopPropagation(); e.preventDefault(); suppress = false; } }, true);

    st.addEventListener('wheel', function(e){      // trackpad / horizontal mouse wheel
      if(e.target.closest && e.target.closest('#hlMediaPanel')) return;
      if(Math.abs(e.deltaX) < 30 || Math.abs(e.deltaX) < Math.abs(e.deltaY)) return;
      var now = Date.now(); if(now - lastWheel < 800) return; lastWheel = now;
      gotoHighlight(e.deltaX > 0 ? 1 : -1);
    }, {passive:true});
  })();

  /* ══════════════════ keyboard ══════════════════ */
  document.addEventListener('keydown',function(e){
    if(!$('hlModal').classList.contains('open')) return;
    if(e.key==='Escape'){ $('hlSheet').classList.contains('show')?hlCloseSheet():(mediaPanelOpen()?hlMediaClose():close()); }
    if(e.key==='ArrowRight') hlNext();
    if(e.key==='ArrowLeft')  hlPrev();
  });

  /* ══════════════════ deep link ══════════════════ */
  document.addEventListener('DOMContentLoaded',function(){
    var id=new URLSearchParams(location.search).get('hl');
    if(id) setTimeout(function(){ window.openHighlightsModal(id); },600);
  });
})();
</script>
</template>

<?php /* (old collections engine removed — profile.php already has the current one; keeping both made the older copy override the newer, which broke "Add to Collection") */ ?>

<!-- ===================== ABOUT ME VIEW MODAL ===================== -->
<div id="aboutMeModal" class="am-modal" role="dialog" aria-modal="true" aria-label="About me">
 <div class="am-lane">

    <!-- TOPBAR: X left · title centered · Edit right (owner only) -->
    <div class="am-topbar">
        <div class="am-tb-side">
            <button type="button" class="am-x" onclick="dzCloseAboutMe()" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="am-topbar-center">
            <div class="am-topbar-title">About Me</div>
        </div>
        <div class="am-tb-side right">
            <button type="button" id="aboutMeEditBtn" class="am-hbtn" onclick="dzOpenEditAboutMe()" style="display:none;">Edit</button>
        </div>
    </div>

    <!-- BODY (skeleton is injected here by JS, then replaced by the real content) -->
    <div class="am-body" id="aboutMeBody"></div>

 </div>
</div>

<!-- ===================== EDIT ABOUT ME MODAL ===================== -->
<div id="editAboutMeModal" class="am-modal" role="dialog" aria-modal="true" aria-label="Edit about me">
 <div class="am-lane">

    <div class="am-topbar">
        <div class="am-tb-side">
            <button type="button" class="am-x" onclick="dzCloseEditAboutMe()" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="am-topbar-center">
            <div class="am-topbar-title">Edit About Me</div>
        </div>
        <div class="am-tb-side right">
            <button type="button" class="am-hbtn am-save" onclick="dzSaveAboutMe()">Save</button>
        </div>
    </div>

    <!-- Segmented tabs -->
    <div class="am-tabs-wrap">
        <div class="am-tabs">
            <button type="button" class="am-etab active" data-tab="core"    onclick="dzSwitchEditTab('core')">Profile</button>
            <button type="button" class="am-etab"        data-tab="contact" onclick="dzSwitchEditTab('contact')">Contact</button>
            <button type="button" class="am-etab"        data-tab="views"   onclick="dzSwitchEditTab('views')">Views</button>
            <button type="button" class="am-etab"        data-tab="quotes"  onclick="dzSwitchEditTab('quotes')">Quotes</button>
            <button type="button" class="am-etab"        data-tab="family"  onclick="dzSwitchEditTab('family')">Family</button>
            <button type="button" class="am-etab"        data-tab="events"  onclick="dzSwitchEditTab('events')">Events</button>
        </div>
    </div>

    <form id="editAboutMeForm" class="am-body" onsubmit="return false;">

        <!-- ── TAB: Profile ── -->
        <div class="am-panel active" id="etab-core">
            <div class="am-g">
                <label>Full Name</label>
                <input type="text" name="full_name" maxlength="100" placeholder="Your full name">
            </div>
            <div class="am-g">
                <label>Nickname</label>
                <input type="text" name="nickname" maxlength="50" placeholder="What people call you">
            </div>
            <div class="am-r2">
                <div class="am-g">
                    <label>Occupation</label>
                    <input type="text" name="occupation" maxlength="100" placeholder="What do you do?">
                </div>
                <div class="am-g">
                    <label>Education</label>
                    <input type="text" name="education" maxlength="150" placeholder="School / field of study">
                </div>
            </div>
            <div class="am-r2">
                <div class="am-g">
                    <label>Current Location</label>
                    <div class="dzloc">
                        <input type="hidden" name="current_location">
                        <div class="dzloc-tag" style="display:none"><i class="ri-map-pin-2-line"></i><span></span><button type="button" class="dzloc-x" aria-label="Clear">&times;</button></div>
                        <div class="dzloc-box"><input type="text" class="dzloc-q" placeholder="Search where you're based…" autocomplete="off" spellcheck="false"><span class="dzloc-spin"></span></div>
                        <div class="dzloc-dd"></div>
                    </div>
                    <div class="am-vis"><div class="am-vis-t"><b>Show on my profile</b><span>Your current location appears under your links</span></div><button type="button" class="am-sw<?php echo !empty($dz_loc_hide_own) ? '' : ' on'; ?>" data-lv="1" role="switch" aria-checked="<?php echo !empty($dz_loc_hide_own) ? 'false' : 'true'; ?>" aria-label="Show current location on my profile" onclick="dzLocVisSave(this)"></button></div>
                </div>
                <div class="am-g">
                    <label>Hometown</label>
                    <div class="dzloc">
                        <input type="hidden" name="hometown">
                        <div class="dzloc-tag" style="display:none"><i class="ri-map-pin-2-line"></i><span></span><button type="button" class="dzloc-x" aria-label="Clear">&times;</button></div>
                        <div class="dzloc-box"><input type="text" class="dzloc-q" placeholder="Search where you're from…" autocomplete="off" spellcheck="false"><span class="dzloc-spin"></span></div>
                        <div class="dzloc-dd"></div>
                    </div>
                </div>
            </div>
            <div class="am-r2">
                <div class="am-g">
                    <label>Relationship Status</label>
                    <select name="relationship_status">
                        <option value="">Prefer not to say</option>
                        <option value="Single">Single</option>
                        <option value="In a relationship">In a relationship</option>
                        <option value="Engaged">Engaged</option>
                        <option value="Married">Married</option>
                        <option value="It's complicated">It's complicated</option>
                    </select>
                </div>
                <div class="am-g">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth">
                </div>
            </div>
            <div class="am-g">
                <label>Website</label>
                <input type="text" name="website" maxlength="255" placeholder="yourwebsite.com">
            </div>
            <div class="am-g">
                <label>Interests</label>
                <input type="text" name="interests" maxlength="255" placeholder="music, art, gaming, travel">
            </div>
            <div class="am-g">
                <label>Description</label>
                <textarea name="description" maxlength="1000" rows="5" placeholder="Tell your followers more about yourself..."></textarea>
            </div>
        </div>

        <!-- ── TAB: Contact ── -->
        <div class="am-panel" id="etab-contact">
            <div class="am-note">
                <i class="ri-shield-check-line"></i>
                <span>Only visible to your followers. Share only what you're comfortable with.</span>
            </div>
            <div class="am-g">
                <label>Email Address</label>
                <input type="email" name="contact_email" maxlength="150" placeholder="your@email.com">
            </div>
            <div class="am-g">
                <label>Phone Number</label>
                <input type="tel" name="contact_phone" maxlength="30" placeholder="+234 800 000 0000">
            </div>
        </div>

        <!-- ── TAB: Views ── -->
        <div class="am-panel" id="etab-views">
            <div class="am-note">
                <i class="ri-information-line"></i>
                <span>These are entirely optional. Share only if you want to.</span>
            </div>
            <div class="am-g">
                <label>Political Views</label>
                <input type="text" name="political_views" maxlength="100" placeholder="e.g. Progressive, Conservative, Moderate…">
            </div>
            <div class="am-g">
                <label>Religious Views</label>
                <input type="text" name="religious_views" maxlength="100" placeholder="e.g. Christian, Muslim, Agnostic…">
            </div>
        </div>

        <!-- ── TAB: Quotes ── -->
        <div class="am-panel" id="etab-quotes">
            <div class="am-g">
                <label>Favorite Quotes</label>
                <textarea name="favorite_quotes" maxlength="2000" rows="8"
                    placeholder='"Be the change you wish to see." — Mahatma Gandhi&#10;&#10;Add one quote per line or just your personal motto...'></textarea>
                <div class="am-hint right">Max 2,000 characters</div>
            </div>
        </div>

        <!-- ── TAB: Family ── -->
        <div class="am-panel" id="etab-family">
            <div class="am-note">
                <i class="ri-user-heart-line"></i>
                <span>Family members you add are visible to your followers. Only add people who have given consent.</span>
            </div>

            <div class="am-sec" id="familyAddCard">
                <div class="am-sec-h"><i class="ri-user-add-line"></i> Add family member</div>
                <div class="am-r2">
                    <div class="am-g">
                        <label>Name</label>
                        <input type="text" id="famName" maxlength="100" placeholder="Their name">
                    </div>
                    <div class="am-g">
                        <label>Relationship</label>
                        <select id="famRelationship">
                            <option value="">Select…</option>
                            <option>Mother</option><option>Father</option><option>Sister</option>
                            <option>Brother</option><option>Spouse / Partner</option><option>Daughter</option>
                            <option>Son</option><option>Grandmother</option><option>Grandfather</option>
                            <option>Aunt</option><option>Uncle</option><option>Cousin</option>
                            <option>Best Friend</option><option>Other</option>
                        </select>
                    </div>
                </div>
                <div class="am-g">
                    <label>Their Photo <span class="am-opt">Optional</span></label>
                    <div class="am-pic-upload" id="famPicUpload" onclick="document.getElementById('famPicInput').click()">
                        <img id="famPicPreview" src="" alt="" style="display:none;">
                        <div id="famPicPlaceholder"><i class="ri-image-add-line"></i><span>Add photo</span></div>
                    </div>
                    <input type="file" id="famPicInput" accept="image/*" style="display:none;" onchange="dzPreviewFamilyPic(this)">
                </div>
                <label class="am-consent">
                    <input type="checkbox" id="famConsent">
                    <span>I confirm this person has given consent to be listed on my profile.</span>
                </label>
                <button type="button" class="am-btn am-add" onclick="dzAddFamilyMember()">
                    <i class="ri-user-add-line"></i> Add Member
                </button>
            </div>

            <div class="am-sec">
                <div class="am-sec-h"><i class="ri-group-line"></i> Your family list</div>
                <div id="familyList" class="am-list"></div>
            </div>
        </div>

        <!-- ── TAB: Events ── -->
        <div class="am-panel" id="etab-events">
            <div class="am-note">
                <i class="ri-calendar-event-line"></i>
                <span>Highlight key moments in your life. These appear in your About Me in reverse chronological order.</span>
            </div>

            <div class="am-sec">
                <div class="am-sec-h"><i class="ri-add-circle-line"></i> Add life event</div>
                <div class="am-r2">
                    <div class="am-g">
                        <label>Event Type</label>
                        <select id="evType">
                            <option value="">Select…</option>
                            <option>Graduation</option><option>Marriage</option><option>New Job</option>
                            <option>Promotion</option><option>Moved Cities</option><option>New Home</option>
                            <option>New Baby</option><option>Lost a Loved One</option><option>Retirement</option>
                            <option>Business Launch</option><option>Award / Achievement</option>
                            <option>Milestone Birthday</option><option>Travel</option><option>Other</option>
                        </select>
                    </div>
                    <div class="am-g">
                        <label>Date <span class="am-opt">Optional</span></label>
                        <input type="date" id="evDate">
                    </div>
                </div>
                <div class="am-g">
                    <label>Title</label>
                    <input type="text" id="evTitle" maxlength="150" placeholder="e.g. Graduated from UNILAG">
                </div>
                <div class="am-g">
                    <label>Details <span class="am-opt">Optional</span></label>
                    <textarea id="evDesc" maxlength="500" rows="3" placeholder="A little more about this moment…"></textarea>
                </div>
                <button type="button" class="am-btn am-add" onclick="dzAddLifeEvent()">
                    <i class="ri-add-circle-line"></i> Add Event
                </button>
            </div>

            <div class="am-sec">
                <div class="am-sec-h"><i class="ri-history-line"></i> Your life events</div>
                <div id="eventList" class="am-list"></div>
            </div>
        </div>

    </form>

<script>
/* Place picker for Current Location / Hometown: Nominatim search in the browser, 3+ characters, 0.45s debounce, stale answers ignored */
(function(){
    var seq = 0;
    function cut(s){ s = String(s || ''); if(s.length <= 100) return s; var c = s.lastIndexOf(',', 100); return (c > 20 ? s.slice(0, c) : s.slice(0, 100)); }
    function init(wrap){
        if(wrap.__dzloc) return; wrap.__dzloc = 1;
        var hid = wrap.querySelector('input[type=hidden]'), q = wrap.querySelector('.dzloc-q'), dd = wrap.querySelector('.dzloc-dd');
        var tag = wrap.querySelector('.dzloc-tag'), tagT = tag.querySelector('span'), timer = null, mine = 0;
        function show(){ var v = hid.value; tag.style.display = v ? 'flex' : 'none'; tagT.textContent = v; }
        /* values written by the modal (field.value = ...) update the tag too */
        var d = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        Object.defineProperty(hid, 'value', { get:function(){ return d.get.call(hid); }, set:function(v){ d.set.call(hid, v); show(); }, configurable:true });
        var f = hid.form; if(f) f.addEventListener('reset', function(){ setTimeout(show, 0); });
        function close(){ wrap.classList.remove('open'); dd.innerHTML = ''; }
        function msg(t){ dd.innerHTML = '<div class="dzloc-msg">' + t + '</div>'; wrap.classList.add('open'); }
        function search(text){
            var id = ++seq; mine = id; wrap.classList.add('busy');
            fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=8&q=' + encodeURIComponent(text))
                .then(function(r){ if(!r.ok) throw 0; return r.json(); })
                .then(function(rows){
                    if(id !== mine) return;           // an older answer arriving late
                    wrap.classList.remove('busy');
                    if(!rows || !rows.length){ msg('No matching places found'); return; }
                    dd.innerHTML = ''; 
                    rows.forEach(function(r){
                        var o = document.createElement('div'); o.className = 'dzloc-opt'; o.textContent = r.display_name;
                        o.addEventListener('mousedown', function(e){ e.preventDefault(); });
                        o.addEventListener('click', function(){ hid.value = cut(r.display_name); q.value = ''; close(); mine = ++seq; });
                        dd.appendChild(o);
                    });
                    wrap.classList.add('open');
                })
                .catch(function(){ if(id !== mine) return; wrap.classList.remove('busy'); msg('Search failed — try again'); });
        }
        q.addEventListener('input', function(){
            clearTimeout(timer); var v = q.value.trim();
            if(v.length < 3){ mine = ++seq; wrap.classList.remove('busy'); close(); return; }
            timer = setTimeout(function(){ search(v); }, 450);
        });
        q.addEventListener('keydown', function(e){ if(e.key === 'Enter') e.preventDefault(); });
        q.addEventListener('blur', function(){ setTimeout(close, 150); });
        tag.querySelector('.dzloc-x').addEventListener('click', function(){ hid.value = ''; q.focus(); });
        show();
    }
    function boot(){ Array.prototype.forEach.call(document.querySelectorAll('#editAboutMeForm .dzloc'), init); }
    boot(); document.addEventListener('DOMContentLoaded', boot);
})();
</script>
 </div>
</div>

<!-- ══ TAB CHOICE SHEET (owner only) ══ -->
<div id="dzTabSheet" class="am-sheet-bg" onclick="if(event.target===this)dzCloseTabSheet()">
    <div class="am-sheet">
        <div class="am-sheet-handle"></div>
        <div id="dzTabSheetTitle" class="am-sheet-title">What would you like to do?</div>

        <div class="am-sheet-opt" onclick="dzTabGoToPage()">
            <div class="am-sheet-ic"><i class="ri-external-link-line"></i></div>
            <div class="am-sheet-tx">
                <div class="am-sheet-name">Go to Page</div>
                <div class="am-sheet-sub">Open the full dedicated page</div>
            </div>
            <i class="ri-arrow-right-s-line am-sheet-arrow"></i>
        </div>

        <div class="am-sheet-opt" onclick="dzTabBrowseHere()">
            <div class="am-sheet-ic"><i class="ri-grid-line"></i></div>
            <div class="am-sheet-tx">
                <div class="am-sheet-name">Browse Here</div>
                <div class="am-sheet-sub">View the grid on this profile page</div>
            </div>
            <i class="ri-arrow-right-s-line am-sheet-arrow"></i>
        </div>

        <div class="am-sheet-cancel" onclick="dzCloseTabSheet()">Cancel</div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     ABOUT ME — STYLES (same design language as the Post modal)
     • full-screen overlay, content in a CENTERED LANE
     • two grey lines separate the lane from the empty space
     • no bubble cards: sections are split by thin lines
     • lightly rounded 5px rectangular buttons, fluid sizing
     • RED + BLACK theme (text on solid red is black)
═══════════════════════════════════════════════════════════ -->
<style>
.am-modal {
    --am-red:#e0351a; --am-red-h:#c52c14; --am-red-l:#ff7a66;
    --am-red-dim:rgba(224,53,26,.14); --am-red-line:rgba(224,53,26,.35);
    --am-on-red:#000;
    --am-card:rgba(var(--fg,255,255,255),.05); --am-card2:rgba(var(--fg,255,255,255),.08);
    --am-line:rgba(var(--fg,255,255,255),.09); --am-line2:rgba(var(--fg,255,255,255),.14);
    --am-sub:rgba(var(--fg,255,255,255),.62); --am-mut:rgba(var(--fg,255,255,255),.45);
    --am-pad:calc(clamp(9px,3vw,16px) * var(--md-s));
    --am-act-h:calc(clamp(25px,7.2vw,30px) * var(--md-s));
    --am-r:5px;
    --am-fs:calc(.8rem * var(--md-s));
    --am-tb-h:calc(54px * var(--md-s));

    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999999;
    background: linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--am-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
}
@media (max-width:490px){ .am-modal{ --am-fs:calc(.74rem * var(--md-s)); --am-tb-h:calc(48px * var(--md-s)); } }
@media (max-width:390px){ .am-modal{ --am-fs:calc(.7rem * var(--md-s));  --am-tb-h:calc(44px * var(--md-s)); } }
.am-modal.active { display: flex; animation: amSlideIn 0.28s cubic-bezier(0.22,1,0.36,1); }
@keyframes amSlideIn  { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes amSlideOut { to { opacity: 0; transform: translateY(16px); } }

.am-modal *, .am-modal *::before, .am-modal *::after { box-sizing: border-box; }
.am-modal button, .am-modal input, .am-modal textarea, .am-modal select { font-family: 'Poppins', sans-serif; }
.am-modal button { cursor: pointer; -webkit-tap-highlight-color: transparent; }
.am-modal button, .am-modal a, .am-modal input, .am-modal textarea, .am-modal select { outline: none; }
.am-modal [class^="ri-"], .am-modal [class*=" ri-"],
.am-modal [class^="ri-"]::before, .am-modal [class*=" ri-"]::before,
.am-sheet-bg [class^="ri-"], .am-sheet-bg [class*=" ri-"],
.am-sheet-bg [class^="ri-"]::before, .am-sheet-bg [class*=" ri-"]::before {
    font-family: 'remixicon' !important; font-style: normal; font-weight: normal; line-height: 1;
}

/* ── the lane + two grey lines ── */
.am-lane { width: 100%; max-width: 580px; height: 100%; display: flex; flex-direction: column; min-height: 0; }
@media (min-width: 801px) { .am-lane { border-left: 1px solid var(--am-line); border-right: 1px solid var(--am-line); } }
@media (max-width: 800px) { .am-lane { max-width: 100%; } }

/* ── Top bar ── */
.am-topbar { flex-shrink: 0; display: flex; align-items: center; min-height: var(--am-tb-h); padding: env(safe-area-inset-top) calc(10px * var(--md-s)) 0; }
.am-tb-side { flex: 0 0 64px; display: flex; align-items: center; }
.am-tb-side.right { justify-content: flex-end; }
.am-x { width: calc(34px * var(--md-s)); height: calc(34px * var(--md-s)); background: none; border: none; color: var(--text,#F0F2FF); display: flex; align-items: center; justify-content: center; opacity: .9; padding: 0; transition: .2s; }
.am-x:hover { color: var(--am-red-l); opacity: 1; }
.am-x svg { width: calc(22px * var(--md-s)); height: calc(22px * var(--md-s)); display: block; }
.am-topbar-center { flex: 1; min-width: 0; text-align: center; }
.am-topbar-title { font-size: calc(.92rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
@media (max-width:490px){ .am-topbar-title{ font-size:calc(.84rem * var(--md-s)); } .am-x{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); } .am-tb-side{ flex-basis:58px; } }
@media (max-width:390px){ .am-topbar-title{ font-size:calc(.8rem * var(--md-s)); } }

.am-hbtn {
    display: inline-flex; align-items: center; justify-content: center; gap: calc(5px * var(--md-s));
    height: var(--am-act-h); padding: 0 calc(13px * var(--md-s)); border: none; border-radius: var(--am-r);
    background: var(--am-red); color: var(--am-on-red);
    font-size: calc(.74rem * var(--md-s)); font-weight: 700; line-height: 1; white-space: nowrap; transition: background .2s, transform .15s;
}
.am-hbtn i { font-size: calc(13px * var(--md-s)); }
.am-hbtn:hover { background: var(--am-red-h); }
.am-hbtn:active { transform: scale(.97); }
.am-hbtn:disabled { opacity: .5; cursor: not-allowed; }
@media (max-width:490px){ .am-hbtn{ font-size:calc(.68rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); } }

/* ── Scroll area ── */
.am-body {
    flex: 1; min-height: 0; margin: 0; width: 100%;
    overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch;
    padding: calc(4px * var(--md-s)) var(--am-pad) calc(28px * var(--md-s));
    scrollbar-width: none;            /* hidden by default (all responsive sizes) */
    -ms-overflow-style: none;
}
.am-body::-webkit-scrollbar { display: none; width: 0; height: 0; }
/* scrollbar only on normal (desktop) view */
@media (min-width: 801px) {
    .am-body { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.28) transparent; -ms-overflow-style: auto; }
    .am-body::-webkit-scrollbar { display: block; width: calc(4px * var(--md-s)); }
    .am-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,.28); border-radius: calc(99px * var(--md-s)); }
    .am-body::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.45); }
}
.am-modal textarea { scrollbar-width: none; }
.am-modal textarea::-webkit-scrollbar { display: none; }

/* ── Sections (separated by lines, no bubbles) ── */
.am-sec { margin-top: calc(16px * var(--md-s)); padding-top: calc(14px * var(--md-s)); border-top: 1px solid var(--am-line); }
.am-body > .am-sec:first-child, .am-panel > .am-sec:first-child { margin-top: calc(8px * var(--md-s)); padding-top: 0; border-top: none; }
.am-sec-h {
    display: flex; align-items: center; gap: calc(6px * var(--md-s)); margin-bottom: calc(9px * var(--md-s));
    font-size: calc(.64rem * var(--md-s)); font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--am-sub);
}
.am-sec-h i { font-size: calc(14px * var(--md-s)); color: var(--am-red-l); }

/* ── Rows ── */
.am-row { display: flex; align-items: flex-start; gap: calc(11px * var(--md-s)); padding: calc(9px * var(--md-s)) 0; }
.am-row + .am-row { border-top: 1px solid var(--am-line); }
.am-row-ic { width: calc(26px * var(--md-s)); height: calc(26px * var(--md-s)); border-radius: var(--am-r); background: var(--am-red-dim); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.am-row-ic i { font-size: calc(14px * var(--md-s)); color: var(--am-red-l); }
.am-row-c { flex: 1; min-width: 0; }
.am-label { font-size: calc(.62rem * var(--md-s)); color: var(--am-mut); margin-bottom: 2px; }
.am-value { font-size: calc(.82rem * var(--md-s)); color: var(--text,#F0F2FF); word-break: break-word; line-height: 1.45; }
.am-value.empty { color: var(--am-mut); font-style: italic; opacity: .75; }
.am-value a { color: var(--am-red-l); text-decoration: none; word-break: break-all; }
.am-value a:hover { text-decoration: underline; }
.am-desc { font-size: calc(.8rem * var(--md-s)); line-height: 1.65; color: rgba(var(--fg,255,255,255),.82); word-break: break-word; }
.am-tags { display: flex; flex-wrap: wrap; gap: calc(6px * var(--md-s)); margin-top: calc(4px * var(--md-s)); }
.am-tag {
    display: inline-flex; align-items: center; height: calc(var(--am-act-h) - calc(6px * var(--md-s))); padding: 0 calc(10px * var(--md-s));
    border-radius: var(--am-r); background: var(--am-red-dim); border: 1px solid var(--am-red-line);
    color: var(--am-red-l); font-size: calc(.68rem * var(--md-s)); font-weight: 600;
}

/* Quotes */
.am-quotes { display: flex; flex-direction: column; gap: calc(8px * var(--md-s)); }
.am-quote { border-left: 3px solid var(--am-red); padding: calc(7px * var(--md-s)) calc(11px * var(--md-s)); background: var(--am-card); border-radius: 0 var(--am-r) var(--am-r) 0; font-size: calc(.78rem * var(--md-s)); color: rgba(var(--fg,255,255,255),.85); line-height: 1.55; white-space: pre-wrap; word-break: break-word; }

/* Family */
.am-fam-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(112px, 1fr)); gap: calc(8px * var(--md-s)); }
.am-fam { background: var(--am-card); border: 1px solid var(--am-line); border-radius: calc(8px * var(--md-s)); padding: calc(12px * var(--md-s)) calc(8px * var(--md-s)); text-align: center; min-width: 0; }
.am-fam img, .am-fam .am-avatar { width: calc(46px * var(--md-s)); height: calc(46px * var(--md-s)); border-radius: 50%; object-fit: cover; margin: 0 auto calc(7px * var(--md-s)); display: block; border: 2px solid var(--am-red); }
.am-avatar { background: var(--am-red-dim); display: flex !important; align-items: center; justify-content: center; }
.am-avatar i { font-size: calc(20px * var(--md-s)); color: var(--am-red-l); }
.am-fam-name { font-size: calc(.74rem * var(--md-s)); font-weight: 600; color: var(--text,#F0F2FF); word-break: break-word; }
.am-fam-rel { font-size: calc(.62rem * var(--md-s)); color: var(--am-mut); margin-top: 2px; }

/* Life events */
.am-ev { display: flex; gap: calc(11px * var(--md-s)); padding: calc(10px * var(--md-s)) 0; }
.am-ev + .am-ev { border-top: 1px solid var(--am-line); }
.am-ev-ic { width: calc(30px * var(--md-s)); height: calc(30px * var(--md-s)); border-radius: var(--am-r); background: var(--am-red-dim); display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
.am-ev-ic i { font-size: calc(15px * var(--md-s)); color: var(--am-red-l); }
.am-ev-b { flex: 1; min-width: 0; }
.am-ev-type { font-size: calc(.6rem * var(--md-s)); font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--am-red-l); margin-bottom: 2px; }
.am-ev-title { font-size: calc(.82rem * var(--md-s)); font-weight: 600; color: var(--text,#F0F2FF); word-break: break-word; }
.am-ev-date { font-size: calc(.64rem * var(--md-s)); color: var(--am-mut); margin-top: 2px; }
.am-ev-desc { font-size: calc(.72rem * var(--md-s)); color: var(--am-sub); margin-top: calc(4px * var(--md-s)); line-height: 1.55; word-break: break-word; }

/* Empty / error state */
.am-empty { text-align: center; padding: calc(56px * var(--md-s)) calc(18px * var(--md-s)); color: var(--am-mut); font-size: calc(.78rem * var(--md-s)); }
.am-empty i { font-size: calc(32px * var(--md-s)); display: block; margin-bottom: calc(10px * var(--md-s)); color: rgba(255,122,102,.45); }
.am-empty-sm { text-align: center; padding: calc(16px * var(--md-s)) 0; color: var(--am-mut); font-size: calc(.72rem * var(--md-s)); }

/* ── Edit: tabs ── */
.am-tabs-wrap { flex-shrink: 0; padding: 0 var(--am-pad) calc(8px * var(--md-s)); }
.am-tabs { display: flex; gap: 2px; padding: calc(3px * var(--md-s)); border-radius: var(--am-r); background: var(--am-card); border: 1px solid var(--am-line); overflow: hidden; }
.am-etab {
    flex: 1 1 0; min-width: 0; height: calc(var(--am-act-h) - 2px); padding: 0 2px; border: none; border-radius: calc(4px * var(--md-s));
    background: none; color: var(--am-sub); font-size: calc(clamp(.56rem, 2.7vw, .76rem) * var(--md-s)); font-weight: 600; white-space: nowrap;
    overflow: hidden; text-overflow: clip; transition: .2s;
}
.am-etab:hover { color: var(--text,#F0F2FF); }
.am-etab.active { background: var(--am-red); color: var(--am-on-red); font-weight: 700; }

.am-panel { display: none; }
.am-panel.active { display: block; animation: amFade .2s ease; }
@keyframes amFade { from { opacity: 0; transform: translateY(-3px); } to { opacity: 1; transform: none; } }

/* ── Edit: fields ── */
.am-g { margin-bottom: calc(14px * var(--md-s)); flex: 1; min-width: 0; }
.am-r2 { display: flex; gap: calc(10px * var(--md-s)); }
.am-g label { display: block; font-size: calc(.62rem * var(--md-s)); font-weight: 600; letter-spacing: .4px; text-transform: uppercase; color: var(--am-sub); margin-bottom: calc(6px * var(--md-s)); }
.am-opt { margin-left: calc(4px * var(--md-s)); font-size: calc(.56rem * var(--md-s)); font-weight: 600; letter-spacing: .4px; color: var(--am-mut); background: rgba(var(--fg,255,255,255),.07); padding: 2px calc(7px * var(--md-s)); border-radius: calc(4px * var(--md-s)); text-transform: uppercase; }
.am-g input, .am-g select, .am-g textarea {
    width: 100%; height: var(--am-act-h); padding: 0 calc(11px * var(--md-s)); border-radius: var(--am-r);
    border: 1px solid var(--am-line2); background: var(--am-card); color: var(--text,#F0F2FF);
    font-size: calc(.76rem * var(--md-s)); transition: .2s; -webkit-appearance: none; appearance: none; color-scheme: dark;
}
.am-g select {
    padding-right: calc(28px * var(--md-s));
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%23ffffff99' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
}
.am-g select option { background: var(--surface-2,#0d1030); color: var(--text,#F0F2FF); }
.am-g textarea { height: auto; min-height: calc(84px * var(--md-s)); padding: calc(9px * var(--md-s)) calc(11px * var(--md-s)); line-height: 1.6; resize: vertical; font-size: calc(.8rem * var(--md-s)); }
.am-g input:focus, .am-g select:focus, .am-g textarea:focus { border-color: var(--am-red); }
.am-g input::placeholder, .am-g textarea::placeholder { color: var(--am-mut); }

/* ── place picker (OpenStreetMap / Nominatim) ── */
.dzloc{ position:relative; }
.am-vis{ display:flex; align-items:center; justify-content:space-between; gap:calc(10px * var(--md-s)); margin:2px 0 calc(4px * var(--md-s)); padding:calc(clamp(6px,1.8vw,8px) * var(--md-s)) calc(clamp(8px,2.4vw,10px) * var(--md-s)); border-radius:calc(5px * var(--md-s)); background:rgba(var(--fg,255,255,255),.04); border:1px solid rgba(var(--fg,255,255,255),.08); }
.am-vis-t{ min-width:0; }
.am-vis-t b{ display:block; font-size:calc(clamp(10.5px,2.8vw,11.5px) * var(--md-s)); font-weight:600; color:var(--text,#F0F2FF); line-height:1.3; }
.am-vis-t span{ display:block; margin-top:1px; font-size:calc(clamp(9.5px,2.5vw,10.5px) * var(--md-s)); color:rgba(var(--fg,255,255,255),.5); line-height:1.3; }
.am-sw{ --sw-w:calc(clamp(30px,8vw,34px) * var(--md-s)); --sw-h:calc(clamp(17px,4.6vw,19px) * var(--md-s)); position:relative; flex-shrink:0; width:var(--sw-w); height:var(--sw-h); border-radius:calc(10px * var(--md-s)); border:none; padding:0; background:rgba(var(--fg,255,255,255),.18); cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent; }
.am-sw::after{ content:''; position:absolute; top:2px; left:2px; width:calc(var(--sw-h) - calc(4px * var(--md-s))); height:calc(var(--sw-h) - calc(4px * var(--md-s))); border-radius:50%; background:#fff; transition:transform .2s; }
.am-sw.on{ background:#FF2D2D; }
.am-sw.on::after{ transform:translateX(calc(var(--sw-w) - var(--sw-h))); }
.am-sw:disabled{ opacity:.6; }
.dzloc-box{ position:relative; }
.dzloc-box .dzloc-q{ padding-right:calc(34px * var(--md-s)); }
.dzloc-spin{ position:absolute; right:calc(11px * var(--md-s)); top:50%; width:calc(13px * var(--md-s)); height:calc(13px * var(--md-s)); margin-top:calc(-7px * var(--md-s)); border:2px solid rgba(var(--fg,255,255,255),.25); border-top-color:#FF2D2D; border-radius:50%; display:none; animation:dzlocSpin .7s linear infinite; }
.dzloc.busy .dzloc-spin{ display:block; }
@keyframes dzlocSpin{ to{ transform:rotate(360deg); } }
.dzloc-tag{ display:flex; align-items:center; gap:calc(7px * var(--md-s)); margin-bottom:calc(6px * var(--md-s)); padding:calc(7px * var(--md-s)) calc(9px * var(--md-s)); border-radius:var(--am-r); background:rgba(255,45,45,.1); border:1px solid rgba(255,45,45,.3); color:var(--text,#F0F2FF); font-size:calc(.72rem * var(--md-s)); line-height:1.35; }
.dzloc-tag i{ color:#FF2D2D; flex-shrink:0; }
.dzloc-tag span{ flex:1; min-width:0; word-break:break-word; }
.dzloc-x{ border:none; background:none; color:rgba(var(--fg,255,255,255),.7); font-size:calc(18px * var(--md-s)); line-height:1; cursor:pointer; padding:0 2px; }
.dzloc-dd{ display:none; position:absolute; left:0; right:0; top:100%; margin-top:calc(4px * var(--md-s)); z-index:30; max-height:220px; overflow-y:auto; border-radius:var(--am-r); background:var(--surface-2,#0d1030); border:1px solid var(--am-line2); box-shadow:0 10px 28px rgba(0,0,0,.5); }
.dzloc.open .dzloc-dd{ display:block; }
.dzloc-opt, .dzloc-msg{ padding:calc(9px * var(--md-s)) calc(11px * var(--md-s)); font-size:calc(.72rem * var(--md-s)); line-height:1.4; color:var(--text,#F0F2FF); border-top:1px solid rgba(var(--fg,255,255,255),.07); }
.dzloc-opt:first-child, .dzloc-msg:first-child{ border-top:none; }
.dzloc-opt{ cursor:pointer; -webkit-tap-highlight-color:transparent; }
.dzloc-opt:hover{ background:rgba(var(--fg,255,255,255),.08); }
.dzloc-msg{ color:rgba(var(--fg,255,255,255),.5); }
.am-hint { font-size: calc(.64rem * var(--md-s)); color: var(--am-mut); margin-top: calc(5px * var(--md-s)); }
.am-hint.right { text-align: right; }
@media (max-width:490px){ .am-g input, .am-g select{ font-size:calc(.7rem * var(--md-s)); } .am-g textarea{ font-size:calc(.74rem * var(--md-s)); } }
@media (max-width:430px){ .am-r2{ flex-direction: column; gap: 0; } }

.am-note {
    display: flex; align-items: flex-start; gap: calc(8px * var(--md-s)); margin: calc(8px * var(--md-s)) 0 calc(16px * var(--md-s)); padding: calc(9px * var(--md-s)) calc(11px * var(--md-s));
    border-radius: var(--am-r); background: var(--am-red-dim); border: 1px solid var(--am-red-line);
    font-size: calc(.7rem * var(--md-s)); color: var(--am-sub); line-height: 1.55;
}
.am-note i { font-size: calc(15px * var(--md-s)); color: var(--am-red-l); flex-shrink: 0; margin-top: 1px; }

/* Family photo picker */
.am-pic-upload { width: calc(70px * var(--md-s)); height: calc(70px * var(--md-s)); border-radius: 50%; border: 1.5px dashed var(--am-line2); cursor: pointer; display: flex; align-items: center; justify-content: center; overflow: hidden; background: var(--am-card); transition: .2s; }
.am-pic-upload:hover { border-color: var(--am-red); background: var(--am-red-dim); }
.am-pic-upload img { width: 100%; height: 100%; object-fit: cover; }
#famPicPlaceholder { display: flex; flex-direction: column; align-items: center; gap: calc(3px * var(--md-s)); color: var(--am-red-l); font-size: calc(.58rem * var(--md-s)); text-align: center; }
#famPicPlaceholder i { font-size: calc(20px * var(--md-s)); }

.am-consent { display: flex; align-items: flex-start; gap: calc(9px * var(--md-s)); cursor: pointer; margin-bottom: calc(12px * var(--md-s)); font-size: calc(.7rem * var(--md-s)); color: var(--am-sub); line-height: 1.5; }
.am-consent input[type=checkbox] { accent-color: var(--am-red); width: calc(15px * var(--md-s)); height: calc(15px * var(--md-s)); flex-shrink: 0; margin-top: 1px; }

/* Buttons */
.am-btn {
    display: flex; align-items: center; justify-content: center; gap: calc(6px * var(--md-s));
    width: 100%; height: var(--am-act-h); padding: 0 calc(14px * var(--md-s)); border: none; border-radius: var(--am-r);
    background: var(--am-red); color: var(--am-on-red);
    font-size: calc(.79rem * var(--md-s)); font-weight: 700; line-height: 1; white-space: nowrap; transition: background .2s, transform .15s;
}
.am-btn i { font-size: calc(14px * var(--md-s)); }
.am-btn:hover { background: var(--am-red-h); }
.am-btn:active { transform: scale(.98); }
.am-btn:disabled { opacity: .5; cursor: not-allowed; }
@media (max-width:490px){ .am-btn{ font-size:calc(.68rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); gap:calc(4px * var(--md-s)); } .am-btn i{ font-size:calc(12px * var(--md-s)); } }

/* Edit lists */
.am-list { display: flex; flex-direction: column; }
.am-li { display: flex; align-items: center; gap: calc(10px * var(--md-s)); padding: calc(9px * var(--md-s)) 0; }
.am-li + .am-li { border-top: 1px solid var(--am-line); }
.am-li img { width: calc(36px * var(--md-s)); height: calc(36px * var(--md-s)); border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 2px solid var(--am-red); }
.am-li-av { width: calc(36px * var(--md-s)); height: calc(36px * var(--md-s)); border-radius: var(--am-r); background: var(--am-red-dim); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.am-li-av.round { border-radius: 50%; }
.am-li-av i { font-size: calc(16px * var(--md-s)); color: var(--am-red-l); }
.am-li-b { flex: 1; min-width: 0; }
.am-li-name { font-size: calc(.76rem * var(--md-s)); font-weight: 600; color: var(--text,#F0F2FF); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.am-li-sub { font-size: calc(.62rem * var(--md-s)); color: var(--am-mut); margin-top: 2px; }
.am-li-del { width: calc(28px * var(--md-s)); height: var(--am-act-h); border: none; border-radius: var(--am-r); background: rgba(var(--fg,255,255,255),.08); color: var(--am-sub); font-size: calc(14px * var(--md-s)); display: flex; align-items: center; justify-content: center; flex-shrink: 0; padding: 0; transition: .2s; }
.am-li-del:hover { background: var(--am-red); color: var(--am-on-red); }

/* ── Skeleton loader ── */
.am-sk { display: flex; flex-direction: column; }
.am-sk-sec { margin-top: calc(16px * var(--md-s)); padding-top: calc(14px * var(--md-s)); border-top: 1px solid var(--am-line); }
.am-sk-sec:first-child { margin-top: calc(8px * var(--md-s)); padding-top: 0; border-top: none; }
.am-sk-sec:nth-child(1){ --d:0s; } .am-sk-sec:nth-child(2){ --d:.07s; } .am-sk-sec:nth-child(3){ --d:.14s; }
.am-sk-sec:nth-child(4){ --d:.21s; } .am-sk-sec:nth-child(5){ --d:.28s; } .am-sk-sec:nth-child(6){ --d:.35s; }
.am-sk-sec:nth-child(7){ --d:.42s; } .am-sk-sec:nth-child(8){ --d:.49s; }
.am-sk-sec * { animation-delay: var(--d); }
.am-sk-t, .am-sk-ic, .am-sk-l, .am-sk-chip, .am-sk-av, .am-sk-q {
    background: linear-gradient(90deg, rgba(var(--fg,255,255,255),.05) 25%, rgba(255,255,255,.14) 37%, rgba(255,255,255,.05) 63%);
    background-size: 400% 100%; animation: amShimmer 1.4s ease-in-out infinite;
}
@keyframes amShimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
.am-sk-t { height: calc(10px * var(--md-s)); width: calc(96px * var(--md-s)); border-radius: calc(4px * var(--md-s)); margin-bottom: calc(12px * var(--md-s)); }
.am-sk-row { display: flex; align-items: center; gap: calc(11px * var(--md-s)); padding: calc(8px * var(--md-s)) 0; }
.am-sk-row + .am-sk-row { border-top: 1px solid var(--am-line); }
.am-sk-ic { width: calc(26px * var(--md-s)); height: calc(26px * var(--md-s)); border-radius: var(--am-r); flex-shrink: 0; }
.am-sk-ls { flex: 1; display: flex; flex-direction: column; gap: calc(6px * var(--md-s)); }
.am-sk-l { height: calc(10px * var(--md-s)); border-radius: calc(4px * var(--md-s)); }
.am-sk-l.s { width: calc(52px * var(--md-s)); height: calc(8px * var(--md-s)); }
.am-sk-l.m { width: 58%; }
.am-sk-l.f { width: 100%; }
.am-sk-chips { display: flex; flex-wrap: wrap; gap: calc(6px * var(--md-s)); margin-top: calc(8px * var(--md-s)); }
.am-sk-chip { height: calc(22px * var(--md-s)); width: calc(68px * var(--md-s)); border-radius: var(--am-r); }
.am-sk-q { height: calc(34px * var(--md-s)); border-radius: 0 var(--am-r) var(--am-r) 0; border-left: 3px solid rgba(var(--fg,255,255,255),.1); margin-bottom: calc(8px * var(--md-s)); }
.am-sk-fam { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: calc(8px * var(--md-s)); }
.am-sk-fc { border: 1px solid var(--am-line); border-radius: calc(8px * var(--md-s)); padding: calc(12px * var(--md-s)) calc(8px * var(--md-s)); display: flex; flex-direction: column; align-items: center; gap: calc(7px * var(--md-s)); }
.am-sk-av { width: calc(46px * var(--md-s)); height: calc(46px * var(--md-s)); border-radius: 50%; }

/* ── Tab choice sheet ── */
.am-sheet-bg {
    display: none; position: fixed; inset: 0; z-index: 9999999; background: rgba(1,2,24,.78);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    align-items: flex-end; justify-content: center; padding: 0 calc(12px * var(--md-s));
    font-family: 'Poppins', sans-serif; color: #fff;
}
.am-sheet {
    width: 100%; max-width: 480px; margin-bottom: calc(12px * var(--md-s)); overflow: hidden;
    background: var(--surface-2,#0d1030); border: 1px solid rgba(var(--fg,255,255,255),.14); border-radius: calc(12px * var(--md-s));
    animation: amSheetUp .26s cubic-bezier(0.22,1,0.36,1);
}
@keyframes amSheetUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: none; } }
.am-sheet-handle { width: calc(36px * var(--md-s)); height: calc(4px * var(--md-s)); border-radius: calc(4px * var(--md-s)); background: rgba(var(--fg,255,255,255),.18); margin: calc(12px * var(--md-s)) auto calc(4px * var(--md-s)); }
.am-sheet-title { font-size: calc(.86rem * var(--md-s)); font-weight: 700; text-align: center; padding: calc(8px * var(--md-s)) calc(20px * var(--md-s)) calc(14px * var(--md-s)); }
.am-sheet-opt { display: flex; align-items: center; gap: calc(12px * var(--md-s)); padding: calc(13px * var(--md-s)) calc(16px * var(--md-s)); cursor: pointer; border-top: 1px solid rgba(var(--fg,255,255,255),.09); transition: background .15s; -webkit-tap-highlight-color: transparent; }
.am-sheet-opt:hover { background: rgba(var(--fg,255,255,255),.05); }
.am-sheet-ic { width: calc(38px * var(--md-s)); height: calc(38px * var(--md-s)); border-radius: calc(8px * var(--md-s)); background: rgba(224,53,26,.14); border: 1px solid rgba(224,53,26,.35); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.am-sheet-ic i { font-size: calc(18px * var(--md-s)); color: #ff7a66; }
.am-sheet-tx { flex: 1; min-width: 0; }
.am-sheet-name { font-size: calc(.8rem * var(--md-s)); font-weight: 600; margin-bottom: 2px; }
.am-sheet-sub { font-size: calc(.64rem * var(--md-s)); color: rgba(var(--fg,255,255,255),.45); }
.am-sheet-arrow { font-size: calc(18px * var(--md-s)); color: rgba(var(--fg,255,255,255),.28); flex-shrink: 0; }
.am-sheet-cancel { text-align: center; padding: calc(13px * var(--md-s)) calc(16px * var(--md-s)) calc(16px * var(--md-s)); font-size: calc(.76rem * var(--md-s)); font-weight: 600; color: rgba(var(--fg,255,255,255),.5); cursor: pointer; border-top: 1px solid rgba(var(--fg,255,255,255),.09); transition: color .15s; }
.am-sheet-cancel:hover { color: var(--text,#F0F2FF); }

@media (max-width:490px){
    .am-row-ic { width: calc(24px * var(--md-s)); height: calc(24px * var(--md-s)); } .am-row-ic i { font-size: calc(13px * var(--md-s)); }
    .am-value { font-size: calc(.76rem * var(--md-s)); } .am-desc { font-size: calc(.74rem * var(--md-s)); } .am-quote { font-size: calc(.72rem * var(--md-s)); }
    .am-fam-grid { grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: calc(7px * var(--md-s)); }
    .am-fam img, .am-fam .am-avatar { width: calc(40px * var(--md-s)); height: calc(40px * var(--md-s)); }
    .am-ev-title { font-size: calc(.76rem * var(--md-s)); } .am-ev-desc { font-size: calc(.68rem * var(--md-s)); }
    .am-empty { padding: calc(44px * var(--md-s)) calc(14px * var(--md-s)); }
}
@media (max-width:390px){
    .am-fam-grid { grid-template-columns: repeat(auto-fill, minmax(86px, 1fr)); }
    .am-sec-h { font-size: calc(.6rem * var(--md-s)); }
}
@media (prefers-reduced-motion:reduce){ .am-modal *, .am-sheet, .am-sheet-bg *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>


<!-- ===================== ABOUT ME JS ===================== -->
<script>/* lazy start: this block's code loads the first time it is used */
window.dzLazyJs=window.dzLazyJs||function(id,names,eager){var done=false;function run(){if(done)return;done=true;var t=document.getElementById(id);if(!t)return;var f=t.content.cloneNode(true),ss=[].slice.call(f.querySelectorAll('script'));ss.forEach(function(s){s.parentNode.removeChild(s)});ss.forEach(function(o){var s=document.createElement('script');s.text=o.textContent;document.body.appendChild(s)})}names.forEach(function(n){var s=function(){run();var r=window[n];if(r&&r!==s)return r.apply(this,arguments)};s.__dzStub=1;window[n]=s});window['dzLazyRun_'+id]=run;if(eager){var go=function(){run();['pointerdown','keydown','touchstart'].forEach(function(e){document.removeEventListener(e,go,true)})};['pointerdown','keydown','touchstart'].forEach(function(e){document.addEventListener(e,go,{capture:true,passive:true})});var idle=function(){(window.requestIdleCallback||function(c){setTimeout(c,1200)})(run,{timeout:3000})};if(document.readyState==='complete')idle();else window.addEventListener('load',idle)}};
dzLazyJs('dzLzAbout',['dzAddFamilyMember','dzAddLifeEvent','dzCloseAboutMe','dzCloseEditAboutMe','dzLocVisSave','dzOpenAboutMe','dzOpenEditAboutMe','dzPreviewFamilyPic','dzSaveAboutMe','dzSwitchEditTab'],false);
</script>
<template id="dzLzAbout">
<script>
let dzAboutMeData          = null;
let dzFamilyData           = [];
let dzEventsData           = [];
let dzAboutMeProfileId     = null;
let dzFamPicFile           = null;
let dzAboutMeSkeletonShown = false; // shimmer loader shows once per page load/refresh

// ── Skeleton markup ────────────────────────────────────────────────────────
function dzSkeletonRows(n) {
    let out = '';
    for (let i = 0; i < n; i++) {
        out += `<div class="am-sk-row"><div class="am-sk-ic"></div><div class="am-sk-ls"><div class="am-sk-l s"></div><div class="am-sk-l ${i % 2 ? 'm' : 'f'}"></div></div></div>`;
    }
    return out;
}
function dzSkeletonHTML() {
    return `
        <div class="am-sk" id="aboutMeSkeleton">
            <div class="am-sk-sec"><div class="am-sk-t"></div>${dzSkeletonRows(4)}</div>
            <div class="am-sk-sec"><div class="am-sk-t"></div>${dzSkeletonRows(2)}</div>
            <div class="am-sk-sec"><div class="am-sk-t"></div>${dzSkeletonRows(2)}</div>
            <div class="am-sk-sec"><div class="am-sk-t"></div>${dzSkeletonRows(1)}<div class="am-sk-chips"><div class="am-sk-chip"></div><div class="am-sk-chip"></div><div class="am-sk-chip"></div></div></div>
            <div class="am-sk-sec"><div class="am-sk-t"></div><div class="am-sk-l f" style="height:54px;border-radius:5px;"></div></div>
            <div class="am-sk-sec"><div class="am-sk-t"></div><div class="am-sk-q"></div><div class="am-sk-q" style="width:80%;"></div></div>
            <div class="am-sk-sec"><div class="am-sk-t"></div>
                <div class="am-sk-fam">
                    <div class="am-sk-fc"><div class="am-sk-av"></div><div class="am-sk-l m"></div></div>
                    <div class="am-sk-fc"><div class="am-sk-av"></div><div class="am-sk-l m"></div></div>
                    <div class="am-sk-fc"><div class="am-sk-av"></div><div class="am-sk-l m"></div></div>
                </div>
            </div>
            <div class="am-sk-sec"><div class="am-sk-t"></div>${dzSkeletonRows(2)}</div>
        </div>`;
}

document.addEventListener('DOMContentLoaded', function () {
    const body = document.getElementById('aboutMeBody');
    if (body && !body.innerHTML.trim()) body.innerHTML = dzSkeletonHTML();
});

// ── Open / close view modal ────────────────────────────────────────────────
function dzOpenAboutMe(profileId) {
    dzAboutMeProfileId = profileId;
    const modal = document.getElementById('aboutMeModal');
    const body  = document.getElementById('aboutMeBody');
    modal.style.animation = '';
    modal.classList.add('active');
    body.scrollTop = 0;
    document.body.style.overflow = 'hidden';

    // Shimmer skeleton only the first time per page load/refresh.
    if (!dzAboutMeSkeletonShown) body.innerHTML = dzSkeletonHTML();

    fetch('about_me_fetch.php?user_id=' + profileId)
        .then(r => r.json())
        .then(data => {
            dzAboutMeSkeletonShown = true;
            if (!data.success) {
                body.innerHTML = `<div class="am-empty"><i class="ri-lock-line"></i><p>${dzEscape(data.message)}</p></div>`;
                document.getElementById('aboutMeEditBtn').style.display = 'none';
                return;
            }
            dzAboutMeData = data.about;
            dzFamilyData  = data.family || [];
            dzEventsData  = data.events || [];
            document.getElementById('aboutMeEditBtn').style.display = data.is_owner ? 'inline-flex' : 'none';
            body.innerHTML = dzRenderAboutMe(data.about, data.family, data.events, data.is_owner);
        })
        .catch(() => {
            dzAboutMeSkeletonShown = true;
            body.innerHTML = '<div class="am-empty"><i class="ri-error-warning-line"></i><p>Something went wrong. Try again.</p></div>';
        });
}

function dzCloseAboutMe() {
    const m = document.getElementById('aboutMeModal');
    if (!m.classList.contains('active')) return;
    m.style.animation = 'amSlideOut 0.22s ease forwards';
    setTimeout(function () {
        m.classList.remove('active');
        m.style.animation = '';
        document.body.style.overflow = '';
    }, 210);
}

// ── Utilities ──────────────────────────────────────────────────────────────
function dzEscape(str) {
    /* also escapes quotes, so the result is safe inside an HTML attribute as well */
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function dzIsFilled(v) { return v !== null && v !== undefined && v.toString().trim() !== ''; }
function dzVal(v) {
    return dzIsFilled(v)
        ? `<div class="am-value">${dzEscape(v)}</div>`
        : `<div class="am-value empty">Not filled yet</div>`;
}
function dzFormatDOB(dob) {
    if (!dob) return null;
    const d = new Date(dob + 'T00:00:00');
    if (isNaN(d.getTime())) return null;
    return d.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
}
function dzFormatEventDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d.getTime())) return '';
    return d.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
}

const DZ_EVENT_ICONS = {
    'Graduation'          : 'ri-graduation-cap-line',
    'Marriage'            : 'ri-heart-2-line',
    'New Job'             : 'ri-briefcase-4-line',
    'Promotion'           : 'ri-arrow-up-circle-line',
    'Moved Cities'        : 'ri-map-pin-2-line',
    'New Home'            : 'ri-home-4-line',
    'New Baby'            : 'ri-parent-line',
    'Lost a Loved One'    : 'ri-flower-line',
    'Retirement'          : 'ri-rest-time-line',
    'Business Launch'     : 'ri-rocket-2-line',
    'Award / Achievement' : 'ri-trophy-line',
    'Milestone Birthday'  : 'ri-cake-2-line',
    'Travel'              : 'ri-plane-line',
    'Other'               : 'ri-star-line',
};

// ── Render helpers ─────────────────────────────────────────────────────────
function dzRow(icon, label, valueHtml) {
    return `<div class="am-row"><div class="am-row-ic"><i class="${icon}"></i></div><div class="am-row-c"><div class="am-label">${label}</div>${valueHtml}</div></div>`;
}
function dzSection(icon, title, inner) {
    return `<div class="am-sec"><div class="am-sec-h"><i class="${icon}"></i> ${title}</div>${inner}</div>`;
}

// ── Render view modal ──────────────────────────────────────────────────────
function dzRenderAboutMe(a, family, events, isOwner) {
    const dobFormatted = dzFormatDOB(a.date_of_birth);

    const interestsHtml = dzIsFilled(a.interests)
        ? `<div class="am-tags">${a.interests.split(',').map(i => i.trim()).filter(Boolean).map(i => `<span class="am-tag">${dzEscape(i)}</span>`).join('')}</div>`
        : `<div class="am-value empty">Not filled yet</div>`;

    const websiteHtml = dzIsFilled(a.website)
        ? `<div class="am-value"><a href="${dzEscape(a.website)}" target="_blank" rel="noopener noreferrer">${dzEscape(a.website.replace(/^https?:\/\//, '').replace(/\/$/, ''))}</a></div>`
        : `<div class="am-value empty">Not filled yet</div>`;

    const basic = dzSection('ri-user-3-line', 'Basic info',
        dzRow('ri-user-3-line', 'Full Name', dzVal(a.full_name)) +
        dzRow('ri-emotion-line', 'Nickname', dzVal(a.nickname)) +
        dzRow('ri-cake-2-line', 'Date of Birth', dobFormatted ? `<div class="am-value">${dobFormatted}</div>` : `<div class="am-value empty">Not filled yet</div>`) +
        dzRow('ri-heart-2-line', 'Relationship Status', dzVal(a.relationship_status)));

    const work = dzSection('ri-briefcase-4-line', 'Work &amp; education',
        dzRow('ri-briefcase-4-line', 'Occupation', dzVal(a.occupation)) +
        dzRow('ri-graduation-cap-line', 'Education', dzVal(a.education)));

    const loc = dzSection('ri-map-pin-2-line', 'Location',
        dzRow('ri-map-pin-2-line', 'Current Location', dzVal(a.current_location)) +
        (isOwner ? dzLocToggleHtml() : '') +
        dzRow('ri-home-4-line', 'Hometown', dzVal(a.hometown)));

    const links = dzSection('ri-links-line', 'Links &amp; interests',
        dzRow('ri-global-line', 'Website', websiteHtml) +
        dzRow('ri-sparkling-2-line', 'Interests', interestsHtml));

    const desc = dzSection('ri-file-text-line', 'Description',
        dzIsFilled(a.description)
            ? `<div class="am-desc">${dzEscape(a.description).replace(/\n/g, '<br>')}</div>`
            : `<div class="am-value empty">Not filled yet</div>`);

    let contact = '';
    if (dzIsFilled(a.contact_email) || dzIsFilled(a.contact_phone)) {
        contact = dzSection('ri-contacts-line', 'Contact information',
            (dzIsFilled(a.contact_email) ? dzRow('ri-mail-line', 'Email', `<div class="am-value"><a href="mailto:${dzEscape(a.contact_email)}">${dzEscape(a.contact_email)}</a></div>`) : '') +
            (dzIsFilled(a.contact_phone) ? dzRow('ri-phone-line', 'Phone', `<div class="am-value"><a href="tel:${dzEscape(a.contact_phone)}">${dzEscape(a.contact_phone)}</a></div>`) : ''));
    }

    let views = '';
    if (dzIsFilled(a.political_views) || dzIsFilled(a.religious_views)) {
        views = dzSection('ri-scales-3-line', 'Views',
            (dzIsFilled(a.political_views) ? dzRow('ri-government-line', 'Political Views', dzVal(a.political_views)) : '') +
            (dzIsFilled(a.religious_views) ? dzRow('ri-book-open-line', 'Religious Views', dzVal(a.religious_views)) : ''));
    }

    let quotes = '';
    if (dzIsFilled(a.favorite_quotes)) {
        quotes = dzSection('ri-double-quotes-l', 'Favorite quotes',
            `<div class="am-quotes">${a.favorite_quotes.split('\n').map(q => q.trim()).filter(Boolean).map(q => `<div class="am-quote">${dzEscape(q)}</div>`).join('')}</div>`);
    }

    let fam = '';
    if (family && family.length > 0) {
        fam = dzSection('ri-group-line', 'Family members',
            `<div class="am-fam-grid">${family.map(m => `
                <div class="am-fam">
                    ${m.profile_pic
                        ? `<img src="${dzEscape(m.profile_pic)}" alt="${dzEscape(m.member_name)}">`
                        : `<div class="am-avatar"><i class="ri-user-3-line"></i></div>`}
                    <div class="am-fam-name">${dzEscape(m.member_name)}</div>
                    <div class="am-fam-rel">${dzEscape(m.relationship)}</div>
                </div>`).join('')}</div>`);
    }

    let evs = '';
    if (events && events.length > 0) {
        evs = dzSection('ri-calendar-event-line', 'Life events',
            events.map(ev => {
                const icon    = DZ_EVENT_ICONS[ev.event_type] || 'ri-star-line';
                const dateStr = dzFormatEventDate(ev.event_date);
                return `
                <div class="am-ev">
                    <div class="am-ev-ic"><i class="${icon}"></i></div>
                    <div class="am-ev-b">
                        <div class="am-ev-type">${dzEscape(ev.event_type)}</div>
                        <div class="am-ev-title">${dzEscape(ev.event_title)}</div>
                        ${dateStr ? `<div class="am-ev-date">${dateStr}</div>` : ''}
                        ${dzIsFilled(ev.description) ? `<div class="am-ev-desc">${dzEscape(ev.description)}</div>` : ''}
                    </div>
                </div>`;
            }).join(''));
    }

    return basic + work + loc + links + desc + contact + views + quotes + fam + evs;
}

// ── Open / close edit modal ────────────────────────────────────────────────
function dzOpenEditAboutMe() {
    if (!dzAboutMeData) return;
    const form = document.getElementById('editAboutMeForm');
    Object.keys(dzAboutMeData).forEach(key => {
        const field = form.elements[key];
        if (field) field.value = dzAboutMeData[key] || '';
    });
    dzSwitchEditTab('core');
    dzRenderFamilyList();
    dzRenderEventList();
    dzFamPicFile = null;
    document.getElementById('famPicPreview').style.display     = 'none';
    document.getElementById('famPicPlaceholder').style.display = 'flex';
    document.getElementById('famPicInput').value     = '';
    document.getElementById('famName').value         = '';
    document.getElementById('famRelationship').value = '';
    document.getElementById('famConsent').checked    = false;
    form.scrollTop = 0;
    const m = document.getElementById('editAboutMeModal');
    m.style.animation = '';
    m.classList.add('active');
}

function dzCloseEditAboutMe() {
    const m = document.getElementById('editAboutMeModal');
    if (!m.classList.contains('active')) return;
    m.style.animation = 'amSlideOut 0.22s ease forwards';
    setTimeout(function () {
        m.classList.remove('active');
        m.style.animation = '';
    }, 210);
}

// ── Edit tab switching ─────────────────────────────────────────────────────
function dzSwitchEditTab(tab) {
    document.querySelectorAll('.am-etab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.am-panel').forEach(p => p.classList.toggle('active', p.id === 'etab-' + tab));
    const form = document.getElementById('editAboutMeForm');
    if (form) form.scrollTop = 0;
}

// ── Show / hide the current location on the profile page (shown by default) ──
window.dzLocShow = <?php echo !empty($dz_loc_hide_own) ? 'false' : 'true'; ?>;
function dzLocToggleHtml() {
    return `<div class="am-vis"><div class="am-vis-t"><b>Show on my profile</b><span>Your current location appears under your links</span></div><button type="button" class="am-sw${window.dzLocShow ? ' on' : ''}" data-lv="1" role="switch" aria-checked="${window.dzLocShow}" aria-label="Show current location on my profile" onclick="dzLocVisSave(this)"></button></div>`;
}
function dzLocVisSave(btn){
    const want = !btn.classList.contains('on');
    const fd = new FormData();
    fd.set('dz_loc_vis', want ? '1' : '0');
    btn.disabled = true;
    fetch('profile.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (!d || d.status !== 'ok') return;
            window.dzLocShow = want;
            document.querySelectorAll('.am-sw[data-lv]').forEach(b => { b.classList.toggle('on', want); b.setAttribute('aria-checked', want ? 'true' : 'false'); });
            const row = document.querySelector('.plp-loc');
            if (row) row.hidden = !want;
        })
        .catch(() => { btn.disabled = false; });
}

// ── Save core fields ───────────────────────────────────────────────────────
function dzSaveAboutMe() {
    const form    = document.getElementById('editAboutMeForm');
    const fd      = new FormData(form);
    fd.set('action', 'core');
    const saveBtn = document.querySelector('#editAboutMeModal .am-save');
    saveBtn.disabled    = true;
    saveBtn.textContent = 'Saving…';
    fetch('about_me_save.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            saveBtn.disabled    = false;
            saveBtn.textContent = 'Save';
            if (data.success) { dzCloseEditAboutMe(); dzOpenAboutMe(dzAboutMeProfileId); }
            else alert(data.message || 'Could not save. Try again.');
        })
        .catch(() => { saveBtn.disabled = false; saveBtn.textContent = 'Save'; alert('Something went wrong. Try again.'); });
}

// ── Family: pic preview ────────────────────────────────────────────────────
function dzPreviewFamilyPic(input) {
    if (!input.files || !input.files[0]) return;
    dzFamPicFile = input.files[0];
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('famPicPreview');
        img.src = e.target.result;
        img.style.display = 'block';
        document.getElementById('famPicPlaceholder').style.display = 'none';
    };
    reader.readAsDataURL(dzFamPicFile);
}

// ── Family: add ───────────────────────────────────────────────────────────
function dzAddFamilyMember() {
    const name    = document.getElementById('famName').value.trim();
    const rel     = document.getElementById('famRelationship').value;
    const consent = document.getElementById('famConsent').checked;
    if (!name)    { alert('Please enter their name.'); return; }
    if (!rel)     { alert('Please select a relationship.'); return; }
    if (!consent) { alert('You must confirm consent before adding a family member.'); return; }
    const btn = document.querySelector('#etab-family .am-add');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'family_add');
    fd.append('member_name', name);
    fd.append('relationship', rel);
    fd.append('consent_given', '1');
    if (dzFamPicFile) fd.append('member_pic', dzFamPicFile);
    fetch('about_me_save.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                dzFamilyData.push(data.member);
                dzRenderFamilyList();
                document.getElementById('famName').value         = '';
                document.getElementById('famRelationship').value = '';
                document.getElementById('famConsent').checked    = false;
                dzFamPicFile = null;
                document.getElementById('famPicPreview').style.display     = 'none';
                document.getElementById('famPicPlaceholder').style.display = 'flex';
                document.getElementById('famPicInput').value = '';
            } else { alert(data.message || 'Could not add member.'); }
        })
        .catch(() => { btn.disabled = false; alert('Something went wrong.'); });
}

// ── Family: delete ─────────────────────────────────────────────────────────
function dzDeleteFamilyMember(memberId) {
    if (!confirm('Remove this family member?')) return;
    const fd = new FormData();
    fd.append('action', 'family_delete');
    fd.append('member_id', memberId);
    fetch('about_me_save.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { dzFamilyData = dzFamilyData.filter(m => m.id != memberId); dzRenderFamilyList(); }
            else alert(data.message || 'Could not remove member.');
        })
        .catch(() => alert('Something went wrong.'));
}

// ── Family: render list ────────────────────────────────────────────────────
function dzRenderFamilyList() {
    const list = document.getElementById('familyList');
    if (!dzFamilyData.length) {
        list.innerHTML = '<div class="am-empty-sm">No family members added yet.</div>';
        return;
    }
    list.innerHTML = dzFamilyData.map(m => `
        <div class="am-li">
            ${m.profile_pic ? `<img src="${dzEscape(m.profile_pic)}" alt="${dzEscape(m.member_name)}">` : `<div class="am-li-av round"><i class="ri-user-3-line"></i></div>`}
            <div class="am-li-b">
                <div class="am-li-name">${dzEscape(m.member_name)}</div>
                <div class="am-li-sub">${dzEscape(m.relationship)}</div>
            </div>
            <button type="button" class="am-li-del" onclick="dzDeleteFamilyMember(${m.id})" title="Remove" aria-label="Remove"><i class="ri-delete-bin-6-line"></i></button>
        </div>`).join('');
}

// ── Events: add ───────────────────────────────────────────────────────────
function dzAddLifeEvent() {
    const type  = document.getElementById('evType').value;
    const title = document.getElementById('evTitle').value.trim();
    const date  = document.getElementById('evDate').value;
    const desc  = document.getElementById('evDesc').value.trim();
    if (!type)  { alert('Please select an event type.'); return; }
    if (!title) { alert('Please enter a title for this event.'); return; }
    const btn = document.querySelector('#etab-events .am-add');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'event_add');
    fd.append('event_type', type);
    fd.append('event_title', title);
    fd.append('event_date', date);
    fd.append('event_description', desc);
    fetch('about_me_save.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                dzEventsData.unshift(data.event);
                dzRenderEventList();
                document.getElementById('evType').value  = '';
                document.getElementById('evTitle').value = '';
                document.getElementById('evDate').value  = '';
                document.getElementById('evDesc').value  = '';
            } else { alert(data.message || 'Could not add event.'); }
        })
        .catch(() => { btn.disabled = false; alert('Something went wrong.'); });
}

// ── Events: delete ─────────────────────────────────────────────────────────
function dzDeleteLifeEvent(eventId) {
    if (!confirm('Remove this life event?')) return;
    const fd = new FormData();
    fd.append('action', 'event_delete');
    fd.append('event_id', eventId);
    fetch('about_me_save.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { dzEventsData = dzEventsData.filter(e => e.id != eventId); dzRenderEventList(); }
            else alert(data.message || 'Could not remove event.');
        })
        .catch(() => alert('Something went wrong.'));
}

// ── Events: render list ────────────────────────────────────────────────────
function dzRenderEventList() {
    const list = document.getElementById('eventList');
    if (!dzEventsData.length) {
        list.innerHTML = '<div class="am-empty-sm">No life events added yet.</div>';
        return;
    }
    list.innerHTML = dzEventsData.map(ev => {
        const icon    = DZ_EVENT_ICONS[ev.event_type] || 'ri-star-line';
        const dateStr = dzFormatEventDate(ev.event_date);
        return `
        <div class="am-li">
            <div class="am-li-av"><i class="${icon}"></i></div>
            <div class="am-li-b">
                <div class="am-li-name">${dzEscape(ev.event_title)}</div>
                <div class="am-li-sub">${dzEscape(ev.event_type)}${dateStr ? ' · ' + dateStr : ''}</div>
            </div>
            <button type="button" class="am-li-del" onclick="dzDeleteLifeEvent(${ev.id})" title="Remove" aria-label="Remove"><i class="ri-delete-bin-6-line"></i></button>
        </div>`;
    }).join('');
}

// ── Escape key: close the top-most About Me modal ──────────────────────────
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('editAboutMeModal').classList.contains('active')) { dzCloseEditAboutMe(); return; }
    if (document.getElementById('aboutMeModal').classList.contains('active')) dzCloseAboutMe();
});
</script>
</template>

<script>
// ── Tab choice sheet: active tab tracker ──
var _dzActiveTab  = null;
var _dzActiveUrl  = null;      // saved BEFORE the sheet closes
var _dzActiveSec  = null;

var _dzTabConfig = {
    memes: {
        title:   'Your Memes',
        goUrl:   'meme_uploads.php?user_id=<?= (int)$profile_id ?>',
        section: 'memes',
    },
    reposts: {
        title:   'Your Reposts',
        goUrl:   'repost.php?user_id=<?= (int)$profile_id ?>',
        section: 'reposts',
    },
};

function dzShowTabSheet(tab) {
    _dzActiveTab = tab;
    var cfg      = _dzTabConfig[tab];

    // Save values NOW before any close happens
    _dzActiveUrl = cfg.goUrl;
    _dzActiveSec = cfg.section;

    document.getElementById('dzTabSheetTitle').textContent = cfg.title;
    document.getElementById('dzTabSheet').style.display    = 'flex';
}

function dzCloseTabSheet() {
    document.getElementById('dzTabSheet').style.display = 'none';
    // do NOT clear _dzActiveUrl/_dzActiveSec here — read first by dzTabGoToPage/dzTabBrowseHere
}

function dzTabGoToPage() {
    var url = _dzActiveUrl;
    dzCloseTabSheet();
    if (url) window.location.href = url;
}

function dzTabBrowseHere() {
    var sec = _dzActiveSec;
    dzCloseTabSheet();
    if (sec) toggleSection(sec);
}
</script>

<?php
/*
=============================================================
  PROFILE PIC  +  PROFILE MODAL  —  drop into profile.php
  v8 — same design language as the Post Modal
   • FIX: bio / profile type / user type / activity vibe now show their
     real current values (read from the same sources profile.php uses,
     with database fallbacks) instead of always saying "Not set"
=============================================================
*/

$profilePic = $user['profile_pic'] ?? '';
if (empty($profilePic)) $profilePic = 'mee.jpg';

$pid = (int)($profile_id ?? $current_user);
$is_own_profile = ($pid === (int)$current_user);

/* ── $settings may not exist on this page → always make it an array ── */
$settings = (isset($settings) && is_array($settings)) ? $settings : [];

/* ── Raw values, in the same order of priority profile.php uses ── */
$__bio      = $user['bio']       ?? $settings['bio'] ?? $settings['Bio'] ?? null;
$__ptype    = $profile_type      ?? $settings['profile_type']  ?? null;
$__utype    = $user['user_type'] ?? $settings['user_type']     ?? null;
$__vibe     = $activity_vibe     ?? $settings['activity_vibe'] ?? null;

/* ── Database fallback: only runs for values that are still missing/empty ── */
if (isset($conn) && $conn && $pid > 0 && $is_own_profile) {
    if ($__bio === null || $__bio === '' || $__utype === null || $__utype === '') {
        try {
            $st = $conn->prepare("SELECT bio, user_type FROM users WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param("i", $pid);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) {
                    if ($__bio === null || $__bio === '')     $__bio   = $row['bio'] ?? '';
                    if ($__utype === null || $__utype === '') $__utype = $row['user_type'] ?? '';
                }
            }
        } catch (Throwable $e) {}
    }
    if ($__ptype === null || $__ptype === '' || $__vibe === null || $__vibe === '') {
        try {
            $st = $conn->prepare("SELECT profile_type, activity_vibe FROM user_settings WHERE user_id = ? LIMIT 1");
            if ($st) {
                $st->bind_param("i", $pid);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) {
                    if ($__ptype === null || $__ptype === '') $__ptype = $row['profile_type'] ?? '';
                    if ($__vibe === null || $__vibe === '')   $__vibe  = $row['activity_vibe'] ?? '';
                }
            }
        } catch (Throwable $e) {}
    }
}

$current_bio      = htmlspecialchars((string)$__bio);
$current_username = htmlspecialchars((string)($user['username'] ?? ''));
$current_nickname = htmlspecialchars((string)($user['nickname'] ?? ''));
$current_pronouns = htmlspecialchars((string)($user['pronouns'] ?? $settings['pronouns'] ?? ''));

$cur_profile_type = (string)$__ptype;
$pm_haptics_on = !isset($settings['haptics_enabled']) || (int)$settings['haptics_enabled'] === 1;
$cur_user_type    = (string)$__utype;
$cur_vibe         = (string)$__vibe;

/* pronouns — an option list (same as the Settings page), not free text */
$raw_pronouns = trim((string)($user['pronouns'] ?? $settings['pronouns'] ?? ''));
$pm_pronoun_groups = [
    'Common'      => ['He/Him', 'She/Her', 'They/Them'],
    'Mixed'       => ['He/They', 'She/They', 'They/He', 'They/She'],
    'Neopronouns' => ['Ze/Zir', 'Xe/Xem', 'Ey/Em', 'Fae/Faer', 'Ve/Ver'],
];
$pm_pron_sel = $raw_pronouns; $pm_pron_custom = false;
if ($raw_pronouns !== '') {
    $found = false;
    foreach (array_merge(...array_values($pm_pronoun_groups)) as $opt) {
        if (strcasecmp($opt, $raw_pronouns) === 0) { $pm_pron_sel = $opt; $found = true; break; }
    }
    $pm_pron_custom = !$found;
}

$pm_user_types = ['' => "Don't show a user type on your profile.", 'Dezzmer' => 'Create memes and post random memes.'];
$pm_vibes = [
    '' => 'None', 'Flexible' => 'Flexible',
    'Early morning (5–9 AM)' => 'Early morning (5–9 AM)', 'Late morning (9–12 PM)' => 'Late morning (9–12 PM)',
    'Afternoon (12–5 PM)' => 'Afternoon (12–5 PM)', 'Evening (5–9 PM)' => 'Evening (5–9 PM)',
    'Night (9 PM–1 AM)' => 'Night (9 PM–1 AM)', 'Late night (1–5 AM)' => 'Late night (1–5 AM)',
];

/* username change limit (once every 14 days), same column the Settings page uses */
$pm_un_locked = false; $pm_un_days = 0;
if (isset($conn) && $conn && $is_own_profile) {
    try {
        $st = $conn->prepare("SELECT username_changed_at FROM users WHERE id = ? LIMIT 1");
        if ($st) {
            $st->bind_param("i", $pid); $st->execute();
            $r = $st->get_result()->fetch_assoc(); $st->close();
            $t = !empty($r['username_changed_at']) ? strtotime($r['username_changed_at']) : 0;
            if ($t && time() < $t + 14 * 86400) { $pm_un_locked = true; $pm_un_days = (int)ceil(($t + 14 * 86400 - time()) / 86400); }
        }
    } catch (Throwable $e) {}
}

/* organisation / entity types (businesses, brands, schools, clubs, media, institutions…) */
$org_categories = [
  '💻 Digital, internet & technology' => ['Digital Platform','Social Media Platform','Media Platform','Streaming Platform','Digital Community','Online Marketplace','E-commerce Platform','Software Company','Technology Company','SaaS Company','App Developer','Web Service','Internet Service','AI Company','AI Developer','Tech Startup','Cybersecurity Company','Cloud Service Provider','IT Services','Web Development Agency','Digital Product','Tech Brand','Gaming Platform','Fintech Platform','Digital Infrastructure'],
  '📺 Media, entertainment & publishing' => ['Media Brand','Media Company','Digital Publisher','News Organization','News Outlet','Magazine','Newspaper','Editorial Brand','Broadcasting Network','Television Network','Radio Station','Podcast Network','Production Company','Film Studio','Record Label','Music Publisher','Entertainment Company','Entertainment Brand','Streaming Network','Documentary Studio','Animation Studio','Publishing House','Book Publisher','Comic Publisher','Independent Media'],
  '🏢 Businesses, commerce & brands' => ['Business','Company','Corporate Brand','Consumer Brand','Retail Brand','Product Brand','Luxury Brand','Fashion Brand','Beauty Brand','Lifestyle Brand','Franchise','Startup','Small Business','Local Business','Online Store','Retail Store','Wholesale Business','Distributor','Manufacturer','Supplier','Marketplace Seller','Service Business','Family Business','Business Group','Holding Company'],
  '🎨 Creative & design industries' => ['Creative Studio','Design Studio','Graphic Design Studio','Branding Agency','Advertising Agency','Art Studio','Illustration Studio','Photography Studio','Film Production Studio','Animation Company','Creative Agency','Fashion House','Fashion Label','Interior Design Studio','Architecture Studio','Music Studio','Sound Studio','Creative Collective','Art Collective','Design Collective','Digital Art Brand','Production Agency'],
  '🎓 Education & knowledge' => ['Educational Institution','University','College','School','Academy','Training Institute','Learning Platform','Education Company','Educational Brand','Research Institute','Research Centre','Science Organization','Academic Department','Professional Institute','Online School','Tutoring Centre','Educational Publisher','Knowledge Platform','Learning Community','Study Organization'],
  '🏛️ Government, public & civic institutions' => ['Government Agency','Government Department','Public Institution','Public Service','Municipal Authority','City Administration','Regional Government','National Agency','Embassy','Consulate','Public Information Office','Political Organization','Civic Organization','Electoral Organization','Public Broadcasting','Law Enforcement Agency','Public Research Agency'],
  '🤝 Nonprofits, advocacy & social organizations' => ['Nonprofit Organization','Charity','Foundation','NGO','Advocacy Organization','Humanitarian Organization','Community Organization','Social Impact Organization','Volunteer Organization','Environmental Organization','Conservation Organization','Cultural Organization','Human Rights Organization','Youth Organization','Religious Organization','Welfare Organization','Fundraising Organization','Campaign Organization'],
  '💰 Finance, investment & professional services' => ['Financial Institution','Bank','Fintech Company','Investment Firm','Venture Capital Firm','Private Equity Firm','Insurance Company','Financial Services Company','Accounting Firm','Consulting Firm','Business Consultancy','Legal Firm','Law Office','Wealth Management Firm','Brokerage','Payment Service','Credit Institution','Financial Education Brand'],
  '🩺 Health, medicine & wellness' => ['Healthcare Institution','Hospital','Clinic','Medical Practice','Medical Centre','Health Organization','Health Brand','Pharmaceutical Company','Medical Technology Company','Laboratory','Diagnostic Centre','Health Research Institute','Wellness Brand','Fitness Brand','Mental Health Organization','Medical Publisher','Healthcare Provider Network'],
  '🏆 Sports & athletics' => ['Sports Organization','Sports Club','Sports Team','Football Club','Athletic Association','Sports Federation','Sports League','Sports Academy','Sports Brand','Sports Media','Sports Agency','Sports Management Company','Tournament Organizer','Sporting Event','Fitness Organization','Esports Organization','Esports Team'],
  '🍽️ Travel, hospitality & food' => ['Hospitality Brand','Hotel','Hotel Group','Resort','Travel Company','Travel Agency','Tour Operator','Tourism Organization','Tourism Board','Airline','Transportation Company','Restaurant','Restaurant Group','Food Brand','Beverage Brand','Food Company','Catering Company','Café','Bakery','Food Service','Travel Platform'],
  '🏗️ Real estate, construction & infrastructure' => ['Real Estate Company','Property Developer','Real Estate Agency','Property Management Company','Construction Company','Architecture Firm','Engineering Firm','Infrastructure Company','Housing Organization','Property Investment Firm','Building Contractor','Interior Design Company','Urban Development Organization','Facilities Management Company'],
  '🔬 Science, nature & environment' => ['Scientific Organization','Science Institution','Research Organization','Scientific Laboratory','Space Organization','Space Company','Environmental Organization','Conservation Group','Wildlife Organization','Botanical Institution','Zoological Institution','Museum','Natural History Institution','Observatory','Science Media','Scientific Publisher','Climate Organization'],
  '👥 Communities, clubs & membership organizations' => ['Community','Professional Association','Membership Organization','Club','Social Club','Alumni Association','Cultural Association','Interest Organization','Hobby Organization','Fan Organization','Fan Club','Community Network','Professional Network','Trade Association','Industry Association','Cooperative','Guild','Union'],
  '🎪 Events, exhibitions & experiences' => ['Event Organizer','Event Company','Event Brand','Exhibition Organizer','Festival Organizer','Conference Organizer','Convention Organizer','Trade Show Organizer','Cultural Event','Music Festival','Art Exhibition','Event Venue','Experience Brand','Entertainment Venue','Event Production Company'],
  '👗 Fashion, beauty & personal lifestyle' => ['Fashion Company','Clothing Brand','Fashion Retailer','Fashion Designer Label','Jewelry Brand','Accessories Brand','Beauty Company','Cosmetics Brand','Skincare Brand','Haircare Brand','Fragrance Brand','Grooming Brand','Lifestyle Company','Lifestyle Publisher','Homeware Brand','Furniture Brand'],
];

if (empty($profile_categories)) {
    $profile_categories = [
      '🎬 Content & Creator' => ['Content Creator','Digital Creator','Influencer','Social Media Creator','Video Creator','Video Blogger / Vlogger','Blogger','Streamer','Livestreamer','Podcaster','Writer','Author','Poet','Screenwriter','Copywriter','Journalist','News Creator','Meme Creator','Meme Curator','Photographer','Videographer','Filmmaker','Director','Producer','Animator','Voice Actor','Voice-over Artist'],
      '🎨 Art & Design' => ['Artist','Digital Artist','Painter','Sculptor','Illustrator','Graphic Designer','UI Designer','UX Designer','Product Designer','Fashion Designer','Jewelry Designer','Interior Designer','3D Artist','3D Designer','Art Director','Creative Director','Makeup Artist','Tattoo Artist','Nail Artist','Hair Stylist','Fashion Stylist'],
      '🎵 Music & Entertainment' => ['Musician','Singer','Songwriter','Rapper','DJ','Composer','Instrumentalist','Dancer','Choreographer','Actor','Actress','Model','Comedian','Entertainer','Performer','Magician','Celebrity','Public Figure'],
      '💻 Technology' => ['Software Engineer','Software Developer','Web Developer','Mobile App Developer','Front-End Developer','Back-End Developer','Full-Stack Developer','Game Developer','Game Designer','UI/UX Designer','Data Scientist','Data Analyst','AI Engineer','Machine Learning Engineer','Cybersecurity Specialist','Ethical Hacker','DevOps Engineer','Cloud Engineer','IT Specialist','Systems Administrator','Database Administrator','Computer Scientist','Tech Entrepreneur','Tech Reviewer'],
      '💼 Business & Professional' => ['Entrepreneur','Founder','CEO','Executive','Business Owner','Consultant','Freelancer','Freelancer/Independent Professional','Investor','Financial Analyst','Accountant','Banker','Economist','Real Estate Agent','Realtor','Salesperson','Marketing Specialist','Brand Strategist','Business Strategist','Recruiter','Human Resources Professional','Project Manager','Product Manager','Operations Manager'],
      '📣 Marketing & Social Media' => ['Social Media Manager','Community Manager','Social Media Strategist','Digital Marketer','Marketing Manager','Brand Manager','Public Relations Specialist','Publicist','SEO Specialist','Advertising Specialist','Influencer Manager','Talent Manager','Brand Ambassador','Affiliate Marketer'],
      '🔬 Science & Medicine' => ['Scientist','Researcher','Biologist','Chemist','Physicist','Mathematician','Engineer','Doctor','Surgeon','Dentist','Pharmacist','Nurse','Therapist','Psychologist','Veterinarian','Nutritionist','Dietitian','Medical Researcher','Healthcare Professional'],
      '⚖️ Law, Government & Public Service' => ['Lawyer','Attorney','Judge','Legal Consultant','Paralegal','Politician','Civil Servant','Government Official','Diplomat','Activist','Human Rights Advocate','Community Organizer','Nonprofit Worker','Policy Analyst'],
      '🎓 Education' => ['Student','Teacher','Professor','Lecturer','Tutor','Academic','Educational Creator','Education Consultant','School Administrator','Librarian','Academic Advisor','Coach','Mentor'],
      '🏋️ Health, Fitness & Lifestyle' => ['Personal Trainer','Fitness Coach','Athlete','Sports Professional','Yoga Instructor','Wellness Coach','Nutrition Coach','Life Coach','Lifestyle Creator','Health Educator','Chef','Baker','Food Creator'],
      '✈️ Travel & Hospitality' => ['Travel Creator','Travel Blogger','Travel Agent','Tour Guide','Pilot','Flight Attendant','Hotelier','Hotel Manager','Event Planner','Wedding Planner','Hospitality Professional','Food Critic','Restaurant Owner'],
      '👗 Fashion & Beauty' => ['Fashion Model','Fashion Creator','Fashion Blogger','Stylist','Personal Stylist','Beauty Creator','Hairstylist','Barber','Esthetician','Cosmetic Artist','Fashion Buyer','Fashion Photographer'],
      '🏗️ Trades & Skilled Work' => ['Electrician','Plumber','Carpenter','Mechanic','Welder','Builder','Construction Worker','Architect','Surveyor','Mason','Technician','Auto Technician','Tailor','Seamstress','Florist','Gardener','Farmer','Landscaper'],
      '📰 Media & Communications' => ['Reporter','Editor','News Anchor','Broadcaster','Radio Host','TV Host','Presenter','Correspondent','Photojournalist','Public Relations Professional','Communications Specialist'],
      '🕹️ Gaming' => ['Gamer','Professional Gamer','Esports Player','Esports Coach','Gaming Creator','Gaming Streamer','Game Reviewer','Gaming Commentator','Esports Analyst'],
      '🛍️ Commerce & Retail' => ['Shop Owner','Retailer','Online Seller','E-commerce Seller','Reseller','Dropshipper','Product Reviewer','Merchant','Wholesaler','Distributor'],
      '🧑‍💼 Other / Personal' => ['Retired','Homemaker','Stay-at-home Parent','Job Seeker','Intern','Apprentice','Volunteer','Military Personnel','Religious Worker','Self-employed','Independent Professional','Private Individual','Other'],
    ];
}
?>

<style>
/* ═══════════════════════════════════════════
   PROFILE PIC
═══════════════════════════════════════════ */


/* ═══════════════════════════════════════════════════════════
   PROFILE MODALS — same design language as the post modal
   • full-screen overlay, content in a CENTERED LANE
   • two grey lines separate the lane from the empty space
   • slightly rounded rectangular buttons, premium fluid sizing
   • bright mid-tone RED accent, black text on red buttons
═══════════════════════════════════════════════════════════ */
.pm-overlay {
    --pm-red:#E8332E; --pm-red-h:#d02a26; --pm-red-l:#E8332E;
    --pm-red-dim:rgba(232,51,46,.16);
    --pm-on-red:#000;
    --pm-card:rgba(var(--fg,255,255,255),.05);
    --pm-line:rgba(var(--fg,255,255,255),.09); --pm-line2:rgba(var(--fg,255,255,255),.14);
    --pm-sub:rgba(var(--fg,255,255,255),.62); --pm-mut:rgba(var(--fg,255,255,255),.45);
    --pm-pad:calc(clamp(14px,4vw,20px) * var(--md-s));
    --pm-act-h:calc(clamp(25px,7.2vw,30px) * var(--md-s));
    --pm-r:5px;
    --pm-br:5px;   /* button / chip corner radius (was 2px) */
    --pm-fs:calc(.8rem * var(--md-s));
    --pm-tb-h:calc(54px * var(--md-s));

    display: none;
    position: fixed; inset: 0;
    z-index: 100000;
    background: linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--pm-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);
}
@media (max-width:490px){ .pm-overlay{ --pm-fs:calc(.74rem * var(--md-s)); --pm-tb-h:calc(48px * var(--md-s)); } }
@media (max-width:390px){ .pm-overlay{ --pm-fs:calc(.7rem * var(--md-s));  --pm-tb-h:calc(44px * var(--md-s)); } }

.pm-overlay.pm-open { opacity: 1; transform: translateY(0); }
.pm-overlay.pm-nested {
    z-index: 100001;
    transform: translateX(28px);
    transition: opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1);
}
.pm-overlay.pm-nested.pm-open { transform: translateX(0); }

.pm-overlay *, .pm-overlay *::before, .pm-overlay *::after { box-sizing: border-box; }
.pm-overlay button, .pm-overlay input, .pm-overlay textarea { font-family: 'Poppins', sans-serif; }
.pm-overlay button { cursor: pointer; -webkit-tap-highlight-color: transparent; }
.pm-overlay button, .pm-overlay a, .pm-overlay input, .pm-overlay textarea { outline: none; }
/* icons keep the icon font */
.pm-overlay [class^="ri-"], .pm-overlay [class*=" ri-"],
.pm-overlay [class^="ri-"]::before, .pm-overlay [class*=" ri-"]::before {
    font-family: 'remixicon' !important; font-style: normal; font-weight: normal; line-height: 1;
}

/* ── the lane + two grey lines ── */
.pm-lane {
    width: 100%; max-width: 580px; height: 100%;
    display: flex; flex-direction: column; min-height: 0;
}
@media (min-width: 801px) {
    .pm-lane { border-left: 1px solid var(--pm-line); border-right: 1px solid var(--pm-line); }
}
@media (max-width: 800px) { .pm-lane { max-width: 100%; } }

/* ── Top bar (no grey line) — X left, title on the right ── */
.pm-topbar {
    flex-shrink: 0;
    display: flex; align-items: center;
    min-height: var(--pm-tb-h);
    padding: env(safe-area-inset-top) calc(10px * var(--md-s)) 0;
    background: transparent;
}
.pm-tb-side { flex: 0 0 38px; display: flex; align-items: center; }
.pm-x {
    width: calc(34px * var(--md-s)); height: calc(34px * var(--md-s)); background: none; border: none; color: var(--text,#F0F2FF);
    display: flex; align-items: center; justify-content: center; opacity: .9;
    padding: 0; transition: 0.2s;
}
.pm-x:hover { color: var(--pm-red-l); opacity: 1; }
.pm-x svg { width: calc(22px * var(--md-s)); height: calc(22px * var(--md-s)); display: block; }
.pm-topbar-center { flex: 1; min-width: 0; text-align: right; padding-right: calc(4px * var(--md-s)); }
.pm-topbar-title {
    font-size: calc(.92rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width:490px){ .pm-topbar-title{ font-size:calc(.84rem * var(--md-s)); } .pm-x{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); } }
@media (max-width:390px){ .pm-topbar-title{ font-size:calc(.8rem * var(--md-s)); } }

/* ── scroll area ── */
.pm-body {
    flex: 1; min-height: 0;
    overflow-y: auto; overflow-x: hidden;
    overscroll-behavior: contain; -webkit-overflow-scrolling: touch;
    padding: calc(4px * var(--md-s)) 0 calc(28px * var(--md-s));
    scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.28) transparent;
}
.pm-body::-webkit-scrollbar { width: calc(4px * var(--md-s)); }
.pm-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,.28); border-radius: calc(99px * var(--md-s)); }
@media (max-width: 800px) {
    .pm-body { scrollbar-width: none; -ms-overflow-style: none; }
    .pm-body::-webkit-scrollbar { display: none; width: 0; height: 0; }
}
.pm-nested-body { display: flex; flex-direction: column; gap: calc(16px * var(--md-s)); padding: calc(14px * var(--md-s)) var(--pm-pad) calc(28px * var(--md-s)); }

/* ── Buttons: slightly rounded rectangles, red, black text ── */
.pm-btn {
    display: flex; align-items: center; justify-content: center; gap: calc(6px * var(--md-s));
    flex: 1; min-width: 0; height: var(--pm-act-h); padding: 0 calc(14px * var(--md-s));
    border: none; border-radius: var(--pm-br);
    background: var(--pm-red); color: var(--pm-on-red);
    font-size: calc(.7rem * var(--md-s)); font-weight: 700; line-height: 1; white-space: nowrap;
    text-decoration: none;
    transition: background .2s ease, transform .15s ease;
}
.pm-btn:hover { background: var(--pm-red-h); }
.pm-btn:active { transform: scale(.98); }
.pm-btn:disabled { opacity: .5; cursor: not-allowed; }
.pm-btn.ghost { background: rgba(var(--fg,255,255,255),.1); color: var(--text,#F0F2FF); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
.pm-btn.ghost:hover { background: rgba(var(--fg,255,255,255),.2); }
.pm-btn.sm { flex: 0 0 auto; padding: 0 calc(14px * var(--md-s)); }
.pm-btn.full { width: 100%; flex: none; }
.pm-btn i { font-size: calc(13px * var(--md-s)); }
@media (max-width:490px){ .pm-btn{ font-size:calc(.62rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); gap:calc(4px * var(--md-s)); } .pm-btn i{ font-size:calc(11px * var(--md-s)); } }

/* ═══════════════════════════════════════════
   PROFILE PIC SECTION
═══════════════════════════════════════════ */
.pm-pic-section {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
    gap: calc(14px * var(--md-s));
    padding: calc(14px * var(--md-s)) var(--pm-pad) calc(20px * var(--md-s));
    border-bottom: 1px solid var(--pm-line);
}
/* the three stacked buttons: Create / Upload · Change Profile Picture · Analytics
   no icons, BLACK text, words start from the left, slightly rounded corners */
.pm-pic-btns { display: flex; flex-direction: column; align-items: stretch; gap: calc(8px * var(--md-s)); }
.pm-pic-btns .pm-btn {
    height: calc(var(--pm-act-h) - calc(4px * var(--md-s)));
    padding: 0 calc(clamp(16px,5.5vw,28px) * var(--md-s));
    justify-content: flex-start;
    text-align: left;
    color: #000;
    border-radius: calc(5px * var(--md-s));
}
.pm-pic-wrap { position: relative; flex-shrink: 0; cursor: pointer; width: calc(clamp(76px,22vw,96px) * var(--md-s)); height: calc(clamp(76px,22vw,96px) * var(--md-s)); }
.pm-pic-wrap img {
    width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block;
    border: 3px solid var(--pm-red);
    box-shadow: none;
}
.pm-pic-overlay {
    position: absolute; inset: 0; border-radius: 50%;
    background: rgba(0,0,0,.4);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .2s; pointer-events: none;
}
.pm-pic-wrap:hover .pm-pic-overlay { opacity: 1; }
.pm-pic-overlay i { font-size: calc(22px * var(--md-s)); color: #fff; }

#pmPicFileInput { display: none; }

/* Preview */
.pm-preview-area {
    display: none; flex-direction: column; align-items: center;
    gap: calc(12px * var(--md-s)); margin-top: calc(4px * var(--md-s)); padding: calc(16px * var(--md-s));
    background: var(--pm-card); border: 1px solid var(--pm-line);
    border-radius: calc(10px * var(--md-s)); width: 100%; max-width: 280px; margin-left: auto;
}
.pm-preview-area.visible { display: flex; }
.pm-preview-area img {
    width: calc(clamp(64px,18vw,80px) * var(--md-s)); height: calc(clamp(64px,18vw,80px) * var(--md-s));
    border-radius: 50%; object-fit: cover; border: 3px solid var(--pm-red);
}
.pm-preview-label {
    font-size: calc(.58rem * var(--md-s)); color: var(--pm-mut);
    text-transform: uppercase; letter-spacing: .8px; font-weight: 700;
}
.pm-preview-actions { display: flex; gap: calc(8px * var(--md-s)); width: 100%; }

/* ═══════════════════════════════════════════
   SECTION LABEL + ROWS
═══════════════════════════════════════════ */
.pm-sec-h {
    display: flex; align-items: center; gap: calc(6px * var(--md-s));
    font-size: calc(.64rem * var(--md-s)); font-weight: 600; letter-spacing: .6px;
    text-transform: uppercase; color: var(--pm-sub);
    padding: calc(18px * var(--md-s)) var(--pm-pad) calc(7px * var(--md-s));
}

.pm-edit-row {
    display: flex; align-items: center; gap: calc(12px * var(--md-s));
    padding: calc(10px * var(--md-s)) var(--pm-pad);
    border-bottom: 1px solid var(--pm-line);
    text-decoration: none; color: inherit;
    cursor: pointer; transition: background .18s;
    -webkit-tap-highlight-color: transparent;
}
.pm-edit-row:hover { background: rgba(var(--fg,255,255,255),.04); }
.pm-edit-row:active { background: rgba(var(--fg,255,255,255),.07); }

.pm-edit-icon {
    width: calc(28px * var(--md-s)); flex-shrink: 0; text-align: center;
    display: flex; align-items: center; justify-content: center;
    font-size: calc(20px * var(--md-s)); color: var(--pm-red-l);
}
.pm-edit-meta { flex: 1; min-width: 0; }
.pm-edit-label { font-size: calc(.71rem * var(--md-s)); font-weight: 600; color: var(--text,#F0F2FF); }
.pm-edit-current {
    font-size: calc(.66rem * var(--md-s)); color: var(--pm-mut); margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pm-edit-chevron { font-size: calc(18px * var(--md-s)); color: var(--pm-mut); transition: transform .18s, color .18s; }
.pm-edit-row:hover .pm-edit-chevron { transform: translateX(3px); color: var(--text,#F0F2FF); }

@media (max-width:490px){
    .pm-edit-icon { font-size: calc(18px * var(--md-s)); width: calc(24px * var(--md-s)); }
    .pm-edit-row { gap: calc(10px * var(--md-s)); }
    .pm-edit-label { font-size: calc(.66rem * var(--md-s)); }
    .pm-edit-current { font-size: calc(.62rem * var(--md-s)); }
    .pm-sec-h { padding-top: calc(14px * var(--md-s)); }
}

/* ═══════════════════════════════════════════
   NESTED FORM FIELDS
═══════════════════════════════════════════ */
.pm-field-group { display: flex; flex-direction: column; gap: calc(8px * var(--md-s)); }
.pm-field-label {
    font-size: calc(.62rem * var(--md-s)); font-weight: 700; letter-spacing: .6px;
    text-transform: uppercase; color: var(--pm-sub);
}
.pm-field-input {
    width: 100%; height: var(--pm-act-h); padding: 0 calc(11px * var(--md-s));
    border-radius: var(--pm-r);
    border: 1px solid var(--pm-line2); background: var(--pm-card); color: var(--text,#F0F2FF);
    font-size: calc(.68rem * var(--md-s)); transition: .2s; -webkit-appearance: none; appearance: none;
}
.pm-field-input:focus { border-color: rgba(232,51,46,.85); box-shadow: 0 0 0 3px rgba(232,51,46,.16); }
.pm-field-input::placeholder { color: var(--pm-mut); }
.pm-field-input[readonly] { opacity: .6; cursor: not-allowed; }
textarea.pm-field-input { height: auto; min-height: calc(108px * var(--md-s)); padding: calc(9px * var(--md-s)) calc(11px * var(--md-s)); line-height: 1.6; resize: none; font-size: calc(.73rem * var(--md-s)); }
@media (max-width:490px){ .pm-field-input{ font-size:calc(.63rem * var(--md-s)); } textarea.pm-field-input{ font-size:calc(.68rem * var(--md-s)); min-height: calc(96px * var(--md-s)); } }

/* Availability */
.pm-avail-row { display: flex; align-items: center; gap: calc(7px * var(--md-s)); min-height: calc(16px * var(--md-s)); }
.pm-avail-indicator { font-size: calc(.76rem * var(--md-s)); }
.pm-avail-text { font-size: calc(.68rem * var(--md-s)); color: var(--pm-mut); }
.pm-avail-text.taken { color: #fb923c; }
.pm-avail-text.ok    { color: #6ee7b7; }

.pm-suggestions { display: flex; flex-wrap: wrap; gap: calc(7px * var(--md-s)); }
.pm-suggestion-chip {
    height: var(--pm-act-h); padding: 0 calc(12px * var(--md-s)); border: none; border-radius: var(--pm-br);
    background: rgba(var(--fg,255,255,255),.1); color: var(--text,#F0F2FF);
    font-size: calc(.66rem * var(--md-s)); font-weight: 600; transition: .2s;
}
.pm-suggestion-chip:hover { background: rgba(var(--fg,255,255,255),.2); }
.pm-suggestion-chip:active { transform: scale(.96); }
@media (max-width:490px){ .pm-suggestion-chip{ font-size:calc(.6rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); } }

/* Info note */
.pm-info-note {
    background: var(--pm-card); border: 1px solid var(--pm-line);
    border-radius: calc(8px * var(--md-s)); padding: calc(11px * var(--md-s)) calc(13px * var(--md-s));
    font-size: calc(.7rem * var(--md-s)); color: var(--pm-mut); line-height: 1.65;
}
.pm-info-note strong { color: var(--pm-sub); font-weight: 600; }
@media (max-width:490px){ .pm-info-note{ font-size:calc(.65rem * var(--md-s)); } }

.pm-char-count { font-size: calc(.62rem * var(--md-s)); color: var(--pm-mut); text-align: right; }

/* ═══════════════════════════════════════════
   TOAST
═══════════════════════════════════════════ */
.pm-toast {
    position: fixed; bottom: calc(30px * var(--md-s)); left: 50%;
    transform: translateX(-50%) translateY(10px);
    background: var(--surface-2,#0d1030);
    border: 1px solid var(--pm-line2, rgba(255,255,255,.14));
    color: #fff; padding: calc(8px * var(--md-s)) calc(18px * var(--md-s)); border-radius: calc(5px * var(--md-s));
    font-size: calc(.67rem * var(--md-s)); font-family: 'Poppins', sans-serif; font-weight: 500;
    z-index: 999999; pointer-events: none;
    box-shadow: 0 8px 28px rgba(0,0,0,.65);
    white-space: nowrap; opacity: 0;
    transition: opacity .22s ease, transform .22s ease;
}
.pm-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }


/* ═══ selects, chips, radios ═══ */
.pm-overlay [hidden] { display: none !important; }
select.pm-field-input {
    padding-right: calc(32px * var(--md-s)); cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23999' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 11px center;
}
select.pm-field-input option, select.pm-field-input optgroup { background: #000; color: #fff; }

.pm-pt-current { display: flex; align-items: center; justify-content: space-between; gap: calc(10px * var(--md-s)); font-size: calc(.64rem * var(--md-s)); color: var(--pm-sub); }
.pm-pt-current strong { color: var(--text,#F0F2FF); font-weight: 600; }
.pm-pt-current .pm-btn { flex: 0 0 auto; }
.pm-pt-cat { margin-bottom: calc(18px * var(--md-s)); }
.pm-pt-cat-title { font-size: calc(.62rem * var(--md-s)); font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--pm-sub); margin-bottom: calc(8px * var(--md-s)); }
.pm-chips { display: flex; flex-wrap: wrap; gap: calc(6px * var(--md-s)); }
.pm-chip {
    height: calc(var(--pm-act-h) - calc(3px * var(--md-s))); padding: 0 calc(11px * var(--md-s)); border: none; border-radius: var(--pm-br);
    background: rgba(var(--fg,255,255,255),.1); color: var(--text,#F0F2FF); font-size: calc(.64rem * var(--md-s)); font-weight: 600; transition: .2s;
}
.pm-chip:hover { background: rgba(var(--fg,255,255,255),.2); }
.pm-chip:active { transform: scale(.96); }
.pm-chip.selected { background: var(--pm-red); color: var(--pm-on-red); }
.pm-chip.selected::before { content: '✓ '; }
.pm-chip.busy { opacity: .55; pointer-events: none; }
.pm-pt-none { display: none; text-align: center; padding: calc(24px * var(--md-s)) 0; color: var(--pm-mut); font-size: calc(.72rem * var(--md-s)); }
.pm-pt-none.show { display: block; }
.pm-tabs { display: flex; gap: calc(6px * var(--md-s)); margin-bottom: calc(10px * var(--md-s)); }
.pm-tab { flex: 1; min-width: 0; height: calc(28px * var(--md-s)); padding: 0 8px; border: none; border-radius: 5px; background: rgba(var(--fg,255,255,255),.08); color: var(--pm-sub); font-size: calc(.64rem * var(--md-s)); font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: background .2s, color .2s, transform .15s; font-family: inherit; }
.pm-tab i { font-size: 13px; flex-shrink: 0; }
.pm-tab:active { transform: scale(.97); }
.pm-tab.on { background: var(--pm-red); color: var(--pm-on-red); }
@keyframes pmUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
.pm-pt-cat.pm-in { animation: pmUp .38s cubic-bezier(.22,1,.36,1) both; }
#pmPtList.pm-reveal .pm-pt-cat:nth-child(2) { animation-delay: .05s; }
#pmPtList.pm-reveal .pm-pt-cat:nth-child(3) { animation-delay: .1s; }
#pmPtList.pm-reveal .pm-pt-cat:nth-child(n+4) { animation-delay: .15s; }

.pm-radio-item { display: flex; align-items: center; justify-content: space-between; gap: calc(14px * var(--md-s)); padding: calc(12px * var(--md-s)) 0; border-bottom: 1px solid var(--pm-line); cursor: pointer; }
.pm-radio-item:last-child { border-bottom: none; }
.pm-radio-text { flex: 1; min-width: 0; }
.pm-radio-text strong { display: block; font-size: calc(.71rem * var(--md-s)); font-weight: 600; color: var(--text,#F0F2FF); }
.pm-radio-text span { display: block; font-size: calc(.66rem * var(--md-s)); color: var(--pm-mut); margin-top: 2px; line-height: 1.5; }
.pm-radio-item input { accent-color: var(--pm-red); width: calc(18px * var(--md-s)); height: calc(18px * var(--md-s)); flex-shrink: 0; margin: 0; cursor: pointer; }

/* ═══ premium skeleton loader ═══ */
.pm-body { position: relative; }
.pm-body.pm-loading { overflow: hidden; }
.pm-skel {
    position: absolute; inset: 0; z-index: 5;
    background: linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924))); background-attachment: fixed;
    opacity: 1; visibility: visible; transition: opacity .3s ease, visibility 0s;
}
.pm-skel.hide { opacity: 0; visibility: hidden; pointer-events: none; transition: opacity .3s ease, visibility 0s .3s; }
.pm-sk {
    border-radius: calc(6px * var(--md-s));
    background: linear-gradient(90deg, rgba(var(--fg,255,255,255),.04) 25%, rgba(255,255,255,.1) 50%, rgba(255,255,255,.04) 75%);
    background-size: 600px 100%; animation: pmShimmer 1.5s infinite linear; flex-shrink: 0;
}
@keyframes pmShimmer { 0% { background-position: -600px 0; } 100% { background-position: 600px 0; } }
.pm-sk-pic { display: flex; justify-content: space-between; align-items: center; padding: calc(14px * var(--md-s)) var(--pm-pad) calc(20px * var(--md-s)); border-bottom: 1px solid var(--pm-line); }
.pm-sk-btns { display: flex; flex-direction: column; gap: calc(8px * var(--md-s)); }
.pm-sk-sec { padding: calc(18px * var(--md-s)) var(--pm-pad) calc(10px * var(--md-s)); }
.pm-sk-row { display: flex; align-items: center; gap: calc(12px * var(--md-s)); padding: calc(12px * var(--md-s)) var(--pm-pad); border-bottom: 1px solid var(--pm-line); }
.pm-sk-col { flex: 1; display: flex; flex-direction: column; gap: calc(7px * var(--md-s)); }
.pm-sk-pad { display: flex; flex-direction: column; gap: calc(14px * var(--md-s)); padding: calc(14px * var(--md-s)) var(--pm-pad); }
.pm-sk-chips { display: flex; flex-wrap: wrap; gap: calc(6px * var(--md-s)); }

@media (prefers-reduced-motion:reduce){ .pm-overlay, .pm-overlay *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>


<?php if ($is_own_profile): ?>

<!-- ═══════════════════════════════════════
     MAIN MODAL — centered lane
═══════════════════════════════════════ -->
<div id="pmMain" class="pm-overlay" role="dialog" aria-modal="true" aria-label="Profile">
 <div class="pm-lane">

    <div class="pm-topbar">
        <div class="pm-tb-side">
            <button type="button" class="pm-x" onclick="pmClose('pmMain')" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="pm-topbar-center"><div class="pm-topbar-title">Profile</div></div>
    </div>

    <div class="pm-body" id="pmMainBody">

        <!-- Profile pic + stacked buttons -->
        <div class="pm-pic-section">
            <div class="pm-pic-btns">
                <a href="upload.php" class="pm-btn sm" onclick="pmClose('pmMain')">Create / Upload</a>
                <button type="button" class="pm-btn sm" onclick="document.getElementById('pmPicFileInput').click()">Change Profile Picture</button>
                <a href="analytics.php" class="pm-btn sm" onclick="pmClose('pmMain')">Analytics</a>
            </div>

            <div class="pm-pic-wrap" onclick="document.getElementById('pmPicFileInput').click()" role="button" aria-label="Change profile picture">
                <img src="<?= htmlspecialchars($profilePic) ?>"
                     alt="Profile"
                     id="pmCurrentPicImg"
                     onerror="this.onerror=null;this.src='mee.jpg';">
                <div class="pm-pic-overlay"><i class="ri-camera-line"></i></div>
            </div>

            <input type="file" id="pmPicFileInput" accept="image/*" onchange="pmHandlePicSelect(this)">

            <div id="pmPreviewArea" class="pm-preview-area">
                <span class="pm-preview-label">Preview</span>
                <img id="pmPreviewImg" src="" alt="Preview">
                <div class="pm-preview-actions">
                    <button type="button" class="pm-btn ghost" onclick="pmCancelPic()">Cancel</button>
                    <button type="button" class="pm-btn" onclick="pmSavePic(this)">Save Photo</button>
                </div>
            </div>
        </div>

        <!-- Edit section -->
        <div class="pm-sec-h">Edit Profile</div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditUsername')">
            <div class="pm-edit-icon"><i class="ri-user-line"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Edit Username</div>
                <div class="pm-edit-current" id="pmDispUsername">@<?= $current_username ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditNickname')">
            <div class="pm-edit-icon"><i class="ri-text"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Edit Nickname</div>
                <div class="pm-edit-current" id="pmDispNickname"><?= $current_nickname ?: 'Not set' ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditBio')">
            <div class="pm-edit-icon"><i class="ri-edit-2-line"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Edit Bio</div>
                <div class="pm-edit-current" id="pmDispBio"><?= $current_bio !== '' ? $current_bio : 'Not set' ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditPronouns')">
            <div class="pm-edit-icon"><i class="ri-hearts-line"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Edit Pronouns</div>
                <div class="pm-edit-current" id="pmDispPronouns"><?= $current_pronouns ?: 'Not set' ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditProfileType')">
            <div class="pm-edit-icon"><i class="ri-price-tag-3-line"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Change Professional Mode Type</div>
                <div class="pm-edit-current" id="pmDispProfileType"><?= htmlspecialchars($cur_profile_type) ?: 'Not set' ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditUserType')">
            <div class="pm-edit-icon"><i class="ri-user-settings-line"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Change User Type</div>
                <div class="pm-edit-current" id="pmDispUserType"><?= htmlspecialchars($cur_user_type) ?: 'Not set' ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

        <div class="pm-edit-row" onclick="pmOpenNested('pmEditVibe')">
            <div class="pm-edit-icon"><i class="ri-time-line"></i></div>
            <div class="pm-edit-meta">
                <div class="pm-edit-label">Change Activity Vibe</div>
                <div class="pm-edit-current" id="pmDispVibe"><?= htmlspecialchars($cur_vibe) ?: 'Not set' ?></div>
            </div>
            <i class="ri-arrow-right-s-line pm-edit-chevron"></i>
        </div>

    </div>
 </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: EDIT USERNAME
═══════════════════════════════════════ -->
<div id="pmEditUsername" class="pm-overlay pm-nested" role="dialog" aria-modal="true" aria-label="Edit username">
 <div class="pm-lane">
    <div class="pm-topbar">
        <div class="pm-tb-side">
            <button type="button" class="pm-x" onclick="pmCloseNested('pmEditUsername')" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="pm-topbar-center"><div class="pm-topbar-title">Edit Username</div></div>
    </div>
    <div class="pm-body">
     <div class="pm-nested-body">
        <div class="pm-field-group">
            <label class="pm-field-label" for="pmUsernameInput">Username</label>
            <input type="text" id="pmUsernameInput" class="pm-field-input"
                   value="<?= $current_username ?>" maxlength="30" autocomplete="off"
                   oninput="pmCheckAvail('username', this.value)"<?= $pm_un_locked ? ' readonly' : '' ?>>
            <div class="pm-avail-row">
                <span id="pmUsernameAvailIcon" class="pm-avail-indicator"></span>
                <span id="pmUsernameAvailText" class="pm-avail-text"></span>
            </div>
            <div id="pmUsernameSuggestions" class="pm-suggestions"></div>
        </div>
        <div class="pm-info-note" id="pmUnNote">
            <?php if ($pm_un_locked): ?>
                <strong>You changed your username recently.</strong> You can change it again in <?= (int)$pm_un_days ?> day<?= $pm_un_days === 1 ? '' : 's' ?>.
            <?php else: ?>
                <strong>You can change your username once every 14 days.</strong> Pick something that feels authentic to who you are right now. Your followers stay connected and your past posts remain linked to your account.
            <?php endif; ?>
        </div>
        <button type="button" class="pm-btn full" id="pmUnSave" onclick="pmSaveUsername(this)"<?= $pm_un_locked ? ' disabled' : '' ?>>
            Save Username
        </button>
     </div>
    </div>
 </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: EDIT NICKNAME
═══════════════════════════════════════ -->
<div id="pmEditNickname" class="pm-overlay pm-nested" role="dialog" aria-modal="true" aria-label="Edit nickname">
 <div class="pm-lane">
    <div class="pm-topbar">
        <div class="pm-tb-side">
            <button type="button" class="pm-x" onclick="pmCloseNested('pmEditNickname')" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="pm-topbar-center"><div class="pm-topbar-title">Edit Nickname</div></div>
    </div>
    <div class="pm-body">
     <div class="pm-nested-body">
        <div class="pm-field-group">
            <label class="pm-field-label" for="pmNicknameInput">Nickname</label>
            <input type="text" id="pmNicknameInput" class="pm-field-input"
                   value="<?= $current_nickname ?>" maxlength="40" autocomplete="off"
                   oninput="pmCheckAvail('nickname', this.value)">
            <div class="pm-avail-row">
                <span id="pmNicknameAvailIcon" class="pm-avail-indicator"></span>
                <span id="pmNicknameAvailText" class="pm-avail-text"></span>
            </div>
            <div id="pmNicknameSuggestions" class="pm-suggestions"></div>
        </div>
        <div class="pm-info-note">
            <strong>Dezzme lets you set a display nickname</strong> — the name shown on your profile and across your posts. It can be your real name, a title, or anything that represents how you want to be known. Unlike your username, it does not need to be unique.
        </div>
        <button type="button" class="pm-btn full"
                onclick="pmSaveField('nickname', document.getElementById('pmNicknameInput').value, this, 'pmEditNickname')">
            Save Nickname
        </button>
     </div>
    </div>
 </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: EDIT BIO
═══════════════════════════════════════ -->
<div id="pmEditBio" class="pm-overlay pm-nested" role="dialog" aria-modal="true" aria-label="Edit bio">
 <div class="pm-lane">
    <div class="pm-topbar">
        <div class="pm-tb-side">
            <button type="button" class="pm-x" onclick="pmCloseNested('pmEditBio')" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="pm-topbar-center"><div class="pm-topbar-title">Edit Bio</div></div>
    </div>
    <div class="pm-body">
     <div class="pm-nested-body">
        <div class="pm-field-group">
            <label class="pm-field-label" for="pmBioInput">Bio <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--ui-mute,rgba(255,255,255,.4));">· max 600 chars</span></label>
            <textarea id="pmBioInput" class="pm-field-input" maxlength="600"
                      oninput="document.getElementById('pmBioCount').textContent = this.value.length + '/600'"><?= $current_bio ?></textarea>
            <div class="pm-char-count" id="pmBioCount"><?= mb_strlen((string)$__bio) ?>/600</div>
        </div>
        <div class="pm-info-note">
            <strong>Dezzme gives you space to express yourself</strong> — your bio is the first thing people read on your profile. Be real, be brief, be you. Update it whenever your vibe changes.
        </div>
        <button type="button" class="pm-btn full"
                onclick="pmSaveField('bio', document.getElementById('pmBioInput').value, this, 'pmEditBio')">
            Save Bio
        </button>
     </div>
    </div>
 </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: EDIT PRONOUNS
═══════════════════════════════════════ -->
<div id="pmEditPronouns" class="pm-overlay pm-nested" role="dialog" aria-modal="true" aria-label="Edit pronouns">
 <div class="pm-lane">
    <div class="pm-topbar">
        <div class="pm-tb-side">
            <button type="button" class="pm-x" onclick="pmCloseNested('pmEditPronouns')" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="pm-topbar-center"><div class="pm-topbar-title">Edit Pronouns</div></div>
    </div>
    <div class="pm-body">
     <div class="pm-nested-body">
        <div class="pm-field-group">
            <label class="pm-field-label" for="pmPronounsInput">Pronouns</label>
            <select id="pmPronounsInput" class="pm-field-input">
                <option value="" <?= $pm_pron_sel === '' ? 'selected' : '' ?>>Don't show pronouns</option>
                <?php if ($pm_pron_custom): ?>
                <option value="<?= htmlspecialchars($raw_pronouns) ?>" selected><?= htmlspecialchars($raw_pronouns) ?> (current)</option>
                <?php endif; ?>
                <?php foreach ($pm_pronoun_groups as $grp => $opts): ?>
                <optgroup label="<?= htmlspecialchars($grp) ?>">
                    <?php foreach ($opts as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($pm_pron_sel === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="pm-info-note">
            <strong>Dezzme respects your identity</strong> — your pronouns will appear on your profile alongside your name so others know how to refer to you. This is always optional and entirely your choice.
        </div>
        <button type="button" class="pm-btn full"
                onclick="pmSaveField('pronouns', document.getElementById('pmPronounsInput').value, this, 'pmEditPronouns')">
            Save Pronouns
        </button>
     </div>
    </div>
 </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: CHANGE PROFESSIONAL MODE TYPE
═══════════════════════════════════════ -->
<div id="pmEditProfileType" class="pm-overlay pm-nested" role="dialog" aria-modal="true" aria-label="Change professional mode type">
 <div class="pm-lane">
    <div class="pm-topbar">
        <div class="pm-tb-side">
            <button type="button" class="pm-x" onclick="pmCloseNested('pmEditProfileType')" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="pm-topbar-center"><div class="pm-topbar-title">Professional Mode Type</div></div>
    </div>
    <div class="pm-body">
     <div class="pm-nested-body">
        <input type="text" id="pmPtSearch" class="pm-field-input" placeholder="Search e.g. Photographer, Developer, Chef, School…" autocomplete="off" oninput="pmPtFilter()">
        <div class="pm-tabs" id="pmPtTabs" role="tablist">
            <button type="button" class="pm-tab on" data-grp="people" role="tab"><i class="ri-user-3-line"></i>Individuals</button>
            <button type="button" class="pm-tab" data-grp="org" role="tab"><i class="ri-building-2-line"></i>Organisations &amp; entities</button>
        </div>
        <div class="pm-pt-current">
            <span>Current: <strong id="pmPtCurrent">None</strong></span>
            <button type="button" class="pm-btn ghost sm" id="pmPtRemove" onclick="pmSaveProfileType('')" hidden>Remove</button>
        </div>
        <div id="pmPtList"></div>
        <div class="pm-pt-none" id="pmPtNone">No professional mode type matches your search</div>
        <div class="pm-info-note">
            <strong>Your professional mode type is a short label on your profile</strong> that tells people what you do or what your organisation is, like Photographer, Meme Creator, Student, School or Fashion Brand. You can only have one at a time. Tap a label to set it, or Remove to clear it. Can't find an exact match? Pick the closest one, or Other.
        </div>
     </div>
    </div>
 </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: CHANGE USER TYPE
═══════════════════════════════════════ -->
<div id="pmEditUserType" class="pm-overlay pm-nested" role="dialog" aria-modal="true" aria-label="Change user type">
 <div class="pm-lane">
    <div class="pm-topbar">
        <div class="pm-tb-side">
            <button type="button" class="pm-x" onclick="pmCloseNested('pmEditUserType')" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="pm-topbar-center"><div class="pm-topbar-title">User Type</div></div>
    </div>
    <div class="pm-body">
     <div class="pm-nested-body">
        <div>
            <?php foreach ($pm_user_types as $val => $desc): ?>
            <label class="pm-radio-item">
                <div class="pm-radio-text"><strong><?= $val === '' ? 'None' : htmlspecialchars($val) ?></strong><span><?= htmlspecialchars($desc) ?></span></div>
                <input type="radio" name="pmUserType" value="<?= htmlspecialchars($val) ?>" <?= ($cur_user_type === $val) ? 'checked' : '' ?> onchange="pmSaveUserType(this)">
            </label>
            <?php endforeach; ?>
        </div>
        <div class="pm-info-note">
            <strong>Your user type shows your meme activity style</strong> on your profile. It saves as soon as you pick one, and you can change it or choose None at any time.
        </div>
     </div>
    </div>
 </div>
</div>


<!-- ═══════════════════════════════════════
     NESTED: CHANGE ACTIVITY VIBE
═══════════════════════════════════════ -->
<div id="pmEditVibe" class="pm-overlay pm-nested" role="dialog" aria-modal="true" aria-label="Change activity vibe">
 <div class="pm-lane">
    <div class="pm-topbar">
        <div class="pm-tb-side">
            <button type="button" class="pm-x" onclick="pmCloseNested('pmEditVibe')" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="pm-topbar-center"><div class="pm-topbar-title">Activity Vibe</div></div>
    </div>
    <div class="pm-body">
     <div class="pm-nested-body">
        <div class="pm-field-group">
            <label class="pm-field-label" for="pmVibeSelect">Activity vibe</label>
            <select id="pmVibeSelect" class="pm-field-input">
                <?php
                /* if the saved vibe isn't one of the listed options, still show it as selected */
                if ($cur_vibe !== '' && !array_key_exists($cur_vibe, $pm_vibes)): ?>
                <option value="<?= htmlspecialchars($cur_vibe) ?>" selected><?= htmlspecialchars($cur_vibe) ?> (current)</option>
                <?php endif; ?>
                <?php foreach ($pm_vibes as $val => $lbl): ?>
                <option value="<?= htmlspecialchars($val) ?>" <?= ($cur_vibe === $val) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="pm-info-note">
            <strong>Your activity vibe tells trackers when you're usually around</strong> on Dezzme. Pick the window that matches your routine, Flexible if it changes day to day, or None to show nothing. It's your choice, and Dezzme doesn't track your activity to fill it in.
        </div>
        <button type="button" class="pm-btn full"
                onclick="pmSaveField('activity_vibe', document.getElementById('pmVibeSelect').value, this, 'pmEditVibe')">
            Save Vibe
        </button>
     </div>
    </div>
 </div>
</div>


<div id="pmToast" class="pm-toast"></div>


<!-- ═══════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════ -->
<script>/* lazy start: this block's code loads the first time it is used (or once the page is idle / first touch) */
window.dzLazyJs=window.dzLazyJs||function(id,names,eager){var done=false;function run(){if(done)return;done=true;var t=document.getElementById(id);if(!t)return;var f=t.content.cloneNode(true),ss=[].slice.call(f.querySelectorAll('script'));ss.forEach(function(s){s.parentNode.removeChild(s)});ss.forEach(function(o){var s=document.createElement('script');s.text=o.textContent;document.body.appendChild(s)})}names.forEach(function(n){var s=function(){run();var r=window[n];if(r&&r!==s)return r.apply(this,arguments)};s.__dzStub=1;window[n]=s});window['dzLazyRun_'+id]=run;if(eager){var go=function(){run();['pointerdown','keydown','touchstart'].forEach(function(e){document.removeEventListener(e,go,true)})};['pointerdown','keydown','touchstart'].forEach(function(e){document.addEventListener(e,go,{capture:true,passive:true})});var idle=function(){(window.requestIdleCallback||function(c){setTimeout(c,1200)})(run,{timeout:3000})};if(document.readyState==='complete')idle();else window.addEventListener('load',idle)}};
dzLazyJs('dzLzPic',['pmCancelPic','pmCheckAvail','pmClose','pmCloseNested','pmHandlePicSelect','pmOpenMain','pmOpenNested','pmPtFilter','pmSaveField','pmSavePic','pmSaveProfileType','pmSaveUserType','pmSaveUsername'],true);
</script>
<template id="dzLzPic">
<script>
(function () {


    /* ── Premium skeleton loader (shimmer shown for a moment each time a screen opens) ── */
    function B(w, h, x) { return '<div class="pm-sk" style="width:' + w + ';height:' + h + 'px;' + (x || '') + '"></div>'; }
    function skHTML(kind) {
        var h = '', i;
        if (kind === 'main') {
            h += '<div class="pm-sk-pic"><div class="pm-sk-btns">' + B('clamp(150px,44vw,190px)', 26, 'border-radius:2px;') + B('clamp(150px,44vw,190px)', 26, 'border-radius:2px;') + B('clamp(150px,44vw,190px)', 26, 'border-radius:2px;') + '</div>' +
                 '<div class="pm-sk" style="width:clamp(76px,22vw,96px);height:clamp(76px,22vw,96px);border-radius:50%"></div></div>';
            for (i = 0; i < 8; i++) {
                if (i === 0) h += '<div class="pm-sk-sec">' + B('90px', 8) + '</div>';
                h += '<div class="pm-sk-row">' + B('24px', 24, 'border-radius:6px;') +
                     '<div class="pm-sk-col">' + B(i % 2 ? '38%' : '48%', 10) + B(i % 2 ? '62%' : '74%', 8) + '</div></div>';
            }
        } else if (kind === 'types') {
            h += '<div class="pm-sk-pad">' + B('100%', 30, 'border-radius:5px;') + B('46%', 10);
            var w = [64, 92, 78, 110, 70, 88, 100, 74, 96, 66, 84, 72];
            for (var g = 0; g < 3; g++) {
                h += B(g % 2 ? '30%' : '38%', 8) + '<div class="pm-sk-chips">';
                for (i = 0; i < 9; i++) h += B(w[(i + g * 3) % w.length] + 'px', 25, 'border-radius:2px;');
                h += '</div>';
            }
            h += '</div>';
        } else if (kind === 'radio') {
            h += '<div class="pm-sk-pad">';
            for (i = 0; i < 2; i++) h += '<div class="pm-sk-row" style="padding-left:0;padding-right:0"><div class="pm-sk-col">' + B('34%', 11) + B('70%', 8) + '</div>' + B('18px', 18, 'border-radius:50%;') + '</div>';
            h += B('100%', 64, 'border-radius:8px;') + '</div>';
        } else {
            h += '<div class="pm-sk-pad">' + B('26%', 9) + B('100%', kind === 'bio' ? 108 : 30, 'border-radius:5px;') + B('100%', 70, 'border-radius:8px;') + B('100%', 30, 'border-radius:2px;') + '</div>';
        }
        return h;
    }
    var SK_KIND = { pmEditBio: 'bio', pmEditProfileType: 'types', pmEditUserType: 'radio' };
    function pmSkeleton(el, kind) {
        var body = el.querySelector('.pm-body');
        if (!body) return;
        var sk = body.querySelector('.pm-skel');
        if (!sk) { sk = document.createElement('div'); sk.className = 'pm-skel'; body.insertBefore(sk, body.firstChild); }
        sk.innerHTML = skHTML(kind);
        sk.classList.remove('hide');
        body.classList.add('pm-loading');
        body.scrollTop = 0;
        clearTimeout(sk._t);
        sk._t = setTimeout(function () {
            sk.classList.add('hide');
            body.classList.remove('pm-loading');
            if (kind === 'types') {
                var l = document.getElementById('pmPtList');
                if (l) { window.pmPtOpen && window.pmPtOpen(); }
            }
        }, kind === 'types' ? 700 : 480);
    }

    /* ── Open / Close ── */
    function show(el, kind) {
        el.style.display = 'flex';
        pmSkeleton(el, kind);
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('pm-open'); });
        });
    }

    window.pmOpenMain = function () {
        var el = document.getElementById('pmMain');
        if (!el) return;
        document.body.style.overflow = 'hidden';
        var b = document.getElementById('pmMainBody');
        if (b) b.scrollTop = 0;
        show(el, 'main');
    };

    window.pmClose = function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('pm-open');
        setTimeout(function () {
            el.style.display = 'none';
            document.body.style.overflow = '';
        }, 270);
    };

    window.pmOpenNested = function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        show(el, SK_KIND[id] || 'field');
    };

    window.pmCloseNested = function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('pm-open');
        setTimeout(function () { el.style.display = 'none'; }, 260);
    };

    /* ESC */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var main = document.getElementById('pmMain');
        if (!main || !main.classList.contains('pm-open')) return;
        var nested = document.querySelectorAll('.pm-overlay.pm-nested.pm-open');
        if (nested.length) {
            window.pmCloseNested(nested[nested.length - 1].id);
        } else {
            window.pmClose('pmMain');
        }
    });

    /* Swipe-down-to-close on main (only when scrolled to the top) */
    var mainEl = document.getElementById('pmMain'), swipeY = 0;
    if (mainEl) {
        mainEl.addEventListener('touchstart', function (e) { swipeY = e.touches[0].clientY; }, { passive: true });
        mainEl.addEventListener('touchend', function (e) {
            var body = document.getElementById('pmMainBody');
            var nestedOpen = document.querySelector('.pm-overlay.pm-nested.pm-open');
            if (nestedOpen) return;
            if (body && body.scrollTop > 0) return;
            if (e.changedTouches[0].clientY - swipeY > 80) window.pmClose('pmMain');
        }, { passive: true });
    }

    /* ── Profile pic ── */
    var _picFile = null;

    window.pmHandlePicSelect = function (input) {
        var f = input.files[0];
        if (!f) return;
        if (!f.type.startsWith('image/')) { pmShowToast('Please pick an image file'); return; }
        _picFile = f;
        var r = new FileReader();
        r.onload = function (e) {
            document.getElementById('pmPreviewImg').src = e.target.result;
            document.getElementById('pmPreviewArea').classList.add('visible');
        };
        r.readAsDataURL(f);
    };

    window.pmCancelPic = function () {
        _picFile = null;
        document.getElementById('pmPreviewArea').classList.remove('visible');
        document.getElementById('pmPreviewImg').src = '';
        document.getElementById('pmPicFileInput').value = '';
    };

    window.pmSavePic = function (btn) {
        if (!_picFile) return;
        var orig = btn.textContent;
        btn.textContent = 'Saving…'; btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'update_profile_pic');
        fd.append('profile_pic_file', _picFile);
        fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (d.success && d.url) {
                var m = document.getElementById('pmCurrentPicImg');
                var t = document.getElementById('profilePic');
                if (m) m.src = d.url;
                if (t) t.src = d.url;
                pmCancelPic();
                btn.textContent = orig;
                pmShowToast('Profile pic updated ✓');
            } else {
                btn.textContent = orig;
                pmShowToast(d.msg || 'Could not save — try again');
            }
        })
        .catch(function () {
            btn.disabled = false; btn.textContent = orig;
            pmShowToast('Network error — check connection');
        });
    };

    /* ── Availability check ── */
    var _avT = {};
    window.pmCheckAvail = function (field, val) {
        clearTimeout(_avT[field]);
        var f = field.charAt(0).toUpperCase() + field.slice(1);
        var iconEl = document.getElementById('pm' + f + 'AvailIcon');
        var textEl = document.getElementById('pm' + f + 'AvailText');
        var sugEl  = document.getElementById('pm' + f + 'Suggestions');
        if (!iconEl) return;
        if (val.length < 2) {
            iconEl.textContent = ''; textEl.textContent = '';
            if (sugEl) sugEl.innerHTML = ''; return;
        }
        iconEl.textContent = '⏳'; textEl.textContent = ''; textEl.className = 'pm-avail-text';
        _avT[field] = setTimeout(function () {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'check_availability=1&field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(val)
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (sugEl) sugEl.innerHTML = '';
                if (d.status === 'available') {
                    iconEl.textContent = '✅'; textEl.textContent = 'Available'; textEl.className = 'pm-avail-text ok';
                } else if (d.status === 'taken') {
                    iconEl.textContent = '❌'; textEl.textContent = 'Already taken — try one of these:'; textEl.className = 'pm-avail-text taken';
                    (d.suggestions || []).forEach(function (s) {
                        var c = document.createElement('button');
                        c.type = 'button';
                        c.className = 'pm-suggestion-chip'; c.textContent = s;
                        c.onclick = function () {
                            var inp = document.getElementById('pm' + f + 'Input');
                            if (inp) { inp.value = s; window.pmCheckAvail(field, s); }
                        };
                        if (sugEl) sugEl.appendChild(c);
                    });
                } else if (d.status === 'short') {
                    iconEl.textContent = ''; textEl.textContent = 'Too short'; textEl.className = 'pm-avail-text taken';
                }
            });
        }, 480);
    };

    window.pmSaveUsername = function (btn) {
        var inp = document.getElementById('pmUsernameInput'), val = inp.value.trim();
        if (!/^[A-Za-z0-9_.]{3,30}$/.test(val)) { pmShowToast('Use 3 to 30 letters, numbers, _ or .'); return; }
        if (!confirm('You can only change your username once every 14 days. Change it now?')) return;
        var orig = btn.textContent;
        btn.textContent = 'Saving…'; btn.disabled = true;
        var fd = new FormData();
        fd.append('acct_action', 'username');
        fd.append('username', val);
        fetch('settings.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                btn.textContent = 'Saved ✓';
                inp.value = d.username; inp.readOnly = true;
                var disp = document.getElementById('pmDispUsername'); if (disp) disp.textContent = '@' + d.username;
                var note = document.getElementById('pmUnNote');
                if (note) note.innerHTML = '<strong>You changed your username recently.</strong> You can change it again in ' + d.days + ' days.';
                pmShowToast('Username updated ✓');
                setTimeout(function () { btn.textContent = orig; window.pmCloseNested('pmEditUsername'); }, 900);
            } else {
                btn.textContent = orig; btn.disabled = false;
                pmShowToast(d.msg || 'Could not save username');
            }
        })
        .catch(function () { btn.textContent = orig; btn.disabled = false; pmShowToast('Network error, try again'); });
    };

    /* ── Save field ── */
    window.pmSaveField = function (field, value, btn, modalId) {
        var orig = btn.textContent;
        btn.textContent = 'Saving…'; btn.disabled = true;
        fetch('ajax_update_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value)
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (d.success) {
                btn.textContent = 'Saved ✓';
                var map = { username:'pmDispUsername', nickname:'pmDispNickname', bio:'pmDispBio', pronouns:'pmDispPronouns', activity_vibe:'pmDispVibe' };
                var el = document.getElementById(map[field]);
                if (el) el.textContent = field === 'username' ? '@' + value : (value || 'Not set');
                var nm = field.replace(/_/g, ' '); pmShowToast(nm.charAt(0).toUpperCase() + nm.slice(1) + ' updated ✓');
                setTimeout(function () { btn.textContent = orig; window.pmCloseNested(modalId); }, 900);
            } else {
                btn.textContent = orig;
                pmShowToast('Error: ' + (d.msg || 'Could not save'));
            }
        })
        .catch(function () {
            btn.disabled = false; btn.textContent = orig;
            pmShowToast('Network error — try again');
        });
    };

    /* ── Quick save (profile type / user type) ── */
    function pmQuickSave(field, value, ok, fail) {
        fetch('ajax_update_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value)
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) { ok(); }
            else { pmShowToast('Error: ' + (d.msg || 'Could not save')); if (fail) fail(); }
        })
        .catch(function () { pmShowToast('Network error — try again'); if (fail) fail(); });
    }

    /* ── Profile type picker ── */
    var PM_CATS = <?= json_encode($profile_categories, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var PM_ORG = <?= json_encode($org_categories, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var pmGrp = 'people';
    window.dzHaptic = window.dzHaptic || (function(){
        var enabled = window.DZ_HAPTICS_ON !== false, last = 0, tickEl = null;
        var canVibrate = false; try{ canVibrate = typeof navigator.vibrate === 'function'; }catch(e){}
        var isIOS = false; try{ isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); }catch(e){}
        var P = { light:[16], medium:[30], heavy:[55], success:[18,50,32], error:[45,60,45,60,60], selection:[11], soft:[21], longPress:[40], double:[18,60,18] };
        var IOS_TICKS = { light:1, medium:2, heavy:3, success:3, error:4, selection:1, soft:1, longPress:2, double:2 };
        function reduced(){ try{ return !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches); }catch(e){ return false; } }
        function iosTick(){
            try{
                if(!tickEl){
                    tickEl = document.createElement('label'); tickEl.setAttribute('aria-hidden', 'true');
                    tickEl.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none;overflow:hidden';
                    var i = document.createElement('input'); i.type = 'checkbox'; i.setAttribute('switch', ''); i.tabIndex = -1; tickEl.appendChild(i);
                    document.body.appendChild(tickEl);
                }
                tickEl.click();
            }catch(e){}
        }
        function fire(kind){
            try{
                if(!enabled || reduced()) return;
                var now = Date.now(); if(now - last < 40) return;       /* one buzz per action, never a burst */
                last = now;
                if(canVibrate){ navigator.vibrate(P[kind] || P.light); return; }
                if(isIOS){ var n = IOS_TICKS[kind] || 1; iosTick(); for(var k = 1; k < n; k++) setTimeout(iosTick, k * 55); }
            }catch(e){}
        }
        var api = { enable:function(on){ enabled = !!on; }, isEnabled:function(){ return enabled; }, supported:function(){ return canVibrate || isIOS; } };
        Object.keys(P).forEach(function(k){ api[k] = function(){ fire(k); }; });
        return api;
    })();
    window.DZ_HAPTICS_ON = window.DZ_HAPTICS_ON !== undefined ? window.DZ_HAPTICS_ON : <?= $pm_haptics_on ? 'true' : 'false' ?>;

    var pmCurType = <?= json_encode($cur_profile_type, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    function pmPtRefresh() {
        document.querySelectorAll('#pmPtList .pm-chip').forEach(function (c) {
            c.classList.toggle('selected', c.dataset.value === pmCurType);
        });
        var lab = document.getElementById('pmPtCurrent'), rm = document.getElementById('pmPtRemove'), disp = document.getElementById('pmDispProfileType');
        if (lab) lab.textContent = pmCurType || 'None';
        if (rm) rm.hidden = !pmCurType;
        if (disp) disp.textContent = pmCurType || 'Not set';
    }

    var pmItems = [], pmShown = 0, pmIO = null, pmSent = null, PM_BATCH = 3;
    function pmCollect() {
        var si = document.getElementById('pmPtSearch'), q = si ? si.value.trim().toLowerCase() : '', out = [];
        [['people', PM_CATS], ['org', PM_ORG]].forEach(function (pair) {
            var grp = pair[0], cats = pair[1];
            if (!q && grp !== pmGrp) return;
            Object.keys(cats).forEach(function (cat) {
                var names = cats[cat].filter(function (n) { return !q || (n + ' ' + cat + (grp === 'org' ? ' organisation organization entity company' : '')).toLowerCase().indexOf(q) !== -1; });
                if (names.length) out.push({ cat: cat, names: names });
            });
        });
        return out;
    }
    function pmAddBatch(animate) {
        var list = document.getElementById('pmPtList'); if (!list || !pmSent) return;
        pmItems.slice(pmShown, pmShown + PM_BATCH).forEach(function (it, k) {
            var wrap = document.createElement('div'); wrap.className = 'pm-pt-cat' + (animate ? ' pm-in' : '');
            if (animate) wrap.style.animationDelay = (k * 0.06) + 's';
            var t = document.createElement('div'); t.className = 'pm-pt-cat-title'; t.textContent = it.cat; wrap.appendChild(t);
            var chips = document.createElement('div'); chips.className = 'pm-chips';
            it.names.forEach(function (name) {
                var c = document.createElement('button');
                c.type = 'button'; c.className = 'pm-chip' + (name === pmCurType ? ' selected' : ''); c.dataset.value = name; c.textContent = name;
                c.addEventListener('click', function () { window.pmSaveProfileType(name, c); });
                chips.appendChild(c);
            });
            wrap.appendChild(chips); list.insertBefore(wrap, pmSent);
            setTimeout(function () { window.dzHaptic.medium(); }, k * 70);     /* a soft buzz as each group of buttons loads */
        });
        pmShown = Math.min(pmItems.length, pmShown + PM_BATCH);
        if (pmShown >= pmItems.length) { if (pmIO) pmIO.unobserve(pmSent); pmSent.style.display = 'none'; }
    }
    function pmRender(animate) {
        var list = document.getElementById('pmPtList'); if (!list) return;
        pmItems = pmCollect(); pmShown = 0; list.innerHTML = '';
        pmSent = document.createElement('div'); pmSent.style.cssText = 'height:2px'; list.appendChild(pmSent);
        document.getElementById('pmPtNone').classList.toggle('show', pmItems.length === 0);
        if (pmIO) pmIO.disconnect();
        if ('IntersectionObserver' in window) {
            pmIO = new IntersectionObserver(function (es) { if (es[0].isIntersecting && pmShown < pmItems.length) pmAddBatch(true); }, { rootMargin: '400px 0px' });
            pmIO.observe(pmSent);
        }
        pmAddBatch(!!animate);
        var guard = 0;
        while (pmShown < pmItems.length && pmSent.getBoundingClientRect().top < innerHeight + 200 && guard++ < 4) pmAddBatch(true);
    }
    window.pmPtFilter = function () { pmRender(false); };
    window.pmPtSetGroup = function (g, replay) {
        pmGrp = g;
        Array.prototype.forEach.call(document.querySelectorAll('#pmPtTabs .pm-tab'), function (t) { var on = t.dataset.grp === g; t.classList.toggle('on', on); t.setAttribute('aria-selected', on); });
        var si = document.getElementById('pmPtSearch'); if (si) si.value = '';
        if (replay) { var b = document.querySelector('#pmEditProfileType .pm-body'); if (b) b.scrollTop = 0; }
        pmRender(replay);
    };
    window.pmPtOpen = function () {   /* opens on the group the current type belongs to */
        var inOrg = !!pmCurType && Object.keys(PM_ORG).some(function (k) { return PM_ORG[k].indexOf(pmCurType) > -1; })
                    && !Object.keys(PM_CATS).some(function (k) { return PM_CATS[k].indexOf(pmCurType) > -1; });
        window.pmPtSetGroup(inOrg ? 'org' : 'people', true);
    };
    Array.prototype.forEach.call(document.querySelectorAll('#pmPtTabs .pm-tab'), function (t) {
        t.addEventListener('click', function () {
            if (t.dataset.grp === pmGrp && !document.getElementById('pmPtSearch').value) return;
            window.dzHaptic.selection(); window.pmPtSetGroup(t.dataset.grp, true);
        });
    });
    pmRender(false);
    pmPtRefresh();

    window.pmSaveProfileType = function (value, chip) {
        if (chip) chip.classList.add('busy');
        pmQuickSave('profile_type', value, function () {
            if (chip) chip.classList.remove('busy');
            pmCurType = value; pmPtRefresh();
            pmShowToast(value ? 'Professional mode type set to ' + value : 'Professional mode type removed');
        }, function () { if (chip) chip.classList.remove('busy'); });
    };

    /* ── User type ── */
    window.pmSaveUserType = function (input) {
        pmQuickSave('user_type', input.value, function () {
            var d = document.getElementById('pmDispUserType');
            if (d) d.textContent = input.value || 'Not set';
            pmShowToast('User type updated ✓');
        });
    };

    /* ── Toast ── */
    window.pmShowToast = function (msg) {
        var t = document.getElementById('pmToast');
        if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function () { t.classList.remove('show'); }, 2600);
    };

})();
</script>
</template>

<script>
(function(){
  var m={picture:'',username:'pmEditUsername',nickname:'pmEditNickname',bio:'pmEditBio',pronouns:'pmEditPronouns',profiletype:'pmEditProfileType',usertype:'pmEditUserType',vibe:'pmEditVibe'};
  var q=new URLSearchParams(location.search).get('edit');
  if(!q||!(q in m))return;
  window.addEventListener('load',function(){
    setTimeout(function(){
      pmOpenMain();
      if(m[q])setTimeout(function(){pmOpenNested(m[q]);},350);
    },300);
  });
})();
</script>

<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     REPORT MODAL v4.1 — Report a User / Content / Message
     Red theme · red icons & buttons with BLACK text
     Centered lane · grey side lines · horizontal-line rows
     Scrollbar: completely hidden on phones/tablets/narrow windows,
                slim red scrollbar on desktop only.
     Sub screens: X on the left — goes BACK to the main picker
                  (if the modal was opened straight to the reason
                   screen, e.g. openReportModal({userId}), X closes)
     Open with:  openReportModal({userId: 123})
══════════════════════════════════════════════════════════ -->

<style>
#dzReportOverlay,
#dzRptStep1Screen,
#dzRptStep2Screen {
    --rp-red:#FF2D2D; --rp-red-h:#e01f1f; --rp-red-l:#ff6b6b;
    --rp-red-dim:rgba(255,45,45,.14); --rp-red-glow:rgba(255,45,45,.34);
    --rp-on-red:#000;
    --rp-card:rgba(var(--fg,255,255,255),.05);
    --rp-line:rgba(var(--fg,255,255,255),.09); --rp-line2:rgba(var(--fg,255,255,255),.14);
    --rp-sub:rgba(var(--fg,255,255,255),.62); --rp-mut:rgba(var(--fg,255,255,255),.45);
    --rp-pad:calc(clamp(9px,3vw,16px) * var(--md-s));
    --rp-act-h:calc(clamp(25px,7.2vw,30px) * var(--md-s));
    --rp-r:5px; --rp-fs:calc(.8rem * var(--md-s)); --rp-tb-h:calc(54px * var(--md-s));

    display:none; position:fixed; inset:0;
    background:linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924))); background-attachment:fixed;
    justify-content:center; overflow:hidden;
    font-family:'Poppins',sans-serif; font-size:var(--rp-fs); color:#fff; -webkit-font-smoothing:antialiased;
    opacity:0;
}
#dzReportOverlay { z-index:999999999999; transform:translateY(18px); transition:opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1); }
#dzRptStep1Screen,
#dzRptStep2Screen { z-index:999999999998; transform:translateX(28px); transition:opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1); }

@media (max-width:490px){ #dzReportOverlay,#dzRptStep1Screen,#dzRptStep2Screen{ --rp-fs:calc(.74rem * var(--md-s)); --rp-tb-h:calc(48px * var(--md-s)); } }
@media (max-width:390px){ #dzReportOverlay,#dzRptStep1Screen,#dzRptStep2Screen{ --rp-fs:calc(.7rem * var(--md-s)); --rp-tb-h:calc(44px * var(--md-s)); } }

#dzReportOverlay.dzrpt-open  { opacity:1; transform:translateY(0); }
#dzRptStep1Screen.dzrpt-open,
#dzRptStep2Screen.dzrpt-open { opacity:1; transform:translateX(0); }

#dzReportOverlay *, #dzReportOverlay *::before, #dzReportOverlay *::after,
#dzRptStep1Screen *, #dzRptStep1Screen *::before, #dzRptStep1Screen *::after,
#dzRptStep2Screen *, #dzRptStep2Screen *::before, #dzRptStep2Screen *::after { box-sizing:border-box; }

#dzReportOverlay button, #dzRptStep1Screen button, #dzRptStep2Screen button,
#dzRptStep1Screen textarea, #dzRptStep1Screen input { font-family:'Poppins',sans-serif; outline:none; }
#dzReportOverlay button, #dzRptStep1Screen button, #dzRptStep2Screen button { cursor:pointer; -webkit-tap-highlight-color:transparent; }

/* icons keep the icon font */
#dzReportOverlay [class^="ri-"], #dzReportOverlay [class*=" ri-"],
#dzRptStep1Screen [class^="ri-"], #dzRptStep1Screen [class*=" ri-"],
#dzRptStep2Screen [class^="ri-"], #dzRptStep2Screen [class*=" ri-"],
#dzReportOverlay [class^="ri-"]::before, #dzReportOverlay [class*=" ri-"]::before,
#dzRptStep1Screen [class^="ri-"]::before, #dzRptStep1Screen [class*=" ri-"]::before,
#dzRptStep2Screen [class^="ri-"]::before, #dzRptStep2Screen [class*=" ri-"]::before {
    font-family:'remixicon' !important; font-style:normal; font-weight:normal; line-height:1;
}

/* ── the lane + two grey lines ── */
.dzrpt-lane { width:100%; max-width:580px; height:100%; display:flex; flex-direction:column; min-height:0; }
@media (min-width:801px){ .dzrpt-lane{ border-left:1px solid var(--rp-line); border-right:1px solid var(--rp-line); } }
@media (max-width:800px){ .dzrpt-lane{ max-width:100%; } }

/* ── Top bar ── */
.dzrpt-topbar { flex-shrink:0; display:flex; align-items:center; gap:calc(6px * var(--md-s)); min-height:var(--rp-tb-h); padding:env(safe-area-inset-top) calc(10px * var(--md-s)) 0 var(--rp-pad); background:transparent; }
.dzrpt-topbar-center { flex:1; min-width:0; display:flex; flex-direction:column; text-align:left; }
.dzrpt-topbar-title { font-size:calc(.92rem * var(--md-s)); font-weight:700; color:var(--text,#F0F2FF); line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dzrpt-topbar-sub { font-size:calc(.62rem * var(--md-s)); color:var(--rp-mut); margin-top:2px; }
.dzrpt-tb-side { flex:0 0 38px; display:flex; align-items:center; justify-content:flex-end; }
.dzrpt-close-btn { width:calc(34px * var(--md-s)); height:calc(34px * var(--md-s)); background:none; border:none; color:var(--text,#F0F2FF); display:flex; align-items:center; justify-content:center; opacity:.9; padding:0; transition:.2s; flex-shrink:0; }
.dzrpt-close-btn:hover { color:var(--rp-red-l); opacity:1; }
.dzrpt-close-btn svg { width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); display:block; }

/* sub screens: X on the LEFT, title after it */
.dzrpt-topbar.dzrpt-back-left { padding-left:calc(8px * var(--md-s)); gap:calc(8px * var(--md-s)); }
.dzrpt-tb-spacer { flex:0 0 38px; }

@media (max-width:490px){
    .dzrpt-topbar-title{ font-size:calc(.84rem * var(--md-s)); }
    .dzrpt-close-btn{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); }
}
@media (max-width:390px){ .dzrpt-topbar-title{ font-size:calc(.8rem * var(--md-s)); } }

/* ═══════════════════════════════════════════════════════════
   SCROLL AREA + SCROLLBAR
   • default (phones / tablets / narrow windows): scrollbar does
     not exist at all — no track, no thumb, no gutter
   • desktop (wider than 800px): slim red scrollbar
═══════════════════════════════════════════════════════════ */
.dzrpt-body { flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; padding:0 0 calc(18px * var(--md-s)); }

/* base = fully hidden (still scrolls by touch / wheel) */
#dzReportOverlay, #dzRptStep1Screen, #dzRptStep2Screen,
#dzReportOverlay *, #dzRptStep1Screen *, #dzRptStep2Screen * {
    scrollbar-width:none;
    scrollbar-color:transparent transparent;
    -ms-overflow-style:none;
}
#dzReportOverlay ::-webkit-scrollbar,
#dzRptStep1Screen ::-webkit-scrollbar,
#dzRptStep2Screen ::-webkit-scrollbar { display:none; width:0; height:0; background:transparent; }

/* desktop: red scrollbar visible */
@media (min-width:801px) {
    #dzReportOverlay .dzrpt-body,
    #dzRptStep1Screen .dzrpt-body {
        scrollbar-width:thin;
        scrollbar-color:#FF2D2D transparent;
    }
    #dzReportOverlay .dzrpt-body::-webkit-scrollbar,
    #dzRptStep1Screen .dzrpt-body::-webkit-scrollbar { display:block; width:calc(6px * var(--md-s)); }
    #dzReportOverlay .dzrpt-body::-webkit-scrollbar-track,
    #dzRptStep1Screen .dzrpt-body::-webkit-scrollbar-track { background:transparent; }
    #dzReportOverlay .dzrpt-body::-webkit-scrollbar-thumb,
    #dzRptStep1Screen .dzrpt-body::-webkit-scrollbar-thumb { background:#FF2D2D; border-radius:calc(99px * var(--md-s)); }
    #dzReportOverlay .dzrpt-body::-webkit-scrollbar-thumb:hover,
    #dzRptStep1Screen .dzrpt-body::-webkit-scrollbar-thumb:hover { background:#e01f1f; }
}

.dzrpt-section-label {
    display:flex; align-items:center; gap:calc(6px * var(--md-s));
    padding:calc(12px * var(--md-s)) var(--rp-pad) calc(10px * var(--md-s));
    font-size:calc(.64rem * var(--md-s)); font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--rp-sub);
    border-top:1px solid var(--rp-line); border-bottom:1px solid var(--rp-line);
}

/* ── Category rows ── */
.dzrpt-cat-row {
    display:flex; align-items:center; gap:calc(12px * var(--md-s));
    margin:0; padding:calc(13px * var(--md-s)) var(--rp-pad);
    background:transparent; border:none; border-bottom:1px solid var(--rp-line);
    cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent;
}
.dzrpt-cat-row:hover { background:var(--rp-red-dim); }
.dzrpt-cat-row:active { background:rgba(255,45,45,.24); }
.dzrpt-cat-icon { width:calc(24px * var(--md-s)); flex-shrink:0; text-align:center; font-size:calc(20px * var(--md-s)); color:var(--rp-red); }
.dzrpt-cat-info { flex:1; min-width:0; }
.dzrpt-cat-label { font-size:calc(.82rem * var(--md-s)); font-weight:700; color:var(--text,#F0F2FF); }
.dzrpt-cat-sub { font-size:calc(.66rem * var(--md-s)); color:var(--rp-mut); margin-top:2px; line-height:1.45; }
.dzrpt-cat-chevron { font-size:calc(18px * var(--md-s)); color:var(--rp-mut); transition:transform .2s, color .2s; flex-shrink:0; }
.dzrpt-cat-row:hover .dzrpt-cat-chevron { transform:translateX(3px); color:var(--rp-red-l); }
@media (max-width:490px){
    .dzrpt-cat-row{ gap:calc(10px * var(--md-s)); padding:calc(12px * var(--md-s)) var(--rp-pad); }
    .dzrpt-cat-icon{ font-size:calc(18px * var(--md-s)); }
    .dzrpt-cat-label{ font-size:calc(.74rem * var(--md-s)); }
    .dzrpt-cat-sub{ font-size:calc(.62rem * var(--md-s)); }
}

/* ── User ID field ── */
.dzrpt-userid-wrap { padding:calc(12px * var(--md-s)) var(--rp-pad) calc(14px * var(--md-s)); }
.dzrpt-userid-label {
    display:flex; align-items:center; gap:calc(6px * var(--md-s)); margin-bottom:calc(8px * var(--md-s));
    font-size:calc(.64rem * var(--md-s)); font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--rp-sub);
}
.dzrpt-userid-label i { font-size:calc(14px * var(--md-s)); color:var(--rp-red); }
.dzrpt-userid-label span { font-weight:400; text-transform:none; letter-spacing:0; color:var(--rp-mut); }
.dzrpt-userid-input {
    width:100%; height:calc(30px * var(--md-s)); padding:0 calc(10px * var(--md-s));
    border-radius:var(--rp-r); border:1px solid var(--rp-line2);
    background:var(--rp-card); color:var(--text,#F0F2FF);
    font-size:calc(.76rem * var(--md-s)); transition:.2s; -webkit-appearance:none; appearance:none;
}
.dzrpt-userid-input::placeholder { color:var(--rp-mut); }
.dzrpt-userid-input:focus { border-color:rgba(255,45,45,.85); box-shadow:0 0 0 3px rgba(255,45,45,.18); }
.dzrpt-userid-hint { font-size:calc(.62rem * var(--md-s)); color:var(--rp-mut); margin-top:calc(6px * var(--md-s)); line-height:1.5; }
@media (max-width:490px){ .dzrpt-userid-input{ font-size:calc(.7rem * var(--md-s)); height:calc(28px * var(--md-s)); } }

/* ── Reason rows ── */
.dzrpt-reason-row {
    display:flex; align-items:center; gap:calc(12px * var(--md-s));
    margin:0; padding:calc(13px * var(--md-s)) var(--rp-pad);
    background:transparent; border:none; border-bottom:1px solid var(--rp-line);
    cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent;
}
.dzrpt-reason-row:hover { background:rgba(255,45,45,.08); }
.dzrpt-reason-row.selected { background:var(--rp-red-dim); }
.dzrpt-reason-icon { font-size:calc(18px * var(--md-s)); color:var(--rp-red); flex-shrink:0; width:calc(24px * var(--md-s)); text-align:center; }
.dzrpt-reason-text { flex:1; min-width:0; font-size:calc(.8rem * var(--md-s)); font-weight:500; color:rgba(var(--fg,255,255,255),.88); }
.dzrpt-reason-row.selected .dzrpt-reason-text { color:var(--text,#F0F2FF); font-weight:600; }
.dzrpt-check { width:calc(20px * var(--md-s)); height:calc(20px * var(--md-s)); border-radius:calc(5px * var(--md-s)); border:1.5px solid rgba(var(--fg,255,255,255),.25); display:flex; align-items:center; justify-content:center; font-size:calc(12px * var(--md-s)); font-weight:800; color:transparent; flex-shrink:0; transition:.2s; }
.dzrpt-reason-row.selected .dzrpt-check { background:var(--rp-red); border-color:var(--rp-red); color:var(--rp-on-red); }
@media (max-width:490px){
    .dzrpt-reason-row{ gap:calc(10px * var(--md-s)); padding:calc(12px * var(--md-s)) var(--rp-pad); }
    .dzrpt-reason-icon{ font-size:calc(16px * var(--md-s)); width:calc(22px * var(--md-s)); }
    .dzrpt-reason-text{ font-size:calc(.72rem * var(--md-s)); }
    .dzrpt-check{ width:calc(18px * var(--md-s)); height:calc(18px * var(--md-s)); font-size:calc(11px * var(--md-s)); }
}

/* ── Other textarea ── */
.dzrpt-other-wrap { padding:calc(12px * var(--md-s)) var(--rp-pad) calc(6px * var(--md-s)); display:none; }
.dzrpt-other-textarea {
    width:100%; min-height:calc(96px * var(--md-s)); padding:calc(9px * var(--md-s)) calc(11px * var(--md-s));
    border-radius:var(--rp-r); border:1px solid var(--rp-line2);
    background:var(--rp-card); color:var(--text,#F0F2FF);
    font-size:calc(.82rem * var(--md-s)); line-height:1.6; resize:none; transition:.2s;
    -webkit-appearance:none; appearance:none; overflow-y:auto;
}
.dzrpt-other-textarea::placeholder { color:var(--rp-mut); }
.dzrpt-other-textarea:focus { border-color:rgba(255,45,45,.85); box-shadow:0 0 0 3px rgba(255,45,45,.18); }
.dzrpt-char-count { text-align:right; font-size:calc(.62rem * var(--md-s)); color:var(--rp-mut); margin-top:calc(5px * var(--md-s)); }
@media (max-width:490px){ .dzrpt-other-textarea{ font-size:calc(.76rem * var(--md-s)); } }

/* ── Buttons (red, BLACK text) ── */
.dzrpt-footer {
    flex-shrink:0; display:flex; gap:calc(8px * var(--md-s));
    padding:calc(8px * var(--md-s)) var(--rp-pad) calc(calc(10px * var(--md-s)) + env(safe-area-inset-bottom));
    border-top:1px solid var(--rp-line);
}
.dzrpt-submit-btn, .dzrpt-cancel-btn, .dzrpt-done-btn {
    display:flex; align-items:center; justify-content:center; gap:calc(6px * var(--md-s));
    min-width:0; height:var(--rp-act-h); padding:0 calc(14px * var(--md-s));
    border:none; border-radius:var(--rp-r);
    font-size:calc(.79rem * var(--md-s)); font-weight:700; line-height:1; white-space:nowrap;
    transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.dzrpt-submit-btn { flex:1.4; order:2; background:var(--rp-red); color:var(--rp-on-red); }
.dzrpt-submit-btn:hover, .dzrpt-done-btn:hover { background:var(--rp-red-h); box-shadow:0 4px 16px var(--rp-red-glow); }
.dzrpt-submit-btn:active, .dzrpt-done-btn:active { transform:scale(.98); }
.dzrpt-submit-btn:disabled { opacity:.5; cursor:not-allowed; box-shadow:none; pointer-events:none; }
.dzrpt-cancel-btn { flex:1; order:1; background:rgba(var(--fg,255,255,255),.1); color:var(--text,#F0F2FF); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
.dzrpt-cancel-btn:hover { background:rgba(var(--fg,255,255,255),.2); }
.dzrpt-done-btn { background:var(--rp-red); color:var(--rp-on-red); padding:0 calc(40px * var(--md-s)); }
@media (max-width:490px){
    .dzrpt-submit-btn, .dzrpt-cancel-btn, .dzrpt-done-btn{ font-size:calc(.68rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); }
    .dzrpt-done-btn{ padding:0 calc(32px * var(--md-s)); }
}

/* ── Success ── */
.dzrpt-success-body { flex:1; min-height:0; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:calc(30px * var(--md-s)) calc(24px * var(--md-s)) calc(60px * var(--md-s)); text-align:center; }
.dzrpt-success-icon { width:calc(64px * var(--md-s)); height:calc(64px * var(--md-s)); border-radius:50%; background:rgba(77,222,138,.12); border:1px solid rgba(77,222,138,.4); display:flex; align-items:center; justify-content:center; margin:0 auto calc(16px * var(--md-s)); font-size:calc(28px * var(--md-s)); color:#4dde8a; }
.dzrpt-success-title { font-size:calc(.95rem * var(--md-s)); font-weight:700; color:var(--text,#F0F2FF); margin-bottom:calc(8px * var(--md-s)); }
.dzrpt-success-desc { font-size:calc(.74rem * var(--md-s)); color:var(--rp-sub); line-height:1.7; max-width:290px; margin:0 auto calc(22px * var(--md-s)); }
@media (max-width:490px){
    .dzrpt-success-icon{ width:calc(56px * var(--md-s)); height:calc(56px * var(--md-s)); font-size:calc(25px * var(--md-s)); }
    .dzrpt-success-title{ font-size:calc(.86rem * var(--md-s)); }
    .dzrpt-success-desc{ font-size:calc(.68rem * var(--md-s)); }
}

/* ── Toast ── */
.dzrpt-toast {
    position:fixed; bottom:calc(calc(30px * var(--md-s)) + env(safe-area-inset-bottom)); left:50%;
    transform:translateX(-50%) translateY(10px);
    background:var(--surface-2,#0d1030); border:1px solid rgba(255,45,45,.4);
    color:#fff; padding:calc(8px * var(--md-s)) calc(16px * var(--md-s)); border-radius:calc(5px * var(--md-s));
    font-size:calc(.72rem * var(--md-s)); font-family:'Poppins',sans-serif; font-weight:500;
    z-index:9999999999999; pointer-events:none;
    box-shadow:0 8px 28px rgba(0,0,0,.65);
    white-space:nowrap; max-width:92vw; overflow:hidden; text-overflow:ellipsis; opacity:0;
    transition:opacity .22s ease, transform .22s ease;
}
.dzrpt-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }

@keyframes dzRptShakeAnim {
    0%,100%{transform:translateX(0);}
    20%{transform:translateX(-6px);}
    40%{transform:translateX(6px);}
    60%{transform:translateX(-4px);}
    80%{transform:translateX(4px);}
}
@media (prefers-reduced-motion:reduce){
    #dzReportOverlay, #dzRptStep1Screen, #dzRptStep2Screen { transition-duration:.01ms !important; }
}
</style>


<!-- ══ SCREEN 0: Category picker ══ -->
<div id="dzReportOverlay" role="dialog" aria-modal="true" aria-label="Report">
  <div class="dzrpt-lane">
    <div class="dzrpt-topbar">
        <div class="dzrpt-topbar-center">
            <div class="dzrpt-topbar-title">Report</div>
            <div class="dzrpt-topbar-sub">Reports are private and anonymous</div>
        </div>
        <div class="dzrpt-tb-side">
            <button type="button" class="dzrpt-close-btn" onclick="closeReportModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
    </div>

    <div class="dzrpt-body">
        <div class="dzrpt-section-label">Select what you want to report</div>

        <div class="dzrpt-cat-row" onclick="dzRptSelectCat('user')">
            <div class="dzrpt-cat-icon"><i class="ri-user-forbid-line"></i></div>
            <div class="dzrpt-cat-info">
                <div class="dzrpt-cat-label">Reporting a User</div>
                <div class="dzrpt-cat-sub">Fake accounts, spam, harassment &amp; more</div>
            </div>
            <i class="ri-arrow-right-s-line dzrpt-cat-chevron"></i>
        </div>

        <div class="dzrpt-cat-row" onclick="dzRptSelectCat('content')">
            <div class="dzrpt-cat-icon"><i class="ri-image-2-line"></i></div>
            <div class="dzrpt-cat-info">
                <div class="dzrpt-cat-label">Reporting Content</div>
                <div class="dzrpt-cat-sub">Memes, posts or comments that break the rules</div>
            </div>
            <i class="ri-arrow-right-s-line dzrpt-cat-chevron"></i>
        </div>

        <div class="dzrpt-cat-row" onclick="dzRptSelectCat('message')">
            <div class="dzrpt-cat-icon"><i class="ri-message-2-line"></i></div>
            <div class="dzrpt-cat-info">
                <div class="dzrpt-cat-label">Reporting a Message</div>
                <div class="dzrpt-cat-sub">Something they sent you in DMs</div>
            </div>
            <i class="ri-arrow-right-s-line dzrpt-cat-chevron"></i>
        </div>
    </div>
  </div>
</div>


<!-- ══ SCREEN 1: Reason picker — X on the left, goes BACK to the picker ══ -->
<div id="dzRptStep1Screen" role="dialog" aria-modal="true" aria-label="Select a reason">
  <div class="dzrpt-lane">
    <div class="dzrpt-topbar dzrpt-back-left">
        <button type="button" class="dzrpt-close-btn" onclick="dzRptBack()" aria-label="Back">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="dzrpt-topbar-center">
            <div class="dzrpt-topbar-title" id="dzRptTitle">Select a reason</div>
        </div>
    </div>

    <div class="dzrpt-body" id="dzRptScroll">
        <!-- User ID — only shown for user reports -->
        <!-- Post ID — optional, only for post / content reports -->
        <div class="dzrpt-userid-wrap" id="dzRptPostIdWrap" style="display:none;">
            <div class="dzrpt-userid-label">
                <i class="ri-fingerprint-line"></i>
                Post ID <span>&nbsp;— optional</span>
            </div>
            <input class="dzrpt-userid-input" type="text" id="dzRptPostIdInput" maxlength="24" autocomplete="off" autocapitalize="characters"
                   placeholder="e.g. TP-1A2B3C4D" style="text-transform:uppercase;">
            <div class="dzrpt-userid-hint">Find this at the bottom of the post's ⋯ menu</div>
        </div>
        <div class="dzrpt-userid-wrap" id="dzRptUserIdWrap" style="display:none;">
            <div class="dzrpt-userid-label">
                <i class="ri-fingerprint-line"></i>
                User ID <span>&nbsp;— optional</span>
            </div>
            <input class="dzrpt-userid-input" type="text" id="dzRptUserIdInput"
                   placeholder="e.g. DZ-10E34AFAD or user #123">
            <div class="dzrpt-userid-hint">Find this on the user's profile page</div>
        </div>

        <div class="dzrpt-section-label" id="dzRptReasonLabel">Select a reason</div>
        <div id="dzRptReasonList"></div>

        <div class="dzrpt-other-wrap" id="dzRptOtherWrap">
            <textarea class="dzrpt-other-textarea" id="dzRptOtherInput"
                      placeholder="Please describe the issue in detail…"
                      maxlength="500"
                      oninput="dzRptCharCount(this)"></textarea>
            <div class="dzrpt-char-count" id="dzRptCharLabel">0 / 500</div>
        </div>
    </div>

    <div class="dzrpt-footer">
        <button type="button" class="dzrpt-cancel-btn" onclick="closeReportModal()">Cancel</button>
        <button type="button" class="dzrpt-submit-btn" id="dzRptSubmitBtn" onclick="dzRptSubmit()">Submit Report</button>
    </div>
  </div>
</div>


<!-- ══ SCREEN 2: Success — no X ══ -->
<div id="dzRptStep2Screen" role="dialog" aria-modal="true" aria-label="Report submitted">
  <div class="dzrpt-lane">
    <div class="dzrpt-topbar">
        <div class="dzrpt-topbar-center">
            <div class="dzrpt-topbar-title">Report Submitted</div>
        </div>
    </div>

    <div class="dzrpt-success-body">
        <div class="dzrpt-success-icon"><i class="ri-shield-check-line"></i></div>
        <div class="dzrpt-success-title">Report Submitted</div>
        <div class="dzrpt-success-desc">
            Thank you. Your report has been submitted for review.
            The reported account will not know who reported them.
        </div>
        <button type="button" class="dzrpt-done-btn" onclick="closeReportModal()">Done</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="dzRptToastEl" class="dzrpt-toast"></div>


<script>/* lazy start: this block's code loads the first time it is used */
window.dzLazyJs=window.dzLazyJs||function(id,names,eager){var done=false;function run(){if(done)return;done=true;var t=document.getElementById(id);if(!t)return;var f=t.content.cloneNode(true),ss=[].slice.call(f.querySelectorAll('script'));ss.forEach(function(s){s.parentNode.removeChild(s)});ss.forEach(function(o){var s=document.createElement('script');s.text=o.textContent;document.body.appendChild(s)})}names.forEach(function(n){var s=function(){run();var r=window[n];if(r&&r!==s)return r.apply(this,arguments)};s.__dzStub=1;window[n]=s});window['dzLazyRun_'+id]=run;if(eager){var go=function(){run();['pointerdown','keydown','touchstart'].forEach(function(e){document.removeEventListener(e,go,true)})};['pointerdown','keydown','touchstart'].forEach(function(e){document.addEventListener(e,go,{capture:true,passive:true})});var idle=function(){(window.requestIdleCallback||function(c){setTimeout(c,1200)})(run,{timeout:3000})};if(document.readyState==='complete')idle();else window.addEventListener('load',idle)}};
dzLazyJs('dzLzRpt',['closeReportModal','dzRptBack','dzRptCharCount','dzRptSelectCat','dzRptSubmit','openReportModal','reportUser'],false);
</script>
<template id="dzLzRpt">
<script>
(function () {
    var _rState = {
        step: 0, category: null,
        targetId: null, contentType: null,
        ownerId: null, reason: null,
        direct: false   /* true when opened straight to the reason screen (no picker behind it) */
    };

    /* ── All reason lists — every item has an icon ── */
    var _userReasons = [
        { icon:'ri-ghost-line',           text:'Fake Account' },
        { icon:'ri-spy-line',             text:'Impersonation' },
        { icon:'ri-spam-2-line',          text:'Spam Activity' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment or Bullying' },
        { icon:'ri-prohibited-line',      text:'Hate Speech' },
        { icon:'ri-suitcase-line',        text:'Scam or Fraud' },
        { icon:'ri-skull-line',           text:'Dangerous Behavior' },
        { icon:'ri-user-unfollow-line',   text:'Underage User' },
        { icon:'ri-eye-off-line',         text:'Privacy Violations' },
        { icon:'ri-scales-3-line',        text:'Illegal Activity' },
        { icon:'ri-alert-line',           text:'Posting Harmful Content' },
        { icon:'ri-robot-line',           text:'Suspicious or Bot Activity' },
        { icon:'ri-repeat-line',          text:'Repeated Rule Violations' },
        { icon:'ri-team-line',            text:'Coordinated Abuse' },
        { icon:'ri-question-line',        text:'Other' }
    ];
    var _contentReasons = [
        { icon:'ri-knife-blood-line',     text:'Graphic Violence or Gore' },
        { icon:'ri-eye-2-line',           text:'Nudity or Sexual Content' },
        { icon:'ri-newspaper-line',       text:'Misinformation or Fake News' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment or Cyberbullying' },
        { icon:'ri-prohibited-line',      text:'Hate Speech or Discrimination' },
        { icon:'ri-spam-2-line',          text:'Spam or Clickbait' },
        { icon:'ri-copyright-line',       text:'Copyright Infringement' },
        { icon:'ri-alert-line',           text:'Illegal or Dangerous Content' },
        { icon:'ri-heart-pulse-line',     text:'Self-Harm or Suicide Content' },
        { icon:'ri-shield-user-line',     text:'Child Safety Concern' },
        { icon:'ri-suitcase-line',        text:'Scam or Deceptive Content' },
        { icon:'ri-emotion-sad-line',     text:'Disturbing or Upsetting Content' },
        { icon:'ri-question-line',        text:'Other' }
    ];
    var _messageReasons = [
        { icon:'ri-sword-line',           text:'Threatening or Violent Language' },
        { icon:'ri-psychotherapy-line',   text:'Sexual Harassment' },
        { icon:'ri-eye-2-line',           text:'Unwanted Sexual Content' },
        { icon:'ri-links-line',           text:'Scam or Phishing Link' },
        { icon:'ri-spam-2-line',          text:'Spam Messages' },
        { icon:'ri-prohibited-line',      text:'Hate Speech' },
        { icon:'ri-eye-off-line',         text:'Sharing Private Information' },
        { icon:'ri-spy-line',             text:'Impersonating Someone' },
        { icon:'ri-team-line',            text:'Coordinated Harassment' },
        { icon:'ri-scales-3-line',        text:'Illegal Content' },
        { icon:'ri-lock-password-line',   text:'Blackmail or Extortion' },
        { icon:'ri-question-line',        text:'Other' }
    ];

    /* ── helpers ── */
    function _openScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('dzrpt-open'); });
        });
    }
    function _closeScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('dzrpt-open');
        setTimeout(function () {
            if (!el.classList.contains('dzrpt-open')) el.style.display = 'none';
        }, 270);
    }
    function _closeAll() {
        ['dzReportOverlay','dzRptStep1Screen','dzRptStep2Screen'].forEach(_closeScreen);
        document.body.style.overflow = '';
        setTimeout(function () {
            _rState = { step:0, category:null, targetId:null, contentType:null, ownerId:null, reason:null, direct:false };
            var ta = document.getElementById('dzRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('dzRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
            var uid = document.getElementById('dzRptUserIdInput');
            if (uid) uid.value = '';
            var pid = document.getElementById('dzRptPostIdInput');
            if (pid) pid.value = '';
        }, 280);
    }

    /* ── public API ── */
    window.openReportModal = function (opts) {
        opts = opts || {};
        _rState.targetId    = opts.userId || opts.contentId || opts.messageId || null;
        _rState.contentType = opts.contentType || null;
        _rState.ownerId     = opts.ownerId || null;

        if (opts.userId && !opts.skipCat) {
            _rState.category = 'user';
            _rState.targetId = opts.userId;
            _rState.direct   = true;   /* no picker behind this screen */
            _buildReasonScreen('user');
            _openScreen('dzRptStep1Screen');
        } else {
            _rState.direct = false;
            _openScreen('dzReportOverlay');
        }
    };

    window.reportUser = function (profileId) {
        openReportModal({ userId: profileId });
    };

    window.closeReportModal = function () { _closeAll(); };

    window.dzRptSelectCat = function (cat) {
        _rState.category = cat;
        _buildReasonScreen(cat);
        _closeScreen('dzReportOverlay');
        setTimeout(function () { _openScreen('dzRptStep1Screen'); }, 80);
    };

    /* X on the reason screen: go back to the main picker.
       If the modal was opened straight to this screen, there is no
       picker to go back to, so it closes instead. */
    window.dzRptBack = function () {
        if (_rState.direct) { _closeAll(); return; }
        _rState.reason = null;
        _closeScreen('dzRptStep1Screen');
        setTimeout(function () { _openScreen('dzReportOverlay'); }, 80);
    };

    function _buildReasonScreen(cat) {
        var reasons = cat === 'user' ? _userReasons
                    : cat === 'content' ? _contentReasons
                    : _messageReasons;
        var titles = {
            user:    'Why are you reporting this user?',
            content: 'Why are you reporting this content?',
            message: 'Why are you reporting this message?'
        };
        document.getElementById('dzRptTitle').textContent    = titles[cat] || 'Select a reason';

        var uidWrap = document.getElementById('dzRptUserIdWrap');
        if (uidWrap) uidWrap.style.display = cat === 'user' ? 'block' : 'none';
        var pidWrap = document.getElementById('dzRptPostIdWrap');
        if (pidWrap) pidWrap.style.display = cat === 'content' ? 'block' : 'none';

        _rState.reason = null;
        document.getElementById('dzRptOtherWrap').style.display = 'none';

        var ta0 = document.getElementById('dzRptOtherInput');
        if (ta0) ta0.value = '';
        var cl0 = document.getElementById('dzRptCharLabel');
        if (cl0) cl0.textContent = '0 / 500';

        var list = document.getElementById('dzRptReasonList');
        list.innerHTML = '';
        reasons.forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'dzrpt-reason-row';
            row.dataset.reason = r.text;
            row.innerHTML =
                '<i class="' + r.icon + ' dzrpt-reason-icon" aria-hidden="true"></i>' +
                '<span class="dzrpt-reason-text">' + _esc(r.text) + '</span>' +
                '<div class="dzrpt-check"></div>';
            row.addEventListener('click', function () { _selectReason(r.text); });
            list.appendChild(row);
        });

        var sc = document.getElementById('dzRptScroll');
        if (sc) sc.scrollTop = 0;
    }

    function _selectReason(reason) {
        _rState.reason = reason;
        document.querySelectorAll('#dzRptReasonList .dzrpt-reason-row').forEach(function (row) {
            var sel = row.dataset.reason === reason;
            row.classList.toggle('selected', sel);
            row.querySelector('.dzrpt-check').textContent = sel ? '✓' : '';
        });
        var ow = document.getElementById('dzRptOtherWrap');
        ow.style.display = reason === 'Other' ? 'block' : 'none';
        if (reason === 'Other') {
            var sc = document.getElementById('dzRptScroll');
            setTimeout(function () { if (sc) sc.scrollTo({ top: sc.scrollHeight, behavior: 'smooth' }); }, 60);
        } else {
            var ta = document.getElementById('dzRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('dzRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
        }
    }

    window.dzRptCharCount = function (el) {
        var cl = document.getElementById('dzRptCharLabel');
        if (cl) cl.textContent = el.value.length + ' / 500';
        el.style.borderColor = '';
    };

    window.dzRptSubmit = function () {
        if (!_rState.reason) {
            var list = document.getElementById('dzRptReasonList');
            list.style.animation = 'none'; void list.offsetWidth;
            list.style.animation = 'dzRptShakeAnim 0.35s ease';
            setTimeout(function () { list.style.animation = ''; }, 400);
            _showToast('Please select a reason first');
            return;
        }
        if (_rState.reason === 'Other') {
            var ta = document.getElementById('dzRptOtherInput');
            if (!ta.value.trim()) {
                ta.style.borderColor = 'rgba(255,45,45,0.85)';
                ta.focus();
                _showToast('Please describe the issue');
                return;
            }
        }

        var btn = document.getElementById('dzRptSubmitBtn');
        btn.disabled = true; btn.textContent = 'Submitting…';

        var cat = _rState.category;
        var fd  = new FormData();
        if (cat === 'user') {
            fd.append('type', 'user');
            fd.append('reported_id', _rState.targetId);
            var uid = (document.getElementById('dzRptUserIdInput').value || '').trim();
            if (uid) fd.append('user_id_hint', uid);
        } else if (cat === 'content') {
            fd.append('type', 'content');
            fd.append('reported_id', _rState.targetId);
            fd.append('content_type', _rState.contentType || 'meme');
        } else {
            fd.append('type', 'content');
            fd.append('reported_id', _rState.targetId);
            fd.append('content_type', 'message');
        }
        fd.append('reason', _rState.reason);
        var desc = (document.getElementById('dzRptOtherInput').value || '').trim();
        if (cat === 'content'){
            var postHint = ((document.getElementById('dzRptPostIdInput') || {}).value || '').trim().toUpperCase();
            if (postHint){
                fd.append('post_id_hint', postHint);
                /* also written into the description so admins see it even where the report script doesn't read post_id_hint */
                desc = ('Post ID: ' + postHint + (desc ? ' — ' + desc : '')).slice(0, 500);
            }
        }
        if (desc) fd.append('description', desc);

        fetch('report_user.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false; btn.textContent = 'Submit Report';
                if (d.success || d.msg === 'already_reported') {
                    _closeScreen('dzRptStep1Screen');
                    setTimeout(function () { _openScreen('dzRptStep2Screen'); }, 80);
                } else {
                    _showToast(d.msg || 'Something went wrong. Try again.');
                }
            })
            .catch(function () {
                btn.disabled = false; btn.textContent = 'Submit Report';
                _showToast('Network error — check your connection');
            });
    };

    function _showToast(msg) {
        var t = document.getElementById('dzRptToastEl');
        if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    function _esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    /* ESC key — reason screen goes back, others close */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var s2 = document.getElementById('dzRptStep2Screen');
        var s1 = document.getElementById('dzRptStep1Screen');
        var s0 = document.getElementById('dzReportOverlay');
        if (s2 && s2.classList.contains('dzrpt-open')) { _closeAll(); }
        else if (s1 && s1.classList.contains('dzrpt-open')) { window.dzRptBack(); }
        else if (s0 && s0.classList.contains('dzrpt-open')) { _closeAll(); }
    });

})();
</script>
</template>

<?php /* ═══════════════════════════════════════════════════════════════════
   PROFILE BOTTOM SHEETS
   Every bottom-sheet / popup modal of profile.php lives in this section
   (moved here from profile.php). Their CSS stays in profile.php and their
   open / close scripts stay next to the feature they belong to — only the
   markup is here, so it renders after everything else on the page.
   ═══════════════════════════════════════════════════════════════════ */ ?>

<!-- ──────── Tracking: Mute / Restrict / Untrack sheet (#mrSheet) — shown to visitors, not the owner ──────── -->
<?php if (!($current_user == $profile_id)): ?>
    <!-- ═══════════════════════════════════════════════
         TRACKING MODAL (Mute / Restrict / Untrack)
         Opened by the "Untrack ⌄" pill above.
         Same structure as Vibe Check / Accounts / Links modals:
         .dzm-sheet › .dzm-handle + .dzm-card › .dzm-scroll › .dzm-header,
         then the .dzm-close-btn underneath.
    ═══════════════════════════════════════════════ -->
    <div id="mrSheet" onclick="if(event.target===this)closeMrSheet()">
        <div class="dzm-sheet">
            <div class="dzm-handle"><span></span></div>

            <div class="dzm-card">
                <div class="dzm-scroll">
                    <div class="dzm-header">Tracking</div>

                    <div class="mr-user-row">
                        <img class="mr-user-pic"
                             src="<?= htmlspecialchars(!empty($user['profile_pic']) ? $user['profile_pic'] : 'mee.jpg') ?>"
                             onerror="this.onerror=null;this.src='mee.jpg'"
                             alt="<?= htmlspecialchars($user['username'] ?? '') ?>">
                        <span class="mr-username-text"><?= htmlspecialchars($user['username'] ?? '') ?></span>
                    </div>

                    <div class="mr-anniv" id="fvxAnniv"></div>
                    <div class="mr-row" id="fvxSinceRow" onclick="if(window.dzFvxSince)dzFvxSince()">
                        <div class="mr-icon"><i class="ri-heart-3-line"></i></div>
                        <div class="dz-fx1"><div class="mr-label">Tracking since</div><div class="mr-sub" id="fvxSinceSub">&nbsp;</div></div>
                    </div>
                    <div class="mr-row" id="fvxTrRow" onclick="if(window.dzFvxTranslate)dzFvxTranslate()">
                        <div class="mr-icon"><i class="ri-translate-2"></i></div>
                        <div class="dz-fx1"><div class="mr-label">Translate profile</div><div class="mr-sub">Their bio and pills in your language</div></div>
                    </div>
                    <div class="mr-row" id="fvxGbRow" onclick="if(window.dzFvxGb)dzFvxGb()">
                        <div class="mr-icon"><i class="ri-book-open-line"></i></div>
                        <div class="dz-fx1"><div class="mr-label">Guest book</div><div class="mr-sub">Read signatures or leave a one-line one</div></div>
                    </div>

                    <div class="mr-row" id="mrMuteRow" onclick="mrToggleMute()">
                        <div class="mr-icon"><i class="ri-volume-mute-line"></i></div>
                        <div class="dz-fx1">
                            <div class="mr-label" id="mrMuteLabel"><?= !empty($is_muted_by_me) ? 'Unmute Account' : 'Mute Account' ?></div>
                            <div class="mr-sub" id="mrMuteSub">
                                <?= !empty($is_muted_by_me)
                                    ? 'Their posts will reappear in your feed'
                                    : "Hide their posts from your feed. They won't know." ?>
                            </div>
                        </div>
                        <?php if (!empty($is_muted_by_me)): ?>
                        <span class="mr-state-badge on-mute" id="mrMuteBadge">ON</span>
                        <?php else: ?>
                        <span class="mr-state-badge" id="mrMuteBadge" style="display:none;"></span>
                        <?php endif; ?>
                    </div>

                    <div class="mr-row" id="mrRestrictRow" onclick="mrToggleRestrict()">
                        <div class="mr-icon"><i class="ri-shield-line"></i></div>
                        <div class="dz-fx1">
                            <div class="mr-label" id="mrRestrictLabel"><?= !empty($is_restricted_by_me) ? 'Remove Restriction' : 'Restrict Account' ?></div>
                            <div class="mr-sub" id="mrRestrictSub">
                                <?= !empty($is_restricted_by_me)
                                    ? 'Their messages will return to your main inbox'
                                    : "Move their messages to requests. They won't know." ?>
                            </div>
                        </div>
                        <?php if (!empty($is_restricted_by_me)): ?>
                        <span class="mr-state-badge on-restrict" id="mrRestrictBadge">ON</span>
                        <?php else: ?>
                        <span class="mr-state-badge" id="mrRestrictBadge" style="display:none;"></span>
                        <?php endif; ?>
                    </div>

                    <div class="mr-row" onclick="mrDoUntrack()">
                        <div class="mr-icon"><i class="ri-user-unfollow-line"></i></div>
                        <div class="dz-fx1">
                            <div class="mr-label red">Untrack</div>
                            <div class="mr-sub">Stop Tracking their posts and updates</div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" class="dzm-close-btn" onclick="closeMrSheet()">Cancel</button>
        </div>
    </div>

    <div id="mrToast" class="mr-toast"></div>

<?php endif; ?>

<!-- ──────── Account switcher (#userKebab) ──────── -->
<!-- ══════════════════════════════════════
     ACCOUNT SWITCHER — opens when the owner taps
     their own username in the header.
══════════════════════════════════════ -->
<?php if ($current_user == $profile_id): ?>
<div id="userKebabOverlay" onclick="closeUserKebab()"></div>

<div id="userKebab">
    <div class="ukb-sheet">

        <div class="ukb-handle"><span></span></div>

        <div class="ukb-card">
            <div class="ukb-scroll">
                <div class="ukb-header">Accounts</div>

                <?php foreach($_SESSION['accounts'] as $acc_id):
                    $acc = null;
                    if (isset($conn)) {
                        $acc = $conn->query("SELECT id, username, profile_pic FROM users WHERE id=".(int)$acc_id)->fetch_assoc();
                    }
                    if (!$acc) $acc = ['id' => $acc_id, 'username' => $user['username'] ?? 'user', 'profile_pic' => $profilePic];
                    $is_active = ($acc_id == $current_user);

                    $acc_unread = 0;
                    if (isset($conn)) {
                        $acc_unread = $conn->query("SELECT COUNT(*) as cnt FROM messages WHERE receiver_id=".(int)$acc_id." AND seen=0")->fetch_assoc()['cnt'] ?? 0;
                    }
                ?>
                <div class="ukb-row-wrap"
                     onmouseover="this.querySelector('.unlink-btn') && (this.querySelector('.unlink-btn').style.opacity='1')"
                     onmouseout="this.querySelector('.unlink-btn') && (this.querySelector('.unlink-btn').style.opacity='0')">

                    <a href="<?php echo $is_active ? '#' : 'switch_account.php?user_id='.$acc_id; ?>"
                       onclick="<?php echo $is_active ? 'return false;' : ''; ?>"
                       class="ukb-row<?php echo $is_active ? ' ukb-active' : ''; ?>"
                       style="<?php echo $is_active ? 'opacity:0.6; cursor:default;' : ''; ?>"
                       onmouseover="if(!<?php echo $is_active ? 'true' : 'false'; ?>)this.style.background='rgba(var(--fg,255,255,255),0.06)'"
                       onmouseout="this.style.background='transparent'">

                        <div class="ukb-avatar-wrap">
                            <img src="<?php echo !empty($acc['profile_pic']) ? htmlspecialchars($acc['profile_pic']) : 'mee.jpg'; ?>"
                                 onerror="this.onerror=null;this.src='mee.jpg'"
                                 class="ukb-avatar">
                            <?php if (!$is_active && $acc_unread > 0): ?>
                            <span class="ukb-dot"></span>
                            <?php endif; ?>
                        </div>

                        <div class="ukb-info">
                            <div class="ukb-name"><?php echo htmlspecialchars($acc['username']); ?></div>
                            <?php if ($is_active): ?>
                                <div class="ukb-sub dz-blue">Active</div>
                            <?php elseif ($acc_unread > 0): ?>
                                <div class="ukb-sub unread-red"><?php echo $acc_unread; ?> unread</div>
                            <?php endif; ?>
                        </div>

                        <?php if ($is_active): ?>
                        <i class="ri-checkbox-circle-fill dz-chk"></i>
                        <?php endif; ?>
                    </a>

                    <?php if (!$is_active): ?>
                    <a href="unlink_account.php?user_id=<?php echo (int)$acc_id; ?>"
                       onclick="return confirm('Remove <?php echo htmlspecialchars($acc['username']); ?> from your accounts?');"
                       class="unlink-btn"
                       title="Remove account"
                       onmouseover="this.style.color='#FF2D2D'"
                       onmouseout="this.style.color='rgba(var(--fg,255,255,255),0.3)'">
                        <i class="ri-close-line"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <div class="ukb-divider"></div>

                <?php if ($at_limit): ?>
                <div class="ukb-add-row dz-add-off" title="Maximum 5 accounts reached">
                    <div class="ukb-add-icon dz-addic-off">
                        <i class="ri-add-line dz-addi-off"></i>
                    </div>
                    <div class="dz-fx1b">
                        <span class="dz-addt">Add Account</span>
                        <span class="dz-addlim">Limit reached (5 max)</span>
                    </div>
                </div>
                <?php else: ?>
                <a href="add_account.php" class="ukb-add-row"
                   onmouseover="this.style.background='rgba(var(--fg,255,255,255),0.06)'"
                   onmouseout="this.style.background='transparent'">
                    <div class="ukb-add-icon dz-addic-on">
                        <i class="ri-add-line dz-addi-on"></i>
                    </div>
                    <div class="dz-fx1b">
                        <span class="dz-addt">Add Account</span>
                        <span class="ukb-slots"><?php echo max(0, 5 - $linked_count); ?> slot<?php echo (5 - $linked_count) === 1 ? '' : 's'; ?> remaining</span>
                    </div>
                </a>
                <?php endif; ?>
            </div><!-- end .ukb-scroll -->
        </div><!-- end .ukb-card -->

        <button type="button" class="ukb-logout-btn" onclick="window.location.href='logout.php'">
            <i class="ri-logout-box-r-line"></i> Logout
        </button>

    </div><!-- end .ukb-sheet -->
</div><!-- end #userKebab -->
<?php endif; ?>


<!-- ──────── Profile links (#profileLinksOverlay) ──────── -->
<!-- ══════════════════════════════════════
     PROFILE LINKS MODAL — same look + sizing as the linked-accounts modal
     Only ever rendered when there are 3+ links
══════════════════════════════════════ -->
<?php if ($profile_link_count > 2): ?>
<div id="profileLinksOverlay" onclick="if(event.target===this)closeProfileLinksModal()">
    <div class="dzm-sheet">
        <div class="dzm-handle"><span></span></div>

        <div class="dzm-card">
            <div class="dzm-scroll">
                <div class="dzm-header"><?= htmlspecialchars($user['username']) ?>'s Links</div>
                <?php foreach ($profile_links as $link): ?>
                <a class="pl-item" href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener">
                    <div class="pl-icon"><i class="ri-link-m"></i></div>
                    <div class="pl-info">
                        <div class="pl-item-title"><?= htmlspecialchars($link['title'] !== '' ? $link['title'] : $link['url']) ?></div>
                        <div class="pl-item-url"><?= htmlspecialchars($link['url']) ?></div>
                    </div>
                    <i class="ri-arrow-right-s-line pl-chevron"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="button" class="dzm-close-btn" onclick="closeProfileLinksModal()">Close</button>
    </div>
</div>
<?php endif; ?>


<!-- ──────── Vibe (#vibePopup) ──────── -->
<!-- ══════════════════════════════════════
     VIBE CHECK — same look + sizing as the linked-accounts modal:
       • responsive view → compact bottom sheet
       • normal view     → 360px centred modal
     • You can NOT vote on your own profile — you only see the result.
     • Visitors who track this profile can vote (one vote, changeable).
     • A voter can UNDO their vote at any time ("Undo my vote").
     • The vibe with the most votes is the result.
     • Owner gets an on/off switch (ON by default).
══════════════════════════════════════ -->
<div id="vibePopup" onclick="if(event.target===this)closeVibePopup()">
  <div class="dzm-sheet">
    <div class="dzm-handle"><span></span></div>

    <div class="dzm-card">
      <div class="dzm-scroll">
        <div class="dzm-header">✨ Vibe check</div>
        <p id="vibeSubText" class="dzm-sub"></p>

        <div id="vibeTopResult" class="vibe-result">
          <p class="vibe-result-label">
            <?php echo ($profile_id == $current_user) ? "Your Vibe Is" : htmlspecialchars($user['username']) . "'s Vibe Is Giving"; ?>
          </p>
          <p id="vibeTopText" class="vibe-result-text"></p>
          <p id="vibeTotalText" class="vibe-result-total"></p>
          <div id="vibeBreakdown" class="vibe-breakdown"></div>
        </div>

        <div id="vibeVoteSection" style="display:none;">
          <div class="dzm-header">What vibe does this profile give?</div>
          <div class="vibe-grid" id="vibeGrid"></div>
        </div>

        <!-- UNDO: shows only when you have a vote on this profile -->
        <div id="vibeUndoRow" class="vibe-undo-row">
          <button type="button" class="vibe-undo" id="vibeUndoBtn" onclick="undoVibe()">Undo my vote</button>
        </div>

        <div id="vibeOwnerRow" class="vibe-owner">
          <div class="vibe-owner-txt">
            <div class="vibe-owner-t">Allow Vibe Check</div>
            <div class="vibe-owner-s" id="vibeOwnerSub">People who track you can give your profile a vibe</div>
          </div>
          <button type="button" class="vibe-switch" id="vibeSwitch" role="switch" aria-checked="true" aria-label="Turn Vibe Check on or off" onclick="vibeToggle()"></button>
        </div>
      </div>
    </div>

    <button type="button" onclick="closeVibePopup()" class="dzm-close-btn">Close</button>
  </div>
</div>


<!-- ──────── Collections + Post options (#colOptsDd #colNameModal #pmSheet #dpModal) ──────── -->
<?php if ($current_user): ?>
<!-- Collection options (pin / visibility / rename / delete) — bottom sheet on phones, centred modal on desktop -->
<div id="colOptsDd" onclick="if(event.target===this)gCloseColOpts()">
    <div class="dzm-sheet">
        <div class="dzm-handle"><span></span></div>
        <div class="dzm-card">
            <div class="dzm-scroll">
                <div class="dzm-header" id="colOptsTitle">Collection</div>
 
                <div class="dzm-item" id="colOptsPinItem" onclick="gColOptDo('toggle_pin')">
                    <div class="dzm-item-ic"><i class="ri-pushpin-line"></i></div>
                    <div class="dzm-item-tx">
                        <div class="dzm-item-t" id="colOptsPinLabel">Pin Collection</div>
                        <div class="dzm-item-s" id="colOptsPinSub">Show it first on your profile</div>
                    </div>
                </div>
 
                <div class="dzm-item" id="colOptsVisItem" onclick="gColOptDo('toggle_vis')">
                    <div class="dzm-item-ic"><i class="ri-earth-line"></i></div>
                    <div class="dzm-item-tx">
                        <div class="dzm-item-t" id="colOptsVisLabel">Make Public</div>
                        <div class="dzm-item-s" id="colOptsVisSub">Anyone can view this collection</div>
                    </div>
                </div>
 
                <div class="dzm-item" onclick="gColOptDo('rename')">
                    <div class="dzm-item-ic"><i class="ri-edit-line"></i></div>
                    <div class="dzm-item-tx">
                        <div class="dzm-item-t">Rename</div>
                        <div class="dzm-item-s">Change the collection's name</div>
                    </div>
                </div>
 
                <div class="dzm-item danger" onclick="gColOptDo('delete')">
                    <div class="dzm-item-ic"><i class="ri-delete-bin-line"></i></div>
                    <div class="dzm-item-tx">
                        <div class="dzm-item-t">Delete</div>
                        <div class="dzm-item-s">The memes inside are not deleted</div>
                    </div>
                </div>
            </div>
        </div>
        <button type="button" class="dzm-close-btn" onclick="gCloseColOpts()">Close</button>
    </div>
</div>

<!-- New Collection / Rename Collection -->
<div id="colNameModal" onclick="if(event.target===this)gCloseNameModal()">
    <div class="dzm-sheet">
        <div class="dzm-handle"><span></span></div>
        <div class="dzm-card">
            <div class="dzm-scroll">
                <div class="dzm-header" id="colNmTitle">New Collection</div>
 
                <div class="cm-field">
                    <input type="text" class="cm-input" id="colNmInput" placeholder="e.g. Cartoon Memes" maxlength="60"
                           autocomplete="off" onkeydown="if(event.key==='Enter')gSaveName()">
                </div>
 
                <div class="cm-row" id="colVisRow">
                    <div class="cm-row-tx">
                        <div class="cm-row-t">Make public</div>
                        <div class="cm-row-s">Anyone can view this collection</div>
                    </div>
                    <label class="col-vis-toggle"><input type="checkbox" id="colVisToggle"><span class="col-vis-slider"></span></label>
                </div>
 
                <div class="cm-actions">
                    <button type="button" class="cm-save" onclick="gSaveName()">Save</button>
                </div>
            </div>
        </div>
        <button type="button" class="dzm-close-btn" onclick="gCloseNameModal()">Cancel</button>
    </div>
</div>

<!-- POST OPTIONS — bottom sheet: Pin post · Add to Collection · Delete post.
     Opened by dzOpenPostSheet(memeId): from the ⋯ button on a grid card (own profile),
     or call it yourself from any other menu. -->
<div id="pmSheet" onclick="if(event.target===this)dzPmBack()">
    <div class="dzm-sheet">
        <div class="dzm-handle"><span></span></div>
        <div class="dzm-card">
            <div class="dzm-scroll">
                <div class="dzm-header">Post options</div>
 
                <div class="dzm-item" id="pmPinRow" onclick="dzPostSheetDo('pin')">
                    <div class="dzm-item-ic"><i class="ri-pushpin-line" id="pmPinIc"></i></div>
                    <div class="dzm-item-tx">
                        <div class="dzm-item-t" id="pmPinT">Pin post</div>
                        <div class="dzm-item-s" id="pmPinS">Show it first on your profile</div>
                    </div>
                </div>
 
                <div class="dzm-item" id="pmColRow" onclick="dzPostSheetDo('collection')">
                    <div class="dzm-item-ic"><i class="ri-bookmark-line"></i></div>
                    <div class="dzm-item-tx">
                        <div class="dzm-item-t">Add to Collection</div>
                        <div class="dzm-item-s">Save it to one of your collections</div>
                    </div>
                </div>
 
                <div class="dzm-item" id="pmFeatRow" onclick="dzPostSheetDo('feature')">
                    <div class="dzm-item-ic"><i class="ri-star-line" id="pmFeatIc"></i></div>
                    <div class="dzm-item-tx">
                        <div class="dzm-item-t" id="pmFeatT">Feature post</div>
                        <div class="dzm-item-s" id="pmFeatS">Show it at the top with a short note</div>
                    </div>
                </div>

                <div class="dzm-item danger" id="pmDelRow" onclick="dzPostSheetDo('delete')">
                    <div class="dzm-item-ic"><i class="ri-delete-bin-line"></i></div>
                    <div class="dzm-item-tx">
                        <div class="dzm-item-t">Delete post</div>
                        <div class="dzm-item-s">Remove it permanently</div>
                    </div>
                </div>
            </div>
        </div>
        <button type="button" class="dzm-close-btn" onclick="dzClosePostSheet()">Close</button>
    </div>
</div>

<!-- DELETE POST — warning before anything is removed -->
<div id="dpModal" onclick="if(event.target===this)dzCloseDeletePost()">
    <div class="dzm-sheet">
        <div class="dzm-handle"><span></span></div>
        <div class="dzm-card">
            <div class="dzm-scroll">
                <div class="dzm-header">Delete post</div>
                <div class="dp-warn">
                    <div class="dp-warn-ic"><i class="ri-error-warning-line"></i></div>
                    <div class="dp-warn-t">Delete this post permanently?</div>
                    <p class="dp-warn-s">This can't be undone. The post will disappear for everyone, and its likes, reposts and saves go with it. It will also leave every collection it is in.</p>
                </div>
                <div class="cm-actions">
                    <button type="button" class="cm-save cm-danger" id="dpConfirm" onclick="dzConfirmDeletePost()">Delete permanently</button>
                </div>
            </div>
        </div>
        <button type="button" class="dzm-close-btn" onclick="dzCloseDeletePost()">Cancel</button>
    </div>
</div>

<?php endif; ?>

<!-- ──────── Saved layout / Feature a post / Bulk collection (#sfSheet #ftModal #bkColSheet) ──────── -->
<?php if ($is_owner_view_c): ?>
<!-- SAVED LAYOUT (#sfSheet) — opened by the ⌄ beside "Saved" -->
<div id="sfSheet" onclick="if(event.target===this)sfClose()">
  <div class="dzm-sheet">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card">
      <div class="dzm-scroll">
        <div class="dzm-header">Saved layout</div>
        <div class="dzm-item<?= $favs_layout === 'default' ? ' mf-on' : '' ?>" data-layout="default" onclick="sfLayout('default')">
          <div class="dzm-item-ic"><i class="ri-grid-fill"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">Default</div><div class="dzm-item-s">Three posts per row</div></div>
          <i class="ri-check-line mf-check"></i>
        </div>
        <div class="dzm-item<?= $favs_layout === 'large' ? ' mf-on' : '' ?>" data-layout="large" onclick="sfLayout('large')">
          <div class="dzm-item-ic"><i class="ri-layout-grid-line"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">Large</div><div class="dzm-item-s">Two bigger posts per row</div></div>
          <i class="ri-check-line mf-check"></i>
        </div>
      </div>
    </div>
    <button type="button" class="dzm-close-btn" onclick="sfClose()">Close</button>
  </div>
</div>

<!-- FEATURE A POST — one-line note, shown above your Memes -->
<div id="ftModal" onclick="if(event.target===this)dzFeatClose()">
  <div class="dzm-sheet">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card">
      <div class="dzm-scroll">
        <div class="dzm-header">Feature this post</div>
        <div class="ft-pad">
          <input type="text" class="ft-input" id="ftNote" maxlength="80" placeholder="Add a note, e.g. Start here (optional)" autocomplete="off">
          <div class="ft-count"><span id="ftCount">0</span>/80</div>
        </div>
        <div class="cm-actions">
          <button type="button" class="cm-save" id="ftSave" onclick="dzFeatSave()">Feature</button>
          <button type="button" class="cm-save cm-danger" id="ftRemove" onclick="dzFeatRemove()" style="display:none;margin-top:8px">Stop featuring</button>
        </div>
      </div>
    </div>
    <button type="button" class="dzm-close-btn" onclick="dzFeatClose()">Cancel</button>
  </div>
</div>

<div id="bkColSheet" onclick="if(event.target===this)dzBulkColClose()">
  <div class="dzm-sheet">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card">
      <div class="dzm-scroll" id="bkColList"><div class="dzm-header">Add to collection</div></div>
    </div>
    <button type="button" class="dzm-close-btn" onclick="dzBulkColClose()">Cancel</button>
  </div>
</div>

<?php endif; ?>

<!-- ──────── Profile picture (#epPicModal) ──────── -->
<?php if ($ep_enabled): ?>
<!-- ══════════════════════════════════════
     EDIT PICTURE MODAL — opened by the "Edit Picture" button
     (own profile, only while the owner has no posts yet — with posts the
     button is "Dashboard"). Same look + sizing as the linked-accounts modal.
══════════════════════════════════════ -->
<div id="epPicModal" onclick="if(event.target===this)epClose()">
    <div class="dzm-sheet">
        <div class="dzm-handle"><span></span></div>

        <div class="dzm-card">
            <div class="dzm-scroll">
                <div class="dzm-header">Profile picture</div>

                <div class="ep-pic-wrap">
                    <img src="<?= htmlspecialchars($profilePic) ?>" id="epCurrentPicImg" alt="Profile" onerror="this.onerror=null;this.src='mee.jpg';">
                </div>

                <button type="button" class="ep-row" onclick="document.getElementById('epFileInput').click()">
                    <span class="ep-row-ic"><i class="ri-image-add-line"></i></span>
                    <span class="ep-row-t">Change picture</span>
                </button>
                <input type="file" id="epFileInput" accept="image/*" onchange="epHandleSelect(this)">

                <div id="epPreviewArea" class="ep-preview-area">
                    <span class="ep-preview-label">Preview</span>
                    <img id="epPreviewImg" src="" alt="Preview">
                    <div class="ep-preview-actions">
                        <button type="button" class="ep-btn save" onclick="epSave(this)">Save Photo</button>
                        <button type="button" class="ep-btn cancel" onclick="epCancel()">Cancel</button>
                    </div>
                </div>

                <div class="ep-hint">JPG, PNG, GIF or WebP &middot; max 5MB</div>
            </div>
        </div>

        <button type="button" class="dzm-close-btn" onclick="epClose()">Close</button>
    </div>
</div>

<div id="epToast" class="ep-toast"></div>

<?php endif; ?>

<!-- ──────── Block / Share card / Memes filter (#blkModal #shcModal #mfSheet) ──────── -->
<!-- BLOCK — confirmation popup. The Block button on someone else's profile opens this first; "Block" finally submits the form. -->
<div id="blkModal" onclick="if(event.target===this)dzBlockClose()">
  <div class="dzm-sheet" role="dialog" aria-modal="true" aria-labelledby="blkTitle">
    <div class="blk-t" id="blkTitle">Block this user?</div>
    <div class="blk-s">They won't be able to message you, see your posts or find your profile. This doesn't include group chats you both belong to. They won't be told that you blocked them.</div>
    <div class="blk-row">
      <button type="button" onclick="dzBlockClose()">Cancel</button>
      <button type="button" class="blk-go" onclick="dzBlockGo()">Block</button>
    </div>
  </div>
</div>

<!-- SHARE PROFILE CARD — a picture with your photo, name and a QR code. Opened by every "Share profile" button. -->
<div id="shcModal" onclick="if(event.target===this)dzShcClose()">
  <div class="dzm-sheet">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card">
      <div class="dzm-scroll">
        <div class="dzm-header">Share profile</div>
        <div class="shc-wrap"><canvas id="shcCanvas" width="1080" height="1350"></canvas></div>
        <div class="shc-btns">
          <button type="button" class="shc-main" onclick="dzShcShare()"><i class="ri-share-forward-line"></i> Share</button>
          <button type="button" onclick="dzShcSave()"><i class="ri-download-2-line"></i> Save</button>
          <button type="button" onclick="dzShcCopy()"><i class="ri-link"></i> Copy link</button>
        </div>
      </div>
    </div>
    <button type="button" class="dzm-close-btn" onclick="dzShcClose()">Close</button>
  </div>
</div>

<!-- MEMES FILTER (#mfSheet) — opened by the ⌄ chevron beside "Memes": All · Videos · Text -->
<div id="mfSheet" onclick="if(event.target===this)mfClose()">
  <div class="dzm-sheet">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card">
      <div class="dzm-scroll">
        <?php if ($is_owner_view_c): ?>
        <div class="dzm-header mf-keep">View</div>
        <div class="dzm-item mf-view mf-on" data-view="memes" onclick="dzInfoView(false)">
          <div class="dzm-item-ic"><i class="ri-grid-fill"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">Memes</div><div class="dzm-item-s">Your posts</div></div>
          <i class="ri-check-line mf-check"></i>
        </div>
        <div class="dzm-item mf-view" data-view="info" onclick="dzInfoView(true)">
          <div class="dzm-item-ic"><i class="ri-book-open-line"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">Guide</div><div class="dzm-item-s">How your profile looks to other Dezzmers</div></div>
          <i class="ri-check-line mf-check"></i>
        </div>
        <?php endif; ?>
        <div class="dzm-header">Show in Memes</div>
        <div class="dzm-item mf-on" data-kind="all" onclick="mfPick('all')">
          <div class="dzm-item-ic"><i class="ri-grid-fill"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">All</div><div class="dzm-item-s">Every post</div></div>
          <i class="ri-check-line mf-check"></i>
        </div>
        <div class="dzm-item" data-kind="image" onclick="mfPick('image')">
          <div class="dzm-item-ic"><i class="ri-image-line"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">Photos</div><div class="dzm-item-s">Only photo posts</div></div>
          <i class="ri-check-line mf-check"></i>
        </div>
        <div class="dzm-item" data-kind="video" onclick="mfPick('video')">
          <div class="dzm-item-ic"><i class="ri-video-line"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">Videos</div><div class="dzm-item-s">Only video posts</div></div>
          <i class="ri-check-line mf-check"></i>
        </div>
        <div class="dzm-item" data-kind="text" onclick="mfPick('text')">
          <div class="dzm-item-ic"><i class="ri-text"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">Text</div><div class="dzm-item-s">Only text posts</div></div>
          <i class="ri-check-line mf-check"></i>
        </div>
        <?php if ($is_owner_view_c): ?>
        <div class="dzm-header">Layout</div>
        <div class="dzm-item<?= $memes_layout === 'default' ? ' mf-on' : '' ?>" data-layout="default" onclick="mfLayout('default')">
          <div class="dzm-item-ic"><i class="ri-grid-fill"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">Default</div><div class="dzm-item-s">Three posts per row</div></div>
          <i class="ri-check-line mf-check"></i>
        </div>
        <div class="dzm-item<?= $memes_layout === 'large' ? ' mf-on' : '' ?>" data-layout="large" onclick="mfLayout('large')">
          <div class="dzm-item-ic"><i class="ri-layout-grid-line"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">Large</div><div class="dzm-item-s">Two bigger posts per row</div></div>
          <i class="ri-check-line mf-check"></i>
        </div>
        <div class="dzm-header">Manage</div>
        <div class="dzm-item" onclick="dzSelStart()">
          <div class="dzm-item-ic"><i class="ri-checkbox-multiple-line"></i></div>
          <div class="dzm-item-tx"><div class="dzm-item-t">Select posts</div><div class="dzm-item-s">Add to a collection or delete several at once</div></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <button type="button" class="dzm-close-btn" onclick="mfClose()">Close</button>
  </div>
</div>


<!-- ──────── Profile CTA pill (#ctaModal) ──────── -->
<?php if ((int)$current_user === (int)$profile_id && (int)$current_user > 0): ?>
<div id="ctaModal" onclick="if(event.target===this)dzCtaClose()">
  <div class="dzm-sheet" role="dialog" aria-modal="true" aria-label="Profile CTA pill">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card">
      <div class="dzm-scroll">
        <div class="dzm-header">Profile CTA pill</div>
        <div class="cta-body">
          <p class="cta-note">One slim pill on your profile that people tap to go to a link — “Learn more”, “Install now”, anything you want. Visitors can drag it up or down; before it opens they see a safety notice. Only visitors see it (preview it with View As). Needs a Public or Limited profile.</p>
          <div class="cta-warn<?php echo $dz_cta_ok ? '' : ' show'; ?>" id="ctaWarn">Your profile is <?php echo htmlspecialchars($dz_level); ?>. Switch it to Public or Limited in Privacy to show a CTA pill.</div>

          <label class="cta-lbl" for="ctaLabel">Pill text</label>
          <input class="cta-in" id="ctaLabel" type="text" maxlength="30" placeholder="Learn more" autocomplete="off" value="<?php echo htmlspecialchars((string)($dz_cta_own['label'] ?? '')); ?>" oninput="dzCtaPrev()">

          <label class="cta-lbl" for="ctaUrl">Link</label>
          <input class="cta-in" id="ctaUrl" type="url" inputmode="url" maxlength="500" placeholder="https://example.com" autocomplete="off" autocapitalize="off" spellcheck="false" value="<?php echo htmlspecialchars((string)($dz_cta_own['url'] ?? '')); ?>">

          <label class="cta-lbl">Picture beside the text (optional)</label>
          <div class="cta-pic-row">
            <img class="cta-pic-prev" id="ctaPicPrev" src="<?php echo htmlspecialchars($dz_cta_ownpic !== '' ? $dz_cta_ownpic : $profilePic); ?>" alt="" onerror="this.onerror=null;this.classList.add('dz-invis');">
            <button type="button" class="cta-pic-btn" onclick="document.getElementById('ctaPicFile').click()">Choose picture</button>
            <button type="button" class="cta-pic-x<?php echo $dz_cta_ownpic !== '' ? '' : ' dz-hide'; ?>" id="ctaPicX" onclick="dzCtaPicClear()">Remove</button>
            <input type="file" id="ctaPicFile" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
          </div>
          <div class="cta-pic-hint" id="ctaPicHint"><?php echo $dz_cta_ownpic !== '' ? 'Your own picture is used.' : 'No picture chosen — your profile picture is used.'; ?></div>

          <label class="cta-lbl">Colour</label>
          <div class="cta-sw" id="ctaSw">
            <?php $cta_sel = (string)($dz_cta_own['color'] ?? 'blue'); foreach (dz_cta_colors() as $ck => $cv): ?>
            <button type="button" data-c="<?php echo $ck; ?>" data-bg="<?php echo $cv[0]; ?>" data-fg="<?php echo $cv[1]; ?>" class="sw-<?php echo $ck; ?><?php echo $ck === $cta_sel ? ' on' : ''; ?>" aria-label="<?php echo htmlspecialchars($cv[2]); ?>" title="<?php echo htmlspecialchars($cv[2]); ?>" onclick="dzCtaPick(this)"></button>
            <?php endforeach; ?>
          </div>

          <div class="cta-prev">
            <div class="dz-cta cc-<?php echo htmlspecialchars($cta_sel); ?>" id="ctaPrev">
              <img class="dz-cta-pic" id="ctaPrevPic" src="<?php echo htmlspecialchars($dz_cta_ownpic !== '' ? $dz_cta_ownpic : $profilePic); ?>" alt="" onerror="this.onerror=null;this.classList.add('dz-hide');">
              <span class="dz-cta-t" id="ctaPrevT">Learn more</span>
              <i class="ri-close-line dz-cta-go"></i>
            </div>
          </div>

          <div class="cta-act">
            <button type="button" class="cta-del<?php echo $dz_cta_own ? '' : ' dz-hide'; ?>" id="ctaDel" onclick="dzCtaRemove()">Remove pill</button>
            <button type="button" class="cta-save" id="ctaSave" onclick="dzCtaSave()" <?php echo $dz_cta_ok ? '' : 'disabled'; ?>>Save</button>
          </div>
        </div>
      </div>
    </div>
    <button type="button" class="dzm-close-btn" onclick="dzCtaClose()">Close</button>
  </div>
</div>

<?php endif; ?>

<!-- ──────── Translate / Verified (#fvxVis #fvxOwn) ──────── -->
<div id="fvxVis" onclick="if(event.target===this)dzFvxClose()">
  <div class="dzm-sheet" role="dialog" aria-modal="true">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card"><div class="dzm-scroll">
      <div class="dzm-header" id="fvxVisT">Guest book</div>
      <div class="fvx-body" id="fvxVisB"></div>
    </div></div>
    <button type="button" class="dzm-close-btn" onclick="dzFvxClose()">Close</button>
  </div>
</div>

<?php if ($fvx_owner): ?>
<div id="fvxOwn" onclick="if(event.target===this)dzFvxOwnClose()">
  <div class="dzm-sheet" role="dialog" aria-modal="true">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card"><div class="dzm-scroll">
      <div class="dzm-header">Visitor tools</div>
      <div class="fvx-tabs">
        <button type="button" data-t="gb" onclick="dzFvxOwnOpen('gb')">Guest book</button>
      </div>
      <div class="fvx-body" id="fvxOwnB"></div>
    </div></div>
    <button type="button" class="dzm-close-btn" onclick="dzFvxOwnClose()">Close</button>
  </div>
</div>
<?php endif; ?>


<!-- ──────── Location + Full username (#dzLocSheet #dzUnSheet) ──────── -->
<div id="dzLocSheet" onclick="if(event.target===this)dzLocBack()">
  <div class="dzm-sheet" role="dialog" aria-modal="true" aria-label="Location">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card">
      <div class="dzm-scroll">
        <div class="dzm-header">Current location</div>
        <div class="lcs-body">
          <a class="lcs-place" id="dzLocLink" href="#"><span id="dzLocPlace"></span></a>
          <div class="lcs-sub">Tap to open in DezzMap</div>
          <div class="lcs-dist" id="dzLocDist" hidden></div>
        </div>
      </div>
    </div>
    <button type="button" class="dzm-close-btn" onclick="dzLocClose()">Close</button>
  </div>
</div>

<div id="dzUnSheet" onclick="if(event.target===this)dzUnBack()">
  <div class="dzm-sheet" role="dialog" aria-modal="true" aria-label="Full username">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card">
      <div class="dzm-scroll">
        <div class="dzm-header">Username</div>
        <div class="uns-name" id="dzUnName"></div>
      </div>
    </div>
    <button type="button" class="dzm-close-btn" onclick="dzUnSheetClose()">Close</button>
  </div>
</div>


<!-- ──────── Shops (#dzShopSheet) ──────── -->
<?php if (count($dz_shops) > 1): ?>
<div id="dzShopSheet" onclick="if(event.target===this)dzShopSheetClose()">
  <div class="dzm-sheet" role="dialog" aria-modal="true" aria-label="Shops">
    <div class="dzm-handle"><span></span></div>
    <div class="dzm-card">
      <div class="dzm-scroll">
        <div class="dzm-header">Shops</div>
        <?php foreach ($dz_shops as $dz_s): ?>
        <a class="shs-row" href="profile_shop.php?user_id=<?php echo (int)$profile_id; ?>&amp;<?php echo $dz_shop_param; ?>=<?php echo (int)$dz_s['id']; ?>">
          <?php /* shop picture, same order as the shop page: 1) the shop's own avatar  2) the owner's profile picture  3) mee.jpg */
          $dz_s_own = !empty($user['profile_pic']) ? (string)$user['profile_pic'] : 'mee.jpg';
          $dz_s_pic = ($dz_s['avatar'] !== '') ? $dz_s['avatar'] : $dz_s_own; ?>
          <img class="shs-pic" src="<?php echo htmlspecialchars($dz_s_pic); ?>" alt="" onerror="this.onerror=null;this.src='mee.jpg'">
          <div><div class="shs-name"><?php echo htmlspecialchars($dz_s['name']); ?></div><div class="shs-view">View Shop</div></div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <button type="button" class="dzm-close-btn" onclick="dzShopSheetClose()">Close</button>
  </div>
</div>

<?php endif; ?>

<?php /* ═══════════════════════════════════════════════════════════════════
   PROFILE BOTTOM-SHEET SCRIPTS
   Open / close / save logic for every modal above. Moved here from profile.php.
   ═══════════════════════════════════════════════════════════════════ */ ?>

<!-- ──── script: Save to collection · Collection options · New/Rename collection ──── -->
<?php if ($current_user): ?>
<script>
// ─────────────────────────────────────────────────────────
//  SAVE TO COLLECTION — one entry point for every "add" / "remove" circle,
//  the Post options sheet and anything else that used to open the old modal
// ─────────────────────────────────────────────────────────
function dzSaveOpen(memeId){
    memeId = parseInt(memeId, 10);
    if(!memeId) return;
    if(typeof dzCurUid !== 'undefined' && !dzCurUid){ gToast('Log in to add to collections'); return; }
 
    openSaveModal(memeId, function(ev){
        if(!ev) return;
        var now = Math.floor(Date.now()/1000);
 
        if(ev.type === 'created'){
            var nc = { id:parseInt(ev.colId,10), name:ev.name||'', is_public:ev.isPublic?1:0,
                       item_count:ev.inCollection?1:0, created_at:now, latest_addition:ev.inCollection?now:null };
            if(!_gMyCols.some(function(c){ return c.id===nc.id; })) _gMyCols.unshift(nc);
            if(_gIsOwner && !_gCols.some(function(c){ return c.id===nc.id; })) _gCols.unshift(nc);
            _gUpdateAllColCounts();
            _gRenderAllFolders();
            return;
        }
 
        if(ev.type === 'collection'){
            [_gMyCols, _gCols].forEach(function(list){
                list.forEach(function(c){
                    if(c.id != ev.colId) return;
                    if(ev.itemCount !== undefined && ev.itemCount !== null) c.item_count = parseInt(ev.itemCount,10)||0;
                    else c.item_count = Math.max(0, c.item_count + (ev.inCollection ? 1 : -1));
                    if(ev.inCollection) c.latest_addition = now;
                });
            });
            _gRefreshCountBadge(ev.colId);
 
            // taken out of the collection you are looking at → the card leaves that view too
            if(!ev.inCollection && _gActiveColId != null && _gActiveColId == ev.colId && _gActiveColCtx){
                var g = _gEl(_gActiveColCtx + 'ColDetailGrid');
                if(g){
                    g.querySelectorAll('.meme-card[data-id="'+ev.memeId+'"]').forEach(function(c){ c.remove(); });
                    var left = g.querySelectorAll('.meme-card').length;
                    var ce = _gEl(_gActiveColCtx + 'ColCount');
                    if(ce) ce.textContent = left + ' item' + (left==1?'':'s');
                }
            }
        }
    });
}
 
// old callers keep working — they now open the new sheet
function gOpenAddToCol(memeId){ dzSaveOpen(memeId); }
function gCloseAddToCol(){ svshClose(); }
 
// The "+" circle on a card and the remove circle inside a collection both open the Save sheet.
// (A capture listener, so it also covers circles drawn by the folder-grid code.)
document.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('.card-add-col-btn, .card-remove-btn') : null;
    if(!b) return;
    var id = parseInt(b.getAttribute('data-meme-id') || '', 10);
    if(!id){
        var card = b.closest('.meme-card, [data-id]');
        if(card) id = parseInt(card.getAttribute('data-id'), 10);
    }
    if(!id){
        var nums = (b.getAttribute('onclick') || '').match(/\d+/g);
        if(nums && nums.length) id = parseInt(nums[nums.length-1], 10);
    }
    if(!id) return;
    e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
    dzSaveOpen(id);
}, true);
 
// ─────────────────────────────────────────────────────────
//  COLLECTION OPTIONS (pin / visibility / rename / delete)
// ─────────────────────────────────────────────────────────
function gOpenOpts(e, colId, colName, ctx, isPublic, isPinned) {
    if(!_gIsOwner)return;
    if(e && e.stopPropagation) e.stopPropagation();
    _gOptsColId     = parseInt(colId, 10);
    _gOptsColName   = colName;
    _gOptsColCtx    = ctx;
    _gOptsColPublic = isPublic;
    _gOptsColPinned = isPinned;
 
    var title = _gEl('colOptsTitle');
    if(title) title.textContent = colName || 'Collection';
 
    var visLabel = _gEl('colOptsVisLabel'), visSub = _gEl('colOptsVisSub'), visItem = _gEl('colOptsVisItem');
    if(visLabel) visLabel.textContent = isPublic ? 'Make Private' : 'Make Public';
    if(visSub)   visSub.textContent   = isPublic ? 'Only you can view this collection' : 'Anyone can view this collection';
    if(visItem){ var vi = visItem.querySelector('i'); if(vi) vi.className = isPublic ? 'ri-lock-line' : 'ri-earth-line'; }
 
    var pinLabel = _gEl('colOptsPinLabel'), pinSub = _gEl('colOptsPinSub'), pinItem = _gEl('colOptsPinItem');
    if(pinLabel) pinLabel.textContent = isPinned ? 'Unpin Collection' : 'Pin Collection';
    if(pinSub)   pinSub.textContent   = isPinned ? 'Stop showing it first' : 'Show it first on your profile';
    if(pinItem){ var pi = pinItem.querySelector('i'); if(pi) pi.className = isPinned ? 'ri-pushpin-fill' : 'ri-pushpin-line'; }
 
    _gEl('colOptsDd').classList.add('open');
    _gLock(true);
}
 
function gCloseColOpts(){
    var dd = _gEl('colOptsDd');
    if(dd) dd.classList.remove('open');
    _gLock(false);
}
 
function gColOptDo(action){
    gCloseColOpts();
 
    if(action==='rename'){
        gOpenNameModal('rename',_gOptsColCtx,_gOptsColName);
 
    } else if(action==='delete'){
        if(!confirm('Delete "'+_gOptsColName+'"?\nMemes inside will NOT be deleted.'))return;
        _gFetch('col_action=delete_collection&col_id='+_gOptsColId,function(d){
            if(d.status!=='ok'){gToast('Could not delete');return;}
            _gCols=_gCols.filter(function(c){return c.id!=_gOptsColId;});
            _gMyCols=_gMyCols.filter(function(c){return c.id!=_gOptsColId;});
            _gPinnedColIds=_gPinnedColIds.filter(function(id){return id!=_gOptsColId;});
            if(_gOptsColCtx)gRenderFolders(_gOptsColCtx);
            _gUpdateAllColCounts();
            gToast('Collection deleted');
        });
 
    } else if(action==='toggle_vis'){
        var newVal = _gOptsColPublic ? 0 : 1;
        _gFetch('col_action=set_visibility&col_id='+_gOptsColId+'&is_public='+newVal,function(d){
            if(d.status!=='ok'){gToast('Could not update');return;}
            _gCols.forEach(function(c){if(c.id==_gOptsColId)c.is_public=newVal;});
            _gMyCols.forEach(function(c){if(c.id==_gOptsColId)c.is_public=newVal;});
            if(_gOptsColCtx)gRenderFolders(_gOptsColCtx);
            gToast(newVal ? 'Collection is now Public 🌐' : 'Collection is now Private 🔒');
        });
 
    } else if(action==='toggle_pin'){
        var isPinnedNow = _gIsColPinned(_gOptsColId);
        if(!isPinnedNow && _gPinnedColIds.length >= 3){
            gToast('Max 3 pinned collections. Unpin one first.');
            return;
        }
        var pinAction = isPinnedNow ? 'unpin_collection' : 'pin_collection';
        _gFetch('col_action='+pinAction+'&col_id='+_gOptsColId,function(d){
            if(d.status!=='ok'){gToast('Could not update');return;}
            if(isPinnedNow){
                _gPinnedColIds=_gPinnedColIds.filter(function(id){return id!=_gOptsColId;});
                gToast('Collection unpinned');
            } else {
                _gPinnedColIds.unshift(parseInt(_gOptsColId,10));
                gToast('📌 Collection pinned');
            }
            if(_gOptsColCtx)gRenderFolders(_gOptsColCtx);
        });
    }
}
 
// ─────────────────────────────────────────────────────────
//  NAME MODAL (create / rename)
// ─────────────────────────────────────────────────────────
function gOpenNameModal(mode,ctx,currentName){
    _gNmMode=mode; _gColCtx=ctx||'favs';
    var input=_gEl('colNmInput'),
        title=_gEl('colNmTitle'),
        visRow=_gEl('colVisRow'),
        visTog=_gEl('colVisToggle');
    if(title)title.textContent=mode==='rename'?'Rename Collection':'New Collection';
    if(input)input.value=mode==='rename'?(currentName||''):'';
    if(visRow)visRow.style.display=mode==='rename'?'none':'flex';
    if(visTog&&mode==='create')visTog.checked=false;
    _gEl('colNameModal').classList.add('open');
    _gLock(true);
    setTimeout(function(){if(input)input.focus();},280);
}
function gCloseNameModal(){ _gEl('colNameModal').classList.remove('open'); _gLock(false); }
 
function gSaveName(){
    var name=(_gEl('colNmInput').value||'').trim();
    if(!name){gToast('Please enter a name');return;}
 
    if(_gNmMode==='create'){
        var isPublic=_gEl('colVisToggle').checked?1:0;
        _gFetch('col_action=create_collection&name='+encodeURIComponent(name)+'&is_public='+isPublic,function(d){
            if(d.status!=='ok'){gToast('Could not create');return;}
            var newCol={
                id: parseInt(d.id,10), name:d.name, is_public:d.is_public||0, item_count:0,
                created_at: Math.floor(Date.now()/1000), latest_addition: null
            };
            if(d.collections) _gCols=_gNormalizeCols(d.collections);
            else _gCols.unshift(newCol);
            if(!_gMyCols.some(function(c){return c.id===newCol.id;})) _gMyCols.unshift(newCol);
            gCloseNameModal();
            if(_gColCtx)gRenderFolders(_gColCtx);
            _gUpdateAllColCounts();
            gToast('Collection "'+d.name+'" created ✓');
        });
    }else{
        _gFetch('col_action=rename_collection&col_id='+_gOptsColId+'&name='+encodeURIComponent(name),function(d){
            if(d.status!=='ok'){gToast('Could not rename');return;}
            _gCols.forEach(function(c){if(c.id==_gOptsColId)c.name=name;});
            _gMyCols.forEach(function(c){if(c.id==_gOptsColId)c.name=name;});
            gCloseNameModal();
            if(_gOptsColCtx)gRenderFolders(_gOptsColCtx);
            gToast('Collection renamed ✓');
        });
    }
}

</script>
<?php endif; ?>

<!-- ──── script: Post options · Delete post ──── -->
<?php if ($current_user): ?>
<script>
// ─────────────────────────────────────────────────────────
//  POST OPTIONS SHEET — Pin / Unpin · Add to Collection · Delete post
//  dzOpenPostSheet(memeId)
//   • Pin / Unpin   → own profile only (pins belong to your profile)
//   • Delete post   → own profile AND the post is yours (data-uid on the card)
//   • Add to Collection → everybody who is logged in
// ─────────────────────────────────────────────────────────
var _pmId = null, _dpId = null;
 
function _pmCard(id){ return document.querySelector('.meme-card[data-id="'+id+'"]'); }
function dzIsPinned(id){ var c=_pmCard(id); return !!c && c.getAttribute('data-pinned')==='1'; }
 
function dzOpenPostSheet(memeId){
    var id = parseInt(memeId, 10);
    if(!id) return;
    _pmId = id;
 
    var card   = _pmCard(id);
    var canPin = _gIsOwner;
    var canDel = _gIsOwner && !!card && String(card.getAttribute('data-uid')) === String(_gProfileId);
 
    _gEl('pmPinRow').style.display = canPin ? '' : 'none';
    _gEl('pmDelRow').style.display = canDel ? '' : 'none';
    _gEl('pmFeatRow').style.display = canDel ? '' : 'none';
    if(canDel){
        var isFeat = String(window.dzFeaturedId || 0) === String(id);
        _gEl('pmFeatT').textContent = isFeat ? 'Edit featured note' : 'Feature post';
        _gEl('pmFeatS').textContent = isFeat ? 'Change the note or stop featuring it' : 'Show it at the top with a short note';
        _gEl('pmFeatIc').className  = isFeat ? 'ri-star-fill' : 'ri-star-line';
    }
 
    if(canPin){
        var on = dzIsPinned(id);
        _gEl('pmPinT').textContent  = on ? 'Unpin post' : 'Pin post';
        _gEl('pmPinS').textContent  = on ? 'Stop showing it first' : 'Show it first on your profile';
        _gEl('pmPinIc').className   = on ? 'ri-pushpin-fill' : 'ri-pushpin-line';
    }
 
    _gEl('pmSheet').classList.add('open');
    _gLock(true);
}
function dzClosePostSheet(){
    var s = _gEl('pmSheet');
    if(s) s.classList.remove('open');
    _gLock(false);
}
 
/* the sheet opens while the finger is still down: the tap that ends the hold must not land on a row (it used to pick "Add to Collection") */
window.dzPmGuard = { down:false, at:0, up:0 };
function dzPmBlocked(){ var g = window.dzPmGuard; return (g.down && Date.now() - g.at < 6000) || (Date.now() - g.up < 450); }
function dzPmBack(){ if(!dzPmBlocked()) dzClosePostSheet(); }

function dzPostSheetDo(action){
    if(dzPmBlocked()) return;
    var id = _pmId;
    dzClosePostSheet();
    if(!id) return;
    if(action === 'pin')             dzTogglePin(id);
    else if(action === 'collection') dzSaveOpen(id);
    else if(action === 'delete')     dzOpenDeletePost(id);
    else if(action === 'feature')    dzFeatOpen(id);
}
 
// pin / unpin through the SAME code the card's pin button uses (gTogglePin)
function dzTogglePin(id){
    if(typeof gTogglePin !== 'function'){ gToast('Pinning is not available right now'); return; }
    var card = _pmCard(id);
    var btn  = card ? card.querySelector('.card-pin-btn') : null;
    if(!btn){                                   // post not on screen: hand gTogglePin a stand-in button in the right state
        btn = document.createElement('button');
        btn.className = 'card-pin-btn' + (dzIsPinned(id) ? ' pinned' : '');
        btn.setAttribute('data-meme-id', id);
        btn.innerHTML = '<i class="ri-pushpin-line"></i>';
    }
    gTogglePin(id, btn);
}
 
// ── delete post (with a warning first) ──
function dzOpenDeletePost(id){
    _dpId = Array.isArray(id) ? id.map(function(n){ return parseInt(n, 10); }) : parseInt(id, 10);
    var _dn = Array.isArray(_dpId) ? _dpId.length : 1, _wt = document.querySelector('#dpModal .dp-warn-t');
    if(_wt) _wt.textContent = _dn > 1 ? 'Delete ' + _dn + ' posts permanently?' : 'Delete this post permanently?';
    var b = _gEl('dpConfirm');
    if(b){ b.disabled = false; b.textContent = 'Delete permanently'; }
    _gEl('dpModal').classList.add('open');
    _gLock(true);
}
function dzCloseDeletePost(){
    var m = _gEl('dpModal');
    if(m) m.classList.remove('open');
    _gLock(false);
    _dpId = null;
}
 
function dzConfirmDeletePost(){
    var id = _dpId, btn = _gEl('dpConfirm');
    if(!id || !btn || btn.disabled) return;
    btn.disabled = true; btn.textContent = 'Deleting…';
 
    fetch(window.location.href, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        credentials:'same-origin',
        body:'post_action=delete_post&meme_ids=' + [].concat(id).join(',')
    }).then(function(r){ return r.json(); }).then(function(d){
        if(!d || d.status !== 'ok'){
            btn.disabled = false; btn.textContent = 'Delete permanently';
            gToast((d && d.message) || 'Could not delete this post');
            return;
        }
        var gone = (d.ids && d.ids.length) ? d.ids : [].concat(id);
        dzCloseDeletePost();
        gone.forEach(function(g){ dzRemovePostFromPage(g); });
        if(typeof window.dzSelCancel === 'function') window.dzSelCancel();
        gToast(gone.length > 1 ? gone.length + ' posts deleted' : 'Post deleted');
    }).catch(function(){
        btn.disabled = false; btn.textContent = 'Delete permanently';
        gToast('Network error — try again');
    });
}
 
function dzRemovePostFromPage(id){
    document.querySelectorAll('.meme-card[data-id="'+id+'"]').forEach(function(c){ c.remove(); });
 
    ['profileFeed','repostFeed','tagsFeed','favsFeed'].forEach(function(n){
        var f = window[n];
        if(!Array.isArray(f)) return;
        for(var i = f.length - 1; i >= 0; i--){ if(String(f[i].id) === String(id)) f.splice(i, 1); }
    });
 
    // post counters (only when they are plain numbers — "1.2k" is left alone)
    document.querySelectorAll('.rp-post-count b, #rpStatPosts .rp-stat-num').forEach(function(el){
        var t = (el.textContent || '').trim();
        if(/^\d+$/.test(t)) el.textContent = Math.max(0, parseInt(t,10) - 1);
    });
 
    // re-page the grids, and rebuild the page if a tab just ran out of posts (so it shows its empty state)
    var empty = false;
    ['memesGrid','repostsGrid','tagsGrid','favsGrid'].forEach(function(gid){
        var g = _gEl(gid);
        if(!g) return;
        if(typeof window.dzGridRefresh === 'function') window.dzGridRefresh(gid);
        if(!g.querySelector(':scope > .meme-card')) empty = true;
    });
    if(empty) setTimeout(function(){ location.reload(); }, 700);
}
 
// Escape closes whichever of these sheets is open
document.addEventListener('keydown', function(e){
    if(e.key !== 'Escape') return;
    ['pmSheet','dpModal','colOptsDd','colNameModal'].forEach(function(id){
        var m = _gEl(id);
        if(m && m.classList.contains('open')){ m.classList.remove('open'); _gLock(false); }
    });
});
</script>
<?php endif; ?>

<!-- ──── script: Feature a post · Select mode · Bulk add to collection ──── -->
<?php if ($is_owner_view_c): ?>
<script>
window.dzFeaturedId   = <?= $dz_featured ? (int)$dz_featured['row']['id'] : 0 ?>;
window.dzFeaturedNote = <?= json_encode($dz_featured ? $dz_featured['note'] : '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
(function(){
    function el(id){ return document.getElementById(id); }
    function toast(m){ if(typeof gToast === 'function') gToast(m); }
    function lock(on){ document.body.style.overflow = on ? 'hidden' : ''; }
    function post(body, cb){
        fetch(window.location.pathname, { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body: body })
            .then(function(r){ return r.json(); }).then(cb).catch(function(){ cb(null); });
    }
    var memes = function(){ return el('memesGrid'); };

    /* ── featured post ── */
    var ftId = 0;
    window.dzFeatOpen = function(id){
        ftId = parseInt(id, 10); var isF = String(window.dzFeaturedId) === String(ftId);
        el('ftNote').value = isF ? window.dzFeaturedNote : ''; el('ftCount').textContent = el('ftNote').value.length;
        el('ftSave').textContent = isF ? 'Save' : 'Feature'; el('ftSave').disabled = false;
        el('ftRemove').style.display = isF ? '' : 'none';
        el('ftModal').classList.add('open'); lock(true);
        setTimeout(function(){ try{ el('ftNote').focus(); }catch(e){} }, 260);
    };
    window.dzFeatClose = function(){ el('ftModal').classList.remove('open'); lock(false); };
    window.dzFeatSave = function(){
        var b = el('ftSave'); if(b.disabled) return; b.disabled = true;
        post('post_action=set_featured&meme_id=' + ftId + '&note=' + encodeURIComponent(el('ftNote').value.trim()), function(d){
            if(!d || d.status !== 'ok'){ b.disabled = false; toast((d && d.message) || 'Could not save'); return; }
            dzFeatClose(); toast('Featured'); setTimeout(function(){ location.reload(); }, 450);
        });
    };
    window.dzFeatRemove = function(){
        post('post_action=clear_featured', function(d){
            if(!d || d.status !== 'ok'){ toast('Could not update'); return; }
            dzFeatClose(); toast('No longer featured'); window.dzFeaturedId = 0;
            var f = el('featuredPost'); if(f) f.remove();
        });
    };
    document.addEventListener('input', function(e){ if(e.target && e.target.id === 'ftNote') el('ftCount').textContent = e.target.value.length; });
    document.addEventListener('keydown', function(e){ if(e.target && e.target.id === 'ftNote' && e.key === 'Enter'){ e.preventDefault(); dzFeatSave(); } });

    /* ── select mode ── */
    var picked = {};
    function ids(){ return Object.keys(picked).map(function(n){ return parseInt(n, 10); }); }
    function bar(){
        var n = ids().length;
        el('bkCount').textContent = n ? n + ' selected' : 'Select posts';
        ['bkCol', 'bkDel'].forEach(function(i){ el(i).disabled = !n; });
    }
    window.dzSelStart = function(){
        mfClose(); var g = memes(); if(!g) return;
        g.querySelectorAll(':scope > .meme-card').forEach(function(c){ if(!c.querySelector('.dz-pick-dot')){ var d = document.createElement('span'); d.className = 'dz-pick-dot'; c.appendChild(d); } });
        picked = {}; document.body.classList.add('dz-selecting'); bar();
    };
    window.dzSelCancel = function(){
        picked = {}; document.body.classList.remove('dz-selecting');
        document.querySelectorAll('#memesGrid .meme-card.dz-picked').forEach(function(c){ c.classList.remove('dz-picked'); });
    };
    document.addEventListener('click', function(e){
        if(!document.body.classList.contains('dz-selecting')) return;
        var c = e.target.closest ? e.target.closest('#memesGrid > .meme-card') : null;
        if(!c) return;
        e.preventDefault(); e.stopPropagation();
        var id = c.getAttribute('data-id');
        if(picked[id]){ delete picked[id]; c.classList.remove('dz-picked'); } else { picked[id] = 1; c.classList.add('dz-picked'); }
        bar();
    }, true);
    window.dzBulkDelete = function(){ var list = ids(); if(list.length) dzOpenDeletePost(list); };

    /* bulk "add to collection": pick one collection, every selected post goes in */
    window.dzBulkColClose = function(){ el('bkColSheet').classList.remove('open'); lock(false); };
    window.dzBulkCollection = function(){
        if(!ids().length) return;
        var box = el('bkColList'); box.innerHTML = '<div class="dzm-header">Add to collection</div><div class="dzm-sub">Loading…</div>';
        el('bkColSheet').classList.add('open'); lock(true);
        post('col_action=get_collections', function(d){
            box.innerHTML = '<div class="dzm-header">Add ' + ids().length + ' post' + (ids().length === 1 ? '' : 's') + ' to…</div>';
            var cols = (d && d.collections) || [];
            if(!cols.length){ box.insertAdjacentHTML('beforeend', '<div class="dzm-sub">You have no collections yet. Open the Collections pill on this tab to create one.</div>'); return; }
            cols.forEach(function(col){
                var row = document.createElement('div'); row.className = 'dzm-item';
                row.innerHTML = '<div class="dzm-item-ic"><i class="ri-folder-line"></i></div><div class="dzm-item-tx"><div class="dzm-item-t"></div><div class="dzm-item-s"></div></div>';
                row.querySelector('.dzm-item-t').textContent = col.name;
                row.querySelector('.dzm-item-s').textContent = (col.item_count || 0) + ' item' + (parseInt(col.item_count, 10) === 1 ? '' : 's');
                row.onclick = function(){ addAll(col); };
                box.appendChild(row);
            });
        });
    };
    function addAll(col){
        var list = ids(), n = 0, i = 0;
        dzBulkColClose();
        (function next(){
            if(i >= list.length){ toast(n + ' post' + (n === 1 ? '' : 's') + ' added to ' + col.name); dzSelCancel(); return; }
            post('col_action=add_to_collection&col_id=' + col.id + '&meme_id=' + list[i++], function(d){ if(d && (d.status === 'ok' || d.status === 'already')) n++; next(); });
        })();
    }
    document.addEventListener('keydown', function(e){
        if(e.key !== 'Escape') return;
        if(el('ftModal').classList.contains('open')) dzFeatClose();
        else if(el('bkColSheet').classList.contains('open')) dzBulkColClose();
        else if(document.body.classList.contains('dz-selecting')) dzSelCancel();
    });
})();
</script>
<?php endif; ?>

<!-- ──── script: Long-press a grid card → Post options ──── -->
<?php if ($current_user == $profile_id): ?>
<script>
/* hold a Memes-grid card for 0.25s (finger or mouse) → Post options sheet (dzOpenPostSheet, defined with the sheets above) */
(function(){
    /* ── long-press (0.25s, finger or mouse) on a Memes-grid card → Post options ── */
    var LP = 250, tmr = 0, card = null, sx = 0, sy = 0, fired = 0;
    function cancel(){ clearTimeout(tmr); tmr = 0; if(card){ card.classList.remove('dz-lp-arm'); card = null; } }
    document.addEventListener('pointerdown', function(e){
        if(e.pointerType === 'mouse' && e.button !== 0) return;
        if(document.body.classList.contains('dz-selecting')) return;      // select mode: taps pick posts instead
        var c = e.target.closest && e.target.closest('.meme-card');
        if(!c || !c.querySelector('.card-pin-btn')) return;               // Memes grid cards only (own profile)
        if(e.target.closest('button, a, .card-pin-btn, .card-add-col-btn')) return;
        cancel(); card = c; sx = e.clientX; sy = e.clientY;
        c.classList.add('dz-lp');
        void c.offsetWidth; c.classList.add('dz-lp-arm');
        tmr = setTimeout(function(){
            var id = card && card.getAttribute('data-id');
            cancel(); fired = Date.now();
            window.dzPmGuard.down = true; window.dzPmGuard.at = fired;
            dzHpHold();
            if(id) dzOpenPostSheet(id);
        }, LP);
    }, true);
    document.addEventListener('pointermove', function(e){ if(tmr && (Math.abs(e.clientX - sx) > 10 || Math.abs(e.clientY - sy) > 10)) cancel(); }, true);
    ['pointerup','pointercancel','pointerleave','scroll','dragstart'].forEach(function(t){ document.addEventListener(t, cancel, true); });
    /* the release ends the guard (a short grace period after it still swallows the tap the browser sends on release) */
    ['pointerup','pointercancel'].forEach(function(t){ document.addEventListener(t, function(){ var g = window.dzPmGuard; if(g.down){ g.down = false; g.up = Date.now(); } }, true); });
    document.addEventListener('pointerdown', function(){ window.dzPmGuard.down = false; }, true);
    /* a long-press must not also open the post */
    document.addEventListener('click', function(e){ if(window.dzPmGuard && dzPmBlocked() && document.getElementById('pmSheet') && document.getElementById('pmSheet').classList.contains('open')){ e.stopPropagation(); e.preventDefault(); return; } if(fired && Date.now() - fired < 600){ e.stopPropagation(); e.preventDefault(); fired = 0; } }, true);
    document.addEventListener('contextmenu', function(e){ var c = e.target.closest && e.target.closest('.meme-card'); if(c && c.querySelector('.card-pin-btn') && (tmr || Date.now() - fired < 800)) e.preventDefault(); }, true);
})();
</script>
<?php endif; ?>

<!-- ──── script: Hold a post on someone else's profile → preview + Like / Comment / Repost / Save / Share ──── -->
<?php if ($current_user && $current_user != $profile_id): ?>
<style>
#dzPeek{ position:fixed; inset:0; z-index:2147483000; display:none; align-items:center; justify-content:center; padding:18px 0 22px;
    background:rgba(3,4,21,.62); -webkit-backdrop-filter:blur(16px) saturate(1.1); backdrop-filter:blur(16px) saturate(1.1);
    opacity:0; transition:opacity .18s ease; -webkit-tap-highlight-color:transparent; touch-action:none; }
#dzPeek.show{ display:flex; }
#dzPeek.in{ opacity:1; }
:root[data-theme="light"] #dzPeek{ background:rgba(236,238,248,.7); }
#dzPeek .pk-wrap{ width:min(80vw,400px); height:100%; max-height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;
    transform:scale(.93); transition:transform .22s cubic-bezier(.22,1,.36,1); }
#dzPeek.in .pk-wrap{ transform:scale(1); }
/* the post: media fills the whole card, the avatar / name sit on top of it and the audio / caption on the bottom (like Instagram's hold preview) */
#dzPeek .pk-card{ position:relative; width:100%; flex:0 1 auto; min-height:0; max-height:100%; aspect-ratio:var(--pk-ar, 9/16); overflow:hidden; border-radius:6px;
    background:#0d1030; border:1px solid rgba(255,255,255,.1); box-shadow:0 18px 50px rgba(0,0,0,.45); color:#fff; }
#dzPeek .pk-media{ position:absolute; inset:0; background:#050619; }
#dzPeek .pk-media img, #dzPeek .pk-media video{ display:block; width:100%; height:100%; object-fit:cover; -webkit-user-select:none; user-select:none; -webkit-touch-callout:none; pointer-events:none; }
#dzPeek .pk-media .pk-vplay{ position:absolute; inset:0; opacity:0; transition:opacity .2s; }
#dzPeek .pk-media .pk-vplay.on{ opacity:1; }
#dzPeek .pk-card::before, #dzPeek .pk-card::after{ content:''; position:absolute; left:0; right:0; z-index:1; pointer-events:none; }
#dzPeek .pk-card::before{ top:0; height:92px; background:linear-gradient(to bottom, rgba(0,0,0,.5), rgba(0,0,0,0)); }
#dzPeek .pk-card::after{ bottom:0; height:110px; background:linear-gradient(to top, rgba(0,0,0,.55), rgba(0,0,0,0)); }
#dzPeek .pk-head{ position:absolute; top:0; left:0; right:0; z-index:2; display:flex; align-items:center; gap:10px; padding:12px 14px; }
#dzPeek .pk-head img{ width:34px; height:34px; border-radius:50%; object-fit:cover; border:1.6px solid var(--red,#FF2D2D); flex-shrink:0; background:rgba(255,255,255,.12); }
#dzPeek .pk-head span{ font-size:.95rem; font-weight:700; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 6px rgba(0,0,0,.45); }
#dzPeek .pk-head .pk-dur{ margin-left:auto; flex-shrink:0; font-size:.82rem; font-weight:500; opacity:.92; text-shadow:0 1px 6px rgba(0,0,0,.5); display:flex; align-items:center; }
#dzPeek .pk-head .pk-dur i{ font-size:1.25rem; }
#dzPeek .pk-foot{ position:absolute; left:0; right:0; bottom:0; z-index:2; padding:12px 14px 13px; display:flex; align-items:center; gap:8px; font-size:.86rem; font-weight:500; line-height:1.35; text-shadow:0 1px 6px rgba(0,0,0,.55); }
#dzPeek .pk-foot i{ font-size:1.05rem; flex-shrink:0; }
#dzPeek .pk-foot span{ min-width:0; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; word-break:break-word; }
#dzPeek .pk-foot:empty{ display:none; }
#dzPeek .pk-menu{ width:75%; flex-shrink:0; border-radius:6px; overflow:hidden; background:rgba(22,27,72,.97); border:1px solid rgba(255,255,255,.1);
    box-shadow:0 14px 40px rgba(0,0,0,.4); color:#fff; transform:translateY(10px); opacity:0; transition:transform .24s .04s cubic-bezier(.22,1,.36,1), opacity .2s .04s; }
#dzPeek.in .pk-menu{ transform:none; opacity:1; }
#dzPeek .pk-row{ width:100%; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:0 16px; height:50px; border:none; border-top:1px solid rgba(255,255,255,.09);
    background:none; color:inherit; font:500 .98rem/1 'Poppins',sans-serif; text-align:left; cursor:pointer; }
#dzPeek .pk-row:first-child{ border-top:none; }
#dzPeek .pk-row:active{ background:rgba(255,255,255,.08); }
#dzPeek .pk-row svg{ width:23px; height:23px; flex-shrink:0; display:block; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
#dzPeek .pk-row.on svg{ fill:currentColor; }
#dzPeek .pk-row.on{ color:var(--red,#FF2D2D); }
#dzPeek .pk-row.pk-repost.on svg{ fill:none; }
:root[data-theme="light"] #dzPeek .pk-card{ border-color:rgba(6,7,26,.1); box-shadow:0 18px 50px rgba(6,7,26,.22); }
:root[data-theme="light"] #dzPeek .pk-media{ background:#eef0f8; }
:root[data-theme="light"] #dzPeek .pk-menu{ background:rgba(255,255,255,.98); color:#0b0d24; border-color:rgba(6,7,26,.1); box-shadow:0 14px 40px rgba(6,7,26,.2); }
:root[data-theme="light"] #dzPeek .pk-row{ border-top-color:rgba(6,7,26,.08); }
:root[data-theme="light"] #dzPeek .pk-row:active{ background:rgba(6,7,26,.06); }
@media (min-width:769px){ #dzPeek .pk-wrap{ width:min(400px,88vw); } #dzPeek .pk-row{ font-size:1rem; } }
body.dz-peek-lock{ overflow:hidden !important; }
.meme-card .media-box img, .meme-card .media-box video{ -webkit-touch-callout:none; }
</style>
<div id="dzPeek" aria-hidden="true"><div class="pk-wrap" id="pkWrap"></div></div>
<script>
(function(){
    var LP = 380, tmr = 0, card = null, sx = 0, sy = 0, firedAt = 0, openedAt = 0, down = false, cur = 0, busy = {};
    var pk = document.getElementById('dzPeek'), wrap = document.getElementById('pkWrap');
    var IC = {
        like:   '<svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
        comment:'<svg viewBox="0 0 24 24"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg>',
        repost: '<svg viewBox="0 0 24 24" style="fill:none"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg>',
        fav:    '<svg viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1z"/></svg>',
        share:  '<svg viewBox="0 0 24 24" style="fill:none"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg>'
    };
    function esc(t){ return String(t == null ? '' : t).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function findItem(id){
        var keys = Object.keys(window);
        for(var i = 0; i < keys.length; i++){
            var k = keys[i]; if(!/Feed$/.test(k) && !/^colFeed_/.test(k)) continue;
            var a = window[k]; if(!Array.isArray(a)) continue;
            for(var j = 0; j < a.length; j++) if(a[j] && String(a[j].id) === String(id)) return a[j];
        }
        return null;
    }
    function toast(m){ if(window.ipvToast) ipvToast(m); else if(window.showToast) showToast(m); }
    function row(type, label, on){
        return '<button type="button" class="pk-row pk-' + type + (on ? ' on' : '') + '" data-act="' + type + '"><span>' + label + '</span>' + IC[type] + '</button>';
    }
    function fmtDur(t){ t = Math.max(0, Math.round(t || 0)); var m = Math.floor(t / 60), x = t % 60; return m + ':' + (x < 10 ? '0' : '') + x; }
    function setAr(w, h){ if(!w || !h) return; var r = Math.min(1.91, Math.max(9 / 16, w / h)); var cd = pk.querySelector('.pk-card'); if(cd) cd.style.setProperty('--pk-ar', r.toFixed(4)); }
    function render(id, item, c){
        var isVid = item ? item.type === 'video' : !!c.querySelector('.media-box video, .media-box .lazy-video');
        var im = c.querySelector('.media-box img'), thumb = im ? im.getAttribute('src') : '';
        var name = item ? item.username : ((c.querySelector('.top-bar .username') || {}).textContent || '');
        var pic  = item ? item.pic : ((c.querySelector('.top-bar img') || {}).src || '');
        var cap  = item ? item.caption : esc((c.querySelector('.caption') || {}).textContent || '');
        var vsrc = '';
        if(isVid){
            var vv = c.querySelector('video');
            vsrc = (vv && (vv.getAttribute('src') || vv.getAttribute('data-src'))) || (item && item.file ? 'uploads/' + item.file : '');
            vsrc = String(vsrc || '').split('#')[0];
        }
        var media = (thumb ? '<img class="pk-poster" src="' + esc(thumb) + '" alt="">' : '') +
                    (vsrc ? '<video class="pk-vplay" src="' + esc(vsrc) + '" muted loop playsinline webkit-playsinline preload="auto"></video>' : '');
        var foot = '';
        if(item && item.audioTitle){
            foot = '<i class="ri-music-2-fill"></i><span>' + esc(item.audioArtist || name) + ' \u00b7 ' + (item.audioIsOriginal ? 'Original audio' : esc(item.audioTitle)) + '</span>';
        } else if(isVid && item && item.audioIsOriginal){
            foot = '<i class="ri-music-2-fill"></i><span>' + esc(name) + ' \u00b7 Original audio</span>';
        } else if(cap){
            foot = '<span>' + cap + '</span>';
        }
        var liked = item && item.liked, rep = item && item.reposted, fav = item && item.faved;
        wrap.innerHTML =
            '<div class="pk-card" style="--pk-ar:' + (isVid ? '0.5625' : '0.8') + '"><div class="pk-media">' + media + '</div>' +
            '<div class="pk-head"><img src="' + esc(pic) + '" alt=""><span>' + esc(name) + '</span>' + (isVid ? '<div class="pk-dur"><i class="ri-play-fill"></i></div>' : '') + '</div>' +
            '<div class="pk-foot">' + foot + '</div></div>' +
            '<div class="pk-menu">' + row('like', liked ? 'Unlike' : 'Like', liked) + row('comment', 'Comment', false) +
            row('repost', rep ? 'Remove repost' : 'Repost', rep) + row('fav', fav ? 'Unsave' : 'Save', fav) + row('share', 'Share', false) + '</div>';
        /* card shape follows the post (tall posts / videos get the tall card, wide ones a wide card) */
        var ci = wrap.querySelector('.pk-media img');
        if(ci){
            var fit = function(){ setAr(ci.naturalWidth, ci.naturalHeight); };
            if(ci.complete && ci.naturalWidth) fit(); else ci.addEventListener('load', fit);
        }
        var pv = wrap.querySelector('.pk-vplay');
        if(pv){                                              /* the video plays here muted + looping; its length goes in the corner */
            pv.muted = true; pv.defaultMuted = true; pv.setAttribute('muted', '');
            pv.addEventListener('loadedmetadata', function(){
                var d = wrap.querySelector('.pk-dur'); if(d && isFinite(pv.duration) && pv.duration > 0) d.textContent = fmtDur(pv.duration);
                if(!ci || !ci.naturalWidth) setAr(pv.videoWidth, pv.videoHeight);
            });
            pv.addEventListener('playing', function(){ pv.classList.add('on'); });
            var pr = pv.play(); if(pr && pr.catch) pr.catch(function(){ /* autoplay refused: the still image stays */ });
        }
    }
    function openPeek(id, c){
        cur = id; render(id, findItem(id), c);
        openedAt = Date.now();
        pk.classList.add('show'); document.body.classList.add('dz-peek-lock'); pk.setAttribute('aria-hidden', 'false');
        void pk.offsetWidth; pk.classList.add('in');
    }
    function stopVid(){ var v = wrap.querySelector('.pk-vplay'); if(v){ try{ v.pause(); v.removeAttribute('src'); v.load(); }catch(_){} } }
    function closePeek(){
        stopVid();
        pk.classList.remove('in'); document.body.classList.remove('dz-peek-lock'); pk.setAttribute('aria-hidden', 'true');
        setTimeout(function(){ if(!pk.classList.contains('in')){ pk.classList.remove('show'); wrap.innerHTML = ''; } }, 220);
    }
    function sync(id, type, data){
        var sk = { like:'liked', repost:'reposted', fav:'faved' }[type], ck = { like:'likeCount', repost:'repostCount', fav:'favCount' }[type];
        if(typeof _gSyncAllFeedsForMeme === 'function') _gSyncAllFeedsForMeme(id, ck, data.count, sk, data[sk]);
    }
    function openComments(id){
        try{ if(window.dzIpvEnsure) dzIpvEnsure(); }catch(_){}          /* the post viewer is lazy: its comment helper only exists once it has been woken */
        if(window.CommentSection && typeof window.CommentSection.open === 'function'){ window.CommentSection.open(id); return; }
        if(window.ipvOpenComments){ ipvOpenComments(id); return; }
        toast('Comments are not available right now');
    }
    function doAction(type){
        var id = cur; if(!id) return;
        if(type === 'comment'){ closePeek(); setTimeout(function(){ openComments(id); }, 120); return; }
        if(type === 'share'){ closePeek(); setTimeout(function(){ if(window.openShareModal) openShareModal(id); else if(window.ipvShare) ipvShare(id); }, 120); return; }
        if(busy[id + type]) return; busy[id + type] = true;
        fetch('profile.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=' + type + '&meme_id=' + id, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                delete busy[id + type];
                if(!d || !d.ok){ toast('Something went wrong. Try again.'); return; }
                sync(id, type, d);
                closePeek();
                if(type === 'like') toast(d.liked ? 'Liked' : 'Like removed');
                else if(type === 'repost') toast(d.reposted ? 'Reposted' : 'Repost removed');
                else if(type === 'fav'){
                    if(d.faved && window.ccmShowSavedToast) ccmShowSavedToast(id);
                    else if(!d.faved && window.ccmHideSavedToast) ccmHideSavedToast();
                    else toast(d.faved ? 'Saved' : 'Removed from saved');
                }
            })
            .catch(function(){ delete busy[id + type]; toast('Something went wrong. Try again.'); });
    }
    wrap.addEventListener('click', function(e){
        var b = e.target.closest && e.target.closest('.pk-row');
        if(!b || Date.now() - openedAt < 450 || down) return;      // the tap that ends the hold must not pick a row
        e.stopPropagation(); doAction(b.getAttribute('data-act'));
    });
    pk.addEventListener('click', function(e){ if(e.target === pk || e.target === wrap){ if(Date.now() - openedAt > 450 && !down) closePeek(); } });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && pk.classList.contains('in')) closePeek(); });

    /* ── hold detection (finger or mouse) on a grid card ── */
    function cancel(){ clearTimeout(tmr); tmr = 0; card = null; }
    document.addEventListener('pointerdown', function(e){
        down = true;
        if(pk.classList.contains('in')) return;
        if(e.pointerType === 'mouse' && e.button !== 0) return;
        var c = e.target.closest && e.target.closest('.meme-card');
        if(!c || !c.getAttribute('data-id')) return;
        if(e.target.closest('button, a, .top-bar, .card-add-col-btn, .icon-bar')) return;
        cancel(); card = c; sx = e.clientX; sy = e.clientY;
        tmr = setTimeout(function(){
            var id = card && card.getAttribute('data-id'), cc = card; cancel();
            if(!id) return;
            firedAt = Date.now();
            dzHpHold();
            openPeek(parseInt(id, 10), cc);
        }, LP);
    }, true);
    document.addEventListener('pointermove', function(e){ if(tmr && (Math.abs(e.clientX - sx) > 10 || Math.abs(e.clientY - sy) > 10)) cancel(); }, true);
    ['pointerup','pointercancel','scroll','dragstart'].forEach(function(t){ document.addEventListener(t, function(){ if(t === 'pointerup' || t === 'pointercancel') down = false; cancel(); }, true); });
    /* the release after a hold must not also open the post */
    document.addEventListener('click', function(e){ if(Date.now() - firedAt < 700){ e.stopPropagation(); e.preventDefault(); } }, true);
    document.addEventListener('contextmenu', function(e){ var c = e.target.closest && e.target.closest('.meme-card'); if(c && (tmr || Date.now() - firedAt < 900 || pk.classList.contains('in'))) e.preventDefault(); }, true);
})();
</script>
<?php endif; ?>

<!-- ──── script: Profile picture upload ──── -->
<?php if ($ep_enabled): ?>
<script>
(function(){
    var _epFile = null;

    window.epOpenModal = function(){
        var m = document.getElementById('epPicModal');
        if(!m) return;
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.epClose = function(){
        var m = document.getElementById('epPicModal');
        if(!m) return;
        m.classList.remove('open');
        document.body.style.overflow = '';
        epCancel();
    };

    window.epHandleSelect = function(input){
        var f = input.files[0];
        if(!f) return;
        if(!f.type.startsWith('image/')){ epToast('Please pick an image file'); return; }
        _epFile = f;
        var r = new FileReader();
        r.onload = function(e){
            document.getElementById('epPreviewImg').src = e.target.result;
            document.getElementById('epPreviewArea').classList.add('visible');
        };
        r.readAsDataURL(f);
    };

    window.epCancel = function(){
        _epFile = null;
        var pa = document.getElementById('epPreviewArea');
        if(pa) pa.classList.remove('visible');
        var pi = document.getElementById('epPreviewImg');
        if(pi) pi.src = '';
        var fi = document.getElementById('epFileInput');
        if(fi) fi.value = '';
    };

    window.epSave = function(btn){
        if(!_epFile) return;
        var orig = btn.textContent;
        btn.textContent = 'Saving…'; btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'update_profile_pic');
        fd.append('profile_pic_file', _epFile);
        fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false;
            if(d.success && d.url){
                /* the live-update script (bottom of the page) refreshes the avatar everywhere */
                if(typeof window.dzLive === 'function') window.dzLive('profile_pic', d.url);
                else {
                    var t = document.getElementById('profilePic');
                    if(t) t.src = d.url;
                }
                var c = document.getElementById('epCurrentPicImg');
                if(c) c.src = d.url;
                epCancel();
                btn.textContent = orig;
                epToast('Profile pic updated ✓');
                setTimeout(epClose, 700);
            } else {
                btn.textContent = orig;
                epToast(d.msg || 'Could not save — try again');
            }
        })
        .catch(function(){
            btn.disabled = false; btn.textContent = orig;
            epToast('Network error — check connection');
        });
    };

    window.epToast = function(msg){
        var t = document.getElementById('epToast');
        if(!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function(){ t.classList.remove('show'); }, 2600);
    };

    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){
            var m = document.getElementById('epPicModal');
            if(m && m.classList.contains('open')) epClose();
        }
    });
})();
</script>
<?php endif; ?>

<!-- ──── script: Profile links ──── -->
<script>
function openProfileLinksModal(){
    dzHp('light');
    var m = document.getElementById('profileLinksOverlay');
    if(!m) return;
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeProfileLinksModal(){
    var m = document.getElementById('profileLinksOverlay');
    if(!m) return;
    m.classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeProfileLinksModal();
});
</script>

<!-- ──── script: Block ──── -->
<script>
(function(){
    var form = null;
    window.dzBlockAsk = function(e, btn){
        if(e && e.preventDefault) e.preventDefault();
        form = btn && btn.form ? btn.form : null;
        var nm = (btn && btn.getAttribute('data-name')) || '';
        document.getElementById('blkTitle').textContent = nm ? 'Block ' + nm + '?' : 'Block this user?';
        document.getElementById('blkModal').classList.add('open'); document.body.style.overflow = 'hidden';
        return false;
    };
    window.dzBlockClose = function(){ document.getElementById('blkModal').classList.remove('open'); document.body.style.overflow = ''; };
    window.dzBlockGo = function(){
        if(!form){ dzBlockClose(); return; }
        var f = form; dzBlockClose();
        var h = document.createElement('input'); h.type = 'hidden'; h.name = 'block'; h.value = '1';
        f.appendChild(h); f.submit();
    };
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && document.getElementById('blkModal').classList.contains('open')) dzBlockClose(); });
})();
</script>

<!-- ──── script: Share profile card ──── -->
<script>
window.DZ_SHARE = <?= json_encode([
    'name'      => (string)($user['username'] ?? ''),
    'nickname'  => (string)($user['nickname'] ?? ''),
    'pic'       => !empty($user['profile_pic']) ? (string)$user['profile_pic'] : 'mee.jpg',
    'bio'       => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)($user['bio'] ?? '')))), 0, 140),
    'trackers'  => (int)$followers_count,
    'id'        => (int)$profile_id,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
(function(){
    function el(id){ return document.getElementById(id); }
    function toast(m){ if(typeof gToast === 'function') return gToast(m); if(typeof picModalToast === 'function') return picModalToast(m); }
    function url(){ return window.location.origin + '/profile.php?user_id=' + window.DZ_SHARE.id; }
    function loadQR(cb){
        if(window.qrcode) return cb(true);
        var sc = document.createElement('script');
        sc.src = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js';
        sc.onload = function(){ cb(!!window.qrcode); }; sc.onerror = function(){ cb(false); };
        document.head.appendChild(sc);
    }
    function rr(x, X, Y, W, H, R){ x.beginPath(); x.moveTo(X+R, Y); x.arcTo(X+W, Y, X+W, Y+H, R); x.arcTo(X+W, Y+H, X, Y+H, R); x.arcTo(X, Y+H, X, Y, R); x.arcTo(X, Y, X+W, Y, R); x.closePath(); }
    function wrap(x, text, maxW, maxLines){
        var words = text.split(' '), lines = [], cur = '';
        for(var i = 0; i < words.length; i++){
            var t = cur ? cur + ' ' + words[i] : words[i];
            if(x.measureText(t).width > maxW && cur){ lines.push(cur); cur = words[i]; if(lines.length === maxLines) break; } else cur = t;
        }
        if(lines.length < maxLines && cur) lines.push(cur);
        if(lines.length === maxLines && words.join(' ') !== lines.join(' ')) lines[maxLines-1] = lines[maxLines-1].replace(/\s+\S*$/, '') + '…';
        return lines;
    }
    function draw(avatar, qrOK){
        var D = window.DZ_SHARE, c = el('shcCanvas'), W = 1080, H = 1350, x = c.getContext('2d');
        var g = x.createLinearGradient(0, 0, W, H); g.addColorStop(0, '#06071A'); g.addColorStop(.55, '#0d0f33'); g.addColorStop(1, '#3a0b17');
        x.fillStyle = g; x.fillRect(0, 0, W, H);
        var rg = x.createRadialGradient(W/2, 330, 20, W/2, 330, 460); rg.addColorStop(0, 'rgba(255,45,45,.30)'); rg.addColorStop(1, 'rgba(255,45,45,0)');
        x.fillStyle = rg; x.fillRect(0, 0, W, 800);
        /* avatar */
        var cx = W/2, cy = 330, r = 150;
        x.save(); x.beginPath(); x.arc(cx, cy, r + 10, 0, Math.PI*2); x.fillStyle = '#FF2D2D'; x.fill(); x.restore();
        x.save(); x.beginPath(); x.arc(cx, cy, r, 0, Math.PI*2); x.closePath(); x.clip();
        if(avatar){ var s = Math.min(avatar.width, avatar.height); x.drawImage(avatar, (avatar.width-s)/2, (avatar.height-s)/2, s, s, cx-r, cy-r, r*2, r*2); }
        else { x.fillStyle = '#1a1c4a'; x.fillRect(cx-r, cy-r, r*2, r*2); x.fillStyle = '#fff'; x.font = '700 150px sans-serif'; x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText((D.name || '?').charAt(0).toUpperCase(), cx, cy+8); }
        x.restore();
        x.textAlign = 'center'; x.textBaseline = 'alphabetic';
        /* same as the profile header: username big + white, @nickname small + muted underneath */
        var title = D.name || '';
        x.fillStyle = '#fff'; x.font = '700 64px sans-serif'; x.fillText(title.length > 24 ? title.slice(0, 23) + '…' : title, W/2, 568);
        if(D.nickname){ x.fillStyle = 'rgba(255,255,255,.6)'; x.font = '500 38px sans-serif'; x.fillText('@' + D.nickname, W/2, 626); }
        var y = 690;
        if(D.bio){ x.fillStyle = 'rgba(255,255,255,.85)'; x.font = '400 34px sans-serif'; wrap(x, D.bio, 800, 3).forEach(function(l){ x.fillText(l, W/2, y); y += 46; }); }
        x.fillStyle = 'rgba(255,255,255,.55)'; x.font = '600 30px sans-serif';
        var ty = Math.max(y + 12, 790);
        x.fillText(D.trackers + ' tracker' + (D.trackers === 1 ? '' : 's'), W/2, ty);
        /* QR — kept well clear of the trackers line */
        var qs = 300, qx = (W - qs)/2, qy = Math.max(900, ty + 84);
        x.fillStyle = '#fff'; rr(x, qx - 26, qy - 26, qs + 52, qs + 52, 28); x.fill();
        if(qrOK){
            var q = window.qrcode(0, 'M'); q.addData(url()); q.make();
            var n = q.getModuleCount(), m = qs / n;
            x.fillStyle = '#06071A';
            for(var i = 0; i < n; i++) for(var j = 0; j < n; j++) if(q.isDark(i, j)) x.fillRect(Math.round(qx + j*m), Math.round(qy + i*m), Math.ceil(m), Math.ceil(m));
        } else {
            x.fillStyle = '#06071A'; x.font = '600 26px sans-serif';   /* QR library unavailable → print the link instead */
            var ly = qy + qs/2 - 20, lk = url().replace(/^https?:\/\//, '');
            wrap(x, lk.replace(/([?&\/])/g, '$1 '), qs - 20, 4).forEach(function(l){ x.fillText(l.replace(/ /g, ''), W/2, ly); ly += 36; });
        }
        x.fillStyle = 'rgba(255,255,255,.7)'; x.font = '500 30px sans-serif'; x.fillText('Scan to open my profile', W/2, 1294);
        x.fillStyle = '#FF2D2D'; x.font = '800 34px sans-serif'; x.fillText('DEZZME', W/2, 1334);
    }
    window.dzShcOpen = function(){
        var m = el('shcModal'); if(!m) return;
        m.classList.add('open'); document.body.style.overflow = 'hidden';
        var img = new Image(); img.crossOrigin = 'anonymous';
        var done = 0, av = null, qok = false;
        function go(){ if(++done === 2) draw(av, qok); }
        img.onload = function(){ av = img; go(); }; img.onerror = function(){ av = null; go(); };
        img.src = window.DZ_SHARE.pic;
        loadQR(function(ok){ qok = ok; go(); });
    };
    window.dzShcClose = function(){ el('shcModal').classList.remove('open'); document.body.style.overflow = ''; };
    function blob(cb){ try { el('shcCanvas').toBlob(cb, 'image/png'); } catch(e){ cb(null); } }   // a tainted canvas (avatar from another site) can't be exported
    window.dzShcShare = function(){
        blob(function(b){
            var D = window.DZ_SHARE, text = 'Track ' + (D.nickname || D.name) + ' on Dezzme';
            if(b && window.File && navigator.canShare){
                var f = new File([b], 'dezzme-' + D.name + '.png', { type:'image/png' });
                if(navigator.canShare({ files:[f] })){ navigator.share({ files:[f], text:text, url:url() }).catch(function(){}); return; }
            }
            if(navigator.share){ navigator.share({ text:text, url:url() }).catch(function(){}); return; }
            dzShcCopy();
        });
    };
    window.dzShcSave = function(){
        blob(function(b){
            if(!b){ toast('Could not save the picture'); return; }
            var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'dezzme-' + window.DZ_SHARE.name + '.png';
            document.body.appendChild(a); a.click(); a.remove(); setTimeout(function(){ URL.revokeObjectURL(a.href); }, 4000);
        });
    };
    window.dzShcCopy = function(){
        if(navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url()).then(function(){ toast('Link copied'); }).catch(function(){ toast('Could not copy link'); });
        else toast('Could not copy link');
    };
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && el('shcModal').classList.contains('open')) dzShcClose(); });
})();
</script>

<!-- ──── script: Memes filter · Saved layout ──── -->
<script>
(function(){
    function sheet(){ return document.getElementById('mfSheet'); }
    window.mfOpen = function(){
        var el = sheet(); if(!el) return;
        el.classList.add('open'); document.body.style.overflow = 'hidden';
    };
    window.mfClose = function(){
        var el = sheet(); if(!el) return;
        el.classList.remove('open'); document.body.style.overflow = '';
    };
    window.mfPick = function(kind){
        window.dzMemeKind = kind;
        Array.prototype.forEach.call(document.querySelectorAll('#mfSheet .dzm-item[data-kind]'), function(it){ it.classList.toggle('mf-on', it.getAttribute('data-kind') === kind); });
        Array.prototype.forEach.call(document.querySelectorAll('#tabMemes .rp-tab-chev'), function(c){ c.classList.toggle('has-filter', kind !== 'all'); });
        mfClose();
        if(typeof window.dzGridView === 'function') window.dzGridView();
    };
    /* Layout (owner only — the sheets only contain these rows for the profile owner; the server also only writes the caller's own row) */
    function applyLayout(gridId, tab, layout, sheetId){
        var grid = document.getElementById(gridId); if(!grid) return;
        var prev = grid.classList.contains('lay-list') ? 'list' : (grid.classList.contains('lay-large') ? 'large' : 'default');
        function apply(l){
            grid.classList.remove('lay-large', 'lay-list');
            if(l !== 'default') grid.classList.add('lay-' + l);
            Array.prototype.forEach.call(document.querySelectorAll('#' + sheetId + ' .dzm-item[data-layout]'), function(it){ it.classList.toggle('mf-on', it.getAttribute('data-layout') === l); });
            if(typeof window.dzGridRefresh === 'function') window.dzGridRefresh(gridId);
            if(typeof window.dzGridView === 'function') window.dzGridView();
        }
        apply(layout);
        if(layout === prev) return;
        fetch(window.location.pathname, { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body:'post_action=set_memes_layout&tab=' + tab + '&layout=' + encodeURIComponent(layout) })
            .then(function(r){ return r.json(); })
            .then(function(d){ if(!d || d.status !== 'ok') apply(prev); })
            .catch(function(){ apply(prev); });
    }
    /* Guide view (owner): the Memes tab turns into "Guide" and the grid swaps for the guide; pick Memes to go back */
    window.dzInfoView = function(on){
        var panel = document.getElementById('memes'), btn = document.getElementById('tabMemes'), sh = sheet();
        if(!panel || !btn) { mfClose(); return; }
        panel.classList.toggle('dz-info-on', !!on);
        if(sh) sh.classList.toggle('mf-info', !!on);
        var lbl = btn.querySelector('.rp-tab-label'), ic = btn.querySelector('.rp-tab-content > i');
        if(lbl) lbl.textContent = on ? 'Guide' : 'Posts';
        if(ic) ic.className = on ? 'ri-book-open-line' : 'tab-ic tab-ic-grid';
        btn.title = on ? 'Guide' : 'Posts';
        document.body.classList.toggle('dz-guide-on', !!on);
        if(on){ var sw = document.getElementById('gridSearchWrap'); if(sw && sw.classList.contains('open') && typeof window.dzGridSearchToggle === 'function') window.dzGridSearchToggle(); }
        Array.prototype.forEach.call(document.querySelectorAll('#mfSheet .dzm-item[data-view]'), function(it){ it.classList.toggle('mf-on', (it.getAttribute('data-view') === 'info') === !!on); });
        mfClose();
        if(typeof rpMoveTabIndicator === 'function') rpMoveTabIndicator(btn);
        if(!on && typeof window.dzGridView === 'function') window.dzGridView();
        setTimeout(function(){ window.dispatchEvent(new Event('resize')); if(typeof rpMoveTabIndicator === 'function') rpMoveTabIndicator(btn); }, 30);
    };
    window.dzInfoEdit = function(){
        if(typeof pmOpenMain === 'function') pmOpenMain(); else window.location.href = 'setting.php';
    };
    window.mfLayout = function(layout){ mfClose(); applyLayout('memesGrid', 'memes', layout, 'mfSheet'); };
    window.sfLayout = function(layout){ sfClose(); applyLayout('favsGrid', 'favorites', layout, 'sfSheet'); };
    window.sfOpen = function(){ var el = document.getElementById('sfSheet'); if(el){ el.classList.add('open'); document.body.style.overflow = 'hidden'; } };
    window.sfClose = function(){ var el = document.getElementById('sfSheet'); if(el){ el.classList.remove('open'); document.body.style.overflow = ''; } };
    document.addEventListener('keydown', function(e){ var el = sheet(); if(e.key === 'Escape' && el && el.classList.contains('open')) mfClose(); var sf = document.getElementById('sfSheet'); if(e.key === 'Escape' && sf && sf.classList.contains('open')) sfClose(); });
})();
</script>

<!-- ──── script: Tracking (Mute / Restrict / Untrack) ──── -->
<script>
/* ── Tracking modal (Mute / Restrict / Untrack) ── */
(function(){
    var _profileId  = <?= (int)$profile_id ?>;
    var _isMuted    = <?= !empty($is_muted_by_me)      ? 'true' : 'false' ?>;
    var _isRestrict = <?= !empty($is_restricted_by_me) ? 'true' : 'false' ?>;

    window.openMrSheet = function(e) {
        if (e) e.stopPropagation();
        var sheet = document.getElementById('mrSheet');
        if (!sheet) return;
        sheet.classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.closeMrSheet = function() {
        var sheet = document.getElementById('mrSheet');
        if (!sheet) return;
        sheet.classList.remove('open');
        document.body.style.overflow = '';
    };

    /* Untrack via the main pill (no sheet open) — spinner then submit */
    window.showSpinnerUntrack = function() {
        var btn = document.getElementById('untrackMainBtn');
        if (!btn) return;
        var wrap = btn.closest('.rp-rect-btn');
        if (wrap) wrap.style.pointerEvents = 'none';
        btn.innerHTML = '<span class="spinner-icon"><span></span><span></span><span></span><span></span></span>';
        if (window._dzLiveTrack) {   // public profile → untrack in the background, no page refresh
            dzLiveFollow(null, false, function(){
                btn.textContent = 'Untrack';
                if (wrap) wrap.style.pointerEvents = '';
            });
            return;
        }
        setTimeout(function() {
            var form = document.getElementById('rpTrackForm');
            if (!form) return;
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = 'unfollow'; h.value = '1';
            form.appendChild(h);
            form.submit();
        }, 120);
    };

    /* Untrack via the modal row */
    window.mrDoUntrack = function() {
        closeMrSheet();
        setTimeout(showSpinnerUntrack, 180);
    };

    /* ── Mute Toggle ── */
    window.mrToggleMute = function() {
        var action = _isMuted ? 'unmute' : 'mute';
        _mrFetch(action, function(d) {
            if (!d || d.status !== 'ok') { mrShowToast('Something went wrong'); return; }
            _isMuted = !_isMuted;
            _updateMuteUI();
            mrShowToast(_isMuted ? 'Account muted' : 'Account unmuted');
        });
    };

    function _updateMuteUI() {
        var lbl = document.getElementById('mrMuteLabel');
        var sub = document.getElementById('mrMuteSub');
        var bdg = document.getElementById('mrMuteBadge');
        if (lbl) lbl.textContent = _isMuted ? 'Unmute Account' : 'Mute Account';
        if (sub) sub.textContent = _isMuted
            ? 'Their posts will reappear in your feed'
            : "Hide their posts from your feed. They won't know.";
        if (bdg) {
            bdg.textContent = _isMuted ? 'ON' : '';
            bdg.className = 'mr-state-badge' + (_isMuted ? ' on-mute' : '');
            bdg.style.display = _isMuted ? '' : 'none';
        }
    }

    /* ── Restrict Toggle ── */
    window.mrToggleRestrict = function() {
        var action = _isRestrict ? 'unrestrict' : 'restrict';
        _mrFetch(action, function(d) {
            if (!d || d.status !== 'ok') { mrShowToast('Something went wrong'); return; }
            _isRestrict = !_isRestrict;
            _updateRestrictUI();
            mrShowToast(_isRestrict ? 'Account restricted' : 'Restriction removed');
        });
    };

    function _updateRestrictUI() {
        var lbl = document.getElementById('mrRestrictLabel');
        var sub = document.getElementById('mrRestrictSub');
        var bdg = document.getElementById('mrRestrictBadge');
        if (lbl) lbl.textContent = _isRestrict ? 'Remove Restriction' : 'Restrict Account';
        if (sub) sub.textContent = _isRestrict
            ? 'Their messages will return to your main inbox'
            : "Move their messages to requests. They won't know.";
        if (bdg) {
            bdg.textContent = _isRestrict ? 'ON' : '';
            bdg.className = 'mr-state-badge' + (_isRestrict ? ' on-restrict' : '');
            bdg.style.display = _isRestrict ? '' : 'none';
        }
    }

    function _mrFetch(action, cb) {
        fetch('profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'mr_action=' + action + '&target_id=' + _profileId
        }).then(function(r) { return r.json(); }).then(cb)
          .catch(function() { mrShowToast('Network error'); });
    }

    window.mrShowToast = function(msg) {
        var t = document.getElementById('mrToast');
        if (!t) return;
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function() { t.classList.remove('show'); }, 2400);
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeMrSheet();
    });

    /* swipe down on the sheet → close (same as before) */
    var _sy = 0;
    var sh = document.getElementById('mrSheet');
    if (sh) {
        sh.addEventListener('touchstart', function(e) { _sy = e.touches[0].clientY; }, { passive: true });
        sh.addEventListener('touchend', function(e) {
            if (e.changedTouches[0].clientY - _sy > 70) closeMrSheet();
        }, { passive: true });
    }
})();
</script>

<!-- ──── script: Account switcher ──── -->
<script>
/* ── Account switcher (userKebab) — opens from the owner's username ── */
function toggleUserKebab(e) {
    if (e) e.stopPropagation();
    const overlay = document.getElementById('userKebabOverlay');
    const k = document.getElementById('userKebab');
    if (!k || !overlay) return;

    const isOpen = k.classList.contains('open');
    if (isOpen) {
        closeUserKebab();
    } else {
        overlay.classList.add('open');
        k.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}

function closeUserKebab() {
    const overlay = document.getElementById('userKebabOverlay');
    const k = document.getElementById('userKebab');
    if (!k || !overlay) return;

    overlay.classList.remove('open');
    k.classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeUserKebab();
});
</script>

<!-- ──── script: Vibe check ──── -->
<script>
/* ══════════════════════════════════════════════════════════════
   VIBE CHECK
   • Opening the modal always asks the server for the live result.
   • Owner  → results only + on/off switch. (Can never vote on themselves.)
   • Visitor who tracks → vote grid (their current vote is highlighted;
     voting again changes it) + the result.
   • Anyone with a vote gets an "Undo my vote" button.
   • Result = the vibe with the MOST votes.
══════════════════════════════════════════════════════════════ */
var VIBES = [
    ['Main character 🔥'], ['Mysterious 🌙'], ['Looks like a problem 😭'], ['Chaos energy ⚡'],
    ['Chill but dangerous 😈'], ['Unbothered elite 😌', 'Unbothered 😌'],
    ["Knows they're attractive 😭"], ['Underrated legend 👑'],
    ['Hot mess 🔥', 'Hot ass mess 🔥'], ['Unreachable energy 📶'], ['Soft life era 🌸'],
    ["Don't text them first 🚫📱"], ['Quiet luxury 🖤'], ["Probably toxic (don't trust) 🚩"],
    ['Gym bro / gym menace 🏋️'], ['Will knock out your teeth 😭'], ['Bookworm energy 📚'],
    ["Thinks they're all that 😏"], ['Would argue and win 💀'], ['Always in drama somehow 🎭'],
    ["Acts innocent but isn't 😭"], ['Walking ego boost 📈']
];
var _vibeMine = '';

function vibePost(body, cb){
    fetch('profile.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: body + '&target_id=' + _picModalProfileId
    }).then(function(r){ return r.json(); }).then(cb)
      .catch(function(){ cb(null); });
}

function vibeBuildGrid(){
    var grid = document.getElementById('vibeGrid');
    if(!grid || grid.children.length) return;
    VIBES.forEach(function(v){
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'vibe-opt';
        b.setAttribute('data-v', v[0]);
        b.textContent = v[1] || v[0];
        b.onclick = function(){ submitVibe(v[0]); };
        grid.appendChild(b);
    });
}

function vibeMarkMine(){
    var grid = document.getElementById('vibeGrid');
    if(!grid) return;
    Array.prototype.forEach.call(grid.children, function(b){
        b.classList.toggle('selected', !!_vibeMine && b.getAttribute('data-v') === _vibeMine);
        b.disabled = false;
    });
}

function vibeRender(d){
    var sub    = document.getElementById('vibeSubText');
    var result = document.getElementById('vibeTopResult');
    var topEl  = document.getElementById('vibeTopText');
    var totEl  = document.getElementById('vibeTotalText');
    var vote   = document.getElementById('vibeVoteSection');
    var owner  = document.getElementById('vibeOwnerRow');
    var undo   = document.getElementById('vibeUndoRow');

    if(!d || d.status !== 'ok'){
        if(sub) sub.textContent = 'Could not load Vibe Check — try again';
        if(result) result.style.display = 'none';
        if(vote) vote.style.display = 'none';
        if(undo) undo.style.display = 'none';
        return;
    }

    _vibeEnabled = (d.enabled !== false);
    _vibeMine    = d.mine || '';
    if(_vibeMine){   /* already gave a vibe → the red nudge icons around the picture go away */
        Array.prototype.forEach.call(document.querySelectorAll('.dz-vbn'), function(el){
            el.classList.add('gone');
            setTimeout(function(){ if(el.parentNode) el.parentNode.removeChild(el); }, 300);
        });
    }
    var total    = parseInt(d.total, 10) || 0;

    /* result = the vibe with the most votes */
    if(result){
        /* the text is ALWAYS filled in (votes are never deleted when Vibe Check is switched off);
           only whether it is shown depends on Vibe Check being on */
        if(topEl) topEl.textContent = total > 0 ? d.top : 'No vibes yet';
        if(totEl) totEl.textContent = total > 0 ? total + ' vote' + (total === 1 ? '' : 's') : '';
        var bd = document.getElementById('vibeBreakdown');
        if(bd){
            var rws = Array.isArray(d.rows) ? d.rows : [];
            bd.innerHTML = '';
            if(rws.length > 1){
                var mx = Math.max.apply(null, rws.map(function(r){ return r.c; })) || 1;
                rws.forEach(function(r){
                    var row = document.createElement('div'); row.className = 'vibe-bd-row';
                    var nm = document.createElement('span'); nm.className = 'vibe-bd-name'; nm.textContent = r.vibe;
                    var bar = document.createElement('span'); bar.className = 'vibe-bd-bar';
                    var fill = document.createElement('i'); fill.style.width = Math.max(6, Math.round(r.c / mx * 100)) + '%'; bar.appendChild(fill);
                    var n = document.createElement('span'); n.className = 'vibe-bd-n'; n.textContent = r.c;
                    row.appendChild(nm); row.appendChild(bar); row.appendChild(n); bd.appendChild(row);
                });
            }
            bd.classList.toggle('show', rws.length > 1);
        }
        result.style.display = (_vibeEnabled && (total > 0 || _vibeOwner)) ? 'block' : 'none';
    }

    /* voting: never on your own profile; only while it's ON and you track them */
    var canVote = !_vibeOwner && _vibeEnabled && d.can_vote === true;
    if(vote){
        vote.style.display = canVote ? 'block' : 'none';
        if(canVote){ vibeBuildGrid(); vibeMarkMine(); }
    }

    /* undo: visible whenever the viewer has a vote of their own (never for the owner) */
    if(undo){
        var btn = document.getElementById('vibeUndoBtn');
        if(btn){ btn.disabled = false; btn.textContent = 'Undo my vote'; }
        undo.style.display = (!_vibeOwner && _vibeMine) ? 'block' : 'none';
    }

    if(owner) owner.style.display = _vibeOwner ? 'flex' : 'none';
    if(_vibeOwner) vibeSyncSwitch();

    if(sub){
        if(_vibeOwner){
            sub.textContent = _vibeEnabled
                ? 'This is what people think of your vibe. Only you can see the results here.'
                : 'Vibe Check is turned off. Nobody can give you a vibe right now.';
        } else if(canVote){
            sub.textContent = _vibeMine ? 'You can change your vibe or undo it any time.' : 'Pick the vibe this profile gives.';
        } else if(!_vibeEnabled){
            sub.textContent = 'Vibe Check is turned off for this profile.';
        } else {
            sub.textContent = 'Track this profile to give your vibe.';
        }
    }

    if(typeof pfVibeUpdate === 'function') pfVibeUpdate(d.top || '', total, d.rows || []);
}

function _vibeHasVotes(){ var t = document.getElementById('vibeTotalText'); return !!(t && t.textContent); }

function vibeSyncSwitch(){
    ['vibeSwitch', 'pfVibeSwitch'].forEach(function(id){
        var sw = document.getElementById(id);
        if(!sw) return;
        sw.classList.toggle('on', _vibeEnabled);
        sw.setAttribute('aria-checked', _vibeEnabled ? 'true' : 'false');
    });
    var sub = document.getElementById('vibeOwnerSub');
    if(sub) sub.textContent = _vibeEnabled
        ? 'People who track you can give your profile a vibe'
        : 'Vibe Check is off — nobody can give you a vibe';
}

function vibeCheck(profileId){
    closePicModal();
    var popup = document.getElementById('vibePopup');
    if(!popup) return;
    var sub = document.getElementById('vibeSubText');
    if(sub) sub.textContent = 'Loading…';
    popup.classList.add('open');
    document.body.style.overflow = 'hidden';
    vibePost('vibe_action=results', vibeRender);
}

function closeVibePopup(){
    var popup = document.getElementById('vibePopup');
    if(!popup) return;
    popup.classList.remove('open');
    window._dzNoPicUntil = Date.now() + 500;   /* a tap on the dark backdrop can't fall through and re-open the profile picture viewer */
    var _pm = document.getElementById('picModal');
    document.body.style.overflow = (_pm && _pm.style.display === 'flex') ? 'hidden' : '';
}
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeVibePopup();
});

/* record a vote → the server saves it, then returns the vibe with the most votes */
function submitVibe(choice){
    if(_vibeOwner) return;   // you can't vibe-check yourself
    var grid = document.getElementById('vibeGrid');
    if(grid) Array.prototype.forEach.call(grid.children, function(b){ b.disabled = true; });
    vibePost('vibe_action=submit&vibe=' + encodeURIComponent(choice), function(d){
        if(d && d.status === 'ok'){
            vibeRender(d);
            picModalToast('Your vibe was saved');
        } else {
            vibeMarkMine();
            var sub = document.getElementById('vibeSubText');
            if(sub) sub.textContent = 'Could not save your vibe — try again';
        }
    });
}

/* UNDO: removes ONLY the viewer's own vote, then refreshes the result */
function undoVibe(){
    if(_vibeOwner || !_vibeMine) return;
    var btn = document.getElementById('vibeUndoBtn');
    if(btn){ btn.disabled = true; btn.textContent = 'Removing…'; }
    var grid = document.getElementById('vibeGrid');
    if(grid) Array.prototype.forEach.call(grid.children, function(b){ b.disabled = true; });
    vibePost('vibe_action=undo', function(d){
        if(d && d.status === 'ok'){
            vibeRender(d);
            picModalToast('Your vote was removed');
        } else {
            if(btn){ btn.disabled = false; btn.textContent = 'Undo my vote'; }
            vibeMarkMine();
            picModalToast('Could not undo — try again');
        }
    });
}

/* owner: switch Vibe Check on / off (ON by default).
   Works from the switch in the right-hand Vibe card AND from the modal.
   When it's OFF, other people can't see the Vibe card or any votes. */
function vibeToggle(){
    if(!_vibeOwner) return;
    var sws = ['vibeSwitch', 'pfVibeSwitch'].map(function(id){ return document.getElementById(id); }).filter(Boolean);
    sws.forEach(function(sw){ sw.disabled = true; });
    var next = !_vibeEnabled;
    vibePost('vibe_action=toggle&enabled=' + (next ? '1' : '0'), function(d){
        sws.forEach(function(sw){ sw.disabled = false; });
        if(d && d.status === 'ok'){
            _vibeEnabled = (d.enabled !== false);
            vibeSyncSwitch();
            if(typeof pfVibeState === 'function') pfVibeState(_vibeEnabled);
            var vres = document.getElementById('vibeTopResult');
            if(vres) vres.style.display = (_vibeEnabled && _vibeHasVotes()) ? 'block' : (_vibeEnabled ? 'block' : 'none');   // off → hidden for the owner too; on → the earlier results are back
            var sub = document.getElementById('vibeSubText');
            if(sub) sub.textContent = _vibeEnabled
                ? 'This is what people think of your vibe. Only you can see the results here.'
                : 'Vibe Check is turned off. Nobody can give you a vibe right now.';
            picModalToast(_vibeEnabled ? 'Vibe Check is on' : 'Vibe Check is off');
        } else {
            picModalToast('Could not change this — try again');
        }
    });
}

</script>

<!-- ──── script: Profile CTA pill ──── -->
<?php if ((int)$current_user === (int)$profile_id && (int)$current_user > 0): ?>
<script>
(function(){
    function $(id){ return document.getElementById(id); }
    var color = <?php echo json_encode($cta_sel); ?>;
    var profilePic = <?php echo json_encode((string)$profilePic); ?>;
    var hadPic = <?php echo $dz_cta_ownpic !== '' ? 'true' : 'false'; ?>, removePic = false, newFile = null, ownPicUrl = <?php echo json_encode($dz_cta_ownpic); ?>;
    function toast(m){ if(typeof gToast === 'function') return gToast(m); if(typeof picModalToast === 'function') return picModalToast(m); }
    function showPic(src){ $('ctaPicPrev').classList.remove('dz-invis'); $('ctaPicPrev').src = src; $('ctaPrevPic').classList.remove('dz-hide'); $('ctaPrevPic').src = src; }
    window.dzCtaPrev = function(){ $('ctaPrevT').textContent = $('ctaLabel').value.trim() || 'Learn more'; };
    window.dzCtaPick = function(b){
        color = b.getAttribute('data-c');
        Array.prototype.forEach.call($('ctaSw').children, function(x){ x.classList.toggle('on', x === b); });
        var p = $('ctaPrev'); p.className = p.className.replace(/\bcc-\S+/g, '').trim() + ' cc-' + color;
    };
    document.addEventListener('change', function(ev){ var inp = ev.target; if(!inp || inp.id !== 'ctaPicFile') return;
        var f = inp.files && inp.files[0]; if(!f) return;
        if(!/^image\//.test(f.type)){ toast('Choose an image'); inp.value = ''; return; }
        if(f.size > 4 * 1024 * 1024){ toast('Picture is too big (max 4 MB)'); inp.value = ''; return; }
        newFile = f; removePic = false;
        showPic(URL.createObjectURL(f));
        $('ctaPicX').classList.remove('dz-hide'); $('ctaPicHint').textContent = 'This picture will show beside your text.';
    });
    window.dzCtaPicClear = function(){
        newFile = null; removePic = hadPic; $('ctaPicFile').value = '';
        showPic(profilePic); $('ctaPicX').classList.add('dz-hide'); $('ctaPicHint').textContent = 'No picture chosen — your profile picture is used.';
    };
    window.dzCtaOpen = function(){
        var on = $('ctaSw').querySelector('.on') || $('ctaSw').firstElementChild;
        dzCtaPick(on); dzCtaPrev();
        $('ctaModal').classList.add('open'); document.body.style.overflow = 'hidden';
    };
    window.dzCtaClose = function(){ $('ctaModal').classList.remove('open'); document.body.style.overflow = ''; };
    function send(fd, done){
        fetch('profile.php', { method:'POST', credentials:'same-origin', body: fd })
            .then(function(r){ return r.json(); }).then(done).catch(function(){ done({status:'error', message:'Network error — try again'}); });
    }
    /* phone photos are big and servers refuse big uploads: crop to a centred square and shrink it first */
    function shrink(file){
        return new Promise(function(resolve){
            var url = URL.createObjectURL(file), im = new Image();
            im.onload = function(){
                try {
                    var side = Math.min(im.naturalWidth, im.naturalHeight), out = Math.min(320, side);
                    var c = document.createElement('canvas'); c.width = out; c.height = out;
                    c.getContext('2d').drawImage(im, (im.naturalWidth - side) / 2, (im.naturalHeight - side) / 2, side, side, 0, 0, out, out);
                    var type = (file.type === 'image/jpeg') ? 'image/jpeg' : 'image/png';
                    c.toBlob(function(b){ URL.revokeObjectURL(url); resolve(b || file); }, type, 0.9);
                } catch(e){ URL.revokeObjectURL(url); resolve(file); }
            };
            im.onerror = function(){ URL.revokeObjectURL(url); resolve(file); };
            im.src = url;
        });
    }
    window.dzCtaSave = function(){
        var btn = $('ctaSave'); if(btn.disabled) return;
        var label = $('ctaLabel').value.trim(), url = $('ctaUrl').value.trim();
        if(!label){ toast('Write what the pill should say'); $('ctaLabel').focus(); return; }
        if(!url){ toast('Add the link the pill opens'); $('ctaUrl').focus(); return; }
        btn.disabled = true;
        (newFile ? shrink(newFile) : Promise.resolve(null)).then(function(blob){
        var fd = new FormData();
        fd.append('cta_action', 'save'); fd.append('label', label); fd.append('url', url); fd.append('color', color);
        if(blob) fd.append('pic', blob, (blob.type === 'image/jpeg') ? 'pic.jpg' : 'pic.png');
        if(removePic) fd.append('remove_pic', '1');
        send(fd, function(d){
            btn.disabled = false;
            if(d && d.status === 'ok'){
                $('ctaUrl').value = d.url; $('ctaLabel').value = d.label; $('ctaDel').classList.remove('dz-hide');
                hadPic = !!d.pic; ownPicUrl = d.pic || ''; newFile = null; removePic = false; $('ctaPicFile').value = '';
                dzCtaClose(); toast('CTA pill saved — preview it with View As');
            } else toast((d && d.message) ? d.message : 'Could not save — try again');
        });
        });
    };
    window.dzCtaRemove = function(){
        var fd = new FormData(); fd.append('cta_action', 'delete');
        send(fd, function(d){
            if(d && d.status === 'ok'){
                $('ctaLabel').value = ''; $('ctaUrl').value = ''; $('ctaDel').classList.add('dz-hide');
                hadPic = false; removePic = false; newFile = null; dzCtaPicClear(); dzCtaPrev(); dzCtaClose(); toast('CTA pill removed');
            } else toast('Could not remove — try again');
        });
    };
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && $('ctaModal').classList.contains('open')) dzCtaClose(); });
})();
</script>
<?php endif; ?>

<!-- ──── script: Translate / Verified ──── -->
<script>
(function(){
    function $(id){ return document.getElementById(id); }
    function esc(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    function toast(m){ if(typeof gToast === 'function') return gToast(m); if(typeof picModalToast === 'function') return picModalToast(m); }
    var PID = <?php echo $fvx_pid; ?>, NAME = <?php echo json_encode($fvx_uname); ?>, OWNER = <?php echo $fvx_owner ? 'true' : 'false'; ?>;
    var SINCE = <?php echo json_encode($fvx_since); ?>, GB = <?php echo json_encode($fvx_gb); ?>, MINE = <?php echo json_encode($fvx_mine); ?>;
    var PIC = <?php echo json_encode((string)$profilePic); ?>;
    var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    function post(data){
        var fd = new FormData(); for(var k in data) fd.append(k, data[k]);
        return fetch('profile.php', { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){ return r.json(); });
    }
    function openVis(title, html){ $('fvxVisT').textContent = title; $('fvxVisB').innerHTML = html; $('fvxVis').classList.add('open'); }
    window.dzFvxClose = function(){ $('fvxVis').classList.remove('open'); };
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ dzFvxClose(); if(window.dzFvxOwnClose) dzFvxOwnClose(); } });

    /* ── Tracking since (+ anniversary card) ── */
    var sinceD = null, yrs = 0, days = 0, annivToday = false, nextIn = 0;
    if(SINCE){
        var p = SINCE.split('-'); sinceD = new Date(+p[0], +p[1]-1, +p[2]);
        var now = new Date(), t0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        days = Math.max(0, Math.round((t0 - sinceD) / 864e5));
        yrs = now.getFullYear() - sinceD.getFullYear();
        var thisYr = new Date(now.getFullYear(), sinceD.getMonth(), sinceD.getDate());
        if(thisYr > t0) yrs--;
        annivToday = (thisYr.getTime() === t0.getTime()) && yrs >= 1;
        var nx = thisYr < t0 ? new Date(now.getFullYear()+1, sinceD.getMonth(), sinceD.getDate()) : thisYr;
        nextIn = Math.round((nx - t0) / 864e5);
    }
    function sinceLabel(){ return sinceD ? sinceD.getDate() + ' ' + MON[sinceD.getMonth()] + ' ' + sinceD.getFullYear() : ''; }
    function dur(){
        if(days < 1) return 'today'; if(days < 31) return days + ' day' + (days === 1 ? '' : 's');
        var m = Math.floor(days / 30.44); if(m < 12) return m + ' month' + (m === 1 ? '' : 's');
        var y = Math.floor(m / 12), r = m % 12; return y + ' year' + (y === 1 ? '' : 's') + (r ? ', ' + r + ' month' + (r === 1 ? '' : 's') : '');
    }
    function sinceCard(){
        var big = annivToday ? '🎉' : '💛';
        var t = annivToday ? 'Happy ' + yrs + '-year tracking anniversary!' : 'Tracking since ' + sinceLabel();
        var s = annivToday ? 'You started tracking @' + esc(NAME) + ' on ' + sinceLabel() + '.' : 'That is ' + dur() + ' (' + days + ' day' + (days === 1 ? '' : 's') + ') of tracking @' + esc(NAME) + '. Your next anniversary is in ' + nextIn + ' day' + (nextIn === 1 ? '' : 's') + '.';
        return '<div class="fvx-card"><div class="big">' + big + '</div><div class="t">' + esc(t) + '</div><div class="s">' + s + '</div></div>';
    }
    window.dzFvxSince = function(){ if(!sinceD){ toast('Tracking date not available'); return; } openVis('Tracking since', sinceCard()); };
    (function(){
        var row = $('fvxSinceRow'); if(!row) return;
        if(!sinceD){ row.classList.add('dz-hide'); return; }
        $('fvxSinceSub').textContent = sinceLabel() + ' · ' + dur();
        if(annivToday){ var h = $('fvxAnniv'); if(h){ h.innerHTML = sinceCard(); } }
    })();

    /* ── Guest book (visitor side) ── */
    function gbRender(){
        var h = '<p class="fvx-note">One-line signatures from people who track ' + esc(NAME) + '. They show here once ' + esc(NAME) + ' approves them.</p>';
        if(!GB.length) h += '<div class="fvx-empty">No signatures yet</div>';
        GB.forEach(function(g){ h += '<div class="fvx-item"><img src="' + esc(g.p || 'mee.jpg') + '" alt="" onerror="this.onerror=null;this.src=\'mee.jpg\'"><div><div class="u">@' + esc(g.u) + '</div><div class="m">' + esc(g.m) + '</div></div></div>'; });
        if(!OWNER){
            h += '<label class="fvx-lbl" for="fvxGbIn">' + (MINE ? 'Your signature' + (MINE.status === 'pending' ? ' (waiting for approval)' : ' (approved)') : 'Sign the guest book') + '</label>';
            h += '<input class="fvx-in" id="fvxGbIn" maxlength="100" placeholder="One line…" autocomplete="off" value="' + esc(MINE ? MINE.message : '') + '">';
            h += '<button type="button" class="fvx-btn" id="fvxGbBtn" onclick="dzFvxGbSign()">' + (MINE ? 'Update signature' : 'Sign') + '</button>';
            if(MINE) h += '<button type="button" class="fvx-btn alt" onclick="dzFvxGbDel()">Remove my signature</button>';
        }
        return h;
    }
    window.dzFvxGb = function(){ openVis('Guest book', gbRender()); };
    window.dzFvxGbSign = function(){
        var v = ($('fvxGbIn').value || '').trim(); if(!v){ toast('Write one line first'); return; }
        var b = $('fvxGbBtn'); b.disabled = true;
        post({ fvx_action:'gb_sign', owner_id:PID, message:v }).then(function(d){
            if(d.status !== 'ok'){ toast(d.message || 'Could not sign'); b.disabled = false; return; }
            MINE = { message:d.message, status:'pending' };
            $('fvxVisB').innerHTML = gbRender(); toast('Signed — waiting for approval');
        }).catch(function(){ toast('Something went wrong'); b.disabled = false; });
    };
    window.dzFvxGbDel = function(){
        if(!MINE) return;
        post({ fvx_action:'gb_mine_del', owner_id:PID }).then(function(d){
            if(d.status === 'ok'){ MINE = null; $('fvxVisB').innerHTML = gbRender(); toast('Signature removed'); }
            else toast(d.message || 'Could not remove');
        });
    };

    /* ── Translate profile: Google's free page translator (browser-side, no key, no server) ── */
    var LANGS = [['af','Afrikaans','Afrikaans'],['sq','Albanian','Shqip'],['am','Amharic','አማርኛ'],['ar','Arabic','العربية'],['hy','Armenian','Հայերեն'],['az','Azerbaijani','Azərbaycan'],['bn','Bengali','বাংলা'],['bg','Bulgarian','Български'],['my','Burmese','မြန်မာ'],['ca','Catalan','Català'],['zh-CN','Chinese (Simplified)','简体中文'],['zh-TW','Chinese (Traditional)','繁體中文'],['hr','Croatian','Hrvatski'],['cs','Czech','Čeština'],['da','Danish','Dansk'],['nl','Dutch','Nederlands'],['en','English','English'],['et','Estonian','Eesti'],['tl','Filipino','Filipino'],['fi','Finnish','Suomi'],['fr','French','Français'],['de','German','Deutsch'],['el','Greek','Ελληνικά'],['gu','Gujarati','ગુજરાતી'],['ha','Hausa','Hausa'],['he','Hebrew','עברית'],['hi','Hindi','हिन्दी'],['hu','Hungarian','Magyar'],['is','Icelandic','Íslenska'],['ig','Igbo','Igbo'],['id','Indonesian','Bahasa Indonesia'],['it','Italian','Italiano'],['ja','Japanese','日本語'],['kn','Kannada','ಕನ್ನಡ'],['kk','Kazakh','Қазақ'],['km','Khmer','ខ្មែរ'],['ko','Korean','한국어'],['lv','Latvian','Latviešu'],['lt','Lithuanian','Lietuvių'],['ms','Malay','Bahasa Melayu'],['ml','Malayalam','മലയാളം'],['mr','Marathi','मराठी'],['ne','Nepali','नेपाली'],['no','Norwegian','Norsk'],['fa','Persian','فارسی'],['pl','Polish','Polski'],['pt','Portuguese','Português'],['pa','Punjabi','ਪੰਜਾਬੀ'],['ro','Romanian','Română'],['ru','Russian','Русский'],['sr','Serbian','Српски'],['sk','Slovak','Slovenčina'],['sl','Slovenian','Slovenščina'],['so','Somali','Soomaali'],['es','Spanish','Español'],['sw','Swahili','Kiswahili'],['sv','Swedish','Svenska'],['ta','Tamil','தமிழ்'],['te','Telugu','తెలుగు'],['th','Thai','ไทย'],['tr','Turkish','Türkçe'],['uk','Ukrainian','Українська'],['ur','Urdu','اردو'],['uz','Uzbek','Oʻzbek'],['vi','Vietnamese','Tiếng Việt'],['cy','Welsh','Cymraeg'],['yo','Yoruba','Yorùbá'],['zu','Zulu','isiZulu']];
    function gtCur(){ var m = document.cookie.match(/(?:^|;\s*)googtrans=\/[^\/]*\/([^;]+)/); return m ? decodeURIComponent(m[1]) : ''; }
    function gtSet(val){
        var host = location.hostname, parts = host.split('.'), parent = parts.length > 2 ? '.' + parts.slice(-2).join('.') : '';
        var base = 'googtrans=' + (val || '') + ';path=/;' + (val ? '' : 'expires=Thu, 01 Jan 1970 00:00:00 GMT;');
        document.cookie = base;
        document.cookie = base + 'domain=' + host + ';';
        if(parent) document.cookie = base + 'domain=' + parent + ';';
    }
    function gtReload(){ try{ sessionStorage.setItem('fvxScroll', String(window.pageYOffset || 0)); }catch(e){} location.reload(); }
    var GT_ON = gtCur();
    if(GT_ON && /^[a-zA-Z\-]{2,8}$/.test(GT_ON)){
        window.fvxGTInit = function(){ try{ new google.translate.TranslateElement({ pageLanguage:'auto', autoDisplay:false }, 'fvxGT'); }catch(e){} };
        var gs = document.createElement('script'); gs.src = 'https://translate.google.com/translate_a/element.js?cb=fvxGTInit'; gs.async = true; document.head.appendChild(gs);
    }
    try{ var sy = sessionStorage.getItem('fvxScroll'); if(sy !== null){ sessionStorage.removeItem('fvxScroll'); window.addEventListener('load', function(){ setTimeout(function(){ window.scrollTo(0, +sy || 0); }, 400); }); } }catch(e){}
    function langList(q){
        q = (q || '').toLowerCase().trim(); var h = '';
        LANGS.forEach(function(l){
            if(q && (l[1] + ' ' + l[2]).toLowerCase().indexOf(q) < 0) return;
            h += '<div class="fvx-lang' + (l[0] === GT_ON ? ' on' : '') + '" onclick="dzFvxPickLang(\'' + l[0] + '\')"><span>' + esc(l[2]) + '</span><small>' + esc(l[1]) + '</small></div>';
        });
        return h || '<div class="fvx-empty">No language found</div>';
    }
    window.dzFvxTranslate = function(){
        var h = '<p class="fvx-note">Translates the whole page — bio, pills and everything else — with Google Translate. Pick a language; the page reloads translated.</p>';
        if(GT_ON) h += '<button type="button" class="fvx-btn alt flush" onclick="dzFvxOrig()">Show original</button>';
        h += '<input class="fvx-in" id="fvxLangQ" placeholder="Search languages" autocomplete="off" oninput="document.getElementById(\'fvxLangL\').innerHTML=dzFvxLangList(this.value)">';
        h += '<div class="fvx-langs" id="fvxLangL">' + langList('') + '</div>';
        $('fvxVis').classList.add('notranslate'); openVis('Translate profile', h);
    };
    window.dzFvxLangList = langList;
    window.dzFvxPickLang = function(code){ gtSet('/auto/' + code); gtReload(); };
    window.dzFvxOrig = function(){ gtSet(''); gtReload(); };

    /* ── Owner tools ── */
    function ownBody(h){ $('fvxOwnB').innerHTML = h; }
    function setTab(t){ Array.prototype.forEach.call(document.querySelectorAll('#fvxOwn .fvx-tabs button'), function(b){ b.classList.toggle('on', b.getAttribute('data-t') === t); }); }
    window.dzFvxOwnClose = function(){ var o = $('fvxOwn'); if(o) o.classList.remove('open'); };
    window.dzFvxOwnOpen = function(tab){
        var o = $('fvxOwn'); if(!o) return; o.classList.add('open'); tab = tab || 'gb'; setTab(tab);
        if(tab === 'gb'){
            ownBody('<div class="fvx-empty">Loading…</div>');
            post({ fvx_action:'gb_list' }).then(function(d){
                if(d.status !== 'ok'){ ownBody('<div class="fvx-empty">Could not load</div>'); return; }
                var h = '<p class="fvx-note">Signatures from visitors. Only the ones you approve show on your guest book.</p>';
                if(!d.rows.length) h += '<div class="fvx-empty">No signatures yet</div>';
                d.rows.forEach(function(r){
                    h += '<div class="fvx-item" id="fvxG' + r.id + '"><img src="' + esc(r.pic || 'mee.jpg') + '" alt="" onerror="this.onerror=null;this.src=\'mee.jpg\'"><div><div class="u">@' + esc(r.username) + (r.status === 'pending' ? ' · pending' : '') + '</div><div class="m">' + esc(r.message) + '</div></div><div class="act">' +
                         (r.status === 'pending' ? '<button type="button" class="fvx-btn sm" onclick="dzFvxGbMod(' + r.id + ',\'approve\')">Approve</button>' : '') +
                         '<button type="button" class="fvx-btn sm alt" onclick="dzFvxGbMod(' + r.id + ',\'delete\')">Delete</button></div></div>';
                });
                ownBody(h);
            }).catch(function(){ ownBody('<div class="fvx-empty">Could not load</div>'); });
        }
    };
    window.dzFvxGbMod = function(id, what){
        post({ fvx_action:'gb_mod', id:id, 'do':what }).then(function(d){
            if(d.status !== 'ok'){ toast('Could not update'); return; }
            if(what === 'delete'){ var r = $('fvxG' + id); if(r) r.remove(); } else dzFvxOwnOpen('gb');
            toast(what === 'approve' ? 'Approved' : 'Deleted');
        });
    };
})();
</script>

<!-- ──── script: Location ──── -->
<script>
(function(){
    var openedAt = 0, seq = 0, cache = {};
    function $(i){ return document.getElementById(i); }
    function geo(q){
        if(cache[q]) return cache[q];
        return cache[q] = fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(a){ return (a && a[0]) ? { lat: parseFloat(a[0].lat), lon: parseFloat(a[0].lon) } : null; })
            .catch(function(){ return null; });
    }
    function miles(a, b){
        var R = 3958.8, t = Math.PI / 180, dLat = (b.lat - a.lat) * t, dLon = (b.lon - a.lon) * t;
        var h = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(a.lat*t) * Math.cos(b.lat*t) * Math.sin(dLon/2) * Math.sin(dLon/2);
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
    }
    window.dzLocOpen = function(el){
        var loc = el.getAttribute('data-loc') || '', me = el.getAttribute('data-me') || '', own = el.getAttribute('data-own') === '1';
        var sh = $('dzLocSheet'); if(!sh || !loc) return;
        $('dzLocPlace').textContent = loc;
        $('dzLocLink').href = 'earth.php?loc=' + encodeURIComponent(loc);   // opens DezzMap and flies to this place
        var d = $('dzLocDist'), my = ++seq;
        d.hidden = own;
        if(own){ }
        else if(!me){ d.textContent = 'Add your location in About me to see how far away this is.'; }
        else {
            d.textContent = 'Working out the distance…';
            Promise.all([geo(me), geo(loc)]).then(function(r){
                if(my !== seq) return;
                if(!r[0] || !r[1]){ d.textContent = 'Distance not available.'; return; }
                var m = miles(r[0], r[1]);
                d.textContent = m < 1 ? 'Less than 1 mile away' : (m < 10 ? m.toFixed(1) : Math.round(m).toLocaleString()) + ' miles away';
            });
        }
        openedAt = Date.now();
        sh.classList.add('open');
    };
    window.dzLocBack = function(){ if(Date.now() - openedAt > 300) window.dzLocClose(); };
    window.dzLocClose = function(){ var sh = $('dzLocSheet'); if(sh) sh.classList.remove('open'); seq++; };
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') window.dzLocClose(); });
})();
</script>

<!-- ──── script: Full username ──── -->
<script>
(function(){
    var HOLD = 200, MOVE = 10, timer = null, sx = 0, sy = 0, swallow = false, openedAt = 0;
    function open(el){
        var n = document.getElementById('dzUnName'), sh = document.getElementById('dzUnSheet');
        if(!n || !sh) return;
        n.textContent = (el.textContent || '').trim();
        openedAt = Date.now();
        sh.classList.add('open');
    }
    window.dzUnBack = function(){ if(Date.now() - openedAt > 500) window.dzUnSheetClose(); };   /* ignore the tap that ends the hold */
    window.dzUnSheetClose = function(){ var sh = document.getElementById('dzUnSheet'); if(sh) sh.classList.remove('open'); };
    function cancel(){ if(timer){ clearTimeout(timer); timer = null; } }
    function bind(){
        var row = document.querySelector('.rp-username-row'), el = row ? row.querySelector('.rp-username') : null;
        if(!row || !el) return;
        el.addEventListener('pointerdown', function(e){
            if(e.pointerType === 'mouse' && e.button !== 0) return;
            sx = e.clientX; sy = e.clientY; cancel();
            timer = setTimeout(function(){ timer = null; swallow = true; open(el); }, HOLD);
        });
        el.addEventListener('pointermove', function(e){ if(timer && (Math.abs(e.clientX - sx) > MOVE || Math.abs(e.clientY - sy) > MOVE)) cancel(); });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function(t){ el.addEventListener(t, function(){ cancel(); if(swallow) setTimeout(function(){ swallow = false; }, 400); }); });
        window.addEventListener('scroll', cancel, { passive:true });
        el.addEventListener('contextmenu', function(e){ e.preventDefault(); });
        /* the click that ends a hold must not open the account page / account menu */
        row.addEventListener('click', function(e){ if(swallow){ e.preventDefault(); e.stopImmediatePropagation(); } }, true);
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind); else bind();
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') window.dzUnSheetClose(); });
})();
</script>

<!-- ──── script: Shops ──── -->
<?php if (count($dz_shops) > 1): ?>
<script>
function dzShopSheetOpen(){ var e = document.getElementById('dzShopSheet'); if(e) e.classList.add('open'); }
function dzShopSheetClose(){ var e = document.getElementById('dzShopSheet'); if(e) e.classList.remove('open'); }
document.addEventListener('keydown', function(e){ if(e.key === 'Escape') dzShopSheetClose(); });
</script>
<?php endif; ?>


<?php /* ═══════════════════════════════════════════════════════════════════
   PROFILE BOTTOM-SHEET STYLES
   All CSS of the modals above (moved here from the main <style> block of profile.php).
   dz_hoist_styles() lifts this <style> into <head> like every other one.
   ═══════════════════════════════════════════════════════════════════ */ ?>
<style>


/* Block confirmation popup — centred, same dark card as the other sheets */
#blkModal{ align-items:center !important; justify-content:center !important; }
#blkModal .dzm-sheet{ width:min(76vw, 280px); max-height:none; gap:0; padding:0; border-radius:calc(6px * var(--bs-s)); overflow:hidden; text-align:center; animation:dzmPopIn 0.22s cubic-bezier(0.34,1.56,0.64,1); }
.blk-t{ padding:calc(16px * var(--bs-s)) calc(16px * var(--bs-s)) calc(6px * var(--bs-s)); font-size:calc(15px * var(--bs-s)); font-weight:700; line-height:1.3; color:var(--text,#F0F2FF); word-break:break-word; }
.blk-s{ padding:0 calc(16px * var(--bs-s)) calc(14px * var(--bs-s)); font-size:calc(11.5px * var(--bs-s)); line-height:1.5; color:rgba(var(--fg,255,255,255),.6); }
.blk-row{ display:flex; border-top:1px solid rgba(var(--fg,255,255,255),.1); }
.blk-row button{ flex:1 1 0; padding:calc(10px * var(--bs-s)) calc(8px * var(--bs-s)); background:none; border:none; color:var(--text,#F0F2FF); font-size:calc(13.5px * var(--bs-s)); font-weight:600; font-family:inherit; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.blk-row button + button{ border-left:1px solid rgba(var(--fg,255,255,255),.1); }
.blk-row button:hover{ background:rgba(var(--fg,255,255,255),.05); }
.blk-row .blk-go{ color:#FF2D2D; }
@media (max-width:600px){
    #blkModal .dzm-sheet{ width:min(72vw, 250px); border-radius:calc(5px * var(--bs-s)); }
    .blk-t{ padding:calc(13px * var(--bs-s)) calc(13px * var(--bs-s)) calc(5px * var(--bs-s)); font-size:calc(12.5px * var(--bs-s)); }
    .blk-s{ padding:0 calc(13px * var(--bs-s)) calc(11px * var(--bs-s)); font-size:calc(9.5px * var(--bs-s)); line-height:1.45; }
    .blk-row button{ padding:calc(8px * var(--bs-s)) calc(6px * var(--bs-s)); font-size:calc(11px * var(--bs-s)); }}

/* Share profile card */
.shc-wrap{ padding:12px 14px 4px; }
.shc-wrap canvas{ display:block; width:100%; max-height:52vh; object-fit:contain; border-radius:12px; background:var(--ink,#06071A); }
.shc-btns{ display:flex; gap:8px; padding:10px 14px 14px; }
#shcModal .shc-btns{ align-items:center; }
#shcModal .shc-btns button{ flex:1 1 0; height:30px; min-height:0; padding:0 6px; margin:0; border:1.5px solid var(--track-red, #FF2D2D); border-radius:7px; background:var(--track-red, #FF2D2D); color:#000; font-size:.8rem; font-weight:700; line-height:1; font-family:inherit; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:5px; white-space:nowrap; transition:background .2s ease, transform .15s ease; }
#shcModal .shc-btns button i{ font-size:14px; line-height:1; color:#000; }
#shcModal .shc-btns button:hover{ background:var(--track-red-hover, #ff1a1a); border-color:var(--track-red-hover, #ff1a1a); }
#shcModal .shc-btns button:active{ transform:scale(.97); }
.ft-input{ width:100%; box-sizing:border-box; margin:6px 0 2px; padding:12px; border-radius:8px; border:1px solid rgba(var(--fg,255,255,255),.14); background:rgba(var(--fg,255,255,255),.05); color:var(--text,#F0F2FF); font-size:14px; font-family:inherit; outline:none; }
.ft-input:focus{ border-color:#FF2D2D; }
.ft-pad{ padding:4px 16px 8px; }
.ft-count{ text-align:right; font-size:10px; color:rgba(var(--fg,255,255,255),.4); }

/* ══════════════════════════════════════
   ACCOUNT SWITCHER (userKebab)
══════════════════════════════════════ */
#userKebabOverlay {
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.6);
    backdrop-filter:blur(2px);
    -webkit-backdrop-filter:blur(2px);
    z-index:999998;
}
#userKebabOverlay.open { display:block; animation:ukbFadeIn 0.2s ease; }
@keyframes ukbFadeIn { from{opacity:0;} to{opacity:1;} }
@keyframes ukbPopIn  { from{opacity:0; transform:translate(-50%,-46%) scale(0.96);} to{opacity:1; transform:translate(-50%,-50%) scale(1);} }
@keyframes ukbSlideUp{ from{transform:translateY(100%);} to{transform:translateY(0);} }

#userKebab {
    display:none;
    position:fixed;
    z-index:999999;
    top:50%;
    left:50%;
    transform:translate(-50%,-50%);
    width:360px;
    max-width:90vw;
}
#userKebab.open { display:block; }
#userKebab.open .ukb-sheet { animation:ukbPopIn 0.22s cubic-bezier(0.34,1.56,0.64,1); }

#userKebab .ukb-sheet {
    background:rgba(12,12,28,0.97);
    backdrop-filter:blur(20px);
    -webkit-backdrop-filter:blur(20px);
    border:none;
    border-radius:calc(22px * var(--bs-s));
    padding:calc(14px * var(--bs-s)) calc(14px * var(--bs-s)) calc(16px * var(--bs-s));
    box-shadow:0 16px 40px rgba(0,0,0,0.6);
    max-height:80vh;
    display:flex;
    flex-direction:column;
    gap:calc(12px * var(--bs-s));
}
#userKebab .ukb-handle {
    display:none;
    justify-content:center;
    padding:2px 0 2px;
    flex-shrink:0;
}
#userKebab .ukb-handle span {
    width:calc(38px * var(--bs-s)); height:calc(4px * var(--bs-s));
    border-radius:2px;
    background:rgba(var(--fg,255,255,255),0.22);
    display:block;
}
#userKebab .ukb-card {
    background:var(--lane,#070924);
    border:none;
    border-radius:calc(6px * var(--bs-s));
    overflow:hidden;
    flex-shrink:1;
    min-height:0;
    display:flex;
    flex-direction:column;
}
#userKebab .ukb-scroll { overflow-y:auto; }
#userKebab .ukb-header {
    padding:calc(12px * var(--bs-s)) calc(16px * var(--bs-s)) calc(6px * var(--bs-s));
    font-size:calc(10px * var(--bs-s));
    color:rgba(var(--fg,255,255,255),0.35);
    letter-spacing:1px;
    text-transform:uppercase;
    flex-shrink:0;
}
#userKebab .ukb-row-wrap { display:flex; align-items:center; position:relative; }
#userKebab .ukb-row {
    display:flex;
    align-items:center;
    gap:calc(10px * var(--bs-s));
    padding:calc(10px * var(--bs-s)) calc(14px * var(--bs-s));
    text-decoration:none;
    color:white;
    transition:background 0.2s;
    flex:1;
    min-width:0;
}
#userKebab .ukb-avatar-wrap { position:relative; flex-shrink:0; }
#userKebab .ukb-avatar {
    width:calc(34px * var(--bs-s)); height:calc(34px * var(--bs-s));
    border-radius:50%;
    border:1.5px solid #FF2D2D;
    object-fit:cover;
    display:block;
}
#userKebab .ukb-dot {
    position:absolute;
    top:-2px; right:-2px;
    width:calc(9px * var(--bs-s)); height:calc(9px * var(--bs-s));
    background:#FF2D2D;
    border-radius:50%;
    border:1.5px solid rgba(20,20,30,0.95);
}
#userKebab .ukb-info { flex:1; min-width:0; }
#userKebab .ukb-name {
    font-size:calc(13px * var(--bs-s)); font-weight:600;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
#userKebab .ukb-sub { font-size:calc(10px * var(--bs-s)); }
#userKebab .unlink-btn {
    opacity:0;
    transition:opacity 0.2s;
    padding:0 14px 0 4px;
    color:rgba(var(--fg,255,255,255),0.3);
    font-size:16px;
    text-decoration:none;
    flex-shrink:0;
    display:flex;
    align-items:center;
}
#userKebab .ukb-divider { border-top:1px solid rgba(var(--fg,255,255,255),0.08); margin:calc(4px * var(--bs-s)) 0; flex-shrink:0; }
#userKebab .ukb-add-row {
    display:flex;
    align-items:center;
    gap:calc(10px * var(--bs-s));
    padding:calc(10px * var(--bs-s)) calc(14px * var(--bs-s));
    text-decoration:none;
    color:rgba(var(--fg,255,255,255),0.75);
    transition:background 0.2s;
}
#userKebab .ukb-add-icon {
    width:calc(32px * var(--bs-s)); height:calc(32px * var(--bs-s));
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}
#userKebab .ukb-logout-btn {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:calc(8px * var(--bs-s));
    width:100%;
    padding:calc(14px * var(--bs-s));
    text-align:center;
    background:var(--lane,#070924);
    border:none;
    border-radius:calc(6px * var(--bs-s));
    color:var(--text,#F0F2FF);
    font-size:calc(14px * var(--bs-s));
    font-weight:600;
    cursor:pointer;
    transition:background 0.2s, border-color 0.2s, color 0.2s;
    font-family:inherit;
    flex-shrink:0;
}
#userKebab .ukb-logout-btn:hover {
    background:#0c0f38;
    color:#FF2D2D;
}

@media (max-width:790px) {
    #userKebab {
        top:auto; bottom:0; left:0; right:0;
        transform:none; width:100%; max-width:100%;
        padding:0; box-sizing:border-box;
    }
    #userKebab.open .ukb-sheet { animation:ukbSlideUp 0.28s cubic-bezier(0.32,0.72,0,1); }
    #userKebab .ukb-handle { display:flex; }
    #userKebab .unlink-btn { opacity:1 !important; }}
/* boxy (not pill / not very round), #070924, no borders — all screen sizes */
#userKebab .ukb-sheet{ border:none !important; background:var(--lane,#070924) !important; }                 /* the modal card */
#userKebab .ukb-card{ border:none !important; border-radius:calc(6px * var(--bs-s)) !important; background:var(--surface-3,#090b29) !important; }   /* inner area: a slightly lighter shade than the modal */
#userKebab .ukb-logout-btn{ border:none !important; border-radius:calc(6px * var(--bs-s)) !important; background:var(--surface-3,#090b29) !important; }
#userKebab .ukb-logout-btn:hover{ background:#0e1136 !important; }
/* NORMAL view: boxier / sharper, but still slightly rounded */
@media (min-width:791px){
    #userKebab .ukb-sheet{ border-radius:calc(8px * var(--bs-s)) !important; }
    #userKebab .ukb-card, #userKebab .ukb-logout-btn{ border-radius:calc(4px * var(--bs-s)) !important; }}
#userKebab .ukb-logout-btn{ color:#FF2D2D !important; font-weight:700 !important; }
#userKebab .ukb-logout-btn i{ color:#FF2D2D; }

/* RESPONSIVE: noticeably smaller linked-accounts sheet */
@media (max-width:790px){
    #userKebab .ukb-sheet{ max-height:60vh; padding:calc(5px * var(--bs-s)) calc(10px * var(--bs-s)) calc(env(safe-area-inset-bottom,0px) + calc(10px * var(--bs-s))); gap:calc(8px * var(--bs-s)); border-radius:0 !important; }
    #userKebab .ukb-handle span{ width:calc(30px * var(--bs-s)); height:calc(3px * var(--bs-s)); }
    #userKebab .ukb-header{ font-size:calc(8.5px * var(--bs-s)); padding:calc(7px * var(--bs-s)) calc(12px * var(--bs-s)) calc(3px * var(--bs-s)); }
    #userKebab .ukb-row{ padding:calc(6px * var(--bs-s)) calc(12px * var(--bs-s)); gap:calc(9px * var(--bs-s)); }
    #userKebab .ukb-avatar{ width:calc(27px * var(--bs-s)); height:calc(27px * var(--bs-s)); }
    #userKebab .ukb-dot{ width:calc(8px * var(--bs-s)); height:calc(8px * var(--bs-s)); }
    #userKebab .ukb-name{ font-size:calc(11.5px * var(--bs-s)); }
    #userKebab .ukb-sub{ font-size:calc(9px * var(--bs-s)); }
    #userKebab .ukb-row > i{ font-size:calc(14px * var(--bs-s)) !important; }
    #userKebab .unlink-btn{ font-size:14px; padding:0 12px 0 4px; }
    #userKebab .ukb-add-row{ padding:calc(6px * var(--bs-s)) calc(12px * var(--bs-s)); gap:calc(9px * var(--bs-s)); }
    #userKebab .ukb-add-icon{ width:calc(25px * var(--bs-s)); height:calc(25px * var(--bs-s)); }
    #userKebab .ukb-add-icon i{ font-size:calc(15px * var(--bs-s)) !important; }
    #userKebab .ukb-add-row span:first-child{ font-size:calc(11.5px * var(--bs-s)) !important; }
    #userKebab .ukb-add-row span:last-child{ font-size:calc(9px * var(--bs-s)) !important; }
    #userKebab .ukb-divider{ margin:2px 0; }
    #userKebab .ukb-logout-btn{ padding:calc(10px * var(--bs-s)); font-size:calc(12px * var(--bs-s)); gap:calc(6px * var(--bs-s)); }}

/* ══════════════════════════════════════════════════════════════
   SHARED "LINKED-ACCOUNTS LOOK" for the Vibe Check, Edit Picture,
   Profile Links and Untrack (Tracking) modals.
   Same overlay, same var(--lane,#070924) sheet, same slightly lighter #090b29
   inner card, same boxy corners, same sizing:
     • normal view     → 360px centred modal (8px corners)
     • responsive view → compact bottom sheet (max 60vh, square top)
══════════════════════════════════════════════════════════════ */
#vibePopup, #epPicModal, #profileLinksOverlay, #mrSheet, #colOptsDd, #colNameModal, #pmSheet, #dpModal, #mfSheet, #sfSheet, #ftModal, #blkModal, #shcModal, #bkColSheet{
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,0.6);
    backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px);
    align-items:center; justify-content:center;
    isolation:isolate;
}
#vibePopup{ z-index:2147483647; }
#mrSheet, #colOptsDd, #colNameModal, #pmSheet, #dpModal, #mfSheet, #sfSheet, #ftModal, #blkModal, #shcModal, #bkColSheet{ z-index:2147483647; }
#profileLinksOverlay{ z-index:999999999; }
#epPicModal{ z-index:999999; }
#vibePopup.open, #epPicModal.open, #profileLinksOverlay.open, #mrSheet.open, #colOptsDd.open, #colNameModal.open, #pmSheet.open, #dpModal.open, #mfSheet.open, #sfSheet.open, #ftModal.open, #blkModal.open, #shcModal.open, #bkColSheet.open{ display:flex; animation:ukbFadeIn 0.2s ease; }
@keyframes dzmPopIn{ from{ opacity:0; transform:translateY(10px) scale(0.96); } to{ opacity:1; transform:none; } }

.dzm-sheet{
    position:relative;
    width:360px; max-width:90vw; max-height:80vh;
    background:var(--lane,#070924); border:none; border-radius:calc(8px * var(--bs-s));
    padding:calc(14px * var(--bs-s)) calc(14px * var(--bs-s)) calc(16px * var(--bs-s));
    box-shadow:0 16px 40px rgba(0,0,0,0.6);
    display:flex; flex-direction:column; gap:calc(12px * var(--bs-s));
    animation:dzmPopIn 0.22s cubic-bezier(0.34,1.56,0.64,1);
}
.dzm-handle{ display:none; justify-content:center; padding:2px 0; flex-shrink:0; }
.dzm-handle span{ width:calc(38px * var(--bs-s)); height:calc(4px * var(--bs-s)); border-radius:2px; background:rgba(var(--fg,255,255,255),0.22); display:block; }
.dzm-card{
    background:var(--surface-3,#090b29); border:none; border-radius:calc(4px * var(--bs-s));
    overflow:hidden; flex-shrink:1; min-height:0;
    display:flex; flex-direction:column;
}
.dzm-scroll{
    overflow-y:auto; min-height:0;
    scrollbar-width:thin; scrollbar-color:rgba(255,255,255,0.15) transparent;
}
.dzm-scroll::-webkit-scrollbar{ width:calc(4px * var(--bs-s)); }
.dzm-scroll::-webkit-scrollbar-thumb{ background:rgba(255,255,255,0.15); border-radius:999px; }
.dzm-header{
    padding:calc(12px * var(--bs-s)) calc(16px * var(--bs-s)) calc(6px * var(--bs-s));
    font-size:calc(10px * var(--bs-s)); color:rgba(var(--fg,255,255,255),0.35);
    letter-spacing:1px; text-transform:uppercase; flex-shrink:0;
}
.dzm-sub{
    padding:0 calc(16px * var(--bs-s)) calc(10px * var(--bs-s));
    font-size:calc(11px * var(--bs-s)); color:rgba(var(--fg,255,255,255),0.5); line-height:1.5;
}
.dzm-close-btn{
    display:flex; align-items:center; justify-content:center; gap:calc(8px * var(--bs-s));
    width:100%; padding:calc(14px * var(--bs-s));
    background:var(--surface-3,#090b29); border:none; border-radius:calc(4px * var(--bs-s));
    color:rgba(var(--fg,255,255,255),0.85); font-size:calc(14px * var(--bs-s)); font-weight:600;
    cursor:pointer; font-family:inherit; flex-shrink:0;
    transition:background 0.2s, color 0.2s;
}
.dzm-close-btn:hover{ background:#0e1136; color:#FF2D2D; }


/* ══════════════════════════════════════════════════════════════
   PROFILE LINKS MODAL — content of the shared linked-accounts look (.dzm-*)
   Rows are built like the account rows: round icon, name, small url, chevron.
══════════════════════════════════════════════════════════════ */
.pl-item{
    display:flex; align-items:center; gap:calc(10px * var(--bs-s));
    padding:calc(10px * var(--bs-s)) calc(14px * var(--bs-s)); text-decoration:none; color:var(--text,#F0F2FF);
    transition:background 0.2s;
}
.pl-item:hover{ background:rgba(var(--fg,255,255,255),0.06); }
.pl-icon{
    width:34px; height:34px; border-radius:50%; flex-shrink:0;
    border:1.5px dashed rgba(var(--fg,255,255,255),0.3);
    display:flex; align-items:center; justify-content:center;
}
.pl-icon i{ font-size:17px; color:rgba(var(--fg,255,255,255),0.6); }
.pl-info{ flex:1; min-width:0; }
.pl-item-title{ font-size:13px; font-weight:600; color:var(--text,#F0F2FF); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pl-item-url{ font-size:10px; color:rgba(var(--fg,255,255,255),0.4); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pl-chevron{ font-size:16px; color:rgba(var(--fg,255,255,255),0.3); flex-shrink:0; }
@media (max-width:790px){
    .pl-item{ padding:6px 12px; gap:9px; }
    .pl-icon{ width:27px; height:27px; }
    .pl-icon i{ font-size:14px; }
    .pl-item-title{ font-size:11.5px; }
    .pl-item-url{ font-size:9px; }
    .pl-chevron{ font-size:14px; }}

/* ══════════════════════════════════════════════════════════════
   UNTRACK / TRACKING SHEET (#mrSheet) — content inside the shared modal look.
   Structure: #mrSheet › .dzm-sheet › .dzm-card › .dzm-scroll ›
              (.dzm-header, .mr-user-row, .mr-row ×3), then .dzm-close-btn.
   Rows match the account / link rows: round dashed icon, label, small sub text.
══════════════════════════════════════════════════════════════ */
.mr-user-row{ display:flex; align-items:center; gap:calc(10px * var(--bs-s)); padding:calc(10px * var(--bs-s)) calc(14px * var(--bs-s)); }
.mr-user-pic{
    width:calc(34px * var(--bs-s)) !important; height:calc(34px * var(--bs-s)) !important;
    border-radius:50%; object-fit:cover; flex-shrink:0;
    border:1.5px solid #FF2D2D;
}
.mr-username-text{
    font-size:calc(13px * var(--bs-s)); font-weight:600; color:var(--text,#F0F2FF); text-transform:none;
    letter-spacing:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    min-width:0;
}

.mr-row{
    display:flex; align-items:center; gap:calc(10px * var(--bs-s)); padding:calc(10px * var(--bs-s)) calc(14px * var(--bs-s)); cursor:pointer;
    border-top:1px solid rgba(var(--fg,255,255,255),.08); transition:background .2s;
    -webkit-tap-highlight-color:transparent;
}
.mr-row:hover{ background:rgba(var(--fg,255,255,255),.06); }
.mr-icon{
    width:calc(34px * var(--bs-s)); height:calc(34px * var(--bs-s)); border-radius:50%; flex-shrink:0;
    border:1.5px dashed rgba(var(--fg,255,255,255),.3);
    display:flex; align-items:center; justify-content:center;
    font-size:calc(17px * var(--bs-s)); color:rgba(var(--fg,255,255,255),.6);
}
.mr-label{ font-size:calc(13px * var(--bs-s)); font-weight:600; color:var(--text,#F0F2FF); }
.mr-label.red{ color:#FF2D2D; }
.mr-sub{ font-size:calc(10px * var(--bs-s)); color:rgba(var(--fg,255,255,255),.4); margin-top:1px; line-height:1.35; }
.mr-state-badge{
    margin-left:auto; flex-shrink:0; font-size:calc(9.5px * var(--bs-s)); font-weight:700;
    padding:calc(3px * var(--bs-s)) calc(8px * var(--bs-s)); border-radius:calc(4px * var(--bs-s)); letter-spacing:.3px;
}
.mr-state-badge.on-mute, .mr-state-badge.on-restrict{ background:rgba(255,45,45,.1); color:#FF2D2D; }

@media (max-width:790px){
    .mr-user-row, .mr-row{ padding:calc(6px * var(--bs-s)) calc(12px * var(--bs-s)); gap:calc(9px * var(--bs-s)); }
    .mr-user-pic, .mr-icon{ width:calc(27px * var(--bs-s)); height:calc(27px * var(--bs-s)); }
    .mr-user-pic{ width:calc(27px * var(--bs-s)) !important; height:calc(27px * var(--bs-s)) !important; }
    .mr-icon{ font-size:calc(14px * var(--bs-s)); }
    .mr-username-text, .mr-label{ font-size:calc(11.5px * var(--bs-s)); }
    .mr-sub{ font-size:calc(9px * var(--bs-s)); }}

/* RESPONSIVE: the same compact bottom sheet as the linked-accounts modal */
@media (max-width:790px){
    #vibePopup, #epPicModal, #profileLinksOverlay, #mrSheet, #colOptsDd, #colNameModal, #pmSheet, #dpModal, #mfSheet, #sfSheet, #ftModal, #blkModal, #shcModal, #bkColSheet{ align-items:flex-end; }
    .dzm-sheet{
        width:100%; max-width:100%; max-height:60vh;
        border-radius:0;
        padding:calc(5px * var(--bs-s)) calc(10px * var(--bs-s)) calc(env(safe-area-inset-bottom,0px) + calc(10px * var(--bs-s)));
        gap:calc(8px * var(--bs-s));
        animation:ukbSlideUp 0.28s cubic-bezier(0.32,0.72,0,1);
    }
    .dzm-handle{ display:flex; }
    .dzm-handle span{ width:calc(30px * var(--bs-s)); height:calc(3px * var(--bs-s)); }
    .dzm-card{ border-radius:calc(6px * var(--bs-s)); }
    .dzm-header{ font-size:calc(8.5px * var(--bs-s)); padding:calc(7px * var(--bs-s)) calc(12px * var(--bs-s)) calc(3px * var(--bs-s)); }
    .dzm-sub{ font-size:calc(10px * var(--bs-s)); padding:0 calc(12px * var(--bs-s)) calc(8px * var(--bs-s)); }
    .dzm-close-btn{ padding:calc(10px * var(--bs-s)); font-size:calc(12px * var(--bs-s)); gap:calc(6px * var(--bs-s)); border-radius:calc(6px * var(--bs-s)); }}

/* ══════════════════════════════════════════════════════════════
   COLLECTION + POST MODALS — all built on the shared .dzm-* look
   (same sheet / card / header / close button as Vibe Check, Accounts, Links, Untrack)
   Collection options #colOptsDd · New / Rename #colNameModal · Save-to-Collection #svshOverlay
   Post options #pmSheet · Delete post #dpModal
   Overlay / open / bottom-sheet rules for these ids live in the shared lists above.
══════════════════════════════════════════════════════════════ */

/* ── option rows (Collection options, Post options) ── */
.dzm-item{
    display:flex; align-items:center; gap:calc(10px * var(--bs-s)); padding:calc(10px * var(--bs-s)) calc(14px * var(--bs-s)); cursor:pointer;
    border-top:1px solid rgba(255,255,255,.08); transition:background .2s;
    -webkit-tap-highlight-color:transparent;
}
.dzm-header + .dzm-item{ border-top:none; }
.dzm-item:hover{ background:rgba(var(--fg,255,255,255),.06); }
.dzm-item-ic{
    width:calc(34px * var(--bs-s)); height:calc(34px * var(--bs-s)); border-radius:50%; flex-shrink:0;
    border:1.5px dashed rgba(var(--fg,255,255,255),.3);
    display:flex; align-items:center; justify-content:center;
    font-size:calc(17px * var(--bs-s)); color:rgba(var(--fg,255,255,255),.6);
}
.dzm-item-tx{ flex:1; min-width:0; }
.dzm-item-t{ font-size:calc(13px * var(--bs-s)); font-weight:600; color:var(--text,#F0F2FF); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dzm-item-s{ font-size:calc(10px * var(--bs-s)); color:rgba(var(--fg,255,255,255),.4); margin-top:1px; line-height:1.35; }
.dzm-item.danger .dzm-item-t{ color:#FF2D2D; }
.dzm-item.danger .dzm-item-ic{ color:#FF2D2D; border-color:rgba(255,45,45,.45); }
#colOptsTitle{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* ── form pieces (New / Rename collection, Save sheet "create" screen, Delete post) ── */
.cm-field{ padding:4px 14px 10px; }
.cm-input{
    width:100%; background:rgba(var(--fg,255,255,255),.06); border:none; border-radius:4px;
    padding:10px 12px; color:var(--text,#F0F2FF); font-size:13px; font-family:Poppins,sans-serif;
    outline:none; box-sizing:border-box; transition:background .2s;
}
.cm-input:focus{ background:rgba(var(--fg,255,255,255),.1); }
.cm-input::placeholder{ color:rgba(var(--fg,255,255,255),.25); }

.cm-row{
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding:10px 14px; border-top:1px solid rgba(var(--fg,255,255,255),.08);
}
.cm-row-tx{ min-width:0; }
.cm-row-t{ font-size:12.5px; font-weight:600; color:var(--text,#F0F2FF); }
.cm-row-s{ font-size:10.5px; color:rgba(var(--fg,255,255,255),.45); margin-top:2px; line-height:1.4; }

.cm-actions{ padding:6px 14px 14px; }
.cm-save{
    display:flex; align-items:center; justify-content:center; width:100%;
    height:var(--rp-action-h); padding:0 14px; border:none; border-radius:var(--rp-action-radius);
    background:var(--track-red); color:#000; font-size:.72rem; font-weight:700; font-family:inherit; line-height:1;
    transition:background .2s, transform .15s;
}
.cm-save:hover{ background:var(--track-red-hover); }
.cm-save:active{ transform:scale(.98); }
.cm-save:disabled{ opacity:.5; pointer-events:none; }
.cm-save.cm-danger{ background:#FF2D2D; color:#fff; }
.cm-save.cm-danger:hover{ background:#ff1a1a; }

/* on/off switch — same look as the Vibe Check switch */
.col-vis-toggle{ position:relative; width:calc(44px * var(--cm-s)); height:calc(25px * var(--cm-s)); flex-shrink:0; display:block; }
.col-vis-toggle input{ opacity:0; width:0; height:0; position:absolute; }
.col-vis-slider{
    position:absolute; inset:0; border-radius:calc(99px * var(--cm-s)); background:rgba(var(--fg,255,255,255),.18);
    cursor:pointer; transition:background .22s ease;
}
.col-vis-slider::before{
    content:''; position:absolute; top:calc(3px * var(--cm-s)); left:calc(3px * var(--cm-s)); width:calc(19px * var(--cm-s)); height:calc(19px * var(--cm-s)); border-radius:50%;
    background:#fff; transition:transform .22s ease;
}
.col-vis-toggle input:checked + .col-vis-slider{ background:var(--red); }
.col-vis-toggle input:checked + .col-vis-slider::before{ transform:translateX(19px); }

/* ── Delete post warning ── */
.dp-warn{ text-align:center; padding:8px 18px 14px; }
.dp-warn-ic{
    width:44px; height:44px; margin:4px auto 10px; border-radius:50%;
    background:rgba(255,45,45,.12); color:#FF2D2D;
    display:flex; align-items:center; justify-content:center; font-size:24px;
}
.dp-warn-t{ font-size:14px; font-weight:700; color:var(--text,#F0F2FF); }
.dp-warn-s{ font-size:11px; color:rgba(var(--fg,255,255,255),.5); line-height:1.6; margin-top:6px; }


/* ── responsive: same compact sizes as the Accounts / Links / Untrack sheets ── */
@media (max-width:790px){
    .dzm-item{ padding:calc(6px * var(--bs-s)) calc(12px * var(--bs-s)); gap:calc(9px * var(--bs-s)); }
    .dzm-item-ic{ width:calc(27px * var(--bs-s)); height:calc(27px * var(--bs-s)); font-size:calc(14px * var(--bs-s)); }
    .dzm-item-t{ font-size:calc(11.5px * var(--bs-s)); }
    .dzm-item-s{ font-size:calc(9px * var(--bs-s)); }
    .cm-field{ padding:2px 12px 8px; }
    .cm-input{ font-size:11.5px; padding:8px 11px; }
    .cm-row{ padding:8px 12px; }
    .cm-row-t{ font-size:11.5px; }
    .cm-row-s{ font-size:9.5px; }
    .cm-actions{ padding:4px 12px 12px; }
    .col-vis-toggle{ width:calc(40px * var(--cm-s)); height:calc(23px * var(--cm-s)); }
    .col-vis-slider::before{ width:calc(17px * var(--cm-s)); height:calc(17px * var(--cm-s)); }
    .col-vis-toggle input:checked + .col-vis-slider::before{ transform:translateX(17px); }
    .dp-warn{ padding:6px 14px 12px; }
    .dp-warn-ic{ width:38px; height:38px; font-size:21px; }
    .dp-warn-t{ font-size:12.5px; }
    .dp-warn-s{ font-size:10px; }}

/* ══════════════════════════════════════
   VIBE CHECK — content inside the shared modal look above
══════════════════════════════════════ */
.vibe-result{
    display:none; text-align:center; padding:4px 16px 14px;
}
.vibe-result-label{ color:rgba(var(--fg,255,255,255),0.35); font-size:9.5px; letter-spacing:1px; text-transform:uppercase; margin:0 0 6px; }
.vibe-result-text{ color:var(--text,#F0F2FF); font-size:17px; font-weight:700; margin:0; word-break:break-word; }
.vibe-result-total{ color:rgba(var(--fg,255,255,255),0.4); font-size:10.5px; margin:6px 0 0; }
.vibe-breakdown{ display:none; margin:12px auto 0; max-width:260px; text-align:left; }
.vibe-breakdown.show{ display:block; }

.vibe-grid{ display:grid; grid-template-columns:1fr 1fr; gap:6px; padding:2px 12px 14px; }
/* UNDO my vote — boxy grey button, same look as the action buttons */
.vibe-undo-row{ display:none; padding:0 12px 14px; }
.vibe-undo{
    display:flex; align-items:center; justify-content:center; width:100%;
    height:var(--rp-action-h); border:none; border-radius:4px;
    background:rgba(var(--fg,255,255,255),0.1); color:var(--text,#F0F2FF); font-size:11.5px; font-weight:600; font-family:inherit;
    transition:background .2s ease, color .2s ease, transform .15s ease;
}
.vibe-undo:hover{ background:rgba(var(--fg,255,255,255),0.2); color:var(--red); }
.vibe-undo:active{ transform:scale(.98); }
.vibe-undo:disabled{ opacity:.5; pointer-events:none; }

/* owner-only on/off switch row */
.vibe-owner{
    display:none; align-items:center; justify-content:space-between; gap:12px;
    padding:10px 16px 12px;
    border-top:1px solid rgba(var(--fg,255,255,255),0.08);
}
.vibe-owner-txt{ min-width:0; }
.vibe-owner-t{ font-size:12.5px; font-weight:600; color:var(--text,#F0F2FF); }
.vibe-owner-s{ font-size:10.5px; color:rgba(var(--fg,255,255,255),0.45); margin-top:2px; line-height:1.4; }@media (max-width:790px){
    .vibe-result{ padding:2px 12px 10px; }
    .vibe-result-label{ font-size:8.5px; margin-bottom:4px; }
    .vibe-result-text{ font-size:15px; }
    .vibe-result-total{ font-size:9.5px; margin-top:4px; }
    .vibe-grid{ gap:5px; padding:2px 10px 10px; }
    .vibe-owner{ padding:8px 12px 10px; }
    .vibe-owner-t{ font-size:11.5px; }
    .vibe-owner-s{ font-size:9.5px; }}

/* ══════════════════════════════════════
   EDIT PICTURE — content inside the shared modal look above
   (only used when the owner has NO posts yet — owners with posts get Dashboard)
══════════════════════════════════════ */
.ep-pic-wrap{
    width:96px; height:96px; margin:6px auto 12px; position:relative;
}
.ep-pic-wrap img{
    width:100%; height:100%; border-radius:50%; object-fit:cover; display:block;
    border:2px solid #FF2D2D;
}
#epFileInput{ display:none; }

.ep-row{
    display:flex; align-items:center; gap:10px; width:100%;
    padding:10px 14px; background:none; border:none;
    border-top:1px solid rgba(var(--fg,255,255,255),0.08);
    color:rgba(var(--fg,255,255,255),0.85); text-align:left; font-family:inherit;
    transition:background .2s;
}
.ep-row:hover{ background:rgba(var(--fg,255,255,255),0.06); }
.ep-row-ic{
    width:32px; height:32px; border-radius:50%; flex-shrink:0;
    border:1.5px dashed rgba(var(--fg,255,255,255),0.3);
    display:flex; align-items:center; justify-content:center;
}
.ep-row-ic i{ font-size:17px; color:rgba(var(--fg,255,255,255),0.6); }
.ep-row-t{ font-size:13px; font-weight:500; }

.ep-preview-area{
    display:none; flex-direction:column; align-items:center; gap:10px;
    padding:12px 14px 14px;
    border-top:1px solid rgba(var(--fg,255,255,255),0.08);
}
.ep-preview-area.visible{ display:flex; }
.ep-preview-area img{
    width:72px; height:72px; border-radius:50%; object-fit:cover;
    border:2px solid #FF2D2D;
}
.ep-preview-label{
    font-size:9px; color:rgba(var(--fg,255,255,255),0.35);
    text-transform:uppercase; letter-spacing:1px; font-weight:700;
}
.ep-preview-actions{ display:flex; gap:8px; width:100%; }
.ep-btn{
    flex:1; height:28px; border:none; border-radius:4px;
    font-size:.7rem; font-weight:700; font-family:inherit; line-height:1;
    transition:background .2s, transform .15s;
}
.ep-btn:active{ transform:scale(.98); }
.ep-btn.save{ background:var(--track-red); color:#000; }
.ep-btn.save:hover{ background:var(--track-red-hover); }
.ep-btn.save:disabled{ opacity:.5; pointer-events:none; }
.ep-btn.cancel{ background:rgba(var(--fg,255,255,255),0.1); color:var(--text,#F0F2FF); }
.ep-btn.cancel:hover{ background:rgba(var(--fg,255,255,255),0.2); }

.ep-hint{
    text-align:center; font-size:10px; color:rgba(var(--fg,255,255,255),0.3);
    padding:10px 16px 12px; line-height:1.5;
    border-top:1px solid rgba(var(--fg,255,255,255),0.08);
}

/* Toast for the edit picture modal */
.ep-toast {
    position: fixed;
    bottom: 30px; left: 50%;
    transform: translateX(-50%) translateY(10px);
    background: rgba(8,8,22,.97);
    border: 1px solid rgba(255,45,45,.25);
    color: #fff; padding: 8px 20px; border-radius: 50px;
    font-size: 12px; font-family: 'Poppins', sans-serif; font-weight: 500;
    z-index: 9999999; pointer-events: none;
    box-shadow: 0 8px 28px rgba(0,0,0,.65);
    white-space: nowrap; opacity: 0;
    transition: opacity .22s ease, transform .22s ease;
}
.ep-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

@media (max-width:790px){
    .ep-pic-wrap{ width:80px; height:80px; margin:4px auto 10px; }
    .ep-row{ padding:8px 12px; gap:9px; }
    .ep-row-ic{ width:27px; height:27px; }
    .ep-row-ic i{ font-size:15px; }
    .ep-row-t{ font-size:11.5px; }
    .ep-preview-area{ padding:10px 12px 12px; gap:8px; }
    .ep-preview-area img{ width:62px; height:62px; }
    .ep-btn{ height:26px; font-size:.64rem; }
    .ep-hint{ font-size:9px; padding:8px 12px 10px; }}

/* ══ small blocks moved up from inline styles in the body ══ */
.dz-fx1{ flex:1; min-width:0; }
.dz-fx1b{ flex:1; }
.dz-blue{ color:#2b67f5; }
.dz-chk{ color:#2b67f5; font-size:16px; flex-shrink:0; }
.ukb-add-row.dz-add-off{ color:rgba(var(--fg,255,255,255),0.25); cursor:not-allowed; }
.ukb-add-icon.dz-addic-off{ border:1.5px dashed rgba(var(--fg,255,255,255),0.15); }
.ukb-add-icon.dz-addic-on{ border:1.5px dashed rgba(var(--fg,255,255,255),0.3); }
.dz-addi-off{ font-size:18px; color:rgba(var(--fg,255,255,255),0.2); }
.dz-addi-on{ font-size:18px; color:rgba(var(--fg,255,255,255),0.5); }
.dz-addt{ font-size:13px; font-weight:500; display:block; }
.dz-addlim{ font-size:10px; color:rgba(var(--fg,255,255,255),0.25); }
/* while the Guide is open the sheet only offers the view switch */
#mfSheet.mf-info .dzm-header:not(.mf-keep), #mfSheet.mf-info .dzm-item:not(.mf-view){ display:none; }
.mf-check{ margin-left:auto; font-size:18px; color:#FF2D2D; display:none; }
.dzm-item.mf-on .mf-check{ display:block; }
.dzm-item.mf-on .dzm-item-ic{ border-style:solid; border-color:#FF2D2D; color:#FF2D2D; }

/* owner editor */
#ctaModal{ display:none; position:fixed; inset:0; z-index:2147483647; align-items:center; justify-content:center; background:rgba(0,0,0,.6); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px); isolation:isolate; }
#ctaModal.open{ display:flex; animation:ukbFadeIn .2s ease; }
@media (max-width:790px){ #ctaModal{ align-items:flex-end; }}
.cta-body{ padding:4px 16px 14px; white-space:normal; }
.cta-lbl{ display:block; margin:12px 0 6px; font-size:10px; letter-spacing:.8px; text-transform:uppercase; color:rgba(var(--fg,255,255,255),.4); }
.cta-in{ width:100%; box-sizing:border-box; padding:10px 12px; border-radius:6px; border:1px solid rgba(var(--fg,255,255,255),.12); background:var(--lane,#070924); color:var(--text,#F0F2FF); font-size:14px; font-family:inherit; outline:none; }
.cta-in:focus{ border-color:rgba(var(--fg,255,255,255),.35); }
.cta-sw{ display:flex; flex-wrap:wrap; gap:8px; }
.cta-sw button{ width:26px; height:26px; border-radius:50%; border:2px solid transparent; padding:0; cursor:pointer; background:var(--c); outline:none; box-shadow:0 0 0 2px var(--surface-3,#090b29) inset; -webkit-tap-highlight-color:transparent; }
.cta-sw button.on{ border-color:#fff; }
.cta-pic-row{ display:flex; align-items:center; gap:10px; }
.cta-pic-prev{ width:44px; height:44px; border-radius:8px; object-fit:cover; background:var(--lane,#070924); border:1px solid rgba(var(--fg,255,255,255),.12); flex-shrink:0; }
.cta-pic-btn, .cta-pic-x{ padding:8px 12px; border:none; border-radius:6px; font-size:12px; font-weight:600; font-family:inherit; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.cta-pic-btn{ background:rgba(var(--fg,255,255,255),.1); color:var(--text,#F0F2FF); }
.cta-pic-x{ background:none; color:rgba(var(--fg,255,255,255),.55); padding:8px 4px; }
.cta-pic-hint{ font-size:10px; color:rgba(var(--fg,255,255,255),.38); margin-top:6px; }
.cta-prev{ margin-top:14px; }
.cta-prev .dz-cta{ position:static; transform:none; opacity:1; animation:none; width:100%; max-width:none; box-shadow:none; pointer-events:none; cursor:default; }
.cta-note{ margin:0 0 4px; font-size:11px; line-height:1.5; color:rgba(var(--fg,255,255,255),.5); }
.cta-warn{ margin:10px 0 0; padding:9px 11px; border-radius:6px; background:rgba(255,45,45,.12); color:#ff8a8a; font-size:11.5px; line-height:1.45; display:none; }
.cta-warn.show{ display:block; }
.cta-act{ display:flex; gap:8px; margin-top:14px; }
.cta-act button{ flex:1; padding:11px; border:none; border-radius:6px; font-size:13.5px; font-weight:600; font-family:inherit; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.cta-save{ background:#FF2D2D; color:#fff; }
.cta-save:disabled{ opacity:.45; cursor:not-allowed; }
.cta-del{ background:rgba(var(--fg,255,255,255),.08); color:rgba(var(--fg,255,255,255),.85); }
@media (max-width:790px){ .cta-in{ font-size:13px; padding:9px 11px; } .cta-lbl{ font-size:9px; } .cta-sw button{ width:24px; height:24px; } .cta-act button{ font-size:12.5px; padding:10px; }}
#fvxVis, #fvxOwn{ display:none; position:fixed; inset:0; z-index:2147483647; align-items:center; justify-content:center; background:rgba(0,0,0,.6); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px); isolation:isolate; }
#fvxVis.open, #fvxOwn.open{ display:flex; animation:ukbFadeIn .2s ease; }
@media (max-width:790px){ #fvxVis, #fvxOwn{ align-items:flex-end; }}
.fvx-body{ padding:4px 16px 14px; white-space:normal; color:var(--text,#F0F2FF); }
.fvx-note{ margin:0 0 8px; font-size:11px; line-height:1.5; color:rgba(var(--fg,255,255,255),.5); }
.fvx-in{ width:100%; box-sizing:border-box; padding:10px 12px; border-radius:6px; border:1px solid rgba(var(--fg,255,255,255),.12); background:var(--lane,#070924); color:var(--text,#F0F2FF); font-size:14px; font-family:inherit; outline:none; resize:none; }
.fvx-in:focus{ border-color:rgba(var(--fg,255,255,255),.35); }
.fvx-lbl{ display:block; margin:12px 0 6px; font-size:10px; letter-spacing:.8px; text-transform:uppercase; color:rgba(var(--fg,255,255,255),.4); }
.fvx-btn{ width:100%; margin-top:12px; padding:11px; border:none; border-radius:6px; background:#FF2D2D; color:#fff; font-size:13.5px; font-weight:600; font-family:inherit; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.fvx-btn:disabled{ opacity:.45; cursor:not-allowed; }
.fvx-btn.alt{ background:rgba(var(--fg,255,255,255),.08); color:rgba(var(--fg,255,255,255),.85); }
.fvx-btn.sm{ width:auto; margin:0; padding:6px 12px; font-size:12px; }
.fvx-tabs{ display:flex; gap:6px; padding:0 16px 4px; }
.fvx-tabs button{ flex:1; padding:8px 4px; border:none; border-radius:6px; background:rgba(var(--fg,255,255,255),.07); color:rgba(var(--fg,255,255,255),.65); font-size:11.5px; font-weight:600; font-family:inherit; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.fvx-tabs button.on{ background:#FF2D2D; color:#fff; }
.fvx-card{ border-radius:8px; padding:16px 14px; text-align:center; background:linear-gradient(135deg, rgba(255,45,45,.18), rgba(255,45,45,.04)); border:1px solid rgba(255,45,45,.3); }
.fvx-card .big{ font-size:30px; line-height:1.1; }
.fvx-card .t{ margin-top:6px; font-size:14px; font-weight:700; }
.fvx-card .s{ margin-top:4px; font-size:11.5px; color:rgba(var(--fg,255,255,255),.6); line-height:1.45; }
.fvx-item{ display:flex; align-items:flex-start; gap:9px; padding:9px 0; border-top:1px solid rgba(var(--fg,255,255,255),.08); }
.fvx-item:first-child{ border-top:none; }
.fvx-item img{ width:30px; height:30px; border-radius:50%; object-fit:cover; flex-shrink:0; background:var(--lane,#070924); }
.fvx-item .u{ font-size:11.5px; font-weight:700; }
.fvx-item .m{ font-size:12.5px; color:rgba(var(--fg,255,255,255),.85); margin-top:1px; word-break:break-word; }
.fvx-item .act{ margin-left:auto; display:flex; gap:6px; flex-shrink:0; }
.fvx-empty{ padding:14px 0; text-align:center; font-size:12px; color:rgba(var(--fg,255,255,255),.4); }
.fvx-langs{ margin-top:8px; max-height:300px; overflow-y:auto; border-radius:6px; }
.mr-anniv{ margin:calc(8px * var(--bs-s)) calc(12px * var(--bs-s)) calc(4px * var(--bs-s)); }
#dzShopSheet{ display:none; position:fixed; inset:0; z-index:2147483647; align-items:center; justify-content:center; background:rgba(0,0,0,.6); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px); isolation:isolate; }
#dzShopSheet.open{ display:flex; animation:ukbFadeIn .2s ease; }
/* Hold the username (0.2s) → bottom sheet with the full name */
#dzUnSheet{ display:none; position:fixed; inset:0; z-index:2147483647; align-items:center; justify-content:center; background:rgba(0,0,0,.6); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px); isolation:isolate; }
#dzUnSheet.open{ display:flex; animation:ukbFadeIn .2s ease; }
@media (max-width:790px){ #dzUnSheet{ align-items:flex-end; }}
.uns-name{ padding:12px 16px 16px; font-size:14px; font-weight:700; line-height:1.4; text-align:center; color:var(--text,#F0F2FF); word-break:break-all; user-select:text; -webkit-user-select:text; }
@media (max-width:490px){ .uns-name{ font-size:13px; padding:10px 14px 14px; }}
/* Tap the location → bottom sheet with the full place + distance + Google Maps */
#dzLocSheet{ display:none; position:fixed; inset:0; z-index:2147483647; align-items:center; justify-content:center; background:rgba(0,0,0,.6); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px); isolation:isolate; }
#dzLocSheet.open{ display:flex; animation:ukbFadeIn .2s ease; }
@media (max-width:790px){ #dzLocSheet{ align-items:flex-end; }}
.lcs-body{ padding:clamp(4px,1.2vw,8px) clamp(14px,4vw,20px) clamp(14px,3.6vw,18px); text-align:center; }
.lcs-place{ display:block; text-decoration:none; font-size:clamp(14px,3.8vw,16px); font-weight:700; line-height:1.4; color:#FF2D2D; word-break:break-word; letter-spacing:.1px; -webkit-tap-highlight-color:transparent; }
a.lcs-place:hover, a.lcs-place:active{ text-decoration:underline; }
.lcs-sub{ margin-top:4px; font-size:clamp(11px,3vw,12px); font-weight:500; color:rgba(var(--fg,255,255,255),.5); }
.lcs-dist{ margin-top:clamp(10px,2.8vw,14px); padding-top:clamp(10px,2.8vw,14px); border-top:1px solid rgba(var(--fg,255,255,255),.09); font-size:clamp(13px,3.5vw,14.5px); font-weight:600; line-height:1.4; color:var(--text,#F0F2FF); }
.lcs-dist[hidden]{ display:none; }
@media (max-width:790px){ #dzShopSheet{ align-items:flex-end; }}
.shs-row{ display:flex; align-items:center; gap:12px; padding:11px 16px; border-top:1px solid rgba(var(--fg,255,255,255),.08); text-decoration:none; color:var(--text,#F0F2FF); -webkit-tap-highlight-color:transparent; transition:background .2s; }
.shs-row:hover{ background:rgba(var(--fg,255,255,255),.06); }
.shs-pic{ width:46px; height:46px; border-radius:50%; object-fit:cover; flex-shrink:0; background:var(--lane,#070924); border:2px solid #FF2D2D; }
.shs-name{ font-size:13.5px; font-weight:700; line-height:1.25; word-break:break-word; }
.shs-view{ margin-top:2px; font-size:11.5px; font-weight:600; color:#FF2D2D; }
/* ── compact sizes for the newer sheets (CTA editor, shops, guest book, translate, tracking-since) — same scale as Untrack / Links / Accounts ── */
.cta-body, .fvx-body{ padding:2px 14px 10px; }
.cta-note, .fvx-note{ font-size:10.5px; line-height:1.45; }
.cta-lbl, .fvx-lbl{ font-size:9px; margin:9px 0 5px; letter-spacing:.7px; }
.cta-in, .fvx-in{ font-size:12.5px; padding:8px 10px; border-radius:5px; }
.cta-act{ margin-top:11px; gap:7px; }
.cta-act button, .fvx-btn{ font-size:12px; padding:9px; border-radius:5px; }
.fvx-btn{ margin-top:9px; }
.fvx-btn.sm{ padding:5px 10px; font-size:11px; margin:0; }
.cta-pic-prev{ width:36px; height:36px; border-radius:6px; }
.cta-pic-btn, .cta-pic-x{ font-size:11px; padding:6px 10px; border-radius:5px; }
.cta-pic-hint{ font-size:9.5px; }
.cta-sw{ gap:7px; } .cta-sw button{ width:22px; height:22px; }
.cta-warn{ font-size:10.5px; padding:7px 9px; border-radius:5px; }
.cta-prev{ margin-top:11px; }
.fvx-tabs{ padding:0 14px 2px; gap:5px; }
.fvx-tabs button{ font-size:11px; padding:7px 4px; border-radius:5px; }
.fvx-item{ gap:8px; padding:7px 0; }
.fvx-item img{ width:26px; height:26px; }
.fvx-item .u{ font-size:10.5px; } .fvx-item .m{ font-size:11.5px; }
.fvx-empty{ padding:10px 0; font-size:11px; }
.fvx-card{ padding:12px 12px; border-radius:6px; } .fvx-card .big{ font-size:24px; } .fvx-card .t{ font-size:13px; } .fvx-card .s{ font-size:10.5px; }
.fvx-langs{ max-height:260px; margin-top:6px; }
.mr-anniv{ margin:calc(6px * var(--bs-s)) calc(12px * var(--bs-s)) 2px; }
.shs-row{ padding:8px 14px; gap:10px; }
.shs-pic{ width:38px; height:38px; border:2px solid #FF2D2D; }
.shs-name{ font-size:12.5px; } .shs-view{ font-size:10.5px; }@media (max-width:790px){
    .cta-body, .fvx-body{ padding:0 12px 8px; }
    .cta-note, .fvx-note{ font-size:10px; }
    .cta-lbl, .fvx-lbl{ font-size:8.5px; margin:8px 0 4px; }
    .cta-in, .fvx-in{ font-size:11.5px; padding:8px 11px; }
    .cta-act button, .fvx-btn{ font-size:11.5px; padding:8px; }
    .fvx-btn.sm{ font-size:10.5px; padding:4px 9px; }
    .cta-pic-prev{ width:32px; height:32px; }
    .cta-sw button{ width:20px; height:20px; }
    .fvx-tabs{ padding:0 12px 2px; } .fvx-tabs button{ font-size:10.5px; padding:6px 4px; }
    .fvx-item img{ width:24px; height:24px; } .fvx-item .u{ font-size:10px; } .fvx-item .m{ font-size:11px; }
    .fvx-card .big{ font-size:20px; } .fvx-card .t{ font-size:12px; } .fvx-card .s{ font-size:10px; }
    .shs-row{ padding:6px 12px; gap:9px; }
    .shs-pic{ width:34px; height:34px; }
    .shs-name{ font-size:11.5px; } .shs-view{ font-size:9.5px; }}

/* ── translate / guest book / tracking-since / shop bottom sheets: same +10% on phones as the other sheets (--bs-s) ── */
.fvx-body{ padding:calc(2px * var(--bs-s)) calc(14px * var(--bs-s)) calc(10px * var(--bs-s)); }
.fvx-note{ font-size:calc(10.5px * var(--bs-s)); }
.fvx-lbl{ font-size:calc(9px * var(--bs-s)); margin:calc(9px * var(--bs-s)) 0 calc(5px * var(--bs-s)); }
.fvx-in{ font-size:calc(12.5px * var(--bs-s)); padding:calc(8px * var(--bs-s)) calc(10px * var(--bs-s)); }
.fvx-btn{ font-size:calc(12px * var(--bs-s)); padding:calc(9px * var(--bs-s)); margin-top:calc(9px * var(--bs-s)); }
.fvx-btn.sm{ padding:calc(5px * var(--bs-s)) calc(10px * var(--bs-s)); font-size:calc(11px * var(--bs-s)); margin:0; }
.fvx-btn.flush{ margin:0 0 calc(8px * var(--bs-s)); }
.fvx-tabs{ padding:0 calc(14px * var(--bs-s)) calc(2px * var(--bs-s)); gap:calc(5px * var(--bs-s)); }
.fvx-tabs button{ font-size:calc(11px * var(--bs-s)); padding:calc(7px * var(--bs-s)) calc(4px * var(--bs-s)); }
.fvx-item{ gap:calc(8px * var(--bs-s)); padding:calc(7px * var(--bs-s)) 0; }
.fvx-item img{ width:calc(26px * var(--bs-s)); height:calc(26px * var(--bs-s)); }
.fvx-item .u{ font-size:calc(10.5px * var(--bs-s)); } .fvx-item .m{ font-size:calc(11.5px * var(--bs-s)); }
.fvx-empty{ padding:calc(10px * var(--bs-s)) 0; font-size:calc(11px * var(--bs-s)); }
.fvx-card{ padding:calc(12px * var(--bs-s)) calc(12px * var(--bs-s)); } .fvx-card .big{ font-size:calc(24px * var(--bs-s)); } .fvx-card .t{ font-size:calc(13px * var(--bs-s)); } .fvx-card .s{ font-size:calc(10.5px * var(--bs-s)); }
.fvx-langs{ max-height:calc(260px * var(--bs-s)); margin-top:calc(6px * var(--bs-s)); }
.shs-row{ padding:calc(8px * var(--bs-s)) calc(14px * var(--bs-s)); gap:calc(10px * var(--bs-s)); }
.shs-pic{ width:calc(38px * var(--bs-s)); height:calc(38px * var(--bs-s)); }
.shs-name{ font-size:calc(12.5px * var(--bs-s)); } .shs-view{ font-size:calc(10.5px * var(--bs-s)); }@media (max-width:790px){
    .fvx-body{ padding:0 calc(12px * var(--bs-s)) calc(8px * var(--bs-s)); }
    .fvx-note{ font-size:calc(10px * var(--bs-s)); }
    .fvx-lbl{ font-size:calc(8.5px * var(--bs-s)); margin:calc(8px * var(--bs-s)) 0 calc(4px * var(--bs-s)); }
    .fvx-in{ font-size:calc(11.5px * var(--bs-s)); padding:calc(8px * var(--bs-s)) calc(11px * var(--bs-s)); }
    .fvx-btn{ font-size:calc(11.5px * var(--bs-s)); padding:calc(8px * var(--bs-s)); }
    .fvx-btn.sm{ font-size:calc(10.5px * var(--bs-s)); padding:calc(4px * var(--bs-s)) calc(9px * var(--bs-s)); }
    .fvx-tabs{ padding:0 calc(12px * var(--bs-s)) calc(2px * var(--bs-s)); } .fvx-tabs button{ font-size:calc(10.5px * var(--bs-s)); padding:calc(6px * var(--bs-s)) calc(4px * var(--bs-s)); }
    .fvx-item img{ width:calc(24px * var(--bs-s)); height:calc(24px * var(--bs-s)); } .fvx-item .u{ font-size:calc(10px * var(--bs-s)); } .fvx-item .m{ font-size:calc(11px * var(--bs-s)); }
    .fvx-card .big{ font-size:calc(20px * var(--bs-s)); } .fvx-card .t{ font-size:calc(12px * var(--bs-s)); } .fvx-card .s{ font-size:calc(10px * var(--bs-s)); }
    .shs-row{ padding:calc(6px * var(--bs-s)) calc(12px * var(--bs-s)); gap:calc(9px * var(--bs-s)); }
    .shs-pic{ width:calc(34px * var(--bs-s)); height:calc(34px * var(--bs-s)); }
    .shs-name{ font-size:calc(11.5px * var(--bs-s)); } .shs-view{ font-size:calc(9.5px * var(--bs-s)); }}
.fvx-btn.flush{ margin:0 0 8px; }
#fvxAnniv:empty{ display:none; }
.ukb-sub.unread-red{ color:#FF2D2D; }
.ukb-slots{ font-size:calc(10px * var(--bs-s)); color:rgba(var(--fg,255,255,255),0.35); }
</style>

<?php /* ── Account warning: shown to the warned user on their own profile; stays until they press "I understand" ── */
if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$profile_id && is_file(__DIR__ . '/dz_warnings.php')) {
    require_once __DIR__ . '/dz_warnings.php';
    dz_warn_overlay(dz_warn_pending($conn, (int)$profile_id, 0), 'account');
} ?>