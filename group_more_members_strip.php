<?php


if (!isset($conn) || !isset($group_id) || !isset($group)) { return; }

$gmm_current_user      = $current_user ?? 0;
$gmm_is_member         = $is_member ?? false;
$gmm_blocked_sql        = $blocked_sql ?? '';
$gmm_group_banned_sql   = $group_banned_sql ?? '';
$gmm_viewer_excluded_sql= $viewer_excluded_sql ?? '';

if (!function_exists('gmm_pic')) {
    function gmm_pic(?string $p): string {
        if (empty($p)) return 'mee.jpg';
        return htmlspecialchars(function_exists('dz_thumb_url') ? dz_thumb_url($p, 120) : $p);   /* small WebP copy when available */
    }
}

if (!function_exists('gmm_lc')) {
    function gmm_lc($v): string {
        return function_exists('mb_strtolower') ? mb_strtolower((string)$v, 'UTF-8') : strtolower((string)$v);
    }
}

if (!function_exists('gmm_format_count')) {
    function gmm_format_count(int $n): string {
        if ($n >= 1000000) {
            return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'm';
        }
        if ($n >= 1000) {
            return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k';
        }
        return (string)$n;
    }
}

/* ── Verified badge (black in light mode, white in dark mode — follows the text colour) ── */
if (!function_exists('gmm_vb')) {
    function gmm_vb(): string {
        return '<svg class="gmm-vb" viewBox="0 0 256 256" aria-label="Verified" fill="currentColor"><path fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></svg>';
    }
}

/* ── One modal row (handles suspended accounts: blank circle + "Suspended" line) ── */
if (!function_exists('gmm_row_html')) {
    function gmm_row_html(array $u, string $badge_html): string {
        $uname = htmlspecialchars($u['username']);
        $nick  = !empty($u['nickname']) ? htmlspecialchars($u['nickname']) : '';
        $uid   = (int)$u['id'];
        $data  = htmlspecialchars(gmm_lc($u['username'] . ' ' . ($u['nickname'] ?? '')));
        $is_s  = !empty($u['is_susp']);
        $vb    = !empty($u['is_verified']) ? gmm_vb() : '';

        $h  = '<div class="gmm-row" data-name="' . $data . '" onclick="location.href=\'profile.php?user_id=' . $uid . '\'">';
        if ($is_s) {
            $h .= '<span class="gmm-row-pic gmm-row-pic-susp"></span>';
        } else {
            $h .= '<img src="' . gmm_pic($u['profile_pic']) . '" onerror="this.src=\'mee.jpg\'" class="gmm-row-pic" alt="' . $uname . '" loading="lazy">';
        }
        $h .= '<div class="gmm-row-info">';
        $h .= '<span class="gmm-row-username">' . $uname . $vb . $badge_html . '</span>';
        if ($is_s) {
            $h .= '<span class="gmm-row-nick gmm-row-susp">Suspended</span>';
        } elseif ($nick) {
            $h .= '<span class="gmm-row-nick">' . $nick . '</span>';
        }
        $h .= '</div><i class="ri-arrow-right-s-line gmm-row-arrow"></i></div>';
        return $h;
    }
}

/* ── Column checks (safe before the SQL is run) ── */
$gmm_has_deact = false;
if (function_exists('dz_has_col')) {
    $gmm_has_deact = (bool)dz_has_col($conn, 'users', 'deactivated_at');
} else {
    $gmm_chk = $conn->query("SHOW COLUMNS FROM `users` LIKE 'deactivated_at'");
    $gmm_has_deact = ($gmm_chk && $gmm_chk->num_rows > 0);
}
$gmm_has_ver = false;
if (function_exists('dz_has_col')) {
    $gmm_has_ver = (bool)dz_has_col($conn, 'users', 'is_verified');
} else {
    $gmm_chk2 = $conn->query("SHOW COLUMNS FROM `users` LIKE 'is_verified'");
    $gmm_has_ver = ($gmm_chk2 && $gmm_chk2->num_rows > 0);
}
$gmm_has_rank = false;
if (function_exists('dz_has_col')) {
    $gmm_has_rank = (bool)dz_has_col($conn, 'users', 'user_rank');
} else {
    $gmm_chk3 = $conn->query("SHOW COLUMNS FROM `users` LIKE 'user_rank'");
    $gmm_has_rank = ($gmm_chk3 && $gmm_chk3->num_rows > 0);
}
$gmm_ver   = $gmm_has_ver ? ", COALESCE(is_verified,0) AS is_verified"   : "";
$gmm_ver_u = $gmm_has_ver ? ", COALESCE(u.is_verified,0) AS is_verified" : "";
$gmm_rank   = $gmm_has_rank ? ", user_rank"   : "";
$gmm_rank_u = $gmm_has_rank ? ", u.user_rank" : "";
$gmm_not_deact_u = $gmm_has_deact ? " AND u.deactivated_at IS NULL" : "";
$gmm_not_deact   = $gmm_has_deact ? " AND deactivated_at IS NULL" : "";
/* banned accounts never show; suspended accounts DO show in the modal (not in the strip) */
$gmm_not_banned_u = $gmm_has_rank ? " AND LOWER(COALESCE(u.user_rank,'')) <> 'banned'" : "";
$gmm_not_banned   = $gmm_has_rank ? " AND LOWER(COALESCE(user_rank,'')) <> 'banned'" : "";

/* ── OWNER ── */
$gmm_owner_id  = (int)($group['created_by'] ?? 0);
$gmm_owner     = null;
if ($gmm_owner_id) {
    $ownq = $conn->query("SELECT id, username, nickname, profile_pic$gmm_ver$gmm_rank FROM users WHERE id = $gmm_owner_id" . $gmm_not_deact . $gmm_not_banned);
    if ($ownq && $ownq->num_rows) {
        $gmm_owner = $ownq->fetch_assoc();
        $gmm_owner['is_susp'] = (gmm_lc($gmm_owner['user_rank'] ?? '') === 'suspended');
    }
}

/* ── ADMINS (excluding owner) ── */
$gmm_admin_ids = $gmm_owner_id ? [$gmm_owner_id] : [];
$gmm_admins_sql = "
    SELECT u.id, u.username, u.nickname, u.profile_pic$gmm_ver_u$gmm_rank_u
    FROM group_admins ga
    JOIN users u ON u.id = ga.user_id
    WHERE ga.group_id = $group_id
" . $gmm_not_deact_u . $gmm_not_banned_u;
if ($gmm_owner_id)                       $gmm_admins_sql .= " AND u.id != $gmm_owner_id";
if (!empty($gmm_blocked_sql))            $gmm_admins_sql .= " AND u.id NOT IN ($gmm_blocked_sql)";
if (!empty($gmm_group_banned_sql))       $gmm_admins_sql .= " AND u.id NOT IN ($gmm_group_banned_sql)";
if (!empty($gmm_viewer_excluded_sql))    $gmm_admins_sql .= " AND u.id NOT IN ($gmm_viewer_excluded_sql)";
$gmm_admins_sql .= " ORDER BY u.username ASC";

$gmm_admins = [];
$ares = $conn->query($gmm_admins_sql);
if ($ares) {
    while ($row = $ares->fetch_assoc()) {
        $row['is_susp'] = (gmm_lc($row['user_rank'] ?? '') === 'suspended');
        $gmm_admins[] = $row;
        $gmm_admin_ids[] = (int)$row['id'];
    }
}

/* ── MODERATORS (excluding owner + admins) ── */
$gmm_mods = [];
$gmm_mod_ids = [];
$gmm_excl_am = !empty($gmm_admin_ids) ? implode(',', array_map('intval', $gmm_admin_ids)) : '0';
$gmm_mods_sql = "
    SELECT u.id, u.username, u.nickname, u.profile_pic$gmm_ver_u$gmm_rank_u
    FROM group_moderators gmo
    JOIN users u ON u.id = gmo.user_id
    JOIN group_members gm ON gm.group_id = gmo.group_id AND gm.user_id = gmo.user_id
    WHERE gmo.group_id = $group_id
      AND u.id NOT IN ($gmm_excl_am)
" . $gmm_not_deact_u . $gmm_not_banned_u;
if (!empty($gmm_blocked_sql))          $gmm_mods_sql .= " AND u.id NOT IN ($gmm_blocked_sql)";
if (!empty($gmm_group_banned_sql))     $gmm_mods_sql .= " AND u.id NOT IN ($gmm_group_banned_sql)";
if (!empty($gmm_viewer_excluded_sql))  $gmm_mods_sql .= " AND u.id NOT IN ($gmm_viewer_excluded_sql)";
$gmm_mods_sql .= " ORDER BY u.username ASC";
try {
    $mdres = $conn->query($gmm_mods_sql);
    if ($mdres) {
        while ($row = $mdres->fetch_assoc()) {
            $row['is_susp'] = (gmm_lc($row['user_rank'] ?? '') === 'suspended');
            $gmm_mods[] = $row;
            $gmm_mod_ids[] = (int)$row['id'];
        }
    }
} catch (Throwable $e) { $gmm_mods = []; $gmm_mod_ids = []; }
$gmm_admin_ids = array_merge($gmm_admin_ids, $gmm_mod_ids);

/* ── MEMBERS (excluding owner + admins + mods), newest joined first ── */
$gmm_exclude_sql = !empty($gmm_admin_ids) ? implode(',', array_map('intval', $gmm_admin_ids)) : '0';
$gmm_members_sql = "
    SELECT u.id, u.username, u.nickname, u.profile_pic$gmm_ver_u$gmm_rank_u, gm.joined_at
    FROM group_members gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = $group_id
      AND u.id NOT IN ($gmm_exclude_sql)
" . $gmm_not_deact_u . $gmm_not_banned_u;
if (!empty($gmm_blocked_sql))          $gmm_members_sql .= " AND u.id NOT IN ($gmm_blocked_sql)";
if (!empty($gmm_group_banned_sql))     $gmm_members_sql .= " AND u.id NOT IN ($gmm_group_banned_sql)";
if (!empty($gmm_viewer_excluded_sql))  $gmm_members_sql .= " AND u.id NOT IN ($gmm_viewer_excluded_sql)";
$gmm_members_sql .= " ORDER BY gm.joined_at DESC";

$gmm_members = [];
$mres = $conn->query($gmm_members_sql);
if ($mres) {
    while ($row = $mres->fetch_assoc()) {
        $row['is_susp'] = (gmm_lc($row['user_rank'] ?? '') === 'suspended');
        $gmm_members[] = $row;
    }
}

/* ── "Newly joined" cutoff — anyone who joined in the last 7 days ── */
$gmm_new_cutoff = strtotime('-7 days');
$gmm_new_members = [];
$gmm_rest_members = [];
foreach ($gmm_members as $m) {
    $jt = !empty($m['joined_at']) ? strtotime($m['joined_at']) : 0;
    if ($jt && $jt >= $gmm_new_cutoff) $gmm_new_members[] = $m;
    else $gmm_rest_members[] = $m;
}

/* ── Preview avatars for the strip (up to 5) — suspended accounts are skipped, the next person takes their place ── */
$gmm_preview = [];
if ($gmm_owner && empty($gmm_owner['is_susp'])) $gmm_preview[] = $gmm_owner;
foreach ($gmm_admins as $a)  { if (count($gmm_preview) < 5 && empty($a['is_susp'])) $gmm_preview[] = $a; }
foreach ($gmm_mods as $a)    { if (count($gmm_preview) < 5 && empty($a['is_susp'])) $gmm_preview[] = $a; }
foreach ($gmm_members as $m) { if (count($gmm_preview) < 5 && empty($m['is_susp'])) $gmm_preview[] = $m; }

/* modal total = everyone listed (suspended included) */
$gmm_total = ($gmm_owner ? 1 : 0) + count($gmm_admins) + count($gmm_mods) + count($gmm_members);

/* strip total = everyone except suspended accounts */
$gmm_strip_total = 0;
if ($gmm_owner && empty($gmm_owner['is_susp'])) $gmm_strip_total++;
foreach ($gmm_admins as $a)  { if (empty($a['is_susp'])) $gmm_strip_total++; }
foreach ($gmm_mods as $a)    { if (empty($a['is_susp'])) $gmm_strip_total++; }
foreach ($gmm_members as $a) { if (empty($a['is_susp'])) $gmm_strip_total++; }

$gmm_count_text  = gmm_format_count($gmm_strip_total);
$gmm_word_text   = ($gmm_strip_total === 1 ? 'member' : 'members');

/* ── Current viewer's own profile info (only needed if a member) ── */
$gmm_me = null;
if ($gmm_is_member && $gmm_current_user) {
    $meq = $conn->query("SELECT id, username, nickname, profile_pic$gmm_ver FROM users WHERE id = $gmm_current_user" . $gmm_not_deact);
    if ($meq && $meq->num_rows) $gmm_me = $meq->fetch_assoc();
}
?>

<!-- ══ STRIP (trigger) — no skeleton, renders instantly ══ -->
<div class="gmm-strip" id="gmmStripReal" onclick="openGmmModal()">
    <div class="gmm-pics">
        <?php foreach ($gmm_preview as $gpu): ?>
        <img src="<?php echo gmm_pic($gpu['profile_pic']); ?>"
             onerror="this.src='mee.jpg'"
             class="gmm-thumb"
             alt="<?php echo htmlspecialchars($gpu['username']); ?>"
             loading="lazy">
        <?php endforeach; ?>
    </div>
    <div class="gmm-label">
        <span class="gmm-label-count"><?php echo htmlspecialchars($gmm_count_text); ?></span>
        <span class="gmm-label-word"><?php echo htmlspecialchars($gmm_word_text); ?></span>
    </div>
</div>


<!-- ══ MODAL — centered lane with grey side lines (same as post modal) ══ -->
<div class="gmm-modal-overlay" id="gmmModal" role="dialog" aria-modal="true" aria-label="Members" onclick="if(event.target===this)closeGmmModal()">
    <div class="gmm-modal-card">

        <!-- TOPBAR: X left · count + title on the right -->
        <div class="gmm-topbar">
            <div class="gmm-tb-side">
                <button type="button" class="gmm-modal-close" onclick="closeGmmModal()" aria-label="Close">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
                </button>
            </div>
            <div class="gmm-topbar-right">
                <span class="gmm-modal-title">
                    <span class="gmm-count-badge"><?php echo $gmm_total; ?></span> Members
                </span>
            </div>
        </div>

        <!-- Search -->
        <div class="gmm-search-wrap">
            <i class="ri-search-line gmm-search-icon"></i>
            <input type="text"
                   class="gmm-search-input"
                   placeholder="Search members…"
                   oninput="filterGmm(this)"
                   autocomplete="off">
        </div>

        <!-- Explainer sentence -->
        <p class="gmm-intro-sentence">These are all the members of this Pod.</p>

        <!-- Pinned "You" row — only if viewer is a member -->
        <?php if ($gmm_me): ?>
        <div class="gmm-me-row" onclick="location.href='profile.php?user_id=<?php echo (int)$gmm_me['id']; ?>'">
            <img src="<?php echo gmm_pic($gmm_me['profile_pic']); ?>"
                 onerror="this.src='mee.jpg'"
                 class="gmm-me-pic" alt="You">
            <div class="gmm-me-info">
                <span class="gmm-me-name">You<?php if (!empty($gmm_me['is_verified'])) echo gmm_vb(); ?></span>
                <span class="gmm-me-sub">@<?php echo htmlspecialchars($gmm_me['username']); ?></span>
            </div>
            <i class="ri-arrow-right-s-line gmm-me-arrow"></i>
        </div>
        <?php endif; ?>

        <div class="gmm-modal-list" id="gmmList">

            <!-- Skeleton (modal only) -->
            <div class="gmm-skeleton-wrap" id="gmmSkeletonList">
                <?php for ($s = 0; $s < 6; $s++): ?>
                <div class="gmm-row gmm-row-skeleton">
                    <span class="gmm-row-pic gmm-skel-circle"></span>
                    <div class="gmm-row-info">
                        <span class="gmm-skel-line gmm-skel-username"></span>
                        <span class="gmm-skel-line gmm-skel-nick"></span>
                    </div>
                </div>
                <?php if ($s < 5): ?><div class="gmm-divider"></div><?php endif; ?>
                <?php endfor; ?>
            </div>

            <!-- Real rows -->
            <div class="gmm-real-wrap" id="gmmRealRows">

                <?php if ($gmm_owner): ?>
                <div class="gmm-sec-head"><i class="ri-vip-crown-line"></i> Pod Owner</div>
                <?php echo gmm_row_html($gmm_owner, '<span class="gmm-role-badge">OWNER</span>'); ?>
                <div class="gmm-divider"></div>
                <?php endif; ?>

                <?php if (!empty($gmm_admins)): ?>
                <div class="gmm-sec-head"><i class="ri-shield-star-line"></i> Admins (<?php echo count($gmm_admins); ?>)</div>
                <?php foreach ($gmm_admins as $ai => $adu): ?>
                <?php echo gmm_row_html($adu, '<span class="gmm-role-badge">ADMIN</span>'); ?>
                <?php if ($ai < count($gmm_admins) - 1): ?><div class="gmm-divider"></div><?php endif; ?>
                <?php endforeach; ?>
                <div class="gmm-divider"></div>
                <?php endif; ?>

                <?php if (!empty($gmm_mods)): ?>
                <div class="gmm-sec-head"><i class="ri-shield-user-line"></i> Moderators (<?php echo count($gmm_mods); ?>)</div>
                <?php foreach ($gmm_mods as $moi => $mou): ?>
                <?php echo gmm_row_html($mou, '<span class="gmm-role-badge">MOD</span>'); ?>
                <?php if ($moi < count($gmm_mods) - 1): ?><div class="gmm-divider"></div><?php endif; ?>
                <?php endforeach; ?>
                <div class="gmm-divider"></div>
                <?php endif; ?>

                <?php if (!empty($gmm_members)): ?>
                <div class="gmm-sec-head"><i class="ri-group-line"></i> Members (<?php echo count($gmm_members); ?>)</div>

                <?php if (!empty($gmm_new_members)): ?>
                <div class="gmm-sub-head">Newly joined</div>
                <?php foreach ($gmm_new_members as $ni => $mu): ?>
                <?php echo gmm_row_html($mu, '<span class="gmm-new-badge">NEW</span>'); ?>
                <?php if ($ni < count($gmm_new_members) - 1): ?><div class="gmm-divider"></div><?php endif; ?>
                <?php endforeach; ?>
                <?php if (!empty($gmm_rest_members)): ?><div class="gmm-divider"></div><?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($gmm_rest_members)): ?>
                <?php if (!empty($gmm_new_members)): ?><div class="gmm-sub-head">All members</div><?php endif; ?>
                <?php foreach ($gmm_rest_members as $ri => $mu): ?>
                <?php echo gmm_row_html($mu, '<span class="gmm-role-badge">MEMBER</span>'); ?>
                <?php if ($ri < count($gmm_rest_members) - 1): ?><div class="gmm-divider"></div><?php endif; ?>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php endif; ?>

            </div><!-- /gmm-real-wrap -->

            <!-- No-results -->
            <div class="gmm-no-results" id="gmmNoResults">
                <i class="ri-search-line"></i>
                <span>No members found</span>
            </div>

        </div><!-- /gmm-modal-list -->
    </div><!-- /gmm-modal-card -->
</div><!-- /gmm-modal-overlay -->


<style>
/* ══════════════════════════════════════════
   GROUP MORE MEMBERS — shimmer (modal only)
══════════════════════════════════════════ */
@keyframes gmmShimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
.gmm-skel-line {
    background: linear-gradient(90deg, rgba(255,255,255,0.06) 25%, rgba(255,255,255,0.16) 50%, rgba(255,255,255,0.06) 75%);
    background-size: 200% 100%;
    animation: gmmShimmer 1.4s ease-in-out infinite;
    border-radius: 4px;
    display: inline-block;
}
.gmm-row-pic.gmm-skel-circle {
    background: linear-gradient(90deg, rgba(255,255,255,0.06) 25%, rgba(255,255,255,0.16) 50%, rgba(255,255,255,0.06) 75%);
    background-size: 200% 100%;
    animation: gmmShimmer 1.4s ease-in-out infinite;
    border: none; box-shadow: none;
}
.gmm-fade-in { animation: gmmFadeIn 0.3s ease forwards; }
@keyframes gmmFadeIn { from{opacity:0;} to{opacity:1;} }

/* ══ STRIP (no skeleton, always visible) ══ */
.gmm-strip {
    display: inline-flex; align-items: center; gap: 10px;
    margin-top: 6px; margin-bottom: 16px;
    padding: 4px 12px 4px 2px;
    border-radius: 40px; max-width: 100%;
    cursor: pointer; user-select: none;
    transition: background 0.2s ease;
    -webkit-tap-highlight-color: transparent;
}
.gmm-strip:hover  { background: rgba(255,255,255,0.04); }
.gmm-strip:active { transform: scale(0.97); }

.gmm-pics { display: flex; align-items: center; flex-shrink: 0; }
.gmm-thumb {
    width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
    border: 2.5px solid #2a5cc9; margin-left: -11px;
    transition: transform 0.2s ease; background: #111;
    box-shadow: 0 0 0 2px #0a0b1e; flex-shrink: 0; display: block;
}
.gmm-thumb:first-child { margin-left: 0; }
.gmm-strip:hover .gmm-thumb { transform: scale(1.08); }

/* Label: count (bright + bigger) + word (dim + smaller), lowered to circle level */
.gmm-label {
    display: inline-flex; align-items: baseline; gap: 4px;
    position: relative; top: 3px;
    font-size: clamp(11.5px, 2.9vw, 13.5px);
    margin: 0; font-family: 'Poppins', sans-serif;
    text-align: left; line-height: 1.2; min-width: 0;
    white-space: nowrap;
}
.gmm-label-count {
    font-size: 1.15em; font-weight: 700;
    color: #fff;
}
.gmm-label-word {
    font-size: 1em; font-weight: 500;
    color: rgba(255,255,255,0.55);
}
.gmm-strip:hover .gmm-label-word { color: rgba(255,255,255,0.8); }

/* ═══════════════════════════════════════════════════════════
   MEMBERS MODAL — same design language as the post modal
   • full-screen overlay, content in a CENTERED LANE
   • one single background color across lane + sides
   • two grey lines separate the lane from the empty space
   • rectangular 5px controls, premium fluid sizing
═══════════════════════════════════════════════════════════ */
.gmm-modal-overlay {
    --pm-blue:#2a5fd8; --pm-blue-h:#234fb5; --pm-blue-l:#7ea6ff;
    --pm-blue-dim:rgba(42,95,216,.18); --pm-blue-glow:rgba(42,95,216,.34);
    --pm-card:rgba(255,255,255,.05); --pm-card2:rgba(255,255,255,.08);
    --pm-line:rgba(255,255,255,.09); --pm-line2:rgba(255,255,255,.14);
    --pm-sub:rgba(255,255,255,.62); --pm-mut:rgba(255,255,255,.45);
    --gmm-ring:#2a5cc9;   /* blue ring colour for pics + suspended placeholder */
    --pm-pad:calc(clamp(9px,3vw,16px) * var(--gmm-s));
    --pm-act-h:calc(clamp(25px,7.2vw,30px) * var(--gmm-s));
    --pm-r:5px;
    --gmm-s:1.1;
    --pm-fs:calc(.8rem * var(--gmm-s));
    --pm-tb-h:calc(54px * var(--gmm-s));

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
@media (max-width:490px){ .gmm-modal-overlay{ --pm-fs:calc(.74rem * var(--gmm-s)); --pm-tb-h:calc(48px * var(--gmm-s)); } }
@media (max-width:390px){ .gmm-modal-overlay{ --pm-fs:calc(.7rem * var(--gmm-s));  --pm-tb-h:calc(44px * var(--gmm-s)); } }

.gmm-modal-overlay.open {
    display: flex;
    animation: gmmSlideIn 0.28s cubic-bezier(0.22,1,0.36,1);
}
@keyframes gmmSlideIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

.gmm-modal-overlay *,
.gmm-modal-overlay *::before,
.gmm-modal-overlay *::after { box-sizing: border-box; }
.gmm-modal-overlay button,
.gmm-modal-overlay input { font-family: 'Poppins', sans-serif; }
.gmm-modal-overlay button { cursor: pointer; -webkit-tap-highlight-color: transparent; }
.gmm-modal-overlay button,
.gmm-modal-overlay input { outline: none; }
/* icons keep the icon font */
.gmm-modal-overlay [class^="ri-"],
.gmm-modal-overlay [class*=" ri-"],
.gmm-modal-overlay [class^="ri-"]::before,
.gmm-modal-overlay [class*=" ri-"]::before {
    font-family: 'remixicon' !important; font-style: normal; font-weight: normal; line-height: 1;
}

/* ── the lane (transparent = same color as the sides) + two grey lines ── */
.gmm-modal-card {
    width: 100%; max-width: calc(580px * var(--gmm-s)); height: 100%; height: 100dvh;
    display: flex; flex-direction: column; min-height: 0;
    background: transparent;
    overflow: hidden;
}
@media (min-width: 801px) {
    .gmm-modal-card { border-left: 1px solid var(--pm-line); border-right: 1px solid var(--pm-line); }
}
@media (max-width: 800px) { .gmm-modal-card { max-width: 100%; border-left: none; border-right: none; } }

/* ── Top bar (no grey line) ── */
.gmm-topbar {
    flex-shrink: 0;
    display: flex; align-items: center;
    min-height: var(--pm-tb-h);
    padding: env(safe-area-inset-top) calc(10px * var(--gmm-s)) 0;
    background: transparent;
    border: none;
}
.gmm-tb-side { flex: 0 0 38px; display: flex; align-items: center; }
.gmm-modal-close {
    width: calc(34px * var(--gmm-s)); height: calc(34px * var(--gmm-s)); background: none; border: none; color: #fff;
    display: flex; align-items: center; justify-content: center; opacity: .9;
    padding: 0; border-radius: 0; transition: 0.2s;
}
.gmm-modal-close:hover { color: var(--pm-blue-l); opacity: 1; background: none; }
.gmm-modal-close svg { width: calc(22px * var(--gmm-s)); height: calc(22px * var(--gmm-s)); display: block; }
@media (max-width:490px){ .gmm-modal-close{ width:calc(30px * var(--gmm-s)); height:calc(30px * var(--gmm-s)); } }

/* count + title sit on the right */
.gmm-topbar-right { flex: 1; min-width: 0; display: flex; justify-content: flex-end; text-align: right; }
.gmm-modal-title {
    font-size: calc(.92rem * var(--gmm-s)); font-weight: 700; color: #fff; line-height: 1.2;
    white-space: nowrap;
    display: flex; align-items: center; justify-content: flex-end; gap: calc(6px * var(--gmm-s));
}
@media (max-width:490px){ .gmm-modal-title{ font-size:calc(.84rem * var(--gmm-s)); } }
@media (max-width:390px){ .gmm-modal-title{ font-size:calc(.8rem * var(--gmm-s)); } }

/* ── Count — white text only ── */
.gmm-count-badge {
    font-size: inherit; font-weight: 700; color: #fff;
    background: none; border: none; padding: 0; line-height: 1;
}

/* ── Search bar — rectangular like post modal inputs ── */
.gmm-search-wrap {
    padding: calc(8px * var(--gmm-s)) var(--pm-pad) calc(6px * var(--gmm-s));
    flex-shrink: 0; position: relative;
    border: none;
}
.gmm-search-icon {
    position: absolute; left: calc(var(--pm-pad) + 11px); top: calc(50% + 1px);
    transform: translateY(-50%);
    color: var(--pm-mut); font-size: calc(14px * var(--gmm-s)); pointer-events: none;
}
.gmm-search-input {
    width: 100%; height: calc(var(--pm-act-h) + calc(6px * var(--gmm-s)));
    padding: 0 calc(11px * var(--gmm-s)) 0 calc(34px * var(--gmm-s)); border-radius: var(--pm-r);
    border: 1px solid var(--pm-line2); background: var(--pm-card); color: #fff;
    font-size: calc(.76rem * var(--gmm-s)); transition: .2s; -webkit-appearance: none; appearance: none;
}
.gmm-search-input::placeholder { color: var(--pm-mut); }
.gmm-search-input:focus {
    border-color: rgba(42,95,216,.85);
    box-shadow: 0 0 0 3px rgba(42,95,216,.18);
    background: var(--pm-card);
}
@media (max-width:490px){ .gmm-search-input{ font-size:calc(.7rem * var(--gmm-s)); } }

/* ── Intro sentence ── */
.gmm-intro-sentence {
    margin: calc(10px * var(--gmm-s)) var(--pm-pad) calc(14px * var(--gmm-s));
    font-size: calc(.66rem * var(--gmm-s)); color: var(--pm-mut);
    font-family: 'Poppins', sans-serif; line-height: 1.5; text-align: left;
}

/* ── Pinned "You" row — edge-to-edge divider lines, no background box ── */
.gmm-me-row {
    display: flex; align-items: center; gap: calc(10px * var(--gmm-s));
    margin: 0 0 calc(2px * var(--gmm-s)); padding: calc(13px * var(--gmm-s)) var(--pm-pad);
    background: transparent;
    border-top: 1.5px solid rgba(255,255,255,0.16);
    border-bottom: 3px solid rgba(255,255,255,0.18);
    cursor: pointer; transition: background 0.15s;
    flex-shrink: 0;
}
.gmm-me-row:hover { background: rgba(255,255,255,0.04); }
.gmm-me-pic {
    width: calc(44px * var(--gmm-s)); height: calc(44px * var(--gmm-s)); border-radius: 50%; object-fit: cover;
    border: 2.5px solid #2a5cc9; flex-shrink: 0;
    box-shadow: 0 0 12px rgba(42,92,201,0.28);
}
.gmm-me-info { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: calc(1px * var(--gmm-s)); margin: 0; padding: 0; text-align: left; }
.gmm-me-name { font-size: calc(13px * var(--gmm-s)); font-weight: 700; color: #4d8fff; font-family: 'Poppins', sans-serif; margin: 0; padding: 0; text-align: left; }
.gmm-me-sub  { font-size: calc(11px * var(--gmm-s)); color: rgba(255,255,255,0.4); font-family: 'Poppins', sans-serif; margin: 0; padding: 0; text-align: left; }
.gmm-me-arrow { color: #fff; font-size: calc(22px * var(--gmm-s)); flex-shrink: 0; }

/* ── Scrollable list (grey scrollbar) ── */
.gmm-modal-list {
    overflow-y: auto; overflow-x: hidden; flex: 1; min-height: 0;
    -webkit-overflow-scrolling: touch;
    padding: calc(4px * var(--gmm-s)) 0 calc(env(safe-area-inset-bottom, 0px) + calc(24px * var(--gmm-s)));
    scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.25) transparent;
}
.gmm-modal-list::-webkit-scrollbar { width: calc(4px * var(--gmm-s)); }
.gmm-modal-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,.25); border-radius: 99px; }

.gmm-skeleton-wrap { display: block; }
.gmm-real-wrap     { display: none; }

/* ── Section headers ── */
.gmm-sec-head {
    display: flex; align-items: center; gap: calc(7px * var(--gmm-s));
    font-size: calc(10px * var(--gmm-s)); font-weight: 700; letter-spacing: 1.1px; text-transform: uppercase;
    color: rgba(77,143,255,0.75);
    font-family: 'Poppins', sans-serif;
    padding: calc(16px * var(--gmm-s)) var(--pm-pad) calc(8px * var(--gmm-s));
}
.gmm-sec-head i { font-size: calc(13px * var(--gmm-s)); color: #4d8fff; }
.gmm-sub-head {
    font-size: calc(10px * var(--gmm-s)); font-weight: 600; letter-spacing: 0.6px;
    color: rgba(255,255,255,0.28);
    font-family: 'Poppins', sans-serif;
    padding: calc(10px * var(--gmm-s)) var(--pm-pad) calc(6px * var(--gmm-s));
}

/* ── Row ── */
.gmm-row {
    display: flex; align-items: center; gap: calc(8px * var(--gmm-s));
    padding: calc(13px * var(--gmm-s)) var(--pm-pad); cursor: pointer;
    transition: background 0.15s ease;
    -webkit-tap-highlight-color: transparent;
}
.gmm-row:hover  { background: rgba(255,255,255,0.05); }
.gmm-row:active { background: var(--pm-blue-dim); }
.gmm-row.hidden { display: none; }

.gmm-row-skeleton       { cursor: default; }
.gmm-row-skeleton:hover { background: transparent; }

/* ── Profile pics in modal (border colour = --gmm-ring, blue) ── */
.gmm-row-pic {
    width: calc(52px * var(--gmm-s)); height: calc(52px * var(--gmm-s)); border-radius: 50%;
    object-fit: cover; border: 2.5px solid var(--gmm-ring, #2a5cc9);
    flex-shrink: 0; background: #1a1a1a;
    box-shadow: 0 0 12px rgba(42,92,201,0.28);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: block;
}
.gmm-row:hover .gmm-row-pic { transform: scale(1.06); box-shadow: 0 0 18px rgba(42,92,201,0.45); }

/* ── Suspended account: blank circle + blue "Suspended" line ── */
.gmm-row-pic.gmm-row-pic-susp { background: rgba(255,255,255,.1); box-shadow: none; }
.gmm-row:hover .gmm-row-pic.gmm-row-pic-susp { box-shadow: none; }
.gmm-row-nick.gmm-row-susp { color: #4d8fff; font-weight: 700; }

.gmm-row-info { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: calc(1px * var(--gmm-s)); margin: 0; padding: 0; text-align: left; }
.gmm-row-username {
    font-size: calc(.82rem * var(--gmm-s)); font-weight: 700; color: #fff;
    font-family: 'Poppins', sans-serif; margin: 0; padding: 0; text-align: left;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: flex; align-items: center; justify-content: flex-start; gap: calc(6px * var(--gmm-s)); width: 100%;
}
.gmm-row-nick {
    font-size: calc(.64rem * var(--gmm-s)); color: var(--pm-mut);
    font-family: 'Poppins', sans-serif; margin: 0; padding: 0; text-align: left;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* ── Chevron: white + bigger ── */
.gmm-row-arrow {
    color: #fff; font-size: calc(22px * var(--gmm-s)); flex-shrink: 0;
    transition: transform 0.2s ease;
}
.gmm-row:hover .gmm-row-arrow { transform: translateX(3px); color: #fff; }

/* ── Verified badge: white in dark mode, black in light mode ── */
.gmm-vb { width: .84em; height: .84em; flex-shrink: 0; display: inline-block; vertical-align: -.08em; margin-left: calc(4px * var(--gmm-s)); color: #fff; }
.gmm-row-username .gmm-vb { margin-left: calc(-2px * var(--gmm-s)); }
.gmm-me-name .gmm-vb { color: #fff; }
[data-theme="light"] .gmm-vb,
[data-theme="light"] .gmm-me-name .gmm-vb { color: #0b0d24; }

/* ── Badges (blue, boxy) ── */
.gmm-role-badge {
    font-size: calc(9px * var(--gmm-s)); font-weight: 700;
    padding: calc(2px * var(--gmm-s)) calc(7px * var(--gmm-s)); border-radius: 4px;
    display: inline-flex; align-items: center;
    letter-spacing: 0.4px; flex-shrink: 0;
    font-family: 'Poppins', sans-serif; line-height: 1.4;
    background: rgba(26,86,219,0.16);
    color: #4d8fff;
    border: 1px solid rgba(26,86,219,0.4);
}
.gmm-new-badge {
    font-size: calc(8.5px * var(--gmm-s)); font-weight: 700;
    padding: calc(2px * var(--gmm-s)) calc(7px * var(--gmm-s)); border-radius: 4px;
    display: inline-flex; align-items: center;
    letter-spacing: 0.5px; flex-shrink: 0;
    font-family: 'Poppins', sans-serif; line-height: 1.4;
    background: rgba(26,86,219,0.1);
    color: rgba(77,143,255,0.75);
    border: 1px solid rgba(26,86,219,0.28);
}

/* ── Divider ── */
.gmm-divider {
    height: calc(1px * var(--gmm-s)); margin: 0 var(--pm-pad);
    background: linear-gradient(90deg,
        rgba(255,255,255,0.06) 0%, rgba(255,255,255,0.06) 35%,
        rgba(255,255,255,0.28) 50%,
        rgba(255,255,255,0.06) 65%, rgba(255,255,255,0.06) 100%);
    background-size: 250% 100%;
    animation: gmmDivShimmer 2.6s linear infinite;
}
@keyframes gmmDivShimmer { 0%{background-position:200% 0;} 100%{background-position:-200% 0;} }

/* ── Skeleton row placeholder sizes ── */
.gmm-skel-username { width: calc(130px * var(--gmm-s)); height: calc(14px * var(--gmm-s)); margin-bottom: calc(2px * var(--gmm-s)); display: block; }
.gmm-skel-nick     { width: calc(90px * var(--gmm-s));  height: calc(11px * var(--gmm-s)); display: block; }

/* ── No-results ── */
.gmm-no-results {
    display: none; flex-direction: column; align-items: center; gap: calc(8px * var(--gmm-s));
    padding: calc(40px * var(--gmm-s)) calc(20px * var(--gmm-s)); text-align: center;
    color: var(--pm-mut); font-size: calc(.72rem * var(--gmm-s));
    font-family: 'Poppins', sans-serif;
}
.gmm-no-results i { font-size: calc(1.8rem * var(--gmm-s)); color: rgba(126,166,255,.45); }
.gmm-no-results.show { display: flex; }

@media (prefers-reduced-motion:reduce){
    .gmm-modal-overlay *{ transition-duration:.01ms !important; animation-duration:.01ms !important; }
}


/* ══ MOBILE / RESPONSIVE — scrollbar completely hidden (scrolling still works) ══ */
@media (max-width: 800px) {
    .gmm-modal-list {
        scrollbar-width: none;            /* Firefox */
        -ms-overflow-style: none;         /* old Edge / IE */
    }
    .gmm-modal-list::-webkit-scrollbar,
    .gmm-modal-list::-webkit-scrollbar-track,
    .gmm-modal-list::-webkit-scrollbar-thumb {
        display: none;
        width: 0;
        height: 0;
        background: transparent;
    }
}

/* ══ RESPONSIVE — 790px ══ */
@media (max-width: 790px) {
    .gmm-strip { gap: 9px; margin-top: 5px; margin-bottom: 14px; padding: 4px 10px 4px 2px; }
    .gmm-thumb { width: 30px; height: 30px; margin-left: -10px; }
    .gmm-label { font-size: clamp(11px, 2.8vw, 13px); }

    .gmm-me-row        { padding-top: calc(11px * var(--gmm-s)); padding-bottom: calc(11px * var(--gmm-s)); gap: calc(9px * var(--gmm-s)); }
    .gmm-me-pic        { width: calc(40px * var(--gmm-s)); height: calc(40px * var(--gmm-s)); }
    .gmm-me-arrow      { font-size: calc(20px * var(--gmm-s)); }
    .gmm-sec-head      { padding-top: calc(14px * var(--gmm-s)); padding-bottom: calc(7px * var(--gmm-s)); font-size: calc(9.5px * var(--gmm-s)); }
    .gmm-sub-head      { padding-top: calc(9px * var(--gmm-s)); padding-bottom: calc(5px * var(--gmm-s)); }
    .gmm-row           { padding-top: calc(11px * var(--gmm-s)); padding-bottom: calc(11px * var(--gmm-s)); gap: calc(9px * var(--gmm-s)); }
    .gmm-row-pic       { width: calc(46px * var(--gmm-s)); height: calc(46px * var(--gmm-s)); }
    .gmm-row-arrow     { font-size: calc(20px * var(--gmm-s)); }
}

/* ══ RESPONSIVE — 490px ══ */
@media (max-width: 490px) {
    .gmm-strip { gap: 8px; margin-top: 4px; margin-bottom: 12px; padding: 3px 9px 3px 2px; }
    .gmm-thumb { width: 27px; height: 27px; margin-left: -9px; box-shadow: 0 0 0 1.5px #0a0b1e; }
    .gmm-label { font-size: clamp(10px, 2.6vw, 12px); top: 2.5px; }

    .gmm-me-row        { padding-top: calc(9px * var(--gmm-s)); padding-bottom: calc(9px * var(--gmm-s)); gap: calc(8px * var(--gmm-s)); border-top-width: 1.25px; border-bottom-width: 2.5px; }
    .gmm-me-pic        { width: calc(36px * var(--gmm-s)); height: calc(36px * var(--gmm-s)); }
    .gmm-me-name       { font-size: calc(12px * var(--gmm-s)); }
    .gmm-me-sub        { font-size: calc(10px * var(--gmm-s)); }
    .gmm-me-arrow      { font-size: calc(18px * var(--gmm-s)); }
    .gmm-sec-head      { padding-top: calc(12px * var(--gmm-s)); padding-bottom: calc(6px * var(--gmm-s)); font-size: calc(9px * var(--gmm-s)); }
    .gmm-sub-head      { padding-top: calc(8px * var(--gmm-s)); padding-bottom: calc(4px * var(--gmm-s)); font-size: calc(9px * var(--gmm-s)); }
    .gmm-row           { padding-top: calc(10px * var(--gmm-s)); padding-bottom: calc(10px * var(--gmm-s)); gap: calc(8px * var(--gmm-s)); }
    .gmm-row-pic       { width: calc(42px * var(--gmm-s)); height: calc(42px * var(--gmm-s)); }
    .gmm-row-username  { font-size: calc(.76rem * var(--gmm-s)); }
    .gmm-row-nick      { font-size: calc(.6rem * var(--gmm-s)); }
    .gmm-row-arrow     { font-size: calc(18px * var(--gmm-s)); }
    .gmm-role-badge    { font-size: calc(8px * var(--gmm-s)); padding: calc(1px * var(--gmm-s)) calc(5px * var(--gmm-s)); }
}

/* ══ RESPONSIVE — 380px ══ */
@media (max-width: 380px) {
    .gmm-thumb { width: 23px; height: 23px; margin-left: -8px; }
    .gmm-label { font-size: 9.5px; top: 2px; }

    .gmm-me-row        { padding-top: calc(8px * var(--gmm-s)); padding-bottom: calc(8px * var(--gmm-s)); gap: calc(7px * var(--gmm-s)); border-top-width: 1px; border-bottom-width: 2px; }
    .gmm-me-pic        { width: calc(32px * var(--gmm-s)); height: calc(32px * var(--gmm-s)); }
    .gmm-me-name       { font-size: calc(11px * var(--gmm-s)); }
    .gmm-me-sub        { font-size: calc(9.5px * var(--gmm-s)); }
    .gmm-me-arrow      { font-size: calc(17px * var(--gmm-s)); }
    .gmm-sec-head      { padding-top: calc(11px * var(--gmm-s)); padding-bottom: calc(5px * var(--gmm-s)); font-size: calc(8.5px * var(--gmm-s)); }
    .gmm-sub-head      { padding-top: calc(7px * var(--gmm-s)); padding-bottom: calc(4px * var(--gmm-s)); font-size: calc(8.5px * var(--gmm-s)); }
    .gmm-row           { padding-top: calc(9px * var(--gmm-s)); padding-bottom: calc(9px * var(--gmm-s)); gap: calc(7px * var(--gmm-s)); }
    .gmm-row-pic       { width: calc(38px * var(--gmm-s)); height: calc(38px * var(--gmm-s)); }
    .gmm-row-username  { font-size: calc(.72rem * var(--gmm-s)); }
    .gmm-row-nick      { font-size: calc(.57rem * var(--gmm-s)); }
    .gmm-row-arrow     { font-size: calc(17px * var(--gmm-s)); }
    .gmm-role-badge    { font-size: calc(7.5px * var(--gmm-s)); padding: calc(1px * var(--gmm-s)) calc(5px * var(--gmm-s)); }
    .gmm-new-badge     { font-size: calc(7.5px * var(--gmm-s)); padding: calc(1px * var(--gmm-s)) calc(5px * var(--gmm-s)); }
}

/* ══ RESPONSIVE — 330px ══ */
@media (max-width: 330px) {
    .gmm-thumb { width: 19px; height: 19px; margin-left: -7px; }
    .gmm-label { font-size: 9px; top: 1.5px; }

    .gmm-search-input  { font-size: calc(0.66rem * var(--gmm-s)); }

    .gmm-me-row        { padding-top: calc(6px * var(--gmm-s)); padding-bottom: calc(6px * var(--gmm-s)); gap: calc(6px * var(--gmm-s)); }
    .gmm-me-pic        { width: calc(28px * var(--gmm-s)); height: calc(28px * var(--gmm-s)); }
    .gmm-sec-head      { padding-top: calc(9px * var(--gmm-s)); padding-bottom: calc(4px * var(--gmm-s)); font-size: calc(8px * var(--gmm-s)); }
    .gmm-sub-head      { padding-top: calc(6px * var(--gmm-s)); padding-bottom: calc(4px * var(--gmm-s)); font-size: calc(8px * var(--gmm-s)); }
    .gmm-row           { padding-top: calc(8px * var(--gmm-s)); padding-bottom: calc(8px * var(--gmm-s)); gap: calc(6px * var(--gmm-s)); }
    .gmm-row-pic       { width: calc(34px * var(--gmm-s)); height: calc(34px * var(--gmm-s)); }
    .gmm-row-username  { font-size: calc(.68rem * var(--gmm-s)); }
    .gmm-row-nick      { font-size: calc(.54rem * var(--gmm-s)); }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched; the pics in the strip keep their look) ═══════════ */
[data-theme="light"] .gmm-strip:hover { background: rgba(6,7,26,0.04); }
[data-theme="light"] .gmm-label-count { color: #0b0d24; }
[data-theme="light"] .gmm-label-word { color: #5f647a; }
[data-theme="light"] .gmm-strip:hover .gmm-label-word { color: #0b0d24; }
[data-theme="light"] .gmm-modal-overlay {
    --pm-card: rgba(6,7,26,.05); --pm-card2: rgba(6,7,26,.08);
    --pm-line: rgba(6,7,26,.1);  --pm-line2: rgba(6,7,26,.2);
    --pm-sub: rgba(6,7,26,.66);  --pm-mut: rgba(6,7,26,.52);
    --pm-blue-l: #2a5fd8;
    background: linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    background-attachment: fixed;
    color: #0b0d24;
}
[data-theme="light"] .gmm-modal-close { color: #0b0d24; }
[data-theme="light"] .gmm-modal-close:hover { color: var(--pm-blue-l); }
[data-theme="light"] .gmm-modal-title,
[data-theme="light"] .gmm-count-badge,
[data-theme="light"] .gmm-row-username,
[data-theme="light"] .gmm-row-arrow,
[data-theme="light"] .gmm-row:hover .gmm-row-arrow,
[data-theme="light"] .gmm-me-arrow { color: #0b0d24; }
[data-theme="light"] .gmm-search-input { background: #fff; color: #0b0d24; border-color: rgba(6,7,26,.22); }
[data-theme="light"] .gmm-search-input::placeholder { color: rgba(6,7,26,.5); }
[data-theme="light"] .gmm-search-input:focus { background: #fff; }
[data-theme="light"] .gmm-me-row { border-top-color: rgba(6,7,26,0.16); border-bottom-color: rgba(6,7,26,0.14); }
[data-theme="light"] .gmm-me-row:hover,
[data-theme="light"] .gmm-row:hover { background: rgba(6,7,26,0.05); }
[data-theme="light"] .gmm-me-name { color: #1a56db; }
[data-theme="light"] .gmm-me-sub { color: #5f647a; }
[data-theme="light"] .gmm-modal-list { scrollbar-color: rgba(6,7,26,.25) transparent; }
[data-theme="light"] .gmm-modal-list::-webkit-scrollbar-thumb { background: rgba(6,7,26,.25); }
[data-theme="light"] .gmm-sec-head { color: rgba(26,86,219,0.85); }
[data-theme="light"] .gmm-sec-head i { color: #1a56db; }
[data-theme="light"] .gmm-sub-head { color: #5f647a; }
[data-theme="light"] .gmm-role-badge { color: #1a56db; background: rgba(26,86,219,0.1); border-color: rgba(26,86,219,0.35); }
[data-theme="light"] .gmm-new-badge { color: #1a56db; background: rgba(26,86,219,0.07); border-color: rgba(26,86,219,0.28); }
[data-theme="light"] .gmm-row-pic.gmm-row-pic-susp { background: rgba(6,7,26,.1); }
[data-theme="light"] .gmm-row-nick.gmm-row-susp { color: #1a56db; }
[data-theme="light"] .gmm-skel-line,
[data-theme="light"] .gmm-row-pic.gmm-skel-circle {
    background: linear-gradient(90deg, rgba(6,7,26,0.06) 25%, rgba(6,7,26,0.14) 50%, rgba(6,7,26,0.06) 75%);
    background-size: 200% 100%;
}
[data-theme="light"] .gmm-divider {
    background: linear-gradient(90deg, rgba(6,7,26,0.07) 0%, rgba(6,7,26,0.07) 35%, rgba(6,7,26,0.22) 50%, rgba(6,7,26,0.07) 65%, rgba(6,7,26,0.07) 100%);
    background-size: 250% 100%;
}
[data-theme="light"] .gmm-no-results i { color: rgba(42,95,216,.6); }
</style>


<script>
/* ══ Modal (skeleton → real rows lives only here) ══ */
function openGmmModal() {
    document.getElementById('gmmModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    _gmmShowSkeleton();
}

function _gmmShowSkeleton() {
    var skel  = document.getElementById('gmmSkeletonList');
    var real  = document.getElementById('gmmRealRows');
    if (!skel || !real) return;

    skel.style.display = 'block';
    real.style.display = 'none';
    real.classList.remove('gmm-fade-in');

    /* only real <img> pics are waited for — suspended placeholders are <span>s */
    var imgs   = real.querySelectorAll('img.gmm-row-pic');
    var total  = imgs.length;
    var loaded = 0;
    var done   = false;

    function reveal() {
        if (done) return;
        done = true;
        skel.style.display = 'none';
        real.style.display = 'block';
        real.classList.add('gmm-fade-in');
    }
    function tick() { loaded++; if (loaded >= total) reveal(); }

    if (total === 0) { reveal(); return; }
    imgs.forEach(function (img) {
        if (img.complete) tick();
        else { img.addEventListener('load', tick); img.addEventListener('error', tick); }
    });
    setTimeout(reveal, 1100);
}

function closeGmmModal() {
    var modal = document.getElementById('gmmModal');
    modal.classList.remove('open');
    document.body.style.overflow = '';
    var inp = modal.querySelector('.gmm-search-input');
    if (inp) { inp.value = ''; filterGmm(inp); }
}

function filterGmm(input) {
    var q    = input.value.trim().toLowerCase();
    var rows = document.querySelectorAll('#gmmRealRows .gmm-row');
    var vis  = 0;
    rows.forEach(function (r) {
        var match = r.dataset.name && r.dataset.name.includes(q);
        r.classList.toggle('hidden', !match);
        if (match) vis++;
    });
    /* hide section/sub headers + dividers while a search is active */
    document.querySelectorAll('#gmmRealRows .gmm-sec-head, #gmmRealRows .gmm-sub-head, #gmmRealRows .gmm-divider').forEach(function(h){
        h.style.display = q.length > 0 ? 'none' : '';
    });
    var noRes = document.getElementById('gmmNoResults');
    if (noRes) noRes.classList.toggle('show', q.length > 0 && vis === 0 && rows.length > 0);
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('gmmModal').classList.contains('open')) {
        closeGmmModal();
    }
});
</script>