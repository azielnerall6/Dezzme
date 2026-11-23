<?php
/* ══════════════════════════════════════════════════════════════════════════
   profile_setup.php — ONE-TIME database setup for profile.php

   profile.php used to run CREATE TABLE IF NOT EXISTS / SHOW COLUMNS / ALTER on
   every single page view. That work now lives here and runs ONCE:

     • profile.php calls dz_schema_ensure() at the top.
     • If the flag file  profile_schema_v1.flag  does not exist yet, it loads this
       file, runs dz_profile_setup($conn), then writes the flag file.
     • From then on every request only does ONE cheap file_exists() check.

   To run it again (e.g. after adding new indexes here): delete profile_schema_v1.flag.

   Everything below is safe to run more than once (idempotent) and every step is
   wrapped so a missing table / column can never crash the page.
   ══════════════════════════════════════════════════════════════════════════ */
if (!function_exists('dz_profile_setup')) {

    /* adds an index only when no existing index already starts with the same columns */
    function dz_profile_add_index($q, $table, array $cols) {
        $res = $q("SHOW INDEX FROM `$table`");
        if (!($res instanceof mysqli_result)) return;          // table doesn't exist → skip
        $idx = [];
        while ($r = $res->fetch_assoc()) {
            $idx[$r['Key_name']][(int)$r['Seq_in_index']] = $r['Column_name'];
        }
        foreach ($idx as $existing) {
            ksort($existing);
            $existing = array_values($existing);
            if (array_slice($existing, 0, count($cols)) === $cols) return;   // already covered
        }
        $name  = 'dz_ix_' . $table . '_' . implode('_', $cols);
        $names = '`' . implode('`,`', $cols) . '`';
        $q("ALTER TABLE `$table` ADD INDEX `$name` ($names)");
    }

    function dz_profile_setup($conn) {
        if (!$conn) return false;
        $q = function ($sql) use ($conn) {
            try { return $conn->query($sql); } catch (Throwable $e) { return false; }
        };

        /* ── tables ── */
        $q("CREATE TABLE IF NOT EXISTS profile_vibes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                target_id INT NOT NULL,
                voter_id INT NOT NULL DEFAULT 0,
                vibe VARCHAR(100) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pvb_target (target_id),
                INDEX idx_pvb_voter (target_id, voter_id)
           )");
        $c = $q("SHOW COLUMNS FROM profile_vibes LIKE 'voter_id'");
        if ($c instanceof mysqli_result && $c->num_rows === 0) {
            $q("ALTER TABLE profile_vibes ADD COLUMN voter_id INT NOT NULL DEFAULT 0");
        }

        $q("CREATE TABLE IF NOT EXISTS profile_vibe_settings (
                user_id INT NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
           )");

        $q("CREATE TABLE IF NOT EXISTS profile_views (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_id INT NOT NULL,
                viewer_id INT NOT NULL,
                viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pv_profile (profile_id, viewed_at),
                INDEX idx_pv_viewer (profile_id, viewer_id)
           )");

        $q("CREATE TABLE IF NOT EXISTS untracked_seen (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                seen_at DATETIME NOT NULL
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* ── "online" dot: add users.last_seen only if NONE of the usual activity columns exists ── */
        $has_any = false;
        foreach (['last_seen', 'last_active', 'last_activity', 'last_online'] as $col) {
            $r = $q("SHOW COLUMNS FROM users LIKE '$col'");
            if ($r instanceof mysqli_result && $r->num_rows > 0) { $has_any = true; break; }
        }
        if (!$has_any) $q("ALTER TABLE users ADD COLUMN last_seen DATETIME NULL DEFAULT NULL");

        /* ── indexes (only added where no equivalent index already exists) ── */
        $wanted = [
            ['memes',                  ['uploaded_by', 'created_at']],
            ['likes',                  ['meme_id']],
            ['reposts',                ['meme_id']],
            ['favorites',              ['meme_id']],
            ['followers',              ['follower_id', 'following_id']],
            ['followers',              ['following_id', 'follower_id']],
            ['group_members',          ['user_id']],
            ['group_members',          ['group_id', 'user_id']],
            ['group_posts',            ['group_id', 'meme_id']],
            ['group_posts',            ['posted_by']],
            ['track_requests',         ['target_id', 'status']],
            ['blocks',                 ['blocker_id', 'blocked_id']],
            ['blocks',                 ['blocked_id']],
            ['messages',               ['receiver_id', 'seen']],
            ['highlights',             ['user_id']],
            ['highlight_media',        ['highlight_id']],
            ['links',                  ['user_id']],
            ['users',                  ['username']],
            ['users',                  ['nickname']],
            ['user_settings',          ['user_id']],
            ['unfollow_log',           ['user_id', 'unfollowed_at']],
            ['profile_page_followers', ['user_id']],
            ['profile_page_admins',    ['page_id', 'user_id']],
        ];
        foreach ($wanted as $w) dz_profile_add_index($q, $w[0], $w[1]);

        return true;
    }
}