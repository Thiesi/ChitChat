import './push-state.js';
import { apiGet, apiPost } from './api.js';

const state = globalThis.ChitChatPushState;
const EXPLICIT_SIGN_OUT = new Set(['/api/v1/logout.php', '/api/v1/account/close.php']);
let stopped = false;
let revision = 0;
let refreshing = null;
let refreshAgain = false;

function supported() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'indexedDB' in window;
}

async function closeNotifications(registration) {
  for (const notification of await registration.getNotifications()) notification.close();
}

export async function clearLocalPush() {
  const intent = ++revision;
  stopped = true;
  if (!supported()) return { revision: intent, epoch: null };
  // Fence outstanding responses before attempting even local subscription cleanup.
  let storageFailed = false;
  let snapshot = null;
  try { snapshot = await state.clear(); } catch { storageFailed = true; }
  const registration = await navigator.serviceWorker.getRegistration('/');
  if (!registration) return { revision: intent, epoch: snapshot?.epoch ?? null };
  await closeNotifications(registration);
  const subscription = await registration.pushManager.getSubscription();
  try {
    await subscription?.unsubscribe();
  } finally {
    await closeNotifications(registration);
    if (storageFailed) await registration.unregister();
  }
  return { revision: intent, epoch: snapshot?.epoch ?? null };
}

export async function pauseLocalPush() {
  const intent = ++revision;
  stopped = true;
  if (!supported()) return { revision: intent, epoch: null };
  let snapshot;
  try {
    snapshot = await state.pause();
  } catch {
    // Without a durable tombstone, keeping a subscription would not be safe.
    return clearLocalPush();
  }
  const registration = await navigator.serviceWorker.getRegistration('/');
  if (registration) await closeNotifications(registration);
  return { revision: intent, epoch: snapshot?.epoch ?? null };
}

export function beforePushIdentityChange(path) {
  return EXPLICIT_SIGN_OUT.has(path) ? clearLocalPush() : pauseLocalPush();
}

export async function completePushIdentityChange(path, intent) {
  // A later logout/disable wins over a delayed sign-in response.
  if (!intent || intent.revision !== revision || EXPLICIT_SIGN_OUT.has(path) || !supported()) return;
  const current = await state.read();
  if (intent.revision !== revision || current?.epoch !== intent.epoch || current?.disabled) return;
  stopped = false;
  ++revision;
  if (refreshing) {
    refreshAgain = true;
  } else {
    void refreshBrowserPush().catch(() => {});
  }
}

export async function clearLocalPushForDevice(id) {
  if ((await state.read())?.id !== id) return false;
  await clearLocalPush();
  return true;
}

export async function registerBrowserSubscription(subscription, session = null, replacesId = null, automatic = false) {
  session ??= await apiGet('/api/v1/session.php');
  if (!session.user || session.user.guest) throw new Error('Sign in to turn on notifications.');
  let current = await state.read();
  if (automatic && current?.disabled) return null;
  if (!current) current = await state.pause();
  const epoch = current.epoch;
  const json = subscription.toJSON();
  const result = await apiPost('/api/v1/push/subscribe.php', {
    endpoint: json.endpoint, p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '',
    ...(Number.isInteger(replacesId) ? { replaces_id: replacesId } : {}),
  });
  if (!await state.save(result, session, epoch, subscription.endpoint)) {
    throw new Error('You signed in as someone else while notifications were being turned on. Try again.');
  }
  return result;
}

export function refreshBrowserPush() {
  refreshing ??= refresh().finally(() => {
    refreshing = null;
    if (refreshAgain && !stopped) {
      refreshAgain = false;
      void refreshBrowserPush().catch(() => {});
    }
  });
  return refreshing;
}

async function refresh() {
  if (!supported() || stopped) return;
  const started = revision;
  const session = await apiGet('/api/v1/session.php');
  if (stopped || started !== revision) return;
  let current = await state.read();
  if (stopped || started !== revision) return;
  if (session.user?.guest) {
    await clearLocalPush();
    return;
  }
  if (!session.user) {
    // Passive expiry is not an explicit opt-out. Keep permission and the
    // raw subscription, but reject its pushes until the same owner signs in.
    await pauseLocalPush();
    return;
  }
  if (current?.user_id && current.user_id !== session.user.id) {
    await clearLocalPush();
    return;
  }
  if (current?.binding && current.session_version !== session.user.session_version) {
    await state.pause();
    const previous = await navigator.serviceWorker.getRegistration('/');
    if (previous) await closeNotifications(previous);
    current = await state.read();
    if (stopped || started !== revision) return;
  }
  if (current?.disabled) return;
  // Permission is requested only by the explicit settings action.
  if (!session.web_push?.enabled || !('Notification' in window) || Notification.permission !== 'granted') return;
  const registration = await navigator.serviceWorker.register('/sw.js');
  const subscription = await registration.pushManager.getSubscription();
  if (!subscription || stopped || started !== revision) return;
  const replacesId = current?.user_id === session.user.id && current.endpoint !== subscription.endpoint ? current.id : null;
  await registerBrowserSubscription(subscription, session, replacesId, true);
}

// Loaded once on every page, including server-rendered rules/privacy pages.
void refreshBrowserPush().catch(() => {});