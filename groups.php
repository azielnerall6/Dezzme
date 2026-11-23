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
    padding: 20px;
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
    max-height: calc(100vh - 40px);
    max-height: calc(100dvh - 40px);
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 6px;
}

.group-pic-modal-close {
    position: absolute;
    top: 14px;
    right: 18px;
    z-index: 10000;
    background: none;
    border: none;
    padding: 0;
    color: #fff;
    font-size: 40px;
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
    padding: 20px;
    font-family: 'Poppins', sans-serif;
}
#groupOptionsOverlay.go-open { display: flex; }

/* ── desktop card ── */
.go-card {
    background: linear-gradient(-45deg,#010218,#010218,#010218);
    border: 1px solid rgba(255,255,255,0.09);
    border-radius: 6px; /* CHANGED: was 25px — now sharp but still slightly rounded */
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
    gap: 10px;
    padding: 14px 16px 11px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.go-group-avatar {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    object-fit: cover;
    border: 2px solid #1a6fd4;
    flex-shrink: 0;
}
.go-group-name {
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.go-group-members {
    font-size: 9.5px;
    color: rgba(255,255,255,0.38);
    margin-top: 1px;
}
.go-close-btn {
    margin-left: auto;
    background: none;
    border: none;
    color: rgba(255,255,255,0.45);
    font-size: 11px;
    font-family: 'Poppins', sans-serif;
    font-weight: 500;
    cursor: pointer;
    flex-shrink: 0;
    padding: 3px 6px;
    letter-spacing: 0.01em;
    transition: 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.go-close-btn:hover { color: #fff; }

/* ── option rows ── */
.go-option {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 11px 16px;
    cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    transition: background 0.15s;
    -webkit-tap-highlight-color: transparent;
    position: relative;
}
.go-option:last-child { border-bottom: none; }
.go-option:hover  { background: rgba(255,255,255,0.04); }
.go-option:active { background: rgba(255,255,255,0.08); }

.go-option-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}
.go-option-icon.blue    { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.red     { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.orange  { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.muted   { background: rgba(6,115,240,0.12); color: #4dabff; }
.go-option-icon.purple  { background: rgba(6,115,240,0.12); color: #4dabff; }

.go-option-text { flex: 1; min-width: 0; }
.go-option-title {
    font-size: 11.5px;
    font-weight: 600;
    color: rgba(255,255,255,0.88);
    line-height: 1.2;
}
.go-option-title.danger { color: #ff6b6b; }
.go-option-sub {
    font-size: 9.5px;
    color: rgba(255,255,255,0.32);
    margin-top: 2px;
    line-height: 1.35;
}
.go-option-arrow {
    color: rgba(255,255,255,0.2);
    font-size: 15px;
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
    width: 36px;
    height: 20px;
    cursor: pointer;
}
.go-toggle input { opacity: 0; width: 0; height: 0; }
.go-toggle-slider {
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0.12);
    border-radius: 20px;
    transition: 0.28s;
    border: 1px solid rgba(255,255,255,0.1);
}
.go-toggle-slider::before {
    content: '';
    position: absolute;
    top: 2px; left: 2px;
    width: 14px; height: 14px;
    background: #000; /* CHANGED: black circle (was white) */
    border-radius: 20px;
    transition: 0.28s;
}
.go-toggle input:checked + .go-toggle-slider { background: rgba(26,109,212,0.85); border-color: rgba(26,109,212,0.5); }
.go-toggle input:checked + .go-toggle-slider::before { transform: translateX(16px); background: #000; } /* CHANGED: stays black when on */

/* ── divider ── */
.go-divider {
    border: none;
    border-top: 1px solid rgba(255,255,255,0.07);
    margin: 3px 0;
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
        width: 30px; height: 3px;
        background: rgba(255,255,255,0.18);
        border-radius: 0;
        margin: 8px auto 0;
    }

    .go-header { padding: 11px 14px 9px; }
    .go-group-avatar { width: 32px; height: 32px; border-radius: 7px; }
    .go-group-name   { font-size: 11.5px; }
    .go-group-members{ font-size: 9px; }

    .go-option { padding: 10px 14px; gap: 10px; }
    .go-option-icon { width: 30px; height: 30px; border-radius: 7px; font-size: 13px; }
    .go-option-title { font-size: 11px; }
    .go-option-sub   { font-size: 9px; }
}

@media (max-width: 490px) {
    .go-header { padding: 12px 14px 10px; }
    .go-group-avatar { width: 34px; height: 34px; border-radius: 7px; }
    .go-group-name   { font-size: 12.5px; }
    .go-group-members{ font-size: 9.5px; }

    .go-option { padding: 11px 14px; gap: 10px; }
    .go-option-icon  { width: 31px; height: 31px; border-radius: 7px; font-size: 14px; }
    .go-option-title { font-size: 12px; }
    .go-option-sub   { font-size: 9.5px; }
    .go-option-arrow { font-size: 15px; }

    .go-toggle { width: 36px; height: 20px; }
    .go-toggle-slider::before { width: 14px; height: 14px; }
    .go-toggle input:checked + .go-toggle-slider::before { transform: translateX(16px); }
}

@media (max-width: 390px) {
    .go-header { padding: 11px 12px 9px; gap: 9px; }
    .go-group-avatar { width: 31px; height: 31px; border-radius: 7px; }
    .go-group-name   { font-size: 11.5px; }
    .go-group-members{ font-size: 9px; }
    .go-close-btn    { font-size: 11px; padding: 3px 6px; }

    .go-option { padding: 10px 12px; gap: 9px; }
    .go-option-icon  { width: 29px; height: 29px; border-radius: 6px; font-size: 13px; }
    .go-option-title { font-size: 11.5px; }
    .go-option-sub   { font-size: 9px; margin-top: 1px; }
    .go-option-arrow { font-size: 14px; }

    .go-toggle { width: 34px; height: 19px; }
    .go-toggle-slider::before { width: 13px; height: 13px; top: 2px; left: 2px; }
    .go-toggle input:checked + .go-toggle-slider::before { transform: translateX(15px); }

    .go-card::before { width: 28px; height: 3px; margin: 8px auto 0; }
}

@media (max-width: 330px) {
    .go-header { padding: 10px 11px 8px; gap: 8px; }
    .go-group-avatar { width: 27px; height: 27px; border-radius: 6px; border-width: 1.5px; }
    .go-group-name   { font-size: 10.5px; }
    .go-group-members{ font-size: 8.5px; }
    .go-close-btn    { font-size: 10px; padding: 3px 5px; }

    .go-option { padding: 9px 11px; gap: 8px; }
    .go-option-icon  { width: 27px; height: 27px; border-radius: 6px; font-size: 12px; }
    .go-option-title { font-size: 10.5px; }
    .go-option-sub   { font-size: 8.5px; }
    .go-option-arrow { font-size: 13px; }

    .go-toggle { width: 31px; height: 17px; }
    .go-toggle-slider::before { width: 11px; height: 11px; }
    .go-toggle input:checked + .go-toggle-slider::before { transform: translateX(14px); }

    .go-card::before { width: 25px; height: 2.5px; margin: 7px auto 0; }
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

<!-- ═══════════════════════════════════════════════════════════════
     POD HIGHLIGHTS VIEWER (profile-style stage + comments below the box)
     Needs: group_highlight_api.php + tables group_highlight_comments
            (with parent_id), group_highlight_likes,
            group_highlight_comment_likes, group_highlight_comment_dislikes
     ═══════════════════════════════════════════════════════════════ -->
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

              <div class="hl-zone hl-zone-prev" onclick="hlPrev()"></div>
              <div class="hl-zone hl-zone-next" onclick="hlNext()"></div>

              <!-- text on media, or the poll -->
              <div id="hlCapLayer" class="hl-cap-layer"></div>

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
                <div class="hl-bar" id="hlBar" onclick="hlGoComments(true)" role="button" tabindex="0">
                  <span class="hl-fake" id="hlBarTxt">Add a comment…</span>
                </div>
                <button type="button" class="hl-act hl-like" id="hlLikeBtn" onclick="hlLike()" aria-label="Like"><i class="ri-heart-3-fill"></i><span id="hlLikeCount"></span></button>
                <button type="button" class="hl-act" onclick="hlGoComments(false)" aria-label="Comments"><i class="ri-question-answer-fill"></i><span id="hlBarCount"></span></button>
              </div>

            </div><!-- /#hlFace -->
          </div><!-- /#hlCube -->
        </div><!-- /#hlScene -->

        <div id="hlLoader"><div class="hl-spin"></div></div>

        <div id="hlSheetOv" onclick="hlCloseSheet()"></div>
        <div id="hlSheet">
          <div class="hl-sheet-handle"></div>
          <div id="hlSheetList"></div>
          <button type="button" class="hl-sheet-cancel" onclick="hlCloseSheet()">Cancel</button>
        </div>
      </div><!-- /#hlStage -->

      <!-- ═════ COMMENTS (below the box, scrolls with the modal) ═════ -->
      <section id="hlComments" aria-label="Comments">
        <div class="hl-cm-top">
          <div class="hl-cm-head">
            <div class="hl-cm-title"><i class="ri-question-answer-fill"></i>Comments<span id="hlCmCount" class="hl-cm-pill"></span></div>
            <div class="hl-cm-sort">
              <button type="button" class="hl-cm-sortbtn" id="hlCmSortBtn"><span id="hlCmSortLbl">Top</span><i class="ri-arrow-down-s-line"></i></button>
              <div class="hl-cm-sortmenu" id="hlCmSortMenu">
                <button type="button" class="active" data-sort="top">Top comments</button>
                <button type="button" data-sort="new">Newest first</button>
                <button type="button" data-sort="old">Oldest first</button>
              </div>
            </div>
            <button type="button" class="hl-cm-hb" id="hlCmNav" onclick="hlCmNav()" aria-label="Go to comments / back to highlight"><i class="ri-arrow-down-s-line hl-nv-dn"></i><i class="ri-arrow-up-s-line hl-nv-up"></i></button>
            <button type="button" class="hl-cm-hb" onclick="hlClose()" aria-label="Close"><i class="ri-close-line"></i></button>
          </div>
        </div>
        <div class="hl-cm-note"><i class="ri-shield-check-line"></i><span>Remember to keep comments respectful.</span></div>
        <div id="hlCmList"></div>
        <div class="hl-cm-sent" id="hlCmSent"><div class="hl-cm-spin"></div></div>
        <div id="hlCmCompose" class="hl-cm-composer"></div>

        <div class="hl-cm-menu" id="hlCmMenu" role="menu">
          <button type="button" data-act="download" role="menuitem"><i class="ri-download-2-line"></i><span>Download comment</span></button>
          <button type="button" data-act="delete" class="danger" role="menuitem"><i class="ri-delete-bin-line"></i><span>Delete comment</span></button>
        </div>
      </section>

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
#hlScroll::-webkit-scrollbar{ width:5px; }
#hlScroll::-webkit-scrollbar-thumb{ background:rgba(36,89,184,.45); border-radius:99px; }
#hlWrap{
  --hl-stage-h:calc(100vh - 64px);
  position:relative; width:100%; max-width:480px; margin:0 auto;
  background:#070924;
  transform:scale(.985) translateY(6px); transition:transform .18s cubic-bezier(.25,.1,.25,1);
}
@supports (height:100dvh){ #hlWrap{ --hl-stage-h:calc(100dvh - 64px); } }
#hlModal.vis #hlWrap{ transform:none; }

#hlStage{
  position:relative; width:100%; height:var(--hl-stage-h);
  background:#000; overflow:hidden; touch-action:pan-y;
  -webkit-user-select:none; user-select:none;
}
@media(min-width:791px){
  #hlWrap{ --hl-stage-h:min(86vh,900px); margin:3vh auto; box-shadow:0 25px 90px rgba(0,0,0,.65),0 0 0 1px rgba(255,255,255,.07); border-radius:8px; }
  #hlStage{ border-radius:8px 8px 0 0; clip-path:inset(0 round 8px 8px 0 0); -webkit-clip-path:inset(0 round 8px 8px 0 0); }
}

/* ═══ PREV / NEXT HIGHLIGHT ARROWS (normal view only) — grey, grey on hover ═══ */
.hl-nav{
  position:absolute; top:calc(var(--hl-stage-h) / 2); transform:translateY(-50%); z-index:20;
  width:44px; height:44px; padding:0; border-radius:50%;
  border:1px solid rgba(255,255,255,.12); background:rgba(36,36,40,.92);
  -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);
  color:#fff; display:flex; align-items:center; justify-content:center; font-size:26px; cursor:pointer;
  box-shadow:0 6px 20px rgba(0,0,0,.5);
  transition:opacity .2s ease, background .2s ease, transform .15s ease;
  -webkit-tap-highlight-color:transparent;
}
.hl-nav i{ line-height:1; }
.hl-nav:hover{ background:rgba(66,66,72,.96); }
.hl-nav:active{ transform:translateY(-50%) scale(.92); background:rgba(80,80,86,.96); }
.hl-nav.off{ opacity:0; pointer-events:none; }
.hl-nav-prev{ left:-58px; }
.hl-nav-next{ right:-58px; }

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
.hl-shade-top{ top:0; height:150px; background:linear-gradient(to bottom,rgba(0,0,0,.7),transparent); }
.hl-shade-bottom{ bottom:0; height:160px; background:linear-gradient(to top,rgba(0,0,0,.75),transparent); }

.hl-zone{ position:absolute; top:100px; bottom:74px; z-index:3; -webkit-tap-highlight-color:transparent; cursor:pointer; }
.hl-zone-prev{ left:0; width:35%; }
.hl-zone-next{ right:0; width:65%; }

/* text on media + poll */
.hl-cap-layer{ position:absolute; inset:0; z-index:4; pointer-events:none; }
.hl-cap-wrap{ position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; padding:112px 18px 84px; text-align:center; min-height:0; pointer-events:none; }
.hl-cap{ pointer-events:auto; max-width:100%; min-height:0; padding:10px 13px; border-radius:12px; background:rgba(0,0,0,.34); color:#fff; font-size:15px; font-weight:600; line-height:1.5; text-shadow:0 1px 6px rgba(0,0,0,.85); word-break:break-word; overflow-wrap:anywhere; -webkit-user-select:text; user-select:text; }
.hl-cap.clamp{ display:-webkit-box; -webkit-line-clamp:4; -webkit-box-orient:vertical; overflow:hidden; }
.hl-cap:not(.clamp){ max-height:100%; overflow-y:auto; scrollbar-width:none; touch-action:pan-y; }
.hl-cap:not(.clamp)::-webkit-scrollbar{ display:none; }
.hl-more{ pointer-events:auto; flex-shrink:0; background:rgba(0,0,0,.5); border:1px solid rgba(255,255,255,.25); border-radius:99px; color:#fff; font-size:11px; font-weight:600; padding:3px 12px; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-more:hover{ background:rgba(0,0,0,.7); }
.hl-men{ font-weight:700; text-decoration:none; cursor:pointer; text-shadow:0 1px 5px rgba(0,0,0,.9); }
.hl-men.user{ color:#6ea2ff; }
.hl-men.group{ color:#37c5ff; }
.hl-men:hover{ text-decoration:underline; }

.hl-poll{ position:absolute; inset:0; display:flex; flex-direction:column; justify-content:center; gap:10px; padding:112px 20px 84px; pointer-events:none; overflow:hidden; }
.hl-ptag{ align-self:center; display:inline-flex; align-items:center; gap:5px; font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:rgba(255,255,255,.85); }
.hl-pq{ font-size:18px; font-weight:700; text-align:center; line-height:1.4; margin-bottom:4px; text-shadow:0 1px 6px rgba(0,0,0,.4); word-break:break-word; }
.hl-po{ pointer-events:auto; position:relative; overflow:hidden; width:100%; text-align:left; border:1.5px solid rgba(255,255,255,.28); background:rgba(255,255,255,.14); color:#fff; border-radius:14px; padding:12px 14px; font-size:14px; font-weight:600; display:flex; justify-content:space-between; align-items:center; gap:10px; cursor:pointer; transition:transform .12s; -webkit-tap-highlight-color:transparent; }
.hl-po:active{ transform:scale(.98); }
.hl-po.mine{ border-color:#fff; background:rgba(255,255,255,.26); }
.hl-po .bar{ position:absolute; left:0; top:0; bottom:0; background:rgba(255,255,255,.28); width:0; transition:width .45s ease; }
.hl-po .lb,.hl-po .pc{ position:relative; word-break:break-word; }
.hl-po .pc{ font-weight:700; flex-shrink:0; }
.hl-pm,.hl-ph{ text-align:center; font-size:11px; color:rgba(255,255,255,.85); }
.hl-ph{ color:rgba(255,255,255,.7); }

/* ═══ HEADER: pod pic, pod name • date, username underneath ═══ */
.hl-top{ position:absolute; top:0; left:0; right:0; z-index:5; padding:calc(env(safe-area-inset-top,0px) + 8px) 10px 0; pointer-events:none; }
.hl-top > *{ pointer-events:auto; }

.hl-dashes{ display:flex; gap:3px; }
.hl-dash{ flex:1 1 0; height:3px; border-radius:2px; background:rgba(255,255,255,.3); overflow:hidden; }
.hl-dash-fill{ height:100%; width:0; background:#2b63d1; border-radius:2px; }
.hl-dash.done .hl-dash-fill{ width:100%; }

.hl-head{ display:flex; align-items:flex-start; gap:9px; margin-top:9px; }
.hl-av-col{ display:flex; flex-direction:column; align-items:center; gap:5px; flex-shrink:0; }
.hl-avatar{ width:38px; height:38px; border-radius:10px; object-fit:cover; border:2.5px solid #2b63d1; display:block; background:#0d1030; }
.hl-songline{ margin-top:2px; max-width:calc(100% - 56px); color:rgba(255,255,255,.8); font-size:10.1px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-songline:empty{ display:none; }
.hl-x-col{ display:flex; flex-direction:column; align-items:center; gap:4px; flex-shrink:0; }
.hl-count{ background:rgba(22,22,24,.7); color:#fff; font-size:10px; font-weight:800; padding:2px 7px; border-radius:6px; }
.hl-head-info{ flex:1; min-width:0; padding-top:0; }
.hl-title-row{ display:flex; flex-direction:column; align-items:flex-start; gap:2px; min-width:0; max-width:100%; }
.hl-pod-row{ display:flex; align-items:center; gap:5px; min-width:0; max-width:100%; }
.hl-pod{ min-width:0; color:#fff; font-weight:700; font-size:13px; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-dot{ color:rgba(255,255,255,.45); font-size:11px; flex-shrink:0; }
.hl-date{ flex-shrink:0; color:rgba(255,255,255,.72); font-size:10.5px; white-space:nowrap; text-shadow:0 1px 4px rgba(0,0,0,.6); }
/* username + Owner/Admin tag: same line AND same text baseline */
.hl-who-row{ display:flex; flex-direction:row; flex-wrap:nowrap; align-items:baseline; gap:6px; min-width:0; max-width:100%; }
#hlModal .hl-who{ display:block; flex:0 1 auto; min-width:0; color:#a3a7b5; cursor:pointer; pointer-events:auto; font-weight:500; font-size:11.5px; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.hl-who:hover{ text-decoration:underline; }
.hl-who-row > #hlBadge{ display:inline-flex; align-items:baseline; flex:0 0 auto; line-height:1.3; }

/* owner / admin: just the word, in blue — no pill, no icon */
.hl-bdg{ display:inline-block; font-size:10.5px; font-weight:700; flex:0 0 auto; white-space:nowrap; letter-spacing:.2px; line-height:1.3; text-shadow:0 1px 4px rgba(0,0,0,.5); }
.hl-bdg.owner{ color:#6ea2ff; }
.hl-bdg.admin{ color:#4d86f0; }

.hl-icon-btn{ --hl-ic:21px; background:none; border:none; color:#fff; cursor:pointer; width:30px; height:30px; padding:0; display:flex; align-items:center; justify-content:center; -webkit-tap-highlight-color:transparent; }
.hl-icon-btn i{ font-size:var(--hl-ic); line-height:1; filter:drop-shadow(0 2px 5px rgba(0,0,0,.7)); }
.hl-icon-btn svg{ width:var(--hl-ic); height:var(--hl-ic); display:block; filter:drop-shadow(0 2px 5px rgba(0,0,0,.7)); }
.hl-icon-btn.hl-x i{ font-size:calc(var(--hl-ic) * 1.4); }
/* menu icon: a bit smaller than the X, but not tiny */
.hl-icon-btn.hl-menu svg{ width:calc(var(--hl-ic) * 1.02); height:calc(var(--hl-ic) * 1.02); }
.hl-icon-btn:active{ transform:scale(.88); opacity:.7; }

.hl-bottom{ position:absolute; left:0; right:0; bottom:0; z-index:7; display:flex; align-items:center; gap:12px; padding:10px 12px 12px; }
.hl-bar{ flex:1; min-width:0; display:flex; align-items:center; height:38px; padding:0 14px; border-radius:999px; background:rgba(255,255,255,.12); -webkit-backdrop-filter:blur(12px); backdrop-filter:blur(12px); cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent; }
.hl-bar:hover{ background:rgba(255,255,255,.18); }
.hl-fake{ flex:1; min-width:0; color:rgba(255,255,255,.6); font-size:12.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hl-act{ background:none; border:none; color:#fff; cursor:pointer; display:flex; align-items:center; gap:4px; font-size:12px; font-weight:800; -webkit-tap-highlight-color:transparent; text-shadow:0 1px 4px rgba(0,0,0,.7); padding:0; transition:color .2s; }
.hl-act i{ font-size:24px; filter:drop-shadow(0 2px 5px rgba(0,0,0,.6)); transition:transform .18s ease, color .2s; }
.hl-act:active i{ transform:scale(1.25); }
.hl-act.on, .hl-act.on span{ color:#4d86f0; }
.hl-act i.pop{ animation:hlPop .38s ease; }
@keyframes hlPop{ 0%{transform:scale(1)} 40%{transform:scale(1.5)} 100%{transform:scale(1)} }

/* loader (stage skeleton) */
#hlLoader{ position:absolute; inset:0; z-index:9; background:linear-gradient(90deg,#0b0c1c 25%,#14162e 50%,#0b0c1c 75%); background-size:200% 100%; animation:hlSh 1.3s linear infinite; display:none; align-items:center; justify-content:center; }
#hlLoader.show{ display:flex; }
@keyframes hlSh{ to{ background-position:-200% 0; } }
.hl-spin{ width:30px; height:30px; border-radius:50%; border:3px solid rgba(255,255,255,.15); border-top-color:#fff; animation:hlSpin .8s linear infinite; }
@keyframes hlSpin{ to{ transform:rotate(360deg); } }

/* menu sheet */
#hlSheetOv{ position:absolute; inset:0; z-index:10; background:rgba(0,0,0,.6); opacity:0; pointer-events:none; transition:opacity .25s ease; }
#hlSheetOv.show{ opacity:1; pointer-events:all; }
#hlSheet{ position:absolute; left:0; right:0; bottom:0; z-index:11; background:rgba(12,12,28,.98); border-top:1px solid rgba(255,255,255,.08); border-radius:12px 12px 0 0; padding:8px 0 12px; transform:translateY(100%); transition:transform .3s cubic-bezier(.32,.72,0,1); }
#hlSheet.show{ transform:translateY(0); }
.hl-sheet-handle{ width:32px; height:3px; border-radius:999px; background:rgba(255,255,255,.2); margin:0 auto 4px; }
.hl-sheet-item{ display:flex; align-items:center; gap:10px; padding:11px 16px; color:#fff; font-size:12.5px; font-weight:500; cursor:pointer; border-bottom:1px solid rgba(255,255,255,.06); -webkit-tap-highlight-color:transparent; }
.hl-sheet-item:last-child{ border-bottom:none; }
.hl-sheet-item:active{ background:rgba(255,255,255,.06); }
.hl-sheet-item i{ font-size:15px; color:#5b8fe6; width:18px; text-align:center; }
.hl-sheet-cancel{ display:block; width:calc(100% - 24px); margin:6px 12px 0; padding:9px; background:rgba(255,255,255,.06); border:none; border-radius:10px; color:rgba(255,255,255,.7); font-weight:600; font-size:12px; cursor:pointer; }
/* toast is fixed to the screen so you can see it from the comments too */
.hl-toast{ position:fixed; left:50%; bottom:96px; transform:translateX(-50%); z-index:30; background:rgba(20,20,25,.96); border:1px solid rgba(43,99,209,.55); color:#fff; padding:7px 18px; border-radius:50px; font-size:11.5px; white-space:nowrap; max-width:92%; overflow:hidden; text-overflow:ellipsis; pointer-events:none; transition:opacity .3s; }

/* ═════════ COMMENTS BELOW THE BOX — detailed, blue, responsive ═════════ */
#hlComments{
  --cm-bg:#070924;
  --cm-px:18px; --csav:34px; --csgap:11px; --cm-fs:13.5px; --cm-meta:11.5px; --thread:rgba(110,160,255,.28);
  position:relative; display:flex; flex-direction:column;
  background:#070924;
  /* fills the whole screen on responsive, so the top bar and the composer sit on the screen edges with nothing around them */
  min-height:100vh; min-height:100dvh;
}
@media(min-width:791px){ #hlComments{ min-height:min(62vh,560px); border-radius:0 0 8px 8px; } }

/* top bar: solid #070924, covers whatever scrolls under it, no line, no shadow */
.hl-cm-top{ position:sticky; top:0; z-index:7; background:#070924; border:none; box-shadow:none; }
.hl-cm-head{ display:flex; align-items:center; gap:6px; padding:calc(var(--cm-px) * .65) calc(var(--cm-px) * .6) calc(var(--cm-px) * .55) var(--cm-px); }
.hl-cm-title{ flex:1; min-width:0; display:flex; align-items:center; gap:8px; font-size:calc(var(--cm-fs) + 1px); font-weight:700; letter-spacing:-.1px; }
.hl-cm-title i{ color:#5b8fe6; font-size:1.15em; }
.hl-cm-pill{ padding:0; border:none; background:none; border-radius:0; color:rgba(255,255,255,.6); font-size:calc(var(--cm-fs) + 1px); font-weight:600; }
.hl-cm-pill:empty{ display:none; }
.hl-cm-sort{ position:relative; }
.hl-cm-sortbtn{ display:flex; align-items:center; gap:2px; padding:5px 9px; border:none; border-radius:8px; background:rgba(255,255,255,.07); color:rgba(255,255,255,.88); font-size:var(--cm-meta); font-weight:600; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-cm-sortbtn:hover{ background:rgba(255,255,255,.13); }
.hl-cm-sortmenu{ position:absolute; top:calc(100% + 6px); right:0; z-index:9; display:none; min-width:150px; background:#0c0e2a; border:1px solid rgba(255,255,255,.1); border-radius:12px; padding:6px; box-shadow:0 10px 30px rgba(0,0,0,.55); }
.hl-cm-sortmenu.show{ display:block; }
.hl-cm-sortmenu button{ display:block; width:100%; text-align:left; padding:8px 10px; border:none; background:none; color:#fff; font-size:12.5px; border-radius:8px; cursor:pointer; }
.hl-cm-sortmenu button:hover{ background:rgba(255,255,255,.07); }
.hl-cm-sortmenu button.active{ color:#6ea2ff; font-weight:600; }
.hl-cm-hb{ width:34px; height:34px; border:none; border-radius:50%; background:rgba(255,255,255,.07); color:#fff; font-size:20px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .2s,transform .15s; -webkit-tap-highlight-color:transparent; flex-shrink:0; }
.hl-cm-hb:hover{ background:rgba(255,255,255,.14); }
.hl-cm-hb:active{ transform:scale(.9); }
/* V (down) while you're on the post, chevron up once you're in the comments */
#hlComments:not(.cm-on) .hl-nv-up{ display:none; }
#hlComments.cm-on .hl-nv-dn{ display:none; }

.hl-cm-note{ display:flex; align-items:center; gap:8px; margin:12px var(--cm-px) 2px; padding:8px 12px; border-radius:12px; background:rgba(255,255,255,.045); border:1px solid rgba(255,255,255,.08); font-size:calc(var(--cm-meta) + .5px); line-height:1.3; color:rgba(255,255,255,.86); }
.hl-cm-note i{ font-size:1rem; line-height:1; color:#7fa6f0; flex-shrink:0; }

#hlCmList{ flex:1 0 auto; padding:2px var(--cm-px) 20px; -webkit-user-select:text; user-select:text; }

/* one comment (any depth) */
.hl-ci{ --csav:34px; --csgap:11px; padding:12px 0; }
.hl-ci.fresh{ animation:hlCmIn .3s ease both; }
@keyframes hlCmIn{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }
.hl-ci-main{ display:flex; gap:var(--csgap); }
.hl-ci-avcol{ display:flex; flex-direction:column; align-items:center; flex-shrink:0; width:var(--csav); }
.hl-ci-av{ width:var(--csav); height:var(--csav); border-radius:50%; overflow:hidden; display:block; flex-shrink:0; border:2px solid #2b63d1; background:#0d1030; }
.hl-ci-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-ci-line{ display:none; flex:1; width:2px; margin-top:6px; border-radius:2px; background:var(--thread); }
.hl-ci.has-thread > .hl-ci-main .hl-ci-line{ display:block; }
.hl-ci.has-thread > .hl-ci-main .hl-ci-body{ padding-bottom:12px; }
.hl-ci-body{ flex:1; min-width:0; }
/* username, Owner/Admin tag and time: one line, same baseline, never wraps */
.hl-ci-l1{ display:flex; flex-direction:row; flex-wrap:nowrap; align-items:baseline; gap:0 8px; min-width:0; }
.hl-ci-un{ display:block; flex:0 1 auto; min-width:0; font-size:calc(var(--cm-fs) - .5px); font-weight:600; line-height:1.4; color:rgba(255,255,255,.74); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.hl-ci-un:hover{ text-decoration:underline; }
.hl-ci-l1 > .hl-bdg{ flex:0 0 auto; line-height:1.4; }
.hl-ci-t{ font-size:var(--cm-meta); line-height:1.4; color:rgba(255,255,255,.4); flex:0 0 auto; white-space:nowrap; }
.hl-ci-t::before{ content:'·'; margin-right:6px; }
.hl-ci-more{ align-self:center; margin-left:auto; margin-right:-6px; width:28px; height:28px; flex-shrink:0; border:none; background:none; color:rgba(255,255,255,.45); font-size:1.15rem; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:color .2s,background .2s; -webkit-tap-highlight-color:transparent; }
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
.hl-ci-r2{ display:flex; align-items:center; gap:18px; margin-top:8px; }
.hl-ci-like,.hl-ci-dis,.hl-ci-reply{ display:flex; align-items:center; gap:6px; border:none; background:none; padding:0; color:rgba(255,255,255,.85); font-size:calc(var(--cm-meta) + .5px); font-weight:500; cursor:pointer; transition:color .2s,transform .12s; -webkit-tap-highlight-color:transparent; }
.hl-ci-like i,.hl-ci-dis i{ font-size:1.15rem; display:block; line-height:1; }
.hl-ci-like:active,.hl-ci-dis:active,.hl-ci-reply:active{ transform:scale(.9); }
.hl-ci-like.on,.hl-ci-dis.on{ color:#4d86f0; }
.hl-ci-like i.pop,.hl-ci-dis i.pop{ animation:hlPop .34s ease; }
.hl-ci-reply{ color:rgba(255,255,255,.5); }
.hl-ci-reply:hover{ color:#fff; }
.hl-crl{ position:relative; display:inline-flex; width:16px; height:16px; flex-shrink:0; margin-left:-2px; }
.hl-crl-av{ width:16px; height:16px; border-radius:50%; overflow:hidden; border:1.5px solid #2b63d1; background:#0d1030; display:block; }
.hl-crl-av img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-crl-b{ position:absolute; right:-5px; bottom:-4px; font-size:.68rem; line-height:1; color:#4d86f0; filter:drop-shadow(0 0 1.5px #070924) drop-shadow(0 0 1px #070924); }

/* "· 9 replies ›" toggle and the nested thread under it */
.hl-ci-tg{ position:relative; display:flex; align-items:center; gap:7px; height:30px; margin-left:calc(var(--csav) + var(--csgap)); border:none; background:none; padding:0; color:#fff; font-size:calc(var(--cm-meta) + .5px); font-weight:600; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.hl-ci-tg::before{ content:''; position:absolute; top:0; height:50%; box-sizing:border-box; left:calc(-1 * (var(--csav) / 2 + var(--csgap) + 1px)); width:calc(var(--csav) / 2 + var(--csgap) + 1px); border-left:2px solid var(--thread); border-bottom:2px solid var(--thread); border-bottom-left-radius:12px; }
.hl-ci-tgav{ width:22px; height:22px; border-radius:50%; overflow:hidden; flex-shrink:0; border:1.5px solid #2b63d1; background:#0d1030; display:block; }
.hl-ci-tgav img{ width:100%; height:100%; object-fit:cover; display:block; }
.hl-ci-tgd{ opacity:.7; }
.hl-ci-chev{ font-size:1.1rem; line-height:1; transition:transform .2s ease; }
.hl-ci-tg.open .hl-ci-chev{ transform:rotate(90deg); }
.hl-sub{ margin-left:calc(var(--csav) + var(--csgap)); margin-top:2px; }
.hl-sub .hl-ci{ --csav:28px; --csgap:9px; padding:10px 0; }
.hl-sub .hl-sub .hl-sub{ margin-left:0; }
.hl-ci-sm{ display:block; margin:2px 0 6px; padding:0; border:none; background:none; color:rgba(255,255,255,.7); font-size:calc(var(--cm-meta) + .2px); font-weight:600; cursor:pointer; }
.hl-ci-sm:hover{ color:#fff; }

.hl-cm-empty{ padding:46px 20px; text-align:center; color:rgba(255,255,255,.5); font-size:var(--cm-meta); line-height:1.6; }
.hl-cm-empty i{ width:58px; height:58px; border-radius:50%; background:rgba(43,99,209,.14); border:1px solid rgba(43,99,209,.35); display:flex; align-items:center; justify-content:center; margin:0 auto 12px; font-size:26px; color:#7fa6f0; }
.hl-cm-sent{ display:none; justify-content:center; padding:10px 0 18px; }
.hl-cm-sent.show{ display:flex; }
.hl-cm-spin{ width:22px; height:22px; border-radius:50%; border:3px solid rgba(255,255,255,.15); border-top-color:#6ea2ff; animation:hlSpin .8s linear infinite; }
.hl-sk{ background:linear-gradient(90deg,rgba(255,255,255,.05) 25%,rgba(255,255,255,.13) 50%,rgba(255,255,255,.05) 75%); background-size:200% 100%; animation:hlSh 1.3s linear infinite; border-radius:6px; }
.hl-ci-skel{ padding:12px 0; display:flex; gap:11px; }

/* the ⋯ menu */
.hl-cm-menu{ position:absolute; z-index:8; display:none; min-width:180px; background:#0c0e2a; border:1px solid rgba(255,255,255,.1); border-radius:12px; padding:6px; box-shadow:0 10px 30px rgba(0,0,0,.55); }
.hl-cm-menu.show{ display:block; }
.hl-cm-menu button{ display:flex; align-items:center; gap:10px; width:100%; text-align:left; padding:10px; border:none; background:none; color:#fff; font-size:12.5px; border-radius:8px; cursor:pointer; }
.hl-cm-menu button i{ font-size:1.1rem; opacity:.85; }
.hl-cm-menu button:hover{ background:rgba(255,255,255,.07); }
.hl-cm-menu button.danger{ color:#ff6b6b; }

/* composer pinned to the bottom: solid #070924, covers what scrolls under it, no line */
.hl-cm-composer{ position:sticky; bottom:0; z-index:6; background:#070924; border:none; box-shadow:none; padding:10px var(--cm-px) calc(10px + env(safe-area-inset-bottom,0px)); }
.hl-cm-composer:empty{ display:none; }
.hl-reply-chip{ display:none; align-items:center; gap:8px; margin:0 0 8px; padding:5px 6px 5px 12px; border-radius:8px; background:rgba(43,99,209,.14); border:1px solid rgba(43,99,209,.42); font-size:var(--cm-meta); color:rgba(255,255,255,.72); }
.hl-reply-chip.show{ display:flex; animation:hlCmIn .2s ease both; }
.hl-reply-chip b{ flex:1; min-width:0; color:#9db9f5; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hl-reply-x{ width:22px; height:22px; border:none; border-radius:50%; background:rgba(255,255,255,.1); color:#fff; display:flex; align-items:center; justify-content:center; font-size:14px; cursor:pointer; flex-shrink:0; }
.hl-reply-x:hover{ background:rgba(255,255,255,.2); }
.hl-compose-row{ display:flex; align-items:center; gap:10px; }
.hl-cmy{ width:32px; height:32px; border-radius:50%; overflow:hidden; flex-shrink:0; border:2px solid #2b63d1; background:#0d1030; display:block; }
.hl-cmy img{ width:100%; height:100%; object-fit:cover; display:block; }
#hlCIn{ flex:1; min-width:0; border:1px solid rgba(255,255,255,.1); outline:none; background:rgba(255,255,255,.07); color:#fff; padding:9px 14px; border-radius:999px; font-size:var(--cm-fs); -webkit-user-select:text; user-select:text; transition:border-color .2s,box-shadow .2s,background .2s; }
#hlCIn:focus{ border-color:rgba(76,141,255,.9); background:rgba(255,255,255,.1); box-shadow:0 0 0 3px rgba(43,99,209,.22); }
#hlCIn::placeholder{ color:rgba(255,255,255,.4); }
.hl-send{ width:34px; height:34px; border:none; border-radius:50%; padding:0; flex-shrink:0; background:linear-gradient(135deg,#2b63d1,#4C8DFF); color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .2s,color .2s,opacity .2s,transform .15s; -webkit-tap-highlight-color:transparent; }
.hl-send svg{ width:18px; height:18px; display:block; }
.hl-send:not(:disabled):active{ transform:scale(.88); }
.hl-send:disabled{ background:rgba(255,255,255,.08); color:rgba(255,255,255,.4); cursor:default; }
.hl-nocm{ display:flex; align-items:center; justify-content:center; gap:7px; font-size:var(--cm-meta); color:rgba(255,255,255,.55); }

/* responsive — things get smaller step by step, like the group page */
@media(max-width:790px){
  /* highlight box leaves a 48px strip at the bottom: only the comments TOP BAR (Comments · count · Top ▾) shows there */
  #hlWrap{ --hl-stage-h:calc(100vh - 48px); }
  @supports (height:100dvh){ #hlWrap{ --hl-stage-h:calc(100dvh - 48px); } }

  /* composer (profile pic, comment bar, send) stays hidden until you scroll into the comments */
  .hl-cm-composer{ opacity:0; visibility:hidden; pointer-events:none; transition:opacity .15s ease; }
  #hlComments.cm-on .hl-cm-composer{ opacity:1; visibility:visible; pointer-events:auto; }

  /* no scrollbar at all on responsive, and no highlight arrows */
  #hlScroll{ scrollbar-width:none; -ms-overflow-style:none; }
  #hlScroll::-webkit-scrollbar{ display:none; width:0; height:0; }
  .hl-nav{ display:none!important; }
  #hlComments{ --cm-px:16px; --csav:32px; --cm-fs:13.5px; --cm-meta:11.5px; }
  /* top bar reaches the very top edge (also under a phone notch) and stays solid */
  .hl-cm-top{ padding-top:env(safe-area-inset-top,0px); }
}
@media(max-width:600px){
  .hl-cap-wrap{ padding-left:22px; padding-right:22px; }
  .hl-poll{ padding-left:24px; padding-right:24px; }
}
@media(max-width:490px){
  #hlComments{ --cm-px:14px; --csav:30px; --csgap:9px; --cm-fs:13px; --cm-meta:11px; }
  .hl-sub .hl-ci{ --csav:26px; --csgap:8px; }
  .hl-avatar{ width:35px; height:35px; border-radius:9px; }
  .hl-pod{ font-size:12.5px; } .hl-who{ font-size:11px; } .hl-date{ font-size:10px; }
  .hl-icon-btn{ --hl-ic:19px; }
  .hl-act i{ font-size:22px; } .hl-act{ font-size:11.5px; }
  .hl-bar{ height:36px; } .hl-fake{ font-size:12px; }
  .hl-cap{ font-size:14px; } .hl-pq{ font-size:16px; }
  .hl-ci-r2{ gap:15px; }
}
@media(max-width:390px){
  #hlComments{ --cm-px:12px; --csav:28px; --csgap:8px; --cm-fs:12.5px; --cm-meta:10.5px; }
  .hl-sub .hl-ci{ --csav:24px; --csgap:7px; }
  .hl-avatar{ width:32px; height:32px; }
  .hl-count{ font-size:9px; }
  .hl-bottom{ gap:10px; padding-left:10px; padding-right:10px; }
  .hl-bar{ height:34px; }
  .hl-po{ font-size:13px; padding:10px 12px; }
  .hl-sheet-item{ font-size:12px; }
  .hl-cm-hb{ width:30px; height:30px; font-size:18px; }
  .hl-cmy{ width:28px; height:28px; }
  .hl-send{ width:32px; height:32px; }
}
@media(max-width:330px){
  #hlComments{ --cm-px:10px; --csav:26px; --csgap:7px; --cm-fs:12px; --cm-meta:10px; }
  .hl-avatar{ width:29px; height:29px; border-width:2px; border-radius:8px; }
  .hl-icon-btn{ --hl-ic:17px; width:28px; height:28px; }
  .hl-act i{ font-size:20px; }
  .hl-pod{ font-size:12px; }
  .hl-cm-note{ font-size:10.5px; }
}
@media(prefers-reduced-motion:reduce){ #hlModal *{ animation-duration:.01ms!important; } }
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
    $('hlScroll').scrollTop=0;
    $('hlComments').classList.remove('cm-on');
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
    isOpen=false; openTok++; renderTok++;
    cleanupFlip(); stopMedia(); stopAudio(); hlCloseSheet(); closeMenu(); $('hlCmSortMenu').classList.remove('show');
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
  function paintHeader(h){
    var av=$('hlAvatar'); av.onerror=function(){ imgFail(av); }; av.src=POD_PIC;
    $('hlPod').textContent=POD_NAME;
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
    if(hChanged){ paintHeader(h); $('hlScroll').scrollTop=0; resetCm(); renderCompose(h); loadComments(true); prefetch(); }
    updateNav();
    $('hlCount').textContent=(mi+1)+'/'+h.media.length;

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
          '<div class="hl-pod-row"><span class="hl-pod">'+esc(POD_NAME)+'</span><span class="hl-dot">•</span><span class="hl-date">'+esc(ago(h.created))+'</span></div>'+
          '<div class="hl-who-row"><span class="hl-who">'+esc(h.by.name)+'</span>'+badge(h.by.role)+'</div></div></div>'+
        '<span class="hl-icon-btn hl-menu">'+MENU_SVG+'</span>'+
        '<div class="hl-x-col"><span class="hl-icon-btn hl-x"><i class="ri-close-line"></i></span><span class="hl-count">'+(tmi+1)+'/'+h.media.length+'</span></div></div></div>'+
      '<div class="hl-bottom"><div class="hl-bar"><span class="hl-fake">'+(h.perm.comment?'Add a comment…':'View comments')+'</span></div>'+
        '<span class="hl-act'+(h.liked?' on':'')+'"><i class="ri-heart-3-fill"></i>'+((+h.likes>0)?'<span>'+esc(fmtNum(h.likes))+'</span>':'')+'</span>'+
        '<span class="hl-act"><i class="ri-question-answer-fill"></i>'+((+h.comments>0)?'<span>'+esc(fmtNum(h.comments))+'</span>':'')+'</span></div>';
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
  window.hlToTop=function(){ $('hlScroll').scrollTo({top:0,behavior:'smooth'}); };
  window.hlCmNav=function(){
    if($('hlComments').classList.contains('cm-on')) hlToTop(); else hlGoComments(false);
  };
  window.hlGoComments=function(focus){
    $('hlComments').scrollIntoView({behavior:'smooth',block:'start'});
    if(focus){ setTimeout(function(){ var i=$('hlCIn'); if(i) i.focus({preventScroll:true}); },420); }
  };

  /* ══════════════════ LIKES (highlight) ══════════════════ */
  function paintLike(h){
    var b=$('hlLikeBtn'); if(!b) return;
    b.classList.toggle('on',!!h.liked);
    $('hlLikeCount').textContent=(+h.likes>0)?fmtNum(h.likes):'';
  }
  window.hlLike=function(){
    var h=cur(); if(!h||liking) return;
    if(h.perm && h.perm.like===false){ toast(h.perm.like_msg||'Only pod members can like'); return; }
    var wasL=!!h.liked, wasN=+h.likes||0;
    h.liked=!wasL; h.likes=Math.max(0,wasN+(wasL?-1:1));
    paintLike(h);
    var ic=$('hlLikeBtn').querySelector('i'); ic.classList.remove('pop'); void ic.offsetWidth; ic.classList.add('pop');
    liking=true;
    api('like',{highlight_id:h.id}).then(function(d){
      liking=false;
      if(!d.success){ h.liked=wasL; h.likes=wasN; if(cur()===h) paintLike(h); toast(d.msg||'Could not like'); return; }
      h.liked=!!d.liked; h.likes=+d.likes||0;
      if(cur()===h) paintLike(h);
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
        '<div class="hl-compose-row"><span class="hl-cmy">'+avImg(h.me.pic)+'</span>'+
        '<input type="text" id="hlCIn" maxlength="500" placeholder="Add a comment…" autocomplete="off" enterkeyhint="send">'+
        '<button type="button" class="hl-send" id="hlCSend" aria-label="Post comment" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5"/><path d="M5.5 11.5 12 5l6.5 6.5"/></svg></button></div>';
      var inp=$('hlCIn'), send=$('hlCSend');
      inp.addEventListener('focus',syncVideo); inp.addEventListener('blur',syncVideo);
      inp.addEventListener('input',function(){ send.disabled=!inp.value.trim(); });
      inp.addEventListener('keydown',function(e){ e.stopPropagation(); if(e.key==='Enter'){ e.preventDefault(); postComment(); } });
      send.addEventListener('click',postComment);
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
        if(CM.sort!=='old') $('hlComments').scrollIntoView({behavior:'smooth',block:'start'});
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
    m.querySelector('[data-act="delete"]').style.display=c.canDel?'':'none';
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
    var c=CM.map[id], h=cur(); if(!c||!h||!c.canDel) return;
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
  $('hlScroll').addEventListener('scroll',closeMenu,{passive:true});
  /* load more comments as you reach the end of the list */
  if('IntersectionObserver' in window){
    new IntersectionObserver(function(es){ if(es[0].isIntersecting) loadComments(false); },{root:$('hlScroll'),rootMargin:'250px 0px'}).observe($('hlCmSent'));
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
      navigator.clipboard.writeText(u).then(function(){ toast('Link copied'); }).catch(function(){ toast('Could not copy'); });
    } else toast('Could not copy');
  };
  window.hlOpenSheet=function(){
    var h=cur(), html='';
    html+='<a class="hl-sheet-item" href="'+HL_USER_URL+h.by.id+'"><i class="ri-user-3-line"></i>View profile</a>';
    html+='<div class="hl-sheet-item" onclick="hlCopy()"><i class="ri-file-copy-line"></i>Copy Link</div>';
    if(h.perm.mod) html+='<a class="hl-sheet-item" href="group_controls.php?id='+GID+'&open=highlights"><i class="ri-settings-3-line"></i>Manage highlights</a>';
    if(String(h.by.id)!==String(ME)) html+='<div class="hl-sheet-item" onclick="hlReport()"><i class="ri-flag-line"></i>Report</div>';
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
</script>

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
   ZEEM CONNECT — lets the user optionally connect this NEW
   group post to one of their OWN existing Zeems (Part 1/Part 2,
   reactions, follow-ups, context, etc). This is a lightweight
   reference stored on the NEW post (connected_meme_id) — it does
   NOT merge, embed, or copy the other post's media/caption. Both
   Zeems remain fully independent afterward. Only the current
   user's own Zeems can be selected (must be re-validated again
   server-side on submit, not just here).
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
                <img class="post-modal-pic" src="<?php echo htmlspecialchars($current_user_pic); ?>" onerror="this.src='uploads/me.jpeg';">
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
                <textarea name="caption" id="captionInput" class="pm-input pm-textarea" rows="3" placeholder="Write a caption… include a #hashtag" oninput="checkHashtag(this)" autocomplete="off"></textarea>
                <div class="hashtag-hint invalid" id="hashtagHint">Must include at least one #hashtag to post</div>
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
                                <div class="pm-audio-lib-sub"><?php echo (int)$a['uses_count']; ?> uses</div>
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
/* ═══════════════════════════════════════════
   POST PILL (trigger — unchanged position/behavior)
═══════════════════════════════════════════ */
.post-pill-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 999px;
    padding: 6px 16px 6px 6px;
    margin-bottom: 22px;
    cursor: pointer;
    transition: 0.25s;
}
.post-pill-wrap:hover { background: rgba(255,255,255,0.09); }
.post-pill-wrap.locked { cursor: default; opacity: 0.55; }
.post-pill-wrap.locked:hover { background: rgba(255,255,255,0.05); }
.post-pill-pic {
    width: 40px; height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #FF2D2D;
    flex-shrink: 0;
}
.post-pill {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(255,255,255,0.06);
    border-radius: 999px;
    padding: 11px 18px;
    color: rgba(255,255,255,0.55);
    font-size: 13px;
}
.post-pill i { font-size: 17px; color: rgba(255,255,255,0.4); }
@media (max-width: 780px) {
    .post-pill-wrap { padding: 4px 14px 4px 5px; gap: 8px; margin: 0 4px 16px; }
    .post-pill-pic { width: 32px; height: 32px; }
    .post-pill { padding: 7px 15px; font-size: 12px; }
}
@media (max-width: 480px) {
    .post-pill-wrap { padding: 3px 12px 3px 4px; gap: 8px; margin: 0 4px 16px; }
    .post-pill-pic { width: 30px; height: 30px; }
    .post-pill { padding: 5px 13px; font-size: 11px; }
}

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
    --pm-card:rgba(255,255,255,.05); --pm-card2:rgba(255,255,255,.08);
    --pm-line:rgba(255,255,255,.09); --pm-line2:rgba(255,255,255,.14);
    --pm-sub:rgba(255,255,255,.62); --pm-mut:rgba(255,255,255,.45);
    --pm-pad:clamp(9px,3vw,16px);
    --pm-act-h:clamp(25px,7.2vw,30px);
    --pm-r:5px;
    --pm-fs:.8rem;
    --pm-tb-h:54px;

    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--pm-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
}
@media (max-width:490px){ #postModal{ --pm-fs:.74rem; --pm-tb-h:48px; } }
@media (max-width:390px){ #postModal{ --pm-fs:.7rem;  --pm-tb-h:44px; } }
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
    padding: env(safe-area-inset-top) 10px 0;
    background: transparent;
}
.pm-tb-side { flex: 0 0 38px; display: flex; align-items: center; }
.pm-tb-side.right { justify-content: flex-end; }
.pm-x {
    width: 34px; height: 34px; background: none; border: none; color: #fff;
    display: flex; align-items: center; justify-content: center; opacity: .9;
    padding: 0; transition: 0.2s;
}
.pm-x:hover { color: var(--pm-blue-l); opacity: 1; }
.pm-x svg { width: 22px; height: 22px; display: block; }
.pm-topbar-center { flex: 1; min-width: 0; text-align: center; }
.pm-topbar-title {
    font-size: .92rem; font-weight: 700; color: #fff; line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width:490px){ .pm-topbar-title{ font-size:.84rem; } .pm-x{ width:30px; height:30px; } }
@media (max-width:390px){ .pm-topbar-title{ font-size:.8rem; } }

.pm-drafts-btn {
    position: relative;
    width: 32px; height: var(--pm-act-h);
    display: flex; align-items: center; justify-content: center;
    border: none; border-radius: var(--pm-r);
    background: rgba(255,255,255,.1); color: var(--pm-blue-l);
    font-size: 16px; transition: 0.2s;
}
.pm-drafts-btn:hover { background: rgba(255,255,255,.2); }
.pm-drafts-btn.pm-drafts-active { background: var(--pm-blue-dim); color: #fff; }
.pm-drafts-badge {
    position: absolute; top: -5px; right: -5px;
    min-width: 16px; height: 16px; padding: 0 4px;
    border-radius: 4px; background: var(--pm-blue); color: var(--pm-on-blue);
    font-size: 9.5px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #070924;
}

/* ── form / scroll area ── */
.pm-form { flex: 1; min-height: 0; display: flex; flex-direction: column; margin: 0; }
.pm-body {
    flex: 1; min-height: 0;
    overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch;
    padding: 4px var(--pm-pad) 18px;
    scrollbar-width: thin; scrollbar-color: rgba(42,95,216,.4) transparent;
}
.pm-body::-webkit-scrollbar { width: 4px; }
.pm-body::-webkit-scrollbar-thumb { background: rgba(42,95,216,.45); border-radius: 99px; }

.upload-error {
    background: rgba(255,45,45,.12); border: 1px solid rgba(255,45,45,.35);
    border-radius: var(--pm-r); padding: 9px 12px; color: #ff7a7a;
    font-size: .72rem; margin-bottom: 12px; display: flex; align-items: center; gap: 7px;
}

/* ── Buttons: rectangular, same sizes as the upload page ── */
.pm-btn {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    flex: 1; min-width: 0; height: var(--pm-act-h); padding: 0 14px;
    border: none; border-radius: var(--pm-r);
    background: var(--pm-blue); color: var(--pm-on-blue);
    font-size: .79rem; font-weight: 700; line-height: 1; white-space: nowrap;
    transition: background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.pm-btn:hover { background: var(--pm-blue-h); box-shadow: 0 4px 16px var(--pm-blue-glow); }
.pm-btn:active { transform: scale(.98); }
.pm-btn:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }
.pm-btn.ghost { background: rgba(255,255,255,.1); color: #fff; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: none; }
.pm-btn.ghost:hover { background: rgba(255,255,255,.2); }
.pm-btn.sm { flex: 0 0 auto; padding: 0 12px; font-size: .75rem; }
.pm-btn i { font-size: 14px; }
@media (max-width:490px){ .pm-btn{ font-size:.68rem; padding:0 10px; gap:4px; } .pm-btn.sm{ font-size:.66rem; } .pm-btn i{ font-size:12px; } }

/* ── Action bar (sticks to the bottom of the lane) ── */
.pm-actionbar {
    flex-shrink: 0; display: flex; gap: 8px;
    padding: 8px var(--pm-pad) calc(10px + env(safe-area-inset-bottom));
}
.pm-btn-main { flex: 1.4; }

/* ── Drafts panel ── */
.pm-drafts-section {
    background: var(--pm-blue-dim); border: 1px solid rgba(42,95,216,.35);
    border-radius: 10px; padding: 12px 12px 4px; margin: 6px 0 14px;
}
.pm-drafts-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.pm-drafts-title { display: flex; align-items: center; gap: 6px; font-size: .76rem; font-weight: 700; color: var(--pm-blue-l); }
.pm-drafts-title i { font-size: 14px; }
.pm-drafts-clear { background: none; border: none; color: var(--pm-blue-l); font-size: .68rem; font-weight: 600; }
.pm-drafts-clear:hover { color: #fff; }
.pm-drafts-note { display: flex; align-items: center; gap: 6px; font-size: .64rem; color: var(--pm-mut); margin-bottom: 8px; }
.pm-drafts-note i { font-size: 12px; flex-shrink: 0; }
.pm-drafts-empty { font-size: .7rem; color: var(--pm-mut); font-style: italic; padding: 6px 2px 12px; }
.pm-draft-row { display: flex; align-items: center; gap: 8px; padding: 8px 2px; border-top: 1px solid rgba(255,255,255,.06); }
.pm-draft-row:first-of-type { border-top: none; }
.pm-draft-info { flex: 1; min-width: 0; cursor: pointer; }
.pm-draft-caption { font-size: .76rem; color: rgba(255,255,255,.88); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-draft-time { font-size: .6rem; color: var(--pm-mut); margin-top: 2px; }
.pm-draft-use-btn {
    height: 24px; padding: 0 11px; border: none; border-radius: var(--pm-r); flex-shrink: 0;
    background: var(--pm-blue); color: var(--pm-on-blue); font-size: .66rem; font-weight: 700; transition: .2s;
}
.pm-draft-use-btn:hover { background: var(--pm-blue-h); }
.pm-draft-del-btn {
    width: 24px; height: 24px; border: none; border-radius: var(--pm-r); flex-shrink: 0;
    background: rgba(255,255,255,.08); color: var(--pm-sub); font-size: 14px;
    display: flex; align-items: center; justify-content: center; transition: .2s;
}
.pm-draft-del-btn:hover { background: rgba(255,255,255,.18); color: #fff; }

/* ── User row ── */
.pm-user-row { display: flex; align-items: center; gap: 10px; margin: 8px 0 14px; }
.post-modal-pic {
    width: clamp(34px,9vw,42px); height: clamp(34px,9vw,42px);
    border-radius: 50%; object-fit: cover; flex-shrink: 0;
    border: 2px solid var(--pm-blue);
}
.pm-user-label { font-size: .6rem; color: var(--pm-mut); }
.pm-user-name { font-size: .82rem; font-weight: 700; color: #fff; }
@media (max-width:490px){ .pm-user-name{ font-size:.76rem; } }

/* ── Dropzone ── */
.pm-drop {
    border: 1.5px dashed var(--pm-line2); border-radius: 10px; padding: 22px 14px;
    text-align: center; transition: .2s; cursor: pointer; display: block; background: var(--pm-card);
}
.pm-drop:hover, .pm-drop.over { border-color: var(--pm-blue); background: var(--pm-blue-dim); }
.pm-drop i { font-size: 26px; color: var(--pm-blue-l); display: block; margin-bottom: 6px; }
.pm-drop p { font-size: .78rem; color: #fff; font-weight: 500; }
.pm-drop small { display: block; color: var(--pm-mut); font-size: .66rem; margin-top: 3px; }
.pm-drop input[type=file] { display: none; }
@media (max-width:490px){ .pm-drop{ padding:18px 12px; } .pm-drop i{ font-size:22px; } .pm-drop p{ font-size:.72rem; } }

/* ── Media strip (cover + reorder) ── */
.pm-strip { margin-top: 10px; }
.pm-strip-inner { display: flex; gap: 7px; overflow-x: auto; padding: 4px 2px; scrollbar-width: none; }
.pm-strip-inner::-webkit-scrollbar { display: none; }
.pm-thumb {
    position: relative; flex-shrink: 0; width: 58px; height: 58px; border-radius: 8px; overflow: hidden;
    border: 2px solid var(--pm-line2); cursor: grab; background: #000;
}
.pm-thumb.cover { border-color: var(--pm-blue); }
.pm-thumb img, .pm-thumb video { width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none; }
.pm-thumb-badge { position: absolute; top: 3px; left: 3px; background: var(--pm-blue); color: var(--pm-on-blue); font-size: 7.5px; font-weight: 800; padding: 1px 5px; border-radius: 4px; }
.pm-thumb-remove {
    position: absolute; top: 3px; right: 3px; width: 17px; height: 17px; border-radius: 4px;
    background: rgba(0,0,0,.8); color: #fff; border: none; font-size: 9px;
    display: flex; align-items: center; justify-content: center; padding: 0;
}
.pm-thumb-remove:hover { background: var(--pm-blue); color: var(--pm-on-blue); }
.pm-thumb-add {
    flex-shrink: 0; width: 58px; height: 58px; border-radius: 8px; border: 2px dashed var(--pm-line2);
    background: var(--pm-card); display: flex; align-items: center; justify-content: center;
    color: var(--pm-sub); font-size: 20px; cursor: pointer; padding: 0;
}
.pm-thumb-add:hover { border-color: var(--pm-blue); color: var(--pm-blue-l); }
@media (max-width:490px){ .pm-thumb, .pm-thumb-add{ width:50px; height:50px; } }
.pm-hint { font-size: .66rem; color: var(--pm-mut); margin-top: 6px; line-height: 1.5; }
.pm-hint strong { color: var(--pm-sub); }

/* ── Preview ── */
.pm-preview {
    position: relative; width: min(100%,250px); margin: 14px auto 0; aspect-ratio: 4/5;
    border-radius: 10px; overflow: hidden; background: var(--pm-card); border: 1px solid var(--pm-line);
}
.pm-preview img, .pm-preview video { width: 100%; height: 100%; object-fit: cover; display: block; }
.pm-preview-label {
    position: absolute; bottom: 8px; left: 8px; background: rgba(0,0,0,.65); backdrop-filter: blur(8px);
    border-radius: 5px; padding: 3px 8px; font-size: 8.5px; font-weight: 700; color: rgba(255,255,255,.75);
    letter-spacing: .6px; text-transform: uppercase;
}
.pm-preview-audio {
    position: absolute; top: 8px; left: 8px; max-width: calc(100% - 16px); background: var(--pm-blue);
    border-radius: 5px; padding: 3px 8px; font-size: 9.5px; font-weight: 700; color: var(--pm-on-blue);
    display: flex; align-items: center; gap: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width:490px){ .pm-preview{ width:min(100%,210px); } }

/* ── Inputs ── */
.pm-field { margin-top: 14px; }
.pm-input {
    width: 100%; height: var(--pm-act-h); padding: 0 11px; border-radius: var(--pm-r);
    border: 1px solid var(--pm-line2); background: var(--pm-card); color: #fff;
    font-size: .76rem; transition: .2s; -webkit-appearance: none; appearance: none;
}
.pm-input:focus { border-color: rgba(42,95,216,.85); box-shadow: 0 0 0 3px rgba(42,95,216,.18); }
.pm-input::placeholder { color: var(--pm-mut); }
.pm-textarea { height: auto; min-height: 84px; padding: 9px 11px; line-height: 1.6; resize: none; font-size: .82rem; }
@media (max-width:490px){ .pm-input{ font-size:.7rem; } .pm-textarea{ font-size:.76rem; } }
.hashtag-hint { font-size: .66rem; color: var(--pm-mut); margin-top: 6px; padding-left: 2px; transition: color .25s; }
.hashtag-hint.valid   { color: var(--pm-blue-l); }
.hashtag-hint.invalid { color: var(--pm-blue-l); }

/* ── Sections ── */
.pm-sec { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--pm-line); }
.pm-sec-h {
    display: flex; align-items: center; gap: 6px; margin-bottom: 9px;
    font-size: .64rem; font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--pm-sub);
}
.pm-sec-h i { font-size: 14px; color: var(--pm-blue-l); }
.pm-optional {
    margin-left: auto; font-size: .58rem; font-weight: 600; letter-spacing: .4px; text-transform: uppercase;
    color: var(--pm-mut); background: rgba(255,255,255,.07); padding: 2px 8px; border-radius: 4px;
}
.pm-empty { padding: 14px 0; text-align: center; color: var(--pm-mut); font-size: .72rem; }
.pm-empty i { font-size: 24px; display: block; margin-bottom: 6px; color: rgba(126,166,255,.45); }

/* ── Pod hashtags ── */
.pm-hashtag-pills { display: flex; flex-wrap: wrap; gap: 7px; }
.pm-hashtag-pill {
    height: var(--pm-act-h); padding: 0 12px; border: none; border-radius: var(--pm-r);
    background: rgba(255,255,255,.1); color: #fff; font-size: .74rem; font-weight: 600; transition: .2s;
}
.pm-hashtag-pill:hover { background: rgba(255,255,255,.2); }
.pm-hashtag-pill:active { transform: scale(.96); }
.pm-hashtag-pill.pm-tag-used { background: var(--pm-blue); color: var(--pm-on-blue); }
@media (max-width:490px){ .pm-hashtag-pill{ font-size:.66rem; padding:0 10px; } }

/* ── Rows + switch ── */
.pm-rows { padding-top: 4px; }
.pm-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; }
.pm-row > i { font-size: 20px; width: 24px; text-align: center; flex-shrink: 0; color: var(--pm-blue-l); }
.pm-lbl { flex: 1; min-width: 0; font-size: .8rem; }
.pm-subfield { padding: 0 0 6px 36px; }
@media (max-width:490px){ .pm-row{ gap:10px; } .pm-row > i{ font-size:18px; width:21px; } .pm-lbl{ font-size:.74rem; } .pm-subfield{ padding-left:0; } }
.pm-switch { position: relative; display: inline-block; width: 40px; height: 23px; flex-shrink: 0; }
.pm-switch input { opacity: 0; width: 0; height: 0; }
.pm-slider { position: absolute; cursor: pointer; inset: 0; background: rgba(255,255,255,.18); border-radius: 34px; transition: .25s; }
.pm-slider::before { position: absolute; content: ""; height: 17px; width: 17px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: .25s; }
.pm-switch input:checked + .pm-slider { background: var(--pm-blue); }
.pm-switch input:checked + .pm-slider::before { transform: translateX(17px); background: #000; }
@media (max-width:490px){ .pm-switch{ width:36px; height:21px; } .pm-slider::before{ height:15px; width:15px; } .pm-switch input:checked + .pm-slider::before{ transform:translateX(15px); } }

/* ── Segmented switch (audio mode) ── */
.pm-seg { display: flex; flex-wrap: wrap; padding: 3px; border-radius: var(--pm-r); background: var(--pm-card); border: 1px solid var(--pm-line); gap: 3px; }
.pm-audio-mode-btn {
    flex: 1; min-width: 70px; height: calc(var(--pm-act-h) - 2px); padding: 0 6px; border: none; border-radius: 4px;
    background: none; color: var(--pm-sub); font-size: .79rem; font-weight: 600; white-space: nowrap;
    display: flex; align-items: center; justify-content: center; transition: .2s;
}
.pm-audio-mode-btn.active { background: var(--pm-blue); color: var(--pm-on-blue); }
@media (max-width:490px){ .pm-audio-mode-btn{ font-size:.68rem; min-width:60px; } }

/* ── Audio library / pill / volume ── */
.pm-audio-lib-list { max-height: 230px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; }
.pm-audio-lib-item { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 8px; background: var(--pm-card); border: 1px solid var(--pm-line); cursor: pointer; transition: .2s; }
.pm-audio-lib-item:hover { border-color: rgba(42,95,216,.55); background: var(--pm-blue-dim); }
.pm-audio-lib-icon { width: 34px; height: 34px; border-radius: 6px; background: var(--pm-blue-dim); display: flex; align-items: center; justify-content: center; color: var(--pm-blue-l); font-size: 15px; flex-shrink: 0; }
.pm-audio-lib-info { flex: 1; min-width: 0; }
.pm-audio-lib-title { font-size: .74rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-audio-lib-sub { font-size: .64rem; color: var(--pm-mut); }
.pm-audio-lib-play { font-size: 20px; color: var(--pm-mut); flex-shrink: 0; }
.pm-audio-pill { display: flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: var(--pm-r); background: var(--pm-blue-dim); border: 1px solid rgba(42,95,216,.4); margin-top: 12px; }
.pm-audio-pill > i { color: var(--pm-blue-l); font-size: 15px; flex-shrink: 0; }
.pm-audio-pill > span { flex: 1; font-size: .72rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-pill-btn { width: 24px; height: 24px; border-radius: var(--pm-r); border: none; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; padding: 0; }
.pm-pill-btn.play { background: var(--pm-blue); color: var(--pm-on-blue); }
.pm-pill-btn.rm { background: rgba(255,255,255,.1); color: var(--pm-sub); }
.pm-pill-btn.rm:hover { background: rgba(255,255,255,.2); color: #fff; }
.pm-vol { margin-top: 12px; display: flex; flex-direction: column; gap: 9px; padding: 11px; background: var(--pm-card); border-radius: 8px; border: 1px solid var(--pm-line); }
.pm-vol-row { display: flex; align-items: center; gap: 8px; font-size: .66rem; color: var(--pm-sub); }
.pm-vol-row span:first-child { width: 56px; flex-shrink: 0; }
.pm-vol-row input[type=range] { flex: 1; accent-color: var(--pm-blue); }
.pm-vol-row span:last-child { width: 26px; text-align: right; font-weight: 700; color: #fff; flex-shrink: 0; }

/* ── Zeem connect ── */
.pm-zeem-toggle-btn {
    width: 100%; height: var(--pm-act-h); display: flex; align-items: center; gap: 8px; padding: 0 12px;
    border: none; border-radius: var(--pm-r); background: rgba(255,255,255,.1);
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    color: #fff; font-size: .79rem; font-weight: 600; transition: .2s;
}
.pm-zeem-toggle-btn:hover { background: rgba(255,255,255,.2); }
.pm-zeem-toggle-btn > i { color: var(--pm-blue-l); font-size: 15px; }
.pm-zeem-toggle-btn > span:nth-of-type(1) { flex: 1; text-align: left; }
@media (max-width:490px){ .pm-zeem-toggle-btn{ font-size:.68rem; } }
.pm-zeem-panel { margin-top: 10px; animation: pmFade .2s ease; }
@keyframes pmFade { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
.pm-zeem-section-label { margin: 12px 0 8px; font-size: .6rem; font-weight: 700; color: var(--pm-mut); text-transform: uppercase; letter-spacing: .6px; }
.pm-zg-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; max-height: 360px; overflow-y: auto; padding-right: 2px; }
@media (max-width:330px){ .pm-zg-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
.pm-zg-item { display: flex; flex-direction: column; text-align: left; padding: 0; border-radius: 8px; overflow: hidden; background: var(--pm-card); border: 1px solid var(--pm-line); color: #fff; transition: .2s; }
.pm-zg-item:hover { border-color: rgba(42,95,216,.7); }
.pm-zg-item.sel { border-color: var(--pm-blue); box-shadow: 0 0 0 2px var(--pm-blue-glow); }
.pm-zg-media { position: relative; display: block; width: 100%; aspect-ratio: 4/5; background: #0d1030; overflow: hidden; }
.pm-zg-media img, .pm-zg-media video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; pointer-events: none; }
.pm-zg-media.txt { background: #0d1030 url('uploads/Text.jpg') center/cover no-repeat; }
.pm-zg-txt { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; padding: 8px; text-align: center; background: rgba(0,0,0,.45); font-size: .6rem; line-height: 1.4; font-weight: 500; word-break: break-word; }
.pm-zg-txt span { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 6; overflow: hidden; }
.pm-zg-flag { position: absolute; top: 5px; right: 5px; padding: 2px 6px; border-radius: 4px; background: rgba(0,0,0,.6); font-size: .58rem; font-weight: 700; display: flex; gap: 3px; align-items: center; }
.pm-zg-check { position: absolute; bottom: 5px; right: 5px; width: 20px; height: 20px; border-radius: 5px; background: var(--pm-blue); color: var(--pm-on-blue); display: flex; align-items: center; justify-content: center; font-size: 13px; }
.pm-zg-cap { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; padding: 6px 7px 7px; font-size: .62rem; line-height: 1.4; color: rgba(255,255,255,.85); min-height: 3em; }

.pm-zeem-connected-card { display: flex; align-items: center; gap: 10px; padding: 9px; border-radius: 8px; background: rgba(42,95,216,.12); border: 1px solid rgba(42,95,216,.4); margin-top: 10px; }
.pm-zeem-connected-thumb { width: 52px; height: 65px; border-radius: 6px; overflow: hidden; background: #000; flex-shrink: 0; }
.pm-zeem-connected-thumb img, .pm-zeem-connected-thumb video { width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none; }
.pm-zeem-connected-info { flex: 1; min-width: 0; }
.pm-zeem-connected-caption { font-size: .74rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-zeem-connected-badge { font-size: .64rem; font-weight: 700; color: var(--pm-blue-l); margin-top: 3px; display: flex; align-items: center; gap: 4px; }
.pm-zeem-label-area { margin-top: 10px; }
.pm-zeem-label-title { display: block; margin-bottom: 7px; font-size: .62rem; font-weight: 700; color: var(--pm-sub); text-transform: uppercase; letter-spacing: .4px; }
.pm-zeem-label-preview { margin-top: 8px; font-size: .68rem; font-weight: 600; color: var(--pm-sub); }
.pm-zeem-label-preview span { color: var(--pm-blue-l); }

/* ── Progress overlay ── */
.pm-pg {
    position: fixed; inset: 0; z-index: 1000000000; background: rgba(1,2,24,.78); backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center; padding: 20px;
    opacity: 0; visibility: hidden; transition: opacity .2s;
}
.pm-pg.show { opacity: 1; visibility: visible; }
.pm-pg-card { width: 100%; max-width: 320px; background: #0d1030; border: 1px solid var(--pm-line2); border-radius: 12px; padding: 20px 18px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.7); }
.pm-pg-ic { width: 48px; height: 48px; border-radius: 50%; background: var(--pm-blue-dim); border: 1px solid rgba(42,95,216,.45); color: var(--pm-blue-l); display: flex; align-items: center; justify-content: center; font-size: 22px; margin: 0 auto 12px; }
.pm-pg-ic.ok  { background: var(--pm-blue); border-color: var(--pm-blue); color: var(--pm-on-blue); }
.pm-pg-ic.bad { background: rgba(255,45,45,.13); border-color: rgba(255,45,45,.35); color: #ff7a7a; }
.pm-pg-title { font-size: .88rem; font-weight: 600; }
.pm-pg-bar { height: 5px; border-radius: 4px; background: rgba(255,255,255,.09); overflow: hidden; margin: 14px 0 8px; }
.pm-pg-bar span { display: block; height: 100%; width: 0; background: var(--pm-blue); border-radius: 4px; transition: width .15s ease; }
.pm-pg-row { display: flex; justify-content: space-between; gap: 10px; font-size: .68rem; color: var(--pm-sub); }
.pm-pg-row b { color: #fff; }
.pm-pg-close { margin-top: 14px; display: none; width: 100%; flex: none; }

@media (prefers-reduced-motion:reduce){ #postModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>

<script>
/* ── Per-user, per-group draft storage ── */
var PM_DRAFTS_KEY = 'pm_drafts_group_<?php echo (int)$group_id; ?>_user_<?php echo (int)$current_user; ?>';
var PM_MAX_DRAFTS = 15;
var PM_MAX_FILES = 20;
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
    pmEl('pmPartnershipCheck').checked = false;
    pmTogglePartnership();
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
   scoped to uploaded_by = current user); the server must re-check
   ownership again on submit as a second line of defense. */
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
   The form is posted in the background with XMLHttpRequest so the progress
   bar can move. When the server answers:
     • if it re-rendered this modal with a ".upload-error" message, that
       message is shown on the progress card;
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

    var form = pmEl('postModalForm');
    var fd = new FormData(form);
    fd.set('upload_post', '1');   // the server looks for this (it normally comes from the button)

    _pmUploading = true;
    pmEl('pmSubmitBtn').disabled = true;
    pmEl('pmDraftBtn').disabled = true;
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
    --gsm-card:rgba(255,255,255,.05); --gsm-card2:rgba(255,255,255,.08);
    --gsm-line:rgba(255,255,255,.09); --gsm-line2:rgba(255,255,255,.14);
    --gsm-sub:rgba(255,255,255,.62); --gsm-mut:rgba(255,255,255,.45);
    --gsm-pad:clamp(9px,3vw,16px);
    --gsm-act-h:clamp(25px,7.2vw,30px);
    --gsm-sb-h:clamp(33px,9vw,38px);
    --gsm-r:5px;
    --gsm-fs:.8rem;
    --gsm-tb-h:54px;

    display: none;
    position: fixed;
    inset: 0;
    z-index: 2147483647;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--gsm-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
}
@media (max-width:490px){ #gsmModal{ --gsm-fs:.74rem; --gsm-tb-h:48px; } }
@media (max-width:390px){ #gsmModal{ --gsm-fs:.7rem;  --gsm-tb-h:44px; } }
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
    display: flex; align-items: center; gap: 8px;
    min-height: var(--gsm-tb-h);
    padding: calc(env(safe-area-inset-top) + 14px) var(--gsm-pad) 6px 0;
    background: transparent;
}
.gsm-back-btn {
    width: 42px; height: 42px; flex-shrink: 0;
    background: none; border: none; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; padding: 0; margin-left: 2px; opacity: .9; transition: 0.2s;
}
.gsm-back-btn:hover { color: var(--gsm-blue-l); opacity: 1; }
.gsm-back-btn:active { opacity: .6; }
@media (max-width:490px){ .gsm-back-btn{ width:38px; height:38px; font-size:28px; margin-left:0; } }

#gsmSearchWrap {
    flex: 1; min-width: 0; display: flex; align-items: center;
    height: var(--gsm-sb-h);
    background: var(--gsm-card);
    border: 1px solid var(--gsm-line2);
    border-radius: var(--gsm-r); overflow: hidden;
    transition: border-color .2s, box-shadow .2s;
}
#gsmSearchWrap.focused { border-color: rgba(42,95,216,.85); box-shadow: 0 0 0 3px rgba(42,95,216,.18); }

.gsm-search-icon { padding: 0 6px 0 12px; color: var(--gsm-mut); font-size: 16px; flex-shrink: 0; display: flex; align-items: center; }
#gsmSearchInput {
    flex: 1; min-width: 0; height: 100%; padding: 0 6px; background: transparent; border: none; outline: none;
    color: #fff; font-size: .8rem;
}
#gsmSearchInput::placeholder { color: var(--gsm-mut); }
@media (max-width:490px){ #gsmSearchInput{ font-size:.74rem; } .gsm-search-icon{ padding:0 5px 0 10px; font-size:15px; } }
#gsmClearBtn {
    display: none; background: transparent; border: none; color: var(--gsm-mut);
    padding: 0 12px; font-size: 17px; flex-shrink: 0; line-height: 1; height: 100%;
    transition: color .2s;
}
#gsmClearBtn:hover { color: #fff; }

#gsmSearchBtn {
    display: none; align-items: center; justify-content: center; flex-shrink: 0;
    height: var(--gsm-sb-h); padding: 0 16px;
    border: none; border-radius: var(--gsm-r);
    background: var(--gsm-blue); color: #fff;
    font-size: .79rem; font-weight: 700; line-height: 1; white-space: nowrap;
    transition: background .2s ease, transform .15s ease, box-shadow .2s ease;
}
#gsmSearchBtn:hover { background: var(--gsm-blue-h); box-shadow: 0 4px 16px var(--gsm-blue-glow); }
#gsmSearchBtn:active { transform: scale(.98); }
@media (max-width:490px){ #gsmSearchBtn{ font-size:.68rem; padding:0 10px; } }

/* ══ SCOPE LABEL — "Searching in [Group Name]" ══ */
.gsm-context-bar {
    display: flex; align-items: center; gap: 7px;
    margin: 8px var(--gsm-pad) 0;
    padding: 0 11px;
    min-height: var(--gsm-act-h);
    font-size: .7rem; font-weight: 500;
    color: var(--gsm-sub);
    background: var(--gsm-blue-dim);
    border: 1px solid rgba(42,95,216,.35);
    border-radius: var(--gsm-r);
    flex-shrink: 0;
}
.gsm-context-bar i { font-size: 14px; color: var(--gsm-blue-l); flex-shrink: 0; }
.gsm-context-bar span { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gsm-context-bar strong { color: #fff; font-weight: 700; }
@media (max-width:490px){ .gsm-context-bar{ font-size:.64rem; gap:6px; } .gsm-context-bar i{ font-size:13px; } }

/* ── Scroll area ── */
#gsmContent {
    flex: 1; min-height: 0; overflow-y: auto; overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    padding: 6px 0 calc(14px + env(safe-area-inset-bottom));
    scrollbar-width: thin; scrollbar-color: rgba(42,95,216,.4) transparent;
}
#gsmContent::-webkit-scrollbar { width: 4px; }
#gsmContent::-webkit-scrollbar-thumb { background: rgba(42,95,216,.45); border-radius: 99px; }

.gsm-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 64px 20px; gap: 8px; color: var(--gsm-mut); text-align: center;
}
.gsm-empty i { font-size: 36px; color: rgba(126,166,255,.45); }
.gsm-empty p { font-size: .8rem; font-weight: 500; color: #fff; }
.gsm-empty small { font-size: .66rem; }

.gsm-sec {
    display: flex; align-items: center;
    font-size: .64rem; font-weight: 600; letter-spacing: .6px;
    color: var(--gsm-sub); text-transform: uppercase;
    padding: 14px var(--gsm-pad) 4px;
}

.gsm-row {
    display: flex; align-items: center; gap: 10px;
    padding: 9px var(--gsm-pad); cursor: pointer;
    transition: background .15s; -webkit-tap-highlight-color: transparent;
}
.gsm-row:hover, .gsm-row:active { background: var(--gsm-card2); }

.gsm-avatar {
    width: clamp(36px,9.5vw,44px); height: clamp(36px,9.5vw,44px);
    border-radius: 50%; object-fit: cover; border: 2px solid var(--gsm-blue); flex-shrink: 0;
}
.gsm-avatar.meme { border-radius: 8px; border-color: var(--gsm-line2); }
.gsm-video-wrap {
    width: clamp(36px,9.5vw,44px); height: clamp(36px,9.5vw,44px);
    border-radius: 8px; border: 2px solid var(--gsm-line2);
    background: #000; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; position: relative; overflow: hidden;
}
.gsm-video-wrap video { width: 100%; height: 100%; object-fit: cover; border-radius: 6px; }
.gsm-video-wrap .gsm-play-overlay { position: absolute; color: rgba(255,255,255,.8); font-size: 16px; pointer-events: none; }

.gsm-query-icon {
    width: clamp(36px,9.5vw,44px); height: clamp(36px,9.5vw,44px);
    border-radius: 50%; background: var(--gsm-card2);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.gsm-query-icon i { font-size: 16px; color: var(--gsm-mut); }

.gsm-info { flex: 1; min-width: 0; }
.gsm-name {
    font-size: .78rem; font-weight: 600; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.gsm-name.cap { color: rgba(255,255,255,.88); font-weight: 500; }
.gsm-sub { font-size: .64rem; color: var(--gsm-mut); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
@media (max-width:490px){ .gsm-name{ font-size:.72rem; } .gsm-sub{ font-size:.6rem; } }

.gsm-hl { color: var(--gsm-blue-l); font-weight: 700; }

.gsm-rm-btn {
    width: 24px; height: 24px; flex-shrink: 0;
    background: rgba(255,255,255,.08); border: none; border-radius: var(--gsm-r);
    color: var(--gsm-sub); font-size: 14px; padding: 0; line-height: 1;
    display: flex; align-items: center; justify-content: center; transition: .2s;
}
.gsm-rm-btn:hover { background: rgba(255,255,255,.18); color: #fff; }

.gsm-full-search-row { border-top: 1px solid var(--gsm-line); margin-top: 6px; padding-top: 12px; }
.gsm-full-search-icon {
    width: clamp(36px,9.5vw,44px); height: clamp(36px,9.5vw,44px);
    border-radius: 50%; background: var(--gsm-blue-dim); border: 2px solid rgba(42,95,216,.55);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.gsm-full-search-icon i { color: var(--gsm-blue-l); font-size: 16px; }
.gsm-full-search-label { color: var(--gsm-blue-l); }

.gsm-loader { padding: 28px; text-align: center; color: var(--gsm-mut); }
.gsm-loader i { font-size: 22px; color: var(--gsm-blue-l); }
.gsm-spin { animation: gsmSpin 0.7s linear infinite; display: inline-block; }
@keyframes gsmSpin { to { transform: rotate(360deg); } }

/* ── RECENTS ── */
#gsmRecentsWrap { padding: 10px 0 0; }
.gsm-recents-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 4px var(--gsm-pad) 10px;
    border-bottom: 1px solid var(--gsm-line2);
}
#gsmRecentsList .gsm-row { border-bottom: 1px solid var(--gsm-line); padding-top: 11px; padding-bottom: 11px; }
.gsm-recents-title {
    font-size: .64rem; font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--gsm-sub);
}
#gsmClearAllBtn {
    background: none; border: none; color: var(--gsm-blue-l);
    font-size: .68rem; font-weight: 600; transition: color .2s;
}
#gsmClearAllBtn:hover { color: #fff; }
.gsm-no-recents { color: var(--gsm-mut); font-size: .72rem; font-style: italic; padding: 4px var(--gsm-pad) 22px; }

/* ── TRIGGER CLEAR BUTTON (used outside the modal, kept for compatibility) ── */
.gsm-trigger-clear {
    margin: 5px; width: 32px; height: 32px;
    border-radius: var(--gsm-r, 5px); color: #fff;
    background: #2a5fd8; display: flex; align-items: center;
    justify-content: center; flex-shrink: 0; text-decoration: none;
    font-size: 15px; transition: all .2s ease;
    -webkit-tap-highlight-color: transparent; cursor: pointer;
}
.gsm-trigger-clear:hover { background: #234fb5; box-shadow: 0 4px 16px rgba(42,95,216,.34); }
.gsm-trigger-clear:active { transform: scale(.96); }

@media (prefers-reduced-motion:reduce){ #gsmModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>

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
                var pic = r.profile_pic || 'uploads/me.jpeg';
                name = r.username || '';
                sub  = r.nickname ? '@'+r.nickname : 'Member';
                avatarHtml = '<img class="gsm-avatar" src="'+gsmEsc(pic)+'" onerror="this.src=\'uploads/me.jpeg\'">';
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
            var pic = u.profile_pic || 'uploads/me.jpeg';
            var enc = encodeURIComponent(JSON.stringify(u));
            return '<div class="gsm-row" onclick="gsmSelectResult(\''+enc+'\')">'
                + '<img class="gsm-avatar" src="'+gsmEsc(pic)+'" onerror="this.src=\'uploads/me.jpeg\'">'
                + '<div class="gsm-info">'
                    + '<div class="gsm-name">'+gsmHL(u.username,q)+'</div>'
                    + '<div class="gsm-sub">'+(u.nickname ? gsmHL('@'+u.nickname,q) : 'Member')+'</div>'
                + '</div>'
                + '<i class="ri-arrow-right-s-line" style="color:rgba(255,255,255,0.3);font-size:18px;flex-shrink:0;"></i>'
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
                + '<i class="ri-image-line" style="color:rgba(255,255,255,0.3);font-size:15px;flex-shrink:0;"></i>'
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
                 onerror="this.src='uploads/me.jpeg'"
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
    --ym-btn:#1b3a85; --ym-btn-h:#234799; --ym-btn-on:#2a5fd8; --ym-btn-on-h:#234fb5; --ym-ink:#fff;
    --ym-card:rgba(255,255,255,.05); --ym-card2:rgba(255,255,255,.08);
    --ym-line:rgba(255,255,255,.09); --ym-line2:rgba(255,255,255,.14);
    --ym-sub:rgba(255,255,255,.62); --ym-mut:rgba(255,255,255,.45);
    --ym-pad:clamp(9px,3vw,16px);
    --ym-act-h:clamp(25px,7.2vw,30px);
    --ym-r:5px;
    --ym-fs:.8rem;
    --ym-tb-h:54px;

    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999999;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--ym-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
}
@media (max-width:490px){ #youModal{ --ym-fs:.74rem; --ym-tb-h:48px; } }
@media (max-width:390px){ #youModal{ --ym-fs:.7rem;  --ym-tb-h:44px; } }
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
    padding: env(safe-area-inset-top) 10px 0;
    background: transparent;
}
.ym-tb-side { flex: 0 0 38px; display: flex; align-items: center; }
.ym-tb-side.right { justify-content: flex-end; }
.ym-x {
    width: 34px; height: 34px; background: none; border: none; color: #fff;
    display: flex; align-items: center; justify-content: center; opacity: .9; padding: 0; transition: 0.2s;
}
.ym-x:hover { color: var(--ym-blue-l); opacity: 1; }
.ym-x svg { width: 22px; height: 22px; display: block; }
.ym-topbar-center { flex: 1; min-width: 0; text-align: right; padding-right: 6px; }
.ym-topbar-title { font-size: .92rem; font-weight: 700; color: #fff; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ym-topbar-sub { font-size: .62rem; color: var(--ym-mut); font-weight: 400; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
@media (max-width:490px){ .ym-topbar-title{ font-size:.84rem; } .ym-x{ width:30px; height:30px; } }
@media (max-width:390px){ .ym-topbar-title{ font-size:.8rem; } }

/* ── Scroll area ── */
.ym-body {
    flex: 1; min-height: 0;
    overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch;
    padding: 4px var(--ym-pad) 28px;
    scrollbar-width: thin; scrollbar-color: rgba(42,95,216,.4) transparent;
}
.ym-body::-webkit-scrollbar { width: 4px; }
.ym-body::-webkit-scrollbar-thumb { background: rgba(42,95,216,.45); border-radius: 99px; }

/* ── HERO: tab buttons left · profile pic right ── */
.ym-hero {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; margin: 8px 0 18px;
}
.ym-tabs {
    display: flex; flex-direction: column; gap: 8px;
    flex: 0 1 190px; min-width: 0;
}
.ym-pill {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    width: 100%; height: var(--ym-act-h); padding: 0 14px;
    border: none; border-radius: var(--ym-r);
    background: var(--ym-btn); color: var(--ym-ink);
    font-size: .79rem; font-weight: 700; line-height: 1; white-space: nowrap;
    transition: background .2s ease, transform .15s ease;
}
.ym-pill i { font-size: 14px; color: var(--ym-ink); }
.ym-pill:hover { background: var(--ym-btn-h); }
.ym-pill:active { transform: scale(.98); }
.ym-pill.active { background: var(--ym-btn-on); color: var(--ym-ink); }
.ym-pill.active:hover { background: var(--ym-btn-on-h); }
@media (max-width:490px){ .ym-pill{ font-size:.68rem; padding:0 10px; gap:4px; } .ym-pill i{ font-size:12px; } .ym-tabs{ flex-basis:160px; } }

.ym-big-pic {
    width: clamp(78px,22vw,104px); height: clamp(78px,22vw,104px);
    border-radius: 50%; object-fit: cover; display: block; flex-shrink: 0;
    border: 3px solid var(--ym-blue);
}

/* ── Tabs ── */
.ym-tab { display: none; }
.ym-tab.active { display: block; }

/* ── Accordion ── */
.ym-accordion {
    background: var(--ym-card); border: 1px solid var(--ym-line);
    border-radius: 8px; margin-bottom: 14px; overflow: hidden; padding: 4px;
}
.ym-acc-head {
    width: 100%; height: var(--ym-act-h); display: flex; align-items: center; gap: 7px;
    padding: 0 12px; border: none; border-radius: var(--ym-r); background: var(--ym-btn); text-align: left;
    color: var(--ym-ink); font-size: .74rem; font-weight: 700; line-height: 1; transition: background .2s;
}
.ym-acc-head:hover { background: var(--ym-btn-h); }
.ym-acc-head i { font-size: 14px; flex-shrink: 0; color: var(--ym-ink); }
.ym-acc-head > span { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ym-acc-chevron { font-size: 15px !important; transition: transform 0.25s; color: var(--ym-ink); }
.ym-accordion.open .ym-acc-chevron { transform: rotate(180deg); }
.ym-acc-body { display: none; padding: 0 8px 10px; }
.ym-accordion.open .ym-acc-body { display: block; }
.ym-acc-body p { font-size: .72rem; color: var(--ym-sub); line-height: 1.6; margin: 10px 0 8px; }
.ym-acc-body ul { padding: 0; list-style: none; margin: 0; }
.ym-acc-body ul li {
    display: flex; align-items: flex-start; gap: 7px; margin-bottom: 6px;
    font-size: .72rem; color: var(--ym-sub); line-height: 1.6;
}
.ym-acc-body ul li i { font-size: 13px; color: var(--ym-blue-l); flex-shrink: 0; margin-top: 2px; }
.ym-acc-body ul li strong, .ym-acc-body p strong { color: #fff; }

.ym-badge-explainer {
    background: var(--ym-card); border: 1px solid var(--ym-line);
    border-radius: 8px; padding: 10px 12px; margin-bottom: 10px;
}
.ym-be-head { display: flex; align-items: center; gap: 7px; font-size: .76rem; font-weight: 700; color: #fff; margin-bottom: 5px; }
.ym-be-head i { font-size: 15px; flex-shrink: 0; }
.ym-be-where {
    margin-left: auto; flex-shrink: 0; white-space: nowrap;
    font-size: .56rem; font-weight: 600; letter-spacing: .4px; text-transform: uppercase;
    color: var(--ym-blue-l); background: var(--ym-blue-dim);
    border: 1px solid rgba(42,95,216,.35); padding: 2px 7px; border-radius: 4px;
}
.ym-be-desc { font-size: .7rem !important; color: var(--ym-mut) !important; line-height: 1.55 !important; margin: 0 !important; }
.ym-be-desc strong { color: rgba(255,255,255,.88) !important; }
.ym-be-divider { border: none; border-top: 1px solid var(--ym-line); margin: 14px 0 12px; }

/* ── Stats ── */
.ym-stats-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 7px; margin-bottom: 10px; }
.ym-stat-card {
    background: var(--ym-card); border: 1px solid var(--ym-line); border-radius: var(--ym-r);
    height: var(--ym-act-h); padding: 0 10px; display: flex; flex-direction: row; align-items: center; gap: 7px; min-width: 0;
}
.ym-stat-num { font-size: .86rem; font-weight: 700; color: #fff; line-height: 1; flex-shrink: 0; }
.ym-stat-lbl { font-size: .62rem; color: var(--ym-mut); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.ym-join-row {
    display: flex; align-items: center; gap: 7px; height: var(--ym-act-h); padding: 0 10px;
    background: var(--ym-card); border: 1px solid var(--ym-line); border-radius: var(--ym-r);
    font-size: .66rem; color: var(--ym-mut); white-space: nowrap; overflow: hidden;
}
.ym-join-row i { font-size: 13px; color: var(--ym-blue-l); flex-shrink: 0; }
.ym-join-row strong { color: rgba(255,255,255,.85); }

/* ── Sort row (rectangular) ── */
.ym-sort-row { display: flex; gap: 7px; margin-bottom: 12px; flex-wrap: wrap; }
.ym-sort-pill {
    height: calc(var(--ym-act-h) - 6px); padding: 0 13px; border: none; border-radius: var(--ym-r);
    background: var(--ym-btn); color: var(--ym-ink); font-size: .7rem; font-weight: 700; transition: .2s;
}
.ym-sort-pill:hover { background: var(--ym-btn-h); }
.ym-sort-pill:active { transform: scale(.96); }
.ym-sort-pill.active { background: var(--ym-btn-on); }
@media (max-width:490px){ .ym-sort-pill{ font-size:.64rem; padding:0 10px; } }

/* ── Empty state ── */
.ym-no-posts { text-align: center; padding: 34px 16px; color: var(--ym-mut); }
.ym-no-posts i { font-size: 30px; display: block; margin-bottom: 8px; color: rgba(126,166,255,.45); }
.ym-no-posts p { font-size: .76rem; margin: 0; }

/* ── Post rows ── */
.ym-post-row {
    display: flex; align-items: center; gap: 11px; padding: 10px 0;
    border-bottom: 1px solid var(--ym-line);
}
.ym-post-row:last-child { border-bottom: none; }
.ym-post-thumb {
    position: relative; width: 54px; height: 54px; border-radius: 8px; overflow: hidden; flex-shrink: 0;
    background: var(--ym-card2); border: 1px solid var(--ym-line);
    display: flex; align-items: center; justify-content: center;
}
.ym-post-thumb img, .ym-post-thumb video { width: 100%; height: 100%; object-fit: cover; display: block; }
.ym-play-overlay { position: absolute; font-size: 18px; color: rgba(255,255,255,.88); pointer-events: none; }
.ym-text-thumb { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; color: var(--ym-mut); font-size: 20px; }
.ym-post-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.ym-post-cap { font-size: .76rem; font-weight: 600; color: rgba(255,255,255,.88); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ym-post-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: .64rem; color: var(--ym-mut); }
.ym-post-meta i { font-size: 11px; }
.ym-post-meta span { display: inline-flex; align-items: center; gap: 3px; }
.ym-post-date { margin-left: auto; opacity: .75; }
.ym-del-btn {
    width: 32px; height: var(--ym-act-h); border-radius: var(--ym-r);
    border: none; background: var(--ym-btn-on); color: var(--ym-ink);
    font-size: 15px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; padding: 0; transition: .2s;
}
.ym-del-btn:hover { background: var(--ym-btn-on-h); }
.ym-del-btn:active { transform: scale(.94); }

@media (max-width:490px){
    .ym-hero { gap: 10px; margin: 6px 0 14px; }
    .ym-acc-head { padding: 0 10px; font-size: .66rem; }
    .ym-acc-body { padding: 0 6px 8px; }
    .ym-acc-body p, .ym-acc-body ul li { font-size: .66rem; }
    .ym-be-head { font-size: .7rem; }
    .ym-be-desc { font-size: .64rem !important; }
    .ym-stat-card { padding: 0 8px; gap: 5px; }
    .ym-stat-num { font-size: .78rem; }
    .ym-stat-lbl { font-size: .58rem; }
    .ym-join-row { font-size: .6rem; padding: 0 8px; }
    .ym-post-thumb { width: 46px; height: 46px; }
    .ym-post-cap { font-size: .7rem; }
    .ym-post-meta { font-size: .6rem; gap: 6px; }
    .ym-del-btn { width: 28px; font-size: 13px; }
}
@media (max-width:390px){
    .ym-tabs { flex-basis: 140px; }
    .ym-post-thumb { width: 40px; height: 40px; }
    .ym-post-row { gap: 8px; }
    .ym-be-where { display: none; }
}

/* ── About this page ── */
.ym-about {
    margin-top: 16px; padding: 14px 12px 12px;
    background: var(--ym-card); border: 1px solid var(--ym-line); border-radius: 8px;
}
.ym-about-h { display: flex; align-items: center; gap: 7px; font-size: .8rem; font-weight: 700; color: #fff; margin-bottom: 8px; }
.ym-about-h i { font-size: 16px; color: var(--ym-blue-l); }
.ym-about-sub { font-size: .74rem; font-weight: 700; color: var(--ym-blue-l); margin: 14px 0 5px; }
.ym-about p { font-size: .72rem; color: var(--ym-sub); line-height: 1.65; margin: 0 0 6px; }
.ym-about p strong, .ym-about li strong { color: #fff; }
.ym-about ul { list-style: none; padding: 0; margin: 4px 0 6px; }
.ym-about li { display: flex; align-items: flex-start; gap: 7px; font-size: .72rem; color: var(--ym-sub); line-height: 1.6; margin-bottom: 5px; }
.ym-about li i { font-size: 13px; color: var(--ym-blue-l); flex-shrink: 0; margin-top: 2px; }
.ym-about-note {
    display: flex; align-items: flex-start; gap: 7px; margin-top: 12px; padding: 9px 10px;
    border-radius: var(--ym-r); background: var(--ym-blue-dim); border: 1px solid rgba(42,95,216,.35);
    font-size: .68rem; color: var(--ym-sub); line-height: 1.55;
}
.ym-about-note i { font-size: 14px; color: var(--ym-blue-l); flex-shrink: 0; }
@media (max-width:490px){
    .ym-about { padding: 12px 10px 10px; }
    .ym-about-h { font-size: .74rem; }
    .ym-about-sub { font-size: .68rem; }
    .ym-about p, .ym-about li { font-size: .66rem; }
    .ym-about-note { font-size: .62rem; }
}

@media (prefers-reduced-motion:reduce){ #youModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>

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
                    <td class="pim-dt-value accent"><?php echo $pod_category; ?></td>
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
                <img src="<?php echo !empty($group['creator_pic']) ? htmlspecialchars($group['creator_pic']) : 'uploads/me.jpeg'; ?>"
                     onerror="this.src='uploads/me.jpeg';" alt="Creator">
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
                    <img src="<?php echo !empty($adm['profile_pic']) ? htmlspecialchars($adm['profile_pic']) : 'uploads/me.jpeg'; ?>"
                         onerror="this.src='uploads/me.jpeg';" alt="Admin">
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
    --pm-card:rgba(255,255,255,.05); --pm-card2:rgba(255,255,255,.08);
    --pm-line:rgba(255,255,255,.09); --pm-line2:rgba(255,255,255,.14);
    --pm-sub:rgba(255,255,255,.62); --pm-mut:rgba(255,255,255,.45);
    --pm-pad:clamp(9px,3vw,16px);
    --pm-act-h:clamp(25px,7.2vw,30px);
    --pm-r:5px;
    --pm-fs:.8rem;
    --pm-tb-h:54px;

    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--pm-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
}
@media (max-width:490px){ #podInfoModal{ --pm-fs:.74rem; --pm-tb-h:48px; } }
@media (max-width:390px){ #podInfoModal{ --pm-fs:.7rem;  --pm-tb-h:44px; } }
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
    padding: env(safe-area-inset-top) 10px 0;
    background: transparent;
}
.pim-tb-side { flex: 0 0 38px; display: flex; align-items: center; }
.pim-tb-side.right { justify-content: flex-end; }
.pim-x {
    width: 34px; height: 34px; background: none; border: none; color: #fff;
    display: flex; align-items: center; justify-content: center; opacity: .9;
    padding: 0; transition: 0.2s;
}
.pim-x:hover { color: var(--pm-blue-l); opacity: 1; }
.pim-x svg { width: 22px; height: 22px; display: block; }
.pim-topbar-center { flex: 1; min-width: 0; text-align: center; }
.pim-topbar-title {
    font-size: .92rem; font-weight: 700; color: #fff; line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width:490px){ .pim-topbar-title{ font-size:.84rem; } .pim-x{ width:30px; height:30px; } }
@media (max-width:390px){ .pim-topbar-title{ font-size:.8rem; } }

/* ── Scroll area ── */
.pim-body {
    flex: 1; min-height: 0;
    overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch;
    padding: 4px var(--pm-pad) calc(22px + env(safe-area-inset-bottom));
    scrollbar-width: thin; scrollbar-color: rgba(42,95,216,.4) transparent;
}
.pim-body::-webkit-scrollbar { width: 4px; }
.pim-body::-webkit-scrollbar-thumb { background: rgba(42,95,216,.45); border-radius: 99px; }

/* ── Sections ── */
.pim-sec { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--pm-line); }
.pim-sec-first { margin-top: 6px; padding-top: 0; border-top: none; }
.pim-section-head {
    display: flex; align-items: center; gap: 6px; margin-bottom: 9px;
    font-size: .64rem; font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--pm-sub);
}
.pim-section-head i { font-size: 14px; color: var(--pm-blue-l); }
.pim-subtext { font-size: .66rem; color: var(--pm-mut); margin: -3px 0 9px; line-height: 1.5; }
.pim-empty { padding: 14px 0; text-align: center; color: var(--pm-mut); font-size: .72rem; }
.pim-empty i { font-size: 24px; display: block; margin-bottom: 6px; color: rgba(126,166,255,.45); }

/* ── About ── */
.pim-desc-text { font-size: .8rem; line-height: 1.65; color: rgba(255,255,255,.82); word-break: break-word; }
.pim-desc-toggle { color: var(--pm-blue-l); font-weight: 600; cursor: pointer; margin-left: 4px; white-space: nowrap; }
.pim-desc-toggle:hover { color: #fff; }
@media (max-width:490px){ .pim-desc-text{ font-size:.74rem; } }

/* ── Details tables (boxy cards) ── */
.pim-details-table {
    width: 100%; border-collapse: separate; border-spacing: 0;
    background: var(--pm-card); border: 1px solid var(--pm-line); border-radius: 10px; overflow: hidden;
}
.pim-details-table tr + tr td { border-top: 1px solid var(--pm-line); }
.pim-details-table td { padding: 10px 12px; vertical-align: middle; }
.pim-dt-icon { width: 38px; text-align: center; padding-right: 0 !important; }
.pim-dt-icon i { font-size: 17px; color: var(--pm-blue-l); }
.pim-dt-label { font-size: .76rem; color: var(--pm-sub); }
.pim-dt-value { text-align: right; font-size: .78rem; font-weight: 700; color: #fff; word-break: break-word; }
.pim-dt-value.accent { color: var(--pm-blue-l); }
.pim-dt-value.gold   { color: var(--pm-blue-l);}
.pim-dt-value.green  { color: var(--pm-blue-l); }
.pim-dt-value.red    { color: var(--pm-blue-l); }
@media (max-width:490px){
    .pim-details-table td{ padding:8px 10px; }
    .pim-dt-icon{ width:32px; } .pim-dt-icon i{ font-size:15px; }
    .pim-dt-label{ font-size:.68rem; } .pim-dt-value{ font-size:.7rem; }
}

/* ── Creator (boxy card, same as post modal cards) ── */
.pim-creator-row {
    display: flex; align-items: center; gap: 10px; padding: 9px 10px;
    border-radius: 8px; background: var(--pm-card); border: 1px solid var(--pm-line); transition: .2s;
}
.pim-creator-row:hover { border-color: rgba(42,95,216,.55); background: var(--pm-blue-dim); }
.pim-creator-row img {
    width: clamp(34px,9vw,42px); height: clamp(34px,9vw,42px);
    border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 2px solid var(--pm-blue);
}
.pim-creator-text { flex: 1; min-width: 0; }
.pim-creator-label { display: flex; align-items: center; gap: 4px; font-size: .6rem; color: var(--pm-mut); }
.pim-creator-label i { font-size: 10px; color: var(--pm-blue-l); }
.pim-creator-name { font-size: .82rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pim-creator-arrow { margin-left: auto; color: var(--pm-mut); font-size: 20px; flex-shrink: 0; }
@media (max-width:490px){ .pim-creator-name{ font-size:.76rem; } }

/* ── Admins ── */
.pim-people-row { display: flex; gap: 8px; overflow-x: auto; padding: 2px 1px 4px; scrollbar-width: none; }
.pim-people-row::-webkit-scrollbar { display: none; }
.pim-person {
    flex: 0 0 auto; width: 86px; display: flex; flex-direction: column; align-items: center; gap: 4px;
    padding: 10px 6px; border-radius: 8px; background: var(--pm-card); border: 1px solid var(--pm-line); transition: .2s;
}
.pim-person:hover { border-color: rgba(42,95,216,.55); background: var(--pm-blue-dim); }
.pim-person img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid var(--pm-blue); }
.pim-person-name { max-width: 100%; font-size: .66rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pim-person-role {
    font-size: 7.5px; font-weight: 800; letter-spacing: .5px; padding: 1px 6px; border-radius: 4px;
    background: var(--pm-blue); color: var(--pm-on-blue);
}
@media (max-width:490px){ .pim-person{ width:78px; padding:8px 5px; } .pim-person img{ width:36px; height:36px; } }

/* ── Tags (hashtags + popular topics) ── */
.pim-tag-wrap { position: relative; }
.pim-tags-scroll { display: flex; gap: 7px; overflow-x: auto; padding: 2px 1px; scrollbar-width: none; scroll-behavior: smooth; }
.pim-tags-scroll::-webkit-scrollbar { display: none; }
.pim-tag {
    flex: 0 0 auto; display: inline-flex; align-items: center; gap: 2px;
    height: var(--pm-act-h); padding: 0 12px; border-radius: var(--pm-r);
    background: rgba(255,255,255,.1); color: #fff; font-size: .74rem; font-weight: 600; white-space: nowrap;
}
.pim-tag i { font-size: 11px; color: var(--pm-blue-l); }
.pim-tag-official { background: var(--pm-blue); color: var(--pm-on-blue); }
.pim-tag-official i { color: var(--pm-on-blue); }
@media (max-width:490px){ .pim-tag{ font-size:.66rem; padding:0 10px; } }

/* ── Scroll arrows (tags + suggested) ── */
.pim-tag-arrow, .pim-sugg-arrow {
    position: absolute; top: 50%; transform: translateY(-50%);
    width: 26px; height: 26px; padding: 0; border: none; border-radius: var(--pm-r);
    background: rgba(1,2,24,.85); color: #fff; font-size: 18px; z-index: 2;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid var(--pm-line2); transition: .2s;
}
.pim-tag-arrow:hover, .pim-sugg-arrow:hover { background: var(--pm-blue); color: var(--pm-on-blue); border-color: var(--pm-blue); }
.pim-tag-arrow-left, .pim-sugg-arrow-left { left: -4px; }
.pim-tag-arrow-right, .pim-sugg-arrow-right { right: -4px; }
@media (max-width:600px){ .pim-tag-arrow, .pim-sugg-arrow { display: none; } }

/* ── Suggested for you ── */
.pim-sugg-wrap { position: relative; }
.pim-sugg-scroll { display: flex; gap: 8px; overflow-x: auto; padding: 2px 1px 4px; scrollbar-width: none; scroll-behavior: smooth; }
.pim-sugg-scroll::-webkit-scrollbar { display: none; }
.pim-sugg-card {
    flex: 0 0 auto; width: 138px; display: flex; flex-direction: column; overflow: hidden;
    border-radius: 8px; background: var(--pm-card); border: 1px solid var(--pm-line); transition: .2s;
}
.pim-sugg-card:hover { border-color: rgba(42,95,216,.55); }
.pim-sugg-img-wrap { display: block; width: 100%; aspect-ratio: 1/1; background: #0d1030; overflow: hidden; }
.pim-sugg-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pim-sugg-body { padding: 8px; display: flex; flex-direction: column; gap: 2px; }
.pim-sugg-name { font-size: .72rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pim-sugg-members { font-size: .62rem; color: var(--pm-mut); margin-bottom: 6px; }
.pim-sugg-btns { display: flex; gap: 5px; }
.pim-sugg-join-btn, .pim-sugg-remove-btn {
    flex: 1; min-width: 0; height: 24px; padding: 0 6px; border: none; border-radius: var(--pm-r);
    font-size: .64rem; font-weight: 700; line-height: 1; white-space: nowrap; transition: .2s;
}
.pim-sugg-join-btn { background: var(--pm-blue); color: var(--pm-on-blue); }
.pim-sugg-join-btn:hover { background: var(--pm-blue-h); box-shadow: 0 4px 16px var(--pm-blue-glow); }
.pim-sugg-remove-btn { background: rgba(255,255,255,.1); color: #fff; }
.pim-sugg-remove-btn:hover { background: rgba(255,255,255,.2); }
.pim-sugg-join-btn:disabled, .pim-sugg-remove-btn:disabled { opacity: .5; cursor: not-allowed; }
@media (max-width:490px){ .pim-sugg-card{ width:124px; } .pim-sugg-name{ font-size:.66rem; } }

@media (prefers-reduced-motion:reduce){ #podInfoModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
</style>

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
    color:#2088e2; font-family:'Poppins',sans-serif; font-size:14px; font-weight:700;
    display:inline-flex; align-items:center; gap:5px; padding:0; margin:0;
    border:none; background:none; cursor:pointer;
    transition:color .2s ease, opacity .2s ease; white-space:nowrap;
}
.invite-modal-btn:hover{ color:#76c0ff; opacity:.9; transform:none; }
.invite-modal-btn i{ font-size:15px; }
@media (max-width:760px){ .invite-modal-btn{ font-size:12px; gap:4px; } .invite-modal-btn i{ font-size:13px; } }
@media (max-width:480px){ .invite-modal-btn{ font-size:10px; gap:3px; } .invite-modal-btn i{ font-size:11px; } }
@media (max-width:360px){ .invite-modal-btn{ font-size:9px; } .invite-modal-btn i{ font-size:10px; } }
@media (max-width:320px){ .invite-modal-btn{ font-size:8px; } .invite-modal-btn i{ font-size:9px; } }

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
    --im-card:rgba(255,255,255,.05); --im-card2:rgba(255,255,255,.08);
    --im-line:rgba(255,255,255,.09); --im-line2:rgba(255,255,255,.14);
    --im-sub:rgba(255,255,255,.62); --im-mut:rgba(255,255,255,.45);
    --im-pad:clamp(9px,3vw,16px);
    --im-act-h:clamp(25px,7.2vw,30px);
    --im-r:5px;
    --im-fs:.8rem;
    --im-tb-h:54px;

    display:none;
    position:fixed; inset:0;
    z-index:999999999;
    background:linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment:fixed;
    justify-content:center;
    overflow:hidden;
    font-family:'Poppins',sans-serif;
    font-size:var(--im-fs);
    color:#fff;
    -webkit-font-smoothing:antialiased;
}
@media (max-width:490px){ #inviteModal{ --im-fs:.74rem; --im-tb-h:48px; } }
@media (max-width:390px){ #inviteModal{ --im-fs:.7rem;  --im-tb-h:44px; } }
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
.im-topbar { flex-shrink:0; display:flex; align-items:center; min-height:var(--im-tb-h); padding:env(safe-area-inset-top) 10px 0; background:transparent; }
.im-tb-side { flex:0 0 38px; display:flex; align-items:center; }
.im-tb-side.right { justify-content:flex-end; }
.im-x { width:34px; height:34px; background:none; border:none; color:#fff; display:flex; align-items:center; justify-content:center; opacity:.9; padding:0; transition:.2s; }
.im-x:hover { color:var(--im-blue-l); opacity:1; }
.im-x svg { width:22px; height:22px; display:block; }
.im-topbar-center { flex:1; min-width:0; text-align:center; }
.im-topbar-title { font-size:.92rem; font-weight:700; color:#fff; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
@media (max-width:490px){ .im-topbar-title{font-size:.84rem;} .im-x{width:30px;height:30px;} }
@media (max-width:390px){ .im-topbar-title{font-size:.8rem;} }

/* ── scroll body ── */
.im-body {
    flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch;
    padding:4px var(--im-pad) 18px;
    scrollbar-width:thin; scrollbar-color:rgba(42,95,216,.4) transparent;
}
.im-body::-webkit-scrollbar { width:4px; }
.im-body::-webkit-scrollbar-thumb { background:rgba(42,95,216,.45); border-radius:99px; }

/* ── about card ── */
.im-about { display:flex; gap:12px; align-items:flex-start; margin:8px 0 14px; padding:12px; border-radius:10px; background:var(--im-blue-dim); border:1px solid rgba(42,95,216,.35); }
.im-about-ic { width:clamp(34px,9vw,42px); height:clamp(34px,9vw,42px); border-radius:var(--im-r); background:var(--im-blue); color:var(--im-on-blue); display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.im-about-text { min-width:0; flex:1; }
.im-about-label { font-size:.6rem; color:var(--im-mut); }
.im-about-name { font-size:.84rem; font-weight:700; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.im-about-desc { margin:6px 0 0; font-size:.7rem; line-height:1.55; color:var(--im-sub); }
.im-about-desc strong { color:var(--im-blue-l); font-weight:600; }
@media (max-width:490px){ .im-about-name{font-size:.78rem;} .im-about-desc{font-size:.66rem;} }

/* ── search ── */
.im-search { display:flex; align-items:center; gap:9px; height:calc(var(--im-act-h) + 8px); padding:0 12px; border-radius:var(--im-r); border:1px solid var(--im-line2); background:var(--im-card); transition:.2s; }
.im-search:focus-within { border-color:rgba(42,95,216,.85); box-shadow:0 0 0 3px rgba(42,95,216,.18); }
.im-search i { color:var(--im-blue-l); font-size:15px; flex-shrink:0; }
.im-search input { flex:1; min-width:0; height:100%; border:none; background:transparent; color:#fff; font-size:.78rem; -webkit-appearance:none; appearance:none; }
.im-search input::placeholder { color:var(--im-mut); }
@media (max-width:490px){ .im-search input{ font-size:.72rem; } }

/* ── section ── */
.im-sec { margin-top:16px; padding-top:14px; border-top:1px solid var(--im-line); }
.im-sec-h { display:flex; align-items:center; gap:6px; margin-bottom:9px; font-size:.64rem; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--im-sub); }
.im-sec-h i { font-size:14px; color:var(--im-blue-l); }

/* ── user rows ── */
.im-row { display:flex; align-items:center; gap:11px; padding:9px 2px; border-bottom:1px solid var(--im-line); }
.im-row:last-child { border-bottom:none; }
.im-row img { width:clamp(34px,9vw,42px); height:clamp(34px,9vw,42px); border-radius:50%; object-fit:cover; border:2px solid var(--im-blue); flex-shrink:0; background:#0d1030; }
.im-row-info { flex:1; min-width:0; }
.im-name { font-size:.78rem; font-weight:600; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.im-nick { font-size:.64rem; color:var(--im-mut); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }
@media (max-width:490px){ .im-name{font-size:.72rem;} }

/* ── buttons: boxy, blue, black text ── */
.im-btn {
    display:flex; align-items:center; justify-content:center; gap:6px;
    flex:1; min-width:0; height:var(--im-act-h); padding:0 14px;
    border:none; border-radius:var(--im-r); background:var(--im-blue); color:var(--im-on-blue);
    font-size:.79rem; font-weight:700; line-height:1; white-space:nowrap;
    transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.im-btn:hover { background:var(--im-blue-h); box-shadow:0 4px 16px var(--im-blue-glow); }
.im-btn:active { transform:scale(.98); }
.im-btn:disabled { opacity:.55; cursor:not-allowed; box-shadow:none; }
.im-btn i { font-size:14px; }
.im-btn.ghost { background:rgba(255,255,255,.1); color:#fff; backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); box-shadow:none; }
.im-btn.ghost:hover { background:rgba(255,255,255,.2); }
.im-btn.sm { flex:0 0 auto; min-width:68px; padding:0 14px; font-size:.74rem; }
.im-btn.sent { background:var(--im-blue-dim); color:var(--im-blue-l); border:1px solid rgba(42,95,216,.45); box-shadow:none; opacity:1; cursor:default; }
@media (max-width:490px){ .im-btn{font-size:.68rem;padding:0 10px;gap:4px;} .im-btn.sm{font-size:.66rem;min-width:60px;} .im-btn i{font-size:12px;} }

.im-actionbar { flex-shrink:0; display:flex; gap:8px; padding:8px var(--im-pad) calc(10px + env(safe-area-inset-bottom)); }

/* ── empty / error states ── */
.im-state { text-align:center; padding:26px 12px; color:var(--im-mut); font-size:.72rem; line-height:1.55; }
.im-state i { display:block; font-size:28px; margin-bottom:8px; color:rgba(126,166,255,.45); }
.im-state b { display:block; color:#fff; font-size:.78rem; font-weight:600; margin-bottom:3px; }
.im-state.err i, .im-state.err b { color:#ff7a7a; }
.im-state .im-btn { margin:12px auto 0; flex:none; }

/* ── premium skeleton ── */
.im-sk-row { display:flex; align-items:center; gap:11px; padding:9px 2px; border-bottom:1px solid var(--im-line); }
.im-sk-row:last-child { border-bottom:none; }
.im-sk { position:relative; overflow:hidden; background:rgba(255,255,255,.07); border-radius:var(--im-r); }
.im-sk::after { content:""; position:absolute; inset:0; transform:translateX(-100%); background:linear-gradient(90deg,transparent,rgba(126,166,255,.16),transparent); animation:imShimmer 1.3s ease-in-out infinite; }
@keyframes imShimmer { to { transform:translateX(100%); } }
.im-sk.av { width:clamp(34px,9vw,42px); height:clamp(34px,9vw,42px); border-radius:50%; flex-shrink:0; }
.im-sk-lines { flex:1; min-width:0; display:flex; flex-direction:column; gap:6px; }
.im-sk.l1 { height:10px; width:55%; }
.im-sk.l2 { height:8px; width:34%; }
.im-sk.bt { width:68px; height:var(--im-act-h); flex-shrink:0; }

@media (prefers-reduced-motion:reduce){
    #inviteModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; }
    .im-sk::after{ display:none; }
}
</style>

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
        fetch('invite_search.php?q=' + encodeURIComponent(q) + '&group_id=' + _inviteGroupId)
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
        var pic = u.profile_pic || 'uploads/me.jpeg';
        var sent = !!_inviteSent[u.id];
        var id = parseInt(u.id, 10);
        return '<div class="im-row">' +
            '<img src="' + escHtml(pic) + '" alt="" onerror="this.onerror=null;this.src=\'uploads/me.jpeg\'">' +
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

function sendInvite(userId) {
    var btn = imEl('inv-btn-' + userId);
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.textContent = '…';

    fetch('send_invite.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'user_id=' + userId + '&group_id=' + _inviteGroupId
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data && data.ok) {
            _inviteSent[userId] = true;
            btn.className = 'im-btn sm sent';
            btn.innerHTML = '<i class="ri-check-line"></i> Sent';
        } else {
            btn.disabled = false;
            btn.textContent = 'Invite';
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Invite';
    });
}

// Close on Escape
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && _inviteOpen) closeInviteModal();
});
</script>


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
    --rp-card:rgba(255,255,255,.05);
    --rp-line:rgba(255,255,255,.09); --rp-line2:rgba(255,255,255,.14);
    --rp-sub:rgba(255,255,255,.62); --rp-mut:rgba(255,255,255,.45);
    --rp-pad:clamp(9px,3vw,16px);
    --rp-act-h:clamp(25px,7.2vw,30px);
    --rp-r:5px;
    --rp-fs:.8rem;
    --rp-tb-h:54px;

    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999999999;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
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
    #gfPostReportOverlay, #gfPostRptStep1Screen, #gfPostRptStep2Screen { --rp-fs:.74rem; --rp-tb-h:48px; }
}
@media (max-width:390px){
    #gfPostReportOverlay, #gfPostRptStep1Screen, #gfPostRptStep2Screen { --rp-fs:.7rem; --rp-tb-h:44px; }
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
    display: flex; align-items: center; gap: 6px;
    min-height: var(--rp-tb-h);
    padding: env(safe-area-inset-top) 10px 0 var(--rp-pad);
    background: transparent;
}
.gfrpt-topbar-center { flex: 1; min-width: 0; display: flex; flex-direction: column; text-align: left; }
.gfrpt-topbar-title {
    font-size: .92rem; font-weight: 700; color: #fff; line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.gfrpt-tb-side { flex: 0 0 38px; display: flex; align-items: center; justify-content: flex-end; }
.gfrpt-close-btn {
    width: 34px; height: 34px; background: none; border: none; color: #fff;
    display: flex; align-items: center; justify-content: center; opacity: .9;
    padding: 0; transition: 0.2s; flex-shrink: 0;
}
.gfrpt-close-btn:hover { color: var(--rp-blue-l); opacity: 1; }
.gfrpt-close-btn svg { width: 22px; height: 22px; display: block; }
/* sub screens: X on the LEFT, title after it */
.gfrpt-topbar.gfrpt-x-left { padding-left: 8px; gap: 8px; }
@media (max-width:490px){
    .gfrpt-topbar-title{ font-size:.84rem; }
    .gfrpt-close-btn{ width:30px; height:30px; }
}
@media (max-width:390px){ .gfrpt-topbar-title{ font-size:.8rem; } }

/* ═══════════════════════════════════════════════════════════
   SCROLL AREA + SCROLLBAR
   • default (phones / tablets / narrow windows): scrollbar does
     not exist at all — no track, no thumb, no gutter
   • desktop only (wide + mouse): slim dark-blue scrollbar
═══════════════════════════════════════════════════════════ */
.gfrpt-body {
    flex: 1; min-height: 0;
    overflow-y: auto; overflow-x: hidden; overscroll-behavior: contain; -webkit-overflow-scrolling: touch;
    padding: 0 0 18px;
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
    #gfPostRptStep1Screen .gfrpt-body::-webkit-scrollbar { display: block; width: 4px; }
    #gfPostReportOverlay .gfrpt-body::-webkit-scrollbar-track,
    #gfPostRptStep1Screen .gfrpt-body::-webkit-scrollbar-track { background: transparent; }
    #gfPostReportOverlay .gfrpt-body::-webkit-scrollbar-thumb,
    #gfPostRptStep1Screen .gfrpt-body::-webkit-scrollbar-thumb { background: rgba(42,95,216,.45); border-radius: 99px; }
}

.gfrpt-section-label {
    display: flex; align-items: center; gap: 6px;
    padding: 12px var(--rp-pad) 10px;
    font-size: .64rem; font-weight: 600; letter-spacing: .6px;
    text-transform: uppercase; color: var(--rp-sub);
    border-top: 1px solid var(--rp-line);
    border-bottom: 1px solid var(--rp-line);
}

/* ── Category rows (horizontal lines, no bubbles) ── */
.gfrpt-cat-row {
    display: flex; align-items: center; gap: 12px;
    margin: 0; padding: 13px var(--rp-pad);
    background: transparent; border: none; border-bottom: 1px solid var(--rp-line);
    cursor: pointer; transition: background .2s; -webkit-tap-highlight-color: transparent;
}
.gfrpt-cat-row:hover { background: var(--rp-blue-dim); }
.gfrpt-cat-row:active { background: rgba(42,95,216,.26); }
.gfrpt-cat-icon {
    width: 24px; flex-shrink: 0; text-align: center;
    font-size: 20px; color: var(--rp-blue-l);
}
.gfrpt-cat-info { flex: 1; min-width: 0; }
.gfrpt-cat-label { font-size: .82rem; font-weight: 700; color: #fff; }
.gfrpt-cat-sub { font-size: .66rem; color: var(--rp-mut); margin-top: 2px; line-height: 1.45; }
.gfrpt-cat-chevron { font-size: 18px; color: var(--rp-mut); transition: transform .2s, color .2s; flex-shrink: 0; }
.gfrpt-cat-row:hover .gfrpt-cat-chevron { transform: translateX(3px); color: var(--rp-blue-l); }
@media (max-width:490px){
    .gfrpt-cat-row{ gap:10px; padding:12px var(--rp-pad); }
    .gfrpt-cat-icon{ font-size:18px; }
    .gfrpt-cat-label{ font-size:.74rem; }
    .gfrpt-cat-sub{ font-size:.62rem; }
}

/* ── Privacy note (after last option) ── */
.gfrpt-note {
    display: flex; gap: 10px; align-items: flex-start;
    padding: 16px var(--rp-pad) 6px;
}
.gfrpt-note-icon { font-size: 18px; color: var(--rp-blue-l); flex-shrink: 0; width: 24px; text-align: center; margin-top: 1px; }
.gfrpt-note-title { font-size: .74rem; font-weight: 700; color: #fff; margin-bottom: 3px; }
.gfrpt-note-text { font-size: .66rem; color: var(--rp-mut); line-height: 1.6; }
@media (max-width:490px){
    .gfrpt-note-title{ font-size:.7rem; }
    .gfrpt-note-text{ font-size:.62rem; }
}

/* ── Reason rows (horizontal lines, no bubbles) ── */
.gfrpt-reason-row {
    display: flex; align-items: center; gap: 12px;
    margin: 0; padding: 13px var(--rp-pad);
    background: transparent; border: none; border-bottom: 1px solid var(--rp-line);
    cursor: pointer; transition: background .2s; -webkit-tap-highlight-color: transparent;
}
.gfrpt-reason-row:hover { background: rgba(42,95,216,.1); }
.gfrpt-reason-row.selected { background: var(--rp-blue-dim); }
.gfrpt-reason-icon { font-size: 18px; color: var(--rp-blue-l); flex-shrink: 0; width: 24px; text-align: center; }
.gfrpt-reason-text { flex: 1; min-width: 0; font-size: .8rem; font-weight: 500; color: rgba(255,255,255,.88); }
.gfrpt-reason-row.selected .gfrpt-reason-text { color: #fff; font-weight: 600; }
.gfrpt-check {
    width: 20px; height: 20px; border-radius: 5px;
    border: 1.5px solid rgba(255,255,255,.25);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800; color: transparent; flex-shrink: 0; transition: .2s;
}
.gfrpt-reason-row.selected .gfrpt-check { background: var(--rp-blue); border-color: var(--rp-blue); color: var(--rp-on-blue); }
@media (max-width:490px){
    .gfrpt-reason-row{ gap:10px; padding:12px var(--rp-pad); }
    .gfrpt-reason-icon{ font-size:16px; width:22px; }
    .gfrpt-reason-text{ font-size:.72rem; }
    .gfrpt-check{ width:18px; height:18px; font-size:11px; }
}

/* ── Other textarea ── */
.gfrpt-other-wrap { padding: 12px var(--rp-pad) 6px; display: none; }
.gfrpt-other-textarea {
    width: 100%; min-height: 96px; padding: 9px 11px;
    border-radius: var(--rp-r); border: 1px solid var(--rp-line2);
    background: var(--rp-card); color: #fff;
    font-size: .82rem; line-height: 1.6; resize: none; transition: .2s;
    -webkit-appearance: none; appearance: none; overflow-y: auto;
}
.gfrpt-other-textarea::placeholder { color: var(--rp-mut); }
.gfrpt-other-textarea:focus { border-color: rgba(42,95,216,.9); box-shadow: 0 0 0 3px rgba(42,95,216,.2); }
.gfrpt-char-count { text-align: right; font-size: .62rem; color: var(--rp-mut); margin-top: 5px; }
@media (max-width:490px){ .gfrpt-other-textarea{ font-size:.76rem; } }

/* ── Buttons (dark blue, BLACK text) ── */
.gfrpt-footer {
    flex-shrink: 0; display: flex; gap: 8px;
    padding: 8px var(--rp-pad) calc(10px + env(safe-area-inset-bottom));
    border-top: 1px solid var(--rp-line);
}
.gfrpt-submit-btn, .gfrpt-cancel-btn, .gfrpt-done-btn {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    min-width: 0; height: var(--rp-act-h); padding: 0 14px;
    border: none; border-radius: var(--rp-r);
    font-size: .79rem; font-weight: 700; line-height: 1; white-space: nowrap;
    transition: background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.gfrpt-submit-btn { flex: 1.4; background: var(--rp-blue); color: var(--rp-on-blue); order: 2; }
.gfrpt-submit-btn:hover { background: var(--rp-blue-h); box-shadow: 0 4px 16px var(--rp-blue-glow); }
.gfrpt-submit-btn:active, .gfrpt-done-btn:active { transform: scale(.98); }
.gfrpt-submit-btn:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; pointer-events: none; }
.gfrpt-cancel-btn {
    flex: 1; order: 1; background: rgba(255,255,255,.1); color: #fff;
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
}
.gfrpt-cancel-btn:hover { background: rgba(255,255,255,.2); }
.gfrpt-done-btn { background: var(--rp-blue); color: var(--rp-on-blue); padding: 0 40px; }
.gfrpt-done-btn:hover { background: var(--rp-blue-h); box-shadow: 0 4px 16px var(--rp-blue-glow); }
@media (max-width:490px){
    .gfrpt-submit-btn, .gfrpt-cancel-btn, .gfrpt-done-btn{ font-size:.68rem; padding:0 10px; }
    .gfrpt-done-btn{ padding:0 32px; }
}

/* ── Success ── */
.gfrpt-success-body {
    flex: 1; min-height: 0;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 30px 24px 60px; text-align: center;
}
.gfrpt-success-icon {
    width: 64px; height: 64px; border-radius: 50%;
    background: rgba(77,222,138,.12); border: 1px solid rgba(77,222,138,.4);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px; font-size: 28px; color: #4dde8a;
}
.gfrpt-success-title { font-size: .95rem; font-weight: 700; color: #fff; margin-bottom: 8px; }
.gfrpt-success-desc { font-size: .74rem; color: var(--rp-sub); line-height: 1.7; max-width: 290px; margin: 0 auto 22px; }
@media (max-width:490px){
    .gfrpt-success-icon{ width:56px; height:56px; font-size:25px; }
    .gfrpt-success-title{ font-size:.86rem; }
    .gfrpt-success-desc{ font-size:.68rem; }
}

/* ── Toast ── */
.gfrpt-toast {
    position: fixed; bottom: calc(30px + env(safe-area-inset-bottom)); left: 50%;
    transform: translateX(-50%) translateY(10px);
    background: #0d1030; border: 1px solid rgba(42,95,216,.55);
    color: #fff; padding: 8px 16px; border-radius: 5px;
    font-size: .72rem; font-family: 'Poppins', sans-serif; font-weight: 500;
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
    --rp-card:rgba(255,255,255,.05);
    --rp-line:rgba(255,255,255,.09); --rp-line2:rgba(255,255,255,.14);
    --rp-sub:rgba(255,255,255,.62); --rp-mut:rgba(255,255,255,.45);
    --rp-pad:clamp(9px,3vw,16px);
    --rp-act-h:clamp(25px,7.2vw,30px);
    --rp-r:5px; --rp-fs:.8rem; --rp-tb-h:54px;

    display:none; position:fixed; inset:0; z-index:99999999;
    background:linear-gradient(-45deg,#010218,#070924,#070924); background-attachment:fixed;
    justify-content:center; overflow:hidden;
    font-family:'Poppins',sans-serif; font-size:var(--rp-fs); color:#fff; -webkit-font-smoothing:antialiased;
    opacity:0; transform:translateY(18px);
    transition:opacity .25s ease, transform .28s cubic-bezier(.22,1,.36,1);
}
@media (max-width:490px){ #dzGroupReportOverlay,#dzGrpRptStep2Screen{ --rp-fs:.74rem; --rp-tb-h:48px; } }
@media (max-width:390px){ #dzGroupReportOverlay,#dzGrpRptStep2Screen{ --rp-fs:.7rem; --rp-tb-h:44px; } }
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
.dzgrprpt-topbar { flex-shrink:0; display:flex; align-items:center; gap:6px; min-height:var(--rp-tb-h); padding:env(safe-area-inset-top) 10px 0 var(--rp-pad); background:transparent; }
.dzgrprpt-topbar-center { flex:1; min-width:0; display:flex; flex-direction:column; text-align:left; }
.dzgrprpt-topbar-title { font-size:.92rem; font-weight:700; color:#fff; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dzgrprpt-tb-side { flex:0 0 38px; display:flex; align-items:center; justify-content:flex-end; }
.dzgrprpt-close-btn { width:34px; height:34px; background:none; border:none; color:#fff; display:flex; align-items:center; justify-content:center; opacity:.9; padding:0; transition:.2s; flex-shrink:0; }
.dzgrprpt-close-btn:hover { color:var(--rp-blue-l); opacity:1; }
.dzgrprpt-close-btn svg { width:22px; height:22px; display:block; }
@media (max-width:490px){ .dzgrprpt-topbar-title{font-size:.84rem;} .dzgrprpt-close-btn{width:30px;height:30px;} }
@media (max-width:390px){ .dzgrprpt-topbar-title{font-size:.8rem;} }

/* ═══════════════════════════════════════════════════════════
   SCROLL AREA + SCROLLBAR
   • default (phones / tablets / narrow windows): scrollbar does
     not exist at all — no track, no thumb, no gutter
   • desktop only (wide + mouse): slim dark-blue scrollbar
═══════════════════════════════════════════════════════════ */
.dzgrprpt-body { flex:1; min-height:0; overflow-y:auto; overflow-x:hidden; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; padding:0 0 18px; }

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
    #dzGroupReportOverlay .dzgrprpt-body::-webkit-scrollbar { display:block; width:6px; }
    #dzGroupReportOverlay .dzgrprpt-body::-webkit-scrollbar-track { background:transparent; }
    #dzGroupReportOverlay .dzgrprpt-body::-webkit-scrollbar-thumb { background:#2a5fd8; border-radius:99px; }
    #dzGroupReportOverlay .dzgrprpt-body::-webkit-scrollbar-thumb:hover { background:#234fb5; }
}

.dzgrprpt-section-label {
    display:flex; align-items:center; gap:6px;
    padding:12px var(--rp-pad) 10px;
    font-size:.64rem; font-weight:600; letter-spacing:.6px; text-transform:uppercase; color:var(--rp-sub);
    border-top:1px solid var(--rp-line); border-bottom:1px solid var(--rp-line);
}

/* ── Reason rows (horizontal lines, no bubbles) ── */
.dzgrprpt-reason-row {
    display:flex; align-items:center; gap:12px;
    margin:0; padding:13px var(--rp-pad);
    background:transparent; border:none; border-bottom:1px solid var(--rp-line);
    cursor:pointer; transition:background .2s; -webkit-tap-highlight-color:transparent;
}
.dzgrprpt-reason-row:hover { background:rgba(42,95,216,.1); }
.dzgrprpt-reason-row:active { background:rgba(42,95,216,.22); }
.dzgrprpt-reason-row.selected { background:var(--rp-blue-dim); }
.dzgrprpt-reason-icon { font-size:18px; color:var(--rp-blue-l); flex-shrink:0; width:24px; text-align:center; }
.dzgrprpt-reason-text { flex:1; min-width:0; font-size:.8rem; font-weight:500; color:rgba(255,255,255,.88); }
.dzgrprpt-reason-row.selected .dzgrprpt-reason-text { color:#fff; font-weight:600; }
.dzgrprpt-check { width:20px; height:20px; border-radius:5px; border:1.5px solid rgba(255,255,255,.25); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; color:transparent; flex-shrink:0; transition:.2s; }
.dzgrprpt-reason-row.selected .dzgrprpt-check { background:var(--rp-blue); border-color:var(--rp-blue); color:var(--rp-on-blue); }
@media (max-width:490px){
    .dzgrprpt-reason-row{gap:10px;padding:12px var(--rp-pad);}
    .dzgrprpt-reason-icon{font-size:16px;width:22px;}
    .dzgrprpt-reason-text{font-size:.72rem;}
    .dzgrprpt-check{width:18px;height:18px;font-size:11px;}
}

/* ── Other textarea ── */
.dzgrprpt-other-wrap { padding:12px var(--rp-pad) 6px; display:none; }
.dzgrprpt-other-textarea {
    width:100%; min-height:96px; padding:9px 11px;
    border-radius:var(--rp-r); border:1px solid var(--rp-line2);
    background:var(--rp-card); color:#fff;
    font-size:.82rem; line-height:1.6; resize:none; transition:.2s;
    -webkit-appearance:none; appearance:none; overflow-y:auto;
}
.dzgrprpt-other-textarea::placeholder { color:var(--rp-mut); }
.dzgrprpt-other-textarea:focus { border-color:rgba(42,95,216,.9); box-shadow:0 0 0 3px rgba(42,95,216,.2); }
.dzgrprpt-char-count { text-align:right; font-size:.62rem; color:var(--rp-mut); margin-top:5px; }
@media (max-width:490px){ .dzgrprpt-other-textarea{ font-size:.76rem; } }

/* ── Buttons (dark blue, BLACK text) ── */
.dzgrprpt-footer {
    flex-shrink:0; display:flex; gap:8px;
    padding:8px var(--rp-pad) calc(10px + env(safe-area-inset-bottom));
    border-top:1px solid var(--rp-line);
}
.dzgrprpt-submit-btn, .dzgrprpt-cancel-btn, .dzgrprpt-done-btn {
    display:flex; align-items:center; justify-content:center; gap:6px;
    min-width:0; height:var(--rp-act-h); padding:0 14px;
    border:none; border-radius:var(--rp-r);
    font-size:.79rem; font-weight:700; line-height:1; white-space:nowrap;
    transition:background .2s ease, transform .15s ease, box-shadow .2s ease;
}
.dzgrprpt-submit-btn { flex:1.4; order:2; background:var(--rp-blue); color:var(--rp-on-blue); }
.dzgrprpt-submit-btn:hover, .dzgrprpt-done-btn:hover { background:var(--rp-blue-h); box-shadow:0 4px 16px var(--rp-blue-glow); }
.dzgrprpt-submit-btn:active, .dzgrprpt-done-btn:active { transform:scale(.98); }
.dzgrprpt-submit-btn:disabled { opacity:.5; cursor:not-allowed; box-shadow:none; pointer-events:none; }
.dzgrprpt-cancel-btn { flex:1; order:1; background:rgba(255,255,255,.1); color:#fff; backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
.dzgrprpt-cancel-btn:hover { background:rgba(255,255,255,.2); }
.dzgrprpt-done-btn { background:var(--rp-blue); color:var(--rp-on-blue); padding:0 40px; }
@media (max-width:490px){
    .dzgrprpt-submit-btn,.dzgrprpt-cancel-btn,.dzgrprpt-done-btn{font-size:.68rem;padding:0 10px;}
    .dzgrprpt-done-btn{padding:0 32px;}
}

/* ── Success ── */
.dzgrprpt-success-body { flex:1; min-height:0; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:30px 24px 60px; text-align:center; }
.dzgrprpt-success-icon { width:64px; height:64px; border-radius:50%; background:rgba(77,222,138,.12); border:1px solid rgba(77,222,138,.4); display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:28px; color:#4dde8a; }
.dzgrprpt-success-title { font-size:.95rem; font-weight:700; color:#fff; margin-bottom:8px; }
.dzgrprpt-success-desc { font-size:.74rem; color:var(--rp-sub); line-height:1.7; max-width:290px; margin:0 auto 22px; }
@media (max-width:490px){
    .dzgrprpt-success-icon{ width:56px; height:56px; font-size:25px; }
    .dzgrprpt-success-title{ font-size:.86rem; }
    .dzgrprpt-success-desc{ font-size:.68rem; }
}

/* ── Toast ── */
.dzgrprpt-toast { position:fixed; bottom:calc(30px + env(safe-area-inset-bottom)); left:50%; transform:translateX(-50%) translateY(10px); background:#0d1030; border:1px solid rgba(42,95,216,.55); color:#fff; padding:8px 16px; border-radius:5px; font-size:.72rem; font-family:'Poppins',sans-serif; font-weight:500; z-index:999999999; pointer-events:none; box-shadow:0 8px 28px rgba(0,0,0,.65); white-space:nowrap; max-width:92vw; overflow:hidden; text-overflow:ellipsis; opacity:0; transition:opacity .22s ease, transform .22s ease; }
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

<!-- ═══════════════════════════════════════════════════════
     GROUP FEED BOTTOM SHEET (mirrors home.php index sheet)
     ═══════════════════════════════════════════════════════ -->
<div id="gfSheetOverlay" class="idx-sheet-overlay" onclick="closeGfSheet()"></div>
<div id="gfSheetPanel" class="idx-sheet-panel">
    <div class="idx-sheet-handle"></div>
    <div class="idx-sheet-list" id="gfSheetList"></div>
    <button class="idx-sheet-cancel" onclick="closeGfSheet()">Cancel</button>
</div>

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
    border-radius:20px 20px 0 0;
    z-index:99999999;
    max-height:80vh;
    display:flex;flex-direction:column;
    padding:8px 0 max(10px, env(safe-area-inset-bottom));
    transform:translateY(100%);
    transition:transform 0.3s cubic-bezier(0.32,0.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,0.5);
}
.idx-sheet-panel.show{transform:translateY(0);}

.idx-sheet-handle{
    width:34px;height:3.5px;border-radius:999px;
    background:rgba(255,255,255,0.2);
    margin:0 auto 4px;flex-shrink:0;
}

.idx-sheet-list{overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch;}
.idx-sheet-list::-webkit-scrollbar{width:4px;}
.idx-sheet-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:999px;}

.idx-sheet-item{
    display:flex;align-items:center;gap:11px;
    padding:9px 18px;
    color:#fff;text-decoration:none;
    font-size:13px;font-weight:500;
    font-family:Poppins,sans-serif;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background 0.15s ease;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.idx-sheet-item:last-child{border-bottom:none;}
.idx-sheet-item:active{background:rgba(255,255,255,0.06);}
.idx-sheet-item i{font-size:15.5px;color:#2f6fd6;width:18px;text-align:center;flex-shrink:0;}
.idx-sheet-item.report{color:#2f6fd6;}


.idx-sheet-cancel{
    margin:6px 12px 0;
    padding:10px;
    background:rgba(255,255,255,0.06);
    border:none;border-radius:12px;
    color:rgba(255,255,255,0.7);
    font-weight:600;font-size:12.5px;
    font-family:Poppins,sans-serif;
    cursor:pointer;flex-shrink:0;
    -webkit-tap-highlight-color:transparent;
}
.idx-sheet-cancel:active{background:rgba(255,255,255,0.1);}

/* Desktop: center as compact modal instead of bottom sheet */
@media (min-width:791px){
    .idx-sheet-panel{
        left:50%;right:auto;bottom:auto;top:50%;
        width:100%;max-width:320px;
        border-radius:18px;
        transform:translate(-50%,-40%) scale(0.95);
        opacity:0;
        pointer-events:none;
        max-height:none;
    }
    .idx-sheet-panel.show{
        transform:translate(-50%,-50%) scale(1);
        opacity:1;
        pointer-events:all;
    }
    .idx-sheet-handle{display:none;}
}

@media (max-width:790px){
    .idx-sheet-panel{border-radius:18px 18px 0 0;max-height:78vh;padding:7px 0 max(9px, env(safe-area-inset-bottom));}
    .idx-sheet-handle{width:32px;height:3.5px;margin:0 auto 4px;}
    .idx-sheet-item{padding:8px 16px;font-size:12.5px;gap:10px;}
    .idx-sheet-item i{font-size:15px;width:17px;}
    .idx-sheet-cancel{margin:5px 11px 0;padding:9px;font-size:12px;border-radius:11px;}
}
@media (max-width:490px){
    .idx-sheet-panel{border-radius:16px 16px 0 0;max-height:76vh;padding:6px 0 max(8px, env(safe-area-inset-bottom));backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);}
    .idx-sheet-handle{width:30px;height:3px;margin:0 auto 3px;}
    .idx-sheet-item{padding:7px 14px;font-size:12px;gap:9px;}
    .idx-sheet-item i{font-size:14px;width:16px;}
    .idx-sheet-cancel{margin:5px 10px 0;padding:8px;font-size:11.5px;border-radius:10px;}
}
@media (max-width:390px){
    .idx-sheet-panel{border-radius:14px 14px 0 0;max-height:74vh;padding:6px 0 max(7px, env(safe-area-inset-bottom));backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 -8px 30px rgba(0,0,0,0.45);}
    .idx-sheet-handle{width:28px;height:3px;margin:0 auto 3px;}
    .idx-sheet-item{padding:6.5px 12px;font-size:11.5px;gap:8px;}
    .idx-sheet-item i{font-size:13.5px;width:15px;}
    .idx-sheet-cancel{margin:4px 9px 0;padding:7px;font-size:11px;border-radius:9px;}
}
@media (max-width:330px){
    .idx-sheet-panel{border-radius:12px 12px 0 0;max-height:72vh;padding:5px 0 max(6px, env(safe-area-inset-bottom));backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:0 -6px 24px rgba(0,0,0,0.4);}
    .idx-sheet-handle{width:24px;height:2.5px;margin:0 auto 3px;}
    .idx-sheet-list::-webkit-scrollbar{width:3px;}
    .idx-sheet-item{padding:5.5px 10px;font-size:10.5px;gap:7px;}
    .idx-sheet-item i{font-size:12.5px;width:13px;}
    .idx-sheet-cancel{margin:4px 8px 0;padding:6px;font-size:10px;border-radius:8px;}
}
</style>

<script>
/* Permission flags for the delete option — echoed from PHP */
var GF_IS_OWNER  = <?php echo $is_owner ? 'true' : 'false'; ?>;
var GF_IS_ADMIN  = <?php echo $is_admin ? 'true' : 'false'; ?>;
var GF_IS_MEMBER = <?php echo $is_member ? 'true' : 'false'; ?>;
/* NEW — mirrors the $gf_paused_locked pattern used elsewhere on this page:
   paused AND viewer is neither owner nor admin means gated features
   (Invite, Discussions nav link, and now this Discuss button) are locked. */
var GF_PAUSE_LOCKED = <?php echo ($group_is_paused && !($is_owner || $is_admin)) ? 'true' : 'false'; ?>;
var GF_CSRF      = CSRF_TOKEN; // already defined globally in group.php

function _gfSheetHTML(d, flags){
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

    return items.join('');
}

function openGfSheet(memeId){
    var d = _gfdFindPostById(memeId);   // ← was: peuraGroupFeed.find(...)
    if(!d) return;

    var isOwnPost = String(d.userId) === String(gfCurrentUserId);
    var canDelete = GF_IS_OWNER || GF_IS_ADMIN || isOwnPost;

    document.getElementById('gfSheetList').innerHTML = _gfSheetHTML(d, { canDelete: canDelete, isOwnPost: isOwnPost });
    document.getElementById('gfSheetOverlay').classList.add('show');
    document.getElementById('gfSheetPanel').classList.add('show');
    document.body.style.overflow = 'hidden';
}


function closeGfSheet(){
    document.getElementById('gfSheetOverlay').classList.remove('show');
    document.getElementById('gfSheetPanel').classList.remove('show');
    document.body.style.overflow = '';
}

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

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        var panel = document.getElementById('gfSheetPanel');
        if(panel && panel.classList.contains('show')) closeGfSheet();
    }
});

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
        background:'rgba(20,20,25,.95)', border:'1px solid rgba(47,111,214,.45)',
        color:'#fff', padding:'8px 20px', borderRadius:'50px', fontSize:'12px',
        zIndex:'9999999', pointerEvents:'none', boxShadow:'0 8px 24px rgba(0,0,0,.6)',
        transition:'opacity .3s', whiteSpace:'nowrap'
    });
    document.body.appendChild(t);
    setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.remove(); },300); }, 2200);
}
</script>

<!-- MODAL — discover-style full-bleed player, reskinned for group feed -->
<div id="gfModal" onclick="if(event.target===this)closeGFModal()">
    <div id="gfd-stage">
       <button type="button" id="gfdFixedBackBtn" class="gfd-icon-btn gfd-back-btn"
        onclick="event.stopPropagation();gfdHandleBackTap()">
        <i class="ri-arrow-left-s-line"></i>
       </button>
        <div id="gfd-inner"></div>
    </div>

    <div id="gfd-arrows">
        <button type="button" onclick="gfmLoadIndex(_gfmIndex-1)" title="Previous">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button type="button" onclick="gfmLoadIndex(_gfmIndex+1)" title="Next">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>
</div>

<style>
/* ══════════════════════════════════════════════════════════
   GF MODAL — REELS/TIKTOK STYLE FULL-SCREEN PLAYER
   Now ported onto the SAME gapless vertical-scroll engine as the
   discover/CCM modal: #gfd-inner hosts a #gfd-vtrack containing up
   to 3 .gfd-vslide wrappers (prev/current/next), each already fully
   rendered before the person ever swipes. Dragging moves the whole
   track by the raw touch delta, so the neighbor post is visibly
   there under your finger the instant you start dragging — no
   black gap, no rebuild-on-release. On a committed swipe, the
   already-mounted neighbor is promoted to "current" (its media
   element reused as-is, already decoded) — only the now-vacant
   side gets a fresh, silent, paused slide built in the background.
   Chrome (top bar, tags, rail, bottom panel) dims and drifts during
   the drag and snaps to full brightness once a post settles as
   current — identical to CCM.

   THEME: accent colour is dark mid-tone blue #2f6fd6 everywhere.
   The only intentional red left is the social-proof avatars.
   The rail "menu" button is white (matches the other rail icons).
   ══════════════════════════════════════════════════════════ */

#gfModal{
    display:none;
    position:fixed;inset:0;
    z-index:9999999;
    background:#000;
    align-items:center;
    justify-content:center;
}
#gfModal.gfm-open{ display:flex; }

#gfd-stage{
    position:relative;
    width:100%;
    height:100%;
    max-width:480px;
    background:#000;
    overflow:hidden;
    touch-action:pan-y;
    /* forces a clean GPU layer so the border-radius clip
       is recomputed correctly instead of leaking video content */
    transform: translateZ(0);
    -webkit-mask-image: -webkit-radial-gradient(white, black);
}

@media(min-width:791px){
    #gfd-stage{
        height:92vh;
        max-height:940px;
        border-radius:22px;
        box-shadow:0 25px 90px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.06);
    }
}

#gfd-inner{ position:relative; width:100%; height:100%; overflow:hidden; }

/* ── vertical track: holds prev/current/next slides stacked so a
   swipe just translates this one element — nothing is destroyed
   or rebuilt mid-drag. Identical structure to CCM's .ccm-vtrack. ── */
.gfd-vtrack{ position:absolute; inset:0; will-change:transform; }
.gfd-vslide{
    position:absolute; left:0; right:0; top:0;
    height:100%;
    background:#000;
}
.gfd-vslide[data-pos="prev"]{ transform:translateY(calc(-100% - 2px)); }
.gfd-vslide[data-pos="current"]{ transform:translateY(0); }
.gfd-vslide[data-pos="next"]{ transform:translateY(calc(100% + 2px)); }

.gfd-slide{
    position:absolute; inset:0;
    background:#000;
    /* No per-slide transition here — sliding is driven by
       .gfd-vtrack's own transform transition, and chrome dim/drift
       is driven per-element via inline styles from JS. */
}

.gfd-media-box{ width:100%; height:100%; position:relative; background:#000; overflow:hidden; }

.gfd-media-box .gfd-media{
    width:100%; height:100%;
    object-fit:contain;
    background:transparent;
    display:block;
    position:relative;
    z-index:1;
}

/* ══════════════════════════════════════════════════════════
   MULTI-MEDIA GALLERY (horizontal swipe within one post)
   ══════════════════════════════════════════════════════════ */
.gfd-media-gallery{
    display:flex; flex-direction:row;
    width:100%; height:100%;
    transition:transform .28s cubic-bezier(.22,1,.36,1);
}
.gfd-media-item{
    position:relative;
    flex:0 0 100%; width:100%; height:100%;
    background:#000;
}

/* Hover arrows (desktop mouse only) — IDENTICAL to the discover modal.
   Visibility does NOT depend on the (hover:none) media query, which
   some desktop / hybrid / emulated setups report incorrectly and
   which permanently hid the arrows. Instead JS adds .gfd-has-mouse to
   #gfModal the moment a real MOUSE pointer moves over it (touch
   pointers never set it), and the arrows appear while the mouse is
   anywhere over the current slide. Hidden arrows also ignore pointer
   events so they can never eat a touch tap. */
.gfd-media-arrow{
    position:absolute; top:34%; transform:translateY(-50%);
    width:36px; height:36px; border-radius:50%;
    background:rgba(0,0,0,.35);
    display:flex; align-items:center; justify-content:center;
    color:#fff; cursor:pointer; z-index:10;
    opacity:0; pointer-events:none;
    transition:opacity .2s ease, background .2s ease;
    -webkit-tap-highlight-color:transparent;
}
#gfModal.gfd-has-mouse .gfd-slide:hover .gfd-media-arrow{ opacity:1; pointer-events:auto; }
#gfModal.gfd-has-mouse .gfd-media-arrow:hover{ background:rgba(0,0,0,.6); }
.gfd-media-arrow-left{ left:56px; }
.gfd-media-arrow-right{ right:64px; }
.gfd-media-arrow i{ font-size:20px; }

.gfd-media-dashes{
    display:flex; justify-content:center; align-items:center; gap:6px;
    margin-top:18px;
    margin-left:-16px; margin-right:-78px;
    width:calc(100% + 94px);
    padding:0 12px;
    box-sizing:border-box;
}
.gfd-dash{
    flex:1 1 auto; max-width:400px; min-width:6px; height:3px; border-radius:2px;
    background:rgba(255,255,255,.32);
    transition:background .2s ease, transform .2s ease, max-width .2s ease;
}
.gfd-dash.active{
    background:#2f6fd6;
}

@media (max-width:790px){ .gfd-media-dashes{ gap:5px; margin-left:-14px; margin-right:-70px; width:calc(100% + 84px); } }
@media (max-width:490px){ .gfd-media-dashes{ gap:5px; margin-left:-12px; margin-right:-62px; width:calc(100% + 74px); } }
@media (max-width:390px){ .gfd-media-dashes{ margin-left:-10px; margin-right:-56px; width:calc(100% + 66px); } }
@media (max-width:330px){ .gfd-media-dashes{ margin-left:-9px; margin-right:-50px; width:calc(100% + 59px); } }

#gfdFixedBackBtn{
    position:absolute;
    top:14px;
    left:4px;
    z-index:50;
}
@media (max-width:490px){
    #gfdFixedBackBtn{ top:11px; left:3px; }
}

/* ══════════════════════════════════════════════════════════
   VIDEO PROGRESS BAR — GF modal, same design as home.php's
   .ccm-video-progress, renamed to the gfd- prefix.
   ══════════════════════════════════════════════════════════ */
.gfd-video-progress{
    position:absolute; bottom:0; left:0; right:0;
    height:26px;
    z-index:11;
    display:flex;
    align-items:flex-end;
    padding:0 4px 6px;
    box-sizing:border-box;
    touch-action:none;
    cursor:pointer;
}
.gfd-video-progress-track{
    position:relative; width:100%; height:4px;
    border-radius:3px;
    background:rgba(255,255,255,.35);
    transition:height .15s ease;
}
.gfd-video-progress.scrubbing .gfd-video-progress-track{ height:6px; }
.gfd-video-progress-fill{
    position:absolute; left:0; top:0; bottom:0;
    width:0%; border-radius:3px;
    background:linear-gradient(90deg,#2f6fd6,#4f8ae8);
    box-shadow:0 0 6px rgba(47,111,214,.7);
}
.gfd-video-progress-thumb{
    position:absolute; top:50%;
    width:13px; height:13px; border-radius:50%;
    background:#fff; border:2px solid #2f6fd6;
    box-shadow:0 1px 5px rgba(0,0,0,.5);
    transform:translate(-50%,-50%) scale(0);
    transition:transform .15s ease;
    pointer-events:none;
}
.gfd-video-progress.scrubbing .gfd-video-progress-thumb{ transform:translate(-50%,-50%) scale(1); }

/* ══════════════════════════════════════════════════════════
   DOUBLE-TAP LIKE HEART BURST — blue heart + pulse ring + particles
   ══════════════════════════════════════════════════════════ */
.gfd-double-tap-heart{
    position:absolute; z-index:14; pointer-events:none;
    transform:translate(-50%,-50%) scale(0) rotate(0deg);
    opacity:0;
}
.gfd-double-tap-heart i{
    font-size:100px; color:#2f6fd6;
    filter:drop-shadow(0 6px 22px rgba(47,111,214,.65));
    -webkit-text-stroke:1.5px rgba(255,255,255,.35);
}
.gfd-double-tap-heart.show{
    animation: gfdHeartBurst .95s cubic-bezier(.17,.89,.32,1.28) forwards;
}
@keyframes gfdHeartBurst{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(-28deg); opacity:0; }
    14%{  transform:translate(-50%,-50%) scale(1.35) rotate(10deg);  opacity:1; }
    28%{  transform:translate(-50%,-50%) scale(.88)  rotate(-6deg);  opacity:1; }
    42%{  transform:translate(-50%,-50%) scale(1.14) rotate(4deg);   opacity:1; }
    58%{  transform:translate(-50%,-50%) scale(.97)  rotate(-1deg);  opacity:1; }
    75%{  transform:translate(-50%,-50%) scale(1.05) rotate(0deg);   opacity:1; }
    100%{ transform:translate(-50%,-50%) scale(1.15) rotate(0deg) translateY(-46px); opacity:0; }
}

.gfd-heart-ring{
    position:absolute; z-index:13; pointer-events:none;
    width:110px; height:110px;
    margin-left:-55px; margin-top:-55px;
    border-radius:50%;
    border:3px solid rgba(47,111,214,.65);
    transform:scale(.25);
    opacity:0;
}
.gfd-heart-ring.show{
    animation: gfdRingPulse .75s cubic-bezier(.15,.6,.35,1) forwards;
}
@keyframes gfdRingPulse{
    0%{   transform:scale(.25); opacity:.9; border-width:4px; }
    100%{ transform:scale(2.4); opacity:0;  border-width:1px; }
}

.gfd-heart-particle{
    position:absolute; z-index:12; pointer-events:none;
    left:0; top:0;
    transform:translate(-50%,-50%) scale(0);
    opacity:0;
    color:#2f6fd6;
}
.gfd-heart-particle i{ filter:drop-shadow(0 2px 8px rgba(47,111,214,.55)); }
.gfd-heart-particle.show{
    animation: gfdParticleFly .8s cubic-bezier(.2,.7,.3,1) forwards;
}
@keyframes gfdParticleFly{
    0%{   transform:translate(-50%,-50%) scale(0)    rotate(0deg);        opacity:0; }
    12%{  opacity:1; }
    100%{ transform:translate(calc(-50% + var(--tx)), calc(-50% + var(--ty))) scale(1) rotate(var(--tr)); opacity:0; }
}

/* ── SKELETON LOADER ── */
.gfd-skeleton{
    position:absolute; inset:0; z-index:15;
    background:#0c0c10;
    opacity:1;
    transition:opacity .32s ease;
    pointer-events:none;
    overflow:hidden;
}
.gfd-skeleton.gfd-skeleton-hide{ opacity:0; }

.gfd-skel-block{
    position:absolute;
    background:linear-gradient(120deg,#17171b 25%,#232329 37%,#17171b 63%);
    background-size:400% 100%;
    animation:gfdSkelPulse 1.3s ease-in-out infinite;
    border-radius:8px;
}
@keyframes gfdSkelPulse{
    0%{ background-position:100% 50%; }
    100%{ background-position:0% 50%; }
}

.gfd-skel-media{ inset:0; border-radius:0; }
.gfd-skel-avatar{ left:16px; bottom:26px; width:48px; height:48px; border-radius:50%; }
.gfd-skel-line{ left:74px; height:11px; border-radius:5px; }
.gfd-skel-line1{ bottom:53px; width:130px; }
.gfd-skel-line2{ bottom:33px; width:190px; }
.gfd-skel-rail{ right:16px; width:27px; height:27px; border-radius:50%; }
.gfd-skel-rail1{ bottom:26px; }
.gfd-skel-rail2{ bottom:78px; }
.gfd-skel-rail3{ bottom:130px; }
.gfd-skel-rail4{ bottom:182px; }

.gfd-skel-shimmer{
    position:absolute; inset:0; z-index:1;
    background:linear-gradient(100deg,
        rgba(255,255,255,0) 30%,
        rgba(255,255,255,.05) 45%,
        rgba(255,255,255,.12) 50%,
        rgba(255,255,255,.05) 55%,
        rgba(255,255,255,0) 70%);
    background-size:250% 100%;
    animation:gfdShimmerSweep 1.3s ease-in-out infinite;
}
@keyframes gfdShimmerSweep{
    0%{ background-position:150% 0; }
    100%{ background-position:-150% 0; }
}

/* ── TAP-TO-PAUSE FEEDBACK ── */
.gfd-tap-flash{
    position:absolute; top:50%; left:50%;
    transform:translate(-50%,-50%) scale(.6);
    z-index:3; pointer-events:none;
    width:64px; height:64px; border-radius:50%;
    background:rgba(0,0,0,.45);
    display:flex; align-items:center; justify-content:center;
    opacity:0; transition:opacity .2s ease, transform .2s ease;
}
.gfd-tap-flash.show{ opacity:1; transform:translate(-50%,-50%) scale(1); }
.gfd-tap-flash i{ font-size:32px; color:#fff; }

/* ── PERSISTENT PAUSE + MUTE + SEEK CONTROLS ── */
.gfd-paused-controls{
    position:absolute; inset:0; z-index:4;
    display:flex; align-items:center; justify-content:center;
    pointer-events:none;
}
.gfd-paused-stack{
    display:flex; flex-direction:column; align-items:center; gap:14px;
}
.gfd-paused-row{
    display:flex; align-items:center; gap:22px;
}
.gfd-pause-icon{
    width:60px; height:60px; border-radius:50%;
    background:rgba(0,0,0,.45);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.gfd-pause-icon i{ font-size:28px; color:#fff; }
.gfd-mute-btn{
    width:46px; height:46px; border-radius:50%;
    background:rgba(0,0,0,.45); border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    pointer-events:all;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, background .15s ease;
}
.gfd-mute-btn:active{ transform:scale(.9); background:rgba(0,0,0,.6); }
.gfd-mute-btn i{ font-size:20px; color:#fff; }

.gfd-seek-btn{
    width:38px; height:38px; border-radius:50%;
    background:rgba(0,0,0,.4); border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    pointer-events:all;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, background .15s ease;
    flex-shrink:0;
}
.gfd-seek-btn:active{ transform:scale(.88); background:rgba(0,0,0,.58); }
.gfd-seek-btn i{ font-size:17px; color:#fff; }

/* ── CAPTION-ONLY POSTS ── */
.gfd-textonly-bg{
    position:absolute; inset:0; z-index:0;
    background-image:url('uploads/Text.jpg');
    background-size:cover; background-position:center;
}
.gfd-textonly-overlay{
    position:absolute; inset:0; z-index:0;
    background:rgba(0,0,0,.45);
}
.gfd-center-caption-wrap{
    position:absolute; inset:0; z-index:1;
    display:flex; align-items:center; justify-content:center;
    padding:90px 34px 140px;
    text-align:center;
}
.gfd-center-caption-wrap.gfd-caption-overflow{ align-items:flex-start; }
.gfd-center-caption-text{
    color:#fff; font-size:16px; font-weight:500; line-height:1.5;
    text-shadow:0 1px 5px rgba(0,0,0,.4);
    max-height:100%; overflow-y:auto;
    word-break:break-word;
    scrollbar-width:none; -ms-overflow-style:none;
    overscroll-behavior:contain;
    touch-action:pan-y;
    -webkit-overflow-scrolling:touch;
}
.gfd-center-caption-text::-webkit-scrollbar{ display:none; }

/* ── TOP BAR ── */
.gfd-top-bar{
    position:absolute; top:0; left:0; right:0; z-index:8;
    padding:14px 12px 34px 4px;
    background:linear-gradient(to bottom, rgba(0,0,0,.68) 0%, rgba(0,0,0,.3) 60%, transparent 100%);
    pointer-events:none;
}
.gfd-top-bar-row{ display:flex; justify-content:space-between; align-items:center; pointer-events:all; }
.gfd-top-bar > *{ pointer-events:all; }
.gfd-back-btn{ padding-left:2px !important; }

.gfd-icon-btn{
    background:none; border:none; box-shadow:none;
    color:#fff; cursor:pointer; padding:6px;
    display:flex; align-items:center; justify-content:center;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, opacity .15s ease;
}
.gfd-icon-btn:active{ transform:scale(.88); opacity:.7; }
.gfd-icon-btn i{ font-size:30px; filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); }
.gfd-search-btn i{ font-size:24px; }

.gfd-back-group{ display:flex; align-items:center; gap:2px; }
.gfd-brand-label{ color:#fff; font-weight:700; font-size:16px; text-shadow:0 2px 6px rgba(0,0,0,.6); font-family:Poppins,sans-serif; }
.gfd-brand-dot{ color:rgba(255,255,255,.6); font-size:14px; font-weight:700; margin:0 5px; }
.gfd-brand-date{ color:rgba(255,255,255,.75); font-size:12.5px; font-weight:500; text-shadow:0 1px 4px rgba(0,0,0,.6); font-family:Poppins,sans-serif; white-space:nowrap; }

.gfd-top-right-tags{
    position:absolute; top:80px; right:14px; z-index:8;
    display:flex; flex-direction:column; align-items:flex-end; gap:6px;
    pointer-events:none;
}

.gfd-media-counter{
    background:rgba(22,22,24,.82); color:#fff;
    font-size:10px; font-weight:800; letter-spacing:.3px;
    padding:5px 9px; border-radius:6px;
}

.gfd-type-tag{
    display:flex; align-items:center; gap:4px;
    background:rgba(22,22,24,.82); border:none;
    border-radius:6px; padding:5px 9px 5px 7px;
    pointer-events:none;
}
.gfd-type-tag i{ font-size:12.5px; color:#fff; }
.gfd-type-tag span{ font-size:10px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }

.gfd-pod-tag{
    position:absolute; top:80px; left:14px; z-index:8;
    display:flex; align-items:center; gap:5px;
    background:none; border:none;
    max-width:150px;
    pointer-events:none;
}
.gfd-pod-tag i{
    font-size:14px; color:#2f6fd6;
    filter:drop-shadow(0 1px 3px rgba(0,0,0,.6));
    flex-shrink:0;
}
.gfd-pod-tag span{
    font-size:13px; font-weight:700; color:#2f6fd6;
    font-family:Poppins,sans-serif;
    text-shadow:0 1px 3px rgba(0,0,0,.6);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}

.gfd-top-search-btn{
    position:absolute; top:20px; right:10px; z-index:9;
    background:none; border:none; box-shadow:none;
    color:#fff; cursor:pointer; padding:6px;
    display:flex; align-items:center; justify-content:center;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, opacity .15s ease;
}
.gfd-top-search-btn:active{ transform:scale(.88); opacity:.7; }
.gfd-top-search-btn i{ font-size:28px; filter:drop-shadow(0 2px 6px rgba(0,0,0,.7)); }

/* ── RIGHT ACTION RAIL ── */
.gfd-rail{
    position:absolute; right:16px; bottom:26px; z-index:9;
    display:flex; flex-direction:column; align-items:center; gap:20px;
}
.gfd-rail.gfd-rail-raised{ bottom:42px; }
.gfd-rail-btn{
    display:flex; flex-direction:column; align-items:center; gap:5px;
    background:none; border:none; cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.gfd-rail-btn i{
    font-size:23px; color:#fff;
    -webkit-text-stroke:0.6px currentColor;
    filter:drop-shadow(0 2px 5px rgba(0,0,0,.55));
    transition:transform .18s ease, color .18s ease;
}
/* Active state is blue; the "more" (menu) button is plain WHITE like
   the other rail icons (it no longer gets any blue override). */
.gfd-rail-btn.active i{ color:#2f6fd6; }
.gfd-rail-btn.gfd-rail-more,
.gfd-rail-btn.gfd-rail-more i{ color:#fff; }
.gfd-rail-btn:active i{ transform:scale(1.3); }
.gfd-rail-count{
    font-size:12px; font-weight:800;
    -webkit-text-stroke:0.2px currentColor;
    color:#fff;
    text-shadow:0 1px 4px rgba(0,0,0,.75), 0 0 8px rgba(255,255,255,.25);
}

@keyframes gfd-repost-arc-swap {
    0%   { transform: rotate(0deg) scale(1); }
    35%  { transform: rotate(140deg) scale(1.15); }
    50%  { transform: rotate(180deg) scale(1.2); }
    65%  { transform: rotate(220deg) scale(1.15); }
    100% { transform: rotate(360deg) scale(1); }
}
.gfd-rail-btn.arc-swap-active i {
    animation: gfd-repost-arc-swap 0.6s cubic-bezier(.33,1,.68,1);
    transition: none !important;
}

.gfd-rail-msg-pic{
    width:30px; height:30px; object-fit:cover;
    border-radius:10px; border:2px solid #fff;
    box-shadow:0 3px 8px rgba(0,0,0,.55);
    display:block;
}

/* ── BOTTOM INFO PANEL ── */
.gfd-bottom{
    position:absolute; left:0; right:0; bottom:0; z-index:7;
    padding:16px 78px 22px 16px;
    background:linear-gradient(to top, rgba(0,0,0,.88) 0%, rgba(0,0,0,.55) 55%, transparent 100%);
}
.gfd-bottom.gfd-no-rail{ padding-right:16px; }

.gfd-user-row{ display:flex; align-items:center; gap:10px; margin-bottom:5px; }
/* Profile border — darker blue, no glow. */
.gfd-user-pic{
    width:48px; height:48px; border-radius:50%; object-fit:cover;
    border:2.5px solid #2459b5;
    cursor:pointer; flex-shrink:0;
}
.gfd-user-info{ display:flex; flex-direction:column; justify-content:center; gap:0px; min-width:0; flex:1; }
.gfd-user-line1{ display:flex; align-items:center; gap:8px; min-width:0; line-height:1.1; margin-bottom:4px; }
.gfd-username{
    color:#fff; font-weight:700; font-size:14px; cursor:pointer;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-shadow:0 1px 3px rgba(0,0,0,.5); line-height:1.1;
}
.gfd-nickname{ color:rgba(255,255,255,.5); font-size:11.5px; font-weight:500; margin:0; line-height:1.1; }

.gfd-info-switcher{
    position:relative;
    min-height:14px;
}
.gfd-switch-pane{
    position:absolute;
    left:0; top:0; right:0;
    opacity:0;
    pointer-events:none;
    transition:opacity .35s ease;
}
.gfd-switch-pane.active{
    position:relative;
    opacity:1;
    pointer-events:all;
}

.gfd-audio-tag{
    display:flex;
    align-items:center;
    gap:7px;
    max-width:100%;
}
.gfd-audio-icon{
    display:inline-flex;
    align-items:flex-end;
    gap:2px;
    height:11px;
    flex-shrink:0;
}
.gfd-eq-bar{
    width:2.5px;
    border-radius:2px;
    background:rgba(255,255,255,.75);
    display:inline-block;
    transform-origin:bottom;
    animation:gfdEqBounce 0.9s ease-in-out infinite;
}
.gfd-eq-bar:nth-child(1){ height:11px; animation-delay:-0.6s; }
.gfd-eq-bar:nth-child(2){ height:11px; animation-delay:-0.2s; }
.gfd-eq-bar:nth-child(3){ height:11px; animation-delay:-0.9s; }
@keyframes gfdEqBounce{
    0%   { transform:scaleY(0.35); }
    25%  { transform:scaleY(1); }
    50%  { transform:scaleY(0.5); }
    75%  { transform:scaleY(0.85); }
    100% { transform:scaleY(0.35); }
}

.gfd-audio-track{
    position:relative;
    overflow:hidden;
    max-width:230px;
    flex:0 1 auto;
    display:flex;
    align-items:center;
}
.gfd-audio-track-text{
    display:inline-block;
    white-space:nowrap;
    font-size:12.5px;
    font-weight:500;
    line-height:1;
    color:rgba(255,255,255,.7);
}
.gfd-audio-track-text.gfd-marquee{
    animation: gfdAudioMarquee var(--marquee-dur,6s) ease-in-out infinite alternate;
}
@keyframes gfdAudioMarquee{
    0%   { transform:translateX(0); }
    12%  { transform:translateX(0); }
    88%  { transform:translateX(var(--marquee-dist,0px)); }
    100% { transform:translateX(var(--marquee-dist,0px)); }
}

.gfd-social-proof-strip{
    position:absolute; left:16px; bottom:175px; z-index:6;
    display:flex; flex-direction:row-reverse; gap:8px;
    pointer-events:none;
    transition:opacity .22s ease, transform .22s ease;
    opacity:1;
}
.gfd-social-proof-strip.gfd-sp-hidden{
    opacity:0;
    transform:translateY(10px);
}
.gfd-social-proof-strip.gfd-sp-raised{ bottom:195px; }
.gfd-sp-avatar-wrap{ position:relative; pointer-events:all; display:inline-block; text-decoration:none; }
.gfd-sp-avatar-wrap:nth-child(1){ animation:gfdSpFloat 3s ease-in-out infinite; }
.gfd-sp-avatar-wrap:nth-child(2){ animation:gfdSpFloat 3s ease-in-out infinite .4s; }
.gfd-sp-avatar-wrap:nth-child(3){ animation:gfdSpFloat 3s ease-in-out infinite .8s; }
.gfd-sp-avatar-wrap:nth-child(4){ animation:gfdSpFloat 3s ease-in-out infinite 1.2s; }
@keyframes gfdSpFloat{ 0%,100%{ transform:translateY(0); } 50%{ transform:translateY(-6px); } }
/* Social-proof avatars intentionally KEEP their red border. */
.gfd-sp-avatar{
    width:44px; height:44px; border-radius:50%; object-fit:cover;
    border:2.5px solid #ff2b2b; display:block;
    box-shadow:0 4px 12px rgba(0,0,0,.7);
}
.gfd-sp-badge{
    position:absolute; bottom:-2px; right:-2px;
    width:16px; height:16px; border-radius:50%;
    display:flex; align-items:center; justify-content:center; z-index:2;
}
.gfd-sp-badge i{ font-size:8px; color:#fff; line-height:1; }

.gfd-track-btn{
    padding:0; margin:0; border-radius:0;
    border:none; background:transparent;
    color:#2f6fd6; font-size:13px; font-weight:700;
    cursor:pointer; flex-shrink:0; font-family:Poppins,sans-serif;
    text-decoration:underline; text-decoration-color:#2f6fd6;
    text-underline-offset:2px; text-decoration-thickness:2.5px;
    transition:opacity .2s, transform .15s;
    -webkit-tap-highlight-color:transparent;
    position:relative; top:-2px;
}
.gfd-track-btn.following{ background:transparent; border:none; color:#2f6fd6; text-decoration:underline; text-decoration-color:#2f6fd6; }
.gfd-track-btn:active{ transform:scale(.94); opacity:.7; }

.gfd-user-dot{ color:#fff; font-size:15px; flex-shrink:0; }

.gfd-partnership-tag{
    display:inline-flex;align-items:center;gap:6px;
    margin-top:8px;
    padding:5px 10px;
    border-radius:8px;
    background:rgba(0,0,0,0.55);
    color:#2f6fd6;
    font-size:11px;
    font-weight:600;
    max-width:100%;
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
}
.gfd-partnership-tag i{ font-size:13px; flex-shrink:0; }
.gfd-partnership-tag strong{ color:#fff; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.gfd-caption-wrap{ max-width:100%; }
.gfd-caption-wrap.gfd-expanded{
    max-height:34vh;
    overflow-y:auto;
    -webkit-overflow-scrolling:touch;
    touch-action:pan-y;
    overscroll-behavior:contain;
    scrollbar-width:none; -ms-overflow-style:none;
    padding-right:2px;
}
.gfd-caption-wrap.gfd-expanded::-webkit-scrollbar{ display:none; }
.gfd-caption-text{ color:#fff; font-size:13px; line-height:1.5; word-break:break-word; text-shadow:0 1px 2px rgba(0,0,0,.35); display:inline; }
.gfd-more-toggle{
    color:#2a66cc; font-size:inherit; font-weight:800; line-height:inherit;
    -webkit-text-stroke:0.4px #2a66cc;
    text-shadow:0 1px 3px rgba(0,0,0,.85), 0 0 6px rgba(0,0,0,.6);
    cursor:pointer; display:inline; -webkit-tap-highlight-color:transparent;
}
.gfd-ellipsis{ color:#fff; font-weight:500; }

#gfd-hint{
    position:absolute; bottom:8px; left:50%; transform:translateX(-50%);
    color:rgba(255,255,255,.28); font-size:10px; z-index:20;
    pointer-events:none; white-space:nowrap;
}

#gfd-arrows{
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:14px; margin-left:16px; flex-shrink:0;
}
#gfd-arrows button{
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);
    border-radius:50%; width:44px; height:44px;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:background .2s ease, transform .15s ease;
    -webkit-tap-highlight-color:transparent;
}
#gfd-arrows button:hover{ background:rgba(255,255,255,.18); }
#gfd-arrows button:active{ transform:scale(.9); }

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
    background:radial-gradient(circle, rgba(47,111,214,.2) 0%, rgba(47,111,214,0) 70%);
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
.gfd-hidden-close{
    position:absolute;
    top:16px;
    left:6px;
    animation:gfdHiddenSlideDown .4s ease .05s both;
}

/* ── search button (top-right) — same as post's top search button, no bg/border ── */
.gfd-hidden-search-btn{
    position:absolute;
    top:20px;
    right:10px;
    width:auto; height:auto;
    border-radius:0;
    background:none;
    border:none;
    box-shadow:none;
    display:flex; align-items:center; justify-content:center;
    color:#fff;
    padding:6px;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease, opacity .15s ease;
    z-index:2;
    animation:gfdHiddenSlideDown .4s ease .05s both;
}
.gfd-hidden-search-btn i{
    font-size:28px;
    filter:drop-shadow(0 2px 6px rgba(0,0,0,.7));
}
.gfd-hidden-search-btn:active{ transform:scale(.88); opacity:.7; }

/* ── pill row — sits just under the back/search buttons ── */
.gfd-hidden-pill-row{
    position:absolute;
    top:74px;
    left:0; right:0;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-wrap:wrap;
    gap:10px;
    padding:0 20px;
    z-index:2;
    animation:gfdHiddenRise .4s ease .1s both;
}
.gfd-hidden-pill{
    display:flex;
    align-items:center;
    gap:8px;
    flex-shrink:0;
    background:rgba(60,60,60,.4);
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
    border:none;
    color:#fff;
    font-family:Poppins,sans-serif;
    font-size:13px;
    font-weight:700;
    padding:10px 18px;
    border-radius:8px;
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background .2s ease, transform .15s ease;
}
.gfd-hidden-pill i{ font-size:14px; }
.gfd-hidden-pill:hover{ background:rgba(75,75,75,.55); }
.gfd-hidden-pill:active{ transform:scale(.96); }

/* ── central icon ── */
.gfd-hidden-icon{
    width:64px; height:64px; border-radius:50%;
    background:rgba(47,111,214,.14); border:1.5px solid rgba(47,111,214,.4);
    display:flex; align-items:center; justify-content:center;
    font-size:27px; color:#2f6fd6; margin-bottom:16px;
    position:relative;
    animation:gfdHiddenPopIn .45s cubic-bezier(.34,1.56,.64,1) .1s both;
}
/* pulsing ring around the icon */
.gfd-hidden-icon::after{
    content:'';
    position:absolute; inset:-8px;
    border-radius:50%;
    border:1.5px solid rgba(47,111,214,.4);
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
.gfd-hidden-title{
    font-size:17px; font-weight:700; color:#fff; margin-bottom:7px;
    letter-spacing:-.2px;
    animation:gfdHiddenRise .4s ease .18s both;
}
.gfd-hidden-sub{
    font-size:12.5px; color:rgba(255,255,255,.55);
    max-width:260px; line-height:1.55; margin-bottom:24px;
    animation:gfdHiddenRise .4s ease .25s both;
}
@keyframes gfdHiddenRise{
    from{ opacity:0; transform:translateY(10px); }
    to{ opacity:1; transform:translateY(0); }
}
@keyframes gfdHiddenSlideDown{
    from{ opacity:0; transform:translateY(-8px); }
    to{ opacity:1; transform:translateY(0); }
}

/* ── undo button ── */
.gfd-hidden-undo{
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.18);
    color:#fff; font-size:13px; font-weight:700; padding:11px 24px; border-radius:30px;
    cursor:pointer; -webkit-tap-highlight-color:transparent;
    transition:background .2s ease, border-color .2s ease, transform .15s ease, box-shadow .2s ease;
    position:relative;
    animation:gfdHiddenRise .4s ease .32s both;
}
.gfd-hidden-undo:hover{
    background:rgba(47,111,214,.25);
    border-color:rgba(47,111,214,.55);
    box-shadow:0 4px 20px rgba(47,111,214,.3);
    transform:translateY(-1px);
}
.gfd-hidden-undo:active{ transform:scale(.96) translateY(0); }
.gfd-hidden-undo i{
    font-size:15px;
    animation:gfdUndoSpinHint 2.5s ease-in-out infinite;
}
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
    .gfd-hidden-icon{ width:58px; height:58px; font-size:24px; margin-bottom:14px; }
    .gfd-hidden-title{ font-size:16px; }
    .gfd-hidden-sub{ font-size:12px; max-width:240px; margin-bottom:22px; }
    .gfd-hidden-undo{ font-size:12.5px; padding:10px 22px; }

    .gfd-hidden-search-btn{ top:17px; right:8px; }
    .gfd-hidden-search-btn i{ font-size:26px; }
    .gfd-hidden-pill-row{ top:68px; gap:9px; }
}

@media (max-width:490px){
    .gfd-hidden-overlay{ padding:20px; }
    .gfd-hidden-overlay::before{ width:190px; height:190px; }
    .gfd-hidden-close{ top:12px; left:5px; }
    .gfd-hidden-icon{ width:52px; height:52px; font-size:21px; margin-bottom:12px; }
    .gfd-hidden-title{ font-size:15px; }
    .gfd-hidden-sub{ font-size:11.5px; max-width:220px; line-height:1.5; margin-bottom:20px; }
    .gfd-hidden-undo{ font-size:12px; padding:9px 20px; gap:5px; }
    .gfd-hidden-undo i{ font-size:13px; }

    .gfd-hidden-search-btn{ top:15px; right:7px; padding:5px; }
    .gfd-hidden-search-btn i{ font-size:22px; }
    .gfd-hidden-pill-row{ top:60px; gap:7px; }
    .gfd-hidden-pill{ font-size:12px; padding:8px 14px; gap:6px; }
    .gfd-hidden-pill i{ font-size:12.5px; }
}

@media (max-width:330px){
    .gfd-hidden-overlay{ padding:16px; }
    .gfd-hidden-overlay::before{ width:150px; height:150px; }
    .gfd-hidden-close{ top:10px; left:4px; }
    .gfd-hidden-icon{ width:46px; height:46px; font-size:18px; margin-bottom:10px; }
    .gfd-hidden-title{ font-size:14px; }
    .gfd-hidden-sub{ font-size:11px; max-width:190px; line-height:1.45; margin-bottom:16px; }
    .gfd-hidden-undo{ font-size:11.5px; padding:8px 18px; gap:4px; }
    .gfd-hidden-undo i{ font-size:12px; }

    .gfd-hidden-search-btn{ top:12px; right:6px; padding:4px; }
    .gfd-hidden-search-btn i{ font-size:19px; }
    .gfd-hidden-pill-row{ top:52px; gap:6px; }
    .gfd-hidden-pill{ font-size:11px; padding:7px 12px; gap:4px; }
    .gfd-hidden-pill i{ font-size:11px; }
}

.gfd-saved-toast{
    position:fixed;
    left:6px;right:6px;
    bottom:calc(30px + env(safe-area-inset-bottom));
    background:rgba(12,12,28,0.98);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:8px;
    padding:13px 16px;
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;
    z-index:999999998;
    font-family:Poppins,sans-serif;
    transform:translateY(120%);
    opacity:0;
    transition:transform .28s cubic-bezier(.32,.72,0,1), opacity .28s ease;
    box-shadow:0 8px 24px rgba(0,0,0,0.35);
}
.gfd-saved-toast.show{
    transform:translateY(0);
    opacity:1;
}
.gfd-saved-toast-left{
    display:flex;align-items:center;gap:10px;
    color:#fff;font-size:14.5px;font-weight:600;
}
.gfd-saved-toast-check{
    width:20px;height:20px;border-radius:50%;
    background:#2f6fd6;color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-size:12px;flex-shrink:0;
}
.gfd-saved-toast-right{
    display:flex;align-items:center;gap:2px;
    color:#2f6fd6;font-size:14.5px;font-weight:600;
    cursor:pointer;-webkit-tap-highlight-color:transparent;
    flex-shrink:0;
    transition:opacity .15s ease;
}
.gfd-saved-toast-right:active{ opacity:0.7; }
.gfd-saved-toast-right i{ font-size:17px; }

/* 790px — TABLET */
@media (max-width:790px){
    .gfd-saved-toast{
        left:5px;right:5px;
        bottom:calc(26px + env(safe-area-inset-bottom));
        border-radius:7px;
        padding:12px 15px;
    }
    .gfd-saved-toast-left{ font-size:14px;gap:9px; }
    .gfd-saved-toast-check{ width:19px;height:19px;font-size:11.5px; }
    .gfd-saved-toast-right{ font-size:14px; }
    .gfd-saved-toast-right i{ font-size:16px; }
}

/* 490px — LARGE MOBILE */
@media (max-width:490px){
    .gfd-saved-toast{
        left:4px;right:4px;
        bottom:calc(20px + env(safe-area-inset-bottom));
        border-radius:6px;
        padding:11px 14px;
        backdrop-filter:blur(16px);
        -webkit-backdrop-filter:blur(16px);
    }
    .gfd-saved-toast-left{ font-size:13.5px;gap:8px; }
    .gfd-saved-toast-check{ width:18px;height:18px;font-size:11px; }
    .gfd-saved-toast-right{ font-size:13.5px;gap:1px; }
    .gfd-saved-toast-right i{ font-size:15.5px; }
}

/* 390px — STANDARD MOBILE */
@media (max-width:390px){
    .gfd-saved-toast{
        left:3px;right:3px;
        bottom:calc(14px + env(safe-area-inset-bottom));
        border-radius:5px;
        padding:10px 13px;
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        box-shadow:0 6px 20px rgba(0,0,0,0.3);
    }
    .gfd-saved-toast-left{ font-size:12.5px;gap:7px; }
    .gfd-saved-toast-check{ width:17px;height:17px;font-size:10px; }
    .gfd-saved-toast-right{ font-size:12.5px; }
    .gfd-saved-toast-right i{ font-size:14.5px; }
}

/* 330px — COMPACT */
@media (max-width:330px){
    .gfd-saved-toast{
        left:2px;right:2px;
        bottom:calc(10px + env(safe-area-inset-bottom));
        border-radius:4px;
        padding:9px 11px;
        backdrop-filter:blur(12px);
        -webkit-backdrop-filter:blur(12px);
    }
    .gfd-saved-toast-left{ font-size:11.5px;gap:6px; }
    .gfd-saved-toast-check{ width:15px;height:15px;font-size:9px; }
    .gfd-saved-toast-right{ font-size:11.5px; }
    .gfd-saved-toast-right i{ font-size:13.5px; }
}

@media(max-width:790px){ #gfd-arrows{ display:none; } }

/* ══════════════════════════════════════
   DISCUSS BUTTON — blue, rectangular (soft corners, NOT a pill),
   thinner, top-left under the back chevron
   ══════════════════════════════════════ */
.gfd-discuss-wrap{
    position:absolute;
    top:78px; left:14px;
    z-index:8;
    pointer-events:none;
}
.gfd-discuss-btn{
    pointer-events:all;
    display:inline-flex;
    align-items:center;
    gap:5px;
    background:rgba(10,10,14,0.72);
    backdrop-filter:blur(8px);
    -webkit-backdrop-filter:blur(8px);
    border:1px solid rgba(47,111,214,0.45);
    border-radius:7px;
    padding:3px 12px;
    color:#2f6fd6;
    font-family:'Poppins', sans-serif;
    font-size:13px;
    font-weight:700;
    letter-spacing:0.1px;
    line-height:1.35;
    cursor:pointer;
    transition:background 0.2s ease, transform 0.15s ease, border-color 0.2s ease;
    -webkit-tap-highlight-color:transparent;
    white-space:nowrap;
}
.gfd-discuss-btn i{
    font-size:14px;
    flex-shrink:0;
    color:#2f6fd6;
}
.gfd-discuss-btn:hover{
    background:rgba(20,20,26,0.85);
    border-color:rgba(47,111,214,0.75);
}
.gfd-discuss-btn:active{
    transform:scale(0.94);
}

/* ══════════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330
   ══════════════════════════════════════ */
@media (max-width:790px){
    .gfd-discuss-wrap{ top:72px; left:13px; }
    .gfd-discuss-btn{ padding:2.5px 11px; font-size:12px; border-radius:6px; gap:5px; }
    .gfd-discuss-btn i{ font-size:13px; }
}
@media (max-width:490px){
    .gfd-discuss-wrap{ top:65px; left:12px; }
    .gfd-discuss-btn{ padding:2px 10px; font-size:11px; border-radius:6px; gap:4px; }
    .gfd-discuss-btn i{ font-size:12px; }
}
@media (max-width:390px){
    .gfd-discuss-wrap{ top:59px; left:10px; }
    .gfd-discuss-btn{ padding:2px 9px; font-size:10.5px; border-radius:5px; gap:4px; }
    .gfd-discuss-btn i{ font-size:11.5px; }
}
@media (max-width:330px){
    .gfd-discuss-wrap{ top:55px; left:9px; }
    .gfd-discuss-btn{ padding:1.5px 8px; font-size:10px; border-radius:5px; gap:3px; }
    .gfd-discuss-btn i{ font-size:11px; }
}

/* ══════════════════════════════════════════════════════════
   CONNECTED ZEEM — HORIZONTAL LAYER STACK
   #gfd-hstack sits as a full-bleed overlay INSIDE the modal's
   inner container (whatever plays the role of #ccm-inner —
   it must be position:relative), stacked above your vertical
   swipe track. Each .gfd-hlayer is itself a full-bleed absolute
   box so the connected-Zeem slide genuinely overlays and slides
   in on top of the current post, instead of pushing layout
   around in normal document flow.
   ══════════════════════════════════════════════════════════ */
#gfd-hstack{
    position:absolute;
    inset:0;
    z-index:12;
    overflow:hidden;
    pointer-events:none; /* flipped to 'all' via JS once a layer is pushed */
}

.gfd-hlayer{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    background:#000;
    will-change:transform;
}

/* ══════════════════════════════════════════════════════════
   CONNECTED ZEEM PILL — occupies the same bottom-left slot as
   .gfd-social-proof-strip (mutually exclusive with it — a post
   is never both showing social proof and linking to a connected
   Zeem at once). Tapping it should trigger your gfd equivalent
   of ccmOpenConnectedZeem() to slide in the linked post.
   ══════════════════════════════════════════════════════════ */
.gfd-connected-tag{
    position:absolute; left:16px; bottom:175px; z-index:6;
    display:flex; align-items:center; gap:8px;
    padding:4px 13px 4px 10px;
    border-radius:8px;
    background:rgba(20,20,23,.96);
    border:1px solid rgba(20,20,23,.96);
    box-shadow:0 6px 18px rgba(0,0,0,.4);
    cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:background .18s ease, transform .15s ease, box-shadow .18s ease, opacity .22s ease;
    max-width:calc(100% - 32px);
}
.gfd-connected-tag:hover{
    background:rgba(30,30,34,.98);
    border-color:rgba(30,30,34,.98);
    box-shadow:0 8px 22px rgba(0,0,0,.5);
}
.gfd-connected-tag:active{
    transform:scale(.96);
}
.gfd-connected-tag.gfd-sp-raised{ bottom:195px; }
.gfd-connected-tag.gfd-sp-hidden{
    opacity:0;
    transform:translateX(10px);
    pointer-events:none;
}

/* Sits below the CTA pill when a post is both an ad and
   connected — dropped further down so it never touches the
   CTA pill above it. */
.gfd-connected-tag.gfd-connected-below-cta{ bottom:130px; }
.gfd-connected-tag.gfd-connected-below-cta.gfd-sp-raised{ bottom:150px; }

.gfd-connected-icon{
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.gfd-connected-icon i{
    font-size:15px; color:#fff;
}

.gfd-connected-text{
    display:flex; align-items:baseline; gap:5px;
    min-width:0;
    overflow:hidden;
    white-space:nowrap;
}
.gfd-connected-text-main{
    font-size:12.5px; font-weight:700; color:#fff;
    letter-spacing:.1px;
    flex-shrink:0;
}
.gfd-connected-text-dot{
    color:rgba(255,255,255,.4);
    font-size:11px;
    flex-shrink:0;
}
.gfd-connected-text-label{
    font-size:12px; font-weight:500; color:rgba(255,255,255,.72);
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    min-width:0;
}

/* ══════════════════════════════════
   RESPONSIVE — 790 / 490 / 390 / 330
   ══════════════════════════════════ */
@media (max-width:790px){
    .gfd-connected-tag{ left:14px; bottom:162px; padding:3.5px 11px 3.5px 9px; gap:7px; border-radius:7px; }
    .gfd-connected-tag.gfd-sp-raised{ bottom:180px; }
    .gfd-connected-tag.gfd-connected-below-cta{ bottom:117px; }
    .gfd-connected-tag.gfd-connected-below-cta.gfd-sp-raised{ bottom:135px; }
    .gfd-connected-icon i{ font-size:12px; }
    .gfd-connected-text-main{ font-size:11.5px; }
    .gfd-connected-text-dot{ font-size:10px; }
    .gfd-connected-text-label{ font-size:11px; }
}

@media (max-width:490px){
    .gfd-connected-tag{ left:12px; bottom:140px; padding:3px 10px 3px 8px; gap:6px; border-radius:7px; }
    .gfd-connected-tag.gfd-sp-raised{ bottom:156px; }
    .gfd-connected-tag.gfd-connected-below-cta{ bottom:95px; }
    .gfd-connected-tag.gfd-connected-below-cta.gfd-sp-raised{ bottom:111px; }
    .gfd-connected-icon i{ font-size:11px; }
    .gfd-connected-text-main{ font-size:11px; }
    .gfd-connected-text-dot{ font-size:9.5px; }
    .gfd-connected-text-label{ font-size:10.5px; }
}

@media (max-width:390px){
    .gfd-connected-tag{ left:10px; bottom:125px; padding:3px 9px 3px 7px; gap:5.5px; border-radius:6px; }
    .gfd-connected-tag.gfd-sp-raised{ bottom:139px; }
    .gfd-connected-tag.gfd-connected-below-cta{ bottom:80px; }
    .gfd-connected-tag.gfd-connected-below-cta.gfd-sp-raised{ bottom:94px; }
    .gfd-connected-icon i{ font-size:10px; }
    .gfd-connected-text-main{ font-size:10.5px; }
    .gfd-connected-text-dot{ font-size:9px; }
    .gfd-connected-text-label{ font-size:10px; }
}

@media (max-width:330px){
    .gfd-connected-tag{ left:9px; bottom:110px; padding:2.5px 8px 2.5px 6px; gap:5px; border-radius:6px; }
    .gfd-connected-tag.gfd-sp-raised{ bottom:122px; }
    .gfd-connected-tag.gfd-connected-below-cta{ bottom:65px; }
    .gfd-connected-tag.gfd-connected-below-cta.gfd-sp-raised{ bottom:77px; }
    .gfd-connected-icon i{ font-size:9px; }
    .gfd-connected-text-main{ font-size:10px; }
    .gfd-connected-text-dot{ font-size:8.5px; }
    .gfd-connected-text-label{ font-size:9.5px; }
}

/* ══════════════════════════════════════
   RESPONSIVE BREAKPOINTS — 790 / 490 / 390 / 330
   ══════════════════════════════════════ */
@media (max-width:790px){
    .gfd-icon-btn i{ font-size:28px; }
    .gfd-search-btn i{ font-size:22px; }
    .gfd-back-btn i{ font-size:34px; }
    .gfd-brand-label{ font-size:15px; }
    .gfd-brand-date{ font-size:11.5px; }
    .gfd-top-right-tags{ top:73px; right:13px; gap:5px; }
    .gfd-type-tag{ padding:5px 8px 5px 6px; }
    .gfd-type-tag i{ font-size:12px; }
    .gfd-type-tag span{ font-size:9.5px; }
    .gfd-media-counter{ font-size:9.5px; padding:4.5px 8px; }
    .gfd-pod-tag{ top:73px; left:13px; max-width:150px; }
    .gfd-pod-tag i{ font-size:13px; }
    .gfd-pod-tag span{ font-size:12px; }
    .gfd-top-search-btn{ top:17px; right:8px; }
    .gfd-top-search-btn i{ font-size:26px; }
    .gfd-rail{ right:10px; bottom:24px; gap:25px; }
    .gfd-rail.gfd-rail-raised{ bottom:36px; }
    .gfd-rail-btn i{ font-size:25px; }
    .gfd-rail-msg-pic{ width:28px; height:28px; border-radius:9px; }
    .gfd-rail-count{ font-size:12px; }
    .gfd-bottom{ right:0; padding:16px 70px 20px 14px; }
    .gfd-bottom.gfd-no-rail{ padding-right:14px; }
    .gfd-social-proof-strip{ left:14px; bottom:162px; }
    .gfd-social-proof-strip.gfd-sp-raised{ bottom:180px; }
    .gfd-sp-avatar{ width:40px; height:40px; }
    .gfd-user-pic{ width:44px; height:44px; }
    .gfd-nickname{ font-size:11px; }
    .gfd-username{ font-size:13.5px; }
    .gfd-track-btn{ font-size:12px; text-decoration-thickness:2.5px; top:-2px; }
    .gfd-caption-text{ font-size:12px; }
    .gfd-partnership-tag{ font-size:10.5px; padding:4.5px 9px; margin-top:7px; gap:5px; }
    .gfd-partnership-tag i{ font-size:12.5px; }
    .gfd-center-caption-text{ font-size:15px; }
    .gfd-paused-stack{ gap:12px; }
    .gfd-paused-row{ gap:18px; }
    .gfd-pause-icon{ width:54px; height:54px; }
    .gfd-pause-icon i{ font-size:26px; }
    .gfd-mute-btn{ width:48px; height:48px; }
    .gfd-mute-btn i{ font-size:20px; }
    .gfd-seek-btn{ width:36px; height:36px; }
    .gfd-seek-btn i{ font-size:16px; }
    .gfd-skel-avatar{ width:44px; height:44px; }
    .gfd-skel-line{ left:68px; }
    .gfd-skel-line1{ bottom:50px; width:120px; }
    .gfd-skel-line2{ bottom:31px; width:175px; }
    .gfd-media-arrow-left{ left:48px; }
    .gfd-media-arrow-right{ right:56px; }
    .gfd-media-arrow{ width:32px; height:32px; }
    .gfd-media-arrow i{ font-size:18px; }
    .gfd-double-tap-heart i{ font-size:80px; }
    .gfd-audio-icon{ height:10px; }
    .gfd-eq-bar:nth-child(1){ height:4.5px; }
    .gfd-eq-bar:nth-child(2){ height:10px; }
    .gfd-eq-bar:nth-child(3){ height:6.5px; }
    .gfd-audio-track{ max-width:210px; }
    .gfd-audio-track-text{ font-size:10.8px; }
}
@media (max-width:490px){
    .gfd-icon-btn i{ font-size:26px; }
    .gfd-search-btn i{ font-size:20px; }
    .gfd-back-btn i{ font-size:32px; }
    .gfd-brand-label{ font-size:14px; }
    .gfd-brand-date{ font-size:11px; }
    .gfd-top-right-tags{ top:66px; right:12px; gap:5px; }
    .gfd-type-tag{ padding:4.5px 7px 4.5px 5px; }
    .gfd-type-tag i{ font-size:11px; }
    .gfd-type-tag span{ font-size:9px; }
    .gfd-media-counter{ font-size:9px; padding:4px 7px; }
    .gfd-pod-tag{ top:66px; left:12px; max-width:135px; }
    .gfd-pod-tag i{ font-size:12px; }
    .gfd-pod-tag span{ font-size:11px; }
    .gfd-top-search-btn{ top:15px; right:7px; }
    .gfd-top-search-btn i{ font-size:24px; }
    .gfd-rail{ right:9px; bottom:22px; gap:22px; }
    .gfd-rail.gfd-rail-raised{ bottom:34px; }
    .gfd-rail-btn i{ font-size:23px; }
    .gfd-rail-msg-pic{ width:26px; height:26px; border-radius:8px; border-width:1.5px; }
    .gfd-rail-count{ font-size:11.5px; }
    .gfd-bottom{ right:0; padding:13px 62px 18px 12px; }
    .gfd-bottom.gfd-no-rail{ padding-right:12px; }
    .gfd-social-proof-strip{ left:12px; bottom:140px; }
    .gfd-social-proof-strip.gfd-sp-raised{ bottom:156px; }
    .gfd-sp-avatar{ width:37px; height:37px; border-width:2px; }
    .gfd-user-pic{ width:41px; height:41px; border-width:2px; }
    .gfd-nickname{ font-size:10.5px; }
    .gfd-username{ font-size:13px; }
    .gfd-track-btn{ font-size:11.5px; text-decoration-thickness:2.25px; top:-1.5px; }
    .gfd-caption-text{ font-size:11.5px; }
    .gfd-partnership-tag{ font-size:10px; padding:4px 8px; margin-top:6px; gap:5px; }
    .gfd-partnership-tag i{ font-size:12px; }
    .gfd-top-bar{ padding:11px 10px 28px 3px; }
    #gfd-hint{ display:none; }
    .gfd-center-caption-text{ font-size:14px; }
    .gfd-center-caption-wrap{ padding:80px 26px 120px; }
    .gfd-paused-stack{ gap:11px; }
    .gfd-paused-row{ gap:16px; }
    .gfd-pause-icon{ width:50px; height:50px; }
    .gfd-pause-icon i{ font-size:24px; }
    .gfd-mute-btn{ width:44px; height:44px; }
    .gfd-mute-btn i{ font-size:19px; }
    .gfd-seek-btn{ width:33px; height:33px; }
    .gfd-seek-btn i{ font-size:15px; }
    .gfd-skel-avatar{ width:41px; height:41px; left:12px; }
    .gfd-skel-line{ left:63px; }
    .gfd-skel-line1{ bottom:47px; width:110px; }
    .gfd-skel-line2{ bottom:29px; width:160px; }
    .gfd-skel-rail{ width:23px; height:23px; right:13px; }
    .gfd-media-arrow-left{ left:42px; }
    .gfd-media-arrow-right{ right:50px; }
    .gfd-media-arrow{ width:30px; height:30px; }
    .gfd-media-arrow i{ font-size:16px; }
    .gfd-double-tap-heart i{ font-size:70px; }
    .gfd-audio-icon{ height:9px; gap:1.5px; }
    .gfd-eq-bar:nth-child(1){ height:4px; }
    .gfd-eq-bar:nth-child(2){ height:9px; }
    .gfd-eq-bar:nth-child(3){ height:6px; }
    .gfd-audio-track{ max-width:180px; }
    .gfd-audio-track-text{ font-size:10.6px; }
}
@media (max-width:390px){
    .gfd-icon-btn i{ font-size:24px; }
    .gfd-search-btn i{ font-size:19px; }
    .gfd-back-btn i{ font-size:30px; }
    .gfd-brand-label{ font-size:13px; }
    .gfd-brand-date{ font-size:10.5px; }
    .gfd-top-right-tags{ top:60px; right:10px; gap:4px; }
    .gfd-type-tag{ padding:4px 6.5px 4px 5px; }
    .gfd-type-tag i{ font-size:10px; }
    .gfd-type-tag span{ font-size:8.5px; }
    .gfd-media-counter{ font-size:8.5px; padding:3.5px 6px; }
    .gfd-pod-tag{ top:60px; left:10px; max-width:120px; }
    .gfd-pod-tag i{ font-size:11px; }
    .gfd-pod-tag span{ font-size:10.5px; }
    .gfd-top-search-btn{ top:13px; right:6px; }
    .gfd-top-search-btn i{ font-size:22px; }
    .gfd-rail{ right:8px; bottom:20px; gap:20px; }
    .gfd-rail.gfd-rail-raised{ bottom:31px; }
    .gfd-rail-btn i{ font-size:21px; }
    .gfd-rail-msg-pic{ width:24px; height:24px; border-radius:7px; border-width:1.5px; }
    .gfd-rail-count{ font-size:11px; }
    .gfd-bottom{ right:0; padding:11px 56px 16px 10px; }
    .gfd-bottom.gfd-no-rail{ padding-right:10px; }
    .gfd-social-proof-strip{ left:10px; bottom:125px; }
    .gfd-social-proof-strip.gfd-sp-raised{ bottom:139px; }
    .gfd-sp-avatar{ width:35px; height:35px; border-width:2px; }
    .gfd-user-pic{ width:39px; height:39px; border-width:2px; }
    .gfd-nickname{ font-size:10px; }
    .gfd-username{ font-size:12.5px; }
    .gfd-track-btn{ font-size:11px; text-decoration-thickness:2px; top:-1.5px; }
    .gfd-caption-text{ font-size:11px; line-height:1.45; }
    .gfd-partnership-tag{ font-size:9.5px; padding:3.5px 7px; margin-top:5px; gap:4px; }
    .gfd-partnership-tag i{ font-size:11px; }
    .gfd-user-row{ gap:8px; margin-bottom:4px; }
    .gfd-center-caption-text{ font-size:13px; }
    .gfd-center-caption-wrap{ padding:72px 22px 110px; }
    .gfd-paused-stack{ gap:10px; }
    .gfd-paused-row{ gap:14px; }
    .gfd-pause-icon{ width:46px; height:46px; }
    .gfd-pause-icon i{ font-size:22px; }
    .gfd-mute-btn{ width:40px; height:40px; }
    .gfd-mute-btn i{ font-size:18px; }
    .gfd-seek-btn{ width:30px; height:30px; }
    .gfd-seek-btn i{ font-size:14px; }
    .gfd-skel-avatar{ width:39px; height:39px; left:10px; }
    .gfd-skel-line{ left:59px; }
    .gfd-skel-line1{ bottom:44px; width:100px; }
    .gfd-skel-line2{ bottom:27px; width:145px; }
    .gfd-skel-rail{ width:21px; height:21px; right:12px; }
    .gfd-media-arrow-left{ left:38px; }
    .gfd-media-arrow-right{ right:46px; }
    .gfd-media-arrow{ width:28px; height:28px; }
    .gfd-media-arrow i{ font-size:15px; }
    .gfd-double-tap-heart i{ font-size:62px; }
    .gfd-audio-icon{ height:8.5px; gap:1.5px; }
    .gfd-eq-bar:nth-child(1){ height:3.5px; }
    .gfd-eq-bar:nth-child(2){ height:8.5px; }
    .gfd-eq-bar:nth-child(3){ height:5.5px; }
    .gfd-audio-track{ max-width:150px; }
    .gfd-audio-track-text{ font-size:9.6px; }
}
@media (max-width:330px){
    .gfd-icon-btn i{ font-size:22px; }
    .gfd-search-btn i{ font-size:18px; }
    .gfd-back-btn i{ font-size:28px; }
    .gfd-brand-label{ font-size:12px; }
    .gfd-brand-date{ font-size:10px; }
    .gfd-top-right-tags{ top:56px; right:9px; gap:4px; }
    .gfd-type-tag{ padding:3.5px 6px 3.5px 4.5px; }
    .gfd-type-tag i{ font-size:9px; }
    .gfd-type-tag span{ font-size:8px; }
    .gfd-media-counter{ font-size:8px; padding:3px 5.5px; }
    .gfd-pod-tag{ top:56px; left:9px; max-width:105px; }
    .gfd-pod-tag i{ font-size:10px; }
    .gfd-pod-tag span{ font-size:10px; }
    .gfd-top-search-btn{ top:12px; right:6px; }
    .gfd-top-search-btn i{ font-size:20px; }
    .gfd-rail{ right:7px; bottom:18px; gap:17px; }
    .gfd-rail.gfd-rail-raised{ bottom:28px; }
    .gfd-rail-btn i{ font-size:19px; }
    .gfd-rail-msg-pic{ width:22px; height:22px; border-radius:6px; border-width:1.5px; }
    .gfd-rail-count{ font-size:10.5px; }
    .gfd-bottom{ right:0; padding:10px 50px 14px 9px; }
    .gfd-bottom.gfd-no-rail{ padding-right:9px; }
    .gfd-social-proof-strip{ left:9px; bottom:110px; }
    .gfd-social-proof-strip.gfd-sp-raised{ bottom:122px; }
    .gfd-sp-avatar{ width:32px; height:32px; border-width:1.5px; }
    .gfd-user-pic{ width:35px; height:35px; border-width:1.5px; }
    .gfd-nickname{ font-size:9.5px; }
    .gfd-username{ font-size:11.5px; }
    .gfd-track-btn{ font-size:10.5px; text-decoration-thickness:2px; top:-1px; }
    .gfd-caption-text{ font-size:10.5px; line-height:1.4; }
    .gfd-partnership-tag{ font-size:9px; padding:3px 6px; margin-top:4px; gap:4px; }
    .gfd-partnership-tag i{ font-size:10px; }
    .gfd-top-bar{ padding:9px 8px 24px 3px; }
    .gfd-center-caption-text{ font-size:12px; }
    .gfd-center-caption-wrap{ padding:64px 18px 100px; }
    .gfd-paused-stack{ gap:9px; }
    .gfd-paused-row{ gap:12px; }
    .gfd-pause-icon{ width:42px; height:42px; }
    .gfd-pause-icon i{ font-size:20px; }
    .gfd-mute-btn{ width:37px; height:37px; }
    .gfd-mute-btn i{ font-size:17px; }
    .gfd-seek-btn{ width:27px; height:27px; }
    .gfd-seek-btn i{ font-size:13px; }
    .gfd-skel-avatar{ width:35px; height:35px; left:9px; }
    .gfd-skel-line{ left:53px; }
    .gfd-skel-line1{ bottom:41px; width:90px; }
    .gfd-skel-line2{ bottom:25px; width:130px; }
    .gfd-skel-rail{ width:19px; height:19px; right:11px; }
    .gfd-media-arrow-left{ left:34px; }
    .gfd-media-arrow-right{ right:42px; }
    .gfd-media-arrow{ width:26px; height:26px; }
    .gfd-media-arrow i{ font-size:14px; }
    .gfd-double-tap-heart i{ font-size:54px; }
    .gfd-audio-icon{ height:8px; gap:1.5px; }
    .gfd-eq-bar:nth-child(1){ height:3px; }
    .gfd-eq-bar:nth-child(2){ height:8px; }
    .gfd-eq-bar:nth-child(3){ height:5px; }
    .gfd-audio-track{ max-width:130px; }
    .gfd-audio-track-text{ font-size:9px; }
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
    border-radius:20px 20px 0 0;
    z-index:99999998;
    max-height:82vh;
    display:flex;flex-direction:column;
    padding:8px 0 max(10px, env(safe-area-inset-bottom));
    transform:translateY(100%);
    transition:transform .3s cubic-bezier(.32,.72,0,1);
    box-shadow:0 -10px 40px rgba(0,0,0,.5);
    font-family:Poppins,sans-serif;
}
.share-modal-panel.show{transform:translateY(0);}

.share-modal-handle{
    width:34px;height:3.5px;border-radius:999px;
    background:rgba(255,255,255,.2);
    margin:0 auto 6px;flex-shrink:0;
}

.share-modal-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:6px 18px 12px;flex-shrink:0;
}
.share-modal-title{ font-size:15px;font-weight:700;color:#fff; }
.share-modal-close{
    width:28px;height:28px;border-radius:50%;
    background:rgba(255,255,255,.07);border:none;color:rgba(255,255,255,.6);
    display:flex;align-items:center;justify-content:center;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
}
.share-modal-close:hover{background:rgba(47,111,214,.22);color:#2f6fd6;}

.share-apps-row{
    display:flex;gap:14px;overflow-x:auto;
    padding:4px 18px 16px;flex-shrink:0;
    scrollbar-width:none;-ms-overflow-style:none;
}
.share-apps-row::-webkit-scrollbar{display:none;}

.share-app-btn{
    display:flex;flex-direction:column;align-items:center;gap:6px;
    flex:0 0 auto;width:60px;
    background:none;border:none;cursor:pointer;
    -webkit-tap-highlight-color:transparent;
    transition:transform .15s ease;
}
.share-app-btn:active{transform:scale(.92);}

.share-app-icon{
    width:50px;height:50px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:22px;color:#fff;
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
.share-app-icon.more       { background:linear-gradient(135deg,#2f6fd6,#1d4ba3); }

.share-app-label{
    font-size:10.5px;font-weight:600;color:rgba(255,255,255,.65);
    white-space:nowrap;
}

.share-apps-divider{
    display:flex;align-items:center;gap:10px;
    padding:0 18px 10px;flex-shrink:0;
}
.share-apps-divider::before,
.share-apps-divider::after{ content:'';flex:1;height:1px;background:rgba(255,255,255,.08); }
.share-apps-divider span{
    font-size:10.5px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;
    color:rgba(255,255,255,.35);white-space:nowrap;
}

.share-modal-search{
    display:flex;align-items:center;gap:8px;
    margin:0 18px 12px;padding:9px 14px;
    background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.1);
    border-radius:12px;flex-shrink:0;
}
.share-modal-search i{color:rgba(255,255,255,.4);font-size:15px;}
.share-modal-search input{
    flex:1;background:none;border:none;outline:none;
    color:#fff;font-size:13px;font-family:Poppins,sans-serif;
}
.share-modal-search input::placeholder{color:rgba(255,255,255,.3);}

.share-modal-list{
    overflow-y:auto;flex:1;padding:0 8px;
    -webkit-overflow-scrolling:touch;
}
.share-modal-list::-webkit-scrollbar{width:4px;}
.share-modal-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:999px;}

.share-modal-empty{
    text-align:center;padding:40px 20px;color:rgba(255,255,255,.35);font-size:13px;
}

.share-user-row{
    display:flex;align-items:center;gap:12px;
    padding:10px 12px;border-radius:12px;
    cursor:pointer;transition:background .15s;
    -webkit-tap-highlight-color:transparent;
}
.share-user-row:hover{background:rgba(255,255,255,.05);}
.share-user-row img{
    width:42px;height:42px;border-radius:50%;object-fit:cover;
    border:2px solid #2f6fd6;flex-shrink:0;
}
.share-user-name{
    flex:1;color:#fff;font-size:13.5px;font-weight:500;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.share-user-check{
    width:22px;height:22px;border-radius:50%;
    border:2px solid rgba(255,255,255,.25);
    display:flex;align-items:center;justify-content:center;
    font-size:12px;color:transparent;flex-shrink:0;
    transition:all .15s;
}
.share-user-row.selected .share-user-check{
    background:#2f6fd6;border-color:#2f6fd6;color:#fff;
}

.share-modal-footer{
    display:flex;align-items:center;gap:12px;
    padding:12px 18px 6px;flex-shrink:0;
    border-top:1px solid rgba(255,255,255,.06);
    margin-top:6px;
}
.share-modal-selcount{ font-size:12px;color:rgba(255,255,255,.5);flex:1; }
.share-send-btn{
    padding:10px 22px;border-radius:12px;border:none;
    background:#2f6fd6;color:#fff;font-weight:700;font-size:13px;
    cursor:pointer;font-family:Poppins,sans-serif;
    transition:opacity .2s;
}
.share-send-btn:disabled{ opacity:.4;cursor:not-allowed; }
.share-send-btn:not(:disabled):hover{ background:#2559ad; }

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

@media (max-width:790px){
    .share-modal-panel{ max-height:80vh; }
    .share-modal-header{ padding:6px 16px 10px; }
    .share-modal-title{ font-size:14.5px; }
    .share-modal-close{ width:27px;height:27px; }
    .share-apps-row{ gap:12px; padding:4px 16px 14px; }
    .share-app-btn{ width:56px; }
    .share-app-icon{ width:46px;height:46px;font-size:20px; }
    .share-app-label{ font-size:10px; }
    .share-apps-divider{ padding:0 16px 9px; }
    .share-apps-divider span{ font-size:10px; }
    .share-modal-search{ margin:0 16px 11px; padding:8px 13px; }
    .share-modal-search input{ font-size:12.5px; }
    .share-user-row{ padding:9px 11px; gap:11px; }
    .share-user-row img{ width:40px;height:40px; }
    .share-user-name{ font-size:13px; }
    .share-modal-footer{ padding:11px 16px 6px; }
    .share-send-btn{ padding:9px 20px; font-size:12.5px; }
}

@media (max-width:490px){
    .share-modal-panel{ max-height:78vh; }
    .share-modal-header{ padding:6px 14px 9px; }
    .share-modal-title{ font-size:14px; }
    .share-modal-close{ width:26px;height:26px;font-size:14px; }
    .share-apps-row{ gap:10px; padding:4px 14px 12px; }
    .share-app-btn{ width:52px; }
    .share-app-icon{ width:42px;height:42px;font-size:18px; }
    .share-app-label{ font-size:9.5px; }
    .share-apps-divider{ padding:0 14px 8px; }
    .share-apps-divider span{ font-size:9.5px; }
    .share-modal-search{ margin:0 14px 10px; padding:8px 12px; }
    .share-modal-search i{ font-size:14px; }
    .share-modal-search input{ font-size:12px; }
    .share-user-row{ padding:8px 10px; gap:10px; }
    .share-user-row img{ width:38px;height:38px; }
    .share-user-name{ font-size:12.5px; }
    .share-user-check{ width:20px;height:20px;font-size:11px; }
    .share-modal-footer{ padding:10px 14px 6px; gap:10px; }
    .share-modal-selcount{ font-size:11px; }
    .share-send-btn{ padding:8px 18px; font-size:12px; }
}

@media (max-width:390px){
    .share-modal-panel{ max-height:76vh; border-radius:18px 18px 0 0; }
    .share-modal-handle{ width:30px;height:3px; }
    .share-modal-header{ padding:5px 12px 8px; }
    .share-modal-title{ font-size:13.5px; }
    .share-modal-close{ width:25px;height:25px;font-size:13px; }
    .share-apps-row{ gap:9px; padding:4px 12px 11px; }
    .share-app-btn{ width:48px; }
    .share-app-icon{ width:40px;height:40px;font-size:17px; }
    .share-app-label{ font-size:9px; }
    .share-apps-divider{ padding:0 12px 7px; gap:8px; }
    .share-apps-divider span{ font-size:9px; }
    .share-modal-search{ margin:0 12px 9px; padding:7px 11px; border-radius:10px; }
    .share-modal-search i{ font-size:13px; }
    .share-modal-search input{ font-size:11.5px; }
    .share-modal-list{ padding:0 6px; }
    .share-user-row{ padding:7px 9px; gap:9px; border-radius:10px; }
    .share-user-row img{ width:35px;height:35px; }
    .share-user-name{ font-size:12px; }
    .share-user-check{ width:19px;height:19px;font-size:10px; }
    .share-modal-footer{ padding:9px 12px 6px; gap:9px; }
    .share-modal-selcount{ font-size:10.5px; }
    .share-send-btn{ padding:8px 16px; font-size:11.5px; border-radius:10px; }
}

@media (max-width:330px){
    .share-modal-panel{ max-height:74vh; border-radius:16px 16px 0 0; }
    .share-modal-handle{ width:26px;height:3px;margin:0 auto 5px; }
    .share-modal-header{ padding:5px 10px 7px; }
    .share-modal-title{ font-size:13px; }
    .share-modal-close{ width:23px;height:23px;font-size:12px; }
    .share-apps-row{ gap:7px; padding:3px 10px 9px; }
    .share-app-btn{ width:44px; }
    .share-app-icon{ width:36px;height:36px;font-size:15px; }
    .share-app-label{ font-size:8.5px; }
    .share-apps-divider{ padding:0 10px 6px; gap:7px; }
    .share-apps-divider span{ font-size:8.5px; }
    .share-modal-search{ margin:0 10px 8px; padding:6px 10px; border-radius:9px; }
    .share-modal-search i{ font-size:12px; }
    .share-modal-search input{ font-size:11px; }
    .share-modal-list{ padding:0 5px; }
    .share-user-row{ padding:6px 8px; gap:8px; border-radius:9px; }
    .share-user-row img{ width:32px;height:32px;border-width:1.5px; }
    .share-user-name{ font-size:11px; }
    .share-user-check{ width:18px;height:18px;font-size:9px;border-width:1.5px; }
    .share-modal-footer{ padding:8px 10px 5px; gap:8px; }
    .share-modal-selcount{ font-size:10px; }
    .share-send-btn{ padding:7px 14px; font-size:11px; border-radius:9px; }
}

/* ── nudges: rail 1% in from the right edge, Discuss shifted left ── */
.gfd-rail{ margin-right:1%; }
.gfd-discuss-wrap{ margin-left:-4px; } /* Discuss moved a bit left (all sizes) */

/* Discuss — icon removed at ALL sizes */
.gfd-discuss-btn{ gap:0; }
.gfd-discuss-btn i{ display:none; }

/* Discuss — NORMAL (desktop) view only: a bit thicker than the small version, thinner than the original */
@media (min-width:791px){
    .gfd-discuss-btn{ font-size:12px; padding:3.5px 11px; border-radius:6px; line-height:1.4; }
}
</style>


<div id="shareModalOverlay" class="share-modal-overlay" onclick="closeShareModal()"></div>
<div id="shareModalPanel" class="share-modal-panel">
    <div class="share-modal-handle"></div>

    <div class="share-modal-header">
        <span class="share-modal-title">Share to</span>
        <button class="share-modal-close" onclick="closeShareModal()"><i class="ri-close-line"></i></button>
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
        <button type="button" class="share-app-btn" id="shareNativeBtn" onclick="shareViaNative()" style="display:none;">
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

<script>
/* ════════════════════════════════════════════════════════════
   GF MODAL JS — ported onto the exact same gapless track engine,
   chrome dim/drift, and per-post state-persistence model as the
   discover/CCM modal. Data source (peuraGroupFeed), endpoints
   (group.php's ajax_like/ajax_repost/ajax_favorite/toggle_follow),
   and public function names (openGFModal, closeGFModal,
   gfmLoadIndex, gfmToggleTrack/gfToggleTrack) are unchanged so
   every existing onclick elsewhere on the page keeps working.
   Intentionally NOT ported: CCM's cross-page IPV/dropdown-grid
   sync (window.ipvSyncActionCount, _gSyncDropdownOnAction) — GF
   posts don't live in profile.php's grids, so there's nothing on
   this page for that sync to update.

   THIS VERSION: multimedia hover arrows now work EXACTLY like the
   discover modal (real-mouse detection via .gfd-has-mouse on
   #gfModal + the same nav-step lock), and the rail menu button is
   white instead of blue.
   ════════════════════════════════════════════════════════════ */
var _gfmIndex            = 0;
var _gfmMemeId            = null;
var _gfmMediaIndex        = 0;
var _gfdCaptionFullHtml   = '';
var _gfdCaptionTruncHtml  = '';
var _gfdCaptionExpanded   = false;
var _gfdInfoSwitchTimer   = null;
var _gfdVSwipeLocked      = false;
var _gfdHStack             = [];
var _gfdConnectedCache     = {};
var _gfdConnectedFetchLock = false;

/* Per-post pause state, so returning to a post you manually paused
   before scrolling away keeps it paused instead of silently
   resuming audio/video behind a stale "paused" overlay. */
var _gfdPausedMemeIds = {};

/* Per-post horizontal media (gallery) index, keyed by post id. */
var _gfdMediaIndexByPost = {};

/* Per-post attached-soundtrack playback position, keyed by the
   post's own id — NOT the audio file URL — so two different posts
   sharing the same track file stay independent, and returning to a
   post resumes its track from where it left off. */
var _gfdTrackPositions = {};

/* gfCurrentUserId, gfGroupId, CSRF_TOKEN, peuraGroupFeed are already
   declared earlier on this page — reused here, not redeclared. */

/* ── helpers ── */
function _gfdFormatDate(t){
    if(!t) return '';
    var d = new Date(t.replace(' ','T'));
    if(isNaN(d)) return t;
    var mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return d.getDate()+' '+mo[d.getMonth()]+' '+d.getFullYear()+' · '+
           String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}
function _gfdTruncateName(name){
    if(!name) return '';
    return name.length > 15 ? name.slice(0,15)+'....' : name;
}
function _gfdTypeTagInfo(rawType){
    var type = (rawType||'').toLowerCase();
    var map = {
        image:{ icon:'ri-file-copy-line', label:'Photo' },
        video:{ icon:'ri-vidicon-line',   label:'Video' },
        gif:{ icon:'ri-file-gif-line',    label:'GIF'   },
        text: { icon:'ri-file-text-line', label:'Text'  }
    };
    return map[type] || { icon:'ri-file-copy-line', label:'Photo' };
}

function _gfdLinkify(caption){
    return (caption||'')
        .replace(/#([a-zA-Z0-9_]+)/g, function(match, tag){
            var mq = encodeURIComponent('#'+tag);
            return '<a href="search.php?focus=1&mq='+mq+
                   '" onclick="event.stopPropagation()" style="color:#6366f1;font-weight:600;text-decoration:none;"'+
                   ' onmouseover="this.style.textDecoration=\'underline\'"'+
                   ' onmouseout="this.style.textDecoration=\'none\'">'+match+'</a>';
        })
        .replace(/@([a-zA-Z0-9_]+)/g, function(match, username){
            return '<a href="profile.php?username='+encodeURIComponent(username)+
                   '" onclick="event.stopPropagation()" style="color:#2f6fd6;font-weight:600;text-decoration:none;"'+
                   ' onmouseover="this.style.textDecoration=\'underline\'"'+
                   ' onmouseout="this.style.textDecoration=\'none\'">'+match+'</a>';
        });
}

function _gfdEscapeHtml(str){
    if(!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}


/* Small toast helper, falls back gracefully to whatever toast
   mechanism this page already exposes elsewhere. */
function gfdToast(msg){
    if (typeof showToast === 'function') { showToast(msg); return; }
    if (typeof gToast === 'function') { gToast(msg); return; }
    console.log(msg);
}

/* Consolidates the full/truncated caption HTML for a post — used
   both when rendering a slide and when activating it as current
   (so _gfdCaptionFullHtml/_gfdCaptionTruncHtml, read by the expand
   toggles, always match whichever post just became current). */
function _gfdComputeCaptionHtml(p){
    var media = (Array.isArray(p.media) && p.media.length) ? p.media.slice() : [{file:p.file, type:p.type}];
    var mediaSet = ['image','video','gif'];
    var firstType = (media[0].type || '').toLowerCase();
    var isTextOnly = media.length === 1 && mediaSet.indexOf(firstType) === -1;

    var captionHtml = _gfdLinkify(p.caption || '');
    var full  = captionHtml;
    var trunc = captionHtml;

    if(isTextOnly){
        var cWords = (p.caption || '').trim().split(/\s+/).filter(Boolean);
        if(cWords.length > 40){
            var cTrunc = cWords.slice(0, 40).join(' ');
            trunc = _gfdLinkify(cTrunc)+
                '<span class="gfd-more-toggle" onclick="event.stopPropagation();gfdToggleCenterCaption()">'+
                    '<span class="gfd-ellipsis">...</span>more'+
                '</span>';
        }
    } else {
        var words = (p.caption || '').trim().split(/\s+/).filter(Boolean);
        if(words.length > 10){
            var truncPlain = words.slice(0, 10).join(' ');
            trunc = _gfdLinkify(truncPlain)+
                '<span class="gfd-more-toggle" onclick="event.stopPropagation();gfdToggleCaption()">'+
                    '<span class="gfd-ellipsis">...</span>more'+
                '</span>';
        }
    }
    return { full: full, trunc: trunc, isTextOnly: isTextOnly };
}

/* ── caption expand/collapse — scoped to the CURRENT slide via
   document.getElementById('gfd-slide'), which is unique because
   only the current position's inner .gfd-slide ever carries that
   id (prev/next slides' inner elements have no id). This matters
   now that up to 3 slides are mounted simultaneously. ── */
function gfdUpdateSocialProofVisibility(){
    var slideEl = document.getElementById('gfd-slide');
    if(!slideEl) return;
    ['#gfd-social-proof', '#gfd-cta-pill', '#gfd-connected-tag'].forEach(function(sel){
        var el = slideEl.querySelector(sel);
        if(el) el.classList.toggle('gfd-sp-hidden', _gfdCaptionExpanded);
    });
}

function _gfdIsInsideExpandedCaption(target){
    if(!_gfdCaptionExpanded || !target || !target.closest) return false;
    return !!(target.closest('.gfd-caption-wrap') || target.closest('.gfd-center-caption-wrap'));
}

function _gfdCheckCaptionOverflow(slide){
    if(!slide) return;
    var wrap = slide.querySelector('.gfd-center-caption-wrap');
    var text = slide.querySelector('.gfd-center-caption-text');
    if(!wrap || !text) return;
    var isOverflowing = text.scrollHeight > wrap.clientHeight;
    wrap.classList.toggle('gfd-caption-overflow', isOverflowing);
}

function gfdToggleCaption(){
    var slideEl = document.getElementById('gfd-slide');
    if(!slideEl) return;
    var textEl = slideEl.querySelector('#gfd-caption-text');
    var wrapEl = slideEl.querySelector('#gfd-caption-wrap');
    if(!textEl) return;
    _gfdCaptionExpanded = !_gfdCaptionExpanded;
    if(_gfdCaptionExpanded){
        textEl.innerHTML = _gfdCaptionFullHtml+
            ' <span class="gfd-more-toggle" onclick="event.stopPropagation();gfdToggleCaption()"> see less</span>';
        if(wrapEl) wrapEl.classList.add('gfd-expanded');
    } else {
        textEl.innerHTML = _gfdCaptionTruncHtml;
        if(wrapEl){
            wrapEl.classList.remove('gfd-expanded');
            wrapEl.scrollTop = 0;
        }
    }
    gfdUpdateSocialProofVisibility();
}

function gfdToggleCenterCaption(){
    var slideEl = document.getElementById('gfd-slide');
    if(!slideEl) return;
    var textEl = slideEl.querySelector('#gfd-center-caption-text');
    var wrapEl = slideEl.querySelector('#gfd-center-caption-wrap');
    if(!textEl) return;
    _gfdCaptionExpanded = !_gfdCaptionExpanded;
    if(_gfdCaptionExpanded){
        textEl.innerHTML = _gfdCaptionFullHtml+
            ' <span class="gfd-more-toggle" onclick="event.stopPropagation();gfdToggleCenterCaption()"> see less</span>';
    } else {
        textEl.innerHTML = _gfdCaptionTruncHtml;
        textEl.scrollTop = 0;
    }
    gfdUpdateSocialProofVisibility();
    var slide = wrapEl ? wrapEl.closest('.gfd-slide') : null;
    if(slide) _gfdCheckCaptionOverflow(slide);
}

/* ── skeleton ── */
function _gfdSkeletonHtml(){
    return '<div class="gfd-skeleton" id="gfd-skeleton">'+
        '<div class="gfd-skel-block gfd-skel-media"></div>'+
        '<div class="gfd-skel-block gfd-skel-avatar"></div>'+
        '<div class="gfd-skel-block gfd-skel-line gfd-skel-line1"></div>'+
        '<div class="gfd-skel-block gfd-skel-line gfd-skel-line2"></div>'+
        '<div class="gfd-skel-block gfd-skel-rail gfd-skel-rail1"></div>'+
        '<div class="gfd-skel-block gfd-skel-rail gfd-skel-rail2"></div>'+
        '<div class="gfd-skel-block gfd-skel-rail gfd-skel-rail3"></div>'+
        '<div class="gfd-skel-block gfd-skel-rail gfd-skel-rail4"></div>'+
        '<div class="gfd-skel-shimmer"></div>'+
    '</div>';
}
function gfdHideSkeleton(root){
    var sk = root ? root.querySelector('#gfd-skeleton') : document.getElementById('gfd-skeleton');
    if(!sk) return;
    sk.classList.add('gfd-skeleton-hide');
    setTimeout(function(){ if(sk && sk.parentNode) sk.remove(); }, 340);
}

/* ── audio title marquee ── */
function _gfdInitAudioMarquee(slide){
    if(!slide) return;
    var track = slide.querySelector('#gfd-audio-track');
    var text  = slide.querySelector('#gfd-audio-track-text');
    if(!track || !text) return;
    requestAnimationFrame(function(){
        var overflow = text.scrollWidth - track.clientWidth;
        if(overflow > 4){
            text.classList.add('gfd-marquee');
            text.style.setProperty('--marquee-dist', (-overflow - 6)+'px');
            var dur = Math.max(4.5, Math.min(14, overflow / 22));
            text.style.setProperty('--marquee-dur', dur+'s');
        }
    });
}

/* ── nickname / audio-title switcher ── */
function _gfdInitInfoSwitcher(slide){
    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }
    if(!slide) return;
    var switcher = slide.querySelector('#gfd-info-switcher');
    if(!switcher) return;
    var panes = switcher.querySelectorAll('.gfd-switch-pane');
    if(panes.length < 2) return;

    var activeIdx = 0;
    panes.forEach(function(p, i){ p.classList.toggle('active', i === 0); });

    _gfdInfoSwitchTimer = setInterval(function(){
        panes[activeIdx].classList.remove('active');
        activeIdx = (activeIdx + 1) % panes.length;
        panes[activeIdx].classList.add('active');
    }, 5000);
}

/* ── attached soundtrack playback (per-post-id position memory) ── */
function _gfdEnsureSoundtrackEl(){
    var stage = document.getElementById('gfd-stage');
    if(!stage) return null;
    var el = document.getElementById('gfdSoundtrackPlayer');
    if(!el){
        el = document.createElement('audio');
        el.id = 'gfdSoundtrackPlayer';
        el.loop = true;
        el.style.display = 'none';
        stage.appendChild(el);
    }
    return el;
}

function _gfdSyncSoundtrack(p){
    var audioEl = _gfdEnsureSoundtrackEl();
    if(!audioEl) return;

    var prevMemeId = audioEl.dataset.memeId || null;

    if(!p || !p.audioFile){
        if(prevMemeId) _gfdTrackPositions[prevMemeId] = audioEl.currentTime || 0;
        audioEl.pause();
        audioEl.removeAttribute('src');
        delete audioEl.dataset.memeId;
        audioEl.load();
        return;
    }

    var isSamePost = prevMemeId !== null && String(prevMemeId) === String(p.id);

    if(!isSamePost){
        if(prevMemeId) _gfdTrackPositions[prevMemeId] = audioEl.currentTime || 0;
        audioEl.src = p.audioFile;
        audioEl.dataset.memeId = p.id;
        var savedTime = _gfdTrackPositions[p.id] || 0;
        audioEl.currentTime = savedTime;
        audioEl.muted = false;
    }
    audioEl.volume = (p.musicVolume !== undefined && p.musicVolume !== null ? p.musicVolume : 80) / 100;

    var curSlide = document.getElementById('gfd-slide');
    var mb = curSlide ? curSlide.querySelector('#gfd-media-box') : null;

    if(_gfdPausedMemeIds[p.id]){
        audioEl.pause();
        if(mb){
            var activeItemForPause = mb.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"]') || mb;
            gfdShowPausedControls(activeItemForPause, audioEl);
        }
    } else {
        audioEl.play().catch(function(){});
    }

    var videoAudioVol = (p.videoAudioVolume !== undefined && p.videoAudioVolume !== null ? p.videoAudioVolume : 40) / 100;
    document.querySelectorAll('.gfd-vslide[data-pos="current"] video.gfd-media').forEach(function(v){
        v.volume = videoAudioVol;
    });
}

function _gfdPauseSoundtrack(){
    var el = document.getElementById('gfdSoundtrackPlayer');
    if(el) el.pause();
}
function _gfdResumeSoundtrack(){
    var el = document.getElementById('gfdSoundtrackPlayer');
    if(el && el.src) el.play().catch(function(){});
}
function _gfdStopSoundtrack(){
    var el = document.getElementById('gfdSoundtrackPlayer');
    if(el){
        var leavingMemeId = el.dataset.memeId || null;
        if(leavingMemeId) _gfdTrackPositions[leavingMemeId] = el.currentTime || 0;
        el.pause();
        el.removeAttribute('src');
        delete el.dataset.memeId;
        el.load();
    }
}

/* ══════════════════════════════════════════════════════════
   HIDE POST — modal-side counterpart to gfHidePost/gfUndoHidePost
   (those two live in an earlier <script> block, near
   gfLoadHiddenIds/gfSaveHiddenIds). Whenever a hide happens while
   the gf modal is open on that exact post, this overlays a
   "Post Hidden" card on top of #gfd-slide with its own Undo button,
   and pauses whatever media/audio was playing underneath it.

   The wiring that calls these two functions from gfHidePost/
   gfUndoHidePost lives in the monkey-patch block further down
   (_gfdPatchHideFns) — it wraps those two functions in place once
   the page has fully loaded, so nothing needs to be hand-edited in
   that other script block anymore.
   ══════════════════════════════════════════════════════════ */
function gfdHideHiddenOverlay(memeId){
    var slideEl = document.getElementById('gfd-slide');
    if(!slideEl){ delete _gfdPausedMemeIds[memeId]; return; }
    var wasBuiltHidden = slideEl.classList.contains('gfd-slide-hidden');
    if(wasBuiltHidden){
        // This slide never had real media built for it — build it now.
        slideEl.classList.remove('gfd-slide-hidden');
        var item = peuraGroupFeed.find(function(p){ return String(p.id) === String(memeId); });
        if(item){
            slideEl.innerHTML = _gfdRenderPost(item, false);
            _gfmMediaIndex = _gfdGetSavedMediaIndex(item.id);
            delete _gfdPausedMemeIds[memeId];
            _gfdInitMediaSwipe(slideEl);
            _gfdInitAudioMarquee(slideEl);
            _gfdInitInfoSwitcher(slideEl);
            slideEl.querySelectorAll('.gfd-video-progress').forEach(gfdInitProgressScrub);
            gfdUpdateMediaGallery(slideEl, false);
            gfdHideSkeleton(slideEl);
            _gfdSyncSoundtrack(item);
        }
        return;
    }
    // Live-hide case: overlay was appended on top of already-rendered media.
    var overlay = slideEl.querySelector('.gfd-hidden-overlay');
    if(overlay) overlay.remove();
    delete _gfdPausedMemeIds[memeId];
    if (typeof _gfmMemeId !== 'undefined' && String(_gfmMemeId) === String(memeId)) {
        var video = slideEl.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"] video.gfd-media');
        if(video) video.play().catch(function(){});
        _gfdResumeSoundtrack();
    }
}

function _gfdIsHidden(id){
    return id !== null && id !== undefined
        && typeof gfLoadHiddenIds === 'function'
        && gfLoadHiddenIds().indexOf(String(id)) !== -1;
}

function _gfdRenderHiddenSlideContent(memeId){
    return '<div class="gfd-hidden-overlay">'+
        '<button type="button" class="gfd-hidden-search-btn" onclick="event.stopPropagation();if(typeof openGroupSearchModal===\'function\')openGroupSearchModal();"><i class="ri-search-line"></i></button>'+
        '<div class="gfd-hidden-pill-row">'+
            '<button type="button" class="gfd-hidden-pill" onclick="event.stopPropagation();window.location.href=\'memepedia.php\';">'+
                '<i class="ri-book-2-line"></i><span>Memepedia</span>'+
            '</button>'+
            '<button type="button" class="gfd-hidden-pill" onclick="event.stopPropagation();window.location.href=\'group_highlights.php\';">'+
                '<i class="ri-star-line"></i><span>Highlights</span>'+
            '</button>'+
            '<button type="button" class="gfd-hidden-pill" onclick="event.stopPropagation();window.location.href=\'group_eveents.php\';">'+
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

/* Fires when a post gets hidden (via the kebab menu's "Hide Post")
   while it's the currently-open slide in the modal. */
function gfdShowHiddenOverlay(memeId){
    var slideEl = document.getElementById('gfd-slide');
    if(!slideEl) return;
    if(typeof _gfmMemeId === 'undefined' || String(_gfmMemeId) !== String(memeId)) return;
    if(slideEl.querySelector('.gfd-hidden-overlay')) return;

    var activeItem = slideEl.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"]')
                   || slideEl.querySelector('#gfd-media-box');
    if(activeItem){
        var video = activeItem.querySelector('video.gfd-media');
        if(video) video.pause();
    }
    _gfdPauseSoundtrack();
    _gfdPausedMemeIds[memeId] = true;
    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }

    var wrap = document.createElement('div');
    wrap.innerHTML = _gfdRenderHiddenSlideContent(memeId);
    slideEl.appendChild(wrap.firstElementChild);
}


/* ══════════════════════════════════════════════════════════
   VIDEO PROGRESS BAR — brought in line with home.php. Reads/writes
   a SINGLE bar per slide that lives as a sibling of .gfd-bottom
   (built once in _gfdRenderPost, appended after the .gfd-bottom
   block) — NOT one bar per .gfd-media-item nested inside the
   transformed .gfd-media-gallery. Skips updating while the user is
   actively dragging the thumb (bar has the "scrubbing" class).
   ══════════════════════════════════════════════════════════ */
function gfdUpdateProgress(video){
    if(!video.duration || isNaN(video.duration)) return;
    var slide = video.closest('.gfd-slide');
    var bar = slide ? slide.querySelector('.gfd-video-progress') : null;
    if(!bar || bar.classList.contains('scrubbing')) return;
    var pct = video.currentTime / video.duration * 100;
    var fill  = bar.querySelector('.gfd-video-progress-fill');
    var thumb = bar.querySelector('.gfd-video-progress-thumb');
    if(fill)  fill.style.width = pct+'%';
    if(thumb) thumb.style.left = pct+'%';
}

/* ── tap handling: single tap (play/pause or gallery nav) vs
   double tap (like + heart burst) ── */
function gfdHandleMediaTap(e, mediaBox, isGallery){
    var now = Date.now();
    var lastTap = mediaBox._gfdLastTap || 0;
    var dt = now - lastTap;

    if(dt > 0 && dt < 320){
        mediaBox._gfdLastTap = 0;
        if(mediaBox._gfdTapTimer){ clearTimeout(mediaBox._gfdTapTimer); mediaBox._gfdTapTimer = null; }
        gfdDoubleTapLike(mediaBox, e);
    } else {
        mediaBox._gfdLastTap = now;
        mediaBox._gfdTapTimer = setTimeout(function(){
            if(isGallery) gfdMediaBoxTap(mediaBox);
            else gfdToggleVideoPlay(mediaBox);
        }, 260);
    }
}

function gfdDoubleTapLike(mediaBox, e){
    var rect = mediaBox.getBoundingClientRect();
    var cx = (e && typeof e.clientX === 'number' && e.clientX !== 0) ? e.clientX : (rect.left + rect.width/2);
    var cy = (e && typeof e.clientY === 'number' && e.clientY !== 0) ? e.clientY : (rect.top + rect.height/2);
    gfdShowHeartBurst(mediaBox, cx - rect.left, cy - rect.top);

    var slideEl = document.getElementById('gfd-slide');
    var likeBtn = slideEl ? slideEl.querySelector('#gfd-like-btn') : null;
    if(likeBtn && !likeBtn.classList.contains('active')){
        gfdAction('like');
    }
}

function gfdShowHeartBurst(mediaBox, x, y){
    mediaBox.querySelectorAll('.gfd-double-tap-heart, .gfd-heart-ring, .gfd-heart-particle')
        .forEach(function(el){ el.remove(); });

    var ring = document.createElement('div');
    ring.className = 'gfd-heart-ring';
    ring.style.left = x+'px';
    ring.style.top  = y+'px';
    mediaBox.appendChild(ring);
    requestAnimationFrame(function(){ ring.classList.add('show'); });
    setTimeout(function(){ ring.remove(); }, 800);

    var heart = document.createElement('div');
    heart.className = 'gfd-double-tap-heart';
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
            var distance = 70 + Math.random()*50;
            var tx       = Math.cos(rad) * distance;
            var ty       = Math.sin(rad) * distance;
            var rot      = (Math.random()*80 - 40);
            var size     = 14 + Math.random()*12;

            var p = document.createElement('div');
            p.className = 'gfd-heart-particle';
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

/* ── tap to pause/resume + mute + 10s seek — falls back to the
   attached soundtrack when the current slide has no local video
   (image/text post with a track attached). ── */
function gfdToggleVideoPlay(mediaBox){
    var video = mediaBox.querySelector('video.gfd-media');
    if(!video){
        var audioEl = document.getElementById('gfdSoundtrackPlayer');
        if(audioEl && audioEl.src){
            if(audioEl.paused){
                audioEl.play().catch(function(){});
                if(_gfmMemeId) delete _gfdPausedMemeIds[_gfmMemeId];
                gfdHidePausedControls(mediaBox);
                gfdFlashIcon(mediaBox,'ri-play-fill');
            } else {
                audioEl.pause();
                if(_gfmMemeId) _gfdPausedMemeIds[_gfmMemeId] = true;
                gfdShowPausedControls(mediaBox, audioEl);
            }
        }
        return;
    }
    if(video.paused){
        video.play();
        _gfdResumeSoundtrack();
        if(_gfmMemeId) delete _gfdPausedMemeIds[_gfmMemeId];
        gfdHidePausedControls(mediaBox);
        gfdFlashIcon(mediaBox, 'ri-play-fill');
    } else {
        video.pause();
        _gfdPauseSoundtrack();
        if(_gfmMemeId) _gfdPausedMemeIds[_gfmMemeId] = true;
        gfdShowPausedControls(mediaBox, video);
    }
}

function gfdMediaBoxTap(mediaBox){
    var activeItem = mediaBox.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"]');
    if(!activeItem) return;
    var video = activeItem.querySelector('video.gfd-media');

    if(video){
        if(video.paused){
            video.play();
            _gfdResumeSoundtrack();
            if(_gfmMemeId) delete _gfdPausedMemeIds[_gfmMemeId];
            gfdHidePausedControls(activeItem);
            gfdFlashIcon(activeItem, 'ri-play-fill');
        } else {
            video.pause();
            _gfdPauseSoundtrack();
            if(_gfmMemeId) _gfdPausedMemeIds[_gfmMemeId] = true;
            gfdShowPausedControls(activeItem, video);
        }
        return;
    }

    var audioEl = document.getElementById('gfdSoundtrackPlayer');
    if(audioEl && audioEl.src){
        if(audioEl.paused){
            audioEl.play().catch(function(){});
            if(_gfmMemeId) delete _gfdPausedMemeIds[_gfmMemeId];
            gfdHidePausedControls(activeItem);
            gfdFlashIcon(activeItem, 'ri-play-fill');
        } else {
            audioEl.pause();
            if(_gfmMemeId) _gfdPausedMemeIds[_gfmMemeId] = true;
            gfdShowPausedControls(activeItem, audioEl);
        }
    }
}

function gfdFlashIcon(mediaBox, iconClass){
    var existing = mediaBox.querySelector('.gfd-tap-flash');
    if(existing) existing.remove();
    var icon = document.createElement('div');
    icon.className = 'gfd-tap-flash';
    icon.innerHTML = '<i class="'+iconClass+'"></i>';
    mediaBox.appendChild(icon);
    requestAnimationFrame(function(){ icon.classList.add('show'); });
    setTimeout(function(){
        icon.classList.remove('show');
        setTimeout(function(){ icon.remove(); }, 220);
    }, 450);
}

function gfdShowPausedControls(mediaBox, video){
    gfdHidePausedControls(mediaBox);

    var trackEl  = document.getElementById('gfdSoundtrackPlayer');
    var hasTrack = trackEl && trackEl.src;
    var soundSrc = hasTrack ? trackEl : video;
    var isMuted  = soundSrc ? !!soundSrc.muted : false;

    var wrap = document.createElement('div');
    wrap.className = 'gfd-paused-controls';
    wrap.innerHTML =
        '<div class="gfd-paused-stack">'+
            '<button type="button" class="gfd-mute-btn" onclick="event.stopPropagation();gfdToggleMute(this)">'+
                '<i class="'+(isMuted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill')+'"></i>'+
            '</button>'+
            '<div class="gfd-paused-row">'+
                '<button type="button" class="gfd-seek-btn" onclick="event.stopPropagation();gfdSeekVideo(this,-10)" title="Back 10s">'+
                    '<i class="ri-replay-10-line"></i>'+
                '</button>'+
                '<div class="gfd-pause-icon"><i class="ri-pause-fill"></i></div>'+
                '<button type="button" class="gfd-seek-btn" onclick="event.stopPropagation();gfdSeekVideo(this,10)" title="Forward 10s">'+
                    '<i class="ri-forward-10-line"></i>'+
                '</button>'+
            '</div>'+
        '</div>';
    mediaBox.appendChild(wrap);
}
function gfdHidePausedControls(mediaBox){
    var existing = mediaBox.querySelector('.gfd-paused-controls');
    if(existing) existing.remove();
}

function gfdToggleMute(btn){
    var container = btn.closest('.gfd-media-box') || btn.closest('.gfd-media-item');
    var video    = container ? container.querySelector('video.gfd-media') : null;
    var trackEl  = document.getElementById('gfdSoundtrackPlayer');
    var hasTrack = trackEl && trackEl.src;
    var media    = hasTrack ? trackEl : video;
    if(!media) return;

    media.muted = !media.muted;

    var icon = btn.querySelector('i');
    icon.className = media.muted ? 'ri-volume-mute-fill' : 'ri-volume-up-fill';
}

function gfdSeekVideo(btn, delta){
    var container = btn.closest('.gfd-media-box') || btn.closest('.gfd-media-item');
    var video    = container ? container.querySelector('video.gfd-media') : null;
    var trackEl  = document.getElementById('gfdSoundtrackPlayer');
    var hasTrack = trackEl && trackEl.src;
    var media    = hasTrack ? trackEl : video;
    if(!media || isNaN(media.duration)) return;

    var newTime = media.currentTime + delta;
    if(newTime < 0) newTime = 0;
    if(media.duration && newTime > media.duration) newTime = media.duration;
    media.currentTime = newTime;

    if(media === video) gfdUpdateProgress(video);
}

/* ══════════════════════════════════════════════════════════
   DRAG-TO-SEEK — ported from home.php, unmodified in behavior.
   ══════════════════════════════════════════════════════════ */
function gfdInitProgressScrub(bar){
    if(!bar || bar._gfdScrubBound) return;
    bar._gfdScrubBound = true;

    var track = bar.querySelector('.gfd-video-progress-track');
    var fill  = bar.querySelector('.gfd-video-progress-fill');
    var thumb = bar.querySelector('.gfd-video-progress-thumb');

    function getMedia(){
        var slide = bar.closest('.gfd-slide');
        var video = slide ? (
            slide.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"] video.gfd-media')
            || slide.querySelector('video.gfd-media')
        ) : null;
        var trackEl = document.getElementById('gfdSoundtrackPlayer');
        var hasTrack = trackEl && trackEl.src && trackEl.dataset.memeId && String(trackEl.dataset.memeId) === String(_gfmMemeId);
        return { video: video, audio: hasTrack ? trackEl : null };
    }
    function pctFromX(x){
        var r = track.getBoundingClientRect();
        return Math.max(0, Math.min(1, (x - r.left) / r.width));
    }
    function paint(pct){
        fill.style.width = (pct*100)+'%';
        thumb.style.left = (pct*100)+'%';
    }
    function seekTo(pct){
        var m = getMedia();
        if(m.video && !isNaN(m.video.duration)) m.video.currentTime = pct * m.video.duration;
        if(m.audio && !isNaN(m.audio.duration))  m.audio.currentTime = pct * m.audio.duration;
    }

    var dragging = false;

    function start(x){
        dragging = true;
        bar.classList.add('scrubbing');
        var m = getMedia();
        if(m.video) m.video.pause();
        if(m.audio) m.audio.pause();
        paint(pctFromX(x));
    }
    function move(x){
        if(!dragging) return;
        var pct = pctFromX(x);
        paint(pct);
        seekTo(pct);
    }
    function end(x){
        if(!dragging) return;
        dragging = false;
        seekTo(pctFromX(x));
        bar.classList.remove('scrubbing');
        var wasManuallyPaused = _gfmMemeId ? !!_gfdPausedMemeIds[_gfmMemeId] : false;
        if(!wasManuallyPaused){
            var m = getMedia();
            if(m.video) m.video.play().catch(function(){});
            if(m.audio) m.audio.play().catch(function(){});
        }
    }

    bar.addEventListener('touchstart', function(e){ e.stopPropagation(); start(e.touches[0].clientX); }, {passive:true});
    bar.addEventListener('touchmove',  function(e){ e.stopPropagation(); move(e.touches[0].clientX);  }, {passive:true});
    bar.addEventListener('touchend',   function(e){ e.stopPropagation(); end(e.changedTouches[0].clientX); }, {passive:true});

    bar.addEventListener('mousedown', function(e){
        e.stopPropagation();
        start(e.clientX);
        function onMove(ev){ move(ev.clientX); }
        function onUp(ev){
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            end(ev.clientX);
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });

    bar.addEventListener('click', function(e){ e.stopPropagation(); });
}

function gfdFixObjectFit(el){
    var box = el.closest('.gfd-media-item') || el.closest('.gfd-media-box');
    if(!box) return;
    var mW = el.tagName === 'VIDEO' ? el.videoWidth  : el.naturalWidth;
    var mH = el.tagName === 'VIDEO' ? el.videoHeight : el.naturalHeight;
    if(!mW || !mH) return;
    var cW = box.clientWidth, cH = box.clientHeight;
    if(!cW || !cH) return;
    var mediaAspect = mW / mH;
    var boxAspect   = cW / cH;

    // Taller/equal than the box → always cover. This scales by width to
    // fill it exactly and only trims overflow off the top/bottom — sides
    // are never touched in this case, so it's safe.
    if(mediaAspect < boxAspect){
        el.style.objectFit = 'cover';
        return;
    }

    // Wider than the box → 'contain' already fills the width exactly
    // (that's never the issue here), it only leaves letterbox bars top/
    // bottom. If the media's height comes close enough to filling the box
    // once scaled to full width, STRETCH it (never crop) to close that
    // gap. If it's far off, leave 'contain' so nothing gets cut off.
    var scaledHeight = cW / mediaAspect;   // height once scaled to fill box width
    var fillRatio    = scaledHeight / cH;  // how close that is to full box height

    // Tweak this threshold: raise it to be stricter (fewer items get
    // stretched/filled), lower it to be more lenient (more items fill
    // edge-to-edge instead of showing letterbox bars).
    var FILL_THRESHOLD = 0.84;

    // 'fill' stretches height only (width is already exactly cW under
    // 'contain', so it stays untouched) — this closes the gap without
    // ever cropping the sides, unlike 'cover' which would crop them.
    el.style.objectFit = (fillRatio >= FILL_THRESHOLD) ? 'fill' : 'contain';
}

function _gfdComputeDashGap(count){
    return Math.max(3, Math.min(6, Math.round(60 / count)));
}

/* ── per-post saved gallery index ── */
function _gfdGetSavedMediaIndex(postId){
    if(postId === null || postId === undefined) return 0;
    var v = _gfdMediaIndexByPost[postId];
    return (typeof v === 'number' && v >= 0) ? v : 0;
}
function _gfdSaveMediaIndex(postId, idx){
    if(postId === null || postId === undefined) return;
    _gfdMediaIndexByPost[postId] = idx;
}

/* ════════════════════════════════════════════════════════════
   SAVED TOAST
   ════════════════════════════════════════════════════════════ */
var _gfdSavedToastTimer = null;

function gfdShowSavedToast(memeId){
    var existing = document.getElementById('gfdSavedToast');
    if(existing) existing.remove();
    clearTimeout(_gfdSavedToastTimer);

    var toast = document.createElement('div');
    toast.id = 'gfdSavedToast';
    toast.className = 'gfd-saved-toast';
    toast.innerHTML =
        '<div class="gfd-saved-toast-left">'+
            '<span class="gfd-saved-toast-check"><i class="ri-check-line"></i></span>'+
            'Saved'+
        '</div>'+
        '<div class="gfd-saved-toast-right" onclick="gfdToastOpenCollections('+memeId+')">'+
            'Collections<i class="ri-arrow-right-s-line"></i>'+
        '</div>';

    document.body.appendChild(toast);
    requestAnimationFrame(function(){ toast.classList.add('show'); });

    _gfdSavedToastTimer = setTimeout(function(){
        toast.classList.remove('show');
        setTimeout(function(){ toast.remove(); }, 300);
    }, 3500);
}

function gfdHideSavedToast(){
    var toast = document.getElementById('gfdSavedToast');
    if(!toast) return;
    clearTimeout(_gfdSavedToastTimer);
    toast.classList.remove('show');
    setTimeout(function(){ toast.remove(); }, 300);
}

function gfdToastOpenCollections(memeId){
    gfdHideSavedToast();
    if(typeof openSaveModal === 'function'){
        openSaveModal(memeId);
    }
}

/* ════════════════════════════════════════════════════════════
   MULTI-MEDIA GALLERY — swipe/nav within one post
   ════════════════════════════════════════════════════════════ */
function gfdUpdateMediaGallery(slide, suppressAutoplay){
    var gallery = slide.querySelector('#gfd-media-gallery');
    if(!gallery) return;
    gallery.style.transform = 'translateX(-'+(_gfmMediaIndex*100)+'%)';

    gallery.querySelectorAll('.gfd-media-item').forEach(function(itemEl){
        var idx = parseInt(itemEl.dataset.idx, 10);
        var vid = itemEl.querySelector('video.gfd-media');
        if(vid){
            if(idx === _gfmMediaIndex){
                if(vid.preload !== 'auto'){ vid.preload = 'auto'; }
                if(suppressAutoplay){
                    vid.pause();
                    gfdShowPausedControls(itemEl, vid);
                } else {
                    vid.play().catch(function(){});
                }
            } else if(Math.abs(idx - _gfmMediaIndex) === 1){
                if(vid.preload !== 'auto'){ vid.preload = 'auto'; }
                vid.pause();
            } else {
                vid.pause();
            }
        }
        if(idx !== _gfmMediaIndex) gfdHidePausedControls(itemEl);
    });

    slide.querySelectorAll('.gfd-dash').forEach(function(d, i){
        d.classList.toggle('active', i === _gfmMediaIndex);
    });

    var totalItems = gallery.querySelectorAll('.gfd-media-item').length;

    var counterEl = slide.querySelector('#gfd-media-counter');
    if(counterEl){
        counterEl.textContent = (_gfmMediaIndex+1)+'/'+totalItems;
    }

    var activeItem = gallery.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"]');
    var typeTagEl  = slide.querySelector('#gfd-type-tag');
    if(activeItem && typeTagEl){
        var info = _gfdTypeTagInfo(activeItem.dataset.type);
        typeTagEl.innerHTML = '<i class="'+info.icon+'"></i><span>'+info.label+'</span>';
    }

    var progressBar = slide.querySelector('.gfd-video-progress');
    if(progressBar){
        var activeVid = activeItem ? activeItem.querySelector('video.gfd-media') : null;
        progressBar.style.display = activeVid ? 'flex' : 'none';
        gfdInitProgressScrub(progressBar);
    }
}

/* ────────────────────────────────────────────────────────────
   MEDIA NAV STEP LOCK — same as the discover modal. Used by the
   hover arrows, arrow keys and the trackpad horizontal wheel so a
   rapid double-fire can never skip a media item.
──────────────────────────────────────────────────────────── */
var _gfdMediaNavLock = false;

function _gfdMediaNavStep(delta){
    if(_gfdMediaNavLock) return;
    _gfdMediaNavLock = true;
    setTimeout(function(){ _gfdMediaNavLock = false; }, 200);

    var slide = document.getElementById('gfd-slide');
    if(!slide) return;
    var mediaBox = slide.querySelector('#gfd-media-box');
    if(!mediaBox) return;
    var count = parseInt(mediaBox.dataset.count || '1', 10);

    var next = _gfmMediaIndex + delta;
    if(next < 0 || next > count - 1) return;

    _gfmMediaIndex = next;
    _gfdSaveMediaIndex(_gfmMemeId, _gfmMediaIndex);
    gfdUpdateMediaGallery(slide);
}

function gfdMediaNext(){ _gfdMediaNavStep(1); }
function gfdMediaPrev(){ _gfdMediaNavStep(-1); }

function _gfdInitMediaSwipe(slide){
    var gallery  = slide.querySelector('#gfd-media-gallery');
    var mediaBox = slide.querySelector('#gfd-media-box');
    if(!gallery || !mediaBox) return;
    if(mediaBox._gfdSwipeBound) return;
    var mediaCount = parseInt(mediaBox.dataset.count || '1', 10);
    if(mediaCount <= 1) return;
    mediaBox._gfdSwipeBound = true;

    var startX = 0, startY = 0, dragging = false, horizontal = false;

    mediaBox.addEventListener('touchstart', function(e){
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        dragging = true;
        horizontal = false;
        gallery.style.transition = 'none';
    }, {passive:true});

    mediaBox.addEventListener('touchmove', function(e){
        if(!dragging) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;
        if(!horizontal && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) horizontal = true;
        if(horizontal){
            e.stopPropagation();
            gallery.style.transform = 'translateX(calc(-'+(_gfmMediaIndex*100)+'% + '+dx+'px))';
        }
    }, {passive:true});

    mediaBox.addEventListener('touchend', function(e){
        if(!dragging) return;
        dragging = false;
        gallery.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';
        if(horizontal){
            e.stopPropagation();
            var dx = e.changedTouches[0].clientX - startX;
            var count = parseInt(mediaBox.dataset.count || '1', 10);
            if(Math.abs(dx) > 50){
                if(dx < 0 && _gfmMediaIndex < count - 1) _gfmMediaIndex++;
                else if(dx > 0 && _gfmMediaIndex > 0) _gfmMediaIndex--;
            }
            _gfdSaveMediaIndex(_gfmMemeId, _gfmMediaIndex);
        }
        gfdUpdateMediaGallery(slide);
    }, {passive:true});
}

/* ── render a single post slide ── */
function _gfdRenderPost(p, isPreview){
    var isOwnPost = String(p.userId) === String(gfCurrentUserId);

    var media = (Array.isArray(p.media) && p.media.length) ? p.media.slice() : [{file:p.file, type:p.type}];
    var mediaCount = media.length;

    var mediaSet  = ['image','video','gif'];
    var firstType = (media[0].type || '').toLowerCase();
    var isTextOnly = mediaCount === 1 && mediaSet.indexOf(firstType) === -1;

    var hasSeparateTrack = !!(p.audioFile) && !p.audioIsOriginal;
    var isMemePaused = !!_gfdPausedMemeIds[p.id];

    var initIdx;
    if(isPreview){
        initIdx = 0;
    } else {
        initIdx = isTextOnly ? 0 : _gfdGetSavedMediaIndex(p.id);
        initIdx = Math.max(0, Math.min(initIdx, mediaCount - 1));
        _gfmMediaIndex = initIdx;
    }

    var isFollowing = !!p.following;
    var capState = _gfdComputeCaptionHtml(p);
    var localCaptionTruncHtml = capState.trunc;
    var mediaBoxInner;

    if(isTextOnly){
        mediaBoxInner = '<div class="gfd-textonly-bg"></div>'+
                    '<div class="gfd-textonly-overlay"></div>'+
                    '<div class="gfd-center-caption-wrap" id="gfd-center-caption-wrap">'+
                        '<div class="gfd-center-caption-text" id="gfd-center-caption-text">'+localCaptionTruncHtml+'</div>'+
                    '</div>';
    } else {
        mediaBoxInner = '<div class="gfd-media-gallery" id="gfd-media-gallery">'+
            media.map(function(m, i){
                var t = (m.type||'').toLowerCase();
                var isActive = (i === initIdx);
                var loadHook = isActive ? "gfdHideSkeleton(this.closest('.gfd-slide'))" : "";
                if(t === 'video'){
                    var mutedAttr = hasSeparateTrack ? 'muted ' : '';
                    var autoplayAttr = (isActive && !isPreview && !isMemePaused) ? 'autoplay' : '';
                    var isAdjacent = !isPreview && mediaCount > 1 && Math.abs(i - initIdx) === 1;
                    var preloadAttr = (isActive && !isPreview) ? 'preload="auto"' :
                                       (isAdjacent ? 'preload="auto"' : 'preload="metadata"');
                    return '<div class="gfd-media-item" data-idx="'+i+'" data-type="video">'+
                        '<video src="'+m.file+'" '+mutedAttr+autoplayAttr+' loop playsinline '+preloadAttr+' '+
                        'onloadedmetadata="gfdFixObjectFit(this);" '+
                        'onloadeddata="'+loadHook+'" '+
                        'ontimeupdate="gfdUpdateProgress(this)" class="gfd-media"></video>'+
                    '</div>';
                }
                return '<div class="gfd-media-item" data-idx="'+i+'" data-type="'+t+'">'+
                    '<img src="'+m.file+'" onerror="this.src=\'uploads/me.jpeg\'" '+
                    'onload="gfdFixObjectFit(this);'+loadHook+'" class="gfd-media">'+
                '</div>';
            }).join('')+
        '</div>'+
        (mediaCount > 1
            ? '<div class="gfd-media-arrow gfd-media-arrow-left" onclick="event.stopPropagation();gfdMediaPrev()"><i class="ri-arrow-left-s-line"></i></div>'+
              '<div class="gfd-media-arrow gfd-media-arrow-right" onclick="event.stopPropagation();gfdMediaNext()"><i class="ri-arrow-right-s-line"></i></div>'
            : '');
    }

    var typeInfo = _gfdTypeTagInfo(isTextOnly ? 'text' : ((media[initIdx] && media[initIdx].type) || firstType));
    var typeTagHtml = '<div class="gfd-type-tag" id="gfd-type-tag"><i class="'+typeInfo.icon+'"></i><span>'+typeInfo.label+'</span></div>';
    var counterHtml = mediaCount > 1
        ? '<div class="gfd-media-counter" id="gfd-media-counter">'+(initIdx+1)+'/'+mediaCount+'</div>'
        : '';

    var searchBtnHtml = '<button type="button" class="gfd-top-search-btn" onclick="event.stopPropagation();if(typeof openGroupSearchModal===\'function\')openGroupSearchModal();"><i class="ri-search-line"></i></button>';

    /* Discuss button — blue rectangular button, top-left under the back chevron */
    var discussBtnHtml = '<div class="gfd-discuss-wrap" id="gfd-discuss-wrap">'+
        '<button type="button" class="gfd-discuss-btn" onclick="event.stopPropagation();gfdOpenDiscuss('+p.id+')">'+
            '<i class="ri-discuss-line"></i><span>Discuss</span>'+
        '</button>'+
    '</div>';

    var dashGap = mediaCount > 1 ? _gfdComputeDashGap(mediaCount) : 6;
    var dashesHtml = (mediaCount > 1)
        ? '<div class="gfd-media-dashes" id="gfd-media-dashes" style="gap:'+dashGap+'px;">'+
            media.map(function(_, i){ return '<span class="gfd-dash'+(i===initIdx?' active':'')+'"></span>'; }).join('')+
          '</div>'
        : '';

    var likeCount   = p.likeCount   || 0;
    var repostCount = p.repostCount || 0;
    var favCount    = p.favCount    || 0;
    var shareCount  = p.shareCount  || 0;
    var liked    = p.liked    || false;
    var reposted = p.reposted || false;
    var faved    = p.faved    || false;

    var trackBtnHtml = !isOwnPost
        ? '<span class="gfd-user-dot">·</span>'+
          '<button class="gfd-track-btn '+(isFollowing?'following':'')+'" data-user="'+p.userId+'" '+
              'onclick="event.stopPropagation();gfmToggleTrack(this)">'+(isFollowing?'Untrack':'Track')+'</button>'
        : '';

    var isNewPost = false;
    if(p.time){
        var _pd = new Date((p.time||'').replace(' ','T'));
        if(!isNaN(_pd)) isNewPost = (Date.now() - _pd.getTime()) <= (172800*1000);
    }
    var displayName = isOwnPost ? 'You' : _gfdTruncateName(p.username);
    var nicknameHtml = (!isOwnPost && p.nickname) ? '<div class="gfd-nickname">@'+p.nickname+'</div>' : '';

    var audioTagHtml = '';
    if(p.audioId !== undefined && p.audioId !== null){
        var audioLabel;
        if(p.audioIsOriginal){
            audioLabel = (isOwnPost ? p.username : displayName) + ' · Original audio';
        } else {
            audioLabel = (p.audioTitle || 'Audio') + (p.audioArtist ? ' — '+p.audioArtist : '');
        }

        audioTagHtml = '<div class="gfd-audio-tag" onclick="event.stopPropagation();gfdOpenSoundsPage('+p.userId+','+p.audioId+')" style="cursor:pointer;">'+
            '<span class="gfd-audio-icon" aria-hidden="true">'+
                '<span class="gfd-eq-bar"></span><span class="gfd-eq-bar"></span><span class="gfd-eq-bar"></span>'+
            '</span>'+
            '<div class="gfd-audio-track" id="gfd-audio-track">'+
                '<span class="gfd-audio-track-text" id="gfd-audio-track-text">'+_gfdEscapeHtml(audioLabel)+'</span>'+
            '</div>'+
        '</div>';
    }

    var switcherHtml = '';
    if(nicknameHtml && audioTagHtml){
        switcherHtml = '<div class="gfd-info-switcher" id="gfd-info-switcher">'+
            '<div class="gfd-switch-pane gfd-switch-nickname-pane active">'+nicknameHtml+'</div>'+
            '<div class="gfd-switch-pane gfd-switch-audio-pane">'+audioTagHtml+'</div>'+
        '</div>';
    } else if(nicknameHtml){
        switcherHtml = nicknameHtml;
    } else if(audioTagHtml){
        switcherHtml = audioTagHtml;
    }

    /* Shared "should this bottom-left slot sit higher" flag — raised either
       because of the paid-partnership badge sitting just below it, OR
       because this post has multi-media dashes running along the bottom. */
    var _gfdNeedsRaise = p.isPartnership || mediaCount > 1;

    /* Social proof never renders on a post that links to a Connected Zeem —
       the connected pill takes this exact strip slot instead.
       NOTE: social-proof avatars intentionally keep their red border + badges. */
    var socialProofHtml = '';
    if(!p.connectedMemeId && Array.isArray(p.socialProof) && p.socialProof.length){
        var spClass = 'gfd-social-proof-strip' + (_gfdNeedsRaise ? ' gfd-sp-raised' : '');
        socialProofHtml = '<div class="'+spClass+'" id="gfd-social-proof">'+
            p.socialProof.slice(0,4).map(function(sp){
                var spPic  = sp.profile_pic || 'uploads/me.jpeg';
                var isLike = sp.action_type === 'like';
                var icon   = isLike ? 'ri-heart-3-fill' : 'ri-repeat-line';
                var color  = isLike ? '#f24455' : '#7a4aff';
                return '<a href="profile.php?user_id='+sp.id+'" onclick="event.stopPropagation()" class="gfd-sp-avatar-wrap">'+
                    '<img src="'+spPic+'" onerror="this.src=\'uploads/me.jpeg\'" class="gfd-sp-avatar">'+
                    '<span class="gfd-sp-badge" style="background:'+color+';"><i class="'+icon+'"></i></span>'+
                '</a>';
            }).join('')+
        '</div>';
    }

    /* Connected-Zeem pill. Occupies the same bottom-left slot as social proof
       (mutually exclusive with it). Tapping it never navigates the page — it
       slides in a new horizontal layer over the modal via gfdOpenConnectedZeem(). */
    var connectedHtml = '';
    if(p.connectedMemeId){
        var raisedClass = _gfdNeedsRaise ? ' gfd-sp-raised' : '';
        var connLabel   = p.connectedLabel ? _gfdEscapeHtml(p.connectedLabel) : 'Connected Zeem';
        connectedHtml = '<a href="#" id="gfd-connected-tag" class="gfd-connected-tag'+raisedClass+'" '+
            'onclick="event.stopPropagation();event.preventDefault();gfdOpenConnectedZeem('+p.connectedMemeId+')">'+
            '<span class="gfd-connected-icon"><i class="ri-play-fill"></i></span>'+
            '<span class="gfd-connected-text">'+
                '<span class="gfd-connected-text-main">View</span>'+
                '<span class="gfd-connected-text-dot">·</span>'+
                '<span class="gfd-connected-text-label">'+connLabel+'</span>'+
            '</span>'+
        '</a>';
    }

    var bottomCaptionHtml = '';
    if(!isTextOnly){
        bottomCaptionHtml = '<div class="gfd-caption-wrap" id="gfd-caption-wrap">'+
              '<span class="gfd-caption-text" id="gfd-caption-text">'+localCaptionTruncHtml+'</span>'+
          '</div>';
    }

    /* Paid Partnership badge — sits under the caption, own line,
       never mixed into the caption text itself. */
    var partnershipHtml = '';
    if(p.isPartnership){
        var brandText = p.partnerBrand ? ' with <strong>'+_gfdEscapeHtml(p.partnerBrand)+'</strong>' : '';
        partnershipHtml = '<div class="gfd-partnership-tag"><i class="ri-handshake-line"></i>Paid partnership'+brandText+'</div>';
    }

    var railClass = 'gfd-rail' + (mediaCount > 1 ? ' gfd-rail-raised' : '');

    var initialActiveType = isTextOnly ? '' : ((media[initIdx] && media[initIdx].type) || firstType).toLowerCase();
    var progressBarHtml = !isTextOnly ? (
        '<div class="gfd-video-progress" style="display:'+(initialActiveType === 'video' ? 'flex' : 'none')+';">'+
            '<div class="gfd-video-progress-track">'+
                '<div class="gfd-video-progress-fill"></div>'+
                '<div class="gfd-video-progress-thumb"></div>'+
            '</div>'+
        '</div>'
    ) : '';

    return (
        _gfdSkeletonHtml()+

        '<div class="gfd-media-box" id="gfd-media-box" data-count="'+mediaCount+'" '+
            'onclick="event.stopPropagation();gfdHandleMediaTap(event,this,'+(mediaCount > 1)+')"'+
        '>'+ mediaBoxInner +'</div>'+

        '<div class="gfd-top-bar">'+
            '<div class="gfd-top-bar-row">'+
                '<div class="gfd-back-group">'+
                    '<span class="gfd-icon-btn gfd-back-btn" style="visibility:hidden;pointer-events:none;"><i class="ri-arrow-left-s-line"></i></span>'+
                    '<span class="gfd-brand-label">Zeems</span>'+
                    (p.time ? '<span class="gfd-brand-dot">·</span><span class="gfd-brand-date">'+_gfdFormatDate(p.time)+(isNewPost?' · <span style="color:#2f6fd6;font-weight:700;">New</span>':'')+'</span>' : '')+
                '</div>'+
            '</div>'+
        '</div>'+

        searchBtnHtml+
        discussBtnHtml+
        '<div class="gfd-top-right-tags">'+ counterHtml + typeTagHtml +'</div>'+
        socialProofHtml+
        connectedHtml+

       '<div class="'+railClass+'">'+
            '<button id="gfd-like-btn" class="gfd-rail-btn '+(liked?'active':'')+'" onclick="event.stopPropagation();gfdAction(\'like\')">'+
                '<i class="ri-heart-3-fill"></i><span id="gfd-like-count" class="gfd-rail-count">'+likeCount+'</span>'+
            '</button>'+
            '<button id="gfd-repost-btn" class="gfd-rail-btn '+(reposted?'active':'')+'" onclick="event.stopPropagation();gfdAction(\'repost\')">'+
                '<i class="ri-repeat-line"></i><span id="gfd-repost-count" class="gfd-rail-count">'+repostCount+'</span>'+
            '</button>'+
            '<button id="gfd-fav-btn" class="gfd-rail-btn '+(faved?'active':'')+'" onclick="event.stopPropagation();gfdAction(\'fav\')">'+
                '<i class="ri-bookmark-fill"></i><span id="gfd-fav-count" class="gfd-rail-count">'+favCount+'</span>'+
            '</button>'+
            '<button id="gfd-share-btn" class="gfd-rail-btn" onclick="event.stopPropagation();openShareModal('+p.id+')">'+
                '<i class="ri-share-forward-fill"></i><span id="gfd-share-count" class="gfd-rail-count">'+shareCount+'</span>'+
            '</button>'+
            '<button class="gfd-rail-btn gfd-rail-more" onclick="event.stopPropagation();openGfSheet('+p.id+')">'+
                '<i class="ri-menu-line"></i>'+
            '</button>'+
            (!isOwnPost
                ? '<button type="button" class="gfd-rail-btn gfd-rail-msg" onclick="event.stopPropagation();gfdOpenSoundsPage('+p.userId+(p.audioId !== undefined && p.audioId !== null ? ','+p.audioId : '')+')">'+
                      '<img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" class="gfd-rail-msg-pic">'+
                  '</button>'
                : '')+
        '</div>'+

        '<div class="gfd-bottom">'+
            '<div class="gfd-user-row">'+
                '<img src="'+(p.pic||'uploads/me.jpeg')+'" onerror="this.src=\'uploads/me.jpeg\'" class="gfd-user-pic" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+
                '<div class="gfd-user-info">'+
                    '<div class="gfd-user-line1">'+
                        '<span class="gfd-username" onclick="location.href=\'profile.php?user_id='+p.userId+'\'">'+displayName+'</span>'+
                        trackBtnHtml+
                    '</div>'+
                    switcherHtml+
                '</div>'+
            '</div>'+
            bottomCaptionHtml+
            partnershipHtml+
            dashesHtml+
        '</div>'+

        progressBarHtml
    );
}

/* ── Discuss button handler — carries the meme_id over to discussions.php,
     which prefills the create-discussion modal with this post's media,
     caption, and a default (changeable) tag. Members-only, same lock
     pattern as the rest of the pod's member-gated actions. ── */
function gfdOpenDiscuss(memeId){
    if (typeof GF_IS_MEMBER === 'undefined' || !GF_IS_MEMBER) {
        if (typeof showDiscLockToast === 'function') {
            showDiscLockToast('Join this pod to access Discussions');
        }
        return;
    }
    // A Connected Zeem the user owns may never have a group_posts row
    // in THIS pod — flag it so discussions.php falls back to an
    // ownership check instead of requiring a group_posts match.
    var post = _gfdFindPostById(memeId);
    var isExternal = !post || !post.groupPostId;
    window.location.href = 'discussions.php?pod_id=' + gfGroupId +
        '&prefill_meme_id=' + memeId +
        (isExternal ? '&external_meme=1' : '');
}

function gfdOpenSoundsPage(userId, audioId){
    if(!userId) return;
    var url = 'audio.php?user_id=' + userId;
    if(audioId){ url += '&audio_id=' + audioId; }
    window.location.href = url;
}

/* ══════════════════════════════════════════════════════════
   CONNECTED ZEEM — horizontal linked-post navigation
   ══════════════════════════════════════════════════════════
   Separate from BOTH existing swipe systems:
     - vertical (_gfdVDragBound / _gfdCommitTrackMove) moves
       between posts in the current pod feed (peuraGroupFeed)
     - horizontal-in-gallery (_gfdInitMediaSwipe) moves between
       media items WITHIN one post
   This is a third system: horizontal, but slides the ENTIRE
   slide (header, media, rail, caption) to a totally different
   post that may not be anywhere in peuraGroupFeed. It lives in
   its own stack (_gfdHStack) of .gfd-hlayer elements inside
   #gfd-hstack (a sibling of the vertical track inside
   #gfd-inner). Only one layer is ever the "active" one —
   whichever is on top of the stack — and it's the one that gets
   id="gfd-slide" so every existing helper keeps working
   completely unmodified.
   ══════════════════════════════════════════════════════════ */

function _gfdEnsureHStackEl(){
    var inner = document.getElementById('gfd-inner');
    if(!inner) return null;
    var el = document.getElementById('gfd-hstack');
    if(!el){
        el = document.createElement('div');
        el.id = 'gfd-hstack';
        el.style.pointerEvents = 'none';
        inner.appendChild(el);
    }
    return el;
}

function gfdOpenConnectedZeem(memeId){
    if(!memeId) return;

    // Already showing this exact post on top — nothing to do.
    if(_gfdHStack.length){
        var topItem = _gfdHStack[_gfdHStack.length - 1].item;
        if(topItem && String(topItem.id) === String(memeId)) return;
    }

    var cached = _gfdConnectedCache[memeId];
    if(cached){
        _gfdPushConnectedLayer(cached);
        return;
    }

    if(_gfdConnectedFetchLock) return;
    _gfdConnectedFetchLock = true;

    fetch('group.php?id=' + gfGroupId, {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    'ajax_get_connected_meme=1&meme_id=' + encodeURIComponent(memeId) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        _gfdConnectedFetchLock = false;
        if(!data || !data.ok || !data.post){
            gfdToast('Could not load this Zeem');
            return;
        }
        _gfdConnectedCache[memeId] = data.post;
        _gfdPushConnectedLayer(data.post);
    })
    .catch(function(){
        _gfdConnectedFetchLock = false;
        gfdToast('Network error — try again');
    });
}

function _gfdPushConnectedLayer(postData){
    var hstack = _gfdEnsureHStackEl();
    if(!hstack) return;

    // Detach id from whatever is currently the active slide (either
    // the vtrack's current post, or the previous top hlayer) so only
    // the new incoming layer ever owns #gfd-slide.
    var curSlide = document.getElementById('gfd-slide');
    if(curSlide) curSlide.removeAttribute('id');

    // Stop timers tied to the outgoing slide — they'll be restarted
    // fresh against the new slide inside _gfdActivateCurrentSlide.
    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }

    var layer = document.createElement('div');
    layer.className = 'gfd-hlayer';

    var slideEl = document.createElement('div');
    slideEl.className = 'gfd-slide';
    slideEl.id = 'gfd-slide';
    slideEl.innerHTML = _gfdRenderPost(postData, false);
    layer.appendChild(slideEl);

    layer.style.transform  = 'translateX(100%)';
    layer.style.transition = 'none';
    hstack.appendChild(layer);
    hstack.style.pointerEvents = 'all';

    void layer.offsetHeight; // force reflow so the slide-in actually animates
    layer.style.transition = 'transform .32s cubic-bezier(.22,1,.36,1)';
    layer.style.transform  = 'translateX(0)';

    layer.querySelectorAll('.gfd-video-progress').forEach(gfdInitProgressScrub);

    _gfdHStack.push({ layer: layer, item: postData });
    _gfmMediaIndex = 0;

    _gfdActivateCurrentSlide(postData);
}

function gfdCloseConnectedZeem(){
    if(!_gfdHStack.length) return;

    var top   = _gfdHStack.pop();
    var layer = top.layer;

    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }

    var outgoingSlide = layer.querySelector('.gfd-slide');
    if(outgoingSlide){
        outgoingSlide.removeAttribute('id');
        outgoingSlide.querySelectorAll('video').forEach(function(v){ v.pause(); });
    }

    layer.style.transition = 'transform .32s cubic-bezier(.22,1,.36,1)';
    layer.style.transform  = 'translateX(100%)';

    var removed = false;
    function finish(){
        if(removed) return;
        removed = true;
        layer.removeEventListener('transitionend', onEnd);
        if(layer.parentNode) layer.parentNode.removeChild(layer);

        var nextTop = _gfdHStack.length ? _gfdHStack[_gfdHStack.length - 1] : null;
        var nextItem;

        if(nextTop){
            var nextSlide = nextTop.layer.querySelector('.gfd-slide');
            if(nextSlide) nextSlide.id = 'gfd-slide';
            nextItem = nextTop.item;
        } else {
            var hstack = document.getElementById('gfd-hstack');
            if(hstack) hstack.style.pointerEvents = 'none';
            var curVSlide = document.querySelector('.gfd-vslide[data-pos="current"] .gfd-slide');
            if(curVSlide) curVSlide.id = 'gfd-slide';
            nextItem = _gfdSlideItem(_gfmIndex);
        }

        _gfmMediaIndex = _gfdGetSavedMediaIndex(nextItem ? nextItem.id : null);
        _gfdActivateCurrentSlide(nextItem);
    }
    var onEnd = function(e){ if(e.target === layer && e.propertyName === 'transform') finish(); };
    layer.addEventListener('transitionend', onEnd);
    setTimeout(finish, 360);
}

/* ── Back dispatcher — if a Connected-Zeem layer is open, one tap
   slides back exactly one hop (never closes the modal); only once
   the stack is empty does back close the modal, same as before. ── */
function gfdHandleBackTap(){
    if(_gfdHStack.length > 0){
        gfdCloseConnectedZeem();
    } else {
        closeGFModal();
    }
}

/* ════════════════════════════════════════════════════════════
   GAPLESS VERTICAL TRACK ENGINE
   ════════════════════════════════════════════════════════════ */
function _gfdSlideItem(idx){
    if(idx < 0 || idx >= peuraGroupFeed.length) return null;
    return peuraGroupFeed[idx];
}

function _gfdBuildSlideWrap(item, pos){
    var wrap = document.createElement('div');
    wrap.className = 'gfd-vslide';
    wrap.dataset.pos = pos;
    if(item){
        var slideEl = document.createElement('div');
        slideEl.className = 'gfd-slide';
        if(pos === 'current') slideEl.id = 'gfd-slide';

        if(_gfdIsHidden(item.id)){
            slideEl.classList.add('gfd-slide-hidden');
            slideEl.innerHTML = _gfdRenderHiddenSlideContent(item.id);
        } else {
            slideEl.innerHTML = _gfdRenderPost(item, pos !== 'current');
        }

        wrap.appendChild(slideEl);
        wrap.querySelectorAll('.gfd-video-progress').forEach(gfdInitProgressScrub);
    }
    return wrap;
}

function _gfdActivateCurrentSlide(currentItem){
    gfdHideSavedToast();

    var newMemeId = currentItem ? currentItem.id : null;
    var isHiddenPost = _gfdIsHidden(newMemeId);

    // Always reset caption state and timers, hidden or not — this is
    // what was leaking stale state across a hidden post before.
    if(currentItem){
        var capState = _gfdComputeCaptionHtml(currentItem);
        _gfdCaptionFullHtml  = capState.full;
        _gfdCaptionTruncHtml = capState.trunc;
    }
    _gfdCaptionExpanded = false;
    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }

    if(isHiddenPost){
        _gfdPausedMemeIds[newMemeId] = true;
        _gfdStopSoundtrack();
        _gfmMemeId = newMemeId;
        return;
    }

    var shouldStayPaused = newMemeId !== null && !!_gfdPausedMemeIds[newMemeId];

    var curSlide = document.getElementById('gfd-slide');
    if(curSlide){
        _gfdInitMediaSwipe(curSlide);
        _gfdInitAudioMarquee(curSlide);
        _gfdInitInfoSwitcher(curSlide);
        gfdUpdateMediaGallery(curSlide, shouldStayPaused);

        var mediaImg = curSlide.querySelector('img.gfd-media');
        var textBg   = curSlide.querySelector('.gfd-textonly-bg');
        var activeVid = curSlide.querySelector('.gfd-media-item[data-idx="'+_gfmMediaIndex+'"] video.gfd-media')
                      || curSlide.querySelector('video.gfd-media');

        if(textBg){
            gfdHideSkeleton(curSlide);
            requestAnimationFrame(function(){ _gfdCheckCaptionOverflow(curSlide); });
        } else if(mediaImg && mediaImg.complete){
            gfdHideSkeleton(curSlide);
        } else if(activeVid && activeVid.readyState >= 2){
            gfdHideSkeleton(curSlide);
        }
        setTimeout(function(){ gfdHideSkeleton(curSlide); }, 2500);
    }

    _gfdSyncSoundtrack(currentItem);

    _gfmMemeId = newMemeId;
}

function _gfdMountTrack(centerIndex){
    _gfdVSwipeLocked = false;

    var inner = document.getElementById('gfd-inner');
    inner.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; v.load(); });

    var track = document.createElement('div');
    track.className = 'gfd-vtrack';
    track.id = 'gfd-vtrack';
    track.appendChild(_gfdBuildSlideWrap(_gfdSlideItem(centerIndex - 1), 'prev'));
    track.appendChild(_gfdBuildSlideWrap(_gfdSlideItem(centerIndex),     'current'));
    track.appendChild(_gfdBuildSlideWrap(_gfdSlideItem(centerIndex + 1), 'next'));

    inner.innerHTML = '';
    inner.appendChild(track);

    _gfmIndex = centerIndex;
    _gfdActivateCurrentSlide(_gfdSlideItem(centerIndex));
}

var GFD_OVERLAY_SELECTOR = '.gfd-top-bar, .gfd-top-search-btn, .gfd-top-right-tags, .gfd-social-proof-strip, .gfd-rail, .gfd-bottom, .gfd-hidden-overlay';

function _gfdOverlayEls(slideEl){
    return slideEl ? slideEl.querySelectorAll(GFD_OVERLAY_SELECTOR) : [];
}

function _gfdSetOverlayProgress(slideEl, progress, dir){
    if(!slideEl) return;
    var opacity   = 1 - (progress * 0.7);
    var translate = (dir || 0) * progress * 16;
    _gfdOverlayEls(slideEl).forEach(function(el){
        el.style.opacity   = opacity;
        el.style.transform = 'translateY('+translate+'px)';
    });
}

function _gfdResetAllOverlayStyles(track){
    if(!track) return;
    track.querySelectorAll('.gfd-vslide .gfd-slide').forEach(function(slideEl){
        _gfdOverlayEls(slideEl).forEach(function(el){
            el.style.transition = '';
            el.style.opacity    = '';
            el.style.transform  = '';
        });
    });
}

var _gfdVDragBound = false;
function _gfdInitVerticalDrag(){
    if(_gfdVDragBound) return;
    _gfdVDragBound = true;
    var stage = document.getElementById('gfd-stage');
    if(!stage) return;

    var startX=0, startY=0, dragging=false, committedVertical=false, decided=false, stageH=0;

    stage.addEventListener('touchstart', function(e){
        if(e.touches.length !== 1) return;
        if(_gfdVSwipeLocked) return;
        // Vertical feed navigation is fully locked while a
        // Connected-Zeem layer is open — the only way out is the
        // back control (gfdHandleBackTap), same as a linked reel.
        if(_gfdHStack.length > 0) return;
        if(_gfdIsInsideExpandedCaption(e.target)) return;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        dragging = true;
        committedVertical = false;
        decided = false;
        stageH = stage.clientHeight;
        var track = document.getElementById('gfd-vtrack');
        if(track){
            track.style.transition = 'none';
            track.querySelectorAll('.gfd-vslide .gfd-slide').forEach(function(slideEl){
                _gfdOverlayEls(slideEl).forEach(function(el){ el.style.transition = 'none'; });
            });
        }
    }, {passive:true});

    stage.addEventListener('touchmove', function(e){
        if(!dragging) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;

        if(!decided){
            if(Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 10){
                committedVertical = true; decided = true;
            } else if(Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10){
                dragging = false; decided = true; return;
            }
        }
        if(committedVertical){
            var track = document.getElementById('gfd-vtrack');
            if(track){
                var atTop    = _gfmIndex <= 0;
                var atBottom = _gfmIndex >= peuraGroupFeed.length - 1;
                var clamped = dy;
                if(atTop && clamped > 0)    clamped = clamped * 0.35;
                if(atBottom && clamped < 0) clamped = clamped * 0.35;
                clamped = Math.max(-stageH, Math.min(stageH, clamped));
                track.style.transform = 'translateY('+clamped+'px)';

                var progress = Math.min(1, Math.abs(clamped) / (stageH * 0.35));
                var dirOut   = clamped < 0 ? -1 : (clamped > 0 ? 1 : 0);
                var curSlideEl = track.querySelector('.gfd-vslide[data-pos="current"] .gfd-slide');
                _gfdSetOverlayProgress(curSlideEl, progress, dirOut);

                var incomingPos = clamped < 0 ? 'next' : (clamped > 0 ? 'prev' : null);
                ['next','prev'].forEach(function(pos){
                    var nSlideEl = track.querySelector('.gfd-vslide[data-pos="'+pos+'"] .gfd-slide');
                    if(!nSlideEl) return;
                    if(pos === incomingPos){
                        _gfdSetOverlayProgress(nSlideEl, 1 - progress, -dirOut);
                    } else {
                        _gfdSetOverlayProgress(nSlideEl, 1, dirOut);
                    }
                });
            }
        }
    }, {passive:true});

    stage.addEventListener('touchend', function(e){
        if(!dragging) return;
        dragging = false;
        var track = document.getElementById('gfd-vtrack');
        if(!track || !committedVertical){ return; }

        var dy = e.changedTouches[0].clientY - startY;
        track.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';

        var threshold = stageH * 0.18;
        if(dy < -threshold && _gfmIndex < peuraGroupFeed.length - 1){
            _gfdCommitTrackMove(1);
        } else if(dy > threshold && _gfmIndex > 0){
            _gfdCommitTrackMove(-1);
        } else {
            track.style.transform = 'translateY(0)';

            var cancelTransition = 'opacity .28s cubic-bezier(.22,1,.36,1), transform .28s cubic-bezier(.22,1,.36,1)';
            var curSlideEl = track.querySelector('.gfd-vslide[data-pos="current"] .gfd-slide');
            _gfdOverlayEls(curSlideEl).forEach(function(el){ el.style.transition = cancelTransition; });
            _gfdSetOverlayProgress(curSlideEl, 0, 0);

            ['next','prev'].forEach(function(pos){
                var nSlideEl = track.querySelector('.gfd-vslide[data-pos="'+pos+'"] .gfd-slide');
                if(!nSlideEl) return;
                _gfdOverlayEls(nSlideEl).forEach(function(el){ el.style.transition = cancelTransition; });
                _gfdSetOverlayProgress(nSlideEl, 1, 0);
            });
        }
    }, {passive:true});
}

function _gfdCommitTrackMove(dir){
    if(_gfdVSwipeLocked) return;
    if(_gfdHStack.length > 0) return; // locked while a Connected-Zeem layer is open

    var track = document.getElementById('gfd-vtrack');
    if(!track) return;

    _gfdVSwipeLocked = true;

    var stageH = document.getElementById('gfd-stage').clientHeight;
    track.style.transition = 'transform .28s cubic-bezier(.22,1,.36,1)';
    track.style.transform  = 'translateY('+(dir > 0 ? -stageH : stageH)+'px)';

    var keepPos     = dir > 0 ? 'next' : 'prev';
    var curSlideEl  = track.querySelector('.gfd-vslide[data-pos="current"] .gfd-slide');
    var nextSlideEl = track.querySelector('.gfd-vslide[data-pos="'+keepPos+'"] .gfd-slide');
    var commitTransition = 'opacity .28s cubic-bezier(.22,1,.36,1), transform .28s cubic-bezier(.22,1,.36,1)';
    _gfdOverlayEls(curSlideEl).forEach(function(el){ el.style.transition = commitTransition; });
    _gfdOverlayEls(nextSlideEl).forEach(function(el){ el.style.transition = commitTransition; });
    _gfdSetOverlayProgress(curSlideEl, 1, -dir);
    _gfdSetOverlayProgress(nextSlideEl, 0, dir);

    var newIndex = _gfmIndex + dir;
    var settled = false;
    var finish = function(){
        if(settled) return;
        settled = true;
        track.removeEventListener('transitionend', onEnd);
        _gfdFinishTrackMove(dir, newIndex);
    };
    var onEnd = function(e){
        if(e.target === track && e.propertyName === 'transform') finish();
    };
    track.addEventListener('transitionend', onEnd);
    setTimeout(finish, 320);
}

function _gfdFinishTrackMove(dir, newIndex){
    var track = document.getElementById('gfd-vtrack');
    if(!track){
        _gfdVSwipeLocked = false;
        return;
    }

    var keepPos = dir > 0 ? 'next' : 'prev';
    var dropPos = dir > 0 ? 'prev' : 'next';
    var keepNode = track.querySelector('.gfd-vslide[data-pos="'+keepPos+'"]');
    var dropNode = track.querySelector('.gfd-vslide[data-pos="'+dropPos+'"]');
    var oldCurrent = track.querySelector('.gfd-vslide[data-pos="current"]');

    if(dropNode){
        dropNode.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; v.load(); });
        dropNode.remove();
    }
    if(oldCurrent){
        oldCurrent.dataset.pos = dropPos;
        oldCurrent.querySelectorAll('video').forEach(function(v){ v.pause(); });
        var oldSlideEl = oldCurrent.querySelector('.gfd-slide');
        if(oldSlideEl) oldSlideEl.removeAttribute('id');
        if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }
    }
    if(keepNode){
        keepNode.dataset.pos = 'current';
        var innerSlide = keepNode.querySelector('.gfd-slide');
        if(innerSlide) innerSlide.id = 'gfd-slide';
    }

    track.style.transition = 'none';
    track.style.transform  = 'translateY(0)';
    void track.offsetHeight;

    var missingIndex = keepPos === 'next' ? newIndex + 1 : newIndex - 1;
    track.appendChild(_gfdBuildSlideWrap(_gfdSlideItem(missingIndex), keepPos));

    var promotedItem = _gfdSlideItem(newIndex);
    var promotedId   = promotedItem ? promotedItem.id : null;

    _gfmIndex      = newIndex;
    _gfmMediaIndex = _gfdGetSavedMediaIndex(promotedId);
    _gfdActivateCurrentSlide(promotedItem);

    _gfdVSwipeLocked = false;
}

/* ── PUBLIC API — names kept identical ── */
function gfmLoadIndex(index){
    if(_gfdVSwipeLocked) return;
    if(_gfdHStack.length > 0) return; // locked while a Connected-Zeem layer is open
    if(index < 0 || index >= peuraGroupFeed.length || index === _gfmIndex) return;
    if(Math.abs(index - _gfmIndex) === 1){
        _gfdCommitTrackMove(index > _gfmIndex ? 1 : -1);
    } else {
        _gfdMountTrack(index);
    }
}

/* ══════════════════════════════════════════════════════════
   MONKEY-PATCH: wire gfHidePost / gfUndoHidePost (defined in
   the earlier script block, near gfLoadHiddenIds) so they call
   gfdShowHiddenOverlay / gfdHideHiddenOverlay the INSTANT a hide
   or undo happens — not just the next time the modal re-activates
   a slide.

   Runs after the whole page (including the other script block)
   has parsed, so gfHidePost/gfUndoHidePost are guaranteed to
   already exist by the time this wraps them.
   ══════════════════════════════════════════════════════════ */
function _gfdPatchHideFns(){
    if (typeof window.gfHidePost === 'function' && !window.gfHidePost._gfdPatched) {
        var _origHide = window.gfHidePost;
        window.gfHidePost = function(memeId, skipServerCall){
            _origHide(memeId, skipServerCall);
            if (typeof _gfmMemeId !== 'undefined' && String(_gfmMemeId) === String(memeId)) {
                gfdShowHiddenOverlay(memeId);
            }
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

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    _gfdPatchHideFns();
} else {
    document.addEventListener('DOMContentLoaded', _gfdPatchHideFns);
}
window.addEventListener('load', _gfdPatchHideFns);

/* ════════════════════════════════════════════════════════════
   BODY SCROLL LOCK — iOS-safe (overflow:hidden alone doesn't stop
   touch scroll/rubber-banding on iOS Safari). Pins the body with
   position:fixed at the current scroll offset while the modal is
   open, and restores the exact scroll position on close.
   ════════════════════════════════════════════════════════════ */
var _gfdScrollLockY = 0;

function gfdLockBodyScroll(){
    _gfdScrollLockY = window.scrollY || window.pageYOffset || 0;
    document.body.style.position = 'fixed';
    document.body.style.top      = '-' + _gfdScrollLockY + 'px';
    document.body.style.left     = '0';
    document.body.style.right    = '0';
    document.body.style.width    = '100%';
    document.body.style.overflow = 'hidden';
}

function gfdUnlockBodyScroll(){
    document.body.style.position = '';
    document.body.style.top      = '';
    document.body.style.left     = '';
    document.body.style.right    = '';
    document.body.style.width    = '';
    document.body.style.overflow = '';
    window.scrollTo(0, _gfdScrollLockY);
}

function openGFModal(memeId){
    var idx = peuraGroupFeed.findIndex(function(p){ return String(p.id) === String(memeId); });
    var targetIdx = (idx >= 0) ? idx : 0;

    _gfdVSwipeLocked = false;

    // Always start a fresh open with no Connected-Zeem layers
    // carried over from a previous session.
    var existingHstack = document.getElementById('gfd-hstack');
    if(existingHstack){
        existingHstack.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
        existingHstack.innerHTML = '';
        existingHstack.style.pointerEvents = 'none';
    }
    _gfdHStack = [];

    _gfdMountTrack(targetIdx);
    _gfdEnsureHStackEl();
    _gfdInitVerticalDrag();

    document.getElementById('gfModal').classList.add('gfm-open');
    gfdLockBodyScroll();
}

function closeGFModal(){
    // clear hstack instantly so a fresh open never starts mid-chain
    var hstack = document.getElementById('gfd-hstack');
    if(hstack){
        hstack.querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
        hstack.innerHTML = '';
        hstack.style.pointerEvents = 'none';
    }
    _gfdHStack = [];

    document.getElementById('gfd-inner').querySelectorAll('video').forEach(function(v){ v.pause(); v.src=''; });
    _gfdStopSoundtrack();
    if(_gfdInfoSwitchTimer){ clearInterval(_gfdInfoSwitchTimer); _gfdInfoSwitchTimer = null; }
    gfdHideSavedToast();
    document.getElementById('gfModal').classList.remove('gfm-open');
    gfdUnlockBodyScroll();
    _gfmMemeId = null;
    _gfmMediaIndex = 0;
    _gfdVSwipeLocked = false;
}

/* ── ACTION HANDLER — hits group.php's ajax_like / ajax_repost /
     ajax_favorite endpoints; optimistic UI update with per-action
     lock and revert-on-failure, scoped to the CURRENT slide only
     (so with 3 slides mounted at once, an action never accidentally
     touches a prev/next slide's duplicate-id button). ── */
var _gfdActionLocks = {};

function gfdAction(type){
    if(!_gfmMemeId) return;
    var memeId  = _gfmMemeId;
    var lockKey = memeId + ':' + type;

    if(_gfdActionLocks[lockKey]) return;
    _gfdActionLocks[lockKey] = true;

    var actionParam       = { like:'ajax_like', repost:'ajax_repost', fav:'ajax_favorite' }[type];
    var stateKeyOnServer  = { like:'liked', repost:'reposted', fav:'favorited' }[type];
    var btnMap    = { like:'gfd-like-btn',   repost:'gfd-repost-btn',   fav:'gfd-fav-btn'    };
    var cntMap    = { like:'gfd-like-count', repost:'gfd-repost-count', fav:'gfd-fav-count'  };
    var cntField  = { like:'likeCount',      repost:'repostCount',      fav:'favCount'        };
    var stateField= { like:'liked',          repost:'reposted',         fav:'faved'           };
    var gridSel   = { like:'.gf-like',       repost:'.gf-repost',       fav:'.gf-fav'         };

    var slideEl = document.getElementById('gfd-slide');
    var cmBtn  = slideEl ? slideEl.querySelector('#'+btnMap[type]) : null;
    var cmSpan = slideEl ? slideEl.querySelector('#'+cntMap[type]) : null;

    var wasActive      = cmBtn ? cmBtn.classList.contains('active') : false;
    var optimisticNext = !wasActive;
    var prevCountText  = cmSpan ? cmSpan.textContent : '0';
    var prevCountNum   = parseInt(prevCountText, 10) || 0;
    var optimisticCount = optimisticNext ? prevCountNum + 1 : Math.max(0, prevCountNum - 1);

    if(cmBtn)  cmBtn.classList.toggle('active', optimisticNext);
    if(cmSpan) cmSpan.textContent = optimisticCount;

    if(cmBtn){
        var actionIcon = cmBtn.querySelector('i');
        if(actionIcon){
            cmBtn.classList.remove('arc-swap-active');
            void actionIcon.offsetWidth; // force reflow so rapid re-taps restart the animation
            cmBtn.classList.add('arc-swap-active');
            actionIcon.addEventListener('animationend', function handler(){
                cmBtn.classList.remove('arc-swap-active');
                actionIcon.removeEventListener('animationend', handler);
            });
        }
    }

    // Saving to favorites: show the "Saved · Collections" toast right
    // away, optimistically — same pattern as profile.php's CCM modal.
    if(type === 'fav'){
        if(optimisticNext) gfdShowSavedToast(memeId);
        else gfdHideSavedToast();
    }

    fetch('group.php?id=' + gfGroupId, {
        method:  'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body:    actionParam + '=1&meme_id=' + memeId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        delete _gfdActionLocks[lockKey];

        if(data.count === undefined){
            if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
            if(cmSpan) cmSpan.textContent = prevCountText;
            // Rollback: the save never actually happened, so the toast shouldn't stand.
            if(type === 'fav' && optimisticNext) gfdHideSavedToast();
            return;
        }

        var nowActive = !!data[stateKeyOnServer];
        if(cmBtn)  cmBtn.classList.toggle('active', nowActive);
        if(cmSpan) cmSpan.textContent = data.count;

        // Server disagreed with our optimistic guess — reconcile the toast.
        if(type === 'fav' && nowActive !== optimisticNext){
            if(nowActive) gfdShowSavedToast(memeId);
            else gfdHideSavedToast();
        }

        var gridBtn = document.querySelector(gridSel[type]+'[data-id="'+memeId+'"]');
        if(gridBtn){
            gridBtn.classList.toggle('active', nowActive);
            var gridCnt = gridBtn.querySelector('span');
            if(gridCnt) gridCnt.textContent = data.count;
        }

        var item = peuraGroupFeed.find(function(p){ return String(p.id) === String(memeId); });
        if(item){
            item[cntField[type]]   = data.count;
            item[stateField[type]] = nowActive;
        }
    })
    .catch(function(err){
        delete _gfdActionLocks[lockKey];
        console.error('gfdAction failed:', err);
        if(cmBtn)  cmBtn.classList.toggle('active', wasActive);
        if(cmSpan) cmSpan.textContent = prevCountText;
        if(type === 'fav' && optimisticNext) gfdHideSavedToast();
    });
}

/* ────────────────────────────────────────────────────────────
   MOUSE DETECTION — powers the multimedia hover arrows, exactly
   like the discover modal. A real mouse moving over the modal adds
   .gfd-has-mouse to #gfModal; any touch/pen press removes it. This
   replaces the old (hover:none) media query, which some desktop /
   hybrid / emulated setups mis-report, leaving the arrows hidden.
──────────────────────────────────────────────────────────── */
(function _gfdInitMouseDetect(){
    var modal = document.getElementById('gfModal');
    if(!modal) return;
    modal.addEventListener('pointermove', function(e){
        if(e.pointerType === 'mouse') modal.classList.add('gfd-has-mouse');
    }, { passive:true });
    modal.addEventListener('pointerdown', function(e){
        if(e.pointerType && e.pointerType !== 'mouse') modal.classList.remove('gfd-has-mouse');
    }, { passive:true });
})();

/* ── desktop wheel + keyboard nav (unchanged behavior, now
   delegating to the track engine via gfmLoadIndex) ── */
document.getElementById('gfModal').addEventListener('wheel', function(e){
    if(_gfdIsInsideExpandedCaption(e.target)) return;

    e.preventDefault();
    var mediaBox = e.target.closest && e.target.closest('#gfd-media-box');
    var mediaCount = mediaBox ? parseInt(mediaBox.dataset.count || '1', 10) : 1;
    if(mediaBox && mediaCount > 1 && Math.abs(e.deltaX) > Math.abs(e.deltaY)){
        if(e.deltaX > 0) gfdMediaNext(); else gfdMediaPrev();
        return;
    }
    if(e.deltaY > 0) gfmLoadIndex(_gfmIndex+1);
    else             gfmLoadIndex(_gfmIndex-1);
}, { passive:false });

document.addEventListener('keydown', function(e){
    if(document.getElementById('gfModal').classList.contains('gfm-open')){
        if(e.key === 'Escape')    gfdHandleBackTap();
        if(e.key === 'ArrowDown') gfmLoadIndex(_gfmIndex+1);
        if(e.key === 'ArrowUp')   gfmLoadIndex(_gfmIndex-1);
        if(e.key === 'ArrowRight') gfdMediaNext();
        if(e.key === 'ArrowLeft')  gfdMediaPrev();
    }
});

window.addEventListener('resize', function(){
    var slide = document.getElementById('gfd-slide');
    if(slide){
        _gfdCheckCaptionOverflow(slide);
        _gfdInitAudioMarquee(slide);
    }
});

function gfmToggleTrack(btn) {
    var userId = btn.dataset.user;
    var wasFollowing = btn.classList.contains('following');

    btn.classList.toggle('following', !wasFollowing);
    btn.textContent = wasFollowing ? 'Track' : 'Untrack';

    fetch('group.php?id=<?php echo (int)$group_id; ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_follow&user_id=' + userId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.status !== 'ok') {
            btn.classList.toggle('following', wasFollowing);
            btn.textContent = wasFollowing ? 'Untrack' : 'Track';
            return;
        }
        var nowFollowing = !!data.following;

        document.querySelectorAll(
            '.gf-track-btn[data-user="' + userId + '"], .gfd-track-btn[data-user="' + userId + '"]'
        ).forEach(function (b) {
            b.classList.toggle('following', nowFollowing);
            b.textContent = nowFollowing ? 'Untrack' : 'Track';
        });

        if (typeof peuraGroupFeed !== 'undefined') {
            peuraGroupFeed.forEach(function (p) {
                if (String(p.userId) === String(userId)) p.following = nowFollowing;
            });
        }
    })
    .catch(function () {
        btn.classList.toggle('following', wasFollowing);
        btn.textContent = wasFollowing ? 'Untrack' : 'Track';
    });
}

function gfToggleTrack(btn) {
    gfmToggleTrack(btn);
}

/* ────────────────────────────────────────────────────────────
   SHARE MODAL — external apps + follow-list picker → shares table + chat message
   (mirrors home.php's CCM share modal exactly, wired to peuraGroupFeed)
──────────────────────────────────────────────────────────── */
if (typeof showToast !== 'function') {
    window.showToast = function(msg){
        var existing = document.getElementById('peura-toast');
        if (existing) existing.remove();
        var t = document.createElement('div');
        t.id = 'peura-toast';
        t.textContent = msg;
        Object.assign(t.style, {
            position:'fixed', bottom:'90px', left:'50%', transform:'translateX(-50%)',
            background:'rgba(20,20,25,.95)', border:'1px solid rgba(47,111,214,.45)',
            color:'#fff', padding:'8px 20px', borderRadius:'50px', fontSize:'12px',
            zIndex:'9999999', pointerEvents:'none', boxShadow:'0 8px 24px rgba(0,0,0,.6)',
            transition:'opacity .3s', whiteSpace:'nowrap'
        });
        document.body.appendChild(t);
        setTimeout(function(){ t.style.opacity = '0'; setTimeout(function(){ t.remove(); }, 300); }, 2200);
    };
}

var _gfShareMemeId   = null;
var _gfShareAllUsers = [];
var _gfShareSelected = new Set();

function _gfShareGetLink(){
    return window.location.origin + '/home.php?meme_id=' + _gfShareMemeId;
}

function _gfShareGetCaption(){
    if(!_gfShareMemeId) return '';
    var item = peuraGroupFeed.find(function(p){ return String(p.id) === String(_gfShareMemeId); });
    return item && item.caption ? item.caption : 'Check this out on Zeems';
}

function openShareModal(memeId){
    _gfShareMemeId   = memeId;
    _gfShareSelected = new Set();
    document.getElementById('shareSearchInput').value = '';
    document.getElementById('shareModalList').innerHTML = '<div class="share-modal-empty">Loading…</div>';
    updateShareFooter();

    var nativeBtn = document.getElementById('shareNativeBtn');
    if(nativeBtn) nativeBtn.style.display = (navigator.share ? 'flex' : 'none');

    document.getElementById('shareModalOverlay').classList.add('show');
    document.getElementById('shareModalPanel').classList.add('show');
    document.body.style.overflow = 'hidden';

    fetch('home.php?type=following')
        .then(function(r){ return r.json(); })
        .then(function(users){
            _gfShareAllUsers = users || [];
            renderShareList(_gfShareAllUsers);
        })
        .catch(function(){
            document.getElementById('shareModalList').innerHTML =
                '<div class="share-modal-empty">Couldn\'t load your tracked people</div>';
        });
}

function closeShareModal(){
    document.getElementById('shareModalOverlay').classList.remove('show');
    document.getElementById('shareModalPanel').classList.remove('show');
    document.body.style.overflow = '';
    _gfShareMemeId = null;
}

function shareViaCopyLink(){
    var link = _gfShareGetLink();
    navigator.clipboard.writeText(link).then(function(){
        showToast('🔗 Link copied');
    }).catch(function(){
        var ta = document.createElement('textarea');
        ta.value = link;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showToast('🔗 Link copied'); } catch(e){ showToast('Could not copy link'); }
        ta.remove();
    });
}

function shareViaWhatsApp(){
    var text = encodeURIComponent(_gfShareGetCaption() + ' — ' + _gfShareGetLink());
    window.open('https://wa.me/?text=' + text, '_blank');
}

function shareViaTelegram(){
    var url  = encodeURIComponent(_gfShareGetLink());
    var text = encodeURIComponent(_gfShareGetCaption());
    window.open('https://t.me/share/url?url=' + url + '&text=' + text, '_blank');
}

function shareViaTwitter(){
    var text = encodeURIComponent(_gfShareGetCaption());
    var url  = encodeURIComponent(_gfShareGetLink());
    window.open('https://twitter.com/intent/tweet?text=' + text + '&url=' + url, '_blank');
}

function shareViaFacebook(){
    var url = encodeURIComponent(_gfShareGetLink());
    window.open('https://www.facebook.com/sharer/sharer.php?u=' + url, '_blank');
}

function shareViaEmail(){
    var subject = encodeURIComponent('Check this out on Zeems');
    var body    = encodeURIComponent(_gfShareGetCaption() + '\n\n' + _gfShareGetLink());
    window.location.href = 'mailto:?subject=' + subject + '&body=' + body;
}

function shareViaNative(){
    if(!navigator.share) return;
    navigator.share({
        title: 'Zeems',
        text:  _gfShareGetCaption(),
        url:   _gfShareGetLink()
    }).catch(function(){ /* user cancelled — no-op */ });
}

function renderShareList(users){
    var list = document.getElementById('shareModalList');
    if(!users.length){
        list.innerHTML = '<div class="share-modal-empty">You aren\'t tracking anyone yet</div>';
        return;
    }
    list.innerHTML = users.map(function(u){
        var pic = u.profile_pic || 'uploads/me.jpeg';
        var sel = _gfShareSelected.has(String(u.id));
        return '<div class="share-user-row'+(sel?' selected':'')+'" data-uid="'+u.id+'" '+
            'onclick="toggleShareUser('+u.id+', this)">'+
            '<img src="'+pic+'" onerror="this.src=\'uploads/me.jpeg\'">'+
            '<span class="share-user-name">'+u.username+'</span>'+
            '<span class="share-user-check"><i class="ri-check-line"></i></span>'+
        '</div>';
    }).join('');
}

function toggleShareUser(userId, el){
    var key = String(userId);
    if(_gfShareSelected.has(key)){ _gfShareSelected.delete(key); el.classList.remove('selected'); }
    else { _gfShareSelected.add(key); el.classList.add('selected'); }
    updateShareFooter();
}

function updateShareFooter(){
    var n = _gfShareSelected.size;
    document.getElementById('shareSelCount').textContent = n + ' selected';
    document.getElementById('shareSendBtn').disabled = n === 0;
}

function filterShareList(){
    var q = document.getElementById('shareSearchInput').value.toLowerCase();
    var filtered = _gfShareAllUsers.filter(function(u){
        return u.username.toLowerCase().includes(q);
    });
    renderShareList(filtered);
}

function sendShare(){
    if(!_gfShareMemeId || _gfShareSelected.size === 0) return;
    var btn = document.getElementById('shareSendBtn');
    btn.disabled = true;
    btn.textContent = 'Sending…';

    var ids = Array.from(_gfShareSelected).join(',');

    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=share_post&meme_id='+_gfShareMemeId+'&receiver_ids='+encodeURIComponent(ids)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        btn.disabled = false;
        btn.textContent = 'Send';
        if(data.status === 'ok'){
            var countEl = document.getElementById('gfd-share-count');
            if(countEl) countEl.textContent = data.count;

            var gridShareBtn = document.querySelector('.gf-share[data-id="'+_gfShareMemeId+'"]');
            if(gridShareBtn){
                var gc = gridShareBtn.querySelector('span');
                if(gc) gc.textContent = data.count;
            }

            var item = peuraGroupFeed.find(function(p){ return String(p.id) === String(_gfShareMemeId); });
            if(item) item.shareCount = data.count;

            showToast('Shared with '+data.sent_to.length+' '+(data.sent_to.length===1?'person':'people'));
            closeShareModal();
        } else {
            showToast(data.message || 'Share failed');
        }
    })
    .catch(function(){
        btn.disabled = false;
        btn.textContent = 'Send';
        showToast('Network error');
    });
}
</script>