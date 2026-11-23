<?php
/* api_memepedia_extra.php
   Gallery · edit diffs · edit thumbs-up for memepedia.php.
   Put it next to api_articles.php. It creates its own tables on first use. */
session_start();
include 'config.php';
header('Content-Type: application/json; charset=utf-8');

function out($a, $code = 200){ http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) out(['error' => 'Please log in'], 401);
$conn->set_charset('utf8mb4');

$conn->query("CREATE TABLE IF NOT EXISTS mp_gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    user_id INT NOT NULL,
    url VARCHAR(500) NOT NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'image',
    caption VARCHAR(160) NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS mp_edit_diffs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    edit_key VARCHAR(64) NOT NULL,
    user_id INT NOT NULL,
    diff MEDIUMTEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_edit (article_id, edit_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS mp_edit_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_id INT NOT NULL,
    edit_key VARCHAR(64) NOT NULL,
    user_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vote (article_id, edit_key, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];

/* ── detail: gallery + diffs + votes for one entry ── */
if ($action === 'detail') {
    $aid = (int)($_GET['id'] ?? 0);
    if (!$aid) out(['error' => 'Missing entry'], 400);

    $gallery = [];
    $st = $conn->prepare("SELECT id, user_id, url, kind, caption FROM mp_gallery WHERE article_id=? ORDER BY id ASC");
    $st->bind_param('i', $aid); $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) {
        $gallery[] = ['id' => (int)$row['id'], 'url' => $row['url'], 'kind' => $row['kind'],
                      'caption' => $row['caption'], 'mine' => ((int)$row['user_id'] === $uid)];
    }

    $diffs = [];
    $st = $conn->prepare("SELECT edit_key, diff FROM mp_edit_diffs WHERE article_id=?");
    $st->bind_param('i', $aid); $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) { $d = json_decode($row['diff'], true); if (is_array($d)) $diffs[$row['edit_key']] = $d; }

    $votes = [];
    $st = $conn->prepare("SELECT edit_key, COUNT(*) c, SUM(user_id=?) m FROM mp_edit_votes WHERE article_id=? GROUP BY edit_key");
    $st->bind_param('ii', $uid, $aid); $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) $votes[$row['edit_key']] = ['count' => (int)$row['c'], 'mine' => ((int)$row['m'] > 0)];

    out(['gallery' => $gallery, 'diffs' => (object)$diffs, 'votes' => (object)$votes]);
}

/* ── gallery_save: add new items, remove your own ── */
if ($action === 'gallery_save') {
    $aid = (int)($in['article_id'] ?? 0);
    if (!$aid) out(['error' => 'Missing entry'], 400);
    $dir = __DIR__ . '/uploads/memepedia/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    foreach ((array)($in['remove'] ?? []) as $rid) {
        $rid = (int)$rid;
        $st = $conn->prepare("SELECT url FROM mp_gallery WHERE id=? AND user_id=? AND article_id=?");
        $st->bind_param('iii', $rid, $uid, $aid); $st->execute();
        if ($row = $st->get_result()->fetch_assoc()) {
            $del = $conn->prepare("DELETE FROM mp_gallery WHERE id=?");
            $del->bind_param('i', $rid); $del->execute();
            if (strpos($row['url'], 'uploads/memepedia/') === 0) @unlink(__DIR__ . '/' . $row['url']);
        }
    }

    $st = $conn->prepare("SELECT COUNT(*) c FROM mp_gallery WHERE article_id=?");
    $st->bind_param('i', $aid); $st->execute();
    $count = (int)$st->get_result()->fetch_assoc()['c'];

    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/webm' => 'webm'];
    foreach ((array)($in['add'] ?? []) as $item) {
        if ($count >= 12) break;
        if (!preg_match('#^data:((?:image/(?:jpeg|png|gif|webp))|(?:video/(?:mp4|webm)));base64,(.+)$#s', (string)($item['data'] ?? ''), $m)) continue;
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 30 * 1024 * 1024) continue;
        if (strpos($m[1], 'image/') === 0 && @getimagesizefromstring($bin) === false) continue;
        $name = bin2hex(random_bytes(10)) . '.' . $ext[$m[1]];
        if (file_put_contents($dir . $name, $bin) === false) continue;
        $url = 'uploads/memepedia/' . $name;
        $kind = strpos($m[1], 'video/') === 0 ? 'video' : 'image';
        $cap = mb_substr(trim(strip_tags((string)($item['caption'] ?? ''))), 0, 160);
        $ins = $conn->prepare("INSERT INTO mp_gallery (article_id, user_id, url, kind, caption) VALUES (?,?,?,?,?)");
        $ins->bind_param('iisss', $aid, $uid, $url, $kind, $cap); $ins->execute();
        $count++;
    }
    out(['success' => true]);
}

/* ── save_diff: store what an edit changed ── */
if ($action === 'save_diff') {
    $aid = (int)($in['article_id'] ?? 0);
    $key = substr((string)($in['edit_key'] ?? ''), 0, 64);
    $diff = $in['diff'] ?? null;
    if (!$aid || $key === '' || !is_array($diff)) out(['error' => 'Bad request'], 400);
    $json = json_encode($diff, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 200000) out(['error' => 'Diff too large'], 413);
    $st = $conn->prepare("INSERT IGNORE INTO mp_edit_diffs (article_id, edit_key, user_id, diff) VALUES (?,?,?,?)");
    $st->bind_param('isis', $aid, $key, $uid, $json); $st->execute();
    out(['success' => true]);
}

/* ── vote: toggle a thumbs-up on an edit ── */
if ($action === 'vote') {
    $aid = (int)($in['article_id'] ?? 0);
    $key = substr((string)($in['edit_key'] ?? ''), 0, 64);
    if (!$aid || $key === '') out(['error' => 'Bad request'], 400);

    $st = $conn->prepare("SELECT id FROM mp_edit_votes WHERE article_id=? AND edit_key=? AND user_id=?");
    $st->bind_param('isi', $aid, $key, $uid); $st->execute();
    if ($st->get_result()->fetch_assoc()) {
        $q = $conn->prepare("DELETE FROM mp_edit_votes WHERE article_id=? AND edit_key=? AND user_id=?");
        $mine = false;
    } else {
        $q = $conn->prepare("INSERT INTO mp_edit_votes (article_id, edit_key, user_id) VALUES (?,?,?)");
        $mine = true;
    }
    $q->bind_param('isi', $aid, $key, $uid); $q->execute();

    $st = $conn->prepare("SELECT COUNT(*) c FROM mp_edit_votes WHERE article_id=? AND edit_key=?");
    $st->bind_param('is', $aid, $key); $st->execute();
    out(['success' => true, 'count' => (int)$st->get_result()->fetch_assoc()['c'], 'mine' => $mine]);
}

out(['error' => 'Unknown action'], 400);