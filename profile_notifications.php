<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once 'config.php';
require_once __DIR__ . '/dz_collab.php';   /* post collaboration invites */
dz_collab_ensure($conn);

$current_user_id = $_SESSION['user_id'] ?? null;
$page_user_id    = $_GET['user_id'] ?? $current_user_id;

/* true = adds a created_at column to pod_poll_votes / pod_reactions once so poll votes & reactions can notify */
$NOTIF_AUTO_MIGRATE = true;

/* ════════════════════════════════════════════════════════════════
 *  HELPERS  (all defined once, guarded so double-includes are safe)
 * ════════════════════════════════════════════════════════════════ */

if (!function_exists('timeAgoNotif')) {
    function timeAgoNotif($datetime) {
        if (empty($datetime)) return 'recently';
        try {
            $now  = new DateTime();
            $past = new DateTime($datetime);
            if (!$now->diff($past)->invert) return 'just now';   // future / same instant
            $diff = $now->diff($past);
            if ($diff->days >= 7) return floor($diff->days / 7) . 'w ago';
            if ($diff->days > 0)  return $diff->days . 'd ago';
            if ($diff->h > 0)     return $diff->h . 'h ago';
            if ($diff->i > 0)     return $diff->i . 'm ago';
            return 'just now';
        } catch (Exception $e) { return 'recently'; }
    }
}

/* Builds one branch of the big UNION. Every branch returns the same 8 columns. */
if (!function_exists('notifBranch')) {
    function notifBranch(string $type, string $ts, string $msg, string $from, string $ref = '0',
                         string $group = '', string $read = '0', string $actor = 'u.id',
                         string $uname = 'u.username', string $pic = 'u.profile_pic'): string {
        return "(SELECT '{$type}' AS type, CONVERT({$ts}, CHAR) AS created_at, TIMESTAMPDIFF(SECOND, {$ts}, NOW()) AS age_sec, {$actor} AS actor_id, "
             . "CONVERT({$uname} USING utf8mb4) AS username, CONVERT({$pic} USING utf8mb4) AS profile_pic, "
             . "CONVERT({$msg} USING utf8mb4) AS message, {$ref} AS ref_id, {$read} AS is_read "
             . "{$from} {$group})";
    }
}

/* "Leah liked 3 of your posts" / "Leah liked your post" – used with GROUP BY actor */
if (!function_exists('notifCnt')) {
    function notifCnt(string $pre, string $suf, string $single): string {
        return "CONCAT(u.username, IF(COUNT(*) > 1, CONCAT('{$pre}', COUNT(*), '{$suf}'), '{$single}'))";
    }
}

/* Same as notifCnt, but ends with the Pod's name: "Leah posted 3 times in Funny memes" (needs `groups` aliased g) */
if (!function_exists('notifCntG')) {
    function notifCntG(string $pre, string $mid, string $single): string {
        return "CONCAT(u.username, IF(COUNT(*) > 1, CONCAT('{$pre}', COUNT(*), '{$mid}'), '{$single}'), g.title)";
    }
}

/* Every notification source, returned as an ARRAY of UNION branches.
 * (notifUnionSql below validates each branch and joins the working ones.)
 * All placeholders are the current user id. */
if (!function_exists('notifBranches')) {
    function notifBranches(string $m, array $opt = []): array {
        $G   = 'GROUP BY u.id, u.username, u.profile_pic';
        $me  = '(SELECT username FROM users WHERE id = ?)';
        $inv = "COALESCE(n.ref_id, (SELECT g.id FROM `groups` g WHERE TRIM(SUBSTRING(n.message, LOCATE('Pod: ', n.message) + 5)) = TRIM(g.title) LIMIT 1), 0)";
        $vibeOn = 'COALESCE((SELECT vibe_check_enabled FROM user_settings WHERE user_id = ?), 1) = 1';
        $b = [];

        /* ── Posts / engagement (one row per person, most recent action) ── */
        $b[] = notifBranch('like', 'MAX(l.created_at)', notifCnt(' liked ', ' of your posts', ' liked your post'),
            "FROM likes l JOIN users u ON u.id = l.user_id
             WHERE l.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?) AND l.user_id != ? AND DATE(l.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('repost', 'MAX(r.created_at)', notifCnt(' reposted ', ' of your memes', ' reposted your meme'),
            "FROM reposts r JOIN users u ON u.id = r.user_id
             WHERE r.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?) AND r.user_id != ? AND DATE(r.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('favorite', 'MAX(f.created_at)', notifCnt(' saved ', ' of your memes', ' saved your meme'),
            "FROM favorites f JOIN users u ON u.id = f.user_id
             WHERE f.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?) AND f.user_id != ? AND DATE(f.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('download', 'MAX(d.downloaded_at)', notifCnt(' downloaded ', ' of your memes', ' downloaded your meme'),
            "FROM downloads d JOIN users u ON u.id = d.user_id
             WHERE d.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?) AND d.user_id != ? AND DATE(d.downloaded_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('comment', 'MAX(c.created_at)', notifCnt(' commented ', ' times on your posts', ' commented on your post'),
            "FROM comments c JOIN memes mm ON mm.id = c.meme_id JOIN users u ON u.id = c.user_id
             WHERE mm.uploaded_by = ? AND c.user_id != ? AND DATE(c.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('remix', 'r.created_at', "CONCAT(u.username, ' remixed your meme')",
            "FROM remix r JOIN users u ON u.id = r.user_id
             WHERE r.root_id IN (SELECT id FROM memes WHERE uploaded_by = ?) AND r.user_id != ? AND DATE(r.created_at) = CURDATE()");

        $b[] = notifBranch('collection', 'MAX(ci.added_at)', notifCnt(' added ', ' of your memes to collections', ' added your meme to a collection'),
            "FROM collection_items ci JOIN collections c ON c.id = ci.collection_id JOIN users u ON u.id = c.user_id
             WHERE ci.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?) AND c.user_id != ? AND c.is_public = 1 AND DATE(ci.added_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('mention', 'MAX(mm.created_at)', "CONCAT(u.username, ' mentioned you in a post')",
            "FROM memes mm JOIN users u ON u.id = mm.uploaded_by
             WHERE mm.uploaded_by != ? AND DATE(mm.created_at) = CURDATE() AND mm.caption LIKE CONCAT('%@', {$me}, '%')", '0', $G);

        $b[] = notifBranch('mention', 'MAX(hmd.created_at)', "CONCAT(u.username, ' mentioned you in a highlight')",
            "FROM highlight_media hmd JOIN users u ON u.id = hmd.user_id
             WHERE hmd.user_id != ? AND DATE(hmd.created_at) = CURDATE() AND hmd.caption LIKE CONCAT('%@', {$me}, '%')", '0', $G);

        $b[] = notifBranch('mention', 'MAX(gc.created_at)', "CONCAT(u.username, ' mentioned you in a comment')",
            "FROM group_highlight_comments gc JOIN users u ON u.id = gc.user_id
             WHERE gc.user_id != ? AND DATE(gc.created_at) = CURDATE() AND gc.group_id NOT IN ({$m}) AND gc.body LIKE CONCAT('%@', {$me}, '%')
               AND NOT EXISTS (SELECT 1 FROM group_highlight_comments pc WHERE pc.id = gc.parent_id AND pc.user_id = ?)
               AND NOT EXISTS (SELECT 1 FROM group_highlights gh WHERE gh.id = gc.highlight_id AND gh.created_by = ?)",
            'gc.group_id', 'GROUP BY u.id, u.username, u.profile_pic, gc.group_id');

        /* ── Profile & people ── */
        $b[] = notifBranch('follow', 'f.created_at', "CONCAT(u.username, ' started Tracking you')",
            "FROM followers f JOIN users u ON u.id = f.follower_id
             WHERE f.following_id = ? AND f.follower_id != ? AND DATE(f.created_at) = CURDATE()");

        /* ── Post collaboration ──
           an invite stays in the bell until you accept or decline it (not just today); the poster hears about acceptances today */
        $b[] = notifBranch('collab_invite', 'mc.created_at', "CONCAT(u.username, ' invited you to collaborate on a post')",
            "FROM meme_collaborators mc JOIN users u ON u.id = mc.invited_by
             JOIN memes cm ON cm.id = mc.meme_id AND cm.is_archived = 0
             WHERE mc.user_id = ? AND mc.status = 'pending' AND mc.invited_by != ?", 'mc.meme_id');
        $b[] = notifBranch('collab_accepted', 'mc.responded_at', "CONCAT(u.username, ' accepted your collaboration invite')",
            "FROM meme_collaborators mc JOIN users u ON u.id = mc.user_id JOIN memes cm ON cm.id = mc.meme_id
             WHERE cm.uploaded_by = ? AND mc.user_id != ? AND mc.status = 'accepted' AND DATE(mc.responded_at) = CURDATE()", 'mc.meme_id');

        /* Same person visiting 5 times today = ONE row, showing their latest visit */
        $b[] = notifBranch('view', 'MAX(pv.viewed_at)', "CONCAT(u.username, ' viewed your profile')",
            "FROM profile_views pv JOIN users u ON u.id = pv.viewer_id
             WHERE pv.profile_id = ? AND pv.viewer_id != ? AND DATE(pv.viewed_at) = CURDATE()
               AND pv.viewer_id NOT IN (SELECT hider_id FROM profile_view_hidden WHERE target_id = ? AND expires_at > NOW())", '0', $G);

        $b[] = notifBranch('vibe', 'pv.created_at', "CONCAT(COALESCE(u.username, 'Someone'), ' dropped a vibe on you: ', pv.vibe)",
            "FROM profile_vibes pv LEFT JOIN users u ON u.id = pv.voter_id
             WHERE pv.target_id = ? AND pv.voter_id != ? AND DATE(pv.created_at) = CURDATE() AND {$vibeOn}",
            '0', '', '0', 'COALESCE(u.id, 0)', "COALESCE(u.username, 'Someone')", "COALESCE(u.profile_pic, 'uploads/me.png')");

        $b[] = notifBranch('vibe', 'vv.created_at', "CONCAT(u.username, ' vibe-checked you: ', vv.vibe)",
            "FROM vibe_votes vv JOIN users u ON u.id = vv.voter_id
             WHERE vv.profile_id = ? AND vv.voter_id != ? AND DATE(vv.created_at) = CURDATE() AND {$vibeOn}");

        $b[] = notifBranch('track_request', 'tr.created_at', "CONCAT(u.username, ' sent you a Track Request')",
            "FROM track_requests tr JOIN users u ON u.id = tr.requester_id
             WHERE tr.target_id = ? AND tr.requester_id != ? AND DATE(tr.created_at) = CURDATE()");

        $b[] = notifBranch('track_approved', 'tr.created_at', "CONCAT(u.username, ' approved your Track Request')",
            "FROM track_requests tr JOIN users u ON u.id = tr.target_id
             WHERE tr.requester_id = ? AND tr.status = 'approved' AND tr.target_id != ? AND DATE(tr.created_at) = CURDATE()");

        $b[] = notifBranch('birthday', "CONCAT(CURDATE(), ' 00:00:00')", "CONCAT(u.username, ' has a birthday today')",
            "FROM user_about ua JOIN users u ON u.id = ua.user_id
             WHERE ua.date_of_birth IS NOT NULL AND DATE_FORMAT(ua.date_of_birth, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
               AND ua.user_id != ? AND ua.user_id IN (SELECT following_id FROM followers WHERE follower_id = ?)");

        $b[] = notifBranch('following_post', 'MAX(mm.created_at)', notifCnt(' posted ', ' new memes', ' posted a new meme'),
            "FROM memes mm JOIN users u ON u.id = mm.uploaded_by
             WHERE mm.uploaded_by IN (SELECT following_id FROM followers WHERE follower_id = ?) AND DATE(mm.created_at) = CURDATE()
               AND (mm.caption IS NULL OR mm.caption NOT LIKE CONCAT('%@', {$me}, '%'))", '0', $G);

        $b[] = notifBranch('message', 'MAX(m.created_at)', notifCnt(' sent you ', ' messages', ' sent you a message'),
            "FROM messages m JOIN users u ON u.id = m.sender_id
             WHERE m.receiver_id = ? AND m.sender_id != ? AND DATE(m.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('subscription', 's.created_at', "CONCAT(u.username, ' requested a subscription')",
            "FROM subscriptions s JOIN users u ON u.id = s.subscriber_id
             WHERE s.owner_id = ? AND s.subscriber_id != ? AND DATE(s.created_at) = CURDATE()");

        $b[] = notifBranch('sponsorship', 'sp.created_at', "CONCAT('Your sponsorship was ', sp.status)",
            "FROM sponsorships sp JOIN users u ON u.id = sp.user_id
             WHERE sp.user_id = ? AND sp.status != 'pending' AND DATE(sp.created_at) = CURDATE()");

        $b[] = notifBranch('ad_status', 'ma.reviewed_at', "CONCAT('Your ad was ', ma.status)",
            "FROM meme_ads ma JOIN memes mm ON mm.id = ma.meme_id JOIN users u ON u.id = ma.reviewed_by
             WHERE mm.uploaded_by = ? AND ma.status IN ('approved', 'rejected') AND DATE(ma.reviewed_at) = CURDATE()");

        $b[] = notifBranch('feedback_update', 'fl.created_at', "CONCAT('Your feedback: ', f.title, ' is now ', REPLACE(fl.new_status, '_', ' '))",
            "FROM feedback_status_log fl JOIN feedback f ON f.id = fl.feedback_id JOIN users u ON u.id = fl.changed_by
             WHERE f.user_id = ? AND fl.changed_by != ? AND DATE(fl.created_at) = CURDATE()");

        $b[] = notifBranch('data_export', 'de.ready_at', "'Your data download is ready. Open Settings > Download your data'",
            "FROM data_exports de JOIN users u ON u.id = de.user_id
             WHERE de.user_id = ? AND de.status = 'ready' AND de.expires_at > NOW() AND DATE(de.ready_at) = CURDATE()", 'de.id');

        /* ── Highlights, videos, sounds ── */
        $b[] = notifBranch('highlight_like', 'MAX(hl.created_at)', notifCnt(' liked ', ' of your highlights', ' liked your highlight'),
            "FROM highlight_likes hl JOIN highlights h ON h.id = hl.highlight_id JOIN users u ON u.id = hl.user_id
             WHERE h.user_id = ? AND hl.user_id != ? AND DATE(hl.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('highlight_msg', 'MAX(hm.created_at)', notifCnt(' sent ', ' messages on your highlights', ' sent a message on your highlight'),
            "FROM highlight_messages hm JOIN highlights h ON h.id = hm.highlight_id JOIN users u ON u.id = hm.user_id
             WHERE h.user_id = ? AND hm.user_id != ? AND DATE(hm.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('video_like', 'MAX(vl.created_at)', notifCnt(' liked ', ' of your videos', ' liked your video'),
            "FROM longform_post_likes vl JOIN longform_posts lp ON lp.id = vl.post_id JOIN users u ON u.id = vl.user_id
             WHERE lp.user_id = ? AND vl.user_id != ? AND DATE(vl.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('video_comment', 'MAX(lc.created_at)', notifCnt(' commented ', ' times on your videos', ' commented on your video'),
            "FROM longform_comments lc JOIN longform_posts lp ON lp.id = lc.post_id JOIN users u ON u.id = lc.user_id
             WHERE lp.user_id = ? AND lc.user_id != ? AND DATE(lc.created_at) = CURDATE()
               AND NOT EXISTS (SELECT 1 FROM longform_comments pc WHERE pc.id = lc.parent_id AND pc.user_id = ?)", '0', $G);

        $b[] = notifBranch('video_reply', 'MAX(lc.created_at)', notifCnt(' replied ', ' times to your comments', ' replied to your comment'),
            "FROM longform_comments lc JOIN longform_comments pc ON pc.id = lc.parent_id JOIN users u ON u.id = lc.user_id
             WHERE pc.user_id = ? AND lc.user_id != ? AND DATE(lc.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('video_save', 'MAX(vs.created_at)', notifCnt(' saved ', ' of your videos', ' saved your video'),
            "FROM longform_post_saves vs JOIN longform_posts lp ON lp.id = vs.post_id JOIN users u ON u.id = vs.user_id
             WHERE lp.user_id = ? AND vs.user_id != ? AND DATE(vs.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('sound_save', 'MAX(ss.created_at)', notifCnt(' saved ', ' of your sounds', ' saved your sound'),
            "FROM saved_sounds ss JOIN sounds so ON so.id = ss.sound_id JOIN users u ON u.id = ss.user_id
             WHERE so.user_id = ? AND ss.user_id != ? AND DATE(ss.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('audio_save', 'MAX(asv.created_at)', notifCnt(' saved ', ' of your audios', ' saved your audio'),
            "FROM audio_saves asv JOIN audios au ON au.id = asv.audio_id JOIN users u ON u.id = asv.user_id
             WHERE au.uploader_id = ? AND asv.user_id != ? AND DATE(asv.created_at) = CURDATE()", '0', $G);

        /* ── Pods ── */
        $b[] = notifBranch('group_join', 'gm.joined_at', "CONCAT(u.username, ' joined your Pod: ', g.title)",
            "FROM group_members gm JOIN `groups` g ON g.id = gm.group_id JOIN users u ON u.id = gm.user_id
             WHERE gm.group_id IN (SELECT id FROM `groups` WHERE created_by = ?) AND gm.user_id != ?
               AND DATE(gm.joined_at) = CURDATE() AND gm.group_id NOT IN ({$m})", 'gm.group_id');

        $b[] = notifBranch('join_request', 'jr.requested_at', "CONCAT(u.username, ' requested to join ', g.title)",
            "FROM group_join_requests jr JOIN `groups` g ON g.id = jr.group_id JOIN users u ON u.id = jr.user_id
             WHERE jr.status = 'pending' AND jr.user_id != ?
               AND jr.group_id IN (SELECT id FROM `groups` WHERE created_by = ? UNION SELECT group_id FROM group_admins WHERE user_id = ?)
               AND jr.group_id NOT IN ({$m})", 'jr.group_id');

        $b[] = notifBranch('join_decision', 'jr.decided_at',
            "CONCAT('Your request to join ', g.title, ' was ', IF(jr.status = 'approved', 'approved', 'declined'))",
            "FROM group_join_requests jr JOIN `groups` g ON g.id = jr.group_id JOIN users u ON u.id = g.created_by
             WHERE jr.user_id = ? AND jr.status IN ('approved', 'denied') AND DATE(jr.decided_at) = CURDATE()
               AND jr.group_id NOT IN ({$m})", 'jr.group_id');

        $b[] = notifBranch('admin_appoint', 'ga.appointed_at', "CONCAT(u.username, ' appointed you as admin of ', g.title)",
            "FROM group_admins ga JOIN `groups` g ON g.id = ga.group_id JOIN users u ON u.id = g.created_by
             WHERE ga.user_id = ? AND g.created_by != ? AND DATE(ga.appointed_at) = CURDATE() AND ga.group_id NOT IN ({$m})", 'ga.group_id');

        $b[] = notifBranch('group_post', 'MAX(gp.created_at)', notifCntG(' posted ', ' times in ', ' posted in '),
            "FROM group_posts gp JOIN `groups` g ON g.id = gp.group_id JOIN users u ON u.id = gp.posted_by
             WHERE gp.group_id IN (SELECT group_id FROM group_members WHERE user_id = ? UNION SELECT id FROM `groups` WHERE created_by = ?)
               AND gp.posted_by != ? AND DATE(gp.created_at) = CURDATE() AND gp.group_id NOT IN ({$m})",
            'gp.group_id', 'GROUP BY u.id, u.username, u.profile_pic, gp.group_id, g.id, g.title');

        $b[] = notifBranch('pod_like', 'pl.created_at', "CONCAT(u.username, ' liked your discussion in ', g.title)",
            "FROM pod_likes pl JOIN pod_discussions pd ON pd.id = pl.discussion_id JOIN `groups` g ON g.id = pd.group_id JOIN users u ON u.id = pl.user_id
             WHERE pd.user_id = ? AND pl.user_id != ? AND pl.type = 'like' AND DATE(pl.created_at) = CURDATE()
               AND pd.group_id NOT IN ({$m})", 'pd.group_id');

        $b[] = notifBranch('pod_reply', 'r.created_at', "CONCAT(u.username, ' replied to your discussion in ', g.title)",
            "FROM pod_discussion_replies r JOIN pod_discussions d ON d.id = r.discussion_id JOIN `groups` g ON g.id = d.group_id JOIN users u ON u.id = r.user_id
             WHERE d.user_id = ? AND r.user_id != ? AND DATE(r.created_at) = CURDATE() AND d.group_id NOT IN ({$m})
               AND NOT EXISTS (SELECT 1 FROM pod_discussion_replies pp WHERE pp.id = r.parent_id AND pp.user_id = ?)", 'd.group_id');

        $b[] = notifBranch('pod_nested_reply', 'r.created_at', "CONCAT(u.username, ' replied to your comment in ', g.title)",
            "FROM pod_discussion_replies r JOIN pod_discussion_replies pr ON pr.id = r.parent_id
             JOIN pod_discussions d ON d.id = pr.discussion_id JOIN `groups` g ON g.id = d.group_id JOIN users u ON u.id = r.user_id
             WHERE pr.user_id = ? AND r.user_id != ? AND DATE(r.created_at) = CURDATE() AND d.group_id NOT IN ({$m})", 'd.group_id');

        $b[] = notifBranch('pod_discussion', 'd.created_at', "CONCAT(u.username, ' started a discussion in ', g.title)",
            "FROM pod_discussions d JOIN `groups` g ON g.id = d.group_id JOIN users u ON u.id = d.user_id
             WHERE d.group_id IN (SELECT group_id FROM group_members WHERE user_id = ? UNION SELECT id FROM `groups` WHERE created_by = ?)
               AND d.user_id != ? AND DATE(d.created_at) = CURDATE() AND d.group_id NOT IN ({$m})", 'd.group_id');

        $b[] = notifBranch('event_rsvp', 'MAX(r.created_at)',
            "CONCAT(u.username, IF(SUM(r.rsvp_type = 'going') > 0, ' is going to ', ' is interested in '), e.event_name)",
            "FROM group_event_rsvps r JOIN group_events e ON e.id = r.event_id JOIN users u ON u.id = r.user_id
             WHERE e.created_by = ? AND r.user_id != ? AND DATE(r.created_at) = CURDATE() AND e.group_id NOT IN ({$m})",
            'e.group_id', 'GROUP BY u.id, u.username, u.profile_pic, e.id, e.event_name, e.group_id');

        $b[] = notifBranch('event_comment', 'MAX(ec.created_at)', "CONCAT(u.username, ' commented on your event ', e.event_name)",
            "FROM group_event_comments ec JOIN group_events e ON e.id = ec.event_id JOIN users u ON u.id = ec.user_id
             WHERE e.created_by = ? AND ec.user_id != ? AND DATE(ec.created_at) = CURDATE() AND e.group_id NOT IN ({$m})",
            'e.group_id', 'GROUP BY u.id, u.username, u.profile_pic, e.id, e.event_name, e.group_id');

        /* Reminder: events happening TODAY that I said I'm going to / interested in */
        $b[] = notifBranch('event_today', 'NOW()',
            "CONCAT('Reminder: ', e.event_name, ' is today at ', TIME_FORMAT(e.event_time, '%H:%i'))",
            "FROM group_events e JOIN group_event_rsvps r ON r.event_id = e.id AND r.user_id = ? JOIN users u ON u.id = e.created_by
             WHERE e.event_date = CURDATE() AND e.group_id NOT IN ({$m})",
            'e.group_id', 'GROUP BY u.id, u.username, u.profile_pic, e.id, e.event_name, e.event_time, e.group_id');


        /* ── Pod polls, reactions, files & highlights ── */
        if (!empty($opt['poll_votes_ts'])) {
            $b[] = notifBranch('pod_poll_vote', 'MAX(pv.created_at)', "CONCAT(u.username, ' voted on your poll in ', g.title)",
                "FROM pod_poll_votes pv JOIN pod_discussions d ON d.id = pv.discussion_id JOIN `groups` g ON g.id = d.group_id JOIN users u ON u.id = pv.user_id
                 WHERE d.user_id = ? AND pv.user_id != ? AND DATE(pv.created_at) = CURDATE() AND d.group_id NOT IN ({$m})",
                'd.group_id', 'GROUP BY u.id, u.username, u.profile_pic, d.id, d.group_id, g.id, g.title');
        }
        if (!empty($opt['reactions_ts'])) {
            $b[] = notifBranch('pod_reaction', 'MAX(pr.created_at)', "CONCAT(u.username, ' reacted to your discussion in ', g.title)",
                "FROM pod_reactions pr JOIN pod_discussions d ON d.id = pr.discussion_id JOIN `groups` g ON g.id = d.group_id JOIN users u ON u.id = pr.user_id
                 WHERE d.user_id = ? AND pr.user_id != ? AND DATE(pr.created_at) = CURDATE() AND d.group_id NOT IN ({$m})",
                'd.group_id', 'GROUP BY u.id, u.username, u.profile_pic, d.id, d.group_id, g.id, g.title');
        }

        $b[] = notifBranch('pod_file', 'MAX(gf.created_at)', notifCntG(' uploaded ', ' files to ', ' uploaded a file to '),
            "FROM group_files gf JOIN `groups` g ON g.id = gf.group_id JOIN users u ON u.id = gf.uploaded_by
             WHERE gf.group_id IN (SELECT group_id FROM group_members WHERE user_id = ? UNION SELECT id FROM `groups` WHERE created_by = ?)
               AND gf.uploaded_by != ? AND DATE(gf.created_at) = CURDATE() AND gf.group_id NOT IN ({$m})",
            'gf.group_id', 'GROUP BY u.id, u.username, u.profile_pic, gf.group_id, g.id, g.title');

        $b[] = notifBranch('phl_like', 'MAX(hl.created_at)', notifCntG(' liked ', ' of your highlights in ', ' liked your highlight in '),
            "FROM group_highlight_likes hl JOIN group_highlights gh ON gh.id = hl.highlight_id JOIN `groups` g ON g.id = gh.group_id JOIN users u ON u.id = hl.user_id
             WHERE gh.created_by = ? AND hl.user_id != ? AND DATE(hl.created_at) = CURDATE() AND gh.group_id NOT IN ({$m})",
            'gh.group_id', 'GROUP BY u.id, u.username, u.profile_pic, gh.group_id, g.id, g.title');

        $b[] = notifBranch('phl_comment', 'MAX(gc.created_at)', notifCntG(' commented ', ' times on your highlights in ', ' commented on your highlight in '),
            "FROM group_highlight_comments gc JOIN group_highlights gh ON gh.id = gc.highlight_id JOIN `groups` g ON g.id = gc.group_id JOIN users u ON u.id = gc.user_id
             WHERE gh.created_by = ? AND gc.user_id != ? AND DATE(gc.created_at) = CURDATE() AND gc.group_id NOT IN ({$m})
               AND NOT EXISTS (SELECT 1 FROM group_highlight_comments pc WHERE pc.id = gc.parent_id AND pc.user_id = ?)",
            'gc.group_id', 'GROUP BY u.id, u.username, u.profile_pic, gc.group_id, g.id, g.title');

        $b[] = notifBranch('phl_reply', 'MAX(gc.created_at)', notifCntG(' replied ', ' times to your comments in ', ' replied to your comment in '),
            "FROM group_highlight_comments gc JOIN group_highlight_comments pc ON pc.id = gc.parent_id JOIN `groups` g ON g.id = gc.group_id JOIN users u ON u.id = gc.user_id
             WHERE pc.user_id = ? AND gc.user_id != ? AND DATE(gc.created_at) = CURDATE() AND gc.group_id NOT IN ({$m})",
            'gc.group_id', 'GROUP BY u.id, u.username, u.profile_pic, gc.group_id, g.id, g.title');

        /* ── Likes on my comments ── */
        $b[] = notifBranch('comment_like', 'MAX(cl.created_at)', notifCnt(' liked ', ' of your comments on videos', ' liked your comment on a video'),
            "FROM longform_comment_likes cl JOIN longform_comments lc ON lc.id = cl.comment_id JOIN users u ON u.id = cl.user_id
             WHERE lc.user_id = ? AND cl.user_id != ? AND DATE(cl.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('comment_like', 'MAX(cl.created_at)', notifCnt(' liked ', ' of your comments on events', ' liked your comment on an event'),
            "FROM group_event_comment_likes cl JOIN group_event_comments ec ON ec.id = cl.comment_id
             JOIN group_events e ON e.id = ec.event_id JOIN users u ON u.id = cl.user_id
             WHERE ec.user_id = ? AND cl.user_id != ? AND DATE(cl.created_at) = CURDATE() AND e.group_id NOT IN ({$m})",
            'e.group_id', 'GROUP BY u.id, u.username, u.profile_pic, e.group_id');

        $b[] = notifBranch('comment_like', 'MAX(cl.created_at)', notifCnt(' liked ', ' of your comments on Pod highlights', ' liked your comment on a Pod highlight'),
            "FROM group_highlight_comment_likes cl JOIN group_highlight_comments gc ON gc.id = cl.comment_id JOIN users u ON u.id = cl.user_id
             WHERE gc.user_id = ? AND cl.user_id != ? AND DATE(cl.created_at) = CURDATE() AND gc.group_id NOT IN ({$m})",
            'gc.group_id', 'GROUP BY u.id, u.username, u.profile_pic, gc.group_id');

        /* ── Shares & downloads ── */
        $b[] = notifBranch('share', 'MAX(sh.created_at)', notifCnt(' shared ', ' of your posts', ' shared your post'),
            "FROM shares sh JOIN users u ON u.id = sh.user_id
             WHERE sh.meme_id IN (SELECT id FROM memes WHERE uploaded_by = ?) AND sh.user_id != ? AND DATE(sh.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('video_share', 'MAX(vsh.created_at)', notifCnt(' shared ', ' of your videos', ' shared your video'),
            "FROM longform_post_shares vsh JOIN longform_posts lp ON lp.id = vsh.post_id JOIN users u ON u.id = vsh.user_id
             WHERE lp.user_id = ? AND vsh.user_id != ? AND DATE(vsh.created_at) = CURDATE()", '0', $G);

        $b[] = notifBranch('video_download', 'MAX(vd.created_at)', notifCnt(' downloaded ', ' of your videos', ' downloaded your video'),
            "FROM longform_post_downloads vd JOIN longform_posts lp ON lp.id = vd.post_id JOIN users u ON u.id = vd.user_id
             WHERE lp.user_id = ? AND vd.user_id != ? AND DATE(vd.created_at) = CURDATE()", '0', $G);

        /* ── Message reactions ── */
        $b[] = notifBranch('message_reaction', 'MAX(mr.created_at)', notifCnt(' reacted ', ' times to your messages', ' reacted to your message'),
            "FROM message_reactions mr JOIN messages msg ON msg.id = mr.message_id JOIN users u ON u.id = mr.user_id
             WHERE msg.sender_id = ? AND mr.user_id != ? AND DATE(mr.created_at) = CURDATE()", '0', $G);

        /* ── Memepedia ── */
        $b[] = notifBranch('article_comment', 'MAX(mc.posted_at)', "CONCAT(u.username, ' commented on your article ', CONVERT(a.title USING utf8mb4))",
            "FROM meme_comments mc JOIN meme_articles a ON a.id = mc.article_id JOIN users u ON u.id = mc.user_id
             WHERE (a.creator_id = ? OR a.created_by = ?) AND mc.user_id != ? AND DATE(mc.posted_at) = CURDATE()",
            'a.id', 'GROUP BY u.id, u.username, u.profile_pic, a.id, a.title');

        $b[] = notifBranch('article_edit', 'MAX(ed.edited_at)', "CONCAT(u.username, ' edited your article ', CONVERT(a.title USING utf8mb4))",
            "FROM meme_edits ed JOIN meme_articles a ON a.id = ed.article_id JOIN users u ON u.id = ed.editor_id
             WHERE (a.creator_id = ? OR a.created_by = ?) AND ed.editor_id != ? AND DATE(ed.edited_at) = CURDATE()",
            'a.id', 'GROUP BY u.id, u.username, u.profile_pic, a.id, a.title');

        /* ── Memepedia: entries I FOLLOW (table meme_follows, created by memepedia.php) ──
         * Only activity after I followed, only today, never my own actions, and never on my own entries
         * (those are already covered by the two branches above). */
        $b[] = notifBranch('article_follow_edit', 'MAX(ed.edited_at)', "CONCAT(u.username, ' edited ', CONVERT(a.title USING utf8mb4), ', which you follow')",
            "FROM meme_follows mf JOIN meme_articles a ON a.id = mf.article_id
             JOIN meme_edits ed ON ed.article_id = a.id JOIN users u ON u.id = ed.editor_id
             WHERE mf.user_id = ? AND ed.editor_id != ? AND COALESCE(a.creator_id, 0) != ?
               AND ed.edited_at >= mf.created_at AND DATE(ed.edited_at) = CURDATE()",
            'a.id', 'GROUP BY u.id, u.username, u.profile_pic, a.id, a.title');

        $b[] = notifBranch('article_follow_comment', 'MAX(mc.posted_at)', "CONCAT(u.username, ' commented on ', CONVERT(a.title USING utf8mb4), ', which you follow')",
            "FROM meme_follows mf JOIN meme_articles a ON a.id = mf.article_id
             JOIN meme_comments mc ON mc.article_id = a.id JOIN users u ON u.id = mc.user_id
             WHERE mf.user_id = ? AND mc.user_id != ? AND COALESCE(a.creator_id, 0) != ?
               AND mc.posted_at >= mf.created_at AND DATE(mc.posted_at) = CURDATE()",
            'a.id', 'GROUP BY u.id, u.username, u.profile_pic, a.id, a.title');

        /* status change = an edit whose summary lists "Status" (e.g. "Edited Status, Tags") */
        $b[] = notifBranch('article_follow_status', 'MAX(ed.edited_at)',
            "CONCAT(u.username, ' changed the status of ', CONVERT(a.title USING utf8mb4), ' to ', CONCAT(UCASE(LEFT(a.status, 1)), SUBSTRING(a.status, 2)))",
            "FROM meme_follows mf JOIN meme_articles a ON a.id = mf.article_id
             JOIN meme_edits ed ON ed.article_id = a.id JOIN users u ON u.id = ed.editor_id
             WHERE mf.user_id = ? AND ed.editor_id != ? AND COALESCE(a.creator_id, 0) != ?
               AND ed.edited_at >= mf.created_at AND DATE(ed.edited_at) = CURDATE()
               AND (ed.summary LIKE 'Edited Status%' OR ed.summary LIKE '%, Status,%' OR ed.summary LIKE '%, Status +%' OR ed.summary LIKE '%, Status')",
            'a.id', 'GROUP BY u.id, u.username, u.profile_pic, a.id, a.title, a.status');

        /* ── Username changes of people I track (A→B→C today shows the original name once) ── */
        $firstOld = "SUBSTRING_INDEX(GROUP_CONCAT(uh.old_username ORDER BY uh.changed_at ASC, uh.id ASC SEPARATOR '|'), '|', 1)";
        $b[] = notifBranch('username_change', 'MAX(uh.changed_at)', "CONCAT(u.username, ' changed their username from ', {$firstOld})",
            "FROM username_history uh JOIN users u ON u.id = uh.user_id
             WHERE uh.user_id != ? AND DATE(uh.changed_at) = CURDATE()
               AND uh.user_id IN (SELECT following_id FROM followers WHERE follower_id = ?)",
            '0', $G . " HAVING {$firstOld} != u.username");

        /* ── Milestones (shown on the day they are reached) ── */
        $thT = '(SELECT 100 AS m UNION ALL SELECT 250 UNION ALL SELECT 500 UNION ALL SELECT 1000 UNION ALL SELECT 2500 UNION ALL SELECT 5000 UNION ALL SELECT 10000 UNION ALL SELECT 25000 UNION ALL SELECT 50000 UNION ALL SELECT 100000)';
        $thV = '(SELECT 1000 AS m UNION ALL SELECT 5000 UNION ALL SELECT 10000 UNION ALL SELECT 50000 UNION ALL SELECT 100000 UNION ALL SELECT 500000 UNION ALL SELECT 1000000)';

        $b[] = notifBranch('milestone',
            "(SELECT f1.created_at FROM followers f1 WHERE f1.following_id = u.id AND (SELECT COUNT(*) FROM followers f2 WHERE f2.following_id = u.id AND (f2.created_at < f1.created_at OR (f2.created_at = f1.created_at AND f2.id <= f1.id))) = ths.m LIMIT 1)",
            "CONCAT('You reached ', FORMAT(ths.m, 0), ' Trackers')",
            "FROM users u CROSS JOIN {$thT} ths
             WHERE u.id = ? AND ths.m <= (SELECT COUNT(*) FROM followers WHERE following_id = u.id)
               AND ths.m > (SELECT COUNT(*) FROM followers WHERE following_id = u.id AND created_at < CURDATE())");

        $b[] = notifBranch('milestone',
            "(SELECT p1.viewed_at FROM profile_views p1 WHERE p1.profile_id = u.id AND p1.viewer_id != u.id AND (SELECT COUNT(*) FROM profile_views p2 WHERE p2.profile_id = u.id AND p2.viewer_id != u.id AND (p2.viewed_at < p1.viewed_at OR (p2.viewed_at = p1.viewed_at AND p2.id <= p1.id))) = ths.m LIMIT 1)",
            "CONCAT('Your profile reached ', FORMAT(ths.m, 0), ' views')",
            "FROM users u CROSS JOIN {$thV} ths
             WHERE u.id = ? AND ths.m <= (SELECT COUNT(*) FROM profile_views WHERE profile_id = u.id AND viewer_id != u.id)
               AND ths.m > (SELECT COUNT(*) FROM profile_views WHERE profile_id = u.id AND viewer_id != u.id AND viewed_at < CURDATE())");

        $b[] = notifBranch('milestone',
            "(SELECT MAX(v.created_at) FROM longform_post_views v WHERE v.post_id = lp.id)",
            "CONCAT('Your video ', lp.title, ' reached ', FORMAT(ths.m, 0), ' views')",
            "FROM longform_posts lp JOIN users u ON u.id = lp.user_id CROSS JOIN {$thV} ths
             WHERE lp.user_id = ? AND ths.m <= (SELECT COUNT(*) FROM longform_post_views v WHERE v.post_id = lp.id)
               AND ths.m > (SELECT COUNT(*) FROM longform_post_views v WHERE v.post_id = lp.id AND v.created_at < CURDATE())");

        $b[] = notifBranch('group_invite', 'n.created_at', 'n.message',
            "FROM notifications n JOIN users u ON u.id = n.actor_id
             WHERE n.user_id = ? AND n.type = 'group_invite' AND DATE(n.created_at) = CURDATE() AND {$inv} NOT IN ({$m})",
            $inv, '', 'n.is_read');

        return $b;
    }
}

/* Validates every branch with prepare() (checks tables, columns and syntax without running anything)
 * and joins only the working ones. One broken branch now costs ONE notification type, not the whole bell.
 * Broken branches are written to the PHP error log as "notif branch #N broken: ...".
 * The result is cached in the session for 5 minutes, so after you fix a table it recovers by itself. */
if (!function_exists('notifUnionSql')) {
    function notifUnionSql(string $m, array $opt = []): string {
        global $conn;
        $branches = notifBranches($m, $opt);
        $key      = 'notif_ok_' . md5($m . json_encode($opt) . count($branches));
        $cache    = $_SESSION[$key] ?? null;

        if (!is_array($cache) || (time() - ($cache['t'] ?? 0)) > 300) {
            $ok = [];
            foreach ($branches as $i => $sql) {
                try {
                    $st = $conn->prepare($sql);
                    if ($st) { $st->close(); $ok[] = $i; }
                    else error_log("notif branch #{$i} broken: " . $conn->error);
                } catch (Throwable $e) {
                    error_log("notif branch #{$i} broken: " . $e->getMessage());
                }
            }
            $cache = ['t' => time(), 'ok' => $ok];
            $_SESSION[$key] = $cache;
        }

        $good = array_intersect_key($branches, array_flip($cache['ok']));
        if (empty($good)) {
            /* never return an empty UNION; this keeps the outer query valid (zero rows) */
            return "(SELECT '' AS type, '' AS created_at, 0 AS age_sec, 0 AS actor_id, '' AS username, '' AS profile_pic, '' AS message, 0 AS ref_id, 0 AS is_read FROM DUAL WHERE 1 = 0)";
        }
        return implode("\nUNION ALL\n", $good);
    }
}

/* Wraps the union: hides blocked / muted users and categories the user switched off in settings. */
if (!function_exists('notifFullSql')) {
    function notifFullSql(string $muted, string $typeFilter, array $opt = []): string {
        $podTypes = "'group_join','join_request','join_decision','admin_appoint','group_post','pod_like','pod_reply','pod_nested_reply','pod_discussion','pod_poll_vote','pod_reaction','pod_file','phl_like','phl_comment','phl_reply','group_invite'";
        return "SELECT t.*, CONVERT(gg.title USING utf8mb4) AS group_name FROM (" . notifUnionSql($muted, $opt) . ") t
                LEFT JOIN `groups` gg ON gg.id = t.ref_id AND t.type IN ({$podTypes})
                WHERE t.actor_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)
                  AND t.actor_id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)
                  AND t.actor_id NOT IN (SELECT muted_id FROM muted_users WHERE muter_id = ?)" . $typeFilter;
    }
}

if (!function_exists('notifRun')) {
    function notifRun(mysqli $conn, string $sql, int $uid): ?array {
        try {
            $st = $conn->prepare($sql);
            if (!$st) { error_log('notif prepare failed: ' . $conn->error); return null; }
            $n = substr_count($sql, '?');
            if ($n > 0) {
                $params = array_fill(0, $n, $uid);
                $st->bind_param(str_repeat('i', $n), ...$params);
            }
            if (!$st->execute()) { error_log('notif execute failed: ' . $st->error); return null; }
            $res  = $st->get_result();
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $st->close();
            return $rows;
        } catch (Throwable $e) {
            error_log('notif error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('notifTotal')) {
    function notifTotal(mysqli $conn, string $fullSql, int $uid): int {
        $rows = notifRun($conn, "SELECT COUNT(*) AS total FROM ({$fullSql}) c", $uid);
        $total = $rows ? (int)($rows[0]['total'] ?? 0) : 0;
        return min(150, $total);
    }
}

/* Unread = events that happened AFTER the moment the bell was last opened today.
 * So if someone views your profile at 5:30, you open the bell at 5:35, and they view again
 * at 5:50, the 5:50 visit counts as new and shows up as the most recent one. */
if (!function_exists('notifUnread')) {
    function notifUnread(mysqli $conn, int $uid, array $rows): int {
        $seenAge = null;   // seconds since the bell was last opened today (DB clock)
        try {
            $st = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, seen_at, NOW()) AS a FROM notification_seen WHERE user_id = ? AND seen_date = CURDATE()");
            $st->bind_param('i', $uid);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row && $row['a'] !== null) $seenAge = max(0, (int)$row['a']);
        } catch (Throwable $e) { error_log('notif unread error: ' . $e->getMessage()); }

        if ($seenAge === null) return count($rows);          // not opened yet today → everything is new
        $n = 0;
        foreach ($rows as $r) {
            if (($r['type'] ?? '') === 'event_today') continue;   // reminder: only counts before first open
            if ((int)($r['age_sec'] ?? 0) < $seenAge) $n++;
        }
        return $n;
    }
}

/* Age label from seconds. Seconds come from the DATABASE clock (TIMESTAMPDIFF vs NOW()),
 * so PHP/MySQL timezone differences can no longer shift times by an hour. */
if (!function_exists('notifAgo')) {
    function notifAgo(int $sec): string {
        $sec = max(0, $sec);
        if ($sec < 60)     return 'just now';
        if ($sec < 3600)   return floor($sec / 60) . 'm ago';
        if ($sec < 86400)  return floor($sec / 3600) . 'h ago';
        if ($sec < 604800) return floor($sec / 86400) . 'd ago';
        return floor($sec / 604800) . 'w ago';
    }
}

/* Fingerprint of the list – lets the poller notice ANY change (new, removed, updated). */
if (!function_exists('notifSig')) {
    function notifSig(array $rows): string {
        $s = '';
        foreach ($rows as $r) {
            $s .= ($r['type'] ?? '') . '|' . ($r['actor_id'] ?? '') . '|' . ($r['created_at'] ?? '') . '|'
                . ($r['ref_id'] ?? '') . '|' . ($r['is_read'] ?? '') . '|' . ($r['message'] ?? '') . ';';
        }
        return md5($s);
    }
}

/* pod_poll_votes and pod_reactions have no timestamp, so "today" is impossible until they get one.
 * This adds a created_at column once (old rows are back-dated so they don't flood today's list).
 * Set $NOTIF_AUTO_MIGRATE = false at the top of the file to disable and run the SQL yourself. */
if (!function_exists('notifHasCol')) {
    function notifHasCol(mysqli $conn, string $table, string $col): bool {
        $st = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->bind_param('ss', $table, $col);
        $st->execute();
        $st->bind_result($n);
        $st->fetch();
        $st->close();
        return $n > 0;
    }
}
if (!function_exists('notifSchemaOpts')) {
    function notifSchemaOpts(mysqli $conn, bool $auto): array {
        if (!empty($_SESSION['notif_schema_ok'])) return ['poll_votes_ts' => true, 'reactions_ts' => true];
        $opt = ['poll_votes_ts' => false, 'reactions_ts' => false];
        $map = ['poll_votes_ts' => 'pod_poll_votes', 'reactions_ts' => 'pod_reactions'];
        foreach ($map as $key => $tbl) {
            try {
                $has = notifHasCol($conn, $tbl, 'created_at');
                if (!$has && $auto) {
                    $conn->query("ALTER TABLE `{$tbl}` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
                    $conn->query("UPDATE `{$tbl}` SET `created_at` = '2000-01-01 00:00:00'");
                    $has = notifHasCol($conn, $tbl, 'created_at');
                }
                $opt[$key] = $has;
            } catch (Throwable $e) { error_log('notif schema error: ' . $e->getMessage()); }
        }
        if ($opt['poll_votes_ts'] && $opt['reactions_ts']) $_SESSION['notif_schema_ok'] = true;
        return $opt;
    }
}

if (!function_exists('notifMaps')) {
    function notifMaps(): array {
        static $m = null;
        if ($m !== null) return $m;
        $m = [
            'icons' => [
                'like'             => ['ri-heart-fill',          '#7a0000'],
                'follow'           => ['ri-user-follow-fill',    '#8b0000'],
                'view'             => ['ri-eye-fill',            '#9b1010'],
                'group_join'       => ['ri-group-fill',          '#a81818'],
                'following_post'   => ['ri-image-fill',          '#b52020'],
                'group_post'       => ['ri-layout-grid-fill',    '#c02828'],
                'remix'            => ['ri-repeat-fill',         '#cc3030'],
                'message'          => ['ri-message-3-fill',      '#d43838'],
                'repost'           => ['ri-repeat-2-fill',       '#dd4040'],
                'favorite'         => ['ri-bookmark-fill',       '#e44848'],
                'download'         => ['ri-download-fill',       '#e85555'],
                'admin_appoint'    => ['ri-shield-star-fill',    '#ef6e6e'],
                'collection'       => ['ri-folder-fill',         '#f27878'],
                'subscription'     => ['ri-vip-crown-fill',      '#f48484'],
                'track_request'    => ['ri-radar-fill',          '#f69090'],
                'track_approved'   => ['ri-checkbox-circle-fill', '#f79d9d'],
                'sponsorship'      => ['ri-megaphone-fill',      '#f9a8a8'],
                'pod_like'         => ['ri-heart-3-fill',        '#fab2b2'],
                'pod_reply'        => ['ri-chat-3-fill',         '#fbbcbc'],
                'pod_nested_reply' => ['ri-reply-fill',          '#fcc6c6'],
                'pod_discussion'   => ['ri-discuss-fill',        '#fdd0d0'],
                'group_invite'     => ['ri-user-add-fill',       '#4dabff'],
                /* new */
                'vibe'             => ['ri-sparkling-2-fill',    '#c58bff'],
                'mention'          => ['ri-at-line',             '#4dabff'],
                'comment'          => ['ri-chat-1-fill',         '#ff9f43'],
                'birthday'         => ['ri-cake-2-fill',         '#ff7ab8'],
                'highlight_like'   => ['ri-star-fill',           '#ffd166'],
                'highlight_msg'    => ['ri-message-2-fill',      '#ffd166'],
                'video_like'       => ['ri-thumb-up-fill',       '#ff6b6b'],
                'video_comment'    => ['ri-chat-3-fill',         '#ff9f43'],
                'video_reply'      => ['ri-reply-fill',          '#ff9f43'],
                'video_save'       => ['ri-bookmark-3-fill',     '#ff6b6b'],
                'sound_save'       => ['ri-music-2-fill',        '#5eead4'],
                'audio_save'       => ['ri-headphone-fill',      '#5eead4'],
                'join_request'     => ['ri-user-add-fill',       '#4dabff'],
                'join_decision'    => ['ri-check-double-fill',   '#4ade80'],
                'event_rsvp'       => ['ri-calendar-check-fill', '#4ade80'],
                'event_comment'    => ['ri-calendar-event-fill', '#ff9f43'],
                'event_today'      => ['ri-alarm-fill',          '#ffd166'],
                'ad_status'        => ['ri-advertisement-fill',  '#f9a8a8'],
                'feedback_update'  => ['ri-feedback-fill',       '#4dabff'],
                'pod_poll_vote'    => ['ri-bar-chart-fill',      '#4dabff'],
                'pod_reaction'     => ['ri-emotion-laugh-fill',  '#ffd166'],
                'pod_file'         => ['ri-file-text-fill',      '#5eead4'],
                'phl_like'         => ['ri-star-fill',           '#ffd166'],
                'phl_comment'      => ['ri-chat-1-fill',         '#ff9f43'],
                'phl_reply'        => ['ri-reply-fill',          '#ff9f43'],
                'comment_like'     => ['ri-heart-2-fill',        '#ff6b6b'],
                'share'            => ['ri-share-forward-fill',  '#4ade80'],
                'video_share'      => ['ri-share-forward-fill',  '#4ade80'],
                'video_download'   => ['ri-download-cloud-fill', '#e85555'],
                'message_reaction' => ['ri-emotion-happy-fill',  '#ffd166'],
                'article_comment'  => ['ri-article-fill',        '#ff9f43'],
                'article_edit'     => ['ri-edit-fill',           '#c58bff'],
                'article_follow_edit'    => ['ri-bookmark-fill', '#c58bff'],
                'article_follow_comment' => ['ri-bookmark-fill', '#ff9f43'],
                'article_follow_status'  => ['ri-pulse-fill',    '#4ade80'],
                'milestone'        => ['ri-trophy-fill',         '#ffd166'],
                'username_change'  => ['ri-edit-box-fill',       '#c58bff'],
                'data_export'      => ['ri-download-cloud-2-fill', '#4dabff'],
                'collab_invite'    => ['ri-team-fill',           '#ff8fa3'],
                'collab_accepted'  => ['ri-user-add-fill',       '#ff8fa3'],
            ],
            'labels' => [
                'like' => 'Likes', 'repost' => 'Reposts', 'favorite' => 'Saves', 'download' => 'Downloads',
                'remix' => 'Remixes', 'collection' => 'Collections', 'follow' => 'New Trackers',
                'view' => 'Profile Views', 'track_request' => 'Track Requests', 'track_approved' => 'Track Approved',
                'admin_appoint' => 'Pod Admin', 'message' => 'Messages', 'group_join' => 'Pod Joins',
                'group_post' => 'Pod Posts', 'pod_like' => 'Pod Likes', 'pod_reply' => 'Pod Replies',
                'pod_nested_reply' => 'Pod Replies', 'pod_discussion' => 'Pod Discussions',
                'following_post' => 'New Posts', 'subscription' => 'Subscriptions', 'sponsorship' => 'Sponsorships',
                'group_invite' => 'Pod Invites', 'vibe' => 'Vibe Checks', 'mention' => 'Mentions',
                'comment' => 'Comments', 'birthday' => 'Birthdays', 'highlight_like' => 'Highlight Likes',
                'highlight_msg' => 'Highlight Messages', 'video_like' => 'Video Likes',
                'video_comment' => 'Video Comments', 'video_reply' => 'Video Replies', 'video_save' => 'Video Saves',
                'sound_save' => 'Sound Saves', 'audio_save' => 'Audio Saves', 'join_request' => 'Join Requests',
                'join_decision' => 'Join Updates', 'event_rsvp' => 'Event RSVPs', 'event_comment' => 'Event Comments',
                'event_today' => 'Events Today', 'ad_status' => 'Ad Updates', 'feedback_update' => 'Feedback Updates',
                'pod_poll_vote' => 'Poll Votes', 'pod_reaction' => 'Pod Reactions', 'pod_file' => 'Pod Files',
                'phl_like' => 'Pod Highlight Likes', 'phl_comment' => 'Pod Highlight Comments', 'phl_reply' => 'Pod Highlight Replies',
                'comment_like' => 'Comment Likes', 'share' => 'Shares', 'video_share' => 'Video Shares',
                'video_download' => 'Video Downloads', 'message_reaction' => 'Message Reactions',
                'article_comment' => 'Article Comments', 'article_edit' => 'Article Edits', 'milestone' => 'Milestones',
                'article_follow_edit' => 'Followed Entries', 'article_follow_comment' => 'Followed Entries', 'article_follow_status' => 'Followed Entries',
                'username_change' => 'Username Changes', 'data_export' => 'Data Downloads',
                'collab_invite' => 'Collaboration Invites', 'collab_accepted' => 'Collaborations',
            ],
            /* wording used when several people did the same thing ("A and 3 others …") */
            'actions' => [
                'like' => 'liked your post', 'repost' => 'reposted your meme', 'favorite' => 'saved your meme',
                'download' => 'downloaded your meme', 'view' => 'viewed your profile', 'follow' => 'started Tracking you',
                'video_like' => 'liked your video', 'video_save' => 'saved your video',
                'highlight_like' => 'liked your highlight',
                'share' => 'shared your post', 'video_share' => 'shared your video', 'video_download' => 'downloaded your video',
            ],
            'cats' => [
                'social'   => ['follow', 'view', 'track_request', 'track_approved', 'group_invite', 'vibe', 'mention',
                               'birthday', 'feedback_update', 'milestone', 'username_change', 'data_export', 'collab_invite', 'collab_accepted'],
                'pods'     => ['group_join', 'group_post', 'pod_like', 'pod_reply', 'pod_nested_reply', 'pod_discussion',
                               'admin_appoint', 'join_request', 'join_decision', 'event_rsvp', 'event_comment', 'event_today',
                               'pod_poll_vote', 'pod_reaction', 'pod_file', 'phl_like', 'phl_comment', 'phl_reply'],
                'content'  => ['like', 'repost', 'favorite', 'download', 'remix', 'collection', 'following_post',
                               'subscription', 'sponsorship', 'comment', 'highlight_like', 'highlight_msg', 'video_like',
                               'video_comment', 'video_reply', 'video_save', 'sound_save', 'audio_save', 'ad_status',
                               'comment_like', 'share', 'video_share', 'video_download', 'article_comment', 'article_edit',
                               'article_follow_edit', 'article_follow_comment', 'article_follow_status'],
                'messages' => ['message', 'message_reaction'],
            ],
            /* these open the Memepedia entry (ref_id = entry id) instead of the actor's profile */
            'articleLink' => ['article_comment', 'article_edit', 'article_follow_edit', 'article_follow_comment', 'article_follow_status'],
            /* these open the Pod instead of the actor's profile */
            'groupLink' => ['group_join', 'group_post', 'pod_like', 'pod_reply', 'pod_nested_reply', 'pod_discussion',
                            'admin_appoint', 'join_request', 'join_decision', 'event_rsvp', 'event_comment',
                            'event_today', 'pod_poll_vote', 'pod_reaction', 'pod_file', 'phl_like', 'phl_comment', 'phl_reply', 'comment_like'],
        ];
        $m['groupable'] = array_keys($m['actions']);
        return $m;
    }
}

if (!function_exists('notifGroup')) {
    function notifGroup(array $items, array $groupable): array {
        $out = []; $idx = []; $seen = [];
        foreach ($items as $n) {
            $type = $n['type'] ?? '';
            if (in_array($type, $groupable, true)) {
                $key   = $type . '_' . substr((string)($n['created_at'] ?? ''), 0, 10);
                $aid   = (int)($n['actor_id'] ?? 0);
                $actor = ['username' => $n['username'], 'profile_pic' => $n['profile_pic'], 'actor_id' => $aid];
                if (!isset($idx[$key])) {
                    $idx[$key]  = count($out);
                    $seen[$key] = [$aid => true];
                    $n['_group_count']  = 1;
                    $n['_group_actors'] = [$actor];
                    $out[] = $n;
                } else {
                    if (isset($seen[$key][$aid])) continue;   // same person twice → count once
                    $seen[$key][$aid] = true;
                    $i = $idx[$key];
                    $out[$i]['_group_count']++;
                    $out[$i]['_group_actors'][] = $actor;
                }
            } else {
                $n['_group_count']  = 1;
                $n['_group_actors'] = [];
                $out[] = $n;
            }
        }
        return $out;
    }
}

if (!function_exists('notifRenderItem')) {
    function notifRenderItem(array $note, array $mp, int $uid): string {
        $ntype  = $note['type'] ?? '';
        $nactor = (int)($note['actor_id'] ?? 0);
        $nref   = (int)($note['ref_id'] ?? 0);
        $rawMsg = (string)($note['message'] ?? '');
        $npic   = !empty($note['profile_pic']) ? htmlspecialchars($note['profile_pic'], ENT_QUOTES) : 'uploads/me.png';
        $nuname = trim((string)($note['username'] ?? '')) !== '' ? htmlspecialchars($note['username'], ENT_QUOTES) : 'Someone';
        $nmsg   = $rawMsg !== '' ? htmlspecialchars($rawMsg, ENT_QUOTES) : 'New activity';
        $ageSec = isset($note['age_sec']) ? max(0, (int)$note['age_sec']) : null;
        $ntime  = $ageSec !== null ? notifAgo($ageSec) : timeAgoNotif($note['created_at'] ?? '');
        $icon   = $mp['icons'][$ntype] ?? ['ri-bell-fill', '#aaa'];
        $nread  = (bool)($note['is_read'] ?? false);
        $gc     = (int)($note['_group_count'] ?? 1);
        $gactors = $note['_group_actors'] ?? [];

        $isInvite   = ($ntype === 'group_invite');
        $isGrouped  = ($gc > 1 && in_array($ntype, $mp['groupable'], true));
        $actionWord = $mp['actions'][$ntype] ?? 'interacted';

        // bold the actor's name only when the message starts with it
        $msgDisplay = $nmsg;
        if ($nuname !== '' && strpos($nmsg, $nuname) === 0) {
            $msgDisplay = '<strong>' . $nuname . '</strong>' . substr($nmsg, strlen($nuname));
        }

        if ($isGrouped && $gc === 2 && count($gactors) >= 2) {
            $n2 = htmlspecialchars($gactors[1]['username'] ?? '', ENT_QUOTES);
            $msgDisplay = '<strong>' . $nuname . '</strong> and <strong>' . $n2 . '</strong> ' . $actionWord;
        } elseif ($isGrouped && $gc > 2) {
            $msgDisplay = '<strong>' . $nuname . '</strong> and <strong>' . ($gc - 1) . ' others</strong> ' . $actionWord;
        }

        // Pod name → dark-blue clickable link to that Pod
        $gname = trim((string)($note['group_name'] ?? ''));
        if ($gname !== '' && $nref > 0 && ($safeG = htmlspecialchars($gname, ENT_QUOTES)) !== '' && ($gpos = strrpos($msgDisplay, $safeG)) !== false) {
            $glink = '<a class="notif-group-link" href="group.php?id=' . $nref . '" onclick="event.stopPropagation();">' . $safeG . '</a>';
            $msgDisplay = substr($msgDisplay, 0, $gpos) . $glink . substr($msgDisplay, $gpos + strlen($safeG));
        } elseif ($isInvite && preg_match('/Pod:\s*(.+)$/iu', $rawMsg, $gm)) {
            $safeName  = htmlspecialchars(trim($gm[1]), ENT_QUOTES);
            $groupHref = 'group.php?id=' . $nref;
            $msgDisplay = preg_replace_callback('/Pod:\s*.+$/iu', function () use ($groupHref, $safeName) {
                return 'Pod: <a class="notif-group-link" href="' . $groupHref . '" onclick="event.stopPropagation();">' . $safeName . '</a>';
            }, $msgDisplay);
        }

        $profileId = $nactor > 0 ? $nactor : $uid;
        if ($isInvite) {
            $tag  = 'div';
            $attr = 'onclick="window.location=\'profile.php?user_id=' . $profileId . '\'"';
        } elseif ($isGrouped) {
            $aj   = htmlspecialchars(json_encode($gactors), ENT_QUOTES, 'UTF-8');
            $al   = htmlspecialchars($actionWord, ENT_QUOTES);
            $tag  = 'div';
            $attr = 'onclick="openNotifActorsModal(' . $aj . ', \'' . $al . '\')"';
        } elseif (in_array($ntype, $mp['articleLink'], true) && $nref > 0) {
            $tag  = 'a';
            $attr = 'href="memepedia.php?open=' . $nref . '"';
        } elseif (in_array($ntype, $mp['groupLink'], true) && $nref > 0) {
            $tag  = 'div';
            $attr = 'onclick="window.location=\'group.php?id=' . $nref . '\'"';
        } else {
            $tag  = 'a';
            $attr = 'href="profile.php?user_id=' . $profileId . '"';
        }

        $unreadClass = (!$nread && in_array($ntype, ['group_invite', 'join_request', 'collab_invite'], true)) ? ' unread' : '';
        $isCollabInvite = ($ntype === 'collab_invite' && $nref > 0);
        if ($isCollabInvite || ($ntype === 'collab_accepted' && $nref > 0)) {
            $tag  = 'div';
            $attr = 'onclick="window.location=\'index.php?meme=' . $nref . '\'"';
        }

        if ($isGrouped && count($gactors) >= 2) {
            $avatar = '<div class="notif-avatar-stack">';
            foreach (array_slice($gactors, 0, 2) as $ga) {
                $gp = !empty($ga['profile_pic']) ? htmlspecialchars($ga['profile_pic'], ENT_QUOTES) : 'uploads/me.png';
                $avatar .= '<img class="sa-img" src="' . $gp . '" onerror="this.src=\'uploads/me.png\'" alt="">';
            }
            if ($gc > 2) $avatar .= '<span class="sa-more">+' . ($gc - 2) . '</span>';
            $avatar .= '<span class="notif-type-icon" style="color:' . $icon[1] . ';bottom:-4px;right:-4px;"><i class="' . $icon[0] . '"></i></span></div>';
        } else {
            $avatar = '<div class="notif-avatar-wrap"><img class="notif-avatar" src="' . $npic . '" onerror="this.src=\'uploads/me.png\'" alt="' . $nuname . '">'
                    . '<span class="notif-type-icon" style="color:' . $icon[1] . ';"><i class="' . $icon[0] . '"></i></span></div>';
        }

        return '<' . $tag . ' class="notif-item' . $unreadClass . '" ' . $attr . '>' . $avatar
             . '<div class="notif-text"><div class="notif-msg">' . $msgDisplay . '</div>'
             . '<div class="notif-time"><i class="ri-time-line"></i><span class="notif-ago"' . ($ageSec !== null ? ' data-age="' . $ageSec . '"' : '') . '>' . $ntime . '</span></div>'
             . ($isCollabInvite ? '<div class="notif-collab-btns"><button type="button" class="notif-collab-yes" onclick="event.stopPropagation();dzNotifCollab(' . $nref . ',1,this)">Accept</button>'
                                 . '<button type="button" class="notif-collab-no" onclick="event.stopPropagation();dzNotifCollab(' . $nref . ',0,this)">Decline</button></div>' : '')
             . '</div></' . $tag . '>';
    }
}

/* Turns raw rows into the tab bar + panel HTML (used for first render AND live refresh). */
if (!function_exists('notifBuildView')) {
    function notifBuildView(array $rows, int $uid): array {
        $mp   = notifMaps();
        $cats = ['all' => $rows, 'social' => [], 'pods' => [], 'content' => [], 'messages' => []];
        foreach ($rows as $n) {
            foreach (['social', 'pods', 'content', 'messages'] as $k) {
                if (in_array($n['type'], $mp['cats'][$k], true)) $cats[$k][] = $n;
            }
        }
        foreach ($cats as $k => $v) $cats[$k] = notifGroup($v, $mp['groupable']);

        $counts = ['all' => count($rows)];
        foreach (['social', 'pods', 'content', 'messages'] as $k) $counts[$k] = count($cats[$k]);

        $defs = ['all' => 'All', 'social' => 'Social', 'pods' => 'Pods', 'content' => 'Content', 'messages' => 'Messages'];
        $tabs = '';
        foreach ($defs as $k => $label) {
            $tabs .= '<button class="notif-tab' . ($k === 'all' ? ' active' : '') . '" data-key="' . $k . '" onclick="switchNotifTab(\'' . $k . '\', this)">'
                   . $label . ($counts[$k] > 0 ? '<span class="notif-tab-count">' . $counts[$k] . '</span>' : '') . '</button>';
        }

        $panels = '';
        foreach ($cats as $k => $items) {
            $panels .= '<div class="notif-panel' . ($k === 'all' ? ' active' : '') . '" id="notif-panel-' . $k . '">';
            if (empty($items)) {
                $panels .= '<div class="notif-empty"><i class="ri-notification-off-line"></i><p>Nothing here today.<br>Check back later!</p></div>';
            } else {
                $sections = [];
                foreach ($items as $n) $sections[$mp['labels'][$n['type'] ?? ''] ?? 'Other'][] = $n;
                foreach ($sections as $name => $list) {
                    $panels .= '<div class="notif-section-label">' . htmlspecialchars($name);
                    if (count($list) > 1) $panels .= '<span class="notif-section-count">' . count($list) . '</span>';
                    $panels .= '</div>';
                    foreach ($list as $note) $panels .= notifRenderItem($note, $mp, $uid);
                }
            }
            $panels .= '</div>';
        }
        return ['tabs' => $tabs, 'panels' => $panels];
    }
}

if (!function_exists('notifJson')) {
    function notifJson(array $data): void {
        while (ob_get_level()) { ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo '@@NOTIF_START@@' . json_encode($data) . '@@NOTIF_END@@';
        exit();
    }
}

/* ════════════════════════════════════════════════════════════════
 *  MAIN
 * ════════════════════════════════════════════════════════════════ */

/* ── "Turn off notifications" switch (Account settings → user_settings.notifications_enabled) ──
 * When it is off: no bell, badge, toast or browser alert is rendered, and any leftover
 * poll / mark_seen / fetch_list request from an old open tab gets an empty "off" answer. */
$notif_enabled = true;
if ($current_user_id) {
    try {
        $ne = $conn->prepare("SELECT notifications_enabled FROM user_settings WHERE user_id = ?");
        $ne->bind_param('i', $current_user_id); $ne->execute();
        $nrow = $ne->get_result()->fetch_assoc(); $ne->close();
        if ($nrow && (int)$nrow['notifications_enabled'] === 0) $notif_enabled = false;
    } catch (Throwable $e) { /* column not created yet: stays on */ }
    if (!$notif_enabled && (isset($_POST['mark_seen']) || isset($_GET['poll_count']) || isset($_GET['fetch_list']))) {
        notifJson(['ok' => true, 'count' => 0, 'unread' => 0, 'sig' => 'off', 'tabs' => '', 'panels' => '']);
    }
}

if ($current_user_id && $page_user_id == $current_user_id && $notif_enabled):

$uid = (int)$current_user_id;

// ── Pods this user muted ─────────────────────────────────
$muted_group_ids = [];
try {
    $mg = $conn->prepare("SELECT group_id FROM group_notif_off WHERE user_id = ?");
    $mg->bind_param('i', $uid);
    $mg->execute();
    $mgr = $mg->get_result();
    while ($row = $mgr->fetch_assoc()) $muted_group_ids[] = (int)$row['group_id'];
    $mg->close();
} catch (Throwable $e) { error_log('notif muted error: ' . $e->getMessage()); }
try {   /* the second "muted Pods" table */
    $mg2 = $conn->prepare("SELECT group_id FROM muted_groups WHERE user_id = ?");
    $mg2->bind_param('i', $uid);
    $mg2->execute();
    $mgr2 = $mg2->get_result();
    while ($row = $mgr2->fetch_assoc()) $muted_group_ids[] = (int)$row['group_id'];
    $mg2->close();
    $muted_group_ids = array_values(array_unique($muted_group_ids));
} catch (Throwable $e) { /* table missing */ }
$muted_groups_sql = !empty($muted_group_ids) ? implode(',', $muted_group_ids) : '0';

// ── Respect the user's own notification settings ─────────
$off_types = [];
try {
    $ps = $conn->prepare("SELECT notify_likes, notify_follows, notify_messages FROM user_settings WHERE user_id = ?");
    $ps->bind_param('i', $uid);
    $ps->execute();
    if ($prow = $ps->get_result()->fetch_assoc()) {
        if (!(int)$prow['notify_likes'])    array_push($off_types, 'like', 'pod_like', 'video_like', 'highlight_like', 'comment_like', 'phl_like');
        if (!(int)$prow['notify_follows'])  array_push($off_types, 'follow', 'track_request', 'track_approved');
        if (!(int)$prow['notify_messages']) array_push($off_types, 'message', 'highlight_msg', 'message_reaction');
    }
    $ps->close();
} catch (Throwable $e) { error_log('notif prefs error: ' . $e->getMessage()); }
$type_filter = !empty($off_types) ? " AND t.type NOT IN ('" . implode("','", $off_types) . "')" : '';

$notif_opt = notifSchemaOpts($conn, $NOTIF_AUTO_MIGRATE);
$FULL_SQL  = notifFullSql($muted_groups_sql, $type_filter, $notif_opt);
$LIST_SQL = $FULL_SQL . " ORDER BY t.created_at DESC LIMIT 150";

// ── AJAX: user opened the bell → acknowledge exactly what exists right now ──
dz_collab_handle_request($conn, $uid);   /* collab_respond from the bell's Accept / Decline buttons */

if (isset($_POST['mark_seen'])) {
    $total = notifTotal($conn, $FULL_SQL, $uid);
    try {
        $st = $conn->prepare("INSERT INTO notification_seen (user_id, seen_date, seen_count) VALUES (?, CURDATE(), ?)
                              ON DUPLICATE KEY UPDATE seen_count = VALUES(seen_count), seen_at = NOW()");
        $st->bind_param('ii', $uid, $total);
        $st->execute();
        $st->close();
        $rd = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND DATE(created_at) = CURDATE()");
        $rd->bind_param('i', $uid);
        $rd->execute();
        $rd->close();
    } catch (Throwable $e) { error_log('notif mark_seen error: ' . $e->getMessage()); }
    notifJson(['ok' => true, 'count' => $total, 'unread' => 0]);
}

// ── AJAX: lightweight poll ───────────────────────────────
if (isset($_GET['poll_count'])) {
    $prow  = notifRun($conn, $LIST_SQL, $uid) ?? [];
    $total = count($prow);
    notifJson(['count' => $total, 'unread' => notifUnread($conn, $uid, $prow), 'sig' => notifSig($prow), 'ts' => time()]);
}

// ── AJAX: fresh list HTML (no page reload needed) ────────
if (isset($_GET['fetch_list'])) {
    $rows  = notifRun($conn, $LIST_SQL, $uid);
    if ($rows === null) notifJson(['error' => true]);
    $view  = notifBuildView($rows, $uid);
    $total = count($rows);
    notifJson(['count' => $total, 'unread' => notifUnread($conn, $uid, $rows), 'sig' => notifSig($rows), 'tabs' => $view['tabs'], 'panels' => $view['panels']]);
}

// ── Auto-fix: backfill ref_id on old group_invite rows ───
try {
    $conn->query("
        UPDATE notifications n
        JOIN `groups` g ON TRIM(g.title) = TRIM(SUBSTRING(n.message, LOCATE('Pod: ', n.message) + 5))
        SET n.ref_id = g.id
        WHERE n.type = 'group_invite' AND n.ref_id IS NULL AND n.message LIKE '%Pod: %'
    ");
} catch (Throwable $e) { error_log('notif backfill error: ' . $e->getMessage()); }

// ── First render ─────────────────────────────────────────
$rows         = notifRun($conn, $LIST_SQL, $uid) ?? [];
$notif_count  = count($rows);
$unread_count = notifUnread($conn, $uid, $rows);
$show_badge   = $unread_count > 0;
$view         = notifBuildView($rows, $uid);
?>

<style>
:root { --notif-bg: #070924; }
.notif-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.notif-bell {
    background: transparent; border: none; cursor: pointer;
    color: var(--ui-text, #fff); font-size: 22px;
    display: flex; align-items: center; justify-content: center;
    position: relative; padding: 4px;
    transition: color 0.25s;
    -webkit-tap-highlight-color: transparent;
}
.notif-bell:hover { color: #FF2D2D; }
.notif-bell i { display: inline-block; transform-origin: 50% 12%; line-height: 0; }
/* bell: the original icon, line made 3% thicker (2px stroke × 1.03 ≈ .06px) and 3% dimmer like the Algorithm icon — change --bell-bold to adjust */
.notif-bell { --bell-bold: .06px; }
.notif-bell i { -webkit-text-stroke: var(--bell-bold) currentColor; filter: brightness(.97); }
.notif-bell i.shake { animation: bellShake 2s ease-in-out; }
@keyframes bellShake {
    0%, 100% { transform: rotate(0); }
    8%       { transform: rotate(18deg); }
    18%      { transform: rotate(-16deg); }
    28%      { transform: rotate(14deg); }
    38%      { transform: rotate(-12deg); }
    48%      { transform: rotate(9deg); }
    58%      { transform: rotate(-7deg); }
    68%      { transform: rotate(5deg); }
    78%      { transform: rotate(-3deg); }
    88%      { transform: rotate(1.5deg); }
}
@media (prefers-reduced-motion: reduce) { .notif-bell i.shake { animation: none; } }
.notif-badge {
    position: absolute; top: -2px; right: -4px;
    min-width: 18px; height: 18px;
    background: #FF2D2D; border-radius: 50px;
    border: 1.5px solid var(--notif-bg);
    font-size: 11px; font-weight: 700; color: #000;
    font-family: Arial, Helvetica, sans-serif;
    -webkit-text-stroke: 0.6px #000;
    display: flex; align-items: center; justify-content: center;
    padding: 0 3px; line-height: 1;
    pointer-events: none;
    transition: opacity 0.3s, transform 0.3s;
}
.notif-badge.hidden { opacity: 0; transform: scale(0); pointer-events: none; }

/* ── The box: boxier corners, #070924, taller ── */
.notif-dropdown {
    display: none;
    position: fixed;
    top: 70px; right: 24px;
    width: clamp(310px, 88vw, 400px);
    min-height: min(440px, 62vh);
    max-height: min(660px, 84vh);
    background: var(--notif-bg);
    border-radius: 5px;
    overflow: hidden;
    z-index: 2147483001;
    box-shadow: 0 24px 64px rgba(0,0,0,0.9), 0 0 0 1px rgba(255,255,255,0.08);
    animation: notifIn 0.22s cubic-bezier(0.22,1,0.36,1);
    flex-direction: column;
}
@keyframes notifIn {
    from { opacity:0; transform:translateY(-10px) scale(0.96); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}
.notif-dropdown.open { display: flex; }
.notif-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 16px 9px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    flex-shrink: 0;
}
.notif-header-left { display: flex; align-items: center; gap: 7px; }
.notif-header-title {
    font-size: 13px; font-weight: 800; color: #fff;
    display: flex; align-items: center; gap: 7px;
    font-family: 'Poppins', sans-serif;
}
.notif-header-title i { color: #FF2D2D; font-size: 15px; }
.notif-count-badge {
    background: rgba(255,45,45,0.2); border: 1px solid rgba(255,45,45,0.35);
    color: #ff7a7a; font-size: 10px; font-weight: 700;
    padding: 1px 7px; border-radius: 50px;
}
.notif-header-right { display: flex; align-items: center; gap: 8px; }
.notif-header-date { font-size: 10px; color: rgba(255,255,255,0.3); font-family: 'Poppins', sans-serif; }
.notif-live-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #cc2e2e;
    animation: livePulse 2s ease-in-out infinite;
    flex-shrink: 0;
}
@keyframes livePulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(204,46,46,0.5); opacity: 1; }
    50%      { box-shadow: 0 0 0 4px rgba(204,46,46,0); opacity: 0.7; }
}
.notif-tabs {
    display: flex; align-items: center;
    padding: 8px 10px 0; gap: 2px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0; overflow-x: auto; scrollbar-width: none;
}
.notif-tabs::-webkit-scrollbar { display: none; }
.notif-tab {
    display: flex; align-items: center; gap: 4px;
    padding: 5px 11px 8px;
    font-size: 11px; font-weight: 700;
    color: rgba(255,255,255,0.4);
    background: none; border: none; cursor: pointer;
    font-family: 'Poppins', sans-serif;
    border-bottom: 2px solid transparent;
    transition: 0.2s; white-space: nowrap;
    -webkit-tap-highlight-color: transparent;
}
.notif-tab:hover { color: rgba(255,255,255,0.7); }
.notif-tab.active { color: #fff; border-bottom-color: #fff; }
.notif-tab-count {
    background: rgba(255,255,255,0.09); border-radius: 20px;
    padding: 1px 5px; font-size: 9px; font-weight: 800;
    min-width: 16px; text-align: center;
}
.notif-tab.active .notif-tab-count { background: rgba(255,255,255,0.18); color: #fff; }
.notif-body {
    overflow-y: auto; flex: 1; min-height: 0;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.1) transparent;
}
.notif-body::-webkit-scrollbar { width: 3px; }
.notif-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }
.notif-panel { display: none; }
.notif-panel.active { display: block; }
.notif-section-label {
    padding: 7px 14px 4px;
    font-size: 10px; font-weight: 800; color: rgba(255,255,255,0.28);
    text-transform: uppercase; letter-spacing: 1.2px;
    font-family: 'Poppins', sans-serif;
    background: rgba(255,255,255,0.025);
    border-bottom: 1px solid rgba(255,255,255,0.04);
    display: flex; align-items: center; justify-content: space-between;
}
.notif-section-count {
    background: rgba(255,255,255,0.07);
    border-radius: 20px; padding: 1px 6px;
    font-size: 9px; font-weight: 800;
    color: rgba(255,255,255,0.35);
}
.notif-item {
    display: flex; align-items: center; gap: 11px;
    padding: 10px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    text-decoration: none;
    transition: background 0.15s; cursor: pointer;
    position: relative;
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: rgba(255,255,255,0.05); }
.notif-item:active { background: rgba(255,255,255,0.08); }
.notif-collab-btns{ display:flex; gap:8px; margin-top:8px; }
.notif-collab-btns button{ height:30px; padding:0 16px; border:0; border-radius:5px; font:600 12px inherit; font-family:inherit; cursor:pointer; }
.notif-collab-yes{ background:#f24455; color:#fff; }
.notif-collab-no{ background:rgba(128,128,128,.22); color:inherit; }
.notif-collab-btns button:disabled{ opacity:.5; }
.notif-item.unread::before {
    content: '';
    position: absolute; left: 5px; top: 50%;
    transform: translateY(-50%);
    width: 4px; height: 4px; border-radius: 50%;
    background: #FF2D2D;
}
.notif-avatar-wrap { position: relative; flex-shrink: 0; }
.notif-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    object-fit: cover; border: 2px solid #FF2D2D; display: block;
}
.notif-avatar-stack { position: relative; width: 38px; height: 38px; flex-shrink: 0; }
.notif-avatar-stack .sa-img {
    position: absolute;
    width: 26px; height: 26px; border-radius: 50%;
    object-fit: cover; border: 1.5px solid var(--notif-bg);
}
.notif-avatar-stack .sa-img:nth-child(1) { top: 0; left: 0; z-index: 3; }
.notif-avatar-stack .sa-img:nth-child(2) { bottom: 0; right: 0; z-index: 2; }
.notif-avatar-stack .sa-more {
    position: absolute; bottom: 0; right: 0; z-index: 4;
    width: 18px; height: 18px; border-radius: 50%;
    background: rgba(255,45,45,0.85); border: 1.5px solid var(--notif-bg);
    font-size: 8px; font-weight: 800; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Poppins', sans-serif;
}
.notif-type-icon {
    position: absolute; bottom: -2px; right: -2px;
    width: 16px; height: 16px; border-radius: 50%;
    background: var(--notif-bg); border: 1.5px solid var(--notif-bg);
    display: flex; align-items: center; justify-content: center;
    font-size: 9px;
}
.notif-text { flex: 1; min-width: 0; }
.notif-msg {
    font-size: 12px; color: rgba(255,255,255,0.84);
    line-height: 1.4;
    white-space: normal; overflow: hidden;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    font-family: 'Poppins', sans-serif;
}
.notif-msg strong { color: #fff; font-weight: 700; }
.notif-msg .notif-group-link {
    color: #1a56db; font-weight: 700; text-decoration: none;
    border-bottom: 1px solid rgba(26,86,219,0.5);
    transition: color 0.15s, border-color 0.15s;
    display: inline;
}
.notif-msg .notif-group-link:hover { color: #2563eb; border-bottom-color: rgba(37,99,235,0.8); }
.notif-time {
    font-size: 10px; color: rgba(255,255,255,0.3);
    margin-top: 2px; display: flex; align-items: center; gap: 3px;
    font-family: 'Poppins', sans-serif;
}
.notif-time i { font-size: 9px; }
.notif-empty { text-align: center; padding: 56px 20px; color: rgba(255,255,255,0.25); }
.notif-empty i { font-size: 36px; display: block; margin-bottom: 10px; opacity: 0.25; }
.notif-empty p { font-size: 12px; line-height: 1.6; font-family: 'Poppins', sans-serif; }
.notif-footer {
    padding: 8px 14px; border-top: 1px solid rgba(255,255,255,0.06);
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}
.notif-footer span { font-size: 10px; color: rgba(255,255,255,0.25); font-family: 'Poppins', sans-serif; }
.notif-footer-refresh {
    background: none; border: none; cursor: pointer;
    color: rgba(255,255,255,0.3); font-size: 13px;
    padding: 2px 6px; border-radius: 6px;
    transition: 0.2s; -webkit-tap-highlight-color: transparent;
}
.notif-footer-refresh:hover { color: #fff; background: rgba(255,255,255,0.07); }
.notif-footer-refresh.spinning i { animation: spin 0.6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.notif-seen-strip {
    padding: 5px 14px;
    font-size: 10px; color: rgba(255,255,255,0.55);
    background: rgba(255,255,255,0.05);
    border-bottom: 1px solid rgba(255,255,255,0.1);
    display: flex; align-items: center; gap: 5px;
    font-family: 'Poppins', sans-serif; font-weight: 600;
    flex-shrink: 0;
}
.notif-toast {
    position: fixed; bottom: 90px; left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: var(--notif-bg);
    border: 1px solid rgba(255,45,45,0.4);
    border-radius: 5px; padding: 10px 18px;
    display: flex; align-items: center; gap: 10px;
    z-index: 2147483003;
    font-family: 'Poppins', sans-serif; font-size: 12px; color: #fff;
    box-shadow: 0 12px 40px rgba(0,0,0,0.7);
    opacity: 0; pointer-events: none;
    transition: opacity 0.3s, transform 0.3s;
    white-space: nowrap;
}
.notif-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); pointer-events: all; }
.notif-toast i { color: #FF2D2D; font-size: 15px; }
.notif-toast-close {
    background: none; border: none; cursor: pointer;
    color: rgba(255,255,255,0.4); font-size: 14px; padding: 0 0 0 6px;
    -webkit-tap-highlight-color: transparent;
}
.notif-toast-close:hover { color: #fff; }

.notif-actors-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.6);
    z-index: 2147483002;
    align-items: center; justify-content: center;
}
.notif-actors-overlay.open { display: flex; }
.notif-actors-modal {
    background: var(--notif-bg);
    border-radius: 5px;
    width: clamp(280px, 90vw, 380px);
    max-height: 70vh;
    display: flex; flex-direction: column;
    box-shadow: 0 24px 64px rgba(0,0,0,0.9), 0 0 0 1px rgba(255,255,255,0.08);
    animation: notifIn 0.22s cubic-bezier(0.22,1,0.36,1);
}
.notif-actors-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    font-family: 'Poppins', sans-serif;
    font-size: 13px; font-weight: 800; color: #fff;
}
.notif-actors-close {
    background: none; border: none; cursor: pointer;
    color: rgba(255,255,255,0.5); font-size: 16px;
    padding: 2px 6px; border-radius: 6px; transition: 0.2s;
}
.notif-actors-close:hover { color: #fff; background: rgba(255,255,255,0.08); }
.notif-actors-list {
    overflow-y: auto; flex: 1;
    scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.1) transparent;
}
.notif-actor-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px; text-decoration: none;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    transition: background 0.15s; cursor: pointer;
}
.notif-actor-row:last-child { border-bottom: none; }
.notif-actor-row:hover { background: rgba(255,255,255,0.06); }
.notif-actor-row img {
    width: 36px; height: 36px; border-radius: 50%;
    object-fit: cover; border: 1.5px solid #FF2D2D; flex-shrink: 0;
}
.notif-actor-row span { font-size: 13px; font-weight: 600; color: #fff; font-family: 'Poppins', sans-serif; }

/* ── Normal (desktop) view: black backdrop, bigger + longer card ── */
.notif-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 2147483000; }
.notif-backdrop.open { display: block; }
@keyframes notifInCenter {
    from { opacity: 0; transform: translate(-50%, -47%) scale(0.96); }
    to   { opacity: 1; transform: translate(-50%, -50%) scale(1); }
}
@media (min-width: 769px) {
    .notif-dropdown {
        top: 50%; left: 50%; right: auto; bottom: auto;
        transform: translate(-50%, -50%);
        animation: notifInCenter 0.22s cubic-bezier(0.22,1,0.36,1);
        width: 480px;
        min-height: min(640px, calc(100vh - 96px));
        max-height: min(840px, calc(100vh - 48px));
    }
    .notif-header { padding: 16px 20px 12px; }
    .notif-header-title { font-size: 15px; }
    .notif-header-title i { font-size: 17px; }
    .notif-count-badge { font-size: 11px; padding: 2px 9px; }
    .notif-header-date { font-size: 11px; }
    .notif-tabs { padding: 10px 14px 0; }
    .notif-tab { font-size: 12.5px; padding: 6px 14px 10px; }
    .notif-tab-count { font-size: 10px; }
    .notif-section-label { font-size: 11px; padding: 9px 20px 6px; }
    .notif-seen-strip { font-size: 11px; padding: 7px 20px; }
    .notif-item { gap: 14px; padding: 14px 20px; }
    .notif-avatar { width: 46px; height: 46px; }
    .notif-avatar-stack { width: 46px; height: 46px; }
    .notif-avatar-stack .sa-img { width: 31px; height: 31px; }
    .notif-avatar-stack .sa-more { width: 21px; height: 21px; font-size: 9px; }
    .notif-type-icon { width: 19px; height: 19px; font-size: 11px; }
    .notif-msg { font-size: 14px; }
    .notif-time { font-size: 11.5px; margin-top: 3px; }
    .notif-empty { padding: 90px 24px; }
    .notif-empty i { font-size: 44px; }
    .notif-empty p { font-size: 14px; }
    .notif-footer { padding: 11px 20px; }
    .notif-footer span { font-size: 11.5px; }
}

/* ─── 768px ─── */
@media (max-width: 768px) {
    .notif-dropdown {
        position: fixed; top: auto; bottom: 70px;
        right: 10px; left: 10px; width: auto;
        min-height: 50vh; max-height: 78vh;
        border-radius: 5px;
    }
    .notif-bell { font-size: 20px; }
    .notif-badge { top: -3px; right: -3px; min-width: 17px; height: 17px; font-size: 10px; }
    .notif-header { padding: 11px 14px 8px; }
    .notif-header-title { font-size: 12px; gap: 6px; }
    .notif-tab { font-size: 10px; padding: 5px 10px 7px; }
    .notif-item { gap: 10px; padding: 9px 13px; }
    .notif-avatar { width: 35px; height: 35px; }
    .notif-avatar-stack { width: 35px; height: 35px; }
    .notif-avatar-stack .sa-img { width: 24px; height: 24px; }
    .notif-msg { font-size: 11.5px; }
    .notif-time { font-size: 9.5px; }
    .notif-toast { font-size: 11.5px; padding: 9px 16px; bottom: 80px; }
}
/* ─── 480px ─── */
@media (max-width: 480px) {
    .notif-dropdown { bottom: 65px; max-height: 76vh; }
    .notif-header { padding: 10px 12px 7px; }
    .notif-tab { font-size: 10.5px; padding: 4px 9px 7px; }
    .notif-item { gap: 9px; padding: 9px 12px; }
    .notif-avatar { width: 34px; height: 34px; }
    .notif-avatar-stack { width: 33px; height: 33px; }
    .notif-avatar-stack .sa-img { width: 22px; height: 22px; }
    .notif-avatar-stack .sa-more { width: 16px; height: 16px; font-size: 7px; }
    .notif-type-icon { width: 14px; height: 14px; font-size: 7.5px; }
    .notif-time { font-size: 9px; margin-top: 1px; }
}
/* ─── 360px ─── */
@media (max-width: 360px) {
    .notif-dropdown { bottom: 58px; max-height: 74vh; }
    .notif-bell { font-size: 18px; }
    .notif-header-date { display: none; }
    .notif-tab { font-size: 10px; padding: 4px 7px 6px; }
    .notif-item { gap: 8px; padding: 7px 11px; }
    .notif-avatar { width: 30px; height: 30px; }
    .notif-avatar-stack { width: 31px; height: 31px; }
    .notif-avatar-stack .sa-img { width: 21px; height: 21px; }
    .notif-msg { font-size: 11px; }
    .notif-toast { font-size: 10.5px; padding: 8px 12px; bottom: 70px; }
}
/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════ */
:root[data-theme="light"] { --notif-bg: #ffffff; }
[data-theme="light"] .notif-bell { color: var(--ui-text, #0b0d24); }
[data-theme="light"] .notif-bell:hover { color: #FF2D2D; }
[data-theme="light"] .notif-badge { border-color: var(--lane, #fff); }
[data-theme="light"] .notif-backdrop { background: rgba(0,0,0,0.45); }
[data-theme="light"] .notif-dropdown,
[data-theme="light"] .notif-actors-modal { box-shadow: 0 24px 64px rgba(0,0,0,0.22), 0 0 0 1px rgba(6,7,26,0.1); }
[data-theme="light"] .notif-actors-overlay { background: rgba(0,0,0,0.4); }
[data-theme="light"] .notif-header,
[data-theme="light"] .notif-actors-header { border-bottom-color: rgba(6,7,26,0.09); }
[data-theme="light"] .notif-header-title,
[data-theme="light"] .notif-actors-header,
[data-theme="light"] .notif-msg strong,
[data-theme="light"] .notif-actor-row span,
[data-theme="light"] .notif-toast { color: #0b0d24; }
[data-theme="light"] .notif-count-badge { color: #d42525; background: rgba(255,45,45,0.12); border-color: rgba(255,45,45,0.3); }
[data-theme="light"] .notif-header-date,
[data-theme="light"] .notif-time,
[data-theme="light"] .notif-footer span { color: #5f647a; }
[data-theme="light"] .notif-tabs { border-bottom-color: rgba(6,7,26,0.09); }
[data-theme="light"] .notif-tab { color: #5f647a; }
[data-theme="light"] .notif-tab:hover { color: #0b0d24; }
[data-theme="light"] .notif-tab.active { color: #0b0d24; border-bottom-color: #0b0d24; }
[data-theme="light"] .notif-tab-count { background: rgba(6,7,26,0.08); }
[data-theme="light"] .notif-tab.active .notif-tab-count { background: rgba(6,7,26,0.14); color: #0b0d24; }
[data-theme="light"] .notif-body,
[data-theme="light"] .notif-actors-list { scrollbar-color: rgba(6,7,26,0.2) transparent; }
[data-theme="light"] .notif-body::-webkit-scrollbar-thumb { background: rgba(6,7,26,0.2); }
[data-theme="light"] .notif-section-label { color: #5f647a; background: rgba(6,7,26,0.035); border-bottom-color: rgba(6,7,26,0.06); }
[data-theme="light"] .notif-section-count { background: rgba(6,7,26,0.08); color: #42465a; }
[data-theme="light"] .notif-item,
[data-theme="light"] .notif-actor-row { border-bottom-color: rgba(6,7,26,0.07); }
[data-theme="light"] .notif-item:hover,
[data-theme="light"] .notif-actor-row:hover { background: rgba(6,7,26,0.05); }
[data-theme="light"] .notif-item:active { background: rgba(6,7,26,0.09); }
[data-theme="light"] .notif-msg { color: rgba(11,13,36,0.86); }
[data-theme="light"] .notif-empty { color: #5f647a; }
[data-theme="light"] .notif-empty i { opacity: 0.4; }
[data-theme="light"] .notif-footer { border-top-color: rgba(6,7,26,0.09); }
[data-theme="light"] .notif-footer-refresh { color: #5f647a; }
[data-theme="light"] .notif-footer-refresh:hover { color: #0b0d24; background: rgba(6,7,26,0.07); }
[data-theme="light"] .notif-seen-strip { color: #42465a; background: rgba(6,7,26,0.045); border-bottom-color: rgba(6,7,26,0.09); }
[data-theme="light"] .notif-toast { box-shadow: 0 12px 40px rgba(0,0,0,0.2); }
[data-theme="light"] .notif-toast-close,
[data-theme="light"] .notif-actors-close { color: #5f647a; }
[data-theme="light"] .notif-toast-close:hover,
[data-theme="light"] .notif-actors-close:hover { color: #0b0d24; }
[data-theme="light"] .notif-actors-close:hover { background: rgba(6,7,26,0.07); }
[data-theme="light"] .notif-avatar-stack .sa-more { color: #fff; }
</style>

<!-- Toast -->
<div class="notif-toast" id="notifToast">
    <i class="ri-notification-3-fill"></i>
    <span id="notifToastMsg">You have new activity</span>
    <button class="notif-toast-close" onclick="dismissNotifToast()">✕</button>
</div>

<!-- Grouped-people modal -->
<div class="notif-actors-overlay" id="notifActorsOverlay" onclick="closeNotifActorsModal(event)">
    <div class="notif-actors-modal" onclick="event.stopPropagation()">
        <div class="notif-actors-header">
            <span id="notifActorsTitle">Activity</span>
            <button class="notif-actors-close" onclick="closeNotifActorsModal()">✕</button>
        </div>
        <div class="notif-actors-list" id="notifActorsList"></div>
    </div>
</div>

<!-- Black backdrop (desktop) -->
<div class="notif-backdrop" id="notifBackdrop"></div>

<div class="notif-wrap" id="notifWrap">

    <button class="notif-bell" id="notifBell" title="Notifications" onclick="toggleNotif(event)">
        <i class="ri-notification-2-line"></i>
        <span class="notif-badge <?= $show_badge ? '' : 'hidden' ?>" id="notifBadge"><?= $unread_count > 99 ? '99+' : $unread_count ?></span>
    </button>

    <div class="notif-dropdown" id="notifDropdown">

        <div class="notif-header">
            <div class="notif-header-left">
                <div class="notif-header-title">
                    <i class="ri-notification-3-fill"></i>
                    Activity
                    <span class="notif-count-badge" id="notifHeaderCount" style="<?= $notif_count > 0 ? '' : 'display:none;' ?>"><?= $notif_count ?></span>
                </div>
                <div class="notif-live-dot" id="notifLiveDot" title="Live updates on"></div>
            </div>
            <div class="notif-header-right">
                <span class="notif-header-date"><?= date('M j, Y') ?></span>
            </div>
        </div>

        <div class="notif-seen-strip" id="notifSeenStrip" style="<?= (!$show_badge && $notif_count > 0) ? '' : 'display:none;' ?>">
            <i class="ri-checkbox-circle-fill"></i> All caught up today
        </div>

        <div class="notif-tabs" id="notifTabs"><?= $view['tabs'] ?></div>

        <div class="notif-body" id="notifBody"><?= $view['panels'] ?></div>

        <div class="notif-footer">
            <span id="notifFooterText">Resets daily at midnight &middot; <?= $notif_count ?> event<?= $notif_count !== 1 ? 's' : '' ?> today</span>
            <button class="notif-footer-refresh" id="notifRefreshBtn" onclick="manualRefreshNotif()" title="Refresh">
                <i class="ri-refresh-line"></i>
            </button>
        </div>

    </div>
</div>

<script>
(function () {

    /*
     * LIVE UPDATES
     * ────────────
     * • Every 10s (and instantly on tab focus / reconnect / opening the bell) the server returns
     *   count + a fingerprint of the whole list. If the fingerprint changed, the list is reloaded
     *   in the background – so it is already up to date when you open it. No page refresh needed.
     * • Times come from the database clock (age in seconds) and tick forward live every 15s.
     *
     *   unread = events newer than the last time the bell was opened (computed by the server)
     */

    var START = '@@NOTIF_START@@', END = '@@NOTIF_END@@';
    var _unread = <?= (int)$unread_count ?>;
    var _count  = <?= (int)$notif_count ?>;
    var _sig    = '<?= notifSig($rows) ?>';
    var _listAt = Date.now();
    var _open = false, _busy = false, _polling = true;

    /* Move the card, backdrop, toast and modal to <body> so no header / nav stacking context can
     * ever sit above the black backdrop. */
    ['notifBackdrop', 'notifDropdown', 'notifToast', 'notifActorsOverlay'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });

    function setOpen(v) {
        _open = v;
        var dd = document.getElementById('notifDropdown'), bd = document.getElementById('notifBackdrop');
        if (dd) dd.classList.toggle('open', v);
        if (bd) bd.classList.toggle('open', v);
    }
    var _activeTab = 'all';
    var _pollTimer = null, _toastTimer = null, _busyTimer = null;
    var POLL_INTERVAL = 10000;
    var PAGE_URL = window.location.href.split('#')[0].split('?')[0] + '?user_id=<?= $uid ?>';

    function byId(id) { return document.getElementById(id); }

    /* Fetch JSON even if the host page printed HTML before our response */
    function getJson(url, opts) {
        opts = opts || {};
        opts.cache = 'no-store';
        return fetch(url, opts).then(function (r) { return r.text(); }).then(function (t) {
            var a = t.lastIndexOf(START), b = t.lastIndexOf(END);
            if (a < 0 || b < 0) throw new Error('bad response');
            return JSON.parse(t.substring(a + START.length, b));
        });
    }

    /* ── Live-ticking relative times ── */
    function agoLabel(s) {
        s = Math.max(0, Math.floor(s));
        if (s < 60) return 'just now';
        var m = Math.floor(s / 60); if (m < 60) return m + 'm ago';
        var h = Math.floor(m / 60); if (h < 24) return h + 'h ago';
        var d = Math.floor(h / 24); if (d < 7)  return d + 'd ago';
        return Math.floor(d / 7) + 'w ago';
    }
    function tickTimes() {
        var extra = (Date.now() - _listAt) / 1000;
        document.querySelectorAll('.notif-ago').forEach(function (el) {
            var age = parseInt(el.getAttribute('data-age'), 10);
            if (!isNaN(age)) el.textContent = agoLabel(age + extra);
        });
    }

    /* ── Badge / header / strip / footer ── */
    function paint(count, unread) {
        _count = count; _unread = unread;
        var badge = byId('notifBadge'), hdr = byId('notifHeaderCount'),
            strip = byId('notifSeenStrip'), foot = byId('notifFooterText');
        if (badge) {
            if (unread > 0) { badge.textContent = unread > 99 ? '99+' : unread; badge.classList.remove('hidden'); }
            else { badge.classList.add('hidden'); }
        }
        if (hdr)   { hdr.textContent = count; hdr.style.display = count > 0 ? '' : 'none'; }
        if (strip) { strip.style.display = (unread === 0 && count > 0) ? '' : 'none'; }
        if (foot)  { foot.innerHTML = 'Resets daily at midnight &middot; ' + count + ' event' + (count !== 1 ? 's' : '') + ' today'; }
    }

    /* ── Tabs ── */
    function selectTab(key) {
        var btn = document.querySelector('.notif-tab[data-key="' + key + '"]') || document.querySelector('.notif-tab');
        if (!btn) return;
        key = btn.getAttribute('data-key');
        _activeTab = key;
        document.querySelectorAll('.notif-tab').forEach(function (t) { t.classList.remove('active'); });
        document.querySelectorAll('.notif-panel').forEach(function (p) { p.classList.remove('active'); });
        btn.classList.add('active');
        var panel = byId('notif-panel-' + key);
        if (panel) panel.classList.add('active');
    }
    window.switchNotifTab = function (key) { selectTab(key); };

    /* ── Acknowledge everything that exists right now ── */
    /* Accept / Decline a collaboration invite right from the bell */
    window.dzNotifCollab = function (memeId, accept, btn) {
        var row = btn.closest('.notif-item'); if (row) row.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        fetch(PAGE_URL, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=collab_respond&meme_id=' + memeId + '&accept=' + (accept ? 1 : 0)
        }).then(function (r) { return r.text(); }).then(function (t) {
            var i = t.indexOf('{"status"'); if (i < 0) throw new Error('bad response');
            return JSON.parse(t.substring(i));
        }).then(function (d) {
            if (d && d.status === 'ok') {
                var msg = row && row.querySelector('.notif-msg');
                var box = row && row.querySelector('.notif-collab-btns');
                if (box) box.innerHTML = '<span style="font-size:12px;opacity:.7">' + (accept ? 'You are now a collaborator. The post is on your profile.' : 'Declined') + '</span>';
            } else {
                if (row) row.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
                showNotifToast((d && d.message) || 'Could not answer this invite.');
            }
        }).catch(function () { if (row) row.querySelectorAll('button').forEach(function (b) { b.disabled = false; }); });
    };

    function markSeen() {
        paint(_count, 0);
        getJson(PAGE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'mark_seen=1'
        }).then(function (d) {
            if (d && typeof d.count === 'number') paint(d.count, 0);
        }).catch(function () {});
    }

    /* ── Reload the list in place ── */
    function refreshList() {
        return getJson(PAGE_URL + '&fetch_list=1').then(function (d) {
            if (!d || d.error) return;
            byId('notifTabs').innerHTML = d.tabs;
            byId('notifBody').innerHTML = d.panels;
            _sig = d.sig;
            _listAt = Date.now();
            selectTab(_activeTab);
            if (_open) {
                paint(d.count, 0);
                if (d.unread > 0) markSeen();
            } else {
                paint(d.count, d.unread);
            }
        }).catch(function () {});
    }

    /* ── Open / close ── */
    window.toggleNotif = function (e) {
        e.stopPropagation();
        var dd = byId('notifDropdown');
        setOpen(!_open);
        if (_open) {
            if (_unread > 0) markSeen();
            poll();               // grab anything newer right now
            tickTimes();
        }
    };
    document.addEventListener('click', function (e) {
        var wrap = byId('notifWrap'), dd = byId('notifDropdown');
        var ov = byId('notifActorsOverlay');
        if (dd && wrap && !wrap.contains(e.target) && !dd.contains(e.target) && !(ov && ov.contains(e.target))) { setOpen(false); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var dd = byId('notifDropdown');
        setOpen(false);
        window.closeNotifActorsModal();
    });

    /* ── Live dot ── */
    function setLiveDot(active) {
        var dot = byId('notifLiveDot');
        if (dot) dot.style.background = active ? '#cc2e2e' : 'rgba(127,127,140,0.45)';
    }

    /* ── Bell shake (2 seconds) ── */
    var _shakeTimer = null;
    function shakeBell() {
        var ic = document.querySelector('#notifBell i');
        if (!ic) return;
        ic.classList.remove('shake');
        void ic.offsetWidth;              // restart the animation if it is already running
        ic.classList.add('shake');
        clearTimeout(_shakeTimer);
        _shakeTimer = setTimeout(function () { ic.classList.remove('shake'); }, 2000);
    }

    /* ── Toast ── */
    function showNotifToast(msg) {
        var toast = byId('notifToast'), msgEl = byId('notifToastMsg');
        if (!toast) return;
        if (msgEl) msgEl.textContent = msg || 'You have new activity';
        toast.classList.add('show');
        clearTimeout(_toastTimer);
        _toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 4500);
    }
    window.dismissNotifToast = function () {
        var toast = byId('notifToast');
        if (toast) toast.classList.remove('show');
        clearTimeout(_toastTimer);
    };

    /* ── "A, B and N others" modal ── */
    window.openNotifActorsModal = function (actors, actionLabel) {
        var overlay = byId('notifActorsOverlay'), list = byId('notifActorsList'), title = byId('notifActorsTitle');
        if (!overlay || !list) return;
        var count = Array.isArray(actors) ? actors.length : 0;
        title.textContent = count + (count === 1 ? ' person ' : ' people ') + actionLabel;
        list.innerHTML = '';
        (actors || []).forEach(function (a) {
            var row = document.createElement('a');
            row.className = 'notif-actor-row';
            row.href = 'profile.php?user_id=' + encodeURIComponent(a.actor_id);
            var img = document.createElement('img');
            img.src = a.profile_pic || 'uploads/me.png';
            img.onerror = function () { this.src = 'uploads/me.png'; };
            img.alt = a.username || 'User';
            var span = document.createElement('span');
            span.textContent = a.username || 'Someone';
            row.appendChild(img);
            row.appendChild(span);
            list.appendChild(row);
        });
        overlay.classList.add('open');
    };
    window.closeNotifActorsModal = function () {
        var overlay = byId('notifActorsOverlay');
        if (overlay) overlay.classList.remove('open');
    };

    /* ── Poll ── */
    function poll() {
        if (!_polling || _busy) return;
        _busy = true;
        clearTimeout(_busyTimer);
        _busyTimer = setTimeout(function () { _busy = false; }, 15000);   // never get stuck
        getJson(PAGE_URL + '&poll_count=1').then(function (data) {
            _busy = false;
            setLiveDot(true);
            var newCount  = data.count || 0;
            var newUnread = data.unread || 0;
            var prevUnread = _unread;

            if (_open) {
                paint(newCount, 0);
                if (newUnread > 0) markSeen();
            } else {
                paint(newCount, newUnread);
                if (newUnread > prevUnread) {
                    var diff = newUnread - prevUnread;
                    var msg = diff === 1 ? 'You have 1 new notification' : 'You have ' + diff + ' new notifications';
                    shakeBell();
                    showNotifToast(msg);
                    sendBrowserNotif(msg);
                }
            }
            if (data.sig && data.sig !== _sig) refreshList();   // list changed → reload quietly
        }).catch(function () { _busy = false; setLiveDot(false); });
    }

    /* ── Browser push ── */
    function sendBrowserNotif(msg) {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'granted') {
            new Notification('Dezzme Activity', {
                body: msg, icon: '/Peura/PEURA.jpg', badge: '/Peura/PEURA.jpg', tag: 'dezzme-notif', silent: false
            });
        } else if (Notification.permission !== 'denied') {
            Notification.requestPermission().then(function (perm) { if (perm === 'granted') sendBrowserNotif(msg); });
        }
    }

    /* ── Manual refresh (in place) ── */
    window.manualRefreshNotif = function () {
        var btn = byId('notifRefreshBtn');
        if (btn) btn.classList.add('spinning');
        refreshList().then(function () {
            setTimeout(function () { if (btn) btn.classList.remove('spinning'); }, 400);
        });
    };

    function maybeRequestPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            setTimeout(function () { Notification.requestPermission(); }, 3000);
        }
    }

    /* ── Pause when hidden, catch up instantly when visible ── */
    function startPolling() {
        clearInterval(_pollTimer);
        _pollTimer = setInterval(poll, POLL_INTERVAL);
    }
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            _polling = false;
            clearInterval(_pollTimer);
            setLiveDot(false);
        } else {
            _polling = true;
            poll();
            startPolling();
            tickTimes();
        }
    });
    window.addEventListener('focus', function () { poll(); tickTimes(); });
    window.addEventListener('online', function () { poll(); });

    /* ── Start ── */
    startPolling();
    setInterval(tickTimes, 15000);
    setTimeout(poll, 2000);
    if (_unread > 0) setTimeout(shakeBell, 600);
    maybeRequestPermission();

})();
</script>

<?php endif; ?>