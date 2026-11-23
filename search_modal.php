<?php
/* ════════════════════════════════════════════════════════════
   Search_Modal.php — unified search modal (markup + CSS + JS)
   USAGE in search.php, AFTER the zeems include:
       <?php include 'zeems_modal.php'; ?>
       <?php include 'Search_Modal.php'; ?>
   Needs (from search.php scope): $conn $current_user $search_query $users
   $memes $groups $collections $sounds $sound_folders $memepedia_entries
   $user_previews_static $group_previews_static $_private_profile_map
   $_approved_map  — and from the page: bringModalToFront(), showToast().
   Post cards in the results open the Zeems modal (zeems_modal.php).
   ════════════════════════════════════════════════════════════ */
$_sm_json_flags = $_json_flags ?? (JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
$SM_VB = '<svg class="sm-vb" viewBox="0 0 256 256" aria-label="Verified" fill="currentColor"><path fill-rule="evenodd" d="M245.9 128.0L245.5 132.6L244.2 137.1L242.3 141.5L239.7 145.7L236.5 149.6L233.0 153.2L229.3 156.6L225.6 159.7L221.9 162.6L218.5 165.5L215.4 168.3L212.7 171.2L210.5 174.2L208.7 177.5L207.3 181.0L206.3 184.9L205.4 189.1L204.7 193.5L204.0 198.2L203.1 203.1L201.9 208.0L200.4 212.8L198.4 217.4L196.0 221.6L193.0 225.3L189.5 228.4L185.6 230.8L181.3 232.5L176.6 233.5L171.8 233.7L166.9 233.4L161.9 232.4L157.1 231.1L152.4 229.5L147.8 227.8L143.5 226.1L139.4 224.6L135.5 223.5L131.7 222.8L128.0 222.5L124.3 222.8L120.5 223.5L116.6 224.6L112.5 226.1L108.2 227.8L103.6 229.5L98.9 231.1L94.1 232.4L89.1 233.4L84.2 233.7L79.4 233.5L74.7 232.5L70.4 230.8L66.5 228.4L63.0 225.3L60.0 221.6L57.6 217.4L55.6 212.8L54.1 208.0L52.9 203.1L52.0 198.2L51.3 193.5L50.6 189.1L49.7 184.9L48.7 181.0L47.3 177.5L45.5 174.2L43.3 171.2L40.6 168.3L37.5 165.5L34.1 162.6L30.4 159.7L26.7 156.6L23.0 153.2L19.5 149.6L16.3 145.7L13.7 141.5L11.8 137.1L10.5 132.6L10.1 128.0L10.5 123.4L11.8 118.9L13.7 114.5L16.3 110.3L19.5 106.4L23.0 102.8L26.7 99.4L30.4 96.3L34.1 93.4L37.5 90.5L40.6 87.7L43.3 84.8L45.5 81.8L47.3 78.5L48.7 75.0L49.7 71.1L50.6 66.9L51.3 62.5L52.0 57.8L52.9 52.9L54.1 48.0L55.6 43.2L57.6 38.6L60.0 34.4L63.0 30.7L66.5 27.6L70.4 25.2L74.7 23.5L79.4 22.5L84.2 22.3L89.1 22.6L94.1 23.6L98.9 24.9L103.6 26.5L108.2 28.2L112.5 29.9L116.6 31.4L120.5 32.5L124.3 33.2L128.0 33.5L131.7 33.2L135.5 32.5L139.4 31.4L143.5 29.9L147.8 28.2L152.4 26.5L157.1 24.9L161.9 23.6L166.9 22.6L171.8 22.3L176.6 22.5L181.3 23.5L185.6 25.2L189.5 27.6L193.0 30.7L196.0 34.4L198.4 38.6L200.4 43.2L201.9 48.0L203.1 52.9L204.0 57.8L204.7 62.5L205.4 66.9L206.3 71.1L207.3 75.0L208.7 78.5L210.5 81.8L212.7 84.8L215.4 87.7L218.5 90.5L221.9 93.4L225.6 96.3L229.3 99.4L233.0 102.8L236.5 106.4L239.7 110.3L242.3 114.5L244.2 118.9L245.5 123.4ZM84.1 131.9Q78.2 137.8 84.1 143.6L108.3 167.9Q114.1 173.7 119.9 167.8L174.2 111.6Q179.9 105.6 174.0 99.9L170.0 96.1Q164.1 90.4 158.4 96.3L119.6 136.4Q113.9 142.3 108.0 136.5L99.6 128.1Q93.8 122.2 87.9 128.1Z"/></svg>';
?>
<div id="searchModal" role="dialog" aria-modal="true" aria-label="Search">

    <!-- ══════════════ VIEW 1 — SEARCH / TYPING ══════════════ -->
    <div id="smViewSearch" class="sm-view sm-view-active">
        <div id="searchModalContent">
            <div class="sm-in">

                <div class="sm-topbar">
                    <button type="button" class="sm-back-btn" onclick="closeSearchModal()" aria-label="Close search">
                        <svg class="sm-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linejoin="miter" stroke-linecap="butt"/></svg>
                    </button>

                    <div id="modalSearchWrap">
                        <span class="sm-search-icon"><i class="ri-search-line"></i></span>
                        <span class="sm-inbox"><input type="text" id="modalSearchInput" placeholder="Search Dezzmers, pods, posts, DZ-ID, PD-ID..." autocomplete="off"></span>
                        <button type="button" id="modalClearBtn" onclick="clearModalSearch()" aria-label="Clear">
                            <i class="ri-close-line"></i>
                        </button>
                    </div>

                    <button type="button" id="smSearchBtn" onclick="smDoSearch()" style="display:none">Search</button>
                </div>

                <div id="searchRecents">
                    <div class="sm-recents-header" id="smRecentsHeader">
                        <span class="sm-recents-title">Recent</span>
                        <div class="sm-recents-actions" id="smRecentsActions">
                            <button type="button" id="smClearAllBtn" onclick="clearAllRecents()" style="display:none">Clear all</button>
                            <button type="button" id="smCollapseBtn" onclick="smCollapseRecents()" style="display:none">
                                <i class="ri-arrow-up-s-line"></i> Collapse
                            </button>
                        </div>
                    </div>
                    <div id="recentsList"></div>
                    <div id="smSeeMoreRow" style="display:none">
                        <button type="button" id="smSeeMoreBtn" onclick="smExpandRecents()">
                            <i class="ri-arrow-down-s-line"></i> See more
                        </button>
                        <button type="button" id="smCollapseBtn2" onclick="smCollapseRecents()" style="display:none">
                            <i class="ri-arrow-up-s-line"></i> Collapse
                        </button>
                    </div>

                    <div id="smSuggestionsWrap">
                        <div class="sm-recents-header">
                            <span class="sm-recents-title" id="smSuggestTitle">Suggested</span>
                        </div>
                        <div id="smSuggestionsList"></div>
                    </div>
                </div>

                <div id="searchLiveResults"></div>

            </div>
        </div>
    </div>

    <!-- ══════════════ VIEW 2 — FULL RESULTS ══════════════ -->
    <div id="smViewResults" class="sm-view">
        <div id="smResultsLoading" class="srm-loading">
            <i class="ri-loader-4-line sm-spin"></i>
        </div>

        <div class="srm-scroll" id="srmScroll">
        <!-- Everything inside #smResultsInner is rendered server-side whenever search.php has ?q=
             (full page load OR the background fetch() the modal makes) -->
        <div id="smResultsInner">
        <?php
        /* ═══════════ PAGES (Profile Pages) matching the query ═══════════ */
        $srm_pages = [];
        $srm_q_raw = trim((string)($search_query ?? ''));
        if ($srm_q_raw !== '' && isset($conn)) {
            try {
                $srm_cu   = (int)($current_user ?? 0);
                $srm_q    = ltrim($srm_q_raw, '#');
                $srm_like = '%' . $srm_q . '%';
                $srm_pfx  = $srm_q . '%';
                $srm_st = $conn->prepare("SELECT p.id, p.name, p.category, p.bio, p.pic,
                        ((SELECT COUNT(*) FROM profile_page_followers f WHERE f.page_id=p.id)
                         + (SELECT COUNT(*) FROM profile_page_follows g WHERE g.target_id=p.id)) AS trackers,
                        EXISTS(SELECT 1 FROM profile_page_followers f2 WHERE f2.page_id=p.id AND f2.user_id=?) AS is_following,
                        (SELECT a.role FROM profile_page_admins a WHERE a.page_id=p.id AND a.user_id=? LIMIT 1) AS my_role
                    FROM profile_pages p
                    WHERE p.name LIKE ? OR p.category LIKE ? OR p.bio LIKE ?
                    ORDER BY (p.name LIKE ?) DESC, trackers DESC, p.id DESC
                    LIMIT 12");
                if ($srm_st) {
                    $srm_st->bind_param("iissss", $srm_cu, $srm_cu, $srm_like, $srm_like, $srm_like, $srm_pfx);
                    $srm_st->execute();
                    $srm_rs = $srm_st->get_result();
                    while ($srm_row = $srm_rs->fetch_assoc()) $srm_pages[] = $srm_row;
                    $srm_st->close();
                }
            } catch (Throwable $e) { $srm_pages = []; }
        }
        $srm_pages = [];   /* Pages are no longer part of search */

        $srm_vmap = [];   /* uploader id => verified, for the badge under each post */
        try {
            $srm_ids = array_values(array_unique(array_filter(array_map('intval', array_column((array)$memes, 'uploaded_by')))));
            if($srm_ids){
                $srm_vq = $conn->query("SELECT id FROM users WHERE is_verified = 1 AND id IN (" . implode(',', $srm_ids) . ")");
                while($srm_vq && ($srm_vr = $srm_vq->fetch_assoc())) $srm_vmap[(int)$srm_vr['id']] = 1;
            }
        } catch (Throwable $e) { $srm_vmap = []; }

        $has_results = !empty($users)
            || !empty($memes)
            || !empty($srm_pages)
            || ($groups && $groups->num_rows > 0)
            || !empty($collections)
            || !empty($sounds)
            || !empty($sound_folders)
            || !empty($memepedia_entries);

        $srm_q_js = json_encode((string)($search_query ?? ''), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);

        if (!function_exists('srm_truncate_name')) {
            function srm_truncate_name($name, $limit = 15) {
                $name = (string)$name;
                return (mb_strlen($name) > $limit) ? mb_substr($name, 0, $limit) . '…' : $name;
            }
            function srm_truncate_words($text, $wordLimit, $colorClass) {
                $text = trim((string)$text);
                if ($text === '') return '';
                $words = preg_split('/\s+/u', $text);
                if (count($words) <= $wordLimit) return htmlspecialchars($text);
                $short = implode(' ', array_slice($words, 0, $wordLimit));
                return htmlspecialchars($short) . '<span class="srm-see-more ' . $colorClass . '">...see more</span>';
            }
            function srm_short_num($n) {
                $n = (int)$n;
                if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
                if ($n >= 1000)    return round($n / 1000, 1) . 'K';
                return (string)$n;
            }
            function srm_build_preview_grid($posts, $see_more_href, $type, $name, $avatarSrc, $defaultAvatar) {
                $posts = $posts ?? [];
                $html  = '<div class="srm-card-grid">';
                for ($i = 0; $i < 3; $i++) {
                    $p = $posts[$i] ?? null;
                    if ($p) {
                        $file  = htmlspecialchars($p['file_name'] ?? '');
                        $ptype = $p['type'] ?? 'image';
                        if ($ptype === 'video') {
                            $html .= '<div class="srm-card-tile" onclick="event.stopPropagation();smNavigateInApp(\''.$see_more_href.'\')">'
                                . '<video src="uploads/'.$file.'" muted playsinline preload="metadata"></video>'
                                . '<i class="ri-play-fill srm-card-tile-play"></i></div>';
                        } else {
                            $thumb = ($ptype === 'image' && $file) ? 'uploads/'.$file : 'uploads/Text.jpg';
                            $html .= '<div class="srm-card-tile" onclick="event.stopPropagation();smNavigateInApp(\''.$see_more_href.'\')">'
                                . '<img src="'.htmlspecialchars($thumb).'" onerror="this.src=\'uploads/Text.jpg\'"></div>';
                        }
                    } else {
                        $html .= '<div class="srm-card-tile srm-card-tile-empty"><i class="ri-image-line"></i></div>';
                    }
                }
                $isGroup     = ($type === 'group');
                $themeClass  = $isGroup ? 'srm-checkout-blue' : 'srm-checkout-red';
                $avatarClass = $isGroup ? 'srm-checkout-avatar-grp' : 'srm-checkout-avatar-user';
                $shortName   = htmlspecialchars(srm_truncate_name($name, 15));
                $html .= '<div class="srm-card-tile srm-checkout-tile '.$themeClass.'" onclick="event.stopPropagation();smNavigateInApp(\''.$see_more_href.'\')">'
                    . '<img class="srm-checkout-avatar '.$avatarClass.'" src="'.htmlspecialchars($avatarSrc).'" onerror="this.src=\''.htmlspecialchars($defaultAvatar).'\';">'
                    . '<span class="srm-checkout-label">Check Out</span>'
                    . '<span class="srm-checkout-name">'.$shortName.'</span>'
                    . '<span class="srm-checkout-btn">See more</span></div>';
                return $html . '</div>';
            }
        }
        ?>

        <div class="srm-topbar">
            <button type="button" class="sm-back-btn" onclick="smResultsBack()" aria-label="Back to search">
                <svg class="sm-back-svg" viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="1.94" stroke-linejoin="miter" stroke-linecap="butt"/></svg>
            </button>
            <div class="srm-title-wrap">
                <div class="srm-title-row">
                    <?php if($search_query): ?>
                    <div class="srm-search-pill">
                        <span class="srm-pill-icon"><i class="ri-search-line"></i></span>
                        <button type="button" id="srmPillText" class="srm-search-pill-text" onclick='smReopenSearchInput(<?php echo $srm_q_js; ?>)'><?php echo htmlspecialchars($search_query); ?></button>
                        <span class="srm-pill-divider"></span>
                        <a href="search.php" id="srmClearBtn" class="srm-clear-btn" onclick="return smClearFullSearch(event)">Clear</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="srm-tabs-wrap">
            <button type="button" class="srm-tab-arrow l" data-dir="left" aria-label="Scroll tabs left"><i class="ri-arrow-left-s-line"></i></button>
            <div class="srm-tabs" id="srmTabs">
                <button type="button" class="srm-tab active" data-filter="all">For You</button>
                <button type="button" class="srm-tab" data-filter="posts">Posts</button>
                <button type="button" class="srm-tab" data-filter="dezzmers">Dezzmers</button>
                <button type="button" class="srm-tab" data-filter="pods">Pods</button>
                <button type="button" class="srm-tab" data-filter="memepedia">Memepedia</button>
                <button type="button" class="srm-tab" data-filter="collections">Collections</button>
                <button type="button" class="srm-tab" data-filter="sounds">Sounds</button>
                <button type="button" class="srm-tab" data-filter="soundfolders">Sound Folders</button>
            </div>
            <button type="button" class="srm-tab-arrow r" data-dir="right" aria-label="Scroll tabs right"><i class="ri-arrow-right-s-line"></i></button>
        </div>

        <div class="srm-content">
        <?php if($search_query): ?>

            <?php if(!$has_results): ?>
            <div class="sm-es">
                <div class="sm-es-title">No results for "<?php echo htmlspecialchars($search_query); ?>"</div>
                <p class="sm-es-text">We couldn't find any Dezzmers, pods or posts for this. Here are a few things you can try.</p>
                <div class="sm-es-tips">
                    <div class="sm-es-tip"><i class="ri-spell-check"></i><span>Check the spelling or try fewer words.</span></div>
                    <div class="sm-es-tip"><i class="ri-hashtag"></i><span>Search a #hashtag to find posts on that topic.</span></div>
                    <div class="sm-es-tip"><i class="ri-fingerprint-line"></i><span>Type DZ- or PD- and an ID to find a Dezzmer or a Pod.</span></div>
                </div>
                <button type="button" class="sm-es-btn" onclick='smReopenSearchInput(<?php echo $srm_q_js; ?>)'>Search again</button>
            </div>
            <?php endif; ?>

            <?php ob_start(); ?>
            <!-- ═══════ POSTS ═══════ -->
            <?php if(!empty($memes)): ?>
            <?php $srm_shuffled_memes = $memes; /* same order as peuraFeed */ ?>
            <script>if(typeof srmSearchFeed === "undefined") var srmSearchFeed = []; srmSearchFeed.length = 0;</script>
            <?php
            $srm_ids = array_map(function($m){ return (int)$m['id']; }, $srm_shuffled_memes);
            $_srm_media_map = []; $_srm_group_map = []; $_srm_audio_map = []; $_srm_social_proof_map = [];
            /* collaborators (accepted) for every post in the results */
            $_srm_collab_map = [];
            try{
                if(!function_exists('dz_collab_payload')&&is_file(__DIR__.'/dz_collab.php')) require_once __DIR__.'/dz_collab.php';
                if(function_exists('dz_collab_payload')){
                    $_srm_ids=[]; foreach($srm_shuffled_memes as $_m){ $_srm_ids[]=(int)$_m['id']; }
                    if($_srm_ids) $_srm_collab_map=dz_collab_payload($conn,$_srm_ids,(int)$current_user);
                }
            }catch(Throwable $e){}

            if(!empty($srm_ids)){
                $srm_ids_str = implode(',', $srm_ids);

                $mm_res = $conn->query("SELECT meme_id, file_name, type, position FROM meme_media WHERE meme_id IN ($srm_ids_str) ORDER BY meme_id, position ASC");
                if($mm_res) while($mm = $mm_res->fetch_assoc()){
                    $mid = (int)$mm['meme_id'];
                    if(!isset($_srm_media_map[$mid])) $_srm_media_map[$mid] = [];
                    $_srm_media_map[$mid][] = ['file'=>$mm['file_name'], 'type'=>$mm['type']];
                }

                $gp_res = $conn->query("SELECT gp.meme_id, g.id AS group_id, g.title AS group_title FROM group_posts gp JOIN groups g ON g.id=gp.group_id WHERE gp.meme_id IN ($srm_ids_str)");
                if($gp_res) while($gp = $gp_res->fetch_assoc()) $_srm_group_map[(int)$gp['meme_id']] = $gp;

                $au_res = $conn->query("SELECT ma.meme_id, a.id AS audio_id, a.title, a.artist, a.file_name, ma.is_original, ma.music_volume, ma.video_audio_volume FROM meme_audio ma JOIN audios a ON a.id=ma.audio_id WHERE ma.meme_id IN ($srm_ids_str)");
                if($au_res) while($au = $au_res->fetch_assoc()) $_srm_audio_map[(int)$au['meme_id']] = $au;

                /* backfill native audio for videos with nothing attached */
                foreach($srm_shuffled_memes as $mrow){
                    $mid = (int)$mrow['id'];
                    if(($mrow['type'] ?? '') !== 'video') continue;
                    if(isset($_srm_audio_map[$mid])) continue;
                    $existing = $conn->query("SELECT id FROM audios WHERE source_meme_id=$mid LIMIT 1")->fetch_assoc();
                    if($existing){
                        $aid = (int)$existing['id'];
                    } else {
                        $uploader_id = (int)($mrow['uploaded_by'] ?? 0);
                        $file_name   = $conn->real_escape_string($mrow['file_name']);
                        $conn->query("INSERT INTO audios (uploader_id, title, file_name, is_original, source_meme_id, created_at) VALUES ($uploader_id, 'Original audio', '$file_name', 1, $mid, NOW())");
                        $aid = $conn->insert_id;
                        if($aid) $conn->query("INSERT INTO meme_audio (meme_id, audio_id, is_original, music_volume, video_audio_volume) VALUES ($mid, $aid, 1, 100, 100)");
                    }
                    if($aid){
                        $_srm_audio_map[$mid] = ['meme_id'=>$mid,'audio_id'=>$aid,'title'=>'Original audio','artist'=>null,
                            'file_name'=>$mrow['file_name'],'is_original'=>1,'music_volume'=>100,'video_audio_volume'=>100];
                    }
                }

                if($current_user){
                    $sp_result = $conn->query("
                        SELECT l.meme_id, u.id, u.username, u.profile_pic, 'like' AS action_type
                        FROM likes l JOIN users u ON u.id=l.user_id JOIN followers f ON f.following_id=u.id
                        WHERE l.meme_id IN ($srm_ids_str) AND f.follower_id=$current_user AND u.id != $current_user
                        UNION ALL
                        SELECT r.meme_id, u.id, u.username, u.profile_pic, 'repost' AS action_type
                        FROM reposts r JOIN users u ON u.id=r.user_id JOIN followers f ON f.following_id=u.id
                        WHERE r.meme_id IN ($srm_ids_str) AND f.follower_id=$current_user AND u.id != $current_user
                    ");
                    $_srm_sp_raw = [];
                    if($sp_result) while($sp = $sp_result->fetch_assoc()){
                        $mid = $sp['meme_id'];
                        if(!isset($_srm_sp_raw[$mid])) $_srm_sp_raw[$mid] = [];
                        $_srm_sp_raw[$mid][] = $sp;
                    }
                    foreach($_srm_sp_raw as $mid => $rows){
                        usort($rows, function($a,$b){ return ($a['action_type']==='repost'?0:1) <=> ($b['action_type']==='repost'?0:1); });
                        $_srm_social_proof_map[$mid] = array_slice($rows, 0, 4);
                    }
                }
            }
            ?>
            <div class="srm-section" data-section="posts">
                <div class="srm-sec-title">Posts</div>
                <div class="srm-posts-grid">
                <?php $srm_feed_idx = 0; /* position in srmSearchFeed (skipped private posts don't count) */ ?>
                <?php foreach($srm_shuffled_memes as $m): ?>
                <?php
                    $m_uploader_id = (int)$m['uploaded_by'];
                    if($m_uploader_id != (int)$current_user && !empty($_private_profile_map[$m_uploader_id]) && empty($_approved_map[$m_uploader_id])) continue;

                    $m_post_pic = !empty($m['profile_pic']) ? $m['profile_pic'] : 'uploads/mee.jpeg';
                    $m_raw_date = $m['created_at'] ?? $m['uploaded_at'] ?? $m['upload_date'] ?? $m['date_posted'] ?? $m['timestamp'] ?? null;
                    $m_date_str = $m_raw_date ? date('j M', strtotime($m_raw_date)) : '';
                    $m_is_text_post = ($m['type'] !== 'video' && $m['type'] !== 'image');

                    $m_id = (int)$m['id'];
                    $m_media_items = [[ 'file' => $m['file_name'], 'type' => $m['type'] ]];
                    if(!empty($_srm_media_map[$m_id])) $m_media_items = array_merge($m_media_items, $_srm_media_map[$m_id]);
                    $m_group_info  = $_srm_group_map[$m_id] ?? null;
                    $m_audio_info  = $_srm_audio_map[$m_id] ?? null;
                    $m_sp_for_meme = $_srm_social_proof_map[$m_id] ?? [];

                    /* one clean JSON object per post — this is the exact shape zeems_modal.php reads */
                    $m_feed_item = [
                        'id'          => $m_id,
                        'file'        => 'uploads/'.$m['file_name'],
                        'type'        => $m['type'],
                        'media'       => array_map(function($mm){ return ['file'=>'uploads/'.$mm['file'], 'type'=>$mm['type']]; }, $m_media_items),
                        'pic'         => $m_post_pic,
                        'userId'      => (string)$m_uploader_id,
                        'username'    => $m['username'],
                        'nickname'    => $m['nickname'] ?? '',
                        'caption'     => str_replace(["\r","\n"], ' ', (string)$m['caption']),
                        'time'        => $m['created_at'] ?? '',
                        'likeCount'   => (int)($m['like_count'] ?? 0),
                        'repostCount' => (int)($m['repost_count'] ?? 0),
                        'favCount'    => (int)($m['fav_count'] ?? 0),
                        'shareCount'  => (int)($m['share_count'] ?? 0),
                        'liked'       => !empty($m['user_liked']),
                        'reposted'    => !empty($m['user_reposted']),
                        'faved'       => !empty($m['user_fav']),
                        'isFollowing' => !empty($m['is_following']),
                        'isYou'       => ($m_uploader_id == (int)$current_user),
                        'online'      => !empty($m['poster_online']),
                        'connectedMemeId' => !empty($m['connected_meme_id']) ? (int)$m['connected_meme_id'] : null,
                        'connectedLabel'  => !empty($m['connected_label']) ? $m['connected_label'] : null,
                        'isPartnership' => !empty($m['is_partnership']),
                        'partnerBrand'  => !empty($m['partner_brand']) ? $m['partner_brand'] : null,
                        'isAiGenerated' => !empty($m['is_ai_generated']),
                        'isAd'          => !empty($m['is_ad']),
                        'collab'        => $_srm_collab_map[$m_id] ?? null,
                        'allowDownload' => (int)($m['allow_download'] ?? 1),
                        'adCtaText'     => $m['ad_cta_text'] ?? '',
                        'adCtaUrl'      => $m['ad_cta_url'] ?? '',
                        'adCtaColor'    => !empty($m['ad_cta_color']) ? $m['ad_cta_color'] : '#FF4C4C',
                        'groupId'     => $m_group_info ? (int)$m_group_info['group_id'] : null,
                        'groupTitle'  => $m_group_info ? $m_group_info['group_title'] : null,
                        'audioId'     => $m_audio_info ? (int)$m_audio_info['audio_id'] : null,
                        'audioTitle'  => $m_audio_info ? $m_audio_info['title'] : null,
                        'audioArtist' => ($m_audio_info && !empty($m_audio_info['artist'])) ? $m_audio_info['artist'] : null,
                        'audioFile'   => $m_audio_info ? 'uploads/'.$m_audio_info['file_name'] : null,
                        'audioIsOriginal'  => (bool)($m_audio_info && !empty($m_audio_info['is_original'])),
                        'musicVolume'      => $m_audio_info ? (int)$m_audio_info['music_volume'] : 80,
                        'videoAudioVolume' => $m_audio_info ? (int)$m_audio_info['video_audio_volume'] : 40,
                        'socialProof' => array_map(function($sp){
                            return ['id'=>(int)$sp['id'], 'username'=>$sp['username'], 'profile_pic'=>$sp['profile_pic'], 'action_type'=>$sp['action_type']];
                        }, $m_sp_for_meme),
                    ];
                    echo '<script>srmSearchFeed.push('.json_encode($m_feed_item, $_sm_json_flags).');</scr'.'ipt>';

                    $m_srm_index = $srm_feed_idx;
                    $srm_feed_idx++;
                ?>
                <div class="srm-post-card" onclick="openDiscoverModalFromSearch(<?php echo $m_srm_index; ?>)">
                    <div class="srm-post-media">
                        <?php if($m['type'] === 'video'): ?>
                            <video src="uploads/<?php echo htmlspecialchars($m['file_name']); ?>" muted autoplay loop playsinline preload="metadata"></video>
                            <i class="ri-play-fill srm-post-play"></i>
                        <?php elseif($m['type'] === 'image'): ?>
                            <img src="uploads/<?php echo htmlspecialchars($m['file_name']); ?>" onerror="this.src='uploads/mee.jpeg'">
                        <?php else: ?>
                            <img src="uploads/Text.jpg" onerror="this.src='uploads/mee.jpeg'">
                            <div class="srm-post-text-overlay"><?php echo htmlspecialchars($m['caption']); ?></div>
                        <?php endif; ?>
                        <?php if($m_date_str): ?><span class="srm-post-date"><?php echo htmlspecialchars($m_date_str); ?></span><?php endif; ?>
                    </div>
                    <?php if(!$m_is_text_post): ?>
                        <div class="srm-post-caption"><?php echo htmlspecialchars($m['caption']); ?></div>
                    <?php endif; ?>
                    <div class="srm-post-meta">
                        <?php $m_co = !empty($_srm_collab_map[$m_id]['list']) ? $_srm_collab_map[$m_id]['list'] : null; ?>
                        <?php if($m_co): ?>
                        <span class="srm-post-stack">
                            <img class="srm-post-avatar" src="<?php echo htmlspecialchars($m_post_pic); ?>" onerror="this.src='uploads/mee.jpeg'">
                            <?php foreach(array_slice($m_co, 0, 2) as $m_c): ?><img class="srm-post-avatar" src="<?php echo htmlspecialchars($m_c['pic']); ?>" onerror="this.src='uploads/mee.jpeg'"><?php endforeach; ?>
                        </span>
                        <span class="srm-post-username"><?php echo htmlspecialchars($m['username']); ?> and <?php echo count($m_co) === 1 ? htmlspecialchars($m_co[0]['username']) : count($m_co) . ' others'; ?></span>
                        <?php else: ?>
                        <img class="srm-post-avatar" src="<?php echo htmlspecialchars($m_post_pic); ?>" onerror="this.src='uploads/mee.jpeg'">
                        <span class="srm-post-username"><?php echo htmlspecialchars($m['username']); ?></span><?php if(!empty($srm_vmap[(int)($m['uploaded_by'] ?? 0)])) echo $SM_VB; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php $srm_block_html['posts'] = ob_get_clean(); ?>

            <?php ob_start(); ?>
            <!-- ═══════ DEZZMERS (CARD) ═══════ -->
            <?php if(!empty($users)): ?>
            <div class="srm-section" data-section="dezzmers">
                <div class="srm-sec-title">Dezzmers</div>
                <?php foreach($users as $u): ?>
                <?php
                    $defaultPic  = 'uploads/mee.jpeg';
                    $pic         = !empty($u['profile_pic']) ? $u['profile_pic'] : $defaultPic;
                    $uid         = (int)$u['id'];
                    $profileHref = 'profile.php?user_id='.$uid;

                    $is_blocked        = !empty($u['is_blocked']);
                    $is_following      = !empty($u['is_following']);
                    $is_private        = !empty($u['is_private']);
                    $is_track_approved = !empty($u['is_track_approved']);
                    $track_req_status  = $u['track_req_status'] ?? null;
                    $bio               = $u['bio'] ?? '';

                    $u_susp = !empty($u['is_suspended']);   /* suspended account: plain tile, "Suspended", no Track button, no previews */
                    $show_private_label = $show_requested_label = $show_track_btn = false;
                    if($current_user && !$is_blocked && !$u_susp){
                        if($is_private && !$is_following && !$is_track_approved){
                            if($track_req_status === 'pending') $show_requested_label = true;
                            else $show_private_label = true;
                        } else {
                            $show_track_btn = true;
                        }
                    }
                    $previews = $user_previews_static[$uid] ?? [];
                ?>
                <div class="srm-card srm-card-user">
                    <div class="srm-card-header" onclick="smNavigateInApp('<?php echo $profileHref; ?>')">
                        <?php if($u_susp): ?><span class="srm-card-avatar srm-avatar-red srm-susp-av"></span><?php else: ?>
                        <img class="srm-card-avatar srm-avatar-red" src="<?php echo htmlspecialchars($pic); ?>" onerror="this.src='<?php echo $defaultPic; ?>';">
                        <?php endif; ?>
                        <div class="srm-card-headinfo">
                            <div class="srm-card-name">
                                <?php echo htmlspecialchars($u['username']); ?><?php if(!empty($u['is_verified'])) echo $SM_VB; ?>
                                <?php if($is_blocked): ?><span class="srm-blocked-txt">Blocked</span><?php endif; ?>
                            </div>
                            <div class="srm-card-sub">
                                <?php if($u_susp): ?><span class="sm-susp-txt">Suspended</span>
                                <?php elseif(!empty($u['nickname'])): ?>@<?php echo htmlspecialchars($u['nickname']); ?>
                                <?php else: ?><span class="srm-card-sub-muted">Dezzmer</span><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if($is_blocked): ?>
                        <div class="srm-card-blocked-row"><span class="srm-blocked-label">Blocked</span></div>
                    <?php elseif($show_requested_label): ?>
                        <div class="srm-card-blocked-row"><span class="sm-private-label"><i class="ri-time-line"></i> Requested</span></div>
                    <?php elseif($show_private_label): ?>
                        <button class="srm-card-track-btn srm-card-private-btn srm-btn-red" data-user="<?php echo $uid; ?>"
                                onclick="event.stopPropagation();srRequestTrack(this, <?php echo $uid; ?>)"><i class="ri-lock-line"></i> Request Track</button>
                    <?php elseif($show_track_btn): ?>
                        <button class="follow-btn srm-card-track-btn srm-btn-red <?php echo $is_following ? 'following tracking' : ''; ?>"
                                data-user="<?php echo $uid; ?>" onclick="event.stopPropagation()"><?php echo $is_following ? 'Untrack' : 'Track'; ?></button>
                    <?php endif; ?>

                    <?php if(!$is_blocked && !$u_susp) echo srm_build_preview_grid($previews, $profileHref, 'user', $u['username'], $pic, $defaultPic); ?>

                    <?php if(!empty($bio) && !$u_susp): ?><div class="srm-card-bio"><?php echo srm_truncate_words($bio, 25, 'srm-see-more-red'); ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php $srm_block_html['dezzmers'] = ob_get_clean(); ?>

            <?php ob_start(); ?>
            <!-- ═══════ PAGES (CARD) ═══════ -->
            <?php if(!empty($srm_pages)): ?>
            <div class="srm-section" data-section="pages">
                <div class="srm-sec-title">Pages</div>
                <?php foreach($srm_pages as $pg): ?>
                <?php
                    $pgid        = (int)$pg['id'];
                    $pgHref      = 'page.php?id='.$pgid;
                    $pgDefault   = 'mee.jpg';
                    $pgPic       = !empty($pg['pic']) ? $pg['pic'] : $pgDefault;
                    $pgRole      = (string)($pg['my_role'] ?? '');
                    $pgFollowing = !empty($pg['is_following']);
                    $pgBio       = $pg['bio'] ?? '';
                    $pgRoleName  = ['owner'=>'Owner','admin'=>'Admin','editor'=>'Editor'][$pgRole] ?? 'Team';

                    $pgPreviews = [];
                    try {
                        $pgq = $conn->query("SELECT file_name, CASE WHEN type='video' THEN 'video' ELSE 'image' END AS type
                                             FROM profile_page_posts WHERE page_id=$pgid AND file_name<>'' ORDER BY id DESC LIMIT 3");
                        if($pgq) while($pr = $pgq->fetch_assoc()){
                            $pr['file_name'] = preg_replace('~^uploads/~', '', (string)$pr['file_name']);
                            $pgPreviews[] = $pr;
                        }
                    } catch (Throwable $e) {}
                ?>
                <div class="srm-card srm-card-user srm-card-page">
                    <div class="srm-card-header" onclick="smNavigateInApp('<?php echo $pgHref; ?>')">
                        <span class="sm-pg-av">
                            <img class="srm-card-avatar srm-avatar-red" src="<?php echo htmlspecialchars($pgPic); ?>" onerror="this.src='<?php echo $pgDefault; ?>';">
                            <span class="sm-pg-badge"><i class="ri-flag-2-fill"></i></span>
                        </span>
                        <div class="srm-card-headinfo">
                            <div class="srm-card-name"><?php echo htmlspecialchars($pg['name']); ?></div>
                            <div class="srm-card-sub">
                                <span class="srm-card-owner">Page</span><span class="srm-card-dot">•</span>
                                <span><?php echo htmlspecialchars($pg['category']); ?></span><span class="srm-card-dot">•</span>
                                <span><?php echo srm_short_num($pg['trackers']); ?> trackers</span>
                            </div>
                        </div>
                    </div>

                    <?php if($pgRole !== ''): ?>
                        <div class="srm-card-blocked-row"><span class="sm-private-label"><i class="ri-shield-user-line"></i> <?php echo htmlspecialchars($pgRoleName); ?> of this page</span></div>
                    <?php else: ?>
                        <button class="page-follow-btn srm-card-track-btn srm-btn-red <?php echo $pgFollowing ? 'following tracking' : ''; ?>"
                                data-page="<?php echo $pgid; ?>" onclick="event.stopPropagation()"><?php echo $pgFollowing ? 'Untrack' : 'Track'; ?></button>
                    <?php endif; ?>

                    <?php echo srm_build_preview_grid($pgPreviews, $pgHref, 'user', $pg['name'], $pgPic, $pgDefault); ?>

                    <?php if(!empty($pgBio)): ?><div class="srm-card-bio"><?php echo srm_truncate_words($pgBio, 25, 'srm-see-more-red'); ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php $srm_block_html['pages'] = ob_get_clean(); ?>

            <?php ob_start(); ?>
            <!-- ═══════ PODS (CARD) ═══════ -->
            <?php if($groups && $groups->num_rows > 0): ?>
            <div class="srm-section" data-section="pods">
                <div class="srm-sec-title">Pods</div>
                <?php while($g = $groups->fetch_assoc()):
                    $gid = (int)$g['id'];
                    $is_joined = false;
                    if($current_user){
                        $chk = $conn->query("SELECT 1 FROM group_members WHERE group_id=$gid AND user_id=$current_user");
                        $is_joined = $chk->num_rows > 0;
                    }
                    $group_pic = $g['group_pic'] ?? '';
                    if(empty($group_pic)) $img_src = 'uploads/default_group.png';
                    elseif(strpos($group_pic, 'uploads/') === 0) $img_src = $group_pic;
                    else $img_src = 'uploads/' . $group_pic;

                    $g_is_adult  = !empty($g['is_adult']);
                    $g_is_muted  = !empty($g['is_muted']);
                    $g_notif_off = !empty($g['notif_off']);
                    $owner_name  = $g['owner_username'] ?? 'Unknown';
                    $groupHref   = 'group.php?id='.$gid;
                    $previews    = $group_previews_static[$gid] ?? [];
                    $g_desc      = $g['description'] ?? '';
                ?>
                <div class="srm-card srm-card-group">
                    <div class="srm-card-header srm-card-header-split">
                        <div class="srm-card-header-left" onclick="smNavigateInApp('<?php echo $groupHref; ?>')">
                            <?php $g_susp = !empty($g['is_suspended']); ?>
                            <?php if($g_susp): ?><span class="srm-card-avatar srm-card-avatar-grp srm-avatar-blue srm-susp-av"></span><?php else: ?>
                            <img class="srm-card-avatar srm-card-avatar-grp srm-avatar-blue" src="<?php echo htmlspecialchars($img_src); ?>" onerror="this.src='uploads/default_group.png';">
                            <?php endif; ?>
                            <div class="srm-card-headinfo">
                                <div class="srm-card-name">
                                    <?php echo htmlspecialchars($g['title']); ?>
                                    <?php if($g_is_adult): ?><span class="sm-nsfw-badge">NSFW</span><?php endif; ?>
                                </div>
                                <div class="srm-card-sub">
                                  <?php if($g_susp): ?><span class="sm-susp-txt blue">Suspended</span><?php else: ?>
                                    <span class="srm-card-owner"><?php echo htmlspecialchars($owner_name); ?></span><span class="srm-card-dot">•</span>
                                    <span><?php echo (int)$g['member_count']; ?> members</span>
                                    <?php if($g_is_muted): ?><span class="sm-muted-icon" title="Muted"><i class="ri-volume-mute-line"></i></span><?php endif; ?>
                                    <?php if($g_notif_off): ?><span class="sm-notif-off-icon" title="Notifications off"><i class="ri-notification-off-line"></i></span><?php endif; ?>
                                  <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if($current_user && !$g_susp): ?>
                            <button class="group-btn srm-card-group-btn srm-btn-blue <?php echo $is_joined ? 'joined' : ''; ?>"
                                    data-group="<?php echo $gid; ?>" onclick="event.stopPropagation()"><?php echo $is_joined ? 'Leave' : 'Join'; ?></button>
                        <?php endif; ?>
                    </div>
                    <?php if(!$g_susp) echo srm_build_preview_grid($previews, $groupHref, 'group', $g['title'], $img_src, 'uploads/default_group.png'); ?>
                    <?php if(!empty($g_desc) && !$g_susp): ?><div class="srm-card-desc"><?php echo srm_truncate_words($g_desc, 30, 'srm-see-more-blue'); ?></div><?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
            <?php $srm_block_html['pods'] = ob_get_clean(); ?>

            <?php
                foreach(['posts', 'dezzmers', 'pages', 'pods'] as $srm_block_key){
                    echo $srm_block_html[$srm_block_key] ?? '';
                }
            ?>

            <!-- ═══════ COLLECTIONS ═══════ -->
            <?php if(!empty($collections)): ?>
            <div class="srm-section" data-section="collections">
                <div class="srm-sec-title">Collections</div>
                <?php foreach($collections as $c): ?>
                <div class="srm-row" onclick="smNavigateInApp('profile.php?user_id=<?php echo (int)$c['owner_id']; ?>#memes')">
                    <div class="sm-res-icon-box col srm-icon-box"><i class="ri-folder-3-line"></i></div>
                    <div class="srm-info">
                        <div class="srm-name"><?php echo htmlspecialchars($c['name']); ?></div>
                        <div class="srm-sub">by <?php echo htmlspecialchars($c['owner_username']); ?> · <?php echo (int)$c['item_count']; ?> items</div>
                    </div>
                    <i class="ri-arrow-right-s-line srm-arrow" style="color:#ff8a8a;"></i>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ═══════ SOUNDS ═══════ -->
            <?php if(!empty($sounds)): ?>
            <div class="srm-section" data-section="sounds">
                <div class="srm-sec-title">Sounds</div>
                <?php foreach($sounds as $s): ?>
                <div class="srm-row" onclick="smNavigateInApp('audio.php?user_id=<?php echo (int)$s['user_id']; ?>')">
                    <div class="sm-res-icon-box sound srm-icon-box"><i class="ri-music-2-line"></i></div>
                    <div class="srm-info">
                        <div class="srm-name"><?php echo htmlspecialchars($s['title']); ?></div>
                        <div class="srm-sub">by <?php echo htmlspecialchars($s['username']); ?></div>
                    </div>
                    <i class="ri-arrow-right-s-line srm-arrow" style="color:#ff8a8a;"></i>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ═══════ SOUND FOLDERS ═══════ -->
            <?php if(!empty($sound_folders)): ?>
            <div class="srm-section" data-section="soundfolders">
                <div class="srm-sec-title">Sound Folders</div>
                <?php foreach($sound_folders as $sf): ?>
                <div class="srm-row" onclick="smNavigateInApp('audio.php?user_id=<?php echo (int)$sf['owner_id']; ?>')">
                    <div class="sm-res-icon-box scol srm-icon-box"><i class="ri-folder-music-line"></i></div>
                    <div class="srm-info">
                        <div class="srm-name"><?php echo htmlspecialchars($sf['name']); ?></div>
                        <div class="srm-sub">by <?php echo htmlspecialchars($sf['owner_username']); ?> · <?php echo (int)$sf['item_count']; ?> tracks</div>
                    </div>
                    <i class="ri-arrow-right-s-line srm-arrow" style="color:#ff8a8a;"></i>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ═══════ MEMEPEDIA (CARD) ═══════ -->
            <?php if(!empty($memepedia_entries)): ?>
            <div class="srm-section" data-section="memepedia">
                <div class="srm-sec-title">Memepedia</div>
                <?php foreach($memepedia_entries as $w): ?>
                <?php
                    $articleHref = 'memepedia.php?open='.(int)$w['id'];
                    $statusLabel = !empty($w['status']) ? ucfirst($w['status']) : '';
                    $dateStr     = !empty($w['created_at']) ? date('j M Y', strtotime($w['created_at'])) : '';
                    $authorPic   = !empty($w['author_pic']) ? $w['author_pic'] : 'uploads/mee.jpeg';
                    $authorName  = !empty($w['author_username']) ? '@'.$w['author_username'] : 'Unknown';
                    $summary     = $w['summary'] ?? '';
                    $isVideoWiki = !empty($w['media_url']) && (($w['media_type'] ?? '') === 'video');
                ?>
                <div class="srm-card srm-card-wiki" onclick="smNavigateInApp('<?php echo $articleHref; ?>')">
                    <?php if($isVideoWiki): ?>
                        <video class="srm-wiki-hero" src="<?php echo htmlspecialchars($w['media_url']); ?>" muted playsinline preload="metadata"></video>
                    <?php elseif(!empty($w['media_url'])): ?>
                        <img class="srm-wiki-hero" src="<?php echo htmlspecialchars($w['media_url']); ?>" onerror="smImgFallback(this,'srm-wiki-hero srm-wiki-hero-fallback','ri-book-open-line')">
                    <?php else: ?>
                        <div class="srm-wiki-hero srm-wiki-hero-fallback"><i class="ri-book-open-line"></i></div>
                    <?php endif; ?>
                    <div class="srm-wiki-body">
                        <div class="srm-wiki-title">
                            <?php echo htmlspecialchars($w['title']); ?>
                            <?php if($statusLabel): ?><span class="srm-wiki-status"><?php echo htmlspecialchars($statusLabel); ?></span><?php endif; ?>
                        </div>
                        <?php if(!empty($summary)): ?><div class="srm-wiki-summary"><?php echo htmlspecialchars($summary); ?></div><?php endif; ?>
                        <div class="srm-wiki-meta">
                            <img src="<?php echo htmlspecialchars($authorPic); ?>" onerror="this.src='uploads/mee.jpeg'">
                            <span>by <?php echo htmlspecialchars($authorName); ?><?php echo $dateStr ? ' · '.htmlspecialchars($dateStr) : ''; ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        <?php endif; ?>

        <!-- per-tab empty state -->
        <div class="sm-es srm-empty-tab" id="srmEmptyTab" style="display:none;">
            <div class="sm-es-title" id="srmEmptyTabTitle"></div>
            <p class="sm-es-text" id="srmEmptyTabText"></p>
            <div class="sm-es-tips">
                <div class="sm-es-tip"><i class="ri-layout-grid-line"></i><span>Results for this search may be waiting in another tab.</span></div>
                <div class="sm-es-tip"><i class="ri-spell-check"></i><span>Try a shorter word or a different spelling.</span></div>
            </div>
            <button type="button" class="sm-es-btn sm-es-backall">Back to For You</button>
        </div>
        </div><!-- /srm-content -->

        </div><!-- /smResultsInner -->
        </div><!-- /srm-scroll -->
    </div>
    <!-- ══════════════ /VIEW 2 ══════════════ -->

</div>
<!-- ══════════════ /searchModal ══════════════ -->


<style>
/* ═══════════════════════════════════════════════════════════
   DEZZME SEARCH MODAL — fluid sizing.
   Every old 790/490/390/330 breakpoint size is now a clamp()
   token (min at small phones → max at normal/desktop widths).
   Only behaviour switches (desktop borders, tab arrows, hiding
   scrollbars on phones, one hidden label) keep a media query.
═══════════════════════════════════════════════════════════ */
#searchModal {
    --sm-red:#FF2D2D; --sm-red-h:#ff1a1a; --sm-red-l:#ff8a8a;
    --sm-red-dim:rgba(255,45,45,.14); --sm-red-glow:rgba(255,45,45,.3);
    --sm-blue:#2a5fd8; --sm-blue-h:#234fb5; --sm-blue-l:#7ea6ff;
    --sm-grey:rgba(var(--sm-fg),.14); --sm-grey-h:rgba(var(--sm-fg),.22);
    --sm-card:rgba(var(--sm-fg),.05); --sm-card2:rgba(var(--sm-fg),.08);
    --sm-line:rgba(var(--sm-fg),.09); --sm-line2:rgba(var(--sm-fg),.14);
    --sm-sub:rgba(var(--sm-fg),.62); --sm-mut:rgba(var(--sm-fg),.45);
    --sm-fg:255,255,255; --sm-ink:#fff;
    --sm-bg:linear-gradient(-45deg,#010218,#070924,#070924);
    --sm-solid:#070924; --sm-ovl:rgba(7,9,36,.86);
    --sm-bs:1;
    --sm-lane:640px;
    --sm-pad:clamp(9px,3vw,16px);
    --sm-act-h:clamp(25px,7.2vw,30px);
    --sm-sb-h:clamp(34px,9.4vw,40px);
    --sm-r:9px;
    --sm-rb:6px;
    --sm-av:clamp(36px,9.5vw,44px);

    /* fluid type + size tokens (old @490 / @390 values → clamp minimums) */
    --sm-fs:      clamp(.7rem, .6rem + .5vw, .8rem);
    --sm-tb-h:    clamp(44px, 20px + 6vw, 54px);
    --sm-bk:      clamp(36px, 30px + 1.4vw, 40px);
    --sm-name:    clamp(.72rem, .66rem + .2vw, .78rem);
    --sm-sub-fs:  clamp(.6rem, .57rem + .12vw, .64rem);
    --sm-btn-fs:  clamp(.64rem, .6rem + .15vw, .7rem);
    --sm-cp:      clamp(12px, 10.5px + .4vw, 14px);
    --sm-cname:   clamp(.76rem, .7rem + .2vw, .84rem);
    --sm-csub:    clamp(.62rem, .58rem + .12vw, .68rem);
    --sm-cbody:   clamp(.66rem, .62rem + .15vw, .72rem);

    display: none;
    position: fixed;
    inset: 0;
    z-index: 999998;
    background: var(--sm-bg);
    background-attachment: fixed;
    flex-direction: column;
    overflow: hidden;
    touch-action: none;
    font-family: 'Poppins', sans-serif;
    font-size: var(--sm-fs);
    color: var(--sm-ink);
    -webkit-font-smoothing: antialiased;
}

#searchModal.sm-open { display: flex; animation: smSlideIn 0.28s cubic-bezier(0.22,1,0.36,1) forwards; }
@keyframes smSlideIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

#searchModal *, #searchModal *::before, #searchModal *::after { box-sizing: border-box; }
#searchModal button, #searchModal input { font-family: 'Poppins', sans-serif; }
#searchModal button { cursor: pointer; -webkit-tap-highlight-color: transparent; }
#searchModal button, #searchModal a, #searchModal input { outline: none; }
#searchModal a { color: inherit; text-decoration: none; }
#searchModal [class^="ri-"], #searchModal [class*=" ri-"],
#searchModal [class^="ri-"]::before, #searchModal [class*=" ri-"]::before {
    font-family: 'remixicon' !important; font-style: normal; font-weight: normal; line-height: 1;
}

.sm-view { display: none; flex: 1; min-height: 0; width: 100%; flex-direction: column; overflow: hidden; background: transparent; }
.sm-view.sm-view-active { display: flex; animation: smViewIn 0.16s ease; }
@keyframes smViewIn { from { opacity: 0; } to { opacity: 1; } }
#smViewResults { position: relative; }

/* full-width scrollers → scrollbar at the far right */
#searchModalContent, .srm-scroll {
    flex: 1; min-height: 0; width: 100%;
    overflow-y: auto; overflow-x: hidden;
    display: flex; flex-direction: column;
    -webkit-overflow-scrolling: touch; overscroll-behavior: contain; touch-action: pan-y;
    scrollbar-width: thin; scrollbar-color: rgba(var(--sm-fg),.28) transparent;
}
#searchModalContent::-webkit-scrollbar, .srm-scroll::-webkit-scrollbar { width: 8px; }
#searchModalContent::-webkit-scrollbar-track, .srm-scroll::-webkit-scrollbar-track { background: transparent; }
#searchModalContent::-webkit-scrollbar-thumb, .srm-scroll::-webkit-scrollbar-thumb { background: rgba(var(--sm-fg),.26); border-radius: 99px; }
#searchModalContent::-webkit-scrollbar-thumb:hover, .srm-scroll::-webkit-scrollbar-thumb:hover { background: rgba(var(--sm-fg),.4); }

/* centered lane — max-width never exceeds the screen, so no breakpoint needed */
.sm-in, #smResultsInner {
    flex: 1 0 auto; width: 100%; max-width: min(var(--sm-lane), 100%); margin: 0 auto; min-width: 0;
    padding-bottom: calc(18px + env(safe-area-inset-bottom));
}
@media (min-width: 801px) {
    .sm-in, #smResultsInner { border-left: 1px solid var(--sm-line); border-right: 1px solid var(--sm-line); }
}

.srm-loading {
    display: none; position: absolute; inset: 0; z-index: 8;
    align-items: center; justify-content: center;
    background: var(--sm-ovl); backdrop-filter: blur(1px);
}
.srm-loading.srm-loading-active { display: flex; }
.srm-loading i { font-size: 30px; color: rgba(var(--sm-fg),0.55); }

/* top bars */
.sm-topbar, .srm-topbar {
    display: flex; align-items: center; gap: 6px;
    min-height: var(--sm-tb-h);
    padding: calc(env(safe-area-inset-top) + 14px) var(--sm-pad) 8px 0;
    background: transparent;
}
.sm-back-btn {
    width: var(--sm-bk); height: var(--sm-bk); flex-shrink: 0;
    background: none; border: none; color: var(--sm-ink);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; padding: 0; margin-left: 0; opacity: .9; transition: 0.2s;
}
.sm-back-btn:hover { color: var(--sm-red); opacity: 1; }
.sm-back-btn:active { opacity: .6; }

#modalSearchWrap {
    flex: 1; min-width: 0; display: flex; align-items: center;
    height: var(--sm-sb-h);
    background: var(--sm-card); border: 1px solid var(--sm-line2);
    border-radius: var(--sm-r); overflow: hidden;
    transition: border-color .2s, box-shadow .2s;
}
#modalSearchWrap.focused { border-color: rgba(255,45,45,.85); box-shadow: 0 0 0 3px rgba(255,45,45,.16); }

.sm-search-icon, .srm-pill-icon {
    padding: 0 clamp(5px,4px + .3vw,6px) 0 clamp(10px,9px + .4vw,12px);
    color: var(--sm-mut); font-size: clamp(15px,14.5px + .15vw,16px); flex-shrink: 0; display: flex; align-items: center;
}
#modalSearchInput {
    flex: 1; min-width: 0; height: 100%; padding: 0 6px; background: transparent; border: none; outline: none;
    color: var(--sm-ink); font-size: var(--sm-fs);
}
#modalSearchInput::placeholder { color: var(--sm-mut); }
#modalClearBtn {
    display: none; background: transparent; border: none; color: var(--sm-mut);
    padding: 0 12px; font-size: 17px; flex-shrink: 0; line-height: 1; height: 100%; transition: color .2s;
}
#modalClearBtn:hover { color: var(--sm-ink); }

#smSearchBtn {
    display: none; align-items: center; flex-shrink: 0; height: 100%; background: transparent; border: none;
    color: var(--sm-red); font-size: clamp(.68rem,.64rem + .2vw,.74rem); font-weight: 700;
    padding: 0 clamp(10px,9px + .35vw,13px); white-space: nowrap; transition: opacity .2s;
}
#smSearchBtn:hover  { opacity: .75; }
#smSearchBtn:active { opacity: .5; }

/* recents */
#searchRecents { padding: 6px 0 0; }
.sm-recents-header {
    display: flex; align-items: center; justify-content: space-between;
    margin: 0 var(--sm-pad); padding: 8px 0 10px; border-bottom: 1px solid var(--sm-line2);
}
.sm-recents-title { font-size: .64rem; font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--sm-sub); }
.sm-recents-actions { display: flex; align-items: center; gap: 10px; }
#smClearAllBtn { display: none; background: none; border: none; color: var(--sm-red); font-size: .68rem; font-weight: 600; transition: color .2s; }
#smClearAllBtn:hover { color: var(--sm-ink); }
.sm-no-recents { color: var(--sm-mut); font-size: .72rem; font-style: italic; padding: 12px var(--sm-pad) 22px; }

#smSeeMoreRow { display: flex; align-items: center; gap: 8px; padding: 6px var(--sm-pad) 8px; }
#smSeeMoreBtn, #smCollapseBtn, #smCollapseBtn2 {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: .68rem; font-weight: 600; color: var(--sm-red);
    background: none; border: none; padding: 4px 0; transition: color .2s;
}
#smSeeMoreBtn:hover, #smCollapseBtn:hover, #smCollapseBtn2:hover { color: var(--sm-ink); }
#smSeeMoreBtn i, #smCollapseBtn i, #smCollapseBtn2 i { font-size: 13px; }

.sm-recent-row {
    display: flex; align-items: center; gap: 10px;
    margin: 0 var(--sm-pad); padding: 11px 0; border-bottom: 1px solid var(--sm-line);
    cursor: pointer; transition: opacity .15s; -webkit-tap-highlight-color: transparent;
}
.sm-recent-row:active { opacity: .55; }

/* live results */
#searchLiveResults { display: none; padding: 0; }
.sm-sec {
    display: flex; align-items: center; font-size: .64rem; font-weight: 600; letter-spacing: .6px;
    color: var(--sm-sub); text-transform: uppercase; padding: 14px var(--sm-pad) 4px;
}
.sm-sec.pid-sec { color: var(--sm-red); }
.sm-res-row { display: flex; align-items: center; gap: 10px; padding: 9px var(--sm-pad); cursor: pointer; transition: background .15s; -webkit-tap-highlight-color: transparent; }
.sm-res-row:hover, .sm-res-row:active { background: var(--sm-card2); }

.sm-r-avatar, .sm-res-avatar { width: var(--sm-av); height: var(--sm-av); border-radius: 50%; object-fit: cover; border: 2px solid var(--sm-red); flex-shrink: 0; }
.sm-r-avatar.grp, .sm-res-avatar.grp { border-radius: 8px; border-color: var(--sm-blue); }
.sm-r-avatar.meme-av, .sm-res-avatar.meme { border-radius: 8px; border-color: var(--sm-line2); }
.sm-r-avatar.pid-av, .sm-res-avatar.pid { border-color: var(--sm-red); }
/* suspended account / pod: plain light tile (a slightly lighter shade of the modal background) with the usual ring, and "Suspended" instead of the nickname / members */
.sm-r-avatar.sm-susp-av, .sm-res-avatar.sm-susp-av { display: block; background: rgba(255,255,255,.1); }
.sm-susp-txt { color: var(--sm-red); font-weight: 700; }
.sm-susp-txt.blue { color: var(--sm-blue); }

.sm-pg-av { position: relative; display: block; flex-shrink: 0; }
.sm-pg-badge {
    position: absolute; right: -4px; bottom: -4px; width: 15px; height: 15px; border-radius: 50%;
    background: var(--sm-red); color: #000; font-size: 8px;
    display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 2px var(--sm-solid);
}

.sm-r-query-icon { width: var(--sm-av); height: var(--sm-av); border-radius: 50%; background: var(--sm-card2); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sm-r-query-icon i { font-size: 16px; color: var(--sm-mut); }

.sm-r-icon-box, .sm-res-icon-box {
    width: var(--sm-av); height: var(--sm-av); border-radius: 8px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: var(--sm-red-dim); border: 1.5px solid rgba(255,45,45,.3);
}
.sm-r-icon-box i, .sm-res-icon-box i { color: var(--sm-red); font-size: 18px; }

.sm-r-video-wrap, .sm-res-video-wrap {
    width: var(--sm-av); height: var(--sm-av); border-radius: 8px; border: 2px solid var(--sm-line2);
    background: #000; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; position: relative; overflow: hidden;
}
.sm-r-video-wrap video, .sm-res-video-wrap video { width: 100%; height: 100%; object-fit: cover; border-radius: 6px; }
.sm-play-overlay { position: absolute; color: rgba(255,255,255,.8); font-size: 16px; pointer-events: none; }

.sm-r-info, .sm-res-info { flex: 1; min-width: 0; }
.sm-r-name, .sm-res-name {
    font-size: var(--sm-name); font-weight: 600; color: var(--sm-ink);
    display: flex; align-items: center; gap: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sm-res-name.meme-caption { color: rgba(var(--sm-fg),.88); font-weight: 500; }
.sm-r-sub, .sm-res-sub {
    font-size: var(--sm-sub-fs); color: var(--sm-mut); margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 5px;
}
.sm-res-icon { color: rgba(var(--sm-fg),.3); font-size: 15px; flex-shrink: 0; }
.sm-res-icon.red { color: var(--sm-red); }
.sm-hl, .sm-hl-pid { color: var(--sm-red); font-weight: 700; }

.sm-rm-btn {
    background: transparent; border: none; color: rgba(var(--sm-fg),.28);
    font-size: clamp(17px,16px + .3vw,19px); padding: clamp(3px,2.5px + .15vw,4px) clamp(5px,4.5px + .15vw,6px);
    flex-shrink: 0; line-height: 1; display: flex; align-items: center; justify-content: center; transition: color .2s;
}
.sm-rm-btn:hover { color: rgba(var(--sm-fg),.65); }

.sm-nsfw-badge {
    display: inline-flex; align-items: center; background: var(--sm-red-dim); border: 1px solid rgba(255,45,45,.45);
    border-radius: 4px; padding: 1px 5px; font-size: 9px; font-weight: 800; color: var(--sm-red);
    letter-spacing: .5px; text-transform: uppercase; flex-shrink: 0; line-height: 1.4;
}
.sm-muted-icon { display: inline-flex; align-items: center; color: rgba(255,200,50,.7); font-size: 11px; flex-shrink: 0; }
.sm-notif-off-icon { display: inline-flex; align-items: center; color: rgba(var(--sm-fg),.35); font-size: 11px; flex-shrink: 0; }
.sm-pid-badge { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; font-weight: 600; color: var(--sm-red); letter-spacing: .04em; }
.sm-pid-badge i { font-size: 10px; }
.sm-pid-pill {
    display: inline-flex; align-items: center; gap: 3px; background: var(--sm-red-dim); border: 1px solid rgba(255,45,45,.3);
    border-radius: 4px; padding: 1px 7px 1px 5px; font-size: 10px; font-weight: 600; color: var(--sm-red); letter-spacing: .05em; white-space: nowrap;
}
.sm-pid-pill i { font-size: 9px; }
.sm-pid-pill.pd { background: rgba(42,95,216,.14); border-color: rgba(42,95,216,.4); color: var(--sm-blue-l); }
.sm-hl-pid.pd, .sm-pid-pill.pd .sm-hl-pid { color: #fff; }

.sm-full-search-row { border-top: 1px solid var(--sm-line); margin: 6px var(--sm-pad) 0; padding: 12px 0 0; }
.sm-full-search-icon { width: var(--sm-av); height: var(--sm-av); border-radius: 50%; background: var(--sm-red-dim); border: 2px solid rgba(255,45,45,.55); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sm-full-search-icon i { color: var(--sm-red); font-size: 16px; }
.sm-full-search-label { color: var(--sm-red); }

.sm-loader { padding: 28px; text-align: center; color: var(--sm-mut); }
.sm-loader i { font-size: 22px; color: var(--sm-red); }
.sm-spin { animation: smSpin 0.7s linear infinite; display: inline-block; }
@keyframes smSpin { to { transform: rotate(360deg); } }

/* empty state */
.sm-es {
    min-height: 46vh; padding: clamp(28px,8vw,48px) clamp(20px,7vw,36px) clamp(36px,10vw,60px);
    display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
}
.sm-es-title { font-size: clamp(.95rem,3.4vw,1.1rem); font-weight: 700; color: var(--sm-ink); letter-spacing: -.2px; max-width: 100%; word-break: break-word; }
.sm-es-text { margin-top: 6px; max-width: 340px; font-size: clamp(.66rem,2.1vw,.76rem); color: var(--sm-mut); line-height: 1.7; }
.sm-es-tips { width: 100%; max-width: 360px; margin-top: clamp(16px,5vw,24px); display: flex; flex-direction: column; gap: 7px; }
.sm-es-tip { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 4px; background: rgba(var(--sm-fg),.05); text-align: left; }
.sm-es-tip i { font-size: 1rem; color: var(--sm-red); flex-shrink: 0; }
.sm-es-tip span { font-size: clamp(.62rem,2vw,.7rem); color: var(--sm-sub); line-height: 1.5; }
.sm-es-btn {
    margin-top: clamp(16px,5vw,22px); display: flex; align-items: center; justify-content: center;
    height: var(--sm-act-h); min-width: 150px; padding: 0 22px; border: none; border-radius: 4px;
    background: rgba(var(--sm-fg),.1); color: var(--sm-ink); font-size: clamp(.62rem,.56rem + .3vw,.72rem); font-weight: 600;
    transition: background .2s ease, transform .15s ease;
}
.sm-es-btn:hover { background: rgba(var(--sm-fg),.2); }
.sm-es-btn:active { transform: scale(.98); }

/* action buttons (no borders, ever) */
#searchModal .sm-track-btn, #searchModal .sm-group-btn,
#searchModal .follow-btn, #searchModal .group-btn, #searchModal .page-follow-btn,
#searchModal .srm-card-track-btn, #searchModal .srm-card-group-btn { border: none !important; box-shadow: none !important; outline: none !important; }
.sm-track-btn, .sm-group-btn {
    flex-shrink: 0; height: var(--sm-act-h); padding: 0 clamp(11px,9px + .7vw,14px);
    border-radius: var(--sm-rb); font-size: var(--sm-btn-fs); font-weight: 700; line-height: 1; white-space: nowrap;
    transition: background .2s, transform .15s;
}
.sm-track-btn:active, .sm-group-btn:active { transform: scale(.97); }
#searchModal .sm-track-btn { background: var(--sm-red); color: #000; }
#searchModal .sm-track-btn:hover { background: var(--sm-red-h); }
#searchModal .sm-track-btn.tracking, #searchModal .sm-track-btn.following { background: var(--sm-grey); color: var(--sm-ink); }
#searchModal .sm-track-btn.tracking:hover { background: var(--sm-grey-h); }
#searchModal .sm-group-btn { background: var(--sm-blue); color: #000; }
#searchModal .sm-group-btn:hover { background: var(--sm-blue-h); }
#searchModal .sm-group-btn.joined { background: var(--sm-grey); color: var(--sm-ink); }
#searchModal .sm-group-btn.joined:hover { background: var(--sm-grey-h); }
.sm-track-btn:disabled, .sm-group-btn:disabled { opacity: .6; }

.sm-private-label {
    flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px; height: var(--sm-act-h); padding: 0 10px;
    font-size: .64rem; font-weight: 600; color: rgba(var(--sm-fg),.8); white-space: nowrap; cursor: default; line-height: 1;
    border-radius: var(--sm-rb); background: var(--sm-grey); border: none;
}
.sm-private-label.clickable { cursor: pointer; }
.sm-private-label.clickable:hover { background: var(--sm-grey-h); color: var(--sm-ink); }
.sm-private-label i { font-size: 11px; }
.sm-blocked-label {
    flex-shrink: 0; display: inline-flex; align-items: center; height: var(--sm-act-h); padding: 0 10px; border-radius: var(--sm-rb);
    background: var(--sm-red-dim); color: rgba(255,120,120,.9); font-size: .64rem; font-weight: 600; white-space: nowrap; cursor: default; line-height: 1;
}

/* suggestions */
#smSuggestionsWrap { padding: 4px 0 8px; }
.sm-tag-row {
    --tg:#FF2D2D; --tg-bg:rgba(255,45,45,.16);
    display: flex; align-items: center; gap: 11px; width: calc(100% - (var(--sm-pad) * 2)); margin: 0 var(--sm-pad);
    padding: 9px 0; background: none; border: none; border-bottom: 1px solid var(--sm-line);
    text-align: left; color: var(--sm-ink); transition: opacity .15s;
}
.sm-tag-row:active { opacity: .55; }
.sm-tag-v1 { --tg:#FF2D2D; --tg-bg:rgba(255,45,45,.16); }
.sm-tag-v2 { --tg:#ff5c4d; --tg-bg:rgba(255,92,77,.16); }
.sm-tag-v3 { --tg:#e11d48; --tg-bg:rgba(225,29,72,.18); }
.sm-tag-v4 { --tg:#ff8a8a; --tg-bg:rgba(255,138,138,.15); }
.sm-tag-v5 { --tg:#c81e1e; --tg-bg:rgba(200,30,30,.2); }
.sm-tag-v6 { --tg:#f43f5e; --tg-bg:rgba(244,63,94,.16); }
.sm-tag-icon-wrap { width: clamp(30px,8vw,36px); height: clamp(30px,8vw,36px); flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; background: var(--tg-bg); color: var(--tg); }
.sm-tag-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.sm-tag-name { font-size: clamp(.7rem,.66rem + .15vw,.76rem); font-weight: 600; color: var(--sm-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3; }
.sm-tag-sub  { font-size: var(--sm-sub-fs); color: var(--sm-mut); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3; }
.sm-tag-arr  { font-size: 16px; color: var(--tg); opacity: .65; flex-shrink: 0; margin-left: auto; }

/* ═══════ RESULTS VIEW ═══════ */
.srm-title-wrap { flex: 1; min-width: 0; }
.srm-title-row { display: flex; align-items: center; min-width: 0; }
.srm-search-pill {
    display: flex; align-items: center; flex: 1; min-width: 0; height: var(--sm-sb-h);
    background: var(--sm-card); border: 1px solid var(--sm-line2); border-radius: var(--sm-r); overflow: hidden;
    transition: border-color .2s, background .2s;
}
.srm-search-pill:hover { border-color: rgba(255,45,45,.6); background: var(--sm-card2); }
.srm-search-pill-text {
    flex: 1; min-width: 0; height: 100%; background: transparent; border: none; color: var(--sm-ink);
    font-size: var(--sm-fs); font-weight: 600; text-align: left; padding: 0 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.srm-pill-divider { flex-shrink: 0; width: 1px; height: 55%; background: var(--sm-line2); }
.srm-clear-btn {
    flex-shrink: 0; height: 100%; display: flex; align-items: center; padding: 0 clamp(10px,9px + .35vw,13px);
    color: var(--sm-red); font-size: clamp(.68rem,.64rem + .2vw,.74rem); font-weight: 700; transition: color .2s;
}
.srm-clear-btn:hover { color: var(--sm-ink); }

.srm-tabs-wrap { position: sticky; top: 0; z-index: 5; margin-top: 4px; background: var(--sm-solid); border-bottom: 1px solid var(--sm-line); }
.srm-tabs {
    display: flex; gap: clamp(16px,5vw,26px); overflow-x: auto; padding: 10px var(--sm-pad) 0;
    scrollbar-width: none; -ms-overflow-style: none; -webkit-overflow-scrolling: touch;
}
.srm-tabs::-webkit-scrollbar { display: none; }
.srm-tab {
    flex-shrink: 0; background: transparent; border: none; color: rgba(var(--sm-fg),.7);
    font-size: clamp(.66rem,.58rem + .3vw,.78rem); font-weight: 700; white-space: nowrap;
    padding: 0 0 clamp(9px,8.5px + .1vw,10px); position: relative; transition: color .18s;
}
.srm-tab:hover, .srm-tab.active { color: var(--sm-ink); }
.srm-tab.active::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 3px; border-radius: 2px 2px 0 0; background: var(--sm-ink); }

.srm-tab-arrow { display: none; position: absolute; top: 0; bottom: 1px; width: 38px; z-index: 2; border: none; color: var(--sm-ink); font-size: 20px; align-items: center; }
.srm-tab-arrow.l { left: 0;  justify-content: flex-start; padding-left: 6px;  background: linear-gradient(90deg, var(--sm-solid) 55%, transparent); }
.srm-tab-arrow.r { right: 0; justify-content: flex-end;   padding-right: 6px; background: linear-gradient(270deg, var(--sm-solid) 55%, transparent); }
.srm-tab-arrow:hover { color: var(--sm-red); }
@media (min-width: 769px) and (hover: hover) {
    .srm-tabs-wrap.can-left  .srm-tab-arrow.l { display: flex; }
    .srm-tabs-wrap.can-right .srm-tab-arrow.r { display: flex; }
}

.srm-content { padding: 3px 0 0; }
.srm-section { margin: 2px 0 12px; padding-bottom: 6px; border-bottom: 1px solid var(--sm-line); }
.srm-section:last-child { border-bottom: none; }
.srm-section.srm-hidden { display: none !important; }
.srm-sec-title { display: flex; align-items: center; gap: 6px; font-size: .64rem; font-weight: 600; letter-spacing: .6px; text-transform: uppercase; color: var(--sm-sub); padding: 14px var(--sm-pad) 8px; }
.srm-content.srm-hide-titles .srm-sec-title { display: none; }

.srm-row { display: flex; align-items: center; gap: 10px; padding: 9px var(--sm-pad); cursor: pointer; transition: background .15s; -webkit-tap-highlight-color: transparent; }
.srm-row:hover, .srm-row:active { background: var(--sm-card2); }
.srm-icon-box { width: var(--sm-av) !important; height: var(--sm-av) !important; border-radius: 8px !important; flex-shrink: 0; }
.srm-icon-box i { font-size: 18px !important; }
.srm-info { flex: 1; min-width: 0; }
.srm-name { font-size: var(--sm-name); font-weight: 600; color: var(--sm-ink); display: flex; align-items: center; gap: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.srm-sub { font-size: var(--sm-sub-fs); color: var(--sm-mut); margin-top: 2px; display: flex; align-items: center; gap: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.srm-blocked-txt { color: #ff5a5a; font-weight: 700; font-size: .62rem; margin-left: 3px; }
.srm-arrow { font-size: 16px; flex-shrink: 0; }

/* posts grid */
.srm-posts-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; padding: 4px var(--sm-pad) 8px; }
@media (min-width: 700px) { .srm-posts-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; } }
.srm-post-card { cursor: pointer; -webkit-tap-highlight-color: transparent; min-width: 0; }
.srm-post-media { position: relative; width: 100%; padding-top: 133.33%; overflow: hidden; background: var(--sm-card); }
.srm-post-media img, .srm-post-media video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }
.srm-post-play { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); color: rgba(255,255,255,.9); font-size: 26px; text-shadow: 0 2px 8px rgba(0,0,0,.6); pointer-events: none; }
.srm-post-date { position: absolute; top: 8px; left: 8px; background: rgba(0,0,0,.55); backdrop-filter: blur(2px); color: #fff; font-size: .62rem; font-weight: 700; padding: 3px 7px; border-radius: 4px; pointer-events: none; }
.srm-post-text-overlay {
    position: absolute; top: 50%; left: 0; right: 0; transform: translateY(-50%); padding: 0 16px; color: #fff;
    font-size: .7rem; font-weight: 700; text-align: center; line-height: 1.5; text-shadow: 0 1px 6px rgba(0,0,0,.7);
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 5; overflow: hidden;
}
.srm-post-caption { margin-top: 6px; font-size: clamp(.66rem,.62rem + .15vw,.72rem); font-weight: 500; color: rgba(var(--sm-fg),.88); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.srm-post-meta { display: flex; align-items: center; gap: 5px; margin-top: 5px; min-width: 0; }
.srm-post-avatar { width: clamp(19px,18px + .2vw,22px); height: clamp(19px,18px + .2vw,22px); border-radius: 50%; object-fit: cover; border: 2px solid var(--sm-red); flex-shrink: 0; }
.srm-post-meta .sm-vb { width: 1.05em; height: 1.05em; margin-left: 3px; }
.srm-post-stack { display: inline-flex; align-items: center; flex-shrink: 0; }
.srm-post-stack .srm-post-avatar + .srm-post-avatar { margin-left: -7px; }
.srm-post-stack .srm-post-avatar:nth-child(1) { position: relative; z-index: 3; }
.srm-post-stack .srm-post-avatar:nth-child(2) { position: relative; z-index: 2; }
.srm-post-stack .srm-post-avatar:nth-child(3) { position: relative; z-index: 1; }
.srm-post-username { font-size: clamp(.66rem,.62rem + .15vw,.72rem); font-weight: 700; color: rgba(var(--sm-fg),.75); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }

/* cards */
.srm-card { margin: 9px var(--sm-pad) 12px; border-radius: var(--sm-r); background: var(--sm-card); border: 1px solid var(--sm-line); overflow: hidden; transition: border-color .15s, background .15s; }
.srm-card:hover { border-color: rgba(255,45,45,.25); background: var(--sm-card2); }
.srm-card-group:hover { border-color: rgba(42,95,216,.4); }

.srm-card-header { display: flex; align-items: center; gap: clamp(10px,9px + .3vw,12px); padding: clamp(11px,10px + .35vw,13px) var(--sm-cp); cursor: pointer; -webkit-tap-highlight-color: transparent; }
.srm-card-header-split { justify-content: space-between; padding-right: 12px; }
.srm-card-header-left { display: flex; align-items: center; gap: clamp(10px,9px + .3vw,12px); cursor: pointer; flex: 1; min-width: 0; }
.srm-card-avatar { width: clamp(40px,11vw,48px); height: clamp(40px,11vw,48px); border-radius: 50%; object-fit: cover; flex-shrink: 0; }
.srm-avatar-red  { border: 2.5px solid var(--sm-red); }
.srm-susp-av { display: block; background: rgba(255,255,255,.1); }
.srm-avatar-blue { border: 2.5px solid var(--sm-blue); }
.srm-card-avatar-grp { border-radius: 9px; }
.srm-card-headinfo { flex: 1; min-width: 0; }
.srm-card-name { font-size: var(--sm-cname); font-weight: 700; color: var(--sm-ink); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.srm-card-sub { font-size: var(--sm-csub); color: rgba(var(--sm-fg),.5); margin-top: 3px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
.srm-card-sub-muted { color: rgba(var(--sm-fg),.35); }
.srm-card-owner { color: rgba(var(--sm-fg),.7); font-weight: 600; }
.srm-card-dot { color: rgba(var(--sm-fg),.25); font-size: 10px; }

.srm-card-track-btn {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    width: calc(100% - (var(--sm-cp) * 2)); margin: 0 var(--sm-cp) clamp(10px,9px + .3vw,12px);
    height: calc(var(--sm-act-h) + 3px); border-radius: var(--sm-rb);
    font-size: clamp(.68rem,.63rem + .2vw,.74rem); font-weight: 700; line-height: 1; transition: background .2s, transform .15s;
}
.srm-card-track-btn:active { transform: scale(.985); }
#searchModal .srm-btn-red { background: var(--sm-red); color: #000; }
#searchModal .srm-btn-red:hover { background: var(--sm-red-h); }
#searchModal .srm-btn-red.tracking, #searchModal .srm-btn-red.following { background: var(--sm-grey); color: var(--sm-ink); }
#searchModal .srm-btn-red.tracking:hover, #searchModal .srm-btn-red.following:hover { background: var(--sm-grey-h); }
#searchModal .srm-btn-red.srm-card-private-btn { background: var(--sm-grey); color: var(--sm-ink); }
#searchModal .srm-btn-red.srm-card-private-btn:hover { background: var(--sm-grey-h); }
.srm-card-track-btn:disabled { opacity: .6; }
.srm-card-blocked-row { padding: 0 var(--sm-cp) clamp(10px,9px + .3vw,12px); }
.srm-blocked-label { display: inline-flex; align-items: center; height: var(--sm-act-h); padding: 0 12px; font-size: .66rem; font-weight: 600; color: rgba(255,120,120,.9); background: var(--sm-red-dim); border-radius: var(--sm-rb); }
.srm-card-group-btn {
    flex-shrink: 0; height: calc(var(--sm-act-h) + 2px); padding: 0 clamp(14px,12px + .7vw,18px);
    border-radius: var(--sm-rb); font-size: clamp(.66rem,.62rem + .2vw,.72rem); font-weight: 700; line-height: 1; transition: background .2s, transform .15s;
}
.srm-card-group-btn:active { transform: scale(.97); }
#searchModal .srm-btn-blue { background: var(--sm-blue); color: #000; }
#searchModal .srm-btn-blue:hover { background: var(--sm-blue-h); }
#searchModal .srm-btn-blue.joined { background: var(--sm-grey); color: var(--sm-ink); }
#searchModal .srm-btn-blue.joined:hover { background: var(--sm-grey-h); }
.srm-card-group-btn:disabled { opacity: .6; }

.srm-card-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: clamp(4px,3.5px + .15vw,5px); padding: 0 var(--sm-cp) var(--sm-cp); }
.srm-card-tile { position: relative; aspect-ratio: 1; border-radius: var(--sm-rb); overflow: hidden; background: var(--sm-card); cursor: pointer; -webkit-tap-highlight-color: transparent; }
.srm-card-tile img, .srm-card-tile video { width: 100%; height: 100%; object-fit: cover; display: block; }
.srm-card-tile-play { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); color: rgba(255,255,255,.9); font-size: 16px; text-shadow: 0 1px 4px rgba(0,0,0,.6); pointer-events: none; }
.srm-card-tile-empty { display: flex; align-items: center; justify-content: center; color: rgba(var(--sm-fg),.12); font-size: 17px; }
.srm-checkout-tile { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; background: var(--sm-grey); padding: 4px 3px; }
.srm-checkout-tile img.srm-checkout-avatar { width: 48%; height: auto; aspect-ratio: 1; object-fit: cover; flex-shrink: 0; display: block; margin-bottom: 2px; }
.srm-checkout-avatar-user { border-radius: 50%; border: 1.6px solid var(--sm-red); }
.srm-checkout-avatar-grp  { border-radius: 14%; border: 1.6px solid var(--sm-blue); }
.srm-checkout-label { font-size: 7px; font-weight: 700; color: rgba(var(--sm-fg),.6); text-align: center; line-height: 1.1; }
.srm-checkout-name { font-size: clamp(.58rem,.55rem + .1vw,.64rem); font-weight: 800; color: var(--sm-ink); text-align: center; line-height: 1.2; word-break: break-word; max-width: 100%; margin-bottom: 2px; }
.srm-checkout-btn { display: block; width: 86%; text-align: center; padding: clamp(2px,1.5px + .1vw,3px) 2px; border-radius: 4px; font-size: clamp(.54rem,.52rem + .1vw,.58rem); font-weight: 700; color: #000; }
.srm-checkout-red .srm-checkout-btn { background: var(--sm-red); }
.srm-checkout-blue .srm-checkout-btn { background: var(--sm-blue); }
.srm-checkout-tile:hover { background: var(--sm-grey-h); }
@media (max-width: 490px) { .srm-checkout-label { display: none; } }   /* show/hide only — not a size */

.srm-card-bio, .srm-card-desc { padding: 0 var(--sm-cp) var(--sm-cp); font-size: var(--sm-cbody); color: rgba(var(--sm-fg),.58); line-height: 1.6; word-break: break-word; }
.srm-see-more { font-weight: 700; cursor: default; margin-left: 2px; white-space: nowrap; }
.srm-see-more-red  { color: var(--sm-red); }
.srm-see-more-blue { color: var(--sm-blue-l); }

.srm-card-wiki { cursor: pointer; }
.srm-wiki-hero { width: 100%; height: clamp(104px,28vw,150px); object-fit: cover; display: block; background: var(--sm-card); }
.srm-wiki-hero-fallback { display: flex; align-items: center; justify-content: center; color: rgba(255,45,45,.4); font-size: 34px; }
.srm-wiki-body { padding: clamp(11px,10px + .3vw,12px) var(--sm-cp) var(--sm-cp); }
.srm-wiki-title { font-size: var(--sm-cname); font-weight: 700; color: var(--sm-ink); display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.srm-wiki-status { font-size: .6rem; font-weight: 700; color: var(--sm-red); background: var(--sm-red-dim); border: 1px solid rgba(255,45,45,.3); padding: 2px 8px; border-radius: 4px; }
.srm-wiki-summary { font-size: clamp(.64rem,.6rem + .15vw,.7rem); color: rgba(var(--sm-fg),.55); margin-top: 7px; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.srm-wiki-meta { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: .66rem; color: var(--sm-mut); }
.srm-wiki-meta img { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(255,45,45,.5); }

/* phones: hide scrollbars (scrolling still works) */
@media (max-width: 768px) {
    #searchModalContent, .srm-scroll, .srm-tabs { scrollbar-width: none; -ms-overflow-style: none; }
    #searchModalContent::-webkit-scrollbar, .srm-scroll::-webkit-scrollbar { display: none; width: 0; }
}
@media (prefers-reduced-motion:reduce){ #searchModal *{ transition-duration:.01ms !important; animation-duration:.01ms !important; } }
/* ═══ LIGHT MODE ( <html data-theme="light"> ) — same palette as the other pages ═══ */
:root[data-theme="light"] #searchModal {
    color-scheme: light;
    --sm-fg:6,7,26; --sm-ink:#0b0d24;
    --sm-bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --sm-solid:#f4f5fb; --sm-ovl:rgba(244,245,251,.88);
    --sm-red:#e02323; --sm-red-h:#c91d1d; --sm-red-l:#d92d2d;
    --sm-red-dim:rgba(224,35,35,.12); --sm-red-glow:rgba(224,35,35,.22);
    --sm-blue-l:#2457c5;
}
:root[data-theme="light"] #searchModal .sm-track-btn,
:root[data-theme="light"] #searchModal .srm-btn-red,
:root[data-theme="light"] #searchModal .sm-group-btn,
:root[data-theme="light"] #searchModal .srm-btn-blue { color: #fff; }
:root[data-theme="light"] #searchModal .sm-track-btn.tracking, :root[data-theme="light"] #searchModal .sm-track-btn.following,
:root[data-theme="light"] #searchModal .srm-btn-red.tracking, :root[data-theme="light"] #searchModal .srm-btn-red.following,
:root[data-theme="light"] #searchModal .sm-group-btn.joined, :root[data-theme="light"] #searchModal .srm-btn-blue.joined,
:root[data-theme="light"] #searchModal .srm-btn-red.srm-card-private-btn { color: var(--sm-ink); }
:root[data-theme="light"] #modalSearchWrap { background: #fff; }

/* verified badge */
#searchModal .sm-vb { display: inline-block; width: 1.12em; height: 1.12em; flex-shrink: 0; margin-left: 4px; vertical-align: -.2em; color: var(--sm-red); }

/* back arrow: exactly the Zeems modal one */
#searchModal .sm-back-svg { height: calc((18px + 4 * clamp(0px, calc((100vw - 330px) / 460), 1px)) * var(--sm-bs)); width: auto; aspect-ratio: 14 / 24; display: block; overflow: visible; }
@media (max-width: 768px) {
    #searchModal { --sm-bs: 1.155; }
    /* iPhone zooms the page when an input's text is under 16px: keep it 16px but draw it at the old size */
    .sm-inbox { flex: 1; min-width: 0; height: 100%; display: block; overflow: hidden; }
    #modalSearchInput { font-size: 16px; width: 133.34%; height: 133.34%; padding: 0 8px; transform: scale(.75); transform-origin: 0 0; }
    .srm-tab { font-size: clamp(.705rem,.656rem + .246vw,.78rem); }
}
.sm-inbox { flex: 1; min-width: 0; height: 100%; display: block; }
</style>

<script>
var SM_VB = <?php echo json_encode($SM_VB); ?>;
/* ══════════════════════════════════════════════════════════════
   DEZZME SEARCH — UNIFIED MODAL ENGINE
══════════════════════════════════════════════════════════════ */
var SM_ENDPOINT   = 'search_recents.php';
var smTimer       = null;
var smLastQ       = '';
var smOpen        = false;
var SM_PAGE_SIZE  = 5;
var _smAllRecents = [];
var _smExpanded   = false;
var _smCurrentQuery = <?php echo json_encode($search_query ?? ''); ?>;
var _smFetchToken  = 0;

function smEsc(s){ var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function smAttr(s){ return smEsc(String(s == null ? '' : s)).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

function smHL(str, q){
    str = String(str == null ? '' : str);
    if(!q) return smEsc(str);
    var idx = str.toLowerCase().indexOf(String(q).toLowerCase());
    if(idx === -1) return smEsc(str);
    return smEsc(str.slice(0, idx)) + '<span class="sm-hl">' + smEsc(str.slice(idx, idx + q.length)) + '</span>' + smEsc(str.slice(idx + q.length));
}
function smHLpid(str, q){
    str = String(str == null ? '' : str);
    if(!q) return smEsc(str);
    var clean = String(q).replace(/^dz-?/i, '');
    if(!clean) return smEsc(str);
    var idx = str.toLowerCase().indexOf(clean.toLowerCase());
    if(idx === -1) return smEsc(str);
    return smEsc(str.slice(0, idx)) + '<span class="sm-hl-pid">' + smEsc(str.slice(idx, idx + clean.length)) + '</span>' + smEsc(str.slice(idx + clean.length));
}
function smNSFWbadge(isAdult){ return isAdult ? '<span class="sm-nsfw-badge">NSFW</span>' : ''; }
function smGroupStatusIcons(isMuted, isNotifOff){
    var h = '';
    if(isMuted)    h += '<span class="sm-muted-icon" title="Muted"><i class="ri-volume-mute-line"></i></span>';
    if(isNotifOff) h += '<span class="sm-notif-off-icon" title="Notifications off"><i class="ri-notification-off-line"></i></span>';
    return h;
}
function smShortNum(n){
    n = parseInt(n) || 0;
    if(n >= 1000000) return (Math.round(n / 100000) / 10) + 'M';
    if(n >= 1000)    return (Math.round(n / 100) / 10) + 'K';
    return String(n);
}
function smPageAvatar(cls, src){
    return '<span class="sm-pg-av"><img class="' + cls + '" src="' + smAttr(src) + '" onerror="this.src=\'mee.jpg\'">'
         + '<span class="sm-pg-badge"><i class="ri-flag-2-fill"></i></span></span>';
}
function smPageName(p){ return p.name || p.title || 'Page'; }
function smPagePic(p){ return p.pic || p.profile_pic || 'mee.jpg'; }

function smImgFallback(el, boxClass, iconClass){
    var box = document.createElement('div');
    box.className = boxClass;
    box.innerHTML = '<i class="' + iconClass + '"></i>';
    if(el && el.parentNode) el.parentNode.replaceChild(box, el);
}
function smVidFallback(el, boxClass, iconClass){
    var box = document.createElement('div');
    box.className = boxClass;
    box.innerHTML = '<i class="' + iconClass + '"></i>';
    var wrap = el.closest('.sm-res-video-wrap, .sm-r-video-wrap') || el.parentNode;
    if(wrap && wrap.parentNode) wrap.parentNode.replaceChild(box, wrap);
}
function smEs(title, text, tips, btn){
    var h = '<div class="sm-es"><div class="sm-es-title">' + title + '</div><p class="sm-es-text">' + text + '</p>';
    if(tips && tips.length){
        h += '<div class="sm-es-tips">' + tips.map(function(t){
            return '<div class="sm-es-tip"><i class="' + t[0] + '"></i><span>' + t[1] + '</span></div>';
        }).join('') + '</div>';
    }
    if(btn) h += '<button type="button" class="sm-es-btn" onclick="' + btn[1] + '">' + btn[0] + '</button>';
    return h + '</div>';
}
function smNavigateInApp(href){ location.href = href; }

/* ── server-side recents ── */
function smGetRecents(cb){ fetch(SM_ENDPOINT + '?action=get').then(function(r){ return r.json(); }).then(function(d){ cb(Array.isArray(d) ? d : []); }).catch(function(){ cb([]); }); }
function smGetSuggestions(cb){ fetch(SM_ENDPOINT + '?action=suggestions').then(function(r){ return r.json(); }).then(function(d){ cb(Array.isArray(d) ? d : []); }).catch(function(){ cb([]); }); }
function smAddRecent(item){ fetch(SM_ENDPOINT + '?action=add', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(item) }).catch(function(){}); }
function smRemoveRecent(type, id){
    fetch(SM_ENDPOINT + '?action=remove', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ type:type, id:id }) })
    .then(function(){ smRenderRecents(); }).catch(function(){ smRenderRecents(); });
}
function smRemoveRecentBtn(btn){ smRemoveRecent(btn.getAttribute('data-type'), btn.getAttribute('data-id')); }
function clearAllRecents(){
    fetch(SM_ENDPOINT + '?action=clear', { method:'POST', headers:{'Content-Type':'application/json'}, body:'{}' })
    .then(function(){ _smExpanded = false; smRenderRecents(); }).catch(function(){ _smExpanded = false; smRenderRecents(); });
}

/* ════════════ PTR LOCK (lets the Zeems modal keep its own touches) ════════════ */
var _smPtrLocked = false;
function _smLockPTR(){
    if(_smPtrLocked) return;
    _smPtrLocked = true;
    document.addEventListener('touchstart', _smPtrBlocker, { passive:false, capture:true });
    document.addEventListener('touchmove',  _smPtrBlocker, { passive:false, capture:true });
}
function _smUnlockPTR(){
    if(!_smPtrLocked) return;
    _smPtrLocked = false;
    document.removeEventListener('touchstart', _smPtrBlocker, { capture:true });
    document.removeEventListener('touchmove',  _smPtrBlocker, { capture:true });
}
function _smZeemsOpen(){
    var z = document.getElementById('cardCaptionModal');
    return !!(z && z.classList.contains('ccm-open'));
}
function _smPtrBlocker(e){
    if(_smZeemsOpen()) return;                       /* Zeems owns its touches */
    var shareP = document.getElementById('shareModalPanel');
    if(shareP && shareP.classList.contains('show') && shareP.contains(e.target)) return;
    var content = document.querySelector('.sm-view-active #searchModalContent, .sm-view-active .srm-scroll');
    if(content && content.contains(e.target)) return;
    if(smOpen) e.stopImmediatePropagation();
}

/* tab arrows (desktop only — CSS decides if they show) */
function smUpdateTabArrows(){
    var wrap = document.querySelector('#smViewResults .srm-tabs-wrap');
    if(!wrap) return;
    var tabs = wrap.querySelector('.srm-tabs');
    if(!tabs) return;
    var max = tabs.scrollWidth - tabs.clientWidth;
    wrap.classList.toggle('can-left',  tabs.scrollLeft > 4);
    wrap.classList.toggle('can-right', tabs.scrollLeft < max - 4);
}
function smQueueTabArrows(){ requestAnimationFrame(function(){ requestAnimationFrame(smUpdateTabArrows); }); }
window.addEventListener('resize', smUpdateTabArrows);

function smSwitchView(view){
    var vs = document.getElementById('smViewSearch'), vr = document.getElementById('smViewResults');
    if(!vs || !vr) return;
    if(view === 'results'){ vs.classList.remove('sm-view-active'); vr.classList.add('sm-view-active'); smQueueTabArrows(); }
    else { vr.classList.remove('sm-view-active'); vs.classList.add('sm-view-active'); }
}

function openSearchModal(){
    if(smOpen) return;
    smOpen = true;
    var m = document.getElementById('searchModal');
    m.style.display = 'flex';
    m.classList.add('sm-open');
    bringModalToFront(m);
    document.body.style.overflow = 'hidden';
    _smExpanded = false;
    _smLockPTR();
    smRenderRecents();
    smQueueTabArrows();
    setTimeout(function(){
        var vs = document.getElementById('smViewSearch');
        if(vs && vs.classList.contains('sm-view-active')){
            var inp = document.getElementById('modalSearchInput');
            if(inp) inp.focus();
        }
    }, 150);
}
function closeSearchModal(){
    if(!smOpen) return;
    smOpen = false;
    var m = document.getElementById('searchModal');
    m.style.display = 'none';
    m.classList.remove('sm-open');
    document.body.style.overflow = '';
    var inp = document.getElementById('modalSearchInput');
    if(inp) inp.value = '';
    document.getElementById('modalClearBtn').style.display     = 'none';
    document.getElementById('smSearchBtn').style.display       = 'none';
    document.getElementById('searchLiveResults').style.display = 'none';
    document.getElementById('searchRecents').style.display     = 'block';
    smLastQ = ''; _smExpanded = false;
    _smUnlockPTR();
    clearTimeout(smTimer);
}
function openSearchResultsModal(){ openSearchModal(); smSwitchView('results'); }
function closeSearchResultsModal(){ closeSearchModal(); }

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && smOpen && !_smZeemsOpen()) closeSearchModal();
});

/* block overscroll PTR inside modal */
(function(){
    document.addEventListener('touchstart', function(e){
        var sc = e.target.closest('#searchModalContent, .srm-scroll');
        if(sc) sc.dataset.smStartY = e.touches[0].clientY;
    }, { passive:true });
    document.addEventListener('touchmove', function(e){
        if(!smOpen) return;
        var sc = e.target.closest('#searchModalContent, .srm-scroll');
        if(!sc) return;
        var dy = e.touches[0].clientY - parseFloat(sc.dataset.smStartY || '0');
        if(sc.scrollTop <= 0 && dy > 0) e.stopPropagation();
    }, { passive:true, capture:false });
})();

var _smRecentCache = {};
function smExpandRecents(){ _smExpanded = true;  smDrawRecents(_smAllRecents); }
function smCollapseRecents(){ _smExpanded = false; smDrawRecents(_smAllRecents); }

function smDrawRecents(arr){
    var list = document.getElementById('recentsList'), clearBtn = document.getElementById('smClearAllBtn');
    var seeMoreRow = document.getElementById('smSeeMoreRow'), seeMoreBtn = document.getElementById('smSeeMoreBtn');
    var colBtn = document.getElementById('smCollapseBtn'), colBtn2 = document.getElementById('smCollapseBtn2');
    if(!list) return;
    _smRecentCache = {};

    var total = arr.length, hasMore = total > SM_PAGE_SIZE;
    var visible = _smExpanded ? arr : arr.slice(0, SM_PAGE_SIZE);
    clearBtn.style.display = total ? 'inline-block' : 'none';

    if(hasMore){
        seeMoreRow.style.display = 'flex';
        if(_smExpanded){ seeMoreBtn.style.display = 'none'; colBtn2.style.display = 'inline-flex'; colBtn.style.display = 'inline-flex'; }
        else { seeMoreBtn.style.display = 'inline-flex'; colBtn2.style.display = 'none'; colBtn.style.display = 'none'; }
    } else { seeMoreRow.style.display = 'none'; colBtn.style.display = 'none'; }

    document.getElementById('smSuggestionsWrap').style.display = 'block';
    if(!total){ list.innerHTML = '<div class="sm-no-recents">No recent searches yet</div>'; smLoadSuggestions(true); return; }
    smLoadSuggestions(false);

    list.innerHTML = visible.map(function(r, idx){
        var defPic = 'uploads/mee.jpeg', name, subParts = [], avatarHtml, nameSuffix = '';
        _smRecentCache[idx] = r;

        if(r.type === 'profile_id'){
            name = r.username || '';
            if(parseInt(r.is_suspended)){ subParts.push('<span class="sm-susp-txt">Suspended</span>'); avatarHtml = '<span class="sm-r-avatar pid-av sm-susp-av"></span>'; }
            else {
            subParts.push('<span class="sm-pid-badge"><i class="ri-fingerprint-line"></i>' + smEsc(r.dz_id || '') + '</span>');
            avatarHtml = '<img class="sm-r-avatar pid-av" src="' + smAttr(r.profile_pic || defPic) + '" onerror="this.src=\'' + defPic + '\'">';
            }
        } else if(r.type === 'user'){
            name = r.username || '';
            if(parseInt(r.is_suspended)){ subParts.push('<span class="sm-susp-txt">Suspended</span>'); avatarHtml = '<span class="sm-r-avatar sm-susp-av"></span>'; }
            else {
            subParts.push(r.nickname ? '@' + smEsc(r.nickname) : 'Dezzmer');
            avatarHtml = '<img class="sm-r-avatar" src="' + smAttr(r.profile_pic || defPic) + '" onerror="this.src=\'' + defPic + '\'">';
            }
        } else if(r.type === 'page'){
            name = smPageName(r);
            subParts.push('Page' + (r.category ? ' · ' + smEsc(r.category) : ''));
            avatarHtml = smPageAvatar('sm-r-avatar', smPagePic(r));
        } else if(r.type === 'group'){
            var gpic = r.group_pic ? (r.group_pic.indexOf('uploads/') === 0 ? r.group_pic : 'uploads/' + r.group_pic) : 'uploads/default_group.png';
            name = r.title || '';
            if(r.is_adult) nameSuffix = smNSFWbadge(true);
            if(parseInt(r.is_suspended)){ subParts.push('<span class="sm-susp-txt blue">Suspended</span>'); avatarHtml = '<span class="sm-r-avatar grp sm-susp-av"></span>'; }
            else {
            subParts.push(smEsc(String(r.member_count || 0)) + ' members');
            var st = smGroupStatusIcons(r.is_muted, r.is_notif_off || r.notif_off);
            if(st) subParts.push(st);
            avatarHtml = '<img class="sm-r-avatar grp" src="' + smAttr(gpic) + '" onerror="this.src=\'uploads/default_group.png\'">';
            }
        } else if(r.type === 'meme'){
            name = (r.caption || 'Post').length > 40 ? (r.caption || 'Post').substring(0, 40) + '…' : (r.caption || 'Post');
            subParts.push('Post · by ' + smEsc(r.username || 'unknown'));
            if(r.meme_type === 'video'){
                avatarHtml = '<div class="sm-r-video-wrap"><video src="uploads/' + smAttr(r.file_name) + '" muted playsinline preload="metadata"></video><i class="ri-play-fill sm-play-overlay"></i></div>';
            } else {
                avatarHtml = '<img class="sm-r-avatar meme-av" src="' + smAttr(r.file_name ? 'uploads/' + r.file_name : 'uploads/Text.jpg') + '" onerror="this.src=\'uploads/Text.jpg\'">';
            }
        } else if(r.type === 'collection'){
            name = r.name || 'Collection'; subParts.push('Collection · by ' + smEsc(r.owner_username || 'unknown'));
            avatarHtml = '<div class="sm-r-icon-box"><i class="ri-folder-3-line"></i></div>';
        } else if(r.type === 'sound'){
            name = r.title || 'Sound'; subParts.push('Sound · by ' + smEsc(r.username || 'unknown'));
            avatarHtml = '<div class="sm-r-icon-box"><i class="ri-music-2-line"></i></div>';
        } else if(r.type === 'sound_collection'){
            name = r.name || 'Sound Folder'; subParts.push('Sound Folder · by ' + smEsc(r.owner_username || 'unknown'));
            avatarHtml = '<div class="sm-r-icon-box"><i class="ri-folder-music-line"></i></div>';
        } else if(r.type === 'memepedia'){
            name = r.title || 'Meme Entry'; subParts.push('Memepedia · ' + smEsc(r.status || ''));
            if(r.media_url && r.media_type === 'video'){
                avatarHtml = '<div class="sm-r-video-wrap"><video src="' + smAttr(r.media_url) + '" muted playsinline preload="metadata" onerror="smVidFallback(this,\'sm-r-icon-box\',\'ri-book-open-line\')"></video><i class="ri-play-fill sm-play-overlay"></i></div>';
            } else if(r.media_url){
                avatarHtml = '<img class="sm-r-avatar meme-av" src="' + smAttr(r.media_url) + '" onerror="smImgFallback(this,\'sm-r-icon-box\',\'ri-book-open-line\')">';
            } else {
                avatarHtml = '<div class="sm-r-icon-box"><i class="ri-book-open-line"></i></div>';
            }
        } else {
            name = r.query || ''; subParts.push('Search');
            avatarHtml = '<div class="sm-r-query-icon"><i class="ri-search-line"></i></div>';
        }

        return '<div class="sm-recent-row" data-idx="' + idx + '" onclick="smGoToIdx(' + idx + ')">'
            + avatarHtml
            + '<div class="sm-r-info"><div class="sm-r-name">' + smEsc(name) + (nameSuffix ? ' ' + nameSuffix : '') + '</div>'
            + '<div class="sm-r-sub">' + subParts.join(' · ') + '</div></div>'
            + '<button type="button" class="sm-rm-btn" aria-label="Remove" data-type="' + smAttr(r.type) + '" data-id="' + smAttr(r.id) + '" onclick="event.stopPropagation();smRemoveRecentBtn(this)"><i class="ri-close-line"></i></button>'
        + '</div>';
    }).join('');
}

function smLoadSuggestions(isNewUser){
    var title = document.getElementById('smSuggestTitle'), list = document.getElementById('smSuggestionsList');
    if(!list) return;
    title.textContent = isNewUser ? 'Trending on Dezzme' : 'Suggested for you';
    list.innerHTML = '<div class="sm-loader"><i class="ri-loader-4-line sm-spin"></i></div>';

    smGetSuggestions(function(tags){
        if(!tags || !tags.length){ list.innerHTML = ''; document.getElementById('smSuggestionsWrap').style.display = 'none'; return; }
        var srcMeta = {
            personal:{ v:'sm-tag-v1', icon:'ri-user-heart-line', sub:'Based on your searches' },
            trending:{ v:'sm-tag-v2', icon:'ri-fire-line',       sub:'Trending now' },
            geo:     { v:'sm-tag-v3', icon:'ri-map-pin-2-line',  sub:'Popular in your region' },
            related: { v:'sm-tag-v4', icon:'ri-link-m',          sub:'Related to your interests' },
            global:  { v:'sm-tag-v5', icon:'ri-global-line',     sub:'Popular worldwide' },
            popular: { v:'sm-tag-v6', icon:'ri-hashtag',         sub:'Popular on Dezzme' }
        };
        var html = '';
        tags.forEach(function(t){
            var meta = srcMeta[t.source || 'popular'] || srcMeta.popular;
            html += '<button type="button" class="sm-tag-row ' + meta.v + '" data-tag="' + smAttr(t.tag) + '" onclick="smSearchTag(this.getAttribute(\'data-tag\'))">'
                + '<span class="sm-tag-icon-wrap"><i class="' + meta.icon + '"></i></span>'
                + '<span class="sm-tag-text"><span class="sm-tag-name">' + smEsc(t.tag) + '</span><span class="sm-tag-sub">' + meta.sub + '</span></span>'
                + '<i class="ri-arrow-right-s-line sm-tag-arr"></i></button>';
        });
        list.innerHTML = html;
        document.getElementById('smSuggestionsWrap').style.display = 'block';
    });
}
function smSearchTag(tag){ smRunFullSearch(tag); }

function smRenderRecents(){
    var list = document.getElementById('recentsList'), btn = document.getElementById('smClearAllBtn');
    if(!list) return;
    list.innerHTML = '<div class="sm-loader"><i class="ri-loader-4-line sm-spin"></i></div>';
    btn.style.display = 'none';
    smGetRecents(function(arr){ _smAllRecents = arr; smDrawRecents(arr); });
}

function smGoToIdx(idx){
    var r = _smRecentCache[idx];
    if(!r) return;
    smAddRecent(r);
    smNavigate(r.type, r.id, r);
}
function smNavigate(type, id, item){
    item = item || {};
    if      (type === 'profile_id')       location.href = 'profile.php?user_id=' + id;
    else if (type === 'user')             location.href = 'profile.php?user_id=' + id;
    else if (type === 'page')             location.href = 'page.php?id=' + id;
    else if (type === 'group')            location.href = 'group.php?id=' + id;
    else if (type === 'meme')             smRunFullSearch(item.caption || '');
    else if (type === 'collection')       location.href = 'profile.php?user_id=' + (item.owner_id || id) + '#memes';
    else if (type === 'sound')            location.href = 'audio.php?user_id=' + (item.user_id || id);
    else if (type === 'sound_collection') location.href = 'audio.php?user_id=' + (item.owner_id || id);
    else if (type === 'memepedia')        location.href = 'memepedia.php?open=' + id;
    else                                  smRunFullSearch(item.query || id);
}
function smGoTo(type, id, enc){
    try { var item = JSON.parse(decodeURIComponent(enc)); smAddRecent(item); smNavigate(type, id, item); }
    catch(e){ smNavigate(type, id, {}); }
}

/* ══ TRACK / JOIN TOGGLES ══ */
function smToggleTrack(btn, userId){
    btn.disabled = true;
    fetch('search.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=toggle_follow&user_id=' + encodeURIComponent(userId) })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.status === 'ok'){
            if(d.following){ btn.textContent = 'Untrack'; btn.classList.add('tracking'); }
            else { btn.textContent = 'Track'; btn.classList.remove('tracking'); }
        }
        btn.disabled = false;
    }).catch(function(){ btn.disabled = false; });
}
function smTogglePage(btn, pageId){
    btn.disabled = true;
    var fd = new FormData();
    fd.append('pp_action', 'toggle_follow');
    fd.append('page_id', pageId);
    fetch('page.php', { method:'POST', body:fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d && d.success){
            if(d.following){ btn.textContent = 'Untrack'; btn.classList.add('tracking', 'following'); }
            else { btn.textContent = 'Track'; btn.classList.remove('tracking', 'following'); }
        }
        btn.disabled = false;
    }).catch(function(){ btn.disabled = false; });
}
function smRequestTrack(btn, userId){
    btn.disabled = true;
    fetch('search.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=request_track&user_id=' + encodeURIComponent(userId) })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.status === 'ok'){
            btn.innerHTML = '<i class="ri-time-line"></i> Requested';
            btn.classList.remove('clickable'); btn.style.cursor = 'default'; btn.onclick = null;
        }
        btn.disabled = false;
    }).catch(function(){ btn.disabled = false; });
}
function smToggleGroup(btn, groupId){
    btn.disabled = true;
    fetch('search.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=toggle_group&group_id=' + encodeURIComponent(groupId) })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.status === 'ok'){
            if(d.joined){ btn.textContent = 'Leave'; btn.classList.add('joined'); }
            else { btn.textContent = 'Join'; btn.classList.remove('joined'); }
        }
        btn.disabled = false;
    }).catch(function(){ btn.disabled = false; });
}

function smDoSearch(){
    var q = document.getElementById('modalSearchInput').value.trim();
    if(q) smRunFullSearch(q);
}

document.getElementById('modalSearchInput').addEventListener('input', function(){
    var q = this.value.trim(), searchBtn = document.getElementById('smSearchBtn');
    document.getElementById('modalClearBtn').style.display = q ? 'block' : 'none';
    searchBtn.style.display = q ? 'inline-flex' : 'none';

    if(!q){
        clearTimeout(smTimer);
        document.getElementById('searchLiveResults').style.display = 'none';
        document.getElementById('searchRecents').style.display     = 'block';
        smLastQ = '';
        return;
    }
    document.getElementById('searchRecents').style.display     = 'none';
    document.getElementById('searchLiveResults').style.display = 'block';
    document.getElementById('searchLiveResults').innerHTML     = '<div class="sm-loader"><i class="ri-loader-4-line sm-spin"></i></div>';

    clearTimeout(smTimer);
    smLastQ = q;
    smTimer = setTimeout(function(){
        if(q !== smLastQ) return;
        fetch('search.php?type=autocomplete&q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(d){ smRenderResults(d, q); })
            .catch(function(){ smRenderResults([], q); });
    }, 260);
});
document.getElementById('modalSearchInput').addEventListener('keydown', function(e){
    if(e.key === 'Enter'){ var q = this.value.trim(); if(q) smRunFullSearch(q); }
});

function clearModalSearch(){
    document.getElementById('modalSearchInput').value          = '';
    document.getElementById('modalClearBtn').style.display     = 'none';
    document.getElementById('smSearchBtn').style.display       = 'none';
    document.getElementById('searchLiveResults').style.display = 'none';
    document.getElementById('searchRecents').style.display     = 'block';
    document.getElementById('modalSearchInput').focus();
    smLastQ = '';
    clearTimeout(smTimer);
}

function smUserAction(u, privateText){
    if(parseInt(u.is_blocked)) return '<span class="sm-blocked-label">Blocked</span>';
    if(parseInt(u.is_private) && !parseInt(u.is_following) && !parseInt(u.is_track_approved)){
        return '<span class="sm-private-label clickable" onclick="event.stopPropagation();smRequestTrack(this,' + u.id + ')"><i class="ri-lock-line"></i>' + privateText + '</span>';
    }
    var isTracking = parseInt(u.is_following) ? true : false;
    return '<button type="button" class="sm-track-btn ' + (isTracking ? 'tracking' : '') + '" onclick="event.stopPropagation();smToggleTrack(this,' + u.id + ')">' + (isTracking ? 'Untrack' : 'Track') + '</button>';
}

/* ══ LIVE (AUTOCOMPLETE) RESULTS ══ */
function smRenderResults(data, q){
    var box = document.getElementById('searchLiveResults'), defPic = 'uploads/mee.jpeg';

    if(!data || !data.length){
        if(/^dz-/i.test(q.trim())){
            box.innerHTML = smEs('No account found for ' + smEsc(q.toUpperCase()), 'We couldn\'t match that Profile ID to a Dezzmer.',
                [['ri-fingerprint-line','Type the full or partial ID, like DZ-10E34A or PD-1024.'],['ri-spell-check','Double-check the letters and numbers.']],
                ['Clear search','clearModalSearch()']);
        } else {
            box.innerHTML = smEs('No results for "' + smEsc(q) + '"', 'We couldn\'t find any Dezzmers, pods or posts for this. Here are a few things you can try.',
                [['ri-spell-check','Check the spelling or try fewer words.'],['ri-hashtag','Search a #hashtag to find posts on that topic.'],['ri-fingerprint-line','Type DZ- or PD- and an ID to find a Dezzmer or a Pod.']],
                ['Clear search','clearModalSearch()']);
        }
        return;
    }

    function by(t){ return data.filter(function(d){ return d.type === t; }); }
    var pidRes = by('profile_id'), users = by('user'), pages = [], groups = by('group'), memes = by('meme'),
        cols = by('collection'), sounds = by('sound'), scols = by('sound_collection'), wikis = by('memepedia');
    var html = '';

    if(pidRes.length){
        html += '<div class="sm-sec pid-sec"><i class="ri-fingerprint-line" style="margin-right:4px;"></i>Profile ID</div>';
        html += pidRes.map(function(u){
            var enc = smStash(u);
            var susp = !!parseInt(u.is_suspended);
            return '<div class="sm-res-row" onclick="smSelectResult(\'profile_id\',' + u.id + ',' + enc + ')">'
                + (susp ? '<span class="sm-res-avatar pid sm-susp-av"></span>' : '<img class="sm-res-avatar pid" src="' + smAttr(u.profile_pic || defPic) + '" onerror="this.src=\'' + defPic + '\'">')
                + '<div class="sm-res-info"><div class="sm-res-name">' + smHL(u.username, q) + (u.is_verified ? SM_VB : '') + '</div>'
                + '<div class="sm-res-sub">' + (susp ? '<span class="sm-susp-txt">Suspended</span>' : '<span class="sm-pid-pill"><i class="ri-fingerprint-line"></i>' + smHLpid(u.dz_id || '', q) + '</span>') + '</div></div>'
                + (susp ? '' : smUserAction(u, 'Private')) + '</div>';
        }).join('');
    }
    if(users.length){
        html += '<div class="sm-sec">Dezzmers</div>';
        html += users.map(function(u){
            var enc = smStash(u);
            var susp = !!parseInt(u.is_suspended);
            return '<div class="sm-res-row" onclick="smSelectResult(\'user\',' + u.id + ',' + enc + ')">'
                + (susp ? '<span class="sm-res-avatar sm-susp-av"></span>' : '<img class="sm-res-avatar" src="' + smAttr(u.profile_pic || defPic) + '" onerror="this.src=\'' + defPic + '\'">')
                + '<div class="sm-res-info"><div class="sm-res-name">' + smHL(u.username, q) + (u.is_verified ? SM_VB : '') + '</div>'
                + '<div class="sm-res-sub">' + (susp ? '<span class="sm-susp-txt">Suspended</span>' : (u.nickname ? smHL('@' + u.nickname, q) : 'Dezzmer')) + '</div></div>'
                + (susp ? '' : smUserAction(u, 'Request Track')) + '</div>';
        }).join('');
    }
    if(pages.length){
        html += '<div class="sm-sec">Pages</div>';
        html += pages.map(function(p){
            var enc = smStash(p), role = p.my_role || '';
            var sub = 'Page' + (p.category ? ' · ' + smEsc(p.category) : '') + (p.trackers != null ? ' · ' + smShortNum(p.trackers) + ' trackers' : '');
            var action;
            if(role){
                var rn = role === 'owner' ? 'Owner' : (role === 'admin' ? 'Admin' : (role === 'editor' ? 'Editor' : 'Team'));
                action = '<span class="sm-private-label"><i class="ri-shield-user-line"></i>' + rn + '</span>';
            } else {
                var isTr = parseInt(p.is_following) ? true : false;
                action = '<button type="button" class="sm-track-btn ' + (isTr ? 'tracking following' : '') + '" onclick="event.stopPropagation();smTogglePage(this,' + p.id + ')">' + (isTr ? 'Untrack' : 'Track') + '</button>';
            }
            return '<div class="sm-res-row" onclick="smSelectResult(\'page\',' + p.id + ',' + enc + ')">'
                + smPageAvatar('sm-res-avatar', smPagePic(p))
                + '<div class="sm-res-info"><div class="sm-res-name">' + smHL(smPageName(p), q) + '</div><div class="sm-res-sub">' + sub + '</div></div>'
                + action + '</div>';
        }).join('');
    }
    if(groups.length){
        html += '<div class="sm-sec">Pods</div>';
        html += groups.map(function(g){
            var pic = g.group_pic ? (g.group_pic.indexOf('uploads/') === 0 ? g.group_pic : 'uploads/' + g.group_pic) : 'uploads/default_group.png';
            var enc = smStash(g), isJoined = !!parseInt(g.is_joined);
            var nsfw = smNSFWbadge(g.is_adult), st = smGroupStatusIcons(g.is_muted, g.is_notif_off || g.notif_off);
            if(parseInt(g.is_suspended)){
                return '<div class="sm-res-row" onclick="smSelectResult(\'group\',' + g.id + ',' + enc + ')">'
                    + '<span class="sm-res-avatar grp sm-susp-av"></span>'
                    + '<div class="sm-res-info"><div class="sm-res-name">' + smHL(g.title, q) + '</div><div class="sm-res-sub"><span class="sm-susp-txt blue">Suspended</span></div></div></div>';
            }
            return '<div class="sm-res-row" onclick="smSelectResult(\'group\',' + g.id + ',' + enc + ')">'
                + '<img class="sm-res-avatar grp" src="' + smAttr(pic) + '" onerror="this.src=\'uploads/default_group.png\'">'
                + '<div class="sm-res-info"><div class="sm-res-name">' + smHL(g.title, q) + (nsfw ? ' ' + nsfw : '') + '</div>'
                + '<div class="sm-res-sub">' + (g.pd_id && /^pd/i.test(q.trim()) ? '<span class="sm-pid-pill pd"><i class="ri-fingerprint-line"></i>' + smHLpid(g.pd_id, q.replace(/^pd-?/i, 'dz-')) + '</span> · ' : '') + smEsc(String(g.member_count || 0)) + ' members' + (st ? ' ' + st : '') + '</div></div>'
                + '<button type="button" class="sm-group-btn ' + (isJoined ? 'joined' : '') + '" onclick="event.stopPropagation();smToggleGroup(this,' + g.id + ')">' + (isJoined ? 'Leave' : 'Join') + '</button></div>';
        }).join('');
    }
    if(memes.length){
        html += '<div class="sm-sec">Posts</div>';
        html += memes.map(function(m){
            var cap = (m.caption || '').length > 55 ? m.caption.substring(0, 55) + '…' : (m.caption || '');
            var enc = smStash(m), thumbHtml;
            if(m.meme_type === 'video'){
                thumbHtml = '<div class="sm-res-video-wrap"><video src="uploads/' + smAttr(m.file_name) + '" muted playsinline preload="metadata" onerror="this.style.display=\'none\'"></video><i class="ri-play-fill sm-play-overlay"></i></div>';
            } else {
                thumbHtml = '<img class="sm-res-avatar meme" src="' + smAttr(m.meme_type === 'image' ? 'uploads/' + m.file_name : 'uploads/Text.jpg') + '" onerror="this.src=\'uploads/mee.jpeg\'">';
            }
            return '<div class="sm-res-row" onclick="smSelectResult(\'meme\',' + m.id + ',' + enc + ')">' + thumbHtml
                + '<div class="sm-res-info"><div class="sm-res-name meme-caption">' + smHL(cap, q) + '</div><div class="sm-res-sub">by ' + smEsc(m.username) + '</div></div>'
                + '<i class="ri-image-line sm-res-icon"></i></div>';
        }).join('');
    }
    if(cols.length){
        html += '<div class="sm-sec">Collections</div>';
        html += cols.map(function(c){
            var enc = smStash(c), n = (c.item_count || 0) + ' item' + ((c.item_count || 0) === 1 ? '' : 's');
            return '<div class="sm-res-row" onclick="smSelectResult(\'collection\',' + c.id + ',' + enc + ')"><div class="sm-res-icon-box"><i class="ri-folder-3-line"></i></div>'
                + '<div class="sm-res-info"><div class="sm-res-name">' + smHL(c.name, q) + '</div><div class="sm-res-sub">by ' + smEsc(c.owner_username || '') + ' · ' + smEsc(n) + '</div></div>'
                + '<i class="ri-arrow-right-s-line sm-res-icon red"></i></div>';
        }).join('');
    }
    if(sounds.length){
        html += '<div class="sm-sec">Sounds</div>';
        html += sounds.map(function(s){
            var enc = smStash(s);
            return '<div class="sm-res-row" onclick="smSelectResult(\'sound\',' + s.id + ',' + enc + ')"><div class="sm-res-icon-box"><i class="ri-music-2-line"></i></div>'
                + '<div class="sm-res-info"><div class="sm-res-name">' + smHL(s.title, q) + '</div><div class="sm-res-sub">by ' + smEsc(s.username || '') + '</div></div>'
                + '<i class="ri-arrow-right-s-line sm-res-icon red"></i></div>';
        }).join('');
    }
    if(scols.length){
        html += '<div class="sm-sec">Sound Folders</div>';
        html += scols.map(function(sf){
            var enc = smStash(sf), n = (sf.item_count || 0) + ' track' + ((sf.item_count || 0) === 1 ? '' : 's');
            return '<div class="sm-res-row" onclick="smSelectResult(\'sound_collection\',' + sf.id + ',' + enc + ')"><div class="sm-res-icon-box"><i class="ri-folder-music-line"></i></div>'
                + '<div class="sm-res-info"><div class="sm-res-name">' + smHL(sf.name, q) + '</div><div class="sm-res-sub">by ' + smEsc(sf.owner_username || '') + ' · ' + smEsc(n) + '</div></div>'
                + '<i class="ri-arrow-right-s-line sm-res-icon red"></i></div>';
        }).join('');
    }
    if(wikis.length){
        html += '<div class="sm-sec">Memepedia</div>';
        html += wikis.map(function(w){
            var enc = smStash(w), sl = w.status ? w.status.charAt(0).toUpperCase() + w.status.slice(1) : '', thumbHtml;
            if(w.media_url && w.media_type === 'video'){
                thumbHtml = '<div class="sm-res-video-wrap"><video src="' + smAttr(w.media_url) + '" muted playsinline preload="metadata" onerror="smVidFallback(this,\'sm-res-icon-box\',\'ri-book-open-line\')"></video><i class="ri-play-fill sm-play-overlay"></i></div>';
            } else if(w.media_url){
                thumbHtml = '<img class="sm-res-avatar meme" src="' + smAttr(w.media_url) + '" onerror="smImgFallback(this,\'sm-res-icon-box\',\'ri-book-open-line\')">';
            } else {
                thumbHtml = '<div class="sm-res-icon-box"><i class="ri-book-open-line"></i></div>';
            }
            return '<div class="sm-res-row" onclick="smSelectResult(\'memepedia\',' + w.id + ',' + enc + ')">' + thumbHtml
                + '<div class="sm-res-info"><div class="sm-res-name">' + smHL(w.title, q) + '</div><div class="sm-res-sub">Memepedia' + (sl ? ' · ' + smEsc(sl) : '') + (w.category ? ' · ' + smEsc(w.category) : '') + '</div></div>'
                + '<i class="ri-arrow-right-s-line sm-res-icon red"></i></div>';
        }).join('');
    }

    html += '<div class="sm-res-row sm-full-search-row" onclick="smFullSearch(\'' + encodeURIComponent(q) + '\')">'
        + '<div class="sm-full-search-icon"><i class="ri-search-line"></i></div>'
        + '<div class="sm-res-info"><div class="sm-res-name sm-full-search-label">Search for "' + smEsc(q) + '"</div>'
        + '<div class="sm-res-sub">See all matching posts, Dezzmers &amp; pods</div></div>'
        + '<i class="ri-arrow-right-line sm-res-icon red"></i></div>';

    box.innerHTML = html;
}

function smFullSearch(encQ){ smRunFullSearch(decodeURIComponent(encQ)); }
var _smStashArr = [];
function smStash(o){ _smStashArr.push(o); return _smStashArr.length - 1; }
function smSelectResult(type, id, enc){
    var item = {};
    if(typeof enc === 'number'){ item = _smStashArr[enc] || {}; }
    else { try { item = JSON.parse(decodeURIComponent(enc)); } catch(e){} }
    try { smAddRecent(item); } catch(e){}
    smNavigate(type, id, item);
}

document.getElementById('modalSearchInput').addEventListener('focus', function(){ document.getElementById('modalSearchWrap').classList.add('focused'); });
document.getElementById('modalSearchInput').addEventListener('blur',  function(){ document.getElementById('modalSearchWrap').classList.remove('focused'); });

/* ══════════ FULL SEARCH — fetches this page, swaps #smResultsInner ══════════ */
function smRunFullSearch(q){
    q = (q || '').trim();
    if(!q) return;
    smAddRecent({ type:'query', id: btoa(unescape(encodeURIComponent(q))).replace(/[^a-zA-Z0-9]/g, '').slice(0, 12), query: q });
    _smCurrentQuery = q;

    openSearchModal();
    smSwitchView('results');
    smSetResultsPill(q);
    smShowResultsLoading(true);

    var newUrl = 'search.php?q=' + encodeURIComponent(q);
    if(window.location.pathname.replace(/^.*\//, 'search.php') + window.location.search !== newUrl){
        history.pushState({ smQuery:q }, '', newUrl);
    }
    var token = ++_smFetchToken;
    fetch(newUrl)
        .then(function(r){ return r.text(); })
        .then(function(html){ if(token !== _smFetchToken) return; smInjectResultsHTML(html, q); })
        .catch(function(){ if(token !== _smFetchToken) return; smShowResultsLoading(false); });
}

function smSetResultsPill(q){
    var wrap = document.querySelector('#smViewResults .srm-title-row');
    if(!wrap) return;
    var pillText = document.getElementById('srmPillText');
    if(pillText){
        pillText.textContent = q;
        pillText.setAttribute('onclick', "smReopenSearchInput(" + JSON.stringify(q).replace(/"/g, '&quot;') + ")");
        return;
    }
    wrap.innerHTML = '<div class="srm-search-pill"><span class="srm-pill-icon"><i class="ri-search-line"></i></span>'
        + '<button type="button" id="srmPillText" class="srm-search-pill-text" onclick="smReopenSearchInput(' + JSON.stringify(q).replace(/"/g,'&quot;') + ')">' + smEsc(q) + '</button>'
        + '<span class="srm-pill-divider"></span><a href="search.php" id="srmClearBtn" class="srm-clear-btn" onclick="return smClearFullSearch(event)">Clear</a></div>';
}
function smShowResultsLoading(on){
    var l = document.getElementById('smResultsLoading');
    if(l) l.classList.toggle('srm-loading-active', !!on);
}

function smInjectResultsHTML(html, q){
    var current = document.getElementById('smResultsInner');
    if(!current){ smShowResultsLoading(false); return; }

    window.srmSearchFeed = [];   /* drop the previous query's feed before the new one is pushed */

    var doc = new DOMParser().parseFromString(html, 'text/html');
    var fresh = doc.getElementById('smResultsInner');

    if(fresh){ current.innerHTML = fresh.innerHTML; }
    else {
        current.innerHTML = smEs('Something went wrong', 'We couldn\'t load your results just now.',
            [['ri-refresh-line','Give it another try in a moment.']],
            ['Search again', 'smReopenSearchInput(' + JSON.stringify(q).replace(/"/g, '&quot;') + ')']);
    }

    /* innerHTML never runs <script> — re-create each one so srmSearchFeed.push(...) executes */
    current.querySelectorAll('script').forEach(function(old){
        var s = document.createElement('script');
        if(old.src) s.src = old.src; else s.textContent = old.textContent;
        old.parentNode.replaceChild(s, old);
    });

    var content = document.querySelector('#smViewResults .srm-content');
    if(content) content.classList.remove('srm-hide-titles');
    var sc = document.getElementById('srmScroll');
    if(sc) sc.scrollTop = 0;
    var et = document.getElementById('srmEmptyTab');
    if(et) et.style.display = 'none';

    smShowResultsLoading(false);
    smQueueTabArrows();
}

function smReopenSearchInput(query){
    smSwitchView('search');
    var input = document.getElementById('modalSearchInput');
    if(!input) return;
    input.value = query || '';
    document.getElementById('searchRecents').style.display     = 'block';
    document.getElementById('searchLiveResults').style.display = 'none';
    document.getElementById('modalClearBtn').style.display     = query ? 'block' : 'none';
    document.getElementById('smSearchBtn').style.display       = query ? 'inline-flex' : 'none';
    var sc = document.getElementById('searchModalContent');
    if(sc) sc.scrollTop = 0;
    setTimeout(function(){ input.focus(); input.setSelectionRange(input.value.length, input.value.length); }, 80);
}
function smResultsBack(){
    var pill = document.getElementById('srmPillText');
    var q = pill ? pill.textContent.trim() : (_smCurrentQuery || '');
    history.pushState({}, '', 'search.php');
    smReopenSearchInput(q);
}
function smClearFullSearch(e){
    if(e) e.preventDefault();
    _smCurrentQuery = '';
    history.pushState({}, '', 'search.php');
    smReopenSearchInput('');
    return false;
}

window.addEventListener('popstate', function(){
    var q = new URLSearchParams(window.location.search).get('q');
    if(q){
        if(!smOpen) openSearchModal();
        smSwitchView('results');
        smSetResultsPill(q);
        smShowResultsLoading(true);
        var token = ++_smFetchToken;
        fetch('search.php?q=' + encodeURIComponent(q))
            .then(function(r){ return r.text(); })
            .then(function(html){ if(token === _smFetchToken) smInjectResultsHTML(html, q); })
            .catch(function(){ if(token === _smFetchToken) smShowResultsLoading(false); });
    } else if(smOpen){ smSwitchView('search'); }
});

/* ══ RESULTS VIEW — delegated events (bound once, survive every AJAX swap) ══ */
(function(){
    var root = document.getElementById('smViewResults');
    if(!root) return;

    var tabEmptyCopy = {
        dezzmers:     { title:'No Dezzmers match this search', text:"We couldn't find any Dezzmers for this. Try a nickname, username, or Profile ID instead." },
        pages:        { title:'No Pages found', text:"This search didn't match any Pages. Try the page name or its category." },
        pods:         { title:'No Pods here yet', text:"This search didn't turn up any Pods. Once one matches, it'll show up right here." },
        posts:        { title:'No Posts found', text:"Nothing matched this search in Posts. Try a shorter word or a different tag." },
        memepedia:    { title:'No Memepedia entries found', text:"This search didn't match any Memepedia articles. Maybe you're the first to write one." },
        collections:  { title:'No Collections found', text:"We couldn't find a Collection for this search." },
        sounds:       { title:'No Sounds found', text:"This search didn't match any Sounds. Try a track name or the creator's username." },
        soundfolders: { title:'No Sound Folders found', text:"We couldn't find a Sound Folder for this search. It might be sitting under Sounds instead." }
    };
    function updateEmptyTabState(filter){
        var et = document.getElementById('srmEmptyTab');
        if(!et) return;
        if(filter === 'all' || root.querySelector('.srm-section[data-section="' + filter + '"]')){ et.style.display = 'none'; return; }
        var copy = tabEmptyCopy[filter] || { title:'Nothing here yet', text:'Try another tab, or search for something else.' };
        document.getElementById('srmEmptyTabTitle').textContent = copy.title;
        document.getElementById('srmEmptyTabText').textContent  = copy.text;
        et.style.display = 'flex';
    }

    root.addEventListener('scroll', function(e){
        if(e.target && e.target.classList && e.target.classList.contains('srm-tabs')) smUpdateTabArrows();
    }, true);

    root.addEventListener('click', function(e){
        var arrow = e.target.closest('.srm-tab-arrow');
        if(arrow){
            var row = root.querySelector('.srm-tabs');
            if(row) row.scrollBy({ left: arrow.getAttribute('data-dir') === 'left' ? -220 : 220, behavior:'smooth' });
            return;
        }
        if(e.target.closest('.sm-es-backall')){
            var first = root.querySelector('.srm-tab[data-filter="all"]');
            if(first) first.click();
            return;
        }
        var tab = e.target.closest('.srm-tab');
        if(tab){
            root.querySelectorAll('.srm-tab').forEach(function(t){ t.classList.remove('active'); });
            tab.classList.add('active');
            var filter = tab.dataset.filter;
            root.querySelectorAll('.srm-section').forEach(function(sec){
                sec.classList.toggle('srm-hidden', !(filter === 'all' || sec.dataset.section === filter));
            });
            var content = root.querySelector('.srm-content');
            if(content) content.classList.toggle('srm-hide-titles', filter !== 'all');
            var sc = document.getElementById('srmScroll');
            if(sc) sc.scrollTop = 0;
            updateEmptyTabState(filter);
            return;
        }
        var followBtn = e.target.closest('.follow-btn');
        if(followBtn){
            e.stopPropagation();
            followBtn.disabled = true;
            fetch('search.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=toggle_follow&user_id=' + followBtn.dataset.user })
            .then(function(r){ return r.json(); }).then(function(d){
                followBtn.disabled = false;
                if(d.status === 'ok'){
                    followBtn.classList.toggle('following', !!d.following);
                    followBtn.classList.toggle('tracking', !!d.following);
                    followBtn.textContent = d.following ? 'Untrack' : 'Track';
                }
            }).catch(function(){ followBtn.disabled = false; });
            return;
        }
        var pageBtn = e.target.closest('.page-follow-btn');
        if(pageBtn){ e.stopPropagation(); smTogglePage(pageBtn, pageBtn.dataset.page); return; }
        var groupBtn = e.target.closest('.group-btn');
        if(groupBtn){
            e.stopPropagation();
            groupBtn.disabled = true;
            fetch('search.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=toggle_group&group_id=' + groupBtn.dataset.group })
            .then(function(r){ return r.json(); }).then(function(d){
                groupBtn.disabled = false;
                if(d.status === 'ok'){
                    groupBtn.classList.toggle('joined', !!d.joined);
                    groupBtn.textContent = d.joined ? 'Leave' : 'Join';
                }
            }).catch(function(){ groupBtn.disabled = false; });
            return;
        }
    });
})();

function srRequestTrack(el, userId){
    el.style.pointerEvents = 'none';
    fetch('search.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=request_track&user_id=' + encodeURIComponent(userId) })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.status === 'ok'){ el.innerHTML = '<i class="ri-time-line"></i> Requested'; el.classList.remove('clickable'); el.style.cursor = 'default'; }
        else el.style.pointerEvents = '';
    }).catch(function(){ el.style.pointerEvents = ''; });
}

/* ══════════ INITIAL LOAD ══════════ */
<?php if($search_query): ?>
window.addEventListener('DOMContentLoaded', function(){ openSearchModal(); smSwitchView('results'); });
<?php endif; ?>

(function(){
    var params = new URLSearchParams(window.location.search);
    if(params.get('focus') !== '1') return;
    var presetQ = params.get('mq') || '';
    setTimeout(function(){
        openSearchModal();
        smSwitchView('search');
        if(presetQ){
            setTimeout(function(){
                var input = document.getElementById('modalSearchInput');
                if(!input) return;
                input.value = presetQ;
                document.getElementById('modalClearBtn').style.display     = 'block';
                document.getElementById('smSearchBtn').style.display       = 'inline-flex';
                document.getElementById('searchRecents').style.display     = 'none';
                document.getElementById('searchLiveResults').style.display = 'block';
                document.getElementById('searchLiveResults').innerHTML = '<div class="sm-loader"><i class="ri-loader-4-line sm-spin"></i></div>';
                smLastQ = presetQ;
                fetch('search.php?type=autocomplete&q=' + encodeURIComponent(presetQ))
                    .then(function(r){ return r.json(); })
                    .then(function(d){ if(presetQ === smLastQ) smRenderResults(d, presetQ); })
                    .catch(function(){ smRenderResults([], presetQ); });
            }, 180);
        }
    }, 120);
})();


/* ══════════════════════════════════════════════════════════════
   ZEEMS GLUE — post cards in the search results open zeems_modal.php
══════════════════════════════════════════════════════════════ */
var _szSearchMode = false;

function openDiscoverModalFromSearch(index){
    if(!window.srmSearchFeed || !srmSearchFeed.length) return;
    _szSearchMode = true;
    /* swap Zeems' feed for the search-result posts (same order as the cards on screen) */
    ccmFeed = srmSearchFeed.map(function(p){ return { type:'post', data:p }; });
    _ccmVSwipeLocked = false;
    _ccmMountTrack(index >= 0 && index < ccmFeed.length ? index : 0);
    _ccmInitVerticalDrag();
    var m = document.getElementById('cardCaptionModal');
    m.classList.add('ccm-open');
    bringModalToFront(m);          /* above the search modal */
    ccmLockBodyScroll();
}

/* things Zeems opens (share sheet, saved toast) must sit above the raised Zeems modal */
(function(){
    var _share = window.openShareModal;
    if(typeof _share === 'function'){
        window.openShareModal = function(id){
            _share(id);
            bringModalToFront(document.getElementById('shareModalOverlay'));
            bringModalToFront(document.getElementById('shareModalPanel'));
        };
    }
    var _toast = window.ccmShowSavedToast;
    if(typeof _toast === 'function'){
        window.ccmShowSavedToast = function(id){
            _toast(id);
            var t = document.getElementById('ccmSavedToast');
            if(t) bringModalToFront(t);
        };
    }
})();

/* when Zeems closes: restore the normal feed and keep the search modal's scroll lock */
document.addEventListener('zeems:closed', function(){
    if(_szSearchMode){ _szSearchMode = false; _buildCcmFeed(); }
    if(smOpen) document.body.style.overflow = 'hidden';
});
</script>



<!-- ══ RIGHT SIDEBAR — Sponsored (post-ads, same source as the in-feed
     ad posts: memes.is_ad + meme_ads, NOT the old sponsorships system) ══ -->
<script>
(function(){
    var listEl = document.getElementById('rsAdsList');
    var sectionEl = listEl ? listEl.parentElement : null;
    if(!listEl) return;

    function esc(s){
        if(s===null||s===undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function cardHtml(a){
        var type = (a.type||'').toLowerCase();
        var media = (type === 'video')
            ? '<video class="rs-ad-thumb" src="'+esc(a.file)+'" muted autoplay loop playsinline disablepictureinpicture onerror="this.style.visibility=\'hidden\'"></video>'
            : '<img class="rs-ad-thumb" src="'+esc(a.file)+'" alt="" onerror="this.style.visibility=\'hidden\'">';
        var caption = a.caption ? a.caption : (a.ctaText || 'Sponsored');
        return '<a class="rs-ad-card" href="'+esc(a.ctaUrl||'#')+'" target="_blank" rel="noopener sponsored">'+
            media+
            '<div class="rs-ad-info">'+
                '<div class="rs-ad-caption">'+esc(caption)+'</div>'+
                '<div class="rs-ad-tag">Ad</div>'+
            '</div>'+
        '</a>';
    }

    fetch('?type=sidebar_ads').then(function(r){ return r.json(); }).then(function(list){
        list = Array.isArray(list) ? list.slice(0,3) : [];
        if(!list.length){ if(sectionEl) sectionEl.style.display = 'none'; return; }
        listEl.innerHTML = list.map(cardHtml).join('');
    }).catch(function(){ if(sectionEl) sectionEl.style.display = 'none'; });
})();
</script>

<!-- ══ COLLECTIONS ROW — fixed at post #14, shows 3 then "See more"
     loads the next 3 (with a brief skeleton), exactly like home.php's
     Collections row. ══ -->
<script>
(function(){
    var ALL = [];
    var visibleCount = 3;

    function esc(s){ if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function fmt(n){ n=parseInt(n)||0; if(n>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M'; if(n>=1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'')+'K'; return n; }

    function rowHtml(c){
        var thumbFile = c.thumb ? ('uploads/'+c.thumb.file) : null;
        var thumbHtml = thumbFile
            ? (String(c.thumb.type).toLowerCase()==='video'
                ? '<video class="hp-coll-thumb" src="'+esc(thumbFile)+'" muted playsinline preload="metadata"></video>'
                : '<img class="hp-coll-thumb" src="'+esc(thumbFile)+'" onerror="this.outerHTML=\'<div class=&quot;hp-coll-thumb hp-coll-thumb-empty&quot;><i class=&quot;ri-folder-3-line&quot;></i></div>\'">')
            : '<div class="hp-coll-thumb hp-coll-thumb-empty"><i class="ri-folder-3-line"></i></div>';
        return '<div class="hp-coll-row" onclick="location.href=\'profile.php?user_id='+c.ownerId+'&open_col='+c.id+'\'">'+thumbHtml+
            '<div class="hp-coll-info"><div class="hp-coll-name">'+esc(c.name)+'</div>'+
            '<div class="hp-coll-meta"><span class="hp-coll-owner">by '+esc(c.ownerUsername)+'</span><span>·</span><span><span class="hp-coll-count">'+fmt(c.itemCount)+'</span> items</span>'+
            '<span class="hp-coll-vis">'+(c.isPublic?'Public':'Private')+'</span></div></div>'+
            '<i class="ri-arrow-right-s-line hp-coll-chevron"></i></div>';
    }

    function skelRowHtml(){
        return '<div class="hp-coll-skel-row">'+
            '<div class="hp-coll-skel-thumb"></div>'+
            '<div class="hp-coll-skel-info">'+
                '<div class="hp-coll-skel-line w1"></div>'+
                '<div class="hp-coll-skel-line w2"></div>'+
            '</div>'+
        '</div>';
    }

    function footerHtml(){
        var hasMore = visibleCount < ALL.length;
        var canLess = visibleCount > 3;
        if(!hasMore && !canLess) return '';
        var html = '<div class="hp-coll-more-row">';
        if(hasMore) html += '<button type="button" class="hp-coll-more-btn" onclick="spCollShowMore()"><i class="ri-arrow-down-s-line"></i> See more</button>';
        if(canLess) html += '<button type="button" class="hp-coll-less-btn" onclick="spCollShowLess()"><i class="ri-arrow-up-s-line"></i> See less</button>';
        html += '</div>';
        return html;
    }

    function render(){
        var block = document.getElementById('spCollBlock');
        if(!block) return;
        var visible = ALL.slice(0, visibleCount);
        block.innerHTML = '<div class="hp-coll-head"><div class="hp-coll-kicker">Collections</div><div class="hp-coll-title">Discover Collections</div></div>'+
            visible.map(rowHtml).join('')+
            footerHtml();
    }

    function renderWithSkeleton(newCount){
        var block = document.getElementById('spCollBlock');
        if(!block) return;
        var currentlyShown = ALL.slice(0, visibleCount - newCount);
        var skelHtml = '';
        for(var i=0;i<newCount;i++) skelHtml += skelRowHtml();
        block.innerHTML = '<div class="hp-coll-head"><div class="hp-coll-kicker">Collections</div><div class="hp-coll-title">Discover Collections</div></div>'+
            currentlyShown.map(rowHtml).join('')+
            skelHtml;
        setTimeout(render, 380);
    }

    window.spCollShowMore = function(){
        var nextCount = Math.min(ALL.length, visibleCount + 3);
        var added = nextCount - visibleCount;
        if(added <= 0){ visibleCount = nextCount; render(); return; }
        visibleCount = nextCount;
        renderWithSkeleton(added);
    };
    window.spCollShowLess = function(){ visibleCount = 3; render(); };

    function mount(){
        var block = document.getElementById('spCollBlock');
        if(!block) return;
        fetch('?type=collections').then(function(r){ return r.json(); }).then(function(list){
            ALL = Array.isArray(list) ? list : [];
            if(!ALL.length){ block.remove(); return; }
            visibleCount = Math.min(3, ALL.length);
            render();
        }).catch(function(){ block.remove(); });
    }
    if(document.readyState !== 'loading') mount();
    else document.addEventListener('DOMContentLoaded', mount);
})();
</script>