// A small menu for any username shown as a button: it offers "Send direct
// message" (or explains why messaging is unavailable), and points to your
// own Account page when the name is yours. One menu serves the whole page.
import { ApiError, apiGet } from './api.js';
import { miniAvatar } from './avatar.js';

const phone = window.matchMedia('(max-width: 34rem)');
const ICON_MESSAGE = ['M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'];
const ICON_PERSON = ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z'];
let menu = null;
let backdrop = null;
let opener = null;
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
    if (opener === target) {
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
    const button = opener;
    close();
    button.focus();
  }
});

async function open(button) {
  const userId = Number(button.dataset.userId);
  const username = button.dataset.username ?? button.textContent ?? '';
  if (!Number.isInteger(userId) || userId < 1) return;

  ensureMenu();
  close();
  const request = ++sequence;
  opener = button;
  button.setAttribute('aria-expanded', 'true');

  const identity = document.createElement('div');
  identity.className = 'popover-identity';
  const name = document.createElement('strong');
  name.textContent = username;
  const label = document.createElement('div');
  label.append(name);
  identity.append(miniAvatar(username, userId), label);
  const actions = document.createElement('div');
  actions.className = 'name-menu-actions';
  menu.replaceChildren(identity, document.createElement('hr'), actions);
  menu.setAttribute('aria-label', username);
  menu.classList.remove('hidden');
  backdrop.classList.toggle('hidden', !phone.matches);
  position(button);

  const self = (await ownUserId()) === userId;
  if (request !== sequence) return;
  if (self) {
    const note = document.createElement('span');
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

function close() {
  sequence += 1;
  if (!menu) return;
  menu.classList.add('hidden');
  backdrop.classList.add('hidden');
  opener?.setAttribute('aria-expanded', 'false');
  opener = null;
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
