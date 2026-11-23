/* time_track.js: counts the time a logged-in person is actively using Dezzme.
   Add to every page for logged-in users (next to </body>):
   <script nonce="<?= $nonce ?>" src="time_track.js" data-csrf="<?= htmlspecialchars($_SESSION['st_csrf']) ?>"></script>
   $_SESSION['st_csrf'] is created by settings.php; create it on your other pages the same way if it is missing:
   if (empty($_SESSION['st_csrf'])) $_SESSION['st_csrf'] = bin2hex(random_bytes(32));          */
(function () {
  var me = document.currentScript;
  var csrf = me && me.getAttribute('data-csrf');
  if (!me || !csrf) return;
  var url = new URL('time_ping.php', me.src).href;

  var EVERY = 30000;          // send every 30 seconds
  var IDLE  = 60000;          // no mouse / key / touch / scroll for 60s = idle, not counted
  var lastAct = Date.now();
  var lastTick = Date.now();

  ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (e) {
    window.addEventListener(e, function () { lastAct = Date.now(); }, { passive: true });
  });

  function send() {
    var now = Date.now();
    var active = !document.hidden && (now - lastAct) <= IDLE;
    var secs = Math.round((now - lastTick) / 1000);
    lastTick = now;
    if (!active || secs < 1) return;
    var fd = new FormData();
    fd.append('seconds', Math.min(secs, 65));
    try {
      fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true, headers: { 'X-CSRF-Token': csrf } }).catch(function () {});
    } catch (e) {}
  }

  setInterval(send, EVERY);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) send();        // send what was used before leaving the tab
    else lastTick = Date.now();         // start fresh when coming back
  });
  window.addEventListener('pagehide', send);
})();