<?php
/* ================================================================
   timeout.php — "Time to move your body" overlay
   Add this line just before </body> on every page where it should appear (feed, home, etc.):

       <?php include 'timeout.php'; ?>

   It counts the time the person spends on the site (only while the tab is open and visible,
   added up across pages and tabs, per account, per day, saved on this device).
   When the limit is reached, a full-screen midnight blue overlay covers the page. To get back to the feed
   the person has to type 1212. Typing it starts a fresh timer.

   TESTING / REAL VALUE
   Set before the include to change it for one page, or edit the defaults below:
       $DZ_TIMEOUT_MINUTES = 120;   // real value: 2 hours
   Shortcut for testing: open any page with ?dzto=now to see the overlay straight away.
================================================================ */
if(session_status() === PHP_SESSION_NONE) session_start();
if(empty($_SESSION['user_id'])) return;                      /* only for logged-in people */
if(defined('DZ_TIMEOUT_LOADED')) return;                     /* never output it twice on one page */
define('DZ_TIMEOUT_LOADED', true);

$DZ_TIMEOUT_MINUTES = isset($DZ_TIMEOUT_MINUTES) ? (float)$DZ_TIMEOUT_MINUTES : 1;   /* 1 = testing. Use 120 for 2 hours */
$DZ_TIMEOUT_CODE    = isset($DZ_TIMEOUT_CODE) ? preg_replace('/\D/', '', (string)$DZ_TIMEOUT_CODE) : '1212';
if($DZ_TIMEOUT_CODE === '') $DZ_TIMEOUT_CODE = '1212';
$DZ_TIMEOUT_THEME   = (isset($DZ_TIMEOUT_THEME) && $DZ_TIMEOUT_THEME === 'dark') ? 'dark' : 'red';   /* 'red' = the midnight blue theme with a black elephant (name kept so existing pages keep working). 'dark' = the Your Algorithm page's near-black background with white animal */
$dzto_uid      = (int)$_SESSION['user_id'];

/* FAMILY CENTER: if this person is a teen with an active supervisor, the supervisor's daily limit and quiet hours win.
   With two supervisors the strictest limit is used and quiet hours from either one apply. */
$dzto_fam = 0; $dzto_quiet = array();
try{
    if(isset($conn) && $conn instanceof mysqli){
        $fq = $conn->prepare("SELECT fs.daily_limit_min, fs.quiet_enabled, fs.quiet_start, fs.quiet_end
                              FROM family_links fl JOIN family_settings fs ON fs.link_id = fl.id
                              WHERE fl.teen_id = ? AND fl.status = 'active'");
        if($fq){
            $fq->bind_param('i', $dzto_uid); $fq->execute(); $fr = $fq->get_result();
            $best = 0;
            while($fr && ($f = $fr->fetch_assoc())){
                $dzto_fam = 1;
                $lm = (int)$f['daily_limit_min'];
                if($lm > 0 && ($best === 0 || $lm < $best)) $best = $lm;
                if(!empty($f['quiet_enabled']) && preg_match('/^(\d{1,2}):(\d{2})/', (string)$f['quiet_start'], $a) && preg_match('/^(\d{1,2}):(\d{2})/', (string)$f['quiet_end'], $b)){
                    $dzto_quiet[] = array(((int)$a[1])*60 + (int)$a[2], ((int)$b[1])*60 + (int)$b[2]);
                }
            }
            $fq->close();
            if($best > 0) $DZ_TIMEOUT_MINUTES = $best;
            elseif($dzto_fam && empty($dzto_quiet)) $dzto_fam = 0;   /* supervised but no limits set: leave the normal behaviour alone */
        }
    }
}catch(Throwable $e){ $dzto_fam = 0; $dzto_quiet = array(); }
$dzto_limit_ms = (int)round($DZ_TIMEOUT_MINUTES * 60000);
?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
#dzto, #dzto *, #dzto *::before, #dzto *::after{ box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
#dzto{
    --red:#ffd166; --purple:#b08cff;
    --bgimg:linear-gradient(-45deg,#010218,#070924,#070924); --ink:#fff; --btn-text:#070924; --card:#0c0c1c; --shade:rgba(255,255,255,.07);
    --bounce:cubic-bezier(.34,1.56,.64,1);
    position:fixed; inset:0; z-index:2147483647; color:#fff; overflow:hidden;
    background:var(--bgimg);
    display:flex; align-items:stretch; justify-content:center;
    -webkit-font-smoothing:antialiased; touch-action:none;
}
/* midnight blue theme (a slightly darker take on royal blue). The animal is black */
#dzto.th-red{ --bgimg:radial-gradient(120% 75% at 50% 38%, #0c3a9e 0%, #082a7c 50%, #051a52 100%); --ink:#000000; --btn-text:#082a7c; --card:#061f63; --shade:rgba(0,0,0,.28); }
#dzto .ele{ color:var(--ink); }
#dzto[hidden]{ display:none !important; }
#dzto.show{ animation:dztoFade .35s ease both; }
#dzto.leave{ animation:dztoOut .38s cubic-bezier(.5,0,.75,0) both; }
@keyframes dztoFade{ from{ opacity:0; } to{ opacity:1; } }
@keyframes dztoOut{ to{ opacity:0; transform:scale(1.04); } }

#dzto .in{
    width:100%; max-width:520px; min-height:100%; gap:16px; display:flex; flex-direction:column; align-items:center; justify-content:space-between;
    padding:calc(env(safe-area-inset-top, 0px) + clamp(46px, 11vh, 92px)) 24px calc(env(safe-area-inset-bottom, 0px) + 28px);
    overflow-y:auto;
    scrollbar-width:thin; scrollbar-color:rgba(255,255,255,.1) transparent;   /* almost invisible scrollbar that blends with the background */
}
#dzto .in::-webkit-scrollbar{ width:6px; }
#dzto .in::-webkit-scrollbar-track{ background:transparent; }
#dzto .in::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.1); border-radius:6px; }
#dzto .in::-webkit-scrollbar-thumb:hover{ background:rgba(255,255,255,.2); }

/* words: pop in, then sweep warm yellow > purple > mosaic, then settle to white */
#dzto .txt{ text-align:center; flex-shrink:0; }
#dzto .ln{ display:block; font-size:clamp(1.55rem, 6.6vw, 2.15rem); font-weight:800; line-height:1.2; letter-spacing:-.6px; }
#dzto .ln + .ln{ margin-top:6px; }
#dzto .w{ position:relative; display:inline-block; white-space:pre; opacity:0; }
#dzto.go .w{ animation:dztoWord .7s var(--bounce) both, dztoSweep 2.4s ease both; animation-delay:var(--d, 0ms); }
#dzto .w::after{
    content:attr(data-w); position:absolute; left:0; top:0; width:100%; height:100%; white-space:pre; pointer-events:none;
    background:repeating-conic-gradient(#ffd166 0 25%, #8b3dff 0 50%, #ff6fb1 0 75%, #3b1d8f 0 100%);
    background-size:8px 8px; -webkit-background-clip:text; background-clip:text;
    -webkit-text-fill-color:transparent; color:transparent; opacity:0;
}
#dzto.go .w::after{ animation:dztoMosaic 2.4s linear both; animation-delay:var(--d, 0ms); }
@keyframes dztoWord{ 0%{ opacity:0; transform:translateY(.7em) rotate(4deg) scale(.8); } 60%{ opacity:1; transform:translateY(-.12em) rotate(-1deg) scale(1.04); } 100%{ opacity:1; transform:none; } }
@keyframes dztoSweep{ 0%{ color:var(--red); } 22%{ color:var(--red); } 38%{ color:var(--purple); } 80%{ color:var(--purple); } 100%{ color:#fff; } }
@keyframes dztoMosaic{ 0%, 44%{ opacity:0; background-position:0 0; } 56%{ opacity:1; } 78%{ opacity:1; } 96%, 100%{ opacity:0; background-position:16px 8px; } }

/* elephant */
#dzto .art{ flex:1 0 auto; width:100%; display:flex; align-items:center; justify-content:center; padding:36px 0 32px; }   /* never shrinks below the elephant, so it can't climb over the words */
#dzto .ele-wrap{ position:relative; width:min(68vw, 300px, 38vh); flex-shrink:0; opacity:0; }   /* also limited by screen height so short screens stay clean */
#dzto.go .ele-wrap{ animation:dztoPop .9s var(--bounce) .35s both; }
@keyframes dztoPop{ 0%{ opacity:0; transform:scale(.45) translateY(30px); } 55%{ opacity:1; transform:scale(1.08) translateY(-4px); } 78%{ transform:scale(.97); } 100%{ opacity:1; transform:none; } }
#dzto .ele{ display:block; width:100%; height:auto; transform-origin:50% 90%; animation:dztoWalk 2.4s ease-in-out 1.4s infinite; cursor:pointer; -webkit-tap-highlight-color:transparent; touch-action:manipulation; }
#dzto .ele.hop{ animation:dztoHop .6s var(--bounce) 1; }
@keyframes dztoWalk{ 0%, 50%, 100%{ transform:translateY(0) rotate(0) scale(1.03, .96); } 25%{ transform:translateY(-15px) rotate(-2.6deg) scale(.98, 1.03); } 75%{ transform:translateY(-15px) rotate(2.6deg) scale(.98, 1.03); } }
#dzto .ele .ear{ transform-box:fill-box; transform-origin:100% 50%; animation:dztoEar 3.2s ease-in-out 1.4s infinite; }
@keyframes dztoEar{ 0%, 36%, 100%{ transform:scaleX(1) rotate(0); } 6%{ transform:scaleX(.66) rotate(-6deg); } 12%{ transform:scaleX(1.03) rotate(2deg); } 18%{ transform:scaleX(.7) rotate(-5deg); } 26%{ transform:scaleX(1) rotate(0); } }
#dzto .ele .eye{ transform-box:fill-box; transform-origin:50% 50%; animation:dztoBlink 3.6s ease-in-out 1.6s infinite; }
#dzto .ele .e2{ animation-delay:1.68s; }
@keyframes dztoBlink{ 0%, 90%, 100%{ transform:scaleY(1); } 94%{ transform:scaleY(.06); } }
#dzto .ele .tusk{ transform-box:fill-box; transform-origin:50% 0%; animation:dztoTrunk 1.2s ease-in-out 1.4s infinite alternate; }
@keyframes dztoTrunk{ from{ transform:rotate(-7deg); } to{ transform:rotate(7deg); } }
@keyframes dztoHop{ 0%{ transform:scale(1,1); } 30%{ transform:scale(1.1,.9) translateY(4px); } 60%{ transform:scale(.94,1.1) translateY(-22px); } 100%{ transform:scale(1,1); } }
#dzto .shade{ position:absolute; left:14%; right:14%; bottom:-6px; height:14px; border-radius:50%; background:var(--shade); animation:dztoShade 1.2s ease-in-out 1.4s infinite; }
@keyframes dztoShade{ 0%, 100%{ transform:scaleX(1.04); opacity:.9; } 50%{ transform:scaleX(.8); opacity:.5; } }

/* code entry */
#dzto .act{ width:100%; max-width:340px; flex-shrink:0; display:flex; flex-direction:column; align-items:center; gap:12px; opacity:0; }
#dzto.go .act{ animation:dztoUp .8s var(--bounce) 1.1s both; }
@keyframes dztoUp{ 0%{ opacity:0; transform:translateY(34px) scale(.96); } 60%{ opacity:1; transform:translateY(-3px); } 100%{ opacity:1; transform:none; } }
#dzto .hint{ font-size:.82rem; font-weight:500; color:rgba(255,255,255,.88); text-align:center; line-height:1.5; }
#dzto .hint b{ font-weight:800; letter-spacing:3px; color:#fff; }
#dzto .famnote{ display:none; font-size:.9rem; font-weight:500; color:rgba(255,255,255,.92); text-align:center; line-height:1.55; }
#dzto.fam .famnote{ display:block; }
#dzto.fam .hint, #dzto.fam .code, #dzto.fam .go-btn{ display:none; }
#dzto .code{
    width:100%; height:52px; border-radius:8px; border:2px solid rgba(255,255,255,.45); background:rgba(255,255,255,.12);
    color:#fff; text-align:center; font-size:1.35rem; font-weight:700; letter-spacing:12px; padding-left:12px; outline:none; -webkit-appearance:none; appearance:none;
    transition:border-color .2s, background .2s, box-shadow .2s, transform .3s var(--bounce);
}
#dzto .code::placeholder{ color:rgba(255,255,255,.55); letter-spacing:2px; font-size:.95rem; font-weight:500; }
#dzto .code:focus{ border-color:#fff; background:rgba(255,255,255,.18); box-shadow:0 0 0 4px rgba(255,255,255,.18); }
#dzto .code.shake{ animation:dztoShake .45s ease; border-color:#fff; }
@keyframes dztoShake{ 0%, 100%{ transform:translateX(0); } 20%{ transform:translateX(-9px); } 40%{ transform:translateX(8px); } 60%{ transform:translateX(-6px); } 80%{ transform:translateX(4px); } }
#dzto .go-btn{
    width:100%; height:52px; border-radius:8px; background:rgba(255,255,255,.2); color:rgba(255,255,255,.55);
    font-size:.95rem; font-weight:600; border:none; cursor:not-allowed; transition:background .2s, color .2s, transform .35s var(--bounce);
}
#dzto .go-btn.ready{ background:#fff; color:var(--btn-text); cursor:pointer; animation:dztoReady .5s var(--bounce); }
#dzto .go-btn.ready:active{ transform:scale(.94); }
@keyframes dztoReady{ 0%{ transform:scale(.9); } 60%{ transform:scale(1.05); } 100%{ transform:scale(1); } }
#dzto .link{ background:none; border:none; color:#fff; font-size:.88rem; font-weight:600; padding:8px 12px; cursor:pointer; transition:transform .3s var(--bounce), opacity .2s; }
#dzto .link:hover{ opacity:.85; }
#dzto .link:active{ transform:scale(.93); }

/* well-being tools sheet */
#dzto .sheet{ position:absolute; inset:0; display:none; align-items:flex-end; justify-content:center; background:rgba(0,0,0,.45); touch-action:auto; }
#dzto .sheet.open{ display:flex; animation:dztoFade .2s ease both; }
#dzto .card{ width:100%; max-width:480px; background:var(--card); border:1px solid rgba(255,255,255,.18); border-bottom:none; border-radius:12px 12px 0 0; padding:20px 22px calc(env(safe-area-inset-bottom, 0px) + 26px); animation:dztoSlide .55s var(--bounce); }
@keyframes dztoSlide{ from{ transform:translateY(90px) scale(.96); opacity:0; } to{ transform:none; opacity:1; } }
#dzto .card h3{ font-size:1.02rem; font-weight:700; margin-bottom:12px; }
#dzto .tip{ display:flex; align-items:center; gap:12px; padding:11px 0; border-top:1px solid rgba(255,255,255,.14); font-size:.86rem; font-weight:500; }
#dzto .tip:first-of-type{ border-top:none; }
#dzto .tip i{ width:34px; height:34px; border-radius:8px; background:rgba(255,255,255,.14); display:flex; align-items:center; justify-content:center; font-style:normal; font-size:1.05rem; flex-shrink:0; }
#dzto .card .close{ width:100%; height:46px; margin-top:14px; border-radius:8px; background:rgba(255,255,255,.16); color:#fff; font-size:.88rem; font-weight:600; border:none; cursor:pointer; transition:background .2s, transform .3s var(--bounce); }
#dzto .card .close:hover{ background:rgba(255,255,255,.26); }
#dzto .card .close:active{ transform:scale(.95); }

@media (max-width:768px){ #dzto .code, #dzto .code::placeholder{ font-size:1.35rem; } #dzto .code::placeholder{ font-size:16px; } }
@media (prefers-reduced-motion:reduce){ #dzto *{ animation-duration:.01ms !important; animation-delay:0ms !important; animation-iteration-count:1 !important; } #dzto .w, #dzto .ele-wrap, #dzto .act{ opacity:1; } }
</style>

<div id="dzto" class="th-<?php echo $DZ_TIMEOUT_THEME; ?>" role="dialog" aria-modal="true" aria-labelledby="dztoTxt" hidden>
    <div class="in">
        <div class="txt" id="dztoTxt">
            <span class="ln" data-t="Now&rsquo;s a good time to move your body"></span>
            <span class="ln" data-t="You&rsquo;ve reached your daily limit."></span>
        </div>

        <div class="art">
            <div class="ele-wrap">
                <svg class="ele" id="dztoEle" viewBox="14 6 218 180" role="img" aria-label="A friendly elephant">
                    <defs>
                        <mask id="dztoMask" maskUnits="userSpaceOnUse" x="0" y="0" width="240" height="190">
                            <path fill="#fff" d="M18.2 181V42.2A32 32 0 0 1 50.2 10.2H191A37 37 0 0 1 228 47.2V150.2A29.4 30.8 0 0 1 169.2 150.2V112.8H165V181Z"/>
                            <rect x="89.4" y="115" width="4.1" height="80" rx="2.05" fill="#000"/>
                            <rect x="165" y="112.8" width="22" height="8.2" rx="2.4" fill="#000"/>
                            <line class="tusk" x1="184.6" y1="116.8" x2="191.7" y2="150.8" stroke="#000" stroke-width="5.4" stroke-linecap="round"/>
                            <path class="ear" fill="#000" d="M115.8 67.8A49 44 0 0 1 164.8 23.8V67.8Z"/>
                            <g class="eye e1"><path fill="#000" d="M179 55.2Q195 40 211.6 54.6Q195 69 179 55.2Z"/><circle cx="191.4" cy="51.8" r="6" fill="#fff"/></g>
                            <g class="eye e2"><path fill="#000" d="M179 36Q195 21.5 211.8 35.5Q196 50 179 36Z"/><circle cx="191.4" cy="32.8" r="6" fill="#fff"/></g>
                        </mask>
                    </defs>
                    <rect x="0" y="0" width="240" height="190" fill="currentColor" mask="url(#dztoMask)"/>
                </svg>
                <span class="shade"></span>
            </div>
        </div>

        <div class="act">
            <p class="famnote" id="dztoFam"></p>
            <p class="hint">Take a break. To go back to your feed, type <b><?php echo htmlspecialchars($DZ_TIMEOUT_CODE, ENT_QUOTES); ?></b></p>
            <input type="text" class="code" id="dztoCode" inputmode="numeric" pattern="[0-9]*" maxlength="<?php echo strlen($DZ_TIMEOUT_CODE); ?>" placeholder="Type the number" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="Type the number to go back to your feed">
            <button type="button" class="go-btn" id="dztoGo" disabled>Return to feed</button>
            <button type="button" class="link" id="dztoWell">Explore well-being tools</button>
        </div>
    </div>

    <div class="sheet" id="dztoSheet">
        <div class="card">
            <h3>Well-being tools</h3>
            <div class="tip"><i>&#128694;</i><span>Stand up and take a short walk</span></div>
            <div class="tip"><i>&#128167;</i><span>Drink a glass of water</span></div>
            <div class="tip"><i>&#128064;</i><span>Look at something far away for 20 seconds</span></div>
            <div class="tip"><i>&#129496;</i><span>Stretch your neck, shoulders and back</span></div>
            <button type="button" class="close" id="dztoSheetX">Close</button>
        </div>
    </div>
</div>

<script>
(function(){
    if(window.__dzto) return; window.__dzto = 1;
    var LIMIT = <?php echo $dzto_limit_ms; ?>, CODE = '<?php echo $DZ_TIMEOUT_CODE; ?>', KEY = 'dz_timeout_<?php echo $dzto_uid; ?>';
    var root = document.getElementById('dzto'), codeIn = document.getElementById('dztoCode'), goBtn = document.getElementById('dztoGo');
    var sheet = document.getElementById('dztoSheet');
    var FAM = <?php echo $dzto_fam ? 1 : 0; ?>, QUIET = <?php echo json_encode($dzto_quiet); ?>, mode = '';
    var isOpen = false, pauser = null, S, last = Date.now();
    function inQuiet(){
        var d = new Date(), n = d.getHours() * 60 + d.getMinutes();
        for(var i = 0; i < QUIET.length; i++){
            var a = QUIET[i][0], b = QUIET[i][1];
            if(a === b) continue;
            if(a < b ? (n >= a && n < b) : (n >= a || n < b)) return true;
        }
        return false;
    }
    function fmt(m){ var h = Math.floor(m / 60) % 24, mm = m % 60, ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return h + ':' + (mm < 10 ? '0' : '') + mm + ' ' + ap; }
    function quietEnd(){
        var d = new Date(), n = d.getHours() * 60 + d.getMinutes();
        for(var i = 0; i < QUIET.length; i++){ var a = QUIET[i][0], b = QUIET[i][1]; if(a !== b && (a < b ? (n >= a && n < b) : (n >= a || n < b))) return b; }
        return 0;
    }

    function today(){ var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
    function load(){
        try{ var s = JSON.parse(localStorage.getItem(KEY) || 'null'); if(s && s.d === today()) return { d:s.d, ms:+s.ms || 0, lock:s.lock ? 1 : 0 }; }catch(e){}
        return { d:today(), ms:0, lock:0 };
    }
    function save(){ try{ localStorage.setItem(KEY, JSON.stringify(S)); }catch(e){} }
    function buzz(p){ if(navigator.vibrate){ try{ navigator.vibrate(p); }catch(e){} } }
    S = load();

    /* build the two lines word by word, so each word can pop in and sweep colours */
    function buildWords(){
        var n = 0;
        root.querySelectorAll('.ln').forEach(function(ln){
            var txt = ln.getAttribute('data-t').replace(/&rsquo;/g, '\u2019');
            ln.innerHTML = '';
            txt.split(/( )/).forEach(function(w){
                if(w === ' '){ ln.appendChild(document.createTextNode(' ')); return; }
                var s = document.createElement('span');
                s.className = 'w'; s.setAttribute('data-w', w); s.textContent = w;
                s.style.setProperty('--d', Math.min(n * 60, 1000) + 'ms'); n++;
                ln.appendChild(s);
            });
        });
    }

    function pauseMedia(){
        document.querySelectorAll('video, audio').forEach(function(m){ try{ if(!m.paused) m.pause(); }catch(e){} });
    }

    function show(m){
        if(isOpen) return; isOpen = true;
        mode = m || (FAM ? 'limit' : '');
        if(mode !== 'quiet'){ S.lock = 1; save(); }
        var L = root.querySelectorAll('.ln');
        if(FAM){
            root.classList.add('fam');
            var note = document.getElementById('dztoFam');
            if(mode === 'quiet'){
                L[0].setAttribute('data-t', 'It&rsquo;s quiet time');
                L[1].setAttribute('data-t', 'Rest up. Back at ' + fmt(quietEnd()) + '.');
                note.textContent = 'Your family supervisor set quiet hours on your account.';
            }else{
                L[0].setAttribute('data-t', 'Now&rsquo;s a good time to move your body');
                L[1].setAttribute('data-t', 'You&rsquo;ve reached your daily limit.');
                note.textContent = 'Your family supervisor set this daily limit. Your time resets tomorrow.';
            }
        }
        buildWords();
        codeIn.value = ''; goBtn.disabled = true; goBtn.classList.remove('ready');
        sheet.classList.remove('open');
        root.classList.remove('leave', 'go'); root.hidden = false;
        void root.offsetWidth;
        root.classList.add('show', 'go');
        document.documentElement.style.overflow = 'hidden'; document.body.style.overflow = 'hidden';
        pauseMedia(); pauser = setInterval(pauseMedia, 400);
        buzz([10, 40, 14]);
        if(!FAM) setTimeout(function(){ try{ codeIn.focus({ preventScroll:true }); }catch(e){} }, 1300);
    }

    function hide(){
        if(!isOpen) return;
        buzz([8, 30, 8]);
        root.classList.remove('show'); root.classList.add('leave');
        setTimeout(function(){
            root.hidden = true; root.classList.remove('leave', 'go'); isOpen = false;
            clearInterval(pauser); pauser = null;
            document.documentElement.style.overflow = ''; document.body.style.overflow = '';
            if(mode !== 'quiet') S = { d:today(), ms:0, lock:0 };   /* a fresh timer starts now (quiet hours ending keeps today's time) */
            save(); last = Date.now(); mode = '';
        }, 380);
    }

    function tryCode(){
        var v = codeIn.value.replace(/\D/g, '');
        if(v === CODE){ goBtn.disabled = false; goBtn.classList.add('ready'); return true; }
        goBtn.disabled = true; goBtn.classList.remove('ready');
        if(v.length >= CODE.length){
            codeIn.classList.remove('shake'); void codeIn.offsetWidth; codeIn.classList.add('shake');
            buzz([30, 40, 30]); setTimeout(function(){ codeIn.value = ''; }, 380);
        }
        return false;
    }
    codeIn.addEventListener('input', function(){ this.value = this.value.replace(/\D/g, ''); tryCode(); });
    codeIn.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); if(tryCode()) hide(); } });
    goBtn.addEventListener('click', function(){ if(tryCode()) hide(); });
    document.getElementById('dztoWell').addEventListener('click', function(){ sheet.classList.add('open'); buzz(8); });
    document.getElementById('dztoSheetX').addEventListener('click', function(){ sheet.classList.remove('open'); });
    sheet.addEventListener('click', function(e){ if(e.target === sheet) sheet.classList.remove('open'); });
    root.querySelectorAll('.ele').forEach(function(el){ el.addEventListener('click', function(){ el.classList.remove('hop'); void el.offsetWidth; el.classList.add('hop'); buzz(10); setTimeout(function(){ el.classList.remove('hop'); }, 650); }); });

    /* the clock: counts only while this page is open and visible; adds up across pages and tabs */
    setInterval(function(){
        var now = Date.now(), dt = now - last; last = now;
        if(FAM && QUIET.length){
            var q = inQuiet();
            if(q && !isOpen){ show('quiet'); return; }
            if(!q && isOpen && mode === 'quiet'){ hide(); return; }
        }
        if(isOpen || document.hidden) return;
        if(dt > 5000) dt = 1000;                 /* the device slept: don't count the gap */
        S = load(); S.ms += dt; save();
        if(S.ms >= LIMIT) show();
    }, 1000);
    document.addEventListener('visibilitychange', function(){ last = Date.now(); });
    window.addEventListener('storage', function(e){ if(e.key === KEY && !isOpen){ var n = load(); if(n.lock) show(); } });   /* another tab hit the limit */

    if(FAM && QUIET.length && inQuiet()) show('quiet');
    else if(S.lock || S.ms >= LIMIT || /[?&]dzto=now\b/.test(location.search)) show();
})();
</script>