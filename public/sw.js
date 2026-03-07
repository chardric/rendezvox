// ============================================================
// RendezVox — Service Worker (self-clean + reinstall)
// ============================================================

// Nuke all caches on install, then re-register proper SW
self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.keys().then(function(names) {
      return Promise.all(names.map(function(n) { return caches.delete(n); }));
    }).then(function() {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function(event) {
  event.waitUntil(self.clients.claim());
});

// Pass everything through to network — no caching
self.addEventListener('fetch', function(event) {
  event.respondWith(fetch(event.request));
});
