<?php
// config.php
$servername = "localhost";
$username = "root"; // default XAMPP user
$password = "";     // default XAMPP password
$dbname = "dezzmee";  // create this database in phpMyAdmin

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    error_log("DB connection failed: " . $conn->connect_error);
    die("Connection failed. Please try again later.");
}
$conn->set_charset("utf8mb4");

/* ══════════════════════════════════════════════════════════════
   DEBUG + TIMING (feed algorithm)
   Both are ON only when the request comes from your own computer (localhost),
   and OFF for everyone else, so they can never leak on the live site.
     DZ_ALGO_DEBUG : ?feed_json=1 answers include a "debug" list (score parts of the top 40 posts)
     DZ_TIMING     : the X-DZ-Timing response header + slow-feed lines in the PHP error log
   To force either one off on localhost too, change true to false below.
══════════════════════════════════════════════════════════════ */
$dz_is_local_request = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if(!defined('DZ_ALGO_DEBUG')) define('DZ_ALGO_DEBUG', $dz_is_local_request ? true : false);
if(!defined('DZ_TIMING'))     define('DZ_TIMING',     $dz_is_local_request ? true : false);

/* ══════════════════════════════════════════════════════════════
   EMAIL VERIFICATION CODES (password reset)
   Keep this file out of public folders / version control.
══════════════════════════════════════════════════════════════ */

// Server secret used to hash one-time codes. Never change it casually:
// changing it invalidates any codes currently waiting to be used.
define('OTP_SECRET', '85a4f007fd2e9dbb6584df0e1fc8016cc367998bbb5946633bd9564d98d6fe76d3fdb82b5f08d0407cf9509a41545344');

// SMTP settings: Mailtrap sandbox for local testing (swap for a real provider like Brevo when you go live)
define('SMTP_HOST',      'sandbox.smtp.mailtrap.io');
define('SMTP_PORT',      2525);                        // Mailtrap accepts 25, 465, 587 or 2525
define('SMTP_USER',      '1ba330884e4202');
define('SMTP_PASS',      '96c7149a16417b');   // <- the only thing left to change
define('MAIL_FROM',      'no-reply@dezzme.test');     // anything works in Mailtrap; use a real domain address when live
define('MAIL_FROM_NAME', 'Dezzme');

/* ══════════════════════════════════════════════════════════════
   MEDIA AI  (dz_vision.php, analyze_media_worker.php, upload.php)
   Studies what a post SHOWS, so the feed can match it to topics and apply
   the Sensitive / Political content levels from "Your Algorithm".
   Keep this file out of public folders / version control: it holds a secret key.
══════════════════════════════════════════════════════════════ */

// Your Anthropic API key (starts with sk-ant-). Leave it '' to switch the media AI off:
// posts are then indexed by caption only and nothing is sent to a third party.
define('DZ_VISION_API_KEY', '');

// Only needed if you run the worker by URL instead of a cron command:
// https://yourdomain.com/analyze_media_worker.php?key=THIS_VALUE
define('DZ_CRON_KEY', '6a8b96e5361e11f76a4517ca065ea22c8146eac7a3fa9724');

// Optional tuning. Every one has a default inside dz_vision.php, so you can delete any of these lines.
// define('DZ_VISION_MODEL', 'claude-haiku-5-5');         // first-pass model (fast and cheap)
// define('DZ_VISION_RECHECK_MODEL', 'claude-sonnet-5-5'); // second opinion on risky posts ('' = off)
// define('DZ_VISION_DAILY_CAP', 3000);                   // AI calls per day: your cost ceiling
// define('DZ_VISION_DAYS', 30);                          // worker looks at posts this many days back
// define('DZ_FFMPEG', '/usr/bin/ffmpeg');                // path to ffmpeg (video frames); XAMPP/Windows: 'C:\\ffmpeg\\bin\\ffmpeg.exe'

/* dz_tokens(): splits a caption (or an AI topic) into lowercase, lightly stemmed words (glutes → glute).
   dz_vision.php, dz_algo_prefs.php and the feed all use it, so it must exist everywhere.
   If your project ALREADY has a dz_tokens() in another file and you see "Cannot redeclare dz_tokens()",
   change the line below to define('DZ_TOKENS_FALLBACK', false); and this one is skipped. */
if(!defined('DZ_TOKENS_FALLBACK')) define('DZ_TOKENS_FALLBACK', true);
if(DZ_TOKENS_FALLBACK && !function_exists('dz_tokens')){
    function dz_tokens($text, $max = 40){
        static $stop = null, $keep = null;
        if($stop === null){
            $stop = array_flip(['a','an','the','and','or','but','if','of','to','in','on','at','by','for','with','from','as','is','are','was','were','be','been','am',
                'it','its','this','that','these','those','i','me','my','we','our','you','your','he','she','him','her','they','them','their','what','when','where','who','how','why',
                'so','not','no','yes','do','does','did','have','has','had','will','would','can','could','just','like','get','got','up','out','all','one','too','very','than','then','there','here','about','into','over']);
            /* words that end in s but are not plurals */
            $keep = array_flip(['news','lens','gas','bus','series','species','this','his','is','was','has','us','always','class','chess','jeans','dress','glass','mass','pass','boss','kiss','bass','plus','canvas','focus','bonus','virus','status','campus','gym','analysis','basis']);
        }
        $text = mb_strtolower((string)$text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}_#\' -]+/u', ' ', $text) ?? '';
        $out = []; $seen = [];
        foreach(preg_split('/[\s]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w){
            $w = trim($w, "#'-_");
            if($w === '' || mb_strlen($w, 'UTF-8') < 2 || isset($stop[$w]) || ctype_digit($w)) continue;
            $t = $w;
            $len = mb_strlen($t, 'UTF-8');
            if(!isset($keep[$t]) && $len > 3){
                if(substr($t, -3) === 'ies' && $len > 4)                         $t = substr($t, 0, -3) . 'y';   /* parties → party */
                elseif(preg_match('/(ss|us|is|ous)$/', $t))                       { /* class, bonus, this: leave */ }
                elseif(preg_match('/(sh|ch|x|z|ss)es$/', $t))                     $t = substr($t, 0, -2);         /* boxes → box, watches → watch */
                elseif(substr($t, -1) === 's')                                    $t = substr($t, 0, -1);         /* glutes → glute */
            }
            $t = mb_substr($t, 0, 40, 'UTF-8');
            if($t === '' || isset($seen[$t])) continue;
            $seen[$t] = 1;
            $out[] = ['term' => $t, 'shown' => mb_substr($w, 0, 40, 'UTF-8')];
            if(count($out) >= (int)$max) break;
        }
        return $out;
    }
}

/**
 * Log a moderation/settings action for a group's Activity Log.
 *
 * @param mysqli   $conn            Active DB connection
 * @param int      $group_id        Group this action happened in
 * @param int      $actor_id        User who performed the action (owner/admin)
 * @param string   $action_type     Short machine key, e.g. 'kick_member', 'rename_group'
 * @param int|null $target_user_id  User the action was done to, if any (kicked/banned/appointed user)
 * @param string|null $details      Optional freeform context (old→new title, hashtag text, etc.)
 */
function log_group_activity($conn, $group_id, $actor_id, $action_type, $target_user_id = null, $details = null) {
    $stmt = $conn->prepare("INSERT INTO group_activity_log (group_id, actor_id, target_user_id, action_type, details) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiss", $group_id, $actor_id, $target_user_id, $action_type, $details);
    $stmt->execute();
    $stmt->close();
}

/* ══════════════════════════════════════════════════════════════
   HIDE COUNTS (likes / reposts / saves / shares)
   The upload page saves memes.hide_likes, hide_reposts, hide_saves
   and hide_shares (0 = show, 1 = hide). Every page uses these helpers.
══════════════════════════════════════════════════════════════ */

// Creates the four columns if they don't exist yet, so any page can run first.
function dz_ensure_hide_columns($conn){
    static $done = false;
    if($done) return;
    $done = true;
    try{
        foreach(['hide_likes','hide_reposts','hide_saves','hide_shares'] as $c){
            $r = $conn->query("SHOW COLUMNS FROM `memes` LIKE '$c'");
            if($r && $r->num_rows === 0){
                $conn->query("ALTER TABLE `memes` ADD COLUMN `$c` TINYINT(1) NOT NULL DEFAULT 0");
            }
        }
    }catch(Throwable $e){}
}
dz_ensure_hide_columns($conn);

// Is this count hidden from the person looking at the page?
// $row must contain uploaded_by and the hide_* column.
// The post owner always sees their own numbers.
function dz_hidden($row, $flag, $viewer_id = null){
    if(empty($row[$flag])) return false;
    if($viewer_id === null) $viewer_id = (int)($_SESSION['user_id'] ?? 0);
    return !($viewer_id && (int)($row['uploaded_by'] ?? 0) === (int)$viewer_id);
}

// The number to print, or '' when hidden.
function dz_count($row, $flag, $value, $viewer_id = null){
    return dz_hidden($row, $flag, $viewer_id) ? '' : (int)$value;
}

// data-attributes for the JS: put dz_attr($row,'hide_likes') inside the span tag, e.g. class="like-count" followed by the dz_attr output
function dz_attr($row, $flag){
    return 'data-hidden="' . (dz_hidden($row, $flag) ? 1 : 0) . '"';
}