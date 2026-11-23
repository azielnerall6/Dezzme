<?php
/* ════════════════════════════════════════════════════════════════
   group_setup.php — one-time database index setup for group.php

   • Runs on the first group-page request, then writes group_schema_v1.flag.
     After that group.php only does one file_exists() per request.
   • To run it again (e.g. after adding tables): delete group_schema_v1.flag.
   • An index is only added when no existing index already starts with those
     columns, so nothing is duplicated. Tables / columns that don't exist on
     your install are skipped silently.
   ════════════════════════════════════════════════════════════════ */
if (!isset($conn) || !($conn instanceof mysqli)) return;

$gp_wanted = [
    // table                      => [ [columns...], ... ]
    'group_members'               => [['group_id','user_id'], ['user_id','group_id']],
    'group_posts'                 => [['group_id','created_at'], ['meme_id'], ['group_id','posted_by']],
    'group_admins'                => [['group_id','user_id']],
    'group_bans'                  => [['group_id','banned_id']],
    'group_join_requests'         => [['group_id','status'], ['group_id','user_id']],
    'group_post_reports'          => [['group_id','status'], ['group_post_id']],
    'group_views'                 => [['group_id','viewer_id','viewed_at']],
    'group_hashtags'              => [['group_id']],
    'group_name_history'          => [['group_id']],
    'group_files'                 => [['group_id','created_at']],
    'group_file_saves'            => [['user_id','file_id']],
    'group_events'                => [['group_id','event_date']],
    'group_highlights'            => [['group_id','created_at']],
    'group_highlight_media'       => [['highlight_id']],
    'group_highlight_polls'       => [['highlight_id']],
    'group_suggestion_dismissed'  => [['user_id']],
    'group_search_recents'        => [['user_id','group_id']],
    'likes'                       => [['meme_id'], ['user_id','meme_id']],
    'reposts'                     => [['meme_id'], ['user_id','meme_id']],
    'favorites'                   => [['meme_id'], ['user_id','meme_id']],
    'downloads'                   => [['meme_id']],
    'shares'                      => [['meme_id']],
    'followers'                   => [['follower_id','following_id'], ['following_id']],
    'blocks'                      => [['blocker_id','blocked_id']],
    'muted_users'                 => [['muter_id']],
    'meme_media'                  => [['meme_id','position']],
    'meme_audio'                  => [['meme_id']],
    'audios'                      => [['source_meme_id']],
    'pod_discussions'             => [['group_id']],
    'pod_discussion_replies'      => [['discussion_id','created_at']],
    'groups'                      => [['category']],
];

$gp_ok = true;
foreach ($gp_wanted as $gp_t => $gp_sets) {
    try {
        $gp_res = $conn->query("SHOW INDEX FROM `$gp_t`");
        if (!$gp_res) continue;                       // table doesn't exist → skip
        $gp_idx = [];                                 // index name => [col1, col2, ...] in order
        while ($gp_r = $gp_res->fetch_assoc()) {
            $gp_idx[$gp_r['Key_name']][(int)$gp_r['Seq_in_index']] = $gp_r['Column_name'];
        }
        foreach ($gp_sets as $gp_cols) {
            $gp_has = false;
            foreach ($gp_idx as $gp_c) {
                ksort($gp_c);
                if (array_slice(array_values($gp_c), 0, count($gp_cols)) === $gp_cols) { $gp_has = true; break; }
            }
            if ($gp_has) continue;
            $gp_name = 'gp_idx_' . substr(md5($gp_t . implode(',', $gp_cols)), 0, 10);
            $gp_list = '`' . implode('`,`', $gp_cols) . '`';
            try { $conn->query("ALTER TABLE `$gp_t` ADD INDEX `$gp_name` ($gp_list)"); }
            catch (Throwable $e) { /* column missing or index too big → skip this one */ }
        }
    } catch (Throwable $e) { /* table missing → skip */ }
}

@file_put_contents(__DIR__ . '/group_schema_v1.flag', date('c'));