const CACHE_NAME = 'dezzme-v3';                     // bumped so phones pick up this version
const OFFLINE_URL = '/Dezzme/offline.html';         // shown when a page cannot load at all (no internet) — keep it in the same folder
const ASSETS = [
    '/Dezzme/index.php',
    '/Dezzme/login.php',
    '/Dezzme/Peura.jpg',        // <- rename this file name too if the picture is no longer called Peura.jpg
    OFFLINE_URL,
];
const ASSET_PATHS = new Set(ASSETS);

// Install — each file is saved on its own, so one missing file can never stop the offline page from being saved
self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE_NAME).then(cache =>
            Promise.all(ASSETS.map(url => cache.add(url).catch(() => {})))
        ).then(() => self.skipWaiting())
    );
});

// Activate — remove old caches, let page requests start while this worker wakes up (navigation preload), take control straight away
self.addEventListener('activate', e => {
    e.waitUntil(
        Promise.all([
            self.registration.navigationPreload ? self.registration.navigationPreload.enable().catch(() => {}) : null,
            caches.keys().then(keys =>
                Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key)))
            ),
        ]).then(() => self.clients.claim())
    );
});

// Fetch — the worker now only steps in where it is needed. Everything else (images, videos, ?type= JSON, POSTs,
// scripts) goes straight to the network exactly as if there were no worker, so it adds no delay.
self.addEventListener('fetch', e => {
    const req = e.request;
    if (req.method !== 'GET') return;

    // A PAGE: use the network (preload starts it in parallel); only if it can't load at all, show offline.html
    if (req.mode === 'navigate') {
        e.respondWith((async () => {
            try {
                const pre = await e.preloadResponse;
                if (pre) return pre;
                return await fetch(req);
            } catch (err) {
                return (await caches.match(OFFLINE_URL)) || Response.error();
            }
        })());
        return;
    }

    // Only the few saved files (logo, offline page) come from the saved copy
    const url = new URL(req.url);
    if (url.origin === self.location.origin && ASSET_PATHS.has(url.pathname) && !url.search) {
        e.respondWith(caches.match(req).then(cached => cached || fetch(req)));
    }
    // anything else: no respondWith → the browser handles it normally
});