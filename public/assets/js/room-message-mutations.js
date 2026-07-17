import { ApiError, apiGet, apiPost } from './api.js';

let enhancementQueued = false;
let generation = 0;
let lastRoomId = null;
let eventSource = null;
let elements = null;

window.addEventListener('DOMContentLoaded', () => {
  const messageList = document.getElementById('message-list');
  const roomList = document.getElementById('room-list');
  const toastRegion = document.getElementById('toast-region');
  const chatShell = document.getElementById('chat-shell');
  if (!messageList || !roomList || !toastRegion || !chatShell) return;
  elements = { messageList, roomList, toastRegion, chatShell };

  new MutationObserver(queueEnhancement).observe(messageList, { childList: true, subtree: true });
  new MutationObserver(() => {
    const roomId = currentRoomId();
    if (roomId !== lastRoomId) {
      lastRoomId = roomId;
      generation += 1;
    }
    queueEnhancement();
  }).observe(roomList, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['class'],
  });
  new MutationObserver(syncEventStream).observe(chatShell, {
    attributes: true,
    attributeFilter: ['class'],
  });

  syncEventStream();
  queueEnhancement();
});

window.addEventListener('beforeunload', closeEventStream);

function currentRoomId() {
  const active = elements?.roomList.querySelector('.room-button.active');
  if (!(active instanceof HTMLElement)) return null;
  const value = Number.parseInt(active.dataset.roomId ?? '', 10);
  return Number.isInteger(value) && value > 0 ? value : null;
}

function queueEnhancement() {
  if (enhancementQueued) return;
  enhancementQueued = true;
  window.setTimeout(() => {
    enhancementQueued = false;
    enhanceVisibleMessages().catch(handleFailure);
  }, 40);
}

async function enhanceVisibleMessages() {
  const roomId = currentRoomId();
  if (roomId === null || !elements || elements.chatShell.classList.contains('hidden')) return;
  const articles = [...elements.messageList.querySelectorAll('article.message')];
  const ids = articles
    .map((article) => Number.parseInt(article.dataset.messageId ?? '', 10))
    .filter((id) => Number.isInteger(id) && id > 0);
  if (ids.length === 0) return;

  const requestGeneration = ++generation;
  const fresh = new Map();
  for (let offset = 0; offset < ids.length; offset += 100) {
    const chunk = ids.slice(offset, offset + 100);
    const parameters = new URLSearchParams({
      room_id: String(roomId),
      message_ids: chunk.join(','),
    });
    const response = await apiGet(`/api/v1/rooms/message-mutations.php?${parameters.toString()}`);
    for (const message of Array.isArray(response.messages) ? response.messages : []) {
      fresh.set(message.id, message);
    }
  }

  if (requestGeneration !== generation || currentRoomId() !== roomId) return;
  for (const article of articles) applyState(article, fresh.get(Number(article.dataset.messageId)) ?? null);
}

function applyState(article, state) {
  if (!state) return;
  const signature = JSON.stringify([
    state.body,
    state.type,
    state.edited_at,
    state.deleted,
    state.deletion_kind,
    state.can_edit,
    state.can_delete,
  ]);
  if (article.dataset.mutationSignature === signature) return;
  article.dataset.mutationSignature = signature;

  article.querySelector('.message-mutation-actions')?.remove();
  article.querySelector('.message-edited-indicator')?.remove();

  const body = article.querySelector('.message-body');
  const header = article.querySelector('.message-header');
  const author = article.querySelector('.message-author')?.textContent ?? 'Someone';
  article.classList.toggle('deleted', Boolean(state.deleted));

  if (body) {
    if (state.deleted) {
      body.textContent = state.deletion_kind === 'author'
        ? 'Message deleted by its author.'
        : 'Message deleted by a moderator.';
    } else if (state.type === 'emote') {
      body.textContent = `* ${author} ${state.body ?? ''}`;
    } else {
      body.textContent = state.body ?? '';
    }
  }

  if (!state.deleted && state.edited_at && header) {
    const indicator = document.createElement('span');
    indicator.className = 'message-edited-indicator';
    indicator.textContent = 'edited';
    indicator.title = `Edited ${formatDateTime(state.edited_at)}`;
    header.append(indicator);
  }

  if (!header || (!state.can_edit && !state.can_delete)) return;
  const actions = document.createElement('span');
  actions.className = 'message-mutation-actions';
  if (state.can_edit) actions.append(actionButton('Edit', () => editMessage(article, state)));
  if (state.can_delete) actions.append(actionButton('Delete', () => deleteMessage(article, state), true));
  header.append(actions);
}

function actionButton(label, handler, danger = false) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = `message-mutation-button${danger ? ' danger' : ''}`;
  button.textContent = label;
  button.addEventListener('click', handler);
  return button;
}

async function editMessage(article, state) {
  const replacement = window.prompt('Edit message', state.body ?? '');
  if (replacement === null || replacement.trim() === state.body) return;
  setBusy(article, true);
  try {
    const response = await apiPost('/api/v1/rooms/edit-message.php', {
      message_id: state.id,
      body: replacement,
    });
    delete article.dataset.mutationSignature;
    applyState(article, response.message);
    toast('Message edited.');
  } catch (error) {
    handleFailure(error);
  } finally {
    setBusy(article, false);
  }
}

async function deleteMessage(article, state) {
  if (!window.confirm('Delete this message for everyone? This cannot be undone.')) return;
  setBusy(article, true);
  try {
    const response = await apiPost('/api/v1/rooms/delete-own-message.php', { message_id: state.id });
    delete article.dataset.mutationSignature;
    applyState(article, response.message);
    toast('Message deleted.');
  } catch (error) {
    handleFailure(error);
  } finally {
    setBusy(article, false);
  }
}

function setBusy(article, busy) {
  for (const button of article.querySelectorAll('.message-mutation-button')) button.disabled = busy;
}

function syncEventStream() {
  if (!elements || elements.chatShell.classList.contains('hidden')) {
    closeEventStream();
    return;
  }
  if (eventSource) return;

  eventSource = new EventSource('/api/v1/events/stream.php', { withCredentials: true });
  eventSource.addEventListener('room_message', (event) => {
    const envelope = parseEvent(event);
    if (envelope?.payload?.message?.room_id === currentRoomId()) queueEnhancement();
  });
  eventSource.addEventListener('message_deleted', (event) => {
    const envelope = parseEvent(event);
    if (envelope?.payload?.room_id === currentRoomId()) queueEnhancement();
  });
}

function closeEventStream() {
  eventSource?.close();
  eventSource = null;
}

function parseEvent(event) {
  try { return JSON.parse(event.data); } catch { return null; }
}

function formatDateTime(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium', timeStyle: 'short',
  }).format(date);
}

function toast(message, kind = 'info') {
  if (!elements) return;
  const node = document.createElement('div');
  node.className = `toast ${kind}`;
  node.textContent = message;
  elements.toastRegion.append(node);
  window.setTimeout(() => node.remove(), 5000);
}

function handleFailure(error) {
  if (error instanceof ApiError && error.status === 401) {
    closeEventStream();
    window.location.assign('/');
    return;
  }
  toast(error instanceof Error ? error.message : 'The message change failed.', 'error');
}
