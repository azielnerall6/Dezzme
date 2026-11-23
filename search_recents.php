<?php
session_start();
require 'config.php';
require 'ml_client.php';

$uid    = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$action = $_GET['action'] ?? '';

header('Content-Type: application/json');

/* ════════════════════════════════════════
   ONE-TIME SCHEMA CHECK
   Older installs may have `type` as an ENUM, which would reject
   the new 'page' type. Convert it to VARCHAR once per session.
════════════════════════════════════════ */
if (empty($_SESSION['sr_schema_v2'])) {
    try {
        $col = $conn->query("SHOW COLUMNS FROM search_recents LIKE 'type'");
        if ($col && ($c = $col->fetch_assoc()) && stripos((string)$c['Type'], 'enum') === 0) {
            $conn->query("ALTER TABLE search_recents MODIFY `type` VARCHAR(30) NOT NULL");
        }
    } catch (Throwable $e) {}
    $_SESSION['sr_schema_v2'] = 1;
}

/* every item type the search modal can save */
$ALLOWED_TYPES = [
    'profile_id', 'user', 'page', 'group', 'meme',
    'collection', 'sound', 'sound_collection', 'memepedia', 'query'
];

/* ════════════════════════════════════════
   GET RECENTS
════════════════════════════════════════ */
if ($action === 'get') {
    if (!$uid) { echo '[]'; exit; }

    $stmt = $conn->prepare(
        "SELECT data FROM search_recents
         WHERE user_id = ? ORDER BY updated_at DESC LIMIT 15"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = $stmt->get_result();

    $out = [];
    if ($rows) {
        while ($r = $rows->fetch_assoc()) {
            $decoded = json_decode($r['data'], true);
            if ($decoded) $out[] = $decoded;
        }
    }
    $stmt->close();

    /* ── refresh saved PAGES from the database (renames / new pic / deleted) ── */
    $pageIds = [];
    foreach ($out as $it) {
        if (($it['type'] ?? '') === 'page' && isset($it['id'])) $pageIds[(int)$it['id']] = true;
    }
    if (!empty($pageIds)) {
        $idList  = implode(',', array_map('intval', array_keys($pageIds)));
        $pageMap = [];
        try {
            $pq = $conn->query("SELECT id, name, category, pic FROM profile_pages WHERE id IN ($idList)");
            if ($pq) while ($p = $pq->fetch_assoc()) $pageMap[(int)$p['id']] = $p;
        } catch (Throwable $e) { $pageMap = null; }

        if (is_array($pageMap)) {
            $fresh = [];
            foreach ($out as $it) {
                if (($it['type'] ?? '') === 'page') {
                    $pid = (int)($it['id'] ?? 0);
                    if (!isset($pageMap[$pid])) continue;          // page was deleted — drop it
                    $it['name']     = $pageMap[$pid]['name'];
                    $it['category'] = $pageMap[$pid]['category'];
                    $it['pic']      = $pageMap[$pid]['pic'];
                }
                $fresh[] = $it;
            }
            $out = $fresh;
        }
    }

    echo json_encode($out);
    exit;
}

/* ════════════════════════════════════════
   ADD RECENT
════════════════════════════════════════ */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$uid) { http_response_code(401); echo '{"error":"unauthorized"}'; exit; }

    /* ── Rate limit: max 30 adds per user per minute ── */
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM search_user_signals
         WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rl = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)($rl['cnt'] ?? 0) > 30) {
        http_response_code(429);
        echo '{"error":"rate_limited"}';
        exit;
    }

    $raw  = file_get_contents('php://input');
    $item = json_decode($raw, true);
    if (!$item || empty($item['type']) || empty($item['id'])) {
        http_response_code(400); echo '{"error":"bad_request"}'; exit;
    }

    if (!in_array($item['type'], $ALLOWED_TYPES, true)) {
        http_response_code(400); echo '{"error":"bad_type"}'; exit;
    }

    /* ── PAGE: never trust the browser — rebuild the item from the database ── */
    if ($item['type'] === 'page') {
        $pgId = (int)$item['id'];
        $pst  = $conn->prepare("SELECT id, name, category, pic FROM profile_pages WHERE id = ? LIMIT 1");
        $pst->bind_param('i', $pgId);
        $pst->execute();
        $pg = $pst->get_result()->fetch_assoc();
        $pst->close();
        if (!$pg) { http_response_code(400); echo '{"error":"page_not_found"}'; exit; }

        $item = [
            'type'     => 'page',
            'id'       => (int)$pg['id'],
            'name'     => $pg['name'],
            'category' => $pg['category'],
            'pic'      => $pg['pic'],
        ];
    }

    $type    = $item['type'];
    $rid     = (string)$item['id'];
    $dataStr = json_encode($item);

    /* Upsert into search_recents */
    $stmt = $conn->prepare(
        "INSERT INTO search_recents (user_id, type, ref_id, data, updated_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()"
    );
    $stmt->bind_param('isss', $uid, $type, $rid, $dataStr);
    $stmt->execute();
    $stmt->close();

    /* Log search signal + update trending */
    if ($item['type'] === 'query' && !empty($item['query'])) {
        $q = strtolower(trim($item['query']));

        /* global query counter */
        $stmt = $conn->prepare(
            "INSERT INTO search_queries (query_text, total_search_count, last_seen)
             VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE total_search_count = total_search_count + 1, last_seen = NOW()"
        );
        $stmt->bind_param('s', $q);
        $stmt->execute();
        $stmt->close();

        /* per-user signal */
        $weight = 1.0;
        $stype  = 'search';
        $stmt   = $conn->prepare(
            "INSERT INTO search_user_signals (user_id, signal_type, signal_value, weight, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('issd', $uid, $stype, $q, $weight);
        $stmt->execute();
        $stmt->close();

        /* trending velocity — subquery in ON DUPLICATE needs alias workaround */
        $stmt = $conn->prepare(
            "INSERT INTO search_trending (query_text, velocity_score, time_window, spike_flag, updated_at)
             VALUES (?,
                (SELECT COUNT(*) FROM search_user_signals s1
                 WHERE s1.signal_type = 'search' AND s1.signal_value = ?
                 AND s1.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)),
             '1h', 0, NOW())
             ON DUPLICATE KEY UPDATE
                velocity_score = (SELECT COUNT(*) FROM search_user_signals s2
                                  WHERE s2.signal_type = 'search' AND s2.signal_value = ?
                                  AND s2.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)),
                updated_at = NOW()"
        );
        $stmt->bind_param('sss', $q, $q, $q);
        $stmt->execute();
        $stmt->close();
    }

    echo '{"ok":1}';
    exit;
}

/* ════════════════════════════════════════
   REMOVE ONE RECENT
════════════════════════════════════════ */
if ($action === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$uid) { http_response_code(401); echo '{"error":"unauthorized"}'; exit; }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $type = $body['type'] ?? '';
    $rid  = (string)($body['id'] ?? '');

    $stmt = $conn->prepare(
        "DELETE FROM search_recents WHERE user_id = ? AND type = ? AND ref_id = ?"
    );
    $stmt->bind_param('iss', $uid, $type, $rid);
    $stmt->execute();
    $stmt->close();

    echo '{"ok":1}';
    exit;
}

/* ════════════════════════════════════════
   CLEAR ALL RECENTS
════════════════════════════════════════ */
if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$uid) { http_response_code(401); echo '{"error":"unauthorized"}'; exit; }

    $stmt = $conn->prepare("DELETE FROM search_recents WHERE user_id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();

    echo '{"ok":1}';
    exit;
}

/* ════════════════════════════════════════
   SMART SUGGESTIONS  (TikTok-style ranking)
════════════════════════════════════════ */
if ($action === 'suggestions') {

    $limit = 8;

    /* ── 1. Personalization signals ── */
    $personalTags  = [];
    $personalWords = [];
    if ($uid) {
        $stmt = $conn->prepare(
            "SELECT signal_value, COUNT(*) as cnt
             FROM search_user_signals
             WHERE user_id = ? AND signal_type = 'search'
             GROUP BY signal_value ORDER BY cnt DESC LIMIT 100"
        );
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $rows = $stmt->get_result();
        if ($rows) {
            while ($r = $rows->fetch_assoc()) {
                $q = $r['signal_value'];
                $c = (int)$r['cnt'];
                preg_match_all('/#(\w+)/u', $q, $m);
                foreach ($m[1] as $h) $personalTags[strtolower($h)] = ($personalTags[strtolower($h)] ?? 0) + $c;
                foreach (explode(' ', $q) as $w) {
                    $w = trim(strtolower($w), '#');
                    if (strlen($w) > 2) $personalWords[$w] = ($personalWords[$w] ?? 0) + $c;
                }
            }
        }
        $stmt->close();
        arsort($personalTags);
        arsort($personalWords);
    }

    /* ── 2. Trending velocity ── */
    $trendRows = $conn->query(
        "SELECT query_text, velocity_score FROM search_trending
         WHERE time_window='1h' ORDER BY velocity_score DESC LIMIT 30"
    );
    $trendMap = [];
    if ($trendRows) {
        while ($r = $trendRows->fetch_assoc()) {
            $trendMap[strtolower($r['query_text'])] = (float)$r['velocity_score'];
        }
    }

    /* ── 3. Global popularity ── */
    $popRows = $conn->query(
        "SELECT query_text, total_search_count, click_through_rate, bounce_rate
         FROM search_queries ORDER BY total_search_count DESC LIMIT 50"
    );
    $maxCount = 1;
    $popMap   = [];
    if ($popRows) {
        while ($r = $popRows->fetch_assoc()) {
            $key          = strtolower($r['query_text']);
            $popMap[$key] = $r;
            if ((int)$r['total_search_count'] > $maxCount) $maxCount = (int)$r['total_search_count'];
        }
    }

    /* ── 4. Trending hashtags from captions ── */
    $captionRows = $conn->query(
        "SELECT caption FROM memes WHERE caption LIKE '%#%'
         ORDER BY views DESC, created_at DESC LIMIT 300"
    );
    $captionTags = [];
    if ($captionRows) {
        while ($r = $captionRows->fetch_assoc()) {
            preg_match_all('/#(\w+)/u', $r['caption'], $m);
            foreach ($m[1] as $h) {
                $h = strtolower($h);
                $captionTags[$h] = ($captionTags[$h] ?? 0) + 1;
            }
        }
    }
    $maxCaption = max(1, !empty($captionTags) ? max(array_values($captionTags)) : 1);

    /* ── 5. Semantic expansion map (PHP fallback only — Python /similar takes priority) ── */
    $semanticMap = [
        // =========================================================
        // HUMOR & COMEDY — COMPLETE EDITION
        // =========================================================
        'funny'             => ['hilarious','lol','comedy','humor','laughing','lmao','lmfao','rofl','dead','jokes','stand up','skit','prank','fail','blooper','cringe','wholesome funny','funny af','crying laughing','sent me','i cant','im dead','💀','😭😭','not me laughing'],
        'meme'              => ['memes','dank memes','meme templates','viral memes','funny memes','meme dump','meme compilation','rare memes','cursed memes','blessed memes','surreal memes','deep fried memes','ironic memes','shitpost','normie memes','dank','spicy memes','fresh memes','stale memes','meme review','meme page','meme account'],
        'relatable'         => ['so true','real life','mood','facts','this is me','same','literally me','no cap','tell me why','who else','i feel attacked','too real','irl','adulting','story of my life','not just me','raises hand','i felt that','understood the assignment'],
        'dark'              => ['dark humor','edgy','offensive memes','black comedy','gallows humor','twisted humor','taboo jokes','dark memes','too dark','going to hell','too soon','macabre humor','morbid humor','dead baby jokes','necro humor'],
        'sarcasm'           => ['sarcastic','irony','ironic','deadpan','dry humor','eye roll','obviously','sure jan','ok boomer','wow thanks','great job','fantastic','wow cool','yeah right','totally','as if','whatever you say'],
        'cringe'            => ['cringeworthy','awkward','embarrassing','secondhand embarrassment','yikes','bruh moment','facepalm','oof','big oof','cringe compilation','cringe attack','yikes on bikes','that face you make','i want to die'],
        'wholesome'         => ['wholesome','heartwarming','cute','sweet','pure','feel good','uplifting','positive vibes','made my day','faith in humanity','wholesome memes','dog tax','good boy','good girl','unexpected wholesome','uwu'],
        'chaos'             => ['chaotic','unhinged','feral','goblin mode','brain rot','chronically online','terminally online','chaotic energy','menace','absolute unit','unwell','not okay','feral behavior','no thoughts head empty','living in my head'],
        // ANIMAL MEMES
        'animal_memes'      => ['animal memes','funny animals','cute animals','animal fails','pets being idiots','derpy animals','animal humor'],
        'dog_memes'         => ['dog memes','doggo','pupper','woofer','floofer','good boy','good girl','bork','boof','henlo fren','doing me a frighten','much wow','so doge','such meme','very dog','doge meme','cheems','walter dog','gabe the dog','vibing cat dog','dog tax','dog being a menace','my dog is broken','dogs are dumb and i love them','hes a rescue','adopt dont shop meme'],
        'cat_memes'         => ['cat memes','grumpy cat','lolcats','ceiling cat','business cat','cat tax','monorail cat','longcat','i can has cheezburger','keyboard cat','nyan cat','this is fine cat','surprised pikachu cat','cat loaf','cat kneading','judgy cat','cat stare','cat knocking things off','cats are jerks','if i fits i sits','cat logic','vibing cat','cat jam','maxwell the cat','happy cat','sad cat meme','polite cat','cat threatening meme','angry cat pointing','woman yelling at cat','blep','mlem','boop the snoot','smol','absolute unit cat','chunky cat','orange cat behavior','black cat energy','tuxedo cat','void cat'],
        'dog_cat_war'       => ['dogs vs cats','cat people vs dog people','dog person','cat person'],
        'other_animal_memes'=> ['raccoon memes','trash panda','crime raccoon','raccoon eating','rat memes','ratatouille memes','frog meme','kermit meme','pepe the frog','feels good man','sad pepe','frog and toad','bird memes','bird is the word','bird culture','two birds arguing','surprised bird','screaming bird','duck memes','duck face','duck hunt','goose memes','untitled goose game meme','menacing goose','horse memes','horse head','horse girl','surprised horse','otter memes','hold hands otter','sea otter','capybara memes','chill capybara','capybara with fruit','fox memes','what does the fox say','fennec fox','red panda memes','bear memes','bear witness','honey badger','honey badger dont care','squirrel memes','dramatic chipmunk','hamster memes','hamster wheel','hamster spinning','quokka memes','quokka selfie','axolotl memes','tardigrade memes','shrimp memes','shrimp heaven now','snail memes','slugs with hats','frog with hat','animals with hats','animals in clothes','lizard memes','chameleon memes','gecko memes','snake memes','danger noodle','nope rope','fluffy snake','cow memes','baby cow','moo deng','hippo memes','hippo chomp','giraffe memes','penguin memes','penguin fall','panda memes','red panda being clumsy','elephant memes','elephant memory','dolphin memes','dolphin chaos','whale memes','blahaj shark','seal memes','seal of approval','seal clapping','walrus memes','chubby walrus'],
        // SCHOOL & EDUCATION MEMES
        'school_memes'      => ['school memes','student memes','back to school','homework memes','exam memes','teacher memes','classroom memes','school struggle','academic validation','school life','school feels'],
        'student_life'      => ['student life','broke student','college memes','uni memes','university memes','campus life','dorm life','student debt memes','pulling an all nighter','finals week','exam season','studying memes','procrastinating','due at midnight','submitted at 11:59','academic validation','overachiever','underachiever','grade grubbing','extra credit','group project memes','group chat silent then chaos','that one smart kid','class participation','attendance meme','absent meme','skipping class','online class memes','zoom university','camera off zoom','muted on zoom'],
        'teacher_memes'     => ['teacher humor','teacher memes','teacher life','teacher vs student','that teacher who','strict teacher','favorite teacher','substitute teacher memes','sub chaos','cover teacher','supply teacher','essay memes','grading papers','red pen','teacher tired','marking memes'],
        'exam_memes'        => ['exam memes','finals memes','test anxiety','open book exam','multiple choice memes','essay question memes','blank answer sheet','all of the above','none of the above','i studied the wrong chapter','brain empty during exam','exam season depression','post exam trauma','grade reveal','failed meme'],
        'homework_memes'    => ['homework memes','i forgot to do my homework','homework due tomorrow','last minute homework','copy homework memes','calculator memes','show your work','math memes','word problems','science fair memes','history homework','essay writing memes','thesis memes','dissertation memes','bibliography memes'],
        // WORK & OFFICE MEMES
        'work_memes'        => ['work memes','office memes','coworker memes','monday memes','corporate humor','9 to 5 memes','hustle culture memes','work life balance','job memes'],
        'monday_memes'      => ['monday memes','i hate mondays','monday morning','case of the mondays','monday energy','garfield monday','monday motivation fake','back to work','sunday scaries','sunday dread'],
        'office_life'       => ['office humor','office memes','coworker memes','toxic coworker','that one coworker','office drama','office kitchen','passive aggressive note','meeting memes','zoom meeting memes','camera on meme','microphone muted','conference call bingo','reply all memes','cc the whole company','corporate email','corporate speak','synergy memes','circle back memes','moving the needle','per my last email','as per my previous email','touch base','bandwidth memes','pivot','deliverables','stakeholder memes'],
        'work_struggle'     => ['work from home memes','wfh memes','remote work memes','hybrid work memes','open office memes','cubicle life','boss memes','micromanager memes','performance review memes','raise memes','underpaid','unpaid overtime','quiet quitting','loud quitting','great resignation memes','job hunting memes','resume memes','linkedin memes','recruiter memes','job interview memes','hired meme','fired meme','layoff memes','corporate layoff'],
        'retail_work'       => ['retail memes','customer service memes','karen memes','the customer is always right meme','retail worker tired','service with a smile','retail therapy','cashier memes','manager memes','call the manager','black friday memes'],
        'food_service'      => ['restaurant memes','waiter memes','chef memes','kitchen memes','line cook life','expo memes','brunch memes','tipping memes','order wrong','table 7','front of house back of house'],
        // INTERNET & ONLINE CULTURE
        'internet_humor'    => ['internet humor','internet culture','extremely online','main character syndrome','npc behavior','npc meme','side quest','tutorial hell','404 humor','loading memes','buffering memes','lag memes','internet explorer memes','windows xp memes','blue screen of death','this is fine','computer broke'],
        'social_media_memes'=> ['social media memes','instagram vs reality','twitter memes','x memes','facebook memes','facebook boomer memes','facebook mom','linkedin memes','tiktok memes','youtube memes','reddit memes','reddit moment','redditor memes','discord memes','discord kitten','telegram memes','whatsapp memes','group chat memes','left on read','seen zone','typing indicator','three dots memes'],
        'gaming_memes'      => ['gaming memes','gamer humor','pc master race','console wars','xbox vs ps5','nintendo memes','mobile gaming memes','pay to win','game lag','respawn memes','rage quit','trash talk','git gud','noob','sweaty gamer','speedrun memes','glitch memes','open world memes','loading screen memes','tutorial memes','final boss memes','dark souls memes','minecraft memes','fortnite memes','roblox memes','among us memes','fall guys','gaming chair','gaming setup','streamer memes','twitch memes','stream sniping'],
        'tech_memes'        => ['tech memes','developer memes','programmer humor','code memes','it memes','it crowd','have you tried turning it off','stack overflow memes','github memes','merge conflict','production is down','bug memes','its not a bug its a feature','deadline memes','agile memes','scrum memes','startup memes','silicon valley memes','crypto memes','nft memes','ai memes','chatgpt memes','prompt engineering jokes'],
        // FOOD MEMES
        'food_memes'        => ['food memes','foodie humor','cooking memes','kitchen fails','baking memes','recipe memes','food fails','hungry memes','midnight snack','hangry memes','eating memes','diet memes'],
        'cooking_fails'     => ['cooking fails','kitchen disaster','burnt food','i cant cook','first time cooking','mom recipe','no measurement cooking','eyeballing it','chef kiss','gordon ramsay memes','hell kitchen memes','baking gone wrong','sourdough memes','instant pot memes','air fryer memes'],
        'food_culture'      => ['pizza memes','pizza debates','pineapple on pizza','burger memes','fast food memes','mcdonalds memes','wendys memes','taco bell memes','subway memes','starbucks memes','coffee memes','coffee addict','tea vs coffee','cereal memes','cereal before milk','soup memes','salad memes','snack memes','junk food memes','healthy eating memes','diet culture memes','cheat day'],
        // RELATIONSHIP & DATING MEMES
        'relationship_memes'=> ['relationship memes','couple memes','dating memes','single memes','situationship','talking stage','love memes','breakup memes','heartbreak memes','ex memes'],
        'dating_app_memes'  => ['tinder memes','bumble memes','hinge memes','dating app fails','match memes','ghosting memes','left swipe','right swipe','its a match','super like','bio memes','profile pic vs real life','catfish memes','sliding into dms','dm memes'],
        'single_life'       => ['single memes','forever alone','single pringle','valentine single','dying alone memes','talking to myself','third wheel','self love memes','self date','independent memes','commitment issues memes'],
        'couple_humor'      => ['couple memes','relationship goals memes','my partner memes','arguing memes','silent treatment','forgetting anniversary','making up memes','proposal memes','wedding memes','marriage memes','husband wife memes','wifey memes','hubby memes'],
        // FAMILY & PARENTING MEMES
        'family_memes'      => ['family memes','parent memes','kids memes','sibling memes','family dinner','family group chat','holiday family','family dysfunction memes'],
        'parenting_memes'   => ['parenting memes','mom memes','dad memes','dad jokes','tired parent','toddler memes','baby memes','new parent memes','sleep deprivation parent','mommy wine memes','dad nap','helicopter parent memes','sports parent','school pickup memes','screen time memes'],
        'sibling_memes'     => ['sibling memes','older sibling','younger sibling','middle child memes','only child memes','sibling rivalry','sibling roast','grew up with siblings','brother memes','sister memes','sibling bond'],
        'boomer_humor'      => ['ok boomer','boomer memes','boomer humor','back in my day','kids these days','millennials memes','gen z memes','gen x memes','generational humor','facebook boomer','avocado toast memes','participation trophy memes'],
        // AGE & GENERATION MEMES
        'gen_z_humor'       => ['gen z memes','gen z humor','gen z slang','rizz','no cap','bussin','slay','understood the assignment','living rent free','not the','this lowkey','understood','caught in 4k','ratio','ok and','touch grass','chronically online','main character','delulu','cheugy','vibe check','slay queen','periodt','and thats on periodt','rent free','no thoughts','down bad','understood the assignment'],
        'millennial_humor'  => ['millennial memes','millennial humor','harry potter references','disney adult','side part','skinny jeans','buzzfeed era','which character are you','this is the way','adulting','late stage capitalism memes','therapy memes','anxiety memes','student loans forever','housing crisis memes','avocado toast'],
        'gen_x_humor'       => ['gen x memes','gen x humor','latchkey kid','mtv generation','90s nostalgia','forgotten generation memes'],
        'zoomer_vs_millennial' => ['gen z vs millennial','generational war memes','cringe millennial','cringe gen z','ok boomer vs millennial','millennials killing industries'],
        // MENTAL HEALTH HUMOR
        'anxiety_memes'     => ['anxiety memes','social anxiety memes','overthinking memes','intrusive thoughts','brain worms','what if','worst case scenario','doom scrolling','spiral memes','3am thoughts','existential dread','anxiety goblin','catastrophizing'],
        'depression_memes'  => ['depression memes','sad memes','relatable sad','no motivation','bed rotting','staying in bed','cant get up','send help','coping humor','dark relatable','therapist memes','therapy memes','its fine everything is fine'],
        'adhd_memes'        => ['adhd memes','adhd humor','squirrel memes','hyperfocus','executive dysfunction','task paralysis','adhd tax','lost keys again','forgot what i was doing','too many tabs open','adhd brain'],
        'introvert_memes'   => ['introvert memes','introvert humor','social battery','drained','people exhausting','cancelled plans happiness','staying in','homebody','no thanks to going out','recharge alone','anti social memes'],
        // NICHE & ABSURDIST
        'absurdist'         => ['absurdist memes','surreal memes','weird memes','bizarre humor','non sequitur','random humor','unexpected','out of context','fever dream memes','liminal space memes','backrooms memes','analog horror'],
        'anti_humor'        => ['anti humor','anti joke','whats brown and sticky','subverted expectations','humor so dry','deadpan anti humor','unfunny on purpose'],
        'meta_humor'        => ['meta memes','memes about memes','recursive meme','self aware meme','breaking the fourth wall','the meme itself','meme within a meme'],
        'shitpost'          => ['shitpost','quality shitpost','low effort meme','ms paint meme','jpeg artifact meme','blurry meme','compressed to death','deep fried','eye sore meme','noise meme'],
        'cursed'            => ['cursed memes','cursed images','blessed cursed','unholy combination','why does this exist','brain hurts','unsee button','cursed content','disturbing but funny'],
        'wholesome_cursed'  => ['wholesome cursed','sweet but wrong','nice but weird','oddly wholesome','unexpectedly heartwarming','chaotic good'],
        // SPORTS MEMES
        'sports_memes'      => ['sports memes','athlete memes','sports fails','sports wins','sports twitter','sports humor','referee memes','penalty memes','offside memes','sports commentary memes'],
        'football_memes'    => ['football memes','soccer memes','premier league memes','champions league memes','world cup memes','ronaldo vs messi','cr7 memes','messi memes','penalty miss','goalkeeper memes','manager touchline memes'],
        'american_sports'   => ['nfl memes','nba memes','mlb memes','nhl memes','super bowl memes','march madness memes','fantasy football memes','sports gambling memes'],
        // LIFESTYLE MEMES
        'gym_memes'         => ['gym memes','fitness memes','gym humor','leg day memes','gym bro memes','gains','do you even lift','protein memes','pre workout memes','cardio memes','running memes','yoga memes','crossfit memes','gym selfie memes','gym rat','new year gym','gym in january','resolution failed'],
        'fashion_memes'     => ['fashion memes','outfit memes','drip memes','fits memes','fashion fails','crocs memes','socks and sandals','dad fashion','girl dinner outfit','clean girl aesthetic','mob wife aesthetic','fashion week memes'],
        'travel_memes'      => ['travel memes','airport memes','flight memes','middle seat','lost luggage','passport memes','tourist memes','travel fails','vacation memes','holiday memes','airbnb memes','hotel memes','hostel memes'],
        'money_memes'       => ['broke memes','no money memes','payday memes','rent memes','bills memes','inflation memes','cost of living memes','grocery prices memes','minimum wage memes','rich vs poor memes','spending money memes','saving money memes','bank account empty'],
        'car_memes'         => ['car memes','driving memes','traffic memes','road rage memes','parking memes','gas prices memes','car broke down','mechanic bill memes','honda civic memes','beater car memes','luxury car memes'],
        'weather_memes'     => ['weather memes','rain memes','snow memes','heatwave memes','british weather memes','winter memes','summer memes','seasonal depression memes','cloudy monday memes'],
        // POP CULTURE
        'movie_memes'       => ['movie memes','film memes','cinema memes','blockbuster memes','plot hole memes','movie logic','villain memes','protagonist memes','movie quotes memes','oscars memes','box office memes'],
        'tv_memes'          => ['tv show memes','series memes','binge watching memes','cliffhanger memes','cancelled show memes','pilot vs finale memes','character death memes','plot twist memes','recap memes'],
        'music_memes'       => ['music memes','song memes','lyrics memes','artist memes','concert memes','spotify memes','playlist memes','music taste memes','aux cord memes','misheard lyrics','earworm memes'],
        'celebrity_memes'   => ['celebrity memes','celebrity drama','celebrity fail','award show memes','red carpet memes','celebrity tweet memes','celebrity apology memes'],
        // CLASSIC & FORMAT MEMES
        'classic_memes'     => ['classic memes','og memes','vintage memes','early internet memes','2000s memes','2010s memes','rage comics','trollface','forever alone','me gusta','feels bad man','feels good man','y u no','one does not simply','i has a bucket','ceiling cat','long cat','rickroll','rick astley meme','numa numa','dramatic chipmunk','star wars kid','numa numa guy','all your base','keyboard cat','nyan cat','double rainbow','the cake is a lie','do a barrel roll','charlie bit my finger','leave britney alone','over 9000','this is sparta','im the juggernaut','numa','all star shrek','shrek memes'],
        'format_memes'      => ['drake meme','distracted boyfriend','two buttons','change my mind','is this a pigeon','galaxy brain','expanding brain','gru plan','stonks','not stonks','they re the same picture','this is fine','bernie mittens','woman yelling at cat','pointing spider man','coffin dance','surprised pikachu','i see this as an absolute win','thanos perfectly balanced','uno reverse','uno draw 25','they had us in the first half','press x to doubt','gigachad','chad meme','virgin vs chad','when the imposter is sus','bond in heaven','cat sitting','blinking white guy','guy at computer girlfriend','hide the pain harold','arthur fist','kermit sipping tea','none of my business','that wasnt very cash money','be like bill','overly attached girlfriend','success kid','first world problems','bad luck brian','scumbag steve','good guy greg'],
        // MISC NICHE
        'plant_memes'       => ['plant memes','plant parent','houseplant memes','succulent memes','plant lady memes','over watering','under watering','talking to plants','plant collection'],
        'astrology_memes'   => ['astrology memes','zodiac memes','mercury retrograde','scorpio memes','gemini memes','aries memes','virgo memes','cancer zodiac memes','leo memes','aquarius memes','sagittarius memes','capricorn memes','taurus memes','libra memes','pisces memes','not like other signs'],
        'history_memes'     => ['history memes','historical memes','medieval memes','ancient memes','war memes','history teacher memes','history buff memes','alternate history memes','if history was a game','cursed historical photos memes'],
        'science_memes'     => ['science memes','chemistry memes','physics memes','biology memes','math memes','engineering memes','lab memes','scientist memes','peer review memes','research memes','grad student memes','phd memes','academia memes'],
        'philosophy_memes'  => ['philosophy memes','existential memes','nihilism memes','stoic memes','socrates meme','plato meme','big brain memes','galaxy brain','thonking','galaxy brain thinking','deep thought memes'],
        'language_memes'    => ['language memes','grammar memes','spelling memes','translator memes','language learning memes','lost in translation','english is weird memes','multilingual memes','accent memes','linguistic memes'],
        'sleepy_memes'      => ['sleep memes','tired memes','insomnia memes','3am memes','nap memes','oversleeping memes','alarm memes','snooze button memes','bed rotting memes','cozy memes','hibernating memes'],
        'morning_memes'     => ['morning person memes','not a morning person','coffee needed','before coffee after coffee','morning routine memes','waking up memes'],
        'night_owl_memes'   => ['night owl memes','staying up late','productive at midnight','second wind memes','2am energy','3am rabbit hole'],
        'food_at_night'     => ['midnight snack memes','3am eating','fridge raiding memes','cereal at midnight','eating feelings memes'],
        'age_memes'         => ['getting older memes','birthday memes','aging memes','back in my day','knees hurt memes','cant stay up late anymore','hangover at 30 memes','adulting is hard','responsibilities memes'],
        'pandemic_humor'    => ['covid memes','quarantine memes','lockdown memes','zoom fatigue','mask memes','pandemic memes','2020 memes','social distancing memes','hand sanitizer memes','toilet paper memes'],
        'election_memes'    => ['election memes','voting memes','political memes','debate memes','campaign memes','ballot memes','democracy memes'],
        'holiday_memes'     => ['christmas memes','holiday memes','thanksgiving memes','halloween memes','new year memes','valentines memes','easter memes','diwali memes','eid memes','hanukkah memes','holiday stress memes','gift giving memes','family holiday memes'],
        'random_internet'   => ['404','this page does not exist','glitch in the matrix','simulation memes','we live in a society','its giving','understood the assignment','the audacity','hold up','wait a minute','sir this is a wendys','no sir','pressed','unhinged replies','ratio','out of pocket','dont at me','unpopular opinion','hot take','allegedly','it be like that','and thats on','period','slay','ate and left no crumbs','left no crumbs','mother','understood she ate','understood the assignment','the bar is on the floor','setting the bar','touch grass','log off'],
        // VIRAL & TRENDS
        'viral'             => ['trending','popular','fyp','blowup','for you page','explore page','going viral','viral moment','blew up','ratio','main character'],
        'trending'          => ['whats trending','hot right now','top trending','trend alert','new trend','challenge','tiktok trend','twitter trending'],
        'aesthetic'         => ['aesthetics','vibes','cottagecore','darkacademia','y2k','retro aesthetic','vaporwave','lofi','coquette','indie','alt aesthetic'],
        // =========================================================
        // AFRICA
        // =========================================================
        'africa'         => ['african','africa memes','african parents','african household','growing up african','african culture','ubuntu','motherland','diaspora','african twitter'],
        'nigeria'        => ['naija','nigerian','afrobeats','afrobeatz','japa','nigerian memes','nigerian parents','omo','shey','wahala','naija twitter','nollywood','aso ebi','owambe','amala','suya','lagosian','abuja','yoruba','igbo','hausa','9ja'],
        'ghana'          => ['ghanaian','ghana memes','accra','kumasi','twi','akan','jollof wars','ghana jollof','kente','waakye','kelewele','ghanaian parents','charley'],
        'kenya'          => ['kenyan','nairobi','swahili','sheng','kenyan memes','ugali','nyama choma','safaricom','mpesa','kenyan parents','bana','aki','mkenya'],
        'south_africa'   => ['south african','joburg','cape town','durban','zulu','xhosa','afrikaans','load shedding','braai','biltong','sa memes','mzansi','yoh','eish','lekker','sharp sharp','howzit'],
        'ethiopia'       => ['ethiopian','addis ababa','amharic','habesha','injera','tej','tigrinja','oromo','coffee ceremony','ethiopian memes','selam','betam','wot'],
        'tanzania'       => ['tanzanian','dar es salaam','dodoma','swahili','zanzibar','ugali','tanzanian memes','mambo','poa','nyama','viazi'],
        'uganda'         => ['ugandan','kampala','luganda','ugandan memes','matoke','rolex uganda','boda boda','chai','museveni','oli otya','gyebale'],
        'rwanda'         => ['rwandan','kigali','kinyarwanda','rwandan memes','ubugali','brochette','moto','agaseke','murakoze','clean city'],
        'cameroon'       => ['cameroonian','yaoundé','douala','camfranglais','cameroon memes','ndole','eru','soya','indomie cameroon','les lions indomptables','franko','wayo'],
        'senegal'        => ['senegalese','dakar','wolof','senegal memes','thieboudienne','yassa','wrestling senegal','teranga','xarit','dieureudieuf'],
        'ivory_coast'    => ['ivorian','abidjan','dioula','côte divoire memes','attiéké','aloco','foutou','nouchi','frère','wari'],
        'mali'           => ['malian','bamako','bambara','mali memes','tô','dolo','maafe','djan','djembe','timbuktu'],
        'zimbabwe'       => ['zimbabwean','harare','bulawayo','shona','ndebele','zim memes','sadza','biltong zim','econet','mukoma','sisi','mhoro'],
        'zambia'         => ['zambian','lusaka','nyanja','bemba','zam memes','nshima','kapenta','chibuku','zesco','muli bwanji'],
        'mozambique'     => ['mozambican','maputo','portuguese mozambique','shangaan','matapa','piri piri','xima','machamba','mano','amigo'],
        'angola'         => ['angolan','luanda','kimbundu','portuguese angola','muamba de galinha','funje','calulu','kuduro','semba','mano','ganda'],
        'botswana'       => ['motswana','gaborone','setswana','botswana memes','seswaa','bogobe','mopane worm','go siame','dumela'],
        'namibia'        => ['namibian','windhoek','oshiwambo','herero','afrikaans namibia','kapana','oshifima','namibia memes','hage','howzit namibia'],
        'malawi'         => ['malawian','lilongwe','blantyre','chichewa','nsima','chambo','malawi memes','muli bwanji','zikomo'],
        'madagascar'     => ['malagasy','antananarivo','madagascar memes','vary','romazava','ravitoto','mora mora','tompoko','hira gasy'],
        'mauritius'      => ['mauritian','port louis','creole mauritius','dholl puri','roti mauritius','alouda','sega music','ti creole','ayo'],
        'seychelles'     => ['seychellois','victoria seychelles','creole seychelles','ladob','grilled fish seychelles','takamaka','ou comment'],
        'somalia'        => ['somali','mogadishu','somali memes','bariis','canjeero','camel milk','xawaash','xamar','walaal','naayaa'],
        'egypt'          => ['egyptian','cairo','alexandria','egyptian arabic','koshary','ful medames','ta3meya','ahlan','aho','masry','yalla egypt'],
        'morocco'        => ['moroccan','casablanca','marrakech','fes','darija','moroccan memes','tagine','couscous morocco','mint tea','hammam','sahbi','bessah','wakha'],
        'algeria'        => ['algerian','algiers','oran','kabyle','algerian memes','couscous algeria','merguez','chakhchoukha','wesh','bzef','sahbi'],
        // =========================================================
        // ASIA
        // =========================================================
        'japan'          => ['japanese','japan memes','tokyo','osaka','kawaii','anime','manga','otaku','weeb','jpop','ramen','sushi','matcha','nani','yabai'],
        'south_korea'    => ['korean','korea memes','seoul','kpop','kdrama','hangul','oppa','skincare','korean food','kimchi','bibimbap','soju','squid game','bts','blackpink','daebak'],
        'india'          => ['indian','india memes','desi','hindi','tamil','telugu','mumbai','delhi','bangalore','chai','biryani','dosa','bollywood','ipl','cricket india','bhai','yaar','jugaad'],
        'philippines'    => ['filipino','pinoy','pinay','manila','tagalog','jolibee','adobo','sinigang','jeepney','ate','kuya','lola','lolo'],
        'indonesia'      => ['indonesian','jakarta','javanese','bahasa','nasi goreng','rendang','gojek','wkwkwk'],
        'china'          => ['chinese','china memes','beijing','shanghai','guangzhou','mandarin','cantonese','dim sum','baozi','hotpot','wechat','weibo','laoshi','aiya','ni hao'],
        'vietnam'        => ['vietnamese','hanoi','ho chi minh city','pho','banh mi','bun bo hue','ca phe','ao dai','oi troi oi','ban oi','viet memes'],
        'thailand'       => ['thai','bangkok','chiang mai','pad thai','tom yum','mango sticky rice','songkran','tuk tuk','sanuk','mai pen rai','thai memes'],
        'malaysia'       => ['malaysian','kuala lumpur','penang','malay','nasi lemak','laksa','roti canai','teh tarik','lah','lor','wah','mamak','boleh'],
        'singapore'      => ['singaporean','singapore memes','singlish','hawker centre','chicken rice','kaya toast','milo dinosaur','lah','leh','lor','kiasu','wah lao'],
        'pakistan'       => ['pakistani','karachi','lahore','islamabad','urdu','nihari','biryani karachi','chai pakistan','ghar','yaar pakistan','bhai pakistan','mashallah pakistan'],
        'bangladesh'     => ['bangladeshi','dhaka','chittagong','bengali','hilsa fish','biryani dhaka','panta bhat','rickshaw dhaka','vai','apa'],
        'saudi_arabia'   => ['saudi','riyadh','jeddah','arabic saudi','kabsa','mandi','mutabbaq','qahwa','habibi saudi','yalla saudi','inshallah saudi'],
        'uae'            => ['emirati','dubai','abu dhabi','arabic uae','machboos','luqaimat','camel milk uae','habibi dubai','yalla dubai','wasta'],
        'iran'           => ['iranian','tehran','isfahan','farsi','persian','ghormeh sabzi','kebab iran','tahdig','doogh','joonam','azizam','persian memes'],
        'turkey'         => ['turkish','istanbul','ankara','turkish memes','dizi','turkish drama','baklava','ayran','abi','kanka','lan'],
        // =========================================================
        // EUROPE
        // =========================================================
        'uk'             => ['british','england','london','manchester','uk memes','british humor','banter','innit','bruv','mate','full english','fish and chips','premier league','nhs','bloody hell','cheers mate','proper','gutted','chuffed','quid'],
        'france'         => ['french','paris','france memes','baguette','croissant','french culture','oui oui','mdr','ptdr'],
        'germany'        => ['german','berlin','munich','germany memes','oktoberfest','bratwurst','bundesliga','autobahn','alter','digga','krass'],
        'spain'          => ['spanish','madrid','barcelona','spain memes','tapas','paella','la liga','real madrid','siesta','venga','tio','mola'],
        'italy'          => ['italian','rome','milan','italy memes','pasta','pizza','gelato','espresso','calcio','serie a','mamma mia','ciao'],
        'portugal'       => ['portuguese','lisbon','porto','portuguese memes','pastel de nata','bifanas','caldo verde','bacalhau','fado','saudade portugal','fixe','epá','caralho'],
        'netherlands'    => ['dutch','amsterdam','rotterdam','netherlands memes','stroopwafel','hagelslag','dutch directness','gezellig','fietsers','doe maar','man'],
        'poland'         => ['polish','warsaw','krakow','pierogi','bigos','kielbasa','żurek','polish memes','no to co','kurwa','ziomek'],
        'russia'         => ['russian','moscow','saint petersburg','borscht russia','pelmeni','blini','vodka','kvass russia','russian memes','brat russia','davai','blin'],
        'ukraine'        => ['ukrainian','kyiv','lviv','borscht','varenyky','holubtsi','salo','horilka','ukrainian memes','slava ukraini','brat'],
        'greece'         => ['greek','athens','thessaloniki','souvlaki','moussaka','spanakopita','ouzo','feta','greek memes','opa','malaka','gamato'],
        'sweden'         => ['swedish','stockholm','gothenburg','meatballs','surströmming','cinnamon bun','fika','ikea','lagom','swedish memes','hej','asså'],
        'ireland'        => ['irish','dublin','cork','galway','coddle','colcannon','soda bread','guinness','craic','ireland memes','yer man','jaysus','gas craic','sound'],
        'scotland'       => ['scottish','edinburgh','glasgow','haggis','irn bru','scottish memes','banter scotland','aye','nae','och','wee'],
        // =========================================================
        // AMERICAS
        // =========================================================
        'usa'            => ['american','usa memes','new york','los angeles','chicago','miami','atlanta','super bowl','nfl','nba','thanksgiving','no cap','fr fr','bussin','slay','rizz','ohio meme','delulu'],
        'canada'         => ['canadian','toronto','vancouver','montreal','hockey','tim hortons','poutine','maple syrup','canadian politeness','eh'],
        'mexico'         => ['mexican','mexico memes','ciudad de mexico','guadalajara','tacos','burritos','tamales','tequila','lucha libre','güey','órale','no manches','ahorita'],
        'brazil'         => ['brazilian','brasil memes','são paulo','rio de janeiro','carnaval','samba','caipirinha','churrasco','feijoada','futebol','flamengo','cara','mano','saudade','eita'],
        'argentina'      => ['argentinian','buenos aires','mate','asado','empanadas','dulce de leche','che','boludo','messi argentina','boca juniors','river plate'],
        'colombia'       => ['colombian','bogotá','medellín','bandeja paisa','arepa','aguardiente','salsa cali','vallenato','parcero','chévere','bacano'],
        'jamaica'        => ['jamaican','kingston','patois','irie','wah gwaan','big up','riddim','dancehall','reggae','jerk','ackee','saltfish'],
        'venezuela'      => ['venezuelan','caracas','maracaibo','arepas','pabellón criollo','cachapas','tequeños','hallacas','chamo','chama','épale','coño'],
        'peru'           => ['peruvian','lima','cusco','ceviche peru','lomo saltado','aji de gallina','anticuchos','pisco','machu picchu','huachafo','pe'],
        'chile'          => ['chilean','santiago','valparaíso','empanadas chile','cazuela','completo','pisco chile','cueca','po','cachai','al tiro'],
        // =========================================================
        // OCEANIA
        // =========================================================
        'australia'      => ['australian','aussie','sydney','melbourne','brisbane','crikey','vegemite','tim tam','kangaroo','koala','footy','afl','fair dinkum','yeah nah','sick','heaps'],
        'new_zealand'    => ['kiwi','nz memes','auckland','wellington','māori','te reo','haka','kia ora','sweet as','chur'],
        // MUSIC
        'afrobeats'      => ['afrobeatz','naija music','afropop','afrofusion','burna boy','wizkid','davido','tems','rema','asake','amapiano','afrohouse','azonto','highlife'],
        'amapiano'       => ['amapiano','log drum','piano sa','kabza de small','dj maphorisa','yanos','south africa music'],
        'hiphop'         => ['hip hop','rap','trap','drill','boom bap','freestyle','cypher','bars','flow','lyricism','808','autotune','underground rap','east coast rap','west coast rap','chicago drill','uk drill'],
        'pop'            => ['pop music','pop star','bop','pop hits','top 40','streaming','spotify','apple music'],
        'rnb'            => ['r&b','rhythm and blues','neo soul','rnb vibes','slow jams'],
        'reggae'         => ['reggae','dancehall','roots reggae','bob marley','ska','dub','one love','rastafari music'],
        'latin'          => ['reggaeton','bachata','salsa','cumbia','merengue','latin pop','latin trap','j balvin','bad bunny','ozuna','karol g','daddy yankee'],
        'kpop'           => ['kpop','k-pop','idol','comeback','mv','fandom','stan','lightstick','photocard','bts army','blinks','stays','once','aespa','newjeans'],
        'edm'            => ['edm','electronic dance music','techno','house music','trance','dubstep','drum and bass','rave','festival','dj','bpm'],
        // SPORTS
        'football'       => ['soccer','premier league','champions league','goals','football memes','la liga','serie a','bundesliga','ligue 1','var','football banter'],
        'basketball'     => ['nba','basketball memes','hoops','march madness','lebron','jordan','kobe','curry','giannis','lakers','celtics','slam dunk'],
        'cricket'        => ['cricket','ipl','test cricket','t20','cricket memes','six','wicket','century','batsman','bowler','ashes'],
        'f1'             => ['formula 1','f1 memes','grand prix','pole position','lewis hamilton','max verstappen','ferrari','mercedes','red bull racing','mclaren'],
        'boxing'         => ['boxing','mma','ufc','fight night','knockout','ko','tko','heavyweight','canelo','fury','usyk'],
        // GAMING
        'gaming'         => ['gamer','gaming memes','video games','playstation','xbox','nintendo','pc gaming','steam','twitch','gta','minecraft','fortnite','call of duty','apex legends','valorant','pokemon','elden ring','dark souls','zelda','mario','speedrun','noob','git gud'],
        // ANIME
        'anime'          => ['manga','otaku','weeb','naruto','one piece','dragon ball','attack on titan','demon slayer','jujutsu kaisen','bleach','my hero academia','death note','one punch man','chainsaw man','spy x family','haikyuu','blue lock','waifu','husbando','isekai','shonen','seasonal anime'],
        // TECH & INTERNET
        'ai'             => ['machine learning','chatgpt','artificial intelligence','gpt','llm','claude','gemini','midjourney','stable diffusion','ai art','prompt engineering','neural network','deep learning','openai','anthropic','agi','ai ethics'],
        'tech'           => ['technology','startup','silicon valley','big tech','software engineer','developer','coder','programmer','coding','open source','github','linux','android','ios','saas','web3','crypto','bitcoin','ethereum','fintech','founder','hackathon'],
        'internet'       => ['internet culture','online','chronically online','digital native','gen z online','millennial online','nostalgia internet','early internet','reddit','twitter x','tumblr','instagram','tiktok','youtube','twitch','discord'],
        'social_media'   => ['instagram','tiktok','youtube','twitter','snapchat','facebook','linkedin','content creator','influencer','algorithm','shadowban','fyp tiktok','collab','brand deal'],
        // FOOD
        'food'           => ['foodie','food memes','food porn','delicious','recipe','cooking','baking','brunch','street food','fast food','gourmet','mukbang','food review','restaurant'],
        'coffee'         => ['coffee','espresso','latte','cappuccino','cold brew','coffee memes','coffee addict','barista','pour over','coffee snob'],
        'tea'            => ['tea','chai','matcha','bubble tea','boba','milk tea','green tea','afternoon tea','tea memes'],
        // LIFESTYLE
        'fashion'        => ['fashion','ootd','outfit','streetwear','hypebeast','sneakers','sneakerhead','jordans','yeezy','thrift flip','vintage fashion','luxury fashion','gucci','louis vuitton','fashion week','fashion memes'],
        'fitness'        => ['gym','workout','fitness memes','lifting','gains','protein','leg day','gym bro','cardio','running','yoga','pilates','crossfit','bulk','cut','shredded','swole'],
        'mental_health'  => ['mental health','anxiety','depression','therapy','therapist','self care','burnout','stress','overwhelmed','adhd','autism','neurodivergent','mental health memes'],
        'relationships'  => ['relationship','dating','situationship','talking stage','ghosting','red flag','green flag','attachment style','love language','toxic relationship','couple goals','rizz','sigma','dating memes','single life'],
        'gen_z'          => ['gen z','gen z memes','gen z humor','zoomers','brain rot','delulu','rizz','slay','no cap','fr fr','npc','fit check','stan','cringe','rent free','it\'s giving','era'],
        'millennial'     => ['millennial','millennial memes','avocado toast','starter pack','nostalgia','y2k','early 2000s','cartoon network','disney channel','nickelodeon','mtv','ipod','myspace','nokia brick'],
        // SCHOOL & WORK
        'school'         => ['school memes','student life','homework','exam','studying','procrastinating','all nighter','group project','teacher','professor','university','college','finals week','student loan'],
        'work'           => ['work memes','office life','9 to 5','work from home','wfh','remote work','corporate','meeting that could be email','monday morning','friday feeling','quiet quitting','hustle culture','side hustle'],
        // NATURE & ANIMALS
        'animals'        => ['animal memes','cute animals','dog memes','cat memes','doge','doggo','pupper','catto','birb','smol','chonk','floof','zoomies','pet tax'],
        'nature'         => ['nature','landscape','wildlife','ocean','mountains','forest','sunset','sunrise','climate change','environment','hiking','camping','outdoors'],
    ];

    /* ── 6. Build candidate pool ── */
    $candidates = [];

    /* personal hashtags */
    foreach ($personalTags as $tag => $cnt) {
        $candidates['#'.$tag] = ['query' => '#'.$tag, 'source' => 'personal', 'personal_score' => min(1, $cnt / 10)];
    }
    /* personal words */
    $skipWords = ['the','and','for','not','this','that','with','are','was','you','but','its','can','has'];
    foreach ($personalWords as $word => $cnt) {
        if (in_array($word, $skipWords)) continue;
        $key = '#'.$word;
        if (!isset($candidates[$key]))
            $candidates[$key] = ['query' => '#'.$word, 'source' => 'personal', 'personal_score' => min(1, $cnt / 15)];
    }
    /* trending */
    foreach ($trendMap as $q => $vel) {
        if (!isset($candidates[$q]))
            $candidates[$q] = ['query' => $q, 'source' => 'trending', 'personal_score' => 0];
    }
    /* caption tags */
    foreach ($captionTags as $tag => $cnt) {
        $key = '#'.$tag;
        if (!isset($candidates[$key]))
            $candidates[$key] = ['query' => '#'.$tag, 'source' => 'trending', 'personal_score' => 0];
    }
    /* global popular */
    foreach ($popMap as $q => $row) {
        if (!isset($candidates[$q]))
            $candidates[$q] = ['query' => $q, 'source' => 'global', 'personal_score' => 0];
    }
    /* semantic expansions — Python /similar first, $semanticMap fallback */
    $topPersonal = array_slice(array_keys($personalTags), 0, 5);
    foreach ($topPersonal as $tag) {
        $mlRelated = ml_similar_tags($tag, 12);
        if (empty($mlRelated) && isset($semanticMap[$tag])) {
            $mlRelated = array_map(fn($t) => '#' . $t, $semanticMap[$tag]);
        }
        foreach ($mlRelated as $related) {
            $key = strtolower($related);
            if (!isset($candidates[$key])) {
                $candidates[$key] = [
                    'query'          => $related,
                    'source'         => 'related',
                    'personal_score' => 0.15,
                ];
            }
        }
    }

    /* ── Evergreen fallbacks ── */
    $fallbacks = [
        '#funny','#meme','#relatable','#hilarious','#viral','#lol',
        '#trending','#dank','#humor','#mood','#wholesome','#cringe',
        '#sarcasm','#darkhumor','#chaos','#npc','#brainrot','#delulu',
        '#rizz','#slay','#nocap','#understood','#itsgiving','#periodt',
        '#naija','#nigerian','#nollywood','#japa','#sapa','#owambe',
        '#asoebi','#yoruba','#igbo','#hausa','#9ja','#abuja','#lagos',
        '#warri','#lagoslife','#naijamemes','#naijafood','#suya',
        '#amala','#peppersoup','#owanbe','#agbado','#thunderfire',
        '#american','#losangeles','#la','#california','#nyc','#newyork',
        '#chicago','#houston','#miami','#atlanta','#compton','#hollywood',
        '#westcoast','#eastcoast','#southside','#usavibes',
        '#blacktwitter','#usmemes','#americanlife','#collegelife',
        '#london','#uk','#british','#manchester','#paris','#france',
        '#german','#berlin','#italy','#rome','#spain','#madrid',
        '#tokyo','#japan','#japanese','#korean','#seoul','#kpop',
        '#chinese','#beijing','#shanghai','#india','#desi','#hindi',
        '#turkish','#istanbul','#arabic','#habibi','#dubai','#uae',
        '#russian','#moscow','#polish','#warsaw','#greek','#athens',
        '#hiphop','#rap','#trap','#drill','#rnb','#pop','#edm',
        '#afrobeats','#amapiano','#reggae','#dancehall','#latin',
        '#reggaeton','#afropop','#highlife','#jujumusic','#fuji',
        '#kpop','#jpop','#classical','#jazz','#blues','#metal',
        '#newmusic','#musicmemes','#spotify','#playlist',
        '#football','#soccer','#premierleague','#championsleague',
        '#laliga','#seriea','#bundesliga','#nba','#basketball',
        '#cricket','#ipl','#f1','#formula1','#boxing','#mma','#ufc',
        '#sports','#sportsmemes','#goals','#matchday',
        '#gaming','#gamer','#ps5','#xbox','#nintendo','#pcgaming',
        '#minecraft','#fortnite','#callofduty','#gta','#roblox',
        '#valorant','#apexlegends','#fifa','#eldenring','#pokemon',
        '#twitch','#streamer','#gamingmemes','#speedrun',
        '#anime','#manga','#otaku','#weeb','#naruto','#onepiece',
        '#demonslayer','#attackontitan','#dragonball','#jujutsukaisen',
        '#myheroaccademia','#deathnote','#haikyuu','#bluelock',
        '#waifu','#husbando','#isekai',
        '#fashion','#ootd','#streetwear','#hypebeast','#sneakers',
        '#fitness','#gym','#workout','#gains','#gymrat','#legday',
        '#food','#foodie','#foodporn','#recipe','#cooking','#baking',
        '#coffee','#tea','#matcha','#bubbletea','#boba',
        '#travel','#wanderlust','#adventure','#explore','#nature',
        '#art','#photography','#aesthetic','#vibes','#lofi',
        '#mentalhealth','#selfcare','#anxiety','#therapy','#burnout',
        '#relationships','#dating','#situationship','#ghosting','#redflag',
        '#school','#studentlife','#exam','#procrastinating','#allnighter',
        '#work','#officememes','#wfh','#remotework','#hustle','#grind',
        '#ai','#chatgpt','#tech','#coding','#programming','#developer',
        '#crypto','#bitcoin','#web3','#startup',
        '#socialmedia','#instagram','#tiktok','#youtube','#twitter',
        '#genz','#millennial','#boomer','#y2k','#nostalgia','#throwback',
        '#earlyinternet','#2000s','#90s','#childhood',
        '#animals','#dogs','#cats','#dogmemes','#catmemes','#cutepets',
        '#wildlife','#ocean','#mountains','#sunset','#outdoors',
    ];
    foreach ($fallbacks as $f) {
        if (!isset($candidates[$f]))
            $candidates[$f] = ['query' => $f, 'source' => 'popular', 'personal_score' => 0];
    }

    /* ════════════════════════════════════════
       ROUTE THROUGH PYTHON /rank IF AVAILABLE
       Falls back to PHP scoring if Python is down
    ════════════════════════════════════════ */

    $topUserTags = array_slice(array_keys($personalTags), 0, 10);

    /* Build the candidate list Python needs */
    $maxVel = max(1, !empty($trendMap) ? max(array_values($trendMap)) : 1);

    $rankCandidates = [];
    $bannedTerms    = ['xxx','nude','porn','kill','hack','drugs','scam'];

    foreach ($candidates as $key => $c) {
        $q      = strtolower($c['query']);
        $qClean = ltrim($q, '#');

        $banned = false;
        foreach ($bannedTerms as $b) {
            if (strpos($qClean, $b) !== false) { $banned = true; break; }
        }
        if ($banned) continue;

        /* pre-compute scores PHP already knows about */
        $popScore = 0;
        if (isset($popMap[$q])) {
            $row      = $popMap[$q];
            $popScore = (int)$row['total_search_count'] / $maxCount;
            $ctr      = (float)($row['click_through_rate'] ?? 0);
            $bounce   = (float)($row['bounce_rate'] ?? 0.5);
            $popScore = $popScore * (0.5 + 0.3 * $ctr + 0.2 * (1 - $bounce));
        }
        if (isset($captionTags[$qClean])) {
            $capScore = $captionTags[$qClean] / $maxCaption;
            $popScore = max($popScore, $capScore * 0.8);
        }

        $trendScore = 0;
        if (isset($trendMap[$q])) {
            $trendScore = $trendMap[$q] / $maxVel;
            if ($trendScore > 0.7) $trendScore = min(1, $trendScore * 1.3);
        }

        /* geo score */
        $geoScore = 0;
        $nigeriaTerms = ['naija','nigeria','nigerian','nigerian memes','afrobeats','afrobeatz','japa','sapa','oga','abeg','nollywood','9ja','yoruba','igbo','hausa','fulani','lagos','abuja','warri','benin','kano','ph city','owerri','ibadan','owambe','asoebi','suya','amala','peppersoup','agege','buka','naijamemes','lagoslife','naijafood','thunderfire','owanbe'];
        $usaTerms     = ['american','usa','losangeles','la','california','hollywood','compton','newyork','nyc','brooklyn','chicago','houston','miami','atlanta','westcoast','eastcoast','southside','blacktwitter','usmemes','collegelife','americanlife','superb owl','nfl','nba','mlb','thanksgiving','fourthofjuly','drip','bussin','rizz','nocap','frrr','understood','itsgiving','slay','periodt','delulu'];
        $eurasianTerms= ['london','british','uk','england','manchester','liverpool','glasgow','paris','france','baguette','berlin','german','germany','oktoberfest','italy','rome','milan','pasta','pizza','spain','madrid','barcelona','portugal','lisbon','netherlands','amsterdam','swedish','stockholm','oslo','norway','copenhagen','denmark','polish','warsaw','russian','moscow','greek','athens','turkish','istanbul','ankara','tokyo','japan','japanese','kawaii','anime','ramen','sushi','sakura','seoul','korean','kpop','kdrama','kimchi','bibimbap','soju','daebak','beijing','shanghai','chinese','mandarin','hotpot','dumplings','mumbai','delhi','bangalore','desi','hindi','bollywood','biryani','dubai','uae','emirati','riyadh','saudi','habibi','yalla','inshallah','jakarta','indonesian','manila','filipino','pinoy','bangkok','thai','singapore','singlish','kuala lumpur','malaysian','vietnamese','hanoi'];
        foreach ($nigeriaTerms  as $t) { if (strpos($qClean, $t) !== false) { $geoScore = 0.78; break; } }
        if ($geoScore === 0) foreach ($usaTerms      as $t) { if (strpos($qClean, $t) !== false) { $geoScore = 0.90; break; } }
        if ($geoScore === 0) foreach ($eurasianTerms as $t) { if (strpos($qClean, $t) !== false) { $geoScore = 0.85; break; } }

        $srcWeight = match($c['source']) {
            'personal' => 1.0, 'trending' => 0.7, 'global' => 0.5,
            'related'  => 0.4, 'popular'  => 0.3, default   => 0.2,
        };
        $baseScore = (0.10 * $srcWeight) + (0.15 * (float)$c['personal_score']);

        $rankCandidates[] = [
            'tag'         => $c['query'],
            'source'      => $c['source'],
            'pop_score'   => round($popScore, 4),
            'trend_score' => round($trendScore, 4),
            'geo_score'   => round($geoScore, 4),
            'base_score'  => round($baseScore, 4),
        ];
    }

    /* ── Try Python /rank first ── */
    $ranked = ml_post('/rank', [
        'candidates'   => $rankCandidates,
        'user_top_tags' => $topUserTags,
        'limit'        => $limit,
    ]);

    if ($ranked && !empty($ranked['ranked'])) {
        /* Python did the work — return directly */
        echo json_encode(array_slice($ranked['ranked'], 0, $limit));
        exit;
    }

    /* ── Python is down — fall back to PHP scoring ── */
    $mlBoosts = ml_score_boost(array_column($rankCandidates, 'tag'), $topUserTags);

    $scored = [];
    foreach ($rankCandidates as $rc) {
        $engScore = 0;
        $q        = strtolower($rc['tag']);
        if (isset($popMap[$q])) {
            $ctr      = (float)($popMap[$q]['click_through_rate'] ?? 0);
            $bounce   = (float)($popMap[$q]['bounce_rate'] ?? 0.5);
            $engScore = ($ctr * 0.6) + ((1 - $bounce) * 0.4);
        }

        $persScore  = (float)($candidates[$rc['tag']]['personal_score'] ?? 0);
        $srcWeight  = match($rc['source']) {
            'personal' => 1.0, 'trending' => 0.7, 'global' => 0.5,
            'related'  => 0.4, 'popular'  => 0.3, default   => 0.2,
        };
        $mlBoost    = (float)($mlBoosts[$rc['tag']] ?? 0.0);

        $finalScore =
            (0.25 * $rc['pop_score'])
          + (0.20 * $rc['trend_score'])
          + (0.20 * $persScore)
          + (0.15 * $engScore)
          + (0.10 * $rc['geo_score'])
          + (0.10 * $srcWeight)
          + (0.15 * $mlBoost);

        $scored[] = [
            'tag'    => $rc['tag'],
            'source' => $rc['source'],
            'score'  => $finalScore,
        ];
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    /* soft bucket mix — guarantee at least 1 from each non-empty source */
    $buckets = ['personal' => [], 'trending' => [], 'global' => [], 'related' => [], 'popular' => []];
    foreach ($scored as $s) {
        $src = array_key_exists($s['source'], $buckets) ? $s['source'] : 'popular';
        $buckets[$src][] = $s;
    }

    $out  = [];
    $seen = [];
    foreach ($buckets as $items) {
        if (empty($items)) continue;
        $key = strtolower($items[0]['tag']);
        if (!isset($seen[$key])) { $seen[$key] = true; $out[] = $items[0]; }
        if (count($out) >= $limit) break;
    }
    foreach ($scored as $s) {
        if (count($out) >= $limit) break;
        $key = strtolower($s['tag']);
        if (!isset($seen[$key])) { $seen[$key] = true; $out[] = $s; }
    }

    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    echo json_encode(array_slice($out, 0, $limit));
    exit;
}

echo '{}';