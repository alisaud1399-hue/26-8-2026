/**
 * PMSH Inventory PWA — Service Worker
 *
 * Strategy:
 * - App shell: cache-first (HTML, CSS, JS, icons, manifest)
 * - API GET (lookup, list, etc.): network-first, fallback to cache
 * - API POST/PUT (sync_batch, verify_field): NEVER cached — always network
 *   (when offline, the page's JS queues to IndexedDB and tries later)
 * - Navigation: network-first with offline.html fallback
 *
 * Version: 1.0.0
 * Last update: 2026-07-30
 */

const CACHE_VERSION = 'pmsh-inventory-v1';
const STATIC_CACHE = CACHE_VERSION + '-static';
const RUNTIME_CACHE = CACHE_VERSION + '-runtime';

// App shell: required to load the page even when offline
const APP_SHELL = [
    '/inventory/scan_offline.php',
    '/inventory/manifest.json',
    '/inventory/icon-192.png',
    '/inventory/icon-512.png',
    '/inventory/icon-maskable-192.png',
    '/inventory/icon-maskable-512.png',
    '/inventory/offline.html',
    'https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://unpkg.com/dexie@3.2.7/dist/dexie.min.js'
];

// Install: pre-cache app shell
self.addEventListener('install', event => {
    console.log('[SW] Installing v1...');
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => cache.addAll(APP_SHELL))
            .then(() => self.skipWaiting())
    );
});

// Activate: clean up old caches
self.addEventListener('activate', event => {
    console.log('[SW] Activating...');
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.filter(k => k.startsWith('pmsh-inventory-') && !k.startsWith(CACHE_VERSION))
                    .map(k => {
                        console.log('[SW] Deleting old cache:', k);
                        return caches.delete(k);
                    })
            ))
            .then(() => self.clients.claim())
    );
});

// Helper: is this a write API? (POST/PUT/PATCH/DELETE)
function isWriteRequest(request) {
    return request.method !== 'GET' || (request.method === 'GET' && request.url.includes('?'));
}

// Helper: is this an API call (under /inventory/api/ or similar)?
function isApiRequest(url) {
    return url.pathname.includes('/inventory/api/') ||
           url.pathname.includes('/api/') ||
           url.searchParams.has('action');  // ajax actions
}

// Fetch handler
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // 1) Skip non-http(s) (chrome-extension://, etc.)
    if (!url.protocol.startsWith('http')) return;

    // 2) Write requests (POST/PUT/DELETE) — NEVER cache, never intercept
    //    The page's JS handles offline by queuing to IndexedDB and retrying
    if (request.method !== 'GET') {
        return; // pass through to network
    }

    // 3) API requests — network-first, fallback to cache (for read-only endpoints)
    if (isApiRequest(url)) {
        event.respondWith(
            fetch(request)
                .then(response => {
                    // Cache successful GET API responses
                    if (response && response.status === 200) {
                        const clone = response.clone();
                        caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone));
                    }
                    return response;
                })
                .catch(() => {
                    // Network failed: serve from cache (stale-while-revalidate style)
                    return caches.match(request).then(cached => {
                        if (cached) return cached;
                        // Return a synthetic JSON error
                        return new Response(
                            JSON.stringify({ ok: false, error: 'offline', cached: false }),
                            { status: 503, headers: { 'Content-Type': 'application/json' } }
                        );
                    });
                })
        );
        return;
    }

    // 4) Navigation requests (HTML pages) — network-first, fallback to offline.html
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(response => {
                    if (response && response.status === 200) {
                        const clone = response.clone();
                        caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone));
                    }
                    return response;
                })
                .catch(() => {
                    return caches.match(request).then(cached => {
                        return cached || caches.match('/inventory/offline.html');
                    });
                })
        );
        return;
    }

    // 5) Static assets (JS, CSS, images, fonts) — cache-first
    event.respondWith(
        caches.match(request).then(cached => {
            if (cached) {
                // Background refresh (stale-while-revalidate)
                fetch(request).then(response => {
                    if (response && response.status === 200) {
                        caches.open(RUNTIME_CACHE).then(cache => cache.put(request, response));
                    }
                }).catch(() => {});
                return cached;
            }
            // Not in cache: fetch and cache
            return fetch(request).then(response => {
                if (response && response.status === 200) {
                    const clone = response.clone();
                    caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone));
                }
                return response;
            }).catch(() => {
                // Image fallback
                if (request.destination === 'image') {
                    return new Response(
                        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="#1a5276" width="100" height="100"/><text x="50" y="55" font-size="20" fill="white" text-anchor="middle" font-family="sans-serif">PMSH</text></svg>',
                        { headers: { 'Content-Type': 'image/svg+xml' } }
                    );
                }
            });
        })
    );
});

// Listen for skip waiting message (force update)
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// Background sync (when supported) — flush queue when back online
self.addEventListener('sync', event => {
    if (event.tag === 'pmsh-sync-queue') {
        console.log('[SW] Background sync triggered');
        // Tell all clients to try syncing
        self.clients.matchAll().then(clients => {
            clients.forEach(client => {
                client.postMessage({ type: 'TRIGGER_SYNC' });
            });
        });
    }
});
