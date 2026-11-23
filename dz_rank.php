<?php
/* ================================================================
   dz_rank.php — Dezzme feed algorithm v2  (steps 3 + 4)  +  v3: "Your Algorithm" settings  +  v4: media understanding
                 +  v5: measurement + smarter scoring  +  v6: wider candidates  +  v7: settings really apply everywhere

   WHAT IT DOES
     1. user affinity   : every like / repost / save / share / comment / view / "not interested" updates ONE small table
                          (dz_user_affinity: per person, per tag / creator / post-type) → reading someone's interests is one fast query
     2. impressions     : the page tells the server which posts were actually seen (dz_feed_impressions)
     3. the ranker      : dz_rank_feed() scores ~1000-1500 candidate posts in PHP and re-orders them:

          score = (engagement_per_age + noise) × engagement-rate × quality × relevance × following × novelty
                  × content-levels × velocity × newness-bonus

          relevance  = 1 + topics you like (stack, capped) + creators you like + post types you like + friends who liked it
                       + what you touched in the last 20 minutes
                       a post's topics = its #hashtags + the words of its caption + what its photo / video SHOWS (v4)
                       topics are normalised (glute = glutes = your synonyms) (v5)
          novelty    = posts you already saw are pushed down (strongest right after you saw them)
          rate       = posts that convert viewers into likes beat posts that merely got shown a lot
                       (v5: smoothed — a post with few viewers is pulled toward the site average instead of being ignored)
          local      = (v5) with "Show more local content" on, posters in the viewer's About me location get a lift (DZ_LOCAL_WEIGHT)
          velocity   = (v5) posts that are gaining likes / reposts / saves / shares / comments RIGHT NOW get a lift
          newness    = (v5) a small bonus for young posts we know little about (a light "bandit": the less seen, the bigger the bonus)
          exploration= a fresh, little-seen post outside your usual taste every few slots
          skips      = a post you looked at briefly and moved on from is shown less (and trains your taste a little)
          lingers    = a post you stayed on for a while trains your taste a little the other way
                       (v5: "briefly" / "a while" depend on the post: videos and long texts need longer)
          content    = the person's Sensitive content / Political content level (Less · Standard · More) against the post's
                       0-100 scores in dz_media_analysis (v4)

     4. YOUR ALGORITHM (algorithm_settings.php, see dz_algo_prefs.php)
          • topics / creators / formats a person picked "more" or "less" of are merged into the taste map (dz_load_affinity)
          • adventure dial      → how strongly taste counts + how often exploration posts appear
          • tracking boost      → 0.85 / 1.25 / 1.6 for people they track   (v7: "Less" is now a real reduction)
          • seen posts          → let them return / balanced / fresh only
          • sensitive / political level → Less · Standard · More
          • learning switches   → stop learning from likes etc., stop learning from watch time
          • v7: "Reset what my feed has learned" sets forget_since; every reader of raw user_interactions ignores older rows,
                and every reader of raw interactions stops when "Learn from my likes" is off
          • v7: creators picked as "more" are a candidate source of their own (dz_picked_creator_ids, DZ_PICKED_POOL)

     5. MEASUREMENT (v5): dz_log_serves() records which post was served at which position, with the algorithm variant.
          Set DZ_AB_TEST to true to split people in two groups: A = the v4 behaviour, B = the v5 behaviour (user_id even = A, odd = B).
          Compare the two with the SQL in dezzme_migration_v5.sql.

     6. v6 (variant B): wider candidates, cold start, related topics, anti-gaming, language
          • collaborative filtering : posts that people with your taste liked (dz_cf_candidates) + a relevance boost for them
          • cold start              : a new person gets the best of many different topics + more exploration; fresh posts nobody has seen yet
                                      get a random slice into every feed; small creators' new posts get a chance; a post waiting for its media
                                      check is no longer held back when the creator is tracked or has a clean record
          • related topics          : topics that appear together on the site (squat ~ leg day ~ gym) share taste (dz_related_terms)
          • anti-gaming             : "gaining right now" counts established accounts only, fake-looking bursts are penalised,
                                      creators many different people report or block are shown less
          • language                : a post in a language the person does not read is shown less (learned from what they engage with)

   SWITCH IT OFF:  define('DZ_ALGO_V2', false);  before shared_core.php loads (or edit the line below) → the old SQL ranking is used.
   TUNING: every number you might want to change is a constant right below.
   NOTE: `sensitive` is a reserved word in MariaDB/MySQL: in SQL it is always written with backticks.
================================================================ */

require_once __DIR__ . '/dz_algo_prefs.php';   /* settings + explicit choices (fails soft if its tables are missing) */
if(is_file(__DIR__ . '/dz_local_content.php')) require_once __DIR__ . '/dz_local_content.php';   /* "Show more local content": About me location helpers */

if(!defined('DZ_ALGO_V2'))          define('DZ_ALGO_V2', true);   /* master switch */
if(!defined('DZ_CAND_POOL'))        define('DZ_CAND_POOL', 600);  /* top-N posts by the cheap SQL score */
if(!defined('DZ_FRESH_POOL'))       define('DZ_FRESH_POOL', 120); /* + the newest posts of the last 3 days (so new posts can compete) */
if(!defined('DZ_CAND_DAYS'))        define('DZ_CAND_DAYS', 60);   /* the "best recent posts" source only looks this many days back (keeps the query fast)... */
if(!defined('DZ_CAND_MIN'))         define('DZ_CAND_MIN', 200);   /* ...unless that finds fewer than this many posts (small / quiet site) → then it looks at everything */
if(!defined('DZ_TAG_POOL'))         define('DZ_TAG_POOL', 150);   /* + recent posts that use this person's favourite tags */
if(!defined('DZ_FOLLOW_POOL'))      define('DZ_FOLLOW_POOL', 100);/* + recent posts from people they follow */
if(!defined('DZ_PICKED_POOL'))      define('DZ_PICKED_POOL', 80); /* v7: + recent posts from creators they picked as "more" on algorithm_settings.php (0 = off) */
if(!defined('DZ_RATE_MIN_IMP'))     define('DZ_RATE_MIN_IMP', 30);   /* variant A: a post needs this many viewers before its engagement RATE is trusted */
if(!defined('DZ_EXPLORE_MAX_IMP'))  define('DZ_EXPLORE_MAX_IMP', 40);/* "little seen" = fewer viewers than this */
if(!defined('DZ_EXPLORE_EVERY'))    define('DZ_EXPLORE_EVERY', 7);   /* an exploration post every N positions (at the middle "adventure" setting) */
/* skip / linger signals (milliseconds a post was ≥60% on screen). Small weights: a like is +2, a skip is only −0.4.
   These are the DEFAULTS for an image post; videos and long texts get longer limits (dz_dwell_thresholds below). */
if(!defined('DZ_SKIP_MIN_MS'))      define('DZ_SKIP_MIN_MS', 250);     /* shorter than this = just scrolling past, no signal */
if(!defined('DZ_SKIP_MAX_MS'))      define('DZ_SKIP_MAX_MS', 1000);    /* 250 ms – 1 s = looked, moved on = SKIP */
if(!defined('DZ_LINGER_MS'))        define('DZ_LINGER_MS', 3000);      /* 3 s or more = LINGER */
if(!defined('DZ_DEEP_MS'))          define('DZ_DEEP_MS', 8000);        /* 8 s or more = long linger */
if(!defined('DZ_SKIP_WEIGHT'))      define('DZ_SKIP_WEIGHT', -0.4);
if(!defined('DZ_LINGER_WEIGHT'))    define('DZ_LINGER_WEIGHT', 0.4);
if(!defined('DZ_DEEP_WEIGHT'))      define('DZ_DEEP_WEIGHT', 0.8);
/* v4: media understanding */
if(!defined('DZ_CAPTION_WORD_W'))   define('DZ_CAPTION_WORD_W', 0.6);  /* a caption word counts this much compared with a hashtag or a word the AI saw in the media (1.0) */
if(!defined('DZ_LEARN_MEDIA_TERMS'))define('DZ_LEARN_MEDIA_TERMS', 8); /* a like / save teaches the taste map up to this many of the post's AI topics (0 = hashtags only) */
/* v4: safety rules on top of the soft ranking (index = the person's level: 0 less · 1 standard · 2 more) */
if(!defined('DZ_HIDE_SENSITIVE'))   define('DZ_HIDE_SENSITIVE', [60, 85, 95]);   /* posts from people they do NOT track, at or above this sensitive score, are left out completely */
if(!defined('DZ_HIDE_POLITICAL_LESS')) define('DZ_HIDE_POLITICAL_LESS', 85);     /* with "Less" political content: left out at or above this political score */
if(!defined('DZ_PENDING_DAYS'))     define('DZ_PENDING_DAYS', 30);               /* a post this young with no analysis yet counts as "not checked yet" */
if(!defined('DZ_PENDING_SENSITIVE'))define('DZ_PENDING_SENSITIVE', [0.35, 0.95, 1.0]);  /* score multiplier for a not-checked-yet post */
if(!defined('DZ_PENDING_POLITICAL'))define('DZ_PENDING_POLITICAL', [0.6, 1.0, 1.0]);
if(!defined('DZ_ALGO_DEBUG'))       define('DZ_ALGO_DEBUG', false);  /* true → feed_json responses include a "debug" list with each post's score parts */

/* v5: measurement + A/B */
if(!defined('DZ_AB_TEST'))          define('DZ_AB_TEST', false);     /* true → even user ids get variant A (v4 behaviour), odd ids get B (v5). false → everybody gets B */
if(!defined('DZ_LOG_SERVES'))       define('DZ_LOG_SERVES', true);   /* record which post was served at which position (table dz_feed_serves, created automatically) */
if(!defined('DZ_SERVES_KEEP_DAYS')) define('DZ_SERVES_KEEP_DAYS', 14);
/* v5: smarter scoring (all variant B only) */
if(!defined('DZ_RATE_FLOOR_IMP'))   define('DZ_RATE_FLOOR_IMP', 10);  /* posts with at least this many viewers are used to measure the site averages */
if(!defined('DZ_PRIOR_IMP'))        define('DZ_PRIOR_IMP', 20);       /* smoothing strength: a post counts as if it already had this many average viewers */
if(!defined('DZ_VELOCITY_HOURS'))   define('DZ_VELOCITY_HOURS', 6);   /* "gaining right now" looks at this many hours of likes / reposts / saves / shares / comments */
if(!defined('DZ_VELOCITY_W'))       define('DZ_VELOCITY_W', 0.2);     /* how strongly velocity lifts a post (the lift is capped at +50%) */
if(!defined('DZ_HOT_POOL'))         define('DZ_HOT_POOL', 100);       /* + posts with the most engagement in the last DZ_VELOCITY_HOURS (candidate source F in shared_core.php) */
if(!defined('DZ_SESSION_MIN'))      define('DZ_SESSION_MIN', 20);     /* topics you liked / saved / shared in the last N minutes count extra */
if(!defined('DZ_SESSION_CAP'))      define('DZ_SESSION_CAP', 0.5);    /* the most this short-term interest can add to relevance */
if(!defined('DZ_UCB'))              define('DZ_UCB', 0.25);           /* newness bonus for young, little-seen posts (0 = off) */
if(!defined('DZ_FEED_CACHE_SEC'))   define('DZ_FEED_CACHE_SEC', 1800);/* the ranked list of one feed session is remembered this long, so page 2, 3… continue page 1 exactly */
/* v5: topic synonyms: 'word people use' => 'the one topic it counts as'. Plurals (glute / glutes) are handled automatically.
   Example:  define('DZ_SYNONYMS', ['booty' => 'glute', 'butt' => 'glute']);   — put your own list here or before shared_core.php loads */
if(!defined('DZ_SYNONYMS'))         define('DZ_SYNONYMS', []);

/* "Show more local content" (switch on algorithm_settings.php): how much a poster in the viewer's place adds to relevance.
   same city = +DZ_LOCAL_WEIGHT, same country / wider place only = half of it. 0.25 is about as strong as a creator you like. 0 = off. */
if(!defined('DZ_LOCAL_WEIGHT'))     define('DZ_LOCAL_WEIGHT', 0.25);

/* v7: the "People you track" dial: Less · Balanced · More. "Less" is now below 1.0, so it really lowers tracked posts. */
if(!defined('DZ_FOLLOW_MULT'))      define('DZ_FOLLOW_MULT', [0.85, 1.25, 1.6]);
/* v8: explicit choices vs learned behaviour, suppression, diversity, negative feedback */
if(!defined('DZ_LESS_STRENGTH'))        define('DZ_LESS_STRENGTH', 0.65);   /* a post about a "Less" topic keeps at least 35% of its score (suppressed, never zeroed) */
if(!defined('DZ_LESS_RELATED_W'))       define('DZ_LESS_RELATED_W', 0.4);   /* topics related to a "Less" topic are lowered by this share (0 = off) */
if(!defined('DZ_LESS_GAP'))             define('DZ_LESS_GAP', 10);          /* at most one "Less" post per this many positions; the rest wait further down */
if(!defined('DZ_NEG_FLOOR'))            define('DZ_NEG_FLOOR', -6);         /* learned taste never goes below this, so no topic is condemned for good */
if(!defined('DZ_EXPLORE_POOL_MAX'))     define('DZ_EXPLORE_POOL_MAX', 400); /* discovery picks come from the best N ranked posts, so they are still decent */
if(!defined('DZ_EXPLORE_MIN_NOVELTY'))  define('DZ_EXPLORE_MIN_NOVELTY', 0.7);  /* a discovery post must be this different from what the feed already shows */

/* ================================================================
   v7 — helpers shared by every reader of raw user_interactions
   Both fail soft when dz_algo_prefs.php is an older copy without the v7 functions.
================================================================ */

/* " AND <col> >= 'forget_since'" after the person pressed "Reset what my feed has learned", otherwise '' ($col is always a column written in code) */
if(!function_exists('dz_rank_since')){
    function dz_rank_since($conn, $user_id, $col){
        return function_exists('dz_algo_since_sql') ? dz_algo_since_sql($conn, (int)$user_id, $col) : '';
    }
}
/* false when the person switched "Learn from my likes, saves and reposts" off */
if(!function_exists('dz_rank_learning_ok')){
    function dz_rank_learning_ok($conn, $user_id){
        return !(function_exists('dz_algo_learning_off') && dz_algo_learning_off($conn, (int)$user_id));
    }
}

/* v7: creators this person picked as "more" on algorithm_settings.php, newest choice first (candidate source for shared_core.php) */
if(!function_exists('dz_picked_creator_ids')){
    function dz_picked_creator_ids($conn, $user_id){
        $out = [];
        $user_id = (int)$user_id;
        if($user_id <= 0 || !function_exists('dz_algo_load_prefs')) return $out;
        foreach(dz_algo_load_prefs($conn, $user_id) as $p){
            if(($p['kind'] ?? '') === 'creator' && ($p['pref'] ?? '') === 'more'){
                $id = (int)$p['item'];
                if($id > 0 && $id !== $user_id) $out[$id] = $id;
            }
        }
        return array_slice(array_values($out), 0, 40);
    }
}

/* [poster_id => 0 … 1] — how local each poster is to the viewer (their About me "Current location").
   One query for all posters (dz_local_boost_map would run one query per poster). Returns [] when the viewer has no location. */
if(!function_exists('dz_local_poster_scores')){
    function dz_local_poster_scores($conn, $viewer, $posterIds){
        $out = [];
        $viewer = (int)$viewer;
        if($viewer <= 0 || !function_exists('dz_about_location') || !function_exists('dz_local_score')) return $out;
        $mine = dz_about_location($conn, $viewer);            /* also finds + remembers which table holds current_location */
        if($mine === '') return $out;
        $ids = [];
        foreach($posterIds as $p){ $p = (int)$p; if($p > 0 && $p !== $viewer) $ids[$p] = $p; }
        $ids = array_values($ids);
        if(!$ids) return $out;
        $tbl = (string)($_SESSION['dz_tbl_loc'] ?? '');
        if($tbl !== '' && preg_match('/^[A-Za-z0-9_]+$/', $tbl)){
            try{
                $res = $conn->query("SELECT user_id, current_location FROM `$tbl` WHERE user_id IN (" . implode(',', $ids) . ")");
                if($res) while($r = $res->fetch_assoc()) $out[(int)$r['user_id']] = dz_local_score($mine, (string)($r['current_location'] ?? ''));
            }catch(Throwable $e){}
            foreach($ids as $id){ if(!isset($out[$id])) $out[$id] = 0.0; }
            return $out;
        }
        return function_exists('dz_local_boost_map') ? dz_local_boost_map($conn, $viewer, $ids) : [];
    }
}

/* ================================================================
   v6 — wider candidates, cold start, related topics, anti-gaming, language
   Everything here is variant B only (variant A keeps the v4 behaviour for A/B comparison).
   All of it fails soft: a missing table / column simply switches that one feature off.
================================================================ */

/* 1. collaborative filtering: "people with your taste liked this" */
if(!defined('DZ_CF_POOL'))          define('DZ_CF_POOL', 150);        /* posts pulled in from people with similar taste */
if(!defined('DZ_CF_W'))             define('DZ_CF_W', 0.4);           /* how much "people like you liked it" adds to relevance (at most) */
if(!defined('DZ_CF_MIN_ACTIONS'))   define('DZ_CF_MIN_ACTIONS', 4);   /* needs at least this many recent likes / saves / reposts / shares / comments to find similar people */
if(!defined('DZ_CF_NEIGHBORS'))     define('DZ_CF_NEIGHBORS', 60);    /* how many similar people are used */
if(!defined('DZ_CF_DAYS'))          define('DZ_CF_DAYS', 14);         /* what those people liked in the last N days */
if(!defined('DZ_CF_CACHE_SEC'))     define('DZ_CF_CACHE_SEC', 600);   /* the similar-people list is remembered this long (per session) */
/* 2. cold start */
if(!defined('DZ_COLD_MIN_ACTIONS')) define('DZ_COLD_MIN_ACTIONS', 6); /* fewer positive actions than this (and < 3 known topics) = a new person */
if(!defined('DZ_COLD_TAGS'))        define('DZ_COLD_TAGS', 14);       /* a new person gets the best recent posts of this many different popular topics... */
if(!defined('DZ_COLD_PER_TAG'))     define('DZ_COLD_PER_TAG', 12);    /* ...this many per topic, so their first reactions teach us something */
if(!defined('DZ_UNDEREXP_POOL'))    define('DZ_UNDEREXP_POOL', 150);  /* a random slice of fresh posts that almost nobody has seen yet (a different slice for every feed) */
if(!defined('DZ_UNDEREXP_MAX_IMP')) define('DZ_UNDEREXP_MAX_IMP', 30);/* "almost nobody" = fewer viewers than this */
if(!defined('DZ_EXPLORE_FROM'))     define('DZ_EXPLORE_FROM', 12);    /* exploration posts may come from rank N downward (v4: 30) */
if(!defined('DZ_EXPLORE_MAX_IMP_B'))define('DZ_EXPLORE_MAX_IMP_B', 60);/* exploration: "little seen" = fewer viewers than this (v4: 40) */
if(!defined('DZ_SMALL_CREATOR_FOLLOWERS')) define('DZ_SMALL_CREATOR_FOLLOWERS', 50);  /* a creator with fewer trackers than this is "new" */
if(!defined('DZ_SMALL_CREATOR_BONUS'))     define('DZ_SMALL_CREATOR_BONUS', 0.15);    /* extra newness bonus for a small creator's young post (only if nobody reported them) */
if(!defined('DZ_PENDING_SENSITIVE_B'))     define('DZ_PENDING_SENSITIVE_B', [0.6, 1.0, 1.0]);   /* a post waiting for its media check: less harsh than v4 [0.35, 0.95, 1.0] */
if(!defined('DZ_PENDING_POLITICAL_B'))     define('DZ_PENDING_POLITICAL_B', [0.85, 1.0, 1.0]);  /* (people they track, and creators whose earlier posts were all clean, are not held back at all) */
/* 3. related topics (learned from the site's own posts: topics that appear together) */
if(!defined('DZ_RELATED_W'))        define('DZ_RELATED_W', 0.5);      /* a related topic counts this much of the topic it comes from (0 = off) */
if(!defined('DZ_RELATED_TOPICS'))   define('DZ_RELATED_TOPICS', 8);   /* the person's top N topics are expanded */
if(!defined('DZ_RELATED_BUILD_MAX'))define('DZ_RELATED_BUILD_MAX', 3);/* at most this many topics are (re)computed per request; the rest next time */
if(!defined('DZ_RELATED_DAYS'))     define('DZ_RELATED_DAYS', 7);     /* a topic's related list is recomputed after this many days */
/* 4. spam / gaming */
if(!defined('DZ_TRUST_MIN_DAYS'))   define('DZ_TRUST_MIN_DAYS', 3);   /* accounts younger than this count only a little when measuring "gaining right now" */
if(!defined('DZ_TRUST_NEW_W'))      define('DZ_TRUST_NEW_W', 0.25);   /* ...this much */
if(!defined('DZ_SUSPECT_MIN'))      define('DZ_SUSPECT_MIN', 20);     /* a burst of at least this much engagement... */
if(!defined('DZ_SUSPECT_RATIO'))    define('DZ_SUSPECT_RATIO', 0.35); /* ...of which less than this share comes from established accounts looks fake */
if(!defined('DZ_SUSPECT_MULT'))     define('DZ_SUSPECT_MULT', 0.6);   /* → the post's score is multiplied by this */
if(!defined('DZ_REP_W'))            define('DZ_REP_W', 0.15);         /* each report / block (by a different person) lowers a creator's posts by this much... */
if(!defined('DZ_REP_FLOOR'))        define('DZ_REP_FLOOR', 0.4);      /* ...but never below this */
if(!defined('DZ_REP_DAYS'))         define('DZ_REP_DAYS', 30);        /* reports and blocks from the last N days (if the tables have a date) */
/* 5. language */
if(!defined('DZ_LANG_MISMATCH'))    define('DZ_LANG_MISMATCH', 0.4);       /* a post in a language the person does not read (known from what they engaged with) */
if(!defined('DZ_LANG_MISMATCH_WEAK'))define('DZ_LANG_MISMATCH_WEAK', 0.7); /* same, when we only know their browser language */
if(!defined('DZ_LANG_MIN_SHARE'))   define('DZ_LANG_MIN_SHARE', 0.12);     /* a language counts as "theirs" at this share of what they engaged with */

/* tables used by v6 (created automatically, once per session) */
if(!function_exists('dz_v6_ensure_tables')){
    function dz_v6_ensure_tables($conn){
        if(!empty($_SESSION['dz_v6_ready'])) return;
        try{
            $conn->query("CREATE TABLE IF NOT EXISTS dz_meme_lang (
                meme_id    INT UNSIGNED NOT NULL PRIMARY KEY,
                lang       VARCHAR(8) NOT NULL DEFAULT 'und',
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS dz_term_related (
                term       VARCHAR(64) NOT NULL,
                related    VARCHAR(64) NOT NULL,
                score      FLOAT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (term, related),
                KEY k_upd (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if(session_status() === PHP_SESSION_ACTIVE) $_SESSION['dz_v6_ready'] = 1;
        }catch(Throwable $e){}
    }
}
/* does table.column exist? (cached) — only ever called with names written in this file */
if(!function_exists('dz_has_col')){
    function dz_has_col($conn, $table, $col){
        static $mem = [];
        $k = $table . '.' . $col;
        if(isset($mem[$k])) return $mem[$k];
        if(isset($_SESSION['dz_cols'][$k])) return $mem[$k] = (bool)$_SESSION['dz_cols'][$k];
        $ok = false;
        try{ $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'"); $ok = ($r && $r->num_rows > 0); }catch(Throwable $e){}
        $mem[$k] = $ok;
        if(session_status() === PHP_SESSION_ACTIVE) $_SESSION['dz_cols'][$k] = $ok ? 1 : 0;
        return $ok;
    }
}

/* ── 4. spam / gaming ─────────────────────────────────────────── */

/* the users-table column that holds the sign-up date (first match), or '' when there is none → then every account counts as established */
if(!function_exists('dz_users_age_col')){
    function dz_users_age_col($conn){
        static $c = null;
        if($c !== null) return $c;
        if(isset($_SESSION['dz_users_age_col'])) return $c = (string)$_SESSION['dz_users_age_col'];
        $c = '';
        foreach(['created_at', 'created', 'registered_at', 'joined_at', 'date_joined', 'signup_date', 'date_created'] as $col){
            if(dz_has_col($conn, 'users', $col)){ $c = $col; break; }
        }
        if(session_status() === PHP_SESSION_ACTIVE) $_SESSION['dz_users_age_col'] = $c;
        return $c;
    }
}
/* SQL number: 1 for an established account, DZ_TRUST_NEW_W for a brand-new one ($alias = the users table alias) */
if(!function_exists('dz_trust_sql')){
    function dz_trust_sql($alias, $conn){
        $col = dz_users_age_col($conn);
        if($col === '') return '1';
        return "(CASE WHEN $alias.`$col` IS NULL OR $alias.`$col` <= (NOW() - INTERVAL " . (int)DZ_TRUST_MIN_DAYS . " DAY) THEN 1 ELSE " . (float)DZ_TRUST_NEW_W . " END)";
    }
}

/* engagement gained in the last N hours, per post: [meme_id => [trusted, raw]]
   raw     = every like / repost / save / share / comment (weights = dz_engagement)
   trusted = the same, but accounts younger than DZ_TRUST_MIN_DAYS count only DZ_TRUST_NEW_W, and the poster's own actions count 0
   A big gap between the two is how a burst of fake accounts shows up. Needs the (meme_id, created_at) index. */
if(!function_exists('dz_recent_engagement')){
    function dz_recent_engagement($conn, $ids, $hours){
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if(!$ids) return $out;
        $hours = max(1, (int)$hours);
        $trust = dz_trust_sql('tu', $conn);
        try{
            $res = $conn->query("
                SELECT ui.meme_id,
                       SUM(CASE ui.interaction_type WHEN 'like' THEN 4 WHEN 'repost' THEN 6 WHEN 'favorite' THEN 3 WHEN 'share' THEN 5 WHEN 'comment' THEN 3 ELSE 0 END) AS raw_w,
                       SUM(CASE ui.interaction_type WHEN 'like' THEN 4 WHEN 'repost' THEN 6 WHEN 'favorite' THEN 3 WHEN 'share' THEN 5 WHEN 'comment' THEN 3 ELSE 0 END
                           * $trust * (CASE WHEN ui.user_id = m.uploaded_by THEN 0 ELSE 1 END)) AS trusted_w
                FROM user_interactions ui
                JOIN memes m  ON m.id  = ui.meme_id
                JOIN users tu ON tu.id = ui.user_id
                WHERE ui.meme_id IN (" . implode(',', $ids) . ")
                  AND ui.created_at >= (NOW() - INTERVAL $hours HOUR)
                  AND ui.interaction_type IN ('like','repost','favorite','share','comment')
                GROUP BY ui.meme_id
            ");
            if($res) while($r = $res->fetch_assoc()) $out[(int)$r['meme_id']] = [(float)$r['trusted_w'], (float)$r['raw_w']];
        }catch(Throwable $e){}
        return $out;
    }
}
/* the posts gaining the most (trusted) engagement right now, whatever their age — candidate source F.
   Bursts that look fake (see DZ_SUSPECT_*) are left out. Reads a few hours of user_interactions: add the created_at index from dezzme_migration_v6.sql. */
if(!function_exists('dz_hot_posts')){
    function dz_hot_posts($conn, $hours, $limit){
        $ids = [];
        $hours = max(1, (int)$hours); $limit = max(1, (int)$limit);
        $trust = dz_trust_sql('tu', $conn);
        try{
            $res = $conn->query("
                SELECT ui.meme_id,
                       SUM(CASE ui.interaction_type WHEN 'like' THEN 4 WHEN 'repost' THEN 6 WHEN 'favorite' THEN 3 WHEN 'share' THEN 5 WHEN 'comment' THEN 3 ELSE 0 END) AS raw_w,
                       SUM(CASE ui.interaction_type WHEN 'like' THEN 4 WHEN 'repost' THEN 6 WHEN 'favorite' THEN 3 WHEN 'share' THEN 5 WHEN 'comment' THEN 3 ELSE 0 END
                           * $trust * (CASE WHEN ui.user_id = m.uploaded_by THEN 0 ELSE 1 END)) AS trusted_w
                FROM user_interactions ui
                JOIN memes m  ON m.id  = ui.meme_id
                JOIN users tu ON tu.id = ui.user_id
                WHERE ui.created_at >= (NOW() - INTERVAL $hours HOUR)
                  AND ui.interaction_type IN ('like','repost','favorite','share','comment')
                GROUP BY ui.meme_id
                HAVING NOT (raw_w >= " . (float)DZ_SUSPECT_MIN . " AND trusted_w < raw_w * " . (float)DZ_SUSPECT_RATIO . ")
                ORDER BY trusted_w DESC
                LIMIT $limit
            ");
            if($res) while($r = $res->fetch_row()) $ids[] = (int)$r[0];
        }catch(Throwable $e){}
        return $ids;
    }
}
/* [creator_id => number of trackers] for the given creators */
if(!function_exists('dz_follower_counts')){
    function dz_follower_counts($conn, $uids){
        $out = [];
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if(!$uids) return $out;
        try{
            $res = $conn->query("SELECT following_id, COUNT(*) FROM followers WHERE following_id IN (" . implode(',', $uids) . ") GROUP BY following_id");
            if($res) while($r = $res->fetch_row()) $out[(int)$r[0]] = (int)$r[1];
        }catch(Throwable $e){}
        return $out;
    }
}
/* [creator_id => reputation penalty ≥ 0]: how many DIFFERENT people reported or blocked them (a block counts half),
   divided by their size (log of trackers) so a famous creator is not punished for being famous. 0 = clean. */
if(!function_exists('dz_creator_reports')){
    function dz_creator_reports($conn, $uids, $folCnt){
        $out = []; $raw = [];
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if(!$uids) return $out;
        $list = implode(',', $uids); $days = (int)DZ_REP_DAYS;
        try{
            $w = dz_has_col($conn, 'reports', 'created_at') ? " AND created_at >= (NOW() - INTERVAL $days DAY)" : '';
            $res = $conn->query("SELECT reported_id, COUNT(DISTINCT reporter_id) FROM reports WHERE reported_id IN ($list)$w GROUP BY reported_id");
            if($res) while($r = $res->fetch_row()) $raw[(int)$r[0]] = ($raw[(int)$r[0]] ?? 0.0) + (float)$r[1];
        }catch(Throwable $e){}
        try{
            $w = dz_has_col($conn, 'blocks', 'created_at') ? " AND created_at >= (NOW() - INTERVAL $days DAY)" : '';
            $res = $conn->query("SELECT blocked_id, COUNT(DISTINCT blocker_id) FROM blocks WHERE blocked_id IN ($list)$w GROUP BY blocked_id");
            if($res) while($r = $res->fetch_row()) $raw[(int)$r[0]] = ($raw[(int)$r[0]] ?? 0.0) + 0.5 * (float)$r[1];
        }catch(Throwable $e){}
        foreach($raw as $id => $v){ $out[$id] = $v / (1.0 + log10(1.0 + (int)($folCnt[$id] ?? 0))); }
        return $out;
    }
}
/* creators whose earlier analysed posts were ALL clean (≥3 posts, none sensitive or political) → their brand-new posts are not held back while the media check is pending */
if(!function_exists('dz_clean_creators')){
    function dz_clean_creators($conn, $uids){
        $out = [];
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if(!$uids) return $out;
        try{
            $res = $conn->query("
                SELECT m.uploaded_by AS u, COUNT(*) AS n, MAX(a.`sensitive`) AS s, MAX(a.political) AS p
                FROM memes m JOIN dz_media_analysis a ON a.meme_id = m.id
                WHERE m.uploaded_by IN (" . implode(',', $uids) . ")
                  AND m.created_at >= (NOW() - INTERVAL 120 DAY) AND COALESCE(a.error, '') = ''
                GROUP BY m.uploaded_by
            ");
            if($res) while($r = $res->fetch_assoc()){
                if((int)$r['n'] >= 3 && (int)$r['s'] < 40 && (int)$r['p'] < 40) $out[(int)$r['u']] = 1;
            }
        }catch(Throwable $e){}
        return $out;
    }
}

/* ── 1 + 2. collaborative filtering and cold start ────────────── */

/* "people with your taste liked this":
     1. your last ~80 likes / saves / reposts / shares / comments (v7: only since the last reset, and only while learning is on)
     2. the people who did the same on the same posts (a like on a viral post counts less; accounts under 3 days old count less)
     3. what those people liked in the last 14 days, minus what you already did
   returns ['ids' => [post ids, best first], 'scores' => [post id => 0…1], 'neighbors' => n]. Remembered 10 minutes per session. */
if(!function_exists('dz_cf_candidates')){
    function dz_cf_candidates($conn, $user_id){
        $none = ['ids' => [], 'scores' => [], 'neighbors' => 0];
        $user_id = (int)$user_id;
        if($user_id <= 0) return $none;
        if(!dz_rank_learning_ok($conn, $user_id)) return $none;            /* v7: "Learn from my likes" is off → no taste is taken from their history */
        $forget = function_exists('dz_algo_forget_since') ? (string)dz_algo_forget_since($conn, $user_id) : '';
        $ck = $_SESSION['dz_cf'] ?? null;
        if(is_array($ck) && (int)($ck['u'] ?? 0) === $user_id && (string)($ck['f'] ?? '') === $forget && (time() - (int)($ck['t'] ?? 0)) < (int)DZ_CF_CACHE_SEC && isset($ck['d'])) return $ck['d'];
        $types = "'like','repost','favorite','share','comment'";
        $out = $none;
        try{
            $mine = [];
            $r = $conn->query("SELECT meme_id FROM user_interactions WHERE user_id = $user_id AND interaction_type IN ($types) AND created_at >= (NOW() - INTERVAL 45 DAY)"
                              . dz_rank_since($conn, $user_id, 'created_at')
                              . " GROUP BY meme_id ORDER BY MAX(created_at) DESC LIMIT 80");
            if($r) while($x = $r->fetch_row()) $mine[] = (int)$x[0];
            if(count($mine) >= (int)DZ_CF_MIN_ACTIONS){
                $trust = dz_trust_sql('nu', $conn);
                $nb = [];
                $r = $conn->query("
                    SELECT ui.user_id AS u, SUM((1.0 / LOG10(10 + COALESCE(ms.likes, 0))) * $trust) AS w, COUNT(*) AS c
                    FROM user_interactions ui
                    LEFT JOIN meme_stats ms ON ms.meme_id = ui.meme_id
                    JOIN users nu ON nu.id = ui.user_id AND nu.deactivated_at IS NULL
                    WHERE ui.meme_id IN (" . implode(',', $mine) . ")
                      AND ui.user_id <> $user_id AND ui.interaction_type IN ($types)
                      AND ui.created_at >= (NOW() - INTERVAL 90 DAY) AND COALESCE(ms.likes, 0) <= 3000
                    GROUP BY ui.user_id HAVING c >= 2
                    ORDER BY w DESC LIMIT " . (int)DZ_CF_NEIGHBORS);
                if($r) while($x = $r->fetch_assoc()) $nb[(int)$x['u']] = (float)$x['w'];
                if($nb){
                    $maxw = max($nb);
                    $seenMine = array_flip($mine);
                    $agg = [];
                    $r = $conn->query("SELECT DISTINCT meme_id, user_id FROM user_interactions
                                       WHERE user_id IN (" . implode(',', array_keys($nb)) . ") AND interaction_type IN ($types)
                                         AND created_at >= (NOW() - INTERVAL " . (int)DZ_CF_DAYS . " DAY) LIMIT 8000");
                    if($r) while($x = $r->fetch_row()){
                        $m = (int)$x[0]; $u = (int)$x[1];
                        if(isset($seenMine[$m]) || !isset($nb[$u])) continue;
                        $agg[$m] = ($agg[$m] ?? 0.0) + $nb[$u] / $maxw;
                    }
                    if($agg){
                        arsort($agg);
                        $agg = array_slice($agg, 0, (int)DZ_CF_POOL, true);
                        $top = (float)reset($agg);
                        foreach($agg as $m => $v){ if($v >= 0.15){ $out['ids'][] = (int)$m; $out['scores'][(int)$m] = round($v / $top, 3); } }
                    }
                    $out['neighbors'] = count($nb);
                }
            }
        }catch(Throwable $e){ $out = $none; }
        if(session_status() === PHP_SESSION_ACTIVE) $_SESSION['dz_cf'] = ['u' => $user_id, 'f' => $forget, 't' => time(), 'd' => $out];
        return $out;
    }
}
/* a new person: very few positive actions and almost no known topics
   (v7: actions before "Reset what my feed has learned" do not count, so a reset puts the person back on cold start) */
if(!function_exists('dz_is_cold')){
    function dz_is_cold($conn, $user_id, $knownTopics){
        $user_id = (int)$user_id;
        if($user_id <= 0 || (int)$knownTopics >= 3) return false;
        try{
            $r = $conn->query("SELECT COUNT(*) FROM user_interactions WHERE user_id = $user_id AND interaction_type IN ('like','repost','favorite','share','comment') AND created_at >= (NOW() - INTERVAL 90 DAY)"
                              . dz_rank_since($conn, $user_id, 'created_at'));
            if($r){ $row = $r->fetch_row(); return ((int)$row[0]) < (int)DZ_COLD_MIN_ACTIONS; }
        }catch(Throwable $e){}
        return false;
    }
}

/* ── 3. related topics ────────────────────────────────────────── */

/* learns, from the site's own recent posts, which topics appear together (cosine of the sets of posts that carry them).
   Stores the best 12 for a topic in dz_term_related. Returns [related => 0…1]. Looks at the last ~20000 posts. */
if(!function_exists('dz_build_related')){
    function dz_build_related($conn, $term){
        $term = (string)$term; $res = [];
        if($term === '' || strlen($term) > 60) return $res;
        try{
            dz_v6_ensure_tables($conn);
            $r = $conn->query("SELECT COALESCE(MAX(id), 0) FROM memes");
            $maxId = $r ? (int)($r->fetch_row()[0] ?? 0) : 0;
            $from = max(0, $maxId - 20000);
            $et = $conn->real_escape_string($term);
            foreach([['dz_meme_terms', 'term', ' AND {a}.w >= 40'], ['dz_meme_tags', 'tag', '']] as $cfg){
                list($tbl, $col, $cond) = $cfg;
                $c1 = str_replace('{a}', 't1', $cond); $c2 = str_replace('{a}', 't2', $cond); $c0 = str_replace('{a}', 'x', $cond);
                $r = $conn->query("SELECT COUNT(DISTINCT t1.meme_id) FROM `$tbl` t1 WHERE t1.`$col` = '$et' AND t1.meme_id >= $from$c1");
                $df1 = $r ? (int)($r->fetch_row()[0] ?? 0) : 0;
                if($df1 < 3) continue;
                $co = [];
                $r = $conn->query("
                    SELECT t2.`$col` AS rt, COUNT(DISTINCT t2.meme_id) AS c
                    FROM `$tbl` t1 JOIN `$tbl` t2 ON t2.meme_id = t1.meme_id AND t2.`$col` <> t1.`$col`$c2
                    WHERE t1.`$col` = '$et' AND t1.meme_id >= $from$c1
                    GROUP BY t2.`$col` HAVING c >= 2 ORDER BY c DESC LIMIT 60");
                if($r) while($x = $r->fetch_assoc()) $co[strtolower((string)$x['rt'])] = (int)$x['c'];
                if(!$co) continue;
                $in = [];
                foreach(array_keys($co) as $k){ $in[] = "'" . $conn->real_escape_string($k) . "'"; }
                $df2 = [];
                $r = $conn->query("SELECT x.`$col` AS rt, COUNT(DISTINCT x.meme_id) AS d FROM `$tbl` x WHERE x.`$col` IN (" . implode(',', $in) . ") AND x.meme_id >= $from$c0 GROUP BY x.`$col`");
                if($r) while($x = $r->fetch_assoc()) $df2[strtolower((string)$x['rt'])] = (int)$x['d'];
                foreach($co as $k => $c){
                    $k = dz_norm_term($k);
                    if($k === '' || $k === $term || strlen($k) > 60) continue;
                    $d2 = max(1, (int)($df2[$k] ?? $df2[strtolower($k)] ?? $c));
                    $score = $c / sqrt($df1 * $d2);
                    if($score >= 0.12 && (!isset($res[$k]) || $score > $res[$k])) $res[$k] = round(min(1.0, $score), 3);
                }
                if($res) break;      /* the terms table was enough; only fall back to hashtags when it found nothing */
            }
            arsort($res); $res = array_slice($res, 0, 12, true);
            $conn->query("DELETE FROM dz_term_related WHERE term = '$et'");
            if($res){
                $vals = [];
                foreach($res as $k => $s){ $vals[] = "('$et', '" . $conn->real_escape_string($k) . "', " . (float)$s . ", NOW())"; }
                $conn->query("INSERT INTO dz_term_related (term, related, score, updated_at) VALUES " . implode(',', $vals));
            } else {
                $conn->query("INSERT INTO dz_term_related (term, related, score, updated_at) VALUES ('$et', '', 0, NOW())");   /* "nothing found": do not recompute for a week */
            }
        }catch(Throwable $e){}
        return $res;
    }
}
/* [term => [related => 0…1]] for the given (normalised) topics. Topics never computed before are built now (at most DZ_RELATED_BUILD_MAX per call). */
if(!function_exists('dz_related_terms')){
    function dz_related_terms($conn, $terms){
        $out = [];
        if((float)DZ_RELATED_W <= 0) return $out;
        $clean = [];
        foreach($terms as $t){ $t = (string)$t; if($t !== '' && strlen($t) <= 60 && $t[0] !== '_') $clean[$t] = $t; }
        $clean = array_slice(array_values($clean), 0, 12);
        if(!$clean) return $out;
        try{
            dz_v6_ensure_tables($conn);
            $in = [];
            foreach($clean as $t){ $in[] = "'" . $conn->real_escape_string($t) . "'"; }
            $fresh = [];
            $r = $conn->query("SELECT term, related, score FROM dz_term_related WHERE term IN (" . implode(',', $in) . ") AND updated_at >= (NOW() - INTERVAL " . (int)DZ_RELATED_DAYS . " DAY) ORDER BY score DESC");
            if($r) while($x = $r->fetch_assoc()){
                $fresh[(string)$x['term']] = true;
                if((string)$x['related'] !== '' && (float)$x['score'] > 0) $out[(string)$x['term']][(string)$x['related']] = (float)$x['score'];
            }
            $built = 0;
            foreach($clean as $t){
                if(isset($fresh[$t])) continue;
                if($built >= (int)DZ_RELATED_BUILD_MAX) break;
                $built++;
                $rel = dz_build_related($conn, $t);
                if($rel) $out[$t] = $rel;
            }
        }catch(Throwable $e){}
        return $out;
    }
}

/* ── 5. language ──────────────────────────────────────────────── */

/* a light language guess from a caption: script first (Arabic, Cyrillic, Chinese, Japanese, Korean, Hindi, Thai, Hebrew, Greek),
   then common-word counts for en, fr, es, pt, de, it, id, tr, nl and Nigerian Pidgin (pcm).
   Returns the code, or 'und' (unknown) when the text is too short or unclear — those posts are never penalised. */
if(!function_exists('dz_detect_lang')){
    function dz_detect_lang($text){
        static $sw = null;
        if($sw === null){
            $lists = [
                'en'  => 'the and you that this with for are was have not but what all when your just like they from will can out about how',
                'fr'  => 'le la les des est une pour que qui dans pas sur avec vous nous mais sont cette être très',
                'es'  => 'el los las una por para con que del pero como más esta son muy tu yo',
                'pt'  => 'não uma para com que mas são você isso está muito dos das meu ele',
                'de'  => 'der die das und ist nicht ein eine mit für auf ich du wir sie auch',
                'it'  => 'il che per non una con sono gli della più questo anche',
                'id'  => 'yang dan di ini itu untuk dengan tidak aku kamu saya ada juga',
                'tr'  => 'bir ve bu için ama çok ben sen ne da',
                'nl'  => 'de het een en van niet met dat ik je voor maar',
                'pcm' => 'dey wetin abeg wahala sabi pikin una oga comot wey naija abi shey jare gbas nau sha biko',
            ];
            $sw = [];
            foreach($lists as $code => $words){ $sw[$code] = array_flip(explode(' ', $words)); }
        }
        $t = preg_replace('~https?://\S+|www\.\S+|[#@][\p{L}\p{N}_]+~u', ' ', (string)$text);
        if($t === null) return 'und';
        $t = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        $nl = (int)preg_match_all('/\p{L}/u', $t);
        if($nl < 12) return 'und';
        if(preg_match_all('/[\p{Hiragana}\p{Katakana}]/u', $t) >= 0.15 * $nl) return 'ja';
        foreach(['ar' => '/\p{Arabic}/u', 'ru' => '/\p{Cyrillic}/u', 'zh' => '/\p{Han}/u', 'ko' => '/\p{Hangul}/u', 'hi' => '/\p{Devanagari}/u', 'th' => '/\p{Thai}/u', 'he' => '/\p{Hebrew}/u', 'el' => '/\p{Greek}/u'] as $code => $re){
            if(preg_match_all($re, $t) >= 0.5 * $nl) return $code;
        }
        $tokens = preg_split('/[^\p{L}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
        if(count($tokens) < 4) return 'und';
        $hits = [];
        foreach($sw as $code => $set){ $h = 0; foreach($tokens as $w){ if(isset($set[$w])) $h++; } $hits[$code] = $h; }
        arsort($hits);
        $codes = array_keys($hits);
        $best = $hits[$codes[0]]; $second = $hits[$codes[1]] ?? 0;
        if($best < 2 || $best < 1.5 * $second) return 'und';
        return $codes[0];
    }
}
/* is a post language one the person reads? (English and Pidgin count as the same family) */
if(!function_exists('dz_lang_ok')){
    function dz_lang_ok($code, $langs){
        static $fam = ['en' => 'en', 'pcm' => 'en'];
        $k = $fam[$code] ?? $code;
        foreach($langs as $l => $share){ if(($fam[$l] ?? $l) === $k) return true; }
        return false;
    }
}
/* the languages a person reads: ['langs' => [code => share], 'strong' => bool]
   strong = learned from ≥5 posts they liked / saved / reposted / shared / commented on in the last 90 days (a language counts at ≥12% of those)
   weak   = only their browser language (Accept-Language) — penalties are lighter. Remembered 1 hour per session.
   v7: the learned part is skipped when "Learn from my likes" is off, and ignores interactions before the last reset. */
if(!function_exists('dz_viewer_langs')){
    function dz_viewer_langs($conn, $user_id){
        $none = ['langs' => [], 'strong' => false];
        $user_id = (int)$user_id;
        if($user_id <= 0) return $none;
        $learn  = dz_rank_learning_ok($conn, $user_id);
        $forget = function_exists('dz_algo_forget_since') ? (string)dz_algo_forget_since($conn, $user_id) : '';
        $ck = $_SESSION['dz_vlang'] ?? null;
        if(is_array($ck) && (int)($ck['u'] ?? 0) === $user_id && (string)($ck['f'] ?? '') === $forget && (int)($ck['l'] ?? 1) === ($learn ? 1 : 0)
           && (time() - (int)($ck['t'] ?? 0)) < 3600 && isset($ck['d'])) return $ck['d'];
        $count = []; $n = 0;
        if($learn){
            try{
                $r = $conn->query("SELECT m.caption FROM user_interactions ui JOIN memes m ON m.id = ui.meme_id
                                   WHERE ui.user_id = $user_id AND ui.interaction_type IN ('like','repost','favorite','share','comment')
                                     AND ui.created_at >= (NOW() - INTERVAL 90 DAY)" . dz_rank_since($conn, $user_id, 'ui.created_at') . "
                                   ORDER BY ui.created_at DESC LIMIT 150");
                if($r) while($x = $r->fetch_row()){
                    $c = dz_detect_lang((string)$x[0]);
                    if($c === 'und') continue;
                    $count[$c] = ($count[$c] ?? 0) + 1; $n++;
                }
            }catch(Throwable $e){}
        }
        $out = $none;
        if($n >= 5){
            foreach($count as $c => $v){ if($v / $n >= (float)DZ_LANG_MIN_SHARE) $out['langs'][$c] = round($v / $n, 3); }
            $out['strong'] = !empty($out['langs']);
        }
        if(!$out['langs']){
            $hdr = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
            $i = 0;
            foreach(explode(',', $hdr) as $part){
                if(++$i > 3) break;
                $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
                if(preg_match('/^[a-z]{2}$/', $code)) $out['langs'][$code] = 1.0;
            }
            foreach($count as $c => $v){ $out['langs'][$c] = $out['langs'][$c] ?? round($v / max(1, $n), 3); }
            $out['strong'] = false;
        }
        if(session_status() === PHP_SESSION_ACTIVE) $_SESSION['dz_vlang'] = ['u' => $user_id, 'f' => $forget, 'l' => $learn ? 1 : 0, 't' => time(), 'd' => $out];
        return $out;
    }
}
/* [post id => language code] for the feed rows. Languages are guessed once and stored in dz_meme_lang. */
if(!function_exists('dz_post_langs')){
    function dz_post_langs($conn, $rows){
        $out = [];
        if(!$rows) return $out;
        try{
            dz_v6_ensure_tables($conn);
            $ids = []; $cap = [];
            foreach($rows as $r){ $id = (int)$r['id']; $ids[$id] = $id; $cap[$id] = (string)($r['caption'] ?? ''); }
            $r = $conn->query("SELECT meme_id, lang FROM dz_meme_lang WHERE meme_id IN (" . implode(',', array_keys($ids)) . ")");
            if($r) while($x = $r->fetch_row()) $out[(int)$x[0]] = (string)$x[1];
            $new = [];
            foreach($ids as $id){
                if(isset($out[$id])) continue;
                $lang = dz_detect_lang($cap[$id]);
                $out[$id] = $lang;
                $new[] = "(" . (int)$id . ", '" . $conn->real_escape_string($lang) . "', NOW())";
            }
            for($i = 0; $i < count($new); $i += 400){
                $conn->query("INSERT INTO dz_meme_lang (meme_id, lang, updated_at) VALUES " . implode(',', array_slice($new, $i, 400)) . " ON DUPLICATE KEY UPDATE lang = VALUES(lang), updated_at = VALUES(updated_at)");
            }
        }catch(Throwable $e){}
        return $out;
    }
}

/* which algorithm a person gets: 'B' = current, 'A' = the v4 behaviour (only differs when DZ_AB_TEST is on) */
if(!function_exists('dz_algo_variant')){
    function dz_algo_variant($user_id){
        if(!DZ_AB_TEST) return 'B';
        return (((int)$user_id) % 2 === 0) ? 'A' : 'B';
    }
}

/* how much one action says about taste (negative = "less of this").
   'comment' and 'follow' are new: call recordInteraction($conn, $uid, $meme_id, 'comment') when someone comments,
   and 'follow' when someone follows from a post. */
if(!function_exists('dz_affinity_weight')){
    function dz_affinity_weight($type){
        static $w = ['repost'=>6.0, 'favorite'=>4.0, 'share'=>3.0, 'comment'=>3.0, 'follow'=>3.0, 'like'=>2.0, 'interested'=>2.0, 'view'=>0.5, 'not_interested'=>-3.0, 'hide'=>-3.0];
        return $w[$type] ?? 1.0;
    }
}

/* v5: one topic word → its normal form: lower-case, plural removed, synonyms applied ("Glutes" → "glute") */
if(!function_exists('dz_norm_term')){
    function dz_norm_term($t){
        $n = strtolower(trim((string)$t));
        if($n === '') return '';
        $syn = DZ_SYNONYMS;
        if(isset($syn[$n])) return (string)$syn[$n];
        $len = strlen($n);
        if($len > 4 && substr($n, -3) === 'ies')                          $n = substr($n, 0, -3) . 'y';
        elseif($len > 4 && preg_match('/(ss|x|ch|sh)es$/', $n))           $n = substr($n, 0, -2);
        elseif($len > 3 && substr($n, -1) === 's' && substr($n, -2) !== 'ss') $n = substr($n, 0, -1);
        return isset($syn[$n]) ? (string)$syn[$n] : $n;
    }
}
/* every spelling a normalised topic might have in the database (used to build the SQL lookups) */
if(!function_exists('dz_term_variants')){
    function dz_term_variants($n){
        $n = (string)$n;
        if($n === '') return [];
        $o = [$n, $n . 's', $n . 'es'];
        if(strlen($n) > 2 && substr($n, -1) === 'y') $o[] = substr($n, 0, -1) . 'ies';
        foreach(DZ_SYNONYMS as $from => $to){ if((string)$to === $n) $o[] = (string)$from; }
        return array_values(array_unique($o));
    }
}
/* tag => score map → the same map with normalised keys (scores of words that normalise to the same topic are added up) */
if(!function_exists('dz_norm_affinity')){
    function dz_norm_affinity($tags){
        $o = [];
        foreach($tags as $k => $v){
            $n = dz_norm_term((string)$k);
            if($n === '') continue;
            $o[$n] = ($o[$n] ?? 0.0) + (float)$v;
        }
        return $o;
    }
}

/* Sensitive content level → multiplier on a post's score, from its 0-100 "sensitive" score.
     Less (0)     strong: clearly sensitive posts drop to almost nothing
     Standard (1) gentle: only the most sensitive posts are held back a little
     More (2)     no hold-back */
if(!function_exists('dz_sensitive_mult')){
    function dz_sensitive_mult($score, $level){
        $s = max(0, min(100, (int)$score)); $level = (int)$level;
        if($level >= 2) return 1.0;
        if($level === 1) return 1.0 - 0.5 * max(0.0, ($s - 50) / 50.0);
        return max(0.05, 1.0 - 1.1 * max(0.0, ($s - 20) / 80.0));
    }
}
/* Political content level → multiplier, from the post's 0-100 "political" score.
     Less (0)     strong: political posts drop a lot
     Standard (1) no change: politics is a topic like any other
     More (2)     a small lift for political posts */
if(!function_exists('dz_political_mult')){
    function dz_political_mult($score, $level){
        $p = max(0, min(100, (int)$score)); $level = (int)$level;
        if($level === 1) return 1.0;
        if($level >= 2) return 1.0 + 0.3 * ($p / 100.0);
        return max(0.1, 1.0 - 1.0 * max(0.0, ($p - 15) / 85.0));
    }
}

/* add (or, with a negative weight, remove) a taste signal for this person: one row each for the post's tags, its creator and its type
   (v4: plus the topics the AI saw in the media).
   Old signal fades lazily: score = old × e^(−λ·hours) + new. Tags fade with a ~7-day half-life, creators/types ~14 days.
   Does nothing when the person switched "Learn from my activity" off. */
/* add (or, with a negative weight, remove) a taste signal for this person: one row each for the post's tags, its creator and its type
   (v4: plus the topics the AI saw in the media).
   Old signal fades lazily: score = old × e^(−λ·hours) + new. Tags fade with a ~7-day half-life, creators/types ~14 days.
   Does nothing when the person switched "Learn from my activity" off.
   v8 — "Not interested" ($ni, detected automatically for weights ≤ −2.5) is deliberately gentle on everything but the post's topics:
        first 3 tags 100%, further tags 50%, media topics 60% (max 4), creator 50%, format 15%.
        One post can lower similar posts, but never condemns a creator or a format. Learned taste is floored at DZ_NEG_FLOOR.
        Un-hiding calls dz_affinity_bump($c, $u, $meme, +3.0, true, true) to give exactly that back. */
if(!function_exists('dz_affinity_bump')){
    function dz_affinity_bump($conn, $user_id, $meme_id, $weight, $removal = false, $ni = false){
        $user_id = (int)$user_id; $meme_id = (int)$meme_id; $weight = (float)$weight;
        if($user_id <= 0 || $meme_id <= 0 || $weight == 0.0) return;
        if(function_exists('dz_algo_learning_off') && dz_algo_learning_off($conn, $user_id)) return;
        if(!$ni && !$removal && $weight <= -2.5) $ni = true;
        try{
            $r = $conn->query("SELECT uploaded_by, type FROM memes WHERE id = $meme_id LIMIT 1");
            $m = $r ? $r->fetch_assoc() : null;
            if(!$m) return;

            $items = [];   /* [kind, item, decay per hour, share of the weight] */
            $creator = (int)$m['uploaded_by'];
            if($creator > 0 && $creator !== $user_id) $items[] = ['creator', (string)$creator, 0.002, $ni ? 0.5 : 1.0];
            $type = strtolower(trim((string)($m['type'] ?? '')));
            if($type !== '') $items[] = ['type', substr($type, 0, 60), 0.002, $ni ? 0.15 : 1.0];
            $seenTag = []; $nTag = 0;
            $tr = $conn->query("SELECT tag FROM dz_meme_tags WHERE meme_id = $meme_id");
            if($tr) while($t = $tr->fetch_assoc()){
                $k = strtolower((string)$t['tag']);
                if($k === '' || isset($seenTag[$k])) continue;
                $seenTag[$k] = 1;
                $items[] = ['tag', $k, 0.004, ($ni && $nTag >= 3) ? 0.5 : 1.0];
                $nTag++;
            }
            /* v4: what the media SHOWS (the AI's topics) also teaches taste, so liking a post of glutes counts as interest in "glute" */
            $nm = (int)DZ_LEARN_MEDIA_TERMS;
            if($ni) $nm = min($nm, 4);
            if($nm > 0){
                try{
                    $ar = $conn->query("SELECT term FROM dz_meme_terms WHERE meme_id = $meme_id AND src = 'ai' ORDER BY w DESC LIMIT $nm");
                    if($ar) while($t = $ar->fetch_assoc()){
                        $k = strtolower((string)$t['term']);
                        if($k === '' || isset($seenTag[$k])) continue;
                        $seenTag[$k] = 1; $items[] = ['tag', $k, 0.004, $ni ? 0.6 : 1.0];
                    }
                }catch(Throwable $e){}
            }
            if(!$items) return;

            if($removal){
                /* taking a like / save / "not interested" back: reduce the taste signal, but never push it past zero the other way
                   (v8: the decay rate used to be bound as an integer here, which made it 0 — fixed) */
                $stmt = $conn->prepare("
                    UPDATE dz_user_affinity
                    SET score      = GREATEST(score * EXP(-? * TIMESTAMPDIFF(HOUR, updated_at, NOW())) + ?,
                                              LEAST(score * EXP(-? * TIMESTAMPDIFF(HOUR, updated_at, NOW())), 0)),
                        updated_at = NOW()
                    WHERE user_id = ? AND kind = ? AND item = ?
                ");
                foreach($items as $it){
                    $kind = $it[0]; $item = $it[1]; $lam = (float)$it[2]; $w = $weight * (float)$it[3];
                    $stmt->bind_param("dddiss", $lam, $w, $lam, $user_id, $kind, $item);
                    $stmt->execute();
                }
                $stmt->close();
                return;
            }
            $floor = (float)DZ_NEG_FLOOR;
            $stmt = $conn->prepare("
                INSERT INTO dz_user_affinity (user_id, kind, item, score, updated_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    score      = GREATEST($floor, score * EXP(-? * TIMESTAMPDIFF(HOUR, updated_at, NOW())) + VALUES(score)),
                    updated_at = NOW()
            ");
            foreach($items as $it){
                $kind = $it[0]; $item = $it[1]; $lam = (float)$it[2]; $w = $weight * (float)$it[3];
                $stmt->bind_param("issdd", $user_id, $kind, $item, $w, $lam);
                $stmt->execute();
            }
            $stmt->close();
        }catch(Throwable $e){}
    }
}

/* this person's current tastes (faded to "now"): ['tag'=>[tag=>score], 'creator'=>[userId=>score], 'type'=>[type=>score]]
   positive = likes it, negative = wants less of it. One indexed query.
   The topics / creators / formats they picked on the "Your Algorithm" page are merged in at the end. */
if(!function_exists('dz_load_affinity')){
    function dz_load_affinity($conn, $user_id){
        $out = ['tag' => [], 'creator' => [], 'type' => []];
        $user_id = (int)$user_id;
        if($user_id <= 0) return $out;
        try{
            $stmt = $conn->prepare("
                SELECT kind, item,
                       score * EXP(-(CASE kind WHEN 'tag' THEN 0.004 ELSE 0.002 END) * TIMESTAMPDIFF(HOUR, updated_at, NOW())) AS eff
                FROM dz_user_affinity
                WHERE user_id = ?
                HAVING ABS(eff) > 0.3
                ORDER BY ABS(eff) DESC
                LIMIT 300
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while($r = $res->fetch_assoc()){
                if(isset($out[$r['kind']])) $out[$r['kind']][(string)$r['item']] = (float)$r['eff'];
            }
            $stmt->close();
        }catch(Throwable $e){}
        return function_exists('dz_algo_merge_prefs') ? dz_algo_merge_prefs($conn, $user_id, $out) : $out;
    }
}

/* v5: how long a post must be on screen to count as a skip / linger / deep linger, depending on what it is.
   returns [skipMaxMs, lingerMs, deepMs]
     image / gif / sticker : the defaults above
     video                 : looked-and-left < 1.5 s, linger 5 s, deep 15 s
     text                  : reading time (≈ 4 words a second) — linger at 60% of it, deep at all of it (never below the defaults) */
if(!function_exists('dz_dwell_thresholds')){
    function dz_dwell_thresholds($type, $caption){
        $type = strtolower(trim((string)$type));
        $skipMax = (int)DZ_SKIP_MAX_MS; $ling = (int)DZ_LINGER_MS; $deep = (int)DZ_DEEP_MS;
        if($type === 'video'){
            return [1500, 5000, 15000];
        }
        if(!in_array($type, ['image', 'gif', 'sticker', 'stickers'], true)){
            $words = str_word_count(strip_tags((string)$caption));
            if($words < 1){ $words = count(preg_split('/\s+/u', trim((string)$caption), -1, PREG_SPLIT_NO_EMPTY)); }
            $read  = (int)round($words / 4.0 * 1000);
            $ling  = min(20000, max($ling, (int)round($read * 0.6)));
            $deep  = min(40000, max($deep, $read));
            if($deep <= $ling) $deep = $ling + 2000;
        }
        return [$skipMax, $ling, $deep];
    }
}

/* the page reports how long posts were on screen: $items = [meme_id => milliseconds].
   skip   (short look, and you did not like / save / repost / share / comment on it) → row marked skipped, post's skip count +1, taste −0.4
   linger (long look)                                                                → row marked lingered (once), post's linger count +1, taste +0.4 / +0.8
   "short" and "long" depend on the post type (dz_dwell_thresholds).
   Ads and your own posts are ignored. Ignored completely when the person switched "Learn from time spent" off. */
if(!function_exists('dz_record_dwell')){
    function dz_record_dwell($conn, $user_id, $items){
        $user_id = (int)$user_id;
        if($user_id <= 0 || !$items) return;
        if(function_exists('dz_algo_use_watch_time') && !dz_algo_use_watch_time($conn, $user_id)) return;
        try{
            $ids = array_map('intval', array_keys($items));
            $list = implode(',', $ids);

            $meta = [];
            $r = $conn->query("SELECT id, uploaded_by, is_ad, type, caption FROM memes WHERE id IN ($list)");
            if($r) while($x = $r->fetch_assoc()) $meta[(int)$x['id']] = $x;

            $engaged = [];   /* posts this person liked / reposted / saved / shared / commented on — never counted as skipped */
            $r = $conn->query("SELECT DISTINCT meme_id FROM user_interactions WHERE user_id = $user_id AND meme_id IN ($list) AND interaction_type IN ('like','repost','favorite','share','comment')");
            if($r) while($x = $r->fetch_assoc()) $engaged[(int)$x['meme_id']] = 1;

            $ins    = $conn->prepare("INSERT IGNORE INTO dz_feed_impressions (user_id, meme_id, seen_count, first_seen, last_seen) VALUES (?, ?, 1, NOW(), NOW())");
            $viewer = $conn->prepare("INSERT INTO meme_stats (meme_id, impressions) VALUES (?, 1) ON DUPLICATE KEY UPDATE impressions = impressions + 1");
            $sel    = $conn->prepare("SELECT skip_count, lingered FROM dz_feed_impressions WHERE user_id = ? AND meme_id = ?");
            $upd    = $conn->prepare("UPDATE dz_feed_impressions SET dwell_ms = dwell_ms + ?, skip_count = skip_count + ?, lingered = GREATEST(lingered, ?), last_seen = NOW() WHERE user_id = ? AND meme_id = ?");
            $skipC  = $conn->prepare("INSERT INTO meme_stats (meme_id, skips) VALUES (?, 1) ON DUPLICATE KEY UPDATE skips = skips + 1");
            $lingC  = $conn->prepare("INSERT INTO meme_stats (meme_id, lingers) VALUES (?, 1) ON DUPLICATE KEY UPDATE lingers = lingers + 1");

            foreach($items as $mid => $ms){
                $mid = (int)$mid; $ms = max(0, min(60000, (int)$ms));
                if($ms < DZ_SKIP_MIN_MS) continue;
                if(!isset($meta[$mid]) || !empty($meta[$mid]['is_ad']) || (int)$meta[$mid]['uploaded_by'] === $user_id) continue;

                list($skipMax, $lingMs, $deepMs) = dz_dwell_thresholds($meta[$mid]['type'] ?? '', $meta[$mid]['caption'] ?? '');

                if($ms < $skipMax)              $kind = isset($engaged[$mid]) ? 'neutral' : 'skip';
                elseif($ms >= $deepMs)          $kind = 'deep';
                elseif($ms >= $lingMs)          $kind = 'linger';
                else                            $kind = 'neutral';
                if($kind === 'neutral') continue;

                $ins->bind_param("ii", $user_id, $mid); $ins->execute();
                if($ins->affected_rows === 1){ $viewer->bind_param("i", $mid); $viewer->execute(); }   /* a skip is also one more viewer */

                $sel->bind_param("ii", $user_id, $mid); $sel->execute();
                $row = $sel->get_result()->fetch_assoc();
                $prevSkips = (int)($row['skip_count'] ?? 0); $prevLinger = (int)($row['lingered'] ?? 0);

                if($kind === 'skip'){
                    $a = 1; $b = 0;
                    $upd->bind_param("iiiii", $ms, $a, $b, $user_id, $mid); $upd->execute();
                    if($prevSkips === 0){ $skipC->bind_param("i", $mid); $skipC->execute(); }
                    dz_affinity_bump($conn, $user_id, $mid, DZ_SKIP_WEIGHT);
                } else {
                    $a = 0; $b = 1;
                    $upd->bind_param("iiiii", $ms, $a, $b, $user_id, $mid); $upd->execute();
                    if($prevLinger === 0){                                   /* the taste bonus and the post's linger count happen once per person per post */
                        $lingC->bind_param("i", $mid); $lingC->execute();
                        dz_affinity_bump($conn, $user_id, $mid, $kind === 'deep' ? DZ_DEEP_WEIGHT : DZ_LINGER_WEIGHT);
                    }
                }
            }
        }catch(Throwable $e){}
    }
}

/* weighted engagement of one feed row (same weights the old SQL formula used) */
if(!function_exists('dz_engagement')){
    function dz_engagement($r){
        return ((int)$r['like_count'] * 4.0) + ((int)$r['repost_count'] * 6.0) + ((int)$r['fav_count'] * 3.0)
             + ((int)$r['share_count'] * 5.0) + (log10(((int)($r['views'] ?? 0)) + 1) * 2.0);
    }
}

/* v5: what this person touched in the last N minutes: [normalised topic => weight].
   Topics = the hashtags of posts they liked / reposted / saved / shared / commented on, plus the topics the AI saw in those posts.
   v7: empty when "Learn from my likes" is off; interactions before the last reset are ignored. */
if(!function_exists('dz_session_topics')){
    function dz_session_topics($conn, $user_id){
        $out = [];
        $user_id = (int)$user_id;
        if($user_id <= 0) return $out;
        if(!dz_rank_learning_ok($conn, $user_id)) return $out;
        $min = max(1, (int)DZ_SESSION_MIN);
        $w   = "CASE ui.interaction_type WHEN 'repost' THEN 6 WHEN 'favorite' THEN 4 WHEN 'share' THEN 3 WHEN 'comment' THEN 3 WHEN 'like' THEN 2 ELSE 0 END";
        $where = "ui.user_id = $user_id AND ui.created_at >= (NOW() - INTERVAL $min MINUTE) AND ui.interaction_type IN ('like','repost','favorite','share','comment')"
               . dz_rank_since($conn, $user_id, 'ui.created_at');
        try{
            $res = $conn->query("SELECT mt.tag AS t, SUM($w) AS w FROM user_interactions ui JOIN dz_meme_tags mt ON mt.meme_id = ui.meme_id WHERE $where GROUP BY mt.tag");
            if($res) while($r = $res->fetch_assoc()){
                $n = dz_norm_term((string)$r['t']);
                if($n !== '') $out[$n] = ($out[$n] ?? 0.0) + (float)$r['w'];
            }
        }catch(Throwable $e){}
        try{
            $res = $conn->query("SELECT mt.term AS t, SUM($w) AS w FROM user_interactions ui JOIN dz_meme_terms mt ON mt.meme_id = ui.meme_id AND mt.src = 'ai' AND mt.w >= 30 WHERE $where GROUP BY mt.term");
            if($res) while($r = $res->fetch_assoc()){
                $n = dz_norm_term((string)$r['t']);
                if($n !== '') $out[$n] = ($out[$n] ?? 0.0) + (float)$r['w'];
            }
        }catch(Throwable $e){}
        return $out;
    }
}

/* v5: records which posts were served, in which position, to which variant (the "what did we show" half of measurement;
   what happened next is in dz_feed_impressions + user_interactions).  $items = [[meme_id, position], …]
   The table is created automatically. Old rows are deleted now and then. */
if(!function_exists('dz_log_serves')){
    function dz_log_serves($conn, $user_id, $items, $page, $variant, $seed){
        $user_id = (int)$user_id;
        if(!DZ_LOG_SERVES || $user_id <= 0 || !$items) return;
        try{
            if(empty($_SESSION['dz_serves_ready'])){
                $conn->query("CREATE TABLE IF NOT EXISTS dz_feed_serves (
                    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    user_id   INT UNSIGNED NOT NULL,
                    meme_id   INT UNSIGNED NOT NULL,
                    pos       SMALLINT UNSIGNED NOT NULL,
                    page      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                    variant   CHAR(1) NOT NULL DEFAULT 'B',
                    seed      INT UNSIGNED NOT NULL DEFAULT 0,
                    served_at DATETIME NOT NULL,
                    KEY k_user_time (user_id, served_at),
                    KEY k_meme (meme_id),
                    KEY k_time (served_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $_SESSION['dz_serves_ready'] = 1;
            }
            $v = ($variant === 'A') ? 'A' : 'B';
            $vals = [];
            foreach(array_slice($items, 0, 300) as $it){
                $vals[] = sprintf("(%d,%d,%d,%d,'%s',%d,NOW())", $user_id, (int)$it[0], max(0, min(65000, (int)$it[1])), max(1, (int)$page), $v, max(0, (int)$seed));
            }
            if($vals) $conn->query("INSERT INTO dz_feed_serves (user_id, meme_id, pos, page, variant, seed, served_at) VALUES " . implode(',', $vals));
            if(mt_rand(1, 300) === 1) $conn->query("DELETE FROM dz_feed_serves WHERE served_at < (NOW() - INTERVAL " . (int)DZ_SERVES_KEEP_DAYS . " DAY) LIMIT 5000");
        }catch(Throwable $e){}
    }
}

/* ────────────────────────────────────────────────────────────────
   THE RANKER
   $rows       candidate rows (from the feed query, with impression_count, age_sec, seen_first_sec, seen_last_sec, seen_count)
   $aff        dz_load_affinity() result
   $social_map meme_id => [people you follow who liked / reposted it]
   $seed       the feed's shuffle seed (same seed = same order on every page)
   $sess_sec   seconds since this feed session started (keeps page 1, 2, 3 consistent)
   returns the rows, best first

   The viewer's "Your Algorithm" dials are read from the global $user_id (set by shared_core.php); without it every dial sits in the middle.
   Variant B (default) = v5 behaviour. Variant A (only when DZ_AB_TEST is on) = the v4 behaviour, for comparison.
──────────────────────────────────────────────────────────────── */
if(!function_exists('dz_rank_feed')){
    function dz_rank_feed($conn, $rows, $aff, $social_map, $seed, $sess_sec){
        if(!$rows) return [];
        $seed = max(1, (int)$seed); $sess_sec = max(0, (int)$sess_sec);

        /* the person's dials */
        $st      = dz_algo_settings($conn, (int)($GLOBALS['user_id'] ?? 0));
        $adv     = max(0, min(100, (int)$st['adventure']));
        $relPow  = 1.5 - ($adv / 100.0);                                  /* how strongly taste counts: 1.5 (familiar) … 1.0 (balanced) … 0.5 (adventurous) */
        $folArr  = (array)DZ_FOLLOW_MULT;
        $folMult = (float)($folArr[(int)$st['follow_level']] ?? 1.25);    /* boost for people they track ("Less" = 0.85, a real reduction) */
        $novLvl  = (int)$st['novelty_level'];                             /* 0 let seen posts return · 1 balanced · 2 fresh only */
        $senLvl  = max(0, min(2, (int)($st['sensitive_level'] ?? 1)));    /* 0 less · 1 standard · 2 more */
        $polLvl  = max(0, min(2, (int)($st['political_level'] ?? 1)));
        $viewer  = (int)($GLOBALS['user_id'] ?? 0);
        $variant = dz_algo_variant($viewer);
        $B       = ($variant === 'B');
        $GLOBALS['dz_algo_variant'] = $variant;
        $cold    = $B && !empty($GLOBALS['dz_cold']);                       /* v6: a new person with little history (set by shared_core.php) */
        $cfMap   = $B ? (array)($GLOBALS['dz_cf_map'] ?? []) : [];          /* v6: post => 0…1 "people with your taste liked it" (set by shared_core.php) */

        $ids = array_map(function($r){ return (int)$r['id']; }, $rows);
        $idList = implode(',', $ids);
        $tagmap = [];
        try{
            $res = $conn->query("SELECT meme_id, tag FROM dz_meme_tags WHERE meme_id IN ($idList)");
            if($res) while($t = $res->fetch_assoc()) $tagmap[(int)$t['meme_id']][] = strtolower((string)$t['tag']);
        }catch(Throwable $e){}

        /* the taste map used for scoring: variant B adds up words that mean the same topic (glute + glutes + synonyms) */
        $affTag = $B ? dz_norm_affinity($aff['tag']) : $aff['tag'];
        /* v6: related topics learned from the site's own posts (topics that appear together): liking "squat" also warms up "leg day" and "gym".
           A related topic gets a share of the topic it comes from; it never overrides a "less of this". */
        $relAdded = [];
        if($B && (float)DZ_RELATED_W > 0 && $affTag){
            $posT = array_filter($affTag, function($v){ return $v > 0; });
            arsort($posT);
            $posT = array_slice($posT, 0, (int)DZ_RELATED_TOPICS, true);
            $relmap = dz_related_terms($conn, array_keys($posT));
            foreach($posT as $t => $sv){
                foreach(($relmap[$t] ?? []) as $rt => $q){
                    if(isset($affTag[$rt]) && $affTag[$rt] < 0) continue;
                    $val = $sv * $q * (float)DZ_RELATED_W;
                    if($val > ($affTag[$rt] ?? 0.0)){ $affTag[$rt] = $val; $relAdded[$rt] = 1; }
                }
            }
        }

        /* v8 (A + B): the person's own choices outrank everything learned.
           • "Less" topic: its score is set to the "less" value whatever was learned (also under other spellings), and topics that
             appear together with it are lowered too, so liking #soccer cannot sneak football back in
           • "More" topic: never below the full "more" value
           $lessW = [topic => 0…1] how strongly a topic counts as "a Less topic" (1 = picked, <1 = related to one) */
        $ex = function_exists('dz_algo_explicit_topics') ? dz_algo_explicit_topics($conn, $viewer) : ['more' => [], 'less' => []];
        foreach($ex['less'] as $t => $_x){ $affTag[$t] = -10.0; }
        foreach($ex['more'] as $t => $_x){ $affTag[$t] = max(20.0, (float)($affTag[$t] ?? 0.0)); }
        $lessW = [];
        foreach($ex['less'] as $t => $_x){ $lessW[$t] = 1.0; }
        if($B && $ex['less'] && (float)DZ_LESS_RELATED_W > 0){
            foreach(dz_related_terms($conn, array_keys($ex['less'])) as $lt => $list){
                foreach($list as $rt => $q){
                    if(isset($ex['more'][$rt]) || isset($ex['less'][$rt])) continue;
                    $cur = (float)($affTag[$rt] ?? 0.0);
                    if($cur > 0) $affTag[$rt] = $cur * max(0.3, 1.0 - 2.0 * (float)DZ_LESS_RELATED_W * $q);
                    else         $affTag[$rt] = min($cur, -10.0 * (float)DZ_LESS_RELATED_W * $q);
                    $lessW[$rt] = max((float)($lessW[$rt] ?? 0.0), 0.5 * $q);
                }
            }
        }

        /* v4: what each post is ABOUT beyond its hashtags — the words of its caption and what its media shows (dz_meme_terms).
           Only the terms this person has an opinion about are fetched, so the query stays small. $wordmap: meme_id => [term => 0…1 weight] */
        $wordmap = []; $keys = []; $kseen = [];
        $addKey = function($k) use (&$keys, &$kseen, $conn){
            $k = (string)$k;
            if($k === '' || $k[0] === '_' || isset($kseen[$k]) || count($keys) >= 400) return;
            $kseen[$k] = 1;
            $keys[] = "'" . $conn->real_escape_string($k) . "'";
        };
        foreach($aff['tag'] as $k => $v){
            $addKey($k);
            if($B){ foreach(dz_term_variants(dz_norm_term($k)) as $vv) $addKey($vv); }
        }
        foreach(array_keys($relAdded) as $rt){ foreach(dz_term_variants($rt) as $vv) $addKey($vv); }
        foreach(array_keys($lessW) as $rt){ foreach(dz_term_variants($rt) as $vv) $addKey($vv); }
        if($keys){
            try{
                $res = $conn->query("SELECT meme_id, term, src, w FROM dz_meme_terms WHERE term IN (" . implode(',', $keys) . ") AND meme_id IN ($idList)");
                if($res) while($t = $res->fetch_assoc()){
                    $wt = ((int)$t['w'] / 100.0) * (($t['src'] === 'ai') ? 1.0 : (float)DZ_CAPTION_WORD_W);
                    $tk = $B ? dz_norm_term((string)$t['term']) : strtolower((string)$t['term']);
                    $m2 = (int)$t['meme_id'];
                    if($tk === '') continue;
                    if(!isset($wordmap[$m2][$tk]) || $wt > $wordmap[$m2][$tk]) $wordmap[$m2][$tk] = $wt;
                }
            }catch(Throwable $e){}
        }

        /* v4: the media-analysis scores (0-100) used by the Sensitive / Political levels. Posts not analysed yet count as 0. */
        $content = [];
        try{
            $res = $conn->query("SELECT meme_id, `sensitive` AS sens, political AS pol, error FROM dz_media_analysis WHERE meme_id IN ($idList)");
            if($res) while($c = $res->fetch_assoc()) $content[(int)$c['meme_id']] = [(int)$c['sens'], (int)$c['pol'], ((string)($c['error'] ?? '') === 'adult')];
        }catch(Throwable $e){}

        /* "Show more local content" — only when the person switched it on (applies to both variants: it is their own choice) */
        $localMap = [];
        if($viewer > 0 && (float)DZ_LOCAL_WEIGHT > 0 && function_exists('dz_local_is_on') && dz_local_is_on($conn, $viewer)){
            $localMap = dz_local_poster_scores($conn, $viewer, array_map(function($r){ return (int)$r['uploaded_by']; }, $rows));
        }

        /* v6: who the creators are (size, reputation, track record) and which languages the person reads */
        $folCnt = []; $repMap = []; $cleanMap = []; $postLang = []; $vlang = ['langs' => [], 'strong' => false];
        if($B){
            $uidSet = []; $pendU = [];
            foreach($rows as $r0){
                $uidSet[(int)$r0['uploaded_by']] = 1;
                if(!isset($content[(int)$r0['id']]) && ((int)($r0['age_sec'] ?? 0)) < ((int)DZ_PENDING_DAYS * 86400)) $pendU[(int)$r0['uploaded_by']] = 1;
            }
            $folCnt   = dz_follower_counts($conn, array_keys($uidSet));
            $repMap   = dz_creator_reports($conn, array_keys($uidSet), $folCnt);
            $cleanMap = dz_clean_creators($conn, array_keys($pendU));
            $vlang    = dz_viewer_langs($conn, $viewer);
            if($vlang['langs']) $postLang = dz_post_langs($conn, $rows);
        }

        $recent = $B ? dz_recent_engagement($conn, $ids, (int)DZ_VELOCITY_HOURS) : [];
        $sessT  = $B ? dz_session_topics($conn, $viewer) : [];

        /* the platform's typical engagement per viewer, measured on posts with enough viewers
           (variant B measures on posts with ≥10 viewers and smooths each post toward the average; A needs ≥30 and cuts off hard) */
        $minImp = $B ? (int)DZ_RATE_FLOOR_IMP : (int)DZ_RATE_MIN_IMP;
        $P      = (float)DZ_PRIOR_IMP;
        $rates = [];
        foreach($rows as $r){
            $imp = (int)($r['impression_count'] ?? 0);
            if($imp >= $minImp) $rates[] = dz_engagement($r) / $imp;
        }
        $avgRate = (count($rates) >= 5) ? array_sum($rates) / count($rates) : 0.0;

        /* the platform's typical skip rate and linger rate (per viewer), same idea */
        $skipRates = []; $lingRates = [];
        foreach($rows as $r){
            $imp = (int)($r['impression_count'] ?? 0);
            if($imp >= $minImp){ $skipRates[] = (int)($r['skip_total'] ?? 0) / $imp; $lingRates[] = (int)($r['linger_total'] ?? 0) / $imp; }
        }
        $haveQ   = (count($skipRates) >= 5);
        $avgSkip = $haveQ ? array_sum($skipRates) / count($skipRates) : 0.0;
        $avgLing = $haveQ ? array_sum($lingRates) / count($lingRates) : 0.0;

        $scored = []; $debug = [];
        foreach($rows as $r){
            $mid = (int)$r['id']; $uid = (int)$r['uploaded_by'];
            $imp = (int)($r['impression_count'] ?? 0);

            /* freshness: age as of the moment this feed session started */
            $age_h = max(0.0, (((int)($r['age_sec'] ?? 0)) - $sess_sec) / 3600.0);
            $E     = dz_engagement($r);
            $base  = ($E + 3.0) / pow($age_h + 2.0, 1.5);                     /* +3 = a starting allowance so brand-new posts can compete */
            $noise = ((($mid * $seed) % 104729) / 104729.0) * 0.5;

            /* engagement rate: viewers → likes/saves/etc.
               B: smoothed — with few viewers the post is pulled toward the site average (no cliff at 30 viewers)
               A: only trusted once enough people have seen it */
            $rf = 1.0;
            if($avgRate > 0){
                if($B)                               $rf = min(1.6, max(0.6, (($E + $avgRate * $P) / ($imp + $P)) / $avgRate));
                elseif($imp >= DZ_RATE_MIN_IMP)      $rf = min(1.6, max(0.6, ($E / $imp) / $avgRate));
            }

            /* viewer behaviour: posts people skip rank lower, posts people linger on rank higher (compared with the site average) */
            $sf = 1.0;
            if($haveQ){
                if($B){
                    $ls = (((int)($r['linger_total'] ?? 0)) + $avgLing * $P) / ($imp + $P);
                    $ks = (((int)($r['skip_total'] ?? 0))   + $avgSkip * $P) / ($imp + $P);
                    $sf = min(1.3, max(0.7, 1.0 + 0.8 * (($ls - $avgLing) - ($ks - $avgSkip))));
                } elseif($imp >= DZ_RATE_MIN_IMP){
                    $sf = min(1.3, max(0.7, 1.0 + 0.8 * ((((int)($r['linger_total'] ?? 0)) / $imp - $avgLing) - (((int)($r['skip_total'] ?? 0)) / $imp - $avgSkip))));
                }
            }

            /* relevance: the post's topics = #hashtags (weight 1) + caption words (lighter) + what the media shows (full) */
            $topics = [];
            foreach(($tagmap[$mid] ?? []) as $t){
                if($B) $t = dz_norm_term($t);
                if($t !== '') $topics[$t] = 1.0;
            }
            foreach(($wordmap[$mid] ?? []) as $t => $wt){ if(!isset($topics[$t]) || $wt > $topics[$t]) $topics[$t] = $wt; }
            $tb = 0.0; $h = 0.0;
            foreach($topics as $t => $wt){
                if(isset($lessW[$t])) $h = max($h, $lessW[$t] * $wt);          /* how much of this post is about a "Less" topic */
                $s = $affTag[$t] ?? 0.0;
                if($s > 0)     $tb += min(1.0, $s / 20.0) * $wt;
                elseif($s < 0){
                    if(isset($ex['less'][$t])) continue;                          /* a picked "Less" topic is handled below as a bounded suppression, not a hole in the score */
                    $tb += max(-0.6, $s / 10.0) * $wt;
                }
            }
            $h  = min(1.0, $h);
            $tb = max(-0.8, min(1.5, $tb));
            $cs = $aff['creator'][(string)$uid] ?? 0.0;
            $cb = ($cs > 0) ? min(0.6, $cs / 25.0) : max(-0.6, $cs / 10.0);
            $ts = $aff['type'][strtolower((string)($r['type'] ?? ''))] ?? 0.0;
            $yb = ($ts > 0) ? min(0.3, $ts / 40.0) : max(-0.2, $ts / 20.0);
            $sb = min(0.45, 0.15 * count($social_map[$mid] ?? []));
            /* v5: short-term interest — topics they liked / saved / shared in the last few minutes */
            $ss = 0.0;
            if($B && $sessT){
                foreach($topics as $t => $wt){ if(isset($sessT[$t])) $ss += min(0.25, $sessT[$t] / 8.0) * $wt; }
                $ss = min((float)DZ_SESSION_CAP, $ss);
            }
            /* local content: poster is in the same place as the viewer (About me "Current location") */
            $lb = isset($localMap[$uid]) ? (float)$localMap[$uid] * (float)DZ_LOCAL_WEIGHT : 0.0;
            /* v6: people with your taste liked this */
            $cfb = isset($cfMap[$mid]) ? (float)DZ_CF_W * (float)$cfMap[$mid] : 0.0;
            /* v8: for a post about a "Less" topic, the learned lifts (friends liked it, what you touched a minute ago, people like you liked it) are cut to 30% */
            if($h > 0){ $dmp = 1.0 - 0.7 * $h; $sb *= $dmp; $ss *= $dmp; $cfb *= $dmp; }
            $rel = max(0.2, 1.0 + ($tb + $cb + $yb + $sb + $ss + $lb + $cfb) * $relPow);  /* the adventure dial scales how much taste matters */
            /* v8: suppression, not exclusion: a "Less" post keeps at least 1 − DZ_LESS_STRENGTH of its score */
            $lsm = 1.0 - (float)DZ_LESS_STRENGTH * $h;

            $follow = ((int)($r['is_following'] ?? 0) > 0) ? $folMult : 1.0;

            /* v5: velocity — engagement gained in the last few hours lifts a post (capped at +50%) */
            $vf = 1.0; $sus = 1.0;
            if($B){
                $rv = $recent[$mid] ?? null;                                   /* [trusted, raw] */
                $rc = $rv ? (float)$rv[0] : 0.0;
                if($rc > 0) $vf = 1.0 + min(0.5, (float)DZ_VELOCITY_W * log10(1.0 + $rc));
                /* v6: a burst that mostly comes from brand-new accounts looks fake → the post is held down */
                if($rv && (float)$rv[1] >= (float)DZ_SUSPECT_MIN && ((float)$rv[0] / max(1.0, (float)$rv[1])) < (float)DZ_SUSPECT_RATIO) $sus = (float)DZ_SUSPECT_MULT;
            }
            /* v5: newness bonus — young posts with few viewers are uncertain, so they get a small chance to prove themselves
               (the fewer viewers, the bigger the bonus; the adventure dial scales it) */
            $ucb = 1.0;
            if($B && (float)DZ_UCB > 0 && $age_h < 72){
                $ucb = 1.0 + (float)DZ_UCB / sqrt(1.0 + $imp / 10.0) * (0.5 + $adv / 100.0);
            }
            /* v6: a new creator (few trackers) gets a little extra for a young post — unless several people reported or blocked them */
            $small = ($B && ((int)($folCnt[$uid] ?? 0)) < (int)DZ_SMALL_CREATOR_FOLLOWERS);
            if($small && $age_h < 72 && ((float)($repMap[$uid] ?? 0.0)) <= 0.0) $ucb *= 1.0 + (float)DZ_SMALL_CREATOR_BONUS;

            /* v4: sensitive / political content levels + safety rules.
               • analysed post  → score multiplier from the person's levels; very sensitive posts from people they do not track are left out
               • not analysed yet (young post, worker has not reached it) → treated cautiously, and never used as an exploration post
               • 18+ pod posts (marked 'adult' by the worker) and the person's own posts are exempt */
            $cmod = 1.0; $sc = 0; $pc = 0; $pending = false;
            $isFol = ((int)($r['is_following'] ?? 0) > 0);
            $isOwn = ($viewer > 0 && $uid === $viewer);
            if(isset($content[$mid])){
                if(!$content[$mid][2] && !$isOwn){
                    $sc = $content[$mid][0]; $pc = $content[$mid][1];
                    if(!$isFol){
                        if($sc >= (int)DZ_HIDE_SENSITIVE[$senLvl]) continue;
                        if($polLvl === 0 && $pc >= (int)DZ_HIDE_POLITICAL_LESS) continue;
                    }
                    $cmod = dz_sensitive_mult($sc, $senLvl) * dz_political_mult($pc, $polLvl);
                }
            } elseif(!$isOwn && ((int)($r['age_sec'] ?? 0)) < ((int)DZ_PENDING_DAYS * 86400)){
                if($B && ($isFol || isset($cleanMap[$uid]))){
                    $cmod = 1.0;   /* v6: someone they track, or a creator whose earlier posts were all clean, is not held back while the check is pending */
                } else {
                    $pending = true;
                    $sensP = $B ? DZ_PENDING_SENSITIVE_B : DZ_PENDING_SENSITIVE;
                    $polP  = $B ? DZ_PENDING_POLITICAL_B : DZ_PENDING_POLITICAL;
                    $cmod = (float)$sensP[$senLvl] * (float)$polP[$polLvl];
                }
            }

            /* v6: creator reputation (many different people reported / blocked them) and language match. Not applied to people they track or their own posts. */
            $rpm = 1.0; $lm = 1.0;
            if($B && !$isOwn && !$isFol){
                $rp = (float)($repMap[$uid] ?? 0.0);
                if($rp > 0) $rpm = max((float)DZ_REP_FLOOR, 1.0 / (1.0 + (float)DZ_REP_W * $rp));
                if($vlang['langs']){
                    $pl = $postLang[$mid] ?? 'und';
                    if($pl !== 'und' && !dz_lang_ok($pl, $vlang['langs'])) $lm = $vlang['strong'] ? (float)DZ_LANG_MISMATCH : (float)DZ_LANG_MISMATCH_WEAK;
                }
            }

            /* novelty: only sightings from BEFORE this session count (so a feed doesn't reshuffle itself while you scroll it) */
            $nov = 1.0;
            if($r['seen_first_sec'] !== null && (int)$r['seen_first_sec'] > $sess_sec){
                $last_before = ((int)$r['seen_last_sec'] > $sess_sec) ? ((int)$r['seen_last_sec'] - $sess_sec) : ((int)$r['seen_first_sec'] - $sess_sec);
                $hh = $last_before / 3600.0;                                   /* hours between that sighting and when this feed started */
                $nov = ($hh < 6) ? 0.05 : (($hh < 24) ? 0.15 : (($hh < 72) ? 0.4 : 0.7));
                if((int)$r['seen_count'] >= 3) $nov *= 0.8;
                $mine = (int)($r['my_skips'] ?? 0);                           /* you skipped it before: keep it down much longer */
                if($mine > 0) $nov = min($nov, ($mine >= 2) ? 0.1 : 0.3);
                if($novLvl === 0)      $nov = 1.0 - (1.0 - $nov) * 0.35;      /* "let seen posts return": soften the push-down */
                elseif($novLvl === 2)  $nov = $nov * 0.5;                     /* "fresh only": push seen posts down twice as hard */
            }

            $score = ($base + $noise) * $rf * $sf * $rel * $lsm * $follow * $cmod * $nov * $vf * $ucb * $sus * $rpm * $lm;
            $scored[] = ['row' => $r, 'score' => $score, 'rel' => $rel, 'nov' => $nov, 'imp' => $imp, 'age_h' => $age_h, 'cmod' => $cmod, 'pending' => $pending,
                         'small' => $small, 'bad' => ($sus < 1.0 || $rpm < 1.0 || $lm < 1.0),
                         'h' => $h, 'neg' => ($tb < 0), 'topics' => array_keys($topics), 'uid' => $uid, 'type' => strtolower((string)($r['type'] ?? ''))];
            if(DZ_ALGO_DEBUG) $debug[$mid] = ['base' => round($base,3), 'rate' => round($rf,2), 'quality' => round($sf,2), 'rel' => round($rel,2), 'tag' => round($tb,2), 'creator' => round($cb,2), 'type' => round($yb,2), 'social' => round($sb,2), 'session' => round($ss,2), 'local' => round($lb,2), 'velocity' => round($vf,2), 'newness' => round($ucb,2), 'cf' => round($cfb,2), 'less' => round($h,2), 'suspect' => $sus, 'reputation' => round($rpm,2), 'language' => $lm, 'small_creator' => $small, 'cold' => $cold, 'follow' => $follow, 'novelty' => $nov, 'sensitive' => $sc, 'political' => $pc, 'content' => round($cmod,2), 'pending' => $pending, 'variant' => $variant];
        }

        usort($scored, function($a, $b){
            if($a['score'] == $b['score']) return (int)$b['row']['id'] <=> (int)$a['row']['id'];
            return ($a['score'] < $b['score']) ? 1 : -1;
        });

        /* TASTEMATCH BLEND (Python): $GLOBALS['dz_tm_ranks'] = [post id => TasteMatch rank], filled by shared_core.php (empty = off / Flask down).
           Done HERE, on the scored list and BEFORE exploration + "Less" spacing, so those rules still shape the final feed.
           Posts are only re-ordered, never added (every post here already passed every rule). Reciprocal-rank fusion:
             w * 1/(k + python_rank) + (1 - w) * 1/(k + php_rank).   A post Python did not return gets nothing from Python's half.
           Posts held back by a safety signal (suspected fake engagement, reported / blocked creator, "Less" topic, learned dislike) never get a Python lift. */
        $tmr = $GLOBALS['dz_tm_ranks'] ?? null;
        if(is_array($tmr) && $tmr && count($scored) > 1){
            $tw = defined('DZ_TM_WEIGHT') ? max(0.0, min(1.0, (float)DZ_TM_WEIGHT)) : 0.55;
            $tk = defined('DZ_TM_FUSE_K') ? max(1.0, (float)DZ_TM_FUSE_K) : 20.0;
            $fz = []; $tmHit = 0;
            foreach($scored as $i => $s){
                $mid = (int)$s['row']['id'];
                $phpTerm = 1.0 / ($tk + $i + 1);
                if(!empty($s['bad']) || !empty($s['neg']) || $s['h'] > 0)   $pyTerm = $phpTerm;
                elseif(isset($tmr[$mid])){ $pyTerm = 1.0 / ($tk + (int)$tmr[$mid]); $tmHit++; }
                else $pyTerm = 0.0;
                $fz[$i] = $tw * $pyTerm + (1.0 - $tw) * $phpTerm;
            }
            if($tmHit > 0){
                $idx = array_keys($scored);
                usort($idx, function($x, $y) use ($fz){ return ($fz[$y] <=> $fz[$x]) ?: ($x <=> $y); });
                $re = []; foreach($idx as $i) $re[] = $scored[$i];
                $scored = $re;
                error_log('[dz tm-blend] ' . json_encode(['posts' => count($scored), 'python_picks_in_feed' => $tmHit, 'w' => $tw]));
            }
        }

        /* exploration. The adventure dial changes how many (30% … 170% of the normal amount) and how often (every 14 … every 2 positions).
           Posts the person's Sensitive / Political level holds back, and posts about a "Less" topic or a topic they lean away from, are never used.
           v8 (C): variant B picks discovery posts for being genuinely DIFFERENT, one at a time. Each pick is scored on
             • new topics (not in the top of the feed, not among their favourites, not already picked)   weight 1.0
             • a creator not yet in the top of the feed or already picked                               weight 0.6
             • a format (image / video / text …) that is rare in the top of the feed                    weight 0.5
             • a little for rank (so discovery is still decent)                                         weight 0.3
           and it must be at least DZ_EXPLORE_MIN_NOVELTY different, otherwise fewer discovery posts are shown instead of fake ones. */
        $main = []; $explore = [];
        $expMult    = 0.3 + 1.4 * ($adv / 100.0);
        if($cold) $expMult *= 1.6;                                                /* v6: a new person sees more different things */
        $maxExplore = min(60, (int)floor((count($scored) / 8) * $expMult));
        $minPos = $B ? (int)DZ_EXPLORE_FROM : 30;                                 /* v6: from rank 12 (v4: 30) */
        $maxImp = $B ? (int)DZ_EXPLORE_MAX_IMP_B : (int)DZ_EXPLORE_MAX_IMP;       /* v6: up to 60 viewers (v4: 40) */
        $relLo  = $B ? 0.7 : 0.8; $relHi = $B ? 1.3 : 1.15;
        $pickedIdx = [];
        if($B){
            $cand = [];
            foreach($scored as $i => $s){
                $ok = ($i >= $minPos) && ($i < (int)DZ_EXPLORE_POOL_MAX)
                   && $s['imp'] < $maxImp && $s['age_h'] < 72
                   && $s['nov'] >= 1.0 && $s['rel'] >= $relLo && $s['rel'] <= $relHi
                   && $s['cmod'] >= 0.95 && empty($s['pending']) && empty($s['bad'])
                   && $s['h'] <= 0 && empty($s['neg']);
                if($ok) $cand[$i] = $s;
            }
            if($cand && $maxExplore > 0){
                $seenT = []; $seenU = []; $typeN = [];
                $known = array_filter($affTag, function($v){ return $v > 0; });
                arsort($known);
                foreach(array_slice(array_keys($known), 0, 30) as $t) $seenT[$t] = 1;                 /* their favourite topics are not "new" to them */
                for($i = 0, $n = min(30, count($scored)); $i < $n; $i++){                               /* neither is what the top of the feed already shows */
                    $s = $scored[$i];
                    foreach($s['topics'] as $t) $seenT[$t] = 1;
                    $seenU[$s['uid']] = 1;
                    $typeN[$s['type']] = ($typeN[$s['type']] ?? 0) + 1;
                }
                while(count($explore) < $maxExplore && $cand){
                    $bestI = null; $bestV = -1.0; $tot = max(1, array_sum($typeN));
                    foreach($cand as $i => $s){
                        $tp = $s['topics'];
                        if($tp){ $new = 0; foreach($tp as $t){ if(!isset($seenT[$t])) $new++; } $tN = $new / count($tp); } else { $tN = 0.4; }
                        $uN = isset($seenU[$s['uid']]) ? 0.0 : 1.0;
                        $yN = 1.0 - (($typeN[$s['type']] ?? 0) / $tot);
                        $v  = 1.0 * $tN + 0.6 * $uN + 0.5 * $yN + 0.3 * (1.0 / (1.0 + $i / 50.0)) + (!empty($s['small']) ? 0.15 : 0.0);
                        if($tp && $tN < 0.5) $v -= 0.8;                       /* mostly-known topics are not discovery */
                        if($v > $bestV){ $bestV = $v; $bestI = $i; }
                    }
                    if($bestI === null || $bestV < (float)DZ_EXPLORE_MIN_NOVELTY) break;
                    $s = $cand[$bestI]; unset($cand[$bestI]);
                    $explore[] = $s; $pickedIdx[$bestI] = true;
                    foreach($s['topics'] as $t) $seenT[$t] = 1;                  /* the next pick has to be different from this one too */
                    $seenU[$s['uid']] = 1;
                    $typeN[$s['type']] = ($typeN[$s['type']] ?? 0) + 1;
                }
            }
            foreach($scored as $i => $s){ if(empty($pickedIdx[$i])) $main[] = $s; }
        } else {
            foreach($scored as $i => $s){
                $ok = ($i >= $minPos) && count($explore) < $maxExplore
                   && $s['imp'] < $maxImp && $s['age_h'] < 72
                   && $s['nov'] >= 1.0 && $s['rel'] >= $relLo && $s['rel'] <= $relHi
                   && $s['cmod'] >= 0.95 && empty($s['pending']) && empty($s['bad']) && $s['h'] <= 0;
                if($ok) $explore[] = $s; else $main[] = $s;
            }
        }
        $every = max(2, dz_explore_every($adv));
        if($cold) $every = max(2, (int)floor($every * 0.6));
        $k = 0;
        foreach($explore as $s){
            $pos = min(count($main), 4 + $k * $every);
            array_splice($main, $pos, 0, [$s]);
            $k++;
        }

        /* v8 (B): "Less" posts are throttled, not removed — at most one per DZ_LESS_GAP positions, the rest wait further down the list */
        $final = []; $held = []; $lastLess = -1000; $gap = max(2, (int)DZ_LESS_GAP);
        foreach($main as $s){
            if($s['h'] >= 0.5){
                if((count($final) - 1 - $lastLess) >= $gap){ $final[] = $s; $lastLess = count($final) - 1; }
                else $held[] = $s;
            } else {
                $final[] = $s;
                if($held && (count($final) - 1 - $lastLess) >= $gap){ $final[] = array_shift($held); $lastLess = count($final) - 1; }
            }
        }
        foreach($held as $s) $final[] = $s;

        $out = [];
        foreach($final as $i => $s){
            if(DZ_ALGO_DEBUG && $i < 40){
                $mid = (int)$s['row']['id'];
                $GLOBALS['dz_algo_debug'][] = ['rank' => $i + 1, 'id' => $mid, 'score' => round($s['score'], 3)] + ($debug[$mid] ?? []);
            }
            $out[] = $s['row'];
        }
        return $out;
    }
}