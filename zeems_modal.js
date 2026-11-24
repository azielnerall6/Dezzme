/* ════════════════════════════════════════════════════════════
   CARD CAPTION MODAL — gapless vertical track engine
   (prev / current / next slides mounted at once, swipe moves the
   whole track, neighbour is promoted on commit)
   ════════════════════════════════════════════════════════════ */
var CCM_ENDPOINT = window.CCM_ENDPOINT || "home.php";
/* optional host hooks (group page): window.CCM_CONFIG = { endpoint, shareLink, actionBody, actionResult, connectedBody,
   shareEndpoint, followEndpoint, extraHtml, menuItems, isHidden, hiddenHtml, onSlide }. Unset = today's behaviour. */
var CCM_CFG = window.CCM_CONFIG || {};
function _ccmEp() {
  return CCM_CFG.endpoint || CCM_ENDPOINT;
}
/* appends a query to the endpoint with '?' or '&' depending on whether it already has one */
function _ccmUrl(extra) {
  var base = _ccmEp();
  if (!extra) return base;
  return base + (base.indexOf("?") === -1 ? "?" : "&") + extra;
}
function _ccmShareLinkFor(id) {
  if (CCM_CFG.shareLink) return CCM_CFG.shareLink(id);
  return new URL(_ccmUrl("meme_id=" + id), window.location.origin + "/").href;
}

/* vertical slide feel — longer + softer ease (was .28s with a very sharp ease-out) */
var CCM_SLIDE_MS = 420;
var CCM_SLIDE_EASE = "cubic-bezier(.32,.72,.24,1)";
var _ccmLastWheelNav = 0;

var _ccmIndex = 0;
var _ccmMemeId = null;
var _ccmMediaIndex = 0;
var ccmFeed = [];
var _ccmCaptionFullHtml = "";
var _ccmCaptionTruncHtml = "";
var _ccmCaptionDateHtml = "";
var _ccmCaptionExpanded = false;
var _ccmInfoSwitchTimer = null;
var _ccmCtaPillTimer = null;
var _ccmVSwipeLocked = false;

var _ccmPausedMemeIds = {};
var _ccmMediaIndexByPost = {};
var _ccmTrackPositions = {};

/* ══ CONNECTED ZEEM — horizontal layer stack state ══ */
var _ccmHStack = [];
var _ccmConnectedCache = {};
var _ccmConnectedFetchLock = false;

var currentUserId = window.CCM_PHP.userId;
window.dzMe = window.dzMe || window.CCM_PHP.me;
/* Haptic feedback preference (Settings → user_settings.haptics_enabled; on unless the user turned it off) */
window.DZ_HAPTICS_ON = window.CCM_PHP.haptics;
/* ═══ DEZZME HAPTIC ENGINE — the one place that vibrates. Everything else calls dzHaptic.light() / .medium() / .success() …
   • Android / Chrome / Firefox-Android: navigator.vibrate patterns.
   • iPhone: Safari has NO Vibration API. From iOS 17.4 a switch-type checkbox gives the system's own light tick when it is toggled
     inside a tap, used here as a best-effort fallback (soft ticks; only works straight from a tap, never after a network wait).
   • Nothing is ever thrown; unsupported devices simply do nothing. Off when the user turned it off, or prefers reduced motion. ═══ */
window.dzHaptic =
  window.dzHaptic ||
  (function () {
    var enabled = window.DZ_HAPTICS_ON !== false,
      last = 0,
      tickEl = null;
    var canVibrate = false;
    try {
      canVibrate = typeof navigator.vibrate === "function";
    } catch (e) {}
    var isIOS = false;
    try {
      isIOS =
        /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
    } catch (e) {}
    var P = {
      light: [16],
      medium: [30],
      heavy: [55],
      success: [18, 50, 32],
      error: [45, 60, 45, 60, 60],
      selection: [11],
      soft: [21],
      longPress: [40],
      double: [18, 60, 18],
    };
    var IOS_TICKS = {
      light: 1,
      medium: 2,
      heavy: 3,
      success: 3,
      error: 4,
      selection: 1,
      soft: 1,
      longPress: 2,
      double: 2,
    };
    function reduced() {
      try {
        return !!(
          window.matchMedia &&
          matchMedia("(prefers-reduced-motion: reduce)").matches
        );
      } catch (e) {
        return false;
      }
    }
    function iosTick() {
      try {
        if (!tickEl) {
          tickEl = document.createElement("label");
          tickEl.setAttribute("aria-hidden", "true");
          tickEl.style.cssText =
            "position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none;overflow:hidden";
          var i = document.createElement("input");
          i.type = "checkbox";
          i.setAttribute("switch", "");
          i.tabIndex = -1;
          tickEl.appendChild(i);
          document.body.appendChild(tickEl);
        }
        tickEl.click();
      } catch (e) {}
    }
    function fire(kind) {
      try {
        if (!enabled || reduced()) return;
        var now = Date.now();
        if (now - last < 40) return; /* one buzz per action, never a burst */
        last = now;
        if (canVibrate) {
          navigator.vibrate(P[kind] || P.light);
          return;
        }
        if (isIOS) {
          var n = IOS_TICKS[kind] || 1;
          iosTick();
          for (var k = 1; k < n; k++) setTimeout(iosTick, k * 55);
        }
      } catch (e) {}
    }
    var api = {
      enable: function (on) {
        enabled = !!on;
      },
      isEnabled: function () {
        return enabled;
      },
      supported: function () {
        return canVibrate || isIOS;
      },
    };
    Object.keys(P).forEach(function (k) {
      api[k] = function () {
        fire(k);
      };
    });
    return api;
  })();

function _ccmComputeDashGap(count) {
  return Math.max(3, Math.min(6, Math.round(60 / count)));
}

/* ── MOUSE DETECTION — powers the hover arrows ── */
(function _ccmInitMouseDetect() {
  var modal = document.getElementById("cardCaptionModal");
  if (!modal) return;
  modal.addEventListener(
    "pointermove",
    function (e) {
      if (e.pointerType === "mouse") modal.classList.add("ccm-has-mouse");
    },
    { passive: true },
  );
  modal.addEventListener(
    "pointerdown",
    function (e) {
      if (e.pointerType && e.pointerType !== "mouse")
        modal.classList.remove("ccm-has-mouse");
    },
    { passive: true },
  );
})();

/* ── SAVED TOAST ── */
var _ccmSavedToastTimer = null;

function ccmShowSavedToast(memeId) {
  var existing = document.getElementById("ccmSavedToast");
  if (existing) existing.remove();
  clearTimeout(_ccmSavedToastTimer);

  var toast = document.createElement("div");
  toast.id = "ccmSavedToast";
  toast.className = "ccm-saved-toast";
  toast.innerHTML =
    '<div class="ccm-saved-toast-left">' +
    '<span class="ccm-saved-toast-check"><i class="ri-check-line"></i></span>' +
    "Saved" +
    "</div>" +
    '<div class="ccm-saved-toast-right" onclick="ccmToastOpenCollections(' +
    memeId +
    ')">' +
    'Collections<i class="ri-arrow-right-s-line"></i>' +
    "</div>";

  document.body.appendChild(toast);
  requestAnimationFrame(function () {
    toast.classList.add("show");
  });

  _ccmSavedToastTimer = setTimeout(function () {
    toast.classList.remove("show");
    setTimeout(function () {
      toast.remove();
    }, 300);
  }, 3500);
}

function ccmHideSavedToast() {
  var toast = document.getElementById("ccmSavedToast");
  if (!toast) return;
  clearTimeout(_ccmSavedToastTimer);
  toast.classList.remove("show");
  setTimeout(function () {
    toast.remove();
  }, 300);
}

function ccmToastOpenCollections(memeId) {
  ccmHideSavedToast();
  if (typeof openSaveModal === "function") {
    openSaveModal(memeId);
  }
}

/* ── DATA LOADING / FEED BUILD ── */
function _loadCcmData() {
  (window._suggPromise || Promise.resolve([])).then(function (sugg) {
    window.cmSuggUsers = sugg || [];
    _buildCcmFeed();
  });
}

/* open-by-id start state (object form of openCardModal) */
var _ccmFeedSource =
  null; /* array passed as { feed:[...] }; null = use peuraFeed */
var _ccmPendingStartTime = 0; /* seconds into the active video */
var _ccmPendingPosterUrl = null; /* frame shown while the video loads */
var _ccmLastPosterUsed = false;
var _ccmUseMenuSheet = false; /* true when opened by id → the rail "…" button opens the built-in menu sheet */

function _buildCcmFeed() {
  ccmFeed = [];
  var suggIdx = 0;
  var sugg = window.cmSuggUsers || [];
  (
    _ccmFeedSource || (typeof peuraFeed !== "undefined" ? peuraFeed : [])
  ).forEach(function (post, i) {
    ccmFeed.push({ type: "post", data: post });
    if ((i + 1) % 7 === 0 && sugg.length > 0) {
      var maxSlice = Math.min(16, sugg.length);
      var slice = [];
      for (var j = 0; j < maxSlice; j++)
        slice.push(sugg[(suggIdx + j) % sugg.length]);
      ccmFeed.push({ type: "sugg", data: slice });
      suggIdx = (suggIdx + maxSlice) % sugg.length;
    }
  });
}

/* ── HELPERS ── */
function _formatDate(timeStr) {
  if (!timeStr) return "";
  var d = new Date(timeStr.replace(" ", "T"));
  if (isNaN(d)) return timeStr;
  var months = [
    "Jan",
    "Feb",
    "Mar",
    "Apr",
    "May",
    "Jun",
    "Jul",
    "Aug",
    "Sep",
    "Oct",
    "Nov",
    "Dec",
  ];
  return (
    d.getDate() +
    " " +
    months[d.getMonth()] +
    " " +
    d.getFullYear() +
    " · " +
    String(d.getHours()).padStart(2, "0") +
    ":" +
    String(d.getMinutes()).padStart(2, "0")
  );
}
function _ccmTruncateName(name) {
  if (!name) return "";
  return name.length > 15 ? name.slice(0, 15) + "...." : name;
}
function _ccmTruncateGroupName(name) {
  return name || "";
}
/* text-only post size by caption length: <=90 s · <=220 m · longer l */
function _ccmTextSize(p) {
  var n = String((p && p.caption) || "").trim().length;
  return n <= 90 ? "s" : n <= 220 ? "m" : "l";
}
function _ccmEscapeHtml(str) {
  if (str == null || str === false || str === "") return "";
  /* & < > and both quote kinds, so it is safe in text AND inside attributes (&apos;/&quot; carry no "#" so hashtag linking is unaffected) */
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&apos;");
}
/* ids that get glued into onclick="" / selectors / URLs: digits only, anything else becomes 0 */
/* CSRF token (window.DZ_CSRF, set by the page) sent with every state-changing request, in the body AND as a header */
function _ccmCsrfQ() {
  return window.DZ_CSRF ? "&csrf=" + encodeURIComponent(window.DZ_CSRF) : "";
}
function _ccmCsrfH(h) {
  h = h || {};
  if (window.DZ_CSRF) h["X-CSRF-Token"] = window.DZ_CSRF;
  return h;
}
function _ccmId(v) {
  var n = parseInt(v, 10);
  return isFinite(n) ? String(n) : "0";
}
/* URLs that go into src="" / href="": only http(s), blob:, data:image|video and relative paths — javascript:, vbscript:, data:text/html etc. become the fallback; result is attribute-escaped */
function _ccmSafeUrl(u, fallback) {
  u = (u == null ? "" : String(u)).trim();
  var ok =
    u !== "" &&
    (/^(https?:|blob:|data:image\/|data:video\/)/i.test(u) ||
      !/^[a-z][a-z0-9+.\-]*:/i.test(u.replace(/[\u0000-\u0020]/g, "")));
  return _ccmEscapeHtml(ok ? u : fallback || "");
}
/* CSS colour for style="" : letters, digits, # ( ) , . % - space only */
function _ccmSafeColor(c, fb) {
  c = String(c == null ? "" : c);
  return /^[#a-zA-Z0-9(),.%\-\s_]{1,60}$/.test(c) ? c : fb;
}
function _ccmTypeTagInfo(rawType) {
  var type = (rawType || "").toLowerCase();
  var map = {
    image: { icon: "ri-file-copy-line", label: "Photo" },
    video: { icon: "ri-vidicon-line", label: "Video" },
    gif: { icon: "ri-file-gif-line", label: "GIF" },
    sticker: { icon: "ri-shapes-line", label: "Sticker" },
    stickers: { icon: "ri-shapes-line", label: "Sticker" },
    text: { icon: "ri-file-text-line", label: "Text" },
  };
  return map[type] || { icon: "ri-file-copy-line", label: "Photo" };
}

function linkifyCaption(caption) {
  if (!caption) return "";
  return _ccmEscapeHtml(caption)
    .replace(/#([a-zA-Z0-9_]+)/g, function (match, tag) {
      var mq = encodeURIComponent("#" + tag);
      return (
        '<a href="search.php?focus=1&mq=' +
        mq +
        '" class="ccm-tag-link" onclick="event.stopPropagation()">' +
        match +
        "</a>"
      );
    })
    .replace(/@([a-zA-Z0-9_]+)/g, function (match, username) {
      return (
        '<a href="profile.php?username=' +
        encodeURIComponent(username) +
        '" class="ccm-mention-link" onclick="event.stopPropagation()">' +
        match +
        "</a>"
      );
    });
}

function _ccmComputeCaptionHtml(p) {
  var media =
    Array.isArray(p.media) && p.media.length
      ? p.media.slice()
      : [{ file: p.file, type: p.type }];
  var mediaSet = ["image", "video", "gif", "sticker", "stickers"];
  var firstType = (media[0].type || "").toLowerCase();
  var isTextOnly = media.length === 1 && mediaSet.indexOf(firstType) === -1;

  var captionHtml = linkifyCaption(p.caption || "");
  var full = captionHtml,
    trunc = captionHtml;

  if (isTextOnly) {
    var cWords = (p.caption || "").trim().split(/\s+/).filter(Boolean);
    if (cWords.length > 40) {
      var cTrunc = cWords.slice(0, 40).join(" ");
      trunc =
        linkifyCaption(cTrunc) +
        '<span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCenterCaption()">' +
        '<span class="ccm-ellipsis">...</span>more' +
        "</span>";
    }
  } else {
    var words = (p.caption || "").trim().split(/\s+/).filter(Boolean);
    if (words.length > 10) {
      var truncPlain = words.slice(0, 10).join(" ");
      trunc =
        linkifyCaption(truncPlain) +
        '<span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCaption()">' +
        '<span class="ccm-ellipsis">...</span>more' +
        "</span>";
    }
  }

  /* post date + "New" tag — shown under the caption when it is tapped (same as the meme modal); never for ads */
  var dateHtml = "";
  if (p.time && !p.isAd) {
    var _d = new Date(String(p.time).replace(" ", "T")),
      _new = false;
    if (!isNaN(_d)) _new = Date.now() - _d.getTime() <= 172800 * 1000;
    dateHtml = '<span class="ccm-cap-date">' + _formatDate(p.time) + "</span>";
    /* "New" (posted in the last 48 h): shown first in the caption, before the first word */
    if (_new && !isTextOnly && !p.isAd && String(full).trim() !== "") {
      var _nt = '<span class="ccm-new-tag">New</span> ';
      full = _nt + full;
      trunc = _nt + trunc;
    }
  }

  return { full: full, trunc: trunc, isTextOnly: isTextOnly, date: dateHtml };
}

/* ── CAPTION EXPAND / COLLAPSE ── */
function ccmUpdateSocialProofVisibility() {
  /* social-proof avatars / CTA pill / connected pill disappear while the caption is expanded ("more" or tapped) and return when it folds */
  var slideEl = document.getElementById("ccm-slide");
  if (!slideEl) return;
  [
    "#ccm-social-proof",
    "#ccm-cta-pill",
    "#ccm-poll-pill",
    "#ccm-connected-tag",
  ].forEach(function (sel) {
    var el = slideEl.querySelector(sel);
    if (el) el.classList.toggle("ccm-sp-hidden", _ccmCaptionExpanded);
  });
}
function _ccmIsInsideExpandedCaption(target) {
  if (!_ccmCaptionExpanded || !target || !target.closest) return false;
  return !!(
    target.closest(".ccm-caption-wrap") ||
    target.closest(".ccm-center-caption-wrap")
  );
}
function _ccmCheckCaptionOverflow(slide) {
  if (!slide) return;
  var wrap = slide.querySelector(".ccm-center-caption-wrap");
  var text = slide.querySelector(".ccm-center-caption-text");
  if (!wrap || !text) return;
  var isOverflowing = text.scrollHeight > wrap.clientHeight;
  wrap.classList.toggle("ccm-caption-overflow", isOverflowing);
}
/* a tap on a caption (even a short one) opens it and reveals the post date under it.
   Links (#tags, @mentions) and the more / see-less words handle their own clicks.
   Once it is open, the stage-wide click handler below folds it back. */
/* DOUBLE-TAP LIKE vs CAPTION OPEN/CLOSE — both live on the same spot, so they share one tiny memory:
   every tap that opens or closes the caption stamps the time here. If a SECOND plain tap lands within CCM_DOUBLE_TAP_MS of
   that stamp it is a double-tap: the post is liked (heart burst) and the caption goes back to how it was before the first tap.
   A single tap still opens / closes the caption exactly as before. */
var _ccmCapTapAt = 0;
function _ccmCapTapIsSecond() {
  var now = Date.now(),
    second = _ccmCapTapAt > 0 && now - _ccmCapTapAt < CCM_DOUBLE_TAP_MS;
  _ccmCapTapAt = second ? 0 : now;
  return second;
}
function _ccmLikeFromDoubleTap(e) {
  var box = document.querySelector("#ccm-slide #ccm-media-box");
  if (box) {
    ccmDoubleTapLike(box, e);
    return;
  }
  var slide =
    document.getElementById(
      "ccm-slide",
    ); /* text-only post: no picture, so the heart pops on the slide itself and flies to the like button */
  if (slide) {
    ccmDoubleTapLike(slide, e);
    return;
  }
}
function ccmCaptionTap(e, isCenter) {
  if (
    e &&
    e.target &&
    e.target.closest &&
    e.target.closest("a, .ccm-more-toggle")
  )
    return;
  var sel = window.getSelection ? String(window.getSelection()) : "";
  if (sel && sel.length > 0) return;
  if (e) e.stopPropagation();
  if (_ccmCaptionExpanded) return;
  /* the caption opens only after the double-tap window has passed with no second tap, so a double-tap is just a like:
       the caption never flashes open and shut under the heart */
  if (_ccmCapPending) {
    clearTimeout(_ccmCapPending);
    _ccmCapPending = null;
    _ccmLikeFromDoubleTap(e);
    return;
  }
  _ccmCapPending = setTimeout(function () {
    _ccmCapPending = null;
    if (_ccmCaptionExpanded) return;
    if (isCenter) ccmToggleCenterCaption();
    else ccmToggleCaption();
  }, CCM_DOUBLE_TAP_MS);
}
var _ccmCapPending = null;

/* While a caption is expanded, a tap ANYWHERE in the modal closes it — except the right rail buttons and the top area
   (back button, "Zeems" label), and the more / see-less words which close it themselves.
   Taps on links / buttons (Track, username, sound, #tags…) still do their own job AND close the caption;
   a tap on a plain area (picture, caption, empty space) only closes it, so it doesn't also pause or like. */
(function () {
  var stage = document.getElementById("ccm-stage");
  if (!stage) return;
  stage.addEventListener(
    "click",
    function (e) {
      if (!_ccmCaptionExpanded) return;
      var t = e.target;
      if (!t || !t.closest) return;
      if (
        t.closest(
          ".ccm-rail, #ccm-fixed-topbar, #ccmFixedBackBtn, .ccm-more-toggle",
        )
      )
        return;

      var slide = document.getElementById("ccm-slide");
      var isCenter = !!(
        slide && slide.querySelector("#ccm-center-caption-text")
      );
      var interactive = !!t.closest(
        "a, button, input, .ccm-user-pic, .ccm-username, .ccm-audio-tag",
      );

      if (!interactive) {
        e.stopPropagation();
        e.preventDefault();
        if (_ccmCapTapIsSecond()) {
          /* double-tap: the first tap opened it, so close it again and like */
          if (isCenter) ccmToggleCenterCaption();
          else ccmToggleCaption();
          _ccmLikeFromDoubleTap(e);
          return;
        }
      } else {
        _ccmCapTapAt = 0;
      }
      if (isCenter) ccmToggleCenterCaption();
      else ccmToggleCaption();
    },
    true,
  );
})();

function ccmToggleCaption() {
  dzHaptic.selection();
  var slideEl = document.getElementById("ccm-slide");
  if (!slideEl) return;
  var textEl = slideEl.querySelector("#ccm-caption-text");
  var wrapEl = slideEl.querySelector("#ccm-caption-wrap");
  if (!textEl) return;
  _ccmCaptionExpanded = !_ccmCaptionExpanded;
  if (_ccmCaptionExpanded) {
    textEl.innerHTML =
      _ccmCaptionFullHtml +
      (_ccmCaptionFullHtml !== _ccmCaptionTruncHtml
        ? ' <span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCaption()"> see less</span>'
        : "") +
      _ccmCaptionDateHtml;
    if (wrapEl) wrapEl.classList.add("ccm-expanded");
  } else {
    textEl.innerHTML = _ccmCaptionTruncHtml;
    if (wrapEl) {
      wrapEl.classList.remove("ccm-expanded");
      wrapEl.scrollTop = 0;
    }
  }
  ccmUpdateSocialProofVisibility();
}
function ccmToggleCenterCaption() {
  dzHaptic.selection();
  var slideEl = document.getElementById("ccm-slide");
  if (!slideEl) return;
  var textEl = slideEl.querySelector("#ccm-center-caption-text");
  var wrapEl = slideEl.querySelector("#ccm-center-caption-wrap");
  if (!textEl) return;
  _ccmCaptionExpanded = !_ccmCaptionExpanded;
  if (_ccmCaptionExpanded) {
    textEl.innerHTML =
      _ccmCaptionFullHtml +
      (_ccmCaptionFullHtml !== _ccmCaptionTruncHtml
        ? ' <span class="ccm-more-toggle" onclick="event.stopPropagation();ccmToggleCenterCaption()"> see less</span>'
        : "") +
      _ccmCaptionDateHtml;
  } else {
    textEl.innerHTML = _ccmCaptionTruncHtml;
    textEl.scrollTop = 0;
  }
  ccmUpdateSocialProofVisibility();
  var slide = wrapEl ? wrapEl.closest(".ccm-slide") : null;
  if (slide) _ccmCheckCaptionOverflow(slide);
}

/* ── SKELETON ── */
function _ccmSkeletonHtml() {
  return (
    '<div class="ccm-skeleton" id="ccm-skeleton">' +
    '<div class="ccm-skel-block ccm-skel-media"></div>' +
    '<div class="ccm-skel-block ccm-skel-avatar"></div>' +
    '<div class="ccm-skel-block ccm-skel-line ccm-skel-line1"></div>' +
    '<div class="ccm-skel-block ccm-skel-line ccm-skel-line2"></div>' +
    '<div class="ccm-skel-block ccm-skel-line ccm-skel-line3"></div>' +
    '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail1"></div>' +
    '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail2"></div>' +
    '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail3"></div>' +
    '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail4"></div>' +
    '<div class="ccm-skel-block ccm-skel-rail ccm-skel-rail5"></div>' +
    "</div>"
  );
}
function ccmHideSkeleton(root) {
  var sk = root
    ? root.querySelector("#ccm-skeleton")
    : document.getElementById("ccm-skeleton");
  if (!sk) return;
  sk.classList.add("ccm-skeleton-hide");
  (root || document).querySelectorAll("video.ccm-media").forEach(function (v) {
    if (v._dzGateTry) v._dzGateTry();
  }); /* the picture is visible now → the sound may start */
  setTimeout(function () {
    if (sk && sk.parentNode) sk.remove();
  }, 180);
}

/* ── SWITCHERS ── */
function _ccmInitAudioMarquee(slide) {
  if (!slide) return;
  var track = slide.querySelector("#ccm-audio-track");
  var text = slide.querySelector("#ccm-audio-track-text");
  if (!track || !text) return;
  requestAnimationFrame(function () {
    var overflow = text.scrollWidth - track.clientWidth;
    if (overflow > 4) {
      text.classList.add("ccm-marquee");
      text.style.setProperty("--marquee-dist", -overflow - 6 + "px");
      var dur = Math.max(4.5, Math.min(14, overflow / 22));
      text.style.setProperty("--marquee-dur", dur + "s");
    }
  });
}
function _ccmInitInfoSwitcher(slide) {
  if (_ccmInfoSwitchTimer) {
    clearInterval(_ccmInfoSwitchTimer);
    _ccmInfoSwitchTimer = null;
  }
  if (!slide) return;
  var switcher = slide.querySelector("#ccm-info-switcher");
  if (!switcher) return;
  var panes = switcher.querySelectorAll(".ccm-switch-pane");
  if (panes.length < 2) return;
  var activeIdx = 0;
  panes.forEach(function (p, i) {
    p.classList.toggle("active", i === 0);
  });
  _ccmInfoSwitchTimer = setInterval(function () {
    panes[activeIdx].classList.remove("active");
    activeIdx = (activeIdx + 1) % panes.length;
    panes[activeIdx].classList.add("active");
  }, 5000);
}

/* ── CTA PILL CYCLE (ad posts) ── */
function _ccmInitCtaPillCycle(slide) {
  if (_ccmCtaPillTimer) {
    clearInterval(_ccmCtaPillTimer);
    _ccmCtaPillTimer = null;
  }
  if (!slide) return;
  var pill = slide.querySelector("#ccm-cta-pill");
  if (!pill) return;
  pill.classList.remove("ccm-cta-pill-expanded");
  var expanded = false;
  _ccmCtaPillTimer = setInterval(function () {
    expanded = !expanded;
    pill.classList.toggle("ccm-cta-pill-expanded", expanded);
  }, 3000);
}

/* ── ATTACHED SOUNDTRACK ── */
function _ccmEnsureSoundtrackEl() {
  var stage = document.getElementById("ccm-stage");
  if (!stage) return null;
  var el = document.getElementById("ccmSoundtrackPlayer");
  if (!el) {
    el = document.createElement("audio");
    el.id = "ccmSoundtrackPlayer";
    el.loop = true;
    el.style.display = "none";
    stage.appendChild(el);
  }
  return el;
}

/* ══ NOT INTERESTED (menu → "Not Interested") ══
   The post scrolls on to the next one by itself and that post shows a card "We will suggest fewer videos like this" with Undo.
   Not undone = the server keeps the not_interested mark, so the post is not shown again and similar posts are recommended less.
   Undo = the mark is removed and zeems goes back to the post. Called from shared_modals.php (notInterestedFromSheet). */
var _ccmNi = { marked: {}, pending: null, popFor: null, timer: null };
function _ccmNiPost(action, id) {
  var body =
    "action=" +
    action +
    "&meme_id=" +
    encodeURIComponent(id) +
    (window.DZ_CSRF ? "&csrf=" + encodeURIComponent(window.DZ_CSRF) : "");
  return fetch(window.location.pathname, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: body,
  }).then(function (r) {
    return r.json();
  });
}
function _ccmNiIndexOf(id) {
  for (var i = 0; i < ccmFeed.length; i++) {
    if (ccmFeed[i].type === "post" && String(ccmFeed[i].data.id) === String(id))
      return i;
  }
  return -1;
}
function _ccmNiHidePage(id, hide) {
  try {
    document
      .querySelectorAll('.meme-tile[data-id="' + id + '"]')
      .forEach(function (e) {
        e.style.display = hide ? "none" : "";
      });
  } catch (e) {}
}
function ccmNotInterested(id) {
  dzHaptic.medium();
  id = String(id);
  var idx = _ccmNiIndexOf(id),
    isAd = idx >= 0 && !!ccmFeed[idx].data.isAd;
  _ccmNi.marked[id] = true;
  _ccmNiHidePage(id, true);
  _ccmNiPost("not_interested", id).catch(function () {});
  _ccmNi.pending = {
    id: id,
    text: isAd
      ? "We will show fewer ads like this"
      : "We will suggest fewer videos like this",
  };
  /* on to the next post that is not marked (or the previous one when this was the last) */
  var to = -1,
    i;
  for (i = idx + 1; i < ccmFeed.length; i++) {
    if (
      ccmFeed[i].type !== "post" ||
      !_ccmNi.marked[String(ccmFeed[i].data.id)]
    ) {
      to = i;
      break;
    }
  }
  if (to < 0)
    for (i = idx - 1; i >= 0; i--) {
      if (
        ccmFeed[i].type !== "post" ||
        !_ccmNi.marked[String(ccmFeed[i].data.id)]
      ) {
        to = i;
        break;
      }
    }
  if (to < 0) {
    closeCardCaptionModal();
    _ccmNi.pending = null;
    return;
  }
  _ccmVSwipeLocked = false;
  ccmLoadIndex(to);
  /* if the move did not start (locked / same index) show the card right away */
  setTimeout(function () {
    if (_ccmNi.pending) _ccmNiShowCard();
  }, CCM_SLIDE_MS + 500);
}
function _ccmNiCardEl() {
  var el = document.getElementById("ccm-ni-pop");
  if (!el) {
    var stage = document.getElementById("ccm-stage");
    if (!stage) return null;
    el = document.createElement("div");
    el.id = "ccm-ni-pop";
    el.className = "ccm-ni-pop";
    el.innerHTML =
      '<div class="ccm-ni-text"></div><button type="button" class="ccm-ni-undo" onclick="event.stopPropagation();ccmNiUndo()">Undo</button>';
    el.addEventListener("click", function (e) {
      e.stopPropagation();
    });
    stage.appendChild(el);
  }
  return el;
}
function _ccmNiShowCard() {
  var pend = _ccmNi.pending;
  if (!pend) return;
  _ccmNi.pending = null;
  var el = _ccmNiCardEl();
  if (!el) return;
  el.querySelector(".ccm-ni-text").textContent = pend.text;
  el.dataset.postId = pend.id;
  /* stop the card right before the "Zeems" / "Advert" word, whatever its width */
  try {
    var _lb = document.querySelector("#ccm-topbar-label .ccm-brand-label"),
      _st = document.getElementById("ccm-stage");
    if (_lb && _st) {
      var _lr = _lb.getBoundingClientRect(),
        _sr = _st.getBoundingClientRect();
      el.style.right = Math.max(8, Math.round(_sr.right - _lr.left + 8)) + "px";
    }
  } catch (e) {}
  _ccmNi.popFor = _ccmMemeId;
  void el.offsetWidth;
  el.classList.add("show");
  clearTimeout(_ccmNi.timer);
  _ccmNi.timer = setTimeout(_ccmNiHideCard, 6000);
}
function _ccmNiHideCard() {
  clearTimeout(_ccmNi.timer);
  var el = document.getElementById("ccm-ni-pop");
  if (el) el.classList.remove("show");
  _ccmNi.popFor = null;
}
function ccmNiUndo() {
  var el = document.getElementById("ccm-ni-pop");
  var id = el ? el.dataset.postId : null;
  if (!id) return;
  delete _ccmNi.marked[id];
  _ccmNiHidePage(id, false);
  _ccmNiPost("undo_not_interested", id).catch(function () {});
  _ccmNiHideCard();
  var idx = _ccmNiIndexOf(id);
  if (idx >= 0) {
    _ccmVSwipeLocked = false;
    ccmLoadIndex(idx);
  }
}
/* called after every slide change: show the pending card on the new post, hide it once the person moves on, skip marked posts */
function _ccmNiOnSlide(id) {
  if (_ccmNi.pending) {
    _ccmNiShowCard();
    return;
  }
  var el = document.getElementById("ccm-ni-pop");
  if (
    el &&
    el.classList.contains("show") &&
    String(id) !== String(_ccmNi.popFor)
  )
    _ccmNiHideCard();
  if (id !== null && _ccmNi.marked[String(id)]) {
    var at = _ccmNiIndexOf(id),
      n = at + 1;
    while (
      n < ccmFeed.length &&
      ccmFeed[n].type === "post" &&
      _ccmNi.marked[String(ccmFeed[n].data.id)]
    )
      n++;
    if (n >= ccmFeed.length) {
      n = at - 1;
      while (
        n >= 0 &&
        ccmFeed[n].type === "post" &&
        _ccmNi.marked[String(ccmFeed[n].data.id)]
      )
        n--;
    }
    if (n >= 0 && n < ccmFeed.length)
      setTimeout(function () {
        _ccmVSwipeLocked = false;
        ccmLoadIndex(n);
      }, 60);
  }
}
window.ccmNotInterested = ccmNotInterested;

/* ══ INTERESTED / NOT INTERESTED (the two buttons at the bottom) ══
   Once in a while, a few seconds into a SUGGESTED post (creator you do not track, not yours; ads, search results and reposts count) the two buttons pop up.
   Not on the profile, messages, group and history pages (set window.CCM_INTEREST_PROMPT = false on any other page to switch it off there too).
   Rules: never twice for the same post, at least 4 posts apart, roughly every other suggested post. */
var _ccmAsk = {
  n: 0,
  last: -99,
  done: {},
  open: {},
  plan: {},
  planIx: -99,
  timer: null,
  hideT: null,
  id: null,
}; /* open = shown but no button pressed yet: it comes back whenever you return to that post */
try {
  _ccmAsk.done = JSON.parse(sessionStorage.getItem("dz_zask") || "{}") || {};
} catch (e) {}
function _ccmAskAllowed() {
  if (window.CCM_INTEREST_PROMPT === false) return false;
  return !/(^|\/)(profile|messages|group|history|audio)[^\/]*\.php$/i.test(
    location.pathname,
  );
}
/* The two buttons are now part of the post's OWN slide (like the chat page's share button): decided when the slide is built, so they are already there while the post is still sliding in,
   nothing is added later, and the caption / avatars / rail are already raised on that slide only (no jump, no flash on the next post). */
function _ccmAskPlanned(p) {
  if (!p || p.id === undefined || p.id === null) return false;
  if (window.CCM_CHAT_SHARE || window.CCM_USE_SOUND || !_ccmAskAllowed())
    return false;
  var id = String(p.id);
  if (_ccmAsk.open[id])
    return true; /* shown before, never answered: it is back whenever you return */
  if (_ccmAsk.plan[id] !== undefined) return _ccmAsk.plan[id];
  var ok = !!((p.suggested || p.isAd) && !p.isYou && !_ccmAsk.done[id]);
  if (ok) {
    var ix = _ccmNiIndexOf(id);
    if (ix >= 0 && _ccmAsk.planIx > -90 && Math.abs(ix - _ccmAsk.planIx) < 4)
      ok = false; /* at least 4 posts apart */
    else if (Math.random() > 0.5)
      ok = false; /* roughly every other suggested post */
    else if (ix >= 0) _ccmAsk.planIx = ix;
  }
  _ccmAsk.plan[id] = ok;
  return ok;
}
function _ccmAskStaticHtml(p, isPreview) {
  if (isPreview || !_ccmAskPlanned(p)) return "";
  var id = _ccmEscapeHtml(String(p.id));
  return (
    '<div class="ccm-ask ccm-ask-static ccm-ask-int" data-id="' +
    id +
    '" ontouchstart="event.stopPropagation()" ontouchmove="event.stopPropagation()" ontouchend="event.stopPropagation()" onpointerdown="event.stopPropagation()" onpointerup="event.stopPropagation()" onclick="event.stopPropagation()">' +
    '<button type="button" class="ccm-ask-no" onclick="event.stopPropagation();ccmAskNo(\'' +
    id +
    '\')"><i class="ri-close-line"></i> Not interested</button>' +
    '<button type="button" class="ccm-ask-yes" onclick="event.stopPropagation();ccmAskYes(this,\'' +
    id +
    '\')"><i class="ri-check-line"></i> Interested</button></div>'
  );
}
function ccmAskNo(id) {
  delete _ccmAsk.open[id];
  document
    .querySelectorAll('.ccm-ask-int[data-id="' + id + '"]')
    .forEach(function (n) {
      if (n.parentNode) n.parentNode.removeChild(n);
    });
  ccmNotInterested(id);
}
function ccmAskYes(btn, id) {
  delete _ccmAsk.open[id];
  dzHaptic.success();
  _ccmNiPost("interested", id).catch(function () {});
  var el = btn.closest(".ccm-ask");
  if (!el) return;
  var ix = _ccmNiIndexOf(id),
    ad = ix >= 0 && !!ccmFeed[ix].data.isAd;
  el.innerHTML =
    '<div class="ccm-ask-thanks">' +
    (ad
      ? "We will suggest more ads like this for you"
      : "We will suggest more posts like this for you") +
    "</div>";
  clearTimeout(_ccmAsk.hideT);
  _ccmAsk.hideT = setTimeout(function () {
    if (el.parentNode) el.parentNode.removeChild(el);
  }, 2600);
}
window.ccmAskNo = ccmAskNo;
window.ccmAskYes = ccmAskYes;
function _ccmAskHide() {
  clearTimeout(_ccmAsk.timer);
  clearTimeout(_ccmAsk.hideT);
  var m = document.getElementById("cardCaptionModal");
  if (m) {
    m.classList.remove("ccm-asking");
    m.classList.remove("ccm-ask-pending");
  }
  var el = document.getElementById("ccm-ask");
  if (el) {
    el.removeAttribute("id");
    el.classList.remove("show");
    setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    }, 300);
  }
  _ccmAsk.id = null;
}
function _ccmAskShow(id) {
  var m = document.getElementById("cardCaptionModal"),
    slide = document.getElementById("ccm-slide");
  if (
    !m ||
    !slide ||
    !m.classList.contains("ccm-open") ||
    String(_ccmMemeId) !== String(id)
  )
    return;
  if (
    m.classList.contains("ccm-commenting") ||
    document.getElementById("ccm-ask")
  )
    return;
  var ni = document.getElementById("ccm-ni-pop");
  if (ni && ni.classList.contains("show")) return;
  _ccmAsk.done[id] = 1;
  _ccmAsk.open[id] = 1;
  _ccmAsk.last = _ccmAsk.n;
  _ccmAsk.id = id;
  try {
    sessionStorage.setItem("dz_zask", JSON.stringify(_ccmAsk.done));
  } catch (e) {}
  var el = document.createElement("div");
  el.id = "ccm-ask";
  el.className = "ccm-ask";
  el.innerHTML =
    '<button type="button" class="ccm-ask-no"><i class="ri-close-line"></i> Not interested</button>' +
    '<button type="button" class="ccm-ask-yes"><i class="ri-check-line"></i> Interested</button>';
  el.addEventListener("click", function (e) {
    e.stopPropagation();
  });
  el.addEventListener(
    "touchstart",
    function (e) {
      e.stopPropagation();
    },
    { passive: true },
  );
  el.querySelector(".ccm-ask-no").addEventListener("click", function (e) {
    e.stopPropagation();
    delete _ccmAsk.open[id];
    _ccmAskHide();
    ccmNotInterested(id);
  });
  el.querySelector(".ccm-ask-yes").addEventListener("click", function (e) {
    e.stopPropagation();
    delete _ccmAsk.open[id];
    dzHaptic.success();
    _ccmNiPost("interested", id).catch(function () {});
    var _ix = _ccmNiIndexOf(id),
      _ad = _ix >= 0 && !!ccmFeed[_ix].data.isAd;
    el.innerHTML =
      '<div class="ccm-ask-thanks">' +
      (_ad
        ? "We will suggest more ads like this for you"
        : "We will suggest more posts like this for you") +
      "</div>";
    clearTimeout(_ccmAsk.hideT);
    _ccmAsk.hideT = setTimeout(_ccmAskHide, 2600);
  });
  slide.appendChild(el);
  m.classList.add("ccm-asking");
  m.classList.remove("ccm-ask-pending");
  void el.offsetWidth;
  el.classList.add("show");
  /* no timer: the two buttons stay until you press one (they only go when you move to another post or close the player) */
}
/* ══ CHAT PAGE: a post you scrolled to that is not in this chat gets one wide button, "Share this in the Chat" (same spot and look as Interested / Not interested).
   The host (messages.php) provides window.CCM_CHAT_SHARE = { has(id), send(id) → Promise }. ══ */
var _ccmJetSvg =
  '<svg class="ccm-jet" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M241.42,198.2l-.06-.09L145.3,30.17a20,20,0,0,0-34.82,0L14.58,198.2a20,20,0,0,0,24.06,28.65L128,196.67l89.36,30.18a20,20,0,0,0,6.69,1.15,20,20,0,0,0,17.37-29.8ZM140,175.39V120a12,12,0,0,0-24,0v55.39L40.72,200.82,127.89,48.06l87.37,152.75Z"/></svg>';
function _ccmShareStaticHtml(p, isPreview) {
  /* AUDIO PAGE: a zeem opened from a sound's panel gets one red "Use this sound" button, same spot and size as "Share this in the Chat".
       The host (audio.php) sets window.CCM_USE_SOUND = { audioId, go() }. */
  if (window.CCM_USE_SOUND && p && p.id !== undefined && !p.isAd) {
    return (
      '<div class="ccm-ask ccm-ask-static" data-id="' +
      _ccmEscapeHtml(String(p.id)) +
      '" ontouchstart="event.stopPropagation()" ontouchmove="event.stopPropagation()" ontouchend="event.stopPropagation()" onpointerdown="event.stopPropagation()" onpointerup="event.stopPropagation()" onmousedown="event.stopPropagation()" onmouseup="event.stopPropagation()" onclick="event.stopPropagation()">' +
      '<button type="button" class="ccm-ask-share ccm-ask-sound" onclick="event.stopPropagation();ccmUseSoundClick()"><i class="ri-music-2-fill"></i><span>Use this sound</span></button></div>'
    );
  }
  if (
    !window.CCM_CHAT_SHARE ||
    !p ||
    p.id === undefined ||
    window.CCM_CHAT_SHARE.has(String(p.id))
  )
    return "";
  return (
    '<div class="ccm-ask ccm-ask-static" data-id="' +
    _ccmEscapeHtml(String(p.id)) +
    '" ontouchstart="event.stopPropagation()" ontouchmove="event.stopPropagation()" ontouchend="event.stopPropagation()" onpointerdown="event.stopPropagation()" onpointerup="event.stopPropagation()" onmousedown="event.stopPropagation()" onmouseup="event.stopPropagation()" onclick="event.stopPropagation()">' +
    '<button type="button" class="ccm-ask-share" onclick="event.stopPropagation();ccmChatShareClick(this,\'' +
    _ccmEscapeHtml(String(p.id)) +
    "')\">" +
    _ccmJetSvg +
    " <span>Share this in the Chat</span></button></div>"
  );
}
function ccmUseSoundClick() {
  dzHaptic.medium();
  if (window.CCM_USE_SOUND && typeof window.CCM_USE_SOUND.go === "function")
    window.CCM_USE_SOUND.go();
}
function ccmChatShareClick(btn, id) {
  if (btn._busy) return;
  btn._busy = true;
  dzHaptic.light();
  var sp = btn.querySelector("span");
  if (sp) sp.textContent = "Sending…";
  Promise.resolve(window.CCM_CHAT_SHARE.send(id))
    .then(function (ok) {
      if (ok === false) throw new Error("fail");
      document
        .querySelectorAll('.ccm-ask-static[data-id="' + id + '"]')
        .forEach(function (n) {
          n.parentNode && n.parentNode.removeChild(n);
        }); /* the button goes, a small notification pops up and fades by itself */
      if (typeof ccmToast === "function") ccmToast("Shared in the chat");
      dzHaptic.success();
    })
    .catch(function () {
      btn._busy = false;
      if (sp) sp.textContent = "Share this in the Chat";
      if (typeof ccmToast === "function")
        ccmToast("Could not share. Try again");
      dzHaptic.error();
    });
}
function _ccmShareShow(id) {
  var m = document.getElementById("cardCaptionModal"),
    slide = document.getElementById("ccm-slide");
  if (
    !m ||
    !slide ||
    !m.classList.contains("ccm-open") ||
    String(_ccmMemeId) !== String(id)
  )
    return;
  if (
    m.classList.contains("ccm-commenting") ||
    document.getElementById("ccm-ask")
  )
    return;
  var el = document.createElement("div");
  el.id = "ccm-ask";
  el.className = "ccm-ask";
  el.innerHTML =
    '<button type="button" class="ccm-ask-share"><svg class="ccm-jet" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M241.42,198.2l-.06-.09L145.3,30.17a20,20,0,0,0-34.82,0L14.58,198.2a20,20,0,0,0,24.06,28.65L128,196.67l89.36,30.18a20,20,0,0,0,6.69,1.15,20,20,0,0,0,17.37-29.8ZM140,175.39V120a12,12,0,0,0-24,0v55.39L40.72,200.82,127.89,48.06l87.37,152.75Z"/></svg> <span>Share this in the Chat</span></button>';
  [
    "click",
    "touchstart",
    "touchmove",
    "touchend",
    "touchcancel",
    "pointerdown",
    "pointerup",
    "mousedown",
    "mouseup",
  ].forEach(function (t) {
    el.addEventListener(
      t,
      function (e) {
        e.stopPropagation();
      },
      { passive: true },
    );
  }); /* a tap here must never reach the player's swipe / tap handling (it used to jump back a post) */
  var btn = el.querySelector("button"),
    busy = false;
  btn.addEventListener("click", function (e) {
    e.stopPropagation();
    if (busy) return;
    busy = true;
    btn.querySelector("span").textContent = "Sending…";
    Promise.resolve(window.CCM_CHAT_SHARE.send(id))
      .then(function (ok) {
        if (ok === false) throw new Error("fail");
        _ccmAskHide(); /* the button goes, a small notification pops up and fades by itself */
        if (typeof ccmToast === "function") ccmToast("Shared in the chat");
      })
      .catch(function () {
        busy = false;
        btn.querySelector("span").textContent = "Share this in the Chat";
        if (typeof ccmToast === "function")
          ccmToast("Could not share. Try again");
      });
  });
  el.style.transition =
    "none"; /* already there the moment the post is: no fade-in */
  slide.appendChild(el);
  m.classList.add("ccm-asking");
  el.classList.add("show");
}
function _ccmAskOnSlide(item) {
  _ccmAskHide();
  _ccmAsk.n++;
  if (
    window.CCM_CHAT_SHARE &&
    item &&
    item.type === "post" &&
    item.data &&
    !window.CCM_CHAT_SHARE.has(String(item.data.id))
  )
    return; /* the share button is already in the slide */
  if (!_ccmAskAllowed() || !item || item.type !== "post" || !item.data) return;
  var id = String(item.data.id);
  /* the buttons are already in this post's slide (built with it); landing on it counts as "shown" */
  if (_ccmAsk.plan[id] && !_ccmAsk.done[id]) {
    _ccmAsk.done[id] = 1;
    _ccmAsk.open[id] = 1;
    _ccmAsk.last = _ccmAsk.n;
    try {
      sessionStorage.setItem("dz_zask", JSON.stringify(_ccmAsk.done));
    } catch (e) {}
  }
}
document.addEventListener("zeems:closed", _ccmAskHide);
document.addEventListener("zeems:closed", function () {
  _ccmNiHideCard();
  _ccmNi.pending = null;
});

/* ══ BUFFERING SPINNER (see the CSS) ══ */
(function () {
  var DELAY = 400;
  function isZ(v) {
    return (
      v &&
      v.tagName === "VIDEO" &&
      v.classList &&
      v.classList.contains("ccm-media")
    );
  }
  function skeletonUp(v) {
    var sl = v.closest(".ccm-slide"),
      sk = sl ? sl.querySelector("#ccm-skeleton") : null;
    return !!(sk && !sk.classList.contains("ccm-skeleton-hide"));
  }
  function el(v, make) {
    var host = v.parentElement;
    if (!host) return null;
    var s = host.querySelector(":scope > .ccm-buf-spin");
    if (!s && make) {
      s = document.createElement("div");
      s.className = "ccm-buf-spin";
      host.appendChild(s);
    }
    return s;
  }
  function hide(v) {
    clearTimeout(v._dzSpinT);
    v._dzSpinT = null;
    clearTimeout(v._dzSpinMax);
    var s = el(v, false);
    if (!s) return;
    s.classList.remove("show");
    setTimeout(function () {
      if (s.parentNode && !s.classList.contains("show")) s.remove();
    }, 260);
  }
  function want(v) {
    if (!isZ(v) || v._dzSpinT) return;
    v._dzSpinT = setTimeout(function () {
      v._dzSpinT = null;
      if (
        !v.isConnected ||
        v.paused ||
        v.ended ||
        v.readyState >= 3 ||
        skeletonUp(v)
      )
        return; /* paused by the person, healthy again, or the skeleton is still covering */
      var s = el(v, true);
      if (!s) return;
      void s.offsetWidth;
      s.classList.add("show");
      clearTimeout(v._dzSpinMax);
      v._dzSpinMax = setTimeout(function () {
        hide(v);
      }, 20000); /* never spin forever */
    }, DELAY);
  }
  ["waiting", "seeking"].forEach(function (t) {
    document.addEventListener(
      t,
      function (e) {
        want(e.target);
      },
      true,
    );
  });
  [
    "playing",
    "canplay",
    "canplaythrough",
    "pause",
    "ended",
    "emptied",
    "error",
  ].forEach(function (t) {
    document.addEventListener(
      t,
      function (e) {
        if (isZ(e.target)) hide(e.target);
      },
      true,
    );
  });
  document.addEventListener(
    "seeked",
    function (e) {
      var v = e.target;
      if (isZ(v) && v.readyState >= 3) hide(v);
    },
    true,
  );
  document.addEventListener(
    "timeupdate",
    function (e) {
      var v = e.target;
      if (isZ(v) && v._dzSpinMax && !v.paused && v.readyState >= 3) hide(v);
    },
    true,
  );
})();

/* ══ THE SOUND NEVER STARTS BEFORE THE PICTURE ══
   When a video starts from the beginning it is held silent for a moment; its sound comes in as soon as its first frame is
   really on screen (first presented frame AND the loading skeleton gone). Never longer than 1.5 s. Hooked on the video's own
   'play' event, so it covers autoplay, swipes and every other way a video can start. The person's mute choice is still honoured. */
document.addEventListener(
  "play",
  function (e) {
    var v = e.target;
    if (
      !v ||
      v.tagName !== "VIDEO" ||
      !v.classList ||
      !v.classList.contains("ccm-media")
    )
      return;
    if (v.muted || v._dzGate || (v.currentTime || 0) > 0.3) return;
    ccmGateBegin(v);
  },
  true,
);
function ccmGateBegin(v) {
  v._dzGate = true;
  v.muted = true;
  var done = false,
    frame = false,
    timer = null;
  function finish(silent) {
    if (done) return;
    done = true;
    v._dzGate = false;
    clearTimeout(timer);
    v.removeEventListener("timeupdate", onTu);
    v._dzGateRelease = null;
    v._dzGateTry = null;
    if (silent === true || !v.isConnected) return;
    var it = ccmFeed[_ccmIndex],
      id = it && it.type === "post" ? it.data.id : _ccmMemeId;
    v.muted = !!(id && _ccmMutedPref[id]);
    ccmEnforceOneSound(); /* a post with separate music keeps its videos silent */
  }
  function tryRelease() {
    if (done || !frame) return;
    var sl = v.closest(".ccm-slide"),
      sk = sl ? sl.querySelector("#ccm-skeleton") : null;
    if (sk && !sk.classList.contains("ccm-skeleton-hide"))
      return; /* picture still covered */
    finish();
  }
  function onTu() {
    if (!v.paused && v.currentTime > 0.06) {
      frame = true;
      tryRelease();
    }
  }
  v._dzGateRelease = finish;
  v._dzGateTry = tryRelease;
  v.addEventListener("timeupdate", onTu);
  if (typeof v.requestVideoFrameCallback === "function") {
    try {
      v.requestVideoFrameCallback(function () {
        frame = true;
        tryRelease();
      });
    } catch (err) {}
  }
  timer = setTimeout(finish, 1500);
}

/* ══ ONE SOUND AT A TIME ══
   • music picked for the post (separate track)  → only the music is heard; every video of the post stays silent
   • the posts's own "original audio" with videos → the videos carry the sound; the soundtrack player is never started
     (it used to start together with the video, so on video 3 you heard video 3 AND the first video's audio)
   • only the video on screen may play; any other video of the post is stopped
   ccmEnforceOneSound() is called after every play / resume / seek / slide change, so nothing can slip through. */
function _ccmSoundtrackWanted(p) {
  if (!p || !p.audioFile) return false;
  if (!p.audioIsOriginal) return true;
  var media =
    Array.isArray(p.media) && p.media.length ? p.media : [{ type: p.type }];
  return !media.some(function (m) {
    return ((m && m.type) || "").toLowerCase() === "video";
  });
}
function ccmEnforceOneSound() {
  var slide = document.getElementById("ccm-slide");
  var it = ccmFeed[_ccmIndex];
  var p = it && it.type === "post" ? it.data : null;
  var trackEl = document.getElementById("ccmSoundtrackPlayer");
  if (slide) {
    var separate = !!(p && p.audioFile && !p.audioIsOriginal);
    slide.querySelectorAll(".ccm-media-item").forEach(function (item) {
      var v = item.querySelector("video.ccm-media");
      if (!v) return;
      var active = parseInt(item.dataset.idx, 10) === _ccmMediaIndex;
      if (!active && !v.paused) {
        try {
          v.pause();
        } catch (e) {}
      }
      if (separate && !v.muted) v.muted = true;
    });
  }
  if (trackEl && p && !_ccmSoundtrackWanted(p) && !trackEl.paused) {
    try {
      trackEl.pause();
    } catch (e) {}
  }
}

function _ccmSyncSoundtrack(item) {
  var audioEl = _ccmEnsureSoundtrackEl();
  if (!audioEl) return;

  var prevMemeId = audioEl.dataset.memeId || null;
  var p = item && item.type === "post" ? item.data : null;

  if (!p || !p.audioFile || !_ccmSoundtrackWanted(p)) {
    if (prevMemeId) _ccmTrackPositions[prevMemeId] = audioEl.currentTime || 0;
    audioEl.pause();
    audioEl.removeAttribute("src");
    delete audioEl.dataset.memeId;
    audioEl.load();
    if (p && p.audioIsOriginal) {
      /* the videos carry the sound: still apply the post's video volume */
      var ov =
        (p.videoAudioVolume !== undefined && p.videoAudioVolume !== null
          ? p.videoAudioVolume
          : 40) / 100;
      document
        .querySelectorAll('.ccm-vslide[data-pos="current"] video.ccm-media')
        .forEach(function (v) {
          v.volume = ov;
        });
    }
    ccmEnforceOneSound();
    return;
  }

  var isSamePost = prevMemeId !== null && String(prevMemeId) === String(p.id);

  if (!isSamePost) {
    if (prevMemeId) _ccmTrackPositions[prevMemeId] = audioEl.currentTime || 0;
    audioEl.src = p.audioFile;
    audioEl.dataset.memeId = p.id;
    audioEl.currentTime = _ccmTrackPositions[p.id] || 0;
    audioEl.muted = false;
  }
  audioEl.volume =
    (p.musicVolume !== undefined && p.musicVolume !== null
      ? p.musicVolume
      : 80) / 100;

  var curSlide = document.getElementById("ccm-slide");
  var mb = curSlide ? curSlide.querySelector("#ccm-media-box") : null;

  if (_ccmPausedMemeIds[p.id]) {
    audioEl.pause();
    if (mb) {
      var activeItemForPause =
        mb.querySelector(
          '.ccm-media-item[data-idx="' + _ccmMediaIndex + '"]',
        ) || mb;
      ccmShowPausedControls(activeItemForPause, audioEl);
    }
  } else {
    audioEl.play().catch(function () {});
  }

  var videoAudioVol =
    (p.videoAudioVolume !== undefined && p.videoAudioVolume !== null
      ? p.videoAudioVolume
      : 40) / 100;
  document
    .querySelectorAll('.ccm-vslide[data-pos="current"] video.ccm-media')
    .forEach(function (v) {
      v.volume = videoAudioVol;
    });
  ccmEnforceOneSound();
}

function _ccmPauseSoundtrack() {
  var el = document.getElementById("ccmSoundtrackPlayer");
  if (el) el.pause();
}
function _ccmResumeSoundtrack() {
  var el = document.getElementById("ccmSoundtrackPlayer");
  if (el && el.src) el.play().catch(function () {});
}
function _ccmStopSoundtrack() {
  var el = document.getElementById("ccmSoundtrackPlayer");
  if (el) {
    var leavingMemeId = el.dataset.memeId || null;
    if (leavingMemeId) _ccmTrackPositions[leavingMemeId] = el.currentTime || 0;
    el.pause();
    el.removeAttribute("src");
    delete el.dataset.memeId;
    el.load();
  }
}

/* ══ VIDEO PROGRESS ══ */
function ccmUpdateProgress(video) {
  if (!video.duration || isNaN(video.duration)) return;
  var slide = video.closest(".ccm-slide");
  var bar = slide ? slide.querySelector(".ccm-video-progress") : null;
  if (!bar || bar.classList.contains("scrubbing")) return;
  var pct = (video.currentTime / video.duration) * 100;
  var fill = bar.querySelector(".ccm-video-progress-fill");
  var thumb = bar.querySelector(".ccm-video-progress-thumb");
  if (fill) fill.style.width = pct + "%";
  if (thumb) thumb.style.left = pct + "%";
}

(function ccmProgressLoop() {
  var v = document.querySelector("#ccm-slide video.ccm-media");
  if (v && !v.paused && !v.ended && v.offsetParent !== null)
    ccmUpdateProgress(v);
  requestAnimationFrame(ccmProgressLoop);
})();

/* ══ DRAG-TO-SEEK on the progress bar (video + soundtrack in lockstep) ══ */
function ccmInitProgressScrub(bar) {
  if (!bar || bar._ccmScrubBound) return;
  bar._ccmScrubBound = true;

  var track = bar.querySelector(".ccm-video-progress-track");
  var fill = bar.querySelector(".ccm-video-progress-fill");
  var thumb = bar.querySelector(".ccm-video-progress-thumb");

  function getMedia() {
    var slide = bar.closest(".ccm-slide");
    var video = slide
      ? slide.querySelector(
          '.ccm-media-item[data-idx="' + _ccmMediaIndex + '"] video.ccm-media',
        ) || slide.querySelector("video.ccm-media")
      : null;
    var trackEl = document.getElementById("ccmSoundtrackPlayer");
    var hasTrack =
      trackEl &&
      trackEl.src &&
      trackEl.dataset.memeId &&
      String(trackEl.dataset.memeId) === String(_ccmMemeId);
    return { video: video, audio: hasTrack ? trackEl : null };
  }
  function pctFromX(x) {
    var r = track.getBoundingClientRect();
    return Math.max(0, Math.min(1, (x - r.left) / r.width));
  }
  function paint(pct) {
    fill.style.width = pct * 100 + "%";
    thumb.style.left = pct * 100 + "%";
  }
  /* the picture itself follows the finger frame by frame: one seek at a time, and the newest spot is applied the moment the last one lands */
  var mvEl = null,
    mvT = 0,
    mvPending = false,
    mvStamp = 0;
  function mvApply() {
    if (!mvEl) return;
    if (mvEl.seeking && Date.now() - mvStamp < 350) {
      mvPending = true;
      return;
    }
    mvPending = false;
    mvStamp = Date.now();
    try {
      mvEl.currentTime = mvT;
    } catch (e) {}
  }
  function mvSeeked() {
    if (mvPending) mvApply();
  }
  function mvBind(v) {
    mvUnbind();
    mvEl = v;
    if (v) v.addEventListener("seeked", mvSeeked);
  }
  function mvUnbind() {
    if (mvEl) mvEl.removeEventListener("seeked", mvSeeked);
    mvEl = null;
    mvPending = false;
  }
  function seekTo(pct, final) {
    var m = getMedia();
    if (m.video && !isNaN(m.video.duration)) {
      mvT = pct * m.video.duration;
      if (final) {
        try {
          m.video.currentTime = mvT;
        } catch (e) {}
      } else {
        if (mvEl !== m.video) mvBind(m.video);
        mvApply();
      }
    }
    if (m.audio && !isNaN(m.audio.duration))
      m.audio.currentTime = pct * m.audio.duration;
  }

  var dragging = false;

  /* Facebook-style preview: a small frame of the video at the spot being dragged to + "0:10 / 0:24" */
  var pv = null,
    pvVideo = null,
    pvTimeEl = null,
    pvT = 0;
  function fmtT(t) {
    t = Math.max(0, Math.floor(t || 0));
    var m = Math.floor(t / 60),
      s = t % 60;
    return m + ":" + (s < 10 ? "0" : "") + s;
  }
  function pvOpen() {
    return; /* no little preview box while scrubbing: the picture itself follows the finger */
    var m = getMedia(),
      slide = bar.closest(".ccm-slide");
    if (!slide || !m.video) return;
    pv = document.createElement("div");
    pv.className = "ccm-scrub-preview";
    var fr = document.createElement("div");
    fr.className = "ccm-scrub-frame";
    var vw = m.video.videoWidth,
      vh = m.video.videoHeight;
    if (vw && vh) fr.style.aspectRatio = vw + " / " + vh;
    var poster = m.video.getAttribute("poster");
    if (poster) fr.style.backgroundImage = 'url("' + poster + '")';
    pvVideo = document.createElement("video");
    pvVideo.muted = true;
    pvVideo.playsInline = true;
    pvVideo.preload = "auto";
    pvVideo.setAttribute("playsinline", "");
    pvVideo.addEventListener("seeked", function () {
      if (pvPending) pvApply();
    });
    pvVideo.addEventListener("loadedmetadata", function () {
      pvApply();
    });
    pvVideo.addEventListener("loadeddata", function () {
      try {
        var pr = pvVideo.play();
        if (pr && pr.then)
          pr.then(function () {
            pvVideo.pause();
            pvApply();
          }).catch(function () {});
      } catch (e) {}
    });
    pvVideo.src = m.video.currentSrc || m.video.src;
    fr.appendChild(pvVideo);
    pvTimeEl = document.createElement("div");
    pvTimeEl.className = "ccm-scrub-time";
    pv.appendChild(fr);
    pv.appendChild(pvTimeEl);
    slide.appendChild(pv);
  }
  var pvPending = false,
    pvStamp = 0;
  function pvApply() {
    if (!pvVideo) return;
    if (pvVideo.seeking && Date.now() - pvStamp < 350) {
      pvPending = true;
      return;
    } /* one seek at a time; the newest spot is applied when it lands */
    pvPending = false;
    pvStamp = Date.now();
    try {
      if (pvVideo.fastSeek && !/Chrome|Chromium/.test(navigator.userAgent))
        pvVideo.fastSeek(pvT);
      else pvVideo.currentTime = pvT;
    } catch (e) {}
  }
  function pvUpdate(pct) {
    var m = getMedia();
    if (!pv || !m.video || isNaN(m.video.duration)) return;
    pvT = pct * m.video.duration;
    pvTimeEl.innerHTML =
      fmtT(pvT) + " <span>/ " + fmtT(m.video.duration) + "</span>";
    /* the preview rides along above the finger and stays inside the picture, like Facebook's */
    var slide = pv.parentNode,
      sr = slide.getBoundingClientRect(),
      tr = track.getBoundingClientRect();
    var half = (pv.firstChild.offsetWidth || 110) / 2 + 8;
    var x = tr.left - sr.left + pct * tr.width;
    pv.style.left = Math.max(half, Math.min(sr.width - half, x)) + "px";
    pvApply();
  }
  function pvClose() {
    pvPending = false;
    if (pvVideo) {
      try {
        pvVideo.removeAttribute("src");
        pvVideo.load();
      } catch (e) {}
    }
    if (pv && pv.parentNode) pv.parentNode.removeChild(pv);
    pv = pvVideo = pvTimeEl = null;
  }

  function start(x) {
    dragging = true;
    bar.classList.add("scrubbing");
    var modalEl = document.getElementById("cardCaptionModal");
    if (modalEl) modalEl.classList.add("ccm-scrubbing");
    var m = getMedia();
    if (m.video) m.video.pause();
    if (m.audio) m.audio.pause();
    if (m.video) mvBind(m.video);
    pvOpen();
    var pct0 = pctFromX(x);
    paint(pct0);
    pvUpdate(pct0);
  }
  function move(x) {
    if (!dragging) return;
    var pct = pctFromX(x);
    paint(pct);
    seekTo(pct);
    pvUpdate(pct);
  }
  function end(x) {
    if (!dragging) return;
    dragging = false;
    seekTo(pctFromX(x), true);
    mvUnbind();
    pvClose();
    var modalEl2 = document.getElementById("cardCaptionModal");
    if (modalEl2) {
      modalEl2.classList.add("ccm-unscrubbed");
      modalEl2.classList.remove("ccm-scrubbing");
      setTimeout(function () {
        modalEl2.classList.remove("ccm-unscrubbed");
      }, 320);
    }
    bar.classList.remove("scrubbing");
    var wasManuallyPaused = _ccmMemeId
      ? !!_ccmPausedMemeIds[_ccmMemeId]
      : false;
    if (!wasManuallyPaused) {
      var m = getMedia();
      ccmEnforceOneSound();
      if (m.video) _ccmFadePlay(m.video);
      if (m.audio) _ccmFadePlay(m.audio);
      setTimeout(ccmEnforceOneSound, 0);
    }
  }

  bar.addEventListener(
    "touchstart",
    function (e) {
      e.stopPropagation();
      start(e.touches[0].clientX);
    },
    { passive: true },
  );
  bar.addEventListener(
    "touchmove",
    function (e) {
      e.stopPropagation();
      move(e.touches[0].clientX);
    },
    { passive: true },
  );
  bar.addEventListener(
    "touchend",
    function (e) {
      e.stopPropagation();
      end(e.changedTouches[0].clientX);
    },
    { passive: true },
  );
  bar.addEventListener(
    "touchcancel",
    function (e) {
      e.stopPropagation();
      end(e.changedTouches[0].clientX);
    },
    { passive: true },
  );

  bar.addEventListener("mousedown", function (e) {
    e.stopPropagation();
    start(e.clientX);
    function onMove(ev) {
      move(ev.clientX);
    }
    function onUp(ev) {
      document.removeEventListener("mousemove", onMove);
      document.removeEventListener("mouseup", onUp);
      end(ev.clientX);
    }
    document.addEventListener("mousemove", onMove);
    document.addEventListener("mouseup", onUp);
  });

  bar.addEventListener("click", function (e) {
    e.stopPropagation();
  });
}

/* ══════════════════════════════════════════════════════════
   SMOOTH PAUSE / RESUME (like Reels / TikTok)
   - sound fades out over ~160ms and then pauses (no click / pop); on resume it starts silent and fades up
   - video + soundtrack always fade together
   - the first tap reacts instantly (no waiting to see if it's a double-tap); a double-tap undoes it and likes
   (iOS Safari ignores script volume, so there the sound just starts/stops — video and UI are still smooth)
══════════════════════════════════════════════════════════ */
var CCM_FADE_MS = 90;
var CCM_DOUBLE_TAP_MS = 250;

function _ccmIsPaused(el) {
  return !!el && (el.paused || !!el._ccmPausing);
}

function _ccmRamp(el, to, ms, done) {
  if (!el) {
    if (done) done();
    return;
  }
  if (el._ccmRamp) cancelAnimationFrame(el._ccmRamp);
  var from = el.volume,
    t0 = performance.now();
  function step(now) {
    var k = Math.min(1, (now - t0) / ms);
    var e =
      k < 0.5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2; /* ease in-out */
    try {
      el.volume = Math.max(0, Math.min(1, from + (to - from) * e));
    } catch (_) {}
    if (k < 1) {
      el._ccmRamp = requestAnimationFrame(step);
    } else {
      el._ccmRamp = null;
      if (done) done();
    }
  }
  el._ccmRamp = requestAnimationFrame(step);
}
function _ccmFadePause(el) {
  if (!el || _ccmIsPaused(el)) return;
  var full = el._ccmRamp ? el._ccmFull : el.volume;
  el._ccmFull = full;
  el._ccmPausing = true;
  _ccmRamp(el, 0, CCM_FADE_MS, function () {
    el.pause();
    try {
      el.volume = full;
    } catch (_) {}
    el._ccmPausing = false;
    el._ccmFull = undefined;
  });
}
/* stuck-frame watchdog: a video that "plays" for a moment and then freezes is kicked, re-played,
   and as a last resort reloaded at the same time. Network buffering is left alone. */
function _ccmPlayWatchdog(el, step) {
  if (!el || el.tagName !== "VIDEO") return;
  if (el._ccmWd) {
    clearTimeout(el._ccmWd);
    el._ccmWd = null;
  }
  step = step || 0;
  var t0 = el.currentTime;
  el._ccmWd = setTimeout(function () {
    el._ccmWd = null;
    if (
      !el.isConnected ||
      !el.getAttribute("src") ||
      el.paused ||
      el._ccmPausing
    )
      return;
    if (el.seeking) return _ccmPlayWatchdog(el, step);
    var moved = Math.abs(el.currentTime - t0) > 0.01;
    if (moved) return; /* healthy */
    if (el.readyState < 3 && el.networkState === 2 && step < 3)
      return _ccmPlayWatchdog(el, step + 1); /* still downloading */
    if (step < 2) {
      try {
        el.currentTime = el.currentTime + 0.001;
      } catch (_) {} /* 1) kick the frame */
      var pr = el.play();
      if (pr && pr.catch) pr.catch(function () {});
      _ccmPlayWatchdog(el, 2);
    } else {
      /* 2) reload at the same time */
      var at = el.currentTime;
      var onMeta = function () {
        el.removeEventListener("loadedmetadata", onMeta);
        try {
          el.currentTime = at;
        } catch (_) {}
        var p2 = el.play();
        if (p2 && p2.catch) p2.catch(function () {});
      };
      el.addEventListener("loadedmetadata", onMeta);
      el.load();
    }
  }, 750);
}

function _ccmFadePlay(el) {
  if (!el) return;
  var full = el._ccmRamp ? el._ccmFull : el.volume;
  el._ccmFull = full;
  el._ccmPausing = false;
  try {
    el.volume = el._ccmRamp ? el.volume : 0;
  } catch (_) {}
  var up = function () {
    _ccmRamp(el, full, CCM_FADE_MS, function () {
      el._ccmFull = undefined;
    });
  };
  var pr = el.play();
  if (pr && pr.then)
    pr.then(up).catch(function () {
      try {
        el.volume = full;
      } catch (_) {}
    });
  else up();
  _ccmPlayWatchdog(el);
}

function _ccmPauseMedia(box, video, audio) {
  _ccmFadePause(video);
  _ccmFadePause(audio);
  if (_ccmMemeId) _ccmPausedMemeIds[_ccmMemeId] = true;
  ccmShowPausedControls(box, video || audio);
}
function _ccmResumeMedia(box, video, audio, quiet) {
  ccmEnforceOneSound(); /* stop any other video of the post before this one starts */
  _ccmFadePlay(video);
  _ccmFadePlay(audio);
  setTimeout(ccmEnforceOneSound, 0);
  if (_ccmMemeId) delete _ccmPausedMemeIds[_ccmMemeId];
  ccmHidePausedControlsSmooth(box);
  if (!quiet) ccmFlashIcon(box, "ri-play-fill");
}

/* ── TAP HANDLING ── */
/* a press on any rail button must never also pause / resume the video: cancel a waiting media tap and ignore one for a moment */
window._ccmRailAt = 0;
/* A tap NEAR a rail button (beside it, or in the gap between two buttons) opens the nearest button instead of pausing the video.
   Only taps in the middle / away from the rail reach the pause toggle. */
(function () {
  var SIDE_L = 22,
    SIDE_R = 14,
    VPAD = 8;
  function railBtns() {
    var out = [];
    document
      .querySelectorAll("#cardCaptionModal .ccm-rail, #ccm-side-rail")
      .forEach(function (r) {
        if (!r.offsetParent && getComputedStyle(r).position !== "fixed") return;
        r.querySelectorAll(".ccm-rail-btn").forEach(function (b) {
          var q = b.getBoundingClientRect();
          if (q.width && q.height) out.push({ b: b, r: q });
        });
      });
    return out;
  }
  function nearTap(e) {
    if (e.__ccmRailFix) return;
    var t = e.target;
    if (!t || !t.closest) return;
    if (
      t.closest(
        ".ccm-rail, #ccm-side-rail, a, button, input, textarea, .ccm-video-progress, .ccm-more-toggle, #ccm-fixed-topbar, #ccmFixedBackBtn, .ccm-user-pic, .ccm-username",
      )
    )
      return;
    if (!t.closest("#ccm-stage")) return;
    var x = e.clientX,
      y = e.clientY;
    if (typeof x !== "number" || (x === 0 && y === 0)) return;
    var list = railBtns();
    if (!list.length) return;
    var best = null,
      bd = 1e9,
      L = 1e9,
      R = -1e9,
      T = 1e9,
      B = -1e9;
    list.forEach(function (it) {
      L = Math.min(L, it.r.left);
      R = Math.max(R, it.r.right);
      T = Math.min(T, it.r.top);
      B = Math.max(B, it.r.bottom);
      var cy = (it.r.top + it.r.bottom) / 2,
        d = Math.abs(cy - y);
      if (d < bd) {
        bd = d;
        best = it;
      }
    });
    if (x < L - SIDE_L || x > R + SIDE_R || y < T - VPAD || y > B + VPAD)
      return;
    window._ccmRailAt = Date.now();
    if (e.type !== "click") return;
    e.stopPropagation();
    e.preventDefault();
    if (e.type === "click") {
      e.__ccmRailFix = true;
      try {
        best.b.click();
      } catch (_) {}
    }
  }
  [
    "click",
    "pointerdown",
    "mousedown",
    "touchstart",
    "touchend",
    "pointerup",
  ].forEach(function (ev) {
    document.addEventListener(ev, nearTap, true);
  });
})();
(function () {
  function mark(e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest(".ccm-rail, #ccm-side-rail")) return;
    window._ccmRailAt = Date.now();
    document.querySelectorAll(".ccm-media-box").forEach(function (m) {
      clearTimeout(m._ccmTapTimer);
      m._ccmTapTimer = null;
      m._ccmLastTap = 0;
    });
  }
  [
    "pointerdown",
    "touchstart",
    "mousedown",
    "click",
    "touchend",
    "pointerup",
  ].forEach(function (ev) {
    document.addEventListener(ev, mark, true);
  });
})();
function ccmHandleMediaTap(e, mediaBox, isGallery) {
  var now = Date.now();
  if (now - (window._ccmRailAt || 0) < 450) return;
  var dt = now - (mediaBox._ccmLastTap || 0);
  var toggle = function (quiet) {
    if (isGallery) ccmMediaBoxTap(mediaBox, quiet);
    else ccmToggleVideoPlay(mediaBox, quiet);
  };

  /* the first tap just closed the caption (nothing was paused / resumed) → this is the 2nd tap of a double-tap: like only */
  if (_ccmCapTapAt > 0 && now - _ccmCapTapAt < CCM_DOUBLE_TAP_MS) {
    _ccmCapTapAt = 0;
    mediaBox._ccmLastTap = 0;
    ccmDoubleTapLike(mediaBox, e);
    mediaBox._ccmLikeUntil = now + CCM_DOUBLE_TAP_MS;
    return;
  }
  /* quick extra taps right after a double-tap keep liking (hearts), they never pause */
  if (mediaBox._ccmLikeUntil && now < mediaBox._ccmLikeUntil) {
    mediaBox._ccmLikeUntil = now + CCM_DOUBLE_TAP_MS;
    ccmDoubleTapLike(mediaBox, e);
    return;
  }
  /* RESUMING a paused video happens on the touch itself (no waiting a double-tap window). A second tap right after it means the person wanted a LIKE:
       the resume is taken back quietly and the like goes through. */
  if (mediaBox._ccmInstant && dt > 0 && dt < CCM_DOUBLE_TAP_MS) {
    clearTimeout(mediaBox._ccmInstT);
    mediaBox._ccmInstant = false;
    mediaBox._ccmLastTap = 0;
    toggle(true);
    mediaBox._ccmLikeUntil = now + CCM_DOUBLE_TAP_MS;
    ccmDoubleTapLike(mediaBox, e);
    return;
  }
  if (!(dt > 0 && dt < CCM_DOUBLE_TAP_MS && mediaBox._ccmTapTimer)) {
    var _pv = isGallery
      ? mediaBox.querySelector(
          '.ccm-media-item[data-idx="' + _ccmMediaIndex + '"] video.ccm-media',
        )
      : mediaBox.querySelector("video.ccm-media");
    var _tr = document.getElementById("ccmSoundtrackPlayer");
    var _pm = _pv || (_tr && _tr.src ? _tr : null);
    if (_pm && _ccmIsPaused(_pm) && !mediaBox._ccmTapTimer) {
      mediaBox._ccmLastTap = now;
      mediaBox._ccmInstant = true;
      clearTimeout(mediaBox._ccmInstT);
      mediaBox._ccmInstT = setTimeout(function () {
        mediaBox._ccmInstant = false;
        mediaBox._ccmLastTap = 0;
      }, CCM_DOUBLE_TAP_MS);
      dzHaptic.light(); /* resume: the same single pulse as the menu button, felt on the touch itself */
      toggle(false);
      return;
    }
  }
  if (dt > 0 && dt < CCM_DOUBLE_TAP_MS && mediaBox._ccmTapTimer) {
    /* second tap of a double-tap: the pause/resume of the first tap has NOT happened yet (it waits for this) → cancel it and like */
    clearTimeout(mediaBox._ccmTapTimer);
    mediaBox._ccmTapTimer = null;
    mediaBox._ccmLastTap = 0;
    mediaBox._ccmLikeUntil = now + CCM_DOUBLE_TAP_MS;
    ccmDoubleTapLike(mediaBox, e);
  } else {
    /* single tap: wait one double-tap window; only if no second tap comes does the video pause / resume */
    mediaBox._ccmLastTap = now;
    dzHaptic.light(); /* pause: the same single pulse as the menu button, on the touch (the pause itself waits one double-tap window) */
    clearTimeout(mediaBox._ccmTapTimer);
    mediaBox._ccmTapTimer = setTimeout(function () {
      mediaBox._ccmTapTimer = null;
      mediaBox._ccmLastTap = 0;
      if (mediaBox.isConnected && Date.now() - (window._ccmRailAt || 0) > 450)
        toggle(false);
    }, CCM_DOUBLE_TAP_MS);
  }
}

function ccmDoubleTapLike(mediaBox, e) {
  dzHaptic.medium(); /* felt at the tap itself (the like lands a moment later) */
  var rect = mediaBox.getBoundingClientRect();
  var cx =
    e && typeof e.clientX === "number" && e.clientX !== 0
      ? e.clientX
      : rect.left + rect.width / 2;
  var cy =
    e && typeof e.clientY === "number" && e.clientY !== 0
      ? e.clientY
      : rect.top + rect.height / 2;
  var item = ccmFeed[_ccmIndex];
  var slideEl = document.getElementById("ccm-slide");
  var doLike = function () {
    if (!item || item.type !== "post") return;
    var likeBtn = slideEl ? slideEl.querySelector("#ccm-like-btn") : null;
    if (likeBtn && !likeBtn.classList.contains("active")) {
      window._ccmHapSkip = true;
      ccmAction("like");
    }
  };
  /* the heart pops where you tapped, then jumps to the like button on the rail and the like happens when it lands */
  ccmShowHeartBurst(mediaBox, cx - rect.left, cy - rect.top, doLike);
}

function ccmLikeTargetIcon() {
  var side = document.getElementById("ccm-side-rail");
  if (
    side &&
    side.offsetParent !== null &&
    getComputedStyle(side).display !== "none"
  ) {
    var i2 = side.querySelector('[onclick*="like"] i');
    if (i2 && i2.offsetParent !== null) return i2;
  }
  return document.querySelector("#ccm-slide #ccm-like-btn i");
}
var _ccmFly = null;
/* Inside a CSS-zoomed card (the ad posts on the search page) a browser can report an element's rectangle in a different scale than the pixels
   the flying heart is placed in. A hidden 100px probe inside the element tells us the real scale, and the rectangle is corrected with it. */
function _ccmZoomK(el) {
  try {
    var zoomed = false;
    for (var a = el; a && a.nodeType === 1; a = a.parentElement) {
      var z = parseFloat(getComputedStyle(a).zoom);
      if (z && isFinite(z) && Math.abs(z - 1) > 0.001) {
        zoomed = true;
        break;
      }
    }
    if (!zoomed) return 1;
    var ez = 1;
    for (var b = el; b && b.nodeType === 1; b = b.parentElement) {
      var zb = parseFloat(getComputedStyle(b).zoom);
      if (zb && isFinite(zb)) ez *= zb;
    }
    var pr = document.createElement("div");
    pr.style.cssText =
      "position:absolute;visibility:hidden;pointer-events:none;width:100px;height:100px;left:0;top:0;";
    el.appendChild(pr);
    var w = pr.getBoundingClientRect().width;
    el.removeChild(pr);
    var k = w / (100 * ez);
    return k > 0.2 && k < 5 ? k : 1;
  } catch (e) {
    return 1;
  }
}
function _ccmTrueRect(el, k) {
  var r = el.getBoundingClientRect();
  if (!k || Math.abs(k - 1) < 0.01) return r;
  return {
    left: r.left / k,
    top: r.top / k,
    width: r.width / k,
    height: r.height / k,
    right: r.right / k,
    bottom: r.bottom / k,
  };
}
function ccmShowHeartBurst(mediaBox, x, y, onLand, targetIcon) {
  if (window.CCM_DT_STYLE === "classic") {
    ccmShowHeartBurstClassic(mediaBox, x, y);
    if (onLand) onLand();
    return;
  }
  /* a new double-tap while one heart is still flying: that heart lands now (so its like is not lost) */
  if (_ccmFly) {
    try {
      _ccmFly.finish();
    } catch (e) {}
  }
  var rect = mediaBox.getBoundingClientRect();
  var cx = rect.left + x,
    cy = rect.top + y;
  var S = Math.round(Math.min(rect.width, rect.height) * 0.26);
  S = Math.max(78, Math.min(118, S));
  var h = document.createElement("div");
  h.className = "ccm-ig-heart";
  h.style.cssText =
    "position:fixed;z-index:2147483300;pointer-events:none;left:" +
    (cx - S / 2) +
    "px;top:" +
    (cy - S / 2) +
    "px;width:" +
    S +
    "px;height:" +
    S +
    "px;margin:0;opacity:0;animation:none;";
  h.innerHTML =
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>';
  document.body.appendChild(h);
  var tgt = targetIcon || ccmLikeTargetIcon(),
    tgtK = tgt ? _ccmZoomK(tgt) : 1,
    tr = tgt ? _ccmTrueRect(tgt, tgtK) : null;
  var dx = tr ? tr.left + tr.width / 2 - cx : 0,
    dy = tr ? tr.top + tr.height / 2 - cy : -S;
  var k = tr ? Math.max(0.14, (tr.width * 0.95) / S) : 0.5;
  var r = (Math.random() * 2 - 1) * 16;
  var POP = 625,
    FLY = 600,
    landed = false,
    raf = 0,
    flying = false,
    cancelled = false;
  /* pop + hold (runs on the compositor) */
  var kf = [
    {
      offset: 0,
      transform: "translate(0,0) scale(0) rotate(" + r + "deg)",
      opacity: 1,
      easing: "cubic-bezier(.2,.9,.3,1.2)",
    },
    {
      offset: 0.26,
      transform: "translate(0,0) scale(1.28) rotate(" + r * 0.5 + "deg)",
      opacity: 1,
      easing: "cubic-bezier(.4,0,.4,1)",
    },
    {
      offset: 0.43,
      transform: "translate(0,0) scale(.92) rotate(" + r + "deg)",
      opacity: 1,
      easing: "cubic-bezier(.4,0,.4,1)",
    },
    {
      offset: 0.58,
      transform: "translate(0,0) scale(1) rotate(" + r + "deg)",
      opacity: 1,
    },
    {
      offset: 1,
      transform: "translate(0,0) scale(1.06) rotate(" + r + "deg)",
      opacity: 1,
    },
  ];
  var anim = h.animate(kf, { duration: POP, fill: "forwards" });
  function land() {
    if (landed) return;
    landed = true;
    var t2 = tgt && tgt.isConnected ? tgt : null,
      rr = t2 ? _ccmTrueRect(t2, tgtK) : null;
    if (t2 && rr && rr.width) {
      t2.classList.remove("ccm-land");
      void t2.offsetWidth;
      t2.classList.add("ccm-land");
      setTimeout(function () {
        t2.classList.remove("ccm-land");
      }, 600);
      var ring = document.createElement("div"),
        sz = rr.width * 1.2;
      ring.style.cssText =
        "position:fixed;z-index:2147483300;pointer-events:none;border-radius:50%;border:2px solid #ff3040;left:" +
        (rr.left + rr.width / 2 - sz / 2) +
        "px;top:" +
        (rr.top + rr.height / 2 - sz / 2) +
        "px;width:" +
        sz +
        "px;height:" +
        sz +
        "px;";
      document.body.appendChild(ring);
      ring.animate(
        [
          { transform: "scale(.6)", opacity: 0.9 },
          { transform: "scale(2.3)", opacity: 0 },
        ],
        { duration: 480, easing: "cubic-bezier(.2,.6,.3,1)", fill: "forwards" },
      ).onfinish = function () {
        ring.remove();
      };
    }
    if (onLand) onLand();
  }
  function cleanup() {
    cancelAnimationFrame(raf);
    h.remove();
    if (_ccmFly === ctl) _ccmFly = null;
  }
  /* flight: the target is read again on every frame, so it still lands on the like button if the page moves or animates */
  function fly() {
    flying = true;
    var t0 = performance.now();
    function frame(now) {
      if (cancelled) return;
      var p = Math.min(1, (now - t0) / FLY),
        e =
          p < 0.5
            ? 2 * p * p
            : 1 - Math.pow(-2 * p + 2, 2) / 2; /* ease in-out */
      var ti = tgt && tgt.isConnected ? tgt : null,
        rr = ti ? _ccmTrueRect(ti, tgtK) : null;
      var tx = rr && rr.width ? rr.left + rr.width / 2 : cx,
        ty = rr && rr.width ? rr.top + rr.height / 2 : cy - S;
      var kk = rr && rr.width ? Math.max(0.14, (rr.width * 0.95) / S) : 0.5;
      var ddx = (tx - cx) * e,
        ddy =
          (ty - cy) * e -
          Math.sin(Math.PI * p) *
            Math.min(90, Math.hypot(tx - cx, ty - cy) * 0.28);
      var sc = 1.06 + (kk - 1.06) * e;
      var sx = sc * (1 + 0.05 * Math.sin(Math.PI * p)),
        sy = sc * (1 - 0.05 * Math.sin(Math.PI * p));
      h.style.transform =
        "translate(" +
        ddx +
        "px," +
        ddy +
        "px) scale(" +
        sx +
        "," +
        sy +
        ") rotate(" +
        r * (1 - e) +
        "deg)";
      h.style.opacity = p > 0.96 ? String(Math.max(0, (1 - p) / 0.04)) : "1";
      if (p < 1) raf = requestAnimationFrame(frame);
      else {
        land();
        cleanup();
      }
    }
    raf = requestAnimationFrame(frame);
  }
  anim.onfinish = function () {
    if (cancelled) return;
    try {
      anim.commitStyles();
    } catch (e) {}
    anim.cancel(); /* hand the heart's transform over to the per-frame flight */
    h.style.opacity = "1";
    h.style.transform = "translate(0,0) scale(1.06) rotate(" + r + "deg)";
    fly();
  };
  /* a second double-tap while this heart is still in the air: it lands now (so its like is not lost) */
  var ctl = {
    finish: function () {
      cancelled = true;
      cancelAnimationFrame(raf);
      try {
        anim.cancel();
      } catch (e) {}
      land();
      cleanup();
    },
  };
  _ccmFly = ctl;
  return;
  /* Instagram style: a single white heart, tilted a random few degrees, pops in with a small overshoot, holds, lifts and fades */
  mediaBox.querySelectorAll(".ccm-ig-heart").forEach(function (el) {
    el.remove();
  });
  var h = document.createElement("div");
  h.className = "ccm-ig-heart";
  h.style.left = x + "px";
  h.style.top = y + "px";
  h.style.setProperty("--r", ((Math.random() * 2 - 1) * 18).toFixed(1) + "deg");
  h.innerHTML =
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>';
  mediaBox.appendChild(h);
  void h.offsetWidth;
  h.classList.add("show");
  setTimeout(function () {
    h.remove();
  }, 1050);
}
function ccmShowHeartBurstClassic(mediaBox, x, y) {
  mediaBox
    .querySelectorAll(
      ".ccm-double-tap-heart, .ccm-heart-ring, .ccm-heart-particle",
    )
    .forEach(function (el) {
      el.remove();
    });

  var ring = document.createElement("div");
  ring.className = "ccm-heart-ring";
  ring.style.left = x + "px";
  ring.style.top = y + "px";
  mediaBox.appendChild(ring);
  requestAnimationFrame(function () {
    ring.classList.add("show");
  });
  setTimeout(function () {
    ring.remove();
  }, 800);

  var heart = document.createElement("div");
  heart.className = "ccm-double-tap-heart";
  heart.style.left = x + "px";
  heart.style.top = y + "px";
  heart.innerHTML = '<i class="ri-heart-3-fill"></i>';
  mediaBox.appendChild(heart);
  requestAnimationFrame(function () {
    heart.classList.add("show");
  });
  setTimeout(function () {
    heart.remove();
  }, 950);

  var particleCount = 8;
  for (var i = 0; i < particleCount; i++) {
    (function (i) {
      var angle = (360 / particleCount) * i + (Math.random() * 20 - 10);
      var rad = (angle * Math.PI) / 180;
      var distance = 70 + Math.random() * 50;
      var tx = Math.cos(rad) * distance;
      var ty = Math.sin(rad) * distance;
      var rot = Math.random() * 80 - 40;
      var size = 14 + Math.random() * 12;

      var p = document.createElement("div");
      p.className = "ccm-heart-particle";
      p.style.left = x + "px";
      p.style.top = y + "px";
      p.style.setProperty("--tx", tx + "px");
      p.style.setProperty("--ty", ty + "px");
      p.style.setProperty("--tr", rot + "deg");
      p.innerHTML =
        '<i class="ri-heart-3-fill" style="font-size:' + size + 'px;"></i>';
      mediaBox.appendChild(p);

      setTimeout(
        function () {
          requestAnimationFrame(function () {
            p.classList.add("show");
          });
        },
        40 + i * 15,
      );
      setTimeout(
        function () {
          p.remove();
        },
        950 + i * 15,
      );
    })(i);
  }
}

/* ── TAP TO PAUSE/RESUME + MUTE + 10s SEEK ── */
function ccmToggleVideoPlay(mediaBox, quiet) {
  var video = mediaBox.querySelector("video.ccm-media");
  var audioEl = document.getElementById("ccmSoundtrackPlayer");
  var track = audioEl && audioEl.src ? audioEl : null;
  if (!video) {
    if (!track) return;
    if (_ccmIsPaused(track)) _ccmResumeMedia(mediaBox, null, track, quiet);
    else _ccmPauseMedia(mediaBox, null, track);
    return;
  }
  if (_ccmIsPaused(video)) _ccmResumeMedia(mediaBox, video, track, quiet);
  else _ccmPauseMedia(mediaBox, video, track);
}

function ccmMediaBoxTap(mediaBox, quiet) {
  var activeItem = mediaBox.querySelector(
    '.ccm-media-item[data-idx="' + _ccmMediaIndex + '"]',
  );
  if (!activeItem) return;
  var video = activeItem.querySelector("video.ccm-media");
  var audioEl = document.getElementById("ccmSoundtrackPlayer");
  var track = audioEl && audioEl.src ? audioEl : null;

  if (video) {
    if (_ccmIsPaused(video)) _ccmResumeMedia(activeItem, video, track, quiet);
    else _ccmPauseMedia(activeItem, video, track);
    return;
  }
  if (track) {
    if (_ccmIsPaused(track)) _ccmResumeMedia(activeItem, null, track, quiet);
    else _ccmPauseMedia(activeItem, null, track);
  }
}

function ccmFlashIcon(mediaBox, iconClass) {
  var existing = mediaBox.querySelector(".ccm-tap-flash");
  if (existing) existing.remove();
  var icon = document.createElement("div");
  icon.className = "ccm-tap-flash";
  icon.innerHTML =
    iconClass === "ri-play-fill"
      ? ccmIco("play")
      : '<i class="' + iconClass + '"></i>';
  mediaBox.appendChild(icon);
  requestAnimationFrame(function () {
    icon.classList.add("show");
  });
  setTimeout(function () {
    icon.classList.remove("show");
    setTimeout(function () {
      icon.remove();
    }, 220);
  }, 450);
}

/* ══ PINCH / DRAG ZOOM ══ the picture follows the fingers; everything else steps aside */
var _ccmZ = null,
  _ccmZg = null;
function _ccmZoomEl(cx, cy) {
  var slide = document.getElementById("ccm-slide");
  if (!slide) return null;
  var list = slide.querySelectorAll(".ccm-media-box .ccm-media");
  for (var i = 0; i < list.length; i++) {
    var r = list[i].getBoundingClientRect();
    if (cx >= r.left && cx <= r.right && cy >= r.top && cy <= r.bottom)
      return list[i];
  }
  return null;
}
function _ccmZoomApply(z, animate) {
  /* uses the separate `scale` + `translate` properties, so the picture keeps its own fit-to-frame transform underneath */
  z.el.style.transition = animate
    ? "scale .34s cubic-bezier(.22,1,.36,1), translate .34s cubic-bezier(.22,1,.36,1)"
    : "none";
  z.el.style.scale = String(z.s);
  z.el.style.translate = z.tx + "px " + z.ty + "px";
}
function _ccmZoomClamp(z) {
  if (z.s < 1) return;
  var mx = ((z.s - 1) * z.base.w) / 2,
    my = ((z.s - 1) * z.base.h) / 2; /* never slide the picture off its frame */
  z.tx = Math.max(-mx, Math.min(mx, z.tx));
  z.ty = Math.max(-my, Math.min(my, z.ty));
}
/* the first finger of a pinch may already have dragged the post a little (vertical-swipe preview): glide it back to rest */
function _ccmSettleSwipe() {
  var track = document.getElementById("ccm-vtrack");
  if (!track) return;
  var tr = "transform " + CCM_SLIDE_MS + "ms " + CCM_SLIDE_EASE;
  track.style.transition = tr;
  track.style.transform = "translateY(0)";
  var cancelT =
    "opacity " +
    CCM_SLIDE_MS +
    "ms " +
    CCM_SLIDE_EASE +
    ", transform " +
    CCM_SLIDE_MS +
    "ms " +
    CCM_SLIDE_EASE;
  var cur = track.querySelector('.ccm-vslide[data-pos="current"] .ccm-slide');
  if (cur) {
    _ccmOverlayEls(cur).forEach(function (el) {
      el.style.transition = cancelT;
    });
    _ccmSetOverlayProgress(cur, 0, 0);
  }
  ["next", "prev"].forEach(function (pos) {
    var n = track.querySelector(
      '.ccm-vslide[data-pos="' + pos + '"] .ccm-slide',
    );
    if (!n) return;
    _ccmOverlayEls(n).forEach(function (el) {
      el.style.transition = cancelT;
    });
    _ccmSetOverlayProgress(n, 1, 0);
  });
}
function _ccmZoomEnter(z) {
  var modal = document.getElementById("cardCaptionModal");
  if (modal) modal.classList.add("ccm-zoomed");
  z.el.classList.add("ccm-zooming");
  var slide = document.getElementById("ccm-slide");
  if (slide && !slide.querySelector(".ccm-unzoom-btn")) {
    var b = document.createElement("button");
    b.type = "button";
    b.className = "ccm-unzoom-btn";
    b.setAttribute("aria-label", "Zoom out");
    b.innerHTML =
      '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3"/><path d="M21 8h-3a2 2 0 0 1-2-2V3"/><path d="M3 16h3a2 2 0 0 1 2 2v3"/><path d="M16 21v-3a2 2 0 0 1 2-2h3"/></svg>';
    ["touchstart", "touchend", "pointerdown", "mousedown"].forEach(
      function (ev) {
        b.addEventListener(ev, function (e) {
          e.stopPropagation();
        });
      },
    );
    b.addEventListener("click", function (e) {
      e.stopPropagation();
      ccmZoomReset(true);
    });
    slide.appendChild(b);
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        b.classList.add("show");
      });
    });
  }
}
function ccmZoomReset(animate) {
  var z = _ccmZ;
  _ccmZ = null;
  _ccmZg = null;
  var modal = document.getElementById("cardCaptionModal");
  if (modal) {
    if (animate) {
      modal.classList.add("ccm-unzoomed");
      setTimeout(function () {
        modal.classList.remove("ccm-unzoomed");
      }, 340);
    }
    modal.classList.remove("ccm-zoomed");
  }
  document.querySelectorAll(".ccm-unzoom-btn").forEach(function (b) {
    if (animate) {
      b.classList.remove("show");
      setTimeout(function () {
        if (b.parentNode) b.parentNode.removeChild(b);
      }, 260);
    } else if (b.parentNode) b.parentNode.removeChild(b);
  });
  if (!z) return;
  var done = function () {
    z.el.style.transition = "";
    z.el.style.scale = "";
    z.el.style.translate = "";
    z.el.classList.remove("ccm-zooming");
  };
  if (animate && z.el.isConnected) {
    z.s = 1;
    z.tx = 0;
    z.ty = 0;
    _ccmZoomApply(z, true);
    setTimeout(done, 360);
  } else done();
}
/* PINCH = "view full screen": the picture swells a touch under the fingers and everything else fades in step with them;
   let go past halfway and the page settles into full-screen view, otherwise it all springs back. Pinch in to leave. */
(function () {
  var stage = document.getElementById("ccm-stage");
  if (!stage) return;
  var g = null,
    tidy = null;
  function mid(t) {
    return {
      x: (t[0].clientX + t[1].clientX) / 2,
      y: (t[0].clientY + t[1].clientY) / 2,
      d: Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY),
    };
  }
  function clamp(v, lo, hi) {
    return Math.max(lo, Math.min(hi, v));
  }
  function modal() {
    return document.getElementById("cardCaptionModal");
  }
  function clean(el, m) {
    if (el) {
      el.style.transition = "";
      el.style.scale = "";
      el.classList.remove("ccm-zooming");
    }
    if (m) {
      m.classList.remove("ccm-peeking", "ccm-peek-settle");
      m.style.removeProperty("--ccm-peek");
    }
  }
  stage.addEventListener(
    "touchstart",
    function (e) {
      if (e.touches.length < 2 || g) return;
      var m = modal();
      if (!m) return;
      var mm = mid(e.touches),
        el = _ccmZoomEl(mm.x, mm.y);
      if (!el) return;
      if (tidy) {
        clearTimeout(tidy);
        tidy = null;
      }
      if (window._ccmVDragState && window._ccmVDragState())
        _ccmSettleSwipe(); /* a swipe the first finger began is put back */
      var fs = m.classList.contains("ccm-fsview");
      g = { el: el, d0: mm.d || 1, fs: fs, p: fs ? 1 : 0 };
      m.classList.remove("ccm-peek-settle");
      m.classList.add("ccm-peeking");
      m.style.setProperty("--ccm-peek", String(g.p));
      e.preventDefault();
      e.stopPropagation();
    },
    { capture: true, passive: false },
  );
  stage.addEventListener(
    "touchmove",
    function (e) {
      if (!g) return;
      e.preventDefault();
      e.stopPropagation();
      if (e.touches.length < 2) return;
      var ratio =
        mid(e.touches).d /
        g.d0; /* the picture itself never scales: only the surroundings fade */
      g.p = g.fs
        ? 1 - clamp((1 - ratio) / 0.25, 0, 1)
        : clamp((ratio - 1) / 0.5, 0, 1);
      var m = modal();
      if (m) m.style.setProperty("--ccm-peek", String(g.p));
    },
    { capture: true, passive: false },
  );
  function end(e) {
    if (!g) return;
    e.preventDefault();
    e.stopPropagation();
    if (e.touches.length > 0) return;
    var st = g,
      m = modal();
    g = null;
    var commit = st.fs ? st.p < 0.5 : st.p >= 0.5;
    var hidden = st.fs ? (commit ? 0 : 1) : commit ? 1 : 0;
    if (m) {
      m.classList.add("ccm-peek-settle");
      m.style.setProperty("--ccm-peek", String(hidden));
    }
    if (commit) {
      if (st.fs) {
        if (typeof window.ccmFsViewExit === "function") window.ccmFsViewExit();
      } else if (typeof window.ccmFsViewEnter === "function")
        window.ccmFsViewEnter();
      if (window.dzHaptic && dzHaptic.light)
        try {
          dzHaptic.light();
        } catch (x) {}
    }
    tidy = setTimeout(function () {
      tidy = null;
      clean(st.el, m);
    }, 320);
  }
  stage.addEventListener("touchend", end, { capture: true, passive: false });
  stage.addEventListener("touchcancel", end, { capture: true, passive: false });
})();

/* ══ LANDSCAPE FULL-SCREEN ══ */
function ccmFsPillUpdate(video) {
  try {
    var slide = video.closest && video.closest(".ccm-slide");
    if (!slide) return;
    var old = slide.querySelector(".ccm-fs-pill");
    if (old) old.remove();
    if (video.tagName !== "VIDEO")
      return; /* the button is for landscape videos only */
    var vw = video.videoWidth,
      vh = video.videoHeight;
    if (!vw || !vh || vw < vh * 1.15) return;
    var sr = slide.getBoundingClientRect(),
      vr = video.getBoundingClientRect();
    if (!vr.width || vr.right <= sr.left + 4 || vr.left >= sr.right - 4) return;
    var sc = Math.min(vr.width / vw, vr.height / vh);
    var picBottom =
      vr.top +
      vr.height / 2 +
      (vh * sc) / 2; /* bottom edge of the picture itself (not the letterbox) */
    var pill = document.createElement("button");
    pill.type = "button";
    pill.className = "ccm-fs-pill";
    pill.innerHTML =
      '<i class="ri-fullscreen-line"></i><span>Full-screen</span>';
    var picTop =
      vr.top +
      vr.height / 2 -
      (vh * sc) / 2; /* top edge of the picture itself */
    pill.style.top = Math.round(picBottom - sr.top + 12) + "px";
    /* when the bottom is crowded (Interested / Share / Use this sound buttons are showing and everything rises), the pill moves up to the top of the picture */
    pill.style.setProperty(
      "--fs-top-up",
      Math.max(
        8,
        Math.round(
          picTop -
            sr.top -
            (23.69 + 2.54 * 0.5) *
              (parseFloat(
                getComputedStyle(
                  document.getElementById("cardCaptionModal"),
                ).getPropertyValue("--ccm-s"),
              ) || 1) -
            10,
        ),
      ) + "px",
    );
    [
      "touchstart",
      "touchend",
      "pointerdown",
      "pointerup",
      "mousedown",
      "dblclick",
    ].forEach(function (ev) {
      pill.addEventListener(ev, function (e) {
        e.stopPropagation();
      });
    });
    pill.addEventListener("click", function (e) {
      e.stopPropagation();
      ccmOpenFullscreen(video);
    });
    slide.appendChild(pill);
  } catch (e) {}
}
/* the picture the person is looking at right now (video, photo, gif … of the current gallery position) */
function ccmFsCurrentMedia() {
  var slide = document.getElementById("ccm-slide");
  if (!slide) return null;
  var items = slide.querySelectorAll(".ccm-media-item .ccm-media");
  if (!items.length) return null;
  return items[Math.min(_ccmMediaIndex || 0, items.length - 1)];
}
function ccmFsPillRefresh() {
  var m = ccmFsCurrentMedia();
  if (m) ccmFsPillUpdate(m);
}
document.addEventListener(
  "loadedmetadata",
  function (e) {
    if (
      e.target &&
      e.target.classList &&
      e.target.classList.contains("ccm-media")
    )
      ccmFsPillRefresh();
  },
  true,
);
document.addEventListener(
  "playing",
  function (e) {
    if (
      e.target &&
      e.target.classList &&
      e.target.classList.contains("ccm-media")
    )
      ccmFsPillRefresh();
  },
  true,
);
document.addEventListener(
  "load",
  function (e) {
    if (
      e.target &&
      e.target.classList &&
      e.target.classList.contains("ccm-media")
    )
      ccmFsPillRefresh();
  },
  true,
);
window.addEventListener("resize", function () {
  if (!_ccmFs) ccmFsPillRefresh();
});
(function () {
  var lastKey = "";
  setInterval(function () {
    var m = document.getElementById("cardCaptionModal");
    if (!m || !m.classList.contains("ccm-open") || _ccmFs) return;
    var key = (_ccmMemeId || "") + ":" + (_ccmMediaIndex || 0);
    var sl = document.getElementById("ccm-slide"),
      has = sl && sl.querySelector(".ccm-fs-pill");
    if (key !== lastKey || (!has && sl)) {
      lastKey = key;
      ccmFsPillRefresh();
    }
  }, 700);
})();

/* turn the phone on its side → every kind of media opens in the landscape player; turn it back → closes again */
var _ccmFsDismissed = false;
function ccmFsOrientationCheck() {
  try {
    var land = window.innerWidth > window.innerHeight;
    var phone =
      window.matchMedia &&
      window.matchMedia("(pointer:coarse)").matches &&
      Math.min(window.innerWidth, window.innerHeight) < 600;
    var m = document.getElementById("cardCaptionModal");
    var open = m && m.classList.contains("ccm-open");
    if (!land) {
      _ccmFsDismissed = false;
      if (_ccmFs && _ccmFs.auto) ccmCloseFullscreen(true);
      return;
    }
    if (!phone || !open || _ccmFs || _ccmFsDismissed) return;
    if (document.querySelector("#ccm-slide .ccm-textonly-bg"))
      return; /* text-only posts have no picture */
    var cur = ccmFsCurrentMedia();
    if (!cur) return;
    ccmOpenFullscreen(cur, true);
  } catch (e) {}
}
window.addEventListener("resize", function () {
  setTimeout(ccmFsOrientationCheck, 120);
});
window.addEventListener("orientationchange", function () {
  setTimeout(ccmFsOrientationCheck, 250);
});

var _ccmFs = null;
function ccmOpenFullscreen(video, auto) {
  if (_ccmFs || !video) return;
  if (!auto) dzHaptic.light();
  var slide = video.closest(".ccm-slide");
  var items = slide
    ? [].slice.call(slide.querySelectorAll(".ccm-media-item .ccm-media"))
    : [];
  if (!items.length) items = [video];
  var idx = Math.max(0, items.indexOf(video)),
    startIdx = idx,
    firstLoad = true,
    lastVid = null;
  var isVid = function (n) {
    return n && n.tagName === "VIDEO";
  };
  var capEl = document.getElementById("ccm-caption-text"),
    nameEl = slide ? slide.querySelector(".ccm-username") : null;
  var capTxt = capEl
    ? (capEl.textContent || "")
        .replace(/^\s*New\s+/, "")
        .replace(/\.\.\.\s*more\s*$/, "")
        .replace(/\s+/g, " ")
        .trim()
    : "";
  var userTxt = nameEl ? (nameEl.textContent || "").trim() : "";
  var m = _ccmCtlMedia({
    closest: function (sel) {
      return video.closest(sel);
    },
  }); /* video + this post's soundtrack (if any) */
  var a = m.audio;
  var wasPlaying = isVid(video) && !video.paused;
  var postId = _ccmMemeId;

  var el = document.createElement("div");
  el.className = "ccm-fs";
  var fmt = function (t) {
    t = Math.max(0, Math.floor(t || 0));
    var mm = Math.floor(t / 60),
      ss = t % 60;
    return mm + ":" + (ss < 10 ? "0" : "") + ss;
  };
  var _rail = function (id) {
    var sl = document.getElementById("ccm-slide");
    return sl ? sl.querySelector("#" + id) : document.getElementById(id);
  };
  var likeBtn = _rail("ccm-like-btn");
  var liked = likeBtn && likeBtn.classList.contains("active");
  el.innerHTML =
    '<div class="ccm-fs-frame">' +
    '<video class="ccm-fs-video" playsinline webkit-playsinline preload="auto"></video>' +
    '<img class="ccm-fs-video ccm-fs-pic" alt="" draggable="false" style="display:none">' +
    '<div class="ccm-fs-ui">' +
    '<div class="ccm-fs-top">' +
    '<button type="button" class="ccm-fs-back" data-a="close" aria-label="Back"><svg viewBox="0 0 14 24" aria-hidden="true"><path d="M12.4 1.6 L2 12 L12.4 22.4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
    '<div class="ccm-fs-tu"><div class="ccm-fs-t"></div><div class="ccm-fs-u"></div></div>' +
    '<div class="ccm-fs-count"></div>' +
    "</div>" +
    '<div class="ccm-fs-center">' +
    '<button type="button" class="ccm-fs-btn ccm-fs-seek" data-a="back">' +
    ccmIco("back10") +
    "</button>" +
    '<button type="button" class="ccm-fs-btn ccm-fs-pp" data-a="pp"></button>' +
    '<button type="button" class="ccm-fs-btn ccm-fs-seek" data-a="fwd">' +
    ccmIco("fwd10") +
    "</button>" +
    "</div>" +
    '<div class="ccm-fs-bottom">' +
    '<div class="ccm-fs-time"></div>' +
    '<div class="ccm-fs-bar"><div class="ccm-fs-track"><div class="ccm-fs-fill"></div><div class="ccm-fs-dot"></div></div></div>' +
    '<div class="ccm-fs-actions">' +
    '<button type="button" class="ccm-fs-act ccm-fs-solid' +
    (liked ? " on" : "") +
    '" data-a="like"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg><span class="ccm-fs-lc"></span></button>' +
    '<button type="button" class="ccm-fs-act" data-a="repost"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg><span class="ccm-fs-rc"></span></button>' +
    '<button type="button" class="ccm-fs-act" data-a="comment" aria-label="Comments"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg><span class="ccm-fs-cc"></span></button>' +
    '<button type="button" class="ccm-fs-act ccm-fs-solid" data-a="fav"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21l-7-5-7 5V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1z"/></svg><span class="ccm-fs-fc"></span></button>' +
    '<button type="button" class="ccm-fs-act" data-a="share"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg><span class="ccm-fs-sc"></span></button>' +
    '<span class="ccm-fs-grow"></span>' +
    '<button type="button" class="ccm-fs-act" data-a="speed"><i class="ri-speed-line"></i><span class="ccm-fs-sp">1x</span></button>' +
    '<button type="button" class="ccm-fs-act" data-a="mute"></button>' +
    '<button type="button" class="ccm-fs-act" data-a="close" aria-label="Exit full screen"><i class="ri-fullscreen-exit-line"></i></button>' +
    "</div>" +
    "</div>" +
    "</div>" +
    "</div>";
  document.body.appendChild(el);
  var frame = el.querySelector(".ccm-fs-frame"),
    v = el.querySelector("video"),
    ui = el.querySelector(".ccm-fs-ui");
  var ppBtn = el.querySelector(".ccm-fs-pp"),
    timeEl = el.querySelector(".ccm-fs-time"),
    bar = el.querySelector(".ccm-fs-bar");
  var fill = el.querySelector(".ccm-fs-fill"),
    dot = el.querySelector(".ccm-fs-dot"),
    muteBtn = el.querySelector('[data-a="mute"]');
  el.querySelector(".ccm-fs-t").textContent = capTxt;
  el.querySelector(".ccm-fs-u").textContent = userTxt;
  /* counts + on/off state are read from the side rail (the one place they are kept) and refreshed after every tap and every half second */
  var fsCount = function (t) {
    t = (t || "").trim();
    return /^\d+$/.test(t) ? ccmFmtViews(+t) : t;
  };
  var syncRail = function () {
    [
      ["like", "ccm-like-btn", "ccm-like-count", ".ccm-fs-lc"],
      ["repost", "ccm-repost-btn", "ccm-repost-count", ".ccm-fs-rc"],
      ["fav", "ccm-fav-btn", "ccm-fav-count", ".ccm-fs-fc"],
      ["comment", "ccm-comment-btn", "ccm-comment-count", ".ccm-fs-cc"],
      ["share", "ccm-share-btn", "ccm-share-count", ".ccm-fs-sc"],
    ].forEach(function (r) {
      var rb = _rail(r[1]),
        rc = _rail(r[2]);
      var fb = el.querySelector('[data-a="' + r[0] + '"]'),
        fc = el.querySelector(r[3]);
      if (!rb) {
        if (fb) fb.style.display = "none";
        return;
      } /* no such button on this post (e.g. repost on your own post) */
      if (fb) fb.style.display = "";
      if (fc) fc.textContent = rc ? fsCount(rc.textContent) : "";
      if (fb && r[0] === "comment" && rb.classList.contains("ccm-cm-off"))
        fb.style.display = "none"; /* comments are off on this post */
      if (fb && r[0] !== "share" && r[0] !== "comment")
        fb.classList.toggle("on", rb.classList.contains("active"));
    });
  };

  var st = {
    el: el,
    v: v,
    a: a,
    video: video,
    wasPlaying: wasPlaying,
    hideT: 0,
    hold: 0,
    rotated: false,
    rate: 1,
    drag: false,
    auto: !!auto,
  };
  var pic = el.querySelector(".ccm-fs-pic"),
    countEl = el.querySelector(".ccm-fs-count");
  if (a) el.classList.add("ccm-fs-hasa");
  syncRail();
  st.sync = setInterval(syncRail, 500);
  _ccmFs = st;
  st.getBack = function () {
    return { el: items[idx], vid: isVid(items[idx]), idx: idx };
  };

  /* the picture to watch: same file, same spot; the post's own players are held still underneath */
  items.forEach(function (n) {
    if (isVid(n)) n.pause();
  });
  st.hold = setInterval(function () {
    items.forEach(function (n) {
      if (isVid(n) && !n.paused) n.pause();
    });
  }, 400);
  function load(i) {
    var it = items[i];
    idx = i;
    countEl.textContent = items.length > 1 ? i + 1 + " / " + items.length : "";
    if (isVid(it)) {
      lastVid = it;
      el.classList.remove("ccm-fs-isimg");
      pic.style.display = "none";
      v.style.display = "";
      v.src = it.currentSrc || it.src;
      v.muted = a ? true : it.muted;
      var startAt = firstLoad ? it.currentTime || 0 : 0;
      v.addEventListener(
        "loadedmetadata",
        function () {
          try {
            v.currentTime = startAt;
          } catch (e) {}
          v.play().catch(function () {});
          if (a) {
            try {
              a.play();
            } catch (e) {}
          }
        },
        { once: true },
      );
    } else {
      try {
        v.pause();
        v.removeAttribute("src");
        v.load();
      } catch (e) {}
      v.style.display = "none";
      pic.style.display = "";
      pic.src = it.currentSrc || it.src;
      el.classList.add("ccm-fs-isimg");
    }
    firstLoad = false;
    if (typeof icons === "function" && st.ready) {
      icons();
      paint();
      showUi();
    }
  }
  st.go = function (d) {
    var n = idx + d;
    if (n < 0 || n >= items.length) return;
    load(n);
  };

  function icons() {
    ppBtn.innerHTML = ccmIco(v.paused ? "play" : "pause");
    muteBtn.innerHTML = ccmIco((a ? a.muted : v.muted) ? "mute" : "volume");
  }
  function paint() {
    var d = v.duration || 0,
      pct = d ? Math.min(100, (v.currentTime / d) * 100) : 0;
    fill.style.width = pct + "%";
    dot.style.left = pct + "%";
    timeEl.innerHTML = fmt(v.currentTime) + " <span>/ " + fmt(d) + "</span>";
  }
  function showUi() {
    ui.classList.remove("hide");
    clearTimeout(st.hideT);
    if (!v.paused && !st.drag)
      st.hideT = setTimeout(function () {
        ui.classList.add("hide");
      }, 3200);
  }
  function layout() {
    var W = window.innerWidth,
      H = window.innerHeight;
    st.rotated =
      H >
      W; /* held upright → turn the player on its side so the picture is landscape */
    if (st.rotated) {
      frame.style.width = H + "px";
      frame.style.height = W + "px";
      frame.style.transform = "translateX(" + W + "px) rotate(90deg)";
    } else {
      frame.style.width = W + "px";
      frame.style.height = H + "px";
      frame.style.transform = "none";
    }
  }
  function seekPct(p) {
    p = Math.max(0, Math.min(1, p));
    if (v.duration) {
      v.currentTime = p * v.duration;
      if (a && a.duration) a.currentTime = p * a.duration;
    }
    paint();
  }
  function pctFrom(ev) {
    var r = bar.getBoundingClientRect();
    return st.rotated
      ? (ev.clientY - r.top) / r.height
      : (ev.clientX - r.left) / r.width;
  }
  v.addEventListener("timeupdate", paint);
  v.addEventListener("play", function () {
    icons();
    showUi();
  });
  v.addEventListener("pause", function () {
    icons();
    showUi();
  });
  v.addEventListener("ended", function () {
    icons();
    showUi();
  });
  st.ready = true;
  load(idx);
  layout();
  icons();
  paint();
  showUi();
  st.onResize = function () {
    layout();
  };
  window.addEventListener("resize", st.onResize);
  window.addEventListener("orientationchange", st.onResize);

  /* scrub by dragging the bar */
  bar.addEventListener("pointerdown", function (ev) {
    ev.stopPropagation();
    st.drag = true;
    bar.classList.add("drag");
    try {
      bar.setPointerCapture(ev.pointerId);
    } catch (e) {}
    v.pause();
    if (a) a.pause();
    seekPct(pctFrom(ev));
  });
  bar.addEventListener("pointermove", function (ev) {
    if (st.drag) seekPct(pctFrom(ev));
  });
  var endDrag = function (ev) {
    if (!st.drag) return;
    st.drag = false;
    bar.classList.remove("drag");
    seekPct(pctFrom(ev));
    v.play().catch(function () {});
    if (a) {
      try {
        a.play();
      } catch (e) {}
    }
    showUi();
  };
  bar.addEventListener("pointerup", endDrag);
  bar.addEventListener("pointercancel", endDrag);

  /* swipe along the picture to move through a gallery */
  var sw = null;
  frame.addEventListener("pointerdown", function (ev) {
    if (ev.target.closest("[data-a]") || ev.target.closest(".ccm-fs-bar")) {
      sw = null;
      return;
    }
    sw = { x: ev.clientX, y: ev.clientY };
  });
  frame.addEventListener("pointerup", function (ev) {
    if (!sw) return;
    var dx = ev.clientX - sw.x,
      dy = ev.clientY - sw.y;
    sw = null;
    var main = st.rotated ? dy : dx,
      cross = st.rotated ? dx : dy;
    if (
      items.length > 1 &&
      Math.abs(main) > 55 &&
      Math.abs(main) > Math.abs(cross) * 1.5
    ) {
      st.swiped = true;
      setTimeout(function () {
        st.swiped = false;
      }, 350);
      st.go(main < 0 ? 1 : -1);
    }
  });
  /* taps: on the picture show / hide the controls; buttons do their job */
  frame.addEventListener("click", function (ev) {
    if (st.swiped) return;
    var b = ev.target.closest("[data-a]");
    if (!b) {
      if (ui.classList.contains("hide")) showUi();
      else if (!v.paused) ui.classList.add("hide");
      else showUi();
      return;
    }
    ev.stopPropagation();
    showUi();
    var act = b.getAttribute("data-a");
    if (act === "close") ccmCloseFullscreen();
    else if (act === "pp") {
      if (v.paused) {
        v.play().catch(function () {});
        if (a) {
          try {
            a.play();
          } catch (e) {}
        }
      } else {
        v.pause();
        if (a) a.pause();
      }
    } else if (act === "back" || act === "fwd") {
      var d2 = v.duration || 0;
      if (d2) seekPct((v.currentTime + (act === "back" ? -10 : 10)) / d2);
    } else if (act === "mute") {
      var nm = !(a ? a.muted : v.muted);
      if (a) a.muted = nm;
      else v.muted = nm;
      icons();
    } else if (act === "speed") {
      var rates = [1, 1.25, 1.5, 2],
        i2 = (rates.indexOf(st.rate) + 1) % rates.length;
      st.rate = rates[i2];
      v.playbackRate = st.rate;
      if (a) a.playbackRate = st.rate;
      el.querySelector(".ccm-fs-sp").textContent =
        (st.rate === 1 ? "1" : String(st.rate)) + "x";
    } else if (act === "like" || act === "repost" || act === "fav") {
      ccmAction(act);
      setTimeout(syncRail, 150);
      setTimeout(syncRail, 600);
    } else if (act === "share") {
      var pid = postId;
      ccmCloseFullscreen();
      if (typeof openShareModal === "function") openShareModal(pid);
    } else if (act === "comment") {
      var cid = postId;
      ccmCloseFullscreen();
      setTimeout(function () {
        if (typeof ccmOpenComments === "function") ccmOpenComments(cid);
      }, 220);
    }
  });

  /* real full screen + landscape where the phone allows it (Android); otherwise the turned-on-its-side layout above */
  try {
    var rq = el.requestFullscreen || el.webkitRequestFullscreen;
    if (rq && !auto) {
      var pr = rq.call(el);
      var lock = function () {
        try {
          if (screen.orientation && screen.orientation.lock)
            screen.orientation.lock("landscape").catch(function () {});
        } catch (e) {}
      };
      if (pr && pr.then) pr.then(lock).catch(function () {});
      else lock();
    }
  } catch (e) {}
  st.onFsChange = function () {
    if (
      !document.fullscreenElement &&
      !document.webkitFullscreenElement &&
      _ccmFs === st
    )
      ccmCloseFullscreen();
  };
  document.addEventListener("fullscreenchange", st.onFsChange);
  document.addEventListener("webkitfullscreenchange", st.onFsChange);
}
function ccmCloseFullscreen(fromTurn) {
  var st = _ccmFs;
  if (!st) return;
  _ccmFs = null;
  if (fromTurn !== true && window.innerWidth > window.innerHeight)
    _ccmFsDismissed = true; /* closed by hand while still on its side: don't pop it open again */
  clearInterval(st.hold);
  clearInterval(st.sync);
  clearTimeout(st.hideT);
  window.removeEventListener("resize", st.onResize);
  window.removeEventListener("orientationchange", st.onResize);
  document.removeEventListener("fullscreenchange", st.onFsChange);
  document.removeEventListener("webkitfullscreenchange", st.onFsChange);
  var t = st.v.currentTime,
    pausedAtEnd = st.v.paused;
  try {
    st.v.pause();
    st.v.removeAttribute("src");
    st.v.load();
  } catch (e) {}
  try {
    if (document.fullscreenElement && document.exitFullscreen)
      document.exitFullscreen();
    else if (document.webkitFullscreenElement && document.webkitExitFullscreen)
      document.webkitExitFullscreen();
  } catch (e) {}
  try {
    if (screen.orientation && screen.orientation.unlock)
      screen.orientation.unlock();
  } catch (e) {}
  if (st.el.parentNode) st.el.parentNode.removeChild(st.el);
  var back = st.getBack
    ? st.getBack()
    : { el: st.video, vid: st.video.tagName === "VIDEO" };
  if (back.idx != null && back.idx !== _ccmMediaIndex) {
    /* gallery moved on while in full screen: follow it */
    _ccmMediaIndex = back.idx;
    try {
      _ccmSaveMediaIndex(_ccmMemeId, _ccmMediaIndex);
    } catch (e) {}
    var sl = document.getElementById("ccm-slide");
    if (sl) {
      try {
        ccmUpdateMediaGallery(sl, !(st.wasPlaying && !pausedAtEnd));
      } catch (e) {}
    }
  }
  if (back.vid) {
    try {
      back.el.currentTime = t;
    } catch (e) {}
    if (st.wasPlaying && !pausedAtEnd) {
      back.el.play().catch(function () {});
    }
  }
  if (st.a) {
    st.a.playbackRate = 1;
    if (!back.vid || pausedAtEnd) {
      if (!(st.wasPlaying && !pausedAtEnd))
        try {
          st.a.pause();
        } catch (e) {}
    }
  }
  ccmFsPillRefresh();
}

function ccmShowPausedControls(mediaBox, video) {
  ccmHidePausedControls(mediaBox);

  var trackEl = document.getElementById("ccmSoundtrackPlayer");
  var hasTrack = trackEl && trackEl.src;
  var soundSrc = hasTrack ? trackEl : video;
  var isMuted = soundSrc ? !!soundSrc.muted : false;

  var wrap = document.createElement("div");
  wrap.className = "ccm-paused-controls";
  wrap.innerHTML =
    '<div class="ccm-paused-stack">' +
    '<button type="button" class="ccm-mute-btn" aria-label="' +
    (isMuted ? "Unmute" : "Mute") +
    '" onclick="event.stopPropagation();ccmToggleMute(this)">' +
    ccmIco(isMuted ? "mute" : "volume") +
    "</button>" +
    '<div class="ccm-paused-row">' +
    '<button type="button" class="ccm-seek-btn" aria-label="Back 10 seconds" onclick="event.stopPropagation();ccmSeekVideo(this,-10)" title="Back 10s">' +
    ccmIco("back10") +
    "</button>" +
    '<div class="ccm-pause-icon" role="img" aria-label="Paused">' +
    ccmIco("pause") +
    "</div>" +
    '<button type="button" class="ccm-seek-btn" aria-label="Forward 10 seconds" onclick="event.stopPropagation();ccmSeekVideo(this,10)" title="Forward 10s">' +
    ccmIco("fwd10") +
    "</button>" +
    "</div>" +
    "</div>";
  mediaBox.appendChild(wrap);
  requestAnimationFrame(function () {
    requestAnimationFrame(function () {
      wrap.classList.add("show");
    });
  });
}
/* fade the pause / mute / seek controls away (used when resuming) */
function ccmHidePausedControlsSmooth(mediaBox) {
  /* ALL of them: coming back to a paused post can leave two copies (one on the item, one on the box) — removing just the first left the other stuck on screen */
  var all = mediaBox.querySelectorAll(".ccm-paused-controls");
  if (!all.length) return;
  all.forEach(function (ex) {
    ex.classList.remove("show");
    setTimeout(function () {
      if (ex.parentNode) ex.parentNode.removeChild(ex);
    }, 110);
  });
}
function ccmHidePausedControls(mediaBox) {
  mediaBox.querySelectorAll(".ccm-paused-controls").forEach(function (e) {
    e.remove();
  });
}
/* the person's mute choice for the videos of a post (no separate music): it follows them from video to video */
var _ccmMutedPref = {};
/* thick, round-cornered icons (inline SVG) for the pause / mute / seek buttons */
function ccmIco(name) {
  var a =
    '<svg class="ccm-ico" viewBox="0 0 24 24" aria-hidden="true" stroke-width="2.4">';
  switch (name) {
    case "play":
      return (
        a.replace("2.4", "2.2") +
        '<path class="f" d="M8.6 5.7c0-1 1.1-1.5 1.9-1l8.2 5.8c.7.5.7 1.5 0 2l-8.2 5.8c-.8.5-1.9 0-1.9-1z"/></svg>'
      );
    case "pause":
      return (
        a.replace("2.4", "3.6") + '<path d="M8.2 5.5v13M15.8 5.5v13"/></svg>'
      );
    case "volume":
      return (
        a.replace('viewBox="0 0 24 24"', 'viewBox="261 218 681 603"') +
        '<path class="f" stroke="none" fill-rule="evenodd" d="M576.2,820.0C560.6,817.2 551.8,812.4 515.6,787.7C500.1,777.2 474.4,759.8 458.5,749.0C442.6,738.3 425.4,726.5 420.5,722.9C415.6,719.3 405.2,712.2 397.5,707.0C381.1,696.0 346.4,671.6 333.5,661.9C314.1,647.4 292.3,621.5 281.6,600.2C258.9,555.2 255.1,512.3 270.0,467.9C277.7,444.5 282.6,435.5 299.8,412.0C309.3,399.1 335.0,374.5 346.8,367.0C365.5,355.1 391.4,337.8 401.0,330.6C412.7,322.0 438.1,304.3 460.9,289.0C470.3,282.7 487.8,270.8 499.8,262.6C559.6,221.4 567.2,217.6 589.5,217.6C628.4,217.6 651.1,243.1 654.0,290.0C655.3,310.7 655.3,742.7 654.0,756.0C649.5,802.0 618.5,827.6 576.2,820.0ZM747.9,729.0C732.2,726.4 723.6,712.1 725.3,691.7C727.0,671.8 735.1,664.0 760.3,658.0C794.4,649.8 822.2,631.8 843.7,603.9C862.9,578.9 876.0,533.5 872.1,504.8C869.5,485.1 856.5,452.0 846.5,439.5C819.9,406.3 796.9,390.9 760.7,382.0C736.8,376.1 727.9,367.8 724.9,348.7C721.1,324.7 735.0,309.3 758.5,311.4C807.1,315.8 864.9,348.4 895.2,388.5C915.9,416.0 920.6,425.4 936.6,471.0C943.6,491.1 944.3,540.8 938.0,565.7C923.1,623.6 884.6,673.9 832.7,702.9C803.4,719.3 763.6,731.6 747.9,729.0ZM738.0,584.8C732.1,581.6 727.6,574.3 726.0,565.2C724.3,556.1 724.4,479.3 726.1,472.4C737.4,425.3 805.6,472.9 801.7,525.2C799.0,559.4 759.6,596.3 738.0,584.8Z"/></svg>'
      );
    case "mute":
      return (
        a
          .replace('viewBox="0 0 24 24"', 'viewBox="261 218 681 603"')
          .replace('stroke-width="2.4"', 'stroke-width="58"') +
        '<path class="f" stroke="none" d="M576.2,820.0C560.6,817.2 551.8,812.4 515.6,787.7C500.1,777.2 474.4,759.8 458.5,749.0C442.6,738.3 425.4,726.5 420.5,722.9C415.6,719.3 405.2,712.2 397.5,707.0C381.1,696.0 346.4,671.6 333.5,661.9C314.1,647.4 292.3,621.5 281.6,600.2C258.9,555.2 255.1,512.3 270.0,467.9C277.7,444.5 282.6,435.5 299.8,412.0C309.3,399.1 335.0,374.5 346.8,367.0C365.5,355.1 391.4,337.8 401.0,330.6C412.7,322.0 438.1,304.3 460.9,289.0C470.3,282.7 487.8,270.8 499.8,262.6C559.6,221.4 567.2,217.6 589.5,217.6C628.4,217.6 651.1,243.1 654.0,290.0C655.3,310.7 655.3,742.7 654.0,756.0C649.5,802.0 618.5,827.6 576.2,820.0Z"/><path d="M742 430L915 625M915 430L742 625"/></svg>'
      );
    case "back10":
      return (
        a +
        '<path d="M5.2 12.4a7.2 7.2 0 1 0 2.2-5.3"/><path d="M4.8 3.6v4.8h4.8"/></svg>'
      );
    case "fwd10":
      return (
        a +
        '<path d="M18.8 12.4a7.2 7.2 0 1 1-2.2-5.3"/><path d="M19.2 3.6v4.8h-4.8"/></svg>'
      );
  }
  return "";
}
/* the video being controlled + the soundtrack ONLY when it belongs to this post (the audio element keeps its old src from earlier posts) */
function _ccmCtlMedia(btn) {
  var container =
    btn.closest(".ccm-media-item") || btn.closest(".ccm-media-box");
  var video = container ? container.querySelector("video.ccm-media") : null;
  var el = document.getElementById("ccmSoundtrackPlayer");
  var audio =
    el &&
    el.src &&
    el.dataset.memeId &&
    String(el.dataset.memeId) === String(_ccmMemeId)
      ? el
      : null;
  return { video: video, audio: audio };
}
/* the search button next to mute: opens the search modal (kept above the Zeems modal); pages without the modal go to the search page */
function ccmOpenSearch() {
  try {
    dzHaptic.selection();
  } catch (e) {}
  if (typeof window.openSearchModal === "function") {
    try {
      window.openSearchModal();
    } catch (e) {}
    var sm = document.getElementById("searchModal");
    if (sm) sm.style.zIndex = "10000001";
  } else {
    location.href = "search.php?focus=1";
  }
}
/* ── POLLS: the pill text, opening the sheet, and keeping the pill in step when someone votes in the sheet ── */
function _ccmPollPillText(poll) {
  var n = poll.total || 0;
  var head = poll.closed ? "Results" : poll.myVote ? "Voted" : "Vote";
  return (
    head +
    " \u00B7 " +
    (n === 0
      ? "No responses yet"
      : ccmFmtViews(n) + (n === 1 ? " response" : " responses"))
  );
}
function ccmOpenPoll(postId) {
  try {
    dzHaptic.light();
  } catch (e) {}
  ccmOpenComments(postId);
}
window.addEventListener("dz-poll-update", function (ev) {
  var d = ev && ev.detail;
  if (!d || !d.poll) return;
  for (var i = 0; i < ccmFeed.length; i++) {
    var it = ccmFeed[i];
    if (
      it.type === "post" &&
      it.data &&
      String(it.data.id) === String(d.memeId)
    ) {
      it.data.poll = d.poll;
    }
  }
  var t = document.getElementById("ccm-poll-pill-text");
  if (t && _ccmMemeId && String(_ccmMemeId) === String(d.memeId))
    t.textContent = _ccmPollPillText(d.poll);
});
function ccmToggleMute(btn) {
  dzHaptic.light(); /* same single pulse as the menu button */
  var m = _ccmCtlMedia(btn);
  var media = m.audio || m.video;
  if (!media) return;
  if (media._dzGateRelease)
    media._dzGateRelease(); /* the sound was still waiting for the first picture: settle it first */
  var nowMuted = !media.muted;
  if (m.audio) m.audio.muted = nowMuted;
  if (m.video)
    m.video.muted = m.audio
      ? true
      : nowMuted; /* with a soundtrack the video itself stays silent */
  if (!m.audio && _ccmMemeId) _ccmMutedPref[_ccmMemeId] = nowMuted;
  btn.innerHTML = ccmIco(nowMuted ? "mute" : "volume");
  btn.setAttribute("aria-label", nowMuted ? "Unmute" : "Mute");
}
function ccmSeekVideo(btn, delta) {
  var m = _ccmCtlMedia(btn);
  var main = m.video || m.audio;
  if (!main || !isFinite(main.duration) || !main.duration) return;
  var t = Math.max(0, Math.min(main.duration, (main.currentTime || 0) + delta));
  var pct = t / main.duration;
  if (m.video) m.video.currentTime = t;
  if (m.audio && m.video && isFinite(m.audio.duration) && m.audio.duration)
    m.audio.currentTime =
      pct * m.audio.duration; /* soundtrack follows in lockstep */
  if (m.video) ccmUpdateProgress(m.video);
}

/* ══ SMART OBJECT-FIT ══ */
function ccmFixObjectFit(el) {
  var box = el.closest(".ccm-media-item") || el.closest(".ccm-media-box");
  if (!box) return;
  var mW = el.tagName === "VIDEO" ? el.videoWidth : el.naturalWidth;
  var mH = el.tagName === "VIDEO" ? el.videoHeight : el.naturalHeight;
  if (!mW || !mH) return;
  var cW = box.clientWidth,
    cH = box.clientHeight;
  if (!cW || !cH) return;

  var mediaAspect = mW / mH;
  var boxAspect = cW / cH;

  el.style.transform = "";

  if (mediaAspect < boxAspect) {
    el.style.objectFit = "cover";
    return;
  }

  var scaledHeight = cW / mediaAspect;
  var fillRatio = scaledHeight / cH;
  var FILL_THRESHOLD = 0.84;

  if (fillRatio >= FILL_THRESHOLD) {
    el.style.objectFit = "fill";
  } else {
    el.style.objectFit = "contain";
  }
}

/* ── TRACK BUTTON ── */
function ccmToggleTrack(btn) {
  var userId = btn.dataset.user;
  if (btn.classList.contains("following")) dzHaptic.light();
  else dzHaptic.medium();
  /* optimistic: flip every Track button for this person at once; the server only confirms (or we roll back) */
  var _wasFollowing = btn.classList.contains("following"),
    _nowFollowing = !_wasFollowing;
  function _paintTrack(on) {
    document
      .querySelectorAll(
        '.ccm-track-btn[data-user="' +
          userId +
          '"], .track-btn[data-user="' +
          userId +
          '"]',
      )
      .forEach(function (fb) {
        fb.textContent = on ? "Untrack" : "Track";
        fb.classList.toggle("following", on);
      });
    document
      .querySelectorAll('.cs-ph-track[data-user="' + userId + '"]')
      .forEach(function (fb) {
        fb.textContent = on ? "Untrack" : "Track";
      });
  }
  _paintTrack(_nowFollowing);
  fetch(CCM_CFG.followEndpoint || _ccmEp(), {
    method: "POST",
    credentials: "same-origin",
    headers: _ccmCsrfH({ "Content-Type": "application/x-www-form-urlencoded" }),
    body:
      "action=toggle_follow&user_id=" +
      encodeURIComponent(userId) +
      _ccmCsrfQ(),
  })
    .then(function (r) {
      return r.json();
    })
    .catch(function () {
      return { status: "fail" };
    })
    .then(function (data) {
      if (data.status !== "ok") {
        _paintTrack(_wasFollowing);
        ccmToast(
          navigator.onLine === false
            ? "You're offline — try again when you're back online"
            : "Couldn't update Track. Please try again.",
        );
        return;
      }
      if (data.status === "ok") {
        ccmFeed.forEach(function (s) {
          if (s.type === "post" && String(s.data.userId) === String(userId))
            s.data.isFollowing = !!data.following;
        });
        Object.keys(_ccmConnectedCache).forEach(function (k) {
          if (String(_ccmConnectedCache[k].userId) === String(userId))
            _ccmConnectedCache[k].isFollowing = !!data.following;
        });
        document
          .querySelectorAll('.ccm-track-btn[data-user="' + userId + '"]')
          .forEach(function (fb) {
            if (data.following) {
              fb.textContent = "Untrack";
              fb.classList.add("following");
            } else {
              fb.textContent = "Track";
              fb.classList.remove("following");
            }
          });
        document
          .querySelectorAll('.cs-ph-track[data-user="' + userId + '"]')
          .forEach(function (fb) {
            fb.textContent = data.following ? "Untrack" : "Track";
          });
        document
          .querySelectorAll('.track-btn[data-user="' + userId + '"]')
          .forEach(function (fb) {
            if (data.following) {
              fb.textContent = "Untrack";
              fb.classList.add("following");
            } else {
              fb.textContent = "Track";
              fb.classList.remove("following");
            }
          });
        /* profile page: updates every Track button + the page itself */
        if (typeof window.dzSetFollowState === "function") {
          try {
            window.dzSetFollowState(userId, !!data.following);
          } catch (e) {}
        }
      }
    });
}

/* ══ MULTI-MEDIA GALLERY ══ */
function _ccmGetSavedMediaIndex(postId) {
  if (postId === null || postId === undefined) return 0;
  var v = _ccmMediaIndexByPost[postId];
  return typeof v === "number" && v >= 0 ? v : 0;
}
function _ccmSaveMediaIndex(postId, idx) {
  if (postId === null || postId === undefined) return;
  _ccmMediaIndexByPost[postId] = idx;
}

/* Starts the video that is on screen and makes sure it REALLY starts: if the phone refuses sound-on autoplay it is started muted at once and un-muted when allowed
   (a multi-media post used to sit there until you tapped it), and if the data was not ready it starts the moment it is. */
function _ccmKickPlay(v) {
  if (!v || v.tagName !== "VIDEO") return;
  var wantMuted = v.muted;
  function stillWanted() {
    if (!v.isConnected || v._ccmPausing || !v.paused) return false;
    var it = v.closest ? v.closest(".ccm-media-item") : null;
    if (!it || parseInt(it.dataset.idx, 10) !== _ccmMediaIndex) return false;
    if (!v.closest("#ccm-slide")) return false;
    return !(_ccmMemeId && _ccmPausedMemeIds[_ccmMemeId]);
  }
  function syncIcon() {
    try {
      var sl = v.closest("#ccm-slide");
      var b = sl && sl.querySelector(".ccm-mute-btn");
      if (b && typeof ccmIco === "function") {
        b.innerHTML = ccmIco(v.muted ? "mute" : "volume");
        b.setAttribute("aria-label", v.muted ? "Unmute" : "Mute");
      }
    } catch (e) {}
  }
  function wantSoundNow() {
    return v.isConnected && !(_ccmMemeId && _ccmMutedPref[_ccmMemeId]);
  }
  /* sound-on: try straight away; if the phone will not allow it without a touch, the very first touch / tap anywhere turns the sound on (no need to find the mute button) */
  function unmute() {
    if (!wantSoundNow() || !v.muted) return;
    try {
      v.muted = false;
    } catch (e) {}
    setTimeout(function () {
      if (!v.isConnected) return;
      if (
        v.paused &&
        !v._ccmPausing &&
        !(_ccmMemeId && _ccmPausedMemeIds[_ccmMemeId])
      ) {
        try {
          v.muted = true;
          v.play().catch(function () {});
        } catch (e) {}
        armGesture();
      } else if (v.muted && wantSoundNow()) armGesture();
      syncIcon();
    }, 300);
  }
  function armGesture() {
    if (v._ccmUnmuteArmed) return;
    v._ccmUnmuteArmed = true;
    syncIcon();
    var go = function (ev) {
      if (
        ev &&
        ev.target &&
        ev.target.closest &&
        ev.target.closest(".ccm-mute-btn")
      )
        return; /* the mute button does its own toggle */
      document.removeEventListener("touchend", go, true);
      document.removeEventListener("pointerup", go, true);
      document.removeEventListener("click", go, true);
      v._ccmUnmuteArmed = false;
      if (!wantSoundNow() || !v.isConnected) return;
      try {
        v.muted = false;
        if (v.paused && !(_ccmMemeId && _ccmPausedMemeIds[_ccmMemeId]))
          v.play().catch(function () {});
      } catch (e) {}
      syncIcon();
    };
    document.addEventListener("touchend", go, true);
    document.addEventListener("pointerup", go, true);
    document.addEventListener("click", go, true);
  }
  function mutedStart() {
    try {
      v.muted = true;
      syncIcon();
      var p2 = v.play();
      if (p2 && p2.then)
        p2.then(function () {
          if (!wantMuted) setTimeout(unmute, 80);
        }).catch(function () {});
    } catch (e) {}
  }
  var pr;
  try {
    pr = v.play();
  } catch (e) {}
  if (pr && pr.catch)
    pr.catch(function (err) {
      if (err && err.name === "NotAllowedError") mutedStart();
    });
  if (v.readyState < 3) {
    var again = function () {
      v.removeEventListener("canplay", again);
      v.removeEventListener("loadeddata", again);
      if (stillWanted()) {
        var q;
        try {
          q = v.play();
        } catch (e) {}
        if (q && q.catch)
          q.catch(function (err) {
            if (err && err.name === "NotAllowedError") mutedStart();
          });
      }
    };
    v.addEventListener("canplay", again);
    v.addEventListener("loadeddata", again);
    setTimeout(function () {
      v.removeEventListener("canplay", again);
      v.removeEventListener("loadeddata", again);
    }, 8000);
  }
}
function ccmUpdateMediaGallery(slide, suppressAutoplay) {
  var gallery = slide.querySelector("#ccm-media-gallery");
  if (!gallery) return;
  /* when the caller doesn't say, respect what the person did: a post they paused stays paused */
  if (suppressAutoplay === undefined)
    suppressAutoplay = !!(_ccmMemeId && _ccmPausedMemeIds[_ccmMemeId]);
  gallery.style.transform = "translateX(-" + _ccmMediaIndex * 100 + "%)";

  gallery.querySelectorAll(".ccm-media-item").forEach(function (itemEl) {
    var idx = parseInt(itemEl.dataset.idx, 10);
    var vid = itemEl.querySelector("video.ccm-media");
    if (vid) {
      if (idx === _ccmMediaIndex) {
        if (vid.preload !== "auto") {
          vid.preload = "auto";
        }
        /* no separate music: this video carries the sound, and it follows the person's mute / unmute choice */
        var _pp =
          ccmFeed[_ccmIndex] && ccmFeed[_ccmIndex].type === "post"
            ? ccmFeed[_ccmIndex].data
            : null;
        if (!(_pp && _pp.audioFile && !_pp.audioIsOriginal))
          vid.muted = !!(_ccmMemeId && _ccmMutedPref[_ccmMemeId]);
        if (suppressAutoplay) {
          vid.pause();
          if (!itemEl.querySelector(".ccm-paused-controls"))
            ccmShowPausedControls(itemEl, vid);
        } else {
          _ccmKickPlay(vid);
          _ccmPlayWatchdog(vid);
        }
      } else if (Math.abs(idx - _ccmMediaIndex) === 1) {
        /* the neighbouring video of the same post loads only once the one on screen can play, so the two never fight for the connection */
        if (vid.preload !== "auto") {
          var _act = gallery.querySelector(
            '.ccm-media-item[data-idx="' +
              _ccmMediaIndex +
              '"] video.ccm-media',
          );
          if (!_act || _act.readyState >= 3) vid.preload = "auto";
          else
            _act.addEventListener(
              "canplay",
              function () {
                vid.preload = "auto";
              },
              { once: true },
            );
        }
        vid.pause();
      } else {
        vid.pause();
      }
    }
    if (idx !== _ccmMediaIndex) ccmHidePausedControls(itemEl);
  });

  slide.querySelectorAll(".ccm-dash").forEach(function (d, i) {
    d.classList.toggle("active", i === _ccmMediaIndex);
  });

  var counterEl = slide.querySelector("#ccm-media-counter");
  if (counterEl) {
    var total = gallery.querySelectorAll(".ccm-media-item").length;
    counterEl.textContent = _ccmMediaIndex + 1 + "/" + total;
  }

  var activeItem = gallery.querySelector(
    '.ccm-media-item[data-idx="' + _ccmMediaIndex + '"]',
  );
  var typeTagEl = slide.querySelector("#ccm-type-tag");
  if (activeItem && typeTagEl) {
    var info = _ccmTypeTagInfo(activeItem.dataset.type);
    typeTagEl.innerHTML =
      '<i class="' + info.icon + '"></i><span>' + info.label + "</span>";
  }

  var progressBar = slide.querySelector(".ccm-video-progress");
  if (progressBar) {
    var activeVid = activeItem
      ? activeItem.querySelector("video.ccm-media")
      : null;
    progressBar.style.display = activeVid ? "flex" : "none";
    ccmInitProgressScrub(progressBar);
  }
  ccmEnforceOneSound();
}

/* moving to ANOTHER photo / video of the same post starts it fresh: forget the "paused" flag and bring the soundtrack back */
function _ccmClearPauseOnMediaChange() {
  if (_ccmMemeId && _ccmPausedMemeIds[_ccmMemeId]) {
    delete _ccmPausedMemeIds[_ccmMemeId];
    var t = document.getElementById("ccmSoundtrackPlayer");
    if (t && t.src) _ccmFadePlay(t);
  }
}

/* arrow / keyboard next-previous step with a 200 ms lock so a rapid double-fire can never skip a media item */
var _ccmMediaNavLock = false;
function _ccmMediaNavStep(delta) {
  if (_ccmMediaNavLock) return;
  var slide = document.getElementById("ccm-slide");
  if (!slide) return;
  var mediaBox = slide.querySelector("#ccm-media-box");
  if (!mediaBox) return;
  var count = parseInt(mediaBox.dataset.count || "1", 10);
  var next = _ccmMediaIndex + delta;
  if (next < 0 || next > count - 1) return;
  _ccmMediaNavLock = true;
  setTimeout(function () {
    _ccmMediaNavLock = false;
  }, 200);
  _ccmMediaIndex = next;
  _ccmSaveMediaIndex(_ccmMemeId, _ccmMediaIndex);
  _ccmClearPauseOnMediaChange();
  ccmUpdateMediaGallery(slide);
}
function ccmMediaNext() {
  _ccmMediaNavStep(1);
}
function ccmMediaPrev() {
  _ccmMediaNavStep(-1);
}

function _ccmInitMediaSwipe(slide) {
  var gallery = slide.querySelector("#ccm-media-gallery");
  var mediaBox = slide.querySelector("#ccm-media-box");
  if (!gallery || !mediaBox) return;
  if (mediaBox._ccmSwipeBound) return;
  var mediaCount = parseInt(mediaBox.dataset.count || "1", 10);
  if (mediaCount <= 1) return;
  mediaBox._ccmSwipeBound = true;

  var startX = 0,
    startY = 0,
    dragging = false,
    horizontal = false;

  mediaBox.addEventListener(
    "touchstart",
    function (e) {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      dragging = true;
      horizontal = false;
      gallery.style.transition = "none";
    },
    { passive: true },
  );

  mediaBox.addEventListener(
    "touchmove",
    function (e) {
      if (!dragging) return;
      var dx = e.touches[0].clientX - startX;
      var dy = e.touches[0].clientY - startY;
      if (!horizontal && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) {
        horizontal = true;
        _ccmVDragAbort();
      }
      if (horizontal) {
        e.stopPropagation();
        gallery.style.transform =
          "translateX(calc(-" + _ccmMediaIndex * 100 + "% + " + dx + "px))";
      }
    },
    { passive: true },
  );

  mediaBox.addEventListener(
    "touchend",
    function (e) {
      if (!dragging) return;
      dragging = false;
      gallery.style.transition = "transform .28s cubic-bezier(.22,1,.36,1)";
      if (horizontal) {
        e.stopPropagation();
        var dx = e.changedTouches[0].clientX - startX;
        var count = parseInt(mediaBox.dataset.count || "1", 10);
        var prevIdx = _ccmMediaIndex;
        if (Math.abs(dx) > 50) {
          if (dx < 0 && _ccmMediaIndex < count - 1) _ccmMediaIndex++;
          else if (dx > 0 && _ccmMediaIndex > 0) _ccmMediaIndex--;
        }
        _ccmSaveMediaIndex(_ccmMemeId, _ccmMediaIndex);
        if (_ccmMediaIndex !== prevIdx) _ccmClearPauseOnMediaChange();
        /* only a real sideways swipe re-syncs the gallery. A plain TAP must not: this used to call play() on
               the active video at touchend, a moment BEFORE the click that toggles pause — so tap-to-resume
               played the video and then the click paused it again, which looked like a frozen video. */
        ccmUpdateMediaGallery(slide);
      }
    },
    { passive: true },
  );
}

/* ── DESKTOP WHEEL + KEYBOARD NAV ── */
(function () {
  var m = document.getElementById("cardCaptionModal");
  if (m && window.CCM_PAGE_MODE) m.classList.add("ccm-pagemode");
  if (m && window.CCM_PAGE_NAV) m.classList.add("ccm-pagenav");
})();
document.getElementById("cardCaptionModal").addEventListener(
  "wheel",
  function (e) {
    if (_ccmIsInsideExpandedCaption(e.target)) return;
    e.preventDefault();

    /* wheel navigation is locked while a Connected-Zeem layer is open */
    if (_ccmHStack.length > 0) return;

    var item = ccmFeed[_ccmIndex];

    if (
      item &&
      item.type === "sugg" &&
      Math.abs(e.deltaX) > Math.abs(e.deltaY)
    ) {
      var slideElS = document.getElementById("ccm-slide");
      var suggRoot = slideElS
        ? slideElS.querySelector(".ccm-sugg-slide")
        : null;
      if (e.deltaX > 0) ccmSuggNext(suggRoot);
      else ccmSuggPrev(suggRoot);
      return;
    }

    var mediaBox = e.target.closest && e.target.closest("#ccm-media-box");
    var mediaCount = mediaBox ? parseInt(mediaBox.dataset.count || "1", 10) : 1;
    if (mediaBox && mediaCount > 1 && Math.abs(e.deltaX) > Math.abs(e.deltaY)) {
      if (e.deltaX > 0) ccmMediaNext();
      else ccmMediaPrev();
      return;
    }

    /* one scroll gesture = one post: ignore tiny deltas and the trackpad's inertia tail */
    if (Math.abs(e.deltaY) < 6) return;
    var _now = Date.now();
    if (_now - _ccmLastWheelNav < CCM_SLIDE_MS + 250) return;
    _ccmLastWheelNav = _now;

    if (e.deltaY > 0) ccmLoadIndex(_ccmIndex + 1);
    else ccmLoadIndex(_ccmIndex - 1);
  },
  { passive: false },
);

/* ═══ Remote control (D-pad) hook — works together with the shared dz_tv include (window.__dzTv) ═══
   Browsing mode (nothing focused): arrows = previous / next post and photo, exactly as before. OK jumps into the buttons (Like first).
   Button mode: the shared include moves the highlight between buttons. Back steps out to browsing mode, Back again closes the modal.
   Does nothing on pages where the dz_tv include is not loaded. */
if (!window._ccmTvBound) {
  window._ccmTvBound = true;
  document.addEventListener(
    "keydown",
    function (e) {
      if (!window.__dzTv || e.ctrlKey || e.metaKey || e.altKey) return;
      var m = document.getElementById("cardCaptionModal");
      if (!m || !m.classList.contains("ccm-open")) return;
      if (typeof _ccmHStack !== "undefined" && _ccmHStack.length > 0)
        return; /* a sheet (comments, menu…) is open: the shared include drives it */
      var a = document.activeElement,
        kc = e.keyCode,
        k = e.key;
      var inBtn = !!(
        a &&
        a !== document.body &&
        a !== m &&
        m.contains(a) &&
        a.id !== "ccm-slide" &&
        a.id !== "ccm-media-box"
      );
      var isArrow =
        /^Arrow(Up|Down|Left|Right)$/.test(k) ||
        kc === 37 ||
        kc === 38 ||
        kc === 39 ||
        kc === 40;
      var isBack =
        kc === 461 ||
        kc === 10009 ||
        kc === 4 ||
        k === "BrowserBack" ||
        k === "GoBack";
      var isOk = k === "Enter" || k === "OK" || kc === 13;
      if (inBtn) {
        if (isBack) {
          e.preventDefault();
          e.stopPropagation();
          try {
            a.blur();
          } catch (_) {}
        } /* back to browsing posts */
        return; /* arrows / OK: handled by the shared include */
      }
      if (isArrow) {
        if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return;
        e.preventDefault(); /* keeps the shared include from grabbing it */
        return; /* the modal's own handler then changes post / photo */
      }
      if (isOk && !(a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName))) {
        var like = document.getElementById("ccm-like-btn"),
          t = like;
        if (!t || t.getBoundingClientRect().width < 2)
          t = m.querySelector(
            'button:not([disabled]),a[href],[role=button],[tabindex="0"]',
          );
        if (t) {
          e.preventDefault();
          e.stopPropagation();
          document.documentElement.classList.add("dz-kbd");
          if (!t.hasAttribute("tabindex") && !/^(BUTTON|A)$/.test(t.tagName))
            t.setAttribute("tabindex", "0");
          try {
            t.focus({ preventScroll: true });
          } catch (_) {
            t.focus();
          }
        }
      }
    },
    true,
  );
}

if (!window._ccmKeydownBound) {
  window._ccmKeydownBound = true;

  document.addEventListener("keydown", function (e) {
    var modal = document.getElementById("cardCaptionModal");
    if (!modal || !modal.classList.contains("ccm-open")) return;

    if (e.key === "Escape") {
      ccmHandleBackTap();
      return;
    }
    if (_ccmHStack.length > 0) return;
    /* desktop shortcuts: Space/K pause · M mute · L like — never while typing, and never with Ctrl/Cmd/Alt held */
    var _tg = e.target,
      _typing =
        _tg &&
        (_tg.isContentEditable ||
          /^(INPUT|TEXTAREA|SELECT)$/.test(_tg.tagName) ||
          (_tg.closest && _tg.closest("button,a")));
    if (!_typing && !e.ctrlKey && !e.metaKey && !e.altKey) {
      var _k = (e.key || "").toLowerCase();
      if (_k === " " || _k === "k") {
        var _mb = document.querySelector("#ccm-slide #ccm-media-box");
        if (_mb) {
          e.preventDefault();
          try {
            ccmToggleVideoPlay(_mb);
          } catch (_) {}
        }
        return;
      }
      if (_k === "m") {
        var _mbtn = document.querySelector("#ccm-slide .ccm-mute-btn");
        if (_mbtn) {
          e.preventDefault();
          _mbtn.click();
        }
        return;
      }
      if (_k === "l") {
        var _lb = document.getElementById("ccm-like-btn");
        if (_lb) {
          e.preventDefault();
          _lb.click();
        }
        return;
      }
    }
    if (e.key === "ArrowDown") {
      ccmLoadIndex(_ccmIndex + 1);
      return;
    }
    if (e.key === "ArrowUp") {
      ccmLoadIndex(_ccmIndex - 1);
      return;
    }

    if (e.key !== "ArrowLeft" && e.key !== "ArrowRight") return;

    var item = ccmFeed[_ccmIndex];
    if (!item) return;

    if (item.type === "sugg") {
      e.preventDefault();
      var slideElS = document.getElementById("ccm-slide");
      var suggRoot = slideElS
        ? slideElS.querySelector(".ccm-sugg-slide")
        : null;
      if (e.key === "ArrowLeft") ccmSuggPrev(suggRoot);
      else ccmSuggNext(suggRoot);
      return;
    }

    if (item.type === "post") {
      var slideEl2 = document.getElementById("ccm-slide");
      var mediaBox = slideEl2 ? slideEl2.querySelector("#ccm-media-box") : null;
      var mediaCount = mediaBox
        ? parseInt(mediaBox.dataset.count || "1", 10)
        : 1;
      if (mediaCount > 1) {
        e.preventDefault();
        if (e.key === "ArrowLeft") ccmMediaPrev();
        else ccmMediaNext();
      }
    }
  });
}

window.addEventListener("resize", function () {
  var slide = document.getElementById("ccm-slide");
  if (slide) {
    _ccmCheckCaptionOverflow(slide);
    _ccmInitAudioMarquee(slide);
  }
});

/* ══════════════════════════════════════════════════════════
   RENDER — POST SLOT
══════════════════════════════════════════════════════════ */
/* optional still image for a video (profile page fills p.poster / m.poster, alias cover). Only read when the modal was opened by id,
   so home / text / search behave exactly as before even if their posts carry a cover field. */
function _ccmPosterFor(p, m, isFirst) {
  var u =
    (m && (m.poster || m.cover)) ||
    (isFirst && p && (p.poster || p.cover)) ||
    "";
  if (!u && m && m.file && _ccmPosterCache[m.file])
    u =
      _ccmPosterCache[
        m.file
      ]; /* first frame we captured while warming this video up */
  return u ? String(u) : "";
}

function _ccmRenderPost(p, isPreview) {
  /* host can mark a post hidden/moderated: a plain dark slide with the host's message instead of the media */
  if (CCM_CFG.isHidden && CCM_CFG.isHidden(p)) {
    if (!isPreview) {
      _ccmMediaIndex = 0;
    }
    return (
      '<div class="ccm-hidden-slide">' +
      (CCM_CFG.hiddenHtml ? CCM_CFG.hiddenHtml(p) || "" : "") +
      "</div>"
    );
  }
  var isOwnPost = String(p.userId) === String(currentUserId);

  var media =
    Array.isArray(p.media) && p.media.length
      ? p.media.slice()
      : [{ file: p.file, type: p.type }];
  var mediaCount = media.length;

  var mediaSet = ["image", "video", "gif", "sticker", "stickers"];
  var firstType = (media[0].type || "").toLowerCase();
  var isTextOnly = mediaCount === 1 && mediaSet.indexOf(firstType) === -1;

  var hasSeparateTrack = !!p.audioFile && !p.audioIsOriginal;
  var isMemePaused = !!_ccmPausedMemeIds[p.id];

  var initIdx,
    initTime = 0,
    pendingPoster = null;
  if (isPreview) {
    initIdx = 0;
  } else {
    initIdx = isTextOnly ? 0 : _ccmGetSavedMediaIndex(p.id);
    initIdx = Math.max(0, Math.min(initIdx, mediaCount - 1));
    _ccmMediaIndex = initIdx;
    /* one-shot start state from openCardModal({...}) — only the first (current) render consumes it */
    initTime = _ccmPendingStartTime;
    pendingPoster = _ccmPendingPosterUrl;
    _ccmPendingStartTime = 0;
    _ccmPendingPosterUrl = null;
    if (!pendingPoster) _ccmLastPosterUsed = false;
  }

  var feedTrackBtn = document.querySelector(
    '.track-btn[data-user="' + _ccmId(p.userId) + '"]',
  );
  /* the server may send the flag as isFollowing (feed / profile / home) or as following (the group page's connected-post answer) */
  var _fl =
    p.isFollowing !== undefined && p.isFollowing !== null
      ? p.isFollowing
      : p.following !== undefined && p.following !== null
        ? p.following
        : null;
  if (typeof _fl === "string")
    _fl = !(_fl === "" || _fl === "0" || _fl.toLowerCase() === "false");
  var isFollowing =
    _fl !== null
      ? !!_fl
      : feedTrackBtn
        ? feedTrackBtn.classList.contains("following")
        : false;

  var capState = _ccmComputeCaptionHtml(p);
  var localCaptionTruncHtml = capState.trunc;
  var mediaBoxInner;

  if (isTextOnly) {
    var _tcls = "ccm-t-" + _ccmTextSize(p);
    mediaBoxInner =
      '<div class="ccm-textonly-bg"></div>' +
      '<div class="ccm-textonly-overlay"></div>' +
      '<div class="ccm-center-caption-wrap" id="ccm-center-caption-wrap" onclick="ccmCaptionTap(event,true)">' +
      '<div class="ccm-center-caption-text ' +
      _tcls +
      '" id="ccm-center-caption-text">' +
      localCaptionTruncHtml +
      "</div>" +
      "</div>";
  } else {
    mediaBoxInner =
      '<div class="ccm-media-gallery" id="ccm-media-gallery">' +
      media
        .map(function (m, i) {
          var t = (m.type || "").toLowerCase();
          var isActive = i === initIdx;
          var loadHook = isActive
            ? "ccmHideSkeleton(this.closest('.ccm-slide'))"
            : "";
          if (t === "video") {
            var mutedAttr = hasSeparateTrack ? "muted " : "";
            var seekHook =
              isActive && !isPreview && initTime > 0
                ? "this.currentTime=" + initTime + ";"
                : "";
            var posterAttr = "";
            var posterUrl =
              isActive && !isPreview && pendingPoster
                ? pendingPoster
                : _ccmPosterFor(p, m, i === 0);
            if (posterUrl) {
              posterAttr = 'poster="' + _ccmSafeUrl(posterUrl) + '" ';
              if (isActive && !isPreview && pendingPoster)
                _ccmLastPosterUsed = true;
            }
            /* profile page only: hide the skeleton on more events (some mobile browsers never fire loadeddata before play) */
            var moreHooks = loadHook
              ? 'oncanplay="' +
                loadHook +
                '" onplaying="' +
                loadHook +
                '" onerror="' +
                loadHook +
                '" '
              : "";
            var autoplayAttr =
              isActive && !isPreview && !isMemePaused ? "autoplay" : "";
            var isAdjacent =
              !isPreview && mediaCount > 1 && Math.abs(i - initIdx) === 1;
            var preloadAttr =
              isActive && !isPreview
                ? 'preload="auto"'
                : 'preload="metadata"'; /* the neighbour is upgraded later, once the visible video can play */
            return (
              '<div class="ccm-media-item" data-idx="' +
              i +
              '" data-type="video">' +
              '<video src="' +
              _ccmSafeUrl(m.file) +
              '" ' +
              posterAttr +
              mutedAttr +
              autoplayAttr +
              " loop playsinline " +
              preloadAttr +
              " " +
              'onloadedmetadata="' +
              seekHook +
              'ccmFixObjectFit(this);" ' +
              'onloadeddata="' +
              loadHook +
              '" ' +
              moreHooks +
              'ontimeupdate="ccmUpdateProgress(this)" class="ccm-media"></video>' +
              "</div>"
            );
          }
          return (
            '<div class="ccm-media-item" data-idx="' +
            i +
            '" data-type="' +
            t +
            '">' +
            '<img src="' +
            _ccmSafeUrl(m.file) +
            '" onerror="this.src=\'mee.jpg\'" ' +
            'onload="ccmFixObjectFit(this);' +
            loadHook +
            '" class="ccm-media">' +
            "</div>"
          );
        })
        .join("") +
      "</div>" +
      (mediaCount > 1
        ? '<div class="ccm-media-arrow ccm-media-arrow-left" onclick="event.stopPropagation();ccmMediaPrev()"><i class="ri-arrow-left-s-line"></i></div>' +
          '<div class="ccm-media-arrow ccm-media-arrow-right" onclick="event.stopPropagation();ccmMediaNext()"><i class="ri-arrow-right-s-line"></i></div>'
        : "");
  }

  var typeInfo = _ccmTypeTagInfo(
    isTextOnly ? "text" : (media[initIdx] && media[initIdx].type) || firstType,
  );
  var typeTagHtml =
    '<div class="ccm-type-tag" id="ccm-type-tag"><i class="' +
    typeInfo.icon +
    '"></i><span>' +
    typeInfo.label +
    "</span></div>";
  var counterHtml =
    mediaCount > 1
      ? '<div class="ccm-media-counter" id="ccm-media-counter">' +
        (initIdx + 1) +
        "/" +
        mediaCount +
        "</div>"
      : "";

  var dashGap = mediaCount > 1 ? _ccmComputeDashGap(mediaCount) : 6;
  var dashesHtml =
    mediaCount > 1
      ? '<div class="ccm-media-dashes" id="ccm-media-dashes" style="gap:' +
        dashGap +
        'px;">' +
        media
          .map(function (_, i) {
            return (
              '<span class="ccm-dash' +
              (i === initIdx ? " active" : "") +
              '"></span>'
            );
          })
          .join("") +
        "</div>"
      : "";

  var groupTagHtml = "";
  if (p.groupId) {
    groupTagHtml =
      '<a href="group.php?id=' +
      _ccmId(p.groupId) +
      '" onclick="event.stopPropagation()" class="ccm-group-tag">' +
      'From&nbsp;<span class="ccm-group-tag-link">' +
      _ccmEscapeHtml(_ccmTruncateGroupName(p.groupTitle || "Group")) +
      "</span>" +
      "</a>";
  }

  /* "AI Generated" is now plain text (no badge, no sparkle) and joins the nickname / audio flipping line */
  var aiLabelHtml = p.isAiGenerated
    ? '<div class="ccm-ai-label">AI Generated</div>'
    : "";

  var likeCount = p.likeCount !== undefined ? p.likeCount : 0;
  var repostCount = p.repostCount !== undefined ? p.repostCount : 0;
  var favCount = p.favCount !== undefined ? p.favCount : 0;
  var shareCount = p.shareCount !== undefined ? p.shareCount : 0;
  var hideL = !isOwnPost && !!p.hideLikes;
  var hideR = !isOwnPost && !!p.hideReposts;
  var hideS = !isOwnPost && !!p.hideSaves;
  var hideH = !isOwnPost && !!p.hideShares;
  var liked = p.liked || false;
  var reposted = p.reposted || false;
  var faved = p.faved || false;

  var _co = p.collab && p.collab.list && p.collab.list.length ? p.collab : null;
  var trackBtnHtml =
    !isOwnPost && !_co
      ? '<span class="ccm-user-dot">·</span>' +
        '<button class="ccm-track-btn ' +
        (isFollowing ? "following" : "") +
        '" data-user="' +
        _ccmId(p.userId) +
        '" ' +
        'onclick="event.stopPropagation();ccmToggleTrack(this)">' +
        (isFollowing ? "Untrack" : "Track") +
        "</button>"
      : "";

  var isNewPost = false;
  if (p.time) {
    var _pd = new Date((p.time || "").replace(" ", "T"));
    if (!isNaN(_pd)) isNewPost = Date.now() - _pd.getTime() <= 172800 * 1000;
  }
  var displayName = isOwnPost
    ? "You"
    : _ccmEscapeHtml(_ccmTruncateName(p.username));
  /* post collaboration: overlapping pics (poster + up to 2 others), "Poster and Sam" / "Poster and 3 others"; tapping either opens the Collaborators sheet */
  var coOpen = _co
    ? "event.stopPropagation();dzCollabSheet(" +
      p.id +
      ", ccmCollabOf(" +
      p.id +
      "))"
    : "";
  var coPics = "",
    coNameHtml = "";
  if (_co) {
    _co.list.slice(0, 2).forEach(function (c, i) {
      coPics +=
        '<img src="' +
        _ccmSafeUrl(c.pic, "mee.jpg") +
        '" onerror="this.src=\'mee.jpg\'" class="ccm-user-pic ccm-co-pic" style="--i:' +
        (i + 1) +
        '" onclick="' +
        coOpen +
        '">';
    });
    displayName =
      displayName +
      " and " +
      (_co.list.length === 1
        ? _ccmEscapeHtml(_ccmTruncateName(_co.list[0].username))
        : _co.list.length + " others");
    /* same text with a red verified badge after the poster, and after the other person when it is just the two of them */
    var _vb =
      '<svg class="ccm-vbadge ccm-vbadge-co" viewBox="0 0 256 256" aria-label="Verified"><use href="#ccm-vflower"/></svg>';
    coNameHtml =
      (isOwnPost ? "You" : _ccmEscapeHtml(_ccmTruncateName(p.username))) +
      " and " +
      (_co.list.length === 1
        ? _ccmEscapeHtml(_ccmTruncateName(_co.list[0].username))
        : _co.list.length + " others");
  }
  var nicknameHtml =
    !isOwnPost && p.nickname && !_co
      ? '<div class="ccm-nickname">@' + _ccmEscapeHtml(p.nickname) + "</div>"
      : "";

  var audioTagHtml = "";
  if (p.audioId !== undefined && p.audioId !== null) {
    var audioLabel;
    if (p.audioIsOriginal) {
      audioLabel = (isOwnPost ? p.username : displayName) + " · Original audio";
    } else {
      audioLabel =
        (p.audioTitle || "Audio") +
        (p.audioArtist ? " — " + p.audioArtist : "");
    }
    audioTagHtml =
      '<div class="ccm-audio-tag" onclick="event.stopPropagation();ccmOpenSoundsPage(' +
      _ccmId(p.userId) +
      "," +
      _ccmId(p.audioId) +
      "," +
      (p.isAd ? "true" : "false") +
      ')">' +
      '<span class="ccm-audio-icon" aria-hidden="true">' +
      '<span class="ccm-eq-bar"></span><span class="ccm-eq-bar"></span><span class="ccm-eq-bar"></span>' +
      "</span>" +
      '<div class="ccm-audio-track" id="ccm-audio-track">' +
      '<span class="ccm-audio-track-text" id="ccm-audio-track-text">' +
      _ccmEscapeHtml(audioLabel) +
      "</span>" +
      "</div>" +
      "</div>";
  }
  var switcherHtml = "";
  var infoPanes = [];
  if (nicknameHtml)
    infoPanes.push({ cls: "ccm-switch-nickname-pane", html: nicknameHtml });
  if (audioTagHtml)
    infoPanes.push({ cls: "ccm-switch-audio-pane", html: audioTagHtml });
  if (aiLabelHtml)
    infoPanes.push({ cls: "ccm-switch-ai-pane", html: aiLabelHtml });
  if (p.isPartnership) {
    /* paid partnership: same small grey line as the nickname, takes its turn in the flip */
    var _pbrand = p.partnerBrand
      ? " with <strong>" + _ccmEscapeHtml(p.partnerBrand) + "</strong>"
      : "";
    infoPanes.push({
      cls: "ccm-switch-partner-pane",
      html:
        '<div class="ccm-nickname ccm-partner-line">Paid partnership' +
        _pbrand +
        "</div>",
    });
  }
  if (infoPanes.length >= 2) {
    switcherHtml =
      '<div class="ccm-info-switcher" id="ccm-info-switcher">' +
      infoPanes
        .map(function (pn, i) {
          return (
            '<div class="ccm-switch-pane ' +
            pn.cls +
            (i === 0 ? " active" : "") +
            '">' +
            pn.html +
            "</div>"
          );
        })
        .join("") +
      "</div>";
  } else if (infoPanes.length === 1) {
    switcherHtml = infoPanes[0].html;
  }

  /* bottom-left slot sits higher when the paid-partnership badge is below it */
  var _ccmNeedsRaise = false; /* the partnership line now lives in the flipping line, so nothing sits below the caption */

  /* SOCIAL PROOF — suppressed for ad posts and connected-Zeem posts */
  var socialProofHtml = _ccmSocialProofHtml(p);

  /* CTA PILL — ad posts with a URL */
  var ctaPillHtml = "";
  var _ccmPillCo =
    p.collab && p.collab.list && p.collab.list.length
      ? " ccm-pill-co"
      : ""; /* collab post: the pills sit higher so they never touch the stacked poster pictures */
  if (p.isAd && p.adCtaUrl) {
    var ctaClass =
      "ccm-cta-pill" + (_ccmNeedsRaise ? " ccm-sp-raised" : "") + _ccmPillCo;
    var ctaColor = _ccmSafeColor(p.adCtaColor, "var(--ccm-accent, #ff4c4c)");
    var ctaLabel = p.adCtaText || "Learn more";
    ctaPillHtml =
      '<a href="' +
      _ccmSafeUrl(p.adCtaUrl, "#") +
      '" target="_blank" rel="noopener noreferrer" id="ccm-cta-pill" class="' +
      ctaClass +
      '" ' +
      'style="--cta-color:' +
      ctaColor +
      ';" onclick="event.stopPropagation()">' +
      '<span class="ccm-cta-pill-text">' +
      _ccmEscapeHtml(ctaLabel) +
      "</span>" +
      '<i class="ri-arrow-right-s-line ccm-cta-pill-chevron"></i>' +
      "</a>";
  }

  /* POLL PILL — a post with a poll: "Vote · 5 responses" (same glass, square corners). Tapping it opens the comment sheet, where the choices are. */
  if (!ctaPillHtml && p.poll && p.poll.options && p.poll.options.length > 1) {
    ctaPillHtml =
      '<button type="button" id="ccm-poll-pill" class="ccm-cta-pill ccm-poll-pill' +
      (_ccmNeedsRaise ? " ccm-sp-raised" : "") +
      _ccmPillCo +
      '" aria-label="Open the poll" onclick="event.stopPropagation();ccmOpenPoll(' +
      _ccmId(p.id) +
      ')">' +
      '<svg class="ccm-poll-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h10M4 18h13" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>' +
      '<span class="ccm-cta-pill-text" id="ccm-poll-pill-text">' +
      _ccmEscapeHtml(_ccmPollPillText(p.poll)) +
      "</span>" +
      "</button>";
  }

  /* CONNECTED ZEEM PILL */
  var connectedTagHtml = "";
  if (p.connectedMemeId) {
    var stackedClass = ctaPillHtml ? " ccm-connected-below-cta" : "";
    var raisedClass = _ccmNeedsRaise ? " ccm-sp-raised" : "";
    var connLabel = p.connectedLabel
      ? _ccmEscapeHtml(p.connectedLabel)
      : "Connected Zeem";
    connectedTagHtml =
      '<a href="#" id="ccm-connected-tag" class="ccm-connected-tag' +
      raisedClass +
      stackedClass +
      _ccmPillCo +
      '" ' +
      'onclick="event.stopPropagation();event.preventDefault();ccmOpenConnectedZeem(' +
      _ccmId(p.connectedMemeId) +
      ')">' +
      '<span class="ccm-connected-icon"><i class="ri-play-fill"></i></span>' +
      '<span class="ccm-connected-text">' +
      '<span class="ccm-connected-text-main">View</span>' +
      '<span class="ccm-connected-text-dot">·</span>' +
      '<span class="ccm-connected-text-label">' +
      connLabel +
      "</span>" +
      "</span>" +
      "</a>";
  }

  var bottomCaptionHtml = "";
  if (!isTextOnly) {
    bottomCaptionHtml =
      '<div class="ccm-caption-wrap" id="ccm-caption-wrap" onclick="ccmCaptionTap(event,false)">' +
      '<span class="ccm-caption-text" id="ccm-caption-text">' +
      localCaptionTruncHtml +
      "</span>" +
      "</div>";
  }

  var partnershipHtml = "";
  if (p.isPartnership) {
    var brandText = p.partnerBrand
      ? " with <strong>" + _ccmEscapeHtml(p.partnerBrand) + "</strong>"
      : "";
    partnershipHtml =
      '<div class="ccm-partnership-tag"><i class="ri-handshake-line"></i>Paid partnership' +
      brandText +
      "</div>";
  }

  var railClass = "ccm-rail" + (mediaCount > 1 ? " ccm-rail-raised" : "");

  var _firstM = media[initIdx];
  var _firstHasPoster =
    !isTextOnly &&
    !isPreview &&
    _firstM &&
    (_firstM.type || "").toLowerCase() === "video" &&
    !!(pendingPoster || _ccmPosterFor(p, _firstM, initIdx === 0));
  var skeletonHtml = isTextOnly || _firstHasPoster ? "" : _ccmSkeletonHtml();

  var initialActiveType = isTextOnly
    ? ""
    : ((media[initIdx] && media[initIdx].type) || firstType).toLowerCase();
  var progressBarHtml = !isTextOnly
    ? '<div class="ccm-video-progress" style="display:' +
      (initialActiveType === "video" ? "flex" : "none") +
      ';">' +
      '<div class="ccm-video-progress-track">' +
      '<div class="ccm-video-progress-fill"></div>' +
      '<div class="ccm-video-progress-thumb"></div>' +
      "</div>" +
      "</div>"
    : "";

  /* RAIL — profile-pic shortcut is swapped for an "AD" tag on ad posts */
  var railMsgOrAdHtml = "";
  if (p.isAd) {
    railMsgOrAdHtml = '<span class="ccm-rail-ad-label">AD</span>';
  } else if (!isOwnPost) {
    railMsgOrAdHtml =
      '<button type="button" class="ccm-rail-btn ccm-rail-msg" aria-label="Sound" onclick="event.stopPropagation();ccmOpenSoundsPage(' +
      p.userId +
      (p.audioId !== undefined && p.audioId !== null ? "," + p.audioId : "") +
      ')">' +
      '<img src="' +
      _ccmSafeUrl(p.pic, "mee.jpg") +
      '" onerror="this.src=\'mee.jpg\'" class="ccm-rail-msg-pic">' +
      "</button>";
  }

  return (
    skeletonHtml +
    '<div class="ccm-media-box" id="ccm-media-box" data-count="' +
    mediaCount +
    '" ' +
    'onclick="event.stopPropagation();ccmHandleMediaTap(event,this,' +
    (mediaCount > 1) +
    ')"' +
    ">" +
    mediaBoxInner +
    "</div>" +
    groupTagHtml +
    '<div class="ccm-top-right-tags">' +
    counterHtml +
    "</div>" +
    (CCM_CFG.extraHtml ? CCM_CFG.extraHtml(p) || "" : "") +
    _ccmShareStaticHtml(p, isPreview) +
    _ccmAskStaticHtml(p, isPreview) +
    socialProofHtml +
    ctaPillHtml +
    connectedTagHtml +
    '<div class="' +
    railClass +
    '">' +
    '<button id="ccm-like-btn" class="ccm-rail-btn ' +
    (liked ? "active" : "") +
    '" aria-label="Like" aria-pressed="' +
    (liked ? "true" : "false") +
    '" onclick="event.stopPropagation();ccmAction(\'like\')">' +
    '<i class="ccm-repost-i ccm-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></i><span id="ccm-like-count" class="ccm-rail-count" data-hidden="' +
    (hideL ? 1 : 0) +
    '">' +
    (hideL ? "" : likeCount) +
    "</span>" +
    "</button>" +
    (p.isAd
      ? ""
      : '<button id="ccm-repost-btn" class="ccm-rail-btn ' +
        (reposted ? "active" : "") +
        '" aria-label="Repost" aria-pressed="' +
        (reposted ? "true" : "false") +
        '" onclick="event.stopPropagation();ccmAction(\'repost\')">' +
        '<i class="ccm-repost-i ccm-rp-big"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></svg></i><span id="ccm-repost-count" class="ccm-rail-count" data-hidden="' +
        (hideR ? 1 : 0) +
        '">' +
        (hideR ? "" : repostCount) +
        "</span>" +
        "</button>") +
    '<button id="ccm-comment-btn" class="ccm-rail-btn" aria-label="Comments" onclick="event.stopPropagation();ccmOpenComments(' +
    p.id +
    ')">' +
    '<i class="ccm-repost-i"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h15A1.5 1.5 0 0 1 21 4.5v11a1.5 1.5 0 0 1-1.5 1.5H7l-4 4z"/></svg><i class="ri-chat-off-line ccm-off-ico" aria-hidden="true"></i></i><span id="ccm-comment-count" class="ccm-rail-count" data-hidden="0">0</span>' +
    "</button>" +
    '<button id="ccm-fav-btn" class="ccm-rail-btn ' +
    (faved ? "active" : "") +
    '" aria-label="Save" aria-pressed="' +
    (faved ? "true" : "false") +
    '" onclick="event.stopPropagation();ccmAction(\'fav\')">' +
    '<i class="ccm-repost-i ccm-fillable"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21l-7-5-7 5V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1z"/></svg></i><span id="ccm-fav-count" class="ccm-rail-count" data-hidden="' +
    (hideS ? 1 : 0) +
    '">' +
    (hideS ? "" : favCount) +
    "</span>" +
    "</button>" +
    '<button id="ccm-share-btn" class="ccm-rail-btn" aria-label="Share" onclick="event.stopPropagation();openShareModal(' +
    p.id +
    ')">' +
    '<i class="ccm-repost-i"><svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 4.3c0-.7.8-1 1.3-.6l6.9 6.2c.4.4.4 1 0 1.4l-6.9 6.2c-.5.4-1.3.1-1.3-.6v-3.3c-4.3-.1-7.3 1-9.4 4-.4.6-1.3.3-1.2-.4.7-6 4-10.2 10.6-10.7z"/></svg></i><span id="ccm-share-count" class="ccm-rail-count" data-hidden="' +
    (hideH ? 1 : 0) +
    '">' +
    (hideH ? "" : shareCount) +
    "</span>" +
    "</button>" +
    '<button class="ccm-rail-btn" aria-label="More options" onclick="event.stopPropagation();ccmMenuTap(' +
    p.id +
    ')">' +
    '<i class="ri-menu-line"></i>' +
    "</button>" +
    railMsgOrAdHtml +
    "</div>" +
    '<div class="ccm-bottom">' +
    '<div class="ccm-user-row">' +
    '<span class="av-wrap' +
    (_co ? " collab" : "") +
    '" data-uid="' +
    _ccmId(p.userId) +
    '"' +
    (_co ? ' data-extra="' + Math.min(2, _co.list.length) + '"' : "") +
    ">" +
    '<img src="' +
    _ccmSafeUrl(p.pic, "mee.jpg") +
    '" onerror="this.src=\'mee.jpg\'" class="ccm-user-pic" onclick="' +
    (_co
      ? coOpen
      : "location.href='profile.php?user_id=" + _ccmId(p.userId) + "'") +
    '">' +
    coPics +
    (!isOwnPost && p.online && !_co ? '<i class="on-dot"></i>' : "") +
    "</span>" +
    '<div class="ccm-user-info">' +
    '<div class="ccm-user-line1">' +
    '<span class="ccm-username" onclick="' +
    (_co
      ? coOpen
      : "location.href='profile.php?user_id=" + _ccmId(p.userId) + "'") +
    '">' +
    (_co ? coNameHtml : displayName) +
    "</span>" +
    (p.isVerified && !_co
      ? '<svg class="ccm-vbadge" viewBox="0 0 256 256" aria-label="Verified"><use href="#ccm-vflower"/></svg>'
      : "") +
    trackBtnHtml +
    "</div>" +
    switcherHtml +
    "</div>" +
    "</div>" +
    bottomCaptionHtml +
    dashesHtml +
    "</div>" +
    progressBarHtml
  );
}

/* the Collaborators sheet needs the post's collab data; the feed item holds it */
function ccmCollabOf(id) {
  var hit = null;
  try {
    (ccmFeed || []).forEach(function (s) {
      if (s.type === "post" && String(s.data.id) === String(id))
        hit = s.data.collab;
    });
  } catch (e) {}
  try {
    if (
      !hit &&
      typeof _ccmConnectedCache !== "undefined" &&
      _ccmConnectedCache[id]
    )
      hit = _ccmConnectedCache[id].collab;
  } catch (e) {}
  return hit || null;
}

/* poster picture = height of the username + nickname block beside it (never under a floor) */
(function () {
  var ro = window.ResizeObserver
    ? new ResizeObserver(function (es) {
        es.forEach(function (e) {
          fit(e.target);
        });
      })
    : null;
  function fit(info) {
    var av = info.parentNode && info.parentNode.querySelector(".av-wrap");
    if (!av) return;
    var r = info.offsetHeight;
    if (!r)
      return; /* layout height — not shrunk by the open/slide scale animation */
    var s =
      parseFloat(
        getComputedStyle(
          document.getElementById("cardCaptionModal"),
        ).getPropertyValue("--ccm-s"),
      ) || 1;
    var h = Math.round(Math.max(36 * s, Math.round(r)) * 1.1);
    /* a collab post has no nickname line, so its text block is shorter: borrow the normal height so the social-proof avatars (which follow --ccm-poster-h) keep their size */
    if (av.classList.contains("collab")) {
      /* measure the block as if it had its nickname line (a hidden probe), so every collab post lands on the SAME base size as a normal post */
      var _cl = info.cloneNode(true);
      while (_cl.children.length > 1)
        _cl.removeChild(_cl.lastChild); /* keep only the username row … */
      var _pr = document.createElement("div");
      _pr.className = "ccm-nickname";
      _pr.textContent = "@probe";
      _cl.appendChild(_pr); /* … plus a nickname line = the standard block */
      _cl.style.cssText +=
        ";visibility:hidden;pointer-events:none;position:absolute;left:0;top:0;width:" +
        info.offsetWidth +
        "px";
      info.parentNode.appendChild(_cl);
      var _rh = _cl.offsetHeight;
      info.parentNode.removeChild(_cl);
      if (_rh) h = Math.round(Math.max(36 * s, Math.round(_rh)) * 1.1);
    }
    var bw = av.classList.contains("collab")
      ? Math.round(h * 1.45)
      : h; /* collab cluster box is a little bigger than one picture */
    av.style.width = bw + "px";
    av.style.height = bw + "px";
    av.setAttribute("data-fit", "1");
    if (
      info.closest("#ccm-slide") ||
      !document
        .getElementById("cardCaptionModal")
        .style.getPropertyValue("--ccm-poster-h")
    )
      document
        .getElementById("cardCaptionModal")
        .style.setProperty("--ccm-poster-h", h + "px");
  }
  function refitAll() {
    document.querySelectorAll("#cardCaptionModal .ccm-user-info").forEach(fit);
  }
  function scan() {
    var m = document.getElementById("cardCaptionModal");
    if (!m) return;
    m.querySelectorAll(".ccm-user-info").forEach(function (info) {
      if (info._ccmFit) return;
      info._ccmFit = 1;
      fit(info);
      if (ro) ro.observe(info);
      /* two cheap one-shot re-checks instead of polling: after the first paint, and once the open animation has settled */
      requestAnimationFrame(function () {
        if (info.isConnected) fit(info);
      });
      setTimeout(function () {
        if (info.isConnected) fit(info);
      }, 450);
    });
  }
  /* event-driven (no 400ms polling): a new slide is measured when it is rendered, again when fonts finish loading,
       when the window / phone rotates, and whenever its own size changes (ResizeObserver) */
  var _scanQ = 0;
  function scanSoon() {
    if (_scanQ) return;
    _scanQ = requestAnimationFrame(function () {
      _scanQ = 0;
      scan();
    });
  }
  (function () {
    var m = document.getElementById("cardCaptionModal");
    new MutationObserver(scanSoon).observe(m || document.body, {
      childList: true,
      subtree: true,
    }); /* only the modal, batched to one frame */
  })();
  if (document.fonts && document.fonts.ready)
    document.fonts.ready.then(refitAll);
  window.addEventListener("load", refitAll);
  window.addEventListener("resize", refitAll);
  window.addEventListener("orientationchange", function () {
    setTimeout(refitAll, 250);
  });
})();

/* when a caller doesn't say whether the post is an ad (e.g. a feed card), look it up:
   block only if every known post with this sound + user is an ad */
function _ccmSoundIsAdOnly(userId, audioId) {
  var pools = [];
  try {
    pools.push(
      (ccmFeed || [])
        .filter(function (s) {
          return s.type === "post";
        })
        .map(function (s) {
          return s.data;
        }),
    );
  } catch (e) {}
  try {
    if (typeof peuraFeed !== "undefined") pools.push(peuraFeed);
  } catch (e) {}
  try {
    pools.push(
      Object.keys(_ccmConnectedCache).map(function (k) {
        return _ccmConnectedCache[k];
      }),
    );
  } catch (e) {}
  var seen = 0,
    ads = 0;
  pools.forEach(function (list) {
    (list || []).forEach(function (p) {
      if (!p || String(p.userId) !== String(userId)) return;
      if (
        audioId !== undefined &&
        audioId !== null &&
        String(p.audioId) !== String(audioId)
      )
        return;
      seen++;
      if (p.isAd) ads++;
    });
  });
  return seen > 0 && seen === ads;
}

function ccmOpenSoundsPage(userId, audioId, isAd) {
  if (!userId) return;
  if (isAd === undefined) isAd = _ccmSoundIsAdOnly(userId, audioId);
  if (isAd) {
    ccmToast("Sorry, this feature doesn't work for Ad posts");
    return;
  }
  var url = "audio.php?user_id=" + userId;
  if (audioId) {
    url += "&audio_id=" + audioId;
  }
  window.location.href = url;
}

/* ── SUGGESTED USERS SLOT ── */
function _ccmSuggRoot(ctx) {
  return ctx
    ? ctx.closest(".ccm-sugg-slide")
    : document.querySelector("#ccm-slide .ccm-sugg-slide");
}

function _ccmRenderSugg(item, isPreview) {
  var users = (item.data || []).filter(Boolean);
  if (users.length === 0) return "";

  var pageCount = Math.max(1, Math.ceil(users.length / 4));
  users.forEach(function (u) {
    try {
      var im = new Image();
      im.decoding = "async";
      im.src = u.profile_pic || "mee.jpg";
    } catch (e) {}
  }); /* warm the pictures so nothing pops in */

  function pageUsers(pg) {
    return users.slice(pg * 4, pg * 4 + 4);
  }
  var pagesHtml = "";
  for (var pg = 0; pg < pageCount; pg++) {
    var slice = pageUsers(pg);
    var cardsHtml = slice
      .map(function (u) {
        var following = parseInt(u.is_following) > 0;
        var metaHtml = "";
        if (u.mutual_count) {
          metaHtml =
            '<span class="ccm-sugg-card-meta">' +
            u.mutual_count +
            " mutual</span>";
        } else if (u.reason) {
          metaHtml =
            '<span class="ccm-sugg-card-meta">' +
            _ccmEscapeHtml(u.reason) +
            "</span>";
        }
        return (
          '<div class="ccm-sugg-card" data-user="' +
          u.id +
          '">' +
          '<button type="button" class="ccm-sugg-close" onclick="event.stopPropagation();ccmSuggDismiss(this)"><i class="ri-close-line"></i></button>' +
          '<a class="ccm-sugg-card-link" href="profile.php?user_id=' +
          _ccmId(u.id) +
          '" onclick="event.stopPropagation()">' +
          '<span class="av-wrap" data-uid="' +
          _ccmId(u.id) +
          '">' +
          '<img src="' +
          _ccmSafeUrl(u.profile_pic, "mee.jpg") +
          '" onerror="this.src=\'mee.jpg\'">' +
          (parseInt(u.is_online) > 0 ? '<i class="on-dot"></i>' : "") +
          "</span>" +
          '<span class="ccm-sugg-card-name"><span class="ccm-sugg-card-name-t">' +
          _ccmEscapeHtml(u.username) +
          "</span>" +
          (parseInt(u.is_verified) > 0
            ? '<svg class="ccm-vbadge ccm-vbadge-sugg" viewBox="0 0 256 256" aria-label="Verified"><use href="#ccm-vflower"/></svg>'
            : "") +
          "</span>" +
          metaHtml +
          "</a>" +
          '<button class="ccm-sugg-card-track ' +
          (following ? "tracking" : "") +
          '" data-user="' +
          u.id +
          '" ' +
          'onclick="event.stopPropagation();toggleSuggTrack(this)">' +
          (following ? "Untrack" : "Track") +
          "</button>" +
          "</div>"
        );
      })
      .join("");
    pagesHtml +=
      '<div class="ccm-sugg-page" data-page="' +
      pg +
      '"><div class="ccm-sugg-grid2">' +
      cardsHtml +
      "</div></div>";
  }

  var dotsHtml = "";
  if (pageCount > 1) {
    for (var d = 0; d < pageCount; d++) {
      dotsHtml +=
        '<span class="ccm-sugg-dot' +
        (d === 0 ? " active" : "") +
        '" onclick="event.stopPropagation();ccmSuggGoToPage(this,' +
        d +
        ')"></span>';
    }
  }
  var arrowsHtml =
    pageCount > 1
      ? '<div class="ccm-sugg-arrow ccm-sugg-arrow-left ccm-sugg-arrow-disabled" role="button" aria-label="Previous" onpointerdown="event.stopPropagation()" onmousedown="event.stopPropagation()" ontouchstart="event.stopPropagation()" onclick="event.stopPropagation();event.preventDefault();ccmSuggPrev(this)"><i class="ri-arrow-left-s-line"></i></div>' +
        '<div class="ccm-sugg-arrow ccm-sugg-arrow-right" role="button" aria-label="Next" onpointerdown="event.stopPropagation()" onmousedown="event.stopPropagation()" ontouchstart="event.stopPropagation()" onclick="event.stopPropagation();event.preventDefault();ccmSuggNext(this)"><i class="ri-arrow-right-s-line"></i></div>'
      : "";

  return (
    '<div class="ccm-sugg-slide">' +
    '<div class="ccm-sugg-bg"><canvas class="ccm-sugg-bgc" width="24" height="48"></canvas></div>' +
    '<div class="ccm-sugg-overlay"></div>' +
    '<div class="ccm-sugg-header">' +
    '<div class="ccm-sugg-header-text">' +
    '<span class="ccm-sugg-title">Suggested for you</span>' +
    '<span class="ccm-sugg-subtitle">Accounts you might like</span>' +
    "</div>" +
    '<span class="ccm-sugg-header-spacer"></span>' +
    "</div>" +
    '<div class="ccm-sugg-pages" data-page-count="' +
    pageCount +
    '" data-current="0">' +
    pagesHtml +
    "</div>" +
    arrowsHtml +
    (pageCount > 1 ? '<div class="ccm-sugg-dots">' + dotsHtml + "</div>" : "") +
    "</div>"
  );
}

/* The blurred backdrop: the 4 profile pictures of the current page drawn TINY on a canvas and stretched up by the browser,
   which is a soft blur with no CSS filter at all (the old filter:blur layer flashed every time the slide slid back in). */
window._ccmSuggImgs = window._ccmSuggImgs || {};
function _ccmSuggPaintBg(root, pg) {
  var cv =
    root && root.querySelector ? root.querySelector(".ccm-sugg-bgc") : null;
  if (!cv || !cv.getContext) return;
  var pageEl = root.querySelector('.ccm-sugg-page[data-page="' + pg + '"]');
  var imgs = pageEl ? pageEl.querySelectorAll(".ccm-sugg-card img") : [];
  var srcs = [];
  for (var k = 0; k < 4; k++) {
    var im = imgs.length ? imgs[k % imgs.length] : null;
    srcs.push(im ? im.getAttribute("src") : "");
  }
  var W = cv.width,
    H = cv.height,
    tw = W / 2,
    th = H / 2;
  function draw() {
    if (cv._ccmPg !== pg + ":" + srcs.join("|")) return;
    var cx = cv.getContext("2d");
    if (!cx) return;
    cx.fillStyle = "#141418";
    cx.fillRect(0, 0, W, H);
    srcs.forEach(function (src, t) {
      var img = window._ccmSuggImgs[src];
      if (!img || !img.naturalWidth) return;
      var x = (t % 2) * tw,
        y = Math.floor(t / 2) * th;
      var sc = Math.max(tw / img.naturalWidth, th / img.naturalHeight),
        w = img.naturalWidth * sc,
        h = img.naturalHeight * sc;
      try {
        cx.save();
        cx.beginPath();
        cx.rect(x, y, tw, th);
        cx.clip();
        cx.drawImage(img, x + (tw - w) / 2, y + (th - h) / 2, w, h);
        cx.restore();
      } catch (e) {
        try {
          cx.restore();
        } catch (e2) {}
      }
    });
    cx.fillStyle = "rgba(0,0,0,.52)";
    cx.fillRect(0, 0, W, H); /* darker, like the old brightness(.42) */
  }
  cv._ccmPg = pg + ":" + srcs.join("|");
  srcs.forEach(function (src) {
    if (!src) return;
    var im = window._ccmSuggImgs[src];
    if (im && im.complete) {
      return;
    }
    if (!im) {
      im = new Image();
      window._ccmSuggImgs[src] = im;
      im.src = src;
    }
    im.addEventListener("load", draw);
  });
  draw();
}
function ccmSuggGoToPage(ctx, idx) {
  var root = _ccmSuggRoot(ctx);
  if (!root) return;
  var pagesEl = root.querySelector(".ccm-sugg-pages");
  var dotsEl = root.querySelector(".ccm-sugg-dots");
  if (!pagesEl) return;
  var pageCount = parseInt(pagesEl.dataset.pageCount || "1", 10);
  if (idx < 0) idx = 0;
  if (idx > pageCount - 1) idx = pageCount - 1;

  pagesEl.style.transition = "transform .32s cubic-bezier(.22,1,.36,1)";
  pagesEl.style.transform = "translateX(-" + idx * 100 + "%)";
  pagesEl.dataset.current = idx;

  if (dotsEl) {
    dotsEl.querySelectorAll(".ccm-sugg-dot").forEach(function (dot, i) {
      dot.classList.toggle("active", i === idx);
    });
  }

  _ccmSuggPaintBg(root, idx);

  var leftArrow = root.querySelector(".ccm-sugg-arrow-left");
  var rightArrow = root.querySelector(".ccm-sugg-arrow-right");
  if (leftArrow)
    leftArrow.classList.toggle("ccm-sugg-arrow-disabled", idx === 0);
  if (rightArrow)
    rightArrow.classList.toggle(
      "ccm-sugg-arrow-disabled",
      idx === pageCount - 1,
    );
}

function ccmSuggNext(ctx) {
  var root = _ccmSuggRoot(ctx);
  var pagesEl = root ? root.querySelector(".ccm-sugg-pages") : null;
  if (!pagesEl) return;
  ccmSuggGoToPage(ctx, parseInt(pagesEl.dataset.current || "0", 10) + 1);
}
function ccmSuggPrev(ctx) {
  var root = _ccmSuggRoot(ctx);
  var pagesEl = root ? root.querySelector(".ccm-sugg-pages") : null;
  if (!pagesEl) return;
  ccmSuggGoToPage(ctx, parseInt(pagesEl.dataset.current || "0", 10) - 1);
}

function _ccmInitSuggSwipe(root) {
  if (!root) return;
  var pagesEl = root.querySelector(".ccm-sugg-pages");
  if (!pagesEl || pagesEl._ccmSwipeBound) return;
  var pageCount = parseInt(pagesEl.dataset.pageCount || "1", 10);
  if (pageCount <= 1) return;
  pagesEl._ccmSwipeBound = true;

  var startX = 0,
    startY = 0,
    dragging = false,
    horizontal = false;

  pagesEl.addEventListener(
    "touchstart",
    function (e) {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      dragging = true;
      horizontal = false;
      pagesEl.style.transition = "none";
    },
    { passive: true },
  );

  pagesEl.addEventListener(
    "touchmove",
    function (e) {
      if (!dragging) return;
      var current = parseInt(pagesEl.dataset.current || "0", 10);
      var dx = e.touches[0].clientX - startX;
      var dy = e.touches[0].clientY - startY;
      if (!horizontal && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8)
        horizontal = true;
      if (horizontal) {
        e.stopPropagation();
        pagesEl.style.transform =
          "translateX(calc(-" + current * 100 + "% + " + dx + "px))";
      }
    },
    { passive: true },
  );

  pagesEl.addEventListener(
    "touchend",
    function (e) {
      if (!dragging) return;
      dragging = false;
      var current = parseInt(pagesEl.dataset.current || "0", 10);
      if (horizontal) {
        e.stopPropagation();
        var dx = e.changedTouches[0].clientX - startX;
        if (dx > 70 && current === 0) {
          pagesEl.style.transition = "";
          pagesEl.style.transform = "translateX(0%)";
          closeCardCaptionModal();
          return;
        } /* first page, swiped right → close, like a post */
        if (Math.abs(dx) > 50) {
          if (dx < 0 && current < pageCount - 1) current++;
          else if (dx > 0 && current > 0) current--;
        }
      }
      ccmSuggGoToPage(pagesEl, current);
    },
    { passive: true },
  );
}

function ccmSuggDismiss(btn) {
  var card = btn.closest(".ccm-sugg-card");
  if (card) card.classList.add("dismissed");
}

function toggleSuggTrack(btn) {
  var userId = btn.dataset.user;
  fetch(CCM_CFG.followEndpoint || _ccmEp(), {
    method: "POST",
    credentials: "same-origin",
    headers: _ccmCsrfH({ "Content-Type": "application/x-www-form-urlencoded" }),
    body:
      "action=toggle_follow&user_id=" +
      encodeURIComponent(userId) +
      _ccmCsrfQ(),
  })
    .then(function (r) {
      return r.json();
    })
    .then(function (data) {
      if (data.status === "ok") {
        if (data.following) {
          btn.textContent = "Untrack";
          btn.classList.add("tracking");
        } else {
          btn.textContent = "Track";
          btn.classList.remove("tracking");
        }
      }
    });
}

/* ══════════════════════════════════════════════════════════
   GAPLESS VERTICAL TRACK ENGINE
══════════════════════════════════════════════════════════ */
function _ccmSlideItem(idx) {
  if (idx < 0 || idx >= ccmFeed.length) return null;
  return ccmFeed[idx];
}

function _ccmRenderItemHtml(item, isPreview) {
  if (!item) return "";
  if (item.type === "post") return _ccmRenderPost(item.data, isPreview);
  if (item.type === "sugg") return _ccmRenderSugg(item, isPreview);
  return "";
}

/* a caption that runs onto a 2nd line pushes the avatars / pills up by one line. Measured as soon as the slide is in the page (a microtask, i.e. BEFORE the first paint), so there is no jump after the post arrives */
function ccmCapLift(slide) {
  if (!slide || !slide.querySelector) return;
  var t = slide.querySelector(".ccm-caption-text");
  if (!t) {
    slide.style.removeProperty("--ccm-cap-lift");
    return;
  }
  var rects = t.getClientRects();
  if (!rects.length) return;
  var tops = {},
    n = 0;
  for (var i = 0; i < rects.length; i++) {
    var k = Math.round(rects[i].top / 3);
    if (!tops[k]) {
      tops[k] = 1;
      n++;
    }
  }
  var cs = getComputedStyle(t),
    lh = parseFloat(cs.lineHeight);
  if (!lh || isNaN(lh)) lh = (parseFloat(cs.fontSize) || 12) * 1.5;
  var extra = Math.max(0, Math.min(2, n - 1)) * lh;
  if (extra) slide.style.setProperty("--ccm-cap-lift", extra.toFixed(1) + "px");
  else slide.style.removeProperty("--ccm-cap-lift");
}
(function () {
  function all() {
    document
      .querySelectorAll("#cardCaptionModal .ccm-slide")
      .forEach(ccmCapLift);
  }
  function start() {
    var host = document.getElementById("cardCaptionModal");
    if (!host || !window.MutationObserver) return;
    new MutationObserver(function (muts) {
      muts.forEach(function (mu) {
        mu.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          if (n.classList.contains("ccm-slide")) ccmCapLift(n);
          else if (n.querySelectorAll)
            n.querySelectorAll(".ccm-slide").forEach(ccmCapLift);
        });
      });
    }).observe(host, { childList: true, subtree: true });
    window.addEventListener("resize", function () {
      requestAnimationFrame(all);
    });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(all);
  }
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", start);
  else start();
})();
function _ccmBuildSlideWrap(item, pos) {
  var wrap = document.createElement("div");
  wrap.className = "ccm-vslide";
  wrap.dataset.pos = pos;
  if (item) {
    var slideEl = document.createElement("div");
    slideEl.className = "ccm-slide";
    if (pos === "current") slideEl.id = "ccm-slide";
    slideEl.innerHTML = _ccmRenderItemHtml(item, pos !== "current");
    wrap.appendChild(slideEl);
    wrap.querySelectorAll(".ccm-video-progress").forEach(ccmInitProgressScrub);
    wrap.querySelectorAll(".ccm-sugg-slide").forEach(function (r) {
      _ccmSuggPaintBg(r, 0);
    });
  }
  return wrap;
}

/* Fills the fixed top bar (right side) for whichever item is current: "Advertisement" for ads, "Zeems" for posts.
   The date is no longer shown here — it appears under the caption when tapped.
   Hidden on suggested-user slides (they have their own header). */
var CCM_HEART_SVG =
  '<svg viewBox="0 0 24 24" aria-hidden="true"><path class="sp-ring" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" stroke-width="3.67" stroke-linejoin="round"/><path class="sp-fill" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>';
/* The fixed back arrow + "Zeems" label fade out while you swipe TOWARD a suggested-users slide (gone before it arrives) and fade back in when you leave it. */
function _ccmChromeEls() {
  return [
    document.getElementById("ccm-fixed-topbar"),
    document.getElementById("ccmFixedBackBtn"),
    document.getElementById("ccmPageMenuBtn"),
  ];
}
function _ccmChromeSet(v, anim) {
  _ccmChromeEls().forEach(function (el) {
    if (!el) return;
    el.style.transition = anim ? "opacity .22s ease" : "none";
    el.style.visibility = "visible";
    el.style.opacity = String(v);
  });
}
function _ccmChromeClear() {
  _ccmChromeEls().forEach(function (el) {
    if (el) {
      el.style.opacity = "";
      el.style.visibility = "";
      el.style.transition = "";
    }
  });
}
function _ccmIsSuggAt(i) {
  var it = ccmFeed[i];
  return !!(it && it.type === "sugg");
}
/* 0..1 visibility while dragging by `progress` (0..1) toward the neighbour in direction nd (+1 next / -1 prev) */
function _ccmChromeDrag(nd, progress) {
  var curS = _ccmIsSuggAt(_ccmIndex),
    nbS = _ccmIsSuggAt(_ccmIndex + nd);
  if (curS === nbS) return; /* post → post or sugg → sugg: nothing changes */
  _ccmChromeSet(nbS ? 1 - progress : progress, false);
}
function _ccmUpdateTopBar(item) {
  _ccmChromeClear();
  var bar = document.getElementById("ccm-fixed-topbar");
  var lab = document.getElementById("ccm-topbar-label");
  if (!bar || !lab) return;
  var _m = document.getElementById("cardCaptionModal");
  if (_m) _m.classList.toggle("ccm-on-sugg", !!(item && item.type === "sugg"));
  if (!item || item.type !== "post") {
    bar.classList.add("ccm-topbar-hidden");
    return;
  }
  bar.classList.remove("ccm-topbar-hidden");
  var p = item.data;
  lab.innerHTML = '<span class="ccm-brand-label">' + "Zeems" + "</span>";
  if (typeof window._ccmWarmProfile === "function")
    window._ccmWarmProfile(); /* get this poster's profile ready for the swipe */
  /* profile page ONLY (modal opened by id): a post's p.context (Reposted / Saved / Tagged) replaces the word.
       Home, text and search open the old way, so they always keep "Zeems" even if their posts carry a context value. */
  bar.classList.remove("ccm-ctx-mode");
  if (_ccmUseMenuSheet && p.context && !p.isAd) {
    bar.classList.add("ccm-ctx-mode");
    lab.innerHTML =
      '<span class="ccm-brand-label">' + _ccmEscapeHtml(p.context) + "</span>";
  }
  var _bl = lab.firstChild;
  if (_bl && _bl.style) {
    if (_ccmBrandShift !== null)
      _bl.style.transform = "translateY(" + _ccmBrandShift.toFixed(2) + "px)";
    else {
      _bl.style.opacity = "0";
      setTimeout(function () {
        if (_bl && _bl.style) _bl.style.opacity = "";
      }, 900);
    } /* first time ever: hidden until it is lined up, so it never visibly moves */
  } /* no jump: the right offset is there from the first frame */
  _ccmAlignBrandSoon();
}
var _ccmBrandKey =
  "dz_bshift_" + window.innerWidth + "x" + (window.devicePixelRatio || 1);
var _ccmBrandShift = (function () {
  try {
    var v = parseFloat(localStorage.getItem(_ccmBrandKey));
    return isFinite(v) ? v : null;
  } catch (e) {
    return null;
  }
})(); /* remembered from the last time: the word is in the right place from the very first frame */

/* Puts the top word ("Zeems") on exactly the same line as the back arrow (or the "+" on zeems.php): the middle of its CAPITAL LETTERS is lined up with the middle of the icon.
   Measured from the real font, so it holds on every phone / laptop size and whatever the font's own spacing is. */
function _ccmAlignBrand() {
  try {
    var lab = document.querySelector("#ccm-topbar-label .ccm-brand-label");
    if (!lab) return;
    var lr = lab.getBoundingClientRect();
    if (!lr.height) return;
    var cur =
      parseFloat(
        (lab.style.transform.match(/translateY\(([-\d.]+)px\)/) || [0, 0])[1],
      ) || 0; /* the offset it already has (measurements below include it) */
    var ref = null,
      cands = [
        document.querySelector("#ccmFixedBackBtn .ccm-back-svg"),
        document.querySelector("#ccmPageMenuBtn .ccm-eq-svg"),
      ];
    for (var i = 0; i < cands.length; i++) {
      if (cands[i]) {
        var rr = cands[i].getBoundingClientRect();
        if (rr.height > 1 && rr.width > 1) {
          ref = rr;
          break;
        }
      }
    }
    if (!ref) return;
    var probe = lab.querySelector(".ccm-baseline-probe");
    if (!probe) {
      probe = document.createElement("i");
      probe.className = "ccm-baseline-probe";
      probe.style.cssText =
        "display:inline-block;width:0;height:0;vertical-align:baseline;pointer-events:none";
      lab.appendChild(probe);
    }
    var baseY = probe.getBoundingClientRect().bottom;
    var cs = getComputedStyle(lab),
      fs = parseFloat(cs.fontSize) || 24,
      k = _ccmZoomK(lab) || 1;
    var asc = fs * 0.7,
      desc = 0; /* fallback: capital height 0.7em, no descender */
    try {
      var cv =
          _ccmAlignBrand._cv ||
          (_ccmAlignBrand._cv = document.createElement("canvas")),
        cx = cv.getContext("2d");
      cx.font = cs.fontWeight + " " + cs.fontSize + " " + cs.fontFamily;
      var m = cx.measureText((lab.textContent || "Zeems").toUpperCase());
      if (m && m.actualBoundingBoxAscent > 0) {
        asc = m.actualBoundingBoxAscent;
        desc = m.actualBoundingBoxDescent || 0;
      }
    } catch (e) {}
    var inkCenter =
      baseY - (asc - desc) / 2; /* middle of the letters on screen */
    var refCenter = ref.top + ref.height / 2;
    var delta = (refCenter - inkCenter) / k;
    var shift = cur + delta;
    if (
      isFinite(shift) &&
      Math.abs(shift) < 40 &&
      (Math.abs(delta) > 0.4 || _ccmBrandShift === null)
    ) {
      _ccmBrandShift = shift;
      try {
        localStorage.setItem(_ccmBrandKey, String(shift));
      } catch (e) {}
      lab.style.transform = "translateY(" + shift.toFixed(2) + "px)";
    }
  } catch (e) {}
}
function _ccmAlignBrandSoon() {
  clearTimeout(_ccmAlignBrandSoon._t);
  _ccmAlignBrandSoon._t = setTimeout(
    function () {
      if (!(typeof _ccmVSwipeLocked !== "undefined" && _ccmVSwipeLocked))
        _ccmAlignBrand();
      var _l = document.querySelector("#ccm-topbar-label .ccm-brand-label");
      if (_l) _l.style.opacity = "";
    },
    _ccmBrandShift === null ? 150 : 420,
  ); /* once things have settled, never mid-swipe */
}
window.addEventListener("resize", function () {
  _ccmAlignBrandSoon();
});
try {
  if (document.fonts) {
    document.fonts.addEventListener("loadingdone", _ccmAlignBrandSoon);
    if (document.fonts.load)
      document.fonts
        .load('24px "Bebas Neue"')
        .then(_ccmAlignBrandSoon)
        .catch(function () {});
  }
} catch (e) {}

function _ccmActivateCurrentSlide(currentItem) {
  ccmHideSavedToast();
  _ccmUpdateTopBar(currentItem);
  if (currentItem && currentItem.type === "post" && currentItem.data)
    ccmLoadCommentCount(currentItem.data.id);

  if (currentItem && currentItem.type === "post") {
    var capState = _ccmComputeCaptionHtml(currentItem.data);
    _ccmCaptionFullHtml = capState.full;
    _ccmCaptionTruncHtml = capState.trunc;
    _ccmCaptionDateHtml = capState.date || "";
  }
  _ccmCaptionExpanded = false;

  var newMemeId =
    currentItem && currentItem.type === "post" ? currentItem.data.id : null;
  var shouldStayPaused = newMemeId !== null && !!_ccmPausedMemeIds[newMemeId];

  var curSlide = document.getElementById("ccm-slide");

  if (curSlide) {
    if (currentItem.type === "post") {
      _ccmInitMediaSwipe(curSlide);
      _ccmInitAudioMarquee(curSlide);
      _ccmInitInfoSwitcher(curSlide);
      ccmUpdateMediaGallery(curSlide, shouldStayPaused);
      _ccmInitCtaPillCycle(curSlide);
    } else {
      if (_ccmInfoSwitchTimer) {
        clearInterval(_ccmInfoSwitchTimer);
        _ccmInfoSwitchTimer = null;
      }
      if (_ccmCtaPillTimer) {
        clearInterval(_ccmCtaPillTimer);
        _ccmCtaPillTimer = null;
      }
    }

    if (currentItem.type === "sugg") {
      _ccmInitSuggSwipe(curSlide.querySelector(".ccm-sugg-slide"));
    }

    var mediaImg = curSlide.querySelector("img.ccm-media");
    var textBg = curSlide.querySelector(".ccm-textonly-bg");
    var activeVid =
      curSlide.querySelector(
        '.ccm-media-item[data-idx="' + _ccmMediaIndex + '"] video.ccm-media',
      ) || curSlide.querySelector("video.ccm-media");

    if (currentItem.type === "sugg" || _ccmLastPosterUsed) {
      ccmHideSkeleton(curSlide);
    } else if (textBg) {
      ccmHideSkeleton(curSlide);
      requestAnimationFrame(function () {
        _ccmCheckCaptionOverflow(curSlide);
      });
    } else if (mediaImg && mediaImg.complete) {
      ccmHideSkeleton(curSlide);
    } else if (activeVid && activeVid.readyState >= 2) {
      ccmHideSkeleton(curSlide);
    }
    setTimeout(function () {
      ccmHideSkeleton(curSlide);
    }, 2500);
    /* safety net for a skeleton that never clears (some phones never fire loadeddata before play) */
    (function (sl) {
      setTimeout(function () {
        try {
          ccmHideSkeleton(sl);
        } catch (e) {}
      }, 5000);
    })(curSlide);
  } else {
    if (_ccmInfoSwitchTimer) {
      clearInterval(_ccmInfoSwitchTimer);
      _ccmInfoSwitchTimer = null;
    }
    if (_ccmCtaPillTimer) {
      clearInterval(_ccmCtaPillTimer);
      _ccmCtaPillTimer = null;
    }
  }

  _ccmSyncSoundtrack(currentItem);

  /* warm up the next posts, but only once the video on screen can play (so the two downloads never fight each other) */
  (function (at, av) {
    function go() {
      if (_ccmIndex !== at) return; /* the person already swiped on */
      ccmWarmUp(
        ccmFeed
          .slice(at + 1, at + 3)
          .filter(function (s) {
            return s && s.type === "post";
          })
          .map(function (s) {
            return s.data;
          }),
        2,
      );
    }
    if (av && av.tagName === "VIDEO" && av.readyState < 3) {
      var fired = false,
        once = function () {
          if (fired) return;
          fired = true;
          go();
        };
      av.addEventListener("canplay", once, { once: true });
      setTimeout(once, 2500);
    } else go();
  })(_ccmIndex, activeVid);

  if (
    CCM_CFG.onSlide &&
    curSlide &&
    currentItem &&
    currentItem.type === "post"
  ) {
    try {
      CCM_CFG.onSlide(curSlide, currentItem.data);
    } catch (e) {}
  }

  _ccmMemeId = newMemeId;
  try {
    if (window.CommentSection && window.CommentSection.prefetch && newMemeId) {
      window.CommentSection.prefetch(newMemeId);
      var _nx = ccmFeed[_ccmIndex + 1];
      if (_nx && _nx.type === "post" && _nx.data)
        window.CommentSection.prefetch(_nx.data.id);
    }
  } catch (e) {} /* the comments are fetched in the background as soon as a post is on screen, so opening them is instant */
  try {
    _ccmOwnShown(newMemeId);
  } catch (e) {}
  try {
    _ccmNiOnSlide(newMemeId);
  } catch (e) {}
  try {
    ccmCapLift(curSlide);
  } catch (e) {}
  try {
    _ccmAskOnSlide(currentItem);
  } catch (e) {}
}

/* ══════════════════════════════════════════════════════════
   WARM-UP — get the next posts ready BEFORE they are opened, without wasting data
   • the very next video downloads fully (muted, off screen) and its first frame is saved as a poster → it opens instantly
   • the one after that only fetches its first ~½ MB (range request, same-origin), enough for the browser to start it fast
   • nothing is warmed on Data Saver or a 2G connection; at most 3 videos are kept; everything is released when the modal closes
══════════════════════════════════════════════════════════ */
var _ccmWarm =
  {}; /* url -> { el, ctrl } so the browser keeps its cache entry and we can stop it later */
var _ccmPosterCache = {}; /* video url -> small JPEG of its first frame */
var CCM_WARM_MAX_VIDEOS = 3,
  CCM_WARM_RANGE_BYTES = 524288;

function _ccmNet() {
  var c =
    navigator.connection ||
    navigator.mozConnection ||
    navigator.webkitConnection;
  if (!c) return { ok: true, full: true };
  if (c.saveData) return { ok: false, full: false };
  var t = c.effectiveType || "";
  if (t === "slow-2g" || t === "2g") return { ok: false, full: false };
  return { ok: true, full: t !== "3g" };
}
function _ccmSameOrigin(u) {
  try {
    return new URL(u, location.href).origin === location.origin;
  } catch (e) {
    return false;
  }
}

/* keep a small JPEG of the video's first frame, so the next time this video opens it shows a picture instead of the skeleton */
function _ccmCapturePoster(v, url) {
  try {
    if (_ccmPosterCache[url] || !v.videoWidth) return;
    var w = Math.min(360, v.videoWidth),
      h = Math.round((w * v.videoHeight) / v.videoWidth);
    var c = document.createElement("canvas");
    c.width = w;
    c.height = h;
    c.getContext("2d").drawImage(v, 0, 0, w, h);
    _ccmPosterCache[url] = c.toDataURL("image/jpeg", 0.6);
  } catch (e) {
    /* cross-origin video without CORS: no poster, nothing breaks */
  }
}

function _ccmReleaseWarm(url) {
  var e = _ccmWarm[url];
  if (!e) return;
  if (e.ctrl) {
    try {
      e.ctrl.abort();
    } catch (_) {}
  }
  var o = e.el;
  if (o && o.tagName === "VIDEO") {
    try {
      o.pause();
    } catch (_) {}
    o.removeAttribute("src");
    try {
      o.load();
    } catch (_) {}
  }
  delete _ccmWarm[url];
}
function ccmWarmCancel() {
  Object.keys(_ccmWarm).forEach(_ccmReleaseWarm);
}

function ccmWarmUp(posts, count) {
  if (!Array.isArray(posts)) return;
  var net = _ccmNet();
  if (!net.ok) return;
  count = count || 2;
  var started = 0,
    fullDone = false;
  for (var i = 0; i < posts.length && started < count; i++) {
    var p = posts[i];
    if (!p) continue;
    var media =
      Array.isArray(p.media) && p.media.length
        ? p.media
        : [{ file: p.file, type: p.type }];
    var m = media[0];
    if (!m || !m.file) continue;
    var t = (m.type || "").toLowerCase();
    var poster =
      m.poster || m.cover || p.poster || p.cover
        ? String(m.poster || m.cover || p.poster || p.cover)
        : "";
    if (poster && !_ccmWarm[poster]) {
      var im = new Image();
      im.src = poster;
      _ccmWarm[poster] = { el: im };
    }
    if (t === "video") {
      if (_ccmWarm[m.file]) {
        started++;
        continue;
      }
      if (!fullDone && net.full) {
        /* the next video: full muted download + first-frame poster */
        fullDone = true;
        var v = document.createElement("video");
        v.preload = "auto";
        v.muted = true;
        v.playsInline = true;
        v.src = m.file;
        (function (v, url) {
          v.addEventListener(
            "loadeddata",
            function () {
              if (_ccmPosterCache[url]) return;
              var d = v.duration;
              if (d && isFinite(d) && d > 0.4) {
                v.addEventListener(
                  "seeked",
                  function () {
                    _ccmCapturePoster(v, url);
                  },
                  { once: true },
                );
                try {
                  v.currentTime = Math.min(0.15, d / 2);
                } catch (e) {
                  _ccmCapturePoster(v, url);
                }
              } else _ccmCapturePoster(v, url);
            },
            { once: true },
          );
        })(v, m.file);
        _ccmWarm[m.file] = { el: v };
      } else if (
        _ccmSameOrigin(m.file) &&
        window.fetch &&
        window.AbortController
      ) {
        /* the one after: just its first chunk, and stop at once if the server does not do ranges (would be the whole file) */
        var ctrl = new AbortController();
        _ccmWarm[m.file] = { el: null, ctrl: ctrl };
        (function (url, ctrl) {
          fetch(url, {
            headers: { Range: "bytes=0-" + (CCM_WARM_RANGE_BYTES - 1) },
            signal: ctrl.signal,
            credentials: "same-origin",
          })
            .then(function (r) {
              if (r.status !== 206) {
                ctrl.abort();
                return null;
              }
              return r.arrayBuffer();
            })
            .catch(function () {});
        })(m.file, ctrl);
      } else {
        continue;
      }
      started++;
    } else if (t === "image" || t === "gif") {
      if (!_ccmWarm[m.file]) {
        var im2 = new Image();
        im2.src = m.file;
        _ccmWarm[m.file] = { el: im2 };
      }
      started++;
    }
  }
  /* keep it small: at most CCM_WARM_MAX_VIDEOS video downloads alive; older ones are stopped */
  var vids = Object.keys(_ccmWarm).filter(function (k) {
    var e = _ccmWarm[k];
    return e && ((e.el && e.el.tagName === "VIDEO") || e.ctrl);
  });
  while (vids.length > CCM_WARM_MAX_VIDEOS) {
    _ccmReleaseWarm(vids.shift());
  }
  var rest = Object.keys(_ccmWarm).filter(function (k) {
    var e = _ccmWarm[k];
    return e && e.el && e.el.tagName === "IMG";
  });
  while (rest.length > 8) {
    delete _ccmWarm[rest.shift()];
  }
}
window.ccmWarmUp = ccmWarmUp;
window.ccmWarmCancel = ccmWarmCancel;

function _ccmMountTrack(centerIndex) {
  _ccmVSwipeLocked = false;

  var existingHstack = document.getElementById("ccm-hstack");
  if (existingHstack) {
    existingHstack.querySelectorAll("video").forEach(function (v) {
      v.pause();
      v.src = "";
    });
    existingHstack.innerHTML = "";
    existingHstack.style.pointerEvents = "none";
  }
  _ccmHStack = [];

  var inner = document.getElementById("ccm-inner");
  inner.querySelectorAll("video").forEach(function (v) {
    v.pause();
    v.src = "";
    v.load();
  });

  var track = document.createElement("div");
  track.className = "ccm-vtrack";
  track.id = "ccm-vtrack";
  track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(centerIndex - 1), "prev"));
  track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(centerIndex), "current"));
  track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(centerIndex + 1), "next"));

  inner.innerHTML = "";
  inner.appendChild(track);
  _ccmEnsureHStackEl();

  _ccmIndex = centerIndex;
  _ccmActivateCurrentSlide(_ccmSlideItem(centerIndex));
}

function _ccmResetAllOverlayStyles(track) {
  if (!track) return;
  track.querySelectorAll(".ccm-vslide .ccm-slide").forEach(function (slideEl) {
    _ccmOverlayEls(slideEl).forEach(function (el) {
      el.style.transition = "";
      el.style.opacity = "";
      el.style.transform = "";
    });
  });
}

var CCM_OVERLAY_SELECTOR =
  ".ccm-group-tag, .ccm-top-right-tags, .ccm-social-proof-strip, .ccm-cta-pill, .ccm-connected-tag, .ccm-rail, .ccm-bottom, .ccm-sugg-header, .ccm-sugg-dots, .ccm-sugg-arrow";

function _ccmOverlayEls(slideEl) {
  return slideEl ? slideEl.querySelectorAll(CCM_OVERLAY_SELECTOR) : [];
}

function _ccmSetOverlayProgress(slideEl, progress, dir) {
  if (!slideEl) return;
  var opacity = 1 - progress * 0.7;
  var translate = (dir || 0) * progress * 16;
  _ccmOverlayEls(slideEl).forEach(function (el) {
    el.style.opacity = opacity;
    el.style.transform = "translateY(" + translate + "px)";
  });
}

var _ccmVDragBound = false;
function _ccmInitVerticalDrag() {
  if (_ccmVDragBound) return;
  _ccmVDragBound = true;
  var stage = document.getElementById("ccm-stage");
  if (!stage) return;

  var startX = 0,
    startY = 0,
    dragging = false,
    committedVertical = false,
    decided = false,
    stageH = 0;
  var multiPhoto = false; /* this touch landed on a post with more than one photo/video */
  var _vs = []; /* recent finger samples {t,y} → flick speed */
  function _flickV() {
    /* px per ms, signed (negative = finger moving up); measured over the last ~110ms */
    var n = _vs.length;
    if (n < 2) return 0;
    var last = _vs[n - 1],
      first = last;
    for (var i = n - 2; i >= 0; i--) {
      if (last.t - _vs[i].t > 110) break;
      first = _vs[i];
    }
    var dt = last.t - first.t;
    return dt > 8 ? (last.y - first.y) / dt : 0;
  }
  /* iOS-style rubber band: soft at first, then it stiffens and never runs past ~28% of the screen */
  function _rubber(d) {
    var lim = stageH * 0.28,
      a = Math.abs(d);
    return (d < 0 ? -1 : 1) * lim * (1 - 1 / ((0.55 * a) / lim + 1));
  }

  /* FIX 1 — used by the photo swipe (and swipe-to-close) the moment they take a drag over:
       stop this vertical drag and tell the caller whether it had already committed to vertical */
  window._ccmVDragState = function () {
    var wasCommitted = committedVertical;
    dragging = false;
    committedVertical = false;
    decided = true;
    return wasCommitted;
  };

  stage.addEventListener(
    "touchstart",
    function (e) {
      if (e.touches.length !== 1) return;
      if (_ccmVSwipeLocked) return;
      /* vertical feed navigation is locked while a Connected-Zeem layer is open */
      if (_ccmHStack.length > 0) return;
      if (_ccmIsInsideExpandedCaption(e.target)) return;
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      dragging = true;
      committedVertical = false;
      decided = false;
      _vs = [{ t: e.timeStamp || Date.now(), y: startY }];
      var _mb = e.target.closest ? e.target.closest("#ccm-media-box") : null;
      multiPhoto = !!_mb && parseInt(_mb.dataset.count || "1", 10) > 1;
      stageH = stage.clientHeight;
      var track = document.getElementById("ccm-vtrack");
      if (track) {
        track.style.transition = "none";
        track
          .querySelectorAll(".ccm-vslide .ccm-slide")
          .forEach(function (slideEl) {
            _ccmOverlayEls(slideEl).forEach(function (el) {
              el.style.transition = "none";
            });
          });
      }
    },
    { passive: true },
  );

  stage.addEventListener(
    "touchmove",
    function (e) {
      if (!dragging) return;
      var dx = e.touches[0].clientX - startX;
      var dy = e.touches[0].clientY - startY;
      _vs.push({ t: e.timeStamp || Date.now(), y: e.touches[0].clientY });
      if (_vs.length > 12) _vs.shift();

      if (!decided) {
        var _ratio = multiPhoto ? 1.7 : 1;
        if (Math.abs(dy) > _ratio * Math.abs(dx) && Math.abs(dy) > 10) {
          committedVertical = true;
          decided = true;
        } else if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) {
          dragging = false;
          decided = true;
          return;
        }
      }
      if (committedVertical) {
        var track = document.getElementById("ccm-vtrack");
        if (track) {
          var atTop = _ccmIndex <= 0;
          var atBottom = _ccmIndex >= ccmFeed.length - 1;
          var clamped = dy;
          if (atTop && clamped > 0) clamped = _rubber(clamped);
          if (atBottom && clamped < 0) clamped = _rubber(clamped);
          clamped = Math.max(-stageH, Math.min(stageH, clamped));
          track.style.transform = "translateY(" + clamped + "px)";

          var progress = Math.min(1, Math.abs(clamped) / (stageH * 0.35));
          var dirOut = clamped < 0 ? -1 : clamped > 0 ? 1 : 0;
          var curSlideEl = track.querySelector(
            '.ccm-vslide[data-pos="current"] .ccm-slide',
          );
          _ccmSetOverlayProgress(curSlideEl, progress, dirOut);
          if (dirOut !== 0) _ccmChromeDrag(dirOut < 0 ? 1 : -1, progress);

          var incomingPos = clamped < 0 ? "next" : clamped > 0 ? "prev" : null;
          ["next", "prev"].forEach(function (pos) {
            var nSlideEl = track.querySelector(
              '.ccm-vslide[data-pos="' + pos + '"] .ccm-slide',
            );
            if (!nSlideEl) return;
            if (pos === incomingPos) {
              _ccmSetOverlayProgress(nSlideEl, 1 - progress, -dirOut);
            } else {
              _ccmSetOverlayProgress(nSlideEl, 1, dirOut);
            }
          });
        }
      }
    },
    { passive: true },
  );

  stage.addEventListener(
    "touchend",
    function (e) {
      if (!dragging) return;
      dragging = false;
      var track = document.getElementById("ccm-vtrack");
      if (!track || !committedVertical) {
        return;
      }

      var dy = e.changedTouches[0].clientY - startY;
      track.style.transition =
        "transform " + CCM_SLIDE_MS + "ms " + CCM_SLIDE_EASE;

      /* distance OR speed decides: a quick flick of even a short distance moves on; a slow drag needs ~18% of the screen */
      var threshold = stageH * 0.18,
        v = _flickV();
      var flick = Math.abs(v) > 0.45 && Math.abs(dy) > 24;
      var goNext =
        (dy < -threshold || (flick && v < 0 && dy < 0)) &&
        _ccmIndex < ccmFeed.length - 1;
      var goPrev =
        (dy > threshold || (flick && v > 0 && dy > 0)) && _ccmIndex > 0;
      /* the faster the finger, the shorter the glide: the remaining distance is covered at (about) the finger's own speed */
      var _ms = CCM_SLIDE_MS;
      if (flick) {
        var remain = Math.max(0, stageH - Math.abs(dy));
        _ms = Math.round(
          Math.max(
            190,
            Math.min(CCM_SLIDE_MS, remain / Math.max(Math.abs(v), 0.9)),
          ),
        );
      }
      var _willGo = goNext || goPrev;
      if (!_willGo)
        _ccmChromeClear(); /* springs back: the arrow / label return to how they were */
      if (goNext) {
        _ccmCommitTrackMove(1, _ms);
      } else if (goPrev) {
        _ccmCommitTrackMove(-1, _ms);
      } else {
        track.style.transition =
          "transform 380ms cubic-bezier(.2,.9,.3,1.12)"; /* springs back with a tiny, natural overshoot */
        track.style.transform = "translateY(0)";

        var cancelTransition =
          "opacity " +
          CCM_SLIDE_MS +
          "ms " +
          CCM_SLIDE_EASE +
          ", transform " +
          CCM_SLIDE_MS +
          "ms " +
          CCM_SLIDE_EASE;
        var curSlideEl = track.querySelector(
          '.ccm-vslide[data-pos="current"] .ccm-slide',
        );
        _ccmOverlayEls(curSlideEl).forEach(function (el) {
          el.style.transition = cancelTransition;
        });
        _ccmSetOverlayProgress(curSlideEl, 0, 0);

        ["next", "prev"].forEach(function (pos) {
          var nSlideEl = track.querySelector(
            '.ccm-vslide[data-pos="' + pos + '"] .ccm-slide',
          );
          if (!nSlideEl) return;
          _ccmOverlayEls(nSlideEl).forEach(function (el) {
            el.style.transition = cancelTransition;
          });
          _ccmSetOverlayProgress(nSlideEl, 1, 0);
        });
      }
    },
    { passive: true },
  );
}

function _ccmCommitTrackMove(dir, ms) {
  ms = typeof ms === "number" && ms > 0 ? ms : CCM_SLIDE_MS;
  if (_ccmVSwipeLocked) return;
  if (_ccmHStack.length > 0) return;

  var track = document.getElementById("ccm-vtrack");
  if (!track) return;

  _ccmVSwipeLocked = true;
  if (_ccmIsSuggAt(_ccmIndex) !== _ccmIsSuggAt(_ccmIndex + dir))
    _ccmChromeSet(
      _ccmIsSuggAt(_ccmIndex + dir) ? 0 : 1,
      true,
    ); /* finish the fade started by the drag (or start it for key / wheel) */

  var stageH = document.getElementById("ccm-stage").clientHeight;
  track.style.transition = "transform " + ms + "ms " + CCM_SLIDE_EASE;
  track.style.transform = "translateY(" + (dir > 0 ? -stageH : stageH) + "px)";

  var keepPos = dir > 0 ? "next" : "prev";
  var curSlideEl = track.querySelector(
    '.ccm-vslide[data-pos="current"] .ccm-slide',
  );
  var nextSlideEl = track.querySelector(
    '.ccm-vslide[data-pos="' + keepPos + '"] .ccm-slide',
  );
  var commitTransition =
    "opacity " +
    ms +
    "ms " +
    CCM_SLIDE_EASE +
    ", transform " +
    ms +
    "ms " +
    CCM_SLIDE_EASE;
  _ccmOverlayEls(curSlideEl).forEach(function (el) {
    el.style.transition = commitTransition;
  });
  _ccmOverlayEls(nextSlideEl).forEach(function (el) {
    el.style.transition = commitTransition;
  });
  _ccmSetOverlayProgress(curSlideEl, 1, -dir);
  _ccmSetOverlayProgress(nextSlideEl, 0, dir);

  var newIndex = _ccmIndex + dir;
  var settled = false;
  var finish = function () {
    if (settled) return;
    settled = true;
    track.removeEventListener("transitionend", onEnd);
    _ccmFinishTrackMove(dir, newIndex);
  };
  var onEnd = function (e) {
    if (e.target === track && e.propertyName === "transform") finish();
  };
  track.addEventListener("transitionend", onEnd);
  setTimeout(finish, ms + 50);
}

function _ccmFinishTrackMove(dir, newIndex) {
  var track = document.getElementById("ccm-vtrack");
  if (!track) {
    _ccmVSwipeLocked = false;
    return;
  }

  var keepPos = dir > 0 ? "next" : "prev";
  var dropPos = dir > 0 ? "prev" : "next";
  var keepNode = track.querySelector('.ccm-vslide[data-pos="' + keepPos + '"]');
  var dropNode = track.querySelector('.ccm-vslide[data-pos="' + dropPos + '"]');
  var oldCurrent = track.querySelector('.ccm-vslide[data-pos="current"]');

  if (dropNode) {
    dropNode.querySelectorAll("video").forEach(function (v) {
      v.pause();
      v.src = "";
      v.load();
    });
    dropNode.remove();
  }
  if (oldCurrent) {
    oldCurrent.dataset.pos = dropPos;
    oldCurrent.querySelectorAll("video").forEach(function (v) {
      v.pause();
    });
    var oldSlideEl = oldCurrent.querySelector(".ccm-slide");
    if (oldSlideEl) oldSlideEl.removeAttribute("id");
    if (_ccmInfoSwitchTimer) {
      clearInterval(_ccmInfoSwitchTimer);
      _ccmInfoSwitchTimer = null;
    }
    if (_ccmCtaPillTimer) {
      clearInterval(_ccmCtaPillTimer);
      _ccmCtaPillTimer = null;
    }
  }
  if (keepNode) {
    keepNode.dataset.pos = "current";
    var innerSlide = keepNode.querySelector(".ccm-slide");
    if (innerSlide) innerSlide.id = "ccm-slide";
  }

  track.style.transition = "none";
  track.style.transform = "translateY(0)";
  void track.offsetHeight;

  var missingIndex = keepPos === "next" ? newIndex + 1 : newIndex - 1;
  track.appendChild(_ccmBuildSlideWrap(_ccmSlideItem(missingIndex), keepPos));

  var promotedItem = _ccmSlideItem(newIndex);
  var promotedId =
    promotedItem && promotedItem.type === "post" ? promotedItem.data.id : null;

  _ccmIndex = newIndex;
  _ccmMediaIndex = _ccmGetSavedMediaIndex(promotedId);
  _ccmActivateCurrentSlide(promotedItem);

  _ccmVSwipeLocked = false;
}

function ccmLoadIndex(index) {
  if (_ccmVSwipeLocked) return;
  if (_ccmHStack.length > 0) return;
  if (index < 0 || index >= ccmFeed.length || index === _ccmIndex) return;
  if (Math.abs(index - _ccmIndex) === 1) {
    _ccmCommitTrackMove(index > _ccmIndex ? 1 : -1);
  } else {
    _ccmMountTrack(index);
  }
}

/* ══════════════════════════════════════════════════════════
   CONNECTED ZEEM — horizontal linked-post navigation
   ══════════════════════════════════════════════════════════ */
function _ccmEnsureHStackEl() {
  var inner = document.getElementById("ccm-inner");
  if (!inner) return null;
  var el = document.getElementById("ccm-hstack");
  if (!el) {
    el = document.createElement("div");
    el.id = "ccm-hstack";
    el.style.position = "absolute";
    el.style.inset = "0";
    el.style.zIndex = "12";
    el.style.overflow = "hidden";
    el.style.pointerEvents = "none";
    inner.appendChild(el);
  }
  return el;
}

function ccmOpenConnectedZeem(memeId) {
  if (!memeId) return;

  if (_ccmHStack.length) {
    var topItem = _ccmHStack[_ccmHStack.length - 1].item;
    if (topItem && String(topItem.data.id) === String(memeId)) return;
  }

  var cached = _ccmConnectedCache[memeId];
  if (cached) {
    _ccmPushConnectedLayer(cached);
    return;
  }

  if (_ccmConnectedFetchLock) return;
  _ccmConnectedFetchLock = true;

  fetch(_ccmEp(), {
    method: "POST",
    credentials: "same-origin",
    headers: _ccmCsrfH({ "Content-Type": "application/x-www-form-urlencoded" }),
    body:
      (CCM_CFG.connectedBody
        ? CCM_CFG.connectedBody(memeId)
        : "action=get_connected_meme&meme_id=" + encodeURIComponent(memeId)) +
      _ccmCsrfQ(),
  })
    .then(function (r) {
      return r.json();
    })
    .then(function (data) {
      _ccmConnectedFetchLock = false;
      if (!data || !data.ok || !data.post) {
        ccmToast("Could not load this Zeem");
        return;
      }
      _ccmConnectedCache[memeId] = data.post;
      _ccmPushConnectedLayer(data.post);
    })
    .catch(function () {
      _ccmConnectedFetchLock = false;
      ccmToast("Network error — try again");
    });
}

function _ccmPushConnectedLayer(postData) {
  var hstack = _ccmEnsureHStackEl();
  if (!hstack) return;

  var curSlide = document.getElementById("ccm-slide");
  if (curSlide) curSlide.removeAttribute("id");

  if (_ccmInfoSwitchTimer) {
    clearInterval(_ccmInfoSwitchTimer);
    _ccmInfoSwitchTimer = null;
  }
  if (_ccmCtaPillTimer) {
    clearInterval(_ccmCtaPillTimer);
    _ccmCtaPillTimer = null;
  }

  var item = { type: "post", data: postData };

  var layer = document.createElement("div");
  layer.className = "ccm-hlayer";
  layer.style.position = "absolute";
  layer.style.inset = "0";
  layer.style.width = "100%";
  layer.style.height = "100%";
  layer.style.background = "#000";
  layer.style.willChange = "transform";

  var slideEl = document.createElement("div");
  slideEl.className = "ccm-slide";
  slideEl.id = "ccm-slide";
  slideEl.innerHTML = _ccmRenderPost(postData, false);
  layer.appendChild(slideEl);

  layer.style.transform = "translateX(100%)";
  layer.style.transition = "none";
  hstack.appendChild(layer);
  hstack.style.pointerEvents = "all";

  void layer.offsetHeight;
  layer.style.transition = "transform .32s cubic-bezier(.22,.36,.36,1)";
  layer.style.transform = "translateX(0)";

  layer.querySelectorAll(".ccm-video-progress").forEach(ccmInitProgressScrub);

  _ccmHStack.push({ layer: layer, item: item });
  _ccmMediaIndex = 0;

  _ccmActivateCurrentSlide(item);
}

function ccmCloseConnectedZeem() {
  if (!_ccmHStack.length) return;

  var top = _ccmHStack.pop();
  var layer = top.layer;

  if (_ccmInfoSwitchTimer) {
    clearInterval(_ccmInfoSwitchTimer);
    _ccmInfoSwitchTimer = null;
  }
  if (_ccmCtaPillTimer) {
    clearInterval(_ccmCtaPillTimer);
    _ccmCtaPillTimer = null;
  }

  var outgoingSlide = layer.querySelector(".ccm-slide");
  if (outgoingSlide) {
    outgoingSlide.removeAttribute("id");
    outgoingSlide.querySelectorAll("video").forEach(function (v) {
      v.pause();
    });
  }

  layer.style.transition = "transform .32s cubic-bezier(.22,1,.36,1)";
  layer.style.transform = "translateX(100%)";

  var removed = false;
  function finish() {
    if (removed) return;
    removed = true;
    layer.removeEventListener("transitionend", onEnd);
    if (layer.parentNode) layer.parentNode.removeChild(layer);

    var nextTop = _ccmHStack.length ? _ccmHStack[_ccmHStack.length - 1] : null;
    var nextItem;

    if (nextTop) {
      var nextSlide = nextTop.layer.querySelector(".ccm-slide");
      if (nextSlide) nextSlide.id = "ccm-slide";
      nextItem = nextTop.item;
    } else {
      var hstack = document.getElementById("ccm-hstack");
      if (hstack) hstack.style.pointerEvents = "none";
      var curVSlide = document.querySelector(
        '.ccm-vslide[data-pos="current"] .ccm-slide',
      );
      if (curVSlide) curVSlide.id = "ccm-slide";
      nextItem = _ccmSlideItem(_ccmIndex);
    }

    _ccmMediaIndex = _ccmGetSavedMediaIndex(
      nextItem && nextItem.type === "post" ? nextItem.data.id : null,
    );
    _ccmActivateCurrentSlide(nextItem);
  }
  var onEnd = function (e) {
    if (e.target === layer && e.propertyName === "transform") finish();
  };
  layer.addEventListener("transitionend", onEnd);
  setTimeout(finish, 360);
}

/* Back button: slides back one Connected-Zeem hop, or closes the modal when none are open */
function ccmHandleBackTap() {
  var _m = document.getElementById("cardCaptionModal");
  if (_m && _m.classList.contains("ccm-cs-side") && window.CommentSection) {
    window.CommentSection.close();
    return;
  } /* laptop: Esc / Back closes the side comments first */
  if (_ccmHStack.length > 0) {
    ccmCloseConnectedZeem();
  } else {
    closeCardCaptionModal();
  }
}

var _ccmScrollLockY = 0;
function ccmLockBodyScroll() {
  document.documentElement.style.overscrollBehavior = "none";
  document.body.style.overscrollBehavior = "none";
  _ccmScrollLockY = window.scrollY || window.pageYOffset || 0;
  document.body.style.position = "fixed";
  document.body.style.top = "-" + _ccmScrollLockY + "px";
  document.body.style.left = "0";
  document.body.style.right = "0";
  document.body.style.width = "100%";
  document.body.style.overflow = "hidden";
}
function ccmUnlockBodyScroll() {
  document.documentElement.style.overscrollBehavior = "";
  document.body.style.overscrollBehavior = "";
  document.body.style.position = "";
  document.body.style.top = "";
  document.body.style.left = "";
  document.body.style.right = "";
  document.body.style.width = "";
  document.body.style.overflow = "";
  window.scrollTo(0, _ccmScrollLockY);
  var _y = _ccmScrollLockY,
    _back = function () {
      if (Math.abs((window.pageYOffset || 0) - _y) > 2) window.scrollTo(0, _y);
    }; /* some phones drop it to the top once: put it back */
  requestAnimationFrame(_back);
  setTimeout(_back, 60);
  setTimeout(_back, 300);
}

/* ══════════ toast (host showToast if the page has one, else a tiny built-in one) ══════════ */
var _ccmToastTimer = null;
function ccmToast(msg) {
  if (typeof showToast === "function") {
    try {
      showToast(msg);
      return;
    } catch (e) {}
  }
  var el = document.getElementById("ccmBuiltinToast");
  if (!el) {
    el = document.createElement("div");
    el.id = "ccmBuiltinToast";
    el.style.cssText =
      "position:fixed;left:50%;bottom:calc(90px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);" +
      "background:rgba(20,20,24,.94);color:#fff;font:600 13px Poppins,sans-serif;padding:10px 16px;border-radius:12px;" +
      "z-index:2147483000;pointer-events:none;opacity:0;transition:opacity .2s;max-width:86vw;text-align:center;";
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.style.opacity = "1";
  clearTimeout(_ccmToastTimer);
  _ccmToastTimer = setTimeout(function () {
    el.style.opacity = "0";
  }, 2200);
}

/* ══════════ "…" menu sheet: copy caption · QR code · share · "why am I seeing this ad" ══════════ */
function _ccmEscHtml(str) {
  return _ccmEscapeHtml(str == null ? "" : String(str));
}

function ccmMenuTap(postId) {
  dzHaptic.light();
  if (!CCM_CFG.menuItems && typeof openIndexSheet === "function") {
    openIndexSheet(postId);
    return;
  }
  openCcmMenuSheet();
}

function _ccmSheetPost() {
  var top = _ccmHStack.length
    ? _ccmHStack[_ccmHStack.length - 1].item
    : ccmFeed[_ccmIndex];
  return top && top.type === "post" ? top.data : null;
}
function _ccmPostLink(id) {
  return _ccmShareLinkFor(id);
}
function _ccmSheetExtraRows(p) {
  var items = null;
  try {
    items = CCM_CFG.menuItems ? CCM_CFG.menuItems(p) : null;
  } catch (e) {}
  if (!Array.isArray(items)) return "";
  return items
    .map(function (it) {
      return it
        ? _ccmSheetRow(
            it.icon || "ri-more-line",
            _ccmEscHtml(it.label || ""),
            it.onclick || "",
          )
        : "";
    })
    .join("");
}
function _ccmSheetRow(icon, label, onclick) {
  onclick = String(onclick || "").replace(/"/g, "&quot;");
  return (
    '<button type="button" class="ccm-sheet-row" onclick="event.stopPropagation();dzHaptic.light();' +
    onclick +
    '">' +
    '<i class="' +
    icon +
    '"></i><span>' +
    label +
    "</span></button>"
  );
}
function _ccmSheetPostHTML(p) {
  return (
    (p.caption
      ? _ccmSheetRow("ri-file-copy-line", "Copy caption", "ccmCopyCaption()")
      : "") +
    _ccmSheetRow("ri-qr-code-line", "Show QR code", "ccmShowQR()") +
    _ccmSheetRow("ri-share-forward-line", "Share", "ccmShareMeme()") +
    _ccmSheetExtraRows(p)
  );
}
function _ccmSheetAdHTML(p) {
  return (
    _ccmSheetRow(
      "ri-information-line",
      "Why am I seeing this ad?",
      "ccmShowAdReason()",
    ) +
    _ccmSheetRow("ri-qr-code-line", "Show QR code", "ccmShowQR()") +
    _ccmSheetExtraRows(p)
  );
}
function _ccmSheetHTML(p) {
  return (
    '<div class="ccm-sheet-grab"></div>' +
    (p.isAd ? _ccmSheetAdHTML(p) : _ccmSheetPostHTML(p))
  );
}
function openCcmMenuSheet() {
  var p = _ccmSheetPost();
  if (!p) return;
  var modal = document.getElementById("cardCaptionModal");
  var ov = document.getElementById("ccm-sheet-ov");
  if (!ov) {
    ov = document.createElement("div");
    ov.id = "ccm-sheet-ov";
    ov.className = "ccm-sheet-ov";
    ov.innerHTML = '<div class="ccm-sheet" id="ccm-sheet"></div>';
    ov.addEventListener("click", function (e) {
      if (e.target === ov) closeCcmMenuSheet();
    });
    modal.appendChild(ov);
  }
  document.getElementById("ccm-sheet").innerHTML = _ccmSheetHTML(p);
  void ov.offsetHeight;
  ov.classList.add("show");
}
function closeCcmMenuSheet() {
  var ov = document.getElementById("ccm-sheet-ov");
  if (ov) ov.classList.remove("show");
}
function ccmSheetBackToList() {
  var p = _ccmSheetPost(),
    sh = document.getElementById("ccm-sheet");
  if (p && sh) sh.innerHTML = _ccmSheetHTML(p);
}
function ccmShowAdReason() {
  var sh = document.getElementById("ccm-sheet");
  if (!sh) return;
  sh.innerHTML =
    '<div class="ccm-sheet-grab"></div>' +
    '<div class="ccm-sheet-title">Why am I seeing this ad?</div>' +
    '<div class="ccm-sheet-text">This post is an ad. The advertiser paid to promote it, so it is shown in your feed and marked ' +
    "“Advert”. Ads are not posts from people you track.</div>" +
    _ccmSheetRow("ri-arrow-left-s-line", "Back", "ccmSheetBackToList()");
}
function ccmShowQR() {
  var p = _ccmSheetPost(),
    sh = document.getElementById("ccm-sheet");
  if (!p || !sh) return;
  var link = p.isAd && p.adCtaUrl ? p.adCtaUrl : _ccmPostLink(p.id);
  sh.innerHTML =
    '<div class="ccm-sheet-grab"></div>' +
    '<div class="ccm-sheet-title">Scan to open</div>' +
    '<div class="ccm-sheet-qr"><img alt="QR code" src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=8&data=' +
    encodeURIComponent(link) +
    '"></div>' +
    _ccmSheetRow("ri-arrow-left-s-line", "Back", "ccmSheetBackToList()");
}
function ccmCopyCaption() {
  var p = _ccmSheetPost();
  if (!p || !p.caption) return;
  var done = function () {
    closeCcmMenuSheet();
    ccmToast("Caption copied");
    dzHaptic.success();
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard
      .writeText(p.caption)
      .then(done)
      .catch(function () {
        _ccmCopyFallback(p.caption);
        done();
      });
  } else {
    _ccmCopyFallback(p.caption);
    done();
  }
}
function _ccmCopyFallback(text) {
  var ta = document.createElement("textarea");
  ta.value = text;
  ta.style.cssText = "position:fixed;opacity:0;";
  document.body.appendChild(ta);
  ta.select();
  try {
    document.execCommand("copy");
  } catch (e) {}
  ta.remove();
}
function ccmShareMeme() {
  var p = _ccmSheetPost();
  closeCcmMenuSheet();
  if (p) openShareModal(p.id);
}

/* current playback of the active post — the profile grid uses it to continue from the same spot */
window.ccmGetCurrentPlayback = function () {
  if (!_ccmMemeId) return null;
  var slide = document.getElementById("ccm-slide");
  if (!slide) return null;
  var box = slide.querySelector("#ccm-media-box");
  var videoTime = 0;
  if (box) {
    var act = box.querySelector(
      '.ccm-media-item[data-idx="' + _ccmMediaIndex + '"]',
    );
    var v = act
      ? act.querySelector("video.ccm-media")
      : box.querySelector("video.ccm-media");
    if (v && !isNaN(v.currentTime)) videoTime = v.currentTime || 0;
  }
  var audioTime = 0;
  var t = document.getElementById("ccmSoundtrackPlayer");
  if (t && t.src && !isNaN(t.currentTime)) audioTime = t.currentTime || 0;
  return {
    id: _ccmMemeId,
    memeId: _ccmMemeId,
    mediaIndex: _ccmMediaIndex,
    videoTime: videoTime,
    currentTime: videoTime,
    audioTime: audioTime,
  };
};

/* open by post id (profile page): openCardModal({ id, feed, mediaIndex, startTime, startAudioTime, posterDataUrl }) */
function _ccmOpenById(o) {
  _ccmUseMenuSheet = true;
  _ccmFeedSource = Array.isArray(o.feed) ? o.feed : null;
  _buildCcmFeed();

  var idx = -1;
  if (o.id !== undefined && o.id !== null) {
    idx = ccmFeed.findIndex(function (s) {
      return s.type === "post" && String(s.data.id) === String(o.id);
    });
  }
  if (idx < 0 && o.userId !== undefined) {
    idx = ccmFeed.findIndex(function (s) {
      return (
        s.type === "post" &&
        String(s.data.userId) === String(o.userId) &&
        s.data.caption === o.caption
      );
    });
  }
  var targetIdx = idx >= 0 ? idx : 0;
  var target = ccmFeed[targetIdx];
  if (target && target.type === "post") {
    var tid = target.data.id;
    _ccmSaveMediaIndex(
      tid,
      typeof o.mediaIndex === "number" && o.mediaIndex > 0 ? o.mediaIndex : 0,
    );
    if (typeof o.startAudioTime === "number" && o.startAudioTime > 0)
      _ccmTrackPositions[tid] = o.startAudioTime;
    else delete _ccmTrackPositions[tid];
    delete _ccmPausedMemeIds[tid];
  }
  _ccmPendingStartTime =
    typeof o.startTime === "number" && o.startTime > 0 ? o.startTime : 0;
  _ccmPendingPosterUrl =
    typeof o.posterDataUrl === "string" && o.posterDataUrl
      ? o.posterDataUrl
      : null;

  _ccmVSwipeLocked = false;
  var existingHstack = document.getElementById("ccm-hstack");
  if (existingHstack) {
    existingHstack.querySelectorAll("video").forEach(function (v) {
      v.pause();
      v.src = "";
    });
    existingHstack.innerHTML = "";
    existingHstack.style.pointerEvents = "none";
  }
  _ccmHStack = [];

  /* open + force layout BEFORE the slides are built, so ccmFixObjectFit() never reads a 0 width/height (the cover<->contain "jump") */
  var modal = document.getElementById("cardCaptionModal");
  modal.classList.add("ccm-open");
  ccmLockBodyScroll();
  void modal.offsetHeight;

  _ccmMountTrack(targetIdx);
  _ccmResetAllOverlayStyles(document.getElementById("ccm-vtrack"));
  _ccmInitVerticalDrag();
}

/* ═══════════════════════════════════════
   OWN CONTINUATION (home / dezztext / search)
   Open post A from the page's feed → zeems shows A first, then its OWN run of posts from the same ranking
   algorithm (a fresh seed), not the page's own B, C, D…  The run is fetched in the background
   (?feed_json=1&nolog=1) as soon as the page is ready, and again right after every close.  If a post is tapped
   while the run is still arriving, the modal waits for it (at most ~0.9 s); if it never arrives, zeems falls back
   to the page's own order exactly as before.  Works for both open forms when the caller passes no list of its own
   (profile / history / group pass theirs, so they are untouched).  Opt out with  window.CCM_OWN_RUN = false.
════════════════════════════════════════ */
var CCM_OWN_CHUNK = 60,
  CCM_OWN_LOW = 40; /* posts per request · refill when fewer than this are still unseen */
var _ccmOwn = {
  queue: [],
  shown: {},
  pos: {},
  seed: 0,
  asof: 0,
  page: 1,
  done: false,
  loading: false,
  p: null,
  opening: false,
  rep: {},
  ads: {},
};
function _ccmOwnPage() {
  if (window.CCM_OWN_FORCE) return true;
  return (
    typeof peuraFeed !== "undefined" &&
    Array.isArray(peuraFeed) &&
    peuraFeed.length > 0
  );
}
function _ccmOwnWants(arg) {
  if (window.CCM_OWN_RUN === false || !_ccmOwnPage()) return false;
  if (arg && typeof arg === "object")
    return !Array.isArray(
      arg.feed,
    ); /* object form with its own list = not ours */
  return true;
}
function _ccmOwnUnseen() {
  return _ccmOwn.queue.filter(function (p) {
    return !_ccmOwn.shown[String(p.id)];
  });
}
function _ccmOwnReady() {
  return _ccmOwnUnseen().length > 0;
}
/* One ranking session per page visit (fixed seed + asof), read chunk by chunk (page 1, 2, 3…): the server ranks it once and
   remembers it, so every later chunk is cheap and never repeats. Only fetches when fewer than CCM_OWN_LOW posts are unseen. */
function _ccmOwnPrefetch() {
  if (
    _ccmOwn.loading ||
    _ccmOwn.done ||
    window.CCM_OWN_RUN === false ||
    !_ccmOwnPage()
  )
    return _ccmOwn.p;
  if (_ccmOwnUnseen().length >= CCM_OWN_LOW) return _ccmOwn.p;
  _ccmOwn.loading = true;
  _ccmOwn.p = new Promise(function (resolve) {
    try {
      if (!_ccmOwn.seed) _ccmOwn.seed = 1 + Math.floor(Math.random() * 104700);
      var p = new URLSearchParams(
        window.CCM_OWN_URL ? "" : window.location.search,
      );
      ["asof", "meme_id", "page", "seed", "type", "limit"].forEach(
        function (k) {
          p.delete(k);
        },
      );
      p.set("feed_json", "1");
      p.set("nolog", "1");
      p.set("limit", String(CCM_OWN_CHUNK));
      p.set("page", String(_ccmOwn.page));
      p.set("seed", String(_ccmOwn.seed));
      if (_ccmOwn.asof) p.set("asof", String(_ccmOwn.asof));
      fetch(
        (window.CCM_OWN_URL || window.location.pathname) + "?" + p.toString(),
        { credentials: "same-origin", headers: { Accept: "application/json" } },
      )
        .then(function (r) {
          return r.ok ? r.json() : null;
        })
        .then(function (j) {
          if (!j || !Array.isArray(j.feed)) {
            if (window.console)
              console.warn("[zeems] own run: no feed in the response");
            return;
          }
          if (j.asof) _ccmOwn.asof = j.asof;
          if (j.seed) _ccmOwn.seed = j.seed;
          var have = {};
          _ccmOwn.queue.forEach(function (q) {
            have[String(q.id)] = 1;
          });
          j.feed.forEach(function (q) {
            var id = String(q.id);
            if (have[id] || _ccmOwn.shown[id])
              return; /* ads stay in the run, like in the page feed */
            have[id] = 1;
            _ccmOwn.queue.push(q);
          });
          _ccmOwn.page = (j.page || _ccmOwn.page) + 1;
          if (!j.hasMore || !j.feed.length) _ccmOwn.done = true;
        })
        .catch(function (e) {
          if (window.console) console.warn("[zeems] own run failed", e);
        })
        .then(function () {
          _ccmOwn.loading = false;
          resolve();
        });
    } catch (e) {
      _ccmOwn.loading = false;
      resolve();
    }
  });
  return _ccmOwn.p;
}
/* the tapped post + the next unseen posts of the session (the tapped post is never repeated); null = nothing ready → page order */
function _ccmOwnTake(tapped) {
  if (!tapped) return null;
  var tid = String(tapped.id);
  _ccmOwn.shown[tid] = 1; /* the page already showed it */
  var out = [tapped],
    un = _ccmOwnUnseen();
  for (var i = 0; i < un.length && out.length <= CCM_OWN_CHUNK; i++) {
    _ccmOwn.pos[String(un[i].id)] = out.length;
    if (un[i].isAd) _ccmOwn.ads[String(un[i].id)] = 1;
    out.push(un[i]);
  }
  if (out.length < 2) return null;
  _ccmOwn.tappedId = tid;
  return out;
}
/* view counts: 1200 -> 1.2K, 3400000 -> 3.4M (null/undefined -> empty) */
function ccmFmtViews(n) {
  if (n === undefined || n === null) return "";
  n = +n || 0;
  if (n >= 1e6)
    return (Math.round(n / 1e5) / 10).toString().replace(/\.0$/, "") + "M";
  if (n >= 1e3)
    return (Math.round(n / 100) / 10).toString().replace(/\.0$/, "") + "K";
  return String(n);
}
/* the server sends back fresh counters after a view is recorded: update the data and the open post */
function ccmApplyViews(map) {
  try {
    Object.keys(map).forEach(function (id) {
      var v = map[id];
      for (var i = 0; i < peuraFeed.length; i++) {
        if (String(peuraFeed[i].id) === String(id)) peuraFeed[i].views = v;
      }
      if (String(_ccmMemeId) === String(id)) {
        var el = document.getElementById("ccm-views-count");
        if (el) {
          el.textContent = ccmFmtViews(v);
          el.setAttribute("data-hidden", "0");
        }
      }
      if (window.kebabData && window.kebabData[id])
        window.kebabData[id].views = v;
    });
  } catch (e) {}
}
/* a post of the run that stays on screen for 1 s counts as SEEN (same table the page's own impressions fill) and as SERVED */
var _ccmOwnRepQ = [],
  _ccmOwnRepTimer = null,
  _ccmOwnDwell = null;
function _ccmOwnShown(id) {
  clearTimeout(_ccmOwnDwell);
  if (!id || window.CCM_OWN_RUN === false) return;
  var sid = String(id);
  if (_ccmOwn.shown[sid] && _ccmOwn.pos[sid] === undefined)
    return; /* page post / already reported */
  if (_ccmOwn.pos[sid] === undefined) return; /* not from the run */
  _ccmOwnDwell = setTimeout(function () {
    if (String(_ccmMemeId) !== sid) return;
    _ccmOwn.shown[sid] = 1;
    var wasAd = !!_ccmOwn.ads[sid];
    if (!wasAd)
      _ccmOwnRepQ.push(
        sid + ":" + _ccmOwn.pos[sid],
      ); /* ads keep their own impression counting: not reported twice */
    delete _ccmOwn.pos[sid];
    if (wasAd) return;
    clearTimeout(_ccmOwnRepTimer);
    _ccmOwnRepTimer = setTimeout(_ccmOwnFlush, 2500);
  }, 1000);
}
function _ccmOwnFlush() {
  if (!_ccmOwnRepQ.length) return;
  var items = _ccmOwnRepQ.splice(0, 50);
  var ids = items
    .map(function (x) {
      return x.split(":")[0];
    })
    .join(",");
  var body = "action=impressions&src=zeems&ids=" + encodeURIComponent(ids);
  var body2 =
    "action=zeems_serves&items=" +
    encodeURIComponent(items.join(",")) +
    "&seed=" +
    encodeURIComponent(_ccmOwn.seed);
  try {
    [body, body2].forEach(function (b) {
      var isImp = b === body;
      var rq = fetch(window.CCM_OWN_URL || window.location.pathname, {
        method: "POST",
        credentials: "same-origin",
        keepalive: true,
        headers: _ccmCsrfH({
          "Content-Type": "application/x-www-form-urlencoded",
        }),
        body: b + _ccmCsrfQ(),
      });
      if (isImp)
        rq.then(function (r) {
          return r.json();
        })
          .then(function (j) {
            if (j && j.views) ccmApplyViews(j.views);
          })
          .catch(function () {});
      else rq.catch(function () {});
    });
  } catch (e) {}
}
document.addEventListener("visibilitychange", function () {
  if (document.hidden) _ccmOwnFlush();
});
document.addEventListener("zeems:closed", function () {
  _ccmOwnFlush();
});
function _ccmOwnFindTapped(arg, userId) {
  for (var i = 0; i < peuraFeed.length; i++) {
    var f = peuraFeed[i];
    if (arg && typeof arg === "object") {
      if (
        arg.id !== undefined &&
        arg.id !== null &&
        String(f.id) === String(arg.id)
      )
        return f;
    } else if (f.file === arg && String(f.userId) === String(userId)) return f;
  }
  return null;
}
/* for pages that open the modal their own way (search.php's hpOpenPost builds the track itself):
   window.ccmOwnPrepare(memeId, go)  →  puts the tapped post first + the fresh run behind it into ccmFeed, then calls go().
   Waits for the run for at most ~0.9 s; with no run it just rebuilds ccmFeed from the page's feed (same as before). */
window.ccmOwnPrepare = function (memeId, go) {
  var apply = function () {
    var run = null;
    try {
      if (_ccmOwnWants(null) && _ccmOwnReady())
        run = _ccmOwnTake(_ccmOwnFindTapped({ id: memeId }, null));
    } catch (e) {}
    _ccmFeedSource = run || null;
    ccmFeed = []; /* rebuilt from the run, or from the page's own feed */
    go();
  };
  if (!_ccmOwnWants(null) || _ccmOwnReady()) {
    apply();
    return;
  }
  if (_ccmOwn.opening) return;
  _ccmOwn.opening = true;
  var fin = function () {
    if (!_ccmOwn.opening) return;
    _ccmOwn.opening = false;
    apply();
  };
  var pr = _ccmOwnPrefetch();
  if (!pr) {
    fin();
    return;
  }
  pr.then(fin);
  setTimeout(fin, 900);
};
/* chat page: the run is fetched only when a post is tapped (window.CCM_OWN_LAZY), from window.CCM_OWN_URL (home.php) */
if (!window.CCM_OWN_LAZY) {
  if (document.readyState === "complete") setTimeout(_ccmOwnPrefetch, 300);
  else
    window.addEventListener("load", function () {
      setTimeout(_ccmOwnPrefetch, 300);
    });
  document.addEventListener("zeems:closed", function () {
    _ccmOwnPrefetch();
  });
}
window.ccmOwnWarm = function () {
  try {
    return _ccmOwnPrefetch();
  } catch (e) {}
};
/* a host page that has the tapped post itself: window.ccmOwnRunFor(post) → Promise of [post, …the algorithm's next posts] (just [post] if the run is not ready within ~1.2 s) */
window.ccmOwnRunFor = function (tapped) {
  return new Promise(function (resolve) {
    var done = false,
      fin = function () {
        if (done) return;
        done = true;
        var r = null;
        try {
          r = _ccmOwnTake(tapped);
        } catch (e) {}
        resolve(r || [tapped]);
      };
    try {
      var pr = _ccmOwnPrefetch();
      if (pr && pr.then) pr.then(fin);
      else fin();
    } catch (e) {
      fin();
    }
    setTimeout(fin, 1200);
  });
};

function openCardModal(file, type, pic, userId, username, caption) {
  if (typeof ccmZoomReset === "function")
    ccmZoomReset(false); /* a fresh post always starts un-zoomed */
  /* wait (briefly) for the fresh run if it is still on its way, then open */
  if (_ccmOwnWants(file) && !_ccmOwnReady()) {
    if (_ccmOwn.opening) return;
    _ccmOwn.opening = true;
    var _args = arguments;
    var _go = function () {
      if (!_ccmOwn.opening) return;
      _ccmOwn.opening = false;
      _ccmOpenCore.apply(null, _args);
    };
    var _pr = _ccmOwnPrefetch();
    if (!_pr) {
      _go();
      return;
    }
    _pr.then(_go);
    setTimeout(_go, 900);
    return;
  }
  return _ccmOpenCore.apply(null, arguments);
}
/* a host page that zooms a wrapper around the modal (CSS zoom) would scale Zeems with it: measure the zoom of everything above it and cancel it, so the size is the same on every page */
function ccmNeutralizeHostZoom() {
  ["cardCaptionModal", "shareModalOverlay", "shareModalPanel"].forEach(
    function (id) {
      var m = document.getElementById(id);
      if (!m) return;
      m.style.zoom = "";
      var z = 1;
      for (var e = m.parentElement; e; e = e.parentElement) {
        var v = parseFloat(getComputedStyle(e).zoom);
        if (v && v !== 1) z *= v;
      }
      if (Math.abs(z - 1) > 0.001) m.style.zoom = (1 / z).toFixed(4);
    },
  );
}
window.addEventListener("resize", function () {
  try {
    ccmNeutralizeHostZoom();
  } catch (e) {}
});
function _ccmOpenCore(file, type, pic, userId, username, caption) {
  try {
    ccmNeutralizeHostZoom();
  } catch (e) {}
  if (file && typeof file === "object") {
    if (_ccmOwnWants(file) && _ccmOwnReady()) {
      var _t0 = _ccmOwnFindTapped(file, null),
        _r0 = _ccmOwnTake(_t0);
      if (_r0) file = Object.assign({}, file, { feed: _r0 });
    }
    return _ccmOpenById(file);
  }
  /* classic form (home / text / search feed): always the page's own peuraFeed */
  _ccmUseMenuSheet = false;
  if (_ccmFeedSource) {
    _ccmFeedSource = null;
    ccmFeed = [];
  }
  /* own continuation: tapped post first, then a fresh run from the same algorithm */
  try {
    if (_ccmOwnWants(file) && _ccmOwnReady()) {
      var _run = _ccmOwnTake(_ccmOwnFindTapped(file, userId));
      if (_run) {
        _ccmFeedSource = _run;
        ccmFeed = [];
      }
    }
  } catch (e) {}
  if (ccmFeed.length === 0) _buildCcmFeed();
  var idx = ccmFeed.findIndex(function (s) {
    return (
      s.type === "post" &&
      s.data.file === file &&
      String(s.data.userId) === String(userId)
    );
  });
  var targetIdx = idx >= 0 ? idx : 0;

  _ccmVSwipeLocked = false;

  var existingHstack = document.getElementById("ccm-hstack");
  if (existingHstack) {
    existingHstack.querySelectorAll("video").forEach(function (v) {
      v.pause();
      v.src = "";
    });
    existingHstack.innerHTML = "";
    existingHstack.style.pointerEvents = "none";
  }
  _ccmHStack = [];

  _ccmMountTrack(targetIdx);
  _ccmInitVerticalDrag();

  document.getElementById("cardCaptionModal").classList.add("ccm-open");
  ccmLockBodyScroll();
}

function closeCardCaptionModal() {
  try {
    if (window.CommentSection) window.CommentSection.close();
  } catch (e) {}
  var _landing = ccmFeed[_ccmIndex];
  var _landingId =
    _landing && _landing.type === "post" ? _landing.data.id : null;
  /* read the playback BEFORE the videos are torn down (src='' resets currentTime) */
  var _pb = null;
  try {
    _pb = window.ccmGetCurrentPlayback();
  } catch (e) {}
  closeCcmMenuSheet();

  var hstack = document.getElementById("ccm-hstack");
  if (hstack) {
    hstack.querySelectorAll("video").forEach(function (v) {
      v.pause();
      v.src = "";
    });
    hstack.innerHTML = "";
    hstack.style.pointerEvents = "none";
  }
  _ccmHStack = [];

  document
    .getElementById("ccm-inner")
    .querySelectorAll("video")
    .forEach(function (v) {
      v.pause();
      v.src = "";
    });
  _ccmStopSoundtrack();
  try {
    ccmWarmCancel();
  } catch (e) {} /* stop warm-up downloads nobody will use */
  ccmHideSavedToast();
  if (_ccmInfoSwitchTimer) {
    clearInterval(_ccmInfoSwitchTimer);
    _ccmInfoSwitchTimer = null;
  }
  if (_ccmCtaPillTimer) {
    clearInterval(_ccmCtaPillTimer);
    _ccmCtaPillTimer = null;
  }
  document.getElementById("cardCaptionModal").classList.remove("ccm-open");
  dzHaptic.light();
  ccmUnlockBodyScroll();
  _ccmMemeId = null;
  _ccmMediaIndex = 0;
  _ccmVSwipeLocked = false;
  document.dispatchEvent(
    new CustomEvent("zeems:closed", {
      detail: {
        memeId: _landingId,
        id: _landingId,
        mediaIndex: _pb ? _pb.mediaIndex : 0,
        videoTime: _pb ? _pb.videoTime : 0,
        currentTime: _pb ? _pb.videoTime : 0,
        audioTime: _pb ? _pb.audioTime : 0,
        playback: _pb,
      },
    }),
  );
}

function _ccmSocialProofHtml(p) {
  var socialProofHtml = "";
  var _ccmNeedsRaise = false;
  if (
    !p.isAd &&
    !p.connectedMemeId &&
    !(p.poll && p.poll.options && p.poll.options.length > 1) &&
    Array.isArray(p.socialProof) &&
    p.socialProof.length
  ) {
    var spClass =
      "ccm-social-proof-strip" +
      (_ccmNeedsRaise ? " ccm-sp-raised" : "") +
      (p.collab && p.collab.list && p.collab.list.length ? " ccm-sp-co" : "");
    socialProofHtml =
      '<div class="' +
      spClass +
      '" id="ccm-social-proof">' +
      p.socialProof
        .slice(0, 4)
        .map(function (sp) {
          var spPic = sp.profile_pic || "mee.jpg";
          var isLike = sp.action_type === "like";
          var icon = isLike ? "ri-heart-3-fill" : "ri-repeat-line";
          var color = isLike ? "#f24455" : "#7a4aff";
          return (
            '<a href="profile.php?user_id=' +
            _ccmId(sp.id) +
            '" onclick="event.stopPropagation()" class="ccm-sp-avatar-wrap">' +
            '<img src="' +
            _ccmSafeUrl(spPic, "mee.jpg") +
            '" onerror="this.src=\'mee.jpg\'" class="ccm-sp-avatar">' +
            (isLike
              ? '<span class="ccm-sp-badge ccm-sp-heart">' +
                CCM_HEART_SVG +
                "</span>"
              : '<span class="ccm-sp-badge ccm-sp-rep"><svg class="ccm-sp-rep-svg" viewBox="0 0 100 100" aria-hidden="true"><circle class="ccm-ring" cx="50" cy="50" r="55.75" fill="none" stroke-width="11.5"/><circle cx="50" cy="50" r="50" fill="' +
                color +
                '"/><g transform="translate(18.8 18.8) scale(2.6)" fill="none" stroke="#fff" stroke-width="2.04" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5"/><path d="M4 13.5V9.276A4.276 4.276 0 0 1 8.276 5H16M12.5 1.5L16 5L12.5 8.5" transform="rotate(180 12 12)"/></g></svg></span>') +
            "</a>"
          );
        })
        .join("") +
      "</div>";
  }
  return socialProofHtml;
}
/* my repost takes a slot in the social-proof strip: it goes first, replacing a like (or the oldest repost if all four are reposts); un-reposting takes it out */
function _ccmSpMine(p, on) {
  var me = window.dzMe;
  if (!me || !me.id) {
    if (window.currentUserId) {
      me = window.dzMe = {
        id: window.currentUserId,
        username: "",
        profile_pic: "mee.jpg",
      };
    } else return;
  }
  var list = (Array.isArray(p.socialProof) ? p.socialProof : []).filter(
    function (x) {
      return String(x.id) !== String(me.id);
    },
  );
  if (on) {
    var bumped = null;
    list.unshift({
      id: me.id,
      username: me.username,
      profile_pic: me.profile_pic,
      action_type: "repost",
    });
    while (list.length > 4) {
      var li = -1;
      for (var i = list.length - 1; i >= 0; i--) {
        if (list[i].action_type === "like") {
          li = i;
          break;
        }
      }
      bumped = list.splice(li >= 0 ? li : list.length - 1, 1)[0];
    }
    p._spBump =
      bumped; /* remembered, so un-reposting puts them straight back */
  } else if (p._spBump) {
    var b = p._spBump;
    p._spBump = null;
    if (
      !list.some(function (x) {
        return String(x.id) === String(b.id);
      })
    ) {
      if (b.action_type === "repost") {
        var at = 0;
        while (at < list.length && list[at].action_type === "repost") at++;
        list.splice(at, 0, b);
      } else list.push(b);
    }
    list = list.slice(0, 4);
  }
  p.socialProof = list;
}
function _ccmSpRefresh(p) {
  var slide = document.getElementById("ccm-slide");
  if (!slide) return;
  var ex = slide.querySelector("#ccm-social-proof"),
    html = _ccmSocialProofHtml(p);
  if (ex) {
    if (html) {
      ex.outerHTML = html;
    } else ex.remove();
  } else if (html) {
    slide.insertAdjacentHTML("beforeend", html);
  }
}
/* ── ACTION HANDLER (like / repost / fav) ── */
function ccmAction(type) {
  if (!_ccmMemeId) return;
  var memeId = _ccmMemeId;

  var actionMap = {
    like: "toggle_like",
    repost: "toggle_repost",
    fav: "toggle_favorite",
  };
  var btnMap = {
    like: "ccm-like-btn",
    repost: "ccm-repost-btn",
    fav: "ccm-fav-btn",
  };
  var cntMap = {
    like: "ccm-like-count",
    repost: "ccm-repost-count",
    fav: "ccm-fav-count",
  };
  var cntField = { like: "likeCount", repost: "repostCount", fav: "favCount" };
  var stateField = { like: "liked", repost: "reposted", fav: "faved" };
  var feedSel = { like: ".like-btn", repost: ".repost-btn", fav: ".fav-btn" };
  var feedCntCls = {
    like: ".like-count",
    repost: ".repost-count",
    fav: ".fav-count",
  };

  var slideEl = document.getElementById("ccm-slide");
  var cmBtn = slideEl ? slideEl.querySelector("#" + btnMap[type]) : null;
  var cmSpan = slideEl ? slideEl.querySelector("#" + cntMap[type]) : null;
  var countHidden = !!(cmSpan && cmSpan.dataset.hidden === "1");

  var wasActive = cmBtn ? cmBtn.classList.contains("active") : false;
  var optimisticNext = !wasActive;
  if (window._ccmHapSkip) {
    window._ccmHapSkip = false;
  } /* the double-tap already buzzed */ else if (type === "fav")
    dzHaptic.light();
  else if (optimisticNext) dzHaptic.medium();
  else dzHaptic.light(); /* like / repost on = a firmer tap, off = a soft one */
  var prevCountText = cmSpan ? cmSpan.textContent : "0";
  var prevCountNum = parseInt(prevCountText, 10) || 0;
  var optimisticCount = optimisticNext
    ? prevCountNum + 1
    : Math.max(0, prevCountNum - 1);

  if (cmBtn) cmBtn.classList.toggle("active", optimisticNext);
  if (cmSpan && !countHidden) cmSpan.textContent = optimisticCount;

  /* my repost takes (or gives back) a social-proof slot the moment I tap, on every page that renders this modal */
  var _spItem = null;
  if (type === "repost") {
    var _spF = ccmFeed.find(function (s) {
      return s.type === "post" && String(s.data.id) === String(memeId);
    });
    if (_spF && !_spF.data.isAd) {
      _spItem = _spF.data;
    } else if (!_spF) {
      /* the post is not in the feed list (some hosts hand the modal their own objects): keep the strip in a small store built from what is on screen */
      window._ccmSpStore = window._ccmSpStore || {};
      var _st = window._ccmSpStore[memeId];
      if (!_st) {
        _st = { id: memeId, socialProof: [] };
        try {
          document
            .querySelectorAll("#ccm-social-proof .ccm-sp-avatar-wrap")
            .forEach(function (a) {
              var im = a.querySelector("img"),
                m = (a.getAttribute("href") || "").match(/user_id=(\d+)/);
              _st.socialProof.push({
                id: m ? m[1] : 0,
                username: "",
                profile_pic: im ? im.getAttribute("src") : "mee.jpg",
                action_type: a.querySelector(".ccm-sp-heart")
                  ? "like"
                  : "repost",
              });
            });
        } catch (e) {}
        window._ccmSpStore[memeId] = _st;
      }
      _spItem = _st;
    }
    if (_spItem) {
      try {
        _ccmSpMine(_spItem, optimisticNext);
        _ccmSpRefresh(_spItem);
      } catch (e) {
        console.warn("social proof update failed", e);
      }
    }
  }

  if (type === "repost" && cmBtn) {
    var repostIcon = cmBtn.querySelector("i");
    if (repostIcon) {
      cmBtn.classList.remove("arc-swap-active");
      void repostIcon.offsetWidth;
      cmBtn.classList.add("arc-swap-active");
      repostIcon.addEventListener("animationend", function handler() {
        cmBtn.classList.remove("arc-swap-active");
        repostIcon.removeEventListener("animationend", handler);
      });
    }
  } else if (cmBtn) {
    cmBtn.style.transform = "scale(1.25)";
    setTimeout(function () {
      if (cmBtn) cmBtn.style.transform = "";
    }, 140);
  }

  if (type === "fav") {
    if (optimisticNext) {
      ccmShowSavedToast(memeId);
    } else {
      ccmHideSavedToast();
    }
  }

  fetch(_ccmEp(), {
    method: "POST",
    credentials: "same-origin",
    headers: _ccmCsrfH({ "Content-Type": "application/x-www-form-urlencoded" }),
    body:
      (CCM_CFG.actionBody
        ? CCM_CFG.actionBody(type, memeId)
        : "action=" +
          actionMap[type] +
          "&meme_id=" +
          encodeURIComponent(memeId)) + _ccmCsrfQ(),
  })
    .then(function (r) {
      return r.json();
    })
    .then(function (data) {
      /* the host may translate its own answer shape: { ok, active, count } */
      var _res = CCM_CFG.actionResult ? CCM_CFG.actionResult(type, data) : null;
      if (_res) {
        data = {
          count: _res.ok
            ? _res.count === undefined
              ? null
              : _res.count
            : undefined,
          _active: _res.active,
        };
      }
      if (data.count === undefined) {
        if (cmBtn) cmBtn.classList.toggle("active", wasActive);
        if (cmSpan && !countHidden) cmSpan.textContent = prevCountText;
        if (type === "fav" && optimisticNext) ccmHideSavedToast();
        if (_spItem) {
          _ccmSpMine(_spItem, wasActive);
          _ccmSpRefresh(_spItem);
        } /* the server said no: put the strip back */
        return;
      }

      var nowActive =
        data._active === true || data._active === false
          ? data._active
          : cmBtn
            ? cmBtn.classList.contains("active")
            : optimisticNext;
      if (cmBtn && nowActive !== cmBtn.classList.contains("active"))
        cmBtn.classList.toggle("active", nowActive);
      if (cmSpan && !countHidden && data.count !== null)
        cmSpan.textContent = data.count;

      if (type === "fav" && nowActive !== optimisticNext) {
        if (nowActive) ccmShowSavedToast(memeId);
        else ccmHideSavedToast();
      }

      var feedBtn = document.querySelector(
        feedSel[type] + '[data-id="' + memeId + '"]',
      );
      if (feedBtn) {
        feedBtn.classList.toggle("active", nowActive);
        var feedCnt = feedBtn.querySelector(feedCntCls[type]);
        if (feedCnt && feedCnt.dataset.hidden !== "1" && data.count !== null)
          feedCnt.textContent = data.count;
      }

      var feedItem = ccmFeed.find(function (s) {
        return s.type === "post" && String(s.data.id) === String(memeId);
      });
      if (feedItem) {
        if (data.count !== null) feedItem.data[cntField[type]] = data.count;
        feedItem.data[stateField[type]] = nowActive;
        if (
          type === "repost" &&
          !feedItem.data.isAd &&
          nowActive !== optimisticNext
        ) {
          _ccmSpMine(feedItem.data, nowActive);
          _ccmSpRefresh(feedItem.data);
        }
      }
      /* tell the host page (the profile page keeps its own lists in step) */
      document.dispatchEvent(
        new CustomEvent("zeems:action", {
          detail: {
            memeId: memeId,
            type: type,
            active: nowActive,
            count: data.count,
            item: feedItem ? feedItem.data : null,
          },
        }),
      );
    })
    .catch(function (err) {
      console.error("ccmAction failed:", err);
      if (cmBtn) cmBtn.classList.toggle("active", wasActive);
      if (cmSpan && !countHidden) cmSpan.textContent = prevCountText;
      if (type === "fav" && optimisticNext) ccmHideSavedToast();
      if (_spItem) {
        _ccmSpMine(_spItem, wasActive);
        _ccmSpRefresh(_spItem);
      }
    });
}

/* ── SHARE MODAL ── */
var _shareMemeId = null;
var _shareAllUsers = [];
var _shareSelected = new Set();

function _shareGetLink() {
  return _ccmShareLinkFor(_shareMemeId);
}

function _shareGetCaption() {
  if (!_shareMemeId) return "";
  var item = ccmFeed.find(function (s) {
    return s.type === "post" && String(s.data.id) === String(_shareMemeId);
  });
  return item && item.data.caption
    ? item.data.caption
    : "Check this out on Zeems";
}

function openShareModal(memeId) {
  dzHaptic.light();
  try {
    ccmNeutralizeHostZoom();
  } catch (e) {}
  _shareMemeId = memeId;
  _shareSelected = new Set();
  document.getElementById("shareSearchInput").value = "";
  document.getElementById("shareModalList").innerHTML =
    '<div class="share-modal-empty">Loading…</div>';
  updateShareFooter();

  var nativeBtn = document.getElementById("shareNativeBtn");
  if (nativeBtn) nativeBtn.style.display = navigator.share ? "flex" : "none";

  document.getElementById("shareModalOverlay").classList.add("show");
  document.getElementById("shareModalPanel").classList.add("show");
  if (_shareOvY === null) {
    _shareOvY = window.pageYOffset || 0;
    _shareOvPrev = document.body.style.overflow;
  }
  document.body.style.overflow = "hidden";

  fetch(
    CCM_CFG.shareEndpoint
      ? CCM_CFG.shareEndpoint +
          (CCM_CFG.shareEndpoint.indexOf("?") === -1 ? "?" : "&") +
          "type=following"
      : _ccmUrl("type=following"),
  )
    .then(function (r) {
      return r.json();
    })
    .then(function (users) {
      _shareAllUsers = users || [];
      renderShareList(_shareAllUsers);
    })
    .catch(function () {
      document.getElementById("shareModalList").innerHTML =
        '<div class="share-modal-empty">Couldn\'t load your tracked people</div>';
    });
}

var _shareOvY = null,
  _shareOvPrev = "";
function closeShareModal() {
  document.getElementById("shareModalOverlay").classList.remove("show");
  document.getElementById("shareModalPanel").classList.remove("show");
  /* give the page its own lock back (Zeems keeps the body locked while it is open) and keep the page where it was */
  if (_shareOvY !== null) {
    document.body.style.overflow = _shareOvPrev || "";
    var _y = _shareOvY,
      _fixed = document.body.style.position === "fixed";
    _shareOvY = null;
    _shareOvPrev = "";
    if (!_fixed) {
      var _back = function () {
        if (Math.abs((window.pageYOffset || 0) - _y) > 2)
          window.scrollTo(0, _y);
      };
      _back();
      requestAnimationFrame(_back);
      setTimeout(_back, 60);
    }
  } else if (document.body.style.position !== "fixed") {
    document.body.style.overflow = "";
  }
  _shareMemeId = null;
}

function shareViaCopyLink() {
  var link = _shareGetLink();
  navigator.clipboard
    .writeText(link)
    .then(function () {
      ccmToast("🔗 Link copied");
      dzHaptic.success();
    })
    .catch(function () {
      var ta = document.createElement("textarea");
      ta.value = link;
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand("copy");
        ccmToast("🔗 Link copied");
      } catch (e) {
        ccmToast("Could not copy link");
      }
      ta.remove();
    });
}

function shareViaWhatsApp() {
  var text = encodeURIComponent(_shareGetCaption() + " — " + _shareGetLink());
  window.open("https://wa.me/?text=" + text, "_blank");
}

function shareViaTelegram() {
  var url = encodeURIComponent(_shareGetLink());
  var text = encodeURIComponent(_shareGetCaption());
  window.open("https://t.me/share/url?url=" + url + "&text=" + text, "_blank");
}

function shareViaTwitter() {
  var text = encodeURIComponent(_shareGetCaption());
  var url = encodeURIComponent(_shareGetLink());
  window.open(
    "https://twitter.com/intent/tweet?text=" + text + "&url=" + url,
    "_blank",
  );
}

function shareViaFacebook() {
  var url = encodeURIComponent(_shareGetLink());
  window.open("https://www.facebook.com/sharer/sharer.php?u=" + url, "_blank");
}

function shareViaEmail() {
  var subject = encodeURIComponent("Check this out on Zeems");
  var body = encodeURIComponent(_shareGetCaption() + "\n\n" + _shareGetLink());
  window.location.href = "mailto:?subject=" + subject + "&body=" + body;
}

function shareViaNative() {
  if (!navigator.share) return;
  navigator
    .share({
      title: "Zeems",
      text: _shareGetCaption(),
      url: _shareGetLink(),
    })
    .catch(function () {});
}

function renderShareList(users) {
  var list = document.getElementById("shareModalList");
  if (!users.length) {
    list.innerHTML =
      '<div class="share-modal-empty">You aren\'t tracking anyone yet</div>';
    return;
  }
  list.innerHTML = users
    .map(function (u) {
      var pic = u.profile_pic || "mee.jpg";
      var sel = _shareSelected.has(String(u.id));
      return (
        '<div class="share-user-row' +
        (sel ? " selected" : "") +
        '" data-uid="' +
        _ccmId(u.id) +
        '" ' +
        'onclick="toggleShareUser(' +
        _ccmId(u.id) +
        ', this)">' +
        '<img src="' +
        _ccmSafeUrl(pic, "mee.jpg") +
        '" onerror="this.src=\'mee.jpg\'">' +
        '<span class="share-user-name">' +
        _ccmEscapeHtml(u.username) +
        "</span>" +
        '<span class="share-user-check"><i class="ri-check-line"></i></span>' +
        "</div>"
      );
    })
    .join("");
}

function toggleShareUser(userId, el) {
  var key = String(userId);
  if (_shareSelected.has(key)) {
    _shareSelected.delete(key);
    el.classList.remove("selected");
  } else {
    _shareSelected.add(key);
    el.classList.add("selected");
  }
  updateShareFooter();
}

function updateShareFooter() {
  var n = _shareSelected.size;
  document.getElementById("shareSelCount").textContent = n + " selected";
  document.getElementById("shareSendBtn").disabled = n === 0;
}

function filterShareList() {
  var q = document.getElementById("shareSearchInput").value.toLowerCase();
  var filtered = _shareAllUsers.filter(function (u) {
    return u.username.toLowerCase().includes(q);
  });
  renderShareList(filtered);
}

function sendShare() {
  if (!_shareMemeId || _shareSelected.size === 0) return;
  var btn = document.getElementById("shareSendBtn");
  btn.disabled = true;
  btn.textContent = "Sending…";

  var ids = Array.from(_shareSelected).join(",");

  fetch(CCM_CFG.shareEndpoint || _ccmEp(), {
    method: "POST",
    credentials: "same-origin",
    headers: _ccmCsrfH({ "Content-Type": "application/x-www-form-urlencoded" }),
    body:
      "action=share_post&meme_id=" +
      encodeURIComponent(_shareMemeId) +
      "&receiver_ids=" +
      encodeURIComponent(ids) +
      _ccmCsrfQ(),
  })
    .then(function (r) {
      return r.json();
    })
    .then(function (data) {
      btn.disabled = false;
      btn.textContent = "Send";
      if (data.status === "ok") {
        var countEl = document.getElementById("ccm-share-count");
        if (countEl && countEl.dataset.hidden !== "1" && data.count !== null)
          countEl.textContent = data.count;

        var feedShareBtn = document.querySelector(
          '.share-btn[data-id="' + _shareMemeId + '"]',
        );
        if (feedShareBtn) {
          var fc = feedShareBtn.querySelector(".share-count");
          if (fc && fc.dataset.hidden !== "1" && data.count !== null)
            fc.textContent = data.count;
        }

        var feedItem = ccmFeed.find(function (s) {
          return (
            s.type === "post" && String(s.data.id) === String(_shareMemeId)
          );
        });
        if (feedItem && data.count !== null)
          feedItem.data.shareCount = data.count;

        ccmToast(
          "Shared with " +
            data.sent_to.length +
            " " +
            (data.sent_to.length === 1 ? "person" : "people"),
        );
        closeShareModal();
      } else {
        ccmToast(data.message || "Share failed");
      }
    })
    .catch(function () {
      btn.disabled = false;
      btn.textContent = "Send";
      ccmToast("Network error");
    });
}

/* load feed data — works whether this file is included before or after window load */
if (document.readyState === "complete") {
  _loadCcmData();
} else {
  window.addEventListener("load", function () {
    _loadCcmData();
  });
}

var ccmTouchStartY = 0;
var ccmTouchInCaption = false;
document.getElementById("cardCaptionModal").addEventListener(
  "touchstart",
  function (e) {
    ccmTouchInCaption = _ccmIsInsideExpandedCaption(e.target);
    if (ccmTouchInCaption) return;
    ccmTouchStartY = e.touches[0].clientY;
  },
  { passive: true },
);

document.getElementById("cardCaptionModal").addEventListener(
  "touchend",
  function (e) {
    if (ccmTouchInCaption) {
      ccmTouchInCaption = false;
      return;
    }
    if (_ccmHStack.length > 0) return;
    var diff = ccmTouchStartY - e.changedTouches[0].clientY;
    if (Math.abs(diff) > 40) {
      if (diff > 0) ccmLoadIndex(_ccmIndex + 1);
      else ccmLoadIndex(_ccmIndex - 1);
    }
  },
  { passive: true },
);

/* ══════════════════════════════════════════════════════════════
   SWIPE RIGHT TO CLOSE  —  dzSwipeClose (same behaviour as the other reels modals)
   One shared function; each modal passes its own settings:
     id        root element id                  isOpen()  is the modal open (and no linked post on top)
     blocked() true if the touch should be left alone (e.g. inside an expanded caption)
     carousel  {sel, countAttr, getIndex}       close()   what the back button calls
     abort()   optional: undo a half-started vertical drag        widthId  element whose width = "100%"
══════════════════════════════════════════════════════════════ */
function _dzSwipeClose(cfg) {
  var root = document.getElementById(cfg.id);
  if (!root || root._dzSwipeBound) return;
  root._dzSwipeBound = true;
  var st = null,
    busy = false;

  function reset() {
    root.style.transition = "";
    root.style.transform = "";
    root.style.willChange = "";
    root.style.borderRadius = "";
    root.style.boxShadow = "";
    root.style.overflow = "";
  }

  /* 1 + 2: listen on the whole document (capture), but only start for a clean touch that began inside the open modal */
  document.addEventListener(
    "touchstart",
    function (e) {
      st = null;
      if (busy) return;
      if (!e.touches || e.touches.length !== 1)
        return; /* two fingers → ignore */
      var t = e.target;
      if (!root.contains(t) || !cfg.isOpen()) return;
      if (
        t.closest &&
        t.closest(
          '.ccm-video-progress, input, textarea, [contenteditable="true"]',
        )
      )
        return; /* scrubber / text field */
      if (cfg.blocked && cfg.blocked(t)) return; /* e.g. expanded caption */
      if (cfg.carousel) {
        /* multi-photo past the first photo → only from the left edge */
        var car = document.querySelector(cfg.carousel.sel);
        if (car) {
          var count = parseInt(
            car.getAttribute(cfg.carousel.countAttr) || "1",
            10,
          );
          if (count > 1 && cfg.carousel.getIndex() > 0) {
            if (e.touches[0].clientX - car.getBoundingClientRect().left > 24)
              return;
          }
        }
      }
      var wEl = cfg.widthId ? document.getElementById(cfg.widthId) : null;
      st = {
        x0: e.touches[0].clientX,
        y0: e.touches[0].clientY,
        mode: "wait",
        curX: 0,
        curY: 0,
        w: (wEl && wEl.clientWidth) || root.clientWidth || window.innerWidth,
        samples: [{ t: performance.now(), x: 0 }],
      };
    },
    { capture: true, passive: true },
  );

  /* 3 + 4: decide the direction from the first few pixels, then follow the finger */
  document.addEventListener(
    "touchmove",
    function (e) {
      if (!st || st.mode === "ignore") return;
      var tp = e.touches && e.touches[0];
      if (!tp) return;
      var dx = tp.clientX - st.x0,
        dy = tp.clientY - st.y0,
        adx = Math.abs(dx),
        ady = Math.abs(dy);

      if (st.mode === "wait") {
        if (Math.max(adx, ady) < 8) return; /* wait for ~8px before choosing */
        if (dx < 0 || (ady > 10 && ady > 1.7 * adx)) {
          st.mode = "ignore";
          return;
        } /* left, or mostly vertical → back off */
        if (dx > 8 && ady <= 1.7 * adx) {
          /* right, up to ~60° from horizontal → take over */
          st.mode = "drag";
          if (cfg.abort) cfg.abort();
          root.style.transition = "none";
          root.style.willChange = "transform";
          root.style.overflow = "hidden";
        } else return;
      }

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      var x = Math.max(0, dx),
        y = dy * 0.85,
        k = Math.min(1, x / st.w);
      st.curX = x;
      st.curY = y;
      root.style.transform =
        "translate3d(" + x + "px," + y + "px,0) scale(" + (1 - 0.1 * k) + ")";
      root.style.borderRadius = 28 * Math.min(1, x / 120) + "px";
      root.style.boxShadow = x > 0 ? "-12px 0 40px rgba(0,0,0,.55)" : "";

      var now = performance.now(); /* sideways speed over the last ~100ms */
      st.samples.push({ t: now, x: x });
      while (st.samples.length > 2 && now - st.samples[0].t > 100)
        st.samples.shift();
    },
    { capture: true, passive: false },
  );

  /* 5: let go → close or spring back */
  function finish(e, cancelled) {
    if (!st) return;
    var s = st;
    st = null;
    if (s.mode !== "drag") return;
    e.stopPropagation();
    e.stopImmediatePropagation();
    if (e.cancelable) e.preventDefault();

    var first = s.samples[0],
      last = s.samples[s.samples.length - 1];
    var v =
      last.t > first.t
        ? (last.x - first.x) / (last.t - first.t)
        : 0; /* px per ms */
    var closeIt =
      !cancelled && (s.curX > s.w * 0.33 || (v > 0.5 && s.curX > 40));

    busy = true;
    if (closeIt) {
      root.style.transition =
        "transform .24s ease-out, border-radius .24s ease-out";
      root.style.transform =
        "translate3d(" + (s.w + 60) + "px," + s.curY + "px,0) scale(.9)";
      setTimeout(function () {
        cfg.close();
        reset();
        busy = false;
      }, 250);
    } else {
      root.style.transition =
        "transform .26s cubic-bezier(.22,1,.36,1), border-radius .26s ease, box-shadow .26s ease";
      root.style.transform = "translate3d(0,0,0) scale(1)";
      root.style.borderRadius = "0px";
      root.style.boxShadow = "none";
      setTimeout(function () {
        reset();
        busy = false;
      }, 270);
    }
  }
  document.addEventListener(
    "touchend",
    function (e) {
      finish(e, false);
    },
    { capture: true, passive: false },
  );
  document.addEventListener(
    "touchcancel",
    function (e) {
      finish(e, true);
    },
    { capture: true, passive: false },
  );
}
window.dzSwipeClose = window.dzSwipeClose || _dzSwipeClose;

/* FIX 1 — reset for a half-started vertical drag.
   Called by the photo swipe the moment it decides the drag is horizontal (and by swipe-to-close when it takes over).
   If the vertical drag had already committed, everything it faded / moved snaps straight back. */
function _ccmVDragAbort() {
  var wasCommitted = window._ccmVDragState ? window._ccmVDragState() : true;
  if (!wasCommitted) return;
  var track = document.getElementById("ccm-vtrack");
  if (!track) return;
  track.style.transition = "none";
  track.style.transform = "translateY(0)";
  var cur = track.querySelector('.ccm-vslide[data-pos="current"] .ccm-slide');
  _ccmOverlayEls(cur).forEach(function (el) {
    el.style.transition = "none";
  });
  _ccmSetOverlayProgress(
    cur,
    0,
    0,
  ); /* current slide: fully visible, no offset */
  ["next", "prev"].forEach(function (pos) {
    var n = track.querySelector(
      '.ccm-vslide[data-pos="' + pos + '"] .ccm-slide',
    );
    if (!n) return;
    _ccmOverlayEls(n).forEach(function (el) {
      el.style.transition = "none";
    });
    _ccmSetOverlayProgress(
      n,
      1,
      0,
    ); /* neighbours: back to their resting values */
  });
}
/* swipe-to-close also puts the photo carousel back where it was */
function _ccmSwipeCloseAbort() {
  _ccmVDragAbort();
  var g = document.querySelector("#ccm-slide #ccm-media-gallery");
  if (g) {
    g.style.transition = "";
    g.style.transform = "translateX(-" + _ccmMediaIndex * 100 + "%)";
  }
}

_dzSwipeClose({
  id: "cardCaptionModal",
  widthId: "ccm-stage",
  isOpen: function () {
    var m = document.getElementById("cardCaptionModal");
    return (
      !window.CCM_PAGE_MODE &&
      !!m &&
      m.classList.contains("ccm-open") &&
      _ccmHStack.length === 0
    );
  },
  blocked: function (t) {
    if (_ccmIsInsideExpandedCaption(t)) return true;
    /* suggested-users slide: past the first page a right swipe goes back a page; only on the first page it closes the player */
    var pg = document.querySelector("#ccm-slide .ccm-sugg-pages");
    return !!(pg && parseInt(pg.dataset.current || "0", 10) > 0);
  },
  carousel: {
    sel: "#ccm-slide #ccm-media-box",
    countAttr: "data-count",
    getIndex: function () {
      return _ccmMediaIndex;
    },
  },
  close: function () {
    closeCardCaptionModal();
  },
  abort: _ccmSwipeCloseAbort,
});

/* ══════════════════════════════════════════════════════════════
   SWIPE RIGHT inside a Connected Zeem (post B) → slides B away and goes back to the post that had the "view connected" button (post A)
   • B follows the finger, A is already underneath
   • past a third of the width, or a quick flick, B leaves and A is back; shorter and B springs back
   • with several photos, only from the left edge once you're past the first photo (same rule as swipe-to-close)
══════════════════════════════════════════════════════════════ */
(function _ccmInitConnectedSwipeBack() {
  var modal = document.getElementById("cardCaptionModal");
  if (!modal || modal._ccmConnBackBound) return;
  modal._ccmConnBackBound = true;
  var st = null,
    busy = false;

  function topLayer() {
    return _ccmHStack.length ? _ccmHStack[_ccmHStack.length - 1].layer : null;
  }

  document.addEventListener(
    "touchstart",
    function (e) {
      st = null;
      if (busy || !e.touches || e.touches.length !== 1) return;
      var layer = topLayer();
      if (!layer) return;
      var t = e.target;
      if (!layer.contains(t) || !modal.classList.contains("ccm-open")) return;
      if (
        t.closest &&
        t.closest(
          '.ccm-video-progress, input, textarea, [contenteditable="true"]',
        )
      )
        return;
      if (_ccmIsInsideExpandedCaption(t)) return;
      var box = layer.querySelector("#ccm-media-box");
      if (box) {
        var count = parseInt(box.getAttribute("data-count") || "1", 10);
        if (
          count > 1 &&
          _ccmMediaIndex > 0 &&
          e.touches[0].clientX - box.getBoundingClientRect().left > 24
        )
          return;
      }
      st = {
        x0: e.touches[0].clientX,
        y0: e.touches[0].clientY,
        mode: "wait",
        curX: 0,
        layer: layer,
        w: layer.clientWidth || window.innerWidth,
        samples: [{ t: performance.now(), x: 0 }],
      };
    },
    { capture: true, passive: true },
  );

  document.addEventListener(
    "touchmove",
    function (e) {
      if (!st || st.mode === "ignore") return;
      var tp = e.touches && e.touches[0];
      if (!tp) return;
      var dx = tp.clientX - st.x0,
        dy = tp.clientY - st.y0,
        adx = Math.abs(dx),
        ady = Math.abs(dy);
      if (st.mode === "wait") {
        if (Math.max(adx, ady) < 8) return;
        if (dx <= 0 || ady > adx * 0.9) {
          st.mode = "ignore";
          return;
        } /* left, or mostly vertical → not ours */
        st.mode = "drag";
        st.layer.style.transition = "none";
        var g =
          st.layer.querySelector(
            "#ccm-media-gallery",
          ); /* keep a photo carousel from also moving */
        if (g) {
          g.style.transition = "none";
          g.style.transform = "translateX(-" + _ccmMediaIndex * 100 + "%)";
        }
      }
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      var x = Math.min(st.w, Math.max(0, dx));
      st.curX = x;
      st.layer.style.transform = "translate3d(" + x + "px,0,0)";
      var now = performance.now();
      st.samples.push({ t: now, x: x });
      while (st.samples.length > 2 && now - st.samples[0].t > 100)
        st.samples.shift();
    },
    { capture: true, passive: false },
  );

  function finish(e, cancelled) {
    if (!st) return;
    var s = st;
    st = null;
    if (s.mode !== "drag") return;
    e.stopPropagation();
    e.stopImmediatePropagation();
    if (e.cancelable) e.preventDefault();
    var g = s.layer.querySelector("#ccm-media-gallery");
    if (g) g.style.transition = "";
    var first = s.samples[0],
      last = s.samples[s.samples.length - 1];
    var v = last.t > first.t ? (last.x - first.x) / (last.t - first.t) : 0;
    var back = !cancelled && (s.curX > s.w * 0.33 || (v > 0.45 && s.curX > 40));
    busy = true;
    if (back) {
      ccmCloseConnectedZeem(); /* slides B out from where the finger left it, A is shown again */
      setTimeout(function () {
        busy = false;
      }, 380);
    } else {
      s.layer.style.transition = "transform .26s cubic-bezier(.22,1,.36,1)";
      s.layer.style.transform = "translate3d(0,0,0)";
      setTimeout(function () {
        busy = false;
      }, 280);
    }
  }
  document.addEventListener(
    "touchend",
    function (e) {
      finish(e, false);
    },
    { capture: true, passive: false },
  );
  document.addEventListener(
    "touchcancel",
    function (e) {
      finish(e, true);
    },
    { capture: true, passive: false },
  );
})();

/* ══════════════════════════════════════════════════════════════
   SWIPE LEFT → OPEN THE POSTER'S PROFILE (TikTok style)
   • the whole post follows the finger to the left while a "peek" panel (avatar + name) slides in from the right
   • let go past ~28% of the width, or flick it, and the panel finishes covering the screen, then profile.php opens
   • let go sooner and everything springs back
   • a haptic tick when you cross the point of no return (Android)
   • multi-photo posts keep their own swipe: the profile gesture only works on the LAST photo
   • works on ads too (opens the advertiser's profile)
   • not used on suggested-user slides, in linked (Connected) posts, in an opened caption or on the video scrubber
   • profile page prefetch is optional — set CCM_PROFILE_PREFETCH = false if profile.php counts a view on every GET
══════════════════════════════════════════════════════════════ */
var CCM_PROFILE_PREFETCH = true;
function CCM_PROFILE_URL(uid) {
  return "profile.php?user_id=" + encodeURIComponent(uid);
}

(function _ccmInitSwipeToProfile() {
  var modal = document.getElementById("cardCaptionModal");
  var stage = document.getElementById("ccm-stage");
  if (!modal || !stage || modal._ccmProfSwipeBound) return;
  modal._ccmProfSwipeBound = true;

  var st = null,
    busy = false,
    peek = null,
    resetTimer = null;

  function moving() {
    return [
      document.getElementById("ccm-inner"),
      document.getElementById("ccm-fixed-topbar"),
      document.getElementById("ccmFixedBackBtn"),
    ];
  }
  function setTransition(v) {
    moving().forEach(function (el) {
      if (el) el.style.transition = v;
    });
    if (peek) peek.style.transition = v;
  }
  function apply(x, w) {
    moving().forEach(function (el) {
      if (el) el.style.transform = "translate3d(" + -x + "px,0,0)";
    });
    if (peek) {
      peek.style.transform = "translate3d(" + (w - x) + "px,0,0)";
      peek.style.setProperty("--k", Math.min(1, x / w));
    }
  }
  function reset() {
    clearTimeout(resetTimer);
    moving().forEach(function (el) {
      if (el) {
        el.style.transition = "";
        el.style.transform = "";
      }
    });
    if (peek) {
      peek.style.transition = "";
      peek.style.transform = "";
      peek.style.setProperty("--k", 0);
      peek.classList.remove("ccm-peek-on", "ccm-peek-loading");
    }
    busy = false;
  }
  function ensurePeek() {
    if (peek && peek.parentNode) return peek;
    peek = document.createElement("div");
    peek.id = "ccm-peek";
    stage.appendChild(peek);
    return peek;
  }
  var frame = null,
    frameUid = null;
  function ensureFrame(p) {
    if (!CCM_PROFILE_PREFETCH || !peek) return;
    if (frame && frame.parentNode && frameUid === p.userId)
      return; /* already loading / loaded for this person */
    if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
    frameUid = p.userId;
    frame = document.createElement("iframe");
    frame.className = "ccm-peek-frame";
    frame.setAttribute("tabindex", "-1");
    frame.setAttribute("aria-hidden", "true");
    var myFrame = frame,
      polls = 0;
    function ready() {
      myFrame.classList.add("ccm-ready");
    }
    myFrame.addEventListener("load", ready);
    /* show it as soon as the page has its first content, don't wait for every image to finish */
    (function poll() {
      if (
        !myFrame.parentNode ||
        myFrame.classList.contains("ccm-ready") ||
        ++polls > 200
      )
        return;
      try {
        var d = myFrame.contentDocument;
        if (
          d &&
          d.readyState !== "loading" &&
          d.body &&
          d.body.children.length > 2 &&
          d.location.href !== "about:blank"
        ) {
          ready();
          return;
        }
      } catch (_) {}
      setTimeout(poll, 50);
    })();
    frame.src =
      CCM_PROFILE_URL(p.userId) +
      "&dz_peek=1"; /* dz_peek: profile.php does not count this as a visit */
    peek.appendChild(frame);
  }
  function dropFrame() {
    if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
    frame = null;
    frameUid = null;
  }
  /* Load the poster's profile in the hidden panel BEFORE the swipe, so it is already drawn the moment you start dragging.
       Waits a split second after you land on a post, so flicking through posts doesn't load a profile for each one. */
  var warmTimer = null;
  window._ccmWarmProfile = function () {
    clearTimeout(warmTimer);
    if (!CCM_PROFILE_PREFETCH) return;
    warmTimer = setTimeout(function () {
      if (
        !modal.classList.contains("ccm-open") ||
        busy ||
        (st && st.mode === "drag")
      )
        return;
      var p = curPost();
      if (!p || !p.userId) return;
      ensurePeek();
      ensureFrame(p);
    }, 120);
  };
  function fillPeek(p) {
    var keep =
      frame && frame.parentNode === peek && frameUid === p.userId
        ? frame
        : null;
    if (keep) peek.removeChild(keep);
    peek.innerHTML =
      '<div class="ccm-peek-inner">' +
      '<img class="ccm-peek-pic" src="' +
      _ccmSafeUrl(p.pic, "mee.jpg") +
      '" onerror="this.onerror=null;this.src=\'mee.jpg\'">' +
      '<div class="ccm-peek-name">' +
      _ccmEscapeHtml(p.username || "") +
      "</div>" +
      '<div class="ccm-peek-line l1"></div>' +
      '<div class="ccm-peek-line l2"></div>' +
      '<div class="ccm-peek-spin"></div>' +
      "</div>";
    if (keep) peek.appendChild(keep);
  }
  function curPost() {
    var it = ccmFeed[_ccmIndex];
    return it && it.type === "post" ? it.data : null;
  }
  function prefetch(uid) {
    var href = CCM_PROFILE_URL(uid);
    if (
      document.querySelector(
        'link[rel="prefetch"][data-ccm-prof="' + uid + '"]',
      )
    )
      return;
    var l = document.createElement("link");
    l.rel = "prefetch";
    l.href = href;
    l.setAttribute("data-ccm-prof", uid);
    document.head.appendChild(l);
  }

  document.addEventListener(
    "touchstart",
    function (e) {
      st = null;
      if (window.CCM_PAGE_MODE || busy || !e.touches || e.touches.length !== 1)
        return; /* zeems.php page: no swipe to profile */
      var t = e.target;
      if (!modal.contains(t) || !modal.classList.contains("ccm-open")) return;
      if (_ccmHStack.length > 0 || _ccmVSwipeLocked) return;
      if (
        t.closest &&
        t.closest(
          '.ccm-video-progress, input, textarea, [contenteditable="true"]',
        )
      )
        return;
      if (_ccmIsInsideExpandedCaption(t)) return;
      var p = curPost();
      if (!p || !p.userId) return;
      /* several photos / videos in one post: the sideways swipe belongs to the gallery until the last one */
      var mb = document.querySelector("#ccm-slide #ccm-media-box");
      var count = mb ? parseInt(mb.getAttribute("data-count") || "1", 10) : 1;
      if (count > 1 && _ccmMediaIndex < count - 1) return;
      var tp = e.touches[0];
      ensurePeek();
      ensureFrame(p); /* fallback: if it isn't loading yet, start now */
      st = {
        x0: tp.clientX,
        y0: tp.clientY,
        mode: "wait",
        curX: 0,
        p: p,
        w: stage.clientWidth || window.innerWidth,
        samples: [{ t: performance.now(), x: 0 }],
        ticked: false,
        prefetched: false,
      };
    },
    { capture: true, passive: true },
  );

  document.addEventListener(
    "touchmove",
    function (e) {
      if (!st || st.mode === "ignore") return;
      var tp = e.touches && e.touches[0];
      if (!tp) return;
      var dx = tp.clientX - st.x0,
        dy = tp.clientY - st.y0,
        adx = Math.abs(dx),
        ady = Math.abs(dy);

      if (st.mode === "wait") {
        if (Math.max(adx, ady) < 8)
          return; /* decide from the first few pixels */
        if (dx >= 0 || ady > adx * 0.8) {
          st.mode = "ignore";
          return;
        } /* right, or mostly vertical → not ours */
        st.mode = "drag";
        if (typeof _ccmVDragAbort === "function")
          _ccmVDragAbort(); /* cancel any vertical drag that began a moment earlier */
        ensurePeek();
        fillPeek(st.p);
        ensureFrame(st.p);
        peek.classList.add("ccm-peek-on");
        setTransition("none");
        var g = document.querySelector("#ccm-slide #ccm-media-gallery");
        if (g) {
          g.style.transition = "none";
          g.style.transform = "translateX(-" + _ccmMediaIndex * 100 + "%)";
        }
      }

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      var x = Math.min(st.w, Math.max(0, -dx));
      st.curX = x;
      apply(x, st.w);

      var commitX = st.w * 0.28;
      if (x >= commitX && !st.ticked) {
        st.ticked = true;
        try {
          if (navigator.vibrate) navigator.vibrate(8);
        } catch (_) {}
      } else if (x < commitX && st.ticked) {
        st.ticked = false;
      }

      var now = performance.now(); /* leftward speed over the last ~100ms */
      st.samples.push({ t: now, x: x });
      while (st.samples.length > 2 && now - st.samples[0].t > 100)
        st.samples.shift();
    },
    { capture: true, passive: false },
  );

  function finish(e, cancelled) {
    if (!st) return;
    var s = st;
    st = null;
    if (s.mode !== "drag") return;
    e.stopPropagation();
    e.stopImmediatePropagation();
    if (e.cancelable) e.preventDefault();

    var g = document.querySelector("#ccm-slide #ccm-media-gallery");
    if (g) g.style.transition = "";

    var first = s.samples[0],
      last = s.samples[s.samples.length - 1];
    var v =
      last.t > first.t
        ? (last.x - first.x) / (last.t - first.t)
        : 0; /* px per ms, positive = leftwards */
    var go = !cancelled && (s.curX > s.w * 0.28 || (v > 0.45 && s.curX > 40));

    busy = true;
    if (go) {
      /* stop the sound / video right away so nothing keeps playing while the page loads */
      modal.querySelectorAll("video").forEach(function (vd) {
        try {
          vd.pause();
        } catch (_) {}
      });
      _ccmPauseSoundtrack();
      setTransition("transform .16s cubic-bezier(.22,.8,.3,1)");
      apply(s.w, s.w);
      peek.classList.add("ccm-peek-loading");
      try {
        sessionStorage.setItem(
          "dzProfileEnter",
          JSON.stringify({ from: "zeems", t: Date.now() }),
        );
      } catch (_) {}
      /* start loading the profile RIGHT NOW — the slide-out plays while the request is already on its way */
      window.location.href = CCM_PROFILE_URL(s.p.userId);
      resetTimer = setTimeout(
        reset,
        10000,
      ); /* safety net if the navigation never happens */
    } else {
      setTransition("transform .26s cubic-bezier(.22,1,.36,1)");
      apply(0, s.w);
      setTimeout(reset, 280);
    }
  }
  document.addEventListener(
    "touchend",
    function (e) {
      finish(e, false);
    },
    { capture: true, passive: false },
  );
  document.addEventListener(
    "touchcancel",
    function (e) {
      finish(e, true);
    },
    { capture: true, passive: false },
  );

  new MutationObserver(function () {
    if (!modal.classList.contains("ccm-open")) {
      clearTimeout(warmTimer);
      dropFrame();
    }
  }).observe(modal, { attributes: true, attributeFilter: ["class"] });

  /* coming BACK from the profile (browser back-forward cache): put everything back and carry on playing the same post */
  window.addEventListener("pageshow", function (e) {
    if (!e.persisted) return;
    reset();
    if (modal.classList.contains("ccm-open")) {
      var cs = document.getElementById("ccm-slide");
      var paused = !!(_ccmMemeId && _ccmPausedMemeIds[_ccmMemeId]);
      if (cs) ccmUpdateMediaGallery(cs, paused);
      if (!paused) _ccmResumeSoundtrack();
    }
  });
})();

/* ══════════ SIDE RAIL (wide screens): mirror the current post's rail into the column beside the box ══════════ */
(function () {
  var last = null;
  function sync() {
    var host = document.getElementById("ccm-side-rail");
    if (!host) return;
    var wide =
      window.matchMedia && window.matchMedia("(min-width:791px)").matches;
    var src = wide ? document.querySelector("#ccm-slide .ccm-rail") : null;
    var html = src ? src.innerHTML : "";
    if (html === last) return;
    last = html;
    host.innerHTML = html;
    [].forEach.call(host.querySelectorAll("[id]"), function (n) {
      n.removeAttribute("id");
    }); /* ids stay unique: the originals live on the post */
  }
  setInterval(sync, 150);
  window.addEventListener("resize", function () {
    last = null;
    sync();
  });
  window.ccmSyncSideRail = function () {
    last = null;
    sync();
  };
})();

/* ══════════ COMMENTS — rail button opens the shared comment sheet (comment_section.php) for this post ══════════ */
var _ccmCommentCounts = {},
  _ccmCommentOff = {};
function _ccmCmtFmt(n) {
  n = +n || 0;
  return n >= 1e6
    ? (n / 1e6).toFixed(1).replace(".0", "") + "M"
    : n >= 1e3
      ? (n / 1e3).toFixed(1).replace(".0", "") + "K"
      : String(n);
}
function ccmPaintCommentCount(postId) {
  var sl = document.getElementById("ccm-slide");
  if (!sl) return;
  var el = sl.querySelector("#ccm-comment-count");
  if (!el) return;
  var n = _ccmCommentCounts[postId],
    off = !!_ccmCommentOff[postId];
  var btn = sl.querySelector("#ccm-comment-btn");
  if (btn) btn.classList.toggle("ccm-cm-off", off);
  el.textContent = off ? "" : _ccmCmtFmt(n || 0);
  el.setAttribute("data-hidden", off ? "1" : "0");
}
function ccmLoadCommentCount(postId) {
  if (!postId) return;
  if (_ccmCommentCounts[postId] !== undefined) ccmPaintCommentCount(postId);
  if (
    !(
      window.CommentSection &&
      (window.CommentSection.status || window.CommentSection.counts)
    )
  )
    return;
  var req = window.CommentSection.status
    ? window.CommentSection.status([postId])
    : window.CommentSection.counts([postId]).then(function (c) {
        return { counts: c, off: {} };
      });
  req
    .then(function (r) {
      _ccmCommentCounts[postId] = +(r.counts || {})[postId] || 0;
      _ccmCommentOff[postId] = !!(r.off || {})[postId];
      if (String(_ccmMemeId) === String(postId)) ccmPaintCommentCount(postId);
    })
    .catch(function () {});
}
window.addEventListener("cs:changed", function (e) {
  var d = e.detail || {};
  if (!d.meme) return;
  _ccmCommentCounts[d.meme] = Math.max(
    0,
    (_ccmCommentCounts[d.meme] || 0) + d.delta,
  );
  if (String(_ccmMemeId) === String(d.meme)) ccmPaintCommentCount(d.meme);
});
/* the video / soundtrack of the post being commented on */
function _ccmCmMedia() {
  var cur = ccmFsCurrentMedia(),
    video = cur && cur.tagName === "VIDEO" ? cur : null;
  var el = document.getElementById("ccmSoundtrackPlayer");
  var audio =
    el &&
    el.src &&
    el.dataset.memeId &&
    String(el.dataset.memeId) === String(_ccmMemeId)
      ? el
      : null;
  return { video: video, audio: audio };
}
function _ccmCmIcons() {
  var ctl = document.querySelector("#ccm-slide .ccm-cm-ctl");
  if (!ctl) return;
  var m = _ccmCmMedia(),
    main = m.video || m.audio;
  if (!main) return;
  var pb = ctl.querySelector('[data-cm="pp"]'),
    mb = ctl.querySelector('[data-cm="mute"]');
  var pIc = main.paused ? "play" : "pause",
    mIc = (m.audio || m.video || {}).muted ? "mute" : "volume";
  if (pb && pb._ic !== pIc) {
    pb._ic = pIc;
    pb.innerHTML = _ccmRdIco(pIc);
  }
  if (mb && mb._ic !== mIc) {
    mb._ic = mIc;
    mb.innerHTML = _ccmRdIco(mIc);
  }
}
/* the comment-sheet buttons use solid, Reddit-style glyphs */
function _ccmRdIco(n) {
  var a =
    '<svg class="ccm-ico rd' +
    (n === "pause" || n === "play" ? " pp" : "") +
    '" viewBox="0 0 24 24" aria-hidden="true">';
  if (n === "pause")
    return (
      a +
      '<rect x="4.6" y="3.4" width="5.6" height="17.2" rx="1.7"/><rect x="13.8" y="3.4" width="5.6" height="17.2" rx="1.7"/></svg>'
    );
  if (n === "play")
    return (
      a +
      '<path d="M7.2 4.6c0-1.1 1.2-1.7 2.1-1.1l9.4 6.4c.8.5.8 1.6 0 2.2l-9.4 6.4c-.9.6-2.1 0-2.1-1.1z"/></svg>'
    );
  var a2 = a.replace('viewBox="0 0 24 24"', 'viewBox="261 218 681 603"');
  if (n === "volume")
    return (
      a2 +
      '<path fill-rule="evenodd" d="M576.2,820.0C560.6,817.2 551.8,812.4 515.6,787.7C500.1,777.2 474.4,759.8 458.5,749.0C442.6,738.3 425.4,726.5 420.5,722.9C415.6,719.3 405.2,712.2 397.5,707.0C381.1,696.0 346.4,671.6 333.5,661.9C314.1,647.4 292.3,621.5 281.6,600.2C258.9,555.2 255.1,512.3 270.0,467.9C277.7,444.5 282.6,435.5 299.8,412.0C309.3,399.1 335.0,374.5 346.8,367.0C365.5,355.1 391.4,337.8 401.0,330.6C412.7,322.0 438.1,304.3 460.9,289.0C470.3,282.7 487.8,270.8 499.8,262.6C559.6,221.4 567.2,217.6 589.5,217.6C628.4,217.6 651.1,243.1 654.0,290.0C655.3,310.7 655.3,742.7 654.0,756.0C649.5,802.0 618.5,827.6 576.2,820.0ZM747.9,729.0C732.2,726.4 723.6,712.1 725.3,691.7C727.0,671.8 735.1,664.0 760.3,658.0C794.4,649.8 822.2,631.8 843.7,603.9C862.9,578.9 876.0,533.5 872.1,504.8C869.5,485.1 856.5,452.0 846.5,439.5C819.9,406.3 796.9,390.9 760.7,382.0C736.8,376.1 727.9,367.8 724.9,348.7C721.1,324.7 735.0,309.3 758.5,311.4C807.1,315.8 864.9,348.4 895.2,388.5C915.9,416.0 920.6,425.4 936.6,471.0C943.6,491.1 944.3,540.8 938.0,565.7C923.1,623.6 884.6,673.9 832.7,702.9C803.4,719.3 763.6,731.6 747.9,729.0ZM738.0,584.8C732.1,581.6 727.6,574.3 726.0,565.2C724.3,556.1 724.4,479.3 726.1,472.4C737.4,425.3 805.6,472.9 801.7,525.2C799.0,559.4 759.6,596.3 738.0,584.8Z"/></svg>'
    );
  return (
    a2 +
    '<path d="M576.2,820.0C560.6,817.2 551.8,812.4 515.6,787.7C500.1,777.2 474.4,759.8 458.5,749.0C442.6,738.3 425.4,726.5 420.5,722.9C415.6,719.3 405.2,712.2 397.5,707.0C381.1,696.0 346.4,671.6 333.5,661.9C314.1,647.4 292.3,621.5 281.6,600.2C258.9,555.2 255.1,512.3 270.0,467.9C277.7,444.5 282.6,435.5 299.8,412.0C309.3,399.1 335.0,374.5 346.8,367.0C365.5,355.1 391.4,337.8 401.0,330.6C412.7,322.0 438.1,304.3 460.9,289.0C470.3,282.7 487.8,270.8 499.8,262.6C559.6,221.4 567.2,217.6 589.5,217.6C628.4,217.6 651.1,243.1 654.0,290.0C655.3,310.7 655.3,742.7 654.0,756.0C649.5,802.0 618.5,827.6 576.2,820.0Z"/><path d="M742 430L915 625M915 430L742 625" fill="none" stroke="#fff" stroke-width="58" stroke-linecap="round"/></svg>'
  );
}
/* warm-up (once, when the browser is idle): parse the button icons and draw the comment sheet unseen, so the FIRST open of the comments is as fast as every later one */
(function () {
  function w() {
    try {
      var d = document.createElement("div");
      d.setAttribute("aria-hidden", "true");
      d.style.cssText =
        "position:absolute;left:-9999px;top:0;width:0;height:0;overflow:hidden";
      d.innerHTML = ["pause", "play", "volume", "mute"].map(_ccmRdIco).join("");
      document.body.appendChild(d);
      setTimeout(function () {
        d.remove();
      }, 600);
    } catch (e) {}
    try {
      if (window.CommentSection && window.CommentSection.warm)
        window.CommentSection.warm();
    } catch (e) {}
  }
  if ("requestIdleCallback" in window)
    requestIdleCallback(w, { timeout: 3000 });
  else setTimeout(w, 1500);
})();
function _ccmCmBuild() {
  var slide = document.getElementById("ccm-slide");
  if (!slide) return;
  var old = slide.querySelector(".ccm-cm-ctl");
  if (old) old.remove();
  var m = _ccmCmMedia();
  if (!m.video && !m.audio) return;
  var ctl = document.createElement("div");
  ctl.className = "ccm-cm-ctl";
  ctl.innerHTML =
    '<button type="button" data-cm="mute" aria-label="Mute"></button><button type="button" data-cm="pp" aria-label="Pause"></button>';
  ["touchstart", "touchend", "pointerdown", "mousedown"].forEach(function (ev) {
    ctl.addEventListener(ev, function (e) {
      e.stopPropagation();
    });
  });
  ctl.querySelector('[data-cm="pp"]').addEventListener("click", function (e) {
    e.stopPropagation();
    var mm = _ccmCmMedia(),
      main = mm.video || mm.audio,
      box = slide.querySelector(".ccm-media-box");
    if (!main) return;
    if (main.paused) _ccmResumeMedia(box, mm.video, mm.audio, true);
    else _ccmPauseMedia(box, mm.video, mm.audio);
    _ccmCmIcons();
  });
  ctl.querySelector('[data-cm="mute"]').addEventListener("click", function (e) {
    e.stopPropagation();
    var mm = _ccmCmMedia(),
      media = mm.audio || mm.video;
    if (!media) return;
    if (media._dzGateRelease) media._dzGateRelease();
    var nowMuted = !media.muted;
    if (mm.audio) mm.audio.muted = nowMuted;
    if (mm.video) mm.video.muted = mm.audio ? true : nowMuted;
    if (!mm.audio && _ccmMemeId) _ccmMutedPref[_ccmMemeId] = nowMuted;
    _ccmCmIcons();
  });
  var _bx = slide.querySelector(".ccm-media-box");
  (_bx || slide).appendChild(ctl);
  _ccmCmIcons(); /* sits on the picture itself, bottom-right */
}
(function () {
  var ro = null,
    tick = 0,
    post = null;
  var probe = null,
    fitTry = 0;
  function topPx() {
    if (!probe) {
      probe = document.createElement("div");
      probe.style.cssText =
        "position:fixed;left:0;top:0;width:0;height:0;visibility:hidden;padding-top:env(safe-area-inset-top,0px)";
      document.body.appendChild(probe);
    }
    return parseFloat(getComputedStyle(probe).paddingTop) || 0;
  }
  function mediaAspect() {
    var sl = document.getElementById("ccm-slide");
    if (!sl) return 0;
    var idx = typeof _ccmMediaIndex !== "undefined" ? _ccmMediaIndex : 0;
    var el =
      sl.querySelector('.ccm-media-item[data-idx="' + idx + '"] .ccm-media') ||
      sl.querySelector(".ccm-media-box .ccm-media");
    if (!el) return 0;
    var w = el.tagName === "VIDEO" ? el.videoWidth : el.naturalWidth,
      h = el.tagName === "VIDEO" ? el.videoHeight : el.naturalHeight;
    return w && h ? w / h : 0;
  }
  /* size the floating picture to its own shape inside the space above the sheet */
  function fit(late) {
    var m = document.getElementById("cardCaptionModal"),
      sl = document.getElementById("ccm-slide");
    if (!m || !sl || !m.classList.contains("ccm-commenting")) return;
    var ar = mediaAspect();
    if (!ar) {
      m.classList.remove("ccm-cm-fit");
      if (fitTry++ < 12) setTimeout(fit, 150);
      return;
    }
    fitTry = 0;
    var cmh = parseFloat(m.style.getPropertyValue("--ccm-cm-h")) || 0,
      G = 6,
      top = topPx() + G,
      side = 16;
    /* measure the sheet where it really is, so the gap above the picture and the gap below it match on every page */
    var sh = document.getElementById("csSheet"),
      sr = sl.getBoundingClientRect(),
      shr = sh ? sh.getBoundingClientRect() : null;
    var sheetTop = sl.clientHeight - cmh;
    if (shr && shr.height > 20) {
      /* where the sheet will REST (its slide-in animation has just started, so its live position is useless): live top minus the translateY it is still carrying */
      var ty = 0;
      try {
        var tf = getComputedStyle(sh).transform;
        if (tf && tf !== "none") {
          var mm = tf.match(/matrix(3d)?\(([^)]+)\)/);
          if (mm) {
            var v = mm[2].split(",").map(parseFloat);
            ty = mm[1] ? v[13] : v[5];
          }
        }
      } catch (x) {}
      if (m.classList.contains("ccm-cm-dragging"))
        ty = 0; /* finger on the sheet: follow where it really is, frame by frame */
      var rt = shr.top - ty - sr.top;
      if (rt > 20 && rt < sl.clientHeight) sheetTop = rt;
    }
    var availH = sheetTop - top - G,
      availW = sl.clientWidth - side * 2;
    if (availH < 80 || availW < 80) return;
    var w = Math.min(availW, availH * ar),
      h = w / ar;
    m.style.setProperty("--ccm-cm-bw", Math.round(w) + "px");
    m.style.setProperty("--ccm-cm-bh", Math.round(h) + "px");
    m.style.setProperty(
      "--ccm-cm-bt",
      Math.round(top + (availH - h) / 2) + "px",
    );
    m.classList.add("ccm-cm-fit");
    if (!late) {
      clearTimeout(fit._t);
      fit._t = setTimeout(function () {
        fit(true);
      }, 380);
    } /* once more after the sheet has settled */
  }
  function setH() {
    var sh = document.getElementById("csSheet"),
      m = document.getElementById("cardCaptionModal");
    if (sh && m) {
      m.style.setProperty("--ccm-cm-h", sh.offsetHeight + "px");
      fit();
    }
  }
  /* the sheet is being dragged with a finger: the picture follows it frame by frame, then settles */
  window.addEventListener("cs:drag", function (e) {
    var d = e.detail || {},
      m = document.getElementById("cardCaptionModal");
    if (
      !m ||
      !m.classList.contains("ccm-commenting") ||
      m.classList.contains("ccm-cm-closing")
    )
      return;
    if (d.dragging) {
      m.classList.add("ccm-cm-dragging");
      m.style.setProperty("--ccm-cm-h", Math.max(0, d.h) + "px");
      fit();
    } else {
      m.classList.remove("ccm-cm-dragging");
      requestAnimationFrame(setH);
    }
  });
  var closeT = 0;
  function cmFinish() {
    clearTimeout(closeT);
    var m = document.getElementById("cardCaptionModal");
    if (!m) return;
    [
      "ccm-commenting",
      "ccm-cm-dragging",
      "ccm-cm-fit",
      "ccm-cm-closing",
    ].forEach(function (c) {
      m.classList.remove(c);
    });
    var c = document.querySelector("#ccm-slide .ccm-cm-ctl");
    if (c) c.remove();
    try {
      var cur = ccmFsCurrentMedia();
      if (cur) ccmFixObjectFit(cur);
    } catch (x) {}
  }
  window.addEventListener("cs:state", function (e) {
    var d = e.detail || {},
      m = document.getElementById("cardCaptionModal");
    if (!m) return;
    if (d.open && d.docked && m.classList.contains("ccm-open") && d.side) {
      /* laptop side panel: the picture is not squeezed, nothing steps aside; only a change of post closes the panel */
      m.classList.add("ccm-cs-side");
      post = String(d.meme);
      clearInterval(tick);
      tick = setInterval(function () {
        if (String(_ccmMemeId) !== post) {
          try {
            window.CommentSection.close();
          } catch (x) {}
        }
      }, 250);
      return;
    }
    if (d.open && d.docked && m.classList.contains("ccm-open")) {
      clearTimeout(closeT);
      m.classList.remove("ccm-cm-closing");
      post = String(d.meme);
      m.classList.add(
        "ccm-commenting",
      ); /* first: so the picture starts moving in the SAME frame as the sheet */
      setH();
      _ccmCmBuild();
      var sh = document.getElementById("csSheet");
      if (window.ResizeObserver && sh) {
        ro = ro || new ResizeObserver(setH);
        ro.disconnect();
        ro.observe(sh);
      }
      clearInterval(tick);
      tick = setInterval(function () {
        _ccmCmIcons();
        if (String(_ccmMemeId) !== post) {
          try {
            window.CommentSection.close();
          } catch (x) {}
        } /* moved to another post → the sheet goes */
      }, 250);
    } else if (!d.open) {
      clearInterval(tick);
      if (ro) ro.disconnect();
      post = null;
      if (m.classList.contains("ccm-cs-side")) {
        m.classList.remove("ccm-cs-side");
        return;
      }
      var sl = document.getElementById("ccm-slide");
      if (m.classList.contains("ccm-commenting") && sl) {
        /* the picture grows back to full screen while the sheet slides away, then the extra classes go */
        m.classList.add("ccm-cm-closing");
        m.classList.remove("ccm-cm-dragging");
        m.style.setProperty("--ccm-cm-h", "0px");
        if (m.classList.contains("ccm-cm-fit")) {
          m.style.setProperty("--ccm-cm-bw", sl.clientWidth + "px");
          m.style.setProperty("--ccm-cm-bh", sl.clientHeight + "px");
          m.style.setProperty("--ccm-cm-bt", "0px");
        }
        clearTimeout(closeT);
        closeT = setTimeout(cmFinish, 270);
      } else cmFinish();
    }
  });
})();
/* who/what the sheet shows above the comments: the post's owner, date, views and live counts (no caption) */
function _ccmCmHeader(postId) {
  var item = null;
  for (var i = 0; i < ccmFeed.length; i++) {
    if (
      ccmFeed[i].type === "post" &&
      ccmFeed[i].data &&
      String(ccmFeed[i].data.id) === String(postId)
    ) {
      item = ccmFeed[i];
      break;
    }
  }
  var p = item ? item.data : null;
  if (!p) return null;
  var d = null,
    date = "";
  if (p.time) {
    d = new Date(String(p.time).replace(" ", "T"));
    if (!isNaN(d))
      date =
        String(d.getHours()).padStart(2, "0") +
        ":" +
        String(d.getMinutes()).padStart(2, "0") +
        " · " +
        String(d.getDate()).padStart(2, "0") +
        "/" +
        String(d.getMonth() + 1).padStart(2, "0") +
        "/" +
        d.getFullYear();
  }
  var views = p.views && +p.views > 0 ? ccmFmtViews(p.views) : "";
  function txt(id) {
    var sl = document.getElementById("ccm-slide"),
      e = sl ? sl.querySelector("#" + id) : null;
    return e ? e.textContent : "";
  }
  function on(id) {
    var sl = document.getElementById("ccm-slide"),
      e = sl ? sl.querySelector("#" + id) : null;
    return !!(e && e.classList.contains("active"));
  }
  return {
    collab: p.collab && p.collab.list && p.collab.list.length ? p.collab : null,
    userId: p.userId,
    username: p.username,
    isVerified: !!p.isVerified,
    nickname: p.nickname || "",
    pic: p.pic && !/\/$/.test(String(p.pic)) ? p.pic : "mee.jpg",
    isOwn: String(p.userId) === String(currentUserId),
    isFollowing: !!p.isFollowing,
    date: date,
    views: views,
    profile: function () {
      location.href = "profile.php?user_id=" + p.userId;
    },
    track: function (btn) {
      ccmToggleTrack(btn);
    },
    act: function (k) {
      if (k === "share") openShareModal(p.id);
      else ccmAction(k);
    },
    get: function () {
      return {
        comments: _ccmCommentCounts[postId] || 0,
        reposts: txt("ccm-repost-count"),
        likes: txt("ccm-like-count"),
        favs: txt("ccm-fav-count"),
        shares: txt("ccm-share-count"),
        liked: on("ccm-like-btn"),
        reposted: on("ccm-repost-btn"),
        faved: on("ccm-fav-btn"),
        following: !!p.isFollowing,
      };
    },
  };
}
function ccmOpenComments(postId) {
  dzHaptic.light();
  if (
    window.CommentSection &&
    typeof window.CommentSection.open === "function"
  ) {
    var st = document.getElementById("ccm-stage");
    if (st) {
      var r = st.getBoundingClientRect(),
        root =
          document.documentElement
            .style; /* wide screens: the sheet sits inside the box's width */
      root.setProperty("--cs-dock-left", Math.round(r.left) + "px");
      root.setProperty("--cs-dock-w", Math.round(r.width) + "px");
      root.setProperty(
        "--cs-dock-bottom",
        Math.round(window.innerHeight - r.bottom) + "px",
      );
      root.setProperty("--cs-dock-h", Math.round(r.height * 0.6) + "px");
    }
    if (
      window.matchMedia &&
      window.matchMedia("(min-width:791px)").matches &&
      st
    ) {
      /* LAPTOP: the comments open as a tall panel BESIDE the box, covering the rail and the arrows (closed with its X). The box itself stays as it is. */
      var rr = st.getBoundingClientRect(),
        left = Math.round(rr.right + 10),
        w = Math.max(320, Math.min(430, window.innerWidth - left - 10));
      root.setProperty("--cs-dock-left", left + "px");
      root.setProperty("--cs-dock-w", w + "px");
      window.CommentSection.open(postId, {
        dock: true,
        side: true,
        header: _ccmCmHeader(postId),
      });
      return;
    }
    window.CommentSection.open(postId, {
      dock: true,
      header: _ccmCmHeader(postId),
    });
    return;
  }
  if (typeof ccmToast === "function")
    ccmToast("Comments are not available here");
}

/* ══════════ AUTO SCROLL (toggle lives in the shared "⋯" index sheet, shown only while this modal is open) ══════════
   ON  → when the current post's video ends it goes to the next post; image / text posts move on after a few seconds.
   Remembered in this browser. The shared sheet reads ccmAutoScrollIsOn() and calls ccmAutoScrollSet(bool). */
(function () {
  var KEY = "dz_ccm_autoscroll",
    on = false,
    lastId = null,
    since = 0,
    fired = false;
  /* saved choice: from the database for a logged-in user (column users.auto_scroll), else this browser */
  var DB_ON = window.CCM_PHP.autoScroll;
  if (DB_ON !== null) {
    on = DB_ON;
  } else {
    try {
      on = localStorage.getItem(KEY) === "1";
    } catch (e) {}
  }
  window.CCM_AUTOSCROLL_DWELL =
    window.CCM_AUTOSCROLL_DWELL ||
    6000; /* ms an image / text post stays before moving on */
  window.ccmAutoScrollIsOn = function () {
    return on;
  };
  window.ccmAutoScrollSet = function (v) {
    on = !!v;
    since = Date.now();
    fired = false;
    try {
      localStorage.setItem(KEY, on ? "1" : "0");
    } catch (e) {}
    try {
      var fd = new URLSearchParams();
      fd.set("action", "set_autoscroll");
      fd.set("value", on ? "1" : "0");
      if (window.DZ_CSRF) fd.set("csrf", window.DZ_CSRF);
      fetch("ccm_pref.php", {
        method: "POST",
        credentials: "same-origin",
        headers: _ccmCsrfH(),
        body: fd,
      }).catch(function () {});
    } catch (e) {}
    if (typeof ccmToast === "function")
      ccmToast(on ? "Auto scroll on" : "Auto scroll off");
  };
  setInterval(function () {
    if (!on) return;
    var m = document.getElementById("cardCaptionModal");
    if (!m || !m.classList.contains("ccm-open")) return;
    var sh = document.getElementById("indexSheetPanel");
    if (document.hidden || (sh && sh.classList.contains("show"))) {
      since = Date.now();
      return;
    }
    var id = String(typeof _ccmMemeId !== "undefined" ? _ccmMemeId : "");
    if (id !== lastId) {
      lastId = id;
      since = Date.now();
      fired = false;
    }
    if (fired) return;
    var slide = document.getElementById("ccm-slide");
    var v = slide ? slide.querySelector("video") : null;
    var go = false;
    if (v) {
      v.loop = false; /* let it end instead of looping */
      if (v.ended) go = true;
      else if (v.paused) since = Date.now(); /* user paused → wait */
    } else if (Date.now() - since >= window.CCM_AUTOSCROLL_DWELL) {
      go = true;
    }
    if (go) {
      if (typeof ccmFeed === "undefined" || _ccmIndex + 1 >= ccmFeed.length)
        return; /* last post → stay */
      fired = true;
      ccmLoadIndex(_ccmIndex + 1);
    }
  }, 300);
})();

/* ── keyboard-style "invisible tap zones" ─────────────────────────────────────────────
   Like the iPhone keyboard: what you SEE is the icon, what catches your tap is a bigger invisible zone.
   A tap that lands just outside a control (or between two close controls) goes to the NEAREST control instead of
   falling through to the video. Taps that land on a control itself work as always.
   Each control has its own reach: big and generous for the right rail, smaller for the controls that sit next to text,
   so a tap meant for the caption / username is never stolen. */
(function () {
  var ZONES = [
    { sel: ".ccm-rail-btn, .ccm-rail-msg, .ccm-icon-btn", reach: 26 },
    {
      sel: ".ccm-mute-btn, .ccm-seek-btn, .ccm-cm-ctl button, .ccm-top-search",
      reach: 14,
    },
    { sel: ".ccm-bottom .ccm-track-btn", reach: 12 },
    { sel: ".ccm-bottom .av-wrap > .ccm-user-pic", reach: 10 },
    { sel: ".ccm-social-proof-strip .ccm-sp-avatar-wrap", reach: 14 },
  ];
  var ALL = ZONES.map(function (z) {
    return z.sel;
  }).join(",");
  var busy = false;
  function dist(r, x, y) {
    var dx = x < r.left ? r.left - x : x > r.right ? x - r.right : 0;
    var dy = y < r.top ? r.top - y : y > r.bottom ? y - r.bottom : 0;
    return Math.sqrt(dx * dx + dy * dy);
  }
  function reachOf(b) {
    for (var i = 0; i < ZONES.length; i++) {
      if (b.matches(ZONES[i].sel)) return ZONES[i].reach;
    }
    return 0;
  }
  document.addEventListener(
    "click",
    function (e) {
      if (busy || !e.isTrusted) return;
      var m = document.getElementById("cardCaptionModal");
      var t = e.target;
      if (!m || !t || !t.closest || !m.contains(t)) return;
      if (t.closest(ALL)) return; /* landed on a control: normal */
      if (t.closest("a,button,input,textarea,select,label,[contenteditable]"))
        return; /* other real controls keep their taps */
      if (t.closest(".ccm-bottom [onclick], .ccm-social-proof-strip [onclick]"))
        return; /* username, caption … keep their own taps */
      var x = e.clientX,
        y = e.clientY;
      if (!(x >= 0) || !(y >= 0)) return;
      var best = null,
        bd = Infinity,
        bc = Infinity;
      m.querySelectorAll(ALL).forEach(function (b) {
        var r = b.getBoundingClientRect();
        if (r.width < 4 || r.height < 4) return;
        var d = dist(r, x, y);
        if (d > reachOf(b)) return;
        var top = document.elementFromPoint(
          r.left + r.width / 2,
          r.top + r.height / 2,
        ); /* must be visible, not under a sheet */
        if (!top || !(b === top || b.contains(top))) return;
        var cx = r.left + r.width / 2,
          cy = r.top + r.height / 2,
          c = Math.sqrt((cx - x) * (cx - x) + (cy - y) * (cy - y));
        /* zones never overlap: the nearest control wins; an exact tie goes to the one whose centre is closer */
        if (d < bd - 0.5 || (Math.abs(d - bd) <= 0.5 && c < bc)) {
          best = b;
          bd = d;
          bc = c;
        }
      });
      if (!best) return;
      e.stopImmediatePropagation();
      e.preventDefault();
      busy = true;
      try {
        best.click();
      } finally {
        busy = false;
      }
    },
    true,
  );
})();

/* ── leaving the tab / locking the phone: pause everything, and resume only what was playing ── */
(function () {
  var resumeList = [];
  function medias() {
    return document.querySelectorAll(
      "#cardCaptionModal video, #cardCaptionModal audio, #ccmSoundtrackPlayer",
    );
  }
  function hide() {
    resumeList = [];
    try {
      medias().forEach(function (el) {
        if (el && !el.paused && !el.ended) {
          resumeList.push(el);
          try {
            el.pause();
          } catch (_) {}
        }
      });
      if (
        typeof audioEl !== "undefined" &&
        audioEl &&
        !audioEl.paused &&
        resumeList.indexOf(audioEl) < 0
      ) {
        resumeList.push(audioEl);
        try {
          audioEl.pause();
        } catch (_) {}
      }
    } catch (e) {}
  }
  function show() {
    var list = resumeList;
    resumeList = [];
    list.forEach(function (el) {
      try {
        if (!el.isConnected) return;
        var pr = el.play();
        if (pr && pr.catch) pr.catch(function () {});
      } catch (_) {}
    });
  }
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) hide();
    else show();
  });
  window.addEventListener("pagehide", hide);
  window.addEventListener("pageshow", function (e) {
    if (e.persisted) show();
  });
})();

/* ══════════ refinement layer: remembered state · press haptics · calm loading / error states ══════════ */
(function () {
  /* 6. remember where the person was (this browser session): pause choice, mute choice, which photo/video of a multi-media post */
  var K = "dz_zstate";
  try {
    var d = JSON.parse(sessionStorage.getItem(K) || "null");
    if (d) {
      if (typeof _ccmMutedPref !== "undefined")
        Object.assign(_ccmMutedPref, d.m || {});
      if (typeof _ccmPausedMemeIds !== "undefined")
        Object.assign(_ccmPausedMemeIds, d.p || {});
      if (typeof _ccmMediaIndexByPost !== "undefined")
        Object.assign(_ccmMediaIndexByPost, d.i || {});
    }
  } catch (e) {}
  function cap(o) {
    var k = Object.keys(o || {});
    if (k.length <= 120) return o || {};
    var n = {};
    k.slice(-120).forEach(function (x) {
      n[x] = o[x];
    });
    return n;
  }
  function save() {
    try {
      sessionStorage.setItem(
        K,
        JSON.stringify({
          m: cap(_ccmMutedPref),
          p: cap(_ccmPausedMemeIds),
          i: cap(_ccmMediaIndexByPost),
        }),
      );
    } catch (e) {}
  }
  window.addEventListener("pagehide", save);
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) save();
  });

  /* 4. one consistent tap tick on every control (like / repost / save / Track already have their own firmer one) */
  document.addEventListener("click", function (e) {
    var t =
      e.target &&
      e.target.closest &&
      e.target.closest(
        "#cardCaptionModal .ccm-rail-btn, #cardCaptionModal .ccm-icon-btn, #cardCaptionModal .ccm-mute-btn, #cardCaptionModal .ccm-seek-btn, #cardCaptionModal .ccm-cm-ctl button",
      );
    if (!t || /^(ccm-like-btn|ccm-repost-btn|ccm-fav-btn)$/.test(t.id)) return;
    try {
      if (window.dzHaptic) dzHaptic.light();
    } catch (_) {}
  });

  /* 7. calm loading + error states for videos */
  var st = document.createElement("style");
  st.textContent =
    "@keyframes ccmSkSweep{from{background-position:150% 0}to{background-position:-50% 0}}" +
    "@keyframes ccmSpin{to{transform:rotate(360deg)}}" +
    '.ccm-media-item.ccm-vloading::after{content:"";position:absolute;inset:0;z-index:2;pointer-events:none;background:linear-gradient(100deg,transparent 35%,rgba(255,255,255,.07) 50%,transparent 65%);background-size:200% 100%;animation:ccmSkSweep 1.3s linear infinite}' +
    '.ccm-media-item.ccm-vloading::before{content:"";position:absolute;left:50%;top:50%;width:34px;height:34px;margin:-17px 0 0 -17px;z-index:3;pointer-events:none;border-radius:50%;border:3px solid rgba(255,255,255,.22);border-top-color:#fff;animation:ccmSpin .8s linear infinite}' +
    ".ccm-vfail{position:absolute;inset:0;z-index:6;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;background:rgba(0,0,0,.62);color:#fff;font:600 13px Poppins,sans-serif;text-align:center;padding:24px}" +
    ".ccm-vfail button{border:none;border-radius:999px;background:#fff;color:#000;font:700 13px Poppins,sans-serif;padding:9px 20px;transition:transform .14s ease}" +
    ".ccm-vfail button:active{transform:scale(.93)}";
  document.head.appendChild(st);

  function isVid(t) {
    return t && t.tagName === "VIDEO" && t.classList.contains("ccm-media");
  }
  function itemOf(v) {
    return v.closest ? v.closest(".ccm-media-item") : null;
  }
  function clearTimer(v) {
    if (v._ccmSkT) {
      clearTimeout(v._ccmSkT);
      v._ccmSkT = 0;
    }
  }
  function loading(v, on) {
    clearTimer(v);
    var it = itemOf(v);
    if (!it) return;
    if (!on) {
      it.classList.remove("ccm-vloading");
      return;
    }
    v._ccmSkT = setTimeout(function () {
      if (v.readyState < 3 && !v.error && !v.paused)
        it.classList.add("ccm-vloading");
    }, 380); /* only if it really takes a moment — never a flash */
  }
  function clearFail(v) {
    var it = itemOf(v),
      f = it && it.querySelector(".ccm-vfail");
    if (f) f.remove();
  }
  function fail(v) {
    loading(v, false);
    var it = itemOf(v);
    if (!it || it.querySelector(".ccm-vfail")) return;
    var off = navigator.onLine === false,
      f = document.createElement("div");
    f.className = "ccm-vfail";
    f.innerHTML =
      "<div>" +
      (off ? "You're offline" : "Couldn't play this video") +
      '</div><button type="button">Try again</button>';
    ["click", "touchstart", "touchend", "pointerdown", "pointerup"].forEach(
      function (ev) {
        f.addEventListener(ev, function (e) {
          e.stopPropagation();
        });
      },
    );
    f.querySelector("button").addEventListener("click", function () {
      f.remove();
      loading(v, true);
      try {
        v.load();
        var pr = v.play();
        if (pr && pr.catch) pr.catch(function () {});
      } catch (_) {}
    });
    it.appendChild(f);
  }
  ["loadstart", "waiting"].forEach(function (ev) {
    document.addEventListener(
      ev,
      function (e) {
        if (isVid(e.target)) loading(e.target, true);
      },
      true,
    );
  });
  ["loadeddata", "canplay", "playing", "pause", "ended"].forEach(function (ev) {
    document.addEventListener(
      ev,
      function (e) {
        if (isVid(e.target)) {
          loading(e.target, false);
          clearFail(e.target);
        }
      },
      true,
    );
  });
  document.addEventListener(
    "error",
    function (e) {
      if (isVid(e.target)) fail(e.target);
    },
    true,
  );

  /* blur-up: a soft, blurred copy of the poster sits behind a video until its first frame is ready, so a swipe never shows black */
  function blurUp(v) {
    var it = itemOf(v);
    if (!it || it.querySelector(".ccm-bu")) return;
    var src = v.getAttribute("poster");
    if (!src || v.readyState >= 2) return;
    var d = document.createElement("div");
    d.className = "ccm-bu";
    d.style.backgroundImage = 'url("' + String(src).replace(/"/g, "%22") + '")';
    it.insertBefore(d, it.firstChild);
  }
  function blurDone(v) {
    var it = itemOf(v),
      d = it && it.querySelector(".ccm-bu");
    if (!d) return;
    d.classList.add("out");
    setTimeout(function () {
      if (d.parentNode) d.parentNode.removeChild(d);
    }, 320);
  }
  document.addEventListener(
    "loadstart",
    function (e) {
      if (isVid(e.target)) blurUp(e.target);
    },
    true,
  );
  ["loadeddata", "playing"].forEach(function (ev) {
    document.addEventListener(
      ev,
      function (e) {
        if (isVid(e.target)) blurDone(e.target);
      },
      true,
    );
  });

  /* a11y: keep aria-pressed in step with the active state, and keep long sessions light */
  function syncA11y() {
    [["ccm-like-btn"], ["ccm-repost-btn"], ["ccm-fav-btn"]].forEach(
      function (a) {
        var b = document.getElementById(a[0]);
        if (b)
          b.setAttribute(
            "aria-pressed",
            b.classList.contains("active") ? "true" : "false",
          );
      },
    );
    var m = document.getElementById("cardCaptionModal");
    if (!m) return;
    m.querySelectorAll("#ccm-slide video.ccm-media[poster]").forEach(
      function (v) {
        if (v.readyState < 2) blurUp(v);
      },
    ); /* a slide that mounted before its video started loading */
  }
  var _trimN = 0;
  function trimMemory() {
    [
      "_ccmConnectedCache",
      "_ccmTrackPositions",
      "_ccmMediaIndexByPost",
      "_ccmPausedMemeIds",
    ].forEach(function (n) {
      var o = window[n];
      if (!o || typeof o !== "object") return;
      var k = Object.keys(o);
      if (k.length <= 90) return;
      k.slice(0, k.length - 60).forEach(function (x) {
        delete o[x];
      }); /* oldest entries go; the recent 60 stay */
    });
  }
  (function () {
    var m = document.getElementById("cardCaptionModal");
    if (!m) return;
    var q = 0;
    new MutationObserver(function () {
      if (q) return;
      q = requestAnimationFrame(function () {
        q = 0;
        syncA11y();
        if (++_trimN % 25 === 0) trimMemory();
      });
    }).observe(m, { childList: true, subtree: true });
    m.addEventListener("click", function () {
      setTimeout(syncA11y, 60);
    });
  })();

  window.addEventListener("offline", function () {
    try {
      ccmToast("You're offline");
    } catch (_) {}
  });
  window.addEventListener("online", function () {
    try {
      ccmToast("Back online");
    } catch (_) {}
    document
      .querySelectorAll("#cardCaptionModal .ccm-vfail button")
      .forEach(function (b) {
        b.click();
      }); /* retry what failed while offline */
  });
})();

/* ── PLAYBACK CONTROLS (index menu): speed, captions, view full screen ── */
(function () {
  var SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2],
    speed = 1,
    caps = false;
  try {
    var s = parseFloat(localStorage.getItem("dz_ccm_speed"));
    if (SPEEDS.indexOf(s) > -1) speed = s;
    caps = localStorage.getItem("dz_ccm_caps") === "1";
  } catch (e) {}
  /* extra playback settings (kept in this browser, so they apply on every page that has Zeems) */
  var remember = true,
    loopOn = true,
    saver = false,
    savedQ = "",
    sleepMin = 0,
    sleepEnd = 0;
  try {
    remember = localStorage.getItem("dz_ccm_remember") !== "0";
    loopOn = localStorage.getItem("dz_ccm_loop") !== "0";
    saver = localStorage.getItem("dz_ccm_saver") === "1";
    savedQ = localStorage.getItem("dz_ccm_q") || "";
    sleepMin = +localStorage.getItem("dz_ccm_sleep_min") || 0;
    sleepEnd = +localStorage.getItem("dz_ccm_sleep_end") || 0;
  } catch (e) {}
  function sv(k, v) {
    try {
      localStorage.setItem(k, v);
    } catch (e) {}
  }
  function vids() {
    return document.querySelectorAll("#cardCaptionModal video");
  }
  function applyCaps(v) {
    var t = v.textTracks;
    if (!t) return;
    for (var i = 0; i < t.length; i++) {
      if (t[i].kind === "captions" || t[i].kind === "subtitles")
        t[i].mode = caps ? "showing" : "hidden";
    }
  }
  function hasCaps(v) {
    var t = v && v.textTracks;
    if (!t) return false;
    for (var i = 0; i < t.length; i++) {
      if (t[i].kind === "captions" || t[i].kind === "subtitles") return true;
    }
    return false;
  }
  function rateOf(v) {
    return remember ? speed : parseFloat(v.dataset.spd) || 1;
  }
  function apply(v) {
    try {
      var r = rateOf(v);
      if (v.playbackRate !== r) v.playbackRate = r;
      if (v.loop !== loopOn) v.loop = loopOn;
      applyCaps(v);
    } catch (e) {}
  }
  ["loadedmetadata", "play", "playing"].forEach(function (ev) {
    document.addEventListener(
      ev,
      function (e) {
        var v = e.target;
        if (
          !(
            v &&
            v.tagName === "VIDEO" &&
            v.closest &&
            v.closest("#cardCaptionModal")
          )
        )
          return;
        apply(v);
        if (ev === "playing") autoQuality(v);
      },
      true,
    );
  });
  /* what the post on screen is: 'video', 'image' (also gif / sticker) or 'text' */
  window.ccmPostKind = function () {
    var box = document.querySelector("#ccm-slide #ccm-media-box");
    if (!box) return "text";
    var items = box.querySelectorAll(".ccm-media-item");
    if (!items.length) return "text";
    var act =
      typeof _ccmMediaIndex !== "undefined"
        ? box.querySelector(
            '.ccm-media-item[data-idx="' + _ccmMediaIndex + '"]',
          )
        : null;
    return (act || items[0]).getAttribute("data-type") === "video"
      ? "video"
      : "image";
  };
  window.ccmHasVideo = function () {
    return window.ccmPostKind() === "video";
  };
  window.ccmSpeedGet = function () {
    if (remember) return speed;
    var v = activeVid();
    return v ? rateOf(v) : 1;
  };
  window.ccmSpeedCycle = function () {
    speed = SPEEDS[(SPEEDS.indexOf(speed) + 1) % SPEEDS.length];
    try {
      localStorage.setItem("dz_ccm_speed", String(speed));
    } catch (e) {}
    vids().forEach(apply);
    return speed;
  };
  window.ccmSpeedSet = function (v) {
    if (SPEEDS.indexOf(v) < 0) return speed;
    if (remember) {
      speed = v;
      sv("dz_ccm_speed", String(speed));
      vids().forEach(apply);
    } else {
      var a = activeVid();
      if (a) {
        a.dataset.spd = String(v);
        apply(a);
      }
    }
    return v;
  };
  /* media quality: swap the playing video for a smaller copy made on the server (cached after the first time) */
  function activeVid() {
    var slide = document.getElementById("ccm-slide");
    if (!slide) return null;
    var box = slide.querySelector("#ccm-media-box");
    if (!box) return null;
    var act =
      typeof _ccmMediaIndex !== "undefined"
        ? box.querySelector(
            '.ccm-media-item[data-idx="' + _ccmMediaIndex + '"]',
          )
        : null;
    return (
      (act ? act.querySelector("video.ccm-media") : null) ||
      box.querySelector("video.ccm-media")
    );
  }
  function qget(params, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "video_quality.php?" + params, true);
    xhr.timeout = 300000;
    xhr.onload = function () {
      var j = null;
      try {
        j = JSON.parse(xhr.responseText);
      } catch (e) {}
      cb(j && j.ok ? j : null);
    };
    xhr.onerror = xhr.ontimeout = function () {
      cb(null);
    };
    xhr.send();
  }
  function origOf(v) {
    if (!v.dataset.origSrc) v.dataset.origSrc = v.getAttribute("src") || "";
    return v.dataset.origSrc;
  }
  function swapTo(v, url, q) {
    var t = v.currentTime || 0,
      wasPaused = v.paused;
    v.addEventListener("loadedmetadata", function f() {
      v.removeEventListener("loadedmetadata", f);
      try {
        v.currentTime = t;
      } catch (e) {}
      if (!wasPaused) {
        var p = v.play();
        if (p && p.catch) p.catch(function () {});
      }
    });
    v.src = url;
    v.dataset.q = String(q);
  }
  function saverActive() {
    if (!saver) return false;
    var c = navigator.connection;
    if (c && c.saveData) return true;
    if (c && c.type) return c.type === "cellular";
    return (
      window.innerWidth <= 790
    ); /* browsers that cannot tell (iPhone) count as mobile data */
  }
  /* a new video starts at the remembered strength, or at 360p when data saver is on */
  function autoQuality(v) {
    if (v.dataset.qTried || v.dataset.q || v !== activeVid()) return;
    var t = 0;
    if (remember && savedQ && savedQ !== "orig") t = +savedQ;
    if (saverActive() && (!t || t > 360)) t = 360;
    if (!t) return;
    v.dataset.qTried = "1";
    var src = origOf(v);
    qget("a=make&h=" + t + "&src=" + encodeURIComponent(src), function (j) {
      if (j && j.url && v.isConnected && !v.dataset.q && origOf(v) === src)
        swapTo(v, j.url, t);
    });
  }
  window.ccmQualityInfo = function (cb) {
    var v = activeVid();
    if (!v) return cb(null);
    qget("a=info&src=" + encodeURIComponent(origOf(v)), function (j) {
      cb(j, v.dataset.q || "orig");
    });
  };
  window.ccmQualitySet = function (q, cb) {
    var v = activeVid();
    if (!v) return cb(false);
    var src = origOf(v);
    v.dataset.qTried = "1";
    if (remember) (sv("dz_ccm_q", String(q)), (savedQ = String(q)));
    if (q === "orig") {
      if (v.dataset.q && v.dataset.q !== "orig") {
        swapTo(v, src, "orig");
      }
      cb(true);
      return;
    }
    qget(
      "a=make&h=" + encodeURIComponent(q) + "&src=" + encodeURIComponent(src),
      function (j) {
        if (j && j.url) {
          swapTo(v, j.url, q);
          cb(true);
        } else cb(false);
      },
    );
  };
  window.ccmLoopIsOn = function () {
    return loopOn;
  };
  window.ccmLoopToggle = function () {
    loopOn = !loopOn;
    sv("dz_ccm_loop", loopOn ? "1" : "0");
    vids().forEach(apply);
    return loopOn;
  };
  window.ccmRememberIsOn = function () {
    return remember;
  };
  window.ccmRememberToggle = function () {
    remember = !remember;
    sv("dz_ccm_remember", remember ? "1" : "0");
    if (!remember) {
      var a = activeVid();
      if (a) a.dataset.spd = String(speed);
    } /* this post keeps what it has now */
    vids().forEach(apply);
    return remember;
  };
  window.ccmSaverIsOn = function () {
    return saver;
  };
  window.ccmSaverToggle = function () {
    saver = !saver;
    sv("dz_ccm_saver", saver ? "1" : "0");
    var a = activeVid();
    if (a && saver) autoQuality(a);
    return saver;
  };
  /* sleep timer: stops auto scroll after the chosen minutes (works across pages: the end time is saved) */
  window.ccmSleepGet = function () {
    return sleepEnd > Date.now() ? sleepMin : 0;
  };
  window.ccmSleepSet = function (m) {
    m = +m || 0;
    sleepMin = m;
    sleepEnd = m ? Date.now() + m * 60000 : 0;
    sv("dz_ccm_sleep_min", String(sleepMin));
    sv("dz_ccm_sleep_end", String(sleepEnd));
    if (typeof ccmToast === "function")
      ccmToast(
        m ? "Auto scroll will stop in " + m + " min" : "Sleep timer off",
      );
  };
  setInterval(function () {
    if (!sleepEnd || Date.now() < sleepEnd) return;
    sleepEnd = 0;
    sleepMin = 0;
    sv("dz_ccm_sleep_min", "0");
    sv("dz_ccm_sleep_end", "0");
    if (
      typeof window.ccmAutoScrollIsOn === "function" &&
      window.ccmAutoScrollIsOn()
    )
      window.ccmAutoScrollSet(false);
  }, 3000);
  window.ccmCapsIsOn = function () {
    return caps;
  };
  window.ccmCapsToggle = function () {
    var v = document.querySelector("#ccm-slide video.ccm-media");
    if (!hasCaps(v)) {
      if (typeof ccmToast === "function")
        ccmToast("No captions for this video");
      return caps;
    }
    caps = !caps;
    try {
      localStorage.setItem("dz_ccm_caps", caps ? "1" : "0");
    } catch (e) {}
    vids().forEach(applyCaps);
    return caps;
  };
  /* view full screen */
  function exitBtn() {
    return document.querySelector("#ccm-stage > .ccm-fsx");
  }
  window.ccmFsViewIsOn = function () {
    var m = document.getElementById("cardCaptionModal");
    return !!(m && m.classList.contains("ccm-fsview"));
  };
  window.ccmFsViewExit = function () {
    var m = document.getElementById("cardCaptionModal");
    if (m) m.classList.remove("ccm-fsview");
    var b = exitBtn();
    if (b) {
      b.classList.remove("show");
      setTimeout(function () {
        if (b.parentNode) b.parentNode.removeChild(b);
      }, 260);
    }
  };
  window.ccmFsViewEnter = function () {
    var m = document.getElementById("cardCaptionModal"),
      st = document.getElementById("ccm-stage");
    if (!m || !st) return;
    if (typeof ccmZoomReset === "function") ccmZoomReset(false);
    m.classList.add("ccm-fsview");
    if (exitBtn()) return;
    var b = document.createElement("button");
    b.type = "button";
    b.className = "ccm-unzoom-btn ccm-fsx";
    b.setAttribute("aria-label", "Exit full screen view");
    b.innerHTML =
      '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3"/><path d="M21 8h-3a2 2 0 0 1-2-2V3"/><path d="M3 16h3a2 2 0 0 1 2 2v3"/><path d="M16 21v-3a2 2 0 0 1 2-2h3"/></svg>';
    ["touchstart", "touchend", "pointerdown", "mousedown"].forEach(
      function (ev) {
        b.addEventListener(ev, function (e) {
          e.stopPropagation();
        });
      },
    );
    b.addEventListener("click", function (e) {
      e.stopPropagation();
      ccmFsViewExit();
    });
    st.appendChild(b);
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        b.classList.add("show");
      });
    });
  };
  document.addEventListener("zeems:closed", function () {
    ccmFsViewExit();
  });
})();
