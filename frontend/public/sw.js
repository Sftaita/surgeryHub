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

  const missionId = event.notification.data?.missionId;
  const url = missionId ? `/app/i/missions/${missionId}` : '/app/i/today';

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
