// Chat-page navigation: the phone drawer, the members panel and the
// sidebar's direct messages. The bell and the account menu are gone (S2b,
// #168): Activity and You are destinations in the shell. This module still
// keeps the unread count fresh for the shell's Activity badge.
import { ApiError, apiGet } from './api.js';
import { miniAvatar } from './avatar.js';

const BADGE_REFRESH_MS = 60_000;
const RECENT_CONVERSATIONS = 8;
const MEMBERS_PANEL_KEY = 'chitchat.membersPanel';
const phone = window.matchMedia('(max-width: 34rem)');

let elements = {};
let badgeTimer = null;
let conversationTimer = null;

window.addEventListener('DOMContentLoaded', () => {
  for (const id of [
    'chat-shell', 'sidebar', 'drawer-toggle', 'drawer-close', 'drawer-backdrop',
    'members-toggle', 'members-close', 'presence-panel', 'dm-list',
  ]) {
    const element = document.getElementById(id);
    if (!element) return;
    elements[id] = element;
  }

  bindDrawer();
  bindMembersPanel();
  elements['drawer-backdrop'].addEventListener('click', closeOverlays);

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (isDrawerOpen()) {
      setDrawerOpen(false);
      elements['drawer-toggle'].focus();
    } else if (phone.matches && elements['chat-shell'].classList.contains('members-open')) {
      setMembersOpen(false);
      elements['members-toggle'].focus();
    }
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
  if (open) elements['drawer-close'].focus();
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

// ---- Direct messages in the sidebar ----

function scheduleConversationRefresh() {
  window.clearTimeout(conversationTimer);
  conversationTimer = window.setTimeout(() => void refreshConversations(), 300);
}

async function refreshConversations() {
  // Guests have no direct messages; the sidebar invites them to sign up instead.
  if (!signedIn || elements['chat-shell'].classList.contains('guest-mode')) return;
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

