<?php
/* ================================================================
   dz_algo_prefs.php — what a person told the algorithm on algorithm_settings.php

   Loaded automatically by dz_rank.php (require_once), so shared_core.php needs no change.

   HOW IT PLUGS IN
     • explicit choices  (dz_user_algo_prefs)    → merged into dz_load_affinity(), so the SQL candidate pool,
                                                   the tag bar and the PHP ranker all honour them with no extra code
     • dials / switches  (dz_user_algo_settings) → read by dz_rank_feed() (adventure, tracking boost, seen posts)
                                                   and by dz_affinity_bump() / dz_record_dwell() (learning on / off)
   Every function fails soft: if the tables are missing the defaults are used and the feed behaves exactly like v2.

   v5 additions: dz_media_analysis + dz_meme_terms tables, and the sensitive_level / political_level dials.
   v6 additions: forget_since (a reset ignores older raw interactions everywhere), dz_algo_learning_allowed(),
                 topic keys: "#memes" → memes, "leg day" → leg_day / legday.
   NOTE: `sensitive` is a reserved word in MariaDB/MySQL, so it is always written with backticks inside SQL.
================================================================ */

if(!function_exists('dz_algo_defaults')){
    function dz_algo_defaults(){
        return ['adventure' => 50, 'follow_level' => 1, 'novelty_level' => 1, 'personalize' => 1, 'use_watch_time' => 1,
                'sensitive_level' => 1, 'political_level' => 1];   /* 0 less | 1 standard | 2 more */
    }
}

/* creates the tables (and the v5 / v6 columns) if they are not there yet (cached per session) */
if(!function_exists('dz_algo_ensure_schema')){
    function dz_algo_ensure_schema($conn){
        if(!empty($_SESSION['dz_algo_schema_v3'])) return;
        try{
            $conn->query("CREATE TABLE IF NOT EXISTS dz_user_algo_prefs (
                user_id INT NOT NULL,
                kind ENUM('tag','creator','type') NOT NULL,
                item VARCHAR(100) NOT NULL,
                pref ENUM('more','less') NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, kind, item),
                KEY idx_user_pref (user_id, pref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            $conn->query("CREATE TABLE IF NOT EXISTS dz_user_algo_settings (
                user_id INT NOT NULL,
                adventure TINYINT UNSIGNED NOT NULL DEFAULT 50,
                follow_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
                novelty_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
                personalize TINYINT(1) NOT NULL DEFAULT 1,
                use_watch_time TINYINT(1) NOT NULL DEFAULT 1,
                sensitive_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
                political_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
                forget_since DATETIME NULL DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            /* tables created by older versions: add the missing columns (works on MySQL and MariaDB) */
            foreach(['sensitive_level' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
                     'political_level' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
                     'forget_since'    => 'DATETIME NULL DEFAULT NULL'] as $col => $def){
                $c = $conn->query("SHOW COLUMNS FROM dz_user_algo_settings LIKE '$col'");
                if($c && $c->num_rows === 0){
                    $conn->query("ALTER TABLE dz_user_algo_settings ADD COLUMN $col $def");
                }
            }

            /* what the media-analysis worker found out about each post */
            $conn->query("CREATE TABLE IF NOT EXISTS dz_media_analysis (
                meme_id     INT NOT NULL,
                status      ENUM('done','skipped','failed') NOT NULL,
                `sensitive` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                political   TINYINT UNSIGNED NOT NULL DEFAULT 0,
                summary     VARCHAR(255) NULL,
                model       VARCHAR(60)  NULL,
                attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                error       VARCHAR(200) NULL,
                analyzed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (meme_id),
                KEY idx_status (status, attempts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            /* the word index of every post: what the CAPTION says and what the MEDIA shows */
            $conn->query("CREATE TABLE IF NOT EXISTS dz_meme_terms (
                meme_id INT NOT NULL,
                term    VARCHAR(40) NOT NULL,
                shown   VARCHAR(40) NOT NULL,
                src     ENUM('ai','caption') NOT NULL,
                w       TINYINT UNSIGNED NOT NULL DEFAULT 100,
                PRIMARY KEY (meme_id, term, src),
                KEY idx_term (term, meme_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $_SESSION['dz_algo_schema_v3'] = 1;
        }catch(Throwable $e){}
    }
}

/* this person's dials and switches (cached for the request) */
if(!function_exists('dz_algo_settings')){
    function dz_algo_settings($conn, $user_id){
        static $cache = [];
        $user_id = (int)$user_id;
        if(isset($cache[$user_id])) return $cache[$user_id];
        $out = dz_algo_defaults();
        if($user_id > 0){
            try{
                /* SELECT * so a table that does not have the newer columns yet still works (those keep their defaults) */
                $r = $conn->query("SELECT * FROM dz_user_algo_settings WHERE user_id = $user_id LIMIT 1");
                if($r && ($row = $r->fetch_assoc())){
                    foreach($out as $k => $v) if(isset($row[$k])) $out[$k] = (int)$row[$k];
                }
            }catch(Throwable $e){}
        }
        return $cache[$user_id] = $out;
    }
}

/* the explicit choices: [['kind'=>'tag','item'=>'memes','pref'=>'more'], …] */
if(!function_exists('dz_algo_load_prefs')){
    function dz_algo_load_prefs($conn, $user_id){
        static $cache = [];
        $user_id = (int)$user_id;
        if(isset($cache[$user_id])) return $cache[$user_id];
        $rows = [];
        if($user_id > 0){
            try{
                $r = $conn->query("SELECT kind, item, pref FROM dz_user_algo_prefs WHERE user_id = $user_id ORDER BY updated_at DESC LIMIT 200");
                if($r) while($x = $r->fetch_assoc()) $rows[] = $x;
            }catch(Throwable $e){}
        }
        return $cache[$user_id] = $rows;
    }
}

/* is learning from likes / saves / reposts / shares switched off for this person? */
if(!function_exists('dz_algo_learning_off')){
    function dz_algo_learning_off($conn, $user_id){
        $s = dz_algo_settings($conn, $user_id);
        return empty($s['personalize']);
    }
}
/* the same question the other way round — every reader of raw user_interactions should check this */
if(!function_exists('dz_algo_learning_allowed')){
    function dz_algo_learning_allowed($conn, $user_id){
        return !dz_algo_learning_off($conn, $user_id);
    }
}
if(!function_exists('dz_algo_use_watch_time')){
    function dz_algo_use_watch_time($conn, $user_id){
        $s = dz_algo_settings($conn, $user_id);
        return !empty($s['use_watch_time']);
    }
}

/* "forget everything before this moment": set by "Reset what my feed has learned".
   Returns 'Y-m-d H:i:s' or null. Cached for the request. */
if(!function_exists('dz_algo_forget_since')){
    function dz_algo_forget_since($conn, $user_id){
        static $cache = [];
        $user_id = (int)$user_id;
        if($user_id <= 0) return null;
        if(array_key_exists($user_id, $cache)) return $cache[$user_id];
        $v = null;
        try{
            $r = $conn->query("SELECT forget_since FROM dz_user_algo_settings WHERE user_id = $user_id LIMIT 1");
            if($r && ($row = $r->fetch_assoc()) && !empty($row['forget_since']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$row['forget_since'])){
                $v = (string)$row['forget_since'];
            }
        }catch(Throwable $e){}
        return $cache[$user_id] = $v;
    }
}
/* SQL fragment " AND <col> >= 'forget_since'" or '' — $col is always a column name written in code, never user input */
if(!function_exists('dz_algo_since_sql')){
    function dz_algo_since_sql($conn, $user_id, $col){
        $f = dz_algo_forget_since($conn, $user_id);
        return $f ? " AND $col >= '$f'" : '';
    }
}
/* call this from the reset_taste action */
if(!function_exists('dz_algo_set_forget')){
    function dz_algo_set_forget($conn, $user_id){
        $user_id = (int)$user_id;
        if($user_id <= 0) return false;
        try{
            $conn->query("INSERT INTO dz_user_algo_settings (user_id, forget_since) VALUES ($user_id, NOW()) ON DUPLICATE KEY UPDATE forget_since = NOW()");
            return true;
        }catch(Throwable $e){ return false; }
    }
}

/* adventure 0…100 → "an exploration post every N positions" (0 → 14, 50 → DZ_EXPLORE_EVERY, 100 → 2) */
if(!function_exists('dz_explore_every')){
    function dz_explore_every($adv){
        $adv  = max(0, min(100, (int)$adv));
        $base = defined('DZ_EXPLORE_EVERY') ? max(3, (int)DZ_EXPLORE_EVERY) : 7;
        if($adv <= 50) return (int)round($base * 2 - $base * ($adv / 50.0));
        return max(2, (int)round($base - ($base - 2) * (($adv - 50) / 50.0)));
    }
}

/* every key a picked topic should be stored under so it matches how posts are indexed:
     "#memes"   → memes, meme            (hashtags are stored bare, lower-case)
     "glutes"   → glutes, glute          (stemmed form of the post index)
     "leg day"  → leg day, leg_day, legday   (a hashtag has no spaces; a phrase is not a single word) */
if(!function_exists('dz_algo_topic_keys')){
    function dz_algo_topic_keys($item){
        $item = strtolower(trim((string)$item));
        $item = ltrim($item, '#');
        if($item === '') return [];
        $keys = [$item];
        if(strpos($item, ' ') !== false){
            $keys[] = str_replace(' ', '_', $item);
            $keys[] = str_replace(' ', '', $item);
        } else {
            if(function_exists('dz_norm_term')){ $n = dz_norm_term($item); if($n !== '') $keys[] = $n; }
            if(function_exists('dz_tokens')){
                $tk = dz_tokens($item);
                if(count($tk) === 1 && !empty($tk[0]['term'])) $keys[] = (string)$tk[0]['term'];
            }
        }
        $out = [];
        foreach($keys as $k){ if($k !== '' && strlen($k) <= 60) $out[$k] = $k; }
        return array_values($out);
    }
}

/* the topics a person picked on algorithm_settings.php, as normalised keys the ranker understands:
   ['more' => [key => 1], 'less' => [key => 1]]
     "#memes" → memes · "glutes" → glute · "leg day" → leg_day and legday (a phrase is not a single word) */
if(!function_exists('dz_algo_explicit_topics')){
    function dz_algo_explicit_topics($conn, $user_id){
        static $cache = [];
        $user_id = (int)$user_id;
        if(isset($cache[$user_id])) return $cache[$user_id];
        $out = ['more' => [], 'less' => []];
        if($user_id > 0){
            foreach(dz_algo_load_prefs($conn, $user_id) as $p){
                if(($p['kind'] ?? '') !== 'tag') continue;
                $item = ltrim(strtolower(trim((string)$p['item'])), '#');
                if($item === '') continue;
                $side = ($p['pref'] === 'less') ? 'less' : 'more';
                if(strpos($item, ' ') !== false){
                    $out[$side][str_replace(' ', '_', $item)] = 1;
                    $out[$side][str_replace(' ', '', $item)]  = 1;
                } else {
                    $k = function_exists('dz_norm_term') ? dz_norm_term($item) : $item;
                    if($k !== '') $out[$side][$k] = 1;
                }
            }
            /* a topic picked on both sides: "less" wins (the safer reading) */
            foreach($out['less'] as $k => $_x) unset($out['more'][$k]);
        }
        return $cache[$user_id] = $out;
    }
}

/* merges the explicit choices into a dz_load_affinity() result.
   v8: a choice now outranks what was learned, including other spellings of the same topic
   ("Less of football" also zeroes a learned +8 on "footballs"; "More of X" zeroes a learned negative on it).
     topic    more +20 → full +1.0      less −10 → −0.6
     creator  more +25 → full +0.6      less −10 → −0.6
     format   more +40 → full +0.3      less −20 → −0.2
   When "learn from my activity" is off, the learned taste is dropped and only these choices count. */
if(!function_exists('dz_algo_merge_prefs')){
    function dz_algo_merge_prefs($conn, $user_id, $aff){
        $user_id = (int)$user_id;
        if($user_id <= 0) return $aff;
        $st = dz_algo_settings($conn, $user_id);
        if(empty($st['personalize'])){
            $aff = ['tag' => [], 'creator' => [], 'type' => []];
            $aff['tag']['_off'] = 0.0;   /* harmless marker: keeps getUserTopTags() from falling back to the interaction history */
        }
        static $power = ['tag' => [20.0, -10.0], 'creator' => [25.0, -10.0], 'type' => [40.0, -20.0]];
        $hasNorm = function_exists('dz_norm_term');
        foreach(dz_algo_load_prefs($conn, $user_id) as $p){
            $k = $p['kind']; $item = (string)$p['item'];
            if(!isset($power[$k]) || $item === '') continue;
            if($k === 'tag'){
                $keys = dz_algo_topic_keys($item);
                if($hasNorm && strpos($item, ' ') === false){
                    $nm = dz_norm_term(ltrim(strtolower($item), '#'));
                    foreach($aff['tag'] as $ak => $av){
                        if($ak !== '' && dz_norm_term((string)$ak) === $nm){
                            if($p['pref'] === 'less' && $av > 0) $aff['tag'][$ak] = 0.0;
                            if($p['pref'] === 'more' && $av < 0) $aff['tag'][$ak] = 0.0;
                        }
                    }
                }
            } else {
                $keys = [$item];
                if($k === 'type'){
                    if($item === 'text')    $keys[] = '';          /* text posts often have an empty type */
                    if($item === 'sticker') $keys[] = 'stickers';
                }
            }
            foreach($keys as $key){
                $cur = (float)($aff[$k][$key] ?? 0.0);
                $aff[$k][$key] = ($p['pref'] === 'more') ? max(0.0, $cur) + $power[$k][0] : min(0.0, $cur) + $power[$k][1];
            }
        }
        return $aff;
    }
}

/* what the system has LEARNED (no explicit choices mixed in) — for the "What your activity says" list.
   Same fade as dz_load_affinity(): tags ~7-day half-life, creators / formats ~14 days. */
if(!function_exists('dz_algo_learned')){
    function dz_algo_learned($conn, $user_id, $limit = 60){
        $out = [];
        $user_id = (int)$user_id; $limit = max(1, min(200, (int)$limit));
        if($user_id <= 0) return $out;
        try{
            $stmt = $conn->prepare("
                SELECT kind, item,
                       score * EXP(-(CASE kind WHEN 'tag' THEN 0.004 ELSE 0.002 END) * TIMESTAMPDIFF(HOUR, updated_at, NOW())) AS eff
                FROM dz_user_affinity
                WHERE user_id = ?
                HAVING ABS(eff) > 1.0
                ORDER BY ABS(eff) DESC
                LIMIT $limit
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while($r = $res->fetch_assoc()) $out[] = ['kind' => (string)$r['kind'], 'item' => (string)$r['item'], 'eff' => round((float)$r['eff'], 2)];
            $stmt->close();
        }catch(Throwable $e){}
        return $out;
    }
}