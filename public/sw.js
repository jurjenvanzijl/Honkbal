/**
 * Service worker voor offline scoren.
 *  - CSS/JS/iconen: uit de cache, op de achtergrond ververst.
 *  - Scoorscherm (/beheer/wedstrijden/{id}/scoren): eerst netwerk, zonder verbinding de laatst
 *    bewaarde versie. Andere pagina's en alle JSON gaan altijd gewoon over het netwerk.
 * De wedstrijddata zelf staat in localStorage (zie public/js/scorer.js), niet hier.
 */
const VERSION = 'honkbal-v2';
const ASSET_CACHE = VERSION + '-assets';
const PAGE_CACHE = 'honkbal-pages';
const SCOPE = new URL(self.registration.scope).pathname;
const ASSETS = ['css/app.css', 'js/common.js', 'js/engine.js', 'js/scorer.js', 'icon.svg', 'manifest.webmanifest']
    .map((p) => SCOPE + p);

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(ASSET_CACHE).then((cache) => cache.addAll(ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys
                .filter((k) => k.endsWith('-assets') && k !== ASSET_CACHE)
                .map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

const isScorerPage = (url) => /\/beheer\/wedstrijden\/\d+\/scoren$/.test(url.pathname);
const isAsset = (url) => /\.(css|js|svg|webmanifest)$/.test(url.pathname) && !url.pathname.endsWith('/sw.js');

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (isScorerPage(url)) {
        event.respondWith(networkFirst(request));
    } else if (isAsset(url)) {
        event.respondWith(staleWhileRevalidate(request));
    }
});

async function networkFirst(request) {
    const cache = await caches.open(PAGE_CACHE);
    try {
        const response = await fetch(request);
        // Alleen echte pagina's bewaren, geen doorverwijzing naar het inlogscherm.
        if (response.ok && !response.redirected && response.type === 'basic') {
            cache.put(request, response.clone());
        }
        return response;
    } catch (e) {
        const cached = await cache.match(request);
        if (cached) return cached;
        return new Response(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width">' +
            '<title>Offline</title><p style="font-family:system-ui;padding:1rem">Geen verbinding. Dit scoorscherm is nog niet ' +
            'eerder op dit toestel geopend; open het één keer met internet, daarna werkt het ook offline.</p>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(ASSET_CACHE);
    const cached = await cache.match(request, { ignoreSearch: true });
    const network = fetch(request)
        .then((response) => {
            if (response.ok) cache.put(request, response.clone());
            return response;
        })
        .catch(() => cached);
    return cached || network;
}
