// FixariVan Service Worker — static/asset cache only; API is never cached.
// Bump CACHE_VERSION (v2 → v3 → …) when changing frontend/CSS/JS so clients refresh bundles.

const CACHE_VERSION = 'v3';
const CACHE_NAME = 'fixarivan-' + CACHE_VERSION;
const STATIC_CACHE = CACHE_NAME + '-static';
const DYNAMIC_CACHE = CACHE_NAME + '-dynamic';

const STATIC_FILES = [
    '/',
    '/index.html',
    '/index.php',
    '/manifest.json',
    '/favicon.svg',
    '/assets/icons/icon.svg',
    '/assets/icons/logo-mark.svg',
    '/master_form.html',
    '/client_form.html',
    '/receipt.html',
    '/track.html',
    '/inventory.html',
    '/calendar.html',
    '/clients.html',
    '/assets/css/mobile.css',
    '/assets/js/mobile-enhancements.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(STATIC_FILES))
            .then(() => self.skipWaiting())
            .catch((error) => console.error('Service Worker: Cache failed', error))
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((k) => !k.includes(CACHE_VERSION))
                        .map((k) => caches.delete(k))
                )
            )
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }
    const url = request.url;
    if (isAPIRequest(url)) {
        event.respondWith(handleAPIRequest(request));
        return;
    }
    if (isStaticFile(url)) {
        event.respondWith(handleStaticFile(request));
        return;
    }
    if (isHTMLRequest(request)) {
        event.respondWith(handleHTMLRequest(request));
        return;
    }
    event.respondWith(handleOtherRequest(request));
});

function isStaticFile(url) {
    return STATIC_FILES.includes(url) ||
           url.includes('.css') ||
           url.includes('.js') ||
           url.includes('.png') ||
           url.includes('.jpg') ||
           url.includes('.jpeg') ||
           url.includes('.gif') ||
           url.includes('.svg');
}

function isAPIRequest(url) {
    return url.includes('/api/') && url.includes('.php');
}

function isHTMLRequest(request) {
    const accept = request.headers.get('accept');
    return accept && accept.includes('text/html');
}

async function handleStaticFile(request) {
    try {
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(STATIC_CACHE);
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (error) {
        return new Response('Offline - Static file not available', { status: 503 });
    }
}

async function handleAPIRequest(request) {
    try {
        return await fetch(new Request(request, { cache: 'no-store', credentials: 'same-origin' }));
    } catch (error) {
        return new Response(JSON.stringify({
            success: false,
            message: 'Offline - API not available',
            offline: true
        }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
        });
    }
}

async function handleHTMLRequest(request) {
    try {
        return await fetch(new Request(request, { cache: 'no-store', credentials: 'same-origin' }));
    } catch (error) {
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        return new Response('Offline - Page not available', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        });
    }
}

async function handleOtherRequest(request) {
    try {
        return await fetch(request);
    } catch (error) {
        return new Response('Offline', { status: 503 });
    }
}

self.addEventListener('sync', (event) => {
    if (event.tag === 'form-sync') {
        event.waitUntil(Promise.resolve());
    }
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
