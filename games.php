<?php
/* ══════════════════════════════════════════════════════════════
   SESSION — hardened cookie (same as tracks.php)
══════════════════════════════════════════════════════════════ */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* DATA SAVER: gzip every response (page + JSON) — cuts the transfer size by ~70% */
if (extension_loaded('zlib') && !ini_get('zlib.output_compression') && !headers_sent()) {
    ob_start('ob_gzhandler');
}

include 'config.php';

/* offline helpers (same ones the Home page uses: banner + full-screen "No internet" state).
   shared_core.php normally loads offline.php; here we load it directly if it isn't already loaded. */
if (!function_exists('dz_offline_ui') && file_exists(__DIR__ . '/offline.php')) {
    require_once __DIR__ . '/offline.php';
}

$current_user = (int)($_SESSION['user_id'] ?? 0);
if (!$current_user) { header("Location: login.php"); exit(); }

/* ══════════════════════════════════════════════════════════════
   CSRF (same scheme as tracks.php)
══════════════════════════════════════════════════════════════ */
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function csrf_same_origin(): bool {
    $host = strtolower((string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (!empty($_SERVER[$h])) {
            $src = strtolower((string)parse_url($_SERVER[$h], PHP_URL_HOST));
            return ($src !== '' && $src === $host);
        }
    }
    return true;
}
function csrf_verify(): bool {
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || $sent === '') return false;
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) return false;
    return csrf_same_origin();
}

/* ══════════════════════════════════════════════════════════════
   ADS JSON  (?type=ads)
   Same ads the home page shows: memes flagged is_ad = 1 whose meme_ads row is approved and has a link.
   Respects "Hide all Ads from…" (hidden_ad_advertisers), blocks, archived posts and deactivated people.
══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['type'] ?? '') === 'ads')) {
    $uid_ads = $current_user;
    session_write_close();   // read-only: don't hold the session lock
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $rows = [];
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS hidden_ad_advertisers (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, hidden_user_id INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_hide (user_id, hidden_user_id))");
        $res = $conn->query("
            SELECT memes.id, memes.file_name, memes.type, memes.caption,
                   ma.cta_url, ma.cta_text, ma.cta_color
            FROM memes
            JOIN meme_ads ma ON ma.meme_id = memes.id
            JOIN users ON users.id = memes.uploaded_by
            WHERE memes.is_ad = 1
              AND memes.is_archived = 0 AND users.deactivated_at IS NULL
              AND ma.status = 'approved'
              AND ma.cta_url IS NOT NULL AND ma.cta_url != ''
              AND memes.uploaded_by NOT IN (SELECT hidden_user_id FROM hidden_ad_advertisers WHERE user_id = $uid_ads)
              AND memes.uploaded_by NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = $uid_ads)
            ORDER BY RAND()
            LIMIT 8
        ");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if (!preg_match('#^https?://#i', (string)$r['cta_url'])) continue;   // only real web links
                $rows[] = [
                    'id'      => (int)$r['id'],
                    'file'    => 'uploads/' . $r['file_name'],
                    'type'    => $r['type'],
                    'caption' => $r['caption'],
                    'ctaUrl'  => $r['cta_url'],
                    'ctaText' => $r['cta_text'],
                    'ctaColor'=> $r['cta_color'] ?: '#FF4C4C',
                ];
            }
        }
    } catch (Throwable $e) { $rows = []; }

    echo json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

/* ══════════════════════════════════════════════════════════════
   GAME REGISTRY
   max  = hard ceiling for a single score
   rate = most points a human can plausibly earn per second
   tag  = small badge on the card (optional)
══════════════════════════════════════════════════════════════ */
$GAMES = [
    'brickbreak'=> ['name' => 'Brick Breaker',    'tag' => 'NEW · 3 LEVELS', 'desc' => 'Bounce the ball, smash every brick, clear wave after wave.', 'max' => 20000, 'rate' => 60],
    'connect4'  => ['name' => 'Connect 4',        'tag' => 'NEW · 3 LEVELS', 'desc' => 'Drop discs and connect four before the computer does.',      'max' => 450,   'rate' => 40],
    'memory'    => ['name' => 'Memory Match',     'tag' => 'NEW · 3 LEVELS', 'desc' => 'Flip cards, find every pair. Faster and cleaner scores more.', 'max' => 600,   'rate' => 20],
    'invaders'  => ['name' => 'Neon Invaders',    'tag' => 'NEW · 3 LEVELS', 'desc' => 'Your ship fires itself. Dodge the bombs and stop the wave.', 'max' => 20000, 'rate' => 80],
    'hoppibara' => ['name' => 'Hoppibara',        'by' => 'Goodgis', 'desc' => 'Hop over oranges, boxes and other baras.',      'max' => 3000,  'rate' => 2.4],
    'ringrush'  => ['name' => 'Neon Rush 3D',     'tag' => 'NEW · 3D', 'desc' => 'Fly through a neon 3D tunnel. Thread every ring.', 'max' => 20000, 'rate' => 55],
    'hilldash'  => ['name' => 'Hill Dash',        'tag' => 'NEW · 4 CARS', 'desc' => 'Pick a car and a terrain. Climb hills, grab fuel and coins.', 'max' => 40000, 'rate' => 80],
    'capyworld' => ['name' => 'Capy World',       'tag' => 'NEW · OPEN WORLD', 'desc' => 'Endless wildlife world: tame animals, enter villages, survive the night.', 'max' => 30000, 'rate' => 25],
    'gravflip'  => ['name' => 'Gravity Flip',     'tag' => 'NEW', 'desc' => 'Flip gravity to run on the floor and the ceiling.', 'max' => 25000, 'rate' => 60],
    'chess'     => ['name' => 'Chess',            'tag' => 'NEW', 'desc' => 'Play white against the computer. 3 levels.',     'max' => 800,   'rate' => 8],
    'match3'    => ['name' => 'Candy Match',      'tag' => 'NEW', 'desc' => 'Swap sweets, match 3+, chain big combos.',       'max' => 15000, 'rate' => 250],
    'cakestack' => ['name' => 'Cake Stack',       'tag' => 'NEW', 'desc' => 'Time your drops and build the tallest cake.',    'max' => 2000,  'rate' => 6.5],
    'cardodge'  => ['name' => 'Car Dodge Race',   'tag' => 'NEW', 'desc' => 'Weave through traffic. It only gets faster.',    'max' => 3000,  'rate' => 5.5],
    'woodblock' => ['name' => 'Wood Block Puzzle','tag' => 'NEW', 'desc' => 'Drag blocks onto the board. Clear rows and columns.', 'max' => 50000, 'rate' => 80],
    'bubbles'   => ['name' => 'Bubble Shooter',   'tag' => 'NEW', 'desc' => 'Aim, pop color chains and beat the falling ceiling.', 'max' => 20000, 'rate' => 120],
    'whackabara'=> ['name' => 'Whack-a-Bara',     'tag' => 'NEW', 'desc' => 'Whack the capybaras in 45 seconds. Dodge the bombs!', 'max' => 3000,  'rate' => 85],
    'minesweeper'=> ['name' => 'Minesweeper',     'tag' => 'NEW · DAILY', 'desc' => 'Flag the mines. A new daily board for everyone.', 'max' => 800,   'rate' => 18],
    'snake'     => ['name' => 'Snake',            'desc' => 'Eat, grow, don’t crash.',                'max' => 3000,   'rate' => 50],
    'pacman'    => ['name' => 'Pac-Man',          'desc' => 'Clear the maze. Dodge the ghosts.',      'max' => 60000,  'rate' => 150],
    'flappy'    => ['name' => 'Flappy Bird',      'desc' => 'Tap to flap through the pipes.',         'max' => 1000,   'rate' => 1.6],
    'g2048'     => ['name' => '2048',             'desc' => 'Slide and merge tiles up to 2048.',      'max' => 100000, 'rate' => 120],
    'sudoku'    => ['name' => 'Sudoku',           'desc' => 'Easy, Medium and Hard number puzzles.',  'max' => 400,    'rate' => 6],
];

/* ══════════════════════════════════════════════════════════════
   POST ACTIONS — start_run / save_score / leaderboard
══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => 'csrf']);
        exit();
    }

    $action = $_POST['action'] ?? '';
    $game   = (string)($_POST['game'] ?? '');
    if (!isset($GAMES[$game])) {
        echo json_encode(['status' => 'error', 'msg' => 'unknown_game']);
        exit();
    }
    $G = $GAMES[$game];

    if ($action === 'start_run') {
        // server-side clock: the client can't fake how long a run lasted
        $_SESSION['gm_run'][$game] = time();
        echo json_encode(['status' => 'ok']);

    } elseif ($action === 'save_score') {
        $score = (int)($_POST['score'] ?? 0);
        $start = (int)($_SESSION['gm_run'][$game] ?? 0);
        unset($_SESSION['gm_run'][$game]);

        if (!$start) { echo json_encode(['status' => 'error', 'msg' => 'no_run']); exit(); }
        $elapsed = max(1, time() - $start);
        $limit   = min($G['max'], (int)ceil($G['rate'] * $elapsed) + 30);
        if ($score < 0 || $score > $limit) { echo json_encode(['status' => 'error', 'msg' => 'invalid']); exit(); }

        // previous best (to tell the player if this one is a new best)
        $prev = 0;
        $st = $conn->prepare("SELECT best_score FROM game_scores WHERE user_id=? AND game=? LIMIT 1");
        $st->bind_param('is', $current_user, $game);
        $st->execute();
        $st->bind_result($prev);
        $st->fetch();
        $st->close();

        $st = $conn->prepare("
            INSERT INTO game_scores (user_id, game, best_score, last_score, plays)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                best_score = GREATEST(best_score, VALUES(best_score)),
                last_score = VALUES(last_score),
                plays      = plays + 1
        ");
        $st->bind_param('isii', $current_user, $game, $score, $score);
        $st->execute();
        $st->close();

        $st = $conn->prepare("INSERT INTO game_runs (user_id, game, score, duration_sec) VALUES (?, ?, ?, ?)");
        $st->bind_param('isii', $current_user, $game, $score, $elapsed);
        $st->execute();
        $st->close();

        echo json_encode(['status' => 'ok', 'best' => max((int)$prev, $score), 'new_best' => ($score > (int)$prev && $score > 0)]);

    } elseif ($action === 'leaderboard') {
        session_write_close();   // read-only action: release the session lock so other requests never queue behind it
        $rows = [];
        $st = $conn->prepare("
            SELECT u.id, u.username, u.nickname, u.profile_pic, g.best_score
            FROM game_scores g
            JOIN users u ON u.id = g.user_id
            WHERE g.game = ? AND g.best_score > 0
            ORDER BY g.best_score DESC, g.updated_at ASC
            LIMIT 20
        ");
        $st->bind_param('s', $game);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'       => (int)$r['id'],
                'username' => $r['username'],
                'nick'     => $r['nickname'] ?? '',
                'pic'      => $r['profile_pic'] ?: 'uploads/me.png',
                'score'    => (int)$r['best_score'],
                'me'       => ((int)$r['id'] === $current_user),
            ];
        }
        $st->close();

        $mine = 0;
        $st = $conn->prepare("SELECT best_score FROM game_scores WHERE user_id=? AND game=? LIMIT 1");
        $st->bind_param('is', $current_user, $game);
        $st->execute();
        $st->bind_result($mine);
        $st->fetch();
        $st->close();

        $rank = 0;
        if ($mine > 0) {
            $st = $conn->prepare("SELECT COUNT(*) + 1 FROM game_scores WHERE game=? AND best_score > ?");
            $st->bind_param('si', $game, $mine);
            $st->execute();
            $st->bind_result($rank);
            $st->fetch();
            $st->close();
        }
        echo json_encode(['status' => 'ok', 'rows' => $rows, 'me' => ['best' => (int)$mine, 'rank' => (int)$rank]]);

    } else {
        echo json_encode(['status' => 'error', 'msg' => 'unknown_action']);
    }
    exit();
}

/* ── my best scores for the list ── */
$my_best = [];
foreach ($GAMES as $k => $_) $my_best[$k] = 0;
$st = $conn->prepare("SELECT game, best_score FROM game_scores WHERE user_id=?");
$st->bind_param('i', $current_user);
$st->execute();
$res = $st->get_result();
while ($r = $res->fetch_assoc()) if (isset($my_best[$r['game']])) $my_best[$r['game']] = (int)$r['best_score'];
$st->close();

/* right column: my top 5 games by best score (only games I've actually played) */
$my_top = array_filter($my_best, function($v){ return $v > 0; });
arsort($my_top);
$my_top = array_slice($my_top, 0, 5, true);

/* shared navigation: rendered here (session still open, so nav.php can use it) and echoed in the page body */
ob_start();
include 'nav.php';
$nav_html = ob_get_clean();

session_write_close();   // page render doesn't write the session: free the lock for the game's save/leaderboard requests

/* ── inline icon set (no icon-font download) ── */
$ICONS = [
    'search'  => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
    'play'    => '<path d="M7 4l13 8-13 8z" fill="currentColor" stroke="none"/>',
    'vol'     => '<path d="M4 9v6h4l5 4V5L8 9z"/><path d="M16 9a4 4 0 010 6M18.5 6.5a8 8 0 010 11"/>',
    'mute'    => '<path d="M4 9v6h4l5 4V5L8 9z"/><path d="M17 9l5 6M22 9l-5 6"/>',
    'pause'   => '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
    'erase'   => '<path d="M20 5H9l-6 7 6 7h11z"/><path d="M13 9l4 6M17 9l-4 6"/>',
    'pencil'  => '<path d="M4 20l1-5L16 4l4 4L9 19z"/>',
    'gamepad' => '<rect x="2" y="7" width="20" height="11" rx="5"/><path d="M7 10v5M4.5 12.5h5"/><circle cx="16" cy="11.5" r="1"/><circle cx="18.5" cy="14" r="1"/>',
    'ghost'   => '<path d="M5 21V11a7 7 0 0114 0v10l-3-2-2 2-2-2-2 2-2-2z"/><circle cx="9.5" cy="11" r="1"/><circle cx="14.5" cy="11" r="1"/>',
    'bird'    => '<path d="M3 15c4 0 6-3 8-7 3 1 6 4 6 8-5 2-10 2-14-1z"/><path d="M17 12l4-1"/>',
    'heart'   => '<path d="M12 20s-8-5-8-11a4.5 4.5 0 018-2.5A4.5 4.5 0 0120 9c0 6-8 11-8 11z" fill="currentColor" stroke="none"/>',
    'hash'    => '<path d="M9 4L7 20M17 4l-2 16M4 9h17M3 15h17"/>',
    'grid'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/>',
    'crown'   => '<path d="M4 19h16M5 19l-1-11 5 4 3-7 3 7 5-4-1 11z"/>',
    'cake'    => '<path d="M4 20h16v-7H4z"/><path d="M4 16c3 2 5-2 8 0s5-2 8 0M12 13V9"/><path d="M12 9c-1.2-1-1.2-2.2 0-3.4 1.2 1.2 1.2 2.4 0 3.4z"/>',
    'candy'   => '<circle cx="12" cy="12" r="5"/><path d="M8 8L3 5l1 6zM16 16l5 3-1-6z"/>',
    'car'     => '<path d="M3 16v-4l2-5h14l2 5v4zM3 16v2M21 16v2"/><circle cx="7.5" cy="16" r="1.5"/><circle cx="16.5" cy="16" r="1.5"/>',
    'block'   => '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/>',
    'bubble'  => '<circle cx="9" cy="9" r="5"/><circle cx="17" cy="15" r="4"/><circle cx="7" cy="18" r="2.5"/>',
    'paw'     => '<circle cx="12" cy="16" r="4"/><circle cx="6" cy="11" r="2"/><circle cx="10" cy="7" r="2"/><circle cx="14" cy="7" r="2"/><circle cx="18" cy="11" r="2"/>',
    'bomb'    => '<circle cx="11" cy="14" r="7"/><path d="M16 8l3-3M17 3.5l3.5 3.5M19 2v3"/>',
    'flag'    => '<path d="M5 21V4M5 4h12l-3 4 3 4H5"/>',
    'mountain'=> '<path d="M2 20l7-12 4 6 3-4 6 10z"/>',
    'compass' => '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5l-2 5-5 2 2-5z"/>',
    'flip'    => '<path d="M7 4v14M3 14l4 4 4-4M17 20V6M13 10l4-4 4 4"/>',
    'cube'    => '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/>',
    'megaphone'=> '<path d="M3 11v3a1 1 0 001 1h2l7 4V6L6 10H4a1 1 0 00-1 1z"/><path d="M16 9a4 4 0 010 6"/>',
    'trophy'  => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 01-10 0z"/><path d="M7 6H4v2a3 3 0 003 3M17 6h3v2a3 3 0 01-3 3"/>',
    'info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>',
    'chev'    => '<path d="M6 9l6 6 6-6"/>',
    'home'    => '<path d="M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10"/>',
    'msg'     => '<path d="M4 5h16v11H9l-5 4z"/>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="referrer" content="same-origin">
<title>Games · Dezzme</title>
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<!-- Poppins (4 weights) + Bebas Neue (only used for the "Games" word in the top bar, same as the Home page) are loaded once
     with font-display:swap and cached by the browser; other pages that use the same URL share that cache.
     No icon font, images, audio files or game libraries are downloaded:
     every game is drawn and synthesised by the code below, so playing uses no extra data.
     (The only extra data is the ad banner media, loaded once when the page opens.) -->
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Bebas+Neue&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a{ color:inherit; text-decoration:none; }

.ic{ width:1em; height:1em; display:block; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; }

:root{
    --fg:255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text:#ffffff;
    --solid:#070924;

    --red:#FF2D2D; --track-red:#FF2D2D; --track-red-hover:#ff1a1a;
    --red-soft:rgba(255,45,45,.14); --red-text:#ff8a8a;
    --bg:linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:rgba(var(--fg),0.05);
    --line:rgba(var(--fg),0.09);
    --line2:rgba(var(--fg),0.14);
    --text:#ffffff; --muted:rgba(var(--fg),0.45); --sub:rgba(var(--fg),0.62);
    --ov:rgba(1,2,24,.72);          /* game overlay (pause / game over / level picker) */
    --ico-bg:#0d1030;               /* right-column game icon tile */
    --ph:#111226;                   /* image placeholders */
    --ok:#4ade80;                   /* "new best" green */
    --sd-blue:#7fb2ff;              /* sudoku player numbers */
    --scroll-thumb:rgba(var(--fg),.22);

    --max-w:580px; --topbar-h:54px;
    --pad:clamp(9px, 3vw, 16px);
    --rp-action-h:clamp(25px, 7.2vw, 30px);
    --rp-action-radius:4px; --rp-pill-radius:5px;
    --sidebar-w:100px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; }
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}
body{
    background:var(--bg); background-attachment:fixed;
    color:var(--text); min-height:100vh;
    overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}
/* desktop: leave room for the nav.php sidebar, same as the home page */
@media (min-width:769px){ body{ padding-left:var(--sidebar-w); } }

/* shell = lane (+ right column on laptops) */
.gm-shell{ width:100%; min-height:100vh; display:flex; justify-content:center; align-items:flex-start; }

/* lane */
.gm-lane{ width:100%; max-width:var(--max-w); min-height:100vh; display:flex; flex-direction:column; }
@media (min-width:801px){ .gm-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .gm-lane{ max-width:100%; } }

/* topbar */
.gm-topbar{
    position:sticky; top:0; z-index:500; height:var(--topbar-h);
    display:flex; align-items:center; gap:2px; padding:0 10px;
    background:var(--bg); background-attachment:fixed;
    border-bottom:1px solid var(--line);
}
.gm-x{
    width:34px; height:34px; background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s; padding:0; flex-shrink:0;
}
.gm-x:hover{ opacity:1; color:var(--red); }
.gm-x svg{ width:22px; height:22px; display:block; }
.gm-title{
    font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    min-width:0; flex:1; text-align:right; margin:0 6px 0 10px;
}
/* "Games" word in the top bar: same look as the "Dezzme" word on the Home page (Bebas Neue, wide letter-spacing),
   but white in dark mode / black in light mode instead of the brand red */
.gm-title.left,
html[data-theme="light"] .gm-title.left{
    font-family:'Bebas Neue',sans-serif !important;
    font-weight:400 !important;
    color:var(--ui-text) !important;
    font-size:clamp(24px,6.5vw,32px) !important;
    letter-spacing:clamp(5px,1.8vw,8px) !important;
    line-height:1 !important;
    padding-left:clamp(5px,1.8vw,8px) !important;
    text-transform:none;
    text-align:left;
    margin:0 6px 0 4px;
}
@media (max-width:768px){
    .gm-title.left,
    html[data-theme="light"] .gm-title.left{ font-size:clamp(24px,6.8vw,31px) !important; }
}
@media (max-width:490px){ .gm-x{ width:30px; height:30px; } .gm-title{ font-size:.84rem; } }

/* search */
.tk-search{ position:relative; display:flex; align-items:center; padding:12px var(--pad) 8px; }
.tk-search svg{ position:absolute; left:calc(var(--pad) + 11px); color:var(--muted); width:1rem; height:1rem; pointer-events:none; }
.tk-search input{
    width:100%; height:clamp(30px, 8vw, 36px);
    background:var(--surface); border:1px solid var(--line2); border-radius:var(--rp-pill-radius);
    padding:0 12px 0 34px; font-size:.76rem; color:var(--text); outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.tk-search input::placeholder{ color:var(--muted); }
.tk-search input:focus{ border-color:rgba(255,45,45,.7); background:rgba(var(--fg),0.07); box-shadow:0 0 0 3px rgba(255,45,45,.12); }

.gm-note{ font-size:.7rem; color:var(--muted); line-height:1.55; padding:4px var(--pad) 12px; border-bottom:1px solid var(--line); }

/* ══════════════════════════════════════
   AD BANNER — rectangular, slides by itself, tap opens the advertiser's link
   (the media sits on its own dark layer in both themes, so its white text stays readable)
══════════════════════════════════════ */
.gm-ads{ padding:14px var(--pad) 0; display:block; }
.gm-ads.gone{ display:none; }
.gm-ads-skel{ width:100%; aspect-ratio:16/9; border-radius:2px; }
.gm-ads-box{ position:relative; border-radius:2px; overflow:hidden; background:#04051a; border:1px solid var(--line); display:none; touch-action:pan-y; user-select:none; -webkit-user-select:none; }
.gm-ads.ready .gm-ads-box{ display:block; }
.gm-ads.ready .gm-ads-skel{ display:none; }
.gm-ads-track{ display:flex; width:100%; transition:transform .45s cubic-bezier(.22,1,.36,1); will-change:transform; }
.gm-ads-track.drag{ transition:none; }
.gm-ad{ position:relative; flex:0 0 100%; aspect-ratio:16/9; display:block; overflow:hidden; background:#04051a; }
.gm-ad-media{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; -webkit-user-drag:none; pointer-events:none; }
.gm-ad::after{ content:''; position:absolute; left:0; right:0; bottom:0; height:62%; background:linear-gradient(0deg, rgba(0,0,0,.78), transparent); pointer-events:none; }
.gm-ad-tag{
    position:absolute; top:8px; left:8px; z-index:2;
    background:rgba(0,0,0,.55); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    color:#FF3B30; font-size:.6rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase;
    padding:3px 8px; border-radius:2px;
}
.gm-ad-body{ position:absolute; left:12px; right:12px; bottom:12px; z-index:2; }
.gm-ad-cap{ flex:1; min-width:0; font-size:.74rem; font-weight:500; line-height:1.4; color:#fff; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; text-shadow:0 1px 4px rgba(0,0,0,.6); }
.gm-ad-cta{
    position:absolute; top:8px; right:8px; z-index:3; max-width:55%; height:var(--rp-action-h); padding:0 13px;
    display:flex; align-items:center; border-radius:2px;
    background:var(--cta,#FF4C4C); color:#000; font-size:.7rem; font-weight:700; line-height:1;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
@media (max-width:800px){
    .gm-ads{ padding-top:15.4px; }
    .gm-ad-cap{ font-size:.814rem; }
    .gm-ad-cta{ font-size:.77rem; }
    .gm-ad-tag{ font-size:.66rem; }
}
@media (max-width:490px){ .gm-ad-cap{ font-size:.75rem; } .gm-ad-cta{ padding:0 10px; font-size:.7rem; } }

/* ad box repeated inside the games list: one after every 6 games, full width (spans both columns) */
.gm-list .gm-ads-inline{ grid-column:1 / -1; padding:0 !important; }
.gm-list .gm-ads-inline.gone{ display:none; }

/* game cards (live preview image on top — the preview stays dark, it is the game's own artwork) */
.gm-list{ display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:clamp(8px, 2.6vw, 14px); padding:14px var(--pad) 40px; }
.gm-list .empty-state{ grid-column:1 / -1; }
.gm-card{
    display:flex; flex-direction:column; background:var(--surface);
    border:1px solid var(--line); border-radius:10px; overflow:hidden; cursor:pointer;
    transition:border-color .2s, transform .2s, background .2s;
}
.gm-card:hover{ border-color:rgba(255,45,45,.5); background:rgba(var(--fg),.07); transform:translateY(-2px); }
.gm-card:active{ transform:scale(.985); }
.gm-thumb{ position:relative; aspect-ratio:16/10; background:#04051a; overflow:hidden; border-bottom:1px solid var(--line); }
.gm-thumb canvas{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; transition:transform .35s ease; }
.gm-card:hover .gm-thumb canvas{ transform:scale(1.05); }
.gm-by{
    position:absolute; top:8px; left:8px; z-index:2;
    background:rgba(0,0,0,.55); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    color:#fff; font-size:.58rem; font-weight:600; padding:3px 7px; border-radius:4px;
}
.gm-tag{
    position:absolute; top:8px; right:8px; z-index:2;
    background:var(--red); color:#000; font-size:.54rem; font-weight:700; letter-spacing:.4px;
    padding:3px 7px; border-radius:4px;
}
.gm-play{
    position:absolute; left:50%; top:50%; width:44px; height:44px; margin:-22px 0 0 -22px; z-index:2;
    border-radius:50%; background:rgba(255,45,45,.94); color:#000;
    display:flex; align-items:center; justify-content:center; font-size:1.3rem;
    opacity:0; transform:scale(.8); transition:opacity .2s, transform .2s; pointer-events:none;
}
.gm-card:hover .gm-play{ opacity:1; transform:scale(1); }
.gm-body{ padding:10px 12px 12px; display:flex; flex-direction:column; flex:1; }
.gm-name{ font-size:.84rem; font-weight:600; color:var(--ui-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gm-desc{ font-size:.66rem; color:var(--muted); margin-top:2px; line-height:1.5; min-height:3em; }
.gm-bestline{ font-size:.64rem; color:var(--sub); margin-top:4px; }
.gm-bestline b{ color:var(--red-text); font-weight:600; }
.gm-body .tk-btn{ width:100%; margin-top:10px; }
@media (max-width:490px){ .gm-name{ font-size:.76rem; } .gm-desc{ font-size:.6rem; } .gm-bestline{ font-size:.6rem; } }

.tk-btn{
    display:flex; align-items:center; justify-content:center; gap:6px;
    flex-shrink:0; min-width:84px; height:var(--rp-action-h); padding:0 12px;
    border:none; border-radius:var(--rp-action-radius);
    font-size:.72rem; font-weight:600; line-height:1; color:var(--ui-text); white-space:nowrap;
    transition:background .2s ease, transform .15s ease;
}
.tk-btn:active{ transform:scale(0.96); }
.tk-btn.track{ background:var(--track-red); color:#000; font-weight:700; }
.tk-btn.track:hover{ background:var(--track-red-hover); }
.tk-btn.ghost{ background:rgba(var(--fg),.12); color:var(--ui-text); font-weight:600; }
.tk-btn.ghost:hover{ background:rgba(var(--fg),.22); }
#gmPause{ font-size:1.15rem; margin-left:2px; }
@media (max-width:490px){ .tk-btn{ min-width:72px; font-size:.64rem; padding:0 9px; } }

.empty-state{ padding:48px 20px; text-align:center; color:var(--muted); display:none; flex-direction:column; align-items:center; gap:10px; }
.empty-state svg{ width:2.1rem; height:2.1rem; color:var(--red); opacity:.85; }
.empty-state span{ font-size:.8rem; }

/* ══════════════════════════════════════
   RIGHT COLUMN — laptop / normal desktop view only (≥1100px), same as the home page
══════════════════════════════════════ */
.gm-rail{ display:none; }
@media (min-width:1100px){
    .gm-shell{ justify-content:flex-start; padding-left:22px; }
    .gm-lane{ flex:0 0 var(--max-w); width:var(--max-w); }
    .gm-rail{
        display:block; flex:1 1 0; min-width:0; max-width:720px;
        position:sticky; top:0; height:100vh; overflow-y:auto; overflow-x:hidden;
        padding:14px 26px 40px 22px;
        scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent;
    }
    .gm-rail::-webkit-scrollbar{ width:5px; }
    .gm-rail::-webkit-scrollbar-track{ background:transparent; }
    .gm-rail::-webkit-scrollbar-thumb{ background:var(--scroll-thumb); border-radius:99px; }
    .gm-rail::-webkit-scrollbar-thumb:hover{ background:rgba(var(--fg),.34); }
}

.pf-card{ margin-bottom:14px; }
.pf-bar{
    display:flex; align-items:center; width:100%; height:var(--rp-action-h);
    border:none; border-radius:var(--rp-action-radius); background:rgba(var(--fg),.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); color:var(--ui-text); font-weight:600; line-height:1;
    gap:7px; padding:0 12px; font-size:.72rem; text-align:left;
    transition:background .2s ease, transform .15s ease;
}
.pf-bar:hover{ background:rgba(var(--fg),.2); }
.pf-bar:active{ transform:scale(.99); }
.pf-bar-ic{ width:14px; height:14px; color:var(--red); flex-shrink:0; }
.pf-bar-t{ flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pf-bar-c{ font-size:.64rem; font-weight:600; color:rgba(var(--fg),.55); flex-shrink:0; }
.pf-bar-chev{ width:18px; height:18px; color:rgba(var(--fg),.75); flex-shrink:0; transition:transform .25s ease; }
.pf-card.collapsed .pf-bar-chev{ transform:rotate(-90deg); }
.pf-body{ display:grid; grid-template-rows:1fr; transition:grid-template-rows .3s ease; }
.pf-inner{ min-height:0; overflow:hidden; }
.pf-pad{ padding-top:8px; }
.pf-card.collapsed .pf-body{ grid-template-rows:0fr; }

.pf-row{ display:flex; align-items:center; gap:10px; padding:8px 2px; color:var(--ui-text); border-bottom:1px solid rgba(var(--fg),.06); cursor:pointer; }
.pf-row:last-of-type{ border-bottom:none; }
.pf-row:hover .pf-row-t{ text-decoration:underline; }
.pf-ico{ width:34px; height:34px; border-radius:8px; border:1.5px solid rgba(var(--fg),.14); background:var(--ico-bg); color:var(--red); display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
.pf-row-info{ flex:1; min-width:0; }
.pf-row-t{ display:block; font-size:.76rem; font-weight:600; color:var(--ui-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pf-row-s{ font-size:.64rem; color:rgba(var(--fg),.5); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pf-rank{ flex-shrink:0; font-size:.78rem; font-weight:700; color:var(--ui-text); }
.pf-empty{ font-size:.72rem; line-height:1.7; color:rgba(var(--fg),.55); padding:4px 2px; }

.pf-about{ padding:4px 2px 2px; }
.pf-about p{ font-size:.72rem; line-height:1.7; color:rgba(var(--fg),.72); margin-bottom:9px; }
.pf-about p:last-child{ margin-bottom:0; }
.pf-about b{ color:var(--ui-text); font-weight:600; }

/* Sponsored list (right column) */
.rs-ads-list{ display:flex; flex-direction:column; gap:8px; }
.rs-ad-card{ display:flex; gap:10px; align-items:flex-start; padding:6px; border-radius:10px; transition:background .15s; }
.rs-ad-card:hover{ background:rgba(var(--fg),.06); }
.rs-ad-thumb{ width:72px; height:72px; border-radius:8px; object-fit:cover; background:var(--ph); flex-shrink:0; display:block; }
.rs-ad-info{ flex:1; min-width:0; padding-top:2px; }
.rs-ad-caption{ font-size:.76rem; line-height:1.35; color:var(--ui-text); display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:5px; }
.rs-ad-tag{ display:inline-block; font-size:.6rem; font-weight:800; color:#FF3B30; letter-spacing:.04em; text-transform:uppercase; }
.rs-ad-skel{ display:flex; gap:10px; padding:6px; }
.rs-ad-skel-thumb{ width:72px; height:72px; border-radius:8px; flex-shrink:0; }
.rs-ad-skel-lines{ flex:1; display:flex; flex-direction:column; gap:6px; padding-top:4px; }
.rs-ad-skel-line{ height:9px; border-radius:5px; }
.rs-ad-skel-line.w1{ width:92%; } .rs-ad-skel-line.w2{ width:65%; } .rs-ad-skel-line.w3{ width:28%; height:8px; }
.rs-divider{ height:1px; background:rgba(var(--fg),.09); margin:0 4px 6px; }
.rs-links{ display:flex; flex-direction:column; gap:2px; }
.rs-link-row{ display:flex; align-items:center; gap:12px; padding:10px 6px; border-radius:8px; color:var(--ui-text); font-size:.84rem; font-weight:600; transition:background .15s; }
.rs-link-row:hover{ background:rgba(var(--fg),.06); }
.rs-link-row svg{ width:17px; height:17px; color:#ff2a2a; flex-shrink:0; }

/* shimmer for the ad skeletons */
.gm-ads-skel, .rs-ad-skel-thumb, .rs-ad-skel-line{
    background:linear-gradient(90deg, rgba(var(--fg),.05) 25%, rgba(var(--fg),.13) 37%, rgba(var(--fg),.05) 63%);
    background-size:400% 100%; animation:gmShimmer 1.4s ease-in-out infinite;
}
@keyframes gmShimmer{ 0%{ background-position:100% 50%; } 100%{ background-position:0% 50%; } }

/* ══════════════════════════════════════
   GAME MODAL — full screen, same lane, X closes
══════════════════════════════════════ */
.gm-modal{
    position:fixed; inset:0; z-index:2147483647;
    display:none; justify-content:center;
    background:var(--solid);
    height:100vh; height:100dvh;
}
.gm-modal.open{ display:flex; animation:gmIn .22s ease both; }
@keyframes gmIn{ from{ opacity:0; transform:translateY(14px); } to{ opacity:1; transform:none; } }

.gm-mlane{
    width:100%; max-width:var(--max-w); height:100%;
    display:flex; flex-direction:column; min-height:0;
}
@media (min-width:801px){ .gm-mlane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .gm-mlane{ max-width:100%; } }

.gm-modal .gm-topbar{ background:var(--solid); background-image:none; }
.gm-mtabs{ display:flex; border-bottom:1px solid var(--line); flex-shrink:0; }
.gm-mtab{
    flex:1; padding:10px 8px; background:none; border:none;
    color:rgba(var(--fg),.5); font-size:.74rem; font-weight:600;
    position:relative; transition:color .2s;
}
.gm-mtab.active{ color:var(--ui-text); }
.gm-mtab.active::after{ content:''; position:absolute; left:20%; right:20%; bottom:0; height:2px; background:var(--ui-text); border-radius:2px 2px 0 0; }

.gm-panel{ flex:1; min-height:0; display:none; flex-direction:column; }
.gm-panel.show{ display:flex; }

.gm-hud{
    display:grid; grid-template-columns:1fr auto 1fr; align-items:center; gap:8px;
    padding:8px var(--pad); border-bottom:1px solid var(--line); flex-shrink:0;
    font-size:.68rem; color:var(--muted);
}
.gm-hud b{ color:var(--ui-text); font-size:.9rem; font-weight:700; margin-left:4px; }
.gm-hud .mid{ color:var(--red-text); font-weight:600; text-align:center; }
.gm-hud .r{ text-align:right; }

.gm-stage{
    position:relative; flex:1; min-height:0;
    display:flex; align-items:center; justify-content:center;
    touch-action:none; user-select:none; -webkit-user-select:none;
    padding:10px;
}
.gm-stage canvas{
    display:block; border-radius:8px; border:1px solid var(--line2);
    background:#04051a; touch-action:none;
}
.gm-ov{
    position:absolute; inset:0; z-index:5;
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px;
    background:var(--ov); backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px);
    text-align:center; padding:20px; color:var(--ui-text);
}
.gm-ov.hide{ display:none; }
.gm-ov .ovic{ font-size:2.6rem; color:var(--red); line-height:0; }
.gm-ov .ovic svg{ width:1em; height:1em; }
.gm-ov h3{ font-size:1.05rem; font-weight:700; color:var(--ui-text); }
.gm-ov p{ font-size:.74rem; color:var(--sub); line-height:1.6; max-width:280px; }
.gm-ov .big{ font-size:2.2rem; font-weight:700; line-height:1; color:var(--ui-text); }
.gm-ov .nb{ color:var(--ok); font-weight:700; font-size:.76rem; }
.gm-ov .tk-btn{ margin-top:8px; height:34px; min-width:120px; font-size:.8rem; }

.gm-help{ padding:8px var(--pad) calc(env(safe-area-inset-bottom,0px) + 12px); text-align:center; font-size:.64rem; color:var(--muted); flex-shrink:0; }

/* leaderboard */
.gm-toplist{ flex:1; overflow-y:auto; padding-bottom:40px; }
.gm-me{ padding:12px var(--pad); font-size:.74rem; color:var(--sub); border-bottom:1px solid var(--line); }
.gm-me b{ color:var(--ui-text); }
.gm-empty{ padding:48px 20px; text-align:center; color:var(--muted); font-size:.78rem; }
.user-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:11px var(--pad); border-bottom:1px solid rgba(var(--fg),0.06);
    cursor:pointer; transition:background .15s; color:var(--ui-text);
}
.user-row:hover{ background:rgba(var(--fg),0.03); }
.user-row.me{ background:rgba(255,45,45,.07); }
.gm-rank{ width:22px; text-align:center; font-size:.76rem; font-weight:700; color:var(--muted); flex-shrink:0; }
.user-row:nth-child(-n+4) .gm-rank{ color:var(--red); }
.user-avatar{ width:clamp(36px, 10vw, 44px); height:clamp(36px, 10vw, 44px); border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid var(--red); }
.user-info{ flex:1; min-width:0; }
.uname{ font-size:.82rem; font-weight:600; color:var(--ui-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.unick{ font-size:.68rem; color:var(--muted); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gm-sc{ font-size:.86rem; font-weight:700; color:var(--ui-text); flex-shrink:0; }

/* skeleton */
#page-skeleton{
    position:fixed; top:var(--topbar-h); right:0; bottom:0; left:0; z-index:400; background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center; opacity:1; transition:opacity .35s ease; pointer-events:none;
}
#page-skeleton.hide{ opacity:0; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),0.06); border-radius:6px; }
.sk::after{ content:''; position:absolute; inset:0; background:linear-gradient(90deg, transparent, rgba(var(--fg),0.09), transparent); transform:translateX(-100%); animation:skShimmer 1.5s infinite; }
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; padding:0 16px 0 10px; border-bottom:1px solid var(--line); }
.sk-back{ width:22px; height:22px; }
.sk-banner{ margin:14px var(--pad) 0; aspect-ratio:16/9; border-radius:2px; }
.sk-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:12px; padding:14px var(--pad); }
.sk-card{ height:200px; border-radius:10px; }

/* DOM games (Sudoku) + choice overlay */
.gm-dom{ display:none; width:100%; height:100%; align-items:center; justify-content:center; overflow:hidden; color:var(--ui-text); }
.gm-ov.solid{ background:var(--solid); backdrop-filter:none; -webkit-backdrop-filter:none; }
.gm-choices{ display:flex; flex-direction:column; gap:8px; width:100%; max-width:220px; margin-top:8px; }
.gm-choices .tk-btn{ width:100%; height:36px; font-size:.8rem; }
#gmSnd{ font-size:1.15rem; margin-left:2px; }

.sd-wrap{ width:min(100%, calc(100dvh - 320px)); min-width:270px; max-width:400px; display:flex; flex-direction:column; gap:10px; }
.sd-grid{ display:grid; grid-template-columns:repeat(9, 1fr); aspect-ratio:1; border:2px solid rgba(255,45,45,.85); border-radius:6px; overflow:hidden; background:rgba(var(--fg),.04); }
.sd-c{ display:flex; align-items:center; justify-content:center; border:1px solid rgba(var(--fg),.08); font-size:clamp(.95rem, 4.8vw, 1.45rem); font-weight:600; color:var(--sd-blue); cursor:pointer; user-select:none; -webkit-user-select:none; }
.sd-c.br{ border-right:2px solid rgba(255,45,45,.7); }
.sd-c.bb{ border-bottom:2px solid rgba(255,45,45,.7); }
.sd-c.g{ color:var(--ui-text); background:rgba(var(--fg),.05); }
.sd-c.peer{ background:rgba(var(--fg),.09); }
.sd-c.same{ background:rgba(255,45,45,.22); }
.sd-c.sel{ background:rgba(255,45,45,.42); }
.sd-c.wrong{ color:#ff5a5a; background:rgba(255,0,0,.2); }
.sd-notes{ display:grid; grid-template-columns:repeat(3, 1fr); width:100%; height:100%; font-size:clamp(.38rem, 1.9vw, .58rem); font-weight:500; color:rgba(var(--fg),.5); line-height:1; text-align:center; align-content:center; }
.sd-pad{ display:grid; grid-template-columns:repeat(9, 1fr); gap:5px; }
.sd-n{ height:clamp(34px, 9vw, 44px); border:none; border-radius:6px; background:rgba(var(--fg),.1); color:var(--ui-text); font-weight:700; font-size:1rem; transition:background .15s, transform .1s; }
.sd-n:hover{ background:rgba(var(--fg),.2); }
.sd-n:active{ transform:scale(.94); }
.sd-n.done{ opacity:.22; pointer-events:none; }
.sd-tools{ display:flex; gap:8px; }
.sd-t{ flex:1; height:34px; border:none; border-radius:6px; background:rgba(var(--fg),.1); color:var(--ui-text); font-size:.72rem; font-weight:600; display:flex; align-items:center; justify-content:center; gap:6px; }
.sd-t svg{ width:15px; height:15px; }
.sd-t:hover{ background:rgba(var(--fg),.2); }
.sd-t.on{ background:var(--track-red); color:#000; }

/* Minesweeper */
.ms-wrap{ display:flex; flex-direction:column; gap:10px; }
.ms-grid{ display:grid; gap:2px; width:100%; }
.ms-c{ aspect-ratio:1; border-radius:4px; background:rgba(var(--fg),.14); color:var(--ui-text); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:clamp(.7rem, 3.4vw, 1.1rem); cursor:pointer; user-select:none; -webkit-user-select:none; touch-action:none; transition:background .1s; }
.ms-c:hover{ background:rgba(var(--fg),.22); }
.ms-c.o{ background:rgba(var(--fg),.035); cursor:default; }
.ms-c.f{ color:#ff2d2d; }
.ms-c.m{ background:rgba(255,45,45,.18); color:var(--red-text); }
.ms-c.x{ background:#ff2d2d; color:#000; }
.ms-c svg{ width:62%; height:62%; }

/* ══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything is 10% bigger on phones / small screens (width ≤ 800px).
   The normal desktop view above is untouched.
══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{ --topbar-h:59.4px; --pad:clamp(10px, 3.3vw, 17.6px); --rp-action-h:clamp(27.5px, 7.92vw, 33px); }
    .gm-x{ width:37.4px; height:37.4px; }
    .gm-x svg{ width:24.2px; height:24.2px; }
    .gm-title{ font-size:1.012rem; }
    #gmSnd, #gmPause{ font-size:1.265rem; }
    .tk-search svg{ width:1.1rem; height:1.1rem; left:calc(var(--pad) + 12px); }
    .tk-search input{ height:clamp(33px, 8.8vw, 39.6px); font-size:.836rem; padding:0 13px 0 37px; }
    .gm-note{ font-size:.77rem; }
    .gm-list{ gap:clamp(9px, 2.86vw, 15.4px); padding-bottom:calc(96px + env(safe-area-inset-bottom, 0px)); }
    .gm-by{ font-size:.638rem; }
    .gm-tag{ font-size:.594rem; }
    .gm-play{ width:48.4px; height:48.4px; margin:-24.2px 0 0 -24.2px; font-size:1.43rem; }
    .gm-name{ font-size:.924rem; }
    .gm-desc{ font-size:.726rem; }
    .gm-bestline{ font-size:.704rem; }
    .tk-btn{ min-width:92.4px; font-size:.792rem; padding:0 13.2px; }
    .empty-state svg{ width:2.31rem; height:2.31rem; }
    .empty-state span{ font-size:.88rem; }
    .sk-card{ height:220px; }

    .gm-mtab{ font-size:.814rem; padding:11px 8.8px; }
    .gm-hud{ font-size:.748rem; }
    .gm-hud b{ font-size:.99rem; }
    .gm-stage{ padding:4px; }
    .gm-help{ font-size:.704rem; }
    .gm-ov .ovic{ font-size:2.86rem; }
    .gm-ov h3{ font-size:1.155rem; }
    .gm-ov p{ font-size:.814rem; max-width:308px; }
    .gm-ov .big{ font-size:2.42rem; }
    .gm-ov .nb{ font-size:.836rem; }
    .gm-ov .tk-btn{ height:37.4px; min-width:132px; font-size:.88rem; }
    .gm-choices{ max-width:242px; }
    .gm-choices .tk-btn{ height:39.6px; font-size:.88rem; }

    .gm-me{ font-size:.814rem; }
    .gm-empty{ font-size:.858rem; }
    .user-row{ padding:12px var(--pad); gap:clamp(11px, 3.3vw, 15.4px); }
    .gm-rank{ width:24.2px; font-size:.836rem; }
    .user-avatar{ width:clamp(39.6px, 11vw, 48.4px); height:clamp(39.6px, 11vw, 48.4px); }
    .uname{ font-size:.902rem; }
    .unick{ font-size:.748rem; }
    .gm-sc{ font-size:.946rem; }

    .sd-wrap{ min-width:297px; max-width:440px; }
    .sd-c{ font-size:clamp(1.045rem, 5.28vw, 1.595rem); }
    .sd-n{ height:clamp(37.4px, 9.9vw, 48.4px); font-size:1.1rem; }
    .sd-t{ height:37.4px; font-size:.792rem; }
    .sd-t svg{ width:16.5px; height:16.5px; }
    .ms-c{ font-size:clamp(.77rem, 3.74vw, 1.21rem); }
}
@media (max-width:490px){
    :root{ --topbar-h:52.8px; }
    .gm-x{ width:33px; height:33px; }
    .gm-title{ font-size:.924rem; }
    .gm-name{ font-size:.836rem; }
    .gm-desc{ font-size:.66rem; }
    .gm-bestline{ font-size:.66rem; }
    .tk-btn{ min-width:79.2px; font-size:.704rem; padding:0 9.9px; }
}
@media (max-width:380px){
    :root{ --topbar-h:48.4px; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the Community page. Game artwork (card previews, the game canvas, ad media)
   keeps its own dark background in both themes, because the games are drawn for it. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --solid:#ffffff;
    --surface:rgba(6,7,26,.05);
    --border:rgba(6,7,26,.2);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --red-text:#d92b2b;
    --ov:rgba(244,245,251,.86);
    --ico-bg:#eceef5;
    --ph:#eceef5;
    --ok:#15a34a;
    --sd-blue:#1a56db;
    --scroll-thumb:rgba(42,95,216,.4);
}

/* ══════════════════════════════════════
   IMMERSIVE PLAY — the game fills the whole screen.
   Score / Best / buttons float over the top edge and fade away while you play; tap the top edge (or move the mouse) to bring them back.
   Always a dark "theatre", whatever the site theme is. (Full-screen API + wake lock are in the script after games-levels.js)
══════════════════════════════════════ */
.gm-modal{
    --fg:255,255,255; --ui-text:#ffffff; --text:#ffffff; --solid:#04051a;
    --muted:rgba(255,255,255,.55); --sub:rgba(255,255,255,.7);
    --line:rgba(255,255,255,.09); --line2:rgba(255,255,255,.14); --surface:rgba(255,255,255,.05);
    --gm-bar-h:calc(44px + env(safe-area-inset-top, 0px));
    background:#04051a; overscroll-behavior:none; -webkit-touch-callout:none; -webkit-user-select:none; user-select:none;
    -webkit-tap-highlight-color:transparent;
}
.gm-modal .gm-mlane{ max-width:none; position:relative; border:none !important; }
.gm-modal canvas{ -webkit-touch-callout:none; }

/* the stage takes everything; only the top strip (floating bar) is kept clear so no game art hides behind the score */
.gm-modal .gm-panel.show#panelPlay{ position:absolute; inset:0; }
.gm-modal .gm-stage{
    padding:var(--gm-bar-h) 0 env(safe-area-inset-bottom, 0px); background:#04051a;
    padding-left:env(safe-area-inset-left, 0px); padding-right:env(safe-area-inset-right, 0px);
}
.gm-modal .gm-stage canvas{ border:none; border-radius:0; }

/* floating bar = topbar + score row in one line */
.gm-modal .gm-topbar{
    position:absolute !important; top:0; left:0; right:0; z-index:30; height:var(--gm-bar-h);
    padding:env(safe-area-inset-top, 0px) calc(8px + env(safe-area-inset-right, 0px)) 0 calc(8px + env(safe-area-inset-left, 0px));
    background:linear-gradient(180deg, rgba(4,5,26,.92), rgba(4,5,26,.55) 70%, rgba(4,5,26,0)) !important; border:none;
    transition:opacity .35s ease, transform .35s ease;
}
.gm-modal .gm-hud{
    position:absolute; z-index:31; top:env(safe-area-inset-top, 0px); left:50px; right:calc(132px + env(safe-area-inset-right, 0px)); height:44px;
    padding:0; border:none; background:none; pointer-events:none;
    grid-template-columns:auto 1fr auto; font-size:.62rem; text-transform:uppercase; letter-spacing:.06em;
    transition:opacity .35s ease, transform .35s ease;
}
.gm-modal .gm-hud b{ display:block; margin:0; font-size:1.02rem; letter-spacing:0; line-height:1.1; }
.gm-modal .gm-hud > div{ display:flex; flex-direction:column; justify-content:center; line-height:1.15; }
.gm-modal .gm-hud .mid{ text-transform:none; letter-spacing:0; font-size:.72rem; }
.gm-modal .gm-title{ display:none; }
.gm-modal .gm-topbar .gm-x:first-child{ margin-right:auto; }
.gm-modal .gm-x{ width:38px; height:38px; background:rgba(4,5,26,.4); border-radius:50%; }
.gm-modal .gm-x svg{ width:20px; height:20px; }
#gmFs svg{ width:20px; height:20px; }
/* Play / Top players switch: a slim pill under the bar, shown with the bar */
.gm-modal .gm-mtabs{
    position:absolute; z-index:30; top:calc(var(--gm-bar-h) - 2px); left:50%; transform:translateX(-50%);
    border:none; gap:2px; padding:3px; border-radius:999px; background:rgba(4,5,26,.72);
    backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px);
    transition:opacity .35s ease, transform .35s ease;
}
.gm-modal .gm-mtab{ flex:none; padding:6px 14px; border-radius:999px; font-size:.7rem; }
.gm-modal .gm-mtab.active{ background:rgba(255,255,255,.14); }
.gm-modal .gm-mtab.active::after{ display:none; }
/* help line: floats at the bottom, fades after a few seconds */
.gm-modal .gm-help{
    position:absolute; left:0; right:0; bottom:0; z-index:20; pointer-events:none;
    padding:8px var(--pad) calc(env(safe-area-inset-bottom, 0px) + 10px);
    background:linear-gradient(0deg, rgba(4,5,26,.8), rgba(4,5,26,0)); transition:opacity .6s ease;
}
.gm-modal.gm-help-off .gm-help{ opacity:0; }
/* idle while playing: everything floats away */
.gm-modal.gm-idle .gm-topbar, .gm-modal.gm-idle .gm-hud{ opacity:0; transform:translateY(-8px); pointer-events:none; }
.gm-modal.gm-idle .gm-mtabs{ opacity:0; transform:translate(-50%,-8px); pointer-events:none; }
/* the Top players list keeps normal flow (below the bar) and never auto-hides */
.gm-modal #panelTop{ position:absolute; inset:0; padding-top:calc(var(--gm-bar-h) + 40px); overflow-y:auto; }
.gm-modal.gm-tab-top .gm-hud{ display:none; }
/* pause / game-over / level-picker overlays cover the whole screen */
.gm-modal .gm-ov{ z-index:25; }
/* Sudoku board no longer needs room for tabs / HUD / help */
.sd-wrap{ width:min(100%, calc(100dvh - 190px)); }
/* page behind stays put while a game is open */
html.gm-playing, body.gm-playing{ overflow:hidden !important; overscroll-behavior:none; touch-action:none; }
@media (hover:hover){ .gm-modal .gm-x:hover{ background:rgba(255,45,45,.18); } }
@media (min-width:801px){ .gm-modal .gm-hud{ left:60px; right:200px; } }
</style>
<?php if (function_exists('dz_offline_styles')) { dz_offline_styles(); /* offline.php */ } ?>
</head>
<body>
<?php if (function_exists('dz_offline_ui')) { dz_offline_ui(); /* offline.php: banner + full-screen "No internet" state, must come before the page scripts */ } ?>

<!-- icon sprite -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
<?php foreach ($ICONS as $iname => $ipath): ?>
    <symbol id="i-<?php echo $iname; ?>" viewBox="0 0 24 24"><?php echo $ipath; ?></symbol>
<?php endforeach; ?>
</svg>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk sk-banner"></div>
        <div class="sk-grid"><?php for($i=0;$i<6;$i++): ?><div class="sk sk-card"></div><?php endfor; ?></div>
    </div>
</div>

<div class="gm-shell">

<div class="gm-lane">
    <div class="gm-topbar">
        <span class="gm-title left">Games</span>
    </div>

    <div class="tk-search">
        <svg class="ic"><use href="#i-search"/></svg>
        <input type="text" id="gm-search" placeholder="Search games..." oninput="filterGames()" autocomplete="off">
    </div>
    <p class="gm-note">Play, beat your best, and climb the leaderboard against other Dezzmers. Most games let you pick a level (Easy, Normal or Hard) before you play. Games run on your device, so playing uses almost no data.</p>

    <!-- ══ AD BANNER — slides through the ads; tap opens the advertiser's link (hidden when there are no ads) ══ -->
    <div class="gm-ads" id="gmAds" data-ad-slot="0">
        <div class="gm-ads-skel"></div>
        <div class="gm-ads-box" id="gmAdsBox">
            <div class="gm-ads-track" id="gmAdsTrack"></div>
        </div>
    </div>

    <div class="gm-list" id="gmList">
        <?php $gm_i = 0; foreach($GAMES as $key => $g): $gm_i++; ?>
        <div class="gm-card" data-name="<?php echo htmlspecialchars(strtolower($g['name'])); ?>" onclick="openGame('<?php echo $key; ?>')">
            <div class="gm-thumb">
                <canvas data-game="<?php echo $key; ?>"></canvas>
                <?php if(!empty($g['by'])): ?><span class="gm-by">by <?php echo htmlspecialchars($g['by']); ?></span><?php endif; ?>
                <?php if(!empty($g['tag'])): ?><span class="gm-tag"><?php echo htmlspecialchars($g['tag']); ?></span><?php endif; ?>
                <span class="gm-play"><svg class="ic"><use href="#i-play"/></svg></span>
            </div>
            <div class="gm-body">
                <div class="gm-name"><?php echo htmlspecialchars($g['name']); ?></div>
                <div class="gm-desc"><?php echo htmlspecialchars($g['desc']); ?></div>
                <div class="gm-bestline">Best: <b id="best-<?php echo $key; ?>"><?php echo (int)$my_best[$key]; ?></b></div>
                <button type="button" class="tk-btn track" onclick="event.stopPropagation(); openGame('<?php echo $key; ?>')">Play</button>
            </div>
        </div>
        <?php if ($gm_i % 6 === 0 && $gm_i < count($GAMES)): /* ad box after every 6 games */ ?>
        <div class="gm-ads gm-ads-inline" data-ad-slot="<?php echo (int)($gm_i / 6); ?>">
            <div class="gm-ads-skel"></div>
            <div class="gm-ads-box"><div class="gm-ads-track"></div></div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
        <div class="empty-state" id="gmNoRes"><svg class="ic"><use href="#i-search"/></svg><span>No games found</span></div>
    </div>
</div>

<!-- ══ RIGHT-HAND COLUMN — laptop / normal desktop view only (≥1100px) ══ -->
<aside class="gm-rail" aria-label="Games highlights">

    <section class="pf-card" id="rsAdsSection">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><svg class="ic pf-bar-ic"><use href="#i-megaphone"/></svg><span class="pf-bar-t">Sponsored</span><svg class="ic pf-bar-chev"><use href="#i-chev"/></svg></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
            <div class="rs-ads-list" id="rsAdsList">
                <?php for($i=0;$i<3;$i++): ?>
                <div class="rs-ad-skel"><div class="rs-ad-skel-thumb"></div><div class="rs-ad-skel-lines"><div class="rs-ad-skel-line w1"></div><div class="rs-ad-skel-line w2"></div><div class="rs-ad-skel-line w3"></div></div></div>
                <?php endfor; ?>
            </div>
        </div></div></div>
    </section>

    <section class="pf-card">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><svg class="ic pf-bar-ic"><use href="#i-trophy"/></svg><span class="pf-bar-t">Your best scores</span><?php if($my_top): ?><span class="pf-bar-c"><?php echo count($my_top); ?></span><?php endif; ?><svg class="ic pf-bar-chev"><use href="#i-chev"/></svg></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
            <?php if($my_top): ?>
                <?php foreach($my_top as $k => $sc): $g = $GAMES[$k]; ?>
                <div class="pf-row" onclick="openGame('<?php echo $k; ?>')">
                    <span class="pf-ico"><svg class="ic"><use href="#i-gamepad"/></svg></span>
                    <div class="pf-row-info">
                        <span class="pf-row-t"><?php echo htmlspecialchars($g['name']); ?></span>
                        <div class="pf-row-s">Tap to play again</div>
                    </div>
                    <span class="pf-rank"><?php echo number_format($sc); ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="pf-empty">You haven't set a score yet. Play any game and your best scores will show up here.</p>
            <?php endif; ?>
        </div></div></div>
    </section>

    <section class="pf-card">
        <button type="button" class="pf-bar" onclick="pfToggle(this)" aria-expanded="true"><svg class="ic pf-bar-ic"><use href="#i-info"/></svg><span class="pf-bar-t">About Dezzme Games</span><svg class="ic pf-bar-chev"><use href="#i-chev"/></svg></button>
        <div class="pf-body"><div class="pf-inner"><div class="pf-pad">
            <div class="pf-about">
                <p><b>Dezzme Games</b> is a small arcade inside Dezzme. Pick a game, beat your best score and see how you rank against other Dezzmers on each game's <b>Top Players</b> tab.</p>
                <p>Every game runs on your device, so playing uses almost no data. Scores are checked by the server, so the leaderboard stays fair.</p>
                <p>Minesweeper has a new <b>daily board</b> for everyone, and new games are added often.</p>
            </div>
        </div></div></div>
    </section>

    <div class="rs-divider"></div>
    <div class="rs-links">
        <a href="home.php" class="rs-link-row"><svg class="ic"><use href="#i-home"/></svg><span>Home</span></a>
        <a href="messages.php" class="rs-link-row"><svg class="ic"><use href="#i-msg"/></svg><span>Messages</span></a>
    </div>
</aside>

</div>

<!-- ══ NAV (nav.php) ══ -->
<?php echo $nav_html; ?>

<!-- ══ GAME MODAL ══ -->
<div class="gm-modal" id="gmModal">
    <div class="gm-mlane">
        <div class="gm-topbar" style="position:relative;">
            <button type="button" class="gm-x" onclick="closeGame()" aria-label="Close game">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
            </button>
            <span class="gm-title" id="gmTitle">Game</span>
            <button type="button" class="gm-x" id="gmPause" onclick="pauseGame()" aria-label="Pause game"><svg class="ic"><use href="#i-pause"/></svg></button>
            <button type="button" class="gm-x" id="gmSnd" onclick="toggleSound()" aria-label="Sound"><svg class="ic"><use href="#i-vol"/></svg></button>
            <button type="button" class="gm-x" id="gmFs" onclick="gmFsToggle()" aria-label="Full screen" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path id="gmFsP" d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg></button>
        </div>
        <div class="gm-mtabs">
            <button type="button" class="gm-mtab active" id="tabPlay" onclick="switchTab('play')">Play</button>
            <button type="button" class="gm-mtab" id="tabTop" onclick="switchTab('top')">Top Players</button>
        </div>

        <div class="gm-panel show" id="panelPlay">
            <div class="gm-hud">
                <div>Score<b id="hudScore">0</b></div>
                <div class="mid" id="hudExtra"></div>
                <div class="r">Best<b id="hudBest">0</b></div>
            </div>
            <div class="gm-stage" id="gmStage">
                <canvas id="gmCv"></canvas>
                <div class="gm-dom" id="gmDom"></div>
                <div class="gm-ov hide" id="gmOv"></div>
            </div>
            <div class="gm-help" id="gmHelp"></div>
        </div>

        <div class="gm-panel" id="panelTop">
            <div class="gm-toplist" id="gmTopList"></div>
        </div>
    </div>
</div>

<script>var BEST = <?php echo json_encode($my_best, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<!-- all game code lives in games.js: the browser downloads it once and keeps it (?v= changes only when you edit the file) -->
<script src="games.js?v=<?php echo (int)@filemtime(__DIR__ . '/games.js'); ?>"></script>
<!-- levels for every game + 4 new games; must load right after games.js -->
<script src="games-levels.js?v=<?php echo (int)@filemtime(__DIR__ . '/games-levels.js'); ?>"></script>


<script>
/* ══ IMMERSIVE PLAY ══ wraps openGame / closeGame / switchTab from games.js — no game code is touched */
(function(){
    var modal = document.getElementById('gmModal'); if(!modal) return;
    var root = document.documentElement, ov = document.getElementById('gmOv'), fsBtn = document.getElementById('gmFs'), fsPath = document.getElementById('gmFsP');
    var idleT = null, helpT = null, wake = null, IDLE_MS = 2800;
    var fsEl = modal, canFs = !!(fsEl.requestFullscreen || fsEl.webkitRequestFullscreen);
    var FS_IN = 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5', FS_OUT = 'M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5';
    if(canFs) fsBtn.hidden = false;

    function isOpen(){ return modal.classList.contains('open'); }
    function busy(){ return (ov && !ov.classList.contains('hide')) || modal.classList.contains('gm-tab-top'); }   // overlay (pause / game over / picker) or the Top players tab
    function showUi(){
        modal.classList.remove('gm-idle'); clearTimeout(idleT);
        if(isOpen() && !busy()) idleT = setTimeout(function(){ if(isOpen() && !busy()) modal.classList.add('gm-idle'); }, IDLE_MS);
    }
    // bring the bar back: tap / hover in the top strip, or any mouse move on desktop
    modal.addEventListener('pointerdown', function(e){ if(e.clientY < 90) showUi(); }, true);
    modal.addEventListener('pointermove', function(e){ if(e.pointerType === 'mouse') showUi(); }, true);
    if(window.MutationObserver && ov) new MutationObserver(showUi).observe(ov, { attributes:true, attributeFilter:['class'] });

    // let the game re-measure its canvas after the layout changes
    function relayout(){ requestAnimationFrame(function(){ window.dispatchEvent(new Event('resize')); }); }

    // ── full screen ──
    function inFs(){ return !!(document.fullscreenElement || document.webkitFullscreenElement); }
    function fsEnter(){ try{ var p = (fsEl.requestFullscreen || fsEl.webkitRequestFullscreen).call(fsEl, { navigationUI:'hide' }); if(p && p.catch) p.catch(function(){}); }catch(e){} }
    function fsExit(){ try{ if(inFs()) (document.exitFullscreen || document.webkitExitFullscreen).call(document); }catch(e){} }
    window.gmFsToggle = function(){ if(inFs()) fsExit(); else fsEnter(); };
    function onFs(){ if(fsPath) fsPath.setAttribute('d', inFs() ? FS_OUT : FS_IN); fsBtn.setAttribute('aria-label', inFs() ? 'Exit full screen' : 'Full screen'); relayout(); }
    document.addEventListener('fullscreenchange', onFs); document.addEventListener('webkitfullscreenchange', onFs);

    // ── keep the screen awake while playing ──
    function wakeOn(){ try{ if(navigator.wakeLock && !wake) navigator.wakeLock.request('screen').then(function(l){ wake = l; l.addEventListener('release', function(){ wake = null; }); }).catch(function(){}); }catch(e){} }
    function wakeOff(){ try{ if(wake){ wake.release(); wake = null; } }catch(e){} }
    document.addEventListener('visibilitychange', function(){ if(!document.hidden && isOpen()) wakeOn(); });

    // no long-press menu / selection / pinch-zoom on the game
    modal.addEventListener('contextmenu', function(e){ e.preventDefault(); });
    modal.addEventListener('gesturestart', function(e){ e.preventDefault(); });
    modal.addEventListener('dblclick', function(e){ e.preventDefault(); });

    function enter(){
        root.classList.add('gm-playing'); document.body.classList.add('gm-playing');
        modal.classList.remove('gm-tab-top', 'gm-idle', 'gm-help-off');
        if(canFs) fsEnter();                                   // called from the tap that opened the game, so the browser allows it
        wakeOn(); relayout(); showUi();
        clearTimeout(helpT); helpT = setTimeout(function(){ modal.classList.add('gm-help-off'); }, 5000);
    }
    function leave(){
        clearTimeout(idleT); clearTimeout(helpT); fsExit(); wakeOff();
        root.classList.remove('gm-playing'); document.body.classList.remove('gm-playing');
        modal.classList.remove('gm-idle', 'gm-tab-top');
    }
    function wrap(name, before, after){
        var f = window[name]; if(typeof f !== 'function') return;
        window[name] = function(){
            if(before) before.apply(this, arguments);
            var r = f.apply(this, arguments);
            if(after) after.apply(this, arguments);
            return r;
        };
    }
    // open / close is detected from the modal's own "open" class, so it works however games.js opens a game
    var wasOpen = false;
    function sync(){ var o = isOpen(); if(o && !wasOpen) enter(); else if(!o && wasOpen) leave(); wasOpen = o; }
    if(window.MutationObserver) new MutationObserver(sync).observe(modal, { attributes:true, attributeFilter:['class'] });
    sync();
    wrap('pauseGame', null, showUi);
    wrap('switchTab', function(t){ modal.classList.toggle('gm-tab-top', t === 'top'); }, function(){ showUi(); relayout(); });
})();
</script>
<!-- ══ ADS: sliding banner (top of the list) + Sponsored list (right column) ══ -->
<script>
/* keep the theme in sync if it is switched in another tab */
window.addEventListener('storage', function(e){
    if(e.key !== 'dz_theme') return;
    if(e.newValue === 'light') document.documentElement.setAttribute('data-theme','light');
    else document.documentElement.removeAttribute('data-theme');
});

/* collapse / expand a right-column card */
function pfToggle(bar){
    var c = bar && bar.closest ? bar.closest('.pf-card') : null;
    if(!c) return;
    var collapsed = c.classList.toggle('collapsed');
    bar.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
}

(function(){
    var railSec = document.getElementById('rsAdsSection');
    var railList = document.getElementById('rsAdsList');

    function esc(s){
        if(s===null||s===undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function safeUrl(u){ return /^https?:\/\//i.test(u||'') ? u : '#'; }
    function isVideo(a){ return String(a.type||'').toLowerCase()==='video'; }
    function isMedia(a){ return ['image','video','gif','sticker','stickers'].indexOf(String(a.type||'').toLowerCase())!==-1 && a.file; }

    function slideHtml(a){
        var media = '';
        if(isVideo(a)) media = '<video class="gm-ad-media" src="'+esc(a.file)+'" muted loop playsinline preload="metadata" disablepictureinpicture></video>';
        else if(isMedia(a)) media = '<img class="gm-ad-media" src="'+esc(a.file)+'" alt="" draggable="false" onerror="this.style.visibility=\'hidden\'">';
        var cap = a.caption ? '<div class="gm-ad-cap">'+esc(a.caption)+'</div>' : '<div class="gm-ad-cap"></div>';
        return '<a class="gm-ad" href="'+esc(safeUrl(a.ctaUrl))+'" target="_blank" rel="noopener sponsored" draggable="false">'+
            media+
            '<span class="gm-ad-tag">Ad</span>'+
            '<span class="gm-ad-cta" style="--cta:'+esc(a.ctaColor||'#FF4C4C')+'">'+esc(a.ctaText||'Learn more')+'</span>'+
            '<div class="gm-ad-body">'+cap+'</div>'+
        '</a>';
    }

    /* ── one sliding banner. The page has one at the top and one after every 6 games; each runs on its own. ── */
    function Slider(wrap, list, delay){
        var boxEl = wrap.querySelector('.gm-ads-box');
        var track = wrap.querySelector('.gm-ads-track');
        var idx = 0, count = list.length, timer = null, paused = false, visible = true;

        function syncVideos(){
            var slides = track.children;
            for(var i=0;i<slides.length;i++){
                var v = slides[i].querySelector('video'); if(!v) continue;
                if(i===idx && visible && !document.hidden){ var p=v.play(); if(p&&p.catch) p.catch(function(){}); } else v.pause();
            }
        }
        function go(n, animate){
            idx = ((n % count) + count) % count;
            track.classList.remove('drag');
            if(animate === false) track.style.transition = 'none';
            track.style.transform = 'translateX(-'+(idx*100)+'%)';
            if(animate === false){ void track.offsetWidth; track.style.transition = ''; }
            syncVideos();
        }
        track.innerHTML = list.map(slideHtml).join('');
        wrap.classList.add('ready');
        go(0, false);
        if(count > 1) setTimeout(function(){
            timer = setInterval(function(){ if(!paused && visible && !document.hidden) go(idx+1); }, 4500);
        }, delay || 0);

        /* only the banner that is on screen plays / slides (saves data and battery) */
        if('IntersectionObserver' in window){
            new IntersectionObserver(function(es){
                visible = es[0].isIntersecting; syncVideos();
            }, { threshold:.25 }).observe(wrap);
        }

        /* swipe / drag (touch + mouse). A drag never counts as a click on the ad. */
        var sx=0, dx=0, down=false, moved=false, w=1;
        boxEl.addEventListener('pointerdown', function(e){
            down = true; moved = false; sx = e.clientX; dx = 0; w = boxEl.clientWidth || 1; paused = true;
        });
        boxEl.addEventListener('pointermove', function(e){
            if(!down || count < 2) return;
            dx = e.clientX - sx;
            if(Math.abs(dx) > 8){
                moved = true;
                track.classList.add('drag');
                var atEdge = (idx===0 && dx>0) || (idx===count-1 && dx<0);
                var off = atEdge ? dx*0.35 : dx;
                track.style.transform = 'translateX(calc(-'+(idx*100)+'% + '+off+'px))';
            }
        });
        function endDrag(){
            if(!down) return; down = false; paused = false;
            track.classList.remove('drag');
            if(moved && count > 1){
                var need = Math.min(90, w*0.2);
                if(dx < -need && idx < count-1) go(idx+1);
                else if(dx > need && idx > 0) go(idx-1);
                else go(idx);
            }
            setTimeout(function(){ moved = false; }, 0);
        }
        boxEl.addEventListener('pointerup', endDrag);
        boxEl.addEventListener('pointercancel', endDrag);
        boxEl.addEventListener('pointerleave', function(e){ if(e.pointerType==='mouse'){ endDrag(); } });
        boxEl.addEventListener('click', function(e){ if(moved){ e.preventDefault(); e.stopPropagation(); } }, true);
        boxEl.addEventListener('mouseenter', function(){ paused = true; });
        boxEl.addEventListener('mouseleave', function(){ if(!down) paused = false; });
        document.addEventListener('visibilitychange', syncVideos);
    }

    /* every ad box on the page: the top one (slot 0) and one after every 6 games (slots 1, 2, 3 …) */
    function buildBanners(list){
        var wraps = document.querySelectorAll('.gm-ads[data-ad-slot]');
        Array.prototype.forEach.call(wraps, function(wrap){
            var slot = parseInt(wrap.getAttribute('data-ad-slot'), 10) || 0;
            /* each box starts at a different ad, so the boxes down the list don't all show the same one */
            var off = list.length ? (slot * 2) % list.length : 0;
            var rotated = list.slice(off).concat(list.slice(0, off)).slice(0, 5);
            Slider(wrap, rotated, slot * 700);
        });
    }

    /* while searching, the repeated ad boxes between the games are hidden, so the results sit together */
    var _filter = window.filterGames;
    if(typeof _filter === 'function'){
        window.filterGames = function(){
            var r = _filter.apply(this, arguments);
            var inp = document.getElementById('gm-search');
            var searching = !!(inp && inp.value.trim() !== '');
            Array.prototype.forEach.call(document.querySelectorAll('.gm-ads-inline'), function(el){
                el.style.display = searching ? 'none' : '';
            });
            return r;
        };
    }

    /* ── Sponsored list (right column) ── */
    function railCard(a){
        var media = isVideo(a)
            ? '<video class="rs-ad-thumb" src="'+esc(a.file)+'" muted autoplay loop playsinline disablepictureinpicture onerror="this.style.visibility=\'hidden\'"></video>'
            : '<img class="rs-ad-thumb" src="'+esc(a.file)+'" alt="" onerror="this.style.visibility=\'hidden\'">';
        return '<a class="rs-ad-card" href="'+esc(safeUrl(a.ctaUrl))+'" target="_blank" rel="noopener sponsored">'+
            media+
            '<div class="rs-ad-info"><div class="rs-ad-caption">'+esc(a.caption || a.ctaText || 'Sponsored')+'</div><div class="rs-ad-tag">Ad</div></div>'+
        '</a>';
    }
    function buildRail(list){
        if(!railList) return;
        /* prefer ads the banner isn't already showing; fall back to the same ones */
        var rest = list.slice(5);
        var pool = rest.length ? rest : list;
        pool = pool.slice(0,3);
        if(!pool.length){ if(railSec) railSec.style.display='none'; return; }
        railList.innerHTML = pool.map(railCard).join('');
    }

    function none(){
        Array.prototype.forEach.call(document.querySelectorAll('.gm-ads'), function(el){ el.classList.add('gone'); });
        if(railSec) railSec.style.display = 'none';
    }

    fetch('<?php echo basename($_SERVER['PHP_SELF']); ?>?type=ads', { credentials:'same-origin', cache:'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(list){
            list = Array.isArray(list) ? list.filter(function(a){ return a && a.ctaUrl; }) : [];
            if(!list.length){ none(); return; }
            buildBanners(list);
            buildRail(list);
        })
        .catch(none);
})();
</script>

</body>
</html>