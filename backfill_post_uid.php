<?php
/* ONE-TIME SETUP / UPGRADE: gives every post a permanent public code like TP-7K3Q-9M2X-4H8W-2R5N (16 characters = about 1.2 septillion codes, far beyond 9 quintillion).
   Posts that already have a shorter 8-character code (TP-7K3Q-9M2X) are upgraded to the new format.
   1) adds memes.post_uid (unique) 2) installs a database trigger so EVERY new post (upload page, group pages, remixes…) gets its code automatically
   3) fills in the code for all existing posts (a few thousand per round, reloads itself).
   Open on the XAMPP computer:  http://localhost/Dezzme/backfill_post_uid.php   — back up the database first. Delete this file when it says DONE. */
include 'config.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if(!in_array($ip, ['127.0.0.1', '::1'], true)){ http_response_code(403); exit('Open this only on the computer that runs XAMPP (localhost).'); }
@set_time_limit(0);
echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font:15px Poppins,Arial;background:#0c0c1c;color:#eee;padding:24px;max-width:640px;margin:auto"><h3>Post codes (TP-XXXX-XXXX-XXXX-XXXX)</h3>';

$ALPHA = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   /* no 0 / O / 1 / I: easy to read out loud */
function tp_code($ALPHA){ $s = ''; for($i = 0; $i < 16; $i++) $s .= $ALPHA[random_int(0, 31)]; return 'TP-' . implode('-', str_split($s, 4)); }

/* 1) column + unique index */
$r = $conn->query("SHOW COLUMNS FROM memes LIKE 'post_uid'");
if($r && $r->num_rows === 0){ $conn->query("ALTER TABLE memes ADD COLUMN post_uid VARCHAR(24) NULL DEFAULT NULL"); echo '<div>✔ column added</div>'; }
else { $conn->query("ALTER TABLE memes MODIFY COLUMN post_uid VARCHAR(24) NULL DEFAULT NULL"); }   /* room for the longer code */
$r = $conn->query("SHOW INDEX FROM memes WHERE Key_name='uq_memes_post_uid'");
if($r && $r->num_rows === 0){ $conn->query("CREATE UNIQUE INDEX uq_memes_post_uid ON memes (post_uid)"); echo '<div>✔ unique index added</div>'; }

/* 2) trigger: every new post gets a code, whichever page creates it (tries a few times so a clash can never block a post) */
$conn->query("DROP TRIGGER IF EXISTS memes_post_uid_bi");
$a = "'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'";
$ch = "SUBSTRING($a,FLOOR(1+RAND()*32),1)"; $grp = "$ch,$ch,$ch,$ch";
$gen = "CONCAT('TP-', $grp, '-', $grp, '-', $grp, '-', $grp)";
$ok = $conn->query("CREATE TRIGGER memes_post_uid_bi BEFORE INSERT ON memes FOR EACH ROW
BEGIN
  DECLARE tries INT DEFAULT 0;
  IF NEW.post_uid IS NULL OR NEW.post_uid = '' THEN
    SET NEW.post_uid = $gen;
    WHILE EXISTS (SELECT 1 FROM memes WHERE post_uid = NEW.post_uid) AND tries < 8 DO
      SET NEW.post_uid = $gen; SET tries = tries + 1;
    END WHILE;
  END IF;
END");
echo $ok ? '<div>✔ automatic codes for new posts are on</div>' : '<div>⚠ could not create the trigger (' . htmlspecialchars($conn->error) . '). New posts will not get a code automatically until it is created.</div>';

/* 3) existing posts */
$need = "post_uid IS NULL OR post_uid='' OR CHAR_LENGTH(post_uid) < 24";
$left = (int)$conn->query("SELECT COUNT(*) c FROM memes WHERE $need")->fetch_assoc()['c'];
echo "<p>Posts still without a code: <b>$left</b></p>";
if(!$left) exit('<h2 style="color:#4ade80">DONE ✔</h2>Every post has its code. You can delete backfill_post_uid.php now.');
$q = $conn->query("SELECT id FROM memes WHERE $need LIMIT 3000");
$st = $conn->prepare("UPDATE memes SET post_uid=? WHERE id=? AND ($need)");
$n = 0;
while($row = $q->fetch_assoc()){
    for($try = 0; $try < 6; $try++){
        $c = tp_code($ALPHA); $id = (int)$row['id'];
        try{ $st->bind_param('si', $c, $id); $st->execute(); break; }catch(Throwable $e){ /* clash: try another code */ }
    }
    $n++;
}
echo "<div>✔ gave codes to $n posts this round</div><p>Next round starting… (leave this page open)</p><script>setTimeout(function(){location.reload()},800)</script>";