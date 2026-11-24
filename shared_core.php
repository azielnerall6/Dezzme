<?php
/* ================================================================
   shared_core.php — ALL the PHP for home.php, dezztext.php and search.php

   Each page now starts with ONE line and nothing else above <!DOCTYPE html>:

     home.php      <?php $DZ_PAGE='home';     $DZ_TEXT_ONLY=false; require_once 'shared_core.php'; ?>
     dezztext.php  <?php $DZ_PAGE='dezztext'; $DZ_TEXT_ONLY=true;  require_once 'shared_core.php'; ?>
     search.php    <?php $DZ_PAGE='search';                         require_once 'shared_core.php'; ?>

   What lives here: session + config, schema, helpers, every JSON endpoint,
   every AJAX action, the ranking algorithm + query, the feed build.
   What the pages keep: only HTML / CSS / JS (they read the variables built below).

   v1 UPGRADE (requires dezzme_migration_v1.sql + backfill_meme_tags.php + dz_tags.php):
     • meme_stats replaces the 4 GROUP BY joins     • dz_meme_tags = exact, all-hashtag matching
     • interest decay ≈ 7 days (was ≈ 23 hours)     • stacked tag boost + negative tags
     • following boost                              • "Not interested" is now a real negative signal
     • LIMIT + paging (?page=2&seed=…&feed_json=1)  • one 'view' per post per 24h

   v4 UPGRADE (works with dz_rank.php v4 — media understanding):
     • candidate source E: posts whose CAPTION WORDS or AI-SEEN MEDIA TOPICS (dz_meme_terms) match the person's
       favourite topics are now pulled into the candidate pool, even when they carry none of those hashtags.
       Fails soft: if dz_meme_terms does not exist yet, this source is simply skipped.

   v6 UPGRADE (works with dz_rank.php v6; see dezzme_migration_v6.sql):
     • candidate source G: collaborative filtering — posts liked by people with the same taste
     • candidate source H: a new person gets the best of many different popular topics
     • candidate source I: a random slice of fresh, almost-unseen posts (new creators can get in)
     • candidate source F counts established accounts only (fake bursts are ignored); source E also uses related topics
     • taste limits raised (16 favourite topics, 12 searched)

   v5 UPGRADE (works with dz_rank.php v5 — measurement + smarter scoring; see dezzme_migration_v5.sql):
     • candidate source F: posts gaining the most likes / reposts / saves / shares / comments in the last few hours
     • candidate source E also matches plural / synonym spellings of the person's favourite topics
     • the ranked list of one feed session is remembered (in the session, 30 min) so page 2, 3… continue page 1
       exactly — no repeats, no skipped posts, even when engagement changes between pages
     • every served feed page is logged (dz_feed_serves: post, position, algorithm variant) for measuring + A/B tests

   v7 UPGRADE (works with dz_rank.php v7 + dz_algo_prefs.php v6):
     • getUserTopTags() fallback ignores interactions from before "Reset what my feed has learned" (forget_since)
     • candidate source B2: creators the person picked as "more" on algorithm_settings.php (DZ_PICKED_POOL)
     • the non-v2 SQL ranking (other sorts, search) uses the "People you track" dial instead of a fixed 1.25

   v8 UPGRADE (works with dz_local_content.php v2):
     • candidate source B3: when "Show more local content" is on, recent posts from people whose About me location
       shares the viewer's city / first place are pulled into the candidate pool (before, local posts that were
       not already candidates could never be boosted)
     • getUserTopTags() fast path merges spellings of the same topic (glute / glutes / synonyms), so one picked
       topic no longer takes two of the 16 favourite-topic slots

   v9 UPGRADE (measure, protect, speed up):
     • TIMING: every candidate query, the heavy query, the social-proof query and the ranking step are timed.
       The numbers go in the X-DZ-Timing response header (browser dev tools → Network → the page request),
       in ?feed_json=1 answers (key "timing"), and in the PHP error log when a feed is slower than DZ_TIMING_SLOW_MS.
       Turn it off before launch with:  define('DZ_TIMING', false);  (before this file is loaded, e.g. in config.php)
     • CACHE: popular tags (1 h), related topics (10 min), site-wide hot posts (1 min) and each person's
       collaborative-filtering result (3 min) are cached (APCu when installed, otherwise files in the temp folder)
       instead of being recomputed on every request. Needs nothing to be installed.
     • CSRF: like / repost / save / block / "not interested" requests can now be checked with a token ($dz_csrf).
       The check only LOGS a missing token until the pages send it; then set  define('DZ_CSRF_ENFORCE', true);
     • getUserTopTags(): multi-word topics ("leg day" stored as leg_day and legday) now share ONE favourite-topic slot

   v11 UPGRADE (the "Why am I seeing this post / ad" sheet now tells the truth):
     • every post / ad in the kebab data now carries a "why" list, built from the SAME signals the ranker uses:
       you chose this creator / topic on Your Algorithm, you track the creator, someone you track reposted or liked it,
       it matches topics you liked / saved / reposted (hashtags + caption words + what the media shows), you lean towards
       this format, people with the same taste liked it, it is a new post being tested, or it is popular right now.
       dz_why_build() creates the list; shared_modals.php shows it.
     • "Not interested" now also clears the cached feed (like algorithm_settings.php does), so a feed tab that is already
       open does not keep serving the old ranking.

   TASTEMATCH SHADOW (log only — needs tm_client.php next to this file and the TasteMatch Flask service on 127.0.0.1:5000):
     • once per feed session (right after the PHP ranking is built, never for cached pages) the TasteMatch top list is
       fetched and written to the PHP error log as one line starting with  [dz tm-shadow]  next to PHP's own ranking.
     • NOTHING the person sees changes. The call has a hard timeout; on any failure it is skipped silently and not
       retried for DZ_TM_DOWN_SEC seconds in that session.
     • Turn it off with:  define('DZ_TM_SHADOW', false);  (before this file is loaded, e.g. in config.php)
================================================================ */

/* connectivity ping (must run before the session starts) */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['type'] ?? '') === 'ping') { header('Cache-Control: no-store'); http_response_code(204); exit; }

if(defined('DZ_SHARED_CORE')) return;
define('DZ_SHARED_CORE', 1);

$dz_t0 = microtime(true);                    /* v9: start of this request, for the timing report */

$DZ_PAGE      = $DZ_PAGE ?? 'home';          /* 'home' | 'dezztext' | 'search' */
$DZ_TEXT_ONLY = !empty($DZ_TEXT_ONLY);       /* true → only text posts + ads are shown (and in the "rising" row) */
$DZ_IS_SEARCH = ($DZ_PAGE === 'search');

if(session_status() !== PHP_SESSION_ACTIVE) session_start();

/* TEMPORARY: logs the request if something has put a non-string value in the CSRF session key.
   Remove this block once you have found the culprit (or once it has never fired for a few days). */
if(isset($_SESSION['dz_csrf']) && !is_string($_SESSION['dz_csrf'])){
    error_log('[dz csrf] array in session on ' . ($_SERVER['REQUEST_URI'] ?? '?') . ' via ' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
}

require_once 'config.php';
/* Full Private accounts are hidden from search, suggestions and trending — except from the people they approved (who already track them) and from themselves */
if(!function_exists('dz_fp_hide_sql')){
    function dz_fp_hide_sql($col, $viewer){
        $v = (int)$viewer;
        return " AND (NOT EXISTS (SELECT 1 FROM user_settings fp_h WHERE fp_h.user_id = $col AND fp_h.is_fully_private = 1) OR $col = $v OR EXISTS (SELECT 1 FROM followers fp_f WHERE fp_f.follower_id = $v AND fp_f.following_id = $col)) ";
    }
}
require_once 'hashtags.php';   /* the hashtag button bar lives in its own file */
require_once 'offline.php';    /* the "No internet connection · Retry" state lives in its own file */
require_once 'dz_tags.php';    /* hashtag extraction + dz_meme_tags sync */
require_once 'word_filters.php';   /* Settings → Word filters: words a person never wants in their feed (posts and ads separately) */
require_once 'dz_views.php';
require_once 'dz_privacy.php';
require_once 'dz_collab.php';    /* post collaboration: invites, accepted collaborators, Collaborators sheet */   /* online / last-seen visibility, download protection, default post settings */   /* real view counts: log + memes.views counter (needs dezzme_views_v1.sql) */
require_once 'dz_rank.php';    /* feed algorithm v2: user affinity + the PHP ranker */
require_once 'dz_local_content.php';   /* About me location + the "show local content" helpers (safe to load twice) */
if(is_file(__DIR__ . '/tm_client.php')) require_once __DIR__ . '/tm_client.php';   /* TasteMatch client (shadow phase). Missing file = shadow simply does nothing */

/* v4: candidate pool for "posts that match by caption words / media topics" (dz_meme_terms) */
if(!defined('DZ_TERM_POOL'))     define('DZ_TERM_POOL', 150);   /* max posts pulled in by topic-word matches */
if(!defined('DZ_TERM_MIN_W'))    define('DZ_TERM_MIN_W', 30);   /* a term must have at least this weight (0-100) on the post to count as a match */
if(!defined('DZ_TERM_DAYS'))     define('DZ_TERM_DAYS', 14);    /* only recent posts are pulled in this way */
if(!defined('DZ_TERM_TOPICS'))   define('DZ_TERM_TOPICS', 12);   /* how many of the person's favourite topics are searched */
/* v8: candidate pool for "Show more local content" */
/* v10 REPOSTS: a repost extends the life of a post — when someone you follow reposts a post, it becomes a candidate for YOUR feed too, even if you don't follow its creator. */
if(!defined('DZ_REPOST_POOL'))      define('DZ_REPOST_POOL', 120);      /* max reposted posts pulled into the pool (0 = off) */
if(!defined('DZ_REPOST_DAYS'))      define('DZ_REPOST_DAYS', 3);        /* a repost keeps spreading for this many days */
if(!defined('DZ_REPOST_BOOST_POS')) define('DZ_REPOST_BOOST_POS', 15);  /* a fresh repost of a post from someone you don't follow can move up this many places */
if(!defined('DZ_REPOST_MAX_BOOST')) define('DZ_REPOST_MAX_BOOST', 10);  /* at most this many reposts are lifted per feed load, so one busy reposter can't take over */
if(!defined('DZ_REPOST_PER_USER'))  define('DZ_REPOST_PER_USER', 3);    /* ...and at most this many from the same reposter */
if(!defined('DZ_LOCAL_POOL'))    define('DZ_LOCAL_POOL', 100);  /* max recent posts pulled in from people in the viewer's place (0 = off) */
if(!defined('DZ_LOCAL_DAYS'))    define('DZ_LOCAL_DAYS', 7);    /* only posts from the last N days are pulled in this way */
/* v9: timing, cache, CSRF switches */
if(!defined('DZ_TIMING'))           define('DZ_TIMING', true);          /* false = no timing header / log at all */
if(!defined('DZ_TIMING_SLOW_MS'))   define('DZ_TIMING_SLOW_MS', 400);   /* feeds slower than this are written to the PHP error log */
if(!defined('DZ_CACHE_POPTAGS_SEC'))  define('DZ_CACHE_POPTAGS_SEC', 3600);
if(!defined('DZ_CACHE_RELATED_SEC'))  define('DZ_CACHE_RELATED_SEC', 600);
if(!defined('DZ_CACHE_HOT_SEC'))      define('DZ_CACHE_HOT_SEC', 60);
if(!defined('DZ_CACHE_CF_SEC'))       define('DZ_CACHE_CF_SEC', 180);
if(!defined('DZ_CSRF_ENFORCE'))       define('DZ_CSRF_ENFORCE', false);  /* true once every page sends the token (see $dz_csrf) */
/* TasteMatch shadow (log only) */
if(!defined('DZ_TM_SHADOW'))          define('DZ_TM_SHADOW', true);      /* false = never call TasteMatch */
if(!defined('DZ_TM_SHADOW_LIMIT'))    define('DZ_TM_SHADOW_LIMIT', 30);  /* how many TasteMatch picks are fetched and compared */
if(!defined('DZ_TM_DOWN_SEC'))        define('DZ_TM_DOWN_SEC', 30);      /* after a failed call, skip TasteMatch for this long in that session */
/* TasteMatch BLEND: PHP and TasteMatch both rank the feed; the two rankings are merged inside dz_rank_feed() (reciprocal-rank fusion).
   Python (TasteMatch) counts a little more than PHP. Any failure = the feed is exactly PHP's ranking. */
if(!defined('DZ_TM_MAIN'))            define('DZ_TM_MAIN', true);        /* false = shadow log only, feed is 100% PHP */
if(!defined('DZ_TM_WEIGHT'))          define('DZ_TM_WEIGHT', 0.55);      /* TasteMatch's share; PHP gets 1 - this (0.55 = Python slightly higher) */
if(!defined('DZ_TM_FUSE_K'))          define('DZ_TM_FUSE_K', 20);        /* smoothing: higher = the very top ranks matter a little less */
if(!defined('DZ_TM_CACHE_SEC'))       define('DZ_TM_CACHE_SEC', 60);      /* re-use TasteMatch's picks for this long in the same session (opening Zeems / home again right away does not call Python again). 0 = always ask */
if(!defined('DZ_TM_BLEND_LIMIT'))     define('DZ_TM_BLEND_LIMIT', 50);   /* how many picks are asked from TasteMatch (Flask caps at 50) */

/* ── v9: timing helpers ── */
$GLOBALS['dz_timing'] = [];
if(!function_exists('dz_time')){
    function dz_time($label, $t0){
        $GLOBALS['dz_timing'][] = [(string)$label, round((microtime(true) - $t0) * 1000, 1)];
    }
}
if(!function_exists('dz_timing_summary')){
    function dz_timing_summary(){
        global $dz_t0;
        $parts = [];
        foreach($GLOBALS['dz_timing'] as $t) $parts[] = $t[0] . '=' . $t[1] . 'ms';
        $total = round((microtime(true) - $dz_t0) * 1000, 1);
        $parts[] = 'total=' . $total . 'ms';
        return [implode('; ', $parts), $total];
    }
}
if(!function_exists('dz_timing_flush')){
    /* sends the header (when headers are still open) and logs slow feeds */
    function dz_timing_flush(){
        if(!DZ_TIMING || empty($GLOBALS['dz_timing'])) return;
        list($line, $total) = dz_timing_summary();
        if(!headers_sent()) header('X-DZ-Timing: ' . $line);
        if($total >= (float)DZ_TIMING_SLOW_MS) error_log('[dz timing] slow feed (' . ($GLOBALS['DZ_PAGE'] ?? '?') . '): ' . $line);
    }
}

/* ── v9: tiny cache (APCu when installed, otherwise files in the system temp folder) ── */
if(!function_exists('dz_cache_remember')){
    function dz_cache_remember($key, $ttl, $fn){
        $k = 'dz_' . md5((string)$key);
        $ttl = max(1, (int)$ttl);
        if(function_exists('apcu_fetch') && function_exists('apcu_store') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)){
            $ok = false;
            $v = apcu_fetch($k, $ok);
            if($ok) return $v;
            $v = $fn();
            apcu_store($k, $v, $ttl);
            return $v;
        }
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $k . '.cache';
        if(is_file($f) && (time() - (int)@filemtime($f)) < $ttl){
            $raw = @file_get_contents($f);
            if($raw !== false){
                $v = @unserialize($raw, ['allowed_classes' => false]);
                if($v !== false || $raw === 'b:0;') return $v;
            }
        }
        $v = $fn();
        $tmp = $f . '.' . getmypid() . '.tmp';
        if(@file_put_contents($tmp, serialize($v), LOCK_EX) !== false) @rename($tmp, $f);
        return $v;
    }
}

/* ── TASTEMATCH SHADOW: fetch TasteMatch's picks once per feed session and LOG them next to PHP's ranking ──
   Log only. Changes nothing the person sees, writes nothing to the database, never throws.
   One line per feed session in the PHP error log:  [dz tm-shadow] {json}
     tm_ms      round trip to Flask             php_n       posts in PHP's ranked list
     tm_n       picks TasteMatch returned       overlap     how many TasteMatch picks are inside PHP's top DZ_TM_SHADOW_LIMIT
     tm         "meme_id@position in PHP's ranking" ("-" = PHP did not rank that post at all)
     php_top    PHP's own top ids for comparison */
if(!function_exists('dz_tm_shadow')){
    function dz_tm_shadow($uid, array $ranked, $page, $variant){
        if(!DZ_TM_SHADOW || !function_exists('tm_fetch')) return;
        $uid = (int)$uid;
        if($uid <= 0) return;
        try{
            if((int)($_SESSION['dz_tm_down_until'] ?? 0) > time()) return;   /* recently failed: do not make the feed wait again */
            $lim = max(1, (int)DZ_TM_SHADOW_LIMIT);
            $tm  = tm_fetch($uid, $lim);
            if(empty($tm['ok'])){
                if(session_status() === PHP_SESSION_ACTIVE) $_SESSION['dz_tm_down_until'] = time() + (int)DZ_TM_DOWN_SEC;
                error_log('[dz tm-shadow] user=' . $uid . ' unavailable: ' . (string)($tm['error'] ?? '?') . ' (' . (string)($tm['ms'] ?? '?') . 'ms)');
                return;
            }
            $php_pos = []; $pos = 0;
            foreach($ranked as $r){ $pos++; $php_pos[(int)$r['id']] = $pos; }
            $php_top = array_slice(array_keys($php_pos), 0, $lim);
            $tm_out = []; $overlap = 0;
            foreach($tm['items'] as $it){
                $id = (int)$it['meme_id'];
                $p  = $php_pos[$id] ?? null;
                if($p !== null && $p <= $lim) $overlap++;
                $tm_out[] = $id . '@' . ($p !== null ? $p : '-');
            }
            error_log('[dz tm-shadow] ' . json_encode([
                'user'    => $uid,
                'page'    => (int)$page,
                'variant' => (string)$variant,
                'tm_ms'   => (float)($tm['ms'] ?? 0),
                'tm_n'    => count($tm['items']),
                'php_n'   => count($php_pos),
                'overlap' => $overlap,
                'tm'      => $tm_out,
                'php_top' => $php_top,
            ]));
        }catch(Throwable $e){ /* shadow must never affect the feed */ }
    }
}

/* ── TASTEMATCH BLEND (step 1 of 2) ──
   Asks TasteMatch (Flask) for its top picks and returns [post id => rank]. The actual merging happens INSIDE dz_rank_feed() (dz_rank.php),
   before its discovery / "Less" spacing, so every PHP rule still applies. Python picks that PHP did not rank are simply ignored.
   Any problem (Flask down, slow, nothing returned) = empty list = the feed is PHP's own ranking. */
if(!function_exists('dz_tm_ranks')){
    function dz_tm_ranks($uid){
        if(!DZ_TM_MAIN || !function_exists('tm_fetch')) return [];
        $uid = (int)$uid;
        if($uid <= 0) return [];
        try{
            if((int)($_SESSION['dz_tm_down_until'] ?? 0) > time()) return [];
            /* picks asked a moment ago for this same person: use them (saves a round trip to the Python service) */
            $_tc = $_SESSION['dz_tm_cache'] ?? null;
            if((int)DZ_TM_CACHE_SEC > 0 && is_array($_tc) && (int)($_tc['u'] ?? 0) === $uid && (time() - (int)($_tc['t'] ?? 0)) < (int)DZ_TM_CACHE_SEC && !empty($_tc['out'])) return (array)$_tc['out'];
            $tm = tm_fetch($uid, max(1, min(50, (int)DZ_TM_BLEND_LIMIT)));
            if(empty($tm['ok']) || empty($tm['items'])){
                if(empty($tm['ok']) && session_status() === PHP_SESSION_ACTIVE) $_SESSION['dz_tm_down_until'] = time() + (int)DZ_TM_DOWN_SEC;
                error_log('[dz tm-blend] user=' . $uid . ' skipped: ' . (empty($tm['ok']) ? (string)($tm['error'] ?? '?') : 'no picks') . ' (' . (string)($tm['ms'] ?? '?') . 'ms)');
                return [];
            }
            $out = [];
            foreach($tm['items'] as $it){ $out[(int)$it['meme_id']] = (int)$it['rank']; }
            if((int)DZ_TM_CACHE_SEC > 0 && session_status() === PHP_SESSION_ACTIVE) $_SESSION['dz_tm_cache'] = ['u' => $uid, 't' => time(), 'out' => $out];
            return $out;
        }catch(Throwable $e){ return []; }
    }
}

/* v2/v3 safety check: stop with a clear message if the new tables are missing or are a different table with the same name (cached per session) */
if(empty($_SESSION['dz_v2_ready'])){
    $_dz_problem = '';
    foreach(['dz_meme_tags' => 'tag', 'meme_stats' => 'skips', 'dz_feed_impressions' => 'skip_count', 'dz_user_affinity' => 'score'] as $_dz_t => $_dz_col){
        $_dz_r = $conn->query("SHOW TABLES LIKE '$_dz_t'");
        if(!$_dz_r || !$_dz_r->num_rows){ $_dz_problem = "table `$_dz_t` is missing"; break; }
        $_dz_r = $conn->query("SHOW COLUMNS FROM `$_dz_t` LIKE '$_dz_col'");
        if(!$_dz_r || !$_dz_r->num_rows){ $_dz_problem = "table `$_dz_t` exists but has no `$_dz_col` column (it is a different table that happens to have the same name)"; break; }
    }
    if($_dz_problem !== ''){
        http_response_code(500);
        exit('Dezzme setup (shared_core.php v2): ' . $_dz_problem . '. Run fix_dz_meme_tags.sql (tags), dezzme_migration_v2.sql (impressions + affinity) and dezzme_migration_v3.sql (skips) in phpMyAdmin.');
    }
    $_SESSION['dz_v2_ready'] = 1;
}

$user_id      = (int)($_SESSION['user_id'] ?? 0);
$current_user = $user_id;      /* both names are used across the pages */

/* ── v10: REPOST BOOST ─────────────────────────────────────────────────────────────────────────────
   The ranker has already ordered the candidates by quality (engagement, the viewer's interests, freshness, "already seen" …).
   Reposts then move up the list by how much they ADD for this viewer:
     • fresher repost  → bigger lift (fades over about a day)
     • viewer does NOT follow the original creator → full lift (the repost is the only way they'd see it); if they do follow the
       creator, half lift (they might see it anyway)
     • more people they follow reposted it → a little more
     • already seen by this viewer → no lift (no pushing the same post again)
   Caps: at most DZ_REPOST_MAX_BOOST lifted per load and DZ_REPOST_PER_USER per reposter, so one busy reposter can't flood the feed. */
if(!function_exists('dz_repost_boost_rows')){
    function dz_repost_boost_rows($conn, $viewer_id, array $rows, array $map){
        $viewer_id = (int)$viewer_id;
        if(!$rows || !$map || $viewer_id <= 0) return $rows;
        $creators = [];
        foreach($rows as $r){ if(isset($map[(int)$r['id']])) $creators[(int)($r['uploaded_by'] ?? 0)] = 1; }
        if(!$creators) return $rows;
        $follows = [];
        $q = $conn->query("SELECT following_id FROM followers WHERE follower_id = $viewer_id AND following_id IN (" . implode(',', array_keys($creators)) . ")");
        if($q) while($x = $q->fetch_row()) $follows[(int)$x[0]] = true;
        $now = time(); $strength = [];
        foreach($rows as $i => $r){
            $mid = (int)$r['id'];
            if(!isset($map[$mid]) || (int)($r['seen_count'] ?? 0) > 0) continue;
            $m = $map[$mid];
            $hours = max(0, ($now - (int)$m['ts']) / 3600);
            $s = exp(-$hours / 18.0);
            if(!empty($follows[(int)($r['uploaded_by'] ?? 0)])) $s *= 0.5;
            $s *= 1 + 0.25 * min(3, max(0, (int)$m['n'] - 1));
            if($s > 0.02) $strength[$i] = $s;
        }
        if(!$strength) return $rows;
        arsort($strength);
        $perUser = []; $lift = [];
        foreach($strength as $i => $s){
            if(count($lift) >= (int)DZ_REPOST_MAX_BOOST) break;
            $by = (int)($map[(int)$rows[$i]['id']]['by'][0] ?? 0);      /* the newest reposter */
            if(($perUser[$by] ?? 0) >= (int)DZ_REPOST_PER_USER) continue;
            $perUser[$by] = ($perUser[$by] ?? 0) + 1;
            $lift[$i] = $s;
        }
        $keys = [];
        foreach($rows as $i => $r) $keys[$i] = isset($lift[$i]) ? $i - (int)round($lift[$i] * (int)DZ_REPOST_BOOST_POS) : $i;
        $order = array_keys($rows);
        usort($order, function($x, $y) use ($keys){ return ($keys[$x] <=> $keys[$y]) ?: ($x <=> $y); });
        $out = [];
        foreach($order as $i) $out[] = $rows[$i];
        return $out;
    }
}

/* ── v9: CSRF token for the like / repost / save / block / "not interested" requests ──
   Pages: put  var DZ_CSRF = <?php echo json_encode($dz_csrf); ?>;  in the page JS and send it as the "csrf" field
   (or an X-CSRF-Token header) on every POST to this file. */
if(empty($_SESSION['dz_csrf']) || !is_string($_SESSION['dz_csrf'])) $_SESSION['dz_csrf'] = bin2hex(random_bytes(16));
$dz_csrf = $_SESSION['dz_csrf'];
if(!function_exists('dz_csrf_check')){
    function dz_csrf_check(){
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $sent = is_string($sent) ? $sent : '';
        $have = is_string($_SESSION['dz_csrf'] ?? null) ? $_SESSION['dz_csrf'] : '';
        if($sent !== '' && $have !== '' && hash_equals($have, $sent)) return true;
        if(!DZ_CSRF_ENFORCE){
            $act = $_POST['action'] ?? '?';
            error_log('[dz csrf] missing or wrong token on action "' . (is_string($act) ? $act : '?') . '" (not enforced yet)');
            return true;
        }
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status'=>'error','success'=>false,'message'=>'Your session expired. Refresh the page and try again.']);
        exit;
    }
}

/* ══════════════════ POLLS (read for the feed + vote). Creating a poll lives in the upload page. ══════════════════
   Tables create themselves the first time (CREATE TABLE IF NOT EXISTS, checked once per login session). */
if(!function_exists('dz_poll_ensure')){
    function dz_poll_ensure($conn){
        if(!empty($_SESSION['dz_poll_schema_v1'])) return;
        try{
            $conn->query("CREATE TABLE IF NOT EXISTS polls (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, meme_id INT NOT NULL, ends_at DATETIME NULL DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_polls_meme (meme_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS poll_options (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, poll_id INT UNSIGNED NOT NULL, position TINYINT UNSIGNED NOT NULL, label VARCHAR(60) NOT NULL, KEY k_opt_poll (poll_id, position)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS poll_votes (poll_id INT UNSIGNED NOT NULL, user_id INT NOT NULL, option_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (poll_id, user_id), KEY k_vote_option (option_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if(session_status() === PHP_SESSION_ACTIVE) $_SESSION['dz_poll_schema_v1'] = 1;
        }catch(Throwable $e){ error_log('[dz poll] schema: '.$e->getMessage()); }
    }
}
/* Polls for a list of posts, keyed by post id. Results (votes per choice) are only sent to someone who already voted, the poll's owner, or once it is closed. */
if(!function_exists('dz_polls_for_memes')){
    function dz_polls_for_memes($conn, $uid, array $ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function($v){ return $v > 0; })));
        if(!$ids) return [];
        $uid = (int)$uid; $out = [];
        try{
            $in = implode(',', $ids);
            $res = $conn->query("SELECT p.id, p.meme_id, p.ends_at, m.uploaded_by AS owner,
                                        (p.ends_at IS NOT NULL AND p.ends_at <= NOW()) AS closed,
                                        CASE WHEN p.ends_at IS NULL THEN NULL ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), p.ends_at)) END AS ends_in
                                 FROM polls p JOIN memes m ON m.id = p.meme_id WHERE p.meme_id IN ($in)");
            if(!$res) return [];
            $polls = [];
            while($r = $res->fetch_assoc()) $polls[(int)$r['id']] = $r;
            if(!$polls) return [];
            $pin = implode(',', array_keys($polls));
            $opts = []; $res = $conn->query("SELECT id, poll_id, label FROM poll_options WHERE poll_id IN ($pin) ORDER BY poll_id, position");
            if($res) while($r = $res->fetch_assoc()) $opts[(int)$r['poll_id']][] = ['id'=>(int)$r['id'], 'label'=>$r['label'], 'votes'=>0];
            $cnt = []; $res = $conn->query("SELECT poll_id, option_id, COUNT(*) AS n FROM poll_votes WHERE poll_id IN ($pin) GROUP BY poll_id, option_id");
            if($res) while($r = $res->fetch_assoc()) $cnt[(int)$r['poll_id']][(int)$r['option_id']] = (int)$r['n'];
            $mine = [];
            if($uid > 0){
                $st = $conn->prepare("SELECT poll_id, option_id FROM poll_votes WHERE user_id = ? AND poll_id IN ($pin)");
                $st->bind_param("i", $uid); $st->execute();
                $rs = $st->get_result(); while($r = $rs->fetch_assoc()) $mine[(int)$r['poll_id']] = (int)$r['option_id'];
                $st->close();
            }
            foreach($polls as $pid => $p){
                $list = $opts[$pid] ?? []; if(count($list) < 2) continue;
                $total = 0; foreach($list as &$o){ $o['votes'] = $cnt[$pid][$o['id']] ?? 0; $total += $o['votes']; } unset($o);
                $closed = !empty($p['closed']);
                $my = $mine[$pid] ?? null;
                $show = $closed || $my !== null || ($uid > 0 && (int)$p['owner'] === $uid);
                if(!$show){ foreach($list as &$o){ $o['votes'] = null; } unset($o); }
                $out[(int)$p['meme_id']] = [
                    'id'=>$pid, 'memeId'=>(int)$p['meme_id'], 'options'=>$list, 'total'=>$total, 'myVote'=>$my,
                    'closed'=>$closed, 'endsIn'=>($p['ends_in'] === null ? null : (int)$p['ends_in']), 'forever'=>($p['ends_at'] === null),
                    'showResults'=>$show,
                ];
            }
        }catch(Throwable $e){ error_log('[dz poll] read failed: '.$e->getMessage()); return []; }
        return $out;
    }
}

/* ── v12: may this person interact with this post? (exists, not archived, owner active, no block either way, private owner only for approved trackers) ──
   Same rules get_connected_meme already applies; used before like / repost / save / view so a post id guessed from the URL can't be touched by someone who must not see it. */
if(!function_exists('dz_can_touch_meme')){
    function dz_can_touch_meme($conn, $uid, $mid){
        $uid = (int)$uid; $mid = (int)$mid;
        if($uid <= 0 || $mid <= 0) return false;
        $st = $conn->prepare("SELECT m.uploaded_by, m.is_archived, u.deactivated_at, u.user_rank, (SELECT COUNT(*) FROM group_posts gpx JOIN `groups` gx ON gx.id = gpx.group_id WHERE gpx.meme_id = m.id AND gx.is_suspended = 1) AS pod_susp, COALESCE(us.is_fully_private,0) AS priv
                              FROM memes m JOIN users u ON u.id = m.uploaded_by LEFT JOIN user_settings us ON us.user_id = m.uploaded_by WHERE m.id = ? LIMIT 1");
        if(!$st) return false;
        $st->bind_param("i", $mid); $st->execute();
        $r = $st->get_result()->fetch_assoc(); $st->close();
        if(!$r || (int)$r['is_archived'] === 1 || $r['deactivated_at'] !== null) return false;
        if(in_array(strtolower((string)($r['user_rank'] ?? '')), ['suspended','banned'], true) || (int)($r['pod_susp'] ?? 0) > 0) return false;   /* suspended account / suspended pod */
        $owner = (int)$r['uploaded_by'];
        if($owner === $uid) return true;
        $b = $conn->prepare("SELECT 1 FROM blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1");
        $b->bind_param("iiii", $uid, $owner, $owner, $uid); $b->execute();
        $blocked = $b->get_result()->num_rows > 0; $b->close();
        if($blocked) return false;
        if(!empty($r['priv'])){
            $a = $conn->prepare("SELECT 1 FROM track_requests WHERE requester_id=? AND target_id=? AND status='approved' LIMIT 1");
            $a->bind_param("ii", $uid, $owner); $a->execute();
            $ok = $a->get_result()->num_rows > 0; $a->close();
            if(!$ok) return false;
        }
        return true;
    }
}

/* ================================================================
   SCHEMA / VISIBILITY RULES
     memes.is_archived = 1            → hidden from every feed / list / search result
     users.deactivated_at IS NOT NULL → that person's posts, profile and presence hidden everywhere
================================================================ */
if(empty($_SESSION['vis_cols_v1'])){
    try{
        $_r = $conn->query("SHOW COLUMNS FROM memes LIKE 'is_archived'");
        if($_r && !$_r->num_rows) $conn->query("ALTER TABLE memes ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
        $_r = $conn->query("SHOW COLUMNS FROM users LIKE 'deactivated_at'");
        if($_r && !$_r->num_rows) $conn->query("ALTER TABLE users ADD COLUMN deactivated_at DATETIME NULL DEFAULT NULL");
        $_SESSION['vis_cols_v1'] = 1;
    }catch(Throwable $e){}
}
/* suspended pods: groups.is_suspended (the reports centre sets it) — posts in a suspended pod, and posts of suspended / banned accounts, are hidden from every feed */
if(empty($_SESSION['susp_cols_v1'])){
    try{
        $_r = $conn->query("SHOW COLUMNS FROM `groups` LIKE 'is_suspended'");
        if($_r && !$_r->num_rows) $conn->query("ALTER TABLE `groups` ADD COLUMN is_suspended TINYINT(1) NOT NULL DEFAULT 0");
        $_SESSION['susp_cols_v1'] = 1;
    }catch(Throwable $e){}
}
/* HIDE COUNTS — a post that hides a count gives every other viewer NO number (icon stays). Owner always sees theirs. */
if(empty($_SESSION['hide_cols_v1'])){
    try{
        foreach(['hide_likes','hide_reposts','hide_saves','hide_shares'] as $_c){
            $_r = $conn->query("SHOW COLUMNS FROM memes LIKE '$_c'");
            if($_r && !$_r->num_rows) $conn->query("ALTER TABLE memes ADD COLUMN `$_c` TINYINT(1) NOT NULL DEFAULT 0");
        }
        $_SESSION['hide_cols_v1'] = 1;
    }catch(Throwable $e){}
}
/* advertisers a person chose to hide ("Hide all Ads from…") */
if(empty($_SESSION['hid_ads_schema_v1'])){
    try{
        $conn->query("CREATE TABLE IF NOT EXISTS hidden_ad_advertisers (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, hidden_user_id INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_hide (user_id, hidden_user_id))");
        $_SESSION['hid_ads_schema_v1'] = 1;
    }catch(Throwable $e){}
}
/* muted_users table + meme_articles author/date/summary columns (used by search / memepedia) */
if(empty($_SESSION['misc_schema_v1'])){
    try{
        $conn->query("CREATE TABLE IF NOT EXISTS `muted_users` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `muter_id` int(10) UNSIGNED NOT NULL,
            `muted_id` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_mute` (`muter_id`,`muted_id`),
            KEY `fk_muted_id` (`muted_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        foreach([['created_by','INT UNSIGNED DEFAULT NULL'],['created_at','TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'],['summary','TEXT DEFAULT NULL']] as $_c){
            $_r = $conn->query("SHOW COLUMNS FROM meme_articles LIKE '{$_c[0]}'");
            if($_r && !$_r->num_rows) $conn->query("ALTER TABLE meme_articles ADD COLUMN {$_c[0]} {$_c[1]}");
        }
        $_SESSION['misc_schema_v1'] = 1;
    }catch(Throwable $e){}
}

/* ================================================================
   SMALL HELPERS
================================================================ */
if(!function_exists('dz_hidden')){
    function dz_hidden($post, $field){
        if(!$post || empty($post[$field])) return false;
        return (int)($post['uploaded_by'] ?? 0) !== (int)($_SESSION['user_id'] ?? 0);
    }
}
if(!function_exists('dz_count')){
    function dz_count($post, $field, $n){ return dz_hidden($post, $field) ? '' : $n; }
}
if(!function_exists('dz_attr')){
    function dz_attr($post, $field){ return 'data-hidden="' . (dz_hidden($post, $field) ? '1' : '0') . '"'; }
}
if(!function_exists('dz_post_flags')){
    function dz_post_flags($conn, $meme_id){
        $meme_id = (int)$meme_id;
        $r = $conn->query("SELECT uploaded_by, hide_likes, hide_reposts, hide_saves, hide_shares FROM memes WHERE id=$meme_id LIMIT 1");
        return $r ? $r->fetch_assoc() : null;
    }
}
if(!function_exists('countRows')){
    function countRows($conn, $table, $meme_id){
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM `$table` WHERE meme_id=?");
        $stmt->bind_param("i", $meme_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()['c'];
    }
}
if(!function_exists('recordInteraction')){
    function recordInteraction($conn, $user_id, $meme_id, $type){
        if(!$user_id) return;
        $stmt = $conn->prepare("INSERT INTO user_interactions (user_id, meme_id, interaction_type) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $user_id, $meme_id, $type);
        $stmt->execute();
        /* every action also updates this person's taste profile (tags / creator / post type / media topics) */
        if(function_exists('dz_affinity_bump')){
            $w = dz_affinity_weight($type);
            if($w != 0) dz_affinity_bump($conn, $user_id, $meme_id, $w);
        }
    }
}

/* writes the EXACT count into meme_stats (self-healing, never drifts, safe alongside triggers) */
if(!function_exists('dz_stat_set')){
    function dz_stat_set($conn, $meme_id, $table, $count){
        static $colmap = ['likes'=>'likes', 'reposts'=>'reposts', 'favorites'=>'saves', 'shares'=>'shares'];
        $meme_id = (int)$meme_id;
        if($meme_id <= 0 || !isset($colmap[$table])) return;
        $col = $colmap[$table];
        $count = max(0, (int)$count);
        try{
            $stmt = $conn->prepare("INSERT INTO meme_stats (meme_id, `$col`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `$col` = VALUES(`$col`)");
            $stmt->bind_param("ii", $meme_id, $count);
            $stmt->execute();
            $stmt->close();
        }catch(Throwable $e){}
    }
}

/* ── ONLINE STATUS (green dot) ──
   Shown only when: the poster allows it (user_settings.show_online_status = 1, no row = allowed), you allow it too,
   and the poster was active in the last 5 minutes. Never on your own posts. */
if(!defined('DZ_ONLINE_WINDOW')) define('DZ_ONLINE_WINDOW', 300);
if(!function_exists('hpUserCol')){
    function hpUserCol($conn){
        static $c = null;
        if($c !== null) return $c;
        foreach(['last_active','last_seen','last_activity','last_online'] as $col){
            $r = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
            if($r && $r->num_rows){ return $c = $col; }
        }
        $conn->query("ALTER TABLE users ADD COLUMN last_active DATETIME NULL DEFAULT NULL");
        return $c = 'last_active';
    }
}
if(!function_exists('hpOnlineSettingCol')){
    function hpOnlineSettingCol($conn){
        static $c = null;
        if($c !== null) return $c;
        $r = $conn->query("SHOW COLUMNS FROM user_settings LIKE 'show_online_status'");
        return $c = ($r && $r->num_rows) ? 'show_online_status' : '';
    }
}
if(!function_exists('getOnlineMap')){
    function getOnlineMap($conn, $viewer_id, $ids){
        $map = [];
        $viewer_id = (int)$viewer_id;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function($v) use ($viewer_id){ return $v > 0 && $v !== $viewer_id; })));
        if(!$viewer_id || !$ids) return $map;
        $col = hpUserCol($conn);
        /* you only see other people's green dot if you share your own; each person's own choice (everyone / their trackers / nobody) decides who sees theirs */
        if(!(dz_viewer_mask($conn, $viewer_id) & 1)) return $map;
        $in   = implode(',', $ids);
        $win  = (int)DZ_ONLINE_WINDOW;
        $res = $conn->query("SELECT u.id FROM users u LEFT JOIN user_settings us ON us.user_id = u.id WHERE u.id IN ($in) AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND u.`$col` >= (NOW() - INTERVAL $win SECOND) AND " . dz_vis_ok_sql('us', 'u', $viewer_id, 'online') . " = 1");
        if($res) while($r = $res->fetch_assoc()) $map[(int)$r['id']] = true;
        return $map;
    }
}
if(!function_exists('dz_poster_online')){
    function dz_poster_online($conn, $poster_id, $viewer_id){
        $poster_id = (int)$poster_id; $viewer_id = (int)$viewer_id;
        if(!$poster_id || $poster_id === $viewer_id) return false;
        $m = getOnlineMap($conn, $viewer_id, [$poster_id]);
        return !empty($m[$poster_id]);
    }
}

/* ── AD FREQUENCY CAP + DIVERSITY GUARD ── */
if(!defined('HP_AD_GAP'))  define('HP_AD_GAP', 5);
if(!defined('HP_AD_LEAD')) define('HP_AD_LEAD', 3);
if(!defined('HP_AD_FIRST_CHANCE')) define('HP_AD_FIRST_CHANCE', 0.3);   /* chance an ad is the very first post */

if(!function_exists('hpFirstTag')){
    function hpFirstTag($caption){
        return preg_match('/#([a-zA-Z0-9_]+)/', (string)$caption, $m) ? strtolower($m[1]) : '';
    }
}
if(!function_exists('hpRunOk')){
    function hpRunOk($out, $p, $max){
        $u = $p['userId']; $t = hpFirstTag($p['caption'] ?? '');
        $ru = 0; $rt = 0; $uBroken = false; $tBroken = ($t === '');
        for($i = count($out) - 1; $i >= 0; $i--){
            $q = $out[$i];
            if(!$uBroken){ if($q['userId'] === $u) $ru++; else $uBroken = true; }
            if(!$tBroken){ if(hpFirstTag($q['caption'] ?? '') === $t) $rt++; else $tBroken = true; }
            if($uBroken && $tBroken) break;
        }
        return $ru < $max && $rt < $max;
    }
}
if(!function_exists('hpDiversityGuard')){
    function hpDiversityGuard($feed, $max = 2, $window = 30){
        $queue = array_values($feed); $out = [];
        while($queue){
            $pick = 0;
            $lim = min(count($queue), $window);
            for($i = 0; $i < $lim; $i++){ if(hpRunOk($out, $queue[$i], $max)){ $pick = $i; break; } }
            $out[] = $queue[$pick];
            array_splice($queue, $pick, 1);
        }
        return $out;
    }
}
if(!function_exists('hpApplyAdCap')){
    function hpApplyAdCap($feed, $gap = 5, $lead = 3, $guard = true){
        $organic = []; $ads = [];
        foreach($feed as $p){ if(!empty($p['isAd'])) $ads[] = $p; else $organic[] = $p; }
        if($guard) $organic = hpDiversityGuard($organic, 2);
        if(!$ads) return $organic;
        if(!$organic) return array_slice($ads, 0, 1);
        $out = []; $since = 0; $need = max(1, (int)$lead); $lastAd = null; $placed = 0;
        if((mt_rand() / mt_getrandmax()) < HP_AD_FIRST_CHANCE){
            $lastAd = array_shift($ads); $out[] = $lastAd;
            $need = max(1, (int)$gap); $placed++;
        }
        foreach($organic as $p){
            $out[] = $p; $since++;
            if($ads && $since >= $need){
                $pick = null;
                foreach($ads as $i => $a){
                    if(!$lastAd || ($a['userId'] !== $lastAd['userId'] && $a['adCtaUrl'] !== $lastAd['adCtaUrl'])){ $pick = $i; break; }
                }
                if($pick === null) $pick = 0;
                $lastAd = $ads[$pick]; $out[] = $lastAd; array_splice($ads, $pick, 1);
                $since = 0; $need = max(1, (int)$gap); $placed++;
            }
        }
        if(!$placed && $ads) $out[] = $ads[0];
        return $out;
    }
}

/* ── caption → escaped HTML (25-word cut, #tags, @mentions) + number format + url helper — used by the search list ── */
if(!function_exists('spCaptionHtml')){
    function spCaptionHtml($caption, $limit = 25){
        $caption = trim(str_replace(["\r","\n"], ' ', (string)$caption));
        if($caption === '') return '';
        $words = preg_split('/\s+/u', $caption);
        $cut = false;
        if(count($words) > $limit){ $words = array_slice($words, 0, $limit); $cut = true; }
        $text = htmlspecialchars(implode(' ', $words), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace_callback('/([#@])([a-zA-Z0-9_]+)/', function($m){
            if($m[1] === '#') return '<a class="cap-tag" href="search.php?focus=1&amp;mq=' . rawurlencode('#' . $m[2]) . '" onclick="event.stopPropagation()">#' . $m[2] . '</a>';
            return '<a class="cap-mention" href="profile.php?username=' . rawurlencode($m[2]) . '" onclick="event.stopPropagation()">@' . $m[2] . '</a>';
        }, $text);
        return $text . ($cut ? '<span class="cap-more">...</span>' : '');
    }
}
if(!function_exists('spFmt')){
    function spFmt($n){
        $n = (int)$n;
        if($n >= 1000000) return rtrim(rtrim(number_format($n/1000000, 1), '0'), '.') . 'M';
        if($n >= 1000)    return rtrim(rtrim(number_format($n/1000, 1), '0'), '.') . 'K';
        return (string)$n;
    }
}
if(!function_exists('spUrl')){
    function spUrl($f, $t){
        $q = [];
        if($f && $f !== 'trending') $q['filter'] = $f;
        if($t && $t !== 'all')      $q['tag']    = $t;
        return 'search.php' . ($q ? '?' . http_build_query($q) : '');
    }
}

/* ================================================================
   v11: "WHY AM I SEEING THIS" — built from the same signals the ranker uses
   ----------------------------------------------------------------
   dz_why_compact($s)  → "leg day" / "leg_day" / "#legday" all become "legday", so spellings of one topic compare equal
   dz_why_build($row, $ctx) → list of reasons, strongest first (max 4). Each reason is
        ['icon' => 'ri-…', 'title' => '…', 'text' => '…']   (plain text; the page escapes it)
   $ctx keys: following (bool), picked_creator (bool), aff_creator (float), aff_type (float), social (rows of the people
              you track who liked / reposted it), repost (['user'=>…, 'others'=>n] or null), topics (list of matched topic
              labels), picked_topics (list of the topics you chose that it matches), cf (bool), is_ad (bool).
================================================================ */

/* ================================================================
   VIDEO THUMBNAILS — the first frame of every video, saved as uploads/thumbs/<video file name>.jpg
   • the upload page can save one itself (dz_save_thumb_upload below); older videos get theirs made here the first time they are
     shown (needs ffmpeg on the server; at most 3 per page load so the feed never waits long)
   • the player paints it as the poster at once, so a video never opens on a black screen
   dz_thumb_url('uploads/abc.mp4') → 'uploads/thumbs/abc.mp4.jpg' or '' when there is none (yet)
================================================================ */
if(!function_exists('dz_thumb_url')){
    function dz_thumb_url($rel){
        static $made = 0, $ffmpeg = null;
        $rel = (string)$rel; if($rel === '') return '';
        $base = basename($rel);
        if(!preg_match('/\.(mp4|mov|m4v|webm|mkv|avi|3gp)$/i', $base)) return '';
        $dir  = __DIR__ . '/uploads/thumbs/';
        $out  = $dir . $base . '.jpg';
        $url  = 'uploads/thumbs/' . rawurlencode($base) . '.jpg';
        if(is_file($out)) return $url;
        if($made >= 3) return '';
        $src = __DIR__ . '/' . ltrim($rel, '/');
        if(!is_file($src) || !function_exists('exec')) return '';
        if($ffmpeg === null){
            $ffmpeg = false;
            foreach(['ffmpeg', 'C:\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\xampp\\ffmpeg\\bin\\ffmpeg.exe', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $c){
                $o = []; $rc = 1; @exec('"' . $c . '" -version 2>&1', $o, $rc);
                if($rc === 0){ $ffmpeg = $c; break; }
            }
        }
        if(!$ffmpeg) return '';
        if(!is_dir($dir)) @mkdir($dir, 0775, true);
        $made++;
        $o = []; $rc = 1;
        @exec('"' . $ffmpeg . '" -y -ss 0.1 -i ' . escapeshellarg($src) . ' -frames:v 1 -vf scale=480:-2 -q:v 5 ' . escapeshellarg($out) . ' 2>&1', $o, $rc);
        return (is_file($out) && filesize($out) > 0) ? $url : '';
    }
}
/* for the upload page: $_FILES['video_thumb'] (a small JPEG the browser cut from the first frame) → uploads/thumbs/<saved video file name>.jpg */
if(!function_exists('dz_save_thumb_upload')){
    function dz_save_thumb_upload($saved_video_file_name, $field = 'video_thumb'){
        if(empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return false;
        if(($_FILES[$field]['size'] ?? 0) > 800000) return false;
        $info = @getimagesize($_FILES[$field]['tmp_name']);
        if(!$info || $info[2] !== IMAGETYPE_JPEG) return false;
        $dir = __DIR__ . '/uploads/thumbs/';
        if(!is_dir($dir)) @mkdir($dir, 0775, true);
        return @move_uploaded_file($_FILES[$field]['tmp_name'], $dir . basename((string)$saved_video_file_name) . '.jpg');
    }
}

if(!function_exists('dz_why_compact')){
    function dz_why_compact($s){
        $s = strtolower(trim((string)$s));
        $s = ltrim($s, '#');
        if(function_exists('dz_norm_term')){ $n = dz_norm_term($s); if($n !== '' && $n !== null) $s = strtolower((string)$n); }
        $c = preg_replace('/[\s_\-]+/u', '', $s);
        return ($c === null || $c === '') ? $s : $c;
    }
}
if(!function_exists('dz_why_list')){
    function dz_why_list(array $a, $max = 3){
        $a = array_values(array_unique($a));
        $a = array_slice($a, 0, $max);
        if(count($a) <= 1) return $a ? $a[0] : '';
        return implode(', ', array_slice($a, 0, -1)) . ' and ' . end($a);
    }
}
if(!function_exists('dz_why_build')){
    function dz_why_build(array $row, array $ctx){
        $out  = [];
        $isAd = !empty($ctx['is_ad']);
        $who  = '@' . (string)($row['username'] ?? 'this account');
        $noun = $isAd ? 'ad' : 'post';

        /* 1. things the person chose themselves always come first (their choices outrank what was learned) */
        if(!empty($ctx['picked_creator'])){
            $out[] = ['icon'=>'ri-star-line', 'title'=>'You chose to see more of ' . $who, 'text'=>'You picked ' . $who . ' under Your Algorithm, so their ' . $noun . 's get a lift in your feed.'];
        }
        if(!empty($ctx['picked_topics'])){
            $out[] = ['icon'=>'ri-price-tag-3-line', 'title'=>'A topic you chose: ' . dz_why_list($ctx['picked_topics'], 2), 'text'=>'You asked for more of this topic under Your Algorithm.'];
        }
        /* 2. people */
        if(!empty($ctx['following'])){
            $out[] = ['icon'=>'ri-user-follow-line', 'title'=>'You track ' . $who, 'text'=>'Posts from people you track are lifted in your feed.'];
        }
        if(!empty($ctx['repost'])){
            $rp = $ctx['repost'];
            $o  = (int)($rp['others'] ?? 0);
            $out[] = ['icon'=>'ri-repeat-line', 'title'=>'@' . $rp['user'] . ($o > 0 ? ' and ' . $o . ($o === 1 ? ' other' : ' others') : '') . ' you track reposted this', 'text'=>'When someone you track reposts a ' . $noun . ', it can reach you even if you do not track the creator.'];
        } elseif(!empty($ctx['social'])){
            $names = [];
            foreach($ctx['social'] as $s){ $names[] = '@' . $s['username']; }
            $out[] = ['icon'=>'ri-heart-3-line', 'title'=>dz_why_list($names, 2) . (count($names) > 2 ? ' and others' : '') . ' you track liked this', 'text'=>'People you track have reacted to this ' . $noun . '.'];
        }
        /* 3. taste learned from what the person did */
        if(!empty($ctx['topics'])){
            $out[] = ['icon'=>'ri-lightbulb-flash-line', 'title'=>'It matches topics you like: ' . dz_why_list($ctx['topics'], 3), 'text'=>'Your recent likes, saves, reposts and time spent on posts point to these topics.'];
        }
        if(($ctx['aff_creator'] ?? 0) >= 2 && empty($ctx['following']) && empty($ctx['picked_creator'])){
            $out[] = ['icon'=>'ri-user-heart-line', 'title'=>'You have enjoyed posts from ' . $who, 'text'=>'You have liked, saved or reposted their content before.'];
        }
        if(($ctx['aff_type'] ?? 0) >= 2){
            $t = strtolower((string)($row['type'] ?? ''));
            $names = ['image'=>'images','video'=>'videos','gif'=>'GIFs','sticker'=>'stickers','stickers'=>'stickers','text'=>'text posts'];
            if(isset($names[$t])) $out[] = ['icon'=>'ri-layout-grid-line', 'title'=>'You often enjoy ' . $names[$t], 'text'=>'Your feed leans towards the formats you react to most.'];
        }
        if(!empty($ctx['cf'])){
            $out[] = ['icon'=>'ri-group-line', 'title'=>'People with a similar taste liked this', 'text'=>'People whose likes, saves and reposts look like yours reacted to this ' . $noun . '.'];
        }
        /* 4. discovery + popularity */
        $age = (int)($row['age_sec'] ?? 999999);
        $imp = (int)($row['impression_count'] ?? 0);
        $maxImp = defined('DZ_UNDEREXP_MAX_IMP') ? (int)DZ_UNDEREXP_MAX_IMP : 30;
        if(!$isAd && $age < 6 * 3600 && $imp <= $maxImp){
            $out[] = ['icon'=>'ri-seedling-line', 'title'=>'A new ' . $noun . ' from a small account', 'text'=>'Dezzme shows brand-new posts to a few people to see how they react, so new creators can be found.'];
        }
        $pop = (int)($row['like_count'] ?? 0) + 2 * (int)($row['repost_count'] ?? 0) + (int)($row['fav_count'] ?? 0);
        $popHidden = !empty($row['hide_likes']) && !empty($row['hide_reposts']) && !empty($row['hide_saves']);
        if(!$popHidden && $pop >= 25 && $age < 5 * 86400){
            $out[] = ['icon'=>'ri-fire-line', 'title'=>'It is getting a lot of attention', 'text'=>'Many people have liked, reposted or saved this ' . $noun . ' recently.'];
        }
        /* 5. fallback — always say something true */
        if(!$out){
            $out[] = ['icon'=>'ri-compass-3-line', 'title'=>'Something a little different', 'text'=>'Dezzme mixes in recent and popular ' . $noun . 's from outside your usual interests so your feed does not go stale. You can change how adventurous your feed is under Your Algorithm.'];
        }
        return array_slice($out, 0, 4);
    }
}

/* ================================================================
   BATCH HELPERS (search previews)
================================================================ */
if(!function_exists('dz_batch_user_previews')){
    function dz_batch_user_previews($conn, $user_ids, $current_user) {
        $previews = [];
        if (empty($user_ids)) return $previews;
        $ids = implode(',', array_map('intval', $user_ids));
        $blocked_sql = '';
        if ($current_user) {
            $b = [];
            $br = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id=$current_user");
            while ($r = $br->fetch_assoc()) $b[] = (int)$r['blocked_id'];
            if (!empty($b)) $blocked_sql = " AND m.uploaded_by NOT IN (" . implode(',', $b) . ") ";
        }
        $res = $conn->query("
            SELECT m.id, m.uploaded_by, m.file_name, m.type, m.caption, m.created_at
            FROM memes m
            JOIN users mu ON mu.id = m.uploaded_by
            WHERE m.uploaded_by IN ($ids)
              AND m.is_adult = 0
              AND m.is_archived = 0 AND mu.deactivated_at IS NULL AND LOWER(COALESCE(mu.user_rank,'')) NOT IN ('suspended','banned') AND NOT EXISTS (SELECT 1 FROM group_posts gpx JOIN `groups` gx ON gx.id = gpx.group_id WHERE gpx.meme_id = m.id AND gx.is_suspended = 1)
              $blocked_sql
            ORDER BY m.uploaded_by, m.created_at DESC
        ");
        while ($row = $res->fetch_assoc()) {
            $uid = (int)$row['uploaded_by'];
            if (!isset($previews[$uid])) $previews[$uid] = [];
            if (count($previews[$uid]) < 3) {
                $previews[$uid][] = ['id'=>(int)$row['id'],'file_name'=>$row['file_name'],'type'=>$row['type'],'caption'=>$row['caption']];
            }
        }
        return $previews;
    }
}
if(!function_exists('dz_batch_group_previews')){
    function dz_batch_group_previews($conn, $group_ids) {
        $previews = [];
        if (empty($group_ids)) return $previews;
        $ids = implode(',', array_map('intval', $group_ids));
        $res = $conn->query("
            SELECT gp.group_id, m.id, m.file_name, m.type, m.caption, gp.created_at
            FROM group_posts gp
            JOIN memes m ON m.id = gp.meme_id
            JOIN users mu ON mu.id = m.uploaded_by
            WHERE gp.group_id IN ($ids)
              AND m.is_archived = 0 AND mu.deactivated_at IS NULL AND LOWER(COALESCE(mu.user_rank,'')) NOT IN ('suspended','banned') AND NOT EXISTS (SELECT 1 FROM group_posts gpx JOIN `groups` gx ON gx.id = gpx.group_id WHERE gpx.meme_id = m.id AND gx.is_suspended = 1)
            ORDER BY gp.group_id, gp.created_at DESC
        ");
        while ($row = $res->fetch_assoc()) {
            $gid = (int)$row['group_id'];
            if (!isset($previews[$gid])) $previews[$gid] = [];
            if (count($previews[$gid]) < 3) {
                $previews[$gid][] = ['id'=>(int)$row['id'],'file_name'=>$row['file_name'],'type'=>$row['type'],'caption'=>$row['caption']];
            }
        }
        return $previews;
    }
}
if(!function_exists('dz_batch_user_bios')){
    function dz_batch_user_bios($conn, $user_ids, $current_user) {
        $bios = [];
        if (empty($user_ids)) return $bios;
        $ids_str = implode(',', array_map('intval', $user_ids));
        $bio_map = [];
        $bio_res = $conn->query("SELECT user_id, bio, COALESCE(is_fully_private,0) AS is_private FROM user_settings WHERE user_id IN ($ids_str)");
        while ($br = $bio_res->fetch_assoc()) {
            $bio_map[(int)$br['user_id']] = ['bio'=>$br['bio'] ?? '', 'is_private'=>(int)$br['is_private']];
        }
        $approved_ids = [];
        if ($current_user) {
            $ar = $conn->query("SELECT target_id FROM track_requests WHERE requester_id=$current_user AND target_id IN ($ids_str) AND status='approved'");
            while ($arow = $ar->fetch_assoc()) $approved_ids[] = (int)$arow['target_id'];
        }
        foreach ($user_ids as $uid) {
            $uid = (int)$uid;
            $bio_text = '';
            if (isset($bio_map[$uid])) {
                $visible = (!$bio_map[$uid]['is_private']) || ($current_user == $uid) || in_array($uid, $approved_ids);
                if ($visible) $bio_text = $bio_map[$uid]['bio'];
            }
            $bios[$uid] = $bio_text;
        }
        return $bios;
    }
}

/* ================================================================
   RANKING INPUT: this user's hashtag affinities (positive AND negative)
================================================================ */
if(!function_exists('getUserTopTags')){
    /* Returns [ top positive tags, tag=>score map, negative tag=>score map ]
       • reads dz_meme_tags → ALL hashtags on a post count, exact match (#game ≠ #gamer)
       • decay 0.004/hour ≈ 7-day half-life (was 0.03 ≈ 23 hours)
       • 'not_interested' / 'hide' are NEGATIVE signals
       • v4: the "tags" in the affinity map can also be media / caption topics (e.g. "glute")
       • v7: the fallback below ignores interactions from before "Reset what my feed has learned"
       • v8: the fast path merges spellings of one topic (glute / glutes / synonyms) into ONE entry
       • v9: spellings that only differ by "_", "-" or a space (leg_day / legday / leg day) share ONE slot too.
             All spellings stay in the list (so a post tagged #leg_day AND one tagged #legday both match), but only
             the topic counts towards the 16 favourite-topic / 10 negative limits. */
    function getUserTopTags($conn, $user_id){
        $top_tags = []; $tag_weights = []; $neg_weights = [];
        if(!$user_id) return [$top_tags, $tag_weights, $neg_weights];

        /* fast path: tastes are kept up to date in dz_user_affinity (one indexed query) */
        $aff = dz_load_affinity($conn, $user_id);
        if(!empty($aff['tag'])){
            /* group spellings of the same topic: key = normalised term with "_", "-" and spaces removed */
            $groups = [];   /* compact => ['s' => strongest score of the group, 'sp' => [spelling => score]] */
            foreach($aff['tag'] as $t => $sc){
                $n = function_exists('dz_norm_term') ? dz_norm_term((string)$t) : strtolower((string)$t);
                if($n === '') $n = strtolower((string)$t);
                if($n === '') continue;
                $n = strtolower((string)$n);
                $compact = preg_replace('/[\s_\-]+/u', '', $n);
                if($compact === null || $compact === '') $compact = $n;
                $sc = (float)$sc;
                if(!isset($groups[$compact])) $groups[$compact] = ['s' => $sc, 'sp' => []];
                elseif(abs($sc) > abs($groups[$compact]['s'])) $groups[$compact]['s'] = $sc;
                if(!isset($groups[$compact]['sp'][$n]) || abs($sc) > abs($groups[$compact]['sp'][$n])) $groups[$compact]['sp'][$n] = $sc;
            }
            /* strongest positive topics first */
            uasort($groups, function($a, $b){ return $b['s'] <=> $a['s']; });
            $posN = 0;
            foreach($groups as $g){
                if($g['s'] <= 0 || $posN >= 16) continue;
                $posN++;
                uasort($g['sp'], function($a, $b){ return abs($b) <=> abs($a); });
                foreach($g['sp'] as $spell => $_x){
                    if(isset($tag_weights[$spell])) continue;
                    $top_tags[] = $spell; $tag_weights[$spell] = round($g['s'], 4);
                }
            }
            /* strongest negative topics first */
            uasort($groups, function($a, $b){ return $a['s'] <=> $b['s']; });
            $negN = 0;
            foreach($groups as $g){
                if($g['s'] >= 0 || $negN >= 10) continue;
                $negN++;
                foreach($g['sp'] as $spell => $_x){
                    if(isset($neg_weights[$spell])) continue;
                    $neg_weights[$spell] = round($g['s'], 4);
                }
            }
            return [$top_tags, $tag_weights, $neg_weights];
        }

        /* fallback (no affinity rows yet): scan the last 60 days of interactions (v7: only since the last reset) */
        $since_sql = function_exists('dz_algo_since_sql') ? dz_algo_since_sql($conn, $user_id, 'ui.created_at') : '';
        try{
            $stmt = $conn->prepare("
                SELECT mt.tag,
                    SUM(
                        CASE ui.interaction_type
                            WHEN 'repost'         THEN 6.0
                            WHEN 'favorite'       THEN 4.0
                            WHEN 'share'          THEN 3.0
                            WHEN 'like'           THEN 2.0
                            WHEN 'view'           THEN 0.5
                            WHEN 'not_interested' THEN -3.0
                            WHEN 'hide'           THEN -3.0
                            ELSE 1.0
                        END
                        * EXP(-0.004 * TIMESTAMPDIFF(HOUR, ui.created_at, NOW()))
                    ) AS affinity_score
                FROM user_interactions ui
                JOIN dz_meme_tags mt ON mt.meme_id = ui.meme_id
                JOIN memes m ON m.id = ui.meme_id
                JOIN users u ON u.id = m.uploaded_by
                WHERE ui.user_id = ?
                  AND m.is_archived = 0 AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')
                  AND ui.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) $since_sql
                GROUP BY mt.tag
                HAVING ABS(affinity_score) > 0.5
                ORDER BY ABS(affinity_score) DESC
                LIMIT 25
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while($r = $res->fetch_assoc()){
                $t = strtolower((string)$r['tag']);
                if($t === '') continue;
                $s = round((float)$r['affinity_score'], 4);
                if($s > 0 && count($top_tags) < 16){ $top_tags[] = $t; $tag_weights[$t] = $s; }
                elseif($s < 0 && count($neg_weights) < 10){ $neg_weights[$t] = $s; }
            }
            $stmt->close();
        }catch(Throwable $e){}
        return [$top_tags, $tag_weights, $neg_weights];
    }
}

/* Builds ONE SQL multiplier from all the user's tags — boosts STACK (capped), negatives shrink.
   Range 0.2 … 2.5.  Returns '' when there is nothing to apply. Uses dz_meme_tags (exact match). */
if(!function_exists('dz_tag_boost_sql')){
    function dz_tag_boost_sql($conn, $top_tags, $pos_weights, $neg_weights){
        $w = [];
        foreach($top_tags as $t){
            $v = min(1.0, round(((float)($pos_weights[$t] ?? 0)) / 20.0, 3));
            if($v > 0) $w[strtolower($t)] = $v;
        }
        foreach($neg_weights as $t => $s){
            $v = max(-0.6, round(((float)$s) / 10.0, 3));
            if($v < 0) $w[strtolower($t)] = $v;
        }
        if(!$w) return '';
        $rows = [];
        foreach($w as $t => $v){
            if(!preg_match('/^[a-z0-9_]{1,50}$/', $t)) continue;
            $rows[] = "SELECT '" . $conn->real_escape_string($t) . "' AS tag, " . sprintf('%.3F', $v) . " AS w";
        }
        if(!$rows) return '';
        $union = implode(' UNION ALL ', $rows);
        return "GREATEST(0.2, LEAST(2.5, 1.0 + COALESCE((SELECT SUM(bw.w) FROM dz_meme_tags bmt JOIN ($union) bw ON bw.tag COLLATE utf8mb4_general_ci = bmt.tag WHERE bmt.meme_id = m.id), 0)))";
    }
}

/* ================================================================
   SUGGESTED USERS (Crossroads-style overlap score)
   Rows carry is_private_any (no Track button) and is_online (green dot).
================================================================ */
if(!function_exists('getSuggestedUsers')){
    function getSuggestedUsers($conn, $user_id, $limit = 20, $exclude_ids = []){
        $sug_user_id = (int)$user_id;
        if(!$sug_user_id) return [];

        $blocked_ids = [0];
        $bres = $conn->query("SELECT blocked_id FROM blocks WHERE blocker_id = $sug_user_id UNION SELECT blocker_id FROM blocks WHERE blocked_id = $sug_user_id");
        if($bres) while($br = $bres->fetch_assoc()) $blocked_ids[] = (int)$br['blocked_id'];

        $exclude_ids = !empty($exclude_ids) ? array_values(array_map('intval', $exclude_ids)) : [0];
        $blocked_ph = implode(',', array_fill(0, count($blocked_ids), '?'));
        $exclude_ph = implode(',', array_fill(0, count($exclude_ids), '?'));
        $limit = (int)$limit;

        $sql = "
            SELECT
                u.id, u.username, u.profile_pic,
                COALESCE((SELECT GREATEST(COALESCE(us.is_private,0), COALESCE(us.is_fully_private,0))
                          FROM user_settings us WHERE us.user_id = u.id LIMIT 1), 0) AS is_private_any,
                (SELECT COUNT(*) FROM followers f2 WHERE f2.follower_id=? AND f2.following_id=u.id) AS is_following,
                (CASE WHEN EXISTS (SELECT 1 FROM followers f1 JOIN followers f2b ON f1.following_id = f2b.following_id AND f1.follower_id = ? AND f2b.follower_id = u.id) THEN 1 ELSE 0 END) AS sig_follow,
                (CASE WHEN EXISTS (SELECT 1 FROM group_members gm1 JOIN group_members gm2 ON gm1.group_id = gm2.group_id AND gm1.user_id = ? AND gm2.user_id = u.id) THEN 1 ELSE 0 END) AS sig_group,
                (CASE WHEN EXISTS (SELECT 1 FROM likes l1 JOIN likes l2 ON l1.meme_id = l2.meme_id AND l1.user_id = ? AND l2.user_id = u.id) THEN 1 ELSE 0 END) AS sig_like,
                (CASE WHEN EXISTS (SELECT 1 FROM reposts r1 JOIN reposts r2 ON r1.meme_id = r2.meme_id AND r1.user_id = ? AND r2.user_id = u.id) THEN 1 ELSE 0 END) AS sig_repost,
                (CASE WHEN EXISTS (SELECT 1 FROM favorites fv1 JOIN favorites fv2 ON fv1.meme_id = fv2.meme_id AND fv1.user_id = ? AND fv2.user_id = u.id) THEN 1 ELSE 0 END) AS sig_fav,
                (CASE WHEN EXISTS (SELECT 1 FROM pod_discussion_replies pr1 JOIN pod_discussion_replies pr2 ON pr1.discussion_id = pr2.discussion_id AND pr1.user_id = ? AND pr2.user_id = u.id) THEN 1 ELSE 0 END) AS sig_pod,
                (CASE WHEN EXISTS (SELECT 1 FROM collection_items ci1 JOIN collections c1 ON ci1.collection_id = c1.id AND c1.user_id = ? JOIN collection_items ci2 ON ci1.meme_id = ci2.meme_id JOIN collections c2 ON ci2.collection_id = c2.id AND c2.user_id = u.id) THEN 1 ELSE 0 END) AS sig_collection,
                (CASE WHEN EXISTS (SELECT 1 FROM profile_views pv1 JOIN profile_views pv2 ON pv1.profile_id = pv2.profile_id AND pv1.viewer_id = ? AND pv2.viewer_id = u.id) THEN 1 ELSE 0 END) AS sig_view
            FROM users u
            WHERE u.id != ?
              AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND NOT EXISTS (SELECT 1 FROM family_links fl_h JOIN family_settings fs_h ON fs_h.link_id = fl_h.id WHERE fl_h.teen_id = u.id AND fl_h.status = 'active' AND fs_h.search_visible = 0)
              AND u.id NOT IN ($blocked_ph)
              AND u.id NOT IN ($exclude_ph)
              AND u.id NOT IN (SELECT following_id FROM followers WHERE follower_id = ?)
              AND NOT EXISTS (SELECT 1 FROM user_settings fp_s WHERE fp_s.user_id = u.id AND fp_s.is_fully_private = 1)
            HAVING (sig_follow + sig_group + sig_like + sig_repost + sig_fav + sig_pod + sig_collection + sig_view) >= 2
            ORDER BY (sig_follow + sig_group + sig_like + sig_repost + sig_fav + sig_pod + sig_collection + sig_view) DESC
            LIMIT $limit
        ";
        $types  = str_repeat('i', 9) . 'i' . str_repeat('i', count($blocked_ids)) . str_repeat('i', count($exclude_ids)) . 'i';
        $params = array_merge(array_fill(0, 9, $sug_user_id), [$sug_user_id], $blocked_ids, $exclude_ids, [$sug_user_id]);

        $stmt = $conn->prepare($sql);
        $refs = [];
        foreach($params as $k => $v){ $refs[$k] = &$params[$k]; }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while($r = $res->fetch_assoc()){
            $overlaps = [];
            if(!empty($r['sig_follow']))     $overlaps[] = 'same creators';
            if(!empty($r['sig_group']))      $overlaps[] = 'same Pods';
            if(!empty($r['sig_like']))       $overlaps[] = 'same content';
            if(!empty($r['sig_repost']))     $overlaps[] = 'same reposts';
            if(!empty($r['sig_fav']))        $overlaps[] = 'same saves';
            if(!empty($r['sig_pod']))        $overlaps[] = 'same discussions';
            if(!empty($r['sig_collection'])) $overlaps[] = 'similar collections';
            if(!empty($r['sig_view']))       $overlaps[] = 'same profiles viewed';
            $r['reason'] = implode(', ', array_slice($overlaps, 0, 2));
            $rows[] = $r;
        }

        if(count($rows) < $limit){   /* top up with random people */
            $have_ids = array_map(function($r){ return (int)$r['id']; }, $rows);
            $skip_ids = array_merge($blocked_ids, $exclude_ids, $have_ids, [0]);
            $skip_ph  = implode(',', array_fill(0, count($skip_ids), '?'));
            $need     = $limit - count($rows);
            $sql2 = "
                SELECT u.id, u.username, u.profile_pic,
                COALESCE((SELECT GREATEST(COALESCE(us.is_private,0), COALESCE(us.is_fully_private,0)) FROM user_settings us WHERE us.user_id = u.id LIMIT 1), 0) AS is_private_any,
                (SELECT COUNT(*) FROM followers f WHERE f.follower_id=? AND f.following_id=u.id) as is_following
                FROM users u
                WHERE u.id != ?
                  AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND NOT EXISTS (SELECT 1 FROM family_links fl_h JOIN family_settings fs_h ON fs_h.link_id = fl_h.id WHERE fl_h.teen_id = u.id AND fl_h.status = 'active' AND fs_h.search_visible = 0)
                  AND u.id NOT IN ($skip_ph)
                  AND u.id NOT IN (SELECT following_id FROM followers WHERE follower_id = ?)
                  AND NOT EXISTS (SELECT 1 FROM user_settings fp_s WHERE fp_s.user_id = u.id AND fp_s.is_fully_private = 1)
                ORDER BY RAND() LIMIT $need
            ";
            $types2  = 'ii' . str_repeat('i', count($skip_ids)) . 'i';
            $params2 = array_merge([$sug_user_id, $sug_user_id], $skip_ids, [$sug_user_id]);
            $stmt2 = $conn->prepare($sql2);
            $refs2 = [];
            foreach($params2 as $k => $v){ $refs2[$k] = &$params2[$k]; }
            array_unshift($refs2, $types2);
            call_user_func_array([$stmt2, 'bind_param'], $refs2);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while($r = $res2->fetch_assoc()){
                $r['sig_follow'] = $r['sig_group'] = $r['sig_like'] = $r['sig_repost'] = 0;
                $r['sig_fav'] = $r['sig_pod'] = $r['sig_collection'] = $r['sig_view'] = 0;
                $r['reason'] = '';
                $rows[] = $r;
            }
        }

        $online = getOnlineMap($conn, $sug_user_id, array_map(function($r){ return (int)$r['id']; }, $rows));
        foreach($rows as &$r){ $r['is_online'] = isset($online[(int)$r['id']]) ? 1 : 0; }
        unset($r);
        return $rows;
    }
}

/* ── featured Memepedia entries ── */
if(!function_exists('getMemepediaFeatured')){
    function getMemepediaFeatured($conn, $status = 'all', $limit = 60){
        $limit = (int)$limit;
        $where = '';
        if($status !== 'all'){
            $status_safe = $conn->real_escape_string($status);
            $where = " WHERE status = '$status_safe' ";
        }
        $rows = [];
        $res = $conn->query("SELECT id, title, summary, media_url, media_type, status, category, views, edit_count, created_at FROM meme_articles $where ORDER BY RAND() LIMIT $limit");
        if($res){
            while($r = $res->fetch_assoc()){
                $rows[] = [
                    'id'=>(int)$r['id'], 'title'=>$r['title'], 'summary'=>$r['summary'],
                    'mediaUrl'=>$r['media_url'], 'mediaType'=>$r['media_type'], 'status'=>$r['status'],
                    'category'=>$r['category'], 'views'=>(int)$r['views'], 'editCount'=>(int)$r['edit_count'],
                ];
            }
        }
        return $rows;
    }
}

/* ── Collections row ── */
if(!function_exists('getHomeCollectionsFeatured')){
    function getHomeCollectionsFeatured($conn, $limit = 30){
        $limit = (int)$limit;
        $rows = [];
        $res = $conn->query("
            SELECT c.id, c.name, c.user_id, c.is_public, u.username AS owner_username,
                   (SELECT COUNT(*) FROM collection_items ci JOIN memes cm ON cm.id = ci.meme_id JOIN users cu ON cu.id = cm.uploaded_by WHERE ci.collection_id = c.id AND cm.is_archived = 0 AND cu.deactivated_at IS NULL AND LOWER(COALESCE(cu.user_rank,'')) NOT IN ('suspended','banned')) AS item_count
            FROM collections c
            JOIN users u ON u.id = c.user_id
            WHERE c.is_public = 1 AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')
            ORDER BY RAND()
            LIMIT $limit
        ");
        if($res){
            while($r = $res->fetch_assoc()){
                $rows[] = ['id'=>(int)$r['id'],'name'=>$r['name'],'ownerId'=>(int)$r['user_id'],'ownerUsername'=>$r['owner_username'],'isPublic'=>(int)$r['is_public'],'itemCount'=>(int)$r['item_count'],'thumb'=>null];
            }
        }
        if($rows){
            $ids_str = implode(',', array_map(function($r){ return $r['id']; }, $rows));
            $thumb_map = [];
            $tres = $conn->query("
                SELECT ci.collection_id, m.file_name, m.type
                FROM collection_items ci
                JOIN memes m ON m.id = ci.meme_id
                JOIN users mu ON mu.id = m.uploaded_by
                WHERE ci.collection_id IN ($ids_str) AND m.is_archived = 0 AND mu.deactivated_at IS NULL AND LOWER(COALESCE(mu.user_rank,'')) NOT IN ('suspended','banned') AND NOT EXISTS (SELECT 1 FROM group_posts gpx JOIN `groups` gx ON gx.id = gpx.group_id WHERE gpx.meme_id = m.id AND gx.is_suspended = 1)
                ORDER BY ci.collection_id, ci.added_at DESC
            ");
            if($tres){
                while($t = $tres->fetch_assoc()){
                    $cid = (int)$t['collection_id'];
                    if(!isset($thumb_map[$cid])) $thumb_map[$cid] = ['file'=>$t['file_name'], 'type'=>$t['type']];
                }
            }
            foreach($rows as &$r){ $r['thumb'] = $thumb_map[$r['id']] ?? null; }
            unset($r);
        }
        return $rows;
    }
}

/* ── Sponsored list for the right-hand column ── */
if(!function_exists('getSidebarAds')){
    function getSidebarAds($conn, $limit = 3){
        global $user_id;
        $limit = (int)$limit;
        $want  = $limit;
        $adWords = !empty($user_id) ? dz_wf_get($conn, (int)$user_id)['ad'] : [];
        if($adWords) $limit = $limit * 6;   /* read extra, then drop the ones with filtered words */
        $rows = [];
        $res = $conn->query("
            SELECT memes.id, memes.file_name, memes.type, memes.caption, ma.cta_url, ma.cta_text
            FROM memes
            JOIN meme_ads ma ON ma.meme_id = memes.id
            JOIN users ON users.id = memes.uploaded_by
            WHERE memes.is_ad = 1
              AND memes.is_archived = 0 AND users.deactivated_at IS NULL AND LOWER(COALESCE(users.user_rank,'')) NOT IN ('suspended','banned')
              AND ma.status = 'approved'
              AND ma.cta_url IS NOT NULL AND ma.cta_url != ''
            ORDER BY RAND()
            LIMIT $limit
        ");
        if($res){
            while($r = $res->fetch_assoc()){
                $rows[] = ['id'=>(int)$r['id'],'file'=>'uploads/'.$r['file_name'],'type'=>$r['type'],'caption'=>$r['caption'],'ctaUrl'=>$r['cta_url'],'ctaText'=>$r['cta_text']];
            }
        }
        if($adWords){
            $rows = dz_wf_filter_rows($rows, $adWords, ['caption', 'ctaText']);
            $rows = array_slice($rows, 0, $want);
        }
        return $rows;
    }
}

/* ================================================================
   NAV USER / UNREAD (used by nav.php and the pages)
================================================================ */
$nav_pic  = 'uploads/mee.jpeg';
$nav_user = null;
if($user_id){
    $stmt = $conn->prepare("SELECT profile_pic, username FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $nav_user = $stmt->get_result()->fetch_assoc();
    if(!empty($nav_user['profile_pic'])) $nav_pic = $nav_user['profile_pic'];
}
$nav_name = $nav_user['username'] ?? '';

$total_unread = 0;
if(!empty($_SESSION['accounts'])){
    foreach($_SESSION['accounts'] as $_acc){
        $_acc = (int)$_acc;
        $_u = $conn->query("SELECT COUNT(*) as cnt FROM messages WHERE receiver_id = $_acc AND seen = 0");
        $total_unread += $_u ? (int)($_u->fetch_assoc()['cnt'] ?? 0) : 0;
    }
}

/* ================================================================
   JSON ENDPOINTS (GET ?type=…)
   autocomplete | sugg | following | memepedia | collections | sidebar_ads | rising | user | online
================================================================ */
if(isset($_GET['type']) && $_GET['type'] === 'autocomplete'){
    header('Content-Type: application/json');
    $q = trim((string)($_GET['q'] ?? ''));
    $results = [];

    if(strlen($q) >= 1){
        $like = "%$q%";

        /* PROFILE ID SEARCH */
        $isDZSearch = false; $dzLike = '';
        if(preg_match('/^dz-?([a-zA-Z0-9]*)/i', $q, $m)){
            $isDZSearch = true;
            $dzLike = 'DZ-' . strtoupper($m[1]) . '%';
        } elseif(strlen($q) >= 3){
            $isDZSearch = true;
            $dzLike = 'DZ-%' . strtoupper($q) . '%';
        }

        $userCols = "
            u.id, u.username, u.nickname, u.profile_pic, u.dz_id, COALESCE(u.is_verified,0) AS is_verified, (LOWER(COALESCE(u.user_rank,'')) = 'suspended') AS is_suspended,
            (SELECT COUNT(*) FROM followers f WHERE f.follower_id = ? AND f.following_id = u.id) AS is_following,
            COALESCE((SELECT us.is_fully_private FROM user_settings us WHERE us.user_id = u.id LIMIT 1), 0) AS is_private,
            COALESCE((SELECT GREATEST(COALESCE(us.is_private,0), COALESCE(us.is_fully_private,0)) FROM user_settings us WHERE us.user_id = u.id LIMIT 1), 0) AS hide_track,
            (SELECT COUNT(*) FROM blocks b WHERE b.blocker_id = ? AND b.blocked_id = u.id) AS is_blocked,
            (SELECT COUNT(*) FROM track_requests tr WHERE tr.requester_id = ? AND tr.target_id = u.id AND tr.status = 'approved') AS is_track_approved";
        $userRow = function($row, $type){
            return [
                'type'=>$type, 'id'=>(int)$row['id'], 'username'=>$row['username'], 'nickname'=>$row['nickname'] ?? '',
                'profile_pic'=>$row['profile_pic'] ?? '', 'dz_id'=>$row['dz_id'] ?? '', 'is_verified'=>(int)($row['is_verified'] ?? 0),
                'is_following'=>(int)$row['is_following'], 'is_private'=>(int)$row['is_private'], 'hide_track'=>(int)$row['hide_track'],
                'is_blocked'=>(int)$row['is_blocked'], 'is_track_approved'=>(int)$row['is_track_approved'],
                'is_suspended'=>(int)($row['is_suspended'] ?? 0),
            ];
        };

        if($isDZSearch){
            $stmt0 = $conn->prepare("SELECT $userCols FROM users u WHERE u.dz_id LIKE ? AND u.id != ? AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) <> 'banned' AND NOT EXISTS (SELECT 1 FROM family_links fl_h JOIN family_settings fs_h ON fs_h.link_id = fl_h.id WHERE fl_h.teen_id = u.id AND fl_h.status = 'active' AND fs_h.search_visible = 0)" . dz_fp_hide_sql('u.id', $current_user) . " LIMIT 4");
            $stmt0->bind_param("iiisi", $current_user, $current_user, $current_user, $dzLike, $current_user);
            $stmt0->execute();
            $res0 = $stmt0->get_result();
            while($row = $res0->fetch_assoc()) $results[] = $userRow($row, 'profile_id');
            $stmt0->close();
        }

        /* USERS */
        $stmt = $conn->prepare("
            SELECT $userCols FROM users u
            WHERE (u.username LIKE ? OR (u.nickname IS NOT NULL AND u.nickname != '' AND u.nickname LIKE ?))
              AND u.id != ? AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) <> 'banned' AND NOT EXISTS (SELECT 1 FROM family_links fl_h JOIN family_settings fs_h ON fs_h.link_id = fl_h.id WHERE fl_h.teen_id = u.id AND fl_h.status = 'active' AND fs_h.search_visible = 0)
            " . dz_fp_hide_sql('u.id', $current_user) . "
            LIMIT 6
        ");
        $stmt->bind_param("iiissi", $current_user, $current_user, $current_user, $like, $like, $current_user);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) $results[] = $userRow($row, 'user');
        $stmt->close();

        /* PAGES */
        try {
            $stmtP = $conn->prepare("
                SELECT p.id, p.name, p.category, p.pic,
                    ((SELECT COUNT(*) FROM profile_page_followers f WHERE f.page_id = p.id)
                     + (SELECT COUNT(*) FROM profile_page_follows g WHERE g.target_id = p.id)) AS trackers,
                    EXISTS(SELECT 1 FROM profile_page_followers f2 WHERE f2.page_id = p.id AND f2.user_id = ?) AS is_following,
                    (SELECT a.role FROM profile_page_admins a WHERE a.page_id = p.id AND a.user_id = ? LIMIT 1) AS my_role
                FROM profile_pages p
                WHERE p.name LIKE ? OR p.category LIKE ?
                ORDER BY (p.name LIKE ?) DESC, trackers DESC, p.id DESC
                LIMIT 5
            ");
            if($stmtP){
                $stmtP->bind_param("iisss", $current_user, $current_user, $like, $like, $like);
                $stmtP->execute();
                $resP = $stmtP->get_result();
                while($row = $resP->fetch_assoc()){
                    $results[] = ['type'=>'page','id'=>(int)$row['id'],'name'=>$row['name'],'category'=>$row['category'] ?? '','pic'=>$row['pic'] ?? '','trackers'=>(int)$row['trackers'],'is_following'=>(int)$row['is_following'],'my_role'=>$row['my_role'] ?? ''];
                }
                $stmtP->close();
            }
        } catch(Throwable $e){}

        /* PODS */
        $stmt2 = $conn->prepare("
            SELECT g.id, g.title, g.group_pic, g.pd_id, g.is_adult, g.created_by, g.is_suspended, ou.username AS owner_username,
                (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count,
                (SELECT COUNT(*) FROM group_members gm2 WHERE gm2.group_id = g.id AND gm2.user_id = ?) AS is_joined,
                EXISTS(SELECT 1 FROM muted_groups mg WHERE mg.user_id = ? AND mg.group_id = g.id) AS is_muted,
                EXISTS(SELECT 1 FROM group_notif_off gno WHERE gno.user_id = ? AND gno.group_id = g.id) AS notif_off
            FROM groups g
            LEFT JOIN users ou ON ou.id = g.created_by
            WHERE (g.title LIKE ? OR g.pd_id LIKE ?)
            ORDER BY (g.pd_id LIKE ?) DESC, (g.title LIKE ?) DESC, g.id DESC
            LIMIT 4
        ");
        $pdLike = preg_match('/^pd-?([a-zA-Z0-9]*)/i', $q, $pm) ? 'PD-' . strtoupper($pm[1]) . '%' : '%' . strtoupper(preg_replace('/^pd-?/i', '', $q)) . '%';
        if(strlen($q) < 3 && !preg_match('/^pd/i', $q)) $pdLike = 'PD-\x00none';
        $stmt2->bind_param("iiissss", $current_user, $current_user, $current_user, $like, $pdLike, $pdLike, $like);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while($row = $res2->fetch_assoc()){
            $results[] = [
                'type'=>'group','id'=>(int)$row['id'],'title'=>$row['title'],'group_pic'=>$row['group_pic'] ?? '','pd_id'=>$row['pd_id'] ?? '',
                'member_count'=>(int)$row['member_count'],'is_joined'=>(int)$row['is_joined'],'is_adult'=>(int)$row['is_adult'],
                'is_muted'=>(int)$row['is_muted'],'notif_off'=>(int)$row['notif_off'],
                'owner_id'=>(int)($row['created_by'] ?? 0),'owner_username'=>$row['owner_username'] ?? '',
                'is_suspended'=>(int)($row['is_suspended'] ?? 0),
            ];
        }
        $stmt2->close();

        /* MEMES / POSTS */
        $stmt3 = $conn->prepare("
            SELECT m.id, m.caption, m.file_name, m.type AS meme_type, u.username, u.profile_pic
            FROM memes m JOIN users u ON u.id = m.uploaded_by
            WHERE m.caption LIKE ? AND m.is_archived = 0 AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND NOT EXISTS (SELECT 1 FROM group_posts gpx JOIN `groups` gx ON gx.id = gpx.group_id WHERE gpx.meme_id = m.id AND gx.is_suspended = 1)
            " . dz_fp_hide_sql('m.uploaded_by', $current_user) . "
            ORDER BY m.created_at DESC LIMIT 5
        ");
        $stmt3->bind_param("s", $like);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        while($row = $res3->fetch_assoc()){
            $results[] = ['type'=>'meme','id'=>(int)$row['id'],'caption'=>$row['caption'],'file_name'=>$row['file_name'],'meme_type'=>$row['meme_type'],'username'=>$row['username'],'profile_pic'=>$row['profile_pic'] ?? ''];
        }
        $stmt3->close();

        /* COLLECTIONS (public) */
        $stmt4 = $conn->prepare("
            SELECT c.id, c.name, c.user_id, u.username AS owner_username,
                (SELECT COUNT(*) FROM collection_items ci JOIN memes cm ON cm.id = ci.meme_id JOIN users cu ON cu.id = cm.uploaded_by WHERE ci.collection_id = c.id AND cm.is_archived = 0 AND cu.deactivated_at IS NULL AND LOWER(COALESCE(cu.user_rank,'')) NOT IN ('suspended','banned')) AS item_count
            FROM collections c JOIN users u ON u.id = c.user_id
            WHERE c.is_public = 1 AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND c.name LIKE ?
            " . dz_fp_hide_sql('c.user_id', $current_user) . "
            ORDER BY c.created_at DESC LIMIT 4
        ");
        $stmt4->bind_param("s", $like);
        $stmt4->execute();
        $res4 = $stmt4->get_result();
        while($row = $res4->fetch_assoc()){
            $results[] = ['type'=>'collection','id'=>(int)$row['id'],'name'=>$row['name'],'owner_id'=>(int)$row['user_id'],'owner_username'=>$row['owner_username'],'item_count'=>(int)$row['item_count']];
        }
        $stmt4->close();

        /* SOUNDS */
        $stmt5 = $conn->prepare("
            SELECT s.id, s.title, s.user_id, u.username
            FROM sounds s JOIN users u ON u.id = s.user_id
            WHERE s.title LIKE ? AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')
            " . dz_fp_hide_sql('s.user_id', $current_user) . "
            ORDER BY s.created_at DESC LIMIT 4
        ");
        $stmt5->bind_param("s", $like);
        $stmt5->execute();
        $res5 = $stmt5->get_result();
        while($row = $res5->fetch_assoc()){
            $results[] = ['type'=>'sound','id'=>(int)$row['id'],'title'=>$row['title'],'user_id'=>(int)$row['user_id'],'username'=>$row['username']];
        }
        $stmt5->close();

        /* SOUND FOLDERS (public) */
        $stmt6 = $conn->prepare("
            SELECT sf.id, sf.name, sf.user_id, u.username AS owner_username,
                (SELECT COUNT(*) FROM sound_folder_items sfi WHERE sfi.folder_id = sf.id) AS item_count
            FROM sound_folders sf JOIN users u ON u.id = sf.user_id
            WHERE sf.is_public = 1 AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND sf.name LIKE ?
            " . dz_fp_hide_sql('sf.user_id', $current_user) . "
            ORDER BY sf.created_at DESC LIMIT 3
        ");
        $stmt6->bind_param("s", $like);
        $stmt6->execute();
        $res6 = $stmt6->get_result();
        while($row = $res6->fetch_assoc()){
            $results[] = ['type'=>'sound_collection','id'=>(int)$row['id'],'name'=>$row['name'],'owner_id'=>(int)$row['user_id'],'owner_username'=>$row['owner_username'],'item_count'=>(int)$row['item_count']];
        }
        $stmt6->close();

        /* MEMEPEDIA */
        $stmt7 = $conn->prepare("
            SELECT ma.id, ma.title, ma.status, ma.category, ma.media_url, ma.media_type, ma.summary, ma.created_at, ma.created_by,
                   au.username AS author_username, au.profile_pic AS author_pic
            FROM meme_articles ma
            LEFT JOIN users au ON au.id = ma.created_by AND au.deactivated_at IS NULL AND LOWER(COALESCE(au.user_rank,'')) NOT IN ('suspended','banned')
            WHERE ma.title LIKE ?
            ORDER BY ma.views DESC LIMIT 4
        ");
        $stmt7->bind_param("s", $like);
        $stmt7->execute();
        $res7 = $stmt7->get_result();
        while($row = $res7->fetch_assoc()){
            $results[] = [
                'type'=>'memepedia','id'=>(int)$row['id'],'title'=>$row['title'],'status'=>$row['status'],'category'=>$row['category'],
                'media_url'=>$row['media_url'] ?? '','media_type'=>$row['media_type'] ?? '','summary'=>$row['summary'] ?? '',
                'created_at'=>$row['created_at'] ?? '','author_id'=>(int)($row['created_by'] ?? 0),
                'author_username'=>$row['author_username'] ?? '','author_pic'=>$row['author_pic'] ?? '',
            ];
        }
        $stmt7->close();

        /* previews + bios */
        $pu = []; $pg = [];
        foreach($results as $r){
            if($r['type'] === 'user' || $r['type'] === 'profile_id') $pu[] = $r['id'];
            elseif($r['type'] === 'group') $pg[] = $r['id'];
        }
        $pu = array_values(array_unique($pu)); $pg = array_values(array_unique($pg));
        $upm = dz_batch_user_previews($conn, $pu, $current_user);
        $gpm = dz_batch_group_previews($conn, $pg);
        $ubm = dz_batch_user_bios($conn, $pu, $current_user);
        foreach($results as &$r){
            if($r['type'] === 'user' || $r['type'] === 'profile_id'){ $r['preview_posts'] = $upm[$r['id']] ?? []; $r['bio'] = $ubm[$r['id']] ?? ''; }
            elseif($r['type'] === 'group'){ $r['preview_posts'] = $gpm[$r['id']] ?? []; }
        }
        unset($r);
    }
    echo json_encode($results);
    exit;
}

if(isset($_GET['type'])){
    $type = $_GET['type'];
    header('Content-Type: application/json');
    /* release the session lock so these requests run in parallel instead of queueing */
    if(session_status() === PHP_SESSION_ACTIVE) session_write_close();

    if($type === 'poll'){
        /* one post's poll (the comment sheet loads it) */
        $mid = (int)($_GET['meme_id'] ?? 0);
        if(!$user_id || $mid <= 0 || !dz_can_touch_meme($conn, (int)$user_id, $mid)){ echo json_encode(['ok'=>true,'poll'=>null]); exit; }
        dz_poll_ensure($conn);
        $pm = dz_polls_for_memes($conn, (int)$user_id, [$mid]);
        echo json_encode(['ok'=>true,'poll'=>$pm[$mid] ?? null]);
        exit;
    }
    if($type === 'sugg'){
        $sugg = $user_id ? getSuggestedUsers($conn, $user_id, 20) : [];
        if(!empty($sugg)){
            $sugg_ids = array_map(function($u){ return (int)$u['id']; }, $sugg);
            $ids_str  = implode(',', $sugg_ids);
            $priv_map = [];
            $pr = $conn->query("SELECT user_id, is_fully_private FROM user_settings WHERE user_id IN ($ids_str)");
            if($pr) while($p = $pr->fetch_assoc()) $priv_map[(int)$p['user_id']] = (int)$p['is_fully_private'];
            $ver_map = [];
            $vq = $conn->query("SELECT id, is_verified FROM users WHERE id IN ($ids_str)");
            if($vq) while($v = $vq->fetch_assoc()) $ver_map[(int)$v['id']] = (int)$v['is_verified'];
            $recent_map = [];
            $rc = $conn->query("SELECT DISTINCT uploaded_by FROM memes WHERE uploaded_by IN ($ids_str) AND created_at >= NOW() - INTERVAL 24 HOUR AND is_archived = 0");
            if($rc) while($r = $rc->fetch_assoc()) $recent_map[(int)$r['uploaded_by']] = true;
            foreach($sugg as &$u){
                $uid = (int)$u['id'];
                $u['is_fully_private'] = $priv_map[$uid] ?? 0;
                $u['has_new_post']     = isset($recent_map[$uid]) ? 1 : 0;
                $u['is_verified']      = $ver_map[$uid] ?? 0;
            }
            unset($u);
        }
        echo json_encode($sugg);

    } elseif($type === 'following'){
        $following_list = [];
        if($user_id){
            $stmt = $conn->prepare("SELECT u.id, u.username, u.profile_pic FROM followers f JOIN users u ON u.id = f.following_id WHERE f.follower_id = ? AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') ORDER BY u.username ASC");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while($row = $res->fetch_assoc()) $following_list[] = $row;
        }
        echo json_encode($following_list);

    } elseif($type === 'memepedia'){
        echo json_encode(getMemepediaFeatured($conn, $_GET['status'] ?? 'all', 60));

    } elseif($type === 'collections'){
        echo json_encode(getHomeCollectionsFeatured($conn, 30));

    } elseif($type === 'sidebar_ads'){
        echo json_encode(getSidebarAds($conn, 3));

    } elseif($type === 'rising'){
        /* posts gaining fast but still small (under 150 likes+reposts, last 72h) — counts from meme_stats */
        $ex = [0];
        if($user_id){
            $uid_i = (int)$user_id;
            $q = $conn->query("SELECT blocked_id AS x FROM blocks WHERE blocker_id=$uid_i UNION SELECT blocker_id FROM blocks WHERE blocked_id=$uid_i UNION SELECT muted_id FROM muted_users WHERE muter_id=$uid_i");
            if($q) while($r = $q->fetch_assoc()) $ex[] = (int)$r['x'];
            $ex[] = $uid_i;
        }
        $exs = implode(',', array_unique($ex));
        $textOnlySql = $DZ_TEXT_ONLY ? " AND COALESCE(LOWER(memes.type),'text') NOT IN ('image','video','gif','sticker','stickers') AND NOT EXISTS (SELECT 1 FROM meme_media mmx WHERE mmx.meme_id = memes.id) " : "";
        $rows = [];
        $res = $conn->query("
            SELECT memes.id, memes.file_name, memes.type, memes.caption, memes.created_at, memes.uploaded_by, memes.hide_likes,
                   users.id AS uid, users.username, users.profile_pic,
                   COALESCE(ms.likes,0) AS likes_n,
                   (COALESCE(memes.trend_velocity,0) + (COALESCE(ms.likes,0) + COALESCE(ms.reposts,0)*2) / (TIMESTAMPDIFF(HOUR, memes.created_at, NOW()) + 2)) AS velocity
            FROM memes
            JOIN users ON users.id = memes.uploaded_by
            LEFT JOIN meme_stats ms ON ms.meme_id = memes.id
            WHERE memes.is_ad = 0 AND memes.is_adult = 0
              AND memes.is_archived = 0 AND users.deactivated_at IS NULL AND LOWER(COALESCE(users.user_rank,'')) NOT IN ('suspended','banned') AND NOT EXISTS (SELECT 1 FROM group_posts gpx JOIN `groups` gx ON gx.id = gpx.group_id WHERE gpx.meme_id = memes.id AND gx.is_suspended = 1)
              AND memes.created_at >= NOW() - INTERVAL 72 HOUR
              $textOnlySql
              AND memes.uploaded_by NOT IN ($exs)
              AND memes.uploaded_by NOT IN (SELECT user_id FROM user_settings WHERE is_fully_private = 1)
              AND COALESCE(ms.likes,0) + COALESCE(ms.reposts,0) < 150
            HAVING velocity > 0
            ORDER BY velocity DESC
            LIMIT 24
        ");
        if($res) while($r = $res->fetch_assoc()){
            $rows[] = [
                'id'=>(int)$r['id'], 'file'=>'uploads/'.$r['file_name'], 'type'=>$r['type'], 'pic'=>$r['profile_pic'],
                'userId'=>(string)$r['uid'], 'username'=>$r['username'], 'caption'=>$r['caption'],
                'likes'=> dz_hidden($r,'hide_likes') ? null : (int)$r['likes_n'],
            ];
        }
        $om = getOnlineMap($conn, $user_id, array_map('intval', array_column($rows, 'userId')));
        foreach($rows as &$rw){ $rw['online'] = !empty($om[(int)$rw['userId']]); }
        unset($rw);
        echo json_encode($rows);

    } elseif($type === 'user'){
        /* @username → id, used when someone taps a mention */
        $u = trim((string)($_GET['u'] ?? ''));
        $out = ['id' => null];
        if($u !== ''){
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND deactivated_at IS NULL LIMIT 1");
            $stmt->bind_param("s", $u);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            if($r) $out['id'] = (int)$r['id'];
        }
        echo json_encode($out);

    } elseif($type === 'online'){
        /* light poll that keeps the green dots fresh; doubles as a heartbeat */
        if($user_id){ $c = hpUserCol($conn); $conn->query("UPDATE users SET `$c` = NOW() WHERE id = " . (int)$user_id); }
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))), 0, 300);
        echo json_encode(array_map('strval', array_keys(getOnlineMap($conn, $user_id, $ids))));

    } else {
        echo json_encode([]);
    }
    exit;
}

/* ================================================================
   AJAX ACTIONS (POST)
================================================================ */
if($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mark_viewed']) && $user_id){
    $mid = intval($_POST['mark_viewed']);
    if($mid <= 0 || !dz_can_touch_meme($conn, $user_id, $mid)){ echo "ok"; exit(); }   /* v12: only posts this person can actually see count */
    /* one 'view' per user per post per 24h (INSERT IGNORE did nothing without a unique key) */
    $stmt = $conn->prepare("SELECT 1 FROM user_interactions WHERE user_id=? AND meme_id=? AND interaction_type='view' AND created_at >= (NOW() - INTERVAL 1 DAY) LIMIT 1");
    $stmt->bind_param("ii", $user_id, $mid);
    $stmt->execute();
    $already = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if(!$already && $mid > 0) recordInteraction($conn, $user_id, $mid, 'view');
    if($mid > 0) dz_record_views($conn, $user_id, [$mid], 'open');   /* same 24 h rule as impressions, so an open + a scroll-past is one view */
    echo "ok";
    exit();
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])){
    header('Content-Type: application/json');
    $action  = $_POST['action'];
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    dz_collab_handle_request($conn, (int)$user_id);   /* collab_search / collab_manage / collab_invite / collab_remove / collab_respond */

    /* ── impressions: the page reports which posts were actually seen (≥60% visible for 1 second) ── */
    if($action === 'impressions'){
        if(session_status() === PHP_SESSION_ACTIVE) session_write_close();   /* don't hold the session lock */
        /* real views (logged in or not): the posts the page says were really on screen */
        $__vids = []; $__vdw = [];
        foreach(explode(',', (string)($_POST['ids'] ?? '')) as $x){ $x = (int)$x; if($x > 0) $__vids[$x] = $x; }
        foreach(explode(',', (string)($_POST['dw'] ?? '')) as $pair){ $pp = explode(':', $pair); if(count($pp) === 2 && (int)$pp[0] > 0) $__vdw[(int)$pp[0]] = (int)$pp[1]; }
        if($__vids) dz_record_views($conn, $user_id, array_values($__vids), dz_view_source());
        if($__vdw)  dz_view_add_watch($conn, $user_id, $__vdw);
        if($user_id){
            $want = [];
            foreach(explode(',', (string)($_POST['ids'] ?? '')) as $x){ $x = (int)$x; if($x > 0) $want[$x] = $x; }
            $want = array_slice(array_values($want), 0, 50);
            $dw = [];   /* "id:milliseconds,id:milliseconds" — how long posts were on screen (skips / lingers) */
            foreach(explode(',', (string)($_POST['dw'] ?? '')) as $pair){
                $pp = explode(':', $pair);
                if(count($pp) === 2 && (int)$pp[0] > 0) $dw[(int)$pp[0]] = (int)$pp[1];
            }
            $dw = array_slice($dw, 0, 50, true);
            if($want){
                try{
                    $ids = [];
                    $vr = $conn->query("SELECT id FROM memes WHERE id IN (" . implode(',', $want) . ")");
                    if($vr) while($x = $vr->fetch_assoc()) $ids[] = (int)$x['id'];
                    $ins = $conn->prepare("INSERT IGNORE INTO dz_feed_impressions (user_id, meme_id, seen_count, first_seen, last_seen) VALUES (?, ?, 1, NOW(), NOW())");
                    $upd = $conn->prepare("UPDATE dz_feed_impressions SET seen_count = seen_count + 1, last_seen = NOW() WHERE user_id = ? AND meme_id = ?");
                    $cnt = $conn->prepare("INSERT INTO meme_stats (meme_id, impressions) VALUES (?, 1) ON DUPLICATE KEY UPDATE impressions = impressions + 1");
                    foreach($ids as $mid){
                        $ins->bind_param("ii", $user_id, $mid); $ins->execute();
                        if($ins->affected_rows === 1){ $cnt->bind_param("i", $mid); $cnt->execute(); }   /* first time this person saw it → one more viewer */
                        else { $upd->bind_param("ii", $user_id, $mid); $upd->execute(); }
                    }
                }catch(Throwable $e){}
            }
            if($dw) dz_record_dwell($conn, $user_id, $dw);   /* after the "seen" rows exist, so a lingered post is not counted twice */
        }
        echo json_encode(['status'=>'ok', 'views'=>dz_view_counts($conn, array_values($__vids))]);   /* live counts for the Zeems modal */
        exit;
    }

    /* ── zeems_serves: posts the zeems modal really showed (after 1 s on screen) are logged as served, like a page feed ── */
    if($action === 'zeems_serves'){
        if(session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if($user_id){
            try{
                $log = []; $seen = [];
                foreach(explode(',', (string)($_POST['items'] ?? '')) as $pair){
                    $pp = explode(':', $pair);
                    if(count($pp) === 2 && (int)$pp[0] > 0 && !isset($seen[(int)$pp[0]])){ $seen[(int)$pp[0]] = 1; $log[] = [(int)$pp[0], max(1, (int)$pp[1])]; }
                    if(count($log) >= 50) break;
                }
                if($log) dz_log_serves($conn, $user_id, $log, 1, dz_algo_variant($user_id), max(1, min(104729, (int)($_POST['seed'] ?? 1))));
            }catch(Throwable $e){}
        }
        echo json_encode(['status'=>'ok']);
        exit;
    }

    /* ── view_stats: the owner's breakdown of one post (total, unique viewers, watch time, last 7 days, sources) ── */
    if($action === 'view_stats'){
        $mid = (int)($_POST['meme_id'] ?? 0);
        $own = false;
        if($user_id && $mid > 0){
            $st = $conn->prepare("SELECT uploaded_by FROM memes WHERE id = ?");
            $st->bind_param("i", $mid); $st->execute();
            $rw = $st->get_result()->fetch_assoc(); $st->close();
            $own = $rw && (int)$rw['uploaded_by'] === $user_id;
        }
        if(!$own){ echo json_encode(['status'=>'error','message'=>'only the owner can see this']); exit; }
        echo json_encode(['status'=>'ok','stats'=>dz_view_stats($conn, $mid)]);
        exit;
    }

    /* ── poll_vote: vote / change vote / remove vote (option_id 0). Open polls only; same visibility rules as liking the post ── */
    if($action === 'poll_vote'){
        dz_csrf_check();
        if(!$user_id){ echo json_encode(['ok'=>false,'error'=>'Log in to vote.']); exit; }
        $poll_id = (int)($_POST['poll_id'] ?? 0); $opt_id = (int)($_POST['option_id'] ?? 0);
        if($poll_id <= 0 || $opt_id < 0){ echo json_encode(['ok'=>false,'error'=>'invalid request']); exit; }
        dz_poll_ensure($conn);
        try{
            $now = time(); $hits = $_SESSION['dz_poll_hits'] ?? [];
            $hits = array_values(array_filter(is_array($hits) ? $hits : [], function($t) use ($now){ return (int)$t > $now - 60; }));
            if(count($hits) >= 30){ echo json_encode(['ok'=>false,'error'=>'Slow down a little.']); exit; }
            $hits[] = $now; $_SESSION['dz_poll_hits'] = $hits;
            $st = $conn->prepare("SELECT meme_id, (ends_at IS NOT NULL AND ends_at <= NOW()) AS closed FROM polls WHERE id = ? LIMIT 1");
            $st->bind_param("i", $poll_id); $st->execute();
            $p = $st->get_result()->fetch_assoc(); $st->close();
            if(!$p || !dz_can_touch_meme($conn, (int)$user_id, (int)$p['meme_id'])){ echo json_encode(['ok'=>false,'error'=>'Poll not found.']); exit; }
            $pmid = (int)$p['meme_id'];
            if(!empty($p['closed'])){
                $pm = dz_polls_for_memes($conn, (int)$user_id, [$pmid]);
                echo json_encode(['ok'=>false,'error'=>'This poll has ended.','poll'=>$pm[$pmid] ?? null]); exit;
            }
            if($opt_id === 0){
                $d = $conn->prepare("DELETE FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                $d->bind_param("ii", $poll_id, $user_id); $d->execute(); $d->close();
            } else {
                $o = $conn->prepare("SELECT 1 FROM poll_options WHERE id = ? AND poll_id = ? LIMIT 1");
                $o->bind_param("ii", $opt_id, $poll_id); $o->execute();
                $okOpt = $o->get_result()->num_rows > 0; $o->close();
                if(!$okOpt){ echo json_encode(['ok'=>false,'error'=>'invalid choice']); exit; }
                $v = $conn->prepare("INSERT INTO poll_votes (poll_id, user_id, option_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE option_id = VALUES(option_id)");
                $v->bind_param("iii", $poll_id, $user_id, $opt_id); $v->execute(); $v->close();
            }
            $pm = dz_polls_for_memes($conn, (int)$user_id, [$pmid]);
            echo json_encode(['ok'=>true,'poll'=>$pm[$pmid] ?? null]);
        }catch(Throwable $e){ error_log('[dz poll] vote failed: '.$e->getMessage()); echo json_encode(['ok'=>false,'error'=>'Could not save your vote. Try again.']); }
        exit;
    }

    /* ── toggle_download: the creator turns "Download" on / off for one of their posts ── */
    if($action === 'toggle_download'){
        dz_csrf_check();   /* v12 */
        $mid = (int)($_POST['meme_id'] ?? 0);
        $v = ($user_id && $mid > 0) ? dz_toggle_download($conn, $user_id, $mid) : null;
        if($v === null){ echo json_encode(['status'=>'error','message'=>'only the owner can change this']); exit; }
        echo json_encode(['status'=>'ok','allow_download'=>$v]);
        exit;
    }

    /* ── toggle_follow ── */
    if($action === 'toggle_follow' && !isset($_POST['meme_id'])){
        dz_csrf_check();   /* v12 */
        if(!$user_id){ echo json_encode(['status'=>'error','message'=>'not logged in']); exit; }
        $target_id = intval($_POST['user_id'] ?? 0);
        if($target_id <= 0 || $target_id === $user_id){ echo json_encode(['status'=>'error','message'=>'invalid user']); exit; }
        $stmt = $conn->prepare("SELECT id FROM followers WHERE follower_id=? AND following_id=?");
        $stmt->bind_param("ii", $user_id, $target_id);
        $stmt->execute();
        $__was_following = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if(!$__was_following){
            /* v12: following needs a real, active, un-blocked account; a private account only after the request was approved (request_track) */
            $tq = $conn->prepare("SELECT COALESCE(us.is_fully_private,0) AS priv FROM users u LEFT JOIN user_settings us ON us.user_id=u.id WHERE u.id=? AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') LIMIT 1");
            $tq->bind_param("i", $target_id); $tq->execute();
            $tr = $tq->get_result()->fetch_assoc(); $tq->close();
            if(!$tr){ echo json_encode(['status'=>'error','message'=>'user not found']); exit; }
            $bq = $conn->prepare("SELECT 1 FROM blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1");
            $bq->bind_param("iiii", $user_id, $target_id, $target_id, $user_id); $bq->execute();
            $isb = $bq->get_result()->num_rows > 0; $bq->close();
            if($isb){ echo json_encode(['status'=>'error','message'=>'not available']); exit; }
            if(!empty($tr['priv'])){
                $aq = $conn->prepare("SELECT 1 FROM track_requests WHERE requester_id=? AND target_id=? AND status='approved' LIMIT 1");
                $aq->bind_param("ii", $user_id, $target_id); $aq->execute();
                $okp = $aq->get_result()->num_rows > 0; $aq->close();
                if(!$okp){ echo json_encode(['status'=>'private','message'=>'This account is private. Send a track request instead.']); exit; }
            }
        }
        if($__was_following){
            $stmt = $conn->prepare("DELETE FROM followers WHERE follower_id=? AND following_id=?");
            $stmt->bind_param("ii", $user_id, $target_id);
            $stmt->execute();
            echo json_encode(['status'=>'ok','following'=>false]);
        } else {
            $stmt = $conn->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?,?)");
            $stmt->bind_param("ii", $user_id, $target_id);
            $stmt->execute();
            echo json_encode(['status'=>'ok','following'=>true]);
        }
        exit;
    }

    /* ── toggle_group ── */
    if($action === 'toggle_group' && isset($_POST['group_id'])){
        dz_csrf_check();   /* v12 */
        $gid = (int)$_POST['group_id'];
        if(!$user_id || $gid <= 0){ echo json_encode(['status'=>'error','message'=>'not logged in']); exit; }
        $ck = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=?");
        $ck->bind_param("ii", $gid, $user_id); $ck->execute();
        $in = $ck->get_result()->num_rows > 0; $ck->close();
        if($in){
            $dq = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=?");
            $dq->bind_param("ii", $gid, $user_id); $dq->execute(); $dq->close();
            echo json_encode(['status'=>'ok','joined'=>false]);
        } else {
            /* v12: joining only when the pod exists. NOTE: private pods / join questions / bans are enforced on group.php, NOT here —
               if this endpoint is not used for joining anywhere, replace this branch with an error so the only way in is group.php. */
            $gq = $conn->prepare("SELECT 1 FROM groups WHERE id=? LIMIT 1");
            $gq->bind_param("i", $gid); $gq->execute();
            $gx = $gq->get_result()->num_rows > 0; $gq->close();
            if(!$gx){ echo json_encode(['status'=>'error','message'=>'pod not found']); exit; }
            $iq = $conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            $iq->bind_param("ii", $gid, $user_id); $iq->execute(); $iq->close();
            echo json_encode(['status'=>'ok','joined'=>true]);
        }
        exit;
    }

    /* ── request_track (private accounts) ── */
    if($action === 'request_track' && isset($_POST['user_id'])){
        dz_csrf_check();   /* v12 */
        $target = (int)$_POST['user_id'];
        if(!$user_id || $target <= 0 || $target === $user_id){ echo json_encode(['status'=>'error','message'=>'invalid request']); exit; }
        $ck = $conn->prepare("SELECT 1 FROM users u WHERE u.id=? AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id=? AND b.blocked_id=u.id) OR (b.blocker_id=u.id AND b.blocked_id=?)) LIMIT 1");
        $ck->bind_param("iii", $target, $user_id, $user_id); $ck->execute();
        $okt = $ck->get_result()->num_rows > 0; $ck->close();
        if(!$okt){ echo json_encode(['status'=>'error','message'=>'not available']); exit; }
        $ex = $conn->prepare("SELECT id FROM track_requests WHERE requester_id=? AND target_id=? LIMIT 1");
        $ex->bind_param("ii", $user_id, $target); $ex->execute();
        $had = $ex->get_result()->num_rows > 0; $ex->close();
        if(!$had){ $ins = $conn->prepare("INSERT INTO track_requests (requester_id, target_id) VALUES (?, ?)"); $ins->bind_param("ii", $user_id, $target); $ins->execute(); $ins->close(); }
        echo json_encode(['status'=>'ok']);
        exit;
    }

    /* ── like / repost / favorite ── */
    $toggleMap = [
        'toggle_like'     => ['likes',     'like',     'hide_likes'],
        'toggle_repost'   => ['reposts',   'repost',   'hide_reposts'],
        'toggle_favorite' => ['favorites', 'favorite', 'hide_saves'],
    ];
    if(isset($toggleMap[$action])){
        dz_csrf_check();   /* v9 */
        list($tbl, $itype, $hidefield) = $toggleMap[$action];
        if(!$user_id || !$meme_id){ echo json_encode(['success'=>false]); exit; }
        $stmt = $conn->prepare("SELECT id FROM `$tbl` WHERE user_id=? AND meme_id=?");
        $stmt->bind_param("ii", $user_id, $meme_id);
        $stmt->execute();
        if($stmt->get_result()->num_rows){
            $stmt = $conn->prepare("DELETE FROM `$tbl` WHERE user_id=? AND meme_id=?");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();
            $stmt = $conn->prepare("DELETE FROM user_interactions WHERE user_id=? AND meme_id=? AND interaction_type=?");
            $stmt->bind_param("iis", $user_id, $meme_id, $itype);
            $stmt->execute();
            dz_affinity_bump($conn, $user_id, $meme_id, -dz_affinity_weight($itype), true);   /* un-like / un-save: take the taste signal back (never below zero) */
        } else {
            if(!dz_can_touch_meme($conn, $user_id, $meme_id)){ echo json_encode(['success'=>false,'message'=>'not available']); exit; }   /* v12 */
            $stmt = $conn->prepare("INSERT INTO `$tbl` (user_id,meme_id) VALUES (?,?)");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();
            recordInteraction($conn, $user_id, $meme_id, $itype);
        }
        $new_count = (int)countRows($conn, $tbl, $meme_id);
        dz_stat_set($conn, $meme_id, $tbl, $new_count);
        echo json_encode(['success'=>true, 'count'=> dz_hidden(dz_post_flags($conn,$meme_id), $hidefield) ? null : $new_count]);
        exit;
    }

    /* ── SHARE (to tracked users via message) ── */
    if($action === 'share_post'){
        dz_csrf_check();   /* v12 */
        if(!$user_id){ echo json_encode(['status'=>'error','message'=>'not logged in']); exit; }
        $receiver_ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string)($_POST['receiver_ids'] ?? ''))),
            function($v) use ($user_id){ return $v > 0 && $v !== $user_id; }
        )));
        if(!empty($receiver_ids)){   /* never send to a deactivated person */
            $vr = $conn->query("SELECT id FROM users WHERE id IN (".implode(',', array_map('intval', $receiver_ids)).") AND deactivated_at IS NULL
                                AND id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id=".(int)$user_id." UNION SELECT blocker_id FROM blocks WHERE blocked_id=".(int)$user_id.")");   /* v12: never message someone who blocked you / you blocked */
            $receiver_ids = [];
            if($vr) while($x = $vr->fetch_assoc()) $receiver_ids[] = (int)$x['id'];
        }
        if(!$meme_id || empty($receiver_ids)){ echo json_encode(['status'=>'error','message'=>'invalid request']); exit; }

        if(!dz_can_touch_meme($conn, $user_id, $meme_id)){ echo json_encode(['status'=>'error','message'=>'meme not found']); exit; }   /* v12: exists, visible to the sender (blocks / private) */

        $sent_to = [];
        $stmt_share = $conn->prepare("INSERT INTO shares (user_id, meme_id, share_type) VALUES (?, ?, 'message')");
        $stmt_msg   = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, seen) VALUES (?, ?, ?, 0)");
        foreach($receiver_ids as $rid){
            $stmt_share->bind_param("ii", $user_id, $meme_id);
            $stmt_share->execute();
            $msg_text = "[meme:$meme_id]";
            $stmt_msg->bind_param("iis", $user_id, $rid, $msg_text);
            $stmt_msg->execute();
            recordInteraction($conn, $user_id, $meme_id, 'share');
            $sent_to[] = $rid;
        }
        $share_total = (int)countRows($conn, 'shares', $meme_id);
        dz_stat_set($conn, $meme_id, 'shares', $share_total);
        echo json_encode([
            'status'=>'ok', 'success'=>true,
            'count'=> dz_hidden(dz_post_flags($conn,$meme_id),'hide_shares') ? null : $share_total,
            'sent_to'=>$sent_to
        ]);
        exit;
    }

    /* ── HIDE ALL ADS FROM ONE ADVERTISER ── */
    if($action === 'hide_ads_from_user'){
        dz_csrf_check();   /* v12 */
        $target = intval($_POST['target_id'] ?? 0);
        if($user_id && $target && $target !== $user_id){
            $stmt = $conn->prepare("INSERT IGNORE INTO hidden_ad_advertisers (user_id, hidden_user_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $target);
            $stmt->execute();
            unset($_SESSION['dz_feed_cache']);
            echo json_encode(['status'=>'ok']);
        } else echo json_encode(['status'=>'error']);
        exit;
    }

    /* ── block_user ── */
    if($action === 'block_user' && isset($_POST['target_id'])){
        dz_csrf_check();   /* v9 */
        $target = (int)$_POST['target_id'];
        if($user_id && $target && $target !== $user_id){
            $stmt = $conn->prepare("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $target);
            $stmt->execute();
            $stmt2 = $conn->prepare("DELETE FROM followers WHERE (follower_id=? AND following_id=?) OR (follower_id=? AND following_id=?)");
            $stmt2->bind_param("iiii", $user_id, $target, $target, $user_id);
            $stmt2->execute();
            unset($_SESSION['dz_feed_cache']);
            echo json_encode(['status'=>'ok']);
        } else echo json_encode(['status'=>'error']);
        exit;
    }

    /* ── not_interested — negative signal for THIS user only; the post is excluded from their feed ──
       v11: also clears the remembered feed (like algorithm_settings.php does) so a tab that is already open stops serving the old ranking,
            and answers with 'already' => true when the post was marked before (the page can still hide it). */
    if($action === 'not_interested' && $meme_id){
        dz_csrf_check();   /* v9 */
        $already = false;
        if($user_id){
            $stmt = $conn->prepare("SELECT 1 FROM user_interactions WHERE user_id=? AND meme_id=? AND interaction_type='not_interested' LIMIT 1");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();
            $already = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if(!$already) recordInteraction($conn, $user_id, $meme_id, 'not_interested');
            unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);
        }
        echo json_encode(['status'=>'ok', 'success'=>true, 'already'=>$already]);
        exit;
    }

    /* ── interested — the "Interested" button on the little question that appears on suggested posts (zeems modal / home / text feed) ──
       A positive taste signal for THIS user only: lifts this creator / topics / format in their feed. Never touches likes. */
    if($action === 'interested' && $meme_id){
        dz_csrf_check();
        if($user_id){
            try{
                $stmt = $conn->prepare("SELECT 1 FROM user_interactions WHERE user_id=? AND meme_id=? AND interaction_type='interested' LIMIT 1");
                $stmt->bind_param("ii", $user_id, $meme_id);
                $stmt->execute();
                $had = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if(!$had){
                    try{
                        $ins = $conn->prepare("INSERT INTO user_interactions (user_id, meme_id, interaction_type) VALUES (?, ?, 'interested')");
                        $ins->bind_param("ii", $user_id, $meme_id);
                        $ins->execute();
                        $ins->close();
                    }catch(Throwable $e){ /* the column may not accept a new type: the taste signal below still counts */ }
                    if(function_exists('dz_affinity_bump')){
                        $w = function_exists('dz_affinity_weight') ? (float)dz_affinity_weight('interested') : 0.0;
                        if($w <= 0) $w = 2.0;
                        try{ dz_affinity_bump($conn, $user_id, $meme_id, $w); }catch(Throwable $e){}
                    }
                    unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);
                }
            }catch(Throwable $e){}
        }
        echo json_encode(['status'=>'ok', 'success'=>true]);
        exit;
    }

    /* ── undo_not_interested — takes the "not interested" mark off ONE post (the Undo button in the zeems modal / the Undo bar) ──
       v11: also gives back the small taste penalty that post caused (same as "Bring back" on algorithm_settings.php). */
    if($action === 'undo_not_interested' && $meme_id){
        dz_csrf_check();
        if($user_id){
            $stmt = $conn->prepare("DELETE FROM user_interactions WHERE user_id=? AND meme_id=? AND interaction_type='not_interested'");
            $stmt->bind_param("ii", $user_id, $meme_id);
            $stmt->execute();
            $n = (int)$stmt->affected_rows;
            $stmt->close();
            if($n > 0 && function_exists('dz_affinity_bump')){ try{ dz_affinity_bump($conn, $user_id, $meme_id, -dz_affinity_weight('not_interested'), true, true); }catch(Throwable $e){} }
            unset($_SESSION['dz_feed_cache'], $_SESSION['dz_pop_tags']);
        }
        echo json_encode(['status'=>'ok', 'success'=>true]);
        exit;
    }

    /* ── report_meme ── */
    if($action === 'report_meme'){
        dz_csrf_check();   /* v12 */
        if($meme_id && $user_id){
            $stmt = $conn->prepare("SELECT uploaded_by FROM memes WHERE id=?");
            $stmt->bind_param("i", $meme_id);
            $stmt->execute();
            $owner = $stmt->get_result()->fetch_assoc()['uploaded_by'] ?? 0;
            if($owner){
                $stmt = $conn->prepare("INSERT INTO reports (reporter_id, reported_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $user_id, $owner);
                $stmt->execute();
            }
        }
        echo json_encode(['status'=>'ok']);
        exit;
    }

    /* ── CONNECTED ZEEM — a linked post's full payload (archived / deactivated / blocked / private / 18+ never returned) ── */
    if($action === 'get_connected_meme'){
        $cid = $meme_id;
        $cu  = $user_id;
        if(!$cid){ echo json_encode(['ok'=>false]); exit; }

        $cst = $conn->prepare("
            SELECT m.*, u.username, u.nickname, u.profile_pic, u.is_verified,
                ma.cta_text AS ad_cta_text, ma.cta_url AS ad_cta_url, ma.cta_color AS ad_cta_color, ma.status AS ad_status
            FROM memes m
            JOIN users u ON u.id = m.uploaded_by
            LEFT JOIN meme_ads ma ON ma.meme_id = m.id
            WHERE m.id = ? AND m.is_archived = 0 AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND NOT EXISTS (SELECT 1 FROM group_posts gpx JOIN `groups` gx ON gx.id = gpx.group_id WHERE gpx.meme_id = m.id AND gx.is_suspended = 1) LIMIT 1
        ");
        $cst->bind_param("i", $cid);
        $cst->execute();
        $cm = $cst->get_result()->fetch_assoc();
        $cst->close();

        $ok   = (bool)$cm;
        $cuid = $ok ? (int)$cm['uploaded_by'] : 0;

        if($ok && $cu){
            $b = $conn->query("SELECT 1 FROM blocks WHERE (blocker_id=$cu AND blocked_id=$cuid) OR (blocker_id=$cuid AND blocked_id=$cu) LIMIT 1");
            if($b && $b->num_rows) $ok = false;
        }
        if($ok && $cuid !== $cu){
            $pv = $conn->query("SELECT is_fully_private FROM user_settings WHERE user_id=$cuid LIMIT 1");
            if($pv && ($pr = $pv->fetch_assoc()) && !empty($pr['is_fully_private'])){
                $ap = $conn->query("SELECT 1 FROM track_requests WHERE requester_id=$cu AND target_id=$cuid AND status='approved' LIMIT 1");
                if(!$ap || !$ap->num_rows) $ok = false;
            }
        }
        if($ok && !empty($cm['is_adult'])){
            $ad = $conn->query("SELECT 1 FROM group_posts gp JOIN group_members gm ON gm.group_id=gp.group_id WHERE gp.meme_id=$cid AND gm.user_id=$cu LIMIT 1");
            if(!$ad || !$ad->num_rows) $ok = false;
        }
        if($ok && !empty($cm['is_ad']) && ($cm['ad_status'] ?? '') !== 'approved') $ok = false;
        if(!$ok){ echo json_encode(['ok'=>false]); exit; }

        $like_count   = (int)countRows($conn,'likes',$cid);
        $repost_count = (int)countRows($conn,'reposts',$cid);
        $fav_count    = (int)countRows($conn,'favorites',$cid);
        $share_count  = (int)countRows($conn,'shares',$cid);

        $user_liked = $user_reposted = $user_faved = $is_following_c = false;
        if($cu){
            $user_liked     = $conn->query("SELECT id FROM likes WHERE meme_id=$cid AND user_id=$cu")->num_rows > 0;
            $user_reposted  = $conn->query("SELECT id FROM reposts WHERE meme_id=$cid AND user_id=$cu")->num_rows > 0;
            $user_faved     = $conn->query("SELECT id FROM favorites WHERE meme_id=$cid AND user_id=$cu")->num_rows > 0;
            $is_following_c = $conn->query("SELECT 1 FROM followers WHERE follower_id=$cu AND following_id=$cuid")->num_rows > 0;
        }

        $media = [['file'=>'uploads/'.$cm['file_name'], 'type'=>$cm['type']]];
        $mr = $conn->query("SELECT file_name, type FROM meme_media WHERE meme_id=$cid ORDER BY position ASC");
        if($mr) while($x = $mr->fetch_assoc()) $media[] = ['file'=>'uploads/'.$x['file_name'], 'type'=>$x['type']];

        $grp = null;
        $gr = $conn->query("SELECT g.id, g.title FROM group_posts gp JOIN groups g ON g.id=gp.group_id WHERE gp.meme_id=$cid LIMIT 1");
        if($gr) $grp = $gr->fetch_assoc();

        $aud = null;
        $ar = $conn->query("SELECT a.id AS audio_id, a.title, a.artist, a.file_name, mau.is_original, mau.music_volume, mau.video_audio_volume FROM meme_audio mau JOIN audios a ON a.id=mau.audio_id WHERE mau.meme_id=$cid LIMIT 1");
        if($ar) $aud = $ar->fetch_assoc();

        $sproof = [];
        if($cu){
            $sp = $conn->query("
                SELECT u.id, u.username, u.profile_pic, 'like' AS action_type
                FROM likes l JOIN users u ON u.id=l.user_id JOIN followers f ON f.following_id=u.id
                WHERE l.meme_id=$cid AND f.follower_id=$cu AND u.id!=$cu AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')
                UNION ALL
                SELECT u.id, u.username, u.profile_pic, 'repost' AS action_type
                FROM reposts r JOIN users u ON u.id=r.user_id JOIN followers f ON f.following_id=u.id
                WHERE r.meme_id=$cid AND f.follower_id=$cu AND u.id!=$cu AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')
            ");
            $raw = [];
            if($sp) while($s = $sp->fetch_assoc()) $raw[] = $s;
            usort($raw, function($a,$b){ return ($a['action_type']==='repost'?0:1) <=> ($b['action_type']==='repost'?0:1); });
            foreach(array_slice($raw, 0, 4) as $s) $sproof[] = ['id'=>(int)$s['id'],'username'=>$s['username'],'profile_pic'=>$s['profile_pic'],'action_type'=>$s['action_type']];
        }

        $post = [
            'id'=>$cid, 'file'=>'uploads/'.$cm['file_name'], 'type'=>$cm['type'], 'media'=>$media,
            'pic'=> !empty($cm['profile_pic']) ? $cm['profile_pic'] : 'uploads/mee.jpeg',
            'userId'=>(string)$cuid, 'username'=>$cm['username'], 'nickname'=>$cm['nickname'] ?? '', 'isVerified'=>!empty($cm['is_verified']),
            'caption'=> $DZ_IS_SEARCH ? str_replace(["\r","\n"], ' ', (string)$cm['caption']) : (string)$cm['caption'],
            'time'=>$cm['created_at'],
            'likeCount'   => dz_hidden($cm,'hide_likes')   ? null : $like_count,
            'repostCount' => dz_hidden($cm,'hide_reposts') ? null : $repost_count,
            'favCount'    => dz_hidden($cm,'hide_saves')   ? null : $fav_count,
            'shareCount'  => dz_hidden($cm,'hide_shares')  ? null : $share_count,
            'views'       => (int)($cm['views'] ?? 0),
            'collab'=>(dz_collab_payload($conn, [(int)$cm['id']], (int)$user_id)[(int)$cm['id']] ?? null), 'hideLikes'=>dz_hidden($cm,'hide_likes'), 'hideReposts'=>dz_hidden($cm,'hide_reposts'),
            'hideSaves'=>dz_hidden($cm,'hide_saves'), 'hideShares'=>dz_hidden($cm,'hide_shares'),
            'liked'=>$user_liked, 'reposted'=>$user_reposted, 'faved'=>$user_faved,
            'isFollowing'=>$is_following_c, 'isYou'=>($cuid === $cu),
            'online'=>dz_poster_online($conn, $cuid, $cu),
            'isPartnership'=>!empty($cm['is_partnership']), 'partnerBrand'=>$cm['partner_brand'] ?? '',
            'isAiGenerated'=>!empty($cm['is_ai_generated']),
            'isAd'=>!empty($cm['is_ad']), 'adCtaText'=>$cm['ad_cta_text'] ?? '', 'adCtaUrl'=>$cm['ad_cta_url'] ?? '', 'adCtaColor'=>$cm['ad_cta_color'] ?? '#FF4C4C',
            'connectedMemeId'=> !empty($cm['connected_meme_id']) ? (int)$cm['connected_meme_id'] : null,
            'connectedLabel' => !empty($cm['connected_label']) ? $cm['connected_label'] : null,
            'groupId'=>$grp ? (int)$grp['id'] : null, 'groupTitle'=>$grp ? $grp['title'] : null,
            'audioId'=>$aud ? (int)$aud['audio_id'] : null, 'audioTitle'=>$aud ? $aud['title'] : null,
            'audioArtist'=>($aud && !empty($aud['artist'])) ? $aud['artist'] : null,
            'audioFile'=>$aud ? 'uploads/'.$aud['file_name'] : null,
            'audioIsOriginal'=>(bool)($aud && !empty($aud['is_original'])),
            'musicVolume'=>$aud ? (int)$aud['music_volume'] : 80,
            'videoAudioVolume'=>$aud ? (int)$aud['video_audio_volume'] : 40,
            'socialProof'=>$sproof,
        ];
        echo json_encode(['ok'=>true, 'post'=>$post], JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

/* ================================================================
   PAGE RENDER FROM HERE: blocks / mutes / personalisation
================================================================ */
$blocked_users = [];
$muted_users   = [];
$muted_group_meme_ids = [];
$user_top_tags = [];
$user_tag_weights = [];
$user_neg_tags = [];

if($user_id){
    $stmt = $conn->prepare("SELECT blocked_id FROM blocks WHERE blocker_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()) $blocked_users[] = (int)$r['blocked_id'];

    $stmt = $conn->prepare("SELECT muted_id FROM muted_users WHERE muter_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if($res) while($r = $res->fetch_assoc()) $muted_users[] = (int)$r['muted_id'];

    $stmt = $conn->prepare("SELECT DISTINCT gp.meme_id FROM muted_groups mg JOIN group_posts gp ON gp.group_id = mg.group_id WHERE mg.user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if($res) while($r = $res->fetch_assoc()) $muted_group_meme_ids[] = (int)$r['meme_id'];

    $_tm = microtime(true);
    list($user_top_tags, $user_tag_weights, $user_neg_tags) = getUserTopTags($conn, $user_id);
    dz_time('taste_load', $_tm);
}
if(mt_rand(1, 10) === 1) dz_heal_tags($conn);   /* tags any new post whose upload script doesn't call dz_sync_meme_tags yet */

/* ================================================================
   FILTER + RANKING ALGORITHM
   Search keeps its own ordering exactly as it was (RAND-based, no shares);
   home / dezztext use the seeded order and count shares.
================================================================ */
$filter = is_string($_GET['filter'] ?? null) ? $_GET['filter'] : 'trending';
$tag    = is_string($_GET['tag'] ?? null) ? $_GET['tag'] : 'all';
$tag_key = strtolower(ltrim(trim((string)$tag), '#'));      /* exact-match key for dz_meme_tags */
$search_query = $DZ_IS_SEARCH ? trim((string)($_GET['q'] ?? '')) : '';

/* paging: home / dezztext / search = 150 posts per page (?page=2…).
   The seed is passed back by the client so pages 2, 3… keep the SAME shuffle as page 1. */
if(!defined('DZ_FEED_PAGE')) define('DZ_FEED_PAGE', 150);
$page_size = $DZ_IS_SEARCH ? 150 : DZ_FEED_PAGE;      /* search is paged like home now (150 a page, ?page=2…) */
if(!empty($_GET['feed_json']) && isset($_GET['limit'])) $page_size = max(10, min(150, (int)$_GET['limit']));   /* the zeems modal asks for small chunks of one ranking session */
$page      = max(1, min(200, (int)($_GET['page'] ?? 1)));
$offset    = ($page - 1) * $page_size;
$feed_seed = isset($_GET['seed']) ? max(1, min(104729, (int)$_GET['seed'])) : mt_rand(1, 104729);
/* when this feed session started — pages 2, 3… send it back so "already seen" and post age are judged at the same moment */
$feed_asof = isset($_GET['asof']) ? (int)$_GET['asof'] : time();
if($feed_asof <= 0 || $feed_asof > time()) $feed_asof = time();

$trend_base = '';
if($DZ_IS_SEARCH){
    // search ordering — same as before, except the random parts now follow the feed seed (RAND() would reshuffle on every page)
    switch($filter){
        case 'most_liked':    $order_logic = "like_count DESC"; break;
        case 'most_reposted': $order_logic = "repost_count DESC"; break;
        case 'most_favorited':$order_logic = "fav_count DESC"; break;
        case 'most_viewed':   $order_logic = "m.views DESC"; break;
        case 'newest':        $order_logic = "m.created_at DESC"; break;
        case 'following':     $order_logic = "((m.id * $feed_seed) % 104729)"; break;
        case 'discover':      $order_logic = "((m.id * $feed_seed) % 104729)"; break;
        default:
            $trend_base  = "(((like_count * 4.0) + (repost_count * 6.0) + (fav_count * 3.0) + (LOG10(m.views + 1) * 2.0)) / POW(TIMESTAMPDIFF(HOUR, m.created_at, NOW()) + 2, 1.5)) + (((m.id * $feed_seed) % 1000) / 2000)";
            $order_logic = "($trend_base) DESC";
            break;
    }
} else {
    switch($filter){
        case 'most_liked':    $order_logic = "like_count DESC"; break;
        case 'most_reposted': $order_logic = "repost_count DESC"; break;
        case 'most_favorited':$order_logic = "fav_count DESC"; break;
        case 'most_shared':   $order_logic = "share_count DESC"; break;
        case 'most_viewed':   $order_logic = "m.views DESC"; break;
        case 'newest':        $order_logic = "m.created_at DESC"; break;
        case 'following':     $order_logic = "((m.id * $feed_seed) % 104729)"; break;
        case 'discover':      $order_logic = "((m.id * $feed_seed) % 104729)"; break;
        default:
            $trend_base  = "(((like_count * 4.0) + (repost_count * 6.0) + (fav_count * 3.0) + (share_count * 5.0) + (LOG10(m.views + 1) * 2.0)) / POW(TIMESTAMPDIFF(HOUR, m.created_at, NOW()) + 2, 1.5)) + ((((m.id * $feed_seed) % 104729) / 104729.0) * 0.5)";
            $order_logic = "($trend_base) DESC";
            break;
    }
}

/* ── QUERY BUILDER (counts now come from meme_stats — no more GROUP BY over 4 big tables) ── */
$sql = "SELECT m.*, u.username, u.nickname, u.profile_pic, u.is_verified,
    COALESCE(ms.likes, 0)    AS like_count,
    COALESCE(ms.reposts, 0)  AS repost_count,
    COALESCE(ms.saves, 0)    AS fav_count,
    COALESCE(ms.shares, 0)   AS share_count,
    COALESCE(ms.impressions, 0)                   AS impression_count,
    TIMESTAMPDIFF(SECOND, m.created_at, NOW())    AS age_sec,
    TIMESTAMPDIFF(SECOND, fi.first_seen, NOW())   AS seen_first_sec,
    TIMESTAMPDIFF(SECOND, fi.last_seen, NOW())    AS seen_last_sec,
    COALESCE(fi.seen_count, 0)                    AS seen_count,
    COALESCE(fi.skip_count, 0)                    AS my_skips,
    COALESCE(ms.skips, 0)                         AS skip_total,
    COALESCE(ms.lingers, 0)                       AS linger_total,
    CASE WHEN user_like.id     IS NOT NULL THEN 1 ELSE 0 END AS user_liked,
    CASE WHEN user_repost.id   IS NOT NULL THEN 1 ELSE 0 END AS user_reposted,
    CASE WHEN user_fav.id      IS NOT NULL THEN 1 ELSE 0 END AS user_fav,
    CASE WHEN user_share.id    IS NOT NULL THEN 1 ELSE 0 END AS user_shared,
    CASE WHEN follow_check.id  IS NOT NULL THEN 1 ELSE 0 END AS is_following,
    ma.cta_text  AS ad_cta_text,
    ma.cta_url   AS ad_cta_url,
    ma.cta_color AS ad_cta_color

    FROM memes m
    JOIN users u ON m.uploaded_by = u.id

    LEFT JOIN meme_stats ms ON ms.meme_id = m.id

    LEFT JOIN likes     user_like   ON user_like.meme_id   = m.id AND user_like.user_id   = ?
    LEFT JOIN reposts   user_repost ON user_repost.meme_id = m.id AND user_repost.user_id = ?
    LEFT JOIN favorites user_fav    ON user_fav.meme_id    = m.id AND user_fav.user_id    = ?
    LEFT JOIN shares    user_share  ON user_share.meme_id  = m.id AND user_share.user_id  = ?
    LEFT JOIN followers follow_check ON follow_check.follower_id = ? AND follow_check.following_id = m.uploaded_by
    LEFT JOIN dz_feed_impressions fi ON fi.user_id = ? AND fi.meme_id = m.id
    LEFT JOIN meme_ads   ma ON ma.meme_id = m.id";

$params = [$user_id, $user_id, $user_id, $user_id, $user_id, $user_id];
$types  = "iiiiii";

$where = " WHERE 1=1 AND m.is_archived = 0 AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')  AND NOT EXISTS (SELECT 1 FROM group_posts gpx JOIN `groups` gx ON gx.id = gpx.group_id WHERE gpx.meme_id = m.id AND gx.is_suspended = 1) ";

/* Ads are never removed by the tag / search / Tracking filters — approved ads always stay in the feed. */
if($tag !== 'all' && $tag_key !== ''){
    $where   .= " AND (EXISTS (SELECT 1 FROM dz_meme_tags mtf WHERE mtf.meme_id = m.id AND mtf.tag = ?) OR m.is_ad = 1) ";
    $params[] = $tag_key;
    $types   .= "s";
}
if($search_query !== ''){
    $where   .= " AND (m.caption LIKE ? OR m.is_ad = 1) ";
    $params[] = "%$search_query%";
    $types   .= "s";
}
if($DZ_TEXT_ONLY){   /* DEZZTEXT: only text posts and ads */
    $where .= " AND (m.is_ad = 1 OR (COALESCE(LOWER(m.type),'text') NOT IN ('image','video','gif','sticker','stickers') AND NOT EXISTS (SELECT 1 FROM meme_media mmx WHERE mmx.meme_id = m.id))) ";
}
if(!empty($blocked_users)){
    $in = implode(',', array_fill(0, count($blocked_users), '?'));
    $where .= " AND m.uploaded_by NOT IN ($in) ";
    $params = array_merge($params, $blocked_users);
    $types .= str_repeat('i', count($blocked_users));
}
if(!empty($muted_users)){
    $in = implode(',', array_fill(0, count($muted_users), '?'));
    $where .= " AND m.uploaded_by NOT IN ($in) ";
    $params = array_merge($params, $muted_users);
    $types .= str_repeat('i', count($muted_users));
}
if(!empty($muted_group_meme_ids)){
    $in = implode(',', array_fill(0, count($muted_group_meme_ids), '?'));
    $where .= " AND m.id NOT IN ($in) ";
    $params = array_merge($params, $muted_group_meme_ids);
    $types .= str_repeat('i', count($muted_group_meme_ids));
}
/* 18+ only for members of the pod it was posted in */
$where .= " AND (m.is_adult = 0 OR EXISTS (SELECT 1 FROM group_posts gp JOIN group_members gm ON gm.group_id = gp.group_id WHERE gp.meme_id = m.id AND gm.user_id = ?)) ";
/* Full Private accounts: their posts stay out of search, suggestions and trending (only the owner and people they approved still see them) */
$where .= " AND (m.is_ad = 1 OR m.uploaded_by > 0" . dz_fp_hide_sql('m.uploaded_by', (int)$user_id) . ") ";
$params[] = $user_id; $types .= "i";
/* ad rules: approved only, never from a hidden advertiser */
$where .= " AND (m.is_ad = 0 OR ma.status = 'approved') ";
$where .= " AND (m.is_ad = 0 OR m.uploaded_by NOT IN (SELECT hidden_user_id FROM hidden_ad_advertisers WHERE user_id = ?)) ";
$params[] = $user_id; $types .= "i";
/* "Not interested" posts never come back for this person */
if($user_id){
    $where .= " AND NOT EXISTS (SELECT 1 FROM user_interactions uni WHERE uni.user_id = ? AND uni.meme_id = m.id AND uni.interaction_type = 'not_interested') ";
    $params[] = $user_id; $types .= "i";
}

if($filter === 'following' && $user_id){
    $where .= " AND (m.is_ad = 1 OR m.uploaded_by IN (SELECT following_id FROM followers WHERE follower_id=?)) ";
    $params[] = $user_id; $types .= "i";
}

/* ── FINAL ORDER: gravity score × stacked tag boost (+ negatives) × following boost ── */
$final_order = " ORDER BY $order_logic, m.id DESC";
if($filter === 'trending'){
    $mults = [];
    $tag_boost = dz_tag_boost_sql($conn, $user_top_tags, $user_tag_weights, $user_neg_tags);
    if($tag_boost !== '') $mults[] = $tag_boost;
    if(!$DZ_IS_SEARCH && $user_id){
        /* v7: the boost follows the "People you track" dial (Less / Balanced / More) instead of a fixed 1.25 */
        $_fol_mult = 1.25;
        if(function_exists('dz_algo_settings') && defined('DZ_FOLLOW_MULT')){
            $_fst = dz_algo_settings($conn, $user_id);
            $_fa  = (array)DZ_FOLLOW_MULT;
            $_fol_mult = (float)($_fa[(int)($_fst['follow_level'] ?? 1)] ?? 1.25);
        }
        $mults[] = "(CASE WHEN follow_check.id IS NOT NULL THEN " . sprintf('%.3F', $_fol_mult) . " ELSE 1.0 END)";
    }
    if($mults){
        $final_order = " ORDER BY ($trend_base) * " . implode(' * ', $mults) . " DESC, m.id DESC";
    }
}

/* ── RUN: organic posts are paged with LIMIT; ads are fetched separately so a LIMIT never starves them ── */
$fetch_rows = function($extra_where, $order_sql, $limit_sql) use ($conn, $sql, $where, $params, $types){
    $st = $conn->prepare($sql . $where . $extra_where . $order_sql . $limit_sql);
    if(!empty($params)) $st->bind_param($types, ...$params);
    $st->execute();
    $rs = $st->get_result();
    $rows = [];
    while($r = $rs->fetch_assoc()) $rows[] = $r;
    $st->close();
    return $rows;
};

/* v2 ranker (home / dezztext, Trending, logged in): fetch a pool of candidates with the cheap SQL score, then dz_rank_feed() re-orders them in PHP.
   Everything else (other sorts, search, logged-out visitors) keeps the plain SQL ranking + LIMIT/OFFSET paging. */
$use_v2 = (DZ_ALGO_V2 && $filter === 'trending' && $user_id > 0 && (!$DZ_IS_SEARCH || $search_query === ''));   /* search with words keeps ordering by what matches */
$dz_algo_debug = [];
$dz_variant    = dz_algo_variant($user_id);   /* 'B' = current algorithm; 'A' only when DZ_AB_TEST is on (see dz_rank.php) */

/* v5: the ranked list of this feed session is remembered, so page 2, 3… continue page 1 exactly (same seed + asof = same session) */
$v2_cached = false; $feed_cache_key = '';
if($use_v2){
    $feed_cache_key = md5($DZ_PAGE . '|' . $tag_key . '|' . $search_query . '|' . ($DZ_TEXT_ONLY ? 1 : 0) . '|' . $feed_seed . '|' . $feed_asof . '|' . $user_id);
    $_fc = $_SESSION['dz_feed_cache'][$feed_cache_key] ?? null;
    if(is_array($_fc) && !empty($_fc['ids']) && (time() - (int)($_fc['t'] ?? 0)) < (int)DZ_FEED_CACHE_SEC){
        $_slice = array_slice(array_map('intval', $_fc['ids']), $offset, $page_size);
        $has_more = count($_fc['ids']) > ($offset + $page_size);
        $organic_rows = [];
        if($_slice){
            $_tm = microtime(true);
            $_byId = [];
            foreach($fetch_rows(" AND m.is_ad = 0 AND m.id IN (" . implode(',', $_slice) . ") ", "", "") as $_r){ $_byId[(int)$_r['id']] = $_r; }
            foreach($_slice as $_id){ if(isset($_byId[$_id])) $organic_rows[] = $_byId[$_id]; }   /* keep the remembered order; posts removed since (blocked, archived…) simply drop out */
            dz_time('heavy_query(cached page)', $_tm);
        }
        $v2_cached = true;
    }
}
if($v2_cached){
    /* rows already loaded from the remembered ranking above */
} elseif($use_v2){
    /* CANDIDATE GENERATION, two phases:
         1) LIGHT queries (only memes + meme_stats) pick ~1000 candidate post ids from several sources
         2) ONE heavy query applies every per-person rule (blocks, mutes, privacy, 18+, ads …) to just those ids
       Personal taste is applied afterwards, in PHP, by dz_rank_feed(). */
    $_gravL = "(((COALESCE(ms.likes,0) * 4.0) + (COALESCE(ms.reposts,0) * 6.0) + (COALESCE(ms.saves,0) * 3.0) + (COALESCE(ms.shares,0) * 5.0) + (LOG10(m.views + 1) * 2.0)) / POW(TIMESTAMPDIFF(HOUR, m.created_at, NOW()) + 2, 1.5))";
    /* the candidate pool follows the page's own rules, so a thin pool is not thrown away afterwards:
         • DEZZTEXT: only text posts are candidates
         • a hashtag page (?tag=gym): only posts with that exact hashtag are candidates */
    $text_only_sql = $DZ_TEXT_ONLY
        ? " AND COALESCE(LOWER(m.type),'text') NOT IN ('image','video','gif','sticker','stickers') AND NOT EXISTS (SELECT 1 FROM meme_media mmx WHERE mmx.meme_id = m.id) "
        : "";
    $tag_only_sql = ($tag !== 'all' && $tag_key !== '')
        ? " AND EXISTS (SELECT 1 FROM dz_meme_tags mtf2 WHERE mtf2.meme_id = m.id AND mtf2.tag = '" . $conn->real_escape_string($tag_key) . "') "
        : "";
    /* v9: $label names the source in the timing report */
    $light_ids = function($extra_where, $order_sql, $limit, $label = 'cand') use ($conn, $text_only_sql, $tag_only_sql){
        $_tl0 = microtime(true);
        $ids = [];
        $rs = $conn->query("SELECT m.id FROM memes m JOIN users u ON u.id = m.uploaded_by LEFT JOIN meme_stats ms ON ms.meme_id = m.id
                            WHERE m.is_archived = 0 AND m.is_ad = 0 AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND NOT EXISTS (SELECT 1 FROM group_posts gpx JOIN `groups` gx ON gx.id = gpx.group_id WHERE gpx.meme_id = m.id AND gx.is_suspended = 1) $text_only_sql $tag_only_sql $extra_where $order_sql LIMIT " . (int)$limit);
        if($rs) while($r = $rs->fetch_row()) $ids[] = (int)$r[0];
        dz_time('cand:' . $label . '(' . count($ids) . ')', $_tl0);
        return $ids;
    };
    $_byGrav = " ORDER BY $_gravL DESC, m.id DESC ";
    $cand = [];
    /* A: the best recent posts overall */
    $_a = $light_ids(" AND m.created_at >= (NOW() - INTERVAL " . (int)DZ_CAND_DAYS . " DAY) ", $_byGrav, DZ_CAND_POOL, 'A_trending');
    if(count($_a) < (int)DZ_CAND_MIN) $_a = $light_ids("", $_byGrav, DZ_CAND_POOL, 'A_trending_wide');   /* quiet site: look further back */
    $cand = array_merge($cand, $_a);
    /* B: people they follow */
    $cand = array_merge($cand, $light_ids(" AND m.uploaded_by IN (SELECT following_id FROM followers WHERE follower_id = " . (int)$user_id . ") AND m.created_at >= (NOW() - INTERVAL 7 DAY) ", " ORDER BY m.created_at DESC ", DZ_FOLLOW_POOL, 'B_following'));
    /* R (v10): REPOSTS — Armstrong posts, David reposts, Michael follows David but not Armstrong: Michael can now get Armstrong's post.
       The pool only needs the post ids; every per-person rule (blocks, mutes, privacy, 18+ …) is still applied by the heavy query below,
       so a repost can never show a post the viewer isn't allowed to see. */
    $dz_repost_map = [];
    if((int)DZ_REPOST_POOL > 0 && $user_id){
        try{
            $_tm = microtime(true);
            $_rr = $conn->query("SELECT r.meme_id, r.user_id, UNIX_TIMESTAMP(r.created_at) AS ts FROM reposts r
                                 WHERE r.user_id IN (SELECT following_id FROM followers WHERE follower_id = " . (int)$user_id . ")
                                   AND r.user_id <> " . (int)$user_id . " AND r.created_at >= (NOW() - INTERVAL " . (int)DZ_REPOST_DAYS . " DAY)
                                 ORDER BY r.created_at DESC LIMIT 400");
            if($_rr) while($_x = $_rr->fetch_assoc()){
                $_mid = (int)$_x['meme_id'];
                if(!isset($dz_repost_map[$_mid])) $dz_repost_map[$_mid] = ['ts'=>(int)$_x['ts'], 'n'=>0, 'by'=>[]];   /* newest repost first, so ts = the latest one */
                $dz_repost_map[$_mid]['n']++;
                $dz_repost_map[$_mid]['by'][] = (int)$_x['user_id'];
            }
            dz_time('cand:R_reposts_query(' . count($dz_repost_map) . ')', $_tm);
            if($dz_repost_map){
                $cand = array_merge($cand, $light_ids(" AND m.uploaded_by <> " . (int)$user_id . " AND m.id IN (" . implode(',', array_slice(array_keys($dz_repost_map), 0, (int)DZ_REPOST_POOL)) . ") ", " ORDER BY m.id DESC ", DZ_REPOST_POOL, 'R_reposts'));
            }
        }catch(Throwable $e){ $dz_repost_map = []; }
    }
    $GLOBALS['dz_repost_map'] = $dz_repost_map;
    /* B2 (v7): creators they picked as "more" on algorithm_settings.php, even when they do not track them */
    if((int)DZ_PICKED_POOL > 0 && function_exists('dz_picked_creator_ids')){
        $_pc = dz_picked_creator_ids($conn, $user_id);
        if($_pc) $cand = array_merge($cand, $light_ids(" AND m.uploaded_by IN (" . implode(',', array_map('intval', $_pc)) . ") AND m.created_at >= (NOW() - INTERVAL 14 DAY) ", " ORDER BY m.created_at DESC ", DZ_PICKED_POOL, 'B2_picked'));
    }
    /* B3 (v8): "Show more local content" — recent posts from people whose About me location shares the viewer's city / first place.
       The ranker only lifts posts that are already candidates, so without this source a local post outside the top of the site
       could never show up. Fails soft: no switch, no location, or no location table = this source is skipped. */
    if((int)DZ_LOCAL_POOL > 0 && function_exists('dz_local_is_on') && function_exists('dz_about_location') && dz_local_is_on($conn, $user_id)){
        try{
            $_myloc = dz_about_location($conn, $user_id);               /* also finds + remembers which table holds current_location */
            $_ltbl  = (string)($_SESSION['dz_tbl_loc'] ?? '');
            if($_myloc !== '' && $_ltbl !== '' && preg_match('/^[A-Za-z0-9_]+$/', $_ltbl)){
                $_first = trim(explode(',', $_myloc)[0]);
                $_first = trim(str_replace(['%', '_', '\\'], '', $_first));   /* no LIKE wildcards from the person's own text */
                if(mb_strlen($_first, 'UTF-8') >= 3){
                    $_lk = $conn->real_escape_string($_first);
                    $cand = array_merge($cand, $light_ids(
                        " AND m.uploaded_by <> " . (int)$user_id . " AND m.uploaded_by IN (SELECT lc.user_id FROM `$_ltbl` lc WHERE lc.current_location LIKE '%$_lk%') AND m.created_at >= (NOW() - INTERVAL " . (int)DZ_LOCAL_DAYS . " DAY) ",
                        $_byGrav, DZ_LOCAL_POOL, 'B3_local'
                    ));
                }
            }
        }catch(Throwable $e){}
    }
    /* C: their favourite tags (exact hashtags) */
    if(!empty($user_top_tags)){
        $_tl = [];
        foreach(array_slice($user_top_tags, 0, 6) as $_t){ if(preg_match('/^[a-z0-9_]{1,50}$/', (string)$_t)) $_tl[] = "'" . $conn->real_escape_string($_t) . "'"; }
        if($_tl) $cand = array_merge($cand, $light_ids(" AND m.id IN (SELECT ct.meme_id FROM dz_meme_tags ct WHERE ct.tag IN (" . implode(',', $_tl) . ")) AND m.created_at >= (NOW() - INTERVAL 14 DAY) ", $_byGrav, DZ_TAG_POOL, 'C_fav_tags'));
    }
    /* D: the newest posts, so brand-new posts with no engagement yet can still compete */
    $cand = array_merge($cand, $light_ids(" AND m.created_at >= (NOW() - INTERVAL 3 DAY) ", " ORDER BY m.created_at DESC ", DZ_FRESH_POOL, 'D_fresh'));
    /* E (v4): posts that MATCH their favourite topics by caption words or by what the photo / video shows (dz_meme_terms),
       even when the post carries none of those hashtags. Skipped quietly if the table does not exist yet. */
    if(!empty($user_top_tags)){
        try{
            $_wl = [];
            $_wseen = [];
            foreach(array_slice($user_top_tags, 0, (int)DZ_TERM_TOPICS) as $_t){
                $_t = (string)$_t;
                if($_t === '' || $_t[0] === '_' || strlen($_t) > 60) continue;
                $_variants = ($dz_variant === 'B') ? array_merge([$_t], dz_term_variants(dz_norm_term($_t))) : [$_t];   /* glutes → glute, glutes, synonyms … */
                foreach($_variants as $_v){
                    $_v = (string)$_v;
                    if($_v === '' || $_v[0] === '_' || strlen($_v) > 60 || isset($_wseen[$_v]) || count($_wl) >= 60) continue;
                    $_wseen[$_v] = 1;
                    $_wl[] = "'" . $conn->real_escape_string($_v) . "'";
                }
            }
            if($dz_variant === 'B'){   /* v6: topics that appear together with their favourites (learned from the site's own posts) */
                $_norms = [];
                foreach(array_slice($user_top_tags, 0, (int)DZ_TERM_TOPICS) as $_t){ $_n = dz_norm_term((string)$_t); if($_n !== '') $_norms[$_n] = $_n; }
                $_norms = array_values($_norms);
                sort($_norms);
                /* v9: related topics change slowly — cached for DZ_CACHE_RELATED_SEC and shared by everyone with the same favourites */
                $_tm = microtime(true);
                $_rel = dz_cache_remember('related|' . implode(',', $_norms), DZ_CACHE_RELATED_SEC, function() use ($conn, $_norms){
                    return dz_related_terms($conn, $_norms);
                });
                dz_time('related_terms', $_tm);
                foreach((array)$_rel as $_rr){
                    $_k = 0;
                    foreach($_rr as $_rw => $_q){
                        if(++$_k > 3) break;
                        foreach(dz_term_variants((string)$_rw) as $_v){
                            if($_v === '' || $_v[0] === '_' || strlen($_v) > 60 || isset($_wseen[$_v]) || count($_wl) >= 60) continue;
                            $_wseen[$_v] = 1;
                            $_wl[] = "'" . $conn->real_escape_string($_v) . "'";
                        }
                    }
                }
            }
            if($_wl){
                $cand = array_merge($cand, $light_ids(
                    " AND m.id IN (SELECT wt.meme_id FROM dz_meme_terms wt WHERE wt.term IN (" . implode(',', $_wl) . ") AND wt.w >= " . (int)DZ_TERM_MIN_W . ") AND m.created_at >= (NOW() - INTERVAL " . (int)DZ_TERM_DAYS . " DAY) ",
                    $_byGrav, DZ_TERM_POOL, 'E_topic_words'
                ));
            }
        }catch(Throwable $e){}
    }
    if($dz_variant === 'B'){
        /* F (v5/v6): posts gaining the most engagement RIGHT NOW, whatever their age — counted from established accounts only;
           bursts that look fake (almost all from brand-new accounts) are left out. Needs the created_at index (dezzme_migration_v6.sql).
           v9: the site-wide list is cached for DZ_CACHE_HOT_SEC (it is the same for everybody). */
        if((int)DZ_HOT_POOL > 0){
            try{
                $_tm = microtime(true);
                $_hot = dz_cache_remember('hot|' . (int)DZ_VELOCITY_HOURS . '|' . ((int)DZ_HOT_POOL * 2), DZ_CACHE_HOT_SEC, function() use ($conn){
                    return dz_hot_posts($conn, (int)DZ_VELOCITY_HOURS, (int)DZ_HOT_POOL * 2);
                });
                dz_time('hot_posts', $_tm);
                if($_hot) $cand = array_merge($cand, $light_ids(" AND m.id IN (" . implode(',', array_map('intval', (array)$_hot)) . ") ", $_byGrav, DZ_HOT_POOL, 'F_hot'));
            }catch(Throwable $e){}
        }
        /* G (v6): COLLABORATIVE FILTERING — what people with the same taste (same likes / saves / reposts) liked recently.
           The scores are handed to the ranker, which also lifts these posts.
           v9: cached per person for DZ_CACHE_CF_SEC (it is the most expensive taste query). */
        $dz_cf_map = [];
        try{
            $_tm = microtime(true);
            $_cf = dz_cache_remember('cf|' . (int)$user_id, DZ_CACHE_CF_SEC, function() use ($conn, $user_id){
                return dz_cf_candidates($conn, $user_id);
            });
            dz_time('collab_filter', $_tm);
            $dz_cf_map = $_cf['scores'];
            if($_cf['ids']) $cand = array_merge($cand, $light_ids(" AND m.id IN (" . implode(',', array_map('intval', $_cf['ids'])) . ") ", $_byGrav, DZ_CF_POOL, 'G_collab'));
        }catch(Throwable $e){}
        $GLOBALS['dz_cf_map'] = $dz_cf_map;
        /* H (v6): COLD START — a new person gets the best recent posts of many DIFFERENT popular topics, so what they react to teaches us their taste fast */
        $dz_cold = dz_is_cold($conn, $user_id, count($user_top_tags));
        $GLOBALS['dz_cold'] = $dz_cold;
        if($dz_cold){
            try{
                /* v9: the popular-tag list is the same for everybody, so it is cached for all visitors (not one hour per session) */
                $_tm = microtime(true);
                $_poptags = dz_cache_remember('poptags', DZ_CACHE_POPTAGS_SEC, function() use ($conn){
                    $_tags = [];
                    $_pr = $conn->query("SELECT tag FROM dz_meme_tags WHERE meme_id >= (SELECT COALESCE(MAX(id), 0) - 20000 FROM memes) GROUP BY tag ORDER BY COUNT(*) DESC LIMIT 24");
                    if($_pr) while($_r = $_pr->fetch_row()) $_tags[] = strtolower((string)$_r[0]);
                    return $_tags;
                });
                dz_time('popular_tags', $_tm);
                foreach(array_slice((array)$_poptags, 0, (int)DZ_COLD_TAGS) as $_tg){
                    if(!preg_match('/^[a-z0-9_]{1,50}$/', $_tg)) continue;
                    $cand = array_merge($cand, $light_ids(
                        " AND m.id IN (SELECT ct.meme_id FROM dz_meme_tags ct WHERE ct.tag = '" . $conn->real_escape_string($_tg) . "') AND m.created_at >= (NOW() - INTERVAL 14 DAY) ",
                        $_byGrav, DZ_COLD_PER_TAG, 'H_cold:' . $_tg
                    ));
                }
            }catch(Throwable $e){}
        }
        /* I (v6): a random slice of fresh posts almost nobody has seen yet (a different slice for every feed) — so a great new post, or a new creator,
           can still get into the pool even when it has no engagement and no matching tag yet. */
        if((int)DZ_UNDEREXP_POOL > 0){
            $cand = array_merge($cand, $light_ids(
                " AND m.created_at >= (NOW() - INTERVAL 2 DAY) AND COALESCE(ms.impressions, 0) < " . (int)DZ_UNDEREXP_MAX_IMP . " ",
                " ORDER BY ((m.id * " . (int)$feed_seed . ") % 104729) ", DZ_UNDEREXP_POOL, 'I_underexposed'
            ));
        }
    }

    $cand = array_values(array_unique($cand));
    $organic_rows = [];
    if($cand){
        $_tm = microtime(true);
        $_seen = [];
        foreach($fetch_rows(" AND m.is_ad = 0 AND m.id IN (" . implode(',', $cand) . ") ", "", "") as $_r){
            $_id = (int)$_r['id'];
            if(empty($_seen[$_id])){ $_seen[$_id] = 1; $organic_rows[] = $_r; }   /* one row per post (guards against duplicate rows from joins) */
        }
        dz_time('heavy_query(' . count($organic_rows) . ' rows)', $_tm);
    }
    $has_more = false;   /* decided after ranking */
} else {
    $_tm = microtime(true);
    $organic_rows = $fetch_rows(" AND m.is_ad = 0 ", $final_order, " LIMIT " . ($page_size + 1) . " OFFSET " . (int)$offset);
    dz_time('sql_ranked_page', $_tm);
    $has_more = count($organic_rows) > $page_size;
    if($has_more) $organic_rows = array_slice($organic_rows, 0, $page_size);
}
$_tm = microtime(true);
/* Audience targeting: pull a bigger random pool, keep only the ads this person is allowed to see (no target = everyone), then cut to the usual number */
$_ad_want = ($page > 1 ? 4 : 10);
$ad_rows  = $fetch_rows(" AND m.is_ad = 1 ", " ORDER BY RAND() ", " LIMIT " . max(60, $_ad_want * 6));   /* a wider pool, so ads aimed at this person are in it */
$ad_rows = array_slice($ad_rows, 0, $_ad_want);
dz_time('ads', $_tm);

/* ── PRE-FETCH ALL ROWS & SOCIAL PROOF ── */
/* ── WORD FILTERS: posts / ads that contain a word this person filtered never reach their feed ── */
if($user_id){
    $_wf = dz_wf_get($conn, (int)$user_id);
    if($_wf['post']) $organic_rows = dz_wf_filter_rows($organic_rows, $_wf['post'], ['caption']);
    if($_wf['ad'])   $ad_rows      = dz_wf_filter_rows($ad_rows, $_wf['ad'], ['caption', 'ad_cta_text']);
}

$all_rows = array_merge($organic_rows, $ad_rows);
$meme_ids = array_map(function($r){ return $r['id']; }, $all_rows);

$social_proof_map = [];
$repost_by_map    = [];   /* v10: "David reposted" — who reposted this post among the people you follow (newest first) */
if($user_id && !empty($meme_ids)){
    $_tm = microtime(true);
    $id_list = implode(',', array_map('intval', $meme_ids));
    $stmt = $conn->prepare("
        SELECT l.meme_id, u.id, u.username, u.profile_pic, 'like' AS action_type, NULL AS acted_at, COALESCE(u.is_verified,0) AS is_verified
        FROM likes l JOIN users u ON u.id = l.user_id JOIN followers f ON f.following_id = u.id
        WHERE l.meme_id IN ($id_list) AND f.follower_id = ? AND u.id != ? AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')
        UNION ALL
        SELECT r.meme_id, u.id, u.username, u.profile_pic, 'repost' AS action_type, r.created_at AS acted_at, COALESCE(u.is_verified,0) AS is_verified
        FROM reposts r JOIN users u ON u.id = r.user_id JOIN followers f ON f.following_id = u.id
        WHERE r.meme_id IN ($id_list) AND f.follower_id = ? AND u.id != ? AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned')
        UNION ALL
        SELECT r.meme_id, u.id, u.username, u.profile_pic, 'repost' AS action_type, r.created_at AS acted_at, COALESCE(u.is_verified,0) AS is_verified
        FROM reposts r JOIN users u ON u.id = r.user_id
        WHERE r.meme_id IN ($id_list) AND r.user_id = ?   /* my own repost takes a slot in the strip (replaces a like, or the oldest repost) */
    ");
    $stmt->bind_param("iiiii", $user_id, $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $sp_result = $stmt->get_result();
    $_social_proof_raw = [];
    while($sp = $sp_result->fetch_assoc()){ $_social_proof_raw[$sp['meme_id']][] = $sp; }
    foreach($_social_proof_raw as $mid => $rows){   /* reposts first (newest first), then likes — top 4 */
        usort($rows, function($a, $b){
            $ar = $a['action_type'] === 'repost'; $br = $b['action_type'] === 'repost';
            if($ar !== $br) return $ar ? -1 : 1;
            return strcmp((string)($b['acted_at'] ?? ''), (string)($a['acted_at'] ?? ''));
        });
        $_rp = array_values(array_filter($rows, function($x) use ($user_id){ return $x['action_type'] === 'repost' && (int)$x['id'] !== (int)$user_id; }));   /* the "X reposted" label never names me */
        if($_rp) $repost_by_map[$mid] = ['first'=>$_rp[0], 'count'=>count($_rp)];
        $social_proof_map[$mid] = array_slice($rows, 0, 4);
    }
    dz_time('social_proof', $_tm);
}
$collab_map = dz_collab_payload($conn, $meme_ids ?? [], (int)$user_id);
dz_poll_ensure($conn);
$poll_map   = dz_polls_for_memes($conn, (int)$user_id, $meme_ids ?? []);   /* posts with a poll → the vote pill + the poll in the comment sheet */   /* posts with accepted collaborators → header + Collaborators sheet */
/* v2: rank the candidates, then cut this page out of the ranked list */
if($use_v2 && !$v2_cached){
    $_tm = microtime(true);
    $sess_sec     = max(0, time() - $feed_asof);
    $dz_aff       = dz_load_affinity($conn, $user_id);
    $GLOBALS['dz_tm_ranks'] = DZ_TM_MAIN ? dz_tm_ranks($user_id) : [];   /* TasteMatch picks, merged inside dz_rank_feed (Python slightly higher) */
    $ranked       = dz_rank_feed($conn, $organic_rows, $dz_aff, $social_proof_map, $feed_seed, $sess_sec);
    if($user_id && function_exists('dz_repost_boost_rows')){ try{ $ranked = dz_repost_boost_rows($conn, $user_id, $ranked, (array)($GLOBALS['dz_repost_map'] ?? [])); }catch(Throwable $e){} }   /* v10: fresh reposts by people you follow */
    dz_time('rank(' . count($organic_rows) . ' posts)', $_tm);
    /* TasteMatch SHADOW (log only): once per feed session, right after PHP's own ranking is final. Nothing below reads its result. */
    $_tm = microtime(true);
    if(!DZ_TM_MAIN) dz_tm_shadow($user_id, $ranked, $page, $dz_variant);   /* blend off: log-only comparison as before */
    dz_time('tm_shadow', $_tm);
    $has_more     = count($ranked) > ($offset + $page_size);
    /* remember the whole ranked list for this feed session (keep the 3 newest sessions) */
    if(session_status() === PHP_SESSION_ACTIVE){
        $_SESSION['dz_feed_cache'][$feed_cache_key] = ['t' => time(), 'ids' => array_map(function($r){ return (int)$r['id']; }, $ranked)];
        if(count($_SESSION['dz_feed_cache']) > 3){
            uasort($_SESSION['dz_feed_cache'], function($a, $b){ return ((int)($a['t'] ?? 0)) <=> ((int)($b['t'] ?? 0)); });
            while(count($_SESSION['dz_feed_cache']) > 3) array_shift($_SESSION['dz_feed_cache']);
        }
    }
    $organic_rows = array_slice($ranked, $offset, $page_size);
    $all_rows     = array_merge($organic_rows, $ad_rows);
}
$memes = $all_rows;

/* ================================================================
   STATIC SEARCH LISTS (?q=…) — search page only
================================================================ */
$groups = []; $users = []; $collections = []; $sounds = []; $sound_folders = []; $memepedia_entries = [];
$user_previews_static = []; $group_previews_static = [];
if($DZ_IS_SEARCH && $search_query !== '' && empty($_GET['feed_json'])){   /* only the HTML page needs these lists, not the page-2-3… JSON calls */
    $like = "%$search_query%";

    $stmt = $conn->prepare("SELECT id, username, nickname, profile_pic, COALESCE(is_verified,0) AS is_verified FROM users WHERE (username LIKE ? OR (nickname IS NOT NULL AND nickname != '' AND nickname LIKE ?)) AND id != ? AND deactivated_at IS NULL");
    $stmt->bind_param("ssi", $like, $like, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $static_user_ids = [];
    while($row = $res->fetch_assoc()){
        $uid = (int)$row['id'];
        $row['is_blocked'] = false; $row['is_following'] = false; $row['is_private'] = false;
        $row['hide_track'] = false; $row['is_track_approved'] = false; $row['track_req_status'] = null;
        if($user_id){
            $b = $conn->query("SELECT 1 FROM blocks WHERE blocker_id=$user_id AND blocked_id=$uid");
            $row['is_blocked'] = $b && $b->num_rows > 0;
            $f = $conn->query("SELECT 1 FROM followers WHERE follower_id=$user_id AND following_id=$uid");
            $row['is_following'] = $f && $f->num_rows > 0;
            $p = $conn->query("SELECT is_private, is_fully_private FROM user_settings WHERE user_id=$uid LIMIT 1");
            if($p && $pr = $p->fetch_assoc()){
                $row['is_private'] = !empty($pr['is_fully_private']);
                $row['hide_track'] = (!empty($pr['is_private']) || !empty($pr['is_fully_private']));
            }
            $t = $conn->query("SELECT status FROM track_requests WHERE requester_id=$user_id AND target_id=$uid LIMIT 1");
            if($t && $tr = $t->fetch_assoc()){ $row['track_req_status'] = $tr['status']; $row['is_track_approved'] = ($tr['status'] === 'approved'); }
        }
        $row['display_name'] = !empty($row['nickname']) ? $row['nickname'] : $row['username'];
        $static_user_ids[] = $uid;
        $users[] = $row;
    }
    $user_previews_static = dz_batch_user_previews($conn, $static_user_ids, $user_id);
    $static_user_bios     = dz_batch_user_bios($conn, $static_user_ids, $user_id);
    foreach($users as &$_u){ $_u['bio'] = $static_user_bios[(int)$_u['id']] ?? ''; }
    unset($_u);

    $stmt4 = $conn->prepare("
        SELECT c.id, c.name, c.user_id, u.username AS owner_username,
            (SELECT COUNT(*) FROM collection_items ci JOIN memes cm ON cm.id = ci.meme_id JOIN users cu ON cu.id = cm.uploaded_by WHERE ci.collection_id = c.id AND cm.is_archived = 0 AND cu.deactivated_at IS NULL AND LOWER(COALESCE(cu.user_rank,'')) NOT IN ('suspended','banned')) AS item_count
        FROM collections c JOIN users u ON u.id = c.user_id
        WHERE c.is_public = 1 AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND c.name LIKE ?
        ORDER BY c.created_at DESC LIMIT 20");
    $stmt4->bind_param("s", $like); $stmt4->execute();
    $res4 = $stmt4->get_result();
    while($row = $res4->fetch_assoc()) $collections[] = ['id'=>(int)$row['id'],'name'=>$row['name'],'owner_id'=>(int)$row['user_id'],'owner_username'=>$row['owner_username'],'item_count'=>(int)$row['item_count']];
    $stmt4->close();

    $stmt5 = $conn->prepare("SELECT s.id, s.title, s.user_id, u.username FROM sounds s JOIN users u ON u.id = s.user_id WHERE s.title LIKE ? AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') ORDER BY s.created_at DESC LIMIT 20");
    $stmt5->bind_param("s", $like); $stmt5->execute();
    $res5 = $stmt5->get_result();
    while($row = $res5->fetch_assoc()) $sounds[] = ['id'=>(int)$row['id'],'title'=>$row['title'],'user_id'=>(int)$row['user_id'],'username'=>$row['username']];
    $stmt5->close();

    $stmt6 = $conn->prepare("
        SELECT sf.id, sf.name, sf.user_id, u.username AS owner_username,
            (SELECT COUNT(*) FROM sound_folder_items sfi WHERE sfi.folder_id = sf.id) AS item_count
        FROM sound_folders sf JOIN users u ON u.id = sf.user_id
        WHERE sf.is_public = 1 AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') AND sf.name LIKE ?
        ORDER BY sf.created_at DESC LIMIT 20");
    $stmt6->bind_param("s", $like); $stmt6->execute();
    $res6 = $stmt6->get_result();
    while($row = $res6->fetch_assoc()) $sound_folders[] = ['id'=>(int)$row['id'],'name'=>$row['name'],'owner_id'=>(int)$row['user_id'],'owner_username'=>$row['owner_username'],'item_count'=>(int)$row['item_count']];
    $stmt6->close();

    $stmt7 = $conn->prepare("
        SELECT ma.id, ma.title, ma.status, ma.category, ma.media_url, ma.media_type, ma.summary, ma.created_at, ma.created_by,
               au.username AS author_username, au.profile_pic AS author_pic
        FROM meme_articles ma LEFT JOIN users au ON au.id = ma.created_by AND au.deactivated_at IS NULL AND LOWER(COALESCE(au.user_rank,'')) NOT IN ('suspended','banned')
        WHERE ma.title LIKE ? ORDER BY ma.views DESC LIMIT 20");
    $stmt7->bind_param("s", $like); $stmt7->execute();
    $res7 = $stmt7->get_result();
    while($row = $res7->fetch_assoc()){
        $memepedia_entries[] = [
            'id'=>(int)$row['id'],'title'=>$row['title'],'status'=>$row['status'],'category'=>$row['category'],
            'media_url'=>$row['media_url'] ?? '','media_type'=>$row['media_type'] ?? '','summary'=>$row['summary'] ?? '',
            'created_at'=>$row['created_at'] ?? '','author_id'=>(int)($row['created_by'] ?? 0),
            'author_username'=>$row['author_username'] ?? '','author_pic'=>$row['author_pic'] ?? '',
        ];
    }
    $stmt7->close();

    $stmt3 = $conn->prepare("
        SELECT g.id, g.title, g.description, g.group_pic, g.is_adult, g.created_by, ou.username AS owner_username,
            (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count,
            EXISTS(SELECT 1 FROM muted_groups mg WHERE mg.user_id = ? AND mg.group_id = g.id) AS is_muted,
            EXISTS(SELECT 1 FROM group_notif_off gno WHERE gno.user_id = ? AND gno.group_id = g.id) AS notif_off
        FROM groups g LEFT JOIN users ou ON ou.id = g.created_by
        WHERE g.title LIKE ?");
    $stmt3->bind_param("iis", $user_id, $user_id, $like);
    $stmt3->execute();
    $groups = $stmt3->get_result();
    $static_group_ids = [];
    while($g = $groups->fetch_assoc()) $static_group_ids[] = (int)$g['id'];
    $group_previews_static = dz_batch_group_previews($conn, $static_group_ids);
    $groups->data_seek(0);
}

/* ================================================================
   FOLLOW LIST + "posted in last 24h" map (home / dezztext)
================================================================ */
$following = null;
$defaultPic = 'uploads/mee.jpeg';
$_follow_bar_new_map = [];
$follow_ids = [];
if(!$DZ_IS_SEARCH && $user_id && empty($DZ_LEAN)){   /* $DZ_LEAN (zeems.php): the "people you track" bar is not shown there, so skip these two queries */
    $stmt = $conn->prepare("SELECT u.id, u.username, u.profile_pic FROM followers f JOIN users u ON f.following_id = u.id WHERE f.follower_id = ? AND u.deactivated_at IS NULL AND LOWER(COALESCE(u.user_rank,'')) NOT IN ('suspended','banned') ORDER BY f.id DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $following = $stmt->get_result();
    if($following && $following->num_rows > 0){
        $following->data_seek(0);
        while($f = $following->fetch_assoc()) $follow_ids[] = (int)$f['id'];
        $following->data_seek(0);
        if($follow_ids){
            $ids_str = implode(',', $follow_ids);
            $rc = $conn->query("SELECT DISTINCT uploaded_by FROM memes WHERE uploaded_by IN ($ids_str) AND created_at >= NOW() - INTERVAL 24 HOUR AND is_archived = 0");
            if($rc) while($r = $rc->fetch_assoc()) $_follow_bar_new_map[(int)$r['uploaded_by']] = true;
        }
    }
}

/* ================================================================
   MAPS: online dots, private profiles, hide-track, media / group / audio
================================================================ */
$_tm = microtime(true);
$_online_map = getOnlineMap($conn, $user_id, array_merge($follow_ids, array_map('intval', array_column($memes, 'uploaded_by'))));
if($user_id){ $_hb = hpUserCol($conn); $conn->query("UPDATE users SET `$_hb` = NOW() WHERE id = " . (int)$user_id); } /* heartbeat */

$_private_profile_map = []; $_approved_map = [];
if($user_id){
    $pp = $conn->query("SELECT user_id FROM user_settings WHERE is_fully_private=1");
    if($pp) while($r = $pp->fetch_assoc()) $_private_profile_map[(int)$r['user_id']] = true;
    $ap = $conn->query("SELECT target_id FROM track_requests WHERE requester_id=".(int)$user_id." AND status='approved'");
    if($ap) while($r = $ap->fetch_assoc()) $_approved_map[(int)$r['target_id']] = true;
}
/* private OR fully-private users → the Track button is never shown for them */
$_hide_track_map = [];
$_htq = $conn->query("SELECT user_id FROM user_settings WHERE is_private=1 OR is_fully_private=1");
if($_htq) while($r = $_htq->fetch_assoc()) $_hide_track_map[(int)$r['user_id']] = true;

$_group_map = []; $_media_map = []; $_audio_map = [];
if(!empty($memes)){
    $ids_str = implode(',', array_map('intval', array_column($memes, 'id')));

    $gp_res = $conn->query("SELECT gp.meme_id, g.id AS group_id, g.title AS group_title FROM group_posts gp JOIN groups g ON g.id = gp.group_id WHERE gp.meme_id IN ($ids_str)");
    if($gp_res) while($gp = $gp_res->fetch_assoc()) $_group_map[(int)$gp['meme_id']] = $gp;

    $mm_res = $conn->query("SELECT meme_id, file_name, type, position FROM meme_media WHERE meme_id IN ($ids_str) ORDER BY meme_id, position ASC");
    if($mm_res) while($mm = $mm_res->fetch_assoc()) $_media_map[(int)$mm['meme_id']][] = ['file'=>$mm['file_name'], 'type'=>$mm['type']];

    $au_res = $conn->query("SELECT ma.meme_id, a.id AS audio_id, a.title, a.artist, a.file_name, ma.is_original, ma.music_volume, ma.video_audio_volume
                            FROM meme_audio ma JOIN audios a ON a.id = ma.audio_id WHERE ma.meme_id IN ($ids_str)");
    if($au_res) while($au = $au_res->fetch_assoc()) $_audio_map[(int)$au['meme_id']] = $au;

    /* backfill "Original audio" for videos with nothing attached */
    $_needs_backfill = [];
    foreach($memes as $mrow){
        $mid = (int)$mrow['id'];
        if(($mrow['type'] ?? '') !== 'video' || isset($_audio_map[$mid])) continue;
        $_needs_backfill[$mid] = $mrow;
    }
    if($_needs_backfill){
        $bf = implode(',', array_keys($_needs_backfill));
        $_existing_native = [];
        $er = $conn->query("SELECT id, source_meme_id FROM audios WHERE source_meme_id IN ($bf)");
        if($er) while($r = $er->fetch_assoc()) $_existing_native[(int)$r['source_meme_id']] = (int)$r['id'];
        foreach($_needs_backfill as $mid => $mrow){
            if(isset($_existing_native[$mid])){ $aid = $_existing_native[$mid]; }
            else {
                $uploader = (int)$mrow['uploaded_by'];
                $fn = $conn->real_escape_string($mrow['file_name']);
                $conn->query("INSERT INTO audios (uploader_id, title, file_name, is_original, source_meme_id, created_at) VALUES ($uploader, 'Original audio', '$fn', 1, $mid, NOW())");
                $aid = $conn->insert_id;
                if($aid) $conn->query("INSERT INTO meme_audio (meme_id, audio_id, is_original, music_volume, video_audio_volume) VALUES ($mid, $aid, 1, 100, 100)");
            }
            if($aid){
                $_audio_map[$mid] = ['meme_id'=>$mid,'audio_id'=>$aid,'title'=>'Original audio','artist'=>null,'file_name'=>$mrow['file_name'],'is_original'=>1,'music_volume'=>100,'video_audio_volume'=>100];
            }
        }
    }
}
dz_time('page_maps', $_tm);

/* ================================================================
   v11: DATA FOR THE "WHY AM I SEEING THIS" LIST (one small query each, only for the posts on this page)
     $_why_post_topics[meme_id] = [compact => label]   hashtags + caption words / media topics (weight ≥ DZ_TERM_MIN_W)
     $_why_pick_*                                       what the person chose on algorithm_settings.php
     $_why_aff                                          the learned taste (creators / formats)
   Everything fails soft: a missing table just means fewer reasons.
================================================================ */
$_tm = microtime(true);
$_why_post_topics = [];
$_why_pick_tags = []; $_why_pick_creators = []; $_why_user_topics = [];
$_why_aff = [];
if($user_id && !$DZ_IS_SEARCH && !empty($memes)){
    try{
        foreach($user_top_tags as $_t){ $_c = dz_why_compact($_t); if($_c !== '' && !isset($_why_user_topics[$_c])) $_why_user_topics[$_c] = (string)$_t; }
        $_why_aff = (isset($dz_aff) && is_array($dz_aff)) ? $dz_aff : dz_load_affinity($conn, $user_id);

        $_wid = implode(',', array_map('intval', array_column($memes, 'id')));
        $_wr = $conn->query("SELECT meme_id, tag FROM dz_meme_tags WHERE meme_id IN ($_wid)");
        if($_wr) while($_x = $_wr->fetch_assoc()){
            $_c = dz_why_compact($_x['tag']);
            if($_c !== '') $_why_post_topics[(int)$_x['meme_id']][$_c] = '#' . ltrim((string)$_x['tag'], '#');
        }
        $_wr = $conn->query("SELECT meme_id, term, shown FROM dz_meme_terms WHERE meme_id IN ($_wid) AND w >= " . (int)DZ_TERM_MIN_W);
        if($_wr) while($_x = $_wr->fetch_assoc()){
            $_c = dz_why_compact($_x['term']);
            $_mid = (int)$_x['meme_id'];
            if($_c !== '' && !isset($_why_post_topics[$_mid][$_c])) $_why_post_topics[$_mid][$_c] = (string)(($_x['shown'] ?? '') !== '' ? $_x['shown'] : $_x['term']);
        }
    }catch(Throwable $e){}
    try{
        $_wr = $conn->query("SELECT kind, item FROM dz_user_algo_prefs WHERE user_id = " . (int)$user_id . " AND pref = 'more'");
        if($_wr) while($_x = $_wr->fetch_assoc()){
            if($_x['kind'] === 'tag')          { $_c = dz_why_compact($_x['item']); if($_c !== '') $_why_pick_tags[$_c] = (string)$_x['item']; }
            elseif($_x['kind'] === 'creator')  { $_why_pick_creators[(int)$_x['item']] = true; }
        }
    }catch(Throwable $e){}
}
dz_time('why_data', $_tm);

/* ================================================================
   BUILD THE FEED DATA
     $_peura_feed_data  → the zeems modal + feed renderer
     $_kebab_data       → the ⋯ post menu (now also carries "why")
     $_list             → search list rows (search page only)
================================================================ */
$_thumb_types = ['image','video','gif','sticker','stickers'];
$_entries = [];

foreach($memes as $row){
    $uid = (int)$row['uploaded_by'];
    if($uid != $user_id && !empty($_private_profile_map[$uid]) && empty($_approved_map[$uid])) continue;

    $mid = (int)$row['id'];
    $media_items = [[ 'file'=>$row['file_name'], 'type'=>$row['type'] ]];
    if(!empty($_media_map[$mid])) $media_items = array_merge($media_items, $_media_map[$mid]);

    $group_info = $_group_map[$mid] ?? null;
    $audio_info = $_audio_map[$mid] ?? null;
    $social     = $social_proof_map[$mid] ?? [];
    $pic        = !empty($row['profile_pic']) ? $row['profile_pic'] : 'uploads/mee.jpeg';
    $is_online  = ($uid != $user_id) && !empty($_online_map[$uid]);
    $cap_raw    = (string)$row['caption'];
    $cap_feed   = $DZ_IS_SEARCH ? str_replace(["\r","\n"], ' ', $cap_raw) : $cap_raw;

    /* "Because you liked…" — discovery posts only (home / dezztext) */
    $reason = null;
    if(!$DZ_IS_SEARCH && $user_id && empty($row['is_ad']) && $uid != $user_id && (int)$row['is_following'] === 0){
        if(!empty($social)){
            $reason = ['type'=>$social[0]['action_type'], 'user'=>$social[0]['username']];
        } else {
            foreach($user_top_tags as $t){
                if(preg_match('/#'.preg_quote($t,'/').'(?![a-zA-Z0-9_])/i', $cap_raw, $mm)){
                    $reason = ['type'=>'tag', 'tag'=>ltrim($mm[0], '#')];
                    break;
                }
            }
        }
    }

    /* v11: why is this post / ad in this person's feed? (only for posts that are not their own) */
    $why = [];
    if(!$DZ_IS_SEARCH && $user_id && $uid != $user_id){
        $_topics = []; $_picked = [];
        foreach(($_why_post_topics[$mid] ?? []) as $_c => $_label){
            if(isset($_why_pick_tags[$_c])) $_picked[] = $_label;
            elseif(isset($_why_user_topics[$_c])) $_topics[] = $_label;
        }
        $_spLikes = [];
        foreach($social as $_s){ if($_s['action_type'] !== 'repost') $_spLikes[] = $_s; }
        $_rb = $repost_by_map[$mid] ?? null;
        $_ctype = strtolower((string)$row['type']);
        $why = dz_why_build($row, [
            'is_ad'          => !empty($row['is_ad']),
            'following'      => (int)($row['is_following'] ?? 0) > 0,
            'picked_creator' => !empty($_why_pick_creators[$uid]),
            'picked_topics'  => $_picked,
            'topics'         => $_topics,
            'aff_creator'    => (float)($_why_aff['creator'][$uid] ?? $_why_aff['creator'][(string)$uid] ?? 0),
            'aff_type'       => (float)($_why_aff['type'][$_ctype] ?? 0),
            'social'         => $_spLikes,
            'repost'         => $_rb ? ['user'=>$_rb['first']['username'], 'others'=>max(0, (int)$_rb['count'] - 1)] : null,
            'cf'             => !empty($GLOBALS['dz_cf_map'][$mid]),
        ]);
    }

    $feedItem = [
        'id'=>$mid, 'file'=>'uploads/'.$row['file_name'], 'type'=>$row['type'],
        'media'=>array_map(function($m){ $it = ['file'=>'uploads/'.$m['file'], 'type'=>$m['type']]; if(strtolower((string)$m['type']) === 'video'){ $pt = dz_thumb_url('uploads/'.$m['file']); if($pt !== '') $it['poster'] = $pt; } return $it; }, $media_items),
        'poster'=>(strtolower((string)$row['type']) === 'video') ? dz_thumb_url('uploads/'.$row['file_name']) : '',
        'pic'=>$pic, 'userId'=>(string)$uid, 'username'=>$row['username'], 'nickname'=>$row['nickname'] ?? '', 'isVerified'=>!empty($row['is_verified']),
        'caption'=>$cap_feed, 'time'=>$row['created_at'],
        'likeCount'   => dz_hidden($row,'hide_likes')   ? null : (int)$row['like_count'],
        'repostCount' => dz_hidden($row,'hide_reposts') ? null : (int)$row['repost_count'],
        'favCount'    => dz_hidden($row,'hide_saves')   ? null : (int)$row['fav_count'],
        'shareCount'  => dz_hidden($row,'hide_shares')  ? null : (int)$row['share_count'],
        'views'       => (int)($row['views'] ?? 0),
        'collab'=>$collab_map[$mid] ?? null, 'poll'=>$poll_map[$mid] ?? null,
        'hideLikes'=>dz_hidden($row,'hide_likes'), 'hideReposts'=>dz_hidden($row,'hide_reposts'),
        'hideSaves'=>dz_hidden($row,'hide_saves'), 'hideShares'=>dz_hidden($row,'hide_shares'),
        'liked'=>(int)$row['user_liked'] > 0, 'reposted'=>(int)$row['user_reposted'] > 0, 'faved'=>(int)$row['user_fav'] > 0,
        'isYou'=>($uid == $user_id), 'isFollowing'=>(int)($row['is_following'] ?? 0) > 0,
        'online'=>$is_online, 'hideTrack'=>!empty($_hide_track_map[$uid]),
        'reason'=>$reason, 'context'=>'Home',
        /* v12: "Suggested for you" — any post that is not yours and whose creator you do not track (ads, search results and reposts by people you track all count) */
        'suggested'=>($user_id && $uid != $user_id && (int)($row['is_following'] ?? 0) === 0),
        'isPartnership'=>!empty($row['is_partnership']), 'partnerBrand'=>$row['partner_brand'] ?? '',
        'isAiGenerated'=>!empty($row['is_ai_generated']),
        'isAd'=>!empty($row['is_ad']), 'adCtaText'=>$row['ad_cta_text'] ?? '', 'adCtaUrl'=>$row['ad_cta_url'] ?? '', 'adCtaColor'=>$row['ad_cta_color'] ?? '#FF4C4C',
        'connectedMemeId'=> !empty($row['connected_meme_id']) ? (int)$row['connected_meme_id'] : null,
        'connectedLabel' => !empty($row['connected_label']) ? $row['connected_label'] : null,
        'groupId'=>$group_info ? (int)$group_info['group_id'] : null, 'groupTitle'=>$group_info ? $group_info['group_title'] : null,
        'audioId'=>$audio_info ? (int)$audio_info['audio_id'] : null, 'audioTitle'=>$audio_info ? $audio_info['title'] : null,
        'audioArtist'=>($audio_info && !empty($audio_info['artist'])) ? $audio_info['artist'] : null,
        'audioFile'=>$audio_info ? 'uploads/'.$audio_info['file_name'] : null,
        'audioIsOriginal'=>(bool)($audio_info && !empty($audio_info['is_original'])),
        'musicVolume'=>$audio_info ? (int)$audio_info['music_volume'] : 80,
        'videoAudioVolume'=>$audio_info ? (int)$audio_info['video_audio_volume'] : 40,
        'socialProof'=>array_map(function($sp){
            return ['id'=>(int)$sp['id'], 'username'=>$sp['username'], 'profile_pic'=>$sp['profile_pic'], 'action_type'=>$sp['action_type'], 'isVerified'=>!empty($sp['is_verified'])];
        }, $social),
        /* v10: the label for a repost — null when nobody you follow reposted it. The original creator stays in uploadedBy / posterUsername. */
        'repostedBy'=> isset($repost_by_map[$mid]) ? [
            'id'=>(int)$repost_by_map[$mid]['first']['id'], 'username'=>$repost_by_map[$mid]['first']['username'],
            'profile_pic'=>$repost_by_map[$mid]['first']['profile_pic'], 'at'=>$repost_by_map[$mid]['first']['acted_at'],
            'others'=>max(0, (int)$repost_by_map[$mid]['count'] - 1),
        ] : null,
    ];

    $kebabItem = [
        'id'=>$mid, 'fileName'=>$row['file_name'], 'type'=>$row['type'], 'uploadedBy'=>$uid,
        'allowDownload'=>(int)($row['allow_download'] ?? 1),
        'collab'=>$collab_map[$mid] ?? null,
        'remixOf'=>!empty($row['remix_of']) ? (int)$row['remix_of'] : null,
        'caption'=>$cap_feed, 'isAd'=>!empty($row['is_ad']),
        'posterPic'=> $DZ_IS_SEARCH ? $pic : (!empty($row['profile_pic']) ? $row['profile_pic'] : 'mee.jpg'),
        'posterUsername'=>$row['username'],
        'why'=>$why,   /* v11: [{icon, title, text}, …] shown by "Why am I seeing this post / ad" */
    ];

    $listItem = null;
    if($DZ_IS_SEARCH){
        $thumb = null;   /* square thumbnail = first real media item; none → text post */
        foreach($media_items as $mi){
            if(in_array(strtolower((string)$mi['type']), $_thumb_types, true) && !empty($mi['file']) && $mi['file'] !== '0'){ $thumb = $mi; break; }
        }
        $listItem = [
            'id'=>$mid, 'uid'=>$uid, 'thumb'=>$thumb, 'count'=>count($media_items), 'isAd'=>!empty($row['is_ad']),
            'pic'=>$pic, 'username'=>$row['username'], 'nickname'=>trim((string)($row['nickname'] ?? '')), 'isVerified'=>!empty($row['is_verified']),
            'caption'=>spCaptionHtml($row['caption']), 'online'=>$is_online, 'collab'=>$collab_map[$mid] ?? null,
            'likes'=>(int)$row['like_count'], 'saves'=>(int)$row['fav_count'], 'reposts'=>(int)$row['repost_count'], 'shares'=>(int)$row['share_count'],
            'hideLikes'=>dz_hidden($row,'hide_likes'), 'hideSaves'=>dz_hidden($row,'hide_saves'),
            'hideReposts'=>dz_hidden($row,'hide_reposts'), 'hideShares'=>dz_hidden($row,'hide_shares'),
            'liked'=>(int)$row['user_liked'] > 0, 'faved'=>(int)$row['user_fav'] > 0, 'reposted'=>(int)$row['user_reposted'] > 0,
        ];
    }

    /* top-level keys are what hpApplyAdCap / hpDiversityGuard read */
    $_entries[] = [
        'id'=>$mid, 'userId'=>(string)$uid, 'caption'=>$cap_raw, 'isAd'=>!empty($row['is_ad']),
        'adCtaUrl'=>$row['ad_cta_url'] ?? '', 'feed'=>$feedItem, 'kebab'=>$kebabItem, 'list'=>$listItem,
    ];
}

/* ad frequency cap + diversity guard (strict sorts skip the guard) */
$_strict_sorts = ['most_liked','most_reposted','most_favorited','most_shared','most_viewed','newest'];
$_entries = hpApplyAdCap($_entries, HP_AD_GAP, HP_AD_LEAD, !in_array($filter, $_strict_sorts, true));

/* v5 measurement: which post was served at which position (ads left out), tagged with the algorithm variant */
if($use_v2 && !$DZ_IS_SEARCH && $user_id && function_exists('dz_log_serves')){
    $_tm = microtime(true);
    $_serve_log = []; $_serve_pos = ($page - 1) * $page_size;
    foreach($_entries as $_e){
        if(!empty($_e['isAd'])) continue;
        $_serve_pos++;
        $_serve_log[] = [(int)$_e['id'], $_serve_pos];
    }
    if(empty($_GET['nolog'])) dz_log_serves($conn, $user_id, $_serve_log, $page, $dz_variant, $feed_seed);   /* ?nolog=1: the zeems modal's background fetch must not count 150 posts as "served" */
    dz_time('log_serves', $_tm);
}

$_list = []; $_peura_feed_data = []; $_kebab_data = [];
foreach($_entries as $e){
    $_peura_feed_data[]    = $e['feed'];
    $_kebab_data[$e['id']] = $e['kebab'];
    if($e['list'] !== null) $_list[] = $e['list'];
}
$_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;

/* ?feed_json=1&page=2&seed=123&asof=1700000000  → the same feed, as JSON, for infinite scroll */
if(!empty($_GET['feed_json'])){
    header('Content-Type: application/json');
    dz_timing_flush();   /* v9: X-DZ-Timing header + slow-feed log */
    $_timing_out = [];
    if(DZ_TIMING){ list($_tl_line, $_tl_total) = dz_timing_summary(); $_timing_out = ['timing' => ['steps' => $GLOBALS['dz_timing'], 'total_ms' => $_tl_total]]; }
    echo json_encode([
        'feed'    => $_peura_feed_data,
        'kebab'   => $_kebab_data,
        'list'    => $_list,
        'page'    => $page,
        'hasMore' => $has_more,
        'seed'    => $feed_seed,
        'asof'    => $feed_asof,
    ] + (DZ_ALGO_DEBUG ? ['debug' => $dz_algo_debug] : []) + $_timing_out, $_json_flags);
    exit;
}

/* ================================================================
   VIEW VARIABLES (tags, sort menu, right-hand panels)
================================================================ */
$ht          = dz_hashtags_sorted($user_top_tags ?? []);   /* see hashtags.php */
$sorted_tags = $ht['sorted'];
$lower_top   = $DZ_IS_SEARCH ? array_map('strtolower', $user_top_tags ?? []) : $ht['lower'];

$sort_items = [
    'trending'       => ['ri-fire-line','Trending'],
    'most_liked'     => ['ri-heart-3-line','Most Liked'],
    'most_reposted'  => ['ri-repeat-line','Most Reposted'],
    'most_favorited' => ['ri-bookmark-line','Most Favorited'],
    'most_viewed'    => ['ri-eye-line','Most Viewed'],
    'newest'         => ['ri-time-line','Newest'],
    'following'      => ['ri-user-follow-line','Tracking'],
    'followers'      => ['ri-user-star-line','Trackers'],
];

/* hashtags for the right-hand panel (same order as the tag bar, top 20, "All" left out) */
$ex_tags = [];
foreach($sorted_tags as $t){ if(is_string($t) && $t !== '' && strtolower($t) !== 'all') $ex_tags[] = $t; }
$ex_tags = array_slice($ex_tags, 0, 20);

dz_timing_flush();   /* v9: X-DZ-Timing header for the HTML page (nothing has been printed yet) */