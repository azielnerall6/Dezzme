<?php
/* ================================================================
   dz_tags.php — hashtag extraction + dz_meme_tags sync
   Included by shared_core.php and backfill_meme_tags.php.
   ALSO require it in your upload / edit-post scripts and call:

       dz_sync_meme_tags($conn, $meme_id, $caption);

   right after a post is created or its caption is edited.
================================================================ */

/* '#Gaming #gaming #GameDev' → ['gaming','gamedev']  (lowercase, unique, max 10, order kept) */
if(!function_exists('dz_extract_tags')){
    function dz_extract_tags($caption, $max = 10){
        if(!preg_match_all('/#([a-zA-Z0-9_]{1,50})/', (string)$caption, $m)) return [];
        $out = [];
        foreach($m[1] as $t){
            $t = strtolower($t);
            if(!in_array($t, $out, true)) $out[] = $t;
            if(count($out) >= (int)$max) break;
        }
        return $out;
    }
}

/* rewrite this post's rows in dz_meme_tags from its caption */
if(!function_exists('dz_sync_meme_tags')){
    function dz_sync_meme_tags($conn, $meme_id, $caption){
        $meme_id = (int)$meme_id;
        if($meme_id <= 0) return;
        try{
            $tags = dz_extract_tags($caption);
            $conn->query("DELETE FROM dz_meme_tags WHERE meme_id = $meme_id");
            if(!$tags) return;
            $stmt = $conn->prepare("INSERT IGNORE INTO dz_meme_tags (meme_id, tag, position) VALUES (?, ?, ?)");
            foreach($tags as $i => $t){
                $pos = (int)$i;
                $stmt->bind_param("isi", $meme_id, $t, $pos);
                $stmt->execute();
            }
            $stmt->close();
        }catch(Throwable $e){}
    }
}

/* safety net: tag any recent post that has a '#' but no dz_meme_tags rows yet
   (covers upload scripts you haven't updated). Cheap; shared_core runs it on ~10% of page loads. */
if(!function_exists('dz_heal_tags')){
    function dz_heal_tags($conn, $hours = 48, $limit = 200){
        $hours = (int)$hours; $limit = (int)$limit;
        try{
            $r = $conn->query("
                SELECT m.id, m.caption FROM memes m
                WHERE m.created_at >= (NOW() - INTERVAL $hours HOUR)
                  AND m.caption LIKE '%#%'
                  AND NOT EXISTS (SELECT 1 FROM dz_meme_tags t WHERE t.meme_id = m.id)
                LIMIT $limit
            ");
            if($r) while($row = $r->fetch_assoc()) dz_sync_meme_tags($conn, (int)$row['id'], $row['caption']);
        }catch(Throwable $e){}
    }
}