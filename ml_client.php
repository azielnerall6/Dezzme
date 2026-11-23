<?php
/**
 * ml_client.php — PHP helper to talk to the Python microservice
 * Include this file in your existing search_recents.php
 * PHP stays the boss. Python just adds extra intelligence.
 */

define('ML_SERVICE_URL', 'http://127.0.0.1:5001');
define('ML_TIMEOUT_MS', 120); // 120ms max — if Python is slow, skip it

/**
 * Get ML-powered similar tags for a given tag.
 * Replaces looking up $semanticMap[$tag] manually.
 *
 * Usage:
 *   $similar = ml_similar_tags('naija', 8);
 *   // returns: [['tag' => '#afrobeats', 'similarity' => 0.87], ...]
 *   // falls back to [] if Python is down — PHP carries on fine
 */
function ml_similar_tags(string $tag, int $top_n = 10): array {
    $response = ml_post('/similar', ['tag' => $tag, 'top_n' => $top_n]);
    if (!$response || empty($response['similar'])) return [];
    return array_column($response['similar'], 'tag');
}

/**
 * Get ML boost scores for a list of candidate tags.
 * PHP adds these on top of its own $finalScore.
 *
 * Usage:
 *   $boosts = ml_score_boost(['#naija','#funny','#viral'], ['naija','afrobeats']);
 *   // returns: ['#naija' => 0.24, '#funny' => 0.08, '#viral' => 0.0]
 *   // falls back to [] (zero boosts) if Python is down — no effect on scoring
 */
function ml_score_boost(array $tags, array $user_tags): array {
    $response = ml_post('/boost', ['tags' => $tags, 'user_tags' => $user_tags]);
    if (!$response || empty($response['boosts'])) return [];
    return $response['boosts'];
}

/**
 * Tell Python to retrain its model.
 * Call this from a nightly cron job.
 *
 * Usage:
 *   ml_train($sessions);
 *   // $sessions = [['naija','afrobeats'],['cats','memes','funny'], ...]
 */
function ml_train(array $sessions): array {
    $response = ml_post('/train', ['sessions' => $sessions]);
    return $response ?? ['ok' => false];
}

/**
 * Check if Python service is alive.
 * Optional — good to log in monitoring.
 */
function ml_health(): bool {
    $response = ml_get('/health');
    return isset($response['status']) && $response['status'] === 'ok';
}

// ─────────────────────────────────────────
// Internal helpers — you don't need to call these directly
// ─────────────────────────────────────────

function ml_post(string $path, array $body): ?array {
    return ml_request('POST', $path, $body);
}

function ml_get(string $path): ?array {
    return ml_request('GET', $path, null);
}

function ml_request(string $method, string $path, ?array $body): ?array {
    $ch = curl_init(ML_SERVICE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS     => ML_TIMEOUT_MS,
        CURLOPT_CONNECTTIMEOUT_MS => 80,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$raw) return null; // Python is down — caller falls back gracefully
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}