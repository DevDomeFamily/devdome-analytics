/*
 * analytics.processdome.com client tracker.
 * Drop into any site:
 *   <script src="https://analytics.processdome.com/track.js" data-site="productdome.com" defer></script>
 *
 * Sends: pageview, amazon_outbound_click,
 *        safari_overlay_shown, safari_open_click, android_intent_click,
 *        internal_click, search, 404_hit.
 */
(function () {
  // One-shot per page load: if track.js is included or injected twice (the connector tag AND a manually
  // pasted tag, or a plugin double-enqueue), each copy fires its own pageview ~seconds apart, inflating
  // pageview counts. Guard on a window flag so exactly one instance runs per document.
  if (window.__ddTrackerLoaded) return;
  window.__ddTrackerLoaded = true;
  var SCRIPT = document.currentScript;
  // The connector injects this tag with `async`, where document.currentScript is null at run time, so
  // it prints window.__DDCFG just before the tag. Prefer that; fall back to the script's own data-*
  // attrs for manually-pasted (defer) tags on non-WP sites.
  var CFG    = (window.__DDCFG && typeof window.__DDCFG === "object") ? window.__DDCFG : {};
  var SITE   = CFG.site || (SCRIPT && (SCRIPT.getAttribute("data-site") || SCRIPT.getAttribute("data-site-id"))) || location.hostname;
  var API    = CFG.endpoint || (SCRIPT && SCRIPT.getAttribute("data-endpoint")) || resolveDefaultEndpoint();
  var SITE_ID = CFG.siteId || (SCRIPT && SCRIPT.getAttribute("data-site-id")) || SITE;
  // DevDome Account ID (DD + 8 digits) — public, routes events into the owning account at ingest.
  var ACCOUNT = CFG.account || (SCRIPT && SCRIPT.getAttribute("data-account")) || null;
  // When the WP connector injects __DDCFG.fp it also prints an INLINE first-party outbound-click detector
  // (beacons same-origin → ad-blockers/privacy browsers can't drop it). That owns outbound clicks, so
  // track.js must NOT also fire them here (double count). track.js still does pageviews/engagement.
  var FP_CLICKS = !!(CFG.fp);
  // The site's click switches (review 2026-09-11): the connector prints them in __DDCFG; manually
  // pasted tags may carry data-click-tracking / data-outbound-tracking. Default on.
  var CLICKS   = (CFG.clicks !== undefined) ? !!CFG.clicks : !(SCRIPT && SCRIPT.getAttribute("data-click-tracking") === "false");
  // "Track AI Referrals" (owner 2026-09-15): sent with every event as ai_ref; the ingest labels a visit that arrived
  // from an AI assistant (ChatGPT, Perplexity, Claude, Gemini, Copilot, ...) as source "ai" only while it is on.
  var AI_REF   = (CFG.ai !== undefined) ? !!CFG.ai : !(SCRIPT && SCRIPT.getAttribute("data-ai-tracking") === "false");
  // The bot detector: the plugin's own copy in first-party mode (data-botd / CFG.botd), else the file next to the endpoint.
  var BOTD_URL = CFG.botd || (SCRIPT && SCRIPT.getAttribute("data-botd")) || "";
  var OUTBOUND = (CFG.outbound !== undefined) ? !!CFG.outbound : !(SCRIPT && SCRIPT.getAttribute("data-outbound-tracking") === "false");

  // Honor "Do Not Track" when the site enabled the switch — bail before any identity or beacon.
  var RESPECT_DNT = (CFG.respectDnt !== undefined) ? !!CFG.respectDnt
    : (SCRIPT && SCRIPT.getAttribute("data-respect-dnt") === "true");
  if (RESPECT_DNT && (navigator.doNotTrack === "1" || window.doNotTrack === "1" || navigator.msDoNotTrack === "1" || navigator.globalPrivacyControl === true)) return; // GPC counts like DNT (DeepSeek round 6)

  function resolveDefaultEndpoint() {
    try {
      var src = SCRIPT && SCRIPT.src;
      if (!src) return "/api/event";
      var u = new URL(src);
      return u.origin + "/api/event";
    } catch (_) { return "/api/event"; }
  }

  // --- identity ---------------------------------------------------------
  //
  // TWO MODES. The site owner chooses; the tracker never decides for them.
  //
  //   COOKIELESS (the default for new sites)
  //     Nothing is written to the visitor's device: no cookie, no localStorage,
  //     no sessionStorage. The tracker sends NO id at all, and the server derives
  //     a rotating one from the request (see the worker). "No cookies" is then a
  //     statement about behaviour, not a slogan — which is the only reason it is
  //     allowed to appear on the website.
  //     Cost, stated plainly: a visitor returning tomorrow is a new visitor.
  //
  //   RETURNING VISITORS (opt-in — sets a first-party cookie)
  //     A random id in localStorage + a mirror cookie, so the same person is
  //     recognised across days and the redirect/transit hops on this domain can
  //     log a click under the SAME visit. This is what affiliate attribution
  //     needs. The dashboard warns that enabling it may require consent.
  //
  // The id never rides a shareable URL. The one exception is a same-domain /dd-go buy link the
  // visitor is clicking right now (see the click handler): the session and visitor id go on that
  // redirect so the edge can log the click under the same visit. Cookieless mode appends nothing.
  var COOKIELESS = (CFG.cookieless !== undefined) ? !!CFG.cookieless
    : !(SCRIPT && SCRIPT.getAttribute("data-cookieless") === "false"); // cookieless unless the tag says otherwise (DeepSeek round 5)

  function uid() {
    return (crypto && crypto.randomUUID) ? crypto.randomUUID()
      : (Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
  }
  function readCookie(n) {
    var m = document.cookie.match(new RegExp("(?:^|; )" + n + "=([^;]+)"));
    return m ? decodeURIComponent(m[1]) : null;
  }
  function writeCookie(n, v) {
    try { document.cookie = n + "=" + encodeURIComponent(v) + "; path=/; max-age=63072000; SameSite=Lax" + (location.protocol === "https:" ? "; Secure" : ""); } catch (_) {}
  }
  function getVisitorId() {
    // Cookieless: touch NOTHING. Not even a read — reading localStorage is still
    // "accessing information on the visitor's device", which is the thing the law
    // and the promise are both about. The server supplies the id instead.
    if (COOKIELESS) return null;

    var v = null;
    try {
      var k = "td_vid";
      v = localStorage.getItem(k) || readCookie("dd_vid");
      if (!v) v = uid();
      localStorage.setItem(k, v);
    } catch (_) { v = readCookie("dd_vid") || uid(); }
    writeCookie("dd_vid", v);
    return v;
  }
  function getSessionId() {
    if (COOKIELESS) return null;
    try {
      var k = "td_sid", ts_k = "td_sid_ts";
      var now = Date.now();
      var s = sessionStorage.getItem(k);
      var ts = parseInt(sessionStorage.getItem(ts_k) || "0", 10);
      if (!s || (now - ts) > 30*60*1000) { s = uid(); sessionStorage.setItem(k, s); }
      sessionStorage.setItem(ts_k, String(now));
      return s;
    } catch (_) { return uid(); }
  }

  // --- device / ua ------------------------------------------------------
  function detect() {
    var ua = navigator.userAgent || "";
    var isIOS     = /iPad|iPhone|iPod/.test(ua);
    var isAndroid = /Android/.test(ua);
    var isMobile  = isIOS || isAndroid || /Mobile/.test(ua);
    var inApp     = /(FBAN|FBAV|Instagram|TikTok|Snapchat|Twitter|Line|Reddit|Pinterest|LinkedInApp)/i.test(ua);
    var browser = "other";
    if (/Edg\//.test(ua)) browser = "edge";
    else if (/Chrome\//.test(ua)) browser = "chrome";
    else if (/Firefox\//.test(ua)) browser = "firefox";
    else if (/Safari\//.test(ua) && !/Chrome\//.test(ua)) browser = "safari";
    var os = isIOS ? "ios" : isAndroid ? "android" : /Windows/.test(ua) ? "windows" : /Mac OS/.test(ua) ? "macos" : /Linux/.test(ua) ? "linux" : "other";
    return {
      device_type: isMobile ? "mobile" : "desktop",
      browser: browser, os: os,
      user_agent: ua,
      screen_width: screen.width, screen_height: screen.height,
      language: navigator.language,
      timezone: (Intl.DateTimeFormat().resolvedOptions().timeZone) || null,
      _isIOS: isIOS, _isAndroid: isAndroid, _inApp: inApp
    };
  }

  var DEV = detect();
  var VID = getVisitorId();
  var SID = getSessionId();

  // In-app browsers (FB/IG/TikTok/... webviews) usually strip the referrer, but the app itself IS
  // the traffic source — its UA token names it. Sent as in_app ONLY when there is no referrer; the
  // backend then attributes the visit to the app's domain instead of "direct/unknown".
  function appRefFromUa(ua) {
    if (/FBAN|FBAV|FB_IAB/i.test(ua)) return "facebook.com";
    if (/Instagram/i.test(ua)) return "instagram.com";
    if (/TikTok/i.test(ua)) return "tiktok.com";
    if (/Snapchat/i.test(ua)) return "snapchat.com";
    if (/Twitter/i.test(ua)) return "x.com";
    if (/Reddit/i.test(ua)) return "reddit.com";
    if (/Pinterest/i.test(ua)) return "pinterest.com";
    if (/LinkedInApp/i.test(ua)) return "linkedin.com";
    if (/Line\//i.test(ua)) return "line.me";
    return null;
  }

  // page_path for storage: pathname + search, but with the internal transit-stitch param (_dd) and the
  // well-known marketing/tracking params stripped. These never define page CONTENT, so leaving them in
  // fragmented one logical page into many Pages rows (/ vs /?srsltid=… vs /?utm_*) and — for _dd — leaked
  // an internal stitch value into a stored path. Content-bearing params (?p=, ?category=…) are kept, so
  // the drill-down still matches. Mirrors the display normalization the dashboard would otherwise need.
  var DROP_PARAMS = /^(_dd|dd_ref|dd_out|utm_[a-z]+|srsltid|gclid|gclsrc|gbraid|wbraid|dclid|fbclid|msclkid|mc_cid|mc_eid|gad_source|_ga|yclid|igshid|ttclid|twclid)$/i;
  function cleanPagePath() {
    try {
      var qs = new URLSearchParams(location.search);
      var keys = [];
      qs.forEach(function (_v, k) { if (DROP_PARAMS.test(k.replace(/^\?+/, ""))) keys.push(k); });
      keys.forEach(function (k) { qs.delete(k); });
      var q = qs.toString();
      return location.pathname + (q ? "?" + q : "");
    } catch (_) { return location.pathname + location.search; }
  }
  // Same scrub for the referrer: a stitch/marketing param in document.referrer (e.g. ?_dd=301:…)
  // must not fragment the referrer list or leak internal markers.
  function cleanRef(r) {
    if (!r) return null;
    try {
      var u = new URL(r);
      if (!u.search) return r;
      var qs = u.searchParams, keys = [];
      qs.forEach(function (_v, k) { if (DROP_PARAMS.test(k)) keys.push(k); });
      if (!keys.length) return r;
      keys.forEach(function (k) { qs.delete(k); });
      u.search = qs.toString();
      return u.toString();
    } catch (_) { return r; }
  }

  // --- send -------------------------------------------------------------
  // The link's host is an Amazon store host (amazon.<tld>, with or without a subdomain), nothing else.
  function amazonHost(u) { try { var h = new URL(u, location.href).hostname.toLowerCase(); return /(^|\.)amazon\.(com|co\.uk|de|fr|it|es|ca|com\.au|co\.jp|in|com\.br|com\.mx|nl|se|pl|sg|ae|sa|com\.tr|eg|cn|com\.be|ie)$/.test(h); } catch (_) { return false; } }
  function basePayload() {
    var p = {
      site_id: SITE_ID,
      site_domain: SITE,
      page_url: location.href,
      page_path: cleanPagePath(),
      referrer: cleanRef(document.referrer) || null,
      visitor_id: VID,
      session_id: SID,
      device_type: DEV.device_type,
      browser: DEV.browser,
      os: DEV.os,
      user_agent: DEV.user_agent,
      screen_width: DEV.screen_width,
      screen_height: DEV.screen_height,
      language: DEV.language,
      timezone: DEV.timezone,
      wd: (navigator.webdriver === true),  // automation flag (Selenium/Puppeteer/Playwright/agentic browsers)
      ai_ref: AI_REF ? 1 : 0
    };
    if (ACCOUNT) p.account_id = ACCOUNT;
    // optional source marker from URL params
    try {
      var qs = new URLSearchParams(location.search);
      var src = qs.get("utm_source") || qs.get("ref") || qs.get("source");
      if (src) p.source_marker = src;
    } catch (_) {}
    if (!p.referrer && DEV._inApp) {
      var appR = appRefFromUa(DEV.user_agent);
      if (appR) p.in_app = appR;
    }
    return p;
  }

  function post(body) {
    return fetch(API, { method: "POST", headers: {"Content-Type":"text/plain;charset=UTF-8"}, body: body, keepalive: true, mode: "cors" });
  }
  // Offline / transient-failure outbox: a beacon or fetch that doesn't go through is queued to
  // localStorage (capped) and retried on the next page load, so a network blip doesn't silently drop
  // the event. The backend dedups pageviews loosely (max engaged per session/page), so a rare retry
  // duplicate is harmless.
  //
  // COOKIELESS: the outbox is DISABLED. It is still storage on the visitor's device — the promise is
  // "we write nothing", not "we write nothing that identifies you", and an events queue would make
  // that promise false on a technicality. The cost is small and honest: an event lost to a network
  // blip stays lost instead of being retried.
  function queue(body) {
    if (COOKIELESS) return;
    try { var a = JSON.parse(localStorage.getItem("td_outbox") || "[]"); a.push(body);
      if (a.length > 50) a = a.slice(-50); localStorage.setItem("td_outbox", JSON.stringify(a)); } catch (_) {}
  }
  function flushOutbox() {
    if (COOKIELESS) return;
    try {
      var a = JSON.parse(localStorage.getItem("td_outbox") || "[]");
      if (!a.length) return;
      localStorage.removeItem("td_outbox");
      a.forEach(function (b) { try { post(b).catch(function () { queue(b); }); } catch (_) { queue(b); } });
    } catch (_) {}
  }

  function send(event_type, extra, useBeacon) {
    var p = basePayload();
    if (CFG.debug && window.console) { try { console.log("DevDome Analytics:", event_type, extra || {}); } catch (_) {} } // Debug mode (Codex round 8: the switch had no effect)
    p.event_type = event_type;
    if (extra) { for (var k in extra) p[k] = extra[k]; }
    var body = JSON.stringify(p);
    // Use text/plain so the request is CORS-"simple" (no preflight). A preflighted beacon fired on
    // page-unload (the dwell/engagement ping) gets dropped by browsers; text/plain delivers reliably.
    // The worker parses the JSON body regardless of content-type.
    if (useBeacon && navigator.sendBeacon) {
      // sendBeacon returns false when the UA queue is full / payload too large — fall through to fetch.
      try { if (navigator.sendBeacon(API, new Blob([body], { type: "text/plain;charset=UTF-8" }))) return; } catch (_) {}
    }
    try { post(body).catch(function () { queue(body); }); } catch (_) { queue(body); }
  }

  // --- events -----------------------------------------------------------
  // Transit stitch: a server-side redirect (e.g. /p/<asin>/) appends ?_dd=<via>:<from-path> to the
  // landing URL so we can record the hop it skipped — track.js never ran on the redirecting URL
  // itself. Read it, wipe it from the address bar, then attach it to this pageview.
  var STITCH = (function () {
    try {
      var qs = new URLSearchParams(location.search);
      var raw = qs.get("_dd");
      if (!raw) return null;
      var i = raw.indexOf(":");
      var from = i >= 0 ? raw.slice(i + 1) : raw;
      if (!from) return null;
      qs.delete("_dd");
      var q = qs.toString();
      try { history.replaceState(null, "", location.pathname + (q ? "?" + q : "") + location.hash); } catch (_) {}
      return { transit_from: from, transit_via: (i >= 0 ? raw.slice(0, i) : "302") };
    } catch (_) { return null; }
  })();
  flushOutbox();
  // Universal cross-CMS redirect detection: the Navigation Timing API reports how many same-origin
  // HTTP redirects the browser followed to reach this page — any platform, any plugin, any redirect
  // mechanism, with NO server cooperation. (Cross-origin redirects before arrival are hidden by the
  // browser for privacy and the intermediate URL is never exposed to JS; the ?_dd stitch supplies the
  // exact hop where the server cooperates.) rc>0 → this landing was reached through a redirect chain.
  var pvExtra = STITCH ? { transit_from: STITCH.transit_from, transit_via: STITCH.transit_via } : {};
  try {
    var navEntry = performance.getEntriesByType && performance.getEntriesByType("navigation")[0];
    var rc = (navEntry && typeof navEntry.redirectCount === "number") ? navEntry.redirectCount
      : ((performance.navigation && performance.navigation.redirectCount) || 0);
    if (rc > 0) pvExtra.rc = rc;
  } catch (_) {}
  // BotD (self-hosted automation detector): run it, then send the pageview WITH its verdict so a
  // profile-spoofing automation (headless / Selenium / Puppeteer / Playwright / ...) is scored a bot at
  // insert even though browser/os/device look real. Bounded + fail-open: the pageview always goes out
  // within ~700ms even if BotD is slow, blocked, or unsupported — it is never delayed away or dropped.
  (function () {
    var sent = false;
    function firePv(verdict) {
      if (sent) return; sent = true;
      if (verdict && typeof verdict.bot !== "undefined") {
        pvExtra.botd = verdict.bot;
        if (verdict.kind) pvExtra.botd_kind = verdict.kind;
      }
      send("pageview", (pvExtra.transit_from || pvExtra.rc || typeof pvExtra.botd !== "undefined") ? pvExtra : null);
    }
    setTimeout(function () { firePv(null); }, 700);
    var url = null, dynImport = null;
    // Local copy first; else the file next to the DevDome endpoint; on a first-party endpoint (same origin as the page)
    // the DevDome CDN, never the site root (Codex round 7).
    var BOTD_CDN = "https://analytics.devdome.com/botd.js";
    try {
      if (BOTD_URL) url = new URL(BOTD_URL, location.href).href;
      else { var apiOrigin = new URL(API, location.href).origin; url = (apiOrigin === location.origin) ? BOTD_CDN : new URL("/botd.js", API).href; }
    } catch (_) { url = BOTD_CDN; }
    // new Function defers the import() syntax to runtime so ancient browsers that can't parse it
    // (and strict-CSP no-eval pages) fail HERE — caught, fail-open — never break the rest of track.js.
    try { dynImport = new Function("u", "return import(u)"); } catch (_) {}
    if (url && dynImport) {
      try {
        dynImport(url)
          .then(function (B) { return B.load(); })
          .then(function (a) { return a.detect(); })
          .then(function (r) {
            var bv = r && r.bot;
            var isBot = (bv === true) || (bv && bv.result === "bad");
            var kind = (r && r.botKind) || (bv && bv.type) || "";
            firePv({ bot: !!isBot, kind: String(kind || "") });
          })
          .catch(function () { firePv(null); });
      } catch (_) { firePv(null); }
    }
  })();

  // --- human-interaction signal (the behavioral layer) ------------------------------------------
  // UA / IP / OS are strings a bot sets; even dwell is fakeable (headless idles so the engagement ping
  // fires). What a bot rarely produces is genuine INPUT. We record whether ANY trusted interaction
  // happened — mousemove / scroll / wheel / touch / key / pointer. `event.isTrusted` is set by the
  // browser for real hardware input and cannot be forged by a page script (dispatchEvent → false), so
  // a script faking events fails it. Reported on the engagement beacon; a session with dwell but ZERO
  // interaction is scored a bot server-side. Passive + capture so it never blocks or misses an event.
  var _interacted = false;
  function markInteract(e) { if (!_interacted && e && e.isTrusted) _interacted = true; }
  ["pointerdown", "pointermove", "mousemove", "wheel", "scroll", "touchstart", "keydown"].forEach(function (evt) {
    window.addEventListener(evt, markInteract, { passive: true, capture: true });
  });

  // engaged time (dwell): accumulate visible time on this page, report cumulative seconds on
  // hide/unload via beacon. Multiple sends per page are fine — the backend takes the max per
  // (session, page), so a hide→show→leave cycle never double-counts.
  // Cap dwell at 30 min: a tab left open for hours (a "zombie" session) must not keep heart-beating
  // forever — that inflates engagement counts and average session duration. Past the cap we stop.
  var _eng = 0, _t0 = Date.now(), _lastEng = 0, _lastInt = false, _ENG_CAP = 1800;
  function flushEngaged() {
    var total = _eng + (document.visibilityState === "visible" ? (Date.now() - _t0) : 0);
    var secs = Math.min(Math.round(total / 1000), _ENG_CAP);
    // Dedup: on navigation both visibilitychange(hidden) AND pagehide fire flushEngaged in the same instant
    // → the same dwell got logged twice (184,184 / 42,42). Skip a repeat of the last value already sent —
    // UNLESS the interaction state changed since: the final flush may be the ONLY carrier of
    // `interacted:true` (first input after the last heartbeat), and dropping it on a same-second
    // rounding tie would let the idle-proxy sweep bot a real visitor.
    // `interacted` rides the ping so the backend can tell a real visit (input happened) from an idle bot.
    if (secs >= 1 && (secs !== _lastEng || _interacted !== _lastInt)) { _lastEng = secs; _lastInt = _interacted; send("engagement", { engaged_seconds: secs, interacted: _interacted }, true); }
    if (secs >= _ENG_CAP) { clearInterval(_hb); }
  }
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") { _eng += Date.now() - _t0; flushEngaged(); }
    else { _t0 = Date.now(); }
  });
  window.addEventListener("pagehide", flushEngaged);
  // Backup heartbeat: report dwell at 5s, then every 20s of visible time, so a real reader is still
  // counted if their final leave-beacon is lost (crash / mobile kill / blocked beacon). The backend
  // keeps the MAX engaged_seconds per (session, page), so repeated reports never double-count.
  setTimeout(function () { if (document.visibilityState === "visible") flushEngaged(); }, 5000);
  var _hb = setInterval(function () { if (document.visibilityState === "visible") flushEngaged(); }, 20000);
  window.addEventListener("pagehide", function () { clearInterval(_hb); });

  // --- SPA route tracking -----------------------------------------------
  // Client-side routers (Next/React/Framer/Wix Studio…) navigate via history.pushState — no document
  // reload, so only the FIRST page of the visit was counted. Hook pushState/replaceState + popstate:
  // on a real path change, close out the leaving page's dwell (URL is still the old one inside the
  // wrapper), then reset dwell and fire a fresh pageview for the new path. basePayload() reads
  // location at send time, so the new URL rides automatically.
  (function () {
    var lastPath = location.pathname + location.search;
    function routed() {
      var p = location.pathname + location.search;
      if (p === lastPath) return;
      lastPath = p;
      _eng = 0; _t0 = Date.now(); _lastEng = 0;
      send("pageview");
    }
    function wrap(fn) {
      var orig = history[fn];
      if (!orig) return;
      history[fn] = function (st, ti, u) {
        // Only a PATH/QUERY change is a navigation — hash scrolls and our own ?_dd cleanup are not.
        var willChange = false;
        try { if (u != null) { var nu = new URL(u, location.href); willChange = (nu.pathname + nu.search) !== (location.pathname + location.search); } } catch (_) {}
        if (willChange) { try { flushEngaged(); } catch (_) {} }
        var r = orig.apply(this, arguments);
        if (willChange) routed();
        return r;
      };
    }
    wrap("pushState");
    wrap("replaceState");
    window.addEventListener("popstate", function () { setTimeout(routed, 0); });
  })();

  // 404 detection (Astro/React apps that render not-found pages should set <meta name="page-status" content="404">)
  var statusMeta = document.querySelector('meta[name="page-status"]');
  if (statusMeta && statusMeta.getAttribute("content") === "404") send("404_hit");

  // search submit
  document.addEventListener("submit", function (e) {
    var f = e.target;
    if (!f || !f.querySelector) return;
    var inp = f.querySelector('input[type="search"], input[name="q"], input[name="query"]');
    if (CLICKS && inp && inp.value) send("search", { query: String(inp.value).slice(0, 200) });
  }, true);

  // Transit/redirect cloaking slug: /go//i//p//r//check//price//amazon/<ASIN>. These 302 the visitor
  // onward and the SERVER side of the hop owns the analytics row (factory edge worker: server-edge;
  // WP plugins: server-pi/server-rm/AM). track.js must NOT also send a redirect for the click — every
  // deployment logs the hop server-side, so a client send double-counted each buy click (one click =
  // redirect via=click + the server hop row, both counted as clicks).
  var TRANSIT_SLUG = /^\/(?:go|i|p|r|check|price|amazon|link)\/(B[0-9A-Z]{9}|[0-9]{9}[0-9X])\/?$/i;

  // click delegation — count EVERY outbound click (amazon OR any other external/affiliate link,
  // e.g. a transit domain like fitnesswares.com/i/<asin>). Same-site links are internal navigation.
  document.addEventListener("click", function (e) {
    var a = e.target && e.target.closest ? e.target.closest("a") : null;
    if (!a) return;
    var href = a.getAttribute("href") || "";
    if (!href || /^(#|mailto:|tel:|sms:|javascript:)/i.test(href)) return;
    // Per-LINK dedup: a connector-cloaked buy link (data-ddtarget) is counted at the edge by /dd-go
    // when the browser navigates to it. Don't also count it here, or every such click doubles. But
    // FIRST stitch this visitor's session/visitor id onto the /dd-go URL, so the edge click joins THIS
    // human's session — it's then scored human by the same session logic AND becomes the exit step in
    // the full path (reddit -> transit -> page -> exit). Without it the click is session-less and the
    // path breaks at the exit. Raw (un-rewritten) outbound links are still counted below.
    if (a.hasAttribute("data-ddtarget")) {
      // A connector-cloaked buy link: clicking it navigates the browser to /dd-go (a top-level user
      // navigation — privacy browsers / tracker-blockers ALLOW it; it's also the only way the visitor
      // reaches the product, so that domain is always reachable), and the EDGE logs the click. The edge
      // used to have NO browser profile, so every such click scored bot. Fix: ride the visitor's PROFILE
      // (browser/os/device) + SESSION on the /dd-go URL itself, so the edge logs the click WITH the
      // profile and scores it on its own merits — human for a real browser, bot for a profile-less
      // crawler that just fetched the bare href. We deliberately do NOT fire a separate /api/event beacon
      // here: that background POST is exactly what privacy browsers (Mullvad/Brave/etc.) and ad-blockers
      // drop, which silently loses the click. The navigation is the reliable counter → one row, no dup.
      try {
        var dh = a.getAttribute("href") || "";
        if (dh.indexOf("/dd-go") !== -1 && dh.indexOf("?") !== -1) {
          var add = "";
          if (!/[?&]sid=/.test(dh) && SID && VID) add += "&sid=" + encodeURIComponent(SID) + "&vid=" + encodeURIComponent(VID); // cookieless: no ids at all, never the string "null"
          if (!/[?&]b=/.test(dh))   add += "&b=" + encodeURIComponent(DEV.browser) + "&o=" + encodeURIComponent(DEV.os) + "&d=" + encodeURIComponent(DEV.device_type);
          if (navigator.webdriver === true && !/[?&]wd=/.test(dh)) add += "&wd=1";
          if (ACCOUNT && !/[?&]ac=/.test(dh)) add += "&ac=" + encodeURIComponent(ACCOUNT);
          if (add) a.setAttribute("href", dh + add);
        }
      } catch (_) {}
      return;
    }
    var asin = a.getAttribute("data-asin") || null;
    // Resolve to absolute so we can tell outbound (different host) from same-site links.
    var url = href, outbound = false;
    try { var u = new URL(href, location.href); url = u.href; outbound = !!u.host && u.host !== location.host; }
    catch (_) {}
    // Transit/redirect slug: the navigation itself reaches the server hop, which logs the redirect
    // event (with the real IP + capture marker). Just don't count it as an internal_click.
    var slugM = null;
    try { slugM = TRANSIT_SLUG.exec((u && u.pathname) || ""); } catch (_) {}
    if (slugM) return;
    var isAmazon = amazonHost(url) || a.hasAttribute("data-amazon"); // the HOST, both ends bounded: amazon.com.evil.com is not Amazon (DeepSeek round 7)
    // Exclude social-share / sharing widgets (facebook, x, reddit, whatsapp, …) — those are not buy clicks.
    var isShare = /facebook\.|fb\.me|twitter\.|\/\/x\.com|linkedin\.|instagram\.|pinterest\.|reddit\.|redd\.it|tumblr\.|whatsapp|wa\.me|t\.me|telegram|getpocket|flipboard|mix\.com|vk\.com|threads\.net|tiktok\.|youtube\.|youtu\.be|sharer|\/intent\/|share-offsite|[?&]url=|\/submit\?|[?&]text=/i.test(url);

    if (isAmazon) {
      // A real buy/affiliate exit. amazon_outbound_click is the event the dashboard counts as a
      // buy click; via:'click' = a real press. Transit-domain exits (fitnesswares.com/i/<asin>)
      // are owned by their server hop (slug return above). Other outbound links (a blogroll, a
      // staging host) are NOT buy clicks — they used to be sent as amazon_outbound_click and
      // inflated Clicks/CTR with non-buy exits (2026-07-21); they are no longer recorded.
      // amazon_button_click dropped too: it double-rowed every direct Amazon click for no reader.
      // Skip when the connector's inline first-party detector owns clicks (FP_CLICKS) — no double count.
      if (!FP_CLICKS && OUTBOUND) {
        send("amazon_outbound_click", { asin: asin, target_url: url, via: "click" }, true);
      }
    } else if (!outbound) {
      if (CLICKS) send("internal_click", { target_url: url, via: "click" });
    }
  }, true);

  // ad click (heuristic): clicks INSIDE a cross-origin ad iframe can't be read, but clicking one
  // blurs the window and makes that iframe document.activeElement. If the cursor is over it, we
  // record an ad_click. We only learn that an ad was clicked — never which ad or its destination.
  var _overAd = false, _lastAd = 0;
  var AD_SRC = /googlesyndication|doubleclick|adservice|adsystem|googleads|adnxs|amazon-adsystem|media\.net|taboola|outbrain|ezoic|adthrive|mediavine/i;
  function looksLikeAd(el) {
    if (!el || el.tagName !== "IFRAME") return false;
    var src = el.getAttribute("src") || el.src || "";
    if (AD_SRC.test(src)) return true;
    var p = el;
    for (var i = 0; i < 4 && p; i++) {
      var sig = (p.id || "") + " " + (p.className || "");
      if (/(^|[\s_-])(ads?|adslot|adsbygoogle|ad-unit|ad-container|advert)([\s_-]|$)/i.test(sig)) return true;
      p = p.parentElement;
    }
    return false;
  }
  // Track hover over ad iframes via mouseover/mouseout on the iframe ELEMENT (these reach the parent,
  // unlike mousemove inside a cross-origin frame). A blur with the ad iframe focused + cursor over it = click.
  document.addEventListener("mouseover", function (e) { if (looksLikeAd(e.target)) _overAd = true; }, true);
  document.addEventListener("mouseout",  function (e) { if (looksLikeAd(e.target)) _overAd = false; }, true);
  window.addEventListener("blur", function () {
    setTimeout(function () {
      var el = document.activeElement;
      if (!looksLikeAd(el) || !_overAd) return;
      var now = Date.now(); if (now - _lastAd < 1000) return; _lastAd = now;
      var src = el.getAttribute("src") || el.src || "", host = "ad";
      try { host = new URL(src, location.href).host || "ad"; } catch (_) {}
      if (CLICKS) send("ad_click", { target_url: host, via: "ad" }, true);
    }, 0);
  });

  // expose for the mobile handler module
  window.__traffic = { send: send, DEV: DEV, SITE: SITE, API: API };
})();
