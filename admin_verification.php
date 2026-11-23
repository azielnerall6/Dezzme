<?php
/* ══════════════════════════════════════════════════════════════
   admin_verification.php – review verification requests
   Admins only (users.is_admin = 1). Approve sets users.is_verified = 1.
   ID photos / selfies are served through this page only and deleted after a decision.
══════════════════════════════════════════════════════════════ */
$IS_HTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $IS_HTTPS, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
include 'config.php';
require_once __DIR__ . '/verification_common.php';
require_once __DIR__ . '/verified_badge.php';

$me = (int)($_SESSION['user_id'] ?? 0);
if ($me <= 0) { header('Location: login.php'); exit; }
$st = $conn->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
$st->bind_param('i', $me); $st->execute();
$adm = $st->get_result()->fetch_assoc(); $st->close();
if (!$adm || (int)$adm['is_admin'] !== 1) { http_response_code(403); echo 'Forbidden'; exit; }

vf_ensure_schema($conn);
vf_sweep_username_changes($conn, true);
if (empty($_SESSION['st_csrf']) || !is_string($_SESSION['st_csrf'])) $_SESSION['st_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['st_csrf'];

/* ───────── secure file viewer: ?file=id|selfie|receipt&rid=N ───────── */
if (isset($_GET['file'])) {
    $cols = ['id' => 'id_file', 'selfie' => 'selfie_file', 'receipt' => 'receipt_file'];
    $col = $cols[$_GET['file']] ?? null; $rid = (int)($_GET['rid'] ?? 0);
    if (!$col || $rid <= 0) { http_response_code(404); exit; }
    $st = $conn->prepare("SELECT $col AS f FROM verification_requests WHERE id = ? LIMIT 1");
    $st->bind_param('i', $rid); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    $f = (string)($row['f'] ?? '');
    $path = VF_DIR . $f;
    if (!preg_match('/^[a-z]+_[a-f0-9]{24}\.(jpg|png|webp|pdf)$/', $f) || !is_file($path)) { http_response_code(404); echo 'File not available (it may have been deleted after review).'; exit; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $f . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    readfile($path); exit;
}

$nonce = base64_encode(random_bytes(16));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

/* ───────── actions (POST → redirect) ───────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf_token'] ?? '';
    $sfs  = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if (!is_string($sent) || !hash_equals($csrf, $sent) || ($sfs !== '' && !in_array($sfs, ['same-origin', 'none'], true))) { http_response_code(403); echo 'Expired. Reload and try again.'; exit; }
    $rid = (int)($_POST['rid'] ?? 0);
    $act = $_POST['action'] ?? '';
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);
    $msg = 'Nothing changed.';

    if ($act === 'revoke_user') {            /* from the Verified accounts tab (works even without a request) */
        $uid = (int)($_POST['uid'] ?? 0);
        $_SESSION['vf_flash'] = ($uid > 0 && vf_revoke_user($conn, $uid, $reason !== '' ? $reason : 'Your badge was removed by an admin.'))
            ? 'Badge revoked. The user can apply again.' : 'Could not revoke that badge.';
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#')); exit;
    }

    $st = $conn->prepare("SELECT * FROM verification_requests WHERE id = ? LIMIT 1");
    $st->bind_param('i', $rid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();

    if ($r) {
        try {
            $conn->begin_transaction();
            $uid = (int)$r['user_id'];
            if ($act === 'approve' && $r['status'] === 'pending') {
                $exp = date('Y-m-d H:i:s', strtotime('+30 days'));
                $u = $conn->prepare("UPDATE verification_requests SET status='approved', reviewed_at=NOW(), reviewed_by=?, plan_expires_at=?, reject_reason=NULL, id_file=NULL, selfie_file=NULL WHERE id=? AND status='pending'");
                $u->bind_param('isi', $me, $exp, $rid); $u->execute(); $u->close();
                $u = $conn->prepare("UPDATE users SET is_verified=1 WHERE id=?");
                $u->bind_param('i', $uid); $u->execute(); $u->close();
                $conn->commit();
                vf_delete_file($r['id_file']); vf_delete_file($r['selfie_file']);
                $msg = 'Approved. @' . ($_POST['uname'] ?? '') . ' is now verified.';
            } elseif ($act === 'reject' && $r['status'] === 'pending') {
                if ($reason === '') { $conn->rollback(); $_SESSION['vf_flash'] = 'Add a reason so the user knows what to fix.'; header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
                $u = $conn->prepare("UPDATE verification_requests SET status='rejected', reviewed_at=NOW(), reviewed_by=?, reject_reason=?, id_file=NULL, selfie_file=NULL WHERE id=? AND status='pending'");
                $u->bind_param('isi', $me, $reason, $rid); $u->execute(); $u->close();
                $conn->commit();
                vf_delete_file($r['id_file']); vf_delete_file($r['selfie_file']);
                $msg = 'Rejected. The user can see your reason and apply again.';
            } elseif ($act === 'revoke' && $r['status'] === 'approved') {
                $u = $conn->prepare("UPDATE verification_requests SET status='revoked', reviewed_at=NOW(), reviewed_by=?, reject_reason=? WHERE id=? AND status='approved'");
                $u->bind_param('isi', $me, $reason, $rid); $u->execute(); $u->close();
                $u = $conn->prepare("UPDATE users SET is_verified=0 WHERE id=?");
                $u->bind_param('i', $uid); $u->execute(); $u->close();
                $conn->commit();
                $msg = 'Badge revoked.';
            } else { $conn->rollback(); }
        } catch (Throwable $e) { try { $conn->rollback(); } catch (Throwable $e2) {} $msg = 'Something went wrong. Nothing was changed.'; }
    }
    $_SESSION['vf_flash'] = $msg;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#')); exit;
}

/* ───────── list ───────── */
$tabs = ['pending' => 'Pending', 'verified' => 'Verified accounts', 'rejected' => 'Rejected', 'revoked' => 'Revoked'];
$tab = $_GET['tab'] ?? 'pending'; if (!isset($tabs[$tab])) $tab = 'pending';
$q = trim((string)($_GET['q'] ?? ''));

$counts = array_fill_keys(array_keys($tabs), 0);
$res = $conn->query("SELECT status, COUNT(*) c FROM verification_requests GROUP BY status");
if ($res) while ($c = $res->fetch_assoc()) if (isset($counts[$c['status']])) $counts[$c['status']] = (int)$c['c'];

$vc = $conn->query("SELECT COUNT(*) c FROM users WHERE is_verified = 1");
if ($vc) $counts['verified'] = (int)$vc->fetch_assoc()['c'];

if ($tab === 'verified') {
    /* every account that currently has the badge, including ones verified by hand */
    $sql = "SELECT u.id AS uid, u.username, u.dz_id, u.email, u.profile_pic, u.created_at AS joined, u.username_changed_at,
                   r.id AS rid, r.plan, r.amount, r.full_name, r.reviewed_at, r.plan_expires_at
            FROM users u
            LEFT JOIN verification_requests r ON r.id = (SELECT MAX(id) FROM verification_requests WHERE user_id = u.id AND status = 'approved')
            WHERE u.is_verified = 1";
    $types = ''; $args = [];
    if ($q !== '') { $sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.dz_id LIKE ? OR r.full_name LIKE ?)"; $like = '%' . $q . '%'; $types = 'ssss'; $args = [$like, $like, $like, $like]; }
    $sql .= " ORDER BY u.username ASC LIMIT 200";
    $st = $conn->prepare($sql); if ($types !== '') $st->bind_param($types, ...$args); $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
} else {
$sql = "SELECT r.*, u.username, u.dz_id, u.email, u.profile_pic, u.created_at AS joined FROM verification_requests r JOIN users u ON u.id = r.user_id WHERE r.status = ?";
$types = 's'; $args = [$tab];
if ($q !== '') { $sql .= " AND (u.username LIKE ? OR r.full_name LIKE ? OR r.payment_ref LIKE ? OR u.email LIKE ?)"; $like = '%' . $q . '%'; $types .= 'ssss'; array_push($args, $like, $like, $like, $like); }
$sql .= " ORDER BY r.id " . ($tab === 'pending' ? 'ASC' : 'DESC') . " LIMIT 100";
$st = $conn->prepare($sql); $st->bind_param($types, ...$args); $st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}

$flash = $_SESSION['vf_flash'] ?? ''; unset($_SESSION['vf_flash']);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$isImg = fn($f) => (bool)preg_match('/\.(jpg|png|webp)$/', (string)$f);
function doc($label, $kind, $rid, $file, $isImg) {
    global $h;
    if (!$file) return '<div class="doc gone"><b>' . $h($label) . '</b><span>Deleted after review</span></div>';
    $url = '?file=' . $kind . '&rid=' . (int)$rid;
    return '<a class="doc" href="' . $h($url) . '" target="_blank" rel="noopener"><b>' . $h($label) . '</b>' .
        ($isImg($file) ? '<img src="' . $h($url) . '" alt="" loading="lazy">' : '<span>Open PDF</span>') . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Dezzme Admin · Verification</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style nonce="<?= $nonce ?>">
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{color-scheme:dark;--accent:#FF2D2D;--fg:255,255,255;--text:#fff;--sub:rgba(var(--fg),.64);--muted:rgba(var(--fg),.45);--surface:rgba(var(--fg),.05);--line:rgba(var(--fg),.12);--ok:#4ade80;--warn:#ffb84d;--bg:#070924;--r:10px}
body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text);font-size:14px;min-height:100vh;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.wrap{max-width:1000px;margin:0 auto;padding:22px 16px 60px}
header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}
h1{font-size:1.3rem}
.tabs{display:flex;gap:6px;flex-wrap:wrap}
.tabs a{padding:7px 14px;border-radius:99px;border:1px solid var(--line);font-size:.78rem;font-weight:600;color:var(--sub)}
.tabs a.on{background:var(--accent);border-color:var(--accent);color:#fff}
.tabs a span{opacity:.75;margin-left:4px}
form.search{display:flex;gap:8px;margin:14px 0}
input[type=text],textarea{width:100%;padding:10px 12px;border-radius:var(--r);border:1px solid var(--line);background:var(--surface);color:var(--text);font:inherit;font-size:.82rem;outline:none}
input:focus,textarea:focus{border-color:var(--accent)}
button{font:inherit;cursor:pointer;border:none;border-radius:var(--r);padding:10px 16px;font-size:.8rem;font-weight:700;color:#fff;background:var(--accent)}
button.ok{background:#16a34a}button.ghost{background:var(--surface);border:1px solid var(--line);color:var(--text)}
.flash{padding:11px 14px;border-radius:var(--r);background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.4);margin-bottom:14px;font-size:.82rem}
.empty{padding:50px 10px;text-align:center;color:var(--muted)}
.req{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:16px;margin-bottom:14px}
.top{display:flex;gap:12px;align-items:center;margin-bottom:12px}
.top img{width:48px;height:48px;border-radius:50%;object-fit:cover;background:#15171f}
.top b{display:block;font-size:.95rem}.top small{color:var(--sub);font-size:.74rem}
.tag{margin-left:auto;padding:3px 10px;border-radius:99px;font-size:.68rem;font-weight:700;background:rgba(255,184,77,.15);color:var(--warn);white-space:nowrap}
.tag.approved{background:rgba(74,222,128,.15);color:var(--ok)}.tag.rejected,.tag.revoked{background:rgba(255,45,45,.15);color:#ff8a8a}
dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 18px;margin-bottom:14px}
dt{font-size:.66rem;text-transform:uppercase;letter-spacing:1px;color:var(--muted)}
dd{font-size:.84rem;word-break:break-word}
.check{padding:10px 12px;border-radius:var(--r);background:rgba(255,184,77,.1);border:1px solid rgba(255,184,77,.35);font-size:.76rem;line-height:1.6;margin-bottom:14px}
.docs{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}
.doc{display:flex;flex-direction:column;gap:6px;padding:8px;border:1px solid var(--line);border-radius:var(--r);background:rgba(0,0,0,.2)}
.doc b{font-size:.7rem;color:var(--sub)}.doc span{font-size:.78rem;color:var(--muted);padding:30px 0;text-align:center}
.doc img{width:100%;height:160px;object-fit:cover;border-radius:6px}
.doc.gone{opacity:.6}
.acts{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start}
.acts form{display:flex;gap:8px;flex:1 1 260px}
.acts form.rej{flex:2 1 320px}
</style>
</head>
<body>
<?= dz_badge_sprite() ?>
<div class="wrap">
  <header>
    <h1>Verification requests</h1>
    <nav class="tabs">
      <?php foreach ($tabs as $k => $label): ?>
        <a class="<?= $k === $tab ? 'on' : '' ?>" href="?tab=<?= $k ?>"><?= $h($label) ?><span><?= $counts[$k] ?></span></a>
      <?php endforeach; ?>
    </nav>
  </header>

  <?php if ($flash): ?><div class="flash"><?= $h($flash) ?></div><?php endif; ?>

  <form class="search" method="get">
    <input type="hidden" name="tab" value="<?= $h($tab) ?>">
    <input type="text" name="q" value="<?= $h($q) ?>" placeholder="Search username, full name, email or reference">
    <button class="ghost" type="submit">Search</button>
  </form>

  <?php if (!$rows): ?><div class="empty">No <?= $h(strtolower($tabs[$tab])) ?> requests<?= $q !== '' ? ' match your search' : '' ?>.</div><?php endif; ?>

  <?php if ($tab === 'verified'): foreach ($rows as $r): ?>
  <article class="req">
    <div class="top">
      <img src="<?= $h($r['profile_pic'] ?: 'uploads/default.jpg') ?>" alt="">
      <div><b>@<?= $h($r['username']) ?> <?= dz_badge('f') ?></b><small><?= $h($r['dz_id']) ?> · <?= $h($r['email']) ?></small></div>
      <span class="tag approved">Verified</span>
    </div>
    <dl>
      <div><dt>Verified</dt><dd><?= $r['reviewed_at'] ? $h(date('j M Y', strtotime($r['reviewed_at']))) : 'Added manually (no request on file)' ?></dd></div>
      <?php if ($r['full_name']): ?><div><dt>Legal name</dt><dd><?= $h($r['full_name']) ?></dd></div><?php endif; ?>
      <?php if ($r['plan']): ?><div><dt>Plan</dt><dd><?= $h(ucfirst($r['plan'])) ?> · NGN<?= number_format((int)$r['amount']) ?></dd></div><?php endif; ?>
      <?php if ($r['plan_expires_at']): ?><div><dt>Renews / expires</dt><dd><?= $h(date('j M Y', strtotime($r['plan_expires_at']))) ?></dd></div><?php endif; ?>
      <div><dt>Joined</dt><dd><?= $h(date('j M Y', strtotime($r['joined']))) ?></dd></div>
      <?php if ($r['username_changed_at']): ?><div><dt>Username last changed</dt><dd><?= $h(date('j M Y', strtotime($r['username_changed_at']))) ?></dd></div><?php endif; ?>
    </dl>
    <div class="acts">
      <form method="post" class="rej" data-confirm="Revoke @<?= $h($r['username']) ?>'s verified badge? They will have to apply again.">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="uid" value="<?= (int)$r['uid'] ?>"><input type="hidden" name="action" value="revoke_user">
        <input type="text" name="reason" maxlength="255" placeholder="Reason shown to the user (optional)">
        <button type="submit">Revoke badge</button>
      </form>
    </div>
  </article>
  <?php endforeach; else: foreach ($rows as $r): $plan = VF_PLANS[$r['plan']] ?? ['name' => ucfirst($r['plan']), 'price' => (int)$r['amount']]; ?>
  <article class="req">
    <div class="top">
      <img src="<?= $h($r['profile_pic'] ?: 'uploads/default.jpg') ?>" alt="">
      <div><b>@<?= $h($r['username']) ?></b><small><?= $h($r['dz_id']) ?> · <?= $h($r['email']) ?></small></div>
      <span class="tag <?= $h($r['status']) ?>"><?= $h(ucfirst($r['status'])) ?></span>
    </div>

    <dl>
      <div><dt>Legal name</dt><dd><?= $h($r['full_name']) ?></dd></div>
      <div><dt>Phone</dt><dd><?= $h($r['phone']) ?></dd></div>
      <div><dt>ID type</dt><dd><?= $h(VF_ID_TYPES[$r['id_type']] ?? $r['id_type']) ?></dd></div>
      <div><dt>Plan</dt><dd><?= $h($plan['name']) ?> · NGN<?= number_format((int)$r['amount'] ?: $plan['price']) ?></dd></div>
      <div><dt>Payer name</dt><dd><?= $h($r['payer_name']) ?></dd></div>
      <div><dt>Reference</dt><dd><?= $h($r['payment_ref']) ?></dd></div>
      <div><dt>Submitted</dt><dd><?= $h(date('j M Y, g:ia', strtotime($r['created_at']))) ?></dd></div>
      <?php if ($r['status'] === 'approved' && $r['plan_expires_at']): ?><div><dt>Renews / expires</dt><dd><?= $h(date('j M Y', strtotime($r['plan_expires_at']))) ?></dd></div><?php endif; ?>
      <?php if ($r['reject_reason']): ?><div><dt>Reason</dt><dd><?= $h($r['reject_reason']) ?></dd></div><?php endif; ?>
    </dl>

    <?php if ($r['status'] === 'pending'): ?>
    <div class="check"><b>Before approving, check:</b> the ID name matches “<?= $h($r['full_name']) ?>” · the selfie matches the ID · the receipt shows NGN<?= number_format((int)$r['amount'] ?: $plan['price']) ?> sent to the Dezzme account, to the right reference · the payer name looks right · the profile isn't impersonating anyone.</div>
    <?php endif; ?>

    <div class="docs">
      <?= doc('ID document', 'id', $r['id'], $r['id_file'], $isImg) ?>
      <?= doc('Selfie', 'selfie', $r['id'], $r['selfie_file'], $isImg) ?>
      <?= doc('Payment receipt', 'receipt', $r['id'], $r['receipt_file'], $isImg) ?>
    </div>

    <?php if ($r['status'] === 'pending'): ?>
    <div class="acts">
      <form method="post" data-confirm="Approve @<?= $h($r['username']) ?> and give them the verified badge?">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="uname" value="<?= $h($r['username']) ?>"><input type="hidden" name="action" value="approve">
        <button class="ok" type="submit">Approve &amp; verify</button>
      </form>
      <form method="post" class="rej">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="reject">
        <input type="text" name="reason" maxlength="255" placeholder="Reason shown to the user (required)" required>
        <button type="submit">Reject</button>
      </form>
    </div>
    <?php elseif ($r['status'] === 'approved'): ?>
    <div class="acts">
      <form method="post" class="rej" data-confirm="Revoke @<?= $h($r['username']) ?>'s verified badge?">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="revoke">
        <input type="text" name="reason" maxlength="255" placeholder="Reason for revoking (optional)">
        <button type="submit">Revoke badge</button>
      </form>
    </div>
    <?php endif; ?>
  </article>
  <?php endforeach; endif; ?>
</div>
<script nonce="<?= $nonce ?>">
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
});
</script>
</body>
</html>