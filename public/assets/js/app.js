import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';
import { createPresenceClient } from './presence.js';
import { renderMessageBody, buildReplyPreview } from './message-content.js';
import { attachMentionAutocomplete } from './mention-autocomplete.js';

const state = {
  user: null,
  rooms: [],
  currentRoom: null,
  messages: [],
  messageIds: new Set(),
  oldestMessageId: null,
  eventSource: null,
  replyTo: null,
};

const elements = {};
let presence = null;
let mentionAutocomplete = null;

window.addEventListener('DOMContentLoaded', () => {
  bindElements();
  bindEvents();
  presence = createPresenceClient({
    getCurrentRoom: () => state.currentRoom,
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
    'login-form',
    'login-username',
    'login-password',
    'register-form',
    'register-username',
    'register-password',
    'register-birth-date',
    'auth-error',
    'chat-shell',
    'connection-status',
    'room-list',
    'current-user',
    'logout-button',
    'new-room-button',
    'room-title',
    'room-info',
    'join-button',
    'empty-state',
    'message-list',
    'load-older-button',
    'composer-wrap',
    'composer-form',
    'composer-input',
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
  elements['login-form'].addEventListener('submit', submitLogin);
  elements['register-form'].addEventListener('submit', submitRegistration);
  elements['logout-button'].addEventListener('click', submitLogout);
  elements['join-button'].addEventListener('click', joinCurrentRoom);
  elements['composer-form'].addEventListener('submit', submitMessage);
  elements['composer-input'].addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      if (mentionAutocomplete?.isOpen()) return;
      event.preventDefault();
      elements['composer-form'].requestSubmit();
    }
  });
  mentionAutocomplete = attachMentionAutocomplete(elements['composer-input'], searchRoomMentions);
  elements['load-older-button'].addEventListener('click', loadOlderMessages);
  elements['reply-banner-cancel'].addEventListener('click', clearReplyTo);
  elements['new-room-button'].addEventListener('click', openRoomDialog);
  elements['room-dialog-cancel'].addEventListener('click', () => elements['room-dialog'].close());
  elements['room-create-form'].addEventListener('submit', createRoom);
}

async function bootstrap() {
  const session = await apiGet('/api/v1/session.php');
  setCsrfToken(session.csrf_token);
  elements['app-loading'].classList.add('hidden');

  if (session.user) {
    await enterApplication(session.user);
  } else {
    showAuthMode('login');
    elements['auth-shell'].classList.remove('hidden');
  }
}

async function enterApplication(user) {
  state.user = user;
  elements['auth-shell'].classList.add('hidden');
  elements['chat-shell'].classList.remove('hidden');
  elements['current-user'].textContent = user.username;
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
    const response = await apiPost('/api/v1/register.php', {
      username: elements['register-username'].value,
      password: elements['register-password'].value,
      birth_date: elements['register-birth-date'].value || null,
    });
    elements['register-form'].reset();
    await enterApplication(response.user);
  } catch (error) {
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

    const name = document.createElement('span');
    name.className = 'room-name';
    name.textContent = `# ${room.name}`;

    const details = document.createElement('span');
    details.className = 'room-meta';
    const markers = [room.visibility];
    if (room.minimum_age > 0) {
      markers.push(`${room.minimum_age}+`);
    }
    if (room.member_role) {
      markers.push(room.member_role);
    } else if (room.invited) {
      markers.push('invited');
    }
    details.textContent = markers.join(' · ');

    button.append(name, details);
    button.addEventListener('click', () => selectRoom(room));
    elements['room-list'].append(button);
  }
}

async function selectRoom(room) {
  state.currentRoom = room;
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

  await loadMessages({ replace: true });
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

async function loadMessages({ replace = false, beforeId = null } = {}) {
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
    renderMessages({ scrollToEnd: replace && beforeId === null });
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

function renderMessages({ scrollToEnd = false } = {}) {
  const list = elements['message-list'];
  list.replaceChildren();

  if (state.messages.length === 0) {
    showEmptyState(state.currentRoom ? 'No messages yet.' : 'Choose a room to begin.');
    return;
  }

  elements['empty-state'].classList.add('hidden');
  if (!elements['load-older-button'].classList.contains('hidden')) {
    list.append(elements['load-older-button']);
  }

  for (const message of state.messages) {
    list.append(buildMessageElement(message));
  }

  if (scrollToEnd) {
    list.scrollTop = list.scrollHeight;
  }
}

function buildMessageElement(message) {
  const article = document.createElement('article');
  article.className = 'message';
  article.classList.toggle('emote', message.type === 'emote');
  article.classList.toggle('deleted', Boolean(message.deleted));
  article.dataset.messageId = String(message.id);

  const header = document.createElement('div');
  header.className = 'message-header';

  const author = document.createElement('span');
  author.className = 'message-author';
  author.textContent = message.username ?? 'System';

  const time = document.createElement('time');
  time.className = 'message-time';
  time.dateTime = message.created_at;
  time.textContent = formatDateTime(message.created_at);

  header.append(author, time);

  if (canReplyInCurrentRoom() && !message.deleted) {
    const replyButton = document.createElement('button');
    replyButton.type = 'button';
    replyButton.className = 'message-reply-button';
    replyButton.textContent = 'Reply';
    replyButton.addEventListener('click', () => setReplyTo(message));
    header.append(replyButton);
  }

  const body = document.createElement('p');
  body.className = 'message-body';
  if (message.deleted) {
    body.textContent = 'Message deleted by a moderator.';
  } else if (message.type === 'emote') {
    body.append(document.createTextNode(`* ${message.username ?? 'Someone'} `));
    const action = document.createElement('span');
    renderMessageBody(action, message.body ?? '', message.mentions);
    body.append(action);
  } else {
    renderMessageBody(body, message.body ?? '', message.mentions);
  }

  article.append(header);
  const preview = buildReplyPreview(message.reply_to);
  if (preview) {
    preview.addEventListener('click', () => focusReplyTarget(message.reply_to));
    article.append(preview);
  }
  article.append(body);
  return article;
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
  const target = elements['message-list'].querySelector(`article[data-message-id="${replyTo.message_id}"]`);
  if (!(target instanceof HTMLElement)) return;
  target.classList.add('search-result-target');
  target.scrollIntoView({ block: 'center', behavior: 'auto' });
}

async function submitMessage(event) {
  event.preventDefault();
  const room = state.currentRoom;
  const body = elements['composer-input'].value.trim();
  if (!room || !body) {
    return;
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
      toast('Ping sent.');
    }
  } catch (error) {
    handleApiFailure(error);
  } finally {
    elements['send-button'].disabled = false;
    elements['composer-input'].focus();
  }
}

function appendMessage(message, scrollToEnd = false) {
  if (!message || state.messageIds.has(message.id)) {
    return;
  }
  state.messageIds.add(message.id);
  state.messages.push(message);
  state.messages.sort((left, right) => left.id - right.id);
  state.oldestMessageId = state.messages[0]?.id ?? null;
  renderMessages({ scrollToEnd });
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
    if (message && message.room_id === state.currentRoom?.id) {
      appendMessage(message, true);
    }
  });

  source.addEventListener('message_deleted', (event) => {
    const envelope = parseEvent(event);
    if (envelope?.payload?.room_id === state.currentRoom?.id) {
      markMessageDeleted(envelope.payload.message_id);
    }
  });

  source.addEventListener('ping', (event) => {
    const envelope = parseEvent(event);
    const payload = envelope?.payload;
    if (!payload) {
      return;
    }
    const sender = payload.sender?.username ?? 'Someone';
    const message = payload.message ? `: ${payload.message}` : '';
    toast(`${sender} pinged you${message}`);
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
    if (payload) {
      toast(`Broadcast: ${payload.message}`);
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
  item.textContent = message;
  elements['toast-region'].append(item);
  window.setTimeout(() => item.remove(), 6000);
}

function formatDateTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(date);
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
    .then((session) => setCsrfToken(session.csrf_token))
    .catch(() => setCsrfToken(''));
}

function handleFatalError(error) {
  elements['app-loading'].textContent = errorMessage(error);
  elements['app-loading'].classList.remove('hidden');
  console.error(error);
}
