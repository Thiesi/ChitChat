// Chat-page navigation: the members panel, and the unread count for the
// shell's Activity badge. The list pane's tablet overlay and phone stack
// are panes.js (S2c); direct messages live on the Direct tab, and the bell
// and the account menu became Activity and You (S2b, #168).
import { ApiError, apiGet } from './api.js';

const BADGE_REFRESH_MS = 60_000;
const MEMBERS_PANEL_KEY = 'chitchat.membersPanel';
const phone = window.matchMedia('(max-width: 47.99rem)');

let elements = {};
let badgeTimer = null;

window.addEventListener('DOMContentLoaded', () => {
  for (const id of [
    'chat-shell', 'drawer-backdrop', 'members-toggle', 'members-close', 'presence-panel',
  ]) {
    const element = document.getElementById(id);
    if (!element) return;
    elements[id] = element;
  }

  bindMembersPanel();
  // On phones the members sheet shares the backdrop with the list overlay.
  elements['drawer-backdrop'].addEventListener('click', closeOverlays);

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (phone.matches && elements['chat-shell'].classList.contains('members-open')) {
      setMembersOpen(false);
      elements['members-toggle'].focus();
    }
  });

  window.addEventListener('chitchat:notifications-changed', () => void refreshBadge());

  new MutationObserver(synchronizeSession).observe(elements['chat-shell'], {
    attributes: true,
    attributeFilter: ['class'],
  });
  synchronizeSession();
});

// ---- Signed-in shell lifecycle ----

let signedIn = false;

function synchronizeSession() {
  const visible = !elements['chat-shell'].classList.contains('hidden');
  if (visible === signedIn) return;
  signedIn = visible;

  if (!visible) {
    window.clearInterval(badgeTimer);
    badgeTimer = null;
    closeOverlays();
    renderBadge(0);
    window.dispatchEvent(new CustomEvent('chitchat:notifications', { detail: { notifications: [] } }));
    return;
  }

  void refreshBadge();
  badgeTimer = window.setInterval(refreshBadge, BADGE_REFRESH_MS);
}

// ---- Members panel ----

function bindMembersPanel() {
  elements['members-toggle'].addEventListener('click', () => {
    setMembersOpen(!elements['chat-shell'].classList.contains('members-open'), true);
  });
  elements['members-close'].addEventListener('click', () => {
    setMembersOpen(false, true);
    elements['members-toggle'].focus();
  });
  // On phones the panel is a sheet over the chat, so it never opens by itself.
  setMembersOpen(!phone.matches && readPreference() === 'open');
}

function setMembersOpen(open, remember = false) {
  elements['chat-shell'].classList.toggle('members-open', open);
  elements['presence-panel'].classList.toggle('hidden', !open);
  elements['members-toggle'].setAttribute('aria-pressed', String(open));
  if (remember && !phone.matches) writePreference(open ? 'open' : 'closed');
}

function readPreference() {
  try {
    return window.localStorage.getItem(MEMBERS_PANEL_KEY);
  } catch {
    return null;
  }
}

function writePreference(value) {
  try {
    window.localStorage.setItem(MEMBERS_PANEL_KEY, value);
  } catch {
    // Storage may be unavailable (private windows, blocked site data); the panel still works.
  }
}

function closeOverlays() {
  if (phone.matches) setMembersOpen(false);
}

// ---- Notifications ----

// One request serves the badge and the Rooms list: the count, and the recent
// notifications whose unread mentions give rooms their "@" and the mention
// card (S4a). Notifications carry no message text, so this reveals nothing new.
const NOTIFICATION_WINDOW = 50;

async function refreshBadge() {
  try {
    const payload = await apiGet(`/api/v1/account/notifications/list.php?limit=${NOTIFICATION_WINDOW}`);
    renderBadge(Number.isInteger(payload.unread_count) ? payload.unread_count : 0);
    window.dispatchEvent(new CustomEvent('chitchat:notifications', { detail: { notifications: Array.isArray(payload.notifications) ? payload.notifications : [] } }));
  } catch (error) {
    handleNotificationError(error);
  }
}

/** The shell's Activity badge (shell.js) shows the count; no badge lives on this page any more. */
function renderBadge(count) {
  window.dispatchEvent(new CustomEvent('chitchat:activity-count', { detail: { count } }));
}

function handleNotificationError(error) {
  if (error instanceof ApiError && error.status === 401) {
    window.clearInterval(badgeTimer);
    badgeTimer = null;
    renderBadge(0);
  }
}
