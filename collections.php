<?php
session_start();
include 'config.php';

$current_user = $_SESSION['user_id'] ?? 0;
if (!$current_user) { echo json_encode(['status'=>'error']); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ── CREATE COLLECTION ── */
if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');
    if (!$name) { echo json_encode(['status'=>'error','message'=>'Name required']); exit; }
    $name = $conn->real_escape_string($name);
    $conn->query("INSERT INTO collections (user_id, name) VALUES ($current_user, '$name')");
    $id = $conn->insert_id;
    echo json_encode(['status'=>'ok','id'=>$id,'name'=>$name]);
    exit;
}

/* ── RENAME COLLECTION ── */
if ($action === 'rename') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$id || !$name) { echo json_encode(['status'=>'error']); exit; }
    $name = $conn->real_escape_string($name);
    $conn->query("UPDATE collections SET name='$name' WHERE id=$id AND user_id=$current_user");
    echo json_encode(['status'=>'ok']);
    exit;
}

/* ── DELETE COLLECTION ── */
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['status'=>'error']); exit; }
    $conn->query("DELETE FROM collection_items WHERE collection_id=$id");
    $conn->query("DELETE FROM collections WHERE id=$id AND user_id=$current_user");
    echo json_encode(['status'=>'ok']);
    exit;
}

/* ── ADD ITEM ── */
if ($action === 'add_item') {
    $col_id  = (int)($_POST['collection_id'] ?? 0);
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    if (!$col_id || !$meme_id) { echo json_encode(['status'=>'error']); exit; }
    $check = $conn->query("SELECT id FROM collections WHERE id=$col_id AND user_id=$current_user");
    if (!$check->num_rows) { echo json_encode(['status'=>'error','message'=>'Not your collection']); exit; }
    $conn->query("INSERT IGNORE INTO collection_items (collection_id, meme_id) VALUES ($col_id, $meme_id)");
    echo json_encode(['status'=>'ok']);
    exit;
}

/* ── REMOVE ITEM ── */
if ($action === 'remove_item') {
    $col_id  = (int)($_POST['collection_id'] ?? 0);
    $meme_id = (int)($_POST['meme_id'] ?? 0);
    $conn->query("DELETE FROM collection_items WHERE collection_id=$col_id AND meme_id=$meme_id");
    echo json_encode(['status'=>'ok']);
    exit;
}

/* ── GET COLLECTIONS (for picker) ── */
if ($action === 'get_collections') {
    $res = $conn->query("SELECT id, name FROM collections WHERE user_id=$current_user ORDER BY created_at DESC");
    $cols = [];
    while ($r = $res->fetch_assoc()) $cols[] = $r;
    echo json_encode(['status'=>'ok','collections'=>$cols]);
    exit;
}

/* ── GET COLLECTION ITEMS ── */
if ($action === 'get_items') {
    $col_id = (int)($_GET['collection_id'] ?? 0);
    $check  = $conn->query("SELECT id FROM collections WHERE id=$col_id AND user_id=$current_user");
    if (!$check->num_rows) { echo json_encode(['status'=>'error']); exit; }
    $res = $conn->query("
        SELECT m.id, m.caption, m.type, m.file_name, m.created_at,
               u.username, u.profile_pic AS uploader_pic, m.uploaded_by,
               (SELECT COUNT(*) FROM likes WHERE meme_id=m.id) AS like_count,
               (SELECT COUNT(*) FROM reposts WHERE meme_id=m.id) AS repost_count,
               (SELECT COUNT(*) FROM favorites WHERE meme_id=m.id) AS fav_count,
               (SELECT COUNT(*) FROM likes WHERE meme_id=m.id AND user_id=$current_user) AS liked,
               (SELECT COUNT(*) FROM reposts WHERE meme_id=m.id AND user_id=$current_user) AS reposted,
               (SELECT COUNT(*) FROM favorites WHERE meme_id=m.id AND user_id=$current_user) AS faved
        FROM collection_items ci
        JOIN memes m ON m.id = ci.meme_id
        JOIN users u ON u.id = m.uploaded_by
        WHERE ci.collection_id = $col_id
        ORDER BY ci.added_at DESC
    ");
    $items = [];
    while ($r = $res->fetch_assoc()) $items[] = $r;
    echo json_encode(['status'=>'ok','items'=>$items]);
    exit;
}

/* ── GET ALL COLLECTIONS WITH COUNTS ── */
if ($action === 'get_all') {
    $res = $conn->query("
        SELECT c.id, c.name, c.created_at,
               COUNT(ci.id) AS item_count,
               (SELECT m.file_name FROM collection_items ci2
                JOIN memes m ON m.id = ci2.meme_id
                WHERE ci2.collection_id = c.id
                ORDER BY ci2.added_at DESC LIMIT 1) AS thumb
        FROM collections c
        LEFT JOIN collection_items ci ON ci.collection_id = c.id
        WHERE c.user_id = $current_user
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ");
    $cols = [];
    while ($r = $res->fetch_assoc()) $cols[] = $r;
    echo json_encode(['status'=>'ok','collections'=>$cols]);
    exit;
}

echo json_encode(['status'=>'error','message'=>'Unknown action']);