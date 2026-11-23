<?php
/* Dezzme account / pod warnings — shared by report_center.php (admin sends), profile_modals.php and group_modals.php (user must acknowledge).
   Table is created on first use. */
if (!function_exists('dz_warn_reasons')) {

function dz_warn_reasons(){
    /* key => [short label, what the person sees] */
    return [
        'harassment'      => ['Harassment or bullying',        'Content or behaviour directed at other members that was harassing, demeaning or bullying. Dezzme is a place to have fun together, not to target people.'],
        'hate'            => ['Hate speech',                   'Content that attacks or demeans people because of who they are (race, religion, gender, sexuality, nationality, disability or similar).'],
        'spam'            => ['Spam or repetitive posting',    'Repeated, low-effort or unwanted posts, comments or messages, including flooding feeds with the same content.'],
        'scam'            => ['Scam or misleading links',      'Content that tries to trick people, collect their money or details, or sends them to misleading or unsafe links.'],
        'impersonation'   => ['Impersonation',                 'Pretending to be another person, brand or organisation, or using their name, photos or identity in a misleading way.'],
        'nudity'          => ['Nudity or sexual content',      'Nudity or sexually explicit material that is not allowed on Dezzme.'],
        'violence'        => ['Violent or graphic content',    'Violent, gory or graphic content, or content that encourages harm to people or animals.'],
        'self_harm'       => ['Self-harm content',             'Content that promotes or glorifies self-harm or suicide. If you are struggling, please reach out to someone you trust or a local helpline.'],
        'copyright'       => ['Copyright or stolen content',   'Posting content that belongs to someone else without permission, or re-posting it as if it were your own.'],
        'misinformation'  => ['False or misleading information','Sharing information that is clearly false or deliberately misleading in a way that could cause harm.'],
        'illegal'         => ['Illegal activity',              'Content that promotes, sells or helps with illegal goods or activities.'],
        'privacy'         => ['Sharing private information',   'Sharing someone’s private details (address, phone number, photos or messages) without their consent.'],
        'minors'          => ['Content risking minors',        'Content that could put children or teenagers at risk, or that is unsuitable for a community with young members.'],
        'fake_engagement' => ['Fake likes, follows or tracking','Buying, selling or trading likes, follows, tracks or other engagement, or using fake accounts to inflate numbers.'],
        'ban_evasion'     => ['Evading a previous action',     'Creating or using another account or pod to get around a warning, suspension or removal.'],
        'offensive_name'  => ['Offensive name or profile',     'A username, nickname, picture, banner or bio that breaks our guidelines.'],
        'offensive_pod'   => ['Offensive pod name or banner',  'A pod name, description, picture or banner that breaks our guidelines.'],
        'pod_content'     => ['Rule-breaking content in your pod','Posts in your pod that break our guidelines. As the pod owner you are responsible for what is allowed to stay there.'],
        'pod_moderation'  => ['Pod not being moderated',      'Harmful content or behaviour in your pod was reported repeatedly and left up.'],
        'pod_purpose'     => ['Pod used for the wrong purpose','The pod is being used for spam, promotion, scams or something other than what it says it is for.'],
        'guidelines'      => ['General guidelines violation',  'Activity that goes against the Dezzme community guidelines.'],
    ];
}

function dz_warn_ensure($conn){
    static $done = false; if ($done) return; $done = true;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS account_warnings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL DEFAULT 0,
            group_id INT NOT NULL DEFAULT 0,
            reason_key VARCHAR(40) NOT NULL DEFAULT 'guidelines',
            reason_text VARCHAR(600) NOT NULL DEFAULT '',
            admin_note VARCHAR(500) NOT NULL DEFAULT '',
            created_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            acknowledged_at DATETIME NULL DEFAULT NULL,
            KEY k_user (user_id, acknowledged_at),
            KEY k_group (group_id, acknowledged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}

/* the oldest warning this person (or this pod's owner) has not yet acknowledged, or null */
function dz_warn_pending($conn, $user_id, $group_id = 0){
    dz_warn_ensure($conn);
    $user_id = (int)$user_id; $group_id = (int)$group_id;
    try {
        $sql = $group_id > 0
            ? "SELECT * FROM account_warnings WHERE group_id=$group_id AND acknowledged_at IS NULL ORDER BY id ASC LIMIT 1"
            : "SELECT * FROM account_warnings WHERE user_id=$user_id AND group_id=0 AND acknowledged_at IS NULL ORDER BY id ASC LIMIT 1";
        $r = $conn->query($sql);
        return ($r instanceof mysqli_result) ? ($r->fetch_assoc() ?: null) : null;
    } catch (Throwable $e) { return null; }
}

/* the overlay itself. $kind 'account' (red) or 'pod' (blue). It cannot be closed except with the "I understand" button. */
function dz_warn_overlay($w, $kind){
    if (!$w) return;
    $pod   = ($kind === 'pod');
    $label = '';
    $reasons = dz_warn_reasons();
    if (isset($reasons[$w['reason_key']])) $label = $reasons[$w['reason_key']][0];
    $h = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $btnBg = $pod ? '#2459B8' : '#FF2D2D'; $btnFg = $pod ? '#fff' : '#000'; $head = $pod ? '#5b8fe6' : '#FF2D2D';
    ?>
<style>
/* full-screen like the profile modals; theme: red on profiles, blue on pods. Button = same thickness / corners / word size as the Track button (Join on pods). */
#dzWarnOv{position:fixed;inset:0;z-index:2147483600;display:flex;flex-direction:column;background:var(--bg-start,#010218);background-image:linear-gradient(180deg,var(--bg-start,#010218),var(--bg-end,var(--lane,#070924)));color:#f2f2f2;overscroll-behavior:contain;font-family:inherit}
#dzWarnOv .dzw-top{flex:0 0 auto;display:flex;align-items:center;justify-content:center;height:calc(52px * var(--hd-s,1));border-bottom:1px solid rgba(255,255,255,.09);font-size:calc(.9rem * var(--hd-s,1));font-weight:800;letter-spacing:.01em;padding-top:env(safe-area-inset-top)}
#dzWarnOv .dzw-top i{color:#FFB800;margin-right:8px;font-size:1.15em}
#dzWarnOv .dzw-scroll{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;display:flex;justify-content:center;align-items:flex-start;padding:28px 18px 18px}
#dzWarnOv .dzw-box{width:100%;max-width:460px;margin:auto 0;text-align:center}
#dzWarnOv .dzw-ic{width:76px;height:76px;border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;background:rgba(255,184,0,.13);color:#FFB800;font-size:38px}
#dzWarnOv h2{margin:0 0 8px;font-size:1.3rem;font-weight:800;line-height:1.25;color:<?php echo $head; ?>}
#dzWarnOv .dzw-sub{margin:0 0 20px;font-size:.88rem;color:rgba(255,255,255,.62);line-height:1.55}
#dzWarnOv .dzw-why{text-align:left;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:14px 15px;margin-bottom:12px}
#dzWarnOv .dzw-why small{display:block;font-size:.66rem;letter-spacing:.09em;text-transform:uppercase;color:rgba(255,255,255,.45);font-weight:700;margin-bottom:6px}
#dzWarnOv .dzw-why b{display:block;font-size:1rem;color:<?php echo $head; ?>;margin-bottom:6px}
#dzWarnOv .dzw-why p{margin:0;font-size:.86rem;line-height:1.6;color:rgba(255,255,255,.8)}
#dzWarnOv .dzw-note{text-align:left;font-size:.84rem;line-height:1.55;color:rgba(255,255,255,.82);border-left:3px solid #FFB800;padding:2px 0 2px 12px;margin:0 0 14px}
#dzWarnOv .dzw-fine{font-size:.78rem;line-height:1.55;color:rgba(255,255,255,.5);margin:0}
#dzWarnOv .dzw-fine a{color:<?php echo $head; ?>;font-weight:700;text-decoration:none}
#dzWarnOv .dzw-foot{flex:0 0 auto;display:flex;justify-content:center;padding:12px 18px calc(14px + env(safe-area-inset-bottom));border-top:1px solid rgba(255,255,255,.09)}
#dzWarnOv .dzw-btn{width:100%;max-width:460px;display:flex;align-items:center;justify-content:center;border:1.5px solid <?php echo $btnBg; ?>;background:<?php echo $btnBg; ?>;color:#000;
  height:<?php echo $pod ? 'calc(30px * var(--hd-s,1))' : 'var(--rp-action-h,30px)'; ?>;border-radius:<?php echo $pod ? 'var(--btn-r,4px)' : 'var(--rp-action-radius,4px)'; ?>;
  font-size:calc(.72rem * var(--hd-s,1));font-weight:700;line-height:1;cursor:pointer;font-family:inherit;transition:background .2s,transform .15s}
#dzWarnOv .dzw-btn:hover{filter:brightness(1.08)}
#dzWarnOv .dzw-btn:focus{outline:none}
#dzWarnOv .dzw-btn:active{transform:scale(.97)}
#dzWarnOv .dzw-btn:disabled{opacity:.6;cursor:wait}
</style>
<div id="dzWarnOv" role="alertdialog" aria-modal="true" aria-labelledby="dzwT" data-wid="<?php echo (int)$w['id']; ?>">
  <div class="dzw-top"><i class="ri-alarm-warning-fill"></i><?php echo $pod ? 'Pod warning' : 'Account warning'; ?></div>
  <div class="dzw-scroll"><div class="dzw-box">
    <div class="dzw-ic"><i class="ri-alarm-warning-fill"></i></div>
    <h2 id="dzwT"><?php echo $pod ? 'Your pod has been warned' : 'Your account has been warned'; ?></h2>
    <p class="dzw-sub"><?php echo $pod ? 'A Dezzme admin reviewed a report about this pod and is giving you an official warning as its owner.' : 'A Dezzme admin reviewed a report about your account and is giving you an official warning.'; ?></p>
    <div class="dzw-why">
      <small>Reason for this warning</small>
      <?php if ($label !== ''): ?><b><?php echo $h($label); ?></b><?php endif; ?>
      <p><?php echo $h($w['reason_text']); ?></p>
    </div>
    <?php if (trim((string)$w['admin_note']) !== ''): ?><p class="dzw-note"><b>Note from the admin:</b> <?php echo $h($w['admin_note']); ?></p><?php endif; ?>
    <p class="dzw-fine">Please follow the <a href="guidelines.php">Dezzme guidelines</a>. More violations can lead to limits on, or suspension of, your <?php echo $pod ? 'pod' : 'account'; ?>.</p>
  </div></div>
  <div class="dzw-foot"><button type="button" class="dzw-btn" id="dzWarnOk">I understand</button></div>
</div>
<script>
(function(){
  var ov = document.getElementById('dzWarnOv'); if(!ov) return;
  document.documentElement.style.overflow = 'hidden';
  /* cannot be dismissed any other way: Escape, remote Back, backdrop taps are all swallowed */
  document.addEventListener('keydown', function(e){
    if(!document.getElementById('dzWarnOv')) return;
    var k = e.key, c = e.keyCode;
    if(k === 'Escape' || k === 'Backspace' && !/^(INPUT|TEXTAREA)$/.test((document.activeElement||{}).tagName||'') || c === 461 || c === 10009 || c === 4 || k === 'BrowserBack'){
      e.stopImmediatePropagation(); e.preventDefault();
    }
  }, true);
  var btn = document.getElementById('dzWarnOk');
  try{ btn.focus(); }catch(e){}
  btn.addEventListener('click', function(){
    btn.disabled = true; btn.textContent = 'Saving…';
    var fd = new FormData(); fd.append('id', ov.getAttribute('data-wid'));
    fetch('warning_ack.php', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.text(); })
      .then(function(t){
        var d = {}; try{ d = JSON.parse(t.slice(t.indexOf('{'))); }catch(e){}
        if(d.ok){
          ov.remove(); document.documentElement.style.overflow = '';
          if(d.next) location.reload();   /* another warning is waiting */
        } else { btn.disabled = false; btn.textContent = 'I understand'; alert(d.msg || 'Could not save. Please try again.'); }
      }).catch(function(){ btn.disabled = false; btn.textContent = 'I understand'; alert('Connection problem. Please try again.'); });
  });
})();
</script>
<?php
}
}