<?php
/* Dezzme thumbnails — small WebP copies of uploaded images so grids and avatars don't download full-size files.
   dz_thumb_url('uploads/abc.jpg', 480)  → 'uploads/thumbs/w480/uploads_abc.jpg.webp' (made on first use), or the original
   path whenever a thumbnail can't be made (no GD/WebP, not a jpg/png, missing file…) so nothing ever breaks.
   Thumbnails are made when a file is uploaded (upload.php) and, for older uploads, the first time they're shown
   (at most 6 per page load, so the first visit never stalls). Delete uploads/thumbs/ any time; it rebuilds itself. */
if (!function_exists('dz_thumb_url')) {
    function dz_thumb_url($rel, $w = 480, $max_new = 6) {
        static $made = 0;
        $rel = (string)$rel;
        if ($rel === '' || strpos($rel, 'uploads/') !== 0 || strpos($rel, '..') !== false || strpos($rel, 'uploads/thumbs/') === 0) return $rel;
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true) || !function_exists('imagewebp')) return $rel;
        $w = max(48, min(1200, (int)$w));
        $dir = __DIR__ . '/uploads/thumbs/w' . $w . '/';
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', substr($rel, 8)) . '.webp';
        $dest = $dir . $name; $url = 'uploads/thumbs/w' . $w . '/' . $name;
        $src = __DIR__ . '/' . $rel;
        if (is_file($dest)) return $url;
        if ($made >= $max_new || !is_file($src) || filesize($src) > 15 * 1024 * 1024) return $rel;
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return $rel;
            $info = @getimagesize($src);
            if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) return $rel;   // unreadable or huge (memory)
            $im = ($ext === 'png') ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
            if (!$im) return $rel;
            if ($ext !== 'png' && function_exists('exif_read_data')) {   // honour phone-camera rotation
                $ex = @exif_read_data($src);
                $o = (int)($ex['Orientation'] ?? 1);
                $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($deg) { $r = @imagerotate($im, $deg, 0); if ($r) { imagedestroy($im); $im = $r; } }
            }
            $sw = imagesx($im); $sh = imagesy($im);
            if ($sw > $w) { $nh = max(1, (int)round($sh * $w / $sw)); $t = imagescale($im, $w, $nh); if ($t) { imagedestroy($im); $im = $t; } }
            if ($ext === 'png') { imagealphablending($im, false); imagesavealpha($im, true); }
            $ok = @imagewebp($im, $dest . '.tmp', 78);
            imagedestroy($im);
            if ($ok && @rename($dest . '.tmp', $dest)) { $made++; return $url; }
            @unlink($dest . '.tmp');
        } catch (Throwable $e) { /* fall through to the original */ }
        return $rel;
    }
}