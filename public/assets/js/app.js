import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';
import { createPresenceClient } from './presence.js';
import { renderMessageBody, buildReplyPreview, buildReactionBar } from './message-content.js';
import { attachMentionAutocomplete } from './mention-autocomplete.js';
import { attachEmojiPicker } from './emoji-picker.js';
import { createRegistrationChallenge } from './registration-challenge.js';
import { attachPhoto, avatarTone, initials } from './avatar.js';
import { nameButton } from './name-menu.js';
import { attachNameCompletion } from './name-completion.js';
import { COMMANDS, SHRUG, attachCommandSuggestions, parseSlashCommand, showCommandHelp, splitTarget } from './slash-commands.js';
import { alertUser } from './attention.js';
import { formatDateTime } from './datetime.js';

const registrationChallenge = createRegistrationChallenge();

const state = {
  user: null,
  rooms: [],
  currentRoom: null,
  readMarker: null,
  newWhileAway: 0,
  // People this account ignores in rooms, and their messages shown anyway.
  ignored: new Set(),
  revealed: new Set(),
  messages: [],
  messageIds: new Set(),
  oldestMessageId: null,
  eventSource: null,
  replyTo: null,
  roomMembers: [],
  pings: [],
};

const elements = {};
let presence = null;
let mentionAutocomplete = null;
let commandSuggestions = null;

window.addEventListener('DOMContentLoaded', () => {
  bindElements();
  bindEvents();
  presence = createPresenceClient({
    getCurrentRoom: () => state.currentRoom,
    getCurrentUserId: () => state.user?.id ?? null,
    canOccupy: canUsePresence,
    onExpired: handlePresenceExpired,
    onUnauthorized: () => forceSignedOut('Your session has ended. Please sign in again.'),
    toast,
  });
  bootstrap().catch(handleFatalError);
});

function bindElements() {
  for (const id of [
    'app-loading',
    'auth-shell',
    'login-tab',
    'register-tab',
    'register-provider-note',
    'register-provider-text',
    'register-provider-cancel',
    'register-password-field',
    'login-form',
    'login-username',
    'login-password',
    'register-form',
    'register-username',
    'register-password',
    'register-birth-date',
    'register-website',
    'auth-error',
    'chat-shell',
    'connection-status',
    'room-list',
    'current-user',
    'user-initials',
    'user-menu-avatar',
    'user-menu-button',
    'logout-button',
    'new-room-button',
    'room-title',
    'room-info',
    'join-button',
    'empty-state',
    'message-list',
    'load-older-button',
    'jump-latest',
    'composer-wrap',
    'composer-form',
    'composer-input',
    'emoji-button',
    'send-button',
    'toast-region',
    'room-dialog',
    'room-create-form',
    'room-key',
    'room-name',
    'room-info-line',
    'room-visibility',
    'room-minimum-age',
    'room-inactivity-timeout',
    'room-dialog-error',
    'room-dialog-cancel',
    'reply-banner',
    'reply-banner-text',
    'reply-banner-cancel',
  ]) {
    const element = document.getElementById(id);
    if (!element) {
      throw new Error(`Missing required interface element: ${id}`);
    }
    elements[id] = element;
  }
}

function bindEvents() {
  elements['login-tab'].addEventListener('click', () => showAuthMode('login'));
  elements['register-tab'].addEventListener('click', () => showAuthMode('register'));
  elements['register-provider-cancel'].addEventListener('click', cancelProviderSignUp);
  elements['login-form'].addEventListener('submit', submitLogin);
  elements['register-form'].addEventListener('submit', submitRegistration);
  // The challenge's issue time starts the server's minimum-fill clock, so it is
  // fetched as soon as the form is shown or used, however it was reached.
  elements['register-form'].addEventListener('focusin', () => {
    registrationChallenge.prepare().catch(() => {});
  });
  elements['logout-button'].addEventListener('click', submitLogout);
  elements['join-button'].addEventListener('click', joinCurrentRoom);
  elements['composer-form'].addEventListener('submit', submitMessage);
  elements['composer-input'].addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      if (mentionAutocomplete?.isOpen() || commandSuggestions?.isOpen()) return;
      event.preventDefault();
      elements['composer-form'].requestSubmit();
    }
  });
  // Before name completion, so Tab picks a command rather than a name.
  commandSuggestions = attachCommandSuggestions(elements['composer-input'], availableCommands);
  mentionAutocomplete = attachMentionAutocomplete(elements['composer-input'], searchRoomMentions);
  attachNameCompletion(elements['composer-input'], completionCandidates);
  attachEmojiPicker(elements['emoji-button'], elements['composer-input']);
  elements['load-older-button'].addEventListener('click', loadOlderMessages);
  elements['jump-latest'].addEventListener('click', jumpToLatest);
  elements['message-list'].addEventListener('scroll', onMessageListScroll, { passive: true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && isNearBottom()) scheduleMarkRead();
  });
  window.addEventListener('chitchat:ignore-changed', (event) => {
    const { userId, ignored } = event.detail ?? {};
    if (!Number.isInteger(userId)) return;
    if (ignored) state.ignored.add(userId);
    else state.ignored.delete(userId);
    renderMessages();
  });
  elements['reply-banner-cancel'].addEventListener('click', clearReplyTo);
  elements['new-room-button'].addEventListener('click', openRoomDialog);
  elements['room-dialog-cancel'].addEventListener('click', () => elements['room-dialog'].close());
  elements['room-create-form'].addEventListener('submit', createRoom);
}

async function bootstrap() {
  const session = await apiGet('/api/v1/session.php');
  setCsrfToken(session.csrf_token);
  state.ignored = new Set(Array.isArray(session.ignored_user_ids) ? session.ignored_user_ids : []);
  renderLockdown(session.lockdown);
  renderSignInProviders(session.sign_in_providers);
  elements['app-loading'].classList.add('hidden');

  if (session.user) {
    await enterApplication(session.user);
  } else {
    showAuthMode(session.pending_sign_up ? 'register' : 'login');
    setProviderSignUp(session.pending_sign_up ?? null);
    showSignInErrorFromRedirect();
    elements['auth-shell'].classList.remove('hidden');
    if (session.pending_sign_up) elements['register-username'].focus();
  }
}

async function enterApplication(user) {
  state.user = user;
  elements['auth-shell'].classList.add('hidden');
  elements['chat-shell'].classList.remove('hidden');
  elements['current-user'].textContent = user.username;
  for (const avatar of [elements['user-initials'], elements['user-menu-avatar']]) {
    avatar.textContent = initials(user.username);
  }
  elements['user-menu-avatar'].dataset.tone = String(avatarTone(user.id));
  for (const avatar of [elements['user-menu-button'], elements['user-menu-avatar']]) {
    avatar.dataset.avatarUser = String(user.id);
    attachPhoto(avatar, user.id);
  }
  elements['new-room-button'].classList.toggle('hidden', !canCreateRooms(user));
  clearAuthError();
  presence.start();
  startEventStream();
  await loadRooms();
}

function showAuthMode(mode) {
  const login = mode === 'login';
  elements['login-tab'].setAttribute('aria-selected', String(login));
  elements['register-tab'].setAttribute('aria-selected', String(!login));
  elements['login-form'].classList.toggle('hidden', !login);
  elements['register-form'].classList.toggle('hidden', login);
  clearAuthError();
  if (!login) registrationChallenge.prepare().catch(() => {});
}

async function submitLogin(event) {
  event.preventDefault();
  setFormBusy(elements['login-form'], true);
  clearAuthError();

  try {
    const response = await apiPost('/api/v1/login.php', {
      username: elements['login-username'].value,
      password: elements['login-password'].value,
    });
    elements['login-form'].reset();
    await enterApplication(response.user);
  } catch (error) {
    showAuthError(error);
  } finally {
    setFormBusy(elements['login-form'], false);
  }
}

async function submitRegistration(event) {
  event.preventDefault();
  setFormBusy(elements['register-form'], true);
  clearAuthError();

  try {
    const proof = await registrationChallenge.solution();
    const details = {
      username: elements['register-username'].value,
      birth_date: elements['register-birth-date'].value || null,
      website: elements['register-website'].value,
      challenge_nonce: proof?.nonce ?? null,
      challenge_solution: proof?.solution ?? null,
    };
    // After Google or Twitch confirmed who this is, no password is needed.
    const response = state.providerSignUp
      ? await apiPost('/api/v1/oidc/register.php', details)
      : await apiPost('/api/v1/register.php', { ...details, password: elements['register-password'].value });
    registrationChallenge.reset();
    elements['register-form'].reset();
    setProviderSignUp(null);
    await enterApplication(response.user);
  } catch (error) {
    // Every submission consumes the challenge, so prepare a fresh one.
    registrationChallenge.reset();
    registrationChallenge.prepare().catch(() => {});
    showAuthError(error);
  } finally {
    setFormBusy(elements['register-form'], false);
  }
}

async function submitLogout() {
  elements['logout-button'].disabled = true;
  try {
    await apiPost('/api/v1/logout.php');
  } catch (error) {
    if (!(error instanceof ApiError && error.status === 401)) {
      toast(errorMessage(error), 'error');
    }
  } finally {
    elements['logout-button'].disabled = false;
    forceSignedOut('You have been logged out.');
  }
}

async function loadRooms(preferredRoomId = null) {
  try {
    const response = await apiGet('/api/v1/rooms/list.php');
    state.rooms = Array.isArray(response.rooms) ? response.rooms : [];
    renderRoomList();

    const desiredId = preferredRoomId ?? state.currentRoom?.id ?? null;
    const desired = desiredId === null
      ? chooseInitialRoom()
      : state.rooms.find((room) => room.id === desiredId) ?? chooseInitialRoom();

    if (desired) {
      await selectRoom(desired);
    } else {
      state.currentRoom = null;
      renderNoRoom();
      await presence.enterCurrentRoom(true);
    }
  } catch (error) {
    handleApiFailure(error);
  }
}

// A rooms_changed event only says "your room list may differ now"; bursts
// (several invitations, a rename right after creation) collapse into one fetch.
let roomListRefreshTimer = null;

function scheduleRoomListRefresh() {
  window.clearTimeout(roomListRefreshTimer);
  roomListRefreshTimer = window.setTimeout(() => {
    refreshRoomList().catch(handleApiFailure);
  }, 250);
}

async function refreshRoomList() {
  const response = await apiGet('/api/v1/rooms/list.php');
  state.rooms = Array.isArray(response.rooms) ? response.rooms : [];
  const current = state.currentRoom;
  if (!current) {
    renderRoomList();
    if (state.rooms.length > 0) {
      await loadRooms();
    }
    return;
  }

  const fresh = state.rooms.find((room) => room.id === current.id);
  if (!fresh) {
    // The open room was deleted or is no longer visible to this account.
    toast(`#${current.name} is no longer available.`);
    state.currentRoom = null;
    await loadRooms();
    return;
  }
  if (canReadHistory(fresh) !== canReadHistory(current)) {
    await selectRoom(fresh);
    return;
  }
  state.currentRoom = fresh;
  renderRoomList();
  renderRoomHeader();
}

function roomFlag(text, variant = '') {
  const flag = document.createElement('span');
  flag.className = variant ? `room-flag ${variant}` : 'room-flag';
  flag.textContent = text;
  return flag;
}

function roomLock(visibility) {
  const lock = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  lock.setAttribute('class', 'room-lock');
  lock.setAttribute('viewBox', '0 0 24 24');
  lock.setAttribute('role', 'img');
  lock.setAttribute('aria-label', visibility === 'private' ? 'Private' : 'Unlisted');
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

function chooseInitialRoom() {
  return state.rooms.find((room) => room.member_role !== null) ?? state.rooms[0] ?? null;
}

function renderRoomList() {
  elements['room-list'].replaceChildren();

  if (state.rooms.length === 0) {
    const message = document.createElement('p');
    message.className = 'room-meta';
    message.textContent = canCreateRooms(state.user)
      ? 'No rooms yet. Create the first one.'
      : 'No rooms are available yet.';
    elements['room-list'].append(message);
    return;
  }

  for (const room of state.rooms) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'room-button';
    button.classList.toggle('active', state.currentRoom?.id === room.id);
    button.dataset.roomId = String(room.id);

    if (state.currentRoom?.id === room.id) {
      button.setAttribute('aria-current', 'true');
    }

    const name = document.createElement('span');
    name.className = 'room-name';
    const hash = document.createElement('span');
    hash.className = 'room-hash';
    hash.textContent = '#';
    name.append(hash, ` ${room.name}`);
    button.append(name);

    const unread = state.currentRoom?.id === room.id ? 0 : (room.unread_count ?? 0);
    button.classList.toggle('unread', unread > 0);
    if (unread > 0) {
      const count = document.createElement('span');
      count.className = 'unread-count';
      count.textContent = unread >= 100 ? '99+' : String(unread);
      const label = document.createElement('span');
      label.className = 'visually-hidden';
      label.textContent = ' unread';
      count.append(label);
      button.append(count);
    }

    // Only what changes how the room can be used: invitations, age limits, privacy.
    if (room.invited && !room.member_role) {
      button.append(roomFlag('invited', 'invited'));
    }
    if (room.minimum_age > 0) {
      button.append(roomFlag(`${room.minimum_age}+`));
    }
    if (room.visibility !== 'public') {
      button.append(roomLock(room.visibility));
    }

    button.addEventListener('click', () => {
      window.dispatchEvent(new CustomEvent('chitchat:room-chosen'));
      selectRoom(room);
    });
    elements['room-list'].append(button);
  }
}

async function selectRoom(room) {
  state.currentRoom = room;
  // Where this visit's "New messages" divider goes; it stays put while here.
  state.readMarker = Number.isInteger(room.last_read_message_id) && (room.unread_count ?? 0) > 0
    ? room.last_read_message_id
    : null;
  state.newWhileAway = 0;
  elements['jump-latest'].classList.add('hidden');
  state.roomMembers = [];
  state.pings = [];
  delete elements['message-list'].dataset.highlightMessageId;
  state.messages = [];
  state.messageIds = new Set();
  state.oldestMessageId = null;
  clearReplyTo();
  renderRoomList();
  renderRoomHeader();
  renderMessages();
  await presence.enterCurrentRoom(true);

  if (!canReadHistory(room)) {
    showEmptyState('Join this private room to view its history.');
    return;
  }

  await loadMessages({ replace: true, pings: fetchPings(room) });
  if (state.currentRoom?.id !== room.id) return;
  // Open at the first unread message when there is one, else at the end.
  const divider = elements['message-list'].querySelector('.new-messages-divider');
  if (divider) elements['message-list'].scrollTop = Math.max(0, divider.offsetTop - 16);
  updateJumpButton();
  scheduleMarkRead();
  void loadRoomMembers(room);
}

// Pings this account sent or received here are shown as private notices in
// the timeline. They load alongside the messages so the room renders once.
async function fetchPings(room) {
  try {
    const response = await apiGet(`/api/v1/rooms/pings.php?room_id=${encodeURIComponent(room.id)}`);
    return Array.isArray(response.pings) ? response.pings : [];
  } catch {
    // The conversation stays usable without its ping notices.
    return [];
  }
}

function addPing(ping) {
  if (!ping || ping.room_id !== state.currentRoom?.id || state.pings.some((known) => known.id === ping.id)) return;
  state.pings.push(ping);
  renderMessages({ scrollToEnd: true });
}

/** Messages and visible pings in time order. Pings older than the loaded history stay hidden until it loads. */
function timeline() {
  const oldest = state.messages[0] ? Date.parse(state.messages[0].created_at) : null;
  const complete = elements['load-older-button'].classList.contains('hidden');
  const pings = state.pings.filter((ping) => (complete || oldest === null || Date.parse(ping.created_at) >= oldest)
    && !isIgnored(ping.sender?.id));
  return [
    ...state.messages.map((message) => ({ kind: 'message', at: Date.parse(message.created_at), item: message })),
    ...pings.map((ping) => ({ kind: 'ping', at: Date.parse(ping.created_at), item: ping })),
  ].sort((left, right) => (left.at - right.at) || (left.kind === 'message' ? -1 : 1));
}

function buildPingElement(ping) {
  const article = document.createElement('article');
  article.className = 'message ping-notice';
  article.dataset.pingId = String(ping.id);
  article.classList.toggle(
    'search-result-target',
    elements['message-list'].dataset.highlightPingId === article.dataset.pingId,
  );

  const icon = document.createElement('span');
  icon.className = 'message-avatar ping-icon';
  icon.setAttribute('aria-hidden', 'true');
  icon.textContent = '🔔';

  const body = document.createElement('p');
  body.className = 'message-body';
  if (ping.sender.id === state.user?.id) {
    body.append('You pinged ', nameButton(ping.target));
  } else {
    body.append(nameButton(ping.sender), ' pinged you');
  }
  if (ping.message) {
    body.append(': ');
    const text = document.createElement('span');
    text.className = 'ping-text';
    text.textContent = ping.message;
    body.append(text);
  }

  const time = document.createElement('time');
  time.className = 'message-time';
  time.dateTime = ping.created_at;
  time.textContent = formatPageDateTime(ping.created_at);
  const meta = document.createElement('div');
  meta.className = 'message-header';
  const label = document.createElement('span');
  label.className = 'ping-label';
  label.textContent = 'Ping · only you two see this';
  meta.append(label, time);

  article.append(icon, meta, body);
  return article;
}

// Tab completion needs names synchronously, so the room's members are
// fetched once per room and kept alongside recent speakers and who is online.
async function loadRoomMembers(room) {
  try {
    const parameters = new URLSearchParams({ room_id: String(room.id), search: '', limit: '25' });
    const response = await apiGet(`/api/v1/rooms/mentionable-users.php?${parameters.toString()}`);
    if (state.currentRoom?.id === room.id) {
      state.roomMembers = Array.isArray(response.users) ? response.users : [];
    }
  } catch {
    // Completion still works from recent speakers and who is online.
  }
}

/** Names to complete, best first: recent speakers, then who is online, then other members. */
function completionCandidates() {
  const own = state.user?.id;
  const names = [];
  for (let index = state.messages.length - 1; index >= 0; index -= 1) {
    const message = state.messages[index];
    if (message.sender_id !== own && typeof message.username === 'string') names.push(message.username);
  }
  for (const user of presence?.users() ?? []) {
    if (user.id !== own) names.push(user.username);
  }
  for (const user of state.roomMembers) {
    if (user.id !== own) names.push(user.username);
  }
  return names;
}

function renderRoomHeader() {
  const room = state.currentRoom;
  if (!room) {
    renderNoRoom();
    return;
  }

  elements['room-title'].textContent = `# ${room.name}`;
  const details = [];
  if (room.info_line) {
    details.push(room.info_line);
  }
  details.push(room.visibility);
  if (room.minimum_age > 0) {
    details.push(`${room.minimum_age}+`);
  }
  if (room.inactivity_timeout_seconds > 0) {
    details.push(`inactive after ${formatDuration(room.inactivity_timeout_seconds)}`);
  }
  elements['room-info'].textContent = details.join(' · ');

  const isMember = room.member_role !== null;
  elements['join-button'].classList.toggle('hidden', isMember);
  elements['join-button'].textContent = room.invited ? 'Accept invitation' : 'Join room';
  elements['composer-wrap'].classList.toggle('hidden', !isMember);
  elements['composer-input'].disabled = !isMember;
}

function renderNoRoom() {
  elements['room-title'].textContent = 'Choose a room';
  elements['room-info'].textContent = 'Select a room from the sidebar.';
  elements['join-button'].classList.add('hidden');
  elements['composer-wrap'].classList.add('hidden');
  state.messages = [];
  state.messageIds = new Set();
  renderMessages();
  presence?.clear();
  showEmptyState(
    canCreateRooms(state.user)
      ? 'Create a room to begin chatting.'
      : 'No room is currently selected.',
  );
}

async function joinCurrentRoom() {
  if (!state.currentRoom) {
    return;
  }

  elements['join-button'].disabled = true;
  try {
    const response = await apiPost('/api/v1/rooms/join.php', {
      room_id: state.currentRoom.id,
    });
    replaceRoom(response.room);
    await selectRoom(response.room);
    toast(`Joined #${response.room.name}.`);
  } catch (error) {
    handleApiFailure(error);
  } finally {
    elements['join-button'].disabled = false;
  }
}

async function loadMessages({ replace = false, beforeId = null, pings = null } = {}) {
  const room = state.currentRoom;
  if (!room) {
    return;
  }

  elements['load-older-button'].disabled = true;
  try {
    const parameters = new URLSearchParams({
      room_id: String(room.id),
      limit: '100',
    });
    if (beforeId !== null) {
      parameters.set('before_id', String(beforeId));
    }

    const response = await apiGet(`/api/v1/rooms/messages.php?${parameters.toString()}`);
    if (state.currentRoom?.id !== room.id) {
      return;
    }

    const incoming = Array.isArray(response.messages) ? response.messages : [];
    if (pings) {
      const loaded = await pings;
      if (state.currentRoom?.id !== room.id) return;
      state.pings = loaded;
    }
    if (replace) {
      state.messages = [];
      state.messageIds = new Set();
    }

    const newMessages = incoming.filter((message) => !state.messageIds.has(message.id));
    for (const message of newMessages) {
      state.messageIds.add(message.id);
    }

    state.messages = beforeId === null
      ? [...state.messages, ...newMessages]
      : [...newMessages, ...state.messages];
    state.messages.sort((left, right) => left.id - right.id);
    state.oldestMessageId = state.messages[0]?.id ?? null;
    elements['load-older-button'].classList.toggle('hidden', incoming.length < 100);
    renderMessages({ scrollToEnd: replace && beforeId === null, prepended: beforeId !== null });
  } catch (error) {
    if (error instanceof ApiError && ['age_requirement_not_met', 'birth_date_required'].includes(error.code)) {
      showEmptyState(error.message);
      elements['load-older-button'].classList.add('hidden');
      return;
    }
    handleApiFailure(error);
  } finally {
    elements['load-older-button'].disabled = false;
  }
}

async function loadOlderMessages() {
  if (state.oldestMessageId !== null) {
    await loadMessages({ beforeId: state.oldestMessageId });
  }
}

function renderMessages({ scrollToEnd = false, prepended = false } = {}) {
  const list = elements['message-list'];
  // The list scrolls on its own and rebuilding it resets scrollTop, so keep the
  // reader's place: same offset for in-place updates, same messages in view
  // when older history is prepended above them.
  const previousTop = list.scrollTop;
  const previousHeight = list.scrollHeight;
  const focus = focusedListItem(list);
  list.replaceChildren();

  const entries = timeline();
  if (entries.length === 0) {
    showEmptyState(state.currentRoom ? 'No messages yet.' : 'Choose a room to begin.');
    return;
  }

  elements['empty-state'].classList.add('hidden');
  if (!elements['load-older-button'].classList.contains('hidden')) {
    list.append(elements['load-older-button']);
  }

  let dividerPlaced = false;
  for (const entry of entries) {
    if (
      !dividerPlaced
      && state.readMarker !== null
      && entry.kind !== 'ping'
      && entry.item.id > state.readMarker
      && entry.item.sender_id !== state.user?.id
      && !isIgnored(entry.item.sender_id)
    ) {
      list.append(newMessagesDivider());
      dividerPlaced = true;
    }
    list.append(entry.kind === 'ping' ? buildPingElement(entry.item) : buildMessageElement(entry.item));
  }
  restoreFocus(list, focus);

  if (scrollToEnd) {
    list.scrollTop = list.scrollHeight;
  } else if (prepended) {
    list.scrollTop = previousTop + (list.scrollHeight - previousHeight);
  } else {
    list.scrollTop = previousTop;
  }
}

// Rebuilding the list would drop keyboard focus (for example on a name or a
// Reply button) whenever a message arrives; remember it and put it back.
function focusedListItem(list) {
  const active = document.activeElement;
  if (!(active instanceof HTMLElement) || !list.contains(active)) return null;
  const article = active.closest('article');
  if (!article) return null;
  const key = article.dataset.messageId ? `[data-message-id="${article.dataset.messageId}"]`
    : article.dataset.pingId ? `[data-ping-id="${article.dataset.pingId}"]` : null;
  if (!key) return null;
  const focusable = [...article.querySelectorAll('button, a[href], [tabindex]')];
  return { key, index: focusable.indexOf(active), self: active === article };
}

function restoreFocus(list, focus) {
  if (!focus) return;
  const article = list.querySelector(`article${focus.key}`);
  if (!article) return;
  const target = focus.self ? article : [...article.querySelectorAll('button, a[href], [tabindex]')][focus.index];
  target?.focus({ preventScroll: true });
}

function isIgnored(userId) {
  return Number.isInteger(userId) && userId !== state.user?.id && state.ignored.has(userId);
}

// A message from someone this account ignores: one quiet line, which can be
// opened for the moment (to follow a conversation that quotes it, say).
function buildIgnoredElement(message) {
  const article = document.createElement('article');
  article.className = 'message ignored-message';
  article.dataset.messageId = String(message.id);
  const text = document.createElement('span');
  text.textContent = `Message from ${message.username ?? 'someone'}, whom you ignore.`;
  const show = document.createElement('button');
  show.type = 'button';
  show.className = 'link-button';
  show.textContent = 'Show';
  show.setAttribute('aria-label', `Show the message from ${message.username ?? 'someone'}`);
  show.addEventListener('click', () => {
    state.revealed.add(message.id);
    renderMessages();
    elements['message-list'].querySelector(`[data-message-id="${message.id}"]`)?.focus();
  });
  article.append(text, show);
  return article;
}

function buildMessageElement(message) {
  if (isIgnored(message.sender_id) && !state.revealed.has(message.id)) {
    return buildIgnoredElement(message);
  }
  const article = document.createElement('article');
  article.className = 'message';
  article.classList.toggle('emote', message.type === 'emote');
  article.classList.toggle('deleted', Boolean(message.deleted));
  article.dataset.messageId = String(message.id);
  // Re-apply a reply/search highlight, since renderMessages() rebuilds every node.
  article.classList.toggle(
    'search-result-target',
    elements['message-list'].dataset.highlightMessageId === article.dataset.messageId,
  );

  const header = document.createElement('div');
  header.className = 'message-header';

  let author;
  if (Number.isInteger(message.sender_id) && typeof message.username === 'string') {
    author = nameButton({ id: message.sender_id, username: message.username }, 'message-author');
  } else {
    author = document.createElement('span');
    author.className = 'message-author';
    author.textContent = message.username ?? 'System';
  }

  // Decorative initials make speakers easier to scan without duplicating
  // their accessible names or introducing a separate avatar/profile feature.
  const avatar = document.createElement('span');
  avatar.className = 'message-avatar';
  avatar.setAttribute('aria-hidden', 'true');
  avatar.textContent = initials(message.username ?? 'System');
  avatar.dataset.tone = String(avatarTone(message.sender_id ?? message.username));
  if (Number.isInteger(message.sender_id)) {
    avatar.dataset.avatarUser = String(message.sender_id);
    attachPhoto(avatar, message.sender_id);
  }
  article.append(avatar);

  const time = document.createElement('time');
  time.className = 'message-time';
  time.dateTime = message.created_at;
  time.textContent = formatPageDateTime(message.created_at);

  header.append(author, time);

  if (canReplyInCurrentRoom() && !message.deleted) {
    const replyButton = document.createElement('button');
    replyButton.type = 'button';
    replyButton.className = 'message-reply-button';
    replyButton.textContent = 'Reply';
    replyButton.addEventListener('click', () => setReplyTo(message));
    header.append(replyButton);
  }

  const body = document.createElement('div');
  body.className = 'message-body';
  if (message.deleted) {
    body.textContent = 'Message deleted by a moderator.';
  } else if (message.type === 'emote') {
    body.append(document.createTextNode(`* ${message.username ?? 'Someone'} `));
    const action = document.createElement('span');
    renderMessageBody(action, message.body ?? '', message.mentions, { nameButtons: true, inline: true });
    body.append(action);
  } else {
    renderMessageBody(body, message.body ?? '', message.mentions, { nameButtons: true });
  }

  article.append(header);
  const preview = buildReplyPreview(message.reply_to);
  if (preview) {
    preview.addEventListener('click', () => focusReplyTarget(message.reply_to));
    article.append(preview);
  }
  article.append(body);
  if (!message.deleted) {
    article.append(buildReactionBar(message.reactions, state.user?.id, (emoji, reactedByMe) => {
      toggleReaction(message.id, emoji, reactedByMe);
    }));
  }
  return article;
}

async function toggleReaction(messageId, emoji, reactedByMe) {
  try {
    const endpoint = reactedByMe ? '/api/v1/rooms/unreact.php' : '/api/v1/rooms/react.php';
    const response = await apiPost(endpoint, { message_id: messageId, emoji });
    updateMessageReactions(messageId, response.reactions);
  } catch (error) {
    handleApiFailure(error);
  }
}

function updateMessageReactions(messageId, reactions) {
  const message = state.messages.find((candidate) => candidate.id === messageId);
  if (!message) {
    return;
  }
  message.reactions = Array.isArray(reactions) ? reactions : [];
  renderMessages();
}

function canReplyInCurrentRoom() {
  return Boolean(state.currentRoom) && !elements['composer-wrap'].classList.contains('hidden');
}

async function searchRoomMentions(prefix) {
  const room = state.currentRoom;
  if (!room) return [];
  const lower = prefix.toLowerCase();
  const broadcastKeywords = ['room', 'here']
    .filter((keyword) => keyword.startsWith(lower))
    .map((keyword) => ({ id: null, username: keyword }));

  const parameters = new URLSearchParams({ room_id: String(room.id), search: prefix, limit: '8' });
  const response = await apiGet(`/api/v1/rooms/mentionable-users.php?${parameters.toString()}`);
  const users = Array.isArray(response.users) ? response.users : [];

  return [...broadcastKeywords, ...users].slice(0, 8);
}

function setReplyTo(message) {
  state.replyTo = {
    id: message.id,
    username: message.username,
    body: message.body,
    deleted: message.deleted,
  };
  renderReplyBanner();
  elements['composer-input'].focus();
}

function clearReplyTo() {
  if (state.replyTo === null) return;
  state.replyTo = null;
  renderReplyBanner();
}

function renderReplyBanner() {
  const reply = state.replyTo;
  elements['reply-banner'].classList.toggle('hidden', reply === null);
  if (!reply) {
    delete elements['reply-banner'].dataset.replyToId;
    return;
  }
  elements['reply-banner'].dataset.replyToId = String(reply.id);
  const author = reply.username ?? 'Someone';
  const excerpt = reply.deleted ? 'Message deleted.' : truncateForBanner(reply.body ?? '');
  elements['reply-banner-text'].textContent = `Replying to ${author}: “${excerpt}”`;
}

function truncateForBanner(text) {
  const collapsed = text.replace(/\s+/gu, ' ').trim();
  return collapsed.length > 80 ? `${collapsed.slice(0, 79).trimEnd()}…` : collapsed;
}

function focusReplyTarget(replyTo) {
  if (!replyTo?.available) return;
  const list = elements['message-list'];
  const target = list.querySelector(`article[data-message-id="${replyTo.message_id}"]`);
  if (!(target instanceof HTMLElement)) return;
  list.querySelectorAll('.search-result-target').forEach((node) => node.classList.remove('search-result-target'));
  list.dataset.highlightMessageId = target.dataset.messageId;
  target.classList.add('search-result-target');
  target.scrollIntoView({ block: 'center', behavior: 'auto' });
}

function availableCommands() {
  const room = state.currentRoom;
  const manages = Boolean(room) && (room.member_role === 'owner' || canCreateRooms(state.user));
  return COMMANDS.filter((command) => !command.manage || manages);
}

async function submitMessage(event) {
  event.preventDefault();
  const room = state.currentRoom;
  let body = elements['composer-input'].value.trim();
  if (!room || !body) {
    return;
  }

  // /me and /ping go to the server as they are; the others run here.
  const command = parseSlashCommand(body);
  if (command && !['me', 'ping'].includes(command.name)) {
    if (command.name !== 'shrug') {
      await runCommand(command, room);
      return;
    }
    body = command.args === '' ? SHRUG : `${command.args} ${SHRUG}`;
  }

  elements['send-button'].disabled = true;
  try {
    const payload = { room_id: room.id, body };
    if (state.replyTo) payload.reply_to_message_id = state.replyTo.id;
    const response = await apiPost('/api/v1/rooms/send.php', payload);
    elements['composer-input'].value = '';
    clearReplyTo();
    await presence.interact();

    if (response.message) {
      appendMessage(response.message, true);
    } else if (response.ping) {
      addPing(response.ping);
    }
  } catch (error) {
    handleApiFailure(error);
  } finally {
    elements['send-button'].disabled = false;
    elements['composer-input'].focus();
  }
}

async function runCommand(command, room) {
  const input = elements['composer-input'];
  const done = () => {
    input.value = '';
    input.focus();
  };
  if (command.name === 'help') {
    done();
    showCommandHelp(availableCommands());
    return;
  }
  if (command.name === 'topic' && availableCommands().some((known) => known.name === 'topic')) {
    if (command.args === '') {
      toast('Write the new info line after /topic.', 'error');
      return;
    }
    await withButtonBusy(async () => {
      const response = await apiPost('/api/v1/rooms/update.php', {
        room_id: room.id,
        name: room.name,
        info_line: command.args,
        visibility: room.visibility,
        minimum_age: room.minimum_age,
        inactivity_timeout_seconds: room.inactivity_timeout_seconds,
      });
      if (response.room && state.currentRoom?.id === room.id) {
        Object.assign(state.currentRoom, { info_line: response.room.info_line });
        renderRoomHeader();
      }
      toast('The room’s info line is updated.');
      done();
    });
    return;
  }
  if (command.name === 'dm') {
    const target = splitTarget(command.args);
    if (!target) {
      toast('Write a name after /dm, and optionally a message.', 'error');
      return;
    }
    await withButtonBusy(async () => {
      const search = new URLSearchParams({ search: target.name, limit: '10' });
      const { users } = await apiGet(`/api/v1/direct-messages/users.php?${search.toString()}`);
      const user = (users ?? []).find((candidate) => candidate.username.toLowerCase() === target.name.toLowerCase());
      if (!user) {
        toast(`Nobody here is called ${target.name}.`, 'error');
        return;
      }
      if (target.message === '') {
        window.location.assign(`/messages.php?with=${encodeURIComponent(user.id)}`);
        return;
      }
      await apiPost('/api/v1/direct-messages/send.php', { recipient_user_id: user.id, body: target.message });
      toast(`Direct message sent to ${user.username}.`);
      done();
    });
    return;
  }
  toast(`/${command.name} is not a command here. Type /help to see what is.`, 'error');
}

async function withButtonBusy(work) {
  elements['send-button'].disabled = true;
  try {
    await work();
  } catch (error) {
    handleApiFailure(error);
  } finally {
    elements['send-button'].disabled = false;
  }
}

function appendMessage(message, scrollToEnd = false) {
  if (!message || state.messageIds.has(message.id)) {
    return;
  }
  // Someone reading further up keeps their place; a button offers the way back.
  const own = message.sender_id === state.user?.id;
  const follow = scrollToEnd && (own || isNearBottom());
  state.messageIds.add(message.id);
  state.messages.push(message);
  state.messages.sort((left, right) => left.id - right.id);
  state.oldestMessageId = state.messages[0]?.id ?? null;
  renderMessages({ scrollToEnd: follow });
  if (scrollToEnd && !follow) state.newWhileAway += 1;
  updateJumpButton();
  if (follow) scheduleMarkRead();
}

function newMessagesDivider() {
  const divider = document.createElement('div');
  divider.className = 'new-messages-divider';
  divider.setAttribute('role', 'separator');
  divider.setAttribute('aria-label', 'New messages');
  const label = document.createElement('span');
  label.setAttribute('aria-hidden', 'true');
  label.textContent = 'New messages';
  divider.append(label);
  return divider;
}

function isNearBottom() {
  const list = elements['message-list'];
  return list.scrollHeight - list.scrollTop - list.clientHeight < 80;
}

function onMessageListScroll() {
  if (isNearBottom()) {
    state.newWhileAway = 0;
    scheduleMarkRead();
  }
  updateJumpButton();
}

function updateJumpButton() {
  const list = elements['message-list'];
  const away = list.scrollHeight - list.scrollTop - list.clientHeight > 240;
  const button = elements['jump-latest'];
  button.classList.toggle('hidden', !state.currentRoom || !(away || state.newWhileAway > 0) || isNearBottom());
  button.textContent = state.newWhileAway > 0
    ? `${state.newWhileAway} new ${state.newWhileAway === 1 ? 'message' : 'messages'} · Jump to latest`
    : 'Jump to latest';
}

function jumpToLatest() {
  const list = elements['message-list'];
  list.scrollTop = list.scrollHeight;
  state.newWhileAway = 0;
  updateJumpButton();
  scheduleMarkRead();
  elements['composer-input'].focus();
}

// Reading is recorded once the newest message has been on screen, a moment
// after scrolling settles, and only while this tab is actually visible.
let markReadTimer = null;
function scheduleMarkRead() {
  window.clearTimeout(markReadTimer);
  markReadTimer = window.setTimeout(markCurrentRoomRead, 600);
}

async function markCurrentRoomRead() {
  const room = state.currentRoom;
  const latest = state.messages.at(-1)?.id;
  if (!room || !room.member_role || !Number.isInteger(latest)) return;
  if (document.visibilityState !== 'visible' || !isNearBottom()) return;
  if ((room.last_read_message_id ?? 0) >= latest && (room.unread_count ?? 0) === 0) return;
  try {
    const response = await apiPost('/api/v1/rooms/read.php', { room_id: room.id, message_id: latest });
    // The open room can be a separate copy of its room-list entry (after joining, say).
    for (const entry of new Set([room, state.rooms.find((candidate) => candidate.id === room.id)])) {
      if (!entry) continue;
      entry.last_read_message_id = response.last_read_message_id;
      entry.unread_count = 0;
    }
  } catch {
    // Unread counts are a convenience; the next attempt catches up.
  }
}

// A message in a joined room that is not on screen counts towards its badge.
function countUnread(message) {
  if (!message || message.sender_id === state.user?.id || isIgnored(message.sender_id)) return;
  const room = state.rooms.find((candidate) => candidate.id === message.room_id);
  if (!room || !room.member_role) return;
  if (room.id === state.currentRoom?.id) {
    if (document.visibilityState === 'visible' && isNearBottom()) return;
  }
  room.unread_count = Math.min(100, (room.unread_count ?? 0) + 1);
  if (room.id !== state.currentRoom?.id) renderRoomList();
}

function markMessageDeleted(messageId) {
  const message = state.messages.find((candidate) => candidate.id === messageId);
  if (!message) {
    return;
  }
  message.deleted = true;
  message.body = null;
  renderMessages();
}

function startEventStream() {
  stopEventStream();
  updateConnectionStatus('connecting', 'Connecting');

  const source = new EventSource('/api/v1/events/stream.php', { withCredentials: true });
  state.eventSource = source;
  source.addEventListener('open', () => updateConnectionStatus('connected', 'Live'));
  source.addEventListener('error', () => {
    if (state.eventSource === source && state.user) {
      updateConnectionStatus('error', 'Reconnecting');
    }
  });

  source.addEventListener('room_message', (event) => {
    const envelope = parseEvent(event);
    const message = envelope?.payload?.message;
    countUnread(message);
    if (message && message.room_id === state.currentRoom?.id) {
      appendMessage(message, true);
    }
    const own = state.user?.id;
    if (message && message.sender_id !== own && !isIgnored(message.sender_id) && message.mentions?.some((mention) => mention.user_id === own)) {
      alertUser('mention', `${message.username ?? 'Someone'} mentioned you`);
    }
  });

  source.addEventListener('message_deleted', (event) => {
    const envelope = parseEvent(event);
    if (envelope?.payload?.room_id === state.currentRoom?.id) {
      markMessageDeleted(envelope.payload.message_id);
    }
  });

  source.addEventListener('message_reaction_changed', (event) => {
    const envelope = parseEvent(event);
    const payload = envelope?.payload;
    if (payload?.message_kind === 'room' && payload.room_id === state.currentRoom?.id) {
      updateMessageReactions(payload.message_id, payload.reactions);
    }
  });

  source.addEventListener('ping', (event) => {
    const ping = parseEvent(event)?.payload?.ping;
    // Pings from someone this account ignores stay silent and out of view.
    if (!ping || (ping.target?.id === state.user?.id && isIgnored(ping.sender?.id))) {
      return;
    }
    addPing(ping);
    if (ping.target?.id === state.user?.id) {
      alertUser('ping', `Ping from ${ping.sender.username}`);
      window.dispatchEvent(new CustomEvent('chitchat:notifications-changed'));
      // In the open room the notice is in the timeline; elsewhere, say where it came from.
      if (ping.room_id !== state.currentRoom?.id) {
        const room = state.rooms.find((candidate) => candidate.id === ping.room_id);
        const where = room ? ` in #${room.name}` : '';
        toast(`${ping.sender.username} pinged you${where}${ping.message ? `: ${ping.message}` : ''}`);
      }
    }
  });

  source.addEventListener('room_broadcast', (event) => {
    const envelope = parseEvent(event);
    const payload = envelope?.payload;
    if (payload) {
      toast(`Room broadcast: ${payload.message}`);
    }
  });

  source.addEventListener('global_broadcast', (event) => {
    const envelope = parseEvent(event);
    const payload = envelope?.payload;
    if (payload?.lockdown) renderLockdown(payload.lockdown);
    if (payload) {
      toast(`Broadcast: ${payload.message}`);
    }
  });

  source.addEventListener('rooms_changed', scheduleRoomListRefresh);

  // Subscribing also makes realtime-bridge.js re-dispatch direct messages as
  // chitchat:realtime, which refreshes the sidebar's conversations.
  source.addEventListener('direct_message', (event) => {
    const message = parseEvent(event)?.payload?.message;
    if (message && !message.outgoing) {
      alertUser('dm', `Message from ${message.sender?.username ?? 'someone'}`);
    }
  });

  source.addEventListener('presence_changed', (event) => {
    const envelope = parseEvent(event);
    const roomId = envelope?.payload?.room_id;
    if (Number.isInteger(roomId)) {
      presence.handleChanged(roomId).catch(handleApiFailure);
    }
  });

  source.addEventListener('forced_logout', (event) => {
    let reason = 'Your session was invalidated.';
    const envelope = parseEvent(event);
    if (typeof envelope?.payload?.reason === 'string' && envelope.payload.reason) {
      reason = envelope.payload.reason;
    }
    forceSignedOut(reason);
  });
}

function stopEventStream() {
  if (state.eventSource) {
    state.eventSource.close();
    state.eventSource = null;
  }
  updateConnectionStatus('disconnected', 'Offline');
}

function parseEvent(event) {
  try {
    return JSON.parse(event.data);
  } catch {
    return null;
  }
}

function updateConnectionStatus(status, label) {
  if (!elements['connection-status']) {
    return;
  }
  elements['connection-status'].dataset.state = status;
  elements['connection-status'].textContent = label;
}

function openRoomDialog() {
  elements['room-create-form'].reset();
  elements['room-visibility'].value = 'public';
  elements['room-minimum-age'].value = '0';
  elements['room-inactivity-timeout'].value = '0';
  elements['room-dialog-error'].textContent = '';
  elements['room-dialog'].showModal();
  elements['room-key'].focus();
}

async function createRoom(event) {
  event.preventDefault();
  setFormBusy(elements['room-create-form'], true);
  elements['room-dialog-error'].textContent = '';

  try {
    const response = await apiPost('/api/v1/rooms/create.php', {
      key: elements['room-key'].value,
      name: elements['room-name'].value,
      info_line: elements['room-info-line'].value,
      visibility: elements['room-visibility'].value,
      minimum_age: Number.parseInt(elements['room-minimum-age'].value, 10),
      inactivity_timeout_seconds: Number.parseInt(elements['room-inactivity-timeout'].value, 10),
    });
    elements['room-dialog'].close();
    await loadRooms(response.room.id);
    toast(`Created #${response.room.name}.`);
  } catch (error) {
    elements['room-dialog-error'].textContent = errorMessage(error);
  } finally {
    setFormBusy(elements['room-create-form'], false);
  }
}

function replaceRoom(room) {
  const index = state.rooms.findIndex((candidate) => candidate.id === room.id);
  if (index === -1) {
    state.rooms.push(room);
  } else {
    state.rooms[index] = room;
  }
  state.currentRoom = room;
  renderRoomList();
  renderRoomHeader();
}

function canCreateRooms(user) {
  if (!user || !Array.isArray(user.roles)) {
    return false;
  }
  return user.roles.some((role) => ['super_admin', 'admin', 'chat_admin'].includes(role));
}

function canUsePresence(room) {
  if (!room || !state.user) {
    return false;
  }
  if (room.member_role !== null) {
    return true;
  }
  return state.user.roles?.some((role) => [
    'super_admin',
    'admin',
    'chat_admin',
    'global_moderator',
  ].includes(role)) ?? false;
}

function canReadHistory(room) {
  if (room.visibility !== 'private' || room.member_role !== null) {
    return true;
  }
  return state.user?.roles?.some((role) => [
    'super_admin',
    'admin',
    'chat_admin',
    'global_moderator',
  ].includes(role)) ?? false;
}

function handlePresenceExpired(room) {
  if (state.currentRoom?.id !== room.id) {
    return;
  }
  state.currentRoom = null;
  renderRoomList();
  renderNoRoom();
  toast(`You left #${room.name} after being inactive. Select it again to return.`, 'warning');
}

function setFormBusy(form, busy) {
  for (const control of form.querySelectorAll('button, input, select, textarea')) {
    control.disabled = busy;
  }
}

function showAuthError(error) {
  elements['auth-error'].textContent = errorMessage(error);
}

function clearAuthError() {
  elements['auth-error'].textContent = '';
}

function showEmptyState(message) {
  elements['message-list'].replaceChildren();
  elements['empty-state'].textContent = message;
  elements['empty-state'].classList.remove('hidden');
}

function toast(message, type = 'info') {
  const item = document.createElement('div');
  item.className = `toast ${type}`;
  item.setAttribute('role', type === 'error' ? 'alert' : 'status');
  // Slash commands stay on one line; browsers otherwise may break right after "/".
  for (const part of String(message).split(/((?<=^|\s)\/[a-z]+(?=[\s.,;:!?)]|$))/)) {
    if (/^\/[a-z]+$/.test(part)) {
      const command = document.createElement('code');
      command.textContent = part;
      item.append(command);
    } else if (part !== '') {
      item.append(part);
    }
  }
  elements['toast-region'].append(item);
  window.setTimeout(() => item.remove(), 6000);
}


function formatDuration(seconds) {
  if (seconds < 3600) {
    return `${Math.round(seconds / 60)}m`;
  }
  return `${Math.round(seconds / 3600)}h`;
}

function errorMessage(error) {
  return error instanceof Error ? error.message : 'An unexpected error occurred.';
}

function handleApiFailure(error) {
  if (error instanceof ApiError && error.status === 401) {
    forceSignedOut('Your session has ended. Please sign in again.');
    return;
  }
  toast(errorMessage(error), 'error');
}

function forceSignedOut(message) {
  stopEventStream();
  presence?.stop();
  state.user = null;
  state.rooms = [];
  state.currentRoom = null;
  state.messages = [];
  state.messageIds = new Set();
  state.oldestMessageId = null;
  clearReplyTo();
  elements['chat-shell'].classList.add('hidden');
  elements['auth-shell'].classList.remove('hidden');
  showAuthMode('login');
  if (message) {
    toast(message, 'error');
  }
  apiGet('/api/v1/session.php')
    .then((session) => {
      setCsrfToken(session.csrf_token);
      renderLockdown(session.lockdown);
    })
    .catch(() => setCsrfToken(''));
}

// Signing up with Google or Twitch: the provider is confirmed, the person
// still chooses a username, and the password field goes away.
function setProviderSignUp(provider) {
  state.providerSignUp = provider;
  elements['register-provider-note'].classList.toggle('hidden', !provider);
  elements['register-password-field'].classList.toggle('hidden', Boolean(provider));
  elements['register-password'].required = !provider;
  elements['register-provider-text'].textContent = provider
    ? `Signing up with ${provider.label}. Choose the username people will see here. You can add a password later on your Account page.`
    : '';
  const parameters = new URLSearchParams(window.location.search);
  if (parameters.has('sign_up')) {
    parameters.delete('sign_up');
    const query = parameters.toString();
    window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
  }
}

async function cancelProviderSignUp() {
  try {
    await apiPost('/api/v1/oidc/cancel-sign-up.php');
  } catch {
    // The pending sign-up expires on its own anyway.
  }
  setProviderSignUp(null);
  elements['register-password'].focus();
}

// "Continue with Google/Twitch": plain links into the provider's sign-in.
function renderSignInProviders(providers) {
  const container = document.getElementById('sign-in-providers');
  if (!container || !Array.isArray(providers) || providers.length === 0) return;
  container.querySelectorAll('a.provider-button').forEach((link) => link.remove());
  for (const provider of providers) {
    const link = document.createElement('a');
    link.className = 'secondary-button provider-button';
    link.href = `/api/v1/oidc/start.php?provider=${encodeURIComponent(provider.id)}`;
    link.textContent = `Continue with ${provider.label}`;
    container.append(link);
  }
  container.classList.remove('hidden');
}

// A provider sign-in that could not finish comes back with the reason in the address.
function showSignInErrorFromRedirect() {
  const parameters = new URLSearchParams(window.location.search);
  const message = parameters.get('sign_in_error');
  if (!message) return;
  elements['auth-error'].textContent = message;
  parameters.delete('sign_in_error');
  const query = parameters.toString();
  window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
}

// During a maintenance lockdown both the sign-in card and the chat say so.
function renderLockdown(lockdown) {
  const on = Boolean(lockdown?.enabled);
  for (const notice of [document.getElementById('auth-lockdown'), document.getElementById('chat-lockdown')]) {
    if (!notice) continue;
    notice.classList.toggle('hidden', !on);
    const text = notice.querySelector('[data-lockdown-text]');
    if (text) text.textContent = on ? (lockdown.message ?? '') : '';
  }
}

function handleFatalError(error) {
  elements['app-loading'].textContent = errorMessage(error);
  elements['app-loading'].classList.remove('hidden');
  console.error(error);
}

function formatPageDateTime(value) {
  return formatDateTime(value, { dateStyle: 'short', timeStyle: 'short' });
}
