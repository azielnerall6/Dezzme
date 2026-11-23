<?php
/* ═══════════════════════════════════════════════════════════════════
   dayleez_modal.php — the viewer people watch Dayleez in
   Same layout as the group highlights viewer, in the red theme:
   the media box on top, comments underneath (no more popping messages).

   Included by dayleez.php with:  include 'dayleez_modal.php';
   Keep that include AFTER the main script block of dayleez.php, because
   this file uses what that script defines:
     $  esc  toast  dlRich  dlCapHTML  dlPlaceHTML  dlTf  ageLabel  leftLabel
     OPEN_U  OPEN_D  MY_PIC  DL_TEXT_BG  DL_USER_URL
   It gives dayleez.php:  window.openDayleez(userId, [userIds])
   and calls window.dlReload() when something changed (rings, deletes).
   Needs these actions in dayleez.php: viewer, view, like, comments,
   comment_add, comment_like, comment_dislike, comment_del, viewers,
   del, follow.
   ═══════════════════════════════════════════════════════════════════ */
?>
<style>
@media(max-width:790px){ :root{ --md-s:1.1; } }
#hlModal,#hlModal *,#hlModal *::before,#hlModal *::after{box-sizing:border-box;font-family:'Poppins',sans-serif}
#hlModal [class^="ri-"],#hlModal [class*=" ri-"]{font-family:'remixicon'!important}
#hlModal{
  display:none; position:fixed; inset:0; z-index:2147483100;
  background:rgba(0,0,0,.94); -webkit-backdrop-filter:blur(14px); backdrop-filter:blur(14px);
  opacity:0; transition:opacity .15s ease; color:#fff; touch-action:manipulation;
}
#hlModal.open{ display:block; }
#hlModal.vis{ opacity:1; }
#hlModal a{ color:inherit; text-decoration:none; }
#hlModal button{ font-family:inherit; }

/* the whole modal scrolls: box on top, comments underneath */
#hlScroll{ position:absolute; inset:0; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; scrollbar-width:thin; scrollbar-color:rgba(255,45,45,.5) transparent; }
#hlScroll::-webkit-scrollbar{ width:calc(5px * var(--md-s)); }
#hlScroll::-webkit-scrollbar-thumb{ background:rgba(255,45,45,.5); border-radius:calc(99px * var(--md-s)); }
#hlWrap{
  --hl-stage-h:calc(100vh - calc(64px * var(--md-s)));
  position:relative; width:100%; max-width:480px; margin:0 auto; background:#070924;
  transform:scale(.985) translateY(6px); transition:transform .18s cubic-bezier(.25,.1,.25,1);
}
@supports (height:100dvh){ #hlWrap{ --hl-stage-h:calc(100dvh - calc(64px * var(--md-s))); } }
#hlModal.vis #hlWrap{ transform:none; }
#hlStage{ position:relative; width:100%; height:var(--hl-stage-h); background:#000; overflow:hidden; touch-action:pan-y; -webkit-user-select:none; user-select:none; }
@media(min-width:791px){
  #hlWrap{ --hl-stage-h:min(86vh,900px); margin:3vh auto; box-shadow:0 25px 90px rgba(0,0,0,.65),0 0 0 1px rgba(255,255,255,.07); border-radius:calc(8px * var(--md-s)); }
  #hlStage{ border-radius:calc(8px * var(--md-s)) calc(8px * var(--md-s)) 0 0; clip-path:inset(0 round 8px 8px 0 0); -webkit-clip-path:inset(0 round 8px 8px 0 0); }
}

/* prev / next person arrows (normal view only) */
.hl-nav{ position:absolute; top:calc(var(--hl-stage-h) / 2); transform:translateY(-50%); z-index:20; width:calc(44px * var(--md-s)); height:calc(44px * var(--md-s)); padding:0; border-radius:50%; border:1px solid rgba(255,255,255,.12); background:rgba(36,36,40,.92); -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px); color:#fff; display:flex; align-items:center; justify-content:center; font-size:calc(26px * var(--md-s)); cursor:pointer; box-shadow:0 6px 20px rgba(0,0,0,.5); transition:opacity .2s ease,background .2s ease,transform .15s ease; -webkit-tap-highlight-color:transparent; }
.hl-nav i{ line-height:1; } .hl-nav:hover{ background:rgba(66,66,72,.96); } .hl-nav:active{ transform:translateY(-50%) scale(.92); }
.hl-nav.off{ opacity:0; pointer-events:none; } .hl-nav-prev{ left:calc(-58px * var(--md-s)); } .hl-nav-next{ right:calc(-58px * var(--md-s)); }

/* 3D cube */
#hlScene{ position:absolute; inset:0; z-index:1; perspective:1000px; -webkit-perspective:1000px; overflow:hidden; background:#000; }
#hlCube{ position:absolute; inset:0; transform-origin:50% 50%; }
#hlCube.flip{ transform-style:preserve-3d; -webkit-transform-style:preserve-3d; will-change:transform; }
#hlCube.flip > *{ backface-visibility:hidden; -webkit-backface-visibility:hidden; }
#hlFace{ position:absolute; inset:0; overflow:hidden; background:#000; }
.hl-peek{ position:absolute; inset:0; overflow:hidden; background:#000; pointer-events:none; }
#hlMedia,.hl-peek-media{ position:absolute; inset:0; background:#000; overflow:hidden; }
.hl-media-item{ position:relative; z-index:1; width:100%; height:100%; object-fit:contain; display:block; background:transparent; -webkit-user-drag:none; }
.hl-shade-top,.hl-shade-bottom{ position:absolute; left:0; right:0; pointer-events:none; z-index:2; }
.hl-shade-top{ top:0; height:calc(150px * var(--md-s)); background:linear-gradient(to bottom,rgba(0,0,0,.7),transparent); }
.hl-shade-bottom{ bottom:0; height:calc(160px * var(--md-s)); background:linear-gradient(to top,rgba(0,0,0,.75),transparent); }
.hl-zone{ position:absolute; top:calc(100px * var(--md-s)); bottom:calc(74px * var(--md-s)); z-index:3; -webkit-tap-highlight-color:transparent; cursor:pointer; }
.hl-zone-prev{ left:0; width:35%; } .hl-zone-next{ right:0; width:65%; }

/* header: pic (opens the profile), name, time */
.hl-top{ position:absolute; top:0; left:0; right:0; z-index:5; padding:calc(env(safe-area-inset-top,0px) + calc(8px * var(--md-s))) calc(10px * var(--md-s)) 0; pointer-events:none; }
.hl-top > *{ pointer-events:auto; }
.hl-dashes{ display:flex; gap:calc(3px * var(--md-s)); overflow:hidden; }
.hl-dash{ flex:1 1 0; min-width:4px; height:calc(3px * var(--md-s)); border-radius:2px; background:rgba(255,255,255,.3); overflow:hidden; }
.hl-dash-fill{ height:100%; width:0; background:#ff2b2b; border-radius:2px; } .hl-dash.done .hl-dash-fill{ width:100%; }
.hl-head{ display:flex; align-items:flex-start; gap:calc(9px * var(--md-s)); margin-top:calc(9px * var(--md-s)); }
.hl-av-col{ display:flex; flex-direction:column; align-items:center; gap:calc(5px * var(--md-s)); flex-shrink:0; }
.hl-av-link{ display:block; -webkit-tap-highlight-color:transparent; }
.hl-avatar{ width:calc(38px * var(--md-s)); height:calc(38px * var(--md-s)); border-radius:30%; object-fit:cover; border:2.5px solid #ff2b2b; display:block; background:#0d1030; cursor:pointer; }
.hl-songline{ margin-top:2px; max-width:calc(100% - calc(56px * var(--md-s))); color:rgba(255,255,255,.8); font-size:calc(10.1px * var(--md-s)); font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-songline:empty{ display:none; }
.hl-x-col{ display:flex; flex-direction:column; align-items:center; gap:calc(4px * var(--md-s)); flex-shrink:0; }
.hl-count{ background:rgba(22,22,24,.7); color:#fff; font-size:calc(10px * var(--md-s)); font-weight:800; padding:2px calc(7px * var(--md-s)); border-radius:calc(6px * var(--md-s)); }
.hl-head-info{ flex:1; min-width:0; padding-top:1px; }
.hl-title-row{ display:flex; flex-direction:column; align-items:flex-start; gap:1px; min-width:0; }
.hl-title{ min-width:0; max-width:100%; color:#fff; font-weight:600; font-size:calc(13px * var(--md-s)); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-date{ flex-shrink:0; color:rgba(255,255,255,.72); font-size:calc(10.5px * var(--md-s)); white-space:nowrap; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-icon-btn{ --hl-ic:calc(21px * var(--md-s)); background:none; border:none; color:#fff; cursor:pointer; width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); padding:0; display:flex; align-items:center; justify-content:center; -webkit-tap-highlight-color:transparent; }
.hl-icon-btn i{ font-size:var(--hl-ic); line-height:1; filter:drop-shadow(0 2px 5px rgba(0,0,0,.7)); }
.hl-icon-btn svg{ width:var(--hl-ic); height:var(--hl-ic); display:block; filter:drop-shadow(0 2px 5px rgba(0,0,0,.7)); }
.hl-icon-btn.hl-x i{ font-size:calc(var(--hl-ic) * 1.4); } .hl-icon-btn.hl-menu svg{ width:calc(var(--hl-ic) * 1.02); height:calc(var(--hl-ic) * 1.02); }
.hl-icon-btn:active{ transform:scale(.88); opacity:.7; }

/* bottom of the box: comment bar, like, comments, all media */
.hl-bottom{ position:absolute; left:0; right:0; bottom:0; z-index:7; display:flex; align-items:center; gap:calc(12px * var(--md-s)); padding:calc(10px * var(--md-s)) calc(12px * var(--md-s)) calc(12px * var(--md-s)); }
.hl-bar{ flex:1; min-width:0; display:flex; align-items:center; height:calc(38px * var(--md-s)); padding:0 calc(14px * var(--md-s)); border-radius:999px; background:rgba(255,255,255,.12); -webkit-backdrop-filter:blur(12px); backdrop-filter:blur(12px); cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent; }
.hl-bar:hover{ background:rgba(255,255,255,.18); }
.hl-fake{ flex:1; min-width:0; color:rgba(255,255,255,.6); font-size:calc(12.5px * var(--md-s)); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hl-act{ background:none; border:none; color:#fff; cursor:pointer; display:flex; align-items:center; gap:calc(4px * var(--md-s)); font-size:calc(12px * var(--md-s)); font-weight:800; -webkit-tap-highlight-color:transparent; text-shadow:0 1px 4px rgba(0,0,0,.7); padding:0; transition:color .2s; }
.hl-act i{ font-size:calc(24px * var(--md-s)); filter:drop-shadow(0 2px 5px rgba(0,0,0,.6)); transition:transform .18s ease,color .2s; } .hl-act:active i{ transform:scale(1.25); }
.hl-act svg{ width:calc(23px * var(--md-s)); height:calc(23px * var(--md-s)); flex-shrink:0; filter:drop-shadow(0 2px 5px rgba(0,0,0,.6)); }
.hl-like.on, .hl-like.on i, .hl-like.on span{ color:#ff2d55; }
.hl-act.on svg{ filter:drop-shadow(0 0 6px rgba(255,255,255,.55)); }
.hl-act i.pop{ animation:hlPop .38s ease; } @keyframes hlPop{ 0%{ transform:scale(1); } 40%{ transform:scale(1.5); } 100%{ transform:scale(1); } }

/* all-media strip */
.hl-mpanel{ position:absolute; left:0; right:0; bottom:calc(calc(60px * var(--md-s)) + env(safe-area-inset-bottom)); z-index:9; padding:calc(10px * var(--md-s)) 0 calc(12px * var(--md-s)); background:linear-gradient(to top,rgba(0,0,0,.78),rgba(0,0,0,.62) 70%,rgba(0,0,0,0)); -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px); opacity:0; transform:translateY(14px); pointer-events:none; transition:opacity .2s ease,transform .22s cubic-bezier(.22,1,.36,1); }
.hl-mpanel.show{ opacity:1; transform:none; pointer-events:auto; }
.hl-mp-head{ display:flex; align-items:center; justify-content:space-between; padding:0 calc(12px * var(--md-s)) calc(8px * var(--md-s)) calc(14px * var(--md-s)); }
.hl-mp-title{ color:#fff; font-size:calc(12px * var(--md-s)); font-weight:600; text-shadow:0 1px 4px rgba(0,0,0,.7); }
.hl-mp-x{ width:calc(26px * var(--md-s)); height:calc(26px * var(--md-s)); border:none; border-radius:50%; background:rgba(255,255,255,.14); color:#fff; font-size:calc(17px * var(--md-s)); display:flex; align-items:center; justify-content:center; padding:0; }
.hl-mp-strip{ display:flex; gap:calc(8px * var(--md-s)); padding:2px calc(14px * var(--md-s)); overflow-x:auto; overflow-y:hidden; -webkit-overflow-scrolling:touch; scrollbar-width:none; overscroll-behavior-x:contain; touch-action:pan-x; }
.hl-mp-strip::-webkit-scrollbar{ display:none; }
.hl-mp-item{ position:relative; flex:0 0 auto; width:calc(clamp(56px,16vw,70px) * var(--md-s)); aspect-ratio:9/14; padding:0; border:2px solid transparent; border-radius:calc(8px * var(--md-s)); overflow:hidden; background:#10122e; transition:transform .15s,border-color .15s,opacity .15s; opacity:.75; }
.hl-mp-item:active{ transform:scale(.95); } .hl-mp-item.cur{ border-color:#fff; opacity:1; }
.hl-mp-item img,.hl-mp-item video{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; }
.hl-mp-num{ position:absolute; left:calc(4px * var(--md-s)); bottom:calc(3px * var(--md-s)); font-size:calc(10px * var(--md-s)); font-weight:700; color:#fff; text-shadow:0 1px 3px rgba(0,0,0,.9); }
.hl-mp-vid{ position:absolute; right:calc(4px * var(--md-s)); top:calc(4px * var(--md-s)); font-size:calc(13px * var(--md-s)); color:#fff; line-height:1; }
.hl-mp-txt{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:calc(15px * var(--md-s)); font-weight:800; color:#fff; text-shadow:0 1px 4px rgba(0,0,0,.8); }

/* loader */
#hlLoader{ position:absolute; inset:0; z-index:9; background:linear-gradient(90deg,#0b0c1c 25%,#14162e 50%,#0b0c1c 75%); background-size:200% 100%; animation:hlSh 1.3s linear infinite; display:none; align-items:center; justify-content:center; } #hlLoader.show{ display:flex; }
@keyframes hlSh{ to{ background-position:-200% 0; } }
.hl-spin{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); border-radius:50%; border:3px solid rgba(255,255,255,.15); border-top-color:#fff; animation:hlSpin .8s linear infinite; } @keyframes hlSpin{ to{ transform:rotate(360deg); } }

/* menu sheet */
#hlSheetOv{ position:absolute; inset:0; z-index:10; background:rgba(0,0,0,.6); opacity:0; pointer-events:none; transition:opacity .25s ease; } #hlSheetOv.show{ opacity:1; pointer-events:all; }
#hlSheet{ position:absolute; left:0; right:0; bottom:0; z-index:11; background:rgba(12,12,28,.98); border-top:1px solid rgba(255,255,255,.08); border-radius:calc(12px * var(--md-s)) calc(12px * var(--md-s)) 0 0; padding:calc(8px * var(--md-s)) 0 calc(12px * var(--md-s)); transform:translateY(100%); transition:transform .3s cubic-bezier(.32,.72,0,1); max-height:80%; display:flex; flex-direction:column; }
#hlSheet.show{ transform:translateY(0); } #hlSheetList{ overflow-y:auto; -webkit-overflow-scrolling:touch; }
.hl-sheet-handle{ width:calc(32px * var(--md-s)); height:calc(3px * var(--md-s)); border-radius:999px; background:rgba(255,255,255,.2); margin:0 auto calc(4px * var(--md-s)); flex-shrink:0; }
.hl-sheet-item{ display:flex; align-items:center; gap:calc(10px * var(--md-s)); padding:calc(11px * var(--md-s)) calc(16px * var(--md-s)); color:#fff; font-size:calc(12.5px * var(--md-s)); font-weight:500; cursor:pointer; border-bottom:1px solid rgba(255,255,255,.06); -webkit-tap-highlight-color:transparent; }
.hl-sheet-item:last-child{ border-bottom:none; } .hl-sheet-item:active{ background:rgba(255,255,255,.06); }
.hl-sheet-item i{ font-size:calc(15px * var(--md-s)); color:#f24455; width:calc(18px * var(--md-s)); text-align:center; } .hl-sheet-item.danger{ color:#ff4c4c; }
.hl-sheet-item img{ width:calc(28px * var(--md-s)); height:calc(28px * var(--md-s)); border-radius:30%; object-fit:cover; border:2px solid #ff2b2b; }
.hl-sheet-cancel{ display:block; width:calc(100% - calc(24px * var(--md-s))); margin:calc(6px * var(--md-s)) calc(12px * var(--md-s)) 0; padding:calc(9px * var(--md-s)); background:rgba(255,255,255,.06); border:none; border-radius:calc(10px * var(--md-s)); color:rgba(255,255,255,.7); font-weight:600; font-size:calc(12px * var(--md-s)); cursor:pointer; flex-shrink:0; }
.hl-toast{ position:fixed; left:50%; bottom:calc(96px * var(--md-s)); transform:translateX(-50%); z-index:30; background:rgba(20,20,25,.96); border:1px solid rgba(255,45,45,.55); color:#fff; padding:calc(7px * var(--md-s)) calc(18px * var(--md-s)); border-radius:calc(50px * var(--md-s)); font-size:calc(11.5px * var(--md-s)); white-space:nowrap; max-width:92%; overflow:hidden; text-overflow:ellipsis; pointer-events:none; transition:opacity .3s; }

/* ═════════ COMMENTS BELOW THE BOX (red theme) ═════════ */
#hlComments{
  --cm-px:18px; --csav:34px; --csgap:calc(11px * var(--md-s)); --cm-fs:calc(13.5px * var(--md-s)); --cm-meta:11.5px; --thread:rgba(255,125,125,.3);
  position:relative; display:flex; flex-direction:column; background:#070924; min-height:100vh; min-height:100dvh;
}
@media(min-width:791px){ #hlComments{ min-height:min(62vh,560px); border-radius:0 0 calc(8px * var(--md-s)) calc(8px * var(--md-s)); } }
.hl-cm-top{ position:sticky; top:0; z-index:7; background:#070924; border:none; box-shadow:none; }
.hl-cm-head{ display:flex; align-items:center; gap:calc(6px * var(--md-s)); padding:calc(var(--cm-px) * .65) calc(var(--cm-px) * .6) calc(var(--cm-px) * .55) var(--cm-px); }
.hl-cm-title{ flex:1; min-width:0; display:flex; align-items:center; gap:calc(8px * var(--md-s)); font-size:calc(var(--cm-fs) + 1px); font-weight:700; letter-spacing:-.1px; }
.hl-cm-title i{ color:#ff5a5a; font-size:1.15em; }
.hl-cm-pill{ color:rgba(255,255,255,.6); font-size:calc(var(--cm-fs) + 1px); font-weight:600; } .hl-cm-pill:empty{ display:none; }
.hl-cm-sort{ position:relative; }
.hl-cm-sortbtn{ display:flex; align-items:center; gap:2px; padding:calc(5px * var(--md-s)) calc(9px * var(--md-s)); border:none; border-radius:calc(8px * var(--md-s)); background:rgba(255,255,255,.07); color:rgba(255,255,255,.88); font-size:var(--cm-meta); font-weight:600; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-cm-sortbtn:hover{ background:rgba(255,255,255,.13); }
.hl-cm-sortmenu{ position:absolute; top:calc(100% + calc(6px * var(--md-s))); right:0; z-index:9; display:none; min-width:calc(150px * var(--md-s)); background:#0c0e2a; border:1px solid rgba(255,255,255,.1); border-radius:calc(12px * var(--md-s)); padding:calc(6px * var(--md-s)); box-shadow:0 10px 30px rgba(0,0,0,.55); }
.hl-cm-sortmenu.show{ display:block; }
.hl-cm-sortmenu button{ display:block; width:100%; text-align:left; padding:calc(8px * var(--md-s)) calc(10px * var(--md-s)); border:none; background:none; color:#fff; font-size:calc(12.5px * var(--md-s)); border-radius:calc(8px * var(--md-s)); cursor:pointer; }
.hl-cm-sortmenu button:hover{ background:rgba(255,255,255,.07); } .hl-cm-sortmenu button.active{ color:#ff8a8a; font-weight:600; }
.hl-cm-hb{ width:calc(34px * var(--md-s)); height:calc(34px * var(--md-s)); border:none; border-radius:50%; background:rgba(255,255,255,.07); color:#fff; font-size:calc(20px * var(--md-s)); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .2s,transform .15s; -webkit-tap-highlight-color:transparent; flex-shrink:0; }
.hl-cm-hb:hover{ background:rgba(255,255,255,.14); } .hl-cm-hb:active{ transform:scale(.9); }
#hlComments:not(.cm-on) .hl-nv-up{ display:none; } #hlComments.cm-on .hl-nv-dn{ display:none; }
.hl-cm-note{ display:flex; align-items:center; gap:calc(8px * var(--md-s)); margin:calc(12px * var(--md-s)) var(--cm-px) 2px; padding:calc(8px * var(--md-s)) calc(12px * var(--md-s)); border-radius:calc(12px * var(--md-s)); background:rgba(255,255,255,.045); border:1px solid rgba(255,255,255,.08); font-size:calc(var(--cm-meta) + .5px); line-height:1.3; color:rgba(255,255,255,.86); }
.hl-cm-note i{ font-size:calc(1rem * var(--md-s)); line-height:1; color:#ff7d7d; flex-shrink:0; }
#hlCmList{ flex:1 0 auto; padding:2px var(--cm-px) calc(20px * var(--md-s)); -webkit-user-select:text; user-select:text; }

.hl-ci{ --csav:34px; --csgap:calc(11px * var(--md-s)); padding:calc(12px * var(--md-s)) 0; }
.hl-ci.fresh{ animation:hlCmIn .3s ease both; } @keyframes hlCmIn{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }
.hl-ci-main{ display:flex; gap:var(--csgap); }
.hl-ci-avcol{ display:flex; flex-direction:column; align-items:center; flex-shrink:0; width:var(--csav); }
.hl-ci-av{ width:var(--csav); height:var(--csav); border-radius:30%; overflow:hidden; display:block; flex-shrink:0; border:2px solid #FF2D2D; background:#0d1030; }
.hl-ci-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-ci-line{ display:none; flex:1; width:2px; margin-top:calc(6px * var(--md-s)); border-radius:2px; background:var(--thread); }
.hl-ci.has-thread > .hl-ci-main .hl-ci-line{ display:block; } .hl-ci.has-thread > .hl-ci-main .hl-ci-body{ padding-bottom:calc(12px * var(--md-s)); }
.hl-ci-body{ flex:1; min-width:0; }
.hl-ci-l1{ display:flex; flex-direction:row; flex-wrap:nowrap; align-items:baseline; gap:0 calc(8px * var(--md-s)); min-width:0; }
.hl-ci-un{ display:block; flex:0 1 auto; min-width:0; font-size:calc(var(--cm-fs) - .5px); font-weight:600; line-height:1.4; color:rgba(255,255,255,.74); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; } .hl-ci-un:hover{ text-decoration:underline; }
.hl-ci-l1 > .hl-bdg{ flex:0 0 auto; line-height:1.4; }
.hl-bdg{ display:inline-block; font-size:calc(10.5px * var(--md-s)); font-weight:700; flex:0 0 auto; white-space:nowrap; letter-spacing:.2px; line-height:1.3; color:#ff7d7d; text-shadow:0 1px 4px rgba(0,0,0,.5); }
.hl-ci-t{ font-size:var(--cm-meta); line-height:1.4; color:rgba(255,255,255,.4); flex:0 0 auto; white-space:nowrap; } .hl-ci-t::before{ content:'·'; margin-right:calc(6px * var(--md-s)); }
.hl-ci-more{ align-self:center; margin-left:auto; margin-right:calc(-6px * var(--md-s)); width:calc(28px * var(--md-s)); height:calc(28px * var(--md-s)); flex-shrink:0; border:none; background:none; color:rgba(255,255,255,.45); font-size:calc(1.15rem * var(--md-s)); border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:color .2s,background .2s; -webkit-tap-highlight-color:transparent; }
.hl-ci-more:hover{ color:#fff; background:rgba(255,255,255,.08); }
.hl-ci-tx{ font-size:var(--cm-fs); line-height:1.55; color:rgba(255,255,255,.92); margin-top:2px; word-break:break-word; overflow-wrap:anywhere; }
.hl-ci-tx .hl-full{ display:none; } .hl-ci-tx.expanded .hl-full{ display:inline; } .hl-ci-tx.expanded .hl-short{ display:none; }
.hl-rm{ border:none; background:none; padding:0; margin-left:2px; font-family:inherit; font-size:inherit; font-weight:400; color:rgba(255,255,255,.7); cursor:pointer; } .hl-rm:hover{ color:#fff; }
.hl-mention{ color:#ff8a8a; font-weight:600; }
.hl-link{ color:#ff8a8a; font-weight:500; word-break:break-all; } .hl-link:hover{ text-decoration:underline; }
.hl-ci-r2{ display:flex; align-items:center; gap:calc(18px * var(--md-s)); margin-top:calc(8px * var(--md-s)); }
.hl-ci-like,.hl-ci-dis,.hl-ci-reply{ display:flex; align-items:center; gap:calc(6px * var(--md-s)); border:none; background:none; padding:0; color:rgba(255,255,255,.85); font-size:calc(var(--cm-meta) + .5px); font-weight:500; cursor:pointer; transition:color .2s,transform .12s; -webkit-tap-highlight-color:transparent; }
.hl-ci-like i,.hl-ci-dis i{ font-size:calc(1.15rem * var(--md-s)); display:block; line-height:1; }
.hl-ci-like:active,.hl-ci-dis:active,.hl-ci-reply:active{ transform:scale(.9); }
.hl-ci-like.on,.hl-ci-dis.on{ color:#ff5a5a; } .hl-ci-like i.pop,.hl-ci-dis i.pop{ animation:hlPop .34s ease; }
.hl-ci-reply{ color:rgba(255,255,255,.5); } .hl-ci-reply:hover{ color:#fff; }
.hl-crl{ position:relative; display:inline-flex; width:calc(16px * var(--md-s)); height:calc(16px * var(--md-s)); flex-shrink:0; margin-left:-2px; }
.hl-crl-av{ width:calc(16px * var(--md-s)); height:calc(16px * var(--md-s)); border-radius:30%; overflow:hidden; border:1.5px solid #FF2D2D; background:#0d1030; display:block; } .hl-crl-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-crl-b{ position:absolute; right:calc(-5px * var(--md-s)); bottom:calc(-4px * var(--md-s)); font-size:calc(.68rem * var(--md-s)); line-height:1; color:#ff2d55; filter:drop-shadow(0 0 1.5px #070924) drop-shadow(0 0 1px #070924); }
.hl-ci-tg{ position:relative; display:flex; align-items:center; gap:calc(7px * var(--md-s)); height:calc(30px * var(--md-s)); margin-left:calc(var(--csav) + var(--csgap)); border:none; background:none; padding:0; color:#fff; font-size:calc(var(--cm-meta) + .5px); font-weight:600; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-ci-tg::before{ content:''; position:absolute; top:0; height:50%; box-sizing:border-box; left:calc(-1 * (var(--csav) / 2 + var(--csgap) + 1px)); width:calc(var(--csav) / 2 + var(--csgap) + 1px); border-left:2px solid var(--thread); border-bottom:2px solid var(--thread); border-bottom-left-radius:12px; }
.hl-ci-tgav{ width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); border-radius:30%; overflow:hidden; flex-shrink:0; border:1.5px solid #FF2D2D; background:#0d1030; display:block; } .hl-ci-tgav img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-ci-tgd{ opacity:.7; } .hl-ci-chev{ font-size:calc(1.1rem * var(--md-s)); line-height:1; transition:transform .2s ease; } .hl-ci-tg.open .hl-ci-chev{ transform:rotate(90deg); }
.hl-sub{ margin-left:calc(var(--csav) + var(--csgap)); margin-top:2px; }
.hl-sub .hl-ci{ --csav:28px; --csgap:calc(9px * var(--md-s)); padding:calc(10px * var(--md-s)) 0; }
.hl-sub .hl-sub .hl-sub{ margin-left:0; }
.hl-ci-sm{ display:block; margin:2px 0 calc(6px * var(--md-s)); padding:0; border:none; background:none; color:rgba(255,255,255,.7); font-size:calc(var(--cm-meta) + .2px); font-weight:600; cursor:pointer; } .hl-ci-sm:hover{ color:#fff; }
.hl-cm-empty{ padding:calc(46px * var(--md-s)) calc(20px * var(--md-s)); text-align:center; color:rgba(255,255,255,.5); font-size:var(--cm-meta); line-height:1.6; }
.hl-cm-empty i{ width:calc(58px * var(--md-s)); height:calc(58px * var(--md-s)); border-radius:50%; background:rgba(255,45,45,.14); border:1px solid rgba(255,45,45,.35); display:flex; align-items:center; justify-content:center; margin:0 auto calc(12px * var(--md-s)); font-size:calc(26px * var(--md-s)); color:#ff7d7d; }
.hl-cm-sent{ display:none; justify-content:center; padding:calc(10px * var(--md-s)) 0 calc(18px * var(--md-s)); } .hl-cm-sent.show{ display:flex; }
.hl-cm-spin{ width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); border-radius:50%; border:3px solid rgba(255,255,255,.15); border-top-color:#ff5a5a; animation:hlSpin .8s linear infinite; }
.hl-sk{ background:linear-gradient(90deg,rgba(255,255,255,.05) 25%,rgba(255,255,255,.13) 50%,rgba(255,255,255,.05) 75%); background-size:200% 100%; animation:hlSh 1.3s linear infinite; border-radius:calc(6px * var(--md-s)); }
.hl-ci-skel{ padding:calc(12px * var(--md-s)) 0; display:flex; gap:calc(11px * var(--md-s)); }
.hl-cm-menu{ position:absolute; z-index:8; display:none; min-width:calc(180px * var(--md-s)); background:#0c0e2a; border:1px solid rgba(255,255,255,.1); border-radius:calc(12px * var(--md-s)); padding:calc(6px * var(--md-s)); box-shadow:0 10px 30px rgba(0,0,0,.55); } .hl-cm-menu.show{ display:block; }
.hl-cm-menu button{ display:flex; align-items:center; gap:calc(10px * var(--md-s)); width:100%; text-align:left; padding:calc(10px * var(--md-s)); border:none; background:none; color:#fff; font-size:calc(12.5px * var(--md-s)); border-radius:calc(8px * var(--md-s)); cursor:pointer; }
.hl-cm-menu button i{ font-size:calc(1.1rem * var(--md-s)); opacity:.85; } .hl-cm-menu button:hover{ background:rgba(255,255,255,.07); } .hl-cm-menu button.danger{ color:#ff6b6b; }

/* composer: pinned to the bottom. The input is 16px so phones do NOT zoom in when you tap it. */
.hl-cm-composer{ position:sticky; bottom:0; z-index:6; background:#070924; border:none; box-shadow:none; padding:calc(10px * var(--md-s)) var(--cm-px) calc(calc(10px * var(--md-s)) + env(safe-area-inset-bottom,0px)); }
.hl-cm-composer:empty{ display:none; }
.hl-reply-chip{ display:none; align-items:center; gap:calc(8px * var(--md-s)); margin:0 0 calc(8px * var(--md-s)); padding:calc(5px * var(--md-s)) calc(6px * var(--md-s)) calc(5px * var(--md-s)) calc(12px * var(--md-s)); border-radius:calc(8px * var(--md-s)); background:rgba(255,45,45,.14); border:1px solid rgba(255,45,45,.42); font-size:var(--cm-meta); color:rgba(255,255,255,.72); }
.hl-reply-chip.show{ display:flex; animation:hlCmIn .2s ease both; }
.hl-reply-chip b{ flex:1; min-width:0; color:#ffb0b0; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hl-reply-x{ width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); border:none; border-radius:50%; background:rgba(255,255,255,.1); color:#fff; display:flex; align-items:center; justify-content:center; font-size:calc(14px * var(--md-s)); cursor:pointer; flex-shrink:0; } .hl-reply-x:hover{ background:rgba(255,255,255,.2); }
.hl-compose-row{ display:flex; align-items:center; gap:calc(10px * var(--md-s)); }
.hl-cmy{ width:calc(32px * var(--md-s)); height:calc(32px * var(--md-s)); border-radius:30%; overflow:hidden; flex-shrink:0; border:2px solid #FF2D2D; background:#0d1030; display:block; } .hl-cmy img{ width:100%; height:100%; object-fit:cover; display:block; }
#hlCIn{ flex:1; min-width:0; border:1px solid rgba(255,255,255,.1); outline:none; background:rgba(255,255,255,.07); color:#fff; padding:calc(9px * var(--md-s)) calc(14px * var(--md-s)); border-radius:999px; font-size:16px; -webkit-text-size-adjust:100%; -webkit-user-select:text; user-select:text; transition:border-color .2s,box-shadow .2s,background .2s; }
#hlCIn:focus{ border-color:rgba(255,45,45,.85); background:rgba(255,255,255,.1); box-shadow:0 0 0 3px rgba(255,45,45,.22); } #hlCIn::placeholder{ color:rgba(255,255,255,.4); }
.hl-send{ width:calc(34px * var(--md-s)); height:calc(34px * var(--md-s)); border:none; border-radius:50%; padding:0; flex-shrink:0; background:linear-gradient(135deg,#FF2D2D,#ff6a4d); color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .2s,color .2s,opacity .2s,transform .15s; -webkit-tap-highlight-color:transparent; }
.hl-send svg{ width:calc(18px * var(--md-s)); height:calc(18px * var(--md-s)); display:block; } .hl-send:not(:disabled):active{ transform:scale(.88); }
.hl-send:disabled{ background:rgba(255,255,255,.08); color:rgba(255,255,255,.4); cursor:default; }

/* responsive */
@media(max-width:790px){
  #hlWrap{ --hl-stage-h:calc(100vh - calc(48px * var(--md-s))); }
  @supports (height:100dvh){ #hlWrap{ --hl-stage-h:calc(100dvh - calc(48px * var(--md-s))); } }
  .hl-cm-composer{ opacity:0; visibility:hidden; pointer-events:none; transition:opacity .15s ease; }
  #hlComments.cm-on .hl-cm-composer{ opacity:1; visibility:visible; pointer-events:auto; }
  #hlScroll{ scrollbar-width:none; -ms-overflow-style:none; } #hlScroll::-webkit-scrollbar{ display:none; width:0; height:0; }
  .hl-nav{ display:none!important; }
  #hlComments{ --cm-px:16px; --csav:32px; }
  .hl-cm-top{ padding-top:env(safe-area-inset-top,0px); }
}
@media(max-width:490px){
  #hlComments{ --cm-px:14px; --csav:30px; --csgap:calc(9px * var(--md-s)); --cm-fs:calc(13px * var(--md-s)); --cm-meta:11px; }
  .hl-sub .hl-ci{ --csav:26px; --csgap:calc(8px * var(--md-s)); }
  .hl-avatar{ width:calc(35px * var(--md-s)); height:calc(35px * var(--md-s)); } .hl-title{ font-size:calc(12.5px * var(--md-s)); } .hl-date{ font-size:calc(10px * var(--md-s)); }
  .hl-icon-btn{ --hl-ic:calc(19px * var(--md-s)); } .hl-act i{ font-size:calc(22px * var(--md-s)); } .hl-act{ font-size:calc(11.5px * var(--md-s)); }
  .hl-bar{ height:calc(36px * var(--md-s)); } .hl-fake{ font-size:calc(12px * var(--md-s)); } .hl-ci-r2{ gap:calc(15px * var(--md-s)); }
}
@media(max-width:390px){
  #hlComments{ --cm-px:12px; --csav:28px; --csgap:calc(8px * var(--md-s)); --cm-fs:calc(12.5px * var(--md-s)); --cm-meta:10.5px; }
  .hl-sub .hl-ci{ --csav:24px; --csgap:calc(7px * var(--md-s)); }
  .hl-avatar{ width:calc(32px * var(--md-s)); height:calc(32px * var(--md-s)); } .hl-count{ font-size:calc(9px * var(--md-s)); }
  .hl-bottom{ gap:calc(10px * var(--md-s)); padding-left:calc(10px * var(--md-s)); padding-right:calc(10px * var(--md-s)); } .hl-bar{ height:calc(34px * var(--md-s)); }
  .hl-cm-hb{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); font-size:calc(18px * var(--md-s)); }
  .hl-cmy{ width:calc(28px * var(--md-s)); height:calc(28px * var(--md-s)); } .hl-send{ width:calc(32px * var(--md-s)); height:calc(32px * var(--md-s)); }
}
@media(max-width:330px){
  #hlComments{ --cm-px:10px; --csav:26px; --csgap:calc(7px * var(--md-s)); --cm-fs:calc(12px * var(--md-s)); --cm-meta:10px; }
  .hl-avatar{ width:calc(29px * var(--md-s)); height:calc(29px * var(--md-s)); border-width:2px; }
  .hl-icon-btn{ --hl-ic:calc(17px * var(--md-s)); width:calc(28px * var(--md-s)); height:calc(28px * var(--md-s)); } .hl-act i{ font-size:calc(20px * var(--md-s)); }
}
@media(prefers-reduced-motion:reduce){ #hlModal *{ animation-duration:.01ms!important; } }
</style>

<div id="hlModal" onclick="if(event.target===this) hlClose()">
  <div id="hlScroll" onclick="if(event.target===this) hlClose()">
    <div id="hlWrap">

      <button type="button" class="hl-nav hl-nav-prev off" id="hlNavPrev" onclick="hlGoHl(-1)" aria-label="Previous person"><i class="ri-arrow-left-s-line"></i></button>
      <button type="button" class="hl-nav hl-nav-next off" id="hlNavNext" onclick="hlGoHl(1)" aria-label="Next person"><i class="ri-arrow-right-s-line"></i></button>

      <!-- ═════ THE DAYLEEZ BOX ═════ -->
      <div id="hlStage">
        <div id="hlScene"><div id="hlCube"><div id="hlFace">
          <div id="hlMedia"></div>
          <div class="hl-shade-top"></div><div class="hl-shade-bottom"></div>
          <div class="hl-zone hl-zone-prev" onclick="hlPrev()"></div><div class="hl-zone hl-zone-next" onclick="hlNext()"></div>
          <div id="hlCapLayer" class="hl-cap-layer"></div>

          <div class="hl-top">
            <div class="hl-dashes" id="hlDashes"></div>
            <div class="hl-head">
              <div class="hl-av-col"><a id="hlAvatarLink" class="hl-av-link" href="#" aria-label="Open profile"><img id="hlAvatar" class="hl-avatar" src="uploads/me.png" alt="" onerror="this.onerror=null;this.src='uploads/me.png'"></a></div>
              <div class="hl-head-info"><div class="hl-title-row"><span id="hlTitle" class="hl-title"></span><span id="hlDate" class="hl-date"></span></div></div>
              <button type="button" class="hl-icon-btn" id="hlSnd" onclick="hlSound()" aria-label="Sound" style="display:none"><i class="ri-volume-up-fill"></i></button>
              <button type="button" class="hl-icon-btn hl-menu" onclick="hlOpenSheet()" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M2 6h20M2 12h20M2 18h20"/></svg></button>
              <div class="hl-x-col"><button type="button" class="hl-icon-btn hl-x" onclick="hlClose()" aria-label="Close"><i class="ri-close-line"></i></button><span id="hlCount" class="hl-count"></span></div>
            </div>
            <div id="hlSong" class="hl-songline"></div>
          </div>

          <div id="hlMediaPanel" class="hl-mpanel" aria-hidden="true">
            <div class="hl-mp-head"><span id="hlMpTitle" class="hl-mp-title"></span><button type="button" class="hl-mp-x" onclick="hlMediaClose()" aria-label="Close"><i class="ri-close-line"></i></button></div>
            <div id="hlMpStrip" class="hl-mp-strip"></div>
          </div>

          <div class="hl-bottom">
            <div class="hl-bar" id="hlBar" onclick="hlGoComments(true)" role="button" tabindex="0"><span class="hl-fake">Add a comment…</span></div>
            <button type="button" class="hl-act hl-like" id="hlLikeBtn" onclick="hlLike()" aria-label="Like"><i class="ri-heart-3-fill"></i><span id="hlLikeCount"></span></button>
            <button type="button" class="hl-act" onclick="hlGoComments(false)" aria-label="Comments"><i class="ri-question-answer-fill"></i><span id="hlBarCount"></span></button>
            <button type="button" id="hlMediaBtn" class="hl-act" onclick="hlMediaToggle()" aria-label="All media">
              <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="2" y="7" width="13" height="14" rx="2.6"/><path d="M8 3.4h11.2A2.8 2.8 0 0 1 22 6.2V17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              <span id="hlMediaCount"></span>
            </button>
          </div>
        </div></div></div>

        <div id="hlLoader"><div class="hl-spin"></div></div>
        <div id="hlSheetOv" onclick="hlCloseSheet()"></div>
        <div id="hlSheet">
          <div class="hl-sheet-handle"></div>
          <div id="hlSheetList"></div>
          <button type="button" class="hl-sheet-cancel" onclick="hlCloseSheet()">Cancel</button>
        </div>
      </div>

      <!-- ═════ COMMENTS (below the box, scrolls with the modal) ═════ -->
      <section id="hlComments" aria-label="Comments">
        <div class="hl-cm-top"><div class="hl-cm-head">
          <div class="hl-cm-title"><i class="ri-question-answer-fill"></i>Comments<span id="hlCmCount" class="hl-cm-pill"></span></div>
          <div class="hl-cm-sort">
            <button type="button" class="hl-cm-sortbtn" id="hlCmSortBtn"><span id="hlCmSortLbl">Top</span><i class="ri-arrow-down-s-line"></i></button>
            <div class="hl-cm-sortmenu" id="hlCmSortMenu">
              <button type="button" class="active" data-sort="top">Top comments</button>
              <button type="button" data-sort="new">Newest first</button>
              <button type="button" data-sort="old">Oldest first</button>
            </div>
          </div>
          <button type="button" class="hl-cm-hb" id="hlCmNav" onclick="hlCmNav()" aria-label="Go to comments / back to the Dayleez"><i class="ri-arrow-down-s-line hl-nv-dn"></i><i class="ri-arrow-up-s-line hl-nv-up"></i></button>
          <button type="button" class="hl-cm-hb" onclick="hlClose()" aria-label="Close"><i class="ri-close-line"></i></button>
        </div></div>
        <div class="hl-cm-note"><i class="ri-shield-check-line"></i><span>Remember to keep comments respectful.</span></div>
        <div id="hlCmList"></div>
        <div class="hl-cm-sent" id="hlCmSent"><div class="hl-cm-spin"></div></div>
        <div id="hlCmCompose" class="hl-cm-composer"></div>
        <div class="hl-cm-menu" id="hlCmMenu" role="menu">
          <button type="button" data-act="download" role="menuitem"><i class="ri-download-2-line"></i><span>Download comment</span></button>
          <button type="button" data-act="delete" class="danger" role="menuitem"><i class="ri-delete-bin-line"></i><span>Delete comment</span></button>
        </div>
      </section>

    </div>
  </div>
</div>

<script>
(function(){
  'use strict';
  var IMG_MS=20000;
  var FB_IMG='data:image/svg+xml;utf8,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" fill="#0d1030"/><circle cx="20" cy="15" r="7" fill="#2b3a7a"/><path d="M6 36c2-8 8-11 14-11s12 3 14 11z" fill="#2b3a7a"/></svg>');
  window.hlImgFail=function(im){ im.onerror=null; im.src=FB_IMG; };

  var H=null,hi=0,mi=0,lastHi=-1,cmFor=0,isOpen=false,changed=false;
  var raf=null,elapsed=0,lastTs=null,renderTok=0,capOpen=false,offscreen=false,ioDone=false,liking=false;
  var flip=null,flipping=false,mediaOK=false,readyCb=null,aud=null,audSrc='',soundOn=true;
  var CM={sid:0,items:[],map:{},more:false,loading:false,posting:false,reply:null,sort:'top',offset:0,menuFor:0,seq:0};
  var SORT_LABELS={top:'Top','new':'Newest',old:'Oldest'};

  function vtoast(msg){
    var old=$('hlModal').querySelector('.hl-toast');if(old)old.remove();
    var t=document.createElement('div');t.className='hl-toast';t.textContent=msg;$('hlModal').appendChild(t);
    setTimeout(function(){t.style.opacity='0';setTimeout(function(){t.remove()},300)},2000);
  }
  function fmtNum(n){n=+n||0;return n>=1e6?(n/1e6).toFixed(1).replace(/\.0$/,'')+'M':n>=1e3?(n/1e3).toFixed(1).replace(/\.0$/,'')+'K':String(n)}
  function cAge(s){s=+s||0;if(s<60)return'now';var m=Math.floor(s/60);if(m<60)return m+'m';var h=Math.floor(m/60);if(h<24)return h+'h';var d=Math.floor(h/24);return d<7?d+'d':Math.floor(d/7)+'w'}
  function api(action,data){
    var p=new URLSearchParams();p.append('dl_action',action);
    Object.keys(data||{}).forEach(function(k){p.append(k,data[k])});
    return fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:p.toString(),credentials:'same-origin'})
      .then(function(r){return r.json()}).catch(function(){return{success:false,msg:'Network error. Try again.'}});
  }
  function cur(){return H?H[hi]:null}
  function curM(){var h=cur();return h?h.media[mi]:null}
  function poke(){elapsed=0}
  function firstUnseen(idx){var u=H[idx],i;for(i=0;i<u.media.length;i++){if(!u.media[i].seen)return i}return 0}
  window.hlMore=function(btn){var cap=btn.previousElementSibling,col=cap.classList.toggle('clamp');btn.textContent=col?'more':'less';capOpen=!col;poke();syncVideo()};

  /* arrows (normal view) */
  function updateNav(){
    var p=$('hlNavPrev'),n=$('hlNavNext'),noP=!H||hi<=0,noN=!H||hi>=H.length-1;
    p.classList.toggle('off',noP);p.disabled=noP;n.classList.toggle('off',noN);n.disabled=noN;
  }
  window.hlGoHl=function(dir){
    if(!H||flipping||$('hlSheet').classList.contains('show'))return;
    var idx=hi+dir;if(idx<0||idx>=H.length)return;
    autoFlip(dir,dir>0?firstUnseen(idx):0);
  };

  /* smart fit */
  function hlFixFit(el){
    var box=el.parentNode;if(!box)return;
    var mW=el.tagName==='VIDEO'?el.videoWidth:el.naturalWidth,mH=el.tagName==='VIDEO'?el.videoHeight:el.naturalHeight;
    if(!mW||!mH)return;var cW=box.clientWidth,cH=box.clientHeight;if(!cW||!cH)return;
    var ma=mW/mH,ba=cW/cH;
    if(ma<ba){el.style.objectFit=((ma/ba)>=0.55)?'fill':'contain';return}
    el.style.objectFit=((cW/ma)/cH>=0.84)?'fill':'contain';
  }
  window.addEventListener('resize',function(){if(!isOpen)return;var el=$('hlMedia').querySelector('.hl-media-item');if(el)hlFixFit(el)});

  /* pause state + sound */
  function getAud(){if(!aud){aud=new Audio();aud.preload='auto'}return aud}
  function isPaused(){
    var a=document.activeElement;
    return flipping||capOpen||offscreen||document.hidden||$('hlSheet').classList.contains('show')||$('hlMediaPanel').classList.contains('show')||(a&&a.id==='hlCIn');
  }
  function syncVideo(){
    var p=isPaused(),v=$('hlMedia').querySelector('video');
    if(v){if(p)v.pause();else v.play().catch(function(){})}
    if(aud&&audSrc){if(p)aud.pause();else aud.play().catch(function(){})}
  }
  function stopAudio(){audSrc='';if(!aud)return;aud.pause();aud.removeAttribute('src');try{aud.load()}catch(e){}}
  function playSong(path,loop,restart){
    if(!path){if(audSrc)stopAudio();return}
    var a=getAud(),ch=audSrc!==path;if(ch){a.src=path;audSrc=path}
    if(ch||restart){try{a.currentTime=0}catch(e){}}
    a.loop=loop;a.muted=!soundOn;if(!isPaused())a.play().catch(function(){});
  }
  function updateSoundBtn(m){
    var has=!!audSrc||(m.type==='video'&&!m.mute),b=$('hlSnd');b.style.display=has?'':'none';
    b.querySelector('i').className=soundOn?'ri-volume-up-fill':'ri-volume-mute-fill';
  }
  window.hlSound=function(){
    soundOn=!soundOn;if(aud)aud.muted=!soundOn;var m=curM(),v=$('hlMedia').querySelector('video');
    if(v)v.muted=!soundOn||!!m.mute;updateSoundBtn(m);
  };
  $('hlStage').addEventListener('pointerdown',function(){if(aud&&audSrc&&aud.paused&&!isPaused())aud.play().catch(function(){})},{passive:true});
  document.addEventListener('visibilitychange',function(){if(isOpen)syncVideo()});

  /* the box pauses when you scroll down to the comments; the composer shows on phones only while you are in the comments */
  function setupObserver(){
    if(ioDone||!('IntersectionObserver' in window))return;ioDone=true;
    new IntersectionObserver(function(es){
      es.forEach(function(e){offscreen=e.intersectionRatio<0.5;$('hlComments').classList.toggle('cm-on',offscreen);if(isOpen)syncVideo()});
    },{root:$('hlScroll'),threshold:[0,.25,.5,.75,1]}).observe($('hlStage'));
  }

  /* open / close */
  window.openDayleez=function(uid,ids){
    if(isOpen)return;
    var m=$('hlModal');isOpen=true;changed=false;offscreen=false;flipping=false;capOpen=false;lastHi=-1;cmFor=0;
    m.classList.add('open');void m.offsetHeight;m.classList.add('vis');document.body.style.overflow='hidden';
    $('hlScroll').scrollTop=0;$('hlComments').classList.remove('cm-on');$('hlLoader').classList.add('show');
    updateNav();setupObserver();resetCm();renderCompose(null);
    api('viewer',{uids:(ids||[uid]).join(',')}).then(function(d){
      if(!isOpen)return;
      if(!d||!d.success||!d.users.length){closeNow();toast('Nothing to watch right now');return}
      H=d.users;var idx=-1;H.forEach(function(h,i){if(h.id===uid)idx=i});if(idx<0)idx=0;
      $('hlLoader').classList.remove('show');hi=idx;mi=firstUnseen(hi);
      if(OPEN_D&&H[hi].id===OPEN_U){H[hi].media.forEach(function(x,i){if(x.id===OPEN_D)mi=i})}
      render();
    });
  };
  function closeNow(){
    if(!isOpen)return;
    isOpen=false;renderTok++;cleanupFlip();stopMedia();stopAudio();stopPoll();hlCloseSheet();closeMenu();$('hlCmSortMenu').classList.remove('show');
    $('hlMediaPanel').classList.remove('show');$('hlMediaBtn').classList.remove('on');capOpen=false;flipping=false;
    var a=document.activeElement;if(a&&a.blur)a.blur();
    var m=$('hlModal');m.classList.remove('vis');
    setTimeout(function(){if(isOpen)return;m.classList.remove('open');$('hlMedia').innerHTML='';$('hlCapLayer').innerHTML='';$('hlLoader').classList.remove('show')},150);
    document.body.style.overflow='';
    $('hlNavPrev').classList.add('off');$('hlNavNext').classList.add('off');
    H=null;resetCm();
    if(changed&&window.dlReload){changed=false;window.dlReload()}
  }
  function stopPoll(){}
  window.hlClose=closeNow;
  function stopMedia(){if(raf){cancelAnimationFrame(raf);raf=null}var v=$('hlMedia').querySelector('video');if(v){v.pause();v.removeAttribute('src');try{v.load()}catch(e){}}}

  function runProgress(fill,video){
    elapsed=0;lastTs=null;fill.style.width='0%';
    function tick(ts){
      if(lastTs==null)lastTs=ts;var p;
      if(video){p=video.duration?video.currentTime/video.duration:0}
      else{if(!isPaused())elapsed+=ts-lastTs;p=elapsed/IMG_MS}
      lastTs=ts;fill.style.width=Math.min(100,p*100)+'%';
      if(!video&&p>=1){raf=null;hlNext();return}
      raf=requestAnimationFrame(tick);
    }
    raf=requestAnimationFrame(tick);
  }
  function markReady(){mediaOK=true;if(readyCb){var c=readyCb;readyCb=null;c()}}

  /* render the current Dayleez */
  function paintHeader(h,m){
    var av=$('hlAvatar');av.onerror=function(){window.hlImgFail(av)};av.src=h.pic;$('hlAvatarLink').href=DL_USER_URL+h.id;
    $('hlTitle').textContent=h.isYou?'Your Dayleez':h.name;
    $('hlDate').textContent=ageLabel(m.age)+' · '+leftLabel(m.left);
    $('hlCount').textContent=(mi+1)+'/'+h.media.length;
    paintCounts(m);mediaPanelUpdate();
  }
  function render(){
    stopMedia();var tok=++renderTok,h=H[hi],m=h.media[mi];
    mediaOK=false;capOpen=false;updateNav();paintHeader(h,m);
    if(lastHi!==hi){lastHi=hi;$('hlScroll').scrollTop=0}

    if(!h.isYou&&!m.seen){m.seen=true;changed=true;api('view',{story_id:m.id})}

    $('hlCapLayer').innerHTML=(m.caption?dlCapHTML(m.caption,m.men,dlTf(m.ctf,null)):'')+(m.place?dlPlaceHTML(m.place,dlTf(m.ptf,null)):'');

    if(m.mode==='each')playSong(m.audio,false,true);else playSong(m.song,true,false);
    var sTitle=(m.mode==='each')?(m.audio?m.audioTitle:''):(m.song?m.songTitle:'');
    var ch=Array.from(String(sTitle||'').trim());if(ch.length>40)sTitle=ch.slice(0,40).join('').replace(/\s+$/,'')+'…';
    $('hlSong').textContent=sTitle?'♪ '+sTitle:'';

    var ds='';h.media.forEach(function(x,i){ds+='<div class="hl-dash'+(i<mi?' done':'')+'"><div class="hl-dash-fill"></div></div>'});
    $('hlDashes').innerHTML=ds;var fill=$('hlDashes').children[mi].firstChild;
    var box=$('hlMedia');box.innerHTML='';
    if(m.type==='video'){
      var v=document.createElement('video');v.className='hl-media-item';v.setAttribute('playsinline','');box.appendChild(v);
      v.addEventListener('loadedmetadata',function(){hlFixFit(v)});v.addEventListener('loadeddata',markReady);
      v.addEventListener('ended',function(){if(tok===renderTok)hlNext()});
      v.addEventListener('error',function(){if(tok!==renderTok)return;markReady();runProgress(fill,null)});
      v.src=m.path;v.muted=!!m.mute||!soundOn;
      if(!isPaused())v.play().catch(function(){v.muted=true;v.play().catch(function(){})});
      runProgress(fill,v);
    }else{
      var im=document.createElement('img');im.className='hl-media-item';im.alt='';im.draggable=false;box.appendChild(im);
      var begun=false;
      function begin(){if(begun||tok!==renderTok)return;begun=true;hlFixFit(im);markReady();runProgress(fill,null)}
      im.addEventListener('load',begin);im.onerror=function(){im.onerror=null;im.src=DL_TEXT_BG};
      im.src=(m.type==='text')?DL_TEXT_BG:m.path;
      if(im.complete&&im.naturalWidth)begin();
    }
    updateSoundBtn(m);
    var n=h.media[mi+1]||(H[hi+1]&&H[hi+1].media[0]);if(n&&n.type!=='video'){var i2=new Image();i2.src=(n.type==='text'?DL_TEXT_BG:n.path)}
    /* each media has its own comments: load them whenever the media changes */
    if(cmFor!==m.id){cmFor=m.id;resetCm();renderCompose(h);loadComments(true)}
  }

  /* 3D cube flip between people */
  var cube=$('hlCube'),face=$('hlFace');
  function setCube(a,W){cube.style.transform='translateZ('+(-W/2)+'px) rotateY('+a+'deg)'}
  var MENU_SVG='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M2 6h20M2 12h20M2 18h20"/></svg>';
  var MEDIA_SVG='<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="2" y="7" width="13" height="14" rx="2.6"/><path d="M8 3.4h11.2A2.8 2.8 0 0 1 22 6.2V17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
  function buildPeek(idx,tmi){
    var h=H[idx],m=h.media[tmi],body;
    if(m.type==='video')body='<video class="hl-media-item" src="'+esc(m.path)+'#t=0.1" muted playsinline preload="metadata"></video>';
    else body='<img class="hl-media-item" alt="" src="'+esc(m.type==='text'?DL_TEXT_BG:m.path)+'">';
    var dashes='';h.media.forEach(function(x,i){dashes+='<div class="hl-dash'+(i<tmi?' done':'')+'"><div class="hl-dash-fill"></div></div>'});
    var el=document.createElement('div');el.className='hl-peek';
    el.innerHTML='<div class="hl-peek-media">'+body+'</div><div class="hl-shade-top"></div><div class="hl-shade-bottom"></div>'+
      '<div class="hl-cap-layer">'+(m.caption?dlCapHTML(m.caption,m.men,dlTf(m.ctf,null)):'')+(m.place?dlPlaceHTML(m.place,dlTf(m.ptf,null)):'')+'</div>'+
      '<div class="hl-top"><div class="hl-dashes">'+dashes+'</div><div class="hl-head"><div class="hl-av-col"><img class="hl-avatar" src="'+esc(h.pic)+'" alt="" onerror="hlImgFail(this)"></div>'+
      '<div class="hl-head-info"><div class="hl-title-row"><span class="hl-title">'+esc(h.isYou?'Your Dayleez':h.name)+'</span><span class="hl-date">'+esc(ageLabel(m.age)+' · '+leftLabel(m.left))+'</span></div></div>'+
      '<span class="hl-icon-btn hl-menu">'+MENU_SVG+'</span><div class="hl-x-col"><span class="hl-icon-btn hl-x"><i class="ri-close-line"></i></span><span class="hl-count">'+(tmi+1)+'/'+h.media.length+'</span></div></div></div>'+
      '<div class="hl-bottom"><div class="hl-bar"><span class="hl-fake">Add a comment…</span></div><span class="hl-act hl-like'+(m.liked?' on':'')+'"><i class="ri-heart-3-fill"></i></span>'+
      '<span class="hl-act"><i class="ri-question-answer-fill"></i>'+(m.comments?'<span>'+esc(fmtNum(m.comments))+'</span>':'')+'</span><span class="hl-act">'+MEDIA_SVG+'</span></div>';
    return el;
  }
  function flipBegin(dir,idx,tmi){
    var W=$('hlStage').clientWidth,peek=buildPeek(idx,tmi);
    flip={dir:dir,idx:idx,tmi:tmi,peek:peek,W:W};flipping=true;cube.classList.add('flip');cube.style.transition='none';
    face.style.transform='translateZ('+(W/2)+'px)';peek.style.transform='rotateY('+(dir*90)+'deg) translateZ('+(W/2)+'px)';
    cube.appendChild(peek);setCube(0,W);
    var mm=peek.querySelector('.hl-media-item');
    if(mm){if(mm.tagName==='VIDEO')mm.addEventListener('loadedmetadata',function(){hlFixFit(mm)});else{mm.addEventListener('load',function(){hlFixFit(mm)});if(mm.complete&&mm.naturalWidth)hlFixFit(mm)}}
    syncVideo();
  }
  function flipMove(dx){if(!flip)return;var p=Math.max(0,Math.min(1,(-flip.dir*dx)/flip.W));setCube(-flip.dir*p*90,flip.W)}
  function cleanupFlip(){
    if(!flip){flipping=false;return}
    cube.style.transition='none';cube.style.transform='';cube.classList.remove('flip');face.style.transform='';
    if(flip.peek.parentNode)flip.peek.parentNode.removeChild(flip.peek);
    flip=null;flipping=false;if(isOpen)syncVideo();
  }
  function flipEnd(commit){
    if(!flip)return;var f=flip,done=false;void cube.offsetWidth;
    cube.style.transition='transform .42s cubic-bezier(.22,.75,.28,1)';setCube(commit?-f.dir*90:0,f.W);
    function onEnd(e){if(e.target===cube)fin()}
    function fin(){
      if(done)return;done=true;cube.removeEventListener('transitionend',onEnd);
      if(flip!==f)return;if(!commit){cleanupFlip();return}
      hi=f.idx;mi=f.tmi;readyCb=null;render();
      var settled=false;function settle(){if(settled)return;settled=true;readyCb=null;requestAnimationFrame(cleanupFlip)}
      if(mediaOK)settle();else{readyCb=settle;setTimeout(settle,450)}
    }
    cube.addEventListener('transitionend',onEnd);setTimeout(fin,560);
  }
  function autoFlip(dir,tmi){var idx=hi+dir;if(!H||idx<0||idx>=H.length)return false;flipBegin(dir,idx,tmi);flipEnd(true);return true}

  /* navigation */
  window.hlNext=function(){
    if(flipping||!H||$('hlSheet').classList.contains('show'))return;
    var h=H[hi];
    if(mi<h.media.length-1){mi++;render()}else if(hi<H.length-1){autoFlip(1,firstUnseen(hi+1))}else closeNow();
  };
  window.hlPrev=function(){
    if(flipping||!H||$('hlSheet').classList.contains('show'))return;
    if(mi>0){mi--;render()}else if(hi>0){autoFlip(-1,H[hi-1].media.length-1)}else render();
  };
  window.hlToTop=function(){$('hlScroll').scrollTo({top:0,behavior:'smooth'})};
  window.hlCmNav=function(){if($('hlComments').classList.contains('cm-on'))hlToTop();else hlGoComments(false)};
  window.hlGoComments=function(focus){
    $('hlComments').scrollIntoView({behavior:'smooth',block:'start'});
    if(focus){setTimeout(function(){var i=$('hlCIn');if(i)i.focus({preventScroll:true})},420)}
  };

  /* all-media strip */
  function mediaPanelOpen(){return $('hlMediaPanel').classList.contains('show')}
  function mediaPanelUpdate(){
    if(!H||!H[hi])return;var h=H[hi],n=h.media.length;$('hlMediaCount').textContent=n>1?n:'';
    if(!mediaPanelOpen())return;
    $('hlMpTitle').textContent=(mi+1)+' of '+n+' media';
    var strip=$('hlMpStrip');
    if(strip.getAttribute('data-hl')!==String(hi)||strip.children.length!==n){
      strip.setAttribute('data-hl',String(hi));var html='';
      h.media.forEach(function(x,i){
        var inner;
        if(x.type==='video')inner='<video src="'+esc(x.path)+'#t=0.1" muted playsinline preload="metadata"></video><i class="ri-play-fill hl-mp-vid"></i>';
        else if(x.type==='text')inner='<img alt="" src="'+esc(DL_TEXT_BG)+'"><span class="hl-mp-txt">Aa</span>';
        else inner='<img alt="" loading="lazy" src="'+esc(x.path)+'" onerror="this.onerror=null;this.src=\''+esc(DL_TEXT_BG)+'\'">';
        html+='<button type="button" class="hl-mp-item" onclick="hlMediaGo('+i+')" aria-label="Media '+(i+1)+'">'+inner+'<span class="hl-mp-num">'+(i+1)+'</span></button>';
      });
      strip.innerHTML=html;
    }
    Array.prototype.forEach.call(strip.children,function(el,i){el.classList.toggle('cur',i===mi)});
    var c=strip.children[mi];
    if(c){var left=c.offsetLeft-(strip.clientWidth-c.offsetWidth)/2;try{strip.scrollTo({left:Math.max(0,left),behavior:'smooth'})}catch(_){strip.scrollLeft=Math.max(0,left)}}
  }
  window.hlMediaToggle=function(){
    if(!H||flipping)return;if(mediaPanelOpen()){hlMediaClose();return}
    var p=$('hlMediaPanel');p.classList.add('show');p.setAttribute('aria-hidden','false');$('hlMediaBtn').classList.add('on');syncVideo();mediaPanelUpdate();
  };
  window.hlMediaClose=function(){
    var p=$('hlMediaPanel');if(!p.classList.contains('show'))return;
    p.classList.remove('show');p.setAttribute('aria-hidden','true');$('hlMediaBtn').classList.remove('on');poke();if(isOpen)syncVideo();
  };
  window.hlMediaGo=function(i){if(!H||flipping)return;if(i<0||i>=H[hi].media.length)return;if(i!==mi){mi=i;render()}else mediaPanelUpdate()};

  /* like (per media) */
  function paintLike(m){
    var b=$('hlLikeBtn');if(!b)return;b.classList.toggle('on',!!m.liked);
    $('hlLikeCount').textContent=(H&&H[hi]&&H[hi].isYou&&m.likeCount>0)?fmtNum(m.likeCount):'';
  }
  function paintCounts(m){
    var c=m.comments||0;$('hlCmCount').textContent=c?String(c):'';$('hlBarCount').textContent=c?fmtNum(c):'';paintLike(m);
  }
  window.hlLike=function(){
    var m=curM();if(!m||liking)return;
    var was=m.liked,n=m.likeCount;m.liked=!was;m.likeCount=Math.max(0,n+(was?-1:1));paintLike(m);
    var ic=$('hlLikeBtn').querySelector('i');ic.classList.remove('pop');void ic.offsetWidth;ic.classList.add('pop');
    liking=true;
    api('like',{story_id:m.id}).then(function(d){
      liking=false;
      if(!d.success){m.liked=was;m.likeCount=n;if(curM()===m)paintLike(m);vtoast(d.msg||'Could not like');return}
      m.liked=!!d.liked;m.likeCount=+d.count||0;if(curM()===m)paintLike(m);
    });
  };

  /* ══════════════ COMMENTS: sort, likes / dislikes, ⋯ menu, nested replies ══════════════ */
  function resetCm(){
    CM.sid=0;CM.items=[];CM.map={};CM.more=false;CM.loading=false;CM.posting=false;CM.reply=null;CM.offset=0;CM.seq++;CM.sort='top';
    $('hlCmSortLbl').textContent=SORT_LABELS.top;
    Array.prototype.forEach.call($('hlCmSortMenu').querySelectorAll('button'),function(b){b.classList.toggle('active',b.getAttribute('data-sort')==='top')});
    $('hlCmSortMenu').classList.remove('show');closeMenu();$('hlCmList').innerHTML='';$('hlCmSent').classList.remove('show');
  }
  function reg(c){
    c.rc=+c.replies||0;c.kids=[];c.open=false;c.kmore=false;c.kloaded=false;c.kloading=false;c.koff=0;
    c.likes=+c.likes||0;c.liked=!!c.liked;c.disliked=!!c.disliked;c.ol=!!c.ol;CM.map[c.id]=c;return c;
  }
  function linkText(t){
    t=String(t||'');var mention='',rest=t,mm=t.match(/^(@[A-Za-z0-9_.]{1,40})(\s|$)/);
    if(mm){mention=mm[1];rest=t.slice(mention.length)}
    var re=/((?:https?:\/\/|www\.)[^\s<>"']+)/gi,out='',last=0,m;
    if(mention)out='<span class="hl-mention">'+esc(mention)+'</span>';
    while((m=re.exec(rest))!==null){
      var url=m[0],trail='',tm=url.match(/[.,!?;:)\]}]+$/);
      if(tm){trail=tm[0];url=url.slice(0,url.length-trail.length)}
      if(!url)continue;
      var href=/^www\./i.test(url)?'https://'+url:url;
      out+=esc(rest.slice(last,m.index))+'<a class="hl-link" href="'+esc(href)+'" target="_blank" rel="noopener noreferrer nofollow">'+esc(url)+'</a>'+esc(trail);
      last=m.index+m[0].length;
    }
    return(out+esc(rest.slice(last))).replace(/\n/g,'<br>');
  }
  function cutText(text){
    var full=String(text||'').replace(/\s+$/,''),re=/\S+/g,m,n=0,end=-1;
    while((m=re.exec(full))!==null){n++;if(n===30){end=m.index+m[0].length;break}}
    var cut=(end!==-1)?full.slice(0,end):full;
    if(cut.length>280){var s=cut.slice(0,280),sp=s.lastIndexOf(' ');cut=(sp>140)?s.slice(0,sp):s}
    cut=cut.replace(/\s+$/,'');return cut.length>=full.length?null:cut;
  }
  function textHtml(text){
    var cut=cutText(text);
    if(cut===null)return '<div class="hl-ci-tx">'+linkText(text)+'</div>';
    return '<div class="hl-ci-tx"><span class="hl-short">'+linkText(cut)+'</span><span class="hl-full">'+linkText(text)+'</span> <button type="button" class="hl-rm" data-rm="1">...Read more</button></div>';
  }
  function avImg(url){return '<img src="'+esc(url||FB_IMG)+'" alt="" onerror="hlImgFail(this)">'}
  function repLabel(n){return n+(n===1?' reply':' replies')}
  function ciHtml(c){
    var h=cur(),crPic=(h&&h.pic)?h.pic:FB_IMG;
    var main='<div class="hl-ci-main"><div class="hl-ci-avcol"><a class="hl-ci-av" href="'+esc(DL_USER_URL+c.uid)+'">'+avImg(c.pic)+'</a><span class="hl-ci-line"></span></div><div class="hl-ci-body">'+
      '<div class="hl-ci-l1"><a class="hl-ci-un" href="'+esc(DL_USER_URL+c.uid)+'">'+esc(c.mine?'You':c.name)+'</a>'+(c.role==='creator'?'<span class="hl-bdg">Creator</span>':'')+
      '<span class="hl-ci-t">'+esc(cAge(c.age))+'</span><button type="button" class="hl-ci-more" data-more="'+c.id+'" aria-label="Comment options" aria-haspopup="menu"><i class="ri-more-2-fill"></i></button></div>'+
      textHtml(c.text)+
      '<div class="hl-ci-r2">'+
        '<button type="button" class="hl-ci-like'+(c.liked?' on':'')+'" data-like="'+c.id+'" aria-label="Like comment"><i class="'+(c.liked?'ri-thumb-up-fill':'ri-thumb-up-line')+'"></i><span>'+(c.likes>0?esc(fmtNum(c.likes)):'')+'</span></button>'+
        '<button type="button" class="hl-ci-dis'+(c.disliked?' on':'')+'" data-dis="'+c.id+'" aria-label="Dislike comment"><i class="'+(c.disliked?'ri-thumb-down-fill':'ri-thumb-down-line')+'"></i></button>'+
        '<button type="button" class="hl-ci-reply" data-reply="'+c.id+'" data-name="'+esc(c.name)+'">Reply</button>'+
        '<span class="hl-crl" title="Liked by the creator"'+(c.ol?'':' style="display:none"')+'><span class="hl-crl-av">'+avImg(crPic)+'</span><i class="hl-crl-b ri-heart-fill"></i></span>'+
      '</div></div></div>';
    var thread='',hasT=c.rc>0;
    if(hasT){
      thread='<button type="button" class="hl-ci-tg'+(c.open?' open':'')+'" data-tg="'+c.id+'"><span class="hl-ci-tgav">'+avImg(c.rpic)+'</span><span class="hl-ci-tgd">·</span><span>'+repLabel(c.rc)+'</span><i class="ri-arrow-right-s-line hl-ci-chev"></i></button>';
      if(c.open){
        var inner='';
        if(c.kloading&&!c.kids.length)inner='<div class="hl-ci-skel"><div class="hl-sk" style="width:26px;height:26px;border-radius:30%;flex-shrink:0"></div><div style="flex:1"><div class="hl-sk" style="width:40%;height:9px;margin-bottom:8px"></div><div class="hl-sk" style="width:75%;height:9px"></div></div></div>';
        else inner=c.kids.map(ciHtml).join('');
        if(c.kmore)inner+='<button type="button" class="hl-ci-sm" data-sm="'+c.id+'">'+(c.kloading?'Loading…':'Show more replies')+'</button>';
        thread+='<div class="hl-sub">'+inner+'</div>';
      }
    }
    return '<div class="hl-ci'+(hasT?' has-thread':'')+(c._n?' fresh':'')+'" data-id="'+c.id+'">'+main+thread+'</div>';
  }
  function clearFresh(){Object.keys(CM.map).forEach(function(k){CM.map[k]._n=false})}
  function renderComments(){
    var html='';
    if(CM.loading&&!CM.items.length){
      for(var s=0;s<4;s++)html+='<div class="hl-ci-skel"><div class="hl-sk" style="width:var(--csav);height:var(--csav);border-radius:30%;flex-shrink:0"></div><div style="flex:1"><div class="hl-sk" style="width:90px;height:10px;margin-bottom:8px"></div><div class="hl-sk" style="width:80%;height:10px"></div></div></div>';
    }else if(!CM.items.length){
      html='<div class="hl-cm-empty"><i class="ri-question-answer-line"></i>No comments yet.<br>Be the first to comment.</div>';
    }else html=CM.items.map(ciHtml).join('');
    $('hlCmList').innerHTML=html;$('hlCmSent').classList.toggle('show',CM.more);clearFresh();
  }
  function loadComments(reset){
    var m=curM();if(!m||CM.loading)return;
    if(reset){CM.items=[];CM.map={};CM.offset=0;CM.more=false}else if(!CM.more)return;
    var seq=CM.seq;CM.sid=m.id;CM.loading=true;renderComments();
    api('comments',{story_id:m.id,sort:CM.sort,offset:CM.offset}).then(function(d){
      if(seq!==CM.seq)return;CM.loading=false;
      if(!d.success){vtoast(d.msg||'Could not load comments');renderComments();return}
      var got=(d.items||[]).map(function(c){c._n=true;return reg(c)});
      CM.items=CM.items.concat(got);CM.offset+=got.length;CM.more=!!d.more;
      m.comments=d.total;paintCounts(m);renderComments();
    });
  }
  function loadKids(id,more){
    var c=CM.map[id],m=curM();if(!c||!m||c.kloading||(more&&!c.kmore))return;
    c.kloading=true;var seq=CM.seq;renderComments();
    api('comments',{story_id:m.id,parent_id:id,offset:c.koff}).then(function(d){
      if(seq!==CM.seq)return;c.kloading=false;
      if(!d.success){vtoast(d.msg||'Could not load replies');renderComments();return}
      (d.items||[]).forEach(function(g){if(c.kids.some(function(k){return k.id===g.id}))return;c.kids.push(reg(g))});
      c.koff+=(d.items||[]).length;c.kmore=!!d.more;c.kloaded=true;renderComments();
    });
  }

  /* composer */
  function renderCompose(h){
    var box=$('hlCmCompose');
    if(!h){box.innerHTML='';return}
    box.innerHTML=
      '<div class="hl-reply-chip" id="hlReplyChip"><span>Replying to</span><b></b><button type="button" class="hl-reply-x" data-cancel-reply="1" aria-label="Cancel reply"><i class="ri-close-line"></i></button></div>'+
      '<div class="hl-compose-row"><span class="hl-cmy">'+avImg(MY_PIC)+'</span>'+
      '<input type="text" id="hlCIn" maxlength="500" placeholder="Add a comment…" autocomplete="off" enterkeyhint="send">'+
      '<button type="button" class="hl-send" id="hlCSend" aria-label="Post comment" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5"/><path d="M5.5 11.5 12 5l6.5 6.5"/></svg></button></div>';
    var inp=$('hlCIn'),send=$('hlCSend');
    inp.addEventListener('focus',syncVideo);inp.addEventListener('blur',syncVideo);
    inp.addEventListener('input',function(){send.disabled=!inp.value.trim()});
    inp.addEventListener('keydown',function(e){e.stopPropagation();if(e.key==='Enter'){e.preventDefault();postComment()}});
    send.addEventListener('click',postComment);
  }
  function paintReplyChip(){
    var chip=$('hlReplyChip'),inp=$('hlCIn');if(!chip||!inp)return;
    if(CM.reply){chip.querySelector('b').textContent='@'+CM.reply.name;chip.classList.add('show');inp.placeholder='Reply to @'+CM.reply.name+'…'}
    else{chip.classList.remove('show');inp.placeholder='Add a comment…'}
  }
  function startReply(btn){
    var inp=$('hlCIn');if(!inp)return;
    CM.reply={id:parseInt(btn.getAttribute('data-reply'),10),name:btn.getAttribute('data-name')};paintReplyChip();
    inp.value='@'+CM.reply.name+' ';inp.dispatchEvent(new Event('input'));inp.focus({preventScroll:true});
  }
  function cancelReply(){
    CM.reply=null;paintReplyChip();var inp=$('hlCIn');
    if(inp&&/^@\S+\s*$/.test(inp.value)){inp.value='';inp.dispatchEvent(new Event('input'))}
  }
  function postComment(){
    var inp=$('hlCIn'),btn=$('hlCSend'),m=curM();if(!inp||!m||CM.posting)return;
    var t=inp.value.trim();if(!t)return;var rep=CM.reply;
    if(rep&&t.replace(/\s+/g,'')==='@'+rep.name.replace(/\s+/g,''))return;
    var data={story_id:m.id,text:t};if(rep)data.parent_id=rep.id;
    CM.posting=true;btn.disabled=true;
    api('comment_add',data).then(function(d){
      CM.posting=false;
      if(!d.success){btn.disabled=!inp.value.trim();vtoast(d.msg||'Could not post');return}
      inp.value='';btn.disabled=true;m.comments=d.total;paintCounts(m);
      if(curM()!==m||!d.item)return;
      var item=d.item;item._n=true;var p=rep?CM.map[rep.id]:null;
      if(p){
        p.rc+=1;p.open=true;if(!p.rpic)p.rpic=item.pic;
        if(p.kloaded){if(!p.kids.some(function(k){return k.id===item.id})){p.kids.push(reg(item));if(!p.kmore)p.koff+=1}renderComments()}
        else{renderComments();loadKids(p.id,false)}
        CM.reply=null;paintReplyChip();
      }else{
        reg(item);
        if(CM.sort==='old'&&CM.more){}
        else if(CM.sort==='old')CM.items.push(item);else CM.items.unshift(item);
        CM.offset+=1;renderComments();
        if(CM.sort!=='old')$('hlComments').scrollIntoView({behavior:'smooth',block:'start'});
      }
    });
  }

  /* like / dislike on a comment */
  function itemEl(id){return $('hlCmList').querySelector('.hl-ci[data-id="'+id+'"]')}
  function paintRow(c,pop){
    var it=itemEl(c.id);if(!it)return;var row=it.querySelector('.hl-ci-main .hl-ci-r2');if(!row)return;
    var l=row.querySelector('.hl-ci-like'),d=row.querySelector('.hl-ci-dis'),cr=row.querySelector('.hl-crl');
    l.classList.toggle('on',c.liked);l.querySelector('i').className=c.liked?'ri-thumb-up-fill':'ri-thumb-up-line';
    l.querySelector('span').textContent=c.likes>0?fmtNum(c.likes):'';
    d.classList.toggle('on',c.disliked);d.querySelector('i').className=c.disliked?'ri-thumb-down-fill':'ri-thumb-down-line';
    if(cr)cr.style.display=c.ol?'':'none';
    if(pop){var ic=(pop==='like'?l:d).querySelector('i');ic.classList.remove('pop');void ic.offsetWidth;ic.classList.add('pop')}
  }
  function iAmCreator(){var h=cur();return !!(h&&h.isYou)}
  function cmLike(btn){
    var id=parseInt(btn.getAttribute('data-like'),10),c=CM.map[id];if(!c)return;
    var was={liked:c.liked,dis:c.disliked,likes:c.likes,ol:c.ol};
    c.liked=!was.liked;c.disliked=false;c.likes=Math.max(0,was.likes+(c.liked?1:-1));if(iAmCreator())c.ol=c.liked;paintRow(c,'like');
    api('comment_like',{comment_id:id}).then(function(d){
      if(!d.success){c.liked=was.liked;c.disliked=was.dis;c.likes=was.likes;c.ol=was.ol;paintRow(c);vtoast(d.msg||'Could not save that');return}
      c.liked=!!d.liked;c.disliked=!!d.disliked;c.likes=+d.likes||0;c.ol=!!d.ol;paintRow(c);
    });
  }
  function cmDislike(btn){
    var id=parseInt(btn.getAttribute('data-dis'),10),c=CM.map[id];if(!c)return;
    var was={liked:c.liked,dis:c.disliked,likes:c.likes,ol:c.ol};
    c.disliked=!was.dis;if(c.disliked&&was.liked){c.liked=false;c.likes=Math.max(0,was.likes-1);if(iAmCreator())c.ol=false}paintRow(c,'dis');
    api('comment_dislike',{comment_id:id}).then(function(d){
      if(!d.success){c.liked=was.liked;c.disliked=was.dis;c.likes=was.likes;c.ol=was.ol;paintRow(c);vtoast(d.msg||'Could not save that');return}
      c.liked=!!d.liked;c.disliked=!!d.disliked;c.likes=+d.likes||0;c.ol=!!d.ol;paintRow(c);
    });
  }

  /* ⋯ menu */
  function closeMenu(){$('hlCmMenu').classList.remove('show');CM.menuFor=0}
  function openMenu(btn){
    var id=parseInt(btn.getAttribute('data-more'),10),c=CM.map[id];if(!c)return;
    var m=$('hlCmMenu');if(m.classList.contains('show')&&CM.menuFor===id){closeMenu();return}
    CM.menuFor=id;m.querySelector('[data-act="delete"]').style.display=c.canDel?'':'none';m.classList.add('show');
    var cr=$('hlComments').getBoundingClientRect(),br=btn.getBoundingClientRect(),mh=m.offsetHeight,top=br.bottom-cr.top+4;
    if(br.bottom+mh+8>window.innerHeight)top=br.top-cr.top-mh-4;
    m.style.top=Math.max(8,top)+'px';m.style.right=Math.max(8,cr.right-br.right)+'px';
  }
  function dlComment(id){
    var c=CM.map[id];if(!c)return;
    var body='Comment by @'+c.name+'\n'+cAge(c.age)+' ago\n\n'+c.text+'\n';
    var url=URL.createObjectURL(new Blob([body],{type:'text/plain;charset=utf-8'}));
    var a=document.createElement('a');a.href=url;a.download='comment-'+id+'.txt';a.rel='noopener';
    document.body.appendChild(a);a.click();document.body.removeChild(a);setTimeout(function(){URL.revokeObjectURL(url)},1500);vtoast('Comment downloaded');
  }
  function dropFromMap(c){delete CM.map[c.id];c.kids.forEach(dropFromMap)}
  function delComment(id){
    var c=CM.map[id],m=curM();if(!c||!m||!c.canDel)return;
    if(!confirm('Delete this comment? This cannot be undone.'))return;
    api('comment_del',{comment_id:id}).then(function(d){
      if(!d.success){vtoast(d.msg||'Could not delete');return}
      var par=c.parent?CM.map[c.parent]:null;
      if(CM.reply&&CM.reply.id===id){CM.reply=null;paintReplyChip()}
      if(par){par.kids=par.kids.filter(function(k){return k.id!==id});par.rc=Math.max(0,par.rc-1);par.koff=Math.max(0,par.koff-1)}
      else{CM.items=CM.items.filter(function(k){return k.id!==id});CM.offset=Math.max(0,CM.offset-1)}
      dropFromMap(c);m.comments=d.total;paintCounts(m);renderComments();vtoast('Comment deleted');
    });
  }
  $('hlComments').addEventListener('click',function(e){
    var t=e.target;function up(sel){return t.closest?t.closest(sel):null}var x;
    if((x=up('[data-cancel-reply]'))){cancelReply();return}
    if((x=up('[data-more]'))){openMenu(x);return}
    if((x=up('[data-act]'))){var act=x.getAttribute('data-act'),id=CM.menuFor;closeMenu();if(act==='download')dlComment(id);else if(act==='delete')delComment(id);return}
    if((x=up('[data-like]'))){cmLike(x);return}
    if((x=up('[data-dis]'))){cmDislike(x);return}
    if((x=up('[data-reply]'))){startReply(x);return}
    if((x=up('[data-tg]'))){var tid=parseInt(x.getAttribute('data-tg'),10),c=CM.map[tid];if(!c)return;c.open=!c.open;if(c.open&&!c.kloaded)loadKids(tid,false);else renderComments();return}
    if((x=up('[data-sm]'))){loadKids(parseInt(x.getAttribute('data-sm'),10),true);return}
    if((x=up('[data-rm]'))){var box=x.parentNode,open=box.classList.toggle('expanded');x.textContent=open?'Show less':'...Read more';return}
  });
  $('hlCmSortBtn').addEventListener('click',function(e){e.stopPropagation();closeMenu();$('hlCmSortMenu').classList.toggle('show')});
  Array.prototype.forEach.call($('hlCmSortMenu').querySelectorAll('button'),function(b){
    b.addEventListener('click',function(e){
      e.stopPropagation();var s=this.getAttribute('data-sort');$('hlCmSortMenu').classList.remove('show');if(s===CM.sort)return;
      CM.sort=s;$('hlCmSortLbl').textContent=SORT_LABELS[s]||'Top';
      Array.prototype.forEach.call($('hlCmSortMenu').querySelectorAll('button'),function(x){x.classList.toggle('active',x===b)});
      CM.seq++;CM.loading=false;loadComments(true);
    });
  });
  document.addEventListener('click',function(e){
    if(!isOpen)return;
    if(!e.target.closest('.hl-cm-sort'))$('hlCmSortMenu').classList.remove('show');
    if(!e.target.closest('#hlCmMenu,.hl-ci-more'))closeMenu();
  });
  $('hlScroll').addEventListener('scroll',closeMenu,{passive:true});
  if('IntersectionObserver' in window){
    new IntersectionObserver(function(es){if(es[0].isIntersecting)loadComments(false)},{root:$('hlScroll'),rootMargin:'250px 0px'}).observe($('hlCmSent'));
  }

  /* share + menu sheet */
  function link(){return location.origin+location.pathname+'?u='+H[hi].id+'&d='+curM().id}
  window.hlShare=function(){hlCloseSheet();if(navigator.share){navigator.share({title:'Dayleez',url:link()}).catch(function(){})}else hlCopy()};
  window.hlCopy=function(){
    hlCloseSheet();var u=link();
    if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(u).then(function(){vtoast('Link copied')}).catch(function(){vtoast('Could not copy')})}
    else vtoast('Could not copy');
  };
  window.hlOpenSheet=function(){
    var h=cur(),m=curM(),html='';
    if(h.isYou){
      html+='<div class="hl-sheet-item" onclick="hlViewers()"><i class="ri-eye-line"></i>Viewed by '+fmtNum(m.views)+'</div>';
      html+='<div class="hl-sheet-item" onclick="hlShare()"><i class="ri-share-forward-line"></i>Share</div>';
      html+='<div class="hl-sheet-item" onclick="hlCopy()"><i class="ri-file-copy-line"></i>Copy Link</div>';
      html+='<div class="hl-sheet-item danger" onclick="hlDelete()"><i class="ri-delete-bin-line" style="color:#ff4c4c;"></i>Delete this Dayleez</div>';
    }else{
      html+='<div class="hl-sheet-item" onclick="hlToggleTrack()"><i class="'+(h.following?'ri-user-unfollow-line':'ri-user-add-line')+'"></i>'+(h.following?'Untrack':'Track')+'</div>';
      html+='<div class="hl-sheet-item" onclick="hlShare()"><i class="ri-share-forward-line"></i>Share</div>';
      html+='<div class="hl-sheet-item" onclick="hlCopy()"><i class="ri-file-copy-line"></i>Copy Link</div>';
      html+='<div class="hl-sheet-item danger" onclick="hlReport()"><i class="ri-flag-line" style="color:#ff4c4c;"></i>Report</div>';
    }
    $('hlSheetList').innerHTML=html;$('hlSheetOv').classList.add('show');$('hlSheet').classList.add('show');syncVideo();
  };
  window.hlCloseSheet=function(){$('hlSheetOv').classList.remove('show');$('hlSheet').classList.remove('show');if(isOpen)syncVideo()};
  window.hlViewers=function(){
    var m=curM();$('hlSheetList').innerHTML='<div class="hl-sheet-item">Loading…</div>';
    api('viewers',{story_id:m.id}).then(function(d){
      if(!d||!d.success||!d.viewers.length){$('hlSheetList').innerHTML='<div class="hl-sheet-item">No views yet</div>';return}
      $('hlSheetList').innerHTML=d.viewers.map(function(v){return '<a class="hl-sheet-item" href="'+esc(DL_USER_URL+v.id)+'"><img src="'+esc(v.pic)+'" alt="" onerror="hlImgFail(this)">'+esc(v.name)+'</a>'}).join('');
    });
  };
  window.hlDelete=function(){
    if(!confirm('Delete this Dayleez? This cannot be undone.'))return;
    var m=curM(),h=cur();
    api('del',{story_id:m.id}).then(function(d){
      if(!d||!d.success){vtoast('Could not delete');return}
      changed=true;hlCloseSheet();h.media.splice(mi,1);cmFor=0;
      if(!h.media.length){H.splice(hi,1);if(!H.length){closeNow();return}hi=Math.min(hi,H.length-1);mi=0}
      else mi=Math.min(mi,h.media.length-1);
      render();vtoast('Deleted');
    });
  };
  window.hlToggleTrack=function(){
    var h=cur();
    api('follow',{user_id:h.id}).then(function(d){
      if(d&&d.success){H.forEach(function(x){if(x.id===h.id)x.following=d.following});changed=true;vtoast(d.following?'Now tracking':'Untracked')}else vtoast('Something went wrong');
      hlCloseSheet();
    });
  };
  window.hlReport=function(){
    var id=curM().id;hlCloseSheet();
    if(typeof openReportModal==='function')openReportModal({contentId:id,contentType:'dayleez'});else vtoast('Report unavailable');
  };

  /* swipe = the cube follows your finger */
  function gotoPerson(dir){if(!H||flipping||$('hlSheet').classList.contains('show'))return;var idx=hi+dir;if(idx<0||idx>=H.length)return;autoFlip(dir,dir>0?firstUnseen(idx):0)}
  (function(){
    var st=$('hlStage'),sx=0,sy=0,pid=null,tracking=false,dragOn=false,dragDir=0,t0=0,lastWheel=0,suppress=false;
    st.addEventListener('pointerdown',function(e){
      if(!H||flipping||$('hlSheet').classList.contains('show'))return;
      if(e.pointerType==='mouse'&&e.button!==0)return;
      if(e.target.closest('.hl-bottom')||e.target.closest('#hlSheet')||e.target.closest('.hl-icon-btn')||e.target.closest('.hl-av-link')||e.target.closest('#hlMediaPanel'))return;
      tracking=true;dragOn=false;pid=e.pointerId;sx=e.clientX;sy=e.clientY;t0=Date.now();
    });
    st.addEventListener('pointermove',function(e){
      if(!tracking||e.pointerId!==pid)return;var dx=e.clientX-sx,dy=e.clientY-sy;
      if(!dragOn){
        if(Math.abs(dx)<12||Math.abs(dx)<Math.abs(dy)*1.3){if(Math.abs(dy)>26)tracking=false;return}
        dragDir=dx<0?1:-1;var idx=hi+dragDir;
        if(idx<0||idx>=H.length)dragOn='edge';else{flipBegin(dragDir,idx,dragDir>0?firstUnseen(idx):0);dragOn=true}
        try{st.setPointerCapture(pid)}catch(_){}
      }
      if(dragOn===true)flipMove(dx);
    });
    function endPtr(e){
      if(!tracking||e.pointerId!==pid)return;tracking=false;if(!dragOn)return;
      var mode=dragOn;dragOn=false;suppress=true;setTimeout(function(){suppress=false},80);
      var dx=e.clientX-sx;
      if(mode==='edge'){if(e.type==='pointerup'&&Math.abs(dx)>80)closeNow();return}
      var W=flip?flip.W:st.clientWidth,v=Math.abs(dx)/Math.max(1,Date.now()-t0),sameWay=(dx<0?1:-1)===dragDir;
      flipEnd(e.type==='pointerup'&&sameWay&&(Math.abs(dx)>W*0.28||(v>0.5&&Math.abs(dx)>40)));
    }
    st.addEventListener('pointerup',endPtr);st.addEventListener('pointercancel',endPtr);
    st.addEventListener('click',function(e){if(suppress){e.stopPropagation();e.preventDefault();suppress=false}},true);
    st.addEventListener('wheel',function(e){
      if(e.target.closest&&e.target.closest('#hlMediaPanel'))return;
      if(Math.abs(e.deltaX)<30||Math.abs(e.deltaX)<Math.abs(e.deltaY))return;
      var now=Date.now();if(now-lastWheel<800)return;lastWheel=now;gotoPerson(e.deltaX>0?1:-1);
    },{passive:true});
  })();

  /* keyboard */
  document.addEventListener('keydown',function(e){
    if(!isOpen)return;var tag=((document.activeElement||{}).tagName||'');
    if(e.key==='Escape'){
      e.stopPropagation();
      if($('hlCmMenu').classList.contains('show'))closeMenu();
      else if($('hlSheet').classList.contains('show'))hlCloseSheet();
      else if(mediaPanelOpen())hlMediaClose();
      else closeNow();
      return;
    }
    if(tag==='INPUT'||tag==='TEXTAREA')return;
    if(e.key==='ArrowRight')hlNext();if(e.key==='ArrowLeft')hlPrev();
  },true);
})();
</script>