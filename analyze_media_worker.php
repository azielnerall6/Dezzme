<?php
/* ================================================================
   analyze_media_worker.php — studies the media of new posts (see dz_vision.php)

   RUN IT EVERY MINUTE
     cPanel cron (command):   * * * * * /usr/bin/php /home/YOURUSER/public_html/analyze_media_worker.php
     or by URL if you can only call URLs:   https://dezzme.com/analyze_media_worker.php?key=YOUR_DZ_CRON_KEY
   Each run handles a small batch (newest posts first), so it never overloads the server or your AI bill.
   Posts are retried up to 3 times if the AI call fails. Safe to run on two schedules at once: a lock keeps one worker at a time.
================================================================ */
if(PHP_SAPI !== 'cli'){
    require_once __DIR__ . '/config.php';
    if(!defined('DZ_CRON_KEY') || DZ_CRON_KEY === '' || !hash_equals((string)DZ_CRON_KEY, (string)($_GET['key'] ?? ''))){ http_response_code(403); exit('forbidden'); }
    header('Content-Type: text/plain; charset=utf-8');
} else {
    require_once __DIR__ . '/config.php';
}
require_once __DIR__ . '/dz_algo_prefs.php';
require_once __DIR__ . '/dz_vision.php';

if(!defined('DZ_VISION_BATCH'))    define('DZ_VISION_BATCH', 8);     /* posts per run */
if(!defined('DZ_VISION_DAYS'))     define('DZ_VISION_DAYS', 30);     /* only posts newer than this many days (the feed only looks this far back anyway) */
if(function_exists('set_time_limit')) @set_time_limit(110);

dz_algo_ensure_schema($conn);

$lock = $conn->query("SELECT GET_LOCK('dz_vision_worker', 0) AS l");
if(!$lock || (int)($lock->fetch_assoc()['l'] ?? 0) !== 1){ exit("another worker is running\n"); }

/* daily cost ceiling */
$used = 0;
$r = $conn->query("SELECT COUNT(*) c FROM dz_media_analysis WHERE model IS NOT NULL AND model <> 'caption-only' AND analyzed_at >= CURDATE()");
if($r) $used = (int)($r->fetch_assoc()['c'] ?? 0);
$room = max(0, (int)DZ_VISION_DAILY_CAP - $used);
if($room <= 0){ $conn->query("SELECT RELEASE_LOCK('dz_vision_worker')"); exit("daily cap reached ($used)\n"); }

$limit = min((int)DZ_VISION_BATCH, $room);
$tries = (int)DZ_VISION_MAX_TRIES; $days = (int)DZ_VISION_DAYS;
$res = $conn->query("
    SELECT m.id, m.uploaded_by, m.file_name, m.type, m.caption, m.is_adult, m.is_ad
    FROM memes m
    JOIN users u ON u.id = m.uploaded_by
    LEFT JOIN dz_media_analysis a ON a.meme_id = m.id
    WHERE m.is_archived = 0 AND u.deactivated_at IS NULL
      AND m.created_at >= (NOW() - INTERVAL $days DAY)
      AND (a.meme_id IS NULL OR (a.status = 'failed' AND a.attempts < $tries AND a.analyzed_at < (NOW() - INTERVAL 10 MINUTE)))
    ORDER BY m.id DESC
    LIMIT $limit
");

$count = ['done' => 0, 'skipped' => 0, 'failed' => 0];
if($res){
    while($m = $res->fetch_assoc()){
        $s = dz_vision_process($conn, $m);
        $count[$s] = ($count[$s] ?? 0) + 1;
    }
}
$conn->query("SELECT RELEASE_LOCK('dz_vision_worker')");

echo date('c') . "  done {$count['done']}  skipped {$count['skipped']}  failed {$count['failed']}"
   . (dz_vision_enabled() ? '' : '  (DZ_VISION_API_KEY is not set: only captions were indexed)') . "\n";