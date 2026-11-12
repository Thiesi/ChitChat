// The Direct list (S4b, #170; spec on the issue, comments 6731513054 and
// 6815158178). Like the Rooms list: rows are links in list items
// (/messages.php?with=N), so a conversation can open in a new tab or be
// copied; a plain click or Enter switches in the page.
//
// A row: the person's pebble, their name over the last message ("You: "
// when it was yours) or its state (Blocked, Unavailable, Account closed,
// from #274's fields when the server sends them), the time of that message
// over the unread count. Only what the payload carries; nothing is guessed. Renamed people
// (#164) arrive renamed in the payload and through user_renamed; a pending
// placeholder name shows as it is.
//
// No DOM access at import time, so the pure helpers can be unit-tested.

import { isPlainActivation, listTime } from './room-list.js';

const TOMBSTONE = /^Closed account #\d+$/;

/**
 * Whether the conversation can still be written in, from the payload's own
 * fields (#274): null when the server doesn't send them (then the row shows
 * the last message as before). "Account closed" only for the tombstone name
 * a finalized closure leaves; otherwise an inactive account is "Unavailable",
 * as is a block from the other side, which is never named.
 */
export function conversationState(conversation) {
  const available = conversation.user?.available;
  const relationship = conversation.relationship;
  if (available === false) return TOMBSTONE.test(conversation.user.username ?? '') ? 'Account closed' : 'Unavailable';
  if (relationship?.blocked_by_me === true) return 'Blocked';
  if (relationship?.messaging_available === false) return 'Unavailable';
  return null;
}

/** The row's spoken name: the person, then what's waiting, then its state. The message and time are its description. */
export function conversationRowLabel(conversation) {
  const unread = Number.isInteger(conversation.unread_count) ? conversation.unread_count : 0;
  const parts = [conversation.user.username];
  if (unread > 0) parts.push(`${unread >= 100 ? '99+' : unread} unread`);
  const state = conversationState(conversation);
  if (state) parts.push(state.toLowerCase());
  return parts.join(', ');
}

/** The last message as the row shows it: "You: " for your own. */
export function conversationPreview(conversation) {
  const last = conversation.last_message;
  if (!last) return '';
  return `${last.outgoing ? 'You: ' : ''}${last.body ?? ''}`;
}

/**
 * Renders the list into the container (the #dm-conversation-list nav).
 *
 * @param {HTMLElement} container
 * @param {{
 *   conversations: object[], currentUserId: number|null, pebble: (user: object) => HTMLElement,
 *   onOpen: (user: object, event: MouseEvent, link: HTMLAnchorElement) => void,
 * }} options
 */
export function renderConversationList(container, options) {
  const { conversations } = options;
  const focusedId = container.contains(document.activeElement)
    ? document.activeElement.closest('[data-focus-key]')?.dataset.focusKey ?? null
    : null;
  container.replaceChildren();

  if (conversations.length === 0) {
    // The door is always open: say where conversations start, and go there.
    const empty = document.createElement('div');
    empty.className = 'conversation-empty';
    const text = document.createElement('p');
    text.className = 'messages-muted';
    text.textContent = 'No conversations yet. Say hello to someone from a room, or start one above.';
    const rooms = document.createElement('a');
    rooms.className = 'secondary-button';
    rooms.href = '/';
    rooms.textContent = 'Go to Rooms';
    empty.append(text, rooms);
    container.append(empty);
    return;
  }

  const list = document.createElement('ul');
  list.className = 'conversation-rows';
  list.setAttribute('aria-labelledby', container.getAttribute('aria-labelledby') ?? '');
  for (const conversation of conversations) list.append(row(conversation, options));
  container.append(list);

  if (focusedId) container.querySelector(`[data-focus-key="${focusedId}"]`)?.focus();
}

function row(conversation, options) {
  const { user } = conversation;
  const current = options.currentUserId === user.id;
  const unread = Number.isInteger(conversation.unread_count) ? conversation.unread_count : 0;

  const item = document.createElement('li');
  item.className = 'conversation-row';

  const link = document.createElement('a');
  link.className = 'conversation-button';
  link.href = `/messages.php?with=${encodeURIComponent(user.id)}`;
  link.dataset.userId = String(user.id);
  link.dataset.focusKey = `conversation-${user.id}`;
  link.classList.toggle('active', current);
  link.classList.toggle('unread', unread > 0);
  if (current) link.setAttribute('aria-current', 'page');
  link.setAttribute('aria-label', conversationRowLabel(conversation));

  const name = document.createElement('span');
  name.className = 'conversation-name';
  name.textContent = user.username;

  // A blocked or unavailable conversation says so instead of the last message.
  const state = conversationState(conversation);
  const preview = document.createElement('span');
  preview.className = state ? 'conversation-preview conversation-state' : 'conversation-preview';
  preview.id = `conversation-preview-${user.id}`;
  preview.textContent = state ?? conversationPreview(conversation);
  link.classList.toggle('inactive', state !== null);

  const meta = document.createElement('span');
  meta.className = 'conversation-meta';
  const describedBy = [preview.id];
  const when = listTime(conversation.last_message?.created_at);
  if (when) {
    const time = document.createElement('time');
    time.className = 'conversation-time';
    time.id = `conversation-time-${user.id}`;
    time.dateTime = conversation.last_message.created_at;
    time.textContent = when;
    meta.append(time);
    describedBy.push(time.id);
  }
  if (unread > 0) {
    const badge = document.createElement('span');
    badge.className = unread >= 100 ? 'unread-badge quiet' : 'unread-badge';
    badge.textContent = unread >= 100 ? '99+' : String(unread);
    meta.append(badge);
  }
  link.setAttribute('aria-describedby', describedBy.join(' '));

  link.append(options.pebble(user), name, preview, meta);
  link.addEventListener('click', (event) => {
    if (!isPlainActivation(event)) return;
    event.preventDefault();
    options.onOpen(user, event, link);
  });
  item.append(link);
  return item;
}
