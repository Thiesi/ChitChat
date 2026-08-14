import { ApiError, apiGet, apiUpload } from './api.js';

const elements = {};
let enhancementQueued = false;
let enhancementGeneration = 0;
let cachedPeer = null;

window.addEventListener('DOMContentLoaded', () => {
  for (const id of [
    'dm-composer',
    'dm-message-input',
    'dm-send',
    'dm-attachment-input',
    'dm-attachment-name',
    'dm-attachment-clear',
    'dm-message-list',
    'dm-peer-name',
    'toast-region',
    'dm-reply-banner',
  ]) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`Missing direct-message attachment interface element: ${id}`);
    elements[id] = element;
  }

  elements['dm-attachment-input'].addEventListener('change', updateSelectedFile);
  elements['dm-attachment-clear'].addEventListener('click', clearSelectedFile);
  elements['dm-composer'].addEventListener('submit', submitAttachment, true);

  new MutationObserver(queueEnhancement).observe(elements['dm-message-list'], {
    childList: true,
    subtree: true,
  });
  new MutationObserver(() => {
    cachedPeer = null;
    enhancementGeneration += 1;
    clearSelectedFile();
    queueEnhancement();
  }).observe(elements['dm-peer-name'], { childList: true, subtree: true, characterData: true });

  queueEnhancement();
});

async function submitAttachment(event) {
  const file = elements['dm-attachment-input'].files?.[0] ?? null;
  if (!file) return;

  event.preventDefault();
  event.stopImmediatePropagation();

  const peer = await currentPeer();
  if (!peer) {
    showToast('Choose a direct-message conversation before uploading a file.', 'error');
    return;
  }

  setBusy(true);
  try {
    const formData = new FormData();
    formData.append('recipient_user_id', String(peer.id));
    formData.append('caption', elements['dm-message-input'].value.trim());
    formData.append('file', file, file.name);
    const replyToId = elements['dm-reply-banner'].dataset.replyToId;
    if (replyToId) formData.append('reply_to_message_id', replyToId);

    await apiUpload('/api/v1/direct-messages/attachments/upload.php', formData);
    elements['dm-message-input'].value = '';
    clearSelectedFile();
    clearReplyBanner();
    showToast('Attachment sent.');
    window.setTimeout(queueEnhancement, 250);
  } catch (error) {
    showToast(errorMessage(error), 'error');
    if (error instanceof ApiError && error.code === 'direct_message_unavailable') {
      window.setTimeout(() => window.location.reload(), 500);
    }
  } finally {
    setBusy(false);
    elements['dm-message-input'].focus();
  }
}

async function currentPeer() {
  const username = elements['dm-peer-name'].textContent?.trim() ?? '';
  if (username === '' || username === 'Choose a conversation') return null;
  if (cachedPeer?.username === username) return cachedPeer;

  const parameters = new URLSearchParams({ search: username, limit: '50' });
  const response = await apiGet(`/api/v1/direct-messages/users.php?${parameters.toString()}`);
  const peer = (Array.isArray(response.users) ? response.users : [])
    .find((user) => String(user.username).toLocaleLowerCase() === username.toLocaleLowerCase());
  if (!peer) return null;
  cachedPeer = { id: Number(peer.id), username: String(peer.username) };
  return cachedPeer;
}

function updateSelectedFile() {
  const file = elements['dm-attachment-input'].files?.[0] ?? null;
  elements['dm-attachment-name'].textContent = file
    ? `${file.name} · ${formatBytes(file.size)}`
    : '';
  elements['dm-attachment-clear'].classList.toggle('hidden', !file);
}

function clearSelectedFile() {
  elements['dm-attachment-input'].value = '';
  elements['dm-attachment-name'].textContent = '';
  elements['dm-attachment-clear'].classList.add('hidden');
}

function clearReplyBanner() {
  elements['dm-reply-banner'].classList.add('hidden');
  delete elements['dm-reply-banner'].dataset.replyToId;
}

function queueEnhancement() {
  if (enhancementQueued) return;
  enhancementQueued = true;
  window.setTimeout(() => {
    enhancementQueued = false;
    enhanceVisibleMessages().catch((error) => {
      if (error?.status !== 401 && error?.status !== 403) console.error(error);
    });
  }, 40);
}

async function enhanceVisibleMessages() {
  const articles = [...elements['dm-message-list'].querySelectorAll('article.dm-message')];
  const messageIds = articles
    .map((article) => Number.parseInt(article.dataset.messageId ?? '', 10))
    .filter((id) => Number.isInteger(id) && id > 0);
  if (messageIds.length === 0) return;

  const generation = ++enhancementGeneration;
  const metadata = new Map();
  for (let offset = 0; offset < messageIds.length; offset += 100) {
    const chunk = messageIds.slice(offset, offset + 100);
    const parameters = new URLSearchParams({ message_ids: chunk.join(',') });
    const response = await apiGet(`/api/v1/direct-messages/attachments/metadata.php?${parameters.toString()}`);
    for (const attachment of Array.isArray(response.attachments) ? response.attachments : []) {
      metadata.set(attachment.message_id, attachment);
    }
  }
  if (generation !== enhancementGeneration) return;

  for (const article of articles) {
    const existingCard = article.querySelector('.attachment-card');
    const messageId = Number.parseInt(article.dataset.messageId ?? '', 10);
    const attachment = metadata.get(messageId);
    if (!attachment) {
      existingCard?.remove();
      delete article.dataset.attachmentId;
      continue;
    }

    const attachmentId = String(attachment.id);
    if (existingCard && article.dataset.attachmentId === attachmentId) continue;
    existingCard?.remove();
    article.dataset.attachmentId = attachmentId;
    article.append(buildAttachmentCard(attachment));
  }
}

function buildAttachmentCard(attachment) {
  const card = document.createElement('section');
  card.className = 'attachment-card';

  if (attachment.previewable) {
    const previewLink = document.createElement('a');
    previewLink.href = `/api/v1/direct-messages/attachments/download.php?id=${attachment.id}&inline=1`;
    previewLink.target = '_blank';
    previewLink.rel = 'noopener';

    const preview = document.createElement('img');
    preview.className = 'attachment-preview';
    preview.src = previewLink.href;
    preview.alt = attachment.name;
    preview.loading = 'lazy';
    preview.decoding = 'async';
    previewLink.append(preview);
    card.append(previewLink);
  }

  const details = document.createElement('div');
  details.className = 'attachment-details';

  const download = document.createElement('a');
  download.className = 'attachment-download dm-attachment-download';
  download.href = `/api/v1/direct-messages/attachments/download.php?id=${attachment.id}`;
  download.textContent = attachment.name;

  const metadata = document.createElement('span');
  metadata.className = 'attachment-metadata';
  metadata.textContent = `${attachment.mime_type} · ${formatBytes(attachment.size_bytes)}`;
  metadata.title = `SHA-256: ${attachment.sha256}`;

  details.append(download, metadata);
  card.append(details);
  return card;
}

function setBusy(busy) {
  elements['dm-attachment-input'].disabled = busy;
  elements['dm-attachment-clear'].disabled = busy;
  elements['dm-message-input'].disabled = busy;
  elements['dm-send'].disabled = busy;
}

function formatBytes(bytes) {
  if (!Number.isFinite(bytes) || bytes < 0) return 'unknown size';
  if (bytes < 1024) return `${bytes} B`;
  const units = ['KiB', 'MiB', 'GiB'];
  let value = bytes / 1024;
  let unit = units[0];
  for (let index = 1; index < units.length && value >= 1024; index += 1) {
    value /= 1024;
    unit = units[index];
  }
  return `${value >= 10 ? value.toFixed(0) : value.toFixed(1)} ${unit}`;
}

function showToast(message, kind = 'info') {
  const toast = document.createElement('div');
  toast.className = `toast ${kind}`;
  toast.textContent = message;
  elements['toast-region'].append(toast);
  window.setTimeout(() => toast.remove(), 5000);
}

function errorMessage(error) {
  return error instanceof Error ? error.message : 'The attachment request failed.';
}
