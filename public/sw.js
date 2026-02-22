// ============================================================
// iRadio — Service Worker
// ============================================================

var CACHE_STATIC = 'iradio-static-v1';
var CACHE_ASSETS = 'iradio-assets-v1';
var EXPECTED_CACHES = [CACHE_STATIC, CACHE_ASSETS];

var PRECACHE_URLS = [
  '/',
  '/manifest.json'
];

// ── Install: precache app shell ──────────────────────────────

self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_STATIC).then(function(cache) {
      return cache.addAll(PRECACHE_URLS);
    }).then(function() {
      return self.skipWaiting();
    })
  );
});

// ── Activate: purge old caches, claim clients ────────────────

self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(names) {
      return Promise.all(
        names.filter(function(name) {
          return EXPECTED_CACHES.indexOf(name) === -1;
        }).map(function(name) {
          return caches.delete(name);
        })
      );
    }).then(function() {
      return self.clients.claim();
    })
  );
});

// ── Fetch: route requests by strategy ────────────────────────

self.addEventListener('fetch', function(event) {
  var url = new URL(event.request.url);

  // Ignore cross-origin requests (Icecast on port 8000, external)
  if (url.origin !== self.location.origin) {
    return;
  }

  // API requests → network-only, never cache
  if (url.pathname.indexOf('/api/') === 0) {
    event.respondWith(
      fetch(event.request).catch(function() {
        return new Response(
          JSON.stringify({ error: 'You appear to be offline' }),
          { status: 503, headers: { 'Content-Type': 'application/json' } }
        );
      })
    );
    return;
  }

  // Icon assets → cache-first
  if (url.pathname.indexOf('/assets/') === 0) {
    event.respondWith(
      caches.open(CACHE_ASSETS).then(function(cache) {
        return cache.match(event.request).then(function(cached) {
          if (cached) {
            return cached;
          }
          return fetch(event.request).then(function(response) {
            if (response.ok) {
              cache.put(event.request, response.clone());
            }
            return response;
          });
        });
      }).catch(function() {
        return caches.match('/');
      })
    );
    return;
  }

  // App shell (/, /manifest.json) → stale-while-revalidate
  if (url.pathname === '/' || url.pathname === '/manifest.json') {
    event.respondWith(
      caches.open(CACHE_STATIC).then(function(cache) {
        return cache.match(event.request).then(function(cached) {
          var fetchPromise = fetch(event.request).then(function(response) {
            if (response.ok) {
              cache.put(event.request, response.clone());
            }
            return response;
          }).catch(function() {
            return cached;
          });
          return cached || fetchPromise;
        });
      })
    );
    return;
  }

  // Everything else → network-first, offline fallback to cached shell
  event.respondWith(
    fetch(event.request).catch(function() {
      return caches.match(event.request).then(function(cached) {
        if (cached) {
          return cached;
        }
        // For navigation requests, serve the cached app shell
        if (event.request.mode === 'navigate') {
          return caches.match('/');
        }
        return new Response('Offline', { status: 503 });
      });
    })
  );
});

// ── Background sync placeholder ──────────────────────────────

self.addEventListener('sync', function(event) {
  if (event.tag === 'sync-requests') {
    event.waitUntil(
      // Placeholder: future implementation will read queued requests
      // from IndexedDB and replay them via fetch('/api/request', ...)
      Promise.resolve().then(function() {
        console.log('[SW] sync-requests: background sync triggered (not yet implemented)');
      })
    );
  }
});
