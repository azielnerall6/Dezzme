<?php if (!isset($conn)) { http_response_code(403); exit; } ?>
<!-- Group Pic Modal -->
<style>
.group-pic-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: #000;
    align-items: center;
    justify-content: center;
    padding: calc(20px * var(--md-s));
    box-sizing: border-box;
}

.group-pic-modal.active {
    display: flex;
}

.group-pic-modal-content {
    display: flex;
    align-items: center;
    justify-content: center;
    max-width: 100%;
    max-height: 100%;
}

.group-pic-modal-content img {
    display: block;
    max-width: 100%;
    max-height: calc(100vh - calc(40px * var(--md-s)));
    max-height: calc(100dvh - calc(40px * var(--md-s)));
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: calc(6px * var(--md-s));
}

.group-pic-modal-close {
    position: absolute;
    top: calc(14px * var(--md-s));
    right: calc(18px * var(--md-s));
    z-index: 10000;
    background: none;
    border: none;
    padding: 0;
    color: var(--text,#F0F2FF);
    font-size: calc(40px * var(--md-s));
    line-height: 1;
    cursor: pointer;
    opacity: 0.8;
    transition: opacity 0.2s;
}

.group-pic-modal-close:hover {
    opacity: 1;
}
</style>

<div id="groupPicModal" class="group-pic-modal" onclick="closeGroupPicModal()">
    <button class="group-pic-modal-close" onclick="event.stopPropagation(); closeGroupPicModal()">&times;</button>
    <div class="group-pic-modal-content" onclick="event.stopPropagation()">
        <img id="groupPicModalImg" src="<?php echo htmlspecialchars($img_src); ?>"
             onerror="this.onerror=null; this.src='uploads/default_group.png';" alt="Group Picture">
    </div>
</div>

<script>
function openGroupPicModal() {
    var modal = document.getElementById('groupPicModal');
    if (!modal) return;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeGroupPicModal() {
    var modal = document.getElementById('groupPicModal');
    if (!modal) return;
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// Close with the Escape key
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeGroupPicModal();
});
</script>

<!-- ══════════════════════════════════════════════════════
     GROUP OPTIONS MODAL  (desktop = centered modal,
     mobile = bottom sheet)
══════════════════════════════════════════════════════ -->
<style>
#groupOptionsOverlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999999999;
    background: rgba(0, 0, 0, 0.72);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    align-items: center;
    justify-content: center;
    padding: calc(20px * var(--md-s));
    font-family: 'Poppins', sans-serif;
}
#groupOptionsOverlay.go-open { display: flex; }

/* ── desktop card ── */
.go-card {
    background: linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-start,#010218),var(--bg-start,#010218));
    border: 1px solid rgba(var(--fg,255,255,255),0.09);
    border-radius: calc(6px * var(--md-s)); /* CHANGED: was 25px — now sharp but still slightly rounded */
    width: 100%;
    max-width: 340px;
    overflow: hidden;
    box-shadow: 0 28px 70px rgba(0,0,0,0.7);
    animation: goCardIn 0.25s cubic-bezier(0.22,1,0.36,1);
    flex-shrink: 0;
}
@keyframes goCardIn {
    from { opacity:0; transform: scale(0.93) translateY(10px); }
    to   { opacity:1; transform: scale(1)    translateY(0); }
}

/* ── header ── */
.go-header {
    display: flex;
    align-items: center;
    gap: calc(10px * var(--md-s));
    padding: calc(14px * var(--md-s)) calc(16px * var(--md-s)) calc(11px * var(--md-s));
    border-bottom: 1px solid rgba(var(--fg,255,255,255),0.07);
}
.go-group-avatar {
    width: calc(36px * var(--md-s));
    height: calc(36px * var(--md-s));
    border-radius: calc(8px * var(--md-s));
    object-fit: cover;
    border: 2px solid #1a6fd4;
    flex-shrink: 0;
}
.go-group-name {
    font-size: calc(12px * var(--md-s));
    font-weight: 700;
    color: var(--text,#F0F2FF);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.go-group-members {
    font-size: calc(9.5px * var(--md-s));
    color: rgba(var(--fg,255,255,255),0.38);
    margin-top: 1px;
}
.go-close-btn {
    margin-left: auto;
    background: none;
    border: none;
    color: rgba(var(--fg,255,255,255),0.45);
    font-size: calc(11px * var(--md-s));
    font-family: 'Poppins', sans-serif;
    font-weight: 500;
    cursor: pointer;
    flex-shrink: 0;
    padding: calc(3px * var(--md-s)) calc(6px * var(--md-s));
    letter-spacing: 0.01em;
    transition: 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.go-close-btn:hover { color: var(--text,#F0F2FF); }

/* ── option rows ── */
.go-option {
    display: flex;
    align-items: center;
    gap: calc(11px * var(--md-s));
    padding: calc(11px * var(--md-s)) calc(16px * var(--md-s));
    cursor: pointer;
    border-bottom: 1px solid rgba(var(--fg,255,255,255),0.05);
    transition: background 0.15s;
    -webkit-tap-highlight-color: transparent;
    position: relative;
}
.go-option:last-child { border-bottom: none; }
.go-option:hover  { background: rgba(var(--fg,255,255,255),0.04); }
.go-option:active { background: rgba(var(--fg,255,255,255),0.08); }

.go-option-icon {
    width: calc(32px * var(--md-s)); height: calc(32px * var(--md-s));
    border-radius: calc(8px * var(--md-s));
    display: flex; align-items: center; justify-content: center;
    font-size: calc(14px * var(--md-s));
    flex-shrink: 0;
}
.go-option-icon.blue    { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.red     { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.orange  { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.muted   { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.purple  { background: rgba(6,115,240,0.12); color: #4dabff; }

.go-option-text { flex: 1; min-width: 0; }
.go-option-title {
    font-size: calc(11.5px * var(--md-s));
    font-weight: 600;
    color: rgba(var(--fg,255,255,255),0.88);
    line-height: 1.2;
}
.go-option-title.danger { color: #ff6b6b; }
.go-option-sub {
    font-size: calc(9.5px * var(--md-s));
    color: rgba(var(--fg,255,255,255),0.32);
    margin-top: 2px;
    line-height: 1.35;
}
.go-option-arrow {
    color: rgba(var(--fg,255,255,255),0.2);
    font-size: calc(15px * var(--md-s));
    flex-shrink: 0;
}

/* ── toggle switch ── */
.go-toggle-wrap {
    display: flex;
    align-items: center;
    flex-shrink: 0;
}
.go-toggle {
    position: relative;
    width: calc(36px * var(--md-s));
    height: calc(20px * var(--md-s));
    cursor: pointer;
}
.go-toggle input { opacity: 0; width: 0; height: 0; }
.go-toggle-slider {
    position: absolute;
    inset: 0;
    background: rgba(var(--fg,255,255,255),0.12);
    border-radius: calc(20px * var(--md-s));
    transition: 0.28s;
    border: 1px solid rgba(var(--fg,255,255,255),0.1);
}
.go-toggle-slider::before {
    content: '';
    position: absolute;
    top: 2px; left: 2px;
    width: calc(14px * var(--md-s)); height: calc(14px * var(--md-s));
    background: #000; /* CHANGED: black circle (was white) */
    border-radius: calc(20px * var(--md-s));
    transition: 0.28s;
}
.go-toggle input:checked + .go-toggle-slider { background: rgba(26,109,212,0.85); border-color: rgba(26,109,212,0.5); }
.go-toggle input:checked + .go-toggle-slider::before { transform: translateX(16px); background: #000; } /* CHANGED: stays black when on */

/* ── divider ── */
.go-divider {
    border: none;
    border-top: 1px solid rgba(var(--fg,255,255,255),0.07);
    margin: calc(3px * var(--md-s)) 0;
}

/* ══ MOBILE — bottom sheet, sharp corners ══ */
@media (max-width: 790px) {
    #groupOptionsOverlay {
        align-items: flex-end;
        justify-content: center;
        padding: 0;
    }

    .go-card {
        border-radius: 0;
        max-width: 100%;
        width: 100%;
        animation: goSheetIn 0.3s cubic-bezier(0.22,1,0.36,1);
        border-bottom: none;
    }
    @keyframes goSheetIn {
        from { transform: translateY(100%); opacity: 0.6; }
        to   { transform: translateY(0);    opacity: 1; }
    }

    /* drag handle */
    .go-card::before {
        content: '';
        display: block;
        width: calc(30px * var(--md-s)); height: calc(3px * var(--md-s));
        background: rgba(var(--fg,255,255,255),0.18);
        border-radius: 0;
        margin: calc(8px * var(--md-s)) auto 0;
    }

    .go-header { padding: calc(11px * var(--md-s)) calc(14px * var(--md-s)) calc(9px * var(--md-s)); }
    .go-group-avatar { width: calc(32px * var(--md-s)); height: calc(32px * var(--md-s)); border-radius: calc(7px * var(--md-s)); }
    .go-group-name   { font-size: calc(11.5px * var(--md-s)); }
    .go-group-members{ font-size: calc(9px * var(--md-s)); }

    .go-option { padding: calc(10px * var(--md-s)) calc(14px * var(--md-s)); gap: calc(10px * var(--md-s)); }
    .go-option-icon { width: calc(30px * var(--md-s)); height: calc(30px * var(--md-s)); border-radius: calc(7px * var(--md-s)); font-size: calc(13px * var(--md-s)); }
    .go-option-title { font-size: calc(11px * var(--md-s)); }
    .go-option-sub   { font-size: calc(9px * var(--md-s)); }
}

@media (max-width: 490px) {
    .go-header { padding: calc(12px * var(--md-s)) calc(14px * var(--md-s)) calc(10px * var(--md-s)); }
    .go-group-avatar { width: calc(34px * var(--md-s)); height: calc(34px * var(--md-s)); border-radius: calc(7px * var(--md-s)); }
    .go-group-name   { font-size: calc(12.5px * var(--md-s)); }
    .go-group-members{ font-size: calc(9.5px * var(--md-s)); }

    .go-option { padding: calc(11px * var(--md-s)) calc(14px * var(--md-s)); gap: calc(10px * var(--md-s)); }
    .go-option-icon  { width: calc(31px * var(--md-s)); height: calc(31px * var(--md-s)); border-radius: calc(7px * var(--md-s)); font-size: calc(14px * var(--md-s)); }
    .go-option-title { font-size: calc(12px * var(--md-s)); }
    .go-option-sub   { font-size: calc(9.5px * var(--md-s)); }
    .go-option-arrow { font-size: calc(15px * var(--md-s)); }

    .go-toggle { width: calc(36px * var(--md-s)); height: calc(20px * var(--md-s)); }
    .go-toggle-slider::before { width: calc(14px * var(--md-s)); height: calc(14px * var(--md-s)); }
    .go-toggle input:checked + .go-toggle-slider::before { transform: translateX(16px); }
}

@media (max-width: 390px) {
    .go-header { padding: calc(11px * var(--md-s)) calc(12px * var(--md-s)) calc(9px * var(--md-s)); gap: calc(9px * var(--md-s)); }
    .go-group-avatar { width: calc(31px * var(--md-s)); height: calc(31px * var(--md-s)); border-radius: calc(7px * var(--md-s)); }
    .go-group-name   { font-size: calc(11.5px * var(--md-s)); }
    .go-group-members{ font-size: calc(9px * var(--md-s)); }
    .go-close-btn    { font-size: calc(11px * var(--md-s)); padding: calc(3px * var(--md-s)) calc(6px * var(--md-s)); }

    .go-option { padding: calc(10px * var(--md-s)) calc(12px * var(--md-s)); gap: calc(9px * var(--md-s)); }
    .go-option-icon  { width: calc(29px * var(--md-s)); height: calc(29px * var(--md-s)); border-radius: calc(6px * var(--md-s)); font-size: calc(13px * var(--md-s)); }
    .go-option-title { font-size: calc(11.5px * var(--md-s)); }
    .go-option-sub   { font-size: calc(9px * var(--md-s)); margin-top: 1px; }
    .go-option-arrow { font-size: calc(14px * var(--md-s)); }

    .go-toggle { width: calc(34px * var(--md-s)); height: calc(19px * var(--md-s)); }
    .go-toggle-slider::before { width: calc(13px * var(--md-s)); height: calc(13px * var(--md-s)); top: 2px; left: 2px; }
    .go-toggle input:checked + .go-toggle-slider::before { transform: translateX(15px); }

    .go-card::before { width: calc(28px * var(--md-s)); height: calc(3px * var(--md-s)); margin: calc(8px * var(--md-s)) auto 0; }
}

@media (max-width: 330px) {
    .go-header { padding: calc(10px * var(--md-s)) calc(11px * var(--md-s)) calc(8px * var(--md-s)); gap: calc(8px * var(--md-s)); }
    .go-group-avatar { width: calc(27px * var(--md-s)); height: calc(27px * var(--md-s)); border-radius: calc(6px * var(--md-s)); border-width: 1.5px; }
    .go-group-name   { font-size: calc(10.5px * var(--md-s)); }
    .go-group-members{ font-size: calc(8.5px * var(--md-s)); }
    .go-close-btn    { font-size: calc(10px * var(--md-s)); padding: calc(3px * var(--md-s)) calc(5px * var(--md-s)); }

    .go-option { padding: calc(9px * var(--md-s)) calc(11px * var(--md-s)); gap: calc(8px * var(--md-s)); }
    .go-option-icon  { width: calc(27px * var(--md-s)); height: calc(27px * var(--md-s)); border-radius: calc(6px * var(--md-s)); font-size: calc(12px * var(--md-s)); }
    .go-option-title { font-size: calc(10.5px * var(--md-s)); }
    .go-option-sub   { font-size: calc(8.5px * var(--md-s)); }
    .go-option-arrow { font-size: calc(13px * var(--md-s)); }

    .go-toggle { width: calc(31px * var(--md-s)); height: calc(17px * var(--md-s)); }
    .go-toggle-slider::before { width: calc(11px * var(--md-s)); height: calc(11px * var(--md-s)); }
    .go-toggle input:checked + .go-toggle-slider::before { transform: translateX(14px); }

    .go-card::before { width: calc(25px * var(--md-s)); height: calc(2.5px * var(--md-s)); margin: calc(7px * var(--md-s)) auto 0; }
}
</style>

<!-- ══ GROUP OPTIONS MODAL ══ -->
<div id="groupOptionsOverlay" onclick="if(event.target===this) closeGroupOptionsModal()">
    <div class="go-card">

        <!-- Header -->
        <div class="go-header">
            <img class="go-group-avatar"
                 src="<?php echo htmlspecialchars($img_src); ?>"
                 onerror="this.src='uploads/default_group.png'" alt="">
            <div style="flex:1;min-width:0;">
                <div class="go-group-name"><?php echo htmlspecialchars($group['title']); ?></div>
                <div class="go-group-members">
                    <i class="ri-team-line" style="font-size:11px;"></i>
                    <?php echo number_format($member_count); ?> members
                </div>
            </div>
            <button class="go-close-btn" onclick="closeGroupOptionsModal()" aria-label="Close">Close</button>
        </div>

        <!-- Mute group -->
        <div class="go-option" onclick="goToggleMute()">
            <div class="go-option-icon orange">
                <i class="ri-volume-mute-line"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title" id="goMuteTitle">
                    Mute <?php echo htmlspecialchars($group['title']); ?>
                </div>
                <div class="go-option-sub" id="goMuteSub">Silence all posts and activity from this pod</div>
            </div>
            <div class="go-toggle-wrap" onclick="event.stopPropagation()">
                <label class="go-toggle" aria-label="Mute group">
                    <input type="checkbox" id="goMuteToggle" onchange="goToggleMute()">
                    <span class="go-toggle-slider"></span>
                </label>
            </div>
        </div>

        <!-- Notifications -->
        <div class="go-option" onclick="goToggleNotifs()">
            <div class="go-option-icon blue">
                <i class="ri-notification-off-line" id="goNotifIcon"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title" id="goNotifTitle">Turn off This Pod notifications</div>
                <div class="go-option-sub" id="goNotifSub">Stop receiving any alerts from <?php echo htmlspecialchars($group['title']); ?></div>
            </div>
            <div class="go-toggle-wrap" onclick="event.stopPropagation()">
                <label class="go-toggle" aria-label="Group notifications">
                    <input type="checkbox" id="goNotifToggle" onchange="goToggleNotifs()">
                    <span class="go-toggle-slider"></span>
                </label>
            </div>
        </div>

        <hr class="go-divider">

        <!-- Share -->
        <div class="go-option" onclick="goShareGroup()">
            <div class="go-option-icon purple">
                <i class="ri-share-line"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title">Share this pod</div>
                <div class="go-option-sub">Invite others by sharing a link</div>
            </div>
            <i class="ri-arrow-right-s-line go-option-arrow"></i>
        </div>

        <!-- Copy link -->
        <div class="go-option" onclick="goCopyLink()">
            <div class="go-option-icon muted">
                <i class="ri-links-line"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title" id="goCopyLinkTitle">Copy pod link</div>
                <div class="go-option-sub">Share the direct link to this pod</div>
            </div>
            <i class="ri-arrow-right-s-line go-option-arrow"></i>
        </div>

        <hr class="go-divider">

        <!-- Report (only for non-owners) -->
        <?php if (!$is_owner): ?>
        <div class="go-option" onclick="closeGroupOptionsModal(); setTimeout(()=>openReportGroupModal(<?php echo (int)$group_id; ?>), 220)">
            <div class="go-option-icon red">
                <i class="ri-flag-line"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title danger">Report this pod</div>
                <div class="go-option-sub">Flag this group for review</div>
            </div>
            <i class="ri-arrow-right-s-line go-option-arrow"></i>
        </div>
        <?php endif; ?>

        <!-- Leave (only for members who aren't owner) -->
        <?php if ($is_member && !$is_owner): ?>
        <div class="go-option" onclick="goLeaveGroup()">
            <div class="go-option-icon red">
                <i class="ri-logout-box-line"></i>
            </div>
            <div class="go-option-text">
                <div class="go-option-title danger">Leave pod</div>
                <div class="go-option-sub">You can rejoin at any time</div>
            </div>
            <i class="ri-arrow-right-s-line go-option-arrow"></i>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ══ TOAST for copy feedback ══ -->
<div id="goToast" style="
    display:none;
    position:fixed;
    bottom:90px;
    left:50%;
    transform:translateX(-50%) translateY(8px);
    background:rgba(10,10,28,0.97);
    border:1px solid rgba(255,255,255,0.1);
    color:#fff;
    padding:9px 20px;
    border-radius:50px;
    font-size:12.5px;
    font-family:'Poppins',sans-serif;
    font-weight:500;
    z-index:9999999999;
    white-space:nowrap;
    box-shadow:0 8px 28px rgba(0,0,0,0.6);
    pointer-events:none;
    opacity:0;
    transition:opacity 0.22s ease, transform 0.22s ease;
"></div>

<script>/* lazy start: this block's code loads the first time it is used */
window.dzLazyJs=window.dzLazyJs||function(id,names,eager){var done=false;function run(){if(done)return;done=true;var t=document.getElementById(id);if(!t)return;var f=t.content.cloneNode(true),ss=[].slice.call(f.querySelectorAll('script'));ss.forEach(function(s){s.parentNode.removeChild(s)});ss.forEach(function(o){var s=document.createElement('script');s.text=o.textContent;document.body.appendChild(s)})}names.forEach(function(n){var s=function(){run();var r=window[n];if(r&&r!==s)return r.apply(this,arguments)};s.__dzStub=1;window[n]=s});window['dzLazyRun_'+id]=run;if(eager){var go=function(){run();['pointerdown','keydown','touchstart'].forEach(function(e){document.removeEventListener(e,go,true)})};['pointerdown','keydown','touchstart'].forEach(function(e){document.addEventListener(e,go,{capture:true,passive:true})});var idle=function(){(window.requestIdleCallback||function(c){setTimeout(c,1200)})(run,{timeout:3000})};if(document.readyState==='complete')idle();else window.addEventListener('load',idle)}};
dzLazyJs('dzLzGOpt',['closeGroupOptionsModal','goCopyLink','goLeaveGroup','goShareGroup','goToggleMute','goToggleNotifs','openGroupOptionsModal'],false);
</script>
<template id="dzLzGOpt">
<script>
/* ══ GROUP OPTIONS MODAL ══ */
var _goOpen      = false;
var _goMuted     = false;
var _goNotifsOff = false;
var GO_GROUP_ID   = <?php echo (int)$group_id; ?>;
var GO_GROUP_NAME = <?php echo json_encode(htmlspecialchars($group['title'])); ?>;

/* ── Load saved states on page ready ── */
(function goLoadStates() {
    fetch('check_group_mute.php?group_id=' + GO_GROUP_ID)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.muted) {
                _goMuted = true;
                var chk   = document.getElementById('goMuteToggle');
                var title = document.getElementById('goMuteTitle');
                var sub   = document.getElementById('goMuteSub');
                if (chk)   chk.checked    = true;
                if (title) title.textContent = 'Unmute ' + GO_GROUP_NAME;
                if (sub)   sub.textContent   = 'Posts and activity from this pod are silenced';
            }
            if (data.notifs_off) {
                _goNotifsOff = true;
                var nChk   = document.getElementById('goNotifToggle');
                var nTitle = document.getElementById('goNotifTitle');
                var nSub   = document.getElementById('goNotifSub');
                var nIcon  = document.getElementById('goNotifIcon');
                if (nChk)   nChk.checked    = true;
                if (nTitle) nTitle.textContent = 'Turn on group notifications';
                if (nSub)   nSub.textContent   = 'You\'re not receiving any alerts from ' + GO_GROUP_NAME;
                if (nIcon)  nIcon.className     = 'ri-notification-line';
            }
        })
        .catch(function () {});
})();

/* ── Open ── */
function openGroupOptionsModal() {
    if (_goOpen) return;
    _goOpen = true;
    var ov = document.getElementById('groupOptionsOverlay');
    ov.classList.add('go-open');
    document.body.style.overflow = 'hidden';
}

/* ── Close ── */
function closeGroupOptionsModal() {
    if (!_goOpen) return;
    _goOpen = false;
    var ov   = document.getElementById('groupOptionsOverlay');
    var card = ov.querySelector('.go-card');
    var isMobile = window.innerWidth <= 768;

    /* inject out-keyframes once */
    if (!document.getElementById('go-out-anim')) {
        var s = document.createElement('style');
        s.id  = 'go-out-anim';
        s.textContent =
            '@keyframes goSheetOut{to{transform:translateY(100%);opacity:0.4}}' +
            '@keyframes goCardOut{to{transform:scale(0.93) translateY(10px);opacity:0}}';
        document.head.appendChild(s);
    }

    card.style.animation = isMobile
        ? 'goSheetOut 0.22s ease forwards'
        : 'goCardOut 0.2s ease forwards';

    setTimeout(function () {
        ov.classList.remove('go-open');
        card.style.animation = '';
        document.body.style.overflow = '';
    }, 220);
}

/* ── Mute toggle ── */
function goToggleMute() {
    _goMuted = !_goMuted;

    var chk   = document.getElementById('goMuteToggle');
    var title = document.getElementById('goMuteTitle');
    var sub   = document.getElementById('goMuteSub');
    if (chk) chk.checked = _goMuted;

    if (_goMuted) {
        if (title) title.textContent = 'Unmute ' + GO_GROUP_NAME;
        if (sub)   sub.textContent   = 'Posts and activity from this pod are silenced';
        goShowToast('Pod muted — posts won\'t appear in your feeds');
    } else {
        if (title) title.textContent = 'Mute ' + GO_GROUP_NAME;
        if (sub)   sub.textContent   = 'Silence all posts and activity from this pod';
        goShowToast('Pod unmuted — posts will appear in your feeds again');
    }

    fetch('group.php?id=' + GO_GROUP_ID, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    'mute_group=1&muted=' + (_goMuted ? 1 : 0) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data.ok) {
            /* revert on failure */
            _goMuted = !_goMuted;
            if (chk) chk.checked = _goMuted;
            goShowToast('Something went wrong — try again');
        }
    })
    .catch(function () {
        _goMuted = !_goMuted;
        if (chk) chk.checked = _goMuted;
        goShowToast('Network error — try again');
    });
}

/* ── Notifications toggle ── */
function goToggleNotifs() {
    _goNotifsOff = !_goNotifsOff;

    var chk   = document.getElementById('goNotifToggle');
    var title = document.getElementById('goNotifTitle');
    var sub   = document.getElementById('goNotifSub');
    var icon  = document.getElementById('goNotifIcon');
    if (chk) chk.checked = _goNotifsOff;

    if (_goNotifsOff) {
        if (title) title.textContent = 'Turn on group notifications';
        if (sub)   sub.textContent   = 'You\'re not receiving any alerts from ' + GO_GROUP_NAME;
        if (icon)  icon.className     = 'ri-notification-line';
        goShowToast('Notifications off for this pod');
    } else {
        if (title) title.textContent = 'Turn off group notifications';
        if (sub)   sub.textContent   = 'Stop receiving any alerts from ' + GO_GROUP_NAME;
        if (icon)  icon.className     = 'ri-notification-off-line';
        goShowToast('Notifications turned back on');
    }

    fetch('group.php?id=' + GO_GROUP_ID, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    'notif_group=1&off=' + (_goNotifsOff ? 1 : 0) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data.ok) {
            _goNotifsOff = !_goNotifsOff;
            if (chk) chk.checked = _goNotifsOff;
            goShowToast('Something went wrong — try again');
        }
    })
    .catch(function () {
        _goNotifsOff = !_goNotifsOff;
        if (chk) chk.checked = _goNotifsOff;
        goShowToast('Network error — try again');
    });
}

/* ── Share ── */
function goShareGroup() {
    var url = window.location.origin + window.location.pathname + '?id=' + GO_GROUP_ID;
    if (navigator.share) {
        navigator.share({
            title: GO_GROUP_NAME,
            text:  'Check out ' + GO_GROUP_NAME + ' on Dezzme!',
            url:   url
        }).catch(function () {});
    } else {
        goCopyLink();
    }
}

/* ── Copy link ── */
function goCopyLink() {
    var url       = window.location.origin + window.location.pathname + '?id=' + GO_GROUP_ID;
    var titleEl   = document.getElementById('goCopyLinkTitle');
    var original  = titleEl ? titleEl.textContent : 'Copy pod link';

    if (!navigator.clipboard) {
        /* fallback for older browsers */
        var ta = document.createElement('textarea');
        ta.value = url;
        ta.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            goShowToast('Link copied to clipboard!');
            if (titleEl) {
                titleEl.textContent = 'Link copied!';
                setTimeout(function () { titleEl.textContent = original; }, 2400);
            }
        } catch (e) {
            goShowToast('Could not copy link');
        }
        document.body.removeChild(ta);
        return;
    }

    navigator.clipboard.writeText(url)
        .then(function () {
            goShowToast('Link copied to clipboard!');
            if (titleEl) {
                titleEl.textContent = 'Link copied!';
                setTimeout(function () { titleEl.textContent = original; }, 2400);
            }
        })
        .catch(function () {
            goShowToast('Could not copy link');
        });
}

/* ── Leave group ── */
function goLeaveGroup() {
    if (!confirm('Leave ' + GO_GROUP_NAME + '? You can rejoin at any time.')) return;
    closeGroupOptionsModal();
    var f   = document.createElement('form');
    f.method = 'POST';
    f.action = 'group.php?id=' + GO_GROUP_ID;
    var inp  = document.createElement('input');
    inp.type  = 'hidden';
    inp.name  = 'leave_group';
    inp.value = '1';
    f.appendChild(inp);
    var csrf = document.createElement('input');
    csrf.type  = 'hidden';
    csrf.name  = 'csrf_token';
    csrf.value = CSRF_TOKEN;
    f.appendChild(csrf);
    document.body.appendChild(f);
    f.submit();
}

/* ── Toast ── */
function goShowToast(msg) {
    var t = document.getElementById('goToast');
    if (!t) return;
    t.textContent = msg;
    t.style.display = 'block';
    clearTimeout(t._tid);
    requestAnimationFrame(function () {
        t.style.opacity   = '1';
        t.style.transform = 'translateX(-50%) translateY(0)';
    });
    t._tid = setTimeout(function () {
        t.style.opacity   = '0';
        t.style.transform = 'translateX(-50%) translateY(8px)';
        setTimeout(function () { t.style.display = 'none'; }, 230);
    }, 2600);
}

/* ── ESC key ── */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && _goOpen) closeGroupOptionsModal();
});

/* ── Mobile swipe-down to close ── */
(function () {
    var startY = 0;
    var ov = document.getElementById('groupOptionsOverlay');
    if (!ov) return;
    ov.addEventListener('touchstart', function (e) {
        startY = e.touches[0].clientY;
    }, { passive: true });
    ov.addEventListener('touchend', function (e) {
        if (e.changedTouches[0].clientY - startY > 60) closeGroupOptionsModal();
    }, { passive: true });
})();
</script>
</template>

<!-- ═══════════════════════════════════════════════════════════════
     POD HIGHLIGHTS VIEWER (profile-style stage + comments below the box)
     Needs: group_highlight_api.php + tables group_highlight_comments
            (with parent_id), group_highlight_likes,
            group_highlight_comment_likes, group_highlight_comment_dislikes
     ═══════════════════════════════════════════════════════════════ -->
<script>
/* Group highlights viewer is LAZY: markup, css and script wait in an inert <template> (same PHP scope as before, nothing inside changed) and are activated the first time a highlight is opened or the page opens with &hl=ID. */
(function(){
  var loaded = false;
  function activate(){
    var tpl = document.getElementById('dzGHlTpl');
    if (!tpl || loaded) return;
    loaded = true;
    var frag = tpl.content.cloneNode(true);
    var scripts = Array.prototype.slice.call(frag.querySelectorAll('script'));
    scripts.forEach(function(s){ s.parentNode.removeChild(s); });
    document.body.appendChild(frag);
    scripts.forEach(function(old){ var s = document.createElement('script'); s.text = old.textContent; document.body.appendChild(s); });
  }
  var stub = function(id){
    activate();
    if (window.openGroupHighlightsModal && window.openGroupHighlightsModal !== stub) return window.openGroupHighlightsModal(id);
    console.error('Highlights viewer failed to load');
  };
  window.openGroupHighlightsModal = stub;
  window.dzGHlEnsure = activate;
  document.addEventListener('DOMContentLoaded', function(){
    var id = new URLSearchParams(location.search).get('hl');
    if (id) setTimeout(function(){ window.openGroupHighlightsModal(id); }, 700);
  });
})();
</script>
<template id="dzGHlTpl">
<div id="hlModal" onclick="if(event.target===this) hlClose()">
  <div id="hlScroll" onclick="if(event.target===this) hlClose()">
    <div id="hlWrap">

      <!-- prev / next highlight arrows (normal view only; first / middle / last aware) -->
      <button type="button" class="hl-nav hl-nav-prev off" id="hlNavPrev" onclick="hlGoHl(-1)" aria-label="Previous highlight"><i class="ri-arrow-left-s-line"></i></button>
      <button type="button" class="hl-nav hl-nav-next off" id="hlNavNext" onclick="hlGoHl(1)" aria-label="Next highlight"><i class="ri-arrow-right-s-line"></i></button>

      <!-- ═════ THE HIGHLIGHT BOX ═════ -->
      <div id="hlStage">
        <div id="hlScene">
          <div id="hlCube">
            <div id="hlFace">

              <div id="hlMedia"></div>
              <div class="hl-shade-top"></div>
              <div class="hl-shade-bottom"></div>

              <div class="hl-zone hl-zone-prev" onclick="window.dzHp&&dzHp('light');hlPrev()"></div>
              <div class="hl-zone hl-zone-next" onclick="window.dzHp&&dzHp('light');hlNext()"></div>

              <!-- text on media, or the poll -->
              <div id="hlCapLayer" class="hl-cap-layer"></div>

              <!-- the highlight's name: shows over a dark layer for a moment when it opens, then fades away (like TikTok / Instagram) -->
              <div id="hlIntro" class="hl-intro" aria-hidden="true"><span id="hlIntroTxt" class="hl-intro-txt"></span></div>

              <div class="hl-top">
                <div class="hl-dashes" id="hlDashes"></div>
                <div class="hl-head">
                  <div class="hl-av-col">
                    <img id="hlAvatar" class="hl-avatar" alt="">
                  </div>
                  <div class="hl-head-info">
                    <div class="hl-title-row">
                      <div class="hl-pod-row"><span id="hlPod" class="hl-pod"></span><span class="hl-dot">•</span><span id="hlDate" class="hl-date"></span></div>
                      <div class="hl-who-row"><a id="hlWho" class="hl-who" href="#"></a><span id="hlBadge"></span></div>
                    </div>
                  </div>
                  <button type="button" class="hl-icon-btn" id="hlSnd" onclick="hlSound()" aria-label="Sound" style="display:none"><i class="ri-volume-up-fill"></i></button>
                  <button type="button" class="hl-icon-btn hl-menu" onclick="hlOpenSheet()" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M2 6h20M2 12h20M2 18h20"/></svg></button>
                  <div class="hl-x-col">
                    <button type="button" class="hl-icon-btn hl-x" onclick="hlClose()" aria-label="Close"><i class="ri-close-line"></i></button>
                    <span id="hlCount" class="hl-count"></span>
                  </div>
                </div>
                <div id="hlSong" class="hl-songline"></div>
              </div>

              <div class="hl-bottom">
                <div class="hl-bar" id="hlBar" onclick="hlOpenComments(true)" role="button" tabindex="0">
                  <span class="hl-fake" id="hlBarTxt">Add a comment…</span>
                </div>
                <button type="button" class="ipv-action-btn hl-ab hl-like" id="hlLikeBtn" onclick="hlLike()" aria-label="Like"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i><span id="hlLikeCount" class="ipv-action-count"></span></button>
                <button type="button" class="ipv-action-btn hl-ab" onclick="hlOpenComments(false)" aria-label="Comments"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg></i><span id="hlBarCount" class="ipv-action-count"></span></button>
              </div>

            </div><!-- /#hlFace -->
          </div><!-- /#hlCube -->
        </div><!-- /#hlScene -->

        <div id="hlLoader"><div class="hl-spin"></div></div>

        <div id="hlSheetOv" onclick="hlCloseSheet()"></div>
        <div id="hlSheet">
          <div class="dzm-handle"><span></span></div>
          <div class="dzm-card"><div class="dzm-scroll">
            <div class="dzm-header">Highlight</div>
            <div id="hlSheetList"></div>
          </div></div>
          <button type="button" class="dzm-close-btn" onclick="hlCloseSheet()">Cancel</button>
        </div>
        <div id="hlCmOv" onclick="hlCloseComments()"></div>
      <!-- ═════ COMMENTS bottom sheet (bottom bar / ⋯ menu) ═════ -->
        <section id="hlComments" aria-label="Comments">
          <div class="hl-cm-top">
            <div class="dzm-handle"><span></span></div>
            <div class="hl-cm-head">
              <div class="hl-cm-title"><i class="ri-question-answer-fill"></i>Comments<span id="hlCmCount" class="hl-cm-pill"></span></div>
              <div class="hl-cm-sort">
                <button type="button" class="hl-cm-sortbtn" id="hlCmSortBtn"><span id="hlCmSortLbl">Top</span><i class="ri-arrow-down-s-line"></i><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h11M4 17h6"/></svg></button>
                <div class="hl-cm-sortmenu" id="hlCmSortMenu">
                  <button type="button" class="active" data-sort="top">Top comments</button>
                  <button type="button" data-sort="new">Newest first</button>
                  <button type="button" data-sort="old">Oldest first</button>
                </div>
              </div>
              <button type="button" class="hl-cm-hb" onclick="hlCloseComments()" aria-label="Close"><i class="ri-close-line"></i></button>
            </div>
          </div>
          <div class="hl-cm-note"><i class="ri-shield-check-line"></i><span>Remember to keep comments respectful.</span></div>
          <div id="hlCmScroll"><div id="hlCmList"></div>
          <div class="hl-cm-sent" id="hlCmSent"><div class="hl-cm-spin"></div></div></div>
          <div id="hlCmCompose" class="hl-cm-composer"></div>

          <div class="hl-cm-menu" id="hlCmMenu" role="menu">
            <button type="button" data-act="download" role="menuitem"><i class="ri-download-2-line"></i><span>Download comment</span></button>
            <button type="button" data-act="delete" class="danger" role="menuitem"><i class="ri-delete-bin-line"></i><span>Delete comment</span></button>
          </div>
        </section>

      </div><!-- /#hlStage -->

    </div><!-- /#hlWrap -->
  </div><!-- /#hlScroll -->
</div>

<style>
#hlModal,#hlModal *,#hlModal *::before,#hlModal *::after{box-sizing:border-box;font-family:'Poppins',sans-serif}
#hlModal [class^="ri-"],#hlModal [class*=" ri-"]{font-family:'remixicon'!important}
#hlModal{
  display:none; position:fixed; inset:0; z-index:2147483100;
  background:rgba(0,0,0,.94); -webkit-backdrop-filter:blur(14px); backdrop-filter:blur(14px);
  opacity:0; transition:opacity .15s ease; color:#fff;
}
#hlModal.open{ display:block; }
#hlModal.vis{ opacity:1; }
#hlModal a{ color:inherit; text-decoration:none; }
#hlModal button{ font-family:inherit; }

/* the whole modal scrolls: box on top, comments underneath */
#hlScroll{ position:absolute; inset:0; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; scrollbar-width:thin; scrollbar-color:rgba(36,89,184,.45) transparent; }
#hlScroll::-webkit-scrollbar{ width:calc(5px * var(--md-s)); }
#hlScroll::-webkit-scrollbar-thumb{ background:rgba(36,89,184,.45); border-radius:calc(99px * var(--md-s)); }
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
  #hlWrap{ --hl-stage-h:min(86vh,900px); margin:3vh auto; box-shadow:0 25px 90px rgba(0,0,0,.65),0 0 0 1px rgba(var(--fg,255,255,255),.07); border-radius:calc(8px * var(--md-s)); }
  #hlStage{ border-radius:calc(8px * var(--md-s)) calc(8px * var(--md-s)) 0 0; clip-path:inset(0 round 8px 8px 0 0); -webkit-clip-path:inset(0 round 8px 8px 0 0); }
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

/* ═══ 3D CUBE ═══ */
#hlScene{ position:absolute; inset:0; z-index:1; perspective:1000px; -webkit-perspective:1000px; overflow:hidden; background:#000; }
#hlCube{ position:absolute; inset:0; transform-origin:50% 50%; }
#hlCube.flip{ transform-style:preserve-3d; -webkit-transform-style:preserve-3d; will-change:transform; }
#hlCube.flip > *{ backface-visibility:hidden; -webkit-backface-visibility:hidden; }
#hlFace{ position:absolute; inset:0; overflow:hidden; background:#000; }
.hl-peek{ position:absolute; inset:0; overflow:hidden; background:#000; pointer-events:none; }

#hlMedia,.hl-peek-media{ position:absolute; inset:0; background:#000; overflow:hidden; }
.hl-media-item{ position:relative; z-index:1; width:100%; height:100%; object-fit:contain; display:block; background:transparent; -webkit-user-drag:none; }
.hl-pollbg{ position:absolute; inset:0; background:linear-gradient(160deg,#101c52,#2459B8 60%,#6b4dff); }

.hl-shade-top,.hl-shade-bottom{ position:absolute; left:0; right:0; pointer-events:none; z-index:2; }
.hl-shade-top{ top:0; height:calc(150px * var(--md-s)); background:linear-gradient(to bottom,rgba(0,0,0,.7),transparent); }
.hl-shade-bottom{ bottom:0; height:calc(160px * var(--md-s)); background:linear-gradient(to top,rgba(0,0,0,.75),transparent); }

.hl-zone{ position:absolute; top:calc(100px * var(--md-s)); bottom:calc(74px * var(--md-s)); z-index:3; -webkit-tap-highlight-color:transparent; cursor:pointer; }
.hl-zone-prev{ left:0; width:35%; }
.hl-zone-next{ right:0; width:65%; }

/* text on media + poll */
.hl-cap-layer{ position:absolute; inset:0; z-index:4; pointer-events:none; }
.hl-cap-wrap{ position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:calc(6px * var(--md-s)); padding:calc(112px * var(--md-s)) calc(18px * var(--md-s)) calc(84px * var(--md-s)); text-align:center; min-height:0; pointer-events:none; }
.hl-cap{ pointer-events:auto; max-width:100%; min-height:0; padding:calc(10px * var(--md-s)) calc(13px * var(--md-s)); border-radius:calc(12px * var(--md-s)); background:rgba(0,0,0,.34); color:#fff; font-size:calc(15px * var(--md-s)); font-weight:600; line-height:1.5; text-shadow:0 1px 6px rgba(0,0,0,.85); word-break:break-word; overflow-wrap:anywhere; -webkit-user-select:text; user-select:text; }
.hl-cap.clamp{ display:-webkit-box; -webkit-line-clamp:4; -webkit-box-orient:vertical; overflow:hidden; }
.hl-cap:not(.clamp){ max-height:100%; overflow-y:auto; scrollbar-width:none; touch-action:pan-y; }
.hl-cap:not(.clamp)::-webkit-scrollbar{ display:none; }
.hl-more{ pointer-events:auto; flex-shrink:0; background:rgba(0,0,0,.5); border:1px solid rgba(255,255,255,.25); border-radius:calc(99px * var(--md-s)); color:#fff; font-size:calc(11px * var(--md-s)); font-weight:600; padding:calc(3px * var(--md-s)) calc(12px * var(--md-s)); cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-more:hover{ background:rgba(0,0,0,.7); }
.hl-men{ font-weight:700; text-decoration:none; cursor:pointer; text-shadow:0 1px 5px rgba(0,0,0,.9); }
.hl-men.user{ color:#6ea2ff; }
.hl-men.group{ color:#37c5ff; }
.hl-men:hover{ text-decoration:underline; }

.hl-poll{ position:absolute; inset:0; display:flex; flex-direction:column; justify-content:center; gap:calc(10px * var(--md-s)); padding:calc(112px * var(--md-s)) calc(20px * var(--md-s)) calc(84px * var(--md-s)); pointer-events:none; overflow:hidden; }
.hl-ptag{ align-self:center; display:inline-flex; align-items:center; gap:calc(5px * var(--md-s)); font-size:calc(10px * var(--md-s)); font-weight:700; letter-spacing:1px; text-transform:uppercase; color:rgba(255,255,255,.85); }
.hl-pq{ font-size:calc(18px * var(--md-s)); font-weight:700; text-align:center; line-height:1.4; margin-bottom:calc(4px * var(--md-s)); text-shadow:0 1px 6px rgba(0,0,0,.4); word-break:break-word; }
.hl-po{ pointer-events:auto; position:relative; overflow:hidden; width:100%; text-align:left; border:1.5px solid rgba(255,255,255,.28); background:rgba(255,255,255,.14); color:#fff; border-radius:calc(14px * var(--md-s)); padding:calc(12px * var(--md-s)) calc(14px * var(--md-s)); font-size:calc(14px * var(--md-s)); font-weight:600; display:flex; justify-content:space-between; align-items:center; gap:calc(10px * var(--md-s)); cursor:pointer; transition:transform .12s; -webkit-tap-highlight-color:transparent; }
.hl-po:active{ transform:scale(.98); }
.hl-po.mine{ border-color:#fff; background:rgba(255,255,255,.26); }
.hl-po .bar{ position:absolute; left:0; top:0; bottom:0; background:rgba(255,255,255,.28); width:0; transition:width .45s ease; }
.hl-po .lb,.hl-po .pc{ position:relative; word-break:break-word; }
.hl-po .pc{ font-weight:700; flex-shrink:0; }
.hl-pm,.hl-ph{ text-align:center; font-size:calc(11px * var(--md-s)); color:rgba(255,255,255,.85); }
.hl-ph{ color:rgba(255,255,255,.7); }

/* ═══ HEADER: pod pic, pod name • date, username underneath ═══ */
.hl-top{ position:absolute; top:0; left:0; right:0; z-index:5; padding:calc(env(safe-area-inset-top,0px) + calc(8px * var(--md-s))) calc(10px * var(--md-s)) 0; pointer-events:none; }
.hl-top > *{ pointer-events:auto; }

.hl-dashes{ display:flex; gap:calc(3px * var(--md-s)); }
.hl-dash{ flex:1 1 0; height:calc(3px * var(--md-s)); border-radius:2px; background:rgba(255,255,255,.3); overflow:hidden; }
.hl-dash-fill{ height:100%; width:0; background:#2b63d1; border-radius:2px; }
.hl-dash.done .hl-dash-fill{ width:100%; }

.hl-head{ display:flex; align-items:flex-start; gap:calc(9px * var(--md-s)); margin-top:calc(9px * var(--md-s)); }
.hl-av-col{ display:flex; flex-direction:column; align-items:center; gap:calc(5px * var(--md-s)); flex-shrink:0; }
.hl-avatar{ width:calc(38px * var(--md-s)); height:calc(38px * var(--md-s)); border-radius:calc(10px * var(--md-s)); object-fit:cover; border:2.5px solid #2b63d1; display:block; background:var(--surface-2,#0d1030); }
.hl-songline{ margin-top:2px; max-width:calc(100% - calc(56px * var(--md-s))); color:rgba(255,255,255,.8); font-size:calc(10.1px * var(--md-s)); font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-songline:empty{ display:none; }
.hl-x-col{ display:flex; flex-direction:column; align-items:center; gap:calc(4px * var(--md-s)); flex-shrink:0; }
.hl-count{ background:rgba(22,22,24,.7); color:#fff; font-size:calc(10px * var(--md-s)); font-weight:800; padding:2px calc(7px * var(--md-s)); border-radius:calc(6px * var(--md-s)); }
.hl-head-info{ flex:1; min-width:0; padding-top:0; }
/* highlight name intro: dark layer + big name, fades in, holds, fades out; never blocks a tap */
.hl-intro{ position:absolute; inset:0; z-index:4; display:flex; align-items:center; justify-content:center; padding:0 12%; background:rgba(0,0,0,.58); opacity:0; pointer-events:none; text-align:center; }
.hl-intro.show{ animation:hlIntroAnim 2.2s ease forwards; }
.hl-intro-txt{ color:#fff; font-weight:700; font-size:calc(clamp(20px, 4.2vw + 8px, 30px) * var(--md-s)); line-height:1.25; letter-spacing:-.2px; text-shadow:0 2px 12px rgba(0,0,0,.6); word-break:break-word; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
@keyframes hlIntroAnim{ 0%{ opacity:0; } 14%{ opacity:1; } 72%{ opacity:1; } 100%{ opacity:0; } }
.hl-title-row{ display:flex; flex-direction:column; align-items:flex-start; gap:2px; min-width:0; max-width:100%; }
.hl-pod-row{ display:flex; align-items:center; gap:calc(5px * var(--md-s)); min-width:0; max-width:100%; }
.hl-pod{ min-width:0; color:#fff; font-weight:700; font-size:calc(13px * var(--md-s)); line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-dot{ color:rgba(255,255,255,.45); font-size:calc(11px * var(--md-s)); flex-shrink:0; }
.hl-date{ flex-shrink:0; color:rgba(255,255,255,.72); font-size:calc(10.5px * var(--md-s)); white-space:nowrap; text-shadow:0 1px 4px rgba(0,0,0,.6); }
/* username + Owner/Admin tag: same line AND same text baseline */
.hl-who-row{ display:flex; flex-direction:row; flex-wrap:nowrap; align-items:baseline; gap:calc(6px * var(--md-s)); min-width:0; max-width:100%; }
#hlModal .hl-who{ display:block; flex:0 1 auto; min-width:0; color:#a3a7b5; cursor:pointer; pointer-events:auto; font-weight:500; font-size:calc(11.5px * var(--md-s)); line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-who:hover{ text-decoration:underline; }
.hl-who-row > #hlBadge{ display:inline-flex; align-items:baseline; flex:0 0 auto; line-height:1.3; }

/* owner / admin: just the word, in blue — no pill, no icon */
.hl-bdg{ display:inline-block; font-size:calc(10.5px * var(--md-s)); font-weight:700; flex:0 0 auto; white-space:nowrap; letter-spacing:.2px; line-height:1.3; text-shadow:0 1px 4px rgba(0,0,0,.5); }
.hl-bdg.owner{ color:#6ea2ff; }
.hl-bdg.admin{ color:#4d86f0; }

.hl-icon-btn{ --hl-ic:21px; background:none; border:none; color:#fff; cursor:pointer; width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); padding:0; display:flex; align-items:center; justify-content:center; -webkit-tap-highlight-color:transparent; }
.hl-icon-btn i{ font-size:var(--hl-ic); line-height:1; filter:drop-shadow(0 2px 5px rgba(0,0,0,.7)); }
.hl-icon-btn svg{ width:var(--hl-ic); height:var(--hl-ic); display:block; filter:drop-shadow(0 2px 5px rgba(0,0,0,.7)); }
.hl-icon-btn.hl-x i{ font-size:calc(var(--hl-ic) * 1.4); }
/* menu icon: a bit smaller than the X, but not tiny */
.hl-icon-btn.hl-menu svg{ width:calc(var(--hl-ic) * 1.02); height:calc(var(--hl-ic) * 1.02); }
.hl-icon-btn:active{ transform:scale(.88); opacity:.7; }

.hl-bottom{ position:absolute; left:0; right:0; bottom:0; z-index:7; display:flex; align-items:center; gap:calc(12px * var(--md-s)); padding:calc(10px * var(--md-s)) calc(12px * var(--md-s)) calc(12px * var(--md-s)); }
.hl-bar{ flex:1; min-width:0; display:flex; align-items:center; height:calc(38px * var(--md-s)); padding:0 calc(14px * var(--md-s)); border-radius:999px; background:rgba(255,255,255,.12); -webkit-backdrop-filter:blur(12px); backdrop-filter:blur(12px); cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent; }
.hl-bar:hover{ background:rgba(255,255,255,.18); }
.hl-fake{ flex:1; min-width:0; color:rgba(255,255,255,.6); font-size:calc(12.5px * var(--md-s)); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hl-act{ background:none; border:none; color:#fff; cursor:pointer; display:flex; align-items:center; gap:calc(4px * var(--md-s)); font-size:calc(12px * var(--md-s)); font-weight:800; -webkit-tap-highlight-color:transparent; text-shadow:0 1px 4px rgba(0,0,0,.7); padding:0; transition:color .2s; }
.hl-act i{ font-size:calc(24px * var(--md-s)); filter:drop-shadow(0 2px 5px rgba(0,0,0,.6)); transition:transform .18s ease, color .2s; }
.hl-act:active i{ transform:scale(1.25); }
.hl-act.on, .hl-act.on span{ color:#4d86f0; }
.hl-act i.pop{ animation:hlPop .38s ease; }
@keyframes hlPop{ 0%{transform:scale(1)} 40%{transform:scale(1.5)} 100%{transform:scale(1)} }

/* loader (stage skeleton) */
#hlLoader{ position:absolute; inset:0; z-index:9; background:linear-gradient(90deg,#0b0c1c 25%,#14162e 50%,#0b0c1c 75%); background-size:200% 100%; animation:hlSh 1.3s linear infinite; display:none; align-items:center; justify-content:center; }
#hlLoader.show{ display:flex; }
@keyframes hlSh{ to{ background-position:-200% 0; } }
.hl-spin{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); border-radius:50%; border:3px solid rgba(255,255,255,.15); border-top-color:#fff; animation:hlSpin .8s linear infinite; }
@keyframes hlSpin{ to{ transform:rotate(360deg); } }

/* menu sheet */
#hlSheetOv{ position:absolute; inset:0; z-index:10; background:rgba(0,0,0,.6); opacity:0; pointer-events:none; transition:opacity .25s ease; }
#hlSheetOv.show{ opacity:1; pointer-events:all; }
#hlSheet{ position:absolute; left:0; right:0; bottom:0; z-index:11; background:rgba(12,12,28,.98); border-top:1px solid rgba(var(--fg,255,255,255),.08); border-radius:calc(12px * var(--md-s)) calc(12px * var(--md-s)) 0 0; padding:calc(8px * var(--md-s)) 0 calc(12px * var(--md-s)); transform:translateY(100%); transition:transform .3s cubic-bezier(.32,.72,0,1); }
#hlSheet.show{ transform:translateY(0); }
.hl-sheet-handle{ width:calc(32px * var(--md-s)); height:calc(3px * var(--md-s)); border-radius:999px; background:rgba(255,255,255,.2); margin:0 auto calc(4px * var(--md-s)); }
.hl-sheet-item{ display:flex; align-items:center; gap:calc(10px * var(--md-s)); padding:calc(11px * var(--md-s)) calc(16px * var(--md-s)); color:#fff; font-size:calc(12.5px * var(--md-s)); font-weight:500; cursor:pointer; border-bottom:1px solid rgba(255,255,255,.06); -webkit-tap-highlight-color:transparent; }
.hl-sheet-item:last-child{ border-bottom:none; }
.hl-sheet-item:active{ background:rgba(255,255,255,.06); }
.hl-sheet-item i{ font-size:calc(15px * var(--md-s)); color:#5b8fe6; width:calc(18px * var(--md-s)); text-align:center; }
.hl-sheet-cancel{ display:block; width:calc(100% - calc(24px * var(--md-s))); margin:calc(6px * var(--md-s)) calc(12px * var(--md-s)) 0; padding:calc(9px * var(--md-s)); background:rgba(255,255,255,.06); border:none; border-radius:calc(10px * var(--md-s)); color:rgba(255,255,255,.7); font-weight:600; font-size:calc(12px * var(--md-s)); cursor:pointer; }
/* toast is fixed to the screen so you can see it from the comments too */
.hl-toast{ position:fixed; left:50%; bottom:calc(96px * var(--md-s)); transform:translateX(-50%); z-index:30; background:rgba(20,20,25,.96); border:1px solid rgba(43,99,209,.55); color:#fff; padding:calc(7px * var(--md-s)) calc(18px * var(--md-s)); border-radius:calc(50px * var(--md-s)); font-size:calc(11.5px * var(--md-s)); white-space:nowrap; max-width:92%; overflow:hidden; text-overflow:ellipsis; pointer-events:none; transition:opacity .3s; }

/* ═════════ COMMENTS BELOW THE BOX — detailed, blue, responsive ═════════ */
#hlComments{
  --cm-bg:var(--lane,#070924);
  --cm-px:18px; --csav:34px; --csgap:calc(11px * var(--md-s)); --cm-fs:calc(13.5px * var(--md-s)); --cm-meta:11.5px; --thread:rgba(110,160,255,.28);
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
.hl-cm-title i{ color:#5b8fe6; font-size:1.15em; }
.hl-cm-pill{ padding:0; border:none; background:none; border-radius:0; color:rgba(255,255,255,.6); font-size:calc(var(--cm-fs) + 1px); font-weight:600; }
.hl-cm-pill:empty{ display:none; }
.hl-cm-sort{ position:relative; }
.hl-cm-sortbtn{ display:flex; align-items:center; gap:2px; padding:calc(5px * var(--md-s)) calc(9px * var(--md-s)); border:none; border-radius:calc(8px * var(--md-s)); background:rgba(255,255,255,.07); color:rgba(255,255,255,.88); font-size:var(--cm-meta); font-weight:600; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-cm-sortbtn:hover{ background:rgba(255,255,255,.13); }
.hl-cm-sortmenu{ position:absolute; top:calc(100% + calc(6px * var(--md-s))); right:0; z-index:9; display:none; min-width:calc(150px * var(--md-s)); background:#0c0e2a; border:1px solid rgba(255,255,255,.1); border-radius:calc(12px * var(--md-s)); padding:calc(6px * var(--md-s)); box-shadow:0 10px 30px rgba(0,0,0,.55); }
.hl-cm-sortmenu.show{ display:block; }
.hl-cm-sortmenu button{ display:block; width:100%; text-align:left; padding:calc(8px * var(--md-s)) calc(10px * var(--md-s)); border:none; background:none; color:#fff; font-size:calc(12.5px * var(--md-s)); border-radius:calc(8px * var(--md-s)); cursor:pointer; }
.hl-cm-sortmenu button:hover{ background:rgba(255,255,255,.07); }
.hl-cm-sortmenu button.active{ color:#6ea2ff; font-weight:600; }
.hl-cm-hb{ width:calc(34px * var(--md-s)); height:calc(34px * var(--md-s)); border:none; border-radius:50%; background:rgba(255,255,255,.07); color:#fff; font-size:calc(20px * var(--md-s)); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .2s,transform .15s; -webkit-tap-highlight-color:transparent; flex-shrink:0; }
.hl-cm-hb:hover{ background:rgba(255,255,255,.14); }
.hl-cm-hb:active{ transform:scale(.9); }
/* V (down) while you're on the post, chevron up once you're in the comments */
#hlComments:not(.cm-on) .hl-nv-up{ display:none; }
#hlComments.cm-on .hl-nv-dn{ display:none; }

.hl-cm-note{ display:flex; align-items:center; gap:calc(8px * var(--md-s)); margin:calc(12px * var(--md-s)) var(--cm-px) 2px; padding:calc(8px * var(--md-s)) calc(12px * var(--md-s)); border-radius:calc(12px * var(--md-s)); background:rgba(255,255,255,.045); border:1px solid rgba(255,255,255,.08); font-size:calc(var(--cm-meta) + .5px); line-height:1.3; color:rgba(255,255,255,.86); }
.hl-cm-note i{ font-size:calc(1rem * var(--md-s)); line-height:1; color:#7fa6f0; flex-shrink:0; }

#hlCmList{ flex:1 0 auto; padding:2px var(--cm-px) calc(20px * var(--md-s)); -webkit-user-select:text; user-select:text; }

/* one comment (any depth) */
.hl-ci{ --csav:34px; --csgap:calc(11px * var(--md-s)); padding:calc(12px * var(--md-s)) 0; }
.hl-ci.fresh{ animation:hlCmIn .3s ease both; }
@keyframes hlCmIn{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }
.hl-ci-main{ display:flex; gap:var(--csgap); }
.hl-ci-avcol{ display:flex; flex-direction:column; align-items:center; flex-shrink:0; width:var(--csav); }
.hl-ci-av{ width:var(--csav); height:var(--csav); border-radius:50%; overflow:hidden; display:block; flex-shrink:0; border:2px solid #2b63d1; background:var(--surface-2,#0d1030); }
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
.hl-mention{ color:#6ea2ff; font-weight:600; }
.hl-link{ color:#6e9cf0; font-weight:500; word-break:break-all; }
.hl-link:hover{ text-decoration:underline; }
.hl-ci-r2{ display:flex; align-items:center; gap:calc(18px * var(--md-s)); margin-top:calc(8px * var(--md-s)); }
.hl-ci-like,.hl-ci-dis,.hl-ci-reply{ display:flex; align-items:center; gap:calc(6px * var(--md-s)); border:none; background:none; padding:0; color:rgba(255,255,255,.85); font-size:calc(var(--cm-meta) + .5px); font-weight:500; cursor:pointer; transition:color .2s,transform .12s; -webkit-tap-highlight-color:transparent; }
.hl-ci-like i,.hl-ci-dis i{ font-size:calc(1.15rem * var(--md-s)); display:block; line-height:1; }
.hl-ci-like:active,.hl-ci-dis:active,.hl-ci-reply:active{ transform:scale(.9); }
.hl-ci-like.on,.hl-ci-dis.on{ color:#4d86f0; }
.hl-ci-like i.pop,.hl-ci-dis i.pop{ animation:hlPop .34s ease; }
.hl-ci-reply{ color:rgba(255,255,255,.5); }
.hl-ci-reply:hover{ color:#fff; }
.hl-crl{ position:relative; display:inline-flex; width:calc(16px * var(--md-s)); height:calc(16px * var(--md-s)); flex-shrink:0; margin-left:-2px; }
.hl-crl-av{ width:calc(16px * var(--md-s)); height:calc(16px * var(--md-s)); border-radius:50%; overflow:hidden; border:1.5px solid #2b63d1; background:var(--surface-2,#0d1030); display:block; }
.hl-crl-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-crl-b{ position:absolute; right:calc(-5px * var(--md-s)); bottom:calc(-4px * var(--md-s)); font-size:calc(.68rem * var(--md-s)); line-height:1; color:#4d86f0; filter:drop-shadow(0 0 1.5px var(--lane,#070924)) drop-shadow(0 0 1px var(--lane,#070924)); }

/* "· 9 replies ›" toggle and the nested thread under it */
.hl-ci-tg{ position:relative; display:flex; align-items:center; gap:calc(7px * var(--md-s)); height:calc(30px * var(--md-s)); margin-left:calc(var(--csav) + var(--csgap)); border:none; background:none; padding:0; color:#fff; font-size:calc(var(--cm-meta) + .5px); font-weight:600; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-ci-tg::before{ content:''; position:absolute; top:0; height:50%; box-sizing:border-box; left:calc(-1 * (var(--csav) / 2 + var(--csgap) + 1px)); width:calc(var(--csav) / 2 + var(--csgap) + 1px); border-left:2px solid var(--thread); border-bottom:2px solid var(--thread); border-bottom-left-radius:12px; }
.hl-ci-tgav{ width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); border-radius:50%; overflow:hidden; flex-shrink:0; border:1.5px solid #2b63d1; background:var(--surface-2,#0d1030); display:block; }
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
.hl-cm-empty i{ width:calc(58px * var(--md-s)); height:calc(58px * var(--md-s)); border-radius:50%; background:rgba(43,99,209,.14); border:1px solid rgba(43,99,209,.35); display:flex; align-items:center; justify-content:center; margin:0 auto calc(12px * var(--md-s)); font-size:calc(26px * var(--md-s)); color:#7fa6f0; }
.hl-cm-sent{ display:none; justify-content:center; padding:calc(10px * var(--md-s)) 0 calc(18px * var(--md-s)); }
.hl-cm-sent.show{ display:flex; }
.hl-cm-spin{ width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); border-radius:50%; border:3px solid rgba(255,255,255,.15); border-top-color:#6ea2ff; animation:hlSpin .8s linear infinite; }
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
.hl-reply-chip{ display:none; align-items:center; gap:calc(8px * var(--md-s)); margin:0 0 calc(8px * var(--md-s)); padding:calc(5px * var(--md-s)) calc(6px * var(--md-s)) calc(5px * var(--md-s)) calc(12px * var(--md-s)); border-radius:calc(8px * var(--md-s)); background:rgba(43,99,209,.14); border:1px solid rgba(43,99,209,.42); font-size:var(--cm-meta); color:rgba(255,255,255,.72); }
.hl-reply-chip.show{ display:flex; animation:hlCmIn .2s ease both; }
.hl-reply-chip b{ flex:1; min-width:0; color:#9db9f5; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hl-reply-x{ width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); border:none; border-radius:50%; background:rgba(255,255,255,.1); color:#fff; display:flex; align-items:center; justify-content:center; font-size:calc(14px * var(--md-s)); cursor:pointer; flex-shrink:0; }
.hl-reply-x:hover{ background:rgba(255,255,255,.2); }
.hl-compose-row{ display:flex; align-items:center; gap:calc(10px * var(--md-s)); }
.hl-cmy{ width:calc(32px * var(--md-s)); height:calc(32px * var(--md-s)); border-radius:50%; overflow:hidden; flex-shrink:0; border:2px solid #2b63d1; background:var(--surface-2,#0d1030); display:block; }
.hl-cmy img{ width:100%; height:100%; object-fit:cover; display:block; }
#hlCIn{ flex:1; min-width:0; border:1px solid rgba(var(--fg,255,255,255),.1); outline:none; background:rgba(var(--fg,255,255,255),.07); color:var(--text,#F0F2FF); padding:calc(9px * var(--md-s)) calc(14px * var(--md-s)); border-radius:999px; font-size:var(--cm-fs); -webkit-user-select:text; user-select:text; transition:border-color .2s,box-shadow .2s,background .2s; }
#hlCIn:focus{ border-color:rgba(76,141,255,.9); background:rgba(var(--fg,255,255,255),.1); box-shadow:0 0 0 3px rgba(43,99,209,.22); }
#hlCIn::placeholder{ color:rgba(var(--fg,255,255,255),.4); }
.hl-send{ width:calc(34px * var(--md-s)); height:calc(34px * var(--md-s)); border:none; border-radius:50%; padding:0; flex-shrink:0; background:linear-gradient(135deg,#2b63d1,#4C8DFF); color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .2s,color .2s,opacity .2s,transform .15s; -webkit-tap-highlight-color:transparent; }
.hl-send svg{ width:calc(18px * var(--md-s)); height:calc(18px * var(--md-s)); display:block; }
.hl-send:not(:disabled):active{ transform:scale(.88); }
.hl-send:disabled{ background:rgba(255,255,255,.08); color:rgba(255,255,255,.4); cursor:default; }
.hl-nocm{ display:flex; align-items:center; justify-content:center; gap:calc(7px * var(--md-s)); font-size:var(--cm-meta); color:rgba(255,255,255,.55); }

/* responsive — things get smaller step by step, like the group page */
@media(max-width:790px){
  /* highlight box leaves a 48px strip at the bottom: only the comments TOP BAR (Comments · count · Top ▾) shows there */
  #hlWrap{ --hl-stage-h:calc(100vh - calc(48px * var(--md-s))); }
  @supports (height:100dvh){ #hlWrap{ --hl-stage-h:calc(100dvh - calc(48px * var(--md-s))); } }

  /* composer (profile pic, comment bar, send) stays hidden until you scroll into the comments */
  .hl-cm-composer{ opacity:0; visibility:hidden; pointer-events:none; transition:opacity .15s ease; }
  #hlComments.cm-on .hl-cm-composer{ opacity:1; visibility:visible; pointer-events:auto; }

  /* no scrollbar at all on responsive, and no highlight arrows */
  #hlScroll{ scrollbar-width:none; -ms-overflow-style:none; }
  #hlScroll::-webkit-scrollbar{ display:none; width:0; height:0; }
  .hl-nav{ display:none!important; }
  #hlComments{ --cm-px:16px; --csav:32px; --cm-fs:calc(13.5px * var(--md-s)); --cm-meta:11.5px; }
  /* top bar reaches the very top edge (also under a phone notch) and stays solid */
  .hl-cm-top{ padding-top:env(safe-area-inset-top,0px); }
}
@media(max-width:600px){
  .hl-cap-wrap{ padding-left:calc(22px * var(--md-s)); padding-right:calc(22px * var(--md-s)); }
  .hl-poll{ padding-left:calc(24px * var(--md-s)); padding-right:calc(24px * var(--md-s)); }
}
@media(max-width:490px){
  #hlComments{ --cm-px:14px; --csav:30px; --csgap:calc(9px * var(--md-s)); --cm-fs:calc(13px * var(--md-s)); --cm-meta:11px; }
  .hl-sub .hl-ci{ --csav:26px; --csgap:calc(8px * var(--md-s)); }
  .hl-avatar{ width:calc(35px * var(--md-s)); height:calc(35px * var(--md-s)); border-radius:calc(9px * var(--md-s)); }
  .hl-pod{ font-size:calc(12.5px * var(--md-s)); } .hl-who{ font-size:calc(11px * var(--md-s)); } .hl-date{ font-size:calc(10px * var(--md-s)); }
  .hl-icon-btn{ --hl-ic:19px; }
  .hl-act i{ font-size:calc(22px * var(--md-s)); } .hl-act{ font-size:calc(11.5px * var(--md-s)); }
  .hl-bar{ height:calc(36px * var(--md-s)); } .hl-fake{ font-size:calc(12px * var(--md-s)); }
  .hl-cap{ font-size:calc(14px * var(--md-s)); } .hl-pq{ font-size:calc(16px * var(--md-s)); }
  .hl-ci-r2{ gap:calc(15px * var(--md-s)); }
}
@media(max-width:390px){
  #hlComments{ --cm-px:12px; --csav:28px; --csgap:calc(8px * var(--md-s)); --cm-fs:calc(12.5px * var(--md-s)); --cm-meta:10.5px; }
  .hl-sub .hl-ci{ --csav:24px; --csgap:calc(7px * var(--md-s)); }
  .hl-avatar{ width:calc(32px * var(--md-s)); height:calc(32px * var(--md-s)); }
  .hl-count{ font-size:calc(9px * var(--md-s)); }
  .hl-bottom{ gap:calc(10px * var(--md-s)); padding-left:calc(10px * var(--md-s)); padding-right:calc(10px * var(--md-s)); }
  .hl-bar{ height:calc(34px * var(--md-s)); }
  .hl-po{ font-size:calc(13px * var(--md-s)); padding:calc(10px * var(--md-s)) calc(12px * var(--md-s)); }
  .hl-sheet-item{ font-size:calc(12px * var(--md-s)); }
  .hl-cm-hb{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); font-size:calc(18px * var(--md-s)); }
  .hl-cmy{ width:calc(28px * var(--md-s)); height:calc(28px * var(--md-s)); }
  .hl-send{ width:calc(32px * var(--md-s)); height:calc(32px * var(--md-s)); }
}
@media(max-width:330px){
  #hlComments{ --cm-px:10px; --csav:26px; --csgap:calc(7px * var(--md-s)); --cm-fs:calc(12px * var(--md-s)); --cm-meta:10px; }
  .hl-avatar{ width:calc(29px * var(--md-s)); height:calc(29px * var(--md-s)); border-width:2px; border-radius:calc(8px * var(--md-s)); }
  .hl-icon-btn{ --hl-ic:17px; width:calc(28px * var(--md-s)); height:calc(28px * var(--md-s)); }
  .hl-act i{ font-size:calc(20px * var(--md-s)); }
  .hl-pod{ font-size:calc(12px * var(--md-s)); }
  .hl-cm-note{ font-size:calc(10.5px * var(--md-s)); }
}
@media(prefers-reduced-motion:reduce){ #hlModal *{ animation-duration:.01ms!important; } }

/* ── LIGHT MODE: the comments section (everything under the video) follows the page theme ── */
/* theme_css.php forces #hlModal and everything in it to the always-dark colour set (white --fg / --text). The video part keeps that;
   the comments part sits on the page-coloured lane, so it gets the light set back (otherwise white text on white). */
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
:root[data-theme="light"] .hl-cm-sortmenu button.active{ color:#2459B8; }
:root[data-theme="light"] .hl-cm-menu button.danger{ color:#d62f2f; }
:root[data-theme="light"] .hl-cm-hb{ background:rgba(var(--fg),.07); color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-cm-hb:hover{ background:rgba(var(--fg),.14); }
:root[data-theme="light"] .hl-cm-title, .hl-cm-title b{ color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-cm-title i{ color:#2459B8; }
:root[data-theme="light"] .hl-cm-note{ background:rgba(var(--fg),.045); border-color:rgba(var(--fg),.1); color:rgba(var(--fg),.86); }
:root[data-theme="light"] .hl-cm-note i{ color:#2459B8; }
:root[data-theme="light"] .hl-ci-un{ color:rgba(var(--fg),.82); }
:root[data-theme="light"] .hl-ci-t{ color:rgba(var(--fg),.5); }
:root[data-theme="light"] .hl-ci-more{ color:rgba(var(--fg),.5); }
:root[data-theme="light"] .hl-ci-more:hover{ color:rgb(var(--fg)); background:rgba(var(--fg),.08); }
:root[data-theme="light"] .hl-ci-tx{ color:rgba(var(--fg),.92); }
:root[data-theme="light"] .hl-rm, .hl-ci-sm{ color:rgba(var(--fg),.7); }
:root[data-theme="light"] .hl-rm:hover, .hl-ci-sm:hover, .hl-ci-reply:hover{ color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-mention{ color:#2459B8; }
:root[data-theme="light"] .hl-link{ color:#2459B8; }
:root[data-theme="light"] .hl-ci-like, .hl-ci-dis{ color:rgba(var(--fg),.85); }
:root[data-theme="light"] .hl-ci-like.on, .hl-ci-dis.on, .hl-crl-b{ color:#2459B8; }
:root[data-theme="light"] .hl-ci-reply{ color:rgba(var(--fg),.55); }
:root[data-theme="light"] .hl-ci-tg{ color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-ci-line{ background:rgba(var(--fg),.14); }
:root[data-theme="light"] .hl-cm-empty{ color:rgba(var(--fg),.55); }
:root[data-theme="light"] .hl-cm-empty i{ color:#2459B8; }
:root[data-theme="light"] .hl-cm-spin{ border-color:rgba(var(--fg),.15); border-top-color:#2459B8; }
:root[data-theme="light"] .hl-sk{ background:linear-gradient(90deg,rgba(var(--fg),.05) 25%,rgba(var(--fg),.12) 50%,rgba(var(--fg),.05) 75%); background-size:200% 100%; }
:root[data-theme="light"] .hl-reply-chip{ color:rgba(var(--fg),.75); background:rgba(43,99,209,.1); }
:root[data-theme="light"] .hl-reply-x{ background:rgba(var(--fg),.1); color:rgb(var(--fg)); }
:root[data-theme="light"] .hl-reply-x:hover{ background:rgba(var(--fg),.2); }
:root[data-theme="light"] .hl-send:disabled{ background:rgba(var(--fg),.08); color:rgba(var(--fg),.4); }
:root[data-theme="light"] .hl-nocm{ color:rgba(var(--fg),.6); }
:root[data-theme="light"] .hl-cm-composer{ border-top:1px solid rgba(var(--fg),.08); }

/* verified badge next to a commenter's name (red) */
/* the row aligns its items on the text baseline: the badge sits on that baseline, dropped a hair like a letter with a tail, so it lines up with the name */
#hlCmList .hl-ci-l1 > .dz-vb{ width:calc(var(--cm-fs) + .5px); height:calc(var(--cm-fs) + .5px); margin:0 calc(5px * var(--md-s)) calc(var(--cm-fs) * -.16) calc(-3px * var(--md-s)); color:#FF2D2D; flex-shrink:0; align-self:baseline; }

</style>
<style>
/* ═══ one screen again: comments + menu are bottom sheets in the profile's .dzm look ═══ */
#hlModal.open{ display:flex; align-items:center; justify-content:center; }
#hlScroll{ overflow:hidden; display:flex; align-items:center; justify-content:center; }
#hlWrap{ height:100%; margin:0; background:none; }
#hlStage{ height:100%; }
.hl-nav{ top:50%; }
@media(min-width:791px){
  #hlWrap{ height:92vh; max-height:940px; margin:0; --hl-stage-h:100%; }
  #hlStage{ border-radius:calc(8px * var(--md-s)); }
}
/* same proportions as the Zeems box (zeems_modal.php): desktop = tall box, 95% of the screen height, width 68% of that height, 10px corners; phone = full screen up to 480px wide */
@media(min-width:791px){
  #hlWrap{ --hl-h:min(95vh,1000px); height:var(--hl-h); width:calc(var(--hl-h) * .68); max-width:none; border-radius:10px; }
  #hlStage{ border-radius:10px; clip-path:inset(0 round 10px); -webkit-clip-path:inset(0 round 10px); }
}
#hlCmOv{ position:absolute; inset:0; z-index:10; background:rgba(0,0,0,.55); opacity:0; pointer-events:none; transition:opacity .25s ease; }
#hlCmOv.show{ opacity:1; pointer-events:all; }
#hlSheet, #hlComments{
  background:var(--lane,#070924); border:none; border-radius:0; box-shadow:none;
  padding:calc(5px * var(--bs-s,1)) calc(10px * var(--bs-s,1)) max(calc(10px * var(--bs-s,1)),env(safe-area-inset-bottom));
  display:flex; flex-direction:column; gap:calc(8px * var(--bs-s,1));
  font-family:'Poppins',sans-serif;
}
#hlSheet{ max-height:60%; }
#hlComments{
  position:absolute; left:0; right:0; bottom:0; top:auto; z-index:11; min-height:0; height:min(76%,620px);
  overflow:hidden; transform:translateY(105%); visibility:hidden;
  transition:transform .28s cubic-bezier(.22,1,.36,1), visibility 0s linear .28s;
}
#hlComments.show{ transform:translateY(0); visibility:visible; transition:transform .28s cubic-bezier(.22,1,.36,1), visibility 0s; }
#hlComments .hl-cm-top{ position:relative; flex:0 0 auto; padding-top:0; background:transparent; }
#hlComments .hl-cm-head{ padding:0 0 calc(2px * var(--bs-s,1)) calc(4px * var(--bs-s,1)); }
#hlComments .hl-cm-title{ font-size:calc(8.5px * var(--bs-s,1)); font-weight:400; letter-spacing:1px; text-transform:uppercase; color:rgba(var(--fg,255,255,255),.45); }
#hlComments .hl-cm-title i{ display:none; }
#hlComments .hl-cm-pill{ font-size:calc(8.5px * var(--bs-s,1)); }
#hlComments .hl-cm-note{ flex:0 0 auto; margin-left:0; margin-right:0; }
#hlComments #hlCmScroll{ flex:1 1 auto; min-height:0; overflow-y:auto; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; background:var(--surface-3,#090b29); border-radius:calc(6px * var(--bs-s,1)); }
#hlComments #hlCmList{ flex:none; }
#hlComments .hl-cm-composer{ position:relative; flex:0 0 auto; opacity:1 !important; visibility:visible !important; pointer-events:auto !important; background:transparent; padding-left:0; padding-right:0; }
.dzm-handle{ display:flex; justify-content:center; padding:2px 0; flex-shrink:0; }
.dzm-handle span{ width:calc(30px * var(--bs-s,1)); height:calc(3px * var(--bs-s,1)); border-radius:2px; background:rgba(var(--fg,255,255,255),.22); display:block; }
#hlSheet .dzm-card{ background:var(--surface-3,#090b29); border-radius:calc(6px * var(--bs-s,1)); overflow:hidden; flex-shrink:1; min-height:0; display:flex; flex-direction:column; }
#hlSheet .dzm-scroll{ overflow-y:auto; min-height:0; }
#hlSheet .dzm-header{ padding:calc(7px * var(--bs-s,1)) calc(12px * var(--bs-s,1)) calc(3px * var(--bs-s,1)); font-size:calc(8.5px * var(--bs-s,1)); color:rgba(var(--fg,255,255,255),.35); letter-spacing:1px; text-transform:uppercase; }
#hlSheet .dzm-close-btn{ display:flex; align-items:center; justify-content:center; width:100%; padding:calc(10px * var(--bs-s,1)); background:var(--surface-3,#090b29); border:none; border-radius:calc(6px * var(--bs-s,1)); color:rgba(var(--fg,255,255,255),.85); font-size:calc(12px * var(--bs-s,1)); font-weight:600; cursor:pointer; flex-shrink:0; }
#hlSheet .mr-row{ display:flex; align-items:center; gap:calc(9px * var(--bs-s,1)); padding:calc(6px * var(--bs-s,1)) calc(12px * var(--bs-s,1)); cursor:pointer; border-top:1px solid rgba(var(--fg,255,255,255),.08); color:inherit; text-decoration:none; -webkit-tap-highlight-color:transparent; }
#hlSheet .mr-row:first-child{ border-top:none; }
#hlSheet .mr-row:active{ background:rgba(var(--fg,255,255,255),.06); }
#hlSheet .mr-icon{ width:calc(27px * var(--bs-s,1)); height:calc(27px * var(--bs-s,1)); border-radius:50%; flex-shrink:0; border:1.5px dashed rgba(var(--fg,255,255,255),.3); display:flex; align-items:center; justify-content:center; font-size:calc(14px * var(--bs-s,1)); color:rgba(var(--fg,255,255,255),.6); }
#hlSheet .mr-icon i{ font-size:inherit; color:inherit; width:auto; }
#hlSheet .mr-body{ flex:1; min-width:0; }
#hlSheet .mr-label{ font-size:calc(11.5px * var(--bs-s,1)); font-weight:600; color:var(--text,#F0F2FF); }
#hlSheet .mr-label.red{ color:#FF2D2D; }
#hlSheet .mr-sub{ font-size:calc(9px * var(--bs-s,1)); color:rgba(var(--fg,255,255,255),.4); margin-top:1px; line-height:1.35; }
#hlSheet .mr-state-badge{ margin-left:auto; flex-shrink:0; font-size:calc(9.5px * var(--bs-s,1)); font-weight:700; padding:calc(3px * var(--bs-s,1)) calc(8px * var(--bs-s,1)); border-radius:4px; background:rgba(255,45,45,.1); color:#FF2D2D; }
:root[data-theme="light"] #hlModal #hlSheet,
:root[data-theme="light"] #hlModal #hlSheet *{ --text:#0b0d24; --fg:6,7,26; --ui-text:#0b0d24; --ui-sub:#52566a; --muted:rgba(0,0,0,.5); }
:root[data-theme="light"] #hlModal #hlSheet .dzm-close-btn{ color:#0b0d24; }
:root[data-theme="light"] #hlModal .dzm-handle span{ background:rgba(6,7,26,.22); }
:root[data-theme="light"] #hlModal #hlComments .hl-cm-title{ color:rgba(6,7,26,.5); }
/* like / comment: the immersive viewer's outline icons (red + filled heart when liked) */
#hlModal .hl-bottom .hl-ab{ gap:calc(6px * var(--md-s)); color:#fff; filter:drop-shadow(0 2px 5px rgba(0,0,0,.55)); }
#hlModal .hl-bottom .hl-ab.on{ color:var(--red,#FF2D2D); }
#hlModal .hl-bottom .hl-ab.on .sp-fillable svg{ fill:currentColor; }
#hlModal .hl-bottom .hl-ab .ipv-action-count{ color:#fff; text-shadow:none; }
#hlModal .hl-bottom .hl-ab.on .ipv-action-count{ color:#fff; }
.hl-ab i.pop{ animation:hlPop .38s ease; }
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
#hlComments .hl-ci-av{ width:var(--csav); height:var(--csav); border:2px solid var(--cs-red); box-sizing:border-box; background:var(--cs-chip); }
#hlComments .hl-ci-line{ background:var(--cs-thread); margin-top:.5em; }
#hlComments .hl-ci-body{ position:relative; }
#hlComments .hl-ci-un{ font-size:.92em; font-weight:600; color:var(--cs-tx); }
#hlComments .hl-ci-t{ font-size:.92em; color:var(--cs-sub); }
#hlComments .hl-ci-more{ color:var(--cs-sub); }
#hlComments .hl-ci-more:hover{ color:var(--cs-tx); background:var(--cs-chip); }
#hlComments .hl-ci-tx{ font-size:1em; line-height:1.35; color:var(--cs-tx); margin-top:.15em; }
#hlComments .hl-mention{ color:var(--cs-red); font-weight:600; }
#hlComments .hl-link{ color:var(--cs-mention); }
#hlComments .hl-rm{ color:var(--cs-red); font-weight:600; }
#hlComments .hl-ci-r2{ margin-top:.3em; gap:1.1em; font-size:.92em; color:var(--cs-sub); }
#hlComments .hl-ci-like, #hlComments .hl-ci-dis, #hlComments .hl-ci-reply{ color:var(--cs-sub); font-size:1em; }
#hlComments .hl-ci-like.on, #hlComments .hl-ci-dis.on{ color:#4d86f0; }
#hlComments .hl-ci-dis:hover, #hlComments .hl-ci-reply:hover{ color:var(--cs-tx); }
#hlComments .hl-ci-dis.on{ color:var(--cs-tx); }
#hlComments .hl-ci-tg{ height:2.4em; margin-left:calc(var(--csav) + var(--csgap)); gap:.55em; color:var(--cs-tx); font-weight:600; font-size:1em; }
#hlComments .hl-ci-tg::before{ left:calc(-1 * (var(--csav) / 2 + var(--csgap) + 1px)); width:calc(var(--csav) / 2 + var(--csgap) + 1px); border-left:2px solid var(--cs-thread); border-bottom:2px solid var(--cs-thread); border-bottom-left-radius:.9em; }
#hlComments .hl-ci-tgav{ width:1.7em; height:1.7em; border:1.5px solid var(--cs-red); box-sizing:border-box; background:var(--cs-chip); }
#hlComments .hl-ci-tgd{ opacity:.6; }
#hlComments .hl-ci-chev{ font-size:1.2em; }
#hlComments .hl-sub{ margin-left:calc(var(--csav) + var(--csgap)); }
#hlComments .hl-ci-sm{ color:var(--cs-sub); font-size:.88em; font-weight:600; }
#hlComments .hl-ci-sm:hover{ color:var(--cs-tx); }
#hlComments .hl-cm-composer{ background:transparent; border-top:1px solid var(--cs-line); padding:.6em 1em max(.9em,env(safe-area-inset-bottom)); }
#hlComments .hl-compose-row{ display:flex; align-items:center; gap:.7em; }
#hlComments .hl-cmy{ width:2.75em; height:2.75em; border:2px solid var(--cs-red); box-sizing:border-box; background:var(--cs-chip); }
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





<script>
(function(){
  'use strict';
  var GID = gfGroupId, ME = gfCurrentUserId;
  var IMG_MS = 120000;  // image / text / poll slide stays 2 minutes before auto-advance
  var HL_TEXT_BG   = <?php echo json_encode($gp_hl_text_bg ?? 'Text.jpg'); ?>;
  var POD_NAME     = <?php echo json_encode((string)($group['title'] ?? ''), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE); ?>;
  var POD_PIC      = <?php echo json_encode((string)($img_src ?? 'uploads/default_group.png'), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE); ?>;
  var HL_USER_URL  = 'profile.php?user_id=';
  var HL_GROUP_URL = 'group.php?id=';

  /* one-shot image fallback: an inline picture, so a missing file can never retry forever */
  var FB_IMG = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" fill="#0d1030"/><circle cx="20" cy="15" r="7" fill="#2b3a7a"/><path d="M6 36c2-8 8-11 14-11s12 3 14 11z" fill="#2b3a7a"/></svg>');
  function imgFail(im){ im.onerror = null; im.src = FB_IMG; }
  window.hlImgFail = imgFail;

  var ids = [], hi = 0, mi = 0, C = {}, isOpen = false, openTok = 0, errMsg = '';
  var raf = null, elapsed = 0, lastTs = null, renderTok = 0;
  var capOpen = false, offscreen = false, voting = false, pushed = false, ioDone = false, liking = false;
  var flip = null, flipping = false, mediaOK = false, readyCb = null;
  var aud = null, audSrc = '', soundOn = true;      // the audio element is only created when a song actually plays
  var CM = { fid:0, items:[], map:{}, more:false, loading:false, posting:false, reply:null, sort:'top', offset:0, menuFor:0, seq:0 };
  var SORT_LABELS = { top:'Top', 'new':'Newest', old:'Oldest' };

  function $(id){ return document.getElementById(id); }
  function esc(s){
    return String(s==null?'':s).replace(/[&<>"']/g,function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function fmtNum(n){ n=+n||0; return n>=1e6?(n/1e6).toFixed(1).replace(/\.0$/,'')+'M':n>=1e3?(n/1e3).toFixed(1).replace(/\.0$/,'')+'K':String(n); }
  function ago(iso){
    var d=new Date(iso); if(isNaN(d)) return '';
    var s=(Date.now()-d.getTime())/1000;
    if(s<60) return 'now'; if(s<3600) return Math.floor(s/60)+'m'; if(s<86400) return Math.floor(s/3600)+'h';
    if(s<604800) return Math.floor(s/86400)+'d'; if(s<2592000) return Math.floor(s/604800)+'w';
    var M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate()+' '+M[d.getMonth()]+' '+d.getFullYear();
  }
  function toast(msg){
    var old=$('hlModal').querySelector('.hl-toast'); if(old) old.remove();
    var t=document.createElement('div'); t.className='hl-toast'; t.textContent=msg;
    $('hlModal').appendChild(t);
    setTimeout(function(){t.style.opacity='0'; setTimeout(function(){t.remove();},300);},2000);
  }
  function badge(role){
    if(role==='owner') return '<span class="hl-bdg owner">Owner</span>';
    if(role==='admin') return '<span class="hl-bdg admin">Admin</span>';
    if(role==='mod' || role==='moderator') return '<span class="hl-bdg admin">Mod</span>';
    return '';
  }
  function api(action,data){
    var fd=new FormData(); fd.append('action',action); fd.append('csrf_token',CSRF_TOKEN);
    Object.keys(data||{}).forEach(function(k){ fd.append(k,data[k]); });
    return fetch('group_highlight_api.php?id='+encodeURIComponent(GID),{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();}).catch(function(){ return {success:false,msg:'Network error. Try again.'}; });
  }
  function cur(){ return C[ids[hi]]; }
  function poke(){ elapsed = 0; }

  /* ══════════════════ data ══════════════════ */
  function loadH(id){
    return api('get',{highlight_id:id}).then(function(d){
      if(!d.success||!d.h){ errMsg=d.msg||''; return null; }
      C[id]=d.h; return d.h;
    });
  }
  function ensure(idx){ var id=ids[idx]; return C[id] ? Promise.resolve(C[id]) : loadH(id); }
  function prefetch(){
    [hi+1,hi-1].forEach(function(i){
      if(i<0||i>=ids.length||C[ids[i]]) return;
      loadH(ids[i]).then(function(h){ if(h&&h.media[0]&&h.media[0].type==='image'){ var im=new Image(); im.src=h.media[0].path; } });
    });
  }

  /* ══════════════════ prev / next highlight arrows (shown on normal view only, CSS hides them on responsive) ══════════════════ */
  function updateNav(){
    var p=$('hlNavPrev'), n=$('hlNavNext');
    var noPrev = hi<=0, noNext = hi>=ids.length-1;      // first → no left arrow, last → no right arrow
    p.classList.toggle('off',noPrev); p.disabled=noPrev;
    n.classList.toggle('off',noNext); n.disabled=noNext;
  }
  window.hlGoHl=function(dir){
    if(window.dzHp) dzHp('light');
    if(!cur()||flipping||$('hlSheet').classList.contains('show')) return;
    var idx=hi+dir; if(idx<0||idx>=ids.length) return;
    autoFlip(dir,dir>0?0:'last');
  };

  /* ══════════════════ TEXT ON MEDIA (@user, group names, more / less) ══════════════════ */
  function richText(text, map){
    text=String(text||''); map=map||{};
    var users=map.users||{}, groups=(map.groups||[]).slice().sort(function(a,b){return b.name.length-a.name.length;}), gid={};
    groups.forEach(function(g){ gid[g.name.toLowerCase()]=g.id; });
    var re='(@[A-Za-z0-9_.]+)';
    if(groups.length) re+='|(^|[^\\w@])('+groups.map(function(g){return g.name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');}).join('|')+')(?![\\w])';
    var rx=new RegExp(re,'gi'), out='', last=0, m;
    while((m=rx.exec(text))!==null){
      if(m[1]){
        var core=m[1].slice(1).replace(/\.+$/,''), id=users[core.toLowerCase()]; if(!id) continue;
        out+=esc(text.slice(last,m.index))+'<a class="hl-men user" href="'+esc(HL_USER_URL+id)+'">@'+esc(core)+'</a>';
        last=m.index+1+core.length; rx.lastIndex=last;
      } else {
        var start=m.index+m[2].length, gi=gid[m[3].toLowerCase()]; if(!gi) continue;
        out+=esc(text.slice(last,start))+'<a class="hl-men group" href="'+esc(HL_GROUP_URL+gi)+'">'+esc(m[3])+'</a>';
        last=start+m[3].length;
      }
    }
    return (out+esc(text.slice(last))).replace(/\n/g,'<br>');
  }
  function capHTML(text,map){
    var long=text.length>120||(text.match(/\n/g)||[]).length>=3;
    return '<div class="hl-cap-wrap"><div class="hl-cap'+(long?' clamp':'')+'">'+richText(text,map)+'</div>'+
      (long?'<button type="button" class="hl-more" onclick="hlMore(this)">more</button>':'')+'</div>';
  }
  window.hlMore=function(btn){
    var cap=btn.previousElementSibling, collapsed=cap.classList.toggle('clamp');
    btn.textContent=collapsed?'more':'less';
    capOpen=!collapsed; poke(); syncVideo();
  };

  /* ══════════════════ POLL SLIDE ══════════════════ */
  function pollHTML(h,m){
    var p=m.poll;
    if(!p) return '<div class="hl-poll"><div class="hl-pq">This poll is unavailable</div></div>';
    var mine=p.mine||[];
    var opts=p.options.map(function(o){
      var on=mine.indexOf(o.id)>-1, pct=(p.show&&p.total>0&&o.votes!=null)?Math.round(o.votes/p.total*100):0;
      return '<button type="button" class="hl-po'+(on?' mine':'')+'" data-vote="'+o.id+'"><i class="bar" style="width:'+pct+'%"></i>'+
        '<span class="lb">'+(on?'<i class="ri-checkbox-circle-fill"></i> ':'')+esc(o.label)+'</span>'+(p.show?'<span class="pc">'+pct+'%</span>':'')+'</button>';
    }).join('');
    var meta=[p.multi?'Pick several':'Pick one']; if(p.total!=null) meta.push(p.total+' voter'+(p.total===1?'':'s'));
    meta.push(p.closed?'Ended':(p.closes?'Ends '+p.closes:'No end time'));
    var hint='';
    if(!h.perm.vote) hint=h.perm.vote_msg;
    else if(!p.show&&!p.closed) hint=p.results==='after_close'?'Results appear when the poll ends':'Vote to see the results';
    return '<div class="hl-poll"><div class="hl-ptag"><i class="ri-bar-chart-horizontal-fill"></i> Poll</div><div class="hl-pq">'+esc(p.q)+'</div>'+opts+
      '<div class="hl-pm">'+esc(meta.join(' · '))+'</div>'+(hint?'<div class="hl-ph">'+esc(hint)+'</div>':'')+'</div>';
  }
  function doVote(btn){
    var h=cur(), m=h.media[mi]; if(!m||!m.poll||voting) return;
    var p=m.poll;
    if(p.closed){ toast('This poll has ended'); return; }
    if(!h.perm.vote){ toast(h.perm.vote_msg); return; }
    voting=true; poke();
    api('vote',{poll_id:p.id,option_id:btn.getAttribute('data-vote')}).then(function(d){
      voting=false;
      if(!d.success){ toast(d.msg||'Could not vote'); return; }
      m.poll=d.poll;
      if(cur()===h && h.media[mi]===m){ $('hlCapLayer').innerHTML=pollHTML(h,m); poke(); }
    });
  }

  /* ══════════════════ SMART OBJECT-FIT ══════════════════ */
  function hlFixFit(el){
    var box=el.parentNode; if(!box) return;
    var mW=el.tagName==='VIDEO'?el.videoWidth:el.naturalWidth, mH=el.tagName==='VIDEO'?el.videoHeight:el.naturalHeight;
    if(!mW||!mH) return;
    var cW=box.clientWidth, cH=box.clientHeight; if(!cW||!cH) return;
    var mediaAspect=mW/mH, boxAspect=cW/cH;
    if(mediaAspect<boxAspect){
      var widthFill=mediaAspect/boxAspect;
      el.style.objectFit=(widthFill>=0.55)?'fill':'contain'; return;
    }
    var fillRatio=(cW/mediaAspect)/cH;
    el.style.objectFit=(fillRatio>=0.84)?'fill':'contain';
  }
  window.addEventListener('resize',function(){ if(!isOpen) return; var el=$('hlMedia').querySelector('.hl-media-item'); if(el) hlFixFit(el); });

  /* ══════════════════ pause state + sound ══════════════════ */
  function getAud(){ if(!aud){ aud=new Audio(); aud.preload='auto'; } return aud; }
  function isPaused(){
    var a=document.activeElement;
    return flipping || capOpen || offscreen || document.hidden || $('hlSheet').classList.contains('show') || (a && a.id==='hlCIn');
  }
  function syncVideo(){
    var paused=isPaused(), v=$('hlMedia').querySelector('video');
    if(v){ if(paused) v.pause(); else v.play().catch(function(){}); }
    if(aud&&audSrc){ if(paused) aud.pause(); else aud.play().catch(function(){}); }
  }
  function stopAudio(){
    audSrc='';
    if(!aud) return;
    aud.pause(); aud.removeAttribute('src'); try{aud.load();}catch(e){}
  }
  function playSong(path,loop,restart){
    if(!path){ if(audSrc) stopAudio(); return; }
    var a=getAud(), changed=audSrc!==path;
    if(changed){ a.src=path; audSrc=path; }
    if(changed||restart){ try{a.currentTime=0;}catch(e){} }
    a.loop=loop; a.muted=!soundOn;
    if(!isPaused()) a.play().catch(function(){});
  }
  function updateSoundBtn(m){
    var has=!!audSrc||(m.type==='video'&&!m.mute);
    var b=$('hlSnd'); b.style.display=has?'':'none';
    b.querySelector('i').className=soundOn?'ri-volume-up-fill':'ri-volume-mute-fill';
  }
  window.hlSound=function(){
    soundOn=!soundOn; if(aud) aud.muted=!soundOn;
    var m=cur().media[mi], v=$('hlMedia').querySelector('video');
    if(v) v.muted=!soundOn||!!m.mute;
    updateSoundBtn(m);
  };
  $('hlStage').addEventListener('pointerdown',function(){ if(aud&&audSrc&&aud.paused&&!isPaused()) aud.play().catch(function(){}); },{passive:true});
  document.addEventListener('visibilitychange',function(){ if(isOpen) syncVideo(); });

  /* the box pauses when you scroll down to the comments; on responsive the composer only shows while you're in the comments */
  function setupObserver(){
    return;
    if(ioDone||!('IntersectionObserver' in window)) return;
    ioDone=true;
    new IntersectionObserver(function(es){
      es.forEach(function(e){
        offscreen=e.intersectionRatio<0.5;
        $('hlComments').classList.toggle('cm-on',offscreen);
        if(isOpen) syncVideo();
      });
    },{root:$('hlScroll'),threshold:[0,.25,.5,.75,1]}).observe($('hlStage'));
  }

  /* ══════════════════ open / close ══════════════════ */
  function hostPause(){
    document.querySelectorAll('#groupFeed video').forEach(function(v){ v.pause(); });
    var ga=document.getElementById('gpAudioPlayer'); if(ga) ga.pause();
  }
  window.openGroupHighlightsModal=function(id){
    if(isOpen) return;
    id=parseInt(id,10); if(!id) return;
    var tiles=Array.prototype.map.call(document.querySelectorAll('#ghStrip .gh-tile'),function(t){return parseInt(t.getAttribute('data-hl-id'),10);}).filter(Boolean);
    var pos=tiles.indexOf(id); if(pos<0){ tiles=[id]; pos=0; }
    ids=tiles; hi=pos; mi=0; isOpen=true; offscreen=false; flipping=false; capOpen=false; errMsg='';
    var m=$('hlModal');
    m.classList.add('open'); void m.offsetHeight; m.classList.add('vis');
    document.body.style.overflow='hidden';
    cmSheetReset();
    $('hlLoader').classList.add('show');
    updateNav();
    resetCm(); renderCompose(null); hostPause(); setupObserver();
    try{ history.pushState({hl:1},'',location.href); pushed=true; }catch(e){ pushed=false; }
    var tok=++openTok;
    ensure(hi).then(function(h){
      if(!isOpen||tok!==openTok) return;
      if(!h){ toast(errMsg||'This highlight is unavailable'); setTimeout(close,1100); return; }
      $('hlLoader').classList.remove('show');
      render(true);
    });
  };
  function closeNow(){
    if(!isOpen) return;
    var _in=$('hlIntro'); if(_in) _in.classList.remove('show');
    isOpen=false; openTok++; renderTok++;
    cleanupFlip(); stopMedia(); stopAudio(); hlCloseSheet(); cmSheetReset(); closeMenu(); $('hlCmSortMenu').classList.remove('show');
    capOpen=false; flipping=false;
    var a=document.activeElement; if(a&&a.blur) a.blur();
    var m=$('hlModal'); m.classList.remove('vis');
    setTimeout(function(){
      if(isOpen) return;
      m.classList.remove('open'); $('hlMedia').innerHTML=''; $('hlCapLayer').innerHTML=''; $('hlLoader').classList.remove('show');
    },150);
    document.body.style.overflow='';
    window.dispatchEvent(new Event('scroll'));
  }
  function close(){
    if(!isOpen) return;
    if(pushed){ pushed=false; try{history.back();}catch(e){} setTimeout(closeNow,350); return; }
    closeNow();
  }
  window.hlClose=close;
  window.addEventListener('popstate',function(){ if(isOpen){ pushed=false; closeNow(); } });

  function stopMedia(){
    if(raf){ cancelAnimationFrame(raf); raf=null; }
    var v=$('hlMedia').querySelector('video');
    if(v){ v.pause(); v.removeAttribute('src'); try{v.load();}catch(e){} }
  }

  /* ══════════════════ progress loop ══════════════════ */
  function runProgress(fill,video){
    elapsed=0; lastTs=null; fill.style.width='0%';
    function tick(ts){
      if(lastTs==null) lastTs=ts;
      var p;
      if(video){ p=video.duration?video.currentTime/video.duration:0; }
      else { if(!isPaused()) elapsed+=ts-lastTs; p=elapsed/IMG_MS; }
      lastTs=ts;
      fill.style.width=Math.min(100,p*100)+'%';
      if(!video&&p>=1){ raf=null; hlNext(); return; }
      raf=requestAnimationFrame(tick);
    }
    raf=requestAnimationFrame(tick);
  }
  function markReady(){ mediaOK=true; if(readyCb){ var c=readyCb; readyCb=null; c(); } }

  /* ══════════════════ render current highlight + media ══════════════════ */
  /* the highlight's own name (what the strip tile shows) — used instead of the group name in the header and for the intro */
  function hlName(h){
    if(h && h.title) return String(h.title);
    var id = (h && h.id) ? h.id : ids[hi];
    var t = document.querySelector('#ghStrip .gh-tile[data-hl-id="'+id+'"] .gh-name');
    return t ? t.textContent : POD_NAME;
  }
  function showIntro(h){
    var el=$('hlIntro'), tx=$('hlIntroTxt');
    if(!el||!tx) return;
    el.classList.remove('show');
    var n=hlName(h); if(!n) return;
    tx.textContent=n;
    void el.offsetWidth;                      // restart the animation
    el.classList.add('show');
  }
  /* picture beside the highlight name = the highlight's cover: its cover photo if it has one, otherwise the first image,
     the first frame of the first video, or the default text image (text / poll). Same pick the strip tile on the page makes. */
  var avTok=0, frameCache={};
  function videoFrame(src,cb){
    if(frameCache[src]){ cb(frameCache[src]); return; }
    var v=document.createElement('video'); v.muted=true; v.playsInline=true; v.preload='auto';
    v.onloadeddata=function(){ try{ v.currentTime=0.1; }catch(e){} };
    v.onseeked=function(){
      try{ var c=document.createElement('canvas'); c.width=v.videoWidth||240; c.height=v.videoHeight||240; c.getContext('2d').drawImage(v,0,0,c.width,c.height); frameCache[src]=c.toDataURL('image/jpeg',.8); cb(frameCache[src]); }catch(e){}
      v.removeAttribute('src'); try{ v.load(); }catch(e){}
    };
    v.src=src;
  }
  function hlSetAvatar(av,h){
    var tok=++avTok, id=(h&&h.id)?h.id:ids[hi];
    function use(u){ if(tok===avTok&&u) av.src=u; }
    var tile=document.querySelector('#ghStrip .gh-tile[data-hl-id="'+id+'"] .gh-sq');
    var im=tile&&tile.querySelector('img'), vd=tile&&tile.querySelector('video');
    var m0=(h&&h.media&&h.media[0])||null;
    var imSrc=im?(im.getAttribute('src')||im.getAttribute('data-src')||''):'';
    if(imSrc){ use(imSrc); return; }                                   /* cover photo, first image, or the text image */
    var vSrc=vd?String(vd.getAttribute('src')||vd.getAttribute('data-src')||'').split('#')[0]:((m0&&m0.type==='video')?m0.path:'');
    if(vSrc){ use(HL_TEXT_BG); videoFrame(vSrc,use); return; }       /* first frame of the first video */
    if(m0&&m0.type==='image'&&m0.path){ use(m0.path); return; }
    use(HL_TEXT_BG);                                                   /* text / poll */
  }
  function paintHeader(h){
    var av=$('hlAvatar'); av.onerror=function(){ imgFail(av); }; hlSetAvatar(av,h);
    $('hlPod').textContent=hlName(h);
    var d=$('hlDate'); d.textContent=ago(h.created); d.title=new Date(h.created).toLocaleString();
    var w=$('hlWho'); w.textContent=h.by.name; w.href=HL_USER_URL+h.by.id;
    $('hlBadge').innerHTML=badge(h.by.role);
    $('hlBarTxt').textContent=h.perm.comment?'Add a comment…':'View comments';
    paintCounts(h);
  }
  function render(hChanged){
    stopMedia();
    var tok=++renderTok, h=cur(), m=h.media[mi];
    mediaOK=false; capOpen=false;
    if(hChanged){ paintHeader(h); showIntro(h); $('hlScroll').scrollTop=0; resetCm(); renderCompose(h); loadComments(true); prefetch(); }
    updateNav();
    $('hlCount').textContent=(mi+1)+'/'+h.media.length;
    paintLike(h);

    $('hlCapLayer').innerHTML = m.type==='poll' ? pollHTML(h,m) : (m.caption ? capHTML(m.caption,m.map) : '');

    /* music: one song across the highlight, or a song for each media */
    if(h.mode==='each') playSong(m.audio,false,true); else playSong(h.song,true,false);
    var sTitle=(h.mode==='each')?(m.audio?m.audio_title:''):(h.song?h.song_title:'');
    var chars=Array.from(String(sTitle||'').trim());
    if(chars.length>40) sTitle=chars.slice(0,40).join('').replace(/\s+$/,'')+'…';
    $('hlSong').textContent=sTitle?'♪ '+sTitle:'';

    var ds=''; h.media.forEach(function(x,i){ ds+='<div class="hl-dash'+(i<mi?' done':'')+'"><div class="hl-dash-fill"></div></div>'; });
    $('hlDashes').innerHTML=ds;
    var fill=$('hlDashes').children[mi].firstChild;

    var box=$('hlMedia'); box.innerHTML='';
    if(m.type==='video'){
      var v=document.createElement('video');
      v.className='hl-media-item'; v.setAttribute('playsinline','');
      box.appendChild(v);
      v.addEventListener('loadedmetadata',function(){ hlFixFit(v); });
      v.addEventListener('loadeddata',markReady);
      v.addEventListener('ended',function(){ if(tok===renderTok) hlNext(); });
      v.addEventListener('error',function(){ if(tok!==renderTok) return; markReady(); runProgress(fill,null); });
      v.src=m.path;
      v.muted=!!m.mute||!soundOn;
      if(!isPaused()) v.play().catch(function(){ v.muted=true; v.play().catch(function(){}); });
      runProgress(fill,v);
    } else if(m.type==='poll'){
      box.innerHTML='<div class="hl-pollbg"></div>';
      markReady(); runProgress(fill,null);
    } else {
      var im=document.createElement('img'); im.className='hl-media-item'; im.alt=''; im.draggable=false;
      box.appendChild(im);
      var begun=false;
      function begin(){ if(begun||tok!==renderTok) return; begun=true; hlFixFit(im); markReady(); runProgress(fill,null); }
      im.addEventListener('load',begin);
      im.onerror=function(){ im.onerror=null; im.src=HL_TEXT_BG; };
      im.src=(m.type==='text')?HL_TEXT_BG:m.path;
      if(im.complete&&im.naturalWidth) begin();
    }
    updateSoundBtn(m);
    preloadNext();
  }
  function preloadNext(){
    var h=cur(), n=h.media[mi+1];
    if(n&&n.type==='image'){ var i=new Image(); i.src=n.path; }
  }

  /* ══════════════════ 3D CUBE FLIP between highlights ══════════════════ */
  var cube=$('hlCube'), face=$('hlFace');
  function setCube(angle,W){ cube.style.transform='translateZ('+(-W/2)+'px) rotateY('+angle+'deg)'; }

  var MENU_SVG='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M2 6h20M2 12h20M2 18h20"/></svg>';
  function buildPeek(h,tmi){
    var m=h.media[tmi], body, layer='';
    if(m.type==='video') body='<video class="hl-media-item" src="'+esc(m.path)+'#t=0.1" muted playsinline preload="metadata"></video>';
    else if(m.type==='poll'){ body='<div class="hl-pollbg"></div>'; layer=pollHTML(h,m); }
    else body='<img class="hl-media-item" alt="" src="'+esc(m.type==='text'?HL_TEXT_BG:m.path)+'">';
    if(m.type!=='poll'&&m.caption) layer=capHTML(m.caption,m.map);
    var dashes=''; h.media.forEach(function(x,i){ dashes+='<div class="hl-dash'+(i<tmi?' done':'')+'"><div class="hl-dash-fill"></div></div>'; });
    var el=document.createElement('div'); el.className='hl-peek';
    el.innerHTML=
      '<div class="hl-peek-media">'+body+'</div>'+
      '<div class="hl-shade-top"></div><div class="hl-shade-bottom"></div>'+
      '<div class="hl-cap-layer">'+layer+'</div>'+
      '<div class="hl-top"><div class="hl-dashes">'+dashes+'</div>'+
        '<div class="hl-head"><div class="hl-av-col"><img class="hl-avatar" src="'+esc(POD_PIC)+'" alt="" onerror="hlImgFail(this)"></div>'+
        '<div class="hl-head-info"><div class="hl-title-row">'+
          '<div class="hl-pod-row"><span class="hl-pod">'+esc(hlName(h))+'</span><span class="hl-dot">•</span><span class="hl-date">'+esc(ago(h.created))+'</span></div>'+
          '<div class="hl-who-row"><span class="hl-who">'+esc(h.by.name)+'</span>'+badge(h.by.role)+'</div></div></div>'+
        '<span class="hl-icon-btn hl-menu">'+MENU_SVG+'</span>'+
        '<div class="hl-x-col"><span class="hl-icon-btn hl-x"><i class="ri-close-line"></i></span><span class="hl-count">'+(tmi+1)+'/'+h.media.length+'</span></div></div></div>'+
      '<div class="hl-bottom"><div class="hl-bar"><span class="hl-fake">'+(h.perm.comment?'Add a comment…':'View comments')+'</span></div>'+
        '<span class="ipv-action-btn hl-ab'+((h.media[tmi]||{}).liked?' on':'')+'"><i class="sp-rp sp-ic sp-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i><span class="ipv-action-count">'+((+(h.media[tmi]||{}).likes>0)?esc(fmtNum((h.media[tmi]||{}).likes)):'')+'</span></span>'+
        '<span class="ipv-action-btn hl-ab"><i class="sp-rp sp-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg></i><span class="ipv-action-count">'+((+h.comments>0)?esc(fmtNum(h.comments)):'')+'</span></span></div>';
    return el;
  }
  function flipBegin(dir,idx,tmi){
    var W=$('hlStage').clientWidth, h=C[ids[idx]];
    if(tmi==='last') tmi=h.media.length-1;
    var peek=buildPeek(h,tmi);
    flip={dir:dir,idx:idx,tmi:tmi,peek:peek,W:W};
    flipping=true;
    cube.classList.add('flip');
    cube.style.transition='none';
    face.style.transform='translateZ('+(W/2)+'px)';
    peek.style.transform='rotateY('+(dir*90)+'deg) translateZ('+(W/2)+'px)';
    cube.appendChild(peek);
    setCube(0,W);
    var mm=peek.querySelector('.hl-media-item');
    if(mm){
      if(mm.tagName==='VIDEO') mm.addEventListener('loadedmetadata',function(){ hlFixFit(mm); });
      else { mm.addEventListener('load',function(){ hlFixFit(mm); }); if(mm.complete&&mm.naturalWidth) hlFixFit(mm); }
    }
    syncVideo();
  }
  function flipMove(dx){
    if(!flip) return;
    var p=Math.max(0,Math.min(1,(-flip.dir*dx)/flip.W));
    setCube(-flip.dir*p*90,flip.W);
  }
  function cleanupFlip(){
    if(!flip){ flipping=false; return; }
    cube.style.transition='none'; cube.style.transform=''; cube.classList.remove('flip'); face.style.transform='';
    if(flip.peek.parentNode) flip.peek.parentNode.removeChild(flip.peek);
    flip=null; flipping=false;
    if(isOpen) syncVideo();
  }
  function flipEnd(commit){
    if(!flip) return;
    var f=flip, done=false;
    void cube.offsetWidth;
    cube.style.transition='transform .42s cubic-bezier(.22,.75,.28,1)';
    setCube(commit?-f.dir*90:0,f.W);
    function onEnd(e){ if(e.target===cube) fin(); }
    function fin(){
      if(done) return; done=true;
      cube.removeEventListener('transitionend',onEnd);
      if(flip!==f) return;
      if(!commit){ cleanupFlip(); return; }
      hi=f.idx; mi=f.tmi; readyCb=null;
      render(true);
      var settled=false;
      function settle(){ if(settled) return; settled=true; readyCb=null; requestAnimationFrame(cleanupFlip); }
      if(mediaOK) settle(); else { readyCb=settle; setTimeout(settle,450); }
    }
    cube.addEventListener('transitionend',onEnd);
    setTimeout(fin,560);
  }
  /* plays the whole turn. false = nothing in that direction */
  function autoFlip(dir,tmi){
    var idx=hi+dir;
    if(idx<0||idx>=ids.length) return false;
    if(C[ids[idx]]){ flipBegin(dir,idx,tmi); flipEnd(true); return true; }
    flipping=true; $('hlLoader').classList.add('show'); syncVideo();
    ensure(idx).then(function(h){
      $('hlLoader').classList.remove('show');
      if(!isOpen) return;
      flipping=false;
      if(!h){                                  // deleted meanwhile: skip it
        ids.splice(idx,1); if(idx<hi) hi--;
        updateNav();
        if(!autoFlip(dir,tmi)){ if(dir>0) close(); else syncVideo(); }
        return;
      }
      flipBegin(dir,idx,tmi); flipEnd(true);
    });
    return true;
  }

  /* ══════════════════ navigation ══════════════════ */
  window.hlNext=function(){
    if(flipping||$('hlSheet').classList.contains('show')||!cur()) return;
    var h=cur();
    if(mi<h.media.length-1){ mi++; render(false); }
    else if(!autoFlip(1,0)) close();
  };
  window.hlPrev=function(){
    if(flipping||$('hlSheet').classList.contains('show')||!cur()) return;
    if(mi>0){ mi--; render(false); }
    else if(!autoFlip(-1,'last')) render(false);
  };
  window.hlToTop=function(){};
  window.hlOpenComments=function(focus){
    if(!cur()) return;
    if(window.dzHp) dzHp('light');
    hlCloseSheet(); closeMenu();
    $('hlCmOv').classList.add('show'); $('hlComments').classList.add('show');
    offscreen=true; syncVideo();
    if(focus){ setTimeout(function(){ var i=$('hlCIn'); if(i&&!i.disabled) i.focus({preventScroll:true}); },340); }
  };
  function cmSheetReset(){ $('hlCmOv').classList.remove('show'); $('hlComments').classList.remove('show'); offscreen=false; var i=$('hlCIn'); if(i) i.blur(); closeMenu(); }
  window.hlCloseComments=function(){
    if(window.dzHp) dzHp('light');
    cmSheetReset();
    if(isOpen) syncVideo();
  };
  window.hlGoComments=function(focus){ window.hlOpenComments(focus); };
  window.hlCmNav=function(){ window.hlOpenComments(false); };

  /* ══════════════════ LIKES (highlight) ══════════════════ */
  /* one like per MEDIA: the heart and the count belong to the media on screen. Painting never animates — only a tap does. */
  function paintLike(h){
    var b=$('hlLikeBtn'); if(!b) return;
    var m=h&&h.media&&h.media[mi]; if(!m) return;
    b.classList.toggle('on',!!m.liked);
    $('hlLikeCount').textContent=(+m.likes>0)?fmtNum(m.likes):'';
  }
  window.hlLike=function(){
    var h=cur(); if(!h||liking) return;
    var m=h.media[mi]; if(!m||!m.id) return;
    if(h.perm && h.perm.like===false){ toast(h.perm.like_msg||'Only pod members can like'); return; }
    var wasL=!!m.liked, wasN=+m.likes||0;
    if(window.dzHp) dzHp(wasL?'light':'medium');
    m.liked=!wasL; m.likes=Math.max(0,wasN+(wasL?-1:1));
    paintLike(h);
    var ic=$('hlLikeBtn').querySelector('i'); ic.classList.remove('pop'); void ic.offsetWidth; ic.classList.add('pop');
    liking=true;
    var here=function(){ return cur()===h && h.media[mi]===m; };
    api('like',{highlight_id:h.id,media_id:m.id}).then(function(d){
      liking=false;
      if(!d.success){ m.liked=wasL; m.likes=wasN; if(here()) paintLike(h); toast(d.msg||'Could not like'); return; }
      m.liked=!!d.liked; m.likes=+d.likes||0;
      if(here()) paintLike(h);
    });
  };

  /* ══════════════════════════════════════════════════════════════
     COMMENTS (below the box): sort, likes / dislikes, ⋯ menu,
     "creator liked this", and true nested replies at any depth —
     each comment with replies has its own "N replies" dropdown.
     ══════════════════════════════════════════════════════════════ */
  function resetCm(){
    CM.fid=0; CM.items=[]; CM.map={}; CM.more=false; CM.loading=false; CM.posting=false; CM.reply=null;
    CM.offset=0; CM.seq++; CM.sort='top';
    $('hlCmSortLbl').textContent=SORT_LABELS.top;
    Array.prototype.forEach.call($('hlCmSortMenu').querySelectorAll('button'),function(b){ b.classList.toggle('active',b.getAttribute('data-sort')==='top'); });
    $('hlCmSortMenu').classList.remove('show');
    closeMenu();
    $('hlCmList').innerHTML=''; $('hlCmSent').classList.remove('show');
  }
  function paintCounts(h){
    var c=h.comments||0;
    $('hlCmCount').textContent=c?String(c):'';
    $('hlBarCount').textContent=c?fmtNum(c):'';
    paintLike(h);
  }
  function reg(c){
    c.rc=+c.replies||0; c.kids=[]; c.open=false; c.kmore=false; c.kloaded=false; c.kloading=false; c.koff=0;
    c.likes=+c.likes||0; c.liked=!!c.liked; c.disliked=!!c.disliked; c.ol=!!c.ol;
    CM.map[c.id]=c; return c;
  }

  /* text: leading @mention coloured, links clickable, long comments cut with "...Read more" */
  function linkText(t){
    t=String(t||'');
    var mention='', rest=t, mm=t.match(/^(@[A-Za-z0-9_.]{1,40})(\s|$)/);
    if(mm){ mention=mm[1]; rest=t.slice(mention.length); }
    var re=/((?:https?:\/\/|www\.)[^\s<>"']+)/gi, out='', last=0, m;
    if(mention) out='<a class="hl-mention" href="profile.php?username='+encodeURIComponent(mention.slice(1).replace(/\.+$/,''))+'">'+esc(mention)+'</a>';
    while((m=re.exec(rest))!==null){
      var url=m[0], trail='', tm=url.match(/[.,!?;:)\]}]+$/);
      if(tm){ trail=tm[0]; url=url.slice(0,url.length-trail.length); }
      if(!url) continue;
      var href=/^www\./i.test(url)?'https://'+url:url;
      out+=esc(rest.slice(last,m.index))+'<a class="hl-link" href="'+esc(href)+'" target="_blank" rel="noopener noreferrer nofollow">'+esc(url)+'</a>'+esc(trail);
      last=m.index+m[0].length;
    }
    out+=esc(rest.slice(last));
    return out.replace(/\n/g,'<br>');
  }
  function cutText(text){
    var full=String(text||'').replace(/\s+$/,''), re=/\S+/g, m, n=0, end=-1;
    while((m=re.exec(full))!==null){ n++; if(n===30){ end=m.index+m[0].length; break; } }
    var cut=(end!==-1)?full.slice(0,end):full;
    if(cut.length>280){ var s=cut.slice(0,280), sp=s.lastIndexOf(' '); cut=(sp>140)?s.slice(0,sp):s; }
    cut=cut.replace(/\s+$/,'');
    return cut.length>=full.length?null:cut;
  }
  function textHtml(text){
    var cut=cutText(text);
    if(cut===null) return '<div class="hl-ci-tx">'+linkText(text)+'</div>';
    return '<div class="hl-ci-tx"><span class="hl-short">'+linkText(cut)+'</span><span class="hl-full">'+linkText(text)+'</span> <button type="button" class="hl-rm" data-rm="1">...Read more</button></div>';
  }
  function avImg(url){ return '<img src="'+esc(url||FB_IMG)+'" alt="" onerror="hlImgFail(this)">'; }
  function repLabel(n){ return n+(n===1?' reply':' replies'); }

  function ciHtml(c){
    var h=cur(), canR=!!(h&&h.perm.comment);
    var crPic=(h&&h.by&&h.by.pic)?h.by.pic:FB_IMG;
    var main=
      '<div class="hl-ci-main">'+
        '<div class="hl-ci-avcol"><a class="hl-ci-av" href="'+HL_USER_URL+c.uid+'">'+avImg(c.pic)+'</a><span class="hl-ci-line"></span></div>'+
        '<div class="hl-ci-body">'+
          '<div class="hl-ci-l1"><a class="hl-ci-un" href="'+HL_USER_URL+c.uid+'">'+esc(c.mine?'You':c.name)+'</a>'+badge(c.role)+
            '<span class="hl-ci-t">'+esc(ago(c.time))+'</span>'+
            '<button type="button" class="hl-ci-more" data-more="'+c.id+'" aria-label="Comment options" aria-haspopup="menu"><i class="ri-more-2-fill"></i></button></div>'+
          textHtml(c.text)+
          '<div class="hl-ci-r2">'+
            '<button type="button" class="hl-ci-like'+(c.liked?' on':'')+'" data-like="'+c.id+'" aria-label="Like comment"><i class="'+(c.liked?'ri-thumb-up-fill':'ri-thumb-up-line')+'"></i><span>'+(c.likes>0?esc(fmtNum(c.likes)):'')+'</span></button>'+
            '<button type="button" class="hl-ci-dis'+(c.disliked?' on':'')+'" data-dis="'+c.id+'" aria-label="Dislike comment"><i class="'+(c.disliked?'ri-thumb-down-fill':'ri-thumb-down-line')+'"></i></button>'+
            (canR?'<button type="button" class="hl-ci-reply" data-reply="'+c.id+'" data-name="'+esc(c.name)+'">Reply</button>':'')+
            '<span class="hl-crl" title="Liked by creator"'+(c.ol?'':' style="display:none"')+'><span class="hl-crl-av">'+avImg(crPic)+'</span><i class="hl-crl-b ri-heart-fill"></i></span>'+
          '</div>'+
        '</div>'+
      '</div>';
    var thread='', hasT=c.rc>0;
    if(hasT){
      thread='<button type="button" class="hl-ci-tg'+(c.open?' open':'')+'" data-tg="'+c.id+'"><span class="hl-ci-tgav">'+avImg(c.rpic)+'</span><span class="hl-ci-tgd">·</span><span>'+repLabel(c.rc)+'</span><i class="ri-arrow-right-s-line hl-ci-chev"></i></button>';
      if(c.open){
        var inner='';
        if(c.kloading&&!c.kids.length) inner='<div class="hl-ci-skel"><div class="hl-sk" style="width:26px;height:26px;border-radius:50%;flex-shrink:0"></div><div style="flex:1"><div class="hl-sk" style="width:40%;height:9px;margin-bottom:8px"></div><div class="hl-sk" style="width:75%;height:9px"></div></div></div>';
        else inner=c.kids.map(ciHtml).join('');
        if(c.kmore) inner+='<button type="button" class="hl-ci-sm" data-sm="'+c.id+'">'+(c.kloading?'Loading…':'Show more replies')+'</button>';
        thread+='<div class="hl-sub">'+inner+'</div>';
      }
    }
    return '<div class="hl-ci'+(hasT?' has-thread':'')+(c._n?' fresh':'')+'" data-id="'+c.id+'">'+main+thread+'</div>';
  }
  function clearFresh(){ Object.keys(CM.map).forEach(function(k){ CM.map[k]._n=false; }); }
  function renderComments(){
    var html='', h=cur();
    if(CM.loading&&!CM.items.length){
      for(var s=0;s<4;s++) html+='<div class="hl-ci-skel"><div class="hl-sk" style="width:var(--csav);height:var(--csav);border-radius:50%;flex-shrink:0"></div><div style="flex:1"><div class="hl-sk" style="width:90px;height:10px;margin-bottom:8px"></div><div class="hl-sk" style="width:80%;height:10px"></div></div></div>';
    } else if(!CM.items.length){
      html='<div class="hl-cm-empty"><i class="ri-question-answer-line"></i>No comments yet.<br>'+(h&&h.perm.comment?'Be the first to comment.':'Pod members can comment here.')+'</div>';
    } else html=CM.items.map(ciHtml).join('');
    $('hlCmList').innerHTML=html;
    $('hlCmSent').classList.toggle('show',CM.more);
    clearFresh();
  }
  function loadComments(reset){
    var h=cur(); if(!h||CM.loading) return;
    if(reset){ CM.items=[]; CM.map={}; CM.offset=0; CM.more=false; }
    else if(!CM.more) return;
    var seq=CM.seq; CM.fid=h.id; CM.loading=true; renderComments();
    api('comments',{highlight_id:h.id,sort:CM.sort,offset:CM.offset}).then(function(d){
      if(seq!==CM.seq) return;
      CM.loading=false;
      if(!d.success){ toast(d.msg||'Could not load comments'); renderComments(); return; }
      var got=(d.items||[]).map(function(c){ c._n=true; return reg(c); });
      CM.items=CM.items.concat(got); CM.offset+=got.length; CM.more=!!d.more;
      h.comments=d.total; paintCounts(h); renderComments();
    });
  }
  function loadKids(id,more){
    var c=CM.map[id], h=cur(); if(!c||!h||c.kloading||(more&&!c.kmore)) return;
    c.kloading=true; var seq=CM.seq; renderComments();
    api('comments',{highlight_id:h.id,parent_id:id,offset:c.koff}).then(function(d){
      if(seq!==CM.seq) return;
      c.kloading=false;
      if(!d.success){ toast(d.msg||'Could not load replies'); renderComments(); return; }
      (d.items||[]).forEach(function(g){
        if(c.kids.some(function(k){ return k.id===g.id; })) return;
        c.kids.push(reg(g));
      });
      c.koff+=(d.items||[]).length; c.kmore=!!d.more; c.kloaded=true; renderComments();
    });
  }

  /* compose */
  function renderCompose(h){
    var box=$('hlCmCompose');
    if(!h){ box.innerHTML=''; return; }
    if(h.perm.comment){
      box.innerHTML=
        '<div class="hl-reply-chip" id="hlReplyChip"><span>Replying to</span><b></b><button type="button" class="hl-reply-x" data-cancel-reply="1" aria-label="Cancel reply"><i class="ri-close-line"></i></button></div>'+
        '<div class="hl-emo" id="hlEmo"><span>❤️</span><span>🙌</span><span>🔥</span><span>👏</span><span>😢</span><span>😍</span><span>😮</span><span>😂</span></div>'+
        '<div class="hl-compose-row"><span class="hl-cmy">'+avImg(h.me.pic)+'</span>'+
        '<input type="text" id="hlCIn" maxlength="500" placeholder="Add a comment…" autocomplete="off" enterkeyhint="send">'+
        '<button type="button" class="hl-send" id="hlCSend" aria-label="Post comment" disabled><svg viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M236.2,218.31A15.88,15.88,0,0,1,224,224a16.22,16.22,0,0,1-5.37-.92l-79.95-27a4,4,0,0,1-2.72-3.79V120a8,8,0,0,0-8.53-8,8.19,8.19,0,0,0-7.47,8.26v72a4,4,0,0,1-2.72,3.79l-79.95,27a16,16,0,0,1-19.26-22.92L114,32.13a16,16,0,0,1,27.89,0L237.9,200.1A15.89,15.89,0,0,1,236.2,218.31Z"/></svg></button></div>';
      var inp=$('hlCIn'), send=$('hlCSend');
      inp.addEventListener('focus',syncVideo); inp.addEventListener('blur',syncVideo);
      inp.addEventListener('input',function(){ send.disabled=!inp.value.trim(); });
      inp.addEventListener('keydown',function(e){ e.stopPropagation(); if(e.key==='Enter'){ e.preventDefault(); postComment(); } });
      send.addEventListener('click',postComment);
      var emo=$('hlEmo'); if(emo) emo.addEventListener('click',function(e){
        var sp=e.target.closest('span'); if(!sp) return;
        if(window.dzHp) dzHp('selection');
        var st=inp.selectionStart==null?inp.value.length:inp.selectionStart, en=inp.selectionEnd==null?st:inp.selectionEnd;
        inp.value=inp.value.slice(0,st)+sp.textContent+inp.value.slice(en);
        var pos=st+sp.textContent.length; send.disabled=!inp.value.trim();
        try{ inp.setSelectionRange(pos,pos); }catch(_){}
      });
    } else {
      box.innerHTML='<div class="hl-nocm"><i class="ri-lock-line"></i>'+esc(h.perm.comment_msg||'Only pod members can comment')+'</div>';
    }
  }
  function paintReplyChip(){
    var chip=$('hlReplyChip'), inp=$('hlCIn'); if(!chip||!inp) return;
    if(CM.reply){ chip.querySelector('b').textContent='@'+CM.reply.name; chip.classList.add('show'); inp.placeholder='Reply to @'+CM.reply.name+'…'; }
    else { chip.classList.remove('show'); inp.placeholder='Add a comment…'; }
  }
  function startReply(btn){
    var inp=$('hlCIn'); if(!inp) return;
    CM.reply={ id:parseInt(btn.getAttribute('data-reply'),10), name:btn.getAttribute('data-name') };
    paintReplyChip();
    inp.value='@'+CM.reply.name+' ';
    inp.dispatchEvent(new Event('input'));
    inp.focus({preventScroll:true});
  }
  function cancelReply(){
    CM.reply=null; paintReplyChip();
    var inp=$('hlCIn');
    if(inp && /^@\S+\s*$/.test(inp.value)){ inp.value=''; inp.dispatchEvent(new Event('input')); }
  }
  function postComment(){
    var inp=$('hlCIn'), btn=$('hlCSend'), h=cur(); if(!inp||!h||CM.posting) return;
    var t=inp.value.trim(); if(!t) return;
    var rep=CM.reply;
    if(rep && t.replace(/\s+/g,'')==='@'+rep.name.replace(/\s+/g,'')) return;
    var data={highlight_id:h.id,text:t}; if(rep) data.parent_id=rep.id;
    CM.posting=true; btn.disabled=true;
    api('comment_add',data).then(function(d){
      CM.posting=false;
      if(!d.success){ btn.disabled=!inp.value.trim(); toast(d.msg||'Could not post'); return; }
      inp.value=''; btn.disabled=true;
      h.comments=d.total; paintCounts(h);
      if(cur()!==h) return;
      var item=d.item; item._n=true;
      var p=rep?CM.map[rep.id]:null;
      if(p){
        p.rc+=1; p.open=true; if(!p.rpic) p.rpic=item.pic;
        if(p.kloaded){ if(!p.kids.some(function(k){return k.id===item.id;})){ p.kids.push(reg(item)); if(!p.kmore) p.koff+=1; } renderComments(); }
        else { renderComments(); loadKids(p.id,false); }
        CM.reply=null; paintReplyChip();
      } else {
        reg(item);
        if(CM.sort==='old'&&CM.more){ /* it will arrive with the rest of the list */ }
        else if(CM.sort==='old') CM.items.push(item); else CM.items.unshift(item);
        CM.offset+=1; renderComments();
        if(CM.sort!=='old') $('hlCmScroll').scrollTop=0;
      }
    });
  }

  /* like / dislike on a comment (targeted DOM updates, no full re-render) */
  function itemEl(id){ return $('hlCmList').querySelector('.hl-ci[data-id="'+id+'"]'); }
  function paintRow(c,popWhich){
    var it=itemEl(c.id); if(!it) return;
    var row=it.querySelector('.hl-ci-main .hl-ci-r2'); if(!row) return;
    var l=row.querySelector('.hl-ci-like'), d=row.querySelector('.hl-ci-dis'), cr=row.querySelector('.hl-crl');
    l.classList.toggle('on',c.liked); l.querySelector('i').className=c.liked?'ri-thumb-up-fill':'ri-thumb-up-line';
    l.querySelector('span').textContent=c.likes>0?fmtNum(c.likes):'';
    d.classList.toggle('on',c.disliked); d.querySelector('i').className=c.disliked?'ri-thumb-down-fill':'ri-thumb-down-line';
    if(cr) cr.style.display=c.ol?'':'none';
    if(popWhich){ var ic=(popWhich==='like'?l:d).querySelector('i'); ic.classList.remove('pop'); void ic.offsetWidth; ic.classList.add('pop'); }
  }
  function iAmCreator(){ var h=cur(); return !!(h&&h.by&&String(h.by.id)===String(ME)); }
  function cmLike(btn){
    var id=parseInt(btn.getAttribute('data-like'),10), c=CM.map[id], h=cur(); if(!c||!h) return;
    if(h.perm.like===false){ toast(h.perm.like_msg||'Only pod members can like'); return; }
    var was={liked:c.liked,dis:c.disliked,likes:c.likes,ol:c.ol};
    c.liked=!was.liked; c.disliked=false; c.likes=Math.max(0,was.likes+(c.liked?1:-1));
    if(iAmCreator()) c.ol=c.liked;
    paintRow(c,'like');
    api('comment_like',{comment_id:id}).then(function(d){
      if(!d.success){ c.liked=was.liked; c.disliked=was.dis; c.likes=was.likes; c.ol=was.ol; paintRow(c); toast(d.msg||'Could not save that'); return; }
      c.liked=!!d.liked; c.disliked=!!d.disliked; c.likes=+d.likes||0;
      if(d.ol!==null&&d.ol!==undefined) c.ol=!!d.ol;
      paintRow(c);
    });
  }
  function cmDislike(btn){
    var id=parseInt(btn.getAttribute('data-dis'),10), c=CM.map[id], h=cur(); if(!c||!h) return;
    if(h.perm.like===false){ toast(h.perm.like_msg||'Only pod members can react'); return; }
    var was={liked:c.liked,dis:c.disliked,likes:c.likes,ol:c.ol};
    c.disliked=!was.dis;
    if(c.disliked&&was.liked){ c.liked=false; c.likes=Math.max(0,was.likes-1); if(iAmCreator()) c.ol=false; }
    paintRow(c,'dis');
    api('comment_dislike',{comment_id:id}).then(function(d){
      if(!d.success){ c.liked=was.liked; c.disliked=was.dis; c.likes=was.likes; c.ol=was.ol; paintRow(c); toast(d.msg||'Could not save that'); return; }
      c.liked=!!d.liked; c.disliked=!!d.disliked; c.likes=+d.likes||0;
      if(d.ol!==null&&d.ol!==undefined) c.ol=!!d.ol;
      paintRow(c);
    });
  }

  /* ⋯ menu: download / delete */
  function closeMenu(){ $('hlCmMenu').classList.remove('show'); CM.menuFor=0; }
  function openMenu(btn){
    var id=parseInt(btn.getAttribute('data-more'),10), c=CM.map[id]; if(!c) return;
    var m=$('hlCmMenu');
    if(m.classList.contains('show')&&CM.menuFor===id){ closeMenu(); return; }
    CM.menuFor=id;
    m.querySelector('[data-act="delete"]').style.display=(c.canDel||(cur()&&cur().perm&&cur().perm.mod))?'':'none';
    m.classList.add('show');
    var cr=$('hlComments').getBoundingClientRect(), br=btn.getBoundingClientRect(), mh=m.offsetHeight;
    var top=br.bottom-cr.top+4;
    if(br.bottom+mh+8>window.innerHeight) top=br.top-cr.top-mh-4;
    m.style.top=Math.max(8,top)+'px';
    m.style.right=Math.max(8,cr.right-br.right)+'px';
  }
  function dlComment(id){
    var c=CM.map[id]; if(!c) return;
    var h=cur();
    var body='Comment by @'+c.name+'\nPosted: '+ago(c.time)+'\n'+(h?'On highlight in: '+POD_NAME+'\n':'')+'\n'+c.text+'\n';
    var url=URL.createObjectURL(new Blob([body],{type:'text/plain;charset=utf-8'}));
    var a=document.createElement('a'); a.href=url; a.download='comment-'+id+'.txt'; a.rel='noopener';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    setTimeout(function(){ URL.revokeObjectURL(url); },1500);
    toast('Comment downloaded');
  }
  function dropFromMap(c){ delete CM.map[c.id]; c.kids.forEach(dropFromMap); }
  function delComment(id){
    var c=CM.map[id], h=cur(); if(!c||!h||!(c.canDel||(h.perm&&h.perm.mod))) return;
    if(!confirm('Delete this comment? This cannot be undone.')) return;
    api('comment_del',{comment_id:id}).then(function(d){
      if(!d.success){ toast(d.msg||'Could not delete'); return; }
      var par=c.parent?CM.map[c.parent]:null;
      if(CM.reply&&(CM.reply.id===id)) { CM.reply=null; paintReplyChip(); }
      if(par){
        par.kids=par.kids.filter(function(k){ return k.id!==id; });
        par.rc=Math.max(0,par.rc-1); par.koff=Math.max(0,par.koff-1);
      } else {
        CM.items=CM.items.filter(function(k){ return k.id!==id; });
        CM.offset=Math.max(0,CM.offset-1);
      }
      dropFromMap(c);
      h.comments=d.total; paintCounts(h);
      renderComments();
      toast('Comment deleted');
    });
  }

  /* one click handler for everything inside the comments section */
  $('hlComments').addEventListener('click',function(e){
    var t=e.target;
    function up(sel){ return t.closest?t.closest(sel):null; }
    var x;
    if((x=up('[data-cancel-reply]'))){ cancelReply(); return; }
    if((x=up('[data-more]'))){ openMenu(x); return; }
    if((x=up('[data-act]'))){ var act=x.getAttribute('data-act'), id=CM.menuFor; closeMenu(); if(act==='download') dlComment(id); else if(act==='delete') delComment(id); return; }
    if((x=up('[data-like]'))){ cmLike(x); return; }
    if((x=up('[data-dis]'))){ cmDislike(x); return; }
    if((x=up('[data-reply]'))){ startReply(x); return; }
    if((x=up('[data-tg]'))){
      var tid=parseInt(x.getAttribute('data-tg'),10), c=CM.map[tid]; if(!c) return;
      c.open=!c.open;
      if(c.open&&!c.kloaded) loadKids(tid,false); else renderComments();
      return;
    }
    if((x=up('[data-sm]'))){ loadKids(parseInt(x.getAttribute('data-sm'),10),true); return; }
    if((x=up('[data-rm]'))){
      var box=x.parentNode, open=box.classList.toggle('expanded');
      x.textContent=open?'Show less':'...Read more';
      return;
    }
  });
  /* sort */
  $('hlCmSortBtn').addEventListener('click',function(e){ e.stopPropagation(); closeMenu(); $('hlCmSortMenu').classList.toggle('show'); });
  Array.prototype.forEach.call($('hlCmSortMenu').querySelectorAll('button'),function(b){
    b.addEventListener('click',function(e){
      e.stopPropagation();
      var s=this.getAttribute('data-sort');
      $('hlCmSortMenu').classList.remove('show');
      if(s===CM.sort) return;
      CM.sort=s; $('hlCmSortLbl').textContent=SORT_LABELS[s]||'Top';
      Array.prototype.forEach.call($('hlCmSortMenu').querySelectorAll('button'),function(x){ x.classList.toggle('active',x===b); });
      CM.seq++; CM.loading=false; loadComments(true);
    });
  });
  document.addEventListener('click',function(e){
    if(!isOpen) return;
    if(!e.target.closest('.hl-cm-sort')) $('hlCmSortMenu').classList.remove('show');
    if(!e.target.closest('#hlCmMenu,.hl-ci-more')) closeMenu();
  });
  $('hlCmScroll').addEventListener('scroll',closeMenu,{passive:true});
  /* load more comments as you reach the end of the list */
  if('IntersectionObserver' in window){
    new IntersectionObserver(function(es){ if(es[0].isIntersecting) loadComments(false); },{root:$('hlCmScroll'),rootMargin:'250px 0px'}).observe($('hlCmSent'));
  }
  $('hlCapLayer').addEventListener('click',function(e){
    var b=e.target.closest('[data-vote]'); if(b) doVote(b);
  });

  /* ══════════════════ menu sheet (≡ icon on the box) ══════════════════ */
  function link(){ return location.origin+location.pathname+'?id='+GID+'&hl='+cur().id; }
  window.hlCopy=function(){
    hlCloseSheet();
    var u=link();
    if(navigator.clipboard&&navigator.clipboard.writeText){
      navigator.clipboard.writeText(u).then(function(){ if(window.dzHp) dzHp('success'); toast('Link copied'); }).catch(function(){ if(window.dzHp) dzHp('error'); toast('Could not copy'); });
    } else { if(window.dzHp) dzHp('error'); toast('Could not copy'); }
  };
  window.hlOpenSheet=function(){
    var h=cur();
    if(window.dzHp) dzHp('light');
    function row(tag,attrs,ic,label,sub,red,badge){
      return '<'+tag+' class="mr-row" '+attrs+'><div class="mr-icon"><i class="'+ic+'"></i></div><div class="mr-body"><div class="mr-label'+(red?' red':'')+'">'+label+'</div><div class="mr-sub">'+sub+'</div></div>'+(badge?'<span class="mr-state-badge">'+badge+'</span>':'')+'</'+tag+'>';
    }
    var cc=+h.comments||0, html='';
    html+=row('div','onclick="hlOpenComments(false)"','ri-chat-3-line','Comments','Read and add comments',false,cc?fmtNum(cc):'');
    html+=row('a','href="'+HL_USER_URL+h.by.id+'"','ri-user-3-line','View profile','See who shared this highlight',false);
    html+=row('div','onclick="hlCopy()"','ri-file-copy-line','Copy Link','Copy the link to this highlight',false);
    if(h.perm.mod) html+=row('a','href="group_controls.php?id='+GID+'&open=highlights"','ri-settings-3-line','Manage highlights','Edit, reorder or remove highlights',false);
    if(String(h.by.id)!==String(ME)) html+=row('div','onclick="hlReport()"','ri-flag-line','Report','Tell us what is wrong with this highlight',true);
    $('hlSheetList').innerHTML=html;
    $('hlSheetOv').classList.add('show'); $('hlSheet').classList.add('show');
    syncVideo();
  };
  window.hlCloseSheet=function(){
    $('hlSheetOv').classList.remove('show'); $('hlSheet').classList.remove('show');
    if(isOpen) syncVideo();
  };
  window.hlReport=function(){
    var id=cur().id;
    hlCloseSheet();
    if(typeof openReportModal==='function') openReportModal({contentId:id,contentType:'highlight'});
    else toast('Report unavailable');
  };

  /* ══════════════════ swipe = the cube follows your finger ══════════════════ */
  function gotoHighlight(dir){
    if(!cur()||flipping||$('hlSheet').classList.contains('show')) return;
    var idx=hi+dir; if(idx<0||idx>=ids.length) return;
    autoFlip(dir,dir>0?0:'last');
  }
  (function(){
    var st=$('hlStage'), sx=0, sy=0, pid=null, tracking=false, dragOn=false, dragDir=0, t0=0, lastWheel=0, suppress=false;

    st.addEventListener('pointerdown',function(e){
      if(!cur()||flipping||$('hlSheet').classList.contains('show')) return;
      if(e.pointerType==='mouse'&&e.button!==0) return;
      if(e.target.closest('.hl-bottom')||e.target.closest('#hlSheet')||e.target.closest('.hl-icon-btn')||e.target.closest('.hl-who')||e.target.closest('.hl-po')) return;
      tracking=true; dragOn=false; pid=e.pointerId; sx=e.clientX; sy=e.clientY; t0=Date.now();
    });
    st.addEventListener('pointermove',function(e){
      if(!tracking||e.pointerId!==pid) return;
      var dx=e.clientX-sx, dy=e.clientY-sy;
      if(!dragOn){
        if(Math.abs(dx)<12||Math.abs(dx)<Math.abs(dy)*1.3){ if(Math.abs(dy)>26) tracking=false; return; }
        dragDir=dx<0?1:-1;
        var idx=hi+dragDir;
        if(idx<0||idx>=ids.length) dragOn='edge';
        else if(!C[ids[idx]]){ dragOn='wait'; ensure(idx); }          // not loaded yet: let it finish loading
        else { flipBegin(dragDir,idx,0); dragOn=true; }
        try{ st.setPointerCapture(pid); }catch(_){}
      }
      if(dragOn===true) flipMove(dx);
    });
    function endPtr(e){
      if(!tracking||e.pointerId!==pid) return;
      tracking=false;
      if(!dragOn) return;
      var mode=dragOn; dragOn=false;
      suppress=true; setTimeout(function(){ suppress=false; },80);
      var dx=e.clientX-sx;
      if(mode==='wait') return;
      if(mode==='edge'){ if(e.type==='pointerup'&&Math.abs(dx)>80) close(); return; }
      var W=flip?flip.W:st.clientWidth, v=Math.abs(dx)/Math.max(1,Date.now()-t0);
      var sameWay=(dx<0?1:-1)===dragDir;
      var commit=e.type==='pointerup'&&sameWay&&(Math.abs(dx)>W*0.28||(v>0.5&&Math.abs(dx)>40));
      flipEnd(commit);
    }
    st.addEventListener('pointerup',endPtr);
    st.addEventListener('pointercancel',endPtr);
    st.addEventListener('click',function(e){ if(suppress){ e.stopPropagation(); e.preventDefault(); suppress=false; } },true);

    st.addEventListener('wheel',function(e){
      if(Math.abs(e.deltaX)<30||Math.abs(e.deltaX)<Math.abs(e.deltaY)) return;
      var now=Date.now(); if(now-lastWheel<800) return; lastWheel=now;
      gotoHighlight(e.deltaX>0?1:-1);
    },{passive:true});
  })();

  /* ══════════════════ keyboard ══════════════════ */
  document.addEventListener('keydown',function(e){
    if(!isOpen) return;
    var tag=((document.activeElement||{}).tagName||'');
    if(e.key==='Escape'){
      e.stopPropagation();
      if($('hlCmMenu').classList.contains('show')) closeMenu();
      else if($('hlSheet').classList.contains('show')) hlCloseSheet(); else close();
      return;
    }
    if(tag==='INPUT'||tag==='TEXTAREA') return;
    if(e.key==='ArrowRight') hlNext();
    if(e.key==='ArrowLeft')  hlPrev();
  },true);

  /* ══════════════════ deep link: group.php?id=5&hl=12 ══════════════════ */
  document.addEventListener('DOMContentLoaded',function(){
    var id=new URLSearchParams(location.search).get('hl');
    if(id) setTimeout(function(){ window.openGroupHighlightsModal(id); },700);
  });
})();

/* verified badge next to commenter names: looks up which commenters are verified and adds the red flower after the name */
(function(){
  var list = document.getElementById('hlCmList'); if(!list) return;
  var cache = {}, busy = false, again = false;
  function badge(){ return window.DZ_VB || (document.getElementById('dz-flower-f') ? '<svg class="dz-vb" viewBox="0 0 256 256" aria-label="Verified" role="img"><use href="#dz-flower-f"/></svg>' : ''); }
  function put(a){ if(a.nextElementSibling && a.nextElementSibling.classList.contains('dz-vb')) return; var b = badge(); if(b) a.insertAdjacentHTML('afterend', b); }
  function scan(){
    if(busy){ again = true; return; }
    var links = list.querySelectorAll('.hl-ci-un'), need = {}, any = false;
    links.forEach(function(a){
      var m = /user_id=(\d+)/.exec(a.getAttribute('href') || ''); if(!m) return;
      if(cache[m[1]] === true) put(a); else if(cache[m[1]] === undefined){ need[m[1]] = 1; any = true; }
    });
    if(!any) return;
    busy = true;
    fetch(location.pathname + location.search.replace(/^\?/, '?') + (location.search ? '&' : '?') + 'dz_vb_ids=' + Object.keys(need).join(','), { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(ids){ var ok = {}; (ids || []).forEach(function(i){ ok[i] = 1; }); Object.keys(need).forEach(function(i){ cache[i] = !!ok[i]; }); })
      .catch(function(){})
      .then(function(){ busy = false; scan(); again = false; });
  }
  new MutationObserver(function(){ scan(); }).observe(list, { childList:true, subtree:true });
})();

</script>
</template>

<?php
/* ── Audio library for the post modal's "Library" picker ──
   Mirrors the query used in upload.php. Guarded so it won't clash if this
   partial is included on a page that already defines $library_audios. */
if (!isset($library_audios)) {
    $library_audios = [];
    if (isset($conn)) {
        $library_audios_res = $conn->query("
            SELECT a.*,
                   (SELECT COUNT(*) FROM meme_audio ma WHERE ma.audio_id=a.id) as uses_count
            FROM audios a
            WHERE a.visibility='public'
            ORDER BY uses_count DESC, a.created_at DESC
            LIMIT 20
        ");
        if ($library_audios_res) {
            while ($a = $library_audios_res->fetch_assoc()) $library_audios[] = $a;
        }
    }
}
?>

<?php
/* ═══════════════════════════════════════════════════════════
   post_modal.php — the group "New post" modal.
   Included by group.php (members only), right after the file-menu sheet:
       <?php if ($is_member && file_exists(__DIR__.'/post_modal.php')) include __DIR__.'/post_modal.php'; ?>
   Needs these from group.php: $conn, $current_user, $current_user_pic, $group,
   $group_hashtags, $library_audios, $upload_error.

   ZEEM CONNECT — lets the user optionally connect this NEW
   group post to one of their OWN existing Zeems (Part 1/Part 2,
   reactions, follow-ups, context, etc). This is a lightweight
   reference stored on the NEW post (connected_meme_id) — it does
   NOT merge, embed, or copy the other post's media/caption. Both
   Zeems remain fully independent afterward. Only the current
   user's own Zeems can be selected (re-validated server-side on submit).

   TAGS & MENTIONS — settings.php only SAVES the choices. This modal
   asks tag_guard.php early (greyed-out people, a red warning under the
   caption). The REAL enforcement is on the server (group.php upload handler).
═══════════════════════════════════════════════════════════ */
$my_zeems_stmt = $conn->prepare("
    SELECT m.id, m.file_name, m.type, m.caption,
           1 + (SELECT COUNT(*) FROM meme_media mm WHERE mm.meme_id = m.id) AS media_count
    FROM memes m
    WHERE m.uploaded_by=?
    ORDER BY m.created_at DESC
    LIMIT 50
");
$my_zeems_stmt->bind_param("i", $current_user);
$my_zeems_stmt->execute();
$my_zeems_res = $my_zeems_stmt->get_result();
$my_zeems = [];
if ($my_zeems_res) while ($z = $my_zeems_res->fetch_assoc()) $my_zeems[] = $z;
$my_zeems_stmt->close();
if (!isset($library_audios) || !is_array($library_audios)) $library_audios = [];
?>

<!-- ─── POST MODAL — centered lane with grey side lines ─────── -->
<div id="postModal" role="dialog" aria-modal="true" aria-label="New post">
 <div class="pm-lane">

    <!-- TOPBAR: X left · title centered · drafts right -->
    <div class="pm-topbar">
        <div class="pm-tb-side">
            <button type="button" class="pm-x" onclick="closePostModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="pm-topbar-center">
            <div class="pm-topbar-title">New Post</div>
        </div>
        <div class="pm-tb-side right">
            <button type="button" class="pm-drafts-btn" onclick="pmToggleDraftsPanel()" aria-label="Drafts">
                <i class="ri-draft-line"></i>
                <span id="pmDraftsBadge" class="pm-drafts-badge" style="display:none;">0</span>
            </button>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="pm-form" id="postModalForm">

        <div class="pm-body" id="pmBody">

            <?php if (!empty($upload_error)): ?>
                <div class="upload-error"><i class="ri-error-warning-line"></i> <?php echo $upload_error; ?></div>
            <?php endif; ?>

            <!-- ── DRAFTS PANEL — toggled via top bar button ── -->
            <div id="pmDraftsSection" class="pm-drafts-section" style="display:none;">
                <div class="pm-drafts-header">
                    <div class="pm-drafts-title"><i class="ri-draft-line"></i> Saved Drafts</div>
                    <button type="button" class="pm-drafts-clear" id="pmDraftsClearBtn" onclick="pmClearAllDrafts()" style="display:none;">Clear all</button>
                </div>
                <div class="pm-drafts-note"><i class="ri-information-line"></i> Only captions are saved — you'll need to re-attach media.</div>
                <div id="pmDraftsList"></div>
            </div>

            <!-- ── POSTING TO ── -->
            <div class="pm-user-row">
                <img class="post-modal-pic" src="<?php echo htmlspecialchars($current_user_pic); ?>" onerror="this.src='mee.jpg';">
                <div class="pm-user-text">
                    <div class="pm-user-label">Posting to</div>
                    <div class="pm-user-name"><?php echo htmlspecialchars($group['title']); ?></div>
                </div>
            </div>

            <!-- ── MEDIA ── -->
            <label class="pm-drop" id="postDropLabel" for="postFileInput">
                <i class="ri-image-add-line"></i>
                <p id="postDropText">Choose up to 20 images or videos</p>
                <small>JPG, PNG, GIF, MP4, WebM, MOV · drag and drop works too</small>
                <input type="file" name="post_file[]" id="postFileInput" multiple accept="image/*,video/*" onchange="previewPostFiles(this)">
            </label>

            <div id="pmStrip" class="pm-strip" style="display:none;">
                <div id="pmStripInner" class="pm-strip-inner"></div>
                <div class="pm-hint"><span id="pmCount">0</span>/20 · first item is the <strong>cover</strong> · drag to reorder · tap ✕ to remove</div>
            </div>

            <div class="pm-preview" id="postPreview" style="display:none;"></div>

            <!-- ── CAPTION ── -->
            <div class="pm-field">
                <textarea name="caption" id="captionInput" class="pm-input pm-textarea" rows="3" placeholder="Write a caption… include a #hashtag" oninput="checkHashtag(this);pmQueueMentionCheck()" autocomplete="off"></textarea>
                <div class="hashtag-hint invalid" id="hashtagHint">Must include at least one #hashtag to post</div>
                <div class="pm-mention-hint" id="pmMentionHint" style="display:none;" role="alert"></div>
            </div>

            <!-- ── POD HASHTAGS — tap to insert, no need to type ── -->
            <?php if (!empty($group_hashtags)): ?>
            <div class="pm-sec pm-hashtag-suggest">
                <div class="pm-sec-h"><i class="ri-hashtag"></i> Tap a pod hashtag to add it</div>
                <div class="pm-hashtag-pills">
                    <?php foreach ($group_hashtags as $tag): ?>
                    <button type="button" class="pm-hashtag-pill" data-tag="<?php echo htmlspecialchars($tag['tag']); ?>" onclick="insertHashtag(this.dataset.tag, this)">
                        #<?php echo htmlspecialchars($tag['tag']); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── TAG PEOPLE (optional) — checked against each person's Tags and mentions settings ── -->
            <div class="pm-sec pm-tag-block">
                <button type="button" class="pm-zeem-toggle-btn" id="pmTagToggleBtn" onclick="pmToggleTagPanel()">
                    <i class="ri-user-add-line"></i>
                    <span>Tag people</span>
                    <span class="pm-optional" id="pmTagCount">Optional</span>
                </button>

                <div class="pm-tg-chips" id="pmTagChips"></div>

                <div id="pmTagPanel" class="pm-zeem-panel" style="display:none;">
                    <!-- NOTE: the id is pmTagSearchInput (NOT pmTagSearch). Inside a <form>, an input's id
                         shadows a global function of the same name in inline handlers, which made
                         oninput="pmTagSearch()" call the input element instead of the function. -->
                    <input type="text" id="pmTagSearchInput" class="pm-input" placeholder="Search a username or nickname…" oninput="pmTagSearch()" autocomplete="off">
                    <div class="pm-tg-list" id="pmTagResults"></div>
                    <div class="pm-hint">People who only allow tags from people they track, or from no one, are greyed out. If someone turned on Manually approve tags, your tag waits until they accept it. You can tag up to 20 people.</div>
                </div>

                <input type="hidden" name="tagged_users" id="pmTaggedUsers" value="">
            </div>

            <!-- ── PAID PARTNERSHIP ── -->
            <div class="pm-sec pm-rows">
                <div class="pm-row">
                    <i class="ri-hand-coin-line"></i>
                    <span class="pm-lbl">Paid partnership</span>
                    <label class="pm-switch"><input type="checkbox" id="pmPartnershipCheck" onchange="pmTogglePartnership()"><span class="pm-slider"></span></label>
                </div>
                <input type="hidden" name="is_partnership" id="pmIsPartnership" value="0">
                <div id="pmPartnershipBrandArea" class="pm-subfield" style="display:none;">
                    <input type="text" id="pmPartnerBrandInput" name="partner_brand" class="pm-input" placeholder="Brand name (optional)" autocomplete="off">
                    <div class="pm-hint">Tags your post as a paid partnership. Leave the name blank if you'd rather not show it.</div>
                </div>
            </div>

            <!-- ── HIDE COUNTS — the poster still sees their own numbers; everyone else sees the icon only ── -->
            <div class="pm-sec pm-rows">
                <div class="pm-sec-h"><i class="ri-eye-off-line"></i> Hide counts <span class="pm-optional">Optional</span></div>
                <div class="pm-row">
                    <i class="ri-heart-3-line"></i>
                    <span class="pm-lbl">Hide like count</span>
                    <label class="pm-switch"><input type="checkbox" name="hide_likes" id="pmHideLikes" value="1"><span class="pm-slider"></span></label>
                </div>
                <div class="pm-row">
                    <i class="ri-repeat-2-line"></i>
                    <span class="pm-lbl">Hide repost count</span>
                    <label class="pm-switch"><input type="checkbox" name="hide_reposts" id="pmHideReposts" value="1"><span class="pm-slider"></span></label>
                </div>
                <div class="pm-row">
                    <i class="ri-bookmark-line"></i>
                    <span class="pm-lbl">Hide save count</span>
                    <label class="pm-switch"><input type="checkbox" name="hide_saves" id="pmHideSaves" value="1"><span class="pm-slider"></span></label>
                </div>
                <div class="pm-row">
                    <i class="ri-share-forward-line"></i>
                    <span class="pm-lbl">Hide share count</span>
                    <label class="pm-switch"><input type="checkbox" name="hide_shares" id="pmHideShares" value="1"><span class="pm-slider"></span></label>
                </div>
            </div>

            <!-- ── ATTACHED AUDIO (optional soundtrack) ── -->
            <div class="pm-sec pm-audio-block">
                <div class="pm-sec-h"><i class="ri-music-2-line"></i> Add sound <span class="pm-optional">Optional</span></div>

                <div class="pm-seg" id="pmAudioTabs">
                    <button type="button" class="pm-audio-mode-btn active" data-mode="none" onclick="pmSetAudioMode('none')">No sound</button>
                    <button type="button" class="pm-audio-mode-btn" data-mode="original" id="pmAudioOriginalBtn" onclick="pmSetAudioMode('original')" style="display:none;">Original</button>
                    <button type="button" class="pm-audio-mode-btn" data-mode="upload" onclick="pmSetAudioMode('upload')">Upload</button>
                    <button type="button" class="pm-audio-mode-btn" data-mode="library" onclick="pmSetAudioMode('library')">Library</button>
                </div>

                <input type="hidden" name="audio_mode" id="pmAudioMode" value="none">
                <input type="hidden" name="selected_audio_id" id="pmSelectedAudioId" value="">

                <div id="pmAudioUploadArea" style="display:none;margin-top:12px;">
                    <label for="pmAudioFileInput" class="pm-drop">
                        <i class="ri-mic-line"></i>
                        <p>Select an MP3, WAV, M4A, OGG or AAC file</p>
                        <small>One track per post</small>
                        <input type="file" id="pmAudioFileInput" name="audio_file" accept=".mp3,.wav,.m4a,.ogg,.aac,audio/*" onchange="pmHandleAudioFile(this.files)">
                    </label>
                </div>

                <div id="pmAudioLibraryArea" style="display:none;margin-top:12px;">
                    <?php if (empty($library_audios)): ?>
                    <div class="pm-empty"><i class="ri-music-2-line"></i>No library tracks yet — be the first to upload one!</div>
                    <?php else: ?>
                    <div class="pm-audio-lib-list">
                        <?php foreach ($library_audios as $a):
                            $lib_label = $a['title'] . (!empty($a['artist']) ? ' — ' . $a['artist'] : '');
                        ?>
                        <div class="pm-audio-lib-item" onclick='pmSelectLibraryAudio(<?php echo (int)$a["id"]; ?>, <?php echo json_encode($lib_label); ?>, <?php echo json_encode("uploads/" . $a["file_name"]); ?>)'>
                            <div class="pm-audio-lib-icon"><i class="ri-music-2-fill"></i></div>
                            <div class="pm-audio-lib-info">
                                <div class="pm-audio-lib-title"><?php echo htmlspecialchars($lib_label); ?></div>
                                <div class="pm-audio-lib-sub"><?php echo (int)($a['uses_count'] ?? 0); ?> uses</div>
                            </div>
                            <i class="ri-play-circle-line pm-audio-lib-play"></i>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="pmAudioSelectedPill" class="pm-audio-pill" style="display:none;">
                    <i class="ri-music-2-fill"></i>
                    <span id="pmAudioPillText">Track</span>
                    <audio id="pmAudioPreviewPlayer" style="display:none;"></audio>
                    <button type="button" class="pm-pill-btn play" onclick="pmToggleAudioPreview()" aria-label="Preview"><i class="ri-play-fill" id="pmAudioPreviewIcon"></i></button>
                    <button type="button" class="pm-pill-btn rm" onclick="pmClearAudio()" aria-label="Remove">✕</button>
                </div>

                <div id="pmAudioVolumeControls" class="pm-vol" style="display:none;">
                    <div class="pm-vol-row">
                        <span>🎵 Music</span>
                        <input type="range" id="pmMusicVolumeRange" name="music_volume" min="0" max="100" value="80" oninput="document.getElementById('pmMusicVolumeVal').textContent=this.value">
                        <span id="pmMusicVolumeVal">80</span>
                    </div>
                    <div class="pm-vol-row" id="pmVideoAudioVolumeRow" style="display:none;">
                        <span>🎬 Video</span>
                        <input type="range" id="pmVideoAudioVolumeRange" name="video_audio_volume" min="0" max="100" value="40" oninput="document.getElementById('pmVideoAudioVolumeVal').textContent=this.value">
                        <span id="pmVideoAudioVolumeVal">40</span>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════
                 ZEEM CONNECT (optional — reference only; the connected
                 Zeem itself is never edited, merged, or embedded).
                 Viewers see a lower-left overlay on the media reading
                 "▶ View · Connected Zeem" (or your custom label).
            ═══════════════════════════ -->
            <div class="pm-sec pm-zeem-block">
                <button type="button" class="pm-zeem-toggle-btn" id="pmZeemToggleBtn" onclick="pmToggleZeemConnectPanel()">
                    <i class="ri-link"></i>
                    <span>Connect a Zeem</span>
                    <span class="pm-optional">Optional</span>
                </button>

                <div id="pmZeemConnectPanel" class="pm-zeem-panel" style="display:none;">
                    <input type="text" id="pmZeemSearchInput" class="pm-input" placeholder="Search your Zeems…" oninput="pmFilterZeemList()" autocomplete="off">
                    <div class="pm-zeem-section-label">Your Zeems · tap the post you want to link</div>
                    <div class="pm-zg-grid" id="pmZeemLibList"></div>
                    <div class="pm-empty" id="pmZeemLibEmpty" style="display:none;"><i class="ri-links-line"></i>No matching Zeems found.</div>
                </div>

                <input type="hidden" name="connected_meme_id" id="pmConnectedMemeId" value="">
                <input type="hidden" name="connected_label" id="pmConnectedLabel" value="">

                <div id="pmZeemConnectedCard" class="pm-zeem-connected-card" style="display:none;">
                    <div class="pm-zeem-connected-thumb" id="pmZeemConnectedThumb"></div>
                    <div class="pm-zeem-connected-info">
                        <div class="pm-zeem-connected-caption" id="pmZeemConnectedCaption">Connected Zeem</div>
                        <div class="pm-zeem-connected-badge"><i class="ri-checkbox-circle-fill"></i> Connected</div>
                    </div>
                    <button type="button" class="pm-btn sm ghost" onclick="pmChangeConnectedZeem()">Change</button>
                    <button type="button" class="pm-pill-btn rm" onclick="pmClearConnectedZeem()" aria-label="Remove connected Zeem">✕</button>
                </div>

                <!-- Custom button label — optional. Blank = "▶ View · Connected Zeem" -->
                <div id="pmZeemLabelArea" class="pm-zeem-label-area" style="display:none;">
                    <label class="pm-zeem-label-title" for="pmZeemLabelInput">Button label (optional)</label>
                    <input type="text" id="pmZeemLabelInput" class="pm-input" maxlength="40" placeholder="e.g. Part 2, The Aftermath, My Reaction 😂" oninput="pmSyncZeemLabel()" autocomplete="off">
                    <div class="pm-zeem-label-preview">▶ View · <span id="pmZeemLabelPreview">Connected Zeem</span></div>
                </div>
            </div>

        </div><!-- /.pm-body -->

        <!-- ACTION BAR -->
        <div class="pm-actionbar">
            <button type="button" class="pm-btn ghost" id="pmDraftBtn" onclick="pmSaveCurrentAsDraft(this)">
                <i class="ri-save-line"></i> Save Draft
            </button>
            <button type="submit" name="upload_post" class="pm-btn pm-btn-main" id="pmSubmitBtn">
                <i class="ri-send-plane-fill"></i> Upload Post
            </button>
        </div>
    </form>
 </div><!-- /.pm-lane -->

 <!-- PROGRESS OVERLAY (shown while the post uploads) -->
 <div class="pm-pg" id="pmPg">
    <div class="pm-pg-card">
        <div class="pm-pg-ic" id="pmPgIc"><i class="ri-upload-cloud-2-line" id="pmPgI"></i></div>
        <div class="pm-pg-title" id="pmPgTitle">Uploading…</div>
        <div class="pm-pg-bar"><span id="pmPgFill"></span></div>
        <div class="pm-pg-row"><span id="pmPgSub">Starting…</span><b id="pmPgPct">0%</b></div>
        <button type="button" class="pm-btn pm-pg-close" id="pmPgClose" onclick="PMPG.hide()">Close</button>
    </div>
 </div>
</div>

<style>
/* ═══════════════════════════════════════════════════════════
   POST MODAL — same design language as upload.php
   • full-screen overlay, content in a CENTERED LANE
   • two grey lines separate the lane from the empty space
   • rectangular 5px buttons, premium fluid sizing
   • dark mid-tone BLUE accent (red is only used for errors)
   • text/icons on solid blue are BLACK
═══════════════════════════════════════════════════════════ */
#postModal {
    --pm-blue:#2a5fd8; --pm-blue-h:#234fb5; --pm-blue-l:#7ea6ff;
    --pm-blue-dim:rgba(42,95,216,.18); --pm-blue-glow:rgba(42,95,216,.34);
    --pm-on-blue:#000;
    --pm-card:rgba(var(--fg,255,255,255),.05); --pm-card2:rgba(var(--fg,255,255,255),.08);
    --pm-line:rgba(var(--fg,255,255,255),.09); --pm-line2:rgba(var(--fg,255,255,255),.14);
    --pm-sub:rgba(var(--fg,255,255,255),.62); --pm-mut:rgba(var(--fg,255,255,255),.45);
    --pm-pad:calc(clamp(9px,3vw,16px) * var(--md-s));
    --pm-act-h:calc(clamp(25px,7.2vw,30px) * var(--md-s));
    --pm-r:5px;
    --pm-fs:calc(.8rem * var(--md-s));
    --pm-tb-h:calc(54px * var(--md-s));

    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999;
    background: linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--pm-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
}
@media (max-width:490px){ #postModal{ --pm-fs:calc(.74rem * var(--md-s)); --pm-tb-h:calc(48px * var(--md-s)); } }
@media (max-width:390px){ #postModal{ --pm-fs:calc(.7rem * var(--md-s));  --pm-tb-h:calc(44px * var(--md-s)); } }
#postModal.open { display: flex; animation: pmSlideIn 0.28s cubic-bezier(0.22,1,0.36,1); }
@keyframes pmSlideIn  { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes pmSlideOut { to { opacity: 0; transform: translateY(16px); } }

#postModal *, #postModal *::before, #postModal *::after { box-sizing: border-box; }
#postModal button, #postModal input, #postModal textarea { font-family: 'Poppins', sans-serif; }
#postModal button { cursor: pointer; -webkit-tap-highlight-color: transparent; }
#postModal button, #postModal a, #postModal input, #postModal textarea { outline: none; }
/* icons keep the icon font */
#postModal [class^="ri-"], #postModal [class*=" ri-"],
#postModal [class^="ri-"]::before, #postModal [class*=" ri-"]::before {
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

/* ── Top bar ── */
.pm-topbar {
    flex-shrink: 0;
    display: flex; align-items: center;
    min-height: var(--pm-tb-h);
    padding: env(safe-area-inset-top) calc(10px * var(--md-s)) 0;
    background: transparent;
}
.pm-tb-side { flex: 0 0 38px; display: flex; align-items: center; }
.pm-tb-side.right { justify-content: flex-end; }
.pm-x {
    width: calc(34px * var(--md-s)); height: calc(34px * var(--md-s)); background: none; border: none; color: var(--text,#F0F2FF);
    display: flex; align-items: center; justify-content: center; opacity: .9;
    padding: 0; transition: 0.2s;
}
.pm-x:hover { color: var(--pm-blue-l); opacity: 1; }
.pm-x svg { width: calc(22px * var(--md-s)); height: calc(22px * var(--md-s)); display: block; }
.pm-topbar-center { flex: 1; min-width: 0; text-align: center; }
.pm-topbar-title {
    font-size: calc(.92rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width:490px){ .pm-topbar-title{ font-size:calc(.84rem * var(--md-s)); } .pm-x{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); } }
@media (max-width:390px){ .pm-topbar-title{ font-size:calc(.8rem * var(--md-s)); } }

.pm-drafts-btn {
    position: relative;
    width: calc(32px * var(--md-s)); height: var(--pm-act-h);
    display: flex; align-items: center; justify-content: center;
    border: none; border-radius: var(--pm-r);
    background: rgba(var(--fg,255,255,255),.1); color: var(--pm-blue-l);
    font-size: calc(16px * var(--md-s)); transition: 0.2s;
}
.pm-drafts-btn:hover { background: rgba(var(--fg,255,255,255),.2); }
.pm-drafts-btn.pm-drafts-active { background: var(--pm-blue-dim); color: #fff; }
.pm-drafts-badge {
    position: absolute; top: calc(-5px * var(--md-s)); right: calc(-5px * var(--md-s));
    min-width: calc(16px * var(--md-s)); height: calc(16px * var(--md-s)); padding: 0 calc(4px * var(--md-s));
    border-radius: calc(4px * var(--md-s)); background: var(--pm-blue); color: var(--pm-on-blue);
    font-size: calc(9.5px * var(--md-s)); font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--lane,#070924);
}

/* ── form / scroll area ── */
.pm-form { flex: 1; min-height: 0; display: flex; flex-direction: column; margin: 0; }
.pm-body {
    flex: 1; min-height: 0;
    overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch;
    padding: calc(4px * var(--md-s)) var(--pm-pad) calc(18px * var(--md-s));
    scrollbar-width: thin; scrollbar-color: rgba(42,95,216,.4) transparent;
}
.pm-body::-webkit-scrollbar { width: calc(4px * var(--md-s)); }
.pm-body::-webkit-scrollbar-thumb { background: rgba(42,95,216,.45); border-radius: calc(99px * var(--md-s)); }

.upload-error {
    background: rgba(255,45,45,.12); border: 1px solid rgba(255,45,45,.35);
    border-radius: var(--pm-r); padding: calc(9px * var(--md-s)) calc(12px * var(--md-s)); color: #ff7a7a;
    font-size: calc(.72rem * var(--md-s)); margin-bottom: calc(12px * var(--md-s)); display: flex; align-items: center; gap: calc(7px * var(--md-s));
}

/* ── Buttons: rectangular, same sizes as the upload page ── */
.pm-btn {
    display: flex; align-items: center; justify-content: center; gap: calc(6px * var(--md-s));
    flex: 1; min-width: 0; height: var(--pm-act-h); padding: 0 calc(14px * var(--md-s));
    border: none; border-radius: var(--pm-r);
    background: var(--pm-blue); color: var(--pm-on-blue);
    font-size: calc(.79rem * var(--md-s)); font-weight: 700; line-height: 1; white-space: nowrap;
    transition: background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.pm-btn:hover { background: var(--pm-blue-h); box-shadow: 0 4px 16px var(--pm-blue-glow); }
.pm-btn:active { transform: scale(.98); }
.pm-btn:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }
.pm-btn.ghost { background: rgba(var(--fg,255,255,255),.1); color: var(--text,#F0F2FF); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: none; }
.pm-btn.ghost:hover { background: rgba(var(--fg,255,255,255),.2); }
.pm-btn.sm { flex: 0 0 auto; padding: 0 calc(12px * var(--md-s)); font-size: calc(.75rem * var(--md-s)); }
.pm-btn i { font-size: calc(14px * var(--md-s)); }
@media (max-width:490px){ .pm-btn{ font-size:calc(.68rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); gap:calc(4px * var(--md-s)); } .pm-btn.sm{ font-size:calc(.66rem * var(--md-s)); } .pm-btn i{ font-size:calc(12px * var(--md-s)); } }

/* ── Action bar (sticks to the bottom of the lane) ── */
.pm-actionbar {
    flex-shrink: 0; display: flex; gap: calc(8px * var(--md-s));
    padding: calc(8px * var(--md-s)) var(--pm-pad) calc(calc(10px * var(--md-s)) + env(safe-area-inset-bottom));
}
.pm-btn-main { flex: 1.4; }

/* ── Drafts panel ── */
.pm-drafts-section {
    background: var(--pm-blue-dim); border: 1px solid rgba(42,95,216,.35);
    border-radius: calc(10px * var(--md-s)); padding: calc(12px * var(--md-s)) calc(12px * var(--md-s)) calc(4px * var(--md-s)); margin: calc(6px * var(--md-s)) 0 calc(14px * var(--md-s));
}
.pm-drafts-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: calc(8px * var(--md-s)); }
.pm-drafts-title { display: flex; align-items: center; gap: calc(6px * var(--md-s)); font-size: calc(.76rem * var(--md-s)); font-weight: 700; color: var(--pm-blue-l); }
.pm-drafts-title i { font-size: calc(14px * var(--md-s)); }
.pm-drafts-clear { background: none; border: none; color: var(--pm-blue-l); font-size: calc(.68rem * var(--md-s)); font-weight: 600; }
.pm-drafts-clear:hover { color: var(--text,#F0F2FF); }
.pm-drafts-note { display: flex; align-items: center; gap: calc(6px * var(--md-s)); font-size: calc(.64rem * var(--md-s)); color: var(--pm-mut); margin-bottom: calc(8px * var(--md-s)); }
.pm-drafts-note i { font-size: calc(12px * var(--md-s)); flex-shrink: 0; }
.pm-drafts-empty { font-size: calc(.7rem * var(--md-s)); color: var(--pm-mut); font-style: italic; padding: calc(6px * var(--md-s)) 2px calc(12px * var(--md-s)); }
.pm-draft-row { display: flex; align-items: center; gap: calc(8px * var(--md-s)); padding: calc(8px * var(--md-s)) 2px; border-top: 1px solid rgba(var(--fg,255,255,255),.06); }
.pm-draft-row:first-of-type { border-top: none; }
.pm-draft-info { flex: 1; min-width: 0; cursor: pointer; }
.pm-draft-caption { font-size: calc(.76rem * var(--md-s)); color: rgba(var(--fg,255,255,255),.88); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-draft-time { font-size: calc(.6rem * var(--md-s)); color: var(--pm-mut); margin-top: 2px; }
.pm-draft-use-btn {
    height: calc(24px * var(--md-s)); padding: 0 calc(11px * var(--md-s)); border: none; border-radius: var(--pm-r); flex-shrink: 0;
    background: var(--pm-blue); color: var(--pm-on-blue); font-size: calc(.66rem * var(--md-s)); font-weight: 700; transition: .2s;
}
.pm-draft-use-btn:hover { background: var(--pm-blue-h); }
.pm-draft-del-btn {
    width: calc(24px * var(--md-s)); height: calc(24px * var(--md-s)); border: none; border-radius: var(--pm-r); flex-shrink: 0;
    background: rgba(var(--fg,255,255,255),.08); color: var(--pm-sub); font-size: calc(14px * var(--md-s));
    display: flex; align-items: center; justify-content: center; transition: .2s;
}
.pm-draft-del-btn:hover { background: rgba(var(--fg,255,255,255),.18); color: var(--text,#F0F2FF); }

/* ── User row ── */
.pm-user-row { display: flex; align-items: center; gap: calc(10px * var(--md-s)); margin: calc(8px * var(--md-s)) 0 calc(14px * var(--md-s)); }
.post-modal-pic {
    width: calc(clamp(34px,9vw,42px) * var(--md-s)); height: calc(clamp(34px,9vw,42px) * var(--md-s));
    border-radius: 50%; object-fit: cover; flex-shrink: 0;
    border: 2px solid var(--pm-blue);
}
.pm-user-label { font-size: calc(.6rem * var(--md-s)); color: var(--pm-mut); }
.pm-user-name { font-size: calc(.82rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); }
@media (max-width:490px){ .pm-user-name{ font-size:calc(.76rem * var(--md-s)); } }

/* ── Dropzone ── */
.pm-drop {
    border: 1.5px dashed var(--pm-line2); border-radius: calc(10px * var(--md-s)); padding: calc(22px * var(--md-s)) calc(14px * var(--md-s));
    text-align: center; transition: .2s; cursor: pointer; display: block; background: var(--pm-card);
}
.pm-drop:hover, .pm-drop.over { border-color: var(--pm-blue); background: var(--pm-blue-dim); }
.pm-drop i { font-size: calc(26px * var(--md-s)); color: var(--pm-blue-l); display: block; margin-bottom: calc(6px * var(--md-s)); }
.pm-drop p { font-size: calc(.78rem * var(--md-s)); color: var(--text,#F0F2FF); font-weight: 500; }
.pm-drop small { display: block; color: var(--pm-mut); font-size: calc(.66rem * var(--md-s)); margin-top: calc(3px * var(--md-s)); }
.pm-drop input[type=file] { display: none; }
@media (max-width:490px){ .pm-drop{ padding:calc(18px * var(--md-s)) calc(12px * var(--md-s)); } .pm-drop i{ font-size:calc(22px * var(--md-s)); } .pm-drop p{ font-size:calc(.72rem * var(--md-s)); } }

/* ── Media strip (cover + reorder) ── */
.pm-strip { margin-top: calc(10px * var(--md-s)); }
.pm-strip-inner { display: flex; gap: calc(7px * var(--md-s)); overflow-x: auto; padding: calc(4px * var(--md-s)) 2px; scrollbar-width: none; }
.pm-strip-inner::-webkit-scrollbar { display: none; }
.pm-thumb {
    position: relative; flex-shrink: 0; width: calc(58px * var(--md-s)); height: calc(58px * var(--md-s)); border-radius: calc(8px * var(--md-s)); overflow: hidden;
    border: 2px solid var(--pm-line2); cursor: grab; background: #000;
}
.pm-thumb.cover { border-color: var(--pm-blue); }
.pm-thumb img, .pm-thumb video { width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none; }
.pm-thumb-badge { position: absolute; top: calc(3px * var(--md-s)); left: calc(3px * var(--md-s)); background: var(--pm-blue); color: var(--pm-on-blue); font-size: calc(7.5px * var(--md-s)); font-weight: 800; padding: 1px calc(5px * var(--md-s)); border-radius: calc(4px * var(--md-s)); }
.pm-thumb-remove {
    position: absolute; top: calc(3px * var(--md-s)); right: calc(3px * var(--md-s)); width: calc(17px * var(--md-s)); height: calc(17px * var(--md-s)); border-radius: calc(4px * var(--md-s));
    background: rgba(0,0,0,.8); color: #fff; border: none; font-size: calc(9px * var(--md-s));
    display: flex; align-items: center; justify-content: center; padding: 0;
}
.pm-thumb-remove:hover { background: var(--pm-blue); color: var(--pm-on-blue); }
.pm-thumb-add {
    flex-shrink: 0; width: calc(58px * var(--md-s)); height: calc(58px * var(--md-s)); border-radius: calc(8px * var(--md-s)); border: 2px dashed var(--pm-line2);
    background: var(--pm-card); display: flex; align-items: center; justify-content: center;
    color: var(--pm-sub); font-size: calc(20px * var(--md-s)); cursor: pointer; padding: 0;
}
.pm-thumb-add:hover { border-color: var(--pm-blue); color: var(--pm-blue-l); }
@media (max-width:490px){ .pm-thumb, .pm-thumb-add{ width:calc(50px * var(--md-s)); height:calc(50px * var(--md-s)); } }
.pm-hint { font-size: calc(.66rem * var(--md-s)); color: var(--pm-mut); margin-top: calc(6px * var(--md-s)); line-height: 1.5; }
.pm-hint strong { color: var(--pm-sub); }

/* ── Preview ── */
.pm-preview {
    position: relative; width: min(100%,250px); margin: calc(14px * var(--md-s)) auto 0; aspect-ratio: 4/5;
    border-radius: calc(10px * var(--md-s)); overflow: hidden; background: var(--pm-card); border: 1px solid var(--pm-line);
}
.pm-preview img, .pm-preview video { width: 100%; height: 100%; object-fit: cover; display: block; }
.pm-preview-label {
    position: absolute; bottom: calc(8px * var(--md-s)); left: calc(8px * var(--md-s)); background: rgba(0,0,0,.65); backdrop-filter: blur(8px);
    border-radius: calc(5px * var(--md-s)); padding: calc(3px * var(--md-s)) calc(8px * var(--md-s)); font-size: calc(8.5px * var(--md-s)); font-weight: 700; color: rgba(255,255,255,.75);
    letter-spacing: .6px; text-transform: uppercase;
}
.pm-preview-audio {
    position: absolute; top: calc(8px * var(--md-s)); left: calc(8px * var(--md-s)); max-width: calc(100% - calc(16px * var(--md-s))); background: var(--pm-blue);
    border-radius: calc(5px * var(--md-s)); padding: calc(3px * var(--md-s)) calc(8px * var(--md-s)); font-size: calc(9.5px * var(--md-s)); font-weight: 700; color: var(--pm-on-blue);
    display: flex; align-items: center; gap: calc(4px * var(--md-s)); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width:490px){ .pm-preview{ width:min(100%,210px); } }

/* ── Inputs ── */
.pm-field { margin-top: calc(14px * var(--md-s)); }
.pm-input {
    width: 100%; height: var(--pm-act-h); padding: 0 calc(11px * var(--md-s)); border-radius: var(--pm-r);
    border: 1px solid var(--pm-line2); background: var(--pm-card); color: var(--text,#F0F2FF);
    font-size: calc(.76rem * var(--md-s)); transition: .2s; -webkit-appearance: none; appearance: none;
}
.pm-input:focus { border-color: rgba(42,95,216,.85); box-shadow: 0 0 0 3px rgba(42,95,216,.18); }
.pm-input::placeholder { color: var(--pm-mut); }
.pm-textarea { height: auto; min-height: calc(84px * var(--md-s)); padding: calc(9px * var(--md-s)) calc(11px * var(--md-s)); line-height: 1.6; resize: none; font-size: calc(.82rem * var(--md-s)); }
@media (max-width:490px){ .pm-input{ font-size:calc(.7rem * var(--md-s)); } .pm-textarea{ font-size:calc(.76rem * var(--md-s)); } }
.hashtag-hint { font-size: calc(.66rem * var(--md-s)); color: var(--pm-mut); margin-top: calc(6px * var(--md-s)); padding-left: 2px; transition: color .25s; }
.hashtag-hint.valid   { color: var(--pm-blue-l); }
.hashtag-hint.invalid { color: var(--pm-blue-l); }
/* @mention not allowed (red = error colour) */
.pm-mention-hint {
    margin-top: calc(6px * var(--md-s)); padding: calc(7px * var(--md-s)) calc(10px * var(--md-s)); border-radius: var(--pm-r);
    background: rgba(255,45,45,.12); border: 1px solid rgba(255,45,45,.35);
    color: #ff7a7a; font-size: calc(.68rem * var(--md-s)); line-height: 1.45;
}

/* ── Sections ── */
.pm-sec { margin-top: calc(16px * var(--md-s)); padding-top: calc(14px * var(--md-s)); border-top: 1px solid var(--pm-line); }
.pm-sec-h {
    display: flex; align-items: center; gap: calc(6px * var(--md-s)); margin-bottom: calc(9px * var(--md-s));
    font-size: calc(.64rem * var(--md-s)); font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--pm-sub);
}
.pm-sec-h i { font-size: calc(14px * var(--md-s)); color: var(--pm-blue-l); }
.pm-optional {
    margin-left: auto; font-size: calc(.58rem * var(--md-s)); font-weight: 600; letter-spacing: .4px; text-transform: uppercase;
    color: var(--pm-mut); background: rgba(var(--fg,255,255,255),.07); padding: 2px calc(8px * var(--md-s)); border-radius: calc(4px * var(--md-s));
}
.pm-empty { padding: calc(14px * var(--md-s)) 0; text-align: center; color: var(--pm-mut); font-size: calc(.72rem * var(--md-s)); }
.pm-empty i { font-size: calc(24px * var(--md-s)); display: block; margin-bottom: calc(6px * var(--md-s)); color: rgba(126,166,255,.45); }

/* ── Pod hashtags ── */
.pm-hashtag-pills { display: flex; flex-wrap: wrap; gap: calc(7px * var(--md-s)); }
.pm-hashtag-pill {
    height: var(--pm-act-h); padding: 0 calc(12px * var(--md-s)); border: none; border-radius: var(--pm-r);
    background: rgba(var(--fg,255,255,255),.1); color: var(--text,#F0F2FF); font-size: calc(.74rem * var(--md-s)); font-weight: 600; transition: .2s;
}
.pm-hashtag-pill:hover { background: rgba(var(--fg,255,255,255),.2); }
.pm-hashtag-pill:active { transform: scale(.96); }
.pm-hashtag-pill.pm-tag-used { background: var(--pm-blue); color: var(--pm-on-blue); }
@media (max-width:490px){ .pm-hashtag-pill{ font-size:calc(.66rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); } }

/* ── Tag people ── */
.pm-tg-chips { display: flex; flex-wrap: wrap; gap: calc(6px * var(--md-s)); margin-top: calc(10px * var(--md-s)); }
.pm-tg-chips:empty { display: none; }
.pm-tg-chip {
    display: inline-flex; align-items: center; gap: calc(6px * var(--md-s)); height: var(--pm-act-h); padding: 0 calc(4px * var(--md-s));
    border-radius: calc(99px * var(--md-s)); background: var(--pm-blue-dim); border: 1px solid rgba(42,95,216,.4);
    color: #fff; font-size: calc(.72rem * var(--md-s)); font-weight: 600; max-width: 100%;
}
.pm-tg-chip img { width: calc(var(--pm-act-h) - calc(8px * var(--md-s))); height: calc(var(--pm-act-h) - calc(8px * var(--md-s))); border-radius: 50%; object-fit: cover; background: #15183a; flex-shrink: 0; }
.pm-tg-chip span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: calc(130px * var(--md-s)); }
.pm-tg-chip button {
    width: calc(20px * var(--md-s)); height: calc(20px * var(--md-s)); border-radius: 50%; border: none; background: rgba(var(--fg,255,255,255),.14);
    color: var(--text,#F0F2FF); font-size: calc(11px * var(--md-s)); display: flex; align-items: center; justify-content: center; flex-shrink: 0; padding: 0;
}
.pm-tg-chip button:hover { background: var(--pm-blue); color: var(--pm-on-blue); }
.pm-tg-list { display: flex; flex-direction: column; gap: calc(6px * var(--md-s)); margin-top: calc(10px * var(--md-s)); }
.pm-tg-row {
    display: flex; align-items: center; gap: calc(10px * var(--md-s)); width: 100%; padding: calc(8px * var(--md-s)) calc(10px * var(--md-s)); border-radius: calc(8px * var(--md-s));
    background: var(--pm-card); border: 1px solid var(--pm-line); color: var(--text,#F0F2FF); text-align: left; transition: .2s;
}
.pm-tg-row:hover:not(:disabled) { border-color: rgba(42,95,216,.6); background: var(--pm-blue-dim); }
.pm-tg-row:disabled { opacity: .6; cursor: not-allowed; }
.pm-tg-av { width: calc(36px * var(--md-s)); height: calc(36px * var(--md-s)); border-radius: 50%; object-fit: cover; background: #15183a; flex-shrink: 0; }
.pm-tg-inf { flex: 1; min-width: 0; }
.pm-tg-inf strong { display: block; font-size: calc(.74rem * var(--md-s)); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-tg-inf span { display: block; font-size: calc(.64rem * var(--md-s)); color: var(--pm-mut); margin-top: 1px; line-height: 1.4; }
.pm-tg-inf span.no { color: #ff7a7a; }
.pm-tg-end { font-size: calc(.66rem * var(--md-s)); font-weight: 700; color: var(--pm-blue-l); flex-shrink: 0; }

/* ── Rows + switch ── */
.pm-rows { padding-top: calc(4px * var(--md-s)); }
.pm-row { display: flex; align-items: center; gap: calc(12px * var(--md-s)); padding: calc(10px * var(--md-s)) 0; }
.pm-row > i { font-size: calc(20px * var(--md-s)); width: calc(24px * var(--md-s)); text-align: center; flex-shrink: 0; color: var(--pm-blue-l); }
.pm-lbl { flex: 1; min-width: 0; font-size: calc(.8rem * var(--md-s)); }
.pm-subfield { padding: 0 0 calc(6px * var(--md-s)) calc(36px * var(--md-s)); }
@media (max-width:490px){ .pm-row{ gap:calc(10px * var(--md-s)); } .pm-row > i{ font-size:calc(18px * var(--md-s)); width:calc(21px * var(--md-s)); } .pm-lbl{ font-size:calc(.74rem * var(--md-s)); } .pm-subfield{ padding-left:0; } }
.pm-switch { position: relative; display: inline-block; width: calc(40px * var(--md-s)); height: calc(23px * var(--md-s)); flex-shrink: 0; }
.pm-switch input { opacity: 0; width: 0; height: 0; }
.pm-slider { position: absolute; cursor: pointer; inset: 0; background: rgba(var(--fg,255,255,255),.18); border-radius: calc(34px * var(--md-s)); transition: .25s; }
.pm-slider::before { position: absolute; content: ""; height: calc(17px * var(--md-s)); width: calc(17px * var(--md-s)); left: calc(3px * var(--md-s)); bottom: calc(3px * var(--md-s)); background: #fff; border-radius: 50%; transition: .25s; }
.pm-switch input:checked + .pm-slider { background: var(--pm-blue); }
.pm-switch input:checked + .pm-slider::before { transform: translateX(17px); background: #000; }
@media (max-width:490px){ .pm-switch{ width:calc(36px * var(--md-s)); height:calc(21px * var(--md-s)); } .pm-slider::before{ height:calc(15px * var(--md-s)); width:calc(15px * var(--md-s)); } .pm-switch input:checked + .pm-slider::before{ transform:translateX(15px); } }

/* ── Segmented switch (audio mode) ── */
.pm-seg { display: flex; flex-wrap: wrap; padding: calc(3px * var(--md-s)); border-radius: var(--pm-r); background: var(--pm-card); border: 1px solid var(--pm-line); gap: calc(3px * var(--md-s)); }
.pm-audio-mode-btn {
    flex: 1; min-width: calc(70px * var(--md-s)); height: calc(var(--pm-act-h) - 2px); padding: 0 calc(6px * var(--md-s)); border: none; border-radius: calc(4px * var(--md-s));
    background: none; color: var(--pm-sub); font-size: calc(.79rem * var(--md-s)); font-weight: 600; white-space: nowrap;
    display: flex; align-items: center; justify-content: center; transition: .2s;
}
.pm-audio-mode-btn.active { background: var(--pm-blue); color: var(--pm-on-blue); }
@media (max-width:490px){ .pm-audio-mode-btn{ font-size:calc(.68rem * var(--md-s)); min-width:calc(60px * var(--md-s)); } }

/* ── Audio library / pill / volume ── */
.pm-audio-lib-list { max-height: 230px; overflow-y: auto; display: flex; flex-direction: column; gap: calc(6px * var(--md-s)); }
.pm-audio-lib-item { display: flex; align-items: center; gap: calc(10px * var(--md-s)); padding: calc(8px * var(--md-s)); border-radius: calc(8px * var(--md-s)); background: var(--pm-card); border: 1px solid var(--pm-line); cursor: pointer; transition: .2s; }
.pm-audio-lib-item:hover { border-color: rgba(42,95,216,.55); background: var(--pm-blue-dim); }
.pm-audio-lib-icon { width: calc(34px * var(--md-s)); height: calc(34px * var(--md-s)); border-radius: calc(6px * var(--md-s)); background: var(--pm-blue-dim); display: flex; align-items: center; justify-content: center; color: var(--pm-blue-l); font-size: calc(15px * var(--md-s)); flex-shrink: 0; }
.pm-audio-lib-info { flex: 1; min-width: 0; }
.pm-audio-lib-title { font-size: calc(.74rem * var(--md-s)); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-audio-lib-sub { font-size: calc(.64rem * var(--md-s)); color: var(--pm-mut); }
.pm-audio-lib-play { font-size: calc(20px * var(--md-s)); color: var(--pm-mut); flex-shrink: 0; }
.pm-audio-pill { display: flex; align-items: center; gap: calc(8px * var(--md-s)); padding: calc(7px * var(--md-s)) calc(10px * var(--md-s)); border-radius: var(--pm-r); background: var(--pm-blue-dim); border: 1px solid rgba(42,95,216,.4); margin-top: calc(12px * var(--md-s)); }
.pm-audio-pill > i { color: var(--pm-blue-l); font-size: calc(15px * var(--md-s)); flex-shrink: 0; }
.pm-audio-pill > span { flex: 1; font-size: calc(.72rem * var(--md-s)); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-pill-btn { width: calc(24px * var(--md-s)); height: calc(24px * var(--md-s)); border-radius: var(--pm-r); border: none; display: flex; align-items: center; justify-content: center; font-size: calc(12px * var(--md-s)); flex-shrink: 0; padding: 0; }
.pm-pill-btn.play { background: var(--pm-blue); color: var(--pm-on-blue); }
.pm-pill-btn.rm { background: rgba(var(--fg,255,255,255),.1); color: var(--pm-sub); }
.pm-pill-btn.rm:hover { background: rgba(var(--fg,255,255,255),.2); color: var(--text,#F0F2FF); }
.pm-vol { margin-top: calc(12px * var(--md-s)); display: flex; flex-direction: column; gap: calc(9px * var(--md-s)); padding: calc(11px * var(--md-s)); background: var(--pm-card); border-radius: calc(8px * var(--md-s)); border: 1px solid var(--pm-line); }
.pm-vol-row { display: flex; align-items: center; gap: calc(8px * var(--md-s)); font-size: calc(.66rem * var(--md-s)); color: var(--pm-sub); }
.pm-vol-row span:first-child { width: calc(56px * var(--md-s)); flex-shrink: 0; }
.pm-vol-row input[type=range] { flex: 1; accent-color: var(--pm-blue); }
.pm-vol-row span:last-child { width: calc(26px * var(--md-s)); text-align: right; font-weight: 700; color: #fff; flex-shrink: 0; }

/* ── Zeem connect (the Tag people button shares this look) ── */
.pm-zeem-toggle-btn {
    width: 100%; height: var(--pm-act-h); display: flex; align-items: center; gap: calc(8px * var(--md-s)); padding: 0 calc(12px * var(--md-s));
    border: none; border-radius: var(--pm-r); background: rgba(var(--fg,255,255,255),.1);
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    color: var(--text,#F0F2FF); font-size: calc(.79rem * var(--md-s)); font-weight: 600; transition: .2s;
}
.pm-zeem-toggle-btn:hover { background: rgba(var(--fg,255,255,255),.2); }
.pm-zeem-toggle-btn > i { color: var(--pm-blue-l); font-size: calc(15px * var(--md-s)); }
.pm-zeem-toggle-btn > span:nth-of-type(1) { flex: 1; text-align: left; }
@media (max-width:490px){ .pm-zeem-toggle-btn{ font-size:calc(.68rem * var(--md-s)); } }
.pm-zeem-panel { margin-top: calc(10px * var(--md-s)); animation: pmFade .2s ease; }
@keyframes pmFade { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
.pm-zeem-section-label { margin: calc(12px * var(--md-s)) 0 calc(8px * var(--md-s)); font-size: calc(.6rem * var(--md-s)); font-weight: 700; color: var(--pm-mut); text-transform: uppercase; letter-spacing: .6px; }
.pm-zg-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: calc(8px * var(--md-s)); max-height: 360px; overflow-y: auto; padding-right: 2px; }
@media (max-width:330px){ .pm-zg-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
.pm-zg-item { display: flex; flex-direction: column; text-align: left; padding: 0; border-radius: calc(8px * var(--md-s)); overflow: hidden; background: var(--pm-card); border: 1px solid var(--pm-line); color: var(--text,#F0F2FF); transition: .2s; }
.pm-zg-item:hover { border-color: rgba(42,95,216,.7); }
.pm-zg-item.sel { border-color: var(--pm-blue); box-shadow: 0 0 0 2px var(--pm-blue-glow); }
.pm-zg-media { position: relative; display: block; width: 100%; aspect-ratio: 4/5; background: var(--surface-2,#0d1030); overflow: hidden; }
.pm-zg-media img, .pm-zg-media video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; pointer-events: none; }
.pm-zg-media.txt { background: var(--surface-2,#0d1030) url('uploads/Text.jpg') center/cover no-repeat; }
.pm-zg-txt { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; padding: calc(8px * var(--md-s)); text-align: center; background: rgba(0,0,0,.45); font-size: calc(.6rem * var(--md-s)); line-height: 1.4; font-weight: 500; word-break: break-word; }
.pm-zg-txt span { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 6; overflow: hidden; }
.pm-zg-flag { position: absolute; top: calc(5px * var(--md-s)); right: calc(5px * var(--md-s)); padding: 2px calc(6px * var(--md-s)); border-radius: calc(4px * var(--md-s)); background: rgba(0,0,0,.6); font-size: calc(.58rem * var(--md-s)); font-weight: 700; display: flex; gap: calc(3px * var(--md-s)); align-items: center; }
.pm-zg-check { position: absolute; bottom: calc(5px * var(--md-s)); right: calc(5px * var(--md-s)); width: calc(20px * var(--md-s)); height: calc(20px * var(--md-s)); border-radius: calc(5px * var(--md-s)); background: var(--pm-blue); color: var(--pm-on-blue); display: flex; align-items: center; justify-content: center; font-size: calc(13px * var(--md-s)); }
.pm-zg-cap { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; padding: calc(6px * var(--md-s)) calc(7px * var(--md-s)) calc(7px * var(--md-s)); font-size: calc(.62rem * var(--md-s)); line-height: 1.4; color: rgba(var(--fg,255,255,255),.85); min-height: 3em; }

.pm-zeem-connected-card { display: flex; align-items: center; gap: calc(10px * var(--md-s)); padding: calc(9px * var(--md-s)); border-radius: calc(8px * var(--md-s)); background: rgba(42,95,216,.12); border: 1px solid rgba(42,95,216,.4); margin-top: calc(10px * var(--md-s)); }
.pm-zeem-connected-thumb { width: calc(52px * var(--md-s)); height: calc(65px * var(--md-s)); border-radius: calc(6px * var(--md-s)); overflow: hidden; background: #000; flex-shrink: 0; }
.pm-zeem-connected-thumb img, .pm-zeem-connected-thumb video { width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none; }
.pm-zeem-connected-info { flex: 1; min-width: 0; }
.pm-zeem-connected-caption { font-size: calc(.74rem * var(--md-s)); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-zeem-connected-badge { font-size: calc(.64rem * var(--md-s)); font-weight: 700; color: var(--pm-blue-l); margin-top: calc(3px * var(--md-s)); display: flex; align-items: center; gap: calc(4px * var(--md-s)); }
.pm-zeem-label-area { margin-top: calc(10px * var(--md-s)); }
.pm-zeem-label-title { display: block; margin-bottom: calc(7px * var(--md-s)); font-size: calc(.62rem * var(--md-s)); font-weight: 700; color: var(--pm-sub); text-transform: uppercase; letter-spacing: .4px; }
.pm-zeem-label-preview { margin-top: calc(8px * var(--md-s)); font-size: calc(.68rem * var(--md-s)); font-weight: 600; color: var(--pm-sub); }
.pm-zeem-label-preview span { color: var(--pm-blue-l); }

/* ── Progress overlay ── */
.pm-pg {
    position: fixed; inset: 0; z-index: 1000000000; background: rgba(1,2,24,.78); backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center; padding: calc(20px * var(--md-s));
    opacity: 0; visibility: hidden; transition: opacity .2s;
}
.pm-pg.show { opacity: 1; visibility: visible; }
.pm-pg-card { width: 100%; max-width: 320px; background: var(--surface-2,#0d1030); border: 1px solid var(--pm-line2); border-radius: calc(12px * var(--md-s)); padding: calc(20px * var(--md-s)) calc(18px * var(--md-s)); text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.7); }
.pm-pg-ic { width: calc(48px * var(--md-s)); height: calc(48px * var(--md-s)); border-radius: 50%; background: var(--pm-blue-dim); border: 1px solid rgba(42,95,216,.45); color: var(--pm-blue-l); display: flex; align-items: center; justify-content: center; font-size: calc(22px * var(--md-s)); margin: 0 auto calc(12px * var(--md-s)); }
.pm-pg-ic.ok  { background: var(--pm-blue); border-color: var(--pm-blue); color: var(--pm-on-blue); }
.pm-pg-ic.bad { background: rgba(255,45,45,.13); border-color: rgba(255,45,45,.35); color: #ff7a7a; }
.pm-pg-title { font-size: calc(.88rem * var(--md-s)); font-weight: 600; }
.pm-pg-bar { height: calc(5px * var(--md-s)); border-radius: calc(4px * var(--md-s)); background: rgba(var(--fg,255,255,255),.09); overflow: hidden; margin: calc(14px * var(--md-s)) 0 calc(8px * var(--md-s)); }
.pm-pg-bar span { display: block; height: 100%; width: 0; background: var(--pm-blue); border-radius: calc(4px * var(--md-s)); transition: width .15s ease; }
.pm-pg-row { display: flex; justify-content: space-between; gap: calc(10px * var(--md-s)); font-size: calc(.68rem * var(--md-s)); color: var(--pm-sub); }
.pm-pg-row b { color: var(--text,#F0F2FF); }
.pm-pg-close { margin-top: calc(14px * var(--md-s)); display: none; width: 100%; flex: none; }

/* RESPONSIVE VIEW: no scrollbar at all anywhere inside the modal (they still scroll by touch) */
@media (max-width: 800px) {
    #postModal .pm-body, #postModal .pm-zg-grid, #postModal .pm-audio-lib-list { scrollbar-width: none; -ms-overflow-style: none; }
    #postModal .pm-body::-webkit-scrollbar, #postModal .pm-zg-grid::-webkit-scrollbar, #postModal .pm-audio-lib-list::-webkit-scrollbar { display: none; width: 0; height: 0; }
}

@media (prefers-reduced-motion:reduce){ #postModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>

<script>
/* ── Per-user, per-group draft storage ── */
var PM_DRAFTS_KEY = 'pm_drafts_group_<?php echo (int)$group_id; ?>_user_<?php echo (int)$current_user; ?>';
var PM_MAX_DRAFTS = 15;
var PM_MAX_FILES = 20;
var PM_GUARD_URL = 'tag_guard.php';   /* tags + @mention checks (same rules the server enforces) */
var PM_DEFAULT_PIC = 'mee.jpg';
var _pmOpen = false;
var _pmDraftsPanelOpen = false;
var _pmUploading = false;

function pmEl(id) { return document.getElementById(id); }

function pmLoadDrafts() {
    try {
        var raw = localStorage.getItem(PM_DRAFTS_KEY);
        var arr = raw ? JSON.parse(raw) : [];
        return Array.isArray(arr) ? arr : [];
    } catch (e) { return []; }
}
function pmSaveDraftsArr(arr) {
    try { localStorage.setItem(PM_DRAFTS_KEY, JSON.stringify(arr)); } catch (e) { /* storage unavailable */ }
}
function pmEsc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}
function pmAttr(s) { return pmEsc(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
function pmTruncateText(str, max) { return str.length > max ? str.slice(0, max - 1) + '…' : str; }

function pmFormatDraftTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    var mins = Math.floor((Date.now() - d.getTime()) / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return mins + 'm ago';
    var hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + 'h ago';
    var days = Math.floor(hrs / 24);
    if (days < 7) return days + 'd ago';
    return d.toLocaleDateString();
}

/* ═══════════════ PROGRESS OVERLAY ═══════════════ */
var PMPG = {
    show: function (t) {
        pmEl('pmPgIc').className = 'pm-pg-ic';
        pmEl('pmPgI').className = 'ri-upload-cloud-2-line';
        pmEl('pmPgTitle').textContent = t;
        pmEl('pmPgClose').style.display = 'none';
        this.set(0, 'Starting…');
        pmEl('pmPg').classList.add('show');
    },
    set: function (p, s) {
        p = Math.max(0, Math.min(100, p));
        pmEl('pmPgFill').style.width = p + '%';
        pmEl('pmPgPct').textContent = Math.round(p) + '%';
        if (s) pmEl('pmPgSub').textContent = s;
    },
    done: function (t, s) {
        pmEl('pmPgIc').className = 'pm-pg-ic ok';
        pmEl('pmPgI').className = 'ri-check-line';
        pmEl('pmPgTitle').textContent = t;
        this.set(100, s || '');
    },
    fail: function (t) {
        pmEl('pmPgIc').className = 'pm-pg-ic bad';
        pmEl('pmPgI').className = 'ri-error-warning-line';
        pmEl('pmPgTitle').textContent = 'Something went wrong';
        pmEl('pmPgSub').textContent = t;
        pmEl('pmPgClose').style.display = 'flex';
    },
    hide: function () { pmEl('pmPg').classList.remove('show'); }
};

/* ═══════════════ DRAFTS ═══════════════ */
function pmUpdateDraftsBadge() {
    var drafts = pmLoadDrafts();
    var badge = pmEl('pmDraftsBadge');
    if (!badge) return;
    if (drafts.length > 0) {
        badge.textContent = drafts.length > 99 ? '99+' : drafts.length;
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}

function pmRenderDrafts() {
    var drafts = pmLoadDrafts();
    var list = pmEl('pmDraftsList');
    var clearBtn = pmEl('pmDraftsClearBtn');
    if (!list) return;

    if (!drafts.length) {
        list.innerHTML = '<div class="pm-drafts-empty">No drafts saved yet — write a caption and tap "Save Draft".</div>';
        if (clearBtn) clearBtn.style.display = 'none';
        pmUpdateDraftsBadge();
        return;
    }
    if (clearBtn) clearBtn.style.display = 'inline-block';

    var sorted = drafts.slice().sort(function (a, b) { return b.id - a.id; });
    list.innerHTML = sorted.map(function (d) {
        var capShort = (d.caption || '').length > 60 ? d.caption.substring(0, 60) + '…' : (d.caption || '(empty caption)');
        return '<div class="pm-draft-row">' +
            '<div class="pm-draft-info" onclick="pmUseDraft(' + d.id + ')">' +
                '<div class="pm-draft-caption">' + pmEsc(capShort) + '</div>' +
                '<div class="pm-draft-time">' + pmFormatDraftTime(d.savedAt) + '</div>' +
            '</div>' +
            '<button type="button" class="pm-draft-use-btn" onclick="pmUseDraft(' + d.id + ')">Use</button>' +
            '<button type="button" class="pm-draft-del-btn" onclick="pmDeleteDraft(' + d.id + ')" aria-label="Delete draft"><i class="ri-close-line"></i></button>' +
        '</div>';
    }).join('');
    pmUpdateDraftsBadge();
}

function pmSaveCurrentAsDraft(btn) {
    var input = pmEl('captionInput');
    var caption = (input.value || '').trim();
    if (!caption) {
        alert('Write a caption first — there\'s nothing to save yet.');
        input.focus();
        return;
    }
    var drafts = pmLoadDrafts();
    drafts.push({ id: Date.now(), caption: caption, savedAt: new Date().toISOString() });
    if (drafts.length > PM_MAX_DRAFTS) {
        drafts.sort(function (a, b) { return a.id - b.id; });
        drafts = drafts.slice(drafts.length - PM_MAX_DRAFTS);
    }
    pmSaveDraftsArr(drafts);
    pmRenderDrafts();
    if (btn) {
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="ri-check-line"></i> Saved!';
        setTimeout(function () { btn.innerHTML = original; }, 1400);
    }
}

function pmUseDraft(id) {
    var d = pmLoadDrafts().find(function (x) { return x.id === id; });
    if (!d) return;
    var input = pmEl('captionInput');
    input.value = d.caption;
    checkHashtag(input);
    pmQueueMentionCheck();
    pmCloseDraftsPanel();
    input.focus();
}
function pmDeleteDraft(id) {
    pmSaveDraftsArr(pmLoadDrafts().filter(function (x) { return x.id !== id; }));
    pmRenderDrafts();
}
function pmClearAllDrafts() {
    if (!confirm('Delete all saved drafts? This can\'t be undone.')) return;
    pmSaveDraftsArr([]);
    pmRenderDrafts();
}
function pmToggleDraftsPanel() { _pmDraftsPanelOpen ? pmCloseDraftsPanel() : pmOpenDraftsPanel(); }
function pmOpenDraftsPanel() {
    _pmDraftsPanelOpen = true;
    pmEl('pmDraftsSection').style.display = 'block';
    document.querySelector('.pm-drafts-btn').classList.add('pm-drafts-active');
    pmRenderDrafts();
    pmEl('pmBody').scrollTop = 0;
}
function pmCloseDraftsPanel() {
    _pmDraftsPanelOpen = false;
    pmEl('pmDraftsSection').style.display = 'none';
    document.querySelector('.pm-drafts-btn').classList.remove('pm-drafts-active');
}

function pmTogglePartnership() {
    var active = pmEl('pmPartnershipCheck').checked;
    pmEl('pmIsPartnership').value = active ? '1' : '0';
    pmEl('pmPartnershipBrandArea').style.display = active ? 'block' : 'none';
    if (!active) pmEl('pmPartnerBrandInput').value = '';
}

/* ═══════════════ TAGS + @MENTIONS (early checks) ═══════════════
   The server (tag_guard.php) decides. Here we only ask it early so
   you see a clear message before uploading anything. */
function pmGuardCall(fields) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(PM_GUARD_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); });
}

var pmTags = [];        // [{id, username, pic}]
var pmTagLast = [];     // last search results
var pmTagTimer = null, pmTagSeq = 0;

function pmTagPic(u) { return (u && u.pic) ? u.pic : PM_DEFAULT_PIC; }

function pmToggleTagPanel() {
    var panel = pmEl('pmTagPanel');
    var willOpen = panel.style.display === 'none';
    panel.style.display = willOpen ? 'block' : 'none';
    if (willOpen) {
        pmEl('pmTagSearchInput').value = '';
        pmTagLast = [];
        pmTagHint('Search a username or nickname to tag someone.');
        pmEl('pmTagSearchInput').focus();
    }
}
function pmTagHint(msg) {
    pmEl('pmTagResults').innerHTML = '<div class="pm-empty"><i class="ri-user-search-line"></i>' + pmEsc(msg) + '</div>';
}
function pmTagSearch() {
    clearTimeout(pmTagTimer);
    var q = pmEl('pmTagSearchInput').value.trim().replace(/^@/, '');
    if (q.length < 2) { pmTagLast = []; pmTagHint('Type at least 2 letters.'); return; }
    pmTagTimer = setTimeout(function () {
        var seq = ++pmTagSeq;
        pmGuardCall({ tag_action: 'search', q: q }).then(function (d) {
            if (seq !== pmTagSeq) return;
            if (!d.success || !d.users || !d.users.length) { pmTagLast = []; pmTagHint('No one found.'); return; }
            pmTagLast = d.users;
            pmRenderTagResults();
        }).catch(function () { if (seq === pmTagSeq) pmTagHint('Could not search. Try again.'); });
    }, 300);
}
function pmRenderTagResults() {
    if (!pmTagLast.length) return;
    var have = {};
    pmTags.forEach(function (u) { have[u.id] = true; });
    pmEl('pmTagResults').innerHTML = pmTagLast.map(function (u) {
        var added = !!have[u.id], dis = added || !u.ok;
        var sub = !u.ok ? '<span class="no">' + pmEsc(u.why) + '</span>' : (u.nickname ? '<span>' + pmEsc(u.nickname) + '</span>' : '');
        return '<button type="button" class="pm-tg-row"' + (dis ? ' disabled' : '') + ' onclick="pmTagAdd(' + u.id + ')">' +
            '<img class="pm-tg-av" src="' + pmAttr(pmTagPic(u)) + '" alt="" onerror="this.src=\'mee.jpg\'">' +
            '<span class="pm-tg-inf"><strong>@' + pmEsc(u.username) + '</strong>' + sub + '</span>' +
            (added ? '<span class="pm-tg-end">Added</span>' : (u.ok ? '<span class="pm-tg-end">Tag</span>' : '')) +
        '</button>';
    }).join('');
}
function pmTagAdd(id) {
    var u = pmTagLast.find(function (x) { return x.id === id; });
    if (!u || !u.ok) return;
    if (pmTags.some(function (x) { return x.id === id; })) return;
    if (pmTags.length >= 20) { alert('You can tag up to 20 people.'); return; }
    pmTags.push({ id: u.id, username: u.username, pic: u.pic });
    pmRenderTags();
}
function pmTagRemove(id) {
    pmTags = pmTags.filter(function (x) { return x.id !== id; });
    pmRenderTags();
}
function pmRenderTags() {
    pmEl('pmTaggedUsers').value = pmTags.map(function (u) { return u.id; }).join(',');
    pmEl('pmTagCount').textContent = pmTags.length ? (pmTags.length === 1 ? '1 tagged' : pmTags.length + ' tagged') : 'Optional';
    pmEl('pmTagChips').innerHTML = pmTags.map(function (u) {
        return '<span class="pm-tg-chip"><img src="' + pmAttr(pmTagPic(u)) + '" alt="" onerror="this.src=\'mee.jpg\'"><span>@' + pmEsc(u.username) + '</span>' +
            '<button type="button" aria-label="Remove tag" onclick="pmTagRemove(' + u.id + ')">✕</button></span>';
    }).join('');
    pmRenderTagResults();
}

/* @mentions in the caption: a person who doesn't allow @mentions from you blocks the post */
var pmMentionTimer = null, pmMentionSeq = 0;
function pmShowMentionHint(msg) {
    var el = pmEl('pmMentionHint');
    el.textContent = msg || '';
    el.style.display = msg ? 'block' : 'none';
}
function pmCheckMentions() {
    var text = pmEl('captionInput').value;
    if (text.indexOf('@') === -1) return Promise.resolve('');
    return pmGuardCall({ tag_action: 'mention_check', text: text }).then(function (d) {
        return (d && d.success && d.blocked && d.blocked.length) ? d.blocked[0].msg : '';
    }).catch(function () { return ''; });   // network trouble: the server still enforces it
}
function pmQueueMentionCheck() {
    clearTimeout(pmMentionTimer);
    if (pmEl('captionInput').value.indexOf('@') === -1) { pmShowMentionHint(''); return; }
    pmMentionTimer = setTimeout(function () {
        var seq = ++pmMentionSeq;
        pmCheckMentions().then(function (msg) { if (seq === pmMentionSeq) pmShowMentionHint(msg); });
    }, 400);
}

/* ═══════════════ OPEN / CLOSE ═══════════════ */
function openPostModal() {
    if (_pmOpen) return;
    _pmOpen = true;
    var m = pmEl('postModal');
    m.classList.add('open');
    pmEl('pmBody').scrollTop = 0;
    document.body.style.overflow = 'hidden';
    pmUpdateDraftsBadge();
}

function pmResetForm() {
    pmSelectedFiles.forEach(function (f) { pmRevoke(f); });
    pmSelectedFiles = [];
    pmEl('postFileInput').value = '';
    pmRender();
    pmEl('captionInput').value = '';
    checkHashtag(pmEl('captionInput'));
    pmShowMentionHint('');
    pmTags = [];
    pmRenderTags();
    pmEl('pmTagPanel').style.display = 'none';
    pmEl('pmPartnershipCheck').checked = false;
    pmTogglePartnership();
    ['pmHideLikes','pmHideReposts','pmHideSaves','pmHideShares'].forEach(function(id){ var e = pmEl(id); if(e) e.checked = false; });   /* hide-count switches start off for the next post */
    pmSetAudioMode('none');
    pmClearConnectedZeem();
    pmCloseDraftsPanel();
}

function closePostModal() {
    if (!_pmOpen || _pmUploading) return;

    var input = pmEl('captionInput');
    var hasUnsavedText = input && input.value.trim().length > 0;
    if (hasUnsavedText && confirm('Save this caption as a draft before closing?')) {
        var drafts = pmLoadDrafts();
        drafts.push({ id: Date.now(), caption: input.value.trim(), savedAt: new Date().toISOString() });
        if (drafts.length > PM_MAX_DRAFTS) {
            drafts.sort(function (a, b) { return a.id - b.id; });
            drafts = drafts.slice(drafts.length - PM_MAX_DRAFTS);
        }
        pmSaveDraftsArr(drafts);
    }

    _pmOpen = false;
    var m = pmEl('postModal');
    m.style.animation = 'pmSlideOut 0.22s ease forwards';
    setTimeout(function () {
        m.classList.remove('open');
        m.style.animation = '';
        document.body.style.overflow = '';
        pmResetForm();
    }, 210);
}

/* ═══════════════ MEDIA (1–20, cover + reorder) ═══════════════ */
var pmSelectedFiles = [];
var pmUrls = new Map();

function pmUrl(f) {
    if (!pmUrls.has(f)) pmUrls.set(f, URL.createObjectURL(f));
    return pmUrls.get(f);
}
function pmRevoke(f) {
    if (pmUrls.has(f)) { URL.revokeObjectURL(pmUrls.get(f)); pmUrls.delete(f); }
}

function pmAddFiles(list) {
    var incoming = Array.prototype.slice.call(list || []).filter(function (f) {
        return f.type.indexOf('image') === 0 || f.type.indexOf('video') === 0;
    });
    if (!incoming.length) return;
    var combined = pmSelectedFiles.concat(incoming);
    if (combined.length > PM_MAX_FILES) {
        alert('You can attach up to ' + PM_MAX_FILES + ' files per post — only the first ' + PM_MAX_FILES + ' were kept.');
        combined = combined.slice(0, PM_MAX_FILES);
    }
    pmSelectedFiles = combined;
    pmSyncFileInput();
    pmRender();
    pmUpdateAudioOriginalVisibility();
}
function previewPostFiles(input) { pmAddFiles(input.files); }

function pmSyncFileInput() {
    var dt = new DataTransfer();
    pmSelectedFiles.forEach(function (f) { dt.items.add(f); });
    pmEl('postFileInput').files = dt.files;
}

function pmRemoveFile(idx) {
    pmRevoke(pmSelectedFiles[idx]);
    pmSelectedFiles.splice(idx, 1);
    pmSyncFileInput();
    pmRender();
    pmUpdateAudioOriginalVisibility();
}

function pmRender() {
    var strip = pmEl('pmStrip'), inner = pmEl('pmStripInner'), prev = pmEl('postPreview'), label = pmEl('postDropText');
    var n = pmSelectedFiles.length;

    if (!n) {
        strip.style.display = 'none';
        prev.style.display = 'none';
        prev.innerHTML = '';
        inner.innerHTML = '';
        label.textContent = 'Choose up to ' + PM_MAX_FILES + ' images or videos';
        return;
    }

    label.textContent = n + ' file' + (n > 1 ? 's' : '') + ' selected · tap to add more';
    pmEl('pmCount').textContent = n;
    strip.style.display = 'block';

    inner.innerHTML = pmSelectedFiles.map(function (f, i) {
        var url = pmUrl(f), isVideo = f.type.indexOf('video') === 0;
        return '<div class="pm-thumb' + (i === 0 ? ' cover' : '') + '" draggable="true" data-idx="' + i + '">' +
            (isVideo ? '<video src="' + url + '" muted playsinline preload="metadata"></video>' : '<img src="' + url + '" alt="">') +
            (i === 0 ? '<span class="pm-thumb-badge">COVER</span>' : '') +
            '<button type="button" class="pm-thumb-remove" onclick="pmRemoveFile(' + i + ')" aria-label="Remove">✕</button>' +
        '</div>';
    }).join('');
    if (n < PM_MAX_FILES) {
        inner.innerHTML += '<button type="button" class="pm-thumb-add" onclick="pmEl(\'postFileInput\').click()" aria-label="Add more"><i class="ri-add-line"></i></button>';
    }

    var drag = null;
    inner.querySelectorAll('.pm-thumb').forEach(function (el) {
        el.addEventListener('dragstart', function () { drag = +el.dataset.idx; });
        el.addEventListener('dragover', function (e) { e.preventDefault(); });
        el.addEventListener('drop', function (e) {
            e.preventDefault();
            var to = +el.dataset.idx;
            if (drag === null || drag === to) return;
            var moved = pmSelectedFiles.splice(drag, 1)[0];
            pmSelectedFiles.splice(to, 0, moved);
            drag = null;
            pmSyncFileInput();
            pmRender();
        });
    });

    var f0 = pmSelectedFiles[0], u0 = pmUrl(f0), v0 = f0.type.indexOf('video') === 0;
    prev.style.display = 'block';
    prev.innerHTML = (v0 ? '<video src="' + u0 + '" muted loop autoplay playsinline></video>' : '<img src="' + u0 + '" alt="">') +
        '<span class="pm-preview-label">' + (n > 1 ? '1/' + n + ' · Gallery' : (v0 ? 'Video' : 'Image')) + '</span>';
    pmUpdateAudioBadge();
}

(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var dz = pmEl('postDropLabel');
        if (!dz) return;
        ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('over'); }); });
        dz.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) pmAddFiles(e.dataTransfer.files); });
    });
})();

/* ── Insert a pod hashtag into the caption with one tap ── */
function insertHashtag(tag, btnEl) {
    var input = pmEl('captionInput');
    var needle = '#' + tag.toLowerCase();
    var current = input.value;
    if (current.toLowerCase().indexOf(needle) !== -1) { input.focus(); return; }
    var trimmed = current.replace(/\s+$/, '');
    input.value = trimmed.length ? (trimmed + ' #' + tag + ' ') : ('#' + tag + ' ');
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
    if (btnEl) btnEl.classList.add('pm-tag-used');
    checkHashtag(input);
}

function checkHashtag(input) {
    var hint = pmEl('hashtagHint');
    var hasTag = input.value.indexOf('#') !== -1;
    if (hasTag) {
        hint.textContent = '✓ Hashtag detected — good to go!';
        hint.className = 'hashtag-hint valid';
    } else {
        hint.textContent = 'Must include at least one #hashtag to post';
        hint.className = 'hashtag-hint invalid';
    }
    document.querySelectorAll('.pm-hashtag-pill').forEach(function (p) {
        var t = '#' + p.dataset.tag.toLowerCase();
        p.classList.toggle('pm-tag-used', input.value.toLowerCase().indexOf(t) !== -1);
    });
}

/* ═══════════════ ATTACHED AUDIO (optional soundtrack) ═══════════════ */
var pmSelectedAudio = null;
var pmPreviewPlaying = false;

function pmSetAudioMode(mode) {
    document.querySelectorAll('.pm-audio-mode-btn').forEach(function (b) { b.classList.remove('active'); });
    var btn = document.querySelector('.pm-audio-mode-btn[data-mode="' + mode + '"]');
    if (btn) btn.classList.add('active');
    pmEl('pmAudioMode').value = mode;
    pmEl('pmAudioUploadArea').style.display = mode === 'upload' ? 'block' : 'none';
    pmEl('pmAudioLibraryArea').style.display = mode === 'library' ? 'block' : 'none';
    if (mode === 'none' || mode === 'original') pmClearAudioSelectionOnly();
    pmUpdateVolumeVisibility();
    pmUpdateAudioBadge();
}
function pmHandleAudioFile(files) {
    if (!files || !files.length) return;
    var file = files[0];
    pmSelectedAudio = { mode: 'upload', file: file, url: URL.createObjectURL(file), title: file.name };
    pmEl('pmSelectedAudioId').value = '';
    pmShowAudioPill(file.name);
    pmUpdateVolumeVisibility();
    pmUpdateAudioBadge();
}
function pmSelectLibraryAudio(id, title, url) {
    pmSelectedAudio = { mode: 'library', id: id, url: url, title: title };
    pmEl('pmSelectedAudioId').value = id;
    pmShowAudioPill(title);
    pmUpdateVolumeVisibility();
    pmUpdateAudioBadge();
}
function pmShowAudioPill(title) {
    pmEl('pmAudioSelectedPill').style.display = 'flex';
    pmEl('pmAudioPillText').textContent = title;
    var player = pmEl('pmAudioPreviewPlayer');
    player.pause();
    player.src = pmSelectedAudio.url;
    pmPreviewPlaying = false;
    pmEl('pmAudioPreviewIcon').className = 'ri-play-fill';
}
function pmToggleAudioPreview() {
    var player = pmEl('pmAudioPreviewPlayer');
    if (!player.src) return;
    if (pmPreviewPlaying) { player.pause(); } else { player.play(); }
    pmPreviewPlaying = !pmPreviewPlaying;
    pmEl('pmAudioPreviewIcon').className = pmPreviewPlaying ? 'ri-pause-fill' : 'ri-play-fill';
    player.onended = function () {
        pmPreviewPlaying = false;
        pmEl('pmAudioPreviewIcon').className = 'ri-play-fill';
    };
}
function pmClearAudio() { pmSetAudioMode('none'); }
function pmClearAudioSelectionOnly() {
    if (pmSelectedAudio && pmSelectedAudio.mode === 'upload' && pmSelectedAudio.url) URL.revokeObjectURL(pmSelectedAudio.url);
    pmSelectedAudio = null;
    pmEl('pmSelectedAudioId').value = '';
    pmEl('pmAudioSelectedPill').style.display = 'none';
    var player = pmEl('pmAudioPreviewPlayer');
    player.pause(); player.src = ''; pmPreviewPlaying = false;
    pmEl('pmAudioFileInput').value = '';
}
function pmUpdateVolumeVisibility() {
    var mode = pmEl('pmAudioMode').value;
    var hasVideo = pmSelectedFiles.some(function (f) { return f.type.indexOf('video') === 0; });
    var hasTrack = (mode === 'upload' || mode === 'library') && pmSelectedAudio;
    pmEl('pmAudioVolumeControls').style.display = hasTrack ? 'flex' : 'none';
    pmEl('pmVideoAudioVolumeRow').style.display = (hasTrack && hasVideo) ? 'flex' : 'none';
}
function pmUpdateAudioOriginalVisibility() {
    var hasVideo = pmSelectedFiles.some(function (f) { return f.type.indexOf('video') === 0; });
    var origBtn = pmEl('pmAudioOriginalBtn');
    if (origBtn) origBtn.style.display = hasVideo ? 'flex' : 'none';
    if (!hasVideo && pmEl('pmAudioMode').value === 'original') pmSetAudioMode('none');
    pmUpdateVolumeVisibility();
}
function pmUpdateAudioBadge() {
    var box = pmEl('postPreview');
    if (!box) return;
    var old = box.querySelector('.pm-preview-audio');
    if (old) old.remove();
    var mode = pmEl('pmAudioMode').value;
    if (box.style.display !== 'none' && (mode === 'upload' || mode === 'library') && pmSelectedAudio) {
        var d = document.createElement('div');
        d.className = 'pm-preview-audio';
        d.innerHTML = '<i class="ri-music-2-fill"></i> ' + pmEsc(pmSelectedAudio.title);
        box.appendChild(d);
    }
}
function pmValidateAudio() {
    var mode = pmEl('pmAudioMode').value;
    if (mode === 'upload' && !pmSelectedAudio) {
        alert('Please select an audio file, or switch back to "No sound".');
        return false;
    }
    if (mode === 'library' && !pmEl('pmSelectedAudioId').value) {
        alert('Please pick a track from the library, or switch back to "No sound".');
        return false;
    }
    return true;
}

/* ═══════════════ ZEEM CONNECT ═══════════════
   Only the current user's own Zeems are ever loaded here (the query is
   scoped to uploaded_by = current user); the server re-checks ownership
   again on submit as a second line of defense. */
var pmMyZeems = <?php echo json_encode(array_map(function ($z) {
    return [
        'id'          => (int)$z['id'],
        'type'        => $z['type'],
        'caption'     => $z['caption'] ?: '',
        'thumb'       => ($z['type'] === 'text') ? 'uploads/Text.jpg' : 'uploads/' . $z['file_name'],
        'media_count' => (int)$z['media_count']
    ];
}, $my_zeems), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function pmZeemMedia(z) {
    if (z.type === 'video') return '<video src="' + pmEsc(z.thumb) + '#t=0.1" muted playsinline preload="metadata"></video>';
    return '<img src="' + pmEsc(z.thumb) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">';
}

function pmToggleZeemConnectPanel() {
    var panel = pmEl('pmZeemConnectPanel');
    var willOpen = panel.style.display === 'none';
    panel.style.display = willOpen ? 'block' : 'none';
    if (willOpen) {
        pmEl('pmZeemSearchInput').value = '';
        pmRenderZeemList(pmMyZeems);
    }
}

function pmRenderZeemList(list) {
    var container = pmEl('pmZeemLibList');
    var empty = pmEl('pmZeemLibEmpty');
    if (!list.length) { container.innerHTML = ''; empty.style.display = 'block'; return; }
    empty.style.display = 'none';
    var sel = parseInt(pmEl('pmConnectedMemeId').value, 10) || 0;

    container.innerHTML = list.map(function (z) {
        var txt = z.type === 'text';
        var cap = z.caption.trim() ? pmEsc(pmTruncateText(z.caption.trim(), 60)) : '(no caption)';
        var media = txt ? '<span class="pm-zg-txt"><span>' + pmEsc(pmTruncateText(z.caption.trim(), 90)) + '</span></span>' : pmZeemMedia(z);
        var flag = z.media_count > 1
            ? '<span class="pm-zg-flag"><i class="ri-stack-line"></i> ' + z.media_count + '</span>'
            : (z.type === 'video' ? '<span class="pm-zg-flag"><i class="ri-play-fill"></i></span>' : '');
        var on = z.id === sel;
        return '<button type="button" class="pm-zg-item' + (on ? ' sel' : '') + '" onclick="pmSelectZeem(' + z.id + ')">' +
            '<span class="pm-zg-media' + (txt ? ' txt' : '') + '">' + media + flag + (on ? '<span class="pm-zg-check"><i class="ri-check-line"></i></span>' : '') + '</span>' +
            '<span class="pm-zg-cap">' + cap + '</span></button>';
    }).join('');
}

function pmFilterZeemList() {
    var q = pmEl('pmZeemSearchInput').value.trim().toLowerCase();
    pmRenderZeemList(q ? pmMyZeems.filter(function (z) { return (z.caption || '').toLowerCase().indexOf(q) !== -1; }) : pmMyZeems);
}

function pmSelectZeem(id) {
    var zeem = pmMyZeems.find(function (z) { return z.id === id; });
    if (!zeem) return;
    pmEl('pmConnectedMemeId').value = zeem.id;

    /* Only the cover of the connected post is shown/referenced — never the rest of its media or its caption. */
    pmEl('pmZeemConnectedThumb').innerHTML = pmZeemMedia(zeem);
    pmEl('pmZeemConnectedCaption').textContent = zeem.caption.trim() ? pmTruncateText(zeem.caption.trim(), 60) : '(no caption)';

    pmEl('pmZeemConnectedCard').style.display = 'flex';
    pmEl('pmZeemConnectPanel').style.display = 'none';
    pmEl('pmZeemToggleBtn').style.display = 'none';

    pmEl('pmZeemLabelInput').value = '';
    pmEl('pmConnectedLabel').value = '';
    pmEl('pmZeemLabelPreview').textContent = 'Connected Zeem';
    pmEl('pmZeemLabelArea').style.display = 'block';
}

function pmChangeConnectedZeem() {
    pmEl('pmZeemConnectPanel').style.display = 'block';
    pmEl('pmZeemSearchInput').value = '';
    pmRenderZeemList(pmMyZeems);
    pmEl('pmZeemConnectPanel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function pmClearConnectedZeem() {
    pmEl('pmConnectedMemeId').value = '';
    pmEl('pmConnectedLabel').value = '';
    pmEl('pmZeemLabelInput').value = '';
    pmEl('pmZeemLabelPreview').textContent = 'Connected Zeem';
    pmEl('pmZeemLabelArea').style.display = 'none';
    pmEl('pmZeemConnectedCard').style.display = 'none';
    pmEl('pmZeemToggleBtn').style.display = 'flex';
    pmEl('pmZeemConnectPanel').style.display = 'none';
    var search = pmEl('pmZeemSearchInput');
    if (search) search.value = '';
}

/* Custom button label — viewers see "▶ View · {this}" (blank = "Connected Zeem"). */
function pmSyncZeemLabel() {
    var val = pmEl('pmZeemLabelInput').value.trim();
    pmEl('pmConnectedLabel').value = val;
    pmEl('pmZeemLabelPreview').textContent = val ? val : 'Connected Zeem';
}

/* ═══════════════ SUBMIT — uploads with a live progress bar ═══════════════
   1) quick checks (hashtag, sound, @mentions allowed?)
   2) the form is posted in the background with XMLHttpRequest so the progress
      bar can move. When the server answers:
        • if it re-rendered this modal with a ".upload-error" message (this is
          also where "@name doesn't allow tags" comes from), that message is
          shown on the progress card;
        • otherwise the post was saved and the page is taken to the server's
          final URL (a fresh GET, so a refresh never re-posts).
   If JavaScript is unavailable the form still posts normally. */
function pmSubmitPost(e) {
    e.preventDefault();
    if (_pmUploading) return;

    var captionEl = pmEl('captionInput');
    if (captionEl.value.indexOf('#') === -1) {
        var hint = pmEl('hashtagHint');
        hint.textContent = 'Must include at least one #hashtag to post';
        hint.className = 'hashtag-hint invalid';
        captionEl.focus();
        return;
    }
    if (!pmValidateAudio()) return;

    _pmUploading = true;
    pmEl('pmSubmitBtn').disabled = true;
    pmEl('pmDraftBtn').disabled = true;

    pmCheckMentions().then(function (msg) {
        if (msg) {
            _pmUploading = false;
            pmEl('pmSubmitBtn').disabled = false;
            pmEl('pmDraftBtn').disabled = false;
            pmShowMentionHint(msg);
            captionEl.focus();
            return;
        }
        pmShowMentionHint('');
        pmStartUpload(captionEl);
    });
}

function pmStartUpload(captionEl) {
    var form = pmEl('postModalForm');
    var fd = new FormData(form);
    fd.set('upload_post', '1');   // the server looks for this (it normally comes from the button)

    PMPG.show('Posting…');

    function finishFail(msg) {
        PMPG.fail(msg);
        _pmUploading = false;
        pmEl('pmSubmitBtn').disabled = false;
        pmEl('pmDraftBtn').disabled = false;
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.href);
    xhr.upload.onprogress = function (ev) {
        if (!ev.lengthComputable) return;
        var f = ev.loaded / ev.total;
        PMPG.set(f * 96, f < 1 ? 'Uploading ' + Math.round(f * 100) + '%' : 'Finishing up…');
    };
    xhr.onerror = function () { finishFail('Network error. Please try again.'); };
    xhr.ontimeout = function () { finishFail('The upload took too long. Please try again.'); };
    xhr.onload = function () {
        if (xhr.status >= 400) { finishFail('The server could not save your post (error ' + xhr.status + '). Please try again.'); return; }

        var errText = '';
        try {
            var doc = new DOMParser().parseFromString(xhr.responseText, 'text/html');
            var errEl = doc.querySelector('#postModal .upload-error');
            if (errEl) errText = (errEl.textContent || '').trim();
        } catch (err) { /* ignore parse problems */ }

        if (errText) { finishFail(errText); return; }

        // saved — forget any draft that matches this caption
        var cap = captionEl.value.trim();
        pmSaveDraftsArr(pmLoadDrafts().filter(function (d) { return (d.caption || '').trim() !== cap; }));

        PMPG.done('Posted!', 'Taking you there…');
        setTimeout(function () { window.location.href = xhr.responseURL || window.location.href; }, 900);
    };
    xhr.send(fd);
}

document.addEventListener('DOMContentLoaded', function () {
    var form = pmEl('postModalForm');
    if (form) form.addEventListener('submit', pmSubmitPost);
    pmUpdateDraftsBadge();
    pmRenderTags();
    checkHashtag(pmEl('captionInput'));

    /* If PHP rejected a normal (non-JS) upload and set $upload_error, reopen the
       modal on load so the error banner inside it is actually visible. */
    <?php if (!empty($upload_error)): ?>
    openPostModal();
    <?php endif; ?>
});

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !_pmOpen) return;
    if (pmEl('pmPg').classList.contains('show')) { if (!_pmUploading) PMPG.hide(); return; }
    if (_pmDraftsPanelOpen) pmCloseDraftsPanel();
    else closePostModal();
});
</script>

<!-- ══════════════════════════════════════
     GROUP SEARCH MODAL — centered lane, same design as the Post Modal
══════════════════════════════════════ -->
<div id="gsmModal" role="dialog" aria-modal="true" aria-label="Search this Pod">
 <div class="gsm-lane">

    <div class="gsm-topbar">
        <button type="button" class="gsm-back-btn" onclick="closeGroupSearchModal()" aria-label="Close search">
            <i class="ri-arrow-left-s-line"></i>
        </button>
        <div id="gsmSearchWrap">
            <span class="gsm-search-icon"><i class="ri-search-line"></i></span>
            <input type="text" id="gsmSearchInput" placeholder="Search members, posts, captions…" autocomplete="off">
            <button type="button" id="gsmClearBtn" onclick="gsmClearSearch()" aria-label="Clear"><i class="ri-close-line"></i></button>
        </div>
        <button type="button" id="gsmSearchBtn" onclick="gsmFullSearch()">Search</button>
    </div>

    <!-- ══ SCOPE LABEL — confirms search is locked to this Pod ══ -->
    <div class="gsm-context-bar">
        <i class="ri-shield-check-line"></i>
        <span>Searching in <strong><?php echo htmlspecialchars($group['title']); ?></strong></span>
    </div>

    <div id="gsmContent">

        <div id="gsmRecentsWrap">
            <div class="gsm-recents-header">
                <span class="gsm-recents-title">Recent</span>
                <button type="button" id="gsmClearAllBtn" onclick="gsmClearAllRecents()" style="display:none">Clear all</button>
            </div>
            <div id="gsmRecentsList"></div>
        </div>

        <div id="gsmEmptyState" class="gsm-empty" style="display:none;">
            <i class="ri-search-2-line"></i>
            <p>Search this Pod</p>
            <small>Find members, posts &amp; captions</small>
        </div>

        <div id="gsmLiveResults" style="display:none;"></div>
    </div>

 </div><!-- /.gsm-lane -->
</div>

<style>
/* ═══════════════════════════════════════════════════════════
   GROUP SEARCH MODAL — same design language as the Post Modal
   • full-screen overlay, content in a CENTERED LANE
   • two grey lines separate the lane from the empty space
   • rectangular 5px buttons, premium fluid sizing
   • dark mid-tone BLUE accent (red only for errors)
═══════════════════════════════════════════════════════════ */
#gsmModal {
    --gsm-blue:#2a5fd8; --gsm-blue-h:#234fb5; --gsm-blue-l:#7ea6ff;
    --gsm-blue-dim:rgba(42,95,216,.18); --gsm-blue-glow:rgba(42,95,216,.34);
    --gsm-card:rgba(var(--fg,255,255,255),.05); --gsm-card2:rgba(var(--fg,255,255,255),.08);
    --gsm-line:rgba(var(--fg,255,255,255),.09); --gsm-line2:rgba(var(--fg,255,255,255),.14);
    --gsm-sub:rgba(var(--fg,255,255,255),.62); --gsm-mut:rgba(var(--fg,255,255,255),.45);
    --gsm-pad:calc(clamp(9px,3vw,16px) * var(--md-s));
    --gsm-act-h:calc(clamp(25px,7.2vw,30px) * var(--md-s));
    --gsm-sb-h:calc(clamp(33px,9vw,38px) * var(--md-s));
    --gsm-r:5px;
    --gsm-fs:calc(.8rem * var(--md-s));
    --gsm-tb-h:calc(54px * var(--md-s));

    display: none;
    position: fixed;
    inset: 0;
    z-index: 2147483647;
    background: linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--gsm-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
}
@media (max-width:490px){ #gsmModal{ --gsm-fs:calc(.74rem * var(--md-s)); --gsm-tb-h:calc(48px * var(--md-s)); } }
@media (max-width:390px){ #gsmModal{ --gsm-fs:calc(.7rem * var(--md-s));  --gsm-tb-h:calc(44px * var(--md-s)); } }
#gsmModal.gsm-open { display: flex; animation: gsmSlideIn 0.28s cubic-bezier(0.22,1,0.36,1); }
@keyframes gsmSlideIn  { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes gsmSlideOut { to { opacity: 0; transform: translateY(16px); } }

#gsmModal *, #gsmModal *::before, #gsmModal *::after { box-sizing: border-box; }
#gsmModal button, #gsmModal input { font-family: 'Poppins', sans-serif; }
#gsmModal button { cursor: pointer; -webkit-tap-highlight-color: transparent; }
#gsmModal button, #gsmModal a, #gsmModal input { outline: none; }
/* icons keep the icon font */
#gsmModal [class^="ri-"], #gsmModal [class*=" ri-"],
#gsmModal [class^="ri-"]::before, #gsmModal [class*=" ri-"]::before {
    font-family: 'remixicon' !important; font-style: normal; font-weight: normal; line-height: 1;
}

/* ── the lane + two grey lines ── */
.gsm-lane {
    width: 100%; max-width: 580px; height: 100%;
    display: flex; flex-direction: column; min-height: 0;
}
@media (min-width: 801px) {
    .gsm-lane { border-left: 1px solid var(--gsm-line); border-right: 1px solid var(--gsm-line); }
}
@media (max-width: 800px) { .gsm-lane { max-width: 100%; } }

/* ── Top bar ── */
.gsm-topbar {
    flex-shrink: 0;
    display: flex; align-items: center; gap: calc(8px * var(--md-s));
    min-height: var(--gsm-tb-h);
    padding: calc(env(safe-area-inset-top) + calc(14px * var(--md-s))) var(--gsm-pad) calc(6px * var(--md-s)) 0;
    background: transparent;
}
.gsm-back-btn {
    width: calc(42px * var(--md-s)); height: calc(42px * var(--md-s)); flex-shrink: 0;
    background: none; border: none; color: var(--text,#F0F2FF);
    display: flex; align-items: center; justify-content: center;
    font-size: calc(28px * var(--md-s)); padding: 0; margin-left: 2px; opacity: .9; transition: 0.2s;
}
.gsm-back-btn:hover { color: var(--gsm-blue-l); opacity: 1; }
.gsm-back-btn:active { opacity: .6; }
@media (max-width:490px){ .gsm-back-btn{ width:calc(38px * var(--md-s)); height:calc(38px * var(--md-s)); font-size:calc(28px * var(--md-s)); margin-left:0; } }

#gsmSearchWrap {
    flex: 1; min-width: 0; display: flex; align-items: center;
    height: var(--gsm-sb-h);
    background: var(--gsm-card);
    border: 1px solid var(--gsm-line2);
    border-radius: var(--gsm-r); overflow: hidden;
    transition: border-color .2s, box-shadow .2s;
}
#gsmSearchWrap.focused { border-color: rgba(42,95,216,.85); box-shadow: 0 0 0 3px rgba(42,95,216,.18); }

.gsm-search-icon { padding: 0 calc(6px * var(--md-s)) 0 calc(12px * var(--md-s)); color: var(--gsm-mut); font-size: calc(16px * var(--md-s)); flex-shrink: 0; display: flex; align-items: center; }
#gsmSearchInput {
    flex: 1; min-width: 0; height: 100%; padding: 0 calc(6px * var(--md-s)); background: transparent; border: none; outline: none;
    color: var(--text,#F0F2FF); font-size: calc(.8rem * var(--md-s));
}
#gsmSearchInput::placeholder { color: var(--gsm-mut); }
@media (max-width:490px){ #gsmSearchInput{ font-size:calc(.74rem * var(--md-s)); } .gsm-search-icon{ padding:0 calc(5px * var(--md-s)) 0 calc(10px * var(--md-s)); font-size:calc(15px * var(--md-s)); } }
#gsmClearBtn {
    display: none; background: transparent; border: none; color: var(--gsm-mut);
    padding: 0 calc(12px * var(--md-s)); font-size: calc(17px * var(--md-s)); flex-shrink: 0; line-height: 1; height: 100%;
    transition: color .2s;
}
#gsmClearBtn:hover { color: var(--text,#F0F2FF); }

#gsmSearchBtn {
    display: none; align-items: center; justify-content: center; flex-shrink: 0;
    height: var(--gsm-sb-h); padding: 0 calc(16px * var(--md-s));
    border: none; border-radius: var(--gsm-r);
    background: var(--gsm-blue); color: var(--text,#F0F2FF);
    font-size: calc(.79rem * var(--md-s)); font-weight: 700; line-height: 1; white-space: nowrap;
    transition: background .2s ease, transform .15s ease, box-shadow .2s ease;
}
#gsmSearchBtn:hover { background: var(--gsm-blue-h); box-shadow: 0 4px 16px var(--gsm-blue-glow); }
#gsmSearchBtn:active { transform: scale(.98); }
@media (max-width:490px){ #gsmSearchBtn{ font-size:calc(.68rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); } }

/* ══ SCOPE LABEL — "Searching in [Group Name]" ══ */
.gsm-context-bar {
    display: flex; align-items: center; gap: calc(7px * var(--md-s));
    margin: calc(8px * var(--md-s)) var(--gsm-pad) 0;
    padding: 0 calc(11px * var(--md-s));
    min-height: var(--gsm-act-h);
    font-size: calc(.7rem * var(--md-s)); font-weight: 500;
    color: var(--gsm-sub);
    background: var(--gsm-blue-dim);
    border: 1px solid rgba(42,95,216,.35);
    border-radius: var(--gsm-r);
    flex-shrink: 0;
}
.gsm-context-bar i { font-size: calc(14px * var(--md-s)); color: var(--gsm-blue-l); flex-shrink: 0; }
.gsm-context-bar span { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gsm-context-bar strong { color: var(--text,#F0F2FF); font-weight: 700; }
@media (max-width:490px){ .gsm-context-bar{ font-size:calc(.64rem * var(--md-s)); gap:calc(6px * var(--md-s)); } .gsm-context-bar i{ font-size:calc(13px * var(--md-s)); } }

/* ── Scroll area ── */
#gsmContent {
    flex: 1; min-height: 0; overflow-y: auto; overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    padding: calc(6px * var(--md-s)) 0 calc(calc(14px * var(--md-s)) + env(safe-area-inset-bottom));
    scrollbar-width: thin; scrollbar-color: rgba(42,95,216,.4) transparent;
}
#gsmContent::-webkit-scrollbar { width: calc(4px * var(--md-s)); }
#gsmContent::-webkit-scrollbar-thumb { background: rgba(42,95,216,.45); border-radius: calc(99px * var(--md-s)); }

.gsm-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: calc(64px * var(--md-s)) calc(20px * var(--md-s)); gap: calc(8px * var(--md-s)); color: var(--gsm-mut); text-align: center;
}
.gsm-empty i { font-size: calc(36px * var(--md-s)); color: rgba(126,166,255,.45); }
.gsm-empty p { font-size: calc(.8rem * var(--md-s)); font-weight: 500; color: var(--text,#F0F2FF); }
.gsm-empty small { font-size: calc(.66rem * var(--md-s)); }

.gsm-sec {
    display: flex; align-items: center;
    font-size: calc(.64rem * var(--md-s)); font-weight: 600; letter-spacing: .6px;
    color: var(--gsm-sub); text-transform: uppercase;
    padding: calc(14px * var(--md-s)) var(--gsm-pad) calc(4px * var(--md-s));
}

.gsm-row {
    display: flex; align-items: center; gap: calc(10px * var(--md-s));
    padding: calc(9px * var(--md-s)) var(--gsm-pad); cursor: pointer;
    transition: background .15s; -webkit-tap-highlight-color: transparent;
}
.gsm-row:hover, .gsm-row:active { background: var(--gsm-card2); }

.gsm-avatar {
    width: calc(clamp(36px,9.5vw,44px) * var(--md-s)); height: calc(clamp(36px,9.5vw,44px) * var(--md-s));
    border-radius: 50%; object-fit: cover; border: 2px solid var(--gsm-blue); flex-shrink: 0;
}
.gsm-avatar.meme { border-radius: calc(8px * var(--md-s)); border-color: var(--gsm-line2); }
.gsm-video-wrap {
    width: calc(clamp(36px,9.5vw,44px) * var(--md-s)); height: calc(clamp(36px,9.5vw,44px) * var(--md-s));
    border-radius: calc(8px * var(--md-s)); border: 2px solid var(--gsm-line2);
    background: #000; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; position: relative; overflow: hidden;
}
.gsm-video-wrap video { width: 100%; height: 100%; object-fit: cover; border-radius: calc(6px * var(--md-s)); }
.gsm-video-wrap .gsm-play-overlay { position: absolute; color: rgba(255,255,255,.8); font-size: calc(16px * var(--md-s)); pointer-events: none; }

.gsm-query-icon {
    width: calc(clamp(36px,9.5vw,44px) * var(--md-s)); height: calc(clamp(36px,9.5vw,44px) * var(--md-s));
    border-radius: 50%; background: var(--gsm-card2);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.gsm-query-icon i { font-size: calc(16px * var(--md-s)); color: var(--gsm-mut); }

.gsm-info { flex: 1; min-width: 0; }
.gsm-name {
    font-size: calc(.78rem * var(--md-s)); font-weight: 600; color: var(--text,#F0F2FF);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.gsm-name.cap { color: rgba(var(--fg,255,255,255),.88); font-weight: 500; }
.gsm-sub { font-size: calc(.64rem * var(--md-s)); color: var(--gsm-mut); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
@media (max-width:490px){ .gsm-name{ font-size:calc(.72rem * var(--md-s)); } .gsm-sub{ font-size:calc(.6rem * var(--md-s)); } }

.gsm-hl { color: var(--gsm-blue-l); font-weight: 700; }

.gsm-rm-btn {
    width: calc(24px * var(--md-s)); height: calc(24px * var(--md-s)); flex-shrink: 0;
    background: rgba(var(--fg,255,255,255),.08); border: none; border-radius: var(--gsm-r);
    color: var(--gsm-sub); font-size: calc(14px * var(--md-s)); padding: 0; line-height: 1;
    display: flex; align-items: center; justify-content: center; transition: .2s;
}
.gsm-rm-btn:hover { background: rgba(var(--fg,255,255,255),.18); color: var(--text,#F0F2FF); }

.gsm-full-search-row { border-top: 1px solid var(--gsm-line); margin-top: calc(6px * var(--md-s)); padding-top: calc(12px * var(--md-s)); }
.gsm-full-search-icon {
    width: calc(clamp(36px,9.5vw,44px) * var(--md-s)); height: calc(clamp(36px,9.5vw,44px) * var(--md-s));
    border-radius: 50%; background: var(--gsm-blue-dim); border: 2px solid rgba(42,95,216,.55);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.gsm-full-search-icon i { color: var(--gsm-blue-l); font-size: calc(16px * var(--md-s)); }
.gsm-full-search-label { color: var(--gsm-blue-l); }

.gsm-loader { padding: calc(28px * var(--md-s)); text-align: center; color: var(--gsm-mut); }
.gsm-loader i { font-size: calc(22px * var(--md-s)); color: var(--gsm-blue-l); }
.gsm-spin { animation: gsmSpin 0.7s linear infinite; display: inline-block; }
@keyframes gsmSpin { to { transform: rotate(360deg); } }

/* ── RECENTS ── */
#gsmRecentsWrap { padding: calc(10px * var(--md-s)) 0 0; }
.gsm-recents-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: calc(4px * var(--md-s)) var(--gsm-pad) calc(10px * var(--md-s));
    border-bottom: 1px solid var(--gsm-line2);
}
#gsmRecentsList .gsm-row { border-bottom: 1px solid var(--gsm-line); padding-top: calc(11px * var(--md-s)); padding-bottom: calc(11px * var(--md-s)); }
.gsm-recents-title {
    font-size: calc(.64rem * var(--md-s)); font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--gsm-sub);
}
#gsmClearAllBtn {
    background: none; border: none; color: var(--gsm-blue-l);
    font-size: calc(.68rem * var(--md-s)); font-weight: 600; transition: color .2s;
}
#gsmClearAllBtn:hover { color: var(--text,#F0F2FF); }
.gsm-no-recents { color: var(--gsm-mut); font-size: calc(.72rem * var(--md-s)); font-style: italic; padding: calc(4px * var(--md-s)) var(--gsm-pad) calc(22px * var(--md-s)); }

/* ── TRIGGER CLEAR BUTTON (used outside the modal, kept for compatibility) ── */
.gsm-trigger-clear {
    margin: calc(5px * var(--md-s)); width: calc(32px * var(--md-s)); height: calc(32px * var(--md-s));
    border-radius: var(--gsm-r, calc(5px * var(--md-s))); color: #fff;
    background: #2a5fd8; display: flex; align-items: center;
    justify-content: center; flex-shrink: 0; text-decoration: none;
    font-size: calc(15px * var(--md-s)); transition: all .2s ease;
    -webkit-tap-highlight-color: transparent; cursor: pointer;
}
.gsm-trigger-clear:hover { background: #234fb5; box-shadow: 0 4px 16px rgba(42,95,216,.34); }
.gsm-trigger-clear:active { transform: scale(.96); }

@media (prefers-reduced-motion:reduce){ #gsmModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>

<script>/* lazy start: this block's code loads the first time it is used */
window.dzLazyJs=window.dzLazyJs||function(id,names,eager){var done=false;function run(){if(done)return;done=true;var t=document.getElementById(id);if(!t)return;var f=t.content.cloneNode(true),ss=[].slice.call(f.querySelectorAll('script'));ss.forEach(function(s){s.parentNode.removeChild(s)});ss.forEach(function(o){var s=document.createElement('script');s.text=o.textContent;document.body.appendChild(s)})}names.forEach(function(n){var s=function(){run();var r=window[n];if(r&&r!==s)return r.apply(this,arguments)};s.__dzStub=1;window[n]=s});window['dzLazyRun_'+id]=run;if(eager){var go=function(){run();['pointerdown','keydown','touchstart'].forEach(function(e){document.removeEventListener(e,go,true)})};['pointerdown','keydown','touchstart'].forEach(function(e){document.addEventListener(e,go,{capture:true,passive:true})});var idle=function(){(window.requestIdleCallback||function(c){setTimeout(c,1200)})(run,{timeout:3000})};if(document.readyState==='complete')idle();else window.addEventListener('load',idle)}};
dzLazyJs('dzLzGsm',['closeGroupSearchModal','gsmClearAllRecents','gsmClearSearch','gsmFullSearch','openGroupSearchModal'],false);
</script>
<template id="dzLzGsm">
<script>
var GSM_GROUP_ID = <?php echo (int)$group_id; ?>;
var gsmTimer = null;
var gsmLastQ = '';
var gsmOpen  = false;
var _gsmRecentCache = {};

function gsmEsc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function gsmHL(str,q){
    if(!q) return gsmEsc(str);
    return gsmEsc(str).replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<span class="gsm-hl">$1</span>');
}

/* ── server-side recents (Pod-scoped only — never touches global search) ── */
function gsmGetRecents(cb){
    fetch('group.php?id='+GSM_GROUP_ID+'&gsearch_recents=get')
        .then(function(r){ return r.json(); })
        .then(function(d){ cb(Array.isArray(d) ? d : []); })
        .catch(function(){ cb([]); });
}
function gsmAddRecent(item){
    fetch('group.php?id='+GSM_GROUP_ID+'&gsearch_recents=add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify(item)
    }).catch(function(){});
}
function gsmRemoveRecent(type, id){
    fetch('group.php?id='+GSM_GROUP_ID+'&gsearch_recents=remove', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({ type: type, id: id })
    })
    .then(function(){ gsmRenderRecents(); })
    .catch(function(){ gsmRenderRecents(); });
}
function gsmClearAllRecents(){
    fetch('group.php?id='+GSM_GROUP_ID+'&gsearch_recents=clear', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: '{}'
    })
    .then(function(){ gsmRenderRecents(); })
    .catch(function(){ gsmRenderRecents(); });
}

function gsmRenderRecents(){
    var list = document.getElementById('gsmRecentsList');
    var clearBtn = document.getElementById('gsmClearAllBtn');
    if(!list) return;
    list.innerHTML = '<div class="gsm-loader"><i class="ri-loader-4-line gsm-spin"></i></div>';
    clearBtn.style.display = 'none';

    gsmGetRecents(function(arr){
        _gsmRecentCache = {};
        if(!arr.length){
            list.innerHTML = '<div class="gsm-no-recents">No recent searches yet</div>';
            return;
        }
        clearBtn.style.display = 'inline-block';

        list.innerHTML = arr.map(function(r, idx){
            _gsmRecentCache[idx] = r;
            var name, sub, avatarHtml;

            if(r.type === 'member'){
                var pic = r.profile_pic || 'mee.jpg';
                name = r.username || '';
                sub  = r.nickname ? '@'+r.nickname : 'Member';
                avatarHtml = '<img class="gsm-avatar" src="'+gsmEsc(pic)+'" onerror="this.src=\'mee.jpg\'">';
            } else if(r.type === 'gpost'){
                name = (r.caption || 'Post').length > 40 ? (r.caption||'Post').substring(0,40)+'…' : (r.caption||'Post');
                sub  = 'Post · by ' + (r.username || 'unknown');
                if(r.meme_type === 'video'){
                    avatarHtml = '<div class="gsm-video-wrap"><video src="uploads/'+gsmEsc(r.file_name)+'" muted playsinline preload="metadata" onerror="this.style.display=\'none\'"></video><i class="ri-play-fill gsm-play-overlay"></i></div>';
                } else {
                    var mpic = r.file_name ? 'uploads/'+r.file_name : 'uploads/Text.jpg';
                    avatarHtml = '<img class="gsm-avatar meme" src="'+gsmEsc(mpic)+'" onerror="this.src=\'uploads/Text.jpg\'">';
                }
            } else {
                name = r.query || '';
                sub  = 'Search';
                avatarHtml = '<div class="gsm-query-icon"><i class="ri-search-line"></i></div>';
            }

            return '<div class="gsm-row" data-idx="'+idx+'" onclick="gsmGoToIdx('+idx+')">'
                + avatarHtml
                + '<div class="gsm-info">'
                    + '<div class="gsm-name'+(r.type==='gpost'?' cap':'')+'">'+gsmEsc(name)+'</div>'
                    + '<div class="gsm-sub">'+gsmEsc(sub)+'</div>'
                + '</div>'
                + '<button type="button" class="gsm-rm-btn" aria-label="Remove" onclick="event.stopPropagation();gsmRemoveRecent(\''+r.type+'\',\''+String(r.id)+'\')">'
                    + '<i class="ri-close-line"></i>'
                + '</button>'
            + '</div>';
        }).join('');
    });
}

function gsmGoToIdx(idx){
    var r = _gsmRecentCache[idx];
    if(!r) return;
    gsmAddRecent(r);
    gsmNavigate(r.type, r);
}

function openGroupSearchModal(){
    if(gsmOpen) return;
    gsmOpen = true;
    var m = document.getElementById('gsmModal');
    m.style.animation = '';
    m.classList.add('gsm-open');
    document.body.style.overflow = 'hidden';
    gsmRenderRecents();
    setTimeout(function(){
        var inp = document.getElementById('gsmSearchInput');
        if(inp) inp.focus();
    }, 150);
}

function closeGroupSearchModal(){
    if(!gsmOpen) return;
    gsmOpen = false;
    var m = document.getElementById('gsmModal');
    m.style.animation = 'gsmSlideOut 0.22s ease forwards';
    setTimeout(function(){
        if(gsmOpen) return; /* re-opened during the close animation */
        m.classList.remove('gsm-open');
        m.style.animation = '';
        document.body.style.overflow = '';
    }, 210);
    document.getElementById('gsmSearchInput').value = '';
    document.getElementById('gsmClearBtn').style.display = 'none';
    document.getElementById('gsmSearchBtn').style.display = 'none';
    document.getElementById('gsmLiveResults').style.display = 'none';
    document.getElementById('gsmEmptyState').style.display = 'none';
    document.getElementById('gsmRecentsWrap').style.display = 'block';
    gsmLastQ = '';
    clearTimeout(gsmTimer);
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && gsmOpen) closeGroupSearchModal();
});

function gsmClearSearch(){
    var inp = document.getElementById('gsmSearchInput');
    inp.value = '';
    document.getElementById('gsmClearBtn').style.display = 'none';
    document.getElementById('gsmSearchBtn').style.display = 'none';
    document.getElementById('gsmLiveResults').style.display = 'none';
    document.getElementById('gsmEmptyState').style.display = 'none';
    document.getElementById('gsmRecentsWrap').style.display = 'block';
    gsmLastQ = '';
    clearTimeout(gsmTimer);
    inp.focus();
}

/* ── STAYS ON THIS PAGE — always redirects to group.php with this exact group_id ── */
function gsmFullSearch(){
    var q = document.getElementById('gsmSearchInput').value.trim();
    if(q){
        gsmAddRecent({ type:'query', id: btoa(encodeURIComponent(q)).slice(0,12), query: q });
        location.href = 'group.php?id='+GSM_GROUP_ID+'&search='+encodeURIComponent(q);
    }
}

/* ── STAYS ON THIS PAGE — every branch targets group.php?id=GSM_GROUP_ID
     (member rows still open the member's profile, which is normal navigation,
     not a "search" — it's the same as clicking their name anywhere else) ── */
function gsmNavigate(type, item){
    if(type === 'member'){
        location.href = 'profile.php?user_id='+item.id;
    } else if(type === 'gpost'){
        location.href = 'group.php?id='+GSM_GROUP_ID+'&search='+encodeURIComponent(item.caption||'');
    } else {
        location.href = 'group.php?id='+GSM_GROUP_ID+'&search='+encodeURIComponent(item.query||'');
    }
}

function gsmRenderResults(data, q){
    var box = document.getElementById('gsmLiveResults');
    if(!data || !data.length){
        box.innerHTML = '<div class="gsm-empty">'
            + '<i class="ri-search-2-line"></i>'
            + '<p>No results for "'+gsmEsc(q)+'"</p>'
            + '<small>Try a different word or member name</small>'
            + '</div>';
        return;
    }

    var members = data.filter(function(d){ return d.type === 'member'; });
    var posts   = data.filter(function(d){ return d.type === 'gpost'; });
    var html = '';

    if(members.length){
        html += '<div class="gsm-sec">Members</div>';
        html += members.map(function(u){
            var pic = u.profile_pic || 'mee.jpg';
            var enc = encodeURIComponent(JSON.stringify(u));
            return '<div class="gsm-row" onclick="gsmSelectResult(\''+enc+'\')">'
                + '<img class="gsm-avatar" src="'+gsmEsc(pic)+'" onerror="this.src=\'mee.jpg\'">'
                + '<div class="gsm-info">'
                    + '<div class="gsm-name">'+gsmHL(u.username,q)+'</div>'
                    + '<div class="gsm-sub">'+(u.nickname ? gsmHL('@'+u.nickname,q) : 'Member')+'</div>'
                + '</div>'
                + '<i class="ri-arrow-right-s-line" style="color:rgba(var(--fg,255,255,255),0.35);font-size:18px;flex-shrink:0;"></i>'
            + '</div>';
        }).join('');
    }

    if(posts.length){
        html += '<div class="gsm-sec">Posts</div>';
        html += posts.map(function(p){
            var capShort = (p.caption||'').length > 55 ? p.caption.substring(0,55)+'…' : (p.caption||'');
            var thumbHtml;
            if(p.meme_type === 'video'){
                thumbHtml = '<div class="gsm-video-wrap"><video src="uploads/'+gsmEsc(p.file_name)+'" muted playsinline preload="metadata" onerror="this.style.display=\'none\'"></video><i class="ri-play-fill gsm-play-overlay"></i></div>';
            } else {
                var thumb = p.file_name ? 'uploads/'+p.file_name : 'uploads/Text.jpg';
                thumbHtml = '<img class="gsm-avatar meme" src="'+gsmEsc(thumb)+'" onerror="this.src=\'uploads/Text.jpg\'">';
            }
            var enc = encodeURIComponent(JSON.stringify(p));
            return '<div class="gsm-row" onclick="gsmSelectResult(\''+enc+'\')">'
                + thumbHtml
                + '<div class="gsm-info">'
                    + '<div class="gsm-name cap">'+gsmHL(capShort,q)+'</div>'
                    + '<div class="gsm-sub">by '+gsmEsc(p.username)+'</div>'
                + '</div>'
                + '<i class="ri-image-line" style="color:rgba(var(--fg,255,255,255),0.35);font-size:15px;flex-shrink:0;"></i>'
            + '</div>';
        }).join('');
    }

    html += '<div class="gsm-row gsm-full-search-row" onclick="gsmFullSearch()">'
        + '<div class="gsm-full-search-icon"><i class="ri-search-line"></i></div>'
        + '<div class="gsm-info">'
            + '<div class="gsm-name gsm-full-search-label">Search for "'+gsmEsc(q)+'"</div>'
            + '<div class="gsm-sub">See all matching posts &amp; members in this Pod</div>'
        + '</div>'
        + '<i class="ri-arrow-right-line" style="color:#7ea6ff;font-size:15px;flex-shrink:0;"></i>'
    + '</div>';

    box.innerHTML = html;
}

function gsmSelectResult(enc){
    var item = {};
    try { item = JSON.parse(decodeURIComponent(enc)); } catch(e){}
    gsmAddRecent(item);
    gsmNavigate(item.type, item);
}

document.getElementById('gsmSearchInput').addEventListener('input', function(){
    var q = this.value.trim();
    var searchBtn = document.getElementById('gsmSearchBtn');

    document.getElementById('gsmClearBtn').style.display = q ? 'block' : 'none';
    searchBtn.style.display = q ? 'flex' : 'none';

    if(!q){
        clearTimeout(gsmTimer);
        document.getElementById('gsmLiveResults').style.display = 'none';
        document.getElementById('gsmEmptyState').style.display = 'none';
        document.getElementById('gsmRecentsWrap').style.display = 'block';
        gsmLastQ = '';
        return;
    }

    document.getElementById('gsmRecentsWrap').style.display = 'none';
    document.getElementById('gsmEmptyState').style.display = 'none';
    document.getElementById('gsmLiveResults').style.display = 'block';
    document.getElementById('gsmLiveResults').innerHTML = '<div class="gsm-loader"><i class="ri-loader-4-line gsm-spin"></i></div>';

    clearTimeout(gsmTimer);
    gsmLastQ = q;
    gsmTimer = setTimeout(function(){
        if(q !== gsmLastQ) return;
        /* Autocomplete endpoint is hard-scoped server-side to GSM_GROUP_ID — see group.php gsearch_type=autocomplete */
        fetch('group.php?id='+GSM_GROUP_ID+'&gsearch_type=autocomplete&q='+encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(d){ gsmRenderResults(d, q); })
            .catch(function(){ gsmRenderResults([], q); });
    }, 260);
});

document.getElementById('gsmSearchInput').addEventListener('keydown', function(e){
    if(e.key === 'Enter') gsmFullSearch();
});

document.getElementById('gsmSearchInput').addEventListener('focus', function(){
    document.getElementById('gsmSearchWrap').classList.add('focused');
});
document.getElementById('gsmSearchInput').addEventListener('blur', function(){
    document.getElementById('gsmSearchWrap').classList.remove('focused');
});
</script>
</template>
<!-- ════════════════════════════════════════════════════
     YOU MODAL — centered lane, grey side lines, same
     background / buttons / sizing as the Post modal
════════════════════════════════════════════════════ -->
<?php if ($is_member): ?>
<div id="youModal" role="dialog" aria-modal="true" aria-label="You in this pod">
 <div class="ym-lane">

    <!-- TOPBAR: X left · title centered · spacer right -->
    <div class="ym-topbar">
        <div class="ym-tb-side">
            <button type="button" class="ym-x" onclick="closeYouModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="ym-topbar-center">
            <div class="ym-topbar-title">You in this Pod</div>
        </div>
    </div>

    <div class="ym-body" id="ymBody">

        <!-- HERO: rectangular tab buttons LEFT · profile pic RIGHT -->
        <div class="ym-hero">
            <div class="ym-tabs">
                <button type="button" class="ym-pill active" onclick="ymTab(this,'ym-overview')">
                    <i class="ri-dashboard-line"></i> Overview
                </button>
                <button type="button" class="ym-pill" onclick="ymTab(this,'ym-manage')">
                    <i class="ri-settings-3-line"></i> Manage posts
                </button>
            </div>
            <img src="<?php echo htmlspecialchars($cu_pic); ?>"
                 onerror="this.src='mee.jpg'"
                 class="ym-big-pic" alt="Your profile picture">
        </div>

        <!-- ═════════ OVERVIEW TAB ═════════ -->
        <div id="ym-overview" class="ym-tab active">

            <div class="ym-accordion">
                <button type="button" class="ym-acc-head" onclick="ymToggleAccordion(this.parentNode)" aria-expanded="false">
                    <i class="ri-question-line"></i>
                    <span>Learn about contributor badges</span>
                    <i class="ri-arrow-down-s-line ym-acc-chevron"></i>
                </button>
                <div class="ym-acc-body">
                    <p>This pod tracks contribution in four different ways. Each badge measures something different on purpose — so recognition doesn't always go to the same one or two people.</p>

                    <div class="ym-badge-explainer">
                        <div class="ym-be-head">
                            <i class="ri-trophy-line" style="color:#7ea6ff;"></i>
                            <strong>Top Contributor OAT</strong>
                            <span class="ym-be-where">Group header</span>
                        </div>
                        <p class="ym-be-desc"><strong>OAT</strong> means "of all time." This is the single highest-scoring member across the pod's entire history — it never resets, so once someone earns it by racking up enough posts and engagement, they hold it until someone else's all-time score overtakes theirs.</p>
                    </div>

                    <div class="ym-badge-explainer">
                        <div class="ym-be-head">
                            <i class="ri-trophy-line" style="color:#2a5fd8;"></i>
                            <strong>Top Contributor</strong>
                            <span class="ym-be-where">Members list</span>
                        </div>
                        <p class="ym-be-desc">The highest-scoring member over the <strong>last 30 days only</strong>. Unlike the OAT badge, this one rolls forward continuously — it always reflects who's been active recently, not who was active months or years ago. This is the badge that's actually winnable if you're new.</p>
                    </div>

                    <div class="ym-badge-explainer">
                        <div class="ym-be-head">
                            <i class="ri-arrow-up-double-line" style="color:#4d8fff;"></i>
                            <strong>Rising Contributor</strong>
                            <span class="ym-be-where">Members list</span>
                        </div>
                        <p class="ym-be-desc">Goes to whoever's activity <strong>jumped the most</strong> compared to the week before — this week's score has to be at least 50% higher than last week's. It only fires on a genuine spike in activity, not normal week-to-week ups and downs.</p>
                    </div>

                    <div class="ym-badge-explainer">
                        <div class="ym-be-head">
                            <i class="ri-calendar-check-line" style="color:#7ea6ff;"></i>
                            <strong>Most Consistent</strong>
                            <span class="ym-be-where">Members list</span>
                        </div>
                        <p class="ym-be-desc">Not about volume — this tracks how many <strong>different days</strong> you posted on in the last 30 days. Ten posts in one afternoon only counts as one active day; one post every few days for two weeks counts as several. It rewards showing up regularly over time, not one big burst.</p>
                    </div>

                    <hr class="ym-be-divider">

                    <p>All four badges are built from the same underlying score:</p>
                    <ul>
                        <li><i class="ri-image-add-line"></i> <span><strong>Posts</strong> — each post adds 3 points to your score.</span></li>
                        <li><i class="ri-heart-line"></i> <span><strong>Likes received</strong> — each like on your posts adds 1 point.</span></li>
                        <li><i class="ri-repeat-line"></i> <span><strong>Reposts received</strong> — each repost adds 2 points.</span></li>
                        <li><i class="ri-bookmark-line"></i> <span><strong>Saves received</strong> — each save adds 2 points.</span></li>
                    </ul>
                    <p>Tip: quality posts that get reposts and saves earn points faster than quantity alone. And since three of the four badges reset on rolling windows, being active <em>now</em> matters just as much as your history — only the OAT badge is locked to all-time totals.</p>
                </div>
            </div>

            <div class="ym-stats-grid">
                <div class="ym-stat-card">
                    <span class="ym-stat-num"><?php echo $cu_post_count; ?></span>
                    <span class="ym-stat-lbl">Posts</span>
                </div>
                <div class="ym-stat-card">
                    <span class="ym-stat-num"><?php echo number_format($cu_total_likes); ?></span>
                    <span class="ym-stat-lbl">Likes received</span>
                </div>
                <div class="ym-stat-card">
                    <span class="ym-stat-num"><?php echo number_format($cu_total_reposts); ?></span>
                    <span class="ym-stat-lbl">Reposts</span>
                </div>
                <div class="ym-stat-card">
                    <span class="ym-stat-num"><?php echo number_format($cu_total_saves); ?></span>
                    <span class="ym-stat-lbl">Saves</span>
                </div>
            </div>

            <?php if ($cu_join_date): ?>
            <div class="ym-join-row">
                <i class="ri-calendar-check-line"></i>
                <span>Joined this pod on <strong><?php echo date('d M Y', strtotime($cu_join_date)); ?></strong></span>
            </div>
            <?php endif; ?>

        </div><!-- /ym-overview -->

        <!-- ═════════ MANAGE POSTS TAB ═════════ -->
        <div id="ym-manage" class="ym-tab">

            <div class="ym-sort-row">
                <button type="button" class="ym-sort-pill active" onclick="ymSort('newest',this)">Newest</button>
                <button type="button" class="ym-sort-pill"        onclick="ymSort('popular',this)">Popular</button>
                <button type="button" class="ym-sort-pill"        onclick="ymSort('oldest',this)">Oldest</button>
            </div>

            <?php if (empty($cu_posts)): ?>
            <div class="ym-no-posts">
                <i class="ri-image-line"></i>
                <p>You haven't posted in this pod yet.</p>
            </div>
            <?php else: ?>
            <div id="ym-post-list">
                <?php foreach ($cu_posts as $cp):
                    $cp_thumb = '';
                    if ($cp['type'] === 'image' && !empty($cp['file_name']) && $cp['file_name'] !== '0') {
                        $cp_thumb = 'uploads/' . $cp['file_name'];
                    } elseif ($cp['type'] === 'video' && !empty($cp['file_name']) && $cp['file_name'] !== '0') {
                        $cp_thumb = 'uploads/' . $cp['file_name'];
                    }
                    $cp_cap   = htmlspecialchars(mb_strlen($cp['caption']) > 60 ? mb_substr($cp['caption'],0,60).'…' : $cp['caption']);
                    $cp_date  = date('d M Y', strtotime($cp['created_at']));
                    $cp_total = (int)$cp['lc'] + (int)$cp['rc'] + (int)$cp['sc'];
                ?>
                <div class="ym-post-row"
                     data-newest="<?php echo strtotime($cp['created_at']); ?>"
                     data-popular="<?php echo $cp_total; ?>"
                     data-gp="<?php echo (int)$cp['gp_id']; ?>"
                     data-mid="<?php echo (int)$cp['meme_id']; ?>">

                    <div class="ym-post-thumb">
                        <?php if ($cp['type'] === 'video' && $cp_thumb): ?>
                            <video src="<?php echo htmlspecialchars($cp_thumb); ?>" muted playsinline preload="metadata"></video>
                            <i class="ri-play-fill ym-play-overlay"></i>
                        <?php elseif ($cp_thumb): ?>
                            <img src="<?php echo htmlspecialchars($cp_thumb); ?>" onerror="this.src='uploads/Text.jpg'" alt="">
                        <?php else: ?>
                            <div class="ym-text-thumb"><i class="ri-file-text-line"></i></div>
                        <?php endif; ?>
                    </div>

                    <div class="ym-post-info">
                        <div class="ym-post-cap"><?php echo $cp_cap; ?></div>
                        <div class="ym-post-meta">
                            <span><i class="ri-heart-line"></i> <?php echo (int)$cp['lc']; ?></span>
                            <span><i class="ri-repeat-line"></i> <?php echo (int)$cp['rc']; ?></span>
                            <span><i class="ri-bookmark-line"></i> <?php echo (int)$cp['sc']; ?></span>
                            <span class="ym-post-date"><?php echo $cp_date; ?></span>
                        </div>
                    </div>

                    <form method="POST" onclick="event.stopPropagation()"
                          onsubmit="return confirm('Permanently delete this post from the group?')">
                        <input type="hidden" name="delete_post_id"  value="<?php echo (int)$cp['gp_id']; ?>">
                        <input type="hidden" name="delete_meme_id"  value="<?php echo (int)$cp['meme_id']; ?>">
                        <button type="submit" name="delete_post" class="ym-del-btn" title="Delete post" aria-label="Delete post">
                            <i class="ri-delete-bin-line"></i>
                        </button>
                    </form>

                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div><!-- /ym-manage -->

        <!-- ═════════ ABOUT THIS PAGE (shown under both tabs) ═════════ -->
        <div class="ym-about">
            <div class="ym-about-h"><i class="ri-information-line"></i> What is this page?</div>

            <p>This is your personal space inside this pod. Only you can see it. It brings together everything about <strong>your own activity</strong> here — how much you've posted, how people have reacted, and the tools to clean up your posts if you change your mind.</p>

            <div class="ym-about-sub">Overview tab</div>
            <p>A quick summary of how you're doing in this pod. The four boxes show your total <strong>posts</strong> and the <strong>likes, reposts and saves</strong> other members have given them. These are the same numbers that build your score for the contributor badges, so it's a good way to see where you stand. Below them you'll see the date you joined the pod. Tap "Learn about contributor badges" to see how each badge is won.</p>

            <div class="ym-about-sub">Manage posts tab</div>
            <p>A list of every post you've made in this pod, each with its cover, a short caption, its likes, reposts and saves, and the date it was posted. Posts with video show a play icon, and text-only posts show a note icon. Use the sort buttons to change the order:</p>
            <ul>
                <li><i class="ri-time-line"></i><span><strong>Newest</strong> — your most recent posts first.</span></li>
                <li><i class="ri-fire-line"></i><span><strong>Popular</strong> — posts with the most combined likes, reposts and saves first.</span></li>
                <li><i class="ri-history-line"></i><span><strong>Oldest</strong> — your earliest posts first.</span></li>
            </ul>

            <div class="ym-about-sub">Deleting a post</div>
            <p>The bin button next to a post removes it from this pod. You'll be asked to confirm first, because <strong>deleting is permanent and can't be undone</strong>. Its likes, reposts and saves will stop counting toward your score.</p>

            <div class="ym-about-note"><i class="ri-lightbulb-line"></i><span>Tip: the numbers here only cover this pod. Your activity in other pods is counted separately.</span></div>
        </div>

    </div><!-- /ym-body -->
 </div><!-- /ym-lane -->
</div><!-- /youModal -->
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     YOU MODAL — STYLES (same design language as the Post modal)
     • full-screen overlay, content in a CENTERED LANE
     • two grey lines separate the lane from the empty space
     • rectangular 5px buttons, fluid premium sizing
     • dark mid-tone BLUE accent (no red, no glow)
═══════════════════════════════════════════════════════════ -->
<style>
#youModal {
    --ym-blue:#2a5fd8; --ym-blue-h:#234fb5; --ym-blue-l:#2a5fd8;
    --ym-blue-dim:rgba(42,95,216,.18);
    --ym-btn:#1b3a85; --ym-btn-h:#234799; --ym-btn-on:#2a5fd8; --ym-btn-on-h:#234fb5; --ym-ink:var(--text,#fff);
    --ym-card:rgba(var(--fg,255,255,255),.05); --ym-card2:rgba(var(--fg,255,255,255),.08);
    --ym-line:rgba(var(--fg,255,255,255),.09); --ym-line2:rgba(var(--fg,255,255,255),.14);
    --ym-sub:rgba(var(--fg,255,255,255),.62); --ym-mut:rgba(var(--fg,255,255,255),.45);
    --ym-pad:calc(clamp(9px,3vw,16px) * var(--md-s));
    --ym-act-h:calc(clamp(25px,7.2vw,30px) * var(--md-s));
    --ym-r:5px;
    --ym-fs:calc(.8rem * var(--md-s));
    --ym-tb-h:calc(54px * var(--md-s));

    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999999;
    background: linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--ym-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
}
@media (max-width:490px){ #youModal{ --ym-fs:calc(.74rem * var(--md-s)); --ym-tb-h:calc(48px * var(--md-s)); } }
@media (max-width:390px){ #youModal{ --ym-fs:calc(.7rem * var(--md-s));  --ym-tb-h:calc(44px * var(--md-s)); } }
#youModal.ym-open { display: flex; animation: ymSlideIn 0.28s cubic-bezier(0.22,1,0.36,1); }
@keyframes ymSlideIn  { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes ymSlideOut { to { opacity: 0; transform: translateY(16px); } }

#youModal *, #youModal *::before, #youModal *::after { box-sizing: border-box; }
#youModal button { font-family: 'Poppins', sans-serif; cursor: pointer; -webkit-tap-highlight-color: transparent; outline: none; }
#youModal [class^="ri-"], #youModal [class*=" ri-"],
#youModal [class^="ri-"]::before, #youModal [class*=" ri-"]::before {
    font-family: 'remixicon' !important; font-style: normal; font-weight: normal; line-height: 1;
}

/* ── the lane + two grey lines ── */
.ym-lane {
    width: 100%; max-width: 580px; height: 100%;
    display: flex; flex-direction: column; min-height: 0;
}
@media (min-width: 801px) {
    .ym-lane { border-left: 1px solid var(--ym-line); border-right: 1px solid var(--ym-line); }
}
@media (max-width: 800px) { .ym-lane { max-width: 100%; } }

/* ── Top bar ── */
.ym-topbar {
    flex-shrink: 0; display: flex; align-items: center;
    min-height: var(--ym-tb-h);
    padding: env(safe-area-inset-top) calc(10px * var(--md-s)) 0;
    background: transparent;
}
.ym-tb-side { flex: 0 0 38px; display: flex; align-items: center; }
.ym-tb-side.right { justify-content: flex-end; }
.ym-x {
    width: calc(34px * var(--md-s)); height: calc(34px * var(--md-s)); background: none; border: none; color: var(--text,#F0F2FF);
    display: flex; align-items: center; justify-content: center; opacity: .9; padding: 0; transition: 0.2s;
}
.ym-x:hover { color: var(--ym-blue-l); opacity: 1; }
.ym-x svg { width: calc(22px * var(--md-s)); height: calc(22px * var(--md-s)); display: block; }
.ym-topbar-center { flex: 1; min-width: 0; text-align: right; padding-right: calc(6px * var(--md-s)); }
.ym-topbar-title { font-size: calc(.92rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ym-topbar-sub { font-size: calc(.62rem * var(--md-s)); color: var(--ym-mut); font-weight: 400; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
@media (max-width:490px){ .ym-topbar-title{ font-size:calc(.84rem * var(--md-s)); } .ym-x{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); } }
@media (max-width:390px){ .ym-topbar-title{ font-size:calc(.8rem * var(--md-s)); } }

/* ── Scroll area ── */
.ym-body {
    flex: 1; min-height: 0;
    overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch;
    padding: calc(4px * var(--md-s)) var(--ym-pad) calc(28px * var(--md-s));
    scrollbar-width: thin; scrollbar-color: rgba(42,95,216,.4) transparent;
}
.ym-body::-webkit-scrollbar { width: calc(4px * var(--md-s)); }
.ym-body::-webkit-scrollbar-thumb { background: rgba(42,95,216,.45); border-radius: calc(99px * var(--md-s)); }

/* ── HERO: tab buttons left · profile pic right ── */
.ym-hero {
    display: flex; align-items: center; justify-content: space-between;
    gap: calc(14px * var(--md-s)); margin: calc(8px * var(--md-s)) 0 calc(18px * var(--md-s));
}
.ym-tabs {
    display: flex; flex-direction: column; gap: calc(8px * var(--md-s));
    flex: 0 1 190px; min-width: 0;
}
.ym-pill {
    display: flex; align-items: center; justify-content: center; gap: calc(6px * var(--md-s));
    width: 100%; height: var(--ym-act-h); padding: 0 calc(14px * var(--md-s));
    border: none; border-radius: var(--ym-r);
    background: var(--ym-btn); color: var(--ym-ink);
    font-size: calc(.79rem * var(--md-s)); font-weight: 700; line-height: 1; white-space: nowrap;
    transition: background .2s ease, transform .15s ease;
}
.ym-pill i { font-size: calc(14px * var(--md-s)); color: var(--ym-ink); }
.ym-pill:hover { background: var(--ym-btn-h); }
.ym-pill:active { transform: scale(.98); }
.ym-pill.active { background: var(--ym-btn-on); color: var(--ym-ink); }
.ym-pill.active:hover { background: var(--ym-btn-on-h); }
@media (max-width:490px){ .ym-pill{ font-size:calc(.68rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); gap:calc(4px * var(--md-s)); } .ym-pill i{ font-size:calc(12px * var(--md-s)); } .ym-tabs{ flex-basis:160px; } }

.ym-big-pic {
    width: calc(clamp(78px,22vw,104px) * var(--md-s)); height: calc(clamp(78px,22vw,104px) * var(--md-s));
    border-radius: 50%; object-fit: cover; display: block; flex-shrink: 0;
    border: 3px solid var(--ym-blue);
}

/* ── Tabs ── */
.ym-tab { display: none; }
.ym-tab.active { display: block; }

/* ── Accordion ── */
.ym-accordion {
    background: var(--ym-card); border: 1px solid var(--ym-line);
    border-radius: calc(8px * var(--md-s)); margin-bottom: calc(14px * var(--md-s)); overflow: hidden; padding: calc(4px * var(--md-s));
}
.ym-acc-head {
    width: 100%; height: var(--ym-act-h); display: flex; align-items: center; gap: calc(7px * var(--md-s));
    padding: 0 calc(12px * var(--md-s)); border: none; border-radius: var(--ym-r); background: var(--ym-btn); text-align: left;
    color: var(--ym-ink); font-size: calc(.74rem * var(--md-s)); font-weight: 700; line-height: 1; transition: background .2s;
}
.ym-acc-head:hover { background: var(--ym-btn-h); }
.ym-acc-head i { font-size: calc(14px * var(--md-s)); flex-shrink: 0; color: var(--ym-ink); }
.ym-acc-head > span { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ym-acc-chevron { font-size: calc(15px * var(--md-s)) !important; transition: transform 0.25s; color: var(--ym-ink); }
.ym-accordion.open .ym-acc-chevron { transform: rotate(180deg); }
.ym-acc-body { display: none; padding: 0 calc(8px * var(--md-s)) calc(10px * var(--md-s)); }
.ym-accordion.open .ym-acc-body { display: block; }
.ym-acc-body p { font-size: calc(.72rem * var(--md-s)); color: var(--ym-sub); line-height: 1.6; margin: calc(10px * var(--md-s)) 0 calc(8px * var(--md-s)); }
.ym-acc-body ul { padding: 0; list-style: none; margin: 0; }
.ym-acc-body ul li {
    display: flex; align-items: flex-start; gap: calc(7px * var(--md-s)); margin-bottom: calc(6px * var(--md-s));
    font-size: calc(.72rem * var(--md-s)); color: var(--ym-sub); line-height: 1.6;
}
.ym-acc-body ul li i { font-size: calc(13px * var(--md-s)); color: var(--ym-blue-l); flex-shrink: 0; margin-top: 2px; }
.ym-acc-body ul li strong, .ym-acc-body p strong { color: var(--text,#F0F2FF); }

.ym-badge-explainer {
    background: var(--ym-card); border: 1px solid var(--ym-line);
    border-radius: calc(8px * var(--md-s)); padding: calc(10px * var(--md-s)) calc(12px * var(--md-s)); margin-bottom: calc(10px * var(--md-s));
}
.ym-be-head { display: flex; align-items: center; gap: calc(7px * var(--md-s)); font-size: calc(.76rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); margin-bottom: calc(5px * var(--md-s)); }
.ym-be-head i { font-size: calc(15px * var(--md-s)); flex-shrink: 0; }
.ym-be-where {
    margin-left: auto; flex-shrink: 0; white-space: nowrap;
    font-size: calc(.56rem * var(--md-s)); font-weight: 600; letter-spacing: .4px; text-transform: uppercase;
    color: var(--ym-blue-l); background: var(--ym-blue-dim);
    border: 1px solid rgba(42,95,216,.35); padding: 2px calc(7px * var(--md-s)); border-radius: calc(4px * var(--md-s));
}
.ym-be-desc { font-size: calc(.7rem * var(--md-s)) !important; color: var(--ym-mut) !important; line-height: 1.55 !important; margin: 0 !important; }
.ym-be-desc strong { color: rgba(var(--fg,255,255,255),.88) !important; }
.ym-be-divider { border: none; border-top: 1px solid var(--ym-line); margin: calc(14px * var(--md-s)) 0 calc(12px * var(--md-s)); }

/* ── Stats ── */
.ym-stats-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: calc(7px * var(--md-s)); margin-bottom: calc(10px * var(--md-s)); }
.ym-stat-card {
    background: var(--ym-card); border: 1px solid var(--ym-line); border-radius: var(--ym-r);
    height: var(--ym-act-h); padding: 0 calc(10px * var(--md-s)); display: flex; flex-direction: row; align-items: center; gap: calc(7px * var(--md-s)); min-width: 0;
}
.ym-stat-num { font-size: calc(.86rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); line-height: 1; flex-shrink: 0; }
.ym-stat-lbl { font-size: calc(.62rem * var(--md-s)); color: var(--ym-mut); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.ym-join-row {
    display: flex; align-items: center; gap: calc(7px * var(--md-s)); height: var(--ym-act-h); padding: 0 calc(10px * var(--md-s));
    background: var(--ym-card); border: 1px solid var(--ym-line); border-radius: var(--ym-r);
    font-size: calc(.66rem * var(--md-s)); color: var(--ym-mut); white-space: nowrap; overflow: hidden;
}
.ym-join-row i { font-size: calc(13px * var(--md-s)); color: var(--ym-blue-l); flex-shrink: 0; }
.ym-join-row strong { color: rgba(var(--fg,255,255,255),.85); }

/* ── Sort row (rectangular) ── */
.ym-sort-row { display: flex; gap: calc(7px * var(--md-s)); margin-bottom: calc(12px * var(--md-s)); flex-wrap: wrap; }
.ym-sort-pill {
    height: calc(var(--ym-act-h) - calc(6px * var(--md-s))); padding: 0 calc(13px * var(--md-s)); border: none; border-radius: var(--ym-r);
    background: var(--ym-btn); color: var(--ym-ink); font-size: calc(.7rem * var(--md-s)); font-weight: 700; transition: .2s;
}
.ym-sort-pill:hover { background: var(--ym-btn-h); }
.ym-sort-pill:active { transform: scale(.96); }
.ym-sort-pill.active { background: var(--ym-btn-on); }
@media (max-width:490px){ .ym-sort-pill{ font-size:calc(.64rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); } }

/* ── Empty state ── */
.ym-no-posts { text-align: center; padding: calc(34px * var(--md-s)) calc(16px * var(--md-s)); color: var(--ym-mut); }
.ym-no-posts i { font-size: calc(30px * var(--md-s)); display: block; margin-bottom: calc(8px * var(--md-s)); color: rgba(126,166,255,.45); }
.ym-no-posts p { font-size: calc(.76rem * var(--md-s)); margin: 0; }

/* ── Post rows ── */
.ym-post-row {
    display: flex; align-items: center; gap: calc(11px * var(--md-s)); padding: calc(10px * var(--md-s)) 0;
    border-bottom: 1px solid var(--ym-line);
}
.ym-post-row:last-child { border-bottom: none; }
.ym-post-thumb {
    position: relative; width: calc(54px * var(--md-s)); height: calc(54px * var(--md-s)); border-radius: calc(8px * var(--md-s)); overflow: hidden; flex-shrink: 0;
    background: var(--ym-card2); border: 1px solid var(--ym-line);
    display: flex; align-items: center; justify-content: center;
}
.ym-post-thumb img, .ym-post-thumb video { width: 100%; height: 100%; object-fit: cover; display: block; }
.ym-play-overlay { position: absolute; font-size: calc(18px * var(--md-s)); color: rgba(255,255,255,.88); pointer-events: none; }
.ym-text-thumb { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; color: var(--ym-mut); font-size: calc(20px * var(--md-s)); }
.ym-post-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: calc(4px * var(--md-s)); }
.ym-post-cap { font-size: calc(.76rem * var(--md-s)); font-weight: 600; color: rgba(var(--fg,255,255,255),.88); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ym-post-meta { display: flex; align-items: center; gap: calc(8px * var(--md-s)); flex-wrap: wrap; font-size: calc(.64rem * var(--md-s)); color: var(--ym-mut); }
.ym-post-meta i { font-size: calc(11px * var(--md-s)); }
.ym-post-meta span { display: inline-flex; align-items: center; gap: calc(3px * var(--md-s)); }
.ym-post-date { margin-left: auto; opacity: .75; }
.ym-del-btn {
    width: calc(32px * var(--md-s)); height: var(--ym-act-h); border-radius: var(--ym-r);
    border: none; background: var(--ym-btn-on); color: var(--ym-ink);
    font-size: calc(15px * var(--md-s)); display: flex; align-items: center; justify-content: center; flex-shrink: 0; padding: 0; transition: .2s;
}
.ym-del-btn:hover { background: var(--ym-btn-on-h); }
.ym-del-btn:active { transform: scale(.94); }

@media (max-width:490px){
    .ym-hero { gap: calc(10px * var(--md-s)); margin: calc(6px * var(--md-s)) 0 calc(14px * var(--md-s)); }
    .ym-acc-head { padding: 0 calc(10px * var(--md-s)); font-size: calc(.66rem * var(--md-s)); }
    .ym-acc-body { padding: 0 calc(6px * var(--md-s)) calc(8px * var(--md-s)); }
    .ym-acc-body p, .ym-acc-body ul li { font-size: calc(.66rem * var(--md-s)); }
    .ym-be-head { font-size: calc(.7rem * var(--md-s)); }
    .ym-be-desc { font-size: calc(.64rem * var(--md-s)) !important; }
    .ym-stat-card { padding: 0 calc(8px * var(--md-s)); gap: calc(5px * var(--md-s)); }
    .ym-stat-num { font-size: calc(.78rem * var(--md-s)); }
    .ym-stat-lbl { font-size: calc(.58rem * var(--md-s)); }
    .ym-join-row { font-size: calc(.6rem * var(--md-s)); padding: 0 calc(8px * var(--md-s)); }
    .ym-post-thumb { width: calc(46px * var(--md-s)); height: calc(46px * var(--md-s)); }
    .ym-post-cap { font-size: calc(.7rem * var(--md-s)); }
    .ym-post-meta { font-size: calc(.6rem * var(--md-s)); gap: calc(6px * var(--md-s)); }
    .ym-del-btn { width: calc(28px * var(--md-s)); font-size: calc(13px * var(--md-s)); }
}
@media (max-width:390px){
    .ym-tabs { flex-basis: 140px; }
    .ym-post-thumb { width: calc(40px * var(--md-s)); height: calc(40px * var(--md-s)); }
    .ym-post-row { gap: calc(8px * var(--md-s)); }
    .ym-be-where { display: none; }
}

/* ── About this page ── */
.ym-about {
    margin-top: calc(16px * var(--md-s)); padding: calc(14px * var(--md-s)) calc(12px * var(--md-s)) calc(12px * var(--md-s));
    background: var(--ym-card); border: 1px solid var(--ym-line); border-radius: calc(8px * var(--md-s));
}
.ym-about-h { display: flex; align-items: center; gap: calc(7px * var(--md-s)); font-size: calc(.8rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); margin-bottom: calc(8px * var(--md-s)); }
.ym-about-h i { font-size: calc(16px * var(--md-s)); color: var(--ym-blue-l); }
.ym-about-sub { font-size: calc(.74rem * var(--md-s)); font-weight: 700; color: var(--ym-blue-l); margin: calc(14px * var(--md-s)) 0 calc(5px * var(--md-s)); }
.ym-about p { font-size: calc(.72rem * var(--md-s)); color: var(--ym-sub); line-height: 1.65; margin: 0 0 calc(6px * var(--md-s)); }
.ym-about p strong, .ym-about li strong { color: var(--text,#F0F2FF); }
.ym-about ul { list-style: none; padding: 0; margin: calc(4px * var(--md-s)) 0 calc(6px * var(--md-s)); }
.ym-about li { display: flex; align-items: flex-start; gap: calc(7px * var(--md-s)); font-size: calc(.72rem * var(--md-s)); color: var(--ym-sub); line-height: 1.6; margin-bottom: calc(5px * var(--md-s)); }
.ym-about li i { font-size: calc(13px * var(--md-s)); color: var(--ym-blue-l); flex-shrink: 0; margin-top: 2px; }
.ym-about-note {
    display: flex; align-items: flex-start; gap: calc(7px * var(--md-s)); margin-top: calc(12px * var(--md-s)); padding: calc(9px * var(--md-s)) calc(10px * var(--md-s));
    border-radius: var(--ym-r); background: var(--ym-blue-dim); border: 1px solid rgba(42,95,216,.35);
    font-size: calc(.68rem * var(--md-s)); color: var(--ym-sub); line-height: 1.55;
}
.ym-about-note i { font-size: calc(14px * var(--md-s)); color: var(--ym-blue-l); flex-shrink: 0; }
@media (max-width:490px){
    .ym-about { padding: calc(12px * var(--md-s)) calc(10px * var(--md-s)) calc(10px * var(--md-s)); }
    .ym-about-h { font-size: calc(.74rem * var(--md-s)); }
    .ym-about-sub { font-size: calc(.68rem * var(--md-s)); }
    .ym-about p, .ym-about li { font-size: calc(.66rem * var(--md-s)); }
    .ym-about-note { font-size: calc(.62rem * var(--md-s)); }
}

@media (prefers-reduced-motion:reduce){ #youModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>

<script>/* lazy start: this block's code loads the first time it is used */
window.dzLazyJs=window.dzLazyJs||function(id,names,eager){var done=false;function run(){if(done)return;done=true;var t=document.getElementById(id);if(!t)return;var f=t.content.cloneNode(true),ss=[].slice.call(f.querySelectorAll('script'));ss.forEach(function(s){s.parentNode.removeChild(s)});ss.forEach(function(o){var s=document.createElement('script');s.text=o.textContent;document.body.appendChild(s)})}names.forEach(function(n){var s=function(){run();var r=window[n];if(r&&r!==s)return r.apply(this,arguments)};s.__dzStub=1;window[n]=s});window['dzLazyRun_'+id]=run;if(eager){var go=function(){run();['pointerdown','keydown','touchstart'].forEach(function(e){document.removeEventListener(e,go,true)})};['pointerdown','keydown','touchstart'].forEach(function(e){document.addEventListener(e,go,{capture:true,passive:true})});var idle=function(){(window.requestIdleCallback||function(c){setTimeout(c,1200)})(run,{timeout:3000})};if(document.readyState==='complete')idle();else window.addEventListener('load',idle)}};
dzLazyJs('dzLzYou',['closeYouModal','openYouModal','ymSort','ymTab','ymToggleAccordion'],false);
</script>
<template id="dzLzYou">
<script>
var _ymOpen = false;

function openYouModal() {
    var m = document.getElementById('youModal');
    if (!m || _ymOpen) return;
    _ymOpen = true;
    m.classList.add('ym-open');
    var b = document.getElementById('ymBody');
    if (b) b.scrollTop = 0;
    document.body.style.overflow = 'hidden';
}

function closeYouModal() {
    var m = document.getElementById('youModal');
    if (!m || !_ymOpen) return;
    _ymOpen = false;
    m.style.animation = 'ymSlideOut 0.22s ease forwards';
    setTimeout(function () {
        m.classList.remove('ym-open');
        m.style.animation = '';
        document.body.style.overflow = '';
    }, 210);
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && _ymOpen) closeYouModal();
});

function ymTab(btn, tabId) {
    document.querySelectorAll('.ym-pill').forEach(function (p) { p.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('.ym-tab').forEach(function (t) { t.classList.remove('active'); });
    var tab = document.getElementById(tabId);
    if (tab) tab.classList.add('active');
    var b = document.getElementById('ymBody');
    if (b) b.scrollTop = 0;
}

function ymToggleAccordion(el) {
    el.classList.toggle('open');
    var head = el.querySelector('.ym-acc-head');
    if (head) head.setAttribute('aria-expanded', el.classList.contains('open') ? 'true' : 'false');
}

function ymSort(key, btn) {
    document.querySelectorAll('.ym-sort-pill').forEach(function (p) { p.classList.remove('active'); });
    btn.classList.add('active');

    var list = document.getElementById('ym-post-list');
    if (!list) return;
    var rows = Array.prototype.slice.call(list.querySelectorAll('.ym-post-row'));

    rows.sort(function (a, b) {
        if (key === 'newest')  return parseInt(b.dataset.newest, 10)  - parseInt(a.dataset.newest, 10);
        if (key === 'oldest')  return parseInt(a.dataset.newest, 10)  - parseInt(b.dataset.newest, 10);
        if (key === 'popular') return parseInt(b.dataset.popular, 10) - parseInt(a.dataset.popular, 10);
        return 0;
    });

    var frag = document.createDocumentFragment();
    rows.forEach(function (r) { frag.appendChild(r); });
    list.appendChild(frag);
}
</script>
</template>
<!-- ══ POD INFO MODAL — centered lane with grey side lines (same design as post modal) ══ -->
<div id="podInfoModal" role="dialog" aria-modal="true" aria-label="Pod info">

    <?php
    /* ── Pre-compute all data ── */
    $stat_members    = (int)$conn->query("SELECT COUNT(*) as c FROM group_members WHERE group_id=$group_id")->fetch_assoc()['c'];
    $stat_posts      = (int)$conn->query("SELECT COUNT(*) as c FROM group_posts WHERE group_id=$group_id")->fetch_assoc()['c'];
    $stat_discs      = (int)$conn->query("SELECT COUNT(*) as c FROM pod_discussions WHERE group_id=$group_id")->fetch_assoc()['c'];
    $stat_admins     = (int)$conn->query("SELECT COUNT(*) as c FROM group_admins WHERE group_id=$group_id")->fetch_assoc()['c'];
    $stat_reposts    = (int)$conn->query("SELECT COUNT(*) as c FROM reposts r JOIN group_posts gp ON gp.meme_id=r.meme_id WHERE gp.group_id=$group_id")->fetch_assoc()['c'];
    $stat_likes      = (int)$conn->query("SELECT COUNT(*) as c FROM likes l JOIN group_posts gp ON gp.meme_id=l.meme_id WHERE gp.group_id=$group_id")->fetch_assoc()['c'];
    $stat_disc_replies = (int)$conn->query("SELECT COUNT(*) as c FROM pod_discussion_replies pdr JOIN pod_discussions pd ON pd.id=pdr.discussion_id WHERE pd.group_id=$group_id")->fetch_assoc()['c'];

    $act_posts_today  = (int)$conn->query("SELECT COUNT(*) as c FROM group_posts WHERE group_id=$group_id AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'];
    $act_new_members  = (int)$conn->query("SELECT COUNT(*) as c FROM group_members WHERE group_id=$group_id AND joined_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
    $act_active_now   = (int)$conn->query("SELECT COUNT(*) as c FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=$group_id AND u.last_seen >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetch_assoc()['c'];

    $ph_res = $conn->query("SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM group_posts WHERE group_id=$group_id GROUP BY hr ORDER BY cnt DESC LIMIT 1");
    $ph_row = $ph_res ? $ph_res->fetch_assoc() : null;
    $peak_hour = $ph_row ? sprintf('%d:00–%d:00', (int)$ph_row['hr'], (int)$ph_row['hr']+1) : 'N/A';

    /* ── Popular topics — auto-detected from post captions (separate from the pod's own set hashtags) ── */
    $tag_res = $conn->query("SELECT m.caption FROM group_posts gp JOIN memes m ON m.id=gp.meme_id WHERE gp.group_id=$group_id AND m.caption IS NOT NULL LIMIT 80");
    $tag_counts = [];
    if ($tag_res) {
        while ($tr = $tag_res->fetch_assoc()) {
            preg_match_all('/#(\w+)/u', $tr['caption'], $mx);
            foreach ($mx[1] as $tg) { $t = strtolower($tg); $tag_counts[$t] = ($tag_counts[$t] ?? 0) + 1; }
        }
    }
    arsort($tag_counts);
    $top_tags = array_slice(array_keys($tag_counts), 0, 10);

    /* ── Image path helper ── */
    $pim_banner = !empty($group['group_pic'])
        ? (strpos($group['group_pic'],'uploads/')===0 ? $group['group_pic'] : 'uploads/'.$group['group_pic'])
        : 'uploads/default_group.png';

    /* ══════════════════════════════════════════════════════════════════
       SMART "SUGGESTED FOR YOU" PODS
       Matches on: (a) same category as the current pod, or
                   (b) a shared significant word between pod titles
                   (e.g. "Funny Memes Club" ↔ "Funny Memes Fan Page").
       Excludes: pods the viewer already belongs to, the current pod
       itself, and any pod the viewer has explicitly dismissed via the
       Remove button (stored permanently in group_suggestion_dismissed).
       Order is randomized every load so the same pods don't always sit
       in the same position. If nothing matches, the whole section is
       hidden — never shows random/unrelated pods as filler.
       ══════════════════════════════════════════════════════════════════ */
    $suggested_pods = [];

    // pods this user has permanently dismissed as a suggestion
    $dismissed_ids = [];
    $dstmt = $conn->prepare("SELECT dismissed_group_id FROM group_suggestion_dismissed WHERE user_id=?");
    $dstmt->bind_param("i", $current_user);
    $dstmt->execute();
    $dres = $dstmt->get_result();
    while ($row = $dres->fetch_assoc()) $dismissed_ids[] = (int)$row['dismissed_group_id'];
    $dstmt->close();

    // pods this user already belongs to (no point suggesting those) + current pod
    $joined_ids = [$group_id];
    $jstmt = $conn->prepare("SELECT group_id FROM group_members WHERE user_id=?");
    $jstmt->bind_param("i", $current_user);
    $jstmt->execute();
    $jres = $jstmt->get_result();
    while ($row = $jres->fetch_assoc()) $joined_ids[] = (int)$row['group_id'];
    $jstmt->close();

    $exclude_ids = array_unique(array_merge($dismissed_ids, $joined_ids));
    $exclude_sql = implode(',', array_map('intval', $exclude_ids));

    // pull meaningful keywords out of this pod's title (drop filler words/short words)
    $sugg_stopwords = ['the','a','an','of','for','and','or','fan','fans','page','club','group','pod','pods','official','community','to','in','on','with','my'];
    $title_words = preg_split('/[^a-z0-9]+/i', strtolower($group['title'] ?? ''));
    $sugg_keywords = [];
    foreach ($title_words as $w) {
        $w = trim($w);
        if ($w !== '' && mb_strlen($w) >= 4 && !in_array($w, $sugg_stopwords, true)) $sugg_keywords[] = $w;
    }
    $sugg_keywords = array_slice(array_unique($sugg_keywords), 0, 6);

    $current_category = $group['category'] ?? '';

    $sugg_where = [];
    $sugg_params = [];
    $sugg_types  = "";

    if (!empty($current_category)) {
        $sugg_where[] = "g.category = ?";
        $sugg_params[] = $current_category;
        $sugg_types   .= "s";
    }
    foreach ($sugg_keywords as $kw) {
        $sugg_where[] = "g.title LIKE ?";
        $sugg_params[] = "%$kw%";
        $sugg_types   .= "s";
    }

    if (!empty($sugg_where)) {
        $sugg_sql = "
            SELECT g.id, g.title, g.group_pic, g.category,
                   (SELECT COUNT(*) FROM group_members WHERE group_id=g.id) AS mc
            FROM groups g
            WHERE g.id NOT IN ($exclude_sql)
              AND (" . implode(' OR ', $sugg_where) . ")
            ORDER BY RAND()
            LIMIT 12
        ";
        $sstmt = $conn->prepare($sugg_sql);
        if ($sstmt) {
            if (!empty($sugg_params)) $sstmt->bind_param($sugg_types, ...$sugg_params);
            $sstmt->execute();
            $sres2 = $sstmt->get_result();
            while ($row = $sres2->fetch_assoc()) $suggested_pods[] = $row;
            $sstmt->close();
        }
    }

    /* ── About text (truncate at 50 words) ── */
    $pim_desc_raw = $group['description'] ?? 'No description set.';
    $pim_desc_words = preg_split('/\s+/', trim($pim_desc_raw));
    $pim_desc_words = array_filter($pim_desc_words, function($w) { return $w !== ''; });
    $pim_desc_words = array_values($pim_desc_words);
    $pim_desc_needs_trunc = count($pim_desc_words) > 50;
    $pim_desc_short_raw = $pim_desc_needs_trunc
        ? implode(' ', array_slice($pim_desc_words, 0, 50))
        : $pim_desc_raw;
    ?>

 <div class="pim-lane">

    <!-- TOPBAR: X left · title centered -->
    <div class="pim-topbar">
        <div class="pim-tb-side">
            <button type="button" class="pim-x" onclick="closePodInfoModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="pim-topbar-center">
            <div class="pim-topbar-title"><?php echo htmlspecialchars($group['title']); ?></div>
        </div>
        <div class="pim-tb-side right"></div>
    </div>

    <!-- SCROLL AREA -->
    <div class="pim-body" id="pimBody">

        <!-- ABOUT -->
        <div class="pim-sec pim-sec-first">
            <div class="pim-section-head"><i class="ri-information-line"></i> About</div>
            <div class="pim-desc-text" id="pimDescText">
                <span id="pimDescShort"><?php echo nl2br(htmlspecialchars($pim_desc_short_raw)); ?></span><span id="pimDescFull" style="display:none;"><?php echo nl2br(htmlspecialchars($pim_desc_raw)); ?></span><?php if ($pim_desc_needs_trunc): ?><span class="pim-desc-toggle" id="pimDescToggleBtn" onclick="pimToggleDesc()">...more</span><?php endif; ?>
            </div>
        </div>

        <!-- OVERVIEW -->
        <div class="pim-sec">
            <div class="pim-section-head"><i class="ri-bar-chart-2-line"></i> Overview</div>
            <table class="pim-details-table">
                <tr>
                    <td class="pim-dt-icon"><i class="ri-group-line"></i></td>
                    <td class="pim-dt-label">Members</td>
                    <td class="pim-dt-value accent"><?php echo number_format($stat_members); ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-image-2-line"></i></td>
                    <td class="pim-dt-label">Posts</td>
                    <td class="pim-dt-value accent"><?php echo number_format($stat_posts); ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-discuss-line"></i></td>
                    <td class="pim-dt-label">Discussions</td>
                    <td class="pim-dt-value accent"><?php echo number_format($stat_discs); ?></td>
                </tr>
            </table>
        </div>

        <!-- DETAILS -->
        <div class="pim-sec">
            <div class="pim-section-head"><i class="ri-list-check-2"></i> Details</div>
            <table class="pim-details-table">
                <tr>
                    <td class="pim-dt-icon"><i class="ri-shield-star-line"></i></td>
                    <td class="pim-dt-label">Admins</td>
                    <td class="pim-dt-value gold"><?php echo number_format($stat_admins); ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-price-tag-3-line"></i></td>
                    <td class="pim-dt-label">Category</td>
                    <td class="pim-dt-value accent"><?php echo htmlspecialchars((string)$pod_category, ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="<?php echo $is_private_group ? 'ri-lock-2-line' : 'ri-earth-line'; ?>"></i></td>
                    <td class="pim-dt-label">Privacy</td>
                    <td class="pim-dt-value <?php echo $is_private_group ? 'red' : 'green'; ?>">
                        <?php echo $is_private_group ? 'Private' : 'Public'; ?>
                    </td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-heart-line"></i></td>
                    <td class="pim-dt-label">Total Likes</td>
                    <td class="pim-dt-value red"><?php echo number_format($stat_likes); ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-repeat-line"></i></td>
                    <td class="pim-dt-label">Total Reposts</td>
                    <td class="pim-dt-value accent"><?php echo number_format($stat_reposts); ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-chat-3-line"></i></td>
                    <td class="pim-dt-label">Discussion Replies</td>
                    <td class="pim-dt-value"><?php echo number_format($stat_disc_replies); ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-time-line"></i></td>
                    <td class="pim-dt-label">Peak Hour</td>
                    <td class="pim-dt-value accent"><?php echo $peak_hour; ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-calendar-event-line"></i></td>
                    <td class="pim-dt-label">Date Created</td>
                    <td class="pim-dt-value"><?php echo date('d M Y', strtotime($group['created_at'])); ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-lock-line"></i></td>
                    <td class="pim-dt-label">Content Rating</td>
                    <td class="pim-dt-value <?php echo !empty($group['is_adult']) ? 'red' : 'green'; ?>">
                        <?php echo !empty($group['is_adult']) ? '18+ Only' : 'All Ages'; ?>
                    </td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-vip-crown-line"></i></td>
                    <td class="pim-dt-label">Creator</td>
                    <td class="pim-dt-value"><?php echo htmlspecialchars($group['creator_name']); ?></td>
                </tr>
            </table>
        </div>

        <!-- LIVE ACTIVITY -->
        <div class="pim-sec">
            <div class="pim-section-head"><i class="ri-pulse-line"></i> Live activity</div>
            <table class="pim-details-table">
                <tr>
                    <td class="pim-dt-icon"><i class="ri-calendar-check-line"></i></td>
                    <td class="pim-dt-label">Posts today</td>
                    <td class="pim-dt-value accent"><?php echo $act_posts_today; ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-user-add-line"></i></td>
                    <td class="pim-dt-label">Joined this week</td>
                    <td class="pim-dt-value accent"><?php echo $act_new_members; ?></td>
                </tr>
                <tr>
                    <td class="pim-dt-icon"><i class="ri-flashlight-line"></i></td>
                    <td class="pim-dt-label">Online now</td>
                    <td class="pim-dt-value" style="color:<?php echo $act_active_now > 0 ? '#4cff91' : '#7ea6ff'; ?>;"><?php echo $act_active_now; ?></td>
                </tr>
            </table>
        </div>

        <!-- CREATOR (boxy card) -->
        <div class="pim-sec">
            <div class="pim-section-head"><i class="ri-vip-crown-line"></i> Creator</div>
            <a href="profile.php?user_id=<?php echo (int)$group['created_by']; ?>" class="pim-creator-row">
                <img src="<?php echo !empty($group['creator_pic']) ? htmlspecialchars($group['creator_pic']) : 'mee.jpg'; ?>"
                     onerror="this.src='mee.jpg';" alt="Creator">
                <div class="pim-creator-text">
                    <div class="pim-creator-label"><i class="ri-vip-crown-fill"></i> Pod Creator</div>
                    <div class="pim-creator-name"><?php echo htmlspecialchars($group['creator_name']); ?></div>
                </div>
                <i class="ri-arrow-right-s-line pim-creator-arrow"></i>
            </a>
        </div>

        <!-- ADMINS -->
        <?php if (!empty($admins)): ?>
        <div class="pim-sec">
            <div class="pim-section-head"><i class="ri-admin-line"></i> Admins (<?php echo count($admins); ?>)</div>
            <div class="pim-people-row">
                <?php foreach ($admins as $adm): ?>
                <a href="profile.php?user_id=<?php echo (int)$adm['id']; ?>" class="pim-person">
                    <img src="<?php echo !empty($adm['profile_pic']) ? htmlspecialchars($adm['profile_pic']) : 'mee.jpg'; ?>"
                         onerror="this.src='mee.jpg';" alt="Admin">
                    <span class="pim-person-name"><?php echo htmlspecialchars($adm['username']); ?></span>
                    <span class="pim-person-role">ADMIN</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- POD HASHTAGS (set by the pod's owner — same set used by the hashtag filter bar) -->
        <?php if (!empty($group_hashtags)): ?>
        <div class="pim-sec">
            <div class="pim-section-head"><i class="ri-price-tag-3-line"></i> Pod Hashtags</div>
            <p class="pim-subtext">Set by this pod's creator — also used to filter the feed</p>
            <div class="pim-tag-wrap" id="pimHashtagWrap">
                <div class="pim-tags-scroll" id="pimHashtagScroll">
                    <?php foreach ($group_hashtags as $tag): ?>
                    <span class="pim-tag pim-tag-official">
                        <i class="ri-hashtag"></i><?php echo htmlspecialchars($tag['tag']); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="pim-tag-arrow pim-tag-arrow-left" onclick="pimScrollTags('pimHashtagScroll',-1)" aria-label="Scroll left">
                    <i class="ri-arrow-left-s-line"></i>
                </button>
                <button type="button" class="pim-tag-arrow pim-tag-arrow-right" onclick="pimScrollTags('pimHashtagScroll',1)" aria-label="Scroll right">
                    <i class="ri-arrow-right-s-line"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- POPULAR TOPICS (auto-detected from post captions) -->
        <div class="pim-sec">
            <div class="pim-section-head"><i class="ri-hashtag"></i> Popular topics</div>
            <?php if (!empty($top_tags)): ?>
            <p class="pim-subtext">Automatically detected from what members are posting</p>
            <div class="pim-tag-wrap" id="pimTopicsWrap">
                <div class="pim-tags-scroll" id="pimTopicsScroll">
                    <?php foreach ($top_tags as $tag): ?>
                    <span class="pim-tag">
                        <i class="ri-hashtag"></i><?php echo htmlspecialchars($tag); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="pim-tag-arrow pim-tag-arrow-left" onclick="pimScrollTags('pimTopicsScroll',-1)" aria-label="Scroll left">
                    <i class="ri-arrow-left-s-line"></i>
                </button>
                <button type="button" class="pim-tag-arrow pim-tag-arrow-right" onclick="pimScrollTags('pimTopicsScroll',1)" aria-label="Scroll right">
                    <i class="ri-arrow-right-s-line"></i>
                </button>
            </div>
            <?php else: ?>
            <div class="pim-empty"><i class="ri-hashtag"></i>No hashtags posted yet — be the first!</div>
            <?php endif; ?>
        </div>

        <!-- SUGGESTED FOR YOU -->
        <?php if (!empty($suggested_pods)): ?>
        <div class="pim-sec" id="pimSuggSec">
            <div class="pim-section-head"><i class="ri-compass-discover-line"></i> Suggested for you</div>
            <div class="pim-sugg-wrap" id="pimSuggWrap">
                <div class="pim-sugg-scroll" id="pimSuggScroll">
                    <?php foreach ($suggested_pods as $sp):
                        $sp_img = !empty($sp['group_pic'])
                            ? (strpos($sp['group_pic'],'uploads/')===0 ? $sp['group_pic'] : 'uploads/'.$sp['group_pic'])
                            : 'uploads/default_group.png';
                    ?>
                    <div class="pim-sugg-card" id="pimSuggCard-<?php echo (int)$sp['id']; ?>">
                        <a href="group.php?id=<?php echo (int)$sp['id']; ?>" class="pim-sugg-img-wrap">
                            <img src="<?php echo htmlspecialchars($sp_img); ?>" onerror="this.src='uploads/default_group.png';" alt="">
                        </a>
                        <div class="pim-sugg-body">
                            <a href="group.php?id=<?php echo (int)$sp['id']; ?>" style="text-decoration:none;">
                                <div class="pim-sugg-name"><?php echo htmlspecialchars($sp['title']); ?></div>
                            </a>
                            <div class="pim-sugg-members"><?php echo number_format((int)$sp['mc']); ?> members</div>
                            <div class="pim-sugg-btns">
                                <form method="POST" action="group.php?id=<?php echo (int)$sp['id']; ?>" style="display:contents;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <button type="submit" name="join_group" class="pim-sugg-join-btn">Join</button>
                                </form>
                                <button type="button" class="pim-sugg-remove-btn" onclick="pimDismissSuggestion(<?php echo (int)$sp['id']; ?>, this)">Remove</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="pim-sugg-arrow pim-sugg-arrow-left" onclick="pimScrollSugg(-1)" aria-label="Scroll left">
                    <i class="ri-arrow-left-s-line"></i>
                </button>
                <button type="button" class="pim-sugg-arrow pim-sugg-arrow-right" onclick="pimScrollSugg(1)" aria-label="Scroll right">
                    <i class="ri-arrow-right-s-line"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /pim-body -->
 </div><!-- /pim-lane -->
</div><!-- /podInfoModal -->

<style>
/* ═══════════════════════════════════════════════════════════
   POD INFO MODAL — same design language as the post modal
   • full-screen overlay, content in a CENTERED LANE
   • two grey lines separate the lane from the empty space
   • rectangular 5px buttons, boxy cards, premium fluid sizing
   • dark mid-tone BLUE accent, text on solid blue is BLACK
═══════════════════════════════════════════════════════════ */
#podInfoModal {
    --pm-blue:#2a5fd8; --pm-blue-h:#234fb5; --pm-blue-l:#7ea6ff;
    --pm-blue-dim:rgba(42,95,216,.18); --pm-blue-glow:rgba(42,95,216,.34);
    --pm-on-blue:#000;
    --pm-card:rgba(var(--fg,255,255,255),.05); --pm-card2:rgba(var(--fg,255,255,255),.08);
    --pm-line:rgba(var(--fg,255,255,255),.09); --pm-line2:rgba(var(--fg,255,255,255),.14);
    --pm-sub:rgba(var(--fg,255,255,255),.62); --pm-mut:rgba(var(--fg,255,255,255),.45);
    --pm-pad:calc(clamp(9px,3vw,16px) * var(--md-s));
    --pm-act-h:calc(clamp(25px,7.2vw,30px) * var(--md-s));
    --pm-r:5px;
    --pm-fs:calc(.8rem * var(--md-s));
    --pm-tb-h:calc(54px * var(--md-s));

    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999;
    background: linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--pm-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
}
@media (max-width:490px){ #podInfoModal{ --pm-fs:calc(.74rem * var(--md-s)); --pm-tb-h:calc(48px * var(--md-s)); } }
@media (max-width:390px){ #podInfoModal{ --pm-fs:calc(.7rem * var(--md-s));  --pm-tb-h:calc(44px * var(--md-s)); } }
#podInfoModal.open { display: flex; animation: podPageIn 0.28s cubic-bezier(0.22,1,0.36,1); }
@keyframes podPageIn  { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes podPageOut { to { opacity: 0; transform: translateY(16px); } }

#podInfoModal *, #podInfoModal *::before, #podInfoModal *::after { box-sizing: border-box; }
#podInfoModal button, #podInfoModal input { font-family: 'Poppins', sans-serif; }
#podInfoModal button { cursor: pointer; -webkit-tap-highlight-color: transparent; }
#podInfoModal button, #podInfoModal a, #podInfoModal input { outline: none; }
#podInfoModal a { color: inherit; text-decoration: none; }
/* icons keep the icon font */
#podInfoModal [class^="ri-"], #podInfoModal [class*=" ri-"],
#podInfoModal [class^="ri-"]::before, #podInfoModal [class*=" ri-"]::before {
    font-family: 'remixicon' !important; font-style: normal; font-weight: normal; line-height: 1;
}

/* ── the lane + two grey lines ── */
.pim-lane {
    width: 100%; max-width: 580px; height: 100%;
    display: flex; flex-direction: column; min-height: 0;
}
@media (min-width: 801px) {
    .pim-lane { border-left: 1px solid var(--pm-line); border-right: 1px solid var(--pm-line); }
}
@media (max-width: 800px) { .pim-lane { max-width: 100%; } }

/* ── Top bar ── */
.pim-topbar {
    flex-shrink: 0;
    display: flex; align-items: center;
    min-height: var(--pm-tb-h);
    padding: env(safe-area-inset-top) calc(10px * var(--md-s)) 0;
    background: transparent;
}
.pim-tb-side { flex: 0 0 38px; display: flex; align-items: center; }
.pim-tb-side.right { justify-content: flex-end; }
.pim-x {
    width: calc(34px * var(--md-s)); height: calc(34px * var(--md-s)); background: none; border: none; color: var(--text,#F0F2FF);
    display: flex; align-items: center; justify-content: center; opacity: .9;
    padding: 0; transition: 0.2s;
}
.pim-x:hover { color: var(--pm-blue-l); opacity: 1; }
.pim-x svg { width: calc(22px * var(--md-s)); height: calc(22px * var(--md-s)); display: block; }
.pim-topbar-center { flex: 1; min-width: 0; text-align: center; }
.pim-topbar-title {
    font-size: calc(.92rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width:490px){ .pim-topbar-title{ font-size:calc(.84rem * var(--md-s)); } .pim-x{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); } }
@media (max-width:390px){ .pim-topbar-title{ font-size:calc(.8rem * var(--md-s)); } }

/* ── Scroll area ── */
.pim-body {
    flex: 1; min-height: 0;
    overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch;
    padding: calc(4px * var(--md-s)) var(--pm-pad) calc(calc(22px * var(--md-s)) + env(safe-area-inset-bottom));
    scrollbar-width: thin; scrollbar-color: rgba(42,95,216,.4) transparent;
}
.pim-body::-webkit-scrollbar { width: calc(4px * var(--md-s)); }
.pim-body::-webkit-scrollbar-thumb { background: rgba(42,95,216,.45); border-radius: calc(99px * var(--md-s)); }

/* ── Sections ── */
.pim-sec { margin-top: calc(16px * var(--md-s)); padding-top: calc(14px * var(--md-s)); border-top: 1px solid var(--pm-line); }
.pim-sec-first { margin-top: calc(6px * var(--md-s)); padding-top: 0; border-top: none; }
.pim-section-head {
    display: flex; align-items: center; gap: calc(6px * var(--md-s)); margin-bottom: calc(9px * var(--md-s));
    font-size: calc(.64rem * var(--md-s)); font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--pm-sub);
}
.pim-section-head i { font-size: calc(14px * var(--md-s)); color: var(--pm-blue-l); }
.pim-subtext { font-size: calc(.66rem * var(--md-s)); color: var(--pm-mut); margin: calc(-3px * var(--md-s)) 0 calc(9px * var(--md-s)); line-height: 1.5; }
.pim-empty { padding: calc(14px * var(--md-s)) 0; text-align: center; color: var(--pm-mut); font-size: calc(.72rem * var(--md-s)); }
.pim-empty i { font-size: calc(24px * var(--md-s)); display: block; margin-bottom: calc(6px * var(--md-s)); color: rgba(126,166,255,.45); }

/* ── About ── */
.pim-desc-text { font-size: calc(.8rem * var(--md-s)); line-height: 1.65; color: rgba(var(--fg,255,255,255),.82); word-break: break-word; }
.pim-desc-toggle { color: var(--pm-blue-l); font-weight: 600; cursor: pointer; margin-left: calc(4px * var(--md-s)); white-space: nowrap; }
.pim-desc-toggle:hover { color: var(--text,#F0F2FF); }
@media (max-width:490px){ .pim-desc-text{ font-size:calc(.74rem * var(--md-s)); } }

/* ── Details tables (boxy cards) ── */
.pim-details-table {
    width: 100%; border-collapse: separate; border-spacing: 0;
    background: var(--pm-card); border: 1px solid var(--pm-line); border-radius: calc(10px * var(--md-s)); overflow: hidden;
}
.pim-details-table tr + tr td { border-top: 1px solid var(--pm-line); }
.pim-details-table td { padding: calc(10px * var(--md-s)) calc(12px * var(--md-s)); vertical-align: middle; }
.pim-dt-icon { width: calc(38px * var(--md-s)); text-align: center; padding-right: 0 !important; }
.pim-dt-icon i { font-size: calc(17px * var(--md-s)); color: var(--pm-blue-l); }
.pim-dt-label { font-size: calc(.76rem * var(--md-s)); color: var(--pm-sub); }
.pim-dt-value { text-align: right; font-size: calc(.78rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); word-break: break-word; }
.pim-dt-value.accent { color: var(--pm-blue-l); }
.pim-dt-value.gold   { color: var(--pm-blue-l);}
.pim-dt-value.green  { color: var(--pm-blue-l); }
.pim-dt-value.red    { color: var(--pm-blue-l); }
@media (max-width:490px){
    .pim-details-table td{ padding:calc(8px * var(--md-s)) calc(10px * var(--md-s)); }
    .pim-dt-icon{ width:calc(32px * var(--md-s)); } .pim-dt-icon i{ font-size:calc(15px * var(--md-s)); }
    .pim-dt-label{ font-size:calc(.68rem * var(--md-s)); } .pim-dt-value{ font-size:calc(.7rem * var(--md-s)); }
}

/* ── Creator (boxy card, same as post modal cards) ── */
.pim-creator-row {
    display: flex; align-items: center; gap: calc(10px * var(--md-s)); padding: calc(9px * var(--md-s)) calc(10px * var(--md-s));
    border-radius: calc(8px * var(--md-s)); background: var(--pm-card); border: 1px solid var(--pm-line); transition: .2s;
}
.pim-creator-row:hover { border-color: rgba(42,95,216,.55); background: var(--pm-blue-dim); }
.pim-creator-row img {
    width: calc(clamp(34px,9vw,42px) * var(--md-s)); height: calc(clamp(34px,9vw,42px) * var(--md-s));
    border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 2px solid var(--pm-blue);
}
.pim-creator-text { flex: 1; min-width: 0; }
.pim-creator-label { display: flex; align-items: center; gap: calc(4px * var(--md-s)); font-size: calc(.6rem * var(--md-s)); color: var(--pm-mut); }
.pim-creator-label i { font-size: calc(10px * var(--md-s)); color: var(--pm-blue-l); }
.pim-creator-name { font-size: calc(.82rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pim-creator-arrow { margin-left: auto; color: var(--pm-mut); font-size: calc(20px * var(--md-s)); flex-shrink: 0; }
@media (max-width:490px){ .pim-creator-name{ font-size:calc(.76rem * var(--md-s)); } }

/* ── Admins ── */
.pim-people-row { display: flex; gap: calc(8px * var(--md-s)); overflow-x: auto; padding: 2px 1px calc(4px * var(--md-s)); scrollbar-width: none; }
.pim-people-row::-webkit-scrollbar { display: none; }
.pim-person {
    flex: 0 0 auto; width: calc(86px * var(--md-s)); display: flex; flex-direction: column; align-items: center; gap: calc(4px * var(--md-s));
    padding: calc(10px * var(--md-s)) calc(6px * var(--md-s)); border-radius: calc(8px * var(--md-s)); background: var(--pm-card); border: 1px solid var(--pm-line); transition: .2s;
}
.pim-person:hover { border-color: rgba(42,95,216,.55); background: var(--pm-blue-dim); }
.pim-person img { width: calc(40px * var(--md-s)); height: calc(40px * var(--md-s)); border-radius: 50%; object-fit: cover; border: 2px solid var(--pm-blue); }
.pim-person-name { max-width: 100%; font-size: calc(.66rem * var(--md-s)); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pim-person-role {
    font-size: calc(7.5px * var(--md-s)); font-weight: 800; letter-spacing: .5px; padding: 1px calc(6px * var(--md-s)); border-radius: calc(4px * var(--md-s));
    background: var(--pm-blue); color: var(--pm-on-blue);
}
@media (max-width:490px){ .pim-person{ width:calc(78px * var(--md-s)); padding:calc(8px * var(--md-s)) calc(5px * var(--md-s)); } .pim-person img{ width:calc(36px * var(--md-s)); height:calc(36px * var(--md-s)); } }

/* ── Tags (hashtags + popular topics) ── */
.pim-tag-wrap { position: relative; }
.pim-tags-scroll { display: flex; gap: calc(7px * var(--md-s)); overflow-x: auto; padding: 2px 1px; scrollbar-width: none; scroll-behavior: smooth; }
.pim-tags-scroll::-webkit-scrollbar { display: none; }
.pim-tag {
    flex: 0 0 auto; display: inline-flex; align-items: center; gap: 2px;
    height: var(--pm-act-h); padding: 0 calc(12px * var(--md-s)); border-radius: var(--pm-r);
    background: rgba(var(--fg,255,255,255),.1); color: var(--text,#F0F2FF); font-size: calc(.74rem * var(--md-s)); font-weight: 600; white-space: nowrap;
}
.pim-tag i { font-size: calc(11px * var(--md-s)); color: var(--pm-blue-l); }
.pim-tag-official { background: var(--pm-blue); color: var(--pm-on-blue); }
.pim-tag-official i { color: var(--pm-on-blue); }
@media (max-width:490px){ .pim-tag{ font-size:calc(.66rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); } }

/* ── Scroll arrows (tags + suggested) ── */
.pim-tag-arrow, .pim-sugg-arrow {
    position: absolute; top: 50%; transform: translateY(-50%);
    width: calc(26px * var(--md-s)); height: calc(26px * var(--md-s)); padding: 0; border: none; border-radius: var(--pm-r);
    background: rgba(1,2,24,.85); color: #fff; font-size: calc(18px * var(--md-s)); z-index: 2;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid var(--pm-line2); transition: .2s;
}
.pim-tag-arrow:hover, .pim-sugg-arrow:hover { background: var(--pm-blue); color: var(--pm-on-blue); border-color: var(--pm-blue); }
.pim-tag-arrow-left, .pim-sugg-arrow-left { left: calc(-4px * var(--md-s)); }
.pim-tag-arrow-right, .pim-sugg-arrow-right { right: calc(-4px * var(--md-s)); }
@media (max-width:600px){ .pim-tag-arrow, .pim-sugg-arrow { display: none; } }

/* ── Suggested for you ── */
.pim-sugg-wrap { position: relative; }
.pim-sugg-scroll { display: flex; gap: calc(8px * var(--md-s)); overflow-x: auto; padding: 2px 1px calc(4px * var(--md-s)); scrollbar-width: none; scroll-behavior: smooth; }
.pim-sugg-scroll::-webkit-scrollbar { display: none; }
.pim-sugg-card {
    flex: 0 0 auto; width: calc(138px * var(--md-s)); display: flex; flex-direction: column; overflow: hidden;
    border-radius: calc(8px * var(--md-s)); background: var(--pm-card); border: 1px solid var(--pm-line); transition: .2s;
}
.pim-sugg-card:hover { border-color: rgba(42,95,216,.55); }
.pim-sugg-img-wrap { display: block; width: 100%; aspect-ratio: 1/1; background: var(--surface-2,#0d1030); overflow: hidden; }
.pim-sugg-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pim-sugg-body { padding: calc(8px * var(--md-s)); display: flex; flex-direction: column; gap: 2px; }
.pim-sugg-name { font-size: calc(.72rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pim-sugg-members { font-size: calc(.62rem * var(--md-s)); color: var(--pm-mut); margin-bottom: calc(6px * var(--md-s)); }
.pim-sugg-btns { display: flex; gap: calc(5px * var(--md-s)); }
.pim-sugg-join-btn, .pim-sugg-remove-btn {
    flex: 1; min-width: 0; height: calc(24px * var(--md-s)); padding: 0 calc(6px * var(--md-s)); border: none; border-radius: var(--pm-r);
    font-size: calc(.64rem * var(--md-s)); font-weight: 700; line-height: 1; white-space: nowrap; transition: .2s;
}
.pim-sugg-join-btn { background: var(--pm-blue); color: var(--pm-on-blue); }
.pim-sugg-join-btn:hover { background: var(--pm-blue-h); box-shadow: 0 4px 16px var(--pm-blue-glow); }
.pim-sugg-remove-btn { background: rgba(var(--fg,255,255,255),.1); color: var(--text,#F0F2FF); }
.pim-sugg-remove-btn:hover { background: rgba(var(--fg,255,255,255),.2); }
.pim-sugg-join-btn:disabled, .pim-sugg-remove-btn:disabled { opacity: .5; cursor: not-allowed; }
@media (max-width:490px){ .pim-sugg-card{ width:calc(124px * var(--md-s)); } .pim-sugg-name{ font-size:calc(.66rem * var(--md-s)); } }

@media (prefers-reduced-motion:reduce){ #podInfoModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>

<script>/* lazy start: this block's code loads the first time it is used */
window.dzLazyJs=window.dzLazyJs||function(id,names,eager){var done=false;function run(){if(done)return;done=true;var t=document.getElementById(id);if(!t)return;var f=t.content.cloneNode(true),ss=[].slice.call(f.querySelectorAll('script'));ss.forEach(function(s){s.parentNode.removeChild(s)});ss.forEach(function(o){var s=document.createElement('script');s.text=o.textContent;document.body.appendChild(s)})}names.forEach(function(n){var s=function(){run();var r=window[n];if(r&&r!==s)return r.apply(this,arguments)};s.__dzStub=1;window[n]=s});window['dzLazyRun_'+id]=run;if(eager){var go=function(){run();['pointerdown','keydown','touchstart'].forEach(function(e){document.removeEventListener(e,go,true)})};['pointerdown','keydown','touchstart'].forEach(function(e){document.addEventListener(e,go,{capture:true,passive:true})});var idle=function(){(window.requestIdleCallback||function(c){setTimeout(c,1200)})(run,{timeout:3000})};if(document.readyState==='complete')idle();else window.addEventListener('load',idle)}};
dzLazyJs('dzLzPod',['closePodInfoModal','openPodInfoModal','pimDismissSuggestion','pimScrollSugg','pimScrollTags','pimToggleDesc'],false);
</script>
<template id="dzLzPod">
<script>
/* ══ Pod Info Modal JS ══ */
function openPodInfoModal() {
    var m = document.getElementById('podInfoModal');
    if (!m) return;
    m.classList.add('open');
    var b = document.getElementById('pimBody');
    if (b) b.scrollTop = 0;
    document.body.style.overflow = 'hidden';
}

function closePodInfoModal() {
    var m = document.getElementById('podInfoModal');
    if (!m || !m.classList.contains('open')) return;
    m.style.animation = 'podPageOut 0.22s ease forwards';
    setTimeout(function () {
        m.classList.remove('open');
        m.style.animation = '';
        document.body.style.overflow = '';
    }, 210);
}

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var m = document.getElementById('podInfoModal');
    if (m && m.classList.contains('open')) closePodInfoModal();
});

/* ── About description: ...more / see less toggle ── */
function pimToggleDesc() {
    var shortEl = document.getElementById('pimDescShort');
    var fullEl = document.getElementById('pimDescFull');
    var btn = document.getElementById('pimDescToggleBtn');
    if (!shortEl || !fullEl || !btn) return;

    var isExpanded = fullEl.style.display !== 'none';
    if (isExpanded) {
        fullEl.style.display = 'none';
        shortEl.style.display = '';
        btn.textContent = '...more';
    } else {
        fullEl.style.display = '';
        shortEl.style.display = 'none';
        btn.textContent = 'see less';
    }
}

/* ── Hashtag / Popular Topics strips: horizontal scroll arrows ── */
function pimScrollTags(scrollId, dir) {
    var track = document.getElementById(scrollId);
    if (!track) return;
    var step = track.clientWidth * 0.7;
    track.scrollBy({ left: dir * step, behavior: 'smooth' });
}

/* ── Suggested For You: horizontal scroll arrows ── */
function pimScrollSugg(dir) {
    var track = document.getElementById('pimSuggScroll');
    if (!track) return;
    var card = track.querySelector('.pim-sugg-card');
    var step = card ? (card.offsetWidth + 8) * 2 : 300;
    track.scrollBy({ left: dir * step, behavior: 'smooth' });
}

/* ── Suggested For You: permanently dismiss a suggestion ── */
function pimDismissSuggestion(groupId, btn) {
    btn.disabled = true;
    var card = document.getElementById('pimSuggCard-' + groupId);

    fetch('group.php?id=<?php echo (int)$group_id; ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'dismiss_suggested_group=1&dismiss_group_id=' + groupId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function (r) { return r.json(); })
    .then(function () {
        if (!card) return;
        card.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        card.style.opacity = '0';
        card.style.transform = 'scale(0.9)';
        setTimeout(function () {
            card.remove();
            var scroll = document.getElementById('pimSuggScroll');
            var sec = document.getElementById('pimSuggSec');
            if (scroll && sec && !scroll.querySelector('.pim-sugg-card')) {
                sec.style.display = 'none';
            }
        }, 260);
    })
    .catch(function () {
        btn.disabled = false;
    });
}
</script>
</template>

<!-- ═══ INVITE MODAL — centered lane with grey side lines (same design as Post Modal) ═══ -->
<div id="inviteModal" role="dialog" aria-modal="true" aria-label="Invite members">
 <div class="im-lane">

    <!-- TOPBAR: X left · title centered · spacer right -->
    <div class="im-topbar">
        <div class="im-tb-side">
            <button type="button" class="im-x" onclick="closeInviteModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
        <div class="im-topbar-center">
            <div class="im-topbar-title">Invite Members</div>
        </div>
        <div class="im-tb-side right"></div>
    </div>

    <div class="im-body" id="imBody">

        <!-- ── WHAT THIS IS ── -->
        <div class="im-about">
            <div class="im-about-ic"><i class="ri-user-add-line"></i></div>
            <div class="im-about-text">
                <div class="im-about-label">Inviting to</div>
                <div class="im-about-name"><?= htmlspecialchars($group['title']) ?></div>
                <p class="im-about-desc">Find a dezzmer by username or nickname and send them an invitation to join this pod. Tap <strong>Invite</strong> next to a name — each person only needs to be invited once.</p>
            </div>
        </div>

        <!-- ── SEARCH ── -->
        <div class="im-search">
            <i class="ri-search-line"></i>
            <input id="inviteSearchInput" type="text" placeholder="Search for a dezzmer…" oninput="searchInviteUsers(this.value)" autocomplete="off" aria-label="Search for a dezzmer">
        </div>

        <!-- ── RESULTS ── -->
        <div class="im-sec">
            <div class="im-sec-h"><i class="ri-group-line"></i> <span id="imResultsLabel">People</span></div>
            <div id="inviteUserList" aria-live="polite"></div>
        </div>

    </div><!-- /.im-body -->

    <!-- ACTION BAR -->
    <div class="im-actionbar">
        <button type="button" class="im-btn ghost" onclick="closeInviteModal()"><i class="ri-check-line"></i> Done</button>
    </div>

 </div><!-- /.im-lane -->
</div>

<style>
/* ═══════════════════════════════════════════
   INVITE LINK / TRIGGER (unchanged behaviour)
═══════════════════════════════════════════ */
.invite-modal-btn{
    color:#2088e2; font-family:'Poppins',sans-serif; font-size:calc(14px * var(--md-s)); font-weight:700;
    display:inline-flex; align-items:center; gap:calc(5px * var(--md-s)); padding:0; margin:0;
    border:none; background:none; cursor:pointer;
    transition:color .2s ease, opacity .2s ease; white-space:nowrap;
}
.invite-modal-btn:hover{ color:#76c0ff; opacity:.9; transform:none; }
.invite-modal-btn i{ font-size:calc(15px * var(--md-s)); }
@media (max-width:760px){ .invite-modal-btn{ font-size:calc(12px * var(--md-s)); gap:calc(4px * var(--md-s)); } .invite-modal-btn i{ font-size:calc(13px * var(--md-s)); } }
@media (max-width:480px){ .invite-modal-btn{ font-size:calc(10px * var(--md-s)); gap:calc(3px * var(--md-s)); } .invite-modal-btn i{ font-size:calc(11px * var(--md-s)); } }
@media (max-width:360px){ .invite-modal-btn{ font-size:calc(9px * var(--md-s)); } .invite-modal-btn i{ font-size:calc(10px * var(--md-s)); } }
@media (max-width:320px){ .invite-modal-btn{ font-size:calc(8px * var(--md-s)); } .invite-modal-btn i{ font-size:calc(9px * var(--md-s)); } }

/* ═══════════════════════════════════════════════════════════
   INVITE MODAL — same design language as Post Modal
   • full-screen overlay, content in a CENTERED LANE
   • two grey lines separate the lane from the empty space
   • rectangular 5px buttons, fluid premium sizing
   • dark mid-tone BLUE accent, text on solid blue is BLACK
═══════════════════════════════════════════════════════════ */
#inviteModal {
    --im-blue:#2a5fd8; --im-blue-h:#234fb5; --im-blue-l:#7ea6ff;
    --im-blue-dim:rgba(42,95,216,.18); --im-blue-glow:rgba(42,95,216,.34);
    --im-on-blue:#000;
    --im-card:rgba(var(--fg,255,255,255),.05); --im-card2:rgba(var(--fg,255,255,255),.08);
    --im-line:rgba(var(--fg,255,255,255),.09); --im-line2:rgba(var(--fg,255,255,255),.14);
    --im-sub:rgba(var(--fg,255,255,255),.62); --im-mut:rgba(var(--fg,255,255,255),.45);
    --im-pad:calc(clamp(9px,3vw,16px) * var(--md-s));
    --im-act-h:calc(clamp(25px,7.2vw,30px) * var(--md-s));
    --im-r:5px;
    --im-fs:calc(.8rem * var(--md-s));
    --im-tb-h:calc(54px * var(--md-s));

    display:none;
    position:fixed; inset:0;
    z-index:999999999;
    background:linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    background-attachment:fixed;
    justify-content:center;
    overflow:hidden;
    font-family:'Poppins',sans-serif;
    font-size:var(--im-fs);
    color:#fff;
    -webkit-font-smoothing:antialiased;
}
@media (max-width:490px){ #inviteModal{ --im-fs:calc(.74rem * var(--md-s)); --im-tb-h:calc(48px * var(--md-s)); } }
@media (max-width:390px){ #inviteModal{ --im-fs:calc(.7rem * var(--md-s));  --im-tb-h:calc(44px * var(--md-s)); } }
#inviteModal.open { display:flex; animation:imSlideIn .28s cubic-bezier(0.22,1,0.36,1); }
@keyframes imSlideIn  { from{opacity:0;transform:translateY(20px);} to{opacity:1;transform:translateY(0);} }
@keyframes imSlideOut { to{opacity:0;transform:translateY(16px);} }

#inviteModal *, #inviteModal *::before, #inviteModal *::after { box-sizing:border-box; }
#inviteModal button, #inviteModal input { font-family:'Poppins',sans-serif; }
#inviteModal button { cursor:pointer; -webkit-tap-highlight-color:transparent; }
#inviteModal button, #inviteModal input { outline:none; }
#inviteModal [class^="ri-"], #inviteModal [class*=" ri-"],
#inviteModal [class^="ri-"]::before, #inviteModal [class*=" ri-"]::before {
    font-family:'remixicon' !important; font-style:normal; font-weight:normal; line-height:1;
}

/* ── lane + grey side lines ── */
.im-lane { width:100%; max-width:580px; height:100%; display:flex; flex-direction:column; min-height:0; }
@media (min-width:801px){ .im-lane { border-left:1px solid var(--im-line); border-right:1px solid var(--im-line); } }
@media (max-width:800px){ .im-lane { max-width:100%; } }

/* ── top bar ── */
.im-topbar { flex-shrink:0; display:flex; align-items:center; min-height:var(--im-tb-h); padding:env(safe-area-inset-top) calc(10px * var(--md-s)) 0; background:transparent; }
.im-tb-side { flex:0 0 38px; display:flex; align-items:center; }
.im-tb-side.right { justify-content:flex-end; }
.im-x { width:calc(34px * var(--md-s)); height:calc(34px * var(--md-s)); background:none; border:none; color:var(--text,#F0F2FF); display:flex; align-items:center; justify-content:center; opacity:.9; padding:0; transition:.2s; }
.im-x:hover { color:var(--im-blue-l); opacity:1; }
.im-x svg { width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); display:block; }
.im-topbar-center { flex:1; min-width:0; text-align:center; }
.im-topbar-title { font-size:calc(.92rem * var(--md-s)); font-weight:700; color:var(--text,#F0F2FF); line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
@media (max-width:490px){ .im-topbar-title{font-size:calc(.84rem * var(--md-s));} .im-x{width:calc(30px * var(--md-s));height:calc(30px * var(--md-s));} }
@media (max-width:390px){ .im-topbar-title{font-size:calc(.8rem * var(--md-s));} }

/* ── scroll body ── */
.im-body {
    flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch;
    padding:calc(4px * var(--md-s)) var(--im-pad) calc(18px * var(--md-s));
    scrollbar-width:thin; scrollbar-color:rgba(42,95,216,.4) transparent;
}
.im-body::-webkit-scrollbar { width:calc(4px * var(--md-s)); }
.im-body::-webkit-scrollbar-thumb { background:rgba(42,95,216,.45); border-radius:calc(99px * var(--md-s)); }

/* ── about card ── */
.im-about { display:flex; gap:calc(12px * var(--md-s)); align-items:flex-start; margin:calc(8px * var(--md-s)) 0 calc(14px * var(--md-s)); padding:calc(12px * var(--md-s)); border-radius:calc(10px * var(--md-s)); background:var(--im-blue-dim); border:1px solid rgba(42,95,216,.35); }
.im-about-ic { width:calc(clamp(34px,9vw,42px) * var(--md-s)); height:calc(clamp(34px,9vw,42px) * var(--md-s)); border-radius:var(--im-r); background:var(--im-blue); color:var(--im-on-blue); display:flex; align-items:center; justify-content:center; font-size:calc(18px * var(--md-s)); flex-shrink:0; }
.im-about-text { min-width:0; flex:1; }
.im-about-label { font-size:calc(.6rem * var(--md-s)); color:var(--im-mut); }
.im-about-name { font-size:calc(.84rem * var(--md-s)); font-weight:700; color:var(--text,#F0F2FF); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.im-about-desc { margin:calc(6px * var(--md-s)) 0 0; font-size:calc(.7rem * var(--md-s)); line-height:1.55; color:var(--im-sub); }
.im-about-desc strong { color:var(--im-blue-l); font-weight:600; }
@media (max-width:490px){ .im-about-name{font-size:calc(.78rem * var(--md-s));} .im-about-desc{font-size:calc(.66rem * var(--md-s));} }

/* ── search ── */
.im-search { display:flex; align-items:center; gap:calc(9px * var(--md-s)); height:calc(var(--im-act-h) + calc(8px * var(--md-s))); padding:0 calc(12px * var(--md-s)); border-radius:var(--im-r); border:1px solid var(--im-line2); background:var(--im-card); transition:.2s; }
.im-search:focus-within { border-color:rgba(42,95,216,.85); box-shadow:0 0 0 3px rgba(42,95,216,.18); }
.im-search i { color:var(--im-blue-l); font-size:calc(15px * var(--md-s)); flex-shrink:0; }
.im-search input { flex:1; min-width:0; height:100%; border:none; background:transparent; color:var(--text,#F0F2FF); font-size:calc(.78rem * var(--md-s)); -webkit-appearance:none; appearance:none; }
.im-search input::placeholder { color:var(--im-mut); }
@media (max-width:490px){ .im-search input{ font-size:calc(.72rem * var(--md-s)); } }

/* ── section ── */
.im-sec { margin-top:calc(16px * var(--md-s)); padding-top:calc(14px * var(--md-s)); border-top:1px solid var(--im-line); }
.im-sec-h { display:flex; align-items:center; gap:calc(6px * var(--md-s)); margin-bottom:calc(9px * var(--md-s)); font-size:calc(.64rem * var(--md-s)); font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--im-sub); }
.im-sec-h i { font-size:calc(14px * var(--md-s)); color:var(--im-blue-l); }

/* ── user rows ── */
.im-row { display:flex; align-items:center; gap:calc(11px * var(--md-s)); padding:calc(9px * var(--md-s)) 2px; border-bottom:1px solid var(--im-line); }
.im-row:last-child { border-bottom:none; }
.im-row img { width:calc(clamp(34px,9vw,42px) * var(--md-s)); height:calc(clamp(34px,9vw,42px) * var(--md-s)); border-radius:50%; object-fit:cover; border:2px solid var(--im-blue); flex-shrink:0; background:var(--surface-2,#0d1030); }
.im-row-info { flex:1; min-width:0; }
.im-name { font-size:calc(.78rem * var(--md-s)); font-weight:600; color:var(--text,#F0F2FF); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.im-nick { font-size:calc(.64rem * var(--md-s)); color:var(--im-mut); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }
@media (max-width:490px){ .im-name{font-size:calc(.72rem * var(--md-s));} }

/* ── buttons: boxy, blue, black text ── */
.im-btn {
    display:flex; align-items:center; justify-content:center; gap:calc(6px * var(--md-s));
    flex:1; min-width:0; height:var(--im-act-h); padding:0 calc(14px * var(--md-s));
    border:none; border-radius:var(--im-r); background:var(--im-blue); color:var(--im-on-blue);
    font-size:calc(.79rem * var(--md-s)); font-weight:700; line-height:1; white-space:nowrap;
    transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.im-btn:hover { background:var(--im-blue-h); box-shadow:0 4px 16px var(--im-blue-glow); }
.im-btn:active { transform:scale(.98); }
.im-btn:disabled { opacity:.55; cursor:not-allowed; box-shadow:none; }
.im-btn i { font-size:calc(14px * var(--md-s)); }
.im-btn.ghost { background:rgba(var(--fg,255,255,255),.1); color:var(--text,#F0F2FF); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); box-shadow:none; }
.im-btn.ghost:hover { background:rgba(var(--fg,255,255,255),.2); }
.im-btn.sm { flex:0 0 auto; min-width:calc(68px * var(--md-s)); padding:0 calc(14px * var(--md-s)); font-size:calc(.74rem * var(--md-s)); }
.im-btn.sent { background:var(--im-blue-dim); color:var(--im-blue-l); border:1px solid rgba(42,95,216,.45); box-shadow:none; opacity:1; cursor:default; }
@media (max-width:490px){ .im-btn{font-size:calc(.68rem * var(--md-s));padding:0 calc(10px * var(--md-s));gap:calc(4px * var(--md-s));} .im-btn.sm{font-size:calc(.66rem * var(--md-s));min-width:calc(60px * var(--md-s));} .im-btn i{font-size:calc(12px * var(--md-s));} }

.im-actionbar { flex-shrink:0; display:flex; gap:calc(8px * var(--md-s)); padding:calc(8px * var(--md-s)) var(--im-pad) calc(calc(10px * var(--md-s)) + env(safe-area-inset-bottom)); }

/* ── empty / error states ── */
.im-state { text-align:center; padding:calc(26px * var(--md-s)) calc(12px * var(--md-s)); color:var(--im-mut); font-size:calc(.72rem * var(--md-s)); line-height:1.55; }
.im-state i { display:block; font-size:calc(28px * var(--md-s)); margin-bottom:calc(8px * var(--md-s)); color:rgba(126,166,255,.45); }
.im-state b { display:block; color:var(--text,#F0F2FF); font-size:calc(.78rem * var(--md-s)); font-weight:600; margin-bottom:calc(3px * var(--md-s)); }
.im-state.err i, .im-state.err b { color:#ff7a7a; }
.im-state .im-btn { margin:calc(12px * var(--md-s)) auto 0; flex:none; }

/* ── premium skeleton ── */
.im-sk-row { display:flex; align-items:center; gap:calc(11px * var(--md-s)); padding:calc(9px * var(--md-s)) 2px; border-bottom:1px solid var(--im-line); }
.im-sk-row:last-child { border-bottom:none; }
.im-sk { position:relative; overflow:hidden; background:rgba(var(--fg,255,255,255),.07); border-radius:var(--im-r); }
.im-sk::after { content:""; position:absolute; inset:0; transform:translateX(-100%); background:linear-gradient(90deg,transparent,rgba(126,166,255,.16),transparent); animation:imShimmer 1.3s ease-in-out infinite; }
@keyframes imShimmer { to { transform:translateX(100%); } }
.im-sk.av { width:calc(clamp(34px,9vw,42px) * var(--md-s)); height:calc(clamp(34px,9vw,42px) * var(--md-s)); border-radius:50%; flex-shrink:0; }
.im-sk-lines { flex:1; min-width:0; display:flex; flex-direction:column; gap:calc(6px * var(--md-s)); }
.im-sk.l1 { height:calc(10px * var(--md-s)); width:55%; }
.im-sk.l2 { height:calc(8px * var(--md-s)); width:34%; }
.im-sk.bt { width:calc(68px * var(--md-s)); height:var(--im-act-h); flex-shrink:0; }

@media (prefers-reduced-motion:reduce){
    #inviteModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; }
    .im-sk::after{ display:none; }
}
</style>

<script>/* lazy start: this block's code loads the first time it is used */
window.dzLazyJs=window.dzLazyJs||function(id,names,eager){var done=false;function run(){if(done)return;done=true;var t=document.getElementById(id);if(!t)return;var f=t.content.cloneNode(true),ss=[].slice.call(f.querySelectorAll('script'));ss.forEach(function(s){s.parentNode.removeChild(s)});ss.forEach(function(o){var s=document.createElement('script');s.text=o.textContent;document.body.appendChild(s)})}names.forEach(function(n){var s=function(){run();var r=window[n];if(r&&r!==s)return r.apply(this,arguments)};s.__dzStub=1;window[n]=s});window['dzLazyRun_'+id]=run;if(eager){var go=function(){run();['pointerdown','keydown','touchstart'].forEach(function(e){document.removeEventListener(e,go,true)})};['pointerdown','keydown','touchstart'].forEach(function(e){document.addEventListener(e,go,{capture:true,passive:true})});var idle=function(){(window.requestIdleCallback||function(c){setTimeout(c,1200)})(run,{timeout:3000})};if(document.readyState==='complete')idle();else window.addEventListener('load',idle)}};
dzLazyJs('dzLzInvite',['closeInviteModal','openInviteModal','searchInviteUsers'],false);
</script>
<template id="dzLzInvite">
<script>
// ═══ INVITE MODAL ═══
var _inviteDebounce = null;
var _inviteReq = 0;
var _inviteSent = {};
var _inviteOpen = false;
var _inviteGroupId = <?= (int)$group_id ?>;

function imEl(id) { return document.getElementById(id); }

function escHtml(s) {
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function imSkeleton(n) {
    var row = '<div class="im-sk-row"><div class="im-sk av"></div><div class="im-sk-lines"><div class="im-sk l1"></div><div class="im-sk l2"></div></div><div class="im-sk bt"></div></div>';
    return new Array((n || 5) + 1).join(row);
}

function imState(icon, title, text, isErr) {
    return '<div class="im-state' + (isErr ? ' err' : '') + '"><i class="' + icon + '"></i><b>' + escHtml(title) + '</b>' + escHtml(text) + '</div>';
}

function imShowIdle() {
    imEl('imResultsLabel').textContent = 'People';
    imEl('inviteUserList').innerHTML = imState('ri-search-line', 'Search for a dezzmer', 'Type a username or nickname above to find a dezzmer to invite.');
}

function openInviteModal() {
    if (_inviteOpen) return;
    _inviteOpen = true;
    var modal = imEl('inviteModal');
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    imEl('imBody').scrollTop = 0;

    var input = imEl('inviteSearchInput');
    input.value = '';
    imEl('inviteUserList').innerHTML = imSkeleton(5);
    setTimeout(function () {
        if (_inviteOpen && !input.value.trim()) imShowIdle();
    }, 420);
    setTimeout(function () { input.focus(); }, 60);
}

function closeInviteModal() {
    if (!_inviteOpen) return;
    _inviteOpen = false;
    clearTimeout(_inviteDebounce);
    _inviteReq++;
    var m = imEl('inviteModal');
    m.style.animation = 'imSlideOut .22s ease forwards';
    setTimeout(function () {
        m.classList.remove('open');
        m.style.animation = '';
        document.body.style.overflow = '';
    }, 210);
}

function searchInviteUsers(q) {
    clearTimeout(_inviteDebounce);
    q = (q || '').trim();
    var list = imEl('inviteUserList');
    var myReq = ++_inviteReq;

    if (q.length < 1) { imShowIdle(); return; }

    imEl('imResultsLabel').textContent = 'Searching…';
    list.innerHTML = imSkeleton(5);

    _inviteDebounce = setTimeout(function () {
        fetch('invite_search.php?q=' + encodeURIComponent(q) + '&group_id=' + encodeURIComponent(_inviteGroupId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (users) {
                if (myReq !== _inviteReq) return; // a newer search replaced this one
                if (!Array.isArray(users)) {
                    console.error('invite_search error:', users);
                    imEl('imResultsLabel').textContent = 'People';
                    list.innerHTML = imState('ri-error-warning-line', 'Something went wrong', (users && users.error) ? users.error : 'Please try again.', true);
                    return;
                }
                renderInviteUsers(users);
            })
            .catch(function (err) {
                if (myReq !== _inviteReq) return;
                console.error('fetch error:', err);
                imEl('imResultsLabel').textContent = 'People';
                list.innerHTML = imState('ri-wifi-off-line', 'Could not load dezzmers', 'Check your connection and try searching again.', true);
            });
    }, 320);
}

function renderInviteUsers(users) {
    var list = imEl('inviteUserList');
    if (!users.length) {
        imEl('imResultsLabel').textContent = 'People';
        list.innerHTML = imState('ri-user-search-line', 'No dezzmers found', 'Try a different username or nickname.');
        return;
    }
    imEl('imResultsLabel').textContent = users.length + (users.length === 1 ? ' dezzmer found' : ' dezzmers found');

    list.innerHTML = users.map(function (u) {
        var pic = u.profile_pic || 'mee.jpg';
        var sent = !!_inviteSent[u.id];
        var id = parseInt(u.id, 10);
        return '<div class="im-row">' +
            '<img src="' + escHtml(pic) + '" alt="" onerror="this.onerror=null;this.src=\'mee.jpg\'">' +
            '<div class="im-row-info">' +
                '<div class="im-name">' + escHtml(u.username) + '</div>' +
                (u.nickname ? '<div class="im-nick">' + escHtml(u.nickname) + '</div>' : '') +
            '</div>' +
            '<button type="button" id="inv-btn-' + id + '" class="im-btn sm' + (sent ? ' sent' : '') + '"' +
                (sent ? ' disabled' : ' onclick="sendInvite(' + id + ')"') + '>' +
                (sent ? '<i class="ri-check-line"></i> Sent' : 'Invite') +
            '</button>' +
        '</div>';
    }).join('');
}

function imToken() {
    try { if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) return CSRF_TOKEN; } catch (e) {}
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
}

function imMarkSent(userId, btn, label) {
    _inviteSent[userId] = true;
    btn.disabled = true;
    btn.className = 'im-btn sm sent';
    btn.innerHTML = '<i class="ri-check-line"></i> ' + label;
}

function imNotify(msg) {
    if (typeof gpToast === 'function') { gpToast(msg); return; }
    var l = imEl('imResultsLabel');
    if (l) l.textContent = msg;
}

function sendInvite(userId) {
    var btn = imEl('inv-btn-' + userId);
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.textContent = '…';

    var token = imToken();
    fetch('send_invite.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest' },
        body: 'user_id=' + encodeURIComponent(userId) + '&group_id=' + encodeURIComponent(_inviteGroupId) + '&csrf_token=' + encodeURIComponent(token)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data && data.ok) {
            imMarkSent(userId, btn, 'Sent');
        } else if (data && data.already) {
            imMarkSent(userId, btn, 'Member');
        } else {
            btn.disabled = false;
            btn.textContent = 'Invite';
            imNotify((data && data.error) ? data.error : 'Could not send the invite. Try again.');
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Invite';
        imNotify('Could not send the invite. Check your connection.');
    });
}

// Close on Escape
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && _inviteOpen) closeInviteModal();
});
</script>
</template>


<!-- ═══════════════════════════════════════════════════════
     GROUP FEED POST REPORT MODAL
     Report Content / Report User / Report to Group Admins
     Original dark background · dark blue buttons & icons (black text)
     Centered lane · grey side lines · horizontal-line rows
     ═══════════════════════════════════════════════════════ -->
<style>
#gfPostReportOverlay,
#gfPostRptStep1Screen,
#gfPostRptStep2Screen {
    --rp-blue:#2a5fd8; --rp-blue-h:#234fb5; --rp-blue-l:#7ea6ff;
    --rp-blue-dim:rgba(42,95,216,.18); --rp-blue-glow:rgba(42,95,216,.34);
    --rp-on-blue:#000;
    --rp-card:rgba(var(--fg,255,255,255),.05);
    --rp-line:rgba(var(--fg,255,255,255),.09); --rp-line2:rgba(var(--fg,255,255,255),.14);
    --rp-sub:rgba(var(--fg,255,255,255),.62); --rp-mut:rgba(var(--fg,255,255,255),.45);
    --rp-pad:calc(clamp(9px,3vw,16px) * var(--md-s));
    --rp-act-h:calc(clamp(25px,7.2vw,30px) * var(--md-s));
    --rp-r:5px;
    --rp-fs:calc(.8rem * var(--md-s));
    --rp-tb-h:calc(54px * var(--md-s));

    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999999;
    background: linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924)));
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--rp-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);
}
@media (max-width:490px){
    #gfPostReportOverlay, #gfPostRptStep1Screen, #gfPostRptStep2Screen { --rp-fs:calc(.74rem * var(--md-s)); --rp-tb-h:calc(48px * var(--md-s)); }
}
@media (max-width:390px){
    #gfPostReportOverlay, #gfPostRptStep1Screen, #gfPostRptStep2Screen { --rp-fs:calc(.7rem * var(--md-s)); --rp-tb-h:calc(44px * var(--md-s)); }
}
#gfPostReportOverlay.gfrpt-open,
#gfPostRptStep1Screen.gfrpt-open,
#gfPostRptStep2Screen.gfrpt-open { opacity: 1; transform: translateY(0); }

#gfPostReportOverlay *, #gfPostReportOverlay *::before, #gfPostReportOverlay *::after,
#gfPostRptStep1Screen *, #gfPostRptStep1Screen *::before, #gfPostRptStep1Screen *::after,
#gfPostRptStep2Screen *, #gfPostRptStep2Screen *::before, #gfPostRptStep2Screen *::after { box-sizing: border-box; }

#gfPostReportOverlay button, #gfPostRptStep1Screen button, #gfPostRptStep2Screen button,
#gfPostRptStep1Screen textarea { font-family: 'Poppins', sans-serif; outline: none; }
#gfPostReportOverlay button, #gfPostRptStep1Screen button, #gfPostRptStep2Screen button {
    cursor: pointer; -webkit-tap-highlight-color: transparent;
}
/* icons keep the icon font */
#gfPostReportOverlay [class^="ri-"], #gfPostReportOverlay [class*=" ri-"],
#gfPostRptStep1Screen [class^="ri-"], #gfPostRptStep1Screen [class*=" ri-"],
#gfPostRptStep2Screen [class^="ri-"], #gfPostRptStep2Screen [class*=" ri-"],
#gfPostReportOverlay [class^="ri-"]::before, #gfPostReportOverlay [class*=" ri-"]::before,
#gfPostRptStep1Screen [class^="ri-"]::before, #gfPostRptStep1Screen [class*=" ri-"]::before,
#gfPostRptStep2Screen [class^="ri-"]::before, #gfPostRptStep2Screen [class*=" ri-"]::before {
    font-family: 'remixicon' !important; font-style: normal; font-weight: normal; line-height: 1;
}

/* ── the lane + two grey lines ── */
.gfrpt-lane {
    width: 100%; max-width: 580px; height: 100%;
    display: flex; flex-direction: column; min-height: 0;
}
@media (min-width: 801px) {
    .gfrpt-lane { border-left: 1px solid var(--rp-line); border-right: 1px solid var(--rp-line); }
}
@media (max-width: 800px) { .gfrpt-lane { max-width: 100%; } }

/* ── Top bar ── */
.gfrpt-topbar {
    flex-shrink: 0;
    display: flex; align-items: center; gap: calc(6px * var(--md-s));
    min-height: var(--rp-tb-h);
    padding: env(safe-area-inset-top) calc(10px * var(--md-s)) 0 var(--rp-pad);
    background: transparent;
}
.gfrpt-topbar-center { flex: 1; min-width: 0; display: flex; flex-direction: column; text-align: left; }
.gfrpt-topbar-title {
    font-size: calc(.92rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.gfrpt-tb-side { flex: 0 0 38px; display: flex; align-items: center; justify-content: flex-end; }
.gfrpt-close-btn {
    width: calc(34px * var(--md-s)); height: calc(34px * var(--md-s)); background: none; border: none; color: var(--text,#F0F2FF);
    display: flex; align-items: center; justify-content: center; opacity: .9;
    padding: 0; transition: 0.2s; flex-shrink: 0;
}
.gfrpt-close-btn:hover { color: var(--rp-blue-l); opacity: 1; }
.gfrpt-close-btn svg { width: calc(22px * var(--md-s)); height: calc(22px * var(--md-s)); display: block; }
/* sub screens: X on the LEFT, title after it */
.gfrpt-topbar.gfrpt-x-left { padding-left: calc(8px * var(--md-s)); gap: calc(8px * var(--md-s)); }
@media (max-width:490px){
    .gfrpt-topbar-title{ font-size:calc(.84rem * var(--md-s)); }
    .gfrpt-close-btn{ width:calc(30px * var(--md-s)); height:calc(30px * var(--md-s)); }
}
@media (max-width:390px){ .gfrpt-topbar-title{ font-size:calc(.8rem * var(--md-s)); } }

/* ═══════════════════════════════════════════════════════════
   SCROLL AREA + SCROLLBAR
   • default (phones / tablets / narrow windows): scrollbar does
     not exist at all — no track, no thumb, no gutter
   • desktop only (wide + mouse): slim dark-blue scrollbar
═══════════════════════════════════════════════════════════ */
.gfrpt-body {
    flex: 1; min-height: 0;
    overflow-y: auto; overflow-x: hidden; overscroll-behavior: contain; -webkit-overflow-scrolling: touch;
    padding: 0 0 calc(18px * var(--md-s));
}
/* base = fully hidden (still scrolls by touch / wheel) */
#gfPostReportOverlay, #gfPostRptStep1Screen, #gfPostRptStep2Screen,
#gfPostReportOverlay *, #gfPostRptStep1Screen *, #gfPostRptStep2Screen * {
    scrollbar-width: none;
    scrollbar-color: transparent transparent;
    -ms-overflow-style: none;
}
#gfPostReportOverlay ::-webkit-scrollbar,
#gfPostRptStep1Screen ::-webkit-scrollbar,
#gfPostRptStep2Screen ::-webkit-scrollbar {
    display: none; width: 0; height: 0; background: transparent;
}
/* desktop only: bring the dark scrollbar back */
@media (min-width: 801px) and (hover: hover) and (pointer: fine) {
    #gfPostReportOverlay .gfrpt-body,
    #gfPostRptStep1Screen .gfrpt-body {
        scrollbar-width: thin;
        scrollbar-color: rgba(42,95,216,.45) transparent;
    }
    #gfPostReportOverlay .gfrpt-body::-webkit-scrollbar,
    #gfPostRptStep1Screen .gfrpt-body::-webkit-scrollbar { display: block; width: calc(4px * var(--md-s)); }
    #gfPostReportOverlay .gfrpt-body::-webkit-scrollbar-track,
    #gfPostRptStep1Screen .gfrpt-body::-webkit-scrollbar-track { background: transparent; }
    #gfPostReportOverlay .gfrpt-body::-webkit-scrollbar-thumb,
    #gfPostRptStep1Screen .gfrpt-body::-webkit-scrollbar-thumb { background: rgba(42,95,216,.45); border-radius: calc(99px * var(--md-s)); }
}

.gfrpt-postid-wrap { padding:calc(12px * var(--md-s)) var(--rp-pad) 0; }
.gfrpt-postid-label { display:flex; align-items:center; gap:calc(6px * var(--md-s)); margin-bottom:calc(8px * var(--md-s)); font-size:calc(.64rem * var(--md-s)); font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--rp-sub,rgba(255,255,255,.7)); }
.gfrpt-postid-label i { font-size:calc(14px * var(--md-s)); color:#3B82F6; }
.gfrpt-postid-label span { font-weight:400; text-transform:none; letter-spacing:0; color:var(--rp-mut,rgba(255,255,255,.45)); }
.gfrpt-postid-input { width:100%; height:calc(30px * var(--md-s)); padding:0 calc(10px * var(--md-s)); border-radius:var(--rp-r,8px); border:1px solid var(--rp-line2,rgba(255,255,255,.14)); background:var(--rp-card,rgba(255,255,255,.05)); color:var(--text,#F0F2FF); font-size:calc(.76rem * var(--md-s)); transition:.2s; -webkit-appearance:none; appearance:none; text-transform:uppercase; box-sizing:border-box; }
.gfrpt-postid-input::placeholder { color:var(--rp-mut,rgba(255,255,255,.45)); text-transform:none; }
.gfrpt-postid-input:focus { border-color:rgba(42,95,216,.9); box-shadow:0 0 0 3px rgba(42,95,216,.2); outline:none; }
.gfrpt-postid-hint { font-size:calc(.62rem * var(--md-s)); color:var(--rp-mut,rgba(255,255,255,.45)); margin-top:calc(6px * var(--md-s)); line-height:1.5; }
@media (max-width:490px){ .gfrpt-postid-input{ font-size:calc(.7rem * var(--md-s)); height:calc(28px * var(--md-s)); } }

.gfrpt-section-label {
    display: flex; align-items: center; gap: calc(6px * var(--md-s));
    padding: calc(12px * var(--md-s)) var(--rp-pad) calc(10px * var(--md-s));
    font-size: calc(.64rem * var(--md-s)); font-weight: 600; letter-spacing: .6px;
    text-transform: uppercase; color: var(--rp-sub);
    border-top: 1px solid var(--rp-line);
    border-bottom: 1px solid var(--rp-line);
}

/* ── Category rows (horizontal lines, no bubbles) ── */
.gfrpt-cat-row {
    display: flex; align-items: center; gap: calc(12px * var(--md-s));
    margin: 0; padding: calc(13px * var(--md-s)) var(--rp-pad);
    background: transparent; border: none; border-bottom: 1px solid var(--rp-line);
    cursor: pointer; transition: background .2s; -webkit-tap-highlight-color: transparent;
}
.gfrpt-cat-row:hover { background: var(--rp-blue-dim); }
.gfrpt-cat-row:active { background: rgba(42,95,216,.26); }
.gfrpt-cat-icon {
    width: calc(24px * var(--md-s)); flex-shrink: 0; text-align: center;
    font-size: calc(20px * var(--md-s)); color: var(--rp-blue-l);
}
.gfrpt-cat-info { flex: 1; min-width: 0; }
.gfrpt-cat-label { font-size: calc(.82rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); }
.gfrpt-cat-sub { font-size: calc(.66rem * var(--md-s)); color: var(--rp-mut); margin-top: 2px; line-height: 1.45; }
.gfrpt-cat-chevron { font-size: calc(18px * var(--md-s)); color: var(--rp-mut); transition: transform .2s, color .2s; flex-shrink: 0; }
.gfrpt-cat-row:hover .gfrpt-cat-chevron { transform: translateX(3px); color: var(--rp-blue-l); }
@media (max-width:490px){
    .gfrpt-cat-row{ gap:calc(10px * var(--md-s)); padding:calc(12px * var(--md-s)) var(--rp-pad); }
    .gfrpt-cat-icon{ font-size:calc(18px * var(--md-s)); }
    .gfrpt-cat-label{ font-size:calc(.74rem * var(--md-s)); }
    .gfrpt-cat-sub{ font-size:calc(.62rem * var(--md-s)); }
}

/* ── Privacy note (after last option) ── */
.gfrpt-note {
    display: flex; gap: calc(10px * var(--md-s)); align-items: flex-start;
    padding: calc(16px * var(--md-s)) var(--rp-pad) calc(6px * var(--md-s));
}
.gfrpt-note-icon { font-size: calc(18px * var(--md-s)); color: var(--rp-blue-l); flex-shrink: 0; width: calc(24px * var(--md-s)); text-align: center; margin-top: 1px; }
.gfrpt-note-title { font-size: calc(.74rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); margin-bottom: calc(3px * var(--md-s)); }
.gfrpt-note-text { font-size: calc(.66rem * var(--md-s)); color: var(--rp-mut); line-height: 1.6; }
@media (max-width:490px){
    .gfrpt-note-title{ font-size:calc(.7rem * var(--md-s)); }
    .gfrpt-note-text{ font-size:calc(.62rem * var(--md-s)); }
}

/* ── Reason rows (horizontal lines, no bubbles) ── */
.gfrpt-reason-row {
    display: flex; align-items: center; gap: calc(12px * var(--md-s));
    margin: 0; padding: calc(13px * var(--md-s)) var(--rp-pad);
    background: transparent; border: none; border-bottom: 1px solid var(--rp-line);
    cursor: pointer; transition: background .2s; -webkit-tap-highlight-color: transparent;
}
.gfrpt-reason-row:hover { background: rgba(42,95,216,.1); }
.gfrpt-reason-row.selected { background: var(--rp-blue-dim); }
.gfrpt-reason-icon { font-size: calc(18px * var(--md-s)); color: var(--rp-blue-l); flex-shrink: 0; width: calc(24px * var(--md-s)); text-align: center; }
.gfrpt-reason-text { flex: 1; min-width: 0; font-size: calc(.8rem * var(--md-s)); font-weight: 500; color: rgba(var(--fg,255,255,255),.88); }
.gfrpt-reason-row.selected .gfrpt-reason-text { color: var(--text,#F0F2FF); font-weight: 600; }
.gfrpt-check {
    width: calc(20px * var(--md-s)); height: calc(20px * var(--md-s)); border-radius: calc(5px * var(--md-s));
    border: 1.5px solid rgba(var(--fg,255,255,255),.25);
    display: flex; align-items: center; justify-content: center;
    font-size: calc(12px * var(--md-s)); font-weight: 800; color: transparent; flex-shrink: 0; transition: .2s;
}
.gfrpt-reason-row.selected .gfrpt-check { background: var(--rp-blue); border-color: var(--rp-blue); color: var(--rp-on-blue); }
@media (max-width:490px){
    .gfrpt-reason-row{ gap:calc(10px * var(--md-s)); padding:calc(12px * var(--md-s)) var(--rp-pad); }
    .gfrpt-reason-icon{ font-size:calc(16px * var(--md-s)); width:calc(22px * var(--md-s)); }
    .gfrpt-reason-text{ font-size:calc(.72rem * var(--md-s)); }
    .gfrpt-check{ width:calc(18px * var(--md-s)); height:calc(18px * var(--md-s)); font-size:calc(11px * var(--md-s)); }
}

/* ── Other textarea ── */
.gfrpt-other-wrap { padding: calc(12px * var(--md-s)) var(--rp-pad) calc(6px * var(--md-s)); display: none; }
.gfrpt-other-textarea {
    width: 100%; min-height: calc(96px * var(--md-s)); padding: calc(9px * var(--md-s)) calc(11px * var(--md-s));
    border-radius: var(--rp-r); border: 1px solid var(--rp-line2);
    background: var(--rp-card); color: var(--text,#F0F2FF);
    font-size: calc(.82rem * var(--md-s)); line-height: 1.6; resize: none; transition: .2s;
    -webkit-appearance: none; appearance: none; overflow-y: auto;
}
.gfrpt-other-textarea::placeholder { color: var(--rp-mut); }
.gfrpt-other-textarea:focus { border-color: rgba(42,95,216,.9); box-shadow: 0 0 0 3px rgba(42,95,216,.2); }
.gfrpt-char-count { text-align: right; font-size: calc(.62rem * var(--md-s)); color: var(--rp-mut); margin-top: calc(5px * var(--md-s)); }
@media (max-width:490px){ .gfrpt-other-textarea{ font-size:calc(.76rem * var(--md-s)); } }

/* ── Buttons (dark blue, BLACK text) ── */
.gfrpt-footer {
    flex-shrink: 0; display: flex; gap: calc(8px * var(--md-s));
    padding: calc(8px * var(--md-s)) var(--rp-pad) calc(calc(10px * var(--md-s)) + env(safe-area-inset-bottom));
    border-top: 1px solid var(--rp-line);
}
.gfrpt-submit-btn, .gfrpt-cancel-btn, .gfrpt-done-btn {
    display: flex; align-items: center; justify-content: center; gap: calc(6px * var(--md-s));
    min-width: 0; height: var(--rp-act-h); padding: 0 calc(14px * var(--md-s));
    border: none; border-radius: var(--rp-r);
    font-size: calc(.79rem * var(--md-s)); font-weight: 700; line-height: 1; white-space: nowrap;
    transition: background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.gfrpt-submit-btn { flex: 1.4; background: var(--rp-blue); color: var(--rp-on-blue); order: 2; }
.gfrpt-submit-btn:hover { background: var(--rp-blue-h); box-shadow: 0 4px 16px var(--rp-blue-glow); }
.gfrpt-submit-btn:active, .gfrpt-done-btn:active { transform: scale(.98); }
.gfrpt-submit-btn:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; pointer-events: none; }
.gfrpt-cancel-btn {
    flex: 1; order: 1; background: rgba(var(--fg,255,255,255),.1); color: var(--text,#F0F2FF);
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
}
.gfrpt-cancel-btn:hover { background: rgba(var(--fg,255,255,255),.2); }
.gfrpt-done-btn { background: var(--rp-blue); color: var(--rp-on-blue); padding: 0 calc(40px * var(--md-s)); }
.gfrpt-done-btn:hover { background: var(--rp-blue-h); box-shadow: 0 4px 16px var(--rp-blue-glow); }
@media (max-width:490px){
    .gfrpt-submit-btn, .gfrpt-cancel-btn, .gfrpt-done-btn{ font-size:calc(.68rem * var(--md-s)); padding:0 calc(10px * var(--md-s)); }
    .gfrpt-done-btn{ padding:0 calc(32px * var(--md-s)); }
}

/* ── Success ── */
.gfrpt-success-body {
    flex: 1; min-height: 0;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: calc(30px * var(--md-s)) calc(24px * var(--md-s)) calc(60px * var(--md-s)); text-align: center;
}
.gfrpt-success-icon {
    width: calc(64px * var(--md-s)); height: calc(64px * var(--md-s)); border-radius: 50%;
    background: rgba(77,222,138,.12); border: 1px solid rgba(77,222,138,.4);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto calc(16px * var(--md-s)); font-size: calc(28px * var(--md-s)); color: #4dde8a;
}
.gfrpt-success-title { font-size: calc(.95rem * var(--md-s)); font-weight: 700; color: var(--text,#F0F2FF); margin-bottom: calc(8px * var(--md-s)); }
.gfrpt-success-desc { font-size: calc(.74rem * var(--md-s)); color: var(--rp-sub); line-height: 1.7; max-width: 290px; margin: 0 auto calc(22px * var(--md-s)); }
@media (max-width:490px){
    .gfrpt-success-icon{ width:calc(56px * var(--md-s)); height:calc(56px * var(--md-s)); font-size:calc(25px * var(--md-s)); }
    .gfrpt-success-title{ font-size:calc(.86rem * var(--md-s)); }
    .gfrpt-success-desc{ font-size:calc(.68rem * var(--md-s)); }
}

/* ── Toast ── */
.gfrpt-toast {
    position: fixed; bottom: calc(calc(30px * var(--md-s)) + env(safe-area-inset-bottom)); left: 50%;
    transform: translateX(-50%) translateY(10px);
    background: var(--surface-2,#0d1030); border: 1px solid rgba(42,95,216,.55);
    color: #fff; padding: calc(8px * var(--md-s)) calc(16px * var(--md-s)); border-radius: calc(5px * var(--md-s));
    font-size: calc(.72rem * var(--md-s)); font-family: 'Poppins', sans-serif; font-weight: 500;
    z-index: 9999999999999; pointer-events: none;
    box-shadow: 0 8px 28px rgba(0,0,0,.65);
    white-space: nowrap; max-width: 92vw; overflow: hidden; text-overflow: ellipsis; opacity: 0;
    transition: opacity .22s ease, transform .22s ease;
}
.gfrpt-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

@keyframes gfRptShakeAnim{
    0%,100%{transform:translateX(0);}
    20%{transform:translateX(-6px);}
    40%{transform:translateX(6px);}
    60%{transform:translateX(-4px);}
    80%{transform:translateX(4px);}
}
@media (prefers-reduced-motion:reduce){
    #gfPostReportOverlay, #gfPostRptStep1Screen, #gfPostRptStep2Screen { transition-duration:.01ms !important; }
}
</style>

<!-- ══ SCREEN 0: Category picker ══ -->
<div id="gfPostReportOverlay" role="dialog" aria-modal="true" aria-label="Report">
  <div class="gfrpt-lane">
    <div class="gfrpt-topbar">
        <div class="gfrpt-topbar-center">
            <div class="gfrpt-topbar-title">Report</div>
        </div>
        <div class="gfrpt-tb-side">
            <button type="button" class="gfrpt-close-btn" onclick="closeGfPostReportModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
    </div>

    <div class="gfrpt-body">
        <div class="gfrpt-section-label">Select what you want to report</div>

        <div class="gfrpt-cat-row" onclick="gfRptSelectCat('content')">
            <div class="gfrpt-cat-icon"><i class="ri-image-2-line"></i></div>
            <div class="gfrpt-cat-info">
                <div class="gfrpt-cat-label">Report Content</div>
                <div class="gfrpt-cat-sub">This post breaks the rules or is inappropriate</div>
            </div>
            <i class="ri-arrow-right-s-line gfrpt-cat-chevron"></i>
        </div>

        <div class="gfrpt-cat-row" onclick="gfRptSelectCat('user')">
            <div class="gfrpt-cat-icon"><i class="ri-user-forbid-line"></i></div>
            <div class="gfrpt-cat-info">
                <div class="gfrpt-cat-label">Report User</div>
                <div class="gfrpt-cat-sub">Fake accounts, spam, harassment &amp; more</div>
            </div>
            <i class="ri-arrow-right-s-line gfrpt-cat-chevron"></i>
        </div>

        <div class="gfrpt-cat-row" onclick="gfRptSelectCat('admins')">
            <div class="gfrpt-cat-icon"><i class="ri-shield-user-line"></i></div>
            <div class="gfrpt-cat-info">
                <div class="gfrpt-cat-label">Report to Group Admins</div>
                <div class="gfrpt-cat-sub">Flag this post for this pod's owner &amp; admins to review</div>
            </div>
            <i class="ri-arrow-right-s-line gfrpt-cat-chevron"></i>
        </div>

        <div class="gfrpt-note">
            <i class="ri-lock-2-line gfrpt-note-icon"></i>
            <div>
                <div class="gfrpt-note-title">Reports are private and anonymous</div>
                <div class="gfrpt-note-text">The person you report is never told who reported them. Every report is reviewed, and reports sent to group admins are only seen by this pod's owner and admins.</div>
            </div>
        </div>
    </div>
  </div>
</div>


<!-- ══ SCREEN 1: Reason picker ══ -->
<div id="gfPostRptStep1Screen" role="dialog" aria-modal="true" aria-label="Select a reason">
  <div class="gfrpt-lane">
    <div class="gfrpt-topbar gfrpt-x-left">
        <button type="button" class="gfrpt-close-btn" onclick="closeGfPostReportModal()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="gfrpt-topbar-center">
            <div class="gfrpt-topbar-title" id="gfRptTitle">Select a reason</div>
        </div>
    </div>

    <div class="gfrpt-body" id="gfRptScroll">
        <!-- Post ID — optional, only for post reports (same idea as the Pod ID on a pod report) -->
        <div class="gfrpt-postid-wrap" id="gfRptPostIdWrap" style="display:none;">
            <div class="gfrpt-postid-label">
                <i class="ri-fingerprint-line"></i>
                Post ID <span>&nbsp;— optional</span>
            </div>
            <input class="gfrpt-postid-input" type="text" id="gfRptPostIdInput" maxlength="24" autocomplete="off" autocapitalize="characters"
                   placeholder="e.g. TP-1A2B3C4D">
            <div class="gfrpt-postid-hint">Find this at the bottom of the post's ⋯ menu</div>
        </div>
        <div class="gfrpt-section-label" id="gfRptReasonLabel">Select a reason</div>
        <div id="gfRptReasonList"></div>

        <div class="gfrpt-other-wrap" id="gfRptOtherWrap">
            <textarea class="gfrpt-other-textarea" id="gfRptOtherInput"
                      placeholder="Please describe the issue in detail…"
                      maxlength="500"
                      oninput="gfRptCharCount(this)"></textarea>
            <div class="gfrpt-char-count" id="gfRptCharLabel">0 / 500</div>
        </div>
    </div>

    <div class="gfrpt-footer">
        <button type="button" class="gfrpt-cancel-btn" onclick="closeGfPostReportModal()">Cancel</button>
        <button type="button" class="gfrpt-submit-btn" id="gfRptSubmitBtn" onclick="gfRptSubmit()">Submit Report</button>
    </div>
  </div>
</div>


<!-- ══ SCREEN 2: Success ══ -->
<div id="gfPostRptStep2Screen" role="dialog" aria-modal="true" aria-label="Report submitted">
  <div class="gfrpt-lane">
    <div class="gfrpt-topbar gfrpt-x-left">
        <button type="button" class="gfrpt-close-btn" onclick="closeGfPostReportModal()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="gfrpt-topbar-center">
            <div class="gfrpt-topbar-title">Report Submitted</div>
        </div>
    </div>

    <div class="gfrpt-success-body">
        <div class="gfrpt-success-icon"><i class="ri-shield-check-line"></i></div>
        <div class="gfrpt-success-title" id="gfRptSuccessTitle">Report Submitted</div>
        <div class="gfrpt-success-desc" id="gfRptSuccessDesc">
            Thank you. Your report has been submitted for review.
        </div>
        <button type="button" class="gfrpt-done-btn" onclick="closeGfPostReportModal()">Done</button>
    </div>
  </div>
</div>

<div id="gfRptToastEl" class="gfrpt-toast"></div>

<script>/* lazy start: this block's code loads the first time it is used */
window.dzLazyJs=window.dzLazyJs||function(id,names,eager){var done=false;function run(){if(done)return;done=true;var t=document.getElementById(id);if(!t)return;var f=t.content.cloneNode(true),ss=[].slice.call(f.querySelectorAll('script'));ss.forEach(function(s){s.parentNode.removeChild(s)});ss.forEach(function(o){var s=document.createElement('script');s.text=o.textContent;document.body.appendChild(s)})}names.forEach(function(n){var s=function(){run();var r=window[n];if(r&&r!==s)return r.apply(this,arguments)};s.__dzStub=1;window[n]=s});window['dzLazyRun_'+id]=run;if(eager){var go=function(){run();['pointerdown','keydown','touchstart'].forEach(function(e){document.removeEventListener(e,go,true)})};['pointerdown','keydown','touchstart'].forEach(function(e){document.addEventListener(e,go,{capture:true,passive:true})});var idle=function(){(window.requestIdleCallback||function(c){setTimeout(c,1200)})(run,{timeout:3000})};if(document.readyState==='complete')idle();else window.addEventListener('load',idle)}};
dzLazyJs('dzLzGfRpt',['closeGfPostReportModal','gfRptCharCount','gfRptSelectCat','gfRptSubmit','openGfPostReportModal'],false);
</script>
<template id="dzLzGfRpt">
<script>
(function () {
    var _gState = {
        step: 0, category: null, reason: null,
        memeId: null, userId: null
    };

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
        { icon:'ri-question-line',        text:'Other' }
    ];
    var _userReasons = [
        { icon:'ri-ghost-line',           text:'Fake Account' },
        { icon:'ri-spy-line',             text:'Impersonation' },
        { icon:'ri-spam-2-line',          text:'Spam Activity' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment or Bullying' },
        { icon:'ri-prohibited-line',      text:'Hate Speech' },
        { icon:'ri-suitcase-line',        text:'Scam or Fraud' },
        { icon:'ri-scales-3-line',        text:'Illegal Activity' },
        { icon:'ri-question-line',        text:'Other' }
    ];
    var _adminReasons = [
        { icon:'ri-focus-3-line',         text:'Off-Topic / Doesn\'t Fit This Pod' },
        { icon:'ri-spam-2-line',          text:'Spam or Repeated Posting' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment Within the Pod' },
        { icon:'ri-prohibited-line',      text:'Hate Speech or Discrimination' },
        { icon:'ri-error-warning-line',   text:'Breaks This Pod\'s Guidelines' },
        { icon:'ri-user-forbid-line',     text:'Suspected Rule-Breaking Member' },
        { icon:'ri-question-line',        text:'Other' }
    ];

    function _openScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('gfrpt-open'); });
        });
    }
    function _closeScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('gfrpt-open');
        setTimeout(function () {
            if (!el.classList.contains('gfrpt-open')) el.style.display = 'none';
        }, 270);
    }
    function _closeAll() {
        ['gfPostReportOverlay','gfPostRptStep1Screen','gfPostRptStep2Screen'].forEach(_closeScreen);
        document.body.style.overflow = '';
        setTimeout(function () {
            _gState = { step:0, category:null, reason:null, memeId:null, userId:null };
            var ta = document.getElementById('gfRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('gfRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
            var pi = document.getElementById('gfRptPostIdInput');
            if (pi) pi.value = '';
        }, 280);
    }

    window.openGfPostReportModal = function (memeId, userId) {
        _gState.memeId = memeId;
        _gState.userId = userId;
        _gState.category = null;
        _gState.reason = null;
        _openScreen('gfPostReportOverlay');
    };

    window.closeGfPostReportModal = function () { _closeAll(); };

    window.gfRptSelectCat = function (cat) {
        _gState.category = cat;
        _buildReasonScreen(cat);
        _closeScreen('gfPostReportOverlay');
        setTimeout(function () { _openScreen('gfPostRptStep1Screen'); }, 80);
    };

    // still used by the Escape key (goes back one screen)
    window.gfRptBack = function () {
        _gState.reason = null;
        _closeScreen('gfPostRptStep1Screen');
        setTimeout(function () { _openScreen('gfPostReportOverlay'); }, 80);
    };

    function _buildReasonScreen(cat) {
        var map = { content: _contentReasons, user: _userReasons, admins: _adminReasons };
        var titles = {
            content: 'Why are you reporting this content?',
            user:    'Why are you reporting this user?',
            admins:  'What should the admins know?'
        };
        document.getElementById('gfRptTitle').textContent = titles[cat] || 'Select a reason';

        _gState.reason = null;
        document.getElementById('gfRptOtherWrap').style.display = 'none';
        var pw = document.getElementById('gfRptPostIdWrap');
        if (pw) pw.style.display = cat === 'content' ? 'block' : 'none';

        var list = document.getElementById('gfRptReasonList');
        list.innerHTML = '';
        (map[cat] || []).forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'gfrpt-reason-row';
            row.dataset.reason = r.text;
            row.innerHTML =
                '<i class="' + r.icon + ' gfrpt-reason-icon" aria-hidden="true"></i>' +
                '<span class="gfrpt-reason-text">' + _esc(r.text) + '</span>' +
                '<div class="gfrpt-check"></div>';
            row.addEventListener('click', function () { _selectReason(r.text); });
            list.appendChild(row);
        });

        var sc = document.getElementById('gfRptScroll');
        if (sc) sc.scrollTop = 0;
    }

    function _selectReason(reason) {
        _gState.reason = reason;
        document.querySelectorAll('#gfRptReasonList .gfrpt-reason-row').forEach(function (row) {
            var sel = row.dataset.reason === reason;
            row.classList.toggle('selected', sel);
            row.querySelector('.gfrpt-check').textContent = sel ? '✓' : '';
        });
        var ow = document.getElementById('gfRptOtherWrap');
        ow.style.display = reason === 'Other' ? 'block' : 'none';
        if (reason === 'Other') {
            var sc = document.getElementById('gfRptScroll');
            setTimeout(function () { if (sc) sc.scrollTo({ top: sc.scrollHeight, behavior: 'smooth' }); }, 60);
        } else {
            var ta = document.getElementById('gfRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('gfRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
        }
    }

    window.gfRptCharCount = function (el) {
        var cl = document.getElementById('gfRptCharLabel');
        if (cl) cl.textContent = el.value.length + ' / 500';
        el.style.borderColor = '';
    };

    window.gfRptSubmit = function () {
        if (!_gState.reason) {
            var list = document.getElementById('gfRptReasonList');
            list.style.animation = 'none'; void list.offsetWidth;
            list.style.animation = 'gfRptShakeAnim 0.35s ease';
            setTimeout(function () { list.style.animation = ''; }, 400);
            _showToast('Please select a reason first');
            return;
        }
        if (_gState.reason === 'Other') {
            var ta = document.getElementById('gfRptOtherInput');
            if (!ta.value.trim()) {
                ta.style.borderColor = 'rgba(42,95,216,0.9)';
                ta.focus();
                _showToast('Please describe the issue');
                return;
            }
        }

        var btn = document.getElementById('gfRptSubmitBtn');
        btn.disabled = true; btn.textContent = 'Submitting…';

        var desc = (document.getElementById('gfRptOtherInput').value || '').trim();
        var cat  = _gState.category;
        var successTitle = 'Report Submitted';
        var successDesc  = 'Thank you. Your report has been submitted for review.';
        var req;

        if (cat === 'content') {
            var fd1 = new FormData();
            fd1.append('reported_id', _gState.memeId);
            fd1.append('content_type', 'meme');
            fd1.append('reason', _gState.reason);
            var postHint = ((document.getElementById('gfRptPostIdInput') || {}).value || '').trim().toUpperCase();
            if (postHint){
                fd1.append('post_id_hint', postHint);
                /* also written into the description so admins see it even where report_content.php doesn't read post_id_hint */
                desc = ('Post ID: ' + postHint + (desc ? ' — ' + desc : '')).slice(0, 500);
            }
            if (desc) fd1.append('description', desc);
            successDesc = 'Thank you. This post has been submitted for review.';
            req = fetch('report_content.php', { method: 'POST', body: fd1 });

        } else if (cat === 'user') {
            var fd2 = new FormData();
            fd2.append('type', 'user');
            fd2.append('reported_id', _gState.userId);
            fd2.append('reason', _gState.reason);
            if (desc) fd2.append('description', desc);
            successDesc = 'Thank you. This user has been submitted for review. They will not know who reported them.';
            req = fetch('report_user.php', { method: 'POST', body: fd2 });

        } else { // admins
            var body = new URLSearchParams();
            body.append('report_to_admins', '1');
            body.append('meme_id', _gState.memeId);
            body.append('reported_user_id', _gState.userId);
            body.append('reason', _gState.reason);
            if (desc) body.append('description', desc);
            body.append('csrf_token', CSRF_TOKEN);
            successTitle = 'Sent to Admins';
            successDesc  = 'This post has been flagged for the owner and admins of this pod to review.';
            req = fetch('group.php?id=' + gfGroupId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
        }

        req.then(function (r) { return r.json(); })
           .then(function (d) {
                btn.disabled = false; btn.textContent = 'Submit Report';
                if (d.success || d.ok || d.msg === 'already_reported') {
                    document.getElementById('gfRptSuccessTitle').textContent = successTitle;
                    document.getElementById('gfRptSuccessDesc').textContent  = successDesc;
                    _closeScreen('gfPostRptStep1Screen');
                    setTimeout(function () { _openScreen('gfPostRptStep2Screen'); }, 80);
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
        var t = document.getElementById('gfRptToastEl');
        if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    function _esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var s2 = document.getElementById('gfPostRptStep2Screen');
        var s1 = document.getElementById('gfPostRptStep1Screen');
        var s0 = document.getElementById('gfPostReportOverlay');
        if (s2 && s2.classList.contains('gfrpt-open')) { _closeAll(); }
        else if (s1 && s1.classList.contains('gfrpt-open')) { window.gfRptBack(); }
        else if (s0 && s0.classList.contains('gfrpt-open')) { _closeAll(); }
    });
})();
</script>
</template>

<!-- ═══════════════════════════════════════════════════════
     REPORT POD MODAL
     Paste near the other modals in group.php (e.g. right before the
     "GROUP FEED POST REPORT MODAL" block). Keep it outside any
     conditional so it exists for everyone.
     ═══════════════════════════════════════════════════════ -->
<style>
#dzGroupReportOverlay,
#dzGrpRptStep2Screen {
    --rp-blue:#2a5fd8; --rp-blue-h:#234fb5; --rp-blue-l:#7ea6ff;
    --rp-blue-dim:rgba(42,95,216,.18); --rp-blue-glow:rgba(42,95,216,.34);
    --rp-on-blue:#000;
    --rp-card:rgba(var(--fg,255,255,255),.05);
    --rp-line:rgba(var(--fg,255,255,255),.09); --rp-line2:rgba(var(--fg,255,255,255),.14);
    --rp-sub:rgba(var(--fg,255,255,255),.62); --rp-mut:rgba(var(--fg,255,255,255),.45);
    --rp-pad:calc(clamp(9px,3vw,16px) * var(--md-s));
    --rp-act-h:calc(clamp(25px,7.2vw,30px) * var(--md-s));
    --rp-r:5px; --rp-fs:calc(.8rem * var(--md-s)); --rp-tb-h:calc(54px * var(--md-s));

    display:none; position:fixed; inset:0; z-index:99999999;
    background:linear-gradient(-45deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)),var(--bg-end,var(--lane,#070924))); background-attachment:fixed;
    justify-content:center; overflow:hidden;
    font-family:'Poppins',sans-serif; font-size:var(--rp-fs); color:#fff; -webkit-font-smoothing:antialiased;
    opacity:0; transform:translateY(18px);
    transition:opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);
}
@media (max-width:490px){ #dzGroupReportOverlay,#dzGrpRptStep2Screen{ --rp-fs:calc(.74rem * var(--md-s)); --rp-tb-h:calc(48px * var(--md-s)); } }
@media (max-width:390px){ #dzGroupReportOverlay,#dzGrpRptStep2Screen{ --rp-fs:calc(.7rem * var(--md-s)); --rp-tb-h:calc(44px * var(--md-s)); } }
#dzGroupReportOverlay.dzgrprpt-open,
#dzGrpRptStep2Screen.dzgrprpt-open { opacity:1; transform:translateY(0); }

#dzGroupReportOverlay *, #dzGroupReportOverlay *::before, #dzGroupReportOverlay *::after,
#dzGrpRptStep2Screen *, #dzGrpRptStep2Screen *::before, #dzGrpRptStep2Screen *::after { box-sizing:border-box; }
#dzGroupReportOverlay button, #dzGrpRptStep2Screen button, #dzGroupReportOverlay textarea { font-family:'Poppins',sans-serif; outline:none; }
#dzGroupReportOverlay button, #dzGrpRptStep2Screen button { cursor:pointer; -webkit-tap-highlight-color:transparent; }
#dzGroupReportOverlay [class^="ri-"], #dzGroupReportOverlay [class*=" ri-"],
#dzGrpRptStep2Screen [class^="ri-"], #dzGrpRptStep2Screen [class*=" ri-"],
#dzGroupReportOverlay [class^="ri-"]::before, #dzGroupReportOverlay [class*=" ri-"]::before,
#dzGrpRptStep2Screen [class^="ri-"]::before, #dzGrpRptStep2Screen [class*=" ri-"]::before {
    font-family:'remixicon' !important; font-style:normal; font-weight:normal; line-height:1;
}

/* ── the lane + two grey lines ── */
.dzgrprpt-lane { width:100%; max-width:580px; height:100%; display:flex; flex-direction:column; min-height:0; }
@media (min-width:801px){ .dzgrprpt-lane{ border-left:1px solid var(--rp-line); border-right:1px solid var(--rp-line); } }
@media (max-width:800px){ .dzgrprpt-lane{ max-width:100%; } }

/* ── Top bar ── */
.dzgrprpt-topbar { flex-shrink:0; display:flex; align-items:center; gap:calc(6px * var(--md-s)); min-height:var(--rp-tb-h); padding:env(safe-area-inset-top) calc(10px * var(--md-s)) 0 var(--rp-pad); background:transparent; }
.dzgrprpt-topbar-center { flex:1; min-width:0; display:flex; flex-direction:column; text-align:left; }
.dzgrprpt-topbar-title { font-size:calc(.92rem * var(--md-s)); font-weight:700; color:var(--text,#F0F2FF); line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dzgrprpt-tb-side { flex:0 0 38px; display:flex; align-items:center; justify-content:flex-end; }
.dzgrprpt-close-btn { width:calc(34px * var(--md-s)); height:calc(34px * var(--md-s)); background:none; border:none; color:var(--text,#F0F2FF); display:flex; align-items:center; justify-content:center; opacity:.9; padding:0; transition:.2s; flex-shrink:0; }
.dzgrprpt-close-btn:hover { color:var(--rp-blue-l); opacity:1; }
.dzgrprpt-close-btn svg { width:calc(22px * var(--md-s)); height:calc(22px * var(--md-s)); display:block; }
@media (max-width:490px){ .dzgrprpt-topbar-title{font-size:calc(.84rem * var(--md-s));} .dzgrprpt-close-btn{width:calc(30px * var(--md-s));height:calc(30px * var(--md-s));} }
@media (max-width:390px){ .dzgrprpt-topbar-title{font-size:calc(.8rem * var(--md-s));} }

/* ═══════════════════════════════════════════════════════════
   SCROLL AREA + SCROLLBAR
   • default (phones / tablets / narrow windows): scrollbar does
     not exist at all — no track, no thumb, no gutter
   • desktop only (wide + mouse): slim dark-blue scrollbar
═══════════════════════════════════════════════════════════ */
.dzgrprpt-body { flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; padding:0 0 calc(18px * var(--md-s)); }

/* base = fully hidden (still scrolls by touch / wheel) */
#dzGroupReportOverlay, #dzGrpRptStep2Screen,
#dzGroupReportOverlay *, #dzGrpRptStep2Screen * {
    scrollbar-width:none;
    scrollbar-color:transparent transparent;
    -ms-overflow-style:none;
}
#dzGroupReportOverlay ::-webkit-scrollbar,
#dzGrpRptStep2Screen ::-webkit-scrollbar {
    display:none; width:0; height:0; background:transparent;
}
/* desktop (wider than 800px): dark blue scrollbar visible */
@media (min-width:801px) {
    #dzGroupReportOverlay .dzgrprpt-body {
        scrollbar-width:thin;
        scrollbar-color:#2a5fd8 transparent;
    }
    #dzGroupReportOverlay .dzgrprpt-body::-webkit-scrollbar { display:block; width:calc(6px * var(--md-s)); }
    #dzGroupReportOverlay .dzgrprpt-body::-webkit-scrollbar-track { background:transparent; }
    #dzGroupReportOverlay .dzgrprpt-body::-webkit-scrollbar-thumb { background:#2a5fd8; border-radius:calc(99px * var(--md-s)); }
    #dzGroupReportOverlay .dzgrprpt-body::-webkit-scrollbar-thumb:hover { background:#234fb5; }
}

.dzgrprpt-section-label {
    display:flex; align-items:center; gap:calc(6px * var(--md-s));
    padding:calc(12px * var(--md-s)) var(--rp-pad) calc(10px * var(--md-s));
    font-size:calc(.64rem * var(--md-s)); font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--rp-sub);
    border-top:1px solid var(--rp-line); border-bottom:1px solid var(--rp-line);
}

/* ── Reason rows (horizontal lines, no bubbles) ── */
.dzgrprpt-reason-row {
    display:flex; align-items:center; gap:calc(12px * var(--md-s));
    margin:0; padding:calc(13px * var(--md-s)) var(--rp-pad);
    background:transparent; border:none; border-bottom:1px solid var(--rp-line);
    cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent;
}
.dzgrprpt-reason-row:hover { background:rgba(42,95,216,.1); }
.dzgrprpt-reason-row:active { background:rgba(42,95,216,.22); }
.dzgrprpt-reason-row.selected { background:var(--rp-blue-dim); }
.dzgrprpt-reason-icon { font-size:calc(18px * var(--md-s)); color:var(--rp-blue-l); flex-shrink:0; width:calc(24px * var(--md-s)); text-align:center; }
.dzgrprpt-reason-text { flex:1; min-width:0; font-size:calc(.8rem * var(--md-s)); font-weight:500; color:rgba(var(--fg,255,255,255),.88); }
.dzgrprpt-reason-row.selected .dzgrprpt-reason-text { color:var(--text,#F0F2FF); font-weight:600; }
.dzgrprpt-check { width:calc(20px * var(--md-s)); height:calc(20px * var(--md-s)); border-radius:calc(5px * var(--md-s)); border:1.5px solid rgba(var(--fg,255,255,255),.25); display:flex; align-items:center; justify-content:center; font-size:calc(12px * var(--md-s)); font-weight:800; color:transparent; flex-shrink:0; transition:.2s; }
.dzgrprpt-reason-row.selected .dzgrprpt-check { background:var(--rp-blue); border-color:var(--rp-blue); color:var(--rp-on-blue); }
@media (max-width:490px){
    .dzgrprpt-reason-row{gap:calc(10px * var(--md-s));padding:calc(12px * var(--md-s)) var(--rp-pad);}
    .dzgrprpt-reason-icon{font-size:calc(16px * var(--md-s));width:calc(22px * var(--md-s));}
    .dzgrprpt-reason-text{font-size:calc(.72rem * var(--md-s));}
    .dzgrprpt-check{width:calc(18px * var(--md-s));height:calc(18px * var(--md-s));font-size:calc(11px * var(--md-s));}
}

/* ── Other textarea ── */
.dzgrprpt-podid-wrap { padding:calc(12px * var(--md-s)) var(--rp-pad) calc(14px * var(--md-s)); }
.dzgrprpt-podid-label { display:flex; align-items:center; gap:calc(6px * var(--md-s)); margin-bottom:calc(8px * var(--md-s)); font-size:calc(.64rem * var(--md-s)); font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--rp-sub); }
.dzgrprpt-podid-label i { font-size:calc(14px * var(--md-s)); color:#3B82F6; }
.dzgrprpt-podid-label span { font-weight:400; text-transform:none; letter-spacing:0; color:var(--rp-mut); }
.dzgrprpt-podid-input { width:100%; height:calc(30px * var(--md-s)); padding:0 calc(10px * var(--md-s)); border-radius:var(--rp-r); border:1px solid var(--rp-line2); background:var(--rp-card); color:var(--text,#F0F2FF); font-size:calc(.76rem * var(--md-s)); transition:.2s; -webkit-appearance:none; appearance:none; text-transform:uppercase; }
.dzgrprpt-podid-input::placeholder { color:var(--rp-mut); text-transform:none; }
.dzgrprpt-podid-input:focus { border-color:rgba(255,45,45,.85); box-shadow:0 0 0 3px rgba(255,45,45,.18); outline:none; }
.dzgrprpt-podid-hint { font-size:calc(.62rem * var(--md-s)); color:var(--rp-mut); margin-top:calc(6px * var(--md-s)); line-height:1.5; }
@media (max-width:490px){ .dzgrprpt-podid-input{ font-size:calc(.7rem * var(--md-s)); height:calc(28px * var(--md-s)); } }
.dzgrprpt-other-wrap { padding:calc(12px * var(--md-s)) var(--rp-pad) calc(6px * var(--md-s)); display:none; }
.dzgrprpt-other-textarea {
    width:100%; min-height:calc(96px * var(--md-s)); padding:calc(9px * var(--md-s)) calc(11px * var(--md-s));
    border-radius:var(--rp-r); border:1px solid var(--rp-line2);
    background:var(--rp-card); color:var(--text,#F0F2FF);
    font-size:calc(.82rem * var(--md-s)); line-height:1.6; resize:none; transition:.2s;
    -webkit-appearance:none; appearance:none; overflow-y:auto;
}
.dzgrprpt-other-textarea::placeholder { color:var(--rp-mut); }
.dzgrprpt-other-textarea:focus { border-color:rgba(42,95,216,.9); box-shadow:0 0 0 3px rgba(42,95,216,.2); }
.dzgrprpt-char-count { text-align:right; font-size:calc(.62rem * var(--md-s)); color:var(--rp-mut); margin-top:calc(5px * var(--md-s)); }
@media (max-width:490px){ .dzgrprpt-other-textarea{ font-size:calc(.76rem * var(--md-s)); } }

/* ── Buttons (dark blue, BLACK text) ── */
.dzgrprpt-footer {
    flex-shrink:0; display:flex; gap:calc(8px * var(--md-s));
    padding:calc(8px * var(--md-s)) var(--rp-pad) calc(calc(10px * var(--md-s)) + env(safe-area-inset-bottom));
    border-top:1px solid var(--rp-line);
}
.dzgrprpt-submit-btn, .dzgrprpt-cancel-btn, .dzgrprpt-done-btn {
    display:flex; align-items:center; justify-content:center; gap:calc(6px * var(--md-s));
    min-width:0; height:var(--rp-act-h); padding:0 calc(14px * var(--md-s));
    border:none; border-radius:var(--rp-r);
    font-size:calc(.79rem * var(--md-s)); font-weight:700; line-height:1; white-space:nowrap;
    transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.dzgrprpt-submit-btn { flex:1.4; order:2; background:var(--rp-blue); color:var(--rp-on-blue); }
.dzgrprpt-submit-btn:hover, .dzgrprpt-done-btn:hover { background:var(--rp-blue-h); box-shadow:0 4px 16px var(--rp-blue-glow); }
.dzgrprpt-submit-btn:active, .dzgrprpt-done-btn:active { transform:scale(.98); }
.dzgrprpt-submit-btn:disabled { opacity:.5; cursor:not-allowed; box-shadow:none; pointer-events:none; }
.dzgrprpt-cancel-btn { flex:1; order:1; background:rgba(var(--fg,255,255,255),.1); color:var(--text,#F0F2FF); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
.dzgrprpt-cancel-btn:hover { background:rgba(var(--fg,255,255,255),.2); }
.dzgrprpt-done-btn { background:var(--rp-blue); color:var(--rp-on-blue); padding:0 calc(40px * var(--md-s)); }
@media (max-width:490px){
    .dzgrprpt-submit-btn,.dzgrprpt-cancel-btn,.dzgrprpt-done-btn{font-size:calc(.68rem * var(--md-s));padding:0 calc(10px * var(--md-s));}
    .dzgrprpt-done-btn{padding:0 calc(32px * var(--md-s));}
}

/* ── Success ── */
.dzgrprpt-success-body { flex:1; min-height:0; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:calc(30px * var(--md-s)) calc(24px * var(--md-s)) calc(60px * var(--md-s)); text-align:center; }
.dzgrprpt-success-icon { width:calc(64px * var(--md-s)); height:calc(64px * var(--md-s)); border-radius:50%; background:rgba(77,222,138,.12); border:1px solid rgba(77,222,138,.4); display:flex; align-items:center; justify-content:center; margin:0 auto calc(16px * var(--md-s)); font-size:calc(28px * var(--md-s)); color:#4dde8a; }
.dzgrprpt-success-title { font-size:calc(.95rem * var(--md-s)); font-weight:700; color:var(--text,#F0F2FF); margin-bottom:calc(8px * var(--md-s)); }
.dzgrprpt-success-desc { font-size:calc(.74rem * var(--md-s)); color:var(--rp-sub); line-height:1.7; max-width:290px; margin:0 auto calc(22px * var(--md-s)); }
@media (max-width:490px){
    .dzgrprpt-success-icon{ width:calc(56px * var(--md-s)); height:calc(56px * var(--md-s)); font-size:calc(25px * var(--md-s)); }
    .dzgrprpt-success-title{ font-size:calc(.86rem * var(--md-s)); }
    .dzgrprpt-success-desc{ font-size:calc(.68rem * var(--md-s)); }
}

/* ── Toast ── */
.dzgrprpt-toast { position:fixed; bottom:calc(calc(30px * var(--md-s)) + env(safe-area-inset-bottom)); left:50%; transform:translateX(-50%) translateY(10px); background:var(--surface-2,#0d1030); border:1px solid rgba(42,95,216,.55); color:#fff; padding:calc(8px * var(--md-s)) calc(16px * var(--md-s)); border-radius:calc(5px * var(--md-s)); font-size:calc(.72rem * var(--md-s)); font-family:'Poppins',sans-serif; font-weight:500; z-index:999999999; pointer-events:none; box-shadow:0 8px 28px rgba(0,0,0,.65); white-space:nowrap; max-width:92vw; overflow:hidden; text-overflow:ellipsis; opacity:0; transition:opacity .22s ease, transform .22s ease; }
.dzgrprpt-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }

@keyframes dzGrpRptShakeAnim { 0%,100%{transform:translateX(0);} 20%{transform:translateX(-6px);} 40%{transform:translateX(6px);} 60%{transform:translateX(-4px);} 80%{transform:translateX(4px);} }
@media (prefers-reduced-motion:reduce){ #dzGroupReportOverlay,#dzGrpRptStep2Screen{ transition-duration:.01ms !important; } }
</style>

<!-- ══ SCREEN 1: Reason picker ══ -->
<div id="dzGroupReportOverlay" role="dialog" aria-modal="true" aria-label="Report this pod">
  <div class="dzgrprpt-lane">
    <div class="dzgrprpt-topbar">
        <div class="dzgrprpt-topbar-center">
            <div class="dzgrprpt-topbar-title">Report Pod</div>
        </div>
        <div class="dzgrprpt-tb-side">
            <button type="button" class="dzgrprpt-close-btn" onclick="closeReportGroupModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
    </div>

    <div class="dzgrprpt-body" id="dzGrpRptScroll">
        <!-- Pod ID — optional, same idea as the User ID on a user report -->
        <div class="dzgrprpt-podid-wrap">
            <div class="dzgrprpt-podid-label">
                <i class="ri-fingerprint-line"></i>
                Pod ID <span>&nbsp;— optional</span>
            </div>
            <input class="dzgrprpt-podid-input" type="text" id="dzGrpRptPodIdInput" maxlength="24" autocomplete="off" autocapitalize="characters"
                   placeholder="e.g. PD-10E34AFAD">
            <div class="dzgrprpt-podid-hint">Find this on the pod's “About This Pod” page</div>
        </div>

        <div class="dzgrprpt-section-label">Select a reason</div>
        <div id="dzGrpRptReasonList"></div>

        <div class="dzgrprpt-other-wrap" id="dzGrpRptOtherWrap">
            <textarea class="dzgrprpt-other-textarea" id="dzGrpRptOtherInput"
                      placeholder="Please describe the issue in detail…" maxlength="500"
                      oninput="dzGrpRptCharCount(this)"></textarea>
            <div class="dzgrprpt-char-count" id="dzGrpRptCharLabel">0 / 500</div>
        </div>
    </div>

    <div class="dzgrprpt-footer">
        <button type="button" class="dzgrprpt-cancel-btn" onclick="closeReportGroupModal()">Cancel</button>
        <button type="button" class="dzgrprpt-submit-btn" id="dzGrpRptSubmitBtn" onclick="dzGrpRptSubmit()">Submit Report</button>
    </div>
  </div>
</div>

<!-- ══ SCREEN 2: Success ══ -->
<div id="dzGrpRptStep2Screen" role="dialog" aria-modal="true" aria-label="Report submitted">
  <div class="dzgrprpt-lane">
    <div class="dzgrprpt-topbar">
        <div class="dzgrprpt-topbar-center">
            <div class="dzgrprpt-topbar-title">Report Submitted</div>
        </div>
        <div class="dzgrprpt-tb-side">
            <button type="button" class="dzgrprpt-close-btn" onclick="closeReportGroupModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
        </div>
    </div>
    <div class="dzgrprpt-success-body">
        <div class="dzgrprpt-success-icon"><i class="ri-shield-check-line"></i></div>
        <div class="dzgrprpt-success-title">Report Submitted</div>
        <div class="dzgrprpt-success-desc">Thank you. Your report has been submitted for review. The Pod will not know who reported it.</div>
        <button type="button" class="dzgrprpt-done-btn" onclick="closeReportGroupModal()">Done</button>
    </div>
  </div>
</div>

<div id="dzGrpRptToastEl" class="dzgrprpt-toast"></div>

<script>/* lazy start: this block's code loads the first time it is used */
window.dzLazyJs=window.dzLazyJs||function(id,names,eager){var done=false;function run(){if(done)return;done=true;var t=document.getElementById(id);if(!t)return;var f=t.content.cloneNode(true),ss=[].slice.call(f.querySelectorAll('script'));ss.forEach(function(s){s.parentNode.removeChild(s)});ss.forEach(function(o){var s=document.createElement('script');s.text=o.textContent;document.body.appendChild(s)})}names.forEach(function(n){var s=function(){run();var r=window[n];if(r&&r!==s)return r.apply(this,arguments)};s.__dzStub=1;window[n]=s});window['dzLazyRun_'+id]=run;if(eager){var go=function(){run();['pointerdown','keydown','touchstart'].forEach(function(e){document.removeEventListener(e,go,true)})};['pointerdown','keydown','touchstart'].forEach(function(e){document.addEventListener(e,go,{capture:true,passive:true})});var idle=function(){(window.requestIdleCallback||function(c){setTimeout(c,1200)})(run,{timeout:3000})};if(document.readyState==='complete')idle();else window.addEventListener('load',idle)}};
dzLazyJs('dzLzGrRpt',['closeReportGroupModal','dzGrpRptCharCount','dzGrpRptSubmit','openReportGroupModal'],false);
</script>
<template id="dzLzGrRpt">
<script>
(function () {
    var _grState = { groupId: null, reason: null };

    var _podReasons = [
        { icon:'ri-prohibited-line',      text:'Hate Speech or Discrimination' },
        { icon:'ri-emotion-unhappy-line', text:'Harassment or Bullying' },
        { icon:'ri-spam-2-line',          text:'Spam or Scam Activity' },
        { icon:'ri-newspaper-line',       text:'Misinformation or Fake News' },
        { icon:'ri-knife-blood-line',     text:'Graphic Violence or Gore' },
        { icon:'ri-eye-2-line',           text:'Nudity or Sexual Content' },
        { icon:'ri-scales-3-line',        text:'Illegal or Dangerous Activity' },
        { icon:'ri-heart-pulse-line',     text:'Self-Harm or Suicide Content' },
        { icon:'ri-shield-user-line',     text:'Child Safety Concern' },
        { icon:'ri-spy-line',             text:'Impersonation or Fake Pod' },
        { icon:'ri-copyright-line',       text:'Copyright Infringement' },
        { icon:'ri-team-line',            text:'Coordinated Abuse' },
        { icon:'ri-question-line',        text:'Other' }
    ];

    function _openScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('dzgrprpt-open'); });
        });
    }
    function _closeScreen(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('dzgrprpt-open');
        setTimeout(function () {
            if (!el.classList.contains('dzgrprpt-open')) el.style.display = 'none';
        }, 270);
    }
    function _closeAll() {
        ['dzGroupReportOverlay','dzGrpRptStep2Screen'].forEach(_closeScreen);
        document.body.style.overflow = '';
        setTimeout(function () {
            _grState = { groupId: null, reason: null };
            var ta = document.getElementById('dzGrpRptOtherInput');
            if (ta) ta.value = '';
            var pi = document.getElementById('dzGrpRptPodIdInput');
            if (pi) pi.value = '';
            var cl = document.getElementById('dzGrpRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
            var ow = document.getElementById('dzGrpRptOtherWrap');
            if (ow) ow.style.display = 'none';
        }, 280);
    }

    /* ── public API (these are the names the buttons call) ── */
    window.openReportGroupModal = function (groupId) {
        _grState.groupId = groupId;
        _grState.reason  = null;
        _buildReasonScreen();
        _openScreen('dzGroupReportOverlay');
    };
    window.reportGroup = function () {
        if (_grState.groupId) window.openReportGroupModal(_grState.groupId);
    };
    window.closeReportGroupModal = function () { _closeAll(); };

    function _buildReasonScreen() {
        _grState.reason = null;
        document.getElementById('dzGrpRptOtherWrap').style.display = 'none';
        var list = document.getElementById('dzGrpRptReasonList');
        list.innerHTML = '';
        _podReasons.forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'dzgrprpt-reason-row';
            row.dataset.reason = r.text;
            row.innerHTML =
                '<i class="' + r.icon + ' dzgrprpt-reason-icon" aria-hidden="true"></i>' +
                '<span class="dzgrprpt-reason-text">' + _esc(r.text) + '</span>' +
                '<div class="dzgrprpt-check"></div>';
            row.addEventListener('click', function () { _selectReason(r.text); });
            list.appendChild(row);
        });
        var sc = document.getElementById('dzGrpRptScroll');
        if (sc) sc.scrollTop = 0;
    }

    function _selectReason(reason) {
        _grState.reason = reason;
        document.querySelectorAll('#dzGrpRptReasonList .dzgrprpt-reason-row').forEach(function (row) {
            var sel = row.dataset.reason === reason;
            row.classList.toggle('selected', sel);
            row.querySelector('.dzgrprpt-check').textContent = sel ? '✓' : '';
        });
        var ow = document.getElementById('dzGrpRptOtherWrap');
        ow.style.display = reason === 'Other' ? 'block' : 'none';
        if (reason === 'Other') {
            var sc = document.getElementById('dzGrpRptScroll');
            setTimeout(function () { if (sc) sc.scrollTo({ top: sc.scrollHeight, behavior: 'smooth' }); }, 60);
        } else {
            var ta = document.getElementById('dzGrpRptOtherInput');
            if (ta) ta.value = '';
            var cl = document.getElementById('dzGrpRptCharLabel');
            if (cl) cl.textContent = '0 / 500';
        }
    }

    window.dzGrpRptCharCount = function (el) {
        var cl = document.getElementById('dzGrpRptCharLabel');
        if (cl) cl.textContent = el.value.length + ' / 500';
        el.style.borderColor = '';
    };

    window.dzGrpRptSubmit = function () {
        if (!_grState.reason) {
            var list = document.getElementById('dzGrpRptReasonList');
            list.style.animation = 'none'; void list.offsetWidth;
            list.style.animation = 'dzGrpRptShakeAnim 0.35s ease';
            setTimeout(function () { list.style.animation = ''; }, 400);
            _showToast('Please select a reason first');
            return;
        }
        if (_grState.reason === 'Other') {
            var ta = document.getElementById('dzGrpRptOtherInput');
            if (!ta.value.trim()) {
                ta.style.borderColor = 'rgba(42,95,216,0.9)';
                ta.focus();
                _showToast('Please describe the issue');
                return;
            }
        }

        var btn = document.getElementById('dzGrpRptSubmitBtn');
        btn.disabled = true; btn.textContent = 'Submitting…';

        var fd = new FormData();
        fd.append('group_id', _grState.groupId);
        fd.append('reason', _grState.reason);
        fd.append('csrf_token', CSRF_TOKEN);
        var desc = (document.getElementById('dzGrpRptOtherInput').value || '').trim();
        var podHint = (document.getElementById('dzGrpRptPodIdInput').value || '').trim().toUpperCase();
        if (podHint) {
            fd.append('pod_id_hint', podHint);
            /* also written into the description so admins see it even where report_group.php doesn't read pod_id_hint */
            desc = ('Pod ID: ' + podHint + (desc ? ' — ' + desc : '')).slice(0, 500);
        }
        if (desc) fd.append('description', desc);

        fetch('report_group.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false; btn.textContent = 'Submit Report';
                if (d.success || d.msg === 'already_reported') {
                    _closeScreen('dzGroupReportOverlay');
                    setTimeout(function () { _openScreen('dzGrpRptStep2Screen'); }, 80);
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
        var t = document.getElementById('dzGrpRptToastEl');
        if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid);
        t._tid = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }
    function _esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var s2 = document.getElementById('dzGrpRptStep2Screen');
        var s1 = document.getElementById('dzGroupReportOverlay');
        if ((s2 && s2.classList.contains('dzgrprpt-open')) || (s1 && s1.classList.contains('dzgrprpt-open'))) _closeAll();
    });
})();
</script>
</template>

<?php /* Post menu (⋯) + Save-to-collection = the SHARED sheets (shared_modals.php). Report stays this page's own. */
$dz_sm_sections = ['save', 'index'];
include __DIR__ . '/shared_modals.php'; ?>
<?php /* Comments sheet (opened by the Zeems rail comment button) */ if (!function_exists('cs_api') && is_file(__DIR__ . '/comment_section.php')) { include __DIR__ . '/comment_section.php'; } ?>
<script>
/* ── Comment buttons in the post feeds (profile viewer + group feed): open the comment sheet + live comment count ── */
(function(){
    if (window.__dzCmCounts) return; window.__dzCmCounts = true;
    function fmt(n){ n = +n || 0; return n >= 1e6 ? (n/1e6).toFixed(1).replace('.0','')+'M' : n >= 1e3 ? (n/1e3).toFixed(1).replace('.0','')+'K' : String(n); }
    window.dzOpenComments = function(id){
        if (window.CommentSection && typeof window.CommentSection.open === 'function') window.CommentSection.open(id);
        else if (typeof showToast === 'function') showToast('Comments are not available here');
    };
    var busy = false, raf = 0;
    function fill(){
        raf = 0; if (busy || !(window.CommentSection && window.CommentSection.counts)) return;
        var els = document.querySelectorAll('[data-cm-count]:not([data-n])'); if (!els.length) return;
        var ids = [], seen = {};
        for (var i = 0; i < els.length; i++){ var id = els[i].getAttribute('data-cm-count'); if (id && !seen[id] && ids.length < 50){ seen[id] = 1; ids.push(id); } }
        ids.forEach(function(id){ var l = document.querySelectorAll('[data-cm-count="'+id+'"]'); for (var k = 0; k < l.length; k++) l[k].setAttribute('data-n', '0'); });
        busy = true;
        window.CommentSection.counts(ids).then(function(m){
            ids.forEach(function(id){ var n = (m && m[id]) || 0, l = document.querySelectorAll('[data-cm-count="'+id+'"]'); for (var k = 0; k < l.length; k++){ l[k].setAttribute('data-n', n); l[k].textContent = fmt(n); } });
        }).catch(function(){}).then(function(){ busy = false; if (document.querySelector('[data-cm-count]:not([data-n])')) schedule(); });
    }
    function schedule(){ if (!raf) raf = requestAnimationFrame(fill); }
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    schedule();
    /* a comment was added / deleted in the sheet → update the number */
    window.addEventListener('cs:changed', function(e){
        var d = e.detail || {}, l = document.querySelectorAll('[data-cm-count="'+d.meme+'"]');
        for (var k = 0; k < l.length; k++){ var n = Math.max(0, (parseInt(l[k].getAttribute('data-n'), 10) || 0) + (d.delta || 0)); l[k].setAttribute('data-n', n); l[k].textContent = fmt(n); }
    });
})();
</script>

<script>
/* Permission flags for the delete option — echoed from PHP */
var GF_IS_OWNER  = <?php echo $is_owner ? 'true' : 'false'; ?>;
var GF_IS_ADMIN  = <?php echo $is_admin ? 'true' : 'false'; ?>;
var GF_IS_MOD    = <?php echo !empty($is_mod) ? 'true' : 'false'; ?>;
var GF_STAFF_IDS = <?php echo json_encode(array_map('intval', $gf_staff_ids ?? [])); ?>;   /* owner + admins: a moderator can't remove their posts */
var GF_IS_MEMBER = <?php echo $is_member ? 'true' : 'false'; ?>;
/* NEW — mirrors the $gf_paused_locked pattern used elsewhere on this page:
   paused AND viewer is neither owner nor admin means gated features
   (Invite, Discussions nav link, and now this Discuss button) are locked. */
var GF_PAUSE_LOCKED = <?php echo ($group_is_paused && !($is_owner || $is_admin)) ? 'true' : 'false'; ?>;
var GF_CSRF      = CSRF_TOKEN; // already defined globally in group.php

/* the rows of this page's menu — handed to the shared sheet (window.dzIndexItems hook in shared_modals.php) */
window.dzIndexItems = function(sd, isOwner){
    var d = _gfdFindPostById(sd.id);
    if(!d) return null;
    var flags = { isOwnPost: String(d.userId) === String(gfCurrentUserId) };
    flags.canDelete = GF_IS_OWNER || GF_IS_ADMIN || flags.isOwnPost || (GF_IS_MOD && GF_STAFF_IDS.indexOf(parseInt(d.userId,10)) === -1);
    var items = [];
    var safeCaption = (d.caption || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");

    items.push('<div class="idx-sheet-item" onclick="gfCopyCaption(\''+safeCaption+'\');closeGfSheet();"><i class="ri-file-copy-line"></i> Copy Caption</div>');

    if(d.type !== 'text' && d.file){
        items.push('<a class="idx-sheet-item" href="'+d.file+'" download><i class="ri-download-2-line"></i> Download</a>');
    }

    items.push('<div class="idx-sheet-item" onclick="closeGfSheet();gfdOpenDiscuss('+d.id+');"><i class="ri-discuss-line"></i> Discuss</div>');
    items.push('<div class="idx-sheet-item" onclick="gfShowMemeQR('+d.id+');closeGfSheet();"><i class="ri-qr-code-line"></i> QR Code</div>');
    items.push('<a class="idx-sheet-item" href="account.php?user_id='+d.userId+'"><i class="ri-user-search-line"></i> About Account</a>');
    items.push('<div class="idx-sheet-item" onclick="gfShareMeme('+d.id+');closeGfSheet();"><i class="ri-share-line"></i> Share Post</div>');
    items.push('<div class="idx-sheet-item" onclick="closeGfSheet();openSaveModal('+d.id+');"><i class="ri-bookmark-line"></i> Add to Collection</div>');
    items.push('<div class="idx-sheet-item" onclick="gfHidePost('+d.id+');closeGfSheet();"><i class="ri-eye-off-line"></i> Hide Post</div>');

    if(flags.canDelete && d.groupPostId){
        items.push('<div class="idx-sheet-item" onclick="gfDeletePost('+d.groupPostId+','+d.id+')"><i class="ri-delete-bin-line"></i> Delete Post</div>');
    }

    if(!flags.isOwnPost){
        items.push('<div class="idx-sheet-item" onclick="closeGfSheet();gfBlockUser('+d.userId+')"><i class="ri-forbid-line"></i> Block User</div>');
    }

    
    items.push('<div class="idx-sheet-item report" onclick="closeGfSheet();openGfPostReportModal('+d.id+','+d.userId+')"><i class="ri-flag-line"></i> Report</div>');
    return items;
};

var currentUserId = gfCurrentUserId;
window.SVSH_ENDPOINT = window.SVSH_ENDPOINT || 'collections_api.php';
if(typeof window.kebabData === 'undefined') window.kebabData = {};
if(typeof window.showToast !== 'function') window.showToast = function(m){ if(typeof gfShowToast === 'function') gfShowToast(m); };

function openGfSheet(memeId){
    var d = _gfdFindPostById(memeId);
    if(!d) return;
    window.kebabData[d.id] = {
        id: d.id, caption: d.caption || '', type: d.type || 'image',
        fileName: (d.type === 'text') ? '' : String(d.file || '').replace(/^uploads\//, ''),
        uploadedBy: d.userId, remixOf: 0, isAd: !!d.isAd,
        posterUsername: d.username || '', posterPic: d.pic || 'mee.jpg'
    };
    openIndexSheet(d.id);
}
window.dzKebabResolve = function(id){
    var d = _gfdFindPostById(id); if(!d) return null;
    return { id: d.id, caption: d.caption || '', type: d.type || 'image',
        fileName: (d.type === 'text') ? '' : String(d.file || '').replace(/^uploads\//, ''),
        uploadedBy: d.userId, remixOf: 0, isAd: !!d.isAd, posterUsername: d.username || '', posterPic: d.pic || 'mee.jpg' };
};
function closeGfSheet(){ if(typeof closeIndexSheet === 'function') closeIndexSheet(); }

/* ── Universal post lookup — checks the vertical feed first, then
   whatever's currently sitting in the Connected-Zeem stack, then
   the raw fetch cache. Needed because a connected post's data never
   lives in peuraGroupFeed. ── */
function _gfdFindPostById(memeId){
    var id = String(memeId);
    var found = peuraGroupFeed.find(function(p){ return String(p.id) === id; });
    if(found) return found;
    for (var i = _gfdHStack.length - 1; i >= 0; i--) {
        if (String(_gfdHStack[i].item.id) === id) return _gfdHStack[i].item;
    }
    if (_gfdConnectedCache[id]) return _gfdConnectedCache[id];
    return null;
}


/* ── Sheet actions ── */
function gfCopyCaption(caption){
    navigator.clipboard.writeText(caption).then(function(){
        gfShowToast('Caption copied!');
    }).catch(function(){
        var el = document.createElement('textarea');
        el.value = caption;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        gfShowToast('Caption copied!');
    });
}

function gfShowMemeQR(memeId){
    var url = window.location.origin + window.location.pathname + '?id=' + gfGroupId + '&meme=' + memeId;
    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(url);
    var win = window.open('', '_blank', 'width=260,height=280');
    win.document.write('<body style="margin:0;background:#000;display:flex;align-items:center;justify-content:center;height:100vh;"><img src="'+qrUrl+'" style="border-radius:12px;"></body>');
}

function gfShareMeme(memeId){
    var url = window.location.origin + window.location.pathname + '?id=' + gfGroupId + '&meme=' + memeId;
    if(navigator.share){
        navigator.share({ title: 'Check this post', url: url });
    } else {
        navigator.clipboard.writeText(url);
        gfShowToast('Link copied to clipboard!');
    }
}

/* ── DISCUSS — carries this meme's meme_id over to discussions.php,
     which prefills the create-discussion modal with this post's media,
     caption, and a default (changeable) tag. Members-only; non-members
     get the same lock-toast used elsewhere for gated pod features.
     NEW: also blocked while the pod is paused (unless the viewer is
     owner/admin), matching Invite / Discussions-nav / Events / Files. ── */
function gfdOpenDiscuss(memeId){
    if (!GF_IS_MEMBER) {
        if (typeof showDiscLockToast === 'function') {
            showDiscLockToast('Join this pod to access Discussions');
        }
        return;
    }
    if (GF_PAUSE_LOCKED) {
        if (typeof showDiscLockToast === 'function') {
            showDiscLockToast('This pod is paused — check back later');
        }
        return;
    }
    window.location.href = 'discussions.php?pod_id=' + gfGroupId + '&prefill_meme_id=' + memeId;
}

function gfDeletePost(groupPostId, memeId){
    if(!confirm('Permanently delete this post?')) return;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = 'group.php?id=' + gfGroupId;

    [['delete_post','1'],['delete_post_id',groupPostId],['delete_meme_id',memeId],['csrf_token',GF_CSRF]].forEach(function(pair){
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = pair[0];
        inp.value = pair[1];
        f.appendChild(inp);
    });

    document.body.appendChild(f);
    f.submit();
}

/* ── HIDDEN POSTS — persisted per user+group in localStorage so
     they stay hidden across refreshes until Undo is clicked ── */
var GF_HIDDEN_KEY = 'gf_hidden_posts_group_<?php echo (int)$group_id; ?>_user_<?php echo (int)$current_user; ?>';
var _gfHiddenState = {}; // memeId -> { unit, originalHTML }

function gfLoadHiddenIds() {
    try {
        var raw = localStorage.getItem(GF_HIDDEN_KEY);
        var arr = raw ? JSON.parse(raw) : [];
        return Array.isArray(arr) ? arr : [];
    } catch (e) { return []; }
}

function gfSaveHiddenIds(arr) {
    try { localStorage.setItem(GF_HIDDEN_KEY, JSON.stringify(arr)); }
    catch (e) { /* storage unavailable — fail silently */ }
}

function gfAddHiddenId(memeId) {
    var ids = gfLoadHiddenIds();
    if (ids.indexOf(String(memeId)) === -1) {
        ids.push(String(memeId));
        gfSaveHiddenIds(ids);
    }
}

function gfRemoveHiddenId(memeId) {
    var ids = gfLoadHiddenIds().filter(function (id) { return id !== String(memeId); });
    gfSaveHiddenIds(ids);
}

/* skipServerCall = true when re-applying on page load (already reported once) */
function gfHidePost(memeId, skipServerCall){
    var btn  = document.querySelector('.gf-menu-btn[data-id="'+memeId+'"]');
    var unit = btn ? btn.closest('.gf-card-unit') : null;
    if(!unit) return;

    if(_gfHiddenState[memeId]) return; // already hidden

    var originalHTML = unit.innerHTML;

    unit.innerHTML =
        '<div class="gf-hidden-placeholder" data-hidden-id="'+memeId+'">' +
            '<div class="gf-hidden-left">' +
                '<div class="gf-hidden-icon"><i class="ri-eye-off-line"></i></div>' +
                '<div class="gf-hidden-text">Post Hidden</div>' +
            '</div>' +
            '<button type="button" class="gf-undo-btn" onclick="gfUndoHidePost('+memeId+')">' +
                '<i class="ri-arrow-go-back-line"></i> Undo' +
            '</button>' +
        '</div>';

    _gfHiddenState[memeId] = { unit: unit, originalHTML: originalHTML };
    gfAddHiddenId(memeId);

    if (!skipServerCall) {
        fetch('home.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=not_interested&meme_id=' + memeId
        }).catch(function(){ /* best-effort, ignore failures */ });
    }
}

function gfUndoHidePost(memeId){
    var pending = _gfHiddenState[memeId];
    if(!pending) return;

    pending.unit.innerHTML = pending.originalHTML;
    delete _gfHiddenState[memeId];
    gfRemoveHiddenId(memeId);

    gfShowToast('Post restored');
}

/* ── Re-apply hidden state on page load, before the user does anything ── */
document.addEventListener('DOMContentLoaded', function () {
    var hiddenIds = gfLoadHiddenIds();
    hiddenIds.forEach(function (id) {
        gfHidePost(id, true); // true = don't re-fire the server call
    });
});

/* ── BLOCK USER — reuses the same 'blocks' table logic as home.php,
     then removes every card by that user from this group feed ── */
function gfBlockUser(userId){
    if(!confirm('Block this user? You won\'t see their content anymore.')) return;

    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=block_user&target_id=' + userId
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(data.status === 'ok'){
            gfShowToast('User blocked');
            document.querySelectorAll('.gf-track-btn[data-user="'+userId+'"]').forEach(function(btn){
                var unit = btn.closest('.gf-card-unit');
                if(unit){
                    unit.style.transition = 'opacity 0.35s, transform 0.35s';
                    unit.style.opacity    = '0';
                    unit.style.transform  = 'scale(0.96)';
                    setTimeout(function(){ unit.remove(); }, 350);
                }
            });
        } else {
            gfShowToast('Could not block user');
        }
    })
    .catch(function(){ gfShowToast('Network error — try again'); });
}

function gfShowToast(msg){
    var t = document.createElement('div');
    t.textContent = msg;
    Object.assign(t.style, {
        position:'fixed', bottom:'90px', left:'50%', transform:'translateX(-50%)',
        background:'rgba(20,20,25,.95)', border:'1px solid rgba(255,45,45,.45)',
        color:'#fff', padding:'8px 20px', borderRadius:'50px', fontSize:'12px',
        zIndex:'9999999', pointerEvents:'none', boxShadow:'0 8px 24px rgba(0,0,0,.6)',
        transition:'opacity .3s', whiteSpace:'nowrap'
    });
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.remove(); },300); }, 2200);
}
</script>

<!-- ================================================================
     GROUP VIEWER MODAL — now the shared zeems_modal.php (same file home / text feed / profile use).
     Everything below it in this block is group-only glue.
     ================================================================ -->
<script>
(function(){
    var GID = <?php echo (int)$group_id; ?>;
    var EP  = 'group.php?id=' + GID;
    window.CCM_ENDPOINT = EP;
    /* group.php's own pages reject a POST without the token; the shared modal's follow call has no token field, so add it here */
    if(!window._gfFetchTokenPatched){
        window._gfFetchTokenPatched = true;
        var _f = window.fetch;
        window.fetch = function(input, init){
            try{
                var url = (typeof input === 'string') ? input : (input && input.url) || '';
                if(init && init.method && String(init.method).toUpperCase() === 'POST' && typeof init.body === 'string' &&
                   url.indexOf('group.php') !== -1 && init.body.indexOf('csrf_token=') === -1){
                    init = Object.assign({}, init, { body: init.body + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN) });
                }
            }catch(e){}
            return _f.call(this, input, init);
        };
    }
    window.CCM_CONFIG = {
        endpoint: EP,
        shareEndpoint: 'home.php',                         /* sharing a post to people goes through home.php, as before */
        shareLink: function(id){ return window.location.origin + window.location.pathname + '?id=' + GID + '&meme=' + id; },
        actionBody: function(type, id){
            var k = { like:'ajax_like', repost:'ajax_repost', fav:'ajax_favorite' }[type];
            return k + '=1&meme_id=' + id + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN);
        },
        actionResult: function(type, data){
            var key = { like:'liked', repost:'reposted', fav:'favorited' }[type];
            if(!data || data.count === undefined) return { ok:false };
            return { ok:true, active:!!data[key], count:data.count };
        },
        connectedBody: function(id){
            return 'ajax_get_connected_meme=1&meme_id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN);
        },
        extraHtml: function(p){
            return '<div class="gfd-discuss-wrap" id="gfd-discuss-wrap">'+
                '<button type="button" class="gfd-discuss-btn" onclick="event.stopPropagation();gfdOpenDiscuss('+p.id+')">'+
                    '<span>Discuss</span>'+
                '</button>'+
            '</div>';
        },
        isHidden: function(p){ return _gfdIsHidden(p.id); },
        hiddenHtml: function(p){ return _gfdRenderHiddenSlideContent(p.id); }
    };
})();
</script>
<?php include __DIR__ . '/zeems_modal.php'; ?>

<style>
/* group page: the Discuss button + hidden-post overlay of the old group viewer, styled on top of the shared modal */
/* Discuss — sits in the top-right column, right under the number-count tag: no icon, the counter's colour and look, a little bigger, corners a little sharper */
.gfd-discuss-wrap{ position:static; margin:0; pointer-events:none; }
.gfd-discuss-btn{
    pointer-events:all; display:inline-flex; align-items:center; gap:0;
    background:rgba(22,22,24,.82); border:none; backdrop-filter:none; -webkit-backdrop-filter:none;
    border-radius:4px; color:#fff; line-height:normal; cursor:pointer;
    font-family:'Poppins', sans-serif; letter-spacing:.3px; font-weight:800; white-space:nowrap;
    font-size:calc(clamp(8px, 7.51px + 0.3vw, 10px) * var(--gf-s, 1) * 1.092);
    padding:calc(clamp(3px, 2.51px + 0.3vw, 5px) * var(--gf-s, 1) * 1.092) calc(clamp(5.5px, 4.25px + 0.55vw, 9px) * var(--gf-s, 1) * 1.092);
    transition:background .2s ease, transform .15s ease; -webkit-tap-highlight-color:transparent;
}
.gfd-discuss-btn i{ display:none; }
.gfd-discuss-btn:hover{ background:rgba(22,22,24,.82); }
.gfd-discuss-btn:active{ transform:scale(.94); }
/* until it has been moved under the counter (a moment after the slide is drawn) it stays out of sight */
.ccm-slide > .gfd-discuss-wrap, #ccm-inner .gfd-discuss-wrap:not(.gfd-placed){ visibility:hidden; position:absolute; }
.ccm-top-right-tags .gfd-discuss-wrap.gfd-placed{ visibility:visible; position:static; }

/* the shared modal (zeems_modal.php) now sizes itself everywhere (avatars, rail, arrows, buttons, caption text, toasts — via --ccm-s / --ccm-t and its avatar-fit script).
   The group page must NOT set sizes on .ccm-* elements here — only group-specific extras (the Discuss button below). Change sizes in zeems_modal.php instead. */
#ccm-hstack{ pointer-events:none; }

/* ============================================================
   GFD HIDDEN OVERLAY — full stylesheet
   ============================================================ */

.gfd-hidden-overlay{
    position:absolute; inset:0; z-index:60;
    background:linear-gradient(-45deg, #010218, #070924, #070924);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-align:center; padding:30px;
    font-family:Poppins,sans-serif;
    overflow:hidden;
    animation:gfdHiddenFadeIn .35s ease;
}
@keyframes gfdHiddenFadeIn{
    from{ opacity:0; }
    to{ opacity:1; }
}

/* ── soft ambient glow drifting behind everything ── */
.gfd-hidden-overlay::before{
    content:'';
    position:absolute;
    width:280px; height:280px;
    border-radius:50%;
    background:radial-gradient(circle, rgba(255,45,45,.2) 0%, rgba(255,45,45,0) 70%);
    top:50%; left:50%;
    transform:translate(-50%,-55%);
    animation:gfdGlowDrift 6s ease-in-out infinite;
    pointer-events:none;
}
@keyframes gfdGlowDrift{
    0%,100%{ transform:translate(-50%,-55%) scale(1); opacity:.8; }
    50%{ transform:translate(-50%,-50%) scale(1.15); opacity:1; }
}

/* ── close button (top-left) ── */
.gfd-hidden-close{ position:absolute; top:16px; left:calc(clamp(4px, 3.55px + 0.35vw, 6px) * var(--gf-s)); animation:gfdHiddenSlideDown .4s ease .05s both; }

/* ── search button (top-right) — same as post's top search button, no bg/border ── */
.gfd-hidden-search-btn{ display:none !important; }   /* the fixed top bar's search button is always on screen now */
.gfd-hidden-search-btn{ position:absolute; top:10px; right:clamp(6px, 5.55px + 0.35vw, 10px); width:auto; height:auto; border-radius:0; background:none; border:none; box-shadow:none; display:flex; align-items:center; justify-content:center; color:#fff; padding:clamp(4px, 3.55px + 0.35vw, 6px); cursor:pointer; -webkit-tap-highlight-color:transparent; transition:transform .15s ease, opacity .15s ease; z-index:2; animation:gfdHiddenSlideDown .4s ease .05s both; }
.gfd-hidden-search-btn i{ font-size:clamp(19px, 16.65px + 1.33vw, 28px); filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); }
.gfd-hidden-search-btn .gfd-sico{ display:block; width:clamp(19px, 16.65px + 1.33vw, 28px); height:clamp(19px, 16.65px + 1.33vw, 28px); filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); }
.gfd-hidden-search-btn:active{ transform:scale(.88); opacity:.7; }

/* ── pill row — sits just under the back/search buttons ── */
.gfd-hidden-pill-row{ position:absolute; top:74px; left:0; right:0; display:flex; align-items:center; justify-content:center; flex-wrap:wrap; gap:clamp(6px, 4.55px + 0.63vw, 10px); padding:0 20px; z-index:2; animation:gfdHiddenRise .4s ease .1s both; }
.gfd-hidden-pill{ display:flex; align-items:center; gap:calc(clamp(4px, 3.09px + 0.7vw, 8px) * var(--gf-s)); flex-shrink:0; background:rgba(60,60,60,.4); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); border:none; color:#fff; font-family:Poppins,sans-serif; font-size:calc(clamp(11px, 10.55px + 0.35vw, 13px) * var(--gf-s)); font-weight:700; padding:10px 18px; border-radius:8px; cursor:pointer; -webkit-tap-highlight-color:transparent; transition:background .2s ease, transform .15s ease; }
.gfd-hidden-pill i{ font-size:calc(clamp(11px, 10.32px + 0.53vw, 14px) * var(--gf-s)); }
.gfd-hidden-pill:hover{ background:rgba(75,75,75,.55); }
.gfd-hidden-pill:active{ transform:scale(.96); }

/* ── central icon ── */
.gfd-hidden-icon{ width:calc(clamp(46px, 43.28px + 2.11vw, 64px) * var(--gf-s)); height:calc(clamp(46px, 43.28px + 2.11vw, 64px) * var(--gf-s)); border-radius:50%; background:rgba(255,45,45,.14); border:1.5px solid rgba(255,45,45,.4); display:flex; align-items:center; justify-content:center; font-size:27px; color:#ff2b2b; margin-bottom:calc(clamp(10px, 9.09px + 0.7vw, 16px) * var(--gf-s)); position:relative; animation:gfdHiddenPopIn .45s cubic-bezier(.34,1.56,.64,1) .1s both; }
/* pulsing ring around the icon */
.gfd-hidden-icon::after{
    content:'';
    position:absolute; inset:-8px;
    border-radius:50%;
    border:1.5px solid rgba(255,45,45,.4);
    animation:gfdIconPulse 2.2s ease-out infinite;
}
@keyframes gfdIconPulse{
    0%{ transform:scale(.85); opacity:.9; }
    100%{ transform:scale(1.35); opacity:0; }
}
@keyframes gfdHiddenPopIn{
    from{ opacity:0; transform:scale(.4) rotate(-15deg); }
    to{ opacity:1; transform:scale(1) rotate(0deg); }
}

/* ── text content ── */
.gfd-hidden-title{ font-size:calc(clamp(14px, 13.55px + 0.35vw, 17px) * var(--gf-s)); font-weight:700; color:#fff; margin-bottom:7px; letter-spacing:-.2px; animation:gfdHiddenRise .4s ease .18s both; }
.gfd-hidden-sub{ font-size:calc(clamp(11px, 10.77px + 0.18vw, 12.5px) * var(--gf-s)); color:rgba(255,255,255,.55); max-width:260px; line-height:1.55; margin-bottom:24px; animation:gfdHiddenRise .4s ease .25s both; }
@keyframes gfdHiddenRise{
    from{ opacity:0; transform:translateY(10px); }
    to{ opacity:1; transform:translateY(0); }
}
@keyframes gfdHiddenSlideDown{
    from{ opacity:0; transform:translateY(-8px); }
    to{ opacity:1; transform:translateY(0); }
}

/* ── undo button ── */
.gfd-hidden-undo{ display:inline-flex; align-items:center; gap:calc(clamp(4px, 3.55px + 0.35vw, 6px) * var(--gf-s)); background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.18); color:#fff; font-size:calc(clamp(11.5px, 11.27px + 0.18vw, 13px) * var(--gf-s)); font-weight:700; padding:calc(clamp(8px, 7.55px + 0.35vw, 11px) * var(--gf-s)) calc(clamp(18px, 17.09px + 0.7vw, 24px) * var(--gf-s)); border-radius:30px; cursor:pointer; -webkit-tap-highlight-color:transparent; transition:background .2s ease, border-color .2s ease, transform .15s ease, box-shadow .2s ease; position:relative; animation:gfdHiddenRise .4s ease .32s both; }
.gfd-hidden-undo:hover{
    background:rgba(255,45,45,.25);
    border-color:rgba(255,45,45,.55);
    box-shadow:0 4px 20px rgba(255,45,45,.3);
    transform:translateY(-1px);
}
.gfd-hidden-undo:active{ transform:scale(.96) translateY(0); }
.gfd-hidden-undo i{ font-size:calc(clamp(12px, 10.55px + 0.63vw, 15px) * var(--gf-s)); animation:gfdUndoSpinHint 2.5s ease-in-out infinite; }
@keyframes gfdUndoSpinHint{
    0%,80%,100%{ transform:rotate(0deg); }
    88%{ transform:rotate(-25deg); }
    94%{ transform:rotate(8deg); }
}

/* ============================================================
   Responsive breakpoints
   ============================================================ */
@media (max-width:790px){
    .gfd-hidden-overlay{ padding:24px; }
    .gfd-hidden-overlay::before{ width:230px; height:230px; }
    .gfd-hidden-icon{ font-size:24px; }
    
    .gfd-hidden-sub{ max-width:240px; margin-bottom:22px; }
    

    .gfd-hidden-search-btn{ top:13px; }
     
    .gfd-hidden-pill-row{ top:68px; }
}

@media (max-width:490px){
    .gfd-hidden-overlay{ padding:20px; }
    .gfd-hidden-overlay::before{ width:190px; height:190px; }
    .gfd-hidden-close{ top:12px; }
    .gfd-hidden-icon{ font-size:21px; }
    
    .gfd-hidden-sub{ max-width:220px; line-height:1.5; margin-bottom:20px; }
    
    

    .gfd-hidden-search-btn{ top:12px; }
     
    .gfd-hidden-pill-row{ top:60px; }
    .gfd-hidden-pill{ padding:8px 14px; }
    
}
</style>

<script>
/* ---- the names the rest of the group page already calls ---- */
(function(){
    function def(name, get, set){ try{ Object.defineProperty(window, name, { get:get, set:set || function(){}, configurable:true }); }catch(e){} }
    def('_gfmIndex',      function(){ return _ccmIndex; });
    def('_gfmMemeId',     function(){ return _ccmMemeId; });
    def('_gfmMediaIndex', function(){ return _ccmMediaIndex; });
    def('_gfdHStack',     function(){ return _ccmHStack; });
    def('_gfdConnectedCache', function(){ return _ccmConnectedCache; });
    window._gfdMediaIndexByPost = _ccmMediaIndexByPost;      /* same objects: the feed writes the start slide / track time, the modal reads them */
    window._gfdTrackPositions   = _ccmTrackPositions;
    window.gfdShowSavedToast = function(id){ return ccmShowSavedToast(id); };
    window.gfdHideSavedToast = function(){ return ccmHideSavedToast(); };

    window.openGFModal = function(memeId){
        openCardModal({
            id: memeId,
            feed: window.peuraGroupFeed || [],
            mediaIndex: _ccmMediaIndexByPost[memeId] || 0,
            startAudioTime: _ccmTrackPositions[memeId] || 0
        });
    };
    var realClose = closeCardCaptionModal;
    window.closeGFModal = function(){ realClose(); };
    /* back button, swipe-down and backdrop taps inside the shared modal call closeCardCaptionModal — send them through closeGFModal so the feed's hook still runs */
    window.closeCardCaptionModal = function(){ return window.closeGFModal.apply(this, arguments); };
    /* the rail "..." button opens the group's own post sheet (copy, discuss, hide, delete, report ...) */
    window.ccmMenuTap = function(postId){ if(typeof openGfSheet === 'function') openGfSheet(postId); };
    /* put every Discuss button under the number-count tag in the top-right column, as soon as a slide is drawn */
    function placeDiscuss(){
        document.querySelectorAll('#ccm-inner .gfd-discuss-wrap:not(.gfd-placed)').forEach(function(w){
            var slide = w.closest('.ccm-slide') || w.parentNode;
            var tags  = slide && slide.querySelector('.ccm-top-right-tags');
            if(tags){ tags.appendChild(w); w.classList.add('gfd-placed'); }
        });
    }
    var inner = document.getElementById('ccm-inner');
    if(inner && window.MutationObserver) new MutationObserver(placeDiscuss).observe(inner, { childList:true, subtree:true });
    placeDiscuss();
})();

function _gfdIsHidden(id){
    return id !== null && id !== undefined
        && typeof gfLoadHiddenIds === 'function'
        && gfLoadHiddenIds().indexOf(String(id)) !== -1;
}
function _gfdRenderHiddenSlideContent(memeId){
    return '<div class="gfd-hidden-overlay">'+
        '<div class="gfd-hidden-pill-row">'+
            '<button type="button" class="gfd-hidden-pill" onclick="event.stopPropagation();window.location.href=\'memepedia.php?group_id=\'+gfGroupId;">'+
                '<i class="ri-book-2-line"></i><span>Memepedia</span>'+
            '</button>'+
            '<button type="button" class="gfd-hidden-pill" onclick="event.stopPropagation();window.location.href=\'group_highlights.php?id=\'+gfGroupId;">'+
                '<i class="ri-star-line"></i><span>Highlights</span>'+
            '</button>'+
            '<button type="button" class="gfd-hidden-pill" onclick="event.stopPropagation();window.location.href=\'group_events.php?id=\'+gfGroupId;">'+
                '<i class="ri-calendar-event-line"></i><span>Events</span>'+
            '</button>'+
        '</div>'+
        '<div class="gfd-hidden-icon"><i class="ri-eye-off-line"></i></div>'+
        '<div class="gfd-hidden-title">Post Hidden</div>'+
        '<div class="gfd-hidden-sub">You won\'t see this post in your feed anymore.</div>'+
        '<button type="button" class="gfd-hidden-undo" onclick="event.stopPropagation();gfUndoHidePost('+memeId+')">'+
            '<i class="ri-arrow-go-back-line"></i> Undo'+
        '</button>'+
    '</div>';
}
/* a post was hidden / un-hidden while it is the open one: draw the slide again (it checks the hidden list itself) */
function gfdShowHiddenOverlay(memeId){
    if(String(_ccmMemeId) !== String(memeId)) return;
    document.querySelectorAll('#ccm-slide video').forEach(function(v){ try{ v.pause(); }catch(e){} });
    if(typeof _ccmMountTrack === 'function') _ccmMountTrack(_ccmIndex);
}
function gfdHideHiddenOverlay(memeId){
    if(String(_ccmMemeId) !== String(memeId)) return;
    if(typeof _ccmMountTrack === 'function') _ccmMountTrack(_ccmIndex);
}
function _gfdPatchHideFns(){
    if (typeof window.gfHidePost === 'function' && !window.gfHidePost._gfdPatched) {
        var _origHide = window.gfHidePost;
        window.gfHidePost = function(memeId, skipServerCall){
            _origHide(memeId, skipServerCall);
            gfdShowHiddenOverlay(memeId);
        };
        window.gfHidePost._gfdPatched = true;
    }
    if (typeof window.gfUndoHidePost === 'function' && !window.gfUndoHidePost._gfdPatched) {
        var _origUndo = window.gfUndoHidePost;
        window.gfUndoHidePost = function(memeId){
            _origUndo(memeId);
            gfdHideHiddenOverlay(memeId);
        };
        window.gfUndoHidePost._gfdPatched = true;
    }
}
if (document.readyState === 'complete' || document.readyState === 'interactive') { _gfdPatchHideFns(); }
else { document.addEventListener('DOMContentLoaded', _gfdPatchHideFns); }
window.addEventListener('load', _gfdPatchHideFns);

/* after like / repost / save in the modal: mirror it on the group page's own post grid (the feed re-syncs itself when the modal closes) */
document.addEventListener('zeems:action', function(e){
    var d = e.detail || {};
    var sel = { like:'.gf-like', repost:'.gf-repost', fav:'.gf-fav' }[d.type];
    if(!sel) return;
    var b = document.querySelector(sel + '[data-id="' + d.memeId + '"]');
    if(b){
        b.classList.toggle('active', !!d.active);
        var s = b.querySelector('span');
        if(s && d.count !== null && d.count !== undefined) s.textContent = d.count;
    }
});
</script>

<?php /* ── Pod warning: shown to the pod owner on their pod; stays until they press "I understand" ── */
if (!empty($is_owner) && !empty($group_id) && is_file(__DIR__ . '/dz_warnings.php')) {
    require_once __DIR__ . '/dz_warnings.php';
    dz_warn_overlay(dz_warn_pending($conn, 0, (int)$group_id), 'pod');
} ?>