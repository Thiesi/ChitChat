import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';
import { renderMessageBody, buildReplyPreview, buildReactionBar } from './message-content.js';
import { attachMentionAutocomplete } from './mention-autocomplete.js';

const state = {
  user: null,
  privacy: null,
  conversations: [],
  selectedUser: null,
  relationship: null,
  messages: [],
  messageIds: new Set(),
  oldestMessageId: null,
  eventSource: null,
  replyTo: null,
};
const elements = {};
let mentionAutocomplete = null;

window.addEventListener('DOMContentLoaded', () => {
  bindElements();
  bindEvents();
  bootstrap().catch(handleFatal);
});

function bindElements() {
  for (const id of [
    'messages-loading', 'messages-shell', 'messages-identity', 'dm-privacy-text',
    'messages-error', 'dm-user-search-form', 'dm-user-search', 'dm-user-results',
    'dm-conversation-list', 'dm-peer-name', 'dm-peer-status', 'dm-block-toggle',
    'dm-empty-state', 'dm-message-list', 'dm-load-older', 'dm-composer',
    'dm-message-input', 'dm-send', 'toast-region',
    'dm-reply-banner', 'dm-reply-banner-text', 'dm-reply-banner-cancel',
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
  elements['dm-block-toggle'].addEventListener('click', toggleBlock);
  elements['dm-reply-banner-cancel'].addEventListener('click', clearReplyTo);
  elements['dm-message-input'].addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      if (mentionAutocomplete?.isOpen()) return;
      event.preventDefault();
      elements['dm-composer'].requestSubmit();
    }
  });
  mentionAutocomplete = attachMentionAutocomplete(elements['dm-message-input'], searchDirectMessageMentions);
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
  state.relationship = null;
  state.messages = [];
  state.messageIds = new Set();
  state.oldestMessageId = null;
  clearReplyTo();
  elements['dm-peer-name'].textContent = user.username;
  elements['dm-peer-status'].textContent = 'Loading conversation…';
  elements['dm-composer'].classList.add('hidden');
  elements['dm-block-toggle'].classList.add('hidden');
  renderConversations();
  renderMessages();
  await Promise.all([
    loadHistory({ replace: true }),
    loadRelationship(user),
  ]);
  await markRead();
}

async function loadRelationship(user = state.selectedUser) {
  if (!user) return;
  try {
    const parameters = new URLSearchParams({ user_id: String(user.id) });
    const response = await apiGet(`/api/v1/direct-messages/block-status.php?${parameters.toString()}`);
    if (state.selectedUser?.id !== user.id) return;
    state.relationship = response.relationship ?? null;
    renderRelationship();
  } catch (error) {
    handleApiFailure(error);
  }
}

function renderRelationship() {
  const user = state.selectedUser;
  const relationship = state.relationship;
  if (!user || !relationship) {
    elements['dm-block-toggle'].classList.add('hidden');
    elements['dm-composer'].classList.add('hidden');
    return;
  }

  const blockedByMe = Boolean(relationship.blocked_by_me);
  const available = Boolean(relationship.messaging_available);
  const button = elements['dm-block-toggle'];
  button.classList.remove('hidden');
  button.dataset.blocked = blockedByMe ? 'true' : 'false';
  button.textContent = blockedByMe ? 'Unblock user' : 'Block user';
  button.setAttribute('aria-label', `${blockedByMe ? 'Unblock' : 'Block'} ${user.username}`);

  if (available) {
    elements['dm-peer-status'].textContent = 'Direct conversation';
    elements['dm-composer'].classList.remove('hidden');
    elements['dm-message-input'].disabled = false;
    elements['dm-send'].disabled = false;
  } else {
    elements['dm-peer-status'].textContent = blockedByMe
      ? 'You blocked this user. Existing history remains available.'
      : 'Direct messaging is unavailable. Existing history remains available.';
    elements['dm-composer'].classList.add('hidden');
  }
}

async function toggleBlock() {
  const user = state.selectedUser;
  const relationship = state.relationship;
  if (!user || !relationship) return;
  const button = elements['dm-block-toggle'];
  button.disabled = true;
  clearError();
  try {
    const path = relationship.blocked_by_me
      ? '/api/v1/direct-messages/unblock.php'
      : '/api/v1/direct-messages/block.php';
    const response = await apiPost(path, { user_id: user.id });
    if (state.selectedUser?.id !== user.id) return;
    state.relationship = response.relationship ?? null;
    renderRelationship();
    toast(state.relationship?.blocked_by_me ? `${user.username} blocked.` : `${user.username} unblocked.`);
  } catch (error) {
    handleApiFailure(error);
  } finally {
    button.disabled = false;
  }
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

  const preview = buildReplyPreview(message.reply_to);
  if (preview) {
    preview.addEventListener('click', () => focusReplyTarget(message.reply_to));
    article.append(preview);
  }

  const body = document.createElement('p');
  body.className = 'dm-message-body';
  renderMessageBody(body, message.body ?? '', message.mentions);

  const meta = document.createElement('span');
  meta.className = 'dm-message-meta';
  const read = message.outgoing && message.read_at ? ' · read' : '';
  meta.textContent = `${message.sender.username} · ${formatDateTime(message.created_at)}${read}`;

  const actions = document.createElement('span');
  actions.className = 'dm-message-actions';
  const replyButton = document.createElement('button');
  replyButton.type = 'button';
  replyButton.className = 'message-reply-button';
  replyButton.textContent = 'Reply';
  replyButton.addEventListener('click', () => setReplyTo(message));
  actions.append(replyButton);

  article.append(body, meta, actions);
  article.append(buildReactionBar(message.reactions, state.user?.id, (emoji, reactedByMe) => {
    toggleReaction(message.id, emoji, reactedByMe);
  }));
  return article;
}

async function toggleReaction(messageId, emoji, reactedByMe) {
  try {
    const endpoint = reactedByMe ? '/api/v1/direct-messages/unreact.php' : '/api/v1/direct-messages/react.php';
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

function searchDirectMessageMentions(prefix) {
  const peer = state.selectedUser;
  if (!peer || !peer.username.toLowerCase().startsWith(prefix.toLowerCase())) return Promise.resolve([]);
  return Promise.resolve([{ id: peer.id, username: peer.username }]);
}

function setReplyTo(message) {
  state.replyTo = {
    id: message.id,
    username: message.sender.username,
    body: message.body,
    deleted: false,
  };
  renderReplyBanner();
  elements['dm-message-input'].focus();
}

function clearReplyTo() {
  if (state.replyTo === null) return;
  state.replyTo = null;
  renderReplyBanner();
}

function renderReplyBanner() {
  const reply = state.replyTo;
  elements['dm-reply-banner'].classList.toggle('hidden', reply === null);
  if (!reply) {
    delete elements['dm-reply-banner'].dataset.replyToId;
    return;
  }
  elements['dm-reply-banner'].dataset.replyToId = String(reply.id);
  const excerpt = truncateForBanner(reply.body ?? '');
  elements['dm-reply-banner-text'].textContent = `Replying to ${reply.username}: “${excerpt}”`;
}

function truncateForBanner(text) {
  const collapsed = text.replace(/\s+/gu, ' ').trim();
  return collapsed.length > 80 ? `${collapsed.slice(0, 79).trimEnd()}…` : collapsed;
}

function focusReplyTarget(replyTo) {
  if (!replyTo?.available) return;
  const target = elements['dm-message-list'].querySelector(`article[data-message-id="${replyTo.message_id}"]`);
  if (!(target instanceof HTMLElement)) return;
  target.classList.add('search-result-target');
  target.scrollIntoView({ block: 'center', behavior: 'auto' });
}

async function sendMessage(event) {
  event.preventDefault();
  const user = state.selectedUser;
  const body = elements['dm-message-input'].value.trim();
  if (!user || !body || !state.relationship?.messaging_available) return;
  elements['dm-send'].disabled = true;
  try {
    const payload = { recipient_user_id: user.id, body };
    if (state.replyTo) payload.reply_to_message_id = state.replyTo.id;
    const response = await apiPost('/api/v1/direct-messages/send.php', payload);
    elements['dm-message-input'].value = '';
    clearReplyTo();
    appendMessage(response.message, true);
    await loadConversations(user.id);
  } catch (error) {
    if (error instanceof ApiError && error.code === 'direct_message_unavailable') {
      await loadRelationship(user);
    }
    handleApiFailure(error);
  } finally {
    elements['dm-send'].disabled = false;
    if (state.relationship?.messaging_available) elements['dm-message-input'].focus();
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

  source.addEventListener('message_reaction_changed', (event) => {
    const envelope = parseEvent(event);
    const payload = envelope?.payload;
    if (payload?.message_kind === 'direct') {
      updateMessageReactions(payload.message_id, payload.reactions);
    }
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
