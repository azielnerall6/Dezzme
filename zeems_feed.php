<?php
/* ================================================================
   zeems_feed.php — the algorithm's endless run for the Zeems player on pages that are not home / zeems (the messages page).
   It is shared_core.php answering ?feed_json=1 on its own (same ranking, same posts as home.php), without any page around it.
   Needs: shared_core.php in the same folder. Nothing else.
   v2 (hardened): GET / POST only · logged-in only · JSON + no-store headers · query values clamped · empty feed answers {"feed":[]}
   ================================================================ */
$DZ_PAGE = 'home'; $DZ_TEXT_ONLY = false;
$__m = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if($__m !== 'GET' && $__m !== 'POST'){
    http_response_code(405); header('Allow: GET, POST'); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['feed' => [], 'error' => 'method not allowed']); exit;
}

/* logged-in people only (the session is read here, then shared_core.php starts it the same way) */
if(session_status() !== PHP_SESSION_ACTIVE) @session_start();
if(empty($_SESSION['user_id'])){
    http_response_code(401); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode(['feed' => [], 'error' => 'not logged in']); exit;
}
session_write_close();   /* a slow ranking query must not block the person's other requests (shared_core.php reopens it if it needs to write) */

if($__m === 'GET'){
    /* keep only the keys the player sends, each forced to a safe shape — nothing else reaches the ranking code */
    $__q = [
        'feed_json' => '1', 'nolog' => '1',
        'limit' => (string)max(10, min(60,  (int)($_GET['limit'] ?? 30))),
        'page'  => (string)max(1,  min(200, (int)($_GET['page']  ?? 1))),
        'seed'  => (string)max(1,  min(104729, (int)($_GET['seed'] ?? 1))),
    ];
    if(isset($_GET['asof']) && (int)$_GET['asof'] > 0) $__q['asof'] = (string)(int)$_GET['asof'];
    /* no 'type' is passed on: that key would route to shared_core.php's other JSON handlers instead of the feed */
    $_GET = $__q; $_REQUEST = $__q;
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

require_once 'shared_core.php';
/* a POST (impressions / zeems_serves) is answered by shared_core.php itself; if nothing was answered, say so in a way the player understands */
if($__m === 'GET'){ header('Content-Type: application/json; charset=utf-8'); echo json_encode(['feed' => []]); }
else { http_response_code(204); }