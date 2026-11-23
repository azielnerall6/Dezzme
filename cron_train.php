<?php
/**
 * cron_train.php — Feed real user session data to Python so the
 * co-occurrence model learns what tags your users actually pair together.
 *
 * Run nightly AFTER cron_index.php (embeddings first, then co-occurrence):
 *   10 3 * * * php /var/www/html/cron_train.php >> /var/log/dezzme_cron.log 2>&1
 *
 * What it does:
 *   1. Groups search_user_signals by user + day into "sessions"
 *      (searches a single user did on the same day = one session)
 *   2. Filters out sessions with < 2 tags (useless for co-occurrence)
 *   3. POSTs all sessions to Python /train so it builds co-occurrence vectors
 *   4. Logs results
 *
 * The model learns things like:
 *   "users who search #naija also search #afrobeats" → boost both together
 *   "users who search #kpop also search #kdrama"    → related cluster
 */

require __DIR__ . '/config.php';
require __DIR__ . '/ml_client.php';

$started = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] cron_train started\n";

/* ══════════════════════════════════════════
   Config — tweak these as your dataset grows
══════════════════════════════════════════ */

// How many days back to pull signals from
// 30 days = good balance of recency vs enough data
$lookbackDays = 30;

// Minimum tags per session (1-tag sessions teach nothing)
$minTagsPerSession = 2;

// Maximum tags per session (guards against bot/spam sessions)
$maxTagsPerSession = 50;

// Max sessions to send to Python in one /train call
// Python handles 10k easily; keep lower if RAM is tight
$maxSessions = 10000;

/* ══════════════════════════════════════════
   1. Pull raw signals grouped by user + day
══════════════════════════════════════════ */

$stmt = $conn->prepare(
    "SELECT
         user_id,
         DATE(created_at)   AS session_date,
         signal_value       AS tag,
         COUNT(*)           AS search_count
     FROM search_user_signals
     WHERE signal_type = 'search'
       AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
       AND signal_value IS NOT NULL
       AND signal_value != ''
     GROUP BY user_id, session_date, signal_value
     ORDER BY user_id, session_date, search_count DESC"
);

if (!$stmt) {
    echo "[" . date('H:i:s') . "] FATAL: Could not prepare statement: " . $conn->error . "\n";
    exit(1);
}

$stmt->bind_param('i', $lookbackDays);
$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    echo "[" . date('H:i:s') . "] FATAL: Query failed: " . $stmt->error . "\n";
    exit(1);
}

echo "[" . date('H:i:s') . "] Pulled " . $result->num_rows . " signal rows from last {$lookbackDays} days\n";

/* ══════════════════════════════════════════
   2. Group rows into sessions
      key = "user_id|date"  →  [tag, tag, tag, ...]
══════════════════════════════════════════ */

$sessionMap  = [];
$bannedTerms = ['xxx', 'nude', 'porn', 'kill', 'hack', 'drugs', 'scam'];

while ($row = $result->fetch_assoc()) {
    $uid  = (int)$row['user_id'];
    $date = $row['session_date'];
    $tag  = strtolower(trim($row['tag']));
    $tag  = ltrim($tag, '#'); // normalise — strip leading # if present

    // Skip junk / too short / too long
    if (strlen($tag) < 2 || strlen($tag) > 60) continue;

    $banned = false;
    foreach ($bannedTerms as $b) {
        if (str_contains($tag, $b)) { $banned = true; break; }
    }
    if ($banned) continue;

    $key = "{$uid}|{$date}";
    $sessionMap[$key][] = $tag;
}

$stmt->close();

echo "[" . date('H:i:s') . "] Built " . count($sessionMap) . " raw sessions\n";

/* ══════════════════════════════════════════
   3. Filter + deduplicate sessions
══════════════════════════════════════════ */

$sessions = [];

foreach ($sessionMap as $key => $tags) {
    // Deduplicate tags within a session
    $tags = array_values(array_unique($tags));

    // Drop sessions that are too short or suspiciously long (bots)
    if (count($tags) < $minTagsPerSession) continue;
    if (count($tags) > $maxTagsPerSession) {
        // Trim to max rather than discard — still useful
        $tags = array_slice($tags, 0, $maxTagsPerSession);
    }

    $sessions[] = $tags;

    if (count($sessions) >= $maxSessions) break;
}

$sessionCount = count($sessions);
echo "[" . date('H:i:s') . "] Valid sessions after filtering: $sessionCount\n";

if ($sessionCount === 0) {
    echo "[" . date('H:i:s') . "] No sessions to train on. Exiting.\n";
    exit(0);
}

/* ══════════════════════════════════════════
   4. Log some stats before sending
══════════════════════════════════════════ */

$allTagsFlat = array_merge(...$sessions);
$tagFreq     = array_count_values($allTagsFlat);
arsort($tagFreq);
$topTags     = array_slice($tagFreq, 0, 10, true);

echo "[" . date('H:i:s') . "] Total tag occurrences in sessions: " . count($allTagsFlat) . "\n";
echo "[" . date('H:i:s') . "] Unique tags across all sessions: " . count(array_unique($allTagsFlat)) . "\n";
echo "[" . date('H:i:s') . "] Top 10 tags: " . implode(', ', array_keys($topTags)) . "\n";

/* ══════════════════════════════════════════
   5. POST to Python /train
      Send in one shot — Python handles the math
══════════════════════════════════════════ */

echo "[" . date('H:i:s') . "] Sending $sessionCount sessions to Python /train ...\n";

// Override timeout for training — this can take a few seconds with large datasets
// We do this by calling ml_request directly with a longer timeout
$ch = curl_init(ML_SERVICE_URL . '/train');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER    => true,
    CURLOPT_TIMEOUT_MS        => 30000,      // 30s — training can be slow
    CURLOPT_CONNECTTIMEOUT_MS => 2000,
    CURLOPT_POST              => true,
    CURLOPT_POSTFIELDS        => json_encode(['sessions' => $sessions]),
    CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
]);

$raw = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || !$raw) {
    echo "[" . date('H:i:s') . "] ERROR: Could not reach Python service. curl error: $err\n";
    echo "[" . date('H:i:s') . "] Training aborted. Embeddings from cron_index.php are still intact.\n";
    exit(1);
}

$response = json_decode($raw, true);

if (!is_array($response)) {
    echo "[" . date('H:i:s') . "] ERROR: Bad response from Python (HTTP $httpCode): $raw\n";
    exit(1);
}

if (isset($response['error'])) {
    echo "[" . date('H:i:s') . "] ERROR from Python: " . $response['error'] . "\n";
    exit(1);
}

/* ══════════════════════════════════════════
   6. Summary
══════════════════════════════════════════ */

$elapsed     = round(microtime(true) - $started, 2);
$tagsTrained = $response['tags_trained'] ?? '?';
$sessUsed    = $response['sessions_used'] ?? '?';

echo "[" . date('H:i:s') . "] Python trained on $sessUsed sessions, built vectors for $tagsTrained tags\n";
echo "[" . date('H:i:s') . "] Done. Took {$elapsed}s\n";

$conn->close();