import { apiGet, apiPost, setCsrfToken } from './api.js';
import { formatDateTime } from './datetime.js';

const elements = {};
let currentSettings = null;

window.addEventListener('DOMContentLoaded', () => {
  for (const id of [
    'settings-loading',
    'settings-shell',
    'settings-identity',
    'settings-error',
    'settings-form',
    'registration-enabled',
    'guest-access-enabled',
    'admin-mfa-required',
    'room-retention',
    'dm-retention',
    'audit-retention',
    'deleted-attachment-retention',
    'orphan-grace',
    'event-retention',
    'login-retention',
    'deleted-room-grace',
    'settings-updated',
    'save-settings',
    'toast-region',
    'application-name-form',
    'lockdown-form',
    'lockdown-state',
    'lockdown-enabled',
    'lockdown-message',
    'lockdown-sign-out',
    'app-name',
    'app-name-default',
    'registration-protection-form',
    'rp-max-attempts',
    'rp-max-attempts-default',
    'rp-window',
    'rp-window-default',
    'rp-min-fill',
    'rp-min-fill-default',
    'rp-pow-bits',
    'rp-pow-bits-default',
    'rp-effective',
  ]) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`Missing operational settings element: ${id}`);
    elements[id] = element;
  }

  elements['settings-form'].addEventListener('submit', saveSettings);
  elements['registration-protection-form'].addEventListener('submit', saveRegistrationProtection);
  elements['application-name-form'].addEventListener('submit', saveApplicationName);
  elements['lockdown-form'].addEventListener('submit', saveLockdown);
  elements['lockdown-enabled'].addEventListener('change', syncLockdownForm);
  bootstrap().catch(handleFatal);
});

async function bootstrap() {
  const session = await apiGet('/api/v1/session.php');
  setCsrfToken(session.csrf_token);
  const roles = Array.isArray(session.user?.roles) ? session.user.roles : [];
  if (!session.user || !roles.includes('super_admin')) {
    window.location.replace('/admin.php');
    return;
  }

  elements['settings-identity'].textContent = `Signed in as ${session.user.username}`;
  const response = await apiGet('/api/v1/admin/settings/get.php');
  renderSettings(response.settings);
  const lockdown = await apiGet('/api/v1/admin/settings/lockdown/get.php');
  renderLockdown(lockdown.lockdown);
  const name = await apiGet('/api/v1/admin/settings/application-name/get.php');
  renderApplicationName(name.application_name);
  const protection = await apiGet('/api/v1/admin/settings/registration-protection/get.php');
  renderRegistrationProtection(protection.registration_protection);
  elements['settings-loading'].classList.add('hidden');
  elements['settings-shell'].classList.remove('hidden');
}

function renderSettings(settings) {
  currentSettings = settings;
  elements['registration-enabled'].value = settings.registration_enabled ? '1' : '0';
  elements['guest-access-enabled'].value = settings.guest_access_enabled ? '1' : '0';
  elements['admin-mfa-required'].value = settings.mfa_required_for_admin_roles ? '1' : '0';
  elements['room-retention'].value = String(settings.room_message_retention_days);
  elements['dm-retention'].value = String(settings.direct_message_retention_days);
  elements['audit-retention'].value = String(settings.audit_retention_days);
  elements['deleted-attachment-retention'].value = String(settings.deleted_attachment_retention_days);
  elements['orphan-grace'].value = String(settings.orphan_attachment_grace_hours);
  elements['event-retention'].value = String(settings.realtime_event_retention_hours);
  elements['login-retention'].value = String(settings.login_attempt_retention_days);
  elements['deleted-room-grace'].value = String(settings.deleted_room_grace_days ?? 30);
  elements['settings-updated'].textContent = `Last changed ${formatDateTime(settings.updated_at)}.`;
}

async function saveSettings(event) {
  event.preventDefault();
  elements['settings-error'].textContent = '';
  const payload = {
    registration_enabled: elements['registration-enabled'].value === '1',
    guest_access_enabled: elements['guest-access-enabled'].value === '1',
    mfa_required_for_admin_roles: elements['admin-mfa-required'].value === '1',
    room_message_retention_days: numberValue('room-retention'),
    direct_message_retention_days: numberValue('dm-retention'),
    audit_retention_days: numberValue('audit-retention'),
    deleted_attachment_retention_days: numberValue('deleted-attachment-retention'),
    orphan_attachment_grace_hours: numberValue('orphan-grace'),
    realtime_event_retention_hours: numberValue('event-retention'),
    login_attempt_retention_days: numberValue('login-retention'),
    deleted_room_grace_days: numberValue('deleted-room-grace'),
  };

  if (currentSettings?.guest_access_enabled && !payload.guest_access_enabled && !window.confirm(
    'Switching guest access off ends every guest visit at once. Save?',
  )) {
    return;
  }

  const destructive = [
    payload.room_message_retention_days,
    payload.direct_message_retention_days,
    payload.audit_retention_days,
    payload.deleted_attachment_retention_days,
  ].some((value) => value > 0);
  if (destructive && !window.confirm(
    'Nonzero retention values permanently delete older data when maintenance runs. Save this policy?',
  )) {
    return;
  }

  if (
    payload.mfa_required_for_admin_roles
    && !currentSettings?.mfa_required_for_admin_roles
    && !window.confirm(
      'Require passkey-based MFA for every administrative role? Existing administrators must already be enrolled, and future role grants will be refused until the target account enrolls.',
    )
  ) {
    return;
  }

  setBusy(true);
  try {
    const response = await apiPost('/api/v1/admin/settings/update.php', payload);
    renderSettings(response.settings);
    toast('Operational settings saved.');
  } catch (error) {
    elements['settings-error'].textContent = errorMessage(error);
  } finally {
    setBusy(false);
  }
}

function renderApplicationName(name) {
  elements['app-name'].value = name.override ?? '';
  elements['app-name'].placeholder = name.default;
  elements['app-name-default'].textContent = `server default ${name.default}`;
}

function renderLockdown(lockdown) {
  elements['lockdown-enabled'].value = lockdown.enabled ? '1' : '0';
  elements['lockdown-message'].value = lockdown.custom_message ?? '';
  elements['lockdown-message'].placeholder = 'Sign-ins are paused for maintenance. Please try again later.';
  elements['lockdown-sign-out'].checked = false;
  elements['lockdown-state'].textContent = lockdown.enabled
    ? `Lockdown is ON${lockdown.since ? ` since ${formatDateTime(lockdown.since)}` : ''}.`
    : 'Lockdown is off. Everyone can sign in.';
  elements['lockdown-state'].classList.toggle('lockdown-on', lockdown.enabled);
  syncLockdownForm();
}

// Signing others out only makes sense when switching lockdown on.
function syncLockdownForm() {
  const on = elements['lockdown-enabled'].value === '1';
  elements['lockdown-sign-out'].disabled = !on;
  if (!on) elements['lockdown-sign-out'].checked = false;
}

async function saveLockdown(event) {
  event.preventDefault();
  elements['settings-error'].textContent = '';
  const enabled = elements['lockdown-enabled'].value === '1';
  const signOut = elements['lockdown-sign-out'].checked;
  if (signOut && !window.confirm('Sign out everyone except Super-Administrators now? They see your message and cannot sign in again until lockdown ends.')) {
    return;
  }
  const message = elements['lockdown-message'].value.trim();

  setFormBusy(elements['lockdown-form'], true);
  try {
    const response = await apiPost('/api/v1/admin/settings/lockdown/update.php', {
      enabled,
      message: message === '' ? null : message,
      sign_out_others: signOut,
    });
    renderLockdown(response.lockdown);
    const signedOut = response.lockdown.signed_out > 0 ? ` ${response.lockdown.signed_out} accounts were signed out.` : '';
    toast(enabled ? `Lockdown is on.${signedOut}` : 'Lockdown is off. Everyone can sign in again.');
  } catch (error) {
    elements['settings-error'].textContent = errorMessage(error);
  } finally {
    setFormBusy(elements['lockdown-form'], false);
  }
}

async function saveApplicationName(event) {
  event.preventDefault();
  elements['settings-error'].textContent = '';
  const value = elements['app-name'].value.trim();

  setFormBusy(elements['application-name-form'], true);
  try {
    const response = await apiPost('/api/v1/admin/settings/application-name/update.php', {
      application_name: value === '' ? null : value,
    });
    renderApplicationName(response.application_name);
    toast('Application name saved. Pages show it the next time they load.');
  } catch (error) {
    elements['settings-error'].textContent = errorMessage(error);
  } finally {
    setFormBusy(elements['application-name-form'], false);
  }
}

// Each field is an optional override; an empty field uses the server default.
const protectionFields = [
  ['rate_limit_max_attempts', 'rp-max-attempts'],
  ['rate_limit_window_seconds', 'rp-window'],
  ['min_fill_seconds', 'rp-min-fill'],
  ['proof_of_work_bits', 'rp-pow-bits'],
];

function renderRegistrationProtection(protection) {
  for (const [name, id] of protectionFields) {
    const override = protection.overrides[name];
    elements[id].value = override === null ? '' : String(override);
    elements[id].placeholder = String(protection.defaults[name]);
    elements[`${id}-default`].textContent = `server default ${protection.defaults[name]}`;
  }
  const effective = protection.effective;
  elements['rp-effective'].textContent = `In effect: ${effective.rate_limit_max_attempts} attempts per IP every `
    + `${effective.rate_limit_window_seconds} seconds, ${effective.min_fill_seconds}-second minimum fill time, `
    + `${effective.proof_of_work_bits}-bit proof of work.`;
}

async function saveRegistrationProtection(event) {
  event.preventDefault();
  elements['settings-error'].textContent = '';
  const payload = {};
  for (const [name, id] of protectionFields) {
    const raw = elements[id].value.trim();
    payload[name] = raw === '' ? null : Number.parseInt(raw, 10);
  }

  setFormBusy(elements['registration-protection-form'], true);
  try {
    const response = await apiPost('/api/v1/admin/settings/registration-protection/update.php', payload);
    renderRegistrationProtection(response.registration_protection);
    toast('Registration protection saved.');
  } catch (error) {
    elements['settings-error'].textContent = errorMessage(error);
  } finally {
    setFormBusy(elements['registration-protection-form'], false);
  }
}

function numberValue(id) {
  const value = Number.parseInt(elements[id].value, 10);
  return Number.isInteger(value) ? value : -1;
}

function setBusy(busy) {
  setFormBusy(elements['settings-form'], busy);
}

function setFormBusy(form, busy) {
  for (const control of form.querySelectorAll('button, input, select')) {
    control.disabled = busy;
  }
}


function toast(message) {
  const item = document.createElement('div');
  item.className = 'toast';
  item.textContent = message;
  elements['toast-region'].append(item);
  window.setTimeout(() => item.remove(), 5000);
}

function errorMessage(error) {
  return error instanceof Error ? error.message : 'The settings request failed.';
}

function handleFatal(error) {
  elements['settings-loading'].textContent = errorMessage(error);
  elements['settings-error'].textContent = errorMessage(error);
}
