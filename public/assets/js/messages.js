import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';

const state = {
  user: null,
  privacy: null,
  conversations: [],
  selectedUser: null,
  messages: [],
  messageIds: new Set(),
  oldestMessageId: null,
  eventSource: null,
};
const elements = {};

window.addEventListener('DOMContentLoaded', () => {
  bindElements();
  bindEvents();
  bootstrap().catch(handleFatal);
});

function bindElements() {
  for (const id of [
    'messages-loading', 'messages-shell', 'messages-identity', 'dm-privacy-text',
    'messages-error', 'dm-user-search-form', 'dm-user-search', 'dm-user-results',
    'dm-conversation-list', 'dm-peer-name', 'dm-peer-status', 'dm-empty-state',
    'dm-message-list', 'dm-load-older', 'dm-composer', 'dm-message-input',
    'dm-send', 'toast-region',
  ]) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`Missing direct-message interface element: ${id}`);
    elements[id] = element;
  }
}

function bindEvents() {
  elements['dm-user-search-form'].addEventListener('submit', searchUsers);
  elements['dm-load-older'].addEventListener('click', loadOlder);
  elements['dm-composer'].addEventListener('submit', sendMessage);
  elements['dm-message-input'].addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      elements['dm-composer'].requestSubmit();
    }
  });
}

async function bootstrap() {
  const session = await apiGet('/api/v1/session.php');
  setCsrfToken(session.csrf_token);
  if (!session.user) {
    window.location.assign('/');
    return;
  }

  state.user = session.user;
  state.privacy = session.privacy?.direct_messages ?? null;
  elements['messages-identity'].textContent = `Signed in as ${state.user.username}`;
  renderPrivacyNotice();
  elements['messages-loading'].classList.add('hidden');
  elements['messages-shell'].classList.remove('hidden');
  startEventStream();
  await loadConversations();
}

function renderPrivacyNotice() {
  const policy = state.privacy;
  if (!policy) {
    elements['dm-privacy-text'].textContent = 'Direct messages are stored by this server and are not end-to-end encrypted.';
    return;
  }
  const inspection = policy.admin_inspection_enabled
    ? `Administrative inspection is enabled for ${formatRole(policy.admin_inspection_role)} and every inspection is audited.`
    : 'Administrative inspection is disabled.';
  elements['dm-privacy-text'].textContent = `Direct messages are not end-to-end encrypted and are retained ${policy.retention}. ${inspection}`;
}

async function loadConversations(preferredUserId = null) {
  try {
    const response = await apiGet('/api/v1/direct-messages/conversations.php');
    state.conversations = Array.isArray(response.conversations) ? response.conversations : [];
    renderConversations();

    const selectedId = preferredUserId ?? state.selectedUser?.id ?? null;
    if (selectedId !== null) {
      const existing = state.conversations.find((item) => item.user.id === selectedId);
      if (existing && (!state.selectedUser || state.selectedUser.id !== selectedId)) {
        await selectUser(existing.user);
      }
    } else if (state.conversations[0]) {
      await selectUser(state.conversations[0].user);
    }
  } catch (error) {
    handleApiFailure(error);
  }
}

function renderConversations() {
  const list = elements['dm-conversation-list'];
  list.replaceChildren();
  if (state.conversations.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'messages-muted';
    empty.textContent = 'No conversations yet.';
    list.append(empty);
    return;
  }

  for (const conversation of state.conversations) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'conversation-button';
    button.classList.toggle('active', state.selectedUser?.id === conversation.user.id);

    const heading = document.createElement('span');
    heading.className = 'conversation-heading';
    const name = document.createElement('span');
    name.className = 'conversation-name';
    name.textContent = conversation.user.username;
    heading.append(name);
    if (conversation.unread_count > 0) {
      const badge = document.createElement('span');
      badge.className = 'unread-badge';
      badge.textContent = String(conversation.unread_count);
      heading.append(badge);
    }

    const preview = document.createElement('span');
    preview.className = 'conversation-preview';
    preview.textContent = `${conversation.last_message.outgoing ? 'You: ' : ''}${conversation.last_message.body}`;
    button.append(heading, preview);
    button.addEventListener('click', () => selectUser(conversation.user));
    list.append(button);
  }
}

async function searchUsers(event) {
  event.preventDefault();
  clearError();
  const search = elements['dm-user-search'].value.trim();
  if (search.length < 2) return;
  try {
    const parameters = new URLSearchParams({ search, limit: '20' });
    const response = await apiGet(`/api/v1/direct-messages/users.php?${parameters.toString()}`);
    renderUserResults(Array.isArray(response.users) ? response.users : []);
  } catch (error) {
    handleApiFailure(error);
  }
}

function renderUserResults(users) {
  const container = elements['dm-user-results'];
  container.replaceChildren();
  for (const user of users) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'dm-user-button';
    button.textContent = user.username;
    button.addEventListener('click', async () => {
      container.replaceChildren();
      elements['dm-user-search'].value = '';
      await selectUser(user);
    });
    container.append(button);
  }
  if (users.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'messages-muted';
    empty.textContent = 'No matching users.';
    container.append(empty);
  }
}

async function selectUser(user) {
  state.selectedUser = user;
  state.messages = [];
  state.messageIds = new Set();
  state.oldestMessageId = null;
  elements['dm-peer-name'].textContent = user.username;
  elements['dm-peer-status'].textContent = 'Direct conversation';
  elements['dm-composer'].classList.remove('hidden');
  renderConversations();
  renderMessages();
  await loadHistory({ replace: true });
  await markRead();
}

async function loadHistory({ replace = false, beforeId = null } = {}) {
  const user = state.selectedUser;
  if (!user) return;
  elements['dm-load-older'].disabled = true;
  try {
    const parameters = new URLSearchParams({ user_id: String(user.id), limit: '100' });
    if (beforeId !== null) parameters.set('before_id', String(beforeId));
    const response = await apiGet(`/api/v1/direct-messages/history.php?${parameters.toString()}`);
    if (state.selectedUser?.id !== user.id) return;
    const incoming = Array.isArray(response.messages) ? response.messages : [];
    if (replace) {
      state.messages = [];
      state.messageIds = new Set();
    }
    const fresh = incoming.filter((message) => !state.messageIds.has(message.id));
    for (const message of fresh) state.messageIds.add(message.id);
    state.messages = beforeId === null ? [...state.messages, ...fresh] : [...fresh, ...state.messages];
    state.messages.sort((left, right) => left.id - right.id);
    state.oldestMessageId = state.messages[0]?.id ?? null;
    elements['dm-load-older'].classList.toggle('hidden', incoming.length < 100);
    renderMessages({ scrollToEnd: replace && beforeId === null });
  } catch (error) {
    handleApiFailure(error);
  } finally {
    elements['dm-load-older'].disabled = false;
  }
}

async function loadOlder() {
  if (state.oldestMessageId !== null) await loadHistory({ beforeId: state.oldestMessageId });
}

function renderMessages({ scrollToEnd = false } = {}) {
  const list = elements['dm-message-list'];
  list.replaceChildren();
  if (state.messages.length === 0) {
    elements['dm-empty-state'].classList.remove('hidden');
    elements['dm-empty-state'].textContent = state.selectedUser ? 'No messages yet.' : 'No conversation selected.';
    return;
  }
  elements['dm-empty-state'].classList.add('hidden');
  if (!elements['dm-load-older'].classList.contains('hidden')) list.append(elements['dm-load-older']);
  for (const message of state.messages) list.append(buildMessage(message));
  if (scrollToEnd) list.scrollTop = list.scrollHeight;
}

function buildMessage(message) {
  const article = document.createElement('article');
  article.className = 'dm-message';
  article.classList.toggle('outgoing', Boolean(message.outgoing));
  article.dataset.messageId = String(message.id);

  const body = document.createElement('p');
  body.className = 'dm-message-body';
  body.textContent = message.body;

  const meta = document.createElement('span');
  meta.className = 'dm-message-meta';
  const read = message.outgoing && message.read_at ? ' · read' : '';
  meta.textContent = `${message.sender.username} · ${formatDateTime(message.created_at)}${read}`;
  article.append(body, meta);
  return article;
}

async function sendMessage(event) {
  event.preventDefault();
  const user = state.selectedUser;
  const body = elements['dm-message-input'].value.trim();
  if (!user || !body) return;
  elements['dm-send'].disabled = true;
  try {
    const response = await apiPost('/api/v1/direct-messages/send.php', {
      recipient_user_id: user.id,
      body,
    });
    elements['dm-message-input'].value = '';
    appendMessage(response.message, true);
    await loadConversations(user.id);
  } catch (error) {
    handleApiFailure(error);
  } finally {
    elements['dm-send'].disabled = false;
    elements['dm-message-input'].focus();
  }
}

function appendMessage(message, scrollToEnd = false) {
  if (!message || state.messageIds.has(message.id)) return;
  state.messageIds.add(message.id);
  state.messages.push(message);
  state.messages.sort((left, right) => left.id - right.id);
  state.oldestMessageId = state.messages[0]?.id ?? null;
  renderMessages({ scrollToEnd });
}

async function markRead() {
  if (!state.selectedUser) return;
  try {
    await apiPost('/api/v1/direct-messages/read.php', { user_id: state.selectedUser.id });
    await refreshConversationsOnly();
  } catch (error) {
    if (!(error instanceof ApiError && error.status === 401)) console.error(error);
  }
}

async function refreshConversationsOnly() {
  const response = await apiGet('/api/v1/direct-messages/conversations.php');
  state.conversations = Array.isArray(response.conversations) ? response.conversations : [];
  renderConversations();
}

function startEventStream() {
  const source = new EventSource('/api/v1/events/stream.php', { withCredentials: true });
  state.eventSource = source;
  source.addEventListener('direct_message', (event) => {
    const envelope = parseEvent(event);
    const message = envelope?.payload?.message;
    if (!message || !state.user) return;
    const peer = message.sender.id === state.user.id ? message.recipient : message.sender;
    if (state.selectedUser?.id === peer.id) {
      appendMessage(message, true);
      if (!message.outgoing) markRead().catch(console.error);
    } else if (!message.outgoing) {
      toast(`New message from ${peer.username}.`);
    }
    refreshConversationsOnly().catch(handleApiFailure);
  });
  source.addEventListener('forced_logout', () => window.location.assign('/'));
}

function parseEvent(event) {
  try { return JSON.parse(event.data); } catch { return null; }
}

function formatRole(role) {
  return role === 'super_admin' ? 'Super-Administrators' : 'Administrators';
}

function formatDateTime(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium', timeStyle: 'short',
  }).format(date);
}

function toast(message, kind = 'info') {
  const node = document.createElement('div');
  node.className = `toast ${kind}`;
  node.textContent = message;
  elements['toast-region'].append(node);
  window.setTimeout(() => node.remove(), 5000);
}

function clearError() { elements['messages-error'].textContent = ''; }
function handleApiFailure(error) {
  if (error instanceof ApiError && error.status === 401) {
    window.location.assign('/');
    return;
  }
  elements['messages-error'].textContent = error instanceof Error ? error.message : 'The request failed.';
}
function handleFatal(error) {
  elements['messages-loading'].textContent = error instanceof Error ? error.message : 'Unable to load direct messages.';
}
