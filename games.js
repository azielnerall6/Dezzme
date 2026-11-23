var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
var $ = function(id){ return document.getElementById(id); };

function ic(n){ return '<svg class="ic"><use href="#i-' + n + '"/></svg>'; }
function pageClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'index.php';
}
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function rand(n){ return Math.floor(Math.random() * n); }
function rrect(c, x, y, w, h, r){
    c.beginPath(); c.moveTo(x + r, y);
    c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r);
    c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath();
}

function post(data, ms){
    data.csrf_token = CSRF;
    var body = Object.keys(data).map(function(k){ return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
    /* a slow or dead connection can never hang the game: the request is dropped after a few seconds */
    var ctl = window.AbortController ? new AbortController() : null;
    var tm = ctl ? setTimeout(function(){ ctl.abort(); }, ms || 7000) : 0;
    return fetch('games.php', {
        method:'POST',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-Token':CSRF },
        credentials:'same-origin', body:body, signal:ctl ? ctl.signal : undefined
    }).then(function(r){ return r.json(); }).catch(function(){ return { status:'error' }; })
      .then(function(x){ clearTimeout(tm); return x; });
}

window.addEventListener('load', function(){
    var sk = $('page-skeleton'); if (!sk) return;
    setTimeout(function(){ sk.classList.add('hide'); setTimeout(function(){ sk.remove(); }, 380); }, 280);
});

function filterGames(){
    var q = $('gm-search').value.trim().toLowerCase(), vis = 0;
    document.querySelectorAll('.gm-card').forEach(function(c){
        var m = c.dataset.name.indexOf(q) > -1; c.style.display = m ? '' : 'none'; if (m) vis++;
    });
    $('gmNoRes').style.display = (q && !vis) ? 'flex' : 'none';
}

/* ══════════════════════════════════════════════════════════════
   ENGINE
══════════════════════════════════════════════════════════════ */
var GAMES = {};
var HELD = { u:false, d:false, l:false, r:false };
var modal = $('gmModal'), stage = $('gmStage'), cv = $('gmCv'), ctx = cv.getContext('2d'), ovEl = $('gmOv');
var cur = null, curKey = null, state = 'idle', tab = 'play', raf = 0, lastT = 0, score = 0;
var TOPC = {};                       // leaderboard cache (saves requests)
var PTRS = {};                       // fingers currently down on the stage
var errored = false;

var api = {
    held:     HELD,
    setScore: function(n){ score = n; $('hudScore').textContent = n; },
    extra:    function(t){ $('hudExtra').textContent = t; },
    end:      function(o){ finish(o); },
    sfx:      function(n){ if (SFX[n]) SFX[n](); }
};

/* ── sound: tiny synth, no audio files ── */
var AC = null, soundOn = true;
try { soundOn = localStorage.getItem('gm_sound') !== '0'; } catch(e){}
function sfxCtx(){
    if (!AC){ try { AC = new (window.AudioContext || window.webkitAudioContext)(); } catch(e){} }
    if (AC && AC.state === 'suspended') AC.resume();
    return AC;
}
function tone(f1, f2, dur, type, vol, delay){
    if (!soundOn) return;
    var a = sfxCtx(); if (!a) return;
    var t = a.currentTime + (delay || 0), o = a.createOscillator(), g = a.createGain();
    o.type = type || 'square'; o.frequency.setValueAtTime(f1, t);
    if (f2 > 0) o.frequency.exponentialRampToValueAtTime(f2, t + dur);
    g.gain.setValueAtTime(vol || .06, t); g.gain.exponentialRampToValueAtTime(.0001, t + dur);
    o.connect(g); g.connect(a.destination); o.start(t); o.stop(t + dur + .03);
}
var SFX = {
    jump:  function(){ tone(300, 620, .15, 'square', .05); },
    land:  function(){ tone(150, 60, .08, 'sine', .09); },
    score: function(){ tone(660, 0, .07, 'triangle', .07); tone(990, 0, .1, 'triangle', .07, .07); },
    level: function(){ [523,659,784,1046].forEach(function(f, i){ tone(f, 0, .12, 'triangle', .07, i * .08); }); },
    hit:   function(){ tone(240, 45, .4, 'sawtooth', .09); tone(120, 40, .4, 'square', .05); if (soundOn && navigator.vibrate) navigator.vibrate(70); },
    win:   function(){ [523,659,784,1046,1318].forEach(function(f, i){ tone(f, 0, .16, 'triangle', .08, i * .1); }); },
    eat:   function(){ tone(520, 900, .08, 'square', .04); },
    flap:  function(){ tone(380, 560, .09, 'triangle', .07); },
    chomp: function(){ tone(260, 180, .05, 'square', .025); },
    ghost: function(){ tone(300, 1200, .2, 'square', .05); },
    slide: function(){ tone(240, 320, .05, 'sine', .06); },
    merge: function(){ tone(420, 0, .06, 'triangle', .07); tone(630, 0, .08, 'triangle', .07, .05); },
    place: function(){ tone(540, 0, .05, 'sine', .07); },
    pop:   function(){ tone(420, 0, .04, 'sine', .05); },
    wrong: function(){ tone(180, 120, .2, 'sawtooth', .06); },
    thud:  function(){ tone(130, 70, .12, 'sine', .1); },
    whoosh:function(){ tone(900, 200, .18, 'sine', .04); },
    clear: function(){ [440,554,659].forEach(function(f, i){ tone(f, 0, .09, 'triangle', .07, i * .06); }); }
};
function syncSnd(){ var b = $('gmSnd'); if (b) b.innerHTML = ic(soundOn ? 'vol' : 'mute'); }
function toggleSound(){
    soundOn = !soundOn;
    try { localStorage.setItem('gm_sound', soundOn ? '1' : '0'); } catch(e){}
    syncSnd(); if (soundOn) SFX.pop();
}

function setupCanvas(){
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    cv.width = cur.W * dpr; cv.height = cur.H * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}
function fit(){
    if (!cur || cur.dom) return;
    var pad = window.innerWidth <= 800 ? 8 : 20;           // phones: boards use more of the screen
    var aw = stage.clientWidth - pad, ah = stage.clientHeight - pad;
    if (aw <= 0 || ah <= 0) return;
    if (cur.resize && cur.resize(aw / ah)) setupCanvas();      // some games adapt their height to the screen
    var s = Math.min(aw / cur.W, ah / cur.H);
    cv.style.width  = Math.floor(cur.W * s) + 'px';
    cv.style.height = Math.floor(cur.H * s) + 'px';
}
window.addEventListener('resize', fit);

function ovAdd(tag, cls, txt){
    var e = document.createElement(tag); if (cls) e.className = cls;
    if (txt != null) e.textContent = txt; ovEl.appendChild(e); return e;
}
function showOv(icon, title, sub, big, nb, btn, fn){
    ovEl.innerHTML = ''; ovEl.classList.toggle('solid', !!(cur && cur.dom));
    if (icon) ovAdd('div', 'ovic').innerHTML = ic(icon);
    if (big != null) ovAdd('div', 'big', big);
    ovAdd('h3', '', title);
    if (nb) ovAdd('div', 'nb', nb);
    if (sub) ovAdd('p', '', sub);
    var b = ovAdd('button', 'tk-btn track', btn); b.type = 'button';
    b.onclick = function(e){ e.stopPropagation(); fn(); };
    ovEl.classList.remove('hide');
}
function showChoice(icon, title, sub, opts, fn){
    ovEl.innerHTML = ''; ovEl.classList.toggle('solid', !!(cur && cur.dom));
    if (icon) ovAdd('div', 'ovic').innerHTML = ic(icon);
    ovAdd('h3', '', title);
    if (sub) ovAdd('p', '', sub);
    var box = ovAdd('div', 'gm-choices');
    opts.forEach(function(o){
        var b = document.createElement('button'); b.type = 'button'; b.className = 'tk-btn track'; b.textContent = o.n;
        b.onclick = function(e){ e.stopPropagation(); fn(o.k); };
        box.appendChild(b);
    });
    ovEl.classList.remove('hide');
}
function hideOv(){ ovEl.classList.add('hide'); }

function refreshBest(g){
    var el = $('best-' + g); if (el) el.textContent = BEST[g] || 0;
    if (g === curKey) $('hudBest').textContent = BEST[g] || 0;
}
function clearHeld(){ HELD.u = HELD.d = HELD.l = HELD.r = false; }
/* tell the game every finger is up (so a pedal / drag can't stay stuck) and forget them all */
function releasePtrs(){
    var ids = Object.keys(PTRS); PTRS = {};
    if (cur && cur.ptr) ids.forEach(function(id){ try { cur.ptr('up', -999, -999, +id); } catch(e){} });
}

function openGame(key){
    if (!GAMES[key]) return;
    sfxCtx(); syncSnd(); clearHeld(); PTRS = {}; errored = false;
    var host = $('gmDom'); host.innerHTML = '';
    curKey = key;
    cur = GAMES[key].make(api, ctx, host);
    $('gmTitle').textContent = GAMES[key].name;
    $('gmHelp').textContent  = GAMES[key].help;
    cv.style.display   = cur.dom ? 'none' : 'block';
    host.style.display = cur.dom ? 'flex' : 'none';
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    if (!cur.dom) setupCanvas();
    switchTab('play', true);
    refreshBest(key);
    api.extra('');
    cur.reset();
    api.setScore(0);
    fit();
    readyScreen();
    cancelAnimationFrame(raf); lastT = performance.now();
    raf = requestAnimationFrame(loop);
}
function closeGame(){
    cancelAnimationFrame(raf);
    releasePtrs();
    try { if (cur && cur.destroy) cur.destroy(); } catch(e){}
    modal.classList.remove('open');
    document.body.style.overflow = '';
    clearHeld();
    state = 'idle'; cur = null; curKey = null;
}

function readyScreen(){
    state = 'ready';
    var G = GAMES[curKey];
    if (cur.steps){
        var picks = [];
        (function next(i){
            if (i >= cur.steps.length){ cur.setLevel(picks); start(); return; }
            var s = cur.steps[i];
            showChoice(G.ovIcon, s.title, s.sub || '', s.opts, function(k){ picks.push(k); next(i + 1); });
        })(0);
    }
    else if (cur.levels) showChoice(G.ovIcon, G.name, G.help, cur.levels, function(k){ cur.setLevel(k); start(); });
    else showOv(G.ovIcon, G.name, G.help, null, null, 'Play', function(){ start(); });
}
function start(){
    hideOv();
    state = 'playing';
    post({ action:'start_run', game:curKey });
    if (cur.begin) cur.begin();
}
function restart(){
    releasePtrs(); errored = false;
    cur.reset(); api.setScore(0); api.extra(''); clearHeld();
    if (cur.levels || cur.steps){ readyScreen(); return; }
    start();
}
function resumeGame(){
    if (state !== 'paused') return;
    hideOv(); state = 'playing'; lastT = performance.now();
}
function pauseGame(){
    if (state !== 'playing') return;
    state = 'paused'; clearHeld(); releasePtrs();
    showOv('pause', 'Paused', '', null, null, 'Resume', resumeGame);
    var b2 = ovAdd('button', 'tk-btn ghost', 'Restart'); b2.type = 'button';
    b2.onclick = function(e){ e.stopPropagation(); restart(); };
    var b3 = ovAdd('button', 'tk-btn ghost', 'Quit game'); b3.type = 'button';
    b3.onclick = function(e){ e.stopPropagation(); closeGame(); };
}
function finish(opt){
    if (state === 'over') return;
    state = 'over'; opt = opt || {}; clearHeld(); releasePtrs();
    var s = score, g = curKey;
    if (opt.win) SFX.win(); else SFX.hit();
    Promise.all([
        post({ action:'save_score', game:g, score:s }, 5000),
        new Promise(function(r){ setTimeout(r, opt.win ? 300 : 650); })
    ]).then(function(res){
        var d = res[0], nb = '', note = opt.sub || '';
        if (d.status === 'ok'){ BEST[g] = d.best; refreshBest(g); delete TOPC[g]; if (d.new_best) nb = 'New best!'; }
        else if (d.msg === 'invalid'){ note = 'That score couldn’t be verified, so it wasn’t saved.'; }
        else { if (s > (BEST[g] || 0)) nb = 'New best (not synced)'; }
        if (curKey !== g || state !== 'over') return;
        showOv('', opt.title || 'Game Over', note, s, nb, 'Play again', restart);
    });
}

function loop(t){
    raf = requestAnimationFrame(loop);
    if (!cur || errored) return;
    var dt = Math.min(0.05, (t - lastT) / 1000); lastT = t;
    try {
        if (state === 'playing') cur.update(dt);
        if (cur.draw) cur.draw(t / 1000, state);
    } catch(err){
        errored = true;
        try { console.error(err); } catch(_){}
        state = 'over'; clearHeld(); releasePtrs();
        showOv('', 'Something glitched', 'The game ran into a problem. Your best score is safe.', null, null, 'Restart', function(){
            errored = false;
            try { restart(); } catch(e2){ closeGame(); }
        });
    }
}

/* tabs */
function switchTab(t, silent){
    tab = t;
    $('tabPlay').classList.toggle('active', t === 'play');
    $('tabTop').classList.toggle('active', t === 'top');
    $('panelPlay').classList.toggle('show', t === 'play');
    $('panelTop').classList.toggle('show', t === 'top');
    if (t === 'top'){ pauseGame(); loadTop(); } else if (!silent) fit();
    if (t === 'play') setTimeout(fit, 0);
}
function loadTop(){
    var box = $('gmTopList'), g = curKey, hit = TOPC[g];
    if (hit && Date.now() - hit.t < 45000){ box.innerHTML = hit.h; return; }     // reuse for 45s: no new request
    box.innerHTML = '<div class="gm-empty">Loading…</div>';
    post({ action:'leaderboard', game:g }).then(function(d){
        if (g !== curKey) return;
        if (d.status !== 'ok'){ box.innerHTML = '<div class="gm-empty">Could not load the leaderboard.</div>'; return; }
        var h = '';
        if (d.me && d.me.best > 0) h += '<div class="gm-me">Your best <b>' + d.me.best + '</b> · Rank <b>#' + d.me.rank + '</b></div>';
        if (!d.rows.length) h += '<div class="gm-empty">No scores yet. Be the first!</div>';
        d.rows.forEach(function(r, i){
            h += '<div class="user-row' + (r.me ? ' me' : '') + '" onclick="location.href=\'profile.php?user_id=' + r.id + '\'">'
              + '<span class="gm-rank">' + (i + 1) + '</span>'
              + '<img class="user-avatar" loading="lazy" decoding="async" src="' + esc(r.pic) + '" onerror="this.onerror=null;this.src=\'uploads/me.png\'" alt="">'
              + '<div class="user-info"><div class="uname">' + esc(r.username) + (r.me ? ' (You)' : '') + '</div>'
              + (r.nick ? '<div class="unick">@' + esc(r.nick) + '</div>' : '') + '</div>'
              + '<div class="gm-sc">' + r.score + '</div></div>';
        });
        TOPC[g] = { t:Date.now(), h:h };
        box.innerHTML = h;
    });
}

/* input — keyboard (also tracks held arrows for smooth-steering games) */
var KEYMAP = { ArrowUp:'u', w:'u', W:'u', ArrowDown:'d', s:'d', S:'d', ArrowLeft:'l', a:'l', A:'l', ArrowRight:'r', d:'r', D:'r' };
document.addEventListener('keydown', function(e){
    if (!modal.classList.contains('open') || !cur) return;
    if (e.key === 'Escape'){
        if (state === 'playing') pauseGame(); else if (state === 'paused') resumeGame(); else closeGame();
        return;
    }
    if (tab !== 'play') return;
    if (e.key === 'p' || e.key === 'P'){
        if (state === 'playing') pauseGame(); else if (state === 'paused') resumeGame();
        e.preventDefault(); return;
    }
    if (!cur.dom && cur.keyx && state === 'playing' && cur.keyx(e)){ e.preventDefault(); return; }
    if (cur.dom){ if (state === 'playing' && cur.key && cur.key(e)) e.preventDefault(); return; }
    var d = KEYMAP[e.key], act = (e.key === ' ' || e.key === 'Enter');
    if (d) HELD[d] = true;
    if (!d && !act) return;
    e.preventDefault();
    if (state === 'ready'){ start(); }
    else if (state === 'over'){ if (!ovEl.classList.contains('hide')) restart(); return; }
    else if (state === 'paused'){ resumeGame(); return; }
    if (state === 'playing'){ if (d) cur.dir(d); else cur.tap(); }
});
document.addEventListener('keyup', function(e){ var d = KEYMAP[e.key]; if (d) HELD[d] = false; });
document.addEventListener('visibilitychange', function(){ if (document.hidden) pauseGame(); });

/* input — touch / mouse (swipe + tap, or a full pointer stream for games that define ptr(); multi:true games get every finger) */
(function(){
    var st = null;
    function cpos(e){
        var r = cv.getBoundingClientRect();
        return [ (e.clientX - r.left) * cur.W / r.width, (e.clientY - r.top) * cur.H / r.height ];
    }
    stage.addEventListener('pointerdown', function(e){
        if (state !== 'playing' || !cur || cur.dom) return;
        if (cur.ptr){
            if (!cur.multi) releasePtrs();           // a lost 'finger up' can never block new touches
            PTRS[e.pointerId] = 1;
            try { stage.setPointerCapture(e.pointerId); } catch(_){}
            var p = cpos(e); cur.ptr('down', p[0], p[1], e.pointerId); return;
        }
        st = { id:e.pointerId, x:e.clientX, y:e.clientY, moved:false, done:false };
        if (cur.instant){ cur.tap(); st.moved = true; }
    });
    stage.addEventListener('pointermove', function(e){
        if (cur && cur.ptr){
            if (PTRS[e.pointerId] && state === 'playing'){ var p = cpos(e); cur.ptr('move', p[0], p[1], e.pointerId); }
            return;
        }
        if (!st || e.pointerId !== st.id || !cur) return;
        if (state !== 'playing' || !cur.swipe) return;
        if (st.done && cur.once) return;
        var dx = e.clientX - st.x, dy = e.clientY - st.y;
        if (Math.abs(dx) < 22 && Math.abs(dy) < 22) return;
        cur.dir(Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'r' : 'l') : (dy > 0 ? 'd' : 'u'));
        st.x = e.clientX; st.y = e.clientY; st.moved = true; st.done = true;
    });
    function endPtr(e, cancel){
        if (!PTRS[e.pointerId]) return false;
        delete PTRS[e.pointerId];
        if (cur && cur.ptr && (state === 'playing' || cur.multi)){
            var p = cancel ? [-999, -999] : cpos(e);
            cur.ptr('up', p[0], p[1], e.pointerId);
        }
        return true;
    }
    stage.addEventListener('pointerup', function(e){
        if (endPtr(e, false)) return;
        if (!st || e.pointerId !== st.id) return;
        if (!st.moved && state === 'playing' && cur) cur.tap();
        st = null;
    });
    stage.addEventListener('pointercancel', function(e){
        if (endPtr(e, true)) return;
        st = null;
    });
    stage.addEventListener('lostpointercapture', function(e){ endPtr(e, true); });
    window.addEventListener('blur', function(){ releasePtrs(); clearHeld(); });
})();

/* ══════════════════════════════════════════════════════════════
   SNAKE
══════════════════════════════════════════════════════════════ */
GAMES.snake = {
    name:'Snake', ovIcon:'gamepad',
    help:'Swipe or use arrow keys / WASD to steer. Eat the dots, avoid walls and yourself.',
    make:function(api, ctx){
        var N = 20, C = 20, W = N * C, H = N * C;
        var s, dir, q, food, acc, step, sc;
        var VEC = { u:{x:0,y:-1}, d:{x:0,y:1}, l:{x:-1,y:0}, r:{x:1,y:0} };

        function placeFood(){
            do { food = { x:rand(N), y:rand(N) }; }
            while (s.some(function(p){ return p.x === food.x && p.y === food.y; }));
        }
        return {
            W:W, H:H, swipe:true, instant:false,
            preview:function(){
                this.reset();
                s = [{x:12,y:8},{x:11,y:8},{x:10,y:8},{x:9,y:8},{x:8,y:8},{x:7,y:8},{x:7,y:9},{x:7,y:10},{x:7,y:11},{x:8,y:11},{x:9,y:11}];
                dir = {x:1,y:0}; food = {x:16,y:6};
            },
            reset:function(){
                s = [{x:10,y:10},{x:9,y:10},{x:8,y:10}];
                dir = {x:1,y:0}; q = []; acc = 0; step = 0.12; sc = 0;
                placeFood();
            },
            dir:function(d){
                var v = VEC[d], last = q.length ? q[q.length-1] : dir;
                if (v.x === -last.x && v.y === -last.y) return;
                if (v.x === last.x && v.y === last.y) return;
                if (q.length < 3) q.push(v);
            },
            tap:function(){},
            update:function(dt){
                acc += dt;
                while (acc >= step){
                    acc -= step;
                    if (q.length) dir = q.shift();
                    var h = { x:s[0].x + dir.x, y:s[0].y + dir.y };
                    var hit = h.x < 0 || h.y < 0 || h.x >= N || h.y >= N ||
                        s.some(function(p, i){ return i < s.length - 1 && p.x === h.x && p.y === h.y; });
                    if (hit){ api.end(); return; }
                    s.unshift(h);
                    if (h.x === food.x && h.y === food.y){
                        sc += 10; api.setScore(sc); api.sfx('eat'); placeFood();
                        step = Math.max(0.065, 0.12 - (sc / 10) * 0.0016);
                    } else s.pop();
                }
            },
            draw:function(t, state){
                ctx.fillStyle = '#04051a'; ctx.fillRect(0, 0, W, H);
                ctx.fillStyle = 'rgba(255,255,255,.035)';
                for (var y = 0; y < N; y++) for (var x = 0; x < N; x++) if ((x + y) % 2) ctx.fillRect(x*C, y*C, C, C);

                // food
                var pr = 6 + Math.sin(t * 6) * 1.2;
                ctx.save(); ctx.shadowColor = '#fff'; ctx.shadowBlur = 12;
                ctx.fillStyle = '#fff'; ctx.beginPath();
                ctx.arc(food.x*C + C/2, food.y*C + C/2, pr, 0, Math.PI*2); ctx.fill(); ctx.restore();

                // snake
                for (var i = s.length - 1; i >= 0; i--){
                    var k = 1 - i / Math.max(s.length, 8) * 0.55;
                    ctx.fillStyle = 'rgba(255,' + Math.round(45 * k) + ',' + Math.round(45 * k) + ',' + (0.55 + 0.45 * k) + ')';
                    var p = s[i], pad = i === 0 ? 1 : 2;
                    ctx.beginPath();
                    if (ctx.roundRect) ctx.roundRect(p.x*C + pad, p.y*C + pad, C - pad*2, C - pad*2, 5);
                    else ctx.rect(p.x*C + pad, p.y*C + pad, C - pad*2, C - pad*2);
                    ctx.fill();
                }
                // eyes
                var hd = s[0], ex = dir.x, ey = dir.y, cx = hd.x*C + C/2, cy = hd.y*C + C/2;
                var px = -ey * 4, py = ex * 4;
                ctx.fillStyle = '#fff';
                ctx.beginPath(); ctx.arc(cx + ex*3 + px, cy + ey*3 + py, 2.4, 0, 7); ctx.arc(cx + ex*3 - px, cy + ey*3 - py, 2.4, 0, 7); ctx.fill();
                ctx.fillStyle = '#000';
                ctx.beginPath(); ctx.arc(cx + ex*4 + px, cy + ey*4 + py, 1.1, 0, 7); ctx.arc(cx + ex*4 - px, cy + ey*4 - py, 1.1, 0, 7); ctx.fill();
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.18)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   PAC-MAN
══════════════════════════════════════════════════════════════ */
GAMES.pacman = {
    name:'Pac-Man', ovIcon:'ghost',
    help:'Swipe or use arrow keys / WASD. Eat every dot, grab power pellets to chase ghosts.',
    make:function(api, ctx){
        var MAP = [
            "###################",
            "#........#........#",
            "#o##.###.#.###.##o#",
            "#.................#",
            "#.##.#.#####.#.##.#",
            "#....#...#...#....#",
            "####.### # ###.####",
            "   #.#       #.#   ",
            "####.# ##-## #.####",
            "    .  #   #  .    ",
            "####.# ##### #.####",
            "   #.#       #.#   ",
            "####.# ##### #.####",
            "#........#........#",
            "#.##.###.#.###.##.#",
            "#o.#.....P.....#.o#",
            "##.#.#.#####.#.#.##",
            "#....#...#...#....#",
            "#.######.#.######.#",
            "#.................#",
            "###################"
        ];
        var COLS = 19, ROWS = 21, T = 20, W = COLS * T, H = ROWS * T;
        var V = { u:[0,-1], d:[0,1], l:[-1,0], r:[1,0] };
        var CORNER = [[18,0],[0,0],[18,20],[0,20]];
        var GCOL = ['#ff2d2d', '#ffb8de', '#00e5ff', '#ffa63d'];
        var grid, left, pac, gh, lives, level, sc, gMode, gT, fright, chain, deadT, readyT, winT, DEAD = 1.2;

        function build(){
            grid = []; left = 0;
            for (var y = 0; y < ROWS; y++){
                grid[y] = [];
                for (var x = 0; x < COLS; x++){
                    var c = MAP[y].charAt(x), v = 0;
                    if (c === '#') v = 1;
                    else if (c === '.'){ v = 2; left++; }
                    else if (c === 'o'){ v = 3; left++; }
                    else if (c === '-') v = 4;
                    grid[y][x] = v;
                }
            }
        }
        function wx(x){ return (x + COLS) % COLS; }
        function open(x, y, ghost){
            if (y < 0 || y >= ROWS) return false;
            var t = grid[y][wx(x)];
            return t !== 1 && (t !== 4 || ghost);
        }
        function resetPos(){
            pac = { x:9, y:15, d:[-1,0], p:0, moving:false, want:[-1,0] };
            var st = [[9,7,0],[9,9,2],[8,9,4],[10,9,6]];
            gh = st.map(function(a, i){
                return { id:i, x:a[0], y:a[1], d:[0,-1], p:0, moving:false, wait:a[2], leaving:false, fr:false, rev:false };
            });
            fright = 0; chain = 0; readyT = 1.4; gMode = 'scatter'; gT = 0;
        }
        function hud(){ api.extra('Lives ' + lives + '  ·  Lv ' + level); }
        function pSpeed(){ return Math.min(8.5, 6.4 * (1 + 0.05 * (level - 1))); }
        function gSpeed(g){ var b = Math.min(7.5, 5.2 * (1 + 0.05 * (level - 1))); return g.fr ? b * 0.6 : b; }

        function pChoose(e){
            var w = e.want;
            if (w && open(e.x + w[0], e.y + w[1], false)) return w;
            if (open(e.x + e.d[0], e.y + e.d[1], false)) return e.d;
            return null;
        }
        function target(g){
            if (gMode === 'scatter') return CORNER[g.id];
            var px = pac.x, py = pac.y;
            if (g.id === 0) return [px, py];
            if (g.id === 1) return [px + pac.d[0]*4, py + pac.d[1]*4];
            if (g.id === 2){ var b = gh[0]; return [px*2 + pac.d[0]*2 - b.x, py*2 + pac.d[1]*2 - b.y]; }
            return Math.hypot(g.x - px, g.y - py) > 8 ? [px, py] : CORNER[3];
        }
        function gChoose(g){
            if (g.wait > 0) return null;
            if (g.leaving){
                if (g.x !== 9) return [g.x < 9 ? 1 : -1, 0];
                if (g.y > 7) return [0, -1];
                g.leaving = false;
            }
            var opts = [], order = [[0,-1],[-1,0],[0,1],[1,0]];
            for (var i = 0; i < 4; i++){
                var d = order[i];
                if (!g.rev && d[0] === -g.d[0] && d[1] === -g.d[1]) continue;
                var nx = wx(g.x + d[0]), ny = g.y + d[1];
                if (!open(nx, ny, true) || grid[ny][nx] === 4) continue;
                opts.push(d);
            }
            g.rev = false;
            if (!opts.length) return [-g.d[0], -g.d[1]];
            if (g.fr) return opts[rand(opts.length)];
            var tg = target(g), best = null, bd = 1e9;
            opts.forEach(function(d){
                var dx = wx(g.x + d[0]) - tg[0], dy = g.y + d[1] - tg[1], dd = dx*dx + dy*dy;
                if (dd < bd){ bd = dd; best = d; }
            });
            return best;
        }
        function stepEnt(e, dt, speed, choose, arrive){
            var rem = speed * dt;
            while (rem > 0){
                if (!e.moving){
                    var d = choose(e);
                    if (!d) break;
                    e.d = d; e.moving = true; e.p = 0;
                }
                var need = 1 - e.p;
                if (rem >= need){
                    rem -= need;
                    e.x = wx(e.x + e.d[0]); e.y += e.d[1]; e.p = 0; e.moving = false;
                    if (arrive) arrive(e);
                } else { e.p += rem; rem = 0; }
            }
        }
        function eat(e){
            var t = grid[e.y][e.x];
            if (t === 2){ grid[e.y][e.x] = 0; sc += 10; left--; api.setScore(sc); api.sfx('chomp'); }
            else if (t === 3){
                grid[e.y][e.x] = 0; sc += 50; left--; api.setScore(sc);
                fright = Math.max(2.5, 7 - (level - 1) * 0.8); chain = 0;
                gh.forEach(function(g){ g.fr = true; g.rev = true; });
            }
            if (left <= 0) winT = 1.2;
        }
        function ppos(e){ return { x:e.x + e.d[0]*e.p, y:e.y + e.d[1]*e.p }; }

        return {
            W:W, H:H, swipe:true, instant:false,
            preview:function(){
                this.reset(); readyT = 0;
                for (var x = 1; x <= 4; x++){ if (grid[13][x] === 2){ grid[13][x] = 0; left--; } }
                pac = { x:5, y:13, d:[1,0], p:.4, moving:true, want:[1,0] };
                gh[0].x = 2;  gh[0].y = 13; gh[0].d = [1,0]; gh[0].wait = 0;
                gh[1].x = 13; gh[1].y = 13; gh[1].d = [-1,0]; gh[1].wait = 0;
                gh[2].x = 9;  gh[2].y = 7;  gh[2].wait = 0; gh[2].fr = true; fright = 5;
                gh[3].wait = 5;
            },
            reset:function(){
                level = 1; lives = 3; sc = 0; deadT = 0; winT = 0;
                build(); resetPos(); hud();
            },
            dir:function(d){
                var v = V[d]; pac.want = v;
                if (pac.moving && pac.d[0] + v[0] === 0 && pac.d[1] + v[1] === 0){
                    pac.x = wx(pac.x + pac.d[0]); pac.y += pac.d[1];
                    pac.d = [v[0], v[1]]; pac.p = 1 - pac.p;
                }
            },
            tap:function(){},
            update:function(dt){
                if (readyT > 0){ readyT -= dt; return; }
                if (deadT > 0){
                    deadT -= dt;
                    if (deadT <= 0){
                        lives--; hud();
                        if (lives <= 0){ api.end(); return; }
                        resetPos();
                    }
                    return;
                }
                if (winT > 0){
                    winT -= dt;
                    if (winT <= 0){ level++; build(); resetPos(); hud(); }
                    return;
                }
                gT += dt;
                if (gMode === 'scatter' && gT > 7){ gMode = 'chase'; gT = 0; }
                else if (gMode === 'chase' && gT > 20){ gMode = 'scatter'; gT = 0; }
                if (fright > 0){ fright -= dt; if (fright <= 0) gh.forEach(function(g){ g.fr = false; }); }

                stepEnt(pac, dt, pSpeed(), pChoose, eat);
                if (winT > 0) return;

                gh.forEach(function(g){
                    if (g.wait > 0){ g.wait -= dt; if (g.wait <= 0) g.leaving = true; return; }
                    stepEnt(g, dt, gSpeed(g), gChoose, null);
                });

                var a = ppos(pac);
                for (var i = 0; i < gh.length; i++){
                    var g = gh[i]; if (g.wait > 0) continue;
                    var b = ppos(g);
                    if (Math.abs(a.x - b.x) < 0.6 && Math.abs(a.y - b.y) < 0.6){
                        if (g.fr){
                            sc += 200 * Math.pow(2, Math.min(chain, 3)); chain++; api.sfx('ghost');
                            api.setScore(sc);
                            g.fr = false; g.x = 9; g.y = 9; g.moving = false; g.p = 0; g.wait = 2.5; g.leaving = false;
                        } else { deadT = DEAD; break; }
                    }
                }
            },
            draw:function(t, state){
                ctx.fillStyle = '#04051a'; ctx.fillRect(0, 0, W, H);
                var x, y;
                // walls
                ctx.fillStyle = 'rgba(255,45,45,.14)';
                for (y = 0; y < ROWS; y++) for (x = 0; x < COLS; x++) if (grid[y][x] === 1) ctx.fillRect(x*T, y*T, T, T);
                ctx.strokeStyle = 'rgba(255,45,45,.75)'; ctx.lineWidth = 2; ctx.beginPath();
                function isW(a, b){ return a < 0 || b < 0 || a >= COLS || b >= ROWS || grid[b][a] === 1; }
                for (y = 0; y < ROWS; y++) for (x = 0; x < COLS; x++){
                    if (grid[y][x] !== 1) continue;
                    var X = x*T, Y = y*T;
                    if (!isW(x, y-1)){ ctx.moveTo(X, Y+1); ctx.lineTo(X+T, Y+1); }
                    if (!isW(x, y+1)){ ctx.moveTo(X, Y+T-1); ctx.lineTo(X+T, Y+T-1); }
                    if (!isW(x-1, y)){ ctx.moveTo(X+1, Y); ctx.lineTo(X+1, Y+T); }
                    if (!isW(x+1, y)){ ctx.moveTo(X+T-1, Y); ctx.lineTo(X+T-1, Y+T); }
                }
                ctx.stroke();
                // door
                ctx.fillStyle = '#ffb8de'; ctx.fillRect(9*T, 8*T + 8, T, 3);
                // dots
                for (y = 0; y < ROWS; y++) for (x = 0; x < COLS; x++){
                    var v = grid[y][x];
                    if (v === 2){ ctx.fillStyle = 'rgba(255,255,255,.85)'; ctx.beginPath(); ctx.arc(x*T + T/2, y*T + T/2, 2, 0, 7); ctx.fill(); }
                    else if (v === 3){ ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(x*T + T/2, y*T + T/2, 4.5 + Math.sin(t*8), 0, 7); ctx.fill(); }
                }
                // ghosts
                if (deadT <= 0 && winT <= 0 || winT > 0){
                    gh.forEach(function(g){
                        var p = ppos(g), cx = p.x*T + T/2, cy = p.y*T + T/2, r = T*0.46;
                        var col = GCOL[g.id];
                        if (g.fr) col = (fright < 2 && Math.floor(t*6) % 2) ? '#fff' : '#2433ff';
                        ctx.fillStyle = col; ctx.beginPath();
                        ctx.arc(cx, cy, r, Math.PI, 0);
                        ctx.lineTo(cx + r, cy + r);
                        ctx.lineTo(cx + r*.66, cy + r*.7); ctx.lineTo(cx + r*.33, cy + r);
                        ctx.lineTo(cx, cy + r*.7); ctx.lineTo(cx - r*.33, cy + r);
                        ctx.lineTo(cx - r*.66, cy + r*.7); ctx.lineTo(cx - r, cy + r);
                        ctx.closePath(); ctx.fill();
                        if (g.fr){
                            ctx.fillStyle = '#ffd1d1';
                            ctx.fillRect(cx - 4, cy - 2, 2.5, 2.5); ctx.fillRect(cx + 1.5, cy - 2, 2.5, 2.5);
                        } else {
                            ctx.fillStyle = '#fff';
                            ctx.beginPath(); ctx.arc(cx - 3.2, cy - 2, 2.8, 0, 7); ctx.arc(cx + 3.2, cy - 2, 2.8, 0, 7); ctx.fill();
                            ctx.fillStyle = '#10208a';
                            ctx.beginPath(); ctx.arc(cx - 3.2 + g.d[0]*1.4, cy - 2 + g.d[1]*1.4, 1.4, 0, 7);
                            ctx.arc(cx + 3.2 + g.d[0]*1.4, cy - 2 + g.d[1]*1.4, 1.4, 0, 7); ctx.fill();
                        }
                    });
                }
                // pac-man
                var pp = ppos(pac), px = pp.x*T + T/2, py = pp.y*T + T/2, ang = Math.atan2(pac.d[1], pac.d[0]);
                var m = pac.moving ? 0.06 + 0.22 * Math.abs(Math.sin(t * 14)) : 0.2;
                if (deadT > 0) m = Math.min(1, (1 - deadT / DEAD)) ;
                if (!(deadT > 0 && m >= 1)){
                    ctx.fillStyle = '#ffd400'; ctx.beginPath();
                    ctx.moveTo(px, py);
                    ctx.arc(px, py, T*0.46, ang + m*Math.PI, ang + (2 - m)*Math.PI);
                    ctx.closePath(); ctx.fill();
                }
                if (readyT > 0){
                    ctx.fillStyle = '#ffd400'; ctx.font = '700 15px Poppins, sans-serif'; ctx.textAlign = 'center';
                    ctx.fillText('READY!', W/2, 11*T + 15);
                }
                if (winT > 0 && Math.floor(t*8) % 2){ ctx.fillStyle = 'rgba(255,255,255,.12)'; ctx.fillRect(0, 0, W, H); }
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.12)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   FLAPPY BIRD
══════════════════════════════════════════════════════════════ */
GAMES.flappy = {
    name:'Flappy Bird', ovIcon:'bird',
    help:'Tap, click or press Space to flap. Fly through the gaps.',
    make:function(api, ctx){
        var W = 320, H = 480, GY = 430, PW = 54, GAP = 130, GRAV = 1500, JUMP = -430, BX = 90, R = 11;
        var bird, pipes, sc, scroll, speed, flap;

        return {
            W:W, H:H, swipe:false, instant:true,
            preview:function(){
                this.reset(); sc = 12; scroll = 60; bird.y = 215; bird.v = -150; flap = .1;
                pipes = [{x:140,gy:120,passed:true},{x:300,gy:230,passed:false}];
            },
            reset:function(){
                bird = { y:200, v:0 }; pipes = []; sc = 0; scroll = 0; speed = 120; flap = 0;
            },
            begin:function(){ bird.v = JUMP; flap = 0.2; api.sfx('flap'); },
            dir:function(){ this.tap(); },
            tap:function(){ bird.v = JUMP; flap = 0.2; api.sfx('flap'); },
            update:function(dt){
                bird.v += GRAV * dt; bird.y += bird.v * dt;
                if (flap > 0) flap -= dt;
                speed = 120 + Math.min(sc, 40) * 1.6;
                scroll += speed * dt;

                if (!pipes.length || pipes[pipes.length-1].x < W - 175){
                    pipes.push({ x:W + 10, gy:50 + Math.random() * (GY - 100 - GAP), passed:false });
                }
                for (var i = pipes.length - 1; i >= 0; i--){
                    var p = pipes[i]; p.x -= speed * dt;
                    if (p.x + PW < -10){ pipes.splice(i, 1); continue; }
                    if (!p.passed && p.x + PW < BX - R){ p.passed = true; sc++; api.setScore(sc); api.sfx('score'); }
                    if (BX + R > p.x && BX - R < p.x + PW && (bird.y - R < p.gy || bird.y + R > p.gy + GAP)){ api.end(); return; }
                }
                if (bird.y + R > GY){ bird.y = GY - R; api.end(); return; }
                if (bird.y - R < 0){ bird.y = R; api.end(); return; }
            },
            draw:function(t, state){
                var g = ctx.createLinearGradient(0, 0, 0, H);
                g.addColorStop(0, '#060830'); g.addColorStop(1, '#14103a');
                ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
                // stars
                ctx.fillStyle = 'rgba(255,255,255,.35)';
                for (var s = 0; s < 22; s++){
                    var sx = ((s * 97) % W - scroll * 0.1 * (1 + s % 3 * .4) + W * 4) % W, sy = (s * 53) % (GY - 40);
                    ctx.fillRect(sx, sy, 1.5, 1.5);
                }
                // pipes
                pipes.forEach(function(p){
                    var pg = ctx.createLinearGradient(p.x, 0, p.x + PW, 0);
                    pg.addColorStop(0, '#b81414'); pg.addColorStop(.5, '#ff2d2d'); pg.addColorStop(1, '#a01010');
                    ctx.fillStyle = pg;
                    ctx.fillRect(p.x, 0, PW, p.gy);
                    ctx.fillRect(p.x, p.gy + GAP, PW, GY - p.gy - GAP);
                    ctx.fillRect(p.x - 4, p.gy - 22, PW + 8, 22);
                    ctx.fillRect(p.x - 4, p.gy + GAP, PW + 8, 22);
                    ctx.fillStyle = 'rgba(0,0,0,.22)';
                    ctx.fillRect(p.x - 4, p.gy - 22, PW + 8, 3); ctx.fillRect(p.x - 4, p.gy + GAP + 19, PW + 8, 3);
                });
                // ground
                ctx.fillStyle = '#0d0f33'; ctx.fillRect(0, GY, W, H - GY);
                ctx.fillStyle = '#ff2d2d'; ctx.fillRect(0, GY, W, 3);
                ctx.fillStyle = 'rgba(255,45,45,.35)';
                for (var gx = -(scroll % 28); gx < W; gx += 28) ctx.fillRect(gx, GY + 12, 14, 3);

                // bird
                var by = bird.y;
                if (state === 'ready') by = 200 + Math.sin(t * 5) * 7;
                var rot = Math.max(-0.5, Math.min(1.2, bird.v / 600));
                if (state === 'ready') rot = 0;
                ctx.save(); ctx.translate(BX, by); ctx.rotate(rot);
                ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.ellipse(0, 0, 15, 12, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#ffb300'; ctx.beginPath(); ctx.moveTo(12, -1); ctx.lineTo(21, 2); ctx.lineTo(12, 6); ctx.closePath(); ctx.fill();
                ctx.fillStyle = '#ffd0d0'; ctx.beginPath();
                var wy = flap > 0 ? -6 : 3;
                ctx.ellipse(-4, wy, 8, 5, flap > 0 ? -0.5 : 0.3, 0, 7); ctx.fill();
                ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(6, -5, 4, 0, 7); ctx.fill();
                ctx.fillStyle = '#000'; ctx.beginPath(); ctx.arc(7.2, -5, 1.8, 0, 7); ctx.fill();
                ctx.restore();

                // score on canvas
                ctx.fillStyle = '#fff'; ctx.font = '700 34px Poppins, sans-serif'; ctx.textAlign = 'center';
                ctx.globalAlpha = .9; ctx.fillText(sc, W/2, 62); ctx.globalAlpha = 1;
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.15)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   HOPPIBARA  (a capybara hopper in the spirit of Goodgis’ game)
══════════════════════════════════════════════════════════════ */
GAMES.hoppibara = {
    name:'Hoppibara', ovIcon:'heart',
    help:'Tap, click, Space or ↑ to hop over oranges, boxes and other baras. Game by Goodgis.',
    make:function(api, ctx){
        var W = 540, H = 300, GY = H - 73, CX = 200, GRAV = 2300, JUMP = 800;
        var y, vy, onG, obs, sc, speed, scroll, runT, jumpBuf, dustT;
        var sqx, sqy, parts, floats, motes, shake, lastDraw, popT;
        var DARK = '#3a1d0a';
        var P_ME   = { body:'#f5a64b', leg:'#e8963f', head:'#c97b34', ear:'#a5642c', out:'#6b3a14', blush:'rgba(255,80,70,.7)',  nose:'#4a2208' };
        var P_PINK = { body:'#f8b8cf', leg:'#efa2bf', head:'#e68db0', ear:'#d4739a', out:'#a04a6c', blush:'rgba(255,60,110,.6)', nose:'#7a2a48' };
        var P_DARK = { body:'#8b5a3c', leg:'#7a4d32', head:'#5a3721', ear:'#472a18', out:'#2b1608', blush:'rgba(255,100,80,.5)', nose:'#1f0f05' };

        function rr(x, yy, w, h, r){
            ctx.beginPath(); ctx.moveTo(x + r, yy);
            ctx.arcTo(x + w, yy, x + w, yy + h, r); ctx.arcTo(x + w, yy + h, x, yy + h, r);
            ctx.arcTo(x, yy + h, x, yy, r); ctx.arcTo(x, yy, x + w, yy, r);
            ctx.closePath(); ctx.fill(); ctx.stroke();
        }
        function poly(pts){
            ctx.beginPath(); ctx.moveTo(pts[0][0], pts[0][1]);
            for (var i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0], pts[i][1]);
            ctx.closePath(); ctx.fill(); ctx.stroke();
        }
        function orange(x, yy, r, col){
            ctx.lineWidth = 2; ctx.strokeStyle = DARK;
            ctx.fillStyle = col; ctx.beginPath(); ctx.arc(x, yy, r, 0, 7); ctx.fill(); ctx.stroke();
            ctx.fillStyle = '#58b947'; ctx.beginPath(); ctx.ellipse(x + 4, yy - r - 1, 6, 3.4, -0.6, 0, 7); ctx.fill(); ctx.stroke();
            ctx.fillStyle = 'rgba(255,255,255,.4)'; ctx.beginPath(); ctx.arc(x - r*0.35, yy - r*0.35, r*0.22, 0, 7); ctx.fill();
        }
        function box(x){
            var w = 62, bh = 28, top = GY - bh;
            ctx.lineWidth = 2.2; ctx.strokeStyle = DARK; ctx.lineJoin = 'round';
            ctx.fillStyle = '#8a4a1a'; poly([[x+4,top],[x-6,top-11],[x+16,top-13],[x+20,top]]);
            ctx.fillStyle = '#b56a2a'; poly([[x+w-20,top],[x+w-16,top-13],[x+w+6,top-11],[x+w-4,top]]);
            orange(x + 20, top - 2, 9, '#ff9a1f'); orange(x + w - 20, top - 2, 9, '#ff9a1f'); orange(x + w/2, top - 6, 10, '#ffc93c');
            ctx.fillStyle = '#7a3f12'; ctx.beginPath(); ctx.rect(x, top, w/2, bh); ctx.fill(); ctx.stroke();
            ctx.fillStyle = '#a85a1f'; ctx.beginPath(); ctx.rect(x + w/2, top, w/2, bh); ctx.fill(); ctx.stroke();
            ctx.fillStyle = DARK; rr(x + w*0.58, top + 8, 16, 5, 2.5);
        }
        /* one capybara drawer used for the player AND the other baras */
        function capy(x, fy, run, air, pal, flip, sx, sy, ko, size){
            ctx.save(); ctx.translate(x, fy);
            ctx.scale((flip ? -1 : 1) * sx * (size || 1), sy * (size || 1));
            if (air) ctx.rotate(vy > 0 ? -0.06 : 0.05);
            ctx.lineWidth = 1.8; ctx.strokeStyle = pal.out; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
            var sw = air ? 0 : Math.sin(run * 9) * 5, lf = air ? 3 : 0;
            ctx.fillStyle = pal.leg;
            rr(-27 + sw, -13 - lf, 9, 13 - lf, 3.5); rr(-11 - sw, -13 - lf, 9, 13 - lf, 3.5);
            rr(11 - sw, -13 - lf, 9, 13 - lf, 3.5);  rr(26 + sw, -13 - lf, 9, 13 - lf, 3.5);
            ctx.fillStyle = pal.head; ctx.beginPath(); ctx.ellipse(-36, -25, 4.5, 3.6, 0, 0, 7); ctx.fill(); ctx.stroke();
            ctx.fillStyle = pal.body; ctx.beginPath(); ctx.ellipse(0, -25, 37, 21, 0, 0, 7); ctx.fill(); ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(-31,-12); ctx.lineTo(-34,-7); ctx.moveTo(-23,-9); ctx.lineTo(-25,-4);
            ctx.moveTo(-26,-42); ctx.lineTo(-29,-47); ctx.moveTo(-15,-45); ctx.lineTo(-16,-50);
            ctx.stroke();
            ctx.fillStyle = pal.head; rr(19, -51, 34, 31, 12);
            ctx.fillStyle = pal.body; ctx.beginPath(); ctx.ellipse(43, -29, 8, 7, 0, 0, 7); ctx.fill();
            ctx.fillStyle = pal.nose; rr(49, -37, 6, 7, 3);
            ctx.fillStyle = pal.ear; ctx.beginPath(); ctx.arc(26, -51, 4.6, 0, 7); ctx.fill(); ctx.stroke();
            ctx.strokeStyle = '#1a0d04'; ctx.lineWidth = 2.2; ctx.beginPath();
            if (ko){ ctx.moveTo(33,-43); ctx.lineTo(39,-37); ctx.moveTo(39,-43); ctx.lineTo(33,-37); }
            else ctx.arc(37, -40, 3.2, 0.1 * Math.PI, 0.9 * Math.PI);
            ctx.stroke();
            ctx.fillStyle = pal.blush; ctx.beginPath(); ctx.arc(31, -31, 4.3, 0, 7); ctx.fill();
            ctx.restore();
        }
        function drawObs(o, t){
            if (o.t === 'box') box(o.x);
            else if (o.t === 'two'){ orange(o.x + 11, GY - 11, 10, '#ff9a1f'); orange(o.x + 27, GY - 12, 11, '#ffc93c'); }
            else if (o.t === 'one') orange(o.x + 11, GY - 11, 11, '#ff9a1f');
            else {
                var br = 1 + Math.sin(t * 3 + o.x * 0.05) * 0.018;
                capy(o.x + o.w - 8, GY, 0, false, o.pal, true, 1, br, false, 0.75);
            }
        }
        function burst(x, yy, n, cols, spd, up, g){
            for (var i = 0; i < n; i++){
                var a = Math.random() * Math.PI * 2, v = (0.3 + Math.random()) * spd;
                parts.push({ x:x, y:yy, vx:Math.cos(a) * v, vy:Math.sin(a) * v - up, g:g, life:0.5 + Math.random() * 0.4, max:0.9,
                             r:1.5 + Math.random() * 2.5, col:cols[(Math.random() * cols.length) | 0] });
            }
        }
        function dust(x, yy, n){ burst(x, yy - 2, n, ['#fff', '#ffe3b8'], 60, 20, 0); }
        function spawn(){
            var r = Math.random(), o;
            if (r < 0.2)       o = { t:'one',  w:22, h:22, m:5 };
            else if (r < 0.4)  o = { t:'two',  w:38, h:24, m:5 };
            else if (r < 0.62) o = { t:'box',  w:62, h:38, m:4 };
            else if (r < 0.81) o = { t:'bara', w:66, h:34, m:7, pal:P_PINK };
            else               o = { t:'bara', w:66, h:34, m:7, pal:P_DARK };
            o.x = W + 20; o.passed = false; o.gap = 0; obs.push(o);
        }
        function jump(){
            vy = JUMP; onG = false; jumpBuf = 0; sqx = 0.84; sqy = 1.22;
            api.sfx('jump'); dust(CX - 10, GY, 5);
        }

        return {
            W:W, H:H, swipe:false, instant:true,
            resize:function(a){
                var nh = Math.round(Math.max(300, Math.min(440, W / a)));
                if (nh === H) return false;
                H = nh; GY = H - 73; this.H = H; return true;
            },
            reset:function(){
                y = 0; vy = 0; onG = true; obs = []; sc = 0; speed = 260; scroll = 0; runT = 0; jumpBuf = 0; dustT = 0;
                sqx = 1; sqy = 1; parts = []; floats = []; shake = 0; lastDraw = 0; popT = 0;
                motes = [];
                for (var i = 0; i < 26; i++) motes.push({ x:Math.random() * W, y:Math.random() * 440, r:0.8 + Math.random() * 1.6, vx:-4 - Math.random() * 8, vy:2 + Math.random() * 5, ph:Math.random() * 6 });
            },
            preview:function(){
                this.reset(); sc = 6; scroll = 30;
                obs = [{ t:'box', w:62, h:38, m:4, x:50, passed:true, gap:0 }, { t:'bara', w:66, h:34, m:7, x:395, passed:false, gap:0, pal:P_PINK }];
            },
            tap:function(){ jumpBuf = 0.12; if (onG) jump(); },
            dir:function(d){ if (d === 'u') this.tap(); else if (d === 'd' && !onG) vy = Math.min(vy, -650); },
            update:function(dt){
                speed = Math.min(560, 260 + sc * 8);
                scroll += speed * dt; runT += dt * speed / 260;
                if (onG && jumpBuf > 0) jump();
                jumpBuf -= dt;
                if (!onG){
                    vy -= GRAV * dt; y += vy * dt;
                    if (y <= 0){ y = 0; vy = 0; onG = true; sqx = 1.2; sqy = 0.78; dust(CX, GY, 9); api.sfx('land'); }
                } else {
                    dustT -= dt;
                    if (dustT <= 0){ dustT = 0.09; parts.push({ x:CX - 22, y:GY - 3, vx:-speed * 0.25, vy:-10 - Math.random() * 20, g:0, life:0.35, max:0.35, r:2 + Math.random() * 2, col:'#fff' }); }
                }
                var last = obs[obs.length - 1];
                if (!last || last.x < W - last.gap){
                    spawn(); obs[obs.length - 1].gap = speed * 0.8 + 150 + Math.random() * 200;
                }
                var L = CX - 20, R = CX + 30;
                for (var i = obs.length - 1; i >= 0; i--){
                    var o = obs[i]; o.x -= speed * dt;
                    if (o.x + o.w < -60){ obs.splice(i, 1); continue; }
                    if (!o.passed && o.x + o.w < CX - 36){
                        o.passed = true; sc++; api.setScore(sc); popT = 0.25;
                        floats.push({ x:CX + 6, y:GY - y - 78, t:0.9, txt:'+1' });
                        if (sc % 10 === 0){
                            api.sfx('level'); floats.push({ x:W / 2, y:100, t:1.3, txt:sc + '!', big:true });
                            burst(W / 2, 80, 30, ['#ff9a1f', '#ffc93c', '#f8b8cf', '#fff', '#58b947'], 220, 180, 420);
                        } else api.sfx('score');
                    }
                    if (R > o.x + o.m && L < o.x + o.w - o.m && y + 4 < o.h){ shake = 0.5; api.end(); return; }
                }
            },
            draw:function(t, state){
                var dd = (state === 'preview') ? 0 : Math.min(0.05, Math.max(0, t - lastDraw));
                lastDraw = t;
                if (state !== 'paused' && dd > 0){
                    sqx += (1 - sqx) * Math.min(1, dd * 14); sqy += (1 - sqy) * Math.min(1, dd * 14);
                    if (shake > 0) shake = Math.max(0, shake - dd * 1.3);
                    if (popT > 0) popT -= dd;
                    for (var k = parts.length - 1; k >= 0; k--){
                        var p = parts[k]; p.x += p.vx * dd; p.y += p.vy * dd; p.vy += p.g * dd; p.life -= dd;
                        if (p.life <= 0) parts.splice(k, 1);
                    }
                    for (k = floats.length - 1; k >= 0; k--){ floats[k].y -= 38 * dd; floats[k].t -= dd; if (floats[k].t <= 0) floats.splice(k, 1); }
                    motes.forEach(function(m){ m.x += m.vx * dd; m.y += m.vy * dd; if (m.x < -5) m.x = W + 5; if (m.y > GY) m.y = 0; });
                }
                ctx.save();
                if (shake > 0) ctx.translate((Math.random() - 0.5) * shake * 22, (Math.random() - 0.5) * shake * 22);

                // room
                ctx.fillStyle = '#ffd596'; ctx.fillRect(-20, -20, W + 40, H + 40);
                var wy = GY - 185, ww = 76, wh = 77, wxs = [75, 438], i;
                // wall decor (only visible on tall screens)
                if (GY > 280){
                    ctx.strokeStyle = '#d99a4e'; ctx.lineWidth = 1.5;
                    ctx.beginPath(); ctx.moveTo(300, 0); ctx.lineTo(300, GY - 262); ctx.stroke();
                    ctx.fillStyle = '#f2994a'; ctx.fillRect(288, GY - 262, 24, 20);
                    ctx.fillStyle = '#58b947';
                    for (i = 0; i < 5; i++){ ctx.beginPath(); ctx.ellipse(294 + i * 3, GY - 268 - (i % 2) * 8, 9, 5, -0.6 + i * 0.35, 0, 7); ctx.fill(); }
                    ctx.fillStyle = '#fff'; ctx.fillRect(170, GY - 300, 46, 58); ctx.fillStyle = '#ffcb85'; ctx.fillRect(176, GY - 294, 34, 46);
                    ctx.fillStyle = '#ff9a1f'; ctx.beginPath(); ctx.arc(193, GY - 271, 9, 0, 7); ctx.fill();
                    ctx.fillStyle = '#fff'; ctx.fillRect(390, GY - 292, 62, 44); ctx.fillStyle = '#ffcb85'; ctx.fillRect(396, GY - 286, 50, 32);
                }
                // light shafts (soft shimmer)
                ctx.fillStyle = 'rgba(255,255,255,' + (0.93 + Math.sin(t * 1.4) * 0.05) + ')';
                for (i = 0; i < 2; i++){
                    var wx = wxs[i];
                    ctx.beginPath();
                    ctx.moveTo(wx + ww, wy); ctx.lineTo(wx + ww + (GY - wy), GY);
                    ctx.lineTo(wx + (GY - wy - wh), GY); ctx.lineTo(wx, wy + wh); ctx.closePath(); ctx.fill();
                }
                // windows
                for (i = 0; i < 2; i++){
                    var x0 = wxs[i];
                    ctx.fillStyle = '#ffe3b8'; ctx.fillRect(x0 - 3, wy - 3, ww + 6, wh + 6);
                    ctx.fillStyle = '#fff'; ctx.fillRect(x0, wy, ww, wh);
                    ctx.fillStyle = '#ffe3b8';
                    ctx.fillRect(x0 + ww / 2 - 2.5, wy, 5, wh); ctx.fillRect(x0, wy + wh / 2 - 2.5, ww, 5);
                }
                // dust motes drifting in the light
                motes.forEach(function(m){
                    if (m.y > GY - 4) return;
                    ctx.fillStyle = 'rgba(255,255,255,' + (0.35 + 0.35 * Math.sin(t * 2 + m.ph)) + ')';
                    ctx.beginPath(); ctx.arc(m.x, m.y, m.r, 0, 7); ctx.fill();
                });
                // floor
                ctx.fillStyle = '#fff'; ctx.fillRect(-20, GY, W + 40, H - GY + 20);
                var s = 17, off = scroll % s, base = Math.floor(scroll / s);
                for (var r = 0; r < 2; r++){
                    for (var c = -1; c <= Math.ceil(W / s) + 1; c++){
                        ctx.fillStyle = ((c + base + r) % 2 === 0) ? '#ffcb85' : '#fff';
                        ctx.fillRect(c * s - off, GY + 3 + r * s, s + 0.5, s);
                    }
                }
                ctx.fillStyle = '#111'; ctx.fillRect(-20, GY, W + 40, 3);
                // speed lines
                if (state === 'playing' && speed > 380){
                    ctx.strokeStyle = 'rgba(255,255,255,.55)'; ctx.lineWidth = 2;
                    for (i = 0; i < 6; i++){
                        var lx = ((i * 131 - scroll * 2.2) % (W + 80) + (W + 80)) % (W + 80) - 40, ly = GY - 20 - i * 24;
                        ctx.beginPath(); ctx.moveTo(lx, ly); ctx.lineTo(lx + 36, ly); ctx.stroke();
                    }
                }
                // obstacles
                obs.forEach(function(o){ drawObs(o, t); });
                // shadow + player
                var sh = Math.max(0.35, 1 - y / 220);
                ctx.fillStyle = 'rgba(90,50,16,.22)'; ctx.beginPath(); ctx.ellipse(CX + 6, GY + 5, 36 * sh, 5 * sh, 0, 0, 7); ctx.fill();
                var bob = (state === 'playing' && onG) ? Math.abs(Math.sin(runT * 9)) * 1.5 : (state === 'ready' ? Math.sin(t * 3) * 0.8 : 0);
                capy(CX, GY - y - bob, runT, !onG, P_ME, false, sqx, sqy, state === 'over', 1);
                // particles
                parts.forEach(function(p){
                    ctx.globalAlpha = Math.max(0, Math.min(1, p.life / p.max * 1.4));
                    ctx.fillStyle = p.col; ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 7); ctx.fill();
                });
                ctx.globalAlpha = 1;
                // floating texts
                ctx.textAlign = 'center';
                floats.forEach(function(f){
                    ctx.globalAlpha = Math.min(1, f.t * 1.6);
                    ctx.fillStyle = f.big ? '#ff2d2d' : '#111'; ctx.font = '700 ' + (f.big ? 30 : 17) + 'px Poppins, sans-serif';
                    ctx.fillText(f.txt, f.x, f.y);
                });
                ctx.globalAlpha = 1;
                // score
                var ps = 1 + Math.max(0, popT) * 1.2;
                ctx.save(); ctx.translate(W / 2, 56); ctx.scale(ps, ps);
                ctx.fillStyle = '#111'; ctx.font = '700 40px Poppins, sans-serif'; ctx.fillText(sc, 0, 0);
                ctx.restore();
                // warm vignette
                var vg = ctx.createRadialGradient(W / 2, H / 2, H * 0.35, W / 2, H / 2, Math.max(W, H) * 0.75);
                vg.addColorStop(0, 'rgba(120,50,0,0)'); vg.addColorStop(1, 'rgba(120,50,0,.2)');
                ctx.fillStyle = vg; ctx.fillRect(-20, -20, W + 40, H + 40);
                ctx.restore();
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,255,255,.2)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   2048 (puzzle)
══════════════════════════════════════════════════════════════ */
GAMES.g2048 = {
    name:'2048', ovIcon:'hash',
    help:'Swipe or use arrow keys / WASD. Merge equal tiles to reach 2048.',
    make:function(api, ctx){
        var W = 400, H = 400, PAD = 12, GAP = 10, CS = (W - PAD * 2 - GAP * 3) / 4, ANIM = 0.1, POP = 0.18;
        var g, sc, anim, animT, pops, after, pending, dying, endT;
        var COL = {
            2:['#eceaff','#1b1d4a'], 4:['#d6d3ff','#1b1d4a'], 8:['#ffb4a8','#fff'], 16:['#ff8e80','#fff'],
            32:['#ff6a5e','#fff'], 64:['#ff4a40','#fff'], 128:['#ff2d2d','#fff'], 256:['#e01f4d','#fff'],
            512:['#c21a6a','#fff'], 1024:['#9a1a8a','#fff'], 2048:['#ffd400','#3a2a00']
        };
        function blank(){ var a = [], r; for (r = 0; r < 4; r++) a.push([0,0,0,0]); return a; }
        function at(d, i, j){ return d === 'l' ? [i, j] : d === 'r' ? [i, 3 - j] : d === 'u' ? [j, i] : [3 - j, i]; }
        function rp(x, yy, w, h, r){
            ctx.beginPath(); ctx.moveTo(x + r, yy);
            ctx.arcTo(x + w, yy, x + w, yy + h, r); ctx.arcTo(x + w, yy + h, x, yy + h, r);
            ctx.arcTo(x, yy + h, x, yy, r); ctx.arcTo(x, yy, x + w, yy, r); ctx.closePath();
        }
        function addTile(){
            var e = [], r, c;
            for (r = 0; r < 4; r++) for (c = 0; c < 4; c++) if (!g[r][c]) e.push([r, c]);
            if (!e.length) return null;
            var p = e[rand(e.length)]; g[p[0]][p[1]] = Math.random() < 0.9 ? 2 : 4; return p;
        }
        function canMove(){
            for (var r = 0; r < 4; r++) for (var c = 0; c < 4; c++){
                var v = g[r][c];
                if (!v) return true;
                if (c < 3 && g[r][c+1] === v) return true;
                if (r < 3 && g[r+1][c] === v) return true;
            }
            return false;
        }
        function move(d){
            var ng = blank(), mv = [], merges = [], gain = 0, moved = false, i, j;
            for (i = 0; i < 4; i++){
                var list = [];
                for (j = 0; j < 4; j++){ var rc = at(d, i, j), v = g[rc[0]][rc[1]]; if (v) list.push({ v:v, fr:rc }); }
                var k = 0;
                for (var a = 0; a < list.length; a++, k++){
                    var t = list[a], dest = at(d, i, k);
                    if (a + 1 < list.length && list[a+1].v === t.v){
                        var n2 = list[a+1], nv = t.v * 2;
                        ng[dest[0]][dest[1]] = nv; gain += nv;
                        mv.push({ v:t.v, fr:t.fr, to:dest }, { v:n2.v, fr:n2.fr, to:dest });
                        merges.push(dest); a++; moved = true;
                    } else {
                        ng[dest[0]][dest[1]] = t.v;
                        mv.push({ v:t.v, fr:t.fr, to:dest });
                        if (t.fr[0] !== dest[0] || t.fr[1] !== dest[1]) moved = true;
                    }
                }
            }
            if (!moved) return;
            g = ng; sc += gain; api.setScore(sc);
            var np = addTile();
            anim = mv; animT = ANIM;
            after = merges.map(function(m){ return { k:m[0] + ',' + m[1], kind:'merge' }; });
            if (np) after.push({ k:np[0] + ',' + np[1], kind:'new' });
            api.sfx(gain ? 'merge' : 'slide');
            if (!canMove()){ dying = true; endT = 0.9; }
        }
        function tile(x, yy, v, s){
            var col = COL[v] || ['#ffd400', '#3a2a00'], cx = x + CS / 2, cy = yy + CS / 2, w = CS * s;
            ctx.fillStyle = col[0]; rp(cx - w / 2, cy - w / 2, w, w, 10 * s); ctx.fill();
            ctx.fillStyle = col[1]; ctx.font = '700 ' + ((v < 100 ? 42 : v < 1000 ? 36 : 28) * s) + 'px Poppins, sans-serif';
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(v, cx, cy + 2); ctx.textBaseline = 'alphabetic';
        }
        return {
            W:W, H:H, swipe:true, once:true, instant:false,
            reset:function(){
                g = blank(); sc = 0; anim = null; animT = 0; pops = {}; after = []; pending = null; dying = false; endT = 0;
                addTile(); addTile();
            },
            preview:function(){
                this.reset();
                g = [[0,2,4,8],[2,16,32,64],[4,8,128,256],[2,4,512,1024]];
            },
            dir:function(d){ if (dying) return; if (animT > 0){ pending = d; return; } move(d); },
            tap:function(){},
            update:function(dt){
                if (animT > 0){
                    animT -= dt;
                    if (animT <= 0){
                        animT = 0; anim = null;
                        after.forEach(function(p){ pops[p.k] = { t:POP, kind:p.kind }; }); after = [];
                        if (pending && !dying){ var d = pending; pending = null; move(d); }
                    }
                }
                for (var k in pops){ pops[k].t -= dt; if (pops[k].t <= 0) delete pops[k]; }
                if (dying && animT <= 0){ endT -= dt; if (endT <= 0){ dying = false; api.end({ title:'No more moves' }); } }
            },
            draw:function(t, state){
                var r, c;
                ctx.fillStyle = '#070924'; ctx.fillRect(0, 0, W, H);
                ctx.fillStyle = '#10133a'; rp(0, 0, W, H, 14); ctx.fill();
                ctx.fillStyle = '#1a1e52';
                for (r = 0; r < 4; r++) for (c = 0; c < 4; c++){ rp(PAD + c * (CS + GAP), PAD + r * (CS + GAP), CS, CS, 10); ctx.fill(); }
                if (animT > 0 && anim){
                    var p = 1 - animT / ANIM; p = p * (2 - p);
                    anim.forEach(function(m){
                        tile(PAD + (m.fr[1] + (m.to[1] - m.fr[1]) * p) * (CS + GAP), PAD + (m.fr[0] + (m.to[0] - m.fr[0]) * p) * (CS + GAP), m.v, 1);
                    });
                } else {
                    for (r = 0; r < 4; r++) for (c = 0; c < 4; c++){
                        var v = g[r][c]; if (!v) continue;
                        var pp = pops[r + ',' + c], s = 1;
                        if (pp){ var q = 1 - pp.t / POP; s = pp.kind === 'new' ? 0.4 + 0.6 * q : 1 + 0.14 * Math.sin(q * Math.PI); }
                        tile(PAD + c * (CS + GAP), PAD + r * (CS + GAP), v, s);
                    }
                }
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.12)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   SUDOKU (Easy / Medium / Hard)
══════════════════════════════════════════════════════════════ */
GAMES.sudoku = {
    name:'Sudoku', ovIcon:'grid',
    help:'Fill every row, column and 3×3 box with 1–9. Three mistakes and it’s over.',
    make:function(api, ctx, host){
        var LV = {
            easy:   { n:'Easy',   remove:40, base:100 },
            medium: { n:'Medium', remove:49, base:200 },
            hard:   { n:'Hard',   remove:55, base:300 }
        };
        var level = 'easy', sol = [], given = [], vals = [], notes = [], sel = -1, noteMode = false;
        var mistakes = 0, elapsed = 0, lastSec = -1, cells = [], pad = [], notesBtn = null;

        function el(tag, cls, txt){ var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
        function fmt(sec){ var m = Math.floor(sec / 60), s = sec % 60; return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s; }

        /* backtracking search with candidate bitmasks; stops after `limit` solutions */
        function search(grid, limit, rnd, out){
            var rows = [0,0,0,0,0,0,0,0,0], cols = rows.slice(), bx = rows.slice(), k, n = 0;
            for (k = 0; k < 81; k++){
                var v = grid[k];
                if (v){ var r0 = (k / 9) | 0, c0 = k % 9, m0 = 1 << (v - 1); rows[r0] |= m0; cols[c0] |= m0; bx[((r0 / 3) | 0) * 3 + ((c0 / 3) | 0)] |= m0; }
            }
            function rec(){
                var best = -1, bc = 10, bm = 0, k2;
                for (k2 = 0; k2 < 81; k2++){
                    if (grid[k2]) continue;
                    var r = (k2 / 9) | 0, c = k2 % 9, b = ((r / 3) | 0) * 3 + ((c / 3) | 0);
                    var m = ~(rows[r] | cols[c] | bx[b]) & 511, cnt = 0, t = m;
                    while (t){ t &= t - 1; cnt++; }
                    if (cnt < bc){ bc = cnt; best = k2; bm = m; if (cnt < 2) break; }
                }
                if (best < 0){ n++; if (out && n === 1) for (var q = 0; q < 81; q++) out[q] = grid[q]; return; }
                if (bc === 0) return;
                var r1 = (best / 9) | 0, c1 = best % 9, b1 = ((r1 / 3) | 0) * 3 + ((c1 / 3) | 0), ds = [], d;
                for (d = 1; d <= 9; d++) if (bm & (1 << (d - 1))) ds.push(d);
                if (rnd) ds.sort(function(){ return Math.random() - 0.5; });
                for (var i = 0; i < ds.length; i++){
                    d = ds[i]; var mk = 1 << (d - 1);
                    grid[best] = d; rows[r1] |= mk; cols[c1] |= mk; bx[b1] |= mk;
                    rec();
                    rows[r1] &= ~mk; cols[c1] &= ~mk; bx[b1] &= ~mk; grid[best] = 0;
                    if (n >= limit) return;
                }
            }
            rec(); return n;
        }
        function generate(lv){
            var full = [], i; for (i = 0; i < 81; i++) full[i] = 0;
            var s = []; search(full, 1, true, s); sol = s;
            var g = s.slice(), order = [], removed = 0;
            for (i = 0; i < 81; i++) order.push(i);
            order.sort(function(){ return Math.random() - 0.5; });
            for (i = 0; i < 81 && removed < LV[lv].remove; i++){
                var k = order[i], t = g[k]; g[k] = 0;
                if (search(g.slice(), 2, false) !== 1) g[k] = t; else removed++;
            }
            given = g.map(function(v){ return v > 0; }); vals = g.slice();
            notes = []; for (i = 0; i < 81; i++) notes[i] = 0;
        }
        function hud(){ api.extra(LV[level].n + '  ·  ' + fmt(Math.floor(elapsed)) + '  ·  ✕ ' + mistakes + '/3'); }

        function build(){
            host.innerHTML = '';
            var wrap = el('div', 'sd-wrap'), grid = el('div', 'sd-grid'), i;
            cells = [];
            for (i = 0; i < 81; i++){
                var c = el('div', 'sd-c');
                (function(i){ c.onclick = function(e){ e.stopPropagation(); sel = i; api.sfx('pop'); render(); }; })(i);
                grid.appendChild(c); cells.push(c);
            }
            var padEl = el('div', 'sd-pad'); pad = [];
            for (var d = 1; d <= 9; d++){
                var b = el('button', 'sd-n', d); b.type = 'button';
                (function(d){ b.onclick = function(e){ e.stopPropagation(); place(d); }; })(d);
                padEl.appendChild(b); pad.push(b);
            }
            var tools = el('div', 'sd-tools');
            var er = el('button', 'sd-t'); er.type = 'button'; er.innerHTML = ic('erase') + ' Erase';
            er.onclick = function(e){ e.stopPropagation(); erase(); };
            notesBtn = el('button', 'sd-t'); notesBtn.type = 'button';
            notesBtn.onclick = function(e){ e.stopPropagation(); toggleNotes(); };
            tools.appendChild(er); tools.appendChild(notesBtn);
            wrap.appendChild(grid); wrap.appendChild(padEl); wrap.appendChild(tools);
            host.appendChild(wrap);
            syncNotesBtn();
        }
        function syncNotesBtn(){
            if (!notesBtn) return;
            notesBtn.className = 'sd-t' + (noteMode ? ' on' : '');
            notesBtn.innerHTML = ic('pencil') + ' Notes: ' + (noteMode ? 'On' : 'Off');
        }
        function toggleNotes(){ noteMode = !noteMode; api.sfx('pop'); syncNotesBtn(); }

        function render(){
            var sv = sel >= 0 ? vals[sel] : 0, sr = (sel / 9) | 0, sc2 = sel % 9, sb = ((sr / 3) | 0) * 3 + ((sc2 / 3) | 0);
            var count = [0,0,0,0,0,0,0,0,0,0], i;
            for (i = 0; i < 81; i++){
                var r = (i / 9) | 0, c = i % 9, b = ((r / 3) | 0) * 3 + ((c / 3) | 0), v = vals[i];
                if (v && v === sol[i]) count[v]++;
                var cls = 'sd-c' + (c % 3 === 2 && c < 8 ? ' br' : '') + (r % 3 === 2 && r < 8 ? ' bb' : '');
                if (given[i]) cls += ' g'; else if (v && v !== sol[i]) cls += ' wrong';
                if (sel >= 0){
                    if (i === sel) cls += ' sel';
                    else if (r === sr || c === sc2 || b === sb) cls += ' peer';
                    if (sv && v === sv && i !== sel) cls += ' same';
                }
                cells[i].className = cls;
                if (v) cells[i].textContent = v;
                else if (notes[i]){
                    var h = '<div class="sd-notes">';
                    for (var d = 1; d <= 9; d++) h += '<span>' + ((notes[i] & (1 << (d - 1))) ? d : '&nbsp;') + '</span>';
                    cells[i].innerHTML = h + '</div>';
                } else cells[i].textContent = '';
            }
            for (i = 1; i <= 9; i++) pad[i - 1].classList.toggle('done', count[i] >= 9);
        }
        function place(d){
            if (sel < 0 || given[sel]) return;
            if (vals[sel] && vals[sel] === sol[sel]) return;
            if (noteMode){
                if (vals[sel]) return;
                notes[sel] ^= (1 << (d - 1)); api.sfx('pop'); render(); return;
            }
            vals[sel] = d; notes[sel] = 0;
            if (d !== sol[sel]){
                mistakes++; api.sfx('wrong'); hud(); render();
                if (mistakes >= 3){ api.setScore(0); api.end({ title:'Out of mistakes', sub:'Three wrong numbers. Try another puzzle!' }); }
                return;
            }
            api.sfx('place');
            var r = (sel / 9) | 0, c = sel % 9, b = ((r / 3) | 0) * 3 + ((c / 3) | 0), m = ~(1 << (d - 1));
            for (var i = 0; i < 81; i++){
                var ri = (i / 9) | 0, ci = i % 9;
                if (ri === r || ci === c || (((ri / 3) | 0) * 3 + ((ci / 3) | 0)) === b) notes[i] &= m;
            }
            render();
            for (i = 0; i < 81; i++) if (vals[i] !== sol[i]) return;
            var sec = Math.floor(elapsed), L = LV[level];
            var score = Math.max(10, L.base + Math.max(0, 100 - Math.floor(sec / 6)) - 15 * mistakes);
            api.setScore(score);
            api.end({ win:true, title:'Puzzle solved!', sub:L.n + '  ·  ' + fmt(sec) + '  ·  ' + mistakes + (mistakes === 1 ? ' mistake' : ' mistakes') });
        }
        function erase(){
            if (sel < 0 || given[sel] || vals[sel] === sol[sel]) return;
            vals[sel] = 0; notes[sel] = 0; api.sfx('pop'); render();
        }

        return {
            dom:true, W:400, H:400, TW:400, TH:250,
            levels:[{ k:'easy', n:'Easy' }, { k:'medium', n:'Medium' }, { k:'hard', n:'Hard' }],
            setLevel:function(k){
                level = k; mistakes = 0; elapsed = 0; lastSec = -1; sel = -1; noteMode = false;
                generate(k); build(); render(); hud();
            },
            reset:function(){ host.innerHTML = ''; mistakes = 0; elapsed = 0; },
            update:function(dt){
                elapsed += dt;
                var sec = Math.floor(elapsed);
                if (sec !== lastSec){ lastSec = sec; hud(); }
            },
            key:function(e){
                var k = e.key, i;
                if (k >= '1' && k <= '9'){ place(+k); return true; }
                if (k === 'Backspace' || k === 'Delete' || k === '0'){ erase(); return true; }
                if (k === 'n' || k === 'N'){ toggleNotes(); return true; }
                var mv = { ArrowUp:-9, ArrowDown:9, ArrowLeft:-1, ArrowRight:1 }[k];
                if (mv){
                    if (sel < 0) sel = 40;
                    else {
                        var r = (sel / 9) | 0, c = sel % 9;
                        if ((k === 'ArrowUp' && r > 0) || (k === 'ArrowDown' && r < 8) || (k === 'ArrowLeft' && c > 0) || (k === 'ArrowRight' && c < 8)) sel += mv;
                    }
                    render(); return true;
                }
                return false;
            },
            thumb:function(cx){
                var W2 = 400, H2 = 250, S = 226, x0 = (W2 - S) / 2, y0 = (H2 - S) / 2, cs = S / 9, i;
                var P = '530070000600195000098000060800060003400803001700020006060000280000419005000080079';
                cx.fillStyle = '#070924'; cx.fillRect(0, 0, W2, H2);
                cx.fillStyle = 'rgba(255,45,45,.4)'; cx.fillRect(x0 + 4 * cs, y0 + 4 * cs, cs, cs);
                cx.fillStyle = 'rgba(255,255,255,.07)';
                for (i = 0; i < 9; i++){ cx.fillRect(x0 + 4 * cs, y0 + i * cs, cs, cs); cx.fillRect(x0 + i * cs, y0 + 4 * cs, cs, cs); }
                cx.fillStyle = 'rgba(255,45,45,.4)'; cx.fillRect(x0 + 4 * cs, y0 + 4 * cs, cs, cs);
                for (i = 0; i <= 9; i++){
                    cx.strokeStyle = (i % 3 === 0) ? 'rgba(255,45,45,.85)' : 'rgba(255,255,255,.14)'; cx.lineWidth = (i % 3 === 0) ? 2 : 1;
                    cx.beginPath(); cx.moveTo(x0 + i * cs, y0); cx.lineTo(x0 + i * cs, y0 + S); cx.moveTo(x0, y0 + i * cs); cx.lineTo(x0 + S, y0 + i * cs); cx.stroke();
                }
                cx.font = '600 17px Poppins, sans-serif'; cx.textAlign = 'center'; cx.textBaseline = 'middle';
                for (i = 0; i < 81; i++){
                    var ch = P.charAt(i); if (ch === '0') continue;
                    cx.fillStyle = (i % 7 === 0) ? '#7fb2ff' : '#fff';
                    cx.fillText(ch, x0 + (i % 9) * cs + cs / 2, y0 + ((i / 9) | 0) * cs + cs / 2 + 1);
                }
                cx.textBaseline = 'alphabetic';
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   CHESS — play white vs the computer (full rules, 3 levels)
══════════════════════════════════════════════════════════════ */
GAMES.chess = {
    name:'Chess', ovIcon:'crown',
    help:'You play white. Tap a piece, then a highlighted square. Capture material and checkmate the computer.',
    make:function(api, ctx){
        var W = 400, H = 400, S = 50;
        var VAL = { p:100, n:320, b:330, r:500, q:900, k:20000 };
        var PTS = { p:1, n:3, b:3, r:5, q:9, k:0 };
        var GLY = { k:'\u265A', q:'\u265B', r:'\u265C', b:'\u265D', n:'\u265E', p:'\u265F' };
        var N_OFF = [[-2,-1],[-2,1],[-1,-2],[-1,2],[1,-2],[1,2],[2,-1],[2,1]];
        var K_OFF = [[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]];
        var B_DIR = [[-1,-1],[-1,1],[1,-1],[1,1]], R_DIR = [[-1,0],[1,0],[0,-1],[0,1]];
        var LEVELS = { easy:{ d:1, noise:140, bonus:60 }, medium:{ d:2, noise:20, bonus:150 }, hard:{ d:3, noise:6, bonus:300 } };
        var lv = LEVELS.medium, S0, sel, targets, last, busy, over, capPts, dead, timer, chkSq, deadline = 0, aborted = false, nodes = 0;

        function isW(p){ return p && p === p.toUpperCase(); }
        function inb(r, c){ return r >= 0 && r < 8 && c >= 0 && c < 8; }
        function init(){
            var rows = ['rnbqkbnr','pppppppp','','','','','PPPPPPPP','RNBQKBNR'], b = [], r, c;
            for (r = 0; r < 8; r++) for (c = 0; c < 8; c++) b.push(rows[r] ? rows[r].charAt(c) : '');
            return { b:b, t:'w', cr:'KQkq', ep:-1, hm:0, cap:'' };
        }
        function attacked(b, sq, by){
            var r = sq >> 3, c = sq & 7, i, d, p, rr, cc, P = by === 'w';
            rr = P ? r + 1 : r - 1;
            if (inb(rr, c - 1) && b[rr*8 + c - 1] === (P ? 'P' : 'p')) return true;
            if (inb(rr, c + 1) && b[rr*8 + c + 1] === (P ? 'P' : 'p')) return true;
            var N = P ? 'N' : 'n', K = P ? 'K' : 'k', B = P ? 'B' : 'b', R = P ? 'R' : 'r', Q = P ? 'Q' : 'q';
            for (i = 0; i < 8; i++){
                rr = r + N_OFF[i][0]; cc = c + N_OFF[i][1]; if (inb(rr, cc) && b[rr*8 + cc] === N) return true;
                rr = r + K_OFF[i][0]; cc = c + K_OFF[i][1]; if (inb(rr, cc) && b[rr*8 + cc] === K) return true;
            }
            for (i = 0; i < 4; i++){
                d = B_DIR[i]; rr = r + d[0]; cc = c + d[1];
                while (inb(rr, cc)){ p = b[rr*8 + cc]; if (p){ if (p === B || p === Q) return true; break; } rr += d[0]; cc += d[1]; }
                d = R_DIR[i]; rr = r + d[0]; cc = c + d[1];
                while (inb(rr, cc)){ p = b[rr*8 + cc]; if (p){ if (p === R || p === Q) return true; break; } rr += d[0]; cc += d[1]; }
            }
            return false;
        }
        function kp(b, color){ var K = color === 'w' ? 'K' : 'k'; for (var i = 0; i < 64; i++) if (b[i] === K) return i; return -1; }
        function inChk(st){ return attacked(st.b, kp(st.b, st.t), st.t === 'w' ? 'b' : 'w'); }

        function gen(st){
            var b = st.b, W_ = st.t === 'w', opp = W_ ? 'b' : 'w', ms = [], sq, p, r, c, u, tr, tc, tp, d, i;
            for (sq = 0; sq < 64; sq++){
                p = b[sq]; if (!p || isW(p) !== W_) continue;
                r = sq >> 3; c = sq & 7; u = p.toLowerCase();
                if (u === 'p'){
                    var dr = W_ ? -1 : 1, s0 = W_ ? 6 : 1, pr = W_ ? 0 : 7;
                    tr = r + dr;
                    if (inb(tr, c) && !b[tr*8 + c]){
                        if (tr === pr) ms.push({ f:sq, t:tr*8 + c, pr:W_ ? 'Q' : 'q' }); else ms.push({ f:sq, t:tr*8 + c });
                        if (r === s0 && !b[(r + 2*dr)*8 + c]) ms.push({ f:sq, t:(r + 2*dr)*8 + c, dp:1 });
                    }
                    for (d = -1; d <= 1; d += 2){
                        tc = c + d; if (!inb(tr, tc)) continue; tp = b[tr*8 + tc];
                        if (tp && isW(tp) !== W_){
                            if (tr === pr) ms.push({ f:sq, t:tr*8 + tc, pr:W_ ? 'Q' : 'q' }); else ms.push({ f:sq, t:tr*8 + tc });
                        } else if (!tp && tr*8 + tc === st.ep) ms.push({ f:sq, t:tr*8 + tc, ep:1 });
                    }
                } else if (u === 'n' || u === 'k'){
                    var offs = u === 'n' ? N_OFF : K_OFF;
                    for (i = 0; i < 8; i++){
                        tr = r + offs[i][0]; tc = c + offs[i][1];
                        if (!inb(tr, tc)) continue;
                        tp = b[tr*8 + tc];
                        if (!tp || isW(tp) !== W_) ms.push({ f:sq, t:tr*8 + tc });
                    }
                    if (u === 'k'){
                        var row = W_ ? 7 : 0;
                        if (sq === row*8 + 4 && !attacked(b, sq, opp)){
                            if (st.cr.indexOf(W_ ? 'K' : 'k') > -1 && !b[row*8 + 5] && !b[row*8 + 6] && b[row*8 + 7] === (W_ ? 'R' : 'r') &&
                                !attacked(b, row*8 + 5, opp) && !attacked(b, row*8 + 6, opp)) ms.push({ f:sq, t:row*8 + 6, cs:1 });
                            if (st.cr.indexOf(W_ ? 'Q' : 'q') > -1 && !b[row*8 + 1] && !b[row*8 + 2] && !b[row*8 + 3] && b[row*8] === (W_ ? 'R' : 'r') &&
                                !attacked(b, row*8 + 3, opp) && !attacked(b, row*8 + 2, opp)) ms.push({ f:sq, t:row*8 + 2, cs:1 });
                        }
                    }
                } else {
                    var dirs = u === 'b' ? B_DIR : u === 'r' ? R_DIR : B_DIR.concat(R_DIR);
                    for (i = 0; i < dirs.length; i++){
                        tr = r + dirs[i][0]; tc = c + dirs[i][1];
                        while (inb(tr, tc)){
                            tp = b[tr*8 + tc];
                            if (!tp) ms.push({ f:sq, t:tr*8 + tc });
                            else { if (isW(tp) !== W_) ms.push({ f:sq, t:tr*8 + tc }); break; }
                            tr += dirs[i][0]; tc += dirs[i][1];
                        }
                    }
                }
            }
            return ms;
        }
        function mk(st, m){
            var b = st.b.slice(), p = b[m.f], W_ = isW(p), cap = b[m.t], cr = st.cr, ep = -1, hm = st.hm + 1;
            b[m.f] = ''; b[m.t] = m.pr || p;
            if (m.ep){ cap = b[m.t + (W_ ? 8 : -8)]; b[m.t + (W_ ? 8 : -8)] = ''; }
            if (m.cs){
                if ((m.t & 7) === 6){ b[m.f + 1] = b[m.f + 3]; b[m.f + 3] = ''; }
                else { b[m.f - 1] = b[m.f - 4]; b[m.f - 4] = ''; }
            }
            if (m.dp) ep = (m.f + m.t) >> 1;
            if (p.toLowerCase() === 'p' || cap) hm = 0;
            [m.f, m.t].forEach(function(s){
                if (s === 0) cr = cr.replace('q', ''); else if (s === 7) cr = cr.replace('k', '');
                else if (s === 56) cr = cr.replace('Q', ''); else if (s === 63) cr = cr.replace('K', '');
                else if (s === 4) cr = cr.replace('k', '').replace('q', ''); else if (s === 60) cr = cr.replace('K', '').replace('Q', '');
            });
            return { b:b, t:W_ ? 'b' : 'w', cr:cr, ep:ep, hm:hm, cap:cap || '' };
        }
        function legal(st){
            var ms = gen(st), out = [], me = st.t, opp = me === 'w' ? 'b' : 'w';
            for (var i = 0; i < ms.length; i++){
                var ns = mk(st, ms[i]);
                if (!attacked(ns.b, kp(ns.b, me), opp)) out.push(ms[i]);
            }
            return out;
        }
        function ev(st){
            var s = 0, b = st.b, sq, p, u, r, c, v, cen;
            for (sq = 0; sq < 64; sq++){
                p = b[sq]; if (!p) continue;
                u = p.toLowerCase(); r = sq >> 3; c = sq & 7; v = VAL[u];
                cen = (3.5 - Math.abs(c - 3.5)) + (3.5 - Math.abs(r - 3.5));
                if (u === 'p') v += (isW(p) ? 6 - r : r - 1) * 6 + cen * 2;
                else if (u === 'n' || u === 'b') v += cen * 5;
                s += isW(p) ? v : -v;
            }
            return st.t === 'w' ? s : -s;
        }
        function order(ms, st){
            ms.forEach(function(m){ var t = st.b[m.t]; m.o = (t ? VAL[t.toLowerCase()] : 0) + (m.pr ? 800 : 0); });
            ms.sort(function(a, b){ return b.o - a.o; });
        }
        function nega(st, d, a, b){
            if ((++nodes & 255) === 0 && Date.now() > deadline) aborted = true;
            if (aborted) return 0;
            if (st.hm >= 100) return 0;
            if (d === 0) return ev(st);
            var ms = legal(st);
            if (!ms.length) return inChk(st) ? -90000 - d : 0;
            order(ms, st);
            for (var i = 0; i < ms.length; i++){
                var v = -nega(mk(st, ms[i]), d - 1, -b, -a);
                if (v >= b) return v;
                if (v > a) a = v;
            }
            return a;
        }
        function aiPick(){
            var ms = legal(S0), best = null, i, d;
            if (!ms.length) return null;
            order(ms, S0);
            /* thinks deeper only while there is time (max ~0.65 s), so it never stalls a slow phone */
            deadline = Date.now() + 650; aborted = false; nodes = 0;
            for (d = 1; d <= lv.d; d++){
                var cb = null, cv = -1e9, ok = true;
                for (i = 0; i < ms.length; i++){
                    var ns = mk(S0, ms[i]), v;
                    if (inChk(ns) && !legal(ns).length) v = 1e6;
                    else v = -nega(ns, d - 1, -1e9, 1e9) + (Math.random() - 0.5) * 2 * lv.noise;
                    if (aborted){ ok = false; break; }
                    if (v > cv){ cv = v; cb = ms[i]; }
                }
                if (ok && cb) best = cb; else break;
            }
            return best || ms[0];
        }

        function endGame(res){
            over = true; busy = false;
            var total = Math.min(790, capPts * 10 + (res === 'win' ? lv.bonus : 0));
            api.setScore(total); api.extra('');
            var T = res === 'win' ? 'Checkmate — you win!' : res === 'loss' ? 'Checkmate — you lost' : 'Draw';
            timer = setTimeout(function(){ api.end({ win:res === 'win', title:T, sub:'Material won: ' + capPts + ' pts' }); }, 450);
        }
        function play(m){
            var mover = S0.t, ns = mk(S0, m);
            if (ns.cap){
                if (mover === 'w'){ capPts += PTS[ns.cap.toLowerCase()]; api.setScore(capPts * 10); }
                api.sfx('eat');
            } else api.sfx('place');
            S0 = ns; last = m; sel = -1; targets = [];
            var chk = inChk(S0), ms = legal(S0);
            chkSq = chk ? kp(S0.b, S0.t) : -1;
            if (!ms.length){ endGame(chk ? (mover === 'w' ? 'win' : 'loss') : 'draw'); return; }
            if (S0.hm >= 100){ endGame('draw'); return; }
            api.extra(chk ? 'Check!' : (S0.t === 'w' ? 'Your move' : 'Thinking…'));
            if (S0.t === 'b'){ busy = true; timer = setTimeout(aiMove, 120); }
        }
        function aiMove(){
            if (dead || over) return;
            var m = aiPick(); busy = false;
            if (m) play(m);
        }
        function click(sq){
            var p = S0.b[sq], i;
            if (sel >= 0) for (i = 0; i < targets.length; i++) if (targets[i].t === sq){ play(targets[i]); return; }
            if (p && isW(p)){
                sel = sq; targets = legal(S0).filter(function(m){ return m.f === sq; }); api.sfx('pop');
            } else { sel = -1; targets = []; }
        }
        function applyMove(f, t){
            var ms = legal(S0);
            for (var i = 0; i < ms.length; i++) if (ms[i].f === f && ms[i].t === t){ S0 = mk(S0, ms[i]); last = ms[i]; return; }
        }

        return {
            W:W, H:H, swipe:false, instant:false,
            levels:[{ k:'easy', n:'Easy' }, { k:'medium', n:'Medium' }, { k:'hard', n:'Hard' }],
            setLevel:function(k){ lv = LEVELS[k] || LEVELS.medium; api.extra('Your move'); },
            reset:function(){
                clearTimeout(timer);
                S0 = init(); sel = -1; targets = []; last = null; busy = false; over = false; dead = false; capPts = 0; chkSq = -1;
            },
            preview:function(){
                this.reset();
                applyMove(52, 36); applyMove(12, 28); applyMove(62, 45); applyMove(1, 18);
                applyMove(61, 34); applyMove(5, 26);
                sel = -1; targets = [];
            },
            destroy:function(){ dead = true; clearTimeout(timer); },
            dir:function(){}, tap:function(){}, update:function(){},
            ptr:function(ty, x, y){
                if (ty !== 'down' || busy || over || S0.t !== 'w') return;
                var c = Math.floor(x / S), r = Math.floor(y / S);
                if (c < 0 || c > 7 || r < 0 || r > 7) return;
                click(r * 8 + c);
            },
            draw:function(t, state){
                var r, c, sq, i;
                ctx.fillStyle = '#070924'; ctx.fillRect(0, 0, W, H);
                for (r = 0; r < 8; r++) for (c = 0; c < 8; c++){
                    ctx.fillStyle = ((r + c) % 2 === 0) ? '#3b4190' : '#1a1e55';
                    ctx.fillRect(c * S, r * S, S, S);
                }
                if (last){
                    ctx.fillStyle = 'rgba(255,214,0,.22)';
                    ctx.fillRect((last.f & 7) * S, (last.f >> 3) * S, S, S);
                    ctx.fillRect((last.t & 7) * S, (last.t >> 3) * S, S, S);
                }
                if (chkSq >= 0){
                    var kx = (chkSq & 7) * S + S/2, ky = (chkSq >> 3) * S + S/2;
                    var gr = ctx.createRadialGradient(kx, ky, 4, kx, ky, S * 0.7);
                    gr.addColorStop(0, 'rgba(255,45,45,.85)'); gr.addColorStop(1, 'rgba(255,45,45,0)');
                    ctx.fillStyle = gr; ctx.fillRect((chkSq & 7) * S, (chkSq >> 3) * S, S, S);
                }
                if (sel >= 0){ ctx.fillStyle = 'rgba(255,45,45,.5)'; ctx.fillRect((sel & 7) * S, (sel >> 3) * S, S, S); }
                ctx.font = '700 ' + Math.round(S * 0.8) + 'px "Segoe UI Symbol","Noto Sans Symbols 2","Apple Symbols","DejaVu Sans",serif';
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.lineJoin = 'round'; ctx.lineWidth = 2.6;
                for (sq = 0; sq < 64; sq++){
                    var p = S0.b[sq]; if (!p) continue;
                    var cx = (sq & 7) * S + S/2, cy = (sq >> 3) * S + S/2 + 2, g = GLY[p.toLowerCase()] + '\uFE0E';
                    if (isW(p)){ ctx.strokeStyle = '#10133a'; ctx.fillStyle = '#ffffff'; }
                    else { ctx.strokeStyle = '#2a0610'; ctx.fillStyle = '#ff4d5a'; }
                    ctx.strokeText(g, cx, cy); ctx.fillText(g, cx, cy);
                }
                ctx.textBaseline = 'alphabetic';
                for (i = 0; i < targets.length; i++){
                    var tx = (targets[i].t & 7) * S + S/2, ty = (targets[i].t >> 3) * S + S/2;
                    if (S0.b[targets[i].t] || targets[i].ep){
                        ctx.strokeStyle = 'rgba(255,45,45,.95)'; ctx.lineWidth = 3; ctx.beginPath(); ctx.arc(tx, ty, S * 0.42, 0, 7); ctx.stroke();
                    } else { ctx.fillStyle = 'rgba(255,255,255,.55)'; ctx.beginPath(); ctx.arc(tx, ty, 6, 0, 7); ctx.fill(); }
                }
                if (busy){ ctx.fillStyle = 'rgba(7,9,36,.18)'; ctx.fillRect(0, 0, W, H); }
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.12)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   CAKE STACK
══════════════════════════════════════════════════════════════ */
GAMES.cakestack = {
    name:'Cake Stack', ovIcon:'cake',
    help:'Tap, click or press Space to drop the sliding layer. Line it up for perfect drops!',
    make:function(api, ctx){
        var W = 360, H = 560, LH = 32, BASE = H - 96, BW = 190;
        var st, cu, debris, sc, streak, cam, flash, fl;

        function hue(i){ return (i * 23 + 340) % 360; }
        function spawnCur(){
            var top = st[st.length - 1], d = st.length % 2 ? 1 : -1;
            cu = { x:d > 0 ? 0 : W - top.w, w:top.w, d:d };
        }
        function layer(x, y, w, h, col){
            ctx.fillStyle = 'hsl(' + col + ',72%,60%)'; rrect(ctx, x, y, w, h, 6); ctx.fill();
            ctx.fillStyle = 'hsl(' + col + ',85%,86%)'; rrect(ctx, x, y, w, 10, 6); ctx.fill();
            ctx.fillStyle = 'rgba(0,0,0,.14)'; ctx.fillRect(x + 2, y + h - 5, w - 4, 4);
            ctx.fillStyle = 'rgba(255,255,255,.35)';
            for (var dx = 14; dx < w - 8; dx += 26) ctx.fillRect(x + dx, y + 14, 3, 3);
        }
        return {
            W:W, H:H, swipe:false, instant:true,
            reset:function(){
                st = [{ x:(W - BW) / 2, w:BW, col:hue(0) }]; debris = []; fl = []; sc = 0; streak = 0; cam = 0; flash = 0;
                spawnCur();
            },
            preview:function(){
                this.reset();
                for (var i = 1; i < 10; i++){
                    var w = BW - i * 5; st.push({ x:(W - w) / 2 + Math.sin(i * 1.7) * 14, w:w, col:hue(i) });
                }
                sc = 9; cam = (st.length - 6) * LH; cu = { x:70, w:st[st.length - 1].w, d:1 };
            },
            dir:function(d){ if (d === 'u' || d === 'd') this.tap(); },
            tap:function(){
                if (!cu) return;
                var top = st[st.length - 1], n = st.length;
                var l = Math.max(cu.x, top.x), r = Math.min(cu.x + cu.w, top.x + top.w), ow = r - l;
                if (ow <= 0){
                    debris.push({ x:cu.x, w:cu.w, y:BASE - (n + 1) * LH, vy:0, vx:cu.d * 50, col:hue(n) });
                    cu = null; api.end(); return;
                }
                var perfect = Math.abs(cu.x - top.x) <= 5, nx, nw;
                if (perfect){
                    nx = top.x; nw = top.w; streak++;
                    if (streak >= 3 && nw < BW){
                        var gr = Math.min(8, BW - nw); nx = Math.max(0, nx - gr / 2); nw = Math.min(W - nx, nw + gr);
                    }
                    flash = 0.3; fl.push({ x:W / 2, y:BASE - (n + 1) * LH + cam - 14, t:0.9, txt:streak >= 3 ? 'PERFECT x' + streak : 'PERFECT' });
                    api.sfx(streak >= 3 ? 'level' : 'score');
                } else {
                    nx = l; nw = ow; streak = 0;
                    if (cu.x < top.x) debris.push({ x:cu.x, w:top.x - cu.x, y:BASE - (n + 1) * LH, vy:0, vx:-40, col:hue(n) });
                    else debris.push({ x:top.x + top.w, w:cu.x + cu.w - (top.x + top.w), y:BASE - (n + 1) * LH, vy:0, vx:40, col:hue(n) });
                    api.sfx('thud');
                }
                st.push({ x:nx, w:nw, col:hue(n) });
                sc += perfect ? 2 : 1; api.setScore(sc);
                spawnCur();
            },
            update:function(dt){
                if (cu){
                    var spd = 170 + Math.min(st.length, 40) * 7;
                    cu.x += cu.d * spd * dt;
                    if (cu.x < 0){ cu.x = 0; cu.d = 1; }
                    if (cu.x + cu.w > W){ cu.x = W - cu.w; cu.d = -1; }
                }
                var target = Math.max(0, st.length - 6) * LH;
                cam += (target - cam) * Math.min(1, dt * 5);
                for (var i = debris.length - 1; i >= 0; i--){
                    var d = debris[i]; d.vy += 1500 * dt; d.y += d.vy * dt; d.x += d.vx * dt;
                    if (d.y > BASE + 900 + cam) debris.splice(i, 1);
                }
                for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 30 * dt; fl[i].t -= dt; if (fl[i].t <= 0) fl.splice(i, 1); }
                if (flash > 0) flash -= dt;
            },
            draw:function(t, state){
                var g = ctx.createLinearGradient(0, 0, 0, H);
                g.addColorStop(0, '#0b0d3a'); g.addColorStop(1, '#2a1245');
                ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
                ctx.fillStyle = 'rgba(255,255,255,.4)';
                for (var s = 0; s < 26; s++) ctx.fillRect((s * 71) % W, ((s * 113) % H + cam * 0.15) % H, 1.6, 1.6);
                // table + plate
                ctx.fillStyle = '#17194d'; ctx.fillRect(0, BASE + cam + 14, W, H);
                ctx.fillStyle = '#e9e6ff'; rrect(ctx, W / 2 - 135, BASE + cam, 270, 14, 7); ctx.fill();
                // layers
                var i;
                for (i = 0; i < st.length; i++) layer(st[i].x, BASE - (i + 1) * LH + cam, st[i].w, LH - 1, st[i].col);
                // cherry
                var tp = st[st.length - 1], cy = BASE - st.length * LH + cam;
                ctx.fillStyle = '#ff2d4a'; ctx.beginPath(); ctx.arc(tp.x + tp.w / 2, cy - 6, 7, 0, 7); ctx.fill();
                ctx.strokeStyle = '#3dbb5a'; ctx.lineWidth = 2; ctx.beginPath(); ctx.moveTo(tp.x + tp.w / 2, cy - 12); ctx.lineTo(tp.x + tp.w / 2 + 4, cy - 20); ctx.stroke();
                // moving layer
                if (cu) layer(cu.x, BASE - (st.length + 1) * LH + cam, cu.w, LH - 1, hue(st.length));
                for (i = 0; i < debris.length; i++){ var d = debris[i]; layer(d.x, d.y + cam, d.w, LH - 1, d.col); }
                // big faint score
                ctx.fillStyle = 'rgba(255,255,255,.14)'; ctx.font = '700 120px Poppins, sans-serif'; ctx.textAlign = 'center';
                ctx.fillText(sc, W / 2, 150);
                // floating texts
                ctx.font = '700 18px Poppins, sans-serif';
                for (i = 0; i < fl.length; i++){ ctx.globalAlpha = Math.min(1, fl[i].t * 1.5); ctx.fillStyle = '#ffd400'; ctx.fillText(fl[i].txt, fl[i].x, fl[i].y); }
                ctx.globalAlpha = 1;
                if (flash > 0){ ctx.fillStyle = 'rgba(255,255,255,' + flash * 0.5 + ')'; ctx.fillRect(0, 0, W, H); }
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.12)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   CANDY MATCH (match-3, 20 moves)
══════════════════════════════════════════════════════════════ */
GAMES.match3 = {
    name:'Candy Match', ovIcon:'candy',
    help:'Swipe a sweet (or tap two neighbours) to swap. Match 3 or more. You have 20 moves, so chain combos!',
    make:function(api, ctx){
        var R = 8, C = 8, CS = 48, OX = 8, OY = 16, W = 400, H = 416;
        var COLS = ['#ff3b5c', '#ff9f1a', '#ffd93d', '#35d07f', '#2f9bff', '#b86bff'];
        var g, phase, sw, moves, combo, sel, drag, sc, fl, clr;

        function newCell(){ return { t:rand(6), ox:0, oy:0, vy:0, s:1, dead:false }; }
        function findM(){
            var mark = {}, groups = [], r, c, k, j, t;
            for (r = 0; r < R; r++){
                c = 0;
                while (c < C){
                    t = g[r][c] ? g[r][c].t : -1; k = c + 1;
                    while (k < C && g[r][k] && g[r][k].t === t) k++;
                    if (t >= 0 && k - c >= 3){ groups.push(k - c); for (j = c; j < k; j++) mark[r * C + j] = 1; }
                    c = k;
                }
            }
            for (c = 0; c < C; c++){
                r = 0;
                while (r < R){
                    t = g[r][c] ? g[r][c].t : -1; k = r + 1;
                    while (k < R && g[k][c] && g[k][c].t === t) k++;
                    if (t >= 0 && k - r >= 3){ groups.push(k - r); for (j = r; j < k; j++) mark[j * C + c] = 1; }
                    r = k;
                }
            }
            return { mark:mark, groups:groups };
        }
        function build(){
            g = []; var r, c, t;
            for (r = 0; r < R; r++){
                g[r] = [];
                for (c = 0; c < C; c++){
                    var e = newCell();
                    while ((c >= 2 && g[r][c-1].t === e.t && g[r][c-2].t === e.t) || (r >= 2 && g[r-1][c].t === e.t && g[r-2][c].t === e.t)) e.t = rand(6);
                    g[r][c] = e;
                }
            }
            if (!hasMove()) build();
        }
        function swapData(r1, c1, r2, c2){
            var a = g[r1][c1], b = g[r2][c2];
            g[r1][c1] = b; g[r2][c2] = a;
            b.ox = c2 - c1; b.oy = r2 - r1; a.ox = c1 - c2; a.oy = r1 - r2;
        }
        function hasMove(){
            var r, c, ok = false, t;
            for (r = 0; r < R && !ok; r++) for (c = 0; c < C && !ok; c++){
                if (c < C - 1){ t = g[r][c]; g[r][c] = g[r][c+1]; g[r][c+1] = t; if (findM().groups.length) ok = true; t = g[r][c]; g[r][c] = g[r][c+1]; g[r][c+1] = t; }
                if (!ok && r < R - 1){ t = g[r][c]; g[r][c] = g[r+1][c]; g[r+1][c] = t; if (findM().groups.length) ok = true; t = g[r][c]; g[r][c] = g[r+1][c]; g[r+1][c] = t; }
            }
            return ok;
        }
        function shuffle(){
            var types = [], r, c, tries = 0;
            for (r = 0; r < R; r++) for (c = 0; c < C; c++) types.push(g[r][c].t);
            do {
                types.sort(function(){ return Math.random() - 0.5; });
                for (r = 0; r < R; r++) for (c = 0; c < C; c++){ g[r][c].t = types[r * C + c]; g[r][c].s = 1; }
                tries++;
            } while (tries < 40 && (findM().groups.length || !hasMove()));
            api.sfx('level');
        }
        function trySwap(r1, c1, r2, c2){
            if (phase !== 'idle' || moves <= 0) return;
            swapData(r1, c1, r2, c2);
            sw = { r1:r1, c1:c1, r2:r2, c2:c2 }; phase = 'swap'; sel = null; drag = null; api.sfx('slide');
        }
        function startClear(m){
            combo++; phase = 'clear';
            var pts = 0, cx = 0, cy = 0, n = 0, k;
            m.groups.forEach(function(L){ pts += L === 3 ? 30 : L === 4 ? 60 : 100; });
            pts *= combo; sc += pts; api.setScore(sc);
            clr = [];
            for (k in m.mark){
                var r = (k / C) | 0, c = k % C;
                g[r][c].dead = true; clr.push([r, c]); cx += c; cy += r; n++;
            }
            fl.push({ x:OX + (cx / n + 0.5) * CS, y:OY + (cy / n + 0.5) * CS, t:0.9, txt:'+' + pts + (combo > 1 ? '  x' + combo : '') });
            api.sfx(combo > 1 ? 'level' : 'clear');
        }
        function collapse(){
            var r, c, w;
            for (c = 0; c < C; c++){
                w = R - 1;
                for (r = R - 1; r >= 0; r--){
                    if (g[r][c]){
                        if (r !== w){ g[w][c] = g[r][c]; g[r][c] = null; g[w][c].oy = -(w - r); g[w][c].vy = 0; }
                        w--;
                    }
                }
                for (r = w; r >= 0; r--){ g[r][c] = newCell(); g[r][c].oy = -(w + 1); }
            }
        }
        function settleXY(dt){
            var done = true, step = 7.5 * dt, r, c, e;
            for (r = 0; r < R; r++) for (c = 0; c < C; c++){
                e = g[r][c]; if (!e) continue;
                if (e.ox){ e.ox = Math.abs(e.ox) <= step ? 0 : e.ox - (e.ox > 0 ? step : -step); }
                if (e.oy){ e.oy = Math.abs(e.oy) <= step ? 0 : e.oy - (e.oy > 0 ? step : -step); }
                if (e.ox || e.oy) done = false;
            }
            return done;
        }
        function fall(dt){
            var done = true, r, c, e;
            for (r = 0; r < R; r++) for (c = 0; c < C; c++){
                e = g[r][c]; if (!e || e.oy >= 0) continue;
                e.vy += 48 * dt; e.oy += e.vy * dt;
                if (e.oy >= 0){ e.oy = 0; e.vy = 0; } else done = false;
            }
            return done;
        }
        function candy(x, y, rad, t){
            var i, a;
            ctx.fillStyle = COLS[t]; ctx.strokeStyle = 'rgba(0,0,0,.35)'; ctx.lineWidth = 2;
            if (t === 1){ rrect(ctx, x - rad, y - rad, rad * 2, rad * 2, rad * 0.38); }
            else {
                ctx.beginPath();
                if (t === 0) ctx.arc(x, y, rad, 0, 7);
                else if (t === 2){ ctx.moveTo(x, y - rad * 1.2); ctx.lineTo(x + rad * 1.1, y); ctx.lineTo(x, y + rad * 1.2); ctx.lineTo(x - rad * 1.1, y); ctx.closePath(); }
                else if (t === 3){ ctx.moveTo(x, y - rad * 1.1); ctx.lineTo(x + rad * 1.15, y + rad * 0.85); ctx.lineTo(x - rad * 1.15, y + rad * 0.85); ctx.closePath(); }
                else if (t === 4){ for (i = 0; i < 6; i++){ a = i * Math.PI / 3 + Math.PI / 6; ctx.lineTo(x + Math.cos(a) * rad * 1.08, y + Math.sin(a) * rad * 1.08); } ctx.closePath(); }
                else { for (i = 0; i < 10; i++){ a = -Math.PI / 2 + i * Math.PI / 5; var rr = (i % 2 ? 0.5 : 1.2) * rad; ctx.lineTo(x + Math.cos(a) * rr, y + Math.sin(a) * rr); } ctx.closePath(); }
            }
            ctx.fill(); ctx.stroke();
            ctx.fillStyle = 'rgba(255,255,255,.5)'; ctx.beginPath(); ctx.ellipse(x - rad * 0.3, y - rad * 0.35, rad * 0.28, rad * 0.16, -0.6, 0, 7); ctx.fill();
        }
        function hudm(){ api.extra('Moves ' + moves); }

        return {
            W:W, H:H, swipe:false, instant:false,
            reset:function(){
                build(); phase = 'idle'; moves = 20; combo = 0; sel = null; drag = null; sc = 0; fl = []; clr = []; sw = null;
            },
            preview:function(){ this.reset(); },
            begin:function(){ hudm(); },
            dir:function(){}, tap:function(){},
            ptr:function(ty, x, y){
                if (ty === 'up'){ drag = null; return; }
                var c = Math.floor((x - OX) / CS), r = Math.floor((y - OY) / CS), ok = (c >= 0 && c < C && r >= 0 && r < R);
                if (ty === 'down'){
                    if (!ok || phase !== 'idle'){ drag = null; return; }
                    if (sel && Math.abs(sel.r - r) + Math.abs(sel.c - c) === 1){ trySwap(sel.r, sel.c, r, c); return; }
                    sel = { r:r, c:c }; drag = { x:x, y:y, r:r, c:c }; api.sfx('pop');
                } else if (ty === 'move'){
                    if (!drag || phase !== 'idle') return;
                    var dx = x - drag.x, dy = y - drag.y;
                    if (Math.abs(dx) < 14 && Math.abs(dy) < 14) return;
                    var nr = drag.r, nc = drag.c;
                    if (Math.abs(dx) > Math.abs(dy)) nc += dx > 0 ? 1 : -1; else nr += dy > 0 ? 1 : -1;
                    var fr = drag.r, fc = drag.c; drag = null;
                    if (nr >= 0 && nr < R && nc >= 0 && nc < C) trySwap(fr, fc, nr, nc);
                }
            },
            update:function(dt){
                var i, r, c, m;
                for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 34 * dt; fl[i].t -= dt; if (fl[i].t <= 0) fl.splice(i, 1); }
                if (phase === 'swap' || phase === 'back'){
                    if (!settleXY(dt)) return;
                    if (phase === 'swap'){
                        m = findM();
                        if (m.groups.length){ moves--; hudm(); combo = 0; startClear(m); }
                        else { swapData(sw.r1, sw.c1, sw.r2, sw.c2); phase = 'back'; api.sfx('wrong'); }
                    } else phase = 'idle';
                } else if (phase === 'clear'){
                    var all = true;
                    for (i = 0; i < clr.length; i++){ var e = g[clr[i][0]][clr[i][1]]; e.s = Math.max(0, e.s - dt * 6); if (e.s > 0) all = false; }
                    if (all){
                        for (i = 0; i < clr.length; i++) g[clr[i][0]][clr[i][1]] = null;
                        clr = []; collapse(); phase = 'fall';
                    }
                } else if (phase === 'fall'){
                    if (!fall(dt)) return;
                    m = findM();
                    if (m.groups.length) startClear(m);
                    else {
                        combo = 0;
                        if (moves <= 0){ phase = 'done'; api.end({ title:'Out of moves' }); return; }
                        if (!hasMove()) shuffle();
                        phase = 'idle';
                    }
                }
            },
            draw:function(t, state){
                var r, c, e;
                ctx.fillStyle = '#070924'; ctx.fillRect(0, 0, W, H);
                ctx.fillStyle = '#10133a'; rrect(ctx, OX - 6, OY - 6, C * CS + 12, R * CS + 12, 12); ctx.fill();
                ctx.save(); ctx.beginPath(); ctx.rect(OX, OY, C * CS, R * CS); ctx.clip();
                for (r = 0; r < R; r++) for (c = 0; c < C; c++){
                    if ((r + c) % 2){ ctx.fillStyle = 'rgba(255,255,255,.035)'; ctx.fillRect(OX + c * CS, OY + r * CS, CS, CS); }
                }
                for (r = 0; r < R; r++) for (c = 0; c < C; c++){
                    e = g[r][c]; if (!e) continue;
                    candy(OX + (c + e.ox) * CS + CS / 2, OY + (r + e.oy) * CS + CS / 2, CS * 0.37 * e.s, e.t);
                }
                if (sel && phase === 'idle'){
                    ctx.strokeStyle = '#fff'; ctx.lineWidth = 3;
                    rrect(ctx, OX + sel.c * CS + 2, OY + sel.r * CS + 2, CS - 4, CS - 4, 10); ctx.stroke();
                }
                ctx.restore();
                ctx.textAlign = 'center'; ctx.font = '700 17px Poppins, sans-serif';
                for (var i = 0; i < fl.length; i++){
                    ctx.globalAlpha = Math.min(1, fl[i].t * 1.6);
                    ctx.fillStyle = '#000'; ctx.fillText(fl[i].txt, fl[i].x + 1, fl[i].y + 1);
                    ctx.fillStyle = '#fff'; ctx.fillText(fl[i].txt, fl[i].x, fl[i].y);
                }
                ctx.globalAlpha = 1;
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.12)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   CAR DODGE RACE
══════════════════════════════════════════════════════════════ */
GAMES.cardodge = {
    name:'Car Dodge Race', ovIcon:'car',
    help:'Drag your finger left and right to steer (or use ← → / A D). Dodge every car.',
    make:function(api, ctx){
        var W = 360, H = 560, RX = 40, RW = 280, LN = 4, LW = RW / LN, PY = H - 130;
        var TCOL = ['#4da3ff', '#ffd400', '#35d07f', '#b86bff', '#ff9f1a', '#e6e6ff'];
        var px, lp, tilt, rows, sc, speed, scroll, safe, gapD, parts, shake, lastDraw, kmT, crashed;

        function laneX(i){ return RX + LW * (i + 0.5); }
        function car(x, y, w, h, col, player){
            ctx.fillStyle = 'rgba(0,0,0,.35)'; rrect(ctx, x - w/2 + 3, y + 5, w, h, 8); ctx.fill();
            ctx.fillStyle = '#0b0c1c';
            ctx.fillRect(x - w/2 - 3, y + h * 0.14, 5, h * 0.2); ctx.fillRect(x + w/2 - 2, y + h * 0.14, 5, h * 0.2);
            ctx.fillRect(x - w/2 - 3, y + h * 0.66, 5, h * 0.2); ctx.fillRect(x + w/2 - 2, y + h * 0.66, 5, h * 0.2);
            ctx.fillStyle = col; rrect(ctx, x - w/2, y, w, h, 9); ctx.fill();
            if (player){ ctx.fillStyle = 'rgba(255,255,255,.9)'; ctx.fillRect(x - 3, y + 2, 6, h - 4); }
            ctx.fillStyle = 'rgba(8,10,40,.88)'; rrect(ctx, x - w/2 + 5, y + h * 0.26, w - 10, h * 0.2, 4); ctx.fill();
            ctx.fillStyle = 'rgba(8,10,40,.75)'; rrect(ctx, x - w/2 + 6, y + h * 0.66, w - 12, h * 0.12, 3); ctx.fill();
            ctx.fillStyle = '#fff6b0'; ctx.fillRect(x - w/2 + 3, y + 1, 7, 4); ctx.fillRect(x + w/2 - 10, y + 1, 7, 4);
            ctx.fillStyle = '#ff2d2d'; ctx.fillRect(x - w/2 + 3, y + h - 5, 7, 4); ctx.fillRect(x + w/2 - 10, y + h - 5, 7, 4);
        }
        function burst(x, y, n){
            for (var i = 0; i < n; i++){
                var a = Math.random() * 6.283, v = 60 + Math.random() * 260;
                parts.push({ x:x, y:y, vx:Math.cos(a) * v, vy:Math.sin(a) * v, life:0.5 + Math.random() * 0.6, max:1.1, r:2 + Math.random() * 3.5, col:['#ff2d2d', '#ff9f1a', '#ffd400', '#fff'][rand(4)] });
            }
        }
        function clampX(v){ return Math.max(RX + 19, Math.min(RX + RW - 19, v)); }
        return {
            W:W, H:H, swipe:false, instant:false,
            reset:function(){
                px = laneX(1); lp = null; tilt = 0; rows = []; sc = 0; speed = 240; scroll = 0; safe = 1; gapD = 0; parts = []; shake = 0; lastDraw = 0; kmT = 0; crashed = false;
            },
            preview:function(){
                this.reset(); sc = 12; scroll = 40; px = laneX(2);
                rows = [
                    { y:90,  cars:[{ lane:0, col:TCOL[0] }, { lane:3, col:TCOL[2] }], passed:false },
                    { y:290, cars:[{ lane:1, col:TCOL[1] }, { lane:2, col:TCOL[3], truck:true }], passed:false }
                ];
            },
            begin:function(){ api.extra('0 km/h'); },
            dir:function(){},
            tap:function(){},
            ptr:function(ty, x){
                if (ty === 'down'){ lp = x; }
                else if (ty === 'move' && lp !== null){ px = clampX(px + (x - lp)); lp = x; }
                else if (ty === 'up'){ lp = null; }
            },
            update:function(dt){
                speed = Math.min(540, 240 + sc * 3.2);
                scroll += speed * dt;
                var Hh = api.held, ox = px;
                px = clampX(px + ((Hh.r ? 1 : 0) - (Hh.l ? 1 : 0)) * 330 * dt);
                tilt += (Math.max(-1, Math.min(1, (px - ox) / Math.max(dt, 0.001) / 320)) - tilt) * Math.min(1, dt * 12);
                kmT -= dt; if (kmT <= 0){ kmT = 0.25; api.extra(Math.round(speed * 0.35) + ' km/h'); }
                var last = rows[rows.length - 1], i, k;
                if (!last || last.y > gapD){
                    safe = Math.max(0, Math.min(LN - 1, safe + rand(3) - 1));
                    var cs = [];
                    for (i = 0; i < LN; i++) if (i !== safe && Math.random() < 0.55) cs.push({ lane:i, col:TCOL[rand(TCOL.length)], truck:Math.random() < 0.15 });
                    rows.push({ y:-100, cars:cs, passed:false });
                    gapD = speed * 0.75 - 100;
                }
                for (i = rows.length - 1; i >= 0; i--){
                    var row = rows[i]; row.y += speed * dt;
                    if (row.y > H + 20){ rows.splice(i, 1); continue; }
                    if (!row.passed && row.y > PY + 62){
                        row.passed = true;
                        if (row.cars.length){ sc++; api.setScore(sc); api.sfx('pop'); }
                    }
                    for (k = 0; k < row.cars.length; k++){
                        var c = row.cars[k], h = c.truck ? 80 : 58;
                        if (Math.abs(px - laneX(c.lane)) < 26 && row.y < PY + 56 && row.y + h > PY + 6){
                            crashed = true; shake = 0.6; burst(px, PY + 30, 40); api.end(); return;
                        }
                    }
                }
            },
            draw:function(t, state){
                var dd = (state === 'preview') ? 0 : Math.min(0.05, Math.max(0, t - lastDraw)); lastDraw = t;
                if (dd > 0){
                    if (shake > 0) shake = Math.max(0, shake - dd * 1.4);
                    for (var q = parts.length - 1; q >= 0; q--){
                        var p = parts[q]; p.x += p.vx * dd; p.y += p.vy * dd; p.vx *= 0.97; p.vy *= 0.97; p.life -= dd;
                        if (p.life <= 0) parts.splice(q, 1);
                    }
                }
                ctx.save();
                if (shake > 0) ctx.translate((Math.random() - 0.5) * shake * 16, (Math.random() - 0.5) * shake * 16);
                ctx.fillStyle = '#05061c'; ctx.fillRect(-20, -20, W + 40, H + 40);
                var i, y;
                // roadside neon posts
                for (i = -1; i < H / 70 + 1; i++){
                    y = i * 70 + (scroll % 70);
                    ctx.fillStyle = 'rgba(255,45,45,.9)'; ctx.fillRect(14, y, 4, 22);
                    ctx.fillRect(W - 18, y, 4, 22);
                    ctx.fillStyle = 'rgba(255,45,45,.18)'; ctx.fillRect(8, y - 6, 16, 34); ctx.fillRect(W - 24, y - 6, 16, 34);
                }
                // road
                ctx.fillStyle = '#16183a'; ctx.fillRect(RX, 0, RW, H);
                var off = scroll % 40;
                for (i = -1; i < H / 20 + 1; i++){
                    ctx.fillStyle = (i % 2 === 0) ? '#ff2d2d' : '#fff';
                    ctx.fillRect(RX - 7, i * 20 + off - 20, 7, 20);
                    ctx.fillRect(RX + RW, i * 20 + off - 20, 7, 20);
                }
                ctx.fillStyle = 'rgba(255,255,255,.35)';
                for (i = 1; i < LN; i++) for (y = -60 + (scroll % 60); y < H; y += 60) ctx.fillRect(RX + LW * i - 2, y, 4, 30);
                // traffic
                rows.forEach(function(row){
                    row.cars.forEach(function(c){ car(laneX(c.lane), row.y, 34, c.truck ? 80 : 58, c.col, false); });
                });
                // player
                if (!(crashed && state === 'over')){
                    ctx.save(); ctx.translate(px, PY + 29); ctx.rotate(tilt * 0.16); ctx.translate(-px, -(PY + 29));
                    car(px, PY, 34, 58, '#ff2d2d', true);
                    ctx.restore();
                }
                parts.forEach(function(p){
                    ctx.globalAlpha = Math.max(0, p.life / p.max); ctx.fillStyle = p.col;
                    ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 7); ctx.fill();
                });
                ctx.globalAlpha = 1;
                // score
                ctx.fillStyle = 'rgba(255,255,255,.9)'; ctx.font = '700 34px Poppins, sans-serif'; ctx.textAlign = 'center';
                ctx.fillText(sc, W / 2, 56);
                ctx.restore();
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.14)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   WOOD BLOCK PUZZLE
══════════════════════════════════════════════════════════════ */
GAMES.woodblock = {
    name:'Wood Block Puzzle', ovIcon:'block',
    help:'Drag a block from the tray onto the board. Fill a whole row or column to clear it. No room left = game over.',
    make:function(api, ctx){
        var W = 360, H = 520, N = 10, CS = 32, GX = 20, GY = 20, TY = 372, TS = 20, LIFT = 64;
        var WC = ['#e0a762', '#cf9150', '#bd7c40'];
        var SH = [
            [[0,0]], [[0,0],[0,1]], [[0,0],[1,0]], [[0,0],[0,1],[0,2]], [[0,0],[1,0],[2,0]],
            [[0,0],[0,1],[0,2],[0,3]], [[0,0],[1,0],[2,0],[3,0]],
            [[0,0],[0,1],[0,2],[0,3],[0,4]], [[0,0],[1,0],[2,0],[3,0],[4,0]],
            [[0,0],[0,1],[1,0],[1,1]], [[0,0],[0,1],[0,2],[1,0],[1,1],[1,2]], [[0,0],[0,1],[1,0],[1,1],[2,0],[2,1]],
            [[0,0],[0,1],[0,2],[1,0],[1,1],[1,2],[2,0],[2,1],[2,2]],
            [[0,0],[1,0],[1,1]], [[0,1],[1,0],[1,1]], [[0,0],[0,1],[1,0]], [[0,0],[0,1],[1,1]],
            [[0,0],[1,0],[2,0],[2,1],[2,2]], [[0,2],[1,2],[2,0],[2,1],[2,2]], [[0,0],[0,1],[0,2],[1,0],[2,0]], [[0,0],[0,1],[0,2],[1,2],[2,2]]
        ];
        var board, tray, drag, sc, streak, parts, fl;

        function newPiece(){
            var s = SH[rand(SH.length)], w = 0, h = 0;
            s.forEach(function(c){ if (c[0] + 1 > h) h = c[0] + 1; if (c[1] + 1 > w) w = c[1] + 1; });
            return { cells:s, w:w, h:h, col:1 + rand(3) };
        }
        function fits(p, r, c){
            for (var i = 0; i < p.cells.length; i++){
                var rr = r + p.cells[i][0], cc = c + p.cells[i][1];
                if (rr < 0 || cc < 0 || rr >= N || cc >= N || board[rr * N + cc]) return false;
            }
            return true;
        }
        function anyFit(){
            for (var i = 0; i < 3; i++){
                var p = tray[i]; if (!p) continue;
                for (var r = 0; r < N; r++) for (var c = 0; c < N; c++) if (fits(p, r, c)) return true;
            }
            return false;
        }
        function refill(){
            for (var tries = 0; tries < 12; tries++){
                tray = [newPiece(), newPiece(), newPiece()];
                if (anyFit()) return;
            }
        }
        function wood(x, y, s, col){
            ctx.fillStyle = WC[col - 1]; rrect(ctx, x + 1, y + 1, s - 2, s - 2, 4); ctx.fill();
            ctx.fillStyle = 'rgba(255,238,205,.3)'; ctx.fillRect(x + 4, y + 3, s - 8, 3);
            ctx.strokeStyle = 'rgba(90,50,16,.28)'; ctx.lineWidth = 1; ctx.beginPath();
            ctx.moveTo(x + 4, y + s * 0.45); ctx.lineTo(x + s - 4, y + s * 0.45);
            ctx.moveTo(x + 4, y + s * 0.72); ctx.lineTo(x + s - 4, y + s * 0.72); ctx.stroke();
            ctx.strokeStyle = 'rgba(60,30,8,.6)'; ctx.lineWidth = 1.5; rrect(ctx, x + 1, y + 1, s - 2, s - 2, 4); ctx.stroke();
        }
        function drawPiece(p, x, y, s, alpha){
            ctx.globalAlpha = alpha;
            p.cells.forEach(function(c){ wood(x + c[1] * s, y + c[0] * s, s, p.col); });
            ctx.globalAlpha = 1;
        }
        function slotPos(i, p){ return { x:60 + 120 * i - p.w * TS / 2, y:TY + 66 - p.h * TS / 2 }; }
        function dragCell(p){
            var ax = drag.x - p.w * CS / 2, ay = drag.y - LIFT - p.h * CS / 2;
            return { r:Math.round((ay - GY) / CS), c:Math.round((ax - GX) / CS), ax:ax, ay:ay };
        }
        function place(i, r, c){
            var p = tray[i], k;
            p.cells.forEach(function(cl){ board[(r + cl[0]) * N + c + cl[1]] = p.col; });
            sc += p.cells.length; tray[i] = null; api.sfx('place');
            var rowsF = [], colsF = [], mark = {}, rr, cc;
            for (rr = 0; rr < N; rr++){ var f = true; for (cc = 0; cc < N; cc++) if (!board[rr * N + cc]){ f = false; break; } if (f) rowsF.push(rr); }
            for (cc = 0; cc < N; cc++){ var f2 = true; for (rr = 0; rr < N; rr++) if (!board[rr * N + cc]){ f2 = false; break; } if (f2) colsF.push(cc); }
            var lines = rowsF.length + colsF.length;
            if (lines){
                rowsF.forEach(function(r0){ for (var x = 0; x < N; x++) mark[r0 * N + x] = 1; });
                colsF.forEach(function(c0){ for (var y = 0; y < N; y++) mark[y * N + c0] = 1; });
                for (k in mark){
                    var cr = (k / N) | 0, ccc = k % N, col = board[k];
                    for (var q = 0; q < 4; q++) parts.push({ x:GX + ccc * CS + CS / 2, y:GY + cr * CS + CS / 2, vx:(Math.random() - 0.5) * 240, vy:-60 - Math.random() * 200, life:0.7, max:0.7, col:WC[col - 1] });
                    board[k] = 0;
                }
                streak++;
                var pts = 10 * lines * (lines + 1) / 2 + Math.min(streak - 1, 6) * 5;
                sc += pts;
                fl.push({ x:W / 2, y:GY + 150, t:1.1, txt:'+' + pts + (streak > 1 ? '  combo x' + streak : '') });
                api.sfx(lines > 1 ? 'level' : 'clear');
            } else streak = 0;
            api.setScore(sc);
            var left = false; for (k = 0; k < 3; k++) if (tray[k]) left = true;
            if (!left) refill();
            if (!anyFit()) api.end({ title:'No more room' });
        }

        return {
            W:W, H:H, swipe:false, instant:false,
            reset:function(){
                board = []; for (var i = 0; i < N * N; i++) board.push(0);
                drag = null; sc = 0; streak = 0; parts = []; fl = []; refill();
            },
            preview:function(){
                this.reset();
                for (var r = 5; r < N; r++) for (var c = 0; c < N; c++) if (Math.random() < 0.72 - (9 - r) * 0.1) board[r * N + c] = 1 + rand(3);
                for (c = 0; c < N; c++) board[9 * N + c] = 1 + rand(3);
                board[9 * N + 4] = 0;
                parts = [];
            },
            dir:function(){}, tap:function(){},
            ptr:function(ty, x, y){
                if (ty === 'down'){
                    if (y < TY - 14) return;
                    var i = Math.floor(x / 120);
                    if (i >= 0 && i < 3 && tray[i]){ drag = { i:i, x:x, y:y }; api.sfx('pop'); }
                } else if (ty === 'move'){
                    if (drag){ drag.x = x; drag.y = y; }
                } else if (ty === 'up'){
                    if (!drag) return;
                    var p = tray[drag.i], d = dragCell(p), di = drag.i;
                    drag = null;
                    if (fits(p, d.r, d.c)) place(di, d.r, d.c);
                }
            },
            update:function(dt){
                var i;
                for (i = parts.length - 1; i >= 0; i--){
                    var p = parts[i]; p.x += p.vx * dt; p.y += p.vy * dt; p.vy += 700 * dt; p.life -= dt;
                    if (p.life <= 0) parts.splice(i, 1);
                }
                for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 28 * dt; fl[i].t -= dt; if (fl[i].t <= 0) fl.splice(i, 1); }
            },
            draw:function(t, state){
                var r, c, i;
                ctx.fillStyle = '#0a0b26'; ctx.fillRect(0, 0, W, H);
                ctx.fillStyle = '#5b3519'; rrect(ctx, GX - 8, GY - 8, N * CS + 16, N * CS + 16, 12); ctx.fill();
                ctx.fillStyle = '#7b4b27'; rrect(ctx, GX - 3, GY - 3, N * CS + 6, N * CS + 6, 8); ctx.fill();
                for (r = 0; r < N; r++) for (c = 0; c < N; c++){
                    var v = board[r * N + c];
                    if (v) wood(GX + c * CS, GY + r * CS, CS, v);
                    else { ctx.fillStyle = 'rgba(0,0,0,.22)'; rrect(ctx, GX + c * CS + 2, GY + r * CS + 2, CS - 4, CS - 4, 4); ctx.fill(); }
                }
                // ghost
                if (drag && tray[drag.i]){
                    var gp = tray[drag.i], d = dragCell(gp);
                    if (fits(gp, d.r, d.c)) drawPiece(gp, GX + d.c * CS, GY + d.r * CS, CS, 0.5);
                }
                // tray
                ctx.fillStyle = 'rgba(255,255,255,.04)'; rrect(ctx, 8, TY - 6, W - 16, 150, 14); ctx.fill();
                for (i = 0; i < 3; i++){
                    var tp = tray[i]; if (!tp) continue;
                    if (drag && drag.i === i){ var sp0 = slotPos(i, tp); drawPiece(tp, sp0.x, sp0.y, TS, 0.25); continue; }
                    var sp = slotPos(i, tp); drawPiece(tp, sp.x, sp.y, TS, 1);
                }
                if (drag && tray[drag.i]){
                    var dp = tray[drag.i], dc = dragCell(dp);
                    drawPiece(dp, dc.ax, dc.ay, CS, 0.95);
                }
                parts.forEach(function(p){
                    ctx.globalAlpha = Math.max(0, p.life / p.max); ctx.fillStyle = p.col; ctx.fillRect(p.x - 3, p.y - 3, 6, 6);
                });
                ctx.globalAlpha = 1;
                ctx.textAlign = 'center'; ctx.font = '700 18px Poppins, sans-serif';
                fl.forEach(function(f){
                    ctx.globalAlpha = Math.min(1, f.t * 1.5);
                    ctx.fillStyle = '#000'; ctx.fillText(f.txt, f.x + 1, f.y + 1);
                    ctx.fillStyle = '#fff'; ctx.fillText(f.txt, f.x, f.y);
                });
                ctx.globalAlpha = 1;
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.12)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   NEON RUSH 3D — real perspective projection, drawn on a canvas
   (no 3D library to download, so it costs no data)
══════════════════════════════════════════════════════════════ */
GAMES.ringrush = {
    name:'Neon Rush 3D', ovIcon:'cube',
    help:'Drag anywhere (or use arrow keys / WASD) to steer your ship. Fly through the hole in every ring. Grab the gem for a perfect bonus!',
    make:function(api, ctx){
        var W = 360, H = 520, CX = W / 2, CY = H * 0.46, F = 240, ZS = 120, A = 95, FAR = 1500, GAPZ = 520, BOUND = 82;
        var px, py, ppx, rings, stars, sc, n, cnt, speed, hue0, shake, lastHole, fl, tilt, lp, scrollZ, crashed, bannerT;

        function pr(x, y, z){ var k = F / z; return [CX + x * k, CY + y * k]; }
        function clamp(v, a, b){ return v < a ? a : v > b ? b : v; }
        function spawnRing(){
            var hr = Math.max(30, 48 - Math.min(n, 36) * 0.5), lim = A - hr - 8;
            var hx = clamp(lastHole.x + (Math.random() * 2 - 1) * 70, -lim, lim);
            var hy = clamp(lastHole.y + (Math.random() * 2 - 1) * 70, -lim, lim);
            lastHole = { x:hx, y:hy };
            var z = rings.length ? rings[rings.length - 1].z + GAPZ : ZS + 700;
            rings.push({ z:z, hx:hx, hy:hy, hr:hr, h:(n * 29) % 360, passed:false });
            n++;
        }
        function fillRings(){ while (!rings.length || rings[rings.length - 1].z < FAR) spawnRing(); }

        return {
            W:W, H:H, swipe:false, instant:false,
            reset:function(){
                px = 0; py = 0; ppx = 0; rings = []; sc = 0; n = 0; cnt = 0; speed = 460; hue0 = 0; shake = 0; lastHole = { x:0, y:0 };
                fl = []; tilt = 0; lp = null; scrollZ = 0; crashed = false; bannerT = 0;
                stars = [];
                for (var i = 0; i < 70; i++) stars.push({ x:(Math.random() * 2 - 1) * 400, y:(Math.random() * 2 - 1) * 400, z:80 + Math.random() * FAR });
                fillRings();
            },
            preview:function(){
                this.reset(); px = 14; py = 22; sc = 8;
                rings = [
                    { z:ZS + 230,  hx:20,  hy:30,  hr:44, h:200, passed:false },
                    { z:ZS + 700,  hx:-30, hy:-10, hr:44, h:250, passed:false },
                    { z:ZS + 1170, hx:10,  hy:-40, hr:44, h:300, passed:false }
                ];
            },
            dir:function(){}, tap:function(){},
            ptr:function(ty, x, y){
                if (ty === 'down'){ lp = { x:x, y:y }; }
                else if (ty === 'move' && lp){
                    px = clamp(px + (x - lp.x) * (ZS / F) * 1.4, -BOUND, BOUND);
                    py = clamp(py + (y - lp.y) * (ZS / F) * 1.4, -BOUND, BOUND);
                    lp = { x:x, y:y };
                } else lp = null;
            },
            update:function(dt){
                var H_ = api.held, ix = (H_.r ? 1 : 0) - (H_.l ? 1 : 0), iy = (H_.d ? 1 : 0) - (H_.u ? 1 : 0), i;
                px = clamp(px + ix * 150 * dt, -BOUND, BOUND); py = clamp(py + iy * 150 * dt, -BOUND, BOUND);
                tilt += (clamp((px - ppx) / Math.max(dt, 0.001) / 180, -1, 1) - tilt) * Math.min(1, dt * 10); ppx = px;
                speed = 460 + Math.min(n, 44) * 9;
                scrollZ += speed * dt; hue0 += dt * 14;
                for (i = 0; i < stars.length; i++){
                    var s = stars[i]; s.z -= speed * dt * 1.2;
                    if (s.z < 25){ s.z = FAR; s.x = (Math.random() * 2 - 1) * 400; s.y = (Math.random() * 2 - 1) * 400; }
                }
                for (i = 0; i < rings.length; i++){
                    var r = rings[i]; r.z -= speed * dt;
                    if (!r.passed && r.z <= ZS){
                        var d = Math.hypot(px - r.hx, py - r.hy);
                        if (d > r.hr - 7){ crashed = true; shake = 0.6; api.end({ title:'Crashed!' }); return; }
                        r.passed = true;
                        var perfect = d < r.hr * 0.35, pts = perfect ? 15 : 10;
                        sc += pts; cnt++; api.setScore(sc);
                        fl.push({ x:CX + px * F / ZS, y:CY + py * F / ZS - 26, t:0.8, txt:perfect ? 'PERFECT +15' : '+10' });
                        api.sfx(perfect ? 'level' : 'score');
                        if (cnt % 10 === 0){ bannerT = 1.2; }
                    }
                }
                while (rings.length && rings[0].z < 45) rings.shift();
                fillRings();
                for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 36 * dt; fl[i].t -= dt; if (fl[i].t <= 0) fl.splice(i, 1); }
                if (bannerT > 0) bannerT -= dt;
            },
            draw:function(t, state){
                var i, k, p, p2;
                ctx.save();
                if (shake > 0){ shake = Math.max(0, shake - 0.02); ctx.translate((Math.random() - 0.5) * shake * 18, (Math.random() - 0.5) * shake * 18); }
                var hb = Math.round(hue0 + 240) % 360;
                var bg = ctx.createRadialGradient(CX, CY, 10, CX, CY, H * 0.7);
                bg.addColorStop(0, 'hsl(' + hb + ',70%,14%)'); bg.addColorStop(1, '#03030f');
                ctx.fillStyle = bg; ctx.fillRect(-20, -20, W + 40, H + 40);
                // stars
                ctx.lineCap = 'round';
                for (i = 0; i < stars.length; i++){
                    var s = stars[i]; if (s.z < 25) continue;
                    p = pr(s.x, s.y, s.z); p2 = pr(s.x, s.y, s.z + speed * 0.05);
                    ctx.strokeStyle = 'rgba(255,255,255,' + clamp(1 - s.z / FAR, 0.05, 0.9) + ')';
                    ctx.lineWidth = clamp(220 / s.z, 0.6, 2.4);
                    ctx.beginPath(); ctx.moveTo(p[0], p[1]); ctx.lineTo(p2[0], p2[1]); ctx.stroke();
                }
                // tunnel depth frames + corner rails
                ctx.lineWidth = 1;
                for (k = 0; k < 9; k++){
                    var z = FAR - (((k * 190) + scrollZ) % (9 * 190)) + 60;
                    if (z < 60) continue;
                    var o = A * F / z;
                    ctx.strokeStyle = 'hsla(' + hb + ',90%,60%,' + clamp((FAR - z) / 900, 0.02, 0.28) + ')';
                    ctx.strokeRect(CX - o, CY - o, o * 2, o * 2);
                }
                ctx.strokeStyle = 'hsla(' + hb + ',90%,60%,.22)';
                [[-1,-1],[1,-1],[1,1],[-1,1]].forEach(function(cn){
                    var a = pr(cn[0] * A, cn[1] * A, FAR), b = pr(cn[0] * A, cn[1] * A, 45);
                    ctx.beginPath(); ctx.moveTo(a[0], a[1]); ctx.lineTo(b[0], b[1]); ctx.stroke();
                });
                // rings, far to near
                for (i = rings.length - 1; i >= 0; i--){
                    var r = rings[i]; if (r.z < 45) continue;
                    var kk = F / r.z, ob = A * kk, hx = CX + r.hx * kk, hy = CY + r.hy * kk, hr = r.hr * kk;
                    var al = r.z < ZS ? clamp((r.z - 45) / (ZS - 45), 0, 1) : clamp((FAR - r.z) / 500, 0, 1);
                    var col = 'hsl(' + Math.round(r.h + hue0) % 360 + ',100%,60%)';
                    ctx.globalAlpha = al * 0.9;
                    ctx.fillStyle = 'hsla(' + Math.round(r.h + hue0) % 360 + ',100%,50%,.3)';
                    ctx.beginPath(); ctx.rect(CX - ob, CY - ob, ob * 2, ob * 2); ctx.moveTo(hx + hr, hy); ctx.arc(hx, hy, hr, 0, Math.PI * 2); ctx.fill('evenodd');
                    ctx.strokeStyle = col; ctx.lineWidth = clamp(3 * kk, 1.5, 6);
                    if (r.z < 700){ ctx.shadowColor = col; ctx.shadowBlur = 12; }
                    ctx.beginPath(); ctx.arc(hx, hy, hr, 0, Math.PI * 2); ctx.stroke();
                    ctx.strokeRect(CX - ob, CY - ob, ob * 2, ob * 2);
                    ctx.shadowBlur = 0;
                    if (!r.passed){
                        var gs = 9 * kk, rot = t * 3;
                        ctx.fillStyle = '#fff'; ctx.globalAlpha = al;
                        ctx.beginPath();
                        ctx.moveTo(hx, hy - gs * 1.3); ctx.lineTo(hx + gs * Math.abs(Math.cos(rot)) , hy); ctx.lineTo(hx, hy + gs * 1.3); ctx.lineTo(hx - gs * Math.abs(Math.cos(rot)), hy);
                        ctx.closePath(); ctx.fill();
                    }
                    ctx.globalAlpha = 1;
                }
                // ship
                var sx = CX + px * F / ZS, sy = CY + py * F / ZS;
                if (!(crashed && state === 'over')){
                    ctx.save(); ctx.translate(sx, sy); ctx.rotate(tilt * 0.5);
                    var fl2 = 7 + Math.random() * 6;
                    ctx.fillStyle = 'rgba(255,170,40,.95)'; ctx.beginPath(); ctx.moveTo(-4, 8); ctx.lineTo(0, 8 + fl2 + 6); ctx.lineTo(4, 8); ctx.closePath(); ctx.fill();
                    ctx.shadowColor = '#ff2d2d'; ctx.shadowBlur = 14;
                    ctx.fillStyle = '#ff2d2d'; ctx.beginPath(); ctx.moveTo(0, -15); ctx.lineTo(15, 12); ctx.lineTo(0, 6); ctx.lineTo(-15, 12); ctx.closePath(); ctx.fill();
                    ctx.shadowBlur = 0;
                    ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.moveTo(0, -9); ctx.lineTo(5, 5); ctx.lineTo(0, 2); ctx.lineTo(-5, 5); ctx.closePath(); ctx.fill();
                    ctx.restore();
                }
                // texts
                ctx.textAlign = 'center'; ctx.font = '700 15px Poppins, sans-serif';
                for (i = 0; i < fl.length; i++){ ctx.globalAlpha = clamp(fl[i].t * 1.6, 0, 1); ctx.fillStyle = '#fff'; ctx.fillText(fl[i].txt, fl[i].x, fl[i].y); }
                ctx.globalAlpha = 1;
                ctx.fillStyle = 'rgba(255,255,255,.92)'; ctx.font = '700 32px Poppins, sans-serif'; ctx.fillText(sc, CX, 52);
                if (bannerT > 0){ ctx.globalAlpha = clamp(bannerT, 0, 1); ctx.fillStyle = '#ffd400'; ctx.font = '700 20px Poppins, sans-serif'; ctx.fillText('FASTER!', CX, 86); ctx.globalAlpha = 1; }
                ctx.restore();
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.18)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   BUBBLE SHOOTER
══════════════════════════════════════════════════════════════ */
GAMES.bubbles = {
    name:'Bubble Shooter', ovIcon:'bubble',
    help:'Drag to aim, release to shoot. Match 3+ colors to pop them; anything left hanging falls. Tap the small bubble to swap. Don’t let the ceiling drop on you!',
    make:function(api, ctx){
        var W = 360, H = 560, R = 18, RH = R * 1.7320508, COLS = 10, DANGER = H - 104, MAXR = Math.floor((DANGER - R) / RH);
        var SX = W / 2, SY = H - 50, NX = 52, NY = H - 38, MISS_MAX = 5, DROP_TIME = 24;
        var BC = ['#ff3b5c', '#ff9f1a', '#ffd93d', '#35d07f', '#2f9bff', '#b86bff'];
        var g, par, cur, nxt, aim, fly, sc, miss, dropT, dropOff, parts, falls, fl, level, K, aiming, over;

        function off(r){ return (r + par) & 1; }
        function cnt(r){ return COLS - off(r); }
        function bx(r, c){ return R + c * 2 * R + off(r) * R; }
        function by(r){ return R + r * RH; }
        function valid(r, c){ return r >= 0 && r < g.length && c >= 0 && c < cnt(r); }
        function nbrs(r, c){
            var o = off(r), res = [[r, c - 1], [r, c + 1]], dr = [-1, 1], i, rr;
            for (i = 0; i < 2; i++){
                rr = r + dr[i];
                if (o){ res.push([rr, c], [rr, c + 1]); } else { res.push([rr, c - 1], [rr, c]); }
            }
            return res.filter(function(n){ return valid(n[0], n[1]); });
        }
        function present(){
            var s = {}, r, c;
            for (r = 0; r < g.length; r++) for (c = 0; c < cnt(r); c++) if (g[r][c] >= 0) s[g[r][c]] = 1;
            return Object.keys(s).map(Number);
        }
        function pickType(){ var p = present(); return p.length ? p[rand(p.length)] : rand(K); }
        function ensureRow(r){ while (g.length <= r){ var row = []; for (var c = 0; c < COLS; c++) row.push(-1); g.push(row); } }
        function trim(){
            while (g.length > 1){
                var empty = true, last = g[g.length - 1];
                for (var c = 0; c < COLS; c++) if (last[c] >= 0){ empty = false; break; }
                if (empty) g.pop(); else break;
            }
        }
        function fillRows(n){
            g = []; par = 0;
            for (var r = 0; r < n; r++){
                var row = [];
                for (var c = 0; c < COLS; c++) row.push(c < cnt(r) ? rand(K) : -1);
                g.push(row);
            }
        }
        function hud(){ api.extra('Drop in ' + Math.max(0, MISS_MAX - miss) + '  ·  Lv ' + level); }
        function lose(){ if (over) return; over = true; api.end({ title:'The ceiling got you' }); }
        function checkLose(){
            for (var r = MAXR; r < g.length; r++) for (var c = 0; c < cnt(r); c++) if (g[r][c] >= 0){ lose(); return true; }
            return false;
        }
        function addRow(){
            par ^= 1;
            var ps = present(), row = [];
            for (var c = 0; c < COLS; c++) row.push(c < cnt(0) ? (ps.length ? ps[rand(ps.length)] : rand(K)) : -1);
            g.unshift(row); dropOff = RH; miss = 0; dropT = DROP_TIME; api.sfx('thud'); hud(); checkLose();
        }
        function collide(x, y){
            if (y <= R + 1) return true;
            var lim = (R * 1.8) * (R * 1.8), r, c;
            for (r = 0; r < g.length; r++){
                var yy = by(r) - dropOff; if (Math.abs(yy - y) > R * 2) continue;
                for (c = 0; c < cnt(r); c++){
                    if (g[r][c] < 0) continue;
                    var dx = bx(r, c) - x, dy = yy - y;
                    if (dx * dx + dy * dy < lim) return true;
                }
            }
            return false;
        }
        function snap(x, y){
            var r0 = Math.round((y + dropOff - R) / RH), best = [0, 0], bd = 1e12, r, c;
            for (r = Math.max(0, r0 - 1); r <= r0 + 1; r++){
                for (c = 0; c < cnt(r); c++){
                    if (r < g.length && g[r][c] >= 0) continue;
                    var dx = bx(r, c) - x, dy = by(r) - dropOff - y, d = dx * dx + dy * dy;
                    if (d < bd){ bd = d; best = [r, c]; }
                }
            }
            return best;
        }
        function burst(x, y, col, n){
            for (var i = 0; i < n; i++){
                var a = Math.random() * 6.283, v = 50 + Math.random() * 140;
                parts.push({ x:x, y:y, vx:Math.cos(a) * v, vy:Math.sin(a) * v, life:0.45, max:0.45, col:col });
            }
        }
        function resolve(r, c){
            var t = g[r][c], seen = {}, stack = [[r, c]], grp = [], popped = 0, dropped = 0;
            seen[r * COLS + c] = 1;
            while (stack.length){
                var p = stack.pop(); grp.push(p);
                nbrs(p[0], p[1]).forEach(function(n){
                    var k = n[0] * COLS + n[1];
                    if (!seen[k] && g[n[0]][n[1]] === t){ seen[k] = 1; stack.push(n); }
                });
            }
            if (grp.length >= 3){
                grp.forEach(function(p){ burst(bx(p[0], p[1]), by(p[0]) - dropOff, BC[t], 6); g[p[0]][p[1]] = -1; });
                popped = grp.length;
                // anything not connected to the top row falls
                var conn = {}, st = [], i, j;
                for (j = 0; j < cnt(0); j++) if (g[0][j] >= 0){ conn[j] = 1; st.push([0, j]); }
                while (st.length){
                    var q = st.pop();
                    nbrs(q[0], q[1]).forEach(function(n){
                        var k = n[0] * COLS + n[1];
                        if (!conn[k] && g[n[0]][n[1]] >= 0){ conn[k] = 1; st.push(n); }
                    });
                }
                for (i = 0; i < g.length; i++) for (j = 0; j < cnt(i); j++){
                    if (g[i][j] >= 0 && !conn[i * COLS + j]){
                        falls.push({ x:bx(i, j), y:by(i) - dropOff, vx:(Math.random() - 0.5) * 90, vy:-60 - Math.random() * 60, t:g[i][j] });
                        g[i][j] = -1; dropped++;
                    }
                }
                var pts = popped * 10 + dropped * 20;
                sc += pts; api.setScore(sc);
                fl.push({ x:bx(r, c), y:by(r) - dropOff, t:0.9, txt:'+' + pts });
                api.sfx(dropped ? 'level' : 'clear');
            }
            return { popped:popped, dropped:dropped };
        }
        function levelUp(){
            level++; sc += 200; api.setScore(sc);
            K = Math.min(6, 4 + level); fillRows(Math.min(8, 4 + level)); dropOff = 0; miss = 0; dropT = DROP_TIME;
            fl.push({ x:W / 2, y:H / 2 - 60, t:1.4, txt:'LEVEL ' + level + '  +200' });
            api.sfx('win'); cur = pickType(); nxt = pickType(); hud();
        }
        function land(){
            var cell = snap(fly.x, fly.y), r = cell[0], c = cell[1];
            ensureRow(r); g[r][c] = fly.t; fly = null;
            var res = resolve(r, c);
            trim();
            if (!present().length){ levelUp(); return; }
            if (!res.popped){ api.sfx('thud'); miss++; if (miss >= MISS_MAX) addRow(); }
            cur = pickType(); nxt = pickType();
            if (!over) checkLose();
            hud();
        }
        function fire(){
            if (fly || over) return;
            fly = { x:SX, y:SY, dx:Math.cos(aim), dy:-Math.sin(aim), t:cur };
            api.sfx('whoosh');
        }
        function setAim(x, y){
            var dx = x - SX, dy = Math.max(20, SY - y);
            aim = Math.max(0.18, Math.min(Math.PI - 0.18, Math.atan2(dy, dx)));
        }
        function bub(x, y, t, r){
            ctx.fillStyle = BC[t]; ctx.beginPath(); ctx.arc(x, y, r - 1, 0, 7); ctx.fill();
            ctx.strokeStyle = 'rgba(0,0,0,.3)'; ctx.lineWidth = 2; ctx.stroke();
            ctx.fillStyle = 'rgba(255,255,255,.5)'; ctx.beginPath(); ctx.ellipse(x - r * 0.3, y - r * 0.35, r * 0.3, r * 0.18, -0.6, 0, 7); ctx.fill();
        }

        return {
            W:W, H:H, swipe:false, instant:false,
            reset:function(){
                level = 1; K = 5; fillRows(6); dropOff = 0; sc = 0; miss = 0; dropT = DROP_TIME; aim = Math.PI / 2; fly = null;
                parts = []; falls = []; fl = []; aiming = false; over = false;
                cur = pickType(); nxt = pickType();
            },
            preview:function(){ this.reset(); aim = 1.3; },
            begin:function(){ hud(); },
            dir:function(d){
                if (d === 'u') fire();
                else if (d === 'l') aim = Math.min(Math.PI - 0.18, aim + 0.06);
                else if (d === 'r') aim = Math.max(0.18, aim - 0.06);
            },
            tap:function(){ fire(); },
            ptr:function(ty, x, y){
                if (ty === 'down'){
                    if (Math.hypot(x - NX, y - NY) < 28){ var t = cur; cur = nxt; nxt = t; api.sfx('pop'); aiming = false; return; }
                    aiming = true; setAim(x, y);
                } else if (ty === 'move'){
                    if (aiming) setAim(x, y);
                } else if (ty === 'up'){
                    if (aiming){ aiming = false; if (x > -500) fire(); }
                }
            },
            update:function(dt){
                var Hh = api.held, i;
                aim = Math.max(0.18, Math.min(Math.PI - 0.18, aim + ((Hh.l ? 1 : 0) - (Hh.r ? 1 : 0)) * 1.6 * dt));
                if (dropOff > 0) dropOff = Math.max(0, dropOff - dt * 160);
                for (i = parts.length - 1; i >= 0; i--){ var p = parts[i]; p.x += p.vx * dt; p.y += p.vy * dt; p.life -= dt; if (p.life <= 0) parts.splice(i, 1); }
                for (i = falls.length - 1; i >= 0; i--){ var f = falls[i]; f.vy += 1100 * dt; f.x += f.vx * dt; f.y += f.vy * dt; if (f.y > H + 30) falls.splice(i, 1); }
                for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 32 * dt; fl[i].t -= dt; if (fl[i].t <= 0) fl.splice(i, 1); }
                if (!fly){
                    dropT -= dt;
                    if (dropT <= 0){ addRow(); if (over) return; }
                } else {
                    var dist = 780 * dt, steps = Math.max(1, Math.ceil(dist / 6)), sd = dist / steps;
                    for (var s = 0; s < steps; s++){
                        fly.x += fly.dx * sd; fly.y += fly.dy * sd;
                        if (fly.x < R){ fly.x = R; fly.dx = -fly.dx; }
                        if (fly.x > W - R){ fly.x = W - R; fly.dx = -fly.dx; }
                        if (collide(fly.x, fly.y)){ land(); break; }
                    }
                }
            },
            draw:function(t, state){
                var r, c, i;
                var bg = ctx.createLinearGradient(0, 0, 0, H);
                bg.addColorStop(0, '#0a0c33'); bg.addColorStop(1, '#1b0f3a');
                ctx.fillStyle = bg; ctx.fillRect(0, 0, W, H);
                // ceiling + drop meter
                ctx.fillStyle = '#ff2d2d'; ctx.fillRect(0, 0, W, 5);
                ctx.fillStyle = 'rgba(255,255,255,.55)'; ctx.fillRect(0, 5, W * Math.max(0, dropT) / DROP_TIME, 2);
                // bubbles
                for (r = 0; r < g.length; r++) for (c = 0; c < cnt(r); c++){
                    if (g[r][c] >= 0) bub(bx(r, c), by(r) - dropOff, g[r][c], R);
                }
                // danger line
                ctx.strokeStyle = 'rgba(255,45,45,.55)'; ctx.lineWidth = 2; ctx.setLineDash([8, 8]);
                ctx.beginPath(); ctx.moveTo(0, DANGER); ctx.lineTo(W, DANGER); ctx.stroke(); ctx.setLineDash([]);
                // aim guide
                if (!fly && state === 'playing'){
                    var x = SX, y = SY, dx = Math.cos(aim), dy = -Math.sin(aim), acc = 0, n = 0;
                    ctx.fillStyle = 'rgba(255,255,255,.55)';
                    while (n < 420){
                        x += dx * 5; y += dy * 5;
                        if (x < R){ x = R; dx = -dx; } if (x > W - R){ x = W - R; dx = -dx; }
                        if (collide(x, y)) break;
                        acc += 5; if (acc >= 18){ acc = 0; ctx.beginPath(); ctx.arc(x, y, 2.2, 0, 7); ctx.fill(); }
                        n++;
                    }
                }
                // falling + particles
                for (i = 0; i < falls.length; i++) bub(falls[i].x, falls[i].y, falls[i].t, R);
                for (i = 0; i < parts.length; i++){
                    ctx.globalAlpha = Math.max(0, parts[i].life / parts[i].max); ctx.fillStyle = parts[i].col;
                    ctx.beginPath(); ctx.arc(parts[i].x, parts[i].y, 3, 0, 7); ctx.fill();
                }
                ctx.globalAlpha = 1;
                // shooter
                ctx.save(); ctx.translate(SX, SY); ctx.rotate(-(aim - Math.PI / 2));
                ctx.fillStyle = '#2a2f6e'; rrect(ctx, -7, -44, 14, 44, 5); ctx.fill();
                ctx.fillStyle = '#ff2d2d'; ctx.fillRect(-7, -44, 14, 5);
                ctx.restore();
                ctx.fillStyle = '#1a1e55'; ctx.beginPath(); ctx.arc(SX, SY + 6, 30, Math.PI, 0); ctx.fill();
                ctx.fillStyle = '#ff2d2d'; ctx.fillRect(SX - 30, SY + 5, 60, 3);
                if (!fly) bub(SX, SY, cur, R);
                else bub(fly.x, fly.y, fly.t, R);
                // next bubble
                ctx.fillStyle = 'rgba(255,255,255,.07)'; ctx.beginPath(); ctx.arc(NX, NY, 25, 0, 7); ctx.fill();
                bub(NX, NY, nxt, R * 0.8);
                ctx.fillStyle = 'rgba(255,255,255,.5)'; ctx.font = '600 9px Poppins, sans-serif'; ctx.textAlign = 'center'; ctx.fillText('NEXT', NX, NY + 36);
                // floating texts
                ctx.font = '700 16px Poppins, sans-serif';
                for (i = 0; i < fl.length; i++){
                    ctx.globalAlpha = Math.min(1, fl[i].t * 1.6);
                    ctx.fillStyle = '#000'; ctx.fillText(fl[i].txt, fl[i].x + 1, fl[i].y + 1);
                    ctx.fillStyle = '#fff'; ctx.fillText(fl[i].txt, fl[i].x, fl[i].y);
                }
                ctx.globalAlpha = 1;
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.14)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   WHACK-A-BARA  (45 seconds)
══════════════════════════════════════════════════════════════ */
GAMES.whackabara = {
    name:'Whack-a-Bara', ovIcon:'paw',
    help:'Tap the capybaras as they pop up. Golden ones are worth more. Never tap the bombs! You have 45 seconds.',
    make:function(api, ctx){
        var W = 360, H = 480, TOTAL = 45, HX = [60, 180, 300], HY = [175, 305, 435];
        var holes, sc, tl, spawnT, streak, shake, parts, fl, lastDraw, lastSec;

        function reset9(){ holes = []; for (var i = 0; i < 9; i++) holes.push({ x:HX[i % 3], y:HY[(i / 3) | 0], k:'', st:'idle', rise:0, life:0, hitT:0 }); }
        function spawn(){
            var free = [], up = 0, i;
            for (i = 0; i < 9; i++){ if (holes[i].st === 'idle') free.push(i); else up++; }
            if (!free.length || up >= 4) return;
            var h = holes[free[rand(free.length)]], prog = (TOTAL - tl) / TOTAL, r = Math.random();
            h.k = r < 0.08 ? 'g' : (r < 0.08 + 0.2 + prog * 0.12 ? 'x' : 'b');
            h.st = 'up'; h.rise = 0; h.life = 1.25 - 0.65 * prog;
        }
        function burst(x, y, cols){
            for (var i = 0; i < 12; i++){
                var a = Math.random() * 6.283, v = 70 + Math.random() * 180;
                parts.push({ x:x, y:y, vx:Math.cos(a) * v, vy:Math.sin(a) * v - 60, life:0.5, max:0.5, col:cols[rand(cols.length)] });
            }
        }
        function bara(x, y, k, rise, hit, t){
            var o = (1 - rise) * 92;
            ctx.save(); ctx.beginPath(); ctx.rect(x - 75, y - 150, 150, 150 + 2); ctx.clip();
            if (k === 'x'){
                ctx.fillStyle = '#15172e'; ctx.beginPath(); ctx.arc(x, y + o - 34, 31, 0, 7); ctx.fill();
                ctx.strokeStyle = '#ff2d2d'; ctx.lineWidth = 4; ctx.beginPath(); ctx.arc(x, y + o - 34, 31, 0, 7); ctx.stroke();
                ctx.fillStyle = 'rgba(255,255,255,.35)'; ctx.beginPath(); ctx.ellipse(x - 10, y + o - 46, 9, 5, -0.6, 0, 7); ctx.fill();
                ctx.strokeStyle = '#d9a05b'; ctx.lineWidth = 3; ctx.beginPath(); ctx.moveTo(x + 14, y + o - 62); ctx.quadraticCurveTo(x + 24, y + o - 78, x + 12, y + o - 86); ctx.stroke();
                ctx.fillStyle = (Math.floor(t * 12) % 2) ? '#ffd400' : '#ff6a00'; ctx.beginPath(); ctx.arc(x + 12, y + o - 88, 5, 0, 7); ctx.fill();
                ctx.fillStyle = '#fff'; ctx.fillRect(x - 12, y + o - 38, 6, 6); ctx.fillRect(x + 6, y + o - 38, 6, 6);
            } else {
                var gold = k === 'g';
                var body = gold ? '#ffcf3a' : '#c97b34', head = gold ? '#ffe27a' : '#d98c3f', ear = gold ? '#e0a512' : '#a5642c', out = gold ? '#8a5a00' : '#6b3a14';
                var sy = hit ? 0.8 : 1;
                ctx.translate(x, y + o); ctx.scale(1, sy);
                ctx.lineWidth = 2.5; ctx.strokeStyle = out; ctx.lineJoin = 'round';
                ctx.fillStyle = body; ctx.beginPath(); ctx.ellipse(0, -8, 42, 40, 0, 0, 7); ctx.fill(); ctx.stroke();
                ctx.fillStyle = ear; ctx.beginPath(); ctx.arc(-24, -66, 8, 0, 7); ctx.fill(); ctx.stroke();
                ctx.beginPath(); ctx.arc(24, -66, 8, 0, 7); ctx.fill(); ctx.stroke();
                ctx.fillStyle = head; rrect(ctx, -34, -78, 68, 56, 24); ctx.fill(); ctx.stroke();
                ctx.fillStyle = '#e7a35a'; if (gold) ctx.fillStyle = '#fff0b0';
                ctx.beginPath(); ctx.ellipse(0, -36, 22, 15, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#4a2208'; rrect(ctx, -10, -50, 20, 12, 5); ctx.fill();
                ctx.strokeStyle = '#1a0d04'; ctx.lineWidth = 3; ctx.beginPath();
                if (hit){ ctx.moveTo(-20, -64); ctx.lineTo(-12, -56); ctx.moveTo(-12, -64); ctx.lineTo(-20, -56); ctx.moveTo(12, -64); ctx.lineTo(20, -56); ctx.moveTo(20, -64); ctx.lineTo(12, -56); }
                else { ctx.moveTo(-18, -60); ctx.arc(-16, -60, 2.2, 0, 7); ctx.moveTo(18, -60); ctx.arc(16, -60, 2.2, 0, 7); }
                ctx.stroke();
                ctx.fillStyle = 'rgba(255,80,70,.55)'; ctx.beginPath(); ctx.arc(-26, -46, 5, 0, 7); ctx.arc(26, -46, 5, 0, 7); ctx.fill();
                if (gold){ ctx.fillStyle = '#fff'; var sp = 1 + Math.sin(t * 10) * 0.4; ctx.fillRect(-4 * sp, -92, 8 * sp, 2); ctx.fillRect(-1, -95, 2, 8); }
            }
            ctx.restore();
        }

        return {
            W:W, H:H, swipe:false, instant:false,
            reset:function(){
                reset9(); sc = 0; tl = TOTAL; spawnT = 0.5; streak = 0; shake = 0; parts = []; fl = []; lastDraw = 0; lastSec = -1;
            },
            preview:function(){
                this.reset(); sc = 120; tl = 30;
                holes[0].k = 'b'; holes[0].st = 'up'; holes[0].rise = 1;
                holes[4].k = 'g'; holes[4].st = 'up'; holes[4].rise = 1;
                holes[6].k = 'x'; holes[6].st = 'up'; holes[6].rise = 0.9;
                holes[8].k = 'b'; holes[8].st = 'up'; holes[8].rise = 0.6;
            },
            begin:function(){ api.extra(TOTAL + 's'); },
            dir:function(){}, tap:function(){},
            ptr:function(ty, x, y){
                if (ty !== 'down') return;
                for (var i = 0; i < 9; i++){
                    var h = holes[i];
                    if (h.st !== 'up' && h.st !== 'down') continue;
                    if (h.rise < 0.3) continue;
                    if (Math.abs(x - h.x) < 52 && y > h.y - 90 && y < h.y + 28){
                        if (h.k === 'x'){
                            sc = Math.max(0, sc - 15); tl -= 2; shake = 0.5; streak = 0; api.sfx('hit');
                            fl.push({ x:h.x, y:h.y - 90, t:0.9, txt:'-15  -2s', bad:true });
                            burst(h.x, h.y - 40, ['#ff2d2d', '#ff9f1a', '#222']);
                        } else {
                            streak++; var pts = (h.k === 'g' ? 30 : 10) + Math.min(streak, 10);
                            sc += pts; api.sfx(h.k === 'g' ? 'level' : 'score');
                            fl.push({ x:h.x, y:h.y - 90, t:0.8, txt:'+' + pts });
                            burst(h.x, h.y - 40, h.k === 'g' ? ['#ffd400', '#fff', '#ffb300'] : ['#ffcb85', '#fff', '#c97b34']);
                        }
                        api.setScore(sc); h.st = 'hit'; h.hitT = 0.25;
                        return;
                    }
                }
                streak = 0;
            },
            update:function(dt){
                tl -= dt;
                var sec = Math.max(0, Math.ceil(tl));
                if (sec !== lastSec){ lastSec = sec; api.extra(sec + 's' + (streak > 2 ? '  ·  x' + streak : '')); }
                if (tl <= 0){ api.end({ title:'Time’s up!' }); return; }
                spawnT -= dt;
                if (spawnT <= 0){
                    spawn();
                    var prog = (TOTAL - tl) / TOTAL;
                    spawnT = Math.max(0.3, 0.85 - prog * 0.5) + Math.random() * 0.25;
                }
                for (var i = 0; i < 9; i++){
                    var h = holes[i];
                    if (h.st === 'up'){
                        h.rise = Math.min(1, h.rise + dt * 7); h.life -= dt;
                        if (h.life <= 0) h.st = 'down';
                    } else if (h.st === 'down'){
                        h.rise -= dt * 8;
                        if (h.rise <= 0){ if (h.k !== 'x') streak = 0; h.st = 'idle'; h.rise = 0; h.k = ''; }
                    } else if (h.st === 'hit'){
                        h.hitT -= dt; h.rise -= dt * 5;
                        if (h.rise <= 0){ h.st = 'idle'; h.rise = 0; h.k = ''; }
                    }
                }
            },
            draw:function(t, state){
                var dd = (state === 'preview') ? 0 : Math.min(0.05, Math.max(0, t - lastDraw)); lastDraw = t;
                var i;
                if (dd > 0){
                    if (shake > 0) shake = Math.max(0, shake - dd * 1.5);
                    for (i = parts.length - 1; i >= 0; i--){ var p = parts[i]; p.x += p.vx * dd; p.y += p.vy * dd; p.vy += 600 * dd; p.life -= dd; if (p.life <= 0) parts.splice(i, 1); }
                    for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 34 * dd; fl[i].t -= dd; if (fl[i].t <= 0) fl.splice(i, 1); }
                }
                ctx.save();
                if (shake > 0) ctx.translate((Math.random() - 0.5) * shake * 14, (Math.random() - 0.5) * shake * 14);
                var bg = ctx.createLinearGradient(0, 0, 0, H);
                bg.addColorStop(0, '#9be070'); bg.addColorStop(1, '#4fa346');
                ctx.fillStyle = bg; ctx.fillRect(-20, -20, W + 40, H + 40);
                ctx.fillStyle = 'rgba(255,255,255,.08)';
                for (i = 0; i < 14; i++) ctx.fillRect((i * 67) % W, 70 + (i * 41) % (H - 90), 20, 3);
                // timer bar
                ctx.fillStyle = 'rgba(0,0,0,.25)'; rrect(ctx, 14, 14, W - 28, 12, 6); ctx.fill();
                ctx.fillStyle = tl < 10 ? '#ff2d2d' : '#fff'; rrect(ctx, 14, 14, Math.max(8, (W - 28) * Math.max(0, tl) / TOTAL), 12, 6); ctx.fill();
                ctx.fillStyle = '#10331f'; ctx.font = '700 30px Poppins, sans-serif'; ctx.textAlign = 'center';
                ctx.fillText(sc, W / 2, 64);
                if (streak > 2){ ctx.font = '700 13px Poppins, sans-serif'; ctx.fillStyle = '#7a2a00'; ctx.fillText('streak x' + streak, W / 2, 84); }
                // holes
                for (i = 0; i < 9; i++){
                    var h = holes[i];
                    ctx.fillStyle = '#2a1406'; ctx.beginPath(); ctx.ellipse(h.x, h.y, 52, 16, 0, 0, 7); ctx.fill();
                    if (h.rise > 0) bara(h.x, h.y, h.k, h.rise, h.st === 'hit', t);
                    ctx.fillStyle = '#8a5a2b'; ctx.beginPath(); ctx.ellipse(h.x, h.y + 4, 58, 17, 0, 0, Math.PI); ctx.fill();
                    ctx.fillStyle = '#a57239'; ctx.beginPath(); ctx.ellipse(h.x, h.y + 3, 58, 14, 0, 0, Math.PI); ctx.fill();
                }
                parts.forEach(function(p){
                    ctx.globalAlpha = Math.max(0, p.life / p.max); ctx.fillStyle = p.col;
                    ctx.beginPath(); ctx.arc(p.x, p.y, 3.5, 0, 7); ctx.fill();
                });
                ctx.globalAlpha = 1;
                ctx.font = '700 19px Poppins, sans-serif'; ctx.textAlign = 'center';
                fl.forEach(function(f){
                    ctx.globalAlpha = Math.min(1, f.t * 1.6);
                    ctx.fillStyle = '#fff'; ctx.fillText(f.txt, f.x + 1, f.y + 1);
                    ctx.fillStyle = f.bad ? '#c40000' : '#10331f'; ctx.fillText(f.txt, f.x, f.y);
                });
                ctx.globalAlpha = 1;
                ctx.restore();
                if (state === 'over'){ ctx.fillStyle = 'rgba(0,0,0,.2)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   MINESWEEPER  (Easy / Medium / Hard / Daily seeded board)
══════════════════════════════════════════════════════════════ */
GAMES.minesweeper = {
    name:'Minesweeper', ovIcon:'bomb',
    help:'Tap to reveal. Long-press (or switch on Flag mode) to flag a mine. Tap a number to clear around it once its flags are placed. “Daily” is the same board for everyone today.',
    make:function(api, ctx, host){
        var LV = {
            easy:   { n:'Easy',   c:8,  r:10, m:10, base:100 },
            medium: { n:'Medium', c:9,  r:14, m:24, base:220 },
            hard:   { n:'Hard',   c:10, r:16, m:36, base:380 },
            daily:  { n:'Daily',  c:9,  r:12, m:20, base:300 }
        };
        var lv = 'easy', L = LV.easy, mine = [], adj = [], opened = [], flag = [], cells = [], flagMode = false;
        var started = false, placed = false, over = false, revealed = 0, flags = 0, elapsed = 0, lastSec = -1, flagBtn = null, lpT = 0, lpFired = false, boom = -1, dayStr = '';
        var NC = ['', '#6fb1ff', '#4ade80', '#ff6b6b', '#a78bfa', '#ffb347', '#2dd4bf', '#f5f5f5', '#9aa0c8'];

        function fmt(s){ var m = Math.floor(s / 60), x = s % 60; return (m < 10 ? '0' : '') + m + ':' + (x < 10 ? '0' : '') + x; }
        function mulberry(a){
            return function(){
                a |= 0; a = a + 0x6D2B79F5 | 0;
                var t = Math.imul(a ^ a >>> 15, 1 | a);
                t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
                return ((t ^ t >>> 14) >>> 0) / 4294967296;
            };
        }
        function hashStr(s){ var h = 5381; for (var i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) | 0; return h >>> 0; }
        function nbs(i){
            var r = (i / L.c) | 0, c = i % L.c, out = [], dr, dc;
            for (dr = -1; dr <= 1; dr++) for (dc = -1; dc <= 1; dc++){
                if (!dr && !dc) continue;
                var rr = r + dr, cc = c + dc;
                if (rr >= 0 && rr < L.r && cc >= 0 && cc < L.c) out.push(rr * L.c + cc);
            }
            return out;
        }
        function hud(){ api.extra(L.n + '  ·  ' + fmt(Math.floor(elapsed)) + '  ·  Mines ' + (L.m - flags)); }

        /* mines are laid out from a seed (daily) or at random, then moved off the first tap */
        function place(first){
            var total = L.c * L.r, order = [], i, rng = lv === 'daily' ? mulberry(hashStr('dezzme-ms-' + dayStr)) : Math.random;
            for (i = 0; i < total; i++) order.push(i);
            for (i = total - 1; i > 0; i--){ var j = Math.floor(rng() * (i + 1)), t = order[i]; order[i] = order[j]; order[j] = t; }
            mine = []; for (i = 0; i < total; i++) mine.push(false);
            for (i = 0; i < L.m; i++) mine[order[i]] = true;
            var zone = {}; zone[first] = 1; nbs(first).forEach(function(n){ zone[n] = 1; });
            for (i = 0; i < total; i++){
                if (mine[i] && zone[i]){
                    for (var k = 0; k < total; k++){
                        if (!mine[k] && !zone[k]){ mine[k] = true; mine[i] = false; break; }
                    }
                }
            }
            adj = [];
            for (i = 0; i < total; i++){
                var n = 0; nbs(i).forEach(function(x){ if (mine[x]) n++; }); adj.push(n);
            }
            placed = true;
        }
        function render(){
            for (var i = 0; i < cells.length; i++){
                var c = cells[i], cls = 'ms-c', h = '';
                if (opened[i]){
                    cls += ' o';
                    if (mine[i]){ cls += (i === boom ? ' x' : ' m'); h = ic('bomb'); }
                    else if (adj[i]){ h = '<span style="color:' + NC[adj[i]] + '">' + adj[i] + '</span>'; }
                } else if (flag[i]){ cls += ' f'; h = ic('flag'); }
                if (c._cls !== cls){ c.className = cls; c._cls = cls; }
                if (c._h !== h){ c.innerHTML = h; c._h = h; }
            }
            hud();
        }
        function lose(i){
            over = true; boom = i;
            for (var k = 0; k < mine.length; k++) if (mine[k] && !flag[k]) opened[k] = true;
            for (k = 0; k < mine.length; k++) if (flag[k] && !mine[k]) opened[k] = false;
            render(); api.sfx('hit');
            api.setScore(revealed);
            api.end({ title:'Boom!', sub:'You cleared ' + revealed + ' safe squares before hitting a mine.' });
        }
        function win(){
            over = true; render(); api.sfx('win');
            var sec = Math.floor(elapsed), sc = L.base + Math.max(0, 150 - sec);
            api.setScore(sc);
            api.end({ win:true, title:'Board cleared!', sub:L.n + (lv === 'daily' ? ' · ' + dayStr : '') + '  ·  ' + fmt(sec) });
        }
        function flood(i){
            var st = [i];
            while (st.length){
                var p = st.pop();
                if (opened[p] || flag[p] || mine[p]) continue;
                opened[p] = true; revealed++;
                if (!adj[p]) nbs(p).forEach(function(n){ if (!opened[n] && !flag[n]) st.push(n); });
            }
        }
        function reveal(i){
            if (over || flag[i] || opened[i]) return;
            if (!placed){ place(i); started = true; }
            if (mine[i]){ lose(i); return; }
            flood(i); api.sfx('pop');
            if (revealed >= L.c * L.r - L.m){ win(); return; }
            render();
        }
        function toggleFlag(i){
            if (over || opened[i]) return;
            flag[i] = !flag[i]; flags += flag[i] ? 1 : -1; api.sfx('place'); render();
        }
        function chord(i){
            if (over || !placed || !adj[i]) return;
            var n = nbs(i), f = 0; n.forEach(function(x){ if (flag[x]) f++; });
            if (f !== adj[i]) return;
            for (var k = 0; k < n.length; k++){
                var x = n[k]; if (flag[x] || opened[x]) continue;
                if (mine[x]){ lose(x); return; }
                flood(x);
            }
            api.sfx('pop');
            if (revealed >= L.c * L.r - L.m){ win(); return; }
            render();
        }
        function tapCell(i){
            if (over) return;
            if (opened[i]) chord(i);
            else if (flagMode) toggleFlag(i);
            else reveal(i);
        }
        function syncFlagBtn(){
            if (!flagBtn) return;
            flagBtn.className = 'sd-t' + (flagMode ? ' on' : '');
            flagBtn.innerHTML = ic('flag') + ' Flag mode: ' + (flagMode ? 'On' : 'Off');
        }
        function build(){
            host.innerHTML = '';
            var wrap = document.createElement('div'); wrap.className = 'ms-wrap';
            var k = (window.matchMedia && window.matchMedia('(max-width:800px)').matches) ? 1.1 : 1;     // 10% bigger on phones
            wrap.style.width = 'min(100%, ' + (k * L.c / L.r).toFixed(3) + ' * (100dvh - 300px), ' + Math.round(440 * k) + 'px)';
            wrap.style.minWidth = Math.round(230 * k) + 'px';
            var grid = document.createElement('div'); grid.className = 'ms-grid';
            grid.style.gridTemplateColumns = 'repeat(' + L.c + ', 1fr)';
            cells = [];
            for (var i = 0; i < L.c * L.r; i++){
                var c = document.createElement('div'); c.className = 'ms-c';
                (function(i, c){
                    c.addEventListener('pointerdown', function(e){
                        e.stopPropagation(); e.preventDefault();
                        if (over) return;
                        lpFired = false; clearTimeout(lpT);
                        if (e.button === 2){ lpFired = true; toggleFlag(i); return; }
                        lpT = setTimeout(function(){
                            lpFired = true; if (!opened[i]) toggleFlag(i);
                            if (soundOn && navigator.vibrate) navigator.vibrate(18);
                        }, 380);
                    });
                    c.addEventListener('pointerup', function(e){
                        e.stopPropagation(); clearTimeout(lpT);
                        if (over || lpFired || e.button === 2) return;
                        tapCell(i);
                    });
                    c.addEventListener('pointerleave', function(){ clearTimeout(lpT); });
                    c.addEventListener('pointercancel', function(){ clearTimeout(lpT); });
                    c.addEventListener('contextmenu', function(e){ e.preventDefault(); });
                })(i, c);
                grid.appendChild(c); cells.push(c);
            }
            var tools = document.createElement('div'); tools.className = 'sd-tools';
            flagBtn = document.createElement('button'); flagBtn.type = 'button';
            flagBtn.addEventListener('click', function(e){ e.stopPropagation(); flagMode = !flagMode; api.sfx('pop'); syncFlagBtn(); });
            tools.appendChild(flagBtn);
            wrap.appendChild(grid); wrap.appendChild(tools); host.appendChild(wrap);
            syncFlagBtn();
        }
        function init(k){
            lv = k; L = LV[k]; var n = L.c * L.r, i;
            mine = []; adj = []; opened = []; flag = [];
            for (i = 0; i < n; i++){ mine.push(false); adj.push(0); opened.push(false); flag.push(false); }
            started = false; placed = false; over = false; revealed = 0; flags = 0; elapsed = 0; lastSec = -1; boom = -1; flagMode = false;
            dayStr = new Date().toISOString().slice(0, 10);
        }

        return {
            dom:true, W:400, H:400, TW:400, TH:250,
            levels:[{ k:'easy', n:'Easy' }, { k:'medium', n:'Medium' }, { k:'hard', n:'Hard' }, { k:'daily', n:'Daily Challenge' }],
            setLevel:function(k){ init(k); build(); render(); },
            reset:function(){ host.innerHTML = ''; init('easy'); },
            destroy:function(){ clearTimeout(lpT); },
            update:function(dt){
                if (!started || over) return;
                elapsed += dt;
                var s = Math.floor(elapsed);
                if (s !== lastSec){ lastSec = s; hud(); }
            },
            key:function(e){
                if (e.key === 'f' || e.key === 'F'){ flagMode = !flagMode; api.sfx('pop'); syncFlagBtn(); return true; }
                return false;
            },
            thumb:function(cx){
                var W2 = 400, H2 = 250, cs = 34, cols = 9, rows = 6, x0 = (W2 - cols * cs) / 2, y0 = (H2 - rows * cs) / 2;
                var P = ['   1....1', '   1F.211', '  12.31  ', '  1.F2   ', '112321 11', '..F.1 1..'];
                cx.fillStyle = '#070924'; cx.fillRect(0, 0, W2, H2);
                cx.font = '700 17px Poppins, sans-serif'; cx.textAlign = 'center'; cx.textBaseline = 'middle';
                for (var r = 0; r < rows; r++) for (var c = 0; c < cols; c++){
                    var ch = P[r].charAt(c), x = x0 + c * cs, y = y0 + r * cs;
                    cx.fillStyle = (ch === '.' || ch === 'F') ? 'rgba(255,255,255,.16)' : 'rgba(255,255,255,.04)';
                    rrect(cx, x + 1, y + 1, cs - 2, cs - 2, 5); cx.fill();
                    if (ch === 'F'){ cx.fillStyle = '#ff2d2d'; cx.beginPath(); cx.moveTo(x + 12, y + 9); cx.lineTo(x + 25, y + 14); cx.lineTo(x + 12, y + 19); cx.closePath(); cx.fill(); cx.fillRect(x + 11, y + 9, 2, 17); }
                    else if (ch >= '1' && ch <= '8'){ cx.fillStyle = NC[+ch]; cx.fillText(ch, x + cs / 2, y + cs / 2 + 1); }
                }
                cx.textBaseline = 'alphabetic';
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   HILL DASH — pick a car and a terrain, climb as far as you can
══════════════════════════════════════════════════════════════ */
GAMES.hilldash = {
    name:'Hill Dash', ovIcon:'mountain',
    help:'Hold the right side (or → / ↑) to drive and the left side (← / ↓) to brake and reverse. In the air the pedals tilt your car. Grab fuel cans and coins, and keep your head off the ground!',
    make:function(api, ctx){
        var W = 480, H = 320;
        var CARS = {
            jeep:    { n:'Capy Jeep',     P:440, k:55, c:8,   wb:80, rw:16, ly:15, rho:42, drive:2, fuel:1.0,  air:7,  vmax:560, grip:1.0,  headUp:34, col:'#ff2d2d' },
            buggy:   { n:'Dune Buggy',    P:500, k:70, c:9,   wb:86, rw:15, ly:12, rho:36, drive:1, fuel:1.15, air:9,  vmax:640, grip:0.95, headUp:36, col:'#ffb300' },
            monster: { n:'Monster Truck', P:410, k:48, c:6.5, wb:94, rw:26, ly:16, rho:52, drive:2, fuel:1.35, air:5,  vmax:480, grip:1.25, headUp:52, col:'#35d07f' },
            moto:    { n:'Trail Bike',    P:460, k:80, c:10,  wb:56, rw:14, ly:10, rho:30, drive:1, fuel:0.85, air:11, vmax:600, grip:0.9,  headUp:44, col:'#2f9bff' }
        };
        var TERS = {
            country:{ n:'Countryside', g:760, grip:1.0,  w:[1000, 380, 130], a:[150, 55, 9], rampLen:12000, sky:['#74c3ff', '#d6f0ff'], far:'#9fd3a1', mid:'#6fbd6a', top:'#58b74a', under:'#7a4f2a', dust:'#d9c7a0' },
            desert: { n:'Desert',      g:760, grip:0.85, w:[1500, 520, 150], a:[170, 60, 6], rampLen:11000, sky:['#ffb86b', '#ffe9c2'], far:'#f0b36b', mid:'#e39f50', top:'#f2c777', under:'#c98a3a', dust:'#f6d9a0' },
            arctic: { n:'Arctic Ice',  g:760, grip:0.6,  w:[1000, 380, 130], a:[112, 42, 7], rampLen:12000, sky:['#9fcfff', '#eaf5ff'], far:'#cfe4f7', mid:'#b2d0ea', top:'#ffffff', under:'#a8c8e8', dust:'#ffffff' },
            moon:   { n:'Moon',        g:300, grip:2.2, airDamp:1.8,  w:[1300, 450, 150], a:[105, 38, 6], rampLen:12000, sky:['#04041a', '#15153d'], far:'#34365e', mid:'#4a4d7c', top:'#aeb2d8', under:'#6c7098', dust:'#c5c8ea', crater:true, stars:true }
        };
        var carKey = 'jeep', terKey = 'country';
        var C, T, G, DK, seed, px, py, vx, vy, a, w, wh, fuel, coins, maxX, camX, camY, objs, nextCoin, nextCan;
        var pd, parts, fl, shake, stallT, over, sc, hudT, dustT, gas, brk, anyCont, tT;

        /* ── terrain (deterministic value noise, new seed every run) ── */
        function hsh(i, o){
            var n = (Math.imul(i, 374761393) + Math.imul(o, 668265263) + seed) | 0;
            n = Math.imul(n ^ (n >>> 13), 1274126177); n ^= n >>> 16;
            return (n >>> 0) / 4294967295 * 2 - 1;
        }
        function vnoise(x, wl, o){
            var t = x / wl, i = Math.floor(t), f = t - i; f = f * f * (3 - 2 * f);
            return hsh(i, o) * (1 - f) + hsh(i + 1, o) * f;
        }
        function TY(x){
            var ramp = Math.min(1.4, 0.6 + Math.max(0, x) / T.rampLen);
            var b = Math.max(0, Math.min(1, (x - 250) / 500)); b = b * b * (3 - 2 * b);
            var h = (vnoise(x, T.w[0], 0) * T.a[0] + vnoise(x, T.w[1], 1) * T.a[1] + vnoise(x, T.w[2], 2) * T.a[2]) * ramp * b;
            /* launch ramps: a long climb, then a sudden drop, so you get big air */
            var kk0 = Math.floor(x / 1700), kj;
            for (kj = kk0 - 1; kj <= kk0; kj++){
                if (hsh(kj, 5) > 0.05){
                    var sx0 = kj * 1700 + 800 + (hsh(kj, 6) * 0.5 + 0.5) * 500, uu = (x - sx0) / 240;
                    var jh = (45 + (hsh(kj, 9) * 0.5 + 0.5) * 55) * Math.min(1, ramp);
                    if (uu > 0 && uu < 1) h += jh * b * uu;
                    else if (uu >= 1 && uu < 1.3){ var dd = 1 - (uu - 1) / 0.3; h += jh * b * dd * dd * (3 - 2 * dd); }
                }
            }
            if (T.crater){
                var k0 = Math.floor(x / 900), k, dip = 0;
                for (k = k0 - 1; k <= k0 + 1; k++){
                    var cx = k * 900 + 330 + (hsh(k, 7) * 0.5 + 0.5) * 280, dp = 22 + (hsh(k, 8) * 0.5 + 0.5) * 26, q = (x - cx) / 150;
                    dip += dp * Math.exp(-q * q) - dp * 0.25 * Math.exp(-((x - cx - 150) / 60) * ((x - cx - 150) / 60));
                }
                h -= dip * b;
            }
            return -h;
        }

        function init(){
            C = CARS[carKey]; T = TERS[terKey]; G = T.g; DK = C.P / (C.vmax * C.vmax);
            seed = (Math.random() * 1e9) | 0;
            px = 0; vx = 0; vy = 0; a = 0; w = 0;
            py = TY(0) - C.rw - C.ly - 3;
            wh = [{ rot:0, spin:0, pen:0, wx:0, wy:0, rx:0, ry:0 }, { rot:0, spin:0, pen:0, wx:0, wy:0, rx:0, ry:0 }];
            fuel = 100; coins = 0; maxX = 0; objs = []; nextCoin = 520; nextCan = 2600;
            pd = {}; parts = []; fl = []; shake = 0; stallT = 0; over = false; sc = 0; hudT = 0; dustT = 0; gas = false; brk = false; anyCont = false; tT = 0;
            camX = px - W * 0.33; camY = py - H * 0.5;
            spawnAhead();
        }
        function spawnAhead(){
            var lim = px + 1200, j;
            while (nextCoin < lim){
                for (j = 0; j < 5; j++){ var x = nextCoin + j * 40; objs.push({ t:'c', x:x, y:TY(x) - 36 - Math.sin(j / 4 * Math.PI) * 16 }); }
                nextCoin += 450 + Math.random() * 350;
            }
            while (nextCan < lim){
                objs.push({ t:'f', x:nextCan, y:TY(nextCan) - 30 });
                nextCan += 3000 + Math.min(nextCan / 12, 2200) + Math.random() * 600;
            }
        }
        function endSub(){ return Math.floor(maxX / 30) + ' m  ·  ' + coins + ' coins'; }
        function crash(){
            over = true; shake = 0.6;
            var hx = px, hy = py;
            for (var i = 0; i < 26; i++){
                var an = Math.random() * 6.283, v = 60 + Math.random() * 240;
                parts.push({ x:hx, y:hy - 20, vx:Math.cos(an) * v, vy:Math.sin(an) * v - 80, life:0.8, max:0.8, r:2 + Math.random() * 3, col:['#ff2d2d', '#ff9f1a', '#ffd400', '#fff'][rand(4)] });
            }
            api.end({ title:'Head crash!', sub:endSub() });
        }

        /* ── physics: two spring-loaded wheels pushing on a rigid chassis ── */
        function step(h){
            var ca = Math.cos(a), sa = Math.sin(a), spd = Math.sqrt(vx * vx + vy * vy);
            var ax = -vx * spd * DK, ay = G - vy * spd * DK * 0.6, tq = 0, cont = 0, i;
            for (i = 0; i < 2; i++){
                var lx = (i ? 1 : -1) * C.wb / 2, ly = C.ly, rx = lx * ca - ly * sa, ry = lx * sa + ly * ca;
                var wx = px + rx, wy = py + ry, Wd = wh[i];
                Wd.wx = wx; Wd.wy = wy; Wd.rx = rx; Wd.ry = ry; Wd.pen = 0;
                var pen = C.rw - (TY(wx) - wy);
                if (pen > 0){
                    cont++;
                    var s = (TY(wx + 3) - TY(wx - 3)) / 6, nn = 1 / Math.sqrt(1 + s * s), nx = s * nn, ny = -nn, tx = nn, tv = s * nn;
                    var pvx = vx - w * ry, pvy = vy + w * rx, vn = pvx * nx + pvy * ny, vt = pvx * tx + pvy * tv;
                    var Fn = Math.min(2600, Math.max(0, C.k * pen * nn - C.c * vn));
                    var Ft = -vt * 0.2, drv = (C.drive === 2 || i === 0), nd = C.drive === 2 ? 2 : 1;
                    if (fuel > 0 && gas && drv) Ft += C.P / nd;
                    if (brk){
                        if (vt > 40) Ft -= C.P * 0.45;
                        else if (fuel > 0 && drv) Ft -= C.P * 0.55 / nd;
                        else Ft -= vt * 0.5;
                    }
                    var lim = C.grip * T.grip * Fn;
                    if (Ft > lim) Ft = lim; else if (Ft < -lim) Ft = -lim;
                    var Fx = nx * Fn + tx * Ft, Fy = ny * Fn + tv * Ft;
                    ax += Fx; ay += Fy; tq += rx * Fy - ry * Fx;
                    Wd.pen = pen; Wd.spin = vt / C.rw;
                } else {
                    Wd.spin += ((gas && fuel > 0 ? 28 : 0) - (brk ? 28 : 0) - Wd.spin) * Math.min(1, h * 2);
                }
                Wd.rot += Wd.spin * h;
            }
            var alpha = tq / (C.rho * C.rho);
            if (!cont){ alpha += ((brk ? 1 : 0) - (gas ? 1 : 0)) * C.air; w *= (1 - (T.airDamp || 0.3) * h); }
            else w *= (1 - 1.4 * h);
            anyCont = cont > 0;
            vx += ax * h; vy += ay * h; px += vx * h; py += vy * h; w += alpha * h; a += w * h;
            if (!(px === px) || !(py === py) || !(a === a)){ crash(); }
        }
        function headPos(){
            var ca = Math.cos(a), sa = Math.sin(a), lx = -C.wb * 0.05, ly = -C.headUp;
            return { x:px + lx * ca - ly * sa, y:py + lx * sa + ly * ca };
        }

        return {
            W:W, H:H, swipe:false, instant:false, multi:true,
            steps:[
                { title:'Choose your car', sub:'Jeep is balanced, Buggy is fast, Monster Truck grips best, Bike is light and thrifty.',
                  opts:[{ k:'jeep', n:'Capy Jeep' }, { k:'buggy', n:'Dune Buggy' }, { k:'monster', n:'Monster Truck' }, { k:'moto', n:'Trail Bike' }] },
                { title:'Choose your terrain', sub:'Arctic is slippery. The Moon has low gravity and big craters.',
                  opts:[{ k:'country', n:'Countryside' }, { k:'desert', n:'Desert' }, { k:'arctic', n:'Arctic Ice' }, { k:'moon', n:'Moon' }] }
            ],
            setLevel:function(p){ carKey = p[0]; terKey = p[1]; init(); },
            resize:function(asp){
                var nh = Math.round(Math.max(300, Math.min(520, W / asp)));
                if (nh === H) return false;
                H = nh; this.H = H; return true;
            },
            reset:function(){ init(); },
            preview:function(){
                init(); gas = true;
                for (var i = 0; i < 150; i++){ step(1 / 120); if (over) break; }
                camX = px - W * 0.33; camY = py - H * 0.5; maxX = px; fuel = 82; coins = 7;
            },
            begin:function(){ api.extra('Fuel 100%'); },
            angle:function(){ return a; }, grounded:function(){ return anyCont; },
            dir:function(){}, tap:function(){},
            ptr:function(ty, x, y, id){
                if (ty === 'up') delete pd[id]; else pd[id] = x < W / 2 ? 'b' : 'g';
            },
            update:function(dt){
                if (over) return;
                var Hh = api.held, pdG = false, pdB = false, k, i;
                for (k in pd){ if (pd[k] === 'g') pdG = true; else pdB = true; }
                gas = pdG || Hh.r || Hh.u; brk = pdB || Hh.l || Hh.d;
                tT += dt;
                var sub = Math.ceil(dt / 0.0055), hs = dt / sub;
                for (i = 0; i < sub; i++){ step(hs); if (over) return; }
                var hp = headPos();
                if (hp.y > TY(hp.x) - 4){ crash(); return; }

                if (px > maxX) maxX = px;
                // fuel
                if (fuel > 0){ fuel = Math.max(0, fuel - (0.9 + (gas ? 2.2 : 0)) * C.fuel * dt); stallT = 0; }
                else {
                    if (Math.abs(vx) < 14) stallT += dt; else stallT = 0;
                    if (stallT > 1.2){ over = true; api.end({ title:'Out of fuel', sub:endSub() }); return; }
                }
                // pickups
                for (i = 0; i < objs.length; i++){
                    var o = objs[i]; if (o.got) continue;
                    var dx = o.x - px, dy = o.y - py;
                    if (dx * dx + dy * dy < 1444){
                        o.got = true;
                        if (o.t === 'c'){ coins++; api.sfx('pop'); fl.push({ x:o.x, y:o.y, t:0.7, txt:'+5' }); }
                        else { fuel = Math.min(100, fuel + 35); api.sfx('level'); fl.push({ x:o.x, y:o.y - 10, t:1, txt:'FUEL +35' }); }
                    }
                }
                if (objs.length > 60) objs = objs.filter(function(q){ return !q.got && q.x > camX - 150; });
                spawnAhead();
                // score + hud
                var s2 = Math.floor(maxX / 30) + coins * 5;
                if (s2 !== sc){ sc = s2; api.setScore(sc); }
                hudT -= dt; if (hudT <= 0){ hudT = 0.2; api.extra('Fuel ' + Math.round(fuel) + '%'); }
                // dust
                dustT -= dt;
                if (dustT <= 0 && Math.abs(vx) > 60){
                    dustT = 0.05;
                    for (i = 0; i < 2; i++) if (wh[i].pen > 0){
                        parts.push({ x:wh[i].wx, y:wh[i].wy + C.rw - wh[i].pen, vx:-vx * 0.2 + (Math.random() - 0.5) * 40, vy:-20 - Math.random() * 50, life:0.5, max:0.5, r:2 + Math.random() * 2.5, col:T.dust });
                    }
                }
                for (i = parts.length - 1; i >= 0; i--){ var p = parts[i]; p.x += p.vx * dt; p.y += p.vy * dt; p.life -= dt; if (p.life <= 0) parts.splice(i, 1); }
                for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 34 * dt; fl[i].t -= dt; if (fl[i].t <= 0) fl.splice(i, 1); }
                // camera
                var tx = px - W * 0.33 + vx * 0.12, ty = py - H * 0.5;
                camX += (tx - camX) * Math.min(1, dt * 5); camY += (ty - camY) * Math.min(1, dt * 3.5);
            },
            draw:function(t, state){
                var i, x, sx;
                ctx.save();
                if (shake > 0){ shake = Math.max(0, shake - 0.02); ctx.translate((Math.random() - 0.5) * shake * 14, (Math.random() - 0.5) * shake * 14); }
                // sky
                var sg = ctx.createLinearGradient(0, 0, 0, H);
                sg.addColorStop(0, T.sky[0]); sg.addColorStop(1, T.sky[1]);
                ctx.fillStyle = sg; ctx.fillRect(-20, -20, W + 40, H + 40);
                if (T.stars){
                    ctx.fillStyle = 'rgba(255,255,255,.8)';
                    for (i = 0; i < 40; i++) ctx.fillRect(((i * 97 - camX * 0.03) % W + W) % W, (i * 53) % (H * 0.6), 1.5, 1.5);
                    ctx.fillStyle = '#cfd2f5'; ctx.beginPath(); ctx.arc(W * 0.78, H * 0.2, 26, 0, 7); ctx.fill();
                    ctx.fillStyle = '#15153d'; ctx.beginPath(); ctx.arc(W * 0.78 + 9, H * 0.2 - 4, 22, 0, 7); ctx.fill();
                } else {
                    ctx.fillStyle = 'rgba(255,255,255,.75)'; ctx.beginPath(); ctx.arc(W * 0.8, H * 0.18, 24, 0, 7); ctx.fill();
                    ctx.fillStyle = 'rgba(255,255,255,.55)';
                    for (i = 0; i < 4; i++){ var cx = ((i * 190 - camX * 0.06) % (W + 160) + W + 160) % (W + 160) - 80, cy = 40 + (i * 37) % 70; rrect(ctx, cx, cy, 70, 18, 9); ctx.fill(); rrect(ctx, cx + 14, cy - 9, 38, 16, 8); ctx.fill(); }
                }
                // parallax hills
                var L = [{ p:0.15, col:T.far, amp:70, wl:430, base:H * 0.62 }, { p:0.35, col:T.mid, amp:50, wl:270, base:H * 0.74 }];
                for (var l = 0; l < 2; l++){
                    var Q = L[l]; ctx.fillStyle = Q.col; ctx.beginPath(); ctx.moveTo(-10, H + 10);
                    for (sx = -10; sx <= W + 10; sx += 12){
                        var u = camX * Q.p + sx;
                        ctx.lineTo(sx, Q.base - camY * 0.05 + Math.sin(u / Q.wl) * Q.amp * 0.5 + Math.sin(u / (Q.wl * 0.43) + 1.7) * Q.amp * 0.25);
                    }
                    ctx.lineTo(W + 10, H + 10); ctx.closePath(); ctx.fill();
                }
                // ground
                ctx.beginPath(); ctx.moveTo(-10, H + 80);
                for (sx = -10; sx <= W + 10; sx += 8) ctx.lineTo(sx, TY(camX + sx) - camY);
                ctx.lineTo(W + 10, H + 80); ctx.closePath();
                ctx.fillStyle = T.under; ctx.fill();
                ctx.beginPath();
                for (sx = -10; sx <= W + 10; sx += 8){ var gy = TY(camX + sx) - camY; if (sx === -10) ctx.moveTo(sx, gy); else ctx.lineTo(sx, gy); }
                ctx.lineJoin = 'round'; ctx.strokeStyle = T.top; ctx.lineWidth = 12; ctx.stroke();
                ctx.strokeStyle = 'rgba(0,0,0,.12)'; ctx.lineWidth = 2; ctx.stroke();
                // pickups
                for (i = 0; i < objs.length; i++){
                    var o = objs[i]; if (o.got) continue;
                    var ox = o.x - camX, oy = o.y - camY; if (ox < -30 || ox > W + 30) continue;
                    if (o.t === 'c'){
                        var sq = Math.abs(Math.sin(t * 5 + o.x * 0.05));
                        ctx.fillStyle = '#ffcf1f'; ctx.beginPath(); ctx.ellipse(ox, oy, 4 + 6 * sq, 10, 0, 0, 7); ctx.fill();
                        ctx.strokeStyle = '#b88400'; ctx.lineWidth = 2; ctx.stroke();
                    } else {
                        ctx.fillStyle = '#ff2d2d'; rrect(ctx, ox - 11, oy - 13, 22, 26, 4); ctx.fill();
                        ctx.fillStyle = '#fff'; ctx.fillRect(ox - 4, oy - 17, 8, 5);
                        ctx.font = '700 14px Poppins, sans-serif'; ctx.textAlign = 'center'; ctx.fillText('F', ox, oy + 6);
                    }
                }
                // dust
                for (i = 0; i < parts.length; i++){
                    var p = parts[i]; ctx.globalAlpha = Math.max(0, p.life / p.max) * 0.8; ctx.fillStyle = p.col;
                    ctx.beginPath(); ctx.arc(p.x - camX, p.y - camY, p.r, 0, 7); ctx.fill();
                }
                ctx.globalAlpha = 1;
                // car
                var cxs = px - camX, cys = py - camY, hlx = -C.wb * 0.05, hly = -C.headUp;
                ctx.save(); ctx.translate(cxs, cys); ctx.rotate(a);
                ctx.lineJoin = 'round'; ctx.lineCap = 'round';
                var wb = C.wb, col = C.col;
                if (carKey === 'jeep'){
                    ctx.fillStyle = col; rrect(ctx, -wb / 2 - 16, -14, wb + 32, 26, 7); ctx.fill();
                    ctx.fillStyle = 'rgba(0,0,0,.25)'; ctx.fillRect(-wb / 2 - 12, 4, wb + 24, 4);
                    ctx.strokeStyle = '#222'; ctx.lineWidth = 3; ctx.beginPath(); ctx.moveTo(8, -14); ctx.lineTo(20, -30); ctx.lineTo(28, -30); ctx.lineTo(32, -14); ctx.stroke();
                    ctx.fillStyle = 'rgba(190,230,255,.7)'; ctx.beginPath(); ctx.moveTo(10, -14); ctx.lineTo(21, -28); ctx.lineTo(27, -28); ctx.lineTo(30, -14); ctx.closePath(); ctx.fill();
                    ctx.fillStyle = '#fff6a8'; ctx.fillRect(wb / 2 + 8, -8, 6, 6);
                } else if (carKey === 'buggy'){
                    ctx.fillStyle = col; rrect(ctx, -wb / 2 - 10, -8, wb + 20, 18, 8); ctx.fill();
                    ctx.strokeStyle = '#2a2a2a'; ctx.lineWidth = 3.5; ctx.beginPath();
                    ctx.moveTo(-22, -8); ctx.lineTo(-18, -34); ctx.lineTo(16, -34); ctx.lineTo(26, -8); ctx.moveTo(-4, -34); ctx.lineTo(-4, -8); ctx.stroke();
                    ctx.fillStyle = '#222'; rrect(ctx, -wb / 2 - 14, -26, 18, 5, 2); ctx.fill(); ctx.fillRect(-wb / 2 - 8, -22, 3, 14);
                    ctx.fillStyle = '#fff6a8'; ctx.fillRect(wb / 2 + 5, -4, 6, 5);
                } else if (carKey === 'monster'){
                    ctx.fillStyle = col; rrect(ctx, -wb / 2 - 12, -26, wb + 24, 30, 9); ctx.fill();
                    ctx.fillStyle = 'rgba(0,0,0,.22)'; ctx.fillRect(-wb / 2 - 8, -2, wb + 16, 4);
                    ctx.fillStyle = col; rrect(ctx, -6, -48, 42, 24, 8); ctx.fill();
                    ctx.fillStyle = 'rgba(190,230,255,.75)'; rrect(ctx, 8, -45, 24, 15, 4); ctx.fill();
                    ctx.fillStyle = '#fff6a8'; ctx.fillRect(wb / 2 + 6, -18, 6, 7);
                } else {
                    ctx.strokeStyle = '#1b1d44'; ctx.lineWidth = 5; ctx.beginPath();
                    ctx.moveTo(-wb / 2, C.ly); ctx.lineTo(-10, -4); ctx.lineTo(10, -4); ctx.lineTo(wb / 2, C.ly);
                    ctx.moveTo(10, -4); ctx.lineTo(wb / 2 - 6, -20); ctx.stroke();
                    ctx.fillStyle = col; rrect(ctx, -6, -16, 26, 12, 5); ctx.fill();
                    ctx.fillStyle = '#111'; rrect(ctx, -22, -9, 18, 6, 3); ctx.fill();
                    ctx.strokeStyle = '#111'; ctx.lineWidth = 3; ctx.beginPath(); ctx.moveTo(wb / 2 - 10, -22); ctx.lineTo(wb / 2 - 2, -24); ctx.stroke();
                }
                // driver (a capybara!)
                if (carKey === 'moto' || carKey === 'buggy'){
                    ctx.strokeStyle = '#3a2410'; ctx.lineWidth = 6; ctx.beginPath(); ctx.moveTo(hlx, hly + 8); ctx.lineTo(hlx - 4, -12); ctx.stroke();
                }
                ctx.fillStyle = '#c97b34'; ctx.beginPath(); ctx.ellipse(hlx, hly, 11, 9.5, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#a5642c'; ctx.beginPath(); ctx.arc(hlx - 6, hly - 8, 3.4, 0, 7); ctx.fill();
                ctx.fillStyle = '#4a2208'; ctx.beginPath(); ctx.ellipse(hlx + 9, hly + 1.5, 3.6, 2.8, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#111'; ctx.beginPath(); ctx.arc(hlx + 3, hly - 2, 1.7, 0, 7); ctx.fill();
                ctx.fillStyle = '#fff'; ctx.fillRect(hlx - 12, hly - 12, 24, 3);   // headband
                ctx.restore();
                // wheels + suspension (drawn over the body)
                for (i = 0; i < 2; i++){
                    var Wd = wh[i], wsx = Wd.wx - camX, wsy = Wd.wy - camY - Math.max(0, Wd.pen);
                    var msx = cxs + Wd.rx, msy = cys + Wd.ry;
                    ctx.strokeStyle = '#8a8fb5'; ctx.lineWidth = 4; ctx.beginPath(); ctx.moveTo(msx, msy); ctx.lineTo(wsx, wsy); ctx.stroke();
                    ctx.fillStyle = '#14152b'; ctx.beginPath(); ctx.arc(wsx, wsy, C.rw, 0, 7); ctx.fill();
                    if (carKey === 'monster'){
                        ctx.fillStyle = '#2a2d55';
                        for (var q = 0; q < 10; q++){ var ang = Wd.rot + q * 0.6283; ctx.beginPath(); ctx.arc(wsx + Math.cos(ang) * (C.rw - 3.5), wsy + Math.sin(ang) * (C.rw - 3.5), 2.4, 0, 7); ctx.fill(); }
                    }
                    ctx.fillStyle = '#d7daf5'; ctx.beginPath(); ctx.arc(wsx, wsy, C.rw * 0.6, 0, 7); ctx.fill();
                    ctx.strokeStyle = '#6b6f99'; ctx.lineWidth = 2; ctx.beginPath();
                    for (q = 0; q < 3; q++){ var an2 = Wd.rot + q * 2.0944; ctx.moveTo(wsx, wsy); ctx.lineTo(wsx + Math.cos(an2) * C.rw * 0.58, wsy + Math.sin(an2) * C.rw * 0.58); }
                    ctx.stroke();
                    ctx.fillStyle = col; ctx.beginPath(); ctx.arc(wsx, wsy, C.rw * 0.18, 0, 7); ctx.fill();
                }
                // floating texts
                ctx.textAlign = 'center'; ctx.font = '700 15px Poppins, sans-serif';
                for (i = 0; i < fl.length; i++){
                    ctx.globalAlpha = Math.min(1, fl[i].t * 1.8);
                    ctx.fillStyle = '#000'; ctx.fillText(fl[i].txt, fl[i].x - camX + 1, fl[i].y - camY + 1);
                    ctx.fillStyle = '#fff'; ctx.fillText(fl[i].txt, fl[i].x - camX, fl[i].y - camY);
                }
                ctx.globalAlpha = 1;
                ctx.restore();
                // HUD
                ctx.fillStyle = 'rgba(0,0,0,.35)'; rrect(ctx, 12, 12, 112, 14, 7); ctx.fill();
                var fcol = (fuel < 25 && Math.floor(t * 4) % 2) ? '#ffffff' : (fuel < 25 ? '#ff2d2d' : '#ffd400');
                ctx.fillStyle = fcol; rrect(ctx, 12, 12, Math.max(6, 112 * fuel / 100), 14, 7); ctx.fill();
                ctx.fillStyle = '#000'; ctx.font = '700 9px Poppins, sans-serif'; ctx.textAlign = 'left'; ctx.fillText('FUEL', 18, 22);
                ctx.textAlign = 'center'; ctx.font = '700 22px Poppins, sans-serif';
                ctx.fillStyle = 'rgba(0,0,0,.45)'; ctx.fillText(Math.floor(maxX / 30) + ' m', W / 2 + 1, 33);
                ctx.fillStyle = '#fff'; ctx.fillText(Math.floor(maxX / 30) + ' m', W / 2, 32);
                ctx.textAlign = 'right'; ctx.font = '700 14px Poppins, sans-serif'; ctx.fillStyle = '#ffcf1f'; ctx.fillText('● ' + coins, W - 14, 26);
                // pedals
                var pdG = false, pdB = false, k2;
                for (k2 in pd){ if (pd[k2] === 'g') pdG = true; else pdB = true; }
                var Hh = api.held; pdG = pdG || Hh.r || Hh.u; pdB = pdB || Hh.l || Hh.d;
                ctx.font = '700 11px Poppins, sans-serif'; ctx.textAlign = 'center';
                ctx.fillStyle = pdB ? 'rgba(255,255,255,.4)' : 'rgba(255,255,255,.14)'; rrect(ctx, 14, H - 70, 84, 56, 14); ctx.fill();
                ctx.fillStyle = pdG ? 'rgba(255,45,45,.7)' : 'rgba(255,45,45,.3)'; rrect(ctx, W - 98, H - 70, 84, 56, 14); ctx.fill();
                ctx.fillStyle = 'rgba(255,255,255,.9)'; ctx.fillText('BRAKE', 56, H - 38); ctx.fillText('GAS', W - 56, H - 38);
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.14)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   CAPY WORLD — an endless wildlife open world seen from above
   (biomes, animals to befriend, villages you can walk into, day/night and weather)
══════════════════════════════════════════════════════════════ */
GAMES.capyworld = {
    name:'Capy World', ovIcon:'compass',
    help:'Drag anywhere to move (slow = sneak up on animals). FEED tames wildlife, DASH stuns predators. Walk up into a house to enter it. Swim, climb, explore forever, deliver parcels and survive the night!',
    make:function(api, ctx){
        var W = 420, H = 560, TS = 32, CH = 16, RG = 20, OFF = 1048576, MUL = 2097152;
        var seed = 0, chunks = {}, vc = {}, taken = {}, seen = {}, visited = {}, disc = {}, journal = {}, jc = 0;
        var px, py, face, hp, inv, dashT, dashCd, dashX, dashY, walkT, sc, gt, fl, parts, quest, msg, oranges, boots, lantern, pets, zi, enterCd, healT, over;
        var animals, flies, drops, rainI, rainT, rainTo, flash, inside, btns, quiet, swimming;
        var joyId, jo, jv, dashId, mm = null, mmx = null, mmT = 0, hudT = 0, evT = 0, spawnT = 0, nVis = 0;
        var ZS = [0.7, 1, 1.3];
        var DX = W - 56, DY = H - 70, DR = 32, FX = W - 56, FY = H - 138, FR = 26;
        var TC = ['#1f55a8', '#3f8ee0', '#ecdca0', '#62b954', '#459a49', '#7f8499', '#eaf3ff', '#4f9448', '#c9a66b', '#8a7a62', '#62b954', '#62b954', '#e4c07a', '#f2f8ff', '#c9a66b'];
        var GR = ['#62b954', '#5fb352', '#66bb58', '#5cae50'];
        var NP = ['Pond', 'Green', 'Mossy', 'Maple', 'Sun', 'River', 'Stone', 'Fern', 'Amber', 'Cedar'];
        var NS = ['vale', 'brook', 'ton', 'field', 'hollow', 'wick', 'haven', 'mere'];
        var PN = ['Pip', 'Moss', 'Juniper', 'Tansy', 'Olive', 'Bramble', 'Clover', 'Hazel', 'Nutmeg', 'Poppy'];
        var HOUSES = [[-4, -3], [2, -4], [-3, 2], [3, 1]];
        var ROOF = ['#d9483b', '#3b7dd9', '#e0a02b', '#8a54c9'];
        var KIND = ['General Store', 'Cozy Inn', 'Family Home', 'Village Elder'];
        var ROLE = ['shopkeeper', 'innkeeper', 'host', 'elder'];
        /* species: k = p prey, n neutral, f flyer, w water, x predator, v villager */
        var SPD = {
            rabbit:  { n:'Rabbit',  k:'p', sp:100, fl:130, sz:6,  b:[3, 4, 7, 2], shy:2, w:3 },
            deer:    { n:'Deer',    k:'p', sp:125, fl:170, sz:11, b:[3, 4, 7],    shy:3, w:2 },
            capybara:{ n:'Capybara',k:'n', sp:55,  fl:0,   sz:10, b:[3, 2, 1],    shy:1, w:3, sw:1 },
            fox:     { n:'Fox',     k:'p', sp:115, fl:140, sz:8,  b:[3, 4, 13],   shy:3, w:1.5 },
            turtle:  { n:'Turtle',  k:'n', sp:22,  fl:0,   sz:7,  b:[2, 1],       shy:1, w:2, sw:1 },
            frog:    { n:'Frog',    k:'p', sp:80,  fl:70,  sz:5,  b:[1, 2, 3],    shy:2, w:2, sw:1 },
            bird:    { n:'Bird',    k:'f', sp:90,  fl:110, sz:6,  b:[3, 4, 7, 2, 12], shy:2, w:3 },
            lizard:  { n:'Lizard',  k:'p', sp:110, fl:100, sz:6,  b:[12],         shy:2, w:3 },
            penguin: { n:'Penguin', k:'n', sp:45,  fl:0,   sz:8,  b:[13, 2],      shy:2, w:3, sw:1 },
            fish:    { n:'Fish',    k:'w', sp:60,  fl:0,   sz:6,  b:[0, 1],       shy:99, w:4 },
            wolf:    { n:'Wolf',    k:'x', sp:108, fl:0,   sz:11, b:[4, 13, 3, 7], shy:99, w:2, dmg:1, sight:230 },
            bear:    { n:'Bear',    k:'x', sp:64,  fl:0,   sz:15, b:[4, 7],       shy:99, w:0.8, dmg:2, sight:170 },
            bug:     { n:'Thorn bug', k:'x', sp:72, fl:0,  sz:10, b:[3, 4, 7, 12, 13], shy:99, w:2, dmg:1, sight:200 },
            villager:{ n:'Villager',k:'v', sp:34,  fl:0,   sz:10, b:[11],         shy:99, w:0 }
        };
        var SPLIST = ['rabbit', 'deer', 'capybara', 'fox', 'turtle', 'frog', 'bird', 'lizard', 'penguin', 'fish', 'wolf', 'bear', 'bug'];
        function KC(cx, cy){ return (cx + OFF) * MUL + (cy + OFF); }

        /* ── noise + terrain ── */
        function hh(a, b, s){
            var n = (Math.imul(a, 374761393) + Math.imul(b, 668265263) + Math.imul(s, 2147483647) + seed) | 0;
            n = Math.imul(n ^ (n >>> 13), 1274126177); n ^= n >>> 16;
            return (n >>> 0) / 4294967295;
        }
        function vn(x, y, s){
            var ix = Math.floor(x), iy = Math.floor(y), fx = x - ix, fy = y - iy;
            fx = fx * fx * (3 - 2 * fx); fy = fy * fy * (3 - 2 * fy);
            var a = hh(ix, iy, s), b = hh(ix + 1, iy, s), c = hh(ix, iy + 1, s), d = hh(ix + 1, iy + 1, s);
            var top = a + (b - a) * fx;
            return top + ((c + (d - c) * fx) - top) * fy;
        }
        function elev(tx, ty){ return (vn(tx * 0.035, ty * 0.035, 1) * 0.6 + vn(tx * 0.08, ty * 0.08, 2) * 0.28 + vn(tx * 0.2, ty * 0.2, 3) * 0.12) * 2 - 1; }
        function rawType(tx, ty){
            var e = elev(tx, ty);
            if (e < -0.4) return 0; if (e < -0.28) return 1; if (e < -0.2) return 2;
            if (e > 0.62) return 6; if (e > 0.45) return 5; if (e > 0.3) return 7;
            var m = vn(tx * 0.05 + 50, ty * 0.05 + 50, 4) * 2 - 1, tp = vn(tx * 0.013 + 200, ty * 0.013 + 200, 5) * 2 - 1;
            if (tp > 0.26 && m < 0.1) return 12;
            if (tp < -0.28) return 13;
            return m > 0.12 ? 4 : 3;
        }
        function landOK(tx, ty){ var t = rawType(tx, ty); return t === 2 || t === 3 || t === 4 || t === 7 || t === 12 || t === 13; }
        function vname(rx, ry){ return NP[Math.floor(hh(rx, ry, 41) * NP.length)] + NS[Math.floor(hh(rx, ry, 42) * NS.length)]; }
        function villageSite(rx, ry){
            var k = rx + ',' + ry; if (k in vc) return vc[k];
            var s = null;
            if (hh(rx, ry, 31) > 0.42){
                var cx = rx * RG + 8 + Math.floor(hh(rx, ry, 32) * 5), cy = ry * RG + 8 + Math.floor(hh(rx, ry, 33) * 5);
                var ok = (landOK(cx + 5, cy) ? 1 : 0) + (landOK(cx - 5, cy) ? 1 : 0) + (landOK(cx, cy + 5) ? 1 : 0) + (landOK(cx, cy - 5) ? 1 : 0);
                if (landOK(cx, cy) && ok >= 3) s = { x:cx, y:cy, rx:rx, ry:ry, name:vname(rx, ry) };
            }
            vc[k] = s; return s;
        }
        function vInfo(tx, ty){
            var s = villageSite(Math.floor(tx / RG), Math.floor(ty / RG)); if (!s) return null;
            var dx = tx - s.x, dy = ty - s.y, d2 = dx * dx + dy * dy;
            if (d2 > 36) return null;
            for (var i = 0; i < 4; i++){
                var hx = HOUSES[i][0], hy = HOUSES[i][1];
                if (dx >= hx && dx < hx + 2 && dy >= hy && dy < hy + 2) return { k:'house', i:i, bl:(dx === hx && dy === hy + 1), s:s };
            }
            if (dx === 0 && dy === 0) return { k:'well', s:s };
            if ((dx === 0 || dy === 0) && d2 <= 25) return { k:'path', s:s, lamp:(d2 === 16) };
            return { k:'ground', s:s };
        }
        function genChunk(cx, cy){
            var a = new Uint8Array(CH * CH), x, y;
            for (y = 0; y < CH; y++) for (x = 0; x < CH; x++){
                var tx = cx * CH + x, ty = cy * CH + y, v = vInfo(tx, ty), t;
                if (v) t = v.k === 'house' ? 9 : v.k === 'well' ? 10 : v.k === 'path' ? 8 : 11;
                else t = rawType(tx, ty);
                a[y * CH + x] = t;
            }
            return { a:a, cx:cx, cy:cy };
        }
        function tileAt(tx, ty){
            var cx = Math.floor(tx / CH), cy = Math.floor(ty / CH), k = KC(cx, cy), c = chunks[k];
            if (!c){ c = chunks[k] = genChunk(cx, cy); }
            return c.a[(ty - cy * CH) * CH + (tx - cx * CH)];
        }
        /* what stands on a tile: 1 oak, 2 flowers, 3 rock, 4 bush, 5 pine, 6 cactus, 7 mushrooms, 8 orange, 9 chest, 10 shrine, 11 palm, 12 dry tuft */
        function objAt(tx, ty, t){
            var h = hh(tx, ty, 77);
            if (t === 3){
                if (h < 0.04) return 1; if (h < 0.075) return 2; if (h < 0.088) return 3; if (h < 0.1) return 4;
                if (h > 0.9915 && h < 0.9985) return 8; if (h >= 0.9985 && h < 0.99935) return 9; if (h >= 0.99935) return 10;
            } else if (t === 4){
                if (h < 0.38) return 1; if (h < 0.48) return 4; if (h < 0.53) return 7; if (h > 0.99 && h < 0.9985) return 8; if (h >= 0.9985 && h < 0.9993) return 9; if (h >= 0.9996) return 10;
            } else if (t === 7){ if (h < 0.05) return 1; if (h < 0.075) return 3; if (h > 0.994 && h < 0.9985) return 8; if (h >= 0.9985) return 9; }
            else if (t === 2){ if (h < 0.025) return 11; if (h < 0.045) return 3; }
            else if (t === 12){ if (h < 0.03) return 6; if (h < 0.05) return 3; if (h < 0.11) return 12; if (h > 0.9955 && h < 0.9985) return 8; if (h >= 0.9985) return 9; }
            else if (t === 13){ if (h < 0.06) return 5; if (h < 0.08) return 3; if (h > 0.996 && h < 0.9985) return 8; if (h >= 0.9985 && h < 0.9993) return 9; if (h >= 0.9996) return 10; }
            else if (t === 5){ if (h < 0.06) return 3; }
            return 0;
        }
        function solidTile(tx, ty){
            var t = tileAt(tx, ty);
            if (t === 9) return true;
            if (t === 0 || t === 1 || t === 6 || t === 8 || t === 10 || t === 11 || t === 14) return false;
            var c = objAt(tx, ty, t);
            return c === 1 || c === 3 || c === 5 || c === 6 || c === 11;
        }
        function speedMul(t){ return t === 0 ? 0.62 : t === 1 ? 0.78 : t === 5 ? 0.62 : t === 6 ? 0.5 : t === 7 ? 0.92 : t === 13 ? 0.88 : t === 12 ? 0.94 : t === 4 ? 0.92 : 1; }
        function blocked(x, y, r){
            var hx = r * 0.8, hy = r * 0.6;
            return solidTile(Math.floor((x - hx) / TS), Math.floor((y - hy) / TS)) || solidTile(Math.floor((x + hx) / TS), Math.floor((y - hy) / TS)) ||
                   solidTile(Math.floor((x - hx) / TS), Math.floor((y + hy) / TS)) || solidTile(Math.floor((x + hx) / TS), Math.floor((y + hy) / TS));
        }
        function inVillage(x, y){ return !!vInfo(Math.floor(x / TS), Math.floor(y / TS)); }
        function moveEnt(e, dx, dy, r, fn){
            if (!fn(e.x + dx, e.y, r)) e.x += dx;
            if (!fn(e.x, e.y + dy, r)) e.y += dy;
        }
        function tAt(x, y){ return tileAt(Math.floor(x / TS), Math.floor(y / TS)); }

        /* ── world set-up ── */
        function newWorld(){
            seed = (Math.random() * 1e9) | 0; chunks = {}; vc = {}; taken = {}; seen = {}; visited = {}; disc = {}; journal = {}; jc = 0;
            var r, dx, dy, found = false;
            px = 16; py = 16;
            for (r = 0; r < 60 && !found; r++){
                for (dx = -r; dx <= r && !found; dx++) for (dy = -r; dy <= r && !found; dy++){
                    if (Math.max(Math.abs(dx), Math.abs(dy)) !== r) continue;
                    var t0 = tileAt(dx, dy); if (t0 !== 3 && t0 !== 11) continue;
                    var good = true;
                    for (var i = -1; i <= 1 && good; i++) for (var j = -1; j <= 1; j++) if (solidTile(dx + i, dy + j)){ good = false; break; }
                    if (good){ px = dx * TS + TS / 2; py = dy * TS + TS / 2; found = true; }
                }
            }
        }
        function findVillages(r0, r1){
            var rx0 = Math.floor(px / TS / RG), ry0 = Math.floor(py / TS / RG), out = [], a, b;
            for (a = -r1; a <= r1; a++) for (b = -r1; b <= r1; b++){
                if (Math.max(Math.abs(a), Math.abs(b)) < r0) continue;
                var s = villageSite(rx0 + a, ry0 + b); if (s) out.push(s);
            }
            return out;
        }
        function pickQuest(avoid){
            var list = [], r = 2;
            while (!list.length && r <= 10){
                list = findVillages(r === 2 ? 2 : r, r + 2).filter(function(s){ return !avoid || s.name !== avoid; });
                r += 2;
            }
            if (!list.length) return null;
            var s = list[rand(list.length)];
            return { x:(s.x + 0.5) * TS, y:(s.y + 0.5) * TS, name:s.name };
        }
        function pickFirstQuest(){
            var best = null, bd = 1e12, list = findVillages(0, 6);
            list.forEach(function(s){ var d = Math.hypot((s.x + 0.5) * TS - px, (s.y + 0.5) * TS - py); if (d > 220 && d < bd){ bd = d; best = s; } });
            return best ? { x:(best.x + 0.5) * TS, y:(best.y + 0.5) * TS, name:best.name } : pickQuest(null);
        }
        function add(n, txt, x, y){
            sc += n; api.setScore(sc);
            if (txt) fl.push({ x:x === undefined ? px : x, y:y === undefined ? py - 30 : y, t:1.1, txt:txt });
        }
        function toast(t){ msg = { txt:t, t:2.8 }; }
        function burst(x, y, cols, n){
            for (var i = 0; i < n; i++){
                var a = Math.random() * 6.283, v = 40 + Math.random() * 120;
                parts.push({ x:x, y:y, vx:Math.cos(a) * v, vy:Math.sin(a) * v - 30, life:0.6, max:0.6, col:cols[rand(cols.length)] });
            }
        }
        function dash(){
            if (dashCd > 0 || dashT > 0 || over || inside) return;
            var vx = jv.x + ((api.held.r ? 1 : 0) - (api.held.l ? 1 : 0)), vy = jv.y + ((api.held.d ? 1 : 0) - (api.held.u ? 1 : 0)), m = Math.hypot(vx, vy);
            if (m > 0.2){ dashX = vx / m; dashY = vy / m; } else { dashX = Math.cos(face); dashY = Math.sin(face); }
            dashT = 0.24; dashCd = 1.0; api.sfx('whoosh');
        }
        function hurt(fromX, fromY, dmg){
            if (inv > 0 || dashT > 0 || over) return;
            hp -= dmg || 1; inv = 1.4; healT = 0; api.sfx('wrong');
            var a = Math.atan2(py - fromY, px - fromX), pe = { x:px, y:py };
            moveEnt(pe, Math.cos(a) * 20, Math.sin(a) * 20, 9, blocked); px = pe.x; py = pe.y;
            if (hp <= 0){ over = true; api.end({ title:'Knocked out!', sub:'You explored ' + nVis + ' areas, met ' + jc + ' species and found ' + Object.keys(disc).length + ' villages.' }); }
        }
        function feed(){
            if (over || inside) return;
            var best = null, bd = 80, i;
            for (i = 0; i < animals.length; i++){
                var a = animals[i], S = SPD[a.sp];
                if (S.k === 'x' || S.k === 'v' || S.shy > 50 || a.tamed) continue;
                var d = Math.hypot(a.x - px, a.y - py); if (d < bd){ bd = d; best = a; }
            }
            if (!best){ toast('No animal close enough to feed'); return; }
            if (oranges < 1){ toast('You need an orange to feed animals'); return; }
            oranges--; best.trust++; api.sfx('pop');
            burst(best.x, best.y - 8, ['#ff6b8a', '#ffd6e0'], 6);
            fl.push({ x:best.x, y:best.y - 18, t:0.9, txt:'♥' });
            var S2 = SPD[best.sp];
            if (best.trust >= S2.shy){
                if (pets < 3){ best.tamed = true; best.slot = pets; pets++; add(15, S2.n + ' joined you! +15', best.x, best.y - 24); api.sfx('level'); toast('A ' + S2.n.toLowerCase() + ' is now your friend!'); }
                else { best.trust = 0; add(5, 'Happy! +5', best.x, best.y - 24); }
            }
        }

        /* ── animals ── */
        function spawnAnimal(dark){
            for (var tries = 0; tries < 10; tries++){
                var a = Math.random() * 6.283, d = 240 + Math.random() * 200, x = px + Math.cos(a) * d, y = py + Math.sin(a) * d, t = tAt(x, y);
                if (t === 8 || t === 9 || t === 10 || t === 11 || t === 14) continue;
                var pool = [], tot = 0, k;
                for (k in SPD){
                    var S = SPD[k]; if (S.w <= 0 || S.b.indexOf(t) < 0) continue;
                    var w = S.w;
                    if (k === 'wolf') w *= dark > 0.3 ? 2.2 : 0.4;
                    if (k === 'bug') w *= dark > 0.2 ? 2.5 : 0.1;
                    if (k === 'bear') w *= dark > 0.5 ? 0.5 : 1;
                    if (k === 'bird' && dark > 0.5) w *= 0.3;
                    if (S.k === 'x' && inVillage(x, y)) continue;
                    pool.push([k, w]); tot += w;
                }
                if (!pool.length) continue;
                var r = Math.random() * tot, pick = pool[0][0];
                for (var i = 0; i < pool.length; i++){ r -= pool[i][1]; if (r <= 0){ pick = pool[i][0]; break; } }
                var Sp = SPD[pick];
                if (Sp.k !== 'f' && Sp.k !== 'w' && blocked(x, y, Sp.sz)) continue;
                animals.push({ sp:pick, x:x, y:y, wt:0, dx:0, dy:0, face:Math.random() * 6.283, trust:0, tamed:false, slot:0, stun:0, kx:0, ky:0, hit:false, cd:0, an:Math.random() * 6, jump:0, seen:false });
                return;
            }
        }
        function spawnVillagers(){
            var s = villageSite(Math.floor(px / TS / RG), Math.floor(py / TS / RG)); if (!s) return;
            if (Math.hypot((s.x + 0.5) * TS - px, (s.y + 0.5) * TS - py) > 420) return;
            var n = 0, i; for (i = 0; i < animals.length; i++) if (animals[i].sp === 'villager' && animals[i].vil === s.name) n++;
            if (n >= 3) return;
            var a = Math.random() * 6.283, d = 20 + Math.random() * 100;
            animals.push({ sp:'villager', vil:s.name, hx:(s.x + 0.5) * TS, hy:(s.y + 0.5) * TS, x:(s.x + 0.5) * TS + Math.cos(a) * d, y:(s.y + 0.5) * TS + Math.sin(a) * d, wt:0, dx:0, dy:0, face:0, trust:0, tamed:false, slot:0, stun:0, kx:0, ky:0, hit:false, cd:0, an:Math.random() * 6, jump:0, hue:rand(4) });
        }
        function canStand(a, S, x, y){
            if (S.k === 'f') return true;
            var t = tAt(x, y);
            if (S.k === 'w') return t === 0 || t === 1;
            if (S.k === 'x' && inVillage(x, y)) return false;
            if (S.k === 'v' && Math.hypot(x - a.hx, y - a.hy) > 160) return false;
            if ((t === 0 || t === 1) && !S.sw && S.k !== 'v' && !a.tamed) return false;
            return !blocked(x, y, S.sz);
        }
        function updAnimal(a, dt, dark, pspeed){
            var S = SPD[a.sp], dx = px - a.x, dy = py - a.y, d = Math.hypot(dx, dy) || 1, vx = 0, vy = 0, speed = S.sp, i;
            a.an += dt * 8;
            if (!a.seen && S.k !== 'v' && d < 140 && !journal[a.sp]){
                journal[a.sp] = 1; jc++; a.seen = true;
                add(10, 'New species: ' + S.n + ' +10', a.x, a.y - 22); toast('Journal: ' + S.n + ' (' + jc + '/' + SPLIST.length + ')'); api.sfx('level');
            }
            if (a.stun > 0){
                a.stun -= dt; a.kx *= 0.9; a.ky *= 0.9; vx = a.kx; vy = a.ky;
                if (a.stun <= 0) a.hit = false;
            } else if (a.tamed){
                var an = face + Math.PI + (a.slot - 1) * 0.8, tx = px + Math.cos(an) * (38 + a.slot * 8), ty = py + Math.sin(an) * (38 + a.slot * 8);
                var foe = null, fd = 150;
                for (i = 0; i < animals.length; i++){
                    var o = animals[i]; if (SPD[o.sp].k !== 'x' || o.stun > 0) continue;
                    var dd = Math.hypot(o.x - a.x, o.y - a.y); if (dd < fd){ fd = dd; foe = o; }
                }
                a.cd -= dt;
                if (foe){
                    var ex = foe.x - a.x, ey = foe.y - a.y, em = Math.hypot(ex, ey) || 1; vx = ex / em * 140; vy = ey / em * 140;
                    if (em < 22 && a.cd <= 0){ foe.stun = 1.4; foe.kx = ex / em * 220; foe.ky = ey / em * 220; a.cd = 2.2; api.sfx('merge'); burst(foe.x, foe.y, ['#fff', '#ffd400'], 6); }
                } else {
                    var gx = tx - a.x, gy = ty - a.y, gm = Math.hypot(gx, gy);
                    if (gm > 500){ a.x = px - Math.cos(face) * 40; a.y = py - Math.sin(face) * 40; }
                    else if (gm > 8){ var sp2 = Math.min(160, 40 + gm * 2.2); vx = gx / gm * sp2; vy = gy / gm * sp2; }
                }
            } else if (S.k === 'x'){
                var sight = S.sight * (dark > 0.4 ? 1.25 : 1);
                if (d < sight && !inVillage(px, py)){ var s2 = speed + dark * 14; vx = dx / d * s2; vy = dy / d * s2; }
                else { a.wt -= dt; if (a.wt <= 0){ a.wt = 1 + Math.random() * 2; var w = Math.random() * 6.283; a.dx = Math.random() < 0.3 ? 0 : Math.cos(w); a.dy = Math.random() < 0.3 ? 0 : Math.sin(w); } vx = a.dx * speed * 0.4; vy = a.dy * speed * 0.4; }
                if (d < 14 + S.sz){ hurt(a.x, a.y, S.dmg); }
                if (dashT > 0 && d < 28 + S.sz){
                    a.stun = 1.6; a.kx = -dx / d * 260; a.ky = -dy / d * 260;
                    if (!a.hit){ a.hit = true; add(5, '+5', a.x, a.y - 16); api.sfx('merge'); burst(a.x, a.y, ['#ffd400', '#fff', '#b86bff'], 8); }
                }
            } else {
                var flee = S.fl * (pspeed < 0.45 ? 0.45 : 1);
                if ((S.k === 'p' || S.k === 'f') && a.trust === 0 && d < flee){ vx = -dx / d * speed * 1.15; vy = -dy / d * speed * 1.15; }
                else {
                    a.wt -= dt;
                    if (a.wt <= 0){ a.wt = (S.k === 'f' ? 1 : 1.2) + Math.random() * 2.4; var w2 = Math.random() * 6.283; a.dx = Math.random() < (S.k === 'f' ? 0.1 : 0.35) ? 0 : Math.cos(w2); a.dy = Math.random() < 0.35 ? 0 : Math.sin(w2); }
                    var kk = S.k === 'f' ? 0.8 : 0.45;
                    vx = a.dx * speed * kk; vy = a.dy * speed * kk;
                    if (S.k === 'w' && a.jump <= 0 && Math.random() < dt * 0.15) a.jump = 0.6;
                }
            }
            if (a.jump > 0) a.jump -= dt;
            if (vx || vy){
                a.face = Math.atan2(vy, vx);
                if (S.k === 'f'){ a.x += vx * dt; a.y += vy * dt; }
                else {
                    moveEnt(a, vx * dt, vy * dt, S.sz, function(x, y){ return !canStand(a, S, x, y); });
                    if (a.sp === 'villager' && Math.hypot(a.x - a.hx, a.y - a.hy) > 150){ a.dx = (a.hx - a.x) / 150; a.dy = (a.hy - a.y) / 150; }
                }
            }
        }

        /* ── houses: walk up into a door ── */
        function enterHouse(v){
            var s = v.s, id = s.x + ',' + s.y + ',' + v.i, type = Math.floor(hh(s.x * 7 + v.i, s.y * 13 + v.i, 91) * 4);
            inside = { id:id, type:type, name:PN[Math.floor(hh(s.x + v.i, s.y, 92) * PN.length)], vil:s.name, t:0, say:'' };
            var says = [
                ['Welcome! Fresh goods, fair prices.', 'Oranges for hearts, boots and lanterns!'],
                ['Long road? Rest here. The beds are warm.', 'Sleep till morning, or try the stew.'],
                ['Oh, a visitor! Make yourself at home.', 'Take a look in the chest if you like.'],
                ['Ah, the parcel carrier. Come in, come in.', 'I know every road, and many secrets.']
            ];
            inside.say = says[type][rand(2)];
            joyId = null; jo = null; jv = { x:0, y:0 }; dashT = 0; api.sfx('place');
            buildBtns();
        }
        function leaveHouse(){ inside = null; btns = []; enterCd = 1.2; py += 26; }
        function dirName(dx, dy){
            var a = Math.atan2(dy, dx) * 180 / Math.PI;
            if (a > -22.5 && a <= 22.5) return 'east'; if (a > 22.5 && a <= 67.5) return 'south-east'; if (a > 67.5 && a <= 112.5) return 'south'; if (a > 112.5 && a <= 157.5) return 'south-west';
            if (a > 157.5 || a <= -157.5) return 'west'; if (a > -157.5 && a <= -112.5) return 'north-west'; if (a > -112.5 && a <= -67.5) return 'north'; return 'north-east';
        }
        function buildBtns(){
            var y0 = H - 196, bw = W - 64, list = [], i = inside;
            function B(label, sub, off, fn){ list.push({ label:label, sub:sub, off:off, fn:fn }); }
            if (i.type === 0){
                B('Buy a heart', '5 oranges', oranges < 5 || hp >= 5, function(){ oranges -= 5; hp++; i.say = 'Stay healthy out there!'; api.sfx('level'); });
                B(lantern ? 'Lantern (owned)' : 'Buy a lantern', '10 oranges · brighter nights', lantern || oranges < 10, function(){ oranges -= 10; lantern = true; i.say = 'It will light your way.'; api.sfx('level'); });
                B(boots ? 'Boots (owned)' : 'Buy swift boots', '12 oranges · +20% speed', boots || oranges < 12, function(){ oranges -= 12; boots = true; i.say = 'Run like the wind!'; api.sfx('level'); });
            } else if (i.type === 1){
                B('Sleep until morning', 'free · full hearts', false, function(){ gt = Math.ceil((gt + 1) / 150) * 150; hp = 5; animals = animals.filter(function(a){ return a.tamed || SPD[a.sp].k !== 'x'; }); rainI = 0; rainTo = 0; i.say = 'Good morning! You look rested.'; api.sfx('win'); });
                B('Hot stew', '3 oranges · +1 heart', oranges < 3 || hp >= 5, function(){ oranges -= 3; hp++; i.say = 'Hearty, isn’t it?'; api.sfx('level'); });
            } else if (i.type === 2){
                var done = taken[i.id + 'c'];
                B(done ? 'Chest (empty)' : 'Open the chest', done ? '' : 'a little treasure', !!done, function(){
                    taken[i.id + 'c'] = 1; var n = 3 + rand(6); oranges += n; add(5, null);
                    if (Math.random() < 0.3 && hp < 5){ hp++; i.say = 'Found ' + n + ' oranges and a heart!'; } else i.say = 'Found ' + n + ' oranges!';
                    api.sfx('level');
                });
                B('Listen to a story', 'free', false, function(){
                    var list2 = findVillages(1, 6).filter(function(s){ return s.name !== i.vil; });
                    if (list2.length){ var s = list2[rand(list2.length)]; var dxx = (s.x + 0.5) * TS - px, dyy = (s.y + 0.5) * TS - py;
                        i.say = s.name + ' lies ' + Math.round(Math.hypot(dxx, dyy) / TS) + ' m ' + dirName(dxx, dyy) + '. Lovely people.'; }
                    else i.say = 'The world is wide. Keep walking!';
                });
            } else {
                B(quest ? 'Change destination' : 'Take a new parcel', quest ? '2 oranges' : 'free', quest && oranges < 2, function(){
                    if (quest) oranges -= 2; quest = pickQuest(quest ? quest.name : null);
                    i.say = quest ? 'Take this to ' + quest.name + '.' : 'No roads known yet. Explore!'; api.sfx('level');
                });
                B('Reveal the map', '8 oranges · wide area', oranges < 8, function(){
                    oranges -= 8; var pc = Math.floor(px / TS / CH), pcy = Math.floor(py / TS / CH);
                    for (var a = pc - 7; a <= pc + 7; a++) for (var b = pcy - 7; b <= pcy + 7; b++) seen[KC(a, b)] = 1;
                    refreshMini(); i.say = 'Now you can see far beyond.'; api.sfx('win');
                });
            }
            B('Leave', '', false, leaveHouse);
            btns = [];
            for (var n = 0; n < list.length; n++){ list[n].x = 32; list[n].y = y0 + n * 46; list[n].w = bw; list[n].h = 40; btns.push(list[n]); }
        }

        /* ── drawing helpers ── */
        function heart(x, y, s, full){
            ctx.fillStyle = full ? '#ff3b5c' : 'rgba(255,255,255,.22)';
            ctx.beginPath(); ctx.moveTo(x, y + s * 0.9);
            ctx.bezierCurveTo(x - s * 1.3, y - s * 0.1, x - s * 0.55, y - s * 1.05, x, y - s * 0.35);
            ctx.bezierCurveTo(x + s * 0.55, y - s * 1.05, x + s * 1.3, y - s * 0.1, x, y + s * 0.9);
            ctx.fill();
        }
        function orangeIcon(x, y, r){
            ctx.fillStyle = '#ff9a1f'; ctx.beginPath(); ctx.arc(x, y, r, 0, 7); ctx.fill();
            ctx.fillStyle = '#58b947'; ctx.beginPath(); ctx.ellipse(x + r * 0.5, y - r, r * 0.5, r * 0.28, -0.5, 0, 7); ctx.fill();
        }
        function drawObj(code, tx, ty, sx, sy){
            var h = hh(tx, ty, 78), sway = Math.sin(gt * 1.6 + tx) * 1.2;
            if (code === 1){
                ctx.fillStyle = 'rgba(0,0,0,.2)'; ctx.beginPath(); ctx.ellipse(sx + 16, sy + 28, 12, 5, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#6b4423'; ctx.fillRect(sx + 13, sy + 14, 6, 14);
                ctx.fillStyle = h > 0.5 ? '#2f8f46' : '#287d3c'; ctx.beginPath(); ctx.arc(sx + 16 + sway, sy + 8, 14, 0, 7); ctx.fill();
                ctx.fillStyle = h > 0.5 ? '#3aa053' : '#2f9446'; ctx.beginPath(); ctx.arc(sx + 20 + sway, sy + 11, 8, 0, 7); ctx.fill();
                ctx.fillStyle = 'rgba(255,255,255,.14)'; ctx.beginPath(); ctx.arc(sx + 11 + sway, sy + 4, 6, 0, 7); ctx.fill();
            } else if (code === 5){
                ctx.fillStyle = 'rgba(0,0,0,.2)'; ctx.beginPath(); ctx.ellipse(sx + 16, sy + 29, 11, 4, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#5a3a1f'; ctx.fillRect(sx + 14, sy + 20, 4, 9);
                for (var q = 0; q < 3; q++){
                    ctx.fillStyle = q % 2 ? '#2d7a4d' : '#2a6f45'; ctx.beginPath();
                    ctx.moveTo(sx + 16 + sway * 0.4, sy - 6 + q * 8); ctx.lineTo(sx + 4 - q * 1.5, sy + 14 + q * 5); ctx.lineTo(sx + 28 + q * 1.5, sy + 14 + q * 5); ctx.closePath(); ctx.fill();
                }
                ctx.fillStyle = 'rgba(255,255,255,.8)'; ctx.beginPath(); ctx.moveTo(sx + 16, sy - 6); ctx.lineTo(sx + 10, sy + 5); ctx.lineTo(sx + 22, sy + 5); ctx.closePath(); ctx.fill();
            } else if (code === 6){
                ctx.fillStyle = 'rgba(0,0,0,.18)'; ctx.beginPath(); ctx.ellipse(sx + 16, sy + 28, 9, 4, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#3f9a5a'; rrect(ctx, sx + 12, sy + 6, 8, 22, 4); ctx.fill();
                rrect(ctx, sx + 5, sy + 12, 6, 12, 3); ctx.fill(); rrect(ctx, sx + 21, sy + 9, 6, 13, 3); ctx.fill();
                ctx.fillStyle = '#ff7aa8'; ctx.fillRect(sx + 14, sy + 4, 4, 3);
            } else if (code === 11){
                ctx.fillStyle = 'rgba(0,0,0,.18)'; ctx.beginPath(); ctx.ellipse(sx + 16, sy + 29, 10, 4, 0, 0, 7); ctx.fill();
                ctx.strokeStyle = '#8a6a3a'; ctx.lineWidth = 4; ctx.beginPath(); ctx.moveTo(sx + 16, sy + 29); ctx.quadraticCurveTo(sx + 20 + sway, sy + 16, sx + 15 + sway, sy + 6); ctx.stroke();
                ctx.fillStyle = '#3fa85c';
                for (var l = 0; l < 5; l++){ var an = -2.6 + l * 0.65 + sway * 0.03; ctx.beginPath(); ctx.ellipse(sx + 15 + sway + Math.cos(an) * 8, sy + 6 + Math.sin(an) * 5, 9, 2.8, an, 0, 7); ctx.fill(); }
            } else if (code === 2){
                var cols = ['#ff6b8a', '#ffd93d', '#ffffff', '#b86bff'];
                ctx.fillStyle = '#3d8f3d'; ctx.fillRect(sx + 8, sy + 13, 1, 4); ctx.fillRect(sx + 19, sy + 21, 1, 4);
                ctx.fillStyle = cols[Math.floor(h * 4)];
                ctx.fillRect(sx + 7, sy + 10 + sway * 0.4, 3, 3); ctx.fillRect(sx + 18, sy + 18, 3, 3); ctx.fillRect(sx + 12, sy + 24 + sway * 0.4, 3, 3);
            } else if (code === 3){
                ctx.fillStyle = 'rgba(0,0,0,.2)'; ctx.beginPath(); ctx.ellipse(sx + 16, sy + 26, 11, 4, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#8b90a8'; ctx.beginPath(); ctx.ellipse(sx + 16, sy + 20, 11, 8, 0, 0, 7); ctx.fill();
                ctx.fillStyle = 'rgba(255,255,255,.25)'; ctx.beginPath(); ctx.ellipse(sx + 12, sy + 17, 4, 3, 0, 0, 7); ctx.fill();
                if (h > 0.5){ ctx.fillStyle = 'rgba(80,160,80,.5)'; ctx.beginPath(); ctx.ellipse(sx + 20, sy + 23, 5, 2.5, 0, 0, 7); ctx.fill(); }
            } else if (code === 4){
                ctx.fillStyle = '#2f7d3a'; ctx.beginPath(); ctx.arc(sx + 16, sy + 22, 8, 0, 7); ctx.fill();
                ctx.fillStyle = '#3d9549'; ctx.beginPath(); ctx.arc(sx + 12, sy + 19, 5, 0, 7); ctx.fill();
                if (h > 0.6){ ctx.fillStyle = '#ff4d6d'; ctx.fillRect(sx + 18, sy + 20, 2, 2); ctx.fillRect(sx + 13, sy + 24, 2, 2); }
            } else if (code === 7){
                ctx.fillStyle = '#f2e6d0'; ctx.fillRect(sx + 9, sy + 20, 3, 5); ctx.fillRect(sx + 19, sy + 22, 3, 4);
                ctx.fillStyle = '#d9483b'; ctx.beginPath(); ctx.ellipse(sx + 10.5, sy + 19, 5, 3.2, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#e9a23b'; ctx.beginPath(); ctx.ellipse(sx + 20.5, sy + 21, 4, 2.6, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#fff'; ctx.fillRect(sx + 9, sy + 18, 2, 1); ctx.fillRect(sx + 19, sy + 20, 2, 1);
            } else if (code === 12){
                ctx.strokeStyle = '#b89548'; ctx.lineWidth = 1.5; ctx.beginPath();
                ctx.moveTo(sx + 10, sy + 26); ctx.lineTo(sx + 9 + sway * 0.4, sy + 17); ctx.moveTo(sx + 14, sy + 26); ctx.lineTo(sx + 15 + sway * 0.4, sy + 15); ctx.moveTo(sx + 18, sy + 26); ctx.lineTo(sx + 21 + sway * 0.4, sy + 18); ctx.stroke();
            } else if (code === 8){
                var by = Math.sin(gt * 3 + tx * 2) * 1.5;
                ctx.fillStyle = 'rgba(0,0,0,.2)'; ctx.beginPath(); ctx.ellipse(sx + 16, sy + 26, 7, 3, 0, 0, 7); ctx.fill();
                orangeIcon(sx + 16, sy + 17 + by, 7);
                ctx.fillStyle = 'rgba(255,255,255,.4)'; ctx.beginPath(); ctx.arc(sx + 13, sy + 14 + by, 2, 0, 7); ctx.fill();
            } else if (code === 9){
                ctx.fillStyle = 'rgba(0,0,0,.25)'; ctx.beginPath(); ctx.ellipse(sx + 16, sy + 27, 12, 4, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#8a4f1d'; rrect(ctx, sx + 6, sy + 12, 20, 14, 3); ctx.fill();
                ctx.fillStyle = '#b8742c'; rrect(ctx, sx + 6, sy + 8, 20, 8, 4); ctx.fill();
                ctx.fillStyle = '#ffd400'; ctx.fillRect(sx + 14, sy + 14, 4, 6);
                ctx.fillStyle = 'rgba(255,230,120,' + (0.3 + 0.2 * Math.sin(gt * 4)) + ')'; ctx.beginPath(); ctx.arc(sx + 16, sy + 16, 16, 0, 7); ctx.fill();
            } else if (code === 10){
                ctx.fillStyle = 'rgba(160,120,255,' + (0.18 + 0.12 * Math.sin(gt * 3)) + ')'; ctx.beginPath(); ctx.arc(sx + 16, sy + 16, 20, 0, 7); ctx.fill();
                ctx.fillStyle = '#a7abc2';
                ctx.fillRect(sx + 4, sy + 6, 7, 22); ctx.fillRect(sx + 21, sy + 6, 7, 22); ctx.fillRect(sx + 2, sy + 2, 28, 7);
                ctx.fillStyle = '#c9a0ff'; ctx.beginPath(); ctx.arc(sx + 16, sy + 20, 4, 0, 7); ctx.fill();
            }
        }
        function drawHouse(v, sx, sy, near){
            ctx.fillStyle = 'rgba(0,0,0,.2)'; ctx.fillRect(sx + 2, sy + 28, 64, 6);
            ctx.fillStyle = '#ead7aa'; ctx.fillRect(sx + 3, sy - 8, 58, 38);
            ctx.fillStyle = 'rgba(0,0,0,.08)'; ctx.fillRect(sx + 3, sy + 20, 58, 10);
            ctx.fillStyle = ROOF[v.i]; ctx.beginPath(); ctx.moveTo(sx - 3, sy - 6); ctx.lineTo(sx + 32, sy - 42); ctx.lineTo(sx + 67, sy - 6); ctx.closePath(); ctx.fill();
            ctx.fillStyle = 'rgba(255,255,255,.18)'; ctx.beginPath(); ctx.moveTo(sx + 32, sy - 42); ctx.lineTo(sx + 67, sy - 6); ctx.lineTo(sx + 32, sy - 6); ctx.closePath(); ctx.fill();
            ctx.fillStyle = '#5a3a1a'; ctx.fillRect(sx + 26, sy + 8, 12, 22);
            ctx.fillStyle = '#e0b84a'; ctx.fillRect(sx + 35, sy + 19, 2, 2);
            var night = 0.5 - 0.5 * Math.cos(gt / 150 * 6.2832);
            ctx.fillStyle = night > 0.35 ? '#ffe08a' : '#9fd6ff'; ctx.fillRect(sx + 9, sy + 2, 11, 11); ctx.fillRect(sx + 44, sy + 2, 11, 11);
            ctx.fillStyle = 'rgba(0,0,0,.2)'; ctx.fillRect(sx + 14, sy + 2, 1, 11); ctx.fillRect(sx + 49, sy + 2, 1, 11);
            var kind = Math.floor(hh(v.s.x * 7 + v.i, v.s.y * 13 + v.i, 91) * 4);
            ctx.fillStyle = 'rgba(0,0,0,.45)'; rrect(ctx, sx + 14, sy - 26, 36, 12, 4); ctx.fill();
            ctx.fillStyle = '#fff'; ctx.font = '700 8px Poppins, sans-serif'; ctx.textAlign = 'center';
            ctx.fillText(['SHOP', 'INN', 'HOME', 'ELDER'][kind], sx + 32, sy - 17);
            if (near){ var bob = Math.sin(gt * 5) * 3; ctx.fillStyle = '#ffd400'; ctx.beginPath(); ctx.moveTo(sx + 32, sy + 6 + bob); ctx.lineTo(sx + 26, sy + 14 + bob); ctx.lineTo(sx + 38, sy + 14 + bob); ctx.closePath(); ctx.fill(); }
        }
        /* capybara body (player, pets, villagers) */
        function drawCapy(x, y, ang, step, alpha, pal, hat, swim){
            pal = pal || { b:'#c97b34', h:'#d98c3f', l:'#a5642c', o:'#6b3a14' };
            ctx.save(); ctx.translate(x, y); ctx.globalAlpha = alpha;
            if (swim){
                ctx.strokeStyle = 'rgba(255,255,255,.5)'; ctx.lineWidth = 1.5;
                for (var rr = 0; rr < 2; rr++){ ctx.beginPath(); ctx.ellipse(0, 4, 16 + rr * 7 + Math.sin(gt * 4 + rr) * 2, 11 + rr * 5, 0, 0, 7); ctx.stroke(); }
            }
            ctx.rotate(ang);
            if (!swim){ ctx.fillStyle = 'rgba(0,0,0,.22)'; ctx.beginPath(); ctx.ellipse(1, 3, 15, 10, 0, 0, 7); ctx.fill(); }
            ctx.fillStyle = pal.l;
            if (!swim){
                var sw = Math.sin(step) * 3.5;
                ctx.beginPath(); ctx.ellipse(7 + sw, -7, 3.6, 2.5, 0, 0, 7); ctx.ellipse(7 - sw, 7, 3.6, 2.5, 0, 0, 7);
                ctx.ellipse(-8 - sw, -7, 3.6, 2.5, 0, 0, 7); ctx.ellipse(-8 + sw, 7, 3.6, 2.5, 0, 0, 7); ctx.fill();
            }
            ctx.fillStyle = pal.b; ctx.strokeStyle = pal.o; ctx.lineWidth = 1.5;
            ctx.beginPath(); ctx.ellipse(-1, 0, 14, 9.5, 0, 0, 7); ctx.fill(); ctx.stroke();
            ctx.fillStyle = pal.h; ctx.beginPath(); ctx.ellipse(11, 0, 7.5, 6.5, 0, 0, 7); ctx.fill(); ctx.stroke();
            ctx.fillStyle = pal.l; ctx.beginPath(); ctx.arc(8, -6, 2.4, 0, 7); ctx.arc(8, 6, 2.4, 0, 7); ctx.fill();
            ctx.fillStyle = '#4a2208'; ctx.beginPath(); ctx.ellipse(17, 0, 2.6, 3, 0, 0, 7); ctx.fill();
            ctx.fillStyle = '#111'; ctx.beginPath(); ctx.arc(12.5, -3, 1.2, 0, 7); ctx.arc(12.5, 3, 1.2, 0, 7); ctx.fill();
            if (hat){ ctx.fillStyle = hat; ctx.beginPath(); ctx.arc(7, 0, 5.5, 0, 7); ctx.fill(); ctx.fillStyle = 'rgba(255,255,255,.35)'; ctx.fillRect(4, -1, 6, 2); }
            ctx.restore();
        }
        function petMark(x, y){ var b = Math.sin(gt * 3 + x) * 2; heart(x, y - 20 + b, 4, true); }
        function drawAnimal(a){
            var S = SPD[a.sp], x = a.x, y = a.y, st = a.stun > 0, mv = Math.sin(a.an) * 2, f = a.face;
            if (a.sp === 'villager'){ drawCapy(x, y, f, a.an, 1, { b:'#c98a4a', h:'#d99a55', l:'#a5642c', o:'#6b3a14' }, ROOF[a.hue || 0]); return; }
            if (a.sp === 'capybara'){ drawCapy(x, y, f, a.an, 1, { b:'#b8722f', h:'#c9823a', l:'#94562a', o:'#5e3412' }, null, tAt(x, y) <= 1); if (a.tamed) petMark(x, y); return; }
            ctx.save(); ctx.translate(x, y);
            var sh = function(rx, ry){ ctx.fillStyle = 'rgba(0,0,0,.22)'; ctx.beginPath(); ctx.ellipse(0, 3, rx, ry, 0, 0, 7); ctx.fill(); };
            ctx.rotate(f);
            if (a.sp === 'rabbit'){
                sh(8, 5); ctx.fillStyle = '#efe6d8'; ctx.beginPath(); ctx.ellipse(0, 0, 8, 5.5, 0, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.arc(7, 0, 4.2, 0, 7); ctx.fill();
                ctx.fillStyle = '#e8cfc4'; ctx.beginPath(); ctx.ellipse(5, -3.5, 4, 1.6, -0.4, 0, 7); ctx.ellipse(5, 3.5, 4, 1.6, 0.4, 0, 7); ctx.fill();
                ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(-8, 0, 3, 0, 7); ctx.fill();
                ctx.fillStyle = '#222'; ctx.fillRect(8, -2, 1.4, 1.4); ctx.fillRect(8, 1, 1.4, 1.4);
            } else if (a.sp === 'deer'){
                sh(13, 7); ctx.fillStyle = '#b9824a'; ctx.beginPath(); ctx.ellipse(0, 0, 12, 6.5, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#a5703c'; ctx.beginPath(); ctx.ellipse(11, 0, 5.5, 3.8, 0, 0, 7); ctx.fill();
                ctx.strokeStyle = '#6b4423'; ctx.lineWidth = 1.5; ctx.beginPath(); ctx.moveTo(10, -3); ctx.lineTo(7, -8); ctx.lineTo(9, -11); ctx.moveTo(7, -8); ctx.lineTo(4, -9); ctx.moveTo(10, 3); ctx.lineTo(7, 8); ctx.lineTo(9, 11); ctx.moveTo(7, 8); ctx.lineTo(4, 9); ctx.stroke();
                ctx.fillStyle = '#f4e3c8'; ctx.beginPath(); ctx.arc(-12, 0, 2.5, 0, 7); ctx.fill();
                ctx.fillStyle = 'rgba(255,255,255,.5)'; ctx.fillRect(-3, -3, 2, 2); ctx.fillRect(2, 2, 2, 2); ctx.fillRect(-5, 3, 2, 2);
            } else if (a.sp === 'fox'){
                sh(10, 6); ctx.fillStyle = '#e2762b'; ctx.beginPath(); ctx.ellipse(0, 0, 9, 5.2, 0, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.moveTo(8, -4); ctx.lineTo(15, 0); ctx.lineTo(8, 4); ctx.closePath(); ctx.fill();
                ctx.beginPath(); ctx.moveTo(7, -3); ctx.lineTo(8, -8); ctx.lineTo(10, -3); ctx.moveTo(7, 3); ctx.lineTo(8, 8); ctx.lineTo(10, 3); ctx.fill();
                ctx.beginPath(); ctx.ellipse(-12, 1 + mv * 0.4, 7, 3.2, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.ellipse(-17, 1 + mv * 0.4, 3, 2.5, 0, 0, 7); ctx.fill();
            } else if (a.sp === 'turtle'){
                sh(9, 6); ctx.fillStyle = '#7bbf6a'; ctx.beginPath(); ctx.arc(8, 0, 3, 0, 7); ctx.fill();
                ctx.fillStyle = '#3f8f4c'; ctx.beginPath(); ctx.ellipse(0, 0, 9, 7, 0, 0, 7); ctx.fill();
                ctx.strokeStyle = '#2a6a36'; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(-6, 0); ctx.lineTo(6, 0); ctx.moveTo(0, -6); ctx.lineTo(0, 6); ctx.stroke();
            } else if (a.sp === 'frog'){
                sh(6, 4); ctx.translate(0, -Math.abs(Math.sin(a.an * 0.6)) * 3);
                ctx.fillStyle = '#5ec27a'; ctx.beginPath(); ctx.ellipse(0, 0, 6, 4.5, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(5, -2.5, 2, 0, 7); ctx.arc(5, 2.5, 2, 0, 7); ctx.fill();
                ctx.fillStyle = '#111'; ctx.fillRect(5.5, -3, 1.2, 1.2); ctx.fillRect(5.5, 2, 1.2, 1.2);
            } else if (a.sp === 'lizard'){
                ctx.fillStyle = '#a5c74a'; ctx.beginPath(); ctx.ellipse(0, 0, 6, 3, 0, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.arc(7, 0, 3, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.moveTo(-5, 0); ctx.quadraticCurveTo(-12, Math.sin(a.an) * 4, -18, Math.sin(a.an + 1) * 5); ctx.lineWidth = 2.4; ctx.strokeStyle = '#a5c74a'; ctx.stroke();
                ctx.fillStyle = '#7da12e'; ctx.fillRect(-1, -4.5, 2, 2); ctx.fillRect(-1, 2.5, 2, 2);
            } else if (a.sp === 'penguin'){
                sh(7, 5); ctx.fillStyle = '#1c2340'; ctx.beginPath(); ctx.ellipse(0, 0, 8, 6, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#f4f6ff'; ctx.beginPath(); ctx.ellipse(1.5, 0, 5, 3.6, 0, 0, 7); ctx.fill();
                ctx.fillStyle = '#ffb300'; ctx.beginPath(); ctx.moveTo(8, -1.2); ctx.lineTo(12, 0); ctx.lineTo(8, 1.2); ctx.fill();
                ctx.fillStyle = '#1c2340'; ctx.fillRect(-1, -9 + mv * 0.3, 3, 4); ctx.fillRect(-1, 5 - mv * 0.3, 3, 4);
            } else if (a.sp === 'wolf'){
                sh(13, 7); ctx.fillStyle = st ? '#8a8fa8' : '#6d7388'; ctx.beginPath(); ctx.ellipse(0, 0, 12, 6.5, 0, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.moveTo(10, -5); ctx.lineTo(19, 0); ctx.lineTo(10, 5); ctx.closePath(); ctx.fill();
                ctx.beginPath(); ctx.moveTo(8, -4); ctx.lineTo(9, -10); ctx.lineTo(12, -4); ctx.moveTo(8, 4); ctx.lineTo(9, 10); ctx.lineTo(12, 4); ctx.fill();
                ctx.beginPath(); ctx.ellipse(-14, 1 + mv * 0.5, 7, 3, 0, 0, 7); ctx.fill();
                ctx.fillStyle = st ? '#fff' : '#ff4d5a'; ctx.fillRect(14, -2.5, 2, 2); ctx.fillRect(14, 0.5, 2, 2);
                ctx.fillStyle = 'rgba(255,255,255,.18)'; ctx.fillRect(-4, -3, 8, 2);
            } else if (a.sp === 'bear'){
                sh(17, 10); ctx.fillStyle = st ? '#8a7a6a' : '#5a3a22'; ctx.beginPath(); ctx.ellipse(0, 0, 16, 11, 0, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.arc(14, 0, 8, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.arc(12, -7, 3, 0, 7); ctx.arc(12, 7, 3, 0, 7); ctx.fill();
                ctx.fillStyle = '#c9a07a'; ctx.beginPath(); ctx.ellipse(19, 0, 3.5, 3, 0, 0, 7); ctx.fill();
                ctx.fillStyle = st ? '#fff' : '#ff4d5a'; ctx.fillRect(15, -4, 2, 2); ctx.fillRect(15, 2, 2, 2);
            } else if (a.sp === 'fish'){
                var jmp = a.jump > 0 ? Math.sin((0.6 - a.jump) / 0.6 * 3.14159) * 10 : 0;
                ctx.translate(0, -jmp); ctx.globalAlpha = a.jump > 0 ? 1 : 0.55;
                ctx.fillStyle = '#ffb347'; ctx.beginPath(); ctx.ellipse(0, 0, 7, 3.2, 0, 0, 7); ctx.fill();
                ctx.beginPath(); ctx.moveTo(-6, 0); ctx.lineTo(-11, -3 + mv * 0.5); ctx.lineTo(-11, 3 - mv * 0.5); ctx.fill();
                ctx.fillStyle = '#fff'; ctx.fillRect(4, -1.5, 1.4, 1.4);
            } else if (a.sp === 'bug'){
                sh(10, 4); ctx.rotate(-f);
                ctx.fillStyle = st ? '#8a8fa8' : '#6a2d9a';
                for (var i = 0; i < 8; i++){
                    var an = i * 0.785 + gt * (st ? 0 : 1.2);
                    ctx.beginPath(); ctx.moveTo(Math.cos(an - 0.2) * 9, Math.sin(an - 0.2) * 9); ctx.lineTo(Math.cos(an) * 15, Math.sin(an) * 15); ctx.lineTo(Math.cos(an + 0.2) * 9, Math.sin(an + 0.2) * 9); ctx.fill();
                }
                ctx.beginPath(); ctx.arc(0, 0, 10, 0, 7); ctx.fill();
                ctx.fillStyle = st ? '#fff' : '#ff4d5a'; ctx.beginPath(); ctx.arc(-3.5, -2, 2.2, 0, 7); ctx.arc(3.5, -2, 2.2, 0, 7); ctx.fill();
            }
            ctx.restore();
            if (st){ ctx.fillStyle = '#ffd400'; ctx.fillRect(x - 1 + Math.cos(gt * 8) * 7, y - S.sz - 8, 3, 3); ctx.fillRect(x - 1 - Math.cos(gt * 8) * 7, y - S.sz - 8, 3, 3); }
            if (a.tamed) petMark(x, y);
        }
        function drawBird(a){
            var x = a.x, y = a.y, fp = Math.sin(gt * 14 + a.an) * 5;
            ctx.fillStyle = 'rgba(0,0,0,.16)'; ctx.beginPath(); ctx.ellipse(x + 4, y + 10, 6, 3, 0, 0, 7); ctx.fill();
            ctx.save(); ctx.translate(x, y - 14); ctx.rotate(a.face);
            ctx.fillStyle = '#6ab7ff'; ctx.beginPath(); ctx.ellipse(0, 0, 6, 3.4, 0, 0, 7); ctx.fill();
            ctx.fillStyle = '#4a98e6'; ctx.beginPath(); ctx.ellipse(-1, -3 - fp * 0.4, 4, 5 + fp * 0.4, 0, 0, 7); ctx.ellipse(-1, 3 + fp * 0.4, 4, 5 + fp * 0.4, 0, 0, 7); ctx.fill();
            ctx.fillStyle = '#ffb300'; ctx.beginPath(); ctx.moveTo(6, -1); ctx.lineTo(10, 0); ctx.lineTo(6, 1); ctx.fill();
            ctx.restore();
            if (a.tamed) petMark(x, y - 14);
        }
        function refreshMini(){
            if (!mm || !mmx) return;
            var ptx = Math.floor(px / TS), pty = Math.floor(py / TS), gx, gy;
            mmx.fillStyle = '#0b0d2a'; mmx.fillRect(0, 0, 72, 72);
            for (gx = 0; gx < 24; gx++) for (gy = 0; gy < 24; gy++){
                var tx = ptx + (gx - 12) * 3, ty = pty + (gy - 12) * 3;
                if (!seen[KC(Math.floor(tx / CH), Math.floor(ty / CH))]) continue;
                mmx.fillStyle = TC[tileAt(tx, ty)]; mmx.fillRect(gx * 3, gy * 3, 3, 3);
            }
            if (quest){
                var qx = (quest.x - px) / TS / 3 + 36, qy = (quest.y - py) / TS / 3 + 36;
                qx = Math.max(2, Math.min(70, qx)); qy = Math.max(2, Math.min(70, qy));
                mmx.fillStyle = '#ffd400'; mmx.fillRect(qx - 2, qy - 2, 5, 5);
            }
            mmx.fillStyle = '#fff'; mmx.fillRect(34, 34, 4, 4);
        }
        function roomButton(b){
            ctx.fillStyle = b.off ? 'rgba(255,255,255,.08)' : 'rgba(255,45,45,.78)';
            rrect(ctx, b.x, b.y, b.w, b.h, 10); ctx.fill();
            ctx.textAlign = 'left'; ctx.fillStyle = b.off ? 'rgba(255,255,255,.4)' : '#fff'; ctx.font = '700 14px Poppins, sans-serif';
            ctx.fillText(b.label, b.x + 14, b.y + (b.sub ? 18 : 25));
            if (b.sub){ ctx.font = '500 10px Poppins, sans-serif'; ctx.fillStyle = b.off ? 'rgba(255,255,255,.3)' : 'rgba(255,255,255,.85)'; ctx.fillText(b.sub, b.x + 14, b.y + 32); }
        }
        function drawInside(){
            var i = inside, rx = 24, ry = 64, rw = W - 48, rh = H - 290, k;
            ctx.fillStyle = '#17152e'; ctx.fillRect(0, 0, W, H);
            ctx.fillStyle = '#6b4a2a'; rrect(ctx, rx - 6, ry - 6, rw + 12, rh + 12, 14); ctx.fill();
            ctx.fillStyle = '#c99a62'; ctx.fillRect(rx, ry, rw, rh);
            ctx.strokeStyle = 'rgba(90,50,16,.28)'; ctx.lineWidth = 1;
            for (k = 0; k < rw; k += 28){ ctx.beginPath(); ctx.moveTo(rx + k, ry); ctx.lineTo(rx + k, ry + rh); ctx.stroke(); }
            ctx.fillStyle = '#8a6238'; ctx.fillRect(rx, ry, rw, 34);
            ctx.fillStyle = '#9fd6ff'; ctx.fillRect(rx + 40, ry + 6, 40, 22); ctx.fillRect(rx + rw - 80, ry + 6, 40, 22);
            ctx.fillStyle = '#8a6238'; ctx.fillRect(rx + 58, ry + 6, 3, 22); ctx.fillRect(rx + rw - 62, ry + 6, 3, 22);
            ctx.fillStyle = '#5a3a1a'; ctx.fillRect(rx + rw / 2 - 18, ry + rh - 8, 36, 8);
            var cx = rx + rw / 2, cy = ry + rh * 0.55;
            if (i.type === 0){
                ctx.fillStyle = '#7a4a22'; rrect(ctx, rx + 24, ry + 52, rw - 48, 30, 6); ctx.fill();
                ctx.fillStyle = '#e8c48a'; ctx.fillRect(rx + 24, ry + 50, rw - 48, 6);
                for (k = 0; k < 5; k++) orangeIcon(rx + 50 + k * 34, ry + 46, 8);
                ctx.fillStyle = '#d9483b'; for (k = 0; k < 3; k++){ rrect(ctx, rx + rw - 120 + k * 28, ry + 38, 14, 22, 3); ctx.fill(); }
                ctx.fillStyle = '#6b4a2a'; ctx.fillRect(rx + 12, ry + 100, 10, rh - 120); ctx.fillRect(rx + rw - 22, ry + 100, 10, rh - 120);
            } else if (i.type === 1){
                for (k = 0; k < 2; k++){
                    var bx = rx + 30 + k * 130, by = ry + 70;
                    ctx.fillStyle = '#7a4a22'; rrect(ctx, bx, by, 100, 56, 6); ctx.fill();
                    ctx.fillStyle = k ? '#d9483b' : '#3b7dd9'; rrect(ctx, bx + 4, by + 14, 92, 38, 6); ctx.fill();
                    ctx.fillStyle = '#fff'; rrect(ctx, bx + 6, by + 4, 28, 14, 6); ctx.fill();
                }
                ctx.fillStyle = '#6b6b78'; ctx.fillRect(rx + rw - 66, ry + 40, 48, 48);
                ctx.fillStyle = 'rgba(255,140,40,' + (0.7 + 0.2 * Math.sin(gt * 9)) + ')'; ctx.beginPath(); ctx.moveTo(rx + rw - 42, ry + 52); ctx.lineTo(rx + rw - 54, ry + 84); ctx.lineTo(rx + rw - 30, ry + 84); ctx.closePath(); ctx.fill();
            } else if (i.type === 2){
                ctx.fillStyle = '#b8474a'; rrect(ctx, cx - 70, cy - 30, 140, 90, 10); ctx.fill();
                ctx.fillStyle = '#7a4a22'; rrect(ctx, cx - 30, cy - 10, 60, 36, 6); ctx.fill();
                orangeIcon(cx - 8, cy - 12, 7); orangeIcon(cx + 10, cy - 13, 6);
                var done = taken[i.id + 'c'];
                ctx.fillStyle = '#8a4f1d'; rrect(ctx, rx + 20, ry + rh - 60, 44, 34, 5); ctx.fill();
                ctx.fillStyle = '#b8742c'; rrect(ctx, rx + 20, ry + rh - 68, 44, 18, 6); ctx.fill();
                if (!done){ ctx.fillStyle = 'rgba(255,230,120,' + (0.35 + 0.25 * Math.sin(gt * 4)) + ')'; ctx.beginPath(); ctx.arc(rx + 42, ry + rh - 48, 34, 0, 7); ctx.fill(); }
                ctx.fillStyle = '#ffd400'; ctx.fillRect(rx + 39, ry + rh - 56, 6, 10);
            } else {
                ctx.fillStyle = '#6b4a2a'; for (k = 0; k < 3; k++) ctx.fillRect(rx + 16 + k * 40, ry + 40, 32, 70);
                for (k = 0; k < 9; k++){ ctx.fillStyle = ROOF[k % 4]; ctx.fillRect(rx + 20 + (k % 3) * 40, ry + 46 + Math.floor(k / 3) * 21, 24, 16); }
                ctx.fillStyle = '#e8d3a6'; rrect(ctx, cx + 10, cy - 10, 110, 70, 6); ctx.fill();
                ctx.strokeStyle = '#8a6238'; ctx.lineWidth = 1.5; ctx.beginPath(); ctx.moveTo(cx + 24, cy + 6); ctx.lineTo(cx + 54, cy - 2); ctx.lineTo(cx + 80, cy + 22); ctx.lineTo(cx + 104, cy + 10); ctx.stroke();
                ctx.fillStyle = '#d9483b'; ctx.beginPath(); ctx.arc(cx + 80, cy + 22, 4, 0, 7); ctx.fill();
                ctx.fillStyle = 'rgba(255,200,100,' + (0.5 + 0.2 * Math.sin(gt * 8)) + ')'; ctx.beginPath(); ctx.arc(rx + rw - 30, ry + 60, 7, 0, 7); ctx.fill();
            }
            drawCapy(cx - 40, cy + 10, 0.4, gt * 3, 1, { b:'#c98a4a', h:'#d99a55', l:'#a5642c', o:'#6b3a14' }, ROOF[(i.type + i.id.length) % 4]);
            drawCapy(rx + rw / 2 + 24, ry + rh - 40, -Math.PI * 0.5, 0, 1, null, null);
            ctx.textAlign = 'center'; ctx.fillStyle = '#fff'; ctx.font = '700 15px Poppins, sans-serif';
            ctx.fillText(KIND[i.type] + ' · ' + i.vil, W / 2, 28);
            ctx.font = '500 11px Poppins, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,.6)'; ctx.fillText(i.name + ' the ' + ROLE[i.type], W / 2, 46);
            var bw = Math.min(W - 48, 360), bx2 = W / 2 - bw / 2, by2 = H - 222;
            ctx.fillStyle = 'rgba(0,0,0,.55)'; rrect(ctx, bx2, by2 - 24, bw, 30, 14); ctx.fill();
            ctx.fillStyle = '#fff'; ctx.font = '600 12px Poppins, sans-serif'; ctx.fillText(i.say, W / 2, by2 - 5);
            for (k = 0; k < btns.length; k++) roomButton(btns[k]);
            ctx.textAlign = 'right'; ctx.font = '700 13px Poppins, sans-serif'; ctx.fillStyle = '#ffcf6a'; ctx.fillText(oranges, W - 34, 28);
            orangeIcon(W - 22, 24, 7);
            for (k = 0; k < 5; k++) if (k < Math.max(hp, 3)) heart(18 + k * 22, 24, 8, k < hp);
        }

        return {
            W:W, H:H, swipe:false, instant:false, multi:true,
            resize:function(asp){
                var nh = Math.round(Math.max(520, Math.min(700, W / asp)));
                if (nh === H) return false;
                H = nh; this.H = H; DY = H - 70; FY = H - 138; if (inside) buildBtns(); return true;
            },
            reset:function(){
                newWorld();
                face = 0; hp = 3; inv = 0; dashT = 0; dashCd = 0; dashX = 1; dashY = 0; walkT = 0; sc = 0; gt = 0; over = false;
                animals = []; fl = []; parts = []; msg = null; joyId = null; jo = null; jv = { x:0, y:0 }; dashId = null;
                mmT = 0; hudT = 0; evT = 0; spawnT = 1; nVis = 0; healT = 0; oranges = 0; boots = false; lantern = false; pets = 0; zi = 1; enterCd = 0;
                inside = null; btns = []; rainI = 0; rainT = 70 + Math.random() * 60; rainTo = 0; flash = 0; quiet = false; swimming = false;
                flies = []; var i; for (i = 0; i < 26; i++) flies.push({ x:Math.random() * 600 - 300, y:Math.random() * 600 - 300, a:Math.random() * 6, s:0.4 + Math.random() });
                drops = []; for (i = 0; i < 90; i++) drops.push({ x:Math.random() * W, y:Math.random() * H, l:8 + Math.random() * 10 });
                try { mm = document.createElement('canvas'); mm.width = 72; mm.height = 72; mmx = mm.getContext('2d'); } catch(e){ mm = null; mmx = null; }
                quest = pickFirstQuest();
                if (quest) toast('Deliver the parcel to ' + quest.name);
                refreshMini();
            },
            preview:function(){
                for (var tries = 0; tries < 80; tries++){
                    newWorld();
                    var rx = Math.floor(px / TS / RG), ry = Math.floor(py / TS / RG), v = villageSite(rx, ry) || villageSite(rx + 1, ry) || villageSite(rx, ry + 1) || villageSite(rx - 1, ry);
                    if (v){ px = (v.x + 0.5) * TS; py = (v.y + 3.5) * TS; break; }
                }
                gt = 8; face = 0.4; hp = 3; sc = 120; oranges = 6; animals = [];
                function mk(sp, dx, dy, f, an){ return { sp:sp, x:px + dx, y:py + dy, face:f, wt:0, dx:0, dy:0, trust:0, tamed:false, slot:0, stun:0, kx:0, ky:0, hit:false, cd:0, an:an, jump:0 }; }
                animals.push(mk('deer', 130, -90, 3, 1), mk('rabbit', -120, 70, 0.6, 2), mk('capybara', -80, -100, 2, 3), mk('bird', 60, -130, 0.3, 4));
                quest = pickFirstQuest(); msg = null; inside = null;
                var pc = Math.floor(px / TS / CH), pcy = Math.floor(py / TS / CH), a, b;
                for (a = pc - 3; a <= pc + 3; a++) for (b = pcy - 3; b <= pcy + 3; b++) seen[KC(a, b)] = 1;
                refreshMini();
            },
            begin:function(){ api.extra(quest ? 'Parcel → ' + quest.name : 'Explore!'); },
            destroy:function(){},
            dir:function(){},
            tap:function(){ dash(); },
            keyx:function(e){
                var k = e.key;
                if (k === 'f' || k === 'F'){ feed(); return true; }
                if (k === '+' || k === '='){ zi = Math.min(2, zi + 1); return true; }
                if (k === '-' || k === '_'){ zi = Math.max(0, zi - 1); return true; }
                return false;
            },
            ptr:function(ty, x, y, id){
                if (inside){
                    if (ty === 'down') for (var i = 0; i < btns.length; i++){
                        var b = btns[i];
                        if (x >= b.x && x <= b.x + b.w && y >= b.y && y <= b.y + b.h && !b.off){ b.fn(); if (inside) buildBtns(); break; }
                    }
                    return;
                }
                if (ty === 'down'){
                    if (Math.hypot(x - DX, y - DY) < DR + 10){ dash(); dashId = id; return; }
                    if (Math.hypot(x - FX, y - FY) < FR + 10){ feed(); return; }
                    if (Math.hypot(x - 30, y - (H - 36)) < 20){ zi = Math.max(0, zi - 1); return; }
                    if (Math.hypot(x - 76, y - (H - 36)) < 20){ zi = Math.min(2, zi + 1); return; }
                    if (joyId === null){ joyId = id; jo = { x:x, y:y }; jv = { x:0, y:0 }; }
                } else if (ty === 'move'){
                    if (id === joyId && jo){
                        var dx = x - jo.x, dy = y - jo.y, m = Math.hypot(dx, dy);
                        if (m > 56){ jo.x += dx / m * (m - 56); jo.y += dy / m * (m - 56); dx = x - jo.x; dy = y - jo.y; m = 56; }
                        jv = m < 8 ? { x:0, y:0 } : { x:dx / 56, y:dy / 56 };
                    }
                } else if (ty === 'up'){
                    if (id === joyId){ joyId = null; jo = null; jv = { x:0, y:0 }; }
                    if (id === dashId) dashId = null;
                }
            },
            update:function(dt){
                if (over) return;
                if (inside){ inside.t += dt; if (msg){ msg.t -= dt; if (msg.t <= 0) msg = null; } return; }
                gt += dt;
                var dark = 0.5 - 0.5 * Math.cos(gt / 150 * 6.2832), i, e;
                /* weather */
                rainT -= dt;
                if (rainT <= 0){ if (rainTo > 0){ rainTo = 0; rainT = 90 + Math.random() * 120; } else { rainTo = 0.5 + Math.random() * 0.5; rainT = 35 + Math.random() * 40; toast('It starts to rain…'); } }
                rainI += (rainTo - rainI) * Math.min(1, dt * 0.6);
                if (rainI > 0.6 && Math.random() < dt * 0.05) flash = 0.35;
                if (flash > 0) flash -= dt * 1.2;
                /* movement: swim, climb, wade, run: only trees, rocks and walls stop you */
                var ix = jv.x + ((api.held.r ? 1 : 0) - (api.held.l ? 1 : 0)), iy = jv.y + ((api.held.d ? 1 : 0) - (api.held.u ? 1 : 0));
                var im = Math.hypot(ix, iy); if (im > 1){ ix /= im; iy /= im; im = 1; }
                var t0 = tAt(px, py), sp = 150 * speedMul(t0) * (boots ? 1.2 : 1);
                swimming = t0 <= 1;
                var vx = ix * sp, vy = iy * sp;
                if (dashT > 0){ dashT -= dt; vx = dashX * 420; vy = dashY * 420; }
                quiet = im < 0.45 && dashT <= 0;
                if (im > 0.1 || dashT > 0){
                    var tf = Math.atan2(dashT > 0 ? vy : iy, dashT > 0 ? vx : ix), df = tf - face;
                    while (df > Math.PI) df -= 6.2832; while (df < -Math.PI) df += 6.2832;
                    face += df * Math.min(1, dt * 14); walkT += dt * (6 + im * 8);
                }
                var pe = { x:px, y:py };
                moveEnt(pe, vx * dt, vy * dt, 9, blocked); px = pe.x; py = pe.y;
                if (dashCd > 0) dashCd -= dt;
                if (inv > 0) inv -= dt;
                if (enterCd > 0) enterCd -= dt;
                healT += dt; if (healT > 40 && hp < 3){ hp++; healT = 0; toast('You feel better'); }
                /* walk up into a door */
                if (enterCd <= 0 && iy < -0.3){
                    var ftx = Math.floor(px / TS), fty = Math.floor((py - 15) / TS);
                    if (tileAt(ftx, fty) === 9){ var vi = vInfo(ftx, fty); if (vi){ enterHouse(vi); return; } }
                }
                /* wildlife */
                var want = 12 + Math.round(dark * 3 + Math.min(4, nVis / 20)) + (zi === 0 ? 4 : 0);
                spawnT -= dt;
                if (spawnT <= 0){ spawnT = 0.8; if (gt > 6 && animals.length < want) spawnAnimal(dark); spawnVillagers(); }
                for (i = animals.length - 1; i >= 0; i--){
                    e = animals[i];
                    if (e.sp === 'villager'){ if (Math.hypot(e.x - px, e.y - py) > 900){ animals.splice(i, 1); continue; } }
                    else if (!e.tamed && Math.hypot(e.x - px, e.y - py) > 640){ animals.splice(i, 1); continue; }
                    updAnimal(e, dt, dark, im);
                    if (over) return;
                }
                /* pickups and discoveries */
                var ptx = Math.floor(px / TS), pty = Math.floor(py / TS), a2, b2;
                for (a2 = -1; a2 <= 1; a2++) for (b2 = -1; b2 <= 1; b2++){
                    var tx = ptx + a2, ty = pty + b2, key = tx + ',' + ty; if (taken[key]) continue;
                    var tt = tileAt(tx, ty), code = objAt(tx, ty, tt);
                    if (code !== 8 && code !== 9 && code !== 10) continue;
                    var cx = (tx + 0.5) * TS, cy = (ty + 0.5) * TS, dd = Math.hypot(cx - px, cy - py);
                    if (code === 8 && dd < 22){ taken[key] = 1; oranges++; add(2, '+1 orange', cx, cy - 10); api.sfx('pop'); burst(cx, cy, ['#ff9a1f', '#ffd400'], 6); }
                    else if (code === 9 && dd < 26){
                        taken[key] = 1; var n = 3 + rand(5); oranges += n; add(25, 'Chest! +25', cx, cy - 12);
                        if (hp < 5){ hp++; toast('Chest: ' + n + ' oranges and a heart!'); } else toast('Chest: ' + n + ' oranges!'); api.sfx('level'); burst(cx, cy, ['#ffd400', '#fff', '#ffb300'], 16);
                    } else if (code === 10 && dd < 34){ taken[key] = 1; add(20, 'Shrine found +20', cx, cy - 14); api.sfx('win'); burst(cx, cy, ['#c9a0ff', '#fff'], 14); if (hp < 5) hp++; }
                }
                var ck = KC(Math.floor(ptx / CH), Math.floor(pty / CH));
                if (!visited[ck]){ visited[ck] = 1; nVis++; if (nVis > 1) add(3, null); }
                var vs = villageSite(Math.floor(ptx / RG), Math.floor(pty / RG));
                if (vs && !disc[vs.name] && Math.hypot((vs.x + 0.5) * TS - px, (vs.y + 0.5) * TS - py) < 170){
                    disc[vs.name] = 1; add(30, 'Village found +30', px, py - 40); toast('Welcome to ' + vs.name + '! Walk up into a house to go in.'); api.sfx('level');
                }
                if (quest && Math.hypot(quest.x - px, quest.y - py) < 70){
                    add(40, 'Delivered! +40', px, py - 40); api.sfx('win'); oranges += 3;
                    var done = quest.name; burst(px, py, ['#ffd400', '#fff', '#ff6b8a', '#58b947'], 22);
                    quest = pickQuest(done); toast(quest ? 'Parcel delivered (+3 oranges)! Next: ' + quest.name : 'Parcel delivered!');
                }
                /* housekeeping */
                evT -= dt;
                if (evT <= 0){
                    evT = 1.5;
                    var Z = ZS[zi], c0x = Math.floor((px - W / 2 / Z) / TS / CH), c1x = Math.floor((px + W / 2 / Z) / TS / CH), c0y = Math.floor((py - H / 2 / Z) / TS / CH), c1y = Math.floor((py + H / 2 / Z) / TS / CH);
                    for (a2 = c0x; a2 <= c1x; a2++) for (b2 = c0y; b2 <= c1y; b2++) seen[KC(a2, b2)] = 1;
                    var keys = Object.keys(chunks);
                    if (keys.length > 110){
                        var pcx = Math.floor(ptx / CH), pcy = Math.floor(pty / CH);
                        keys.forEach(function(k){ var c = chunks[k]; if (Math.abs(c.cx - pcx) > 5 || Math.abs(c.cy - pcy) > 5) delete chunks[k]; });
                    }
                }
                mmT -= dt; if (mmT <= 0){ mmT = 0.4; refreshMini(); }
                hudT -= dt;
                if (hudT <= 0){ hudT = 0.4; api.extra(quest ? 'Parcel → ' + quest.name + ' · ' + Math.round(Math.hypot(quest.x - px, quest.y - py) / TS) + ' m' : 'Explore!'); }
                for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 26 * dt; fl[i].t -= dt; if (fl[i].t <= 0) fl.splice(i, 1); }
                for (i = parts.length - 1; i >= 0; i--){ var p = parts[i]; p.x += p.vx * dt; p.y += p.vy * dt; p.life -= dt; if (p.life <= 0) parts.splice(i, 1); }
                for (i = 0; i < flies.length; i++){ var f2 = flies[i]; f2.a += dt * f2.s; f2.x += Math.cos(f2.a) * 10 * dt; f2.y += Math.sin(f2.a * 1.3) * 10 * dt; }
                for (i = 0; i < drops.length; i++){ var dr = drops[i]; dr.y += 700 * dt; dr.x -= 120 * dt; if (dr.y > H){ dr.y = -10; dr.x = Math.random() * (W + 100); } if (dr.x < -10) dr.x = W + 5; }
                if (msg){ msg.t -= dt; if (msg.t <= 0) msg = null; }
            },
            draw:function(t, state){
                if (inside){
                    drawInside();
                    if (msg){ ctx.globalAlpha = Math.min(1, msg.t * 1.5); ctx.textAlign = 'center'; ctx.font = '600 12px Poppins, sans-serif'; ctx.fillStyle = '#ffd400'; ctx.fillText(msg.txt, W / 2, 64); ctx.globalAlpha = 1; }
                    if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.18)'; ctx.fillRect(0, 0, W, H); }
                    return;
                }
                var Z = ZS[zi], dark = 0.5 - 0.5 * Math.cos(gt / 150 * 6.2832);
                var vw = W / Z, vh = H / Z;
                var tx0 = Math.floor((px - vw / 2) / TS) - 1, ty0 = Math.floor((py - vh / 2) / TS) - 1, tx1 = Math.floor((px + vw / 2) / TS) + 1, ty1 = Math.floor((py + vh / 2) / TS) + 3;
                var tx, ty, i, sx, sy, tt;
                ctx.fillStyle = '#0b0d2a'; ctx.fillRect(0, 0, W, H);
                ctx.save(); ctx.translate(W / 2, H / 2); ctx.scale(Z, Z); ctx.translate(-Math.round(px * Z) / Z, -Math.round(py * Z) / Z);
                /* ground */
                for (ty = ty0; ty <= ty1; ty++) for (tx = tx0; tx <= tx1; tx++){
                    tt = tileAt(tx, ty); sx = tx * TS; sy = ty * TS;
                    ctx.fillStyle = (tt === 3 || tt === 11) ? GR[(tx * 7 + ty * 13) & 3] : TC[tt];
                    ctx.fillRect(sx, sy, TS + 1, TS + 1);
                    var hg = hh(tx, ty, 9);
                    if (tt <= 1){
                        if (hg > 0.72){ ctx.fillStyle = 'rgba(255,255,255,.2)'; ctx.fillRect(sx + 6 + Math.sin(gt * 2 + tx) * 4, sy + 12 + (hh(tx, ty, 10) * 10 | 0), 12, 2); }
                        var fo = 0.35 + 0.2 * Math.sin(gt * 2 + tx + ty);
                        ctx.fillStyle = 'rgba(255,255,255,' + fo + ')';
                        if (tileAt(tx, ty - 1) > 1) ctx.fillRect(sx, sy, TS, 3);
                        if (tileAt(tx, ty + 1) > 1) ctx.fillRect(sx, sy + TS - 3, TS, 3);
                        if (tileAt(tx - 1, ty) > 1) ctx.fillRect(sx, sy, 3, TS);
                        if (tileAt(tx + 1, ty) > 1) ctx.fillRect(sx + TS - 3, sy, 3, TS);
                        if (tt === 0){ ctx.fillStyle = 'rgba(0,20,60,.18)'; ctx.fillRect(sx, sy, TS, TS); }
                    } else if (tt === 3 || tt === 4 || tt === 7 || tt === 11){
                        if (hg > 0.45){
                            var swy = Math.sin(gt * 2 + tx * 0.7 + ty) * 1.2;
                            ctx.fillStyle = 'rgba(30,110,40,.5)'; ctx.fillRect(sx + 7 + swy, sy + 20, 1, 4); ctx.fillRect(sx + 9 + swy, sy + 19, 1, 5); ctx.fillRect(sx + 21 + swy, sy + 9, 1, 4);
                        }
                    } else if (tt === 6){ ctx.fillStyle = 'rgba(160,190,230,.35)'; ctx.fillRect(sx + 4, sy + 8, 10, 3); }
                    else if (tt === 5){ ctx.fillStyle = 'rgba(0,0,0,.12)'; ctx.fillRect(sx + 3, sy + 18, 14, 4); ctx.fillStyle = 'rgba(255,255,255,.12)'; ctx.fillRect(sx + 14, sy + 5, 10, 3); }
                    else if (tt === 12){ ctx.fillStyle = 'rgba(190,140,60,.35)'; ctx.fillRect(sx + 4, sy + 10 + (hg * 8 | 0), 14, 2); ctx.fillRect(sx + 14, sy + 22, 10, 2); }
                    else if (tt === 13){ ctx.fillStyle = 'rgba(150,180,220,.3)'; ctx.fillRect(sx + 6, sy + 12 + (hg * 8 | 0), 12, 2); }
                    else if (tt === 2){ if (hg > 0.8){ ctx.fillStyle = '#f7ebd0'; ctx.fillRect(sx + 10, sy + 14, 3, 2); } }
                    else if (tt === 8){ ctx.fillStyle = 'rgba(0,0,0,.07)'; ctx.fillRect(sx + 8, sy + 10, 4, 3); }
                }
                /* fish swim under the surface */
                for (i = 0; i < animals.length; i++) if (animals[i].sp === 'fish' && animals[i].jump <= 0) drawAnimal(animals[i]);
                /* objects + creatures, row by row so things pass behind trees and roofs */
                var rows = {}, flyers = [], pr = Math.floor(py / TS);
                for (i = 0; i < animals.length; i++){
                    var an = animals[i], S = SPD[an.sp];
                    if (S.k === 'f'){ flyers.push(an); continue; }
                    if (an.sp === 'fish' && an.jump <= 0) continue;
                    var rw = Math.floor(an.y / TS); (rows[rw] = rows[rw] || []).push(an);
                }
                for (ty = ty0; ty <= ty1; ty++){
                    for (tx = tx0; tx <= tx1; tx++){
                        tt = tileAt(tx, ty); sx = tx * TS; sy = ty * TS;
                        if (tt === 9){ var v = vInfo(tx, ty); if (v && v.bl){ var near = Math.abs(px - (sx + 32)) < 90 && py > sy && py < sy + 120; drawHouse(v, sx, sy, near); } }
                        else if (tt === 10){
                            ctx.fillStyle = '#8b90a8'; ctx.beginPath(); ctx.arc(sx + 16, sy + 16, 11, 0, 7); ctx.fill();
                            ctx.fillStyle = '#3f8ee0'; ctx.beginPath(); ctx.arc(sx + 16, sy + 16, 7, 0, 7); ctx.fill();
                            ctx.fillStyle = 'rgba(255,255,255,.35)'; ctx.fillRect(sx + 12, sy + 13, 5, 2);
                        } else if (tt === 8){
                            var vv = vInfo(tx, ty);
                            if (vv && vv.lamp){
                                ctx.fillStyle = '#4a3a2a'; ctx.fillRect(sx + 14, sy + 8, 4, 22);
                                ctx.fillStyle = dark > 0.25 ? '#ffe08a' : '#cfc7a8'; ctx.beginPath(); ctx.arc(sx + 16, sy + 8, 5, 0, 7); ctx.fill();
                            }
                        } else {
                            var code = objAt(tx, ty, tt);
                            if (code && !((code === 8 || code === 9 || code === 10) && taken[tx + ',' + ty])) drawObj(code, tx, ty, sx, sy);
                        }
                    }
                    if (ty === pr){
                        var al = (inv > 0 && Math.floor(gt * 14) % 2) ? 0.35 : 1;
                        if (dashT > 0) drawCapy(px - dashX * 22, py - dashY * 22, face, walkT, 0.3);
                        drawCapy(px, py, face, walkT, al, null, null, swimming);
                    }
                    if (rows[ty]) for (i = 0; i < rows[ty].length; i++) drawAnimal(rows[ty][i]);
                }
                for (i = 0; i < flyers.length; i++) drawBird(flyers[i]);
                /* butterflies by day, fireflies by night */
                for (i = 0; i < flies.length; i++){
                    var fz = flies[i], fx2 = px + fz.x, fy2 = py + fz.y;
                    if (dark > 0.3){
                        var gl = 0.4 + 0.4 * Math.sin(gt * 3 + i);
                        ctx.fillStyle = 'rgba(255,240,120,' + gl + ')'; ctx.beginPath(); ctx.arc(fx2, fy2, 2, 0, 7); ctx.fill();
                        ctx.fillStyle = 'rgba(255,240,120,' + gl * 0.25 + ')'; ctx.beginPath(); ctx.arc(fx2, fy2, 7, 0, 7); ctx.fill();
                    } else if (i < 10 && rainI < 0.3){
                        var fl3 = Math.sin(gt * 18 + i) * 3;
                        ctx.fillStyle = ['#ff9ac1', '#ffd36b', '#8fd3ff'][i % 3]; ctx.beginPath(); ctx.ellipse(fx2 - 2, fy2, 2.5, 1.6 + Math.abs(fl3) * 0.4, 0, 0, 7); ctx.ellipse(fx2 + 2, fy2, 2.5, 1.6 + Math.abs(fl3) * 0.4, 0, 0, 7); ctx.fill();
                    }
                }
                for (i = 0; i < parts.length; i++){
                    ctx.globalAlpha = Math.max(0, parts[i].life / parts[i].max); ctx.fillStyle = parts[i].col;
                    ctx.beginPath(); ctx.arc(parts[i].x, parts[i].y, 3, 0, 7); ctx.fill();
                }
                ctx.globalAlpha = 1;
                /* night lights: lamps and lantern */
                if (dark > 0.25){
                    ctx.globalCompositeOperation = 'lighter';
                    var lr = lantern ? 170 : 120, lg = ctx.createRadialGradient(px, py, 5, px, py, lr);
                    lg.addColorStop(0, 'rgba(255,200,110,' + dark * 0.4 + ')'); lg.addColorStop(1, 'rgba(255,200,110,0)');
                    ctx.fillStyle = lg; ctx.fillRect(px - lr, py - lr, lr * 2, lr * 2);
                    for (ty = ty0; ty <= ty1; ty++) for (tx = tx0; tx <= tx1; tx++){
                        if (tileAt(tx, ty) !== 8) continue;
                        var lv = vInfo(tx, ty); if (!lv || !lv.lamp) continue;
                        var lx = tx * TS + 16, ly = ty * TS + 8, g2 = ctx.createRadialGradient(lx, ly, 2, lx, ly, 90);
                        g2.addColorStop(0, 'rgba(255,210,120,' + dark * 0.5 + ')'); g2.addColorStop(1, 'rgba(255,210,120,0)');
                        ctx.fillStyle = g2; ctx.fillRect(lx - 90, ly - 90, 180, 180);
                    }
                    ctx.globalCompositeOperation = 'source-over';
                }
                ctx.textAlign = 'center'; ctx.font = '700 12px Poppins, sans-serif';
                for (i = 0; i < fl.length; i++){
                    ctx.globalAlpha = Math.min(1, fl[i].t * 1.6);
                    ctx.fillStyle = '#000'; ctx.fillText(fl[i].txt, fl[i].x + 1, fl[i].y + 1);
                    ctx.fillStyle = '#fff'; ctx.fillText(fl[i].txt, fl[i].x, fl[i].y);
                }
                ctx.globalAlpha = 1;
                ctx.restore();
                /* darkness, rain, thunder */
                if (dark > 0.05 || rainI > 0.05){
                    var dk = Math.min(0.88, dark * 0.82 + rainI * 0.25), inner = lantern ? 110 : 60;
                    var g = ctx.createRadialGradient(W / 2, H / 2, inner, W / 2, H / 2, Math.max(W, H) * 0.62);
                    g.addColorStop(0, 'rgba(5,8,45,' + (dk * 0.2) + ')'); g.addColorStop(1, 'rgba(5,8,45,' + dk + ')');
                    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
                }
                if (rainI > 0.08){
                    ctx.strokeStyle = 'rgba(190,215,255,' + (0.25 + rainI * 0.3) + ')'; ctx.lineWidth = 1; ctx.beginPath();
                    for (i = 0; i < drops.length * rainI; i++){ var d3 = drops[i]; ctx.moveTo(d3.x, d3.y); ctx.lineTo(d3.x - 3, d3.y + d3.l); }
                    ctx.stroke();
                }
                if (flash > 0){ ctx.fillStyle = 'rgba(255,255,255,' + Math.min(0.5, flash) + ')'; ctx.fillRect(0, 0, W, H); }
                /* quest arrow */
                if (quest){
                    var qd = Math.hypot(quest.x - px, quest.y - py);
                    if (qd > 150 / Z){
                        var qa = Math.atan2(quest.y - py, quest.x - px), rr = Math.min(W, H) * 0.34;
                        ctx.save(); ctx.translate(W / 2 + Math.cos(qa) * rr, H / 2 + Math.sin(qa) * rr); ctx.rotate(qa);
                        ctx.fillStyle = '#ffd400'; ctx.strokeStyle = 'rgba(0,0,0,.5)'; ctx.lineWidth = 2;
                        ctx.beginPath(); ctx.moveTo(13, 0); ctx.lineTo(-8, -9); ctx.lineTo(-3, 0); ctx.lineTo(-8, 9); ctx.closePath(); ctx.fill(); ctx.stroke();
                        ctx.restore();
                    } else {
                        var bob = Math.sin(gt * 5) * 4, qsx = W / 2 + (quest.x - px) * Z, qsy = H / 2 + (quest.y - py) * Z;
                        ctx.fillStyle = '#ffd400';
                        ctx.beginPath(); ctx.moveTo(qsx, qsy - 60 + bob); ctx.lineTo(qsx - 8, qsy - 74 + bob); ctx.lineTo(qsx + 8, qsy - 74 + bob); ctx.closePath(); ctx.fill();
                    }
                }
                /* HUD */
                for (i = 0; i < 5; i++) if (i < Math.max(hp, 3)) heart(18 + i * 22, 20, 8, i < hp);
                ctx.textAlign = 'left'; ctx.font = '700 12px Poppins, sans-serif';
                orangeIcon(16, 46, 7); ctx.fillStyle = '#ffcf6a'; ctx.fillText(oranges, 28, 50);
                ctx.fillStyle = 'rgba(255,255,255,.8)'; ctx.fillText('Journal ' + jc + '/' + SPLIST.length, 62, 50);
                if (pets > 0){ heart(158, 46, 5, true); ctx.fillStyle = '#ff9ab0'; ctx.fillText('x' + pets, 168, 50); }
                if (quest){
                    ctx.font = '600 11px Poppins, sans-serif';
                    var qt = 'Parcel → ' + quest.name + '  ' + Math.round(Math.hypot(quest.x - px, quest.y - py) / TS) + ' m';
                    var qw = ctx.measureText(qt).width + 16;
                    ctx.fillStyle = 'rgba(0,0,0,.45)'; rrect(ctx, 8, 62, qw, 22, 11); ctx.fill();
                    ctx.fillStyle = '#ffd400'; ctx.fillText(qt, 16, 77);
                }
                if (mm){
                    ctx.fillStyle = 'rgba(0,0,0,.5)'; rrect(ctx, W - 84, 6, 78, 78, 8); ctx.fill();
                    ctx.drawImage(mm, W - 81, 9, 72, 72);
                    ctx.strokeStyle = 'rgba(255,255,255,.35)'; ctx.lineWidth = 1; ctx.strokeRect(W - 81.5, 8.5, 73, 73);
                }
                ctx.textAlign = 'center'; ctx.font = '700 10px Poppins, sans-serif';
                ctx.fillStyle = dark > 0.55 ? '#c9a0ff' : 'rgba(255,255,255,.7)'; ctx.fillText((dark > 0.55 ? 'NIGHT' : (dark > 0.3 ? 'DUSK' : 'DAY')) + (rainI > 0.3 ? ' · RAIN' : ''), W - 45, 98);
                if (swimming){ ctx.fillStyle = 'rgba(120,200,255,.9)'; ctx.fillText('SWIMMING', W / 2, H - 150); }
                if (quiet && state === 'playing'){ ctx.fillStyle = 'rgba(255,255,255,.35)'; ctx.fillText('sneaking', W / 2, H - 164); }
                if (msg){
                    ctx.globalAlpha = Math.min(1, msg.t * 1.5); ctx.font = '600 13px Poppins, sans-serif';
                    var mw = ctx.measureText(msg.txt).width + 24;
                    ctx.fillStyle = 'rgba(0,0,0,.6)'; rrect(ctx, W / 2 - mw / 2, H - 138, mw, 28, 14); ctx.fill();
                    ctx.fillStyle = '#fff'; ctx.fillText(msg.txt, W / 2, H - 119); ctx.globalAlpha = 1;
                }
                /* buttons */
                ctx.fillStyle = dashCd > 0 ? 'rgba(255,255,255,.12)' : 'rgba(255,45,45,.55)';
                ctx.beginPath(); ctx.arc(DX, DY, DR, 0, 7); ctx.fill();
                ctx.strokeStyle = 'rgba(255,255,255,.55)'; ctx.lineWidth = 2; ctx.beginPath(); ctx.arc(DX, DY, DR, 0, 7); ctx.stroke();
                if (dashCd > 0){ ctx.strokeStyle = '#ff2d2d'; ctx.lineWidth = 4; ctx.beginPath(); ctx.arc(DX, DY, DR - 3, -Math.PI / 2, -Math.PI / 2 + (1 - dashCd) * 6.2832); ctx.stroke(); }
                ctx.fillStyle = '#fff'; ctx.font = '700 11px Poppins, sans-serif'; ctx.fillText('DASH', DX, DY + 4);
                ctx.fillStyle = oranges > 0 ? 'rgba(255,154,31,.7)' : 'rgba(255,255,255,.12)'; ctx.beginPath(); ctx.arc(FX, FY, FR, 0, 7); ctx.fill();
                ctx.strokeStyle = 'rgba(255,255,255,.5)'; ctx.lineWidth = 2; ctx.beginPath(); ctx.arc(FX, FY, FR, 0, 7); ctx.stroke();
                ctx.fillStyle = '#fff'; ctx.font = '700 10px Poppins, sans-serif'; ctx.fillText('FEED', FX, FY + 4);
                ctx.fillStyle = 'rgba(0,0,0,.45)'; ctx.beginPath(); ctx.arc(30, H - 36, 17, 0, 7); ctx.arc(76, H - 36, 17, 0, 7); ctx.fill();
                ctx.fillStyle = '#fff'; ctx.font = '700 18px Poppins, sans-serif'; ctx.fillText('−', 30, H - 30); ctx.fillText('+', 76, H - 30);
                if (jo){
                    ctx.strokeStyle = 'rgba(255,255,255,.35)'; ctx.lineWidth = 2; ctx.beginPath(); ctx.arc(jo.x, jo.y, 40, 0, 7); ctx.stroke();
                    ctx.fillStyle = 'rgba(255,255,255,.4)'; ctx.beginPath(); ctx.arc(jo.x + jv.x * 40, jo.y + jv.y * 40, 16, 0, 7); ctx.fill();
                }
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.18)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   GRAVITY FLIP — run on the floor, flip to the ceiling, dodge everything
══════════════════════════════════════════════════════════════ */
GAMES.gravflip = {
    name:'Gravity Flip', ovIcon:'flip',
    help:'Tap, click or press Space / ↑ / ↓ to flip gravity between the floor and the ceiling. Dodge the spikes and blocks, grab the orbs.',
    make:function(api, ctx){
        var W = 480, H = 320, PX = 110, SZ = 24, CL = 34, FL = H - 34, GRAV = 2700;
        var y, vy, dir, grounded, rot, speed, distPx, dist, orbsN, sc, obs, orbs, lastEnd, lastSide, parts, trail, shake, flipBuf, scroll, tT, trailT, lastDraw;

        function spawn(){
            var type = Math.random() < 0.55 ? 'spike' : 'block', sd = Math.random() < 0.5 ? 1 : -1;
            var gap = (sd === lastSide ? speed * 0.62 : speed * 1.0) + 120 + Math.random() * 170;
            var x = lastEnd + gap, o = { t:type, sd:sd, x:x }, i;
            if (type === 'spike'){ o.w = (1 + rand(3)) * 20; o.h = 22; }
            else { o.w = 30 + rand(20); o.h = 40 + rand(Math.min(70, 34 + Math.floor(dist / 12))); }
            lastEnd = x + o.w; lastSide = sd; obs.push(o);
            /* orbs wait on the safe side, just before the obstacle: flip early to grab them */
            for (i = 0; i < 3; i++) orbs.push({ x:x - 140 + i * 36, sd:-sd, got:false, ph:Math.random() * 6 });
        }
        function flip(){
            dir = -dir; vy = dir * 160; flipBuf = 0; grounded = false;
            api.sfx('jump');
            for (var i = 0; i < 8; i++) parts.push({ x:scroll + PX + SZ / 2, y:y + SZ / 2, vx:-60 - Math.random() * 120, vy:(Math.random() - 0.5) * 160, life:0.4, max:0.4, col:'#ffb36b', r:2 + Math.random() * 2 });
        }
        function hit(o){
            var px0 = scroll + PX + 4, px1 = scroll + PX + SZ - 4, py0 = y + 4, py1 = y + SZ - 4;
            var ox0 = o.x + (o.t === 'spike' ? 4 : 0), ox1 = o.x + o.w - (o.t === 'spike' ? 4 : 0);
            var hh = o.t === 'spike' ? o.h * 0.75 : o.h;
            var oy0 = o.sd > 0 ? FL - hh : CL, oy1 = o.sd > 0 ? FL : CL + hh;
            return px1 > ox0 && px0 < ox1 && py1 > oy0 && py0 < oy1;
        }
        function slab(top){
            var gy = top ? 0 : FL, gh = top ? CL : H - FL;
            var g = ctx.createLinearGradient(0, gy, 0, gy + gh);
            g.addColorStop(top ? 0 : 1, '#0b0c2e'); g.addColorStop(top ? 1 : 0, '#23275e');
            ctx.fillStyle = g; ctx.fillRect(0, gy, W, gh);
            ctx.fillStyle = '#ff2d2d'; ctx.fillRect(0, top ? CL - 3 : FL, W, 3);
            ctx.fillStyle = 'rgba(255,45,45,.3)';
            for (var x = -(scroll % 40); x < W; x += 40) ctx.fillRect(x, top ? CL - 12 : FL + 8, 18, 3);
        }
        function spikes(o, sx){
            var n = Math.round(o.w / 20), i;
            ctx.fillStyle = '#ff4d8d'; ctx.strokeStyle = '#ffd1e3'; ctx.lineWidth = 1.5;
            for (i = 0; i < n; i++){
                var bx = sx + i * 20;
                ctx.beginPath();
                if (o.sd > 0){ ctx.moveTo(bx, FL); ctx.lineTo(bx + 10, FL - o.h); ctx.lineTo(bx + 20, FL); }
                else { ctx.moveTo(bx, CL); ctx.lineTo(bx + 10, CL + o.h); ctx.lineTo(bx + 20, CL); }
                ctx.closePath(); ctx.fill(); ctx.stroke();
            }
        }
        function block(o, sx){
            var by = o.sd > 0 ? FL - o.h : CL;
            var g = ctx.createLinearGradient(sx, 0, sx + o.w, 0);
            g.addColorStop(0, '#3a2bb8'); g.addColorStop(0.5, '#6a54ff'); g.addColorStop(1, '#3a2bb8');
            ctx.fillStyle = g; ctx.fillRect(sx, by, o.w, o.h);
            ctx.strokeStyle = '#bfb3ff'; ctx.lineWidth = 2; ctx.strokeRect(sx + 1, by + 1, o.w - 2, o.h - 2);
            ctx.fillStyle = 'rgba(255,255,255,.12)'; ctx.fillRect(sx + 4, by + 4, 5, o.h - 8);
            ctx.fillStyle = '#ff2d2d';
            if (o.sd > 0){ ctx.beginPath(); ctx.moveTo(sx, by); ctx.lineTo(sx + o.w / 2, by - 9); ctx.lineTo(sx + o.w, by); ctx.fill(); }
            else { ctx.beginPath(); ctx.moveTo(sx, by + o.h); ctx.lineTo(sx + o.w / 2, by + o.h + 9); ctx.lineTo(sx + o.w, by + o.h); ctx.fill(); }
        }
        function player(){
            var cx = PX + SZ / 2, cy = y + SZ / 2;
            ctx.save(); ctx.translate(cx, cy); ctx.rotate(rot);
            ctx.fillStyle = '#c97b34'; ctx.strokeStyle = '#6b3a14'; ctx.lineWidth = 2;
            rrect(ctx, -SZ / 2, -SZ / 2, SZ, SZ, 7); ctx.fill(); ctx.stroke();
            ctx.fillStyle = '#a5642c'; ctx.beginPath(); ctx.arc(-6, -SZ / 2 - 1, 3.4, 0, 7); ctx.fill();
            ctx.fillStyle = '#d98c3f'; rrect(ctx, 1, -2, 11, 10, 4); ctx.fill();
            ctx.fillStyle = '#4a2208'; ctx.fillRect(9, 1, 4, 5);
            ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(5, -6, 3.2, 0, 7); ctx.fill();
            ctx.fillStyle = '#111'; ctx.beginPath(); ctx.arc(6, -6, 1.5, 0, 7); ctx.fill();
            ctx.restore();
        }

        return {
            W:W, H:H, swipe:false, instant:true,
            resize:function(asp){
                var nh = Math.round(Math.max(300, Math.min(460, W / asp)));
                if (nh === H) return false;
                H = nh; FL = H - 34; this.H = H; return true;
            },
            reset:function(){
                y = FL - SZ; vy = 0; dir = 1; grounded = true; rot = 0; speed = 310; distPx = 0; dist = 0; orbsN = 0; sc = 0;
                obs = []; orbs = []; lastEnd = 520; lastSide = 0; parts = []; trail = []; shake = 0; flipBuf = 0; scroll = 0; tT = 0; trailT = 0; lastDraw = 0;
                while (lastEnd < 1500) spawn();
            },
            preview:function(){
                this.reset(); scroll = 330; dist = 13; speed = 380; y = CL; dir = -1; rot = Math.PI; grounded = true; distPx = 330;
                parts = []; trail = [{ x:PX - 30, y:CL, a:0.3 }, { x:PX - 60, y:CL, a:0.18 }];
            },
            dir:function(){ this.tap(); },
            tap:function(){ flipBuf = 0.14; if (grounded) flip(); },
            update:function(dt){
                var i;
                tT += dt;
                speed = Math.min(650, 310 + dist * 0.55);
                scroll += speed * dt; distPx += speed * dt; dist = Math.floor(distPx / 25);
                var wasG = grounded;
                vy += dir * GRAV * dt; y += vy * dt; grounded = false;
                if (dir > 0 && y >= FL - SZ){ y = FL - SZ; if (!wasG && Math.abs(vy) > 300){ api.sfx('land'); for (i = 0; i < 5; i++) parts.push({ x:scroll + PX + SZ / 2, y:FL, vx:-80 - Math.random() * 120, vy:-30 - Math.random() * 60, life:0.35, max:0.35, col:'#fff', r:2 }); } vy = 0; grounded = true; }
                else if (dir < 0 && y <= CL){ y = CL; if (!wasG && Math.abs(vy) > 300){ api.sfx('land'); for (i = 0; i < 5; i++) parts.push({ x:scroll + PX + SZ / 2, y:CL, vx:-80 - Math.random() * 120, vy:30 + Math.random() * 60, life:0.35, max:0.35, col:'#fff', r:2 }); } vy = 0; grounded = true; }
                if (flipBuf > 0){ flipBuf -= dt; if (grounded) flip(); }
                rot += ((dir > 0 ? 0 : Math.PI) - rot) * Math.min(1, dt * 16);
                while (lastEnd < scroll + W + 700) spawn();
                trailT -= dt; if (trailT <= 0){ trailT = 0.04; trail.push({ x:scroll + PX, y:y, a:0.35 }); if (trail.length > 8) trail.shift(); }
                for (i = obs.length - 1; i >= 0; i--){
                    var o = obs[i];
                    if (o.x + o.w < scroll - 40){ obs.splice(i, 1); continue; }
                    if (hit(o)){ shake = 0.6; for (var k = 0; k < 24; k++) parts.push({ x:scroll + PX + SZ / 2, y:y + SZ / 2, vx:(Math.random() - 0.5) * 360, vy:(Math.random() - 0.5) * 360, life:0.7, max:0.7, col:['#ff2d2d', '#ffb36b', '#fff'][rand(3)], r:2 + Math.random() * 3 }); api.end({ title:'Crashed!', sub:dist + ' m  ·  ' + orbsN + ' orbs' }); return; }
                }
                var cx = scroll + PX + SZ / 2, cy = y + SZ / 2;
                for (i = orbs.length - 1; i >= 0; i--){
                    var ob = orbs[i];
                    if (ob.x < scroll - 40){ orbs.splice(i, 1); continue; }
                    if (ob.got) continue;
                    var oy = ob.sd > 0 ? FL - 20 : CL + 20;
                    if (Math.abs(ob.x - cx) < 20 && Math.abs(oy - cy) < 22){ ob.got = true; orbsN++; api.sfx('pop'); for (var q = 0; q < 6; q++) parts.push({ x:ob.x, y:oy, vx:(Math.random() - 0.5) * 200, vy:(Math.random() - 0.5) * 200, life:0.4, max:0.4, col:'#ffd400', r:2 }); }
                }
                var s2 = dist + orbsN * 5;
                if (s2 !== sc){ sc = s2; api.setScore(sc); }
            },
            draw:function(t, state){
                var dd = (state === 'preview') ? 0 : Math.min(0.05, Math.max(0, t - lastDraw)); lastDraw = t;
                var i, sx;
                if (dd > 0){
                    if (shake > 0) shake = Math.max(0, shake - dd * 1.4);
                    for (i = parts.length - 1; i >= 0; i--){ var p = parts[i]; p.x += p.vx * dd; p.y += p.vy * dd; p.life -= dd; if (p.life <= 0) parts.splice(i, 1); }
                    for (i = 0; i < trail.length; i++) trail[i].a = Math.max(0, trail[i].a - dd * 0.9);
                }
                ctx.save();
                if (shake > 0) ctx.translate((Math.random() - 0.5) * shake * 16, (Math.random() - 0.5) * shake * 16);
                var bg = ctx.createLinearGradient(0, 0, 0, H);
                bg.addColorStop(0, '#07082a'); bg.addColorStop(1, '#1b0f3f');
                ctx.fillStyle = bg; ctx.fillRect(-20, -20, W + 40, H + 40);
                /* parallax: far glowing columns + grid */
                ctx.fillStyle = 'rgba(120,90,255,.08)';
                for (i = 0; i < 9; i++){ var fx = ((i * 140 - scroll * 0.2) % (W + 140) + (W + 140)) % (W + 140) - 70, fh = 60 + (i * 37) % 120; ctx.fillRect(fx, FL - fh, 34, fh); ctx.fillRect(fx + 6, CL, 22, fh * 0.5); }
                ctx.strokeStyle = 'rgba(255,255,255,.05)'; ctx.lineWidth = 1; ctx.beginPath();
                for (i = -1; i < W / 60 + 2; i++){ var gx = i * 60 - (scroll * 0.5 % 60); ctx.moveTo(gx, CL); ctx.lineTo(gx, FL); }
                ctx.stroke();
                slab(true); slab(false);
                /* orbs */
                for (i = 0; i < orbs.length; i++){
                    var ob = orbs[i]; if (ob.got) continue;
                    sx = ob.x - scroll; if (sx < -30 || sx > W + 30) continue;
                    var oy = ob.sd > 0 ? FL - 20 : CL + 20, pu = 1 + Math.sin(t * 6 + ob.ph) * 0.15;
                    ctx.fillStyle = 'rgba(255,212,0,.22)'; ctx.beginPath(); ctx.arc(sx, oy, 13 * pu, 0, 7); ctx.fill();
                    ctx.fillStyle = '#ffd400'; ctx.beginPath(); ctx.arc(sx, oy, 6.5 * pu, 0, 7); ctx.fill();
                    ctx.fillStyle = 'rgba(255,255,255,.7)'; ctx.beginPath(); ctx.arc(sx - 2, oy - 2, 2, 0, 7); ctx.fill();
                }
                /* obstacles */
                for (i = 0; i < obs.length; i++){
                    var o = obs[i]; sx = o.x - scroll; if (sx < -80 || sx > W + 10) continue;
                    if (o.t === 'spike') spikes(o, sx); else block(o, sx);
                }
                /* trail + particles + player */
                for (i = 0; i < trail.length; i++){
                    var tr = trail[i]; if (tr.a <= 0) continue;
                    ctx.globalAlpha = tr.a; ctx.fillStyle = '#ff9a4a'; rrect(ctx, tr.x - scroll, tr.y, SZ, SZ, 7); ctx.fill();
                }
                ctx.globalAlpha = 1;
                for (i = 0; i < parts.length; i++){
                    var q = parts[i]; ctx.globalAlpha = Math.max(0, q.life / q.max); ctx.fillStyle = q.col;
                    ctx.beginPath(); ctx.arc(q.x - scroll, q.y, q.r, 0, 7); ctx.fill();
                }
                ctx.globalAlpha = 1;
                if (!(state === 'over' && shake > 0.3)) player();
                /* gravity arrow hint + distance */
                ctx.fillStyle = 'rgba(255,255,255,.9)'; ctx.font = '700 22px Poppins, sans-serif'; ctx.textAlign = 'center';
                ctx.fillText(dist + ' m', W / 2, CL + 34);
                if (state === 'ready'){ ctx.font = '600 12px Poppins, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,.6)'; ctx.fillText('TAP TO FLIP GRAVITY', W / 2, H / 2); }
                ctx.restore();
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.14)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   CARD PREVIEWS — drawn only when a card scrolls into view
   (local canvas drawing: no images are downloaded)
══════════════════════════════════════════════════════════════ */
var PREV_DONE = {};
function renderPreview(c){
    var key = c.dataset.game; if (PREV_DONE[key]) return; PREV_DONE[key] = 1;
    var G = GAMES[key]; if (!G) return;
    var fake = { held:{}, setScore:function(){}, extra:function(){}, end:function(){}, sfx:function(){} };
    var cx = c.getContext('2d'), g = G.make(fake, cx, document.createElement('div'));
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    if (g.thumb){
        c.width = g.TW * dpr; c.height = g.TH * dpr; cx.setTransform(dpr, 0, 0, dpr, 0, 0);
        g.thumb(cx); return;
    }
    c.width = g.W * dpr; c.height = g.H * dpr; cx.setTransform(dpr, 0, 0, dpr, 0, 0);
    g.reset(); if (g.preview) g.preview();
    g.draw(1.2, 'preview');
}
function initPreviews(){
    var cs = document.querySelectorAll('.gm-thumb canvas');
    function safe(c){ try { renderPreview(c); } catch(e){} }
    /* cards are drawn one at a time so opening the page never locks it up */
    var queue = [], pumping = false;
    function pump(){
        var c = queue.shift();
        if (!c){ pumping = false; return; }
        safe(c); setTimeout(pump, 40);
    }
    function enqueue(c){ queue.push(c); if (!pumping){ pumping = true; setTimeout(pump, 0); } }
    if ('IntersectionObserver' in window){
        var io = new IntersectionObserver(function(es){
            es.forEach(function(e){ if (e.isIntersecting){ io.unobserve(e.target); enqueue(e.target); } });
        }, { rootMargin:'160px' });
        cs.forEach(function(c){ io.observe(c); });
    } else cs.forEach(enqueue);
}
(document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(initPreviews);

/* ══════════════════════════════════════════════════════════════
   games-levels.js — load this RIGHT AFTER games.js (games.js itself is not edited).
   1) Adds a level picker (Easy / Normal / Hard) to every game that did not have one yet.
   2) Adds 4 new games, each with 3 levels: Brick Breaker, Memory Match, Connect 4, Neon Invaders.
   Chess, Sudoku, Minesweeper and Hill Dash already had their own levels and are left alone.
══════════════════════════════════════════════════════════════ */
(function(){
var LV3 = [{ k:'easy', n:'Easy' }, { k:'normal', n:'Normal' }, { k:'hard', n:'Hard' }];
function clamp(v, a, b){ return v < a ? a : v > b ? b : v; }
function fmt(s){ s = Math.floor(s); var m = Math.floor(s / 60), x = s % 60; return (m < 10 ? '0' : '') + m + ':' + (x < 10 ? '0' : '') + x; }
function lvOf(api){ return api.lv || 'normal'; }

/* ══════════════════════════════════════════════════════════════
   BRICK BREAKER
══════════════════════════════════════════════════════════════ */
GAMES.brickbreak = {
    name:'Brick Breaker', ovIcon:'block', diff:LV3,
    help:'Drag to move the paddle (or ← → / A D). Tap or press Space to launch the ball. Clear every brick and keep the ball alive!',
    make:function(api, ctx){
        var W = 360, H = 520, PY = H - 60, PH = 12, BR = 7, COLS = 9, BW = 36, BH = 18, BX0 = 18, BY0 = 64;
        var LVS = { easy:{ pw:100, sp:300, rows:4, lives:4 }, normal:{ pw:76, sp:350, rows:5, lives:3 }, hard:{ pw:58, sp:400, rows:6, lives:3 } };
        var CL = ['#ff3b5c', '#ff9f1a', '#ffd93d', '#35d07f', '#2f9bff', '#b86bff', '#ff7ab6', '#7fe0ff'];
        var px, pw, ball, bricks, sc, lives, wave, stuck, parts, fl, base, speed, rowsN;

        function L(){ return LVS[lvOf(api)]; }
        function hud(){ api.extra('Lives ' + lives + '  ·  Wave ' + wave); }
        function build(){
            bricks = []; rowsN = Math.min(8, L().rows + wave - 1);
            for (var r = 0; r < rowsN; r++) for (var c = 0; c < COLS; c++){
                bricks.push({ c:c, r:r, hp:((lvOf(api) === 'hard' && r === 0) || (wave > 2 && r < 2)) ? 2 : 1 });
            }
        }
        function setSpeed(s){
            speed = s; var m = Math.hypot(ball.vx, ball.vy);
            if (m > 0){ ball.vx *= speed / m; ball.vy *= speed / m; }
        }
        function launch(){
            if (!stuck) return;
            stuck = false; var a = Math.random() * 0.5 - 0.25;
            ball.vx = Math.sin(a) * speed; ball.vy = -Math.cos(a) * speed; api.sfx('whoosh');
        }
        function burst(x, y, col){
            for (var i = 0; i < 7; i++){
                var a = Math.random() * 6.283, v = 40 + Math.random() * 130;
                parts.push({ x:x, y:y, vx:Math.cos(a) * v, vy:Math.sin(a) * v, life:0.4, max:0.4, col:col });
            }
        }
        function loseBall(){
            lives--; api.sfx('hit'); hud(); stuck = true; ball.vx = 0; ball.vy = 0;
            if (lives <= 0) api.end({ title:'Game over', sub:'You reached wave ' + wave + '.' });
        }
        function brickHit(){
            for (var i = 0; i < bricks.length; i++){
                var b = bricks[i], bx = BX0 + b.c * BW, by = BY0 + b.r * BH;
                var cx = clamp(ball.x, bx, bx + BW), cy = clamp(ball.y, by, by + BH), dx = ball.x - cx, dy = ball.y - cy;
                if (dx * dx + dy * dy >= BR * BR) continue;
                if (Math.abs(dx) > Math.abs(dy)) ball.vx = Math.abs(ball.vx) * (dx > 0 ? 1 : -1);
                else ball.vy = Math.abs(ball.vy) * (dy > 0 ? 1 : -1);
                b.hp--;
                if (b.hp <= 0){
                    bricks.splice(i, 1);
                    var pts = 10 + Math.max(0, 3 - b.r) * 4;
                    sc += pts; api.setScore(sc); api.sfx('score'); burst(bx + BW / 2, by + BH / 2, CL[b.r % 8]);
                    fl.push({ x:bx + BW / 2, y:by, t:0.6, txt:'+' + pts });
                    setSpeed(Math.min(base * 1.6, speed + 1.5));
                } else api.sfx('pop');
                return true;
            }
            return false;
        }

        return {
            W:W, H:H, swipe:false, instant:false,
            reset:function(){
                wave = 1; sc = 0; lives = 3; pw = 76; base = 350; speed = 350; px = W / 2; parts = []; fl = [];
                ball = { x:px, y:PY - BR - 1, vx:0, vy:0 }; stuck = true; build();
            },
            preview:function(){
                this.reset(); px = 200; stuck = false; ball.x = 150; ball.y = 330; ball.vx = 150; ball.vy = -300;
                bricks = bricks.filter(function(b){ return !((b.c + b.r * 2) % 7 === 3 && b.r > 1) && !(b.r === 4 && b.c > 5); });
            },
            begin:function(){
                var l = L(); pw = l.pw; base = l.sp; speed = base; lives = l.lives; wave = 1; px = W / 2;
                build(); stuck = true; ball.vx = 0; ball.vy = 0; hud();
            },
            dir:function(){}, tap:function(){ launch(); },
            ptr:function(ty, x){
                if (ty === 'up') return;
                px = clamp(x, pw / 2, W - pw / 2);
                if (ty === 'down') launch();
            },
            update:function(dt){
                var Hh = api.held, i;
                if (Hh.l || Hh.r) px = clamp(px + ((Hh.r ? 1 : 0) - (Hh.l ? 1 : 0)) * 430 * dt, pw / 2, W - pw / 2);
                for (i = parts.length - 1; i >= 0; i--){ var p = parts[i]; p.x += p.vx * dt; p.y += p.vy * dt; p.life -= dt; if (p.life <= 0) parts.splice(i, 1); }
                for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 30 * dt; fl[i].t -= dt; if (fl[i].t <= 0) fl.splice(i, 1); }
                if (stuck){ ball.x = px; ball.y = PY - BR - 1; return; }
                var n = Math.max(1, Math.ceil(speed * dt / 4)), h = dt / n;
                for (var s = 0; s < n; s++){
                    ball.x += ball.vx * h; ball.y += ball.vy * h;
                    if (ball.x < BR){ ball.x = BR; ball.vx = Math.abs(ball.vx); api.sfx('pop'); }
                    else if (ball.x > W - BR){ ball.x = W - BR; ball.vx = -Math.abs(ball.vx); api.sfx('pop'); }
                    if (ball.y < BR){ ball.y = BR; ball.vy = Math.abs(ball.vy); api.sfx('pop'); }
                    if (ball.vy > 0 && ball.y + BR >= PY && ball.y - BR <= PY + PH && Math.abs(ball.x - px) <= pw / 2 + BR){
                        var rel = clamp((ball.x - px) / (pw / 2), -1, 1);
                        ball.vx = Math.sin(rel * 1.0) * speed; ball.vy = -Math.cos(rel * 1.0) * speed; ball.y = PY - BR; api.sfx('place');
                    }
                    if (brickHit() && !bricks.length){
                        wave++; sc += 50; api.setScore(sc); build(); stuck = true; ball.vx = 0; ball.vy = 0;
                        speed = Math.min(base * 1.4, base * (1 + 0.07 * (wave - 1)));
                        fl.push({ x:W / 2, y:H / 2, t:1.3, txt:'WAVE ' + wave + '  +50' }); api.sfx('level'); hud();
                        return;
                    }
                    if (ball.y > H + BR){ loseBall(); return; }
                }
            },
            draw:function(t, state){
                var i, g = ctx.createLinearGradient(0, 0, 0, H);
                g.addColorStop(0, '#080a2e'); g.addColorStop(1, '#170d38');
                ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
                ctx.fillStyle = 'rgba(255,255,255,.35)';
                for (i = 0; i < 24; i++) ctx.fillRect((i * 83) % W, (i * 127) % H, 1.5, 1.5);
                for (i = 0; i < bricks.length; i++){
                    var b = bricks[i], x = BX0 + b.c * BW, y = BY0 + b.r * BH;
                    ctx.fillStyle = CL[b.r % 8]; rrect(ctx, x + 1, y + 1, BW - 2, BH - 2, 4); ctx.fill();
                    ctx.fillStyle = 'rgba(255,255,255,.28)'; ctx.fillRect(x + 4, y + 3, BW - 8, 3);
                    if (b.hp > 1){ ctx.strokeStyle = '#fff'; ctx.lineWidth = 2; rrect(ctx, x + 2, y + 2, BW - 4, BH - 4, 3); ctx.stroke(); }
                }
                ctx.fillStyle = '#ff2d2d'; rrect(ctx, px - pw / 2, PY, pw, PH, 6); ctx.fill();
                ctx.fillStyle = 'rgba(255,255,255,.4)'; ctx.fillRect(px - pw / 2 + 6, PY + 2, pw - 12, 3);
                ctx.save(); ctx.shadowColor = '#fff'; ctx.shadowBlur = 12; ctx.fillStyle = '#fff';
                ctx.beginPath(); ctx.arc(ball.x, ball.y, BR, 0, 7); ctx.fill(); ctx.restore();
                for (i = 0; i < parts.length; i++){
                    ctx.globalAlpha = Math.max(0, parts[i].life / parts[i].max); ctx.fillStyle = parts[i].col;
                    ctx.fillRect(parts[i].x - 2, parts[i].y - 2, 4, 4);
                }
                ctx.globalAlpha = 1;
                ctx.textAlign = 'center'; ctx.font = '700 15px Poppins, sans-serif';
                for (i = 0; i < fl.length; i++){ ctx.globalAlpha = Math.min(1, fl[i].t * 1.6); ctx.fillStyle = '#fff'; ctx.fillText(fl[i].txt, fl[i].x, fl[i].y); }
                ctx.globalAlpha = 1;
                ctx.font = '700 12px Poppins, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,.7)'; ctx.textAlign = 'left';
                ctx.fillText('♥ ' + lives, 14, 24); ctx.textAlign = 'right'; ctx.fillText('Wave ' + wave, W - 14, 24);
                if (stuck && state === 'playing'){ ctx.textAlign = 'center'; ctx.fillStyle = 'rgba(255,255,255,.6)'; ctx.fillText('TAP TO LAUNCH', W / 2, PY - 40); }
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.14)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   MEMORY MATCH
══════════════════════════════════════════════════════════════ */
GAMES.memory = {
    name:'Memory Match', ovIcon:'paw', diff:LV3,
    help:'Tap two cards to flip them and find every matching pair. Fewer mistakes and a faster time score higher.',
    make:function(api, ctx){
        var W = 360, H = 440, GAP = 10;
        var LVS = { easy:{ c:4, r:3, par:45 }, normal:{ c:4, r:4, par:75 }, hard:{ c:5, r:4, par:105 } };
        var COL = ['#ff3b5c', '#ff9f1a', '#d4a800', '#35d07f', '#2f9bff', '#b86bff', '#ff5fa8', '#12b5d6', '#ff6a3d', '#7aa81f'];
        var cards, cfg, cw, ch, gx, gy, first, second, pend, moves, mist, elapsed, done, lastSec, started, sc, fl;

        function star(cx, cy, r){ ctx.beginPath(); for (var i = 0; i < 10; i++){ var a = -Math.PI / 2 + i * Math.PI / 5, rr = i % 2 ? r * 0.45 : r; ctx.lineTo(cx + Math.cos(a) * rr, cy + Math.sin(a) * rr); } ctx.closePath(); ctx.fill(); }
        function poly(cx, cy, r, n, rot){ ctx.beginPath(); for (var i = 0; i < n; i++){ var a = rot + i * 6.2832 / n; ctx.lineTo(cx + Math.cos(a) * r, cy + Math.sin(a) * r); } ctx.closePath(); ctx.fill(); }
        function sym(i, x, y, r){
            ctx.fillStyle = COL[i % 10];
            switch (i % 10){
                case 0: ctx.beginPath(); ctx.arc(x, y, r, 0, 7); ctx.fill(); break;
                case 1: rrect(ctx, x - r, y - r, r * 2, r * 2, r * 0.3); ctx.fill(); break;
                case 2: poly(x, y + r * 0.1, r * 1.15, 3, -Math.PI / 2); break;
                case 3: star(x, y, r * 1.2); break;
                case 4:
                    ctx.beginPath(); ctx.moveTo(x, y + r * 0.95);
                    ctx.bezierCurveTo(x - r * 1.5, y - r * 0.1, x - r * 0.6, y - r * 1.2, x, y - r * 0.4);
                    ctx.bezierCurveTo(x + r * 0.6, y - r * 1.2, x + r * 1.5, y - r * 0.1, x, y + r * 0.95); ctx.fill(); break;
                case 5: poly(x, y, r * 1.2, 4, -Math.PI / 2); break;
                case 6: ctx.fillRect(x - r * 0.35, y - r, r * 0.7, r * 2); ctx.fillRect(x - r, y - r * 0.35, r * 2, r * 0.7); break;
                case 7: ctx.beginPath(); ctx.arc(x, y, r, 0, 7); ctx.fill(); ctx.fillStyle = '#eceaff'; ctx.beginPath(); ctx.arc(x + r * 0.5, y - r * 0.2, r * 0.85, 0, 7); ctx.fill(); break;
                case 8: poly(x, y, r * 1.1, 6, 0); break;
                default: ctx.beginPath(); ctx.moveTo(x + r * 0.3, y - r * 1.1); ctx.lineTo(x - r * 0.8, y + r * 0.2); ctx.lineTo(x - r * 0.05, y + r * 0.2); ctx.lineTo(x - r * 0.3, y + r * 1.1); ctx.lineTo(x + r * 0.8, y - r * 0.2); ctx.lineTo(x + r * 0.05, y - r * 0.2); ctx.closePath(); ctx.fill();
            }
        }
        function setup(){
            cfg = LVS[lvOf(api)]; var pairs = cfg.c * cfg.r / 2, i, deck = [];
            for (i = 0; i < pairs; i++){ deck.push(i, i); }
            for (i = deck.length - 1; i > 0; i--){ var j = rand(i + 1), t = deck[i]; deck[i] = deck[j]; deck[j] = t; }
            cards = deck.map(function(s){ return { sym:s, f:0, up:false, done:false }; });
            cw = (W - 24 - GAP * (cfg.c - 1)) / cfg.c;
            ch = Math.min(cw * 1.2, (H - 120 - GAP * (cfg.r - 1)) / cfg.r);
            var total = cfg.r * ch + GAP * (cfg.r - 1);
            gx = 12; gy = 64 + (H - 64 - 14 - total) / 2;
            first = second = null; pend = 0; moves = 0; mist = 0; elapsed = 0; done = 0; lastSec = -1; sc = 0; fl = [];
        }
        function hud(){ api.extra('Moves ' + moves + '  ·  ' + fmt(elapsed)); }
        function cardAt(x, y){
            for (var i = 0; i < cards.length; i++){
                var cx = gx + (i % cfg.c) * (cw + GAP), cy = gy + Math.floor(i / cfg.c) * (ch + GAP);
                if (x >= cx && x <= cx + cw && y >= cy && y <= cy + ch) return cards[i];
            }
            return null;
        }
        function judge(){
            moves++;
            if (first.sym === second.sym){
                first.done = second.done = true; done++; sc = done * 20; api.setScore(sc); api.sfx('score');
                fl.push({ x:W / 2, y:44, t:0.8, txt:'Pair!' });
                first = second = null;
                if (done >= cards.length / 2){
                    var bonus = clamp(Math.round((cfg.par - elapsed) / cfg.par * 80), 0, 80);
                    var fin = Math.max(done * 10, done * 20 + bonus - mist * 2);
                    api.setScore(fin);
                    api.end({ win:true, title:'All matched!', sub:moves + ' moves  ·  ' + fmt(elapsed) + '  ·  ' + mist + ' mistakes' });
                }
            } else { mist++; api.sfx('wrong'); pend = -0.5; }
            hud();
        }

        return {
            W:W, H:H, swipe:false, instant:false,
            reset:function(){ setup(); started = false; },
            preview:function(){
                this.reset();
                [0, 3, 6, 9].forEach(function(i){ cards[i].up = true; cards[i].f = 1; });
                cards[1].up = cards[1].f = 1; cards[1].done = true; cards[2].done = true; cards[2].up = true; cards[2].f = 1;
            },
            begin:function(){ setup(); started = true; hud(); },
            dir:function(){}, tap:function(){},
            ptr:function(ty, x, y){
                if (ty !== 'down' || pend !== 0 || !started) return;
                var c = cardAt(x, y);
                if (!c || c.up || c.done) return;
                c.up = true; api.sfx('pop');
                if (!first) first = c; else { second = c; pend = 0.45; }
            },
            update:function(dt){
                var i;
                elapsed += dt; var s = Math.floor(elapsed); if (s !== lastSec){ lastSec = s; hud(); }
                for (i = 0; i < cards.length; i++){
                    var c = cards[i], tg = c.up ? 1 : 0;
                    if (c.f < tg) c.f = Math.min(tg, c.f + dt * 6); else if (c.f > tg) c.f = Math.max(tg, c.f - dt * 6);
                }
                if (pend > 0){ pend -= dt; if (pend <= 0){ pend = 0; judge(); } }
                else if (pend < 0){ pend += dt; if (pend >= 0){ pend = 0; if (first){ first.up = false; } if (second){ second.up = false; } first = second = null; } }
                for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 20 * dt; fl[i].t -= dt; if (fl[i].t <= 0) fl.splice(i, 1); }
            },
            draw:function(t, state){
                ctx.fillStyle = '#070924'; ctx.fillRect(0, 0, W, H);
                for (var i = 0; i < cards.length; i++){
                    var c = cards[i], x = gx + (i % cfg.c) * (cw + GAP), y = gy + Math.floor(i / cfg.c) * (ch + GAP);
                    var s = Math.max(0.02, Math.abs(Math.cos(c.f * Math.PI))), face = c.f > 0.5, w = cw * s, cx = x + cw / 2, cy = y + ch / 2;
                    ctx.save(); ctx.globalAlpha = c.done ? 0.6 : 1;
                    ctx.fillStyle = face ? '#eceaff' : '#ff2d2d'; rrect(ctx, cx - w / 2, y, w, ch, 10); ctx.fill();
                    ctx.save(); ctx.translate(cx, cy); ctx.scale(s, 1);
                    if (face) sym(c.sym, 0, 0, Math.min(cw, ch) * 0.28);
                    else { ctx.fillStyle = 'rgba(255,255,255,.3)'; poly(0, 0, Math.min(cw, ch) * 0.2, 4, -Math.PI / 2); }
                    ctx.restore();
                    if (c.done && face){ ctx.strokeStyle = '#35d07f'; ctx.lineWidth = 3; rrect(ctx, cx - w / 2, y, w, ch, 10); ctx.stroke(); }
                    ctx.restore();
                }
                ctx.textAlign = 'center'; ctx.font = '700 16px Poppins, sans-serif';
                for (i = 0; i < fl.length; i++){ ctx.globalAlpha = Math.min(1, fl[i].t * 1.6); ctx.fillStyle = '#35d07f'; ctx.fillText(fl[i].txt, fl[i].x, fl[i].y); }
                ctx.globalAlpha = 1;
                ctx.font = '600 12px Poppins, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,.6)';
                ctx.fillText('Pairs ' + done + ' / ' + cards.length / 2 + '   ·   Mistakes ' + mist, W / 2, 34);
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.1)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   CONNECT 4  (you are red, the computer is yellow)
══════════════════════════════════════════════════════════════ */
GAMES.connect4 = {
    name:'Connect 4', ovIcon:'bubble', diff:LV3,
    help:'You are red and you go first. Tap a column to drop a disc. Connect four in a row before the computer does.',
    make:function(api, ctx){
        var W = 360, H = 420, CS = 48, BX = 12, BY = 72, C = 7, R = 6;
        var DEP = { easy:1, normal:3, hard:5 }, RND = { easy:0.35, normal:0.08, hard:0 }, BASE = { easy:100, normal:200, hard:300 };
        var ORD = [3, 2, 4, 1, 5, 0, 6], WINS = [];
        var b, turn, anim, hover, over, hl, myMoves, wait, endT, ended, result, i, j;
        for (i = 0; i < R; i++) for (j = 0; j < C; j++){
            if (j <= 3) WINS.push([i * C + j, i * C + j + 1, i * C + j + 2, i * C + j + 3]);
            if (i <= 2) WINS.push([i * C + j, (i + 1) * C + j, (i + 2) * C + j, (i + 3) * C + j]);
            if (i <= 2 && j <= 3) WINS.push([i * C + j, (i + 1) * C + j + 1, (i + 2) * C + j + 2, (i + 3) * C + j + 3]);
            if (i <= 2 && j >= 3) WINS.push([i * C + j, (i + 1) * C + j - 1, (i + 2) * C + j - 2, (i + 3) * C + j - 3]);
        }
        function lowest(c){ for (var r = R - 1; r >= 0; r--) if (!b[r * C + c]) return r; return -1; }
        function winLine(){
            for (var k = 0; k < WINS.length; k++){
                var w = WINS[k], a = b[w[0]];
                if (a && a === b[w[1]] && a === b[w[2]] && a === b[w[3]]) return { who:a, line:w };
            }
            return null;
        }
        function full(){ for (var c = 0; c < C; c++) if (!b[c]) return false; return true; }
        function evalB(p){
            var s = 0, o = 3 - p, k, w, mine, theirs, r;
            for (k = 0; k < WINS.length; k++){
                w = WINS[k]; mine = theirs = 0;
                for (var q = 0; q < 4; q++){ if (b[w[q]] === p) mine++; else if (b[w[q]] === o) theirs++; }
                if (mine && theirs) continue;
                if (mine === 3) s += 5; else if (mine === 2) s += 2;
                if (theirs === 3) s -= 4; else if (theirs === 2) s -= 1;
            }
            for (r = 0; r < R; r++){ if (b[r * C + 3] === p) s += 3; else if (b[r * C + 3] === o) s -= 3; }
            return s;
        }
        function nega(d, a, be, p){
            if (winLine()) return -(100000 + d);
            if (d === 0) return evalB(p);
            var any = false;
            for (var k = 0; k < 7; k++){
                var c = ORD[k], r = lowest(c); if (r < 0) continue;
                any = true; b[r * C + c] = p;
                var v = -nega(d - 1, -be, -a, 3 - p);
                b[r * C + c] = 0;
                if (v > a) a = v;
                if (a >= be) break;
            }
            return any ? a : 0;
        }
        function aiPick(){
            var lv = lvOf(api), cols = [], c, k;
            for (c = 0; c < C; c++) if (lowest(c) >= 0) cols.push(c);
            if (!cols.length) return -1;
            if (Math.random() < RND[lv]) return cols[rand(cols.length)];
            var best = -1e9, bc = cols[0];
            for (k = 0; k < 7; k++){
                c = ORD[k]; var r = lowest(c); if (r < 0) continue;
                b[r * C + c] = 2;
                var v = winLine() ? 1e6 : -nega(DEP[lv] - 1, -1e9, 1e9, 1);
                b[r * C + c] = 0;
                v += Math.random() * 0.4;
                if (v > best){ best = v; bc = c; }
            }
            return bc;
        }
        function drop(c, who){
            var r = lowest(c); if (r < 0) return false;
            anim = { c:c, r:r, y:BY - 30, vy:0, who:who }; if (who === 1) myMoves++; return true;
        }
        function land(){
            b[anim.r * C + anim.c] = anim.who; api.sfx('thud'); anim = null;
            var w = winLine();
            if (w){ over = true; hl = w.line; result = w.who === 1 ? 'win' : 'loss'; endT = 0.9; }
            else if (full()){ over = true; result = 'draw'; endT = 0.5; }
            else { turn = turn === 'h' ? 'ai' : 'h'; wait = 0.35; api.extra(turn === 'h' ? 'Your turn' : 'Thinking…'); }
        }

        return {
            W:W, H:H, swipe:false, instant:false,
            reset:function(){
                b = []; for (var k = 0; k < R * C; k++) b.push(0);
                turn = 'h'; anim = null; hover = -1; over = false; hl = null; myMoves = 0; wait = 0; endT = 0; ended = false; result = '';
            },
            preview:function(){
                this.reset();
                var seq = [3, 3, 4, 2, 4, 4, 5, 3, 2, 2], who = 1;
                seq.forEach(function(c){ var r = lowest(c); if (r >= 0) b[r * C + c] = who; who = 3 - who; });
            },
            begin:function(){ api.extra('Your turn'); },
            dir:function(){}, tap:function(){},
            ptr:function(ty, x){
                var c = Math.floor((x - BX) / CS);
                if (c < 0 || c >= C) { if (ty === 'move') hover = -1; return; }
                if (ty === 'move'){ hover = c; return; }
                if (ty !== 'down' || turn !== 'h' || anim || over) return;
                hover = c;
                if (!drop(c, 1)) api.sfx('wrong');
            },
            update:function(dt){
                if (anim){
                    anim.vy += 2400 * dt; anim.y += anim.vy * dt;
                    var ty = BY + anim.r * CS + CS / 2;
                    if (anim.y >= ty){ anim.y = ty; land(); }
                    return;
                }
                if (over){
                    endT -= dt;
                    if (endT <= 0 && !ended){
                        ended = true;
                        var base = BASE[lvOf(api)];
                        if (result === 'win'){
                            var s = base + Math.max(0, 24 - myMoves) * 5; api.setScore(s);
                            api.end({ win:true, title:'You win!', sub:'Four in a row in ' + myMoves + ' moves.' });
                        } else if (result === 'draw'){
                            api.setScore(Math.round(base / 3)); api.end({ title:'It’s a draw', sub:'The board is full.' });
                        } else { api.setScore(0); api.end({ title:'The computer wins', sub:'Try again, or pick an easier level.' }); }
                    }
                    return;
                }
                if (turn === 'ai'){ wait -= dt; if (wait <= 0){ var c = aiPick(); if (c >= 0) drop(c, 2); } }
            },
            draw:function(t, state){
                var r, c, k;
                ctx.fillStyle = '#070924'; ctx.fillRect(0, 0, W, H);
                ctx.textAlign = 'center'; ctx.font = '700 15px Poppins, sans-serif'; ctx.fillStyle = '#fff';
                ctx.fillText(over ? (result === 'win' ? 'You win!' : result === 'draw' ? 'Draw' : 'Computer wins') : (turn === 'h' ? 'Your turn' : 'Thinking…'), W / 2, 30);
                if (turn === 'h' && !anim && !over && hover >= 0){
                    ctx.globalAlpha = 0.55; ctx.fillStyle = '#ff2d2d'; ctx.beginPath(); ctx.arc(BX + hover * CS + CS / 2, BY - 22, 17, 0, 7); ctx.fill(); ctx.globalAlpha = 1;
                }
                ctx.fillStyle = '#1a1e55'; rrect(ctx, BX - 6, BY - 6, C * CS + 12, R * CS + 12, 14); ctx.fill();
                for (r = 0; r < R; r++) for (c = 0; c < C; c++){
                    var cx = BX + c * CS + CS / 2, cy = BY + r * CS + CS / 2, v = b[r * C + c];
                    ctx.fillStyle = v === 1 ? '#ff2d2d' : v === 2 ? '#ffd400' : '#070924';
                    ctx.beginPath(); ctx.arc(cx, cy, 18, 0, 7); ctx.fill();
                    if (v){ ctx.fillStyle = 'rgba(255,255,255,.28)'; ctx.beginPath(); ctx.arc(cx - 5, cy - 5, 6, 0, 7); ctx.fill(); }
                }
                if (hl){
                    ctx.strokeStyle = '#fff'; ctx.lineWidth = 3 + Math.sin(t * 8);
                    for (k = 0; k < 4; k++){ var q = hl[k]; ctx.beginPath(); ctx.arc(BX + (q % C) * CS + CS / 2, BY + Math.floor(q / C) * CS + CS / 2, 20, 0, 7); ctx.stroke(); }
                }
                if (anim){ ctx.fillStyle = anim.who === 1 ? '#ff2d2d' : '#ffd400'; ctx.beginPath(); ctx.arc(BX + anim.c * CS + CS / 2, anim.y, 18, 0, 7); ctx.fill(); }
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.1)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   NEON INVADERS
══════════════════════════════════════════════════════════════ */
GAMES.invaders = {
    name:'Neon Invaders', ovIcon:'ghost', diff:LV3,
    help:'Drag to move your ship (or ← → / A D). It fires by itself. Shoot every invader before they reach the bottom!',
    make:function(api, ctx){
        var W = 360, H = 520, PY = H - 48, COLS = 8, GX = 34, GY = 26;
        var LVS = { easy:{ rows:3, sp:22, bomb:0.35, lives:4, fire:0.3 }, normal:{ rows:4, sp:30, bomb:0.55, lives:3, fire:0.35 }, hard:{ rows:5, sp:40, bomb:0.8, lives:3, fire:0.4 } };
        var RC = ['#ff3b5c', '#ff9f1a', '#35d07f', '#2f9bff', '#b86bff', '#ff7ab6'];
        var px, en, gx, gy, dir, shots, bombs, lives, sc, wave, cd, inv, parts, fl, total, alive, tA, bombT;

        function L(){ return LVS[lvOf(api)]; }
        function hud(){ api.extra('Lives ' + lives + '  ·  Wave ' + wave); }
        function build(){
            en = []; var rows = Math.min(6, L().rows + Math.floor((wave - 1) / 2));
            for (var r = 0; r < rows; r++) for (var c = 0; c < COLS; c++) en.push({ c:c, r:r, alive:true });
            total = alive = en.length; gx = 20; gy = 66; dir = 1; shots = []; bombs = [];
        }
        function burst(x, y, col){
            for (var i = 0; i < 8; i++){ var a = Math.random() * 6.283, v = 40 + Math.random() * 130; parts.push({ x:x, y:y, vx:Math.cos(a) * v, vy:Math.sin(a) * v, life:0.45, max:0.45, col:col }); }
        }
        function hit(){
            if (inv > 0) return;
            lives--; inv = 1.5; api.sfx('hit'); hud(); burst(px, PY, '#ff2d2d');
            if (lives <= 0) api.end({ title:'Earth has fallen', sub:'You reached wave ' + wave + '.' });
        }

        return {
            W:W, H:H, swipe:false, instant:false,
            reset:function(){
                wave = 1; sc = 0; lives = 3; px = W / 2; cd = 0.3; inv = 0; parts = []; fl = []; tA = 0; bombT = 1.5; build();
            },
            preview:function(){
                this.reset();
                en.forEach(function(e){ if ((e.c * 3 + e.r) % 7 === 2) e.alive = false; });
                alive = en.filter(function(e){ return e.alive; }).length; gx = 40; gy = 90;
                shots = [{ x:px, y:380 }, { x:px, y:300 }]; bombs = [{ x:120, y:300 }, { x:250, y:360 }];
            },
            begin:function(){ var l = L(); lives = l.lives; wave = 1; sc = 0; build(); cd = 0.3; inv = 0; hud(); },
            dir:function(){}, tap:function(){ if (cd > 0.1) cd = 0; },
            ptr:function(ty, x){ if (ty !== 'up') px = clamp(x, 16, W - 16); },
            update:function(dt){
                var l = L(), Hh = api.held, i, j, e, s, b;
                tA += dt; if (inv > 0) inv -= dt;
                if (Hh.l || Hh.r) px = clamp(px + ((Hh.r ? 1 : 0) - (Hh.l ? 1 : 0)) * 320 * dt, 16, W - 16);
                cd -= dt; if (cd <= 0){ shots.push({ x:px, y:PY - 14 }); cd = l.fire; }
                /* formation */
                var minC = 99, maxC = -1, maxY = 0;
                for (i = 0; i < en.length; i++){ e = en[i]; if (!e.alive) continue; if (e.c < minC) minC = e.c; if (e.c > maxC) maxC = e.c; var yy = gy + e.r * GY; if (yy > maxY) maxY = yy; }
                var spd = (l.sp + (wave - 1) * 4) * (1 + (1 - alive / total) * 1.8);
                gx += dir * spd * dt;
                if (alive > 0){
                    var left = gx + minC * GX, right = gx + maxC * GX + 24;
                    if (dir > 0 && right > W - 8){ dir = -1; gy += 14; }
                    else if (dir < 0 && left < 8){ dir = 1; gy += 14; }
                    if (maxY + 18 >= PY - 8){ lives = 0; api.sfx('hit'); api.end({ title:'They landed!', sub:'The invaders reached the ground at wave ' + wave + '.' }); return; }
                }
                /* bombs from the front line */
                bombT -= dt;
                if (bombT <= 0 && alive > 0){
                    bombT = (0.6 + Math.random() * 0.8) / (l.bomb * (1 + wave * 0.1));
                    var col = rand(COLS), low = null;
                    for (i = 0; i < en.length; i++){ e = en[i]; if (e.alive && e.c === col && (!low || e.r > low.r)) low = e; }
                    if (low) bombs.push({ x:gx + low.c * GX + 12, y:gy + low.r * GY + 16 });
                }
                for (i = shots.length - 1; i >= 0; i--){ shots[i].y -= 520 * dt; if (shots[i].y < -10) shots.splice(i, 1); }
                for (i = bombs.length - 1; i >= 0; i--){
                    b = bombs[i]; b.y += (170 + wave * 10) * dt;
                    if (b.y > H + 10){ bombs.splice(i, 1); continue; }
                    if (Math.abs(b.x - px) < 15 && b.y > PY - 10 && b.y < PY + 12){ bombs.splice(i, 1); hit(); if (lives <= 0) return; }
                }
                /* shots vs invaders */
                for (i = shots.length - 1; i >= 0; i--){
                    s = shots[i];
                    for (j = 0; j < en.length; j++){
                        e = en[j]; if (!e.alive) continue;
                        var ex = gx + e.c * GX + 12, ey = gy + e.r * GY + 9;
                        if (Math.abs(s.x - ex) < 14 && Math.abs(s.y - ey) < 12){
                            e.alive = false; alive--; shots.splice(i, 1);
                            var pts = 10 + Math.max(0, 2 - e.r) * 10; sc += pts; api.setScore(sc); api.sfx('clear');
                            burst(ex, ey, RC[e.r % 6]); fl.push({ x:ex, y:ey, t:0.5, txt:'+' + pts });
                            break;
                        }
                    }
                }
                if (alive <= 0){
                    wave++; sc += 100; api.setScore(sc); build(); api.sfx('level');
                    fl.push({ x:W / 2, y:H / 2, t:1.3, txt:'WAVE ' + wave + '  +100' }); hud();
                }
                for (i = parts.length - 1; i >= 0; i--){ var p = parts[i]; p.x += p.vx * dt; p.y += p.vy * dt; p.life -= dt; if (p.life <= 0) parts.splice(i, 1); }
                for (i = fl.length - 1; i >= 0; i--){ fl[i].y -= 28 * dt; fl[i].t -= dt; if (fl[i].t <= 0) fl.splice(i, 1); }
            },
            draw:function(t, state){
                var i, e;
                var g = ctx.createLinearGradient(0, 0, 0, H); g.addColorStop(0, '#05061f'); g.addColorStop(1, '#150a33');
                ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
                ctx.fillStyle = 'rgba(255,255,255,.4)';
                for (i = 0; i < 30; i++) ctx.fillRect((i * 79) % W, ((i * 131) + tA * 12 * (1 + i % 3)) % H, 1.5, 1.5);
                var leg = Math.floor(tA * 3) % 2;
                for (i = 0; i < en.length; i++){
                    e = en[i]; if (!e.alive) continue;
                    var x = gx + e.c * GX, y = gy + e.r * GY;
                    ctx.fillStyle = RC[e.r % 6]; rrect(ctx, x, y + 2, 24, 13, 5); ctx.fill();
                    ctx.fillRect(x + 3, y - 1, 3, 4); ctx.fillRect(x + 18, y - 1, 3, 4);
                    ctx.fillRect(x + (leg ? 2 : 5), y + 14, 4, 4); ctx.fillRect(x + (leg ? 18 : 15), y + 14, 4, 4);
                    ctx.fillStyle = '#05061f'; ctx.fillRect(x + 6, y + 6, 4, 4); ctx.fillRect(x + 14, y + 6, 4, 4);
                }
                ctx.fillStyle = '#fff';
                for (i = 0; i < shots.length; i++) ctx.fillRect(shots[i].x - 1.5, shots[i].y - 8, 3, 12);
                ctx.fillStyle = '#ff7ab6';
                for (i = 0; i < bombs.length; i++){ var bb = bombs[i]; ctx.fillRect(bb.x - 2 + Math.sin(t * 20 + bb.y) * 2, bb.y - 6, 4, 12); }
                if (!(inv > 0 && Math.floor(t * 14) % 2)){
                    ctx.save(); ctx.shadowColor = '#ff2d2d'; ctx.shadowBlur = 12; ctx.fillStyle = '#ff2d2d';
                    ctx.beginPath(); ctx.moveTo(px, PY - 16); ctx.lineTo(px + 16, PY + 10); ctx.lineTo(px, PY + 4); ctx.lineTo(px - 16, PY + 10); ctx.closePath(); ctx.fill(); ctx.restore();
                    ctx.fillStyle = '#fff'; ctx.fillRect(px - 2, PY - 6, 4, 8);
                }
                for (i = 0; i < parts.length; i++){ ctx.globalAlpha = Math.max(0, parts[i].life / parts[i].max); ctx.fillStyle = parts[i].col; ctx.fillRect(parts[i].x - 2, parts[i].y - 2, 4, 4); }
                ctx.globalAlpha = 1;
                ctx.textAlign = 'center'; ctx.font = '700 14px Poppins, sans-serif';
                for (i = 0; i < fl.length; i++){ ctx.globalAlpha = Math.min(1, fl[i].t * 1.6); ctx.fillStyle = '#fff'; ctx.fillText(fl[i].txt, fl[i].x, fl[i].y); }
                ctx.globalAlpha = 1;
                ctx.font = '700 12px Poppins, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,.7)'; ctx.textAlign = 'left'; ctx.fillText('♥ ' + lives, 14, 24);
                ctx.textAlign = 'right'; ctx.fillText('Wave ' + wave, W - 14, 24);
                if (state === 'over'){ ctx.fillStyle = 'rgba(255,45,45,.14)'; ctx.fillRect(0, 0, W, H); }
            }
        };
    }
};

/* ══════════════════════════════════════════════════════════════
   LEVELS FOR THE GAMES THAT DIDN'T HAVE THEM
   a) real-time games: Easy / Hard change the game speed
   b) puzzle games and Capy World: small tweaks patched into the game
══════════════════════════════════════════════════════════════ */
var TS = {
    snake:      { easy:0.8,  hard:1.25 },
    pacman:     { easy:0.85, hard:1.15 },
    flappy:     { easy:0.88, hard:1.15 },
    hoppibara:  { easy:0.85, hard:1.2 },
    cakestack:  { easy:0.8,  hard:1.25 },
    cardodge:   { easy:0.85, hard:1.2 },
    gravflip:   { easy:0.85, hard:1.2 },
    ringrush:   { easy:0.85, hard:1.2 },
    whackabara: { easy:0.85, hard:1.25 }
};
Object.keys(TS).forEach(function(k){ if (GAMES[k]){ GAMES[k].diff = LV3; GAMES[k].ts = TS[k]; } });

function patch(key, reps){
    var G = GAMES[key]; if (!G) return;
    var src = G.make.toString(), ok = true;
    reps.forEach(function(r){ if (src.indexOf(r[0]) < 0) ok = false; });
    if (!ok) return;                                   // all-or-nothing: if the game code changed, leave it as it was
    reps.forEach(function(r){ src = src.replace(r[0], function(){ return r[1]; }); });
    try { G.make = (0, eval)('(' + src + ')'); G.diff = LV3; } catch(e){}
}
patch('g2048', [
    ['var W = 400, H = 400, PAD = 12,', 'var LVX = function(){ return ({ easy:{ skip:0.3 }, normal:{}, hard:{ extra:0.3 } })[api.lv || "normal"]; }; var W = 400, H = 400, PAD = 12,'],
    ['var np = addTile();', 'var np = ((LVX().skip && gain && Math.random() < LVX().skip) ? null : addTile()); if (LVX().extra && Math.random() < LVX().extra) addTile();'],
    ['Math.random() < 0.9 ? 2 : 4', 'Math.random() < (api.lv === "hard" ? 0.8 : 0.9) ? 2 : 4']
]);
patch('match3', [
    ['begin:function(){ hudm(); },', 'begin:function(){ moves = ({ easy:30, normal:20, hard:14 })[api.lv || "normal"]; hudm(); },']
]);
patch('woodblock', [
    ['var s = SH[rand(SH.length)], w = 0, h = 0;', 'var pool = SH.filter(function(q){ var n = q.length, lv = api.lv || "normal"; return lv === "easy" ? n <= 4 : lv === "hard" ? n >= 3 : true; }), s = pool[rand(pool.length)], w = 0, h = 0;'],
    ['dir:function(){}, tap:function(){},', 'begin:function(){ refill(); }, dir:function(){}, tap:function(){},']
]);
patch('bubbles', [
    ['var g, par, cur, nxt,', 'var KB = 5, g, par, cur, nxt,'],
    ['K = Math.min(6, 4 + level);', 'K = Math.min(6, KB - 1 + level);'],
    ['begin:function(){ hud(); },', 'begin:function(){ var L0 = ({ easy:{ k:4, m:6, d:30 }, normal:{ k:5, m:5, d:24 }, hard:{ k:6, m:4, d:18 } })[api.lv || "normal"]; KB = L0.k; K = L0.k; MISS_MAX = L0.m; DROP_TIME = L0.d; fillRows(6); dropOff = 0; miss = 0; dropT = DROP_TIME; cur = pickType(); nxt = pickType(); hud(); },']
]);
patch('capyworld', [
    ["begin:function(){ api.extra(quest ? 'Parcel → ' + quest.name : 'Explore!'); },", "begin:function(){ hp = ({ easy:5, normal:3, hard:2 })[api.lv || 'normal']; api.extra(quest ? 'Parcel → ' + quest.name : 'Explore!'); },"],
    ['var w = S.w;', "var w = S.w * (S.k === 'x' ? ({ easy:0.5, normal:1, hard:1.7 })[api.lv || 'normal'] : 1);"]
]);

/* ══════════════════════════════════════════════════════════════
   ENGINE HOOKS (no edits to games.js: these replace its global functions)
══════════════════════════════════════════════════════════════ */
if (typeof openGame === 'function' && typeof readyScreen === 'function' && typeof restart === 'function'){
    var _open = openGame, _ready = readyScreen, _restart = restart;
    var hasDiff = function(){ var G = GAMES[curKey]; return !!(G && G.diff && cur && !cur.levels && !cur.steps); };

    openGame = function(key){
        api.lv = null;
        _open(key);
        var G = GAMES[key];
        if (cur && G && G.ts && !cur._ts){
            var u = cur.update, c = cur;
            cur.update = function(dt){ var m = G.ts[api.lv || 'normal']; return u.call(c, m ? dt * m : dt); };
            cur._ts = 1;
        }
    };
    readyScreen = function(){
        if (!hasDiff()){ _ready(); return; }
        var G = GAMES[curKey];
        state = 'ready';
        showChoice(G.ovIcon, G.name, G.help, G.diff, function(k){ api.lv = k; if (cur.setLevel) cur.setLevel(k); start(); });
    };
    restart = function(){
        if (!hasDiff()){ _restart(); return; }
        releasePtrs(); errored = false;
        cur.reset(); api.setScore(0); api.extra(''); clearHeld();
        readyScreen();
    };
}

/* the new cards are drawn right away (so a card can never be skipped if this file loaded after the first preview pass) */
if (typeof renderPreview === 'function' && typeof PREV_DONE !== 'undefined'){
    ['brickbreak', 'memory', 'connect4', 'invaders'].forEach(function(k){
        var c = document.querySelector('canvas[data-game="' + k + '"]');
        if (c){ PREV_DONE[k] = 0; try { renderPreview(c); } catch(e){} }
    });
}
})();