<?php
/* ═══════════════════════════════════════════════════════════════
   dz_views.php — Dezzme view counts
   A "view" = a post that was really on screen (the feed reports posts ≥60% visible for 1 second — the
   `impressions` action). No click needed. Rules:
     • one view per person per post per 24 hours (user id when logged in, hashed IP + user agent for guests)
     • your own posts, ads, archived posts and bots never count
     • every accepted view is written to meme_view_log (rebuildable) AND bumps memes.views (what cards read)
   Needs dezzme_views_v1.sql. If the table is missing everything here quietly does nothing.
   ═══════════════════════════════════════════════════════════════ */
if (defined('DZ_VIEWS_LOADED')) return;
define('DZ_VIEWS_LOADED', 1);

function dz_view_is_bot(): bool {
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return true;
    return (bool)preg_match('/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|monitor|curl|wget|python-requests|httpclient|headless|lighthouse|pingdom|uptime/', $ua);
}

/* a stable, non-reversible id for a logged-out visitor */
function dz_view_guest_hash(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $salt = defined('DZ_VIEW_SALT') ? DZ_VIEW_SALT : ('dz-views-' . php_uname('n'));
    return substr(hash_hmac('sha256', $ip . '|' . $ua, $salt), 0, 16);
}

/* where the view came from: explicit ?src=, else guessed from the page that sent it */
function dz_view_source(): string {
    $ok = ['feed','search','profile','zeems','history','open'];
    $s  = strtolower(trim((string)($_POST['src'] ?? '')));
    if (in_array($s, $ok, true)) return $s;
    $ref = strtolower((string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH));
    if (strpos($ref, 'search')  !== false) return 'search';
    if (strpos($ref, 'profile') !== false) return 'profile';
    if (strpos($ref, 'history') !== false) return 'history';
    return 'feed';
}

/* record views for a batch of posts. Returns how many were accepted. */
function dz_record_views($conn, int $uid, array $ids, string $source = 'feed'): int {
    try {
        if (dz_view_is_bot()) return 0;
        $want = [];
        foreach ($ids as $x) { $x = (int)$x; if ($x > 0) $want[$x] = $x; }
        $want = array_slice(array_values($want), 0, 50);
        if (!$want) return 0;
        $in = implode(',', $want);

        /* only real, public posts that are not the viewer's own and not ads */
        $ok = [];
        $r = $conn->query("SELECT id FROM memes WHERE id IN ($in) AND is_ad = 0 AND is_archived = 0" . ($uid ? " AND uploaded_by <> " . (int)$uid : ''));
        if ($r) while ($x = $r->fetch_assoc()) $ok[(int)$x['id']] = true;
        if (!$ok) return 0;
        $in = implode(',', array_keys($ok));

        /* who is viewing */
        $gh = $uid ? null : dz_view_guest_hash();
        $who = $uid ? "viewer_id = " . (int)$uid : "guest_hash = '" . $conn->real_escape_string($gh) . "'";

        /* already counted for this person in the last 24 h? */
        $r = $conn->query("SELECT DISTINCT meme_id FROM meme_view_log WHERE $who AND meme_id IN ($in) AND created_at >= (NOW() - INTERVAL 1 DAY)");
        if ($r === false) return 0;                                   /* table missing → do nothing */
        while ($x = $r->fetch_assoc()) unset($ok[(int)$x['meme_id']]);
        if (!$ok) return 0;

        $ins = $conn->prepare("INSERT INTO meme_view_log (meme_id, viewer_id, guest_hash, source) VALUES (?, ?, ?, ?)");
        $done = [];
        $vid = $uid ?: null;
        foreach (array_keys($ok) as $mid) {
            $ins->bind_param("iiss", $mid, $vid, $gh, $source);
            if ($ins->execute()) $done[] = (int)$mid;
        }
        $ins->close();
        if ($done) $conn->query("UPDATE memes SET views = views + 1 WHERE id IN (" . implode(',', $done) . ")");   /* one statement for the whole batch */
        return count($done);
    } catch (Throwable $e) { return 0; }
}

/* how long each post stayed on screen: "id => milliseconds". Stored on the person's latest view row (last 24 h). */
function dz_view_add_watch($conn, int $uid, array $dw): void {
    try {
        if (!$dw || dz_view_is_bot()) return;
        $who = $uid ? "viewer_id = " . (int)$uid : "guest_hash = '" . $conn->real_escape_string(dz_view_guest_hash()) . "'";
        foreach ($dw as $mid => $ms) {
            $mid = (int)$mid; $ms = max(0, min(600000, (int)$ms));        /* a minute-ten ceiling keeps one stuck tab from skewing averages */
            if ($mid <= 0 || $ms <= 0) continue;
            $conn->query("UPDATE meme_view_log SET watch_ms = GREATEST(watch_ms, $ms) WHERE $who AND meme_id = $mid AND created_at >= (NOW() - INTERVAL 1 DAY) ORDER BY id DESC LIMIT 1");
        }
    } catch (Throwable $e) {}
}

/* current counters for some posts: id => views (for live updates) */
function dz_view_counts($conn, array $ids): array {
    $out = [];
    try {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return $out;
        $r = $conn->query("SELECT id, views FROM memes WHERE id IN (" . implode(',', array_slice($ids, 0, 50)) . ")");
        if ($r) while ($x = $r->fetch_assoc()) $out[(int)$x['id']] = (int)$x['views'];
    } catch (Throwable $e) {}
    return $out;
}

/* the owner's breakdown for one post ("View insights").
   Old keys (total, unique, members, guests, avg_watch_ms, days, sources) are kept so older callers keep working. Every block is
   read on its own, so a missing table only blanks that block. */
function dz_view_stats($conn, int $meme_id): array {
    $mid = (int)$meme_id;
    $o = ['total'=>0,'unique'=>0,'members'=>0,'guests'=>0,'avg_watch_ms'=>0,'days'=>[],'sources'=>[],
          'type'=>'','created_at'=>'','impressions'=>0,'interactions'=>0,'engagement_rate'=>0,
          'eng'=>['likes'=>0,'reposts'=>0,'saves'=>0,'shares'=>0,'comments'=>0,'share_types'=>[],'profile_visits'=>0,'new_followers'=>0],
          'aud'=>['followers'=>0,'non_followers'=>0,'guests'=>0,'new_viewers'=>0,'returning'=>0],
          'watch'=>['total_ms'=>0,'measured'=>0,'buckets'=>[0,0,0,0]],
          'time'=>['hour1'=>0,'day1'=>0,'day7'=>0,'life'=>0]];
    $q = function(string $sql) use ($conn) { try { $r = $conn->query($sql); return ($r instanceof mysqli_result) ? $r : null; } catch (Throwable $e) { return null; } };
    $one = function(string $sql) use ($q) { $r = $q($sql); return $r ? ($r->fetch_assoc() ?: []) : []; };

    $m = $one("SELECT views, uploaded_by, type, created_at FROM memes WHERE id = $mid");
    $o['total'] = (int)($m['views'] ?? 0); $o['type'] = (string)($m['type'] ?? ''); $o['created_at'] = (string)($m['created_at'] ?? '');
    $owner = (int)($m['uploaded_by'] ?? 0);

    /* reach: unique accounts / visitors that saw it */
    $x = $one("SELECT COUNT(DISTINCT viewer_id) m, COUNT(DISTINCT guest_hash) g, COALESCE(AVG(NULLIF(watch_ms,0)),0) a, COALESCE(SUM(watch_ms),0) t, COUNT(NULLIF(watch_ms,0)) n,
                      SUM(watch_ms > 0 AND watch_ms < 1000) b0, SUM(watch_ms >= 1000 AND watch_ms < 3000) b1, SUM(watch_ms >= 3000 AND watch_ms < 10000) b2, SUM(watch_ms >= 10000) b3,
                      COUNT(*) logged
               FROM meme_view_log WHERE meme_id = $mid");
    if ($x) {
        $o['members'] = (int)$x['m']; $o['guests'] = (int)$x['g']; $o['unique'] = $o['members'] + $o['guests'];
        $o['avg_watch_ms'] = (int)round($x['a']);
        $o['watch'] = ['total_ms'=>(int)$x['t'], 'measured'=>(int)$x['n'], 'buckets'=>[(int)$x['b0'], (int)$x['b1'], (int)$x['b2'], (int)$x['b3']]];
        if ($o['total'] < (int)$x['logged']) $o['total'] = (int)$x['logged'];
    }

    /* last 7 days */
    $r = $q("SELECT DATE(created_at) d, COUNT(*) c FROM meme_view_log WHERE meme_id = $mid AND created_at >= (CURDATE() - INTERVAL 6 DAY) GROUP BY DATE(created_at)");
    $map = []; if ($r) while ($y = $r->fetch_assoc()) $map[$y['d']] = (int)$y['c'];
    for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $o['days'][] = ['d'=>$d, 'c'=>$map[$d] ?? 0]; }

    /* where views came from */
    $r = $q("SELECT source, COUNT(*) c FROM meme_view_log WHERE meme_id = $mid GROUP BY source ORDER BY c DESC");
    if ($r) while ($y = $r->fetch_assoc()) $o['sources'][] = ['s'=>$y['source'], 'c'=>(int)$y['c']];

    /* engagement */
    foreach (['likes'=>'likes', 'reposts'=>'reposts', 'saves'=>'favorites', 'comments'=>'comments'] as $k => $t) {
        $y = $one("SELECT COUNT(*) c FROM `$t` WHERE meme_id = $mid" . ($owner ? " AND user_id <> $owner" : ''));
        $o['eng'][$k] = (int)($y['c'] ?? 0);
    }
    $r = $q("SELECT share_type, COUNT(*) c FROM shares WHERE meme_id = $mid" . ($owner ? " AND user_id <> $owner" : '') . " GROUP BY share_type");
    if ($r) while ($y = $r->fetch_assoc()) { $o['eng']['share_types'][(string)$y['share_type']] = (int)$y['c']; $o['eng']['shares'] += (int)$y['c']; }
    $o['interactions'] = $o['eng']['likes'] + $o['eng']['reposts'] + $o['eng']['saves'] + $o['eng']['shares'] + $o['eng']['comments'];
    $o['engagement_rate'] = $o['unique'] > 0 ? round($o['interactions'] / $o['unique'] * 100, 1) : 0;

    /* profile visits + new followers: counted when it happens within 24 h after that person viewed this post */
    if ($owner) {
        $y = $one("SELECT COUNT(DISTINCT v.viewer_id) c FROM meme_view_log v
                   JOIN profile_views pv ON pv.viewer_id = v.viewer_id AND pv.profile_id = $owner AND pv.viewed_at >= v.created_at AND pv.viewed_at <= v.created_at + INTERVAL 1 DAY
                   WHERE v.meme_id = $mid AND v.viewer_id IS NOT NULL");
        $o['eng']['profile_visits'] = (int)($y['c'] ?? 0);
        $y = $one("SELECT COUNT(DISTINCT v.viewer_id) c FROM meme_view_log v
                   JOIN followers f ON f.follower_id = v.viewer_id AND f.following_id = $owner AND f.created_at >= v.created_at AND f.created_at <= v.created_at + INTERVAL 1 DAY
                   WHERE v.meme_id = $mid AND v.viewer_id IS NOT NULL");
        $o['eng']['new_followers'] = (int)($y['c'] ?? 0);

        /* audience: followers vs non-followers (among signed-in viewers) */
        $y = $one("SELECT COUNT(DISTINCT v.viewer_id) tot, COUNT(DISTINCT f.follower_id) fol
                   FROM meme_view_log v LEFT JOIN followers f ON f.follower_id = v.viewer_id AND f.following_id = $owner
                   WHERE v.meme_id = $mid AND v.viewer_id IS NOT NULL");
        $o['aud']['followers'] = (int)($y['fol'] ?? 0);
        $o['aud']['non_followers'] = max(0, (int)($y['tot'] ?? 0) - $o['aud']['followers']);
    }
    $o['aud']['guests'] = $o['guests'];

    /* new vs returning: returning = came back on another day to the same post */
    $y = $one("SELECT SUM(n > 1) ret, SUM(n = 1) nw FROM (SELECT COUNT(*) n FROM meme_view_log WHERE meme_id = $mid AND viewer_id IS NOT NULL GROUP BY viewer_id) t");
    $o['aud']['returning'] = (int)($y['ret'] ?? 0); $o['aud']['new_viewers'] = (int)($y['nw'] ?? 0);

    /* impressions = times it was served into somebody's feed */
    $y = $one("SELECT COUNT(*) c FROM dz_feed_serves WHERE meme_id = $mid");
    $o['impressions'] = max((int)($y['c'] ?? 0), $o['total']);

    /* performance over time, measured from when it was posted */
    $y = $one("SELECT SUM(v.created_at <= m.created_at + INTERVAL 1 HOUR) h1, SUM(v.created_at <= m.created_at + INTERVAL 1 DAY) d1, SUM(v.created_at <= m.created_at + INTERVAL 7 DAY) d7, COUNT(*) life
               FROM meme_view_log v JOIN memes m ON m.id = v.meme_id WHERE v.meme_id = $mid");
    $o['time'] = ['hour1'=>(int)($y['h1'] ?? 0), 'day1'=>(int)($y['d1'] ?? 0), 'day7'=>(int)($y['d7'] ?? 0), 'life'=>max((int)($y['life'] ?? 0), $o['total'])];
    return $o;
}

/* 1200 → 1.2K, 3400000 → 3.4M */
function dz_view_fmt(int $n): string {
    if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    if ($n >= 1000)    return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    return (string)$n;
}