import { apiGet, apiPost, setCsrfToken } from './api.js';

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
    'room-retention',
    'dm-retention',
    'audit-retention',
    'deleted-attachment-retention',
    'orphan-grace',
    'event-retention',
    'login-retention',
    'settings-updated',
    'save-settings',
    'toast-region',
  ]) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`Missing operational settings element: ${id}`);
    elements[id] = element;
  }

  elements['settings-form'].addEventListener('submit', saveSettings);
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
  elements['settings-loading'].classList.add('hidden');
  elements['settings-shell'].classList.remove('hidden');
}

function renderSettings(settings) {
  currentSettings = settings;
  elements['registration-enabled'].value = settings.registration_enabled ? '1' : '0';
  elements['room-retention'].value = String(settings.room_message_retention_days);
  elements['dm-retention'].value = String(settings.direct_message_retention_days);
  elements['audit-retention'].value = String(settings.audit_retention_days);
  elements['deleted-attachment-retention'].value = String(settings.deleted_attachment_retention_days);
  elements['orphan-grace'].value = String(settings.orphan_attachment_grace_hours);
  elements['event-retention'].value = String(settings.realtime_event_retention_hours);
  elements['login-retention'].value = String(settings.login_attempt_retention_days);
  elements['settings-updated'].textContent = `Last changed ${formatDateTime(settings.updated_at)}.`;
}

async function saveSettings(event) {
  event.preventDefault();
  elements['settings-error'].textContent = '';
  const payload = {
    registration_enabled: elements['registration-enabled'].value === '1',
    room_message_retention_days: numberValue('room-retention'),
    direct_message_retention_days: numberValue('dm-retention'),
    audit_retention_days: numberValue('audit-retention'),
    deleted_attachment_retention_days: numberValue('deleted-attachment-retention'),
    orphan_attachment_grace_hours: numberValue('orphan-grace'),
    realtime_event_retention_hours: numberValue('event-retention'),
    login_attempt_retention_days: numberValue('login-retention'),
  };

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

function numberValue(id) {
  const value = Number.parseInt(elements[id].value, 10);
  return Number.isInteger(value) ? value : -1;
}

function setBusy(busy) {
  for (const control of elements['settings-form'].querySelectorAll('button, input, select')) {
    control.disabled = busy;
  }
}

function formatDateTime(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
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
