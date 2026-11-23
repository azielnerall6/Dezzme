<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

/*
 * ── REQUIRED DB MIGRATION (run once) ───────────────────────────────
 *
 * ALTER TABLE meme_articles ADD COLUMN gallery LONGTEXT NULL;
 * ALTER TABLE meme_edits    ADD COLUMN changes LONGTEXT NULL;
 *
 * CREATE TABLE IF NOT EXISTS meme_edit_votes (
 *   id         INT AUTO_INCREMENT PRIMARY KEY,
 *   edit_id    INT NOT NULL,
 *   article_id INT NOT NULL,
 *   voter_key  VARCHAR(80) NOT NULL,   -- 'u<user_id>' or 's<session_id>'
 *   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   UNIQUE KEY uniq_vote (edit_id, voter_key),
 *   KEY idx_article (article_id)
 * );
 *
 * (meme_articles.views and meme_edits.id are assumed to already exist.)
 */

// ── Helper: save base64 media to disk ──────────────────
function saveMedia($base64, $type) {
    if (!$base64) return null;
    $uploadDir = 'uploads/memepedia/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $base64 = preg_replace('/^data:[^;]+;base64,/', '', $base64);
    $bytes  = base64_decode($base64);
    if (!$bytes) return null;

    $ext      = ($type === 'video') ? 'mp4' : 'jpg';
    $filename = $uploadDir . uniqid('meme_', true) . '.' . $ext;
    file_put_contents($filename, $bytes);
    return $filename;
}

// ── Helper: normalize an incoming extra_images object to {1:.., 2:.., 3:..} ──
function normalizeExtraImages($raw) {
    $out = [1 => null, 2 => null, 3 => null];
    if (is_array($raw)) {
        foreach ([1, 2, 3] as $n) {
            $val = $raw[$n] ?? $raw[(string)$n] ?? null;
            if ($val) $out[$n] = $val;
        }
    }
    return $out;
}

// ── Helper: save fresh base64 extra images, keep existing URLs, drop nulls ──
function resolveExtraImages($raw) {
    $normalized = normalizeExtraImages($raw);
    $resolved   = [];
    foreach ($normalized as $n => $val) {
        if (!$val) { $resolved[$n] = null; continue; }
        if (strpos($val, 'data:') === 0) {
            $saved = saveMedia($val, 'image');
            $resolved[$n] = $saved ?: null;
        } else {
            $resolved[$n] = $val;
        }
    }
    return $resolved;
}

// ── Helper: guess media type from a path ───────────────
function guessMediaType($url, $hint = '') {
    if ($hint === 'video' || $hint === 'image') return $hint;
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    return in_array($ext, ['mp4', 'webm', 'mov', 'm4v', 'ogg']) ? 'video' : 'image';
}

// ── Helper: resolve gallery items ──────────────────────
// Input : [{url, media_data, media_type, caption}]
//         url        -> kept item (already saved)
//         media_data -> new base64 upload
// Output: [{url, type, caption}]
function resolveGallery($raw, $max = 24) {
    $out = [];
    if (!is_array($raw)) return $out;

    foreach ($raw as $item) {
        if (!is_array($item)) continue;
        if (count($out) >= $max) break;

        $caption = mb_substr(trim((string)($item['caption'] ?? '')), 0, 300);
        $hint    = $item['media_type'] ?? ($item['type'] ?? '');

        if (!empty($item['media_data'])) {
            $type  = ($hint === 'video') ? 'video' : 'image';
            $saved = saveMedia($item['media_data'], $type);
            if ($saved) {
                $out[] = ['url' => $saved, 'type' => $type, 'caption' => $caption];
            }
        } elseif (!empty($item['url'])) {
            $url   = (string)$item['url'];
            $out[] = ['url' => $url, 'type' => guessMediaType($url, $hint), 'caption' => $caption];
        }
    }
    return $out;
}

// ── Helper: sanitize edit_changes (array of diff entries) ──
function resolveEditChanges($raw) {
    if (!is_array($raw)) return [];
    // Cap size so a bad client can't bloat the table
    $raw = array_slice($raw, 0, 100);
    $json = json_encode($raw);
    if ($json === false || strlen($json) > 200000) return [];
    return $raw;
}

// ── Helper: identify the current voter ─────────────────
function voterKey() {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    return $uid ? ('u' . $uid) : ('s' . session_id());
}

$SEGMENT_KEYS = [
    'origin', 'creator_source', 'how_popular', 'variations', 'platforms',
    'cultural_significance', 'notable_uses', 'sources_references',
    'evolution', 'current_status', 'related_memes'
];

function resolveSegments($raw, $segmentKeys) {
    $out = [];
    foreach ($segmentKeys as $key) {
        $out[$key] = is_array($raw) && isset($raw[$key]) ? trim((string)$raw[$key]) : '';
    }
    return $out;
}

// ── LIST ───────────────────────────────────────────────
if ($action === 'list') {
    $result = $conn->query("SELECT a.*,
            a.views AS view_count,
            (SELECT COUNT(*) FROM meme_comments c WHERE c.article_id = a.id) AS comment_count,
            u.username AS creator_name, u.profile_pic AS creator_avatar
        FROM meme_articles a
        LEFT JOIN users u ON u.id = a.creator_id
        ORDER BY a.created_at DESC");

    if (!$result) {
        echo json_encode(['error' => $conn->error]); exit;
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['views']         = (int)($row['views'] ?? 0);
        $row['view_count']    = (int)($row['view_count'] ?? 0);
        $row['comment_count'] = (int)($row['comment_count'] ?? 0);
        $row['tags']          = json_decode($row['tags'] ?? '[]');
        $row['segments']      = json_decode($row['segments'] ?? '{}', true) ?: new stdClass();
        $row['extra_images']  = json_decode($row['extra_images'] ?? '{}', true) ?: new stdClass();
        $row['gallery']       = json_decode($row['gallery'] ?? '[]', true) ?: [];
        $row['edits']         = [];
        $rows[]               = $row;
    }
    echo json_encode($rows);

// ── GET SINGLE ─────────────────────────────────────────
} elseif ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }

    $row = $conn->query("SELECT a.*, a.views AS view_count,
            u.username AS creator_name, u.profile_pic AS creator_avatar
        FROM meme_articles a
        LEFT JOIN users u ON u.id = a.creator_id
        WHERE a.id = $id")->fetch_assoc();

    if (!$row) { echo json_encode(['error' => 'Not found']); exit; }

    $row['views']        = (int)($row['views'] ?? 0);
    $row['view_count']   = (int)($row['view_count'] ?? 0);
    $row['tags']         = json_decode($row['tags'] ?? '[]');
    $row['segments']     = json_decode($row['segments'] ?? '{}', true) ?: new stdClass();
    $row['extra_images'] = json_decode($row['extra_images'] ?? '{}', true) ?: new stdClass();
    $row['gallery']      = json_decode($row['gallery'] ?? '[]', true) ?: [];

    // Edit history, with vote counts + whether the current visitor voted
    $vk = $conn->real_escape_string(voterKey());
    $edits = [];
    $er = $conn->query("SELECT e.*, e.edit_summary AS summary,
            u.username AS editor_name, u.profile_pic AS editor_avatar,
            (SELECT COUNT(*) FROM meme_edit_votes v WHERE v.edit_id = e.id) AS votes,
            (SELECT COUNT(*) FROM meme_edit_votes v WHERE v.edit_id = e.id AND v.voter_key = '$vk') AS voted
        FROM meme_edits e
        LEFT JOIN users u ON u.id = e.editor_id
        WHERE e.article_id = $id ORDER BY e.edited_at ASC, e.id ASC");

    if ($er) {
        while ($e = $er->fetch_assoc()) {
            $e['id']      = (int)$e['id'];
            $e['changes'] = json_decode($e['changes'] ?? '[]', true) ?: [];
            $e['votes']   = (int)$e['votes'];
            $e['voted']   = ((int)$e['voted']) > 0;
            $edits[] = $e;
        }
    }
    $row['edits'] = $edits;

    // Comments
    $comments = [];
    $cr = $conn->query("SELECT * FROM meme_comments WHERE article_id = $id ORDER BY posted_at ASC");
    while ($c = $cr->fetch_assoc()) $comments[] = $c;
    $row['comments']      = $comments;
    $row['comment_count'] = count($comments);

    echo json_encode($row);

// ── SAVE (new entry) ───────────────────────────────────
} elseif ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    global $SEGMENT_KEYS;
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) { echo json_encode(['error' => 'Invalid JSON']); exit; }

    $title    = $conn->real_escape_string(trim($data['title']    ?? ''));
    $summary  = $conn->real_escape_string(trim($data['summary']  ?? ''));
    $status   = $conn->real_escape_string($data['status']        ?? 'rising');
    $category = $conn->real_escape_string($data['category']      ?? '');
    $year     = $conn->real_escape_string($data['origin_year']   ?? '');
    $platform = $conn->real_escape_string($data['origin_platform'] ?? '');
    $tags     = $conn->real_escape_string(json_encode($data['tags'] ?? []));
    $creator  = (int)($_SESSION['user_id'] ?? 0);

    $segments     = resolveSegments($data['segments'] ?? [], $SEGMENT_KEYS);
    $segmentsJson = $conn->real_escape_string(json_encode($segments));

    $extraImages     = resolveExtraImages($data['extra_images'] ?? []);
    $extraImagesJson = $conn->real_escape_string(json_encode($extraImages));

    $gallery     = resolveGallery($data['gallery'] ?? []);
    $galleryJson = $conn->real_escape_string(json_encode($gallery));

    // Generate unique slug
    $slugBase = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $data['title'] ?? ''), '-'));
    $slug     = $slugBase;
    $suffix   = 1;
    while ($conn->query("SELECT id FROM meme_articles WHERE slug='" . $conn->real_escape_string($slug) . "'")->num_rows > 0) {
        $slug = $slugBase . '-' . $suffix++;
    }
    $slug = $conn->real_escape_string($slug);

    // Cover image is required
    $mediaUrl  = '';
    $mediaType = '';
    if (!empty($data['media_data'])) {
        $saved = saveMedia($data['media_data'], $data['media_type'] ?? 'image');
        if ($saved) {
            $mediaUrl  = $conn->real_escape_string($saved);
            $mediaType = $conn->real_escape_string($data['media_type'] ?? 'image');
        }
    }
    if (!$mediaUrl) {
        echo json_encode(['error' => 'A cover image is required']); exit;
    }

    $ok = $conn->query("INSERT INTO meme_articles
        (slug, title, summary, body, segments, media_url, media_type, extra_images, gallery, status, category,
         origin_year, origin_platform, tags, creator_id, created_by, created_at, updated_at)
        VALUES
        ('$slug','$title','$summary','','$segmentsJson','$mediaUrl','$mediaType','$extraImagesJson','$galleryJson','$status',
         '$category','$year','$platform','$tags',$creator,$creator,NOW(),NOW())");

    if (!$ok) {
        echo json_encode(['error' => $conn->error]); exit;
    }

    echo json_encode(['id' => $conn->insert_id]);

// ── UPDATE (edit everything) ───────────────────────────
} elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    global $SEGMENT_KEYS;
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['id'] ?? 0);
    if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }

    $title    = $conn->real_escape_string(trim($data['title']    ?? ''));
    $summary  = $conn->real_escape_string(trim($data['summary']  ?? ''));
    $status   = $conn->real_escape_string($data['status']        ?? 'rising');
    $category = $conn->real_escape_string($data['category']      ?? '');
    $year     = $conn->real_escape_string($data['origin_year']   ?? '');
    $platform = $conn->real_escape_string($data['origin_platform'] ?? '');
    $tags     = $conn->real_escape_string(json_encode($data['tags'] ?? []));
    $editorId = (int)($_SESSION['user_id'] ?? 0);

    $segments     = resolveSegments($data['segments'] ?? [], $SEGMENT_KEYS);
    $segmentsJson = $conn->real_escape_string(json_encode($segments));

    $extraImages     = resolveExtraImages($data['extra_images'] ?? []);
    $extraImagesJson = $conn->real_escape_string(json_encode($extraImages));

    // Cover image: only touch it if a new one was uploaded.
    $mediaSet = '';
    if (!empty($data['media_data'])) {
        $saved = saveMedia($data['media_data'], $data['media_type'] ?? 'image');
        if ($saved) {
            $mediaUrl  = $conn->real_escape_string($saved);
            $mediaType = $conn->real_escape_string($data['media_type'] ?? 'image');
            $mediaSet  = ", media_url='$mediaUrl', media_type='$mediaType'";
        }
    }

    // Gallery: only replace if the client actually sent the key
    // (kept items have `url`, new items have `media_data`, removed items are simply absent).
    $gallerySet = '';
    if (array_key_exists('gallery', $data)) {
        $gallery     = resolveGallery($data['gallery']);
        $galleryJson = $conn->real_escape_string(json_encode($gallery));
        $gallerySet  = ", gallery='$galleryJson'";
    }

    $ok = $conn->query("UPDATE meme_articles SET
        title='$title', summary='$summary', segments='$segmentsJson', extra_images='$extraImagesJson',
        status='$status', category='$category', origin_year='$year', origin_platform='$platform',
        tags='$tags', edit_count=edit_count+1, updated_at=NOW()
        $mediaSet $gallerySet
        WHERE id=$id");

    if (!$ok) {
        echo json_encode(['error' => $conn->error]); exit;
    }

    // Log the edit (summary + structured diff) for the History tab
    $editSummary = $conn->real_escape_string($data['edit_summary'] ?? 'Updated the entry');
    $changes     = resolveEditChanges($data['edit_changes'] ?? []);
    $changesJson = $conn->real_escape_string(json_encode($changes));

    $conn->query("INSERT INTO meme_edits (article_id, editor_id, old_body, new_body, edit_summary, changes, edited_at)
        VALUES ($id, $editorId, '', '', '$editSummary', '$changesJson', NOW())");
    $editId = (int)$conn->insert_id;

    echo json_encode(['success' => true, 'edit_id' => $editId]);

// ── VOTE ON AN EDIT (toggle) ───────────────────────────
} elseif ($action === 'vote_edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data      = json_decode(file_get_contents('php://input'), true);
    $articleId = (int)($data['article_id'] ?? 0);
    $editId    = (int)($data['edit_id']    ?? 0);
    $editIndex = isset($data['edit_index']) ? (int)$data['edit_index'] : -1;

    if (!$articleId) { echo json_encode(['error' => 'Missing article_id']); exit; }

    // Resolve the edit: prefer edit_id, fall back to its position in the history list
    if ($editId) {
        $chk = $conn->query("SELECT id FROM meme_edits WHERE id=$editId AND article_id=$articleId")->fetch_assoc();
        if (!$chk) { echo json_encode(['error' => 'Edit not found']); exit; }
    } elseif ($editIndex >= 0) {
        $chk = $conn->query("SELECT id FROM meme_edits WHERE article_id=$articleId
            ORDER BY edited_at ASC, id ASC LIMIT $editIndex, 1")->fetch_assoc();
        if (!$chk) { echo json_encode(['error' => 'Edit not found']); exit; }
        $editId = (int)$chk['id'];
    } else {
        echo json_encode(['error' => 'Missing edit_id or edit_index']); exit;
    }

    $vk = $conn->real_escape_string(voterKey());

    $existing = $conn->query("SELECT id FROM meme_edit_votes WHERE edit_id=$editId AND voter_key='$vk'")->fetch_assoc();
    if ($existing) {
        $conn->query("DELETE FROM meme_edit_votes WHERE edit_id=$editId AND voter_key='$vk'");
        $voted = false;
    } else {
        $conn->query("INSERT IGNORE INTO meme_edit_votes (edit_id, article_id, voter_key, created_at)
            VALUES ($editId, $articleId, '$vk', NOW())");
        $voted = true;
    }

    $cnt   = $conn->query("SELECT COUNT(*) AS c FROM meme_edit_votes WHERE edit_id=$editId")->fetch_assoc();
    $votes = (int)($cnt['c'] ?? 0);

    echo json_encode(['success' => true, 'votes' => $votes, 'voted' => $voted]);

// ── INCREMENT VIEWS ────────────────────────────────────
} elseif ($action === 'increment_views' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false]); exit; }
    $conn->query("UPDATE meme_articles SET views=views+1 WHERE id=$id");
    echo json_encode(['success' => true]);

// ── COMMENT ────────────────────────────────────────────
} elseif ($action === 'comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data      = json_decode(file_get_contents('php://input'), true);
    $articleId = (int)($data['article_id'] ?? 0);
    $text      = $conn->real_escape_string(trim($data['text']     ?? ''));
    $username  = $conn->real_escape_string($data['username']      ?? '');
    $avatar    = $conn->real_escape_string($data['avatar']        ?? '');
    $userId    = (int)($_SESSION['user_id'] ?? 0);

    if (!$articleId || !$text) {
        echo json_encode(['error' => 'Missing fields']); exit;
    }

    $ok = $conn->query("INSERT INTO meme_comments (article_id, user_id, username, avatar, text, posted_at)
        VALUES ($articleId, $userId, '$username', '$avatar', '$text', NOW())");

    if (!$ok) {
        echo json_encode(['error' => $conn->error]); exit;
    }

    echo json_encode(['success' => true]);

// ── DELETE ─────────────────────────────────────────────
} elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['id'] ?? 0);
    if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $owner  = $conn->query("SELECT creator_id FROM meme_articles WHERE id=$id")->fetch_assoc();
    if (!$owner) { echo json_encode(['error' => 'Not found']); exit; }
    if ((int)$owner['creator_id'] !== $userId) {
        echo json_encode(['error' => 'Only the creator can delete this entry']); exit;
    }

    $conn->query("DELETE FROM meme_edit_votes WHERE article_id=$id");
    $conn->query("DELETE FROM meme_edits WHERE article_id=$id");
    $conn->query("DELETE FROM meme_comments WHERE article_id=$id");
    $conn->query("DELETE FROM meme_article_tags WHERE article_id=$id");
    $ok = $conn->query("DELETE FROM meme_articles WHERE id=$id");

    if (!$ok) { echo json_encode(['error' => $conn->error]); exit; }
    echo json_encode(['success' => true]);

// ── UNKNOWN ────────────────────────────────────────────
} else {
    echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
}