<?php
/* ================================================================
   dz_local_content.php — "Show local content" helpers   (v2)
   The person's place is the "Current location" they wrote in About me.
   The switch itself is saved on algorithm_settings.php as dz_user_algo_settings.local_content (0 / 1).

   v2 changes:
     • every function is wrapped in function_exists (no "cannot redeclare" fatal if the file is loaded twice)
     • more filler words are ignored, so "South Africa" no longer matches "South Korea" and
       "United States" no longer matches "United Kingdom"
     • an empty "no table found" result is no longer remembered for the whole session (it is retried after 10 minutes)

   Use in the feed ranking (dz_rank.php):
       if (dz_local_is_on($conn, $viewer_id)) {
           $boost = dz_local_boost_map($conn, $viewer_id, $poster_ids);   // [poster_id => 0 .. 1]
       }
   ================================================================ */

/* the About me "Current location" text (table found by its current_location column, same as profile.php) */
if (!function_exists('dz_about_location')) {
    function dz_about_location($conn, $uid) {
        static $tbl = null;
        $uid = (int)$uid;
        if ($uid <= 0 || !$conn) return '';
        try {
            if ($tbl === null && !empty($_SESSION['dz_tbl_loc'])) $tbl = (string)$_SESSION['dz_tbl_loc'];
            if ($tbl === null && isset($_SESSION['dz_tbl_loc_miss']) && (time() - (int)$_SESSION['dz_tbl_loc_miss']) < 600) return '';   /* looked recently, nothing there */
            if ($tbl === null) {
                $tbl = '';
                $q = $conn->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
                                   JOIN information_schema.COLUMNS u ON u.TABLE_SCHEMA = c.TABLE_SCHEMA AND u.TABLE_NAME = c.TABLE_NAME AND u.COLUMN_NAME = 'user_id'
                                   WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'current_location' LIMIT 1");
                if ($q instanceof mysqli_result && ($qr = $q->fetch_assoc())) $tbl = (string)$qr['TABLE_NAME'];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    if ($tbl !== '') { $_SESSION['dz_tbl_loc'] = $tbl; unset($_SESSION['dz_tbl_loc_miss']); }
                    else { unset($_SESSION['dz_tbl_loc']); $_SESSION['dz_tbl_loc_miss'] = time(); }
                }
            }
            if ($tbl === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tbl)) { $tbl = null; return ''; }
            $r = $conn->query("SELECT current_location FROM `$tbl` WHERE user_id = $uid LIMIT 1");
            if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) return trim((string)($row['current_location'] ?? ''));
        } catch (Throwable $e) {}
        return '';
    }
}

/* adds dz_user_algo_settings.local_content once (a session flag stops it checking on every page) */
if (!function_exists('dz_local_ensure_column')) {
    function dz_local_ensure_column($conn) {
        if (!empty($_SESSION['dz_local_col'])) return;
        try {
            $r = $conn->query("SHOW COLUMNS FROM `dz_user_algo_settings` LIKE 'local_content'");
            if ($r instanceof mysqli_result && $r->num_rows === 0) $conn->query("ALTER TABLE `dz_user_algo_settings` ADD COLUMN local_content TINYINT(1) NOT NULL DEFAULT 0");
            $r = $conn->query("SHOW COLUMNS FROM `dz_user_algo_settings` LIKE 'local_content'");
            if ($r instanceof mysqli_result && $r->num_rows > 0) $_SESSION['dz_local_col'] = 1;
        } catch (Throwable $e) {}
    }
}

if (!function_exists('dz_local_is_on')) {
    function dz_local_is_on($conn, $uid) {
        $uid = (int)$uid;
        if ($uid <= 0) return false;
        try {
            $r = $conn->query("SELECT local_content FROM dz_user_algo_settings WHERE user_id = $uid LIMIT 1");
            if ($r instanceof mysqli_result && ($row = $r->fetch_assoc())) return !empty($row['local_content']);
        } catch (Throwable $e) {}
        return false;
    }
}

/* "Lagos, Nigeria" → ['lagos','nigeria'] (lower-case, accents removed, small filler words dropped) */
if (!function_exists('dz_loc_tokens')) {
    function dz_loc_tokens($s) {
        $s = mb_strtolower((string)$s, 'UTF-8');
        if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t !== false && $t !== '') $s = $t; }
        $s = preg_replace('/[^a-z0-9, ]+/', ' ', $s);
        /* words that appear in many different places, so sharing them says nothing about being in the same place */
        static $stop = ['the','of','and','state','city','province','region','county','district','area','in','at',
                        'south','north','east','west','new','united','republic','kingdom','states','saint','island','islands',
                        'central','upper','lower','greater','federal','capital','territory'];
        $out = [];
        foreach (preg_split('/[\s,]+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (strlen($w) < 3 || in_array($w, $stop, true)) continue;
            $out[$w] = true;
        }
        return array_keys($out);
    }
}

/* 1.0 same city / first place named, 0.5 only the wider place matches (same country…), 0 nothing in common */
if (!function_exists('dz_local_score')) {
    function dz_local_score($viewerLoc, $posterLoc) {
        $a = dz_loc_tokens($viewerLoc); $b = dz_loc_tokens($posterLoc);
        if (!$a || !$b) return 0.0;
        $firstA = dz_loc_tokens(explode(',', (string)$viewerLoc)[0]);
        $firstB = dz_loc_tokens(explode(',', (string)$posterLoc)[0]);
        if ($firstA && $firstB && array_intersect($firstA, $firstB)) return 1.0;
        return array_intersect($a, $b) ? 0.5 : 0.0;
    }
}

/* [poster_id => 0 .. 1] for a list of posters, against the viewer's own About me location */
if (!function_exists('dz_local_boost_map')) {
    function dz_local_boost_map($conn, $viewer_id, array $poster_ids) {
        $out = [];
        $mine = dz_about_location($conn, $viewer_id);
        if ($mine === '') return $out;
        foreach (array_unique(array_map('intval', $poster_ids)) as $pid) {
            if ($pid <= 0) continue;
            $out[$pid] = ($pid === (int)$viewer_id) ? 0.0 : dz_local_score($mine, dz_about_location($conn, $pid));
        }
        return $out;
    }
}