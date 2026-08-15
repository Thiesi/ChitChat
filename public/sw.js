// ChitChat Web Push service worker. Registered at the site root so its
// scope covers every page. Push payloads carry only { title, body, link }
// — the same privacy-safe text already shown in the in-app notification
// timeline, never a raw message body. See docs/architecture/0006-web-push.md.

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = {};
  }

  const title = typeof data.title === 'string' && data.title !== '' ? data.title : 'ChitChat';
  const body = typeof data.body === 'string' ? data.body : '';
  const link = typeof data.link === 'string' && data.link !== '' ? data.link : '/';

  event.waitUntil(
    self.registration.showNotification(title, {
      body,
      data: { link },
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const link = event.notification.data?.link ?? '/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url.endsWith(link) && 'focus' in client) {
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(link);
      }
      return undefined;
    }),
  );
});
