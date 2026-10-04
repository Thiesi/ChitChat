// Chat-page navigation: the phone drawer, the members panel, the
// notifications and account popovers, and the sidebar's direct messages.
import { ApiError, apiGet, apiPost } from './api.js';
import { miniAvatar } from './avatar.js';
import { setSoundEnabled, soundEnabled } from './attention.js';

const BADGE_REFRESH_MS = 60_000;
const RECENT_CONVERSATIONS = 8;
const PREVIEW_NOTIFICATIONS = 5;
const MEMBERS_PANEL_KEY = 'chitchat.membersPanel';
const phone = window.matchMedia('(max-width: 34rem)');

let elements = {};
let badgeTimer = null;
let conversationTimer = null;
let openPopover = null;

window.addEventListener('DOMContentLoaded', () => {
  for (const id of [
    'chat-shell', 'sidebar', 'drawer-toggle', 'drawer-close', 'drawer-backdrop',
    'members-toggle', 'members-close', 'presence-panel',
    'notifications-button', 'notifications-popover', 'notifications-preview', 'notifications-mark-all',
    'notification-badge', 'user-menu-button', 'user-menu', 'dm-list',
  ]) {
    const element = document.getElementById(id);
    if (!element) return;
    elements[id] = element;
  }

  bindDrawer();
  bindMembersPanel();
  for (const toggle of document.querySelectorAll('input[data-sound]')) {
    toggle.checked = soundEnabled(toggle.dataset.sound);
    toggle.addEventListener('change', () => setSoundEnabled(toggle.dataset.sound, toggle.checked));
  }
  bindPopover(elements['notifications-button'], elements['notifications-popover'], refreshNotifications);
  bindPopover(elements['user-menu-button'], elements['user-menu']);
  elements['notifications-mark-all'].addEventListener('click', markAllRead);
  elements['drawer-backdrop'].addEventListener('click', closeOverlays);

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (openPopover) {
      const { button } = openPopover;
      closePopover();
      button.focus();
    } else if (isDrawerOpen()) {
      setDrawerOpen(false);
      elements['drawer-toggle'].focus();
    } else if (phone.matches && elements['chat-shell'].classList.contains('members-open')) {
      setMembersOpen(false);
      elements['members-toggle'].focus();
    }
  });
  document.addEventListener('click', (event) => {
    if (openPopover && !openPopover.anchor.contains(event.target)) closePopover();
  });

  window.addEventListener('chitchat:room-chosen', () => setDrawerOpen(false));
  window.addEventListener('chitchat:notifications-changed', () => void refreshBadge());
  window.addEventListener('chitchat:realtime', (event) => {
    if (event.detail?.type === 'direct_message') scheduleConversationRefresh();
  });

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
    elements['dm-list'].replaceChildren();
    return;
  }

  void refreshBadge();
  badgeTimer = window.setInterval(refreshBadge, BADGE_REFRESH_MS);
  void refreshConversations();
}

// ---- Drawer (phones) ----

function bindDrawer() {
  elements['drawer-toggle'].addEventListener('click', () => setDrawerOpen(!isDrawerOpen()));
  elements['drawer-close'].addEventListener('click', () => {
    setDrawerOpen(false);
    elements['drawer-toggle'].focus();
  });
  phone.addEventListener('change', () => {
    if (!phone.matches) setDrawerOpen(false);
  });
}

function isDrawerOpen() {
  return elements['chat-shell'].classList.contains('drawer-open');
}

function setDrawerOpen(open) {
  elements['chat-shell'].classList.toggle('drawer-open', open);
  elements['drawer-toggle'].setAttribute('aria-expanded', String(open));
  if (open) {
    closePopover();
    elements['drawer-close'].focus();
  }
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
  if (open) closePopover();
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

// ---- Popovers ----

function bindPopover(button, popover, onOpen = null) {
  const anchor = button.closest('.popover-anchor');
  button.addEventListener('click', () => {
    if (openPopover?.popover === popover) {
      closePopover();
      return;
    }
    closePopover();
    setDrawerOpen(false);
    if (phone.matches) setMembersOpen(false);
    openPopover = { button, popover, anchor };
    popover.classList.remove('hidden');
    button.setAttribute('aria-expanded', 'true');
    elements['chat-shell'].classList.toggle('sheet-open', phone.matches);
    onOpen?.();
    popover.querySelector('a[href], button:not(.hidden), input')?.focus();
  });
}

function closePopover() {
  if (!openPopover) return;
  openPopover.popover.classList.add('hidden');
  openPopover.button.setAttribute('aria-expanded', 'false');
  elements['chat-shell'].classList.remove('sheet-open');
  openPopover = null;
}

function closeOverlays() {
  closePopover();
  setDrawerOpen(false);
  if (phone.matches) setMembersOpen(false);
}

// ---- Notifications ----

async function refreshBadge() {
  try {
    const payload = await apiGet('/api/v1/account/notifications/list.php?limit=1');
    renderBadge(Number.isInteger(payload.unread_count) ? payload.unread_count : 0);
  } catch (error) {
    handleNotificationError(error);
  }
}

function renderBadge(count) {
  const badge = elements['notification-badge'];
  badge.textContent = count > 99 ? '99+' : String(count);
  badge.classList.toggle('hidden', count === 0);
  elements['notifications-button'].setAttribute(
    'aria-label',
    count === 0 ? 'Notifications, none unread' : `Notifications, ${count} unread`,
  );
  elements['notifications-mark-all'].classList.toggle('hidden', count === 0);
}

async function refreshNotifications() {
  const list = elements['notifications-preview'];
  try {
    const payload = await apiGet(`/api/v1/account/notifications/list.php?limit=${PREVIEW_NOTIFICATIONS}`);
    const notifications = Array.isArray(payload.notifications) ? payload.notifications : [];
    renderBadge(Number.isInteger(payload.unread_count) ? payload.unread_count : 0);
    list.replaceChildren(...(notifications.length > 0
      ? notifications.map(buildNotification)
      : [emptyItem('No notifications yet.')]));
  } catch (error) {
    list.replaceChildren(emptyItem('Notifications could not be loaded.'));
    handleNotificationError(error);
  }
}

function buildNotification(notification) {
  const item = document.createElement('li');
  const hasLink = typeof notification.link === 'string' && notification.link !== '';
  const body = document.createElement(hasLink ? 'a' : 'div');
  body.className = 'notification-item';
  body.classList.toggle('unread', !notification.read);
  if (hasLink) body.href = notification.link;

  const title = document.createElement('strong');
  title.textContent = typeof notification.title === 'string' ? notification.title : 'Notification';
  const message = document.createElement('span');
  message.textContent = typeof notification.message === 'string' ? notification.message : '';
  const time = document.createElement('time');
  time.dateTime = typeof notification.created_at === 'string' ? notification.created_at : '';
  time.textContent = formatTime(notification.created_at);
  body.append(title, message, time);

  if (hasLink && !notification.read && Number.isInteger(notification.id)) {
    body.addEventListener('click', async (event) => {
      event.preventDefault();
      try {
        await apiPost('/api/v1/account/notifications/read.php', { ids: [notification.id] });
      } finally {
        window.location.assign(notification.link);
      }
    });
  }
  item.append(body);
  return item;
}

async function markAllRead() {
  try {
    await apiPost('/api/v1/account/notifications/read.php', { all: true });
  } catch (error) {
    handleNotificationError(error);
  }
  await refreshNotifications();
  elements['notifications-button'].focus();
}

function handleNotificationError(error) {
  if (error instanceof ApiError && error.status === 401) {
    window.clearInterval(badgeTimer);
    badgeTimer = null;
    renderBadge(0);
  }
}

// ---- Direct messages in the sidebar ----

function scheduleConversationRefresh() {
  window.clearTimeout(conversationTimer);
  conversationTimer = window.setTimeout(() => void refreshConversations(), 300);
}

async function refreshConversations() {
  if (!signedIn) return;
  const list = elements['dm-list'];
  try {
    const payload = await apiGet('/api/v1/direct-messages/conversations.php');
    const conversations = Array.isArray(payload.conversations) ? payload.conversations : [];
    list.replaceChildren(...(conversations.length > 0
      ? conversations.slice(0, RECENT_CONVERSATIONS).map(buildConversation)
      : [emptyItem('No conversations yet.')]));
  } catch (error) {
    if (!(error instanceof ApiError && error.status === 401)) {
      list.replaceChildren(emptyItem('Conversations could not be loaded.'));
    }
  }
}

function buildConversation(conversation) {
  const item = document.createElement('li');
  const link = document.createElement('a');
  link.className = 'dm-button';
  link.href = `/messages.php?with=${encodeURIComponent(conversation.user.id)}`;
  const unread = Number.isInteger(conversation.unread_count) ? conversation.unread_count : 0;
  link.classList.toggle('unread', unread > 0);

  const name = document.createElement('span');
  name.className = 'dm-name';
  name.textContent = conversation.user.username;
  link.append(miniAvatar(conversation.user.username, conversation.user.id), name);

  if (unread > 0) {
    const count = document.createElement('span');
    count.className = 'unread-count';
    count.textContent = unread > 99 ? '99+' : String(unread);
    const label = document.createElement('span');
    label.className = 'visually-hidden';
    label.textContent = ' unread';
    count.append(label);
    link.append(count);
  }
  item.append(link);
  return item;
}

// ---- Helpers ----

function emptyItem(text) {
  const item = document.createElement('li');
  item.className = 'room-meta';
  item.textContent = text;
  return item;
}

function formatTime(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? ''
    : date.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}
