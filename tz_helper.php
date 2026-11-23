<?php
/* ══════════════════════════════════════════════════════════════
   tz_helper.php: the person's own time zone (Settings > Time zone).
   Include it with:  require_once __DIR__ . '/tz_helper.php';

   dz_user_tz_name($conn, $uid)   the saved time zone name, or '' if they never picked one
   dz_local_date($conn, $uid)     their "today" as Y-m-d (time_ping.php uses this)
   dz_set_day_vars($conn, $uid)   sets the MySQL variables @dz_today, @dz_start, @dz_end
                                  so queries can use the person's real day (see notes in the reply)
══════════════════════════════════════════════════════════════ */
if (!function_exists('dz_user_tz_name')) {
    function dz_user_tz_name($conn, $uid) {
        $uid = (int)$uid;
        if (isset($_SESSION['dz_tz']) && is_array($_SESSION['dz_tz']) && (int)($_SESSION['dz_tz']['u'] ?? 0) === $uid) {
            return (string)$_SESSION['dz_tz']['tz'];
        }
        $name = '';
        try {
            $st = $conn->prepare("SELECT timezone FROM user_settings WHERE user_id = ? LIMIT 1");
            $st->bind_param('i', $uid); $st->execute();
            $r = $st->get_result()->fetch_assoc(); $st->close();
            $name = trim((string)($r['timezone'] ?? ''));
        } catch (Throwable $e) { /* column not created yet */ }
        if ($name !== '' && !in_array($name, DateTimeZone::listIdentifiers(), true)) $name = '';
        $_SESSION['dz_tz'] = ['u' => $uid, 'tz' => $name];
        return $name;
    }
}
if (!function_exists('dz_local_date')) {
    function dz_local_date($conn, $uid) {
        $name = dz_user_tz_name($conn, $uid);
        if ($name !== '') return (new DateTime('now', new DateTimeZone($name)))->format('Y-m-d');
        try { $q = $conn->query("SELECT CURDATE() AS d"); if ($q && ($r = $q->fetch_assoc())) return (string)$r['d']; } catch (Throwable $e) {}
        return date('Y-m-d');
    }
}
if (!function_exists('dz_set_day_vars')) {
    function dz_set_day_vars($conn, $uid) {
        $name = dz_user_tz_name($conn, $uid);
        if ($name === '') {   // no time zone chosen: behave exactly like CURDATE()
            $conn->query("SET @dz_today = CURDATE(), @dz_start = CURDATE(), @dz_end = (CURDATE() + INTERVAL 1 DAY)");
            return;
        }
        $tz  = new DateTimeZone($name);
        $mid = new DateTime('today', $tz);                       // 00:00 today, in the person's time zone
        $off = 0;                                                // seconds the database clock is ahead of UTC
        try { $q = $conn->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS o"); if ($q && ($r = $q->fetch_assoc())) $off = (int)round(((int)$r['o']) / 900) * 900; } catch (Throwable $e) {}
        $start = clone $mid; $start->setTimezone(new DateTimeZone('UTC')); $start->modify(($off >= 0 ? '+' : '') . $off . ' seconds');
        $end   = clone $start; $end->modify('+86400 seconds');
        $conn->query("SET @dz_today = '" . $mid->format('Y-m-d') . "', @dz_start = '" . $start->format('Y-m-d H:i:s') . "', @dz_end = '" . $end->format('Y-m-d H:i:s') . "'");
    }
}