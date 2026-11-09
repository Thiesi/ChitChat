// Bound, privacy-safe Web Push. The account timeline remains authoritative.
importScripts('/assets/js/push-state.js');
const pushState = globalThis.ChitChatPushState;

function safeLink(value) {
  try {
    const url = new URL(typeof value === 'string' ? value : '/', self.location.origin);
    return url.origin === self.location.origin && ['https:', 'http:'].includes(url.protocol)
      ? url.pathname + url.search + url.hash : '/';
  } catch {
    return '/';
  }
}

async function closeBoundNotifications(binding) {
  for (const notification of await self.registration.getNotifications()) {
    if (!binding || notification.data?.binding === binding) notification.close();
  }
}

self.addEventListener('push', (event) => {
  event.waitUntil((async () => {
    let data;
    try {
      data = event.data?.json();
    } catch {
      return;
    }
    // Upgraded workers fail closed for legacy/unbound data. A page-load refresh
    // upgrades an existing subscription before notifications can be shown again.
    if (!data || !await pushState.claim(data.event_id, data.binding)) return;
    const current = await pushState.read();
    if (current?.binding !== data.binding) return;
    const appName = typeof data.app_name === 'string' && data.app_name ? data.app_name : 'Notification';
    await self.registration.showNotification(typeof data.title === 'string' && data.title ? data.title : appName, {
      body: typeof data.body === 'string' ? data.body : '',
      tag: data.event_id,
      data: { link: safeLink(data.link), binding: data.binding, event_id: data.event_id },
    });
    // Logout can interleave with showNotification's asynchronous completion.
    if ((await pushState.read())?.binding !== data.binding) await closeBoundNotifications(data.binding);
  })().catch(() => {}));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil((async () => {
    const data = event.notification.data ?? {};
    const current = await pushState.read();
    let link = '/';
    if (current?.binding && current.binding === data.binding) {
      try {
        const response = await fetch('/api/v1/session.php', { credentials: 'same-origin', cache: 'no-store' });
        const session = response.ok ? await response.json() : null;
        if (session?.user && session.user.id === current.user_id && session.user.session_version === current.session_version
            && (await pushState.read())?.binding === data.binding) link = safeLink(data.link);
      } catch {
        // An unavailable session cannot authorize the old destination.
      }
    }
    const absolute = new URL(link, self.location.origin).href;
    for (const client of await self.clients.matchAll({ type: 'window', includeUncontrolled: true })) {
      if (client.url === absolute && 'focus' in client) return client.focus();
    }
    return self.clients.openWindow?.(link);
  })().catch(() => {}));
});

self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const current = await pushState.read();
    if (!current?.binding || !current.user_id) return;
    const response = await fetch('/api/v1/session.php', { credentials: 'same-origin', cache: 'no-store' });
    const session = response.ok ? await response.json() : null;
    if (!session?.user || session.user.guest || session.user.id !== current.user_id
        || session.user.session_version !== current.session_version) {
      await pushState.clear();
      await closeBoundNotifications();
      await event.newSubscription?.unsubscribe();
      return;
    }
    const subscription = event.newSubscription
      ?? (event.oldSubscription?.options ? await self.registration.pushManager.subscribe(event.oldSubscription.options) : null);
    if (!subscription) return;
    const json = subscription.toJSON();
    const registration = await fetch('/api/v1/push/subscribe.php', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token },
      body: JSON.stringify({ endpoint: json.endpoint, p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '', replaces_id: current.id }),
    });
    if (registration.ok) await pushState.save(await registration.json(), session, current.epoch, subscription.endpoint);
  })().catch(() => {}));
});