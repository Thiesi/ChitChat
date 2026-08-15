import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';

const elements = {
  unsupported: document.querySelector('#push-unsupported'),
  status: document.querySelector('#push-status'),
  subscribeToggle: document.querySelector('#push-subscribe-toggle'),
  settings: document.querySelector('#push-settings'),
  preferencesForm: document.querySelector('#push-preferences-form'),
  mentionedEnabled: document.querySelector('#push-mentioned-enabled'),
  quietStart: document.querySelector('#push-quiet-start'),
  quietEnd: document.querySelector('#push-quiet-end'),
  quietTimezone: document.querySelector('#push-quiet-timezone'),
  preferencesSave: document.querySelector('#push-preferences-save'),
  preferencesStatus: document.querySelector('#push-preferences-status'),
  deviceList: document.querySelector('#push-device-list'),
  deviceEmpty: document.querySelector('#push-device-empty'),
  error: document.querySelector('#push-error'),
};

let vapidPublicKey = null;
let currentEndpoint = null;

void initialize();

async function initialize() {
  if (!elements.subscribeToggle) {
    return;
  }

  try {
    const session = await apiGet('/api/v1/session.php');
    setCsrfToken(session.csrf_token ?? '');
    if (!session.user) {
      return;
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      elements.unsupported.classList.remove('hidden');
      return;
    }

    const webPush = session.web_push ?? {};
    if (!webPush.enabled || typeof webPush.vapid_public_key !== 'string' || webPush.vapid_public_key === '') {
      elements.status.textContent = 'Push notifications are not configured for this installation.';
      return;
    }
    vapidPublicKey = webPush.vapid_public_key;

    await navigator.serviceWorker.register('/sw.js');
    elements.subscribeToggle.disabled = false;
    elements.subscribeToggle.addEventListener('click', toggleSubscription);
    elements.preferencesForm.addEventListener('submit', savePreferences);
    elements.settings.classList.remove('hidden');

    await refreshSubscriptionState();
    await loadPreferencesAndDevices();
  } catch (error) {
    elements.error.textContent = error instanceof Error
      ? error.message
      : 'Unable to initialize push notifications.';
  }
}

async function refreshSubscriptionState() {
  const registration = await navigator.serviceWorker.ready;
  const subscription = await registration.pushManager.getSubscription();
  currentEndpoint = subscription?.endpoint ?? null;
  updateToggleState();
}

function updateToggleState() {
  elements.subscribeToggle.textContent = currentEndpoint
    ? 'Disable push notifications on this device'
    : 'Enable push notifications on this device';
  elements.status.textContent = currentEndpoint
    ? 'Push notifications are enabled on this device.'
    : 'Push notifications are not enabled on this device.';
}

async function toggleSubscription() {
  elements.error.textContent = '';
  elements.subscribeToggle.disabled = true;
  try {
    const registration = await navigator.serviceWorker.ready;
    if (currentEndpoint) {
      const subscription = await registration.pushManager.getSubscription();
      await subscription?.unsubscribe();
      await apiPost('/api/v1/push/unsubscribe.php', { endpoint: currentEndpoint });
      currentEndpoint = null;
    } else {
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        elements.error.textContent = 'Notification permission was not granted.';
        return;
      }
      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
      });
      const json = subscription.toJSON();
      await apiPost('/api/v1/push/subscribe.php', {
        endpoint: json.endpoint,
        p256dh: json.keys?.p256dh ?? '',
        auth: json.keys?.auth ?? '',
      });
      currentEndpoint = subscription.endpoint;
    }
    updateToggleState();
    await loadPreferencesAndDevices();
  } catch (error) {
    elements.error.textContent = error instanceof Error
      ? error.message
      : 'Unable to update this device’s push subscription.';
  } finally {
    elements.subscribeToggle.disabled = false;
  }
}

async function loadPreferencesAndDevices() {
  try {
    const payload = await apiGet('/api/v1/push/preferences.php');
    const preferences = payload.preferences ?? {};
    elements.mentionedEnabled.checked = preferences.mentioned_push_enabled !== false;

    const quietHours = preferences.quiet_hours ?? null;
    elements.quietStart.value = quietHours ? String(quietHours.start) : '';
    elements.quietEnd.value = quietHours ? String(quietHours.end) : '';
    elements.quietTimezone.value = quietHours ? quietHours.timezone : '';

    renderDevices(Array.isArray(payload.devices) ? payload.devices : []);
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      return;
    }
    elements.error.textContent = error instanceof Error
      ? error.message
      : 'Unable to load notification preferences.';
  }
}

function renderDevices(devices) {
  elements.deviceList.replaceChildren();
  elements.deviceEmpty.classList.toggle('hidden', devices.length !== 0);

  for (const device of devices) {
    const item = document.createElement('li');
    item.className = 'push-device';

    const label = document.createElement('span');
    label.textContent = typeof device.user_agent === 'string' && device.user_agent !== ''
      ? device.user_agent
      : 'Unknown device';

    const meta = document.createElement('span');
    meta.className = 'account-muted';
    meta.textContent = `Added ${formatTimestamp(device.created_at)}`;

    const revoke = document.createElement('button');
    revoke.type = 'button';
    revoke.className = 'secondary-button';
    revoke.textContent = 'Remove';
    revoke.addEventListener('click', () => revokeDevice(device.id));

    item.append(label, meta, revoke);
    elements.deviceList.append(item);
  }
}

async function revokeDevice(id) {
  elements.error.textContent = '';
  try {
    const payload = await apiPost('/api/v1/push/revoke-device.php', { id });
    renderDevices(Array.isArray(payload.devices) ? payload.devices : []);
  } catch (error) {
    elements.error.textContent = error instanceof Error ? error.message : 'Unable to remove the device.';
  }
}

async function savePreferences(event) {
  event.preventDefault();
  elements.preferencesStatus.textContent = '';
  elements.error.textContent = '';
  elements.preferencesSave.disabled = true;

  const startValue = elements.quietStart.value;
  const endValue = elements.quietEnd.value;
  const timezoneValue = elements.quietTimezone.value.trim();

  try {
    await apiPost('/api/v1/push/update-preferences.php', {
      mentioned_push_enabled: elements.mentionedEnabled.checked,
      quiet_hours_start: startValue === '' ? null : Number(startValue),
      quiet_hours_end: endValue === '' ? null : Number(endValue),
      quiet_hours_timezone: timezoneValue === '' ? null : timezoneValue,
    });
    elements.preferencesStatus.textContent = 'Saved.';
  } catch (error) {
    elements.error.textContent = error instanceof Error
      ? error.message
      : 'Unable to save notification preferences.';
  } finally {
    elements.preferencesSave.disabled = false;
  }
}

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

function formatTimestamp(value) {
  if (typeof value !== 'string' || value === '') {
    return 'an unknown time';
  }
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}
