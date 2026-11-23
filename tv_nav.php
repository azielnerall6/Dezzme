<?php if(defined('DZ_TV_INCLUDED')) return; define('DZ_TV_INCLUDED', 1); ?><?php /* Dezzme TV / remote-control (D-pad) navigation — shared by profile.php and group_page.php.
   Arrow keys move focus to the nearest control in that direction, OK/Enter presses it, Back closes the top sheet.
   Always safe on touch/mouse: the focus ring only appears after a key is pressed. TV mode (bigger UI) turns on for
   TV browsers, for ?tv=1 (remembered) and is switched off with ?tv=0. */ ?>
<style id="dzTvCss">
/* focus ring — only while navigating by keys/remote */
html.dz-kbd :focus{outline:3px solid #fff !important;outline-offset:3px !important;box-shadow:0 0 0 7px rgba(0,0,0,.65) !important;border-radius:inherit;z-index:2147483000}
html.dz-kbd input:focus,html.dz-kbd textarea:focus,html.dz-kbd select:focus{outline-offset:1px !important}
html.dz-kbd{scroll-behavior:auto}
[data-dz-tvfocus]{cursor:pointer}
/* TV mode: bigger everything, safe margins for overscan */
html.dz-tv body{zoom:1.3}
html.dz-tv body{padding-bottom:40px}
@media (min-width:2500px){html.dz-tv body{zoom:1.9}}
</style>
<script id="dzTvJs">
(function(){
  if(window.__dzTv) return; window.__dzTv = 1;
  var root = document.documentElement, ls = null;
  try{ ls = window.localStorage; }catch(e){}
  /* ── TV mode switch ── */
  try{
    var q = new URLSearchParams(location.search).get('tv');
    if(q === '1' && ls) ls.setItem('dzTv', '1'); else if(q === '0' && ls) ls.removeItem('dzTv');
    var ua = navigator.userAgent || '';
    var isTv = /SmartTV|Smart-TV|SMART-TV|Tizen|Web0S|WebOS|NetCast|HbbTV|BRAVIA|AppleTV|CrKey|AFTM|AndroidTV|Android TV|GoogleTV|Roku|VIDAA|Viera|Hisense|PhilipsTV/i.test(ua);
    if((q !== '0') && (isTv || q === '1' || (ls && ls.getItem('dzTv') === '1'))) root.classList.add('dz-tv');
  }catch(e){}

  /* ── make every clickable thing reachable ── */
  var NATIVE = 'a[href],button,input:not([type=hidden]),select,textarea,summary,[contenteditable=true]';
  var CLICKY = '[onclick],[role=button],[role=tab],[role=menuitem],[role=link],[role=switch],[role=checkbox],[role=option],label[for],.btn,.tab,.pill,.chip';
  function visible(el){
    if(el.disabled || el.getAttribute('aria-hidden') === 'true') return false;
    var r = el.getBoundingClientRect();
    if(r.width < 2 || r.height < 2) return false;
    var cs = getComputedStyle(el);
    if(cs.visibility === 'hidden' || cs.display === 'none' || cs.pointerEvents === 'none') return false;
    return true;
  }
  function prep(scope){
    var list = (scope || document).querySelectorAll(CLICKY);
    for(var i = 0; i < list.length; i++){
      var el = list[i];
      if(el.matches(NATIVE) || el.hasAttribute('tabindex')) continue;
      el.setAttribute('tabindex', '0'); el.setAttribute('data-dz-tvfocus', '1');
      if(!el.hasAttribute('role') && el.tagName !== 'LABEL') el.setAttribute('role', 'button');
    }
  }
  prep();
  var pend = 0;
  try{
    new MutationObserver(function(){ if(pend) return; pend = setTimeout(function(){ pend = 0; prep(); }, 250); })
      .observe(document.body, {childList:true, subtree:true});
  }catch(e){}

  /* ── which area is "active" (open sheet/modal traps focus) ── */
  function topLayer(){
    var best = null, bz = 0, all = document.querySelectorAll('body *');
    /* only inspect likely overlays to stay fast */
    var c = document.querySelectorAll('[class*=ov],[class*=modal],[class*=sheet],[class*=Modal],[class*=popup],[class*=menu],[id*=Ov],[id*=Modal],[id*=Sheet]');
    for(var i = 0; i < c.length; i++){
      var el = c[i], cs = getComputedStyle(el);
      if(cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity < .05) continue;
      var r = el.getBoundingClientRect();
      if(r.width < innerWidth * .5 || r.height < innerHeight * .3) continue;
      if(el.querySelectorAll(NATIVE + ',[tabindex]').length === 0) continue;
      var z = parseInt(cs.zIndex, 10) || 0;
      if(z >= bz){ bz = z; best = el; }
    }
    return best;
  }
  function candidates(){
    var layer = topLayer(), scope = layer || document;
    var all = scope.querySelectorAll(NATIVE + ',[tabindex]:not([tabindex="-1"])'), out = [];
    for(var i = 0; i < all.length; i++){
      var el = all[i];
      if(!visible(el)) continue;
      if(el.type === 'radio' && !el.checked && el.name && scope.querySelector('input[type=radio][name="' + el.name + '"]:checked')) continue;
      var r = el.getBoundingClientRect();
      if(r.bottom < 0 || r.top > innerHeight * 3) continue; /* far offscreen is fine, scrolls into view */
      out.push(el);
    }
    return out;
  }
  function center(r){ return {x: r.left + r.width / 2, y: r.top + r.height / 2}; }

  /* ── spatial navigation ── */
  function move(dir){
    var cur = document.activeElement;
    if(!cur || cur === document.body || !visible(cur)) cur = null;
    var list = candidates();
    if(!list.length) return false;
    if(!cur){
      /* nothing focused → start with the first control that is on screen */
      for(var i = 0; i < list.length; i++){ var r0 = list[i].getBoundingClientRect(); if(r0.top >= 0 && r0.top < innerHeight){ focusEl(list[i]); return true; } }
      focusEl(list[0]); return true;
    }
    var cr = cur.getBoundingClientRect(), cc = center(cr), best = null, bs = 1e12;
    for(var j = 0; j < list.length; j++){
      var el = list[j]; if(el === cur || cur.contains(el) || el.contains(cur)) continue;
      var r = el.getBoundingClientRect(), c = center(r), dx = c.x - cc.x, dy = c.y - cc.y, inDir = false, main, cross, overlap;
      if(dir === 'right'){ inDir = r.left >= cr.right - cr.width * .4 && dx > 4; main = Math.max(0, r.left - cr.right); cross = Math.abs(dy); overlap = Math.min(r.bottom, cr.bottom) - Math.max(r.top, cr.top); }
      if(dir === 'left'){  inDir = r.right <= cr.left + cr.width * .4 && dx < -4; main = Math.max(0, cr.left - r.right); cross = Math.abs(dy); overlap = Math.min(r.bottom, cr.bottom) - Math.max(r.top, cr.top); }
      if(dir === 'down'){  inDir = r.top >= cr.bottom - cr.height * .4 && dy > 4; main = Math.max(0, r.top - cr.bottom); cross = Math.abs(dx); overlap = Math.min(r.right, cr.right) - Math.max(r.left, cr.left); }
      if(dir === 'up'){    inDir = r.bottom <= cr.top + cr.height * .4 && dy < -4; main = Math.max(0, cr.top - r.bottom); cross = Math.abs(dx); overlap = Math.min(r.right, cr.right) - Math.max(r.left, cr.left); }
      if(!inDir) continue;
      var score = main * 1 + cross * (overlap > 0 ? 1.2 : 3.2);
      if(score < bs){ bs = score; best = el; }
    }
    if(best){ focusEl(best); return true; }
    return false;
  }
  function focusEl(el){
    try{ el.focus({preventScroll:true}); }catch(e){ el.focus(); }
    try{ el.scrollIntoView({block:'center', inline:'center', behavior:'auto'}); }catch(e){}
  }

  /* ── keys ── */
  var DIRS = {ArrowUp:'up', ArrowDown:'down', ArrowLeft:'left', ArrowRight:'right', Up:'up', Down:'down', Left:'left', Right:'right'};
  var CODES = {38:'up', 40:'down', 37:'left', 39:'right'};
  function typing(el){
    if(!el) return false;
    var t = el.tagName;
    return t === 'TEXTAREA' || el.isContentEditable || (t === 'INPUT' && !/^(button|submit|checkbox|radio|range|reset|file|image|color)$/i.test(el.type));
  }
  function escape(){
    var ev = new KeyboardEvent('keydown', {key:'Escape', code:'Escape', keyCode:27, which:27, bubbles:true, cancelable:true});
    var layer = topLayer();
    document.dispatchEvent(ev);
    /* pages whose sheet has no Escape handler: press its close button instead */
    setTimeout(function(){
      if(!layer || !document.body.contains(layer)) return;
      var cs = getComputedStyle(layer);
      if(cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity < .05 || layer.getBoundingClientRect().width < 2) return;
      var cl = layer.querySelector('[data-close],[aria-label="Close"],[aria-label="close"],[title="Close"],[class*="close"],[class*="Close"],[onclick*="lose"]');
      if(cl) cl.click();
    }, 120);
  }
  document.addEventListener('keydown', function(e){
    if(e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
    var k = e.key, kc = e.keyCode, a = document.activeElement;
    /* Back key on TV remotes (webOS 461, Tizen 10009, Android TV 4, BrowserBack) → close the top sheet */
    if(kc === 461 || kc === 10009 || kc === 4 || k === 'BrowserBack' || k === 'GoBack'){
      e.preventDefault();
      if(topLayer()) escape(); else history.back();
      return;
    }
    if(k === 'Backspace' && !typing(a) && topLayer()){ e.preventDefault(); escape(); return; }
    var dir = DIRS[k] || (k === undefined || k === 'Unidentified' ? CODES[kc] : null);
    if(dir){
      /* keep text cursor / sliders / highlight story arrows working */
      if(typing(a) && (dir === 'left' || dir === 'right' || a.tagName === 'TEXTAREA')) return;
      if(a && a.tagName === 'INPUT' && a.type === 'range' && (dir === 'left' || dir === 'right')) return;
      if(a && a.tagName === 'SELECT') return;
      var hl = document.querySelector('#hlModal.open'); if(hl && (dir === 'left' || dir === 'right')) return;
      root.classList.add('dz-kbd');
      if(move(dir)) e.preventDefault();
      return;
    }
    if(k === 'Enter' || k === 'OK' || kc === 13){
      if(a && a !== document.body && a.hasAttribute && a.hasAttribute('data-dz-tvfocus')){ e.preventDefault(); a.click(); }
      return;
    }
    if(k === ' ' && a && a.hasAttribute && a.hasAttribute('data-dz-tvfocus')){ e.preventDefault(); a.click(); return; }
    if(k === 'Tab') root.classList.add('dz-kbd');
  }, false);
  /* touch / mouse hides the ring again */
  ['mousedown','touchstart','pointerdown'].forEach(function(t){ document.addEventListener(t, function(){ root.classList.remove('dz-kbd'); }, {passive:true, capture:true}); });
  /* a sheet just opened → put focus inside it so the remote keeps working */
  var lastLayer = null;
  setInterval(function(){
    if(!root.classList.contains('dz-kbd')) return;
    var l = topLayer();
    if(l && l !== lastLayer){ var c = l.querySelector(NATIVE + ',[tabindex="0"]'); if(c && visible(c)) focusEl(c); }
    lastLayer = l;
  }, 400);
})();
</script>