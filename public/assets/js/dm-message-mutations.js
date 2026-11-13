import { ApiError, apiGet, apiPost, isPageLeft } from './api.js';
import { openMessageReportDialog } from './message-report-dialog.js';
import { renderMessageBody } from './message-content.js';
import './realtime-bridge.js';
import { formatDateTime } from './datetime.js';
import { confirmAction } from './confirm-dialog.js';
import { editInline } from './inline-edit.js';
import { errorText, failedSending } from './unavailable.js';

let enhancementQueued = false;
let generation = 0;
let elements = null;
const reportedMessages = new Set();
// A live update to a message being edited waits here until the editor closes (S7).
const heldStates = new WeakMap();
const EDIT_NOT_SAVED = 'Your edit wasn’t saved.';

window.addEventListener('DOMContentLoaded', () => {
  const messageList = document.getElementById('dm-message-list');
  const conversationList = document.getElementById('dm-conversation-list');
  const toastRegion = document.getElementById('toast-region');
  const messagesShell = document.getElementById('messages-shell');
  if (!messageList || !conversationList || !toastRegion || !messagesShell) return;
  elements = { messageList, conversationList, toastRegion, messagesShell };

  new MutationObserver(queueEnhancement).observe(messageList, { childList: true, subtree: true });
  // As for rooms (#270): only a change of the open conversation matters here;
  // re-renders of the same one are covered by the message-list observer.
  let lastPeerId = null;
  new MutationObserver(() => {
    const peerId = conversationList.querySelector('.conversation-button.active')?.dataset.userId ?? null;
    if (peerId === lastPeerId) return;
    lastPeerId = peerId;
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

window.addEventListener('chitchat:message-reported', (event) => {
  if (event.detail?.messageKind !== 'direct') return;
  reportedMessages.add(Number(event.detail.messageId));
  queueEnhancement();
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
  if (article.dataset.editing) {
    heldStates.set(article, state);
    return;
  }
  const canReport = !state.deleted
    && !article.classList.contains('outgoing')
    && !reportedMessages.has(state.id);
  const signature = JSON.stringify([
    state.body,
    state.edited_at,
    state.deleted,
    state.can_edit,
    state.can_delete,
    canReport,
    state.mentions,
  ]);
  if (article.dataset.mutationSignature === signature) return;
  article.dataset.mutationSignature = signature;

  article.querySelector('.message-mutation-actions')?.remove();
  article.querySelector('.message-edited-indicator')?.remove();

  const body = article.querySelector('.dm-message-body');
  const meta = article.querySelector('.dm-message-meta');
  article.classList.toggle('deleted', Boolean(state.deleted));
  if (body) {
    if (state.deleted) {
      body.textContent = 'Message deleted by sender.';
    } else {
      renderMessageBody(body, state.body ?? '', state.mentions);
    }
  }

  if (!state.deleted && state.edited_at && meta) {
    const indicator = document.createElement('span');
    indicator.className = 'message-edited-indicator';
    indicator.textContent = ' · edited';
    indicator.title = `Edited ${formatDateTime(state.edited_at)}`;
    meta.append(indicator);
  }

  if (!state.can_edit && !state.can_delete && !canReport) return;
  const actions = document.createElement('span');
  actions.className = 'message-mutation-actions';
  if (state.can_edit) actions.append(actionButton('Edit', (event) => editMessage(article, state, event.currentTarget)));
  if (state.can_delete) actions.append(actionButton('Delete for everyone', () => deleteMessage(article, state), true));
  if (canReport) actions.append(actionButton('Report', () => openMessageReportDialog('direct', state.id)));
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

async function editMessage(article, state, opener) {
  const body = article.querySelector('.dm-message-body');
  if (!body || article.dataset.editing) return;
  let updated = null;
  const saved = await editInline({
    article,
    body,
    text: state.body ?? '',
    label: 'Edit direct message',
    opener,
    save: async (text) => {
      setBusy(article, true);
      try {
        const response = await apiPost('/api/v1/direct-messages/edit.php', { message_id: state.id, body: text });
        updated = response.message;
      } catch (error) {
        handleFailure(error, EDIT_NOT_SAVED);
        throw error;
      } finally {
        setBusy(article, false);
      }
    },
  });
  // The saved message, or a live update that arrived while the editor was open.
  const latest = (saved ? updated : null) ?? heldStates.get(article) ?? null;
  heldStates.delete(article);
  if (latest) {
    delete article.dataset.mutationSignature;
    applyState(article, latest);
  }
  if (saved) toast('Direct message edited.');
  // The actions are drawn again with the message: focus the new Edit button.
  const again = [...article.querySelectorAll('.message-mutation-button')].find((button) => button.textContent === 'Edit');
  (again ?? article).focus({ preventScroll: true });
}

async function deleteMessage(article, state) {
  const confirmed = await confirmAction({
    title: 'Delete this direct message?',
    text: 'It’s removed for both of you. This can’t be undone.',
    confirmLabel: 'Delete for both',
    announce: null,
  });
  if (!confirmed) return;
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


function toast(message, kind = 'info') {
  if (!elements || !message) return;
  const node = document.createElement('div');
  node.className = `toast ${kind}`;
  node.textContent = message;
  elements.toastRegion.append(node);
  window.setTimeout(() => node.remove(), 5000);
}

function handleFailure(error, then = '') {
  // A request the page aborted on leaving shows nothing (#270).
  if (isPageLeft(error)) return;
  if (error instanceof ApiError && error.status === 401) {
    window.location.assign('/');
    return;
  }
  const reason = errorText(error, 'The direct-message change failed.');
  toast(then ? failedSending(reason, then) : reason, 'error');
}
