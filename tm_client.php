<?php
/* tm_client.php — talks to TasteMatch AI (Flask). Never throws; on any problem returns ok=false so callers fall back to PHP. */

if(!defined('TM_BASE_URL'))   define('TM_BASE_URL', 'http://127.0.0.1:5000');
if(!defined('TM_TIMEOUT_MS')) define('TM_TIMEOUT_MS', 800);   /* hard ceiling for one call */

if(!function_exists('tm_fetch')){
    /* returns ['ok'=>bool, 'items'=>[['meme_id','rank','hybrid','content','collab','source','recovery'], …], 'ms'=>float, 'error'=>string] */
    function tm_fetch($user_id, $limit = 30){
        $t0 = microtime(true);
        $out = ['ok' => false, 'items' => [], 'ms' => 0.0, 'error' => ''];
        $user_id = (int)$user_id; $limit = max(1, min(50, (int)$limit));
        if($user_id <= 0){ $out['error'] = 'no user'; return $out; }
        $url = rtrim(TM_BASE_URL, '/') . '/api/recommend/' . $user_id . '?limit=' . $limit;
        $body = false;
        try{
            if(function_exists('curl_init')){
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT_MS => 300,
                    CURLOPT_TIMEOUT_MS => (int)TM_TIMEOUT_MS,
                    CURLOPT_NOSIGNAL => 1,
                ]);
                $body = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if($body === false) $out['error'] = curl_error($ch);
                elseif($code !== 200){ $out['error'] = 'HTTP ' . $code; $body = false; }
                curl_close($ch);
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => TM_TIMEOUT_MS / 1000.0]]);
                $body = @file_get_contents($url, false, $ctx);
                if($body === false) $out['error'] = 'request failed';
            }
        }catch(Throwable $e){ $out['error'] = $e->getMessage(); $body = false; }
        $out['ms'] = round((microtime(true) - $t0) * 1000, 1);
        if($body === false) return $out;
        $j = json_decode($body, true);
        if(!is_array($j) || !isset($j['recommendations']) || !is_array($j['recommendations'])){ $out['error'] = 'bad response'; return $out; }
        $rank = 0;
        foreach($j['recommendations'] as $r){
            $id = (int)($r['meme_id'] ?? ($r['post']['id'] ?? 0));
            if($id <= 0) continue;
            $rank++;
            $out['items'][] = [
                'meme_id' => $id, 'rank' => $rank,
                'hybrid'  => (float)($r['hybrid_score'] ?? 0),
                'content' => (float)($r['content_score'] ?? 0),
                'collab'  => (float)($r['collaborative_score'] ?? 0),
            ];
        }
        $out['ok'] = true;
        return $out;
    }
}