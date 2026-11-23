<?php
/* ══════════════════════════════════════════════════════════════
   account_purge.php  –  removes a deleted account for good
   (library; written against the `dezzmee` database dump of 5 Nov 2026)

   dz_purge_due($conn)           purge every account whose 30-day grace has ended
   dz_purge_user($conn, $uid)    purge one account right now (no grace check)

   What goes: the account row, everything it posted (memes, long-form videos, highlights,
   stories, shop, products, collections, sounds, audios, files, discussions), everything it
   did on other people's content (comments, likes, reposts, replies, views, messages...),
   its settings, sessions, reports and notifications, and the uploaded files on disk.
   Pods the user created are handed to another admin/member; if nobody else is in the Pod
   it is deleted with everything inside it.
   Tables that are not named below are still swept: any table with an unambiguous
   user column (user_id, sender_id, follower_id ...) loses that user's rows.
══════════════════════════════════════════════════════════════ */

/* ---------- schema cache ---------- */
function dz_pg_schema($conn): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    $r = $conn->query("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()");
    while ($r && ($row = $r->fetch_assoc())) $c[strtolower($row['t'])][strtolower($row['c'])] = true;
    return $c;
}
function dz_pg_has($conn, string $t, string $col = ''): bool {
    $s = dz_pg_schema($conn); $t = strtolower($t);
    return isset($s[$t]) && ($col === '' || isset($s[$t][strtolower($col)]));
}
function dz_pg_in(array $ids): string {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return '(' . ($ids ? implode(',', $ids) : '0') . ')';
}
/* ids of rows matching `col IN vals` */
function dz_pg_ids($conn, string $t, string $col, $vals, string $pk = 'id'): array {
    $vals = (array)$vals;
    if (!$vals || !dz_pg_has($conn, $t, $col) || !dz_pg_has($conn, $t, $pk)) return [];
    $out = [];
    foreach (array_chunk($vals, 500) as $ch) {
        $r = $conn->query("SELECT `$pk` FROM `$t` WHERE `$col` IN " . dz_pg_in($ch));
        while ($r && ($row = $r->fetch_row())) $out[] = (int)$row[0];
    }
    return $out;
}
function dz_pg_del($conn, string $t, string $col, $vals): void {
    $vals = (array)$vals;
    if (!$vals || !dz_pg_has($conn, $t, $col)) return;
    foreach (array_chunk($vals, 500) as $ch) $conn->query("DELETE FROM `$t` WHERE `$col` IN " . dz_pg_in($ch));
}
function dz_pg_null($conn, string $t, string $col, $vals): void {
    $vals = (array)$vals;
    if (!$vals || !dz_pg_has($conn, $t, $col)) return;
    foreach (array_chunk($vals, 500) as $ch) $conn->query("UPDATE `$t` SET `$col` = NULL WHERE `$col` IN " . dz_pg_in($ch));
}

/* ---------- files ---------- */
function dz_pg_resolve(string $v): ?string {
    $v = trim(str_replace('\\', '/', $v));
    if ($v === '' || strlen($v) > 300 || strpos($v, '..') !== false || preg_match('#^[a-z][a-z0-9+.-]*:#i', $v)) return null;
    if (!preg_match('/\.[a-z0-9]{2,5}$/i', $v) || preg_match('/\.(php|phtml|phar|ini|htaccess|env|js|css)$/i', $v)) return null;
    $v = ltrim($v, '/');
    if (stripos(basename($v), 'default') !== false || strcasecmp(basename($v), 'Text.jpg') === 0) return null;
    $root = realpath(__DIR__ . '/uploads');
    if (!$root) return null;
    $cands = [$v];
    if (strpos($v, '/') === false) {
        foreach (['uploads/', 'uploads/memes/', 'uploads/audios/', 'uploads/audio/', 'uploads/sounds/', 'uploads/pod_files/', 'uploads/group_files/', 'uploads/messages/', 'uploads/covers/', 'uploads/shop/'] as $p) $cands[] = $p . $v;
    }
    foreach ($cands as $c) {
        $abs = realpath(__DIR__ . '/' . $c);
        if ($abs && is_file($abs) && strpos($abs, $root . DIRECTORY_SEPARATOR) === 0) return $abs;
    }
    return null;
}
/* collect file paths from `cols` of rows matching `col IN vals` */
function dz_pg_collect($conn, array &$files, string $t, string $col, $vals, array $cols): void {
    $vals = (array)$vals;
    if (!$vals || !dz_pg_has($conn, $t, $col)) return;
    $cols = array_values(array_filter($cols, fn($c) => dz_pg_has($conn, $t, $c)));
    if (!$cols) return;
    $sel = '`' . implode('`,`', $cols) . '`';
    foreach (array_chunk($vals, 500) as $ch) {
        $r = $conn->query("SELECT $sel FROM `$t` WHERE `$col` IN " . dz_pg_in($ch));
        while ($r && ($row = $r->fetch_row())) foreach ($row as $v) {
            if (!is_string($v) || $v === '') continue;
            $abs = dz_pg_resolve($v);
            if ($abs) $files[$abs] = true;
        }
    }
}

/* ---------- Pods the user created ---------- */
function dz_pg_pods($conn, int $U, array &$files): void {
    if (!dz_pg_has($conn, 'groups', 'created_by')) return;
    $gids = dz_pg_ids($conn, 'groups', 'created_by', [$U]);
    $dead = [];
    foreach ($gids as $g) {
        $new = 0;
        if (dz_pg_has($conn, 'group_admins', 'user_id')) {
            $r = $conn->query("SELECT user_id FROM group_admins WHERE group_id = $g AND user_id <> $U ORDER BY appointed_at ASC, id ASC LIMIT 1");
            if ($r && ($row = $r->fetch_row())) $new = (int)$row[0];
        }
        if (!$new && dz_pg_has($conn, 'group_members', 'user_id')) {
            $r = $conn->query("SELECT user_id FROM group_members WHERE group_id = $g AND user_id <> $U ORDER BY 1 ASC LIMIT 1");
            if ($r && ($row = $r->fetch_row())) $new = (int)$row[0];
        }
        if ($new) {
            $conn->query("UPDATE `groups` SET created_by = $new WHERE id = $g");
            if (dz_pg_has($conn, 'group_admins', 'appointed_by')) {
                $conn->query("INSERT INTO group_admins (group_id, user_id, appointed_by) SELECT $g, $new, $new FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM group_admins WHERE group_id = $g AND user_id = $new)");
            }
        } else $dead[] = $g;
    }
    if (!$dead) return;

    /* Pod with nobody else in it: remove all of it */
    dz_pg_collect($conn, $files, 'groups', 'id', $dead, ['group_pic', 'banner_pic']);
    dz_pg_collect($conn, $files, 'group_files', 'group_id', $dead, ['stored_name']);
    dz_pg_collect($conn, $files, 'group_highlight_media', 'group_id', $dead, ['file_path', 'audio_path']);
    dz_pg_collect($conn, $files, 'group_highlights', 'group_id', $dead, ['cover_path', 'song_path']);

    $hl   = dz_pg_ids($conn, 'group_highlights', 'group_id', $dead);
    $cm   = dz_pg_ids($conn, 'group_highlight_comments', 'group_id', $dead);
    $md   = dz_pg_ids($conn, 'group_highlight_media', 'group_id', $dead);
    $pl   = dz_pg_ids($conn, 'group_highlight_polls', 'group_id', $dead);
    $ev   = dz_pg_ids($conn, 'group_events', 'group_id', $dead);
    $evc  = dz_pg_ids($conn, 'group_event_comments', 'event_id', $ev);
    $fl   = dz_pg_ids($conn, 'group_files', 'group_id', $dead);
    $gp   = dz_pg_ids($conn, 'group_posts', 'group_id', $dead);
    $ds   = dz_pg_ids($conn, 'pod_discussions', 'group_id', $dead);
    $jq   = dz_pg_ids($conn, 'group_join_questions', 'group_id', $dead);

    dz_pg_del($conn, 'group_highlight_comment_likes', 'comment_id', $cm);
    dz_pg_del($conn, 'group_highlight_comment_dislikes', 'comment_id', $cm);
    dz_pg_del($conn, 'group_highlight_likes', 'highlight_id', $hl);
    dz_pg_del($conn, 'group_highlight_poll_votes', 'poll_id', $pl);
    dz_pg_del($conn, 'group_highlight_poll_options', 'poll_id', $pl);
    dz_pg_del($conn, 'group_highlight_polls', 'id', $pl);
    dz_pg_del($conn, 'group_event_comment_likes', 'comment_id', $evc);
    dz_pg_del($conn, 'group_event_comments', 'event_id', $ev);
    dz_pg_del($conn, 'group_event_rsvps', 'event_id', $ev);
    dz_pg_del($conn, 'group_file_reports', 'file_id', $fl);
    dz_pg_del($conn, 'group_file_saves', 'file_id', $fl);
    dz_pg_del($conn, 'group_reactions', 'group_post_id', $gp);
    dz_pg_del($conn, 'group_join_answers', 'question_id', $jq);
    foreach (['pod_discussion_replies', 'pod_likes', 'pod_reactions', 'pod_poll_options', 'pod_poll_votes', 'pod_discussion_media', 'pod_discussion_reports'] as $t)
        dz_pg_del($conn, $t, 'discussion_id', $ds);

    /* every remaining table that carries a group_id column */
    foreach (dz_pg_schema($conn) as $t => $cols) {
        if ($t === 'groups' || !isset($cols['group_id'])) continue;
        dz_pg_del($conn, $t, 'group_id', $dead);
    }
    dz_pg_del($conn, 'groups', 'id', $dead);
}

/* ---------- the purge ---------- */
function dz_purge_user($conn, int $U): bool {
    if ($U <= 0) return false;
    @set_time_limit(0);
    $files = [];
    $S = dz_pg_schema($conn);

    /* ---- content ids ---- */
    $memes = dz_pg_ids($conn, 'memes', 'uploaded_by', [$U]);
    $lfp   = dz_pg_ids($conn, 'longform_posts', 'user_id', [$U]);
    $hls   = dz_pg_ids($conn, 'highlights', 'user_id', [$U]);
    $stor  = dz_pg_ids($conn, 'stories', 'user_id', [$U]);
    $prod  = dz_pg_ids($conn, 'products', 'user_id', [$U]);
    $shops = dz_pg_ids($conn, 'shops', 'user_id', [$U]);
    $cols_ = dz_pg_ids($conn, 'collections', 'user_id', [$U]);
    $aud   = dz_pg_ids($conn, 'audios', 'uploader_id', [$U]);
    $snd   = dz_pg_ids($conn, 'sounds', 'user_id', [$U]);
    $sfold = dz_pg_ids($conn, 'sound_folders', 'user_id', [$U]);
    $disc  = dz_pg_ids($conn, 'pod_discussions', 'user_id', [$U]);
    $msgs  = array_merge(dz_pg_ids($conn, 'messages', 'sender_id', [$U]), dz_pg_ids($conn, 'messages', 'receiver_id', [$U]));
    $gcg   = dz_pg_ids($conn, 'msg_gc_groups', 'owner_id', [$U]);
    $msg_  = dz_pg_ids($conn, 'ms_groups', 'owner_id', [$U]);
    $fam   = array_merge(dz_pg_ids($conn, 'family_links', 'parent_id', [$U]), dz_pg_ids($conn, 'family_links', 'teen_id', [$U]));
    $lfc   = array_unique(array_merge(dz_pg_ids($conn, 'longform_comments', 'user_id', [$U]), dz_pg_ids($conn, 'longform_comments', 'post_id', $lfp)));
    $stre  = array_unique(array_merge(dz_pg_ids($conn, 'story_replies', 'sender_id', [$U]), dz_pg_ids($conn, 'story_replies', 'story_id', $stor)));
    $gposts = array_merge(dz_pg_ids($conn, 'group_posts', 'posted_by', [$U]), dz_pg_ids($conn, 'group_posts', 'meme_id', $memes));
    $gfiles = dz_pg_ids($conn, 'group_files', 'uploaded_by', [$U]);
    $ghc    = dz_pg_ids($conn, 'group_highlight_comments', 'user_id', [$U]);
    $gevc   = dz_pg_ids($conn, 'group_event_comments', 'user_id', [$U]);
    $colls  = $cols_;

    /* ---- files to remove (read before the rows disappear) ---- */
    dz_pg_collect($conn, $files, 'users', 'id', [$U], ['profile_pic', 'cover_photo', 'banner_pic']);
    dz_pg_collect($conn, $files, 'memes', 'id', $memes, ['file_name', 'cover_image']);
    dz_pg_collect($conn, $files, 'meme_media', 'meme_id', $memes, ['file_name']);
    dz_pg_collect($conn, $files, 'longform_posts', 'id', $lfp, ['video_path', 'thumbnail_path']);
    dz_pg_collect($conn, $files, 'highlights', 'id', $hls, ['cover_path', 'song_path']);
    dz_pg_collect($conn, $files, 'highlight_media', 'user_id', [$U], ['file_path', 'audio_path']);
    dz_pg_collect($conn, $files, 'stories', 'id', $stor, ['media_path', 'audio_path', 'song_path', 'cover_path']);
    dz_pg_collect($conn, $files, 'product_images', 'product_id', $prod, ['image']);
    dz_pg_collect($conn, $files, 'shops', 'id', $shops, ['banner', 'avatar']);
    dz_pg_collect($conn, $files, 'profile_cta', 'user_id', [$U], ['pic']);
    dz_pg_collect($conn, $files, 'pod_discussion_media', 'discussion_id', $disc, ['file']);
    dz_pg_collect($conn, $files, 'group_files', 'id', $gfiles, ['stored_name']);
    dz_pg_collect($conn, $files, 'group_highlight_media', 'added_by', [$U], ['file_path', 'audio_path']);
    dz_pg_collect($conn, $files, 'messages', 'sender_id', [$U], ['file_attachment']);
    dz_pg_collect($conn, $files, 'msg_gc_messages', 'sender_id', [$U], ['file_attachment']);
    dz_pg_collect($conn, $files, 'ms_group_messages', 'sender_id', [$U], ['file_attachment']);
    /* audios: keep the file if another person's post still plays it */
    $audUnused = [];
    foreach ($aud as $a) {
        $used = 0;
        if (dz_pg_has($conn, 'meme_audio', 'audio_id')) {
            $r = $conn->query("SELECT COUNT(*) FROM meme_audio WHERE audio_id = $a" . ($memes ? " AND meme_id NOT IN " . dz_pg_in($memes) : ''));
            if ($r && ($row = $r->fetch_row())) $used = (int)$row[0];
        }
        if (!$used) $audUnused[] = $a;
    }
    dz_pg_collect($conn, $files, 'audios', 'id', $audUnused, ['file_name', 'cover_art']);
    /* own export ZIPs */
    if (dz_pg_has($conn, 'data_exports', 'file_name')) {
        $r = $conn->query("SELECT file_name FROM data_exports WHERE user_id = $U AND file_name IS NOT NULL");
        while ($r && ($row = $r->fetch_row())) { $z = __DIR__ . '/data_exports/' . basename((string)$row[0]); if (is_file($z)) $files[$z] = true; }
    }

    /* ---- database (one transaction; every table is InnoDB) ---- */
    $conn->begin_transaction();
    try {
        /* Pods the user created: hand over or remove */
        dz_pg_pods($conn, $U, $files);

        /* memes and everything hanging off them */
        foreach (['comments', 'likes', 'favorites', 'reposts', 'shares', 'downloads', 'dz_feed_impressions', 'dz_feed_serves', 'dz_media_analysis',
                  'dz_meme_lang', 'dz_meme_tags', 'dz_meme_terms', 'meme_ads', 'meme_audio', 'meme_media', 'meme_stats', 'meme_tags',
                  'user_interactions', 'pinned_posts', 'profile_featured', 'archived_posts', 'collection_items', 'group_post_pins', 'group_post_reports'] as $t)
            dz_pg_del($conn, $t, 'meme_id', $memes);
        dz_pg_del($conn, 'remix', 'post_id', $memes);
        dz_pg_del($conn, 'group_reactions', 'group_post_id', $gposts);
        dz_pg_del($conn, 'group_posts', 'id', $gposts);
        dz_pg_null($conn, 'memes', 'connected_meme_id', $memes);
        dz_pg_null($conn, 'pod_discussions', 'source_meme_id', $memes);
        dz_pg_null($conn, 'audios', 'source_meme_id', $memes);
        dz_pg_del($conn, 'memes', 'id', $memes);

        /* long-form videos */
        dz_pg_del($conn, 'longform_comment_likes', 'comment_id', $lfc);
        dz_pg_del($conn, 'longform_comment_dislikes', 'comment_id', $lfc);
        dz_pg_del($conn, 'longform_comments', 'parent_id', $lfc);
        dz_pg_del($conn, 'longform_comments', 'id', $lfc);
        foreach (['longform_post_dislikes', 'longform_post_downloads', 'longform_post_hashtags', 'longform_post_likes', 'longform_post_saves',
                  'longform_post_shares', 'longform_post_views', 'longform_reports', 'longform_watch_progress'] as $t)
            dz_pg_del($conn, $t, 'post_id', $lfp);
        dz_pg_del($conn, 'longform_posts', 'id', $lfp);

        /* highlights */
        foreach (['highlight_likes', 'highlight_messages', 'highlight_media'] as $t) dz_pg_del($conn, $t, 'highlight_id', $hls);
        dz_pg_del($conn, 'highlights', 'id', $hls);

        /* stories */
        dz_pg_del($conn, 'story_reply_likes', 'reply_id', $stre);
        dz_pg_del($conn, 'story_reply_dislikes', 'reply_id', $stre);
        dz_pg_del($conn, 'story_replies', 'parent_id', $stre);
        dz_pg_del($conn, 'story_replies', 'id', $stre);
        foreach (['story_likes', 'story_views'] as $t) dz_pg_del($conn, $t, 'story_id', $stor);
        dz_pg_del($conn, 'stories', 'id', $stor);

        /* shop */
        $shopColl = dz_pg_ids($conn, 'shop_collections', 'shop_id', $shops);
        dz_pg_del($conn, 'shop_collection_items', 'collection_id', $shopColl);
        dz_pg_del($conn, 'shop_collection_items', 'product_id', $prod);
        dz_pg_del($conn, 'shop_collections', 'shop_id', $shops);
        dz_pg_del($conn, 'shop_reviews', 'shop_id', $shops);
        dz_pg_del($conn, 'shop_reviews', 'product_id', $prod);
        foreach (['product_images', 'product_saves', 'product_reports'] as $t) dz_pg_del($conn, $t, 'product_id', $prod);
        dz_pg_del($conn, 'products', 'id', $prod);
        dz_pg_del($conn, 'shops', 'id', $shops);

        /* collections */
        dz_pg_del($conn, 'collection_items', 'collection_id', $colls);
        dz_pg_del($conn, 'pinned_collections', 'collection_id', $colls);
        dz_pg_del($conn, 'collections', 'id', $colls);

        /* sounds and audios */
        dz_pg_del($conn, 'sound_folder_items', 'folder_id', $sfold);
        dz_pg_del($conn, 'sound_folder_items', 'sound_id', $snd);
        dz_pg_del($conn, 'sound_folder_items', 'audio_id', $aud);
        dz_pg_del($conn, 'saved_sounds', 'sound_id', $snd);
        dz_pg_null($conn, 'sounds', 'original_user_id', [$U]);
        dz_pg_del($conn, 'sounds', 'id', $snd);
        dz_pg_del($conn, 'audio_saves', 'audio_id', $aud);
        dz_pg_del($conn, 'meme_audio', 'audio_id', $aud);
        dz_pg_del($conn, 'audios', 'id', $aud);

        /* subscriptions */
        foreach (['subscription_content', 'subscription_memes', 'subscription_tiers'] as $t) dz_pg_del($conn, $t, 'owner_id', [$U]);
        dz_pg_del($conn, 'subscriptions', 'owner_id', [$U]);
        dz_pg_del($conn, 'subscriptions', 'subscriber_id', [$U]);

        /* family, legacy, linked accounts */
        dz_pg_del($conn, 'family_activity_log', 'link_id', $fam);
        dz_pg_del($conn, 'family_settings', 'link_id', $fam);
        dz_pg_del($conn, 'family_links', 'id', $fam);
        dz_pg_del($conn, 'legacy_contacts', 'contact_id', [$U]);
        dz_pg_del($conn, 'linked_accounts', 'primary_user_id', [$U]);
        dz_pg_del($conn, 'linked_accounts', 'linked_user_id', [$U]);

        /* private messages (both directions) */
        dz_pg_del($conn, 'message_hidden', 'message_id', $msgs);
        dz_pg_del($conn, 'message_reactions', 'message_id', $msgs);
        dz_pg_del($conn, 'messages', 'id', $msgs);
        foreach (['msg_gc_members', 'msg_gc_messages', 'msg_gc_reads'] as $t) dz_pg_del($conn, $t, 'group_id', $gcg);
        dz_pg_del($conn, 'msg_gc_groups', 'id', $gcg);
        foreach (['ms_group_members', 'ms_group_messages', 'ms_group_reads'] as $t) dz_pg_del($conn, $t, 'group_id', $msg_);
        dz_pg_del($conn, 'ms_groups', 'id', $msg_);
        dz_pg_del($conn, 'msg_chat_archive', 'target_id', [$U]);
        dz_pg_del($conn, 'msg_gc_typing', 'target_id', [$U]);
        dz_pg_del($conn, 'ms_typing', 'target_id', [$U]);
        dz_pg_del($conn, 'ms_chat_mutes', 'other_id', [$U]);
        dz_pg_del($conn, 'ms_disappear', 'set_by', [$U]);
        dz_pg_del($conn, 'orbiter_scores', 'target_id', [$U]);
        dz_pg_del($conn, 'orbiter_scores', 'actor_id', [$U]);
        dz_pg_del($conn, 'profile_vibes', 'target_id', [$U]);
        dz_pg_del($conn, 'profile_vibes', 'voter_id', [$U]);
        dz_pg_del($conn, 'track_requests', 'requester_id', [$U]);
        dz_pg_del($conn, 'track_requests', 'target_id', [$U]);
        dz_pg_del($conn, 'profile_view_hidden', 'hider_id', [$U]);
        dz_pg_del($conn, 'profile_view_hidden', 'target_id', [$U]);
        dz_pg_del($conn, 'guestbook_entries', 'owner_id', [$U]);
        dz_pg_del($conn, 'guestbook_entries', 'author_id', [$U]);
        dz_pg_del($conn, 'unfollow_log', 'unfollowed_by', [$U]);
        dz_pg_del($conn, 'unfollow_log', 'user_id', [$U]);
        dz_pg_del($conn, 'user_reports', 'reported_user_id', [$U]);
        dz_pg_del($conn, 'content_reports', 'content_owner_id', [$U]);
        dz_pg_del($conn, 'family_links', 'initiated_by', [$U]);

        /* user's own entries inside Pods that continue to exist */
        dz_pg_del($conn, 'group_highlight_comment_likes', 'comment_id', $ghc);
        dz_pg_del($conn, 'group_highlight_comment_dislikes', 'comment_id', $ghc);
        dz_pg_del($conn, 'group_highlight_comments', 'parent_id', $ghc);
        dz_pg_del($conn, 'group_highlight_comments', 'id', $ghc);
        dz_pg_del($conn, 'group_event_comment_likes', 'comment_id', $gevc);
        dz_pg_del($conn, 'group_event_comments', 'id', $gevc);
        dz_pg_del($conn, 'group_file_reports', 'file_id', $gfiles);
        dz_pg_del($conn, 'group_file_saves', 'file_id', $gfiles);
        dz_pg_del($conn, 'group_files', 'id', $gfiles);
        dz_pg_del($conn, 'group_highlight_media', 'added_by', [$U]);
        dz_pg_del($conn, 'group_events', 'created_by', [$U]);
        dz_pg_del($conn, 'group_challenges', 'created_by', [$U]);
        dz_pg_del($conn, 'group_highlights', 'created_by', [$U]);
        dz_pg_del($conn, 'group_bans', 'banned_id', [$U]);
        dz_pg_del($conn, 'group_post_reports', 'reported_user_id', [$U]);
        dz_pg_del($conn, 'pod_discussion_reports', 'reported_user_id', [$U]);
        dz_pg_del($conn, 'group_suggestion_dismissed', 'dismissed_group_id', dz_pg_ids($conn, 'groups', 'created_by', [$U]));

        /* Pod discussions by the user (replies, likes, polls...) */
        $repl = array_unique(array_merge(dz_pg_ids($conn, 'pod_discussion_replies', 'user_id', [$U]), dz_pg_ids($conn, 'pod_discussion_replies', 'discussion_id', $disc)));
        dz_pg_del($conn, 'pod_discussion_replies', 'parent_id', $repl);
        dz_pg_del($conn, 'pod_discussion_replies', 'id', $repl);
        foreach (['pod_likes', 'pod_reactions', 'pod_poll_options', 'pod_poll_votes', 'pod_discussion_media', 'pod_discussion_reports'] as $t)
            dz_pg_del($conn, $t, 'discussion_id', $disc);
        dz_pg_del($conn, 'pod_discussions', 'id', $disc);

        /* anything else that points at the user through an unambiguous column */
        $refCols = ['user_id', 'uploader_id', 'uploaded_by', 'creator_id', 'owner_id', 'author_id', 'sender_id', 'receiver_id', 'follower_id',
                    'following_id', 'blocker_id', 'blocked_id', 'muter_id', 'muted_id', 'requester_id', 'voter_id', 'viewer_id', 'reporter_id',
                    'subscriber_id', 'hider_id', 'restrictor_id', 'restricted_id', 'banned_id', 'posted_by', 'actor_id', 'profile_id',
                    'hidden_user_id', 'contact_id', 'tagged_user_id', 'tagged_by', 'reported_by', 'editor_id', 'teen_id'];
        foreach ($S as $t => $cols) {
            if ($t === 'users' || $t === 'meme_articles') continue;   // wiki articles stay
            foreach ($refCols as $c) if (isset($cols[$c])) $conn->query("DELETE FROM `$t` WHERE `$c` = $U");
        }
        /* notifications that were about this user */
        if (dz_pg_has($conn, 'notifications', 'actor_id')) $conn->query("DELETE FROM notifications WHERE actor_id = $U");

        /* last: the account itself */
        $conn->query("DELETE FROM users WHERE id = $U");
        if ($conn->errno) throw new Exception($conn->error);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('account purge failed for user ' . $U . ': ' . $e->getMessage());
        return false;
    }

    /* ---- files, after the database commit ---- */
    foreach (array_keys($files) as $f) @unlink($f);
    return true;
}

/* purge every account whose grace period is over */
function dz_purge_due($conn, int $max = 5): int {
    $n = 0;
    try {
        $r = $conn->query("SELECT id FROM users WHERE delete_after IS NOT NULL AND delete_after <= NOW() ORDER BY delete_after ASC LIMIT " . (int)$max);
        $ids = [];
        while ($r && ($row = $r->fetch_row())) $ids[] = (int)$row[0];
        foreach ($ids as $id) {
            $ok = dz_purge_user($conn, $id);
            if ($ok) $n++;
        }
    } catch (Throwable $e) { error_log('dz_purge_due: ' . $e->getMessage()); }
    return $n;
}

/* run dz_purge_due at most once an hour, from normal page traffic */
function dz_purge_maybe($conn): void {
    $dir = __DIR__ . '/data_exports';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $stamp = $dir . '/.last_purge';
    if (is_file($stamp) && time() - (int)@filemtime($stamp) < 3600) return;
    @touch($stamp);
    dz_purge_due($conn, 3);
}