<?php
/* ══════════════════════════════════════════════════════
   CROSSROADS — Social Discovery Component
   Only shown on CURRENT USER's own profile page.
   Drop this file in your project folder and include it
   inside profile.php AFTER the profile header block,
   BEFORE the tabs — like this:

       <?php if ($current_user == $profile_id): ?>
           <?php include 'crossroads.php'; ?>
       <?php endif; ?>

   Required vars already set by profile.php:
     $conn, $current_user, $profile_id
   Verified accounts get the filled flower badge next to their
   name inside the Crossroads MODAL (same colour as the pic border).
   Needs verified_badge.php in the same folder.
   ══════════════════════════════════════════════════════ */

if (empty($current_user) || $current_user != $profile_id) return;

$cr_me = (int)$current_user;   // the ONLY value ever placed into SQL text
if ($cr_me <= 0) return;

require_once __DIR__ . '/verified_badge.php';

/* ── Small helpers ── */
if (!function_exists('cr_h')) {
    // escape for HTML text AND attributes (quotes included)
    function cr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('cr_lc')) {
    function cr_lc($v) { return function_exists('mb_strtolower') ? mb_strtolower((string)$v, 'UTF-8') : strtolower((string)$v); }
}
if (!function_exists('cr_safe_img')) {
    // image src may only be a relative path or an http(s) URL — blocks javascript:, data:, etc.
    function cr_safe_img($p) {
        $p = trim((string)$p);
        if ($p === '') return 'uploads/default.jpg';
        if (preg_match('#^(?:https?:)?//#i', $p) || !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $p)) return $p;
        return 'uploads/default.jpg';
    }
}

/* ── CSRF token (one random token per session) ── */
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$cr_token = '';
if (session_status() === PHP_SESSION_ACTIVE) {
    if (empty($_SESSION['cr_csrf']) || !is_string($_SESSION['cr_csrf'])) {
        $_SESSION['cr_csrf'] = bin2hex(random_bytes(32));
    }
    $cr_token = $_SESSION['cr_csrf'];
}

/* ── Show/hide preference (saved on the user's account) ──
   Needs this column once:
   ALTER TABLE users ADD COLUMN hide_crossroads TINYINT(1) NOT NULL DEFAULT 0;
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cr_hide'])) {

    /* every one of these checks must pass, otherwise nothing is saved */
    $cr_sent  = $_POST['cr_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $cr_pass  = true;

    // 1) CSRF token — constant-time compare, fails closed if there is no session
    if ($cr_token === '' || !is_string($cr_sent) || !hash_equals($cr_token, $cr_sent)) $cr_pass = false;

    // 2) must be our own background (XHR/fetch) request — a custom header blocks cross-site forms
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') $cr_pass = false;

    // 3) browser must say it is same-origin (when it tells us)
    $cr_fs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($cr_fs !== '' && $cr_fs !== 'same-origin') $cr_pass = false;

    // 4) Origin header (when sent) must be this exact host
    $cr_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($cr_origin !== '') {
        $cr_oh = strtolower((string)parse_url($cr_origin, PHP_URL_HOST));
        $cr_op = parse_url($cr_origin, PHP_URL_PORT);
        if ($cr_op) $cr_oh .= ':' . $cr_op;
        if ($cr_oh === '' || $cr_oh !== strtolower($_SERVER['HTTP_HOST'] ?? '')) $cr_pass = false;
    }

    // 5) value must be exactly "0" or "1"
    if (!isset($_POST['cr_hide']) || !is_string($_POST['cr_hide']) || !in_array($_POST['cr_hide'], ['0', '1'], true)) $cr_pass = false;

    $cr_saved = false;
    if ($cr_pass) {
        try {
            $cr_set = (int)$_POST['cr_hide'];
            if ($cr_st = $conn->prepare("UPDATE users SET hide_crossroads = ? WHERE id = ?")) {
                $cr_st->bind_param('ii', $cr_set, $cr_me);
                $cr_saved = $cr_st->execute();
                $cr_st->close();
            }
        } catch (Throwable $e) { $cr_saved = false; }
    }

    // the page is already partly sent, so the JS reads this marker instead of the status code
    if (!headers_sent()) {
        header('Cache-Control: no-store');
        http_response_code($cr_saved ? 200 : 403);
    }
    echo $cr_saved ? "\n<!--CR_SAVED-->" : "\n<!--CR_DENIED-->";
    exit;
}

$cr_hidden = 0;
try {
    if ($cr_st = $conn->prepare("SELECT hide_crossroads FROM users WHERE id = ? LIMIT 1")) {
        $cr_st->bind_param('i', $cr_me);
        $cr_st->execute();
        $cr_st->bind_result($cr_hv);
        if ($cr_st->fetch()) $cr_hidden = (int)$cr_hv;
        $cr_st->close();
    }
} catch (Throwable $e) { $cr_hidden = 0; }

/* ── Deactivated accounts never show up as a suggestion (safe before the SQL is run: the filter is only added when users.deactivated_at exists) ── */
$cr_has_deact = false;
if (function_exists('dz_has_col')) {
    $cr_has_deact = (bool)dz_has_col($conn, 'users', 'deactivated_at');
} else {
    $cr_chk = $conn->query("SHOW COLUMNS FROM `users` LIKE 'deactivated_at'");
    $cr_has_deact = ($cr_chk && $cr_chk->num_rows > 0);
}
$cr_not_deact = $cr_has_deact ? " AND u.deactivated_at IS NULL" : "";

$cr_query = $conn->query("
    SELECT
        u.id, u.username, u.nickname, u.profile_pic, u.is_verified,

        (CASE WHEN EXISTS (
            SELECT 1 FROM followers f1
            JOIN followers f2
              ON f1.following_id = f2.following_id
             AND f1.follower_id  = $cr_me
             AND f2.follower_id  = u.id
        ) THEN 1 ELSE 0 END) AS sig_follow,

        (CASE WHEN EXISTS (
            SELECT 1 FROM group_members gm1
            JOIN group_members gm2
              ON gm1.group_id = gm2.group_id
             AND gm1.user_id  = $cr_me
             AND gm2.user_id  = u.id
        ) THEN 1 ELSE 0 END) AS sig_group,

        (CASE WHEN EXISTS (
            SELECT 1 FROM likes l1
            JOIN likes l2
              ON l1.meme_id = l2.meme_id
             AND l1.user_id = $cr_me
             AND l2.user_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_like,

        (CASE WHEN EXISTS (
            SELECT 1 FROM reposts r1
            JOIN reposts r2
              ON r1.meme_id = r2.meme_id
             AND r1.user_id = $cr_me
             AND r2.user_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_repost,

        (CASE WHEN EXISTS (
            SELECT 1 FROM favorites fv1
            JOIN favorites fv2
              ON fv1.meme_id = fv2.meme_id
             AND fv1.user_id = $cr_me
             AND fv2.user_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_fav,

        (CASE WHEN EXISTS (
            SELECT 1 FROM pod_discussion_replies pr1
            JOIN pod_discussion_replies pr2
              ON pr1.discussion_id = pr2.discussion_id
             AND pr1.user_id       = $cr_me
             AND pr2.user_id       = u.id
        ) THEN 1 ELSE 0 END) AS sig_pod,

        (CASE WHEN EXISTS (
            SELECT 1 FROM collection_items ci1
            JOIN collections c1 ON ci1.collection_id = c1.id AND c1.user_id = $cr_me
            JOIN collection_items ci2 ON ci1.meme_id = ci2.meme_id
            JOIN collections c2 ON ci2.collection_id = c2.id AND c2.user_id = u.id
        ) THEN 1 ELSE 0 END) AS sig_collection,

        (CASE WHEN EXISTS (
            SELECT 1 FROM profile_views pv1
            JOIN profile_views pv2
              ON pv1.profile_id = pv2.profile_id
             AND pv1.viewer_id  = $cr_me
             AND pv2.viewer_id  = u.id
        ) THEN 1 ELSE 0 END) AS sig_view

    FROM users u
    WHERE u.id != $cr_me
      $cr_not_deact
      AND u.id NOT IN (
          SELECT following_id FROM followers WHERE follower_id = $cr_me
      )
    HAVING (sig_follow + sig_group + sig_like + sig_repost + sig_fav + sig_pod + sig_collection + sig_view) >= 2
    ORDER BY (sig_follow + sig_group + sig_like + sig_repost + sig_fav + sig_pod + sig_collection + sig_view) DESC
");

if (!$cr_query || $cr_query->num_rows === 0) return;

$cr_total   = $cr_query->num_rows;
$cr_preview = [];
$cr_all     = [];

while ($cr_row = $cr_query->fetch_assoc()) {
    $cr_all[] = $cr_row;
    if (count($cr_preview) < 3) $cr_preview[] = $cr_row;
}
?>
<?php echo dz_badge_sprite(); ?>

<!-- ══ STYLES FIRST — must load before the markup so nothing paints unstyled ══ -->
<style>
/* ══ SKELETON SHIMMER (shared) ══ */
@keyframes skeletonShimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
.skeleton-line,
.skeleton-badge {
    background: linear-gradient(
        90deg,
        rgba(255,255,255,0.06) 25%,
        rgba(255,255,255,0.16) 50%,
        rgba(255,255,255,0.06) 75%
    );
    background-size: 200% 100%;
    animation: skeletonShimmer 1.4s ease-in-out infinite;
}
.skeleton-line  { border-radius: 4px;  display: inline-block; }
.skeleton-badge { border-radius: 20px; display: inline-block; }

.cr-row-pic.skeleton-circle {
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

.cr-fade-in { animation: crContentFadeIn 0.3s ease forwards; }
@keyframes crContentFadeIn { from { opacity: 0; } to { opacity: 1; } }

/* ══ STRIP (unchanged) ══ */
.crossroads-strip {
    display: inline-flex; align-items: center; gap: 10px;
    margin-top: 4px; padding: 4px 8px 4px 2px; border-radius: 40px;
    max-width: 100%;
    cursor: pointer; user-select: none;
    transition: background 0.2s ease;
    -webkit-tap-highlight-color: transparent;
}
.crossroads-strip:hover  { background: transparent; }
.crossroads-strip:active { transform: scale(0.97); }

.crossroads-pics { display: flex; align-items: center; flex-shrink: 0; }
.crossroads-thumb {
    width: 33px; height: 33px; border-radius: 5px; object-fit: cover;
    border: 2.5px solid #FF2D2D; margin-left: -11px;
    transition: transform 0.2s ease; background: #111;
    box-shadow: 0 0 0 2px #0a0b1e; position: relative; flex-shrink: 0;
}
.crossroads-thumb:first-child { margin-left: 0; }
.crossroads-strip:hover .crossroads-thumb { transform: scale(1.08); }

.crossroads-label {
    font-size: clamp(10.72px, 2.73vw, 12.67px);
    color: rgba(255,255,255,0.5); margin: 0;
    font-family: 'Poppins', sans-serif; line-height: 1.4;
    min-width: 0; white-space: normal; word-break: break-word;
    display: flex; align-items: center; gap: 5px; flex-wrap: wrap;
}
.crossroads-label strong { color: rgba(255,255,255,0.92); font-weight: 600; }

/* ═══════════════════════════════════════════════════════════
   CROSSROADS MODAL — same design language as the post modal
   • full-screen overlay, content in a CENTERED LANE
   • two grey lines separate the lane from the empty space
   • rectangular 5px controls, premium fluid sizing
   • dark mid-tone BLUE accent
═══════════════════════════════════════════════════════════ */
.crossroads-modal-overlay {
    --pm-blue:#2a5fd8; --pm-blue-h:#234fb5; --pm-blue-l:#7ea6ff;
    --pm-blue-dim:rgba(42,95,216,.18); --pm-blue-glow:rgba(42,95,216,.34);
    --pm-card:rgba(255,255,255,.05); --pm-card2:rgba(255,255,255,.08);
    --pm-line:rgba(255,255,255,.09); --pm-line2:rgba(255,255,255,.14);
    --pm-sub:rgba(255,255,255,.62); --pm-mut:rgba(255,255,255,.45);
    --cr-ring:#FF2D2D;   /* profile-pic border colour AND verified badge colour */
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
@media (max-width:490px){ .crossroads-modal-overlay{ --pm-fs:0.807rem; --pm-tb-h:52.32px; } }
@media (max-width:390px){ .crossroads-modal-overlay{ --pm-fs:0.763rem;  --pm-tb-h:47.96px; } }

.crossroads-modal-overlay.open {
    display: flex;
    animation: crSlideIn 0.28s cubic-bezier(0.22,1,0.36,1);
}
@keyframes crSlideIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

.crossroads-modal-overlay *,
.crossroads-modal-overlay *::before,
.crossroads-modal-overlay *::after { box-sizing: border-box; }
.crossroads-modal-overlay button,
.crossroads-modal-overlay input { font-family: 'Poppins', sans-serif; }
.crossroads-modal-overlay button { cursor: pointer; -webkit-tap-highlight-color: transparent; }
.crossroads-modal-overlay button,
.crossroads-modal-overlay input { outline: none; }
/* icons keep the icon font */
.crossroads-modal-overlay [class^="ri-"],
.crossroads-modal-overlay [class*=" ri-"],
.crossroads-modal-overlay [class^="ri-"]::before,
.crossroads-modal-overlay [class*=" ri-"]::before {
    font-family: 'remixicon' !important; font-style: normal; font-weight: normal; line-height: 1;
}

/* ── the lane + two grey lines ── */
.crossroads-modal-card {
    width: 100%; max-width: 580px; height: 100%;
    display: flex; flex-direction: column; min-height: 0;
    background: transparent;
    overflow: hidden;
}
@media (min-width: 801px) {
    .crossroads-modal-card { border-left: 1px solid var(--pm-line); border-right: 1px solid var(--pm-line); }
}
@media (max-width: 800px) { .crossroads-modal-card { max-width: 100%; } }

/* ── Top bar (no grey line) ── */
.cr-topbar {
    flex-shrink: 0;
    display: flex; align-items: center;
    min-height: var(--pm-tb-h);
    padding: env(safe-area-inset-top) 10px 0;
    background: transparent;
    border: none;
}
.cr-tb-side { flex: 0 0 38px; display: flex; align-items: center; }
.crossroads-modal-close {
    width: 34px; height: 34px; background: none; border: none; color: #fff;
    display: flex; align-items: center; justify-content: center; opacity: .9;
    padding: 0; transition: 0.2s;
}
.crossroads-modal-close:hover { color: var(--pm-blue-l); opacity: 1; }
.crossroads-modal-close svg { width: 22px; height: 22px; display: block; }

/* count + title sit on the right */
.cr-topbar-right { flex: 1; min-width: 0; display: flex; justify-content: flex-end; text-align: right; }
.crossroads-modal-title {
    font-size: .92rem; font-weight: 700; color: #fff; line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: flex; align-items: center; justify-content: flex-end; gap: 6px;
}
@media (max-width:490px){ .crossroads-modal-title{ font-size:0.916rem; } .crossroads-modal-close{ width:32.7px; height:32.7px; } }
@media (max-width:390px){ .crossroads-modal-title{ font-size:0.872rem; } }

/* ── Count — white text only ── */
.cr-count-badge {
    font-size: inherit;
    font-weight: 700;
    color: #fff;
    background: none;
    border: none;
    padding: 0;
    line-height: 1;
}

/* ── Description (no grey line) ── */
.crossroads-modal-desc {
    font-size: .66rem; color: var(--pm-mut);
    margin: 0; padding: 2px var(--pm-pad) 10px;
    font-family: 'Poppins', sans-serif; line-height: 1.5;
    border: none; flex-shrink: 0;
}
@media (max-width:490px){ .crossroads-modal-desc{ font-size:0.676rem; } }

/* ── Search bar — rectangular like post modal inputs ── */
.cr-search-wrap {
    padding: 0 var(--pm-pad) 10px;
    flex-shrink: 0; position: relative;
    border: none;
}
.cr-search-icon {
    position: absolute; left: calc(var(--pm-pad) + 11px); top: calc(50% - 5px);
    transform: translateY(-50%);
    margin-top: -1px;
    color: var(--pm-mut); font-size: 14px; pointer-events: none;
}
.cr-search-input {
    width: 100%; height: calc(var(--pm-act-h) + 6px);
    padding: 0 11px 0 34px; border-radius: var(--pm-r);
    border: 1px solid var(--pm-line2); background: var(--pm-card); color: #fff;
    font-size: .76rem; transition: .2s; -webkit-appearance: none; appearance: none;
}
.cr-search-input::placeholder { color: var(--pm-mut); }
.cr-search-input:focus {
    border-color: rgba(42,95,216,.85);
    box-shadow: 0 0 0 3px rgba(42,95,216,.18);
    background: var(--pm-card);
}
@media (max-width:490px){ .cr-search-input{ font-size:0.763rem; } }

/* ── Scrollable list (grey scrollbar) ── */
.crossroads-modal-list {
    overflow-y: auto; overflow-x: hidden; flex: 1; min-height: 0;
    -webkit-overflow-scrolling: touch;
    padding: 2px 0 calc(env(safe-area-inset-bottom, 0px) + 24px);
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.25) transparent;
}
.crossroads-modal-list::-webkit-scrollbar { width: 4px; }
.crossroads-modal-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,.25); border-radius: 99px; }

.cr-skeleton-wrap { display: block; }
.cr-real-wrap      { display: none; }

/* ── Each row ── */
.cr-row {
    display: flex; align-items: center; gap: 14px;
    padding: 12px var(--pm-pad); cursor: pointer;
    transition: background 0.15s ease;
    -webkit-tap-highlight-color: transparent;
}
.cr-row:hover  { background: rgba(255,255,255,0.05); }
.cr-row:active { background: var(--pm-blue-dim); }
.cr-row.hidden { display: none; }
.cr-row-skeleton          { cursor: default; }
.cr-row-skeleton:hover    { background: transparent; }

/* ── Profile pics in modal (border colour = --cr-ring, same as the verified badge) ── */
.cr-row-pic {
    width: 56px; height: 56px; border-radius: 50%;
    object-fit: cover; border: 2.5px solid var(--cr-ring, #FF2D2D);
    flex-shrink: 0; background: #1a1a1a;
    box-shadow: 0 0 12px rgba(255,45,45,0.22);
    transition: transform 0.2s ease, box-shadow 0.2s ease; display: block;
}
.cr-row:hover .cr-row-pic { transform: scale(1.06); box-shadow: 0 0 18px rgba(255,45,45,0.4); }

.cr-row-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 5px; }
/* name + badge on one line; a long name is cut with "…" but the badge never is */
.cr-row-username {
    font-size: .82rem; font-weight: 700; color: #fff;
    font-family: 'Poppins', sans-serif;
    display: flex; align-items: center; gap: .3em; min-width: 0;
}
.cr-row-username .cr-nm {
    min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
/* verified badge (modal rows only): filled, same size as the text (1em follows every
   responsive font-size below), colour = same variable as the pic border, dark AND light mode */
.cr-row-username .vbadge {
    width: 1em; height: 1em;
    vertical-align: baseline;
    flex-shrink: 0;
    color: var(--cr-ring, #FF2D2D) !important;
    fill: currentColor;
}

.cr-badges { display: flex; flex-wrap: wrap; gap: 4px; }
.cr-badge {
    font-size: clamp(8.5px, 2vw, 9.5px); font-family: 'Poppins', sans-serif;
    font-weight: 500; color: rgba(255,255,255,0.5);
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
    border-radius: 20px; padding: 1.5px 7px; white-space: nowrap;
}
.cr-badge-more { color: #FF2D2D; background: rgba(255,45,45,0.08); border-color: rgba(255,45,45,0.2); }

/* ── Skeleton row text/badge sizes ── */
.cr-skel-username { width: 130px; height: 14px; }
.cr-skel-badge     { width: 64px; height: 16px; }
.cr-skel-badge-sm  { width: 46px; }
.cr-skel-score     { width: 20px; height: 16px; }

.cr-score-wrap  { display: flex; flex-direction: column; align-items: center; gap: 1px; flex-shrink: 0; }
.cr-score       { font-size: clamp(15px, 3.5vw, 17px); font-weight: 700; color: #FF2D2D; font-family: 'Poppins', sans-serif; line-height: 1; }
.cr-score-label { font-size: 8.5px; color: rgba(255,255,255,0.25); font-family: 'Poppins', sans-serif; text-transform: uppercase; letter-spacing: 0.5px; }

/* ── Chevron: white + bigger ── */
.cr-row-arrow {
    color: #fff; font-size: 22px; flex-shrink: 0;
    transition: transform 0.2s ease;
}
.cr-row:hover .cr-row-arrow { transform: translateX(3px); color: #fff; }

/* ── Divider between rows (grey, shimmering) ── */
.cr-divider {
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
    animation: crDividerShimmer 2.6s linear infinite;
}
@keyframes crDividerShimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ── No results ── */
.cr-no-results {
    display: none; flex-direction: column; align-items: center; gap: 8px;
    padding: 40px 20px; text-align: center;
    color: var(--pm-mut); font-size: .72rem;
    font-family: 'Poppins', sans-serif;
}
.cr-no-results i { font-size: 1.8rem; color: rgba(126,166,255,.45); }
.cr-no-results.show { display: flex; }

/* ── Show/hide toggle ── */
.cr-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.cr-toggle-label {
    font-family: 'Poppins', sans-serif; font-size: .7rem;
    color: rgba(255,255,255,0.55); line-height: 1.3;
}
.cr-toggle-modal { padding: 0 var(--pm-pad) 10px; flex-shrink: 0; }
.cr-toggle-page  { display: none; margin-top: -3px; }
.cr-off .cr-toggle-page { display: block; }
.cr-show-btn {
    background: none; border: none; padding: 4px 2px; cursor: pointer; outline: none;
    font-family: 'Poppins', sans-serif; font-size: .6685rem; /* .7rem − 4.5% */
    color: rgba(255,255,255,0.55); transition: color 0.2s ease;
    -webkit-tap-highlight-color: transparent;
}
.cr-show-btn:hover, .cr-show-btn:focus-visible { color: rgba(255,255,255,0.9); }
.cr-off .crossroads-strip { display: none; }

.cr-switch {
    position: relative; flex-shrink: 0;
    width: 34px; height: 20px; padding: 0; border: none; border-radius: 999px;
    background: rgba(255,255,255,0.18); cursor: pointer; outline: none;
    transition: background 0.2s ease; -webkit-tap-highlight-color: transparent;
}
.cr-switch[aria-checked="true"] { background: #FF2D2D; }
.cr-switch:focus-visible { box-shadow: 0 0 0 3px rgba(255,45,45,.35); }
.cr-switch-knob {
    position: absolute; top: 2px; left: 2px;
    width: 16px; height: 16px; border-radius: 50%; background: #0a0b1e;
    transition: transform 0.2s ease;
}
.cr-switch[aria-checked="true"] .cr-switch-knob { transform: translateX(14px); }

@media (prefers-reduced-motion:reduce){
    .crossroads-modal-overlay *{ transition-duration:.01ms !important; animation-duration:.01ms !important; }
}

/* ══ RESPONSIVE (the badge is 1em, so it scales with each username font-size; the
      explicit badge + gap rules below keep it locked to the text at every breakpoint) ══ */
@media (max-width: 768px) {
    .crossroads-strip        { gap: 9.81px; margin-top: 3px; padding: 3px 6px 3px 2px; }
    .crossroads-thumb        { width: 37.06px; height: 37.06px; margin-left: -10.9px; border-width: 2px; box-shadow: 0 0 0 1.5px #0a0b1e; }
    .crossroads-label        { font-size: clamp(11.69px, 3.08vw, 13.29px); position: relative; top: 3px; }
    .cr-row                  { gap: 13.08px; }
    .cr-row-pic              { width: 52.32px; height: 52.32px; }
    .cr-row-arrow            { font-size: 21.8px; }
    .cr-score                { font-size: 16.35px; }
    .cr-row-username         { gap: .3em; }
    .cr-row-username .vbadge { width: 1em; height: 1em; }
}
@media (max-width: 480px) {
    .crossroads-strip        { gap: 8.72px; margin-top: 2px; }
    .crossroads-thumb        { width: 32.7px; height: 32.7px; margin-left: -9.81px; border-width: 2px; box-shadow: 0 0 0 1.5px #0a0b1e; }
    .crossroads-label        { font-size: clamp(11.16px, 2.87vw, 12.75px); top: 3px; }
    .cr-row                  { gap: 11.99px; padding-top: 10.9px; padding-bottom: 10.9px; }
    .cr-row-pic              { width: 47.96px; height: 47.96px; border-width: 2px; }
    .cr-row-username         { font-size: 0.828rem; gap: .28em; }
    .cr-row-username .vbadge { width: 1em; height: 1em; }
    .cr-row-arrow            { font-size: 19.62px; }
    .cr-score                { font-size: 15.26px; }
    .cr-badge                { font-size: 8.72px; padding: 1px 5px; }
}
@media (max-width: 390px) {
    .crossroads-thumb        { width: 29.43px; height: 29.43px; margin-left: -8.72px; }
    .crossroads-label        { font-size: 10.63px; top: 3px; }
    .cr-row                  { gap: 10.9px; padding-top: 9.81px; padding-bottom: 9.81px; }
    .cr-row-pic              { width: 41.42px; height: 41.42px; }
    .cr-row-username         { font-size: 0.785rem; gap: .26em; }
    .cr-row-username .vbadge { width: 1em; height: 1em; }
    .cr-row-arrow            { font-size: 18.53px; }
}
@media (max-width: 320px) {
    .crossroads-thumb  { width: 26.16px; height: 26.16px; margin-left: -7.63px; }
    .crossroads-label  { font-size: 10.1px; top: 2px; }
    .cr-row-pic        { width: 37.06px; height: 37.06px; }
    .cr-row-username .vbadge { width: 1em; height: 1em; }
    .cr-search-input   { font-size: 0.719rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched; the pics in the strip keep their look) ═══════════ */
[data-theme="light"] .crossroads-label { color: #5f647a; }
[data-theme="light"] .crossroads-label strong { color: #0b0d24; }
[data-theme="light"] .crossroads-modal-overlay {
    --pm-card: rgba(6,7,26,.05); --pm-card2: rgba(6,7,26,.08);
    --pm-line: rgba(6,7,26,.1);  --pm-line2: rgba(6,7,26,.2);
    --pm-sub: rgba(6,7,26,.66);  --pm-mut: rgba(6,7,26,.52);
    --pm-blue-l: #2a5fd8;
    background: linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    background-attachment: fixed;
    color: #0b0d24;
}
[data-theme="light"] .crossroads-modal-close { color: #0b0d24; }
[data-theme="light"] .crossroads-modal-close:hover { color: var(--pm-blue-l); }
[data-theme="light"] .crossroads-modal-title,
[data-theme="light"] .cr-count-badge,
[data-theme="light"] .cr-row-username,
[data-theme="light"] .cr-row-arrow,
[data-theme="light"] .cr-row:hover .cr-row-arrow { color: #0b0d24; }
/* badge keeps the pic-border colour in light mode too (after the username colour rule above) */
[data-theme="light"] .cr-row-username .vbadge { color: var(--cr-ring, #FF2D2D) !important; }
[data-theme="light"] .cr-search-input { background: #fff; color: #0b0d24; border-color: rgba(6,7,26,.22); }
[data-theme="light"] .cr-search-input::placeholder { color: rgba(6,7,26,.5); }
[data-theme="light"] .cr-search-input:focus { background: #fff; }
[data-theme="light"] .crossroads-modal-list { scrollbar-color: rgba(6,7,26,.25) transparent; }
[data-theme="light"] .crossroads-modal-list::-webkit-scrollbar-thumb { background: rgba(6,7,26,.25); }
[data-theme="light"] .cr-row:hover { background: rgba(6,7,26,.05); }
[data-theme="light"] .cr-badge { color: #42465a; background: rgba(6,7,26,.06); border-color: rgba(6,7,26,.14); }
[data-theme="light"] .cr-badge-more { color: #d42525; background: rgba(255,45,45,.1); border-color: rgba(255,45,45,.28); }
[data-theme="light"] .cr-score-label { color: #5f647a; }
[data-theme="light"] .cr-toggle-label,
[data-theme="light"] .cr-show-btn { color: #5f647a; }
[data-theme="light"] .cr-show-btn:hover,
[data-theme="light"] .cr-show-btn:focus-visible { color: #0b0d24; }
[data-theme="light"] .cr-switch { background: rgba(6,7,26,.22); }
[data-theme="light"] .cr-switch[aria-checked="true"] { background: #FF2D2D; }
[data-theme="light"] .cr-switch-knob { background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.3); }
[data-theme="light"] .skeleton-line,
[data-theme="light"] .skeleton-badge,
[data-theme="light"] .cr-row-pic.skeleton-circle {
    background: linear-gradient(90deg, rgba(6,7,26,0.06) 25%, rgba(6,7,26,0.14) 50%, rgba(6,7,26,0.06) 75%);
    background-size: 200% 100%;
}
[data-theme="light"] .cr-divider {
    background: linear-gradient(90deg, rgba(6,7,26,0.07) 0%, rgba(6,7,26,0.07) 35%, rgba(6,7,26,0.22) 50%, rgba(6,7,26,0.07) 65%, rgba(6,7,26,0.07) 100%);
    background-size: 250% 100%;
}
[data-theme="light"] .cr-no-results i { color: rgba(42,95,216,.6); }
</style>

<!-- ══ CROSSROADS STRIP ══ -->

<div id="crossroadsWrap" class="<?php echo $cr_hidden ? 'cr-off' : ''; ?>" data-csrf="<?php echo cr_h($cr_token); ?>">

<!-- Text button shown on the page ONLY while Crossroads is hidden -->
<div class="cr-toggle-page">
    <button type="button" class="cr-show-btn" onclick="setCrossroads(true)">Show Crossroad Suggestion</button>
</div>

<!-- Strip (no skeleton — shows immediately, NO verified badge here) -->
<div class="crossroads-strip" id="crossroadsStripReal" onclick="openCrossroadsModal()">
    <div class="crossroads-pics">
        <?php foreach ($cr_preview as $cu): ?>
        <img src="<?php echo cr_h(cr_safe_img($cu['profile_pic'])); ?>"
             onerror="this.onerror=null;this.src='uploads/default.jpg'"
             class="crossroads-thumb"
             width="33" height="33"
             alt="<?php echo cr_h($cu['username']); ?>"
             loading="lazy">
        <?php endforeach; ?>
    </div>
    <p class="crossroads-label">
        You,
        <strong><?php echo cr_h($cr_preview[0]['username']); ?></strong>
        <?php if ($cr_total > 1): ?>
            and <strong><?php echo ($cr_total - 1); ?> other<?php echo $cr_total > 2 ? 's' : ''; ?></strong>
        <?php endif; ?>
        Keep Crossing paths
    </p>
</div>

<!-- ══ CROSSROADS MODAL — centered lane with grey side lines (same as post modal)
     style="display:none" keeps it hidden even if the CSS were ever late ══ -->
<div class="crossroads-modal-overlay" id="crossroadsModal" style="display:none" role="dialog" aria-modal="true" aria-label="Crossroads">
    <div class="crossroads-modal-card">

        <!-- TOPBAR: X left · count + title on the right -->
        <div class="cr-topbar">
            <div class="cr-tb-side">
                <button type="button" class="crossroads-modal-close" onclick="closeCrossroadsModalBtn()" aria-label="Close">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
                </button>
            </div>
            <div class="cr-topbar-right">
                <div class="crossroads-modal-title">
                    <span class="cr-count-badge"><?php echo $cr_total; ?></span> Crossroads
                </div>
            </div>
        </div>

        <p class="crossroads-modal-desc">
            People you keep crossing paths with — same spaces, same vibes, never connected.
        </p>

        <div class="cr-toggle-row cr-toggle-modal">
            <span class="cr-toggle-label">Show Crossroad Suggestion</span>
            <button type="button" class="cr-switch" role="switch" aria-checked="true"
                    aria-label="Show Crossroad Suggestion" onclick="setCrossroads(false)">
                <span class="cr-switch-knob"></span>
            </button>
        </div>

        <!-- Search -->
        <div class="cr-search-wrap">
            <i class="ri-search-line cr-search-icon"></i>
            <input type="text"
                   class="cr-search-input"
                   placeholder="Search users…"
                   oninput="filterCrossroads(this)"
                   autocomplete="off">
        </div>

        <div class="crossroads-modal-list" id="crossroadsList">

            <!-- Skeleton rows (shown first every time modal opens) -->
            <div class="cr-skeleton-wrap" id="crossroadsSkeletonList">
                <?php for ($s = 0; $s < 6; $s++): ?>
                <div class="cr-row cr-row-skeleton">
                    <span class="cr-row-pic skeleton-circle"></span>
                    <div class="cr-row-info">
                        <span class="skeleton-line cr-skel-username"></span>
                        <div class="cr-badges">
                            <span class="skeleton-badge cr-skel-badge"></span>
                            <span class="skeleton-badge cr-skel-badge cr-skel-badge-sm"></span>
                            <span class="skeleton-badge cr-skel-badge cr-skel-badge-sm"></span>
                        </div>
                    </div>
                    <div class="cr-score-wrap">
                        <span class="skeleton-line cr-skel-score"></span>
                    </div>
                </div>
                <?php if ($s < 5): ?><div class="cr-divider"></div><?php endif; ?>
                <?php endfor; ?>
            </div>

            <!-- Real rows (hidden until loaded) -->
            <div class="cr-real-wrap" id="crossroadsRealRows">
                <?php
                $cr_count = count($cr_all);
                foreach ($cr_all as $cr_i => $cu):
                    $cr_pic   = cr_h(cr_safe_img($cu['profile_pic']));
                    $cr_uname = cr_h($cu['username']);
                    $cr_uid   = (int)$cu['id'];
                    $cr_data  = cr_lc($cu['username']);
                    $cr_vbdg  = !empty($cu['is_verified']) ? dz_badge('f') : '';

                    $cr_overlaps = [];
                    if ($cu['sig_follow'])     $cr_overlaps[] = 'Same creators';
                    if ($cu['sig_group'])      $cr_overlaps[] = 'Same communities';
                    if ($cu['sig_like'])       $cr_overlaps[] = 'Same content';
                    if ($cu['sig_repost'])     $cr_overlaps[] = 'Same reposts';
                    if ($cu['sig_fav'])        $cr_overlaps[] = 'Same saves';
                    if ($cu['sig_pod'])        $cr_overlaps[] = 'Same discussions';
                    if ($cu['sig_collection']) $cr_overlaps[] = 'Similar collections';
                    if ($cu['sig_view'])       $cr_overlaps[] = 'Same profiles viewed';

                    $cr_score = $cu['sig_follow'] + $cu['sig_group'] + $cu['sig_like']
                              + $cu['sig_repost'] + $cu['sig_fav']   + $cu['sig_pod']
                              + $cu['sig_collection'] + $cu['sig_view'];
                ?>
                <div class="cr-row"
                     data-name="<?php echo cr_h($cr_data); ?>"
                     onclick="location.href='profile.php?user_id=<?php echo $cr_uid; ?>'">
                    <img src="<?php echo $cr_pic; ?>"
                         onerror="this.onerror=null;this.src='uploads/default.jpg'"
                         class="cr-row-pic"
                         width="56" height="56"
                         alt="<?php echo $cr_uname; ?>"
                         decoding="async"
                         <?php echo $cr_i < 8 ? '' : 'loading="lazy"'; ?>>
                    <div class="cr-row-info">
                        <span class="cr-row-username"><span class="cr-nm"><?php echo $cr_uname; ?></span><?php echo $cr_vbdg; ?></span>
                        <div class="cr-badges">
                            <?php foreach (array_slice($cr_overlaps, 0, 3) as $cr_badge): ?>
                            <span class="cr-badge"><?php echo $cr_badge; ?></span>
                            <?php endforeach; ?>
                            <?php if (count($cr_overlaps) > 3): ?>
                            <span class="cr-badge cr-badge-more">+<?php echo count($cr_overlaps) - 3; ?> more</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cr-score-wrap">
                        <span class="cr-score"><?php echo $cr_score; ?></span>
                        <span class="cr-score-label">paths</span>
                    </div>
                    <i class="ri-arrow-right-s-line cr-row-arrow"></i>
                </div>
                <?php if ($cr_i < $cr_count - 1): ?>
                <div class="cr-divider"></div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- No results -->
            <div class="cr-no-results" id="crNoResults">
                <i class="ri-search-line"></i>
                <span>No users found</span>
            </div>
        </div>

    </div>
</div>

</div><!-- /#crossroadsWrap -->

<script>
/* ── Modal: skeleton → real ── */
function openCrossroadsModal(){
    var modal = document.getElementById('crossroadsModal');
    modal.style.display = '';      // remove inline hide, let the .open class take over
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    showCrossroadsSkeleton();
}

function showCrossroadsSkeleton(){
    var skel = document.getElementById('crossroadsSkeletonList');
    var real = document.getElementById('crossroadsRealRows');
    if(!skel || !real) return;

    skel.style.display = 'block';
    real.style.display = 'none';
    real.classList.remove('cr-fade-in');

    // only wait for the first screenful of pics (the rest load as you scroll)
    var imgs  = Array.prototype.slice.call(real.querySelectorAll('.cr-row-pic'), 0, 8);
    var total = imgs.length;
    var loaded = 0;
    var done = false;

    function reveal(){
        if(done) return;
        done = true;
        skel.style.display = 'none';
        real.style.display = 'block';
        real.classList.add('cr-fade-in');
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
    setTimeout(reveal, 700); // safety fallback
}

/* ── Show / hide Crossroads (no page refresh) ── */
function applyCrossroadsState(on){
    var wrap  = document.getElementById('crossroadsWrap');
    var strip = document.getElementById('crossroadsStripReal');
    if(!wrap) return;
    if(!on) closeCrossroadsModalBtn();
    wrap.classList.toggle('cr-off', !on);
    if(on && strip){
        strip.classList.remove('cr-fade-in');
        void strip.offsetWidth;           // restart the fade
        strip.classList.add('cr-fade-in');
    }
}

var crBusy = false;
function setCrossroads(on){
    if(crBusy) return;                    // ignore double-clicks while saving
    crBusy = true;
    applyCrossroadsState(on);             // instant UI change

    var wrap  = document.getElementById('crossroadsWrap');
    var token = wrap ? (wrap.getAttribute('data-csrf') || '') : '';
    var fd = new FormData();
    fd.append('cr_hide', on ? '0' : '1');
    fd.append('cr_csrf', token);

    fetch(window.location.href, {
        method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token }
    })
    .then(function(r){ return r.text(); })
    .then(function(t){
        if(t.indexOf('<!--CR_SAVED-->') === -1) throw new Error('not saved');
        crBusy = false;
    })
    .catch(function(){
        crBusy = false;
        applyCrossroadsState(!on);        // couldn't save → put it back
    });
}

function closeCrossroadsModalBtn(){
    var modal = document.getElementById('crossroadsModal');
    if(!modal) return;
    modal.classList.remove('open');
    modal.style.display = 'none';  // hidden again inline
    document.body.style.overflow = '';
    // reset search on close
    var inp = modal.querySelector('.cr-search-input');
    if(inp){ inp.value = ''; filterCrossroads(inp); }
}

function filterCrossroads(input){
    var q    = input.value.trim().toLowerCase();
    var rows = document.querySelectorAll('#crossroadsRealRows .cr-row');
    var vis  = 0;
    rows.forEach(function(r){
        var match = r.dataset.name.includes(q);
        r.classList.toggle('hidden', !match);
        if(match) vis++;
    });
    var noRes = document.getElementById('crNoResults');
    if(noRes) noRes.classList.toggle('show', q.length > 0 && vis === 0 && rows.length > 0);
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeCrossroadsModalBtn();
});
</script>