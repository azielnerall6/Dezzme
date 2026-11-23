<?php
/* ══════════════════════════════════════════════════════════════
   profile_mutuals.php
   Include inside profile.php (outside any block check).
   Requires: $conn, $current_user, $profile_id already set.
   Verified accounts get the filled flower badge next to their
   name inside the mutuals MODAL only (not in the strip).
   The badge uses the SAME colour as the profile-pic border
   (one variable: --mu-ring). Change it once, both follow.
   Needs verified_badge.php in the same folder.
══════════════════════════════════════════════════════════════ */

$mu_me  = (int)$current_user;   // only integer-cast ids are ever placed into SQL text
$mu_pid = (int)$profile_id;
if($mu_me <= 0 || $mu_pid <= 0 || $mu_me === $mu_pid) return;

require_once __DIR__ . '/verified_badge.php';

/* ── Small helpers ── */
if(!function_exists('mu_h')){
    // escape for HTML text AND attributes (quotes included)
    function mu_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if(!function_exists('mu_lc')){
    function mu_lc($v){ return function_exists('mb_strtolower') ? mb_strtolower((string)$v, 'UTF-8') : strtolower((string)$v); }
}
if(!function_exists('mu_safe_img')){
    // image src may only be a relative path or an http(s) URL — blocks javascript:, data:, etc.
    function mu_safe_img($p){
        $p = trim((string)$p);
        if($p === '') return 'mee.jpg';
        if(preg_match('#^(?:https?:)?//#i', $p) || !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $p)) return $p;
        return 'mee.jpg';
    }
}

/* ── Deactivated accounts never show up as a mutual (safe before the SQL is run: the filter is only added when users.deactivated_at exists) ── */
$mu_has_deact = false;
if(function_exists('dz_has_col')){
    $mu_has_deact = (bool)dz_has_col($conn, 'users', 'deactivated_at');
} else {
    $mu_chk = $conn->query("SHOW COLUMNS FROM `users` LIKE 'deactivated_at'");
    $mu_has_deact = ($mu_chk && $mu_chk->num_rows > 0);
}
$mu_not_deact = $mu_has_deact ? " AND u.deactivated_at IS NULL" : "";

$mutuals_result = $conn->query("
    SELECT u.id, u.username, u.nickname, u.profile_pic, u.is_verified, u.user_rank
    FROM   followers f1
    JOIN   followers f2 ON f2.follower_id  = f1.following_id
                       AND f2.following_id = $mu_pid
    JOIN   users     u  ON u.id            = f1.following_id
    WHERE  f1.follower_id   = $mu_me
      AND  f1.following_id != $mu_me
      AND  f1.following_id != $mu_pid
      $mu_not_deact
      AND  LOWER(COALESCE(u.user_rank,'')) <> 'banned'
    ORDER BY u.username ASC
");

if(!$mutuals_result || $mutuals_result->num_rows === 0) return;

$mutuals_total = (int)$mutuals_result->num_rows;
$preview_users = [];
$all_mutuals   = [];

/* the strip never shows a suspended account (the next person takes their place); the modal lists everyone, suspended accounts included */
$strip_total = 0;
while($row = $mutuals_result->fetch_assoc()){
    $row['is_susp'] = (mu_lc($row['user_rank'] ?? '') === 'suspended');
    if(!$row['is_susp']){
        $strip_total++;
        if(count($preview_users) < 3) $preview_users[] = $row;
    }
    $all_mutuals[] = $row;
}
?>
<?php echo dz_badge_sprite(); ?>

<!-- ══ STYLES FIRST — must load before the markup so nothing paints unstyled ══ -->
<style>
/* ══ SKELETON SHIMMER (modal only) ══ */
@keyframes skeletonShimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
.skeleton-line {
    background: linear-gradient(
        90deg,
        rgba(255,255,255,0.06) 25%,
        rgba(255,255,255,0.16) 50%,
        rgba(255,255,255,0.06) 75%
    );
    background-size: 200% 100%;
    animation: skeletonShimmer 1.4s ease-in-out infinite;
    border-radius: 4px;
    display: inline-block;
}

.mutual-row-pic.skeleton-circle {
    background: linear-gradient(
        90deg,
        rgba(255,255,255,0.06) 25%,
        rgba(255,255,255,0.16) 50%,
        rgba(255,255,255,0.06) 75%
    );
    background-size: 200% 100%;
    animation: skeletonShimmer 1.4s ease-in-out infinite;
    border: none;
    box-shadow: none;
}

.mutuals-fade-in { animation: mutualsContentFadeIn 0.3s ease forwards; }
@keyframes mutualsContentFadeIn { from { opacity: 0; } to { opacity: 1; } }

/* ── Strip (visible immediately, no skeleton) ── */
.mutuals-strip {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-top: -6px;
    padding: 4px 8px 4px 2px;
    border-radius: 40px;
    max-width: 100%;
    cursor: pointer;
    user-select: none;
    transition: background 0.2s ease;
    -webkit-tap-highlight-color: transparent;
}
.mutuals-strip:hover  { background: transparent; }
.mutuals-strip:active { transform: scale(0.97); }

/* ── Overlapping avatars ── */
.mutuals-pics { display: flex; align-items: center; flex-shrink: 0; }
.mutual-thumb {
    width: 33px; height: 33px;
    border-radius: 50%; object-fit: cover;
    border: 2.5px solid #FF2D2D;
    margin-left: -11px;
    transition: transform 0.2s ease;
    background: #111;
    box-shadow: 0 0 0 2px #0a0b1e;
    position: relative; flex-shrink: 0;
}
.mutual-thumb:first-child { margin-left: 0; }
.mutuals-strip:hover .mutual-thumb { transform: scale(1.08); }

/* ── Label ── */
.mutuals-label {
    font-size: clamp(10.72px, 2.73vw, 12.67px);
    color: rgba(255,255,255,0.5);
    margin: 0;
    font-family: 'Poppins', sans-serif;
    line-height: 1.4; min-width: 0;
    white-space: normal; word-break: break-word;
}
.mutuals-label strong { color: rgba(255,255,255,0.92); font-weight: 600; }

/* verified badge (modal rows only): filled, same size as the text,
   colour = the SAME variable the profile-pic border uses (--mu-ring), dark AND light mode.
   !important + svg fill make sure nothing from verified_badge.php can override it. */
.mutual-row-username .vbadge {
    width: 1em; height: 1em;
    vertical-align: baseline;
    flex-shrink: 0;
    color: var(--mu-ring, #FF2D2D) !important;
    fill: currentColor;
}

/* ═══════════════════════════════════════════════════════════
   MUTUALS MODAL — same design language as the post modal
   • full-screen overlay, content in a CENTERED LANE
   • two grey lines separate the lane from the empty space
   • rectangular 5px controls, premium fluid sizing
   • dark mid-tone BLUE accent (X close hovers RED)
═══════════════════════════════════════════════════════════ */
.mutuals-modal-overlay {
    --pm-blue:#2a5fd8; --pm-blue-h:#234fb5; --pm-blue-l:#7ea6ff;
    --pm-blue-dim:rgba(42,95,216,.18); --pm-blue-glow:rgba(42,95,216,.34);
    --pm-card:rgba(255,255,255,.05); --pm-card2:rgba(255,255,255,.08);
    --pm-line:rgba(255,255,255,.09); --pm-line2:rgba(255,255,255,.14);
    --pm-sub:rgba(255,255,255,.62); --pm-mut:rgba(255,255,255,.45);
    --pm-red:#FF2D2D;
    --mu-ring:#FF2D2D;   /* profile-pic border colour AND verified badge colour */
    --pm-pad:clamp(9px,3vw,16px);
    --pm-act-h:clamp(25px,7.2vw,30px);
    --pm-r:5px;
    --pm-fs:.8rem;
    --pm-tb-h:54px;

    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999;
    background: linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment: fixed;
    justify-content: center;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: var(--pm-fs);
    color: #fff;
    -webkit-font-smoothing: antialiased;
}
@media (max-width:490px){ .mutuals-modal-overlay{ --pm-fs:0.807rem; --pm-tb-h:52.32px; } }
@media (max-width:390px){ .mutuals-modal-overlay{ --pm-fs:0.763rem;  --pm-tb-h:47.96px; } }

.mutuals-modal-overlay.open {
    display: flex;
    animation: mutualSlideIn 0.28s cubic-bezier(0.22,1,0.36,1);
}
@keyframes mutualSlideIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

.mutuals-modal-overlay *,
.mutuals-modal-overlay *::before,
.mutuals-modal-overlay *::after { box-sizing: border-box; }
.mutuals-modal-overlay button,
.mutuals-modal-overlay input { font-family: 'Poppins', sans-serif; }
.mutuals-modal-overlay button { cursor: pointer; -webkit-tap-highlight-color: transparent; }
.mutuals-modal-overlay button,
.mutuals-modal-overlay input { outline: none; }
/* icons keep the icon font */
.mutuals-modal-overlay [class^="ri-"],
.mutuals-modal-overlay [class*=" ri-"],
.mutuals-modal-overlay [class^="ri-"]::before,
.mutuals-modal-overlay [class*=" ri-"]::before {
    font-family: 'remixicon' !important; font-style: normal; font-weight: normal; line-height: 1;
}

/* ── the lane + two grey lines ── */
.mutuals-modal-card {
    width: 100%; max-width: 580px; height: 100%;
    display: flex; flex-direction: column; min-height: 0;
    background: transparent;
    overflow: hidden;
}
@media (min-width: 801px) {
    .mutuals-modal-card { border-left: 1px solid var(--pm-line); border-right: 1px solid var(--pm-line); }
}
@media (max-width: 800px) { .mutuals-modal-card { max-width: 100%; } }

/* ── Top bar (no grey line) ── */
.mutuals-topbar {
    flex-shrink: 0;
    display: flex; align-items: center;
    min-height: var(--pm-tb-h);
    padding: env(safe-area-inset-top) 10px 0;
    background: transparent;
    border: none;
}
.mutuals-tb-side { flex: 0 0 38px; display: flex; align-items: center; }
.mutuals-modal-close {
    width: 34px; height: 34px; background: none; border: none; color: #fff;
    display: flex; align-items: center; justify-content: center; opacity: .9;
    padding: 0; transition: 0.2s;
}
/* X hover = RED */
.mutuals-modal-close:hover,
.mutuals-modal-close:focus-visible,
.mutuals-modal-close:active { color: var(--pm-red); opacity: 1; }
.mutuals-modal-close svg { width: 22px; height: 22px; display: block; }

/* +count + title sit on the right */
.mutuals-topbar-right { flex: 1; min-width: 0; display: flex; justify-content: flex-end; text-align: right; }
.mutuals-modal-title {
    font-size: .92rem; font-weight: 700; color: #fff; line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: flex; align-items: center; justify-content: flex-end; gap: 6px;
}
@media (max-width:490px){ .mutuals-modal-title{ font-size:0.916rem; } .mutuals-modal-close{ width:32.7px; height:32.7px; } }
@media (max-width:390px){ .mutuals-modal-title{ font-size:0.872rem; } }

/* ── Count badge — white text only (shows as +5) ── */
.mutuals-count-badge {
    font-size: inherit;
    font-weight: 700;
    color: #fff;
    background: none;
    border: none;
    padding: 0;
    line-height: 1;
}

/* ── Search bar — rectangular like post modal inputs ── */
.mutuals-search-wrap {
    padding: 8px var(--pm-pad) 10px;
    flex-shrink: 0; position: relative;
    border: none;
}
.mutuals-search-icon {
    position: absolute; left: calc(var(--pm-pad) + 11px); top: 50%;
    transform: translateY(-50%);
    margin-top: -1px;
    color: var(--pm-mut); font-size: 14px; pointer-events: none;
}
.mutuals-search-input {
    width: 100%; height: calc(var(--pm-act-h) + 6px);
    padding: 0 11px 0 34px; border-radius: var(--pm-r);
    border: 1px solid var(--pm-line2); background: var(--pm-card); color: #fff;
    font-size: .76rem; transition: .2s; -webkit-appearance: none; appearance: none;
}
.mutuals-search-input::placeholder { color: var(--pm-mut); }
.mutuals-search-input:focus {
    border-color: rgba(42,95,216,.85);
    box-shadow: 0 0 0 3px rgba(42,95,216,.18);
    background: var(--pm-card);
}
@media (max-width:490px){ .mutuals-search-input{ font-size:0.763rem; } }

/* ── Scrollable list (grey scrollbar) ── */
.mutuals-modal-list {
    overflow-y: auto; overflow-x: hidden; flex: 1; min-height: 0;
    -webkit-overflow-scrolling: touch;
    padding: 2px 0 calc(env(safe-area-inset-bottom, 0px) + 24px);
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.25) transparent;
}
.mutuals-modal-list::-webkit-scrollbar { width: 4px; }
.mutuals-modal-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,.25); border-radius: 99px; }

.mutuals-skeleton-wrap { display: block; }
.mutuals-real-wrap      { display: none; }

/* ── Each row ── */
.mutual-row {
    display: flex; align-items: center; gap: 14px;
    padding: 12px var(--pm-pad); cursor: pointer;
    transition: background 0.15s ease;
    -webkit-tap-highlight-color: transparent;
}
.mutual-row:hover  { background: rgba(255,255,255,0.05); }
.mutual-row:active { background: var(--pm-blue-dim); }
.mutual-row.hidden { display: none; }
.mutual-row-skeleton       { cursor: default; }
.mutual-row-skeleton:hover { background: transparent; }

/* ── Profile pics in modal (border colour = --mu-ring, same as the verified badge) ── */
.mutual-row-pic {
    width: 52px; height: 52px; border-radius: 50%;
    object-fit: cover; border: 2.5px solid var(--mu-ring, #FF2D2D);
    flex-shrink: 0; background: #1a1a1a;
    box-shadow: 0 0 12px rgba(255,45,45,0.22);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: block;
}
.mutual-row-pic.mutual-row-pic-susp { background: rgba(255,255,255,.1); box-shadow: none; }
.mutual-row-nick.mutual-row-susp { color: var(--mu-ring, #FF2D2D); font-weight: 700; }
.mutual-row:hover .mutual-row-pic {
    transform: scale(1.06);
    box-shadow: 0 0 18px rgba(255,45,45,0.4);
}

.mutual-row-info {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column; gap: 3px;
}
/* name + badge on one line; a long name is cut with "…" but the badge never is */
.mutual-row-username {
    font-size: .82rem;
    font-weight: 700; color: #fff;
    font-family: 'Poppins', sans-serif;
    display: flex; align-items: center; gap: .3em; min-width: 0;
}
.mutual-row-username .mu-nm {
    min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.mutual-row-nick {
    font-size: .64rem;
    color: var(--pm-mut);
    font-family: 'Poppins', sans-serif;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* ── Chevron: white + a bit bigger ── */
.mutual-row-arrow {
    color: #fff; font-size: 22px; flex-shrink: 0;
    transition: transform 0.2s ease;
}
.mutual-row:hover .mutual-row-arrow { transform: translateX(3px); color: #fff; }

/* ── Skeleton row text sizes ── */
.mutuals-skel-username { width: 130px; height: 14px; margin-bottom: 2px; }
.mutuals-skel-nick      { width: 90px;  height: 11px; }

/* ── Divider between rows (grey, shimmering) ── */
.mutual-divider {
    height: 1px;
    margin: 0 var(--pm-pad);
    background: linear-gradient(
        90deg,
        rgba(255,255,255,0.06) 0%,
        rgba(255,255,255,0.06) 35%,
        rgba(255,255,255,0.28) 50%,
        rgba(255,255,255,0.06) 65%,
        rgba(255,255,255,0.06) 100%
    );
    background-size: 250% 100%;
    animation: mutualDividerShimmer 2.6s linear infinite;
}
@keyframes mutualDividerShimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ── No results ── */
.mutuals-no-results {
    display: none;
    flex-direction: column; align-items: center; gap: 8px;
    padding: 40px 20px; text-align: center;
    color: var(--pm-mut); font-size: .72rem;
    font-family: 'Poppins', sans-serif;
}
.mutuals-no-results i { font-size: 1.8rem; color: rgba(126,166,255,.45); }
.mutuals-no-results.show { display: flex; }

@media (prefers-reduced-motion:reduce){
    .mutuals-modal-overlay *{ transition-duration:.01ms !important; animation-duration:.01ms !important; }
}

/* ══ RESPONSIVE ══ */
@media (max-width: 768px) {
    .mutuals-strip        { gap: 9.81px; margin-top: -11px; padding: 3px 6px 3px 2px; }
    .mutual-thumb         { width: 37.06px; height: 37.06px; margin-left: -10.9px; border-width: 2px; box-shadow: 0 0 0 1.5px #0a0b1e; }
    .mutuals-label        { font-size: clamp(11.69px, 3.08vw, 13.29px); position: relative; top: 3px; }
    .mutual-row           { gap: 13.08px; }
    .mutual-row-pic       { width: 50.14px; height: 50.14px; }
    .mutual-row-arrow     { font-size: 21.8px; }
}
@media (max-width: 480px) {
    .mutuals-strip        { gap: 8.72px; margin-top: -1px; }
    .mutual-thumb         { width: 32.7px; height: 32.7px; margin-left: -9.81px; border-width: 2px; box-shadow: 0 0 0 1.5px #0a0b1e; }
    .mutuals-label        { font-size: clamp(11.16px, 2.87vw, 12.75px); top: 3px; }
    .mutual-row           { gap: 11.99px; padding-top: 10.9px; padding-bottom: 10.9px; }
    .mutual-row-pic       { width: 45.78px; height: 45.78px; border-width: 2px; }
    .mutual-row-username  { font-size: 0.828rem; }
    .mutual-row-nick      { font-size: 0.654rem; }
    .mutual-row-arrow     { font-size: 19.62px; }
}
@media (max-width: 390px) {
    .mutual-thumb         { width: 29.43px; height: 29.43px; margin-left: -8.72px; }
    .mutuals-label        { font-size: 10.63px; top: 3px; }
    .mutual-row           { gap: 10.9px; padding-top: 9.81px; padding-bottom: 9.81px; }
    .mutual-row-pic       { width: 41.42px; height: 41.42px; }
    .mutual-row-username  { font-size: 0.785rem; }
    .mutual-row-nick      { font-size: 0.621rem; }
    .mutual-row-arrow     { font-size: 18.53px; }
}
@media (max-width: 320px) {
    .mutual-thumb         { width: 26.16px; height: 26.16px; margin-left: -7.63px; }
    .mutuals-label        { font-size: 10.1px; top: 2px; }
    .mutual-row-pic       { width: 37.06px; height: 37.06px; }
    .mutuals-search-input { font-size: 0.719rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched; the pics in the strip keep their look) ═══════════ */
[data-theme="light"] .mutuals-label { color: #5f647a; }
[data-theme="light"] .mutuals-label strong { color: #0b0d24; }
[data-theme="light"] .mutuals-modal-overlay {
    --pm-card: rgba(6,7,26,.05); --pm-card2: rgba(6,7,26,.08);
    --pm-line: rgba(6,7,26,.1);  --pm-line2: rgba(6,7,26,.2);
    --pm-sub: rgba(6,7,26,.66);  --pm-mut: rgba(6,7,26,.52);
    --pm-blue-l: #2a5fd8;
    background: linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    background-attachment: fixed;
    color: #0b0d24;
}
[data-theme="light"] .mutuals-modal-close { color: #0b0d24; }
[data-theme="light"] .mutuals-modal-close:hover,
[data-theme="light"] .mutuals-modal-close:focus-visible,
[data-theme="light"] .mutuals-modal-close:active { color: var(--pm-red); }
[data-theme="light"] .mutuals-modal-title,
[data-theme="light"] .mutuals-count-badge,
[data-theme="light"] .mutual-row-username,
[data-theme="light"] .mutual-row-arrow,
[data-theme="light"] .mutual-row:hover .mutual-row-arrow { color: #0b0d24; }
/* badge keeps the pic-border colour in light mode too (must come after the username colour rule above) */
[data-theme="light"] .mutual-row-username .vbadge { color: var(--mu-ring, #FF2D2D) !important; }
[data-theme="light"] .mutuals-search-input { background: #fff; color: #0b0d24; border-color: rgba(6,7,26,.22); }
[data-theme="light"] .mutuals-search-input::placeholder { color: rgba(6,7,26,.5); }
[data-theme="light"] .mutuals-search-input:focus { background: #fff; }
[data-theme="light"] .mutuals-modal-list { scrollbar-color: rgba(6,7,26,.25) transparent; }
[data-theme="light"] .mutuals-modal-list::-webkit-scrollbar-thumb { background: rgba(6,7,26,.25); }
[data-theme="light"] .mutual-row:hover { background: rgba(6,7,26,.05); }
[data-theme="light"] .skeleton-line,
[data-theme="light"] .mutual-row-pic.skeleton-circle {
    background: linear-gradient(90deg, rgba(6,7,26,0.06) 25%, rgba(6,7,26,0.14) 50%, rgba(6,7,26,0.06) 75%);
    background-size: 200% 100%;
}
[data-theme="light"] .mutual-divider {
    background: linear-gradient(90deg, rgba(6,7,26,0.07) 0%, rgba(6,7,26,0.07) 35%, rgba(6,7,26,0.22) 50%, rgba(6,7,26,0.07) 65%, rgba(6,7,26,0.07) 100%);
    background-size: 250% 100%;
}
[data-theme="light"] .mutuals-no-results i { color: rgba(42,95,216,.6); }
</style>

<!-- ══ STRIP (no skeleton — shows immediately, NO verified badge here) ══ -->
<?php if(!empty($preview_users)): ?>
<div class="mutuals-strip" id="mutualsStripReal" onclick="openMutualsModal()">
    <div class="mutuals-pics">
        <?php foreach($preview_users as $mu): ?>
        <img src="<?php echo mu_h(mu_safe_img($mu['profile_pic'])); ?>"
             onerror="this.onerror=null;this.src='mee.jpg'"
             class="mutual-thumb"
             width="33" height="33"
             alt="<?php echo mu_h($mu['username']); ?>"
             loading="lazy">
        <?php endforeach; ?>
    </div>
    <p class="mutuals-label">
        Tracked by
        <strong><?php echo mu_h($preview_users[0]['username']); ?></strong>
        <?php if($strip_total > 1): ?>
            and <strong>+<?php echo ($strip_total - 1); ?> other<?php echo $strip_total > 2 ? 's' : ''; ?></strong> you track
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<!-- ══ MODAL — centered lane with grey side lines (same as post modal)
     style="display:none" keeps it hidden even if the CSS were ever late ══ -->
<div class="mutuals-modal-overlay" id="mutualsModal" style="display:none" role="dialog" aria-modal="true" aria-label="Mutuals">
    <div class="mutuals-modal-card">

        <!-- TOPBAR: X left · +count + title on the right -->
        <div class="mutuals-topbar">
            <div class="mutuals-tb-side">
                <button type="button" class="mutuals-modal-close" onclick="closeMutualsModalBtn()" aria-label="Close">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
                </button>
            </div>
            <div class="mutuals-topbar-right">
                <div class="mutuals-modal-title">
                    <span class="mutuals-count-badge"><?php echo $mutuals_total; ?></span> Mutuals
                </div>
            </div>
        </div>

        <!-- Search -->
        <div class="mutuals-search-wrap">
            <i class="ri-search-line mutuals-search-icon"></i>
            <input type="text"
                   class="mutuals-search-input"
                   placeholder="Search users…"
                   oninput="filterMutuals(this)"
                   autocomplete="off">
        </div>

        <div class="mutuals-modal-list" id="mutualsList">

            <!-- Skeleton rows (shown first every time modal opens) -->
            <div class="mutuals-skeleton-wrap" id="mutualsSkeletonList">
                <?php for($s = 0; $s < 6; $s++): ?>
                <div class="mutual-row mutual-row-skeleton">
                    <span class="mutual-row-pic skeleton-circle"></span>
                    <div class="mutual-row-info">
                        <span class="skeleton-line mutuals-skel-username"></span>
                        <span class="skeleton-line mutuals-skel-nick"></span>
                    </div>
                </div>
                <?php if($s < 5): ?><div class="mutual-divider"></div><?php endif; ?>
                <?php endfor; ?>
            </div>

            <!-- Real rows (hidden until loaded) -->
            <div class="mutuals-real-wrap" id="mutualsRealRows">
                <?php
                $mutuals_count = count($all_mutuals);
                foreach($all_mutuals as $i => $mu):
                    $pic   = mu_h(mu_safe_img($mu['profile_pic']));
                    $uname = mu_h($mu['username']);
                    $nick  = !empty($mu['nickname']) ? mu_h($mu['nickname']) : '';
                    $uid   = (int)$mu['id'];
                    $data  = mu_lc($mu['username'] . ' ' . (string)($mu['nickname'] ?? ''));
                    $vbdg  = !empty($mu['is_verified']) ? dz_badge('f') : '';
                    $is_s  = !empty($mu['is_susp']);
                ?>
                <div class="mutual-row"
                     data-name="<?php echo mu_h($data); ?>"
                     onclick="location.href='profile.php?user_id=<?php echo $uid; ?>'">
                    <?php if($is_s): ?>
                    <span class="mutual-row-pic mutual-row-pic-susp"></span>
                    <?php else: ?>
                    <img src="<?php echo $pic; ?>"
                         onerror="this.onerror=null;this.src='mee.jpg'"
                         class="mutual-row-pic"
                         width="52" height="52"
                         alt="<?php echo $uname; ?>"
                         loading="lazy">
                    <?php endif; ?>
                    <div class="mutual-row-info">
                        <span class="mutual-row-username"><span class="mu-nm"><?php echo $uname; ?></span><?php echo $vbdg; ?></span>
                        <?php if($is_s): ?>
                        <span class="mutual-row-nick mutual-row-susp">Suspended</span>
                        <?php elseif($nick): ?>
                        <span class="mutual-row-nick"><?php echo $nick; ?></span>
                        <?php endif; ?>
                    </div>
                    <i class="ri-arrow-right-s-line mutual-row-arrow"></i>
                </div>
                <?php if($i < $mutuals_count - 1): ?>
                <div class="mutual-divider"></div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- No results state -->
            <div class="mutuals-no-results" id="mutualsNoResults">
                <i class="ri-search-line"></i>
                <span>No users found</span>
            </div>
        </div>

    </div>
</div>

<script>
/* ── Modal: skeleton → real (strip has no skeleton anymore) ── */
function openMutualsModal(){
    var m = document.getElementById('mutualsModal');
    m.style.display = '';          // remove inline hide, let the .open class take over
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
    showMutualsSkeleton();
}

function showMutualsSkeleton(){
    var skel = document.getElementById('mutualsSkeletonList');
    var real = document.getElementById('mutualsRealRows');
    if(!skel || !real) return;

    skel.style.display = 'block';
    real.style.display = 'none';
    real.classList.remove('mutuals-fade-in');

    var imgs  = real.querySelectorAll('img.mutual-row-pic');
    var total = imgs.length;
    var loaded = 0;
    var done = false;

    function reveal(){
        if(done) return;
        done = true;
        skel.style.display = 'none';
        real.style.display = 'block';
        real.classList.add('mutuals-fade-in');
    }
    function checkDone(){
        loaded++;
        if(loaded >= total) reveal();
    }
    if(total === 0){ reveal(); return; }
    imgs.forEach(function(img){
        if(img.complete) checkDone();
        else {
            img.addEventListener('load', checkDone);
            img.addEventListener('error', checkDone);
        }
    });
    setTimeout(reveal, 1100); // safety fallback
}

function closeMutualsModalBtn(){
    var modal = document.getElementById('mutualsModal');
    if(!modal) return;
    modal.classList.remove('open');
    modal.style.display = 'none';  // hidden again inline
    document.body.style.overflow = '';
    // reset search on close
    var inp = modal.querySelector('.mutuals-search-input');
    if(inp){ inp.value = ''; filterMutuals(inp); }
}

function filterMutuals(input){
    var q    = input.value.trim().toLowerCase();
    var rows = document.querySelectorAll('#mutualsRealRows .mutual-row');
    var vis  = 0;
    rows.forEach(function(r){
        var match = r.dataset.name.includes(q);
        r.classList.toggle('hidden', !match);
        if(match) vis++;
    });
    var noRes = document.getElementById('mutualsNoResults');
    if(noRes) noRes.classList.toggle('show', q.length > 0 && vis === 0 && rows.length > 0);
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeMutualsModalBtn();
});
</script>