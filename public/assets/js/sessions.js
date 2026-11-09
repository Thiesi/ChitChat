// "Where you're signed in" on You (#205). Lists the server's registered
// sign-ins for this account, newest-active first with this browser on top,
// and signs out one of them or every other one. These are server-issued
// sign-ins, not proof a device is online, so the copy never says "online".
// Members only; a guest visit is a single sign-in and has Leave.
import { ApiError, apiGet, apiPost } from './api.js';
import { formatDateTime } from './datetime.js';
import { withPrivilegedStepUp } from './step-up.js';

const card = document.getElementById('sessions-card');
const list = document.getElementById('sessions-list');
const status = document.getElementById('sessions-status');
const error = document.getElementById('sessions-error');
const revokeOthers = document.getElementById('sessions-revoke-others');

if (card && list && status && error && revokeOthers) {
  revokeOthers.addEventListener('click', signOutOthers);
  void start();
}

async function start() {
  let session;
  try {
    session = await apiGet('/api/v1/session.php');
  } catch {
    return;
  }
  if (!session?.user || session.user.guest === true || session.capabilities?.signed_in_sessions !== true) return;
  card.classList.remove('hidden');
  await load();
}

async function load() {
  try {
    const payload = await apiGet('/api/v1/account/sessions/list.php');
    render(Array.isArray(payload.sessions) ? payload.sessions : []);
  } catch (failure) {
    showError(failure, 'Your sign-ins couldn’t be loaded.');
  }
}

function render(sessions) {
  list.replaceChildren(...sessions.map(row));
  revokeOthers.hidden = !sessions.some((entry) => !entry.current);
}

function nameOf(entry) {
  // Labels are untrusted display text; they only ever go into textContent.
  const label = typeof entry.device_label === 'string' && entry.device_label.trim() ? entry.device_label.trim() : null;
  const hint = typeof entry.client_hint === 'string' && entry.client_hint.trim() ? entry.client_hint.trim() : 'Unknown client';
  return { title: label ?? hint, detail: label ? hint : null };
}

function row(entry) {
  const { title, detail } = nameOf(entry);
  const item = document.createElement('li');
  item.className = 'session-row';
  item.dataset.sessionId = String(entry.id ?? '');

  const main = document.createElement('div');
  main.className = 'session-main';
  const name = document.createElement('span');
  name.className = 'session-name';
  name.textContent = title;
  main.append(name);
  if (entry.current) {
    const current = document.createElement('span');
    current.className = 'session-current';
    current.textContent = 'This browser';
    main.append(current);
  }
  const meta = document.createElement('span');
  meta.className = 'session-meta';
  meta.textContent = [detail, `Signed in ${day(entry.created_at)}`, lastActive(entry.last_active_at)].filter(Boolean).join(' · ');
  main.append(meta);
  item.append(main);

  if (!entry.current) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'secondary-button';
    button.textContent = 'Sign out';
    button.setAttribute('aria-label', `Sign out ${title}`);
    button.addEventListener('click', () => void signOutOne(entry, title, item, button));
    item.append(button);
  }
  return item;
}

async function signOutOne(entry, title, item, button) {
  clear();
  button.disabled = true;
  try {
    await apiPost('/api/v1/account/sessions/revoke.php', { id: entry.id });
    removeRow(item);
    status.textContent = `Signed out ${title}.`;
  } catch (failure) {
    if (failure instanceof ApiError && failure.code === 'session_not_found') {
      // Only this means the sign-in is gone (it ended, or was pruned).
      removeRow(item);
      status.textContent = 'That sign-in had already ended.';
      return;
    }
    button.disabled = false;
    showError(failure, 'That sign-in couldn’t be signed out.');
  }
}

async function signOutOthers() {
  clear();
  revokeOthers.disabled = true;
  try {
    // Members confirm with a fresh sign-in check first (privileged step-up).
    const payload = await withPrivilegedStepUp(() => apiPost('/api/v1/account/sessions/revoke-others.php', {}));
    const count = Number.isInteger(payload?.revoked_count) ? payload.revoked_count : 0;
    await load();
    status.textContent = count === 0
      ? 'There were no other sign-ins.'
      : `Signed out everywhere else (${count} ${count === 1 ? 'sign-in' : 'sign-ins'}).`;
    document.getElementById('sessions-heading')?.focus();
  } catch (failure) {
    showError(failure, 'The other sign-ins couldn’t be signed out.');
  } finally {
    revokeOthers.disabled = false;
  }
}

/** Removes a row and keeps focus in the list: the next row's button, else the heading. */
function removeRow(item) {
  const next = item.nextElementSibling ?? item.previousElementSibling;
  const hadFocus = item.contains(document.activeElement);
  item.remove();
  revokeOthers.hidden = !list.querySelector('.session-row button');
  if (!hadFocus) return;
  const target = next?.querySelector('button') ?? document.getElementById('sessions-heading');
  target?.focus();
}

function day(value) {
  return typeof value === 'string' ? formatDateTime(value, { dateStyle: 'medium', timeStyle: null }) : 'at an unknown time';
}

/** Minute precision, written at most every five minutes by the server. */
function lastActive(value) {
  const time = typeof value === 'string' ? Date.parse(value) : Number.NaN;
  if (Number.isNaN(time)) return null;
  const minutes = Math.max(0, Math.round((Date.now() - time) / 60_000));
  if (minutes < 5) return 'Active in the last few minutes';
  if (minutes < 60) return `Last active ${minutes} minutes ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `Last active ${hours} ${hours === 1 ? 'hour' : 'hours'} ago`;
  return `Last active ${formatDateTime(value, { dateStyle: 'medium', timeStyle: null })}`;
}

function clear() {
  status.textContent = '';
  error.textContent = '';
}

function showError(failure, fallback) {
  // The server's message explains what happened (a malformed request, a rate limit with its wait).
  error.textContent = failure instanceof Error && failure.message ? failure.message : fallback;
}
