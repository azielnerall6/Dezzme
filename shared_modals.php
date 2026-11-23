<?php
/* ═══════════════════════════════════════════════════════════════
   shared_modals.php — Dezzme shared bottom sheets & modals
   Contains:
     1. Save to Collection sheet   → openSaveModal(memeId, onChange)
     2. Post menu (index sheet)    → openIndexSheet(memeId)
     3. Report modal               → openReportModal({...})

   USE ON ANY PAGE (just before </body>):
       include 'shared_modals.php';   (inside a PHP block)

   The page that includes it must provide (for the post menu):
       window.kebabData   — map of memeId → post data (shared_core.php now adds a "why" list to every item)
       currentUserId      — logged-in user's id
       showToast(msg)     — optional (falls back to its own toast)
   Remix Icon + Poppins must already be loaded by the host page.

   v11 (post menu):
     • "Why am I seeing this post / ad" now shows the REAL reasons from shared_core.php (d.why): topics you chose or
       like, creators you track or picked, reposts / likes from people you track, people with a similar taste,
       new-post testing, popularity. A button opens Your Algorithm (algorithm_settings.php).
     • Normal posts now have "Interested" too (for now it only says thanks — it changes nothing in the feed yet).
     • "Not Interested" is fixed: it no longer depends on the page's own button. If the page has no
       notInterested(), the sheet sends the request itself (needs window.DZ_CSRF when CSRF is enforced), hides the
       post on the page, fires a "dz:not-interested" event, and shows an Undo bar (undo_not_interested).
     • Ad "Why am I seeing this" no longer puts the whole post in an onclick attribute (it looks the post up by id).

   Opening this file directly in the browser shows a small test
   page so you can try all three modals.
   ═══════════════════════════════════════════════════════════════ */

/* never render twice if included more than once on a page */
if (defined('DZ_SHARED_MODALS_LOADED')) { return; }
define('DZ_SHARED_MODALS_LOADED', true);

/* Which parts to render. A host page can set, BEFORE including this file:
       $dz_sm_sections = ['save','index'];      // e.g. profile + group page (they keep their own Report modal)
   Default = all three. */
function dz_sm_want($s){
    global $dz_sm_sections;
    return !isset($dz_sm_sections) || !is_array($dz_sm_sections) || in_array($s, $dz_sm_sections, true);
}

/* are we being opened directly (standalone) rather than included? */
$dzStandalone = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME']);

if ($dzStandalone):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Dezzme — Shared Modals</title>
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body{margin:0;min-height:100vh;background:linear-gradient(-45deg,#010218,#070924,#070924);
     font-family:'Poppins',sans-serif;color:#fff;display:flex;flex-direction:column;
     align-items:center;justify-content:center;gap:12px;padding:20px;}
.dz-test-btn{background:#f24455;color:#000;border:none;border-radius:8px;padding:12px 22px;
     font-family:'Poppins',sans-serif;font-weight:700;font-size:14px;cursor:pointer;min-width:240px;}
</style>
</head>
<body>
<h3 style="margin:0 0 8px;">Shared modals test</h3>
<button class="dz-test-btn" onclick="openSaveModal(1)">Open Save to Collection</button>
<button class="dz-test-btn" onclick="openIndexSheet(1)">Open Post Menu (normal)</button>
<button class="dz-test-btn" onclick="openIndexSheet(2)">Open Post Menu (ad)</button>
<button class="dz-test-btn" onclick="openReportModal()">Open Report</button>
<script>
/* test stubs — real pages provide these */
var currentUserId = 99;
window.kebabData = {
    1: {id:1, caption:'Test meme', type:'image', fileName:'test.jpg', uploadedBy:5, remixOf:0, isAd:false,
        why:[
            {icon:'ri-user-follow-line', title:'You track @tester', text:'Posts from people you track are lifted in your feed.'},
            {icon:'ri-lightbulb-flash-line', title:'It matches topics you like: #gym, glutes', text:'Your recent likes, saves, reposts and time spent on posts point to these topics.'}
        ]},
    2: {id:2, caption:'Test ad',  type:'image', fileName:'test.jpg', uploadedBy:5, isAd:true,
        posterUsername:'testadvertiser', posterPic:'mee.jpg',
        why:[{icon:'ri-lightbulb-flash-line', title:'It matches topics you like: #gym', text:'Your recent likes, saves, reposts and time spent on posts point to these topics.'}]}
};
</script>
<?php endif; ?>

<?php if (dz_sm_want('save')): ?>
<!-- ═══ SAVE TO COLLECTION · POST MENU (index sheet) · REPORT — all sizes 10% bigger ═══ -->
<style id="dzShEarly">/* keep the sheets invisible until their own CSS (further down) has been read: no flash of unstyled words */
#svshOverlay,#svshPanel,#indexSheetOverlay,#indexSheetPanel{display:none !important}</style>
<div id="svshOverlay" class="svsh-overlay" onclick="svshClose()"></div>
<div id="svshPanel" class="svsh-panel">
    <div class="svsh-screens" id="svshScreens">

        <!-- SCREEN 1: main sheet -->
        <div class="svsh-screen">
            <div class="svsh-handle"></div>

            <div class="svsh-card">
            <div class="svsh-section-label">Add to a collection</div>

            <div class="svsh-row svsh-create-row" onclick="svshOpenCreate()">
                <i class="ri-folder-add-line svsh-row-icon"></i>
                <div class="svsh-row-text">
                    <div class="svsh-row-title">Create a new collection</div>
                    <div class="svsh-row-sub">Save posts to a new collection</div>
                </div>
                <i class="ri-arrow-right-s-line svsh-chevron"></i>
            </div>

            <div class="svsh-collections-list" id="svshCollectionsList"></div>
            </div>
            <button type="button" class="svsh-close-btn" onclick="svshClose()">Close</button>
        </div>

        <!-- SCREEN 2: create collection -->
        <div class="svsh-screen">
            <div class="svsh-handle"></div>
            <div class="svsh-card">
                <div class="svsh-section-label">Create a collection</div>

                <div class="svsh-create-body">
                    <input type="text" id="svshNameInput" class="svsh-name-input"
                           placeholder="Collection name" maxlength="60" oninput="svshValidateName()">
                    <div class="svsh-required-hint" id="svshRequiredHint">Required</div>

                    <div class="svsh-toggle-row">
                        <div class="svsh-toggle-text">
                            <div class="svsh-toggle-title">Set the collection to public</div>
                            <div class="svsh-toggle-sub">Anyone can view this collection</div>
                        </div>
                        <label class="svsh-switch">
                            <input type="checkbox" id="svshPublicToggle">
                            <span class="svsh-switch-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="svsh-actions">
                    <button type="button" class="svsh-done-btn" id="svshDoneBtn" onclick="svshCreateCollection()">Done</button>
                </div>
            </div>
            <button type="button" class="svsh-close-btn" onclick="svshBackToMain()">Back</button>
        </div>

    </div>
</div>
<style>
/* pages that never set --bs-s (home, text feed, search) got scale 1; phones get the same +10% the profile and group pages use */
:root{ --dz-s-def:1; }
@media (max-width:768px){ :root{ --dz-s-def:1.1; } }
/* sharp corners */
#svshPanel, .svsh-panel, .svsh-name-input, .svsh-done-btn{ border-radius:0 !important; }

.svsh-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,0.6);
    backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    z-index:999999996;opacity:0;pointer-events:none;
    transition:opacity 0.25s ease;
}
.svsh-overlay.show{opacity:1;pointer-events:all;}

.svsh-panel{
    position:fixed;left:0;right:0;bottom:0;
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-top:1px solid rgba(255,255,255,0.08);
    border-radius:22px 22px 0 0;
    z-index:999999997;
    overflow:hidden;
    max-height:82vh;
    display:flex;flex-direction:column;
    transform:translateY(100%);
    transition:transform 0.3s cubic-bezier(0.32,0.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,0.5);
    font-family:Poppins,sans-serif;
}
.svsh-panel.show{transform:translateY(0);}

.svsh-screens{
    display:flex;width:200%;
    transition:transform .3s cubic-bezier(.32,.72,0,1);
}
.svsh-panel.svsh-create-mode .svsh-screens{ transform:translateX(-50%); }

.svsh-screen{
    width:50%;
    flex-shrink:0;
    padding:8.8px 0 max(11px, env(safe-area-inset-bottom));
    max-height:82vh;
    overflow-y:auto;
    box-sizing:border-box;
    -webkit-overflow-scrolling:touch;
}
.svsh-screen::-webkit-scrollbar{width:4px;}
.svsh-screen::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

.svsh-handle{
    width:37.4px;height:3.85px;border-radius:1098.9px;
    background:rgba(255,255,255,0.2);
    margin:0 auto 4.4px;flex-shrink:0;
}

.svsh-section-label{
    font-size:14.3px;font-weight:700;color:#fff;
    padding:13.2px 19.8px 6.6px;
}

/* ── ROW ITEMS — matched to idx-sheet-item ── */
.svsh-row{
    display:flex;align-items:center;gap:12.1px;
    padding:9.9px 19.8px;
    color:#fff;text-decoration:none;
    font-size:14.3px;font-weight:500;
    font-family:Poppins,sans-serif;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background 0.15s ease;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.svsh-row:active{background:rgba(255,255,255,0.06);}
.svsh-row-icon{
    font-size:17.05px;color:#f24455;width:19.8px;text-align:center;flex-shrink:0;
}

.svsh-row-text{ flex:1; min-width:0; }
.svsh-row-title{
    font-size:14.3px;font-weight:600;color:#fff;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.svsh-row-sub{
    font-size:12.1px;color:rgba(255,255,255,0.45);
    margin-top:2.2px;display:flex;align-items:center;gap:4.4px;
}

.svsh-chevron{ font-size:19.8px; color:rgba(255,255,255,0.25); flex-shrink:0; }

.svsh-collections-list{ margin-top:0; }
.svsh-col-thumb{
    width:37.4px;height:37.4px;flex-shrink:0;border-radius:9.9px;
    object-fit:cover;background:rgba(255,255,255,0.08);
}
.svsh-col-thumb-empty{
    display:flex;align-items:center;justify-content:center;
    color:#f24455;font-size:17.6px;
}

.svsh-add-btn{
    width:28.6px;height:28.6px;flex-shrink:0;border-radius:50%;
    border:1.5px solid rgba(255,255,255,0.22);
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:16.5px;
    transition:all .15s ease;
    -webkit-tap-highlight-color:transparent;
}
.svsh-add-btn.svsh-added{
    background:#f24455;border-color:#f24455;
}

.svsh-loading{
    text-align:center;padding:26.4px;color:rgba(255,255,255,0.35);font-size:14.3px;
}

/* ── CREATE SCREEN ── */
.svsh-create-topbar{
    display:flex;align-items:center;justify-content:space-between;
    padding:6.6px 19.8px 15.4px;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.svsh-back-btn{
    width:33px;height:33px;border-radius:50%;
    background:rgba(255,255,255,0.06);border:none;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:20.9px;cursor:pointer;-webkit-tap-highlight-color:transparent;
    transition:background .18s;
}
.svsh-back-btn:hover{background:rgba(255,45,45,0.18);color:#ff6060;}
.svsh-create-title{ font-size:14.85px;font-weight:700;color:#fff; }
.svsh-done-btn{
    background:none;border:none;color:#f24455;
    font-size:14.3px;font-weight:700;cursor:pointer;padding:6.6px 2.2px;
    font-family:Poppins,sans-serif;-webkit-tap-highlight-color:transparent;
}
.svsh-done-btn:disabled{ opacity:.4; }

.svsh-create-body{ padding:17.6px 19.8px 11px; }
.svsh-name-input{
    width:100%;padding:14.3px 16.5px;
    background:rgba(255,255,255,0.045);
    border:1px solid rgba(255,255,255,0.09);
    border-radius:14.3px;color:#fff;font-size:14.85px;
    font-family:Poppins,sans-serif;outline:none;box-sizing:border-box;
    transition:border-color .2s, background .2s;
}
.svsh-name-input::placeholder{ color:rgba(255,255,255,0.22); }
.svsh-name-input:focus{
    border-color:rgba(255,45,45,0.4);
    background:rgba(255,45,45,0.04);
}

.svsh-required-hint{
    font-size:11.55px;color:rgba(255,255,255,0.25);
    margin:7.7px 2.2px 22px;
}
.svsh-required-hint.svsh-error{ color:#ff6060; }

.svsh-toggle-row{
    display:flex;align-items:center;justify-content:space-between;
    gap:15.4px;padding:6.6px 2.2px;
}
.svsh-toggle-title{ font-size:14.3px;font-weight:600;color:#fff; }
.svsh-toggle-sub{ font-size:11.55px;color:rgba(255,255,255,0.35);margin-top:2.2px; }

.svsh-switch{ position:relative;width:48.4px;height:28.6px;flex-shrink:0; }
.svsh-switch input{ opacity:0;width:0;height:0; }
.svsh-switch-slider{
    position:absolute;inset:0;background:rgba(255,255,255,0.15);
    border-radius:1098.9px;cursor:pointer;transition:.2s;
}
.svsh-switch-slider::before{
    content:'';position:absolute;width:22px;height:22px;left:3.3px;top:3.3px;
    background:#fff;border-radius:50%;transition:.2s;
}
.svsh-switch input:checked + .svsh-switch-slider{ background:#f24455; }
.svsh-switch input:checked + .svsh-switch-slider::before{ transform:translateX(19.8px); }

/* Desktop: centered modal, same as idx-sheet */
@media (min-width:791px){
    .svsh-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:374px;
        border-radius:19.8px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;
        pointer-events:none;
        max-height:70vh;
    }
    .svsh-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;
        pointer-events:all;
    }
    .svsh-handle{display:none;}
}

/* 790px — TABLET */
@media (max-width:790px){
    .svsh-panel{
        border-radius:19.8px 19.8px 0 0;
        max-height:78vh;
    }
    .svsh-screen{ padding:7.7px 0 max(9.9px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:35.2px;height:3.85px;margin:0 auto 4.4px; }
    .svsh-section-label{ padding:12.1px 17.6px 5.5px; font-size:13.75px; }
    .svsh-row{ padding:8.8px 17.6px; font-size:13.75px; gap:11px; }
    .svsh-row-icon{ font-size:16.5px; width:18.7px; }
    .svsh-col-thumb{ width:35.2px;height:35.2px;border-radius:8.8px; }
    .svsh-add-btn{ width:27.5px;height:27.5px;font-size:15.4px; }
    .svsh-create-topbar{ padding:5.5px 17.6px 13.2px; }
    .svsh-create-body{ padding:15.4px 17.6px 9.9px; }
}

/* 490px — LARGE MOBILE */
@media (max-width:490px){
    .svsh-panel{
        border-radius:17.6px 17.6px 0 0;
        max-height:76vh;
        backdrop-filter:blur(16px);
        -webkit-backdrop-filter:blur(16px);
    }
    .svsh-screen{ padding:6.6px 0 max(8.8px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:33px;height:3.3px;margin:0 auto 3.3px; }
    .svsh-section-label{ padding:11px 15.4px 5.5px; font-size:13.2px; }
    .svsh-row{ padding:7.7px 15.4px; font-size:13.2px; gap:9.9px; }
    .svsh-row-icon{ font-size:15.4px; width:17.6px; }
    .svsh-col-thumb{ width:33px;height:33px;border-radius:8.8px; }
    .svsh-add-btn{ width:26.4px;height:26.4px;font-size:14.3px; }
    .svsh-create-topbar{ padding:5.5px 15.4px 12.1px; }
    .svsh-back-btn{ width:29.7px;height:29.7px;font-size:18.7px; }
    .svsh-create-title{ font-size:14.3px; }
    .svsh-create-body{ padding:14.3px 15.4px 8.8px; }
    .svsh-name-input{ font-size:14.3px;padding:13.2px 15.4px;border-radius:13.2px; }
}

/* 390px — STANDARD MOBILE */
@media (max-width:390px){
    .svsh-panel{
        border-radius:15.4px 15.4px 0 0;
        max-height:74vh;
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        box-shadow:0 -8px 30px rgba(0,0,0,0.45);
    }
    .svsh-screen{ padding:6.6px 0 max(7.7px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:30.8px;height:3.3px;margin:0 auto 3.3px; }
    .svsh-section-label{ padding:9.9px 13.2px 4.4px; font-size:12.65px; }
    .svsh-row{ padding:7.15px 13.2px; font-size:12.65px; gap:8.8px; }
    .svsh-row-icon{ font-size:14.85px; width:16.5px; }
    .svsh-col-thumb{ width:30.8px;height:30.8px;border-radius:7.7px; }
    .svsh-add-btn{ width:24.2px;height:24.2px;font-size:13.2px; }
    .svsh-create-topbar{ padding:4.4px 13.2px 11px; }
    .svsh-back-btn{ width:27.5px;height:27.5px;font-size:17.6px; }
    .svsh-create-title{ font-size:13.75px; }
    .svsh-create-body{ padding:13.2px 13.2px 7.7px; }
    .svsh-name-input{ font-size:13.75px;padding:12.1px 14.3px;border-radius:12.1px; }
}

/* 330px — SMALL / COMPACT MOBILE */
@media (max-width:330px){
    .svsh-panel{
        border-radius:13.2px 13.2px 0 0;
        max-height:72vh;
        backdrop-filter:blur(12px);
        -webkit-backdrop-filter:blur(12px);
        box-shadow:0 -6px 24px rgba(0,0,0,0.4);
    }
    .svsh-screen{ padding:5.5px 0 max(6.6px, env(safe-area-inset-bottom)); }
    .svsh-handle{ width:26.4px;height:2.75px;margin:0 auto 3.3px; }
    .svsh-screen::-webkit-scrollbar{width:3px;}
    .svsh-section-label{ padding:8.8px 11px 4.4px; font-size:12.1px; }
    .svsh-row{ padding:6.05px 11px; font-size:11.55px; gap:7.7px; }
    .svsh-row-icon{ font-size:13.75px; width:14.3px; }
    .svsh-col-thumb{ width:28.6px;height:28.6px;border-radius:6.6px; }
    .svsh-add-btn{ width:22px;height:22px;font-size:12.1px; }
    .svsh-create-topbar{ padding:4.4px 11px 8.8px; }
    .svsh-back-btn{ width:25.3px;height:25.3px;font-size:15.4px; }
    .svsh-create-title{ font-size:12.65px; }
    .svsh-create-body{ padding:11px 11px 6.6px; }
    .svsh-name-input{ font-size:12.65px;padding:11px 13.2px;border-radius:11px; }
}
</style>
<style id="dzShDzmSave">
/* ═══ Save-to-collection sheet — the profile page's bottom-sheet look (.dzm-*): sheet › inner card › rows, Close button below ═══ */

#svshPanel.svsh-panel{ --dz-s:var(--bs-s,var(--dz-s-def,1)); --dz-lane:var(--lane,#070924); --dz-card:var(--surface-3,#090b29); --dz-fg:var(--fg,255,255,255); --dz-text:var(--text,#F0F2FF); --dz-red:var(--red,#FF2D2D); background:var(--dz-lane); border:0; border-radius:0 !important; box-shadow:none; backdrop-filter:none; -webkit-backdrop-filter:none;
    padding:calc(5px * var(--dz-s)) 0 calc(env(safe-area-inset-bottom,0px) + calc(10px * var(--dz-s))); gap:calc(8px * var(--dz-s)); color:var(--dz-text); font-family:Poppins,sans-serif; max-height:60vh;  }

[data-theme="light"] #svshPanel{ --dz-lane:var(--lane,#ffffff); --dz-card:var(--surface-3,#eef1f7); --dz-fg:var(--fg,15,20,40); --dz-text:var(--text,#14171f); }
.svsh-overlay{ background:rgba(0,0,0,.6); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px); }
#svshPanel .svsh-screens{ flex:1; min-height:0; }
#svshPanel .svsh-screen{ padding:0 calc(10px * var(--dz-s)); display:flex; flex-direction:column; gap:calc(8px * var(--dz-s)); overflow:hidden; max-height:60vh; box-sizing:border-box; }
#svshPanel .svsh-handle{ width:calc(30px * var(--dz-s)); height:calc(3px * var(--dz-s)); border-radius:2px; background:rgba(var(--dz-fg),.22); margin:0 auto; flex-shrink:0; }
#svshPanel .svsh-card{ background:var(--dz-card); border-radius:calc(6px * var(--dz-s)); overflow-y:auto; min-height:0; flex:0 1 auto; scrollbar-width:thin; scrollbar-color:rgba(var(--dz-fg),.15) transparent; }
#svshPanel .svsh-card::-webkit-scrollbar{ width:calc(4px * var(--dz-s)); }
#svshPanel .svsh-card::-webkit-scrollbar-thumb{ background:rgba(var(--dz-fg),.15); border-radius:999px; }
#svshPanel .svsh-section-label{ padding:calc(7px * var(--dz-s)) calc(12px * var(--dz-s)) calc(3px * var(--dz-s)); font-size:calc(8.5px * var(--dz-s)); font-weight:400; color:rgba(var(--dz-fg),.35); letter-spacing:1px; text-transform:uppercase; }
#svshPanel .svsh-row{ display:flex; align-items:center; gap:calc(9px * var(--dz-s)); padding:calc(6px * var(--dz-s)) calc(12px * var(--dz-s)); color:var(--dz-text); font-size:calc(11.5px * var(--dz-s)); font-weight:600; border:0; border-top:1px solid rgba(var(--dz-fg),.08); transition:background .2s; }
#svshPanel .svsh-section-label + .svsh-row{ border-top:0; }
#svshPanel .svsh-row:hover{ background:rgba(var(--dz-fg),.06); }
#svshPanel .svsh-row:active{ background:rgba(var(--dz-fg),.06); }
#svshPanel .svsh-row-icon,#svshPanel .svsh-col-thumb{
    width:calc(27px * var(--dz-s)); height:calc(27px * var(--dz-s)); border-radius:50%; flex-shrink:0; box-sizing:border-box;
    display:flex; align-items:center; justify-content:center; text-align:center;
    border:1.5px dashed rgba(var(--dz-fg),.3); color:rgba(var(--dz-fg),.6); font-size:calc(14px * var(--dz-s)); background:transparent; }
#svshPanel img.svsh-col-thumb{ display:block; object-fit:cover; border:0; background:rgba(var(--dz-fg),.08); }
#svshPanel .svsh-col-thumb-empty{ color:rgba(var(--dz-fg),.6); font-size:calc(14px * var(--dz-s)); }
#svshPanel .svsh-row-title{ font-size:calc(11.5px * var(--dz-s)); font-weight:600; color:var(--dz-text); }
#svshPanel .svsh-row-sub{ font-size:calc(9px * var(--dz-s)); color:rgba(var(--dz-fg),.4); margin-top:1px; line-height:1.35; }
#svshPanel .svsh-chevron{ font-size:calc(14px * var(--dz-s)); color:rgba(var(--dz-fg),.3); }
#svshPanel .svsh-add-btn{ width:calc(22px * var(--dz-s)); height:calc(22px * var(--dz-s)); font-size:calc(13px * var(--dz-s)); border:1.5px solid rgba(var(--dz-fg),.3); color:var(--dz-text); }
#svshPanel .svsh-add-btn.svsh-added{ background:var(--dz-red); border-color:var(--dz-red); color:#fff; }
#svshPanel .svsh-loading{ padding:calc(18px * var(--dz-s)); font-size:calc(11.5px * var(--dz-s)); color:rgba(var(--dz-fg),.4); }
/* create screen */
#svshPanel .svsh-create-body{ padding:calc(4px * var(--dz-s)) calc(14px * var(--dz-s)) calc(2px * var(--dz-s)); }
#svshPanel .svsh-name-input{ background:rgba(var(--dz-fg),.06); border:0; border-radius:4px; padding:calc(8px * var(--dz-s)) calc(11px * var(--dz-s)); color:var(--dz-text); font-size:calc(11.5px * var(--dz-s)); }
#svshPanel .svsh-name-input:focus{ background:rgba(var(--dz-fg),.1); border:0; }
#svshPanel .svsh-name-input::placeholder{ color:rgba(var(--dz-fg),.25); }
#svshPanel .svsh-required-hint{ font-size:calc(9.5px * var(--dz-s)); color:rgba(var(--dz-fg),.4); margin:calc(5px * var(--dz-s)) 2px calc(8px * var(--dz-s)); }
#svshPanel .svsh-required-hint.svsh-error{ color:var(--dz-red); }
#svshPanel .svsh-toggle-row{ padding:calc(8px * var(--dz-s)) 0; border-top:1px solid rgba(var(--dz-fg),.08); gap:12px; }
#svshPanel .svsh-toggle-title{ font-size:calc(11.5px * var(--dz-s)); font-weight:600; color:var(--dz-text); }
#svshPanel .svsh-toggle-sub{ font-size:calc(9.5px * var(--dz-s)); color:rgba(var(--dz-fg),.45); margin-top:2px; line-height:1.4; }
#svshPanel .svsh-switch{ width:calc(40px * var(--dz-s)); height:calc(23px * var(--dz-s)); }
#svshPanel .svsh-switch-slider{ background:rgba(var(--dz-fg),.18); }
#svshPanel .svsh-switch-slider::before{ top:calc(3px * var(--dz-s)); left:calc(3px * var(--dz-s)); width:calc(17px * var(--dz-s)); height:calc(17px * var(--dz-s)); }
#svshPanel .svsh-switch input:checked + .svsh-switch-slider{ background:var(--dz-red); }
#svshPanel .svsh-switch input:checked + .svsh-switch-slider::before{ transform:translateX(calc(17px * var(--dz-s))); }
#svshPanel .svsh-actions{ padding:calc(4px * var(--dz-s)) calc(12px * var(--dz-s)) calc(12px * var(--dz-s)); }
#svshPanel .svsh-done-btn{ display:flex; align-items:center; justify-content:center; width:100%; height:var(--rp-action-h,calc(38px * var(--dz-s))); padding:0 14px; border:0; border-radius:var(--rp-action-radius,4px);
    background:var(--track-red,#FF2D2D); color:#000; font-size:.72rem; font-weight:700; font-family:inherit; line-height:1; transition:background .2s, transform .15s; }
#svshPanel .svsh-done-btn:hover{ background:var(--track-red-hover,#ff4d4d); }
#svshPanel .svsh-done-btn:active{ transform:scale(.98); }
#svshPanel .svsh-done-btn:disabled{ opacity:.5; pointer-events:none; }
.svsh-close-btn{ display:flex; align-items:center; justify-content:center; gap:calc(6px * var(--dz-s)); width:100%; padding:calc(10px * var(--dz-s)); background:var(--dz-card); border:0; border-radius:calc(6px * var(--dz-s));
    color:rgba(var(--dz-fg),.85); font-size:calc(12px * var(--dz-s)); font-weight:600; font-family:Poppins,sans-serif; cursor:pointer; flex-shrink:0; -webkit-tap-highlight-color:transparent; transition:background .2s, color .2s; }
.svsh-close-btn:hover{ color:var(--dz-red); }
/* desktop: the same centred 360px modal as the profile's */
@media (min-width:791px){
    #svshPanel.svsh-panel{ max-width:360px; width:90vw; border-radius:calc(8px * var(--dz-s)) !important; padding:calc(14px * var(--dz-s)) 0 calc(16px * var(--dz-s)); gap:calc(12px * var(--dz-s)); max-height:80vh; box-shadow:0 16px 40px rgba(0,0,0,.6); }
    #svshPanel .svsh-screen{ padding:0 calc(14px * var(--dz-s)); gap:calc(12px * var(--dz-s)); max-height:70vh; }
    #svshPanel .svsh-card{ border-radius:calc(4px * var(--dz-s)); }
    #svshPanel .svsh-handle{ display:none; }
    #svshPanel .svsh-section-label{ padding:calc(12px * var(--dz-s)) calc(16px * var(--dz-s)) calc(6px * var(--dz-s)); font-size:calc(10px * var(--dz-s)); }
    #svshPanel .svsh-row{ gap:calc(10px * var(--dz-s)); padding:calc(10px * var(--dz-s)) calc(14px * var(--dz-s)); font-size:calc(13px * var(--dz-s)); }
    #svshPanel .svsh-row-icon,#svshPanel .svsh-col-thumb{ width:calc(34px * var(--dz-s)); height:calc(34px * var(--dz-s)); font-size:calc(17px * var(--dz-s)); }
    #svshPanel .svsh-col-thumb-empty{ font-size:calc(17px * var(--dz-s)); }
    #svshPanel .svsh-row-title{ font-size:calc(13px * var(--dz-s)); }
    #svshPanel .svsh-row-sub{ font-size:calc(10px * var(--dz-s)); }
    #svshPanel .svsh-chevron{ font-size:calc(16px * var(--dz-s)); }
    #svshPanel .svsh-add-btn{ width:calc(26px * var(--dz-s)); height:calc(26px * var(--dz-s)); font-size:calc(15px * var(--dz-s)); }
    #svshPanel .svsh-create-body{ padding:calc(4px * var(--dz-s)) calc(14px * var(--dz-s)) calc(2px * var(--dz-s)); }
    #svshPanel .svsh-name-input{ font-size:calc(13px * var(--dz-s)); padding:calc(10px * var(--dz-s)) calc(12px * var(--dz-s)); }
    #svshPanel .svsh-required-hint,#svshPanel .svsh-toggle-sub{ font-size:calc(10.5px * var(--dz-s)); }
    #svshPanel .svsh-toggle-row{ padding:calc(10px * var(--dz-s)) 0; }
    #svshPanel .svsh-toggle-title{ font-size:calc(12.5px * var(--dz-s)); }
    #svshPanel .svsh-switch{ width:calc(44px * var(--dz-s)); height:calc(25px * var(--dz-s)); }
    #svshPanel .svsh-switch-slider::before{ width:calc(19px * var(--dz-s)); height:calc(19px * var(--dz-s)); }
    #svshPanel .svsh-switch input:checked + .svsh-switch-slider::before{ transform:translateX(calc(19px * var(--dz-s))); }
    #svshPanel .svsh-actions{ padding:calc(6px * var(--dz-s)) calc(14px * var(--dz-s)) calc(14px * var(--dz-s)); }
    .svsh-close-btn{ padding:calc(14px * var(--dz-s)); font-size:calc(14px * var(--dz-s)); border-radius:calc(4px * var(--dz-s)); gap:calc(8px * var(--dz-s)); }
}
</style>

<script>
/* Point this at wherever collections_api.php lives relative to the
   current page — same file works on home.php, profile.php, etc. */
var SVSH_ENDPOINT = window.SVSH_ENDPOINT || 'collections_api.php';

let svshMemeId    = null;
let svshCollections = [];
let svshOnChange  = null; // optional callback(state) so the calling page can sync its own button/counts

function svshFetch(body){
    return fetch(SVSH_ENDPOINT, {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    body
    }).then(function(r){ return r.json(); });
}

function openSaveModal(memeId, onChange){
    svshMemeId   = memeId;
    svshOnChange = onChange || null;

    document.getElementById('svshPanel').classList.remove('svsh-create-mode');
    document.getElementById('svshCollectionsList').innerHTML = '<div class="svsh-loading">Loading…</div>';

    document.getElementById('svshOverlay').classList.add('show');
    document.getElementById('svshPanel').classList.add('show');
    document.body.style.overflow = 'hidden';

    svshFetch('col_action=get_my_collections&meme_id='+memeId).then(function(d){
        if(d.status !== 'ok') return;
        svshCollections = d.collections || [];
        svshRenderCollections();
    }).catch(function(){
        document.getElementById('svshCollectionsList').innerHTML =
            '<div class="svsh-loading">Couldn\'t load collections</div>';
    });
}

function svshClose(){
    document.getElementById('svshOverlay').classList.remove('show');
    document.getElementById('svshPanel').classList.remove('show');
    document.body.style.overflow = '';
    setTimeout(function(){
        svshMemeId = null;
        document.getElementById('svshPanel').classList.remove('svsh-create-mode');
        document.getElementById('svshNameInput').value = '';
        document.getElementById('svshPublicToggle').checked = false;
    }, 280);
}

function svshRenderCollections(){
    var list = document.getElementById('svshCollectionsList');
    if(!svshCollections.length){ list.innerHTML = ''; return; }

    list.innerHTML = svshCollections.map(function(c){
        var thumb = c.thumb
            ? '<img src="'+c.thumb+'" class="svsh-col-thumb" onerror="this.src=\'uploads/Text.jpg\'">'
            : '<div class="svsh-col-thumb svsh-col-thumb-empty"><i class="ri-folder-3-line"></i></div>';
        var inCol = !!c.in_collection;
        return '<div class="svsh-row svsh-col-row" onclick="svshToggleCollection('+c.id+')">'+
            thumb+
            '<div class="svsh-row-text">'+
                '<div class="svsh-row-title">'+svshEsc(c.name)+'</div>'+
                '<div class="svsh-row-sub"><i class="'+(c.is_public==1?'ri-earth-line':'ri-lock-line')+'"></i> '+
                    (c.is_public==1?'Public':'Only me')+'</div>'+
            '</div>'+
            '<div class="svsh-add-btn '+(inCol?'svsh-added':'')+'" id="svshAddBtn-'+c.id+'">'+
                '<i class="'+(inCol?'ri-check-line':'ri-add-line')+'"></i>'+
            '</div>'+
        '</div>';
    }).join('');
}

function svshToggleCollection(colId){
    if(!svshMemeId) return;
    var col = svshCollections.find(function(c){ return c.id == colId; });
    if(!col) return;

    var wasIn = !!col.in_collection;
    col.in_collection = !wasIn;
    svshUpdateAddBtn(colId, col.in_collection);

    var action = wasIn ? 'remove_from_collection' : 'add_to_collection';
    svshFetch('col_action='+action+'&col_id='+colId+'&meme_id='+svshMemeId).then(function(d){
        if(d.status !== 'ok' && d.status !== 'already'){
            col.in_collection = wasIn;
            svshUpdateAddBtn(colId, wasIn);
            return;
        }
        if(d.item_count !== undefined) col.item_count = d.item_count;
        svshToast(col.in_collection ? 'Added to '+col.name : 'Removed from '+col.name);
        if(svshOnChange) svshOnChange({ type:'collection', colId:colId, inCollection:col.in_collection });
    }).catch(function(){ col.in_collection = wasIn; svshUpdateAddBtn(colId, wasIn); });
}

function svshUpdateAddBtn(colId, inCol){
    var btn = document.getElementById('svshAddBtn-'+colId);
    if(!btn) return;
    btn.classList.toggle('svsh-added', inCol);
    btn.innerHTML = '<i class="'+(inCol?'ri-check-line':'ri-add-line')+'"></i>';
}

function svshOpenCreate(){
    document.getElementById('svshPanel').classList.add('svsh-create-mode');
    document.getElementById('svshNameInput').value = '';
    document.getElementById('svshPublicToggle').checked = false;
    document.getElementById('svshRequiredHint').classList.remove('svsh-error');
    setTimeout(function(){ document.getElementById('svshNameInput').focus(); }, 260);
}
function svshBackToMain(){
    document.getElementById('svshPanel').classList.remove('svsh-create-mode');
}
function svshValidateName(){
    document.getElementById('svshRequiredHint').classList.remove('svsh-error');
}

function svshCreateCollection(){
    var name = document.getElementById('svshNameInput').value.trim();
    if(!name){ document.getElementById('svshRequiredHint').classList.add('svsh-error'); return; }

    var isPublic = document.getElementById('svshPublicToggle').checked ? 1 : 0;
    var btn = document.getElementById('svshDoneBtn');
    btn.disabled = true;

    svshFetch('col_action=create_collection&name='+encodeURIComponent(name)+'&is_public='+isPublic+'&meme_id='+(svshMemeId||0))
    .then(function(d){
        btn.disabled = false;
        if(d.status !== 'ok'){ svshToast('Could not create collection'); return; }
        svshCollections.unshift({
            id: d.id, name: d.name, is_public: d.is_public,
            item_count: svshMemeId ? 1 : 0, thumb: null, in_collection: !!svshMemeId
        });
        svshRenderCollections();
        svshBackToMain();
        svshToast('Collection "'+d.name+'" created ✓');
        if(svshOnChange) svshOnChange({ type:'created', colId:d.id });
    }).catch(function(){ btn.disabled = false; svshToast('Network error'); });
}

function svshEsc(s){
    if(!s) return '';
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function svshToast(msg){
    if(typeof showToast === 'function'){ showToast(msg); return; } // reuse page's toast if present
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);'+
        'background:rgba(20,20,25,.95);color:#fff;padding:8px 20px;border-radius:50px;'+
        'font-size:12px;z-index:999999999;';
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); }, 2200);
}
</script>

<?php endif; ?>
<?php if (dz_sm_want('index')): ?>
<!-- ═══════════════════════════════════════════════════════
     INDEX BOTTOM SHEET (post-aware: normal post vs post-ad)
     ═══════════════════════════════════════════════════════ -->
<div id="indexSheetOverlay" class="idx-sheet-overlay" onclick="closeIndexSheet()"></div>
<div id="indexSheetPanel" class="idx-sheet-panel">
    <div class="idx-sheet-handle"></div>

    <div class="idx-sheet-track" id="indexSheetTrack">

        <!-- SCREEN A: option list (differs for normal post vs post-ad) -->
        <div class="idx-sheet-screen">
            <div class="idx-sheet-list" id="indexSheetList"></div>
        </div>

        <!-- SCREEN B: "why am I seeing this" detail -->
        <div class="idx-sheet-screen">
            <div class="idx-detail-card">
            <div class="idx-detail-header">
                <button class="idx-detail-back" onclick="idxSheetBackToList()"><i class="ri-arrow-left-s-line"></i></button>
                <div class="idx-detail-title" id="idxDetailTitle">Why you're seeing this</div>
                <span class="idx-detail-spacer"></span>
            </div>
            <div class="idx-detail-body" id="idxDetailBody"></div>
            </div>
        </div>

    </div>

    <button class="idx-sheet-cancel" id="indexSheetCancelBtn" onclick="closeIndexSheet()">Close</button>
</div>

<!-- ═══ COLLABORATORS (post collaboration): the sheet people open from the Zeems header, and the poster's manager ═══ -->
<div id="dzCollabOverlay" class="dzc-overlay" onclick="dzCollabClose()"></div>
<div id="dzCollabPanel" class="dzc-panel" role="dialog" aria-label="Collaborators">
    <div class="dzc-handle"></div>
    <div class="dzc-card">
        <div class="dzc-title" id="dzCollabTitle">Collaborators</div>
        <div class="dzc-body" id="dzCollabBody"></div>
    </div>
    <button type="button" class="dzc-close" onclick="dzCollabClose()">Close</button>
</div>
<style>
.dzc-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);z-index:2147483645;opacity:0;pointer-events:none;transition:opacity .25s ease}
.dzc-overlay.show{opacity:1;pointer-events:all}
.dzc-panel{position:fixed;left:0;right:0;bottom:0;max-height:80vh;display:flex;flex-direction:column;background:rgba(12,12,28,.98);border-top:1px solid rgba(255,255,255,.08);z-index:2147483646;
    padding:8.8px 0 max(14px, env(safe-area-inset-bottom));transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1);color:#fff;font-family:inherit}
.dzc-panel.show{transform:translateY(0);box-shadow:0 -10px 40px rgba(0,0,0,.5)}
.dzc-handle{width:38px;height:4px;border-radius:2px;background:rgba(255,255,255,.28);margin:0 auto 10px;flex-shrink:0}
.dzc-title{text-align:center;font-weight:700;font-size:15px;padding:4px 16px 12px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0}
.dzc-body{overflow-y:auto;-webkit-overflow-scrolling:touch;flex:1}
.dzc-row{display:flex;align-items:center;gap:13px;padding:11px 16px}
.dzc-av{width:49px;height:49px;border-radius:50%;object-fit:cover;flex-shrink:0;background:#222;cursor:pointer;border:2px solid var(--red,#FF2D2D);box-sizing:border-box}
.dzc-av.sm{width:38px;height:38px}
.dzc-meta{flex:1;min-width:0}
.dzc-name{display:flex;align-items:center;gap:5px;font-weight:700;font-size:14.5px;cursor:pointer}
.dzc-name span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dzc-name svg{width:14px;height:14px;flex-shrink:0;color:#FF2D2D}
.dzc-sub{font-size:12px;color:rgba(255,255,255,.5);margin-top:2px}
.dzc-btn{flex-shrink:0;min-width:92px;height:34px;padding:0 14px;border:0;border-radius:5px;background:#f24455;color:#fff;font:600 13px inherit;font-family:inherit;cursor:pointer}
.dzc-btn.on{background:rgba(255,255,255,.12);color:#fff}
.dzc-btn.ghost{background:rgba(255,255,255,.1)}
.dzc-btn:disabled{opacity:.5}
.dzc-x{width:32px;height:32px;border:0;background:none;color:rgba(255,255,255,.55);font-size:18px;cursor:pointer;flex-shrink:0}
.dzc-search{display:flex;align-items:center;gap:8px;margin:12px 16px 4px;padding:0 12px;height:40px;border-radius:5px;background:rgba(255,255,255,.08)}
.dzc-search input{flex:1;min-width:0;background:none;border:0;outline:0;color:#fff;font:inherit;font-size:14px}
.dzc-hd{padding:14px 16px 4px;font-size:12px;font-weight:700;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.04em}
.dzc-note{padding:6px 16px 10px;font-size:12px;color:rgba(255,255,255,.45);line-height:1.45}
.dzc-chip{display:inline-block;padding:1px 7px;border-radius:4px;background:rgba(255,255,255,.1);font-size:11px;margin-left:6px;font-weight:600}
.dzc-chip.ok{background:rgba(48,209,88,.18);color:#5fe08a}
</style>
<script>
(function(){
    var VB = '<svg viewBox="0 0 256 256" aria-label="Verified"><use href="#ccm-vflower"/></svg>';
    function esc(t){ return String(t == null ? '' : t).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function ep(){ return (window.CCM_CFG && window.CCM_CFG.followEndpoint) || window.CCM_ENDPOINT || window.location.pathname; }
    function post(body){
        return fetch(window.CCM_ENDPOINT || window.location.pathname, { method:'POST', credentials:'same-origin',
            headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body: body + (window.DZ_CSRF ? '&csrf=' + encodeURIComponent(window.DZ_CSRF) : '') })
            .then(function(r){ return r.json(); });
    }
    function toast(t){ if(typeof showToast === 'function') showToast(t); }
    function open(title, html){
        document.getElementById('dzCollabTitle').textContent = title;
        document.getElementById('dzCollabBody').innerHTML = html;
        document.getElementById('dzCollabOverlay').classList.add('show');
        document.getElementById('dzCollabPanel').classList.add('show');
    }
    window.dzCollabClose = function(){
        document.getElementById('dzCollabOverlay').classList.remove('show');
        document.getElementById('dzCollabPanel').classList.remove('show');
    };

    /* ── the sheet everyone sees: the poster first, then each collaborator, with a Track button ── */
    function personRow(u){
        var btn = u.isMe ? '' : '<button type="button" class="dzc-btn' + (u.following ? ' on' : '') + '" data-user="' + u.id + '" onclick="dzCollabTrack(this)">' + (u.following ? 'Untrack' : 'Track') + '</button>';
        return '<div class="dzc-row"><img class="dzc-av" src="' + esc(u.pic) + '" onerror="this.src=\'mee.jpg\'" onclick="location.href=\'profile.php?user_id=' + u.id + '\'">' +
               '<div class="dzc-meta"><div class="dzc-name" onclick="location.href=\'profile.php?user_id=' + u.id + '\'"><span>' + esc(u.username) + '</span>' + (u.isVerified ? VB : '') + '</div>' +
               (u.nickname ? '<div class="dzc-sub">@' + esc(u.nickname) + '</div>' : '') +
               (u.role ? '<div class="dzc-sub">' + u.role + '</div>' : '') + '</div>' + btn + '</div>';
    }
    window.dzCollabSheet = function(memeId, collab){
        collab = collab || ((window.kebabData && window.kebabData[memeId]) ? window.kebabData[memeId].collab : null);
        if(!collab || !collab.owner) return;
        var o = Object.assign({}, collab.owner);
        open('Collaborators', personRow(o) + (collab.list || []).map(personRow).join(''));
    };
    /* Track / Untrack — reflects at once (like the profile page and its modals): every Track button for this person flips
       immediately, the page's own button follows, and it goes back only if the server says no. */
    function setTrackUi(uid, now){
        document.querySelectorAll('.dzc-btn[data-user="' + uid + '"]').forEach(function(b){ b.classList.toggle('on', !!now); b.textContent = now ? 'Untrack' : 'Track'; });
        var upd = function(c){ if(!c) return; if(c.owner && String(c.owner.id) === String(uid)) c.owner.following = !!now; (c.list || []).forEach(function(x){ if(String(x.id) === String(uid)) x.following = !!now; }); };
        Object.keys(window.kebabData || {}).forEach(function(k){ upd(window.kebabData[k].collab); });
        if(typeof window.dzSetFollowState === 'function'){ try { window.dzSetFollowState(uid, !!now); } catch(e){} }
        document.querySelectorAll('.ccm-track-btn[data-user="' + uid + '"]').forEach(function(fb){ fb.textContent = now ? 'Untrack' : 'Track'; fb.classList.toggle('following', !!now); });
    }
    window.dzCollabTrack = function(btn){
        if(btn.dataset.busy === '1') return;
        var uid = btn.dataset.user, was = btn.classList.contains('on');
        if(typeof window.dzHp === 'function'){ try { window.dzHp(was ? 'light' : 'medium'); } catch(e){} }
        btn.dataset.busy = '1';
        setTrackUi(uid, !was);
        fetch(ep(), { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body:'action=toggle_follow&user_id=' + encodeURIComponent(uid) })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.dataset.busy = '';
            if(!d || d.status !== 'ok'){ setTrackUi(uid, was); toast('Something went wrong'); return; }
            if(!!d.following !== !was) setTrackUi(uid, !!d.following);
        }).catch(function(){ btn.dataset.busy = ''; setTrackUi(uid, was); toast('Network error'); });
    };

    /* ── the poster's manager: current collaborators + invite more (up to 14) ── */
    var MG = { id: 0, list: [], max: 5, timer: null };
    function mgRender(){
        var rows = MG.list.map(function(u){
            return '<div class="dzc-row"><img class="dzc-av sm" src="' + esc(u.pic) + '" onerror="this.src=\'mee.jpg\'">' +
                   '<div class="dzc-meta"><div class="dzc-name"><span>' + esc(u.username) + '</span><b class="dzc-chip' + (u.status === 'accepted' ? ' ok' : '') + '">' + (u.status === 'accepted' ? 'Collaborator' : 'Invited') + '</b></div>' + (u.nickname ? '<div class="dzc-sub">@' + esc(u.nickname) + '</div>' : '') + '</div>' +
                   '<button type="button" class="dzc-x" aria-label="Remove" onclick="dzCollabRemove(' + u.id + ')"><i class="ri-close-line"></i></button></div>';
        }).join('');
        var full = MG.list.length >= MG.max;
        open('Collaborators',
            '<div class="dzc-note">Invite up to ' + MG.max + ' people. The post shows on their profile too once they accept.</div>' +
            (full ? '<div class="dzc-note">You have reached the limit of ' + MG.max + '. Remove someone to invite another person.</div>' :
                '<div class="dzc-search"><i class="ri-search-line" style="opacity:.5"></i><input id="dzcQ" type="text" placeholder="Search by username" autocomplete="off" oninput="dzCollabSearch(this.value)"></div><div id="dzcResults"></div>') +
            (rows ? '<div class="dzc-hd">On this post</div>' + rows : ''));
    }
    function mgLoad(){
        return post('action=collab_manage&meme_id=' + MG.id).then(function(d){
            if(d && d.status === 'ok'){ MG.list = d.list || []; MG.max = d.max || 5; mgRender(); }
            else toast((d && d.message) || 'Not available here.');
        }).catch(function(){ toast('Connection problem.'); });
    }
    window.dzCollabManage = function(memeId){ MG.id = memeId; if(typeof closeIndexSheet === 'function') closeIndexSheet(); setTimeout(mgLoad, 120); };
    window.dzCollabSearch = function(q){
        clearTimeout(MG.timer);
        var box = document.getElementById('dzcResults'); if(!box) return;
        if(!q.trim()){ box.innerHTML = ''; return; }
        MG.timer = setTimeout(function(){
            post('action=collab_search&q=' + encodeURIComponent(q)).then(function(d){
                var taken = {}; MG.list.forEach(function(u){ taken[u.id] = 1; });
                var b = document.getElementById('dzcResults'); if(!b) return;
                var us = ((d && d.users) || []).filter(function(u){ return !taken[u.id]; });
                b.innerHTML = us.length ? us.map(function(u){
                    return '<div class="dzc-row"><img class="dzc-av sm" src="' + esc(u.pic) + '" onerror="this.src=\'mee.jpg\'"><div class="dzc-meta"><div class="dzc-name"><span>' + esc(u.username) + '</span>' + (u.isVerified ? VB : '') + '</div>' + (u.nickname ? '<div class="dzc-sub">@' + esc(u.nickname) + '</div>' : '') + '</div>' +
                           '<button type="button" class="dzc-btn" onclick="dzCollabInvite(' + u.id + ', this)">Invite</button></div>';
                }).join('') : '<div class="dzc-note">No one found.</div>';
            });
        }, 250);
    };
    window.dzCollabInvite = function(uid, btn){
        btn.disabled = true;
        post('action=collab_invite&meme_id=' + MG.id + '&user_ids=' + uid).then(function(d){
            if(d && d.status === 'ok'){
                var why = Object.keys(d.skipped || {}).map(function(k){ return d.skipped[k]; })[0];
                toast(d.sent ? 'Invite sent' : (why || 'Could not invite'));
                mgLoad();
            } else { btn.disabled = false; toast((d && d.message) || 'Could not invite'); }
        }).catch(function(){ btn.disabled = false; toast('Connection problem.'); });
    };
    window.dzCollabRemove = function(uid){
        post('action=collab_remove&meme_id=' + MG.id + '&user_id=' + uid).then(function(d){
            if(d && d.status === 'ok') mgLoad(); else toast((d && d.message) || 'Could not remove');
        });
    };
    /* a collaborator leaves a post they accepted */
    window.dzCollabLeave = function(memeId, myId){
        MG.id = memeId;
        post('action=collab_remove&meme_id=' + memeId + '&user_id=' + myId).then(function(d){
            if(typeof closeIndexSheet === 'function') closeIndexSheet();
            toast(d && d.status === 'ok' ? 'You left this collaboration' : ((d && d.message) || 'Could not leave'));
        });
    };
})();
</script>

<style>
.idx-sheet-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,0.6);
    backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    z-index:99999998;opacity:0;pointer-events:none;
    transition:opacity 0.25s ease;
}
.idx-sheet-overlay.show{opacity:1;pointer-events:all;}

.idx-sheet-panel{
    position:fixed;left:0;right:0;bottom:0;
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border-top:1px solid rgba(255,255,255,0.08);
    border-radius:22px 22px 0 0;
    z-index:99999999;
    max-height:80vh;
    display:flex;flex-direction:column;
    padding:8.8px 0 max(11px, env(safe-area-inset-bottom));
    transform:translateY(100%);
    transition:transform 0.3s cubic-bezier(0.32,0.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,0.5);
    overflow:hidden;
}
.idx-sheet-panel.show{transform:translateY(0);}
/* closed sheets wait just below the screen: their upward shadow must not bleed onto the bottom of the page (it dimmed Zeems' progress bar and caption) */
.svsh-panel:not(.show),.idx-sheet-panel:not(.show){ box-shadow:none !important; }

.idx-sheet-handle{
    width:37.4px;height:3.85px;border-radius:1098.9px;
    background:rgba(255,255,255,0.2);
    margin:0 auto 4.4px;flex-shrink:0;
}

/* ── SLIDING TRACK: list ↔ detail ── */
.idx-sheet-track{
    display:flex;width:200%;
    flex:1;min-height:0;
    transition:transform .3s cubic-bezier(.32,.72,0,1);
}
.idx-sheet-track.show-detail{ transform:translateX(-50%); }

.idx-sheet-screen{
    width:50%;flex-shrink:0;
    display:flex;flex-direction:column;min-height:0;
}

.idx-sheet-list{overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch;}
.idx-sheet-list::-webkit-scrollbar{width:4px;}
.idx-sheet-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

.idx-sheet-item{
    display:flex;align-items:center;gap:12.1px;
    padding:9.9px 19.8px;
    color:#fff;text-decoration:none;
    font-size:14.3px;font-weight:500;
    font-family:Poppins,sans-serif;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background 0.15s ease;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.idx-sheet-item:last-child{border-bottom:none;}
.idx-sheet-item:active{background:rgba(255,255,255,0.06);}
.idx-sheet-item i{font-size:17.05px;color:#f24455;width:19.8px;text-align:center;flex-shrink:0;}
/* Post ID row — a normal row at the end of the menu (same spacing and line as the others) */
.idx-sheet-item.idx-pid{justify-content:space-between;gap:12px;}
.idx-pid-tx{display:flex;flex-direction:column;min-width:0;flex:1;line-height:1.3;}
.idx-pid-t{font-weight:600;}
.idx-pid-v{font-size:.66rem;opacity:.6;font-variant-numeric:tabular-nums;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
/* taller menu so every option incl. Post ID fits on screen without scrolling */
#indexSheetPanel.idx-sheet-panel{max-height:94vh !important;max-height:94dvh !important;}
/* Copy button: same look as the Untrack button on profiles (soft filled box, no border, no chevron) */
.idx-pid-copy{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;padding:8px 18px;border-radius:3px;background:rgba(var(--dz-fg,255,255,255),0.1);color:var(--dz-text,#F0F2FF);font-size:.72rem;font-weight:600;line-height:1;text-shadow:0 0 .35px currentColor;transition:background .2s ease,transform .15s ease;}
.idx-pid:hover .idx-pid-copy{background:rgba(var(--dz-fg,255,255,255),0.2);}
.idx-pid:active .idx-pid-copy{transform:scale(.97);}
.idx-sheet-item.danger{color:#ff4c4c;}
.idx-sheet-item.danger i{color:#ff4c4c;}

/* item with a small subtitle line (e.g. "Not Interested") */
.idx-sheet-item-title{font-size:14.3px;font-weight:500;color:#fff;line-height:1.3;}
.idx-sheet-item-sub{font-size:11.55px;color:rgba(255,255,255,0.4);margin-top:2.2px;line-height:1.3;}
.idx-sheet-item.danger .idx-sheet-item-title{color:#ff4c4c;}

.idx-sheet-cancel{
    margin:6.6px 13.2px 0;
    padding:11px;
    background:rgba(255,255,255,0.06);
    border:none;border-radius:13.2px;
    color:rgba(255,255,255,0.7);
    font-weight:600;font-size:13.75px;
    font-family:Poppins,sans-serif;
    cursor:pointer;flex-shrink:0;
    -webkit-tap-highlight-color:transparent;
}
.idx-sheet-cancel:active{background:rgba(255,255,255,0.1);}
.idx-auto-row .idx-sw{ margin-left:auto; flex-shrink:0; width:38px; height:22px; border-radius:999px; background:rgba(128,128,128,.45); position:relative; transition:background .2s; }
.idx-auto-row .idx-sw::after{ content:''; position:absolute; top:2px; left:2px; width:18px; height:18px; border-radius:50%; background:#fff; transition:transform .2s; box-shadow:0 1px 3px rgba(0,0,0,.35); }
.idx-auto-row .idx-sw.on{ background:#f24455 !important; }
.idx-pb-go{ margin-left:auto; flex-shrink:0; opacity:.55; display:block; }
.idx-pb-label{ font-size:11px; letter-spacing:.08em; text-transform:uppercase; opacity:.5; padding:14px 4px 6px; font-weight:700; }
.idx-pb-chips{ display:flex; flex-wrap:wrap; gap:8px; padding:2px 4px 6px; }
.idx-pb-chip{ cursor:pointer; padding:9px 14px; -webkit-tap-highlight-color:transparent; }
.idx-pb-chip:active{ transform:scale(.96); }
.idx-pb-chip.sel{ background:rgba(242,68,85,.16); color:#f24455; }
.idx-auto-row .idx-sw.on::after{ transform:translateX(16px); }
/* sharp corners (Save + menu sheets) */
.idx-sheet-panel, #indexSheetPanel{ border-radius:0 !important; }

/* ── DETAIL SCREEN ── */
.idx-detail-header{
    display:flex;align-items:center;gap:8.8px;
    padding:4.4px 15.4px 11px;
    border-bottom:1px solid rgba(255,255,255,0.06);
    flex-shrink:0;
}
.idx-detail-back{
    width:30.8px;height:30.8px;border-radius:50%;
    background:rgba(255,255,255,0.06);border:none;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:20.9px;cursor:pointer;-webkit-tap-highlight-color:transparent;
    transition:background .18s,color .18s;flex-shrink:0;
}
.idx-detail-back:hover{background:rgba(255,45,45,0.18);color:#ff6060;}
.idx-detail-title{
    flex:1;font-size:14.85px;font-weight:700;color:#fff;
    text-align:center;padding-right:30.8px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.idx-detail-spacer{width:30.8px;flex-shrink:0;}

.idx-detail-body summary::-webkit-details-marker{display:none;}
.idx-detail-body{
    flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;
    padding:15.4px 19.8px 19.8px;
}
.idx-detail-body::-webkit-scrollbar{width:4px;}
.idx-detail-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

.idx-detail-general{
    font-size:13.2px;line-height:1.65;color:rgba(255,255,255,0.62);
    margin-bottom:17.6px;
}

.idx-detail-divider{
    display:flex;align-items:center;gap:11px;margin:17.6px 0 15.4px;
}
.idx-detail-divider::before,.idx-detail-divider::after{
    content:'';flex:1;height:1px;background:rgba(255,255,255,0.08);
}
.idx-detail-divider span{
    font-size:10.45px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;
    color:rgba(255,255,255,0.3);white-space:nowrap;
}

.idx-detail-poster-row{
    display:flex;align-items:center;gap:11px;margin-bottom:13.2px;
}
.idx-detail-poster-pic{
    width:41.8px;height:41.8px;border-radius:50%;object-fit:cover;
    border:2px solid #f24455;flex-shrink:0;
}
.idx-detail-poster-name{font-size:14.3px;font-weight:700;color:#fff;}
.idx-detail-poster-sub{font-size:11.55px;color:rgba(255,255,255,0.4);margin-top:1px;}

.idx-detail-unique{
    font-size:13.2px;line-height:1.7;color:rgba(255,255,255,0.72);
    margin-bottom:19.8px;
}

.idx-detail-hide-btn{
    width:100%;padding:12.1px;border-radius:13.2px;
    border:1px solid rgba(255,76,76,0.35);
    background:rgba(255,76,76,0.08);color:#ff6060;
    font-size:13.75px;font-weight:700;font-family:Poppins,sans-serif;
    cursor:pointer;-webkit-tap-highlight-color:transparent;
    display:flex;align-items:center;justify-content:center;gap:7.7px;
    transition:background .18s;
}
.idx-detail-hide-btn:hover{background:rgba(255,76,76,0.16);}
.idx-detail-hide-btn i{font-size:16.5px;}

/* ── v11: real "why" reasons + link to Your Algorithm ── */
.idx-why-list{ display:flex;flex-direction:column; margin-bottom:6px; }
.idx-why-row{ display:flex;align-items:flex-start;gap:12.1px; padding:11px 0; border-bottom:1px solid rgba(255,255,255,0.06); }
.idx-why-row:last-child{ border-bottom:none; }
.idx-why-ic{
    width:33px;height:33px;flex-shrink:0;border-radius:9.9px;
    background:rgba(242,68,85,0.12);color:#f24455;
    display:flex;align-items:center;justify-content:center;font-size:17px;
}
.idx-why-txt{ flex:1;min-width:0; }
.idx-why-title{ font-size:13.75px;font-weight:600;color:#fff;line-height:1.4;word-break:break-word; }
.idx-why-sub{ font-size:12.1px;color:rgba(255,255,255,0.5);margin-top:2.5px;line-height:1.55; }
.idx-detail-link-btn{
    width:100%;margin-top:14px;padding:12.1px;border-radius:13.2px;
    border:1px solid rgba(242,68,85,0.4);
    background:rgba(242,68,85,0.1);color:#f24455;
    font-size:13.75px;font-weight:700;font-family:Poppins,sans-serif;text-decoration:none;
    cursor:pointer;-webkit-tap-highlight-color:transparent;
    display:flex;align-items:center;justify-content:center;gap:7.7px;
    transition:background .18s;
}
.idx-detail-link-btn:hover{background:rgba(242,68,85,0.18);}
.idx-detail-link-btn i{font-size:16.5px;}

/* ── v11: Undo bar for "Not interested" (only used when the page has no notInterested() of its own) ── */
.idx-undo-bar{
    position:fixed;left:50%;bottom:92px;transform:translateX(-50%) translateY(20px);
    width:min(360px, calc(100vw - 32px));display:flex;align-items:center;gap:12px;
    padding:10px 10px 10px 16px;background:rgba(8,8,22,.97);border:1px solid rgba(255,255,255,.16);
    box-shadow:0 8px 24px rgba(0,0,0,.6);color:#fff;font-family:Poppins,sans-serif;
    opacity:0;pointer-events:none;z-index:999999999;
    transition:opacity .22s ease, transform .3s cubic-bezier(.34,1.56,.64,1);
}
.idx-undo-bar.show{opacity:1;pointer-events:auto;transform:translateX(-50%) translateY(0);}
.idx-undo-bar span{flex:1;min-width:0;font-size:12.5px;font-weight:500;line-height:1.4;}
.idx-undo-bar button{
    height:32px;padding:0 16px;border:none;background:#f24455;color:#000;
    font-size:12.5px;font-weight:700;font-family:Poppins,sans-serif;cursor:pointer;flex-shrink:0;
}

/* ═══ RESPONSIVE (mirrors existing breakpoints) ═══ */
@media (min-width:791px){
    .idx-sheet-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:374px;
        border-radius:19.8px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;pointer-events:none;
        max-height:76vh;
    }
    .idx-sheet-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;pointer-events:all;
    }
    .idx-sheet-handle{display:none;}
}
@media (max-width:790px){
    .idx-sheet-panel{ border-radius:19.8px 19.8px 0 0; max-height:78vh; padding:7.7px 0 max(9.9px, env(safe-area-inset-bottom)); }
    .idx-sheet-handle{ width:35.2px;height:3.85px;margin:0 auto 4.4px; }
    .idx-sheet-item{ padding:8.8px 17.6px; font-size:13.75px; gap:11px; }
    .idx-sheet-item i{ font-size:16.5px; width:18.7px; }
    .idx-sheet-cancel{ margin:5.5px 12.1px 0; padding:9.9px; font-size:13.2px; border-radius:12.1px; }
    .idx-detail-body{ padding:13.2px 17.6px 17.6px; }
    .idx-detail-general,.idx-detail-unique{ font-size:12.65px; }
}
@media (max-width:490px){
    .idx-sheet-panel{ border-radius:17.6px 17.6px 0 0; max-height:76vh; padding:6.6px 0 max(8.8px, env(safe-area-inset-bottom)); backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px); }
    .idx-sheet-handle{ width:33px;height:3.3px;margin:0 auto 3.3px; }
    .idx-sheet-item{ padding:7.7px 15.4px; font-size:13.2px; gap:9.9px; }
    .idx-sheet-item i{ font-size:15.4px; width:17.6px; }
    .idx-sheet-item-sub{ font-size:11px; }
    .idx-sheet-cancel{ margin:5.5px 11px 0; padding:8.8px; font-size:12.65px; border-radius:11px; }
    .idx-detail-header{ padding:4.4px 13.2px 9.9px; }
    .idx-detail-title{ font-size:14.3px; }
    .idx-detail-body{ padding:12.1px 15.4px 15.4px; }
    .idx-detail-general,.idx-detail-unique{ font-size:12.1px; }
    .idx-detail-poster-pic{ width:37.4px;height:37.4px; }
    .idx-detail-hide-btn,.idx-detail-link-btn{ padding:11px;font-size:13.2px; }
    .idx-why-title{ font-size:13.2px; }
    .idx-why-sub{ font-size:11.55px; }
}
@media (max-width:390px){
    .idx-sheet-panel{ border-radius:15.4px 15.4px 0 0; max-height:74vh; padding:6.6px 0 max(7.7px, env(safe-area-inset-bottom)); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); box-shadow:0 -8px 30px rgba(0,0,0,0.45); }
    .idx-sheet-handle{ width:30.8px;height:3.3px;margin:0 auto 3.3px; }
    .idx-sheet-item{ padding:7.15px 13.2px; font-size:12.65px; gap:8.8px; }
    .idx-sheet-item i{ font-size:14.85px; width:16.5px; }
    .idx-sheet-cancel{ margin:4.4px 9.9px 0; padding:7.7px; font-size:12.1px; border-radius:9.9px; }
    .idx-detail-body{ padding:11px 13.2px 13.2px; }
}
@media (max-width:330px){
    .idx-sheet-panel{ border-radius:13.2px 13.2px 0 0; max-height:72vh; padding:5.5px 0 max(6.6px, env(safe-area-inset-bottom)); backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); box-shadow:0 -6px 24px rgba(0,0,0,0.4); }
    .idx-sheet-handle{ width:26.4px;height:2.75px;margin:0 auto 3.3px; }
    .idx-sheet-list::-webkit-scrollbar{width:3px;}
    .idx-sheet-item{ padding:6.05px 11px; font-size:11.55px; gap:7.7px; }
    .idx-sheet-item i{ font-size:13.75px; width:14.3px; }
    .idx-sheet-cancel{ margin:4.4px 8.8px 0; padding:6.6px; font-size:11px; border-radius:8.8px; }
    .idx-detail-body{ padding:9.9px 11px 11px; }
}
</style>
<style id="dzShDzmIdx">
/* ═══ Post menu + Collaborators — the profile page's bottom-sheet look (.dzm-*) ═══ */

#indexSheetPanel.idx-sheet-panel{ --dz-s:var(--bs-s,var(--dz-s-def,1)); --dz-lane:var(--lane,#070924); --dz-card:var(--surface-3,#090b29); --dz-fg:var(--fg,255,255,255); --dz-text:var(--text,#F0F2FF); --dz-red:var(--red,#FF2D2D); background:var(--dz-lane); border:0; border-radius:0 !important; box-shadow:none; backdrop-filter:none; -webkit-backdrop-filter:none;
    padding:calc(5px * var(--dz-s)) 0 calc(env(safe-area-inset-bottom,0px) + calc(10px * var(--dz-s))); gap:calc(8px * var(--dz-s)); color:var(--dz-text); font-family:Poppins,sans-serif; max-height:60vh;  }

#dzCollabPanel.dzc-panel{ --dz-s:var(--bs-s,var(--dz-s-def,1)); --dz-lane:var(--lane,#070924); --dz-card:var(--surface-3,#090b29); --dz-fg:var(--fg,255,255,255); --dz-text:var(--text,#F0F2FF); --dz-red:var(--red,#FF2D2D); background:var(--dz-lane); border:0; border-radius:0 !important; box-shadow:none; backdrop-filter:none; -webkit-backdrop-filter:none;
    padding:calc(5px * var(--dz-s)) 0 calc(env(safe-area-inset-bottom,0px) + calc(10px * var(--dz-s))); gap:calc(8px * var(--dz-s)); color:var(--dz-text); font-family:Poppins,sans-serif; max-height:60vh; padding-left:calc(10px * var(--dz-s)); padding-right:calc(10px * var(--dz-s)); display:flex; flex-direction:column; transition:transform .3s cubic-bezier(.32,.72,0,1), opacity .25s ease; }

[data-theme="light"] #indexSheetPanel,[data-theme="light"] #dzCollabPanel{ --dz-lane:var(--lane,#ffffff); --dz-card:var(--surface-3,#eef1f7); --dz-fg:var(--fg,15,20,40); --dz-text:var(--text,#14171f); }
.idx-sheet-overlay,.dzc-overlay{ background:rgba(0,0,0,.6); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px); }
#indexSheetPanel .idx-sheet-handle,#dzCollabPanel .dzc-handle{ width:calc(30px * var(--dz-s)); height:calc(3px * var(--dz-s)); border-radius:2px; background:rgba(var(--dz-fg),.22); margin:0 auto; flex-shrink:0; }
#indexSheetPanel .idx-sheet-screen{ padding:0 calc(10px * var(--dz-s)); box-sizing:border-box; }
/* the sheet is only as tall as the screen showing: the screen that is off to the side folds to nothing once the slide ends */
#indexSheetPanel .idx-sheet-screen{ max-height:none; transition:max-height 0s; }
#indexSheetPanel .idx-sheet-track:not(.show-detail) .idx-sheet-screen:nth-child(2),
#indexSheetPanel .idx-sheet-track.show-detail .idx-sheet-screen:nth-child(1){ max-height:0; overflow:hidden; padding-top:0; padding-bottom:0; transition:max-height 0s linear .32s; }
/* the list is the inner card */
#indexSheetPanel .idx-sheet-list{ background:var(--dz-card); border-radius:calc(6px * var(--dz-s)); flex:0 1 auto; min-height:0; scrollbar-width:thin; scrollbar-color:rgba(var(--dz-fg),.15) transparent; }
#indexSheetPanel .idx-sheet-list::-webkit-scrollbar-thumb{ background:rgba(var(--dz-fg),.15); }
#indexSheetList::before{ content:'Post options'; display:block; padding:calc(7px * var(--dz-s)) calc(12px * var(--dz-s)) calc(3px * var(--dz-s)); font-size:calc(8.5px * var(--dz-s)); color:rgba(var(--dz-fg),.35); letter-spacing:1px; text-transform:uppercase; }
#indexSheetPanel .idx-sheet-item{ display:flex; align-items:center; gap:calc(9px * var(--dz-s)); padding:calc(6px * var(--dz-s)) calc(12px * var(--dz-s)); color:var(--dz-text); font-size:calc(11.5px * var(--dz-s)); font-weight:600; border:0; border-top:1px solid rgba(var(--dz-fg),.08); transition:background .2s; }
#indexSheetPanel .idx-sheet-item:first-child{ border-top:0; }
#indexSheetPanel .idx-sheet-item:hover,#indexSheetPanel .idx-sheet-item:active{ background:rgba(var(--dz-fg),.06); }
#indexSheetPanel .idx-sheet-item > i{
    width:calc(27px * var(--dz-s)); height:calc(27px * var(--dz-s)); border-radius:50%; flex-shrink:0; box-sizing:border-box;
    display:flex; align-items:center; justify-content:center; text-align:center;
    border:1.5px dashed rgba(var(--dz-fg),.3); color:rgba(var(--dz-fg),.6); font-size:calc(14px * var(--dz-s)); }
#indexSheetPanel .idx-sheet-item.danger{ color:var(--dz-red); }
#indexSheetPanel .idx-sheet-item.danger > i{ color:var(--dz-red); border-color:rgba(255,45,45,.45); }
#indexSheetPanel .idx-sheet-item-title{ font-size:calc(11.5px * var(--dz-s)); font-weight:600; color:var(--dz-text); line-height:1.3; }
#indexSheetPanel .idx-sheet-item-sub{ font-size:calc(9px * var(--dz-s)); color:rgba(var(--dz-fg),.4); margin-top:1px; line-height:1.35; }
#indexSheetPanel .idx-sheet-item.danger .idx-sheet-item-title{ color:var(--dz-red); }
#indexSheetPanel .idx-auto-row .idx-sw{ background:rgba(var(--dz-fg),.18); }
#indexSheetPanel .idx-auto-row .idx-sw.on{ background:var(--dz-red) !important; }
/* detail screen (insights / why am I seeing this) = a second inner card */
#indexSheetPanel .idx-detail-card{ background:var(--dz-card); border-radius:calc(6px * var(--dz-s)); display:flex; flex-direction:column; min-height:0; flex:0 1 auto; overflow:hidden; }
#indexSheetPanel .idx-detail-header{ gap:calc(8px * var(--dz-s)); padding:calc(7px * var(--dz-s)) calc(12px * var(--dz-s)); border-bottom:1px solid rgba(var(--dz-fg),.08); }
#indexSheetPanel .idx-detail-back{ width:calc(27px * var(--dz-s)); height:calc(27px * var(--dz-s)); background:transparent; border:1.5px dashed rgba(var(--dz-fg),.3); color:rgba(var(--dz-fg),.6); font-size:calc(16px * var(--dz-s)); box-sizing:border-box; }
#indexSheetPanel .idx-detail-back:hover{ background:rgba(var(--dz-fg),.06); color:var(--dz-red); }
#indexSheetPanel .idx-detail-title{ font-size:calc(11.5px * var(--dz-s)); font-weight:600; color:var(--dz-text); padding-right:calc(27px * var(--dz-s)); }
#indexSheetPanel .idx-detail-spacer{ width:0; }
#indexSheetPanel .idx-detail-body{ padding:calc(12px * var(--dz-s)) calc(14px * var(--dz-s)) calc(14px * var(--dz-s)); scrollbar-width:thin; scrollbar-color:rgba(var(--dz-fg),.15) transparent; }
#indexSheetPanel .idx-detail-general{ color:rgba(var(--dz-fg),.62); font-size:calc(11px * var(--dz-s)); }
#indexSheetPanel .idx-detail-unique{ color:rgba(var(--dz-fg),.72); font-size:calc(11px * var(--dz-s)); }
#indexSheetPanel .idx-detail-divider::before,#indexSheetPanel .idx-detail-divider::after{ background:rgba(var(--dz-fg),.1); }
#indexSheetPanel .idx-detail-divider span{ color:rgba(var(--dz-fg),.4); }
#indexSheetPanel .idx-detail-poster-name{ color:var(--dz-text); font-size:calc(12px * var(--dz-s)); }
#indexSheetPanel .idx-detail-poster-sub{ color:rgba(var(--dz-fg),.4); font-size:calc(9.5px * var(--dz-s)); }
#indexSheetPanel .idx-detail-poster-pic{ border-color:var(--dz-red); }
#indexSheetPanel .idx-why-row{ border-bottom-color:rgba(var(--dz-fg),.08); }
#indexSheetPanel .idx-why-ic{ width:calc(27px * var(--dz-s)); height:calc(27px * var(--dz-s)); border-radius:50%; background:transparent; border:1.5px dashed rgba(var(--dz-fg),.3); color:rgba(var(--dz-fg),.6); font-size:calc(14px * var(--dz-s)); box-sizing:border-box; }
#indexSheetPanel .idx-why-title{ color:var(--dz-text); font-size:calc(11.5px * var(--dz-s)); }
#indexSheetPanel .idx-why-sub{ color:rgba(var(--dz-fg),.5); font-size:calc(10px * var(--dz-s)); }
#indexSheetPanel .idx-detail-hide-btn,#indexSheetPanel .idx-detail-link-btn{ border-radius:4px; padding:calc(10px * var(--dz-s)); font-size:calc(11.5px * var(--dz-s)); }
#indexSheetPanel .idx-detail-link-btn{ color:var(--dz-red); border-color:rgba(255,45,45,.4); background:rgba(255,45,45,.1); }
#indexSheetPanel .idx-detail-hide-btn{ color:var(--dz-red); border-color:rgba(255,45,45,.35); background:rgba(255,45,45,.08); }
/* Close button */
#indexSheetPanel .idx-sheet-cancel,#dzCollabPanel .dzc-close{ display:flex; align-items:center; justify-content:center; gap:calc(6px * var(--dz-s)); margin:0 calc(10px * var(--dz-s)); padding:calc(10px * var(--dz-s)); background:var(--dz-card); border:0; border-radius:calc(6px * var(--dz-s));
    color:rgba(var(--dz-fg),.85); font-size:calc(12px * var(--dz-s)); font-weight:600; font-family:Poppins,sans-serif; cursor:pointer; flex-shrink:0; -webkit-tap-highlight-color:transparent; transition:background .2s, color .2s; }
#dzCollabPanel .dzc-close{ margin:0; width:100%; }
#indexSheetPanel .idx-sheet-cancel:hover,#dzCollabPanel .dzc-close:hover{ color:var(--dz-red); background:var(--dz-card); }
/* ── Collaborators ── */
#dzCollabPanel .dzc-card{ background:var(--dz-card); border-radius:calc(6px * var(--dz-s)); display:flex; flex-direction:column; min-height:0; flex:0 1 auto; overflow:hidden; }
#dzCollabPanel .dzc-title{ text-align:left; font-weight:400; font-size:calc(8.5px * var(--dz-s)); color:rgba(var(--dz-fg),.35); letter-spacing:1px; text-transform:uppercase; padding:calc(7px * var(--dz-s)) calc(12px * var(--dz-s)) calc(3px * var(--dz-s)); border:0; }
#dzCollabPanel .dzc-body{ scrollbar-width:thin; scrollbar-color:rgba(var(--dz-fg),.15) transparent; }
#dzCollabPanel .dzc-row{ gap:calc(9px * var(--dz-s)); padding:calc(6px * var(--dz-s)) calc(12px * var(--dz-s)); border-top:1px solid rgba(var(--dz-fg),.08); }
#dzCollabPanel .dzc-body > .dzc-row:first-child{ border-top:0; }
#dzCollabPanel .dzc-av{ width:calc(34px * var(--dz-s)); height:calc(34px * var(--dz-s)); border-width:1.5px; border-color:var(--dz-red); }
#dzCollabPanel .dzc-av.sm{ width:calc(27px * var(--dz-s)); height:calc(27px * var(--dz-s)); }
#dzCollabPanel .dzc-name{ font-size:calc(11.5px * var(--dz-s)); font-weight:600; color:var(--dz-text); }
#dzCollabPanel .dzc-sub{ font-size:calc(9px * var(--dz-s)); color:rgba(var(--dz-fg),.4); margin-top:1px; }
#dzCollabPanel .dzc-btn{ display:flex; align-items:center; justify-content:center; min-width:calc(70px * var(--dz-s)); height:calc(28px * var(--dz-s)); padding:0 calc(12px * var(--dz-s)); border:1.5px solid var(--track-red,#FF2D2D); border-radius:var(--rp-action-radius,4px);
    background:var(--track-red,#FF2D2D); color:#000; font-family:inherit; font-size:calc(11px * var(--dz-s)); font-weight:700; line-height:1; white-space:nowrap; transition:background .2s, border-color .2s, transform .15s; -webkit-tap-highlight-color:transparent; }
#dzCollabPanel .dzc-btn:hover{ background:var(--track-red-hover,#ff1a1a); border-color:var(--track-red-hover,#ff1a1a); }
#dzCollabPanel .dzc-btn:active{ transform:scale(.94); }
@media (max-width:768px){ #dzCollabPanel .dzc-btn{ text-shadow:0 0 .35px currentColor; } }
#dzCollabPanel .dzc-btn.on,#dzCollabPanel .dzc-btn.on:hover,#dzCollabPanel .dzc-btn.ghost{ background:rgba(var(--dz-fg),.1); border-color:transparent; color:var(--dz-text); font-weight:700; backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
#dzCollabPanel .dzc-btn:disabled{ opacity:1; }
#dzCollabPanel .dzc-x{ color:rgba(var(--dz-fg),.55); }
#dzCollabPanel .dzc-search{ margin:calc(8px * var(--dz-s)) calc(12px * var(--dz-s)) calc(4px * var(--dz-s)); height:calc(34px * var(--dz-s)); border-radius:4px; background:rgba(var(--dz-fg),.06); }
#dzCollabPanel .dzc-search input{ color:var(--dz-text); font-size:calc(11.5px * var(--dz-s)); }
#dzCollabPanel .dzc-hd{ padding:calc(8px * var(--dz-s)) calc(12px * var(--dz-s)) calc(3px * var(--dz-s)); font-size:calc(8.5px * var(--dz-s)); font-weight:400; letter-spacing:1px; color:rgba(var(--dz-fg),.35); }
#dzCollabPanel .dzc-note{ padding:calc(4px * var(--dz-s)) calc(12px * var(--dz-s)) calc(8px * var(--dz-s)); font-size:calc(10px * var(--dz-s)); color:rgba(var(--dz-fg),.45); }
#dzCollabPanel .dzc-chip{ background:rgba(var(--dz-fg),.1); color:var(--dz-text); }
#dzCollabPanel .dzc-chip.ok{ background:rgba(48,209,88,.18); color:#5fe08a; }
/* desktop: centred 360px modal */
@media (min-width:791px){
    #indexSheetPanel.idx-sheet-panel{ max-width:360px; width:90vw; border-radius:calc(8px * var(--dz-s)) !important; padding:calc(14px * var(--dz-s)) 0 calc(16px * var(--dz-s)); gap:calc(12px * var(--dz-s)); max-height:80vh; box-shadow:0 16px 40px rgba(0,0,0,.6); }
    #dzCollabPanel.dzc-panel{ left:50%; right:auto; top:50%; bottom:auto; width:90vw; max-width:360px; border-radius:calc(8px * var(--dz-s)) !important; padding:calc(14px * var(--dz-s)) calc(14px * var(--dz-s)) calc(16px * var(--dz-s)); gap:calc(12px * var(--dz-s)); max-height:80vh;
        transform:translate(-50%,-40%) scale(.95); opacity:0; pointer-events:none; box-shadow:0 16px 40px rgba(0,0,0,.6); }
    #dzCollabPanel.dzc-panel.show{ transform:translate(-50%,-50%) scale(1); opacity:1; pointer-events:all; }
    #dzCollabPanel .dzc-handle{ display:none; }
    #indexSheetPanel .idx-sheet-screen{ padding:0 calc(14px * var(--dz-s)); }
    #indexSheetPanel .idx-sheet-list,#indexSheetPanel .idx-detail-card,#dzCollabPanel .dzc-card{ border-radius:calc(4px * var(--dz-s)); }
    #indexSheetList::before,#dzCollabPanel .dzc-title{ padding:calc(12px * var(--dz-s)) calc(16px * var(--dz-s)) calc(6px * var(--dz-s)); font-size:calc(10px * var(--dz-s)); }
    #indexSheetPanel .idx-sheet-item{ gap:calc(10px * var(--dz-s)); padding:calc(10px * var(--dz-s)) calc(14px * var(--dz-s)); font-size:calc(13px * var(--dz-s)); }
    #indexSheetPanel .idx-sheet-item > i{ width:calc(34px * var(--dz-s)); height:calc(34px * var(--dz-s)); font-size:calc(17px * var(--dz-s)); }
    #indexSheetPanel .idx-sheet-item-title{ font-size:calc(13px * var(--dz-s)); }
    #indexSheetPanel .idx-sheet-item-sub{ font-size:calc(10px * var(--dz-s)); }
    #indexSheetPanel .idx-sheet-cancel{ margin:0 calc(14px * var(--dz-s)); padding:calc(14px * var(--dz-s)); font-size:calc(14px * var(--dz-s)); border-radius:calc(4px * var(--dz-s)); }
    #dzCollabPanel .dzc-close{ padding:calc(14px * var(--dz-s)); font-size:calc(14px * var(--dz-s)); border-radius:calc(4px * var(--dz-s)); }
    #indexSheetPanel .idx-detail-header{ padding:calc(10px * var(--dz-s)) calc(14px * var(--dz-s)); }
    #indexSheetPanel .idx-detail-back{ width:calc(34px * var(--dz-s)); height:calc(34px * var(--dz-s)); font-size:calc(18px * var(--dz-s)); }
    #indexSheetPanel .idx-detail-title{ font-size:calc(13px * var(--dz-s)); padding-right:calc(34px * var(--dz-s)); }
    #indexSheetPanel .idx-detail-general,#indexSheetPanel .idx-detail-unique{ font-size:calc(12.5px * var(--dz-s)); }
    #indexSheetPanel .idx-why-ic{ width:calc(34px * var(--dz-s)); height:calc(34px * var(--dz-s)); font-size:calc(17px * var(--dz-s)); }
    #indexSheetPanel .idx-why-title{ font-size:calc(13px * var(--dz-s)); }
    #indexSheetPanel .idx-why-sub{ font-size:calc(11px * var(--dz-s)); }
    #dzCollabPanel .dzc-btn{ height:calc(32px * var(--dz-s)); font-size:calc(12px * var(--dz-s)); }
    #dzCollabPanel .dzc-row{ gap:calc(10px * var(--dz-s)); padding:calc(10px * var(--dz-s)) calc(14px * var(--dz-s)); }
    #dzCollabPanel .dzc-av{ width:calc(40px * var(--dz-s)); height:calc(40px * var(--dz-s)); }
    #dzCollabPanel .dzc-av.sm{ width:calc(34px * var(--dz-s)); height:calc(34px * var(--dz-s)); }
    #dzCollabPanel .dzc-name{ font-size:calc(13px * var(--dz-s)); }
    #dzCollabPanel .dzc-sub{ font-size:calc(10px * var(--dz-s)); }
}
</style>
<script>(function(){var s=document.getElementById("dzShEarly");if(s)s.remove();})();</script>

<script>
/* ═══════════════════════════════════════════════════════
   SMALL HELPERS
   ═══════════════════════════════════════════════════════ */
function _idxEsc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

/* one two-line row (title + subtitle) — used by the ad list and by the new "Interested" / "Not Interested" rows */
function _idxRow(cls, icon, onclick, href, title, sub){
    var tag = href ? 'a' : 'div';
    var hrefAttr = href ? ' href="'+href+'"' : '';
    var onclickAttr = onclick ? ' onclick="'+onclick+'"' : '';
    return '<'+tag+' class="idx-sheet-item'+(cls?' '+cls:'')+'"'+hrefAttr+onclickAttr+'>'+
        '<i class="'+icon+'"></i>'+
        '<div><div class="idx-sheet-item-title">'+title+'</div>'+
             '<div class="idx-sheet-item-sub">'+sub+'</div></div>'+
    '</'+tag+'>';
}

/* ═══════════════════════════════════════════════════════
   DISPATCHER — routes to the normal-post list or the
   post-ad list based on d.isAd. This is the ONLY thing
   openIndexSheet() calls, so nothing else needs to change.
   ═══════════════════════════════════════════════════════ */
function _idxSheetHTML(d, isOwner){
    var html = d.isAd ? _idxSheetAdHTML(d, isOwner) : _idxSheetPostHTML(d, isOwner);
    /* "Stats for nerds" is added HERE so every list gets it: normal posts, ads, and the lists pages supply themselves (group page …) */
    var row = _idxNerdsRow(d);
    if(row && html.indexOf('dzStatsForNerds') < 0){
        var k = html.indexOf('openReportModal');
        if(k > -1){ k = Math.max(html.lastIndexOf('<div', k), html.lastIndexOf('<a ', k), html.lastIndexOf('<button', k)); html = html.slice(0, k) + row + html.slice(k); }
        else html += row;
    }
    return html + _idxPidRow(d.id);   /* every post, at the very end of the list: its permanent Post ID + a copy button */
}
/* ── Post ID row (last item of the menu): shows the permanent TP-code and copies it ── */
var _idxPidCache = {};
function _idxPidRow(id){
    /* the icon is a DIRECT child so it gets the same dashed circle as every other row in the menu */
    return '<div class="idx-sheet-item idx-pid" id="idxPid" data-id="'+id+'" onclick="idxCopyPid(this)">'+
        '<i class="ri-fingerprint-line"></i>'+
        '<span class="idx-pid-tx"><span class="idx-pid-t">Post ID</span><span class="idx-pid-v">Loading…</span></span>'+
        '<span class="idx-pid-copy">Copy</span></div>';
}
function _idxFillPid(id){
    var el = document.getElementById('idxPid'); if(!el) return;
    var set = function(t){ var v = document.querySelector('#idxPid .idx-pid-v'); if(v && el.dataset.id == id){ v.textContent = t; el.dataset.uid = (t.indexOf('TP-') === 0) ? t : ''; } };
    if(_idxPidCache[id]){ set(_idxPidCache[id]); return; }
    fetch('post_uid.php?id=' + encodeURIComponent(id), { cache:'no-store', credentials:'same-origin' }).then(function(r){ return r.json(); }).then(function(j){
        if(j && j.ok && j.uid){ _idxPidCache[id] = j.uid; set(j.uid); } else set('Not available');
    }).catch(function(){ set('Not available'); });
}
function idxCopyPid(el){
    var uid = el && el.dataset.uid; if(!uid) return;
    var done = function(){ if(typeof _idxToast === 'function') _idxToast('Post ID copied'); var c = el.querySelector('.idx-pid-copy'); if(c){ c.textContent = 'Copied'; setTimeout(function(){ c.textContent = 'Copy'; }, 1600); } };
    if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(uid).then(done).catch(function(){ idxCopyFallback(uid); done(); }); }
    else { idxCopyFallback(uid); done(); }
}
function idxCopyFallback(t){ try{ var a = document.createElement('textarea'); a.value = t; a.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0'; document.body.appendChild(a); a.select(); document.execCommand('copy'); document.body.removeChild(a); }catch(e){} }
/* the video file of a post (single video, or the first video in a multi-media post); '' when there is none */
function _idxVideoName(d){
    var isV = function(n){ return /\.(mp4|mov|webm|m4v)(\?|$)/i.test(String(n || '')); }, clean = function(n){ return String(n).replace(/^.*uploads\//, '').replace(/\?.*$/, ''); };
    var c = [d.fileName, d.file, d.src, d.url];
    ['media', 'files', 'items', 'extra', 'mediaList'].forEach(function(k){ if(Array.isArray(d[k])) d[k].forEach(function(m){ c.push(typeof m === 'string' ? m : (m && (m.fileName || m.file || m.file_name || m.src || m.url))); }); });
    for(var i = 0; i < c.length; i++){ if(c[i] && isV(c[i])) return clean(c[i]); }
    /* no video: a picture post gets the panel too (size, format, download time …) */
    var isI = function(n){ return /\.(jpe?g|png|gif|webp|avif|bmp|heic)(\?|$)/i.test(String(n || '')); };
    for(var j = 0; j < c.length; j++){ if(c[j] && isI(c[j])) return clean(c[j]); }
    return '';
}
/* every picture / video file of a post (for the "Data used" total) */
function _idxAllMediaNames(d){
    var c = [d.fileName, d.file, d.src, d.url], out = [];
    ['media', 'files', 'items', 'extra', 'mediaList'].forEach(function(k){ if(Array.isArray(d[k])) d[k].forEach(function(m){ c.push(typeof m === 'string' ? m : (m && (m.fileName || m.file || m.file_name || m.src || m.url))); }); });
    c.forEach(function(x){ if(x && /\.(mp4|mov|webm|m4v|jpe?g|png|gif|webp|avif|bmp|heic)(\?|$)/i.test(String(x))){ var n = String(x).replace(/^.*uploads\//, '').replace(/\?.*$/, '').replace(/[\\'"<>]/g, ''); if(n && out.indexOf(n) < 0) out.push(n); } });
    return out;
}
function _idxNerdsRow(d){
    var n = _idxVideoName(d); if(!n) return '';
    n = n.replace(/[\\'"<>]/g, '');
    var all = _idxAllMediaNames(d).join('|');
    return '<div class="idx-sheet-item" onclick="closeIndexSheet();dzStatsForNerds('+d.id+',\''+n+'\',\''+all+'\')"><i class="ri-bar-chart-horizontal-line"></i> Stats for nerds</div>';
}

/* the profile page shows people's own posts: no Interested / Not interested / Why am I seeing this there */
function _idxOnProfile(){ return /(^|\/)profile[^\/]*\.php$/i.test(location.pathname); }

/* ── NORMAL POST LIST (+ "Interested" and the real "Why am I seeing this post") ── */
function _idxSheetPostHTML(d, isOwner){
    /* host hook: a page (e.g. the group page) can supply its own list of rows for a normal post.
       window.dzIndexItems(d, isOwner) → array of html strings. Ads always use the shared ad list. */
    if(typeof window.dzIndexItems === 'function'){
        var _custom = window.dzIndexItems(d, isOwner);
        if(_custom && _custom.length) return _custom.join('');
    }
    var items = [];

    items.push('<a class="idx-sheet-item" href="remix_upload.php?meme_id='+d.id+'"><i class="ri-scissors-cut-line"></i> Remix</a>');

    /* the creator can turn Download off for a post: then other people don't get the button (they can still always download their own) */
    if(d.type !== 'text' && d.fileName && d.fileName !== '0' && (isOwner || d.allowDownload !== 0)){
        items.push('<a class="idx-sheet-item" href="uploads/'+d.fileName+'" download><i class="ri-download-2-line"></i> Download</a>');
    }

    if(d.collab && d.collab.list && d.collab.list.length){
        items.push('<div class="idx-sheet-item" onclick="closeIndexSheet();dzCollabSheet('+d.id+')"><i class="ri-team-line"></i> View collaborators</div>');
        var _me = d.collab.list.filter(function(x){ return x.isMe; })[0];
        if(_me) items.push('<div class="idx-sheet-item danger" onclick="dzCollabLeave('+d.id+','+_me.id+')"><i class="ri-logout-box-r-line"></i> Leave this collaboration</div>');
    }
    items.push('<a class="idx-sheet-item" href="account.php?user_id='+d.uploadedBy+'"><i class="ri-user-search-line"></i> About Account</a>');
    items.push('<div class="idx-sheet-item" onclick="shareMeme('+d.id+');closeIndexSheet();"><i class="ri-share-line"></i> Share Post</div>');
    items.push('<div class="idx-sheet-item" onclick="closeIndexSheet();openSaveModal('+d.id+');"><i class="ri-bookmark-line"></i> Add to Collection</div>');

    if(d.remixOf){
        items.push('<a class="idx-sheet-item" href="index.php?meme='+d.remixOf+'"><i class="ri-arrow-go-back-line"></i> See Original</a>');
    }

    if(isOwner){
        items.push('<div class="idx-sheet-item" onclick="idxShowViewStats('+d.id+')"><i class="ri-bar-chart-2-line"></i> View insights</div>');
        items.push('<div class="idx-sheet-item" onclick="dzCollabManage('+d.id+')"><i class="ri-user-add-line"></i> Collaborators</div>');
        if(d.type !== 'text' && d.fileName && d.fileName !== '0'){
            var _dlOn = d.allowDownload !== 0;
            items.push('<div class="idx-sheet-item" onclick="idxToggleDownload('+d.id+')"><i class="'+(_dlOn ? 'ri-download-2-line' : 'ri-forbid-2-line')+'"></i> '+(_dlOn ? 'Turn off downloads' : 'Allow downloads')+'</div>');
        }
    }
    var _prof = _idxOnProfile();
    if(!_prof) items.push('<div class="idx-sheet-item" onclick="idxShowPostReason('+d.id+')"><i class="ri-information-line"></i> Why am I seeing this post</div>');

    /* not on your own posts, and not on the profile page */
    if(_prof){
        /* nothing here */
    } else if(!isOwner){
        items.push(_idxRow('', 'ri-checkbox-circle-line',
            'idxInterested('+d.id+')', null,
            'Interested', 'We\'ll show you more posts like this'));
        items.push(_idxRow('', 'ri-eye-off-line',
            'notInterestedFromSheet('+d.id+')', null,
            'Not Interested', 'We\'ll show you fewer posts like this'));
    } else {
        items.push('<div class="idx-sheet-item" onclick="notInterestedFromSheet('+d.id+')"><i class="ri-eye-off-line"></i> Not Interested</div>');
    }
    items.push('<div class="idx-sheet-item" onclick="openReportModal({contentId:'+d.id+',contentType:\'meme\'});closeIndexSheet();"><i class="ri-flag-line"></i> Report</div>');

    if(!isOwner){
        items.push('<div class="idx-sheet-item danger" onclick="closeIndexSheet();blockUser('+d.uploadedBy+')"><i class="ri-forbid-line"></i> Block User</div>');
    }

    return items.join('');
}

/* ══════════ STATS FOR NERDS — a small, playful live panel about the video / picture on screen ══════════
   Measured from the real player (nothing made up). Rows pop in one by one with the same bouncy motion as the Algorithm page; no haptics. Close with the X. */
(function(){
    var panel = null, timer = null, uidVal = '', info = {}, last = {t:0, f:0, fps:0}, rows = {}, order = 0;
    function fmtB(n){ if(!n) return '0 KB'; return n >= 1048576 ? (n/1048576).toFixed(2) + ' MB' : Math.round(n/1024) + ' KB'; }
    var isVid = function(n){ return /\.(mp4|mov|webm|m4v)$/i.test(n || ''); };

    function css(){
        if(document.getElementById('dzNerdsCss')) return;
        var st = document.createElement('style'); st.id = 'dzNerdsCss';
        st.textContent =
        '#dzNerds{--nb:cubic-bezier(.34,1.56,.64,1);position:fixed;left:8px;right:8px;top:calc(env(safe-area-inset-top,0px) + 70px);max-width:none;box-sizing:border-box;z-index:2147483000;'+
            'background:rgba(10,10,12,.72);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);color:#fff;border:1px solid rgba(255,255,255,.18);border-radius:0;'+
            'padding:8px 10px 7px;font-family:inherit;animation:dzNpanel .5s var(--nb) both;transform-origin:top left}'+
        'html[data-theme="light"] #dzNerds{background:rgba(255,255,255,.88);color:#0b0d24;border-color:rgba(6,7,26,.14)}'+
        '@keyframes dzNpanel{0%{opacity:0;transform:scale(.72) translateY(-8px)}60%{opacity:1;transform:scale(1.03)}100%{opacity:1;transform:none}}'+
        '@keyframes dzNrow{0%{opacity:0;transform:translateY(8px) scale(.92)}60%{opacity:1;transform:translateY(-1px) scale(1.015)}100%{opacity:1;transform:none}}'+
        '@keyframes dzNword{0%{opacity:0;transform:translateY(.7em) rotate(5deg) scale(.8)}60%{opacity:1;transform:translateY(-.1em) rotate(-1deg) scale(1.05)}100%{opacity:1;transform:none}}'+
        '@keyframes dzNsweep{0%,18%{color:#FF2D2D}40%,78%{color:#8b3dff}100%{color:inherit}}'+
        '#dzNerds .nh{display:flex;align-items:center;gap:8px;margin-bottom:3px}'+
        '#dzNerds .nt{flex:1;font-weight:700;font-size:.8rem;line-height:20px}'+
        '#dzNerds .nt .w{display:inline-block;white-space:pre;animation:dzNword .65s var(--nb) both,dzNsweep 2.2s ease both;animation-delay:var(--d,0ms)}'+
        '#dzNerds .nst{font-size:.66rem;font-weight:600;opacity:.9}'+
        '#dzNerds .nst.ok{color:#ff6b6b}#dzNerds .nst.warn{color:#ff9a9a}#dzNerds .nst.idle{opacity:.55}'+
        '#dzNerds .nx{all:unset;cursor:pointer;width:28px;height:28px;margin:-3px -4px -3px 0;display:flex;align-items:center;justify-content:center;font-size:23px;opacity:.85;transition:transform .3s var(--nb),opacity .15s}'+
        '#dzNerds .nx:hover{transform:rotate(90deg) scale(1.15);opacity:1}#dzNerds .nx:active{transform:scale(.85)}'+
        '#dzNerds .nl{display:flex;flex-direction:column}'+
        '#dzNerds .nr{display:flex;align-items:center;gap:9px;padding:2.5px 0;line-height:17px;animation:dzNrow .5s var(--nb) both;animation-delay:var(--d,0ms);transition:transform .3s var(--nb)}'+
        '#dzNerds .nr:hover{transform:translateX(3px)}'+
        '#dzNerds .ni{font-size:14.7px;width:16px;text-align:center;flex-shrink:0;opacity:.85;line-height:1}'+
        '#dzNerds .nk{font-size:.68rem;opacity:.6;flex:1;min-width:0;white-space:nowrap}'+
        '#dzNerds .nv{font-size:.7rem;font-weight:700;text-align:right;font-variant-numeric:tabular-nums;max-width:62%;transition:color .2s}'+
        '#dzNerds .nr.hb{flex-wrap:wrap;row-gap:3px;padding-bottom:3px}'+
        '#dzNerds .nbar{flex:0 0 calc(100% - 25px);max-width:calc(100% - 25px);box-sizing:border-box;height:3px;background:rgba(255,255,255,.12);overflow:hidden;margin-left:25px}'+
        'html[data-theme="light"] #dzNerds .nbar{background:rgba(6,7,26,.12)}'+
        '#dzNerds .nbar b{display:block;height:100%;width:0;background:linear-gradient(90deg,#FF2D2D,#8b3dff);transition:width .5s var(--nb)}'+
        '#dzNerds .nv.good{color:#ffa3a3}#dzNerds .nv.meh{color:#ff6b6b}#dzNerds .nv.bad{color:#ff2d2d}';
        document.head.appendChild(st);
    }
    /* one row per stat: created once (it pops in), afterwards only its text / colour / bar change */
    function row(key, icon, label, value, tone, pct){
        var list = panel.querySelector('.nl'), r = rows[key];
        if(!r){
            var el = document.createElement('div'); el.className = 'nr'; el.style.setProperty('--d', (order++ * 55) + 'ms');
            el.innerHTML = '<i class="ni ' + icon + '"></i><span class="nk"></span><span class="nv"></span>';
            if(key === 'uid' && list.firstChild) list.insertBefore(el, list.firstChild); else list.appendChild(el);
            r = rows[key] = { el: el, k: el.querySelector('.nk'), v: el.querySelector('.nv'), bar: null };
            r.k.textContent = label;
        }
        r.seen = true;
        if(r.v.textContent !== String(value)) r.v.textContent = value;
        var cls = 'nv' + (tone ? ' ' + tone : ''); if(r.v.className !== cls) r.v.className = cls;
        if(pct != null){
            if(!r.bar){ var bw = document.createElement('div'); bw.className = 'nbar'; bw.innerHTML = '<b></b>'; r.el.classList.add('hb'); r.el.appendChild(bw); r.bar = bw.firstChild; }
            r.bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
        }
    }
    function endRows(){ Object.keys(rows).forEach(function(k){ if(!rows[k].seen){ if(rows[k].el.parentNode) rows[k].el.parentNode.removeChild(rows[k].el); delete rows[k]; } else rows[k].seen = false; }); }
    function state(txt, cls){ var s = panel.querySelector('.nst'); if(s.dataset.s !== txt){ s.dataset.s = txt; s.className = 'nst ' + cls; s.textContent = txt; } }

    function findVideo(name){
        var best = null, bs = -1;
        Array.prototype.slice.call(document.querySelectorAll('video')).forEach(function(v){
            var src = (v.currentSrc || v.src || ''), r = v.getBoundingClientRect(), vis = Math.max(0, Math.min(r.bottom, innerHeight) - Math.max(r.top, 0)) * Math.max(0, Math.min(r.right, innerWidth) - Math.max(r.left, 0));
            var sc = (src.indexOf(name) >= 0 ? 1e12 : 0) + (v.paused ? 0 : 1e9) + vis;
            if(vis > 0 && sc > bs){ bs = sc; best = v; }
        });
        return best;
    }
    function findImage(name){
        var best = null, bs = -1;
        Array.prototype.slice.call(document.querySelectorAll('img')).forEach(function(im){
            if((im.currentSrc || im.src || '').indexOf(name) < 0) return;
            var r = im.getBoundingClientRect(), vis = Math.max(0, Math.min(r.bottom, innerHeight) - Math.max(r.top, 0)) * Math.max(0, Math.min(r.right, innerWidth) - Math.max(r.left, 0));
            if(vis > 0 && vis > bs){ bs = vis; best = im; }
        });
        return best;
    }
    function codecsFromBytes(buf){
        var u = new Uint8Array(buf), txt = '';
        for(var i = 0; i < u.length; i++) txt += String.fromCharCode(u[i]);
        var vc = /avc1|hvc1|hev1|av01|vp09|vp08/.exec(txt), ac = /mp4a|Opus|ac-3|ec-3|vorb/.exec(txt);
        var vm = { avc1:'H.264 (AVC)', hvc1:'H.265 (HEVC)', hev1:'H.265 (HEVC)', av01:'AV1', vp09:'VP9', vp08:'VP8' }, am = { mp4a:'AAC', Opus:'Opus', 'ac-3':'AC-3', 'ec-3':'E-AC-3', vorb:'Vorbis' };
        var mo = txt.indexOf('moov'), md = txt.indexOf('mdat');
        return { v: vc ? vm[vc[0]] : null, a: ac ? am[ac[0]] : null, fast: mo >= 0 && (md < 0 || mo < md) };
    }
    function probe(name){
        info = { codecs:'…', speed:'…', size:'', fast:'' };
        var t0 = performance.now();
        fetch('uploads/' + name, { headers:{ Range:'bytes=0-393215' }, cache:'no-store' }).then(function(r){
            var cr = r.headers.get('Content-Range'); if(cr && /\/(\d+)$/.test(cr)){ info.sizeB = +RegExp.$1; info.size = fmtB(info.sizeB); } else { var cl = r.headers.get('Content-Length'); if(cl){ info.sizeB = +cl; info.size = fmtB(+cl); } }
            return r.arrayBuffer().then(function(b){
                info.probeB = b.byteLength; var dt = (performance.now() - t0) / 1000; if(b.byteLength > 20000 && dt > 0) info.speed = Math.round(b.byteLength * 8 / dt / 1000).toLocaleString() + ' Kbps';
                var c = codecsFromBytes(b);
                info.codecs = (c.v || 'unknown') + (c.a ? ' + ' + c.a : (c.v ? ' · no audio' : ''));
                info.fast = c.v ? (c.fast ? 'Yes' : 'No (index at the end)') : '';
            });
        }).catch(function(){ info.codecs = 'unavailable'; info.speed = (navigator.connection && navigator.connection.downlink) ? Math.round(navigator.connection.downlink * 1000).toLocaleString() + ' Kbps (est.)' : 'unavailable'; });
    }
    function probeImg(name){
        info = { speed:'…', size:'…', type:'' };
        var t0 = performance.now();
        fetch('uploads/' + name, { cache:'no-store' }).then(function(r){
            info.type = (r.headers.get('Content-Type') || '').replace('image/', '').toUpperCase();
            return r.blob().then(function(b){
                info.probeB = b.size; var dt = (performance.now() - t0) / 1000; info.size = fmtB(b.size);
                if(b.size > 20000 && dt > 0) info.speed = Math.round(b.size * 8 / dt / 1000).toLocaleString() + ' Kbps';
                else info.speed = (navigator.connection && navigator.connection.downlink) ? Math.round(navigator.connection.downlink * 1000).toLocaleString() + ' Kbps (est.)' : 'too small to time';
            });
        }).catch(function(){ info.size = 'unavailable'; info.speed = 'unavailable'; });
    }
    /* DATA USED: what this post's files cost you. 'fetched' = came over the network; 'cached' = served from your phone's cache (no data). The panel's own test download is taken out. */
    var allNames = [];
    function dataUsed(){
        var f = 0, c = 0, sf = 0;
        try{
            performance.getEntriesByType('resource').forEach(function(e){
                if(e.name.indexOf('uploads/') < 0) return;
                var net = e.transferSize || 0, body = e.encodedBodySize || e.decodedBodySize || 0, isC = !net && body > 0;
                sf += net;
                for(var i = 0; i < allNames.length; i++){ if(e.name.indexOf(allNames[i]) >= 0){ if(isC) c += body; else f += net; break; } }
            });
        }catch(x){}
        f = Math.max(0, f - (info.probeB || 0)); sf = Math.max(0, sf - (info.probeB || 0));
        return { fetched: f, cached: c, session: sf };
    }
    function netBytes(name){
        var n = 0; try{ performance.getEntriesByType('resource').forEach(function(e){ if(e.name.indexOf(name) >= 0) n += (e.transferSize || e.encodedBodySize || 0); }); }catch(e){}
        return n;
    }
    function drawVideo(id, name){
        var v = findVideo(name), dpr = window.devicePixelRatio || 1;
        if(!v){ state('No video on screen', 'idle'); endRows(); return; }
        var q = v.getVideoPlaybackQuality ? v.getVideoPlaybackQuality() : null, now = performance.now();
        if(q){ if(last.t && now - last.t >= 900){ last.fps = Math.round((q.totalVideoFrames - last.f) / ((now - last.t) / 1000)); last.t = now; last.f = q.totalVideoFrames; } else if(!last.t){ last.t = now; last.f = q.totalVideoFrames; } }
        var buf = 0; try{ for(var i = 0; i < v.buffered.length; i++){ if(v.currentTime >= v.buffered.start(i) - .05 && v.currentTime <= v.buffered.end(i) + .05) buf = v.buffered.end(i) - v.currentTime; } }catch(e){}
        if(v.paused) state('Paused', 'idle'); else if(v.readyState < 3) state('Buffering', 'warn'); else state('Playing', 'ok');
        if(uidVal) row('uid', 'ri-fingerprint-line', 'Post ID', uidVal, '');
        var h = Math.min(v.videoWidth, v.videoHeight), res = h >= 1000 ? '1080p' : h >= 700 ? '720p' : h >= 460 ? '480p' : h + 'p';
        row('vp',   'ri-aspect-ratio-line','Viewport',          Math.round(v.clientWidth * dpr) + '×' + Math.round(v.clientHeight * dpr) + (q ? ' · ' + q.totalVideoFrames + ' frames' : ''), '');
        row('vid',  'ri-film-line',        'Video',             v.videoWidth + '×' + v.videoHeight + (last.fps ? ' @ ' + last.fps + ' fps' : '') + ' · ' + res, '');
        row('vol',  'ri-volume-up-line',   'Volume',            (v.muted ? 'Muted' : Math.round(v.volume * 100) + '%') + ' · ' + v.playbackRate + '×', v.muted ? 'meh' : '');
        row('cod',  'ri-code-s-slash-line','Codecs',            info.codecs, '');
        row('spd',  'ri-speed-line',       'Connection speed',  info.speed, '');
        var nb_ = netBytes(name);
        row('net',  'ri-download-cloud-2-line', 'Network activity', fmtB(nb_) + (info.size ? ' of ' + info.size : ''), '', info.sizeB ? Math.min(100, nb_ / info.sizeB * 100) : null);
        var du = dataUsed();
        row('dat',  'ri-database-2-line',  'Data used (this post)', fmtB(du.fetched) + (du.cached ? ' · ' + fmtB(du.cached) + ' cached' : ''), '');
        row('ses',  'ri-global-line',      'Data used (all posts)', fmtB(du.session), '');
        row('buf',  'ri-battery-charge-line', 'Buffer health',  buf.toFixed(1) + ' s', buf >= 4 ? 'good' : buf >= 1.5 ? 'meh' : 'bad', Math.min(100, buf / 10 * 100));
        row('drp',  'ri-error-warning-line', 'Dropped frames',  q ? q.droppedVideoFrames + (q.totalVideoFrames ? ' of ' + q.totalVideoFrames : '') : 'n/a', q && q.droppedVideoFrames > 0 ? (q.droppedVideoFrames > 12 ? 'bad' : 'meh') : 'good');
        if(info.fast) row('fst', 'ri-flashlight-line', 'Fast start', info.fast, /^Yes/.test(info.fast) ? 'good' : 'meh');
        row('pos',  'ri-time-line',        'Position',          v.currentTime.toFixed(1) + ' / ' + (isFinite(v.duration) ? v.duration.toFixed(1) : '?') + ' s', '', isFinite(v.duration) && v.duration ? v.currentTime / v.duration * 100 : 0);
        endRows();
    }
    function drawImg(id, name){
        var im = findImage(name), dpr = window.devicePixelRatio || 1;
        if(!im){ state('No picture on screen', 'idle'); endRows(); return; }
        var ok = im.complete && im.naturalWidth; state(ok ? 'Loaded' : 'Loading', ok ? 'ok' : 'warn');
        if(uidVal) row('uid', 'ri-fingerprint-line', 'Post ID', uidVal, '');
        var sc = im.clientWidth ? im.naturalWidth / (im.clientWidth * dpr) : 0, e = null;
        try{ e = performance.getEntriesByType('resource').filter(function(x){ return x.name.indexOf(name) >= 0; })[0]; }catch(x){}
        row('vp',  'ri-aspect-ratio-line', 'Viewport',         Math.round(im.clientWidth * dpr) + '×' + Math.round(im.clientHeight * dpr), '');
        row('img', 'ri-image-line',        'Image',            im.naturalWidth + '×' + im.naturalHeight + ' · ' + ((info.type || name.split('.').pop()).toUpperCase()), '');
        if(sc) row('shp', 'ri-focus-3-line', 'Sharpness',      sc >= 1 ? 'Crisp' : 'Enlarged ' + (1 / sc).toFixed(1) + '×', sc >= 1 ? 'good' : 'meh');
        row('siz', 'ri-file-line',         'File size',        info.size || '…', '');
        row('spd', 'ri-speed-line',        'Connection speed', info.speed || '…', '');
        row('net', 'ri-download-cloud-2-line', 'Network activity', fmtB(netBytes(name)), '');
        var du2 = dataUsed();
        row('dat', 'ri-database-2-line', 'Data used (this post)', fmtB(du2.fetched) + (du2.cached ? ' · ' + fmtB(du2.cached) + ' cached' : ''), '');
        row('ses', 'ri-global-line',     'Data used (all posts)', fmtB(du2.session), '');
        if(e) row('lt', 'ri-timer-flash-line', 'Load time',    Math.round(e.duration) + ' ms', e.duration < 400 ? 'good' : e.duration < 1200 ? 'meh' : 'bad');
        endRows();
    }
    window.dzStatsForNerds = function(id, name, all){
        dzStatsClose(); css(); rows = {}; order = 0;
        allNames = String(all || name).split('|').filter(Boolean); if(allNames.indexOf(name) < 0) allNames.push(name);
        panel = document.createElement('div'); panel.id = 'dzNerds';
        var words = ['Stats', 'for', 'nerds'], t = '';
        words.forEach(function(w, i){ t += '<span class="w" style="--d:' + (i * 90) + 'ms">' + w + (i < 2 ? ' ' : '') + '</span>'; });
        panel.innerHTML = '<div class="nh"><div class="nt">' + t + '</div><span class="nst idle">…</span><button type="button" class="nx" aria-label="Close"><i class="ri-close-line"></i></button></div><div class="nl"></div>';
        panel.querySelector('.nx').onclick = function(e){ e.stopPropagation(); dzStatsClose(); };
        ['touchstart','touchend','pointerdown','pointerup','click'].forEach(function(ev){ panel.addEventListener(ev, function(e){ e.stopPropagation(); }); });
        document.body.appendChild(panel);
        last = {t:0, f:0, fps:0}; uidVal = '';
        var vid = isVid(name); if(vid) probe(name); else probeImg(name);
        var loadUid = function(){ var _uidFor = id; fetch('post_uid.php?id=' + encodeURIComponent(id), { cache:'no-store', credentials:'same-origin' }).then(function(r){ return r.json(); }).then(function(j){ if(j && j.ok && j.uid && panel && _uidFor === id) uidVal = j.uid; }).catch(function(){}); };
        loadUid();
        /* The panel follows what is on screen: scroll from post A to post B and EVERY stat (and the Post ID) switches to B;
           inside a multi-media post it switches between its pictures and videos, each showing its own kind of stats. */
        var scopeRoot = function(){
            var ids = ['ipvOverlay', 'cardCaptionModal'];
            for(var i = 0; i < ids.length; i++){
                var el = document.getElementById(ids[i]);
                if(el && el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden' && getComputedStyle(el).display !== 'none') return el;
            }
            return document;
        };
        /* visible area of an element on the screen, also cut by every parent that clips (overflow hidden / auto / scroll) — so the slides of a swipe carousel that sit beside the current one count as NOT visible */
        var visArea = function(el){
            var r = el.getBoundingClientRect(), L = Math.max(r.left, 0), T = Math.max(r.top, 0), R = Math.min(r.right, innerWidth), B = Math.min(r.bottom, innerHeight);
            for(var n = el.parentNode; n && n.nodeType === 1 && n !== document.body; n = n.parentNode){
                var cs = getComputedStyle(n);
                if(/(hidden|auto|scroll|clip)/.test(cs.overflowX + cs.overflowY)){ var q = n.getBoundingClientRect(); L = Math.max(L, q.left); T = Math.max(T, q.top); R = Math.min(R, q.right); B = Math.min(B, q.bottom); }
            }
            return Math.max(0, R - L) * Math.max(0, B - T);
        };
        /* the file name behind an <img>/<video>; a thumbnail (uploads/thumbs/w480/uploads_x.jpg.webp) is mapped back to its original (x.jpg) so a picture is measured exactly like a solo picture */
        var upl = function(el){
            var m = /uploads\/([^?#]+)/.exec(el.currentSrc || el.src || el.getAttribute('data-src') || ''); if(!m) return null;
            var n = m[1], t = /^thumbs\/w\d+\/uploads_(.+)\.webp$/i.exec(n); if(t) n = t[1];
            return n;
        };
        /* really visible: not display:none / hidden / faded out (slides can be stacked on top of each other and only the active one is shown) */
        var shown = function(el){
            for(var n = el; n && n.nodeType === 1; n = n.parentNode){
                var cs = getComputedStyle(n);
                if(cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) < 0.05) return false;
            }
            return true;
        };
        /* of all pictures / videos stacked at that spot, is this the one on top? */
        var onTop = function(el){
            var r = el.getBoundingClientRect(), x = Math.min(innerWidth - 1, Math.max(0, (Math.max(r.left, 0) + Math.min(r.right, innerWidth)) / 2)), y = Math.min(innerHeight - 1, Math.max(0, (Math.max(r.top, 0) + Math.min(r.bottom, innerHeight)) / 2));
            var st = document.elementsFromPoint ? document.elementsFromPoint(x, y) : [];
            for(var i = 0; i < st.length; i++){ var t = st[i].tagName; if((t === 'VIDEO' || t === 'IMG') && !(st[i].closest && st[i].closest('#dzNerds'))){ if(st[i].clientWidth < 150 || !shown(st[i])) continue; return st[i] === el; } }
            return true;
        };
        var onScreen = function(){
            var best = null, bs = 0;
            Array.prototype.slice.call(scopeRoot().querySelectorAll('video,img')).forEach(function(el){
                if(!upl(el) || el.clientWidth < 150 || (el.closest && el.closest('#dzNerds,.top-bar,.ipv-avatar,[class*="avatar"]'))) return;
                var s = visArea(el); if(s <= bs || !shown(el) || !onTop(el)) return;
                bs = s; best = el;
            });
            if(!best) return null;
            var holder = best.closest ? best.closest('[data-id]') : null, pid = holder ? holder.getAttribute('data-id') : '';
            var slide = best.closest ? best.closest('#ccm-vtrack > *') : null;   /* Zeems: each post is one child of the vertical track */
            return { name: upl(best), pid: /^\d+$/.test(pid || '') ? pid : null, holder: holder, slide: slide };
        };
        var homeHolder = null, homeSlide = null, homeIdx, homeInZeems = null;
        var zIdx = function(){ try{ return (typeof _ccmIndex !== 'undefined') ? _ccmIndex : undefined; }catch(e){ return undefined; } };
        var go = function(){
            if(!panel) return;
            /* Zeems modal: the card belongs to the post it was opened on — a swipe / arrow to another post, or closing Zeems, removes it */
            var zm = document.getElementById('cardCaptionModal'), zOpen = !!(zm && zm.classList.contains('ccm-open'));
            if(homeInZeems === null){ homeInZeems = zOpen; homeIdx = zIdx(); }
            if(homeInZeems){
                if(!zOpen){ dzStatsClose(); return; }
                var zi = zIdx(); if(zi !== undefined && homeIdx !== undefined && zi !== homeIdx){ dzStatsClose(); return; }
            }
            var cur = onScreen();
            if(cur){
                var changed = false;
                /* a DIFFERENT post came on screen → the card goes away (it only follows the media of the post it was opened on) */
                if((cur.pid && String(cur.pid) !== String(id)) || (!cur.pid && cur.holder && homeHolder && cur.holder !== homeHolder)){ dzStatsClose(); return; }
                if(cur.holder && !homeHolder) homeHolder = cur.holder;
                if(cur.slide){ if(!homeSlide) homeSlide = cur.slide; else if(cur.slide !== homeSlide){ dzStatsClose(); return; } }
                if(cur.holder) Array.prototype.slice.call(cur.holder.querySelectorAll('video,img')).forEach(function(el){ var n = upl(el); if(n && el.clientWidth >= 150 && allNames.indexOf(n) < 0) allNames.push(n); });
                if(cur.name !== name || changed){
                    name = cur.name; vid = isVid(name); last = {t:0, f:0, fps:0};
                    if(allNames.indexOf(name) < 0) allNames.push(name);
                    if(vid) probe(name); else probeImg(name);
                }
            }
            if(vid) drawVideo(id, name); else drawImg(id, name);
        };
        /* nearly as wide as the Zeems box (laptop) or the phone screen, with a small gap on each side */
        var fit = function(){
            if(!panel) return;
            var st = document.getElementById('ccm-stage'), m = document.getElementById('cardCaptionModal'), r = (st && m && m.classList.contains('ccm-open')) ? st.getBoundingClientRect() : null;
            if(r && r.width > 200 && innerWidth > 790){ panel.style.left = Math.round(r.left + 8) + 'px'; panel.style.right = 'auto'; panel.style.width = Math.round(r.width - 16) + 'px'; }
            else if(innerWidth > 790){ panel.style.left = '10px'; panel.style.right = 'auto'; panel.style.width = '380px'; }
            else { panel.style.left = '8px'; panel.style.right = '8px'; panel.style.width = 'auto'; }
        };
        fit(); window.addEventListener('resize', fit);
        go(); timer = setInterval(function(){ fit(); go(); }, 500);
    };
    window.dzStatsClose = function(){ if(timer){ clearInterval(timer); timer = null; } window.removeEventListener('resize', window.__dzNfit || function(){}); if(panel && panel.parentNode) panel.parentNode.removeChild(panel); panel = null; };
})();

/* ── POST-AD LIST — a meme flagged is_ad=1, NOT a standalone
   sponsorships-table ad. Mirrors Instagram's ad menu.
   Every item carries a title+subtitle pair. ── */
function _idxSheetAdHTML(d, isOwner){
    var items = [];
    var uname = _idxEsc(d.posterUsername || 'this account');
    var _prof = _idxOnProfile();

    if(!_prof) items.push(_idxRow(
        '', 'ri-checkbox-circle-line',
        'idxAdInterested('+d.id+')', null,
        'Interested',
        'We\'ll show you more Ads like this'
    ));

    if(!_prof) items.push(_idxRow(
        '', 'ri-close-circle-line',
        'idxAdNotInterested('+d.id+')', null,
        'Not Interested',
        'We\'ll show you fewer Ads like this'
    ));

    items.push(_idxRow(
        '', 'ri-bookmark-line',
        'closeIndexSheet();openSaveModal('+d.id+');', null,
        'Add to Collection',
        'Save this ad to one of your collections'
    ));

    items.push(_idxRow(
        '', 'ri-information-line',
        null, 'account.php?user_id='+d.uploadedBy,
        'About this Ad',
        'See who\'s behind this ad and why it was made'
    ));

    if(!_prof) items.push(_idxRow(
        '', 'ri-question-line',
        'idxShowAdReason('+d.id+')', null,
        'Why am I seeing this Ad',
        'Learn how ads are chosen for your feed'
    ));

    items.push(_idxRow(
        '', 'ri-flag-line',
        'openReportModal({contentId:'+d.id+',contentType:\'meme\'});closeIndexSheet();', null,
        'Report Ad',
        'Flag this ad for review — your report stays anonymous'
    ));

    if(!isOwner){
        items.push(_idxRow(
            'danger', 'ri-forbid-line',
            'closeIndexSheet();blockUser('+d.uploadedBy+')', null,
            'Block Ad Owner',
            'You won\'t see posts or ads from '+uname+' again'
        ));
    }

    return items.join('');
}

/* ═══════════════════════════════════════════════════════
   DETAIL SCREEN — shared slide-in "why am I seeing this"
   ═══════════════════════════════════════════════════════ */
function idxSheetShowDetail(title, bodyHtml){
    document.getElementById('idxDetailTitle').textContent = title;
    document.getElementById('idxDetailBody').innerHTML = bodyHtml;
    document.getElementById('indexSheetTrack').classList.add('show-detail');
}
function idxSheetBackToList(){
    document.getElementById('indexSheetTrack').classList.remove('show-detail');
}

/* owner only: turn "Download" on / off for one post */
function idxToggleDownload(id){
    var body = 'action=toggle_download&meme_id=' + encodeURIComponent(id) + (window.DZ_CSRF ? '&csrf=' + encodeURIComponent(window.DZ_CSRF) : '');
    fetch(window.CCM_ENDPOINT || window.location.pathname, { method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body:body })
    .then(function(r){ return r.json(); })
    .then(function(j){
        if(!j || j.status !== 'ok'){ if(typeof showToast === 'function') showToast('Could not change that.'); return; }
        /* keep the cached menu data in step so the next open shows the new wording */
        if(window.kebabData && window.kebabData[id]) window.kebabData[id].allowDownload = j.allow_download;
        if(typeof closeIndexSheet === 'function') closeIndexSheet();
        if(typeof showToast === 'function') showToast(j.allow_download ? 'Downloads are on for this post' : 'Downloads are off for this post');
    }).catch(function(){ if(typeof showToast === 'function') showToast('Connection problem.'); });
}

/* owner only — "View insights": overview first, then Engagement / Audience / Discovery / Viewing behaviour / Over time.
   Views = times it was really on screen · Reach = different accounts that saw it · Impressions = times it was shown. */
function idxShowViewStats(id){
    idxSheetShowDetail('View insights', '<div class="idx-detail-general">Loading…</div>');
    var body = 'action=view_stats&meme_id=' + encodeURIComponent(id) + (window.DZ_CSRF ? '&csrf=' + encodeURIComponent(window.DZ_CSRF) : '');
    fetch(window.CCM_ENDPOINT || window.location.pathname, { method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded' }, body:body })
    .then(function(r){ return r.json(); })
    .then(function(j){
        if(!j || j.status !== 'ok'){ idxSheetShowDetail('View insights', '<div class="idx-detail-general">Not available here.</div>'); return; }
        var st = j.stats, E = st.eng || {}, A = st.aud || {}, W = st.watch || {}, T = st.time || {};
        var fmt = function(n){ n=+n||0; return n>=1e6 ? (Math.round(n/1e5)/10)+'M' : n>=1e3 ? (Math.round(n/100)/10)+'K' : String(n); };
        var pct = function(a, b){ return b > 0 ? (Math.round(a / b * 1000) / 10) + '%' : '—'; };
        var dur = function(ms){ ms = +ms || 0; if(!ms) return '—'; var s2 = ms/1000; if(s2 < 60) return (Math.round(s2*10)/10)+' s'; var m = Math.floor(s2/60); return m < 60 ? m+' min '+Math.round(s2%60)+' s' : Math.floor(m/60)+' h '+(m%60)+' min'; };
        var BAR = '#f24455', MUTE = 'font-size:11px;opacity:.55';
        function tile(v, l){ return '<div style="flex:1 1 40%;min-width:0;padding:12px 8px;border-radius:5px;background:rgba(128,128,128,.12);text-align:center"><div style="font-size:20px;font-weight:700">'+v+'</div><div style="font-size:11px;opacity:.6;margin-top:2px">'+l+'</div></div>'; }
        function row(l, v, sub){ return '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding:7px 0;font-size:13px;border-bottom:1px solid rgba(128,128,128,.12)"><span>'+l+(sub?' <span style="'+MUTE+'">'+sub+'</span>':'')+'</span><b>'+v+'</b></div>'; }
        function bar(l, c, tot, right){ var w = tot > 0 ? Math.max(2, Math.round(c / tot * 100)) : 0;
            return '<div style="padding:6px 0;font-size:13px"><div style="display:flex;justify-content:space-between;margin-bottom:4px"><span>'+l+'</span><b>'+(right || fmt(c))+'</b></div>'+
                   '<div style="height:6px;border-radius:3px;background:rgba(128,128,128,.15);overflow:hidden"><div style="height:100%;width:'+w+'%;background:'+BAR+'"></div></div></div>'; }
        function sec(title, icon, inner, open){ return '<details'+(open?' open':'')+' style="border-top:1px solid rgba(128,128,128,.18);margin-top:12px;padding-top:2px"><summary style="list-style:none;cursor:pointer;display:flex;align-items:center;gap:8px;padding:10px 0;font-size:13px;font-weight:700"><i class="'+icon+'" style="color:'+BAR+'"></i>'+title+'<i class="ri-arrow-down-s-line" style="margin-left:auto;opacity:.5"></i></summary>'+inner+'</details>'; }
        var empty = function(t){ return '<div style="'+MUTE+';padding:4px 0 10px">'+t+'</div>'; };
        var isText = (st.type === 'text');

        /* 1 · overview */
        var html = '<div style="display:flex;flex-wrap:wrap;gap:8px">'+tile(fmt(st.total),'Views')+tile(fmt(st.unique),'Reach')+tile(fmt(st.interactions),'Interactions')+tile((st.engagement_rate||0)+'%','Engagement rate')+'</div>'+
                   '<div style="'+MUTE+';margin-top:8px">'+fmt(st.impressions)+' impressions · engagement rate = interactions ÷ reach</div>';

        /* 2 · engagement */
        var eTot = (E.likes||0)+(E.reposts||0)+(E.saves||0)+(E.shares||0)+(E.comments||0);
        var shareTypes = {internal:'In Dezzme', message:'In messages', external:'Outside Dezzme', copy_link:'Link copied'};
        var shareSub = Object.keys(E.share_types || {}).map(function(k){ return (shareTypes[k]||k)+' '+fmt(E.share_types[k]); }).join(' · ');
        html += sec('Engagement', 'ri-heart-3-line',
            bar('Likes', E.likes||0, eTot)+bar('Reposts', E.reposts||0, eTot)+bar('Saves', E.saves||0, eTot)+bar('Shares', E.shares||0, eTot)+bar('Comments', E.comments||0, eTot)+
            (shareSub ? '<div style="'+MUTE+';padding:2px 0 6px">Shares: '+shareSub+'</div>' : '')+
            row('Profile visits', fmt(E.profile_visits||0), 'within a day of viewing')+row('New followers', fmt(E.new_followers||0), 'within a day of viewing'), true);

        /* 3 · audience */
        var mem = (A.followers||0)+(A.non_followers||0);
        html += sec('Audience', 'ri-group-line',
            (mem + (A.guests||0) > 0
                ? bar('Followers', A.followers||0, mem + (A.guests||0))+bar('Not following you', A.non_followers||0, mem + (A.guests||0))+bar('Signed-out visitors', A.guests||0, mem + (A.guests||0))+
                  '<div style="height:8px"></div>'+bar('New viewers', A.new_viewers||0, (A.new_viewers||0)+(A.returning||0))+bar('Returning viewers', A.returning||0, (A.new_viewers||0)+(A.returning||0))
                : empty('No viewers yet.'))+
            '<div style="'+MUTE+';padding:6px 0 4px">Location and age aren\'t collected on Dezzme yet, so they can\'t be shown.</div>', false);

        /* 4 · discovery */
        var srcName = {feed:'Home feed', zeems:'Zeems', search:'Search', profile:'Profile', history:'History', open:'Direct / shared link', backfill:'Earlier views'};
        var sTot = 0; (st.sources||[]).forEach(function(x){ sTot += x.c; });
        html += sec('How people found it', 'ri-compass-3-line',
            ((st.sources||[]).map(function(x){ return bar(_idxEsc(srcName[x.s] || x.s), x.c, sTot, fmt(x.c)+' · '+pct(x.c, sTot)); }).join('') || empty('No views yet.')), false);

        /* 5 · viewing behaviour (not for text memes) */
        if(!isText){
            var bk = W.buckets || [0,0,0,0], bTot = bk[0]+bk[1]+bk[2]+bk[3];
            html += sec('Viewing behaviour', 'ri-timer-line',
                row('Average time on screen', dur(st.avg_watch_ms))+row('Total time on screen', dur(W.total_ms))+
                '<div style="'+MUTE+';padding:10px 0 2px">How long people stayed</div>'+
                (bTot ? bar('Under 1 s', bk[0], bTot, pct(bk[0], bTot))+bar('1–3 s', bk[1], bTot, pct(bk[1], bTot))+bar('3–10 s', bk[2], bTot, pct(bk[2], bTot))+bar('10 s or more', bk[3], bTot, pct(bk[3], bTot)) : empty('Not enough watch data yet.')), false);
        }

        /* 6 · performance over time */
        var max = 1; (st.days||[]).forEach(function(d){ if(d.c > max) max = d.c; });
        var bars = (st.days||[]).map(function(d){
            var h = Math.max(3, Math.round(d.c / max * 54));
            var lab = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][new Date(d.d + 'T12:00:00').getDay()];
            return '<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px"><div style="font-size:10px;opacity:.6">'+fmt(d.c)+'</div>'+
                   '<div style="width:100%;max-width:22px;height:'+h+'px;background:'+BAR+';border-radius:4px 4px 0 0"></div><div style="font-size:10px;opacity:.55">'+lab+'</div></div>';
        }).join('');
        html += sec('Over time', 'ri-line-chart-line',
            row('First hour', fmt(T.hour1||0))+row('First 24 hours', fmt(T.day1||0))+row('First 7 days', fmt(T.day7||0))+row('Lifetime', fmt(T.life||0))+
            '<div style="'+MUTE+';padding:12px 0 6px">Last 7 days</div><div style="display:flex;align-items:flex-end;gap:6px;height:90px">'+bars+'</div>', false);

        html += '<div style="font-size:11px;opacity:.5;margin-top:14px">A view is a post that was really on screen — one per person per 24 hours; your own views and ads don\'t count. Engagement excludes your own actions.</div>';
        idxSheetShowDetail('View insights', html);
    }).catch(function(){ idxSheetShowDetail('View insights', '<div class="idx-detail-general">Not available here.</div>'); });
}

/* the real reasons (d.why, built by shared_core.php from the ranking signals) as a list */
function _idxWhyListHTML(d){
    var w = (d && d.why && d.why.length) ? d.why : [];
    if(!w.length) return '';
    return '<div class="idx-why-list">' + w.map(function(r){
        var ic = /^ri-[a-z0-9\-]+$/.test(r.icon || '') ? r.icon : 'ri-information-line';
        return '<div class="idx-why-row">'+
            '<span class="idx-why-ic"><i class="'+ic+'"></i></span>'+
            '<div class="idx-why-txt"><div class="idx-why-title">'+_idxEsc(r.title)+'</div>'+
            (r.text ? '<div class="idx-why-sub">'+_idxEsc(r.text)+'</div>' : '')+'</div>'+
        '</div>';
    }).join('') + '</div>';
}
function _idxAlgoBtn(){
    return '<a class="idx-detail-link-btn" href="algorithm_settings.php"><i class="ri-equalizer-line"></i> Adjust your algorithm</a>';
}
function _idxPost(id){
    return (window.kebabData && window.kebabData[id]) || null;
}

/* one personal sentence built from the real reasons (d.why): "@leah's post is in your feed mainly because you track @leah and it matches topics you like: love." */
function _idxWhyLead(d, noun){
    var w = (d && d.why && d.why.length) ? d.why : [];
    var who = d && d.posterUsername ? '@' + _idxEsc(d.posterUsername) + '\'s ' : 'This ';
    var bits = [];
    for(var i = 0; i < w.length && bits.length < 2; i++){
        var t = String(w[i].title || '').trim(); if(!t) continue;
        bits.push(_idxEsc(t.charAt(0).toLowerCase() + t.slice(1)));
    }
    var head = bits.length
        ? who + noun + ' is in your feed mainly because ' + bits.join(' and ') + '.'
        : who + noun + ' is a little different from what you usually see: Dezzme mixes in fresh and popular ' + noun + 's so your feed stays interesting.';
    return '<div class="idx-detail-general" style="font-weight:600;font-size:15px;line-height:1.45;margin-bottom:10px">' + head + '</div>';
}

/* normal posts — the real reasons from the ranking algorithm */
function idxShowPostReason(id){
    var d = _idxPost(id) || {};
    var intro = _idxWhyLead(d, 'post') +
        '<div class="idx-detail-general">'+
            'Dezzme suggests posts based on your activity, the creators you track, the topics you choose, '+
            'and what is popular or new. These are the reasons this post is in your feed:'+
        '</div>';
    var list = _idxWhyListHTML(d);
    if(!list){
        list = '<div class="idx-detail-unique">Dezzme mixes in recent and popular posts from outside your usual interests so your feed does not go stale.</div>';
    }
    idxSheetShowDetail("Why you're seeing this post", intro + list + _idxAlgoBtn());
}

/* ads — general explanation + advertiser + the real reasons (nothing about the advertiser's private targeting) */
function idxShowAdReason(id){
    var d = _idxPost(id) || {};
    var pic   = _idxEsc(d.posterPic || 'mee.jpg');
    var uname = _idxEsc(d.posterUsername || 'this account');

    var general = _idxWhyLead(d, 'ad') +
        '<div class="idx-detail-general">'+
            'Ads are shown in your Dezzme feed based on a mix of signals — your recent activity, the topics '+
            'and creators you engage with, and what\'s trending across the app right now. Ads help keep Dezzme free to use for everyone.'+
        '</div>';

    var divider = '<div class="idx-detail-divider"><span>About this ad</span></div>';

    var posterRow =
        '<div class="idx-detail-poster-row">'+
            '<img src="'+pic+'" onerror="this.src=\'mee.jpg\'" class="idx-detail-poster-pic">'+
            '<div>'+
                '<div class="idx-detail-poster-name">'+uname+'</div>'+
                '<div class="idx-detail-poster-sub">Sponsored content</div>'+
            '</div>'+
        '</div>';

    var list = _idxWhyListHTML(d);
    var unique = list ||
        ('<div class="idx-detail-unique">'+uname+' chose to reach people with interests similar to yours. '+
         'As your activity on Dezzme changes over time, the ads you see may change too.</div>');

    var hideBtn =
        '<button class="idx-detail-hide-btn" data-u="'+uname+'" onclick="idxHideAllAdsFromUser('+(+d.uploadedBy||0)+', this.getAttribute(\'data-u\'))">'+
            '<i class="ri-eye-off-line"></i> Hide all Ads from '+uname+
        '</button>';

    idxSheetShowDetail("Why you're seeing this Ad", general + divider + posterRow + unique + _idxAlgoBtn() + '<div style="height:10px"></div>' + hideBtn);
}

/* ═══════════════════════════════════════════════════════
   INTERESTED (normal posts + ads) — for now only a thank-you, nothing changes in the feed yet
   ═══════════════════════════════════════════════════════ */
/* tells the server this person wants more like this post (taste signal) — used by the sheet, the home / text feed question and the zeems modal */
function dzInterested(memeId){
    var csrf = window.DZ_CSRF ? '&csrf=' + encodeURIComponent(window.DZ_CSRF) : '';
    return fetch(window.DZ_ACTION_ENDPOINT || window.location.pathname, {
        method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
        body:'action=interested&meme_id=' + encodeURIComponent(memeId) + csrf
    }).then(function(r){ return r.json(); }).catch(function(){});
}
window.dzInterested = dzInterested;
function idxInterested(memeId){
    closeIndexSheet();
    dzInterested(memeId);
    _idxToast("Thanks! We'll show you more posts like this");
}
function idxAdInterested(memeId){
    closeIndexSheet();
    _idxToast("Thanks! We'll show you more Ads like this");
}

/* ═══════════════════════════════════════════════════════
   NOT INTERESTED — works on every page
   1) zeems modal  → ccmNotInterested (unchanged)
   2) the page still has its own notInterested(id, btn) and the post's menu button → use it (unchanged)
   3) otherwise (the old code did NOTHING here) → the sheet does it itself:
        POST action=not_interested → hide the post on the page → Undo bar (undo_not_interested)
   ═══════════════════════════════════════════════════════ */
function idxAdNotInterested(memeId){
    notInterestedFromSheet(memeId); // same flow for ads
}

function notInterestedFromSheet(memeId){
    /* inside the zeems modal: the post scrolls on by itself and the next post shows an Undo card (ccmNotInterested lives in zeems_modal.php) */
    var _z = document.getElementById('cardCaptionModal');
    if(_z && _z.classList.contains('ccm-open') && typeof window.ccmNotInterested === 'function'){
        closeIndexSheet();
        window.ccmNotInterested(memeId);
        return;
    }
    var btn = document.querySelector('.index-kebab-btn[data-id="'+memeId+'"]');
    if(btn && typeof window.notInterested === 'function'){
        window.notInterested(memeId, btn);
        closeIndexSheet();
        return;
    }
    closeIndexSheet();
    idxNotInterestedDirect(memeId);
}

/* find every element on the page that shows this post, so it can be hidden (and shown again on Undo) */
function _idxPostEls(memeId){
    var id = String(memeId), found = [], seen = [];
    function add(el){ if(el && seen.indexOf(el) === -1 && el !== document.body && el !== document.documentElement){ seen.push(el); found.push(el); } }
    ['data-meme-id','data-post-id','data-mid','data-memeid'].forEach(function(a){
        document.querySelectorAll('['+a+'="'+id+'"]').forEach(function(el){
            /* a button inside the post carries the id too: hide the whole card instead */
            var card = el.closest('.post-card,.feed-card,.meme-card,.feed-post,.post,.idx-card,article,li') || el;
            add(card);
        });
    });
    var kb = document.querySelector('.index-kebab-btn[data-id="'+id+'"]');
    if(kb) add(kb.closest('.post-card,.feed-card,.meme-card,.feed-post,.post,.idx-card,article,li'));
    return found;
}

function idxNotInterestedDirect(memeId){
    var els = _idxPostEls(memeId), prev = els.map(function(el){ return el.style.display; });
    els.forEach(function(el){ el.style.display = 'none'; });
    var csrf = window.DZ_CSRF ? '&csrf=' + encodeURIComponent(window.DZ_CSRF) : '';
    fetch(window.DZ_ACTION_ENDPOINT || window.location.pathname, {
        method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
        body:'action=not_interested&meme_id=' + encodeURIComponent(memeId) + csrf
    })
    .then(function(r){ return r.json(); })
    .then(function(j){
        if(!j || (j.status !== 'ok' && !j.success)) throw new Error('failed');
        try{ document.dispatchEvent(new CustomEvent('dz:not-interested', { detail:{ memeId:memeId } })); }catch(e){}
        _idxUndoBar("Got it. We'll show fewer posts like this.", function(){
            fetch(window.DZ_ACTION_ENDPOINT || window.location.pathname, {
                method:'POST', credentials:'same-origin',
                headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
                body:'action=undo_not_interested&meme_id=' + encodeURIComponent(memeId) + csrf
            }).then(function(r){ return r.json(); }).then(function(){
                els.forEach(function(el, i){ el.style.display = prev[i]; });
                try{ document.dispatchEvent(new CustomEvent('dz:not-interested-undo', { detail:{ memeId:memeId } })); }catch(e){}
                _idxToast('Brought back.');
            }).catch(function(){ _idxToast('Could not undo that.'); });
        });
    })
    .catch(function(){
        els.forEach(function(el, i){ el.style.display = prev[i]; });   /* the server said no: show the post again */
        _idxToast('Something went wrong. Try again.');
    });
}

/* plain toast: the page's own showToast when it has one */
function _idxToast(msg){
    if(typeof showToast === 'function'){ showToast(msg); return; }
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);'+
        'background:rgba(20,20,25,.95);color:#fff;padding:8px 20px;border-radius:50px;'+
        'font-size:12px;z-index:999999999;font-family:Poppins,sans-serif;';
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); }, 2200);
}

/* bar with an Undo button (6 seconds) */
var _idxUbT = null;
function _idxUndoBar(msg, onUndo){
    var b = document.getElementById('idxUndoBar');
    if(!b){
        b = document.createElement('div');
        b.id = 'idxUndoBar'; b.className = 'idx-undo-bar';
        b.innerHTML = '<span></span><button type="button">Undo</button>';
        document.body.appendChild(b);
    }
    b.querySelector('span').textContent = msg;
    var btn = b.querySelector('button');
    btn.onclick = function(){ clearTimeout(_idxUbT); b.classList.remove('show'); if(onUndo) onUndo(); };
    b.classList.remove('show'); void b.offsetWidth; b.classList.add('show');
    clearTimeout(_idxUbT);
    _idxUbT = setTimeout(function(){ b.classList.remove('show'); }, 6000);
}

/* "Hide all Ads from…": saved by the hide_ads_from_user action on this page (table hidden_ad_advertisers)
   and filtered out of the feed query. */
function idxHideAllAdsFromUser(userId, username){
    if(!confirm('Hide all ads from '+(username||'this account')+'?')) return;
    fetch(location.pathname, {
        method:  'POST',
        credentials:'same-origin',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'action=hide_ads_from_user&target_id='+userId
    })
    .then(function(r){ return r.json(); })
    .then(function(){
        showToast("You won't see ads from "+(username||'this account')+' anymore');
        closeIndexSheet();
    })
    .catch(function(){ showToast('Something went wrong'); });
}

/* ═══════════════════════════════════════════════════════
   OPEN / CLOSE — resets to list view every time it opens
   ═══════════════════════════════════════════════════════ */
/* ── Auto scroll (Zeems modal only) ────────────────────────────────────────────
   The row shows ONLY when this sheet was opened from the Zeems modal (#cardCaptionModal.ccm-open) and that
   modal provides ccmAutoScrollIsOn() / ccmAutoScrollSet(bool). Elsewhere nothing is added. */
function _idxInZeems(){
    var z = document.getElementById('cardCaptionModal');
    return !!(z && z.classList.contains('ccm-open') && typeof window.ccmAutoScrollSet === 'function');
}
function _idxAutoScrollRow(){
    if(!_idxInZeems()) return '';
    var on = (typeof window.ccmAutoScrollIsOn === 'function') && window.ccmAutoScrollIsOn();
    return '<div class="idx-sheet-item idx-auto-row" onclick="idxToggleAutoScroll(this)">'
         + '<i class="ri-arrow-down-double-line"></i> Auto scroll'
         + '<span class="idx-sw' + (on ? ' on' : '') + '"></span></div>';
}
function _idxPlaybackRows(){
    if(!_idxInZeems()) return '';
    return '<div class="idx-sheet-item" onclick="idxOpenPlayback()"><i class="ri-play-circle-line"></i> Playback controls'
         + '<svg class="idx-pb-go" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg></div>';
}
var _IDX_SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2];
function _idxSpeedLbl(s){ return s + 'x'; }
function _idxPlaybackBody(){
    var on = (typeof window.ccmAutoScrollIsOn === 'function') && window.ccmAutoScrollIsOn();
    var cc = window.ccmCapsIsOn && window.ccmCapsIsOn();
    var cur = window.ccmSpeedGet ? window.ccmSpeedGet() : 1;
    var h = '<div class="idx-pb-list">'
        + '<div class="idx-sheet-item idx-auto-row" onclick="idxToggleAutoScrollPB(this)"><i class="ri-arrow-down-double-line"></i> Auto scroll<span class="idx-sw' + (on ? ' on' : '') + '"></span></div>'
        + '<div class="idx-sheet-item idx-auto-row" onclick="idxToggleCaps(this)"><i class="ri-closed-captioning-line"></i> Closed captions<span class="idx-sw' + (cc ? ' on' : '') + '"></span></div>'
        + '<div class="idx-sheet-item" onclick="idxFullView()"><i class="ri-fullscreen-line"></i> View full screen</div>'
        + '<div class="idx-pb-label">Speed</div><div class="idx-pb-chips">';
    _IDX_SPEEDS.forEach(function(sp){
        h += '<span class="idx-pid-copy idx-pb-chip' + (sp === cur ? ' sel' : '') + '" onclick="idxPickSpeed(' + sp + ',this)">' + _idxSpeedLbl(sp) + '</span>';
    });
    return h + '</div></div>';
}
function idxOpenPlayback(){
    if(window.dzHaptic) dzHaptic.light();
    idxSheetShowDetail('Playback controls', _idxPlaybackBody());
}
function idxToggleAutoScrollPB(row){
    if(typeof window.ccmAutoScrollSet !== 'function') return;
    var on = !(typeof window.ccmAutoScrollIsOn === 'function' && window.ccmAutoScrollIsOn());
    window.ccmAutoScrollSet(on);
    var sw = row.querySelector('.idx-sw'); if(sw) sw.classList.toggle('on', on);
}
function idxPickSpeed(sp, el){
    window.ccmSpeedSet(sp);
    var l = el.parentNode.querySelectorAll('.idx-pb-chip');
    for(var i = 0; i < l.length; i++) l[i].classList.toggle('sel', l[i] === el);
}
function idxToggleCaps(row){
    var on = window.ccmCapsToggle(); var sw = row.querySelector('.idx-sw'); if(sw) sw.classList.toggle('on', !!on);
}
function idxFullView(){
    closeIndexSheet();
    setTimeout(function(){ if(typeof window.ccmFsViewEnter === 'function') window.ccmFsViewEnter(); }, 200);
}
function idxToggleAutoScroll(row){
    if(typeof window.ccmAutoScrollSet !== 'function') return;
    var on = !(typeof window.ccmAutoScrollIsOn === 'function' && window.ccmAutoScrollIsOn());
    window.ccmAutoScrollSet(on);
    var sw = row && row.querySelector('.idx-sw'); if(sw) sw.classList.toggle('on', on);
    setTimeout(closeIndexSheet, 260);
}

function openIndexSheet(memeId){
    if(window.dzHaptic) dzHaptic.light();
    var d = window.kebabData && window.kebabData[memeId];
    /* a page that does not pre-fill kebabData (profile / group viewers) can resolve the post on demand */
    if(!d && typeof window.dzKebabResolve === 'function'){ try{ d = window.dzKebabResolve(memeId); }catch(e){} if(d){ window.kebabData = window.kebabData || {}; window.kebabData[memeId] = d; } }
    if(!d) return;
    var isOwner = String(d.uploadedBy) === String(currentUserId);

    document.getElementById('indexSheetTrack').classList.remove('show-detail');
    document.getElementById('indexSheetList').innerHTML = _idxPlaybackRows() + _idxSheetHTML(d, isOwner);
    _idxFillPid(d.id);
    document.getElementById('indexSheetOverlay').classList.add('show');
    document.getElementById('indexSheetPanel').classList.add('show');
    document.body.style.overflow = 'hidden';
}


/* ── drag the sheet with a finger (phone): follows the hand, flick or pull down to close, otherwise springs back ── */
(function(){
    function init(){
        var panel = document.getElementById('indexSheetPanel'), ov = document.getElementById('indexSheetOverlay');
        if(!panel || panel._dragInit) return; panel._dragInit = true;
        var y0 = 0, dy = 0, t0 = 0, on = false, pending = false;
        function scroller(el){
            while(el && el !== panel){
                if(el.scrollHeight > el.clientHeight + 1){ var cs = getComputedStyle(el).overflowY; if(cs === 'auto' || cs === 'scroll') return el; }
                el = el.parentNode;
            }
            return null;
        }
        panel.addEventListener('touchstart', function(e){
            if(window.innerWidth > 790 || e.touches.length !== 1) return;
            var sc = scroller(e.target);
            pending = !(sc && sc.scrollTop > 0); on = false; dy = 0;
            y0 = e.touches[0].clientY; t0 = Date.now();
        }, {passive:true});
        panel.addEventListener('touchmove', function(e){
            if(!pending) return;
            var d = e.touches[0].clientY - y0;
            if(!on){
                if(d > 6){ on = true; panel.style.transition = 'none'; if(ov) ov.style.transition = 'none'; }
                else if(d < -6){ pending = false; return; }
                else return;
            }
            dy = Math.max(0, d);
            panel.style.transform = 'translateY(' + dy + 'px)';
            if(ov) ov.style.opacity = String(Math.max(0, 1 - dy / (panel.offsetHeight || 400)));
            if(e.cancelable) e.preventDefault();
        }, {passive:false});
        function end(){
            if(!on){ pending = false; return; }
            on = false; pending = false;
            var v = dy / Math.max(1, Date.now() - t0), close = dy > (panel.offsetHeight || 400) * 0.3 || v > 0.6;
            panel.style.transition = ''; panel.style.transform = '';
            if(ov){ ov.style.transition = ''; ov.style.opacity = ''; }
            if(close) closeIndexSheet();
        }
        panel.addEventListener('touchend', end); panel.addEventListener('touchcancel', end);
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
function closeIndexSheet(){
    document.getElementById('indexSheetOverlay').classList.remove('show');
    document.getElementById('indexSheetPanel').classList.remove('show');
    document.body.style.overflow = '';
    setTimeout(function(){
        document.getElementById('indexSheetTrack').classList.remove('show-detail');
    }, 300);
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        var panel = document.getElementById('indexSheetPanel');
        if(!panel || !panel.classList.contains('show')) return;
        var track = document.getElementById('indexSheetTrack');
        if(track && track.classList.contains('show-detail')){ idxSheetBackToList(); }
        else { closeIndexSheet(); }
    }
});
</script>

<?php endif; ?>
<?php if (dz_sm_want('report')): ?>
<!-- ══════════════════════════════════════════════════════════
     REPORT MODAL (HOME) v4.1 — Report a User / Content
     Same look & behavior as the user report modal:
     Red theme · red icons & buttons with BLACK text
     Centered lane · grey side lines · horizontal-line rows
     Scrollbar: completely hidden on phones/tablets/narrow windows,
                slim red scrollbar on desktop only.
     Sub screens: X on the left — ALWAYS goes BACK to the main
                  category picker (screen 0). Only the X on screen 0
                  closes the modal.
     Open with:  openReportModal({userId: 123})
                 openReportModal({contentId: 45, contentType: 'meme'})
══════════════════════════════════════════════════════════ -->

<style>
#dzReportOverlay,
#dzRptStep1Screen,
#dzRptStep2Screen {
    --rp-red:#FF2D2D; --rp-red-h:#e01f1f; --rp-red-l:#ff6b6b;
    --rp-red-dim:rgba(255,45,45,.14); --rp-red-glow:rgba(255,45,45,.34);
    --rp-on-red:#000;
    --rp-card:rgba(255,255,255,.05);
    --rp-line:rgba(255,255,255,.09); --rp-line2:rgba(255,255,255,.14);
    --rp-sub:rgba(255,255,255,.62); --rp-mut:rgba(255,255,255,.45);
    --rp-pad:clamp(9.9px,3.3vw,17.6px);
    --rp-act-h:clamp(27.5px,7.92vw,33px);
    --rp-r:5.5px; --rp-fs:0.88rem; --rp-tb-h:59.4px;

    display:none; position:fixed; inset:0;
    background:linear-gradient(-45deg,#010218,#070924,#070924); background-attachment:fixed;
    justify-content:center; overflow:hidden;
    font-family:'Poppins',sans-serif; font-size:var(--rp-fs); color:#fff; -webkit-font-smoothing:antialiased;
    opacity:0;
}
#dzReportOverlay { z-index:999999999999; transform:translateY(19.8px); transition:opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1); }
#dzRptStep1Screen,
#dzRptStep2Screen { z-index:999999999998; transform:translateX(30.8px); transition:opacity .22s ease, transform .26s cubic-bezier(.22,1,.36,1); }

@media (max-width:490px){ #dzReportOverlay,#dzRptStep1Screen,#dzRptStep2Screen{ --rp-fs:0.81rem; --rp-tb-h:52.8px; } }
@media (max-width:390px){ #dzReportOverlay,#dzRptStep1Screen,#dzRptStep2Screen{ --rp-fs:0.77rem; --rp-tb-h:48.4px; } }

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
.dzrpt-topbar { flex-shrink:0; display:flex; align-items:center; gap:6.6px; min-height:var(--rp-tb-h); padding:env(safe-area-inset-top) 11px 0 var(--rp-pad); background:transparent; }
.dzrpt-topbar-center { flex:1; min-width:0; display:flex; flex-direction:column; text-align:left; }
.dzrpt-topbar-title { font-size:1.01rem; font-weight:700; color:#fff; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dzrpt-tb-side { flex:0 0 38px; display:flex; align-items:center; justify-content:flex-end; }
.dzrpt-close-btn { width:37.4px; height:37.4px; background:none; border:none; color:#fff; display:flex; align-items:center; justify-content:center; opacity:.9; padding:0; transition:.2s; flex-shrink:0; }
.dzrpt-close-btn:hover { color:var(--rp-red-l); opacity:1; }
.dzrpt-close-btn svg { width:24.2px; height:24.2px; display:block; }

/* sub screens: X on the LEFT, title after it */
.dzrpt-topbar.dzrpt-back-left { padding-left:8.8px; gap:8.8px; }

@media (max-width:490px){
    .dzrpt-topbar-title{ font-size:0.92rem; }
    .dzrpt-close-btn{ width:33px; height:33px; }
}
@media (max-width:390px){ .dzrpt-topbar-title{ font-size:0.88rem; } }

/* ═══════════════════════════════════════════════════════════
   SCROLL AREA + SCROLLBAR
   • default (phones / tablets / narrow windows): scrollbar does
     not exist at all — no track, no thumb, no gutter
   • desktop (wider than 800px): slim red scrollbar
═══════════════════════════════════════════════════════════ */
.dzrpt-body { flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; padding:0 0 19.8px; }

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
    #dzRptStep1Screen .dzrpt-body::-webkit-scrollbar { display:block; width:6px; }
    #dzReportOverlay .dzrpt-body::-webkit-scrollbar-track,
    #dzRptStep1Screen .dzrpt-body::-webkit-scrollbar-track { background:transparent; }
    #dzReportOverlay .dzrpt-body::-webkit-scrollbar-thumb,
    #dzRptStep1Screen .dzrpt-body::-webkit-scrollbar-thumb { background:#FF2D2D; border-radius:99px; }
    #dzReportOverlay .dzrpt-body::-webkit-scrollbar-thumb:hover,
    #dzRptStep1Screen .dzrpt-body::-webkit-scrollbar-thumb:hover { background:#e01f1f; }
}

.dzrpt-section-label {
    display:flex; align-items:center; gap:6.6px;
    padding:13.2px var(--rp-pad) 11px;
    font-size:0.7rem; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--rp-sub);
    border-top:1px solid var(--rp-line); border-bottom:1px solid var(--rp-line);
}

/* ── Category rows ── */
.dzrpt-cat-row {
    display:flex; align-items:center; gap:13.2px;
    margin:0; padding:14.3px var(--rp-pad);
    background:transparent; border:none; border-bottom:1px solid var(--rp-line);
    cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent;
}
.dzrpt-cat-row:hover { background:var(--rp-red-dim); }
.dzrpt-cat-row:active { background:rgba(255,45,45,.24); }
.dzrpt-cat-icon { width:26.4px; flex-shrink:0; text-align:center; font-size:22px; color:var(--rp-red); }
.dzrpt-cat-info { flex:1; min-width:0; }
.dzrpt-cat-label { font-size:0.9rem; font-weight:700; color:#fff; }
.dzrpt-cat-sub { font-size:0.73rem; color:var(--rp-mut); margin-top:2.2px; line-height:1.45; }
.dzrpt-cat-chevron { font-size:19.8px; color:var(--rp-mut); transition:transform .2s, color .2s; flex-shrink:0; }
.dzrpt-cat-row:hover .dzrpt-cat-chevron { transform:translateX(3.3px); color:var(--rp-red-l); }
@media (max-width:490px){
    .dzrpt-cat-row{ gap:11px; padding:13.2px var(--rp-pad); }
    .dzrpt-cat-icon{ font-size:19.8px; }
    .dzrpt-cat-label{ font-size:0.81rem; }
    .dzrpt-cat-sub{ font-size:0.68rem; }
}

/* ── User ID field ── */
.dzrpt-userid-wrap { padding:13.2px var(--rp-pad) 15.4px; }
.dzrpt-userid-label {
    display:flex; align-items:center; gap:6.6px; margin-bottom:8.8px;
    font-size:0.7rem; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--rp-sub);
}
.dzrpt-userid-label i { font-size:15.4px; color:var(--rp-red); }
.dzrpt-userid-label span { font-weight:400; text-transform:none; letter-spacing:0; color:var(--rp-mut); }
.dzrpt-userid-input {
    width:100%; height:33px; padding:0 11px;
    border-radius:var(--rp-r); border:1px solid var(--rp-line2);
    background:var(--rp-card); color:#fff;
    font-size:0.84rem; transition:.2s; -webkit-appearance:none; appearance:none;
}
.dzrpt-userid-input::placeholder { color:var(--rp-mut); }
.dzrpt-userid-input:focus { border-color:rgba(255,45,45,.85); box-shadow:0 0 0 3px rgba(255,45,45,.18); }
.dzrpt-userid-hint { font-size:0.68rem; color:var(--rp-mut); margin-top:6.6px; line-height:1.5; }
@media (max-width:490px){ .dzrpt-userid-input{ font-size:0.77rem; height:30.8px; } }

/* ── Reason rows ── */
.dzrpt-reason-row {
    display:flex; align-items:center; gap:13.2px;
    margin:0; padding:14.3px var(--rp-pad);
    background:transparent; border:none; border-bottom:1px solid var(--rp-line);
    cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent;
}
.dzrpt-reason-row:hover { background:rgba(255,45,45,.08); }
.dzrpt-reason-row.selected { background:var(--rp-red-dim); }
.dzrpt-reason-icon { font-size:19.8px; color:var(--rp-red); flex-shrink:0; width:26.4px; text-align:center; }
.dzrpt-reason-text { flex:1; min-width:0; font-size:0.88rem; font-weight:500; color:rgba(255,255,255,.88); }
.dzrpt-reason-row.selected .dzrpt-reason-text { color:#fff; font-weight:600; }
.dzrpt-check { width:22px; height:22px; border-radius:5.5px; border:1.5px solid rgba(255,255,255,.25); display:flex; align-items:center; justify-content:center; font-size:13.2px; font-weight:800; color:transparent; flex-shrink:0; transition:.2s; }
.dzrpt-reason-row.selected .dzrpt-check { background:var(--rp-red); border-color:var(--rp-red); color:var(--rp-on-red); }
@media (max-width:490px){
    .dzrpt-reason-row{ gap:11px; padding:13.2px var(--rp-pad); }
    .dzrpt-reason-icon{ font-size:17.6px; width:24.2px; }
    .dzrpt-reason-text{ font-size:0.79rem; }
    .dzrpt-check{ width:19.8px; height:19.8px; font-size:12.1px; }
}

/* ── Other textarea ── */
.dzrpt-other-wrap { padding:13.2px var(--rp-pad) 6.6px; display:none; }
.dzrpt-other-textarea {
    width:100%; min-height:105.6px; padding:9.9px 12.1px;
    border-radius:var(--rp-r); border:1px solid var(--rp-line2);
    background:var(--rp-card); color:#fff;
    font-size:0.9rem; line-height:1.6; resize:none; transition:.2s;
    -webkit-appearance:none; appearance:none; overflow-y:auto;
}
.dzrpt-other-textarea::placeholder { color:var(--rp-mut); }
.dzrpt-other-textarea:focus { border-color:rgba(255,45,45,.85); box-shadow:0 0 0 3px rgba(255,45,45,.18); }
.dzrpt-char-count { text-align:right; font-size:0.68rem; color:var(--rp-mut); margin-top:5.5px; }
@media (max-width:490px){ .dzrpt-other-textarea{ font-size:0.84rem; } }

/* ── Buttons (red, BLACK text) ── */
.dzrpt-footer {
    flex-shrink:0; display:flex; gap:8.8px;
    padding:8.8px var(--rp-pad) calc(11px + env(safe-area-inset-bottom));
    border-top:1px solid var(--rp-line);
}
.dzrpt-submit-btn, .dzrpt-cancel-btn, .dzrpt-done-btn {
    display:flex; align-items:center; justify-content:center; gap:6.6px;
    min-width:0; height:var(--rp-act-h); padding:0 15.4px;
    border:none; border-radius:var(--rp-r);
    font-size:0.87rem; font-weight:700; line-height:1; white-space:nowrap;
    transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.dzrpt-submit-btn { flex:1.4; order:2; background:var(--rp-red); color:var(--rp-on-red); }
.dzrpt-submit-btn:hover, .dzrpt-done-btn:hover { background:var(--rp-red-h); box-shadow:0 4px 16px var(--rp-red-glow); }
.dzrpt-submit-btn:active, .dzrpt-done-btn:active { transform:scale(.98); }
.dzrpt-submit-btn:disabled { opacity:.5; cursor:not-allowed; box-shadow:none; pointer-events:none; }
.dzrpt-cancel-btn { flex:1; order:1; background:rgba(255,255,255,.1); color:#fff; backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
.dzrpt-cancel-btn:hover { background:rgba(255,255,255,.2); }
.dzrpt-done-btn { background:var(--rp-red); color:var(--rp-on-red); padding:0 44px; }
@media (max-width:490px){
    .dzrpt-submit-btn, .dzrpt-cancel-btn, .dzrpt-done-btn{ font-size:0.75rem; padding:0 11px; }
    .dzrpt-done-btn{ padding:0 35.2px; }
}

/* ── Success ── */
.dzrpt-success-body { flex:1; min-height:0; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:33px 26.4px 66px; text-align:center; }
.dzrpt-success-icon { width:70.4px; height:70.4px; border-radius:50%; background:rgba(77,222,138,.12); border:1px solid rgba(77,222,138,.4); display:flex; align-items:center; justify-content:center; margin:0 auto 17.6px; font-size:30.8px; color:#4dde8a; }
.dzrpt-success-title { font-size:1.04rem; font-weight:700; color:#fff; margin-bottom:8.8px; }
.dzrpt-success-desc { font-size:0.81rem; color:var(--rp-sub); line-height:1.7; max-width:319px; margin:0 auto 24.2px; }
@media (max-width:490px){
    .dzrpt-success-icon{ width:61.6px; height:61.6px; font-size:27.5px; }
    .dzrpt-success-title{ font-size:0.95rem; }
    .dzrpt-success-desc{ font-size:0.75rem; }
}

/* ── Toast ── */
.dzrpt-toast {
    position:fixed; bottom:calc(33px + env(safe-area-inset-bottom)); left:50%;
    transform:translateX(-50%) translateY(11px);
    background:#0d1030; border:1px solid rgba(255,45,45,.4);
    color:#fff; padding:8.8px 17.6px; border-radius:5.5px;
    font-size:0.79rem; font-family:'Poppins',sans-serif; font-weight:500;
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
<style>
/* ═══ LIGHT MODE for the menu, save and report modals — only when <html data-theme="light"> (dark is untouched) ═══ */
[data-theme="light"] .svsh-overlay,[data-theme="light"] .idx-sheet-overlay,[data-theme="light"] .dzc-overlay{ background:rgba(20,24,40,.38); }
/* the sheets themselves follow the theme variables (see #dzShDzmSave / #dzShDzmIdx) */
/* v11: why-list + undo bar */
[data-theme="light"] .idx-undo-bar{ background:#fff; color:#14171f; border-color:rgba(15,20,40,.16); box-shadow:0 8px 24px rgba(15,20,40,.2); }
[data-theme="light"] .idx-undo-bar button{ color:#fff; }

/* report modal (3 screens) */
[data-theme="light"] #dzReportOverlay,[data-theme="light"] #dzRptStep1Screen,[data-theme="light"] #dzRptStep2Screen{
    --rp-red-dim:rgba(255,45,45,.09); --rp-on-red:#fff; --rp-card:rgba(15,20,40,.045);
    --rp-line:rgba(15,20,40,.09); --rp-line2:rgba(15,20,40,.16);
    --rp-sub:rgba(20,23,31,.65); --rp-mut:rgba(20,23,31,.5);
    background:#f4f6fb; color:#14171f;
}
[data-theme="light"] .dzrpt-topbar-title,[data-theme="light"] .dzrpt-close-btn,[data-theme="light"] .dzrpt-cat-label,[data-theme="light"] .dzrpt-userid-input,
[data-theme="light"] .dzrpt-reason-text,[data-theme="light"] .dzrpt-reason-row.selected .dzrpt-reason-text,[data-theme="light"] .dzrpt-success-title,
[data-theme="light"] #dzRptStep1Screen textarea,[data-theme="light"] #dzRptStep1Screen input,[data-theme="light"] #dzRptStep2Screen textarea{ color:#14171f; }
[data-theme="light"] .dzrpt-cat-row:active{ background:rgba(255,45,45,.16); }
[data-theme="light"] .dzrpt-cancel-btn{ background:rgba(15,20,40,.08); color:#14171f; }
[data-theme="light"] .dzrpt-cancel-btn:hover{ background:rgba(15,20,40,.14); }
[data-theme="light"] .dzrpt-success-icon{ background:rgba(30,160,90,.1); border-color:rgba(30,160,90,.4); }
[data-theme="light"] .dzrpt-toast{ background:#fff; color:#14171f; box-shadow:0 8px 28px rgba(15,20,40,.2); }
</style>


<!-- ══ SCREEN 0: Category picker ══ -->
<div id="dzReportOverlay" role="dialog" aria-modal="true" aria-label="Report">
  <div class="dzrpt-lane">
    <div class="dzrpt-topbar">
        <div class="dzrpt-topbar-center">
            <div class="dzrpt-topbar-title">Report</div>
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
        <div class="dzrpt-userid-wrap" id="dzRptUserIdWrap" style="display:none;">
            <div class="dzrpt-userid-label">
                <i class="ri-fingerprint-line"></i>
                User ID <span>&nbsp;— optional</span>
            </div>
            <input class="dzrpt-userid-input" type="text" id="dzRptUserIdInput"
                   placeholder="e.g. DZ-10E34AFAD or user #123">
            <div class="dzrpt-userid-hint">Find this on the user's profile page</div>
        </div>

        <!-- Post ID — only shown for post / content reports -->
        <div class="dzrpt-userid-wrap" id="dzRptPostIdWrap" style="display:none;">
            <div class="dzrpt-userid-label">
                <i class="ri-fingerprint-line"></i>
                Post ID <span>&nbsp;— optional</span>
            </div>
            <input class="dzrpt-userid-input" type="text" id="dzRptPostIdInput" maxlength="24" autocomplete="off" autocapitalize="characters"
                   placeholder="e.g. TP-7K3Q-9M2X-4H8W-2R5N">
            <div class="dzrpt-userid-hint">Find this at the bottom of the post's ⋯ menu</div>
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


<script>
(function () {
    var _rState = {
        step: 0, category: null,
        targetId: null, contentType: null,
        ownerId: null, reason: null
    };

    /* ── Reason lists — every item has an icon ── */
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
            _rState = { step:0, category:null, targetId:null, contentType:null, ownerId:null, reason:null };
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
        _rState.targetId    = opts.userId || opts.contentId || null;
        _rState.contentType = opts.contentType || null;
        _rState.ownerId     = opts.ownerId || null;

        if (opts.userId && !opts.skipCat) {
            _rState.category = 'user';
            _rState.targetId = opts.userId;
            _buildReasonScreen('user');
            _openScreen('dzRptStep1Screen');
        } else if (opts.contentId) {
            /* opened directly against a piece of content (e.g. kebab sheet "Report") */
            _rState.category    = 'content';
            _rState.targetId    = opts.contentId;
            _rState.contentType = opts.contentType || 'meme';
            _buildReasonScreen('content');
            _openScreen('dzRptStep1Screen');
        } else {
            _openScreen('dzReportOverlay');
        }
    };

    window.reportUser = function (profileId) {
        openReportModal({ userId: profileId });
    };

    window.closeReportModal = function () { _closeAll(); };

    window.dzRptSelectCat = function (cat) {
        _rState.category = cat;
        /* if "Reporting Content" was picked from the generic category screen
           (no contentId/contentType supplied up front), default to meme so
           report_content.php always receives a valid content_type */
        if (cat === 'content' && !_rState.contentType) {
            _rState.contentType = 'meme';
        }
        _buildReasonScreen(cat);
        _closeScreen('dzReportOverlay');
        setTimeout(function () { _openScreen('dzRptStep1Screen'); }, 80);
    };

    /* X on the reason screen: ALWAYS go back to the main picker (screen 0),
       even when the modal was opened straight to the reason screen.
       Only the X on screen 0 (and Cancel / Done) closes the modal. */
    window.dzRptBack = function () {
        _rState.reason = null;
        _closeScreen('dzRptStep1Screen');
        setTimeout(function () { _openScreen('dzReportOverlay'); }, 80);
    };

    function _buildReasonScreen(cat) {
        var reasons = cat === 'user' ? _userReasons : _contentReasons;
        var titles = {
            user:    'Why are you reporting this user?',
            content: 'Why are you reporting this content?'
        };
        document.getElementById('dzRptTitle').textContent = titles[cat] || 'Select a reason';

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
        var endpoint;

        if (cat === 'user') {
            endpoint = 'report_user.php';
            fd.append('type', 'user');
            fd.append('reported_id', _rState.targetId);
            var uid = (document.getElementById('dzRptUserIdInput').value || '').trim();
            if (uid) fd.append('user_id_hint', uid);
        } else {
            endpoint = 'report_content.php';
            fd.append('reported_id', _rState.targetId);
            fd.append('content_type', _rState.contentType || 'meme');
        }
        fd.append('reason', _rState.reason);
        var desc = (document.getElementById('dzRptOtherInput').value || '').trim();
        if (cat !== 'user'){
            var postHint = ((document.getElementById('dzRptPostIdInput') || {}).value || '').trim().toUpperCase();
            if (postHint){
                fd.append('post_id_hint', postHint);
                /* also written into the description so admins see it even where report_content.php doesn't read post_id_hint */
                desc = ('Post ID: ' + postHint + (desc ? ' — ' + desc : '')).slice(0, 500);
            }
        }
        if (desc) fd.append('description', desc);

        fetch(endpoint, { method: 'POST', body: fd })
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
<?php endif; ?>
<!-- ══ "Are you interested in this post?" — home + text feed (the zeems modal has its own version) ══ -->
<style>
.dzask{
    margin:8px 6px 4px; padding:10px 0 10px; border-radius:10px; position:relative;
    background:transparent; color:var(--ui-text, #fff);
    border:0;
    font-family:Poppins,sans-serif; opacity:0; transform:translateY(6px);
    transition:opacity .25s ease, transform .3s ease;
}
.dzask.show{ opacity:1; transform:none; }
.dzask-q{ font-size:12.5px; font-weight:600; line-height:1.3; margin-bottom:8px; color:var(--ui-text, #fff); }
.dzask-btns{ display:flex; gap:8px; }
.dzask-btns button{
    flex:1 1 0; min-width:0; height:32px; border-radius:5px; cursor:pointer; font-family:inherit; font-size:12.5px; font-weight:600;
    display:flex; align-items:center; justify-content:center; gap:5px; color:var(--ui-text, #fff);
    background:rgba(var(--fg, 255,255,255), .07); border:1px solid rgba(var(--fg, 255,255,255), .12);
    -webkit-tap-highlight-color:transparent; transition:transform .12s ease, opacity .12s ease;
}
.dzask-btns button:active{ transform:scale(.96); opacity:.75; }
.dzask-btns i{ font-size:14px; line-height:1; }
.dzask-btns .dzask-yes i{ color:var(--red, #FF2D2D); }
.dzask-thanks{ font-size:12.5px; font-weight:600; text-align:center; padding:6px 0; }
</style>
<script>
(function(){
    if(window.__dzAskInit) return; window.__dzAskInit = true;
    var page = (location.pathname.split('/').pop() || '').toLowerCase();
    if(!(window.DZ_INTEREST_ASK === true || page === 'home.php' || page === 'text_feed.php')) return;

    var KEY = 'dz_ask_done', done = {}, lastAt = 0, seen = {}, open = null, dwell = { id:null, t:0 };
    try{ done = JSON.parse(sessionStorage.getItem(KEY) || '{}') || {}; }catch(e){}
    function remember(id){ done[id] = 1; try{ sessionStorage.setItem(KEY, JSON.stringify(done)); }catch(e){} }

    function close(el, thenRemove){
        if(!el) return;
        el.classList.remove('show');
        setTimeout(function(){ if(el.parentNode) el.parentNode.removeChild(el); }, 300);
        if(open === el) open = null;
        lastAt = Date.now();
    }
    function show(post){
        var id = post.getAttribute('data-id'); if(!id) return;
        remember(id);
        var el = document.createElement('div');
        el.className = 'dzask'; el.setAttribute('data-ask', id);
        el.innerHTML =
            '<div class="dzask-q">Are you interested in this post?</div>'+
            '<div class="dzask-btns">'+
                '<button type="button" class="dzask-no"><i class="ri-close-line"></i> Not interested</button>'+
                '<button type="button" class="dzask-yes"><i class="ri-check-line"></i> Interested</button>'+
            '</div>';
        post.appendChild(el);
        open = el; lastAt = Date.now();
        requestAnimationFrame(function(){ requestAnimationFrame(function(){ el.classList.add('show'); }); });
        el.addEventListener('click', function(e){ e.stopPropagation(); });
        el.querySelector('.dzask-no').addEventListener('click', function(){
            close(el);
            if(typeof window.notInterestedFromSheet === 'function') window.notInterestedFromSheet(id);
        });
        el.querySelector('.dzask-yes').addEventListener('click', function(){
            if(typeof window.dzInterested === 'function') window.dzInterested(id);
            el.innerHTML = '<div class="dzask-thanks">' + (post.querySelector('.ipv-ad-tag') ? 'We will suggest more ads like this for you' : 'We will suggest more posts like this for you') + '</div>';
            setTimeout(function(){ close(el); }, 2000);
        });
        /* no timer and no X: the card stays on the post until Interested or Not interested is pressed (scroll away and back, it is still there) */
    }

    /* once in a while: a suggested post that has been on screen for a few seconds, never twice for the same post */
    setInterval(function(){
        if(document.hidden || open) return;
        if(Date.now() - lastAt < 45000) return;                 /* at least 45 s between two questions */
        var posts = document.querySelectorAll('.ipv-post[data-suggested="1"]'), vh = window.innerHeight, best = null, bestV = 0;
        for(var i = 0; i < posts.length; i++){
            var r = posts[i].getBoundingClientRect(), vis = Math.min(r.bottom, vh) - Math.max(r.top, 0);
            var frac = r.height ? vis / Math.min(r.height, vh) : 0;
            if(frac > bestV){ bestV = frac; best = posts[i]; }
        }
        if(!best || bestV < .7){ dwell = { id:null, t:0 }; return; }
        var id = best.getAttribute('data-id');
        if(done[id] || best.querySelector('.dzask')){ return; }
        if(dwell.id !== id){ dwell = { id:id, t:0 }; if(!(id in seen)) seen[id] = Math.random() < .4; }   /* decided once per post: roughly 2 of 5 */
        dwell.t += 1;
        if(dwell.t >= 3 && seen[id]){ dwell = { id:null, t:0 }; show(best); }
    }, 1000);
})();
</script>
<?php if ($dzStandalone): ?>
</body>
</html>
<?php endif; ?>
<script>
/* haptic tap for every row inside the ⋯ post menu (Interested, Not interested, Report, Copy link …) */
document.addEventListener('click', function(e){
    try{ if(window.dzHaptic && e.target.closest && e.target.closest('#indexSheetList') && e.target.closest('button, a, [onclick]')) dzHaptic.light(); }catch(x){}
}, true);
</script>