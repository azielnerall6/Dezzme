<?php
/**
 * cron_index.php — Feed tag vocabulary to Python so embeddings stay current.
 *
 * Run nightly (or whenever your tag set grows):
 *   0 3 * * * php /var/www/html/cron_index.php >> /var/log/dezzme_cron.log 2>&1
 *
 * What it does:
 *   1. Pulls every known tag from memes captions + search_queries
 *   2. Cleans and deduplicates them
 *   3. POSTs the full list to Python /index so embeddings are built
 *   4. Logs results
 */

require __DIR__ . '/config.php';
require __DIR__ . '/ml_client.php';

$started = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] cron_index started\n";

/* ══════════════════════════════════════════
   1. Extract hashtags from memes captions
══════════════════════════════════════════ */

$captionTags = [];

$result = $conn->query(
    "SELECT caption FROM memes
     WHERE caption LIKE '%#%'
     ORDER BY views DESC, created_at DESC
     LIMIT 5000"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        preg_match_all('/#(\w+)/u', $row['caption'], $matches);
        foreach ($matches[1] as $tag) {
            $clean = strtolower(trim($tag));
            if (strlen($clean) >= 2 && strlen($clean) <= 60) {
                $captionTags[$clean] = true;
            }
        }
    }
    echo "[" . date('H:i:s') . "] Extracted " . count($captionTags) . " unique tags from memes captions\n";
} else {
    echo "[" . date('H:i:s') . "] WARNING: Could not query memes table: " . $conn->error . "\n";
}

/* ══════════════════════════════════════════
   2. Pull query terms from search_queries
══════════════════════════════════════════ */

$queryTags = [];

$result = $conn->query(
    "SELECT query_text FROM search_queries
     ORDER BY total_search_count DESC
     LIMIT 2000"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $q = strtolower(trim($row['query_text']));

        // extract any hashtags embedded in queries
        preg_match_all('/#(\w+)/u', $q, $matches);
        foreach ($matches[1] as $tag) {
            $clean = trim($tag);
            if (strlen($clean) >= 2 && strlen($clean) <= 60) {
                $queryTags[$clean] = true;
            }
        }

        // also treat the bare query as a tag if it looks like one
        $bare = ltrim($q, '#');
        if (strlen($bare) >= 2 && strlen($bare) <= 60 && !str_contains($bare, ' ')) {
            $queryTags[$bare] = true;
        }
    }
    echo "[" . date('H:i:s') . "] Extracted " . count($queryTags) . " terms from search_queries\n";
} else {
    echo "[" . date('H:i:s') . "] WARNING: Could not query search_queries table: " . $conn->error . "\n";
}

/* ══════════════════════════════════════════
   3. Pull tags from user signals too
      (catches long-tail tags users actually type)
══════════════════════════════════════════ */

$signalTags = [];

$result = $conn->query(
    "SELECT DISTINCT signal_value FROM search_user_signals
     WHERE signal_type = 'search'
     AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     LIMIT 3000"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $q = strtolower(trim($row['signal_value']));
        preg_match_all('/#(\w+)/u', $q, $matches);
        foreach ($matches[1] as $tag) {
            $clean = trim($tag);
            if (strlen($clean) >= 2 && strlen($clean) <= 60) {
                $signalTags[$clean] = true;
            }
        }
        $bare = ltrim($q, '#');
        if (strlen($bare) >= 2 && strlen($bare) <= 60 && !str_contains($bare, ' ')) {
            $signalTags[$bare] = true;
        }
    }
    echo "[" . date('H:i:s') . "] Extracted " . count($signalTags) . " terms from user signals\n";
} else {
    echo "[" . date('H:i:s') . "] WARNING: Could not query search_user_signals: " . $conn->error . "\n";
}

/* ══════════════════════════════════════════
   4. Merge + clean everything
══════════════════════════════════════════ */

$allTags = array_keys(array_merge($captionTags, $queryTags, $signalTags));

// Strip out junk
$banned = ['xxx', 'nude', 'porn', 'kill', 'hack', 'drugs', 'scam'];
$allTags = array_filter($allTags, function(string $tag) use ($banned): bool {
    foreach ($banned as $b) {
        if (str_contains($tag, $b)) return false;
    }
    return true;
});

// Prefix with # so Python stores them consistently
$allTags = array_values(array_map(fn($t) => '#' . $t, $allTags));

$total = count($allTags);
echo "[" . date('H:i:s') . "] Total unique tags to index: $total\n";

if ($total === 0) {
    echo "[" . date('H:i:s') . "] Nothing to index. Exiting.\n";
    exit(0);
}

/* ══════════════════════════════════════════
   5. POST to Python /index in batches
      (sentence-transformers can handle ~500 at once fine;
       batching avoids hitting PHP's timeout on huge vocab sets)
══════════════════════════════════════════ */

$batchSize  = 500;
$batches    = array_chunk($allTags, $batchSize);
$totalNew   = 0;
$totalSeen  = 0;
$batchErrors = 0;

foreach ($batches as $i => $batch) {
    $batchNum = $i + 1;
    $response = ml_post('/index', ['tags' => $batch]);

    if (!$response) {
        echo "[" . date('H:i:s') . "] ERROR: Batch $batchNum failed (Python down or timeout)\n";
        $batchErrors++;
        continue;
    }

    $newly  = (int)($response['newly_indexed'] ?? 0);
    $total_ = (int)($response['total_indexed'] ?? 0);
    $totalNew  += $newly;
    $totalSeen += (count($batch) - $newly);

    echo "[" . date('H:i:s') . "] Batch $batchNum/" . count($batches) . ": "
       . count($batch) . " sent, $newly newly embedded, $total_ total in Python\n";

    // Small pause between batches so we don't hammer the GPU
    if ($batchNum < count($batches)) usleep(200_000); // 200ms
}

/* ══════════════════════════════════════════
   6. Summary
══════════════════════════════════════════ */

$elapsed = round(microtime(true) - $started, 2);

if ($batchErrors > 0) {
    echo "[" . date('H:i:s') . "] FINISHED WITH ERRORS: $batchErrors batch(es) failed\n";
} else {
    echo "[" . date('H:i:s') . "] Done. $totalNew new embeddings built, $totalSeen already known. Took {$elapsed}s\n";
}

$conn->close();