// Bump this on every icon/manifest change so already-installed users pick up
// the new assets instead of keeping a stale cached copy (Lot 2 PWA audit —
// previously this service worker had no fetch handler at all, so icon
// requests were left entirely to the browser's default HTTP cache heuristics).
const STATIC_CACHE_VERSION = 'v3';
const STATIC_CACHE_NAME = `surgicalhub-static-${STATIC_CACHE_VERSION}`;
const STATIC_CACHE_PATH_PATTERN = /\/(icons\/|manifest\.json$)/;

self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys
          .filter(function (key) { return key.startsWith('surgicalhub-static-') && key !== STATIC_CACHE_NAME; })
          .map(function (key) { return caches.delete(key); })
      );
    }).then(function () { return self.clients.claim(); })
  );
});

// Network-first for icons/manifest: always try to fetch the current version
// first so an icon update ships as soon as the device is online, only
// falling back to the last cached copy when offline. This does not control
// the OS/launcher's own home-screen icon cache on an already-installed PWA
// (notably iOS Safari does not refresh it automatically after install) —
// that limitation is outside what a service worker can fix.
self.addEventListener('fetch', function (event) {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || !STATIC_CACHE_PATH_PATTERN.test(url.pathname)) return;

  event.respondWith(
    fetch(event.request)
      .then(function (response) {
        const clone = response.clone();
        caches.open(STATIC_CACHE_NAME).then(function (cache) { cache.put(event.request, clone); });
        return response;
      })
      .catch(function () {
        return caches.match(event.request);
      })
  );
});

self.addEventListener('push', function (event) {
  if (!event.data) return;

  let payload;
  try {
    payload = event.data.json();
  } catch {
    payload = { title: 'SurgicalHub', body: event.data.text() };
  }

  const title = payload.title ?? 'SurgicalHub';
  const options = {
    body: payload.body ?? '',
    icon: '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    data: payload.data ?? {},
  };

  event.waitUntil(
    Promise.all([
      self.registration.showNotification(title, options),
      clients.matchAll({ includeUncontrolled: true, type: 'window' }).then(function (clientList) {
        clientList.forEach(function (client) {
          client.postMessage({ type: 'PUSH_NOTIFICATION', payload });
        });
      }),
    ])
  );
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  // Point 4 (audit UX) — `data.url` (calculé côté serveur, NotificationTargetResolver)
  // est prioritaire : il est correct quel que soit le rôle du destinataire. Repli sur
  // l'ancienne heuristique (toujours /app/i/...) uniquement pour les envois push qui ne
  // le portent pas encore — tous les appelants n'ont pas été retrofit dans ce lot.
  const missionId = event.notification.data?.missionId;
  const url = event.notification.data?.url
    || (missionId ? `/app/i/missions/${missionId}` : '/app/i/today');

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (const client of clientList) {
        if (client.url.includes(url) && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(url);
      }
    })
  );
});
