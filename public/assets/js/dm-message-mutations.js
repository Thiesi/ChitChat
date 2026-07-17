import { ApiError, apiGet, apiPost } from './api.js';
import './realtime-bridge.js';

let enhancementQueued = false;
let generation = 0;
let elements = null;

window.addEventListener('DOMContentLoaded', () => {
  const messageList = document.getElementById('dm-message-list');
  const conversationList = document.getElementById('dm-conversation-list');
  const toastRegion = document.getElementById('toast-region');
  const messagesShell = document.getElementById('messages-shell');
  if (!messageList || !conversationList || !toastRegion || !messagesShell) return;
  elements = { messageList, conversationList, toastRegion, messagesShell };

  new MutationObserver(queueEnhancement).observe(messageList, { childList: true, subtree: true });
  new MutationObserver(() => {
    generation += 1;
    queueEnhancement();
  }).observe(conversationList, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['class'],
  });

  queueEnhancement();
});

window.addEventListener('chitchat:realtime', (event) => {
  if (event.detail?.type === 'direct_message') queueEnhancement();
});

function queueEnhancement() {
  if (enhancementQueued) return;
  enhancementQueued = true;
  window.setTimeout(() => {
    enhancementQueued = false;
    enhanceVisibleMessages().catch(handleFailure);
  }, 40);
}

async function enhanceVisibleMessages() {
  if (!elements || elements.messagesShell.classList.contains('hidden')) return;
  const articles = [...elements.messageList.querySelectorAll('article.dm-message')];
  const ids = articles
    .map((article) => Number.parseInt(article.dataset.messageId ?? '', 10))
    .filter((id) => Number.isInteger(id) && id > 0);
  if (ids.length === 0) return;

  const requestGeneration = ++generation;
  const fresh = new Map();
  for (let offset = 0; offset < ids.length; offset += 100) {
    const chunk = ids.slice(offset, offset + 100);
    const parameters = new URLSearchParams({ message_ids: chunk.join(',') });
    const response = await apiGet(`/api/v1/direct-messages/message-mutations.php?${parameters.toString()}`);
    for (const message of Array.isArray(response.messages) ? response.messages : []) {
      fresh.set(message.id, message);
    }
  }

  if (requestGeneration !== generation) return;
  for (const article of articles) applyState(article, fresh.get(Number(article.dataset.messageId)) ?? null);
}

function applyState(article, state) {
  if (!state) return;
  const signature = JSON.stringify([
    state.body,
    state.edited_at,
    state.deleted,
    state.can_edit,
    state.can_delete,
  ]);
  if (article.dataset.mutationSignature === signature) return;
  article.dataset.mutationSignature = signature;

  article.querySelector('.message-mutation-actions')?.remove();
  article.querySelector('.message-edited-indicator')?.remove();

  const body = article.querySelector('.dm-message-body');
  const meta = article.querySelector('.dm-message-meta');
  article.classList.toggle('deleted', Boolean(state.deleted));
  if (body) body.textContent = state.deleted ? 'Message deleted by sender.' : (state.body ?? '');

  if (!state.deleted && state.edited_at && meta) {
    const indicator = document.createElement('span');
    indicator.className = 'message-edited-indicator';
    indicator.textContent = ' · edited';
    indicator.title = `Edited ${formatDateTime(state.edited_at)}`;
    meta.append(indicator);
  }

  if (!state.can_edit && !state.can_delete) return;
  const actions = document.createElement('span');
  actions.className = 'message-mutation-actions';
  if (state.can_edit) actions.append(actionButton('Edit', () => editMessage(article, state)));
  if (state.can_delete) actions.append(actionButton('Delete for everyone', () => deleteMessage(article, state), true));
  article.append(actions);
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
  const replacement = window.prompt('Edit direct message', state.body ?? '');
  if (replacement === null || replacement.trim() === state.body) return;
  setBusy(article, true);
  try {
    const response = await apiPost('/api/v1/direct-messages/edit.php', {
      message_id: state.id,
      body: replacement,
    });
    delete article.dataset.mutationSignature;
    applyState(article, response.message);
    toast('Direct message edited.');
  } catch (error) {
    handleFailure(error);
  } finally {
    setBusy(article, false);
  }
}

async function deleteMessage(article, state) {
  if (!window.confirm('Delete this direct message for both participants? This cannot be undone.')) return;
  setBusy(article, true);
  try {
    const response = await apiPost('/api/v1/direct-messages/delete.php', { message_id: state.id });
    delete article.dataset.mutationSignature;
    applyState(article, response.message);
    toast('Direct message deleted.');
  } catch (error) {
    handleFailure(error);
  } finally {
    setBusy(article, false);
  }
}

function setBusy(article, busy) {
  for (const button of article.querySelectorAll('.message-mutation-button')) button.disabled = busy;
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
    window.location.assign('/');
    return;
  }
  toast(error instanceof Error ? error.message : 'The direct-message change failed.', 'error');
}
