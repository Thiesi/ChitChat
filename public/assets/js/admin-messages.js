import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';

const state = {
  user: null,
  privacy: null,
  userA: null,
  userB: null,
  messages: [],
  messageIds: new Set(),
  oldestMessageId: null,
};
const elements = {};

window.addEventListener('DOMContentLoaded', () => {
  bindElements();
  bindEvents();
  bootstrap().catch(handleFatal);
});

function bindElements() {
  for (const id of [
    'inspection-loading', 'inspection-shell', 'inspection-identity', 'inspection-error',
    'inspection-form', 'inspection-user-a-search', 'inspection-user-a-search-button',
    'inspection-user-a-results', 'inspection-user-a-selected', 'inspection-user-b-search',
    'inspection-user-b-search-button', 'inspection-user-b-results', 'inspection-user-b-selected',
    'inspection-reason', 'inspection-submit', 'inspection-results',
    'inspection-conversation-title', 'inspection-conversation-meta',
    'inspection-message-list', 'inspection-load-older', 'toast-region',
  ]) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`Missing inspection interface element: ${id}`);
    elements[id] = element;
  }
}

function bindEvents() {
  elements['inspection-user-a-search-button'].addEventListener('click', () => searchUsers('a'));
  elements['inspection-user-b-search-button'].addEventListener('click', () => searchUsers('b'));
  elements['inspection-form'].addEventListener('submit', inspectConversation);
  elements['inspection-load-older'].addEventListener('click', inspectOlder);
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
  const allowed = state.privacy?.admin_inspection_enabled && canInspect(state.user, state.privacy.admin_inspection_role);
  if (!allowed) {
    elements['inspection-loading'].textContent = state.privacy?.admin_inspection_enabled
      ? 'Your account is not permitted to inspect direct messages.'
      : 'Administrative direct-message inspection is disabled.';
    return;
  }

  elements['inspection-identity'].textContent = `Signed in as ${state.user.username}`;
  elements['inspection-loading'].classList.add('hidden');
  elements['inspection-shell'].classList.remove('hidden');
}

async function searchUsers(slot) {
  clearError();
  const input = elements[`inspection-user-${slot}-search`];
  const search = input.value.trim();
  if (search.length < 2) {
    setError('Enter at least two username characters.');
    return;
  }

  try {
    const parameters = new URLSearchParams({ search, limit: '20' });
    const response = await apiGet(`/api/v1/admin/direct-messages/users.php?${parameters.toString()}`);
    renderUserResults(slot, Array.isArray(response.users) ? response.users : []);
  } catch (error) {
    handleApiFailure(error);
  }
}

function renderUserResults(slot, users) {
  const container = elements[`inspection-user-${slot}-results`];
  container.replaceChildren();
  for (const user of users) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'inspection-user-button';
    button.textContent = user.username;
    button.addEventListener('click', () => selectUser(slot, user));
    container.append(button);
  }
  if (users.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'messages-muted';
    empty.textContent = 'No matching users.';
    container.append(empty);
  }
}

function selectUser(slot, user) {
  if (slot === 'a') state.userA = user;
  else state.userB = user;
  const selected = elements[`inspection-user-${slot}-selected`];
  selected.textContent = `${user.username} · ID ${user.id}`;
  selected.classList.add('has-user');
  elements[`inspection-user-${slot}-results`].replaceChildren();
  elements[`inspection-user-${slot}-search`].value = '';
}

async function inspectConversation(event) {
  event.preventDefault();
  await runInspection({ replace: true, beforeId: null });
}

async function inspectOlder() {
  if (state.oldestMessageId !== null) {
    await runInspection({ replace: false, beforeId: state.oldestMessageId });
  }
}

async function runInspection({ replace, beforeId }) {
  clearError();
  if (!state.userA || !state.userB) {
    setError('Select two users before inspecting a conversation.');
    return;
  }
  if (state.userA.id === state.userB.id) {
    setError('Select two different users.');
    return;
  }
  const reason = elements['inspection-reason'].value.trim();
  if (reason.length < 3) {
    setError('Enter a meaningful inspection reason.');
    return;
  }

  setBusy(true);
  try {
    const response = await apiPost('/api/v1/admin/direct-messages/inspect.php', {
      user_a_id: state.userA.id,
      user_b_id: state.userB.id,
      reason,
      before_id: beforeId,
      limit: 100,
    });
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

    elements['inspection-conversation-title'].textContent = `${response.user_a.username} ↔ ${response.user_b.username}`;
    elements['inspection-conversation-meta'].textContent = `This page was audited with reason: ${reason}`;
    elements['inspection-results'].classList.remove('hidden');
    elements['inspection-load-older'].classList.toggle('hidden', incoming.length < 100);
    renderMessages();
    toast(`Inspection audit record written for ${incoming.length} returned messages.`, 'warning');
  } catch (error) {
    handleApiFailure(error);
  } finally {
    setBusy(false);
  }
}

function renderMessages() {
  const list = elements['inspection-message-list'];
  list.replaceChildren();
  if (!elements['inspection-load-older'].classList.contains('hidden')) {
    list.append(elements['inspection-load-older']);
  }
  if (state.messages.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'messages-muted';
    empty.textContent = 'No messages were found for this conversation page.';
    list.append(empty);
    return;
  }

  for (const message of state.messages) {
    const article = document.createElement('article');
    article.className = 'dm-message';
    article.classList.toggle('outgoing', state.userA && message.sender.id === state.userA.id);

    const body = document.createElement('p');
    body.className = 'dm-message-body';
    body.textContent = message.body;

    const meta = document.createElement('span');
    meta.className = 'dm-message-meta';
    const read = message.read_at ? ' · recipient read' : ' · unread';
    meta.textContent = `${message.sender.username} → ${message.recipient.username} · ${formatDateTime(message.created_at)}${read}`;
    article.append(body, meta);
    list.append(article);
  }
}

function setBusy(busy) {
  for (const control of elements['inspection-form'].querySelectorAll('button, input, textarea')) {
    control.disabled = busy;
  }
  elements['inspection-load-older'].disabled = busy;
}

function canInspect(user, role) {
  const roles = Array.isArray(user.roles) ? user.roles : [];
  return role === 'super_admin'
    ? roles.includes('super_admin')
    : roles.some((candidate) => ['super_admin', 'admin'].includes(candidate));
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
  window.setTimeout(() => node.remove(), 6000);
}

function clearError() { elements['inspection-error'].textContent = ''; }
function setError(message) { elements['inspection-error'].textContent = message; }
function handleApiFailure(error) {
  if (error instanceof ApiError && error.status === 401) {
    window.location.assign('/');
    return;
  }
  setError(error instanceof Error ? error.message : 'The inspection request failed.');
}
function handleFatal(error) {
  elements['inspection-loading'].textContent = error instanceof Error ? error.message : 'Unable to load inspection controls.';
}
