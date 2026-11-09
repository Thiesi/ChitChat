import './push-state.js';
import { apiGet, apiPost } from './api.js';

const state = globalThis.ChitChatPushState;
let stopped = false;
let refreshing = null;

function supported() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'indexedDB' in window;
}

async function closeNotifications(registration) {
  for (const notification of await registration.getNotifications()) notification.close();
}

export async function clearLocalPush() {
  stopped = true;
  // The durable tombstone fences every outstanding registration response.
  // Clear it before attempting network cleanup, including when the browser is offline.
  let storageFailed = false;
  if ('indexedDB' in window) {
    try { await state.clear(); } catch { storageFailed = true; }
  }
  if (!supported()) return;
  const registration = await navigator.serviceWorker.getRegistration('/');
  if (!registration) return;
  await closeNotifications(registration);
  const subscription = await registration.pushManager.getSubscription();
  try {
    await subscription?.unsubscribe();
  } finally {
    await closeNotifications(registration);
    if (storageFailed) await registration.unregister();
  }
}

export async function clearLocalPushForDevice(id) {
  if ((await state.read())?.id !== id) return false;
  await clearLocalPush();
  return true;
}

export async function registerBrowserSubscription(subscription, session = null, replacesId = null) {
  session ??= await apiGet('/api/v1/session.php');
  if (!session.user || session.user.guest) throw new Error('Sign in before enabling push notifications.');
  let current = await state.read();
  if (!current) current = await state.clear();
  const epoch = current.epoch;
  const json = subscription.toJSON();
  const result = await apiPost('/api/v1/push/subscribe.php', {
    endpoint: json.endpoint, p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '',
    ...(Number.isInteger(replacesId) ? { replaces_id: replacesId } : {}),
  });
  if (!await state.save(result, session, epoch, subscription.endpoint)) {
    throw new Error('The login changed while this push device was registered.');
  }
  return result;
}

export function refreshBrowserPush() {
  refreshing ??= refresh().finally(() => { refreshing = null; });
  return refreshing;
}

async function refresh() {
  if (!supported() || stopped) return;
  const session = await apiGet('/api/v1/session.php');
  if (stopped) return;
  const current = await state.read();
  if (!session.user || session.user.guest) {
    await clearLocalPush();
    return;
  }
  if (current?.user_id && (current.user_id !== session.user.id || current.session_version !== session.user.session_version)) {
    await clearLocalPush();
    return;
  }
  // Permission is only requested by the explicit settings action.
  if (!session.web_push?.enabled || !('Notification' in window) || Notification.permission !== 'granted') return;
  const registration = await navigator.serviceWorker.register('/sw.js');
  const subscription = await registration.pushManager.getSubscription();
  if (!subscription || stopped) return;
  const replacesId = current?.user_id === session.user.id && current.endpoint !== subscription.endpoint ? current.id : null;
  await registerBrowserSubscription(subscription, session, replacesId);
}

window.addEventListener('chitchat:session-changed', (event) => {
  if (event.detail?.path === '/api/v1/logout.php' || event.detail?.path === '/api/v1/account/close.php') return;
  stopped = false;
  void refreshBrowserPush().catch(() => {});
});

// HeadAssets loads this once on every page, including server-rendered policy pages.
void refreshBrowserPush().catch(() => {});