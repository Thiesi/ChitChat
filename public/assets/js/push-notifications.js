import { clearLocalPush, clearLocalPushForDevice, registerBrowserSubscription } from './push-lifecycle.js';
import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';
import { formatDateTime } from './datetime.js';

const elements = {
  unsupported: document.querySelector('#push-unsupported'),
  status: document.querySelector('#push-status'),
  subscribeToggle: document.querySelector('#push-subscribe-toggle'),
  settings: document.querySelector('#push-settings'),
  browser: document.querySelector('#push-browser'),
  browserStatus: document.querySelector('#push-browser-status'),
  devices: document.querySelector('#push-devices'),
  noDevice: document.querySelector('#push-no-device'),
  preferencesForm: document.querySelector('#push-preferences-form'),
  dmEnabled: document.querySelector('#push-dm-enabled'),
  mentionedEnabled: document.querySelector('#push-mentioned-enabled'),
  pingedEnabled: document.querySelector('#push-pinged-enabled'),
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
let dmWasEnabled = false;

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

    const webPush = session.web_push ?? {};
    if (!webPush.enabled || typeof webPush.vapid_public_key !== 'string' || webPush.vapid_public_key === '') {
      elements.status.textContent = 'Alerts aren’t set up on this server.';
      return;
    }
    vapidPublicKey = webPush.vapid_public_key;

    // What should alert you is per account and applies to every device,
    // including the ChitChat app, so it's shown whether or not this browser
    // is subscribed or can subscribe at all (#150).
    elements.settings.classList.remove('hidden');
    elements.devices.classList.remove('hidden');
    elements.browser.classList.remove('hidden');
    elements.preferencesForm.addEventListener('submit', savePreferences);
    await loadPreferencesAndDevices();

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      elements.unsupported.classList.remove('hidden');
      elements.subscribeToggle.closest('.account-action-row')?.classList.add('hidden');
      return;
    }
    await navigator.serviceWorker.register('/sw.js');
    elements.subscribeToggle.disabled = false;
    elements.subscribeToggle.addEventListener('click', toggleSubscription);
    await refreshSubscriptionState();
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
  elements.subscribeToggle.textContent = currentEndpoint ? 'Turn off alerts here' : 'Turn on alerts here';
  elements.browserStatus.textContent = currentEndpoint
    ? 'Alerts are on in this browser.'
    : 'Alerts are off in this browser.';
}

async function toggleSubscription() {
  elements.error.textContent = '';
  elements.subscribeToggle.disabled = true;
  try {
    const registration = await navigator.serviceWorker.ready;
    if (currentEndpoint) {
      await clearLocalPush();
      await apiPost('/api/v1/push/unsubscribe.php', { endpoint: currentEndpoint });
      currentEndpoint = null;
    } else {
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        elements.error.textContent = 'Your browser didn’t allow alerts. You can change that in its site settings.';
        return;
      }
      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
      });
      await registerBrowserSubscription(subscription);
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
    // Off unless the person turned it on (#150); older servers don't send it.
    dmWasEnabled = preferences.direct_message_push_enabled === true;
    elements.dmEnabled.checked = dmWasEnabled;
    elements.mentionedEnabled.checked = preferences.mentioned_push_enabled !== false;
    elements.pingedEnabled.checked = preferences.pinged_push_enabled !== false;

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
  elements.noDevice.classList.toggle('hidden', devices.length !== 0);

  for (const device of devices) {
    const item = document.createElement('li');
    item.className = 'push-device';

    const label = document.createElement('span');
    label.textContent = typeof device.user_agent === 'string' && device.user_agent !== ''
      ? device.user_agent
      : 'Other device';

    const meta = document.createElement('span');
    meta.className = 'account-muted';
    meta.textContent = `since ${formatTimestamp(device.created_at)}`;

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
    if (await clearLocalPushForDevice(id)) {
      currentEndpoint = null;
      updateToggleState();
    }
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
    const dmEnabled = elements.dmEnabled.checked;
    await apiPost('/api/v1/push/update-preferences.php', {
      direct_message_push_enabled: dmEnabled,
      mentioned_push_enabled: elements.mentionedEnabled.checked,
      pinged_push_enabled: elements.pingedEnabled.checked,
      quiet_hours_start: startValue === '' ? null : Number(startValue),
      quiet_hours_end: endValue === '' ? null : Number(endValue),
      quiet_hours_timezone: timezoneValue === '' ? null : timezoneValue,
    });
    // Turning it on never covers earlier messages, so say "from now on".
    elements.preferencesStatus.textContent = dmEnabled && !dmWasEnabled
      ? 'Saved. You’ll be alerted about new direct messages from now on.'
      : 'Saved.';
    dmWasEnabled = dmEnabled;
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
  return formatDateTime(value);
}
