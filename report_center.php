<?php
/* ============================================================
   reports_center.php — Dezzme Admin Reports Center
   Protect this page: only allow admin / super-admin users.

   NEW: "Articles" tab → reported Memepedia entries
        (rows written by report_article.php into `article_reports`).

   Run once if the table doesn't exist yet:

   CREATE TABLE IF NOT EXISTS article_reports (
     id               INT AUTO_INCREMENT PRIMARY KEY,
     article_id       INT NOT NULL,
     article_title    VARCHAR(255) NOT NULL DEFAULT '',
     article_owner_id INT NOT NULL DEFAULT 0,
     reporter_id      INT NOT NULL,
     reason           VARCHAR(120) NOT NULL,
     description      TEXT NULL,
     status           VARCHAR(20) NOT NULL DEFAULT 'pending',
     admin_notes      TEXT NULL,
     created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     KEY idx_article (article_id),
     KEY idx_reporter (reporter_id, created_at)
   );

   NOTE: report_action_log.report_type must accept the value 'article'
   (if it is an ENUM, add 'article' to it; a VARCHAR needs nothing).
   ============================================================ */
session_start();
include 'config.php';
require_once __DIR__ . '/dz_warnings.php';

/* ── EDIT THIS if your Memepedia entries live in a different table (same as report_article.php) ── */
const RC_ARTICLES_TABLE = 'meme_articles';

// ── Auth guard ───────────────────────────────────────────────
$admin_id = (int)($_SESSION['user_id'] ?? 0);
$chk = $conn->query("SELECT user_rank FROM users WHERE id=$admin_id LIMIT 1");
if (!$chk || !in_array(strtolower($chk->fetch_assoc()['user_rank'] ?? ''), ['admin','super_admin'])) {
    http_response_code(403);
    exit('Access denied.');
}

// ── AJAX action handler ──────────────────────────────────────
if (isset($_POST['rc_action'])) {
    /* one failed query must never turn the reply into an HTML error page (the buttons would then do nothing) */
    if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
    ob_start();
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'msg' => 'Server error: ' . $e['message']]);
        }
    });
    header('Content-Type: application/json');
    $action    = $_POST['rc_action'];
    $rtype     = $conn->real_escape_string($_POST['rtype'] ?? 'user');
    $rid       = (int)($_POST['rid']   ?? 0);
    $uid       = (int)($_POST['uid']   ?? 0);
    $new_status= $_POST['status'] ?? 'resolved';
    if (!in_array($new_status, ['pending','under_review','resolved','dismissed'], true)) $new_status = 'resolved';
    $notes     = $conn->real_escape_string($_POST['notes']  ?? '');

    $table_map = ['user' => 'user_reports', 'content' => 'content_reports', 'pod' => 'pod_reports', 'article' => 'article_reports'];
    $table     = $table_map[$rtype] ?? 'user_reports';

    if ($action === 'update_status') {
        $conn->query("UPDATE $table SET status='$new_status', admin_notes='$notes' WHERE id=$rid");
        // Log it
        $act_label = $conn->real_escape_string("Status → $new_status");
        $conn->query("INSERT INTO report_action_log (report_type,report_id,admin_id,action,notes)
                      VALUES ('$rtype',$rid,$admin_id,'$act_label','$notes')");
        echo json_encode(['ok' => true]);

    } elseif ($action === 'warn_user') {
        /* the warned person (or pod owner) gets a full-screen notice they must acknowledge — see dz_warnings.php / warning_ack.php */
        $gid_w = (int)($_POST['gid'] ?? 0);
        if (!$uid && !$gid_w) { echo json_encode(['ok' => false, 'msg' => 'No user to warn']); exit; }
        $reasons = dz_warn_reasons();
        $rkey = (string)($_POST['reason'] ?? 'guidelines');
        if (!isset($reasons[$rkey])) $rkey = 'guidelines';
        $rtext = $reasons[$rkey][1];
        $anote = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
        dz_warn_ensure($conn);
        $is_pod_w = ($rtype === 'pod' && $gid_w > 0);
        if ($is_pod_w && !$uid) {   /* pod owner looked up from the pod */
            $q = $conn->query("SELECT created_by FROM `groups` WHERE id=$gid_w LIMIT 1");
            if ($q instanceof mysqli_result) { $uid = (int)($q->fetch_assoc()['created_by'] ?? 0); }
        }
        if (!$uid) { echo json_encode(['ok' => false, 'msg' => 'Could not find who to warn']); exit; }
        $gsave = $is_pod_w ? $gid_w : 0;
        $st = $conn->prepare("INSERT INTO account_warnings (user_id, group_id, reason_key, reason_text, admin_note, created_by) VALUES (?,?,?,?,?,?)");
        if (!$st) { echo json_encode(['ok' => false, 'msg' => 'Could not save the warning']); exit; }
        $st->bind_param('iisssi', $uid, $gsave, $rkey, $rtext, $anote, $admin_id);
        $st->execute();
        $msg = $conn->real_escape_string(($is_pod_w ? "Your pod" : "Your account") . " has received a warning: " . $reasons[$rkey][0]);
        @$conn->query("INSERT INTO notifications (user_id,type,message) VALUES ($uid,'warning','$msg')");
        $lab = $conn->real_escape_string('Warned: ' . $reasons[$rkey][0]);
        $nt = $conn->real_escape_string($anote);
        @$conn->query("INSERT INTO report_action_log (report_type,report_id,admin_id,action,notes) VALUES ('$rtype',$rid,$admin_id,'$lab','$nt')");
        echo json_encode(['ok' => true]);

    } elseif ($action === 'suspend_user') {
        if (!$uid) { echo json_encode(['ok' => false, 'msg' => 'No user to suspend']); exit; }
        /* suspended_at feeds the "Suspended on … / N days left to appeal" screen on the profile page */
        $__c = $conn->query("SHOW COLUMNS FROM users LIKE 'suspended_at'");
        if (!$__c || $__c->num_rows === 0) { @$conn->query("ALTER TABLE users ADD COLUMN suspended_at DATETIME NULL DEFAULT NULL"); }
        $__c = $conn->query("SHOW COLUMNS FROM users LIKE 'rank_before_suspend'");
        if (!$__c || $__c->num_rows === 0) { @$conn->query("ALTER TABLE users ADD COLUMN rank_before_suspend VARCHAR(40) NULL DEFAULT NULL"); }
        /* remember the rank to give back on Unsuspend (not when they are already suspended) */
        $conn->query("UPDATE users SET rank_before_suspend = IF(LOWER(user_rank) IN ('suspended','banned'), rank_before_suspend, user_rank), user_rank='Suspended', suspended_at=NOW() WHERE id=$uid");
        echo json_encode(['ok' => true]);

    } elseif ($action === 'unsuspend_user') {
        if (!$uid) { echo json_encode(['ok' => false, 'msg' => 'No user to unsuspend']); exit; }
        /* give back the rank they had before; if it was never recorded, the column's own default */
        $back = '';
        $r1 = @$conn->query("SELECT rank_before_suspend FROM users WHERE id=$uid LIMIT 1");
        if ($r1 instanceof mysqli_result) { $back = trim((string)($r1->fetch_assoc()['rank_before_suspend'] ?? '')); }
        if ($back === '' || in_array(strtolower($back), ['suspended','banned'], true)) {
            $back = '';
            $r2 = $conn->query("SHOW COLUMNS FROM users LIKE 'user_rank'");
            if ($r2) { $back = (string)($r2->fetch_assoc()['Default'] ?? ''); }
        }
        $backE = $conn->real_escape_string($back);
        $conn->query("UPDATE users SET user_rank='$backE', suspended_at=NULL, rank_before_suspend=NULL WHERE id=$uid AND LOWER(user_rank)='suspended'");
        @$conn->query("UPDATE account_appeals SET status='approved' WHERE user_id=$uid AND status='pending'");
        $conn->query("INSERT INTO report_action_log (report_type,report_id,admin_id,action,notes)
                      VALUES ('$rtype',$rid,$admin_id,'Account unsuspended','user #$uid restored')");
        echo json_encode(['ok' => true]);

    } elseif ($action === 'reject_appeal') {
        if (!$uid) { echo json_encode(['ok' => false, 'msg' => 'No user']); exit; }
        @$conn->query("UPDATE account_appeals SET status='rejected', admin_notes='$notes' WHERE user_id=$uid AND status='pending'");
        echo json_encode(['ok' => true, 'reload' => true]);

    } elseif ($action === 'ban_user') {
        if (!$uid) { echo json_encode(['ok' => false, 'msg' => 'No user to ban']); exit; }
        $conn->query("UPDATE users SET user_rank='Banned' WHERE id=$uid");
        echo json_encode(['ok' => true]);

    } elseif ($action === 'remove_content') {
        $ctype = $conn->real_escape_string($_POST['ctype'] ?? 'meme');
        $cid   = (int)($_POST['cid'] ?? 0);
        if ($ctype === 'meme')    $conn->query("DELETE FROM memes    WHERE id=$cid");
        if ($ctype === 'message') $conn->query("DELETE FROM messages WHERE id=$cid");
        try {
            /* these are whole items with their own tables: remove the item and its media */
            if ($ctype === 'highlight')       { $conn->query("DELETE FROM highlight_media       WHERE highlight_id=$cid"); $conn->query("DELETE FROM highlights       WHERE id=$cid"); }
            if ($ctype === 'group_highlight') { $conn->query("DELETE FROM group_highlight_media WHERE highlight_id=$cid"); $conn->query("DELETE FROM group_highlights WHERE id=$cid"); }
            if ($ctype === 'longform')        { $conn->query("DELETE FROM longform_posts        WHERE id=$cid"); }
        } catch (Throwable $e) {}
        echo json_encode(['ok' => true]);

    } elseif ($action === 'delete_article') {
        // Remove the Memepedia entry and close every report filed against it
        $aid = (int)($_POST['aid'] ?? 0);
        if (!$aid) { echo json_encode(['ok' => false, 'msg' => 'No entry specified']); exit; }
        $conn->query("DELETE FROM `" . RC_ARTICLES_TABLE . "` WHERE id=$aid");
        $conn->query("UPDATE article_reports SET status='resolved', admin_notes='Entry removed by admin' WHERE article_id=$aid");
        $conn->query("INSERT INTO report_action_log (report_type,report_id,admin_id,action,notes)
                      VALUES ('article',$rid,$admin_id,'Entry deleted','Memepedia entry #$aid removed')");
        echo json_encode(['ok' => true, 'reload' => true]);

    } elseif ($action === 'delete_pod') {
        $gid = (int)($_POST['gid'] ?? 0);
        $conn->query("DELETE FROM groups WHERE id=$gid");
        echo json_encode(['ok' => true]);

    } elseif ($action === 'suspend_pod') {
        $gid = (int)($_POST['gid'] ?? 0);
        if (!$gid) { echo json_encode(['ok' => false, 'msg' => 'No pod specified']); exit; }
        foreach (['is_suspended' => "TINYINT(1) NOT NULL DEFAULT 0", 'suspended_at' => "DATETIME NULL DEFAULT NULL"] as $__c => $__def) {
            $__r = $conn->query("SHOW COLUMNS FROM `groups` LIKE '$__c'");
            if (!$__r || $__r->num_rows === 0) { $conn->query("ALTER TABLE `groups` ADD COLUMN `$__c` $__def"); }
        }
        $conn->query("UPDATE `groups` SET is_suspended=1, suspended_at=NOW() WHERE id=$gid");
        $conn->query("INSERT INTO report_action_log (report_type,report_id,admin_id,action,notes)
                      VALUES ('$rtype',$rid,$admin_id,'Pod suspended','pod #$gid')");
        echo json_encode(['ok' => true]);

    } elseif ($action === 'unsuspend_pod') {
        $gid = (int)($_POST['gid'] ?? 0);
        if (!$gid) { echo json_encode(['ok' => false, 'msg' => 'No pod specified']); exit; }
        $conn->query("UPDATE `groups` SET is_suspended=0, suspended_at=NULL WHERE id=$gid");
        @$conn->query("UPDATE pod_appeals SET status='approved' WHERE group_id=$gid AND status='pending'");
        $conn->query("INSERT INTO report_action_log (report_type,report_id,admin_id,action,notes)
                      VALUES ('$rtype',$rid,$admin_id,'Pod unsuspended','pod #$gid')");
        echo json_encode(['ok' => true]);

    } elseif ($action === 'reject_pod_appeal') {
        $gid = (int)($_POST['gid'] ?? 0);
        @$conn->query("UPDATE pod_appeals SET status='rejected', admin_notes='$notes' WHERE group_id=$gid AND status='pending'");
        echo json_encode(['ok' => true, 'reload' => true]);

    } elseif ($action === 'lock_pod') {
        $gid = (int)($_POST['gid'] ?? 0);
        $conn->query("UPDATE groups SET is_locked=1 WHERE id=$gid");
        echo json_encode(['ok' => true]);

    } else {
        echo json_encode(['ok' => false, 'msg' => 'Unknown action']);
    }
    exit;
}

// ── Suspension columns / tables (created once, so the queries below never hit a missing column) ──
try {
    foreach ([['groups','is_suspended',"TINYINT(1) NOT NULL DEFAULT 0"],['groups','suspended_at',"DATETIME NULL DEFAULT NULL"],
              ['users','suspended_at',"DATETIME NULL DEFAULT NULL"],['users','rank_before_suspend',"VARCHAR(40) NULL DEFAULT NULL"]] as [$__t,$__c,$__def]) {
        $__r = $conn->query("SHOW COLUMNS FROM `$__t` LIKE '$__c'");
        if (!$__r || $__r->num_rows === 0) { $conn->query("ALTER TABLE `$__t` ADD COLUMN `$__c` $__def"); }
    }
} catch (Throwable $e) { /* no ALTER permission: the queries below fall back */ }

// ── Fetch filter params ──────────────────────────────────────
$filter_status = $conn->real_escape_string($_GET['status'] ?? '');
$filter_reason = $conn->real_escape_string($_GET['reason'] ?? '');
$filter_type   = $conn->real_escape_string($_GET['type']   ?? '');
$search        = $conn->real_escape_string($_GET['q']      ?? '');

$where_base = " WHERE 1=1";
$s_clause   = $filter_status ? " AND r.status='$filter_status'" : '';
$r_clause   = $filter_reason ? " AND r.reason LIKE '%$filter_reason%'" : '';
$q_clause   = $search        ? " AND (r.reason LIKE '%$search%' OR r.description LIKE '%$search%')" : '';

// ── Summary counts ───────────────────────────────────────────
function rcCount($conn, $table, $where = '') {
    $res = $conn->query("SELECT COUNT(*) c FROM $table $where");
    return $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;
}
$all_tables = ['user_reports','content_reports','pod_reports','article_reports'];
$total = 0; $pending = 0; $resolved = 0; $dismissed = 0;
foreach ($all_tables as $t) {
    $total     += rcCount($conn,$t);
    $pending   += rcCount($conn,$t,"WHERE status='pending'");
    $resolved  += rcCount($conn,$t,"WHERE status='resolved'");
    $dismissed += rcCount($conn,$t,"WHERE status='dismissed'");
}

// ── Fetch reports ────────────────────────────────────────────
$user_reports = $conn->query("
    SELECT r.*, ru.username AS reported_name, ru.profile_pic AS reported_pic,
           rep.username AS reporter_name
    FROM user_reports r
    LEFT JOIN users ru  ON ru.id  = r.reported_user_id
    LEFT JOIN users rep ON rep.id = r.reporter_id
    $where_base $s_clause $r_clause $q_clause
    ORDER BY r.created_at DESC
    LIMIT 200
");

$content_reports = $conn->query("
    SELECT r.*,
           ow.username AS owner_name,
           rep.username AS reporter_name
    FROM content_reports r
    LEFT JOIN users ow  ON ow.id  = r.content_owner_id
    LEFT JOIN users rep ON rep.id = r.reporter_id
    $where_base $s_clause $r_clause $q_clause
    ORDER BY r.created_at DESC
    LIMIT 200
");

$pod_reports = $conn->query("
    SELECT r.*, g.title AS pod_name, g.created_by AS creator_id,
           cr.username AS creator_name,
           rep.username AS reporter_name
    FROM pod_reports r
    LEFT JOIN groups g  ON g.id   = r.group_id
    LEFT JOIN users cr  ON cr.id  = g.created_by
    LEFT JOIN users rep ON rep.id = r.reporter_id
    $where_base $s_clause $r_clause $q_clause
    ORDER BY r.created_at DESC
    LIMIT 200
");

// Memepedia article reports (live title comes from the articles table; NULL = entry was deleted)
$article_reports = $conn->query("
    SELECT r.*,
           a.id    AS live_article_id,
           a.title AS live_title,
           ow.username  AS owner_name,
           rep.username AS reporter_name,
           (SELECT COUNT(*) FROM article_reports x WHERE x.article_id = r.article_id) AS report_count
    FROM article_reports r
    LEFT JOIN `" . RC_ARTICLES_TABLE . "` a ON a.id = r.article_id
    LEFT JOIN users ow  ON ow.id  = r.article_owner_id
    LEFT JOIN users rep ON rep.id = r.reporter_id
    $where_base $s_clause $r_clause $q_clause
    ORDER BY r.created_at DESC
    LIMIT 200
");

// Account appeals (written by the suspended-account screen on profile.php)
/* content_reports.content_type must be able to hold 'highlight', 'group_highlight' and 'longform' (an old ENUM('meme','comment','message') would reject / blank them) */
try {
    $__ct = $conn->query("SHOW COLUMNS FROM content_reports LIKE 'content_type'");
    if ($__ct instanceof mysqli_result && ($__ctr = $__ct->fetch_assoc()) && stripos((string)$__ctr['Type'], 'enum') === 0) {
        $conn->query("ALTER TABLE content_reports MODIFY content_type VARCHAR(30) NOT NULL DEFAULT 'meme'");
    }
} catch (Throwable $e) {}
@$conn->query("CREATE TABLE IF NOT EXISTS account_appeals (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, message VARCHAR(1000) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'pending', admin_notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_appeal_user (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
@$conn->query("CREATE TABLE IF NOT EXISTS pod_appeals (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, user_id INT NOT NULL, message VARCHAR(1000) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'pending', admin_notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_pa_group (group_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pod_appeals = $conn->query("
    SELECT a.*, g.title AS pod_name, g.is_suspended
    FROM pod_appeals a
    LEFT JOIN `groups` g ON g.id = a.group_id
    ORDER BY (a.status='pending') DESC, a.created_at DESC
    LIMIT 200
"); } catch (Throwable $e) { $pod_appeals = false; }
try { $appeals = $conn->query("
    SELECT a.*, u.username, u.nickname, u.user_rank
    FROM account_appeals a
    LEFT JOIN users u ON u.id = a.user_id
    ORDER BY (a.status='pending') DESC, a.created_at DESC
    LIMIT 200
"); } catch (Throwable $e) { $appeals = false; }

// Helper: status badge HTML
function rcBadge($status) {
    $map = [
        'pending'      => ['#FF9900','⏳'],
        'under_review' => ['#4499FF','🔍'],
        'resolved'     => ['#4dde8a','✅'],
        'dismissed'    => ['#888','🚫'],
    ];
    [$color, $ico] = $map[$status] ?? ['#888','?'];
    $label = ucwords(str_replace('_',' ',$status));
    return "<span style='background:rgba(0,0,0,.3);border:1px solid $color;color:$color;
            padding:3px 10px;border-radius:999px;font-size:10px;font-weight:700;
            white-space:nowrap;'>$ico $label</span>";
}
/* Pod ID / Post ID typed by the reporter: the report form writes "Pod ID: X — notes" / "Post ID: X — notes" at the start of the description.
   Returns [chip text, remaining notes]; chip text is '' when there is none. */
function rcSplitHint($desc, $label) {
    $desc = trim((string)$desc);
    if (preg_match('/^' . preg_quote($label, '/') . ':\s*(\S+)(?:\s*[—-]\s*(.*))?$/su', $desc, $m)) return [$label . ': ' . $m[1], trim($m[2] ?? '')];
    return ['', $desc];
}
function rcHintChip($txt) {
    if ($txt === '') return '';
    return '<div style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;background:rgba(255,45,45,.08);border:1px solid rgba(255,45,45,.2);border-radius:6px;padding:3px 8px;font-size:10px;color:rgba(255,100,100,.85);white-space:nowrap;"><i class="ri-fingerprint-line" style="font-size:11px;"></i> ' . htmlspecialchars($txt) . '</div>';
}
function rcFmt($ts) {
    return date('M j, Y · H:i', strtotime($ts));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reports Center — Dezzme Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
body{background:linear-gradient(-45deg,#010218,#070924,#070924);min-height:100vh;color:#f0f2ff;overflow-x:hidden;}
a{text-decoration:none;color:inherit;}

/* ── Top bar ── */
.rc-topbar{display:flex;align-items:center;justify-content:space-between;padding:18px 28px;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.07);}
.rc-logo{font-size:20px;font-weight:700;letter-spacing:1px;color:#FF2D2D;}
.rc-topbar-right{display:flex;align-items:center;gap:14px;}
.rc-back-btn{padding:7px 18px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:rgba(255,255,255,.65);font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;}
.rc-back-btn:hover{background:rgba(255,255,255,.12);color:#fff;}

/* ── Wrapper ── */
.rc-wrap{max-width:1100px;margin:0 auto;padding:28px 20px 80px;}

/* ── Summary cards ── */
.rc-summary{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px;margin-bottom:28px;}
.rc-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:18px;padding:18px 20px;}
.rc-card-num{font-size:28px;font-weight:700;color:#fff;line-height:1.1;}
.rc-card-label{font-size:11px;color:rgba(255,255,255,.4);margin-top:4px;text-transform:uppercase;letter-spacing:.6px;}
.rc-card-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px;}

/* ── Filter bar ── */
.rc-filters{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;align-items:center;}
.rc-filter-input{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:8px 14px;color:#fff;font-size:12px;font-family:'Poppins',sans-serif;outline:none;transition:border-color .2s;}
.rc-filter-input:focus{border-color:rgba(255,45,45,.45);}
.rc-filter-input::placeholder{color:rgba(255,255,255,.25);}
.rc-filter-select{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:8px 14px;color:#fff;font-size:12px;font-family:'Poppins',sans-serif;outline:none;cursor:pointer;}
.rc-filter-select option{background:#0a0b1e;color:#fff;}

/* ── Tab bar ── */
.rc-tabs{display:flex;gap:0;border-bottom:1px solid rgba(255,255,255,.07);margin-bottom:22px;overflow-x:auto;scrollbar-width:none;}
.rc-tabs::-webkit-scrollbar{display:none;}
.rc-tab{padding:11px 20px;font-size:13px;font-weight:600;color:rgba(255,255,255,.45);cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;transition:all .2s;-webkit-tap-highlight-color:transparent;}
.rc-tab:hover{color:rgba(255,255,255,.75);}
.rc-tab.active{color:#FF2D2D;border-bottom-color:#FF2D2D;}
.rc-badge{display:inline-flex;align-items:center;justify-content:center;background:rgba(255,45,45,.15);color:#FF5566;border-radius:999px;padding:1px 7px;font-size:10px;font-weight:700;margin-left:6px;}

/* ── Table ── */
.rc-table-wrap{overflow-x:auto;border-radius:16px;border:1px solid rgba(255,255,255,.07);}
table{width:100%;border-collapse:collapse;min-width:700px;}
thead th{background:rgba(255,255,255,.04);padding:11px 16px;text-align:left;font-size:11px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid rgba(255,255,255,.07);}
tbody tr{border-bottom:1px solid rgba(255,255,255,.04);transition:background .15s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:rgba(255,255,255,.03);}
tbody td{padding:12px 16px;font-size:12.5px;color:rgba(255,255,255,.8);vertical-align:middle;}
.rc-user-cell{display:flex;align-items:center;gap:9px;}
.rc-user-cell img{width:32px;height:32px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(255,45,45,.3);flex-shrink:0;}
.rc-username{font-weight:600;color:#fff;font-size:12.5px;}
.rc-reason-pill{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:3px 9px;font-size:11px;color:rgba(255,255,255,.7);display:inline-block;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.rc-multi{display:inline-flex;align-items:center;gap:4px;margin-top:4px;background:rgba(255,153,0,.1);border:1px solid rgba(255,153,0,.3);border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700;color:#FF9900;white-space:nowrap;}
.rc-deleted{display:inline-flex;align-items:center;gap:4px;margin-top:4px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);border-radius:6px;padding:2px 8px;font-size:10px;font-weight:600;color:rgba(255,255,255,.45);white-space:nowrap;}

/* ── Action buttons ── */
.rc-actions{display:flex;flex-wrap:wrap;gap:5px;}
.rc-act{display:inline-flex;align-items:center;gap:4px;padding:5px 11px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:rgba(255,255,255,.65);font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .18s;font-family:'Poppins',sans-serif;}
.rc-act:hover{background:rgba(255,255,255,.1);color:#fff;}
.rc-act.red{border-color:rgba(255,45,45,.3);color:#FF5566;}
.rc-act.red:hover{background:rgba(255,45,45,.15);border-color:rgba(255,45,45,.6);}
.rc-act.green{border-color:rgba(77,222,138,.3);color:#4dde8a;}
.rc-act.green:hover{background:rgba(77,222,138,.1);}
.rc-act.orange{border-color:rgba(255,153,0,.3);color:#FF9900;}
.rc-act.orange:hover{background:rgba(255,153,0,.1);}

/* ── Empty state ── */
.rc-empty{text-align:center;padding:50px 20px;color:rgba(255,255,255,.3);}
.rc-empty i{font-size:2.5rem;display:block;margin-bottom:12px;color:rgba(255,45,45,.3);}

/* ── Confirm modal ── */
#rcConfirmModal{display:none;position:fixed;inset:0;z-index:9999999;background:rgba(0,0,0,.82);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px;}
#rcConfirmModal.open{display:flex;}
#rcWarnModal{display:none;position:fixed;inset:0;z-index:9999999;background:rgba(0,0,0,.82);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px;}
#rcWarnModal.open{display:flex;}
.rcw-l{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;color:rgba(255,255,255,.5);margin:10px 0 5px;text-transform:uppercase;}
.rcw-in{width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:10px;color:#fff;font-size:13px;padding:9px 10px;font-family:'Poppins',sans-serif;outline:none;}
.rcw-in option{background:#12122a;color:#fff;}
.rcw-prev{font-size:12px;line-height:1.5;color:rgba(255,255,255,.6);background:rgba(255,153,0,.07);border:1px solid rgba(255,153,0,.22);border-radius:10px;padding:9px 11px;margin-top:8px;}
.rc-confirm-box{background:rgba(12,12,28,.98);border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:24px;width:100%;max-width:360px;box-shadow:0 24px 60px rgba(0,0,0,.7);}
.rc-confirm-box h3{font-size:15px;font-weight:700;color:#fff;margin-bottom:8px;}
.rc-confirm-box p{font-size:12.5px;color:rgba(255,255,255,.5);line-height:1.55;margin-bottom:16px;}
.rc-confirm-btns{display:flex;gap:10px;}
.rc-confirm-cancel{flex:1;padding:10px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:rgba(255,255,255,.55);font-size:12.5px;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;}
.rc-confirm-ok{flex:1;padding:10px;border-radius:12px;background:#FF2D2D;border:none;color:#fff;font-size:12.5px;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;}
.rc-confirm-ok.orange{background:#FF9900;}
.rc-confirm-ok.green{background:#4dde8a;color:#000;}

/* ── Responsive ── */
@media(max-width:768px){
    .rc-topbar{padding:14px 16px;}
    .rc-wrap{padding:18px 12px 70px;}
    .rc-summary{grid-template-columns:repeat(2,1fr);gap:10px;}
    .rc-card{padding:14px 16px;}
    .rc-card-num{font-size:24px;}
    .rc-tab{padding:10px 15px;font-size:12px;}
    thead th{padding:9px 12px;}
    tbody td{padding:10px 12px;}
}
@media(max-width:480px){
    .rc-summary{grid-template-columns:repeat(2,1fr);}
    .rc-filter-input,.rc-filter-select{width:100%;}
    .rc-act{padding:4px 9px;font-size:10.5px;}
}
</style>
</head>
<body>

<div class="rc-topbar">
    <div class="rc-logo"><i class="ri-shield-star-line"></i> Reports Center</div>
    <div class="rc-topbar-right">
        <a href="home.php" class="rc-back-btn"><i class="ri-arrow-left-line"></i> Back to Home</a>
    </div>
</div>

<div class="rc-wrap">

    <!-- ── Summary cards ── -->
    <div class="rc-summary">
        <div class="rc-card">
            <div class="rc-card-num"><?= number_format($total) ?></div>
            <div class="rc-card-label"><span class="rc-card-dot" style="background:#aaa;"></span>Total Reports</div>
        </div>
        <div class="rc-card">
            <div class="rc-card-num" style="color:#FF9900;"><?= number_format($pending) ?></div>
            <div class="rc-card-label"><span class="rc-card-dot" style="background:#FF9900;"></span>Pending</div>
        </div>
        <div class="rc-card">
            <div class="rc-card-num" style="color:#4dde8a;"><?= number_format($resolved) ?></div>
            <div class="rc-card-label"><span class="rc-card-dot" style="background:#4dde8a;"></span>Resolved</div>
        </div>
        <div class="rc-card">
            <div class="rc-card-num" style="color:#888;"><?= number_format($dismissed) ?></div>
            <div class="rc-card-label"><span class="rc-card-dot" style="background:#888;"></span>Dismissed</div>
        </div>
    </div>

    <!-- ── Filters ── -->
    <form method="GET" class="rc-filters" id="rcFilterForm">
        <input type="hidden" name="tab" id="rcTabField" value="<?= htmlspecialchars($_GET['tab'] ?? 'users') ?>">
        <input type="text" name="q" class="rc-filter-input" placeholder="🔍  Search reason or description…" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" style="min-width:220px;">
        <select name="status" class="rc-filter-select" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="pending"      <?= $filter_status==='pending'      ?'selected':''?>>Pending</option>
            <option value="under_review" <?= $filter_status==='under_review' ?'selected':''?>>Under Review</option>
            <option value="resolved"     <?= $filter_status==='resolved'     ?'selected':''?>>Resolved</option>
            <option value="dismissed"    <?= $filter_status==='dismissed'    ?'selected':''?>>Dismissed</option>
        </select>
        <button type="submit" class="rc-act green"><i class="ri-search-line"></i> Search</button>
        <a href="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>" class="rc-act">Clear</a>
    </form>

    <!-- ── Tabs ── -->
    <div class="rc-tabs">
        <div class="rc-tab active" id="tab-users"   onclick="rcTab('users')">
            <i class="ri-user-forbid-line"></i> Dezzmers
            <?php if($user_reports && $user_reports->num_rows): ?><span class="rc-badge"><?= $user_reports->num_rows ?></span><?php endif; ?>
        </div>
        <div class="rc-tab" id="tab-pods" onclick="rcTab('pods')">
            <i class="ri-group-line"></i> Pods
            <?php if($pod_reports && $pod_reports->num_rows): ?><span class="rc-badge"><?= $pod_reports->num_rows ?></span><?php endif; ?>
        </div>
        <div class="rc-tab" id="tab-content" onclick="rcTab('content')">
            <i class="ri-image-2-line"></i> Content
            <?php if($content_reports && $content_reports->num_rows): ?><span class="rc-badge"><?= $content_reports->num_rows ?></span><?php endif; ?>
        </div>
        <div class="rc-tab" id="tab-appeals" onclick="rcTab('appeals')">
            <i class="ri-hand-heart-line"></i> Appeals
            <?php $__pa = 0; if ($pod_appeals) { while ($__y = $pod_appeals->fetch_assoc()) { if ($__y['status'] === 'pending') $__pa++; } $pod_appeals->data_seek(0); } if ($appeals) { $appeals->data_seek(0); while ($__x = $appeals->fetch_assoc()) { if ($__x['status'] === 'pending') $__pa++; } $appeals->data_seek(0); } if ($__pa): ?><span class="rc-badge"><?= $__pa ?></span><?php endif; ?>
        </div>
        <div class="rc-tab" id="tab-articles" onclick="rcTab('articles')">
            <i class="ri-book-open-line"></i> Articles
            <?php if($article_reports && $article_reports->num_rows): ?><span class="rc-badge"><?= $article_reports->num_rows ?></span><?php endif; ?>
        </div>
    </div>

    <!-- ═══════════════════════════════════════
     USER REPORTS
═══════════════════════════════════════ -->
<div id="section-users">
    <div class="rc-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Reported User</th>
                    <th>Reporter</th>
                    <th>Reason & Notes</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (!$user_reports || $user_reports->num_rows === 0):
            ?>
                <tr><td colspan="7"><div class="rc-empty"><i class="ri-shield-check-line"></i>No user reports found</div></td></tr>
            <?php else:
                while ($r = $user_reports->fetch_assoc()):
                    $pic = !empty($r['reported_pic']) ? htmlspecialchars($r['reported_pic']) : 'mee.jpg';
                    // Parse out the User ID hint if it was prepended
                    $raw_desc = trim($r['description'] ?? '');
                    $hint_str  = '';
                    $extra_desc = '';
                    if (str_starts_with($raw_desc, 'Reporter provided User ID:')) {
                        $parts      = explode(' | ', $raw_desc, 2);
                        $hint_str   = trim($parts[0]);
                        $extra_desc = trim($parts[1] ?? '');
                    } else {
                        $extra_desc = $raw_desc;
                    }
            ?>
                <tr id="urow-<?= $r['id'] ?>">
                    <td style="color:rgba(255,255,255,.35);font-size:11px;">#<?= $r['id'] ?></td>
                    <td>
                        <div class="rc-user-cell">
                            <img src="<?= $pic ?>" onerror="this.src='mee.jpg'">
                            <div>
                                <div class="rc-username"><?= htmlspecialchars($r['reported_name'] ?? $r['reported_username']) ?></div>
                                <div style="font-size:10px;color:rgba(255,255,255,.3);">ID <?= $r['reported_user_id'] ?></div>
                            </div>
                        </div>
                    </td>
                    <td style="color:rgba(255,255,255,.55);font-size:12px;"><?= htmlspecialchars($r['reporter_name'] ?? 'User '.$r['reporter_id']) ?></td>
                    <td>
                        <span class="rc-reason-pill"><?= htmlspecialchars($r['reason']) ?></span>
                        <?php if ($hint_str): ?>
                            <div style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;background:rgba(255,45,45,.08);border:1px solid rgba(255,45,45,.2);border-radius:6px;padding:3px 8px;font-size:10px;color:rgba(255,100,100,.85);white-space:nowrap;">
                                <i class="ri-fingerprint-line" style="font-size:11px;"></i>
                                <?= htmlspecialchars($hint_str) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($extra_desc): ?>
                            <div style="font-size:10.5px;color:rgba(255,255,255,.38);margin-top:5px;line-height:1.5;max-width:200px;word-break:break-word;">
                                <?= htmlspecialchars($extra_desc) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:rgba(255,255,255,.4);white-space:nowrap;"><?= rcFmt($r['created_at']) ?></td>
                    <td><?= rcBadge($r['status']) ?></td>
                    <td>
                        <div class="rc-actions">
                            <a href="profile.php?user_id=<?= $r['reported_user_id'] ?>" target="_blank" class="rc-act"><i class="ri-user-line"></i> Profile</a>
                            <button class="rc-act orange" onclick="rcWarnOpen({rid:<?=$r['id']?>,rtype:'user',uid:<?=$r['reported_user_id']?>},'Warn User')"><i class="ri-alarm-warning-line"></i> Warn</button>
                            <button class="rc-act red" onclick="rcConfirm('suspend_user',{rid:<?=$r['id']?>,rtype:'user',uid:<?=$r['reported_user_id']?>},'Suspend User','Suspend this user? They will not be able to log in.','Suspend','red')"><i class="ri-forbidden-2-line"></i> Suspend</button>
                            <button class="rc-act green" onclick="rcConfirm('unsuspend_user',{rid:<?=$r['id']?>,rtype:'user',uid:<?=$r['reported_user_id']?>,reload:1},'Unsuspend User','Restore this account to normal?','Unsuspend','green')"><i class="ri-lock-unlock-line"></i> Unsuspend</button>
                            <button class="rc-act red" onclick="rcConfirm('ban_user',{rid:<?=$r['id']?>,rtype:'user',uid:<?=$r['reported_user_id']?>},'Ban User','Permanently ban this user?','Ban','red')"><i class="ri-close-circle-line"></i> Ban</button>
                            <button class="rc-act green" onclick="rcUpdateStatus('user',<?=$r['id']?>,'resolved','urow-<?=$r['id']?>')"><i class="ri-check-line"></i> Resolve</button>
                            <button class="rc-act" onclick="rcUpdateStatus('user',<?=$r['id']?>,'dismissed','urow-<?=$r['id']?>')"><i class="ri-close-line"></i> Dismiss</button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════
     POD REPORTS
═══════════════════════════════════════ -->
<div id="section-pods" style="display:none;">
    <div class="rc-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Pod</th>
                    <th>Creator</th>
                    <th>Reporter</th>
                    <th>Reason & Notes</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (!$pod_reports || $pod_reports->num_rows === 0):
            ?>
                <tr><td colspan="8"><div class="rc-empty"><i class="ri-group-line"></i>No pod reports found</div></td></tr>
            <?php else:
                while ($r = $pod_reports->fetch_assoc()):
                    [$pod_hint, $pod_desc] = rcSplitHint($r['description'] ?? '', 'Pod ID');
            ?>
                <tr id="prow-<?= $r['id'] ?>">
                    <td style="color:rgba(255,255,255,.35);font-size:11px;">#<?= $r['id'] ?></td>
                    <td>
                        <div style="font-weight:600;color:#fff;"><?= htmlspecialchars($r['pod_name'] ?? 'Pod '.$r['group_id']) ?></div>
                        <div style="font-size:10px;color:rgba(255,255,255,.3);">ID <?= $r['group_id'] ?></div>
                    </td>
                    <td style="font-size:12px;color:rgba(255,255,255,.6);"><?= htmlspecialchars($r['creator_name'] ?? 'ID '.$r['creator_id']) ?></td>
                    <td style="font-size:12px;color:rgba(255,255,255,.5);"><?= htmlspecialchars($r['reporter_name'] ?? 'User '.$r['reporter_id']) ?></td>
                    <td>
                        <span class="rc-reason-pill"><?= htmlspecialchars($r['reason']) ?></span>
                        <?= rcHintChip($pod_hint) ?>
                        <?php if ($pod_desc): ?>
                            <div style="font-size:10.5px;color:rgba(255,255,255,.38);margin-top:5px;line-height:1.5;max-width:200px;word-break:break-word;">
                                <?= htmlspecialchars($pod_desc) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:rgba(255,255,255,.4);white-space:nowrap;"><?= rcFmt($r['created_at']) ?></td>
                    <td><?= rcBadge($r['status']) ?></td>
                    <td>
                        <div class="rc-actions">
                            <a href="community.php?group_id=<?= $r['group_id'] ?>" target="_blank" class="rc-act"><i class="ri-group-line"></i> View Pod</a>
                            <button class="rc-act orange" onclick="rcWarnOpen({rid:<?=$r['id']?>,rtype:'pod',uid:<?=(int)($r['creator_id']??0)?>,gid:<?=(int)$r['group_id']?>},'Warn Creator')"><i class="ri-alarm-warning-line"></i> Warn</button>
                            <button class="rc-act red" onclick="rcConfirm('suspend_pod',{rid:<?=$r['id']?>,rtype:'pod',gid:<?=$r['group_id']?>},'Suspend Pod','Suspend this pod? It will be hidden from everyone and its creator can appeal.','Suspend','red')"><i class="ri-forbidden-2-line"></i> Suspend</button>
                            <button class="rc-act green" onclick="rcConfirm('unsuspend_pod',{rid:<?=$r['id']?>,rtype:'pod',gid:<?=$r['group_id']?>,reload:1},'Unsuspend Pod','Restore this pod to normal?','Unsuspend','green')"><i class="ri-lock-unlock-line"></i> Unsuspend</button>
                            <button class="rc-act orange" onclick="rcConfirm('lock_pod',{rid:<?=$r['id']?>,rtype:'pod',gid:<?=$r['group_id']?>},'Lock Pod','Lock this pod? Members cannot post.','Lock','orange')"><i class="ri-lock-line"></i> Lock</button>
                            <button class="rc-act red" onclick="rcConfirm('delete_pod',{rid:<?=$r['id']?>,rtype:'pod',gid:<?=$r['group_id']?>},'Delete Pod','Permanently delete this pod?','Delete','red')"><i class="ri-delete-bin-line"></i> Delete</button>
                            <button class="rc-act green" onclick="rcUpdateStatus('pod',<?=$r['id']?>,'resolved','prow-<?=$r['id']?>')"><i class="ri-check-line"></i> Resolve</button>
                            <button class="rc-act" onclick="rcUpdateStatus('pod',<?=$r['id']?>,'dismissed','prow-<?=$r['id']?>')"><i class="ri-close-line"></i> Dismiss</button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════
     CONTENT REPORTS
═══════════════════════════════════════ -->
<div id="section-content" style="display:none;">
    <div class="rc-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>Content ID</th>
                    <th>Owner</th>
                    <th>Reporter</th>
                    <th>Reason & Notes</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (!$content_reports || $content_reports->num_rows === 0):
            ?>
                <tr><td colspan="9"><div class="rc-empty"><i class="ri-image-2-line"></i>No content reports found</div></td></tr>
            <?php else:
                while ($r = $content_reports->fetch_assoc()):
                    $ct_icons = ['meme'=>'ri-image-line','comment'=>'ri-chat-1-line','message'=>'ri-message-2-line','highlight'=>'ri-slideshow-3-line','group_highlight'=>'ri-slideshow-3-line','longform'=>'ri-movie-2-line'];
                    $ct_names = ['meme'=>'Post','comment'=>'Comment','message'=>'Message','highlight'=>'Highlight','group_highlight'=>'Pod highlight','longform'=>'Longform video'];
                    $ct_icon  = $ct_icons[$r['content_type']] ?? 'ri-file-line';
                    $ct_name  = $ct_names[$r['content_type']] ?? ucfirst((string)$r['content_type']);
                    [$cont_hint, $cont_desc] = rcSplitHint($r['description'] ?? '', 'Post ID');
            ?>
                <tr id="crow-<?= $r['id'] ?>">
                    <td style="color:rgba(255,255,255,.35);font-size:11px;">#<?= $r['id'] ?></td>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.06);padding:3px 9px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap;">
                            <i class="<?= $ct_icon ?>"></i> <?= htmlspecialchars($ct_name) ?>
                        </span>
                    </td>
                    <td style="font-size:11px;color:rgba(255,255,255,.4);">#<?= $r['content_id'] ?></td>
                    <td style="font-size:12px;color:rgba(255,255,255,.6);"><?= htmlspecialchars($r['owner_name'] ?? 'ID '.($r['content_owner_id']??'?')) ?></td>
                    <td style="font-size:12px;color:rgba(255,255,255,.5);"><?= htmlspecialchars($r['reporter_name'] ?? 'User '.$r['reporter_id']) ?></td>
                    <td>
                        <span class="rc-reason-pill"><?= htmlspecialchars($r['reason']) ?></span>
                        <?= rcHintChip($cont_hint) ?>
                        <?php if ($cont_desc): ?>
                            <div style="font-size:10.5px;color:rgba(255,255,255,.38);margin-top:5px;line-height:1.5;max-width:200px;word-break:break-word;">
                                <?= htmlspecialchars($cont_desc) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:rgba(255,255,255,.4);white-space:nowrap;"><?= rcFmt($r['created_at']) ?></td>
                    <td><?= rcBadge($r['status']) ?></td>
                    <td>
                        <div class="rc-actions">
                            <?php if ($r['content_type']==='meme'): ?>
                            <a href="meme_view.php?id=<?= $r['content_id'] ?>" target="_blank" class="rc-act"><i class="ri-eye-line"></i> View</a>
                            <button class="rc-act red" onclick="rcConfirm('remove_content',{rid:<?=$r['id']?>,rtype:'content',cid:<?=$r['content_id']?>,ctype:'<?=$r['content_type']?>',uid:<?=(int)($r['content_owner_id']??0)?>},'Remove Content','Permanently delete this meme?','Remove','red')"><i class="ri-delete-bin-line"></i> Remove</button>
                            <?php elseif (in_array($r['content_type'], ['highlight','group_highlight','longform'], true)): ?>
                            <button class="rc-act red" onclick="rcConfirm('remove_content',{rid:<?=$r['id']?>,rtype:'content',cid:<?=$r['content_id']?>,ctype:'<?=$r['content_type']?>',uid:<?=(int)($r['content_owner_id']??0)?>},'Remove <?= $ct_name ?>','Permanently delete this <?= strtolower($ct_name) ?>?','Remove','red')"><i class="ri-delete-bin-line"></i> Remove</button>
                            <?php elseif ($r['content_type']==='message'): ?>
                            <button class="rc-act red" onclick="rcConfirm('remove_content',{rid:<?=$r['id']?>,rtype:'content',cid:<?=$r['content_id']?>,ctype:'message',uid:<?=(int)($r['content_owner_id']??0)?>},'Delete Message','Permanently delete this message?','Delete','red')"><i class="ri-delete-bin-line"></i> Delete</button>
                            <?php endif; ?>
                            <button class="rc-act orange" onclick="rcWarnOpen({rid:<?=$r['id']?>,rtype:'content',uid:<?=(int)($r['content_owner_id']??0)?>},'Warn User')"><i class="ri-alarm-warning-line"></i> Warn</button>
                            <button class="rc-act red" onclick="rcConfirm('suspend_user',{rid:<?=$r['id']?>,rtype:'content',uid:<?=(int)($r['content_owner_id']??0)?>},'Suspend User','Suspend the content owner?','Suspend','red')"><i class="ri-forbidden-2-line"></i> Suspend</button>
                            <button class="rc-act green" onclick="rcUpdateStatus('content',<?=$r['id']?>,'resolved','crow-<?=$r['id']?>')"><i class="ri-check-line"></i> Resolve</button>
                            <button class="rc-act" onclick="rcUpdateStatus('content',<?=$r['id']?>,'dismissed','crow-<?=$r['id']?>')"><i class="ri-close-line"></i> Dismiss</button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════
     ARTICLE (MEMEPEDIA) REPORTS
═══════════════════════════════════════ -->
<div id="section-articles" style="display:none;">
    <div class="rc-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Entry</th>
                    <th>Author</th>
                    <th>Reporter</th>
                    <th>Reason & Notes</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (!$article_reports || $article_reports->num_rows === 0):
            ?>
                <tr><td colspan="8"><div class="rc-empty"><i class="ri-book-open-line"></i>No article reports found</div></td></tr>
            <?php else:
                while ($r = $article_reports->fetch_assoc()):
                    $art_desc   = trim($r['description'] ?? '');
                    $art_alive  = !empty($r['live_article_id']);
                    $art_title  = $art_alive ? $r['live_title'] : ($r['article_title'] ?: 'Entry '.$r['article_id']);
                    $art_owner  = (int)($r['article_owner_id'] ?? 0);
                    $rep_count  = (int)($r['report_count'] ?? 1);
            ?>
                <tr id="arow-<?= $r['id'] ?>">
                    <td style="color:rgba(255,255,255,.35);font-size:11px;">#<?= $r['id'] ?></td>
                    <td>
                        <div style="font-weight:600;color:#fff;max-width:180px;word-break:break-word;"><?= htmlspecialchars($art_title) ?></div>
                        <div style="font-size:10px;color:rgba(255,255,255,.3);">ID <?= (int)$r['article_id'] ?></div>
                        <?php if ($rep_count > 1): ?>
                            <div class="rc-multi"><i class="ri-flag-2-line"></i> <?= $rep_count ?> reports on this entry</div>
                        <?php endif; ?>
                        <?php if (!$art_alive): ?>
                            <div class="rc-deleted"><i class="ri-delete-bin-line"></i> Entry deleted</div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:rgba(255,255,255,.6);"><?= htmlspecialchars($r['owner_name'] ?? 'ID '.$art_owner) ?></td>
                    <td style="font-size:12px;color:rgba(255,255,255,.5);"><?= htmlspecialchars($r['reporter_name'] ?? 'User '.$r['reporter_id']) ?></td>
                    <td>
                        <span class="rc-reason-pill"><?= htmlspecialchars($r['reason']) ?></span>
                        <?php if ($art_desc): ?>
                            <div style="font-size:10.5px;color:rgba(255,255,255,.38);margin-top:5px;line-height:1.5;max-width:200px;word-break:break-word;">
                                <?= htmlspecialchars($art_desc) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:rgba(255,255,255,.4);white-space:nowrap;"><?= rcFmt($r['created_at']) ?></td>
                    <td><?= rcBadge($r['status']) ?></td>
                    <td>
                        <div class="rc-actions">
                            <?php if ($art_alive): ?>
                            <a href="memepedia.php?open=<?= (int)$r['article_id'] ?>&from=nav" target="_blank" class="rc-act"><i class="ri-eye-line"></i> View Entry</a>
                            <?php endif; ?>
                            <?php if ($art_owner): ?>
                            <a href="profile.php?user_id=<?= $art_owner ?>" target="_blank" class="rc-act"><i class="ri-user-line"></i> Author</a>
                            <?php endif; ?>
                            <button class="rc-act orange" onclick="rcWarnOpen({rid:<?=$r['id']?>,rtype:'article',uid:<?=$art_owner?>},'Warn Author')"><i class="ri-alarm-warning-line"></i> Warn</button>
                            <?php if ($art_alive): ?>
                            <button class="rc-act red" onclick="rcConfirm('delete_article',{rid:<?=$r['id']?>,rtype:'article',aid:<?=(int)$r['article_id']?>},'Delete Entry','Permanently delete this Memepedia entry? All reports on it will be marked resolved.','Delete','red')"><i class="ri-delete-bin-line"></i> Delete</button>
                            <?php endif; ?>
                            <button class="rc-act red" onclick="rcConfirm('suspend_user',{rid:<?=$r['id']?>,rtype:'article',uid:<?=$art_owner?>},'Suspend Author','Suspend the author of this entry?','Suspend','red')"><i class="ri-forbidden-2-line"></i> Suspend</button>
                            <button class="rc-act green" onclick="rcUpdateStatus('article',<?=$r['id']?>,'resolved','arow-<?=$r['id']?>')"><i class="ri-check-line"></i> Resolve</button>
                            <button class="rc-act" onclick="rcUpdateStatus('article',<?=$r['id']?>,'dismissed','arow-<?=$r['id']?>')"><i class="ri-close-line"></i> Dismiss</button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- ═══════════════════════════════════════
     SUSPENSION APPEALS
═══════════════════════════════════════ -->
<div id="section-appeals" style="display:none;">
    <div class="rc-table-wrap">
        <table>
            <thead><tr><th>#</th><th>Account</th><th>Message</th><th>Sent</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ((!$appeals || $appeals->num_rows === 0) && (!$pod_appeals || $pod_appeals->num_rows === 0)): ?>
                <tr><td colspan="6"><div class="rc-empty"><i class="ri-hand-heart-line"></i>No appeals yet</div></td></tr>
            <?php else: while ($appeals && ($a = $appeals->fetch_assoc())):
                $a_susp = strtolower((string)($a['user_rank'] ?? '')) === 'suspended'; ?>
                <tr id="aprow-<?= (int)$a['id'] ?>">
                    <td style="color:rgba(255,255,255,.35);font-size:11px;">#<?= (int)$a['id'] ?></td>
                    <td><div class="rc-username"><?= htmlspecialchars($a['nickname'] ?: ($a['username'] ?? 'User')) ?></div><div style="font-size:10px;color:rgba(255,255,255,.3);">ID <?= (int)$a['user_id'] ?></div></td>
                    <td style="max-width:260px;word-break:break-word;font-size:11.5px;color:rgba(255,255,255,.6);"><?= $a['message'] !== '' ? htmlspecialchars($a['message']) : '<span style="opacity:.4">(no message)</span>' ?></td>
                    <td style="font-size:11px;color:rgba(255,255,255,.4);white-space:nowrap;"><?= rcFmt($a['created_at']) ?></td>
                    <td><?= rcBadge($a['status']) ?></td>
                    <td><div class="rc-actions">
                        <a href="profile.php?user_id=<?= (int)$a['user_id'] ?>" target="_blank" class="rc-act"><i class="ri-user-line"></i> Profile</a>
                        <?php if ($a_susp): ?>
                        <button class="rc-act green" onclick="rcConfirm('unsuspend_user',{rid:0,rtype:'user',uid:<?= (int)$a['user_id'] ?>,reload:1},'Unsuspend Account','Restore this account to normal?','Unsuspend','green')"><i class="ri-lock-unlock-line"></i> Unsuspend</button>
                        <?php if ($a['status'] === 'pending'): ?>
                        <button class="rc-act red" onclick="rcConfirm('reject_appeal',{rid:0,rtype:'user',uid:<?= (int)$a['user_id'] ?>},'Reject Appeal','The account stays disabled and cannot appeal again.','Reject','red')"><i class="ri-close-circle-line"></i> Reject</button>
                        <?php endif; endif; ?>
                    </div></td>
                </tr>
            <?php endwhile; endif; ?>
            <?php if ($pod_appeals) while ($a = $pod_appeals->fetch_assoc()): $a_psusp = !empty($a['is_suspended']); ?>
                <tr id="paprow-<?= (int)$a['id'] ?>">
                    <td style="color:rgba(255,255,255,.35);font-size:11px;">#P<?= (int)$a['id'] ?></td>
                    <td><div class="rc-username"><i class="ri-group-line"></i> <?= htmlspecialchars($a['pod_name'] ?? 'Pod') ?></div><div style="font-size:10px;color:rgba(255,255,255,.3);">Pod ID <?= (int)$a['group_id'] ?></div></td>
                    <td style="max-width:260px;word-break:break-word;font-size:11.5px;color:rgba(255,255,255,.6);"><?= $a['message'] !== '' ? htmlspecialchars($a['message']) : '<span style="opacity:.4">(no message)</span>' ?></td>
                    <td style="font-size:11px;color:rgba(255,255,255,.4);white-space:nowrap;"><?= rcFmt($a['created_at']) ?></td>
                    <td><?= rcBadge($a['status']) ?></td>
                    <td><div class="rc-actions">
                        <a href="group.php?id=<?= (int)$a['group_id'] ?>" target="_blank" class="rc-act"><i class="ri-group-line"></i> View Pod</a>
                        <?php if ($a_psusp): ?>
                        <button class="rc-act green" onclick="rcConfirm('unsuspend_pod',{rid:0,rtype:'pod',gid:<?= (int)$a['group_id'] ?>,reload:1},'Unsuspend Pod','Restore this pod to normal?','Unsuspend','green')"><i class="ri-lock-unlock-line"></i> Unsuspend</button>
                        <?php if ($a['status'] === 'pending'): ?>
                        <button class="rc-act red" onclick="rcConfirm('reject_pod_appeal',{rid:0,rtype:'pod',gid:<?= (int)$a['group_id'] ?>},'Reject Appeal','The pod stays disabled and cannot appeal again.','Reject','red')"><i class="ri-close-circle-line"></i> Reject</button>
                        <?php endif; endif; ?>
                    </div></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- /.rc-wrap -->

<!-- Confirm modal -->
<div id="rcWarnModal">
    <div class="rc-confirm-box" style="max-width:420px">
        <h3 id="rcWarnTitle">Warn</h3>
        <p>Pick why. The user (or pod owner) sees a full-screen warning with this reason and can only close it by pressing “I understand”.</p>
        <label class="rcw-l">Reason</label>
        <select id="rcWarnReason" class="rcw-in" onchange="rcWarnPrev()"></select>
        <div id="rcWarnPrev" class="rcw-prev"></div>
        <label class="rcw-l">Extra note to the user <span style="opacity:.5">(optional)</span></label>
        <textarea id="rcWarnNote" class="rcw-in" rows="3" maxlength="500" placeholder="e.g. Please remove the posts from last week."></textarea>
        <div class="rc-confirm-btns" style="margin-top:12px">
            <button class="rc-confirm-cancel" onclick="document.getElementById('rcWarnModal').classList.remove('open')">Cancel</button>
            <button class="rc-confirm-ok orange" id="rcWarnSend" onclick="rcWarnSend()">Send warning</button>
        </div>
    </div>
</div>
<div id="rcConfirmModal">
    <div class="rc-confirm-box">
        <h3 id="rcConfTitle">Are you sure?</h3>
        <p  id="rcConfDesc">This action cannot be undone.</p>
        <div class="rc-confirm-btns">
            <button class="rc-confirm-cancel" onclick="document.getElementById('rcConfirmModal').classList.remove('open')">Cancel</button>
            <button class="rc-confirm-ok" id="rcConfOkBtn" onclick="rcExecConfirm()">Confirm</button>
        </div>
    </div>
</div>

<script>
/* ── Tab switching (remembers the tab across search / filter reloads) ── */
var RC_TABS=['users','pods','content','articles','appeals'];
function rcTab(name) {
    RC_TABS.forEach(function(n){
        document.getElementById('tab-'+n).classList.toggle('active', n===name);
        document.getElementById('section-'+n).style.display = n===name ? '' : 'none';
    });
    var f=document.getElementById('rcTabField'); if(f) f.value=name;
    try{ var u=new URL(window.location.href); u.searchParams.set('tab',name); window.history.replaceState({},'',u); }catch(e){}
}
(function(){
    var t=new URLSearchParams(window.location.search).get('tab');
    if(t && RC_TABS.indexOf(t)!==-1) rcTab(t);
})();

/* ── Status update ── */
function rcUpdateStatus(rtype, rid, status, rowId) {
    fetch(window.location.pathname, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'rc_action=update_status&rtype='+rtype+'&rid='+rid+'&status='+status
    }).then(function(r){return r.text();}).then(function(t){
        var d; try{ var i=t.indexOf('{'); d=JSON.parse(i>0?t.slice(i):t); }catch(e){ rcToast('Server error: '+t.replace(/<[^>]*>/g,' ').trim().slice(0,140)); return; }
        if(!d.ok){ rcToast(d.msg||'Could not update'); return; }
        var row=document.getElementById(rowId);
        if(row){
            var badgeCell=row.querySelector('td:nth-last-child(2)');
            if(badgeCell){
                var map={resolved:'#4dde8a ✅ Resolved',dismissed:'#888 🚫 Dismissed',pending:'#FF9900 ⏳ Pending',under_review:'#4499FF 🔍 Under Review'};
                var parts=(map[status]||'#aaa ?').split(' ');
                var color=parts.shift();
                var label=parts.join(' ');
                badgeCell.innerHTML="<span style='background:rgba(0,0,0,.3);border:1px solid "+color+";color:"+color+";padding:3px 10px;border-radius:999px;font-size:10px;font-weight:700;'>"+label+"</span>";
            }
        }
        rcToast('Status updated ✓');
    });
}

/* ── Confirm modal ── */
var _rcPendingAction=null;
var RC_WARN = <?= json_encode(array_map(function($v){ return ['l'=>$v[0],'t'=>$v[1]]; }, dz_warn_reasons()), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP) ?>;
var _rcWarnPayload=null;
function rcWarnOpen(payload, title){
    _rcWarnPayload = payload;
    document.getElementById('rcWarnTitle').textContent = title;
    var sel = document.getElementById('rcWarnReason'), keys = Object.keys(RC_WARN), pod = payload.rtype === 'pod';
    keys.sort(function(a,b){ var pa = a.indexOf('pod_')===0, pb = b.indexOf('pod_')===0; return pod ? (pb-pa) : (pa-pb); });
    sel.innerHTML = '';
    keys.forEach(function(k){ var o = document.createElement('option'); o.value = k; o.textContent = RC_WARN[k].l; sel.appendChild(o); });
    document.getElementById('rcWarnNote').value = '';
    rcWarnPrev();
    document.getElementById('rcWarnModal').classList.add('open');
}
function rcWarnPrev(){ var k = document.getElementById('rcWarnReason').value; document.getElementById('rcWarnPrev').textContent = RC_WARN[k] ? RC_WARN[k].t : ''; }
function rcWarnSend(){
    var p = _rcWarnPayload; if(!p) return;
    var btn = document.getElementById('rcWarnSend'); btn.disabled = true;
    var body = 'rc_action=warn_user&rtype=' + (p.rtype||'user') + '&rid=' + (p.rid||0) + '&uid=' + (p.uid||0) + (p.gid ? '&gid=' + p.gid : '')
        + '&reason=' + encodeURIComponent(document.getElementById('rcWarnReason').value) + '&note=' + encodeURIComponent(document.getElementById('rcWarnNote').value);
    fetch(window.location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(r){return r.text();})
        .then(function(t){
            btn.disabled = false;
            var d; try{ var i=t.indexOf('{'); d=JSON.parse(i>0?t.slice(i):t); }catch(e){ rcToast('Server error: '+t.replace(/<[^>]*>/g,' ').trim().slice(0,140)); return; }
            if(d.ok){ document.getElementById('rcWarnModal').classList.remove('open'); rcToast('Warning sent ✓'); }
            else rcToast(d.msg||'Something went wrong');
        }).catch(function(){ btn.disabled = false; rcToast('Request failed. Check your connection.'); });
}
function rcConfirm(action, payload, title, desc, btnLabel, color) {
    _rcPendingAction={action:action,payload:payload};
    document.getElementById('rcConfTitle').textContent=title;
    document.getElementById('rcConfDesc').textContent=desc;
    var btn=document.getElementById('rcConfOkBtn');
    btn.textContent=btnLabel;
    btn.className='rc-confirm-ok'+(color?' '+color:'');
    document.getElementById('rcConfirmModal').classList.add('open');
}
function rcExecConfirm() {
    if(!_rcPendingAction)return;
    document.getElementById('rcConfirmModal').classList.remove('open');
    var a=_rcPendingAction.action;
    var p=_rcPendingAction.payload;
    var body='rc_action='+a+'&rtype='+(p.rtype||'user')+'&rid='+(p.rid||0)+'&uid='+(p.uid||0);
    if(p.gid)   body+='&gid='+p.gid;
    if(p.cid)   body+='&cid='+p.cid;
    if(p.aid)   body+='&aid='+p.aid;
    if(p.ctype) body+='&ctype='+encodeURIComponent(p.ctype);
    fetch(window.location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(r){return r.text();})
        .then(function(t){
            var d; try{ var i=t.indexOf('{'); d=JSON.parse(i>0?t.slice(i):t); }catch(e){ rcToast('Server error: '+t.replace(/<[^>]*>/g,' ').trim().slice(0,140)); return; }
            if(d.ok){
                rcToast('Action completed ✓');
                if(d.reload||p.reload) setTimeout(function(){ window.location.reload(); },700);
            } else rcToast(d.msg||'Something went wrong');
        }).catch(function(){ rcToast('Request failed. Check your connection.'); });
    _rcPendingAction=null;
}
document.getElementById('rcConfirmModal').addEventListener('click',function(e){
    if(e.target===this)this.classList.remove('open');
});

/* ── Toast ── */
function rcToast(msg){
    var ex=document.getElementById('rcToastEl');if(ex)ex.remove();
    var t=document.createElement('div');t.id='rcToastEl';
    t.style.cssText='position:fixed;bottom:28px;left:50%;transform:translateX(-50%);'+
        'background:rgba(8,8,22,.97);border:1px solid rgba(77,222,138,.3);color:#fff;'+
        'padding:9px 24px;border-radius:50px;font-size:12px;z-index:99999999;pointer-events:none;'+
        'box-shadow:0 8px 24px rgba(0,0,0,.6);white-space:nowrap;'+
        'animation:rcToastIn .22s ease forwards;';
    t.textContent=msg;document.body.appendChild(t);
    setTimeout(function(){t.style.opacity='0';t.style.transition='opacity .2s';
        setTimeout(function(){t.remove();},220);},2600);
}
</script>
<style>
@keyframes rcToastIn{from{opacity:0;transform:translateX(-50%) translateY(8px);}to{opacity:1;transform:translateX(-50%) translateY(0);}}
</style>
</body>
</html>