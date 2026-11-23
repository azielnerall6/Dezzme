<?php
/* ═══════════════════════════════════════════════════════════════
   dz_privacy.php — Dezzme activity-status privacy, download protection and default post settings
   • user_settings.online_visibility    0 = nobody · 1 = my trackers · 2 = everyone   (the green "online" dot)
   • user_settings.last_seen_visibility 0 = nobody · 1 = my trackers · 2 = everyone   ("Active 5m ago")
     Full Private accounts are capped at "my trackers" automatically. "Trackers" = people who track ME.
     Old accounts: the old on/off "show_online_status" still counts until the person picks a level (off = nobody, on = everyone).
     show_online_status is kept in step (1 only when everyone) so a page that still reads the old switch hides rather than leaks.
   • memes.allow_download               1 = anyone can download · 0 = creator turned downloads off
   • user_settings.def_*                the person's default settings for new posts
   Every column is added automatically the first time it is needed.
   ═══════════════════════════════════════════════════════════════ */
if (defined('DZ_PRIVACY_LOADED')) return;
define('DZ_PRIVACY_LOADED', 1);

function dz_priv_ensure($conn = null): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!$conn) { $conn = $GLOBALS['conn'] ?? null; }
    if (!$conn) return;
    if (!empty($_SESSION['dz_priv_cols_v1'])) return;
    try {
        $need = [
            'user_settings' => [
                'online_visibility'    => 'TINYINT NULL DEFAULT NULL',
                'last_seen_visibility' => 'TINYINT NULL DEFAULT NULL',
                'def_hide_likes'       => 'TINYINT(1) NOT NULL DEFAULT 0',
                'def_hide_reposts'     => 'TINYINT(1) NOT NULL DEFAULT 0',
                'def_hide_saves'       => 'TINYINT(1) NOT NULL DEFAULT 0',
                'def_hide_shares'      => 'TINYINT(1) NOT NULL DEFAULT 0',
                'def_ai_label'         => 'TINYINT(1) NOT NULL DEFAULT 0',
                'def_allow_download'   => 'TINYINT(1) NOT NULL DEFAULT 1',
            ],
            'memes' => ['allow_download' => 'TINYINT(1) NOT NULL DEFAULT 1'],
        ];
        foreach ($need as $tbl => $cols) {
            foreach ($cols as $c => $def) {
                $r = $conn->query("SHOW COLUMNS FROM `$tbl` LIKE '$c'");
                if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE `$tbl` ADD COLUMN `$c` $def");
            }
        }
        $_SESSION['dz_priv_cols_v1'] = 1;
    } catch (Throwable $e) {}
}

/* SQL: the level (0 / 1 / 2) a person has chosen for "online" or "last seen", after the Full Private cap.
   $us = alias of user_settings (LEFT JOINed), kind = 'online' | 'last' */
function dz_vis_level_sql(string $us, string $kind = 'online'): string {
    dz_priv_ensure();
    $col = $kind === 'last' ? 'last_seen_visibility' : 'online_visibility';
    return "LEAST(COALESCE($us.`$col`, COALESCE($us.`online_visibility`, CASE WHEN $us.`show_online_status` = 0 THEN 0 ELSE 2 END)), CASE WHEN COALESCE($us.`is_fully_private`,0) = 1 THEN 1 ELSE 2 END)";
}

/* SQL (1 / 0): does person $u show "online" / "last seen" to viewer $viewer?   $us and $u are table aliases. */
function dz_vis_ok_sql(string $us, string $u, int $viewer, string $kind = 'online'): string {
    $L = dz_vis_level_sql($us, $kind); $v = (int)$viewer;
    return "(CASE WHEN $L = 2 THEN 1 WHEN $L = 1 AND EXISTS (SELECT 1 FROM followers dzv_f WHERE dzv_f.follower_id = $v AND dzv_f.following_id = $u.id) THEN 1 ELSE 0 END)";
}

/* SQL: both answers packed in one number — bit 1 = online ok, bit 2 = last seen ok (3 = both, the old "1") */
function dz_vis_mask_sql(string $us, string $u, int $viewer): string {
    return "(" . dz_vis_ok_sql($us, $u, $viewer, 'online') . " + 2 * " . dz_vis_ok_sql($us, $u, $viewer, 'last') . ")";
}

/* the viewer's own choice, same bits: you only see other people's status if you share your own ("hide yours → you don't see theirs") */
function dz_viewer_mask($conn, int $uid): int {
    dz_priv_ensure($conn);
    try {
        $r = $conn->query("SELECT online_visibility AS o, last_seen_visibility AS l, show_online_status AS s FROM user_settings WHERE user_id = " . (int)$uid . " LIMIT 1");
        $row = ($r instanceof mysqli_result) ? $r->fetch_assoc() : null;
        if (!$row) return 3;
        $legacy = ($row['s'] !== null && (int)$row['s'] === 0) ? 0 : 2;
        $o = $row['o'] !== null ? (int)$row['o'] : $legacy;
        $l = $row['l'] !== null ? (int)$row['l'] : $o;
        return ($o > 0 ? 1 : 0) + ($l > 0 ? 2 : 0);
    } catch (Throwable $e) { return 3; }
}

/* PHP: may $viewer see $uid's "online" ('online') or "last seen" ('last')? (used where a page asks one person at a time) */
function dz_presence_allowed($conn, int $uid, int $viewer, string $kind = 'online'): bool {
    try {
        if ($viewer > 0 && !(dz_viewer_mask($conn, $viewer) & ($kind === 'last' ? 2 : 1))) return false;
        $sql = "SELECT " . dz_vis_ok_sql('us', 'u', $viewer, $kind) . " AS ok FROM users u LEFT JOIN user_settings us ON us.user_id = u.id WHERE u.id = " . (int)$uid . " LIMIT 1";
        $r = $conn->query($sql);
        $row = ($r instanceof mysqli_result) ? $r->fetch_assoc() : null;
        return $row ? ((int)$row['ok'] === 1) : true;
    } catch (Throwable $e) { return true; }
}

/* ── downloads ── */
/* the creator turns downloads on / off for one of their own posts. Returns the new value (1 / 0) or null if it isn't theirs. */
function dz_toggle_download($conn, int $uid, int $meme_id): ?int {
    dz_priv_ensure($conn);
    try {
        $st = $conn->prepare("UPDATE memes SET allow_download = 1 - allow_download WHERE id = ? AND uploaded_by = ?");
        $st->bind_param("ii", $meme_id, $uid); $st->execute(); $n = $st->affected_rows; $st->close();
        if ($n < 1) return null;
        $r = $conn->query("SELECT allow_download FROM memes WHERE id = " . (int)$meme_id);
        $row = ($r instanceof mysqli_result) ? $r->fetch_assoc() : null;
        return $row ? (int)$row['allow_download'] : null;
    } catch (Throwable $e) { return null; }
}

/* ── default post settings ── call this right after a new post is saved (upload.php / remix_upload.php / text post) ── */
function dz_apply_post_defaults($conn, int $uid, int $meme_id): void {
    dz_priv_ensure($conn);
    try {
        $r = $conn->query("SELECT def_hide_likes, def_hide_reposts, def_hide_saves, def_hide_shares, def_ai_label, def_allow_download FROM user_settings WHERE user_id = " . (int)$uid . " LIMIT 1");
        $d = ($r instanceof mysqli_result) ? $r->fetch_assoc() : null;
        if (!$d) return;
        $st = $conn->prepare("UPDATE memes SET hide_likes = ?, hide_reposts = ?, hide_saves = ?, hide_shares = ?, is_ai_generated = GREATEST(is_ai_generated, ?), allow_download = ? WHERE id = ? AND uploaded_by = ?");
        $a = (int)$d['def_hide_likes']; $b = (int)$d['def_hide_reposts']; $c = (int)$d['def_hide_saves']; $e = (int)$d['def_hide_shares']; $f = (int)$d['def_ai_label']; $g = (int)$d['def_allow_download'];
        $st->bind_param("iiiiiiii", $a, $b, $c, $e, $f, $g, $meme_id, $uid); $st->execute(); $st->close();
    } catch (Throwable $e) {}
}