<?php
/* returns the permanent TP-code of a post: post_uid.php?id=123  →  {"ok":true,"uid":"TP-7K3Q-9M2X"}  (used by Stats for nerds, reports, moderation tools) */
session_start();
include 'config.php';
header('Content-Type: application/json');
if(empty($_SESSION['user_id'])){ echo json_encode(['ok' => false]); exit; }
$id = (int)($_GET['id'] ?? 0);
if($id <= 0){ echo json_encode(['ok' => false]); exit; }
$r = @$conn->query("SHOW COLUMNS FROM memes LIKE 'post_uid'");
if(!$r || !$r->num_rows){ echo json_encode(['ok' => false]); exit; }
$st = $conn->prepare("SELECT post_uid FROM memes WHERE id=? LIMIT 1");
$st->bind_param('i', $id); $st->execute();
$row = $st->get_result()->fetch_assoc(); $st->close();
echo json_encode(['ok' => !empty($row['post_uid']), 'uid' => $row['post_uid'] ?? '']);