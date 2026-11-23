<?php
/* ══════════════════════════════════════════════════════════════════
   JOIN QUESTIONS HELPER  (include this in group_controls.php AND group.php)
   - Owner sets up to 10 optional questions (group_controls.php → Group Privacy).
   - Requesting members answer them on group.php; answers show in Join Requests.
   ══════════════════════════════════════════════════════════════════ */
if (!defined('JQ_MAX')) define('JQ_MAX', 10);
if (!defined('JQ_Q_LEN')) define('JQ_Q_LEN', 200);
if (!defined('JQ_A_LEN')) define('JQ_A_LEN', 500);

function jq_clean($s, $max) {
    $s = trim(strip_tags(str_replace(["\r\n", "\r"], "\n", (string)$s)));
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

// Creates the two tables once per login session.
function jq_ensure_schema($conn) {
    if (!empty($_SESSION['jq_schema_v1'])) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS group_join_questions (
            id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL,
            question VARCHAR(200) NOT NULL, is_required TINYINT(1) NOT NULL DEFAULT 0,
            sort_order TINYINT NOT NULL DEFAULT 0, INDEX (group_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS group_join_answers (
            id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, user_id INT NOT NULL,
            question_id INT NULL, question_text VARCHAR(200) NOT NULL, answer TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (group_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_SESSION['jq_schema_v1'] = 1;
    } catch (Throwable $e) { /* no CREATE permission: run the SQL by hand */ }
}

// [ ['id'=>1,'question'=>'...','is_required'=>0], ... ] in order
function jq_fetch($conn, $gid) {
    $rows = [];
    try {
        $st = $conn->prepare("SELECT id, question, is_required FROM group_join_questions WHERE group_id=? ORDER BY sort_order ASC, id ASC");
        $st->bind_param("i", $gid); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = ['id' => (int)$r['id'], 'question' => $r['question'], 'is_required' => (int)$r['is_required']];
        $st->close();
    } catch (Throwable $e) {}
    return $rows;
}

// Owner saves the whole list. $raw = JSON: [{"q":"...","req":0|1}, ...]
function jq_save($conn, $gid, $raw) {
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) return ['ok' => false, 'msg' => 'Those questions could not be read.'];
    $out = [];
    foreach ($d as $it) {
        $q = jq_clean(is_array($it) ? ($it['q'] ?? '') : '', JQ_Q_LEN);
        if ($q === '') continue;
        $out[] = ['q' => $q, 'req' => (is_array($it) && !empty($it['req'])) ? 1 : 0];
    }
    if (count($out) > JQ_MAX) return ['ok' => false, 'msg' => 'You can set up to ' . JQ_MAX . ' questions.'];
    try {
        $st = $conn->prepare("DELETE FROM group_join_questions WHERE group_id=?");
        $st->bind_param("i", $gid); $st->execute(); $st->close();
        $st = $conn->prepare("INSERT INTO group_join_questions (group_id, question, is_required, sort_order) VALUES (?,?,?,?)");
        foreach ($out as $i => $o) { $st->bind_param("isii", $gid, $o['q'], $o['req'], $i); $st->execute(); }
        $st->close();
    } catch (Throwable $e) { return ['ok' => false, 'msg' => 'Could not save the questions.']; }
    return ['ok' => true, 'count' => count($out)];
}

// Used by group.php when a member submits a join request.
// $answers = [ question_id => 'text', ... ]  (e.g. $_POST['jq'])
function jq_submit_answers($conn, $gid, $uid, $answers) {
    $qs = jq_fetch($conn, $gid);
    if (!$qs) return ['ok' => true, 'saved' => 0];
    $answers = is_array($answers) ? $answers : [];
    $clean = [];
    foreach ($qs as $q) {
        $a = jq_clean($answers[$q['id']] ?? '', JQ_A_LEN);
        if ($a === '' && $q['is_required']) return ['ok' => false, 'msg' => 'Please answer: ' . $q['question']];
        if ($a !== '') $clean[] = ['id' => $q['id'], 'q' => $q['question'], 'a' => $a];
    }
    try {
        $st = $conn->prepare("DELETE FROM group_join_answers WHERE group_id=? AND user_id=?");
        $st->bind_param("ii", $gid, $uid); $st->execute(); $st->close();
        $st = $conn->prepare("INSERT INTO group_join_answers (group_id, user_id, question_id, question_text, answer) VALUES (?,?,?,?,?)");
        foreach ($clean as $c) { $st->bind_param("iiiss", $gid, $uid, $c['id'], $c['q'], $c['a']); $st->execute(); }
        $st->close();
    } catch (Throwable $e) { return ['ok' => false, 'msg' => 'Could not save your answers.']; }
    return ['ok' => true, 'saved' => count($clean)];
}

// Last problem hit while reading answers (shown in Join Requests when something is wrong)
$GLOBALS['jq_last_error'] = '';

function jq_cols($conn, $table) {
    $cols = [];
    try {
        $r = $conn->query("SHOW COLUMNS FROM `$table`");
        if ($r) while ($c = $r->fetch_assoc()) $cols[] = $c['Field'];
    } catch (Throwable $e) {}
    return $cols;
}

// [ user_id => [ ['q'=>..., 'a'=>...], ... ] ] for every user who answered in this group.
// Tolerant of an older/different group_join_answers table (other column names).
function jq_answers_for($conn, $gid) {
    $map = [];
    try {
        $cols = jq_cols($conn, 'group_join_answers');
        if (!$cols) { $GLOBALS['jq_last_error'] = 'The answers table does not exist yet.'; return $map; }
        $has = function ($c) use ($cols) { return in_array($c, $cols, true); };
        $ansCol = $has('answer') ? 'answer' : ($has('answer_text') ? 'answer_text' : ($has('response') ? 'response' : ($has('answers') ? 'answers' : '')));
        $uidCol = $has('user_id') ? 'user_id' : ($has('member_id') ? 'member_id' : ($has('uid') ? 'uid' : ''));
        if ($ansCol === '' || $uidCol === '' || !$has('group_id')) {
            $GLOBALS['jq_last_error'] = 'The answers table has different columns than expected (' . implode(', ', $cols) . ').';
            return $map;
        }
        $qExpr = "''"; $join = '';
        if ($has('question_text') && $has('question_id')) { $qExpr = "COALESCE(NULLIF(a.question_text,''), q.question, '')"; $join = "LEFT JOIN group_join_questions q ON q.id=a.question_id"; }
        elseif ($has('question_text')) { $qExpr = "a.question_text"; }
        elseif ($has('question_id'))   { $qExpr = "COALESCE(q.question, '')"; $join = "LEFT JOIN group_join_questions q ON q.id=a.question_id"; }
        elseif ($has('question'))      { $qExpr = "a.question"; }
        $order = $has('id') ? 'a.id ASC' : 'a.' . $uidCol . ' ASC';
        $sql = "SELECT a.$uidCol AS uid, $qExpr AS qtxt, a.$ansCol AS ans FROM group_join_answers a $join WHERE a.group_id=? ORDER BY $order";
        $st = $conn->prepare($sql);
        if (!$st) { $GLOBALS['jq_last_error'] = 'Could not read answers: ' . $conn->error; return $map; }
        $st->bind_param("i", $gid); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            if (trim((string)$r['ans']) === '') continue;
            $map[(int)$r['uid']][] = ['q' => ($r['qtxt'] !== '' ? $r['qtxt'] : 'Question'), 'a' => $r['ans']];
        }
        $st->close();
    } catch (Throwable $e) { $GLOBALS['jq_last_error'] = 'Could not read answers: ' . $e->getMessage(); }
    return $map;
}

// Optional: clear a person's answers after the request is approved/denied.
function jq_clear_answers($conn, $gid, $uid) {
    try {
        $st = $conn->prepare("DELETE FROM group_join_answers WHERE group_id=? AND user_id=?");
        $st->bind_param("ii", $gid, $uid); $st->execute(); $st->close();
    } catch (Throwable $e) {}
}