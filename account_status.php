<?php
session_start();
include 'config.php';
/* verified flower badge (dz_badge_sprite() + dz_badge('f')) — same file the other pages use */
require_once __DIR__ . '/verified_badge.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if(!$user_id){ header("Location: login.php"); exit(); }

/* ─────────────────────────────────────────────────────────────
   Small safe DB helpers (prepared statements, never fatal)
───────────────────────────────────────────────────────────── */
function as_all($conn, $sql, $types = '', $params = []){
    try{
        $st = $conn->prepare($sql);
        if(!$st) return [];
        if($types !== '') $st->bind_param($types, ...$params);
        $st->execute();
        $res  = $st->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();
        return $rows;
    }catch(Throwable $e){ return []; }
}
function as_one($conn, $sql, $types = '', $params = []){
    $r = as_all($conn, $sql, $types, $params);
    return $r ? $r[0] : null;
}
function as_count($conn, $sql, $types = '', $params = []){
    $r = as_one($conn, $sql, $types, $params);
    return $r ? (int)array_values($r)[0] : 0;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function as_ago($datetime){
    if(!$datetime) return '';
    try{
        $diff = (new DateTime())->diff(new DateTime($datetime));
    }catch(Throwable $e){ return ''; }
    if($diff->y > 0) return $diff->y.' year'.($diff->y>1?'s':'').' ago';
    if($diff->m > 0) return $diff->m.' month'.($diff->m>1?'s':'').' ago';
    if($diff->d > 0) return $diff->d.' day'.($diff->d>1?'s':'').' ago';
    if($diff->h > 0) return $diff->h.' hour'.($diff->h>1?'s':'').' ago';
    if($diff->i > 0) return $diff->i.' min ago';
    return 'Just now';
}
function as_date($datetime){
    $t = $datetime ? strtotime($datetime) : false;
    return $t ? date('M j, Y', $t) : '';
}
function as_plural($n, $one, $many = null){
    return $n.' '.($n == 1 ? $one : ($many ?? $one.'s'));
}

/* How many removals before an account counts as a repeat offender */
const AS_REPEAT = 3;

/* ─────────────────────────────────────────────────────────────
   Support requests table (already in your DB — safe to keep)
───────────────────────────────────────────────────────────── */
$conn->query("
    CREATE TABLE IF NOT EXISTS support_requests (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        center VARCHAR(20) NOT NULL,
        category VARCHAR(60) NOT NULL,
        reference VARCHAR(255) NOT NULL DEFAULT '',
        message TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if(empty($_SESSION['as_csrf'])) $_SESSION['as_csrf'] = bin2hex(random_bytes(16));

$support_categories = [
    'Removed content or message',
    'Pod access',
    'Reach and suggestions',
    'Audience and age settings',
    'Earnings and ads',
    'Verification and security',
    'Something else'
];

/* ─────────────────────────────────────────────────────────────
   POST: send a support request (AJAX / JSON)
───────────────────────────────────────────────────────────── */
if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'support'){
    header('Content-Type: application/json; charset=utf-8');

    $csrf_ok = isset($_POST['csrf']) && hash_equals($_SESSION['as_csrf'], (string)$_POST['csrf']);
    if(!$csrf_ok){
        echo json_encode(['ok'=>false, 'msg'=>'Your session expired. Please refresh the page and try again.']); exit();
    }

    $cat = trim((string)($_POST['category'] ?? ''));
    $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 255);
    $msg = trim((string)($_POST['message'] ?? ''));

    if(!in_array($cat, $support_categories, true)){
        echo json_encode(['ok'=>false, 'msg'=>'Please choose what you need help with.']); exit();
    }
    $len = mb_strlen($msg);
    if($len < 10){
        echo json_encode(['ok'=>false, 'msg'=>'Please tell us a little more (at least 10 characters).']); exit();
    }
    if($len > 1000){
        echo json_encode(['ok'=>false, 'msg'=>'Your message is too long (max 1000 characters).']); exit();
    }

    $recent = as_count($conn,
        "SELECT COUNT(*) FROM support_requests
         WHERE user_id=? AND center='account' AND status='pending'
           AND created_at > (NOW() - INTERVAL 1 DAY)", 'i', [$user_id]);
    if($recent >= 3){
        echo json_encode(['ok'=>false, 'msg'=>'You already have a few open requests. Please wait for a reply before sending more.']); exit();
    }

    try{
        $st = $conn->prepare("INSERT INTO support_requests (user_id, center, category, reference, message, status) VALUES (?, 'account', ?, ?, ?, 'pending')");
        $st->bind_param('isss', $user_id, $cat, $ref, $msg);
        $st->execute();
        $st->close();
        echo json_encode(['ok'=>true, 'msg'=>"Request sent. We'll get back to you soon."]);
    }catch(Throwable $e){
        echo json_encode(['ok'=>false, 'msg'=>'Something went wrong. Please try again.']);
    }
    exit();
}

/* ═════════════════════════════════════════════════════════════
   LOAD EVERYTHING FROM THE DATABASE
═════════════════════════════════════════════════════════════ */
$me = as_one($conn, "SELECT username, nickname, profile_pic, created_at, email, email_verified, is_verified, user_rank, dz_id FROM users WHERE id=? LIMIT 1", 'i', [$user_id]);
if(!$me){ header("Location: 404.php"); exit(); }

$set = as_one($conn, "SELECT is_private, is_fully_private FROM user_settings WHERE user_id=? LIMIT 1", 'i', [$user_id])
       ?: ['is_private'=>0, 'is_fully_private'=>0];

/* ── Removals. Only DECIDED outcomes are shown: 'resolved' (action taken)
      and 'under_review'. 'pending' / 'dismissed' reports are never shown,
      so people who report stay anonymous. ── */
$removed = [];
$review  = [];

$kinds = ['meme'=>['Post','ri-image-2-line'], 'comment'=>['Comment','ri-chat-1-line'], 'message'=>['Message','ri-mail-line']];
$rows = as_all($conn, "
    SELECT cr.content_type AS ctype, cr.reason, cr.status, cr.updated_at AS at
    FROM content_reports cr
    LEFT JOIN memes m ON (cr.content_type='meme' AND m.id = cr.content_id)
    WHERE COALESCE(cr.content_owner_id, m.uploaded_by) = ?
      AND cr.status IN ('resolved','under_review')
    ORDER BY cr.updated_at DESC LIMIT 50", 'i', [$user_id]);
foreach($rows as $r){
    $k  = $kinds[$r['ctype']] ?? ['Content','ri-file-line'];
    $it = ['kind'=>$k[0], 'icon'=>$k[1], 'reason'=>$r['reason'], 'at'=>$r['at']];
    if($r['status'] === 'resolved') $removed[] = $it; else $review[] = $it;
}
foreach(as_all($conn, "SELECT reason, created_at AS at FROM group_post_reports WHERE reported_user_id=? AND status='resolved' ORDER BY created_at DESC LIMIT 50", 'i', [$user_id]) as $r){
    $removed[] = ['kind'=>'Pod post', 'icon'=>'ri-group-line', 'reason'=>$r['reason'], 'at'=>$r['at']];
}
foreach(as_all($conn, "SELECT reason, created_at AS at FROM pod_discussion_reports WHERE reported_user_id=? AND status='resolved' ORDER BY created_at DESC LIMIT 50", 'i', [$user_id]) as $r){
    $removed[] = ['kind'=>'Pod discussion', 'icon'=>'ri-discuss-line', 'reason'=>$r['reason'], 'at'=>$r['at']];
}
foreach(as_all($conn, "SELECT title, updated_at AS at FROM longform_posts WHERE user_id=? AND status='removed' ORDER BY updated_at DESC LIMIT 50", 'i', [$user_id]) as $r){
    $removed[] = ['kind'=>'Video', 'icon'=>'ri-movie-2-line', 'reason'=>'"'.$r['title'].'" was taken down', 'at'=>$r['at']];
}
usort($removed, function($a, $b){ return strtotime($b['at']) <=> strtotime($a['at']); });
usort($review,  function($a, $b){ return strtotime($b['at']) <=> strtotime($a['at']); });
$removed_count = count($removed);
$review_count  = count($review);

/* ── Account-level signals ── */
$acct_resolved = as_all($conn, "SELECT reason, updated_at AS at FROM user_reports WHERE reported_user_id=? AND status='resolved' ORDER BY updated_at DESC LIMIT 20", 'i', [$user_id]);
$acct_review   = as_count($conn, "SELECT COUNT(*) FROM user_reports WHERE reported_user_id=? AND status='under_review'", 'i', [$user_id]);

$bans = as_all($conn, "SELECT g.title, gb.created_at AS at FROM group_bans gb JOIN `groups` g ON g.id = gb.group_id WHERE gb.banned_id=? ORDER BY gb.created_at DESC", 'i', [$user_id]);
$pods_joined = as_count($conn, "SELECT COUNT(*) FROM group_members WHERE user_id=?", 'i', [$user_id]);

$family = as_one($conn, "
    SELECT fl.relationship, fl.responded_at, fl.created_at AS linked_at,
           fs.daily_limit_min, fs.quiet_enabled, fs.quiet_start, fs.quiet_end,
           fs.msg_scope, fs.lock_private, fs.hide_adult, fs.pod_approval, fs.search_visible
    FROM family_links fl
    LEFT JOIN family_settings fs ON fs.link_id = fl.id
    WHERE fl.teen_id=? AND fl.status='active' LIMIT 1", 'i', [$user_id]);

$adult_posts  = as_count($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by=? AND is_adult=1", 'i', [$user_id]);
$adult_pods   = as_count($conn, "SELECT COUNT(*) FROM `groups` WHERE created_by=? AND is_adult=1", 'i', [$user_id]);
$total_posts  = as_count($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by=?", 'i', [$user_id]);
$not_featured = as_count($conn, "SELECT COUNT(*) FROM memes WHERE uploaded_by=? AND feature_ok=0", 'i', [$user_id]);

$spons = ['pending'=>0, 'approved'=>0, 'rejected'=>0];
foreach(as_all($conn, "SELECT status, COUNT(*) AS c FROM sponsorships WHERE user_id=? GROUP BY status", 'i', [$user_id]) as $r){
    if(isset($spons[$r['status']])) $spons[$r['status']] = (int)$r['c'];
}
$ads = ['pending'=>0, 'approved'=>0, 'rejected'=>0];
foreach(as_all($conn, "SELECT ma.status, COUNT(*) AS c FROM meme_ads ma JOIN memes m ON m.id = ma.meme_id WHERE m.uploaded_by=? GROUP BY ma.status", 'i', [$user_id]) as $r){
    if(isset($ads[$r['status']])) $ads[$r['status']] = (int)$r['c'];
}
$ad_reports_actioned = as_count($conn, "SELECT COUNT(*) FROM ad_reports ar JOIN sponsorships s ON s.id = ar.sponsorship_id WHERE s.user_id=? AND ar.status='reviewed'", 'i', [$user_id]);
$verif = as_one($conn, "SELECT plan, status, reviewed_at, created_at FROM verification_requests WHERE user_id=? ORDER BY id DESC LIMIT 1", 'i', [$user_id]);

$sessions_count = as_count($conn, "SELECT COUNT(*) FROM user_sessions WHERE user_id=?", 'i', [$user_id]);
$linked_count   = as_count($conn, "SELECT COUNT(*) FROM linked_accounts WHERE primary_user_id=? OR linked_user_id=?", 'ii', [$user_id, $user_id]);

/* ═════════════════════════════════════════════════════════════
   STATUS ENGINE
═════════════════════════════════════════════════════════════ */
$rank = ['ok'=>0, 'info'=>1, 'warn'=>2, 'bad'=>3];
function worst_state($items){
    global $rank;
    $w = 'ok';
    foreach($items as $i){ if($rank[$i['level']] > $rank[$w]) $w = $i['level']; }
    return $w;
}
function row_sub($items, $fallback){
    global $rank;
    $top = null; $n = 0;
    foreach($items as $i){
        if($i['level'] === 'ok') continue;
        $n++;
        if($top === null || $rank[$i['level']] > $rank[$top['level']]) $top = $i;
    }
    if(!$top) return $fallback;
    return $top['title'].($n > 1 ? ' · +'.($n - 1).' more' : '');
}

/* ── 1. Content standing ── */
$r_items = [];
$resolved_level = $removed_count >= AS_REPEAT ? 'bad' : 'warn';
foreach($removed as $r){
    $r_items[] = [
        'icon'=>$r['icon'], 'level'=>$resolved_level,
        'title'=>$r['kind'].' removed',
        'text'=>'Reason: '.$r['reason'],
        'meta'=>as_date($r['at']).' · '.as_ago($r['at'])
    ];
}
foreach($review as $r){
    $r_items[] = [
        'icon'=>$r['icon'], 'level'=>'info',
        'title'=>$r['kind'].' under review',
        'text'=>'Our team is looking at this ('.$r['reason'].'). Nothing has been removed yet.',
        'meta'=>as_ago($r['at'])
    ];
}
$r_state = worst_state($r_items);
$r_sub = '';
if($removed_count) $r_sub = as_plural($removed_count, 'item').' removed';
if($review_count)  $r_sub .= ($r_sub ? ' · ' : '').$review_count.' under review';
if($r_sub === '')  $r_sub = $total_posts > 0 ? 'All '.as_plural($total_posts, 'post').' are in good standing' : 'No removed content or messages';

/* ── 2. Pod access ── */
$p_items = [];
foreach($bans as $b){
    $p_items[] = [
        'icon'=>'ri-forbid-2-line', 'level'=>'bad',
        'title'=>'Banned from '.$b['title'],
        'text'=>"You can't join, post or take part in this pod.",
        'meta'=>as_date($b['at']).' · '.as_ago($b['at'])
    ];
}
if($family && !empty($family['pod_approval'])){
    $p_items[] = [
        'icon'=>'ri-parent-line', 'level'=>'info',
        'title'=>'Pods need approval',
        'text'=>'Because of parental supervision, a parent or guardian approves the pods you join.',
        'meta'=>''
    ];
}
$p_state = worst_state($p_items);
if($bans){
    $p_sub = 'Banned from '.as_plural(count($bans), 'pod');
}else{
    $p_sub = $pods_joined > 0 ? 'Member of '.as_plural($pods_joined, 'pod').', no restrictions' : 'No pod restrictions';
}
if($p_state === 'info' && !$bans) $p_sub = row_sub($p_items, $p_sub);

/* ── 3. Reach & suggestions ── */
$c_items = [];
foreach($acct_resolved as $r){
    $c_items[] = [
        'icon'=>'ri-user-unfollow-line', 'level'=>'bad',
        'title'=>'Account limited after a report',
        'text'=>'Reason: '.$r['reason'].'. Your account is shown less in suggestions.',
        'meta'=>as_date($r['at'])
    ];
}
if($acct_review > 0){
    $c_items[] = [
        'icon'=>'ri-time-line', 'level'=>'info',
        'title'=>'Account under review',
        'text'=>"We're reviewing your account. Your reach won't change unless we find a problem.",
        'meta'=>''
    ];
}
if($not_featured > 0){
    $c_items[] = [
        'icon'=>'ri-star-off-line', 'level'=>'warn',
        'title'=>as_plural($not_featured, 'post').' can\'t be featured',
        'text'=>"These posts won't be suggested to people who don't track you yet.",
        'meta'=>$total_posts > 0 ? $not_featured.' of '.$total_posts.' posts' : ''
    ];
}
if($removed_count >= AS_REPEAT){
    $c_items[] = [
        'icon'=>'ri-arrow-down-circle-line', 'level'=>'warn',
        'title'=>'Repeated removals',
        'text'=>'You have '.$removed_count.' removed items, which can reduce how often your content is suggested.',
        'meta'=>''
    ];
}
if((int)$set['is_fully_private']){
    $c_items[] = [
        'icon'=>'ri-lock-2-line', 'level'=>'info',
        'title'=>'Your account is fully private',
        'text'=>"Private accounts aren't suggested to people who don't track you. This is your choice and you can change it in settings.",
        'meta'=>''
    ];
}elseif((int)$set['is_private']){
    $c_items[] = [
        'icon'=>'ri-lock-line', 'level'=>'info',
        'title'=>'Your account is private',
        'text'=>"Your content is only suggested to approved trackers. This is your choice and you can change it in settings.",
        'meta'=>''
    ];
}
$c_state = worst_state($c_items);
$c_sub = row_sub($c_items, 'Your posts can be suggested to everyone');

/* ── 4. Audience & age ── */
$u_items = [];
if($adult_posts > 0){
    $u_items[] = [
        'icon'=>'ri-eye-off-line', 'level'=>'info',
        'title'=>as_plural($adult_posts, 'post').' marked 18+',
        'text'=>"Posts marked 18+ are hidden from people under 18.",
        'meta'=>''
    ];
}
if($adult_pods > 0){
    $u_items[] = [
        'icon'=>'ri-group-line', 'level'=>'info',
        'title'=>as_plural($adult_pods, 'pod').' marked 18+',
        'text'=>"People under 18 can't see or join pods you created that are marked 18+.",
        'meta'=>''
    ];
}
if($family){
    $bits = [];
    if(isset($family['daily_limit_min']) && (int)$family['daily_limit_min'] > 0) $bits[] = 'Daily limit '.(int)$family['daily_limit_min'].' min';
    if(!empty($family['quiet_enabled']) && !empty($family['quiet_start'])) $bits[] = 'Quiet hours '.substr($family['quiet_start'],0,5).'–'.substr($family['quiet_end'],0,5);
    if(isset($family['msg_scope'])) $bits[] = 'Messages: '.($family['msg_scope'] === 'everyone' ? 'everyone' : ($family['msg_scope'] === 'trackers' ? 'trackers only' : 'nobody'));
    if(!empty($family['lock_private']))    $bits[] = 'Account kept private';
    if(isset($family['search_visible']) && !(int)$family['search_visible']) $bits[] = 'Hidden from search';
    $u_items[] = [
        'icon'=>'ri-shield-user-line', 'level'=>'info',
        'title'=>'Parental supervision is on',
        'text'=>$bits ? implode(' · ', $bits) : 'A parent or guardian manages some settings on your account.',
        'meta'=>!empty($family['responded_at']) ? 'Since '.as_date($family['responded_at']) : ''
    ];
    if(!empty($family['hide_adult'])){
        $u_items[] = [
            'icon'=>'ri-prohibited-line', 'level'=>'info',
            'title'=>'18+ content is hidden',
            'text'=>"You won't see content marked 18+ while supervision is on.",
            'meta'=>''
        ];
    }
}
$u_state = worst_state($u_items);
$u_sub = row_sub($u_items, 'Open to everyone, no age limits applied');

/* ── 5. Earnings & ads ── */
$m_items = [];
if($ad_reports_actioned > 0){
    $m_items[] = [
        'icon'=>'ri-megaphone-line', 'level'=>'bad',
        'title'=>'Sponsored content was actioned',
        'text'=>as_plural($ad_reports_actioned, 'report').' on your sponsorships led to action by our team.',
        'meta'=>''
    ];
}
if($removed_count >= AS_REPEAT){
    $m_items[] = [
        'icon'=>'ri-money-dollar-circle-line', 'level'=>'bad',
        'title'=>'Earning tools limited',
        'text'=>'Repeated removals can pause your access to ads and sponsorships.',
        'meta'=>''
    ];
}
$rej = $spons['rejected'] + $ads['rejected'];
if($rej > 0){
    $m_items[] = [
        'icon'=>'ri-close-circle-line', 'level'=>'warn',
        'title'=>as_plural($rej, 'ad').' not approved',
        'text'=>"Some of your sponsorships or ads didn't meet our ad guidelines.",
        'meta'=>''
    ];
}
$pend = $spons['pending'] + $ads['pending'];
if($pend > 0){
    $m_items[] = [
        'icon'=>'ri-time-line', 'level'=>'info',
        'title'=>as_plural($pend, 'ad').' awaiting approval',
        'text'=>"We're reviewing these. The result will show up here.",
        'meta'=>''
    ];
}
$appr = $spons['approved'] + $ads['approved'];
if($appr > 0){
    $m_items[] = [
        'icon'=>'ri-checkbox-circle-line', 'level'=>'ok',
        'title'=>as_plural($appr, 'ad').' approved',
        'text'=>'These meet our ad guidelines.',
        'meta'=>''
    ];
}
$m_state = worst_state($m_items);
$m_sub = row_sub($m_items, $appr > 0 ? as_plural($appr, 'ad').' approved' : 'No issues with ads or sponsorships');

/* ── 6. Verification & security ── */
$s_items = [];
if(!(int)$me['email_verified']){
    $s_items[] = [
        'icon'=>'ri-mail-unread-line', 'level'=>'warn',
        'title'=>'Email not verified',
        'text'=>'Verify '.$me['email'].' to protect your account and recover it if you get locked out.',
        'meta'=>''
    ];
}else{
    $s_items[] = [
        'icon'=>'ri-mail-check-line', 'level'=>'ok',
        'title'=>'Email verified',
        'text'=>'Your email address is confirmed.',
        'meta'=>''
    ];
}
if((int)$me['is_verified']){
    $s_items[] = [
        'icon'=>'ri-verified-badge-line', 'level'=>'ok',
        'title'=>'Verified account',
        'text'=>'Your account carries the verified badge.',
        'meta'=>$verif ? ucfirst($verif['plan']).' plan' : ''
    ];
}elseif($verif){
    $vmap = [
        'pending'   => ['info', 'Verification request pending', "We're reviewing your verification request."],
        'approved'  => ['ok',   'Verification approved',        'Your verification request was approved.'],
        'rejected'  => ['warn', 'Verification not approved',    "Your last verification request wasn't approved."],
        'cancelled' => ['info', 'Verification cancelled',       'Your last verification request was cancelled.']
    ];
    if(isset($vmap[$verif['status']])){
        $v = $vmap[$verif['status']];
        $s_items[] = ['icon'=>'ri-verified-badge-line', 'level'=>$v[0], 'title'=>$v[1], 'text'=>$v[2], 'meta'=>ucfirst($verif['plan']).' plan · '.as_ago($verif['reviewed_at'] ?: $verif['created_at'])];
    }
}
if($sessions_count > 0){
    $s_items[] = [
        'icon'=>'ri-smartphone-line', 'level'=>$sessions_count > 5 ? 'warn' : 'info',
        'title'=>as_plural($sessions_count, 'active session'),
        'text'=>$sessions_count > 5 ? "You're signed in on a lot of devices. If you don't recognise them, change your password." : "Devices where you're currently signed in.",
        'meta'=>''
    ];
}
if($linked_count > 0){
    $s_items[] = [
        'icon'=>'ri-links-line', 'level'=>'info',
        'title'=>as_plural($linked_count, 'linked account'),
        'text'=>'Other Dezzme accounts connected to this one.',
        'meta'=>''
    ];
}
$s_state = worst_state($s_items);
$s_sub = row_sub($s_items, ((int)$me['is_verified'] ? 'Verified' : 'Email verified').($sessions_count ? ' · '.as_plural($sessions_count, 'active session') : ''));

/* ── The six rows ── */
$ROWS = [
    ['key'=>'content',  'icon'=>'ri-article-line',          'title'=>'Content standing',     'state'=>$r_state, 'sub'=>$r_sub, 'items'=>$r_items,
     'intro'=>'Posts, comments, messages, pod content and videos that were reviewed or removed under our guidelines.',
     'empty'=>"You don't have any removed content or messages."],
    ['key'=>'pods',     'icon'=>'ri-group-line',            'title'=>'Pod access',           'state'=>$p_state, 'sub'=>$p_sub, 'items'=>$p_items,
     'intro'=>'Your access to pods you belong to, and any bans placed by pod owners and admins.',
     'empty'=>"You aren't banned from any pod."],
    ['key'=>'reach',    'icon'=>'ri-radar-line',            'title'=>'Reach and suggestions','state'=>$c_state, 'sub'=>$c_sub, 'items'=>$c_items,
     'intro'=>"Whether your account and posts can be suggested to people who don't track you yet.",
     'empty'=>'Your account and posts can be suggested to everyone.'],
    ['key'=>'audience', 'icon'=>'ri-shield-user-line',      'title'=>'Audience and age',     'state'=>$u_state, 'sub'=>$u_sub, 'items'=>$u_items,
     'intro'=>'Who can see your account and content, including age limits and parental supervision.',
     'empty'=>'No age limits or supervision are applied to your account.'],
    ['key'=>'earnings', 'icon'=>'ri-money-dollar-circle-line','title'=>'Earnings and ads',   'state'=>$m_state, 'sub'=>$m_sub, 'items'=>$m_items,
     'intro'=>'Your access to ads, sponsorships and other ways to earn on Dezzme.',
     'empty'=>'You can use all earning tools.'],
    ['key'=>'security', 'icon'=>'ri-lock-password-line',    'title'=>'Verification and security','state'=>$s_state, 'sub'=>$s_sub, 'items'=>$s_items,
     'intro'=>'Your email, verification badge and the devices signed in to your account.',
     'empty'=>'Nothing to review here.']
];

$overall = 'ok';
foreach($ROWS as $r){
    if($r['state'] === 'bad') $overall = 'bad';
    elseif($r['state'] === 'warn' && $overall !== 'bad') $overall = 'warn';
}

/* Pill labels per state */
$PILL = ['ok'=>'Good', 'info'=>'Notice', 'warn'=>'Attention', 'bad'=>'Action taken'];

/* Count rows by state (for the hero line) */
$flagged = 0;
foreach($ROWS as $r){ if($r['state'] === 'warn' || $r['state'] === 'bad') $flagged++; }

/* Days in good standing = since the last decided action (or since joining) */
$last_bad = 0;
foreach($removed as $r){ $t = strtotime($r['at']); if($t > $last_bad) $last_bad = $t; }
foreach($bans as $b){ $t = strtotime($b['at']); if($t > $last_bad) $last_bad = $t; }
foreach($acct_resolved as $r){ $t = strtotime($r['at']); if($t > $last_bad) $last_bad = $t; }
$joined_ts  = strtotime($me['created_at']) ?: time();
$since_ts   = $last_bad ?: $joined_ts;
$clean_days = max(0, (int)floor((time() - $since_ts) / 86400));

$uname = $me['username'];
$HERO = [
    'ok'   => ['In good standing',     'No restrictions on @'.$uname.'. '.($last_bad ? 'Your last notice was '.as_ago(date('Y-m-d H:i:s', $last_bad)).'.' : 'You have never had a violation.')],
    'warn' => ['A few things to check', as_plural($flagged, 'area').' on your account '.($flagged == 1 ? 'needs' : 'need').' attention. Nothing is blocked right now.'],
    'bad'  => ['Action was taken',      as_plural($flagged, 'area').' on your account '.($flagged == 1 ? 'has' : 'have').' limits or removals. Tap a card below to see why.']
][$overall];

/* ── Recent activity timeline (everything real, newest first) ── */
$EV = [];
foreach($removed as $r){
    $EV[] = ['icon'=>$r['icon'], 'level'=>$resolved_level, 'title'=>$r['kind'].' removed', 'text'=>$r['reason'], 'at'=>$r['at']];
}
foreach($review as $r){
    $EV[] = ['icon'=>$r['icon'], 'level'=>'info', 'title'=>$r['kind'].' under review', 'text'=>$r['reason'], 'at'=>$r['at']];
}
foreach($bans as $b){
    $EV[] = ['icon'=>'ri-forbid-2-line', 'level'=>'bad', 'title'=>'Banned from '.$b['title'], 'text'=>'Pod access removed', 'at'=>$b['at']];
}
foreach($acct_resolved as $r){
    $EV[] = ['icon'=>'ri-user-unfollow-line', 'level'=>'bad', 'title'=>'Account limited', 'text'=>$r['reason'], 'at'=>$r['at']];
}
if($verif){
    $vt = ['pending'=>['info','Verification requested'], 'approved'=>['ok','Verification approved'], 'rejected'=>['warn','Verification not approved'], 'cancelled'=>['info','Verification cancelled']];
    if(isset($vt[$verif['status']])){
        $EV[] = ['icon'=>'ri-verified-badge-line', 'level'=>$vt[$verif['status']][0], 'title'=>$vt[$verif['status']][1], 'text'=>ucfirst($verif['plan']).' plan', 'at'=>($verif['reviewed_at'] ?: $verif['created_at'])];
    }
}
if($family){
    $EV[] = ['icon'=>'ri-shield-user-line', 'level'=>'info', 'title'=>'Parental supervision started', 'text'=>'Managed by a '.($family['relationship'] ?: 'parent or guardian'), 'at'=>($family['responded_at'] ?: $family['linked_at'])];
}
$EV[] = ['icon'=>'ri-user-add-line', 'level'=>'ok', 'title'=>'Account created', 'text'=>'Welcome to Dezzme', 'at'=>$me['created_at']];
usort($EV, function($a, $b){ return strtotime($b['at']) <=> strtotime($a['at']); });
$EV = array_slice($EV, 0, 8);

/* ── Account details (all from the DB) ── */
$privacy_txt = (int)$set['is_fully_private'] ? 'Fully private' : ((int)$set['is_private'] ? 'Private' : 'Public');
if((int)$me['is_verified']) $verify_txt = 'Verified';
elseif($verif && $verif['status'] === 'pending') $verify_txt = 'Request pending';
else $verify_txt = 'Not verified';

function st_icon($s){
    $m = [
        'ok'   => ['#22c55e', '<path d="M7.2 12.6l3.1 3.1 6.5-6.7"/>'],
        'warn' => ['#f59e0b', '<path d="M12 7.2v6.2"/><path d="M12 16.6v.01"/>'],
        'bad'  => ['#FF2D2D', '<path d="M8.4 8.4l7.2 7.2M15.6 8.4l-7.2 7.2"/>'],
        'info' => ['#60a5fa', '<path d="M12 11v5.2"/><path d="M12 7.8v.01"/>']
    ];
    $c = $m[$s] ?? $m['ok'];
    return '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="12" fill="'.$c[0].'"/><g fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">'.$c[1].'</g></svg>';
}
$ICONS = [];
foreach(['ok','warn','bad','info'] as $s) $ICONS[$s] = st_icon($s);

$pic = !empty($me['profile_pic']) ? $me['profile_pic'] : 'uploads/me.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Status | Dezzme</title>

<!-- THEME: same switch as community.php — runs first so there is no dark → light flash -->
<script>
try{
    if (localStorage.getItem('dz_theme') === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    }
}catch(e){}
</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

:root{
    --red:      #FF2D2D;
    --green:    #22c55e;
    --amber:    #f59e0b;
    --blue:     #60a5fa;

    --fg:       255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text:  #ffffff;

    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --solid:    #070924;        /* avatar badge ring */
    --sheet:    rgba(12,12,28,0.98);   /* modal sheets + select dropdown */
    --ph:       #1a1a1a;        /* avatar placeholder */
    --overlay:  rgba(0,0,0,0.7);
    --surface:  rgba(var(--fg),0.05);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.14);
    --text:     #ffffff;
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.62);

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);
    --r:        5px;
    --act-h:    clamp(25px, 7.2vw, 30px);
    --act-r:    4px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; }

/* RESPONSIVE view: no scrollbars anywhere — scrolling still works. */
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}

body{
    background:var(--bg);
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    display:flex; justify-content:center;
    overflow-x:hidden;
    -webkit-font-smoothing:antialiased;
}

/* RESPONSIVE view: everything 5% bigger */
@media (max-width:800px){
    .ac-lane, .sk-lane, .as-sheet{ zoom:1.05; }
}

/* state colours */
.st-ok  { --c:var(--green); --cbg:rgba(34,197,94,.13);  --cbd:rgba(34,197,94,.30); }
.st-info{ --c:var(--blue);  --cbg:rgba(96,165,250,.13); --cbd:rgba(96,165,250,.30); }
.st-warn{ --c:var(--amber); --cbg:rgba(245,158,11,.13); --cbd:rgba(245,158,11,.30); }
.st-bad { --c:var(--red);   --cbg:rgba(255,45,45,.13);  --cbd:rgba(255,45,45,.32); }

/* ── centered lane + grey side lines ── */
.ac-lane{
    width:100%; max-width:var(--max-w);
    min-height:100vh;
    display:flex; flex-direction:column;
    padding-bottom:72px;
}
@media (min-width:801px){
    .ac-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); }
}
@media (max-width:800px){ .ac-lane{ max-width:100%; } }

/* ══════════════════════════════════════
   TOPBAR — X on the left, "Account Status" on the right
══════════════════════════════════════ */
.ac-topbar{
    position:sticky; top:0; z-index:500;
    height:var(--topbar-h);
    display:flex; align-items:center; gap:2px;
    padding:0 10px;
    background:var(--bg);
    background-attachment:fixed;
}
.ac-topbar::after{
    content:''; position:absolute; left:0; right:0; bottom:0;
    height:1px; background:rgba(var(--fg),0.14);
    pointer-events:none; z-index:2;
}
.ac-x{
    width:34px; height:34px;
    background:none; border:none; color:var(--ui-text);
    display:flex; align-items:center; justify-content:center;
    opacity:.9; transition:opacity .2s, color .2s;
    padding:0; flex-shrink:0;
}
.ac-x:hover{ opacity:1; color:var(--red); }
.ac-x svg{ width:22px; height:22px; display:block; }
.ac-title{
    flex:1; min-width:0;
    font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    text-align:right; margin:0 6px 0 10px;
}
@media (max-width:490px){
    .ac-x{ width:30px; height:30px; }
    .ac-title{ font-size:.84rem; }
}
@media (max-width:380px){ .ac-title{ font-size:.8rem; } }

/* ══════════════════════════════════════
   HERO
══════════════════════════════════════ */
.as-hero{
    position:relative; overflow:hidden;
    display:flex; flex-direction:column; align-items:center; text-align:center;
    padding:clamp(20px, 5.5vw, 30px) var(--pad) clamp(18px, 5vw, 26px);
}
.as-avatar-wrap{ position:relative; margin-bottom:14px; }
.as-avatar{
    position:relative;
    width:clamp(72px, 21vw, 98px); height:clamp(72px, 21vw, 98px);
    border-radius:50%; object-fit:cover; display:block;
    border:2.6px solid var(--red);
    background:var(--ph);
}
.as-badge{
    position:absolute; right:-3px; bottom:0;
    width:clamp(24px, 7vw, 30px); height:clamp(24px, 7vw, 30px);
    border-radius:50%; background:var(--solid);
    display:flex; align-items:center; justify-content:center; padding:2.5px;
}
.as-badge svg{ width:100%; height:100%; display:block; }
/* name + verified badge on one line; a long name is cut with "…" but the badge never is */
.as-name{
    font-size:clamp(.88rem, 3.4vw, 1.05rem); font-weight:700; color:var(--ui-text);
    max-width:100%;
    display:flex; align-items:center; justify-content:center; gap:.3em; min-width:0;
}
.as-name .as-nm{ min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* verified badge: filled, 1em = follows the name size, RED (same colour as the avatar border) in dark AND light mode */
.as-name .vbadge{
    width:1em; height:1em; flex-shrink:0;
    vertical-align:baseline;
    color:var(--red) !important;
    fill:currentColor;
}
.as-eyebrow{
    margin-top:2px;
    font-size:clamp(.58rem, 1.9vw, .68rem); color:var(--muted); font-weight:500;
    text-transform:uppercase; letter-spacing:.08em;
}
.as-pill-hero{
    margin-top:14px;
    display:inline-flex; align-items:center; gap:7px;
    height:clamp(26px, 7vw, 30px); padding:0 14px;
    border-radius:var(--act-r);
    background:var(--cbg); border:1px solid var(--cbd);
    color:var(--c); font-size:clamp(.68rem, 2.2vw, .78rem); font-weight:700;
}
.as-pill-hero::before{ content:''; width:7px; height:7px; border-radius:50%; background:var(--c); }
.as-subline{
    margin-top:10px;
    font-size:clamp(.64rem, 2vw, .74rem); color:var(--muted);
    line-height:1.7; max-width:360px;
}

/* meter: one segment per area, coloured by its status */
.as-meter{ display:flex; gap:4px; width:100%; max-width:300px; margin-top:16px; }
.as-seg{ flex:1; height:4px; border-radius:1px; background:var(--c); opacity:.9; }

/* ── Stats bar ── */
.stats-bar{
    display:grid; grid-template-columns:repeat(3, 1fr);
    border-top:1px solid var(--line); border-bottom:1px solid var(--line);
}
.stat-item{ display:flex; flex-direction:column; align-items:center; padding:clamp(11px, 3.4vw, 16px) 6px; text-align:center; }
.stat-item + .stat-item{ border-left:1px solid var(--line); }
.stat-num{ font-size:clamp(.88rem, 3.2vw, 1.2rem); font-weight:700; color:var(--text); line-height:1; }
.stat-num.c-bad{ color:var(--red); }
.stat-num.c-warn{ color:var(--amber); }
.stat-num.c-info{ color:var(--blue); }
.stat-lbl{ font-size:clamp(.48rem, 1.7vw, .6rem); color:var(--muted); margin-top:4px; letter-spacing:.03em; text-transform:uppercase; }

/* ── Section label ── */
.section-label{
    padding:clamp(14px, 4vw, 20px) var(--pad) 6px;
    font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted);
}

/* ══════════════════════════════════════
   STATUS CARDS (rows)
══════════════════════════════════════ */
.info-list{ display:flex; flex-direction:column; }
.divider{ width:100%; border:none; border-top:1px solid var(--line); margin:0; }

.sc-row{
    width:100%; text-align:left; color:inherit; font:inherit;
    background:none; border:none;
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:clamp(11px, 3.2vw, 16px) var(--pad);
    transition:background .18s;
}
.sc-row:hover{ background:rgba(var(--fg),0.04); }
.sc-row:active{ background:rgba(var(--fg),0.07); }
.sc-icon{
    width:clamp(30px, 8.4vw, 38px); height:clamp(30px, 8.4vw, 38px);
    border-radius:6px; background:var(--cbg);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; color:var(--c); font-size:clamp(.85rem, 2.8vw, 1.05rem);
    border:1px solid var(--cbd);
}
.sc-main{ flex:1; min-width:0; }
.sc-label{
    font-size:clamp(.54rem, 1.8vw, .66rem); color:var(--muted); font-weight:400;
    line-height:1.2; text-transform:uppercase; letter-spacing:.05em;
}
.sc-value{
    font-size:clamp(.72rem, 2.4vw, .86rem); font-weight:500; color:var(--text);
    line-height:1.4; margin-top:2px; word-break:break-word;
}
.sc-pill{
    flex-shrink:0; display:flex; align-items:center; justify-content:center;
    height:clamp(20px, 5.6vw, 23px); padding:0 9px; border-radius:4px;
    background:var(--cbg); color:var(--c);
    font-size:.6rem; font-weight:700; letter-spacing:.02em; white-space:nowrap;
}
.chevron{ color:var(--muted); font-size:1rem; flex-shrink:0; }

/* ── Account detail rows ── */
.info-row{
    display:flex; align-items:center; gap:clamp(10px, 3vw, 14px);
    padding:clamp(11px, 3.2vw, 16px) var(--pad);
}
.info-icon{
    width:clamp(27px, 7.6vw, 36px); height:clamp(27px, 7.6vw, 36px);
    border-radius:6px; background:var(--surface);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; color:var(--red); font-size:clamp(.78rem, 2.6vw, 1rem);
}
.info-content{ flex:1; min-width:0; }
.info-label{
    font-size:clamp(.54rem, 1.8vw, .66rem); color:var(--muted); font-weight:400;
    line-height:1.2; text-transform:uppercase; letter-spacing:.05em;
}
.info-value{
    font-size:clamp(.72rem, 2.4vw, .86rem); font-weight:500; color:var(--text);
    line-height:1.4; margin-top:2px; word-break:break-word;
}
.info-value.v-ok{ color:var(--green); }
.info-value.v-warn{ color:var(--amber); }
.info-row.clickable{ cursor:pointer; transition:background .18s; width:100%; background:none; border:none; color:inherit; font:inherit; text-align:left; }
.info-row.clickable:hover{ background:rgba(var(--fg),0.04); }
.info-row.clickable:hover .info-icon{ background:rgba(255,45,45,0.12); }

/* ── Timeline ── */
.tl{ padding:4px var(--pad) 6px; }
.tl-item{ position:relative; display:flex; gap:clamp(10px, 3vw, 14px); padding:0 0 clamp(14px, 4vw, 18px); }
.tl-item:last-child{ padding-bottom:6px; }
.tl-item::before{
    content:''; position:absolute; left:calc(clamp(24px, 6.8vw, 30px) / 2 - .5px); top:clamp(24px, 6.8vw, 30px); bottom:-2px;
    width:1px; background:var(--line2);
}
.tl-item:last-child::before{ display:none; }
.tl-dot{
    width:clamp(24px, 6.8vw, 30px); height:clamp(24px, 6.8vw, 30px); flex-shrink:0;
    border-radius:50%; background:var(--cbg); border:1px solid var(--cbd); color:var(--c);
    display:flex; align-items:center; justify-content:center;
    font-size:clamp(.7rem, 2.4vw, .85rem);
    position:relative; z-index:1;
}
.tl-body{ flex:1; min-width:0; padding-top:1px; }
.tl-title{ font-size:clamp(.72rem, 2.4vw, .82rem); font-weight:600; color:var(--ui-text); line-height:1.35; }
.tl-text{ margin-top:2px; font-size:clamp(.62rem, 2vw, .7rem); color:var(--sub); line-height:1.6; word-break:break-word; }
.tl-time{ margin-top:3px; font-size:clamp(.54rem, 1.7vw, .62rem); color:var(--muted); }

/* ── Notes ── */
.mutual-notes{ padding:clamp(11px, 3.4vw, 16px) var(--pad); display:flex; flex-direction:column; gap:8px; }
.mutual-note{ font-size:clamp(.6rem, 1.9vw, .7rem); color:var(--muted); line-height:1.65; }
.mutual-note strong{ color:rgba(var(--fg),0.7); font-weight:600; }

/* ══════════════════════════════════════
   MODALS — centered (desktop) / bottom sheet (responsive)
══════════════════════════════════════ */
.as-overlay{
    position:fixed; inset:0; z-index:999999;
    background:var(--overlay);
    backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
    display:none; align-items:flex-end; justify-content:center;
}
.as-overlay.open{ display:flex; animation:asFade .18s ease both; }
@keyframes asFade{ from{ opacity:0; } to{ opacity:1; } }
@keyframes asSlideUp{ from{ transform:translateY(60px); opacity:0; } to{ transform:none; opacity:1; } }

.as-sheet{
    position:relative; width:100%; max-width:480px; max-height:82vh;
    display:flex; flex-direction:column; overflow:hidden;
    background:var(--sheet);
    border:1px solid var(--line); border-bottom:none;
    border-radius:10px 10px 0 0;
    animation:asSlideUp .28s cubic-bezier(.32,.72,0,1);
}
.as-sheet::before{
    content:''; display:block; flex-shrink:0;
    width:36px; height:4px; background:rgba(var(--fg),.18);
    border-radius:4px; margin:10px auto 2px;
}
.as-sheet-head{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:10px var(--pad); border-bottom:1px solid var(--line); flex-shrink:0;
}
.as-sheet-title{ font-size:.88rem; font-weight:700; color:var(--ui-text); display:flex; align-items:center; gap:10px; min-width:0; }
.as-sheet-title > span:last-child{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.as-sheet-badge{
    font-size:.6rem; font-weight:700; line-height:1; flex-shrink:0;
    background:var(--cbg); color:var(--c);
    border-radius:4px; padding:4px 7px;
}
.as-close{
    width:28px; height:28px; flex-shrink:0;
    background:rgba(var(--fg),.08); border:none; border-radius:4px;
    color:var(--sub); font-size:1.05rem;
    display:flex; align-items:center; justify-content:center;
    transition:background .2s, color .2s;
}
.as-close:hover{ background:rgba(var(--fg),.18); color:var(--ui-text); }
.as-sheet-body{ overflow-y:auto; flex:1; padding:0 0 calc(env(safe-area-inset-bottom,0px) + 18px); }

.as-intro{
    padding:clamp(11px, 3.4vw, 16px) var(--pad);
    font-size:clamp(.66rem, 2.1vw, .74rem); color:var(--muted); line-height:1.7;
    border-bottom:1px solid var(--line);
}
.as-empty{
    display:flex; align-items:center; gap:12px;
    padding:clamp(18px, 5vw, 26px) var(--pad);
    font-size:clamp(.74rem, 2.4vw, .84rem); color:var(--text); line-height:1.5;
}
.as-empty svg{ width:24px; height:24px; flex-shrink:0; }

.as-item{
    display:flex; align-items:flex-start; gap:clamp(10px, 3vw, 14px);
    padding:clamp(12px, 3.4vw, 16px) var(--pad);
    border-bottom:1px solid var(--line);
}
.as-item-ic{
    width:clamp(30px, 8vw, 36px); height:clamp(30px, 8vw, 36px);
    border-radius:6px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:clamp(.85rem, 2.8vw, 1rem);
    background:var(--cbg); color:var(--c); border:1px solid var(--cbd);
}
.as-item-body{ flex:1; min-width:0; }
.as-item-title{ font-size:clamp(.74rem, 2.4vw, .84rem); font-weight:600; color:var(--ui-text); line-height:1.4; }
.as-item-text{ margin-top:3px; font-size:clamp(.66rem, 2.1vw, .74rem); color:var(--sub); line-height:1.65; word-break:break-word; }
.as-item-meta{ margin-top:4px; font-size:clamp(.56rem, 1.8vw, .64rem); color:var(--muted); }

.as-help{
    padding:clamp(14px, 4vw, 18px) var(--pad) 0;
    display:flex; flex-direction:column; gap:8px;
}
.as-help p{ font-size:clamp(.62rem, 2vw, .7rem); color:var(--muted); line-height:1.65; }

.as-btn{
    display:flex; align-items:center; justify-content:center; gap:6px;
    width:100%; height:var(--act-h); min-height:34px; padding:0 14px;
    background:rgba(var(--fg),0.1);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:none; border-radius:var(--act-r);
    color:var(--ui-text); font-size:.74rem; font-weight:600; white-space:nowrap;
    transition:background .2s, transform .15s, opacity .2s;
}
.as-btn:hover{ background:rgba(var(--fg),0.2); }
.as-btn:active{ transform:scale(.98); }
.as-btn[disabled]{ opacity:.55; pointer-events:none; }
.as-btn.primary{ background:var(--red); color:#fff; }
.as-btn.primary:hover{ background:#ff4a4a; }
@media (max-width:490px){ .as-btn{ font-size:.68rem; } }

/* ── Support form ── */
.as-form{ padding:clamp(12px, 3.6vw, 16px) var(--pad) 0; display:flex; flex-direction:column; gap:12px; }
.as-field label{
    display:block; margin-bottom:5px;
    font-size:.58rem; font-weight:600; text-transform:uppercase; letter-spacing:.07em; color:var(--muted);
}
.as-input, .as-select, .as-textarea{
    width:100%; background:var(--surface);
    border:1px solid var(--line2); border-radius:var(--r);
    color:var(--ui-text); font-size:.76rem; outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
.as-input, .as-select{ height:var(--ctl-h); padding:0 12px; }
.as-textarea{ padding:10px 12px; min-height:110px; resize:vertical; line-height:1.6; }
.as-select{
    background-image:linear-gradient(45deg, transparent 50%, rgba(var(--fg),.6) 50%), linear-gradient(135deg, rgba(var(--fg),.6) 50%, transparent 50%);
    background-position:calc(100% - 17px) 50%, calc(100% - 12px) 50%;
    background-size:5px 5px, 5px 5px; background-repeat:no-repeat;
    padding-right:30px;
}
.as-select option{ background:var(--solid); color:var(--ui-text); }
.as-input::placeholder, .as-textarea::placeholder{ color:var(--muted); }
.as-input:focus, .as-select:focus, .as-textarea:focus{
    border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07);
    box-shadow:0 0 0 3px rgba(255,45,45,.12);
}
.as-counter{ margin-top:4px; text-align:right; font-size:.58rem; color:var(--muted); }
.as-err{ display:none; font-size:.68rem; color:var(--red); line-height:1.5; }
.as-err.show{ display:block; }

@media (min-width:600px){
    .as-overlay{ align-items:center; padding:20px; }
    .as-sheet{ border-radius:8px; border-bottom:1px solid var(--line); max-width:420px; }
    .as-sheet::before{ display:none; }
}
@media (max-width:490px){
    .as-sheet-title{ font-size:.82rem; }
    .as-input, .as-select, .as-textarea{ font-size:.7rem; }
}

/* Toast (stays dark with green text in both themes, like the community page toast) */
#as-toast{
    position:fixed; bottom:88px; left:50%;
    transform:translateX(-50%) translateY(12px);
    background:rgba(8,8,22,.97); border:1px solid rgba(74,222,128,.3);
    color:#4ade80; font-size:.74rem; font-weight:500;
    padding:9px 18px; border-radius:var(--r);
    opacity:0; pointer-events:none;
    transition:opacity .22s ease, transform .22s ease;
    z-index:9999999; white-space:nowrap;
    box-shadow:0 8px 24px rgba(0,0,0,.6);
}
#as-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

/* ══════════════════════════════════════
   SKELETON — same lane, same lines
══════════════════════════════════════ */
#page-skeleton{
    position:fixed; inset:0; z-index:9999;
    background:var(--bg); background-attachment:fixed;
    display:flex; justify-content:center;
    opacity:1; transition:opacity .35s ease; pointer-events:none;
}
#page-skeleton.hide{ opacity:0; }
.sk-lane{ width:100%; max-width:var(--max-w); display:flex; flex-direction:column; }
@media (min-width:801px){ .sk-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .sk-lane{ max-width:100%; } }
.sk{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent);
    transform:translateX(-100%); animation:skShimmer 1.5s infinite;
}
@keyframes skShimmer{ 100%{ transform:translateX(100%); } }
.sk-topbar{ height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; padding:0 16px 0 14px; border-bottom:1px solid rgba(var(--fg),0.14); }
.sk-back{ width:20px; height:20px; }
.sk-title{ width:120px; height:14px; }
.sk-hero{ display:flex; flex-direction:column; align-items:center; gap:10px; padding:24px 0 22px; }
.sk-circle{ width:clamp(72px,21vw,98px); height:clamp(72px,21vw,98px); border-radius:50%; }
.sk-h1{ width:130px; height:14px; margin-top:6px; }
.sk-h2{ width:150px; height:26px; border-radius:4px; }
.sk-p{ width:min(260px,64%); height:10px; }
.sk-stats{ display:grid; grid-template-columns:repeat(3,1fr); border-top:1px solid var(--line); border-bottom:1px solid var(--line); padding:16px 0; }
.sk-stat{ width:40px; height:14px; margin:0 auto; }
.sk-row{ display:flex; align-items:center; gap:14px; padding:14px var(--pad); border-bottom:1px solid var(--line); }
.sk-row-i{ width:36px; height:36px; border-radius:6px; flex-shrink:0; }
.sk-row-t{ width:150px; height:12px; }
.sk-row-s{ width:100px; height:9px; margin-top:7px; }
.sk-row-p{ width:52px; height:20px; margin-left:auto; border-radius:4px; }

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same switch + same palette as community.php and the other pages. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --solid:#ffffff;
    --sheet:#ffffff;
    --ph:#eceef5;
    --overlay:rgba(6,7,26,0.45);
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
    --red:#e62020;
    /* deeper status colours so they stay readable on white */
    --green:#16a34a;
    --amber:#d97706;
    --blue:#2563eb;
}
[data-theme="light"] .as-sheet{ box-shadow:0 -8px 30px rgba(6,7,26,.12); }
@media (min-width:600px){ [data-theme="light"] .as-sheet{ box-shadow:0 12px 40px rgba(6,7,26,.18); } }
[data-theme="light"] .as-input:focus,
[data-theme="light"] .as-select:focus,
[data-theme="light"] .as-textarea:focus{ border-color:rgba(230,32,32,.7); box-shadow:0 0 0 3px rgba(230,32,32,.12); }
/* verified badge stays red in light mode too (name text turns dark, the badge does not) */
[data-theme="light"] .as-name .vbadge{ color:var(--red) !important; }
</style>
</head>
<body>

<?php echo dz_badge_sprite(); ?>

<!-- ══ SKELETON ══ -->
<div id="page-skeleton">
    <div class="sk-lane">
        <div class="sk-topbar">
            <div class="sk sk-back"></div>
            <div class="sk sk-title"></div>
        </div>
        <div class="sk-hero">
            <div class="sk sk-circle"></div>
            <div class="sk sk-h1"></div>
            <div class="sk sk-h2"></div>
            <div class="sk sk-p"></div>
        </div>
        <div class="sk-stats">
            <div class="sk sk-stat"></div><div class="sk sk-stat"></div><div class="sk sk-stat"></div>
        </div>
        <?php for($i=0;$i<6;$i++): ?>
        <div class="sk-row">
            <div class="sk sk-row-i"></div>
            <div>
                <div class="sk sk-row-t"></div>
                <div class="sk sk-row-s"></div>
            </div>
            <div class="sk sk-row-p"></div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<div class="ac-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="acClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="ac-title">Account Status</span>
    </div>

    <!-- ══ HERO ══ -->
    <div class="as-hero st-<?php echo h($overall); ?>">
        <div class="as-avatar-wrap">
            <img class="as-avatar" src="<?php echo h($pic); ?>" onerror="this.src='uploads/me.png'" alt="Profile picture">
            <span class="as-badge"><?php echo st_icon($overall); ?></span>
        </div>
        <div class="as-name"><span class="as-nm"><?php echo h($uname); ?></span><?php echo !empty($me['is_verified']) ? dz_badge('f') : ''; ?></div>
        <div class="as-eyebrow"><?php echo h($me['user_rank'] ?: 'Regular'); ?> · Account status</div>
        <div class="as-pill-hero"><?php echo h($HERO[0]); ?></div>
        <p class="as-subline"><?php echo h($HERO[1]); ?></p>
        <div class="as-meter">
            <?php foreach($ROWS as $r): ?>
            <span class="as-seg st-<?php echo h($r['state']); ?>" title="<?php echo h($r['title']); ?>"></span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ STATS ══ -->
    <div class="stats-bar">
        <div class="stat-item">
            <span class="stat-num"><?php echo number_format($clean_days); ?></span>
            <span class="stat-lbl">Days in good standing</span>
        </div>
        <div class="stat-item">
            <span class="stat-num <?php echo $removed_count ? 'c-bad' : ''; ?>"><?php echo (int)$removed_count; ?></span>
            <span class="stat-lbl">Removed</span>
        </div>
        <div class="stat-item">
            <span class="stat-num <?php echo ($review_count + $acct_review) ? 'c-info' : ''; ?>"><?php echo (int)($review_count + $acct_review); ?></span>
            <span class="stat-lbl">Under review</span>
        </div>
    </div>

    <!-- ══ STANDING ══ -->
    <div class="section-label">Your standing</div>
    <div class="info-list">
        <?php foreach($ROWS as $i => $r): ?>
        <?php if($i > 0): ?><hr class="divider"><?php endif; ?>
        <button type="button" class="sc-row st-<?php echo h($r['state']); ?>" onclick="openDetail('<?php echo h($r['key']); ?>')">
            <span class="sc-icon"><i class="<?php echo h($r['icon']); ?>"></i></span>
            <span class="sc-main">
                <span class="sc-label" style="display:block"><?php echo h($r['title']); ?></span>
                <span class="sc-value" style="display:block"><?php echo h($r['sub']); ?></span>
            </span>
            <span class="sc-pill"><?php echo h($PILL[$r['state']]); ?></span>
            <i class="ri-arrow-right-s-line chevron"></i>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ══ RECENT ACTIVITY ══ -->
    <div class="section-label">Recent activity</div>
    <hr class="divider" style="margin-bottom:12px">
    <div class="tl">
        <?php foreach($EV as $e): ?>
        <div class="tl-item st-<?php echo h($e['level']); ?>">
            <span class="tl-dot"><i class="<?php echo h($e['icon']); ?>"></i></span>
            <div class="tl-body">
                <div class="tl-title"><?php echo h($e['title']); ?></div>
                <?php if(!empty($e['text'])): ?><div class="tl-text"><?php echo h($e['text']); ?></div><?php endif; ?>
                <div class="tl-time"><?php echo h(as_date($e['at'])); ?> · <?php echo h(as_ago($e['at'])); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ══ ACCOUNT DETAILS ══ -->
    <div class="section-label">Account details</div>
    <div class="info-list">
        <hr class="divider">
        <div class="info-row">
            <div class="info-icon"><i class="ri-calendar-line"></i></div>
            <div class="info-content">
                <div class="info-label">Member since</div>
                <div class="info-value"><?php echo h(as_date($me['created_at'])); ?></div>
            </div>
        </div>
        <hr class="divider">
        <div class="info-row">
            <div class="info-icon"><i class="ri-mail-line"></i></div>
            <div class="info-content">
                <div class="info-label">Email</div>
                <div class="info-value <?php echo (int)$me['email_verified'] ? 'v-ok' : 'v-warn'; ?>"><?php echo (int)$me['email_verified'] ? 'Verified' : 'Not verified'; ?></div>
            </div>
        </div>
        <hr class="divider">
        <div class="info-row">
            <div class="info-icon"><i class="ri-verified-badge-line"></i></div>
            <div class="info-content">
                <div class="info-label">Verification</div>
                <div class="info-value <?php echo (int)$me['is_verified'] ? 'v-ok' : ''; ?>"><?php echo h($verify_txt); ?></div>
            </div>
        </div>
        <hr class="divider">
        <div class="info-row">
            <div class="info-icon"><i class="ri-lock-line"></i></div>
            <div class="info-content">
                <div class="info-label">Privacy</div>
                <div class="info-value"><?php echo h($privacy_txt); ?></div>
            </div>
        </div>
        <hr class="divider">
        <div class="info-row">
            <div class="info-icon"><i class="ri-shield-user-line"></i></div>
            <div class="info-content">
                <div class="info-label">Parental supervision</div>
                <div class="info-value"><?php echo $family ? 'On' : 'Off'; ?></div>
            </div>
        </div>
        <hr class="divider">
        <div class="info-row">
            <div class="info-icon"><i class="ri-group-line"></i></div>
            <div class="info-content">
                <div class="info-label">Pods joined</div>
                <div class="info-value"><?php echo (int)$pods_joined; ?></div>
            </div>
        </div>
        <hr class="divider">
        <button type="button" class="info-row clickable" onclick="openSupport('')">
            <div class="info-icon"><i class="ri-customer-service-2-line"></i></div>
            <div class="info-content">
                <div class="info-label">Think something is wrong?</div>
                <div class="info-value">Get support</div>
            </div>
            <i class="ri-arrow-right-s-line chevron"></i>
        </button>
        <hr class="divider">
    </div>

    <div class="mutual-notes">
        <div class="mutual-note"><strong>Account Status</strong> only shows decisions that have been reviewed. Reports that are still waiting for review, or that were dismissed, are never shown here.</div>
    </div>

</div><!-- /.ac-lane -->

<!-- ══ DETAIL MODAL ══ -->
<div class="as-overlay" id="detailModal" onclick="if(event.target===this)closeDetail()">
    <div class="as-sheet" id="dSheet" role="dialog" aria-modal="true" aria-labelledby="dTitle">
        <div class="as-sheet-head">
            <span class="as-sheet-title"><span class="as-sheet-badge" id="dBadge"></span><span id="dTitle"></span></span>
            <button type="button" class="as-close" onclick="closeDetail()" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="as-sheet-body">
            <div id="dBody"></div>
            <div class="as-help">
                <p>Think something here is a mistake? Send us a request and we'll take a look.</p>
                <button type="button" class="as-btn" id="dSupportBtn"><i class="ri-customer-service-2-line"></i> Get support</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ SUPPORT MODAL ══ -->
<div class="as-overlay" id="supportModal" onclick="if(event.target===this)closeSupport()">
    <div class="as-sheet" role="dialog" aria-modal="true" aria-labelledby="sTitle">
        <div class="as-sheet-head">
            <span class="as-sheet-title"><i class="ri-customer-service-2-line" style="color:var(--red)"></i><span id="sTitle">Get support</span></span>
            <button type="button" class="as-close" onclick="closeSupport()" aria-label="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="as-sheet-body">
            <form class="as-form" id="supportForm" onsubmit="return submitSupport(event)" autocomplete="off">
                <input type="hidden" name="action" value="support">
                <input type="hidden" name="csrf" value="<?php echo h($_SESSION['as_csrf']); ?>">

                <div class="as-field">
                    <label for="sCat">What do you need help with?</label>
                    <select class="as-select" id="sCat" name="category" required>
                        <option value="" disabled selected>Choose one…</option>
                        <?php foreach($support_categories as $c): ?>
                        <option value="<?php echo h($c); ?>"><?php echo h($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="as-field">
                    <label for="sRef">Link or ID (optional)</label>
                    <input class="as-input" type="text" id="sRef" name="reference" maxlength="255" placeholder="Post link, pod name or anything that helps">
                </div>

                <div class="as-field">
                    <label for="sMsg">Tell us what happened</label>
                    <textarea class="as-textarea" id="sMsg" name="message" maxlength="1000" placeholder="Explain why you think this is a mistake or what you need help with…" required oninput="document.getElementById('sCount').textContent=this.value.length+' / 1000'"></textarea>
                    <div class="as-counter" id="sCount">0 / 1000</div>
                </div>

                <div class="as-err" id="sErr"></div>
                <button type="submit" class="as-btn primary" id="sSubmit">Send request</button>
            </form>
        </div>
    </div>
</div>

<div id="as-toast"><i class="ri-check-line" style="margin-right:5px;"></i><span id="as-toast-text"></span></div>

<script>
var ROWS  = <?php echo json_encode($ROWS,  JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>;
var ICONS = <?php echo json_encode($ICONS, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var PILL  = <?php echo json_encode($PILL,  JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var CATEGORY_FOR = {
    content:  'Removed content or message',
    pods:     'Pod access',
    reach:    'Reach and suggestions',
    audience: 'Audience and age settings',
    earnings: 'Earnings and ads',
    security: 'Verification and security'
};

// ── X: go back, or to the profile if there is no history ──
function acClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php?user_id=<?php echo (int)$user_id; ?>';
}

// ── Skeleton ──────────────────────────────────────────────
(function(){
    var sk = document.getElementById('page-skeleton');
    if(!sk) return;
    var done = false;
    function hide(){
        if(done) return; done = true;
        sk.classList.add('hide');
        setTimeout(function(){ if(sk.parentNode) sk.remove(); }, 380);
    }
    window.addEventListener('load', function(){ setTimeout(hide, 280); });
    setTimeout(hide, 1500);   // safety net
})();

function esc(s){
    var d = document.createElement('div');
    d.textContent = (s === null || s === undefined) ? '' : String(s);
    return d.innerHTML;
}

// ── Detail modal ──────────────────────────────────────────
var currentKey = '';
function openDetail(key){
    var r = null;
    for(var i = 0; i < ROWS.length; i++){ if(ROWS[i].key === key){ r = ROWS[i]; break; } }
    if(!r) return;
    currentKey = key;

    var sheet = document.getElementById('dSheet');
    sheet.className = 'as-sheet st-' + r.state;
    var badge = document.getElementById('dBadge');
    badge.textContent = PILL[r.state] || '';
    document.getElementById('dTitle').textContent = r.title;

    var html = '<p class="as-intro">' + esc(r.intro) + '</p>';
    if(!r.items.length){
        html += '<div class="as-empty">' + ICONS.ok + '<span>' + esc(r.empty) + '</span></div>';
    }else{
        r.items.forEach(function(it){
            html += '<div class="as-item st-' + esc(it.level) + '">' +
                        '<div class="as-item-ic"><i class="' + esc(it.icon) + '"></i></div>' +
                        '<div class="as-item-body">' +
                            '<div class="as-item-title">' + esc(it.title) + '</div>' +
                            (it.text ? '<div class="as-item-text">' + esc(it.text) + '</div>' : '') +
                            (it.meta ? '<div class="as-item-meta">' + esc(it.meta) + '</div>' : '') +
                        '</div>' +
                    '</div>';
        });
    }
    document.getElementById('dBody').innerHTML = html;
    document.getElementById('detailModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDetail(){
    document.getElementById('detailModal').classList.remove('open');
    if(!document.getElementById('supportModal').classList.contains('open')) document.body.style.overflow = '';
}
document.getElementById('dSupportBtn').addEventListener('click', function(){
    var cat = CATEGORY_FOR[currentKey] || '';
    closeDetail();
    openSupport(cat);
});

// ── Support modal ─────────────────────────────────────────
function openSupport(cat){
    var sel = document.getElementById('sCat');
    if(cat) sel.value = cat;
    document.getElementById('sErr').classList.remove('show');
    document.getElementById('supportModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeSupport(){
    document.getElementById('supportModal').classList.remove('open');
    document.body.style.overflow = '';
}
function showToast(msg){
    var t = document.getElementById('as-toast');
    document.getElementById('as-toast-text').textContent = msg;
    t.classList.add('show');
    clearTimeout(window._asToast);
    window._asToast = setTimeout(function(){ t.classList.remove('show'); }, 2800);
}
function submitSupport(e){
    e.preventDefault();
    var form = document.getElementById('supportForm');
    var btn  = document.getElementById('sSubmit');
    var err  = document.getElementById('sErr');
    err.classList.remove('show');

    if(!form.category.value){ err.textContent = 'Please choose what you need help with.'; err.classList.add('show'); return false; }
    if(form.message.value.trim().length < 10){ err.textContent = 'Please tell us a little more (at least 10 characters).'; err.classList.add('show'); return false; }

    btn.disabled = true; btn.textContent = 'Sending…';
    fetch('account_status.php', { method:'POST', body:new FormData(form), credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false; btn.textContent = 'Send request';
            if(d && d.ok){
                form.reset();
                document.getElementById('sCount').textContent = '0 / 1000';
                closeSupport();
                showToast(d.msg);
            }else{
                err.textContent = (d && d.msg) ? d.msg : 'Something went wrong. Please try again.';
                err.classList.add('show');
            }
        })
        .catch(function(){
            btn.disabled = false; btn.textContent = 'Send request';
            err.textContent = 'Network error. Please check your connection and try again.';
            err.classList.add('show');
        });
    return false;
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){ closeDetail(); closeSupport(); }
});
</script>
</body>
</html>