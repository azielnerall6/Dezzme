<?php
ob_start();
session_start();
include 'config.php';

/* If the web server dropped the query string, rebuild $_GET from the original URL,
   or from the X-Fam-Qs header that the page's own JavaScript sends with every API call. */
if (empty($_GET['action'])) {
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($qs === '') $qs = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    if ($qs === '') $qs = (string)($_SERVER['HTTP_X_FAM_QS'] ?? '');
    if ($qs !== '') { parse_str($qs, $fam_qs); if (is_array($fam_qs)) $_GET = array_merge($fam_qs, $_GET); }
}
/* the helpers below check for false; do not let a bad query throw a fatal error */
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);

/* ═══════════════════════════════════════════════════════════════
   FAMILY CENTER  ·  family.php
   Consent-first parental supervision for Dezzme.
   - Either a parent/guardian OR a teen can start an invitation.
   - Nothing is active until the other person accepts.
   - Either side can end supervision at any time (the other is told).
   - Parents see activity SUMMARIES only. Never messages, searches or
     private content.
   Tables used (auto-created below):  family_links, family_settings,
   family_activity_log.  Existing tables read: users, user_about,
   followers, memes, likes, groups, group_members, blocks, notifications.
   ═══════════════════════════════════════════════════════════════ */

$me     = (int)($_SESSION['user_id'] ?? 0);
$is_api = isset($_GET['action']);

function fam_json($data, $code = 200){
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!$me) {
    if ($is_api) fam_json(['error' => 'Your session expired. Please log in again.'], 401);
    header("Location: login.php"); exit();
}
if (empty($_SESSION['fam_csrf'])) $_SESSION['fam_csrf'] = bin2hex(random_bytes(24));
$fam_csrf = $_SESSION['fam_csrf'];
if (method_exists($conn, 'set_charset')) $conn->set_charset('utf8mb4');

/* ── tiny prepared-statement helpers ─────────────────────────── */
function fam_has_col(mysqli $c, string $t, string $col): bool {
    static $cache = [];
    $k = $t . '.' . $col;
    if (isset($cache[$k])) return $cache[$k];
    $r = @$c->query("SHOW COLUMNS FROM `" . $c->real_escape_string($t) . "` LIKE '" . $c->real_escape_string($col) . "'");
    return $cache[$k] = ($r && $r->num_rows > 0);
}
function fam_rows(mysqli $c, string $sql, string $types = '', array $p = []): array {
    $st = $c->prepare($sql);
    if (!$st) { $GLOBALS['fam_err'] = $c->error; return []; }
    if ($types !== '') $st->bind_param($types, ...$p);
    if (!$st->execute()) { $st->close(); return []; }
    $res  = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $rows;
}
function fam_one(mysqli $c, string $sql, string $types = '', array $p = []) {
    $r = fam_rows($c, $sql, $types, $p);
    return $r[0] ?? null;
}
function fam_exec(mysqli $c, string $sql, string $types = '', array $p = []) {
    $st = $c->prepare($sql);
    if (!$st) return false;
    if ($types !== '') $st->bind_param($types, ...$p);
    $ok = $st->execute();
    $out = $ok ? ['id' => (int)$st->insert_id, 'n' => (int)$st->affected_rows] : false;
    $st->close();
    return $out;
}

/* ── schema (created once per session) ───────────────────────── */
function fam_install(mysqli $c){
    $c->query("CREATE TABLE IF NOT EXISTS `family_links` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `parent_id` int(10) UNSIGNED NOT NULL,
      `teen_id` int(10) UNSIGNED NOT NULL,
      `status` enum('pending','active','declined','ended','cancelled') NOT NULL DEFAULT 'pending',
      `initiated_by` enum('parent','teen') NOT NULL,
      `relationship` varchar(40) NOT NULL DEFAULT '',
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `responded_at` datetime DEFAULT NULL,
      `ended_at` datetime DEFAULT NULL,
      `ended_by` int(10) UNSIGNED DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_pair` (`parent_id`,`teen_id`),
      KEY `idx_teen` (`teen_id`,`status`),
      KEY `idx_parent` (`parent_id`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $c->query("CREATE TABLE IF NOT EXISTS `family_settings` (
      `link_id` int(10) UNSIGNED NOT NULL,
      `daily_limit_min` smallint(5) UNSIGNED NOT NULL DEFAULT 120,
      `quiet_enabled` tinyint(1) NOT NULL DEFAULT 1,
      `quiet_start` time NOT NULL DEFAULT '22:00:00',
      `quiet_end` time NOT NULL DEFAULT '07:00:00',
      `msg_scope` enum('everyone','trackers','nobody') NOT NULL DEFAULT 'trackers',
      `lock_private` tinyint(1) NOT NULL DEFAULT 1,
      `hide_adult` tinyint(1) NOT NULL DEFAULT 1,
      `pod_approval` tinyint(1) NOT NULL DEFAULT 1,
      `search_visible` tinyint(1) NOT NULL DEFAULT 1,
      `weekly_summary` tinyint(1) NOT NULL DEFAULT 1,
      `customized` tinyint(1) NOT NULL DEFAULT 0,
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`link_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $c->query("CREATE TABLE IF NOT EXISTS `family_activity_log` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `link_id` int(10) UNSIGNED NOT NULL,
      `actor_id` int(10) UNSIGNED NOT NULL,
      `action` varchar(40) NOT NULL,
      `detail` varchar(255) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_link` (`link_id`,`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
if (empty($_SESSION['fam_tables_ok'])) { fam_install($conn); $_SESSION['fam_tables_ok'] = 1; }

/* ── controls ────────────────────────────────────────────────── */
function fam_defaults(): array {
    return [
        'daily_limit_min' => 120, 'quiet_enabled' => 1, 'quiet_start' => '22:00', 'quiet_end' => '07:00',
        'msg_scope' => 'trackers', 'lock_private' => 1, 'hide_adult' => 1, 'pod_approval' => 1,
        'search_visible' => 1, 'weekly_summary' => 1,
    ];
}
function fam_clean_controls($in, ?array $base = null): array {
    $out = $base ?: fam_defaults();
    if (!is_array($in)) return $out;
    if (isset($in['daily_limit_min'])) {
        $v = (int)$in['daily_limit_min'];
        if (in_array($v, [0,15,30,45,60,90,120,180,240], true)) $out['daily_limit_min'] = $v;
    }
    foreach (['quiet_enabled','lock_private','hide_adult','pod_approval','search_visible','weekly_summary'] as $k) {
        if (array_key_exists($k, $in)) $out[$k] = empty($in[$k]) ? 0 : 1;
    }
    foreach (['quiet_start','quiet_end'] as $k) {
        if (isset($in[$k]) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$in[$k])) $out[$k] = (string)$in[$k];
    }
    if (isset($in['msg_scope']) && in_array($in['msg_scope'], ['everyone','trackers','nobody'], true)) $out['msg_scope'] = $in['msg_scope'];
    return $out;
}
function fam_ctl_from_row(array $r): array {
    if (!isset($r['msg_scope']) || $r['msg_scope'] === null) return fam_defaults();
    return [
        'daily_limit_min' => (int)$r['daily_limit_min'], 'quiet_enabled' => (int)$r['quiet_enabled'],
        'quiet_start' => substr((string)$r['quiet_start'], 0, 5), 'quiet_end' => substr((string)$r['quiet_end'], 0, 5),
        'msg_scope' => $r['msg_scope'], 'lock_private' => (int)$r['lock_private'], 'hide_adult' => (int)$r['hide_adult'],
        'pod_approval' => (int)$r['pod_approval'], 'search_visible' => (int)$r['search_visible'], 'weekly_summary' => (int)$r['weekly_summary'],
    ];
}
function fam_controls_on(array $c): int {
    $n = 0;
    if ((int)$c['daily_limit_min'] > 0) $n++;
    if ((int)$c['quiet_enabled'])       $n++;
    if ($c['msg_scope'] !== 'everyone') $n++;
    if ((int)$c['lock_private'])        $n++;
    if ((int)$c['hide_adult'])          $n++;
    if ((int)$c['pod_approval'])        $n++;
    if (!(int)$c['search_visible'])     $n++;
    return $n;
}
function fam_save_settings(mysqli $c, int $linkId, array $ctl, int $customized = 0){
    return fam_exec($c,
        "INSERT INTO family_settings (link_id,daily_limit_min,quiet_enabled,quiet_start,quiet_end,msg_scope,lock_private,hide_adult,pod_approval,search_visible,weekly_summary,customized)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE daily_limit_min=VALUES(daily_limit_min), quiet_enabled=VALUES(quiet_enabled), quiet_start=VALUES(quiet_start),
           quiet_end=VALUES(quiet_end), msg_scope=VALUES(msg_scope), lock_private=VALUES(lock_private), hide_adult=VALUES(hide_adult),
           pod_approval=VALUES(pod_approval), search_visible=VALUES(search_visible), weekly_summary=VALUES(weekly_summary),
           customized=GREATEST(customized,VALUES(customized))",
        'iiisssiiiiii',
        [$linkId, (int)$ctl['daily_limit_min'], (int)$ctl['quiet_enabled'], $ctl['quiet_start'], $ctl['quiet_end'], $ctl['msg_scope'],
         (int)$ctl['lock_private'], (int)$ctl['hide_adult'], (int)$ctl['pod_approval'], (int)$ctl['search_visible'], (int)$ctl['weekly_summary'], $customized]);
}

/* ── users, ages, logs, notifications ────────────────────────── */
function fam_user(mysqli $c, int $id){
    $page  = fam_has_col($c, 'users', 'is_page') ? 'COALESCE(u.is_page,0) AS is_page' : '0 AS is_page';
    $act   = fam_has_col($c, 'users', 'last_active') ? 'u.last_active' : 'NULL AS last_active';
    $dobOk = fam_has_col($c, 'user_about', 'date_of_birth');
    $dob   = $dobOk ? 'a.date_of_birth' : 'NULL AS date_of_birth';
    $join  = $dobOk ? 'LEFT JOIN user_about a ON a.user_id=u.id' : '';
    return fam_one($c, "SELECT u.id,u.username,u.nickname,u.profile_pic,u.dz_id,$page,$act,$dob FROM users u $join WHERE u.id=? LIMIT 1", 'i', [$id]);
}
function fam_pub(array $u): array {
    return ['id' => (int)$u['id'], 'username' => $u['username'], 'nickname' => (string)($u['nickname'] ?? ''),
            'pic' => $u['profile_pic'] ?: 'uploads/me.png', 'dz' => (string)($u['dz_id'] ?? '')];
}
function fam_age($dob){
    if (!$dob || $dob === '0000-00-00') return null;
    try { return (int)(new DateTime($dob))->diff(new DateTime('now'))->y; } catch (Exception $e) { return null; }
}
function fam_notify(mysqli $c, int $to, int $actor, string $type, int $ref, string $msg){
    fam_exec($c, "INSERT INTO notifications (user_id,actor_id,type,ref_id,message) VALUES (?,?,?,?,?)", 'iisis', [$to, $actor, $type, $ref, $msg]);
}
function fam_log(mysqli $c, int $link, int $actor, string $action, string $detail = ''){
    fam_exec($c, "INSERT INTO family_activity_log (link_id,actor_id,action,detail) VALUES (?,?,?,?)", 'iiss', [$link, $actor, $action, mb_substr($detail, 0, 250)]);
}
function fam_link(mysqli $c, int $id){
    return fam_one($c, "SELECT * FROM family_links WHERE id=? LIMIT 1", 'i', [$id]);
}
function fam_blocked(mysqli $c, int $a, int $b): bool {
    if (!fam_has_col($c, 'blocks', 'blocker_id') || !fam_has_col($c, 'blocks', 'blocked_id')) return false;
    return (bool)fam_one($c, "SELECT 1 AS x FROM blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1", 'iiii', [$a, $b, $b, $a]);
}
/* returns an error string, or null when the pair is allowed */
function fam_check_pair(mysqli $c, int $parentId, int $teenId): ?string {
    if ($parentId === $teenId) return "You can't link an account to itself.";
    $p = fam_user($c, $parentId); $t = fam_user($c, $teenId);
    if (!$p || !$t || (int)$p['is_page'] || (int)$t['is_page']) return "That account can't be used with Family Center.";
    $pa = fam_age($p['date_of_birth']); $ta = fam_age($t['date_of_birth']);
    if ($pa !== null && $pa < 18)  return "A supervising account must belong to someone aged 18 or older.";
    if ($ta !== null && $ta >= 18) return "Supervision is only available for accounts under 18.";
    if (fam_blocked($c, $parentId, $teenId)) return "You can't link with this account.";
    return null;
}

$REL_OPTS = ['Mother', 'Father', 'Legal guardian', 'Grandparent', 'Other family member'];

/* ═══════════════════════════ API ═══════════════════════════ */
if ($is_api) {
    $action = (string)$_GET['action'];
    $in = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($fam_csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) fam_json(['error' => 'Security check failed. Refresh the page and try again.'], 403);
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) $in = [];
    }

    switch ($action) {

    /* ── everything the page needs in one call ── */
    case 'state': {
        $rows = fam_rows($conn,
            "SELECT l.id,l.parent_id,l.teen_id,l.status,l.initiated_by,l.relationship,l.created_at,l.responded_at,
                    s.link_id AS s_id,s.daily_limit_min,s.quiet_enabled,s.quiet_start,s.quiet_end,s.msg_scope,s.lock_private,s.hide_adult,
                    s.pod_approval,s.search_visible,s.weekly_summary,s.customized,
                    o.id AS o_id,o.username AS o_name,o.nickname AS o_nick,o.profile_pic AS o_pic,o.dz_id AS o_dz,o.last_active AS o_active
             FROM family_links l
             LEFT JOIN family_settings s ON s.link_id=l.id
             JOIN users o ON o.id = IF(l.parent_id=?, l.teen_id, l.parent_id)
             WHERE (l.parent_id=? OR l.teen_id=?) AND l.status IN ('pending','active')
             ORDER BY (l.status='pending') DESC, l.created_at DESC", 'iii', [$me, $me, $me]);

        $links = []; $sup = 0; $by = 0; $pin = 0; $pout = 0; $prot = 0;
        foreach ($rows as $r) {
            $role      = ((int)$r['parent_id'] === $me) ? 'parent' : 'teen';
            $pending   = $r['status'] === 'pending';
            $recipient = $r['initiated_by'] === 'parent' ? 'teen' : 'parent';
            $direction = $pending ? ($recipient === $role ? 'incoming' : 'outgoing') : null;
            $ctl       = fam_ctl_from_row($r);
            $on        = fam_controls_on($ctl);
            if ($r['status'] === 'active') { if ($role === 'parent') { $sup++; $prot += $on; } else { $by++; } }
            if ($direction === 'incoming') $pin++;
            if ($direction === 'outgoing') $pout++;
            $links[] = [
                'id' => (int)$r['id'], 'status' => $r['status'], 'role' => $role, 'direction' => $direction,
                'initiated_by' => $r['initiated_by'], 'relationship' => $r['relationship'],
                'created_at' => $r['created_at'], 'responded_at' => $r['responded_at'],
                'other' => ['id' => (int)$r['o_id'], 'username' => $r['o_name'], 'nickname' => (string)($r['o_nick'] ?? ''),
                            'pic' => $r['o_pic'] ?: 'uploads/me.png', 'dz' => (string)($r['o_dz'] ?? '')],
                'controls' => $ctl, 'controls_on' => $on, 'customized' => (int)($r['customized'] ?? 0),
                'last_active' => ($role === 'parent' && $r['status'] === 'active') ? $r['o_active'] : null,
            ];
        }
        $meU = fam_user($conn, $me); $myAge = fam_age($meU['date_of_birth'] ?? null);
        fam_json([
            'links' => $links,
            'stats' => ['supervising' => $sup, 'supervisors' => $by, 'incoming' => $pin, 'outgoing' => $pout, 'protections' => $prot],
            'me'    => ['age_known' => $myAge !== null, 'can_parent' => ($myAge === null || $myAge >= 18), 'can_teen' => ($myAge === null || $myAge < 18)],
            'rels'  => $REL_OPTS,
        ]);
    }

    /* ── find a person to invite ── */
    case 'search_users': {
        $q = trim((string)($_GET['q'] ?? '')); $role = ($_GET['role'] ?? '') === 'teen' ? 'teen' : 'parent';
        if (mb_strlen($q) < 2) fam_json(['results' => []]);
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $GLOBALS['fam_err'] = '';
        $pageCond = fam_has_col($conn, 'users', 'is_page') ? 'COALESCE(u.is_page,0)=0' : '1=1';
        $dobOk    = fam_has_col($conn, 'user_about', 'date_of_birth');
        $dobSel   = $dobOk ? 'a.date_of_birth' : 'NULL AS date_of_birth';
        $dobJoin  = $dobOk ? 'LEFT JOIN user_about a ON a.user_id=u.id' : '';
        $types    = 'i'; $params = [$me];
        $where = [];
        foreach (['username', 'dz_id', 'nickname'] as $cn) if (fam_has_col($conn, 'users', $cn)) $where[] = "u.$cn LIKE ?";
        if (!$where) $where = ['u.username LIKE ?'];
        $types .= str_repeat('s', count($where));
        foreach ($where as $_) $params[] = $like;
        $blk = '';
        if (fam_has_col($conn, 'blocks', 'blocker_id') && fam_has_col($conn, 'blocks', 'blocked_id')) {
            $blk = " AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id=? AND b.blocked_id=u.id) OR (b.blocker_id=u.id AND b.blocked_id=?))";
            $types .= 'ii'; $params[] = $me; $params[] = $me;
        }
        $types .= 's'; $params[] = $q;
        $rows = fam_rows($conn,
            "SELECT u.id,u.username,u.nickname,u.profile_pic,u.dz_id,$dobSel
             FROM users u $dobJoin
             WHERE $pageCond AND u.id<>? AND (" . implode(' OR ', $where) . ")$blk
             ORDER BY (u.username=?) DESC, u.username ASC LIMIT 12", $types, $params);
        $searchErr = (string)($GLOBALS['fam_err'] ?? '');
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $existing = [];
        if ($ids) {
            $in_list = implode(',', array_map('intval', $ids));
            foreach (fam_rows($conn, "SELECT parent_id,teen_id,status FROM family_links WHERE (parent_id=? AND teen_id IN ($in_list)) OR (teen_id=? AND parent_id IN ($in_list))", 'ii', [$me, $me]) as $e) {
                $other = ((int)$e['parent_id'] === $me) ? (int)$e['teen_id'] : (int)$e['parent_id'];
                $existing[$other] = $e['status'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $age = fam_age($r['date_of_birth']); $ok = true; $why = '';
            if ($role === 'parent' && $age !== null && $age >= 18) { $ok = false; $why = 'Account is 18 or older'; }
            if ($role === 'teen'   && $age !== null && $age < 18)  { $ok = false; $why = 'Account is under 18'; }
            $st = $existing[(int)$r['id']] ?? null;
            if ($st === 'active')  { $ok = false; $why = 'Already linked'; }
            if ($st === 'pending') { $ok = false; $why = 'Invitation pending'; }
            $u = fam_pub($r); $u['ok'] = $ok; $u['why'] = $why;
            $out[] = $u;
            if (count($out) >= 8) break;
        }
        fam_json(['results' => $out, 'dbg' => $searchErr]);
    }

    /* ── send an invitation (parent → teen  OR  teen → parent) ── */
    case 'invite': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fam_json(['error' => 'Invalid request.'], 405);
        $role = ($in['role'] ?? '') === 'teen' ? 'teen' : (($in['role'] ?? '') === 'parent' ? 'parent' : '');
        $other = (int)($in['other_id'] ?? 0); $rel = trim((string)($in['relationship'] ?? ''));
        if (!$role || $other <= 0) fam_json(['error' => 'Choose who you want to invite.'], 422);
        if (!in_array($rel, $REL_OPTS, true)) fam_json(['error' => 'Choose how you are related.'], 422);
        $meU = fam_user($conn, $me); $myAge = fam_age($meU['date_of_birth'] ?? null);
        if ($myAge === null && empty($in['age_confirmed'])) fam_json(['error' => 'Please confirm your age to continue.'], 422);
        if ($role === 'parent' && $myAge !== null && $myAge < 18)  fam_json(['error' => 'Only accounts aged 18 or older can supervise.'], 422);
        if ($role === 'teen'   && $myAge !== null && $myAge >= 18) fam_json(['error' => 'Only accounts under 18 can be supervised.'], 422);

        $parentId = $role === 'parent' ? $me : $other;
        $teenId   = $role === 'parent' ? $other : $me;
        if ($err = fam_check_pair($conn, $parentId, $teenId)) fam_json(['error' => $err], 422);

        $cnt = fam_one($conn, "SELECT COUNT(*) n FROM family_links WHERE teen_id=? AND status IN ('pending','active') AND parent_id<>?", 'ii', [$teenId, $parentId]);
        if ((int)($cnt['n'] ?? 0) >= 2) fam_json(['error' => 'A teen account can have at most 2 supervisors.'], 422);
        $out = fam_one($conn, "SELECT COUNT(*) n FROM family_links WHERE status='pending' AND initiated_by=? AND " . ($role === 'parent' ? 'parent_id' : 'teen_id') . "=?", 'si', [$role, $me]);
        if ((int)($out['n'] ?? 0) >= 10) fam_json(['error' => 'You have too many pending invitations. Cancel some first.'], 422);

        $ex = fam_one($conn, "SELECT * FROM family_links WHERE parent_id=? AND teen_id=? LIMIT 1", 'ii', [$parentId, $teenId]);
        if ($ex) {
            if ($ex['status'] === 'active')  fam_json(['error' => 'You are already linked with this account.'], 422);
            if ($ex['status'] === 'pending') fam_json(['error' => 'An invitation is already waiting for a response.'], 422);
            if ($ex['status'] === 'declined' && $ex['initiated_by'] === $role && $ex['responded_at'] && strtotime($ex['responded_at']) > time() - 7 * 86400)
                fam_json(['error' => 'This invitation was declined recently. You can try again after 7 days.'], 422);
            fam_exec($conn, "UPDATE family_links SET status='pending', initiated_by=?, relationship=?, created_at=NOW(), responded_at=NULL, ended_at=NULL, ended_by=NULL WHERE id=?", 'ssi', [$role, $rel, (int)$ex['id']]);
            $linkId = (int)$ex['id'];
        } else {
            $ins = fam_exec($conn, "INSERT INTO family_links (parent_id,teen_id,status,initiated_by,relationship) VALUES (?,?, 'pending', ?, ?)", 'iiss', [$parentId, $teenId, $role, $rel]);
            if (!$ins) fam_json(['error' => 'Could not send the invitation. Please try again.'], 500);
            $linkId = $ins['id'];
        }
        fam_save_settings($conn, $linkId, fam_clean_controls($in['controls'] ?? null), 0);
        fam_log($conn, $linkId, $me, 'invited', $role === 'parent' ? 'Parent invited teen' : 'Teen invited parent');
        $msg = $role === 'parent'
            ? ($meU['username'] . ' invited you to Family Center supervision. Review and accept or decline.')
            : ($meU['username'] . ' asked you to supervise their account in Family Center.');
        fam_notify($conn, $other, $me, 'family_invite', $linkId, $msg);
        fam_json(['ok' => true, 'id' => $linkId]);
    }

    /* ── accept / decline (recipient only) ── */
    case 'respond': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fam_json(['error' => 'Invalid request.'], 405);
        $l = fam_link($conn, (int)($in['link_id'] ?? 0)); $accept = !empty($in['accept']);
        if (!$l || $l['status'] !== 'pending') fam_json(['error' => 'This invitation is no longer available.'], 404);
        $recipient = $l['initiated_by'] === 'parent' ? (int)$l['teen_id'] : (int)$l['parent_id'];
        $initiator = $l['initiated_by'] === 'parent' ? (int)$l['parent_id'] : (int)$l['teen_id'];
        if ($recipient !== $me) fam_json(['error' => 'Only the invited person can respond.'], 403);
        $meU = fam_user($conn, $me);
        if ($accept) {
            if ($err = fam_check_pair($conn, (int)$l['parent_id'], (int)$l['teen_id'])) fam_json(['error' => $err], 422);
            $cnt = fam_one($conn, "SELECT COUNT(*) n FROM family_links WHERE teen_id=? AND status='active' AND id<>?", 'ii', [(int)$l['teen_id'], (int)$l['id']]);
            if ((int)($cnt['n'] ?? 0) >= 2) fam_json(['error' => 'This teen account already has 2 supervisors.'], 422);
            fam_exec($conn, "UPDATE family_links SET status='active', responded_at=NOW() WHERE id=?", 'i', [(int)$l['id']]);
            fam_log($conn, (int)$l['id'], $me, 'accepted', 'Supervision started');
            fam_notify($conn, $initiator, $me, 'family_accepted', (int)$l['id'], $meU['username'] . ' accepted your Family Center invitation.');
        } else {
            fam_exec($conn, "UPDATE family_links SET status='declined', responded_at=NOW() WHERE id=?", 'i', [(int)$l['id']]);
            fam_log($conn, (int)$l['id'], $me, 'declined', 'Invitation declined');
            fam_notify($conn, $initiator, $me, 'family_declined', (int)$l['id'], $meU['username'] . ' declined your Family Center invitation.');
        }
        fam_json(['ok' => true]);
    }

    /* ── cancel my own pending invitation ── */
    case 'cancel': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fam_json(['error' => 'Invalid request.'], 405);
        $l = fam_link($conn, (int)($in['link_id'] ?? 0));
        if (!$l || $l['status'] !== 'pending') fam_json(['error' => 'This invitation is no longer pending.'], 404);
        $initiator = $l['initiated_by'] === 'parent' ? (int)$l['parent_id'] : (int)$l['teen_id'];
        if ($initiator !== $me) fam_json(['error' => 'Only the sender can cancel an invitation.'], 403);
        fam_exec($conn, "UPDATE family_links SET status='cancelled', responded_at=NOW() WHERE id=?", 'i', [(int)$l['id']]);
        fam_log($conn, (int)$l['id'], $me, 'cancelled', 'Invitation cancelled');
        fam_json(['ok' => true]);
    }

    /* ── end supervision (either side, any time) ── */
    case 'end': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fam_json(['error' => 'Invalid request.'], 405);
        $l = fam_link($conn, (int)($in['link_id'] ?? 0));
        if (!$l || $l['status'] !== 'active') fam_json(['error' => 'This supervision is not active.'], 404);
        if ((int)$l['parent_id'] !== $me && (int)$l['teen_id'] !== $me) fam_json(['error' => 'Not allowed.'], 403);
        $other = (int)$l['parent_id'] === $me ? (int)$l['teen_id'] : (int)$l['parent_id'];
        $meU = fam_user($conn, $me);
        fam_exec($conn, "UPDATE family_links SET status='ended', ended_at=NOW(), ended_by=? WHERE id=?", 'ii', [$me, (int)$l['id']]);
        fam_log($conn, (int)$l['id'], $me, 'ended', 'Supervision ended');
        fam_notify($conn, $other, $me, 'family_ended', (int)$l['id'], $meU['username'] . ' ended Family Center supervision.');
        fam_json(['ok' => true]);
    }

    /* ── parent edits controls ── */
    case 'save_controls': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fam_json(['error' => 'Invalid request.'], 405);
        $l = fam_link($conn, (int)($in['link_id'] ?? 0));
        if (!$l || $l['status'] !== 'active' || (int)$l['parent_id'] !== $me) fam_json(['error' => 'Only an active supervisor can change controls.'], 403);
        $cur = fam_ctl_from_row(fam_one($conn, "SELECT * FROM family_settings WHERE link_id=?", 'i', [(int)$l['id']]) ?: []);
        $new = fam_clean_controls($in['controls'] ?? null, $cur);
        $labels = ['daily_limit_min' => 'Daily limit', 'quiet_enabled' => 'Quiet hours', 'quiet_start' => 'Quiet hours', 'quiet_end' => 'Quiet hours',
                   'msg_scope' => 'Messaging', 'lock_private' => 'Private account', 'hide_adult' => '18+ content', 'pod_approval' => 'Pod approval',
                   'search_visible' => 'Search visibility', 'weekly_summary' => 'Weekly summary'];
        $changed = [];
        foreach ($new as $k => $v) if ((string)$v !== (string)$cur[$k]) $changed[$labels[$k]] = true;
        if (!$changed) fam_json(['ok' => true, 'changed' => 0, 'controls' => $new, 'controls_on' => fam_controls_on($new)]);
        fam_save_settings($conn, (int)$l['id'], $new, 1);
        fam_log($conn, (int)$l['id'], $me, 'controls', 'Updated: ' . implode(', ', array_keys($changed)));
        $meU = fam_user($conn, $me);
        fam_notify($conn, (int)$l['teen_id'], $me, 'family_update', (int)$l['id'], $meU['username'] . ' updated your Family Center controls (' . implode(', ', array_keys($changed)) . ').');
        fam_json(['ok' => true, 'changed' => count($changed), 'controls' => $new, 'controls_on' => fam_controls_on($new)]);
    }

    /* ── activity summary for a supervised teen (counts only) ── */
    case 'insights': {
        $l = fam_link($conn, (int)($_GET['link_id'] ?? 0));
        if (!$l || $l['status'] !== 'active' || (int)$l['parent_id'] !== $me) fam_json(['error' => 'Insights are only available to an active supervisor.'], 403);
        $t = (int)$l['teen_id'];
        $cnt = fn($sql) => (int)(fam_one($conn, $sql, 'i', [$t])['n'] ?? 0);
        $fix = function($rows){ foreach ($rows as &$r) { $r['profile_pic'] = $r['profile_pic'] ?: 'uploads/me.png'; } return $rows; };
        $pods = fam_rows($conn, "SELECT g.id,g.title,g.group_pic,g.is_adult,g.privacy FROM group_members gm JOIN `groups` g ON g.id=gm.group_id WHERE gm.user_id=? ORDER BY gm.joined_at DESC LIMIT 8", 'i', [$t]);
        $adult = 0; foreach ($pods as $p) if ((int)$p['is_adult']) $adult++;
        $daily = [];
        foreach (fam_rows($conn, "SELECT DATE(created_at) d, COUNT(*) c FROM memes WHERE uploaded_by=? AND created_at>=DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)", 'i', [$t]) as $r) $daily[$r['d']] = (int)$r['c'];
        $series = [];
        for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $series[] = ['d' => $d, 'c' => $daily[$d] ?? 0]; }
        $tu = fam_user($conn, $t);
        fam_json([
            'posts7' => $cnt("SELECT COUNT(*) n FROM memes WHERE uploaded_by=? AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'posts_all' => $cnt("SELECT COUNT(*) n FROM memes WHERE uploaded_by=?"),
            'likes7' => $cnt("SELECT COUNT(*) n FROM likes WHERE user_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'tracked7' => $cnt("SELECT COUNT(*) n FROM followers WHERE follower_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'trackers7' => $cnt("SELECT COUNT(*) n FROM followers WHERE following_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'tracking' => $cnt("SELECT COUNT(*) n FROM followers WHERE follower_id=?"),
            'trackers' => $cnt("SELECT COUNT(*) n FROM followers WHERE following_id=?"),
            'pods_n' => $cnt("SELECT COUNT(*) n FROM group_members WHERE user_id=?"),
            'adult_pods' => $adult, 'pods' => $pods, 'series' => $series,
            'recent_tracked'  => $fix(fam_rows($conn, "SELECT u.id,u.username,u.nickname,u.profile_pic,f.created_at FROM followers f JOIN users u ON u.id=f.following_id WHERE f.follower_id=? ORDER BY f.created_at DESC LIMIT 6", 'i', [$t])),
            'recent_trackers' => $fix(fam_rows($conn, "SELECT u.id,u.username,u.nickname,u.profile_pic,f.created_at FROM followers f JOIN users u ON u.id=f.follower_id WHERE f.following_id=? ORDER BY f.created_at DESC LIMIT 6", 'i', [$t])),
            'last_active' => $tu['last_active'] ?? null,
        ]);
    }

    /* ── activity log (all my links, or one link) ── */
    case 'activity': {
        $lid = (int)($_GET['link_id'] ?? 0);
        $sql = "SELECT g.id,g.link_id,g.action,g.detail,g.created_at,g.actor_id,u.username
                FROM family_activity_log g JOIN family_links l ON l.id=g.link_id JOIN users u ON u.id=g.actor_id
                WHERE (l.parent_id=? OR l.teen_id=?)" . ($lid ? " AND g.link_id=?" : "") . " ORDER BY g.id DESC LIMIT 40";
        $rows = $lid ? fam_rows($conn, $sql, 'iii', [$me, $me, $lid]) : fam_rows($conn, $sql, 'ii', [$me, $me]);
        foreach ($rows as &$r) { $r['mine'] = ((int)$r['actor_id'] === $me); }
        fam_json(['items' => $rows]);
    }

    default:
        fam_json(['error' => 'Unknown action.'], 404);
    }
}

/* ═══════════════════ PAGE RENDER (non-API) ═══════════════════ */
$meRow    = fam_one($conn, "SELECT username, profile_pic FROM users WHERE id=? LIMIT 1", 'i', [$me]);
$username = $meRow['username'] ?? 'You';
$profile_pic = !empty($meRow['profile_pic']) ? $meRow['profile_pic'] : 'uploads/me.png';
$init_tab  = in_array(($_GET['tab'] ?? ''), ['overview','supervising','by','requests','activity'], true) ? $_GET['tab'] : 'overview';
$open_link = (int)($_GET['link'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Family Center | Dezzme</title>
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
button{ font-family:inherit; }
a { color:inherit; text-decoration:none; }
img { max-width:100%; display:block; }

[class^="ri-"], [class*=" ri-"],
[class^="ri-"]::before, [class*=" ri-"]::before,
[class^="ri-"]::after,  [class*=" ri-"]::after {
    font-family:'remixicon' !important;
    font-style:normal; font-weight:normal; line-height:1;
    speak:none; text-transform:none;
    -webkit-font-smoothing:antialiased;
}

/* ══════════ THEME — RED (same block as Memepedia red) ══════════ */
/*THEME-START*/
:root{
    --accent:        #FF2D2D;
    --accent-hover:  #ff1a1a;
    --accent-soft:   rgba(255,45,45,.14);
    --accent-border: rgba(255,45,45,.4);
    --accent-fill:   rgba(255,45,45,.2);
    --accent-glow:   rgba(255,45,45,.28);
    --accent-text:   #ff8a8a;
    --accent-focus:  rgba(255,45,45,.7);
    --accent-ring:   rgba(255,45,45,.12);
    --accent-sel:    rgba(255,45,45,.35);
    --accent-rgb:    255,45,45;
    --scroll-thumb:  rgba(255,255,255,.32);
    --gold:          #ff8a8a;
    --gold-dim:      rgba(255,138,138,0.1);
    --gold-border:   rgba(255,138,138,0.35);
}
/*THEME-END*/

:root{
    --fg:            255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text:       #ffffff;
    --danger:        #ff6b6b;
    --danger-soft:   rgba(255,107,107,.14);
    --ok:            #4ade80;
    --ok-soft:       rgba(74,222,128,.12);
    --warn:          #fbbf24;
    --warn-soft:     rgba(251,191,36,.12);
    --on-accent:     #000;
    --solid:         #070924;
    --surface:       rgba(var(--fg),0.05);
    --surface-2:     rgba(var(--fg),0.08);
    --border:        rgba(var(--fg),0.14);
    --line:          rgba(var(--fg),0.09);
    --text:          #ffffff;
    --muted:         rgba(var(--fg),0.45);
    --muted-2:       rgba(var(--fg),0.3);
    --sub:           rgba(var(--fg),0.62);
    --bg: linear-gradient(-45deg,#010218,#070924,#070924);
    --card-bg:       rgba(12,12,28,0.99);   /* sheets */
    --pop-bg:        rgba(8,8,22,.97);      /* toasts */
    --scrim:         rgba(0,0,0,.62);
    --ph:            #171a24;               /* avatar placeholder */

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);
    --r:        5px;
    --act-h:    clamp(25px, 7.2vw, 30px);
    --act-r:    4px;
    --chip-h:   clamp(22px, 6vw, 26px);
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; -webkit-text-size-adjust:100%; }
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}
::selection{ background:var(--accent-sel); color:#fff; }
body{ background:var(--bg); background-attachment:fixed; color:var(--text); min-height:100vh; overflow-x:hidden; -webkit-font-smoothing:antialiased; }
@media (prefers-reduced-motion:reduce){ *{ animation-duration:.01ms !important; transition-duration:.01ms !important; } }

/* ══ SHELL: main lane + right info column ══ */
.fc-shell{ width:100%; display:flex; align-items:flex-start; justify-content:center; }
.fc-side{ display:none; }
@media (min-width:1100px){
    .fc-shell{ justify-content:flex-start; max-width:1240px; margin:0 auto; padding-left:22px; }
    .fc-side{
        display:block; flex:1 1 0; min-width:0; position:sticky; top:0; height:100vh;
        overflow-y:auto; overflow-x:hidden; padding:0 22px 30px 20px;
        scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent;
    }
    .fc-side::-webkit-scrollbar{ width:5px; }
    .fc-side::-webkit-scrollbar-thumb{ background:var(--scroll-thumb); border-radius:99px; }
}
.fc-lane{ width:100%; max-width:var(--max-w); min-height:100vh; align-self:stretch; display:flex; flex-direction:column; padding-bottom:56px; }
@media (min-width:801px){ .fc-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .fc-lane{ max-width:100%; } }
@media (min-width:1100px){ .fc-lane{ flex:0 0 var(--max-w); width:var(--max-w); } }

/* ══ TOPBAR ══ */
.ac-topbar{ position:sticky; top:0; z-index:200; height:var(--topbar-h); flex-shrink:0; display:flex; align-items:center; gap:2px; padding:0 10px; background:var(--bg); background-attachment:fixed; }
.ac-topbar::after{ content:''; position:absolute; left:0; right:0; bottom:0; height:1px; background:rgba(var(--fg),0.14); pointer-events:none; z-index:2; }
.ac-x{ width:34px; height:34px; background:none; border:none; color:var(--ui-text); display:flex; align-items:center; justify-content:center; opacity:.9; transition:opacity .2s, color .2s; padding:0; flex-shrink:0; }
.ac-x:hover{ opacity:1; color:var(--accent); }
.ac-x svg{ width:22px; height:22px; display:block; }
.ac-x i{ font-size:28px; }
.ac-title{ flex:1; min-width:0; font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin:0 6px 0 4px; }
.ac-title small{ font-size:.62rem; font-weight:600; color:var(--accent-text); margin-left:8px; letter-spacing:.4px; text-transform:uppercase; }
.tb-icons{ display:flex; align-items:center; flex-shrink:0; }
.tb-icon-btn{ width:40px; height:40px; background:none; border:none; border-radius:50%; color:var(--ui-text); font-size:1.5rem; display:flex; align-items:center; justify-content:center; transition:color .2s, transform .15s; padding:0; }
.tb-icon-btn:hover{ color:var(--accent); }
.tb-icon-btn:active{ transform:scale(.9); }
.tb-add-btn{ font-size:1.8rem; }
@media (max-width:490px){
    .ac-x{ width:30px; height:30px; } .ac-title{ font-size:.84rem; }
    .tb-icon-btn{ width:36px; height:36px; font-size:1.35rem; } .tb-add-btn{ font-size:1.6rem; }
}
@media (max-width:380px){ .ac-title{ font-size:.8rem; } .ac-title small{ display:none; } .tb-icon-btn{ width:33px; height:33px; } }
@media (max-width:330px){ .ac-title{ font-size:.74rem; } .tb-icon-btn{ width:30px; height:30px; font-size:1.2rem; } .tb-add-btn{ font-size:1.4rem; } }

/* ══ TAB CHIPS ══ */
.toolbar{ position:sticky; top:var(--topbar-h); z-index:190; display:flex; align-items:center; padding:10px var(--pad); background:var(--solid); border-bottom:1px solid var(--line); }
.filter-chips{ display:flex; align-items:center; gap:6px; overflow-x:auto; flex:1; scrollbar-width:none; -ms-overflow-style:none; }
.filter-chips::-webkit-scrollbar{ display:none; }
@media (max-width:768px){ .filter-chips{ margin:0 calc(var(--pad) * -1); padding:0 var(--pad); } }
.chip{ display:flex; align-items:center; gap:5px; flex-shrink:0; height:var(--chip-h); padding:0 12px; border-radius:var(--act-r); border:none; background:rgba(var(--fg),0.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); color:var(--sub); font-size:.7rem; font-weight:600; transition:background .2s, color .2s, transform .15s; white-space:nowrap; }
.chip:hover{ background:rgba(var(--fg),0.2); color:var(--ui-text); }
.chip:active{ transform:scale(.96); }
.chip.active{ background:var(--accent); color:var(--on-accent); font-weight:700; }
.chip .bdg{ min-width:15px; height:15px; padding:0 4px; border-radius:99px; background:var(--accent); color:var(--on-accent); font-size:.55rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
.chip.active .bdg{ background:var(--on-accent); color:var(--accent); }
@media (max-width:490px){ .chip{ font-size:.64rem; padding:0 10px; } }

/* ══ GENERIC ══ */
.view{ display:flex; flex-direction:column; animation:viewIn .35s ease both; }
@keyframes viewIn{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:none;} }
.sec-label{ padding:clamp(16px,4.4vw,22px) var(--pad) 8px; font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted); display:flex; align-items:center; justify-content:space-between; gap:8px; }
.sec-label a, .sec-label button{ background:none; border:none; color:var(--accent-text); font-size:.62rem; font-weight:600; letter-spacing:.3px; text-transform:none; }
.sec-label a:hover, .sec-label button:hover{ color:var(--ui-text); }

.btn{ display:inline-flex; align-items:center; justify-content:center; gap:6px; height:var(--ctl-h); padding:0 18px; border:none; border-radius:var(--r); background:var(--accent); color:var(--on-accent); font-size:.74rem; font-weight:700; transition:background .2s, transform .15s, opacity .2s; white-space:nowrap; }
.btn:hover{ background:var(--accent-hover); }
.btn:active{ transform:scale(.98); }
.btn.ghost{ background:rgba(var(--fg),.1); color:var(--ui-text); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
.btn.ghost:hover{ background:rgba(var(--fg),.2); }
.btn.danger{ background:var(--danger-soft); color:var(--danger); }
.btn.danger:hover{ background:var(--danger); color:var(--on-accent); }
.btn.sm{ height:var(--act-h); padding:0 12px; font-size:.68rem; border-radius:var(--act-r); }
.btn.block{ width:100%; }
.btn.disabled, .btn:disabled{ opacity:.4; pointer-events:none; }
@media (max-width:490px){ .btn{ font-size:.68rem; padding:0 14px; } .btn.sm{ font-size:.62rem; padding:0 10px; } }

.pill{ display:inline-flex; align-items:center; gap:4px; font-size:.58rem; font-weight:700; padding:2px 8px; border-radius:var(--act-r); background:var(--accent-soft); border:1px solid var(--accent-border); color:var(--accent-text); white-space:nowrap; }
.pill.ok{ background:var(--ok-soft); border-color:rgba(74,222,128,.35); color:var(--ok); }
.pill.wait{ background:var(--warn-soft); border-color:rgba(251,191,36,.35); color:var(--warn); }
.pill.mute{ background:rgba(var(--fg),.07); border-color:var(--line); color:var(--sub); }

/* ══ HERO ══ */
.fc-hero{ margin:clamp(12px,3.5vw,18px) var(--pad) 0; padding:clamp(16px,4.6vw,22px); border-radius:8px; border:1px solid var(--accent-border); position:relative; overflow:hidden; background:linear-gradient(135deg, rgba(255,45,45,.18), rgba(255,45,45,.05) 55%, rgba(var(--fg),.03)); }
.fc-hero::after{ content:''; position:absolute; right:-40px; top:-40px; width:150px; height:150px; border-radius:50%; background:radial-gradient(circle, var(--accent-glow), transparent 70%); pointer-events:none; }
.fc-hero-top{ display:flex; align-items:center; gap:12px; margin-bottom:10px; position:relative; z-index:1; }
.fc-hero-ic{ width:clamp(38px,10.5vw,46px); height:clamp(38px,10.5vw,46px); border-radius:11px; background:var(--accent); color:#fff; display:flex; align-items:center; justify-content:center; font-size:clamp(1.15rem,3.6vw,1.45rem); flex-shrink:0; box-shadow:0 6px 20px var(--accent-glow); }
.fc-hero h1{ font-size:clamp(1rem,3.8vw,1.2rem); font-weight:700; letter-spacing:-.2px; line-height:1.25; }
.fc-hero h1 small{ display:block; font-size:.6rem; color:var(--accent-text); font-weight:600; letter-spacing:1.2px; text-transform:uppercase; margin-bottom:2px; }
.fc-hero p{ font-size:clamp(.68rem,2.1vw,.76rem); color:var(--sub); line-height:1.7; font-weight:300; position:relative; z-index:1; }
.fc-hero-acts{ display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; position:relative; z-index:1; }

/* ══ PROGRESS + ROADMAP ══ */
.fc-prog-wrap{ padding:0 var(--pad); }
.fc-prog-head{ display:flex; align-items:center; justify-content:space-between; font-size:.66rem; color:var(--sub); margin-bottom:7px; }
.fc-prog-head b{ color:var(--ui-text); font-weight:700; }
.fc-prog{ height:6px; border-radius:99px; background:rgba(var(--fg),.08); overflow:hidden; }
.fc-prog i{ display:block; height:100%; width:0; border-radius:99px; background:linear-gradient(90deg, var(--accent), #ff6a6a); transition:width .8s cubic-bezier(.22,.9,.3,1); }
.rd{ display:flex; gap:12px; padding:clamp(11px,3.2vw,14px) var(--pad); border-top:1px solid var(--line); cursor:pointer; transition:background .18s; }
.rd:last-child{ border-bottom:1px solid var(--line); }
.rd:hover{ background:rgba(var(--fg),.04); }
.rd-n{ width:26px; height:26px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.66rem; font-weight:700; background:var(--surface-2); color:var(--sub); border:1px solid var(--line); transition:.25s; }
.rd.done .rd-n{ background:var(--accent); color:var(--on-accent); border-color:var(--accent); }
.rd.cur .rd-n{ border-color:var(--accent); color:var(--accent-text); box-shadow:0 0 0 4px var(--accent-ring); }
.rd-t{ flex:1; min-width:0; }
.rd-t b{ display:flex; align-items:center; gap:7px; font-size:clamp(.72rem,2.3vw,.8rem); font-weight:600; }
.rd-t span{ display:block; font-size:clamp(.62rem,1.9vw,.68rem); color:var(--muted); line-height:1.55; margin-top:2px; }
.rd.done .rd-t b{ color:var(--sub); }
.rd > i{ color:var(--muted); font-size:1rem; align-self:center; flex-shrink:0; }

/* ══ TILES ══ */
.tiles{ display:grid; grid-template-columns:repeat(2,1fr); gap:1px; background:var(--line); border-top:1px solid var(--line); border-bottom:1px solid var(--line); margin-top:clamp(16px,4.4vw,22px); }
.tile{ background:var(--solid); padding:clamp(12px,3.4vw,16px) var(--pad); display:flex; align-items:center; gap:11px; }
.tile-ic{ width:clamp(30px,8vw,36px); height:clamp(30px,8vw,36px); border-radius:8px; background:var(--accent-soft); color:var(--accent-text); display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
.tile-n{ font-size:clamp(1rem,3.8vw,1.25rem); font-weight:700; line-height:1; }
.tile-l{ font-size:clamp(.5rem,1.7vw,.6rem); color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-top:4px; }

/* ══ LINK ROWS ══ */
.fc-row{ display:flex; align-items:flex-start; gap:clamp(11px,3vw,14px); padding:clamp(12px,3.4vw,15px) var(--pad); border-bottom:1px solid var(--line); transition:background .18s; cursor:pointer; position:relative; opacity:0; animation:rowIn .4s ease forwards; }
@keyframes rowIn{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:none;} }
.fc-row:hover{ background:rgba(var(--fg),.04); }
.fc-row.static{ cursor:default; } .fc-row.static:hover{ background:none; }
.av{ width:clamp(42px,12vw,52px); height:clamp(42px,12vw,52px); border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid var(--accent); background:var(--ph); }
.av.sm{ width:clamp(34px,9.4vw,40px); height:clamp(34px,9.4vw,40px); border-width:1.5px; }
.av.xs{ width:30px; height:30px; border-width:1.5px; }
.av.lg{ width:clamp(64px,19vw,84px); height:clamp(64px,19vw,84px); border-width:2.6px; box-shadow:0 4px 18px rgba(255,45,45,.25); }
.fc-info{ flex:1; min-width:0; }
.fc-top{ display:flex; align-items:center; gap:7px; margin-bottom:3px; flex-wrap:wrap; }
.fc-name{ font-size:clamp(.78rem,2.5vw,.88rem); font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
.fc-sub{ font-size:clamp(.62rem,2vw,.7rem); color:var(--muted); line-height:1.5; margin-bottom:5px; }
.fc-meta{ font-size:clamp(.58rem,1.9vw,.65rem); color:var(--muted-2); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.fc-meta span{ display:flex; align-items:center; gap:3px; }
.fc-acts{ display:flex; gap:6px; margin-top:9px; flex-wrap:wrap; }
.fc-chev{ color:var(--muted); font-size:1.1rem; align-self:center; flex-shrink:0; }

/* ══ EMPTY ══ */
.empty{ flex:1; min-height:44vh; padding:clamp(28px,8vw,48px) clamp(20px,7vw,36px) clamp(36px,10vw,60px); display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; }
.empty-ic{ width:56px; height:56px; border-radius:50%; background:var(--accent-soft); color:var(--accent-text); display:flex; align-items:center; justify-content:center; font-size:1.6rem; margin-bottom:14px; }
.es-title{ font-size:clamp(.95rem,3.4vw,1.1rem); font-weight:700; letter-spacing:-.2px; }
.es-text{ margin-top:6px; max-width:340px; font-size:clamp(.66rem,2.1vw,.76rem); color:var(--muted); line-height:1.7; }
.es-tips{ width:100%; max-width:360px; margin-top:clamp(16px,5vw,22px); display:flex; flex-direction:column; gap:7px; }
.es-tip{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--act-r); background:rgba(var(--fg),0.05); text-align:left; }
.es-tip i{ font-size:1rem; color:var(--accent); flex-shrink:0; }
.es-tip span{ font-size:clamp(.62rem,2vw,.7rem); color:var(--sub); line-height:1.5; }
.empty .btn{ margin-top:clamp(16px,5vw,22px); }

/* ══ PRINCIPLE CARDS ══ */
.pr{ display:flex; gap:12px; padding:clamp(12px,3.4vw,15px) var(--pad); border-top:1px solid var(--line); }
.pr:last-child{ border-bottom:1px solid var(--line); }
.pr-ic{ width:32px; height:32px; border-radius:8px; background:var(--surface); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:.98rem; flex-shrink:0; }
.pr b{ display:block; font-size:clamp(.72rem,2.3vw,.8rem); font-weight:600; }
.pr span{ display:block; font-size:clamp(.62rem,1.9vw,.68rem); color:var(--muted); line-height:1.6; margin-top:2px; }

/* ══ RIGHT COLUMN CARDS ══ */
.gs-cards{ padding:18px 0 2px; }
.gs-card{ margin-bottom:12px; }
.gs-bar{ display:flex; align-items:center; gap:7px; width:100%; height:30px; padding:0 12px; border:none; border-radius:var(--act-r); background:rgba(var(--fg),.1); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); color:var(--ui-text); font-size:.72rem; font-weight:600; line-height:1; text-align:left; transition:background .2s ease, transform .15s ease; }
.gs-bar:hover{ background:rgba(var(--fg),.2); }
.gs-bar:active{ transform:scale(.99); }
.gs-bar-ic{ font-size:14px; color:var(--accent-text); flex-shrink:0; }
.gs-bar-t{ flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.gs-bar-chev{ font-size:18px; color:var(--sub); flex-shrink:0; transition:transform .25s ease; }
.gs-card.collapsed .gs-bar-chev{ transform:rotate(-90deg); }
.gs-body{ display:grid; grid-template-rows:1fr; transition:grid-template-rows .3s ease; }
.gs-inner{ min-height:0; overflow:hidden; }
.gs-pad{ padding-top:8px; }
.gs-card.collapsed .gs-body{ grid-template-rows:0fr; }
.gs-row{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:9px 2px; border-bottom:1px solid rgba(var(--fg),.06); }
.gs-row:last-child{ border-bottom:none; }
.gs-row-l{ font-size:.66rem; color:var(--muted); font-weight:500; white-space:nowrap; }
.gs-row-v{ font-size:.74rem; font-weight:600; color:var(--ui-text); text-align:right; min-width:0; white-space:nowrap; }
.gs-p{ font-size:.72rem; line-height:1.65; color:var(--sub); overflow-wrap:anywhere; padding:2px 2px 4px; }
.gs-p b{ color:var(--ui-text); font-weight:600; }
.gs-callout{ display:flex; gap:9px; margin:8px 0 4px; padding:10px 11px; border-radius:var(--act-r); background:var(--accent-soft); border:1px solid var(--accent-border); font-size:.67rem; line-height:1.6; color:var(--sub); }
.gs-callout i{ color:var(--accent-text); font-size:1rem; flex-shrink:0; margin-top:1px; }
.gs-callout b{ color:var(--ui-text); }
.gs-step{ display:flex; gap:11px; padding:10px 2px; border-bottom:1px solid rgba(var(--fg),.06); }
.gs-step:last-child{ border-bottom:none; }
.gs-sn{ width:22px; height:22px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.62rem; font-weight:700; color:var(--on-accent); background:var(--accent); }
.gs-st b{ display:block; font-size:.72rem; margin-bottom:2px; }
.gs-st span{ display:block; font-size:.65rem; line-height:1.55; color:var(--muted); overflow-wrap:anywhere; }
.gs-rule{ display:flex; gap:9px; padding:6px 2px; font-size:.68rem; line-height:1.55; color:var(--sub); overflow-wrap:anywhere; }
.gs-rule i{ font-size:.95rem; flex-shrink:0; margin-top:1px; color:var(--ok); }
.gs-rule.no i{ color:var(--danger); }
.gs-link{ display:flex; align-items:center; gap:9px; width:100%; padding:9px 2px; border:none; border-bottom:1px solid rgba(var(--fg),.06); background:none; color:inherit; font-size:.7rem; text-align:left; }
.gs-link:last-child{ border-bottom:none; }
.gs-link i:first-child{ color:var(--accent-text); font-size:.95rem; }
.gs-link span{ flex:1; }
.gs-link:hover span{ text-decoration:underline; }
.gs-note{ font-size:.68rem; color:var(--muted); line-height:1.55; padding:4px 2px; }

/* ══ MODAL SHELL ══ */
.modal-overlay{ position:fixed; inset:0; z-index:1000; display:none; justify-content:center; align-items:flex-start; background:var(--bg); background-attachment:fixed; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch; }
.modal-overlay.active{ display:flex; }
@media (min-width:801px) and (hover:hover) and (pointer:fine){
    .modal-overlay{ scrollbar-width:thin; scrollbar-color:var(--scroll-thumb) transparent; }
    .modal-overlay::-webkit-scrollbar{ display:block; width:5px; }
    .modal-overlay::-webkit-scrollbar-track{ background:transparent; }
    .modal-overlay::-webkit-scrollbar-thumb{ background:var(--scroll-thumb); border-radius:99px; }
}
.modal-lane{ width:100%; max-width:var(--max-w); min-height:100%; display:flex; flex-direction:column; }
@media (min-width:801px){ .modal-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .modal-lane{ max-width:100%; } }
.modal-body{ flex:1; padding:6px var(--pad) 36px; width:100%; }
.step-count{ font-size:.66rem; font-weight:600; color:var(--sub); padding:0 8px; white-space:nowrap; }
.step-count b{ color:var(--ui-text); }

/* ══ WIZARD ══ */
.wz-bar{ display:flex; gap:5px; padding:14px var(--pad) 0; }
.wz-bar i{ flex:1; height:4px; border-radius:99px; background:rgba(var(--fg),.1); transition:background .3s; }
.wz-bar i.on{ background:var(--accent); }
.wz-bar i.cur{ background:var(--accent); box-shadow:0 0 10px var(--accent-glow); }
.wz-eyebrow{ font-size:.6rem; font-weight:700; letter-spacing:1.6px; text-transform:uppercase; color:var(--accent-text); margin:18px 0 6px; }
.wz-title{ font-size:clamp(1rem,4vw,1.2rem); font-weight:700; letter-spacing:-.2px; line-height:1.3; margin-bottom:6px; }
.wz-sub{ font-size:clamp(.68rem,2.1vw,.76rem); color:var(--sub); line-height:1.7; font-weight:300; margin-bottom:16px; }
.wz-pane{ animation:viewIn .3s ease both; }
.wz-foot{ position:sticky; bottom:0; z-index:20; display:flex; gap:8px; padding:12px var(--pad) calc(12px + env(safe-area-inset-bottom,0px)); background:var(--solid); border-top:1px solid var(--line); }
.wz-foot .btn{ flex:1; }
.wz-foot .btn.ghost{ flex:0 0 auto; min-width:90px; }

.choice{ display:flex; align-items:flex-start; gap:12px; width:100%; padding:clamp(13px,3.6vw,16px); margin-bottom:10px; border-radius:8px; border:1.5px solid var(--border); background:var(--surface); color:inherit; text-align:left; transition:border-color .2s, background .2s, transform .15s; position:relative; }
.choice:hover{ background:rgba(var(--fg),.08); }
.choice:active{ transform:scale(.99); }
.choice.sel{ border-color:var(--accent); background:var(--accent-soft); }
.choice.off{ opacity:.45; pointer-events:none; }
.choice-ic{ width:38px; height:38px; border-radius:10px; background:var(--accent-soft); color:var(--accent-text); display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
.choice b{ display:block; font-size:clamp(.78rem,2.5vw,.86rem); font-weight:700; }
.choice span{ display:block; font-size:clamp(.64rem,2vw,.7rem); color:var(--sub); line-height:1.6; margin-top:3px; font-weight:300; }
.choice .tick{ position:absolute; top:12px; right:12px; width:20px; height:20px; border-radius:50%; border:1.5px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:.8rem; color:transparent; transition:.2s; }
.choice.sel .tick{ background:var(--accent); border-color:var(--accent); color:var(--on-accent); }
.choice .why{ color:var(--warn); font-size:.62rem; margin-top:4px; }

.ck{ display:flex; align-items:flex-start; gap:10px; padding:12px; border-radius:var(--r); background:var(--surface); border:1px solid var(--line); cursor:pointer; margin-top:6px; }
.ck input{ position:absolute; opacity:0; pointer-events:none; }
.ck-box{ width:20px; height:20px; border-radius:5px; border:1.5px solid var(--border); flex-shrink:0; display:flex; align-items:center; justify-content:center; color:transparent; font-size:.85rem; transition:.2s; margin-top:1px; }
.ck input:checked + .ck-box{ background:var(--accent); border-color:var(--accent); color:var(--on-accent); }
.ck input:focus-visible + .ck-box{ box-shadow:0 0 0 3px var(--accent-ring); }
.ck-t{ font-size:clamp(.66rem,2vw,.72rem); color:var(--sub); line-height:1.65; }
.ck-t b{ color:var(--ui-text); font-weight:600; }

.gc-input, .gc-select{ width:100%; padding:0 12px; height:var(--ctl-h); border-radius:var(--r); border:1px solid var(--border); background:var(--surface); color:var(--ui-text); font-size:.76rem; outline:none; transition:border-color .2s, background .2s, box-shadow .2s; -webkit-appearance:none; appearance:none; }
.gc-input:focus, .gc-select:focus{ border-color:var(--accent-focus); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-ring); }
.gc-input::placeholder{ color:var(--muted); }
.gc-select{ cursor:pointer; padding-right:34px; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.5)' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; }
.gc-select option{ background:var(--solid); color:var(--ui-text); }
input[type=time].gc-input{ padding:0 8px; color-scheme:dark; }
.search-wrap{ display:flex; align-items:center; gap:8px; height:var(--ctl-h); background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:0 12px; transition:border-color .2s, background .2s, box-shadow .2s; }
.search-wrap:focus-within{ border-color:var(--accent-focus); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px var(--accent-ring); }
.search-wrap i{ color:var(--muted); font-size:.95rem; }
.search-wrap input{ background:transparent; border:none; outline:none; flex:1; min-width:0; color:var(--ui-text); font-size:.76rem; }
.search-wrap input::placeholder{ color:var(--muted); }
.res-list{ margin:12px calc(var(--pad) * -1) 0; }
.res{ display:flex; align-items:center; gap:12px; padding:10px var(--pad); border-bottom:1px solid var(--line); cursor:pointer; transition:background .15s; }
.res:hover{ background:rgba(var(--fg),.05); }
.res.off{ opacity:.5; cursor:not-allowed; }
.res-i{ flex:1; min-width:0; }
.res-i b{ display:block; font-size:clamp(.74rem,2.4vw,.82rem); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.res-i span{ display:block; font-size:clamp(.6rem,1.9vw,.68rem); color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sel-card{ display:flex; align-items:center; gap:12px; padding:12px; border-radius:8px; border:1.5px solid var(--accent); background:var(--accent-soft); margin-top:12px; }
.sel-card .res-i{ flex:1; }
.hint{ display:flex; gap:9px; padding:10px 12px; margin-top:12px; border-radius:var(--act-r); background:var(--surface); border:1px solid var(--line); font-size:.66rem; color:var(--sub); line-height:1.6; }
.hint i{ color:var(--accent-text); font-size:.95rem; flex-shrink:0; margin-top:1px; }
.hint b{ color:var(--ui-text); }
.opt-wrap{ display:flex; flex-wrap:wrap; gap:7px; }
.opt{ height:var(--act-h); padding:0 14px; border-radius:var(--act-r); border:none; background:rgba(var(--fg),.1); color:var(--ui-text); font-size:.7rem; font-weight:600; transition:background .2s, transform .15s; }
.opt:hover{ background:rgba(var(--fg),.2); }
.opt:active{ transform:scale(.96); }
.opt.sel{ background:var(--accent); color:var(--on-accent); font-weight:700; }
.two-col{ display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:16px; }
@media (max-width:420px){ .two-col{ grid-template-columns:1fr; } }
.col-card{ padding:12px; border-radius:8px; background:var(--surface); border:1px solid var(--line); }
.col-card h4{ font-size:.66rem; font-weight:700; letter-spacing:.8px; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
.col-card h4.y{ color:var(--ok); } .col-card h4.n{ color:var(--danger); }
.col-card .gs-rule{ padding:4px 0; font-size:.65rem; }

.preset-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:6px; }
@media (max-width:420px){ .preset-grid{ grid-template-columns:1fr; } }
.preset{ padding:12px 10px; border-radius:8px; border:1.5px solid var(--border); background:var(--surface); color:inherit; text-align:left; transition:.2s; position:relative; }
.preset:hover{ background:rgba(var(--fg),.08); }
.preset.sel{ border-color:var(--accent); background:var(--accent-soft); }
.preset i{ font-size:1.15rem; color:var(--accent-text); }
.preset b{ display:block; font-size:.76rem; margin:6px 0 2px; }
.preset span{ display:block; font-size:.6rem; color:var(--sub); line-height:1.5; }
.preset em{ position:absolute; top:8px; right:8px; font-style:normal; font-size:.5rem; font-weight:700; letter-spacing:.5px; text-transform:uppercase; background:var(--accent); color:var(--on-accent); padding:2px 6px; border-radius:4px; }

.rev-top{ display:flex; align-items:center; justify-content:center; gap:14px; padding:16px 0 4px; }
.rev-top > i{ font-size:1.4rem; color:var(--accent); }
.rev-p{ text-align:center; max-width:110px; }
.rev-p b{ display:block; font-size:.7rem; margin-top:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.rev-p span{ font-size:.58rem; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
.rev-list{ margin:14px calc(var(--pad) * -1) 0; border-top:1px solid var(--line); }
.rev-item{ display:flex; align-items:center; gap:11px; padding:10px var(--pad); border-bottom:1px solid var(--line); }
.rev-item > i{ color:var(--accent); font-size:1rem; width:20px; text-align:center; }
.rev-item span{ flex:1; font-size:.7rem; color:var(--sub); }
.rev-item b{ font-size:.72rem; font-weight:600; }

.done-wrap{ text-align:center; padding:clamp(24px,7vw,40px) 6px 6px; }
.done-ic{ width:68px; height:68px; margin:0 auto 14px; border-radius:50%; background:var(--accent); color:#fff; display:flex; align-items:center; justify-content:center; font-size:2rem; box-shadow:0 0 0 8px var(--accent-ring), 0 10px 30px var(--accent-glow); animation:pop .5s cubic-bezier(.2,1.4,.4,1) both; }
@keyframes pop{ from{ transform:scale(.3); opacity:0; } to{ transform:none; opacity:1; } }
.next-list{ text-align:left; margin:20px calc(var(--pad) * -1) 0; }

/* ══ CONTROLS EDITOR ══ */
.ctl-sec{ display:flex; align-items:center; gap:7px; margin:18px 0 4px; font-size:.6rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); }
.ctl-sec i{ color:var(--accent); font-size:.85rem; }
.ctl-sec::after{ content:''; flex:1; height:1px; background:var(--line); }
.ctl{ display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid var(--line); }
.ctl:last-child{ border-bottom:none; }
.ctl-i{ flex:1; min-width:0; }
.ctl-i b{ display:block; font-size:clamp(.72rem,2.3vw,.8rem); font-weight:600; }
.ctl-i span{ display:block; font-size:clamp(.6rem,1.9vw,.66rem); color:var(--muted); line-height:1.55; margin-top:2px; }
.ctl-sub{ display:flex; align-items:center; gap:8px; padding:0 0 12px; font-size:.66rem; color:var(--sub); }
.ctl-sub[hidden]{ display:none; }
.ctl-sub .gc-input{ width:100px; }
.ctl-row-wrap{ border-bottom:1px solid var(--line); } .ctl-row-wrap .ctl{ border-bottom:none; }
.ctl .gc-select{ width:124px; flex-shrink:0; }
.sw{ position:relative; width:40px; height:23px; flex-shrink:0; display:block; }
.sw input{ position:absolute; inset:0; opacity:0; width:100%; height:100%; margin:0; cursor:pointer; z-index:2; }
.sw span{ position:absolute; inset:0; border-radius:99px; background:rgba(var(--fg),.16); transition:background .2s; }
.sw span::after{ content:''; position:absolute; top:3px; left:3px; width:17px; height:17px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.3); transition:transform .22s cubic-bezier(.3,1.3,.5,1); }
.sw input:checked + span{ background:var(--accent); }
.sw input:checked + span::after{ transform:translateX(17px); }
.sw input:focus-visible + span{ box-shadow:0 0 0 3px var(--accent-ring); }
.sw input:disabled{ cursor:not-allowed; } .sw input:disabled + span{ opacity:.55; }
.seg{ display:flex; gap:3px; padding:3px; border-radius:var(--r); background:rgba(var(--fg),.07); flex-shrink:0; }
.seg button{ height:calc(var(--act-h) - 2px); padding:0 9px; border:none; border-radius:3px; background:transparent; color:var(--sub); font-size:.6rem; font-weight:600; white-space:nowrap; transition:.2s; }
.seg button.sel{ background:var(--accent); color:var(--on-accent); font-weight:700; }
.seg.ro button:not(.sel){ opacity:.5; }
@media (max-width:490px){ .ctl{ flex-wrap:wrap; } .ctl .seg{ width:100%; } .seg button{ flex:1; } .ctl .gc-select{ width:110px; } }

/* ══ MANAGE MODAL ══ */
.mg-head{ display:flex; align-items:center; gap:14px; padding:clamp(16px,4.4vw,22px) var(--pad) 14px; }
.mg-head h2{ font-size:clamp(.95rem,3.6vw,1.12rem); font-weight:700; letter-spacing:-.2px; }
.mg-head p{ font-size:clamp(.6rem,1.9vw,.68rem); color:var(--muted); margin-top:2px; }
.mg-head .pill{ margin-top:6px; }
.detail-tabs{ display:flex; align-items:stretch; border-top:1px solid var(--line); border-bottom:1px solid var(--line); overflow-x:auto; scrollbar-width:none; }
.detail-tabs::-webkit-scrollbar{ display:none; }
.detail-tab{ flex:1 0 auto; padding:clamp(10px,2.8vw,13px) 14px; font-size:.7rem; font-weight:600; color:var(--muted); transition:color .2s, border-color .2s; border:none; border-bottom:2px solid transparent; white-space:nowrap; margin-bottom:-1px; background:none; display:flex; align-items:center; justify-content:center; gap:5px; }
.detail-tab:hover{ color:var(--ui-text); }
.detail-tab.active{ color:var(--ui-text); border-bottom-color:var(--ui-text); }
.mg-pane{ padding:6px var(--pad) 40px; animation:viewIn .3s ease both; }
.banner{ display:flex; gap:10px; padding:11px 12px; border-radius:var(--act-r); background:var(--accent-soft); border:1px solid var(--accent-border); font-size:.66rem; color:var(--sub); line-height:1.6; margin-top:14px; }
.banner i{ color:var(--accent-text); font-size:1rem; flex-shrink:0; margin-top:1px; }
.banner b{ color:var(--ui-text); }
.banner.info{ background:var(--surface); border-color:var(--line); }
.savebar{ position:sticky; bottom:0; z-index:30; display:none; align-items:center; gap:10px; padding:11px var(--pad) calc(11px + env(safe-area-inset-bottom,0px)); background:var(--solid); border-top:1px solid var(--accent-border); }
.savebar.show{ display:flex; animation:viewIn .25s ease both; }
.savebar span{ flex:1; font-size:.68rem; color:var(--sub); }
.info-row{ display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid var(--line); }
.info-row > i{ width:30px; height:30px; border-radius:7px; background:var(--surface); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0; }
.info-row small{ display:block; font-size:.54rem; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
.info-row b{ display:block; font-size:.76rem; font-weight:500; margin-top:1px; }
.danger-zone{ margin-top:22px; padding:14px; border-radius:8px; border:1px solid rgba(255,107,107,.3); background:var(--danger-soft); }
.danger-zone h4{ font-size:.76rem; color:var(--danger); margin-bottom:4px; display:flex; align-items:center; gap:6px; }
.danger-zone p{ font-size:.66rem; color:var(--sub); line-height:1.65; margin-bottom:12px; }

/* insights */
.ins-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:1px; background:var(--line); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; margin-top:14px; }
@media (max-width:420px){ .ins-grid{ grid-template-columns:repeat(2,1fr); } }
.ins{ background:var(--solid); padding:12px 10px; text-align:center; }
.ins b{ display:block; font-size:clamp(.95rem,3.6vw,1.15rem); font-weight:700; line-height:1; }
.ins span{ display:block; font-size:.5rem; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-top:5px; line-height:1.4; }
.card{ margin-top:16px; padding:14px; border-radius:8px; background:var(--surface); border:1px solid var(--line); }
.card h4{ font-size:.62rem; font-weight:700; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); margin-bottom:12px; }
.bars{ display:flex; align-items:flex-end; gap:6px; height:96px; }
.bar{ flex:1; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; gap:5px; min-width:0; }
.bar i{ width:100%; border-radius:4px 4px 0 0; background:linear-gradient(180deg, var(--accent), rgba(255,45,45,.45)); min-height:3px; transform-origin:bottom; animation:grow .7s cubic-bezier(.22,.9,.3,1) both; }
.bar i.z{ background:rgba(var(--fg),.1); }
.bar em{ font-style:normal; font-size:.52rem; color:var(--muted); font-weight:600; }
.bar u{ text-decoration:none; font-size:.54rem; color:var(--sub); font-weight:600; }
@keyframes grow{ from{ transform:scaleY(0); } to{ transform:scaleY(1); } }
.mini{ display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid rgba(var(--fg),.06); }
.mini:last-child{ border-bottom:none; }
.mini b{ display:block; font-size:.72rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mini span{ display:block; font-size:.58rem; color:var(--muted); }
.mini > div{ flex:1; min-width:0; }
.alert{ display:flex; gap:10px; padding:11px 12px; margin-top:14px; border-radius:var(--act-r); background:var(--warn-soft); border:1px solid rgba(251,191,36,.35); font-size:.66rem; color:var(--sub); line-height:1.6; }
.alert i{ color:var(--warn); font-size:1rem; flex-shrink:0; margin-top:1px; }
.alert b{ color:var(--ui-text); }

/* timeline */
.tl{ display:flex; gap:12px; padding:12px 0; border-bottom:1px solid var(--line); }
.tl:last-child{ border-bottom:none; }
.tl-d{ width:30px; height:30px; border-radius:50%; background:var(--accent-soft); color:var(--accent-text); display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
.tl-d.ok{ background:var(--ok-soft); color:var(--ok); } .tl-d.bad{ background:var(--danger-soft); color:var(--danger); }
.tl b{ display:block; font-size:.72rem; font-weight:600; line-height:1.45; }
.tl span{ display:block; font-size:.62rem; color:var(--muted); line-height:1.55; margin-top:1px; }
.tl small{ display:block; font-size:.56rem; color:var(--muted-2); margin-top:3px; }
.tl-wrap{ padding:0 var(--pad); }

/* ══ GUIDE / FAQ ══ */
.faq{ border-top:1px solid var(--line); }
.faq:last-child{ border-bottom:1px solid var(--line); }
.faq-q{ display:flex; align-items:center; gap:10px; width:100%; padding:13px var(--pad); background:none; border:none; color:var(--ui-text); font-size:clamp(.72rem,2.3vw,.8rem); font-weight:600; text-align:left; }
.faq-q span{ flex:1; }
.faq-q i{ color:var(--muted); font-size:1.1rem; transition:transform .25s; }
.faq.open .faq-q i{ transform:rotate(180deg); color:var(--accent); }
.faq-a{ display:grid; grid-template-rows:0fr; transition:grid-template-rows .3s ease; }
.faq.open .faq-a{ grid-template-rows:1fr; }
.faq-a > div{ overflow:hidden; }
.faq-a p{ padding:0 var(--pad) 14px; font-size:clamp(.66rem,2vw,.72rem); color:var(--sub); line-height:1.75; font-weight:300; }

/* ══ CONFIRM SHEET + TOAST ══ */
.sheet-bd{ position:fixed; inset:0; z-index:3000; background:rgba(0,0,0,0); opacity:0; visibility:hidden; transition:opacity .25s, background .25s, visibility .25s; display:flex; align-items:flex-end; justify-content:center; }
.sheet-bd.open{ opacity:1; visibility:visible; background:var(--scrim); }
.sheet{ width:100%; max-width:480px; background:var(--card-bg); border:1px solid var(--line); border-bottom:none; border-radius:16px 16px 0 0; padding:6px var(--pad) calc(16px + env(safe-area-inset-bottom,0px)); transform:translateY(100%); transition:transform .28s cubic-bezier(.32,.72,0,1); }
.sheet-bd.open .sheet{ transform:none; }
.sheet::before{ content:''; display:block; width:36px; height:4px; border-radius:4px; background:rgba(var(--fg),.18); margin:8px auto 14px; }
.sheet h3{ font-size:.92rem; font-weight:700; margin-bottom:6px; }
.sheet p{ font-size:.7rem; color:var(--sub); line-height:1.7; margin-bottom:16px; }
.sheet .row{ display:flex; gap:8px; } .sheet .row .btn{ flex:1; }
@media (min-width:600px){ .sheet-bd{ align-items:center; padding:20px; } .sheet{ border-radius:10px; border-bottom:1px solid var(--line); } .sheet::before{ display:none; } .sheet{ padding-top:20px; } }

.toast-container{ position:fixed; bottom:24px; left:50%; transform:translateX(-50%); z-index:4000; display:flex; flex-direction:column; gap:8px; align-items:center; pointer-events:none; }
.toast{ display:flex; align-items:center; gap:9px; padding:9px 18px; border-radius:var(--r); background:var(--pop-bg); border:1px solid var(--accent-border); font-size:.74rem; font-weight:500; color:var(--text); box-shadow:0 8px 24px rgba(0,0,0,.6); animation:toastIn .3s ease forwards; max-width:calc(100vw - 32px); }
.toast i{ color:var(--accent); font-size:1rem; flex-shrink:0; }
.toast.success i{ color:var(--ok); }
@keyframes toastIn{ from{ opacity:0; transform:translateY(12px);} to{ opacity:1; transform:none;} }

/* ══ SKELETON ══ */
.sk-shimmer{ position:relative; overflow:hidden; background:rgba(var(--fg),.06); border-radius:6px; }
.sk-shimmer::after{ content:''; position:absolute; inset:0; background:linear-gradient(90deg, transparent, rgba(var(--fg),.09), transparent); transform:translateX(-100%); animation:sk-shine 1.5s infinite; }
@keyframes sk-shine{ 100%{ transform:translateX(100%); } }
.sk-row{ display:flex; align-items:flex-start; gap:12px; padding:14px var(--pad); border-bottom:1px solid var(--line); }
.sk-av{ width:clamp(42px,12vw,52px); height:clamp(42px,12vw,52px); border-radius:50%; flex-shrink:0; }
.sk-lines{ flex:1; display:flex; flex-direction:column; gap:7px; padding-top:2px; }
.sk-hero{ margin:clamp(12px,3.5vw,18px) var(--pad) 0; height:170px; border-radius:8px; }
.sk-tiles{ display:grid; grid-template-columns:repeat(2,1fr); gap:1px; margin-top:22px; border-top:1px solid var(--line); border-bottom:1px solid var(--line); background:var(--line); }
.sk-tile{ background:var(--solid); padding:16px var(--pad); display:flex; gap:11px; align-items:center; }
.sk-pad{ padding:0 var(--pad); }
.gs-row-v .sk-shimmer{ display:inline-block; width:34px; height:12px; vertical-align:middle; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Same approach as the About This Account page. Kept near the end
   so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --ctl-h:clamp(33px, 8.8vw, 39.6px);
        --r:5.5px;
        --act-h:clamp(27.5px, 7.92vw, 33px);
        --act-r:4.4px;
        --chip-h:clamp(24px, 6.6vw, 28.6px);
    }

    /* topbar */
    .ac-topbar{ padding:0 11px; gap:2px; }
    .ac-x{ width:37px; height:37px; }
    .ac-x svg{ width:24px; height:24px; }
    .ac-x i{ font-size:31px; }
    .ac-title{ font-size:1.01rem; margin:0 7px 0 5px; }
    .ac-title small{ font-size:.68rem; margin-left:9px; }
    .tb-icon-btn{ width:44px; height:44px; font-size:1.65rem; }
    .tb-add-btn{ font-size:1.98rem; }

    /* tabs */
    .toolbar{ padding:11px var(--pad); }
    .filter-chips{ gap:6.6px; }
    .chip{ gap:5.5px; padding:0 13px; font-size:.77rem; }
    .chip .bdg{ min-width:16.5px; height:16.5px; padding:0 4.4px; font-size:.6rem; }

    /* generic */
    .sec-label{ padding:clamp(18px, 4.8vw, 24px) var(--pad) 8.8px; font-size:.66rem; letter-spacing:1.65px; gap:8.8px; }
    .sec-label a, .sec-label button{ font-size:.68rem; }
    .btn{ gap:6.6px; padding:0 20px; font-size:.81rem; }
    .btn.sm{ padding:0 13px; font-size:.75rem; }
    .pill{ gap:4.4px; font-size:.64rem; padding:2.2px 8.8px; }

    /* hero */
    .fc-hero{ margin:clamp(13px, 3.85vw, 20px) var(--pad) 0; padding:clamp(18px, 5vw, 24px); border-radius:8.8px; }
    .fc-hero-top{ gap:13px; margin-bottom:11px; }
    .fc-hero-ic{ width:clamp(42px, 11.5vw, 51px); height:clamp(42px, 11.5vw, 51px); border-radius:12px; font-size:clamp(1.27rem, 3.96vw, 1.6rem); }
    .fc-hero h1{ font-size:clamp(1.1rem, 4.2vw, 1.32rem); }
    .fc-hero h1 small{ font-size:.66rem; }
    .fc-hero p{ font-size:clamp(.75rem, 2.31vw, .84rem); }
    .fc-hero-acts{ gap:8.8px; margin-top:15px; }

    /* progress + roadmap */
    .fc-prog-head{ font-size:.73rem; margin-bottom:7.7px; }
    .fc-prog{ height:6.6px; }
    .rd{ gap:13px; padding:clamp(12px, 3.5vw, 15.4px) var(--pad); }
    .rd-n{ width:28.6px; height:28.6px; font-size:.73rem; }
    .rd-t b{ gap:7.7px; font-size:clamp(.79rem, 2.53vw, .88rem); }
    .rd-t span{ font-size:clamp(.68rem, 2.09vw, .75rem); margin-top:2.2px; }
    .rd > i{ font-size:1.1rem; }

    /* tiles */
    .tiles{ margin-top:clamp(18px, 4.8vw, 24px); }
    .tile{ padding:clamp(13px, 3.74vw, 17.6px) var(--pad); gap:12px; }
    .tile-ic{ width:clamp(33px, 8.8vw, 39.6px); height:clamp(33px, 8.8vw, 39.6px); border-radius:8.8px; font-size:1.1rem; }
    .tile-n{ font-size:clamp(1.1rem, 4.18vw, 1.375rem); }
    .tile-l{ font-size:clamp(.55rem, 1.87vw, .66rem); margin-top:4.4px; }

    /* link rows */
    .fc-row{ gap:clamp(12px, 3.3vw, 15.4px); padding:clamp(13px, 3.74vw, 16.5px) var(--pad); }
    .av{ width:clamp(46px, 13.2vw, 57px); height:clamp(46px, 13.2vw, 57px); border-width:2.2px; }
    .av.sm{ width:clamp(37px, 10.3vw, 44px); height:clamp(37px, 10.3vw, 44px); border-width:1.65px; }
    .av.xs{ width:33px; height:33px; border-width:1.65px; }
    .av.lg{ width:clamp(70px, 20.9vw, 92px); height:clamp(70px, 20.9vw, 92px); border-width:2.9px; }
    .fc-top{ gap:7.7px; margin-bottom:3.3px; }
    .fc-name{ font-size:clamp(.86rem, 2.75vw, .97rem); }
    .fc-sub{ font-size:clamp(.68rem, 2.2vw, .77rem); margin-bottom:5.5px; }
    .fc-meta{ font-size:clamp(.64rem, 2.09vw, .72rem); gap:11px; }
    .fc-meta span{ gap:3.3px; }
    .fc-acts{ gap:6.6px; margin-top:10px; }
    .fc-chev{ font-size:1.2rem; }

    /* empty */
    .empty-ic{ width:62px; height:62px; font-size:1.76rem; margin-bottom:15px; }
    .es-title{ font-size:clamp(1.05rem, 3.74vw, 1.21rem); }
    .es-text{ margin-top:6.6px; max-width:374px; font-size:clamp(.73rem, 2.31vw, .84rem); }
    .es-tips{ max-width:396px; gap:7.7px; }
    .es-tip{ gap:11px; padding:11px 13px; }
    .es-tip i{ font-size:1.1rem; }
    .es-tip span{ font-size:clamp(.68rem, 2.2vw, .77rem); }

    /* principle cards */
    .pr{ gap:13px; padding:clamp(13px, 3.74vw, 16.5px) var(--pad); }
    .pr-ic{ width:35px; height:35px; border-radius:8.8px; font-size:1.08rem; }
    .pr b{ font-size:clamp(.79rem, 2.53vw, .88rem); }
    .pr span{ font-size:clamp(.68rem, 2.09vw, .75rem); margin-top:2.2px; }

    /* modal shell + wizard */
    .modal-body{ padding:6.6px var(--pad) 40px; }
    .step-count{ font-size:.73rem; padding:0 9px; }
    .wz-bar{ gap:5.5px; padding:15px var(--pad) 0; }
    .wz-bar i{ height:4.4px; }
    .wz-eyebrow{ font-size:.66rem; margin:20px 0 6.6px; }
    .wz-title{ font-size:clamp(1.1rem, 4.4vw, 1.32rem); margin-bottom:6.6px; }
    .wz-sub{ font-size:clamp(.75rem, 2.31vw, .84rem); margin-bottom:17.6px; }
    .wz-foot{ gap:8.8px; padding:13px var(--pad) calc(13px + env(safe-area-inset-bottom,0px)); }
    .wz-foot .btn.ghost{ min-width:99px; }

    .choice{ gap:13px; padding:clamp(14px, 3.96vw, 17.6px); margin-bottom:11px; }
    .choice-ic{ width:42px; height:42px; border-radius:11px; font-size:1.32rem; }
    .choice b{ font-size:clamp(.86rem, 2.75vw, .95rem); }
    .choice span{ font-size:clamp(.7rem, 2.2vw, .77rem); margin-top:3.3px; }
    .choice .tick{ top:13px; right:13px; width:22px; height:22px; font-size:.88rem; }
    .choice .why{ font-size:.68rem; margin-top:4.4px; }

    .ck{ gap:11px; padding:13px; margin-top:6.6px; }
    .ck-box{ width:22px; height:22px; font-size:.94rem; }
    .ck-t{ font-size:clamp(.73rem, 2.2vw, .79rem); }

    .gc-input, .gc-select{ padding:0 13px; font-size:.84rem; }
    .gc-select{ padding-right:37px; background-position:right 13px center; }
    input[type=time].gc-input{ padding:0 9px; }
    .search-wrap{ gap:8.8px; padding:0 13px; }
    .search-wrap i{ font-size:1.05rem; }
    .search-wrap input{ font-size:.84rem; }
    .res{ gap:13px; padding:11px var(--pad); }
    .res-i b{ font-size:clamp(.81rem, 2.64vw, .9rem); }
    .res-i span{ font-size:clamp(.66rem, 2.09vw, .75rem); }
    .sel-card{ gap:13px; padding:13px; margin-top:13px; }
    .hint{ gap:10px; padding:11px 13px; margin-top:13px; font-size:.73rem; }
    .hint i{ font-size:1.05rem; }
    .opt-wrap{ gap:7.7px; }
    .opt{ padding:0 15px; font-size:.77rem; }
    .two-col{ gap:11px; margin-top:17.6px; }
    .col-card{ padding:13px; }
    .col-card h4{ font-size:.73rem; margin-bottom:8.8px; gap:6.6px; }
    .col-card .gs-rule{ font-size:.72rem; }

    .preset-grid{ gap:8.8px; }
    .preset{ padding:13px 11px; }
    .preset i{ font-size:1.27rem; }
    .preset b{ font-size:.84rem; margin:6.6px 0 2.2px; }
    .preset span{ font-size:.66rem; }
    .preset em{ top:9px; right:9px; font-size:.55rem; padding:2.2px 6.6px; }

    .rev-top{ gap:15px; padding:17.6px 0 4.4px; }
    .rev-top > i{ font-size:1.54rem; }
    .rev-p{ max-width:121px; }
    .rev-p b{ font-size:.77rem; margin-top:6.6px; }
    .rev-p span{ font-size:.64rem; }
    .rev-list{ margin-top:15px; }
    .rev-item{ gap:12px; padding:11px var(--pad); }
    .rev-item > i{ font-size:1.1rem; width:22px; }
    .rev-item span{ font-size:.77rem; }
    .rev-item b{ font-size:.79rem; }
    .done-ic{ width:75px; height:75px; font-size:2.2rem; }
    .next-list{ margin-top:22px; }

    /* controls editor */
    .ctl-sec{ gap:7.7px; margin:20px 0 4.4px; font-size:.66rem; letter-spacing:1.65px; }
    .ctl-sec i{ font-size:.94rem; }
    .ctl{ gap:13px; padding:13px 0; }
    .ctl-i b{ font-size:clamp(.79rem, 2.53vw, .88rem); }
    .ctl-i span{ font-size:clamp(.66rem, 2.09vw, .73rem); margin-top:2.2px; }
    .ctl-sub{ gap:8.8px; padding:0 0 13px; font-size:.73rem; }
    .ctl-sub .gc-input{ width:110px; }
    .ctl .gc-select{ width:136px; }
    .sw{ width:44px; height:25px; }
    .sw span::after{ top:3.3px; left:3.3px; width:18.7px; height:18.7px; }
    .sw input:checked + span::after{ transform:translateX(18.7px); }
    .seg{ gap:3.3px; padding:3.3px; }
    .seg button{ height:calc(var(--act-h) - 2px); padding:0 10px; font-size:.66rem; }

    /* manage modal */
    .mg-head{ gap:15px; padding:clamp(18px, 4.8vw, 24px) var(--pad) 15px; }
    .mg-head h2{ font-size:clamp(1.05rem, 3.96vw, 1.23rem); }
    .mg-head p{ font-size:clamp(.66rem, 2.09vw, .75rem); margin-top:2.2px; }
    .mg-head .pill{ margin-top:6.6px; }
    .detail-tab{ padding:clamp(11px, 3.08vw, 14px) 15px; font-size:.77rem; gap:5.5px; }
    .mg-pane{ padding:6.6px var(--pad) 44px; }
    .banner{ gap:11px; padding:12px 13px; font-size:.73rem; margin-top:15px; }
    .banner i{ font-size:1.1rem; }
    .savebar{ gap:11px; padding:12px var(--pad) calc(12px + env(safe-area-inset-bottom,0px)); }
    .savebar span{ font-size:.75rem; }
    .info-row{ gap:13px; padding:13px 0; }
    .info-row > i{ width:33px; height:33px; border-radius:7.7px; font-size:1rem; }
    .info-row small{ font-size:.59rem; }
    .info-row b{ font-size:.84rem; margin-top:1.1px; }
    .danger-zone{ margin-top:24px; padding:15px; border-radius:8.8px; }
    .danger-zone h4{ font-size:.84rem; margin-bottom:4.4px; gap:6.6px; }
    .danger-zone p{ font-size:.73rem; margin-bottom:13px; }

    /* insights */
    .ins-grid{ margin-top:15px; }
    .ins{ padding:13px 11px; }
    .ins b{ font-size:clamp(1.05rem, 3.96vw, 1.265rem); }
    .ins span{ font-size:.55rem; margin-top:5.5px; }
    .card{ margin-top:17.6px; padding:15px; border-radius:8.8px; }
    .card h4{ font-size:.68rem; margin-bottom:13px; }
    .bars{ gap:6.6px; height:106px; }
    .bar{ gap:5.5px; }
    .bar em{ font-size:.57rem; }
    .bar u{ font-size:.59rem; }
    .mini{ gap:11px; padding:8.8px 0; }
    .mini b{ font-size:.79rem; }
    .mini span{ font-size:.64rem; }
    .alert{ gap:11px; padding:12px 13px; margin-top:15px; font-size:.73rem; }
    .alert i{ font-size:1.1rem; }

    /* timeline */
    .tl{ gap:13px; padding:13px 0; }
    .tl-d{ width:33px; height:33px; font-size:.94rem; }
    .tl b{ font-size:.79rem; }
    .tl span{ font-size:.68rem; }
    .tl small{ font-size:.62rem; margin-top:3.3px; }

    /* guide / faq */
    .faq-q{ gap:11px; padding:14px var(--pad); font-size:clamp(.79rem, 2.53vw, .88rem); }
    .faq-q i{ font-size:1.2rem; }
    .faq-a p{ padding:0 var(--pad) 15px; font-size:clamp(.73rem, 2.2vw, .79rem); }

    /* confirm sheet + toast */
    .sheet{ padding:6.6px var(--pad) calc(18px + env(safe-area-inset-bottom,0px)); }
    .sheet::before{ width:40px; height:4.4px; margin:9px auto 15px; }
    .sheet h3{ font-size:1.01rem; margin-bottom:6.6px; }
    .sheet p{ font-size:.77rem; margin-bottom:17.6px; }
    .sheet .row{ gap:8.8px; }
    .toast-container{ bottom:26px; }
    .toast{ gap:10px; padding:10px 20px; font-size:.81rem; }
    .toast i{ font-size:1.1rem; }

    /* skeleton */
    .sk-row{ gap:13px; padding:15px var(--pad); }
    .sk-av{ width:clamp(46px, 13.2vw, 57px); height:clamp(46px, 13.2vw, 57px); }
    .sk-lines{ gap:7.7px; }
    .sk-hero{ height:187px; }
    .sk-tile{ padding:17.6px var(--pad); gap:12px; }
}

/* centered-card sheet range (same as the About page) */
@media (min-width:600px) and (max-width:800px){
    .sheet{ max-width:440px; padding-top:22px; }
}

@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .ac-x{ width:33px; height:33px; }
    .ac-x i{ font-size:30px; }
    .ac-title{ font-size:.92rem; }
    .tb-icon-btn{ width:40px; height:40px; font-size:1.5rem; }
    .tb-add-btn{ font-size:1.76rem; }
    .chip{ font-size:.7rem; padding:0 11px; }
    .btn{ font-size:.75rem; padding:0 15px; }
    .btn.sm{ font-size:.68rem; padding:0 11px; }
}

@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .ac-title{ font-size:.88rem; }
    .tb-icon-btn{ width:36px; height:36px; }
}

@media (max-width:330px){
    .ac-title{ font-size:.81rem; }
    .tb-icon-btn{ width:33px; height:33px; font-size:1.32rem; }
    .tb-add-btn{ font-size:1.54rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette and same dz_theme switch as the About page, Community, Games, Algorithm and Upload pages.
   The red accent stays the same in both themes. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --solid:#f6f7fc;
    --surface:rgba(6,7,26,.05);
    --surface-2:rgba(6,7,26,.07);
    --line:rgba(6,7,26,.1);
    --border:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --muted-2:rgba(6,7,26,.4);
    --sub:rgba(6,7,26,.66);
    --card-bg:#ffffff;
    --pop-bg:#ffffff;
    --scrim:rgba(6,7,26,.45);
    --ph:#eceef5;
    --ok:#15a34a;
    --ok-soft:rgba(21,163,74,.12);
    --warn:#b7791f;
    --warn-soft:rgba(183,121,31,.12);
    --danger:#d93a3a;
    --danger-soft:rgba(217,58,58,.1);
    --on-accent:#ffffff;
    --accent-text:#d11f1f;
    --accent-soft:rgba(255,45,45,.1);
    --accent-border:rgba(255,45,45,.4);
    --accent-glow:rgba(255,45,45,.22);
    --scroll-thumb:rgba(42,95,216,.4);
}
[data-theme="light"] .gc-select{
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='rgba(6,7,26,0.55)' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
}
[data-theme="light"] input[type=time].gc-input{ color-scheme:light; }
[data-theme="light"] .toast{ box-shadow:0 8px 24px rgba(0,0,0,.18); }
[data-theme="light"] .fc-hero{ background:linear-gradient(135deg, rgba(255,45,45,.12), rgba(255,45,45,.04) 55%, rgba(6,7,26,.02)); }
[data-theme="light"] .fc-prog i{ background:linear-gradient(90deg, var(--accent), #ff6a6a); }
[data-theme="light"] .pill.ok{ border-color:rgba(21,163,74,.4); }
[data-theme="light"] .pill.wait{ border-color:rgba(183,121,31,.4); }
[data-theme="light"] .alert{ border-color:rgba(183,121,31,.4); }
[data-theme="light"] .danger-zone{ border-color:rgba(217,58,58,.35); }
[data-theme="light"] .sw span::after{ box-shadow:0 1px 3px rgba(0,0,0,.25); }
</style>
</head>
<body>

<div class="fc-shell">

<div class="fc-lane">

    <!-- ══ TOPBAR ══ -->
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="fcClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-title">Family Center<small>Safety</small></div>
        <div class="tb-icons">
            <button type="button" class="tb-icon-btn tb-add-btn" onclick="openWizard()" aria-label="Set up supervision" title="Set up supervision"><i class="ri-add-line"></i></button>
            <button type="button" class="tb-icon-btn" onclick="openModal('modal-guide')" aria-label="How Family Center works" title="How it works"><i class="ri-question-line"></i></button>
        </div>
    </div>

    <!-- ══ TABS ══ -->
    <div class="toolbar">
        <div class="filter-chips" id="tabChips">
            <button type="button" class="chip active" data-tab="overview"    onclick="setTab('overview')">Overview</button>
            <button type="button" class="chip" data-tab="supervising"        onclick="setTab('supervising')">Supervising <span class="bdg" id="bdgSup" hidden></span></button>
            <button type="button" class="chip" data-tab="by"                 onclick="setTab('by')">My supervisors <span class="bdg" id="bdgBy" hidden></span></button>
            <button type="button" class="chip" data-tab="requests"           onclick="setTab('requests')">Requests <span class="bdg" id="bdgReq" hidden></span></button>
            <button type="button" class="chip" data-tab="activity"           onclick="setTab('activity')">Activity</button>
        </div>
    </div>

    <!-- ══ VIEW HOST (JS renders into this; skeleton first) ══ -->
    <div id="view"></div>

</div><!-- /.fc-lane -->

<!-- ═════════ RIGHT COLUMN ═════════ -->
<aside class="fc-side" aria-label="About Family Center">
  <div class="gs-cards">

    <div class="gs-card" data-k="about">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-shield-user-line gs-bar-ic"></i><span class="gs-bar-t">About Family Center</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <p class="gs-p"><b>Family Center</b> is where parents, guardians and teens on Dezzme set up supervision together. It is built on one rule: <b>nobody is supervised without agreeing to it</b>, and either person can step out whenever they want.</p>
        <div class="gs-callout"><i class="ri-lock-2-line"></i><span>Parents see <b>summaries</b> such as time, posts and who a teen tracks. They <b>never</b> see private messages or searches.</span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="glance">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-bar-chart-2-line gs-bar-ic"></i><span class="gs-bar-t">Your Family Center at a glance</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-row"><span class="gs-row-l">Teens you supervise</span><span class="gs-row-v" id="fcsSup"><span class="sk-shimmer"></span></span></div>
        <div class="gs-row"><span class="gs-row-l">People supervising you</span><span class="gs-row-v" id="fcsBy"><span class="sk-shimmer"></span></span></div>
        <div class="gs-row"><span class="gs-row-l">Invitations waiting</span><span class="gs-row-v" id="fcsPend"><span class="sk-shimmer"></span></span></div>
        <div class="gs-row"><span class="gs-row-l">Protections switched on</span><span class="gs-row-v" id="fcsProt"><span class="sk-shimmer"></span></span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="how">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-route-line gs-bar-ic"></i><span class="gs-bar-t">How setup works</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-step"><span class="gs-sn">1</span><span class="gs-st"><b>Pick your role</b><span>Parent or guardian (18+), or a teen (13–17) inviting a parent.</span></span></div>
        <div class="gs-step"><span class="gs-sn">2</span><span class="gs-st"><b>Find the person</b><span>Search by username or Profile ID (DZ-…).</span></span></div>
        <div class="gs-step"><span class="gs-sn">3</span><span class="gs-st"><b>Say how you're related</b><span>Mother, father, guardian and so on, so everyone knows who is who.</span></span></div>
        <div class="gs-step"><span class="gs-sn">4</span><span class="gs-st"><b>Choose controls</b><span>Start from Strict, Balanced or Light, then fine-tune.</span></span></div>
        <div class="gs-step"><span class="gs-sn">5</span><span class="gs-st"><b>Review and send</b><span>The other person accepts, and supervision starts.</span></span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="see">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-eye-line gs-bar-ic"></i><span class="gs-bar-t">What a parent can and can't see</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <div class="gs-rule"><i class="ri-check-line"></i><span>Posts, likes and activity counts for the last 7 days</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Who the teen recently tracked, and new trackers</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>Pods the teen is in, with a flag for 18+ Pods</span></div>
        <div class="gs-rule"><i class="ri-check-line"></i><span>When the teen was last active</span></div>
        <div class="gs-rule no"><i class="ri-close-line"></i><span>Private messages or what's in them</span></div>
        <div class="gs-rule no"><i class="ri-close-line"></i><span>Searches, passwords or login details</span></div>
        <div class="gs-rule no"><i class="ri-close-line"></i><span>Anything changed in secret, since the teen is told about every change</span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="talk">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-chat-heart-line gs-bar-ic"></i><span class="gs-bar-t">Conversation starters</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <p class="gs-p">Supervision works best as a conversation. Try one of these:</p>
        <div class="gs-rule"><i class="ri-chat-quote-line" style="color:var(--accent-text)"></i><span>"What's the funniest thing you've seen online this week?"</span></div>
        <div class="gs-rule"><i class="ri-chat-quote-line" style="color:var(--accent-text)"></i><span>"Is there anyone you've met online who makes you uncomfortable?"</span></div>
        <div class="gs-rule"><i class="ri-chat-quote-line" style="color:var(--accent-text)"></i><span>"What limits would feel fair to you, and why?"</span></div>
        <div class="gs-rule"><i class="ri-chat-quote-line" style="color:var(--accent-text)"></i><span>"If something upsetting happens, would you tell me? What would help?"</span></div>
      </div></div></div>
    </div>

    <div class="gs-card" data-k="help">
      <button type="button" class="gs-bar" onclick="gsToggle(this)" aria-expanded="true"><i class="ri-customer-service-2-line gs-bar-ic"></i><span class="gs-bar-t">Help and safety</span><i class="ri-arrow-down-s-line gs-bar-chev"></i></button>
      <div class="gs-body"><div class="gs-inner"><div class="gs-pad">
        <button type="button" class="gs-link" onclick="openModal('modal-guide')"><i class="ri-book-open-line"></i><span>Read the full guide</span><i class="ri-arrow-right-s-line"></i></button>
        <a class="gs-link" href="help.php#family"><i class="ri-questionnaire-line"></i><span>Help Center: Family</span><i class="ri-arrow-right-s-line"></i></a>
        <a class="gs-link" href="help.php#transparency"><i class="ri-flag-line"></i><span>Report a safety concern</span><i class="ri-arrow-right-s-line"></i></a>
      </div></div></div>
    </div>

  </div>
</aside>

</div><!-- /.fc-shell -->

<!-- ══ SETUP WIZARD ══ -->
<div class="modal-overlay" id="modal-wizard">
  <div class="modal-lane">
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="closeWizard()" aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-title">Set up supervision</div>
        <span class="step-count" id="wzCount"></span>
    </div>
    <div class="wz-bar" id="wzBar"></div>
    <div class="modal-body" id="wzBody"></div>
    <div class="wz-foot" id="wzFoot"></div>
  </div>
</div>

<!-- ══ MANAGE / DETAIL ══ -->
<div class="modal-overlay" id="modal-manage">
  <div class="modal-lane">
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="closeManage()" aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-title" id="mgTitle">Supervision</div>
    </div>
    <div id="mgBody" style="flex:1"></div>
    <div class="savebar" id="mgSave">
        <span>You have unsaved changes</span>
        <button type="button" class="btn ghost sm" onclick="discardControls()">Discard</button>
        <button type="button" class="btn sm" id="mgSaveBtn" onclick="saveControls()"><i class="ri-check-line"></i> Save</button>
    </div>
  </div>
</div>

<!-- ══ GUIDE ══ -->
<div class="modal-overlay" id="modal-guide">
  <div class="modal-lane">
    <div class="ac-topbar">
        <button type="button" class="ac-x" onclick="closeModal('modal-guide')" aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <div class="ac-title">How Family Center works</div>
    </div>
    <div class="fc-hero" style="margin-bottom:6px">
        <div class="fc-hero-top"><div class="fc-hero-ic"><i class="ri-shield-user-fill"></i></div><h1><small>The full guide</small>Safer together</h1></div>
        <p>Family Center lets a parent or guardian and a teen agree on a few simple guardrails for Dezzme. It's designed to protect without spying, and to keep the teen in the loop at every step.</p>
    </div>
    <div class="sec-label">Core principles</div>
    <div class="pr"><div class="pr-ic"><i class="ri-hand-heart-line"></i></div><div><b>Consent first</b><span>Every link starts with an invitation. It only becomes active when the other person accepts.</span></div></div>
    <div class="pr"><div class="pr-ic"><i class="ri-door-open-line"></i></div><div><b>Either side can leave</b><span>A teen or a parent can end supervision at any time. The other person is told straight away.</span></div></div>
    <div class="pr"><div class="pr-ic"><i class="ri-eye-off-line"></i></div><div><b>Summaries, not surveillance</b><span>Parents see counts and accounts, never private messages or searches.</span></div></div>
    <div class="pr"><div class="pr-ic"><i class="ri-notification-3-line"></i></div><div><b>No secret changes</b><span>Whenever controls change, the teen gets a notification and the change is written to the shared Activity log.</span></div></div>
    <div class="sec-label">Questions</div>
    <div id="faqList"></div>
    <div style="height:30px"></div>
  </div>
</div>

<!-- ══ CONFIRM SHEET ══ -->
<div class="sheet-bd" id="sheetBd" onclick="if(event.target===this)sheetClose(false)">
    <div class="sheet" role="dialog" aria-modal="true">
        <h3 id="shT"></h3>
        <p id="shP"></p>
        <div class="row">
            <button type="button" class="btn ghost" onclick="sheetClose(false)">Cancel</button>
            <button type="button" class="btn" id="shOk" onclick="sheetClose(true)">Confirm</button>
        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<script>
// ── keep the theme in sync if it is switched in another tab ──
window.addEventListener('storage', function(e){
    if(e.key !== 'dz_theme') return;
    if(e.newValue === 'light') document.documentElement.setAttribute('data-theme','light');
    else document.documentElement.removeAttribute('data-theme');
});

const ME = {
    id: <?= (int)$me ?>,
    username: <?= json_encode($username, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    avatar: <?= json_encode($profile_pic, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    csrf: <?= json_encode($fam_csrf) ?>
};
const INIT_TAB  = <?= json_encode($init_tab) ?>;
const OPEN_LINK = <?= (int)$open_link ?>;

/* ═════════════════ UTILITIES ═════════════════ */
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
const sleep = ms => new Promise(r => setTimeout(r, ms));
const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function parseDate(s){ if(!s) return null; const d = new Date(String(s).replace(' ','T')); return isNaN(d) ? null : d; }
function fmtDate(s){ const d = parseDate(s); return d ? d.getDate()+' '+MONTHS[d.getMonth()]+' '+d.getFullYear() : '—'; }
function timeSince(s){
    const d = parseDate(s); if(!d) return 'a while ago';
    const t = Math.floor((Date.now() - d) / 1000);
    if (t < 60) return 'just now';
    if (t < 3600) return Math.floor(t/60) + 'm ago';
    if (t < 86400) return Math.floor(t/3600) + 'h ago';
    if (t < 604800) return Math.floor(t/86400) + 'd ago';
    return fmtDate(s);
}
function relText(r){ return ({'Mother':'mother','Father':'father','Legal guardian':'legal guardian','Grandparent':'grandparent','Other family member':'family member'})[r] || 'family member'; }
function toast(msg, type, icon){
    const c = $('toastContainer'), t = document.createElement('div');
    t.className = 'toast ' + (type || 'info');
    const i = document.createElement('i'); i.className = icon || 'ri-information-line';
    const s = document.createElement('span'); s.textContent = msg;
    t.appendChild(i); t.appendChild(s); c.appendChild(t);
    setTimeout(() => t.remove(), 3400);
}
function openModal(id){ const m = $(id); if(!m) return; m.classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeModal(id){ const m = $(id); if(!m) return; m.classList.remove('active'); if(!document.querySelector('.modal-overlay.active')) document.body.style.overflow = ''; }
function fcClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'home.php';
}
function countUp(el, n){
    if (!n || matchMedia('(prefers-reduced-motion:reduce)').matches) { el.textContent = n; return; }
    const t0 = performance.now(), dur = 650;
    (function f(t){ const p = Math.min(1, (t - t0) / dur); el.textContent = Math.round(n * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(f); })(t0);
}

/* right-column collapsible cards */
window.gsToggle = function(btn){
    const card = btn.closest('.gs-card'); if(!card) return;
    const c = card.classList.toggle('collapsed');
    btn.setAttribute('aria-expanded', c ? 'false' : 'true');
    try { localStorage.setItem('fc_gs_' + card.getAttribute('data-k'), c ? '1' : '0'); } catch(e){}
};
document.querySelectorAll('.gs-card').forEach(card => {
    try { if (localStorage.getItem('fc_gs_' + card.getAttribute('data-k')) === '1') { card.classList.add('collapsed'); const b = card.querySelector('.gs-bar'); if(b) b.setAttribute('aria-expanded','false'); } } catch(e){}
});

/* confirm sheet */
let _sheetRes = null;
function confirmSheet(o){
    $('shT').textContent = o.title; $('shP').textContent = o.text;
    const b = $('shOk'); b.textContent = o.ok || 'Confirm'; b.className = 'btn' + (o.danger ? ' danger' : '');
    $('sheetBd').classList.add('open');
    return new Promise(res => { _sheetRes = res; });
}
function sheetClose(v){ $('sheetBd').classList.remove('open'); if(_sheetRes){ const r = _sheetRes; _sheetRes = null; r(v); } }

/* API */
async function api(action, o){
    o = o || {};
    try {
        const qs = 'action=' + action + (o.qs || '');
        const opts = { method: o.method || 'GET', headers: { 'Content-Type':'application/json', 'X-CSRF-Token': ME.csrf, 'X-Fam-Qs': qs }, credentials: 'same-origin' };
        if (o.body) opts.body = JSON.stringify(o.body);
        const res = await fetch('family.php?' + qs, opts);
        const raw = await res.text();
        let data = null; try { data = JSON.parse(raw); } catch(e){}
        if (!data && /^\s*</.test(raw)) { console.error('Family Center API returned a page instead of JSON:', raw.slice(0, 600)); toast('The server sent a page instead of data. Open the browser console for details.', 'error', 'ri-error-warning-line'); return null; }
        if (!res.ok || !data || data.error) { toast((data && data.error) || 'Something went wrong. Please try again.', 'error', 'ri-error-warning-line'); return null; }
        return data;
    } catch(e) { toast('Network error. Check your connection.', 'error', 'ri-wifi-off-line'); return null; }
}

/* ═════════════════ STATE ═════════════════ */
const TABS = ['overview','supervising','by','requests','activity'];
let tab = TABS.includes(INIT_TAB) ? INIT_TAB : 'overview';
let S = null, activityCache = null;
const L = () => S ? S.links : [];
const act = r => L().filter(l => l.status === 'active' && (!r || l.role === r));
const incoming = () => L().filter(l => l.direction === 'incoming');
const outgoing = () => L().filter(l => l.direction === 'outgoing');
const findLink = id => L().find(l => l.id === id);
function insSeen(){ try { return localStorage.getItem('fc_ins_seen') === '1'; } catch(e){ return false; } }

async function loadState(quiet){
    const d = quiet ? await api('state') : (await Promise.all([api('state'), sleep(650)]))[0];
    if (d) S = d;
    else if (!S) { $('view').innerHTML = `<div class="empty"><div class="empty-ic"><i class="ri-wifi-off-line"></i></div><div class="es-title">Couldn't load Family Center</div><p class="es-text">Check your connection and try again.</p><button type="button" class="btn" onclick="retryLoad()"><i class="ri-refresh-line"></i> Try again</button></div>`; return; }
    renderSide(); renderView();
}
async function retryLoad(){ $('view').innerHTML = skFor(tab); await loadState(false); }

/* ═════════════════ SKELETONS ═════════════════ */
function skRows(n){
    let h = '';
    for (let i = 0; i < n; i++) h += '<div class="sk-row"><div class="sk-av sk-shimmer"></div><div class="sk-lines"><div class="sk-shimmer" style="width:55px;height:9px"></div><div class="sk-shimmer" style="width:60%;height:13px"></div><div class="sk-shimmer" style="width:85%;height:9px"></div><div class="sk-shimmer" style="width:40%;height:9px"></div></div></div>';
    return h;
}
function skOverview(){
    return `<div class="sk-hero sk-shimmer"></div>
    <div class="sk-pad" style="margin-top:22px"><div class="sk-shimmer" style="width:45%;height:10px;margin-bottom:10px"></div><div class="sk-shimmer" style="height:6px;border-radius:99px"></div></div>
    <div style="margin-top:14px">${[0,1,2,3,4].map(() => '<div class="sk-row" style="align-items:center"><div class="sk-shimmer" style="width:26px;height:26px;border-radius:50%;flex-shrink:0"></div><div class="sk-lines"><div class="sk-shimmer" style="width:50%;height:12px"></div><div class="sk-shimmer" style="width:80%;height:9px"></div></div></div>').join('')}</div>
    <div class="sk-tiles">${[0,1,2,3].map(() => '<div class="sk-tile"><div class="sk-shimmer" style="width:34px;height:34px;border-radius:8px;flex-shrink:0"></div><div class="sk-lines"><div class="sk-shimmer" style="width:30px;height:14px"></div><div class="sk-shimmer" style="width:70%;height:8px"></div></div></div>').join('')}</div>`;
}
const skFor = t => `<div class="view">${t === 'overview' ? skOverview() : '<div class="sk-pad" style="padding-top:20px"><div class="sk-shimmer" style="width:40%;height:10px"></div></div>' + skRows(4)}</div>`;

/* ═════════════════ RENDER: SHELL ═════════════════ */
function setTab(t){
    tab = t;
    document.querySelectorAll('#tabChips .chip').forEach(c => c.classList.toggle('active', c.dataset.tab === t));
    const b = document.querySelector('#tabChips .chip[data-tab="' + t + '"]');
    try { b.scrollIntoView({ inline:'nearest', block:'nearest' }); } catch(e){}
    renderView(); window.scrollTo({ top:0 });
}
function renderSide(){
    if (!S) return;
    const st = S.stats, set = (id, v) => { const e = $(id); if (e) e.textContent = v; };
    set('fcsSup', st.supervising); set('fcsBy', st.supervisors); set('fcsPend', st.incoming + st.outgoing);
    set('fcsProt', st.supervising ? st.protections + ' of ' + (st.supervising * 7) : '—');
    [['bdgSup', st.supervising], ['bdgBy', st.supervisors], ['bdgReq', st.incoming]].forEach(([id, n]) => { const e = $(id); if (!e) return; e.textContent = n; e.hidden = !n; });
}
function renderView(){
    const v = $('view');
    if (!S) { v.innerHTML = skFor(tab); return; }
    let html = '';
    if (tab === 'overview') html = viewOverview();
    else if (tab === 'supervising') html = viewList('parent');
    else if (tab === 'by') html = viewList('teen');
    else if (tab === 'requests') html = viewRequests();
    else if (tab === 'activity') {
        if (!activityCache) {
            v.innerHTML = skFor('activity');
            api('activity').then(d => { activityCache = d ? d.items : []; if (tab === 'activity') renderView(); });
            return;
        }
        html = viewActivity();
    }
    v.innerHTML = '<div class="view">' + html + '</div>';
    afterRender();
}
function afterRender(){
    document.querySelectorAll('#view .fc-prog i[data-w]').forEach(el => requestAnimationFrame(() => { el.style.width = el.dataset.w + '%'; }));
    document.querySelectorAll('#view .cu').forEach(el => countUp(el, +el.dataset.n));
    document.querySelectorAll('#view .fc-row').forEach((el, i) => { el.style.animationDelay = (i * 45) + 'ms'; });
}

/* ═════════════════ RENDER: ROWS ═════════════════ */
function statusPill(l){
    if (l.status === 'active') return '<span class="pill ok"><i class="ri-shield-check-fill"></i> Active</span>';
    if (l.direction === 'incoming') return '<span class="pill"><i class="ri-mail-unread-line"></i> Needs your reply</span>';
    if (l.direction === 'outgoing') return '<span class="pill wait"><i class="ri-time-line"></i> Awaiting reply</span>';
    return '<span class="pill mute">' + esc(l.status) + '</span>';
}
function linkRow(l){
    const o = l.other, parent = l.role === 'parent';
    const sub = parent ? 'Teen account' + (l.relationship ? ' · you are their ' + relText(l.relationship) : '') : 'Your ' + relText(l.relationship);
    let meta = '';
    if (l.status === 'active') {
        meta += `<span><i class="ri-calendar-check-line"></i> Since ${fmtDate(l.responded_at || l.created_at)}</span>`;
        if (parent) meta += `<span><i class="ri-shield-check-line"></i> ${l.controls_on} of 7 protections on</span>` + (l.last_active ? `<span><i class="ri-pulse-line"></i> Active ${timeSince(l.last_active)}</span>` : '');
    } else meta += `<span><i class="ri-time-line"></i> Sent ${timeSince(l.created_at)}</span>`;
    let acts = '';
    if (l.direction === 'incoming') acts = `<div class="fc-acts"><button type="button" class="btn sm" onclick="event.stopPropagation();respond(${l.id},true)"><i class="ri-check-line"></i> Accept</button><button type="button" class="btn ghost sm" onclick="event.stopPropagation();respond(${l.id},false)">Decline</button></div>`;
    else if (l.direction === 'outgoing') acts = `<div class="fc-acts"><button type="button" class="btn ghost sm" onclick="event.stopPropagation();cancelInvite(${l.id})">Cancel invitation</button></div>`;
    const clickable = l.status === 'active';
    return `<div class="fc-row ${clickable ? '' : 'static'}" ${clickable ? `onclick="openManage(${l.id})"` : ''}>
        <img class="av" src="${esc(o.pic)}" alt="" onerror="this.src='uploads/me.png'">
        <div class="fc-info">
            <div class="fc-top"><span class="fc-name">${esc(o.username)}</span>${statusPill(l)}</div>
            <div class="fc-sub">${sub}${o.nickname ? ' · @' + esc(o.nickname) : ''}</div>
            <div class="fc-meta">${meta}</div>${acts}
        </div>${clickable ? '<i class="ri-arrow-right-s-line fc-chev"></i>' : ''}
    </div>`;
}
function emptyState(role){
    const p = role === 'parent';
    return `<div class="empty"><div class="empty-ic"><i class="${p ? 'ri-parent-line' : 'ri-shield-user-line'}"></i></div>
        <div class="es-title">${p ? "You're not supervising anyone yet" : 'No one supervises your account'}</div>
        <p class="es-text">${p ? 'Invite a teen you look after. They have to accept before anything changes, and you choose the controls together.' : 'If you want a parent or guardian to help keep your account safe, you can invite them. Nothing starts until they accept, and you can leave any time.'}</p>
        <div class="es-tips">
            <div class="es-tip"><i class="ri-hand-heart-line"></i><span>Consent first. Both people must agree.</span></div>
            <div class="es-tip"><i class="ri-eye-off-line"></i><span>Messages and searches stay private.</span></div>
        </div>
        <button type="button" class="btn" onclick="openWizard('${role}')"><i class="ri-add-line"></i> ${p ? 'Invite a teen' : 'Invite a parent or guardian'}</button></div>`;
}

/* ═════════════════ RENDER: TABS ═════════════════ */
function roadmap(){
    const any = L().length > 0, active = act().length > 0, pend = L().some(l => l.status === 'pending');
    const ctlDone = active && (act('parent').length ? act('parent').some(l => l.customized) : true);
    const goCtl = act('parent').length ? "setTab('supervising')" : "setTab('by')";
    const steps = [
        { t:'Choose your role',  d:'Parent or guardian, or a teen inviting a parent.', done:any,  go:'openWizard()' },
        { t:'Find and invite',   d:'Search by username or Profile ID and say how you are related.', done:any, go:'openWizard()' },
        { t:'Wait for approval', d:'The other person has to accept. Nobody is supervised without agreeing.', done:active, pend:pend, go:"setTab('requests')" },
        { t:'Set the controls',  d:'Screen time, messaging, privacy and content, in your own words.', done:ctlDone, go:goCtl },
        { t:'Check in weekly',   d:'Look at the activity summary and talk about it together.', done:insSeen() && ctlDone, go:"setTab('supervising')" }
    ];
    const cur = steps.findIndex(s => !s.done);
    steps.forEach((s, i) => { s.cur = i === cur; });
    return steps;
}
function viewOverview(){
    const sup = act('parent'), by = act('teen'), inc = incoming(), out = outgoing();
    const steps = roadmap(), done = steps.filter(s => s.done).length, pct = Math.round(done / steps.length * 100);
    const family = act().slice(0, 3);
    let h = `<div class="fc-hero">
        <div class="fc-hero-top"><div class="fc-hero-ic"><i class="ri-shield-user-fill"></i></div><h1><small>Welcome to</small>Family Center</h1></div>
        <p>A calm, step-by-step way for parents, guardians and teens to agree on guardrails for Dezzme. Everything is opt-in: the other person always accepts first, and either of you can leave at any time.</p>
        <div class="fc-hero-acts"><button type="button" class="btn" onclick="openWizard()"><i class="ri-add-line"></i> Set up supervision</button><button type="button" class="btn ghost" onclick="openModal('modal-guide')"><i class="ri-book-open-line"></i> How it works</button></div>
    </div>`;
    if (inc.length) {
        h += `<div class="sec-label">Needs your attention <span style="color:var(--accent-text)">${inc.length}</span></div>` + inc.map(linkRow).join('');
    }
    h += `<div class="sec-label">Your setup</div>
    <div class="fc-prog-wrap"><div class="fc-prog-head"><span>Setup progress</span><b>${pct}%</b></div><div class="fc-prog"><i data-w="${pct}"></i></div></div>
    <div style="margin-top:14px">${steps.map((s, i) => `<div class="rd ${s.done ? 'done' : ''} ${s.cur ? 'cur' : ''}" onclick="${s.go}">
        <div class="rd-n">${s.done ? '<i class="ri-check-line"></i>' : i + 1}</div>
        <div class="rd-t"><b>${s.t}${s.cur ? '<span class="pill" style="margin:0">Next</span>' : ''}${s.pend && !s.done ? '<span class="pill wait" style="margin:0">Waiting</span>' : ''}</b><span>${s.d}</span></div><i class="ri-arrow-right-s-line"></i></div>`).join('')}</div>
    <div class="tiles">
        <div class="tile"><div class="tile-ic"><i class="ri-parent-line"></i></div><div><div class="tile-n cu" data-n="${sup.length}">0</div><div class="tile-l">Teens supervised</div></div></div>
        <div class="tile"><div class="tile-ic"><i class="ri-shield-user-line"></i></div><div><div class="tile-n cu" data-n="${by.length}">0</div><div class="tile-l">Your supervisors</div></div></div>
        <div class="tile"><div class="tile-ic"><i class="ri-mail-send-line"></i></div><div><div class="tile-n cu" data-n="${inc.length + out.length}">0</div><div class="tile-l">Invitations waiting</div></div></div>
        <div class="tile"><div class="tile-ic"><i class="ri-shield-check-line"></i></div><div><div class="tile-n cu" data-n="${S.stats.protections}">0</div><div class="tile-l">Protections on</div></div></div>
    </div>`;
    if (family.length) {
        h += `<div class="sec-label">Your family<button type="button" onclick="setTab('${sup.length ? 'supervising' : 'by'}')">See all</button></div>` + family.map(linkRow).join('');
    }
    h += `<div class="sec-label">What to expect</div>
    <div class="pr"><div class="pr-ic"><i class="ri-hand-heart-line"></i></div><div><b>Consent first</b><span>Every link starts as an invitation and only becomes active once accepted.</span></div></div>
    <div class="pr"><div class="pr-ic"><i class="ri-eye-off-line"></i></div><div><b>Privacy stays intact</b><span>Parents see summaries. Private messages and searches are never shown.</span></div></div>
    <div class="pr"><div class="pr-ic"><i class="ri-notification-3-line"></i></div><div><b>Teens are always told</b><span>Any change to controls sends the teen a notification and is logged in Activity.</span></div></div>`;
    return h;
}
function viewList(role){
    const list = L().filter(l => l.role === role && l.status === 'active');
    const pend = L().filter(l => l.role === role && l.status === 'pending').length;
    if (!list.length) return emptyState(role) + (pend ? `<div class="hint" style="margin:0 var(--pad)"><i class="ri-time-line"></i><span>You have <b>${pend}</b> invitation${pend > 1 ? 's' : ''} waiting. See <b>Requests</b>.</span></div>` : '');
    const p = role === 'parent';
    return `<div class="sec-label">${p ? 'Teens you supervise' : 'People who supervise you'}<span style="color:var(--muted)">${list.length}</span></div>` + list.map(linkRow).join('') +
        (pend ? `<div class="hint" style="margin:14px var(--pad) 0"><i class="ri-time-line"></i><span>${pend} more invitation${pend > 1 ? 's are' : ' is'} waiting in <b>Requests</b>.</span></div>` : '') +
        `<div style="padding:16px var(--pad) 0"><button type="button" class="btn ghost block" onclick="openWizard('${role}')"><i class="ri-add-line"></i> ${p ? 'Invite another teen' : 'Invite another supervisor'}</button></div>`;
}
function viewRequests(){
    const inc = incoming(), out = outgoing();
    if (!inc.length && !out.length) return `<div class="empty"><div class="empty-ic"><i class="ri-mail-check-line"></i></div><div class="es-title">No pending requests</div><p class="es-text">When someone invites you to Family Center, or you invite someone, it will show up here until it is answered.</p><button type="button" class="btn" onclick="openWizard()"><i class="ri-add-line"></i> Set up supervision</button></div>`;
    let h = '';
    if (inc.length) h += `<div class="sec-label">Needs your reply <span style="color:var(--accent-text)">${inc.length}</span></div>` + inc.map(linkRow).join('');
    if (out.length) h += `<div class="sec-label">Sent by you <span style="color:var(--muted)">${out.length}</span></div>` + out.map(linkRow).join('');
    return h;
}
const ACT = {
    invited:   ['ri-mail-send-line',    'Invitation sent', ''],
    accepted:  ['ri-check-double-line', 'Invitation accepted', 'ok'],
    declined:  ['ri-close-circle-line', 'Invitation declined', 'bad'],
    cancelled: ['ri-forbid-line',       'Invitation cancelled', 'bad'],
    ended:     ['ri-door-open-line',    'Supervision ended', 'bad'],
    controls:  ['ri-equalizer-line',    'Controls updated', '']
};
function timeline(items){
    return items.map(a => { const m = ACT[a.action] || ['ri-information-line', a.action, '']; return `<div class="tl"><div class="tl-d ${m[2]}"><i class="${m[0]}"></i></div><div><b>${m[1]}</b><span>${a.mine ? 'You' : esc(a.username)}${a.detail ? ' · ' + esc(a.detail) : ''}</span><small>${fmtDate(a.created_at)} · ${timeSince(a.created_at)}</small></div></div>`; }).join('');
}
function viewActivity(){
    if (!activityCache.length) return `<div class="empty"><div class="empty-ic"><i class="ri-history-line"></i></div><div class="es-title">Nothing here yet</div><p class="es-text">Invitations, approvals and every change to controls are recorded here so that both sides can see what happened and when.</p></div>`;
    return `<div class="sec-label">Shared activity log</div><div class="tl-wrap">${timeline(activityCache)}</div>`;
}

/* ═════════════════ ACTIONS ═════════════════ */
async function refreshAfter(){ activityCache = null; await loadState(true); }
async function respond(id, accept){
    const l = findLink(id); if (!l) return;
    if (accept && l.role === 'teen') {
        const ok = await confirmSheet({ title: 'Accept supervision from ' + l.other.username + '?', text: 'They will see activity summaries (posts, likes, who you track, your Pods) and can set controls such as screen time. They can never read your private messages. You will be told about every change, and you can end this any time.', ok: 'Accept' });
        if (!ok) return;
    }
    const r = await api('respond', { method:'POST', body:{ link_id:id, accept:!!accept } });
    if (r) { toast(accept ? 'Supervision is now active' : 'Invitation declined', 'success', 'ri-check-line'); await refreshAfter(); }
}
async function cancelInvite(id){
    const r = await api('cancel', { method:'POST', body:{ link_id:id } });
    if (r) { toast('Invitation cancelled', 'success', 'ri-check-line'); await refreshAfter(); }
}
async function endLink(id){
    const l = findLink(id); if (!l) return;
    const ok = await confirmSheet({ title: 'End supervision?', text: l.other.username + ' will be told straight away. Controls stop applying, and you can set things up again later with a new invitation.', ok: 'End supervision', danger: true });
    if (!ok) return;
    const r = await api('end', { method:'POST', body:{ link_id:id } });
    if (r) { M.dirty = false; setSave(false); closeModal('modal-manage'); toast('Supervision ended', 'success', 'ri-check-line'); await refreshAfter(); }
}

/* ═════════════════ CONTROLS EDITOR (shared) ═════════════════ */
const PRESETS = {
    strict:   { label:'Strict',   ic:'ri-shield-keyhole-line', d:'Tight limits, best for younger teens.', c:{ daily_limit_min:60,  quiet_enabled:1, quiet_start:'21:00', quiet_end:'07:00', msg_scope:'nobody',   lock_private:1, hide_adult:1, pod_approval:1, search_visible:0, weekly_summary:1 } },
    balanced: { label:'Balanced', ic:'ri-shield-check-line',   d:'Sensible guardrails for most teens.',   rec:true, c:{ daily_limit_min:120, quiet_enabled:1, quiet_start:'22:00', quiet_end:'07:00', msg_scope:'trackers', lock_private:1, hide_adult:1, pod_approval:1, search_visible:1, weekly_summary:1 } },
    light:    { label:'Light',    ic:'ri-shield-line',         d:'More freedom for older teens.',         c:{ daily_limit_min:0,   quiet_enabled:0, quiet_start:'22:00', quiet_end:'07:00', msg_scope:'everyone', lock_private:0, hide_adult:1, pod_approval:0, search_visible:1, weekly_summary:1 } }
};
function presetMatch(c){ for (const k in PRESETS) { const p = PRESETS[k].c; if (Object.keys(p).every(x => String(p[x]) === String(c[x]))) return k; } return 'custom'; }
const LIM = [[0,'No limit'],[15,'15 min'],[30,'30 min'],[45,'45 min'],[60,'1 hour'],[90,'1.5 hours'],[120,'2 hours'],[180,'3 hours'],[240,'4 hours']];
const CTL_SECTIONS = [
    { t:'Time and rest', ic:'ri-hourglass-line', items:[
        { k:'daily_limit_min', t:'Daily time limit', d:'How long Dezzme can be used each day.', type:'select' },
        { k:'quiet_enabled',   t:'Quiet hours', d:'Pause Dezzme overnight so sleep comes first.', type:'quiet' } ] },
    { t:'Messages and privacy', ic:'ri-lock-line', items:[
        { k:'msg_scope',      t:'Who can message', d:'Controls who is allowed to start a conversation.', type:'seg', opts:[['everyone','Everyone'],['trackers','People they track'],['nobody','No one']] },
        { k:'lock_private',   t:'Keep account private', d:'Only approved trackers can see their posts.', type:'toggle' },
        { k:'search_visible', t:'Show in search and suggestions', d:'Turn off so strangers find them less easily.', type:'toggle' } ] },
    { t:'Content and community', ic:'ri-apps-line', items:[
        { k:'hide_adult',   t:'Hide 18+ content', d:'Keeps adult memes and 18+ Pods out of their feed.', type:'toggle' },
        { k:'pod_approval', t:'Approve new Pods', d:'A parent has to okay a Pod before the teen joins.', type:'toggle' } ] },
    { t:'Updates', ic:'ri-mail-line', items:[
        { k:'weekly_summary', t:'Weekly summary', d:'A short activity summary for the supervisor each week.', type:'toggle' } ] }
];
function mountControls(el, vals, o){
    o = o || {}; const v = Object.assign({}, vals), ro = !!o.ro, dis = ro ? 'disabled' : '';
    let h = '';
    CTL_SECTIONS.forEach(sec => {
        h += `<div class="ctl-sec"><i class="${sec.ic}"></i>${sec.t}</div>`;
        sec.items.forEach(it => {
            const head = `<div class="ctl-i"><b>${it.t}</b><span>${it.d}</span></div>`;
            if (it.type === 'toggle') h += `<div class="ctl">${head}<label class="sw"><input type="checkbox" data-k="${it.k}" ${v[it.k] ? 'checked' : ''} ${dis}><span></span></label></div>`;
            else if (it.type === 'select') h += `<div class="ctl">${head}<select class="gc-select" data-k="${it.k}" ${dis}>${LIM.map(x => `<option value="${x[0]}" ${+v[it.k] === x[0] ? 'selected' : ''}>${x[1]}</option>`).join('')}</select></div>`;
            else if (it.type === 'seg') h += `<div class="ctl">${head}<div class="seg ${ro ? 'ro' : ''}" data-seg="${it.k}">${it.opts.map(x => `<button type="button" data-v="${x[0]}" class="${v[it.k] === x[0] ? 'sel' : ''}" ${dis}>${x[1]}</button>`).join('')}</div></div>`;
            else if (it.type === 'quiet') h += `<div class="ctl-row-wrap"><div class="ctl">${head}<label class="sw"><input type="checkbox" data-k="quiet_enabled" ${v.quiet_enabled ? 'checked' : ''} ${dis}><span></span></label></div><div class="ctl-sub" ${v.quiet_enabled ? '' : 'hidden'}>From <input type="time" class="gc-input" data-k="quiet_start" value="${esc(v.quiet_start)}" ${dis}> to <input type="time" class="gc-input" data-k="quiet_end" value="${esc(v.quiet_end)}" ${dis}></div></div>`;
        });
    });
    el.innerHTML = h;
    if (ro) return;
    const emit = () => { if (o.onChange) o.onChange(Object.assign({}, v)); };
    el.onchange = e => {
        const t = e.target, k = t.dataset && t.dataset.k; if (!k) return;
        if (t.type === 'time' && !t.value) { t.value = v[k]; return; }
        if (t.type === 'checkbox') v[k] = t.checked ? 1 : 0; else if (t.tagName === 'SELECT') v[k] = +t.value; else v[k] = t.value;
        if (k === 'quiet_enabled') { const sub = el.querySelector('.ctl-sub'); if (sub) sub.hidden = !v.quiet_enabled; }
        emit();
    };
    el.onclick = e => {
        const b = e.target.closest('.seg button'); if (!b || b.disabled) return;
        const seg = b.parentNode; v[seg.dataset.seg] = b.dataset.v;
        seg.querySelectorAll('button').forEach(x => x.classList.toggle('sel', x === b)); emit();
    };
}
function ctlSummary(c){
    const lim = (LIM.find(x => x[0] === +c.daily_limit_min) || LIM[0])[1];
    const msg = ({ everyone:'Everyone', trackers:'People they track', nobody:'No one' })[c.msg_scope];
    return [
        ['ri-timer-line', 'Daily limit', lim],
        ['ri-moon-clear-line', 'Quiet hours', c.quiet_enabled ? c.quiet_start + ' to ' + c.quiet_end : 'Off'],
        ['ri-chat-private-line', 'Who can message', msg],
        ['ri-lock-line', 'Private account', c.lock_private ? 'On' : 'Off'],
        ['ri-search-line', 'Search and suggestions', c.search_visible ? 'Visible' : 'Hidden'],
        ['ri-eye-off-line', '18+ content', c.hide_adult ? 'Hidden' : 'Allowed'],
        ['ri-group-line', 'Pod approval', c.pod_approval ? 'Required' : 'Not required'],
        ['ri-mail-line', 'Weekly summary', c.weekly_summary ? 'On' : 'Off']
    ];
}

/* ═════════════════ SETUP WIZARD ═════════════════ */
const W = {};
const WT = ['Your role','Find the person','Relationship','Controls','Review'];
let _searchTok = 0, _searchTimer = null;
function openWizard(role){
    if (!S) { toast('Still loading. One moment.', 'info', 'ri-time-line'); return; }
    Object.assign(W, { step:1, role:role || null, ageOk:false, other:null, rel:'', preset:'balanced', ctl:Object.assign({}, PRESETS.balanced.c), q:'', results:null, busy:false, done:false, agree:false });
    renderWizard(); openModal('modal-wizard'); $('modal-wizard').scrollTop = 0;
}
function closeWizard(){ closeModal('modal-wizard'); }
function wName(){ return W.other ? esc(W.other.username) : 'them'; }
function wValid(){
    if (W.step === 1) return !!W.role && (S.me.age_known || W.ageOk);
    if (W.step === 2) return !!W.other;
    if (W.step === 3) return !!W.rel;
    if (W.step === 5) return W.agree && !W.busy;
    return true;
}
function updateWizNext(){ const b = $('wzNext'); if (!b) return; b.classList.toggle('disabled', !wValid()); }
function wBack(){ if (W.step > 1) { W.step--; renderWizard(); $('modal-wizard').scrollTop = 0; } }
function wNext(){
    if (!wValid()) return;
    if (W.step === 5) { wSend(); return; }
    W.step++; renderWizard(); $('modal-wizard').scrollTop = 0;
}
function renderWizard(){
    const n = W.step;
    $('wzBar').innerHTML = W.done ? '' : WT.map((_, i) => `<i class="${i < n - 1 ? 'on' : ''} ${i === n - 1 ? 'cur' : ''}"></i>`).join('');
    $('wzCount').innerHTML = W.done ? '' : `<b>${n}</b> of 5`;
    const body = $('wzBody'), foot = $('wzFoot');
    if (W.done) {
        body.innerHTML = '<div class="wz-pane">' + wDone() + '</div>';
        foot.innerHTML = `<button type="button" class="btn ghost" onclick="closeWizard();setTab('requests')">View requests</button><button type="button" class="btn" onclick="closeWizard()">Done</button>`;
        return;
    }
    body.innerHTML = '<div class="wz-pane">' + [wRole, wFind, wRel, wCtl, wReview][n - 1]() + '</div>';
    if (n === 2) bindFind();
    if (n === 4) mountWizCtl();
    foot.innerHTML = (n > 1 ? '<button type="button" class="btn ghost" onclick="wBack()"><i class="ri-arrow-left-line"></i> Back</button>' : '') +
        `<button type="button" class="btn" id="wzNext" onclick="wNext()">${n === 5 ? '<i class="ri-send-plane-fill"></i> Send invitation' : 'Continue <i class="ri-arrow-right-line"></i>'}</button>`;
    updateWizNext();
}
/* step 1 */
function wRole(){
    const cp = S.me.can_parent, ct = S.me.can_teen;
    let ageBlock = '';
    if (W.role && !S.me.age_known) {
        ageBlock = `<label class="ck"><input type="checkbox" onchange="wAge(this.checked)" ${W.ageOk ? 'checked' : ''}><span class="ck-box"><i class="ri-check-line"></i></span><span class="ck-t">${W.role === 'parent' ? 'I confirm I am <b>18 or older</b> and I am a parent or legal guardian.' : 'I confirm I am <b>under 18</b> and I want to invite a parent or guardian.'}</span></label>`;
    }
    return `<div class="wz-eyebrow">Step 1</div><div class="wz-title">Who are you in this family?</div>
    <div class="wz-sub">Family Center looks different for parents and for teens. Pick the one that fits you.</div>
    <button type="button" class="choice ${W.role === 'parent' ? 'sel' : ''} ${cp ? '' : 'off'}" onclick="wSetRole('parent')"><div class="choice-ic"><i class="ri-parent-line"></i></div><div><b>I'm a parent or guardian</b><span>Supervise a teen under 18. You choose the controls and see activity summaries.</span>${cp ? '' : '<div class="why">Your profile age is under 18.</div>'}</div><div class="tick"><i class="ri-check-line"></i></div></button>
    <button type="button" class="choice ${W.role === 'teen' ? 'sel' : ''} ${ct ? '' : 'off'}" onclick="wSetRole('teen')"><div class="choice-ic"><i class="ri-user-heart-line"></i></div><div><b>I'm a teen (13 to 17)</b><span>Invite a parent or guardian to help keep your account safe. You stay in the loop.</span>${ct ? '' : '<div class="why">Your profile age is 18 or older.</div>'}</div><div class="tick"><i class="ri-check-line"></i></div></button>
    ${ageBlock}`;
}
function wSetRole(r){ W.role = r; W.ageOk = false; W.other = null; W.results = null; W.q = ''; renderWizard(); }
function wAge(v){ W.ageOk = v; updateWizNext(); }
/* step 2 */
function selCard(){
    if (!W.other) return '';
    const o = W.other;
    return `<div class="sel-card"><img class="av sm" src="${esc(o.pic)}" alt="" onerror="this.src='uploads/me.png'"><div class="res-i"><b>${esc(o.username)}</b><span>${o.dz ? esc(o.dz) : ''}${o.nickname ? ' · @' + esc(o.nickname) : ''}</span></div><button type="button" class="btn ghost sm" onclick="wChange()">Change</button></div>`;
}
function wFind(){
    const p = W.role === 'parent';
    return `<div class="wz-eyebrow">Step 2</div><div class="wz-title">${p ? 'Which teen do you want to supervise?' : 'Who should supervise you?'}</div>
    <div class="wz-sub">${p ? 'Search by username, nickname or Profile ID. They have to accept your invitation before anything changes.' : 'Search for your parent or guardian by username or Profile ID. They have to accept before anything changes.'}</div>
    <div id="wSel">${selCard()}</div>
    <div id="wSearch" ${W.other ? 'hidden' : ''}><div class="search-wrap"><i class="ri-search-line"></i><input id="wQ" type="text" placeholder="Username or DZ- Profile ID" autocomplete="off" spellcheck="false" value="${esc(W.q)}"></div><div class="res-list" id="wRes"></div></div>
    <div class="hint"><i class="ri-information-line"></i><span>${p ? 'Teen accounts must be <b>under 18</b>. People already linked show as unavailable.' : 'Supervisors must be <b>18 or older</b>. A teen can have up to <b>2</b> supervisors.'}</span></div>`;
}
function bindFind(){
    const q = $('wQ'); if (!q) return;
    q.addEventListener('input', () => { W.q = q.value; clearTimeout(_searchTimer); _searchTimer = setTimeout(() => wSearch(W.q.trim()), 300); });
    if (W.q.trim().length >= 2) wSearch(W.q.trim()); else wShowRes();
    if (!W.other) setTimeout(() => q.focus(), 120);
}
function wShowRes(){
    const el = $('wRes'); if (!el) return;
    if (W.results === null) { el.innerHTML = '<div class="empty-tab" style="padding:18px var(--pad);font-size:.7rem;color:var(--muted)">Type at least 2 characters to search.</div>'; return; }
    if (W.results === 'loading') { el.innerHTML = skRows(3); return; }
    if (!W.results.length) { el.innerHTML = '<div style="padding:22px var(--pad);font-size:.72rem;color:var(--muted);text-align:center">No accounts found.</div>'; return; }
    el.innerHTML = W.results.map((u, i) => `<div class="res ${u.ok ? '' : 'off'}" onclick="wPick(${i})"><img class="av sm" src="${esc(u.pic)}" alt="" onerror="this.src='uploads/me.png'"><div class="res-i"><b>${esc(u.username)}</b><span>${u.dz ? esc(u.dz) : ''}${u.nickname ? ' · @' + esc(u.nickname) : ''}</span></div>${u.ok ? '<i class="ri-arrow-right-s-line" style="color:var(--muted)"></i>' : `<span class="pill mute">${esc(u.why)}</span>`}</div>`).join('');
}
async function wSearch(q){
    if (q.length < 2) { W.results = null; wShowRes(); return; }
    const tok = ++_searchTok; W.results = 'loading'; wShowRes();
    const d = await api('search_users', { qs:'&q=' + encodeURIComponent(q) + '&role=' + W.role });
    if (tok !== _searchTok) return;
    if (d && d.dbg) { console.error('Family Center search SQL error:', d.dbg); toast('Search problem: ' + d.dbg, 'error', 'ri-error-warning-line'); }
    W.results = d ? d.results : []; wShowRes();
}
function wPick(i){ const u = W.results && W.results[i]; if (!u) return; if (!u.ok) { toast(u.why, 'info', 'ri-information-line'); return; } W.other = u; renderWizard(); }
function wChange(){ W.other = null; renderWizard(); }
/* step 3 */
function wRel(){
    const p = W.role === 'parent';
    return `<div class="wz-eyebrow">Step 3</div><div class="wz-title">${p ? 'How are you related to ' + wName() + '?' : 'How is ' + wName() + ' related to you?'}</div>
    <div class="wz-sub">This appears on both sides of the link so everyone knows who is who.</div>
    <div class="opt-wrap">${S.rels.map((r, i) => `<button type="button" class="opt ${W.rel === r ? 'sel' : ''}" onclick="wSetRel(${i})">${esc(r)}</button>`).join('')}</div>
    <div class="two-col">
        <div class="col-card"><h4 class="y"><i class="ri-eye-line"></i> A parent can see</h4>
            <div class="gs-rule"><i class="ri-check-line"></i><span>Posts and likes this week</span></div>
            <div class="gs-rule"><i class="ri-check-line"></i><span>Who the teen tracks</span></div>
            <div class="gs-rule"><i class="ri-check-line"></i><span>Pods they've joined</span></div>
            <div class="gs-rule"><i class="ri-check-line"></i><span>Last active time</span></div></div>
        <div class="col-card"><h4 class="n"><i class="ri-eye-off-line"></i> Never visible</h4>
            <div class="gs-rule no"><i class="ri-close-line"></i><span>Private messages</span></div>
            <div class="gs-rule no"><i class="ri-close-line"></i><span>Searches</span></div>
            <div class="gs-rule no"><i class="ri-close-line"></i><span>Passwords</span></div>
            <div class="gs-rule no"><i class="ri-close-line"></i><span>Secret changes</span></div></div>
    </div>`;
}
function wSetRel(i){ W.rel = S.rels[i]; renderWizard(); }
/* step 4 */
function wCtl(){
    if (W.role === 'teen') return `<div class="wz-eyebrow">Step 4</div><div class="wz-title">Your parent sets the controls</div>
        <div class="wz-sub">After ${wName()} accepts, they can choose controls such as screen time and messaging. You'll see every setting on your side, and you'll be told whenever one changes. These are the starting defaults:</div><div id="wCtl"></div>`;
    return `<div class="wz-eyebrow">Step 4</div><div class="wz-title">Choose the controls</div>
        <div class="wz-sub">Start from a preset and fine-tune anything. ${wName()} will see exactly what you chose before they accept, and you can change it later.</div>
        <div class="preset-grid">${Object.keys(PRESETS).map(k => { const p = PRESETS[k]; return `<button type="button" class="preset ${W.preset === k ? 'sel' : ''}" data-p="${k}" onclick="wPreset('${k}')">${p.rec ? '<em>Recommended</em>' : ''}<i class="${p.ic}"></i><b>${p.label}</b><span>${p.d}</span></button>`; }).join('')}</div>
        <div id="wCtl"></div>`;
}
function mountWizCtl(){
    const el = $('wCtl'); if (!el) return;
    if (W.role === 'teen') { mountControls(el, PRESETS.balanced.c, { ro:true }); return; }
    mountControls(el, W.ctl, { onChange: v => { W.ctl = v; W.preset = presetMatch(v); document.querySelectorAll('.preset').forEach(b => b.classList.toggle('sel', b.dataset.p === W.preset)); } });
}
function wPreset(k){ W.preset = k; W.ctl = Object.assign({}, PRESETS[k].c); document.querySelectorAll('.preset').forEach(b => b.classList.toggle('sel', b.dataset.p === k)); mountWizCtl(); }
/* step 5 */
function wReview(){
    const p = W.role === 'parent', o = W.other;
    const me = `<div class="rev-p"><img class="av lg" style="margin:0 auto" src="${esc(ME.avatar)}" alt="" onerror="this.src='uploads/me.png'"><b>You</b><span>${p ? 'Parent' : 'Teen'}</span></div>`;
    const them = `<div class="rev-p"><img class="av lg" style="margin:0 auto" src="${esc(o.pic)}" alt="" onerror="this.src='uploads/me.png'"><b>${esc(o.username)}</b><span>${p ? 'Teen' : 'Parent'}</span></div>`;
    const rows = [['ri-heart-line', 'Relationship', (p ? 'You are their ' : 'They are your ') + relText(W.rel)], ['ri-fingerprint-line', 'Profile ID', o.dz || '—']];
    if (p) ctlSummary(W.ctl).forEach(r => rows.push(r));
    return `<div class="wz-eyebrow">Step 5</div><div class="wz-title">Review and send</div>
    <div class="wz-sub">${p ? 'This is what ' + wName() + ' will see before they decide.' : wName() + ' will get your request and decide whether to accept.'}</div>
    <div class="rev-top">${p ? me : them}<i class="ri-arrow-right-line"></i>${p ? them : me}</div>
    <div class="rev-list">${rows.map(r => `<div class="rev-item"><i class="${r[0]}"></i><span>${r[1]}</span><b>${esc(r[2])}</b></div>`).join('')}</div>
    <label class="ck" style="margin-top:16px"><input type="checkbox" id="wAgree" onchange="wAgreeSet(this.checked)" ${W.agree ? 'checked' : ''}><span class="ck-box"><i class="ri-check-line"></i></span><span class="ck-t">I understand that <b>${wName()}</b> must accept before anything starts, that <b>either of us can end supervision at any time</b>, and that every change is visible to both of us.</span></label>`;
}
function wAgreeSet(v){ W.agree = v; updateWizNext(); }
async function wSend(){
    if (W.busy || !wValid()) return;
    W.busy = true; updateWizNext();
    const r = await api('invite', { method:'POST', body:{ role:W.role, other_id:W.other.id, relationship:W.rel, age_confirmed:W.ageOk ? 1 : 0, controls:W.role === 'parent' ? W.ctl : null } });
    W.busy = false;
    if (r) { W.done = true; activityCache = null; await loadState(true); renderWizard(); $('modal-wizard').scrollTop = 0; }
    else updateWizNext();
}
function wDone(){
    const p = W.role === 'parent';
    return `<div class="done-wrap"><div class="done-ic"><i class="ri-send-plane-fill"></i></div><div class="wz-title">Invitation sent</div>
    <div class="wz-sub" style="max-width:380px;margin:0 auto">${wName()} has been notified. Nothing changes until they accept.</div></div>
    <div class="sec-label" style="padding-left:0">What happens next</div>
    <div class="next-list">
        <div class="rd done"><div class="rd-n"><i class="ri-check-line"></i></div><div class="rd-t"><b>Invitation sent</b><span>${wName()} got a notification.</span></div></div>
        <div class="rd cur"><div class="rd-n">2</div><div class="rd-t"><b>${wName()} reviews it</b><span>They can accept or decline. You can cancel it from <b>Requests</b> while it waits.</span></div></div>
        <div class="rd"><div class="rd-n">3</div><div class="rd-t"><b>${p ? 'Supervision starts' : 'Your parent sets the controls'}</b><span>${p ? 'Your controls apply and the activity summary becomes available.' : 'You will see every control and get notified when it changes.'}</span></div></div>
    </div>`;
}

/* ═════════════════ MANAGE ═════════════════ */
const M = { id:null, tab:'controls', vals:null, orig:null, dirty:false, ins:null, act:null };
function setSave(on){ $('mgSave').classList.toggle('show', !!on); }
function openManage(id){
    const l = findLink(id); if (!l || l.status !== 'active') return;
    Object.assign(M, { id, tab:'controls', vals:Object.assign({}, l.controls), orig:Object.assign({}, l.controls), dirty:false, ins:null, act:null });
    renderManage(); openModal('modal-manage'); $('modal-manage').scrollTop = 0; setSave(false);
}
async function closeManage(){
    if (M.dirty) { const ok = await confirmSheet({ title:'Discard unsaved changes?', text:'Your changes to the controls have not been saved yet.', ok:'Discard', danger:true }); if (!ok) return; M.dirty = false; setSave(false); }
    closeModal('modal-manage');
}
function renderManage(){
    const l = findLink(M.id); if (!l) return;
    const parent = l.role === 'parent', o = l.other;
    $('mgTitle').textContent = parent ? 'Manage supervision' : 'Your supervision';
    const tabs = [['controls','ri-equalizer-line','Controls']].concat(parent ? [['insights','ri-line-chart-line','Insights']] : [], [['activity','ri-history-line','Activity'], ['about','ri-information-line','About']]);
    $('mgBody').innerHTML = `<div class="mg-head"><img class="av lg" src="${esc(o.pic)}" alt="" onerror="this.src='uploads/me.png'"><div><h2>${esc(o.username)}</h2><p>${parent ? 'Teen account · you are their ' + relText(l.relationship) : 'Your ' + relText(l.relationship)}</p>${statusPill(l)}</div></div>
    <div class="detail-tabs">${tabs.map(t => `<button type="button" class="detail-tab ${M.tab === t[0] ? 'active' : ''}" onclick="setMgTab('${t[0]}')"><i class="${t[1]}"></i> ${t[2]}</button>`).join('')}</div><div id="mgPane"></div>`;
    renderMgPane();
}
function setMgTab(t){
    M.tab = t;
    document.querySelectorAll('#mgBody .detail-tab').forEach(b => b.classList.toggle('active', b.getAttribute('onclick').indexOf("'" + t + "'") > -1));
    renderMgPane();
}
function renderMgPane(){
    const l = findLink(M.id); if (!l) return; const pane = $('mgPane'), parent = l.role === 'parent';
    if (M.tab === 'controls') {
        pane.innerHTML = `<div class="mg-pane">${parent
            ? `<div class="banner"><i class="ri-notification-3-line"></i><span>Changes apply right away and <b>${esc(l.other.username)}</b> is told what changed.</span></div>`
            : `<div class="banner info"><i class="ri-lock-2-line"></i><span>These controls are set by your ${relText(l.relationship)}. If you'd like something changed, talk to them. You'll be notified whenever they update a setting.</span></div>`}
            <div id="mgCtl"></div></div>`;
        mountControls($('mgCtl'), M.vals, { ro:!parent, onChange: v => { M.vals = v; M.dirty = JSON.stringify(v) !== JSON.stringify(M.orig); setSave(M.dirty); } });
    } else if (M.tab === 'insights') {
        if (M.ins) { pane.innerHTML = insightsHTML(M.ins); return; }
        pane.innerHTML = '<div class="mg-pane"><div class="sk-shimmer" style="height:150px;margin-top:14px"></div><div class="sk-shimmer" style="height:150px;margin-top:16px"></div></div>';
        api('insights', { qs:'&link_id=' + M.id }).then(d => { if (!d) return; M.ins = d; try { localStorage.setItem('fc_ins_seen', '1'); } catch(e){} if (M.tab === 'insights') $('mgPane').innerHTML = insightsHTML(d); });
    } else if (M.tab === 'activity') {
        if (M.act) { pane.innerHTML = actHTML(M.act); return; }
        pane.innerHTML = '<div class="mg-pane">' + skRows(3) + '</div>';
        api('activity', { qs:'&link_id=' + M.id }).then(d => { if (!d) return; M.act = d.items; if (M.tab === 'activity') $('mgPane').innerHTML = actHTML(d.items); });
    } else {
        pane.innerHTML = aboutHTML(l);
    }
}
function actHTML(items){ return `<div class="mg-pane">${items.length ? timeline(items) : '<div style="padding:30px 0;text-align:center;font-size:.74rem;color:var(--muted)">No activity yet.</div>'}</div>`; }
function aboutHTML(l){
    const parent = l.role === 'parent', o = l.other;
    return `<div class="mg-pane">
        <div class="info-row"><i class="ri-heart-line"></i><div><small>Relationship</small><b>${parent ? 'You are their ' : 'They are your '}${relText(l.relationship)}</b></div></div>
        <div class="info-row"><i class="ri-calendar-check-line"></i><div><small>Supervision started</small><b>${fmtDate(l.responded_at || l.created_at)}</b></div></div>
        <div class="info-row"><i class="ri-mail-send-line"></i><div><small>Started by</small><b>${(l.initiated_by === l.role) ? 'You' : esc(o.username)} (${l.initiated_by === 'parent' ? 'parent' : 'teen'})</b></div></div>
        <div class="info-row"><i class="ri-fingerprint-line"></i><div><small>Their Profile ID</small><b>${esc(o.dz || '—')}</b></div></div>
        <div class="banner info"><i class="ri-information-line"></i><span>${parent ? 'You can see activity <b>summaries</b> only. Private messages, searches and passwords are never shown.' : 'Your supervisor sees activity <b>summaries</b> only: posts, likes, who you track and your Pods. They can never read your messages.'}</span></div>
        <div class="danger-zone"><h4><i class="ri-door-open-line"></i> End supervision</h4><p>${parent ? 'The teen is told straight away and your controls stop applying.' : 'Your supervisor is told straight away and their controls stop applying.'} You can set things up again later with a new invitation.</p><button type="button" class="btn danger sm" onclick="endLink(${l.id})">End supervision</button></div>
        <p style="margin-top:16px;font-size:.64rem;color:var(--muted);line-height:1.7">Something worrying going on? <a href="help.php#transparency" style="color:var(--accent-text);text-decoration:underline">Report a safety concern</a>.</p>
    </div>`;
}
function insightsHTML(d){
    const max = Math.max(1, ...d.series.map(x => x.c)), dn = ['Su','Mo','Tu','We','Th','Fr','Sa'];
    const mini = (arr, empty) => arr.length ? arr.map(u => `<div class="mini"><img class="av xs" src="${esc(u.profile_pic)}" alt="" onerror="this.src='uploads/me.png'"><div><b>${esc(u.username)}</b><span>${u.nickname ? '@' + esc(u.nickname) + ' · ' : ''}${timeSince(u.created_at)}</span></div></div>`).join('') : `<div style="font-size:.68rem;color:var(--muted);padding:6px 0">${empty}</div>`;
    return `<div class="mg-pane">
        ${d.adult_pods > 0 ? `<div class="alert"><i class="ri-alert-line"></i><span>They are in <b>${d.adult_pods}</b> Pod${d.adult_pods > 1 ? 's' : ''} marked 18+. Consider talking about it, or turn on <b>Approve new Pods</b>.</span></div>` : ''}
        <div class="ins-grid">
            <div class="ins"><b>${d.posts7}</b><span>Posts this week</span></div>
            <div class="ins"><b>${d.likes7}</b><span>Likes given</span></div>
            <div class="ins"><b>${d.tracked7}</b><span>New accounts tracked</span></div>
            <div class="ins"><b>${d.trackers7}</b><span>New trackers</span></div>
            <div class="ins"><b>${d.pods_n}</b><span>Pods joined</span></div>
            <div class="ins"><b style="font-size:.78rem;line-height:1.3">${d.last_active ? timeSince(d.last_active) : '—'}</b><span>Last active</span></div>
        </div>
        <div class="card"><h4>Posts, last 7 days</h4><div class="bars">${d.series.map((x, i) => `<div class="bar"><u>${x.c}</u><i class="${x.c ? '' : 'z'}" style="height:${x.c ? Math.max(8, Math.round(x.c / max * 100)) : 4}%;animation-delay:${i * 60}ms"></i><em>${dn[new Date(x.d + 'T00:00:00').getDay()]}</em></div>`).join('')}</div></div>
        <div class="card"><h4>Recently tracked <span style="text-transform:none;letter-spacing:0;color:var(--muted-2)">· ${d.tracking} total</span></h4>${mini(d.recent_tracked, 'They have not tracked anyone yet.')}</div>
        <div class="card"><h4>New trackers <span style="text-transform:none;letter-spacing:0;color:var(--muted-2)">· ${d.trackers} total</span></h4>${mini(d.recent_trackers, 'No trackers yet.')}</div>
        <div class="card"><h4>Pods they're in</h4>${d.pods.length ? d.pods.map(p => `<div class="mini"><img class="av xs" src="${esc(p.group_pic ? (p.group_pic.indexOf('/') > -1 ? p.group_pic : 'uploads/' + p.group_pic) : 'uploads/default_group.png')}" alt="" onerror="this.src='uploads/me.png'"><div><b>${esc(p.title)}</b><span>${p.privacy === 'private' ? 'Private Pod' : 'Public Pod'}</span></div>${+p.is_adult ? '<span class="pill">18+</span>' : ''}</div>`).join('') : '<div style="font-size:.68rem;color:var(--muted);padding:6px 0">Not in any Pods.</div>'}</div>
        <div class="banner info"><i class="ri-eye-off-line"></i><span>Summaries only. Private messages, searches and passwords are never shown.</span></div>
    </div>`;
}
async function saveControls(){
    const l = findLink(M.id); if (!l || !M.dirty) return; const btn = $('mgSaveBtn'); btn.classList.add('disabled');
    const r = await api('save_controls', { method:'POST', body:{ link_id:M.id, controls:M.vals } });
    btn.classList.remove('disabled');
    if (!r) return;
    l.controls = r.controls; l.controls_on = r.controls_on; if (r.changed) l.customized = 1;
    M.orig = Object.assign({}, r.controls); M.vals = Object.assign({}, r.controls); M.dirty = false; M.act = null; activityCache = null;
    setSave(false); toast(r.changed ? 'Controls saved. ' + l.other.username + ' was notified.' : 'Nothing changed', 'success', 'ri-check-line');
    renderMgPane(); renderView(); renderSide();
}
function discardControls(){ M.vals = Object.assign({}, M.orig); M.dirty = false; setSave(false); renderMgPane(); }

/* ═════════════════ GUIDE FAQ ═════════════════ */
const FAQ = [
    ['Who can use Family Center?', 'Parents and legal guardians aged 18 or older can supervise, and teens under 18 can be supervised. If your profile has a date of birth we check it. If not, we ask you to confirm your age.'],
    ['Does the teen have to agree?', 'Yes. Every link starts as an invitation, and nothing is active until the other person accepts. A teen can also be the one to start the invitation.'],
    ['What can a parent see?', 'Summaries only: posts and likes from the last 7 days, who the teen recently tracked and who recently tracked them, which Pods they are in (with a flag for 18+ Pods), and when they were last active.'],
    ['Can a parent read messages?', 'No. Family Center never shows private messages, what is said in them, searches or passwords.'],
    ['Can there be more than one supervisor?', 'Yes, up to two per teen account, for example both parents or a parent and a guardian.'],
    ['How do I end supervision?', 'Open the link from My supervisors or Supervising, go to About and choose End supervision. The other person is told immediately. Either side can do this at any time.'],
    ['What if someone declines?', 'The invitation closes and the sender is told. To avoid pressure, the same invitation cannot be re-sent for 7 days.'],
    ['How are changes handled?', 'Whenever a parent changes a control, the teen gets a notification and the change is written to the shared Activity log. Nothing is changed in secret.']
];
function renderFaq(){
    $('faqList').innerHTML = FAQ.map((f, i) => `<div class="faq" id="faq${i}"><button type="button" class="faq-q" onclick="document.getElementById('faq${i}').classList.toggle('open')"><span>${f[0]}</span><i class="ri-arrow-down-s-line"></i></button><div class="faq-a"><div><p>${f[1]}</p></div></div></div>`).join('');
}

/* ═════════════════ KEYS + INIT ═════════════════ */
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if ($('sheetBd').classList.contains('open')) { sheetClose(false); return; }
    if ($('modal-manage').classList.contains('active')) { closeManage(); return; }
    if ($('modal-wizard').classList.contains('active')) { closeWizard(); return; }
    if ($('modal-guide').classList.contains('active')) { closeModal('modal-guide'); }
});
document.addEventListener('visibilitychange', () => { if (!document.hidden && S && !document.querySelector('.modal-overlay.active')) loadState(true); });

(async function init(){
    document.querySelectorAll('#tabChips .chip').forEach(c => c.classList.toggle('active', c.dataset.tab === tab));
    $('view').innerHTML = skFor(tab);
    renderFaq();
    await loadState(false);
    if (OPEN_LINK && S) { const l = findLink(OPEN_LINK); if (l) { if (l.status === 'active') openManage(l.id); else setTab('requests'); } }
    setInterval(() => { if (!document.hidden && !document.querySelector('.modal-overlay.active')) loadState(true); }, 60000);
})();
</script>
</body>
</html>