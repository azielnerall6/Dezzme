<?php
/* ════════════════════════════════════════════════════════════
   zeems_modal.php — Card Caption Modal (Reels/TikTok style player)
   USAGE:  <?php include 'zeems_modal.php'; ?>   (put it before </body>)
   NEEDS on the host page:
     - Remix Icon CSS (ri-*)           - Poppins font (optional)
     - peuraFeed (array of posts)      - window._suggPromise (Promise → users[])
     - showToast(), openSaveModal(), openIndexSheet()   (all optional — every call is guarded)
     - $current_user (optional, defaults to 0)
   Open it with: openCardModal(file, type, pic, userId, username, caption)
   or by post id (profile page): openCardModal({ id, feed, mediaIndex, startTime, startAudioTime, posterDataUrl })
   Close it with: closeCardCaptionModal()  → fires 'zeems:closed' with { memeId, id, mediaIndex, videoTime, audioTime }
   ════════════════════════════════════════════════════════════ */
if (defined('CCM_MODAL_LOADED')) { return; }
define('CCM_MODAL_LOADED', true);
?>
<!-- MEME CARD CAPTION MODAL -->
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&display=swap" rel="stylesheet">
<svg class="ccm-sprite" width="0" height="0" aria-hidden="true" focusable="false"><symbol id="ccm-vflower" viewBox="0 0 256 256"><path fill="currentColor" fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></symbol></svg>
<style>#cardCaptionModal:not(.ccm-open){display:none}</style>
<div id="cardCaptionModal" onclick="if(event.target===this){ if(this.classList.contains('ccm-commenting') || this.classList.contains('ccm-cs-side')){ if(window.CommentSection) window.CommentSection.close(); } else closeCardCaptionModal(); }">
    <div id="ccm-stage">
        <button type="button" id="ccmFixedBackBtn" class="ccm-icon-btn ccm-back-btn"
                onclick="event.stopPropagation();ccmHandleBackTap()">
            <svg class="ccm-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linejoin="miter" stroke-linecap="butt"/></svg>
        </button>
        <a id="ccmPageMenuBtn" class="ccm-icon-btn ccm-back-btn" href="upload.php" aria-label="Upload" onclick="event.stopPropagation()">
            <svg class="ccm-eq-svg" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 1 V19 M1 10 H19" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="butt"/></svg>
        </a>
        <!-- fixed top bar ("Zeems" label on the right) — lives OUTSIDE the slides, so it never scrolls with the swipe, just like the back button -->
        <div class="ccm-top-bar" id="ccm-fixed-topbar">
            <div class="ccm-top-bar-row">
                <div class="ccm-back-group">
                    <span class="ccm-icon-btn ccm-back-btn ccm-back-ghost"><svg class="ccm-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linejoin="miter" stroke-linecap="butt"/></svg></span>
                </div>
                <span id="ccm-topbar-label"><span class="ccm-brand-label">ZEEMS</span></span>
            </div>
        </div>
        <!-- #ccm-vtrack (vertical feed) and #ccm-hstack (Connected Zeem
             horizontal layers) are both built/appended into this by JS -->
        <div id="ccm-inner"></div>
    </div>

    <!-- desktop: the action buttons live out here, beside the box (a copy of the current post's rail; on phones they stay on the post) -->
    <div id="ccm-side-rail"></div>

    <!-- desktop-only up/down navigation -->
    <div id="ccm-arrows">
        <button type="button" onclick="ccmLoadIndex(_ccmIndex-1)" title="Previous">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button type="button" onclick="ccmLoadIndex(_ccmIndex+1)" title="Next">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>
</div>

<style>
/* ══════════════════════════════════════════════════════════
   RESPONSIVE SCALE TOKEN
   --ccm-t goes 0px → 1px as the viewport grows 330px → 790px.
   Every responsive value below is:  calc((<value@330px> + <delta> * var(--ccm-t)) * var(--ccm-s))
   so it glides smoothly between the old 330 / 390 / 490 / 790
   breakpoints with NO media queries.
   ══════════════════════════════════════════════════════════ */
:root{
    --ccm-t: clamp(0px, calc((100 * var(--ccm-vw, 1vw) - 330px) / 460), 1px);
    --ccm-s: 1;                 /* size multiplier — 1 on desktop */
    --ccm-resp: clamp(0px, calc((791px - 100vw) * 100), 1px);   /* 0px above 790px wide, 1px at 790px and below */
}
/* responsive / phone view: everything in the modal 15.5% bigger (change 1.155 to tune) */
@media (max-width:768px){ :root{ --ccm-s: 1.155; } }
/* the modal carries its OWN copy of these numbers: a host page (group page ...) that sets --ccm-s / --ccm-t on :root for something else can no longer make Zeems bigger or smaller there */
#cardCaptionModal, #shareModalOverlay, #shareModalPanel{
    --ccm-t: clamp(0px, calc((100 * var(--ccm-vw, 1vw) - 330px) / 460), 1px);
    --ccm-s: 1;
    --ccm-resp: clamp(0px, calc((791px - 100vw) * 100), 1px);
}
@media (max-width:768px){ #cardCaptionModal, #shareModalOverlay, #shareModalPanel{ --ccm-s: 1.155; } }
/* ── LAPTOP / DESKTOP (791px and wider): the box is TALL AND THIN like TikTok (9:16) and everything inside is sized from the box's own width
   (--ccm-vw = 1% of the box width, used wherever the phone layout used 1vw), so it looks like the phone layout shrunk into the box. Phones are not touched. ── */
@media (min-width:791px){
    #cardCaptionModal{
        --ccm-h: min(95vh, 1000px);                        /* the box: tall like Reels, but wider than 9:16 */
        --ccm-ref-w: calc(var(--ccm-h) * .68);             /* box width; everything inside is sized from this width */
        --ccm-stage-w: var(--ccm-ref-w);
        --ccm-vw: calc(var(--ccm-ref-w) / 100);
        --ccm-s: 1;                                     /* everything inside the box (poster, pic, caption, social proof, buttons) is bigger */
        /* the box used to be wider; it is narrower now. Half the difference goes on each side so the up/down arrows stay exactly where they were */
        --ccm-d: max(0px, calc((min(92vh, 940px) * .76 - var(--ccm-ref-w)) / 2));
    }
}

/* ══════════════════════════════════════════════════════════
   CARD CAPTION MODAL — REELS/TIKTOK STYLE FULL-SCREEN PLAYER
   ══════════════════════════════════════════════════════════ */
#cardCaptionModal{
    touch-action:pan-y;              /* keep vertical scroll, stop the browser claiming sideways drags */
    overscroll-behavior:none;        /* no pull-to-refresh / rubber-band drag of the page behind the player */
    display:none;
    position:fixed;inset:0;
    z-index:9999999;
    background:#000;
    align-items:center;
    justify-content:center;
}
#cardCaptionModal.ccm-open{ display:flex; }

#ccm-stage{
    position:relative;
    width:100%;
    height:100%;
    max-width:480px;
    background:#000;
    overflow:hidden;
    touch-action:pan-y;
    overscroll-behavior:none;
    will-change:transform;
}
@media(min-width:791px){
    #ccm-stage{
        /* desktop: a tall 9:16 box, small sharp-ish corners, 95% of the screen height */
        height:var(--ccm-h);
        width:var(--ccm-stage-w);
        max-width:none;
        margin:0 var(--ccm-d);
        border-radius:10px;
        box-shadow:0 25px 90px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.06);
        clip-path: inset(0 round 10px);
        -webkit-clip-path: inset(0 round 10px);
    }
}

#ccm-inner{ position:relative; width:100%; height:100%; overflow:hidden; }

.ccm-vtrack{ position:absolute; inset:0; will-change:transform; }
.ccm-vslide{ position:absolute; left:0; right:0; top:0; height:100%; background:#000; }
.ccm-vslide[data-pos="prev"]{ transform:translateY(calc(-100% - 2px)); }
.ccm-vslide[data-pos="current"]{ transform:translateY(0); }
.ccm-vslide[data-pos="next"]{ transform:translateY(calc(100% + 2px)); }

.ccm-slide{ position:absolute; inset:0; background:#000; }

.ccm-media-box{ width:100%; height:100%; position:relative; background:#000; overflow:hidden; }
.ccm-media-box .ccm-media{
    width:100%; height:100%;
    object-fit:contain;
    background:transparent;
    display:block;
    position:relative;
    z-index:1;
}

/* ── MULTI-MEDIA GALLERY ── */
.ccm-media-gallery{
    display:flex; flex-direction:row;
    width:100%; height:100%;
    transition:transform .28s cubic-bezier(.22,1,.36,1);
}
.ccm-media-item{ position:relative; flex:0 0 100%; width:100%; height:100%; background:#000; }

/* Hover arrows — shown only when a real mouse is detected (.ccm-has-mouse set by JS) */
.ccm-media-arrow{
    position:absolute; top:34%; transform:translateY(-50%);
    width:calc((26px + 10 * var(--ccm-t)) * var(--ccm-s)); height:calc((26px + 10 * var(--ccm-t)) * var(--ccm-s));
    border-radius:50%;
    background:rgba(0,0,0,.35);
    display:flex; align-items:center; justify-content:center;
    color:#fff; cursor:pointer; z-index:6;
    opacity:0; pointer-events:none;
    transition:opacity .2s ease, background .2s ease;
    -webkit-tap-highlight-color:transparent;
}
#cardCaptionModal.ccm-has-mouse .ccm-media-box:hover .ccm-media-arrow{ opacity:1; pointer-events:auto; }
#cardCaptionModal.ccm-has-mouse .ccm-media-arrow:hover{ background:rgba(0,0,0,.6); }
.ccm-media-arrow-left{ left:calc((34px + 22 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-media-arrow-right{ right:calc((42px + 22 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-media-arrow i{ font-size:calc((14px + 6 * var(--ccm-t)) * var(--ccm-s)); }

/* Dashes span the full modal width (broken out of .ccm-bottom's padding) — same numbers as the meme modal */
.ccm-media-dashes{
    display:flex; justify-content:center; align-items:center; gap:6px;
    margin-top:18px;
    margin-left:-16px;
    margin-right:-78px;
    width:calc(100% + 94px);
    padding:0 12px;
    box-sizing:border-box;
}
.ccm-dash{
    flex:1 1 auto; max-width:400px; min-width:6px; height:2.82px; border-radius:2px;
    background:rgba(255,255,255,.6); box-shadow:0 0 0 .5px rgba(0,0,0,.3), 0 1px 3px rgba(0,0,0,.45);
    transition:background .2s ease, transform .2s ease, max-width .2s ease;
}
.ccm-dash.active{ background:var(--ccm-accent, var(--red,#FF2D2D)); }
#cardCaptionModal .ccm-media-dashes .ccm-dash{ background:rgba(255,255,255,.6) !important; opacity:1 !important; }
#cardCaptionModal .ccm-media-dashes .ccm-dash.active{ background:var(--ccm-accent, var(--red,#FF2D2D)) !important; }

/* zeems.php: the player IS the page (like Reels / TikTok) — no back arrow, and the "Zeems" word sits at the left */
#cardCaptionModal.ccm-pagemode #ccmFixedBackBtn{ display:none !important; }
#cardCaptionModal.ccm-pagemode .ccm-top-bar-row{ justify-content:flex-start; }
/* zeems.php with the bottom bar (nav.php): the player stops where the bar starts, like Instagram Reels */
#cardCaptionModal.ccm-pagenav{ bottom:calc(52px + env(safe-area-inset-bottom, 0px)); }
@media (min-width:769px){ #cardCaptionModal.ccm-pagenav{ bottom:0; left:102px; } }
@media (max-width:790px){ #cardCaptionModal.ccm-pagemode .ccm-brand-label{ font-size:clamp(23px, 6.4vw, 29px) !important; } }   /* about the size of Facebook's "Reels" title on a phone */
#cardCaptionModal.ccm-pagemode .ccm-back-group{ visibility:hidden; width:0; overflow:hidden; }   /* keeps the row as tall as before, so "Zeems" sits on the same line as the + */
#cardCaptionModal.ccm-pagemode .ccm-top-bar{ padding-left:calc((12px + 4 * var(--ccm-t)) * var(--ccm-s)); }
/* the "+" icon (opens upload.php) sits at the top right, same size as the back arrow */
#ccmPageMenuBtn{ display:flex; position:absolute; z-index:50; top:calc((11px + 3 * var(--ccm-t)) * var(--ccm-s)); right:calc((11px + 2 * var(--ccm-t)) * var(--ccm-s)); padding-left:6px !important; text-decoration:none; }
/* same size and same distance from the right edge as the + on the home page */
#cardCaptionModal #ccmPageMenuBtn .ccm-eq-svg path{ vector-effect:non-scaling-stroke; stroke-width:calc(clamp(18px, 16px + 0.6vw, 22px) * .1203) !important; }   /* line as thick as the back arrow on the profile page (2.5 units of a 24-unit arrow, drawn at clamp(18..22px) x 1.155) */   /* same line thickness as the home page + */
#cardCaptionModal #ccmPageMenuBtn .ccm-eq-svg{ height:calc(clamp(34px,9vw,42px) * .644); }
#cardCaptionModal.ccm-on-sugg #ccmPageMenuBtn{ visibility:hidden; pointer-events:none; }
/* desktop only: the + is halfway between its old size and the back arrow's height */
@media (min-width:791px){ #cardCaptionModal #ccmPageMenuBtn .ccm-eq-svg{ height:calc((clamp(34px,9vw,42px) * .644 + (18px + 4 * var(--ccm-t)) * var(--ccm-s)) / 2); } #cardCaptionModal #ccmPageMenuBtn{ align-items:center; } }
.ccm-eq-svg{ height:calc((19px + 4.5 * var(--ccm-t)) * var(--ccm-s)); width:auto; aspect-ratio:1 / 1; display:block; filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); overflow:visible; }
#ccmFixedBackBtn{
    position:absolute;
    top:calc((11px + 3 * var(--ccm-t)) * var(--ccm-s));
    left:calc((11px + 2 * var(--ccm-t)) * var(--ccm-s));
    z-index:50;
}

/* ══════════ VIDEO PROGRESS BAR ══════════ */
.ccm-video-progress{
    position:absolute; bottom:0; left:0; right:0;
    height:28px;
    z-index:11;
    display:none;
    align-items:flex-end;
    padding:0 10px 8px;                     /* long: runs almost edge to edge */
    box-sizing:border-box;
    touch-action:none;
    cursor:pointer;
}
.ccm-video-progress-track{
    position:relative; width:100%; height:2.5px;   /* thin and neat, like Facebook's */
    border-radius:2px;
    background:rgba(255,255,255,.38);
    box-shadow:0 0 0 .5px rgba(0,0,0,.16);          /* just enough edge to still show on a white picture */
    transition:height .15s ease;
}
.ccm-video-progress.scrubbing .ccm-video-progress-track{ height:5px; }
.ccm-video-progress-fill{
    position:absolute; left:0; top:0; bottom:0;
    width:0%; border-radius:2px;
    background:var(--ccm-accent, var(--red,#FF2D2D));          /* one uniform red: no gradient, no glow */
}
.ccm-video-progress-thumb{
    position:absolute; top:50%;
    width:13px; height:13px; border-radius:50%;
    background:var(--ccm-accent, var(--red,#FF2D2D)); border:2px solid #fff;
    box-shadow:0 1px 4px rgba(0,0,0,.4);
    transform:translate(-50%,-50%) scale(0);
    transition:transform .15s ease;
    pointer-events:none;
}
.ccm-video-progress.scrubbing .ccm-video-progress-thumb{ transform:translate(-50%,-50%) scale(1); }


/* progress bar: pinned with ID-scoped !important rules so no page stylesheet (resets, [class*=progress] rules…) can change how it looks */
#cardCaptionModal .ccm-video-progress{
    position:absolute !important; bottom:0 !important; left:0 !important; right:0 !important;
    height:28px !important; z-index:11 !important; box-sizing:border-box !important;
    align-items:flex-end !important; padding:0 10px 8px !important; margin:0 !important;
    touch-action:none !important; cursor:pointer !important; background:none !important; border:0 !important;
    opacity:1 !important; visibility:visible !important;
}
#cardCaptionModal .ccm-video-progress .ccm-video-progress-track{
    position:relative !important; display:block !important; width:100% !important; height:2.82px !important; margin:0 !important; padding:0 !important;
    border-radius:2px !important; background:rgba(255,255,255,.6) !important; overflow:visible !important;
    box-shadow:0 0 0 .5px rgba(0,0,0,.25), 0 1px 3px rgba(0,0,0,.35) !important;
}
#cardCaptionModal .ccm-video-progress.scrubbing .ccm-video-progress-track{ height:6px !important; }
#cardCaptionModal .ccm-video-progress .ccm-video-progress-fill{
    position:absolute !important; left:0 !important; top:0 !important; bottom:0 !important; height:auto !important;
    border-radius:2px !important; background:var(--ccm-accent, var(--red,#FF2D2D)) !important; opacity:1 !important;
}
#cardCaptionModal .ccm-video-progress .ccm-video-progress-thumb{
    position:absolute !important; top:50% !important; width:13px !important; height:13px !important; border-radius:50% !important;
    background:var(--ccm-accent, var(--red,#FF2D2D)) !important; border:2px solid #fff !important; box-sizing:border-box !important;
}

/* ══════════ DOUBLE-TAP LIKE — Instagram style: one white heart pops in tilted, holds, then lifts and fades ══════════ */
.ccm-ig-heart{
    position:absolute; z-index:14; pointer-events:none; left:0; top:0;
    width:calc((84px + 34 * var(--ccm-t)) * var(--ccm-s)); height:calc((84px + 34 * var(--ccm-t)) * var(--ccm-s));
    margin:calc((84px + 34 * var(--ccm-t)) * var(--ccm-s) / -2) 0 0 calc((84px + 34 * var(--ccm-t)) * var(--ccm-s) / -2);
    opacity:0; transform:scale(0) rotate(var(--r, 0deg));
    filter:drop-shadow(0 2px 14px rgba(0,0,0,.38));
    will-change:transform, opacity;
}
.ccm-ig-heart svg{ display:block; width:100%; height:100%; fill:#ff3040; filter:drop-shadow(0 4px 16px rgba(255,48,64,.45)); }
.ccm-ig-heart.show{ animation:ccmIgHeart 1s linear forwards; }
@keyframes ccmIgHeart{
    0%   { transform:scale(0)    rotate(var(--r)) translateY(0);     opacity:1; animation-timing-function:cubic-bezier(.2,.9,.3,1); }
    16%  { transform:scale(1.2)  rotate(var(--r)) translateY(0);     opacity:1; animation-timing-function:cubic-bezier(.4,0,.4,1); }
    28%  { transform:scale(.94)  rotate(var(--r)) translateY(0);     opacity:1; animation-timing-function:cubic-bezier(.4,0,.4,1); }
    38%  { transform:scale(1)    rotate(var(--r)) translateY(0);     opacity:1; }
    72%  { transform:scale(1)    rotate(var(--r)) translateY(0);     opacity:1; animation-timing-function:cubic-bezier(.5,0,1,1); }
    100% { transform:scale(1.12) rotate(var(--r)) translateY(-34px); opacity:0; }
}

/* ══════════ DOUBLE-TAP LIKE HEART BURST (classic, used when window.CCM_DT_STYLE === 'classic') ══════════ */
.ccm-double-tap-heart{
    position:absolute; z-index:14; pointer-events:none;
    transform:translate(-50%,-50%) scale(0) rotate(0deg);
    opacity:0;
}
.ccm-double-tap-heart i{
    font-size:calc((54px + 40 * var(--ccm-t)) * var(--ccm-s)); color:var(--ccm-accent, #ff2d4f);
    filter:drop-shadow(0 6px 22px rgba(255,45,79,.65));
    -webkit-text-stroke:1.5px rgba(255,255,255,.35);
}
.ccm-double-tap-heart.show{ animation: ccmHeartBurst .95s cubic-bezier(.17,.89,.32,1.28) forwards; }
@keyframes ccmHeartBurst{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(-28deg); opacity:0; }
    14%{  transform:translate(-50%,-50%) scale(1.35) rotate(10deg);  opacity:1; }
    28%{  transform:translate(-50%,-50%) scale(.88)  rotate(-6deg);  opacity:1; }
    42%{  transform:translate(-50%,-50%) scale(1.14) rotate(4deg);   opacity:1; }
    58%{  transform:translate(-50%,-50%) scale(.97)  rotate(-1deg);  opacity:1; }
    75%{  transform:translate(-50%,-50%) scale(1.05) rotate(0deg);   opacity:1; }
    100%{ transform:translate(-50%,-50%) scale(1.15) rotate(0deg) translateY(-46px); opacity:0; }
}
.ccm-heart-ring{
    position:absolute; z-index:13; pointer-events:none;
    width:110px; height:110px;
    margin-left:-55px; margin-top:-55px;
    border-radius:50%;
    border:3px solid rgba(255,45,79,.65);
    transform:scale(.25);
    opacity:0;
}
.ccm-heart-ring.show{ animation: ccmRingPulse .75s cubic-bezier(.15,.6,.35,1) forwards; }
@keyframes ccmRingPulse{
    0%{   transform:scale(.25); opacity:.9; border-width:4px; }
    100%{ transform:scale(2.4); opacity:0;  border-width:1px; }
}
.ccm-heart-particle{
    position:absolute; z-index:12; pointer-events:none;
    left:0; top:0;
    transform:translate(-50%,-50%) scale(0);
    opacity:0;
    color:var(--ccm-accent, #ff2d4f);
}
.ccm-heart-particle i{ filter:drop-shadow(0 2px 8px rgba(255,45,79,.55)); }
.ccm-heart-particle.show{ animation: ccmParticleFly .8s cubic-bezier(.2,.7,.3,1) forwards; }
@keyframes ccmParticleFly{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(0deg);        opacity:0; }
    12%{  opacity:1; }
    100%{ transform:translate(calc(-50% + var(--tx)), calc(-50% + var(--ty))) scale(1) rotate(var(--tr)); opacity:0; }
}

/* ══════════ SKELETON LOADER ══════════
   Flat slate screen with static blocks: avatar + name line + two caption lines at the bottom left,
   action-rail circles on the right. No shine sweep, no pulse. */
.ccm-skeleton{
    position:absolute; inset:0; z-index:15;
    background:#2a2f38;
    opacity:0;                                   /* app-like: the loading screen only appears if a post is STILL not ready after about .7s; normally you never see it */
    animation:ccmSkIn .22s ease .7s forwards;
    transition:opacity .14s ease;
    pointer-events:none;
    overflow:hidden;
}
@keyframes ccmSkIn{ to{ opacity:1; } }
.ccm-skeleton.ccm-skeleton-hide{ opacity:0 !important; animation:none; }
.ccm-skel-block{
    position:absolute;
    background:#3b434e;
    animation:none;
    border-radius:8px;
}
.ccm-skel-media{ inset:0; border-radius:0; background:transparent; }
.ccm-skel-avatar{
    left:calc((9px + 7 * var(--ccm-t)) * var(--ccm-s)); bottom:calc((60px + 6 * var(--ccm-t)) * var(--ccm-s));
    width:calc((34px + 12 * var(--ccm-t)) * var(--ccm-s)); height:calc((34px + 12 * var(--ccm-t)) * var(--ccm-s));
    border-radius:50%;
}
.ccm-skel-line{ height:calc(12px * var(--ccm-s)); border-radius:4px; }
/* name line sits beside the avatar, aligned to its top half */
.ccm-skel-line1{
    left:calc((52px + 19 * var(--ccm-t)) * var(--ccm-s));
    bottom:calc((78px + 8 * var(--ccm-t)) * var(--ccm-s));
    width:calc((200px + 40 * var(--ccm-t)) * var(--ccm-s));
}
/* two caption lines under it, starting at the avatar's left edge */
.ccm-skel-line2, .ccm-skel-line3{ left:calc((9px + 7 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-skel-line2{ bottom:calc((36px + 4 * var(--ccm-t)) * var(--ccm-s)); width:calc((290px + 40 * var(--ccm-t)) * var(--ccm-s)); max-width:calc(100% - 60px); }
.ccm-skel-line3{ bottom:calc((15px + 2 * var(--ccm-t)) * var(--ccm-s)); width:calc((190px + 30 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-skel-rail{
    right:calc((11px + 5 * var(--ccm-t)) * var(--ccm-s));
    width:calc((24px + 8 * var(--ccm-t)) * var(--ccm-s)); height:calc((24px + 8 * var(--ccm-t)) * var(--ccm-s));
    border-radius:50%;
}
.ccm-skel-rail1{ bottom:26px; }
.ccm-skel-rail2{ bottom:78px; }
.ccm-skel-rail3{ bottom:130px; }
.ccm-skel-rail4{ bottom:182px; }
.ccm-skel-rail5{ bottom:234px; }

/* ── TAP-TO-PAUSE FEEDBACK ── */
.ccm-tap-flash{
    position:absolute; top:50%; left:50%;
    transform:translate(-50%,-50%) scale(.6);
    z-index:3; pointer-events:none;
    width:calc((42px + 18 * var(--ccm-t, .5px)) * var(--ccm-s, 1)); height:calc((42px + 18 * var(--ccm-t, .5px)) * var(--ccm-s, 1));
    border-radius:50%;          /* circle */
    background:rgba(0,0,0,.5);
    display:flex; align-items:center; justify-content:center;
    opacity:0; transition:opacity .2s ease, transform .2s ease;
}
.ccm-tap-flash .ccm-ico{ width:calc((19px + 8 * var(--ccm-t, .5px)) * var(--ccm-s, 1)); height:calc((19px + 8 * var(--ccm-t, .5px)) * var(--ccm-s, 1)); }
.ccm-tap-flash.show{ opacity:1; transform:translate(-50%,-50%) scale(1); }
.ccm-tap-flash i{ font-size:32px; color:#fff; }

/* ── PERSISTENT PAUSE + MUTE + SEEK CONTROLS ── */
.ccm-paused-controls{
    position:absolute; inset:0; z-index:4;
    display:flex; align-items:center; justify-content:center;
    pointer-events:none;
    opacity:0; transition:opacity .1s ease;
}
.ccm-paused-controls.show{ opacity:1; }
.ccm-paused-controls .ccm-pause-icon{ transform:scale(.82); transition:transform .24s cubic-bezier(.22,1,.36,1); }
.ccm-paused-controls.show .ccm-pause-icon{ transform:scale(1); }
.ccm-paused-stack{ display:flex; flex-direction:column; align-items:center; gap:calc((9px + 5 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-paused-row{ display:flex; align-items:center; gap:calc((12px + 10 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-pause-icon{
    width:calc((42px + 18 * var(--ccm-t)) * var(--ccm-s)); height:calc((42px + 18 * var(--ccm-t)) * var(--ccm-s));
    border-radius:50%;          /* circle */
    background:rgba(0,0,0,.5);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.ccm-mute-btn{
    width:calc((37px + 9 * var(--ccm-t)) * var(--ccm-s)); height:calc((37px + 9 * var(--ccm-t)) * var(--ccm-s));
    border-radius:calc((11px + 3 * var(--ccm-t)) * var(--ccm-s));
    background:rgba(0,0,0,.5); border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    pointer-events:all;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, background .15s ease;
}
.ccm-mute-btn:active{ transform:scale(.9); background:rgba(0,0,0,.65); }
/* top-right: the "Zeems / Reposted" label, the 1/3 counter and the type tag are gone; the mute button lives there instead and (being part of the paused controls) shows ONLY while the video is paused */
.ccm-media-counter, .ccm-type-tag{ display:none !important; }
/* "ZEEMS" (Bebas Neue, white, like "Dezzme" on the home page) shows at the left on zeems.php only; inside the modal on other pages the back arrow is there instead */
#cardCaptionModal:not(.ccm-pagemode) #ccm-topbar-label{ display:none !important; }
#cardCaptionModal.ccm-pagemode #ccm-topbar-label .ccm-brand-label{ margin:0; text-shadow:0 2px 6px rgba(0,0,0,.6); }
/* the top bar's row used to stretch across the whole width and sat ON TOP of the mute button, swallowing its taps (it muted once, then could not unmute): it now only covers the back button */
#ccm-fixed-topbar .ccm-top-bar-row{ width:fit-content; }
/* mute button: a circle with the same dark disc as the pause button, HALF its size, centred right ABOVE the pause button */
.ccm-paused-controls .ccm-mute-btn{
    --ccm-pz: calc((42px + 18 * var(--ccm-t)) * var(--ccm-s));    /* the pause button's size */
    --ccm-mz: calc(var(--ccm-pz) * .72);                          /* half of it, +20% +20% */
    position:absolute; left:50%; top:50%; right:auto; bottom:auto;
    translate:-50% calc(-50% - (var(--ccm-pz) + var(--ccm-mz)) / 2 - 14px * var(--ccm-s));
    z-index:21;
    width:var(--ccm-mz); height:var(--ccm-mz);
    border-radius:50% !important;
    background:rgba(0,0,0,.5) !important; box-shadow:none;
}
.ccm-paused-controls .ccm-mute-btn:active{ background:rgba(0,0,0,.65) !important; }
.ccm-paused-controls .ccm-mute-btn .ccm-ico{ width:calc(var(--ccm-mz) * .5); height:calc(var(--ccm-mz) * .5); filter:none; }
/* pause + back-10 / forward-10 buttons: circles instead of rounded squares */
.ccm-pause-icon, .ccm-seek-btn, .ccm-tap-flash{ border-radius:50% !important; }
.ccm-seek-btn{
    width:calc((27px + 11 * var(--ccm-t)) * var(--ccm-s)); height:calc((27px + 11 * var(--ccm-t)) * var(--ccm-s));
    border-radius:calc((9px + 3 * var(--ccm-t)) * var(--ccm-s));
    background:rgba(0,0,0,.5); border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    pointer-events:all;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, background .15s ease;
    flex-shrink:0;
}
.ccm-seek-btn:active{ transform:scale(.88); background:rgba(0,0,0,.65); }
/* icons: inline SVG with thick, round strokes and round joins (smooth like Facebook) */
.ccm-ico{ display:block; color:#fff; fill:none; stroke:currentColor; stroke-linecap:round; stroke-linejoin:round; overflow:visible; }
.ccm-ico .f{ fill:currentColor; }
.ccm-pause-icon .ccm-ico{ width:calc((19px + 8 * var(--ccm-t)) * var(--ccm-s)); height:calc((19px + 8 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-mute-btn  .ccm-ico{ width:calc((17px + 3 * var(--ccm-t)) * var(--ccm-s)); height:calc((17px + 3 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-seek-btn  .ccm-ico{ width:calc((14px + 4 * var(--ccm-t)) * var(--ccm-s)); height:calc((14px + 4 * var(--ccm-t)) * var(--ccm-s)); }

/* ── LANDSCAPE FULL-SCREEN ("Full-screen" pill under landscape videos, then a landscape player like Facebook's) ── */
.ccm-fs-pill{
    position:absolute; left:50%; transform:translateX(-50%); z-index:9;
    display:inline-flex; align-items:center; gap:calc(5.08px * var(--ccm-s, 1)); height:calc((23.69px + 2.54 * var(--ccm-t, .5px)) * var(--ccm-s, 1)); padding:0 calc(9.31px * var(--ccm-s, 1)); border:none;
    border-radius:calc((6.77px + 1.69 * var(--ccm-t, .5px)) * var(--ccm-s, 1));           /* rounded square, like the player buttons */
    background:rgba(40,40,42,.78); color:#fff; font:600 calc(9.73px * var(--ccm-s, 1))/1 Poppins,sans-serif; cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.ccm-fs-pill i{ font-size:calc(11.84px * var(--ccm-s, 1)); }
.ccm-fs-pill:active{ transform:translateX(-50%) scale(.95); }
.ccm-fs{ position:fixed; inset:0; z-index:2147483000; background:#000; overflow:hidden; font-family:Poppins,sans-serif; color:#fff; -webkit-tap-highlight-color:transparent; }
.ccm-fs-frame{ position:absolute; top:0; left:0; transform-origin:0 0; background:#000; overflow:hidden; touch-action:none; user-select:none; -webkit-user-select:none; }
.ccm-fs-isimg .ccm-fs-center, .ccm-fs-isimg .ccm-fs-time, .ccm-fs-isimg .ccm-fs-bar, .ccm-fs-isimg [data-a="speed"]{ display:none !important; }
.ccm-fs-isimg:not(.ccm-fs-hasa) [data-a="mute"]{ display:none !important; }
.ccm-fs-tu{ min-width:0; flex:1; }
.ccm-fs-count{ margin-left:auto; flex-shrink:0; font:600 13px/1 Poppins,sans-serif; padding:6px 10px; border-radius:8px; background:rgba(0,0,0,.5); }
.ccm-fs-count:empty{ display:none; }
.ccm-fs-video{ position:absolute; inset:0; width:100%; height:100%; object-fit:contain; background:#000; }
.ccm-fs-ui{ position:absolute; inset:0; transition:opacity .2s ease; }
.ccm-fs-ui.hide{ opacity:0; pointer-events:none; }
.ccm-fs-top{ position:absolute; top:0; left:0; right:0; display:flex; align-items:flex-start; gap:12px; padding:max(14px, env(safe-area-inset-top)) 18px 0 max(14px, env(safe-area-inset-left)); }
.ccm-fs-back{ border:none; background:none; color:#fff; padding:4px 6px 4px 0; cursor:pointer; flex-shrink:0; }
.ccm-fs-back svg{ width:14px; height:24px; display:block; }
.ccm-fs-t{ font-size:15px; font-weight:600; line-height:1.3; text-shadow:0 1px 4px rgba(0,0,0,.6); overflow:hidden; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; }
.ccm-fs-u{ font-size:14px; font-weight:500; line-height:1.3; opacity:.92; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.ccm-fs-center{ position:absolute; left:0; right:0; top:50%; transform:translateY(-50%); display:flex; align-items:center; justify-content:center; gap:26px; pointer-events:none; }
.ccm-fs-btn{ pointer-events:all; border:none; cursor:pointer; background:rgba(0,0,0,.5); color:#fff; display:flex; align-items:center; justify-content:center; -webkit-tap-highlight-color:transparent; transition:transform .15s ease; }
.ccm-fs-btn:active{ transform:scale(.9); }
.ccm-fs-seek{ width:44px; height:44px; border-radius:14px; }
.ccm-fs-pp{ width:62px; height:62px; border-radius:20px; }
.ccm-fs .ccm-ico{ width:22px; height:22px; }
.ccm-fs-pp .ccm-ico{ width:28px; height:28px; }
.ccm-fs-seek .ccm-ico{ width:20px; height:20px; }
.ccm-fs-bottom{ position:absolute; left:0; right:0; bottom:0; padding:0 max(16px, env(safe-area-inset-right)) max(12px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
.ccm-fs-time{ font-size:14px; font-weight:600; margin-bottom:8px; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.ccm-fs-time span{ opacity:.75; font-weight:500; }
.ccm-fs-bar{ position:relative; height:22px; display:flex; align-items:center; cursor:pointer; touch-action:none; }
.ccm-fs-track{ position:relative; width:100%; height:2.5px; border-radius:2px; background:rgba(255,255,255,.38); transition:height .15s ease; }
.ccm-fs-bar.drag .ccm-fs-track{ height:5px; }
.ccm-fs-fill{ position:absolute; left:0; top:0; bottom:0; width:0; border-radius:2px; background:var(--ccm-accent, #ff2b2b); }
.ccm-fs-dot{ position:absolute; top:50%; width:13px; height:13px; border-radius:50%; background:var(--ccm-accent, #ff2b2b); border:2px solid #fff; transform:translate(-50%,-50%) scale(0); transition:transform .15s ease; }
.ccm-fs-bar.drag .ccm-fs-dot{ transform:translate(-50%,-50%) scale(1); }
.ccm-fs-actions{ display:flex; align-items:center; gap:24px; margin-top:6px; }
.ccm-fs-act{ border:none; background:none; color:#fff; display:inline-flex; align-items:center; gap:8px; font:700 15px/1 Poppins,sans-serif; padding:6px 0; cursor:pointer; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.ccm-fs-act i{ font-size:30px; }
.ccm-fs-act span{ display:inline; opacity:1; color:#fff; min-width:0; }
.ccm-fs-actions{ flex-wrap:nowrap; }
.ccm-fs-act.on i{ color:var(--ccm-accent, #ff2b2b); }
.ccm-fs-act svg{ fill:none; flex:none; filter:drop-shadow(0 1px 4px rgba(0,0,0,.6)); }
.ccm-fs-act.on svg{ color:#ff3040; }
.ccm-fs-act.ccm-fs-solid.on svg{ fill:currentColor; }
.ccm-fs-act svg{ width:30px; height:30px; }
.ccm-fs-act .ccm-ico{ width:30px; height:30px; }
.ccm-fs-grow{ flex:1; }

/* ── ZOOM (pinch / drag): only the picture, the progress bar and the back button stay; a round button at the bottom-right zooms out ── */
#cardCaptionModal.ccm-zoomed .ccm-slide > *:not(.ccm-media-box):not(.ccm-video-progress):not(.ccm-unzoom-btn),
#cardCaptionModal.ccm-zoomed #ccm-fixed-topbar,
#cardCaptionModal.ccm-zoomed #ccm-arrows,
#cardCaptionModal.ccm-zoomed #ccm-side-rail,
#cardCaptionModal.ccm-zoomed .ccm-paused-controls,
#cardCaptionModal.ccm-zoomed .ccm-tap-flash,
#cardCaptionModal.ccm-zoomed .ccm-type-tag,
#cardCaptionModal.ccm-zoomed .ccm-media-dashes,
#cardCaptionModal.ccm-zoomed .ccm-media-counter,
#cardCaptionModal.ccm-zoomed .ccm-media-arrow,
#cardCaptionModal.ccm-zoomed .ccm-fs-pill{ opacity:0 !important; pointer-events:none !important; transition:opacity .24s ease !important; }
/* the same fade on the way back (class stays for a moment after zooming out) */
#cardCaptionModal.ccm-unzoomed .ccm-slide > *:not(.ccm-media-box):not(.ccm-video-progress):not(.ccm-unzoom-btn),
#cardCaptionModal.ccm-unzoomed #ccm-fixed-topbar,
#cardCaptionModal.ccm-unzoomed #ccm-arrows,
#cardCaptionModal.ccm-unzoomed #ccm-side-rail,
#cardCaptionModal.ccm-unzoomed .ccm-paused-controls,
#cardCaptionModal.ccm-unzoomed .ccm-tap-flash,
#cardCaptionModal.ccm-unzoomed .ccm-type-tag,
#cardCaptionModal.ccm-unzoomed .ccm-media-dashes,
#cardCaptionModal.ccm-unzoomed .ccm-media-counter,
#cardCaptionModal.ccm-unzoomed .ccm-media-arrow,
#cardCaptionModal.ccm-unzoomed .ccm-fs-pill{ transition:opacity .24s ease !important; }

/* ── VIEW FULL SCREEN (index menu): only the picture, progress bar, back + upload buttons and a round exit button stay ── */
#cardCaptionModal.ccm-fsview .ccm-slide > *:not(.ccm-media-box):not(.ccm-video-progress),
#cardCaptionModal.ccm-fsview #ccm-fixed-topbar,
#cardCaptionModal.ccm-fsview #ccm-arrows,
#cardCaptionModal.ccm-fsview #ccm-side-rail,
#cardCaptionModal.ccm-fsview .ccm-paused-controls,
#cardCaptionModal.ccm-fsview .ccm-tap-flash,
#cardCaptionModal.ccm-fsview .ccm-type-tag,
#cardCaptionModal.ccm-fsview .ccm-media-dashes,
#cardCaptionModal.ccm-fsview .ccm-media-counter,
#cardCaptionModal.ccm-fsview .ccm-media-arrow,
#cardCaptionModal.ccm-fsview .ccm-fs-pill{ opacity:0 !important; pointer-events:none !important; transition:opacity .24s ease !important; }
.ccm-unzoom-btn{
    position:absolute; right:14px; bottom:calc(40px * var(--ccm-s, 1)); z-index:13;
    width:calc((36px + 6 * var(--ccm-t, .5px)) * var(--ccm-s, 1)); height:calc((36px + 6 * var(--ccm-t, .5px)) * var(--ccm-s, 1));
    border:none; border-radius:50%; background:rgba(58,58,60,.82); color:#fff; cursor:pointer; padding:0;
    display:flex; align-items:center; justify-content:center; -webkit-tap-highlight-color:transparent; touch-action:manipulation;
    opacity:0; transform:scale(.82); transition:opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1);
}
.ccm-unzoom-btn.show{ opacity:1; transform:scale(1); }
.ccm-unzoom-btn svg{ width:calc(20px * var(--ccm-s, 1)); height:calc(20px * var(--ccm-s, 1)); display:block; shape-rendering:geometricPrecision; }
.ccm-unzoom-btn.show:active{ transform:scale(.9); }
#ccm-slide .ccm-media-box .ccm-media.ccm-zooming{ will-change:scale, translate; }

/* ── SCRUBBING (dragging the progress bar): everything but the picture and the bar fades away, a small preview frame shows the spot ── */
#cardCaptionModal.ccm-scrubbing .ccm-slide > *:not(.ccm-media-box):not(.ccm-video-progress):not(.ccm-scrub-preview),
#cardCaptionModal.ccm-scrubbing #ccm-fixed-topbar,
#cardCaptionModal.ccm-scrubbing #ccmFixedBackBtn,#cardCaptionModal.ccm-scrubbing #ccmPageMenuBtn,
#cardCaptionModal.ccm-scrubbing #ccm-arrows,
#cardCaptionModal.ccm-scrubbing #ccm-side-rail,
#cardCaptionModal.ccm-scrubbing .ccm-paused-controls,
#cardCaptionModal.ccm-scrubbing .ccm-tap-flash,
#cardCaptionModal.ccm-scrubbing .ccm-type-tag,
#cardCaptionModal.ccm-scrubbing .ccm-media-dashes,
#cardCaptionModal.ccm-scrubbing .ccm-media-counter,
#cardCaptionModal.ccm-scrubbing .ccm-media-arrow,
#cardCaptionModal.ccm-scrubbing .ccm-fs-pill{ opacity:0 !important; pointer-events:none !important; transition:opacity .2s ease !important; }
#cardCaptionModal.ccm-unscrubbed .ccm-slide > *:not(.ccm-media-box):not(.ccm-video-progress):not(.ccm-scrub-preview),
#cardCaptionModal.ccm-unscrubbed #ccm-fixed-topbar,
#cardCaptionModal.ccm-unscrubbed #ccmFixedBackBtn,#cardCaptionModal.ccm-unscrubbed #ccmPageMenuBtn,
#cardCaptionModal.ccm-unscrubbed #ccm-arrows,
#cardCaptionModal.ccm-unscrubbed #ccm-side-rail,
#cardCaptionModal.ccm-unscrubbed .ccm-paused-controls,
#cardCaptionModal.ccm-unscrubbed .ccm-tap-flash,
#cardCaptionModal.ccm-unscrubbed .ccm-type-tag,
#cardCaptionModal.ccm-unscrubbed .ccm-media-dashes,
#cardCaptionModal.ccm-unscrubbed .ccm-media-counter,
#cardCaptionModal.ccm-unscrubbed .ccm-media-arrow,
#cardCaptionModal.ccm-unscrubbed .ccm-fs-pill{ transition:opacity .2s ease !important; }
.ccm-scrub-preview{
    position:absolute; left:50%; bottom:calc(46px * var(--ccm-s)); transform:translateX(-50%); z-index:12;
    display:flex; flex-direction:column; align-items:center; gap:10px; pointer-events:none;
}
.ccm-scrub-frame{
    width:calc((104px + 34 * var(--ccm-t)) * var(--ccm-s)); aspect-ratio:9/16; max-height:32vh;
    border-radius:11px; overflow:hidden; background:#111 center/cover no-repeat;
    box-shadow:0 4px 18px rgba(0,0,0,.45);
}
.ccm-scrub-frame video{ width:100%; height:100%; object-fit:cover; display:block; }
.ccm-scrub-time{ color:#fff; font-weight:700; font-size:calc((14px + 2 * var(--ccm-t)) * var(--ccm-s)); text-shadow:0 1px 6px rgba(0,0,0,.8); letter-spacing:.2px; }
.ccm-scrub-time span{ opacity:.7; font-weight:600; }

/* ── CAPTION-ONLY POSTS ── */
.ccm-textonly-bg{
    position:absolute; inset:0; z-index:0;
    background-image:url('uploads/Text.jpg');
    background-size:cover; background-position:center;
}
.ccm-textonly-overlay{ position:absolute; inset:0; z-index:0; background:rgba(0,0,0,.45); }

.ccm-center-caption-wrap{
    position:absolute;
    top:calc((90px + 32 * var(--ccm-t)) * var(--ccm-s));
    left:calc((9px + 7 * var(--ccm-t)) * var(--ccm-s));
    right:calc((44px + 26 * var(--ccm-t)) * var(--ccm-s));
    bottom:calc((150px + 75 * var(--ccm-t)) * var(--ccm-s));
    z-index:1;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
}
.ccm-center-caption-wrap.ccm-caption-overflow{ align-items:flex-start; }
.ccm-center-caption-text{
    width:100%;
    max-width:calc((260px + 160 * var(--ccm-t)) * var(--ccm-s));
    margin:0 auto;
    transform:translateX(calc((6px + 8 * var(--ccm-t)) * var(--ccm-s)));
    color:var(--cap,rgba(255,255,255,.85)); font-size:calc((12px + 4 * var(--ccm-t)) * var(--ccm-s)); font-weight:500; line-height:1.5;
    text-shadow:0 1px 5px rgba(0,0,0,.4);
    max-height:100%; overflow-y:auto;
    word-break:break-word;
    scrollbar-width:none; -ms-overflow-style:none;
    touch-action:pan-y;
    -webkit-overflow-scrolling:touch;
}
.ccm-center-caption-text::-webkit-scrollbar{ display:none; }
/* Text-post typography — same tiers as the home feed: shorter posts get bigger, bolder type */
.ccm-center-caption-text.ccm-t-s{ font-size:calc(clamp(.95rem, calc(2.8 * var(--ccm-vw, 1vw)) + .1rem, 1.15rem) * var(--ccm-s)); font-weight:700; line-height:1.4; }
.ccm-center-caption-text.ccm-t-m{ font-size:calc(clamp(.86rem, calc(1.5 * var(--ccm-vw, 1vw)) + .4rem, .95rem) * var(--ccm-s));  font-weight:600; line-height:1.5; }
.ccm-center-caption-text.ccm-t-l{ font-size:calc(clamp(.8rem, calc(.8 * var(--ccm-vw, 1vw)) + .55rem, .86rem) * var(--ccm-s));   font-weight:500; line-height:1.6; }

/* FIX 3 — overlays on their own graphics layer so phones don't blink them while the photo/video beneath moves */
.ccm-group-tag, .ccm-top-right-tags, .ccm-social-proof-strip,
.ccm-cta-pill, .ccm-connected-tag, .ccm-rail, .ccm-bottom{
    will-change:transform, opacity;
    backface-visibility:hidden;
    -webkit-backface-visibility:hidden;
}

/* ── TOP BAR ── */
.ccm-top-bar{
    position:absolute; top:0; left:0; right:0; z-index:8;
    padding:calc((9px + 5 * var(--ccm-t)) * var(--ccm-s)) calc((8px + 4 * var(--ccm-t)) * var(--ccm-s)) calc((24px + 10 * var(--ccm-t)) * var(--ccm-s)) calc((3px + var(--ccm-t)) * var(--ccm-s));
    background:none;           /* no grey fog: the buttons and the label stay readable through their own soft shadows */
    pointer-events:none;
}
.ccm-top-bar .ccm-top-bar-row, .ccm-top-bar-row{ text-shadow:0 1px 8px rgba(0,0,0,.55), 0 0 2px rgba(0,0,0,.45); display:flex; justify-content:space-between; align-items:center; pointer-events:all; }
/* ── "…" menu sheet ── */
.ccm-hidden-slide{ position:absolute; inset:0; background:#0c0c10; color:#fff; display:flex; align-items:center; justify-content:center; text-align:center; padding:24px; font-family:Poppins,sans-serif; }
.ccm-sheet-ov{ position:absolute; inset:0; z-index:60; display:flex; align-items:flex-end; justify-content:center; background:rgba(0,0,0,0); visibility:hidden; transition:background .22s, visibility 0s .22s; }
.ccm-sheet-ov.show{ background:rgba(0,0,0,.55); visibility:visible; transition:background .22s; }
.ccm-sheet{ width:100%; max-width:520px; background:#16161a; border-radius:18px 18px 0 0; padding:8px 8px calc(14px + env(safe-area-inset-bottom,0px)); transform:translateY(100%); transition:transform .24s cubic-bezier(.22,.9,.3,1); font-family:Poppins,sans-serif; color:#fff; }
.ccm-sheet-ov.show .ccm-sheet{ transform:translateY(0); }
.ccm-sheet-grab{ width:38px; height:4px; border-radius:2px; background:rgba(255,255,255,.22); margin:4px auto 8px; }
.ccm-sheet-row{ display:flex; align-items:center; gap:14px; width:100%; background:none; border:none; color:#fff; font:600 15px Poppins,sans-serif; padding:14px 14px; border-radius:12px; cursor:pointer; text-align:left; }
.ccm-sheet-row:active{ background:rgba(255,255,255,.08); }
.ccm-sheet-row i{ font-size:21px; }
.ccm-sheet-title{ font-weight:700; font-size:16px; text-align:center; padding:6px 0 10px; }
.ccm-sheet-text{ font-size:13.5px; line-height:1.5; color:rgba(255,255,255,.75); padding:0 14px 10px; text-align:center; }
.ccm-sheet-qr{ display:flex; justify-content:center; padding:4px 0 10px; }
.ccm-sheet-qr img{ width:220px; height:220px; border-radius:12px; background:#fff; }

.ccm-top-bar > *{ pointer-events:all; }
#ccm-fixed-topbar{ z-index:20; }
#ccm-fixed-topbar.ccm-topbar-hidden{ visibility:hidden; }
.ccm-back-btn{ padding-left:2px !important; }

.ccm-icon-btn{
    background:none; border:none; box-shadow:none;
    color:#fff; cursor:pointer; padding:6px;
    display:flex; align-items:center; justify-content:center;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, opacity .15s ease;
}
.ccm-icon-btn:active{ transform:scale(.88); opacity:.7; }
.ccm-track-btn, .ccm-pause-icon, .ccm-social-proof-strip > *{ transition:transform .14s cubic-bezier(.2,.8,.3,1); -webkit-tap-highlight-color:transparent; }
.ccm-track-btn:active, .ccm-pause-icon:active, .ccm-social-proof-strip > *:active{ transform:scale(.93); }
/* static styles that used to be written inline */
.ccm-sprite{ position:absolute; width:0; height:0; overflow:hidden; }
.ccm-back-btn.ccm-back-ghost{ visibility:hidden !important; pointer-events:none !important; }
#ccm-topbar-label{ display:contents; }
#shareNativeBtn{ display:none; }   /* the script shows it (display:flex) only where the phone supports native sharing */
/* blur-up placeholder behind a video that is still loading */
.ccm-media-item{ isolation:isolate; }
.ccm-bu{ position:absolute; inset:0; z-index:-1; background:center / cover no-repeat; filter:blur(18px) brightness(.85); transform:scale(1.12); opacity:1; transition:opacity .3s ease; pointer-events:none; }
.ccm-bu.out{ opacity:0; }
/* keyboard focus ring (only for keyboard users) */
#cardCaptionModal :is(button, a, [role="button"]):focus-visible{ outline:2px solid #fff; outline-offset:3px; border-radius:6px; }
a.ccm-tag-link, a.ccm-mention-link{ font-weight:600 !important; text-decoration:none !important; }
a.ccm-tag-link{ color:var(--gw, rgba(255,255,255,.65)) !important; }
a.ccm-mention-link{ color:var(--ccm-accent, #ff2b2b) !important; }
a.ccm-tag-link:hover, a.ccm-mention-link:hover{ text-decoration:underline !important; }
.ccm-audio-tag{ cursor:pointer; }
.ccm-sp-rep-svg{ display:block; width:100%; height:100%; overflow:visible; }
/* ── respect the phone: notch / home-bar safe areas, reduced motion ── */
#cardCaptionModal .ccm-rail{ translate:calc(-1 * env(safe-area-inset-right, 0px)) calc(-1 * env(safe-area-inset-bottom, 0px)); }
/* --ccm-cap-lift = one caption line per extra line (set by JS before the post is drawn), so the avatars and pills clear a caption that runs onto a 2nd line */
#cardCaptionModal .ccm-social-proof-strip, #cardCaptionModal .ccm-cta-pill, #cardCaptionModal .ccm-connected-tag{ translate:0 calc(-1 * env(safe-area-inset-bottom, 0px) - var(--ccm-cap-lift, 0px)); }
#cardCaptionModal .ccm-bottom{ border-bottom:env(safe-area-inset-bottom, 0px) solid transparent; border-left:env(safe-area-inset-left, 0px) solid transparent; }   /* the dark gradient still reaches the screen edge; text clears the home bar */
#cardCaptionModal .ccm-video-progress{ translate:0 calc(-1 * env(safe-area-inset-bottom, 0px)); }
#cardCaptionModal #ccmFixedBackBtn, #cardCaptionModal #ccmPageMenuBtn{ translate:env(safe-area-inset-left, 0px) env(safe-area-inset-top, 0px); }
#cardCaptionModal .ccm-top-bar{ translate:0 env(safe-area-inset-top, 0px); }
@media (prefers-reduced-motion:reduce){
    #cardCaptionModal *, #cardCaptionModal *::before, #cardCaptionModal *::after,
    .ccm-sheet, .ccm-saved-toast, #ccmBuiltinToast, .ccm-media-item::before, .ccm-media-item::after{
        animation-duration:.001ms !important; animation-iteration-count:1 !important; transition-duration:.001ms !important; scroll-behavior:auto !important;
    }
    .ccm-media-item.ccm-vloading::before{ animation:none !important; border-top-color:rgba(255,255,255,.22); }   /* a still, quiet loading mark */
    .ccm-media-item.ccm-vloading::after{ animation:none !important; }
}

.ccm-icon-btn i{ font-size:calc((22px + 8 * var(--ccm-t)) * var(--ccm-s)); filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); }
/* the back arrow: a thin, tall chevron like Instagram's (was the heavier icon-font arrow) */
.ccm-back-btn .ccm-back-svg{ height:calc((18px + 4 * var(--ccm-t)) * var(--ccm-s)); width:auto; aspect-ratio:14 / 24; display:block; filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); overflow:visible; }

.ccm-back-group{ display:flex; align-items:center; gap:2px; }
/* "Zeems" / "Advert" — same size as the back arrow, sits on the right */
.ccm-brand-label{
    color:#fff; font-weight:700;
    font-size:calc((18px + 4 * var(--ccm-t)) * var(--ccm-s));   /* same size as the back arrow (its height) */
    text-shadow:0 2px 6px rgba(0,0,0,.6);
    font-family:Poppins,sans-serif;
    white-space:nowrap;
    margin-right:calc(8px * var(--ccm-s));
}
/* same size on every page (a host page's own rules can't shrink it) */
#cardCaptionModal #ccm-fixed-topbar #ccm-topbar-label .ccm-brand-label{
    font-size:calc(18px + 4 * clamp(0px, calc((100 * var(--ccm-vw, 1vw) - 330px) / 460), 1px)) !important;
    font-weight:700 !important; font-family:Poppins,sans-serif !important; line-height:normal !important; letter-spacing:normal !important;
}
@media (max-width:768px){
    #cardCaptionModal #ccm-fixed-topbar #ccm-topbar-label .ccm-brand-label{ font-size:calc((18px + 4 * clamp(0px, calc((100 * var(--ccm-vw, 1vw) - 330px) / 460), 1px)) * 1.155) !important; }
}
/* the top word ("Zeems" / "Advert"): same look as the "Dezzme" word on the home page — Bebas Neue, wide letter-spacing, same size — but white */
#cardCaptionModal #ccm-fixed-topbar #ccm-topbar-label .ccm-brand-label{
    font-family:'Bebas Neue',sans-serif !important; font-weight:400 !important; color:#fff !important; display:inline-block !important;
    font-size:clamp(24px, calc(6.5 * var(--ccm-vw, 1vw)), 32px) !important;
    letter-spacing:clamp(5px, calc(1.8 * var(--ccm-vw, 1vw)), 8px) !important; line-height:1 !important;
}
@media (max-width:768px){
    #cardCaptionModal #ccm-fixed-topbar #ccm-topbar-label .ccm-brand-label{ font-size:clamp(24px, calc(6.8 * var(--ccm-vw, 1vw)), 31px) !important; }
}
/* profile picture + nickname lines: identical on every page (a host page such as the pod page can't square the picture or enlarge the text) */
#cardCaptionModal .ccm-bottom .ccm-user-pic{
    width:calc((32.96px + 12.36 * var(--ccm-t)) * var(--ccm-s) * .98) !important; height:calc((32.96px + 12.36 * var(--ccm-t)) * var(--ccm-s) * .98) !important;   /* 2% smaller than the social-proof avatars */
    min-width:0 !important; min-height:0 !important; max-width:none !important; max-height:none !important;
    border-radius:50% !important; object-fit:cover !important; aspect-ratio:1 / 1 !important;
    border:calc((2px + var(--ccm-t)) * var(--ccm-s)) solid var(--ccm-accent, var(--red,#FF2D2D)) !important; box-sizing:content-box !important; padding:0 !important; margin:0 !important;
}
/* the poster's picture is exactly as tall as the two lines beside it (username + nickname), never smaller than a floor */
#cardCaptionModal .ccm-bottom .ccm-user-row > .av-wrap:not([data-fit]){ visibility:hidden; animation:ccmAvShow 0s .35s forwards; }
@keyframes ccmAvShow{ to{ visibility:visible; } }   /* no size jump: shown once measured */
#cardCaptionModal .ccm-bottom .ccm-user-row > .av-wrap{ width:calc(39.6px * var(--ccm-s)); height:calc(39.6px * var(--ccm-s)); }   /* JS sets the real size = height of the username + nickname block */
#cardCaptionModal .ccm-bottom .ccm-user-row > .av-wrap .ccm-user-pic{
    position:absolute !important; inset:0 !important; width:100% !important; height:100% !important; box-sizing:border-box !important;
    border-width:calc((2px + var(--ccm-t)) * var(--ccm-s)) !important;   /* same ring thickness as the social-proof avatars */
}
/* post collaboration: the pictures share ONE poster-sized box and shrink as more people join (2 = Instagram diagonal, 3 = messages-style trio) */
#cardCaptionModal .ccm-bottom .ccm-user-row > .av-wrap.collab .ccm-user-pic{
    position:absolute !important; inset:auto !important; width:54% !important; height:54% !important; box-sizing:border-box !important;   /* 2 people: each picture is smaller than a single poster picture */
    border-width:calc((2px + var(--ccm-t)) * var(--ccm-s)) !important; background:#10143a;
}
#cardCaptionModal .ccm-bottom .ccm-user-row > .av-wrap.collab[data-extra="1"] > .ccm-user-pic:not(.ccm-co-pic){ left:9% !important; bottom:9% !important; top:auto !important; z-index:2 !important; }
#cardCaptionModal .ccm-bottom .ccm-user-row > .av-wrap.collab[data-extra="1"] > .ccm-co-pic{ right:9% !important; top:9% !important; left:auto !important; bottom:auto !important; z-index:1 !important; }
#cardCaptionModal .ccm-bottom .ccm-user-row > .av-wrap.collab[data-extra="2"] .ccm-user-pic{ width:45% !important; height:45% !important; }   /* 3 people: smaller still */
#cardCaptionModal .ccm-bottom .ccm-user-row > .av-wrap.collab[data-extra="2"] > .ccm-user-pic:not(.ccm-co-pic){ left:27.5% !important; top:10% !important; right:auto !important; bottom:auto !important; z-index:3 !important; }
#cardCaptionModal .ccm-bottom .ccm-user-row > .av-wrap.collab[data-extra="2"] > .ccm-co-pic[style*="--i:1"]{ left:12% !important; bottom:10% !important; top:auto !important; right:auto !important; z-index:2 !important; }
#cardCaptionModal .ccm-bottom .ccm-user-row > .av-wrap.collab[data-extra="2"] > .ccm-co-pic[style*="--i:2"]{ right:12% !important; bottom:10% !important; top:auto !important; left:auto !important; z-index:1 !important; }
#cardCaptionModal .ccm-bottom .ccm-nickname, #cardCaptionModal .ccm-bottom .ccm-ai-label, #cardCaptionModal .ccm-bottom .ccm-partner-line{
    font-size:calc(clamp(8.93px, 8.47px + calc(0.282 * var(--ccm-vw, 1vw)), 10.81px) * var(--ccm-s)) !important; font-family:Poppins,sans-serif !important; letter-spacing:normal !important; text-transform:none !important;
}
/* the round profile shortcut under the menu button: always a circle, white ring 3% thicker, on every page */
#cardCaptionModal .ccm-rail .ccm-rail-msg .ccm-rail-msg-pic{
    width:calc(clamp(22px, 20.03px + calc(1.18 * var(--ccm-vw, 1vw)), 30px) * var(--ccm-s) * 1.05) !important; height:calc(clamp(22px, 20.03px + calc(1.18 * var(--ccm-vw, 1vw)), 30px) * var(--ccm-s) * 1.05) !important;
    min-width:0 !important; min-height:0 !important; max-width:none !important; max-height:none !important; aspect-ratio:1 / 1 !important;
    border-radius:50% !important; object-fit:cover !important; box-sizing:content-box !important; padding:0 !important; margin:0 !important;
    border:calc(clamp(19px, 17.03px + calc(1.18 * var(--ccm-vw, 1vw)), 25px) * var(--ccm-s) * 1.166 * .0833) solid #fff !important; display:block !important;
}
#cardCaptionModal .ccm-rail .ccm-rail-msg{ border-radius:50% !important; overflow:visible !important; }
/* ── COMMENTS OPEN (docked sheet): the picture is squeezed into the space above it, everything else steps aside ── */
#cardCaptionModal.ccm-commenting .ccm-media-box{
    position:absolute; left:0; right:auto; top:0; width:100%; height:calc(100% - var(--ccm-cm-h, 60%)); border-radius:0;
    transform:translateZ(0);
    transition:width .26s cubic-bezier(.32,.72,0,1), height .26s cubic-bezier(.32,.72,0,1), top .26s cubic-bezier(.32,.72,0,1), left .26s cubic-bezier(.32,.72,0,1), border-radius .26s cubic-bezier(.32,.72,0,1);
}
#cardCaptionModal.ccm-commenting .ccm-media-box .ccm-media{ object-fit:contain !important; }
/* the picture floats above the sheet at its own shape, rounded corners, a tiny gap (JS sets --ccm-cm-bw / bh / bt).
   Every property that changes is a number on both sides, so the whole move is ONE smooth animation that runs at the same time and speed as the sheet. */
#cardCaptionModal.ccm-commenting.ccm-cm-fit .ccm-media-box{
    left:calc(50% - var(--ccm-cm-bw) / 2); right:auto; top:var(--ccm-cm-bt); width:var(--ccm-cm-bw); height:var(--ccm-cm-bh); border-radius:20px;
}
#cardCaptionModal.ccm-commenting.ccm-cm-closing .ccm-media-box{ border-radius:0 !important; }
#cardCaptionModal.ccm-cm-dragging .ccm-media-box{ transition:none !important; }
#cardCaptionModal.ccm-commenting .ccm-slide > *:not(.ccm-media-box):not(.ccm-cm-ctl),
#cardCaptionModal.ccm-commenting #ccm-fixed-topbar, #cardCaptionModal.ccm-commenting #ccmFixedBackBtn, #cardCaptionModal.ccm-commenting #ccmPageMenuBtn,
#cardCaptionModal.ccm-commenting #ccm-arrows, #cardCaptionModal.ccm-commenting #ccm-side-rail,
#cardCaptionModal.ccm-commenting .ccm-paused-controls, #cardCaptionModal.ccm-commenting .ccm-tap-flash,
#cardCaptionModal.ccm-commenting .ccm-fs-pill{ opacity:0 !important; pointer-events:none !important; }
#cardCaptionModal.ccm-commenting #ccm-stage{ pointer-events:none; }
#cardCaptionModal.ccm-cm-dragging .ccm-media-box, #cardCaptionModal.ccm-cm-dragging .ccm-cm-ctl{ transition:none !important; }
.ccm-cm-ctl{ position:absolute; right:10px; bottom:10px; z-index:60; display:flex; gap:8px; pointer-events:auto; }   /* a child of the picture: always bottom-right ON it, above it, and it moves/resizes with it */
.ccm-media-box > .ccm-cm-ctl{ right:10px !important; bottom:10px !important; left:auto !important; top:auto !important; transform:none !important; transition:none !important; }
.ccm-cm-ctl button{ width:38px; height:38px; border-radius:50%; border:none; background:rgba(40,40,46,.85); display:flex; align-items:center; justify-content:center; cursor:pointer; -webkit-tap-highlight-color:transparent; padding:0; }
.ccm-cm-ctl button:active{ transform:scale(.92); }
.ccm-cm-ctl .ccm-ico{ width:21px; height:21px; fill:#fff; stroke:none; }
.ccm-cm-ctl .ccm-ico .f{ fill:#fff; }
.ccm-cm-ctl .ccm-ico.pp{ width:28px; height:28px; }   /* pause / play as big as the mute icon */
.ccm-ad-badge{
    font-size:9px; font-weight:800; letter-spacing:1.2px; color:#fff;
    border:1px solid rgba(255,255,255,.4); border-radius:4px; padding:3px 7px;
    text-transform:uppercase; background:rgba(0,0,0,.3);
}

.ccm-group-tag{
    position:absolute; top:calc((56px + 24 * var(--ccm-t)) * var(--ccm-s)); left:calc((9px + 7 * var(--ccm-t)) * var(--ccm-s)); z-index:8;
    display:flex; align-items:center; gap:5px;
    border-radius:6px;
    padding:calc((3px + 2 * var(--ccm-t)) * var(--ccm-s)) calc((5.5px + 3.5 * var(--ccm-t)) * var(--ccm-s)) calc((3px + 2 * var(--ccm-t)) * var(--ccm-s)) calc((4px + 3 * var(--ccm-t)) * var(--ccm-s));
    font-size:calc((9px + 2 * var(--ccm-t)) * var(--ccm-s)); color:rgba(255,255,255,.85);
}
.ccm-group-tag{
    background:rgba(22,22,24,.72);
    font-weight:500;
    text-decoration:none;
    max-width:calc(100% - (60px + 30 * var(--ccm-t)) * var(--ccm-s));
    white-space:nowrap; overflow:hidden;
}
.ccm-group-tag-link{ color:var(--ccm-accent, #ff4c4c); font-weight:700; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ccm-group-tag:hover .ccm-group-tag-link{ text-decoration:underline; }
/* responsive / phone: "From <group>" tag 3% smaller (text, icon, padding, gap) */
@media (max-width:768px){
    .ccm-group-tag{
        gap:calc(5px * .97);
        padding:calc((3px + 2 * var(--ccm-t)) * var(--ccm-s) * .97) calc((5.5px + 3.5 * var(--ccm-t)) * var(--ccm-s) * .97) calc((3px + 2 * var(--ccm-t)) * var(--ccm-s) * .97) calc((4px + 3 * var(--ccm-t)) * var(--ccm-s) * .97);
        font-size:calc((9px + 2 * var(--ccm-t)) * var(--ccm-s) * .97);
    }
}

.ccm-top-right-tags{
    position:absolute; top:calc((56px + 24 * var(--ccm-t)) * var(--ccm-s)); right:calc((9px + 5 * var(--ccm-t)) * var(--ccm-s)); z-index:8;
    display:flex; flex-direction:column; align-items:flex-end; gap:calc((4px + 2 * var(--ccm-t)) * var(--ccm-s));
    pointer-events:none;
}
.ccm-media-counter{
    background:rgba(22,22,24,.82); color:#fff;
    font-size:calc((8px + 2 * var(--ccm-t)) * var(--ccm-s)); font-weight:800; letter-spacing:.3px;
    padding:calc((3px + 2 * var(--ccm-t)) * var(--ccm-s)) calc((5.5px + 3.5 * var(--ccm-t)) * var(--ccm-s)); border-radius:6px;
}
.ccm-type-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(22,22,24,.82); border:none;
    border-radius:6px;
    padding:calc((3.5px + 1.5 * var(--ccm-t)) * var(--ccm-s)) calc((6px + 3 * var(--ccm-t)) * var(--ccm-s)) calc((3.5px + 1.5 * var(--ccm-t)) * var(--ccm-s)) calc((4.5px + 2.5 * var(--ccm-t)) * var(--ccm-s));
}
.ccm-type-tag i{ font-size:calc((9px + 3.5 * var(--ccm-t)) * var(--ccm-s)); color:#fff; }
.ccm-type-tag span{
    font-size:calc((8px + 2 * var(--ccm-t)) * var(--ccm-s)); font-weight:700; color:#fff;
    text-transform:uppercase; letter-spacing:.4px;
    white-space:nowrap;
}

/* ── RIGHT ACTION RAIL ── */
.ccm-rail{
    /* same numbers as the meme modal rail: width-based formulas, x --ccm-s (1.155) on phones */
    position:absolute;
    right:calc(clamp(11px, 9.55px + calc(.7 * var(--ccm-vw, 1vw)), 16px) * var(--ccm-s));
    bottom:calc(clamp(18px, 16.05px + calc(1.18 * var(--ccm-vw, 1vw)), 26px) * var(--ccm-s));
    z-index:9;
    display:flex; flex-direction:column; align-items:center;
    gap:22px;                                   /* fixed px, changes at set widths (below) */
}
.ccm-rail.ccm-rail-raised{ bottom:calc((clamp(18px, 16.05px + calc(1.18 * var(--ccm-vw, 1vw)), 26px) + 12px) * var(--ccm-s)); }
@media (max-width:790px){ .ccm-rail{ gap:22px; } }
@media (max-width:490px){ .ccm-rail{ gap:20px; } }
@media (max-width:390px){ .ccm-rail{ gap:18px; } }
@media (max-width:330px){ .ccm-rail{ gap:15px; } }

.ccm-rail-btn{
    display:flex; flex-direction:column; align-items:center; gap:6px;
    background:none; border:none; cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.ccm-rail-btn i{
    font-size:calc(clamp(19px, 17.03px + calc(1.18 * var(--ccm-vw, 1vw)), 25px) * var(--ccm-s) * .93); color:#fff;     /* icons 7% smaller: measured against Instagram's mobile rail */
    -webkit-text-stroke:0.6px currentColor;
    filter:drop-shadow(0 2px 5px rgba(0,0,0,.55));
    transition:transform .18s ease, color .18s ease;
}
.ccm-rail-btn.active i{ color:#ff3040; }
@keyframes ccmLandPop{ 0%{ transform:scale(.7); } 40%{ transform:scale(1.5); } 70%{ transform:scale(.94); } 100%{ transform:scale(1); } }
.ccm-rail-btn i.ccm-land, i.ccm-land{ animation:ccmLandPop .5s cubic-bezier(.3,.9,.3,1) both; }
.ccm-rail-btn .ccm-share-ico{
    width:calc(clamp(21px, 18.8px + calc(1.3 * var(--ccm-vw, 1vw)), 28px) * var(--ccm-s) * .93); height:calc(clamp(21px, 18.8px + calc(1.3 * var(--ccm-vw, 1vw)), 28px) * var(--ccm-s) * .93);
    color:#fff; display:block; overflow:visible;
    filter:drop-shadow(0 2px 5px rgba(0,0,0,.55));
}
.ccm-rail-btn .ccm-repost-i{ display:block; width:1.166em; height:1.166em; margin:-.083em; line-height:0; font-style:normal; filter:none; }
.ccm-rail-btn.active .ccm-fillable svg{ fill:currentColor; }
.ccm-rail-btn .ccm-repost-i.ccm-rp-big{ width:1.28em; height:1.28em; margin:-.14em -.14em -.03em; }
.ccm-rail-btn .ccm-repost-i svg{ display:block; width:100%; height:100%; overflow:visible; filter:drop-shadow(0 2px 5px rgba(0,0,0,.55)); }
/* same line thickness as the repost icon: its box is 1.28em, the others 1.166em, so their stroke gets 2.2 instead of 2 */
.ccm-rail-btn .ccm-repost-i:not(.ccm-rp-big) svg{ stroke-width:2.2; }
/* comments turned off for this post: the same chat-off icon as the Upload page, no number; the button still opens the sheet */
.ccm-rail-btn .ccm-off-ico{ display:none; font-size:1.166em !important; line-height:1; width:100%; height:100%; text-align:center; font-style:normal; -webkit-text-stroke:0 !important; filter:drop-shadow(0 2px 5px rgba(0,0,0,.55)) !important; }
.ccm-rail-btn.ccm-cm-off .ccm-repost-i svg{ display:none; }
.ccm-rail-btn.ccm-cm-off .ccm-off-ico{ display:block; }
/* menu button: same width and shape as the menu button on the home page (24px-wide icon stretched 18%) */
.ccm-rail-btn i.ri-menu-line{ display:inline-block; font-size:calc(clamp(20px, calc(5 * var(--ccm-vw, 1vw)) - 4px, 22px) * var(--ccm-s)); -webkit-text-stroke:.65px currentColor; transform:scaleX(1.18); }   /* lines as thick as the other rail icons (~2.5px) */
.ccm-rail-btn:active i.ri-menu-line{ transform:scaleX(1.18) scale(1.3); }
.ccm-rail-btn:active i{ transform:scale(1.3); }
/* hidden count (poster hid it): the number is switched off, so the button shrinks to just its icon */
.ccm-rail-count[data-hidden="1"]{ display:none !important; }
.ccm-rail-count{
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
    font-size:calc(clamp(11px, 10.5px + calc(.3 * var(--ccm-vw, 1vw)), 12.5px) * var(--ccm-s) * .96); font-weight:600; letter-spacing:0;
    line-height:1.15;
    color:#fff;
    text-shadow:0 1px 4px rgba(0,0,0,.55);
}

@keyframes ccm-repost-arc-swap {
    0%   { transform: rotate(0deg) scale(1); }
    35%  { transform: rotate(140deg) scale(1.15); }
    50%  { transform: rotate(180deg) scale(1.2); }
    65%  { transform: rotate(220deg) scale(1.15); }
    100% { transform: rotate(360deg) scale(1); }
}
#ccm-repost-btn.arc-swap-active i{
    animation: ccm-repost-arc-swap 0.6s cubic-bezier(.33,1,.68,1);
    transition: none !important;
}

.ccm-rail-msg{ text-decoration:none; }

.ccm-rail-msg-pic{
    width:calc(clamp(22px, 20.03px + calc(1.18 * var(--ccm-vw, 1vw)), 30px) * var(--ccm-s) * 1.05); height:calc(clamp(22px, 20.03px + calc(1.18 * var(--ccm-vw, 1vw)), 30px) * var(--ccm-s) * 1.05);
    object-fit:cover;
    border-radius:50%; border:calc(clamp(19px, 17.03px + calc(1.18 * var(--ccm-vw, 1vw)), 25px) * var(--ccm-s) * 1.166 * .0833) solid #fff;
    box-shadow:0 3px 8px rgba(0,0,0,.55);
    display:block; transition:transform .15s ease;
}
.ccm-rail-msg:active .ccm-rail-msg-pic{ transform:scale(1.12); }


/* ── AD RAIL LABEL ── */
.ccm-rail-ad-label{
    /* same look as the "Ad" tag in the immersive modal: plain red word, no box */
    display:flex; align-items:center; justify-content:center;
    font-size:calc(clamp(10.5px, 10.01px + calc(0.3 * var(--ccm-vw, 1vw)), 12.5px) * var(--ccm-s) * 1.05); font-weight:800; color:#fff;
    letter-spacing:.03em; text-transform:uppercase; user-select:none; pointer-events:none;
    text-shadow:0 1px 4px rgba(0,0,0,.6);
    flex-shrink:0;
}

/* ══════════════════════════════════════════════════════════
   BOTTOM INFO PANEL — profile pic, username, nickname, audio,
   caption, date, dashes. Same proportions as the meme modal.
   ══════════════════════════════════════════════════════════ */
.ccm-bottom{
    position:absolute; left:0; right:0; bottom:0; z-index:7;
    padding:16px 78px 22px 16px;
    background:linear-gradient(to top, rgba(0,0,0,.66) 0%, rgba(0,0,0,.38) 52%, transparent 100%);     /* halfway between the old heavy overlay and the faint one */
}
/* the lines were clipped (tails of g / p / y cut off) because their boxes were as tall as the font and had overflow:hidden:
   give every line room above and below, and pull the margins back so the layout does not move */
.ccm-bottom .ccm-username{ line-height:1.35; padding:.12em 0 .22em; margin:-.12em 0 -.22em; }
.ccm-bottom .ccm-nickname, .ccm-bottom .ccm-ai-label, .ccm-bottom .ccm-partner-line{ line-height:1.4; padding:.1em 0 .2em; margin:-.1em 0 -.2em; overflow:visible; }
.ccm-bottom .ccm-audio-track{ padding:.15em 0 .25em; margin:-.15em 0 -.25em; }
.ccm-bottom .ccm-audio-track-text{ line-height:1.4; }
.ccm-bottom .ccm-info-switcher{ overflow:visible; }
/* secondary lines (nickname, audio, AI label, paid partnership): Facebook's soft white, easy to read */
.ccm-bottom .ccm-nickname, .ccm-bottom .ccm-ai-label, .ccm-bottom .ccm-partner-line, .ccm-bottom .ccm-audio-track-text{ color:rgba(255,255,255,.92); text-shadow:0 1px 3px rgba(0,0,0,.75), 0 0 8px rgba(0,0,0,.45); }
.ccm-bottom .ccm-partner-line strong{ color:#fff; font-weight:700; }
.ccm-rail-count{ text-shadow:0 1px 5px rgba(0,0,0,.7), 0 0 2px rgba(0,0,0,.6); }
.ccm-bottom.ccm-no-rail{ padding-right:calc(clamp(9px, 6.49px + calc(1.11 * var(--ccm-vw, 1vw)), 16px) * var(--ccm-s)); }

.ccm-user-row{ display:flex; align-items:center; gap:calc(clamp(8px, 6.94px + calc(0.48 * var(--ccm-vw, 1vw)), 10px) * var(--ccm-s)); margin-bottom:calc(clamp(4px, 3.47px + calc(0.24 * var(--ccm-vw, 1vw)), 5px) * var(--ccm-s)); }
.ccm-user-pic{
    width:calc(clamp(35px, 33.12px + calc(1.61 * var(--ccm-vw, 1vw)), 48px) * var(--ccm-s)); height:calc(clamp(35px, 33.12px + calc(1.61 * var(--ccm-vw, 1vw)), 48px) * var(--ccm-s));
    border-radius:50%; object-fit:cover;
    border:2.5px solid var(--ccm-accent, var(--red,#FF2D2D)); cursor:pointer; flex-shrink:0;
}
/* ── ONLINE DOT (green) — same look as the home page; same class names so the home page's 60s "online" poll keeps these fresh too ── */
#cardCaptionModal .av-wrap{ position:relative; display:inline-flex; flex-shrink:0; line-height:0; text-decoration:none; }
/* Every property is set explicitly (incl. height / min-height / max-height / box-shadow) so a host page's own
   .on-dot rules can never merge with this one. A host can tune the dot with these optional variables:
   --ccm-dot-w (32%)  --ccm-dot-max (16px)  --ccm-dot-offset (2%)
   --ccm-dot-border (inside ring, clamp 1.5–2.5px)  --ccm-dot-shadow (outside ring, 0px)  --ccm-dot-ring (ring colour) */
#cardCaptionModal .on-dot{
    position:absolute;
    right:var(--ccm-dot-offset,2%); bottom:var(--ccm-dot-offset,2%);
    width:var(--ccm-dot-w,32%); height:auto;
    min-width:9px; min-height:0;
    max-width:var(--ccm-dot-max,16px); max-height:none;
    aspect-ratio:1;
    border-radius:50%; background:var(--green,#22c55e);
    border:var(--ccm-dot-border,clamp(1.5px,calc(.5 * var(--ccm-vw, 1vw)),2.5px)) solid var(--ccm-dot-ring,#000);
    box-shadow:0 0 0 var(--ccm-dot-shadow,0px) var(--ccm-dot-ring,#000);
    box-sizing:border-box; pointer-events:none; z-index:3;
}
.ccm-user-info{ display:flex; flex-direction:column; justify-content:center; gap:0px; min-width:0; flex:1; }
.ccm-user-line1{ display:flex; align-items:center; gap:8px; min-width:0; line-height:1.1; margin-bottom:4px; }
/* Dezzme Verified badge: filled flower with the check cut out, right after the username, about the size of the name's letters (like Instagram) */
.ccm-vbadge{ flex:0 0 auto; width:calc(clamp(11.5px, 11.28px + calc(0.33 * var(--ccm-vw, 1vw)), 14px) * var(--ccm-s) * 0.9126); height:calc(clamp(11.5px, 11.28px + calc(0.33 * var(--ccm-vw, 1vw)), 14px) * var(--ccm-s) * 0.9126); margin-left:calc(-3.5px * var(--ccm-s)); color:#fff; display:block; position:relative; top:calc(-1.15px * var(--ccm-s)); filter:drop-shadow(0 1px 2px rgba(0,0,0,.45)); }
.ccm-username{
    color:#fff; font-weight:700; font-size:calc(clamp(11.5px, 11.28px + calc(0.33 * var(--ccm-vw, 1vw)), 14px) * var(--ccm-s) * 0.975); cursor:pointer;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-shadow:0 1px 3px rgba(0,0,0,.5); line-height:1.1;
}
.ccm-nickname{ color:rgba(255,255,255,.5); font-size:calc(clamp(8.93px, 8.47px + calc(0.282 * var(--ccm-vw, 1vw)), 10.81px) * var(--ccm-s)); font-weight:500; margin:0; line-height:1.1; }
/* "AI Generated" — same size as the nickname, plain text, flips with the nickname / audio line */
.ccm-ai-label{ color:rgba(255,255,255,.75); font-size:calc(clamp(8.93px, 8.47px + calc(0.282 * var(--ccm-vw, 1vw)), 10.81px) * var(--ccm-s)); font-weight:600; margin:0; line-height:1.1; }

.ccm-info-switcher{ position:relative; min-height:14px; }
.ccm-switch-pane{ position:absolute; left:0; top:0; right:0; opacity:0; pointer-events:none; transition:opacity .35s ease; }
.ccm-switch-pane.active{ position:relative; opacity:1; pointer-events:all; }

.ccm-audio-tag{ display:flex; align-items:center; gap:7px; max-width:100%; }
.ccm-audio-icon{ display:inline-flex; align-items:flex-end; gap:calc(clamp(1.5px, 1px + calc(0.14 * var(--ccm-vw, 1vw)), 2px) * var(--ccm-s)); height:calc(clamp(8px, 7.01px + calc(0.43 * var(--ccm-vw, 1vw)), 11px) * var(--ccm-s)); flex-shrink:0; }
.ccm-eq-bar{
    width:2.5px; border-radius:2px;
    background:rgba(255,255,255,.75);
    display:inline-block; transform-origin:bottom;
    animation:ccmEqBounce 0.9s ease-in-out infinite;
}
.ccm-eq-bar:nth-child(1){ height:11px; animation-delay:-0.6s; }
.ccm-eq-bar:nth-child(2){ height:calc(clamp(8px, 7.01px + calc(0.43 * var(--ccm-vw, 1vw)), 11px) * var(--ccm-s)); animation-delay:-0.2s; }
.ccm-eq-bar:nth-child(3){ height:11px; animation-delay:-0.9s; }
@keyframes ccmEqBounce{
    0%   { transform:scaleY(0.35); }
    25%  { transform:scaleY(1); }
    50%  { transform:scaleY(0.5); }
    75%  { transform:scaleY(0.85); }
    100% { transform:scaleY(0.35); }
}
.ccm-audio-track{ position:relative; overflow:hidden; max-width:230px; flex:0 1 auto; display:flex; align-items:center; }
.ccm-audio-track-text{
    display:inline-block; white-space:nowrap;
    font-size:calc(clamp(8.46px, 8.07px + calc(0.32 * var(--ccm-vw, 1vw)), 11.75px) * var(--ccm-s)); font-weight:500; line-height:1;
    color:rgba(255,255,255,.7);
}
.ccm-audio-track-text.ccm-marquee{ animation: ccmAudioMarquee var(--marquee-dur,6s) ease-in-out infinite alternate; }
@keyframes ccmAudioMarquee{
    0%   { transform:translateX(0); }
    12%  { transform:translateX(0); }
    88%  { transform:translateX(var(--marquee-dist,0px)); }
    100% { transform:translateX(var(--marquee-dist,0px)); }
}

.ccm-track-btn{
    padding:0; margin:0; border-radius:0;
    border:none; background:transparent;
    color:var(--ccm-accent, #ff2b2b); font-size:calc(clamp(10.5px, 10.01px + calc(0.3 * var(--ccm-vw, 1vw)), 13px) * var(--ccm-s)); font-weight:700;
    cursor:pointer; flex-shrink:0; font-family:Poppins,sans-serif;
    text-decoration:underline; text-decoration-color:var(--ccm-accent, #ff2b2b);
    text-underline-offset:3.5px; text-decoration-thickness:2px;
    transition:opacity .2s, transform .15s;
    -webkit-tap-highlight-color:transparent;
    position:relative; top:-2px;
}
.ccm-bottom .ccm-track-btn, .ccm-bottom .ccm-track-btn.following{ background:none !important; box-shadow:none !important; border:none !important; text-shadow:none !important; backdrop-filter:none !important; -webkit-backdrop-filter:none !important; }
.ccm-track-btn.following{ background:transparent; border:none; color:var(--ccm-accent, #ff2b2b); text-decoration:underline; text-decoration-color:var(--ccm-accent, #ff2b2b); }
.ccm-track-btn:active{ transform:scale(.94); opacity:.7; }
.ccm-user-dot{ color:#fff; font-size:15px; flex-shrink:0; }

.ccm-partnership-tag{
    display:inline-flex; align-items:center; gap:calc(clamp(4px, 3.47px + calc(0.24 * var(--ccm-vw, 1vw)), 6px) * var(--ccm-s));
    margin-top:calc(clamp(4px, 3.02px + calc(0.59 * var(--ccm-vw, 1vw)), 8px) * var(--ccm-s));
    padding:calc(clamp(3px, 2.51px + calc(0.3 * var(--ccm-vw, 1vw)), 5px) * var(--ccm-s)) calc(clamp(6px, 5.02px + calc(0.59 * var(--ccm-vw, 1vw)), 10px) * var(--ccm-s));
    border-radius:calc(clamp(7px, 7.54px + calc(0.08 * var(--ccm-vw, 1vw)), 8px) * var(--ccm-s));
    background:rgba(0,0,0,0.55);
    color:var(--ccm-accent, #ff4c4c);
    font-size:calc(clamp(9px, 8.51px + calc(0.3 * var(--ccm-vw, 1vw)), 11px) * var(--ccm-s));
    font-weight:600;
    max-width:100%;
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
}
.ccm-partnership-tag i{ font-size:calc(clamp(10px, 9.51px + calc(0.45 * var(--ccm-vw, 1vw)), 13px) * var(--ccm-s)); flex-shrink:0; }
.ccm-partnership-tag strong{ color:#fff; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.ccm-caption-wrap{ max-width:100%; }
.ccm-caption-wrap.ccm-expanded{
    max-height:34vh; overflow-y:auto;
    -webkit-overflow-scrolling:touch;
    touch-action:pan-y;
    scrollbar-width:none; -ms-overflow-style:none;
    padding-right:2px;
}
.ccm-caption-wrap.ccm-expanded::-webkit-scrollbar{ display:none; }
.ccm-caption-text{
    color:var(--cap,#fff); font-size:calc(clamp(10.5px, 10.01px + calc(0.3 * var(--ccm-vw, 1vw)), 13px) * var(--ccm-s)); line-height:1.5;
    word-break:break-word; text-shadow:0 1px 3px rgba(0,0,0,.8), 0 0 10px rgba(0,0,0,.5);
    display:inline;
}
.ccm-more-toggle{
    color:var(--more,#fff); font-size:inherit; font-weight:700; line-height:inherit;
    cursor:pointer; display:inline;
    -webkit-tap-highlight-color:transparent;
}
.ccm-ellipsis{ color:var(--cap,rgba(255,255,255,.85)); font-weight:500; }

.ccm-meta-row{
    display:flex; align-items:center; gap:6px; margin-top:calc((6px + 2 * var(--ccm-t)) * var(--ccm-s));
    color:rgba(255,255,255,.55); font-size:calc((10px + var(--ccm-t)) * var(--ccm-s));
}
.ccm-meta-row i{ font-size:11px; }
.ccm-new-tag{ display:inline-block; margin-right:5px; padding:.03em .55em .1em; border-radius:3px; background:rgba(150,150,150,.55); color:#fff; font-size:.82em; font-weight:700; line-height:1.35; letter-spacing:.2px; vertical-align:.04em; text-shadow:none; }   /* sharper corners; extra bottom padding lifts the word to the middle of the box */
/* post date — appears under the caption when it is tapped (same as the meme modal) */
.ccm-cap-date{ display:block; margin-top:calc(4px * var(--ccm-s)); color:rgba(255,255,255,.6); font-size:calc(clamp(9.5px, 9.01px + calc(0.3 * var(--ccm-vw, 1vw)), 11.5px) * var(--ccm-s)); font-weight:500; line-height:1.3; text-shadow:0 1px 3px rgba(0,0,0,.5); }

/* bottom-area step sizes — same breakpoints/values as the meme modal */
@media (max-width:790px){
    .ccm-media-dashes{ margin-left:-14px; margin-right:-70px; width:calc(100% + 84px); }
    .ccm-bottom{ right:0; padding:16px 70px 20px 14px; }
    .ccm-eq-bar:nth-child(1){ height:4.5px; }
    .ccm-eq-bar:nth-child(3){ height:6.5px; }
    .ccm-audio-track{ max-width:210px; }
}
@media (max-width:490px){
    .ccm-media-dashes{ margin-left:-12px; margin-right:-62px; width:calc(100% + 74px); }
    .ccm-bottom{ right:0; padding:13px 62px 18px 12px; }
    .ccm-user-pic{ border-width:2px; }
    .ccm-track-btn{ text-decoration-thickness:2.25px; top:-1.5px; }
    .ccm-eq-bar:nth-child(1){ height:4px; }
    .ccm-eq-bar:nth-child(3){ height:6px; }
    .ccm-audio-track{ max-width:180px; }
}
@media (max-width:390px){
    .ccm-media-dashes{ margin-left:-10px; margin-right:-56px; width:calc(100% + 66px); }
    .ccm-bottom{ right:0; padding:11px 56px 16px 10px; }
    .ccm-track-btn{ text-decoration-thickness:2px; top:-1.5px; }
    .ccm-caption-text{ line-height:1.45; }
    .ccm-eq-bar:nth-child(1){ height:3.5px; }
    .ccm-eq-bar:nth-child(3){ height:5.5px; }
    .ccm-audio-track{ max-width:150px; }
}
@media (max-width:330px){
    .ccm-media-dashes{ margin-left:-9px; margin-right:-50px; width:calc(100% + 59px); }
    .ccm-bottom{ right:0; padding:10px 50px 14px 9px; }
    .ccm-user-pic{ border-width:1.5px; }
    .ccm-track-btn{ text-decoration-thickness:2px; top:-1px; }
    .ccm-caption-text{ line-height:1.4; }
    .ccm-eq-bar:nth-child(1){ height:3px; }
    .ccm-eq-bar:nth-child(3){ height:5px; }
    .ccm-audio-track{ max-width:130px; }
}

/* ── SOCIAL PROOF / CTA PILL / CONNECTED PILL (share the same slot) ── */
.ccm-social-proof-strip{
    position:absolute; left:calc((9px + 7 * var(--ccm-t)) * var(--ccm-s)); bottom:calc((110px + 65 * var(--ccm-t)) * var(--ccm-s)); z-index:6;
    display:flex; flex-direction:row-reverse; gap:8px;
    pointer-events:none;
    transition:opacity .22s ease, transform .22s ease, visibility .22s ease;
    opacity:1;
}
.ccm-social-proof-strip.ccm-sp-co{ margin-bottom:calc(var(--ccm-poster-h, 40px) * .5); }   /* a collab cluster is taller than one picture: lift the strip so it never touches it (dashes / progress bar) */
.ccm-media-dashes ~ .ccm-social-proof-strip.ccm-sp-co{ }
#ccm-slide:has(.ccm-media-dashes) .ccm-social-proof-strip.ccm-sp-co,
#ccm-slide:has(.ccm-video-progress[style*="display:flex"]) .ccm-social-proof-strip.ccm-sp-co{ margin-bottom:calc(var(--ccm-poster-h, 40px) * .5 + (110px + 65 * var(--ccm-t)) * var(--ccm-s) * .08); }   /* with dashes / the progress bar: another 8% higher */
.ccm-social-proof-strip.ccm-sp-hidden{ opacity:0; transform:translateY(10px); visibility:hidden; }
.ccm-social-proof-strip.ccm-sp-hidden .ccm-sp-avatar-wrap{ pointer-events:none; }
.ccm-social-proof-strip.ccm-sp-raised{ bottom:calc((122px + 73 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-sp-avatar-wrap{ position:relative; pointer-events:all; display:inline-block; text-decoration:none; }
.ccm-sp-avatar-wrap:nth-child(1){ animation:ccmSpFloat 3s ease-in-out infinite; }
.ccm-sp-avatar-wrap:nth-child(2){ animation:ccmSpFloat 3s ease-in-out infinite .4s; }
.ccm-sp-avatar-wrap:nth-child(3){ animation:ccmSpFloat 3s ease-in-out infinite .8s; }
.ccm-sp-avatar-wrap:nth-child(4){ animation:ccmSpFloat 3s ease-in-out infinite 1.2s; }
@keyframes ccmSpFloat{ 0%,100%{ transform:translateY(0); } 50%{ transform:translateY(-6px); } }
.ccm-sp-avatar{
    box-sizing:border-box;   /* total size incl. ring = poster pic */
    width:calc(var(--ccm-poster-h, calc(39.6px * var(--ccm-s))) * 1.003); height:calc(var(--ccm-poster-h, calc(39.6px * var(--ccm-s))) * 1.003);   /* the old 9%-bigger size (1.09) made 8% smaller = about the poster picture size */
    border-radius:50%; object-fit:cover;
    border:calc((2px + var(--ccm-t)) * var(--ccm-s)) solid var(--ccm-accent, var(--red,#FF2D2D)); display:block;
    box-shadow:0 4px 12px rgba(0,0,0,.7);
}
.ccm-sp-badge{
    position:absolute; bottom:-2px; right:-2px;
    width:16px; height:16px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    z-index:2;
}
.ccm-sp-badge i{ font-size:8px; color:#fff; line-height:1; }
/* LIKE badge = a real heart (not a heart in a circle): red, outlined in the page background so it stands out from the avatar's red border */
/* both badges (heart + repost) are the size of the like button in the immersive modal */
.ccm-sp-badge.ccm-sp-heart, .ccm-sp-badge.ccm-sp-rep{ --spb:calc(clamp(17px, 15.02px + calc(0.87 * var(--ccm-vw, 1vw)), 22px) * var(--ccm-s)); width:var(--spb); height:var(--spb); }
.ccm-sp-badge.ccm-sp-heart{ background:none; bottom:calc(-5px * var(--ccm-s)); right:calc(-6px * var(--ccm-s)); }
.ccm-sp-badge.ccm-sp-rep{ width:calc(var(--spb) * .88); height:calc(var(--spb) * .88); bottom:calc(-4px * var(--ccm-s)); right:calc(-5px * var(--ccm-s)); background:none; box-shadow:none; }
.ccm-sp-badge.ccm-sp-rep i{ font-size:calc(var(--spb) * .6); color:#fff; line-height:1; display:block; }
.ccm-sp-heart svg{ width:100%; height:100%; display:block; overflow:visible; }
.ccm-sp-heart .sp-ring{ fill:#000; stroke:#000; }
:root[data-theme="light"] .ccm-sp-heart .sp-ring{ fill:#000; stroke:#000; }
.ccm-sp-rep .ccm-ring{ stroke:#000; }
:root[data-theme="light"] .ccm-sp-rep .ccm-ring{ stroke:#000; }
.ccm-sp-heart .sp-fill{ fill:#FF2D2D; }
/* responsive / phone view only: social-proof badges 11% bigger (circle, icon and corner offset) */
@media (max-width:768px){
    .ccm-sp-badge{ width:calc(16px * 1.11); height:calc(16px * 1.11); bottom:calc(-2px * 1.11); right:calc(-2px * 1.11); }
    .ccm-sp-badge i{ font-size:calc(8px * 1.11); }
}

:root{ --ccm-cta-fs:calc((18.7px + 3.3 * var(--ccm-t)) * var(--ccm-s)); }
/* glass colour shared by the resting link pill and the little action buttons (Interested / Not interested / Share this in the Chat) */
:root{ --ccm-glass:rgba(38,38,40,.5); }   /* translucent dark mid-tone grey: the picture still shows through (change this ONE value to retune the pill and every action button) */   /* size of the chevron in the ad link button (the words use the username size) */
.ccm-cta-pill{
    position:absolute; left:calc((9px + 7 * var(--ccm-t)) * var(--ccm-s)); bottom:calc((110px + 65 * var(--ccm-t)) * var(--ccm-s)); z-index:6;
    display:flex; align-items:center; justify-content:flex-start;
    /* resting (inactive) pill: glass colour, no border */
    box-sizing:border-box;
    height:calc(31px * var(--ccm-s));   /* same thickness as the Interested / Not interested buttons */
    padding:0 calc((10px + 4 * var(--ccm-t)) * var(--ccm-s));
    border-radius:8px;
    background:var(--ccm-glass);
   
    border:0;
    color:#fff;
    font-weight:700;
    font-size:calc(clamp(11.5px, 11.28px + calc(0.33 * var(--ccm-vw, 1vw)), 14px) * var(--ccm-s) * 0.95);   /* username size, 5% smaller */
    text-decoration:none;
    box-shadow:none;
    white-space:nowrap;
    overflow:hidden;
    width:auto;
    right:auto;
    transition:right .4s cubic-bezier(.22,1,.36,1), padding .4s cubic-bezier(.22,1,.36,1), height .4s cubic-bezier(.22,1,.36,1),
               background .4s ease, border-color .4s ease, box-shadow .4s ease;
}
.ccm-cta-pill.ccm-sp-raised{ bottom:calc((122px + 73 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-cta-pill-text{ position:relative; z-index:1; overflow:hidden; text-overflow:ellipsis; }
.ccm-cta-pill-chevron{
    display:inline-block;
    font-size:0;
    opacity:0;
    max-width:0;
    overflow:hidden;
    margin-left:0;
    flex-shrink:0;
    transition:opacity .3s ease, max-width .3s ease, margin-left .3s ease, font-size .3s ease;
}
.ccm-cta-pill.ccm-cta-pill-expanded{
    right:calc((46px + 28 * var(--ccm-t)) * var(--ccm-s));
    height:calc(2 * (8px + 5 * var(--ccm-t)) * var(--ccm-s) + var(--ccm-cta-fs));
    padding:0 calc((12px + 6 * var(--ccm-t)) * var(--ccm-s));
    background:var(--cta-color, var(--ccm-accent, #ff4c4c));
    -webkit-backdrop-filter:none; backdrop-filter:none;
    border-color:transparent;
    box-shadow:0 6px 22px rgba(0,0,0,.45);
}
.ccm-cta-pill.ccm-cta-pill-expanded .ccm-cta-pill-chevron{
    opacity:1;
    max-width:calc((26px + 4 * var(--ccm-t)) * var(--ccm-s));
    margin-left:auto;
    padding-left:calc(8px * var(--ccm-s));
    font-size:var(--ccm-cta-fs);   /* the chevron's own size */
    line-height:1;
}

#ccm-nav-hint{
    position:absolute; bottom:8px; left:50%; transform:translateX(-50%);
    color:rgba(255,255,255,.28); font-size:10px; z-index:20;
    pointer-events:none; white-space:nowrap;
}
@media(max-width:490px){ #ccm-nav-hint{ display:none; } }

#ccm-arrows{
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:14px; margin-left:16px; flex-shrink:0;
}
@media(min-width:791px){ #ccm-arrows{ margin-left:calc(16px + var(--ccm-d)); } }   /* the box got narrower: the arrows keep their old place */
#ccm-arrows button{
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);
    border-radius:50%; width:44px; height:44px;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:background .2s ease, transform .15s ease;
    -webkit-tap-highlight-color:transparent;
}
#ccm-arrows button:hover{ background:rgba(255,255,255,.18); }
#ccm-arrows button:active{ transform:scale(.9); }
@media(max-width:790px){ #ccm-arrows{ display:none; } }

/* ── desktop / wide screens: rail buttons sit OUTSIDE the box, between it and the up/down arrows ── */
#ccm-side-rail{ display:none; }
@media(min-width:791px){
    #ccm-side-rail{
        display:flex; flex-direction:column; align-items:center; gap:18px; flex-shrink:0;
        margin-left:16px; align-self:flex-end;
        margin-bottom:calc((100vh - var(--ccm-h)) / 2);
        transition:opacity .22s ease;
    }
    #ccm-stage .ccm-rail{ display:none !important; }
    #ccm-side-rail{ gap:18px; margin-left:calc(14px - var(--ccm-d)); }   /* hugs the (narrower) box */
    #ccm-side-rail .ccm-rail-btn{ gap:5px; }
    #ccm-side-rail .ccm-rail-btn i{ font-size:22px; }
    #ccm-side-rail .ccm-rail-btn i.ri-menu-line{ font-size:19px; }
    #ccm-side-rail .ccm-rail-btn .ccm-share-ico{ width:22px; height:22px; }
    #ccm-side-rail .ccm-rail-count{ font-size:11px; }
    #ccm-side-rail .ccm-rail-msg-pic, #cardCaptionModal #ccm-side-rail .ccm-rail-msg .ccm-rail-msg-pic{ width:30px !important; height:30px !important; }
    #ccm-side-rail .ccm-rail-msg-pic:hover{ transform:scale(1.06); }
    #ccm-side-rail .ccm-rail-btn:hover i{ transform:scale(1.08); }
    .ccm-bottom{ padding-right:16px; }
    /* laptop: the rail is outside the box, so the bottom area only has 16px each side; the dashes now fill exactly the box width and stay inside it */
    .ccm-media-dashes{ margin-left:-16px; margin-right:-16px; width:calc(100% + 32px); }
}

/* ══════════════════════════════════════════════════════════
   SUGGESTED USERS — blurred 4-way split backdrop, paged 2x2 grid
   ══════════════════════════════════════════════════════════ */
.ccm-sugg-slide{ position:relative; width:100%; height:100%; overflow:hidden; background:#000; transform:translateZ(0); backface-visibility:hidden; -webkit-backface-visibility:hidden; contain:paint; }

.ccm-sugg-bg{
    position:absolute; inset:0;
    display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr;
    z-index:0;
}
.ccm-sugg-bgc{ display:block; width:100%; height:100%; grid-column:1 / -1; grid-row:1 / -1; image-rendering:auto; }
.ccm-sugg-bg-tile{
    background-size:cover; background-position:center;
    background-color:#141418;
    /* no blur filter / scale here any more: that heavy layer re-painted (a light flash) every time the slide slid back in */
}
.ccm-sugg-overlay{ position:absolute; inset:0; z-index:1; background:rgba(0,0,0,.3); }

.ccm-sugg-header{
    position:absolute; top:0; left:0; right:0; z-index:5;
    padding:calc((11px + 3 * var(--ccm-t)) * var(--ccm-s)) calc((8px + 6 * var(--ccm-t)) * var(--ccm-s)) calc((16px + 8 * var(--ccm-t)) * var(--ccm-s)) calc((12px + 4 * var(--ccm-t)) * var(--ccm-s));
    background:linear-gradient(to bottom, rgba(0,0,0,.7) 0%, rgba(0,0,0,.3) 65%, transparent 100%);
    display:flex; align-items:center; gap:8px;
}
.ccm-sugg-header-text{
    flex:1; min-width:0;
    display:flex; align-items:center; gap:calc((5px + 3 * var(--ccm-t)) * var(--ccm-s));
    min-height:42px;
}
.ccm-sugg-title{
    flex-shrink:0; white-space:nowrap;
    font-size:calc((12.5px + 2.5 * var(--ccm-t)) * var(--ccm-s)); font-weight:800; color:#fff; font-family:Poppins,sans-serif;
}
.ccm-sugg-subtitle{
    min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    font-size:calc((9.5px + 2 * var(--ccm-t)) * var(--ccm-s)); color:rgba(255,255,255,.55); margin-top:0;
}
.ccm-sugg-header-spacer{ width:30px; flex-shrink:0; }
/* no back button on the suggested-users slide: the fixed one is hidden while this slide is current */
#cardCaptionModal.ccm-on-sugg #ccmFixedBackBtn{ visibility:hidden; pointer-events:none; }

.ccm-sugg-pages{
    position:relative; z-index:2;
    width:100%; height:100%;
    display:flex;
    transition:transform .32s cubic-bezier(.22,1,.36,1);
}
.ccm-sugg-page{
    flex:0 0 100%; width:100%; height:100%;
    display:flex; align-items:center; justify-content:center;
    box-sizing:border-box;
    padding:calc((46px + 32 * var(--ccm-t)) * var(--ccm-s)) calc((8px + 6 * var(--ccm-t)) * var(--ccm-s)) calc((38px + 20 * var(--ccm-t)) * var(--ccm-s));
}
.ccm-sugg-grid2{
    width:100%; height:100%;
    display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr;
    gap:calc((6px + 4 * var(--ccm-t)) * var(--ccm-s));
}
.ccm-sugg-card{
    position:relative;
    border-radius:calc((13px + 7 * var(--ccm-t)) * var(--ccm-s)); overflow:hidden;
    background:rgba(40,40,46,.62);
    border:1px solid rgba(255,255,255,.14);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:calc((5px + 3 * var(--ccm-t)) * var(--ccm-s)); padding:calc((8px + 4 * var(--ccm-t)) * var(--ccm-s)) calc((4px + 4 * var(--ccm-t)) * var(--ccm-s));
    transition:opacity .3s ease, transform .3s ease;
}
.ccm-sugg-card.dismissed{ opacity:0; transform:scale(.9); pointer-events:none; }

.ccm-sugg-close{
    position:absolute; top:calc((5px + 3 * var(--ccm-t)) * var(--ccm-s)); right:calc((5px + 3 * var(--ccm-t)) * var(--ccm-s)); z-index:3;
    width:calc((18px + 6 * var(--ccm-t)) * var(--ccm-s)); height:calc((18px + 6 * var(--ccm-t)) * var(--ccm-s)); border-radius:50%;
    background:rgba(0,0,0,.45); border:none; color:#fff;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; font-size:calc((10px + 4 * var(--ccm-t)) * var(--ccm-s)); padding:0;
    -webkit-tap-highlight-color:transparent;
}
.ccm-sugg-card-link{
    display:flex; flex-direction:column; align-items:center; gap:calc((5px + 3 * var(--ccm-t)) * var(--ccm-s));
    text-decoration:none; color:inherit; width:100%;
}
.ccm-sugg-card img{
    width:calc((40px + 20 * var(--ccm-t)) * var(--ccm-s)); height:calc((40px + 20 * var(--ccm-t)) * var(--ccm-s));
    border-radius:50%; object-fit:cover;
    border:calc((2px + var(--ccm-t)) * var(--ccm-s)) solid var(--ccm-accent, var(--red,#FF2D2D)); display:block;
    box-shadow:0 4px 14px rgba(0,0,0,.4);
}
.ccm-sugg-card-name{
    font-size:calc((10px + 3 * var(--ccm-t)) * var(--ccm-s)); font-weight:700; color:#fff;
    display:flex; align-items:center; justify-content:center; gap:calc(3px * var(--ccm-s)); max-width:100%; min-width:0;
}
.ccm-sugg-card-name-t{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
.ccm-vbadge.ccm-vbadge-co{ width:.95em !important; height:.95em !important; color:#FF2D2D !important; display:inline-block !important; position:relative !important; margin:0 0 0 calc(2px * var(--ccm-s)) !important; vertical-align:middle; filter:none !important; }
.ccm-vbadge.ccm-vbadge-sugg{ width:1.05em; height:1.05em; margin-left:0; position:static; filter:none; }
.ccm-sugg-card-meta{
    display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2; overflow:hidden;
    max-width:100%; box-sizing:border-box; padding:0 2px; text-align:center; line-height:1.25; word-break:break-word;
    font-size:calc((8px + 1.6 * var(--ccm-t)) * var(--ccm-s)); font-weight:500; color:rgba(255,255,255,.6);
}

.ccm-sugg-card-track{
    width:86%; margin-top:1px;
    font-size:calc((9px + 2 * var(--ccm-t)) * var(--ccm-s)); font-weight:700; padding:calc((5.5px + 1.5 * var(--ccm-t)) * var(--ccm-s)) 0;
    border-radius:9px; border:1.5px solid var(--ccm-accent, #ff2b2b);
    background:transparent; color:var(--ccm-accent, #ff2b2b);
    cursor:pointer; transition:all .2s;
    font-family:Poppins,sans-serif;
    -webkit-tap-highlight-color:transparent;
}
.ccm-sugg-card-track:hover{ background:rgba(var(--ccm-accent-rgb, 255,43,43), .12); }
.ccm-sugg-card-track.tracking{ background:var(--ccm-accent, #ff2b2b); color:var(--ccm-on-accent, #fff); border-color:var(--ccm-accent, #ff2b2b); }

.ccm-sugg-dots{
    position:absolute; left:0; right:0; bottom:calc((9px + 7 * var(--ccm-t)) * var(--ccm-s)); z-index:3;
    display:flex; align-items:center; justify-content:center; gap:calc((5px + 3 * var(--ccm-t)) * var(--ccm-s));
}
.ccm-sugg-dot{
    width:7px; height:7px; border-radius:50%;
    background:rgba(255,255,255,.35);
    cursor:pointer; transition:all .2s ease;
    -webkit-tap-highlight-color:transparent;
}
.ccm-sugg-dot.active{ background:var(--ccm-accent, #ff2b2b); width:18px; border-radius:4px; }

.ccm-sugg-arrow{
    position:absolute; top:37%; transform:translateY(-50%);
    width:calc((24px + 8 * var(--ccm-t)) * var(--ccm-s)); height:calc((24px + 8 * var(--ccm-t)) * var(--ccm-s)); border-radius:50%;
    background:rgba(10,11,20,.72); touch-action:manipulation;
    display:flex; align-items:center; justify-content:center;
    color:#fff; cursor:pointer; z-index:4;
    opacity:0; pointer-events:none;
    transition:opacity .2s ease;
    -webkit-tap-highlight-color:transparent;
}
#cardCaptionModal.ccm-has-mouse .ccm-sugg-slide:hover .ccm-sugg-arrow{ opacity:1; pointer-events:auto; }
.ccm-sugg-arrow.ccm-sugg-arrow-disabled{ opacity:0 !important; pointer-events:none !important; }
.ccm-sugg-arrow-left{ left:4px; }
.ccm-sugg-arrow-right{ right:4px; }
.ccm-sugg-arrow i{ font-size:calc((14px + 5 * var(--ccm-t)) * var(--ccm-s)); }

/* ══════════ SWIPE LEFT → POSTER'S PROFILE ("peek" panel that slides in from the right) ══════════ */
#ccm-peek{
    position:absolute; inset:0; z-index:30;
    background:#0c0c10;
    transform:translate3d(100%,0,0);
    visibility:hidden;
    pointer-events:none;
    will-change:transform;
    --k:0;
}
#ccm-peek.ccm-peek-on{ visibility:visible; pointer-events:all; }
.ccm-peek-inner{
    position:absolute; inset:0;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:calc(12px * var(--ccm-s));
}
.ccm-peek-pic{
    width:calc(92px * var(--ccm-s)); height:calc(92px * var(--ccm-s));
    border-radius:50%; object-fit:cover;
    border:3px solid var(--ccm-accent, var(--red,#FF2D2D));
    box-shadow:0 10px 34px rgba(0,0,0,.55);
    transform:scale(calc(.8 + .2 * var(--k)));
    opacity:calc(.35 + .65 * var(--k));
}
.ccm-peek-name{
    color:#fff; font-weight:700; font-family:Poppins,sans-serif;
    font-size:calc(15px * var(--ccm-s));
    opacity:calc(.35 + .65 * var(--k));
}
.ccm-peek-line{
    height:10px; border-radius:5px;
    background:linear-gradient(120deg,#17171b 25%,#232329 37%,#17171b 63%);
    background-size:400% 100%;
    animation:ccmSkelPulse 1.3s ease-in-out infinite;
}
.ccm-peek-line.l1{ width:calc(150px * var(--ccm-s)); }
.ccm-peek-line.l2{ width:calc(110px * var(--ccm-s)); }
/* the REAL profile page, loaded in a hidden frame while you drag, so it is already on screen as the panel slides in */
.ccm-peek-frame{
    position:absolute; inset:0; width:100%; height:100%; border:0; background:#0c0c10;
    opacity:0; transition:opacity .08s ease; pointer-events:none; z-index:2;
}
.ccm-peek-frame.ccm-ready{ opacity:1; }
.ccm-peek-spin{
    width:22px; height:22px; border-radius:50%;
    border:2.5px solid rgba(255,255,255,.18); border-top-color:var(--ccm-accent, #ff2b2b);
    opacity:0; transition:opacity .2s ease;
    animation:ccmPeekSpin .7s linear infinite;
}
#ccm-peek.ccm-peek-loading .ccm-peek-spin{ opacity:1; }
@keyframes ccmPeekSpin{ to{ transform:rotate(360deg); } }

/* ══════════ BUFFERING SPINNER ══════════
   Skeleton = the screen is empty and being set up.  Spinner = a picture is already there and the video is catching up
   (buffering mid-playback, or after a seek). Never shown together; only after 0.4 s so short waits never flash it. */
.ccm-buf-spin{
    position:absolute; left:50%; top:50%; z-index:6; pointer-events:none;
    width:calc(54px * var(--ccm-s)); height:calc(54px * var(--ccm-s)); margin:calc(-27px * var(--ccm-s)) 0 0 calc(-27px * var(--ccm-s));
    border-radius:50%; background:rgba(0,0,0,.38);
    opacity:0; transform:scale(.8); transition:opacity .18s ease, transform .22s cubic-bezier(.34,1.56,.64,1);
}
.ccm-buf-spin.show{ opacity:1; transform:scale(1); }
.ccm-buf-spin::after{
    content:''; position:absolute; inset:calc(9px * var(--ccm-s)); border-radius:50%;
    border:calc(3px * var(--ccm-s)) solid rgba(255,255,255,.22); border-top-color:#fff;
    animation:ccmBufSpin .75s linear infinite;
}
@keyframes ccmBufSpin{ to{ transform:rotate(360deg); } }
@media (prefers-reduced-motion:reduce){ .ccm-buf-spin::after{ animation-duration:1.6s; } }

/* ══════════ "NOT INTERESTED" CARD (with Undo) ══════════ */
/* ── "Interested / Not interested" — two buttons that pop up at the bottom once in a while on suggested posts ── */
.ccm-ask{
    position:absolute; z-index:12; left:calc(6px * var(--ccm-s)); right:calc(6px * var(--ccm-s)); bottom:calc(9px * var(--ccm-s));
    display:flex; gap:calc(8px * var(--ccm-s));
    opacity:0; transform:translateY(10px); pointer-events:none;
    transition:opacity .25s ease, transform .3s ease;
}
.ccm-ask.show{ opacity:1; transform:none; pointer-events:auto; }
.ccm-ask button{
    flex:1 1 0; min-width:0; height:calc(31px * var(--ccm-s)); border-radius:calc(5px * var(--ccm-s)); cursor:pointer; padding:0;
    display:flex; align-items:center; justify-content:center; gap:calc(5px * var(--ccm-s));
    font-family:Poppins,sans-serif; font-size:calc((11px + 1.5 * var(--ccm-t)) * var(--ccm-s)); font-weight:600; color:#fff; line-height:1;
    background:var(--ccm-glass); border:0;   /* same glass as the resting link pill */
    -webkit-tap-highlight-color:transparent; transition:transform .12s ease, opacity .12s ease;
}
.ccm-ask button:active{ transform:scale(.96); opacity:.75; }
.ccm-ask button i{ font-size:calc(14px * var(--ccm-s)); line-height:1; }
.ccm-ask .ccm-jet{ width:calc(16px * var(--ccm-s)); height:calc(16px * var(--ccm-s)); margin-right:calc(8px * var(--ccm-s)); flex:0 0 auto; display:block; }
.ccm-ask .ccm-ask-share{ gap:0; }
/* "Use this sound" (audio page): red button with dark words, same size and spot as "Share this in the Chat" */
.ccm-ask .ccm-ask-sound{ background:var(--ccm-accent, #FF2D2D); color:#14070a; gap:calc(7px * var(--ccm-s)); }
.ccm-ask .ccm-ask-sound i{ font-size:calc(16px * var(--ccm-s)); color:#14070a; }
.ccm-ask .ccm-ask-yes i{ color:var(--ccm-accent, var(--red,#FF2D2D)); }
.ccm-ask .ccm-ask-thanks{
    flex:1 1 auto; height:calc(31px * var(--ccm-s)); border-radius:calc(5px * var(--ccm-s)); display:flex; align-items:center; justify-content:center; padding:0 10px; text-align:center;
    font-family:Poppins,sans-serif; font-size:calc((11px + 1.5 * var(--ccm-t)) * var(--ccm-s)); font-weight:600; color:#fff; line-height:1.2;
    background:var(--ccm-glass); border:0;
}
/* while the buttons are up, the caption block and the rail make room above them */
#cardCaptionModal .ccm-bottom, #cardCaptionModal .ccm-rail, #cardCaptionModal .ccm-social-proof-strip, #cardCaptionModal .ccm-cta-pill, #cardCaptionModal .ccm-connected-tag{ transition:margin-bottom .28s ease, opacity .22s ease, transform .22s ease, visibility .22s ease; }
#cardCaptionModal:is(.ccm-asking,.ccm-ask-pending) .ccm-rail,
#cardCaptionModal:is(.ccm-asking,.ccm-ask-pending) .ccm-social-proof-strip, #cardCaptionModal:is(.ccm-asking,.ccm-ask-pending) .ccm-cta-pill, #cardCaptionModal:is(.ccm-asking,.ccm-ask-pending) .ccm-connected-tag{ margin-bottom:calc(38px * var(--ccm-s)); }
/* avatars (social proof) sit 8% higher while the buttons are up, so they clear the dark bottom area; the Full-screen pill rises with the caption block */
#cardCaptionModal:is(.ccm-asking,.ccm-ask-pending) .ccm-social-proof-strip{ margin-bottom:calc((38px + 0.08 * (110px + 65 * var(--ccm-t))) * var(--ccm-s)); }
#cardCaptionModal .ccm-fs-pill{ transition:transform .28s ease; }
#cardCaptionModal:is(.ccm-asking,.ccm-ask-pending) .ccm-fs-pill, #cardCaptionModal .ccm-slide:has(.ccm-ask-static) .ccm-fs-pill{ top:var(--fs-top-up) !important; }
/* order from the top: dashes, progress bar, then the two buttons underneath, all on the same dark fog */
#cardCaptionModal .ccm-bottom{ transition:padding-bottom .28s ease; }
#cardCaptionModal:is(.ccm-asking,.ccm-ask-pending) .ccm-bottom{ padding-bottom:calc(20px + 38px * var(--ccm-s)); }
#cardCaptionModal:is(.ccm-asking,.ccm-ask-pending) .ccm-video-progress{ bottom:calc(40px * var(--ccm-s)) !important; }
/* the chat page's "Share this in the Chat" button is part of the post's own slide, so it is there from the first frame (also while the slide is still sliding in) */
.ccm-ask.ccm-ask-static{ opacity:1; transform:none; pointer-events:auto; transition:none; }
#cardCaptionModal .ccm-slide:has(.ccm-ask-static) .ccm-rail,
#cardCaptionModal .ccm-slide:has(.ccm-ask-static) .ccm-social-proof-strip, #cardCaptionModal .ccm-slide:has(.ccm-ask-static) .ccm-cta-pill, #cardCaptionModal .ccm-slide:has(.ccm-ask-static) .ccm-connected-tag{ margin-bottom:calc(38px * var(--ccm-s)); transition:none; }
#cardCaptionModal .ccm-slide:has(.ccm-ask-static) .ccm-bottom{ padding-bottom:calc(20px + 38px * var(--ccm-s)); transition:none; }
#cardCaptionModal .ccm-slide:has(.ccm-ask-static) .ccm-video-progress{ bottom:calc(40px * var(--ccm-s)) !important; }
.ccm-ni-pop{
    position:absolute; z-index:60; left:calc((52px + 6 * var(--ccm-t)) * var(--ccm-s)); right:calc((92px + 10 * var(--ccm-t)) * var(--ccm-s));   /* sits between the back button and the "Zeems" word */
    top:calc((8px + 3 * var(--ccm-t)) * var(--ccm-s));
    padding:calc(14px * var(--ccm-s)) calc(14px * var(--ccm-s)) calc(10px * var(--ccm-s));
    border-radius:calc(5px * var(--ccm-s));
    background:#070924; border:1px solid rgba(255,255,255,.14); box-shadow:0 10px 30px rgba(0,0,0,.55);
    text-align:center; color:#fff; font-family:Poppins,sans-serif;
    opacity:0; transform:translateY(-10px) scale(.94); pointer-events:none;
    transition:opacity .22s ease, transform .4s cubic-bezier(.34,1.56,.64,1);
}
.ccm-ni-pop.show{ opacity:1; transform:none; pointer-events:auto; }
.ccm-ni-text{ font-size:calc((13px + 2 * var(--ccm-t)) * var(--ccm-s)); font-weight:600; line-height:1.3; margin-bottom:calc(10px * var(--ccm-s)); }
.ccm-ni-undo{
    display:block; width:100%; border:0; cursor:pointer; font-family:inherit;
    padding:calc(7px * var(--ccm-s)) 0; border-radius:calc(4px * var(--ccm-s));
    background:rgba(255,255,255,.12); color:#fff; font-weight:700; font-size:calc((12px + 2 * var(--ccm-t)) * var(--ccm-s));
}
.ccm-ni-undo:active{ background:rgba(255,255,255,.24); }

/* ══════════ SAVED TOAST ══════════ */
/* the Save-to-Collection sheet (shared_modals) must sit above the Zeems modal on every page (search lifts the modal's z-index very high) */
#svshOverlay{ z-index:2147483600 !important; }
#svshPanel{ z-index:2147483601 !important; }
.ccm-saved-toast{
    position:fixed;
    left:calc((3px + 3 * var(--ccm-t)) * var(--ccm-s)); right:calc((3px + 3 * var(--ccm-t)) * var(--ccm-s));
    bottom:calc((14px + 16 * var(--ccm-t)) * var(--ccm-s) + env(safe-area-inset-bottom));
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:calc((5px + 3 * var(--ccm-t)) * var(--ccm-s));
    padding:calc((10px + 3 * var(--ccm-t)) * var(--ccm-s)) calc((13px + 3 * var(--ccm-t)) * var(--ccm-s));
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;
    z-index:2147483640;
    font-family:Poppins,sans-serif;
    transform:translateY(120%);
    opacity:0;
    transition:transform .28s cubic-bezier(.32,.72,0,1), opacity .28s ease;
    box-shadow:0 8px 24px rgba(0,0,0,0.35);
}
.ccm-saved-toast.show{ transform:translateY(0); opacity:1; }
.ccm-saved-toast-left{
    display:flex;align-items:center;gap:calc((7px + 3 * var(--ccm-t)) * var(--ccm-s));
    color:#fff;font-size:calc((12.5px + 2 * var(--ccm-t)) * var(--ccm-s));font-weight:600;
}
.ccm-saved-toast-check{
    width:calc((17px + 3 * var(--ccm-t)) * var(--ccm-s));height:calc((17px + 3 * var(--ccm-t)) * var(--ccm-s));border-radius:50%;
    background:#f24455;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:calc((10px + 2 * var(--ccm-t)) * var(--ccm-s));flex-shrink:0;
}
.ccm-saved-toast-right{
    display:flex;align-items:center;gap:2px;
    color:#f24455;font-size:calc((12.5px + 2 * var(--ccm-t)) * var(--ccm-s));font-weight:600;
    cursor:pointer;-webkit-tap-highlight-color:transparent;
    flex-shrink:0;
    transition:opacity .15s ease;
}
.ccm-saved-toast-right:active{ opacity:0.7; }
.ccm-saved-toast-right i{ font-size:calc((14.5px + 2.5 * var(--ccm-t)) * var(--ccm-s)); }

/* ══════════ CONNECTED ZEEM — HORIZONTAL LAYER STACK ══════════ */
#ccm-hstack{ position:absolute; inset:0; z-index:12; overflow:hidden; }
.ccm-hlayer{ position:absolute; inset:0; width:100%; height:100%; background:#000; will-change:transform; }

.ccm-connected-tag{
    position:absolute; left:calc((9px + 7 * var(--ccm-t)) * var(--ccm-s)); bottom:calc((128px + 65 * var(--ccm-t)) * var(--ccm-s)); z-index:6;
    display:flex; align-items:center; gap:calc((5px + 3 * var(--ccm-t)) * var(--ccm-s));
    padding:calc((2.5px + 1.5 * var(--ccm-t)) * var(--ccm-s)) calc((8px + 5 * var(--ccm-t)) * var(--ccm-s)) calc((2.5px + 1.5 * var(--ccm-t)) * var(--ccm-s)) calc((6px + 4 * var(--ccm-t)) * var(--ccm-s));
    border-radius:calc((6px + 2 * var(--ccm-t)) * var(--ccm-s));
    background:rgba(20,20,23,.96);
    border:1px solid rgba(20,20,23,.96);
    box-shadow:0 6px 18px rgba(0,0,0,.4);
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background .18s ease, transform .15s ease, box-shadow .18s ease, opacity .22s ease;
    max-width:calc(100% - 32px);
    text-decoration:none;
}
.ccm-connected-tag:hover{ background:rgba(30,30,34,.98); border-color:rgba(30,30,34,.98); box-shadow:0 8px 22px rgba(0,0,0,.5); }
.ccm-connected-tag:active{ transform:scale(.96); }
.ccm-connected-tag.ccm-sp-raised{ bottom:calc((140px + 73 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-connected-tag.ccm-sp-hidden{ opacity:0 !important; transform:translateY(10px) !important; visibility:hidden; pointer-events:none !important; }
/* the CTA pill and the Connected button fade away with the caption's "more", and come back on "see less" — same motion as the social-proof avatars */
.ccm-cta-pill.ccm-sp-hidden{ opacity:0 !important; transform:translateY(10px) !important; visibility:hidden; pointer-events:none !important; }
.ccm-cta-pill, .ccm-connected-tag{ transition:opacity .22s ease, transform .22s ease, visibility .22s ease, background .18s ease, box-shadow .18s ease; }
/* poll pill: the resting link pill's glass, but SQUARE like the Interested / Not interested buttons, with a little poll icon */
.ccm-cta-pill.ccm-poll-pill{
    cursor:pointer; margin:0; -webkit-appearance:none; appearance:none; text-align:left; gap:calc(7px * var(--ccm-s));
    border-radius:calc(5px * var(--ccm-s));
    font-family:Poppins,sans-serif; font-size:calc((11px + 1.5 * var(--ccm-t)) * var(--ccm-s)); font-weight:600; line-height:1;
    -webkit-tap-highlight-color:transparent;
}
.ccm-cta-pill.ccm-poll-pill:active{ transform:scale(.97); opacity:.8; }
.ccm-poll-ico{ width:calc(15px * var(--ccm-s)); height:calc(15px * var(--ccm-s)); flex:0 0 auto; display:block; }
/* resting CTA pill + poll pill thinner: phone = 12.5% less, desktop = 10% less (thickness and text) */
:root{ --ccm-pill-k:.875; }
@media (max-width:768px){ :root{ --ccm-pill-k:.91875; } }   /* phone: 5% thicker + bigger words than before */
@media (min-width:769px){ :root{ --ccm-pill-k:.9; } }
.ccm-cta-pill:not(.ccm-cta-pill-expanded){ height:calc(31px * var(--ccm-s) * var(--ccm-pill-k)); font-size:calc(clamp(11.5px, 11.28px + calc(0.33 * var(--ccm-vw, 1vw)), 14px) * var(--ccm-s) * 0.95 * var(--ccm-pill-k)); }
.ccm-cta-pill.ccm-poll-pill{ font-size:calc((11px + 1.5 * var(--ccm-t)) * var(--ccm-s) * var(--ccm-pill-k)); }
.ccm-poll-ico{ width:calc(15px * var(--ccm-s) * var(--ccm-pill-k)); height:calc(15px * var(--ccm-s) * var(--ccm-pill-k)); }
/* sits below the CTA pill when the post is both an ad and connected */
.ccm-connected-tag.ccm-connected-below-cta{ bottom:calc((83px + 65 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-connected-tag.ccm-connected-below-cta.ccm-sp-raised{ bottom:calc((95px + 73 * var(--ccm-t)) * var(--ccm-s)); }
/* View Zeem button: same background design as the voted / resting CTA pill (glass, no border, no shadow) */
.ccm-connected-tag, .ccm-connected-tag:hover{ background:var(--ccm-glass); border:0; box-shadow:none; }
.ccm-connected-tag{ border-radius:calc(5px * var(--ccm-s)); }
.ccm-connected-tag:hover{ filter:brightness(1.15); }
/* the pills (CTA, poll, View Zeem) rise like the social-proof avatars so they never touch the poster block:
   - collab post: half a poster picture higher (the stacked pictures are taller)
   - dashes or the video progress bar showing: 8% higher
   - Interested / Share-in-chat buttons showing: 38px + 8% higher */
.ccm-pill-co{ --ccm-pill-lift:calc(var(--ccm-poster-h, 40px) * .5); }
.ccm-cta-pill.ccm-pill-co{ bottom:calc((110px + 65 * var(--ccm-t)) * var(--ccm-s) + var(--ccm-pill-lift)); }
.ccm-cta-pill.ccm-pill-co.ccm-sp-raised{ bottom:calc((122px + 73 * var(--ccm-t)) * var(--ccm-s) + var(--ccm-pill-lift)); }
.ccm-connected-tag.ccm-pill-co{ bottom:calc((128px + 65 * var(--ccm-t)) * var(--ccm-s) + var(--ccm-pill-lift)); }
.ccm-connected-tag.ccm-pill-co.ccm-connected-below-cta{ bottom:calc((83px + 65 * var(--ccm-t)) * var(--ccm-s) + var(--ccm-pill-lift)); }
#ccm-slide:has(.ccm-media-dashes) .ccm-cta-pill, #ccm-slide:has(.ccm-media-dashes) .ccm-connected-tag,
#ccm-slide:has(.ccm-video-progress[style*="display:flex"]) .ccm-cta-pill, #ccm-slide:has(.ccm-video-progress[style*="display:flex"]) .ccm-connected-tag{ margin-bottom:calc((110px + 65 * var(--ccm-t)) * var(--ccm-s) * .08); }
#cardCaptionModal:is(.ccm-asking,.ccm-ask-pending) .ccm-cta-pill, #cardCaptionModal:is(.ccm-asking,.ccm-ask-pending) .ccm-connected-tag,
#cardCaptionModal .ccm-slide:has(.ccm-ask-static) .ccm-cta-pill, #cardCaptionModal .ccm-slide:has(.ccm-ask-static) .ccm-connected-tag{ margin-bottom:calc((38px + 0.08 * (110px + 65 * var(--ccm-t))) * var(--ccm-s)); }

.ccm-connected-icon{ display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.ccm-connected-icon i{ font-size:calc((9px + 6 * var(--ccm-t)) * var(--ccm-s)); color:#fff; }
.ccm-connected-text{ display:flex; align-items:baseline; gap:5px; min-width:0; overflow:hidden; white-space:nowrap; }
.ccm-connected-text-main{ font-size:calc((10px + 2.5 * var(--ccm-t)) * var(--ccm-s)); font-weight:700; color:#fff; letter-spacing:.1px; flex-shrink:0; }
.ccm-connected-text-dot{ color:rgba(255,255,255,.4); font-size:calc((8.5px + 2.5 * var(--ccm-t)) * var(--ccm-s)); flex-shrink:0; }
.ccm-connected-text-label{
    font-size:calc((9.5px + 2.5 * var(--ccm-t)) * var(--ccm-s)); font-weight:500; color:rgba(255,255,255,.72);
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0;
}

/* same thickness (and word size) as the resting CTA pill and the voted button, on phone and desktop */
.ccm-connected-tag{ box-sizing:border-box; height:calc(31px * var(--ccm-s) * var(--ccm-pill-k)); padding:0 calc((10px + 4 * var(--ccm-t)) * var(--ccm-s)) 0 calc((8px + 4 * var(--ccm-t)) * var(--ccm-s)); align-items:center; }
.ccm-connected-text{ align-items:center; }
.ccm-connected-text-main{ font-size:calc((11px + 1.5 * var(--ccm-t)) * var(--ccm-s) * var(--ccm-pill-k)); }
.ccm-connected-text-label{ font-size:calc((10.5px + 1.5 * var(--ccm-t)) * var(--ccm-s) * var(--ccm-pill-k)); }
.ccm-connected-text-dot{ font-size:calc((9.5px + 1.5 * var(--ccm-t)) * var(--ccm-s) * var(--ccm-pill-k)); }
.ccm-connected-icon i{ font-size:calc((11px + 5 * var(--ccm-t)) * var(--ccm-s) * var(--ccm-pill-k)); }

/* ══════════ AD FROM TAG / AD UI ══════════ */
.ad-from-tag{
    display:inline-flex;align-items:center;gap:6px;
    background:rgba(40,40,46,0.85);
    border-radius:20px;
    padding:5px 10px 5px 5px;
    margin-bottom:8px;
    text-decoration:none;
    max-width:100%;
    -webkit-tap-highlight-color:transparent;
}
.ad-from-avatar{
    width:calc((18px + 4 * var(--ccm-t)) * var(--ccm-s));height:calc((18px + 4 * var(--ccm-t)) * var(--ccm-s));border-radius:50%;object-fit:cover;
    border:2.5px solid var(--ccm-accent, var(--red,#FF2D2D));flex-shrink:0;display:block;
}
.ad-from-label{ color:rgba(255,255,255,0.6); font-size:calc((10px + var(--ccm-t)) * var(--ccm-s)); font-weight:500; white-space:nowrap; }
.ad-from-name{ color:#fff; font-size:calc((11px + var(--ccm-t)) * var(--ccm-s)); font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.brand-logo{
    width:calc((30px + 6 * var(--ccm-t)) * var(--ccm-s));
    height:calc((30px + 6 * var(--ccm-t)) * var(--ccm-s));
    border-radius:calc((8px + 2 * var(--ccm-t)) * var(--ccm-s));
    border:2.5px solid var(--ccm-accent, var(--red,#FF2D2D));
    object-fit:cover;
    flex-shrink:0;
    display:block;
}
.ad-actions-row{
    display:flex;
    align-items:center;
    gap:calc((12px + 2 * var(--ccm-t)) * var(--ccm-s));
    margin-top:calc((8px + 2 * var(--ccm-t)) * var(--ccm-s));
    margin-bottom:calc((8px + 2 * var(--ccm-t)) * var(--ccm-s));
    padding-top:calc((7px + var(--ccm-t)) * var(--ccm-s));
    border-top:1px solid rgba(255,255,255,0.08);
}
.ad-action-btn{
    display:flex; align-items:center; gap:5px;
    background:none; border:none; cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    padding:0;
}
.ad-action-btn i{
    font-size:calc((14px + 2 * var(--ccm-t)) * var(--ccm-s));
    color:rgba(255,255,255,0.6);
    transition:color 0.2s ease, transform 0.2s ease;
}
.ad-action-btn.active i{ color:var(--ccm-accent, #ff2d55); }
.ad-action-btn:active i{ transform:scale(1.2); }
.ad-action-count{ font-size:calc((11px + var(--ccm-t)) * var(--ccm-s)); font-weight:600; color:rgba(255,255,255,0.6); }
.ad-view-badge{ margin-left:auto; }
.ad-view-badge i{ color:rgba(255,255,255,0.4); }

.ccm-ad-logo{
    width:calc((41px + 7 * var(--ccm-t)) * var(--ccm-s)); height:calc((41px + 7 * var(--ccm-t)) * var(--ccm-s));
    border-radius:calc((10px + 2 * var(--ccm-t)) * var(--ccm-s));
    object-fit:cover;
    border:calc((2.5px + .5 * var(--ccm-t)) * var(--ccm-s)) solid var(--ccm-accent, var(--red,#FF2D2D));
    cursor:pointer;
    flex-shrink:0;
    display:block;
}
.ccm-ad-from-tag{
    display:inline-flex;align-items:center;gap:6px;
    background:rgba(40,40,46,0.85);
    border-radius:8px;
    padding:calc((2.5px + .5 * var(--ccm-t)) * var(--ccm-s)) calc((8px + 2 * var(--ccm-t)) * var(--ccm-s)) calc((2.5px + .5 * var(--ccm-t)) * var(--ccm-s)) calc((3.5px + .5 * var(--ccm-t)) * var(--ccm-s));
    margin-bottom:0;
    text-decoration:none;
    max-width:100%;
    -webkit-tap-highlight-color:transparent;
}
.ccm-ad-from-avatar{
    width:calc((22px + 4 * var(--ccm-t)) * var(--ccm-s));height:calc((22px + 4 * var(--ccm-t)) * var(--ccm-s));border-radius:50%;object-fit:cover;
    border:2.5px solid var(--ccm-accent, var(--red,#FF2D2D));flex-shrink:0;display:block;
}
.ccm-ad-from-label{ color:rgba(255,255,255,0.6); font-size:calc((10px + var(--ccm-t)) * var(--ccm-s)); font-weight:500; white-space:nowrap; }
.ccm-ad-from-name{ color:#fff; font-size:calc((11px + var(--ccm-t)) * var(--ccm-s)); font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.ccm-bottom-ad{ padding-bottom:calc((54px + 36 * var(--ccm-t)) * var(--ccm-s)); }
#ccm-ad-rail{ bottom:calc((52px + 28 * var(--ccm-t)) * var(--ccm-s)); }
.ccm-rail-ad-tag{
    color:rgba(255,255,255,.4); font-size:9.5px; font-weight:800;
    letter-spacing:.6px; margin-top:-6px; text-align:center;
}
.ccm-ad-from-switcher{ position:relative; min-height:30px; margin-bottom:10px; }
.ccm-ad-from-tag.ccm-switch-pane{ position:absolute; left:0; top:0; opacity:0; pointer-events:none; transition:opacity .35s ease; }
.ccm-ad-from-tag.ccm-switch-pane.active{ position:relative; opacity:1; pointer-events:all; }

.ccm-visit-brand-btn{
    left:calc((9px + 5 * var(--ccm-t)) * var(--ccm-s)); right:calc((9px + 5 * var(--ccm-t)) * var(--ccm-s));
    bottom:calc((12px + 12 * var(--ccm-t)) * var(--ccm-s));
    padding:calc((7px + 3 * var(--ccm-t)) * var(--ccm-s)) 0;
    font-size:calc((11.5px + 1.5 * var(--ccm-t)) * var(--ccm-s));
    border-radius:calc((11px + 3 * var(--ccm-t)) * var(--ccm-s));
}

/* ══════════════════════════════════════
   SHARE MODAL — bottom sheet (mobile) / centered (desktop)
   ══════════════════════════════════════ */
.share-modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.6);
    backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    z-index:99999997;opacity:0;pointer-events:none;
    transition:opacity .25s ease;
}
.share-modal-overlay.show{opacity:1;pointer-events:all;}

.share-modal-panel{
    position:fixed;left:0;right:0;bottom:0;
    background:rgba(12,12,28,.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-top:1px solid rgba(255,255,255,.08);
    border-radius:calc((16px + 4 * var(--ccm-t)) * var(--ccm-s)) calc((16px + 4 * var(--ccm-t)) * var(--ccm-s)) 0 0;
    z-index:99999998;
    max-height:78vh;
    display:flex;flex-direction:column;
    padding:8px 0 max(10px, env(safe-area-inset-bottom));
    transform:translateY(100%);
    transition:transform .3s cubic-bezier(.32,.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,.5);
    font-family:Poppins,sans-serif;
}
.share-modal-panel.show{transform:translateY(0);}

.share-modal-handle{
    width:calc((26px + 8 * var(--ccm-t)) * var(--ccm-s));height:calc((3px + .5 * var(--ccm-t)) * var(--ccm-s));border-radius:999px;
    background:rgba(255,255,255,.2);
    margin:0 auto calc((5px + var(--ccm-t)) * var(--ccm-s));flex-shrink:0;
}
.share-modal-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:calc((5px + var(--ccm-t)) * var(--ccm-s)) calc((10px + 8 * var(--ccm-t)) * var(--ccm-s)) calc((7px + 5 * var(--ccm-t)) * var(--ccm-s));flex-shrink:0;
}
.share-modal-title{ font-size:calc((13px + 2 * var(--ccm-t)) * var(--ccm-s));font-weight:700;color:#fff; }
.share-modal-close{
    width:calc((23px + 5 * var(--ccm-t)) * var(--ccm-s));height:calc((23px + 5 * var(--ccm-t)) * var(--ccm-s));border-radius:50%;
    font-size:calc((12px + 2 * var(--ccm-t)) * var(--ccm-s));
    background:rgba(255,255,255,.07);border:none;color:rgba(255,255,255,.6);
    display:flex;align-items:center;justify-content:center;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.share-modal-close:hover{background:rgba(var(--ccm-accent-rgb, 255,43,43), .18);color:var(--ccm-accent, #ff4c4c);}

.share-apps-row{
    display:flex;gap:calc((7px + 7 * var(--ccm-t)) * var(--ccm-s));overflow-x:auto;
    padding:calc((3px + var(--ccm-t)) * var(--ccm-s)) calc((10px + 8 * var(--ccm-t)) * var(--ccm-s)) calc((9px + 7 * var(--ccm-t)) * var(--ccm-s));flex-shrink:0;
    scrollbar-width:none;-ms-overflow-style:none;
}
.share-apps-row::-webkit-scrollbar{display:none;}

.share-app-btn{
    display:flex;flex-direction:column;align-items:center;gap:6px;
    flex:0 0 auto;width:calc((44px + 16 * var(--ccm-t)) * var(--ccm-s));
    background:none;border:none;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease;
}
.share-app-btn:active{transform:scale(.92);}
.share-app-icon{
    width:calc((36px + 14 * var(--ccm-t)) * var(--ccm-s));height:calc((36px + 14 * var(--ccm-t)) * var(--ccm-s));border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:calc((15px + 7 * var(--ccm-t)) * var(--ccm-s));color:#fff;
    box-shadow:0 4px 14px rgba(0,0,0,.35);
    transition:box-shadow .2s ease;
}
.share-app-btn:hover .share-app-icon{ box-shadow:0 6px 18px rgba(0,0,0,.5); }
.share-app-icon.copy       { background:linear-gradient(135deg,#4b4b56,#2a2a33); }
.share-app-icon.whatsapp   { background:linear-gradient(135deg,#25d366,#1fa855); }
.share-app-icon.telegram   { background:linear-gradient(135deg,#2ea6de,#229ed9); }
.share-app-icon.twitter    { background:linear-gradient(135deg,#111,#000); }
.share-app-icon.facebook   { background:linear-gradient(135deg,#1877f2,#0f5ec4); }
.share-app-icon.email      { background:linear-gradient(135deg,#ff9a56,#ff6a3d); }
.share-app-icon.more       { background:linear-gradient(135deg,var(--ccm-accent, #ff2b2b),#c0141e); }
.share-app-label{ font-size:calc((8.5px + 2 * var(--ccm-t)) * var(--ccm-s));font-weight:600;color:rgba(255,255,255,.65);white-space:nowrap; }

.share-apps-divider{
    display:flex;align-items:center;gap:calc((7px + 3 * var(--ccm-t)) * var(--ccm-s));
    padding:0 calc((10px + 8 * var(--ccm-t)) * var(--ccm-s)) calc((6px + 4 * var(--ccm-t)) * var(--ccm-s));flex-shrink:0;
}
.share-apps-divider::before,
.share-apps-divider::after{ content:'';flex:1;height:1px;background:rgba(255,255,255,.08); }
.share-apps-divider span{
    font-size:calc((8.5px + 2 * var(--ccm-t)) * var(--ccm-s));font-weight:700;letter-spacing:.4px;text-transform:uppercase;
    color:rgba(255,255,255,.35);white-space:nowrap;
}

.share-modal-search{
    display:flex;align-items:center;gap:8px;
    margin:0 calc((10px + 8 * var(--ccm-t)) * var(--ccm-s)) calc((8px + 4 * var(--ccm-t)) * var(--ccm-s));
    padding:calc((6px + 3 * var(--ccm-t)) * var(--ccm-s)) calc((10px + 4 * var(--ccm-t)) * var(--ccm-s));
    background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.1);
    border-radius:calc((9px + 3 * var(--ccm-t)) * var(--ccm-s));flex-shrink:0;
}
.share-modal-search i{color:rgba(255,255,255,.4);font-size:calc((12px + 3 * var(--ccm-t)) * var(--ccm-s));}
.share-modal-search input{
    flex:1;background:none;border:none;outline:none;
    color:#fff;font-size:calc((11px + 2 * var(--ccm-t)) * var(--ccm-s));font-family:Poppins,sans-serif;
}
.share-modal-search input::placeholder{color:rgba(255,255,255,.3);}

.share-modal-list{
    overflow-y:auto;flex:1;padding:0 calc((5px + 3 * var(--ccm-t)) * var(--ccm-s));
    -webkit-overflow-scrolling:touch;
}
.share-modal-list::-webkit-scrollbar{width:4px;}
.share-modal-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:999px;}
.share-modal-empty{ text-align:center;padding:40px 20px;color:rgba(255,255,255,.35);font-size:13px; }

.share-user-row{
    display:flex;align-items:center;gap:calc((8px + 4 * var(--ccm-t)) * var(--ccm-s));
    padding:calc((6px + 4 * var(--ccm-t)) * var(--ccm-s)) calc((8px + 4 * var(--ccm-t)) * var(--ccm-s));border-radius:calc((9px + 3 * var(--ccm-t)) * var(--ccm-s));
    cursor:pointer;transition:background .15s;
    -webkit-tap-highlight-color:transparent;
}
.share-user-row:hover{background:rgba(255,255,255,.05);}
.share-user-row img{
    width:calc((32px + 10 * var(--ccm-t)) * var(--ccm-s));height:calc((32px + 10 * var(--ccm-t)) * var(--ccm-s));border-radius:50%;object-fit:cover;
    border:calc((2px + .5 * var(--ccm-t)) * var(--ccm-s)) solid var(--ccm-accent, var(--red,#FF2D2D));flex-shrink:0;
}
.share-user-name{
    flex:1;color:#fff;font-size:calc((11px + 2.5 * var(--ccm-t)) * var(--ccm-s));font-weight:500;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.share-user-check{
    width:calc((18px + 4 * var(--ccm-t)) * var(--ccm-s));height:calc((18px + 4 * var(--ccm-t)) * var(--ccm-s));border-radius:50%;
    border:calc((1.5px + .5 * var(--ccm-t)) * var(--ccm-s)) solid rgba(255,255,255,.25);
    display:flex;align-items:center;justify-content:center;
    font-size:calc((9px + 3 * var(--ccm-t)) * var(--ccm-s));color:transparent;flex-shrink:0;
    transition:all .15s;
}
.share-user-row.selected .share-user-check{ background:var(--ccm-accent, #ff2b2b);border-color:var(--ccm-accent, #ff2b2b);color:var(--ccm-on-accent, #fff); }

.share-modal-footer{
    display:flex;align-items:center;gap:calc((8px + 4 * var(--ccm-t)) * var(--ccm-s));
    padding:calc((8px + 4 * var(--ccm-t)) * var(--ccm-s)) calc((10px + 8 * var(--ccm-t)) * var(--ccm-s)) calc((5px + var(--ccm-t)) * var(--ccm-s));flex-shrink:0;
    border-top:1px solid rgba(255,255,255,.06);
    margin-top:6px;
}
.share-modal-selcount{ font-size:calc((10px + 2 * var(--ccm-t)) * var(--ccm-s));color:rgba(255,255,255,.5);flex:1; }
.share-send-btn{
    padding:calc((7px + 3 * var(--ccm-t)) * var(--ccm-s)) calc((14px + 8 * var(--ccm-t)) * var(--ccm-s));border-radius:calc((9px + 3 * var(--ccm-t)) * var(--ccm-s));border:none;
    background:var(--ccm-accent, #ff2b2b);color:var(--ccm-on-accent, #fff);font-weight:700;font-size:calc((11px + 2 * var(--ccm-t)) * var(--ccm-s));
    cursor:pointer;font-family:Poppins,sans-serif;
    transition:opacity .2s;
}
.share-send-btn:disabled{ opacity:.4;cursor:not-allowed; }
.share-send-btn:not(:disabled):hover{ background:#e01f1f; }


/* ═══ SHARE MODAL = the comments sheet's look: midnight blue (dark) or white (light), same X button ═══ */
.share-modal-overlay{ z-index:2147483540; }
.share-modal-panel{
    --cs-bg:rgba(12,12,28,.98); --cs-tx:#f5f5f5; --cs-sub:rgba(255,255,255,.55); --cs-line:rgba(255,255,255,.08);
    --cs-field:rgba(255,255,255,.07); --cs-chip:rgba(255,255,255,.09); --cs-handle:rgba(255,255,255,.2);
    z-index:2147483541;
    background:var(--cs-bg); color:var(--cs-tx);
    border-top:1px solid var(--cs-line); border-radius:0;
}
/* closed sheet waits just below the screen: its shadow must not bleed up and dim the bottom of the page */
.share-modal-panel:not(.show){ box-shadow:none !important; }
:root[data-theme="light"] .share-modal-overlay{ background:rgba(20,24,40,.38); }
:root[data-theme="light"] .share-modal-panel{
    --cs-bg:rgba(255,255,255,.99); --cs-tx:#14171f; --cs-sub:rgba(20,23,31,.55); --cs-line:rgba(15,20,40,.10);
    --cs-field:rgba(15,20,40,.06); --cs-chip:rgba(15,20,40,.07); --cs-handle:rgba(15,20,40,.18);
    box-shadow:0 -10px 40px rgba(15,20,40,.18);
}
.share-modal-handle{ background:var(--cs-handle); }
.share-modal-title{ color:var(--cs-tx); }
.share-modal-close{
    all:unset; cursor:pointer; box-sizing:border-box; width:38px; height:38px; border-radius:50%;
    display:flex; align-items:center; justify-content:center; color:var(--cs-tx); flex:none; -webkit-tap-highlight-color:transparent;
}
.share-modal-close:hover{ background:var(--cs-chip); color:var(--cs-tx); }
.share-modal-close svg{ width:27px; height:27px; fill:none; stroke:currentColor; stroke-width:2.3; stroke-linecap:round; stroke-linejoin:round; display:block; }
.share-app-label{ color:var(--cs-sub); }
.share-apps-divider::before, .share-apps-divider::after{ background:var(--cs-line); }
.share-apps-divider span{ color:var(--cs-sub); }
.share-modal-search{ background:var(--cs-field); border-color:var(--cs-line); }
.share-modal-search i{ color:var(--cs-sub); }
.share-modal-search input{ color:var(--cs-tx); }
.share-modal-search input::placeholder{ color:var(--cs-sub); }
.share-modal-list::-webkit-scrollbar-thumb{ background:var(--cs-handle); }
.share-modal-empty{ color:var(--cs-sub); }
.share-user-row:hover{ background:var(--cs-chip); }
.share-user-name{ color:var(--cs-tx); }
.share-user-check{ border-color:var(--cs-sub); }
.share-modal-footer{ border-top-color:var(--cs-line); }
.share-modal-selcount{ color:var(--cs-sub); }
@media (min-width:791px){ .share-modal-panel{ border-radius:20px; border:1px solid var(--cs-line); } }

/* desktop: centered dialog instead of bottom sheet */
@media (min-width:791px){
    .share-modal-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:400px;
        border-radius:20px;
        transform:translate(-50%,-40%) scale(.95);
        opacity:0;pointer-events:none;
        max-height:560px;
    }
    .share-modal-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;pointer-events:all;
    }
    .share-modal-handle{display:none;}
}
</style>

<!-- SHARE MODAL MARKUP -->
<div id="shareModalOverlay" class="share-modal-overlay" onclick="closeShareModal()"></div>
<div id="shareModalPanel" class="share-modal-panel">
    <div class="share-modal-handle"></div>

    <div class="share-modal-header">
        <span class="share-modal-title">Share to</span>
        <button class="share-modal-close" onclick="closeShareModal()" aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
    </div>

    <div class="share-apps-row" id="shareAppsRow">
        <button type="button" class="share-app-btn" onclick="shareViaCopyLink()">
            <span class="share-app-icon copy"><i class="ri-link"></i></span>
            <span class="share-app-label">Copy Link</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaWhatsApp()">
            <span class="share-app-icon whatsapp"><i class="ri-whatsapp-fill"></i></span>
            <span class="share-app-label">WhatsApp</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaTelegram()">
            <span class="share-app-icon telegram"><i class="ri-telegram-fill"></i></span>
            <span class="share-app-label">Telegram</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaTwitter()">
            <span class="share-app-icon twitter"><i class="ri-twitter-x-fill"></i></span>
            <span class="share-app-label">X</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaFacebook()">
            <span class="share-app-icon facebook"><i class="ri-facebook-fill"></i></span>
            <span class="share-app-label">Facebook</span>
        </button>
        <button type="button" class="share-app-btn" onclick="shareViaEmail()">
            <span class="share-app-icon email"><i class="ri-mail-fill"></i></span>
            <span class="share-app-label">Email</span>
        </button>
        <button type="button" class="share-app-btn" id="shareNativeBtn" onclick="shareViaNative()">
            <span class="share-app-icon more"><i class="ri-more-fill"></i></span>
            <span class="share-app-label">More</span>
        </button>
    </div>

    <div class="share-apps-divider"><span>Or share with people you track</span></div>

    <div class="share-modal-search">
        <i class="ri-search-line"></i>
        <input type="text" id="shareSearchInput" placeholder="Search people you track…" oninput="filterShareList()">
    </div>

    <div class="share-modal-list" id="shareModalList">
        <div class="share-modal-empty">Loading…</div>
    </div>

    <div class="share-modal-footer">
        <span class="share-modal-selcount" id="shareSelCount">0 selected</span>
        <button class="share-send-btn" id="shareSendBtn" onclick="sendShare()" disabled>Send</button>
    </div>
</div>

<?php
/* values the Zeems modal script needs from PHP — computed here so zeems_modal.js can be a plain, cacheable file */
$__zme = ['id'=>(int)($current_user ?? $user_id ?? $uid ?? $me_id ?? 0), 'username'=>'', 'profile_pic'=>'uploads/mee.jpeg'];
try{ if(!empty($conn) && $__zme['id']){ $__zq = $conn->prepare('SELECT username, profile_pic FROM users WHERE id = ?'); $__zq->bind_param('i', $__zme['id']); $__zq->execute(); $__zr = $__zq->get_result()->fetch_assoc();
     if($__zr){ $__zme['username'] = (string)$__zr['username']; if(!empty($__zr['profile_pic'])) $__zme['profile_pic'] = $__zr['profile_pic']; } } }catch(Throwable $e){}
$__hp = 1;
try{ if(!empty($conn) && !empty($current_user)){ $__hq = $conn->prepare('SELECT haptics_enabled FROM user_settings WHERE user_id = ? LIMIT 1'); if($__hq){ $__hq->bind_param('i', $current_user); $__hq->execute(); $__hr = $__hq->get_result()->fetch_assoc(); if($__hr && isset($__hr['haptics_enabled'])) $__hp = (int)$__hr['haptics_enabled']; } } }catch(Throwable $e){}
$__as = null;
if (!empty($conn) && !empty($current_user)) {
    try {
        $__r = $conn->query("SELECT auto_scroll FROM users WHERE id = " . (int)$current_user . " LIMIT 1");
        if ($__r instanceof mysqli_result && ($__row = $__r->fetch_assoc())) { $__as = (int)$__row['auto_scroll']; }
    } catch (Throwable $e) { $__as = null; }
}
?>
<script>
window.CCM_PHP = {
    userId:     <?php echo (int)($current_user ?? 0); ?>,
    me:         <?php echo json_encode($__zme, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    haptics:    <?php echo $__hp ? 'true' : 'false'; ?>,
    autoScroll: <?php echo $__as === null ? 'null' : ($__as ? 'true' : 'false'); ?>
};
</script>
<script src="zeems_modal.js?v=<?php echo is_file(__DIR__ . '/zeems_modal.js') ? filemtime(__DIR__ . '/zeems_modal.js') : 0; ?>"></script>
<?php
/* Comment sheet UI + API (comment_section.php). Loaded once; if a page already included it, nothing is loaded twice. */
if (!function_exists('cs_api') && is_file(__DIR__ . '/comment_section.php')) {
    if (!isset($nonce)) { $nonce = ''; }
    include __DIR__ . '/comment_section.php';
}
?>