// Bump this on every icon/manifest change so already-installed users pick up
// the new assets instead of keeping a stale cached copy (Lot 2 PWA audit —
// previously this service worker had no fetch handler at all, so icon
// requests were left entirely to the browser's default HTTP cache heuristics).
const STATIC_CACHE_VERSION = 'v3';
const STATIC_CACHE_NAME = `surgicalhub-static-${STATIC_CACHE_VERSION}`;
const STATIC_CACHE_PATH_PATTERN = /\/(icons\/|manifest\.json$)/;

// D-175 — fiabilisation Web Push. Bump SW_VERSION on every change of this file: the page reports
// it to the server (POST /api/push/sync), which only expects delivery receipts from a service
// worker that is known to send them.
const SW_VERSION = '2026.10.30-1';
// Shared with the page (src/app/features/push/pushDeviceState.ts) — not a `surgicalhub-static-*`
// cache, so the activate cleanup below never deletes it.
const PUSH_STATE_CACHE = 'surgicalhub-push-state';
const PUSH_SW_KEY = '/__push/sw';
const PUSH_DEVICE_KEY = '/__push/device';
const PUSH_RECEIPTS_KEY = '/__push/receipts';
const RECEIPTS_MAX_QUEUE = 100;
const RECEIPTS_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;
const RECEIPTS_PER_REQUEST = 20;

async function readPushState(key) {
  try {
    const cache = await caches.open(PUSH_STATE_CACHE);
    const res = await cache.match(key);
    return res ? await res.json() : null;
  } catch (e) {
    return null;
  }
}

async function writePushState(key, value) {
  try {
    const cache = await caches.open(PUSH_STATE_CACHE);
    await cache.put(key, new Response(JSON.stringify(value), { headers: { 'Content-Type': 'application/json' } }));
  } catch (e) {
    // Best-effort: without Cache Storage, receipts are simply not sent.
  }
}

// Receipts are queued first, then sent: a device offline when the push arrives sends them on
// the next push, click or page sync. The receipt key only exists inside the encrypted payload
// (one per send) — it is the receipt's whole authentication, never logged.
async function queueReceipt(receipt, event) {
  const now = Date.now();
  const queue = ((await readPushState(PUSH_RECEIPTS_KEY)) || []).filter(function (r) { return now - r.at < RECEIPTS_MAX_AGE_MS; });
  queue.push({ receipt: receipt, event: event, at: now });
  await writePushState(PUSH_RECEIPTS_KEY, queue.slice(-RECEIPTS_MAX_QUEUE));
}

async function flushReceipts() {
  const device = await readPushState(PUSH_DEVICE_KEY);
  if (!device || typeof device.apiBase !== 'string') return; // page never synced yet: keep the queue
  const queue = (await readPushState(PUSH_RECEIPTS_KEY)) || [];
  if (queue.length === 0) return;

  const batch = queue.slice(0, RECEIPTS_PER_REQUEST);
  try {
    const res = await fetch(device.apiBase + '/api/push/receipts', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      // No `keepalive`: event.waitUntil() already keeps the worker alive, and keepalive + a CORS
      // preflight (JSON body to the API origin) is rejected by some Chrome versions.
      body: JSON.stringify({ receipts: batch }),
    });
    // 204 = stored (or unknown key, silently ignored) ; 400 = malformed: never retried. Anything
    // else (offline, 429, 5xx): kept for a later flush.
    if (!res.ok && res.status !== 400) return;
  } catch (e) {
    return;
  }
  const sent = new Set(batch.map(function (r) { return r.receipt + '|' + r.event + '|' + r.at; }));
  const rest = ((await readPushState(PUSH_RECEIPTS_KEY)) || []).filter(function (r) { return !sent.has(r.receipt + '|' + r.event + '|' + r.at); });
  await writePushState(PUSH_RECEIPTS_KEY, rest);
}

function base64UrlToUint8Array(value) {
  const padding = '='.repeat((4 - (value.length % 4)) % 4);
  const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, function (c) { return c.charCodeAt(0); });
}

// The browser replaced (or dropped) the subscription while no page — hence no session — may be
// open. The new subscription is reported with the dedicated device token the page stored after
// POST /api/push/sync: it only allows replacing THIS device's endpoint (PushDeviceRenewalService).
async function renewFromServiceWorker(event) {
  const device = await readPushState(PUSH_DEVICE_KEY);
  if (!device || !device.deviceToken || typeof device.apiBase !== 'string') return;

  let subscription = event.newSubscription || null;
  if (!subscription) {
    const oldKey = event.oldSubscription && event.oldSubscription.options && event.oldSubscription.options.applicationServerKey;
    const key = oldKey || (device.applicationServerKey ? base64UrlToUint8Array(device.applicationServerKey) : null);
    if (!key) return; // the next page sync will re-create it (reconciliation at start-up)
    subscription = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
  }

  const res = await fetch(device.apiBase + '/api/push/device/renew', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ deviceToken: device.deviceToken, subscription: subscription.toJSON(), swVersion: SW_VERSION }),
  });
  if (!res.ok) return; // 401/409: the next page sync reconciles with the session
  const body = await res.json();
  await writePushState(PUSH_DEVICE_KEY, Object.assign({}, device, {
    deviceToken: body.deviceToken || device.deviceToken,
    endpoint: subscription.endpoint,
  }));
}

self.addEventListener('install', function (event) {
  self.skipWaiting();
  event.waitUntil(writePushState(PUSH_SW_KEY, { version: SW_VERSION }));
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys
          .filter(function (key) { return key.startsWith('surgicalhub-static-') && key !== STATIC_CACHE_NAME; })
          .map(function (key) { return caches.delete(key); })
      );
    }).then(function () {
      return writePushState(PUSH_SW_KEY, { version: SW_VERSION });
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
  // D-175 — the receipt key travels with the notification (needed for the OPENED receipt) but is
  // never handed to the pages.
  const receipt = typeof payload.receipt === 'string' ? payload.receipt : null;
  const options = {
    body: payload.body ?? '',
    icon: '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    data: Object.assign({}, payload.data ?? {}, receipt ? { receipt: receipt } : {}),
  };
  const pagePayload = { title: payload.title, body: payload.body, data: payload.data };

  event.waitUntil((async function () {
    if (receipt) await queueReceipt(receipt, 'RECEIVED');
    // DISPLAY_REQUESTED means showNotification() resolved — NOT that Android actually showed it
    // (muted channel, Do Not Disturb, battery saver…).
    try {
      await self.registration.showNotification(title, options);
      if (receipt) await queueReceipt(receipt, 'DISPLAY_REQUESTED');
    } catch (e) {
      if (receipt) await queueReceipt(receipt, 'DISPLAY_FAILED');
    }
    const clientList = await clients.matchAll({ includeUncontrolled: true, type: 'window' });
    clientList.forEach(function (client) {
      client.postMessage({ type: 'PUSH_NOTIFICATION', payload: pagePayload });
    });
    if (receipt) await flushReceipts();
  })());
});

self.addEventListener('pushsubscriptionchange', function (event) {
  event.waitUntil(renewFromServiceWorker(event).catch(function () {}));
});

// The page asks for a flush after each sync (receipts queued while offline or before the page
// ever told this service worker where the API is).
self.addEventListener('message', function (event) {
  if (event.data && event.data.type === 'PUSH_FLUSH_RECEIPTS') {
    event.waitUntil(flushReceipts());
  }
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
  const receipt = event.notification.data?.receipt;
  if (typeof receipt === 'string') {
    event.waitUntil(queueReceipt(receipt, 'OPENED').then(flushReceipts));
  }

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
