<?php
/* Dezzme — video quality renditions for Zeems (put next to uploads/). Needs ffmpeg (same one the upload page uses).
   ?a=info&src=uploads/x.mp4          -> which strengths this video can play at
   ?a=make&h=480&src=uploads/x.mp4    -> makes (once, then cached in uploads/q/) the 480p copy and returns its url */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function dz_out($a){ echo json_encode($a); exit; }
function dz_ff(){
    if(!function_exists('exec')) return '';
    foreach(['ffmpeg', 'C:\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\xampp\\ffmpeg\\bin\\ffmpeg.exe', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $c){
        $o = []; $rc = 1; @exec('"' . $c . '" -version 2>&1', $o, $rc);
        if($rc === 0) return $c;
    }
    return '';
}
$LADDER = [144, 240, 360, 480, 720, 1080, 1440];
$a   = $_GET['a'] ?? 'info';
$raw = (string)($_GET['src'] ?? '');
$path = parse_url($raw, PHP_URL_PATH); $name = basename(rawurldecode((string)$path));
if($name === '' || $name === '.' || $name === '..' || !preg_match('/\.(mp4|m4v|mov|webm)$/i', $name)) dz_out(['ok'=>false]);
$dir = __DIR__ . '/uploads/'; $file = $dir . $name;
if(!is_file($file)) dz_out(['ok'=>false]);
$ff = dz_ff(); if($ff === '') dz_out(['ok'=>false, 'err'=>'no ffmpeg']);

$o = []; @exec('"' . $ff . '" -hide_banner -i ' . escapeshellarg($file) . ' 2>&1', $o);
$info = implode("\n", $o);
if(!preg_match('/Video:.*?, (\d{2,5})x(\d{2,5})/', $info, $m)) dz_out(['ok'=>false]);
$short = min((int)$m[1], (int)$m[2]);
$opts = array_values(array_filter($LADDER, function($q) use ($short){ return $q < $short; }));

if($a === 'info') dz_out(['ok'=>true, 'short'=>$short, 'options'=>$opts]);

$h = (int)($_GET['h'] ?? 0);
if(!in_array($h, $opts, true)) dz_out(['ok'=>false]);
$qdir = $dir . 'q/'; if(!is_dir($qdir)) @mkdir($qdir, 0775, true);
$outName = preg_replace('/[^A-Za-z0-9._-]+/', '_', preg_replace('/\.[A-Za-z0-9]+$/', '', $name)) . '_' . substr(md5($name), 0, 6) . '_' . $h . 'p.mp4';
$out = $qdir . $outName;
$url = 'uploads/q/' . rawurlencode($outName);
if(is_file($out) && filesize($out) > 1024) dz_out(['ok'=>true, 'url'=>$url]);

@set_time_limit(300); ignore_user_abort(true);
$lock = @fopen($qdir . $outName . '.lock', 'c');
if($lock) flock($lock, LOCK_EX);                       /* two taps at once make it only once */
if(!(is_file($out) && filesize($out) > 1024)){
    $tmp = $qdir . 'tmp_' . $outName;
    $vf = "scale='if(gt(iw,ih),-2,$h)':'if(gt(iw,ih),$h,-2)'";
    $crf = $h <= 240 ? 29 : ($h <= 480 ? 27 : 25);
    $oo = []; $rc = 1;
    @exec('"' . $ff . '" -y -hide_banner -loglevel error -i ' . escapeshellarg($file) . ' -vf "' . $vf . '" -c:v libx264 -preset veryfast -crf ' . $crf . ' -pix_fmt yuv420p -c:a aac -b:a 96k -ac 2 -movflags +faststart ' . escapeshellarg($tmp) . ' 2>&1', $oo, $rc);
    if($rc === 0 && is_file($tmp) && filesize($tmp) > 1024) @rename($tmp, $out); else @unlink($tmp);
}
if($lock){ flock($lock, LOCK_UN); fclose($lock); @unlink($qdir . $outName . '.lock'); }
if(is_file($out) && filesize($out) > 1024) dz_out(['ok'=>true, 'url'=>$url]);
dz_out(['ok'=>false]);