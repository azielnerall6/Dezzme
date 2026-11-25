<?php $DZ_PAGE='dezztext'; $DZ_TEXT_ONLY=true;  require_once 'shared_core.php'; ?>
<?php include 'timeout.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Dezztext | Text feed</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Bebas+Neue&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
/* post collaboration: ONE poster-sized box, pictures shrink as people join (2 = diagonal, 3 = trio) — same as the Zeems modal */
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
</style>
<style>
/* verified badge (black / white with the theme) in "Liked by …" and in the likes / reposts sheet */
.ipv-likes-text .dz-vb,.ipv-liker-name .dz-vb{width:.95em;height:.95em;margin-left:3px;vertical-align:-.12em;flex:none;color:currentColor}
.ipv-liker-name{display:inline-flex;align-items:center}
</style>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function(){
        navigator.serviceWorker.register('/Peura/service-worker.js').catch(function(e){ console.log('SW error:', e); });
    });
}
</script>
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>
<link rel="stylesheet" href="theme_css.php?v=<?= @filemtime(__DIR__.'/theme_css.php') ?>"> <!-- shared theme: colours, captions, feed (home.php + text_feed.php) -->
<style>
/* ═══════════ TEXT FEED ONLY — top bar (pencil icon) ═══════════ */
.dt-edit-svg{display:block}
.hp-topbar .hft-plus{font-size:calc(clamp(1.3rem,3.6vw + 7px,1.65rem) * var(--ccm-s));margin-left:-2px}
/* phones/tablets: the Tabler edit icon has more empty space inside its box than the other top-bar icons, so it is drawn 22% larger to look the same size */
@media(max-width:768px){.hp-topbar .hft-plus{font-size:calc(clamp(1.15rem,3.5vw + 6.8px,1.5rem) * var(--ccm-s) * 1.22)}}

/* ═══════════ WRITE A TEXT — modal built like the upload page's sheets ═══════════ */
.wsh{--accent:#FF2D2D;--accent-l:#ff7d7d;--accent-dim:rgba(255,45,45,.16);--btn:#dc3838;--btn-h:#c92f2f;
  --card:rgba(255,255,255,.05);--card2:rgba(255,255,255,.08);--line:rgba(255,255,255,.09);--line2:rgba(255,255,255,.14);
  --sub:rgba(255,255,255,.62);--mut:rgba(255,255,255,.45);--act-h:clamp(25px,7.2vw,30px);--act-r:5px;
  position:fixed;inset:0;z-index:100000;display:flex;flex-direction:column;color:#fff;font-size:.8rem;
  background:linear-gradient(-45deg,var(--bg-start),var(--bg-end),var(--bg-end));background-attachment:fixed;
  opacity:0;visibility:hidden;pointer-events:none;transition:opacity .22s ease,visibility 0s linear .22s;box-shadow:0 0 0 200vmax rgba(1,2,24,.62)}
.wsh.open{opacity:1;visibility:visible;pointer-events:all;transition:opacity .22s ease}
.wsh#w-tag{z-index:100010}
@media(min-width:769px){.wsh{left:var(--sidebar-w);border-left:1px solid rgba(255,255,255,.09);border-right:1px solid rgba(255,255,255,.09)}}
@media(min-width:801px){.wsh{right:auto;width:var(--lane-w);left:calc(var(--sidebar-w) + (100% - var(--sidebar-w) - var(--lane-w))/2)}}
@media(min-width:1100px){.wsh{left:calc(var(--sidebar-w) + 22px)}}
.wsh-head{flex-shrink:0}
.wsh-in{max-width:var(--lane-w);margin:0 auto;display:flex;align-items:center;padding:max(8px,env(safe-area-inset-top)) 10px 8px;height:54px}
.wsh-title{flex:1;text-align:center;font-size:.92rem;font-weight:700;margin-right:38px}
.w-close{width:34px;height:34px;background:none;border:none;color:#fff;display:flex;align-items:center;justify-content:center;opacity:1;transition:.2s;padding:4px}
.w-close:hover{color:var(--accent)}
.w-close svg{width:22px;height:22px;display:block}
.wsh-body{flex:1;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:rgba(255,45,45,.35) transparent}
.wsh-pad{max-width:var(--lane-w);margin:0 auto;padding:12px clamp(9px,3vw,16px) 24px}
.w-preview{position:relative;width:min(100%,250px);margin:6px auto 0;aspect-ratio:4/5;border-radius:10px;overflow:hidden;background:var(--card);border:1px solid var(--line);display:flex;align-items:center;justify-content:center}
.w-prev-in{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:url('uploads/Text.jpg') center/cover;padding:20px;text-align:center;font-size:15px;font-weight:600;color:#fff;text-shadow:0 2px 8px rgba(0,0,0,.8);word-break:break-word;overflow:hidden}
.w-prev-label{position:absolute;bottom:8px;left:8px;background:rgba(0,0,0,.65);backdrop-filter:blur(8px);border-radius:5px;padding:3px 8px;font-size:8.5px;font-weight:700;color:rgba(255,255,255,.75);letter-spacing:.6px;text-transform:uppercase}
.w-prev-ph{color:var(--mut);font-size:.7rem;text-align:center;padding:18px}
.w-prev-ph i{font-size:28px;display:block;margin-bottom:6px;color:rgba(255,45,45,.35)}
.w-caption{width:100%;min-height:88px;padding:16px 0 4px;color:#fff;font-size:.82rem;line-height:1.55;outline:none;word-break:break-word}
.w-caption:empty:before{content:"Add a caption… (don't forget a #hashtag)";color:var(--mut)}
.w-cnt{font-size:.66rem;color:var(--mut);text-align:right;margin-bottom:4px}
.w-chips{display:flex;gap:7px;overflow-x:auto;padding:6px 0 14px;scrollbar-width:none}
.w-chips::-webkit-scrollbar{display:none}
.w-chip{flex-shrink:0;display:flex;align-items:center;gap:6px;height:var(--act-h);padding:0 12px;border-radius:var(--act-r);background:rgba(255,255,255,.1);border:none;color:#fff;font-size:.79rem;font-weight:600;transition:.2s}
.w-chip:hover{background:rgba(255,255,255,.2)}
.w-chip i{font-size:14px;color:var(--accent)}
.w-rows{border-top:1px solid var(--line)}
.w-row{display:flex;align-items:center;gap:12px;padding:12px 0;cursor:pointer;color:#fff}
.w-row+.w-row{border-top:1px solid rgba(255,255,255,.04)}
.w-row>i:first-child{font-size:20px;width:24px;text-align:center;flex-shrink:0}
.w-lbl{flex:1;min-width:0;font-size:.8rem}
.w-lbl small{display:block;font-size:.66rem;color:var(--sub);line-height:1.5;margin-top:1px}
.w-val{max-width:140px;font-size:.72rem;color:var(--sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.w-chev{font-size:19px;color:var(--mut);flex-shrink:0}
.w-switch{position:relative;display:inline-block;width:40px;height:23px;flex-shrink:0}
.w-switch input{opacity:0;width:0;height:0}
.w-slider{position:absolute;cursor:pointer;inset:0;background:rgba(255,255,255,.18);border-radius:34px;transition:.25s}
.w-slider::before{position:absolute;content:"";height:17px;width:17px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s}
.w-switch input:checked+.w-slider{background:var(--btn)}
.w-switch input:checked+.w-slider::before{transform:translateX(17px);background:#000}
.w-sub{padding:0 0 12px 36px}
.w-input{width:100%;height:var(--act-h);padding:0 11px;border-radius:var(--act-r);border:1px solid var(--line2);background:var(--card);color:#fff;font-size:.76rem;transition:.2s;-webkit-appearance:none;appearance:none}
.w-input:focus{border-color:rgba(255,45,45,.6);box-shadow:0 0 0 3px rgba(255,45,45,.12)}
.w-input::placeholder{color:var(--mut)}
.w-hint{font-size:.66rem;color:var(--mut);margin-top:6px;line-height:1.5}
.w-sec-h{font-size:.64rem;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--sub);margin-bottom:9px}
.w-sec{margin-top:18px;padding-top:14px;border-top:1px solid var(--line)}
.w-note{color:var(--sub);font-size:.68rem;line-height:1.65}
.w-note p+p{margin-top:8px}
.w-actionbar{flex-shrink:0;padding:8px clamp(9px,3vw,16px) max(10px,env(safe-area-inset-bottom));background:linear-gradient(-45deg,var(--bg-start),var(--bg-end),var(--bg-end))}
.w-actionbar>div{max-width:var(--lane-w);margin:0 auto}
.w-btn{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;height:var(--act-h);padding:0 14px;border-radius:var(--act-r);border:none;background:var(--btn);color:#000;font-size:.79rem;font-weight:700;line-height:1;white-space:nowrap;transition:background .2s ease,transform .15s ease}
.w-btn:hover{background:var(--btn-h)}
.w-btn:active{transform:scale(.98)}
.w-btn:disabled{opacity:.5;cursor:not-allowed}
.w-tg-chips{display:flex;flex-wrap:wrap;gap:6px}
.w-tg-chip{display:inline-flex;align-items:center;gap:6px;height:var(--act-h);padding:0 4px;border-radius:99px;background:var(--accent-dim);border:1px solid rgba(255,45,45,.3);color:#fff;font-size:.72rem;font-weight:600;max-width:100%}
.w-tg-chip img{width:calc(var(--act-h) - 8px);height:calc(var(--act-h) - 8px);border-radius:50%;object-fit:cover;background:#15183a;flex-shrink:0}
.w-tg-chip span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px}
.w-tg-chip button{width:20px;height:20px;border-radius:50%;border:none;background:rgba(255,255,255,.14);color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.w-tg-chip button:hover{background:#dc3838}
.w-tg-list{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.w-tg-row{display:flex;align-items:center;gap:10px;width:100%;padding:8px 10px;border-radius:8px;background:var(--card);border:1px solid var(--line);color:#fff;text-align:left;transition:.2s}
.w-tg-row:hover:not(:disabled){border-color:rgba(255,45,45,.5);background:var(--accent-dim)}
.w-tg-row:disabled{opacity:.6;cursor:not-allowed}
.w-tg-av{width:36px;height:36px;border-radius:50%;object-fit:cover;background:#15183a;flex-shrink:0}
.w-tg-inf{flex:1;min-width:0}
.w-tg-inf strong{display:block;font-size:.74rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.w-tg-inf span{display:block;font-size:.64rem;color:var(--mut);margin-top:1px;line-height:1.4}
.w-tg-inf span.no{color:#f87171}
.w-tg-end{font-size:.66rem;font-weight:700;color:var(--accent-l);flex-shrink:0}
.w-empty{padding:16px 0;text-align:center;color:var(--mut);font-size:.72rem}
.w-empty i{font-size:24px;display:block;margin-bottom:6px;color:rgba(255,45,45,.35)}

/* RESPONSIVE (phones / tablets, below 769px): everything in the write modal is 10% bigger than the upload-page sizes. Desktop is unchanged. */
@media(max-width:768px){
  .wsh{--act-h:clamp(27.5px,7.9vw,33px);font-size:.88rem}
  .wsh-in{height:59px}
  .wsh-title{font-size:1.01rem;margin-right:42px}
  .w-close{width:37px;height:37px}
  .w-close svg{width:24px;height:24px}
  .wsh-pad{padding:13px clamp(10px,3.3vw,17.6px) 26px}
  .w-actionbar{padding:9px clamp(10px,3.3vw,17.6px) max(11px,env(safe-area-inset-bottom))}
  .w-preview{width:min(100%,275px)}
  .w-prev-in{font-size:16.5px;padding:22px}
  .w-prev-label{font-size:9.4px;padding:3px 9px;bottom:9px;left:9px}
  .w-caption{font-size:.9rem;min-height:97px;padding:17.6px 0 4px}
  .w-cnt{font-size:.73rem}
  .w-chip{font-size:.87rem;padding:0 13px;gap:6.6px}
  .w-chip i{font-size:15.4px}
  .w-row{gap:13px;padding:13px 0}
  .w-row>i:first-child{font-size:22px;width:26px}
  .w-lbl{font-size:.88rem}
  .w-lbl small{font-size:.73rem}
  .w-val{font-size:.79rem;max-width:154px}
  .w-chev{font-size:21px}
  .w-switch{width:44px;height:25px}
  .w-slider::before{height:19px;width:19px;left:3px;bottom:3px}
  .w-switch input:checked+.w-slider::before{transform:translateX(19px)}
  .w-sub{padding-left:40px;padding-bottom:13px}
  .w-input{font-size:.84rem}
  .w-hint{font-size:.73rem}
  .w-note{font-size:.75rem}
  .w-sec-h{font-size:.7rem}
  .w-btn{font-size:.87rem;padding:0 15px;gap:6.6px}
  .w-tg-chip{font-size:.79rem}
  .w-tg-chip span{max-width:143px}
  .w-tg-chip button{width:22px;height:22px;font-size:12px}
  .w-tg-row{padding:9px 11px;gap:11px}
  .w-tg-av{width:40px;height:40px}
  .w-tg-inf strong{font-size:.81rem}
  .w-tg-inf span{font-size:.7rem}
  .w-tg-end{font-size:.73rem}
  .w-empty{font-size:.79rem}
  .w-pg-card{max-width:352px;padding:22px 20px}
  .w-pg-ic{width:53px;height:53px;font-size:24px}
  .w-pg-title{font-size:.97rem}
  .w-pg-row{font-size:.75rem}
}
@media(max-width:490px){
  .wsh{font-size:.81rem}
  .wsh-in{height:53px}
  .wsh-title{font-size:.92rem}
  .w-preview{width:min(100%,231px)}
  .w-btn{font-size:.75rem;padding:0 11px;gap:4.4px}
  .w-chip{font-size:.75rem;padding:0 10px;gap:4.4px}
  .w-chip i{font-size:13px}
  .w-row{gap:11px;padding:11px 0}
  .w-row>i:first-child{font-size:20px;width:23px}
  .w-lbl{font-size:.81rem}
  .w-val{font-size:.73rem;max-width:110px}
  .w-switch{width:40px;height:23px}
  .w-slider::before{height:16px;width:16px}
  .w-switch input:checked+.w-slider::before{transform:translateX(18px)}
  .w-sub{padding-left:0}
  .w-input{font-size:.77rem}
}
@media(max-width:390px){.wsh{font-size:.77rem}}
@media(max-width:330px){.w-val{display:none}}
.w-pg{position:fixed;inset:0;z-index:100020;background:rgba(1,2,24,.78);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;visibility:hidden;transition:opacity .2s}
.w-pg.show{opacity:1;visibility:visible}
.w-pg-card{width:100%;max-width:320px;background:#0d1030;border:1px solid rgba(255,255,255,.14);border-radius:12px;padding:20px 18px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.7);color:#fff}
.w-pg-ic{width:48px;height:48px;border-radius:50%;background:rgba(255,45,45,.16);border:1px solid rgba(255,45,45,.3);color:#FF2D2D;display:flex;align-items:center;justify-content:center;font-size:22px;margin:0 auto 12px}
.w-pg-ic.ok{background:rgba(77,222,138,.13);border-color:rgba(77,222,138,.35);color:#4dde8a}
.w-pg-ic.bad{background:rgba(220,56,56,.13);border-color:rgba(220,56,56,.35);color:#f87171}
.w-pg-title{font-size:.88rem;font-weight:600}
.w-pg-bar{height:5px;border-radius:4px;background:rgba(255,255,255,.09);overflow:hidden;margin:14px 0 8px}
.w-pg-bar span{display:block;height:100%;width:0;background:#dc3838;border-radius:4px;transition:width .15s ease}
.w-pg-row{display:flex;justify-content:space-between;font-size:.68rem;color:rgba(255,255,255,.62);gap:10px}
.w-pg-row b{color:#fff}
.w-pg-close{margin-top:14px;display:none}
</style>
<?php dz_hashtags_styles(); /* hashtags.php */ ?>
<?php dz_offline_styles(); /* offline.php */ ?>
<style>
/* top bar: the Dezztext word sits at the right */
.hp-topbar .hft-title.hft-title-r{ position:absolute !important; right:16px !important; left:auto !important; top:50% !important; transform:translateY(-50%) !important; margin:0 !important; width:auto !important; text-align:right !important; }
/* icons drawn as even-line SVGs (same as Zeems + search): rail buttons in the inline viewer */
.sp-rp{ display:inline-block; width:1.166em; height:1.166em; margin:-.083em; line-height:0; font-style:normal; vertical-align:middle; }
.sp-rp svg{ display:block; width:100%; height:100%; overflow:visible; }
/* comments turned off for a post: the same "chat-off" icon as the Upload page instead of the speech bubble, and no number (the button still opens the sheet) */
.comment-btn .cm-off-ic{ display:none; }
.comment-btn.cm-off svg{ display:none !important; }
.comment-btn.cm-off .cm-off-ic{ display:block; width:100%; height:100%; font-size:1.166em; line-height:1; text-align:center; font-style:normal; -webkit-text-stroke:0; }
.comment-count[data-off="1"]{ display:none !important; }
.ipv-action-btn.active .sp-fillable svg{ fill:currentColor; }
.sp-rring{ stroke:#000; }
:root[data-theme="light"] .sp-rring{ stroke:#fff; }
.ipv-action-btn .ipv-ic-on, .ipv-action-btn .ipv-ic-off{ display:none; }
</style>
<style>
/* header word: same look as the "Dezzme" word on the login page (Bebas Neue, brand red, wide letter-spacing) */
.hp-topbar .hft-title,html[data-theme="light"] .hp-topbar .hft-title{font-family:'Bebas Neue',sans-serif !important;font-weight:400 !important;color:#E03C3C !important;font-size:clamp(24px,6.5vw,32px) !important;letter-spacing:clamp(5px,1.8vw,8px) !important;line-height:1 !important;padding-left:clamp(5px,1.8vw,8px) !important;text-transform:none}
@media (max-width:768px){.hp-topbar .hft-title,html[data-theme="light"] .hp-topbar .hft-title{font-size:clamp(24px,6.8vw,31px) !important}}
.hp-topbar .hft-title.hft-title-r{ padding-left:0 !important; margin-right:calc(-1 * clamp(5px,1.8vw,8px)) !important; }
/* multi-photo posts: same fit as the immersive viewer — whole picture inside the box (black bars), only near-box-shaped pictures fill it */
/* ad button: a full-width bar under the picture (same as home) */
.ipv-post .ipv-cta-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;box-sizing:border-box;width:100%;min-height:44px;padding:0 16px;background:var(--cta-color,#FF4C4C);color:var(--cta-ink,#fff);text-decoration:none;font-weight:600;font-size:14px;line-height:1.2;-webkit-tap-highlight-color:transparent;transition:filter .15s ease}
.ipv-post .ipv-cta-bar:active{filter:brightness(.92)}
.ipv-post .ipv-cta-bar-text{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ipv-post .ipv-cta-bar svg{flex:none}
#ipvList .ipv-media-wrap.ipv-multi .ipv-media-item img,#ipvList .ipv-media-wrap.ipv-multi .ipv-media-item video{object-fit:contain !important;background:#000}
#ipvList .ipv-media-wrap.ipv-multi .ipv-media-item img.ipv-fit-cover,#ipvList .ipv-media-wrap.ipv-multi .ipv-media-item video.ipv-fit-cover{object-fit:cover !important}
/* the picture fills its slide by absolute position (not percentage height) so every browser, iPhone included, centres it the same */
#ipvList .ipv-media-wrap:not(.ipv-textonly) .ipv-media-track{position:absolute;top:0;left:0;right:0;bottom:0;height:auto}
#ipvList .ipv-media-wrap.ipv-multi .ipv-media-item{align-self:stretch;height:auto;position:relative}
#ipvList .ipv-media-wrap.ipv-multi .ipv-media-item img,#ipvList .ipv-media-wrap.ipv-multi .ipv-media-item video{position:absolute;top:0;left:0;width:100%;height:100%;object-position:center center}
</style>
<!-- Dezzme verified flower + NEW word: same design/placement as the immersive modal -->
<style id="dz-vbnew-css">
.ipv-username-row > .dz-vb{ width:var(--dzvb,13px); height:var(--dzvb,13px); margin-left:-2px; color:var(--text,#F0F2FF); position:relative; top:calc(var(--dzvb,13px) * -0.11); }
.ipv-post.ipv-long-header .ipv-username-row > .dz-vb{ color:#fff; filter:drop-shadow(0 1px 4px rgba(0,0,0,.5)); }
.dz-vb{ flex-shrink:0; display:inline-block; vertical-align:-.15em; }
.ipv-actions{ position:relative; }
.dz-newtag{ position:absolute; right:calc(clamp(6px, 5.02px + 0.59vw, 9px) * var(--ccm-s, 1)); top:50%; transform:translateY(-50%); display:flex; align-items:center; font-size:calc(clamp(10.5px, 10.01px + 0.3vw, 12.5px) * var(--ccm-s, 1)); font-weight:800; color:var(--red,#FF2D2D); letter-spacing:.03em; text-transform:uppercase; pointer-events:none; user-select:none; }
.ipv-ad-tag.dz-flip{ display:grid; perspective:140px; }
.dz-flip .dz-fa, .dz-flip .dz-fb{ grid-area:1/1; justify-self:end; backface-visibility:hidden; -webkit-backface-visibility:hidden; }
.dz-flip .dz-fa{ color:#FF3B30; animation:dzFlipA 16s infinite; }
.dz-flip .dz-fb{ color:var(--red,#FF2D2D); animation:dzFlipB 16s infinite; }
@keyframes dzFlipA{ 0%,47%{ transform:rotateX(0) } 50%,97%{ transform:rotateX(-180deg) } 100%{ transform:rotateX(-360deg) } }
@keyframes dzFlipB{ 0%,47%{ transform:rotateX(180deg) } 50%,97%{ transform:rotateX(0) } 100%{ transform:rotateX(-180deg) } }
</style>
<script>
window.DZ_VB='<svg class="dz-vb" viewBox="0 0 256 256" aria-label="Verified" fill="currentColor"><path fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></svg>';
window.dzAdTag=function(p,isNew){
  if(p.isAd) return isNew ? '<div class="ipv-ad-tag dz-flip"><span class="dz-fa">Ad</span><span class="dz-fb">New</span></div>' : '<div class="ipv-ad-tag">Ad</div>';
  return isNew ? '<div class="dz-newtag">New</div>' : '';
};
(function(){
  var raf=0;
  function sz(){ raf=0; try{ var u=document.querySelector('.ipv-username'); if(u){ var f=parseFloat(getComputedStyle(u).fontSize); if(f>0) document.documentElement.style.setProperty('--dzvb', f+'px'); } }catch(e){} }
  function q(){ if(!raf) raf=requestAnimationFrame(sz); }
  document.addEventListener('DOMContentLoaded',function(){ q(); try{ new MutationObserver(q).observe(document.body,{childList:true,subtree:true}); }catch(e){} window.addEventListener('resize',q); });
})();
</script>
<style id="dzPartnerFlip">
/* "Paid partnership" flips under the username with the nickname / audio (it no longer sits on the video) */
.ipv-partner-line{display:flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#ff4c4c;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ipv-partner-line i{font-size:12px;flex-shrink:0;}
.ipv-partner-line b{color:#fff;font-weight:700;overflow:hidden;text-overflow:ellipsis;}
</style>
</head>
<body>
<?php dz_offline_ui(); /* offline.php: banner + full-screen "No internet" state, must come before the page scripts */ ?>

<?php include 'nav.php'; /* sidebar (desktop) / bottom bar (mobile) */ ?>

<div class="hp-shell">
<div class="hp-col">

    <header class="hp-topbar" id="homeFixedTopbar">
        <div class="hft-left">
            <button type="button" class="hft-btn hft-plus" onclick="W.open()" aria-label="Write a text"><svg id="dtEditIcon" class="dt-edit-svg" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1"/><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z"/><path d="M16 5l3 3"/></svg></button>
        </div>
        <div class="hft-title hft-title-r">Dezztext</div>
    </header>

    

<?php
/* For You / Tracking switch — same look as the Chats / Group Chats switch in messages.php. For You = the normal ranked feed, Tracking = only people you track. */
$__ft_on  = (($filter ?? 'trending') === 'following') ? 'tracking' : 'foryou';
$__ft_q   = $_GET; unset($__ft_q['page'], $__ft_q['feed_json'], $__ft_q['seed'], $__ft_q['limit']);
$__ft_path = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$__ft_url = function($f) use ($__ft_q, $__ft_path){ $q = $__ft_q; unset($q['filter']); if($f === 'following') $q['filter'] = 'following'; return $__ft_path . ($q ? '?' . http_build_query($q) : ''); };
?>
<style>
.feed-tabs{ display:flex; gap:2.2px; margin:8px clamp(6px,1.8vw,10px) 4px; padding:2.2px; border:1px solid rgba(var(--fg,255,255,255),.09); border-radius:5.5px; background:rgba(var(--fg,255,255,255),.05); box-sizing:border-box; }
.feed-tab{ flex:1; height:28.6px; border:none; border-radius:4.4px; background:transparent; color:rgba(var(--fg,255,255,255),.62); font:600 11.6px/1 'Poppins',sans-serif; white-space:nowrap; padding:0 6px; display:flex; align-items:center; justify-content:center; text-decoration:none; transition:background .2s,color .2s; -webkit-tap-highlight-color:transparent; }
.feed-tab:hover{ color:var(--ui-text,#fff); }
.feed-tab.active{ background:var(--red,#FF2D2D); color:#000; }
</style>
<div class="feed-tabs" id="feedTabs">
    <a class="feed-tab<?= $__ft_on === 'foryou' ? ' active' : '' ?>" href="<?= htmlspecialchars($__ft_url('trending')) ?>">For You</a>
    <a class="feed-tab<?= $__ft_on === 'tracking' ? ' active' : '' ?>" href="<?= htmlspecialchars($__ft_url('following')) ?>">Tracking</a>
</div>


    <?php dz_hashtag_bar($tag, $filter, $ht); /* hashtags.php */ ?>

    <div class="ipv-list" id="ipvList"></div>

    <audio id="ipvAudioPlayer" loop muted style="display:none"></audio>

</div>

<!-- RIGHT-HAND COLUMN — laptop / normal desktop view only (≥1100px) -->
<aside class="hp-right-rail" aria-label="Home highlights">

    <section class="pf-card" id="rsAdsSection" data-k="ads">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-megaphone-line pf-bar-ic"></i><span class="pf-bar-t">Sponsored</span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
            <div class="rs-ads-list" id="rsAdsList">
                <div class="rs-ad-skel"><div class="rs-ad-skel-thumb"></div><div class="rs-ad-skel-lines"><div class="rs-ad-skel-line w1"></div><div class="rs-ad-skel-line w2"></div><div class="rs-ad-skel-line w3"></div></div></div>
                <div class="rs-ad-skel"><div class="rs-ad-skel-thumb"></div><div class="rs-ad-skel-lines"><div class="rs-ad-skel-line w1"></div><div class="rs-ad-skel-line w2"></div><div class="rs-ad-skel-line w3"></div></div></div>
                <div class="rs-ad-skel"><div class="rs-ad-skel-thumb"></div><div class="rs-ad-skel-lines"><div class="rs-ad-skel-line w1"></div><div class="rs-ad-skel-line w2"></div><div class="rs-ad-skel-line w3"></div></div></div>
            </div>
        </div></div></div>
    </section>

    <?php
    $rs_tags = [];
    foreach($sorted_tags as $t){ if($t !== 'All') $rs_tags[] = $t; }
    $rs_tags = array_slice($rs_tags, 0, 20);
    if(!empty($rs_tags)):
    ?>
    <section class="pf-card" data-k="tags">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-hashtag pf-bar-ic"></i><span class="pf-bar-t">Top hashtags</span><span class="pf-bar-c"><?= count($rs_tags) ?></span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
            <div class="pf-chips">
                <?php foreach($rs_tags as $t): ?>
                <a class="pf-chip<?= ($tag === $t) ? ' on' : '' ?>" href="<?= htmlspecialchars(hpUrl($filter, $t)) ?>">#<?= htmlspecialchars($t) ?></a>
                <?php endforeach; ?>
            </div>
        </div></div></div>
    </section>
    <?php endif; ?>

    <section class="pf-card" id="rsPeopleCard" data-k="people" style="display:none;">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-user-add-line pf-bar-ic"></i><span class="pf-bar-t">People you may like</span><span class="pf-bar-c" id="rsPeopleCount"></span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad" id="rsPeopleBody"></div></div></div>
    </section>

    <section class="pf-card" id="rsMpCard" data-k="memepedia" style="display:none;">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-book-open-line pf-bar-ic"></i><span class="pf-bar-t">Memepedia featured</span><span class="pf-bar-c" id="rsMpCount"></span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad" id="rsMpBody"></div></div></div>
    </section>

    <section class="pf-card" data-k="about">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><i class="ri-information-line pf-bar-ic"></i><span class="pf-bar-t">About Dezztext</span><i class="ri-arrow-down-s-fill pf-bar-chev"></i></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
            <div class="pf-about">
                <p>Dezztext is the text-only side of Dezzme. Every post here is made of words, with no photos or videos to scroll past, just thoughts, jokes, stories, hot takes and one-liners from the community.</p>
                <p>Like, repost and save the texts that hit home, and Track the writers you enjoy so their latest posts show up at the top of your feed. Tap any text post to open it and read it in full.</p>
                <p>Use hashtags to find the conversations you care about, browse the top hashtags to see what people are writing about right now, and tap the pencil at the top to share something of your own.</p>
            </div>
        </div></div></div>
    </section>

    <div class="rs-divider"></div>
    <div class="rs-links">
        <a href="messages.php" class="rs-link-row"><i class="ri-wechat-line"></i><span>Messages</span></a>
        <a href="profile_longform.php" class="rs-link-row"><i class="ri-vidicon-line"></i><span>Your videos</span></a>
    </div>
</aside>

</div>

<!-- WRITE A TEXT (pencil in the top bar) — same sheet design as the upload page -->
<div class="wsh" id="w-sheet">
    <div class="wsh-head"><div class="wsh-in">
        <button type="button" class="w-close" onclick="W.close()" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
        <div class="wsh-title">Write a text</div>
    </div></div>
    <div class="wsh-body"><div class="wsh-pad">
        <div class="w-preview" id="w-preview"><div class="w-prev-in"></div><span class="w-prev-label">Text post</span></div>

        <div class="w-caption" id="w-caption" contenteditable="true" oninput="W.sync()"></div>
        <div class="w-cnt" id="w-cnt">0/2200</div>

        <div class="w-chips">
            <button type="button" class="w-chip" onclick="W.hash()"><i class="ri-hashtag"></i>Hashtags</button>
            <button type="button" class="w-chip" onclick="W.tagOpen()"><i class="ri-user-add-line"></i>Tag people</button>
        </div>

        <div class="w-rows">
            <div class="w-row" onclick="W.tagOpen()"><i class="ri-user-add-line"></i><span class="w-lbl">Tag people</span><span class="w-val" id="w-tagval">None</span><i class="ri-arrow-right-s-line w-chev"></i></div>
            <div class="w-row" style="cursor:default"><i class="ri-hand-coin-line"></i><span class="w-lbl">Paid partnership</span>
                <label class="w-switch"><input type="checkbox" id="w-partner" onchange="W.partner()"><span class="w-slider"></span></label></div>
            <div class="w-sub" id="w-brand-wrap" style="display:none">
                <input type="text" class="w-input" id="w-brand" maxlength="100" placeholder="Brand name (optional)">
                <div class="w-hint">Tags your post as a paid partnership. Leave the name blank if you'd rather not show it.</div></div>
            <div class="w-row" style="cursor:default"><i class="ri-sparkling-2-line"></i><span class="w-lbl">Add AI label<small>We ask you to label realistic content made with AI.</small></span>
                <label class="w-switch"><input type="checkbox" id="w-ai"><span class="w-slider"></span></label></div>
        </div>

        <div class="w-note w-sec"><p>Dezztext posts are made of words only. Write your text, add at least one #hashtag so people can find it, and press Post Text.</p>
            <p>Tagged people and @mentions follow each person's Tags and mentions settings. If someone doesn't allow it, you'll see a message and nothing is posted.</p></div>
    </div></div>
    <div class="w-actionbar"><div><button type="button" class="w-btn" id="w-post" onclick="W.post()"><i class="ri-send-plane-fill"></i><span>Post Text</span></button></div></div>
</div>

<!-- TAG PEOPLE -->
<div class="wsh" id="w-tag">
    <div class="wsh-head"><div class="wsh-in">
        <button type="button" class="w-close" onclick="W.tagClose()" aria-label="Close"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
        <div class="wsh-title">Tag people</div>
    </div></div>
    <div class="wsh-body"><div class="wsh-pad">
        <div class="w-sec-h">Tagged</div>
        <div class="w-tg-chips" id="w-tagsel"></div>
        <div class="w-sec">
            <input type="text" id="w-tagq" class="w-input" placeholder="Search a username or nickname…" autocomplete="off" oninput="W.tagSearch()">
            <div class="w-tg-list" id="w-tagres"></div>
        </div>
        <div class="w-note w-sec"><p>Tagged people are linked to your post. Anyone who only allows tags from people they track, or from no one, is greyed out and can't be tagged.</p>
            <p>If someone turned on Manually approve tags, the tag stays pending until they accept it. You can tag up to 20 people.</p></div>
    </div></div>
    <div class="w-actionbar"><div><button type="button" class="w-btn" onclick="W.tagClose()">Done</button></div></div>
</div>

<!-- PROGRESS -->
<div class="w-pg" id="w-pg"><div class="w-pg-card">
    <div class="w-pg-ic" id="w-pg-ic"><i class="ri-upload-cloud-2-line" id="w-pg-i"></i></div>
    <div class="w-pg-title" id="w-pg-title">Posting…</div>
    <div class="w-pg-bar"><span id="w-pg-fill"></span></div>
    <div class="w-pg-row"><span id="w-pg-sub">Starting…</span><b id="w-pg-pct">0%</b></div>
    <button type="button" class="w-btn w-pg-close" id="w-pg-close" onclick="W.pgHide()">Close</button>
</div></div>

<!-- likes / reposts bottom sheet (same as the immersive viewer + home page) -->
<div id="ipvLikersOverlay" class="ipv-likers-overlay" onclick="if(event.target===this)closeEngModal()">
    <div class="ipv-likers-panel">
        <div class="ipv-likers-handle"></div>
        <div class="ipv-likers-tabs" id="ipvLikersTabs">
            <button type="button" class="ipv-likers-tab active" data-tab="likes" onclick="switchEngTab(this,'likes')">Likes</button>
            <button type="button" class="ipv-likers-tab" data-tab="reposts" onclick="switchEngTab(this,'reposts')">Reposts</button>
        </div>
        <div class="ipv-likers-list" id="ipvLikersList"></div>
    </div>
</div>

<!-- ═══ FEED DATA — must sit BEFORE the zeems modal include (it reads peuraFeed) ═══ -->
<script>
var peuraFeed = <?= json_encode($_peura_feed_data, $_json_flags) ?>;
window.WT_LOGGED = <?= $user_id ? 1 : 0 ?>;
var currentUserId = <?= (int)$user_id ?>;
var dzMe = <?php
    $__me = ['id'=>(int)$user_id, 'username'=>'', 'profile_pic'=>'uploads/mee.jpeg'];
    try{ $__mq = $conn->prepare('SELECT username, profile_pic FROM users WHERE id = ?'); $__mq->bind_param('i', $user_id); $__mq->execute(); $__mr = $__mq->get_result()->fetch_assoc();
         if($__mr){ $__me['username'] = (string)$__mr['username']; if(!empty($__mr['profile_pic'])) $__me['profile_pic'] = $__mr['profile_pic']; } }catch(Throwable $e){}
    echo json_encode($__me, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>;
window.kebabData = <?= json_encode($_kebab_data, $_json_flags | JSON_FORCE_OBJECT) ?>;
window.CCM_ENDPOINT = 'text_feed.php';   /* the comments sheet sends its like / repost / save / track buttons to this file */
</script>

<?php include 'shared_modals.php'; /* post menu (⋯), Save to Collection sheet, Report modal */ ?>
<?php include 'comment_section.php'; /* comments sheet: opens from the comment button (data-comments="ID") on every post, ads included */ ?>
<?php include 'zeems_modal.php'; /* feed cards now open this modal */ ?>


<!-- ═══ MAIN PAGE SCRIPT ═══ -->
<script>
/* ───────── shared small helpers ───────── */
function showToast(msg){
    var ex = document.getElementById('peura-toast'); if(ex) ex.remove();
    var t = document.createElement('div'); t.id = 'peura-toast'; t.textContent = msg;
    Object.assign(t.style,{position:'fixed',bottom:'90px',left:'50%',transform:'translateX(-50%)',background:'rgba(20,20,25,.95)',border:'1px solid rgba(255,45,45,.3)',color:'#fff',padding:'8px 20px',borderRadius:'50px',fontSize:'12px',zIndex:'99999999',pointerEvents:'none',boxShadow:'0 8px 24px rgba(0,0,0,.6)',transition:'opacity .3s',whiteSpace:'nowrap'});
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.remove(); },300); },2200);
}
function sendAction(action, memeId, callback){
    fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action='+action+'&meme_id='+memeId})
    .then(function(r){ return r.json(); }).then(callback).catch(function(e){ console.error(e); });
}

/* ═══════════ NOT INTERESTED — same as the home + search pages ═══════════
   The post is replaced, right in the feed, by a "Post hidden · Undo" row in the same spot. Tap Undo and the post comes back.
   Nothing is saved until you leave / refresh the page (or the page has been left alone for a minute), so Undo is free.
   The save is action=not_interested, which the server remembers: after a refresh the post is gone and never comes back in your feed. */
(function(){
    var COMMIT_MS = 60000, pending = {};      /* memeId → {rows, ph, timer} */

    function rowsFor(id){
        return Array.prototype.slice.call(document.querySelectorAll('#ipvList .ipv-post[data-id="'+id+'"]'));
    }
    function dropFromFeeds(id){
        try{ if(window.hpFeedRemoved) window.hpFeedRemoved(id); }catch(e){}
        try{
            if(typeof ccmFeed !== 'undefined' && Array.isArray(ccmFeed)){
                for(var j = ccmFeed.length - 1; j >= 0; j--){
                    var it = ccmFeed[j];
                    if(it && it.type === 'post' && it.data && String(it.data.id) === String(id)) ccmFeed.splice(j, 1);
                }
            }
        }catch(e){}
    }
    function send(id, beacon){
        var body = 'action=not_interested&meme_id=' + encodeURIComponent(id);
        try{
            if(beacon && navigator.sendBeacon) navigator.sendBeacon('text_feed.php', new Blob([body], {type:'application/x-www-form-urlencoded'}));
            else fetch('text_feed.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body, keepalive:true, credentials:'same-origin'}).catch(function(){});
        }catch(e){}
    }
    function makePlaceholder(id, isAd){
        var ph = document.createElement('div');
        ph.className = 'hp-nint-ph';
        ph.dataset.ph = id;
        ph.innerHTML = '<div class="hp-nint-body"><b>Post hidden</b><span>'+(isAd ? 'You\u2019ll see fewer Ads like this.' : 'You won\u2019t see this post again.')+'</span></div>'+
            '<button type="button" class="hp-nint-undo" onclick="event.stopPropagation();hpUndoNotInterested('+id+')">Undo</button>';
        return ph;
    }
    /* the hiding is final: save it, drop the Undo button, and remove the post for good */
    function commit(id, beacon){
        var p = pending[id]; if(!p) return;
        clearTimeout(p.timer); delete pending[id];
        send(id, beacon);
        p.rows.forEach(function(r){ if(r.parentNode) r.parentNode.removeChild(r); });
        if(p.ph){
            var u = p.ph.querySelector('.hp-nint-undo'); if(u) u.remove();
            p.ph.classList.add('hp-nint-done');
            var sp = p.ph.querySelector('.hp-nint-body span'); if(sp) sp.textContent = 'Removed from your feed.';
        }
        dropFromFeeds(id);
    }

    window.notInterested = function(memeId, el){
        var id = String(memeId);
        if(pending[id]) return;
        var rows = rowsFor(id);
        if(!rows.length){ send(id, false); dropFromFeeds(id); return; }   /* not in the feed (e.g. opened from the viewer) → just save it */
        var ph = makePlaceholder(id, !!rows[0].querySelector('.ipv-ad-tag'));
        rows[0].parentNode.insertBefore(ph, rows[0]);
        rows.forEach(function(r){
            r.dataset.nint = '1'; r.style.display = 'none';
            r.querySelectorAll('video').forEach(function(v){ try{ v.pause(); }catch(e){} });
        });
        pending[id] = { rows: rows, ph: ph, timer: setTimeout(function(){ commit(id, false); }, COMMIT_MS) };
    };
    window.hpUndoNotInterested = function(id){
        id = String(id);
        var p = pending[id]; if(!p) return;
        clearTimeout(p.timer); delete pending[id];
        if(p.ph && p.ph.parentNode) p.ph.parentNode.removeChild(p.ph);
        p.rows.forEach(function(r){ delete r.dataset.nint; r.style.display = ''; });
        if(window.hpResumeFocused) window.hpResumeFocused();
    };
    /* leaving, refreshing or switching away saves it, so it does not come back */
    function flushAll(){ Object.keys(pending).forEach(function(id){ commit(id, true); }); }
    window.addEventListener('pagehide', flushAll);
    document.addEventListener('visibilitychange', function(){ if(document.hidden) flushAll(); });
})();

function blockUser(userId){
    if(!confirm("Block this user? You won't see their content anymore.")) return;
    fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=block_user&target_id='+userId})
    .then(function(r){ return r.json(); }).then(function(d){
        if(d.status !== 'ok') return;
        document.querySelectorAll('.ipv-post[data-user="'+userId+'"]').forEach(function(p){ p.style.transition='opacity .4s'; p.style.opacity='0'; setTimeout(function(){ p.remove(); },400); });
    });
}
function shareMeme(memeId){
    var url = window.location.origin + '/Peura/index.php?meme=' + memeId;
    if(navigator.share) navigator.share({title:'Check this on Dezzme',url:url});
    else { navigator.clipboard.writeText(url); showToast('Link copied!'); }
}

/* ───────── likes / reposts sheet (same design + behaviour as the immersive viewer + home page) ───────── */
var hpLikers={likes:[],reposts:[]}, hpLikersTab='likes', hpLikersTok=0;
function openEngModal(memeId){
    var p=(window.peuraFeed||[]).find(function(x){ return String(x.id)===String(memeId); });
    var proof=(p&&Array.isArray(p.socialProof))?p.socialProof:[];
    hpLikers.likes   = proof.filter(function(sp){ return sp.action_type==='like'; });
    hpLikers.reposts = proof.filter(function(sp){ return sp.action_type==='repost'; });
    hpLikersTab='likes';
    document.querySelectorAll('#ipvLikersTabs .ipv-likers-tab').forEach(function(b){ b.classList.toggle('active', b.dataset.tab==='likes'); });
    renderEngList('likes');
    document.getElementById('ipvLikersOverlay').classList.add('ipv-open');
    /* the quick list above is only the people you know — swap in the full lists the moment they arrive */
    var tok=++hpLikersTok;
    ['likes','reposts'].forEach(function(type){
        fetch('get_engagement.php?meme_id='+memeId+'&type='+type).then(function(r){ return r.json(); }).then(function(users){
            if(tok!==hpLikersTok || !Array.isArray(users)) return;
            var _known={}; (hpLikers.likes||[]).concat(hpLikers.reposts||[]).forEach(function(k){ if(k.isVerified) _known[String(k.id)]=1; });
            hpLikers[type]=users.map(function(u){ return {id:u.id, username:u.username, profile_pic:u.profile_pic, isVerified:!!(u.is_verified||u.isVerified||u.verified||_known[String(u.id)]), action_type:(type==='likes'?'like':'repost')}; });
            if(hpLikersTab===type) renderEngList(type);
        }).catch(function(){});
    });
}
function switchEngTab(btn,tab){
    hpLikersTab=tab;
    document.querySelectorAll('#ipvLikersTabs .ipv-likers-tab').forEach(function(b){ b.classList.toggle('active', b===btn); });
    renderEngList(tab);
}
function renderEngList(tab){
    var listEl=document.getElementById('ipvLikersList'); if(!listEl) return;
    var list=hpLikers[tab]||[];
    if(!list.length){ listEl.innerHTML='<div class="ipv-likers-empty">No '+tab+' yet</div>'; return; }
    var icon = tab==='reposts' ? 'ri-repeat-line' : 'ri-heart-3-fill';
    listEl.innerHTML=list.map(function(sp){
        return '<a href="profile.php?user_id='+sp.id+'" class="ipv-liker-row" style="text-decoration:none;">'+
            '<img class="ipv-liker-avatar" src="'+esc(sp.profile_pic||'uploads/mee.jpeg')+'" onerror="this.onerror=null;this.src=\'uploads/mee.jpeg\'">'+
            '<span class="ipv-liker-name">'+esc(sp.username||'')+(sp.isVerified?(window.DZ_VB||''):'')+'</span>'+
            '<i class="ipv-liker-icon '+icon+'"></i>'+
        '</a>';
    }).join('');
}
function closeEngModal(){ var el=document.getElementById('ipvLikersOverlay'); if(el) el.classList.remove('ipv-open'); }
document.addEventListener('keydown',function(e){ if(e.key==='Escape') closeEngModal(); });

/* ───────── escaping / formatting ───────── */
function dzAdSound(e){ if(e&&e.stopPropagation) e.stopPropagation(); showToast('Sorry, this feature doesn’t work for Ad posts'); return false; }
window.dzSafeUrl = window.dzSafeUrl || function(u){ u=String(u==null?'':u).trim(); if(/^(https?:)?\/\//i.test(u)) return u; if(/^[a-z][a-z0-9+.-]*:/i.test(u)) return '#'; return u ? 'https://'+u : '#'; };
/* black words on a light button colour, white words on a dark one */
function dzCtaInk(c){
    c=String(c||'').trim().toLowerCase();var r,g,b,m;
    if((m=/^#([0-9a-f]{3})$/.exec(c))){r=parseInt(m[1][0]+m[1][0],16);g=parseInt(m[1][1]+m[1][1],16);b=parseInt(m[1][2]+m[1][2],16);}
    else if((m=/^#([0-9a-f]{6})/.exec(c))){r=parseInt(m[1].slice(0,2),16);g=parseInt(m[1].slice(2,4),16);b=parseInt(m[1].slice(4,6),16);}
    else if((m=/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/.exec(c))){r=+m[1];g=+m[2];b=+m[3];}
    else if(c==='white')return '#000';
    else return '#fff';
    return (0.2126*r+0.7152*g+0.0722*b)>160?'#000':'#fff';
}
function esc(s){
    if(s===null||s===undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;');
}
/* comment count next to the comment button: uses p.commentCount if the feed has it, otherwise it is filled in by hpLoadCommentCounts() */
function commentCntHtml(p){ return '<span class="ipv-action-count comment-count" data-id="'+p.id+'" data-hidden="0">'+(p.commentCount!=null?fmtNum(p.commentCount):'')+'</span>'; }
function cntHtml(cls,hidden,n){ return '<span class="ipv-action-count '+cls+'" data-hidden="'+(hidden?'1':'0')+'">'+(hidden?'':fmtNum(n||0))+'</span>'; }
function fmtNum(n){
    n=parseInt(n)||0;
    if(n>=1e9) return (n/1e9).toFixed(1).replace(/\.0$/,'')+'B';
    if(n>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M';
    if(n>=1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'')+'K';
    return n;
}
function linkify(raw){
    return esc(raw||'')
        .replace(/#([a-zA-Z0-9_]+)/g,function(m,tag){
            return '<a class="dz-tag" href="search.php?focus=1&mq='+encodeURIComponent('#'+tag)+'" onclick="event.stopPropagation()">'+m+'</a>';
        })
        .replace(/@([a-zA-Z0-9_]+)/g,function(m,u){
            return '<a class="dz-mention" href="profile.php?username='+encodeURIComponent(u)+'" onclick="return dzMention(event,\''+u+'\')">'+m+'</a>';
        });
}
/* tap @username → looks the user up and opens their profile (ctrl/middle-click still opens the link normally) */
function dzMention(e,u){
    e.stopPropagation();
    if(e.ctrlKey||e.metaKey||e.shiftKey||e.button===1) return true;
    fetch('?type=user&u='+encodeURIComponent(u)).then(function(r){ return r.json(); }).then(function(d){
        if(d && d.id) location.href='profile.php?user_id='+d.id; else showToast('User not found');
    }).catch(function(){ location.href='profile.php?username='+encodeURIComponent(u); });
    return false;
}
function typeTagInfo(t){
    t=(t||'').toLowerCase();
    return ({image:{icon:'ri-file-copy-line',label:'Photo'},video:{icon:'ri-vidicon-line',label:'Video'},gif:{icon:'ri-file-gif-line',label:'GIF'},sticker:{icon:'ri-shapes-line',label:'Sticker'},stickers:{icon:'ri-shapes-line',label:'Sticker'},text:{icon:'ri-file-text-line',label:'Text'}})[t]||{icon:'ri-file-copy-line',label:'Photo'};
}
function fullDate(s){
    if(!s) return ''; var d=new Date(String(s).replace(' ','T')); if(isNaN(d)) return s;
    var D=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return D[d.getDay()]+', '+d.getDate()+' '+M[d.getMonth()]+' '+d.getFullYear()+' · '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}
function dzStripHtml(p){
    var strip='';
    if(!p.isAd && Array.isArray(p.socialProof) && p.socialProof.length){
        strip='<div class="ipv-social-proof-strip'+''+'">'+p.socialProof.slice(0,4).map(function(sp){
            var like=sp.action_type==='like';
            return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="ipv-sp-avatar-wrap"><img src="'+esc(sp.profile_pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" class="ipv-sp-avatar">'+
                (like ? '<span class="ipv-sp-badge ipv-sp-heart">'+IPV_HEART_SVG+'</span>'
                      : '<span class="ipv-sp-badge ipv-sp-rep" style="background:none;box-shadow:none;border:0;"><svg viewBox="0 0 100 100" aria-hidden="true" style="display:block;width:100%;height:100%;overflow:visible"><circle class="sp-rring" cx="50" cy="50" r="55.75" fill="none" stroke-width="11.5"/><circle cx="50" cy="50" r="50" fill="#7a4aff"/><g transform="translate(18.8 18.8) scale(2.6)" fill="none" stroke="#fff" stroke-width="2.04" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></g></svg></span>')+
            '</a>';
        }).join('')+'</div>';
    }
    return strip;
}
function isNewPost(s){ if(!s) return false; var d=new Date(String(s).replace(' ','T')); return !isNaN(d) && (Date.now()-d.getTime())<=172800*1000; }
function likesText(p){
    var proof=Array.isArray(p.socialProof)?p.socialProof:[];
    var lp=proof.filter(function(sp){ return sp.action_type==='like'; });
    var c=p.likeCount||0, rc=p.repostCount||0, base='';
    if(p.hideLikes){ if(lp.length) base='<span class="ipv-likes-dim">Liked by</span> <b>'+esc(lp[0].username||'')+'</b>'+(lp[0].isVerified?(window.DZ_VB||''):''); }
    else if(c>0){
        if(lp.length){
            var rest=c-1, first=esc(lp[0].username||'')+(lp[0].isVerified?(window.DZ_VB||''):'');
            base = rest>0
              ? '<span class="ipv-likes-dim">Liked by</span> <b>'+first+'</b> <span class="ipv-likes-dim">and</span> <b>'+fmtNum(rest)+' other'+(rest===1?'':'s')+'</b>'
              : '<span class="ipv-likes-dim">Liked by</span> <b>'+first+'</b>';
        } else base='<b>'+fmtNum(c)+' like'+(c===1?'':'s')+'</b>';
    }
    if(!p.hideReposts && rc>0){ var rp='<b>'+fmtNum(rc)+' repost'+(rc===1?'':'s')+'</b>'; base=base?base+' · '+rp:rp; }
    return base;
}
function socialRowHtml(p){
    var lp=Array.isArray(p.socialProof)?p.socialProof.filter(function(sp){ return sp.action_type==='like'; }):[];
    var av=lp.length?'<div class="ipv-social-avatars">'+lp.slice(0,3).map(function(sp){ return '<img src="'+esc(sp.profile_pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'">'; }).join('')+'</div>':'';
    var tx=likesText(p);
    if(!av && !tx) return '';
    return '<div class="ipv-social-row" onclick="event.stopPropagation();openEngModal('+p.id+')">'+av+(tx?'<div class="ipv-likes-text">'+tx+'</div>':'')+'</div>';
}
/* avatar + green "Active now" dot (ring is the page colour, sits on the pic's bottom-right) */
function avWrap(inner, uid, online, cls){
    return '<span class="av-wrap'+(cls?' '+cls:'')+'" data-uid="'+uid+'">'+inner+(online?'<i class="on-dot"></i>':'')+'</span>';
}

/* heart badge for the "liked by" avatars (same SVG as the immersive viewer) */
var IPV_HEART_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path class="sp-ring" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" stroke-width="3.67" stroke-linejoin="round"/><path class="sp-fill" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>';

/* the video of a post that is ON SCREEN (a gallery has several; never the first one by default) */
function hpActiveVideo(post){
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
function hpApplyVideoSound(post){
    if(!post) return;
    var act = hpActiveVideo(post);
    var on  = post.dataset.vsound === '1';
    post.querySelectorAll('video.ipv-video').forEach(function(v){
        if(v !== act){ if(!v.paused) v.pause(); v.muted = true; }
    });
    if(act && !post.dataset.audio) act.muted = !on;
    var b = post.querySelector('.ipv-video-mute-btn i');
    if(b && !post.dataset.audio) b.className = on ? 'ri-volume-up-fill' : 'ri-volume-mute-fill';
}

/* ═══════════ COMMENT COUNTS (the number next to the comment button) ═══════════
   Asked from comment_section.php in one request per batch of posts. When someone adds or deletes a comment in the sheet,
   the sheet fires "cs:changed" and the number is updated right away. */
var hpCommentCounts = {}, hpCommentOff = {};
function hpPaintComment(id){
    var off = !!hpCommentOff[id];
    document.querySelectorAll('.comment-count[data-id="'+id+'"]').forEach(function(sp){ sp.textContent = off ? '' : fmtNum(hpCommentCounts[id] || 0); if(off) sp.setAttribute('data-off','1'); else sp.removeAttribute('data-off'); });
    document.querySelectorAll('.comment-btn[data-comments="'+id+'"]').forEach(function(b){ b.classList.toggle('cm-off', off); });
}
function hpLoadCommentCounts(posts){
    if(!window.CommentSection || !posts || !posts.length) return;
    var ids = posts.map(function(p){ return p.dataset.id; }).filter(Boolean);
    if(!ids.length) return;
    var req = window.CommentSection.status ? window.CommentSection.status(ids) : window.CommentSection.counts(ids).then(function(c){ return { counts:c, off:{} }; });
    req.then(function(r){
        var c = (r && r.counts) || {}, off = (r && r.off) || {};
        Object.keys(c).forEach(function(id){ hpCommentCounts[id] = c[id]; hpCommentOff[id] = !!off[id]; hpPaintComment(id); });
    }).catch(function(){});
}
window.addEventListener('cs:changed', function(e){
    var d = e && e.detail; if(!d || !d.meme) return;
    hpCommentCounts[d.meme] = Math.max(0, (hpCommentCounts[d.meme] || 0) + (d.delta || 0));
    hpPaintComment(d.meme);
});

/* ═══════════ RENDER ONE POST ═══════════ */
function renderPost(p){
    var media=(Array.isArray(p.media)&&p.media.length)?p.media:[{file:p.file,type:p.type}];
    var mc=media.length, isOwn=(p.isYou===true);
    var mediaSet=['image','video','gif','sticker','stickers'];
    var firstType=((media[0].type)||'').toLowerCase();
    var isTextOnly=(mc===1 && mediaSet.indexOf(firstType)===-1);
    var hasVideo=media.some(function(m){ return (m.type||'').toLowerCase()==='video'; });
    var mediaInner;

    if(isTextOnly){
        var words=(p.caption||'').trim().split(/\s+/).filter(Boolean);
        var tln=(p.caption||'').trim().length, tcls=tln<=90?'ipv-t-s':(tln<=220?'ipv-t-m':'ipv-t-l');
        var trunc=words.length>40
            ? linkify(words.slice(0,40).join(' '))+' <span class="ipv-more-wrap" onclick="event.stopPropagation();hpToggleCenterCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>'
            : linkify(p.caption||'');
        mediaInner='<div class="ipv-textonly-clickable" onclick="hpMediaTap(event,this,'+p.id+')">'+
            '<div class="ipv-center-caption-wrap"><div class="ipv-center-caption-text '+tcls+'" data-full="'+esc(p.caption||'')+'">'+trunc+'</div></div></div>';
    } else {
        mediaInner='<div class="ipv-media-track">'+media.map(function(m,i){
            var t=(m.type||'').toLowerCase();
            if(t==='video'){
                return '<div class="ipv-media-item" data-idx="'+i+'" data-type="video"><video src="'+esc(m.file)+'" muted loop playsinline preload="metadata" class="ipv-video" data-idx="'+i+'" onloadedmetadata="hpMeasure(this)" onclick="event.stopPropagation();hpMediaTap(event,this,'+p.id+')"></video></div>';
            }
            var src=(t==='text'||!m.file)?'uploads/Text.jpg':m.file;
            return '<div class="ipv-media-item" data-idx="'+i+'" data-type="'+(t||'image')+'"><img src="'+esc(src)+'" data-idx="'+i+'" loading="lazy" draggable="false" onerror="this.src=\'uploads/Text.jpg\'" onload="hpMeasure(this)" onclick="event.stopPropagation();hpMediaTap(event,this,'+p.id+')"></div>';
        }).join('')+'</div>';
    }

    var dotsHtml=(!isTextOnly&&mc>1)?'<div class="ipv-dots">'+media.slice(0,20).map(function(_,i){ return '<span class="ipv-dot'+(i===0?' active':'')+'"></span>'; }).join('')+'</div>':'';
    var counterHtml=(!isTextOnly&&mc>1)?'<div class="ipv-media-counter">1/'+mc+'</div>':'';
    var topRight=counterHtml?'<div class="ipv-top-right-tags">'+counterHtml+'</div>':'';
    var arrows=(!isTextOnly&&mc>1)?'<button type="button" class="ipv-media-arrow ipv-arrow-left" onclick="event.stopPropagation();hpArrowNav(this,-1)"><i class="ri-arrow-left-s-line"></i></button><button type="button" class="ipv-media-arrow ipv-arrow-right" onclick="event.stopPropagation();hpArrowNav(this,1)"><i class="ri-arrow-right-s-line"></i></button>':'';

    var tl='';
    if(p.groupTitle) tl+='<a href="group.php?id='+p.groupId+'" onclick="event.stopPropagation()" class="ipv-group-tag">From&nbsp;<b>'+esc(p.groupTitle)+'</b></a>';
    var topLeft=tl?'<div class="ipv-top-left-tags">'+tl+'</div>':'';

    var partner='';
    /* paid partnership moved into the username flip */

    var strip=dzStripHtml(p);

    var cta='';
    if(p.isAd && p.adCtaUrl){
        var _ctaCol=p.adCtaColor||'#FF4C4C';
        cta='<a href="'+esc(dzSafeUrl(p.adCtaUrl))+'" target="_blank" rel="noopener" class="ipv-cta-bar" data-meme-id="'+p.id+'" style="--cta-color:'+esc(_ctaCol)+';--cta-ink:'+dzCtaInk(_ctaCol)+';" onclick="event.stopPropagation()"><span class="ipv-cta-bar-text">'+esc(p.adCtaText||'Learn more')+'</span><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></a>';
    }

    var controls='';
    /* ONE sound button per post:
       - video posts: a single button. A video's "Original audio" IS the video's own sound, so it isn't played a second time through the sound player.
         If real music is attached to the video, the same button turns the video sound and the music on/off together.
       - photo / gallery posts with music: the single music button.
       - ads never play an attached track (their sound button shows the "not for Ads" message). */
    /* the "track" must never be the video's own sound: flagged original, OR the very same file as one of the post's videos (that played the sound twice, a beat apart = the echo) */
    var _base=function(u){ return String(u||'').split('?')[0].split('/').pop().toLowerCase(); };
    var audioIsVideoFile=!!p.audioFile && media.some(function(m){ return (m.type||'').toLowerCase()==='video' && _base(m.file)===_base(p.audioFile); });
    var useTrack=!!p.audioFile && !p.isAd && !(p.audioIsOriginal && hasVideo) && !audioIsVideoFile;
    if(!isTextOnly && (hasVideo||p.audioFile)){
        controls='<div class="ipv-media-controls">';
        if(hasVideo) controls+='<button type="button" class="ipv-mute-btn ipv-video-mute-btn"'+((mc>1&&firstType!=='video'&&!useTrack)?' style="display:none"':'')+' onclick="event.stopPropagation();hpToggleVideoMute(this)"><i class="ri-volume-mute-fill"></i></button>';
        else controls+='<button type="button" class="ipv-mute-btn ipv-track-mute-btn" onclick="'+(p.isAd?'dzAdSound(event)':'event.stopPropagation();hpToggleTrackMute()')+'"><i class="ri-volume-mute-fill"></i></button>';
        controls+='</div>';
    }

    var displayName=isOwn?'You':esc(p.username||'');
    var newTag='';var isNewP=isNewPost(p.time);
    var trackBtn=!isOwn?'<span class="ipv-dot-sep-red">•</span><button type="button" class="ipv-track-btn track-btn'+(p.isFollowing?' following':'')+'" data-user="'+p.userId+'" onclick="event.stopPropagation();handleTrackBtn(this)">'+(p.isFollowing?'Untrack':'Track')+'</button>':'';

    var nick=(!isOwn&&p.nickname)?'@'+esc(p.nickname):'';
    var _co=(p.collab&&p.collab.list&&p.collab.list.length)?p.collab:null,coName=displayName,coPics='',coOpen='';
    if(_co){
        coOpen='event.stopPropagation();dzCollabSheet('+p.id+',hpCollabOf('+p.id+'))';
        coName=displayName+' and '+(_co.list.length===1?esc(_co.list[0].username):_co.list.length+' others');
        nick='';trackBtn='';
        _co.list.slice(0,2).forEach(function(c,i){coPics+='<img class="ipv-avatar ipv-co-pic c'+(i+1)+'" src="'+esc(c.pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" onclick="'+coOpen+'">';});
    }

    var audioHtml='';
    if(p.audioId!==undefined&&p.audioId!==null){
        var label=p.audioIsOriginal?((isOwn?p.username:displayName)+' · Original audio'):((p.audioTitle||'Audio')+(p.audioArtist?' — '+p.audioArtist:''));
        audioHtml='<div class="ipv-audio-tag" onclick="'+(p.isAd?'dzAdSound(event)':'event.stopPropagation();ccmOpenSoundsPage('+p.userId+','+p.audioId+')')+'" style="cursor:pointer;"><span class="ipv-audio-icon"><span class="ipv-eq-bar"></span><span class="ipv-eq-bar"></span><span class="ipv-eq-bar"></span></span><span class="ipv-audio-text">'+esc(label)+'</span></div>';
    }
    var aiHtml=p.isAiGenerated?'<div class="ipv-ai-line"><span>AI Generated</span></div>':'';
    var partnerHtml=p.isPartnership?'<div class="ipv-partner-line"><i class="ri-handshake-line"></i><span>Paid partnership'+(p.partnerBrand?' with <b>'+esc(p.partnerBrand)+'</b>':'')+'</span></div>':'';
    var panes=[];
    if(p.suggested && !isOwn) panes.push('<div class="ipv-sub-line ipv-sugg-line">Suggested for you</div>');
    if(nick) panes.push('<div class="ipv-sub-line">'+nick+'</div>');
    if(partnerHtml) panes.push(partnerHtml);
    if(audioHtml) panes.push(audioHtml);
    if(aiHtml) panes.push(aiHtml);
    var switcher='';
    if(panes.length>1) switcher='<div class="ipv-info-switcher" data-switcher="1">'+panes.map(function(h,i){ return '<div class="ipv-switch-pane'+(i===0?' active':'')+'">'+h+'</div>'; }).join('')+'</div>';
    else if(panes.length===1) switcher=panes[0];

    var headerCap='',cap='';
    if(!isTextOnly){
        var w=(p.caption||'').trim().split(/\s+/).filter(Boolean), capHtml;
        if(w.length>14){
            capHtml='<span class="ipv-cap-short" data-full="'+esc(p.caption||'')+'" data-expanded="0">'+linkify(w.slice(0,14).join(' '))+'<span class="ipv-more-wrap" onclick="event.stopPropagation();hpToggleCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span></span>';
        } else capHtml=linkify(p.caption||'');
        headerCap='<div class="ipv-header-caption">'+capHtml+'</div>';
        cap='<div class="ipv-caption"><b class="ipv-cap-user">'+displayName+'</b>'+capHtml+'</div>';
    }

    var wrapClass='ipv-media-wrap'+((!isTextOnly&&mc>1)?' ipv-multi':'')+(isTextOnly?' ipv-textonly'+(tl?' ipv-tl':''):'');
    var audioAttrs=useTrack?' data-audio="'+esc(p.audioFile)+'" data-music-vol="'+(p.musicVolume!=null?p.musicVolume:80)+'" data-video-vol="'+(p.videoAudioVolume!=null?p.videoAudioVolume:40)+'"':'';

    var avatarImg='<img class="ipv-avatar" src="'+esc(p.pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" onclick="'+(_co?coOpen:'location.href=\'profile.php?user_id='+p.userId+'\'')+'">';

    var rs=p.reason, reasonHtml='';
    if(rs){
        var rtxt = rs.type==='tag' ? 'Because you like <b>#'+esc(rs.tag)+'</b>'
                 : 'Because <b>@'+esc(rs.user)+'</b> '+(rs.type==='repost'?'reposted':'liked')+' this';
        reasonHtml='<div class="ipv-reason"><i class="ri-lightbulb-flash-line"></i><span>'+rtxt+'</span></div>';
    }

    return '<div class="ipv-post'+(isTextOnly?' ipv-post-text':'')+(reasonHtml?' ipv-has-reason':'')+'" data-id="'+p.id+'" data-user="'+p.userId+'"'+(p.suggested&&!isOwn?' data-suggested="1"':'')+audioAttrs+'>'+
        reasonHtml+
        '<div class="ipv-header">'+
            avWrap(avatarImg+coPics, p.userId, !isOwn && p.online, _co?'ipv-collab n'+Math.min(2,_co.list.length):'')+
            '<div class="ipv-header-info"><div class="ipv-username-row"><span class="ipv-username'+(_co?' ipv-co-name':'')+'" onclick="'+(_co?coOpen:'location.href=\'profile.php?user_id='+p.userId+'\'')+'">'+coName+'</span>'+((p.isVerified&&!_co)?(window.DZ_VB||''):'')+newTag+trackBtn+'</div>'+switcher+'</div>'+
            '<button type="button" class="ipv-kebab index-kebab-btn" data-id="'+p.id+'" onclick="event.stopPropagation();openIndexSheet('+p.id+')"><i class="ri-menu-line"></i></button>'+
        '</div>'+
        headerCap+
        '<div class="'+wrapClass+'" data-count="'+mc+'" data-active-idx="0">'+(isTextOnly?(topLeft+topRight+mediaInner+strip+partner):(topLeft+topRight+strip+mediaInner+arrows+controls+partner+dotsHtml))+'</div>'+cta+
        '<div class="ipv-actions"><div class="ipv-actions-left">'+
            '<div class="ipv-action-group"><button type="button" class="ipv-action-btn like-btn'+(p.liked?' active':'')+'" data-id="'+p.id+'" onclick="event.stopPropagation();hpAction('+p.id+',\'like\',this)"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i></button>'+cntHtml('like-count',p.hideLikes,p.likeCount)+'</div>'+
            (p.isAd?'':'<div class="ipv-action-group"><button type="button" class="ipv-action-btn repost-btn'+(p.reposted?' active':'')+'" data-id="'+p.id+'" onclick="event.stopPropagation();hpAction('+p.id+',\'repost\',this)"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg></i></button>'+cntHtml('repost-count',p.hideReposts,p.repostCount)+'</div>')+
            '<div class="ipv-action-group"><button type="button" class="ipv-action-btn comment-btn" data-comments="'+p.id+'" aria-label="Comments"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg><i class="ri-chat-off-line cm-off-ic" aria-hidden="true"></i></i></button>'+commentCntHtml(p)+'</div>'+
            '<div class="ipv-action-group"><button type="button" class="ipv-action-btn fav-btn'+(p.faved?' active':'')+'" data-id="'+p.id+'" onclick="event.stopPropagation();hpAction('+p.id+',\'fav\',this)"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21l-7-5-7 5V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1z"/></svg></i></button>'+cntHtml('fav-count',p.hideSaves,p.favCount)+'</div>'+
            '<div class="ipv-action-group"><button type="button" class="ipv-action-btn share-btn" data-id="'+p.id+'" onclick="event.stopPropagation();openShareModal('+p.id+')"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i></button>'+cntHtml('share-count',p.hideShares,p.shareCount)+'</div>'+
        '</div>'+dzAdTag(p,isNewP)+'</div>'+
        socialRowHtml(p)+cap+
        (isTextOnly?'<div class="ipv-time ipv-time-row"><div class="ipv-time-left"><span>'+(p.time?esc(fullDate(p.time)):'')+'</span><span class="ipv-zeem-hint">Click Text to zeem</span></div></div>':(p.time?'<div class="ipv-time">'+esc(fullDate(p.time))+'</div>':''))+
    '</div>';
}

function hpCollabOf(id){var a=[].concat((typeof peuraFeed!=='undefined'&&peuraFeed)||[],window._risePool||[]);for(var i=0;i<a.length;i++){if(String(a[i].id)===String(id))return a[i].collab||null}return null}
function dzCollabFit(){document.querySelectorAll('.av-wrap.ipv-collab:not([data-fit])').forEach(function(w){var h=w.closest('.ipv-header')||w.parentNode;var pr=document.createElement('span');pr.className='av-wrap';pr.style.cssText='position:absolute;visibility:hidden;left:-9999px;top:0';pr.innerHTML='<img class="ipv-avatar" alt="">';h.appendChild(pr);var s=pr.firstChild.getBoundingClientRect().width;h.removeChild(pr);if(s>8){w.style.setProperty('--cw',Math.round(s*1.45)+'px');w.setAttribute('data-fit','1');}});}
(function(){var t=0;function q(){if(t)return;t=requestAnimationFrame(function(){t=0;dzCollabFit();});}try{new MutationObserver(q).observe(document.documentElement,{childList:true,subtree:true});}catch(e){}window.addEventListener('resize',function(){document.querySelectorAll('.av-wrap.ipv-collab[data-fit]').forEach(function(w){w.removeAttribute('data-fit');});q();});q();})();
function skeletonHTML(n){
    var one='<div class="ipv-skel-post"><div class="ipv-skel-header"><div class="ipv-skel-avatar"></div><div class="ipv-skel-header-lines"><div class="ipv-skel-line w1"></div><div class="ipv-skel-line w2"></div></div></div><div class="ipv-skel-media"></div><div class="ipv-skel-actions"><div class="ipv-skel-icon"></div><div class="ipv-skel-icon"></div><div class="ipv-skel-icon"></div><div class="ipv-skel-icon"></div></div><div class="ipv-skel-cap"></div><div class="ipv-skel-cap2"></div></div>';
    return new Array(n+1).join(one);
}

/* ───────── caption expand/collapse ───────── */
function hpSyncCapState(post){
    if(!post) return;
    var open = post.querySelector('.ipv-cap-short[data-expanded="1"], .ipv-center-caption-text[data-open="1"]');
    post.classList.toggle('ipv-cap-open', !!open);
}
window.hpToggleCaption=function(el){
    var wrap=el.closest('.ipv-cap-short'); if(!wrap) return;
    var post=el.closest('.ipv-post');
    var open=wrap.dataset.expanded==='1', full=wrap.dataset.full||'';
    if(!open){ wrap.dataset.expanded='1'; wrap.innerHTML=linkify(full)+' <span class="ipv-more-toggle" onclick="event.stopPropagation();hpToggleCaption(this)"> less</span>'; }
    else { wrap.dataset.expanded='0'; wrap.innerHTML=linkify(full.trim().split(/\s+/).slice(0,14).join(' '))+'<span class="ipv-more-wrap" onclick="event.stopPropagation();hpToggleCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>'; }
    hpSyncCapState(post);
};
window.hpToggleCenterCaption=function(el){
    var t=el.closest('.ipv-center-caption-text'); if(!t) return;
    var post=el.closest('.ipv-post');
    var full=t.dataset.full||'';
    if(t.dataset.open!=='1'){ t.dataset.open='1'; t.innerHTML=linkify(full)+' <span class="ipv-more-toggle" onclick="event.stopPropagation();hpToggleCenterCaption(this)"> less</span>'; }
    else { t.dataset.open='0'; t.innerHTML=linkify(full.trim().split(/\s+/).slice(0,40).join(' '))+'<span class="ipv-more-wrap" onclick="event.stopPropagation();hpToggleCenterCaption(this)"><span class="ipv-ellipsis">...</span><span class="ipv-more-toggle"> more</span></span>'; }
    hpSyncCapState(post);
};

/* ───────── suggested users ───────── */
window._suggRemoved = window._suggRemoved || new Set();

function hpSuggArrows(scroller){
    if(!scroller) return;
    var wrap=scroller.closest('.hp-sugg-scroll-wrap'); if(!wrap) return;
    var l=wrap.querySelector('.hp-sugg-arrow-l'), r=wrap.querySelector('.hp-sugg-arrow-r');
    if(l) l.classList.toggle('hidden', scroller.scrollLeft<=4);
    if(r) r.classList.toggle('hidden', scroller.scrollLeft>=scroller.scrollWidth-scroller.clientWidth-4);
}
function hpSuggScroll(btn,dir){
    var wrap=btn.closest('.hp-sugg-scroll-wrap'); if(!wrap) return;
    var sc=wrap.querySelector('.hp-sugg-scroll'); if(!sc) return;
    sc.scrollBy({left:dir*Math.max(200,sc.clientWidth*0.75),behavior:'smooth'});
}
function hpSuggRemove(btn){
    var card=btn.closest('.hp-sugg-card'), sec=btn.closest('.hp-sugg');
    if(card){
        if(card.dataset.uid) window._suggRemoved.add(String(card.dataset.uid));
        var sc=card.parentNode; card.remove(); hpSuggArrows(sc);
    }
    if(sec && !sec.querySelector('.hp-sugg-card')) sec.remove();
}
function hpSuggClose(btn){ var s=btn.closest('.hp-sugg'); if(s) s.remove(); }
window.addEventListener('resize',function(){ document.querySelectorAll('.hp-sugg-scroll').forEach(hpSuggArrows); });

/* ═══════════ MEMEPEDIA FEATURED ROW ═══════════ */
var MP_EVERY = 12, MP_COUNT = 8;
var mpLoaded = false, mpShownIds = {}, mpLastOrder = {}, mpRowSeq = 1;
window._mpSkeletonShown = window._mpSkeletonShown || false;

var mpStatusMeta = {
    all:       { label:'All',       icon:'ri-apps-2-line'    },
    trending:  { label:'Trending',  icon:'ri-fire-fill'      },
    rising:    { label:'Rising',    icon:'ri-arrow-up-line'  },
    classic:   { label:'Classic',   icon:'ri-building-line'  },
    legendary: { label:'Legendary', icon:'ri-vip-crown-line' },
    dead:      { label:'Dead',      icon:'ri-skull-line'     },
    revived:   { label:'Revived',   icon:'ri-refresh-line'   }
};

window._mpPromise = fetch('?type=memepedia').then(function(r){ return r.json(); })
    .then(function(a){ window.mpArticles = Array.isArray(a) ? a : []; mpLoaded = true; fillMpSlots(); return window.mpArticles; })
    .catch(function(){ window.mpArticles = []; mpLoaded = true; fillMpSlots(); return []; });

function mpShuffle(a){
    a=a.slice();
    for(var i=a.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)),t=a[i]; a[i]=a[j]; a[j]=t; }
    return a;
}
function mpPick(rowId, status){
    var pool=(window.mpArticles||[]).filter(function(a){ return status==='all' || a.status===status; });
    if(!pool.length) return [];
    var want=Math.min(MP_COUNT,pool.length);
    if(!mpShownIds[rowId]) mpShownIds[rowId]={};
    var shown=mpShownIds[rowId];
    var fresh=pool.filter(function(a){ return !shown[a.id+':'+status]; });
    if(fresh.length<want){ shown={}; mpShownIds[rowId]=shown; fresh=pool; }
    var pick=[], order='', tries=0;
    do{
        pick=mpShuffle(fresh).slice(0,want);
        if(pick.length<want){
            var ids={}; pick.forEach(function(a){ ids[a.id]=1; });
            mpShuffle(pool).forEach(function(a){ if(pick.length<want && !ids[a.id]){ pick.push(a); ids[a.id]=1; } });
        }
        order=pick.map(function(a){ return a.id; }).join(',');
        tries++;
    } while(pool.length>1 && order===mpLastOrder[rowId+':'+status] && tries<8);
    mpLastOrder[rowId+':'+status]=order;
    pick.forEach(function(a){ shown[a.id+':'+status]=true; });
    return pick;
}
function mpCardHtml(a){
    var meta=mpStatusMeta[a.status]||mpStatusMeta.trending;
    var thumb;

    if(a.mediaUrl){
        var mt = (a.mediaType || '').toLowerCase();

        if(mt === 'video'){
            thumb = '<video class="hp-mp-thumb" src="'+esc(a.mediaUrl)+'" '+
                    'autoplay muted loop playsinline disablepictureinpicture '+
                    'preload="auto" '+
                    'onerror="this.outerHTML=\'<div class=&quot;hp-mp-thumb-empty&quot;>\uD83D\uDCD6</div>\'"></video>';
        } else {
            thumb = '<img class="hp-mp-thumb" src="'+esc(a.mediaUrl)+'" alt="'+esc(a.title)+'" loading="lazy" '+
                    'onerror="this.outerHTML=\'<div class=&quot;hp-mp-thumb-empty&quot;>\uD83D\uDCD6</div>\'">';
        }
    } else {
        thumb = '<div class="hp-mp-thumb-empty">\uD83D\uDCD6</div>';
    }

    return '<div class="hp-mp-card" onclick="mpOpen('+a.id+')">'+
        thumb+
        '<div class="hp-mp-badge"><i class="'+meta.icon+'"></i>'+meta.label+'</div>'+
        '<div class="hp-mp-info">'+
            '<div class="hp-mp-card-title">'+esc(a.title)+'</div>'+
            '<div class="hp-mp-card-meta"><span><i class="ri-eye-line"></i> '+fmtNum(a.views||0)+'</span></div>'+
        '</div>'+
    '</div>';
}
function mpSkeletonCards(n){
    var one='<div class="hp-mp-skel-card"><div class="hp-mp-skel-thumb"></div><div class="hp-mp-skel-line" style="width:80%;"></div><div class="hp-mp-skel-line" style="width:45%;margin-bottom:10px;"></div></div>';
    return new Array(n+1).join(one);
}
function mpSkeletonRowInner(){
    var pills=Object.keys(mpStatusMeta).map(function(){ return '<span class="hp-mp-skel-pill"></span>'; }).join('');
    return '<div class="hp-mp-head"><div><div class="hp-mp-kicker">Memepedia Featured</div><div class="hp-mp-title"><i class="ri-book-open-line"></i>Explore the culture</div></div></div>'+
        '<div class="hp-mp-pills">'+pills+'</div>'+
        '<div class="hp-mp-scroll-wrap"><div class="hp-mp-scroll">'+mpSkeletonCards(4)+'</div></div>';
}
function mpRowHtml(rowId){
    var status='all';
    var picked=mpPick(rowId,status);
    var pillsHtml=Object.keys(mpStatusMeta).map(function(k){
        return '<button type="button" class="hp-mp-pill'+(k===status?' active':'')+'" data-status="'+k+'" onclick="mpSwitchStatus(this,\''+rowId+'\',\''+k+'\')">'+mpStatusMeta[k].label+'</button>';
    }).join('');
    var cardsHtml=picked.length ? picked.map(mpCardHtml).join('') : '<div style="padding:20px 4px;color:rgba(255,255,255,.4);font-size:.72rem;">Nothing here yet.</div>';
    return '<div class="hp-mp" data-mp-id="'+rowId+'" data-mp-status="'+status+'">'+
        '<div class="hp-mp-head"><div><div class="hp-mp-kicker">Memepedia Featured</div>'+
            '<div class="hp-mp-title"><i class="ri-book-open-line"></i>Explore the culture</div></div>'+
            '<button type="button" class="hp-mp-x" aria-label="Hide" onclick="mpCloseRow(this)"><i class="ri-close-line"></i></button></div>'+
        '<div class="hp-mp-pills">'+pillsHtml+'</div>'+
        '<div class="hp-mp-scroll-wrap">'+
            '<button type="button" class="hp-mp-arrow hp-mp-arrow-l hidden" aria-label="Scroll left" onclick="mpScroll(this,-1)"><i class="ri-arrow-left-s-line"></i></button>'+
            '<div class="hp-mp-scroll" onscroll="mpArrows(this)">'+cardsHtml+'</div>'+
            '<button type="button" class="hp-mp-arrow hp-mp-arrow-r" aria-label="Scroll right" onclick="mpScroll(this,1)"><i class="ri-arrow-right-s-line"></i></button>'+
        '</div></div>';
}
function fillMpSlots(){
    if(!mpLoaded) return;
    document.querySelectorAll('.hp-mp.hp-mp-slot:not([data-filled])').forEach(function(slot){
        slot.dataset.filled='1';
        if(!(window.mpArticles||[]).length){ slot.remove(); return; }
        var rowId='mp'+(mpRowSeq++);
        slot.outerHTML=mpRowHtml(rowId);
    });
    requestAnimationFrame(function(){
        document.querySelectorAll('.hp-mp-scroll').forEach(function(s){ if(!s.dataset.init){ s.dataset.init='1'; s.scrollLeft=0; } mpArrows(s); });
    });
}
window.mpOpen=function(id){ location.href='memepedia.php?open='+id; };
window.mpCloseRow=function(btn){ var s=btn.closest('.hp-mp'); if(s) s.remove(); };
window.mpArrows=function(scroller){
    if(!scroller) return;
    var wrap=scroller.closest('.hp-mp-scroll-wrap'); if(!wrap) return;
    var l=wrap.querySelector('.hp-mp-arrow-l'), r=wrap.querySelector('.hp-mp-arrow-r');
    if(l) l.classList.toggle('hidden', scroller.scrollLeft<=4);
    if(r) r.classList.toggle('hidden', scroller.scrollLeft>=scroller.scrollWidth-scroller.clientWidth-4);
};
window.mpScroll=function(btn,dir){
    var wrap=btn.closest('.hp-mp-scroll-wrap'); if(!wrap) return;
    var sc=wrap.querySelector('.hp-mp-scroll'); if(!sc) return;
    sc.scrollBy({left:dir*Math.max(200,sc.clientWidth*0.75),behavior:'smooth'});
};
window.mpSwitchStatus=function(btn, rowId, status){
    var row=btn.closest('.hp-mp'); if(!row) return;
    row.querySelectorAll('.hp-mp-pill').forEach(function(p){ p.classList.toggle('active', p===btn); });
    row.dataset.mpStatus=status;
    var doSwap=function(){
        var picked=mpPick(rowId,status);
        var sc=row.querySelector('.hp-mp-scroll');
        if(!sc) return;
        sc.innerHTML = picked.length ? picked.map(mpCardHtml).join('') : '<div style="padding:20px 4px;color:rgba(255,255,255,.4);font-size:.72rem;">Nothing here yet.</div>';
        sc.scrollLeft=0; mpArrows(sc);
    };
    if(!window._mpSkeletonShown){
        window._mpSkeletonShown=true;
        var sc=row.querySelector('.hp-mp-scroll');
        if(sc) sc.innerHTML=mpSkeletonCards(4);
        setTimeout(doSwap,420);
    } else {
        doSwap();
    }
};
window.addEventListener('resize',function(){ document.querySelectorAll('.hp-mp-scroll').forEach(mpArrows); });


/* ═══════════ RISING ROW — posts gaining fast that are still small (every 14th post, starting at the 7th) ═══════════ */
var RISE_EVERY = 14, RISE_AT = 7, RISE_COUNT = 8;
var riseLoaded = false, riseShown = {};
window._risePool = [];

window._risePromise = fetch('?type=rising').then(function(r){ return r.json(); })
    .then(function(a){
        var inFeed = {}; (window.peuraFeed || []).forEach(function(p){ inFeed[String(p.id)] = 1; });
        window._risePool = (Array.isArray(a) ? a : []).filter(function(x){ return !inFeed[String(x.id)]; });
        riseLoaded = true; fillRiseSlots();
    })
    .catch(function(){ riseLoaded = true; fillRiseSlots(); });

function riseCardHtml(a){
    var t = String(a.type || '').toLowerCase(), thumb;
    if(t === 'video') thumb = '<video class="hp-rise-thumb" src="'+esc(a.file)+'#t=0.1" muted loop playsinline preload="metadata"></video>';
    else if(['image','gif','sticker','stickers'].indexOf(t) === -1 || !a.file) thumb = '<div class="hp-rise-thumb hp-rise-text">'+esc((a.caption || '').slice(0, 90))+'</div>';
    else thumb = '<img class="hp-rise-thumb" src="'+esc(a.file)+'" alt="" loading="lazy" onerror="this.src=\'uploads/Text.jpg\'">';
    return '<div class="hp-rise-card" onclick="window.hpOpenZeems('+a.id+')">'+
        '<div class="hp-rise-media">'+thumb+'<div class="hp-rise-badge"><i class="ri-fire-fill"></i>Rising</div></div>'+
        '<div class="hp-rise-info"><img class="hp-rise-av" src="'+esc(a.pic || 'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" alt="">'+
        '<span class="hp-rise-name">'+esc(a.username)+'</span>'+
        '<span class="hp-rise-likes"><i class="ri-heart-3-fill" style="color:var(--red)"></i>'+(a.likes==null?'':fmtNum(a.likes))+'</span></div>'+
    '</div>';
}
function riseRowHtml(items){
    return '<div class="hp-rise">'+
        '<div class="hp-mp-head"><div><div class="hp-mp-kicker">Rising now</div><div class="hp-mp-title"><i class="ri-fire-fill"></i>Gaining fast</div></div>'+
            '<button type="button" class="hp-mp-x" aria-label="Hide" onclick="this.closest(\'.hp-rise\').remove()"><i class="ri-close-line"></i></button></div>'+
        '<div class="hp-mp-scroll-wrap">'+
            '<button type="button" class="hp-mp-arrow hp-mp-arrow-l hidden" aria-label="Scroll left" onclick="mpScroll(this,-1)"><i class="ri-arrow-left-s-line"></i></button>'+
            '<div class="hp-mp-scroll" onscroll="mpArrows(this)">'+items.map(riseCardHtml).join('')+'</div>'+
            '<button type="button" class="hp-mp-arrow hp-mp-arrow-r" aria-label="Scroll right" onclick="mpScroll(this,1)"><i class="ri-arrow-right-s-line"></i></button>'+
        '</div></div>';
}
function fillRiseSlots(){
    if(!riseLoaded) return;
    document.querySelectorAll('.hp-rise-slot:not([data-filled])').forEach(function(slot){
        slot.dataset.filled = '1';
        var fresh = window._risePool.filter(function(a){ return !riseShown[a.id]; });
        if(fresh.length < 3){ slot.remove(); return; }
        var pick = mpShuffle(fresh).slice(0, RISE_COUNT);
        pick.forEach(function(a){ riseShown[a.id] = 1; });
        slot.outerHTML = riseRowHtml(pick);
    });
    requestAnimationFrame(function(){ document.querySelectorAll('.hp-mp-scroll').forEach(mpArrows); });
}

/* ═══════════ COLLECTIONS ROW (in the feed) ═══════════ */
(function(){
    var ALL = [];
    var visibleCount = 3;

    function esc2(s){
        if(s===null||s===undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtNum2(n){
        n=parseInt(n)||0;
        if(n>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M';
        if(n>=1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'')+'K';
        return n;
    }
    function shuffle2(a){
        a=a.slice();
        for(var i=a.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)),t=a[i]; a[i]=a[j]; a[j]=t; }
        return a;
    }

    function rowHtml(c){
        var thumbFile = c.thumb ? ('uploads/'+c.thumb.file) : null;
        var thumbHtml;
        if(thumbFile && String(c.thumb.type).toLowerCase() === 'video'){
            thumbHtml = '<video class="hp-coll-thumb" src="'+esc2(thumbFile)+'" muted playsinline preload="metadata" onerror="this.outerHTML=\'<div class=&quot;hp-coll-thumb hp-coll-thumb-empty&quot;><i class=&quot;ri-folder-3-line&quot;></i></div>\'"></video>';
        } else if(thumbFile){
            thumbHtml = '<img class="hp-coll-thumb" src="'+esc2(thumbFile)+'" onerror="this.outerHTML=\'<div class=&quot;hp-coll-thumb hp-coll-thumb-empty&quot;><i class=&quot;ri-folder-3-line&quot;></i></div>\'">';
        } else {
            thumbHtml = '<div class="hp-coll-thumb hp-coll-thumb-empty"><i class="ri-folder-3-line"></i></div>';
        }
        var visLabel = c.isPublic ? 'Public' : 'Private';
        var itemWord = c.itemCount === 1 ? 'item' : 'items';
        return '<div class="hp-coll-row" onclick="location.href=\'profile.php?user_id='+c.ownerId+'&open_col='+c.id+'\'">'+
            thumbHtml+
            '<div class="hp-coll-info">'+
                '<div class="hp-coll-name">'+esc2(c.name)+'</div>'+
                '<div class="hp-coll-meta"><span class="hp-coll-owner">by '+esc2(c.ownerUsername)+'</span><span>·</span><span><span class="hp-coll-count">'+fmtNum2(c.itemCount)+'</span> '+itemWord+'</span><span class="hp-coll-vis">'+visLabel+'</span></div>'+
            '</div>'+
            '<i class="ri-arrow-right-s-line hp-coll-chevron"></i>'+
        '</div>';
    }

    function skelRowHtml(){
        return '<div class="hp-coll-skel-row">'+
            '<div class="hp-coll-skel-thumb"></div>'+
            '<div class="hp-coll-skel-info">'+
                '<div class="hp-coll-skel-line w1"></div>'+
                '<div class="hp-coll-skel-line w2"></div>'+
            '</div>'+
        '</div>';
    }

    function footerHtml(){
        var hasMore = visibleCount < ALL.length;
        var canLess = visibleCount > 3;
        if(!hasMore && !canLess) return '';
        var html = '<div class="hp-coll-more-row">';
        if(hasMore) html += '<button type="button" class="hp-coll-more-btn" onclick="hpCollShowMore()"><i class="ri-arrow-down-s-line"></i> See more</button>';
        if(canLess) html += '<button type="button" class="hp-coll-less-btn" onclick="hpCollShowLess()"><i class="ri-arrow-up-s-line"></i> See less</button>';
        html += '</div>';
        return html;
    }

    function render(container){
        if(!container) return;
        var visible = ALL.slice(0, visibleCount);
        container.innerHTML = '<div class="hp-coll-head"><div class="hp-coll-kicker">Collections</div><div class="hp-coll-title">Discover Collections</div></div>'+
            visible.map(rowHtml).join('')+
            footerHtml();
    }

    function renderWithSkeleton(container, newCount){
        if(!container) return;
        var currentlyShown = ALL.slice(0, visibleCount);
        var skelHtml = '';
        for(var i=0;i<newCount;i++) skelHtml += skelRowHtml();

        container.innerHTML = '<div class="hp-coll-head"><div class="hp-coll-kicker">Collections</div><div class="hp-coll-title">Discover Collections</div></div>'+
            currentlyShown.map(rowHtml).join('')+
            skelHtml;

        setTimeout(function(){
            render(document.getElementById('hpCollBlock'));
        }, 380);
    }

    window.hpCollShowMore = function(){
        var nextCount = Math.min(ALL.length, visibleCount + 3);
        var added = nextCount - visibleCount;
        if(added <= 0){ visibleCount = nextCount; render(document.getElementById('hpCollBlock')); return; }
        visibleCount = nextCount;
        renderWithSkeleton(document.getElementById('hpCollBlock'), added);
    };
    window.hpCollShowLess = function(){ visibleCount = 3; render(document.getElementById('hpCollBlock')); };

    window._hpCollMount = function(list){
        var block = document.querySelector('.hp-coll-slot:not([data-filled])');
        if(!block){ window._hpCollPending = list; return; }
        ALL = shuffle2(list || []);
        visibleCount = 3;
        block.dataset.filled = '1';
        if(!ALL.length){ block.remove(); return; }
        block.outerHTML = '<div class="hp-coll" id="hpCollBlock"></div>';
        render(document.getElementById('hpCollBlock'));
    };

    fetch('?type=collections').then(function(r){ return r.json(); })
        .then(function(list){ list = Array.isArray(list) ? list : []; window._hpCollMount(list); })
        .catch(function(){ window._hpCollMount([]); });
})();

/* ═══════════ RIGHT COLUMN — Sponsored ads ═══════════ */
(function(){
    var listEl = document.getElementById('rsAdsList');
    var sectionEl = document.getElementById('rsAdsSection');
    if(!listEl) return;

    function esc3(s){
        if(s===null||s===undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function cardHtml(a){
        var type = (a.type||'').toLowerCase();
        var media = (type === 'video')
            ? '<video class="rs-ad-thumb" src="'+esc3(a.file)+'" muted autoplay loop playsinline disablepictureinpicture onerror="this.style.visibility=\'hidden\'"></video>'
            : '<img class="rs-ad-thumb" src="'+esc3(a.file)+'" alt="" onerror="this.style.visibility=\'hidden\'">';
        var caption = a.caption ? a.caption : (a.ctaText || 'Sponsored');
        return '<a class="rs-ad-card" href="'+esc3(a.ctaUrl||'#')+'" target="_blank" rel="noopener sponsored">'+
            media+
            '<div class="rs-ad-info">'+
                '<div class="rs-ad-caption">'+esc3(caption)+'</div>'+
                '<div class="rs-ad-tag">Ad</div>'+
            '</div>'+
        '</a>';
    }

    fetch('?type=sidebar_ads').then(function(r){ return r.json(); }).then(function(list){
        list = Array.isArray(list) ? list.slice(0,3) : [];
        if(!list.length){ if(sectionEl) sectionEl.style.display='none'; return; }
        listEl.innerHTML = list.map(cardHtml).join('');
    }).catch(function(){ if(sectionEl) sectionEl.style.display='none'; });
})();

/* ═══════════ FEED ENGINE ═══════════ */
(function(){
    var FEED=peuraFeed, BATCH=4, SUGG_EVERY=10, SUGG_COUNT=8;
    var listEl=document.getElementById('ipvList');
    var rendered=0, loadingBatch=false, sentinelObs=null, postObs=null;
    var visibility={}, switchers={}, ctaTimers={}, actionLock={};
    var curAudioPost=null, trackMuted=true, originMemeId=null;
    var shownSugg=new Set(), suggLoaded=false, lastSuggOrder='';

    var COLL_INSERT_AFTER = Math.min(4, Math.max(1, FEED.length));
    var collInserted = false;

    /* ═══ PAGING ═══
       Page 1 is embedded by PHP in peuraFeed. When the reader reaches the end of it, the next page is fetched from
       ?feed_json=1&page=N&seed=S (same filter / tag / q as this page) and appended to FEED.
       The seed keeps the shuffle identical across pages. Posts already shown are skipped. */
    var PAGING = {
        page:    <?= (int)$page ?>,
        hasMore: <?= !empty($has_more) ? 'true' : 'false' ?>,
        seed:    <?= (int)$feed_seed ?>,
        asof:    <?= (int)$feed_asof ?>,
        fetching:false, fails:0
    };
    var feedIds = {}; FEED.forEach(function(p){ feedIds[String(p.id)] = 1; });

    /* the ⋯ post menu reads its data from a global that PHP prints ($_kebab_data). Add the new pages' entries to it.
       If your kebab variable has a different name, put it in this list. */
    var KEBAB_VARS = ['peuraKebab','indexKebabData','kebabData','_kebabData'];
    function mergeKebab(k){
        if(!k) return;
        for(var i=0;i<KEBAB_VARS.length;i++){
            var store = window[KEBAB_VARS[i]];
            if(store && typeof store === 'object'){ Object.keys(k).forEach(function(id){ store[id] = k[id]; }); return; }
        }
        console.warn('home script: kebab data variable not found — the ⋯ menu may not work on posts from page 2+. Add its name to KEBAB_VARS.');
    }

    function removeSentinel(){
        var s=document.getElementById('ipvSentinel'); if(s) s.remove();
        if(sentinelObs){ sentinelObs.disconnect(); sentinelObs=null; }
    }

    function fetchMore(){
        if(PAGING.fetching || !PAGING.hasMore) return;
        PAGING.fetching = true;
        var qs = new URLSearchParams(location.search);
        qs.delete('type');
        qs.set('feed_json','1'); qs.set('page', String(PAGING.page + 1)); qs.set('seed', String(PAGING.seed)); qs.set('asof', String(PAGING.asof));
        fetch(location.pathname + '?' + qs.toString(), {credentials:'same-origin', headers:{'Accept':'application/json'}})
        .then(function(r){ if(!r.ok) throw new Error('http '+r.status); return r.json(); })
        .then(function(d){
            PAGING.fetching = false; PAGING.fails = 0;
            PAGING.page    = d.page || (PAGING.page + 1);
            PAGING.hasMore = !!d.hasMore;
            mergeKebab(d.kebab);
            var added = 0;
            (d.feed || []).forEach(function(p){
                if(!feedIds[String(p.id)]){ feedIds[String(p.id)] = 1; FEED.push(p); added++; }
            });
            if(added) renderBatch(BATCH, true);
            else if(PAGING.hasMore) fetchMore();     /* a whole page of already-seen posts → try the next one */
            else removeSentinel();
        })
        .catch(function(){
            PAGING.fetching = false; PAGING.fails++;
            if(PAGING.fails < 3) setTimeout(ensureSentinel, 1500 * PAGING.fails);   /* retry a couple of times, then stop quietly */
            else removeSentinel();
        });
    }

    window._suggPromise=fetch('?type=sugg').then(function(r){ return r.json(); })
        .then(function(u){ window.cmSuggUsers=Array.isArray(u)?u:[]; suggLoaded=true; fillSuggSlots(); return window.cmSuggUsers; })
        .catch(function(){ window.cmSuggUsers=[]; suggLoaded=true; fillSuggSlots(); return []; });

    function suggRowHtml(users){
        var cards=users.map(function(u){
            var uid=parseInt(u.id,10), following=parseInt(u.is_following)>0, priv=!!parseInt(u.is_fully_private||0), on=!!parseInt(u.is_online||0);
            var btn=(!priv||following)?'<button type="button" class="track-btn hp-sugg-btn'+(following?' following':'')+'" data-user="'+uid+'" onclick="event.stopPropagation();handleTrackBtn(this)">'+(following?'Untrack':'Track')+'</button>':'';
            var photo='<img class="hp-sugg-photo" src="'+esc(u.profile_pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'">';
            return '<div class="hp-sugg-card" data-uid="'+uid+'"><a href="profile.php?user_id='+uid+'" class="hp-sugg-aw-link" style="display:block">'+avWrap(photo,uid,on,'hp-sugg-aw')+'</a>'+
                '<div class="hp-sugg-info"><a class="hp-sugg-name" href="profile.php?user_id='+uid+'">'+esc(u.username)+'</a><div class="hp-sugg-reason">'+esc(u.reason||'')+'</div></div>'+
                '<div class="hp-sugg-btns">'+btn+'<button type="button" class="hp-sugg-rm" onclick="hpSuggRemove(this)">Remove</button></div></div>';
        }).join('');
        return '<div class="hp-sugg"><div class="hp-sugg-head"><div><div class="hp-sugg-kicker">Suggested for you</div><div class="hp-sugg-title">People you may like</div></div><button type="button" class="hp-sugg-x" aria-label="Hide suggestions" onclick="hpSuggClose(this)"><i class="ri-close-line"></i></button></div>'+
            '<div class="hp-sugg-scroll-wrap">'+
                '<button type="button" class="hp-sugg-arrow hp-sugg-arrow-l hidden" aria-label="Scroll left" onclick="hpSuggScroll(this,-1)"><i class="ri-arrow-left-s-line"></i></button>'+
                '<div class="hp-sugg-scroll" onscroll="hpSuggArrows(this)">'+cards+'</div>'+
                '<button type="button" class="hp-sugg-arrow hp-sugg-arrow-r" aria-label="Scroll right" onclick="hpSuggScroll(this,1)"><i class="ri-arrow-right-s-line"></i></button>'+
            '</div></div>';
    }

    function shuffle(a){
        a=a.slice();
        for(var i=a.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)), t=a[i]; a[i]=a[j]; a[j]=t; }
        return a;
    }
    function pickSugg(){
        var pool=(window.cmSuggUsers||[]).filter(function(u){ return !window._suggRemoved.has(String(u.id)); });
        if(!pool.length) return [];
        var want=Math.min(SUGG_COUNT,pool.length);
        var fresh=pool.filter(function(u){ return !shownSugg.has(String(u.id)); });
        if(fresh.length<want){ shownSugg.clear(); fresh=pool; }
        var pick=[], order='';
        for(var t=0;t<8;t++){
            pick=shuffle(fresh).slice(0,want);
            if(pick.length<want){
                var ids=new Set(pick.map(function(u){ return String(u.id); }));
                shuffle(pool).forEach(function(u){ if(pick.length<want && !ids.has(String(u.id))){ pick.push(u); ids.add(String(u.id)); } });
            }
            order=pick.map(function(u){ return u.id; }).join(',');
            if(pool.length<2 || order!==lastSuggOrder) break;
        }
        lastSuggOrder=order;
        pick.forEach(function(u){ shownSugg.add(String(u.id)); });
        return pick;
    }
    function fillSuggSlots(){
        if(!suggLoaded) return;
        document.querySelectorAll('.hp-sugg-slot:not([data-filled])').forEach(function(slot){
            slot.dataset.filled='1';
            var picked=pickSugg();
            if(!picked.length){ slot.remove(); return; }
            slot.outerHTML=suggRowHtml(picked);
        });
        requestAnimationFrame(function(){
            document.querySelectorAll('.hp-sugg-scroll').forEach(function(s){ if(!s.dataset.init){ s.dataset.init='1'; s.scrollLeft=0; } hpSuggArrows(s); });
        });
    }

    /* ---- batch renderer ---- */
    function renderBatch(count,animate){
        var end=Math.min(rendered+count,FEED.length), html='';
        for(var i=rendered;i<end;i++){
            html+=renderPost(FEED[i]);
            if((i+1)%SUGG_EVERY===0) html+='<div class="hp-sugg-slot"></div>';
            if((i+1)%MP_EVERY===0) html+='<div class="hp-mp hp-mp-slot">'+mpSkeletonRowInner()+'</div>';
            if((i+1)%RISE_EVERY===RISE_AT) html+='<div class="hp-rise hp-rise-slot"></div>';
            if(!collInserted && (i+1)===COLL_INSERT_AFTER){ html+='<div class="hp-coll hp-coll-slot"></div>'; collInserted=true; }
        }
        var tmp=document.createElement('div'); tmp.innerHTML=html;
        var frag=document.createDocumentFragment(), newPosts=[];
        Array.prototype.slice.call(tmp.children).forEach(function(n){
            if(n.classList.contains('ipv-post')){ newPosts.push(n); if(animate) n.classList.add('ipv-post-enter'); }
            frag.appendChild(n);
        });
        var sentinel=document.getElementById('ipvSentinel');
        if(sentinel) listEl.insertBefore(frag,sentinel); else listEl.appendChild(frag);
        rendered=end;

        bindCarousels(newPosts); initSwitchers(newPosts); initCtaCycles(newPosts); observePosts(newPosts); hpLoadCommentCounts(newPosts);
        fillSuggSlots(); fillMpSlots(); fillRiseSlots();
        if(window._hpCollPending){ window._hpCollMount(window._hpCollPending); window._hpCollPending=null; }

        if(rendered>=FEED.length && !PAGING.hasMore) removeSentinel();   /* really the end of the feed */
        else ensureSentinel();                                           /* more to show, or more pages to fetch */
    }
    function ensureSentinel(){
        var s=document.getElementById('ipvSentinel');
        if(!s){ s=document.createElement('div'); s.id='ipvSentinel'; s.className='ipv-load-more'; s.innerHTML='<div class="ipv-load-more-dot"></div><div class="ipv-load-more-dot"></div><div class="ipv-load-more-dot"></div>'; listEl.appendChild(s); }
        if(sentinelObs) sentinelObs.disconnect();
        if(!('IntersectionObserver' in window)){ if(rendered<FEED.length) renderBatch(FEED.length,false); return; }
        sentinelObs=new IntersectionObserver(function(es){
            es.forEach(function(e){
                if(!e.isIntersecting || loadingBatch) return;
                if(rendered<FEED.length){
                    loadingBatch=true;
                    setTimeout(function(){ renderBatch(BATCH,true); loadingBatch=false; },110);
                } else if(PAGING.hasMore){
                    fetchMore();
                }
            });
        },{rootMargin:'600px 0px 600px 0px'});
        sentinelObs.observe(s);
    }

    /* ---- IMPRESSIONS + DWELL TIME ----
       "Seen"   : a post counts as seen once ≥60% of it has been on screen for 1 second.
       "Skip"   : it was on screen (≥60%) for 0.25–1 s and you moved on without reacting to it.
       "Linger" : it stayed on screen for 3 s or more.
       Everything is reported in small batches; the server uses it to stop showing you the same posts again,
       to learn your taste a little, and to judge how well each post performs per viewer. ---- */
    var impTimers={}, impQueue=[], impSent={};
    var visStart={}, dwDone={}, dwQueue=[];
    var DW_MIN=250, DW_SKIP_MAX=1000, DW_LINGER=3000;     /* milliseconds — same thresholds the server uses (dz_rank.php) */

    function dwLeave(post,noSkip){
        var id=post.dataset.id, t=visStart[id];
        if(!t) return; delete visStart[id];
        var ms=Date.now()-t;
        if(ms<DW_MIN || (ms>=DW_SKIP_MAX && ms<DW_LINGER)) return;                         /* nothing worth reporting */
        if(ms<DW_SKIP_MAX){
            if(noSkip) return;                                                             /* switching tabs is not skipping */
            if(post.querySelector('.like-btn.active,.repost-btn.active,.fav-btn.active')) return;   /* you reacted to it → not a skip */
        }
        dwDone[id]=1; dwQueue.push(id+':'+Math.min(ms,60000));
        if(dwQueue.length>=10) flushImpressions(false);
    }
    function impVisible(post,on){
        var id=post.dataset.id;
        if(!id || post.querySelector('.ipv-ad-tag')) return;
        if(on){ if(!visStart[id] && !dwDone[id]) visStart[id]=Date.now(); }
        else dwLeave(post,false);
        if(impSent[id]) return;
        if(on){
            if(!impTimers[id]) impTimers[id]=setTimeout(function(){
                delete impTimers[id]; impSent[id]=1; impQueue.push(id);
                if(impQueue.length>=10) flushImpressions(false);
            },1000);
        } else if(impTimers[id]){ clearTimeout(impTimers[id]); delete impTimers[id]; }
    }
    /* tapping a post opens it in the zeems modal: that is interest, not a skip, and time spent inside the modal is not feed time */
    function dwPause(){ visStart={}; }
    function dwResume(){
        var now=Date.now();
        document.querySelectorAll('#ipvList .ipv-post').forEach(function(p){
            var id=p.dataset.id;
            if(id && (visibility[id]||0)>=.6 && !visStart[id] && !dwDone[id] && !p.querySelector('.ipv-ad-tag')) visStart[id]=now;
        });
    }
    function dwFinalizeAll(){
        Object.keys(visStart).forEach(function(id){
            var p=document.querySelector('.ipv-post[data-id="'+id+'"]');
            if(p) dwLeave(p,true); else delete visStart[id];
        });
    }
    function flushImpressions(useBeacon){
        while(impQueue.length || dwQueue.length){
            var body='action=impressions&ids='+encodeURIComponent(impQueue.splice(0,50).join(','));
            if(dwQueue.length) body+='&dw='+encodeURIComponent(dwQueue.splice(0,50).join(','));
            try{
                if(useBeacon && navigator.sendBeacon) navigator.sendBeacon('text_feed.php', new Blob([body],{type:'application/x-www-form-urlencoded'}));
                else fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body,keepalive:true}).catch(function(){});
            }catch(err){}
        }
    }
    setInterval(function(){ flushImpressions(false); },5000);
    document.addEventListener('visibilitychange',function(){
        if(document.hidden){ dwFinalizeAll(); flushImpressions(true); } else dwResume();
    });
    window.addEventListener('pagehide',function(){ dwFinalizeAll(); flushImpressions(true); });

    /* ---- autoplay on scroll + attached-track sync ---- */
    function observePosts(posts){
        if(!('IntersectionObserver' in window)) return;
        if(!postObs){
            postObs=new IntersectionObserver(function(es){
                es.forEach(function(e){
                    var post=e.target; visibility[post.dataset.id]=e.intersectionRatio;
                    impVisible(post, e.isIntersecting && e.intersectionRatio>=.6);
                    var v=hpActiveVideo(post);
                    if(e.isIntersecting&&e.intersectionRatio>.6){ hpApplyVideoSound(post); if(v) v.play().catch(function(){}); }
                    else post.querySelectorAll('video.ipv-video').forEach(function(x){ x.pause(); });
                });
                syncAudioFocus();
            },{threshold:[0,.6,1]});
        }
        posts.forEach(function(p){ postObs.observe(p); });
    }
    function syncAudioFocus(){
        var bestId=null,best=0;
        Object.keys(visibility).forEach(function(id){ if(visibility[id]>best){ best=visibility[id]; bestId=id; } });
        var a=document.getElementById('ipvAudioPlayer');
        if(best<.6||!bestId){ stopAudio(); return; }
        if(bestId===curAudioPost) return;
        var post=document.querySelector('.ipv-post[data-id="'+bestId+'"]'); if(!post) return;
        var url=post.dataset.audio;
        curAudioPost=bestId;
        if(!url){ a.pause(); return; }
        if(a.getAttribute('src')!==url){ a.src=url; a.currentTime=0; }
        a.muted=trackMuted; a.volume=(parseInt(post.dataset.musicVol,10)||80)/100;
        a.play().catch(function(){});
        var vv=(parseInt(post.dataset.videoVol,10)||40)/100;
        post.querySelectorAll('video.ipv-video').forEach(function(v){ v.volume=vv; v.muted=trackMuted; });
        updateTrackMuteIcons();
    }
    function stopAudio(){ var a=document.getElementById('ipvAudioPlayer'); if(a) a.pause(); curAudioPost=null; }
    function pauseAllVideos(){ document.querySelectorAll('#ipvList video').forEach(function(v){ v.pause(); }); }
    function resumeFocused(){
        var mid=window.innerHeight/2;
        document.querySelectorAll('#ipvList .ipv-post').forEach(function(post){
            var r=post.getBoundingClientRect();
            if(r.top<mid&&r.bottom>mid){ var v=hpActiveVideo(post); hpApplyVideoSound(post); if(v) v.play().catch(function(){}); }
        });
        syncAudioFocus();
    }

    function initSwitchers(posts){
        posts.forEach(function(post){
            var sw=post.querySelector('[data-switcher="1"]'); if(!sw) return;
            var panes=sw.querySelectorAll('.ipv-switch-pane'); if(panes.length<2) return;
            var i=0, id=post.dataset.id;
            switchers[id]=setInterval(function(){ panes[i].classList.remove('active'); i=(i+1)%panes.length; panes[i].classList.add('active'); },5000);
        });
    }
    function initCtaCycles(posts){ /* the ad button is now a steady bar under the picture: nothing to cycle */ }

    var MULTI_RATIO=3.5/4, MULTI_TOL=.15, LONG_RATIO=1.2;
    window.hpMeasure=function(el){
        var wrap=el.closest('.ipv-media-wrap'); if(!wrap) return;
        var w=el.tagName==='VIDEO'?el.videoWidth:el.naturalWidth, h=el.tagName==='VIDEO'?el.videoHeight:el.naturalHeight;
        if(!w||!h) return;
        var ratio=h/w; el.dataset.ratio=ratio;
        if(wrap.classList.contains('ipv-multi')){
            var fc=Math.abs(ratio-MULTI_RATIO)/MULTI_RATIO<=MULTI_TOL; el.classList.toggle('ipv-fit-cover',fc); el.style.setProperty('object-fit',fc?'cover':'contain','important');
            return;
        }
        if(parseInt(el.dataset.idx||'0',10)===parseInt(wrap.dataset.activeIdx||'0',10)) applyAspect(wrap,ratio);
    };
    /* safety net (same result as the immersive viewer): measure every picture / video that is ALREADY loaded — a cached image can finish before its onload is attached, and then a tall or wide photo never got its fit */
    var sweepQueued=false;
    function hpSweep(){
        sweepQueued=false;
        document.querySelectorAll('#ipvList .ipv-media-item img, #ipvList .ipv-media-item video').forEach(function(el){
            if(el.dataset.ratio) return;
            if(el.tagName==='VIDEO' ? el.readyState>=1 : (el.complete&&el.naturalWidth)) window.hpMeasure(el);
        });
    }
    function queueSweep(){ if(sweepQueued) return; sweepQueued=true; requestAnimationFrame(hpSweep); }
    document.addEventListener('DOMContentLoaded',queueSweep); window.addEventListener('load',queueSweep);
    (function(){ var host=document.getElementById('ipvList'); if(host&&window.MutationObserver) new MutationObserver(queueSweep).observe(host,{childList:true,subtree:true}); })();
    function applyAspect(wrap,ratio){
        var c=Math.max(.55,Math.min(ratio,1.6)), width=wrap.clientWidth||wrap.offsetWidth; if(!width) return;
        wrap.style.aspectRatio='auto'; wrap.style.height=Math.round(width*c)+'px';
        var post=wrap.closest('.ipv-post'); if(post) post.classList.toggle('ipv-long-header',c>=LONG_RATIO);
    }
    window.addEventListener('resize',function(){
        document.querySelectorAll('#ipvList .ipv-media-wrap:not(.ipv-multi):not(.ipv-textonly)').forEach(function(wrap){
            var i=parseInt(wrap.dataset.activeIdx||'0',10);
            var el=wrap.querySelector('.ipv-media-item[data-idx="'+i+'"] img, .ipv-media-item[data-idx="'+i+'"] video');
            if(el&&el.dataset.ratio) applyAspect(wrap,parseFloat(el.dataset.ratio));
        });
    });

    function bindCarousels(posts){
        posts.forEach(function(post){
            var wrap=post.querySelector('.ipv-media-wrap[data-count]'); if(!wrap) return;
            var count=parseInt(wrap.dataset.count,10); if(count<=1) return;
            var track=wrap.querySelector('.ipv-media-track'), dots=wrap.querySelectorAll('.ipv-dot'), counter=wrap.querySelector('.ipv-media-counter');
            var idx=0,startX=0,startY=0,dx=0,dragging=false,lock=null;
            var DEAD=12;
            function goTo(n){
                idx=Math.max(0,Math.min(n,count-1)); wrap.dataset.activeIdx=String(idx);
                track.style.transform='translateX(-'+(idx*100)+'%)';
                dots.forEach(function(d,i){ d.classList.toggle('active',i===idx); });
                if(counter) counter.textContent=(idx+1)+'/'+count;
                wrap.querySelectorAll('.ipv-media-item video').forEach(function(v){ var vi=parseInt(v.dataset.idx,10); if(vi!==idx) v.pause(); });
                hpApplyVideoSound(post);          /* muted state of the new slide follows the unmute choice; every other video stays silent */
                wrap.querySelectorAll('.ipv-media-item video').forEach(function(v){ if(parseInt(v.dataset.idx,10)===idx && (visibility[post.dataset.id]||0)>.6) v.play().catch(function(){}); });
                var vm=wrap.querySelector('.ipv-video-mute-btn');
                if(vm) vm.style.display=(wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"] video')||post.dataset.audio)?'flex':'none';
                var tt=wrap.querySelector('.ipv-type-tag'), it=wrap.querySelector('.ipv-media-item[data-idx="'+idx+'"]');
                if(tt&&it){ var info=typeTagInfo(it.dataset.type||'image'); tt.innerHTML='<i class="'+info.icon+'"></i><span>'+info.label+'</span>'; }
            }
            wrap._ipvGoTo=function(d){ goTo(idx+d); };

            wrap.addEventListener('touchstart',function(e){
                if(e.touches.length!==1){ dragging=false; lock=null; return; }
                startX=e.touches[0].clientX; startY=e.touches[0].clientY;
                dx=0; lock=null; dragging=true;
            },{passive:true});

            wrap.addEventListener('touchmove',function(e){
                if(!dragging) return;
                var rawX=e.touches[0].clientX-startX, rawY=e.touches[0].clientY-startY;
                if(lock===null){
                    if(Math.abs(rawX)<DEAD && Math.abs(rawY)<DEAD) return;
                    lock=(Math.abs(rawX)>Math.abs(rawY)*2)?'h':'v';
                    if(lock==='h') track.style.transition='none';
                }
                
                if(lock!=='h') return;
                dx=rawX>0?rawX-DEAD:rawX+DEAD;
                var atEdge=(idx===0&&dx>0)||(idx===count-1&&dx<0);
                track.style.transform='translateX(calc(-'+(idx*100)+'% + '+(atEdge?dx*0.25:dx)+'px))';
            },{passive:true});

            function endTouch(){
                if(!dragging) return; dragging=false;
                track.style.transition='transform .28s cubic-bezier(.22,1,.36,1)';
                var n=idx;
                if(lock==='h'){
                    var need=Math.min(90,(wrap.clientWidth||300)*0.2);
                    if(Math.abs(dx)>need) n=dx<0?idx+1:idx-1;
                }
                lock=null; dx=0;
                goTo(n);
            }
            wrap.addEventListener('touchend',endTouch,{passive:true});
            wrap.addEventListener('touchcancel',endTouch,{passive:true});
        });
    }
    window.hpArrowNav=function(btn,dir){ var w=btn.closest('.ipv-media-wrap'); if(w&&w._ipvGoTo) w._ipvGoTo(dir); };

    window.hpToggleVideoMute=function(btn){
        var wrap=btn.closest('.ipv-media-wrap'), i=parseInt(wrap.dataset.activeIdx||'0',10), post=btn.closest('.ipv-post');
        var v=wrap.querySelector('.ipv-media-item[data-idx="'+i+'"] video');
        var hasTrack=!!(post && post.dataset.audio), nowMuted;
        if(v){ v.muted=!v.muted; nowMuted=v.muted; }
        else if(hasTrack){ nowMuted=!trackMuted; }
        else return;
        if(!hasTrack && post){ post.dataset.vsound = nowMuted ? '0' : '1'; hpApplyVideoSound(post); }   /* remember it for the next video of this post */
        if(hasTrack){   /* music attached: this one button controls the video's sound and the music together */
            trackMuted=nowMuted;
            var a=document.getElementById('ipvAudioPlayer'); if(a) a.muted=trackMuted;
            wrap.querySelectorAll('video').forEach(function(x){ x.muted=nowMuted; });
            updateTrackMuteIcons();
        }
        btn.querySelector('i').className=nowMuted?'ri-volume-mute-fill':'ri-volume-up-fill';
    };
    window.hpToggleTrackMute=function(){
        var a=document.getElementById('ipvAudioPlayer'); if(!a) return;
        trackMuted=!trackMuted; a.muted=trackMuted; updateTrackMuteIcons();
    };
    function updateTrackMuteIcons(){
        var cls=trackMuted?'ri-volume-mute-fill':'ri-volume-up-fill';
        document.querySelectorAll('#ipvList .ipv-track-mute-btn i, #ipvList .ipv-post[data-audio] .ipv-video-mute-btn i').forEach(function(i){ i.className=cls; });
    }

    window.hpMediaTap=function(e,el,memeId){
        var box=el.closest('.ipv-media-wrap')||el, now=Date.now(), dt=now-(box._lastTap||0);
        if(dt>0&&dt<320){
            box._lastTap=0; if(box._tapTimer){ clearTimeout(box._tapTimer); box._tapTimer=null; }
            doubleTapLike(box,e,memeId);
        } else {
            box._lastTap=now;
            box._tapTimer=setTimeout(function(){ openZeems(memeId); },260);
        }
    };

    /* ═══ OPEN THE ZEEMS MODAL (zeems_modal.php, included above) ═══ */
    function openZeems(memeId){
        var p=FEED.find(function(x){ return String(x.id)===String(memeId); }) || (window._risePool||[]).find(function(x){ return String(x.id)===String(memeId); });
        if(!p) return;
        var post=document.querySelector('.ipv-post[data-id="'+memeId+'"]');
        var wrap=post&&post.querySelector('.ipv-media-wrap[data-count]');
        var mediaIdx=wrap?(parseInt(wrap.dataset.activeIdx||'0',10)||0):0;
        originMemeId=memeId;
        dwPause();
        pauseAllVideos(); stopAudio();

        if(typeof openCardModal!=='function'){
            console.warn('text_feed.php: openCardModal not found — is zeems_modal.php included?');
            return;
        }
        /* open on the same slide the user was viewing in the feed */
        if(typeof _ccmMediaIndexByPost!=='undefined') _ccmMediaIndexByPost[p.id]=mediaIdx;

        openCardModal(p.file, p.type, p.pic, p.userId, p.username, p.caption, memeId);
    }
    window.hpOpenZeems=openZeems;

    /* used by "Not interested": takes a post out of the feed list without shifting the posts still to come */
    window.hpFeedRemoved=function(id){
        var i=FEED.findIndex(function(x){ return String(x.id)===String(id); });
        if(i>-1){ FEED.splice(i,1); if(i<rendered) rendered--; }
    };
    window.hpResumeFocused=function(){ resumeFocused(); };

    /* called by the zeems modal when it closes: pass the id of the post it ended on (optional).
       Also fires when the modal dispatches the "zeems:closed" event. */
    window.hpZeemsClosed=function(landingId){
        syncAllFromFeed();
        dwResume();
        if(landingId!=null && String(landingId)!==String(originMemeId)){
            var el=document.querySelector('#ipvList .ipv-post[data-id="'+landingId+'"]'); if(el && el.dataset.nint!=='1') el.scrollIntoView({block:'start'});
        }
        originMemeId=null;
        setTimeout(resumeFocused,80);
    };
    document.addEventListener('zeems:closed',function(e){ window.hpZeemsClosed(e&&e.detail?(e.detail.memeId!=null?e.detail.memeId:e.detail.id):null); });

    /* zeems_modal.php doesn't announce when it closes, so wrap its close function:
       sync counts/likes back into the feed, jump to the post the modal ended on, resume playback */
    if(typeof closeCardCaptionModal==='function'){
        var _origClose=closeCardCaptionModal;
        window.closeCardCaptionModal=function(){
            var landing=null;
            try{
                var it=(typeof ccmFeed!=='undefined')?ccmFeed[_ccmIndex]:null;
                landing=(it&&it.type==='post')?it.data.id:(typeof _ccmMemeId!=='undefined'?_ccmMemeId:null);
            }catch(e){}
            var r=_origClose.apply(this,arguments);
            window.hpZeemsClosed(landing);
            return r;
        };
    }

    function doubleTapLike(box,e,memeId){
        if(window.dzHaptic) dzHaptic.medium();      /* felt at the tap itself; the like that lands a moment later stays silent */
        var r=box.getBoundingClientRect();
        var cx=(e&&typeof e.clientX==='number'&&e.clientX!==0)?e.clientX:(r.left+r.width/2);
        var cy=(e&&typeof e.clientY==='number'&&e.clientY!==0)?e.clientY:(r.top+r.height/2);
        var post=box.closest('.ipv-post'), b=post?post.querySelector('.like-btn'):null;
        var go=function(){ if(b&&!b.classList.contains('active')){ window._dzHapSkip=true; hpAction(memeId,'like',b); window._dzHapSkip=false; } };
        /* same animation as the Zeems modal: the heart pops where you tapped, jumps to this post's like button, and the like happens when it lands */
        if(typeof ccmShowHeartBurst==='function'){ ccmShowHeartBurst(box,cx-r.left,cy-r.top,go,b?b.querySelector('i'):null); }
        else { heartBurst(box,cx-r.left,cy-r.top); go(); }
    }
    function heartBurst(box,x,y){
        box.querySelectorAll('.ipv-double-tap-heart,.ipv-heart-ring,.ipv-heart-particle').forEach(function(el){ el.remove(); });
        var ring=document.createElement('div'); ring.className='ipv-heart-ring'; ring.style.left=x+'px'; ring.style.top=y+'px'; box.appendChild(ring);
        requestAnimationFrame(function(){ ring.classList.add('show'); }); setTimeout(function(){ ring.remove(); },800);
        var h=document.createElement('div'); h.className='ipv-double-tap-heart'; h.style.left=x+'px'; h.style.top=y+'px'; h.innerHTML='<i class="ri-heart-3-fill"></i>'; box.appendChild(h);
        requestAnimationFrame(function(){ h.classList.add('show'); }); setTimeout(function(){ h.remove(); },950);
        for(var i=0;i<8;i++)(function(i){
            var rad=((360/8)*i+(Math.random()*20-10))*Math.PI/180, dist=60+Math.random()*42;
            var p=document.createElement('div'); p.className='ipv-heart-particle'; p.style.left=x+'px'; p.style.top=y+'px';
            p.style.setProperty('--tx',Math.cos(rad)*dist+'px'); p.style.setProperty('--ty',Math.sin(rad)*dist+'px'); p.style.setProperty('--tr',(Math.random()*80-40)+'deg');
            p.innerHTML='<i class="ri-heart-3-fill" style="font-size:'+(12+Math.random()*10)+'px;"></i>'; box.appendChild(p);
            setTimeout(function(){ requestAnimationFrame(function(){ p.classList.add('show'); }); },40+i*15); setTimeout(function(){ p.remove(); },950+i*15);
        })(i);
    }

    var MAP={like:{a:'toggle_like',c:'likeCount',s:'liked'},repost:{a:'toggle_repost',c:'repostCount',s:'reposted'},fav:{a:'toggle_favorite',c:'favCount',s:'faved'}};
    window.hpAction=function(memeId,type,btn){
        var key=memeId+':'+type; if(actionLock[key]) return; actionLock[key]=true;
        var m=MAP[type], was=btn.classList.contains('active'), next=!was;
        if(window._dzHapSkip){ window._dzHapSkip=false; } else if(window.dzHaptic){ if(type==='fav') dzHaptic.light(); else if(next) dzHaptic.medium(); else dzHaptic.light(); }
        var cntEl=btn.parentNode.querySelector('.ipv-action-count');
        var prev=cntEl?(parseInt((cntEl.textContent||'0').replace(/[^\d.]/g,''))||0):0;
        btn.classList.toggle('active',next);
        var hid=!!(cntEl && cntEl.dataset.hidden==='1');
        if(cntEl && !hid) cntEl.textContent=fmtNum(next?prev+1:Math.max(0,prev-1));
        if(type==='repost'){
            var ic=btn.querySelector('i'); btn.classList.remove('arc-swap-active'); void ic.offsetWidth; btn.classList.add('arc-swap-active');
            ic.addEventListener('animationend',function h(){ btn.classList.remove('arc-swap-active'); ic.removeEventListener('animationend',h); });
        }
        if(type==='fav'){ if(next&&typeof ccmShowSavedToast==='function') ccmShowSavedToast(memeId); else if(!next&&typeof ccmHideSavedToast==='function') ccmHideSavedToast(); }

        fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action='+m.a+'&meme_id='+memeId})
        .then(function(r){ return r.json(); }).then(function(d){
            delete actionLock[key];
            if(d.count===undefined){ btn.classList.toggle('active',was); if(cntEl && !hid) cntEl.textContent=fmtNum(prev); if(type==='fav'&&next&&typeof ccmHideSavedToast==='function') ccmHideSavedToast(); return; }
            var item=FEED.find(function(x){ return String(x.id)===String(memeId); });
            if(item){ if(d.count!==null) item[m.c]=d.count; item[m.s]=next; }
            if(cntEl && !hid && d.count!==null) cntEl.textContent=fmtNum(d.count);
            if(type==='repost' && item && !item.isAd) dzSpMine(item,next);
            if(type==='like'||type==='repost'){ var post=btn.closest('.ipv-post'); if(post&&item) updateSocialRow(post,item); }
            if(type==='repost'){ var _po=btn.closest('.ipv-post'); if(_po&&item) dzSpRefresh(_po,item); }
        }).catch(function(){
            delete actionLock[key]; btn.classList.toggle('active',was); if(cntEl && !hid) cntEl.textContent=fmtNum(prev);
            if(type==='fav'&&next&&typeof ccmHideSavedToast==='function') ccmHideSavedToast();
        });
    };
    /* my repost takes a slot in the strip: it replaces a like (or, if all are reposts, the oldest repost); un-reposting takes it out */
    function dzSpMine(p,on){
        var list=(Array.isArray(p.socialProof)?p.socialProof:[]).filter(function(x){ return String(x.id)!==String(dzMe.id); });
        if(on){
            var bumped=null;
            list.unshift({id:dzMe.id, username:dzMe.username, profile_pic:dzMe.profile_pic, action_type:'repost'});
            while(list.length>4){
                var li=-1; for(var i=list.length-1;i>=0;i--){ if(list[i].action_type==='like'){ li=i; break; } }
                bumped=list.splice(li>=0?li:list.length-1,1)[0];
            }
            p._spBump=bumped;      /* remembered, so un-reposting puts them straight back */
        } else if(p._spBump){
            var b=p._spBump; p._spBump=null;
            if(!list.some(function(x){ return String(x.id)===String(b.id); })){
                if(b.action_type==='repost'){ var at=0; while(at<list.length && list[at].action_type==='repost') at++; list.splice(at,0,b); }
                else list.push(b);
            }
            list=list.slice(0,4);
        }
        p.socialProof=list;
    }
    function dzSpRefresh(post,p){
        var ex=post.querySelector('.ipv-social-proof-strip'), html=dzStripHtml(p);
        if(ex){ if(html) ex.outerHTML=html; else ex.remove(); }
        else if(html){ var w=post.querySelector('.ipv-media-wrap'); if(w) w.insertAdjacentHTML('afterbegin',html); }
    }
    function updateSocialRow(post,p){
        var ex=post.querySelector('.ipv-social-row'); if(ex) ex.remove();
        var html=socialRowHtml(p); if(html){ var a=post.querySelector('.ipv-actions'); if(a) a.insertAdjacentHTML('afterend',html); }
    }

    function syncAllFromFeed(){
        document.querySelectorAll('#ipvList .ipv-post').forEach(function(post){
            var p=FEED.find(function(x){ return String(x.id)===String(post.dataset.id); }); if(!p) return;
            [['like','.like-btn','.like-count','liked','likeCount'],['repost','.repost-btn','.repost-count','reposted','repostCount'],['fav','.fav-btn','.fav-count','faved','favCount']].forEach(function(t){
                var b=post.querySelector(t[1]), c=post.querySelector(t[2]);
                if(b) b.classList.toggle('active',!!p[t[3]]); if(c && c.dataset.hidden!=='1') c.textContent=fmtNum(p[t[4]]||0);
            });
            var sc=post.querySelector('.share-count'); if(sc && sc.dataset.hidden!=='1') sc.textContent=fmtNum(p.shareCount||0);
            updateSocialRow(post,p);
        });
    }
    window.hpSyncFeed=syncAllFromFeed;
    /* haptic tap on the comment button */
    document.addEventListener('click', function(e){ try{ if(window.dzHaptic && e.target.closest && e.target.closest('.comment-btn')) dzHaptic.light(); }catch(x){} }, true);

    window.handleTrackBtn=function(btn){
        var uid=btn.dataset.user;
        if(window.dzHaptic){ if(btn.classList.contains('following')) dzHaptic.light(); else dzHaptic.medium(); }
        fetch('text_feed.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=toggle_follow&user_id='+uid})
        .then(function(r){ return r.json(); }).then(function(d){
            if(d.status!=='ok') return;
            document.querySelectorAll('.track-btn[data-user="'+uid+'"]').forEach(function(b){
                b.textContent=d.following?'Untrack':'Track'; b.classList.toggle('following',!!d.following); b.classList.toggle('tracking',!!d.following);
            });
            FEED.forEach(function(p){ if(String(p.userId)===String(uid)) p.isFollowing=!!d.following; });
        });
    };

    /* ---- empty feed ---- */
    function emptyStateHtml(){
        return '<div class="empty-state">'+
            '<div class="es-title">Nothing to show here</div>'+
            '<p class="es-text">No text posts match this tag or filter yet. Try another one, or be the first to write something.</p>'+
            '<div class="es-tips">'+
                '<div class="es-tip"><i class="ri-hashtag"></i><span>Pick a different tag from the tag bar.</span></div>'+
            '</div>'+
            <?php if($tag !== 'all'): ?>
            '<a class="es-btn" href="<?= htmlspecialchars(hpUrl($filter, 'all'), ENT_QUOTES) ?>">Show all texts</a>'+
            <?php else: ?>
            '<button type="button" class="es-btn" onclick="W.open()">Write a text</button>'+
            <?php endif; ?>
        '</div>';
    }

    if(!FEED.length){
        listEl.innerHTML=emptyStateHtml();
    } else {
        listEl.innerHTML=skeletonHTML(4);
        setTimeout(function(){ listEl.innerHTML=''; renderBatch(BATCH,true); },450);
    }
})();

/* ═══════════ RIGHT COLUMN — collapse cards, See more / See less, people / memepedia cards ═══════════ */
function pfToggle(bar){
    var c = bar && bar.closest ? bar.closest('.pf-card') : null;
    if(!c) return;
    var collapsed = c.classList.toggle('collapsed');
    bar.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
}

function pfMore(btn){
    var list = btn.previousElementSibling;
    if(!list) return;
    var rows  = Array.prototype.filter.call(list.children, function(r){ return r.classList.contains('pf-row'); });
    var init  = parseInt(list.dataset.init, 10)  || 5;
    var step  = parseInt(list.dataset.step, 10)  || 5;
    var shown = parseInt(list.dataset.shown, 10) || init;

    if(btn.dataset.mode === 'less'){
        shown = init;
        rows.forEach(function(r, i){
            r.classList.remove('pf-in');
            r.classList.toggle('pf-hold', i >= shown);
        });
    } else {
        var next = Math.min(rows.length, shown + step);
        rows.forEach(function(r, i){
            if(i >= shown && i < next){ r.classList.remove('pf-hold'); r.classList.add('pf-in'); }
        });
        shown = next;
    }
    list.dataset.shown = shown;
    var done = shown >= rows.length;
    btn.dataset.mode = done ? 'less' : 'more';
    btn.textContent  = done ? 'See less' : 'See more';
}

(function(){
    var INIT = 5;

    function shuffleArr(a){
        a=a.slice();
        for(var i=a.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)),t=a[i]; a[i]=a[j]; a[j]=t; }
        return a;
    }
    function fillCard(cardId, bodyId, countId, rowsHtml){
        var card = document.getElementById(cardId), body = document.getElementById(bodyId), cnt = document.getElementById(countId);
        if(!card || !body) return;
        if(!rowsHtml.length){ card.style.display='none'; return; }
        var html = '<div class="pf-list" data-init="'+INIT+'" data-step="5" data-shown="'+INIT+'">'+
            rowsHtml.map(function(r, i){ return i >= INIT ? r.replace('class="pf-row', 'class="pf-row pf-hold') : r; }).join('')+
            '</div>';
        if(rowsHtml.length > INIT) html += '<button type="button" class="pf-more" data-mode="more" onclick="pfMore(this)">See more</button>';
        body.innerHTML = html;
        if(cnt) cnt.textContent = String(rowsHtml.length);
        card.style.display = '';
    }

    /* ── People you may like (with green dot) ── */
    function peopleRow(u){
        var uid = parseInt(u.id, 10), following = parseInt(u.is_following) > 0, priv = !!parseInt(u.is_fully_private || 0), on = !!parseInt(u.is_online || 0);
        var btn = (!priv || following)
            ? '<button type="button" class="track-btn pf-track'+(following?' following':'')+'" data-user="'+uid+'" onclick="handleTrackBtn(this)">'+(following?'Untrack':'Track')+'</button>'
            : '';
        var img = '<img class="pf-av" src="'+esc(u.profile_pic||'uploads/mee.jpeg')+'" onerror="this.src=\'uploads/mee.jpeg\'" alt="">';
        return '<div class="pf-row">'+
            '<a href="profile.php?user_id='+uid+'">'+avWrap(img, uid, on)+'</a>'+
            '<div class="pf-row-info"><a class="pf-row-t" href="profile.php?user_id='+uid+'">'+esc(u.username)+'</a>'+
            '<div class="pf-row-s">'+esc(u.reason||'Suggested for you')+'</div></div>'+btn+'</div>';
    }
    (window._suggPromise || Promise.resolve([])).then(function(users){
        users = shuffleArr((users || []).filter(function(u){ return !window._suggRemoved.has(String(u.id)); })).slice(0, 15);
        fillCard('rsPeopleCard', 'rsPeopleBody', 'rsPeopleCount', users.map(peopleRow));
    });

    /* ── Memepedia featured — only 3 shown ── */
    function mpRow(a){
        var meta = mpStatusMeta[a.status] || mpStatusMeta.trending;
        var mt = String(a.mediaType || '').toLowerCase(), thumb;
        if(a.mediaUrl && mt === 'video') thumb = '<video class="pf-av sq" src="'+esc(a.mediaUrl)+'#t=0.1" muted playsinline preload="metadata"></video>';
        else if(a.mediaUrl) thumb = '<img class="pf-av sq" src="'+esc(a.mediaUrl)+'" alt="" loading="lazy" onerror="this.outerHTML=\'<div class=&quot;pf-av sq&quot;><i class=&quot;ri-book-open-line&quot;></i></div>\'">';
        else thumb = '<div class="pf-av sq"><i class="ri-book-open-line"></i></div>';
        return '<div class="pf-row clickable" onclick="mpOpen('+a.id+')">'+thumb+
            '<div class="pf-row-info"><div class="pf-row-t">'+esc(a.title)+'</div>'+
            '<div class="pf-row-s">'+esc(meta.label)+' · '+fmtNum(a.views||0)+' views</div></div></div>';
    }
    (window._mpPromise || Promise.resolve([])).then(function(arts){
        arts = shuffleArr(arts || []).slice(0, 3);
        fillCard('rsMpCard', 'rsMpBody', 'rsMpCount', arts.map(mpRow));
    });
})();

/* ═══════════ GREEN DOT — keep "Active now" fresh every 60s (also acts as a heartbeat) ═══════════ */
(function(){
    function poll(){
        if(document.hidden) return;
        var wraps=document.querySelectorAll('.av-wrap[data-uid]'), seen={}, ids=[];
        wraps.forEach(function(w){ var id=w.dataset.uid; if(id && !seen[id] && ids.length<300){ seen[id]=1; ids.push(id); } });
        fetch('?type=online&ids='+ids.join(',')).then(function(r){ return r.json(); }).then(function(list){
            var on={}; (list||[]).forEach(function(i){ on[String(i)]=1; });
            wraps.forEach(function(w){
                var dot=w.querySelector('.on-dot'), isOn=!!on[w.dataset.uid];
                if(isOn && !dot) w.insertAdjacentHTML('beforeend','<i class="on-dot"></i>');
                else if(!isOn && dot) dot.remove();
            });
        }).catch(function(){});
    }
    setInterval(poll,60000);
    document.addEventListener('visibilitychange',function(){ if(!document.hidden) poll(); });
})();


/* ═══════════ WRITE A TEXT — posts through upload.php so tag / @mention rules are enforced there ═══════════ */
window.W = (function(){
    var $ = function(id){ return document.getElementById(id); };
    var eh = function(v){ return String(v==null?'':v).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
    var MAX = 2200, tags = [], tagLast = [], tagTimer = null, tagSeq = 0, busy = false;

    function lock(){ document.body.style.overflow = document.querySelector('.wsh.open') ? 'hidden' : ''; }
    function tagPic(u){ return u && u.pic ? u.pic : 'uploads/default.jpg'; }

    function open(){
        if(!window.WT_LOGGED){ location.href = 'login.php'; return; }
        $('w-sheet').classList.add('open'); lock();
        var c = $('w-caption'); setTimeout(function(){ c.focus({preventScroll:true}); }, 80);
    }
    function close(){ $('w-sheet').classList.remove('open'); lock(); }

    function cap(){ return $('w-caption').innerText.replace(/\u00a0/g,' '); }
    function sync(){
        var t = cap(), p = $('w-preview');
        $('w-cnt').textContent = t.length + '/' + MAX;
        p.innerHTML = '<div class="w-prev-in">'+eh(t)+'</div><span class="w-prev-label">Text post</span>';
    }
    function hash(){
        var c = $('w-caption'); c.focus();
        document.execCommand('insertText', false, (c.innerText.trim() && !/\s$/.test(c.innerText) ? ' ' : '') + '#');
        sync();
    }
    function partner(){
        var on = $('w-partner').checked;
        $('w-brand-wrap').style.display = on ? 'block' : 'none';
        if(!on) $('w-brand').value = ''; else $('w-brand').focus();
    }

    /* tag people */
    function tagOpen(){
        $('w-tag').classList.add('open'); lock(); $('w-tagq').value = ''; tagLast = [];
        tagHint('Search a username or nickname to tag someone.'); tagRender();
    }
    function tagClose(){ $('w-tag').classList.remove('open'); lock(); }
    function tagHint(m){ $('w-tagres').innerHTML = '<div class="w-empty"><i class="ri-user-search-line"></i>'+eh(m)+'</div>'; }
    function tagRender(){
        $('w-tagsel').innerHTML = tags.length
            ? tags.map(function(u){ return '<span class="w-tg-chip"><img src="'+eh(tagPic(u))+'" alt="" onerror="this.src=\'uploads/default.jpg\'"><span>@'+eh(u.username)+'</span><button type="button" aria-label="Remove tag" onclick="W.tagRemove('+u.id+')">✕</button></span>'; }).join('')
            : '<span class="w-hint" style="margin:0">Nobody tagged yet.</span>';
        $('w-tagval').textContent = tags.length ? (tags.length === 1 ? '@'+tags[0].username : tags.length+' people') : 'None';
        if(!tagLast.length) return;
        var have = {}; tags.forEach(function(u){ have[u.id] = 1; });
        $('w-tagres').innerHTML = tagLast.map(function(u){
            var added = !!have[u.id], dis = added || !u.ok;
            var sub = !u.ok ? '<span class="no">'+eh(u.why)+'</span>' : (u.nickname ? '<span>'+eh(u.nickname)+'</span>' : '');
            return '<button type="button" class="w-tg-row"'+(dis?' disabled':'')+' onclick="W.tagAdd('+u.id+')"><img class="w-tg-av" src="'+eh(tagPic(u))+'" alt="" onerror="this.src=\'uploads/default.jpg\'"><span class="w-tg-inf"><strong>@'+eh(u.username)+'</strong>'+sub+'</span>'+(added?'<span class="w-tg-end">Added</span>':(u.ok?'<span class="w-tg-end">Tag</span>':''))+'</button>';
        }).join('');
    }
    function tagSearch(){
        clearTimeout(tagTimer);
        var q = $('w-tagq').value.trim().replace(/^@/,'');
        if(q.length < 2){ tagLast = []; tagHint('Type at least 2 letters.'); return; }
        tagTimer = setTimeout(function(){
            var s = ++tagSeq, fd = new FormData(); fd.append('tag_action','search'); fd.append('q',q);
            fetch('upload.php',{method:'POST',body:fd}).then(function(r){ return r.json(); }).then(function(d){
                if(s !== tagSeq) return;
                if(!d.success || !d.users || !d.users.length){ tagLast = []; tagHint('No one found.'); return; }
                tagLast = d.users; tagRender();
            }).catch(function(){ if(s === tagSeq) tagHint('Could not search. Try again.'); });
        }, 300);
    }
    function tagAdd(id){
        var u = tagLast.filter(function(x){ return x.id === id; })[0];
        if(!u || !u.ok || tags.some(function(x){ return x.id === id; })) return;
        if(tags.length >= 20){ showToast('You can tag up to 20 people'); return; }
        tags.push({id:u.id, username:u.username, pic:u.pic}); tagRender();
    }
    function tagRemove(id){ tags = tags.filter(function(x){ return x.id !== id; }); tagRender(); }

    /* progress */
    function pgShow(t){
        $('w-pg-ic').className = 'w-pg-ic'; $('w-pg-i').className = 'ri-upload-cloud-2-line';
        $('w-pg-title').textContent = t; $('w-pg-close').style.display = 'none'; pgSet(0,'Starting…'); $('w-pg').classList.add('show');
    }
    function pgSet(p,s){ p = Math.max(0,Math.min(100,p)); $('w-pg-fill').style.width = p+'%'; $('w-pg-pct').textContent = Math.round(p)+'%'; if(s) $('w-pg-sub').textContent = s; }
    function pgHide(){ $('w-pg').classList.remove('show'); }
    function send(fd, onp){
        return new Promise(function(res, rej){
            var x = new XMLHttpRequest(); x.open('POST','upload.php');
            x.upload.onprogress = function(e){ if(e.lengthComputable && onp) onp(e.loaded/e.total); };
            x.onload = function(){ try{ res(JSON.parse(x.responseText)); }catch(e){ rej(new Error('Could not post. Please try again.')); } };
            x.onerror = function(){ rej(new Error('Network error. Please try again.')); };
            x.send(fd);
        });
    }

    function post(){
        if(busy) return;
        var t = cap();
        if(!t.trim()){ showToast('Write something first'); return; }
        if(t.length > MAX){ showToast('Your text is too long (max '+MAX+' characters)'); return; }
        if(!/#\w+/.test(t)){ showToast('Please add at least one #hashtag'); return; }
        busy = true; $('w-post').disabled = true;
        var fd = new FormData();
        fd.append('upload','1'); fd.append('ajax','1'); fd.append('post_type','text');
        fd.append('caption_hidden', t);
        fd.append('tagged_users', tags.map(function(u){ return u.id; }).join(','));
        var p = $('w-partner').checked;
        fd.append('is_partnership', p ? '1' : '0'); fd.append('partner_brand', p ? $('w-brand').value.trim() : '');
        fd.append('is_ai_generated', $('w-ai').checked ? '1' : '0');
        fd.append('audio_mode','none');
        pgShow('Posting…');
        send(fd, function(f){ pgSet(f*96, f < 1 ? 'Uploading '+Math.round(f*100)+'%' : 'Finishing up…'); }).then(function(d){
            if(!d.success) throw new Error(d.msg || 'Could not post.');
            $('w-pg-ic').className = 'w-pg-ic ok'; $('w-pg-i').className = 'ri-check-line';
            $('w-pg-title').textContent = 'Posted!'; pgSet(100, d.msg || '');
            setTimeout(function(){ location.reload(); }, 1000);
        }).catch(function(e){
            $('w-pg-ic').className = 'w-pg-ic bad'; $('w-pg-i').className = 'ri-error-warning-line';
            $('w-pg-title').textContent = 'Something went wrong'; $('w-pg-sub').textContent = e.message; $('w-pg-close').style.display = 'block';
            busy = false; $('w-post').disabled = false;
        });
    }

    document.addEventListener('keydown', function(e){
        if(e.key !== 'Escape') return;
        if($('w-tag').classList.contains('open')) tagClose();
        else if($('w-sheet').classList.contains('open')) close();
    });

    return {open:open, close:close, sync:sync, hash:hash, partner:partner,
            tagOpen:tagOpen, tagClose:tagClose, tagSearch:tagSearch, tagAdd:tagAdd, tagRemove:tagRemove, post:post, pgHide:pgHide};
})();




/* ═══════════ NOTIFICATION BELL — red dot behaviour ═══════════ */
function dzBellClick(e, a){
    if(e && (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1)) return;
    if(e) e.preventDefault();

    var dot = document.getElementById('rpBellDot');
    if(dot && dot.parentNode) dot.parentNode.removeChild(dot);

    var href = (a && a.href) ? a.href : 'notifications.php';
    var went = false;
    function go(){ if(went) return; went = true; window.location.href = href; }

    try {
        fetch('notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'mark_all_read=1',
            credentials: 'same-origin',
            keepalive: true
        }).then(go).catch(go);
    } catch(err){ go(); }
    setTimeout(go, 700);
}





/* ═══════════ SWIPE: the page follows your finger, then opens the next page ═══════════
     • finger moves RIGHT → LEFT  →  search.php
     • finger moves LEFT → RIGHT  →  home.php
   Same drag logic as the home page and the immersive viewer's swipe-to-close.
   Starts only on a clearly sideways swipe; vertical scrolling, sideways scrollers (rows, tag bar),
   photo carousels that still have more slides in that direction, and any open sheet / modal / the write box are left alone. */
(function(){
    var URL_LEFT  = 'search.php';   /* swipe right → left */
    var URL_RIGHT = 'home.php';     /* swipe left → right */
    var st = null, busy = false;
    function target(){ return document.querySelector('.hp-shell'); }
    function blocked(t){
        if(document.body.style.overflow === 'hidden') return true;                         /* a modal / sheet is open */
        if(document.querySelector('.ipv-likers-overlay.ipv-open')) return true;
        if(document.querySelector('.wsh.open')) return true;                               /* write-a-text / tag people */
        if(t.closest && t.closest('input,textarea,select,[contenteditable],.ccm-video-progress,.hl-seek')) return true;
        for(var el = t; el && el !== document.body; el = el.parentElement){                /* any sideways scroller */
            if(el.scrollWidth > el.clientWidth + 2){
                var ox = getComputedStyle(el).overflowX;
                if(ox === 'auto' || ox === 'scroll') return true;
            }
        }
        return false;
    }
    /* a photo carousel that still has more slides in the swipe direction keeps the swipe for itself */
    function carouselWants(t, dir){
        var mb = t.closest ? t.closest('.ipv-media-wrap[data-count]') : null;
        if(!mb) return false;
        var c = parseInt(mb.dataset.count || '1', 10), i = parseInt(mb.dataset.activeIdx || '0', 10);
        if(c <= 1) return false;
        return dir < 0 ? (i < c - 1) : (i > 0);
    }
    function reset(el){
        el.style.transition = 'none'; el.style.transform = ''; el.style.opacity = ''; el.style.willChange = '';
        void el.offsetWidth; el.style.transition = '';
    }
    document.addEventListener('touchstart', function(e){
        st = null;
        if(busy || e.touches.length !== 1) return;
        var el = target(); if(!el || !el.contains(e.target)) return;
        if(blocked(e.target)) return;
        var t = e.touches[0];
        st = { x:t.clientX, y:t.clientY, el:el, tg:e.target, dir:0, on:false, dead:false, lx:t.clientX, lt:Date.now(), v:0, w:(el.getBoundingClientRect().width || window.innerWidth) };
    }, { capture:true, passive:true });

    document.addEventListener('touchmove', function(e){
        if(!st || st.dead) return;
        var t = e.touches[0], dx = t.clientX - st.x, dy = t.clientY - st.y;
        if(!st.on){
            if(Math.abs(dy) > 10 && Math.abs(dy) > Math.abs(dx) * 1.7){ st.dead = true; return; }   /* scrolling up / down */
            if(!(Math.abs(dx) > 8 && Math.abs(dy) <= Math.abs(dx) * 1.7)) return;                  /* wait for a clear sideways swipe */
            st.dir = dx < 0 ? -1 : 1;
            if(carouselWants(st.tg, st.dir)){ st.dead = true; return; }
            st.on = true;
            st.el.style.transition = 'none';
            st.el.style.willChange = 'transform';
        }
        e.stopPropagation();
        if(e.cancelable) e.preventDefault();
        var x = st.dir < 0 ? Math.min(0, dx) : Math.max(0, dx);
        st.el.style.transform = 'translate3d(' + x + 'px,0,0)';
        st.el.style.opacity = String(1 - Math.min(1, Math.abs(x) / (st.w * 1.2)) * 0.5);
        var now = Date.now(), dt = now - st.lt;
        if(dt > 0){ st.v = Math.abs(st.lx - t.clientX) / dt; st.lx = t.clientX; st.lt = now; }
    }, { capture:true, passive:false });

    function finish(e, cancelled){
        if(!st) return;
        var s = st; st = null;
        if(!s.on) return;
        if(e && e.stopPropagation) e.stopPropagation();
        var el = s.el, ct = e && e.changedTouches && e.changedTouches[0];
        var dist = ct ? Math.max(0, s.dir < 0 ? (s.x - ct.clientX) : (ct.clientX - s.x)) : 0;   /* how far it moved in the swipe direction */
        var go = !cancelled && (dist > s.w * 0.33 || (s.v > 0.5 && dist > 40));
        busy = true;
        if(go){
            el.style.transition = 'transform .24s cubic-bezier(.22,.8,.3,1), opacity .24s ease';
            el.style.transform = 'translate3d(' + (s.dir * s.w * 1.05) + 'px,0,0)';
            el.style.opacity = '0';
            var url = s.dir < 0 ? URL_LEFT : URL_RIGHT;
            setTimeout(function(){ location.href = url; }, 235);
            setTimeout(function(){ reset(el); busy = false; }, 1500);   /* safety: if the page did not leave */
        } else {
            el.style.transition = 'transform .26s cubic-bezier(.22,1,.36,1), opacity .26s ease';
            el.style.transform = 'translate3d(0,0,0)';
            el.style.opacity = '1';
            setTimeout(function(){ reset(el); busy = false; }, 280);
        }
    }
    document.addEventListener('touchend',    function(e){ finish(e, false); }, { capture:true, passive:true });
    document.addEventListener('touchcancel', function(e){ finish(e, true);  }, { capture:true, passive:true });
    /* coming back with the browser's back button: make sure the page is in place */
    window.addEventListener('pageshow', function(e){ var el = target(); if(el){ reset(el); } busy = false; });
})();

</script>
<?php dz_hashtags_script(); /* hashtags.php */ ?>
</body>
</html>