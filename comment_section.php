<?php
/* ═══════════════════════════════════════════════════════════════
   comment_section.php  –  ONE file: comment UI + comment API
   Use on any page:
       1) a button with attribute data-comments="MEME_ID"
       2) include 'comment_section.php'; once at the bottom of the page (after $nonce is set)
   When this file is opened directly by the browser's fetch() it acts as the API.

   v7: Facebook-style reactions
       • hold a comment  → reaction bar + the comment + menu (blurred background)
       • the reactions show at the bottom right of the comment (top 3 + total)
       • tap them        → bottom sheet with everybody who reacted (filter by emoji)
   A table "comment_reactions" is created automatically the first time it is needed.

   v8: Save comment (the bookmark icon in the header lists your saved comments) · Pin comment (post owner) ·
       🥇 First badge · Translate all comments (in the sort menu).
       Tables created automatically: comment_saves, comment_pins.
       From any other page (e.g. Settings) you can open the saved list with:  CommentSection.openSaved()
   The red heart is still stored in your existing comment_likes table, so old likes are kept.
═══════════════════════════════════════════════════════════════ */

if (defined('CS_LOADED')) { return; }   // already included on this page: skip everything (no duplicate sheet, no redeclared functions)
define('CS_LOADED', true);

/* ================= CONFIG – edit these ================= */
// Everything lives in one flat folder, so the URLs are worked out automatically.
$CS_BASE  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$CS_URL   = $CS_BASE . '/comment_section.php';
$CS_LOGIN = $CS_BASE . '/login.php';           // where guests are sent (change if your login page has another name)
$CS = [
    'config'      => __DIR__ . '/config.php',    // your config.php (defines $conn, mysqli)
    'users_table' => 'users',
    'u_name'      => 'username',
    'u_avatar'    => 'profile_pic',              // stored like "mee.jpg"
    'max_text'    => 1000,
    'max_img'     => 5 * 1024 * 1024,            // 5 MB
    'page'        => 15,
    'replies'     => 10,
];
/* ======================================================= */

/* ---------------- API helpers (cs_ prefix = no clashes with your pages) ---------------- */
if (!function_exists('cs_out')) {   // functions are only created once, even if this file is loaded twice

function cs_out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; }
function cs_fail($m, $code = 400) { cs_out(['error' => $m], $code); }
function cs_uid() { return (int)($_SESSION['user_id'] ?? 0); }
function cs_need_login() { if (!cs_uid()) cs_fail('Please log in', 401); }
function cs_need_csrf() {
    $t = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (empty($_SESSION['cs_csrf']) || !hash_equals($_SESSION['cs_csrf'], $t)) cs_fail('Bad token', 403);
}
function cs_ago($ts) {
    $d = max(0, time() - strtotime($ts));
    foreach ([['w', 604800], ['d', 86400], ['h', 3600], ['m', 60]] as $x) if ($d >= $x[1]) return intdiv($d, $x[1]) . $x[0];
    return 'now';
}
function cs_avatar($a) {
    if (!$a) $a = 'mee.jpg';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return preg_match('#^(https?:)?//|^/#', $a) ? $a : $base . '/' . $a;
}
function cs_run($sql, $types = '', $p = []) {
    global $conn;
    $st = $conn->prepare($sql);
    if ($types !== '') $st->bind_param($types, ...$p);
    $st->execute();
    return $st;
}
function cs_rows($sql, $types = '', $p = []) {
    $st = cs_run($sql, $types, $p);
    $r = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $r;
}
function cs_one($sql, $types = '', $p = []) { $r = cs_rows($sql, $types, $p); return $r[0] ?? null; }

/* ── "Search · …" topic of a post: the caption, hashtags, audio title, category and the top comments vote for words / word pairs,
      then every candidate is checked against the other posts so the row only suggests what really returns results. Cached 6 hours per post. ── */
function cs_topic($memeId, $caption) {
    $memeId = (int)$memeId; if ($memeId <= 0) return '';
    try { $GLOBALS['conn']->query("CREATE TABLE IF NOT EXISTS post_topic (meme_id INT NOT NULL PRIMARY KEY, topic VARCHAR(120) NOT NULL, made_at DATETIME NOT NULL)"); } catch (Throwable $e) {}
    try { $c = cs_one('SELECT topic FROM post_topic WHERE meme_id = ? AND made_at > (NOW() - INTERVAL 6 HOUR)', 'i', [$memeId]); if ($c) return (string)$c['topic']; } catch (Throwable $e) {}
    static $stop = null;
    if ($stop === null) $stop = array_flip(explode(' ', 'the and for with this that have from your you are was were but not just they them what when where which will would could should about into over than then there their been being also more most some very like our out all any can had has its how who why get got too one two her his she him now see saw look looks looked let lets going gone made make makes want wants need needs know says said say really still even much many such only come came back after before again here these those because while every another other thing things something someone people guys guy yall omg lol lmao wow new today day post video pic picture photo check link follow tag tracks love great good nice best cool haha hahaha funny please thanks thank yes yeah yep nope bro dude man girl lmfao fr tbh imo'));
    $sc = []; $first = [];
    $add = function ($w, $pts, $pos = 0) use (&$sc, &$first, $stop) {
        $w = mb_strtolower(trim($w), 'UTF-8'); if ($w === '' || isset($stop[$w]) || mb_strlen($w, 'UTF-8') < 3 || ctype_digit($w)) return;
        $sc[$w] = ($sc[$w] ?? 0) + $pts + min(mb_strlen($w, 'UTF-8'), 9) / 12; if (!isset($first[$w])) $first[$w] = $pos;
    };
    $words = function ($t) { preg_match_all("/[\\p{L}][\\p{L}\\p{N}']{2,}/u", preg_replace(['~https?://\\S+~', '~[@#]\\w+~u'], ' ', (string)$t), $m); return array_map(function ($w) { return preg_replace("/'s$/u", '', mb_strtolower($w, 'UTF-8')); }, $m[0]); };
    $caption = (string)$caption;
    /* hashtags: strongest signal; CamelCase and snake_case are split into words */
    $tagWords = [];
    if (preg_match_all('/#([\\p{L}\\p{N}_]+)/u', $caption, $m)) foreach ($m[1] as $h) { $h = str_replace('_', ' ', preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $h)); foreach (preg_split('/\\s+/', trim($h)) as $w) { $add($w, 5, 0); $tagWords[] = mb_strtolower($w, 'UTF-8'); } }
    $cw = $words($caption); foreach ($cw as $i => $w) $add($w, 3, $i + 1);
    /* caption word pairs ("older woman", "beach day") */
    $pairs = []; for ($i = 0; $i + 1 < count($cw); $i++) { if (!isset($stop[$cw[$i]]) && !isset($stop[$cw[$i + 1]]) && strlen($cw[$i]) > 2 && strlen($cw[$i + 1]) > 2) $pairs[] = $cw[$i] . ' ' . $cw[$i + 1]; }
    /* audio title, category, top comments */
    try { $a = cs_one('SELECT a.title FROM meme_audio ma JOIN audios a ON a.id = ma.audio_id WHERE ma.meme_id = ? AND (ma.is_original IS NULL OR ma.is_original = 0) LIMIT 1', 'i', [$memeId]); if ($a) foreach ($words($a['title']) as $w) $add($w, 1.2, 40); } catch (Throwable $e) {}
    try { $a = cs_one('SELECT category FROM memes WHERE id = ?', 'i', [$memeId]); if ($a && !empty($a['category'])) foreach ($words($a['category']) as $w) $add($w, 2, 30); } catch (Throwable $e) {}
    try { foreach (cs_rows('SELECT text FROM comments WHERE meme_id = ? AND parent_id IS NULL ORDER BY id DESC LIMIT 40', 'i', [$memeId]) as $r) foreach ($words($r['text']) as $w) if (isset($sc[$w]) || count($sc) < 4) $add($w, 0.6, 60); } catch (Throwable $e) {}
    if (!$sc) return '';
    arsort($sc);
    $cands = array_slice(array_keys($sc), 0, 8);
    /* how many OTHER posts mention it: a phrase nobody else has is not worth a search */
    $hits = function ($t) use ($memeId) {
        try { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t) . '%'; $r = cs_one('SELECT COUNT(*) AS n FROM (SELECT id FROM memes WHERE id <> ? AND caption LIKE ? LIMIT 25) x', 'is', [$memeId, $like]); return (int)($r['n'] ?? 0); } catch (Throwable $e) { return 0; }
    };
    $best = []; foreach ($cands as $w) { $n = $hits($w); $best[$w] = $sc[$w] * (1 + log(1 + $n)) * ($n ? 1 : .35); }
    foreach (array_slice(array_unique($pairs), 0, 5) as $p) { $n = $hits($p); if ($n) $best[$p] = (($sc[explode(' ', $p)[0]] ?? 1) + ($sc[explode(' ', $p)[1]] ?? 1)) * .8 * (1 + log(1 + $n)); }
    arsort($best); $keys = array_keys($best); $top = $best[$keys[0]];
    /* one word, two, three or four: every strong word of the post that other posts also use joins the phrase (kept in reading order) */
    $pick = [$keys[0]];
    foreach (array_slice($keys, 1, 8) as $k) {
        if (count($pick) >= 4) break;
        if ($best[$k] < $top * .38) break;
        $dup = false; foreach ($pick as $p) { if (strpos(' ' . $p . ' ', ' ' . $k . ' ') !== false || strpos(' ' . $k . ' ', ' ' . $p . ' ') !== false) $dup = true; }
        if ($dup) continue;
        if (strpos($k, ' ') === false && !$hits($k) && !isset($sc[$k])) continue;
        $pick[] = $k;
    }
    usort($pick, function ($x, $y) use ($first) { $fx = $first[explode(' ', $x)[0]] ?? 99; $fy = $first[explode(' ', $y)[0]] ?? 99; return $fx <=> $fy; });
    $topic = implode(' ', $pick);
    $seenW = []; $topic = implode(' ', array_filter(explode(' ', $topic), function ($w) use (&$seenW) { if (isset($seenW[$w])) return false; $seenW[$w] = 1; return true; }));
    $topic = mb_substr(trim($topic), 0, 60, 'UTF-8');
    try { $st = $GLOBALS['conn']->prepare('REPLACE INTO post_topic (meme_id, topic, made_at) VALUES (?, ?, NOW())'); if ($st) { $st->bind_param('is', $memeId, $topic); $st->execute(); $st->close(); } } catch (Throwable $e) {}
    return $topic;
}
function cs_meme_owner($memeId) {
    $r = cs_one('SELECT uploaded_by FROM memes WHERE id = ?', 'i', [$memeId]);
    if (!$r) cs_fail('Meme not found', 404);
    return (int)$r['uploaded_by'];
}
/* the poster turned comments off for this post (memes.comments_off = 1, set in Upload / Edit post) */
function cs_comments_off($memeId) {
    try { $r = cs_one('SELECT comments_off FROM memes WHERE id = ?', 'i', [(int)$memeId]); return $r && (int)$r['comments_off'] === 1; }
    catch (Throwable $e) { return false; }   // column not created yet = nobody has turned comments off
}
/* one-time schema checks: remembered in a tiny temp file so every request does not re-run CREATE TABLE / information_schema lookups (slow on XAMPP) */
function cs_marker($key) { return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cs_' . md5(__DIR__ . '|' . $key) . '.mark'; }
function cs_cached($key, $ttl = 86400) { $f = cs_marker($key); return (@is_file($f) && (time() - (int)@filemtime($f)) < $ttl) ? (string)@file_get_contents($f) : null; }
function cs_mark($key, $val = '1') { @file_put_contents(cs_marker($key), $val); }
function cs_col_exists($table, $col) {
    $r = cs_one("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", 'ss', [$table, $col]);
    return (int)($r['n'] ?? 0) > 0;
}

/* ── Block list. Uses a table you already have (blocks / user_blocks / blocked_users) when one matches,
      otherwise creates cs_user_blocks the first time somebody blocks someone. Returns [table, blocker_col, blocked_col] or null. ── */
function cs_block_map($create = false) {
    global $conn;
    static $found = false;                      // false = not looked up yet
    if ($found === false && ($c = cs_cached('blockmap')) !== null) { $found = ($c === 'none') ? null : (json_decode($c, true) ?: null); }
    if ($found === false) {
        $found = null;
        try {
            $pairs = [['blocker_id', 'blocked_id'], ['user_id', 'blocked_user_id'], ['blocker_id', 'blocked_user_id'], ['user_id', 'blocked_id']];
            foreach (['blocks', 'user_blocks', 'blocked_users'] as $t) {
                foreach ($pairs as $p) {
                    if (cs_col_exists($t, $p[0]) && cs_col_exists($t, $p[1])) { $found = [$t, $p[0], $p[1]]; break 2; }
                }
            }
        } catch (Throwable $e) { $found = null; }
        cs_mark('blockmap', $found ? json_encode($found) : 'none');
    }
    if ($found === null && $create) {
        $conn->query("CREATE TABLE IF NOT EXISTS cs_user_blocks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            blocker_id INT UNSIGNED NOT NULL,
            blocked_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cs_block (blocker_id, blocked_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $found = ['cs_user_blocks', 'blocker_id', 'blocked_id'];
        cs_mark('blockmap', json_encode($found));
    }
    return $found;
}
function cs_blocked_ids($me) {
    static $cache = [];
    $me = (int)$me;
    if (!$me) return [];
    if (isset($cache[$me])) return $cache[$me];
    $cache[$me] = [];
    try {
        $m = cs_block_map(false);
        if ($m) foreach (cs_rows("SELECT `{$m[2]}` AS b FROM `{$m[0]}` WHERE `{$m[1]}` = ?", 'i', [$me]) as $r) $cache[$me][] = (int)$r['b'];
    } catch (Throwable $e) { /* no block list yet */ }
    return $cache[$me];
}

/* Word filters (Settings → Word filters): comments that contain a word the POST OWNER put in Settings → Word filters → Comments stay hidden from everyone
   except the person who wrote them. Replies under a hidden comment are hidden with it. Returns [comment_id => parent_id]. */
function cs_hidden($memeId) {
    global $conn;
    static $cache = [];
    $memeId = (int)$memeId;
    if (isset($cache[$memeId])) return $cache[$memeId];
    $cache[$memeId] = [];
    try {
        if (!function_exists('dz_wf_get') && is_file(__DIR__ . '/word_filters.php')) require_once __DIR__ . '/word_filters.php';
        if (!function_exists('dz_wf_get') || !function_exists('dz_wf_regex')) return [];
        $r = cs_one('SELECT uploaded_by FROM memes WHERE id = ?', 'i', [$memeId]);
        if (!$r) return [];
        $lists = dz_wf_get($conn, (int)$r['uploaded_by']);
        $rx = dz_wf_regex($lists['comment'] ?? []);
        if ($rx === '') return [];
        $me = cs_uid();
        $rows = cs_rows('SELECT id, parent_id, user_id, text FROM comments WHERE meme_id = ? ORDER BY id ASC LIMIT 20000', 'i', [$memeId]);
        $hit = [];
        foreach ($rows as $c) {
            $parent = $c['parent_id'] ? (int)$c['parent_id'] : 0;
            $bad = ($c['text'] !== '' && $c['text'] !== null && @preg_match($rx, (string)$c['text'])) || ($parent && isset($hit[$parent]));
            if ($bad && !($me && (int)$c['user_id'] === $me)) $hit[(int)$c['id']] = $parent;
        }
        $cache[$memeId] = $hit;
    } catch (Throwable $e) { error_log('comment word filter: ' . $e->getMessage()); }
    return $cache[$memeId];
}
/* Hide / "Mark as spam" are saved per account (so they follow you to every device). One row per user + comment. */
function cs_hid_table() {
    global $conn;
    static $done = false;
    if ($done) return;
    $done = true;
    if (cs_cached('tbl_hid') !== null) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS comment_user_hidden (
            user_id INT UNSIGNED NOT NULL,
            comment_id INT UNSIGNED NOT NULL,
            kind CHAR(1) NOT NULL DEFAULT 'h',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, comment_id),
            KEY idx_comment (comment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        cs_mark('tbl_hid');
    } catch (Throwable $e) {}
}

/* ═══ Reactions ═══
   The 8 reactions, in bar order (the same emojis as the comment box):  like = ❤️ · pray = 🙌 · fire = 🔥 · clap = 👏 · cry = 😢 · love = 😍 · wow = 😮 · haha = 😂
   "like" (the red heart) lives in your old comment_likes table; every other reaction lives in comment_reactions.
   One person = one reaction per comment. */
function cs_reacts() { return ['like', 'pray', 'fire', 'clap', 'cry', 'love', 'wow', 'haha']; }
function cs_react_table() {
    global $conn;
    static $done = false;
    if ($done) return;
    $done = true;
    if (cs_cached('tbl_react') !== null) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS comment_reactions (
            comment_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            reaction VARCHAR(16) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (comment_id, user_id),
            KEY idx_cr_comment (comment_id, reaction),
            KEY idx_cr_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        cs_mark('tbl_react');
    } catch (Throwable $e) { error_log('comment_reactions table: ' . $e->getMessage()); }
}
/* counts per reaction, my reaction, and whether the post owner reacted — for a list of comment ids */
function cs_react_info($ids, $owner) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    $out = [];
    foreach ($ids as $i) $out[$i] = ['counts' => [], 'mine' => null, 'author' => false];
    if (!$ids) return $out;
    cs_react_table();
    $in = implode(',', $ids);
    $me = cs_uid(); $owner = (int)$owner;
    foreach (cs_rows("SELECT comment_id, COUNT(*) AS n FROM comment_likes WHERE comment_id IN ($in) GROUP BY comment_id") as $r)
        $out[(int)$r['comment_id']]['counts']['like'] = (int)$r['n'];
    foreach (cs_rows("SELECT comment_id, reaction, COUNT(*) AS n FROM comment_reactions WHERE comment_id IN ($in) GROUP BY comment_id, reaction") as $r)
        $out[(int)$r['comment_id']]['counts'][$r['reaction']] = (int)$r['n'];
    if ($me) {
        foreach (cs_rows("SELECT comment_id FROM comment_likes WHERE user_id = $me AND comment_id IN ($in)") as $r)
            $out[(int)$r['comment_id']]['mine'] = 'like';
        foreach (cs_rows("SELECT comment_id, reaction FROM comment_reactions WHERE user_id = $me AND comment_id IN ($in)") as $r)
            $out[(int)$r['comment_id']]['mine'] = $r['reaction'];
    }
    if ($owner) {
        foreach (cs_rows("SELECT comment_id FROM comment_likes WHERE user_id = $owner AND comment_id IN ($in)") as $r)
            $out[(int)$r['comment_id']]['author'] = true;
        foreach (cs_rows("SELECT comment_id FROM comment_reactions WHERE user_id = $owner AND comment_id IN ($in)") as $r)
            $out[(int)$r['comment_id']]['author'] = true;
    }
    return $out;
}
/* what the browser gets for one comment: total, my reaction, and the top 3 reactions (most used first) */
function cs_pack($inf) {
    $order = array_flip(cs_reacts());
    $c = $inf['counts'];
    uksort($c, function ($a, $b) use ($c, $order) {
        $d = $c[$b] <=> $c[$a];
        return $d !== 0 ? $d : (($order[$a] ?? 99) <=> ($order[$b] ?? 99));
    });
    return [
        'likes' => array_sum($c), 'liked' => $inf['mine'] !== null, 'my_react' => $inf['mine'],
        'reacts' => array_slice(array_keys($c), 0, 3), 'react_counts' => $c,
    ];
}

/* ═══ Save · Pin · First comment ═══
   comment_saves : one row per (user, comment) = the private "Saved comments" list
   comment_pins  : one row per post = the comment the post owner pinned to the top
   First comment = the oldest top-level comment on a post. */
function cs_save_table() {
    global $conn;
    static $done = false;
    if ($done) return;
    $done = true;
    if (cs_cached('tbl_save') !== null) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS comment_saves (
            user_id INT UNSIGNED NOT NULL,
            comment_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, comment_id),
            KEY idx_cs_saved_comment (comment_id),
            KEY idx_cs_saved_when (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        cs_mark('tbl_save');
    } catch (Throwable $e) { error_log('comment_saves table: ' . $e->getMessage()); }
}
function cs_pin_table() {
    global $conn;
    static $done = false;
    if ($done) return;
    $done = true;
    if (cs_cached('tbl_pin') !== null) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS comment_pins (
            meme_id INT UNSIGNED NOT NULL PRIMARY KEY,
            comment_id INT UNSIGNED NOT NULL,
            pinned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cp_comment (comment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        cs_mark('tbl_pin');
    } catch (Throwable $e) { error_log('comment_pins table: ' . $e->getMessage()); }
}
function cs_pinned_id($memeId) {                 // the pinned comment of a post (0 = none)
    static $cache = [];
    $memeId = (int)$memeId;
    if (!$memeId) return 0;
    if (isset($cache[$memeId])) return $cache[$memeId];
    $cache[$memeId] = 0;
    try {
        cs_pin_table();
        $r = cs_one('SELECT comment_id FROM comment_pins WHERE meme_id = ?', 'i', [$memeId]);
        if ($r) $cache[$memeId] = (int)$r['comment_id'];
    } catch (Throwable $e) {}
    return $cache[$memeId];
}
function cs_first_id($memeId) {                  // the first (oldest) top-level comment of a post
    static $cache = [];
    $memeId = (int)$memeId;
    if (!$memeId) return 0;
    if (!isset($cache[$memeId])) {
        $cache[$memeId] = 0;
        try { $r = cs_one('SELECT MIN(id) AS m FROM comments WHERE meme_id = ? AND parent_id IS NULL', 'i', [$memeId]); $cache[$memeId] = (int)($r['m'] ?? 0); } catch (Throwable $e) {}
    }
    return $cache[$memeId];
}
function cs_saved_set($ids) {                    // [comment_id => true] for the comments I saved
    $me = cs_uid();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    if (!$me || !$ids) return [];
    cs_save_table();
    $set = [];
    try {
        foreach (cs_rows("SELECT comment_id FROM comment_saves WHERE user_id = $me AND comment_id IN (" . implode(',', $ids) . ")") as $r) $set[(int)$r['comment_id']] = true;
    } catch (Throwable $e) {}
    return $set;
}
function cs_media($r, $base) {
    if (empty($r['media_url'])) return null;
    if ($r['media_type'] === 'image')   return ['type' => 'image',   'url' => $base . '/uploads/comments/' . $r['media_url']];
    if ($r['media_type'] === 'sticker') return ['type' => 'sticker', 'url' => $base . '/uploads/stickers/' . $r['media_url']];
    if ($r['media_type'] === 'gif')     return ['type' => 'gif',     'url' => $r['media_url']];
    return null;
}

function cs_fetch($where, $ints, $order, $limit, $offset, $owner, $hideMeme = 0) {   // $where uses "?" for integers only
    global $CS;
    cs_hid_table();
    cs_react_table();
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $me = cs_uid();
    $hidden = $hideMeme ? cs_hidden($hideMeme) : [];
    $extra = '';
    if ($hidden) $extra .= ' AND c.id NOT IN (' . implode(',', array_map('intval', array_keys($hidden))) . ')';
    $blocked = cs_blocked_ids($me);
    if ($blocked) $extra .= ' AND c.user_id NOT IN (' . implode(',', array_map('intval', $blocked)) . ')';
    $sql = "SELECT c.*, u.{$CS['u_name']} AS uname, u.{$CS['u_avatar']} AS uavatar, COALESCE(u.is_verified,0) AS uverified,
        ((SELECT COUNT(*) FROM comment_likes l WHERE l.comment_id = c.id) + (SELECT COUNT(*) FROM comment_reactions x WHERE x.comment_id = c.id)) AS likes,
        (SELECT COUNT(*) FROM followers f WHERE f.follower_id = ? AND f.following_id = c.user_id) AS following,
        (SELECT COUNT(*) FROM comments r WHERE r.parent_id = c.id) AS reply_count,
        TIMESTAMPDIFF(SECOND, c.created_at, NOW()) AS age_s,
        (SELECT h.kind FROM comment_user_hidden h WHERE h.comment_id = c.id AND h.user_id = ? LIMIT 1) AS my_hid,
        (SELECT u2.{$CS['u_avatar']} FROM comments r2 JOIN {$CS['users_table']} u2 ON u2.id = r2.user_id WHERE r2.parent_id = c.id ORDER BY (r2.user_id = ?) DESC, r2.id ASC LIMIT 1) AS reply_avatar
        FROM comments c JOIN {$CS['users_table']} u ON u.id = c.user_id
        WHERE ($where)$extra ORDER BY $order LIMIT ? OFFSET ?";
    $params = array_merge([$me, $me, $owner], $ints, [$limit, $offset]);
    $out = [];
    $ownAv = null;   // the post owner's picture, looked up once (used for the "liked by author" badge)
    $dbRows = cs_rows($sql, str_repeat('i', count($params)), $params);
    $info = cs_react_info(array_column($dbRows, 'id'), $owner);
    $saved = cs_saved_set(array_column($dbRows, 'id'));
    $pinId = $hideMeme ? cs_pinned_id($hideMeme) : 0;
    foreach ($dbRows as $r) {
        $inf = $info[(int)$r['id']];
        $pk = cs_pack($inf);
        $media = null;
        if ($r['media_url']) {
            if ($r['media_type'] === 'image')   $media = ['type' => 'image',   'url' => $base . '/uploads/comments/' . $r['media_url']];
            if ($r['media_type'] === 'sticker') $media = ['type' => 'sticker', 'url' => $base . '/uploads/stickers/' . $r['media_url']];
            if ($r['media_type'] === 'gif')     $media = ['type' => 'gif',     'url' => $r['media_url']];
        }
        $aa = null;
        if ($inf['author']) {
            if ($ownAv === null) {
                $o = cs_one("SELECT {$CS['u_avatar']} AS a FROM {$CS['users_table']} WHERE id = ?", 'i', [$owner]);
                $ownAv = cs_avatar($o['a'] ?? null);
            }
            $aa = $ownAv;
        }
        $rc = max(0, (int)$r['reply_count'] - ($hidden ? count(array_keys($hidden, (int)$r['id'], true)) : 0));
        $out[] = [
            'author_avatar' => $aa,
            'id' => (int)$r['id'], 'parent_id' => $r['parent_id'] ? (int)$r['parent_id'] : null,
            'user_id' => (int)$r['user_id'], 'username' => $r['uname'], 'is_verified' => !empty($r['uverified']), 'avatar' => cs_avatar($r['uavatar']),
            'text' => $r['text'], 'media' => $media, 'ago' => cs_ago($r['created_at']),
            'likes' => $pk['likes'], 'liked' => $pk['liked'],
            'my_react' => $pk['my_react'], 'reacts' => $pk['reacts'], 'react_counts' => $pk['react_counts'],
            'author_liked' => $inf['author'], 'is_author' => (int)$r['user_id'] === $owner,
            'following' => (bool)$r['following'],
            'reply_count' => $rc,
            /* the small profile pic on the "N replies" row: the post owner's if they replied, otherwise the first replier's */
            'reply_avatar' => $rc > 0 ? cs_avatar($r['reply_avatar']) : null,
            'can_delete' => $me && ($me === (int)$r['user_id'] || $me === $owner),
            /* seconds left in which the author can still edit (1 minute after posting); 0 = no Edit button */
            'edit_left' => ($me && $me === (int)$r['user_id'] && (string)$r['text'] !== '') ? max(0, 60 - max(0, (int)$r['age_s'])) : 0,
            'edited' => !empty($r['edited_at']),
            'my_hid' => $r['my_hid'] ?: null,                  // 'h' hidden · 's' marked as spam (by this account)
            'saved' => isset($saved[(int)$r['id']]),            // I saved this comment
            'pinned' => $pinId && (int)$r['id'] === $pinId,     // pinned to the top by the post owner
            'can_pin' => $me && $me === (int)$owner && !$r['parent_id'],   // only the post owner, only top-level comments
            'is_first' => !$r['parent_id'] && (int)$r['id'] === cs_first_id((int)$r['meme_id']),   // 🥇 first comment of the post
        ];
    }
    return $out;
}

function cs_api() {
    global $conn, $CS;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $upDir = __DIR__ . '/uploads/comments/';
    $base  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $action = $_GET['action'] ?? '';
    if (in_array($action, ['poll', 'list', 'replies', 'count', 'reactors', 'saved_list'], true)) session_write_close();   // read-only calls: release the session lock so live polling never blocks other requests

    try {
        switch ($action) {

        case 'init':
            if (empty($_SESSION['cs_csrf'])) $_SESSION['cs_csrf'] = bin2hex(random_bytes(16));
            session_write_close();                   // csrf is saved: release the session lock now so this request never holds up the page's other requests
            $me = null;
            if (cs_uid() && ($u = cs_one("SELECT id, {$CS['u_name']} AS n, {$CS['u_avatar']} AS a FROM {$CS['users_table']} WHERE id = ?", 'i', [cs_uid()])))
                $me = ['id' => (int)$u['id'], 'name' => $u['n'], 'avatar' => cs_avatar($u['a'])];
            cs_out(['csrf' => $_SESSION['cs_csrf'], 'me' => $me]);

        case 'list':
            $meme  = (int)($_GET['meme_id'] ?? 0);
            $page  = max(1, (int)($_GET['page'] ?? 1));
            $key   = (string)($_GET['sort'] ?? 'relevant');
            $orders = [
                'relevant' => '(likes + reply_count * 2) DESC, c.id DESC',     // liked + discussed first
                'newest'   => 'c.id DESC',
                'oldest'   => 'c.id ASC',
                'liked'    => 'likes DESC, c.id DESC',
                'replied'  => 'reply_count DESC, c.id DESC',
                'tracking' => 'c.id DESC',
                'top' => 'likes DESC, c.id DESC', 'new' => 'c.id DESC',          // old names still work
            ];
            $sort  = $orders[$key] ?? $orders['relevant'];
            $owner = cs_meme_owner($meme);
            $mx    = cs_one('SELECT COALESCE(MAX(id), 0) AS m FROM comments WHERE meme_id = ?', 'i', [$meme]);   // baseline for live updates
            $where = 'c.meme_id = ? AND c.parent_id IS NULL';
            $pin   = cs_pinned_id($meme);                                        // the pinned comment is shown first on page 1
            if ($pin && $key !== 'tracking') $where .= ' AND c.id <> ' . (int)$pin;
            if ($key === 'tracking') {                                           // only people the viewer tracks
                $me = cs_uid();
                $where .= ' AND c.user_id IN (SELECT following_id FROM followers WHERE follower_id = ' . (int)$me . ')';
            }
            $items = cs_fetch($where, [$meme], $sort, $CS['page'] + 1, ($page - 1) * $CS['page'], $owner, $meme);
            $out = ['items' => array_slice($items, 0, $CS['page']), 'more' => count($items) > $CS['page'], 'max_id' => (int)($mx['m'] ?? 0)];
            $out['pin'] = $pin;
            if ($page === 1 && $pin && $key !== 'tracking') {
                $pc = cs_fetch('c.id = ?', [$pin], 'c.id', 1, 0, $owner, $meme);
                if ($pc) $out['items'] = array_merge($pc, $out['items']);
            }
            if ($page === 1) {                                                   // the post header (owner, caption, date, counts) works on every page
                $me = cs_uid();
                $cnt = function ($sql, $types, $p) { try { $r = cs_one($sql, $types, $p); return (int)($r['n'] ?? 0); } catch (Throwable $e) { return 0; } };
                $pm = null;
                try { $pm = cs_one('SELECT m.caption, m.created_at, m.views, m.uploaded_by, u.username, u.nickname, u.profile_pic, COALESCE(u.is_verified,0) AS is_verified FROM memes m JOIN users u ON u.id = m.uploaded_by WHERE m.id = ?', 'i', [$meme]); } catch (Throwable $e) { $pm = null; }
                if (!$pm) { try { $pm = cs_one('SELECT m.caption, m.created_at, 0 AS views, m.uploaded_by, u.username, u.nickname, u.profile_pic, COALESCE(u.is_verified,0) AS is_verified FROM memes m JOIN users u ON u.id = m.uploaded_by WHERE m.id = ?', 'i', [$meme]); } catch (Throwable $e) { $pm = null; } }
                if ($pm) {
                    $out['caption'] = (string)($pm['caption'] ?? '');
                    try { $out['topic'] = cs_topic($meme, $out['caption']); } catch (Throwable $e) { $out['topic'] = ''; }
                    $co_pl = null;
                    try {
                        if (!function_exists('dz_collab_payload') && is_file(__DIR__ . '/dz_collab.php')) require_once __DIR__ . '/dz_collab.php';
                        if (function_exists('dz_collab_payload') && isset($GLOBALS['conn'])) { $cm_ = dz_collab_payload($GLOBALS['conn'], [(int)$meme], (int)$me); $co_pl = $cm_[(int)$meme] ?? null; }
                    } catch (Throwable $e) { $co_pl = null; }
                    $out['post'] = [
                        'collab' => $co_pl,
                        'owner_id' => (int)$pm['uploaded_by'], 'is_verified' => !empty($pm['is_verified']), 'username' => $pm['username'], 'nickname' => (string)($pm['nickname'] ?? ''),
                        'avatar' => cs_avatar($pm['profile_pic']), 'caption' => (string)($pm['caption'] ?? ''),
                        'date' => $pm['created_at'] ? date('H:i · d/m/Y', strtotime($pm['created_at'])) : '',
                        'views' => (int)($pm['views'] ?? 0), 'is_own' => $me && $me === (int)$pm['uploaded_by'],
                        'likes' => $cnt('SELECT COUNT(*) AS n FROM likes WHERE meme_id = ?', 'i', [$meme]),
                        'reposts' => $cnt('SELECT COUNT(*) AS n FROM reposts WHERE meme_id = ?', 'i', [$meme]),
                        'favs' => $cnt('SELECT COUNT(*) AS n FROM favorites WHERE meme_id = ?', 'i', [$meme]),
                        'shares' => $cnt('SELECT COUNT(*) AS n FROM shares WHERE meme_id = ?', 'i', [$meme]),
                        'comments' => max(0, $cnt('SELECT COUNT(*) AS n FROM comments WHERE meme_id = ?', 'i', [$meme]) - count(cs_hidden($meme))),
                        'liked' => $me ? $cnt('SELECT COUNT(*) AS n FROM likes WHERE meme_id = ? AND user_id = ?', 'ii', [$meme, $me]) > 0 : false,
                        'reposted' => $me ? $cnt('SELECT COUNT(*) AS n FROM reposts WHERE meme_id = ? AND user_id = ?', 'ii', [$meme, $me]) > 0 : false,
                        'faved' => $me ? $cnt('SELECT COUNT(*) AS n FROM favorites WHERE meme_id = ? AND user_id = ?', 'ii', [$meme, $me]) > 0 : false,
                        'following' => $me ? $cnt('SELECT COUNT(*) AS n FROM followers WHERE follower_id = ? AND following_id = ?', 'ii', [$me, (int)$pm['uploaded_by']]) > 0 : false,
                    ];
                }
            }
            if (cs_comments_off($meme)) { $out['items'] = []; $out['more'] = false; $out['off'] = true; }
            cs_out($out);

        case 'replies':                                 // direct replies to ANY comment (a top-level comment or another reply)
            $parent = (int)($_GET['parent_id'] ?? 0);
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $r = cs_one('SELECT meme_id FROM comments WHERE id = ?', 'i', [$parent]);
            if (!$r) cs_fail('Not found', 404);
            if (cs_comments_off((int)$r['meme_id'])) cs_out(['items' => [], 'more' => false]);
            $owner = cs_meme_owner((int)$r['meme_id']);
            $items = cs_fetch('c.parent_id = ?', [$parent], 'c.id ASC', $CS['replies'] + 1, $offset, $owner, (int)$r['meme_id']);
            cs_out(['items' => array_slice($items, 0, $CS['replies']), 'more' => count($items) > $CS['replies']]);

        case 'count':                                   // comment totals (replies included) for one or more posts: ?ids=1,2,3
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))))));
            $ids = array_slice($ids, 0, 50);
            $counts = [];
            foreach ($ids as $i) $counts[$i] = 0;
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                foreach (cs_rows("SELECT meme_id, COUNT(*) AS n FROM comments WHERE meme_id IN ($in) GROUP BY meme_id", str_repeat('i', count($ids)), $ids) as $r)
                    $counts[(int)$r['meme_id']] = (int)$r['n'];
                foreach ($ids as $i) if ($counts[$i] > 0 && ($h = cs_hidden($i))) $counts[$i] = max(0, $counts[$i] - count($h));
            }
            $off = [];                                   // posts whose poster turned comments off (Zeems shows the crossed-out icon, no number)
            if ($ids) {
                try {
                    $in2 = implode(',', array_fill(0, count($ids), '?'));
                    foreach (cs_rows("SELECT id FROM memes WHERE comments_off = 1 AND id IN ($in2)", str_repeat('i', count($ids)), $ids) as $r) $off[] = (int)$r['id'];
                } catch (Throwable $e) {}                // column not created yet = nobody has turned comments off
            }
            cs_out(['counts' => $counts, 'off' => $off]);

        case 'poll':                                    // live updates: new comments/replies, reactions, deletions, total
            $meme  = (int)($_GET['meme_id'] ?? 0);
            $since = max(0, (int)($_GET['since'] ?? 0));
            $ids   = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))))), 0, 150);
            $owner = cs_meme_owner($meme);
            $me    = cs_uid();
            $mx    = cs_one('SELECT COALESCE(MAX(id), 0) AS m FROM comments WHERE meme_id = ?', 'i', [$meme]);
            $out   = ['max_id' => (int)$mx['m'], 'new' => [], 'likes' => [], 'gone' => []];
            if ($since > 0 && $out['max_id'] > $since)
                $out['new'] = cs_fetch('c.meme_id = ? AND c.id > ?', [$meme, $since], 'c.id ASC', 50, 0, $owner, $meme);
            $out['next'] = $out['new'] ? (int)end($out['new'])['id'] : $out['max_id'];
            $out['pin'] = cs_pinned_id($meme);
            if ($ids) {
                $in = implode(',', array_map('intval', $ids));
                $rows = cs_rows("SELECT id FROM comments WHERE meme_id = ? AND id IN ($in)", 'i', [$meme]);
                $info = cs_react_info(array_column($rows, 'id'), $owner);
                $seen = []; $ownAv = null;
                foreach ($rows as $r) {
                    $i = (int)$r['id'];
                    $seen[$i] = 1;
                    $inf = $info[$i];
                    $aa = null;
                    if ($inf['author']) {
                        if ($ownAv === null) { $o = cs_one("SELECT {$CS['u_avatar']} AS a FROM {$CS['users_table']} WHERE id = ?", 'i', [$owner]); $ownAv = cs_avatar($o['a'] ?? null); }
                        $aa = $ownAv;
                    }
                    $out['likes'][] = array_merge(['id' => $i, 'author_liked' => $inf['author'], 'author_avatar' => $aa], cs_pack($inf));
                }
                foreach ($ids as $i) if (!isset($seen[$i])) $out['gone'][] = $i;
            }
            $t = cs_one('SELECT COUNT(*) AS n FROM comments WHERE meme_id = ?', 'i', [$meme]);
            $out['total'] = max(0, (int)($t['n'] ?? 0) - count(cs_hidden($meme)));
            cs_out($out);

        case 'add':
            cs_need_login(); cs_need_csrf();
            $meme   = (int)($_POST['meme_id'] ?? 0);
            $parent = (int)($_POST['parent_id'] ?? 0);
            $text   = trim((string)($_POST['text'] ?? ''));
            $mtype  = (string)($_POST['media_type'] ?? 'none');
            $owner  = cs_meme_owner($meme);
            if (cs_comments_off($meme)) cs_fail('Comments are turned off for this post');
            if (mb_strlen($text) > $CS['max_text']) cs_fail('Comment too long');

            if ($parent) {                      // true nested threading: the parent is kept EXACTLY as given (a comment or a reply),
                                                // so a reply to a reply builds its own sub-thread instead of collapsing to the top
                $p = cs_one('SELECT meme_id FROM comments WHERE id = ?', 'i', [$parent]);
                if (!$p || (int)$p['meme_id'] !== $meme) cs_fail('Bad parent');
            }

            $mediaUrl = null;
            if ($mtype === 'image') {
                $f = $_FILES['image'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) cs_fail('Upload failed');
                if ($f['size'] > $CS['max_img']) cs_fail('Image too large (max 5 MB)');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? null;
                if (!$ext || !@getimagesize($f['tmp_name'])) cs_fail('Unsupported image');
                if (!is_dir($upDir)) mkdir($upDir, 0755, true);
                $mediaUrl = bin2hex(random_bytes(12)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], $upDir . $mediaUrl)) cs_fail('Could not save image', 500);
            } else { $mtype = 'none'; }

            if ($text === '' && !$mediaUrl) cs_fail('Write something first');

            $pid = $parent ?: null;
            $me  = cs_uid();
            cs_run('INSERT INTO comments (user_id, meme_id, parent_id, text, media_type, media_url) VALUES (?,?,?,?,?,?)',
                   'iiisss', [$me, $meme, $pid, $text, $mtype, $mediaUrl])->close();
            $id = (int)$conn->insert_id;
            cs_out(['item' => cs_fetch('c.id = ?', [$id], 'c.id', 1, 0, $owner)[0]]);

        case 'hide':                                    // hide / mark as spam: saved on the account
            cs_need_login(); cs_need_csrf();
            $cid  = (int)($_POST['comment_id'] ?? 0);
            $kind = ($_POST['kind'] ?? 'h') === 's' ? 's' : 'h';
            if (!cs_one('SELECT id FROM comments WHERE id = ?', 'i', [$cid])) cs_fail('Not found', 404);
            cs_hid_table();
            cs_run('INSERT INTO comment_user_hidden (user_id, comment_id, kind) VALUES (?,?,?) ON DUPLICATE KEY UPDATE kind = VALUES(kind)', 'iis', [cs_uid(), $cid, $kind])->close();
            cs_out(['ok' => true]);

        case 'unhide':
            cs_need_login(); cs_need_csrf();
            cs_hid_table();
            cs_run('DELETE FROM comment_user_hidden WHERE user_id = ? AND comment_id = ?', 'ii', [cs_uid(), (int)($_POST['comment_id'] ?? 0)])->close();
            cs_out(['ok' => true]);

        case 'hide_sync':                               // one-time move of what this browser had saved in localStorage
            cs_need_login(); cs_need_csrf();
            $map = json_decode((string)($_POST['map'] ?? '{}'), true);
            cs_hid_table();
            $n = 0;
            if (is_array($map)) foreach (array_slice($map, 0, 500, true) as $cid => $kind) {
                $cid = (int)$cid; if ($cid <= 0) continue;
                if (!cs_one('SELECT id FROM comments WHERE id = ?', 'i', [$cid])) continue;
                cs_run('INSERT IGNORE INTO comment_user_hidden (user_id, comment_id, kind) VALUES (?,?,?)', 'iis', [cs_uid(), $cid, $kind === 's' ? 's' : 'h'])->close();
                $n++;
            }
            cs_out(['ok' => true, 'saved' => $n]);

        case 'edit':                                    // the author can edit a comment's text for 1 minute after posting
            cs_need_login(); cs_need_csrf();
            $cid  = (int)($_POST['comment_id'] ?? 0);
            $text = trim((string)($_POST['text'] ?? ''));
            $r = cs_one('SELECT user_id, media_url, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM comments WHERE id = ?', 'i', [$cid]);
            if (!$r) cs_fail('Not found', 404);
            if ((int)$r['user_id'] !== cs_uid()) cs_fail('Not allowed', 403);
            if ((int)$r['age'] > 65) cs_fail('You can only edit a comment in the first minute');   // 5s grace for slow connections
            if (mb_strlen($text) > $CS['max_text']) cs_fail('Comment too long');
            if ($text === '' && !$r['media_url']) cs_fail('Write something first');
            try { cs_run('UPDATE comments SET text = ?, edited_at = NOW() WHERE id = ?', 'si', [$text, $cid])->close(); }
            catch (Throwable $e) {                      // first edit on an older database: add the column once, then retry
                $conn->query('ALTER TABLE comments ADD COLUMN edited_at DATETIME NULL DEFAULT NULL');
                cs_run('UPDATE comments SET text = ?, edited_at = NOW() WHERE id = ?', 'si', [$text, $cid])->close();
            }
            cs_out(['ok' => true, 'text' => $text]);

        case 'like':                                    // old name: the same as reacting with the red heart
            $_POST['reaction'] = 'like';
            // no break: continues into 'react'
        case 'react':                                   // set / change / remove my reaction. Sending the reaction I already have removes it.
            cs_need_login(); cs_need_csrf();
            $cid = (int)($_POST['comment_id'] ?? 0);
            $key = (string)($_POST['reaction'] ?? '');
            if ($key !== '' && !in_array($key, cs_reacts(), true)) cs_fail('Unknown reaction');
            if (!cs_one('SELECT id FROM comments WHERE id = ?', 'i', [$cid])) cs_fail('Not found', 404);
            cs_react_table();
            $me  = cs_uid();
            $cur = cs_react_info([$cid], 0)[$cid]['mine'];
            cs_run('DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?', 'ii', [$cid, $me])->close();
            cs_run('DELETE FROM comment_reactions WHERE comment_id = ? AND user_id = ?', 'ii', [$cid, $me])->close();
            if ($key !== '' && $key !== $cur) {
                if ($key === 'like') cs_run('INSERT IGNORE INTO comment_likes (comment_id, user_id) VALUES (?,?)', 'ii', [$cid, $me])->close();
                else cs_run('INSERT INTO comment_reactions (comment_id, user_id, reaction) VALUES (?,?,?) ON DUPLICATE KEY UPDATE reaction = VALUES(reaction)', 'iis', [$cid, $me, $key])->close();
            }
            cs_out(cs_pack(cs_react_info([$cid], 0)[$cid]));

        case 'reactors':                                // who reacted to a comment (the bottom sheet). ?comment_id=&filter=(a reaction or empty)&offset=
            $cid = (int)($_GET['comment_id'] ?? 0);
            $flt = (string)($_GET['filter'] ?? '');
            $off = max(0, (int)($_GET['offset'] ?? 0));
            if (!cs_one('SELECT id FROM comments WHERE id = ?', 'i', [$cid])) cs_fail('Not found', 404);
            cs_react_table();
            $all = [];
            try {
                foreach (cs_rows('SELECT user_id, UNIX_TIMESTAMP(created_at) AS ts FROM comment_likes WHERE comment_id = ?', 'i', [$cid]) as $r)
                    $all[] = ['u' => (int)$r['user_id'], 'k' => 'like', 'ts' => (int)$r['ts']];
            } catch (Throwable $e) {                    // an older comment_likes table without created_at
                $all = [];
                foreach (cs_rows('SELECT user_id FROM comment_likes WHERE comment_id = ?', 'i', [$cid]) as $r)
                    $all[] = ['u' => (int)$r['user_id'], 'k' => 'like', 'ts' => 0];
            }
            foreach (cs_rows('SELECT user_id, reaction, UNIX_TIMESTAMP(created_at) AS ts FROM comment_reactions WHERE comment_id = ?', 'i', [$cid]) as $r)
                $all[] = ['u' => (int)$r['user_id'], 'k' => (string)$r['reaction'], 'ts' => (int)$r['ts']];
            $bl = cs_blocked_ids(cs_uid());
            if ($bl) $all = array_values(array_filter($all, function ($x) use ($bl) { return !in_array($x['u'], $bl, true); }));
            usort($all, function ($a, $b) { return $b['ts'] <=> $a['ts']; });          // newest first
            $cnt = [];
            foreach ($all as $x) $cnt[$x['k']] = ($cnt[$x['k']] ?? 0) + 1;
            $order = array_flip(cs_reacts());
            uksort($cnt, function ($a, $b) use ($cnt, $order) { $d = $cnt[$b] <=> $cnt[$a]; return $d !== 0 ? $d : (($order[$a] ?? 99) <=> ($order[$b] ?? 99)); });
            $tabs = [];
            foreach ($cnt as $k => $n) $tabs[] = ['k' => $k, 'n' => $n];
            $list  = $flt !== '' ? array_values(array_filter($all, function ($x) use ($flt) { return $x['k'] === $flt; })) : $all;
            $slice = array_slice($list, $off, 41);
            $more  = count($slice) > 40;
            $slice = array_slice($slice, 0, 40);
            $users = [];
            if ($slice) {
                $in = implode(',', array_map('intval', array_column($slice, 'u')));
                foreach (cs_rows("SELECT id, {$CS['u_name']} AS n, {$CS['u_avatar']} AS a, COALESCE(is_verified,0) AS v FROM {$CS['users_table']} WHERE id IN ($in)") as $u) $users[(int)$u['id']] = $u;
            }
            $items = [];
            foreach ($slice as $x) {
                if (!isset($users[$x['u']])) continue;
                $items[] = ['user_id' => $x['u'], 'username' => $users[$x['u']]['n'], 'is_verified' => !empty($users[$x['u']]['v']), 'avatar' => cs_avatar($users[$x['u']]['a']), 'k' => $x['k']];
            }
            cs_out(['total' => count($all), 'tabs' => $tabs, 'items' => $items, 'more' => $more]);

        case 'save':                                    // save / unsave a comment (private list)
            cs_need_login(); cs_need_csrf();
            $cid = (int)($_POST['comment_id'] ?? 0);
            if (!cs_one('SELECT id FROM comments WHERE id = ?', 'i', [$cid])) cs_fail('Not found', 404);
            cs_save_table();
            $me = cs_uid();
            $st = cs_run('INSERT IGNORE INTO comment_saves (user_id, comment_id) VALUES (?,?)', 'ii', [$me, $cid]);
            $saved = $st->affected_rows > 0; $st->close();
            if (!$saved) cs_run('DELETE FROM comment_saves WHERE user_id = ? AND comment_id = ?', 'ii', [$me, $cid])->close();
            cs_out(['saved' => $saved]);

        case 'saved_list':                              // my saved comments, newest saved first (any post)
            cs_need_login();
            $off = max(0, (int)($_GET['offset'] ?? 0));
            cs_save_table();
            $rows = cs_rows("SELECT c.id, c.meme_id, c.user_id, c.text, c.media_type, c.media_url, c.created_at,
                    u.{$CS['u_name']} AS uname, u.{$CS['u_avatar']} AS uavatar, COALESCE(u.is_verified,0) AS uverified
                FROM comment_saves s
                JOIN comments c ON c.id = s.comment_id
                JOIN {$CS['users_table']} u ON u.id = c.user_id
                WHERE s.user_id = ? ORDER BY s.created_at DESC LIMIT 31 OFFSET $off", 'i', [cs_uid()]);
            $more = count($rows) > 30;
            $items = [];
            foreach (array_slice($rows, 0, 30) as $r) {
                $items[] = ['id' => (int)$r['id'], 'meme_id' => (int)$r['meme_id'], 'user_id' => (int)$r['user_id'],
                            'username' => $r['uname'], 'is_verified' => !empty($r['uverified']), 'avatar' => cs_avatar($r['uavatar']), 'text' => (string)$r['text'],
                            'media' => cs_media($r, $base), 'ago' => cs_ago($r['created_at'])];
            }
            cs_out(['items' => $items, 'more' => $more]);

        case 'pin':                                     // the post owner pins / unpins a comment (one pinned comment per post)
            cs_need_login(); cs_need_csrf();
            $cid = (int)($_POST['comment_id'] ?? 0);
            $r = cs_one('SELECT meme_id, parent_id FROM comments WHERE id = ?', 'i', [$cid]);
            if (!$r) cs_fail('Not found', 404);
            if ($r['parent_id']) cs_fail('Only a comment can be pinned, not a reply');
            $meme = (int)$r['meme_id'];
            if (cs_meme_owner($meme) !== cs_uid()) cs_fail('Only the post owner can pin a comment', 403);
            cs_pin_table();
            $cur = cs_one('SELECT comment_id FROM comment_pins WHERE meme_id = ?', 'i', [$meme]);
            if ($cur && (int)$cur['comment_id'] === $cid) {
                cs_run('DELETE FROM comment_pins WHERE meme_id = ?', 'i', [$meme])->close();
                cs_out(['pinned' => false, 'pin' => 0]);
            }
            cs_run('REPLACE INTO comment_pins (meme_id, comment_id) VALUES (?,?)', 'ii', [$meme, $cid])->close();   // pinning another one replaces the old pin
            cs_out(['pinned' => true, 'pin' => $cid]);

        case 'delete':                                  // the author or the post owner may delete; the WHOLE sub-tree under it goes too
            cs_need_login(); cs_need_csrf();
            $cid = (int)($_POST['comment_id'] ?? 0);
            $r = cs_one('SELECT c.user_id, c.parent_id, m.uploaded_by FROM comments c JOIN memes m ON m.id = c.meme_id WHERE c.id = ?', 'i', [$cid]);
            if (!$r) cs_fail('Not found', 404);
            $me = cs_uid();
            if ($me !== (int)$r['user_id'] && $me !== (int)$r['uploaded_by']) cs_fail('Not allowed', 403);

            $ids = [$cid]; $frontier = [$cid]; $guard = 0;      // walk every level of replies underneath, breadth-first
            while ($frontier && $guard++ < 200) {
                $in = implode(',', array_map('intval', $frontier));
                $next = [];
                foreach (cs_rows("SELECT id FROM comments WHERE parent_id IN ($in)") as $x) { $next[] = (int)$x['id']; $ids[] = (int)$x['id']; }
                $frontier = $next;
            }
            $in = implode(',', array_map('intval', $ids));
            foreach (cs_rows("SELECT id, media_type, media_url FROM comments WHERE id IN ($in)") as $row) {
                if ($row['media_type'] === 'image' && $row['media_url']) @unlink($upDir . basename($row['media_url']));
            }
            $conn->query("DELETE FROM comment_likes WHERE comment_id IN ($in)");
            try { cs_react_table(); $conn->query("DELETE FROM comment_reactions WHERE comment_id IN ($in)"); } catch (Throwable $e) {}
            try { cs_save_table(); $conn->query("DELETE FROM comment_saves WHERE comment_id IN ($in)"); } catch (Throwable $e) {}
            try { cs_pin_table(); $conn->query("DELETE FROM comment_pins WHERE comment_id IN ($in)"); } catch (Throwable $e) {}
            $conn->query("DELETE FROM comments WHERE id IN ($in)");
            cs_out(['ok' => true, 'removed' => count($ids), 'parent_id' => $r['parent_id'] ? (int)$r['parent_id'] : null]);

        case 'track':                                   // track / untrack — same rules as the profile page
            cs_need_login(); cs_need_csrf();
            $to = (int)($_POST['user_id'] ?? 0);
            $me = cs_uid();
            if ($to <= 0 || $to === $me || !($tu = cs_one("SELECT * FROM {$CS['users_table']} WHERE id = ?", 'i', [$to]))) cs_fail('You cannot track this account');
            $has = cs_one('SELECT 1 AS x FROM followers WHERE follower_id = ? AND following_id = ?', 'ii', [$me, $to]);
            if ($has) {                                 // Untrack (profile page: delete follower, log it, clear the request)
                cs_run('DELETE FROM followers WHERE follower_id = ? AND following_id = ?', 'ii', [$me, $to])->close();
                try { cs_run('INSERT INTO unfollow_log (unfollowed_by, user_id) VALUES (?, ?)', 'ii', [$me, $to])->close(); } catch (Throwable $e) {}
                try { cs_run('DELETE FROM track_requests WHERE requester_id = ? AND target_id = ?', 'ii', [$me, $to])->close(); } catch (Throwable $e) {}
                cs_out(['tracking' => false]);
            }
            $m = cs_block_map(false);                   // blocked either way = cannot track
            if ($m && cs_one("SELECT 1 AS x FROM `{$m[0]}` WHERE (`{$m[1]}` = ? AND `{$m[2]}` = ?) OR (`{$m[1]}` = ? AND `{$m[2]}` = ?)", 'iiii', [$me, $to, $to, $me]))
                cs_fail('You cannot track this account');
            if (!empty($tu['is_fully_private'])) {      // private profile: needs an approved request first
                $ap = null;
                try { $ap = cs_one("SELECT status FROM track_requests WHERE requester_id = ? AND target_id = ? LIMIT 1", 'ii', [$me, $to]); } catch (Throwable $e) {}
                if ($ap && $ap['status'] === 'pending') {          // a second tap cancels the pending request
                    cs_run("DELETE FROM track_requests WHERE requester_id = ? AND target_id = ? AND status = 'pending'", 'ii', [$me, $to])->close();
                    cs_out(['tracking' => false, 'requested' => false]);
                }
                if (!$ap || $ap['status'] !== 'approved') {
                    cs_run("INSERT INTO track_requests (requester_id, target_id, status, created_at) VALUES (?,?,'pending',NOW()) ON DUPLICATE KEY UPDATE created_at = IF(status='approved', created_at, NOW()), status = IF(status='approved','approved','pending')", 'ii', [$me, $to])->close();
                    cs_out(['tracking' => false, 'requested' => true]);
                }
            }
            cs_run('INSERT IGNORE INTO followers (follower_id, following_id) VALUES (?, ?)', 'ii', [$me, $to])->close();
            try { cs_run('DELETE FROM unfollow_log WHERE unfollowed_by = ? AND user_id = ?', 'ii', [$me, $to])->close(); } catch (Throwable $e) {}
            cs_out(['tracking' => true]);

        case 'block':                                   // block the author of a comment
            cs_need_login(); cs_need_csrf();
            $to = (int)($_POST['user_id'] ?? 0);
            $me = cs_uid();
            if ($to <= 0 || $to === $me || !cs_one("SELECT id FROM {$CS['users_table']} WHERE id = ?", 'i', [$to])) cs_fail('You cannot block this account');
            $m = cs_block_map(true);
            cs_run("INSERT IGNORE INTO `{$m[0]}` (`{$m[1]}`, `{$m[2]}`) VALUES (?, ?)", 'ii', [$me, $to])->close();
            try { cs_run('DELETE FROM followers WHERE follower_id = ? AND following_id = ?', 'ii', [$me, $to])->close(); } catch (Throwable $e) {}
            try { cs_run('DELETE FROM follows WHERE follower_id = ? AND following_id = ?', 'ii', [$me, $to])->close(); } catch (Throwable $e) {}
            try { cs_run('DELETE FROM track_requests WHERE requester_id = ? AND target_id = ?', 'ii', [$me, $to])->close(); } catch (Throwable $e) {}
            cs_out(['ok' => true]);

        case 'report':                                  // report a comment
            cs_need_login(); cs_need_csrf();
            $cid    = (int)($_POST['comment_id'] ?? 0);
            $reason = (string)($_POST['reason'] ?? '');
            if (!in_array($reason, ['spam', 'harassment', 'hate', 'sexual', 'violence', 'scam', 'misinfo', 'other'], true)) cs_fail('Choose a reason');
            $r = cs_one('SELECT user_id FROM comments WHERE id = ?', 'i', [$cid]);
            if (!$r) cs_fail('Comment not found', 404);
            $me = cs_uid();
            if ((int)$r['user_id'] === $me) cs_fail('You cannot report your own comment');
            $conn->query("CREATE TABLE IF NOT EXISTS comment_reports (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                comment_id INT UNSIGNED NOT NULL,
                reporter_id INT UNSIGNED NOT NULL,
                reported_user_id INT UNSIGNED NOT NULL DEFAULT 0,
                reason VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cs_report (comment_id, reporter_id),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            cs_run('INSERT IGNORE INTO comment_reports (comment_id, reporter_id, reported_user_id, reason) VALUES (?,?,?,?)', 'iiis', [$cid, $me, (int)$r['user_id'], $reason])->close();
            cs_out(['ok' => true]);

        default:
            cs_fail('Unknown action', 404);
        }
    } catch (Throwable $e) {
        error_log('comment_section: ' . $e->getMessage());
        cs_fail('Server error', 500);
    }
}

} // end of function_exists guard

/* ---------------- Direct request = API.  Included in a page = UI. ---------------- */
if (strcasecmp((string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''), __FILE__) === 0) {
    require $CS['config'];
    if (session_status() === PHP_SESSION_NONE) session_start();
    cs_api();
    exit;
}
$CS_NONCE = htmlspecialchars((string)($nonce ?? ''), ENT_QUOTES, 'UTF-8'); // uses your page's CSP $nonce
?>
<style nonce="<?= $CS_NONCE ?>">
/* ═══ Comments sheet — same look as the profile bottom sheets: midnight-blue (dark) / white (light), sharp corners,
       sizes follow the same breakpoints. Everything inside is in em, so one font-size per breakpoint scales it all. ═══ */
.cs-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
  z-index:2147483500;opacity:0;pointer-events:none;transition:opacity .25s ease}
.cs-overlay.show{opacity:1;pointer-events:all}

.cs-sheet{
  --cs-bg:rgba(12,12,28,.98); --cs-tx:#f5f5f5; --cs-sub:rgba(255,255,255,.55); --cs-line:rgba(255,255,255,.08);
  --cs-field:rgba(255,255,255,.07); --cs-handle:rgba(255,255,255,.2); --cs-chip:rgba(255,255,255,.09);
  --cs-chip-on-bg:#fff; --cs-chip-on-tx:#0c0c1c; --cs-mention:#6aa8ff; --cs-red:var(--red,#ff2d2d);
  --cs-thread:rgba(255,255,255,.24);
  --cs-fs:14.3px; --cs-k:.894;
  position:fixed;left:0;right:0;bottom:0;width:100%;
  height:78vh;height:78dvh;max-height:calc(100dvh - 8px);
  background:var(--cs-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  border-top:1px solid var(--cs-line);border-radius:0;color:var(--cs-tx);
  z-index:2147483501;display:flex;flex-direction:column;overflow:hidden;
  transform:translateY(100%);transition:transform .26s cubic-bezier(.32,.72,0,1);
  box-shadow:0 -10px 40px rgba(0,0,0,.5);
  font:var(--cs-fs)/1.35 Poppins,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
.cs-sheet.show{transform:translateY(0)}
[data-theme="light"] .cs-overlay{background:rgba(20,24,40,.38)}
[data-theme="light"] .cs-sheet{
  --cs-bg:rgba(255,255,255,.99); --cs-tx:#14171f; --cs-sub:rgba(20,23,31,.55); --cs-line:rgba(15,20,40,.10);
  --cs-field:rgba(15,20,40,.06); --cs-handle:rgba(15,20,40,.18); --cs-chip:rgba(15,20,40,.07);
  --cs-chip-on-bg:#0c0c1c; --cs-chip-on-tx:#fff; --cs-mention:#2457c5; --cs-thread:rgba(15,20,40,.22);
  box-shadow:0 -10px 40px rgba(15,20,40,.18)}

.cs-handle{padding:.7em 0 .4em;cursor:pointer;flex:none}
.cs-handle i{display:block;width:2.5em;height:.27em;border-radius:99px;background:var(--cs-handle);margin:0 auto}
.cs-tabs{display:flex;gap:1.2em;justify-content:center;padding:.15em 0 .6em;flex:none;font-size:.92em;color:var(--cs-sub);border-bottom:1px solid var(--cs-line)}
.cs-tabs b{cursor:pointer;font-weight:600;padding:.25em 0;border-bottom:2px solid transparent}
.cs-tabs b.on{color:var(--cs-tx);border-bottom-color:var(--cs-red)}
.cs-list{flex:1;min-height:0;overflow-y:auto;padding:.4em 1.05em .7em;overscroll-behavior:contain;scrollbar-width:thin}
/* scroll bar — slim, rounded, floating thumb that follows the sheet's own colours (dark + light) */
.cs-list,#csPopList,.cs-rs-list{scrollbar-width:thin;scrollbar-color:var(--cs-handle,rgba(255,255,255,.2)) transparent}
.cs-list::-webkit-scrollbar,#csPopList::-webkit-scrollbar,.cs-rs-list::-webkit-scrollbar{width:7px;background:transparent}
.cs-list::-webkit-scrollbar-track,#csPopList::-webkit-scrollbar-track,.cs-rs-list::-webkit-scrollbar-track{background:transparent;margin:6px 0}
.cs-list::-webkit-scrollbar-thumb,#csPopList::-webkit-scrollbar-thumb,.cs-rs-list::-webkit-scrollbar-thumb{background:var(--cs-handle,rgba(255,255,255,.2));border-radius:99px;border:2px solid transparent;background-clip:padding-box;min-height:36px}
.cs-list::-webkit-scrollbar-thumb:hover,#csPopList::-webkit-scrollbar-thumb:hover,.cs-rs-list::-webkit-scrollbar-thumb:hover,
.cs-list::-webkit-scrollbar-thumb:active,#csPopList::-webkit-scrollbar-thumb:active,.cs-rs-list::-webkit-scrollbar-thumb:active{background:var(--cs-sub,rgba(255,255,255,.55));background-clip:padding-box}
.cs-empty,.cs-load{text-align:center;color:var(--cs-sub);padding:2.2em 0}
/* loading skeleton (first open + every "load more") */
.cs-sk{display:flex;gap:.8em;padding:.75em 0;pointer-events:none}
.cs-sk-av,.cs-sk-l{background:linear-gradient(90deg,var(--cs-line) 25%,rgba(130,135,170,.22) 50%,var(--cs-line) 75%);background-size:200% 100%;animation:csSk 1.25s ease-in-out infinite}
.cs-sk-av{width:2.6em;height:2.6em;border-radius:50%;flex:none}
.cs-sk-b{flex:1;min-width:0;display:flex;flex-direction:column;gap:.55em;padding-top:.25em}
.cs-sk-l{height:.72em;border-radius:.2em}
@keyframes csSk{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ═══ every comment is an ".cs-it" item:  [ row (avatar+line, text, like, dots) ]  [ "N replies" toggle ]  [ sub-thread ] ═══ */
.cs-it{--csav:2.6em;--csgap:.75em;padding:.95em 0}
.cs-it.cs-thread{margin:0 -1.05em;padding:.95em 1.05em;border-bottom:1px solid var(--cs-line)}
.cs-it.cs-thread:last-child{border-bottom:0}
.cs-sub .cs-it,.cs-titems .cs-it{--csav:2.3em}
.cs-sub .cs-it{padding:.75em 0 .15em}
.cs-sub .cs-it:first-child{padding-top:.5em}
.cs-c{display:flex;gap:var(--csgap);margin:0;padding:0}

/* the avatar column: the profile pic, and (when replies hang under it) the thread line running down from it */
.cs-avcol{display:flex;flex-direction:column;align-items:center;flex:none;width:var(--csav)}
.cs-line{display:none;flex:1;width:2px;margin-top:.5em;border-radius:2px;background:var(--cs-thread)}
.cs-it.has-thread > .cs-c .cs-line{display:block}
.cs-it.has-thread > .cs-c .cs-body{padding-bottom:.8em}

.cs-av{box-sizing:border-box;border:2px solid var(--cs-red);width:var(--csav,2.6em);height:var(--csav,2.6em);border-radius:50%;flex:none;object-fit:cover;background:var(--cs-chip);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--cs-tx)}
.cs-body{flex:1;min-width:0}
.cs-meta{font-size:.92em;color:var(--cs-sub);display:flex;flex-wrap:wrap;gap:.3em;align-items:center}
.cs-meta .n{color:var(--cs-tx);font-weight:600}
.cs-vb{display:inline-flex;width:1em;height:1em;margin-left:-.15em;color:var(--red,#FF2D2D);position:relative;top:-.04em}.cs-vb svg{width:100%;height:100%;display:block}
.cs-ph-name .cs-vb,.cs-rs-nm .cs-vb{margin-left:.3em;vertical-align:-.1em}
.cs-by{color:var(--cs-sub)}.cs-by .h{color:var(--cs-red)}
.cs-text{margin-top:.15em;white-space:pre-wrap;word-wrap:break-word}
.cs-text .m,.cs-ph-cap .m,a.cs-mn{color:var(--cs-red);font-weight:600;text-decoration:none}
a.cs-ht{color:var(--gw,var(--cs-sub));font-weight:600;text-decoration:none}
a.cs-mn:hover,a.cs-ht:hover{text-decoration:underline}
a.cs-srch{display:flex;align-items:center;gap:11px;box-sizing:border-box;width:calc(100% + .7em);margin:10px -.35em 8px;padding:11px 14px;border:1px solid rgba(138,124,255,.38);border-radius:4px;background:none;color:#9486ec;font-weight:600;font-size:.95em;line-height:1.2;text-decoration:none;-webkit-tap-highlight-color:transparent;transition:border-color .15s ease,opacity .15s ease}
a.cs-srch svg{flex:none;width:18px;height:18px;opacity:.95}
a.cs-srch .t{min-width:0;flex:1 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:left}
a.cs-srch .t i{font-style:normal;font-weight:500;opacity:.7;margin-right:.35em}
a.cs-srch .go{flex:none;width:16px;height:16px;opacity:.7}
a.cs-srch:active{border-color:rgba(158,146,255,.6);opacity:.75}
:root[data-theme="light"] a.cs-srch{color:#4a3cc4;border-color:rgba(84,70,210,.35);background:none}
.cs-tail{white-space:nowrap}
.cs-moretx{color:var(--cs-red);font-weight:600;cursor:pointer}
.cs-moretx:hover{opacity:.8}
.cs-media{margin-top:.45em}
.cs-media img{max-width:15em;max-height:18.5em;border-radius:.8em;display:block;cursor:zoom-in;-webkit-tap-highlight-color:transparent}
.cs-media img.st{max-width:8.5em;max-height:8.5em}
.cs-act{margin-top:.3em;display:flex;gap:1.1em;font-size:.92em;color:var(--cs-sub)}
.cs-act button{all:unset;cursor:pointer}.cs-act button:hover{color:var(--cs-tx)}
.cs-side{flex:none;display:flex;align-items:center;gap:.55em;align-self:flex-start;padding-top:.1em}
.cs-like{display:flex;flex-direction:row;align-items:center;gap:.3em;color:var(--cs-sub);font-size:.88em}
.cs-like button{all:unset;cursor:pointer;display:flex;-webkit-tap-highlight-color:transparent}
.cs-like svg,.cs-ic svg{width:1.3em;height:1.3em;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.cs-ic svg{width:1.45em;height:1.45em}
.cs-like.on button{color:var(--cs-red)}.cs-like.on svg{fill:var(--cs-red)}
/* like count: smaller + lighter than before */
.cs-like span{font-size:.95em;font-weight:500;line-height:1;min-width:1em;white-space:nowrap;font-variant-numeric:tabular-nums}

/* "· 9 replies ›" row: joined to the commenter's profile pic by a curved line from the avatar's thread line */
.cs-vrw{margin:0}
.cs-vr{all:unset;box-sizing:border-box;cursor:pointer;position:relative;display:flex;align-items:center;gap:.55em;height:2.4em;
  margin-left:calc(var(--csav) + var(--csgap));color:var(--cs-tx);font-weight:600;-webkit-tap-highlight-color:transparent}
.cs-vr:before{content:"";position:absolute;top:0;height:50%;box-sizing:border-box;
  left:calc(-1 * (var(--csav) / 2 + var(--csgap) + 1px));width:calc(var(--csav) / 2 + var(--csgap) + 1px);
  border-left:2px solid var(--cs-thread);border-bottom:2px solid var(--cs-thread);border-bottom-left-radius:.9em}
.cs-vr-av{width:1.7em;height:1.7em;border-radius:50%;overflow:hidden;box-sizing:border-box;border:1.5px solid var(--cs-red);flex:none;background:var(--cs-chip)}
.cs-vr-av img{width:100%;height:100%;object-fit:cover;display:block}
.cs-vr-dot{opacity:.6}
.cs-vr-lbl{font-size:.92em}
.cs-vr-chev{display:flex;transition:transform .2s ease}
.cs-vr-chev svg{width:1.2em;height:1.2em;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.cs-vr.open .cs-vr-chev{transform:rotate(90deg)}

/* the sub-thread that drops down under a reply's own "N replies" toggle */
.cs-sub{margin-left:calc(var(--csav) + var(--csgap));margin-top:.1em}
.cs-submore{all:unset;cursor:pointer;display:block;margin:.2em 0 .5em;font-size:.88em;font-weight:600;color:var(--cs-sub)}
.cs-submore:hover{color:var(--cs-tx)}
.cs-subload{color:var(--cs-sub);font-size:.88em;padding:.6em 0}

.cs-bar{flex:none;border-top:1px solid var(--cs-line)}
.cs-emo{display:flex;justify-content:space-between;padding:.7em 1.1em .3em}
.cs-emo span{font-size:1.65em;cursor:pointer;user-select:none}
.cs-note{display:none;padding:.3em 1.1em;font-size:.85em;color:var(--cs-sub);justify-content:space-between}
.cs-note.show{display:flex}.cs-note b{cursor:pointer}
.cs-prev{display:none;padding:.45em 1.1em 0;position:relative}
.cs-prev.show{display:block}
.cs-prev img{height:5em;border-radius:.8em;cursor:zoom-in}
.cs-prev b{position:absolute;top:.15em;left:1em;background:#000a;color:#fff;border-radius:50%;width:1.45em;height:1.45em;text-align:center;line-height:1.45em;cursor:pointer;font-size:.85em}
.cs-in{display:flex;align-items:center;gap:.7em;padding:.6em 1em max(.9em, env(safe-area-inset-bottom))}
/* the user's own picture at the bottom: red ring */
.cs-in .cs-av{width:2.75em;height:2.75em;border:2px solid var(--cs-red)}
.cs-box{flex:1;min-width:0;display:flex;align-items:center;gap:.55em;background:var(--cs-field);border-radius:.8em;padding:.35em .8em}
.cs-inw{flex:1;min-width:0;display:flex}
.cs-box input[type=text]{flex:1;min-width:0;width:100%;background:none;border:0;outline:0;color:var(--cs-tx);font:inherit;padding:.35em 0}
/* touch screens: iPhone zooms into any field under 16px. So the field is really 16px, then scaled down by --cs-k
   (= sheet font size / 16) so the text looks exactly as big as the rest of the sheet, on every breakpoint. */
@media (pointer:coarse){
  .cs-inw{position:relative;display:block;height:2.1em}
  .cs-box input[type=text]{position:absolute;left:0;top:50%;flex:none;width:calc(100% / var(--cs-k));font-size:16px;line-height:1.3;
    transform:translateY(-50%) scale(var(--cs-k));transform-origin:0 50%}
}
.cs-box input[type=text]::placeholder{color:var(--cs-sub)}
.cs-ic{all:unset;cursor:pointer;color:var(--cs-tx);display:flex;opacity:.85}
.cs-post{all:unset;cursor:pointer;color:var(--cs-red);display:flex;align-items:center;padding:0 .15em}
.cs-post svg{width:1.56em;height:1.56em;display:block;fill:currentColor;transition:transform .15s ease}
.cs-post:active svg{transform:scale(.88)}
.cs-post[disabled]{opacity:.4;cursor:default}

/* drag to move: handle + tabs follow the finger (phones). .cs-full = pulled up to (almost) full screen */
.cs-sheet{transition:transform .26s cubic-bezier(.32,.72,0,1),height .26s cubic-bezier(.32,.72,0,1)}
.cs-sheet.cs-drag{transition:none !important}
/* while the sheet is sliding, drop the live blur (it is repainted every frame and is what makes phones stutter; the panel is almost opaque anyway) */
.cs-sheet{will-change:transform}
.cs-sheet.cs-anim{backdrop-filter:none !important;-webkit-backdrop-filter:none !important}
.cs-overlay.cs-drag{transition:none !important}
.cs-sheet.cs-full{height:calc(100vh - 6px);height:calc(100dvh - 6px)}
.cs-handle,.cs-tabs{touch-action:none;-webkit-user-select:none;user-select:none}
.cs-handle{padding:.9em 0 .6em}
@media (min-width:791px){ .cs-sheet{transition:transform .26s cubic-bezier(.32,.72,0,1),opacity .26s} }
/* desktop: centred modal, like the profile sheets */
@media (min-width:791px){
  .cs-sheet{left:50%;right:auto;bottom:auto;top:50%;max-width:480px;height:min(76vh,680px);
    transform:translate(-50%,-40%) scale(.95);opacity:0;pointer-events:none;border:1px solid var(--cs-line)}
  .cs-sheet.show{transform:translate(-50%,-50%) scale(1);opacity:1;pointer-events:all}
  .cs-handle{display:none}
  .cs-tabs{padding-top:.9em}
}
/* same size steps as the profile sheets */
@media (max-width:790px){ .cs-sheet{--cs-fs:13.75px;--cs-k:.859;height:78vh;height:78dvh} }
@media (max-width:490px){ .cs-sheet{--cs-fs:13.2px;--cs-k:.825;height:80vh;height:80dvh;backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)} }
@media (max-width:390px){ .cs-sheet{--cs-fs:12.65px;--cs-k:.79;height:82vh;height:82dvh;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px)} }
@media (max-width:330px){ .cs-sheet{--cs-fs:11.55px;--cs-k:.722;height:84vh;height:84dvh;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)} }
@media (max-height:520px) and (max-width:790px){ .cs-sheet{height:calc(100dvh - 6px)} .cs-emo{display:none} }

/* ═══ post header (opened from Zeems): the post's owner, date and counts sit at the top of the list, comments under it ═══ */
.cs-ph{padding:.6em 0 0;margin-bottom:.2em;border-bottom:1px solid var(--cs-line)}
.cs-ph-top{display:flex;align-items:center;gap:.75em}
.cs-ph .cs-av{width:3.1em;height:3.1em;cursor:pointer}
.cs-ph-names{flex:1;min-width:0;cursor:pointer}
.cs-ph-name{font-weight:700;font-size:1.05em;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cs-ph-nick{color:var(--cs-sub);font-size:.845em;line-height:1.3;margin-top:.45em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cs-ph-cl{position:relative;display:block;flex:none;width:4.1em;height:4.1em;cursor:pointer}
.cs-ph-cl .cs-av{position:absolute;width:66%;height:66%}
.cs-ph-cl.n1 .cs-av:nth-child(1){left:0;bottom:0;z-index:2}
.cs-ph-cl.n1 .cs-av:nth-child(2){right:0;top:0;z-index:1}
.cs-ph-cl.n2 .cs-av{width:58%;height:58%}
.cs-ph-cl.n2 .cs-av:nth-child(1){left:21%;top:0;z-index:3}
.cs-ph-cl.n2 .cs-av:nth-child(2){left:0;bottom:0;z-index:2}
.cs-ph-cl.n2 .cs-av:nth-child(3){right:0;bottom:0;z-index:1}
.cs-ph-track{all:unset;cursor:pointer;flex:none;font-weight:700;color:var(--cs-red);text-decoration:underline;text-decoration-thickness:2px;text-underline-offset:3px;padding:.3em .2em}
.cs-ph-track:active{opacity:.6}
.cs-ph-cap{margin:.85em 0 0;font-size:1em;line-height:1.7;white-space:pre-wrap;word-wrap:break-word;color:var(--cs-tx)}
.cs-ph-cap:empty{display:none}
/* ── poll: the choices sit right under the caption ── */
.cs-poll{margin:.9em 0 .2em}
.cs-pl-opt{position:relative;display:flex;align-items:center;gap:.6em;width:100%;min-height:2.9em;margin:0 0 .5em;padding:.55em .95em;border:0;border-radius:.85em;background:var(--cs-field);color:var(--cs-tx);font:inherit;font-size:1em;font-weight:500;text-align:left;cursor:pointer;overflow:hidden;-webkit-tap-highlight-color:transparent;transition:transform .12s ease,opacity .12s ease}
.cs-pl-opt:active:not(:disabled){transform:scale(.985)}
.cs-pl-opt:disabled{cursor:default}
.cs-pl-opt.busy{opacity:.7}
.cs-pl-fill{position:absolute;left:0;top:0;bottom:0;width:0;background:var(--cs-thread);border-radius:inherit;transition:width .45s cubic-bezier(.22,1,.36,1);z-index:0}
.cs-pl-lab{position:relative;z-index:1;flex:1;min-width:0;overflow-wrap:anywhere;line-height:1.35}
.cs-pl-pct{position:relative;z-index:1;font-weight:600;font-variant-numeric:tabular-nums;color:var(--cs-sub)}
.cs-pl-opt.on .cs-pl-lab{font-weight:700}
.cs-pl-opt.on .cs-pl-pct{color:var(--cs-tx)}
.cs-pl-ck{position:relative;z-index:1;width:1.15em;height:1.15em;flex:0 0 auto;stroke:currentColor;fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
.cs-pl-meta{margin:.15em .2em .1em;font-size:.88em;color:var(--cs-sub)}
.cs-ph-meta{margin:.7em 0 .7em;color:var(--cs-sub);font-size:.92em}
.cs-ph-meta b{color:var(--cs-tx);font-weight:700}
.cs-ph-stats{display:flex;justify-content:space-around;align-items:center;padding:.8em .2em .65em;border-top:1px solid var(--cs-line)}
/* same buttons as the Zeems rail (icon + count beside it); only the ICON turns red when pressed, never the number */
.cs-ph-stats button{all:unset;cursor:pointer;display:flex;flex-direction:row;align-items:center;gap:.4em;color:var(--cs-tx);-webkit-tap-highlight-color:transparent}
.cs-ph-stats i{font-size:1.66em;line-height:1;-webkit-text-stroke:.6px currentColor;transition:transform .18s ease,color .18s ease}
.cs-ph-stats .cs-ic{display:block;width:1.166em;height:1.166em;margin:-.083em;font-style:normal;-webkit-text-stroke:0}
.cs-ph-stats .cs-ic svg{display:block;width:100%;height:100%;overflow:visible}
.cs-ph-stats button.on .cs-fillable svg{fill:currentColor}
.cs-ph-stats span{font-size:1.02em;font-weight:800;line-height:1.2;color:var(--cs-tx);min-width:.6em}
.cs-ph-stats button.on i,.cs-ph-stats button.on svg{color:var(--cs-red)}
.cs-ph-stats button:active i,.cs-ph-stats button:active svg{transform:scale(.9)}

/* "Comments" title + sort menu (horizontal bars, top right) */
.cs-tabs{position:relative;justify-content:flex-start;align-items:center;gap:0;padding:.15em 3.4em .6em 1.05em}
.cs-title{font-weight:700;font-size:1.12em;color:var(--cs-tx)}
.cs-menubtn{all:unset;cursor:pointer;position:absolute;right:.9em;top:50%;transform:translateY(-62%);color:var(--cs-tx);display:flex;padding:.3em}
.cs-menubtn svg{width:1.7em;height:1.7em;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round}
.cs-menu{display:none;position:absolute;right:.7em;top:calc(100% - .2em);z-index:6;min-width:11em;background:var(--cs-bg);border:1px solid var(--cs-line);box-shadow:0 8px 28px rgba(0,0,0,.4);padding:.3em 0}
.cs-menu.show{display:block}
.cs-menu button{all:unset;cursor:pointer;display:block;width:100%;box-sizing:border-box;padding:.65em 1.1em;font-size:.95em;color:var(--cs-tx)}
.cs-menu button:hover{background:var(--cs-chip)}
.cs-menu button.on{color:var(--cs-red);font-weight:700}
/* responsive only: bigger sort menu */
@media (max-width:790px){
  .cs-menu{min-width:14em;right:.5em}
  .cs-menu button{padding:.95em 1.4em;font-size:1.2em}
}

/* ═══ DOCKED mode (opened from Zeems): no dimming — the post's picture stays visible, squeezed above the sheet ═══ */
.cs-overlay.cs-docked{background:transparent;backdrop-filter:none;-webkit-backdrop-filter:none;pointer-events:none !important}
.cs-sheet.cs-docked{height:60vh;height:60dvh;box-shadow:0 -6px 24px rgba(0,0,0,.35)}
.cs-sheet.cs-docked.cs-full{height:calc(100vh - 6px);height:calc(100dvh - 6px)}
.cs-sheet.cs-docked .cs-handle{display:block}
@media (min-width:791px){
  .cs-sheet.cs-docked{left:var(--cs-dock-left,50%);width:var(--cs-dock-w,480px);max-width:none;top:auto;bottom:var(--cs-dock-bottom,0px);
    height:var(--cs-dock-h,60vh);border-radius:0 0 22px 22px;border:0;border-top:1px solid var(--cs-line);
    transform:translateY(24px);opacity:0;pointer-events:none}
  .cs-sheet.cs-docked.show{transform:translateY(0);opacity:1;pointer-events:all}
  /* LAPTOP, opened from Zeems: a full-height panel beside the box that covers the rail buttons and the arrows. It has an X; phones never see this. */
  .cs-sheet.cs-docked.cs-side{left:var(--cs-dock-left,60%);top:0;bottom:0;right:auto;width:var(--cs-dock-w,420px);height:100vh;height:100dvh;max-width:none;
    border-radius:0;border:0;border-left:1px solid var(--cs-line);box-shadow:-10px 0 40px rgba(0,0,0,.4);transform:translateX(28px);opacity:0}
  .cs-sheet.cs-docked.cs-side.show{transform:translateX(0);opacity:1}
  /* the X sits on the LEFT of "Comments", smaller (same scale as the Zeems box), and the panel is tighter: less side / top / bottom space */
  .cs-sheet.cs-side{--cs-fs:13px;--cs-k:.81}
  .cs-sheet.cs-side .cs-closex{display:flex !important;position:static;order:-1;transform:none;margin:0 .55em 0 -.3em;width:2em !important;height:2em !important}
  .cs-sheet.cs-side .cs-closex svg{width:1.35em !important;height:1.35em !important}
  .cs-sheet.cs-side #csTabs{padding:.4em 5.6em .45em .8em}
  .cs-sheet.cs-side .cs-menubtn{right:.5em}
  .cs-sheet.cs-side .cs-savedbtn{right:3em}
  /* every part of the panel (top bar, list, emoji row, input bar) spans the FULL width of the panel: no inward gaps */
  .cs-sheet.cs-side{align-items:stretch;justify-content:flex-start}
  .cs-sheet.cs-side > *{width:100%;max-width:none;margin-left:0;margin-right:0;box-sizing:border-box;align-self:stretch}
  .cs-sheet.cs-side #csTabs{justify-content:flex-start}
  .cs-sheet.cs-side .cs-list{overflow-x:hidden}
  .cs-sheet.cs-side .cs-emo{padding:.5em .8em .2em}
  .cs-sheet.cs-side .cs-list{padding:.2em .8em .4em}
  .cs-sheet.cs-side .cs-in{padding:.4em .8em .6em}
}
.cs-sheet .cs-closex{display:none !important}   /* phones / responsive: never shown (the laptop side panel turns it on below) */

/* ═══════════════════════════════════════════════════════════════
   v2: three-dot button · comment menu · translate screen · select-text screen · image viewer · hidden comments
   (these live OUTSIDE .cs-sheet, so they get their own copy of the colour variables)
   ═══════════════════════════════════════════════════════════════ */
.cs-pop,.cs-scr{
  --cs-bg:#0b0c20; --cs-tx:#f5f5f5; --cs-sub:rgba(255,255,255,.55); --cs-line:rgba(255,255,255,.1);
  --cs-field:rgba(255,255,255,.07); --cs-handle:rgba(255,255,255,.2); --cs-chip:rgba(255,255,255,.09);
  --cs-red:var(--red,#ff2d2d); --cs-fs:14.3px}
[data-theme="light"] .cs-pop,[data-theme="light"] .cs-scr{
  --cs-bg:#ffffff; --cs-tx:#14171f; --cs-sub:rgba(20,23,31,.55); --cs-line:rgba(15,20,40,.1);
  --cs-field:rgba(15,20,40,.06); --cs-handle:rgba(15,20,40,.18); --cs-chip:rgba(15,20,40,.07)}
@media (max-width:790px){ .cs-pop,.cs-scr{--cs-fs:14px} }
@media (max-width:490px){ .cs-pop,.cs-scr{--cs-fs:13.5px} }
@media (max-width:390px){ .cs-pop,.cs-scr{--cs-fs:13px} }

/* three dots on every comment */
.cs-dots{all:unset;cursor:pointer;display:flex;align-items:center;color:var(--cs-sub);padding:.2em .35em;-webkit-tap-highlight-color:transparent}
.cs-dots svg{width:1.5em;height:1.5em;fill:currentColor;display:block}
.cs-dots:hover{color:var(--cs-tx)}

/* hidden / spam comment */
.cs-hidnote{display:none;align-items:center;gap:.7em;width:100%;color:var(--cs-sub);font-size:.95em}
.cs-hidnote button{all:unset;cursor:pointer;color:var(--cs-red);font-weight:600;text-decoration:underline}
.cs-c.hid>*:not(.cs-hidnote){display:none}
.cs-it.hid>:not(.cs-c){display:none}
.cs-c.hid .cs-hidnote{display:flex}

/* "Translated to …  Show original" line under a comment */
.cs-trbar{display:none;align-items:center;gap:.5em;margin-top:.35em;font-size:.9em;color:var(--cs-sub)}
.cs-trbar.show{display:flex}
.cs-trbar i{color:var(--cs-red);font-size:1.1em;flex:none}
.cs-trbar span{min-width:0}
.cs-trbar button{all:unset;cursor:pointer;color:var(--cs-red);font-weight:700;text-decoration:underline;flex:none}

/* comment menu: bottom sheet on phones, centred card on desktop */
.cs-pop-ov{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2147483600;opacity:0;pointer-events:none;transition:opacity .22s ease}
.cs-pop-ov.show{opacity:1;pointer-events:all}
.cs-pop{position:fixed;left:0;right:0;bottom:0;z-index:2147483601;background:var(--cs-bg);color:var(--cs-tx);
  border-top:1px solid var(--cs-line);max-height:84vh;max-height:84dvh;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:none;
  transform:translateY(100%);transition:transform .28s cubic-bezier(.32,.72,0,1);
  padding-bottom:max(.5em,env(safe-area-inset-bottom));box-shadow:0 -10px 40px rgba(0,0,0,.5);
  font:var(--cs-fs)/1.35 Poppins,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
.cs-pop::-webkit-scrollbar{display:none}
html.cs-open,html.cs-open body{overscroll-behavior-y:contain}
/* comments turned off for a post: the comment button on the feeds shows the same "chat-off" icon as the Upload page, and its number is hidden (it still opens the sheet) */
.comment-count[data-off="1"]{display:none !important}
.comment-btn .cm-off-ic{display:none}
.comment-btn.cm-off svg{display:none !important}
.comment-btn.cm-off .cm-off-ic{display:block;width:100%;height:100%;font-size:1.166em;line-height:1;text-align:center;font-style:normal;-webkit-text-stroke:0}
.cs-pop.show{transform:translateY(0)}
.cs-pop-handle{padding:.8em 0 .5em;cursor:pointer}
.cs-pop-handle i{display:block;width:2.5em;height:.27em;border-radius:99px;background:var(--cs-handle);margin:0 auto}
.cs-pop-name{padding:.1em 1.3em .8em;font-size:.9em;color:var(--cs-sub);border-bottom:1px solid var(--cs-line);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cs-pop-it{all:unset;box-sizing:border-box;display:flex;align-items:center;gap:1em;width:100%;padding:1em 1.3em;border-bottom:1px solid var(--cs-line);cursor:pointer;font-size:1.1em;color:var(--cs-tx);-webkit-tap-highlight-color:transparent}
.cs-pop-it i{font-size:1.35em;width:1.2em;text-align:center;flex:none;color:var(--cs-tx)}
.cs-pop-it:hover{background:var(--cs-chip)}
.cs-pop-it.danger,.cs-pop-it.danger i{color:var(--cs-red)}
@media (min-width:791px){
  .cs-pop{left:50%;right:auto;bottom:auto;top:50%;width:340px;max-height:80vh;border:1px solid var(--cs-line);
    transform:translate(-50%,-46%) scale(.96);opacity:0;pointer-events:none;transition:transform .25s ease,opacity .25s ease;padding-bottom:.3em}
  .cs-pop.show{transform:translate(-50%,-50%) scale(1);opacity:1;pointer-events:all}
  .cs-pop-handle{display:none}
  .cs-pop-name{padding-top:1em}
  .cs-pop-it{font-size:1em}
}

/* full-screen screens (translate picker · report · select text) */
.cs-scr{display:none;position:fixed;inset:0;z-index:2147483610;background:var(--cs-bg);justify-content:center;overflow:hidden;
  color:var(--cs-tx);font:var(--cs-fs)/1.35 Poppins,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
  opacity:0;transform:translateY(20px);transition:opacity .25s ease,transform .28s cubic-bezier(.22,1,.36,1)}
.cs-scr.open{opacity:1;transform:translateY(0)}
.cs-lane{width:100%;max-width:580px;height:100%;display:flex;flex-direction:column;min-height:0}
@media (min-width:801px){ .cs-lane{border-left:1px solid var(--cs-line);border-right:1px solid var(--cs-line)} }
.cs-scr-top{flex:none;display:flex;align-items:center;gap:.7em;min-height:3.7em;padding:env(safe-area-inset-top) .8em 0 .6em}
.cs-scr-x{all:unset;cursor:pointer;width:2.6em;height:2.6em;display:flex;align-items:center;justify-content:center;color:var(--cs-tx);flex:none}
.cs-scr-x:hover{color:var(--cs-red)}
.cs-scr-x svg{width:1.7em;height:1.7em;display:block}
.cs-scr-title{flex:1;min-width:0;font-weight:700;font-size:1.1em;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cs-scr-body{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.cs-scr-body::-webkit-scrollbar{display:none}
.cs-scr-foot{flex:none;display:flex;gap:.7em;padding:.7em 1.1em max(.9em,env(safe-area-inset-bottom));border-top:1px solid var(--cs-line)}
.cs-btn{all:unset;box-sizing:border-box;flex:1;height:2.9em;display:flex;align-items:center;justify-content:center;gap:.5em;border-radius:.4em;background:var(--cs-red);color:#fff;font-weight:700;cursor:pointer}
.cs-btn:active{transform:scale(.98)}
.cs-btn[disabled]{opacity:.4;cursor:default}

.cs-lsearch{position:relative;padding:.8em 1.1em;border-bottom:1px solid var(--cs-line);flex:none}
.cs-lsearch i{position:absolute;left:2em;top:50%;transform:translateY(-50%);color:var(--cs-sub);pointer-events:none}
.cs-lsearch input{box-sizing:border-box;width:100%;height:2.8em;padding:0 .9em 0 2.7em;border-radius:.45em;border:1px solid var(--cs-line);background:var(--cs-field);color:var(--cs-tx);font:inherit;outline:none;-webkit-appearance:none;appearance:none}
.cs-lsearch input::placeholder{color:var(--cs-sub)}
.cs-lsearch input:focus{border-color:var(--cs-red)}
@media (pointer:coarse){ .cs-lsearch input{font-size:16px} }
.cs-seclabel{padding:1em 1.1em .8em;font-size:.8em;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--cs-sub);border-bottom:1px solid var(--cs-line)}
.cs-lrow{display:flex;align-items:center;gap:.95em;padding:1.05em 1.1em;border-bottom:1px solid var(--cs-line);cursor:pointer;transition:background .2s;-webkit-tap-highlight-color:transparent}
.cs-lrow:hover,.cs-lrow.sel{background:rgba(255,45,45,.12);background:color-mix(in srgb,var(--cs-red) 12%,transparent)}
.cs-lrow .ic{font-size:1.4em;color:var(--cs-red);width:1.3em;text-align:center;flex:none}
.cs-lrow .nm{flex:1;min-width:0;font-weight:500}
.cs-lrow.sel .nm{font-weight:600}
.cs-lrow .en{font-size:.85em;font-weight:500;color:var(--cs-sub);flex:none;max-width:42%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cs-lrow .ck{width:1.6em;height:1.6em;border-radius:.4em;border:1.5px solid var(--cs-handle);display:flex;align-items:center;justify-content:center;font-size:.9em;font-weight:800;color:transparent;flex:none}
.cs-lrow.sel .ck{background:var(--cs-red);border-color:var(--cs-red);color:#fff}
.cs-lempty{padding:2.5em 1em;text-align:center;color:var(--cs-sub)}
.cs-seltxt{margin:0;padding:1.2em 1.1em;font-size:1.15em;line-height:1.6;white-space:pre-wrap;word-wrap:break-word;-webkit-user-select:text;user-select:text;-webkit-touch-callout:default}

/* image viewer: blurred copy of the picture behind, picture centred with rounded corners */
.cs-viewer{display:none;position:fixed;inset:0;z-index:2147483620;background:#0a0a0f;align-items:center;justify-content:center;overflow:hidden;
  opacity:0;transition:opacity .22s ease;touch-action:none}
.cs-viewer.open{opacity:1}
.cs-vbg{position:absolute;inset:-12%;background-size:cover;background-position:center;filter:blur(38px) brightness(.5) saturate(1.2);transform:scale(1.1)}
.cs-vimg{position:relative;max-width:min(94vw,900px);max-height:78vh;max-height:78dvh;border-radius:1.2em;object-fit:contain;
  box-shadow:0 14px 50px rgba(0,0,0,.55);transition:transform .25s ease;-webkit-user-select:none;user-select:none;-webkit-user-drag:none}
.cs-vx{all:unset;position:absolute;top:max(.9em,env(safe-area-inset-top));right:.9em;width:2.7em;height:2.7em;border-radius:50%;background:rgba(0,0,0,.45);
  color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:2;font-size:14px}
.cs-vx svg{width:1.5em;height:1.5em;display:block}

.cs-toast{position:fixed;left:50%;bottom:calc(2.2em + env(safe-area-inset-bottom));transform:translateX(-50%) translateY(10px);z-index:2147483630;
  background:rgba(20,22,40,.96);color:#fff;padding:.65em 1.2em;border-radius:.5em;font:500 13.5px/1.3 Poppins,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
  box-shadow:0 8px 28px rgba(0,0,0,.45);opacity:0;pointer-events:none;transition:opacity .2s ease,transform .2s ease;max-width:92vw;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cs-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

/* ═══ v3 ═══ */
/* "liked by author": the author's picture (red ring) with a red heart (black outline) on its corner, next to Reply */
.cs-act{align-items:center}
.cs-abadge{position:relative;display:inline-flex;width:1.75em;height:1.75em;flex:none}
.cs-abadge-av{display:block;width:100%;height:100%;box-sizing:border-box;border-radius:50%;object-fit:cover;border:2px solid var(--cs-red);background:var(--cs-chip)}
.cs-abadge-h{position:absolute;right:-.45em;bottom:-.35em;width:1em;height:1em;fill:var(--cs-red);stroke:#000;stroke-width:2.4;stroke-linejoin:round;paint-order:stroke fill;overflow:visible}

/* comment menu: the list scrolls, so every option can be reached on any screen height */
.cs-pop{display:flex;flex-direction:column;overflow:hidden;max-height:calc(100vh - 56px);max-height:calc(100dvh - 56px)}
.cs-pop-handle,.cs-pop-name{flex:none}
#csPopList{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;touch-action:pan-y;scrollbar-width:thin}
@media (max-height:700px){ .cs-pop-it{padding:.8em 1.3em} }
@media (min-width:791px){ .cs-pop{max-height:80vh} }

/* new comments / replies that arrive live flash softly */
@keyframes csIn{from{background:rgba(255,45,45,.16)}to{background:transparent}}
.cs-new{animation:csIn 1.8s ease}
/* touch screens: hold a comment to open its menu, so no text-selection / image callout on press */
@media (pointer:coarse){ .cs-c{-webkit-touch-callout:none;-webkit-user-select:none;user-select:none} }

/* picture viewer: zoom + pan */
.cs-vimg{touch-action:none;cursor:zoom-in;will-change:transform}
.cs-viewer.zoomed .cs-vimg{cursor:grab}

/* ═══ v4: the Replies screen (back arrow + X, the tapped comment pinned on top, its direct replies below) ═══ */
.cs-thead{padding:.15em .6em .5em .3em;gap:.2em}
.cs-thead .cs-title{flex:1;min-width:0}
.cs-tback,.cs-tx{all:unset;cursor:pointer;width:2.4em;height:2.4em;display:flex;align-items:center;justify-content:center;color:var(--cs-tx);flex:none;border-radius:50%;-webkit-tap-highlight-color:transparent}
.cs-tback:hover,.cs-tx:hover{background:var(--cs-chip)}
.cs-tback svg,.cs-tx svg{width:1.7em;height:1.7em;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.cs-troot{margin:0 -1.05em;padding:0 1.05em;background:var(--cs-field);border-bottom:1px solid var(--cs-line)}
.cs-troot:empty{display:none}
.cs-it.cs-root{padding:.95em 0 .6em}
/* ═══ v5 ═══ */
/* header: "Comments" and "Replies" are the same size; back arrow is exactly as big as the X */
.cs-tabs .cs-title{font-size:1.3em !important;font-weight:700}
.cs-thead{align-items:center}
.cs-tback,.cs-tx{width:2.7em !important;height:2.7em !important}
.cs-tback svg,.cs-tx svg{width:1.95em !important;height:1.95em !important;stroke-width:2.3}
/* action row:  Reply | 12 likes   Edit   (author badge) */
.cs-sep{margin:0 -.55em;opacity:.5}
.cs-lcnt{font-variant-numeric:tabular-nums}
.cs-edit{color:var(--cs-tx) !important;font-weight:600 !important}
.cs-edtag{opacity:.8}
.cs-edbox{margin-top:.4em}
.cs-edta{display:block;width:100%;box-sizing:border-box;resize:none;background:var(--cs-field);color:var(--cs-tx);border:1px solid var(--cs-line);border-radius:.8em;padding:.5em .7em;font:inherit;outline:0}
.cs-edbtns{display:flex;justify-content:flex-end;gap:.9em;margin-top:.35em;font-size:.95em}
.cs-edbtns button{all:unset;cursor:pointer;color:var(--cs-sub)}
.cs-edbtns button.sv{color:var(--cs-red);font-weight:700}
.cs-edbtns button[disabled]{opacity:.5}
@media (pointer:coarse){ .cs-edta{font-size:16px} }
/* ═══ v6 ═══ */
.cs-go{cursor:pointer}
.cs-act > button:first-child{font-weight:600}
.cs-lcnt{font-weight:600;color:var(--cs-tx)}
/* back arrow = the profile page's arrow: thin, tall chevron, no round button, turns red on hover */
.cs-tback{border-radius:0 !important;background:none !important}
.cs-tback:hover{background:none !important;color:var(--cs-red) !important}
.cs-tback svg.cs-chev{height:1.55em !important;width:auto !important;aspect-ratio:14/24;overflow:visible;fill:none;stroke-width:3}
/* comment menu: the index bottom sheet's layout, made 5% bigger, with its Cancel button */
.cs-pop{--cs-bg:rgba(12,12,28,.98);border-radius:0;border-top:1px solid rgba(255,255,255,.08);padding:9.24px 0 max(11.55px,env(safe-area-inset-bottom))}
[data-theme="light"] .cs-pop{--cs-bg:#fff;border-top-color:var(--cs-line)}
.cs-pop-name{display:none}
.cs-pop-handle{padding:0;margin:0 auto 4.6px}
.cs-pop-handle i{width:39.3px;height:4.04px;background:var(--cs-handle)}
.cs-pop .cs-pop-it{gap:12.7px;padding:10.4px 20.8px;font-size:15px;font-weight:500;line-height:1.3;border-bottom:1px solid rgba(255,255,255,.06)}
[data-theme="light"] .cs-pop .cs-pop-it{border-bottom-color:var(--cs-line)}
.cs-pop .cs-pop-it:last-child{border-bottom:none}
.cs-pop .cs-pop-it i{font-size:17.9px;width:20.8px;color:#f24455}
.cs-pop .cs-pop-it.danger,.cs-pop .cs-pop-it.danger i{color:#ff4c4c}
.cs-pop-cancel{all:unset;box-sizing:border-box;display:block;flex:none;text-align:center;cursor:pointer;margin:6.9px 13.9px 0;padding:11.55px;
  background:rgba(255,255,255,.06);border-radius:13.9px;color:rgba(255,255,255,.7);font:600 14.4px Poppins,sans-serif;-webkit-tap-highlight-color:transparent}
.cs-pop-cancel:active{background:rgba(255,255,255,.1)}
[data-theme="light"] .cs-pop-cancel{background:rgba(15,20,40,.06);color:rgba(20,23,31,.7)}
@media (min-width:791px){ .cs-pop{width:393px;max-width:393px} }
@media (max-width:790px){
  .cs-pop{padding-top:8.1px;padding-bottom:max(10.4px,env(safe-area-inset-bottom))}
  .cs-pop-handle i{width:37px}
  .cs-pop .cs-pop-it{padding:9.24px 18.5px;font-size:14.44px;gap:11.55px}
  .cs-pop .cs-pop-it i{font-size:17.3px;width:19.6px}
  .cs-pop-cancel{margin:5.8px 12.7px 0;padding:10.4px;font-size:13.86px;border-radius:12.7px}
}
@media (max-width:490px){
  .cs-pop{padding-top:6.93px;padding-bottom:max(9.24px,env(safe-area-inset-bottom))}
  .cs-pop-handle{margin-bottom:3.5px}.cs-pop-handle i{width:34.65px;height:3.47px}
  .cs-pop .cs-pop-it{padding:8.1px 16.2px;font-size:13.86px;gap:10.4px}
  .cs-pop .cs-pop-it i{font-size:16.2px;width:18.5px}
  .cs-pop-cancel{margin:5.8px 11.55px 0;padding:9.24px;font-size:13.28px;border-radius:11.55px}
}
@media (max-width:390px){
  .cs-pop{padding-bottom:max(8.1px,env(safe-area-inset-bottom))}
  .cs-pop-handle i{width:32.3px}
  .cs-pop .cs-pop-it{padding:7.5px 13.86px;font-size:13.28px;gap:9.24px}
  .cs-pop .cs-pop-it i{font-size:15.6px;width:17.3px}
  .cs-pop-cancel{margin:4.6px 10.4px 0;padding:8.1px;font-size:12.7px;border-radius:10.4px}
}
@media (max-width:330px){
  .cs-pop{padding-top:5.8px;padding-bottom:max(6.93px,env(safe-area-inset-bottom))}
  .cs-pop-handle i{width:27.7px;height:2.9px}
  .cs-pop .cs-pop-it{padding:6.35px 11.55px;font-size:12.13px;gap:8.1px}
  .cs-pop .cs-pop-it i{font-size:14.4px;width:15px}
  .cs-pop-cancel{margin:4.6px 9.24px 0;padding:6.93px;font-size:11.55px;border-radius:9.24px}
}
/* "Replies" sits in the middle of the bar, between the back arrow and the X */
.cs-thead .cs-title{text-align:center}

/* ═══════════════════════════════════════════════════════════════
   v7: Facebook-style reactions
   ① the reaction summary at the bottom right of every comment
   ② hold a comment → blurred screen with the reaction bar, the comment and the menu
   ③ tap the summary → bottom sheet with everyone who reacted
   ═══════════════════════════════════════════════════════════════ */
/* my reaction, shown on the right-hand button instead of the heart (when it is not the heart) */
.cs-like .cs-lemo{font-size:1.3em;line-height:1;min-width:0;font-weight:400;display:block}

/* ① summary: up to 3 overlapping emojis + the total, at the right end of the action row */
.cs-it{position:relative}
.cs-it > .cs-rxsum{all:unset;box-sizing:border-box;cursor:pointer;position:absolute;right:0;bottom:.2em;z-index:2;display:inline-flex;align-items:center;
  padding:.1em 0;color:var(--cs-sub);font-size:.92em;-webkit-tap-highlight-color:transparent}
.cs-it.cs-thread > .cs-rxsum{right:1.05em}
.cs-it.has-rx{padding-bottom:1.75em}
.cs-rxsum .e{display:inline-flex;align-items:center;justify-content:center;position:relative;width:1.6em;height:1.6em;border-radius:50%;
  background:var(--cs-bg);box-shadow:0 0 0 2px var(--cs-bg);font-size:.95em;line-height:1;margin-left:-.45em;font-style:normal}
.cs-rxsum .e:nth-child(1){margin-left:0;z-index:3}
.cs-rxsum .e:nth-child(2){z-index:2}
.cs-rxsum .e:nth-child(3){z-index:1}
.cs-rxsum .n{margin-left:.5em;font-weight:600;font-size:.98em;color:var(--cs-sub);font-variant-numeric:tabular-nums}

/* ② the hold-to-react screen (outside the sheet, so it carries its own colours) */
.cs-rx,.cs-rs,.cs-rs-ov{
  --cs-bg:#0b0c20; --cs-card:#1a1c36; --cs-tx:#f5f5f5; --cs-sub:rgba(255,255,255,.55); --cs-line:rgba(255,255,255,.1);
  --cs-field:rgba(255,255,255,.07); --cs-handle:rgba(255,255,255,.2); --cs-chip:rgba(255,255,255,.09);
  --cs-red:var(--red,#ff2d2d); --cs-fs:14.3px}
[data-theme="light"] .cs-rx,[data-theme="light"] .cs-rs,[data-theme="light"] .cs-rs-ov{
  --cs-bg:#ffffff; --cs-card:#ffffff; --cs-tx:#14171f; --cs-sub:rgba(20,23,31,.55); --cs-line:rgba(15,20,40,.1);
  --cs-field:rgba(15,20,40,.06); --cs-handle:rgba(15,20,40,.18); --cs-chip:rgba(15,20,40,.07)}
@media (max-width:790px){ .cs-rx,.cs-rs{--cs-fs:14px} }
@media (max-width:490px){ .cs-rx,.cs-rs{--cs-fs:13.5px} }
@media (max-width:390px){ .cs-rx,.cs-rs{--cs-fs:13px} }

.cs-rx{display:none;position:fixed;inset:0;z-index:2147483605;touch-action:none;-webkit-user-select:none;user-select:none;
  font:var(--cs-fs)/1.35 Poppins,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:var(--cs-tx)}
.cs-rx.open{display:block}
.cs-rx-bg{position:absolute;inset:0;background:rgba(0,0,0,.62);backdrop-filter:blur(9px);-webkit-backdrop-filter:blur(9px);opacity:0;transition:opacity .22s ease}
[data-theme="light"] .cs-rx-bg{background:rgba(20,24,40,.45)}
.cs-rx.show .cs-rx-bg{opacity:1}
.cs-rx-stack{position:absolute;display:flex;flex-direction:column;gap:10px;align-items:stretch;visibility:hidden}
.cs-rx.show .cs-rx-stack{visibility:visible}

/* the reaction bar */
.cs-rx-bar{align-self:flex-start;display:flex;align-items:center;justify-content:center;gap:.05em;padding:.3em .5em;border-radius:99px;
  background:var(--cs-card);box-shadow:0 8px 28px rgba(0,0,0,.45);border:1px solid var(--cs-line);
  transform-origin:left bottom;transform:translateY(14px) scale(.8);opacity:0;transition:transform .26s cubic-bezier(.3,1.5,.5,1),opacity .18s ease}
.cs-rx.show .cs-rx-bar{transform:none;opacity:1}
.cs-rx-b{all:unset;box-sizing:border-box;cursor:pointer;position:relative;font-size:2em;line-height:1;padding:.13em;border-radius:50%;
  opacity:0;transform:translateY(.6em) scale(.5);transition:transform .2s cubic-bezier(.3,1.6,.5,1),opacity .15s ease,background .15s ease;
  -webkit-tap-highlight-color:transparent;font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif}
.cs-rx.show .cs-rx-b{opacity:1;transform:none;transition-delay:calc(var(--i) * 24ms)}
.cs-rx.show .cs-rx-b:hover,.cs-rx.show .cs-rx-b:active{transform:scale(1.34) translateY(-.12em);transition-delay:0s}
.cs-rx-b.on{background:rgba(255,45,45,.2);background:color-mix(in srgb,var(--cs-red) 22%,transparent)}

/* the comment, lifted above the blur */
.cs-rx-card{--csav:2.4em;position:relative;display:flex;gap:.75em;padding:.85em .95em;border-radius:1em;background:var(--cs-card);color:var(--cs-tx);
  max-height:36vh;max-height:36dvh;overflow:hidden;box-shadow:0 8px 28px rgba(0,0,0,.4);border:1px solid var(--cs-line)}
.cs-rx-card.clip:after{content:"";position:absolute;left:0;right:0;bottom:0;height:2.6em;background:linear-gradient(to bottom,transparent,var(--cs-card));pointer-events:none}
.cs-rx-body{flex:1;min-width:0}
.cs-rx-h{display:flex;align-items:baseline;gap:.5em}
.cs-rx-n{font-weight:600}
.cs-rx-t{color:var(--cs-sub);font-size:.92em}
.cs-rx-tx{margin-top:.15em;white-space:pre-wrap;word-wrap:break-word}
.cs-rx-img{display:block;margin-top:.45em;max-width:9em;max-height:9em;border-radius:.7em}

/* the menu under the comment (4 options + More, like Facebook) */
.cs-rx-menu{align-self:flex-end;width:min(17.5em,100%);box-sizing:border-box;overflow-y:auto;overscroll-behavior:contain;touch-action:pan-y;scrollbar-width:none;
  background:var(--cs-card);border-radius:1em;padding:.3em 0;box-shadow:0 8px 28px rgba(0,0,0,.45);border:1px solid var(--cs-line);
  transform-origin:top right;transform:translateY(-10px) scale(.95);opacity:0;transition:transform .24s cubic-bezier(.3,1.3,.5,1) .04s,opacity .18s ease .04s}
.cs-rx-menu::-webkit-scrollbar{display:none}
.cs-rx.show .cs-rx-menu{transform:none;opacity:1}
.cs-rx-it{all:unset;box-sizing:border-box;display:flex;align-items:center;gap:1em;width:100%;padding:.8em 1.1em;cursor:pointer;color:var(--cs-tx);
  font-size:1.07em;font-weight:500;line-height:1.3;-webkit-tap-highlight-color:transparent}
.cs-rx-it i{font-size:1.4em;width:1.2em;text-align:center;flex:none;line-height:1}
.cs-rx-it:active{background:var(--cs-chip)}
.cs-rx-it.danger,.cs-rx-it.danger i{color:#ff4c4c}
.cs-rx-it svg{width:1.3em;height:1.3em;margin-left:auto;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.cs-rx-extra{display:none}
.cs-rx-menu.more .cs-rx-extra{display:block}

/* ③ the reactions bottom sheet */
.cs-rs-ov{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2147483606;opacity:0;pointer-events:none;transition:opacity .22s ease}
.cs-rs-ov.show{opacity:1;pointer-events:all}
.cs-rs{position:fixed;left:0;right:0;bottom:0;height:68vh;height:68dvh;z-index:2147483607;background:var(--cs-bg);color:var(--cs-tx);
  border-top:1px solid var(--cs-line);display:flex;flex-direction:column;overflow:hidden;
  transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1);box-shadow:0 -10px 40px rgba(0,0,0,.5);
  font:var(--cs-fs)/1.35 Poppins,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
.cs-rs.show{transform:translateY(0)}
/* closed sheets wait just below the screen: their upward shadow was bleeding onto the bottom of the page and dimming the caption, dashes and progress bar */
.cs-sheet:not(.show),.cs-pop:not(.show),.cs-rs:not(.show){box-shadow:none !important}
.cs-rs-handle{flex:none;padding:.9em 0 .5em;cursor:pointer;touch-action:none}
.cs-rs-handle i{display:block;width:2.5em;height:.27em;border-radius:99px;background:var(--cs-handle);margin:0 auto}
.cs-rs-head{flex:none;position:relative;padding:.2em 1.2em .75em;border-bottom:1px solid var(--cs-line)}
.cs-rs-filter{all:unset;cursor:pointer;display:inline-flex;align-items:center;gap:.4em;font-weight:600;font-size:1.08em;padding:.35em 0;-webkit-tap-highlight-color:transparent}
.cs-rs-filter svg{width:1.1em;height:1.1em;fill:none;stroke:currentColor;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round;transition:transform .2s ease}
.cs-rs-filter.open svg{transform:rotate(180deg)}
.cs-rs-menu{display:none;position:absolute;left:1em;top:calc(100% - .35em);z-index:3;min-width:13em;max-height:50vh;overflow-y:auto;
  background:var(--cs-card);border:1px solid var(--cs-line);box-shadow:0 10px 30px rgba(0,0,0,.45);padding:.3em 0}
.cs-rs-menu.show{display:block}
.cs-rs-mi{all:unset;box-sizing:border-box;display:flex;align-items:center;gap:.75em;width:100%;padding:.75em 1.1em;cursor:pointer;color:var(--cs-tx);font-size:1.02em}
.cs-rs-mi:hover{background:var(--cs-chip)}
.cs-rs-mi .em{font-size:1.25em;line-height:1;width:1.3em;text-align:center;flex:none}
.cs-rs-mi .c{margin-left:auto;color:var(--cs-sub);font-variant-numeric:tabular-nums}
.cs-rs-mi.on{color:var(--cs-red);font-weight:700}
.cs-rs-list{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;padding:.3em 0 max(.8em,env(safe-area-inset-bottom))}

.cs-rs-row{display:flex;align-items:center;gap:1em;padding:.6em 1.2em;cursor:pointer;-webkit-tap-highlight-color:transparent}
.cs-rs-row:active{background:var(--cs-chip)}
.cs-rs-av{--csav:3.2em;position:relative;flex:none;display:flex}
.cs-rs-badge{position:absolute;right:-.35em;bottom:-.3em;width:1.55em;height:1.55em;border-radius:50%;background:var(--cs-bg);box-shadow:0 0 0 2px var(--cs-bg);
  display:flex;align-items:center;justify-content:center;font-size:.9em;line-height:1;font-style:normal}
.cs-rs-nm{flex:1;min-width:0;font-size:1.12em;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media (min-width:791px){
  .cs-rs{left:50%;right:auto;bottom:auto;top:50%;width:420px;height:min(70vh,620px);border:1px solid var(--cs-line);
    transform:translate(-50%,-46%) scale(.96);opacity:0;pointer-events:none;transition:transform .25s ease,opacity .25s ease}
  .cs-rs.show{transform:translate(-50%,-50%) scale(1);opacity:1;pointer-events:all}
  .cs-rs-handle{display:none}
  .cs-rs-head{padding-top:1em}
}

/* ═══════════════════════════════════════════════════════════════
   v8: save comment · pin comment · 🥇 first badge · translate all
   ═══════════════════════════════════════════════════════════════ */
/* bookmark button in the header, left of the sort menu button */
#csTabs{padding-right:6.8em}
.cs-savedbtn{all:unset;cursor:pointer;position:absolute;right:3.7em;top:50%;transform:translateY(-62%);color:var(--cs-tx);display:flex;padding:.3em;-webkit-tap-highlight-color:transparent}
.cs-savedbtn svg{width:1.6em;height:1.6em;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.cs-savedbtn:hover{color:var(--cs-red)}

/* "Translate all comments" row at the bottom of the sort menu */
.cs-menu button.cs-trall{display:flex;align-items:center;justify-content:space-between;gap:1.4em;border-top:1px solid var(--cs-line);margin-top:.3em}
.cs-menu button.cs-trall b{font-weight:600;color:var(--cs-sub);font-size:.92em;white-space:nowrap}
.cs-menu button.cs-trall.on b{color:var(--cs-red)}

/* pinned comment + first comment badge (in the name line) */
.cs-pin{display:inline-flex;align-items:center;gap:.25em;color:var(--cs-red);font-weight:600}
.cs-pin i{font-size:1.05em}
.cs-first{display:inline-flex;align-items:center;padding:.08em .55em;border-radius:3px;font-weight:600;font-size:.88em;line-height:1.5;
  color:var(--cs-tx);background:rgba(150,155,170,.26)}
.cs-it.is-pinned{background:rgba(255,45,45,.05);background:color-mix(in srgb,var(--cs-red) 6%,transparent)}

/* saved comments screen */
.cs-sv-row{display:flex;gap:.85em;align-items:flex-start;padding:1em 1.1em;border-bottom:1px solid var(--cs-line);cursor:pointer;-webkit-tap-highlight-color:transparent}
.cs-sv-row:active{background:var(--cs-chip)}
.cs-sv-body{flex:1;min-width:0}
.cs-sv-h{display:flex;align-items:baseline;gap:.5em}
.cs-sv-n{font-weight:600}
.cs-sv-t{color:var(--cs-sub);font-size:.92em}
.cs-sv-tx{margin-top:.2em;white-space:pre-wrap;word-wrap:break-word;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
.cs-sv-img{display:block;margin-top:.45em;max-width:8em;max-height:8em;border-radius:.7em}
.cs-sv-go{margin-top:.5em;color:var(--cs-red);font-weight:600;font-size:.92em}
.cs-sv-un{all:unset;cursor:pointer;color:var(--cs-red);display:flex;padding:.2em;flex:none;-webkit-tap-highlight-color:transparent}
.cs-sv-un svg{width:1.5em;height:1.5em;fill:currentColor;stroke:currentColor;stroke-width:2;stroke-linejoin:round}

/* retry button, keyboard focus and reduced motion */
.cs-failbox{ text-align:center; }
.cs-retry{ margin-top:10px; border:1px solid rgba(var(--fg,255,255,255),.28); background:none; color:inherit; font-weight:600; font-size:.8rem; font-family:inherit; padding:9px 18px; border-radius:999px; }
.cs-retry:active{ transform:scale(.95); }
#csSheet :is(button, a, [role="button"]):focus-visible{ outline:2px solid currentColor; outline-offset:2px; }
@media (prefers-reduced-motion:reduce){
  #csSheet, #csSheet *, #csSheet *::before, #csSheet *::after, #csRx *, #csPop *{ animation-duration:.001ms !important; animation-iteration-count:1 !important; transition-duration:.001ms !important; scroll-behavior:auto !important; }
}
</style>

<div class="cs-overlay" id="csOverlay"></div>
<div class="cs-sheet" id="csSheet" role="dialog" aria-label="Comments">
  <div class="cs-handle" id="csHandle"><i></i></div>

  <!-- list screen header -->
  <div class="cs-tabs" id="csTabs"><span class="cs-title">Comments</span>
    <button type="button" class="cs-tx cs-closex" id="csCloseX" aria-label="Close comments"><svg viewBox="0 0 24 24"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
    <button type="button" class="cs-savedbtn" id="csSavedBtn" aria-label="Saved comments"><svg viewBox="0 0 24 24"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/></svg></button>
    <button type="button" class="cs-menubtn" id="csMenuBtn" aria-label="Sort comments"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h11M4 17h6"/></svg></button>
    <div class="cs-menu" id="csMenu">
      <button type="button" data-sort="relevant" class="on">Relevant</button><button type="button" data-sort="newest">Newest</button><button type="button" data-sort="oldest">Oldest</button>
      <button type="button" data-sort="liked">Most liked</button><button type="button" data-sort="replied">Most replied</button><button type="button" data-sort="tracking">Tracking</button>
      <button type="button" class="cs-trall" id="csTrAll"><span>Translate all comments</span><b id="csTrAllState">Off</b></button>
    </div>
  </div>

  <!-- replies screen header -->
  <div class="cs-tabs cs-thead" id="csTHead" style="display:none;">
    <button type="button" class="cs-tback" id="csTBack" aria-label="Back to comments"><svg class="cs-chev" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="miter" stroke-linecap="butt"/></svg></button>
    <span class="cs-title">Replies</span>
    <button type="button" class="cs-tx" id="csTX" aria-label="Close comments"><svg viewBox="0 0 24 24"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
  </div>

  <div class="cs-list" id="csList"></div>

  <!-- replies screen body: the tapped comment pinned at the top, then its direct replies -->
  <div class="cs-list cs-tscroll" id="csTScroll" style="display:none;">
    <div class="cs-troot" id="csTRoot"></div>
    <div class="cs-titems" id="csTItems"></div>
    <div class="cs-load" id="csTLoad" style="display:none;">Loading…</div>
  </div>

  <div class="cs-bar">
    <div class="cs-emo" id="csEmo"><span>❤️</span><span>🙌</span><span>🔥</span><span>👏</span><span>😢</span><span>😍</span><span>😮</span><span>😂</span></div>
    <div class="cs-note" id="csNote"><span id="csNoteTxt"></span><b id="csNoteX">✕</b></div>
    <div class="cs-prev" id="csPrev"><img id="csPrevImg" alt=""><b id="csPrevX">✕</b></div>
    <div class="cs-in">
      <div class="cs-av" id="csMeAv"></div>
      <div class="cs-box">
        <div class="cs-inw"><input type="text" id="csText" maxlength="1000" placeholder="What do you think of this?" autocomplete="off"></div>
        <label class="cs-ic" title="Image"><input type="file" id="csFile" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
          <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg></label>
        <button class="cs-post" id="csPost" disabled aria-label="Post"><svg viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M236.2,218.31A15.88,15.88,0,0,1,224,224a16.22,16.22,0,0,1-5.37-.92l-79.95-27a4,4,0,0,1-2.72-3.79V120a8,8,0,0,0-8.53-8,8.19,8.19,0,0,0-7.47,8.26v72a4,4,0,0,1-2.72,3.79l-79.95,27a16,16,0,0,1-19.26-22.92L114,32.13a16,16,0,0,1,27.89,0L237.9,200.1A15.89,15.89,0,0,1,236.2,218.31Z"/></svg></button>
      </div>
    </div>
  </div>
</div>

<!-- comment menu (three dots) -->
<div class="cs-pop-ov" id="csPopOv"></div>
<div class="cs-pop" id="csPop" role="menu" aria-label="Comment options">
  <div class="cs-pop-handle" id="csPopHandle"><i></i></div>
  <div class="cs-pop-name" id="csPopName"></div>
  <div id="csPopList"></div>
  <button type="button" class="cs-pop-cancel" id="csPopCancel">Cancel</button>
</div>

<!-- hold a comment: reaction bar + the comment + menu (blurred background) -->
<div class="cs-rx" id="csRx" role="dialog" aria-modal="true" aria-label="React to this comment">
  <div class="cs-rx-bg" id="csRxBg"></div>
  <div class="cs-rx-stack" id="csRxStack">
    <div class="cs-rx-bar" id="csRxBar"></div>
    <div class="cs-rx-card" id="csRxCard"></div>
    <div class="cs-rx-menu" id="csRxMenu" role="menu"></div>
  </div>
</div>

<!-- everyone who reacted (bottom sheet) -->
<div class="cs-rs-ov" id="csRsOv"></div>
<div class="cs-rs" id="csRs" role="dialog" aria-modal="true" aria-label="Reactions">
  <div class="cs-rs-handle" id="csRsHandle"><i></i></div>
  <div class="cs-rs-head">
    <button type="button" class="cs-rs-filter" id="csRsFilter"><span id="csRsFilterTxt">All reactions</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
    <div class="cs-rs-menu" id="csRsMenu"></div>
  </div>
  <div class="cs-rs-list" id="csRsList"></div>
</div>

<!-- translate: pick your language -->
<div class="cs-scr notranslate" id="csLang" translate="no" role="dialog" aria-modal="true" aria-label="Translate this comment">
  <div class="cs-lane">
    <div class="cs-scr-top">
      <button type="button" class="cs-scr-x" id="csLangX" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="cs-scr-title" id="csLangTitle">Translate this comment</div>
    </div>
    <div class="cs-lsearch"><i class="ri-search-line"></i><input type="text" id="csLangQ" placeholder="Search your language…" autocomplete="off"></div>
    <div class="cs-scr-body" id="csLangBody">
      <div class="cs-seclabel">Translate into</div>
      <div id="csLangList"></div>
    </div>
  </div>
</div>

<!-- saved comments -->
<div class="cs-scr" id="csSaved" role="dialog" aria-modal="true" aria-label="Saved comments">
  <div class="cs-lane">
    <div class="cs-scr-top">
      <button type="button" class="cs-scr-x" id="csSavedX" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="cs-scr-title">Saved comments</div>
    </div>
    <div class="cs-scr-body" id="csSavedBody"><div id="csSavedList"></div></div>
  </div>
</div>

<!-- report a comment -->
<div class="cs-scr" id="csRep" role="dialog" aria-modal="true" aria-label="Report comment">
  <div class="cs-lane">
    <div class="cs-scr-top">
      <button type="button" class="cs-scr-x" id="csRepX" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="cs-scr-title">Report comment</div>
    </div>
    <div class="cs-scr-body">
      <div class="cs-seclabel">Why are you reporting this?</div>
      <div id="csRepList"></div>
    </div>
    <div class="cs-scr-foot"><button type="button" class="cs-btn" id="csRepSend" disabled>Submit report</button></div>
  </div>
</div>

<!-- select text -->
<div class="cs-scr" id="csSel" role="dialog" aria-modal="true" aria-label="Select text">
  <div class="cs-lane">
    <div class="cs-scr-top">
      <button type="button" class="cs-scr-x" id="csSelX" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
      <div class="cs-scr-title">Select text</div>
    </div>
    <div class="cs-scr-body"><div class="cs-seltxt" id="csSelTxt"></div></div>
    <div class="cs-scr-foot"><button type="button" class="cs-btn" id="csSelCopy"><i class="ri-file-copy-line"></i> Copy</button></div>
  </div>
</div>

<!-- picture viewer -->
<div class="cs-viewer" id="csViewer" role="dialog" aria-modal="true" aria-label="Picture">
  <div class="cs-vbg" id="csVBg"></div>
  <button type="button" class="cs-vx" id="csVX" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>
  <img class="cs-vimg" id="csVImg" alt="">
</div>
<div class="cs-toast" id="csToast" aria-live="polite"></div>

<script nonce="<?= $CS_NONCE ?>">
window.CommentSection = (() => {
  const API = <?= json_encode($CS_URL) ?>, LOGIN = <?= json_encode($CS_LOGIN) ?>;
  const $ = id => document.getElementById(id);
  const hp = k => { try { if (window.dzHaptic && dzHaptic[k]) dzHaptic[k](); } catch (x) {} };   // haptics (the engine lives in zeems_modal.php; silent when absent or switched off)
  const PROF = API.replace(/comment_section\.php.*$/, '') + 'profile.php?user_id=';   // picture / name → that person's profile
  const goProfile = (e, id) => { if (e) e.stopPropagation(); location.href = PROF + id; };
  const HEART = '<svg viewBox="0 0 24 24"><path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 2.7 4.5 6.3 4.5c2 0 3.7 1.1 5.7 3.3 2-2.2 3.7-3.3 5.7-3.3 3.6 0 5.4 3.9 3.9 7.3C19.5 16.4 12 21 12 21z"/></svg>';
  const DOTS = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/></svg>';
  const CHEV = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
  const CHEV_DOWN = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
  /* the 8 reactions (same emojis as the comment box). "like" is the red heart. */
  const REACTS = ['like', 'pray', 'fire', 'clap', 'cry', 'love', 'wow', 'haha'];
  const EMO = {like: '❤️', pray: '🙌', fire: '🔥', clap: '👏', cry: '😢', love: '😍', wow: '😮', haha: '😂'};
  let cfg = null, S = {}, phTimer = 0, TOK = 0;
  let ovSaved = false, prevOv = '', ovY = 0;   // the page's own body scroll lock + where the page was scrolled, restored on close
  const FOL = {};   // userId -> do I track this person? (filled from every comment the server sends)
  const RX = {};    // comment id -> {my_react, likes, reacts, react_counts} (the latest the browser knows)
  const SAV = {};   // comment id -> did I save it?
  const TA = {code: '', tok: 0};   // "Translate all comments": the language it translates into ('' = off)
  const TA_KEY = 'cs_trall_v1';
  const BOOKMARK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/></svg>';
  const reset = () => S = {meme: 0, page: 1, sort: 'relevant', more: false, busy: false, parent: 0, replyName: '', media: null, header: null, since: 0, total: null, ready: false, pin: null, dirty: false,
                           view: 'list', t: null, seen: new Set(), tok: ++TOK};
  reset();

  async function api(action, {params = {}, body = null} = {}) {
    const u = new URL(API, location.origin);
    u.searchParams.set('action', action);
    for (const k in params) u.searchParams.set(k, params[k]);
    const opt = {credentials: 'same-origin'};
    if (body) { opt.method = 'POST'; opt.body = body; opt.headers = {'X-CSRF': cfg.csrf}; }
    let r;
    try { r = await fetch(u, opt); }
    catch (x) { throw new Error(navigator.onLine === false ? "You're offline. Check your connection and try again." : 'Connection problem. Please try again.'); }
    const j = await r.json().catch(() => ({}));
    if (r.status === 401) { location.href = LOGIN; throw new Error('login'); }
    if (!r.ok) throw new Error(j.error || 'Something went wrong');
    return j;
  }
  const vbEl = () => { const v = document.createElement('span'); v.className = 'cs-vb'; v.innerHTML = VBSVG; return v; };
  const VBSVG = '<svg viewBox="0 0 256 256" fill="currentColor" aria-label="Verified"><path fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></svg>';
  const el = (tag, cls, txt) => { const e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; };
  const avatar = (url, name) => {
    /* empty / folder-only / broken picture -> the default mee.jpg (Dezzme root) */
    if (!url || /\/$/.test(String(url)) || /(^|\/)(null|undefined|default[^\/]*)$/i.test(String(url))) url = 'mee.jpg';
    const i = el('img', 'cs-av'); i.alt = ''; i.loading = 'lazy';
    i.onerror = () => { i.onerror = null; i.src = 'mee.jpg'; };
    i.src = url; return i;
  };
  const fd = o => { const f = new FormData(); for (const k in o) f.append(k, o[k]); return f; };
  const fmt = n => n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? (n / 1e3).toFixed(1).replace('.0', '') + 'K' : String(n);

  /* tell the page a post's comment total changed (the Zeems rail button listens for this) */
  function changed(meme, delta) { if (S.total != null) S.total += delta; try { if (S.header && S.header.onComment) S.header.onComment(delta); } catch (e) {} try { window.dispatchEvent(new CustomEvent('cs:changed', {detail: {meme: +meme, delta: delta}})); } catch (e) {} }

  /* ───────────── small UI helpers: toast · screens · picture viewer ───────────── */
  let toastT = 0;
  addEventListener('offline', () => { if (document.getElementById('csSheet') && $('csSheet').classList.contains('show')) toast("You're offline"); });
  addEventListener('online', () => {
    if (!$('csSheet') || !$('csSheet').classList.contains('show')) return;
    toast('Back online');
    const b = document.querySelector('#csList .cs-retry'); if (b) b.click();                    // retry whatever failed while offline
  });
  function toast(m) { const t = $('csToast'); t.textContent = m; t.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), 2400); }

  const SCR = ['csLang', 'csSel', 'csRep', 'csSaved'];
  function scrOpen(id) { const e = $(id); e.style.display = 'flex'; requestAnimationFrame(() => requestAnimationFrame(() => e.classList.add('open'))); }
  function scrClose(id) { const e = $(id); e.classList.remove('open'); setTimeout(() => { if (!e.classList.contains('open')) e.style.display = 'none'; }, 270); }

  const VZ = {s: 1, x: 0, y: 0, ptrs: new Map(), g: null, last: {t: 0, x: 0, y: 0}};
  function vApply(anim) {
    const im = $('csVImg');
    im.style.transition = anim ? 'transform .22s ease' : 'none';
    im.style.transform = 'translate(' + VZ.x + 'px,' + VZ.y + 'px) scale(' + VZ.s + ')';
    $('csViewer').classList.toggle('zoomed', VZ.s > 1.01);
  }
  function vReset() { VZ.s = 1; VZ.x = 0; VZ.y = 0; VZ.ptrs.clear(); VZ.g = null; VZ.last = {t: 0, x: 0, y: 0}; vApply(false); }
  function vClamp() {
    const im = $('csVImg'), w = im.offsetWidth * VZ.s, h = im.offsetHeight * VZ.s;
    const mx = w > innerWidth ? (w - innerWidth) / 2 + 40 : 0, my = h > innerHeight ? (h - innerHeight) / 2 + 40 : 0;
    VZ.x = Math.max(-mx, Math.min(mx, VZ.x)); VZ.y = Math.max(-my, Math.min(my, VZ.y));
  }
  function vZoomAt(px, py, ns) {
    ns = Math.max(1, Math.min(6, ns));
    const fx = px - innerWidth / 2, fy = py - innerHeight / 2, r = ns / VZ.s;
    VZ.x = fx - (fx - VZ.x) * r; VZ.y = fy - (fy - VZ.y) * r; VZ.s = ns;
    if (ns <= 1.001) { VZ.s = 1; VZ.x = 0; VZ.y = 0; }
    vClamp();
  }
  function openViewer(url) {
    const v = $('csViewer'), im = $('csVImg');
    vReset(); v.style.opacity = '';
    im.src = url;
    $('csVBg').style.backgroundImage = 'url("' + String(url).replace(/"/g, '%22') + '")';
    v.style.display = 'flex';
    requestAnimationFrame(() => requestAnimationFrame(() => v.classList.add('open')));
  }
  function closeViewer() {
    const v = $('csViewer');
    v.style.opacity = ''; v.classList.remove('open');
    setTimeout(() => { if (!v.classList.contains('open')) { v.style.display = 'none'; $('csVImg').removeAttribute('src'); } }, 230);
  }

  /* closes the top-most extra layer; returns true if it closed something (used by Esc) */
  function closeTop() {
    if ($('csViewer').classList.contains('open')) { closeViewer(); return true; }
    for (const id of SCR) if ($(id).classList.contains('open')) { scrClose(id); return true; }
    if ($('csRs').classList.contains('show')) { rsClose(); return true; }
    if ($('csRx').classList.contains('show')) { rxClose(); return true; }
    if ($('csPop').classList.contains('show')) { popClose(); return true; }
    if (S.view === 'thread') { closeThread(); return true; }          // Esc inside the Replies screen = back
    return false;
  }
  function closeLayers() {
    popClose(); rxClose(); rsClose();
    SCR.forEach(id => { if ($(id).classList.contains('open')) scrClose(id); });
    if ($('csViewer').classList.contains('open')) closeViewer();
  }

  /* ───────────── hide / spam (saved on the account, so it follows you to every device) ───────────── */
  const HID_KEY = 'cs_hidden_v1';
  const HID = {};                                                   // comment id → 'h' | 's' (filled from the server with every comment)
  function markHid(row, c, kind, persist) {
    row.classList.add('hid');
    if (row.parentElement) row.parentElement.classList.add('hid');
    const note = row.querySelector(':scope > .cs-hidnote');
    if (note) note._txt.textContent = kind === 's' ? 'Marked as spam' : 'Comment hidden';
    HID[c.id] = kind;
    if (persist && cfg && cfg.me) api('hide', {body: fd({comment_id: c.id, kind: kind})}).catch(e => { if (e.message !== 'login') toast(e.message); });
  }
  function unHid(row, c) {
    row.classList.remove('hid'); if (row.parentElement) row.parentElement.classList.remove('hid'); delete HID[c.id];
    if (cfg && cfg.me) api('unhide', {body: fd({comment_id: c.id})}).catch(e => { if (e.message !== 'login') toast(e.message); });
  }
  /* what an older version kept in this browser is sent to the account once, then removed */
  async function migrateHid() {
    let old = {};
    try { old = JSON.parse(localStorage.getItem(HID_KEY) || '{}') || {}; } catch (e) {}
    if (!cfg || !cfg.me || !Object.keys(old).length) return;
    try { await api('hide_sync', {body: fd({map: JSON.stringify(old)})}); localStorage.removeItem(HID_KEY); } catch (e) {}
  }

  /* ───────────── translate (same engine + language list as Memepedia) ───────────── */
  const TR_LANGS = [['en','English','English'],['fr','French','Français'],['es','Spanish','Español'],['pt','Portuguese','Português'],['de','German','Deutsch'],['it','Italian','Italiano'],['nl','Dutch','Nederlands'],['ar','Arabic','العربية'],['zh-CN','Chinese (Simplified)','简体中文'],['zh-TW','Chinese (Traditional)','繁體中文'],['hi','Hindi','हिन्दी'],['bn','Bengali','বাংলা'],['ur','Urdu','اردو'],['ru','Russian','Русский'],['uk','Ukrainian','Українська'],['ja','Japanese','日本語'],['ko','Korean','한국어'],['tr','Turkish','Türkçe'],['vi','Vietnamese','Tiếng Việt'],['th','Thai','ไทย'],['id','Indonesian','Bahasa Indonesia'],['ms','Malay','Bahasa Melayu'],['tl','Filipino','Filipino'],['sw','Swahili','Kiswahili'],['yo','Yoruba','Yorùbá'],['ig','Igbo','Igbo'],['ha','Hausa','Hausa'],['zu','Zulu','isiZulu'],['xh','Xhosa','isiXhosa'],['af','Afrikaans','Afrikaans'],['am','Amharic','አማርኛ'],['so','Somali','Soomaali'],['rw','Kinyarwanda','Kinyarwanda'],['mg','Malagasy','Malagasy'],['pl','Polish','Polski'],['cs','Czech','Čeština'],['sk','Slovak','Slovenčina'],['hu','Hungarian','Magyar'],['ro','Romanian','Română'],['bg','Bulgarian','Български'],['el','Greek','Ελληνικά'],['sr','Serbian','Српски'],['hr','Croatian','Hrvatski'],['sl','Slovenian','Slovenščina'],['sv','Swedish','Svenska'],['no','Norwegian','Norsk'],['da','Danish','Dansk'],['fi','Finnish','Suomi'],['is','Icelandic','Íslenska'],['et','Estonian','Eesti'],['lv','Latvian','Latviešu'],['lt','Lithuanian','Lietuvių'],['he','Hebrew','עברית'],['fa','Persian','فارسی'],['ta','Tamil','தமிழ்'],['te','Telugu','తెలుగు'],['ml','Malayalam','മലയാളം'],['kn','Kannada','ಕನ್ನಡ'],['mr','Marathi','मराठी'],['gu','Gujarati','ગુજરાતી'],['pa','Punjabi','ਪੰਜਾਬੀ'],['ne','Nepali','नेपाली'],['si','Sinhala','සිංහල'],['my','Burmese','မြန်မာ'],['km','Khmer','ខ្មែរ'],['lo','Lao','ລາວ'],['ka','Georgian','ქართული'],['hy','Armenian','Հայերեն'],['az','Azerbaijani','Azərbaycan'],['kk','Kazakh','Қазақша'],['uz','Uzbek','Oʻzbek'],['mn','Mongolian','Монгол'],['ht','Haitian Creole','Kreyòl ayisyen'],['ca','Catalan','Català'],['gl','Galician','Galego'],['eu','Basque','Euskara'],['cy','Welsh','Cymraeg'],['ga','Irish','Gaeilge'],['sq','Albanian','Shqip'],['mk','Macedonian','Македонски'],['bs','Bosnian','Bosanski'],['be','Belarusian','Беларуская'],['mt','Maltese','Malti'],['la','Latin','Latina']];
  const trNative = code => { const L = TR_LANGS.find(x => x[0] === code); return L ? L[2] : code; };
  const trCache = new Map();

  /* split long text into pieces the translator accepts, keeping sentence and line breaks */
  function trChunks(text, max) {
    if (text.length <= max) return [text];
    const parts = text.match(/[^.!?。！？\n]+[.!?。！？]*\s*|\n+/g) || [text];
    const out = []; let cur = '';
    parts.forEach(p => {
      if (cur && (cur + p).length > max) { out.push(cur); cur = ''; }
      while (p.length > max) { out.push(p.slice(0, max)); p = p.slice(max); }
      cur += p;
    });
    if (cur) out.push(cur);
    return out;
  }
  async function trFetch(text, code) {
    const base = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&dt=t&tl=' + encodeURIComponent(code);
    let res;
    try {
      res = await fetch(base, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'}, body: 'q=' + encodeURIComponent(text)});
      if (!res.ok) throw new Error('post');
    } catch (e) {
      res = await fetch(base + '&q=' + encodeURIComponent(text));
      if (!res.ok) throw new Error('translate');
    }
    const d = await res.json();
    return (d && d[0] ? d[0] : []).map(s => (s && s[0]) || '').join('');
  }
  async function trText(text, code) {
    const key = code + '\u0001' + text;
    if (trCache.has(key)) return trCache.get(key);
    const out = [];
    for (const p of trChunks(text, 1400)) out.push(p.trim() ? await trFetch(p, code) : p);
    const res = out.join('');
    trCache.set(key, res);
    return res;
  }

  const textOf = body => Array.from(body.children).find(x => x.classList.contains('cs-text'));
  function trBarOf(body) {
    if (body._trBar) return body._trBar;
    const b = el('div', 'cs-trbar');
    b.innerHTML = '<i class="ri-translate-2"></i><span></span><button type="button">Show original</button>';
    const t = textOf(body); if (t) t.after(b);
    b._sp = b.querySelector('span'); b._bt = b.querySelector('button');
    b._bt.onclick = () => trOriginal(body);
    body._trBar = b;
    return b;
  }
  function trOriginal(body) {
    const st = body._tr; if (!st) return;
    body._trTok = (body._trTok || 0) + 1;
    const t = textOf(body); if (t) { fillText(t, st.orig); t.removeAttribute('dir'); }
    st.code = '';
    if (body._trBar) body._trBar.classList.remove('show');
  }
  let trActive = 0; const trWait = [];                               // at most 4 translations at the same time ("Translate all" can ask for many)
  async function trSlot(fn) {
    if (trActive >= 4) await new Promise(r => trWait.push(r));
    trActive++;
    try { return await fn(); } finally { trActive--; const n = trWait.shift(); if (n) n(); }
  }
  /* quiet = used by "Translate all comments": no "Translated to…" line under every comment, no error toast per comment */
  async function trApply(body, code, quiet) {
    const st = body._tr, t = textOf(body);
    if (!st || !t || !st.orig.trim()) { if (!quiet) toast('Nothing to translate'); return; }
    const tok = body._trTok = (body._trTok || 0) + 1;
    const bar = quiet ? null : trBarOf(body);
    if (bar) { bar._sp.textContent = 'Translating…'; bar._bt.style.display = 'none'; bar.classList.add('show'); }
    else if (body._trBar) body._trBar.classList.remove('show');
    try {
      const out = await trSlot(() => trText(st.orig, code));
      if (tok !== body._trTok) return;
      fillText(t, out); t.setAttribute('dir', 'auto'); st.code = code;
      if (bar) { bar._sp.textContent = 'Translated to ' + trNative(code); bar._bt.style.display = ''; }
    } catch (e) {
      if (tok !== body._trTok) return;
      fillText(t, st.orig); t.removeAttribute('dir'); st.code = '';
      if (bar) { bar.classList.remove('show'); toast('Could not translate right now — check your connection'); }
    }
  }

  /* ── Translate all comments: every comment on screen (and every new one) is translated into the chosen language ── */
  function trAllUi() {
    const b = $('csTrAll'); if (!b) return;
    b.classList.toggle('on', !!TA.code);
    $('csTrAllState').textContent = TA.code ? trNative(TA.code) : 'Off';
  }
  function trAllEach(fn) { document.querySelectorAll('#csSheet .cs-body').forEach(b => { if (b._tr) fn(b); }); }
  function trAllOn(code) {
    TA.code = code; TA.tok++;
    try { localStorage.setItem(TA_KEY, code); } catch (e) {}
    trAllUi();
    trAllEach(b => { if (b._tr.orig.trim()) trApply(b, code, true); });
    toast('Translating comments to ' + trNative(code));
  }
  function trAllOff() {
    TA.code = ''; TA.tok++;
    try { localStorage.removeItem(TA_KEY); } catch (e) {}
    trAllUi();
    trAllEach(b => { if (b._tr.code) trOriginal(b); });
    toast('Showing the original comments');
  }

  let LT = null;   // the comment the language picker is open for: {c, body}
  function openLang(c, body, all) {
    LT = {c: c, body: body, all: !!all};
    $('csLangTitle').textContent = all ? 'Translate all comments' : 'Translate this comment';
    $('csLangQ').value = '';
    langBuild('');
    $('csLangBody').scrollTop = 0;
    scrOpen('csLang');
  }
  function langBuild(v) {
    v = (v || '').trim().toLowerCase();
    const box = $('csLangList'); box.innerHTML = '';
    const cur = !LT ? '' : LT.all ? TA.code : (LT.body && LT.body._tr ? LT.body._tr.code : '');
    if (cur && !v) {
      const o = el('div', 'cs-lrow');
      o.innerHTML = '<i class="ri-arrow-go-back-line ic" aria-hidden="true"></i><span class="nm">' + (LT && LT.all ? 'Turn off translation' : 'Show original') + '</span>';
      o.onclick = () => { scrClose('csLang'); if (LT) { if (LT.all) trAllOff(); else trOriginal(LT.body); } };
      box.appendChild(o);
    }
    let n = 0;
    TR_LANGS.forEach(L => {
      if (v && (L[1] + ' ' + L[2]).toLowerCase().indexOf(v) === -1) return;
      n++;
      const row = el('div', 'cs-lrow' + (cur === L[0] ? ' sel' : ''));
      row.innerHTML = '<i class="ri-translate-2 ic" aria-hidden="true"></i><span class="nm"></span><span class="en"></span><div class="ck">' + (cur === L[0] ? '✓' : '') + '</div>';
      row.querySelector('.nm').textContent = L[2];
      row.querySelector('.en').textContent = L[1];
      row.onclick = () => { scrClose('csLang'); if (LT) { if (LT.all) trAllOn(L[0]); else trApply(LT.body, L[0]); } };
      box.appendChild(row);
    });
    if (!n) box.innerHTML = '<div class="cs-lempty">No language found</div>';
  }

  /* ───────────── copy · select text ───────────── */
  function copyText(t) {
    const ok = () => toast('Copied');
    const fb = () => {
      const a = document.createElement('textarea'); a.value = t; a.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(a); a.select();
      try { document.execCommand('copy'); ok(); } catch (e) { toast('Could not copy'); }
      document.body.removeChild(a);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(ok).catch(fb); else fb();
  }
  function openSel(t) {
    $('csSelTxt').textContent = t;
    scrOpen('csSel');
    setTimeout(() => {   // select everything so the handles show right away
      try { const r = document.createRange(); r.selectNodeContents($('csSelTxt')); const s = getSelection(); s.removeAllRanges(); s.addRange(r); } catch (e) {}
    }, 320);
  }

  /* ───────────── track · block · report (from the three-dot menu) ───────────── */
  async function doTrack(c) {
    if (!cfg.me) { location.href = LOGIN; return; }
    try {
      const j = await api('track', {body: fd({user_id: c.user_id})});
      FOL[c.user_id] = !!j.tracking;
      toast(j.requested ? 'Track request sent to ' + c.username : j.tracking ? 'Now tracking ' + c.username : (j.requested === false ? 'Request cancelled' : 'Untracked ' + c.username));
      if (S.header && S.header.userId === c.user_id) {              // keep the post header's Track button in step
        if (S.header.setFollowing) S.header.setFollowing(!!j.tracking);
        const tb = document.querySelector('#csList .cs-ph-track'); if (tb) tb.textContent = j.tracking ? 'Untrack' : 'Track';
      }
    } catch (e) { if (e.message !== 'login') toast(e.message); }
  }
  async function doBlock(c) {
    if (!cfg.me) { location.href = LOGIN; return; }
    if (!confirm('Block @' + c.username + '? You will no longer see their comments.')) return;
    try {
      await api('block', {body: fd({user_id: c.user_id})});
      FOL[c.user_id] = false;
      toast('Blocked ' + c.username);
      if (S.view === 'thread') closeThread();
      load(true);                                                    // reload the list without them
    } catch (e) { if (e.message !== 'login') toast(e.message); }
  }

  /* ── delete (lives in the three-dot menu) ── */
  async function doDelete(c) {
    if (!confirm('Delete this comment?')) return;
    try {
      const j = await api('delete', {body: fd({comment_id: c.id})});
      removeComment(c.id);
      changed(S.meme, -(j.removed || 1));                            // the comment AND every reply under it
    } catch (e) { if (e.message !== 'login') alert(e.message); }
  }

  /* ── save comment (private list) · pin comment (post owner) ── */
  const saveItem = c => ({ic: SAV[c.id] ? 'ri-bookmark-fill' : 'ri-bookmark-line', t: SAV[c.id] ? 'Unsave comment' : 'Save comment', f: () => doSave(c)});
  const pinItem = c => ({ic: c.pinned ? 'ri-pushpin-2-fill' : 'ri-pushpin-2-line', t: c.pinned ? 'Unpin comment' : 'Pin comment', f: () => doPin(c)});
  async function doSave(c) {
    if (!cfg.me) { location.href = LOGIN; return; }
    try {
      const j = await api('save', {body: fd({comment_id: c.id})});
      SAV[c.id] = !!j.saved; c.saved = !!j.saved;
      toast(j.saved ? 'Saved — find it under the bookmark icon' : 'Removed from saved');
    } catch (e) { if (e.message !== 'login') toast(e.message); }
  }
  async function doPin(c) {
    if (!cfg.me) { location.href = LOGIN; return; }
    try {
      const j = await api('pin', {body: fd({comment_id: c.id})});
      S.pin = j.pin || 0;
      toast(j.pinned ? 'Comment pinned to the top' : 'Comment unpinned');
      if (S.view === 'list') load(true); else S.dirty = true;        // the list is redrawn with the pinned comment on top
    } catch (e) { if (e.message !== 'login') toast(e.message); }
  }

  /* ── the Saved comments screen (also: CommentSection.openSaved() from any page) ── */
  let SV = null;
  function svRow(it) {
    const r = el('div', 'cs-sv-row');
    const bd = el('div', 'cs-sv-body'), hd = el('div', 'cs-sv-h');
    const svn = el('span', 'cs-sv-n', it.username); hd.append(svn); if (it.is_verified) hd.append(vbEl()); hd.append(el('span', 'cs-sv-t', it.ago));
    bd.appendChild(hd);
    if (it.text) bd.appendChild(el('div', 'cs-sv-tx', it.text));
    if (it.media) { const im = el('img', 'cs-sv-img'); im.src = it.media.url; im.alt = ''; im.loading = 'lazy'; bd.appendChild(im); }
    bd.appendChild(el('div', 'cs-sv-go', 'View post ›'));
    const ub = el('button', 'cs-sv-un'); ub.type = 'button'; ub.setAttribute('aria-label', 'Remove from saved'); ub.innerHTML = BOOKMARK;
    ub.onclick = async e => {
      e.stopPropagation();
      try {
        await api('save', {body: fd({comment_id: it.id})});
        SAV[it.id] = false; r.remove(); toast('Removed from saved');
        const l = $('csSavedList'); if (!l.children.length) l.innerHTML = '<div class="cs-lempty">Nothing saved yet.</div>';
      } catch (err) { if (err.message !== 'login') toast(err.message); }
    };
    r.onclick = () => { scrClose('csSaved'); open(it.meme_id); };     // opens that post's comments
    r.append(avatar(it.avatar, it.username), bd, ub);
    return r;
  }
  async function svLoad(first) {
    const st = SV; if (!st) return;
    if (!first && (st.busy || !st.more)) return;
    if (first) st.offset = 0;
    st.busy = true;
    const list = $('csSavedList');
    try {
      const j = await api('saved_list', {params: {offset: st.offset}});
      if (SV !== st) return;
      if (first) list.innerHTML = '';
      j.items.forEach(it => { SAV[it.id] = true; list.appendChild(svRow(it)); });
      st.offset += j.items.length; st.more = !!j.more;
      if (first && !j.items.length) list.innerHTML = '<div class="cs-lempty">Nothing saved yet.<br>Hold a comment and choose Save comment.</div>';
    } catch (e) { if (SV === st) { if (first) list.innerHTML = ''; if (e.message !== 'login') toast(e.message); } }
    st.busy = false;
  }
  async function openSaved() {
    await ensure();
    if (!cfg.me) { location.href = LOGIN; return; }
    SV = {offset: 0, more: false, busy: false};
    $('csSavedList').innerHTML = '<div class="cs-lempty">Loading…</div>';
    $('csSavedBody').scrollTop = 0;
    scrOpen('csSaved');
    svLoad(true);
  }

  /* ── edit: the author's own comment, for 1 minute after posting ── */
  function addEdit(act, c, row, body) {
    const b = el('button', 'cs-edit', 'Edit'); b.type = 'button';
    b.onclick = () => startEdit(c, body, b);
    const before = act.querySelector(':scope > .cs-abadge') || act.querySelector(':scope > .cs-rxsum');
    if (before) act.insertBefore(b, before); else act.appendChild(b);
    setTimeout(() => {                                               // gone after the minute is up
      b.remove();
      const bx = body.querySelector(':scope > .cs-edbox'); if (bx) { bx.remove(); const t = body.querySelector(':scope > .cs-text'); if (t) t.style.display = ''; toast('Edit time is over'); }
    }, Math.max(0, c.edit_left) * 1000);
  }
  function startEdit(c, body, btn) {
    if (body.querySelector(':scope > .cs-edbox')) return;
    const old = body.querySelector(':scope > .cs-text');
    const box = el('div', 'cs-edbox'), ta = el('textarea', 'cs-edta');
    ta.value = old ? (old._full != null ? old._full : old.textContent) : (c.text || ''); ta.maxLength = 1000; ta.rows = 2;
    const bs = el('div', 'cs-edbtns'), sv = el('button', 'sv', 'Save'), cn = el('button', '', 'Cancel');
    sv.type = cn.type = 'button'; bs.append(cn, sv); box.append(ta, bs);
    if (old) { old.style.display = 'none'; old.after(box); } else body.querySelector(':scope > .cs-meta').after(box);
    ta.focus(); try { ta.setSelectionRange(ta.value.length, ta.value.length); } catch (x) {}
    cn.onclick = () => { box.remove(); if (old) old.style.display = ''; };
    sv.onclick = async () => {
      const t = ta.value.trim();
      if (!t && !c.media) { toast('Write something first'); return; }
      sv.disabled = true;
      try {
        const j = await api('edit', {body: fd({comment_id: c.id, text: t})});
        c.text = j.text; body._tr = {code: '', orig: j.text};
        box.remove();
        let tn = old;
        if (!j.text) { if (old) old.remove(); }
        else if (tn) { fillText(tn, j.text); tn.style.display = ''; }
        else { tn = textNode(j.text); body.querySelector(':scope > .cs-meta').after(tn); }
        const meta = body.querySelector(':scope > .cs-meta');
        if (meta && !meta.querySelector('.cs-edtag')) meta.appendChild(el('span', 'cs-edtag', '· edited'));
        toast('Comment updated');
      } catch (e) {
        sv.disabled = false;
        if (e.message !== 'login') { toast(e.message); if (/first minute/.test(e.message)) { box.remove(); if (old) old.style.display = ''; btn.remove(); } }
      }
    };
  }

  const REPORT_REASONS = [
    ['spam', 'Spam', 'ri-spam-2-line'], ['harassment', 'Harassment or bullying', 'ri-emotion-unhappy-line'],
    ['hate', 'Hate speech', 'ri-forbid-2-line'], ['sexual', 'Nudity or sexual content', 'ri-eye-off-line'],
    ['violence', 'Violence or threats', 'ri-alarm-warning-line'], ['scam', 'Scam or fraud', 'ri-error-warning-line'],
    ['misinfo', 'Misinformation', 'ri-information-line'], ['other', 'Something else', 'ri-flag-line']
  ];
  let RP = null;   // {c, reason}
  function repBuild() {
    const box = $('csRepList'); box.innerHTML = '';
    REPORT_REASONS.forEach(R => {
      const row = el('div', 'cs-lrow' + (RP.reason === R[0] ? ' sel' : ''));
      row.innerHTML = '<i class="' + R[2] + ' ic" aria-hidden="true"></i><span class="nm"></span><div class="ck">' + (RP.reason === R[0] ? '✓' : '') + '</div>';
      row.querySelector('.nm').textContent = R[1];
      row.onclick = () => { RP.reason = R[0]; repBuild(); $('csRepSend').disabled = false; };
      box.appendChild(row);
    });
  }
  function openReport(c) {
    if (!cfg.me) { location.href = LOGIN; return; }
    RP = {c: c, reason: ''};
    repBuild(); $('csRepSend').disabled = true;
    scrOpen('csRep');
  }
  async function submitReport() {
    if (!RP || !RP.reason) return;
    const b = $('csRepSend'); b.disabled = true;
    try {
      await api('report', {body: fd({comment_id: RP.c.id, reason: RP.reason})});
      scrClose('csRep'); toast('Thanks — your report was sent');
    } catch (e) { if (e.message !== 'login') toast(e.message); b.disabled = false; }
  }

  /* ───────────── the three-dot menu ───────────── */
  let popAt = 0;
  function popOpen(c, row, body) {
    const mine = !!(cfg && cfg.me && cfg.me.id === c.user_id), hasText = !!(c.text && c.text.trim());
    const cur = () => { const t = textOf(body); return t ? (t._full != null ? t._full : t.textContent) : (c.text || ''); };
    const items = [];
    items.push(saveItem(c));
    if (c.can_pin) items.push(pinItem(c));
    if (hasText) items.push({ic: 'ri-translate-2', t: 'Translate', f: () => openLang(c, body)});
    if (!mine) {
      const fol = !!FOL[c.user_id];
      items.push({ic: fol ? 'ri-user-unfollow-line' : 'ri-user-follow-line', t: fol ? 'Untrack' : 'Track', f: () => doTrack(c)});
    }
    items.push({ic: 'ri-eye-off-line', t: 'Hide', f: () => markHid(row, c, 'h', true)});
    if (hasText) {
      items.push({ic: 'ri-file-copy-line', t: 'Copy', f: () => copyText(cur())});
      items.push({ic: 'ri-text', t: 'Select text', f: () => openSel(cur())});
    }
    if (c.can_delete) items.push({ic: 'ri-delete-bin-line', t: 'Delete', danger: 1, f: () => doDelete(c)});
    if (!mine) {                                                    // Report · Block · Mark as spam are always the last three
      items.push({ic: 'ri-flag-line', t: 'Report', danger: 1, f: () => openReport(c)});
      items.push({ic: 'ri-forbid-line', t: 'Block', danger: 1, f: () => doBlock(c)});
      items.push({ic: 'ri-spam-2-line', t: 'Mark as spam', danger: 1, f: () => markHid(row, c, 's', true)});
    }
    $('csPopName').textContent = c.username;
    const list = $('csPopList'); list.innerHTML = '';
    items.forEach(it => {
      const b = el('button', 'cs-pop-it' + (it.danger ? ' danger' : '')); b.type = 'button'; b.setAttribute('role', 'menuitem');
      b.innerHTML = '<i class="' + it.ic + '"></i><span></span>';
      b.querySelector('span').textContent = it.t;
      b.onclick = () => { hp('light'); popClose(); if (it.f) setTimeout(it.f, 130); };
      list.appendChild(b);
    });
    $('csPop').style.maxHeight = Math.floor(window.innerHeight * (window.innerWidth > 790 ? 0.8 : 0.88)) + 'px';
    $('csPopList').scrollTop = 0;
    popAt = Date.now();
    $('csPopOv').classList.add('show'); $('csPop').classList.add('show');
  }
  function popClose() { $('csPopOv').classList.remove('show'); $('csPop').classList.remove('show'); }

  /* ═══════════════════════════════════════════════════════════════
     REACTIONS  (Facebook style)
       • hold a comment (or hold its right-hand button) → rxOpen: blurred screen, reaction bar, the comment, the menu
       • the right-hand button: tap = ❤️ (tap again = remove). When you reacted with another emoji it shows that emoji instead.
       • the summary at the bottom right of the comment → rsOpen: bottom sheet with everyone who reacted
     ═══════════════════════════════════════════════════════════════ */
  /* writes the newest reaction numbers for one comment everywhere that comment is shown */
  function paintReactAll(id, u) {
    RX[id] = {my_react: u.my_react || null, likes: +u.likes || 0, reacts: u.reacts || [], react_counts: u.react_counts || {}};
    document.querySelectorAll('#csSheet .cs-c[data-id="' + id + '"]').forEach(r => paintReact(r, RX[id]));
  }
  function paintReact(row, u) {
    const like = row.querySelector(':scope > .cs-side > .cs-like');
    if (like) {
      const my = u.my_react || '';
      like.classList.toggle('on', my === 'like');
      const b = like.firstChild, want = my && my !== 'like' ? my : '';
      if (b && b.dataset.k !== want) { b.dataset.k = want; b.innerHTML = want ? '<span class="cs-lemo">' + EMO[want] + '</span>' : HEART; }
    }
    const act = row.querySelector(':scope > .cs-body > .cs-act');
    if (act) setLikeTxt(act, u.likes);
    const it = row.parentElement;
    if (it && it.classList.contains('cs-it')) paintSum(it, u);
  }
  /* "Reply | 12 likes": the total sits beside Reply, split by a | */
  function setLikeTxt(act, n) {
    const lc = act.querySelector(':scope > .cs-lcnt'), sp = act.querySelector(':scope > .cs-sep'); if (!lc || !sp) return;
    n = +n || 0;
    lc.textContent = n ? fmt(n) + (n === 1 ? ' like' : ' likes') : '';
    lc.style.display = sp.style.display = n ? '' : 'none';
  }
  /* the summary: up to 3 emojis (most used first) + the total. Tap = who reacted. */
  function paintSum(it, u) {
    let s = it.querySelector(':scope > .cs-rxsum');
    const rs = (u.reacts || []).filter(k => EMO[k]), n = +u.likes || 0;
    if (!n || !rs.length) { if (s) s.remove(); it.classList.remove('has-rx'); return; }
    if (!s) {
      s = el('button', 'cs-rxsum'); s.type = 'button'; s.setAttribute('aria-label', 'See who reacted');
      s.onclick = e => { e.stopPropagation(); rsOpen(+it.dataset.id); };
      it.appendChild(s);
    }
    it.classList.add('has-rx');
    s.innerHTML = rs.map(k => '<span class="e">' + EMO[k] + '</span>').join('') + '<span class="n">' + fmt(n) + '</span>';
  }
  async function doReact(c, key) {
    if (!cfg.me) { location.href = LOGIN; return; }
    /* optimistic: show the new reaction at once; the server's numbers replace it, and a failure puts everything back */
    const prev = RX[c.id] ? JSON.parse(JSON.stringify(RX[c.id])) : {my_react: null, likes: 0, reacts: [], react_counts: {}};
    const nc = Object.assign({}, prev.react_counts || {});
    let likes = +prev.likes || 0, my = prev.my_react || null;
    if (my) { nc[my] = Math.max(0, (nc[my] || 0) - 1); likes = Math.max(0, likes - 1); }
    if (my === key) my = null; else { nc[key] = (nc[key] || 0) + 1; likes += 1; my = key; }
    paintReactAll(c.id, {my_react: my, likes, react_counts: nc, reacts: Object.keys(nc).filter(k => nc[k] > 0).sort((a, b) => nc[b] - nc[a]).slice(0, 3)});
    try {
      const j = await api('react', {body: fd({comment_id: c.id, reaction: key})});
      paintReactAll(c.id, j);
    } catch (e) { paintReactAll(c.id, prev); if (e.message !== 'login') toast(e.message); }
  }

  /* ── hold-to-react screen ── */
  let RXs = null, rxAt = 0, rxT = 0;
  function rxPlace() {
    const st = $('csRxStack'), bar = $('csRxBar'), card = $('csRxCard'), menu = $('csRxMenu');
    const vh = innerHeight, gap = 10, lo = (innerWidth > 790 ? 12 : 46), bottom = 22;
    menu.style.maxHeight = '';
    const barH = bar.offsetHeight, cardH = card.offsetHeight;
    const availMenu = vh - lo - bottom - barH - cardH - gap * 2;
    const menuH = Math.min(menu.scrollHeight, Math.max(130, availMenu));
    menu.style.maxHeight = menuH + 'px';
    const total = barH + gap + cardH + gap + menuH;
    const want = RXs.top != null ? RXs.top : RXs.rect.top - barH - gap;       // the comment stays where it was on the screen
    const top = Math.max(lo, Math.min(want, vh - total - bottom));
    RXs.top = top; st.style.top = top + 'px';
  }
  function rxOpen(c, row, body) {
    if (row && row.classList && row.classList.contains('hid')) return;
    popClose();
    const root = $('csRx'), st = $('csRxStack'), bar = $('csRxBar'), card = $('csRxCard'), menu = $('csRxMenu');
    clearTimeout(rxT);
    const my = (RX[c.id] || {}).my_react || '';

    /* the reaction bar */
    bar.innerHTML = '';
    REACTS.forEach((k, i) => {
      const b = el('button', 'cs-rx-b' + (k === my ? ' on' : '')); b.type = 'button'; b.style.setProperty('--i', i);
      b.textContent = EMO[k]; b.setAttribute('aria-label', k);
      b.onclick = () => { if (Date.now() - rxAt < 350) return; hp('medium'); rxClose(); doReact(c, k); };
      bar.appendChild(b);
    });

    /* the comment (a copy, lifted above the blur) */
    card.innerHTML = ''; card.classList.remove('clip');
    card.appendChild(avatar(c.avatar, c.username));
    const bx = el('div', 'cs-rx-body'), hd = el('div', 'cs-rx-h');
    hd.append(el('span', 'cs-rx-n', c.username)); if (c.is_verified) hd.append(vbEl()); hd.append(el('span', 'cs-rx-t', c.ago));
    bx.appendChild(hd);
    const t0 = textOf(body), txt = t0 ? (t0._full != null ? t0._full : t0.textContent) : (c.text || '');
    if (txt) bx.appendChild(el('div', 'cs-rx-tx', txt));
    if (c.media) { const im = el('img', 'cs-rx-img'); im.src = c.media.url; im.alt = ''; bx.appendChild(im); }
    card.appendChild(bx);

    /* the menu: 4 options + "More", like Facebook */
    const mine = !!(cfg && cfg.me && cfg.me.id === c.user_id), hasText = !!(c.text && c.text.trim());
    const cur = () => { const t = textOf(body); return t ? (t._full != null ? t._full : t.textContent) : (c.text || ''); };
    const main = [], extra = [];
    main.push({ic: 'ri-reply-line', t: 'Reply', f: () => { if (!cfg.me) { location.href = LOGIN; return; } setTarget(c.id, c.username, true); }});
    main.push(saveItem(c));
    if (c.can_pin) main.push(pinItem(c));
    if (hasText) {
      main.push({ic: 'ri-translate-2', t: 'Translate', f: () => openLang(c, body)});
      main.push({ic: 'ri-file-copy-line', t: 'Copy', f: () => copyText(cur())});
    }
    main.push({ic: 'ri-eye-off-line', t: 'Hide', f: () => markHid(row, c, 'h', true)});
    if (hasText) extra.push({ic: 'ri-text', t: 'Select text', f: () => openSel(cur())});
    if (!mine) { const fol = !!FOL[c.user_id]; extra.push({ic: fol ? 'ri-user-unfollow-line' : 'ri-user-follow-line', t: fol ? 'Untrack' : 'Track', f: () => doTrack(c)}); }
    if (c.can_delete) extra.push({ic: 'ri-delete-bin-line', t: 'Delete', danger: 1, f: () => doDelete(c)});
    if (!mine) {
      extra.push({ic: 'ri-flag-line', t: 'Report', danger: 1, f: () => openReport(c)});
      extra.push({ic: 'ri-forbid-line', t: 'Block', danger: 1, f: () => doBlock(c)});
      extra.push({ic: 'ri-spam-2-line', t: 'Mark as spam', danger: 1, f: () => markHid(row, c, 's', true)});
    }
    if (extra.length < 2) { main.push(...extra); extra.length = 0; }
    const mk = it => {
      const b = el('button', 'cs-rx-it' + (it.danger ? ' danger' : '')); b.type = 'button'; b.setAttribute('role', 'menuitem');
      b.innerHTML = '<i class="' + it.ic + '"></i><span></span>';
      b.querySelector('span').textContent = it.t;
      b.onclick = () => { if (Date.now() - rxAt < 350) return; hp('light'); rxClose(); if (it.f) setTimeout(it.f, 130); };
      return b;
    };
    menu.innerHTML = ''; menu.classList.remove('more'); menu.scrollTop = 0;
    main.forEach(it => menu.appendChild(mk(it)));
    if (extra.length) {
      const mb = el('button', 'cs-rx-it'); mb.type = 'button';
      mb.innerHTML = '<span>More</span>' + CHEV_DOWN;
      const ex = el('div', 'cs-rx-extra'); extra.forEach(it => ex.appendChild(mk(it)));
      mb.onclick = () => { if (Date.now() - rxAt < 350) return; menu.classList.add('more'); mb.style.display = 'none'; rxPlace(); };
      menu.append(mb, ex);
    }

    /* put the stack over the sheet, with the comment staying where it was */
    const sr = $('csSheet').getBoundingClientRect();
    st.style.left = Math.round(sr.left + 12) + 'px'; st.style.width = Math.round(sr.width - 24) + 'px';
    RXs = {rect: row.getBoundingClientRect(), top: null};
    root.classList.add('open');
    rxPlace();
    rxAt = Date.now();
    requestAnimationFrame(() => requestAnimationFrame(() => {
      root.classList.add('show');
      if (card.scrollHeight > card.clientHeight + 2) card.classList.add('clip');
    }));
  }
  function rxClose() {
    const r = $('csRx'); if (!r.classList.contains('open')) return;
    r.classList.remove('show');
    clearTimeout(rxT);
    rxT = setTimeout(() => { if (!r.classList.contains('show')) r.classList.remove('open'); }, 240);
  }

  /* ── bottom sheet: everyone who reacted, with an emoji filter ── */
  let RS = null;
  function rsOpen(cid) {
    if (!cfg) return;
    RS = {id: +cid, filter: '', offset: 0, more: false, busy: false, tok: 0};
    $('csRsList').innerHTML = '<div class="cs-lempty">Loading…</div>';
    $('csRsFilterTxt').textContent = 'All reactions';
    $('csRsMenu').classList.remove('show'); $('csRsFilter').classList.remove('open');
    $('csRsOv').classList.add('show'); $('csRs').classList.add('show');
    rsLoad(true);
  }
  function rsClose() {
    $('csRsOv').classList.remove('show'); $('csRs').classList.remove('show'); $('csRsMenu').classList.remove('show');
    RS = null;
  }
  function rsRow(it) {
    const r = el('div', 'cs-rs-row');
    const av = el('div', 'cs-rs-av'); av.appendChild(avatar(it.avatar, it.username));
    const bd = el('span', 'cs-rs-badge'); bd.textContent = EMO[it.k] || '';
    av.appendChild(bd);
    const rsn = el('div', 'cs-rs-nm', it.username); if (it.is_verified) rsn.appendChild(vbEl()); r.append(av, rsn);
    r.onclick = () => goProfile(null, it.user_id);
    return r;
  }
  function rsMenuBuild(j) {
    const m = $('csRsMenu'); m.innerHTML = '';
    const mk = (k, label, n) => {
      const b = el('button', 'cs-rs-mi' + (RS && RS.filter === k ? ' on' : '')); b.type = 'button';
      b.innerHTML = '<span class="em"></span><span class="l"></span><span class="c"></span>';
      b.querySelector('.em').textContent = k ? EMO[k] : '';
      b.querySelector('.l').textContent = label;
      b.querySelector('.c').textContent = fmt(n);
      if (!k) b.querySelector('.em').style.display = 'none';
      b.onclick = () => {
        if (!RS) return;
        RS.filter = k;
        $('csRsFilterTxt').textContent = k ? EMO[k] + ' ' + fmt(n) : 'All reactions';
        m.classList.remove('show'); $('csRsFilter').classList.remove('open');
        rsLoad(true);
      };
      m.appendChild(b);
    };
    mk('', 'All reactions', j.total);
    (j.tabs || []).forEach(t => mk(t.k, ({like: 'Heart', pray: 'Praise', fire: 'Fire', clap: 'Applause', cry: 'Sad', love: 'Love', wow: 'Wow', haha: 'Haha'})[t.k] || t.k, t.n));
  }
  async function rsLoad(first) {
    const st = RS; if (!st) return;
    if (!first && (st.busy || !st.more)) return;
    if (first) st.offset = 0;
    const tk = ++st.tok; st.busy = true;
    const list = $('csRsList');
    try {
      const j = await api('reactors', {params: {comment_id: st.id, filter: st.filter, offset: st.offset}});
      if (RS !== st || tk !== st.tok) return;
      if (first) { list.innerHTML = ''; list.scrollTop = 0; rsMenuBuild(j); }
      j.items.forEach(it => list.appendChild(rsRow(it)));
      st.offset += j.items.length; st.more = !!j.more;
      if (first && !j.items.length) list.innerHTML = '<div class="cs-lempty">No reactions yet</div>';
    } catch (e) {
      if (RS === st && tk === st.tok) { if (first) list.innerHTML = '<div class="cs-lempty"></div>'; if (e.message !== 'login') toast(e.message); }
    } finally { if (RS === st && tk === st.tok) st.busy = false; }
  }
  function initRx() {
    $('csRxBg').onclick = () => { if (Date.now() - rxAt > 400) rxClose(); };
    $('csRsOv').onclick = rsClose;
    $('csRsFilter').onclick = () => { const m = $('csRsMenu'); m.classList.toggle('show'); $('csRsFilter').classList.toggle('open', m.classList.contains('show')); };
    $('csRsList').addEventListener('scroll', e => { const l = e.target; if (RS && RS.more && l.scrollTop + l.clientHeight > l.scrollHeight - 200) rsLoad(false); });
    $('csRs').addEventListener('pointerdown', e => { if (!e.target.closest('#csRsMenu') && !e.target.closest('#csRsFilter')) { $('csRsMenu').classList.remove('show'); $('csRsFilter').classList.remove('open'); } });
    /* drag the handle down to close the sheet (tap = close too) */
    const h = $('csRsHandle'), sh = $('csRs'); let y0 = 0, dy = 0, on = false;
    h.addEventListener('pointerdown', e => { on = true; y0 = e.clientY; dy = 0; sh.style.transition = 'none'; try { h.setPointerCapture(e.pointerId); } catch (x) {} });
    h.addEventListener('pointermove', e => { if (!on) return; dy = Math.max(0, e.clientY - y0); sh.style.transform = 'translateY(' + dy + 'px)'; });
    const up = () => { if (!on) return; on = false; sh.style.transition = ''; sh.style.transform = ''; if (dy > 90 || dy < 6) rsClose(); };
    h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up);
  }

  /* ───────────── comment text (with @mentions) ───────────── */
  /* long comments: first 25 words, "more" adds the next 25, at the end "Show less" returns to 25 */
  const CLAMP = 25;
  /* @name -> profile, #tag -> search: same links (and colours) as the captions in Zeems */
  function rich(d, str) {
    str.split(/([@#]\w+)/).forEach(p => {
      if (/^@\w+$/.test(p)) { const a = el('a', 'cs-mn', p); a.href = 'profile.php?username=' + encodeURIComponent(p.slice(1)); a.onclick = e => e.stopPropagation(); d.appendChild(a); }
      else if (/^#\w+$/.test(p)) { const a = el('a', 'cs-ht', p); a.href = 'search.php?focus=1&mq=' + encodeURIComponent(p); a.onclick = e => e.stopPropagation(); d.appendChild(a); }
      else if (p) d.appendChild(document.createTextNode(p));
    });
  }
  function fillText(d, t) { d._full = String(t); d._n = CLAMP; paintText(d); }
  function paintText(d) {
    const t = d._full, toks = t.match(/\S+\s*/g) || [], total = toks.length;
    d.textContent = '';
    const put = str => rich(d, str);
    if (total <= CLAMP) { put(t); return; }
    const lead = (t.match(/^\s*/) || [''])[0], all = d._n >= total;
    const body = lead + toks.slice(0, all ? total : d._n).join('').replace(/\s+$/, '');
    const m = body.match(/^([\s\S]*?)(\S+)$/), head = m ? m[1] : '', last = m ? m[2] : body;   // the last word stays glued to "more", so it can't drop to the next line
    put(head);
    const tail = el('span', 'cs-tail');
    tail.appendChild(document.createTextNode(last + (all ? ' ' : '… ')));
    const b = el('span', 'cs-moretx', all ? 'Show less' : 'more'); b.setAttribute('role', 'button'); b.tabIndex = 0;
    b.onclick = e => { e.stopPropagation(); d._n = all ? CLAMP : Math.min(total, d._n + CLAMP); paintText(d); };
    tail.appendChild(b); d.appendChild(tail);
  }
  function textNode(t) { const d = el('div', 'cs-text'); fillText(d, t); return d; }

  const BADGE_H = '<svg class="cs-abadge-h" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 2.7 4.5 6.3 4.5c2 0 3.7 1.1 5.7 3.3 2-2.2 3.7-3.3 5.7-3.3 3.6 0 5.4 3.9 3.9 7.3C19.5 16.4 12 21 12 21z"/></svg>';
  function mkBadge(av) {
    const ab = el('span', 'cs-abadge'); ab.title = 'Liked by author';
    const ai = el('img', 'cs-abadge-av'); ai.src = av || ''; ai.alt = ''; ai.loading = 'lazy';
    ab.appendChild(ai); ab.insertAdjacentHTML('beforeend', BADGE_H);
    return ab;
  }
  /* the "liked by author" badge sits right after the Reply button, on every comment (before the reaction summary) */
  function setBadge(row, on, av) {
    const act = row.querySelector(':scope > .cs-body > .cs-act');
    if (!act) return;
    const ex = act.querySelector(':scope > .cs-abadge');
    if (on && !ex) { const sm = act.querySelector(':scope > .cs-rxsum'), nb = mkBadge(av); if (sm) act.insertBefore(nb, sm); else act.appendChild(nb); }
    else if (!on && ex) ex.remove();
  }
  /* hold a comment (about half a second) to open its menu; right-click does the same on desktop.
     allowBtn = also start when the press begins on a button (used for the like button) */
  function attachLong(row, fn, allowBtn) {
    let t = 0, x0 = 0, y0 = 0;
    const clear = () => { clearTimeout(t); t = 0; };
    row.addEventListener('pointerdown', e => {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      if (row.classList.contains('hid') || (!allowBtn && e.target.closest('button,a,input,textarea'))) return;
      x0 = e.clientX; y0 = e.clientY; clear();
      t = setTimeout(() => { t = 0; row._lp = Date.now(); hp('longPress'); fn(); }, 480);
    });
    row.addEventListener('pointermove', e => { if (t && Math.hypot(e.clientX - x0, e.clientY - y0) > 8) clear(); });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(ev => row.addEventListener(ev, clear));
    row.addEventListener('contextmenu', e => {
      if (e.target.closest('input,textarea') || row.classList.contains('hid')) return;
      e.preventDefault();
      if (!row._lp || Date.now() - row._lp > 1000) { row._lp = Date.now(); fn(); }
    });
  }

  /* ═══════════════════════════════════════════════════════════════
     COMMENTS + REPLIES  (same logic as the long-form modal)
       kind 'top'    a comment in the main list. Its "N replies" row opens the Replies screen.
       kind 'root'   the comment pinned at the top of the Replies screen.
       kind 'nested' any reply (at ANY depth). If it has replies of its own, its "N replies" row
                     is an inline toggle that drops that reply's own sub-thread open underneath it.
     Replying to any comment posts directly under THAT comment (true nesting).
     ═══════════════════════════════════════════════════════════════ */
  function mkToggle(it) {
    const b = el('button', 'cs-vr'); b.type = 'button';
    const av = el('span', 'cs-vr-av'), im = el('img'); im.alt = ''; av.appendChild(im);
    const lbl = el('span', 'cs-vr-lbl'), ch = el('span', 'cs-vr-chev'); ch.innerHTML = CHEV;
    b.append(av, el('span', 'cs-vr-dot', '·'), lbl, ch);
    b._av = av; b._im = im; b._lbl = lbl;
    if (it._st && it._st.open) b.classList.add('open');
    b.onclick = e => { e.stopPropagation(); if (it._kind === 'top') openThread(it); else toggleSub(it); };
    return b;
  }
  /* sets the reply count of one comment: shows / hides its "N replies" row and the thread line under its avatar */
  function setCount(it, n, av) {
    it._cnt = Math.max(0, n);
    if (av !== undefined && av !== null) it._av = av;
    it.classList.toggle('has-thread', it._cnt > 0);
    const w = it._vrw;
    if (!w) return;                                                   // the pinned root has no toggle: its replies ARE the screen
    if (it._cnt <= 0) { w.textContent = ''; w.style.display = 'none'; return; }
    w.style.display = '';
    let b = w.firstChild;
    if (!b) { b = mkToggle(it); w.appendChild(b); }
    b._lbl.textContent = it._cnt + (it._cnt === 1 ? ' reply' : ' replies');
    if (it._av) { if (b._im.getAttribute('src') !== it._av) b._im.src = it._av; b._av.style.display = ''; } else b._av.style.display = 'none';
  }

  function render(c, kind, direct) {
    kind = kind || 'top';
    S.seen.add(c.id);
    if (c.following !== undefined) FOL[c.user_id] = !!c.following;
    RX[c.id] = {my_react: c.my_react || null, likes: +c.likes || 0, reacts: c.reacts || [], react_counts: c.react_counts || {}};
    SAV[c.id] = !!c.saved;

    const it = el('div', 'cs-it' + ((kind === 'top' || direct) ? ' cs-thread' : '') + (kind === 'root' ? ' cs-root' : ''));
    it.dataset.id = c.id; it.dataset.parent = c.parent_id || '';
    if (c.pinned) it.classList.add('is-pinned');
    it._kind = kind; it._c = c; it._cnt = c.reply_count || 0; it._av = c.reply_avatar || '';

    const row = el('div', 'cs-c'); row.dataset.id = c.id; row.dataset.user = c.user_id;
    const av0 = avatar(c.avatar, c.username); av0.classList.add('cs-go'); av0.onclick = e => goProfile(e, c.user_id);
    const col = el('div', 'cs-avcol'); col.append(av0, el('span', 'cs-line'));
    row.appendChild(col);

    const body = el('div', 'cs-body');
    body._tr = {code: '', orig: c.text || ''};
    const meta = el('div', 'cs-meta');
    const nm0 = el('span', 'n cs-go', c.username); nm0.onclick = e => goProfile(e, c.user_id);
    meta.append(nm0);
    if (c.is_verified) { const vb = el('span', 'cs-vb'); vb.innerHTML = VBSVG; meta.appendChild(vb); }
    meta.append(el('span', '', c.ago));
    if (c.is_author) meta.appendChild(el('span', '', '· Author'));
    if (c.pinned) { const pn = el('span', 'cs-pin'); pn.innerHTML = '<i class="ri-pushpin-2-fill"></i> Pinned'; meta.appendChild(pn); }
    if (c.is_first) meta.appendChild(el('span', 'cs-first', 'First Comment'));
    if (c.edited) meta.appendChild(el('span', 'cs-edtag', '· edited'));
    body.appendChild(meta);
    if (c.text) body.appendChild(textNode(c.text));
    if (c.media) {
      const m = el('div', 'cs-media'), im = el('img', c.media.type === 'sticker' ? 'st' : ''); im.src = c.media.url; im.alt = ''; im.loading = 'lazy';
      im.onclick = () => openViewer(c.media.url);
      m.appendChild(im); body.appendChild(m);
    }
    const act = el('div', 'cs-act');
    const rb = el('button', '', 'Reply'); rb.type = 'button';
    rb.onclick = () => {
      if (!cfg.me) { location.href = LOGIN; return; }
      hp('light'); setTarget(c.id, c.username, true);                                   // Reply → the comment bar opens with @username, ready to type
    };
    act.appendChild(rb);
    act.append(el('span', 'cs-sep', '|'), el('span', 'cs-lcnt'));      // Reply | 12 likes
    setLikeTxt(act, c.likes);
    if (c.edit_left > 0) addEdit(act, c, row, body);                // only for the first minute; sits before the "liked by author" badge
    if (c.author_liked) act.appendChild(mkBadge(c.author_avatar));
    const mediaEl = body.querySelector(':scope > .cs-media');         // Reply · Edit · badge · reactions sit ABOVE a comment's picture
    if (mediaEl) body.insertBefore(act, mediaEl); else body.appendChild(act);
    row.appendChild(body);

    /* the right-hand button: tap = ❤️ / remove my reaction · hold = the reaction bar */
    const like = el('div', 'cs-like');
    const lb = el('button'); lb.type = 'button'; lb.innerHTML = HEART; lb.setAttribute('aria-label', 'React');
    lb.onclick = () => {
      if (lb._lp && Date.now() - lb._lp < 900) return;               // this press was a hold: it already opened the reaction bar
      if (!cfg.me) { location.href = LOGIN; return; }
      hp((RX[c.id] || {}).my_react ? 'light' : 'medium'); doReact(c, (RX[c.id] || {}).my_react || 'like');
    };
    like.append(lb);
    const dots = el('button', 'cs-dots'); dots.type = 'button'; dots.setAttribute('aria-label', 'More options'); dots.innerHTML = DOTS;
    dots.onclick = e => { e.stopPropagation(); hp('light'); popOpen(c, row, body); };
    const side = el('div', 'cs-side'); side.append(like, dots); row.appendChild(side);

    /* placeholder shown instead of the comment when it is hidden / marked as spam */
    const note = el('div', 'cs-hidnote'), nt = el('span', '', 'Comment hidden'), undo = el('button', '', 'Undo');
    undo.type = 'button'; undo.onclick = () => unHid(row, c);
    note.append(nt, undo); note._txt = nt; row.appendChild(note);

    it.appendChild(row);
    paintReact(row, RX[c.id]);                                        // my reaction on the button, the count beside Reply, the summary at the bottom right
    if (kind !== 'root') { it._vrw = el('div', 'cs-vrw'); it.appendChild(it._vrw); }
    if (kind === 'nested') {
      it._sub = el('div', 'cs-sub'); it._sub.style.display = 'none'; it._box = it._sub; it.appendChild(it._sub);
      it._st = {open: false, loaded: false, offset: 0, more: false, busy: false};
    }
    if (kind === 'root') it._st = {open: true, loaded: false, offset: 0, more: false, busy: false};
    setCount(it, it._cnt, it._av);

    if (c.my_hid) HID[c.id] = c.my_hid; else delete HID[c.id];
    if (HID[c.id]) markHid(row, c, HID[c.id], false);
    attachLong(row, () => rxOpen(c, row, body));                       // hold the comment → reactions + menu
    attachLong(lb, () => rxOpen(c, row, body), true);                  // hold the right-hand button → the same
    if (TA.code && c.text) trApply(body, TA.code, true);               // "Translate all comments" is on
    return it;
  }

  /* ── the inline dropdown under a reply's own "N replies" row ── */
  function toggleSub(it) {
    const st = it._st; if (!st) return;
    st.open = !st.open;
    const b = it._vrw && it._vrw.firstChild;
    if (b) b.classList.toggle('open', st.open);
    it._sub.style.display = st.open ? '' : 'none';
    if (st.open && !st.loaded) { it._sub.innerHTML = '<div class="cs-subload">Loading…</div>'; loadReplies(it, false); }
  }
  async function loadReplies(it, more) {
    const st = it._st, box = it._box;
    if (!st || !box || st.busy || (more && !st.more)) return;
    st.busy = true;
    const tok = S.tok, isRoot = it._kind === 'root';
    try {
      const j = await api('replies', {params: {parent_id: it.dataset.id, offset: st.offset}});
      if (tok !== S.tok || !it.isConnected) { st.busy = false; return; }
      if (!st.loaded && !isRoot) box.innerHTML = '';
      st.loaded = true;
      const old = box.querySelector(':scope > .cs-submore'); if (old) old.remove();
      j.items.forEach(r => { if (!box.querySelector(':scope > .cs-it[data-id="' + r.id + '"]')) box.appendChild(render(r, 'nested', isRoot)); });
      st.offset += j.items.length; st.more = !!j.more;
      if (st.more && !isRoot) {
        const mb = el('button', 'cs-submore', 'Show more replies'); mb.type = 'button';
        mb.onclick = () => loadReplies(it, true);
        box.appendChild(mb);
      }
    } catch (e) { if (!st.loaded && !isRoot) box.innerHTML = ''; }
    st.busy = false;
    if (isRoot) $('csTLoad').style.display = 'none';
  }

  /* ── the Replies screen ── */
  function setPh() { $('csText').placeholder = !cfg || !cfg.me ? 'Log in to comment' : (S.view === 'thread' ? 'Reply…' : 'What do you think of this?'); }
  function openThread(mainIt) {
    const c = mainIt._c;
    popClose(); rxClose(); $('csMenu').classList.remove('show');
    S.view = 'thread'; S.t = {root: +c.id, rootIt: null};
    $('csTabs').style.display = 'none'; $('csList').style.display = 'none';
    $('csTHead').style.display = ''; $('csTScroll').style.display = '';
    const rootC = Object.assign({}, c, RX[c.id] || {}, {reply_count: mainIt._cnt, reply_avatar: mainIt._av, saved: !!SAV[c.id]});   // RX = the newest reactions
    const rit = render(rootC, 'root'); rit._box = $('csTItems');
    S.t.rootIt = rit;
    $('csTRoot').innerHTML = ''; $('csTRoot').appendChild(rit);
    $('csTItems').innerHTML = '';
    $('csTScroll').scrollTop = 0;
    setPh();
    setTarget(c.id, c.username, false);
    if (rit._cnt > 0) { $('csTLoad').style.display = ''; loadReplies(rit, false); }
    else { rit._st.loaded = true; $('csTLoad').style.display = 'none'; }
  }
  function closeThread() {
    S.view = 'list'; S.t = null;
    $('csTHead').style.display = 'none'; $('csTScroll').style.display = 'none';
    $('csTabs').style.display = ''; $('csList').style.display = '';
    $('csTRoot').innerHTML = ''; $('csTItems').innerHTML = '';
    setPh();
    cancelReply();
    if (S.dirty) { S.dirty = false; load(true); }
  }

  /* removes a comment (and everything under it) from every place it is shown, and fixes its parent's reply count */
  function dropItem(it) {
    const pid = it.dataset.parent, par = it.parentElement;
    let owner = null;                                                 // the item whose reply list we are removing from
    if (par) owner = par === $('csTItems') ? (S.t && S.t.rootIt) : par.closest('.cs-it');
    it.remove();
    if (owner && owner._st) owner._st.offset = Math.max(0, owner._st.offset - 1);
    if (pid) document.querySelectorAll('#csSheet .cs-it[data-id="' + pid + '"]').forEach(p => setCount(p, p._cnt - 1));
  }
  function removeComment(id) {
    if (S.view === 'thread' && S.t && String(S.t.root) === String(id)) closeThread();
    document.querySelectorAll('#csSheet .cs-it[data-id="' + id + '"]').forEach(dropItem);
  }

  /* puts a new reply wherever its parent is shown, and bumps that parent's "N replies" row.
     live = it arrived from another person (poll); otherwise it is my own reply. */
  function insertReply(c, live) {
    document.querySelectorAll('#csSheet .cs-it[data-id="' + c.parent_id + '"]').forEach(p => {
      if (c.is_author || !p._av) p._av = c.avatar;
      setCount(p, p._cnt + 1, p._av);
      const k = p._kind;
      if (k === 'top') return;                                        // main list: only the count changes
      const st = p._st, box = p._box;
      if (!st || !box) return;
      if (!live && k === 'nested' && !st.open) { toggleSub(p); return; }   // my own reply: open that sub-thread so I see it
      if (k === 'nested' && !st.open) return;
      if (!st.loaded) return;
      if (live && st.more) return;
      if (box.querySelector(':scope > .cs-it[data-id="' + c.id + '"]')) return;
      const n = render(c, 'nested', k === 'root');
      if (live) n.classList.add('cs-new');
      box.appendChild(n);
      if (!st.more) st.offset++;
    });
  }

  /* ---------- post header (only when the page passes opts.header, e.g. Zeems) ---------- */
  const ICO = {
    comment: '<svg viewBox="0 0 24 24"><path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.3 8.9 8.9 0 0 1-3.6-.8L3.5 20.5l1.5-4.9A8.1 8.1 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5z"/></svg>',
    repost: '<svg viewBox="0 0 24 24"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></svg>',
    like: HEART,
    fav: '<svg viewBox="0 0 24 24"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/></svg>',
    share: '<svg viewBox="0 0 24 24"><path d="M12 3v12"/><path d="M7.5 7.5L12 3l4.5 4.5"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>'
  };
  /* when the page didn't pass a header (any page except Zeems), build one from what the server sent, with its own working buttons */
  function defaultHeader(po) {
    const st = {comments: po.comments, reposts: po.reposts, likes: po.likes, favs: po.favs, shares: po.shares, liked: po.liked, reposted: po.reposted, faved: po.faved, following: po.following};
    const ep = window.CCM_ENDPOINT || 'home.php', meme = S.meme;
    const send = body => fetch(ep, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body + (window.DZ_CSRF ? '&csrf=' + encodeURIComponent(window.DZ_CSRF) : '')}).then(r => r.json());
    const map = {like: ['toggle_like', 'liked', 'likes'], repost: ['toggle_repost', 'reposted', 'reposts'], fav: ['toggle_favorite', 'faved', 'favs']};
    return {
      collab: po.collab || null, userId: po.owner_id, isVerified: !!po.is_verified, username: po.username, nickname: po.nickname, pic: po.avatar, isOwn: po.is_own, isFollowing: po.following,
      date: po.date, views: fmt(po.views || 0), caption: po.caption,
      profile: () => { location.href = 'profile.php?user_id=' + po.owner_id; },
      setFollowing: v => { st.following = !!v; },
      track: btn => {
        if (!cfg.me) { location.href = LOGIN; return; }
        send('action=toggle_follow&user_id=' + po.owner_id).then(j => { if (j.status === 'ok') { st.following = !!j.following; FOL[po.owner_id] = !!j.following; btn.textContent = st.following ? 'Untrack' : 'Track'; } }).catch(() => {});
      },
      act: k => {
        if (k === 'share') { if (typeof window.openShareModal === 'function') window.openShareModal(meme); return; }
        if (!cfg.me) { location.href = LOGIN; return; }
        const [action, flag, cnt] = map[k], was = st[flag];
        st[flag] = !was; st[cnt] = Math.max(0, st[cnt] + (was ? -1 : 1));
        send('action=' + action + '&meme_id=' + encodeURIComponent(meme)).then(j => {
          if (!j.success) throw 0;
          if (j.count !== null && j.count !== undefined) st[cnt] = j.count;
        }).catch(() => { st[flag] = was; st[cnt] = Math.max(0, st[cnt] + (was ? 1 : -1)); });
      },
      onComment: d => { st.comments = Math.max(0, st.comments + d); },
      get: () => ({comments: st.comments, reposts: st.reposts, likes: st.likes, favs: st.favs, shares: st.shares, liked: st.liked, reposted: st.reposted, faved: st.faved, following: st.following})
    };
  }

  /* ---------- poll under the caption: loads once, votes with one tap, tap your own choice again to take the vote back ---------- */
  const POLL_URL = () => window.CCM_ENDPOINT || 'home.php';   /* polls use the same endpoint as likes / reposts (shared_core.php) */
  const fmtLeft = s => { s = Math.max(0, s | 0); if (s >= 86400) return Math.ceil(s / 86400) + 'd'; if (s >= 3600) return Math.ceil(s / 3600) + 'h'; return Math.max(1, Math.ceil(s / 60)) + 'm'; };
  function renderPoll(box, poll) {
    box.textContent = '';
    if (!poll || !poll.options || poll.options.length < 2) { box.style.display = 'none'; return; }
    box.style.display = '';
    box._poll = poll;
    poll.options.forEach(o => {
      const mine = poll.myVote === o.id;
      const b = el('button', 'cs-pl-opt' + (mine ? ' on' : '')); b.type = 'button'; b.disabled = !!poll.closed;
      let pct = 0;
      if (poll.showResults && o.votes != null) {
        pct = poll.total ? Math.round(o.votes * 100 / poll.total) : 0;
        const f = el('span', 'cs-pl-fill'); b.append(f); requestAnimationFrame(() => requestAnimationFrame(() => { f.style.width = pct + '%'; }));
      }
      b.append(el('span', 'cs-pl-lab', o.label));
      if (mine) { const ck = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); ck.setAttribute('viewBox', '0 0 24 24'); ck.setAttribute('class', 'cs-pl-ck'); const pa = document.createElementNS('http://www.w3.org/2000/svg', 'path'); pa.setAttribute('d', 'M5 12.5l4.5 4.5L19 7.5'); ck.append(pa); b.append(ck); }
      if (poll.showResults && o.votes != null) b.append(el('span', 'cs-pl-pct', pct + '%'));
      b.onclick = () => votePoll(box, poll, mine ? 0 : o.id, b);
      box.append(b);
    });
    const n = poll.total || 0;
    let t = n === 0 ? 'No responses yet' : n.toLocaleString() + (n === 1 ? ' response' : ' responses');
    if (poll.closed) t += ' · Poll ended'; else if (!poll.forever && poll.endsIn != null) t += ' · ' + fmtLeft(poll.endsIn) + ' left';
    box.append(el('div', 'cs-pl-meta', t));
  }
  async function votePoll(box, poll, optionId, btn) {
    if (!(cfg && cfg.me)) { location.href = LOGIN; return; }
    if (box._busy) return; box._busy = true; btn.classList.add('busy');
    try {
      const body = 'action=poll_vote&poll_id=' + encodeURIComponent(poll.id) + '&option_id=' + encodeURIComponent(optionId) + (window.DZ_CSRF ? '&csrf=' + encodeURIComponent(window.DZ_CSRF) : '');
      const hd = {'Content-Type': 'application/x-www-form-urlencoded'}; if (window.DZ_CSRF) hd['X-CSRF-Token'] = window.DZ_CSRF;
      const r = await fetch(POLL_URL(), {method: 'POST', credentials: 'same-origin', headers: hd, body});
      const j = await r.json().catch(() => ({}));
      if (j.poll) { renderPoll(box, j.poll); try { window.dispatchEvent(new CustomEvent('dz-poll-update', {detail: {memeId: poll.memeId, poll: j.poll}})); } catch (_) {} }
      if (!r.ok || !j.ok) toast(j.error || 'Could not save your vote. Try again.');
    } catch (e) { toast('No connection. Try again.'); }
    box._busy = false; btn.classList.remove('busy');
  }
  async function loadPoll(box) {
    const meme = S.meme;
    try {
      const r = await fetch(POLL_URL() + (POLL_URL().indexOf('?') < 0 ? '?' : '&') + 'type=poll&meme_id=' + encodeURIComponent(meme), {credentials: 'same-origin'});
      if (!r.ok) return;
      const j = await r.json();
      if (S.meme !== meme || !box.isConnected) return;
      renderPoll(box, j.poll || null);
    } catch (e) {}
  }

  function renderHeader(h) {
    const box = el('div', 'cs-ph');
    const top = el('div', 'cs-ph-top');
    const co = (h.collab && h.collab.list && h.collab.list.length) ? h.collab : null;
    const openCo = () => { if (typeof window.dzCollabSheet === 'function') window.dzCollabSheet(S.meme, co); else if (h.profile) h.profile(); };
    let av;
    if (co) {
      av = el('span', 'cs-ph-cl n' + Math.min(2, co.list.length));
      av.append(avatar(h.pic, h.username));
      co.list.slice(0, 2).forEach(c => av.append(avatar(c.pic, c.username)));
      av.onclick = openCo;
    } else { av = avatar(h.pic, h.username); av.onclick = () => h.profile && h.profile(); }
    const names = el('div', 'cs-ph-names'); names.onclick = () => co ? openCo() : (h.profile && h.profile());
    const phn = el('div', 'cs-ph-name', co ? ((h.isOwn ? 'You' : (h.username || '')) + ' and ' + (co.list.length === 1 ? co.list[0].username : co.list.length + ' others')) : (h.username || '')); if (h.isVerified && !co) phn.appendChild(vbEl()); names.append(phn);
    if (h.nickname && !co) names.append(el('div', 'cs-ph-nick', '@' + h.nickname));
    top.append(av, names);
    if (!co && !h.isOwn && h.userId) {
      const tb = el('button', 'cs-ph-track', h.isFollowing ? 'Untrack' : 'Track'); tb.dataset.user = h.userId; tb.type = 'button';
      tb.onclick = () => h.track && h.track(tb);
      top.append(tb);
    }
    box.append(top);
    const cap = el('div', 'cs-ph-cap'); if (h.caption) rich(cap, h.caption); box.append(cap);
    const pollBox = el('div', 'cs-poll'); pollBox.style.display = 'none'; box.append(pollBox); loadPoll(pollBox);   // the poll's choices come right after the caption
    {                                                                 // the date and the views always show (a post with no views yet shows "0 Views")
      const m = el('div', 'cs-ph-meta');
      if (h.date) m.append(document.createTextNode(h.date + ' · '));
      const hv = (h.views === undefined || h.views === null || h.views === '') ? '0' : h.views;
      m.append(el('b', 'cs-ph-vn', hv), el('span', 'cs-ph-vl', hv === '1' ? ' View' : ' Views'));
      box.append(m);
    }
    const stats = el('div', 'cs-ph-stats');
    const ICONS = {like: '<i class="cs-ic cs-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i>', repost: '<i class="cs-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg></i>', comment: '<i class="cs-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg></i>', fav: '<i class="cs-ic cs-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg></i>', share: '<i class="cs-ic"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i>'};
    [['like', 'likes'], ['repost', 'reposts'], ['comment', 'comments'], ['fav', 'favs'], ['share', 'shares']].forEach(([k, f]) => {
      const b = el('button'); b.type = 'button'; b.dataset.k = k; b.innerHTML = ICONS[k]; b.append(el('span', '', ''));
      b.onclick = () => { if (k === 'comment') { $('csText').focus(); } else if (h.act) { h.act(k); setTimeout(refreshHeader, 250); setTimeout(refreshHeader, 800); } };
      stats.append(b);
    });
    box.append(stats);
    return box;
  }
  function refreshHeader() {
    const h = S.header, box = document.querySelector('#csList .cs-ph'); if (!h || !box || !h.get) return;
    let v; try { v = h.get() || {}; } catch (e) { return; }
    const f = { comment: 'comments', repost: 'reposts', like: 'likes', fav: 'favs', share: 'shares' };
    box.querySelectorAll('.cs-ph-stats button').forEach(b => {
      const k = b.dataset.k, sp = b.querySelector('span');
      if (sp && f[k]) sp.textContent = v[f[k]] == null ? '' : v[f[k]];
      const ic = b.querySelector('i');
      if (k === 'like') { b.classList.toggle('on', !!v.liked); }
      if (k === 'repost') b.classList.toggle('on', !!v.reposted);
      if (k === 'fav') { b.classList.toggle('on', !!v.faved); }
    });
    const tb = box.querySelector('.cs-ph-track'); if (tb && v.following != null) tb.textContent = v.following ? 'Untrack' : 'Track';
  }

  /* when the page's own header (Zeems) gives no view count, the number the server sent is used */
  function setPostViews(n) {
    const b = document.querySelector('#csList .cs-ph-vn'), l = document.querySelector('#csList .cs-ph-vl'); if (!b || !l) return;
    n = +n || 0; b.textContent = fmt(n); l.textContent = n === 1 ? ' View' : ' Views';
  }
  /* skeleton rows: a ghost avatar + two text lines per comment */
  function skRows(n) {
    const w = el('div', 'cs-sk-wrap'), widths = [['38%', '88%'], ['30%', '72%'], ['44%', '95%'], ['34%', '60%'], ['40%', '82%'], ['28%', '70%']];
    for (let i = 0; i < n; i++) {
      const r = el('div', 'cs-sk'), b = el('div', 'cs-sk-b'), a = el('div', 'cs-sk-av'), l1 = el('div', 'cs-sk-l'), l2 = el('div', 'cs-sk-l');
      const wd = widths[i % widths.length]; l1.style.width = wd[0]; l2.style.width = wd[1];
      b.appendChild(l1); b.appendChild(l2); r.appendChild(a); r.appendChild(b); w.appendChild(r);
    }
    return w;
  }
  function skClear(list) { list.querySelectorAll('.cs-sk-wrap').forEach(n => n.remove()); }
  /* infinite scroll: if the loaded comments don't fill the sheet (or you are already at the bottom), fetch the next page */
  function fillMore() {
    const l = $('csList'); if (!l || S.busy || S.fail || !S.more || S.view !== 'list') return;
    if (l.scrollHeight <= l.clientHeight + 200 || l.scrollTop + l.clientHeight > l.scrollHeight - 200) load(false);
  }
  /* background fetch of a post's first page of comments (Zeems calls this when a post comes on screen); open() then uses it at once */
  const PF = {};
  function prefetch(id) {
    id = +id; if (!id || (PF[id] && Date.now() - PF[id].t < 30000)) return;
    const keys = Object.keys(PF); if (keys.length > 6) delete PF[keys[0]];
    PF[id] = {t: Date.now(), p: api('list', {params: {meme_id: id, page: 1, sort: 'relevant'}})};
    PF[id].p.catch(() => { delete PF[id]; });
  }
  /* "Search · beach grandma": a shortcut built from the post's own hashtags (or its main words) that opens search with it */
  const STOP = new Set('the and for with this that have from your you are was were but not just they them what when where which will would could should about into over than then there their been being also more most some very like our out all any can had has its how who why get got too one two her his she him now see saw look looks looked let lets going gone made make makes want wants need needs know says said say really still even much many such only come came back after before again here these those because while every another other thing things something someone people guys guy yall omg lol lmao wow new today day post video pic picture photo check link follow tag tracks'.split(' '));
  function searchPhrase(cap) {
    cap = String(cap || ''); if (!cap.trim()) return '';
    const seen = {}, out = [];
    /* hashtags first (CamelCase / snake_case split into words), best first */
    (cap.match(/#\w+/g) || []).forEach(h => {
      const w = h.slice(1).replace(/_+/g, ' ').replace(/([a-z0-9])([A-Z])/g, '$1 $2').toLowerCase().trim();
      if (w && !seen[w]) { seen[w] = 1; out.push(w); }
    });
    if (out.length) { const tags = out.slice(0, 3).join(' ').split(' ').filter((x, i, a) => a.indexOf(x) === i); return tags.slice(0, 4).join(' '); }
    /* no hashtags: score the caption's words (repeated, early, longer = more important), drop filler */
    const words = (cap.replace(/https?:\/\/\S+/g, ' ').replace(/[@#]\w+/g, ' ').toLowerCase().match(/[a-zÀ-￿][a-zÀ-￿0-9']{2,}/g) || []).map(w => w.replace(/'s$/, ''));
    const sc = {}, pos = {};
    words.forEach((w, i) => { if (STOP.has(w)) return; sc[w] = (sc[w] || 0) + 1.5 + Math.min(w.length, 9) / 9 + (i < 6 ? .6 : 0); if (pos[w] === undefined) pos[w] = i; });
    const top = Object.keys(sc).sort((a, b) => sc[b] - sc[a] || pos[a] - pos[b]).slice(0, 3).sort((a, b) => pos[a] - pos[b]);
    return top.join(' ');
  }
  function addSearchRow(list, cap, topic) {
    const old = list.querySelector(':scope > .cs-srch'); if (old) old.remove();
    const q = (topic && String(topic).trim()) || searchPhrase(cap); if (!q) return;
    const a = el('a', 'cs-srch'); a.href = 'search.php?focus=1&mq=' + encodeURIComponent(q);
    a.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7.5"/><path d="m21 21-4.3-4.3"/></svg>';
    const t = el('span', 't'); t.appendChild(el('i', '', 'Search ·')); t.appendChild(document.createTextNode(q)); a.appendChild(t);
    a.insertAdjacentHTML('beforeend', '<svg class="go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>');
    a.onclick = e => e.stopPropagation();
    const hd = list.querySelector(':scope > .cs-ph');
    if (hd) hd.after(a); else list.insertBefore(a, list.firstChild);
  }
  async function load(first) {
    if (S.busy) return; S.busy = true; if (first) S.fail = false;
    const list = $('csList');
    if (first) { list.innerHTML = ''; S.page = 1; if (S.header) { list.appendChild(renderHeader(S.header)); refreshHeader(); } }
    list.appendChild(skRows(first ? 6 : 3));
    if (!first) list.scrollTop = list.scrollTop;                    // keep the place while the ghost rows appear
    try {
      const pf = (first && S.sort === 'relevant') ? PF[S.meme] : null; if (pf) delete PF[S.meme];
      const j = (pf && Date.now() - pf.t < 30000) ? await pf.p.catch(() => api('list', {params: {meme_id: S.meme, page: S.page, sort: S.sort}})) : await api('list', {params: {meme_id: S.meme, page: S.page, sort: S.sort}});
      skClear(list);
      await ensure();                                       // (already done by now in practice)
      if (j.max_id != null && (first || !S.since)) S.since = Math.max(S.since || 0, j.max_id);
      if (first && S.total == null && j.post) S.total = j.post.comments;
      S.pin = j.pin || 0;
      S.ready = true;
      if (first && !S.header && j.post) { S.header = defaultHeader(j.post); FOL[j.post.owner_id] = !!j.post.following; list.insertBefore(renderHeader(S.header), list.firstChild); refreshHeader(); clearInterval(phTimer); phTimer = setInterval(refreshHeader, 500); }
      if (first && S.header && j.post && j.post.is_verified && !S.header.isVerified) { S.header.isVerified = true; const pn = list.querySelector('.cs-ph-name'); if (pn && !pn.querySelector('.cs-vb')) pn.appendChild(vbEl()); }
      if (first && S.header && j.post && (S.header.views === undefined || S.header.views === null || S.header.views === '')) setPostViews(j.post.views);
      if (first && S.header && j.caption !== undefined) { const ce = list.querySelector('.cs-ph-cap'); if (ce && !S.header.caption) { ce.textContent = ''; rich(ce, j.caption); } }
      if (first) addSearchRow(list, (S.header && S.header.caption) || j.caption || (j.post && j.post.caption) || '', j.topic);
      if (first) { const bar = $('csText').closest('.cs-bar'); if (bar) bar.style.display = j.off ? 'none' : ''; }
      j.items.forEach(c => list.appendChild(render(c, 'top')));
      S.more = j.more; S.page++;
      if (first && j.off) list.appendChild(el('div', 'cs-empty', 'Comments are turned off for this post.'));
      else if (first && !j.items.length) list.appendChild(el('div', 'cs-empty', S.sort === 'tracking' ? 'No comments from people you track yet.' : 'No comments yet. Be the first!'));
    } catch (e) {
      S.fail = true; skClear(list);
      const box = el('div', 'cs-empty cs-failbox', e.message), again = el('button', 'cs-retry', 'Try again'); again.type = 'button';
      again.onclick = () => { box.remove(); S.fail = false; load(first); };
      box.appendChild(document.createElement('br')); box.appendChild(again); list.appendChild(box);
    }
    S.busy = false;
    requestAnimationFrame(fillMore);
  }

  /* ---------- composer: who am I replying to? ---------- */
  function setTarget(id, name, mention) {
    if (!cfg.me) { location.href = LOGIN; return; }
    S.parent = +id || 0; S.replyName = name || '';
    const t = $('csText');
    if (id && mention) {
      if (!t.value.trim() || /^@\w+\s*$/.test(t.value)) t.value = '@' + name + ' ';
      $('csNoteTxt').textContent = 'Replying to @' + name; $('csNote').classList.add('show');
      t.focus();
    } else {
      if (/^@\w+\s*$/.test(t.value)) t.value = '';
      $('csNote').classList.remove('show');
    }
    setPh(); sync();
  }
  function cancelReply() {
    const t = $('csText'); if (/^@\w+\s*$/.test(t.value)) t.value = '';
    S.parent = (S.view === 'thread' && S.t) ? S.t.root : 0; S.replyName = '';
    $('csNote').classList.remove('show'); sync();
  }

  function setMedia(m) {
    S.media = m;
    if (m) { $('csPrevImg').src = m.preview; $('csPrev').classList.add('show'); }
    else { $('csPrev').classList.remove('show'); $('csFile').value = ''; }
    sync();
  }
  function sync() { $('csPost').disabled = !cfg || !cfg.me || !($('csText').value.trim() || S.media); }

  async function submit() {
    if ($('csPost').disabled) return;
    const btn = $('csPost'); btn.disabled = true;
    const inThread = S.view === 'thread' && S.t;
    const target = inThread ? (S.parent || S.t.root) : (S.replyName ? S.parent : 0);   // main list: a reply goes under the comment I tapped Reply on
    const o = {meme_id: S.meme, parent_id: target, text: $('csText').value.trim(), media_type: S.media ? S.media.type : 'none'};
    if (S.media) { if (S.media.type === 'image') o.image = S.media.file; else o.media_value = S.media.value; }
    try {
      const j = await api('add', {body: fd(o)}), c = j.item;
      S.seen.add(c.id);                                              // so the live poll never adds it a second time
      const list = $('csList'); list.querySelector('.cs-empty')?.remove();
      if (c.parent_id) {
        insertReply(c, false);
        setTimeout(() => { const n = document.querySelector('#csTScroll .cs-c[data-id="' + c.id + '"]'); if (n && n.scrollIntoView) n.scrollIntoView({block: 'nearest', behavior: 'smooth'}); }, 40);
      } else { const node = render(c, 'top'); placeTop(list, node); list.scrollTop = 0; }
      $('csText').value = ''; cancelReply(); setMedia(null);
      changed(S.meme, 1);
    } catch (e) { if (e.message !== 'login') toast(e.message); }      // what you typed stays in the box, so nothing is lost
    sync();
  }

  function placeTop(list, node) {                                    // a new comment goes under the post header and under the pinned comment
    const pn = list.querySelector(':scope > .cs-it.is-pinned'), hd = list.querySelector(':scope > .cs-ph');
    if (pn) pn.after(node); else if (hd) hd.after(node); else list.prepend(node);
  }
  function setSortUi(k) { $('csMenu').classList.remove('show'); $('csMenu').querySelectorAll('button[data-sort]').forEach(b => b.classList.toggle('on', b.dataset.sort === k)); }

  /* ───────────── live updates: new comments, replies, reactions and deletions show up without refreshing ───────────── */
  let pollT = 0, polling = false;
  function pollStop() { clearTimeout(pollT); pollT = 0; }
  function pollSchedule(ms) { clearTimeout(pollT); pollT = setTimeout(pollRun, ms == null ? 2000 : ms); }
  async function pollRun() {
    if (!$('csSheet').classList.contains('show')) return;
    if (document.hidden || polling || !S.ready || S.busy) { pollSchedule(); return; }
    polling = true;
    const meme = S.meme;
    try {
      const ids = Array.from(new Set(Array.from(document.querySelectorAll('#csSheet .cs-c')).map(x => x.dataset.id))).slice(0, 150).join(',');
      const j = await api('poll', {params: {meme_id: meme, since: S.since, ids: ids}});
      if (meme === S.meme && $('csSheet').classList.contains('show')) applyPoll(j);
    } catch (e) {}
    polling = false;
    pollSchedule();
  }
  function addLive(c) {
    if (S.seen.has(c.id)) return;                                     // already shown (or already counted) — e.g. my own
    S.seen.add(c.id);
    if (c.parent_id) { insertReply(c, true); return; }
    const list = $('csList');
    if (S.sort === 'newest' || S.sort === 'relevant') {
      list.querySelector('.cs-empty')?.remove();
      const before = list.scrollTop, hd = list.querySelector('.cs-ph'), node = render(c, 'top');
      node.classList.add('cs-new');
      placeTop(list, node);
      if (before > 0) list.scrollTop = before + node.offsetHeight;
    } else if (S.sort === 'oldest' && !S.more) {
      list.querySelector('.cs-empty')?.remove();
      const node = render(c, 'top'); node.classList.add('cs-new'); list.appendChild(node);
    }
  }
  function applyPoll(j) {
    (j.gone || []).forEach(id => removeComment(id));
    (j.likes || []).forEach(u => {
      paintReactAll(u.id, u);                                          // reactions: my emoji, the top 3 and the total
      document.querySelectorAll('#csSheet .cs-c[data-id="' + u.id + '"]').forEach(r => setBadge(r, !!u.author_liked, u.author_avatar));
    });
    (j.new || []).forEach(addLive);
    if (typeof j.pin === 'number' && S.pin != null && j.pin !== S.pin && S.view === 'list' && !S.busy) { S.pin = j.pin; load(true); }   // the owner pinned / unpinned something
    if (typeof j.next === 'number' && j.next > S.since) S.since = j.next;
    if (typeof j.total === 'number' && S.total != null && j.total !== S.total) changed(S.meme, j.total - S.total);
  }

  /* ---------- open / close ---------- */
  /* the "init" request (login info + csrf) is started once and shared: it can be warmed up in the background and never delays the sheet */
  let cfgP = null;
  function ensure() {
    if (cfg) return Promise.resolve();
    return cfgP || (cfgP = (async () => { cfg = await api('init'); bind(); migrateHid(); })().catch(e => { cfgP = null; throw e; }));
  }
  let animT = 0;
  function csAnim() { const sh = $('csSheet'); if (!sh) return; sh.classList.add('cs-anim'); clearTimeout(animT); animT = setTimeout(() => sh.classList.remove('cs-anim'), 340); }
  async function open(memeId, opts) {
    const warm = ensure();                       // runs alongside the list request below, the sheet does not wait for it
    reset(); S.meme = +memeId;
    const dock = !!(opts && opts.dock);
    S.header = (opts && opts.header) || null;
    clearInterval(phTimer); if (S.header) phTimer = setInterval(refreshHeader, 500);
    $('csSheet').classList.toggle('cs-docked', dock); $('csOverlay').classList.toggle('cs-docked', dock);
    S.side = !!(opts && opts.side); $('csSheet').classList.toggle('cs-side', S.side);
    const applyMe = () => { $('csMeAv').replaceWith(Object.assign(avatar(cfg.me?.avatar, cfg.me?.name), {id: 'csMeAv'})); $('csText').disabled = !cfg.me; setPh(); };
    if (cfg) applyMe(); else { $('csText').disabled = true; warm.then(() => { if (S.meme === +memeId) applyMe(); }).catch(() => {}); }
    /* always start on the comments list, never inside an old Replies screen */
    $('csTabs').style.display = ''; $('csList').style.display = '';
    $('csTHead').style.display = 'none'; $('csTScroll').style.display = 'none';
    $('csTRoot').innerHTML = ''; $('csTItems').innerHTML = '';
    $('csText').value = ''; cancelReply(); setMedia(null); setPh();
    setSortUi('relevant');
    $('csSheet').classList.remove('cs-full', 'cs-drag'); $('csSheet').style.height = ''; $('csSheet').style.transform = '';
    csAnim(); $('csOverlay').classList.add('show'); $('csSheet').classList.add('show');
    if (!ovSaved) { ovSaved = true; prevOv = document.body.style.overflow; ovY = window.pageYOffset || document.documentElement.scrollTop || 0; }
    document.body.style.overflow = 'hidden';
    document.documentElement.classList.add('cs-open');   /* no pull-to-refresh / scroll chaining into the page while the sheet is open */
    load(true);
    pollSchedule(2000);
    state(true, dock);
  }
  function close() {
    clearInterval(phTimer); pollStop();
    if (cfg) closeLayers();
    const was = $('csSheet').classList.contains('show'), dock = $('csSheet').classList.contains('cs-docked');
    csAnim(); $('csOverlay').classList.remove('show'); $('csSheet').classList.remove('show');
    /* give the page its own lock back (Zeems keeps the body locked while it is open): clearing it made the page behind scroll and pull-to-refresh */
    const wasSaved = ovSaved, y0 = ovY;
    if (ovSaved) { document.body.style.overflow = prevOv || ''; ovSaved = false; prevOv = ''; }
    else document.body.style.overflow = '';
    document.documentElement.classList.remove('cs-open');
    /* the page must stay exactly where it was (post B): if the browser dropped it to the top, the next scroll up looked like a pull-to-refresh */
    if (wasSaved && document.body.style.position !== 'fixed') {
      const back = () => { const now = window.pageYOffset || document.documentElement.scrollTop || 0; if (Math.abs(now - y0) > 2) window.scrollTo(0, y0); };
      back(); requestAnimationFrame(back); setTimeout(back, 60); setTimeout(back, 300);
    }
    if (was) state(false, dock);
  }
  /* tell the page the sheet opened / closed (Zeems squeezes its picture above a docked sheet) */
  function state(open, docked) { try { window.dispatchEvent(new CustomEvent('cs:state', {detail: {open: open, docked: docked, meme: S.meme, side: !!S.side}})); } catch (e) {} }

  /* ---------- drag the sheet with the finger (phones): up = taller, down = smaller / close ---------- */
  function initDrag() {
    const sheet = $('csSheet'), ov = $('csOverlay');
    const mobile = () => window.matchMedia('(max-width:790px)').matches;
    let d = null;                                   // active drag
    const fullH = () => window.innerHeight - 6;

    function begin(y, fromList) {
      if (!mobile()) return false;
      const full = sheet.classList.contains('cs-full');
      const cur = sheet.getBoundingClientRect().height;
      d = { y0: y, last: y, lt: Date.now(), v: 0, fromList: !!fromList, started: !fromList, full: full,
            baseH: full ? null : cur, startH: cur, normalH: null };
      return true;
    }
    /* "normal" height = the height with .cs-full removed */
    function normalHeight() {
      const had = sheet.classList.contains('cs-full');
      if (!had) return sheet.getBoundingClientRect().height;
      sheet.classList.add('cs-drag'); sheet.classList.remove('cs-full');
      const h = sheet.getBoundingClientRect().height;
      sheet.classList.add('cs-full'); void sheet.offsetHeight; sheet.classList.remove('cs-drag');
      return h;
    }
    function apply(dy) {
      const H0 = d.normalH, Hf = fullH();
      const y = (d.full ? -(Hf - H0) : 0) + dy;            // + = lower, - = taller (relative to the normal state)
      d.y = y;
      sheet.classList.add('cs-drag'); ov.classList.add('cs-drag');
      /* the page (Zeems) moves its picture with the finger: it gets the height the sheet really shows right now */
      try { window.dispatchEvent(new CustomEvent('cs:drag', {detail: {dragging: true, h: y <= 0 ? Math.min(Hf, H0 - y) : Math.max(0, H0 - y)}})); } catch (e) {}
      if (y <= 0) {
        sheet.style.height = Math.min(Hf, H0 - y) + 'px'; sheet.style.transform = 'translateY(0)'; ov.style.opacity = '';
      } else {
        sheet.style.height = H0 + 'px'; sheet.style.transform = 'translateY(' + y + 'px)';
        ov.style.opacity = String(Math.max(0, 1 - y / H0));
      }
    }
    function move(y) {
      const now = Date.now(), dt = Math.max(1, now - d.lt);
      d.v = (y - d.last) / dt; d.last = y; d.lt = now;       // px per ms (+ = down)
      apply(y - d.y0);
    }
    function end() {
      if (!d || !d.started) { d = null; return; }
      const H0 = d.normalH, Hf = fullH(), y = d.y, v = d.v;
      sheet.classList.remove('cs-drag'); ov.classList.remove('cs-drag');
      let to;                                                // 'close' | 'normal' | 'full'
      if (y > 0) to = (y > H0 * 0.28 || v > 0.6) ? 'close' : 'normal';
      else { const mid = -(Hf - H0) / 2; to = (v < -0.5) ? 'full' : (v > 0.5) ? 'normal' : (y < mid ? 'full' : 'normal'); }
      sheet.style.height = ''; sheet.style.transform = ''; ov.style.opacity = '';
      if (to === 'close') { sheet.classList.remove('cs-full'); close(); }
      else {
        sheet.classList.toggle('cs-full', to === 'full');
        try { window.dispatchEvent(new CustomEvent('cs:drag', {detail: {dragging: false, full: to === 'full'}})); } catch (e) {}
      }
      d = null;
    }

    /* handle + both headers (Comments / Replies): pointer events (touch-action:none). The drag only starts after a few px of movement, so a plain tap still works. */
    [$('csHandle'), ...document.querySelectorAll('#csSheet .cs-tabs')].forEach(z => {
      z.addEventListener('pointerdown', e => {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        begin(e.clientY, false); if (d) { d.started = false; d.pid = e.pointerId; }
      });
      z.addEventListener('pointermove', e => {
        if (!d || d.fromList || e.pointerId !== d.pid) return;
        if (!d.started) {
          if (Math.abs(e.clientY - d.y0) < 5) return;
          d.normalH = normalHeight(); d.started = true; d.y0 = e.clientY; d.last = e.clientY; d.lt = Date.now();
          try { z.setPointerCapture(e.pointerId); } catch (x) {}
        }
        move(e.clientY);
      });
      const up = () => { if (d && !d.fromList) end(); };
      z.addEventListener('pointerup', up); z.addEventListener('pointercancel', up);
    });
    /* tapping the handle (without dragging) closes */
    let tapY = 0; $('csHandle').addEventListener('pointerdown', e => { tapY = e.clientY; });
    $('csHandle').addEventListener('click', e => { if (Math.abs(e.clientY - tapY) < 6) close(); });

    /* comment list + replies list: pulling DOWN while already at the top moves the whole sheet */
    function bindList(list) {
      list.addEventListener('touchstart', e => {
        if (e.touches.length !== 1) return;
        if (!begin(e.touches[0].clientY, true)) return;
        d.atTop = list.scrollTop <= 0;
      }, {passive: true});
      list.addEventListener('touchmove', e => {
        if (!d || !d.fromList) return;
        const y = e.touches[0].clientY, dy = y - d.y0;
        if (!d.started) {
          if (d.atTop && dy > 6) { d.normalH = normalHeight(); d.started = true; d.y0 = y; d.last = y; d.lt = Date.now(); }
          else if (!d.atTop || dy < -6) { d = null; return; }   // normal scrolling
          else return;
        }
        if (e.cancelable) e.preventDefault();
        move(y);
      }, {passive: false});
      const lend = () => { if (d && d.fromList) end(); };
      list.addEventListener('touchend', lend); list.addEventListener('touchcancel', lend);
    }
    bindList($('csList')); bindList($('csTScroll'));
  }

  /* picture viewer, comment menu, language picker, report screen, select-text screen */
  function initExtras() {
    /* viewer: pinch / wheel / double-tap to zoom, drag to move when zoomed, drag up or down to dismiss when not zoomed,
       tap outside the picture / X / Esc closes */
    const v = $('csViewer'), im = $('csVImg');
    im.draggable = false;
    $('csVX').onclick = e => { e.stopPropagation(); closeViewer(); };
    const cxy = () => [innerWidth / 2, innerHeight / 2];
    v.addEventListener('pointerdown', e => {
      if (e.target.closest('#csVX')) return;
      try { v.setPointerCapture(e.pointerId); } catch (x) {}
      VZ.ptrs.set(e.pointerId, {x: e.clientX, y: e.clientY});
      if (VZ.ptrs.size === 1) {
        VZ.g = {x0: e.clientX, y0: e.clientY, tx: VZ.x, ty: VZ.y, t: Date.now(), moved: 0, multi: false, target: e.target, dy: 0, dismiss: false};
      } else if (VZ.ptrs.size === 2 && VZ.g) {
        const [a, b] = [...VZ.ptrs.values()], [cx, cy] = cxy();
        Object.assign(VZ.g, {multi: true, dismiss: false, d0: Math.hypot(a.x - b.x, a.y - b.y) || 1, s0: VZ.s, tx: VZ.x, ty: VZ.y, mx: (a.x + b.x) / 2 - cx, my: (a.y + b.y) / 2 - cy});
        v.style.opacity = ''; vApply(false);
      }
    });
    v.addEventListener('pointermove', e => {
      const g = VZ.g; if (!g || !VZ.ptrs.has(e.pointerId)) return;
      VZ.ptrs.set(e.pointerId, {x: e.clientX, y: e.clientY});
      if (VZ.ptrs.size >= 2 && g.d0) {
        const [a, b] = [...VZ.ptrs.values()], [cx, cy] = cxy();
        const ns = Math.max(1, Math.min(6, g.s0 * Math.hypot(a.x - b.x, a.y - b.y) / g.d0)), r = ns / g.s0;
        const mx = (a.x + b.x) / 2 - cx, my = (a.y + b.y) / 2 - cy;
        VZ.s = ns; VZ.x = mx - (g.mx - g.tx) * r; VZ.y = my - (g.my - g.ty) * r;
        vClamp(); vApply(false); g.moved = 99; return;
      }
      if (VZ.ptrs.size === 1) {
        const dx = e.clientX - g.x0, dy = e.clientY - g.y0;
        g.moved = Math.max(g.moved, Math.hypot(dx, dy));
        if (VZ.s > 1.01) { VZ.x = g.tx + dx; VZ.y = g.ty + dy; vClamp(); vApply(false); }
        else if (g.moved > 6 && !g.multi) {
          g.dismiss = true; g.dy = dy;
          im.style.transition = 'none';
          im.style.transform = 'translateY(' + dy + 'px) scale(' + (1 - Math.min(.25, Math.abs(dy) / 1200)) + ')';
          v.style.opacity = String(Math.max(.3, 1 - Math.abs(dy) / 500));
        }
      }
    });
    const up = e => {
      if (!VZ.ptrs.has(e.pointerId)) return;
      VZ.ptrs.delete(e.pointerId);
      const g = VZ.g; if (!g) return;
      if (VZ.ptrs.size === 1) { const p = [...VZ.ptrs.values()][0]; g.x0 = p.x; g.y0 = p.y; g.tx = VZ.x; g.ty = VZ.y; g.d0 = 0; g.moved = 99; return; }
      if (VZ.ptrs.size > 0) return;
      VZ.g = null;
      if (VZ.s < 1.03) { VZ.s = 1; VZ.x = 0; VZ.y = 0; }
      if (g.dismiss) { if (Math.abs(g.dy) > 90) closeViewer(); else { v.style.opacity = ''; vApply(true); } return; }
      vApply(true);
      if (!g.multi && g.moved < 8 && Date.now() - g.t < 400 && e.type === 'pointerup') {
        if (g.target !== im) { closeViewer(); return; }                       // tap outside the picture
        const now = Date.now(), L = VZ.last;                                   // double tap / double click on the picture
        if (now - L.t < 320 && Math.hypot(e.clientX - L.x, e.clientY - L.y) < 30) {
          VZ.last = {t: 0, x: 0, y: 0};
          if (VZ.s > 1.05) { VZ.s = 1; VZ.x = 0; VZ.y = 0; } else vZoomAt(e.clientX, e.clientY, 2.5);
          vApply(true);
        } else VZ.last = {t: now, x: e.clientX, y: e.clientY};
      }
    };
    v.addEventListener('pointerup', up); v.addEventListener('pointercancel', up);
    v.addEventListener('wheel', e => {
      e.preventDefault();
      vZoomAt(e.clientX, e.clientY, VZ.s * Math.exp(-e.deltaY * 0.0025));
      vApply(false);
    }, {passive: false});
    $('csPrevImg').onclick = () => { if (S.media && S.media.preview) openViewer(S.media.preview); };

    /* menu */
    $('csPopOv').onclick = () => { if (Date.now() - popAt > 350) popClose(); };
    $('csPopCancel').onclick = popClose;
    $('csPopHandle').onclick = popClose;

    /* language picker */
    $('csLangX').onclick = () => scrClose('csLang');
    $('csLangQ').oninput = e => langBuild(e.target.value);

    /* report */
    $('csRepX').onclick = () => scrClose('csRep');
    $('csRepSend').onclick = submitReport;

    /* select text */
    $('csSelX').onclick = () => scrClose('csSel');

    /* saved comments */
    $('csSavedX').onclick = () => scrClose('csSaved');
    $('csSavedBody').addEventListener('scroll', e => { const l = e.target; if (SV && SV.more && l.scrollTop + l.clientHeight > l.scrollHeight - 200) svLoad(false); });
    $('csSelCopy').onclick = () => copyText($('csSelTxt').textContent);
  }

  function bind() {
    $('csOverlay').onclick = close;
    initDrag();
    initExtras();
    initRx();
    document.addEventListener('visibilitychange', () => { if (!document.hidden && $('csSheet').classList.contains('show')) pollSchedule(300); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { if (closeTop()) return; close(); } });
    $('csList').addEventListener('scroll', e => { const l = e.target; if (S.more && l.scrollTop + l.clientHeight > l.scrollHeight - 200) load(false); });
    $('csTScroll').addEventListener('scroll', e => {
      const l = e.target, r = S.t && S.t.rootIt;
      if (r && r._st && r._st.more && l.scrollTop + l.clientHeight > l.scrollHeight - 200) loadReplies(r, true);
    });
    $('csTBack').onclick = closeThread;
    $('csTX').onclick = () => { hp('light'); close(); };
    $('csCloseX').onclick = () => { hp('light'); close(); };
    $('csMenuBtn').onclick = e => { e.stopPropagation(); hp('light'); $('csMenu').classList.toggle('show'); };
    ['pointerdown', 'touchstart'].forEach(ev => $('csMenuBtn').addEventListener(ev, e => e.stopPropagation()));
    $('csMenu').addEventListener('pointerdown', e => e.stopPropagation());
    ['pointerdown', 'touchstart'].forEach(ev => $('csSavedBtn').addEventListener(ev, e => e.stopPropagation()));
    $('csSavedBtn').onclick = e => { e.stopPropagation(); $('csMenu').classList.remove('show'); openSaved(); };
    try { TA.code = localStorage.getItem(TA_KEY) || ''; } catch (e) {}
    trAllUi();
    $('csTrAll').onclick = e => { e.stopPropagation(); $('csMenu').classList.remove('show'); openLang(null, null, true); };
    $('csMenu').querySelectorAll('button[data-sort]').forEach(b => b.onclick = e => {
      e.stopPropagation(); hp('selection'); $('csMenu').classList.remove('show');
      if (b.dataset.sort === 'tracking' && !cfg.me) { location.href = LOGIN; return; }
      S.sort = b.dataset.sort; setSortUi(S.sort); load(true);
    });
    document.addEventListener('click', e => { if (!e.target.closest('#csMenu') && !e.target.closest('#csMenuBtn')) $('csMenu').classList.remove('show'); });
    $('csEmo').onclick = e => { if (e.target.tagName === 'SPAN' && cfg.me) { hp('selection'); const t = $('csText'); t.value += e.target.textContent; t.focus(); sync(); } else if (!cfg.me) location.href = LOGIN; };
    $('csText').oninput = sync;
    $('csText').onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); submit(); } };
    $('csPost').onclick = () => { hp('medium'); submit(); };
    $('csNoteX').onclick = cancelReply;
    $('csPrevX').onclick = () => setMedia(null);
    $('csFile').onchange = e => {
      const f = e.target.files[0]; if (!f) return;
      if (!/^image\//.test(f.type)) return alert('Please choose an image');
      if (f.size > 5 * 1024 * 1024) { e.target.value = ''; return alert('Image too large (max 5 MB)'); }
      const rd = new FileReader(); rd.onload = () => setMedia({type: 'image', file: f, preview: rd.result}); rd.readAsDataURL(f);
    };
  }
  document.addEventListener('click', e => { const t = e.target.closest('[data-comments]'); if (t) { e.preventDefault(); open(t.dataset.comments); } });
  /* posts with comments turned off (from the "count" answer): remembered, and every comment button / number for them is switched to the off look */
  const OFF = {};
  function applyOff(root) {
    const ids = Object.keys(OFF); if (!ids.length) return;
    ids.forEach(id => {
      (root || document).querySelectorAll('.comment-count[data-id="' + id + '"]').forEach(el => { el.setAttribute('data-off', '1'); });
      (root || document).querySelectorAll('.comment-btn[data-id="' + id + '"], .comment-btn[data-comments="' + id + '"]').forEach(b => {
        b.classList.add('cm-off');
        const ic = b.querySelector('.sp-ic') || b.querySelector('i');
        if (ic && !ic.querySelector('.cm-off-ic')) { const o = document.createElement('i'); o.className = 'ri-chat-off-line cm-off-ic'; o.setAttribute('aria-hidden', 'true'); ic.appendChild(o); }
      });
    });
  }
  let offT = 0;
  if (window.MutationObserver) new MutationObserver(() => { if (!Object.keys(OFF).length) return; clearTimeout(offT); offT = setTimeout(() => applyOff(), 150); }).observe(document.documentElement, {childList: true, subtree: true});
  function noteOff(j) { (j.off || []).forEach(i => { OFF[i] = true; }); if ((j.off || []).length) applyOff(); }
  async function counts(ids) { const j = await api('count', {params: {ids: [].concat(ids).join(',')}}); noteOff(j); return j.counts || {}; }
  /* counts + which of these posts have comments turned off: {counts: {id: n}, off: {id: true}} */
  async function status(ids) { const j = await api('count', {params: {ids: [].concat(ids).join(',')}}); noteOff(j); const off = {}; (j.off || []).forEach(i => { off[i] = true; }); return {counts: j.counts || {}, off: off}; }
  /* background warm-up: fetch login info once the page has settled so the first open is just one request */
  window.addEventListener('load', () => setTimeout(() => { ensure().catch(() => {}); try { warmPaint(); } catch (e) {} }, 1200));
  /* the very first open is the slow one: the browser has never drawn the sheet. So once, quietly, draw it for two frames (invisible, untouchable) and the first real open is as fast as every later one */
  let painted = false;
  function warmPaint() {
    return;   /* switched off: drawing the sheet in the background made it flash on screen for a split second after a page loaded */
    const sh = $('csSheet'); if (painted || !sh || sh.classList.contains('show')) return;
    painted = true;
    const keep = sh.style.cssText, phone = window.matchMedia('(max-width:790px)').matches;
    sh.style.cssText = keep + ';transition:none !important;opacity:.01 !important;pointer-events:none !important;' + (phone ? 'transform:translateY(0) !important;' : '');
    void sh.offsetHeight;
    requestAnimationFrame(() => requestAnimationFrame(() => { if (!sh.classList.contains('show')) sh.style.cssText = keep; else sh.style.cssText = keep; }));
  }
  return {open, close, prefetch, counts, status, openSaved, warm: () => { warmPaint(); return ensure().catch(() => {}); }};
})();
</script>