<?php
session_start();
include 'config.php';

$user_id = $_SESSION['user_id'] ?? 0;

/* ── ADMIN GUARD ──
   Adjust this check to match however your project already flags
   admins (a role column, an is_admin flag, a hardcoded ID list, etc).
   As written, it assumes a `users.is_admin` column — change if needed. */
if (!$user_id) {
    header('Location: login.php');
    exit;
}

$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$is_admin = (int)($stmt->get_result()->fetch_assoc()['is_admin'] ?? 0);

if (!$is_admin) {
    http_response_code(403);
    die('Forbidden — admin access only.');
}

/* ── SORT / FILTER ── */
$sort = $_GET['sort'] ?? 'impressions';
$validSorts = [
    'impressions' => 'impression_count',
    'likes'       => 'like_count',
    'favorites'   => 'fav_count',
    'dismissals'  => 'dismiss_count',
    'engagement'  => 'engagement_rate',
    'newest'      => 's.id',
];
$sortCol = $validSorts[$sort] ?? 'impression_count';

/* ── MAIN QUERY — per-ad rollup of every stat we track ──
   engagement_rate = (likes + favorites) / impressions, guarded against
   divide-by-zero, expressed as a percentage. */
$sql = "
    SELECT
        s.id,
        s.title,
        s.image,
        s.caption,
        s.status,
        s.link,
        u.username AS poster_username,

        (SELECT COUNT(*) FROM ad_impressions  WHERE sponsorship_id = s.id) AS impression_count,
        (SELECT COUNT(*) FROM ad_likes        WHERE sponsorship_id = s.id) AS like_count,
        (SELECT COUNT(*) FROM ad_favorites    WHERE sponsorship_id = s.id) AS fav_count,
        (SELECT COUNT(*) FROM ad_dismissals   WHERE sponsorship_id = s.id) AS dismiss_count,

        ROUND(
            (
                (SELECT COUNT(*) FROM ad_likes     WHERE sponsorship_id = s.id) +
                (SELECT COUNT(*) FROM ad_favorites WHERE sponsorship_id = s.id)
            )
            /
            GREATEST((SELECT COUNT(*) FROM ad_impressions WHERE sponsorship_id = s.id), 1)
            * 100
        , 2) AS engagement_rate

    FROM sponsorships s
    JOIN users u ON u.id = s.user_id
    ORDER BY $sortCol DESC
";
$result = $conn->query($sql);
$ads = [];
if ($result) { while ($row = $result->fetch_assoc()) { $ads[] = $row; } }

/* ── TOTALS ROW ── */
$totals = [
    'impressions' => array_sum(array_column($ads, 'impression_count')),
    'likes'       => array_sum(array_column($ads, 'like_count')),
    'favorites'   => array_sum(array_column($ads, 'fav_count')),
    'dismissals'  => array_sum(array_column($ads, 'dismiss_count')),
];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ads Analytics | Dezzme Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:Poppins,sans-serif;}
body{
    background:linear-gradient(-45deg,#010218,#070924,#070924);
    background-attachment:fixed;
    color:#F0F2FF;
    min-height:100vh;
    padding:32px clamp(16px,4vw,48px);
}
h1{
    font-size:clamp(22px,3vw,30px);
    font-weight:700;
    margin-bottom:6px;
}
.subtitle{
    color:rgba(255,255,255,0.45);
    font-size:13px;
    margin-bottom:28px;
}

.totals-row{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
    gap:14px;
    margin-bottom:28px;
}
.total-card{
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:16px;
    padding:18px 20px;
}
.total-card .label{
    font-size:11px;
    font-weight:600;
    letter-spacing:0.5px;
    text-transform:uppercase;
    color:rgba(255,255,255,0.4);
    margin-bottom:6px;
}
.total-card .value{
    font-size:26px;
    font-weight:700;
}
.total-card.impressions .value{ color:#5b9dff; }
.total-card.likes .value{ color:#ff2d55; }
.total-card.favorites .value{ color:#ffb020; }
.total-card.dismissals .value{ color:rgba(255,255,255,0.5); }

.sort-bar{
    display:flex;
    gap:8px;
    margin-bottom:18px;
    flex-wrap:wrap;
}
.sort-bar a{
    padding:7px 14px;
    border-radius:20px;
    border:1px solid rgba(255,255,255,0.12);
    color:rgba(255,255,255,0.65);
    text-decoration:none;
    font-size:12.5px;
    font-weight:500;
    transition:0.2s;
}
.sort-bar a:hover, .sort-bar a.active{
    background:#ff2d55;
    border-color:#ff2d55;
    color:#fff;
}

table{
    width:100%;
    border-collapse:collapse;
    background:rgba(255,255,255,0.03);
    border-radius:16px;
    overflow:hidden;
}
thead{ background:rgba(255,255,255,0.05); }
th{
    text-align:left;
    padding:12px 14px;
    font-size:11px;
    font-weight:700;
    letter-spacing:0.5px;
    text-transform:uppercase;
    color:rgba(255,255,255,0.4);
    white-space:nowrap;
}
td{
    padding:12px 14px;
    font-size:13px;
    border-top:1px solid rgba(255,255,255,0.06);
    vertical-align:middle;
}
tr:hover td{ background:rgba(255,255,255,0.02); }

.ad-cell{
    display:flex;
    align-items:center;
    gap:10px;
    max-width:260px;
}
.ad-cell img{
    width:36px;height:36px;
    border-radius:8px;
    object-fit:cover;
    border:1.5px solid rgba(255,45,85,0.5);
    flex-shrink:0;
}
.ad-cell .ad-title{
    font-weight:600;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.ad-cell .ad-caption{
    font-size:11px;
    color:rgba(255,255,255,0.4);
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:200px;
}

.status-badge{
    font-size:10px;
    font-weight:700;
    padding:3px 9px;
    border-radius:20px;
    text-transform:uppercase;
    letter-spacing:0.5px;
}
.status-approved{ background:rgba(77,222,138,0.15); color:#4dde8a; }
.status-pending{ background:rgba(255,176,32,0.15); color:#ffb020; }
.status-rejected{ background:rgba(255,45,45,0.15); color:#ff2d2d; }

.eng-rate{ font-weight:700; }
.eng-good{ color:#4dde8a; }
.eng-mid{ color:#ffb020; }
.eng-low{ color:rgba(255,255,255,0.4); }

.empty-state{
    text-align:center;
    padding:60px 20px;
    color:rgba(255,255,255,0.35);
}
</style>
</head>
<body>

<h1>Ads Performance</h1>
<div class="subtitle">Impressions only count a genuine ~1s view (see impression-honesty fix) — not a bare render.</div>

<div class="totals-row">
    <div class="total-card impressions">
        <div class="label">Total Impressions</div>
        <div class="value"><?= number_format($totals['impressions']) ?></div>
    </div>
    <div class="total-card likes">
        <div class="label">Total Likes</div>
        <div class="value"><?= number_format($totals['likes']) ?></div>
    </div>
    <div class="total-card favorites">
        <div class="label">Total Favorites</div>
        <div class="value"><?= number_format($totals['favorites']) ?></div>
    </div>
    <div class="total-card dismissals">
        <div class="label">Total "Not Interested"</div>
        <div class="value"><?= number_format($totals['dismissals']) ?></div>
    </div>
</div>

<div class="sort-bar">
    <a href="?sort=impressions" class="<?= $sort==='impressions'?'active':'' ?>">Most Viewed</a>
    <a href="?sort=likes" class="<?= $sort==='likes'?'active':'' ?>">Most Liked</a>
    <a href="?sort=favorites" class="<?= $sort==='favorites'?'active':'' ?>">Most Favorited</a>
    <a href="?sort=engagement" class="<?= $sort==='engagement'?'active':'' ?>">Best Engagement Rate</a>
    <a href="?sort=dismissals" class="<?= $sort==='dismissals'?'active':'' ?>">Most Dismissed</a>
    <a href="?sort=newest" class="<?= $sort==='newest'?'active':'' ?>">Newest</a>
</div>

<?php if (empty($ads)): ?>
    <div class="empty-state">No ads found.</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>Ad</th>
            <th>Advertiser</th>
            <th>Status</th>
            <th>Impressions</th>
            <th>Likes</th>
            <th>Favorites</th>
            <th>Dismissed</th>
            <th>Engagement</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($ads as $ad):
            $rate = (float)$ad['engagement_rate'];
            $rateClass = $rate >= 8 ? 'eng-good' : ($rate >= 3 ? 'eng-mid' : 'eng-low');
            $statusClass = 'status-' . ($ad['status'] === 'approved' ? 'approved' : ($ad['status'] === 'pending' ? 'pending' : 'rejected'));
        ?>
        <tr>
            <td>
                <div class="ad-cell">
                    <img src="uploads/<?= htmlspecialchars($ad['image']) ?>" onerror="this.src='uploads/mee.jpeg'">
                    <div style="min-width:0;">
                        <div class="ad-title"><?= htmlspecialchars($ad['title']) ?></div>
                        <div class="ad-caption"><?= htmlspecialchars($ad['caption']) ?></div>
                    </div>
                </div>
            </td>
            <td><?= htmlspecialchars($ad['poster_username']) ?></td>
            <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($ad['status']) ?></span></td>
            <td><?= number_format($ad['impression_count']) ?></td>
            <td><?= number_format($ad['like_count']) ?></td>
            <td><?= number_format($ad['fav_count']) ?></td>
            <td><?= number_format($ad['dismiss_count']) ?></td>
            <td class="eng-rate <?= $rateClass ?>"><?= $rate ?>%</td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

</body>
</html>