'use strict';

const CACHE_NAME    = 'totem-v3';
const STATIC_ASSETS = [
  '/totem/',
  '/totem/index.php',
  '/totem/assets/css/totem.css',
  '/totem/assets/js/totem.js',
  'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap',
];

// ── Install: pre-cache static assets ──────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache =>
      Promise.allSettled(
        STATIC_ASSETS.map(url =>
          cache.add(url).catch(() => {}) // Fail silently for external assets
        )
      )
    ).then(() => self.skipWaiting())
  );
});

// ── Activate: clean old caches ─────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

// ── Fetch: strategy by request type ───────────────────────────────────
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Always bypass for API calls, SSE, and non-GET
  if (
    event.request.method !== 'GET' ||
    url.pathname.includes('/api/') ||
    url.pathname.includes('/admin/api/') ||
    url.pathname.includes('sse.php') ||
    url.pathname.includes('health.php')
  ) {
    return; // Let network handle it
  }

  // Network-first for app JS/CSS (ensures updates apply immediately)
  if (url.pathname.match(/\.(css|js)$/)) {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          if (response.ok) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
          }
          return response;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // Cache-first for fonts and images (rarely change)
  if (
    url.pathname.match(/\.(png|jpg|jpeg|webp|svg|ico|woff2?)$/) ||
    url.hostname === 'fonts.googleapis.com' ||
    url.hostname === 'fonts.gstatic.com'
  ) {
    event.respondWith(
      caches.match(event.request).then(cached =>
        cached || fetch(event.request).then(response => {
          if (response.ok) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
          }
          return response;
        })
      )
    );
    return;
  }

  // Network-first for HTML pages
  event.respondWith(
    fetch(event.request)
      .then(response => {
        if (response.ok) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
        }
        return response;
      })
      .catch(() =>
        caches.match(event.request).then(cached => {
          if (cached) return cached;
          // Offline fallback: serve the main page
          return caches.match('/totem/index.php') || new Response(
            '<html><body style="background:#111218;color:#f0f2f8;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;flex-direction:column;gap:16px">' +
            '<div style="font-size:64px">📡</div>' +
            '<h2>Sem conexão</h2>' +
            '<p style="color:#9ca3af">Verifique a rede e tente novamente</p>' +
            '</body></html>',
            { headers: { 'Content-Type': 'text/html' } }
          );
        })
      )
  );
});

// ── Skip waiting when main thread requests it ─────────────────────────
self.addEventListener('message', event => {
  if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});

// ── Background sync: retry failed orders ──────────────────────────────
self.addEventListener('sync', event => {
  if (event.tag === 'retry-order') {
    event.waitUntil(retryPendingOrders());
  }
});

async function retryPendingOrders() {
  const cache = await caches.open('pending-orders');
  const requests = await cache.keys();
  await Promise.allSettled(
    requests.map(async req => {
      try {
        const res = await fetch(req.clone());
        if (res.ok) await cache.delete(req);
      } catch (_) {}
    })
  );
}
