<?php
/* ══════════════════════════════════════════════════════════════
   verification_common.php – shared by profile_verified.php and admin_verification.php
   EDIT THE BANK DETAILS BELOW before going live.
══════════════════════════════════════════════════════════════ */

const VF_PLANS = [
    'standard' => ['name' => 'Standard', 'price' => 2000],
    'plus'     => ['name' => 'Plus',     'price' => 4500],
    'elite'    => ['name' => 'Elite',    'price' => 9000],
];
const VF_ID_TYPES = [
    'nin'             => 'National ID (NIN slip or card)',
    'passport'        => 'International passport',
    'drivers_license' => "Driver's licence",
    'voters_card'     => "Voter's card",
];
/* where users send their payment */
const VF_PAY = ['bank' => 'YOUR BANK NAME', 'account_name' => 'DEZZME LIMITED', 'account_number' => '0000000000'];

/* Private upload folder. Best practice: move this OUTSIDE your web root (e.g. dirname(__DIR__).'/private/verification/').
   A .htaccess deny-all file is created automatically as a fallback. */
const VF_DIR = __DIR__ . '/private_uploads/verification/';
const VF_MAX = 5 * 1024 * 1024;

function vf_ensure_schema(mysqli $conn): void {
    if (!empty($_SESSION['vf_schema_v2'])) return;
    $conn->query("CREATE TABLE IF NOT EXISTS verification_requests (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        plan ENUM('standard','plus','elite') NOT NULL,
        payment_method VARCHAR(20) NOT NULL DEFAULT 'transfer',
        age_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('pending','approved','rejected','cancelled','revoked') NOT NULL DEFAULT 'pending',
        trial_ends_at DATETIME NULL, reviewed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (user_id, status))");
    /* upgrades an older table in place (MariaDB: ADD COLUMN IF NOT EXISTS) */
    $cols = [
        "full_name VARCHAR(120) NULL", "phone VARCHAR(20) NULL", "id_type VARCHAR(30) NULL",
        "id_file VARCHAR(80) NULL", "selfie_file VARCHAR(80) NULL", "receipt_file VARCHAR(80) NULL",
        "payer_name VARCHAR(120) NULL", "payment_ref VARCHAR(30) NULL", "amount INT UNSIGNED NOT NULL DEFAULT 0",
        "reject_reason VARCHAR(255) NULL", "reviewed_by INT UNSIGNED NULL", "plan_expires_at DATETIME NULL",
    ];
    foreach ($cols as $c) { try { $conn->query("ALTER TABLE verification_requests ADD COLUMN IF NOT EXISTS $c"); } catch (Throwable $e) {} }
    foreach ([
        "MODIFY trial_ends_at DATETIME NULL",
        "MODIFY payment_method VARCHAR(20) NOT NULL DEFAULT 'transfer'",
        "MODIFY status ENUM('pending','approved','rejected','cancelled','revoked') NOT NULL DEFAULT 'pending'",
    ] as $m) { try { $conn->query("ALTER TABLE verification_requests $m"); } catch (Throwable $e) {} }
    $_SESSION['vf_schema_v2'] = 1;
}

/* default avatars don't count as "has a profile picture" */
function vf_has_pic($pic): bool {
    $pic = trim((string)$pic);
    return $pic !== '' && !preg_match('#(^|/)(me|default)\.(jpe?g|png|webp)$#i', $pic);
}

/* Validates (real MIME type, size) and saves one uploaded file. Returns the stored file name or null with $err set. */
function vf_save_upload(string $field, bool $allowPdf, string $prefix, ?string &$err): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) { $err = 'A required file is missing.'; return null; }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $err = 'A file could not be uploaded. Try again.'; return null; }
    if ($f['size'] > VF_MAX) { $err = 'Each file must be 5 MB or smaller.'; return null; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if ($allowPdf) $map['application/pdf'] = 'pdf';
    if (!isset($map[$mime])) { $err = $allowPdf ? 'Files must be JPG, PNG, WebP or PDF.' : 'Photos must be JPG, PNG or WebP.'; return null; }
    if (!is_dir(VF_DIR) && !@mkdir(VF_DIR, 0755, true)) { $err = 'Server storage is not available.'; return null; }
    if (!file_exists(VF_DIR . '.htaccess')) @file_put_contents(VF_DIR . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    $name = $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $map[$mime];
    if (!move_uploaded_file($f['tmp_name'], VF_DIR . $name)) { $err = 'Could not save the file.'; return null; }
    return $name;
}

function vf_delete_file($name): void {
    if (is_string($name) && preg_match('/^[a-z]+_[a-f0-9]{24}\.(jpg|png|webp|pdf)$/', $name)) @unlink(VF_DIR . $name);
}

/* Removes a user's badge and marks their approved request(s) as revoked. Works for users verified by hand too. */
function vf_revoke_user(mysqli $conn, int $uid, string $reason): bool {
    $reason = mb_substr($reason, 0, 255);
    try {
        $conn->begin_transaction();
        $u = $conn->prepare("UPDATE users SET is_verified = 0 WHERE id = ?");
        $u->bind_param('i', $uid); $u->execute(); $u->close();
        $u = $conn->prepare("UPDATE verification_requests SET status='revoked', reviewed_at=NOW(), reject_reason=? WHERE user_id=? AND status='approved'");
        $u->bind_param('si', $reason, $uid); $u->execute(); $u->close();
        $conn->commit();
        return true;
    } catch (Throwable $e) { try { $conn->rollback(); } catch (Throwable $e2) {} return false; }
}

/* CALL THIS right after a username is changed successfully (in setting.php):
       require_once __DIR__ . '/verification_common.php';
       vf_on_username_change($conn, $user_id);
   Badge is removed at once and the user has to apply again. */
function vf_on_username_change(mysqli $conn, int $uid): void {
    try {
        $st = $conn->prepare("SELECT is_verified FROM users WHERE id = ? LIMIT 1");
        $st->bind_param('i', $uid); $st->execute();
        $r = $st->get_result()->fetch_assoc(); $st->close();
        if ($r && (int)$r['is_verified'] === 1) vf_revoke_user($conn, $uid, 'You changed your username, so your badge was removed. Apply again to get verified.');
    } catch (Throwable $e) {}
}

/* Safety net: removes the badge from anyone whose username changed (users.username_changed_at) AFTER they were approved.
   Throttled to once per 5 minutes per session unless $force. Silent if the verification table is missing. */
function vf_sweep_username_changes(mysqli $conn, bool $force = false): void {
    if (!$force) {
        $now = time();
        if (isset($_SESSION['vf_sweep_at']) && $now - (int)$_SESSION['vf_sweep_at'] < 300) return;
        $_SESSION['vf_sweep_at'] = $now;
    }
    try {
        $res = $conn->query("SELECT DISTINCT r.user_id FROM verification_requests r JOIN users u ON u.id = r.user_id
            WHERE r.status='approved' AND u.is_verified=1 AND r.reviewed_at IS NOT NULL
              AND u.username_changed_at IS NOT NULL AND u.username_changed_at > r.reviewed_at");
        if ($res) while ($x = $res->fetch_assoc()) vf_revoke_user($conn, (int)$x['user_id'], 'You changed your username, so your badge was removed. Apply again to get verified.');
    } catch (Throwable $e) {}
}