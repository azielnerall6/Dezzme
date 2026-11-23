<?php
/* ══════════════════════════════════════════════════════════════
   word_filters.php  –  "Word filters" (Settings → Word filters)
   Each person keeps two separate lists of words they do not want in their feed:
     'post' = normal meme posts      'ad' = meme ads
   A post or ad whose caption (ads: also the button text) contains one of the words never
   reaches that person's feed. Used by shared_core.php and settings.php.

   Matching: whole words, not case sensitive, phrases allowed ("good morning").
             "politic*" matches politics, political… ; "*gram" matches instagram…
             "#gym" matches the hashtag.
══════════════════════════════════════════════════════════════ */
const DZ_WF_MAX_WORDS = 100;     // per list
const DZ_WF_MAX_LEN   = 60;

if (!function_exists('dz_wf_table')) {
    function dz_wf_table($conn): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS user_word_filters (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                kind ENUM('post','ad','comment') NOT NULL,
                word VARCHAR(80) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_kind_word (user_id, kind, word),
                KEY k_user (user_id, kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) { error_log('word filters table: ' . $e->getMessage()); }
        /* tables made before comment filters existed only know 'post' and 'ad': widen the column once */
        try {
            $c = $conn->query("SHOW COLUMNS FROM user_word_filters LIKE 'kind'");
            $row = $c ? $c->fetch_assoc() : null;
            if ($row && stripos((string)$row['Type'], 'comment') === false)
                $conn->query("ALTER TABLE user_word_filters MODIFY kind ENUM('post','ad','comment') NOT NULL");
        } catch (Throwable $e) { error_log('word filters enum: ' . $e->getMessage()); }
    }
}

/* the person's lists: ['post' => [...], 'ad' => [...]] (cached per request) */
if (!function_exists('dz_wf_get')) {
    function dz_wf_get($conn, int $uid): array {
        static $cache = [];
        if (isset($cache[$uid])) return $cache[$uid];
        $out = ['post' => [], 'ad' => [], 'comment' => []];
        if ($uid > 0) {
            dz_wf_table($conn);
            try {
                $r = $conn->query("SELECT kind, word FROM user_word_filters WHERE user_id = " . (int)$uid . " ORDER BY id ASC");
                while ($r && ($row = $r->fetch_assoc())) $out[$row['kind']][] = $row['word'];
            } catch (Throwable $e) { error_log('word filters read: ' . $e->getMessage()); }
        }
        return $cache[$uid] = $out;
    }
}

/* one regex for a list of words, or '' when the list is empty */
if (!function_exists('dz_wf_regex')) {
    function dz_wf_regex(array $words): string {
        $parts = [];
        foreach ($words as $w) {
            $w = trim((string)$w);
            if ($w === '') continue;
            $pre = ''; $suf = '';
            if (substr($w, -1) === '*') { $w = rtrim($w, '*'); $suf = ''; } else { $suf = '(?![\p{L}\p{N}_])'; }
            if (substr($w, 0, 1) === '*') { $w = ltrim($w, '*'); $pre = ''; } else { $pre = '(?<![\p{L}\p{N}_])'; }
            if ($w === '') continue;
            $q = preg_quote($w, '/');
            $q = preg_replace('/\s+/u', '\s+', $q);      // any run of spaces matches a phrase
            $parts[] = $pre . $q . $suf;
        }
        return $parts ? '/(?:' . implode('|', $parts) . ')/iu' : '';
    }
}

/* drop every row that contains a filtered word. $fields = row keys to read. */
if (!function_exists('dz_wf_filter_rows')) {
    function dz_wf_filter_rows(array $rows, array $words, array $fields): array {
        $rx = dz_wf_regex($words);
        if ($rx === '' || !$rows) return $rows;
        $keep = [];
        foreach ($rows as $r) {
            $text = '';
            foreach ($fields as $f) if (!empty($r[$f]) && is_string($r[$f])) $text .= ' ' . $r[$f];
            if ($text === '' || !@preg_match($rx, $text)) $keep[] = $r;
        }
        return $keep;
    }
}

/* clean what a person typed into a list of words */
if (!function_exists('dz_wf_parse')) {
    function dz_wf_parse(string $raw): array {
        $out = [];
        foreach (preg_split('/[,\n\r;]+/u', $raw) as $w) {
            $w = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$w);
            $w = trim(preg_replace('/\s+/u', ' ', $w));
            if ($w === '' || trim($w, '*') === '') continue;
            $w = mb_strtolower($w, 'UTF-8');
            if (mb_strlen($w, 'UTF-8') > DZ_WF_MAX_LEN) $w = mb_substr($w, 0, DZ_WF_MAX_LEN, 'UTF-8');
            $out[$w] = true;
        }
        return array_keys($out);
    }
}