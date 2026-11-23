<?php
/* ════════════════════════════════════════════════════════════════════
   hashtags.php — the hashtag button bar (All, #Funny, #Gaming …) shared by
   home.php, text_feed.php and any other page.

   ORDER OF THE BUTTONS (new)
     1. All
     2. the viewer's favourite tags (most-interacted first)
     3. TRENDING tags on Dezzme this week (built from real posts, updates itself)
     4. the rest of the fixed list below (DZ_HASHTAG_LIST)
   A tag that is in more than one group only appears once.

   HOW TO USE IN A PAGE  (nothing changes for the pages — same calls as before)
     1) near the top, after config.php:   require_once 'hashtags.php';
     2) once the viewer's favourite tags are known (an array of lowercase tag
        names, or [] if you have none):
            $ht = dz_hashtags_sorted($user_top_tags);
     3) inside <head> (anywhere before the bar):   <?php dz_hashtags_styles(); ?>
     4) where the bar should appear:               <?php dz_hashtag_bar($tag, $filter, $ht); ?>
        ($tag = current ?tag= value or 'all',  $filter = current ?filter= value)
     5) just before </body>:                        <?php dz_hashtags_script(); ?>

   Needs the dz_meme_tags table (dezzme_migration_v1.sql / fix_dz_meme_tags.sql) for the trending part.
   If that table can't be read, the bar quietly falls back to favourites + the fixed list.

   Needs Remix Icon (ri-arrow-left-s-line / ri-arrow-right-s-line) for the two
   scroll arrows. Colours use var(--red) and the size multiplier var(--ccm-s)
   when the page defines them, and sensible defaults when it doesn't.
   LIGHT MODE: the pill / arrow colours come from the theme variables in theme_css.php
   (--fg, --ui-text, --ui-sub2). Every one has the dark colour as its fallback, so the bar
   looks exactly the same as before in dark mode, even on a page that doesn't load the theme.
   Tapping a hashtag reloads the page with ?tag=Name (the current ?filter= is kept).
════════════════════════════════════════════════════════════════════ */

/* the fixed tag list — edit it here and every page changes */
if(!defined('DZ_HASHTAG_LIST')) define('DZ_HASHTAG_LIST', ['All','Funny','Me','Text','Memes','Gaming','Love','School','Anime','Music','Movies','Sports','Football','Dank','DarkHumor','Wholesome','Cursed','Cringe','Sus','Bruh','LOL','ROFL','Template','Classic','OldButGold','Reaction','Mood','Same','Unexpected','PlotTwist','NoContext','ExplainThis','JustWhy','ImDone','RelatableAF','TooReal','BigMood','Pain','Dead','ICant','SendHelp','ThisAintIt','ItBeLikeThat','WhyTho','LowQuality','HighQuality','DeepFried','Brainrot','NPC','SideEye','Mid','W','L','Rare','Legendary','Throwback']);

/* trending settings — to change them, define the constant BEFORE require_once 'hashtags.php' */
if(!defined('DZ_TREND_DAYS'))          define('DZ_TREND_DAYS', 7);     /* look at posts from the last N days */
if(!defined('DZ_TREND_MIN'))           define('DZ_TREND_MIN', 2);      /* a tag needs at least N posts in that time to count as trending */
if(!defined('DZ_TREND_MAX'))           define('DZ_TREND_MAX', 12);     /* show at most N trending tags */
if(!defined('DZ_TREND_CACHE_SECONDS')) define('DZ_TREND_CACHE_SECONDS', 600);   /* recalculate every 10 minutes, not on every page load */

/* link for a filter + tag combination, relative to the current page */
if(!function_exists('hpUrl')){
    function hpUrl($f, $t){
        $q = [];
        if($f && $f !== 'trending') $q['filter'] = $f;
        if($t && $t !== 'all')      $q['tag']    = $t;
        return '?' . http_build_query($q);
    }
}

/* the most-used tags on Dezzme over the last DZ_TREND_DAYS days, lowercase, busiest first.
   Counts only public, non-ad, non-18+ posts from active accounts. Cached in a temp file so it is one query every few minutes. */
if(!function_exists('dz_trending_tags')){
    function dz_trending_tags($conn){
        static $mem = null;
        if($mem !== null) return $mem;

        $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'dz_trending_tags_v1.json';
        if(is_file($file) && (time() - (int)@filemtime($file)) < (int)DZ_TREND_CACHE_SECONDS){
            $cached = json_decode((string)@file_get_contents($file), true);
            if(is_array($cached)) return $mem = $cached;
        }

        $tags = [];
        $ok   = false;
        if($conn){
            try{
                $days = max(1, (int)DZ_TREND_DAYS);
                $min  = max(1, (int)DZ_TREND_MIN);
                $max  = max(1, (int)DZ_TREND_MAX);
                $res = $conn->query("
                    SELECT t.tag, COUNT(*) AS c
                    FROM dz_meme_tags t
                    JOIN memes m ON m.id = t.meme_id
                    JOIN users u ON u.id = m.uploaded_by
                    WHERE m.created_at >= (NOW() - INTERVAL $days DAY)
                      AND m.is_archived = 0 AND m.is_ad = 0 AND m.is_adult = 0
                      AND u.deactivated_at IS NULL
                      AND m.uploaded_by NOT IN (SELECT user_id FROM user_settings WHERE is_fully_private = 1)
                    GROUP BY t.tag
                    HAVING c >= $min
                    ORDER BY c DESC, t.tag ASC
                    LIMIT " . ($max * 2) . "
                ");
                if($res){
                    $ok = true;
                    while($r = $res->fetch_assoc()){
                        $low = strtolower((string)$r['tag']);
                        if($low === '' || ctype_digit($low)) continue;      /* skip empty and number-only tags like #1 */
                        $tags[] = $low;
                        if(count($tags) >= $max) break;
                    }
                }
            }catch(Throwable $e){ $ok = false; }
        }
        if($ok) @file_put_contents($file, json_encode($tags), LOCK_EX);     /* only cache a real answer */
        return $mem = $tags;
    }
}

/* the tags in display order: "All", the viewer's favourites, this week's trending tags, then the rest of the fixed list.
   $user_top_tags = lowercase tag names the viewer interacts with most (most-liked first).
   returns ['sorted' => [...display names...], 'lower' => [favourites, lowercase], 'trending' => [trending, lowercase]]  */
function dz_hashtags_sorted($user_top_tags = [], $all = null, $conn = null){
    $all = is_array($all) ? $all : DZ_HASHTAG_LIST;
    if($conn === null) $conn = $GLOBALS['conn'] ?? null;

    $fav = [];
    foreach((is_array($user_top_tags) ? $user_top_tags : []) as $t){
        $low = strtolower(trim((string)$t));
        if($low !== '' && !in_array($low, $fav, true)) $fav[] = $low;
    }

    /* lowercase → the nicely-capitalised name from the fixed list (e.g. 'darkhumor' → 'DarkHumor') */
    $fixed = [];
    foreach($all as $t){ if($t !== 'All') $fixed[strtolower($t)] = $t; }
    $label = function($low) use ($fixed){ return $fixed[$low] ?? ucfirst($low); };

    $sorted = ['All']; $seen = ['all' => 1]; $trending = [];

    foreach($fav as $low){
        if(isset($seen[$low])) continue;
        $seen[$low] = 1; $sorted[] = $label($low);
    }
    foreach(dz_trending_tags($conn) as $low){
        if(isset($seen[$low])) continue;
        $seen[$low] = 1; $sorted[] = $label($low); $trending[] = $low;
    }
    foreach($all as $t){
        $low = strtolower($t);
        if(isset($seen[$low])) continue;
        $seen[$low] = 1; $sorted[] = $t;
    }
    return ['sorted' => $sorted, 'lower' => $fav, 'trending' => $trending];
}

/* the bar's CSS (printed once per page)
   Colours: var(--fg) is the R,G,B tint of the theme (white in dark mode, navy in light mode);
   var(--ui-text) / var(--ui-sub2) are the light-mode text colours. The value after the comma is the dark-mode colour. */
function dz_hashtags_styles(){
    static $done = false; if($done) return; $done = true;
    echo "<style>\n" . <<<'DZCSS'
/* HASHTAG BAR */
.tag-bar-wrap{display:flex;align-items:center;gap:6px;padding:0 calc(clamp(6px,1vw + 1px,10px) * var(--ccm-s,1));width:100%}
.tag-scroll-btn-desktop{width:32px;height:32px;border-radius:8px;padding:0;border:1px solid rgba(var(--fg,255,255,255),.12);background:rgba(var(--fg,255,255,255),.05);color:var(--ui-text,#fff);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:.2s}
.tag-scroll-btn-desktop i{display:block;font-size:22px;line-height:1}
.tag-scroll-btn-desktop:hover{background:rgba(255,45,45,.7);border-color:transparent;color:#fff}
.tag-scrollbar-wrapper{flex:1;min-width:0;overflow:hidden}
.tag-scrollbar{display:flex;gap:8px;padding:8px 4px 12px;overflow-x:auto;white-space:nowrap;scrollbar-width:none;-ms-overflow-style:none;-webkit-overflow-scrolling:touch}
.tag-scrollbar::-webkit-scrollbar{display:none}
.tag-pill{
    flex-shrink:0;display:inline-flex;align-items:center;border-radius:5.5px;
    padding:calc(clamp(4.5px,1.5vw - 1.4px,6px) * var(--ccm-s,1)) calc(clamp(10px,3vw - 1.7px,13px) * var(--ccm-s,1));
    background:rgba(var(--fg,255,255,255),.07);border:none;color:var(--ui-sub2,rgba(255,255,255,.8));
    font-size:calc(clamp(.65rem,1.44vw + 4.8px,.74rem) * var(--ccm-s,1));font-weight:500;letter-spacing:.2px;line-height:1.3;
    white-space:nowrap;text-decoration:none;transition:all .2s;-webkit-tap-highlight-color:transparent;
}
.tag-pill:hover{background:rgba(var(--fg,255,255,255),.12);color:var(--ui-text,#fff)}
.tag-pill.trending:not(.active){background:rgba(255,45,45,.13);color:var(--ui-text,#fff)}
.tag-pill.trending:not(.active):hover{background:rgba(255,45,45,.24)}
.tag-pill.active{background:var(--red,#FF2D2D);color:#000;font-weight:600}
@media(max-width:768px){.tag-bar-wrap{--ccm-s:1.1}.tag-scroll-btn-desktop{display:none!important}}
DZCSS
    . "\n</style>\n";
}

/* the bar itself */
function dz_hashtag_bar($tag, $filter, $ht){
    $sorted   = $ht['sorted'];
    $lower    = $ht['lower'];
    $trending = $ht['trending'] ?? [];
    $cur      = strtolower((string)$tag);
    echo '<div class="tag-bar-wrap">';
    echo '<button type="button" class="tag-scroll-btn-desktop" id="tagLeft" aria-label="Scroll tags left"><i class="ri-arrow-left-s-line"></i></button>';
    echo '<div class="tag-scrollbar-wrapper"><div class="tag-scrollbar" id="tagScrollbar">';
    foreach($sorted as $t){
        $low      = strtolower($t);
        $isActive = ($t === 'All') ? ($cur === 'all') : ($cur === $low);
        $isAff    = in_array($low, $lower, true);
        $isTrend  = in_array($low, $trending, true);
        $cls = 'tag-pill' . ($isActive ? ' active' : '') . ($isAff && !$isActive ? ' user-affinity' : '') . ($isTrend && !$isActive ? ' trending' : '');
        echo '<a href="' . htmlspecialchars(hpUrl($filter, $t === 'All' ? 'all' : $t)) . '" class="' . $cls . '">' . ($t === 'All' ? 'All' : '#' . htmlspecialchars($t)) . '</a>';
    }
    echo '</div></div>';
    echo '<button type="button" class="tag-scroll-btn-desktop" id="tagRight" aria-label="Scroll tags right"><i class="ri-arrow-right-s-line"></i></button>';
    echo '</div>';
}

/* the bar's behaviour: arrow buttons + scroll the active tag into view (printed once per page) */
function dz_hashtags_script(){
    static $done = false; if($done) return; $done = true;
    echo "<script>\n" . <<<'DZJS'
(function(){
    var b=document.getElementById('tagScrollbar'), l=document.getElementById('tagLeft'), r=document.getElementById('tagRight');
    if(l&&r&&b){ l.addEventListener('click',function(){ b.scrollBy({left:-200,behavior:'smooth'}); }); r.addEventListener('click',function(){ b.scrollBy({left:200,behavior:'smooth'}); }); }
    var a=b&&b.querySelector('.tag-pill.active'); if(a) b.scrollLeft=Math.max(0,a.offsetLeft-60);
})();
DZJS
    . "\n</script>\n";
}