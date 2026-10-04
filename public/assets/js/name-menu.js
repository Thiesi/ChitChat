// A small profile card for any username shown as a button: the person's
// picture, any staff badge, and when they joined, with "Send direct message"
// (or why messaging is unavailable), your own Account page when the name is
// yours, and, for moderators, removing an inappropriate picture. One card
// serves the whole page.
import { ApiError, apiGet, apiPost } from './api.js';
import { miniAvatar, refreshPhoto } from './avatar.js';
import { formatDateTime } from './datetime.js';

const phone = window.matchMedia('(max-width: 34rem)');
const ICON_MESSAGE = ['M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'];
const ICON_PERSON = ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z'];
let menu = null;
let backdrop = null;
let opener = null;
let openerMessageId = null;
let currentUserId = null;
let sequence = 0;

/** A username rendered as a button that opens the name menu. */
export function nameButton(user, className = '') {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = className ? `user-name ${className}` : 'user-name';
  button.dataset.userId = String(user.id);
  button.dataset.username = user.username;
  button.setAttribute('aria-haspopup', 'dialog');
  button.textContent = user.username;
  return button;
}

document.addEventListener('click', (event) => {
  const target = event.target instanceof Element ? event.target.closest('.user-name[data-user-id]') : null;
  if (target instanceof HTMLElement) {
    event.preventDefault();
    if (opener && currentOpener() === target) {
      close();
    } else {
      void open(target);
    }
    return;
  }
  if (menu && !menu.classList.contains('hidden') && !menu.contains(event.target)) close();
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && opener) {
    const button = currentOpener();
    close();
    button?.focus();
  }
});

// Message lists re-render while a menu is open, replacing the name that
// opened it; find its replacement so focus still returns to the same name.
function currentOpener() {
  if (!opener || opener.isConnected) return opener;
  const { userId } = opener.dataset;
  const messageId = openerMessageId;
  const scope = messageId ? document.querySelector(`[data-message-id="${CSS.escape(messageId)}"]`) : document;
  return scope?.querySelector(`.user-name[data-user-id="${CSS.escape(userId ?? '')}"]`) ?? null;
}

async function open(button) {
  const userId = Number(button.dataset.userId);
  const username = button.dataset.username ?? button.textContent ?? '';
  if (!Number.isInteger(userId) || userId < 1) return;

  ensureMenu();
  close();
  const request = ++sequence;
  opener = button;
  openerMessageId = button.closest('[data-message-id]')?.dataset.messageId ?? null;
  button.setAttribute('aria-expanded', 'true');

  const identity = document.createElement('div');
  identity.className = 'popover-identity profile-head';
  const name = document.createElement('strong');
  name.textContent = username;
  const label = document.createElement('div');
  label.append(name);
  const avatar = miniAvatar(username, userId);
  avatar.classList.add('profile-avatar');
  identity.append(avatar, label);
  const actions = document.createElement('div');
  actions.className = 'name-menu-actions';
  menu.replaceChildren(identity, document.createElement('hr'), actions);
  menu.setAttribute('aria-label', username);
  menu.classList.remove('hidden');
  backdrop.classList.toggle('hidden', !phone.matches);
  position(button);

  const profileRequest = apiGet(`/api/v1/users/profile.php?user_id=${encodeURIComponent(userId)}`).catch(() => null);
  const self = (await ownUserId()) === userId;
  if (request !== sequence) return;
  void profileRequest.then((response) => {
    if (request === sequence && response?.profile) showProfile(label, actions, response.profile, self);
  });
  if (self) {
    const note = document.createElement('span');
    note.className = 'profile-meta';
    note.textContent = 'This is you';
    label.append(note);
    actions.append(menuLink('/account.php', 'Your account', ICON_PERSON));
    focusFirst();
    return;
  }

  const dm = menuLink(`/messages.php?with=${encodeURIComponent(userId)}`, 'Send direct message', ICON_MESSAGE);
  actions.append(dm);
  focusFirst();

  try {
    const response = await apiGet(`/api/v1/direct-messages/block-status.php?user_id=${encodeURIComponent(userId)}`);
    if (request !== sequence) return;
    const relationship = response.relationship ?? {};
    if (!relationship.messaging_available) {
      // History stays readable, so the link remains; it just says what to expect.
      dm.lastChild.textContent = 'View direct messages';
      const status = document.createElement('p');
      status.className = 'name-menu-status';
      status.textContent = relationship.blocked_by_me
        ? `You blocked ${username}. Unblock them in direct messages to write again.`
        : 'Direct messaging with this person is unavailable.';
      actions.append(status);
    }
  } catch (error) {
    if (error instanceof ApiError && error.status === 404 && request === sequence) {
      actions.replaceChildren(statusText('This account is no longer available.'));
    }
  }
}

// Fills in what the profile adds: a staff badge, when they joined, and the
// moderator action for an inappropriate picture.
function showProfile(label, actions, profile, self) {
  if (profile.badge) {
    const badge = document.createElement('span');
    badge.className = 'profile-badge';
    badge.textContent = profile.badge;
    label.querySelector('strong')?.after(badge);
  }
  const since = document.createElement('span');
  since.className = 'profile-meta';
  since.textContent = `Member since ${formatDateTime(profile.member_since, { dateStyle: 'long', timeStyle: null })}`;
  label.append(since);

  if (profile.can_remove_avatar && !self) {
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'menu-item danger-item';
    remove.textContent = 'Remove profile picture';
    remove.addEventListener('click', async () => {
      if (!window.confirm(`Remove ${profile.username}'s profile picture? They will be told, and it is recorded in the audit log.`)) return;
      remove.disabled = true;
      try {
        await apiPost('/api/v1/account/avatar/remove.php', { user_id: profile.id });
        refreshPhoto(profile.id);
        remove.replaceWith(statusText('Profile picture removed.'));
      } catch (error) {
        remove.disabled = false;
        remove.after(statusText(error instanceof Error ? error.message : 'Removing the picture failed.'));
      }
    });
    actions.append(document.createElement('hr'), remove);
  }
}

function close() {
  sequence += 1;
  if (!menu) return;
  menu.classList.add('hidden');
  backdrop.classList.add('hidden');
  currentOpener()?.setAttribute('aria-expanded', 'false');
  opener = null;
  openerMessageId = null;
}

function ensureMenu() {
  if (menu) return;
  menu = document.createElement('section');
  menu.id = 'name-menu';
  menu.className = 'popover name-menu hidden';
  backdrop = document.createElement('div');
  backdrop.className = 'name-menu-backdrop hidden';
  backdrop.setAttribute('aria-hidden', 'true');
  backdrop.addEventListener('click', close);
  document.body.append(backdrop, menu);
}

function position(button) {
  if (phone.matches) {
    menu.style.removeProperty('top');
    menu.style.removeProperty('left');
    return;
  }
  const rect = button.getBoundingClientRect();
  const width = menu.offsetWidth;
  const height = menu.offsetHeight;
  const left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 8);
  const below = rect.bottom + 6;
  const top = below + height > window.innerHeight - 8 ? Math.max(8, rect.top - height - 6) : below;
  menu.style.left = `${left}px`;
  menu.style.top = `${top}px`;
}

function menuLink(href, text, paths) {
  const link = document.createElement('a');
  link.className = 'menu-item';
  link.href = href;
  const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  icon.setAttribute('viewBox', '0 0 24 24');
  icon.setAttribute('aria-hidden', 'true');
  icon.setAttribute('focusable', 'false');
  for (const [key, value] of Object.entries({ fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })) {
    icon.setAttribute(key, value);
  }
  for (const d of paths) {
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', d);
    icon.append(path);
  }
  link.append(icon);
  const label = document.createElement('span');
  label.textContent = text;
  link.append(label);
  return link;
}

function statusText(text) {
  const status = document.createElement('p');
  status.className = 'name-menu-status';
  status.textContent = text;
  return status;
}

function focusFirst() {
  menu.querySelector('a[href]')?.focus();
}

async function ownUserId() {
  if (currentUserId !== null) return currentUserId;
  try {
    const session = await apiGet('/api/v1/session.php');
    currentUserId = session.user?.id ?? null;
  } catch {
    currentUserId = null;
  }
  return currentUserId;
}

// A sign-in or sign-out on this page changes who "you" are.
window.addEventListener('chitchat:session-changed', () => {
  currentUserId = null;
  close();
});
