<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? 0;
if(!$user_id){ echo json_encode(['status'=>'error','message'=>'not logged in']); exit; }

$action = $_POST['col_action'] ?? $_GET['col_action'] ?? '';

function svshFetchMyCollections($conn, $user_id, $meme_id = 0){
    $rows = [];
    $stmt = $conn->prepare("
        SELECT c.id, c.name, COALESCE(c.is_public,0) as is_public,
            UNIX_TIMESTAMP(c.created_at) as created_at,
            (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id=c.id) as item_count
        FROM collections c WHERE c.user_id = ? ORDER BY c.created_at DESC
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()){ $r['in_collection'] = false; $rows[] = $r; }

    if($meme_id && !empty($rows)){
        $ids = implode(',', array_map('intval', array_column($rows, 'id')));
        $has = [];
        $r2 = $conn->query("SELECT collection_id FROM collection_items WHERE meme_id=".(int)$meme_id." AND collection_id IN ($ids)");
        if($r2) while($h = $r2->fetch_assoc()) $has[(int)$h['collection_id']] = true;
        foreach($rows as &$r){ if(!empty($has[(int)$r['id']])) $r['in_collection'] = true; }
        unset($r);
    }

    foreach($rows as &$r){
        $r['thumb'] = null;
        $t = $conn->prepare("
            SELECT m.file_name, m.type FROM collection_items ci
            JOIN memes m ON m.id = ci.meme_id
            WHERE ci.collection_id = ? ORDER BY ci.added_at DESC LIMIT 1
        ");
        $t->bind_param('i', $r['id']);
        $t->execute();
        $trow = $t->get_result()->fetch_assoc();
        if($trow){
            $r['thumb'] = ($trow['type']==='text' || empty($trow['file_name']) || $trow['file_name']==='0')
                ? 'uploads/Text.jpg' : 'uploads/'.$trow['file_name'];
        }
    }
    unset($r);
    return $rows;
}

switch($action){

    case 'get_my_collections': {
        $meme_id = (int)($_POST['meme_id'] ?? $_GET['meme_id'] ?? 0);
        $collections = svshFetchMyCollections($conn, $user_id, $meme_id);

        $is_faved = false;
        if($meme_id){
            $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id=? AND meme_id=?");
            $stmt->bind_param('ii', $user_id, $meme_id);
            $stmt->execute();
            $is_faved = $stmt->get_result()->num_rows > 0;
        }
        echo json_encode(['status'=>'ok','collections'=>$collections,'is_faved'=>$is_faved]);
        break;
    }

    case 'toggle_favorite': {
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if(!$meme_id){ echo json_encode(['status'=>'error']); exit; }

        $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id=? AND meme_id=?");
        $stmt->bind_param('ii', $user_id, $meme_id);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;

        if($exists){
            $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id=? AND meme_id=?");
            $stmt->bind_param('ii', $user_id, $meme_id); $stmt->execute();

            $stmt = $conn->prepare("DELETE FROM user_interactions WHERE user_id=? AND meme_id=? AND interaction_type='favorite'");
            $stmt->bind_param('ii', $user_id, $meme_id); $stmt->execute();

            $faved = false;
        } else {
            $stmt = $conn->prepare("INSERT INTO favorites (user_id,meme_id) VALUES (?,?)");
            $stmt->bind_param('ii', $user_id, $meme_id); $stmt->execute();

            /* FIX: was a plain INSERT — if a row for this
               (user_id, meme_id, 'favorite') already existed (e.g. from a
               prior save/unsave cycle), the unique constraint on
               user_interactions threw a duplicate-entry error here. Since
               mysqli throws exceptions on DB errors by default in PHP 8.1+,
               that exception aborted the script before the json_encode()
               below ever ran — so the favorites row above was written
               successfully (hence "shows saved after refresh") but the
               response body was a broken/empty non-JSON payload, which made
               the frontend's r.json() throw and revert the UI optimistically.
               INSERT IGNORE makes a duplicate hit a no-op instead of a fatal
               error, so execution always reaches the response below. */
            $stmt = $conn->prepare("INSERT IGNORE INTO user_interactions (user_id, meme_id, interaction_type) VALUES (?,?,'favorite')");
            $stmt->bind_param('ii', $user_id, $meme_id); $stmt->execute();

            $faved = true;
        }

        $count = $conn->query("SELECT COUNT(*) c FROM favorites WHERE meme_id=".(int)$meme_id)->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','faved'=>$faved,'count'=>(int)$count]);
        break;
    }

    case 'create_collection': {
        $name      = trim($_POST['name'] ?? '');
        $is_public = !empty($_POST['is_public']) ? 1 : 0;
        $meme_id   = (int)($_POST['meme_id'] ?? 0);
        if($name === ''){ echo json_encode(['status'=>'error','message'=>'Name required']); exit; }

        $stmt = $conn->prepare("INSERT INTO collections (user_id, name, is_public, created_at) VALUES (?,?,?,NOW())");
        $stmt->bind_param('isi', $user_id, $name, $is_public);
        $stmt->execute();
        $col_id = $conn->insert_id;

        if($col_id && $meme_id){
            $stmt = $conn->prepare("INSERT IGNORE INTO collection_items (collection_id, meme_id, added_at) VALUES (?,?,NOW())");
            $stmt->bind_param('ii', $col_id, $meme_id);
            $stmt->execute();
        }
        echo json_encode(['status'=>'ok','id'=>$col_id,'name'=>$name,'is_public'=>$is_public]);
        break;
    }

    case 'add_to_collection': {
        $col_id  = (int)($_POST['col_id'] ?? 0);
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if(!$col_id || !$meme_id){ echo json_encode(['status'=>'error']); exit; }

        $own = $conn->prepare("SELECT id FROM collections WHERE id=? AND user_id=?");
        $own->bind_param('ii', $col_id, $user_id);
        $own->execute();
        if(!$own->get_result()->num_rows){ echo json_encode(['status'=>'error','message'=>'Not your collection']); exit; }

        $check = $conn->prepare("SELECT id FROM collection_items WHERE collection_id=? AND meme_id=?");
        $check->bind_param('ii', $col_id, $meme_id);
        $check->execute();
        if($check->get_result()->num_rows){ echo json_encode(['status'=>'already']); exit; }

        $stmt = $conn->prepare("INSERT INTO collection_items (collection_id, meme_id, added_at) VALUES (?,?,NOW())");
        $stmt->bind_param('ii', $col_id, $meme_id);
        $stmt->execute();

        $count = $conn->query("SELECT COUNT(*) c FROM collection_items WHERE collection_id=".$col_id)->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','item_count'=>(int)$count]);
        break;
    }

    case 'remove_from_collection': {
        $col_id  = (int)($_POST['col_id'] ?? 0);
        $meme_id = (int)($_POST['meme_id'] ?? 0);
        if(!$col_id || !$meme_id){ echo json_encode(['status'=>'error']); exit; }

        $own = $conn->prepare("SELECT id FROM collections WHERE id=? AND user_id=?");
        $own->bind_param('ii', $col_id, $user_id);
        $own->execute();
        if(!$own->get_result()->num_rows){ echo json_encode(['status'=>'error','message'=>'Not your collection']); exit; }

        $stmt = $conn->prepare("DELETE FROM collection_items WHERE collection_id=? AND meme_id=?");
        $stmt->bind_param('ii', $col_id, $meme_id);
        $stmt->execute();

        $count = $conn->query("SELECT COUNT(*) c FROM collection_items WHERE collection_id=".$col_id)->fetch_assoc()['c'];
        echo json_encode(['status'=>'ok','item_count'=>(int)$count]);
        break;
    }

    default:
        echo json_encode(['status'=>'error','message'=>'unknown action']);
}