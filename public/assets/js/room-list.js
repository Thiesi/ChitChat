// The Rooms list (S4a, #170; spec on the issue, comments 6731513054 and
// 6815158178). Rows are links in list items: a room has a real address
// (/?room_id=N), so it can open in a new tab or be copied. A plain click or
// Enter switches rooms in the page instead; Ctrl, Cmd or Shift clicks and the
// middle button stay with the browser.
//
// From the top: the newest unread mention as a card, invitations as cards
// with Join, "Your rooms" by recent activity, then "Other rooms" (public and
// unlisted rooms you haven't joined) with Join. On phones Other rooms waits
// behind "Find rooms" at the end of Your rooms.
//
// Every row says only what the server sent: "N here now" and the glow come
// from active_count, the time from last_activity_at, "@" from an unread
// mention notification for the room. Nothing is guessed.
//
// No DOM access at import time, so the pure helpers can be unit-tested.

import { identityHueIndex } from './identity-hue.js';
import { formatDateTime } from './datetime.js';

const DAY_MS = 86_400_000;

/** A room's "#" drawn as its lantern; the text stays "# name" for every reader. */
export function lantern(room) {
  const hash = document.createElement('span');
  hash.className = 'room-hash lantern';
  const hue = identityHueIndex(room.id);
  if (hue !== null) hash.dataset.hue = String(hue);
  hash.textContent = '#';
  return hash;
}

/** Joined rooms, newest activity first; rooms without activity follow by name. */
export function sortByRecency(rooms) {
  const time = (room) => {
    const parsed = Date.parse(room.last_activity_at ?? '');
    return Number.isNaN(parsed) ? null : parsed;
  };
  return [...rooms].sort((a, b) => {
    const ta = time(a);
    const tb = time(b);
    if (ta !== null && tb !== null && ta !== tb) return tb - ta;
    if ((ta === null) !== (tb === null)) return ta === null ? 1 : -1;
    return a.name.localeCompare(b.name);
  });
}

/** Splits the API's rooms into the list's groups. */
export function groupRooms(rooms) {
  const invitations = [];
  const yours = [];
  const others = [];
  for (const room of rooms) {
    if (room.member_role) yours.push(room);
    else if (room.invited) invitations.push(room);
    else others.push(room);
  }
  const byName = (a, b) => a.name.localeCompare(b.name);
  return { invitations: invitations.sort(byName), yours: sortByRecency(yours), others: others.sort(byName) };
}

/**
 * A row's time, like a chat list: the time today, "Yesterday", the weekday
 * within the last week, then the date. Calendar days in the device's zone.
 */
export function listTime(value, now = new Date()) {
  const date = new Date(value ?? '');
  if (value === null || value === undefined || Number.isNaN(date.getTime())) return '';
  const day = (moment) => Math.round(new Date(moment.getFullYear(), moment.getMonth(), moment.getDate()).getTime() / DAY_MS);
  const days = day(now) - day(date);
  if (days <= 0) return formatDateTime(date, { dateStyle: null, timeStyle: 'short' });
  if (days === 1) return 'Yesterday';
  if (days < 7) return weekday(date);
  return formatDateTime(date, { dateStyle: 'short', timeStyle: null });
}

function weekday(date) {
  try {
    return new Intl.DateTimeFormat(undefined, { weekday: 'long' }).format(date);
  } catch {
    return date.toDateString().slice(0, 3);
  }
}

/**
 * Unread room mentions from the notification list, newest first, with the
 * room and message parsed from the server's own link (/?room_id=N&message_id=M).
 */
export function unreadRoomMentions(notifications) {
  const mentions = [];
  for (const item of Array.isArray(notifications) ? notifications : []) {
    if (item?.kind !== 'mentioned' || item.read === true || typeof item.link !== 'string') continue;
    const match = /^\/\?room_id=(\d+)&message_id=(\d+)$/.exec(item.link);
    if (!match) continue;
    mentions.push({ id: item.id, roomId: Number(match[1]), messageId: Number(match[2]), message: item.message ?? '', link: item.link });
  }
  return mentions;
}

/** The row's spoken name: the room, then what's waiting in it. */
export function roomRowLabel(room, { unread = 0, mentioned = false, readOnly = false } = {}) {
  const parts = [room.name];
  if (unread > 0) parts.push(`${unread >= 100 ? '99+' : unread} unread`);
  if (mentioned) parts.push('mentions you');
  if ((room.active_count ?? 0) > 0) parts.push(`${room.active_count} here now`);
  if (room.invited && !room.member_role) parts.push('invited');
  if (room.minimum_age > 0) parts.push(`${room.minimum_age}+`);
  if (room.visibility === 'private') parts.push('private');
  if (room.visibility === 'unlisted') parts.push('unlisted');
  if (readOnly) parts.push('read only');
  return parts.join(', ');
}

/** An unmodified primary click (or Enter) stays in the page; anything else is the browser's. */
export function isPlainActivation(event) {
  return event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
}

/**
 * Renders the list into the container (the #room-list nav).
 *
 * @param {HTMLElement} container
 * @param {{
 *   rooms: object[], currentRoomId: number|null, mentions: object[], canCreate: boolean,
 *   compact: boolean, othersOpen: boolean, readOnly: (room: object) => boolean,
 *   onOpen: (room: object, event: MouseEvent, link: HTMLAnchorElement) => void,
 *   onJoin: (room: object, button: HTMLButtonElement) => void,
 *   onOpenMention: (mention: object, event: MouseEvent) => void,
 *   onToggleOthers: (open: boolean) => void,
 * }} options
 */
export function renderRoomList(container, options) {
  const { rooms, mentions, canCreate, compact, othersOpen } = options;
  const focusedId = container.contains(document.activeElement) ? focusKey(document.activeElement) : null;
  container.replaceChildren();

  if (rooms.length === 0) {
    const message = document.createElement('p');
    message.className = 'room-meta room-list-empty';
    message.textContent = canCreate ? 'No rooms yet. Create the first one.' : 'No rooms are available yet.';
    container.append(message);
    return;
  }

  const mentionedRooms = new Set(mentions.map((mention) => mention.roomId));
  const newest = mentions.find((mention) => rooms.some((room) => room.id === mention.roomId && room.member_role));
  if (newest) container.append(mentionCard(newest, options));

  const { invitations, yours, others } = groupRooms(rooms);
  if (invitations.length > 0) {
    container.append(group('invitations', 'Invitations', invitations.map((room) => row(room, options, mentionedRooms, { join: true, card: true }))));
  }
  if (yours.length > 0) {
    container.append(group('yours', 'Your rooms', yours.map((room) => row(room, options, mentionedRooms))));
  } else if (invitations.length === 0) {
    const hint = document.createElement('p');
    hint.className = 'room-meta room-list-empty';
    hint.textContent = others.length > 0 ? 'You haven’t joined a room yet. Pick one below.' : 'No rooms to join yet.';
    container.append(hint);
  }

  if (others.length > 0) {
    // Phones: "Find rooms" opens the same list, unless there is nothing else to show.
    const behindButton = compact && yours.length > 0;
    const section = group('others', 'Other rooms', others.map((room) => row(room, options, mentionedRooms, { join: true })));
    if (behindButton) {
      const find = document.createElement('button');
      find.type = 'button';
      find.className = 'secondary-button find-rooms-button';
      find.id = 'find-rooms-button';
      find.textContent = 'Find rooms';
      find.setAttribute('aria-expanded', String(othersOpen));
      find.setAttribute('aria-controls', 'room-group-others');
      find.dataset.focusKey = 'find-rooms';
      find.addEventListener('click', () => options.onToggleOthers(!othersOpen));
      container.append(find);
      section.hidden = !othersOpen;
    }
    container.append(section);
  }

  // A re-render (a new message elsewhere, a join) keeps focus where it was.
  if (focusedId) container.querySelector(`[data-focus-key="${focusedId}"]`)?.focus();
}

function focusKey(element) {
  return element.closest('[data-focus-key]')?.dataset.focusKey ?? null;
}

function group(key, title, items) {
  // A plain group, not a landmark: the pane is the region; each list is named by its heading.
  const section = document.createElement('div');
  section.className = `room-group room-group-${key}`;
  section.id = `room-group-${key}`;
  const heading = document.createElement('h2');
  heading.className = 'room-group-heading';
  heading.id = `room-group-${key}-heading`;
  heading.textContent = title;
  const list = document.createElement('ul');
  list.className = 'room-rows';
  list.setAttribute('aria-labelledby', heading.id);
  list.append(...items);
  section.append(heading, list);
  return section;
}

function row(room, options, mentionedRooms, { join = false, card = false } = {}) {
  const current = options.currentRoomId === room.id;
  const unread = current || !room.member_role ? 0 : (room.unread_count ?? 0);
  const mentioned = !current && Boolean(room.member_role) && mentionedRooms.has(room.id);
  const readOnly = options.readOnly(room);
  const here = room.member_role ? (room.active_count ?? 0) : 0;

  const item = document.createElement('li');
  item.className = card ? 'room-row room-invitation' : 'room-row';

  const link = document.createElement('a');
  link.className = 'room-button';
  link.href = `/?room_id=${encodeURIComponent(room.id)}`;
  link.dataset.roomId = String(room.id);
  link.dataset.focusKey = `room-${room.id}`;
  link.classList.toggle('active', current);
  link.classList.toggle('unread', unread > 0);
  if (current) link.setAttribute('aria-current', 'page');
  link.setAttribute('aria-label', roomRowLabel(room, { unread, mentioned, readOnly }));

  const mark = lantern(room);
  mark.classList.toggle('glow', here > 0);
  mark.setAttribute('aria-hidden', 'true');

  // The "# name" text stays together for every reader and for journeys that find rows by it.
  const name = document.createElement('span');
  name.className = 'room-name';
  name.textContent = ` ${room.name}`;

  const sub = document.createElement('span');
  sub.className = 'room-sub';
  sub.id = `room-sub-${room.id}`;
  if (here > 0) {
    const now = document.createElement('span');
    now.className = 'room-row-here';
    now.textContent = `${here} here now`;
    sub.append(now);
    if (room.info_line) sub.append(' · ');
  }
  if (card) sub.append(room.info_line ? `Invitation · ${room.info_line}` : 'You’re invited');
  else if (room.info_line) sub.append(room.info_line);
  if (!sub.textContent) sub.classList.add('empty');

  const meta = document.createElement('span');
  meta.className = 'room-row-meta';
  const when = room.member_role ? listTime(room.last_activity_at) : '';
  if (when) {
    const time = document.createElement('time');
    time.className = 'room-time';
    time.id = `room-time-${room.id}`;
    time.dateTime = room.last_activity_at;
    time.textContent = when;
    meta.append(time);
  }
  const badges = document.createElement('span');
  badges.className = 'room-badges';
  if (mentioned) {
    const at = document.createElement('span');
    at.className = 'mention-badge';
    at.textContent = '@';
    badges.append(at);
  }
  if (unread > 0) {
    const count = document.createElement('span');
    count.className = unread >= 100 ? 'unread-count quiet' : 'unread-count';
    count.textContent = unread >= 100 ? '99+' : String(unread);
    const label = document.createElement('span');
    label.className = 'visually-hidden';
    label.textContent = ' unread';
    count.append(label);
    badges.append(count);
  }
  // Only what changes how the room can be used: invitations, age limits, privacy.
  if (room.invited && !room.member_role && !card) badges.append(flag('invited', 'invited'));
  if (room.minimum_age > 0) badges.append(flag(`${room.minimum_age}+`));
  if (room.visibility !== 'public') badges.append(roomLock(room.visibility));
  if (readOnly) badges.append(flag('read only'));
  if (badges.childElementCount > 0) meta.append(badges);

  // The sub-line and the time are the row's description (S4b): read after its name.
  const described = [sub.textContent ? sub.id : '', when ? `room-time-${room.id}` : ''].filter(Boolean);
  if (described.length > 0) link.setAttribute('aria-describedby', described.join(' '));
  link.append(mark, name, sub, meta);
  link.addEventListener('click', (event) => {
    if (!isPlainActivation(event)) return;
    event.preventDefault();
    options.onOpen(room, event, link);
  });
  item.append(link);

  if (join) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'secondary-button room-join';
    button.dataset.focusKey = `join-${room.id}`;
    const hidden = document.createElement('span');
    hidden.className = 'visually-hidden';
    hidden.textContent = ` ${room.name}`;
    button.append('Join', hidden);
    button.addEventListener('click', () => options.onJoin(room, button));
    item.append(button);
  }
  return item;
}

function mentionCard(mention, options) {
  const card = document.createElement('aside');
  card.className = 'mention-card';
  card.setAttribute('aria-labelledby', 'mention-card-text');
  const at = document.createElement('span');
  at.className = 'mention-badge';
  at.setAttribute('aria-hidden', 'true');
  at.textContent = '@';
  const text = document.createElement('p');
  text.id = 'mention-card-text';
  text.className = 'mention-card-text';
  // The server's own words ("Jonas mentioned you in “Board games night”."); notifications keep no quote.
  text.textContent = mention.message;
  const open = document.createElement('a');
  open.className = 'mention-card-open';
  open.href = mention.link;
  open.dataset.focusKey = 'mention-card';
  open.textContent = 'Open';
  open.setAttribute('aria-describedby', 'mention-card-text');
  open.addEventListener('click', (event) => {
    if (!isPlainActivation(event)) return;
    event.preventDefault();
    options.onOpenMention(mention, event);
  });
  card.append(at, text, open);
  return card;
}

function flag(text, variant = '') {
  const element = document.createElement('span');
  element.className = variant ? `room-flag ${variant}` : 'room-flag';
  element.textContent = text;
  return element;
}

function roomLock(visibility) {
  const lock = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  lock.setAttribute('class', 'room-lock');
  lock.setAttribute('viewBox', '0 0 24 24');
  lock.setAttribute('aria-hidden', 'true');
  lock.setAttribute('focusable', 'false');
  lock.setAttribute('fill', 'none');
  lock.setAttribute('stroke', 'currentColor');
  lock.setAttribute('stroke-width', '2.2');
  lock.setAttribute('stroke-linecap', 'round');
  lock.setAttribute('stroke-linejoin', 'round');
  const shapes = visibility === 'private'
    ? [['rect', { x: '4', y: '11', width: '16', height: '10', rx: '2' }], ['path', { d: 'M8 11V7a4 4 0 0 1 8 0v4' }]]
    : [['path', { d: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z' }], ['line', { x1: '3', y1: '3', x2: '21', y2: '21' }]];
  for (const [tag, attributes] of shapes) {
    const shape = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (const [key, value] of Object.entries(attributes)) shape.setAttribute(key, value);
    lock.append(shape);
  }
  return lock;
}
