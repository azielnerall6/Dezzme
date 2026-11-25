<?php
/* Dezzme — ad "info card" for Zeems: a picture + a line + tappable questions above an ad's button.
   Put this file next to upload.php. It is used two ways:
     · included by upload.php  -> dz_ad_faq_save() stores the card when an ad is submitted
     · called by Zeems         -> ad_faq.php?a=get&id=MEME_ID           (read the card)
                                  POST a=ask&id=MEME_ID&i=0             (send question #0 to the ad owner's messages) */
if(!function_exists('dz_ad_faq_ensure')){
    function dz_ad_faq_ensure($conn){
        static $done = false; if($done) return; $done = true;
        $conn->query("CREATE TABLE IF NOT EXISTS ad_faq (meme_id INT NOT NULL PRIMARY KEY, info_pic VARCHAR(255) NULL, info_text VARCHAR(255) NULL, questions TEXT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4");
    }
    function dz_ad_faq_clean($s, $max){
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s);
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return mb_substr($s, 0, $max);
    }
    /* square 320px jpeg copy of the picture (GD), saved in uploads/ — returns the saved path or '' */
    function dz_ad_faq_pic($file){
        if(empty($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 6 * 1024 * 1024) return '';
        $info = @getimagesize($file['tmp_name']); if(!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) return '';
        $dir = __DIR__ . '/uploads/'; if(!is_dir($dir)) @mkdir($dir, 0755, true);
        $name = 'adinfo_' . bin2hex(random_bytes(8)) . '.jpg';
        if(function_exists('imagecreatefromstring') && function_exists('imagejpeg')){
            $src = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
            if($src){
                $w = imagesx($src); $h = imagesy($src); $s = min($w, $h); $T = 320;
                $dst = imagecreatetruecolor($T, $T); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $src, 0, 0, (int)(($w - $s) / 2), (int)(($h - $s) / 2), $T, $T, $s, $s);
                $ok = imagejpeg($dst, $dir . $name, 84); imagedestroy($src); imagedestroy($dst);
                if($ok) return 'uploads/' . $name;
            }
        }
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2]];
        $name = 'adinfo_' . bin2hex(random_bytes(8)) . '.' . $ext;
        return move_uploaded_file($file['tmp_name'], $dir . $name) ? 'uploads/' . $name : '';
    }
    function dz_ad_faq_save($conn, int $memeId, array $post, array $files){
        $text = dz_ad_faq_clean($post['ad_info_text'] ?? '', 90);
        $qs = [];
        foreach ((array)($post['ad_q'] ?? []) as $q){ $q = dz_ad_faq_clean($q, 60); if($q !== '' && count($qs) < 4) $qs[] = $q; }
        $pic = dz_ad_faq_pic($files['ad_info_pic'] ?? null);
        if($text === '' && !$qs && $pic === '') return;
        dz_ad_faq_ensure($conn);
        $j = json_encode($qs, JSON_UNESCAPED_UNICODE);
        $st = $conn->prepare("REPLACE INTO ad_faq (meme_id, info_pic, info_text, questions) VALUES (?,?,?,?)");
        $st->bind_param("isss", $memeId, $pic, $text, $j); $st->execute(); $st->close();
    }
}

if(realpath(__FILE__) === @realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))){
    if(session_status() !== PHP_SESSION_ACTIVE) session_start();
    require_once __DIR__ . '/config.php';
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $out = function($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
    $me = (int)($_SESSION['user_id'] ?? 0);
    $id = (int)($_REQUEST['id'] ?? 0);
    $act = (string)($_REQUEST['a'] ?? 'get');
    if($id <= 0) $out(['ok' => false]);
    dz_ad_faq_ensure($conn);
    $st = $conn->prepare("SELECT f.info_pic, f.info_text, f.questions, m.uploaded_by FROM ad_faq f JOIN memes m ON m.id = f.meme_id WHERE f.meme_id = ? AND m.is_ad = 1 LIMIT 1");
    $st->bind_param("i", $id); $st->execute(); $row = $st->get_result()->fetch_assoc(); $st->close();
    if(!$row) $out(['ok' => false]);
    $qs = json_decode((string)$row['questions'], true); if(!is_array($qs)) $qs = [];

    if($act === 'get'){
        $out(['ok' => true, 'pic' => (string)($row['info_pic'] ?? ''), 'text' => (string)($row['info_text'] ?? ''), 'questions' => array_values($qs), 'owner' => (int)$row['uploaded_by']]);
    }
    if($act === 'ask' && $_SERVER['REQUEST_METHOD'] === 'POST'){
        if($me <= 0) $out(['ok' => false, 'msg' => 'Log in to send a question.']);
        $owner = (int)$row['uploaded_by'];
        if($owner === $me) $out(['ok' => false, 'msg' => 'This is your own ad.']);
        $i = (int)($_POST['i'] ?? -1);
        if(!isset($qs[$i])) $out(['ok' => false]);
        $b = $conn->prepare("SELECT 1 FROM blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1");
        $b->bind_param("iiii", $me, $owner, $owner, $me); $b->execute(); $blocked = (bool)$b->get_result()->fetch_row(); $b->close();
        if($blocked) $out(['ok' => false, 'msg' => "You can't message this account."]);
        $msg = '[adq:' . $id . '] ' . $qs[$i];
        $d = $conn->prepare("SELECT id FROM messages WHERE sender_id=? AND receiver_id=? AND message=? AND created_at > (NOW() - INTERVAL 1 DAY) LIMIT 1");
        $d->bind_param("iis", $me, $owner, $msg); $d->execute(); $dup = (bool)$d->get_result()->fetch_row(); $d->close();
        if($dup) $out(['ok' => true, 'owner' => $owner, 'again' => true]);
        $r = $conn->prepare("SELECT COUNT(*) FROM messages WHERE sender_id=? AND message LIKE '[adq:%' AND created_at > (NOW() - INTERVAL 1 HOUR)");
        $r->bind_param("i", $me); $r->execute(); $n = (int)($r->get_result()->fetch_row()[0] ?? 0); $r->close();
        if($n >= 15) $out(['ok' => false, 'msg' => 'Too many questions. Try again later.']);
        $ins = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, seen, created_at) VALUES (?,?,?,0,NOW())");
        $ins->bind_param("iis", $me, $owner, $msg);
        if(!$ins->execute()) $out(['ok' => false, 'msg' => 'Could not send. Try again.']);
        $out(['ok' => true, 'owner' => $owner]);
    }
    $out(['ok' => false]);
}