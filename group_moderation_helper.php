<?php
/* ══════════════════════════════════════════════════════════════════
   group_moderation_helper.php
   Enforces the Auto-moderation word list and Slow Mode that owners and
   admins set on the group settings page. Include it where a member
   creates a post:  include_once 'group_moderation_helper.php';
   The owner and admins should always be skipped ($is_staff = true).
   ══════════════════════════════════════════════════════════════════ */

function gcm_settings($conn, $group_id) {
    $group_id = (int)$group_id;
    $out = ['words' => [], 'mode' => 'block', 'whole' => 1, 'slow' => 0, 'probation' => 0];
    try {
        $r = $conn->query("SELECT automod_words, automod_action, automod_whole, slow_mode_seconds, probation_posts FROM `groups` WHERE id=$group_id LIMIT 1");
        if (!$r || !($g = $r->fetch_assoc())) return $out;
        $out['words'] = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string)($g['automod_words'] ?? '')), function ($w) { return $w !== ''; }));
        $out['mode']  = (($g['automod_action'] ?? 'block') === 'report') ? 'report' : 'block';
        $out['whole'] = !empty($g['automod_whole']) ? 1 : 0;
        $out['slow']  = (int)($g['slow_mode_seconds'] ?? 0);
        $out['probation'] = (int)($g['probation_posts'] ?? 0);
    } catch (Throwable $e) { /* columns not created yet: moderation is simply off */ }
    return $out;
}

/* Returns the banned word found in $text, or null. */
function gcm_find_word($words, $whole, $text) {
    $text = (string)$text;
    if ($text === '') return null;
    foreach ($words as $w) {
        if ($w === '') continue;
        if ($whole) {
            if (preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($w, '/') . '(?![\p{L}\p{N}_])/iu', $text)) return $w;
        } elseif (mb_stripos($text, $w) !== false) {
            return $w;
        }
    }
    return null;
}

/*
 * Check a caption BEFORE saving the post.
 * Returns ['ok'=>true]                                    -> publish normally
 *         ['ok'=>false,'action'=>'block','msg'=>...]      -> do not publish, show msg
 *         ['ok'=>true,'action'=>'report','word'=>'...']   -> publish, then call gcm_flag_post()
 */
function gcm_check_text($conn, $group_id, $text, $is_staff = false) {
    if ($is_staff) return ['ok' => true];
    $s = gcm_settings($conn, $group_id);
    if (!$s['words']) return ['ok' => true];
    $hit = gcm_find_word($s['words'], $s['whole'], $text);
    if ($hit === null) return ['ok' => true];
    if ($s['mode'] === 'block') {
        return ['ok' => false, 'action' => 'block', 'word' => $hit, 'msg' => 'Your post contains a word this group does not allow.'];
    }
    return ['ok' => true, 'action' => 'report', 'word' => $hit];
}

/* Adds the new post to Reported Posts. Call after the post row exists. Returns true on success. */
function gcm_flag_post($conn, $group_id, $group_post_id, $word) {
    try {
        $reason = 'Auto-moderation';
        $desc   = 'Contains a banned word: ' . $word;
        $st = $conn->prepare("INSERT INTO group_post_reports (group_id, group_post_id, reason, description, status) VALUES (?,?,?,?, 'pending')");
        $st->bind_param("iiss", $group_id, $group_post_id, $reason, $desc);
        $ok = $st->execute(); $st->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false; // your reports table needs another required column (for example the reporter): add it above
    }
}

/* ── Slow mode ── */
function gcm_ensure_throttle($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("CREATE TABLE IF NOT EXISTS group_post_throttle (
        group_id INT NOT NULL, user_id INT NOT NULL, last_post_at INT NOT NULL,
        PRIMARY KEY (group_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/* Returns ['ok'=>true] or ['ok'=>false,'wait'=>seconds,'msg'=>...]. */
function gcm_slowmode_check($conn, $group_id, $user_id, $is_staff = false) {
    if ($is_staff) return ['ok' => true];
    $s = gcm_settings($conn, $group_id);
    if ($s['slow'] <= 0) return ['ok' => true];
    try {
        gcm_ensure_throttle($conn);
        $st = $conn->prepare("SELECT last_post_at FROM group_post_throttle WHERE group_id=? AND user_id=? LIMIT 1");
        $st->bind_param("ii", $group_id, $user_id); $st->execute();
        $row = $st->get_result()->fetch_assoc(); $st->close();
        if ($row) {
            $wait = ((int)$row['last_post_at'] + $s['slow']) - time();
            if ($wait > 0) {
                $m = (int)ceil($wait / 60);
                $txt = $wait < 60 ? $wait . ' second' . ($wait === 1 ? '' : 's') : $m . ' minute' . ($m === 1 ? '' : 's');
                return ['ok' => false, 'wait' => $wait, 'msg' => 'Slow mode is on. You can post again in ' . $txt . '.'];
            }
        }
    } catch (Throwable $e) { /* never block posting because of a throttle error */ }
    return ['ok' => true];
}

/* Call right after a post is saved successfully. */
function gcm_slowmode_record($conn, $group_id, $user_id) {
    try {
        gcm_ensure_throttle($conn);
        $now = time();
        $st = $conn->prepare("INSERT INTO group_post_throttle (group_id, user_id, last_post_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_post_at=VALUES(last_post_at)");
        $st->bind_param("iii", $group_id, $user_id, $now); $st->execute(); $st->close();
    } catch (Throwable $e) { /* ignore */ }
}

/* ── New member probation ──
 * Needs group_posts.is_pending (the settings page adds it). Call this before saving a post:
 *   $pb = gcm_probation_check($conn, $group_id, $user_id, $is_staff);
 *   if (!empty($pb['blocked'])) { show $pb['msg']; stop; }
 *   // save the post, then:
 *   if ($pb['pending']) gcm_mark_pending($conn, $new_group_post_id);  // and tell the member $pb['msg']
 * Everywhere posts are listed for members, add:  AND gp.is_pending = 0
 */
function gcm_probation_check($conn, $group_id, $user_id, $is_staff = false) {
    if ($is_staff) return ['pending' => false];
    $s = gcm_settings($conn, $group_id);
    $n = $s['probation'];
    if ($n <= 0) return ['pending' => false];
    try {
        $st = $conn->prepare("SELECT SUM(is_pending = 0) AS live_c, SUM(is_pending = 1) AS pend_c FROM group_posts WHERE group_id=? AND posted_by=?");
        $st->bind_param("ii", $group_id, $user_id); $st->execute();
        $row = $st->get_result()->fetch_assoc(); $st->close();
        $live = (int)($row['live_c'] ?? 0); $pend = (int)($row['pend_c'] ?? 0);
        if ($live >= $n) return ['pending' => false];
        if ($pend >= $n) return ['pending' => false, 'blocked' => true, 'msg' => 'You already have posts waiting for approval. Please wait for an admin to review them.'];
        return ['pending' => true, 'msg' => 'Your post was sent to the group admins for approval. It will appear once approved.'];
    } catch (Throwable $e) {
        return ['pending' => false]; // never block posting because of a probation error
    }
}

function gcm_mark_pending($conn, $group_post_id) {
    try {
        $st = $conn->prepare("UPDATE group_posts SET is_pending=1 WHERE id=?");
        $st->bind_param("i", $group_post_id); $ok = $st->execute(); $st->close();
        return (bool)$ok;
    } catch (Throwable $e) { return false; }
}