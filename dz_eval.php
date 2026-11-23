<?php
/* ================================================================
   dz_eval.php — "How is the feed doing?"  (admin-only report)

   Reads dz_feed_serves (what was served, at which position, by which algorithm variant),
   user_interactions (like / save / repost / share / not interested after being served)
   and dz_feed_impressions (seen / skipped) and shows, per algorithm variant and per position:
     seen %, like %, save %, repost %, share %, "not interested" %, skip %   (all per 100 posts served)

   Open:  dz_eval.php            (last 7 days)
          dz_eval.php?days=30    (1 - 90 days)
          dz_eval.php?variant=B  (positions for one variant only)

   Who may open it:
     • anyone on localhost (127.0.0.1 / ::1), always
     • accounts with users.is_admin = 1, ONLY when $ALLOW_ADMIN_ACCOUNTS below is true (default false = localhost only)

   How to read it: an interaction counts when it happened within 24 hours AFTER the post was served to that person.
   "Skip" = the person has a skip recorded for that post (dz_feed_impressions.skip_count, lifetime, not only for this serve).
   Compare variants / positions with the same number of serves; small numbers (under ~200 serves) are noise.
================================================================ */
session_start();

/* TEMPORARY: logs the request if something has put a non-string value in the CSRF session key.
   Remove this block once you have found the culprit (or once it has never fired for a few days). */
if(isset($_SESSION['dz_csrf']) && !is_string($_SESSION['dz_csrf'])){
    error_log('[dz csrf] array in session on ' . ($_SERVER['REQUEST_URI'] ?? '?') . ' via ' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
}

require_once 'config.php';

$ALLOW_ADMIN_ACCOUNTS = false;   /* true = accounts with users.is_admin = 1 may also open this page from the live site */

$uid   = (int)($_SESSION['user_id'] ?? 0);
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

$is_admin = false;
if($ALLOW_ADMIN_ACCOUNTS && $uid > 0){
    $st = $conn->prepare("SELECT is_admin FROM users WHERE id = ? AND deactivated_at IS NULL LIMIT 1");
    $st->bind_param("i", $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $is_admin = !empty($row['is_admin']);
}
if(!$local && !$is_admin){
    http_response_code(403);
    exit('Not allowed.');
}

function ev_cols($conn, $t){
    $o = [];
    $r = $conn->query("SHOW COLUMNS FROM `$t`");
    if($r) while($x = $r->fetch_assoc()) $o[strtolower($x['Field'])] = $x['Field'];
    return $o;
}
function ev_pick($cols, $cands){
    foreach($cands as $c) if(isset($cols[$c])) return $cols[$c];
    return '';
}
function ev_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function ev_pct($n, $d){ return $d > 0 ? number_format($n * 100 / $d, 1) . '%' : '–'; }

$days    = max(1, min(90, (int)($_GET['days'] ?? 7)));
$variant = isset($_GET['variant']) && is_string($_GET['variant']) ? trim($_GET['variant']) : '';   /* an array in the URL can no longer cause a warning */

$sc = ev_cols($conn, 'dz_feed_serves');
$cu = ev_pick($sc, ['user_id', 'uid']);
$cm = ev_pick($sc, ['meme_id', 'post_id', 'mid']);
$cp = ev_pick($sc, ['position', 'pos', 'rank_pos', 'rank', 'slot']);
$cv = ev_pick($sc, ['variant', 'algo_variant', 'algo', 'algorithm']);
$ct = ev_pick($sc, ['served_at', 'created_at', 'ts', 'time', 'logged_at']);

$problem = '';
if(!$sc) $problem = 'The table dz_feed_serves does not exist yet (it is filled when people load the feed).';
elseif(!$cu || !$cm || !$ct) $problem = 'Could not find the user / post / time columns in dz_feed_serves. Columns found: ' . implode(', ', array_values($sc));

$rowsV = []; $rowsP = []; $total = 0;
if(!$problem){
    $vExpr = $cv ? "s.`$cv`" : "'-'";
    $pExpr = $cp ? "s.`$cp`" : "0";
    $interact = function($type) use ($cu, $cm, $ct){
        return "SUM(EXISTS(SELECT 1 FROM user_interactions ui WHERE ui.user_id = s.`$cu` AND ui.meme_id = s.`$cm` AND ui.interaction_type = '$type'
                 AND ui.created_at >= s.`$ct` AND ui.created_at < s.`$ct` + INTERVAL 1 DAY))";
    };
    $base = "
        SELECT %GROUP% AS grp, MIN(%MINPOS%) AS ord,
               COUNT(*) AS serves,
               SUM(COALESCE(fi.seen_count, 0) > 0) AS seen,
               SUM(COALESCE(fi.skip_count, 0) > 0) AS skipped,
               " . $interact('like') . " AS likes,
               " . $interact('favorite') . " AS saves,
               " . $interact('repost') . " AS reposts,
               " . $interact('share') . " AS shares,
               " . $interact('not_interested') . " AS notint
        FROM dz_feed_serves s
        LEFT JOIN dz_feed_impressions fi ON fi.user_id = s.`$cu` AND fi.meme_id = s.`$cm`
        WHERE s.`$ct` >= NOW() - INTERVAL $days DAY %EXTRA%
        GROUP BY grp ORDER BY ord ASC";

    /* per variant */
    $q = str_replace(['%GROUP%', '%MINPOS%', '%EXTRA%'], [$vExpr, '1', ''], $base);
    $r = $conn->query($q);
    if($r) while($x = $r->fetch_assoc()){ $rowsV[] = $x; $total += (int)$x['serves']; }
    elseif($conn->error) $problem = 'Query error: ' . $conn->error;

    /* per position bucket */
    if($cp && !$problem){
        $bucket = "CASE WHEN $pExpr <= 1 THEN '1' WHEN $pExpr = 2 THEN '2' WHEN $pExpr = 3 THEN '3' WHEN $pExpr <= 5 THEN '4-5'
                        WHEN $pExpr <= 10 THEN '6-10' WHEN $pExpr <= 20 THEN '11-20' WHEN $pExpr <= 50 THEN '21-50'
                        WHEN $pExpr <= 100 THEN '51-100' ELSE '101+' END";
        $extra = ($cv && $variant !== '') ? " AND s.`$cv` = '" . $conn->real_escape_string($variant) . "' " : '';
        $q = str_replace(['%GROUP%', '%MINPOS%', '%EXTRA%'], [$bucket, $pExpr, $extra], $base);
        $r = $conn->query($q);
        if($r) while($x = $r->fetch_assoc()) $rowsP[] = $x;
        elseif($conn->error) $problem = 'Query error: ' . $conn->error;
    }
}

function ev_table($rows, $firstHead){
    if(!$rows) return '<p class="muted">No data in this period yet.</p>';
    $h = '<div class="tw"><table><thead><tr><th>' . ev_h($firstHead) . '</th><th>Served</th><th>Seen</th><th>Like</th><th>Save</th><th>Repost</th><th>Share</th><th>Not interested</th><th>Skip</th></tr></thead><tbody>';
    foreach($rows as $x){
        $n = (int)$x['serves'];
        $h .= '<tr><td><b>' . ev_h($x['grp']) . '</b></td><td>' . number_format($n) . '</td>'
            . '<td>' . ev_pct((int)$x['seen'], $n) . '</td>'
            . '<td>' . ev_pct((int)$x['likes'], $n) . '</td>'
            . '<td>' . ev_pct((int)$x['saves'], $n) . '</td>'
            . '<td>' . ev_pct((int)$x['reposts'], $n) . '</td>'
            . '<td>' . ev_pct((int)$x['shares'], $n) . '</td>'
            . '<td>' . ev_pct((int)$x['notint'], $n) . '</td>'
            . '<td>' . ev_pct((int)$x['skipped'], $n) . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Feed evaluation | Dezzme</title>
<style>
*{ box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', system-ui, sans-serif; }
body{ background:linear-gradient(-45deg,#010218,#070924,#070924); background-attachment:fixed; color:#fff; min-height:100vh; padding:24px 16px 60px; }
.wrap{ max-width:980px; margin:0 auto; }
h1{ font-size:1.5rem; font-weight:800; letter-spacing:-.4px; }
h2{ font-size:1.05rem; font-weight:700; margin:30px 0 4px; }
p.muted, .muted{ color:rgba(255,255,255,.5); font-size:.78rem; line-height:1.6; margin-top:4px; }
.bar{ display:flex; flex-wrap:wrap; gap:8px; margin:16px 0 4px; }
.bar a{ padding:7px 14px; border-radius:6px; background:rgba(255,255,255,.07); color:#fff; text-decoration:none; font-size:.78rem; font-weight:600; border:1px solid rgba(255,255,255,.09); }
.bar a.on{ background:#FF2D2D; border-color:#FF2D2D; }
.tw{ overflow-x:auto; margin-top:12px; border:1px solid rgba(255,255,255,.09); border-radius:8px; }
table{ width:100%; border-collapse:collapse; font-size:.82rem; min-width:640px; }
th, td{ padding:10px 12px; text-align:right; white-space:nowrap; }
th:first-child, td:first-child{ text-align:left; }
thead th{ font-size:.7rem; font-weight:600; color:rgba(255,255,255,.55); background:rgba(255,255,255,.04); }
tbody tr + tr td{ border-top:1px solid rgba(255,255,255,.07); }
.err{ margin-top:18px; padding:14px; border:1px solid rgba(255,45,45,.4); border-radius:8px; color:#ff8a8a; font-size:.85rem; line-height:1.6; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Feed evaluation</h1>
    <p class="muted"><?php echo $uid > 0 ? 'Logged in as user id ' . (int)$uid . '.' : 'You are not logged in.'; ?></p>
    <p class="muted">Per 100 posts served. An action counts when it happened within 24 hours after the post was served to that person. Under about 200 served posts the numbers are mostly noise.</p>

    <div class="bar">
        <?php foreach([1, 3, 7, 14, 30, 90] as $d): ?>
            <a class="<?php echo $d === $days ? 'on' : ''; ?>" href="?days=<?php echo $d; ?><?php echo $variant !== '' ? '&variant=' . urlencode($variant) : ''; ?>"><?php echo $d; ?> day<?php echo $d > 1 ? 's' : ''; ?></a>
        <?php endforeach; ?>
    </div>

    <?php if($problem): ?>
        <div class="err"><?php echo ev_h($problem); ?></div>
    <?php else: ?>
        <h2>By algorithm variant</h2>
        <p class="muted"><?php echo number_format($total); ?> posts served in the last <?php echo $days; ?> day<?php echo $days > 1 ? 's' : ''; ?>. Variant B is the current algorithm; A only appears when DZ_AB_TEST is on in dz_rank.php.</p>
        <?php echo ev_table($rowsV, 'Variant'); ?>

        <?php if($cp): ?>
            <h2>By position in the feed<?php echo $variant !== '' ? ' (variant ' . ev_h($variant) . ')' : ''; ?></h2>
            <p class="muted">Healthy feeds show the best rates near the top and a gentle fall-off. If position 1 does no better than position 50, the ranking is not adding value.</p>
            <?php if($cv && count($rowsV) > 1): ?>
                <div class="bar">
                    <a class="<?php echo $variant === '' ? 'on' : ''; ?>" href="?days=<?php echo $days; ?>">All variants</a>
                    <?php foreach($rowsV as $x): ?>
                        <a class="<?php echo $variant === (string)$x['grp'] ? 'on' : ''; ?>" href="?days=<?php echo $days; ?>&variant=<?php echo urlencode((string)$x['grp']); ?>">Variant <?php echo ev_h($x['grp']); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php echo ev_table($rowsP, 'Position'); ?>
        <?php else: ?>
            <p class="muted" style="margin-top:20px">dz_feed_serves has no position column, so the per-position table is skipped.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>