<?php
/* ═══════════════════════════════════════════════════════════════════════════
   theme_css.php — ONE stylesheet shared by home.php and text_feed.php
   ───────────────────────────────────────────────────────────────────────────
   Load it in the <head> of both pages, BEFORE the page's own <style>:

       <link rel="stylesheet" href="theme_css.php?v=<?= @filemtime(__DIR__.'/theme_css.php') ?>">

   • The ?v= part changes whenever this file changes, so phones and the service worker
     never keep serving an old copy (the file itself is cached for a year).
   • Colours (background, captions, hashtags, accent …) are at the top in section 1 — edit them there.
   • Everything the two pages have in common is in here: layout, top bar base, the feed + captions,
     the like/repost badges, "Post hidden · Undo" row, likes sheet, suggestion / Memepedia / rising /
     collection rows, the right-hand column and the loading skeletons.
   • Things only ONE page needs stay inside that page's own <style>:
         home.php       → the "people you track" bar + the upload "+" button
         text_feed.php  → the pencil icon and the "Write a text" sheets
   • hashtags.php and offline.php still print their own small styles.
   ═══════════════════════════════════════════════════════════════════════════ */
/* ── Saved light / dark choice (per account) ──────────────────────────────────────────────────────────────
   <script src="theme_css.php?boot=1"></script>   in <head>, BEFORE the stylesheet link → sets data-theme before the page paints
   POST theme_css.php?set=1  (theme=light|dark) → saves the choice on the logged-in account (users.theme, created automatically)
   Logged-out visitors fall back to this browser's localStorage. */
if(isset($_GET['boot']) || isset($_GET['set'])){
    if(session_status()===PHP_SESSION_NONE){ if(isset($_GET['boot'])) @session_start(['read_and_close'=>true]); else @session_start(); }   /* boot only READS the login: close the session at once so it never makes the page wait for its lock */
    $dz_uid=(int)($_SESSION['user_id'] ?? 0);
    $dz_db=null;
    if($dz_uid>0){ try{ @include_once __DIR__.'/config.php'; if(isset($conn) && $conn instanceof mysqli) $dz_db=$conn; }catch(Throwable $e){} }
    header('Cache-Control: private, no-store, max-age=0');
    if(isset($_GET['set'])){
        header('Content-Type: application/json');
        $sfs=strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
        $host=strtolower((string)parse_url('//'.($_SERVER['HTTP_HOST'] ?? ''),PHP_URL_HOST));
        $org=(string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
        $same=($sfs!=='cross-site') && ($org==='' || strtolower((string)parse_url($org,PHP_URL_HOST))===$host);
        $t=($_POST['theme'] ?? '')==='light' ? 'light' : (($_POST['theme'] ?? '')==='dark' ? 'dark' : '');
        if($_SERVER['REQUEST_METHOD']!=='POST' || !$same || $t==='' || $dz_uid<=0 || !$dz_db){ echo json_encode(['status'=>'skip']); exit; }
        try{
            $c=$dz_db->query("SHOW COLUMNS FROM users LIKE 'theme'");
            if($c instanceof mysqli_result && $c->num_rows===0){ $dz_db->query("ALTER TABLE users ADD COLUMN theme VARCHAR(5) NOT NULL DEFAULT 'dark'"); }
            $st=$dz_db->prepare("UPDATE users SET theme = ? WHERE id = ?"); $st->bind_param('si',$t,$dz_uid); $st->execute(); $st->close();
            echo json_encode(['status'=>'ok','theme'=>$t]);
        }catch(Throwable $e){ echo json_encode(['status'=>'error']); }
        exit;
    }
    header('Content-Type: application/javascript; charset=utf-8');
    $t='';
    if($dz_db){ try{ $r=$dz_db->query("SELECT theme FROM users WHERE id=".$dz_uid." LIMIT 1"); if($r instanceof mysqli_result){ $row=$r->fetch_assoc(); $t=(string)($row['theme'] ?? ''); } }catch(Throwable $e){ $t=''; } }
    if($t!=='light' && $t!=='dark') $t='';
    echo "(function(){var s=".json_encode($t).";try{if(s){localStorage.setItem('dz_theme',s);}else{s=localStorage.getItem('dz_theme')||'';}}catch(e){}var h=document.documentElement;if(s==='light')h.setAttribute('data-theme','light');else h.removeAttribute('data-theme');})();";
    exit;
}
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=31536000, immutable');
?>
/* THEME COLOURS — the only place colours are defined. Profile / group pages load just this part with theme_css.php?vars=1 */
:root{
    /* ── page background (the dark blue gradient behind everything) ── */
    --bg-start:#010218;            /* gradient start (darkest) */
    --bg-end:#070924;              /* gradient end */
    --lane:#070924;                /* the centre column the feed sits in + the colour the green dot / badges are outlined with */
    --post-sep:#02030e;            /* thick line between two posts */
    --border:rgba(255,255,255,.07);/* thin side lines of the column on desktop */

    /* ── brand + text colours ── */
    --red:#FF2D2D;                 /* main accent: buttons, usernames in captions, active icons */
    --ink:#06071A;
    --violet:#7c5cff;
    --text:#F0F2FF;               /* normal text */
    --muted:rgba(255,255,255,.45);/* small grey text */
    --sub:rgba(255,255,255,.62);  /* lighter sub-text */
    --green:#22c55e;               /* "active now" dot */

    /* ── CAPTIONS ── */
    --cap:rgba(255,255,255,.85);   /* caption text colour */
    --gw:rgba(255,255,255,.65);    /* #hashtags inside captions */
    --more:#fff;                   /* "more" / "less" button — brighter than the caption */

    /* ── SURFACES + TINTS used by sheets, modals, cards, buttons (profile / group pages and their modals) ── */
    --surface-2:#0d1030;           /* raised panel / bottom-sheet body */
    --surface-3:#090b29;           /* card on the lane */
    --surface-deep:#05061a;        /* deepest panel */
    --fg:255,255,255;              /* R,G,B of the tint used for text-on-surface, thin lines and soft fills: rgba(var(--fg),.1). Light theme = 6,7,26 */
    --on-accent:#fff;              /* text on red / blue buttons */
}
/* LIGHT THEME — switched on by <html data-theme="light"> (Dark mode toggle in the profile menu). Same names, light values. */
:root[data-theme="light"]{
    color-scheme:light;
    --bg-start:#ffffff; --bg-end:#f4f5fb; --lane:#ffffff; --post-sep:#eceef5;
    --border:rgba(0,0,0,.09);
    --ink:#ffffff; --text:#0b0d24; --muted:rgba(0,0,0,.5); --sub:rgba(0,0,0,.66);
    --cap:rgba(0,0,0,.86); --gw:rgba(0,0,0,.6); --more:#000;
    --surface-2:#f4f5fb; --surface-3:#f7f8fc; --surface-deep:#eceef5;
    --fg:6,7,26;

    /* TEXT COLOURS IN LIGHT MODE — used by the "LIGHT THEME — element colours" section at the end of this file */
    --ui-text:#0b0d24;             /* usernames, counts, icons, menu (⋯) button, titles, buttons — the strongest text */
    --ui-sub:#52566a;              /* nickname / @handle, audio + AI lines, "because you…" line, small grey words (dark grey) */
    --ui-sub2:#42465a;             /* the dim words in "Liked by … and …" */
    --ui-mute:#5f647a;             /* date / time under a post */
}
<?php if(!isset($_GET['vars'])): /* ?vars=1 (profile + group pages) skips the home/text-feed layout below and jumps to the light-theme colour rules at the end */ ?>
/* ═══════════ 2. BASE — reset, page background, text colour ═══════════ */
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif}
button,a{cursor:pointer;outline:none;border:none;background:none}
/* ═══════════════════════════════════════════════════════════════════════════
   1. THEME VARIABLES — change a colour here and BOTH pages follow
   ═══════════════════════════════════════════════════════════════════════════ */
:root{
    /* ── layout sizes ── */
    --lane-w:520px;                /* width of the centre column on desktop */
    --sidebar-w:100px;             /* width of the left nav on desktop */
    --rsn:28px;                    /* height of the "Because you liked…" line */

    /* ── scale + sizes that depend on it (--ccm-s is raised to 1.155 on phones, see the end of this file) ── */
    --ccm-s:1;
    --rp-action-h:calc(clamp(25px,7.2vw,30px) * var(--ccm-s));   /* height of grey action buttons */
    --rp-action-radius:4px;                                        /* corner radius of those buttons */
    --spr:calc(clamp(48px,3vw + 38px,76px) * var(--ccm-s));       /* raised overlay offset on long-header posts */
    --lht:calc(clamp(42px,3.4vw + 31px,58px) * var(--ccm-s));     /* top tag offset on long-header posts */
    --tb-ic:calc(clamp(1.15rem,3.5vw + 6.8px,1.5rem) * var(--ccm-s)); /* top bar icon size */
}
body{
    background:linear-gradient(-45deg,var(--bg-start),var(--bg-end),var(--bg-end));background-attachment:fixed;
    color:var(--text);min-height:100vh;overflow-x:hidden;
    display:flex;flex-direction:column;align-items:center;padding:0 0 76px;
}
@media(min-width:769px){body{padding-left:var(--sidebar-w);align-items:flex-start}}

/* ═══════════ 3. SHELL — centre column + page layout ═══════════ */
.hp-shell{width:100%;min-height:100vh;display:flex;justify-content:center;align-items:flex-start}
.hp-col{width:100%;max-width:var(--lane-w);min-height:100vh;background:var(--lane)}
@media(min-width:769px){.hp-col{border-left:1px solid var(--border);border-right:1px solid var(--border)}}

/* ═══════════ ONLINE DOT (green) — sits on the bottom-right of the profile pic, ring = page colour ═══════════ */
.av-wrap{position:relative;display:inline-flex;flex-shrink:0;line-height:0;text-decoration:none}
.on-dot{position:absolute;right:2%;bottom:2%;width:32%;min-width:9px;max-width:16px;aspect-ratio:1;border-radius:50%;background:var(--green);border:calc(clamp(1.5px,.5vw,2.5px) * var(--ccm-s)) solid var(--lane);box-sizing:border-box;pointer-events:none;z-index:3}
.hp-sugg-aw{display:block;width:100%;line-height:0}
.hp-sugg-aw .on-dot{right:8px;bottom:8px;width:calc(clamp(11px,6%,16px) * var(--ccm-s))}

/* ═══════════ RIGHT COLUMN — laptop / normal desktop view only (≥1100px) ═══════════ */
.hp-right-rail{display:none}
@media(min-width:1100px){
    .hp-shell{justify-content:flex-start;padding-left:22px}
    .hp-col{margin:0;flex:0 0 var(--lane-w);width:var(--lane-w)}
    .hp-right-rail{
        display:block;flex:1 1 0;min-width:0;max-width:720px;
        position:sticky;top:0;height:100vh;overflow-y:auto;overflow-x:hidden;
        padding:14px 26px 40px 22px;
        scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.22) transparent;
    }
    .hp-right-rail::-webkit-scrollbar{width:5px}
    .hp-right-rail::-webkit-scrollbar-track{background:transparent}
    .hp-right-rail::-webkit-scrollbar-thumb{background:rgba(255,255,255,.22);border-radius:99px}
    .hp-right-rail::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.34)}
}

.pf-card{margin-bottom:14px}
.pf-bar,.pf-more{
    display:flex;align-items:center;width:100%;height:var(--rp-action-h);
    border:none;border-radius:var(--rp-action-radius);background:rgba(255,255,255,.1);
    backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);color:#fff;font-weight:600;line-height:1;
    transition:background .2s ease,transform .15s ease;
}
.pf-bar{gap:7px;padding:0 12px;font-size:.72rem;text-align:left}
.pf-more{justify-content:center;margin-top:8px;padding:0 12px;font-size:.7rem;text-decoration:none}
.pf-bar:hover,.pf-more:hover{background:rgba(255,255,255,.2)}
.pf-bar:active{transform:scale(.99)}
.pf-more:active{transform:scale(.98)}
.pf-bar-ic{font-size:14px;color:var(--red);flex-shrink:0}
.pf-bar-t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pf-bar-c{font-size:.64rem;font-weight:600;color:rgba(255,255,255,.55);flex-shrink:0}
.pf-bar-chev{font-size:18px;color:rgba(255,255,255,.75);flex-shrink:0;transition:transform .25s ease}
.pf-card.collapsed .pf-bar-chev{transform:rotate(-90deg)}
.pf-body{display:grid;grid-template-rows:1fr;transition:grid-template-rows .3s ease}
.pf-inner{min-height:0;overflow:hidden}
.pf-pad{padding-top:8px}
.pf-card.collapsed .pf-body{grid-template-rows:0fr}

.pf-row{display:flex;align-items:center;gap:10px;padding:8px 2px;color:#fff;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.06)}
.pf-row:last-of-type{border-bottom:none}
.pf-row.clickable{cursor:pointer}
.pf-row.clickable:hover .pf-row-t{text-decoration:underline}
.pf-hold{display:none}
.pf-in{animation:pfRowIn .3s cubic-bezier(.22,1,.36,1) both}
@keyframes pfRowIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.pf-av{width:34px;height:34px;border-radius:50%;object-fit:cover;border:1.5px solid var(--red);flex-shrink:0;display:block;background:#0d1030}
.pf-av.sq{border-radius:8px;border-color:rgba(255,255,255,.14)}
div.pf-av.sq{display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.35);font-size:15px}
.pf-row-info{flex:1;min-width:0}
.pf-row-t{display:block;font-size:.76rem;font-weight:600;color:#fff;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
a.pf-row-t:hover{text-decoration:underline}
.pf-row-s{font-size:.64rem;color:rgba(255,255,255,.5);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pf-track{flex-shrink:0;height:24px;padding:0 13px;border-radius:4px;background:var(--red);color:#000;font-size:.66rem;font-weight:700;line-height:1;transition:background .2s}
.pf-track:hover{background:#ff1a1a}
.pf-track.following{background:rgba(255,255,255,.1);color:#fff}

.pf-chips{display:flex;flex-wrap:wrap;gap:6px}
.pf-chip{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:var(--rp-action-radius);background:rgba(255,255,255,.07);color:#fff;font-size:.68rem;font-weight:600;text-decoration:none;line-height:1;transition:background .2s ease}
a.pf-chip:hover{background:rgba(255,255,255,.16)}
.pf-chip.on{background:var(--red);color:#000}

.pf-about{padding:4px 2px 2px}
.pf-about p{font-size:.72rem;line-height:1.7;color:rgba(255,255,255,.72);margin-bottom:9px}
.pf-about p:last-child{margin-bottom:0}
.pf-about b{color:#fff;font-weight:600}

/* Sponsored ads */
.rs-ads-list{display:flex;flex-direction:column;gap:8px}
.rs-ad-card{display:flex;gap:10px;align-items:flex-start;text-decoration:none;padding:6px;border-radius:10px;transition:background .15s}
.rs-ad-card:hover{background:rgba(255,255,255,.06)}
.rs-ad-thumb{width:72px;height:72px;border-radius:8px;object-fit:cover;background:#111226;flex-shrink:0;display:block}
.rs-ad-info{flex:1;min-width:0;padding-top:2px}
.rs-ad-caption{font-size:.76rem;line-height:1.35;color:#fff;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:5px}
.rs-ad-tag{display:inline-block;font-size:.6rem;font-weight:800;color:#FF3B30;letter-spacing:.04em;text-transform:uppercase}
.rs-ad-skel{display:flex;gap:10px;padding:6px}
.rs-ad-skel-thumb{width:72px;height:72px;border-radius:8px;flex-shrink:0}
.rs-ad-skel-lines{flex:1;display:flex;flex-direction:column;gap:6px;padding-top:4px}
.rs-ad-skel-line{height:9px;border-radius:5px}
.rs-ad-skel-line.w1{width:92%}.rs-ad-skel-line.w2{width:65%}.rs-ad-skel-line.w3{width:28%;height:8px}
.rs-divider{height:1px;background:rgba(255,255,255,.09);margin:0 4px 6px}
.rs-links{display:flex;flex-direction:column;gap:2px}
.rs-link-row{display:flex;align-items:center;gap:12px;padding:10px 6px;border-radius:8px;color:#fff;text-decoration:none;font-size:.84rem;font-weight:600;transition:background .15s}
.rs-link-row:hover{background:rgba(255,255,255,.06)}
.rs-link-row i{font-size:17px;color:#ff2a2a;width:18px;text-align:center;flex-shrink:0}

/* shared shimmer for every skeleton */
.rs-ad-skel-thumb,.rs-ad-skel-line,.ipv-skel-avatar,.ipv-skel-line,.ipv-skel-media,.ipv-skel-icon,.ipv-skel-cap,.ipv-skel-cap2,
.hp-mp-skel-pill,.hp-mp-skel-thumb,.hp-mp-skel-line,.hp-coll-skel-thumb,.hp-coll-skel-line{
    background:linear-gradient(90deg,rgba(255,255,255,.05) 25%,rgba(255,255,255,.13) 37%,rgba(255,255,255,.05) 63%);
    background-size:400% 100%;animation:ipvSkelShimmer 1.4s ease-in-out infinite;
}
@keyframes ipvSkelShimmer{0%{background-position:100% 50%}100%{background-position:0% 50%}}

/* ═══════════ 4. TOP BAR (shared part — each page adds its own icons) ═══════════ */
.hp-topbar{
    position:sticky;top:0;z-index:900;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;
    gap:calc(clamp(4px,3.75vw - 8px,10px) * var(--ccm-s));padding:calc(clamp(9px,3.1vw - 1.2px,14px) * var(--ccm-s)) calc(clamp(10px,3.75vw - 2.4px,16px) * var(--ccm-s));
    background:var(--lane);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
}
.hft-btn{background:none;border:none;color:#fff;font-size:var(--tb-ic);display:flex;align-items:center;justify-content:center;padding:2px;opacity:.9;transition:opacity .2s,color .2s;-webkit-tap-highlight-color:transparent;flex-shrink:0;text-decoration:none;line-height:1}
.hft-btn:hover,.hft-btn:active{opacity:1;color:#FF2D2D}
.hft-title{text-align:center;font-size:calc(clamp(.95rem,4.5vw + .35px,1.4rem) * var(--ccm-s));font-weight:700;color:#fff;letter-spacing:calc(clamp(1px,1.2vw - 3px,3px) * var(--ccm-s));padding-left:1px;white-space:nowrap;transform:translateX(calc(clamp(-14px,-2.6vw,-7px) * var(--ccm-s)))}
.hft-left{display:flex;align-items:center;justify-content:flex-start}
.hft-right{display:flex;align-items:center;justify-content:flex-end;gap:calc(clamp(9px,3.4vw,16px) * var(--ccm-s));position:relative;padding-right:var(--hft-inset,calc(clamp(5px,1.6vw,8px) * var(--ccm-s)))}   /* --hft-inset = extra space between the bell and the screen edge */
.rp-tb-btn{background:none;border:none;color:#fff;display:flex;align-items:center;justify-content:center;opacity:.9;transition:opacity .2s,color .2s;text-decoration:none;padding:2px;position:relative}
.rp-tb-btn:hover{opacity:1;color:var(--red)}
.rp-tb-dot{position:absolute;top:2px;right:2px;width:8px;height:8px;background:var(--red);border-radius:50%;border:1.5px solid #06071A}
.rp-tb-btn,.hp-topbar .notif-bell{font-size:calc(clamp(1.15rem,3.5vw + 6.8px,1.55rem) * var(--ccm-s));padding:2px;opacity:.9}
.hp-topbar .notif-bell:hover{opacity:1}

/* ═══════════ FOLLOW / TRACK BAR ═══════════ */

/* scroll arrows — square, rounded corners, big centred arrow */
.fb-arrow,.hp-sugg-arrow,.hp-mp-arrow{position:absolute;transform:translateY(-50%);z-index:10;width:34px;height:34px;border-radius:8px;padding:0;background:rgba(0,0,0,.7);border:1.5px solid rgba(255,255,255,.25);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;line-height:1;opacity:0;pointer-events:none;transition:opacity .25s ease,background .2s ease}
.fb-arrow i,.hp-sugg-arrow i,.hp-mp-arrow i{display:block;font-size:24px;line-height:1}
.fb-arrow{top:42%}.hp-sugg-arrow{top:42%}.hp-mp-arrow{top:calc(50% + 10px)}
.fb-arrow-left,.hp-sugg-arrow-l,.hp-mp-arrow-l{left:6px}
.fb-arrow-right,.hp-sugg-arrow-r,.hp-mp-arrow-r{right:6px}
.fb-arrow:hover,.hp-sugg-arrow:hover,.hp-mp-arrow:hover{background:rgba(255,45,45,.85)}
.follow-wrap:hover .fb-arrow,.follow-wrap.show-arrows .fb-arrow,
.hp-sugg-scroll-wrap:hover .hp-sugg-arrow:not(.hidden),.hp-mp-scroll-wrap:hover .hp-mp-arrow:not(.hidden){opacity:1;pointer-events:auto}
.fb-arrow.hidden,.hp-sugg-arrow.hidden,.hp-mp-arrow.hidden{opacity:0!important;pointer-events:none!important}
@media(max-width:768px){.fb-arrow,.hp-sugg-arrow,.hp-mp-arrow{display:none!important}}

/* HASHTAG BAR: styles live in hashtags.php */

/* ═══════════ 5. THE FEED — post header, media, actions, CAPTIONS, badges ═══════════ */
.ipv-list{padding-bottom:30px;margin-top:0}
.ipv-list>.ipv-post:first-child:not(.ipv-long-header),.ipv-list>.ipv-skel-post:first-child{border-top:3px solid var(--post-sep)}
.ipv-post{border-bottom:3px solid var(--post-sep);padding-bottom:12px;margin-bottom:0;scroll-margin-top:64px}
.ipv-header{display:flex;align-items:center;gap:calc(clamp(6px,1.4vw,10px) * var(--ccm-s));padding:calc(clamp(7px,1.6vw,11px) * var(--ccm-s)) calc(clamp(8px,1.5vw,12px) * var(--ccm-s))}
.ipv-avatar{width:calc(clamp(25px,1.9vw + 18.7px,34px) * var(--ccm-s));height:calc(clamp(25px,1.9vw + 18.7px,34px) * var(--ccm-s));border-radius:50%;object-fit:cover;border:1.6px solid var(--red);cursor:pointer;flex-shrink:0}
.ipv-header-info{flex:1;min-width:0;display:flex;flex-direction:column}
.ipv-username-row{display:flex;align-items:center;gap:6px;min-width:0;margin-bottom:2px}
.ipv-username{font-size:calc(clamp(11px,.37vw + 9.6px,13px) * var(--ccm-s));font-weight:700;color:#fff;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ipv-new-tag{color:var(--red);font-size:11px;font-weight:800;flex-shrink:0}
.ipv-dot-sep{color:rgba(255,255,255,.35);font-size:12px;flex-shrink:0}
.ipv-dot-sep-red{color:var(--red);font-size:13px;flex-shrink:0;line-height:1}
.ipv-track-btn{background:none;border:none;padding:0;color:var(--red);font-size:calc(clamp(11px,1vw + 6px,12px) * var(--ccm-s));font-weight:700;cursor:pointer;flex-shrink:0;text-decoration:underline;text-decoration-color:var(--red);text-underline-offset:2px;text-decoration-thickness:1.5px;position:relative;top:-1px}
.ipv-info-switcher{position:relative;min-height:14px}
.ipv-switch-pane{position:absolute;left:0;top:0;right:0;opacity:0;pointer-events:none;transition:opacity .35s ease}
.ipv-switch-pane.active{position:relative;opacity:1;pointer-events:all}
.ipv-sub-line{font-size:calc(clamp(10.08px,.864vw + 3.26px,10.56px) * var(--ccm-s));color:rgba(220,224,255,.55);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ipv-audio-tag{display:flex;align-items:center;gap:6px}
.ipv-audio-icon{display:inline-flex;align-items:flex-end;gap:2px;height:9px;flex-shrink:0}
.ipv-eq-bar{width:2px;height:9px;border-radius:2px;background:rgba(255,255,255,.65);display:inline-block;transform-origin:bottom;animation:ipvEq .9s ease-in-out infinite}
.ipv-eq-bar:nth-child(1){animation-delay:-.6s}.ipv-eq-bar:nth-child(2){animation-delay:-.2s}.ipv-eq-bar:nth-child(3){animation-delay:-.9s}
@keyframes ipvEq{0%{transform:scaleY(.35)}25%{transform:scaleY(1)}50%{transform:scaleY(.5)}75%{transform:scaleY(.85)}100%{transform:scaleY(.35)}}
.ipv-ai-line{display:flex;align-items:center;gap:5px;font-size:calc(clamp(10.08px,.864vw + 3.26px,10.56px) * var(--ccm-s));font-weight:500;color:rgba(220,224,255,.6);white-space:nowrap}
.ipv-audio-text{font-size:calc(clamp(10.08px,.864vw + 3.26px,10.56px) * var(--ccm-s));font-weight:500;color:rgba(220,224,255,.6);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}
.ipv-kebab{background:none;border:none;color:#fff;font-size:calc(clamp(20px,5vw - 4px,22px) * var(--ccm-s));padding:6px 4px 6px 10px;margin-right:-6px;flex-shrink:0;-webkit-tap-highlight-color:transparent;position:relative;top:-3px;display:flex;align-items:center;justify-content:center;line-height:1}
.ipv-kebab i{display:inline-block;transform:scaleX(1.18)}
.ipv-kebab:active{transform:scale(1.08)}

.ipv-header-caption{padding:0 calc(clamp(10px,.65vw + 6.8px,12px) * var(--ccm-s)) 9px;font-size:calc(clamp(11px,.37vw + 9.6px,12.5px) * var(--ccm-s));line-height:1.5;color:var(--cap);word-break:break-word}
.ipv-post.ipv-long-header .ipv-header-caption{display:none}
.ipv-post.ipv-long-header{position:relative}
.ipv-post.ipv-long-header .ipv-header{position:absolute;top:0;left:0;right:0;z-index:8;padding-bottom:22px;background:linear-gradient(180deg,rgba(0,0,0,.68) 0%,rgba(0,0,0,.34) 55%,transparent 100%)}
.ipv-post.ipv-has-reason.ipv-long-header .ipv-header{top:var(--rsn)}
.ipv-post.ipv-long-header .ipv-ai-line{color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.5)}
.ipv-post.ipv-long-header .ipv-username,.ipv-post.ipv-long-header .ipv-sub-line,.ipv-post.ipv-long-header .ipv-audio-text,.ipv-post.ipv-long-header .ipv-kebab{color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.5)}
.ipv-post.ipv-long-header .ipv-top-left-tags,.ipv-post.ipv-long-header .ipv-top-right-tags{top:var(--lht)}

.ipv-media-wrap{position:relative;width:100%;background:#000;overflow:hidden;aspect-ratio:4/4.2;transition:height .18s ease;touch-action:pan-y}
.ipv-media-track{display:flex;height:100%;transition:transform .28s cubic-bezier(.22,1,.36,1);will-change:transform}
.ipv-media-item{flex:0 0 100%;height:100%;position:relative;background:#000}
.ipv-media-item img,.ipv-media-item video{width:100%;height:100%;object-fit:cover;display:block;cursor:pointer;-webkit-user-drag:none;user-select:none;-webkit-user-select:none}
.ipv-media-wrap.ipv-multi{aspect-ratio:4/3.5}
.ipv-media-wrap.ipv-multi .ipv-media-item{display:flex;align-items:center;justify-content:center}
.ipv-media-wrap.ipv-multi .ipv-media-item img,.ipv-media-wrap.ipv-multi .ipv-media-item video{object-fit:contain;background:#000}
.ipv-media-wrap.ipv-multi .ipv-media-item img.ipv-fit-cover,.ipv-media-wrap.ipv-multi .ipv-media-item video.ipv-fit-cover{object-fit:cover}
.ipv-media-wrap.ipv-textonly{aspect-ratio:auto;min-height:130px;background:var(--lane);display:flex;flex-direction:column}
.ipv-media-wrap.ipv-textonly .ipv-social-proof-strip,
.ipv-media-wrap.ipv-textonly .ipv-cta-pill,
.ipv-media-wrap.ipv-textonly .ipv-partnership-tag{position:relative;left:auto;bottom:auto;align-self:flex-start;margin:0 18px 20px}
.ipv-dots{position:absolute;left:0;right:0;bottom:10px;z-index:6;display:flex;align-items:center;justify-content:center;gap:5px;flex-wrap:wrap;row-gap:5px}
.ipv-dot{width:5px;height:5px;border-radius:50%;background:rgba(255,255,255,.35);box-shadow:0 0 3px rgba(0,0,0,.65);transition:background .18s,transform .18s;flex-shrink:0}
.ipv-dot.active{background:var(--red);transform:scale(1.3)}
.ipv-media-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:6;width:calc(clamp(26px,6vw - 3.4px,32px) * var(--ccm-s));height:calc(clamp(26px,6vw - 3.4px,32px) * var(--ccm-s));border-radius:50%;background:rgba(0,0,0,.42);color:#fff;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent}
.ipv-media-arrow i{font-size:calc(clamp(16px,4vw - 3.6px,20px) * var(--ccm-s))}
.ipv-arrow-left{left:10px}.ipv-arrow-right{right:10px}

.ipv-top-left-tags{position:absolute;top:calc(clamp(8px,2vw - 1.8px,10px) * var(--ccm-s));left:calc(clamp(8px,2vw - 1.8px,10px) * var(--ccm-s));z-index:6;display:flex;flex-direction:column;align-items:flex-start;gap:5px;pointer-events:none}
.ipv-top-left-tags>*{pointer-events:all;flex:none;width:max-content}
.ipv-group-tag{display:flex;align-items:center;gap:4px;background:rgba(40,42,50,.78);border-radius:6px;padding:4px 8px 4px 6px;text-decoration:none;font-size:9px;font-weight:600;color:rgba(255,255,255,.85);max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ipv-group-tag i{font-size:10.5px;color:rgba(255,255,255,.55);flex-shrink:0}
.ipv-group-tag b{color:#ff5f5f;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ipv-ai-tag{display:flex;align-items:center;gap:4px;background:rgba(59,130,246,.18);border:1px solid rgba(59,130,246,.35);border-radius:6px;padding:4px 8px 4px 6px;font-size:9px;font-weight:600;color:#fff;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);white-space:nowrap}
.ipv-ai-tag i{font-size:11px;color:#7fb2ff}
.ipv-top-right-tags{position:absolute;top:calc(clamp(8px,2vw - 1.8px,10px) * var(--ccm-s));right:calc(clamp(8px,2vw - 1.8px,10px) * var(--ccm-s));z-index:6;display:flex;flex-direction:column;align-items:flex-end;gap:5px;pointer-events:none}
.ipv-media-counter{background:rgba(22,22,24,.78);color:#fff;font-size:9px;font-weight:800;letter-spacing:.3px;padding:3px 7px;border-radius:5px}
.ipv-type-tag{display:flex;align-items:center;gap:4px;background:rgba(22,22,24,.78);border-radius:5px;padding:3px 7px 3px 5px}
.ipv-type-tag i{font-size:10.5px;color:#fff}
.ipv-type-tag span{font-size:9px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}

.ipv-social-proof-strip{position:absolute;left:12px;bottom:30px;z-index:6;display:flex;flex-direction:row-reverse;gap:7px;pointer-events:none;transition:opacity .25s ease,visibility .25s ease}
.ipv-social-proof-strip.ipv-sp-raised{bottom:var(--spr)}
.ipv-sp-avatar-wrap{position:relative;pointer-events:all;display:inline-block;text-decoration:none}
.ipv-sp-avatar-wrap:nth-child(1){animation:ipvSpFloat 3s ease-in-out infinite}
.ipv-sp-avatar-wrap:nth-child(2){animation:ipvSpFloat 3s ease-in-out infinite .4s}
.ipv-sp-avatar-wrap:nth-child(3){animation:ipvSpFloat 3s ease-in-out infinite .8s}
.ipv-sp-avatar-wrap:nth-child(4){animation:ipvSpFloat 3s ease-in-out infinite 1.2s}
@keyframes ipvSpFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
.ipv-sp-avatar{width:calc(clamp(34px,27.91px + 2.06vw,46px) * var(--ccm-s));height:calc(clamp(34px,27.91px + 2.06vw,46px) * var(--ccm-s));border-radius:50%;object-fit:cover;border:2.5px solid var(--red);display:block;box-shadow:0 4px 12px rgba(0,0,0,.7)}
.ipv-sp-badge{position:absolute;bottom:-2px;right:-2px;width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;z-index:2}
.ipv-sp-badge i{font-size:8px;color:#fff;line-height:1}
/* LIKE badge = a real heart (not a heart in a circle): red, outlined in the lane colour so it stands out from the avatar's red border — same as the immersive viewer.
   Both badges (heart + repost) are the size of the like button. */
.ipv-sp-badge.ipv-sp-heart,.ipv-sp-badge.ipv-sp-rep{--spb:calc(clamp(17px,15.02px + .87vw,22px) * var(--ccm-s));width:var(--spb);height:var(--spb)}
.ipv-sp-badge.ipv-sp-heart{background:none;bottom:calc(-5px * var(--ccm-s));right:calc(-6px * var(--ccm-s))}
.ipv-sp-badge.ipv-sp-rep{width:calc(var(--spb) * .88);height:calc(var(--spb) * .88);bottom:calc(-4px * var(--ccm-s));right:calc(-5px * var(--ccm-s));box-shadow:0 0 0 calc(2px * var(--ccm-s)) var(--lane,#070924)}
.ipv-sp-badge.ipv-sp-rep i{font-size:calc(var(--spb) * .6);color:#fff;line-height:1;display:block}
.ipv-sp-heart svg{width:100%;height:100%;display:block;overflow:visible}
.ipv-sp-heart .sp-ring{fill:var(--lane,#070924);stroke:var(--lane,#070924)}
.ipv-sp-heart .sp-fill{fill:#FF2D2D}
.ipv-partnership-tag{position:absolute;z-index:7;left:calc(clamp(10px,2vw + .2px,12px) * var(--ccm-s));bottom:calc(clamp(26px,8vw - 13px,34px) * var(--ccm-s));display:inline-flex;align-items:center;gap:6px;padding:calc(clamp(4px,1vw - .9px,5px) * var(--ccm-s)) calc(clamp(8px,2vw - 1.8px,10px) * var(--ccm-s));border-radius:8px;background:rgba(0,0,0,.55);color:#ff4c4c;font-size:calc(clamp(10px,1vw + 5.1px,11px) * var(--ccm-s));font-weight:600;max-width:calc(100% - 24px);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);pointer-events:none;transition:opacity .25s ease,visibility .25s ease}
.ipv-partnership-tag i{font-size:13px;flex-shrink:0}
.ipv-partnership-tag b{color:#fff;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ipv-cta-pill{position:absolute;left:calc(clamp(10px,2vw + .2px,12px) * var(--ccm-s));bottom:calc(clamp(26px,4vw + 6.4px,30px) * var(--ccm-s));z-index:6;display:flex;align-items:center;justify-content:flex-start;padding:calc(clamp(7px,.65vw + 3.8px,9px) * var(--ccm-s)) calc(clamp(11px,.97vw + 6.2px,14px) * var(--ccm-s));border-radius:8px;background:rgba(30,30,34,.55);border:1px solid rgba(255,255,255,.28);color:#fff;font-weight:700;font-size:calc(clamp(10px,.5vw + 7.5px,11.5px) * var(--ccm-s));text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.3);white-space:nowrap;overflow:hidden;max-width:calc(100% - 24px);transition:padding .4s cubic-bezier(.22,1,.36,1),background .4s ease,border-color .4s ease,box-shadow .4s ease,min-width .4s cubic-bezier(.22,1,.36,1),opacity .25s ease,visibility .25s ease}
.ipv-cta-pill.ipv-sp-raised{bottom:var(--spr);font-size:13px}
.ipv-cta-pill-text{position:relative;z-index:1;overflow:hidden;text-overflow:ellipsis}
.ipv-cta-pill-chevron{display:inline-block;font-size:0;opacity:0;max-width:0;overflow:hidden;margin-left:0;flex-shrink:0;transition:opacity .3s ease,max-width .3s ease,margin-left .3s ease,font-size .3s ease}
.ipv-cta-pill.ipv-cta-pill-expanded{background:var(--cta-color,#FF4C4C);border-color:transparent;box-shadow:0 6px 22px rgba(0,0,0,.45);padding:calc(clamp(10px,.65vw + 6.8px,12px) * var(--ccm-s)) calc(clamp(16px,.65vw + 12.8px,18px) * var(--ccm-s));min-width:min(300px,calc(100% - 24px));justify-content:space-between}
.ipv-cta-pill.ipv-cta-pill-expanded .ipv-cta-pill-chevron{opacity:1;max-width:20px;margin-left:auto;padding-left:8px;font-size:15px}

.ipv-post.ipv-cap-open .ipv-social-proof-strip,
.ipv-post.ipv-cap-open .ipv-cta-pill,
.ipv-post.ipv-cap-open .ipv-partnership-tag,
.ipv-post.ipv-cap-open .ipv-connected-btn{opacity:0;visibility:hidden;pointer-events:none}
.ipv-post.ipv-cap-open .ipv-textonly .ipv-social-proof-strip,
.ipv-post.ipv-cap-open .ipv-textonly .ipv-cta-pill,
.ipv-post.ipv-cap-open .ipv-textonly .ipv-partnership-tag{display:none}

.ipv-media-controls{position:absolute;bottom:12px;right:12px;z-index:6;display:flex;flex-direction:column;gap:8px}
.ipv-mute-btn{width:calc(clamp(22px,20.64px + 1.05vw,28px) * var(--ccm-s));height:calc(clamp(22px,20.64px + 1.05vw,28px) * var(--ccm-s));border-radius:50%;background:rgba(0,0,0,.5);color:#fff;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent}
.ipv-mute-btn i{font-size:calc(clamp(11px,10.32px + .53vw,14px) * var(--ccm-s))}
.ipv-textonly-clickable{flex:1 1 auto;display:flex;align-items:flex-start;justify-content:flex-start;width:100%;padding:14px 18px 18px;cursor:pointer}
.ipv-media-wrap.ipv-textonly.ipv-tl .ipv-textonly-clickable{padding-top:70px}
.ipv-media-wrap.ipv-textonly .ipv-top-right-tags{top:calc(clamp(10px,2vw + .2px,12px) * var(--ccm-s));right:calc(clamp(12px,2vw + 2.2px,14px) * var(--ccm-s))}
.ipv-center-caption-wrap{width:100%;display:block;text-align:left}
.ipv-tag-gap{display:block;float:right;width:62px;height:1em;font-size:12.5px;pointer-events:none}
.ipv-center-caption-text{color:var(--cap);font-size:12.5px;font-weight:400;line-height:1.6;max-width:100%;word-break:break-word;text-align:left}
/* Text-post typography (shared by Dezztext and Home): shorter posts get bigger, bolder type */
.ipv-textonly .ipv-tag-gap{height:1.6em}
.ipv-center-caption-text.ipv-t-s{font-size:calc(clamp(.95rem,2.8vw + .1rem,1.15rem) * var(--ccm-s));font-weight:700;line-height:1.4}
.ipv-center-caption-text.ipv-t-m{font-size:calc(clamp(.86rem,1.5vw + .4rem,.95rem) * var(--ccm-s));font-weight:600;line-height:1.5}
.ipv-center-caption-text.ipv-t-l{font-size:calc(clamp(.8rem,.8vw + .55rem,.86rem) * var(--ccm-s));font-weight:500;line-height:1.6}

.ipv-actions{display:flex;align-items:center;justify-content:space-between;padding:calc(clamp(7px,.65vw + 3.8px,9px) * var(--ccm-s)) calc(clamp(8px,.65vw + 4.8px,10px) * var(--ccm-s)) 0;position:relative}
.ipv-actions-left{display:flex;align-items:center;gap:calc(clamp(9px,1.9vw + 2.7px,18px) * var(--ccm-s))}
.ipv-action-group{display:flex;align-items:center;gap:6px}
.ipv-action-btn{background:none;border:none;color:#fff;font-size:calc(clamp(17px,1.06vw + 13.5px,22px) * var(--ccm-s));padding:2px;display:flex;align-items:center;-webkit-tap-highlight-color:transparent;transition:transform .12s ease}
.ipv-action-btn:active{transform:scale(1.22)}
.ipv-action-btn .ipv-ic-on{display:none}
.ipv-action-btn.active .ipv-ic-on{display:inline}
.ipv-action-btn.active .ipv-ic-off{display:none}
.ipv-action-btn.active{color:var(--red)}
.ipv-action-count{font-size:calc(clamp(11px,.9vw + 6.6px,12px) * var(--ccm-s));font-weight:700;color:#fff}
@keyframes ipv-repost-arc-swap{0%{transform:rotate(0) scale(1)}35%{transform:rotate(140deg) scale(1.15)}50%{transform:rotate(180deg) scale(1.2)}65%{transform:rotate(220deg) scale(1.15)}100%{transform:rotate(360deg) scale(1)}}
.ipv-action-btn.arc-swap-active i{animation:ipv-repost-arc-swap .6s cubic-bezier(.33,1,.68,1);transition:none!important}
.ipv-ad-tag{position:absolute;right:9px;top:50%;transform:translateY(-50%);font-size:12.5px;font-weight:800;color:#FF3B30;letter-spacing:.03em;text-transform:uppercase;pointer-events:none;user-select:none}
.ipv-social-row{display:flex;align-items:center;gap:7px;padding:6px calc(clamp(10px,.65vw + 6.8px,12px) * var(--ccm-s)) 0;cursor:pointer}
.ipv-social-avatars{display:flex;flex-shrink:0}
.ipv-social-avatars img{width:calc(clamp(17px,3vw + 2.3px,20px) * var(--ccm-s));height:calc(clamp(17px,3vw + 2.3px,20px) * var(--ccm-s));border-radius:50%;object-fit:cover;border:1.6px solid rgba(200,203,214,.65);margin-left:-7px}
.ipv-social-avatars img:first-child{margin-left:0}
.ipv-likes-text{font-size:calc(clamp(11.5px,.33vw + 9.9px,12.5px) * var(--ccm-s));color:#fff}
.ipv-likes-text b{font-weight:700}.ipv-likes-dim{color:rgba(220,224,255,.72);font-weight:400}
.ipv-caption{display:none;padding:5px calc(clamp(10px,.65vw + 6.8px,12px) * var(--ccm-s)) 0;font-size:calc(clamp(11px,.37vw + 9.6px,12.5px) * var(--ccm-s));line-height:1.5;color:var(--cap);word-break:break-word}
.ipv-post.ipv-long-header .ipv-caption{display:block}
.ipv-caption b.ipv-cap-user{margin-right:5px;color:var(--red)}
.ipv-more-toggle{color:var(--more);cursor:pointer;font-weight:700}.ipv-more-wrap{cursor:pointer}.ipv-ellipsis{color:var(--cap);font-weight:400}
.dz-tag,.dz-mention{font-weight:600;text-decoration:none;cursor:pointer;-webkit-tap-highlight-color:transparent}
.dz-tag{color:var(--gw)}
.dz-mention{color:var(--red)}
.dz-tag:hover,.dz-mention:hover{text-decoration:underline}
.ipv-time{padding:6px calc(clamp(10px,.65vw + 6.8px,12px) * var(--ccm-s)) 0;font-size:10px;color:rgba(220,224,255,.4);letter-spacing:.3px}
.ipv-time.ipv-time-row{display:flex;align-items:center;justify-content:space-between;gap:8px}
.ipv-time-left{display:flex;align-items:center;flex-wrap:wrap;gap:2px 10px;min-width:0}
.ipv-zeem-hint{color:var(--red);font-weight:300;font-size:10px;letter-spacing:.2px}

.ipv-post.ipv-post-text .ipv-header{border-bottom:1px solid rgba(255,255,255,.13)}
.ipv-post.ipv-post-text .ipv-actions{border-top:1px solid rgba(255,255,255,.13)}

.ipv-post:has(+ .ipv-post.ipv-long-header),.hp-sugg:has(+ .ipv-post.ipv-long-header),.hp-mp:has(+ .ipv-post.ipv-long-header),.hp-rise:has(+ .ipv-post.ipv-long-header),.hp-coll:has(+ .ipv-post.ipv-long-header){border-bottom:none}

.ipv-double-tap-heart{position:absolute;z-index:14;pointer-events:none;transform:translate(-50%,-50%) scale(0);opacity:0}
.ipv-double-tap-heart i{font-size:calc(clamp(60px,7.7vw + 22px,84px) * var(--ccm-s));color:#ff2d4f;filter:drop-shadow(0 6px 20px rgba(255,45,79,.6));-webkit-text-stroke:1.3px rgba(255,255,255,.32)}
.ipv-double-tap-heart.show{animation:ipvHeartBurst .95s cubic-bezier(.17,.89,.32,1.28) forwards}
@keyframes ipvHeartBurst{0%{transform:translate(-50%,-50%) scale(0) rotate(-28deg);opacity:0}14%{transform:translate(-50%,-50%) scale(1.35) rotate(10deg);opacity:1}28%{transform:translate(-50%,-50%) scale(.88) rotate(-6deg);opacity:1}42%{transform:translate(-50%,-50%) scale(1.14) rotate(4deg);opacity:1}58%{transform:translate(-50%,-50%) scale(.97);opacity:1}75%{transform:translate(-50%,-50%) scale(1.05);opacity:1}100%{transform:translate(-50%,-50%) scale(1.15) translateY(-40px);opacity:0}}
.ipv-heart-ring{position:absolute;z-index:13;pointer-events:none;width:95px;height:95px;margin-left:-47.5px;margin-top:-47.5px;border-radius:50%;border:3px solid rgba(255,45,79,.62);transform:scale(.25);opacity:0}
.ipv-heart-ring.show{animation:ipvRingPulse .75s cubic-bezier(.15,.6,.35,1) forwards}
@keyframes ipvRingPulse{0%{transform:scale(.25);opacity:.9;border-width:4px}100%{transform:scale(2.3);opacity:0;border-width:1px}}
.ipv-heart-particle{position:absolute;z-index:12;pointer-events:none;left:0;top:0;transform:translate(-50%,-50%) scale(0);opacity:0;color:#ff2d4f}
.ipv-heart-particle.show{animation:ipvParticleFly .8s cubic-bezier(.2,.7,.3,1) forwards}
@keyframes ipvParticleFly{0%{transform:translate(-50%,-50%) scale(0);opacity:0}12%{opacity:1}100%{transform:translate(calc(-50% + var(--tx)),calc(-50% + var(--ty))) scale(1) rotate(var(--tr));opacity:0}}

.ipv-skel-post{border-bottom:3px solid var(--post-sep);padding-bottom:12px;margin-bottom:0}
.ipv-skel-header{display:flex;align-items:center;gap:10px;padding:11px 12px}
.ipv-skel-avatar{width:34px;height:34px;border-radius:50%;flex-shrink:0}
.ipv-skel-header-lines{flex:1;display:flex;flex-direction:column;gap:6px}
.ipv-skel-line{height:10px;border-radius:5px}.ipv-skel-line.w1{width:120px}.ipv-skel-line.w2{width:75px;height:8px}
.ipv-skel-media{width:100%;aspect-ratio:4/3.5}
.ipv-skel-actions{display:flex;gap:18px;padding:9px 12px 0}.ipv-skel-icon{width:22px;height:22px;border-radius:6px}
.ipv-skel-cap{margin:11px 12px 0;height:10px;border-radius:5px;width:82%}.ipv-skel-cap2{margin:6px 12px 0;height:10px;border-radius:5px;width:52%}
.ipv-load-more{display:flex;align-items:center;justify-content:center;padding:24px 0}
.ipv-load-more-dot{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.35);margin:0 3px;animation:ipvLoadDot 1.1s ease-in-out infinite}
.ipv-load-more-dot:nth-child(2){animation-delay:.15s}.ipv-load-more-dot:nth-child(3){animation-delay:.3s}
@keyframes ipvLoadDot{0%,80%,100%{transform:scale(.6);opacity:.4}40%{transform:scale(1);opacity:1}}
@keyframes ipvPostFadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.ipv-post-enter{animation:ipvPostFadeIn .45s cubic-bezier(.22,1,.36,1) both}

/* ═══════════ "NOT INTERESTED" — "Post hidden · Undo" row that replaces the post in the feed (same as the home + search pages) ═══════════ */
.hp-nint-ph{display:flex;align-items:center;gap:clamp(11px,6.1px + 1vw,14px);padding:clamp(14px,10.7px + .67vw,16px) 14px;border-bottom:3px solid var(--post-sep);box-sizing:border-box;animation:hpNintIn .28s ease both}
@keyframes hpNintIn{from{opacity:0}to{opacity:1}}
.hp-nint-body{flex:1;display:flex;flex-direction:column;justify-content:center;min-width:0;gap:2px}
.hp-nint-body b{color:#fff;font-size:clamp(.74rem,.64rem + .49vw,.88rem);font-weight:600;line-height:1.3}
.hp-nint-body span{color:rgba(255,255,255,.5);font-size:clamp(.64rem,.55rem + .42vw,.76rem);line-height:1.45}
.hp-nint-undo{flex-shrink:0;background:#2b2e3b;color:#fff;border:0;border-radius:5px;padding:0 clamp(12px,5px + 2.1vw,22px);height:var(--rp-action-h);min-height:32px;font-family:'Poppins',sans-serif;font-weight:600;font-size:clamp(.7rem,.61rem + .42vw,.82rem);cursor:pointer;transition:background .2s,transform .15s}
.hp-nint-undo:hover,.hp-nint-undo:active{background:#363a49}
.hp-nint-undo:active{transform:scale(.97)}
.hp-nint-ph.hp-nint-done .hp-nint-body b{color:rgba(255,255,255,.75)}

/* ═══════════ EMPTY STATE ═══════════ */
.empty-state{min-height:52vh;padding:calc(clamp(28px,8vw,48px) * var(--ccm-s)) calc(clamp(20px,7vw,36px) * var(--ccm-s)) calc(clamp(36px,10vw,60px) * var(--ccm-s));display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
.es-title{font-size:calc(clamp(.95rem,3.4vw,1.1rem) * var(--ccm-s));font-weight:700;color:#fff;letter-spacing:-.2px}
.es-text{margin-top:6px;max-width:340px;font-size:calc(clamp(.66rem,2.1vw,.76rem) * var(--ccm-s));color:var(--muted);line-height:1.7}
.es-tips{width:100%;max-width:360px;margin-top:calc(clamp(16px,5vw,24px) * var(--ccm-s));display:flex;flex-direction:column;gap:7px}
.es-tip{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--rp-action-radius);background:rgba(255,255,255,.05);text-align:left}
.es-tip i{font-size:1rem;color:var(--red);flex-shrink:0}
.es-tip span{font-size:calc(clamp(.62rem,2vw,.7rem) * var(--ccm-s));color:var(--sub);line-height:1.5}
.es-btn{margin-top:calc(clamp(16px,5vw,22px) * var(--ccm-s));display:flex;align-items:center;justify-content:center;height:var(--rp-action-h);min-width:150px;padding:0 22px;border-radius:var(--rp-action-radius);background:rgba(255,255,255,.1);color:#fff;font-size:calc(clamp(.62rem,1.6vw + 2.1px,.72rem) * var(--ccm-s));font-weight:600;text-decoration:none;transition:background .2s ease,transform .15s ease}
.es-btn:hover{background:rgba(255,255,255,.2)}
.es-btn:active{transform:scale(.98)}

/* ═══════════ SUGGESTED USERS ROW ═══════════ */
.hp-sugg{padding:12px 0 2px;border-top:none;border-bottom:3px solid var(--post-sep)}
.hp-sugg-head,.hp-mp-head{display:flex;align-items:center;justify-content:space-between;padding:0 14px 10px}
.hp-mp-head{padding-bottom:4px}
.hp-sugg-kicker,.hp-mp-kicker,.hp-coll-kicker{font-size:calc(clamp(.56rem,.96vw + 4.3px,.62rem) * var(--ccm-s));color:rgba(255,255,255,.45);line-height:1.2}
.hp-sugg-title,.hp-mp-title,.hp-coll-title{font-size:calc(clamp(.82rem,2.1vw + 2.9px,.95rem) * var(--ccm-s));font-weight:600;color:#fff;line-height:1.3}
.hp-mp-title{display:flex;align-items:center;gap:6px}
.hp-mp-title i{color:var(--red)}
.hp-sugg-x,.hp-mp-x{color:rgba(255,255,255,.7);font-size:22px;display:flex;padding:2px}
.hp-sugg-scroll-wrap,.hp-mp-scroll-wrap{position:relative}
.hp-sugg-scroll,.hp-mp-scroll{display:flex;overflow-x:auto;scroll-padding-left:14px;scroll-snap-type:x proximity;scrollbar-width:none;-ms-overflow-style:none;-webkit-overflow-scrolling:touch}
.hp-sugg-scroll{gap:8px;padding:0 14px 14px;scroll-padding-right:14px}
.hp-mp-scroll{gap:10px;padding:0 14px 16px}
.hp-sugg-scroll::-webkit-scrollbar,.hp-mp-scroll::-webkit-scrollbar{display:none}
.hp-sugg-scroll::after,.hp-mp-scroll::after{content:"";flex:0 0 6px}
.hp-sugg-card{flex:0 0 calc(clamp(172px,33vw + 10px,205px) * var(--ccm-s));scroll-snap-align:start;background:var(--lane);border:1px solid rgba(255,255,255,.12);border-radius:10px;overflow:hidden;display:flex;flex-direction:column}
.hp-sugg-photo{display:block;width:100%;aspect-ratio:1/1.05;object-fit:cover;background:var(--lane)}
.hp-sugg-info{padding:9px 10px 0}
.hp-sugg-name{display:block;font-size:calc(clamp(.74rem,1.28vw + 5.6px,.82rem) * var(--ccm-s));font-weight:600;color:#fff;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hp-sugg-reason{font-size:calc(clamp(.58rem,1.12vw + 3.8px,.65rem) * var(--ccm-s));color:rgba(200,205,255,.6);margin-top:2px;min-height:1em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hp-sugg-btns{display:flex;gap:6px;padding:8px 8px 10px;margin-top:auto}
.hp-sugg-btn,.hp-sugg-rm{flex:1;padding:calc(clamp(6px,1vw + 1.1px,7px) * var(--ccm-s)) 0;border-radius:7px;font-size:calc(clamp(.66rem,.96vw + 5.9px,.72rem) * var(--ccm-s));font-weight:600;color:#fff;text-align:center;-webkit-tap-highlight-color:transparent}
.hp-sugg-btn{flex:1.35;background:#FF2D2D}
.hp-sugg-btn.following{background:rgba(255,45,45,.16);color:#ff5a5a}
.hp-sugg-rm{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);color:#e6e8f5}

/* ═══════════ MEMEPEDIA FEATURED ROW ═══════════ */
.hp-mp{padding:14px 0 2px;border-top:none;border-bottom:3px solid var(--post-sep)}
.hp-mp-pills{display:flex;gap:7px;overflow-x:auto;padding:8px 14px 10px;scrollbar-width:none;-ms-overflow-style:none}
.hp-mp-pills::-webkit-scrollbar{display:none}
.hp-mp-pill{flex-shrink:0;padding:calc(clamp(5px,1vw + .1px,6px) * var(--ccm-s)) calc(clamp(11px,3vw - 3.7px,14px) * var(--ccm-s));border-radius:6px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.05);color:#fff;font-size:calc(clamp(.66rem,.96vw + 5.9px,.72rem) * var(--ccm-s));font-weight:600;white-space:nowrap;transition:all .2s;-webkit-tap-highlight-color:transparent}
.hp-mp-pill:hover{background:rgba(255,255,255,.1)}
.hp-mp-pill.active{background:var(--red);border-color:var(--red)}
.hp-mp-card{flex:0 0 calc(clamp(210px,50vw - 35px,260px) * var(--ccm-s));scroll-snap-align:start;border-radius:6px;overflow:hidden;position:relative;background:#111226;border:1px solid rgba(255,255,255,.1);cursor:pointer;-webkit-tap-highlight-color:transparent;transition:transform .15s ease}
.hp-mp-card:active{transform:scale(.97)}
.hp-mp-thumb,.hp-mp-thumb-empty{width:100%;aspect-ratio:16/13;display:block;background:linear-gradient(135deg,#262b3d,#171a24)}
.hp-mp-thumb{object-fit:cover}
.hp-mp-thumb-empty{display:flex;align-items:center;justify-content:center;font-size:1.8rem}
.hp-mp-badge{position:absolute;top:8px;left:8px;display:flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;background:rgba(10,11,30,.75);backdrop-filter:blur(4px);font-size:.62rem;font-weight:700;color:#a9bdf5;border:1px solid rgba(120,150,255,.35)}
.hp-mp-badge i{font-size:.68rem}
.hp-mp-info{padding:9px 10px 11px}
.hp-mp-card-title{font-size:calc(clamp(.72rem,.96vw + 6.8px,.78rem) * var(--ccm-s));font-weight:700;color:#fff;line-height:1.32;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:4px}
.hp-mp-card-meta{font-size:calc(clamp(.58rem,.64vw + 6.15px,.62rem) * var(--ccm-s));color:rgba(255,255,255,.4);display:flex;align-items:center;gap:8px}
.hp-mp-card-meta i{font-size:.68rem}
.hp-mp-skel-pill{flex-shrink:0;width:70px;height:26px;border-radius:6px}
.hp-mp-skel-card{flex:0 0 calc(clamp(210px,50vw - 35px,260px) * var(--ccm-s));border-radius:6px;overflow:hidden;background:#111226}
.hp-mp-skel-thumb{width:100%;aspect-ratio:16/13}
.hp-mp-skel-line{height:9px;border-radius:5px;margin:9px 10px 0}


/* ═══════════ "BECAUSE YOU LIKED…" REASON LINE ═══════════ */
.ipv-reason{height:var(--rsn);display:flex;align-items:center;gap:6px;padding:0 calc(clamp(10px,.65vw + 6.8px,12px) * var(--ccm-s));font-size:calc(clamp(10px,.5vw + 7.5px,11px) * var(--ccm-s));color:rgba(220,224,255,.6);overflow:hidden}
.ipv-reason i{color:var(--red);font-size:13px;flex-shrink:0}
.ipv-reason span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ipv-reason b{color:#fff;font-weight:600}

/* ═══════════ RISING ROW ═══════════ */
.hp-rise{padding:14px 0 2px;border-top:none;border-bottom:3px solid var(--post-sep)}
.hp-rise-slot{display:none}
.hp-rise-card{flex:0 0 calc(clamp(128px,30vw,150px) * var(--ccm-s));scroll-snap-align:start;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:transform .15s ease}
.hp-rise-card:active{transform:scale(.97)}
.hp-rise-media{position:relative;border-radius:8px;overflow:hidden;background:#111226;border:1px solid rgba(255,255,255,.1)}
.hp-rise-thumb{width:100%;aspect-ratio:3/4;object-fit:cover;display:block}
.hp-rise-text{display:flex;align-items:center;justify-content:center;padding:12px;text-align:center;font-size:.72rem;line-height:1.4;color:#fff;background:linear-gradient(135deg,#262b3d,#171a24);overflow:hidden;word-break:break-word}
.hp-rise-badge{position:absolute;top:7px;left:7px;display:flex;align-items:center;gap:3px;padding:3px 8px;border-radius:6px;background:rgba(10,11,30,.75);backdrop-filter:blur(4px);font-size:.6rem;font-weight:700;color:#ffb36b;border:1px solid rgba(255,150,60,.35)}
.hp-rise-info{display:flex;align-items:center;gap:6px;padding:7px 2px 0}
.hp-rise-av{width:20px;height:20px;border-radius:50%;object-fit:cover;border:1.4px solid var(--red);flex-shrink:0}
.hp-rise-name{flex:1;min-width:0;font-size:.68rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hp-rise-likes{font-size:.64rem;color:rgba(255,255,255,.55);display:flex;align-items:center;gap:3px;flex-shrink:0}

/* ═══════════ COLLECTIONS ROW ═══════════ */
.hp-coll{padding:12px 0 4px;border-top:none;border-bottom:3px solid var(--post-sep)}
.hp-coll-head{padding:0 14px 10px}
.hp-coll-row{display:flex;align-items:center;gap:calc(clamp(10px,2vw + .2px,12px) * var(--ccm-s));padding:calc(clamp(8px,1vw + 3.1px,9px) * var(--ccm-s)) calc(clamp(12px,2vw + 2.2px,14px) * var(--ccm-s));border-bottom:1px solid rgba(255,255,255,.055);cursor:pointer;-webkit-tap-highlight-color:transparent;transition:background .15s}
.hp-coll-row:last-of-type{border-bottom:none}
.hp-coll-row:hover{background:rgba(255,255,255,.03)}
.hp-coll-thumb{width:calc(clamp(42px,6vw + 12.6px,48px) * var(--ccm-s));height:calc(clamp(42px,6vw + 12.6px,48px) * var(--ccm-s));border-radius:9px;object-fit:cover;background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.14);flex-shrink:0;display:block}
.hp-coll-thumb-empty{display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.32);font-size:19px}
.hp-coll-info{flex:1;min-width:0}
.hp-coll-name{font-size:calc(clamp(.76rem,1.12vw + 6.7px,.83rem) * var(--ccm-s));font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hp-coll-meta{font-size:calc(clamp(.63rem,1.12vw + 4.6px,.7rem) * var(--ccm-s));color:rgba(255,255,255,.42);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:5px}
.hp-coll-count{color:#fff;font-weight:600}
.hp-coll-owner{color:rgba(255,255,255,.42)}
.hp-coll-vis{font-size:.62rem;font-weight:600;padding:1px 6px;border-radius:5px;border:1px solid rgba(255,255,255,.16);color:rgba(255,255,255,.5);flex-shrink:0}
.hp-coll-chevron{color:rgba(255,255,255,.3);font-size:20px;flex-shrink:0}
.hp-coll-more-row{display:flex;align-items:center;gap:16px;padding:calc(clamp(9px,1vw + 4.1px,10px) * var(--ccm-s)) calc(clamp(12px,2vw + 2.2px,14px) * var(--ccm-s)) calc(clamp(7px,1vw + 2.1px,8px) * var(--ccm-s))}
.hp-coll-more-btn,.hp-coll-less-btn{display:flex;align-items:center;gap:3px;color:var(--red);font-size:.74rem;font-weight:700;-webkit-tap-highlight-color:transparent}
.hp-coll-skel-row{display:flex;align-items:center;gap:12px;padding:9px 14px}
.hp-coll-skel-thumb{width:48px;height:48px;border-radius:9px;flex-shrink:0}
.hp-coll-skel-info{flex:1;min-width:0;display:flex;flex-direction:column;gap:6px}
.hp-coll-skel-line{height:10px;border-radius:5px}
.hp-coll-skel-line.w1{width:55%}.hp-coll-skel-line.w2{width:35%;height:8px}

/* ═══════════ LIKES / REPOSTS BOTTOM SHEET — same design as the immersive viewer ═══════════ */
.ipv-likers-overlay{display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.55);align-items:flex-end;justify-content:center;opacity:0;transition:opacity .2s ease}
.ipv-likers-overlay.ipv-open{display:flex;opacity:1}
.ipv-likers-panel{width:100%;max-width:470px;max-height:70vh;background:var(--lane);border-radius:16px 16px 0 0;border:1px solid var(--border,rgba(255,255,255,.08));border-bottom:none;padding:10px 0 18px;display:flex;flex-direction:column;transform:translateY(100%);transition:transform .28s cubic-bezier(.22,1,.36,1);font-family:'Poppins',sans-serif}
.ipv-likers-overlay.ipv-open .ipv-likers-panel{transform:translateY(0)}
.ipv-likers-handle{width:36px;height:4px;border-radius:3px;background:rgba(255,255,255,.25);margin:0 auto 12px}
.ipv-likers-list{overflow-y:auto;padding:2px 0 6px}
.ipv-likers-tabs{display:flex;gap:6px;padding:0 16px 10px;border-bottom:1px solid var(--border,rgba(255,255,255,.07))}
.ipv-likers-tab{background:none;border:none;font-family:inherit;font-size:12.5px;font-weight:700;color:rgba(220,224,255,.5);padding:6px 14px;border-radius:20px;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:color .15s ease,background .15s ease}
.ipv-likers-tab.active{color:#fff;background:rgba(255,45,45,.14)}
.ipv-liker-row{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border,rgba(255,255,255,.07))}
.ipv-liker-avatar{width:38px;height:38px;border-radius:50%;object-fit:cover;border:1.5px solid var(--red,#FF2D2D);flex-shrink:0}
.ipv-liker-name{font-size:13px;font-weight:600;color:#fff;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ipv-liker-icon{font-size:14px;color:var(--red,#FF2D2D);flex-shrink:0}
.ipv-likers-empty{padding:24px 16px;text-align:center;font-size:12.5px;color:rgba(220,224,255,.5)}

/* ═══════════ RESPONSIVE view: no scrollbars anywhere — scrolling still works ═══════════ */
@media(max-width:768px){
    html,body,*{scrollbar-width:none!important;-ms-overflow-style:none!important}
    *::-webkit-scrollbar,html::-webkit-scrollbar,body::-webkit-scrollbar{display:none!important;width:0!important;height:0!important;background:transparent!important}
}

/* topbar, follow bar and tag bar use 10% (feed below them stays at 15.5%) */
@media(max-width:768px){:root{--ccm-s:1.155}.hp-topbar,.follow-wrap{--ccm-s:1.1}}
.hp-topbar{--tb-ic:calc(clamp(1.15rem,3.5vw + 6.8px,1.5rem) * var(--ccm-s))}

<?php endif; ?>
/* ═══════════════════════════════════════════════════════════════════════════
   LIGHT THEME — element colours
   Everything below only applies when <html data-theme="light"> is set, so dark mode is never touched.
   Text colours come from --ui-text / --ui-sub / --ui-sub2 / --ui-mute (top of this file); lines and soft fills
   come from --fg, so a change there is picked up here too. Pieces that sit ON a photo (tags, counters, mute button,
   the header of tall posts, the red buttons) deliberately keep their white-on-dark look.
   ═══════════════════════════════════════════════════════════════════════════ */

/* ── top bar ── */
[data-theme="light"] .hft-btn,
[data-theme="light"] .hft-title,
[data-theme="light"] .rp-tb-btn,
[data-theme="light"] .hp-topbar .notif-bell{color:var(--ui-text)}
[data-theme="light"] .hft-btn:hover,
[data-theme="light"] .hft-btn:active,
[data-theme="light"] .rp-tb-btn:hover{color:var(--red)}
[data-theme="light"] .rp-tb-dot,
[data-theme="light"] .fb-badge{border-color:var(--lane)}
[data-theme="light"] .follow-user p{color:var(--ui-text)}

/* ── post header: username, nickname, audio / AI line, menu (⋯) button, track dot ── */
[data-theme="light"] .ipv-username,
[data-theme="light"] .ipv-kebab{color:var(--ui-text)}
[data-theme="light"] .ipv-sub-line,
[data-theme="light"] .ipv-ai-line,
[data-theme="light"] .ipv-audio-text,
[data-theme="light"] .ipv-reason{color:var(--ui-sub)}
[data-theme="light"] .ipv-reason b{color:var(--ui-text)}
[data-theme="light"] .ipv-dot-sep{color:rgba(var(--fg),.4)}
[data-theme="light"] .ipv-eq-bar{background:rgba(var(--fg),.6)}
[data-theme="light"] .ipv-ad-tag{color:#e0291f}

/* ── posts whose header sits ON the photo keep white text (they have a dark fade behind them) ── */
[data-theme="light"] .ipv-post.ipv-long-header .ipv-dot-sep{color:rgba(255,255,255,.6)}
[data-theme="light"] .ipv-post.ipv-long-header .ipv-eq-bar{background:rgba(255,255,255,.75)}

/* ── action rail: like / repost / save / share icons + numbers ── */
[data-theme="light"] .ipv-action-btn,
[data-theme="light"] .ipv-action-count{color:var(--ui-text)}
[data-theme="light"] .ipv-action-btn.active{color:var(--red)}

/* ── liked-by line, date, text-post borders ── */
[data-theme="light"] .ipv-likes-text,
[data-theme="light"] .ipv-likes-text b{color:var(--ui-text)}
[data-theme="light"] .ipv-likes-dim{color:var(--ui-sub2)}
[data-theme="light"] .ipv-social-avatars img{border-color:rgba(var(--fg),.28)}
[data-theme="light"] .ipv-time{color:var(--ui-mute)}
[data-theme="light"] .ipv-post.ipv-post-text .ipv-header{border-bottom-color:rgba(var(--fg),.14)}
[data-theme="light"] .ipv-post.ipv-post-text .ipv-actions{border-top-color:rgba(var(--fg),.14)}
[data-theme="light"] .ipv-load-more-dot{background:rgba(var(--fg),.35)}

/* ── "Post hidden · Undo" row ── */
[data-theme="light"] .hp-nint-body b{color:var(--ui-text)}
[data-theme="light"] .hp-nint-body span{color:var(--ui-sub)}
[data-theme="light"] .hp-nint-ph.hp-nint-done .hp-nint-body b{color:var(--ui-sub2)}
[data-theme="light"] .hp-nint-undo{background:rgba(var(--fg),.09);color:var(--ui-text)}
[data-theme="light"] .hp-nint-undo:hover,
[data-theme="light"] .hp-nint-undo:active{background:rgba(var(--fg),.16)}

/* ── likes / reposts sheet (engagement modal) ── */
[data-theme="light"] .ipv-likers-panel{box-shadow:0 -8px 30px rgba(0,0,0,.14)}
[data-theme="light"] .ipv-likers-handle{background:rgba(var(--fg),.2)}
[data-theme="light"] .ipv-likers-tab,
[data-theme="light"] .ipv-likers-empty{color:var(--ui-sub)}
[data-theme="light"] .ipv-likers-tab.active{color:var(--ui-text);background:rgba(255,45,45,.12)}
[data-theme="light"] .ipv-liker-name{color:var(--ui-text)}

/* ── empty state ── */
[data-theme="light"] .es-title{color:var(--ui-text)}
[data-theme="light"] .es-tip{background:rgba(var(--fg),.05)}
[data-theme="light"] .es-btn{background:rgba(var(--fg),.09);color:var(--ui-text)}
[data-theme="light"] .es-btn:hover{background:rgba(var(--fg),.16)}

/* ── right-hand column (buttons, rows, chips, about text, sponsored) ── */
[data-theme="light"] .pf-bar,
[data-theme="light"] .pf-more{background:rgba(var(--fg),.08);color:var(--ui-text)}
[data-theme="light"] .pf-bar:hover,
[data-theme="light"] .pf-more:hover{background:rgba(var(--fg),.14)}
[data-theme="light"] .pf-bar-c{color:rgba(var(--fg),.55)}
[data-theme="light"] .pf-bar-chev{color:rgba(var(--fg),.7)}
[data-theme="light"] .pf-row{color:var(--ui-text);border-bottom-color:rgba(var(--fg),.08)}
[data-theme="light"] .pf-row-t{color:var(--ui-text)}
[data-theme="light"] .pf-row-s{color:var(--ui-sub)}
[data-theme="light"] .pf-av{background:var(--surface-2)}
[data-theme="light"] .pf-av.sq{border-color:rgba(var(--fg),.16);color:rgba(var(--fg),.4)}
[data-theme="light"] .pf-track.following{background:rgba(var(--fg),.09);color:var(--ui-text)}
[data-theme="light"] .pf-chip{background:rgba(var(--fg),.07);color:var(--ui-text)}
[data-theme="light"] a.pf-chip:hover{background:rgba(var(--fg),.14)}
[data-theme="light"] .pf-chip.on{background:var(--red);color:#000}
[data-theme="light"] .pf-about p{color:var(--ui-sub2)}
[data-theme="light"] .pf-about b{color:var(--ui-text)}
[data-theme="light"] .rs-ad-card:hover{background:rgba(var(--fg),.05)}
[data-theme="light"] .rs-ad-thumb{background:var(--surface-2)}
[data-theme="light"] .rs-ad-caption{color:var(--ui-text)}
[data-theme="light"] .rs-divider{background:rgba(var(--fg),.1)}
[data-theme="light"] .rs-link-row{color:var(--ui-text)}
[data-theme="light"] .rs-link-row:hover{background:rgba(var(--fg),.05)}
[data-theme="light"] .hp-right-rail{scrollbar-color:rgba(var(--fg),.25) transparent}
[data-theme="light"] .hp-right-rail::-webkit-scrollbar-thumb{background:rgba(var(--fg),.22)}

/* ── suggested people row ── */
[data-theme="light"] .hp-sugg-kicker,
[data-theme="light"] .hp-mp-kicker,
[data-theme="light"] .hp-coll-kicker{color:rgba(var(--fg),.55)}
[data-theme="light"] .hp-sugg-title,
[data-theme="light"] .hp-mp-title,
[data-theme="light"] .hp-coll-title,
[data-theme="light"] .hp-sugg-name{color:var(--ui-text)}
[data-theme="light"] .hp-sugg-x,
[data-theme="light"] .hp-mp-x{color:rgba(var(--fg),.7)}
[data-theme="light"] .hp-sugg-card{border-color:rgba(var(--fg),.14)}
[data-theme="light"] .hp-sugg-reason{color:var(--ui-sub)}
[data-theme="light"] .hp-sugg-rm{background:rgba(var(--fg),.08);border-color:rgba(var(--fg),.16);color:var(--ui-text)}

/* ── Memepedia + rising rows ── */
[data-theme="light"] .hp-mp-pill{background:rgba(var(--fg),.05);border-color:rgba(var(--fg),.18);color:var(--ui-text)}
[data-theme="light"] .hp-mp-pill:hover{background:rgba(var(--fg),.1)}
[data-theme="light"] .hp-mp-pill.active{background:var(--red);border-color:var(--red);color:var(--on-accent)}
[data-theme="light"] .hp-mp-card,
[data-theme="light"] .hp-rise-media,
[data-theme="light"] .hp-mp-skel-card{background:var(--surface-2);border-color:rgba(var(--fg),.12)}
[data-theme="light"] .hp-mp-card-title,
[data-theme="light"] .hp-rise-name{color:var(--ui-text)}
[data-theme="light"] .hp-mp-card-meta,
[data-theme="light"] .hp-rise-likes{color:rgba(var(--fg),.55)}

/* ── collections row ── */
[data-theme="light"] .hp-coll-row{border-bottom-color:rgba(var(--fg),.07)}
[data-theme="light"] .hp-coll-row:hover{background:rgba(var(--fg),.04)}
[data-theme="light"] .hp-coll-thumb{background:rgba(var(--fg),.08);border-color:rgba(var(--fg),.16)}
[data-theme="light"] .hp-coll-thumb-empty{color:rgba(var(--fg),.4)}
[data-theme="light"] .hp-coll-name,
[data-theme="light"] .hp-coll-count{color:var(--ui-text)}
[data-theme="light"] .hp-coll-meta,
[data-theme="light"] .hp-coll-owner{color:rgba(var(--fg),.55)}
[data-theme="light"] .hp-coll-vis{color:rgba(var(--fg),.6);border-color:rgba(var(--fg),.2)}
[data-theme="light"] .hp-coll-chevron{color:rgba(var(--fg),.35)}

/* ── loading skeletons ── */
[data-theme="light"] .rs-ad-skel-thumb,
[data-theme="light"] .rs-ad-skel-line,
[data-theme="light"] .ipv-skel-avatar,
[data-theme="light"] .ipv-skel-line,
[data-theme="light"] .ipv-skel-media,
[data-theme="light"] .ipv-skel-icon,
[data-theme="light"] .ipv-skel-cap,
[data-theme="light"] .ipv-skel-cap2,
[data-theme="light"] .hp-mp-skel-pill,
[data-theme="light"] .hp-mp-skel-thumb,
[data-theme="light"] .hp-mp-skel-line,
[data-theme="light"] .hp-coll-skel-thumb,
[data-theme="light"] .hp-coll-skel-line{background:linear-gradient(90deg,rgba(var(--fg),.06) 25%,rgba(var(--fg),.13) 37%,rgba(var(--fg),.06) 63%);background-size:400% 100%}

/* ── shared_modals.php — post menu (⋯) sheet ── */
[data-theme="light"] .idx-sheet-panel,
[data-theme="light"] .svsh-panel{background:var(--lane);border-top-color:var(--border);box-shadow:0 -10px 40px rgba(0,0,0,.14)}
[data-theme="light"] .idx-sheet-handle,
[data-theme="light"] .svsh-handle{background:rgba(var(--fg),.2)}
[data-theme="light"] .idx-sheet-item,
[data-theme="light"] .idx-sheet-item-title,
[data-theme="light"] .idx-detail-title,
[data-theme="light"] .idx-detail-poster-name,
[data-theme="light"] .idx-detail-back{color:var(--ui-text)}
[data-theme="light"] .idx-sheet-item{border-bottom-color:rgba(var(--fg),.08)}
[data-theme="light"] .idx-sheet-item:active{background:rgba(var(--fg),.06)}
[data-theme="light"] .idx-sheet-item.danger,
[data-theme="light"] .idx-sheet-item.danger .idx-sheet-item-title{color:#d92b2b}
[data-theme="light"] .idx-sheet-item-sub,
[data-theme="light"] .idx-detail-poster-sub{color:var(--ui-sub)}
[data-theme="light"] .idx-sheet-cancel{background:rgba(var(--fg),.07);color:var(--ui-text)}
[data-theme="light"] .idx-detail-header{border-bottom-color:rgba(var(--fg),.08)}
[data-theme="light"] .idx-detail-back{background:rgba(var(--fg),.07)}
[data-theme="light"] .idx-detail-general{color:var(--ui-sub2)}
[data-theme="light"] .idx-detail-unique{color:var(--ui-text)}
[data-theme="light"] .idx-detail-divider::before,
[data-theme="light"] .idx-detail-divider::after{background:rgba(var(--fg),.1)}
[data-theme="light"] .idx-detail-divider span{color:var(--ui-mute)}
[data-theme="light"] .idx-detail-hide-btn{color:#d92b2b}

/* ── shared_modals.php — save to collection sheet ── */
[data-theme="light"] .svsh-row,
[data-theme="light"] .svsh-section-label,
[data-theme="light"] .svsh-row-title,
[data-theme="light"] .svsh-create-title,
[data-theme="light"] .svsh-toggle-title,
[data-theme="light"] .svsh-back-btn,
[data-theme="light"] .svsh-done-btn{color:var(--ui-text)}
[data-theme="light"] .svsh-done-btn{color:#f24455}
[data-theme="light"] .svsh-row{border-bottom-color:rgba(var(--fg),.08)}
[data-theme="light"] .svsh-row:active{background:rgba(var(--fg),.06)}
[data-theme="light"] .svsh-row-sub,
[data-theme="light"] .svsh-toggle-sub{color:var(--ui-sub)}
[data-theme="light"] .svsh-chevron{color:rgba(var(--fg),.3)}
[data-theme="light"] .svsh-col-thumb,
[data-theme="light"] .svsh-back-btn{background:rgba(var(--fg),.07)}
[data-theme="light"] .svsh-add-btn{border-color:rgba(var(--fg),.3);color:var(--ui-text)}
[data-theme="light"] .svsh-add-btn.svsh-added{background:#f24455;border-color:#f24455;color:#fff}
[data-theme="light"] .svsh-create-topbar{border-bottom-color:rgba(var(--fg),.08)}
[data-theme="light"] .svsh-name-input{background:rgba(var(--fg),.05);border-color:rgba(var(--fg),.14);color:var(--ui-text)}
[data-theme="light"] .svsh-name-input::placeholder{color:rgba(var(--fg),.4)}
[data-theme="light"] .svsh-required-hint{color:rgba(var(--fg),.5)}
[data-theme="light"] .svsh-required-hint.svsh-error{color:#d92b2b}
[data-theme="light"] .svsh-switch-slider{background:rgba(var(--fg),.2)}
[data-theme="light"] .svsh-switch-slider::before{box-shadow:0 1px 3px rgba(0,0,0,.3)}

/* ── shared_modals.php — report screens ── */
[data-theme="light"] #dzReportOverlay,
[data-theme="light"] #dzRptStep1Screen,
[data-theme="light"] #dzRptStep2Screen{--rp-card:rgba(var(--fg),.05);--rp-line:rgba(var(--fg),.1);--rp-line2:rgba(var(--fg),.16);--rp-sub:rgba(var(--fg),.62);--rp-mut:rgba(var(--fg),.5);background:var(--lane);color:var(--ui-text)}
[data-theme="light"] .dzrpt-topbar-title,
[data-theme="light"] .dzrpt-close-btn,
[data-theme="light"] .dzrpt-cat-label,
[data-theme="light"] .dzrpt-success-title,
[data-theme="light"] .dzrpt-userid-input,
[data-theme="light"] .dzrpt-other-textarea,
[data-theme="light"] .dzrpt-reason-row.selected .dzrpt-reason-text{color:var(--ui-text)}
[data-theme="light"] .dzrpt-close-btn:hover{color:var(--red)}
[data-theme="light"] .dzrpt-reason-text{color:rgba(var(--fg),.88)}
[data-theme="light"] .dzrpt-reason-row.selected .dzrpt-reason-text{color:var(--ui-text)}
[data-theme="light"] .dzrpt-check{border-color:rgba(var(--fg),.3)}
[data-theme="light"] .dzrpt-cancel-btn{background:rgba(var(--fg),.08);color:var(--ui-text)}
[data-theme="light"] .dzrpt-cancel-btn:hover{background:rgba(var(--fg),.14)}

/* ── text_feed.php — "Write a text" + "Tag people" sheets and the posting box ── */
[data-theme="light"] .wsh{--card:rgba(var(--fg),.05);--card2:rgba(var(--fg),.08);--line:rgba(var(--fg),.1);--line2:rgba(var(--fg),.16);--sub:rgba(var(--fg),.62);--mut:rgba(var(--fg),.5);color:var(--ui-text);box-shadow:0 0 0 200vmax rgba(0,0,0,.35)}
[data-theme="light"] .w-close,
[data-theme="light"] .w-caption,
[data-theme="light"] .w-row,
[data-theme="light"] .w-input,
[data-theme="light"] .w-tg-chip,
[data-theme="light"] .w-tg-row,
[data-theme="light"] .w-chip{color:var(--ui-text)}
[data-theme="light"] .w-chip{background:rgba(var(--fg),.08)}
[data-theme="light"] .w-chip:hover{background:rgba(var(--fg),.14)}
[data-theme="light"] .w-tg-chip button{background:rgba(var(--fg),.12);color:var(--ui-text)}
[data-theme="light"] .w-tg-av,
[data-theme="light"] .w-tg-chip img{background:var(--surface-2)}
[data-theme="light"] .w-slider{background:rgba(var(--fg),.2)}
[data-theme="light"] .w-actionbar{background:linear-gradient(-45deg,var(--bg-start),var(--bg-end),var(--bg-end))}
[data-theme="light"] .w-pg{background:rgba(0,0,0,.45)}
[data-theme="light"] .w-pg-card{background:var(--surface-2);border-color:rgba(var(--fg),.14);color:var(--ui-text)}
[data-theme="light"] .w-pg-bar{background:rgba(var(--fg),.1)}
[data-theme="light"] .w-pg-row{color:var(--ui-sub)}
[data-theme="light"] .w-pg-row b{color:var(--ui-text)}

/* ═══════════════════════════════════════════════════════════════════════════
   LIGHT THEME — profile page, group page and their modals / sheets
   Same idea as the section above: only applies when <html data-theme="light"> is set, so dark mode is never touched.
   Text uses --ui-text / --ui-sub / --ui-sub2 / --ui-mute; lines and soft fills use --fg.
   (rules here use !important because several of these elements are styled by #id selectors or inline.)
   Red / blue buttons and anything sitting ON a photo or video keep white-on-dark.
   ═══════════════════════════════════════════════════════════════════════════ */

/* ── ZEEMS MODAL is never themed: inside it every colour variable is pinned to its original dark value (light OR dark mode), so it looks exactly as it did before ── */
#cardCaptionModal,
#cardCaptionModal *,
.ccm-ni-pop,
.ccm-ni-pop *{
    --bg-start:#010218; --bg-end:#070924; --lane:#070924; --post-sep:#02030e; --border:rgba(255,255,255,.07);
    --ink:#06071A; --text:#fff; --muted:rgba(255,255,255,.45); --sub:rgba(255,255,255,.62);
    --cap:rgba(255,255,255,.85); --gw:rgba(255,255,255,.65); --more:#fff;
    --surface-2:#0d1030; --surface-3:#090b29; --surface-deep:#05061a; --fg:255,255,255;
    --ui-text:#fff; --ui-sub:rgba(255,255,255,.62); --ui-sub2:rgba(255,255,255,.62); --ui-mute:rgba(255,255,255,.45);
}
[data-theme="light"] #cardCaptionModal{color:#fff}

/* ── generated: every remaining white text / white line / white fill on profile, group and their modals ── */
[data-theme="light"] .rp-topbar-username{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .rp-hl-ring::after{border:var(--rp-hl-ring) solid rgba(var(--fg,6,7,26),0.28) !important}
[data-theme="light"] .rp-hl-name{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .grid .meme-card .card-badges .dz-type-icon{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .dz-skel-card{background:rgba(var(--fg,6,7,26),0.05) !important}
[data-theme="light"] .ft-thumb{background:rgba(var(--fg,6,7,26),0.06) !important}
[data-theme="light"] .grid.lay-list .meme-card .media-box{background:rgba(var(--fg,6,7,26),0.05) !important}
[data-theme="light"] .dz-sk{background:rgba(var(--fg,6,7,26),0.06) !important}
[data-theme="light"] #userKebab .ukb-row{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .dzm-scroll::-webkit-scrollbar-thumb{background:rgba(var(--fg,6,7,26),0.15) !important}
[data-theme="light"] .dzm-item{border-top:1px solid rgba(var(--fg,6,7,26),.08) !important}
[data-theme="light"] .svsh-screen::-webkit-scrollbar-thumb{background:rgba(var(--fg,6,7,26),0.15) !important}
[data-theme="light"] .svsh-col-thumb{background:rgba(var(--fg,6,7,26),0.08) !important}
[data-theme="light"] .pf-side::-webkit-scrollbar-thumb{background:rgba(var(--fg,6,7,26),.22) !important}
[data-theme="light"] .pf-side::-webkit-scrollbar-thumb:hover{background:rgba(var(--fg,6,7,26),.34) !important}
[data-theme="light"] .cw-box{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #gpJqModal{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #gpSortSheet{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gf-username{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gf-dot-sep{color:rgba(var(--fg,6,7,26),0.35) !important}
[data-theme="light"] .gf-sub-line{color:var(--ui-mute,#5f647a) !important}
[data-theme="light"] .gf-eq-bar{background:rgba(var(--fg,6,7,26),.65) !important}
[data-theme="light"] .gf-audio-text{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .gf-kebab{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gf-partnership-tag b{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gf-action-btn{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gf-action-count{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gf-likes-text{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gf-likes-dim{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .gf-time{color:rgba(var(--fg,6,7,26),0.4) !important}
[data-theme="light"] .gf-post.gf-post-text .gf-header{border-bottom:1px solid rgba(var(--fg,6,7,26),.13) !important}
[data-theme="light"] .gf-post.gf-post-text .gf-actions{border-top:1px solid rgba(var(--fg,6,7,26),.13) !important}
[data-theme="light"] .gf-file-card{background:rgba(var(--fg,6,7,26),.08) !important}
[data-theme="light"] .gf-file-card:hover{background:rgba(var(--fg,6,7,26),.12) !important}
[data-theme="light"] .gf-file-card:active{background:rgba(var(--fg,6,7,26),.16) !important}
[data-theme="light"] .gf-file-title{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gf-file-sub{color:var(--ui-mute,#5f647a) !important}
[data-theme="light"] .gf-load-more-dot{background:rgba(var(--fg,6,7,26),.35) !important}
[data-theme="light"] .gf-empty{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .gf-hidden-text{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .gf-undo-btn{color:var(--ui-text,#0b0d24) !important;background:rgba(var(--fg,6,7,26),.07) !important;border:1px solid rgba(var(--fg,6,7,26),.15) !important}
[data-theme="light"] .gx-sheet{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #ageGateOverlay{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #ageGateLearnMoreModal{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .ipv-overlay::-webkit-scrollbar-track{background:rgba(var(--fg,6,7,26),.04) !important}
[data-theme="light"] .ipv-overlay::-webkit-scrollbar-thumb{background:rgba(var(--fg,6,7,26),.28) !important}
[data-theme="light"] .ipv-overlay::-webkit-scrollbar-thumb:hover{background:rgba(var(--fg,6,7,26),.5) !important}
[data-theme="light"] .ipv-topbar-username-sm{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .ipv-dot-sep{color:rgba(var(--fg,6,7,26),0.35) !important}
[data-theme="light"] .ipv-sub-line{color:var(--ui-mute,#5f647a) !important}
[data-theme="light"] .ipv-audio-text{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .ipv-action-count{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .ipv-likes-dim{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .ipv-time{color:rgba(var(--fg,6,7,26),0.4) !important}
[data-theme="light"] .ipv-likers-tab{color:var(--ui-mute,#5f647a) !important}
[data-theme="light"] .ipv-likers-empty{color:var(--ui-mute,#5f647a) !important}
[data-theme="light"] .ipv-load-more-dot{background:rgba(var(--fg,6,7,26),.35) !important}
[data-theme="light"] .idx-sheet-list::-webkit-scrollbar-thumb{background:rgba(var(--fg,6,7,26),0.15) !important}
[data-theme="light"] .idx-detail-body::-webkit-scrollbar-thumb{background:rgba(var(--fg,6,7,26),0.15) !important}
[data-theme="light"] .pkbm-overlay{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .pkbm-body::-webkit-scrollbar-thumb{background:rgba(var(--fg,6,7,26),.28) !important}
[data-theme="light"] .pkbm-body::-webkit-scrollbar-thumb:hover{background:rgba(var(--fg,6,7,26),.45) !important}
[data-theme="light"] .hl-mc-head{color:var(--ui-mute,#5f647a) !important}
[data-theme="light"] .hl-mc-list{border-bottom:6px solid rgba(var(--fg,6,7,26),.05) !important}
[data-theme="light"] .hl-mc-row{border-bottom:1px solid rgba(var(--fg,6,7,26),.06) !important}
[data-theme="light"] .hl-mc-main{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .hl-mc-date{color:rgba(var(--fg,6,7,26),0.4) !important}
[data-theme="light"] .am-modal{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .am-body::-webkit-scrollbar-thumb{background:rgba(var(--fg,6,7,26),.28) !important}
[data-theme="light"] .am-body::-webkit-scrollbar-thumb:hover{background:rgba(var(--fg,6,7,26),.45) !important}
[data-theme="light"] .pm-overlay{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .pm-body::-webkit-scrollbar-thumb{background:rgba(var(--fg,6,7,26),.28) !important}
[data-theme="light"] .pm-pic-overlay i{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #dzReportOverlay{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #dzRptStep1Screen{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #dzRptStep2Screen{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .hl-ci-un{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .hl-ci-t{color:rgba(var(--fg,6,7,26),0.4) !important}
[data-theme="light"] .hl-ci-more{color:var(--ui-mute,#5f647a) !important}
[data-theme="light"] .hl-ci-more:hover{color:var(--ui-text,#0b0d24) !important;background:rgba(var(--fg,6,7,26),.08) !important}
[data-theme="light"] .hl-ci-tx{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .hl-ci-like{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .hl-ci-dis{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .hl-ci-reply{color:var(--ui-mute,#5f647a) !important}
[data-theme="light"] .hl-ci-reply:hover{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .hl-ci-tg{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .hl-ci-sm{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .hl-ci-sm:hover{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .pm-vol-row span:last-child{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #gsmModal{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #youModal{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #podInfoModal{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #inviteModal{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #gfPostReportOverlay{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #gfPostRptStep1Screen{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #gfPostRptStep2Screen{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #dzGroupReportOverlay{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #dzGrpRptStep2Screen{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gfd-hidden-search-btn{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gfd-hidden-title{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gfd-hidden-sub{color:var(--ui-mute,#5f647a) !important}
[data-theme="light"] .gfd-hidden-undo{color:var(--ui-text,#0b0d24) !important;background:rgba(var(--fg,6,7,26),.08) !important;border:1px solid rgba(var(--fg,6,7,26),.18) !important}

/* ── group feed + profile: usernames, nicknames, liked-by, files, hidden-post row (written by hand) ── */
[data-theme="light"] .gf-username,[data-theme="light"] .gf-kebab,[data-theme="light"] .gf-action-group:not(.active) .gf-action-btn,[data-theme="light"] .gf-action-count,
[data-theme="light"] .gf-likes-text,[data-theme="light"] .gf-likes-text b,[data-theme="light"] .gf-file-title{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gf-sub-line,[data-theme="light"] .gf-audio-text,[data-theme="light"] .gf-ai-line,[data-theme="light"] .gf-file-sub,[data-theme="light"] .gf-hidden-text{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .gf-likes-dim{color:var(--ui-sub2,#42465a) !important}
[data-theme="light"] .gf-time,[data-theme="light"] .gf-date{color:var(--ui-mute,#5f647a) !important}
[data-theme="light"] .gf-dot-sep{color:rgba(var(--fg,6,7,26),.4) !important}
[data-theme="light"] .gf-undo-btn{background:rgba(var(--fg,6,7,26),.09) !important;color:var(--ui-text,#0b0d24) !important}

/* ── fixes: things that sit ON a photo stay white; group top bar turns dark once it has a solid background ── */
[data-theme="light"] .gf-post.gf-long-header .gf-username,[data-theme="light"] .gf-post.gf-long-header .gf-sub-line,[data-theme="light"] .gf-post.gf-long-header .gf-audio-text,[data-theme="light"] .gf-post.gf-long-header .gf-ai-line,[data-theme="light"] .gf-post.gf-long-header .gf-kebab{color:#fff !important}
[data-theme="light"] .gf-post.gf-long-header .gf-dot-sep{color:rgba(255,255,255,.6) !important}
[data-theme="light"] .grid .meme-card .card-badges .dz-type-icon{color:#fff !important}
[data-theme="light"] .gp-topbar.scrolled .gp-bbtn,[data-theme="light"] .gp-topbar.scrolled .gp-bbtn.back,[data-theme="light"] .gp-topbar.scrolled .gp-bbtn i,[data-theme="light"] .gp-topbar.scrolled .gp-topbar-title{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gp-topbar.scrolled .gp-bbtn{background:rgba(var(--fg,6,7,26),.08) !important}
[data-theme="light"] .gp-topbar.scrolled .gp-bbtn.back{background:transparent !important}
[data-theme="light"] .g-pill .g-pill-count,[data-theme="light"] .msg-pill .req-badge,[data-theme="light"] .g-pill.active .g-pill-count,[data-theme="light"] .msg-pill.active .req-badge{color:var(--ui-text,#0b0d24) !important}

/* ── always-dark surfaces keep their original white words: profile-picture viewer, toasts ── */
.modal, .modal *,
.rp-email-menu, .rp-email-menu *,
.rp-hl-arrow, .rp-hl-arrow *,
#hlModal, #hlModal *,
#hlLoader, #hlLoader *,
.hl-nav, .hl-nav *,
.hl-toast, .hl-toast *,
.hl-cm-sortmenu, .hl-cm-sortmenu *,
.hl-cm-menu, .hl-cm-menu *,
#dzVaToast, #dzVaToast *, .mr-toast, .mr-toast *, .ep-toast, .ep-toast *, .g-toast, .g-toast *, #gp-toast, #gp-toast *, #discLockToast, #discLockToast *{
    --text:#fff; --fg:255,255,255; --ui-text:#fff; --ui-sub:rgba(255,255,255,.62); --ui-sub2:rgba(255,255,255,.62); --ui-mute:rgba(255,255,255,.45);
    --cap:rgba(255,255,255,.85); --gw:rgba(255,255,255,.65); --more:#fff; --muted:rgba(255,255,255,.45); --sub:rgba(255,255,255,.62);
}
/* ── long posts (header sits on the photo): nickname + audio line + menu stay white ── */
[data-theme="light"] .ipv-post.ipv-long-header .ipv-username,[data-theme="light"] .ipv-post.ipv-long-header .ipv-sub-line,[data-theme="light"] .ipv-post.ipv-long-header .ipv-ai-line,[data-theme="light"] .ipv-post.ipv-long-header .ipv-audio-text,[data-theme="light"] .ipv-post.ipv-long-header .ipv-kebab,[data-theme="light"] .ipv-post.ipv-long-header .ipv-new-tag{color:#fff !important}

/* ── carousel dots sit on the photo: always white (active = accent); filled buttons keep their active colour; "more" toggles ── */
[data-theme="light"] .ipv-dot,[data-theme="light"] .gf-dot{background:rgba(255,255,255,.35) !important}
[data-theme="light"] .ipv-dot.active{background:var(--red) !important}
[data-theme="light"] .gf-dot.active{background:var(--feed-blue,var(--red)) !important}
[data-theme="light"] .gf-action-group.active .gf-action-btn{color:var(--feed-blue,var(--red)) !important}
[data-theme="light"] .ipv-action-btn.active,[data-theme="light"] .ipv-action-group.active .ipv-action-btn{color:var(--red) !important}
[data-theme="light"] .gf-more-toggle{font-weight:700 !important;-webkit-text-stroke:.25px currentColor}
[data-theme="light"] .gp-dot{background:rgba(var(--fg,6,7,26),.4) !important}

/* ── highlights: static ring a bit darker; the spinning comet ring is dark too (it was white) ── */
[data-theme="light"] .rp-hl-ring::after{border-color:rgba(var(--fg,6,7,26),.45) !important}
[data-theme="light"] .rp-hl.spin .rp-hl-ring::after{border-color:transparent !important}
[data-theme="light"] .rp-hl-svg rect,[data-theme="light"] .gh-svg rect{stroke:rgba(var(--fg,6,7,26),.75) !important}

/* ── contacts dropdown stays dark with white words in both modes ── */
[data-theme="light"] .rp-email-menu .rp-email-item{color:#fff !important}
[data-theme="light"] .rp-email-item:hover{background:rgba(255,255,255,.07) !important}

/* ── group pause sheets (Learn more / Manage) and other dark-literal sheets become real light sheets ── */
[data-theme="light"] .gp-sheet,[data-theme="light"] #userKebab .ukb-sheet{background:var(--lane,#fff) !important;color:var(--ui-text,#0b0d24) !important;border-color:var(--border) !important;box-shadow:0 -10px 40px rgba(0,0,0,.14)}
[data-theme="light"] .gp-sheet h4,[data-theme="light"] .gp-sheet strong,[data-theme="light"] .gp-sheet .gp-hl{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] .gp-sheet .sub{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] .gp-sheet p,[data-theme="light"] .gp-list li{color:var(--ui-sub2,#42465a) !important}
[data-theme="light"] .gp-sheet .cancel{color:var(--ui-text,#0b0d24) !important;background:rgba(var(--fg,6,7,26),.07) !important}
[data-theme="light"] .gp-grid-head{background:var(--lane,#fff) !important}

/* ── group pin / admin grey buttons (.gx-btn.ghost, e.g. "Pin a post") ── */
[data-theme="light"] .gx-btn.ghost{color:var(--ui-text,#0b0d24) !important;background:rgba(var(--fg,6,7,26),.1) !important}
[data-theme="light"] .gx-btn.ghost:hover{background:rgba(var(--fg,6,7,26),.17) !important}

/* ── group "New post" modal (#postModal): it sets its own white text, so in light mode everything inside it was white-on-white ── */
[data-theme="light"] #postModal{color:var(--ui-text,#0b0d24) !important;--pm-blue-l:#1c47b8;--pm-mut:rgba(var(--fg,6,7,26),.62);--pm-sub:rgba(var(--fg,6,7,26),.72)}
[data-theme="light"] #postModal .pm-lbl,
[data-theme="light"] #postModal .pm-user-name,
[data-theme="light"] #postModal .pm-tg-inf strong,
[data-theme="light"] #postModal .pm-audio-lib-title,
[data-theme="light"] #postModal .pm-zeem-connected-caption{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #postModal .pm-drafts-btn.pm-drafts-active{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #postModal .pm-tg-chip{color:var(--ui-text,#0b0d24) !important}
[data-theme="light"] #postModal .upload-error{color:#b3202a !important}
[data-theme="light"] #postModal .pm-optional{color:var(--ui-sub,#52566a) !important}
[data-theme="light"] #postModal .hashtag-hint,
[data-theme="light"] #postModal .hashtag-hint.valid,
[data-theme="light"] #postModal .hashtag-hint.invalid{color:#1c47b8 !important}