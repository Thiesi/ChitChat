import { apiGet, apiUpload } from './api.js';

const elements = {};
let enhancementQueued = false;
let enhancementGeneration = 0;
let lastRoomId = null;

window.addEventListener('DOMContentLoaded', () => {
  for (const id of [
    'composer-form',
    'composer-input',
    'send-button',
    'attachment-input',
    'attachment-name',
    'attachment-clear',
    'message-list',
    'room-list',
    'toast-region',
  ]) {
    const element = document.getElementById(id);
    if (!element) {
      throw new Error(`Missing attachment interface element: ${id}`);
    }
    elements[id] = element;
  }

  elements['attachment-input'].addEventListener('change', updateSelectedFile);
  elements['attachment-clear'].addEventListener('click', clearSelectedFile);
  elements['composer-form'].addEventListener('submit', submitAttachment, true);

  const messageObserver = new MutationObserver(queueEnhancement);
  messageObserver.observe(elements['message-list'], { childList: true, subtree: true });

  const roomObserver = new MutationObserver(() => {
    const roomId = currentRoomId();
    if (roomId !== lastRoomId) {
      lastRoomId = roomId;
      clearSelectedFile();
      queueEnhancement();
    }
  });
  roomObserver.observe(elements['room-list'], {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['class'],
  });

  queueEnhancement();
});

async function submitAttachment(event) {
  const file = elements['attachment-input'].files?.[0] ?? null;
  if (!file) {
    return;
  }

  event.preventDefault();
  event.stopImmediatePropagation();

  const roomId = currentRoomId();
  if (roomId === null) {
    showToast('Choose a room before uploading a file.', 'error');
    return;
  }

  setBusy(true);
  try {
    const formData = new FormData();
    formData.append('room_id', String(roomId));
    formData.append('caption', elements['composer-input'].value.trim());
    formData.append('file', file, file.name);

    await apiUpload('/api/v1/attachments/upload.php', formData);
    elements['composer-input'].value = '';
    clearSelectedFile();
    showToast('Attachment uploaded.');
    window.setTimeout(queueEnhancement, 250);
  } catch (error) {
    showToast(errorMessage(error), 'error');
  } finally {
    setBusy(false);
    elements['composer-input'].focus();
  }
}

function updateSelectedFile() {
  const file = elements['attachment-input'].files?.[0] ?? null;
  elements['attachment-name'].textContent = file
    ? `${file.name} · ${formatBytes(file.size)}`
    : '';
  elements['attachment-clear'].classList.toggle('hidden', !file);
}

function clearSelectedFile() {
  elements['attachment-input'].value = '';
  elements['attachment-name'].textContent = '';
  elements['attachment-clear'].classList.add('hidden');
}

function currentRoomId() {
  const active = elements['room-list']?.querySelector('.room-button.active');
  if (!(active instanceof HTMLElement)) {
    return null;
  }
  const value = Number.parseInt(active.dataset.roomId ?? '', 10);
  return Number.isInteger(value) && value > 0 ? value : null;
}

function queueEnhancement() {
  if (enhancementQueued) {
    return;
  }
  enhancementQueued = true;
  window.setTimeout(() => {
    enhancementQueued = false;
    enhanceVisibleMessages().catch((error) => {
      if (error?.status !== 401 && error?.status !== 403) {
        console.error(error);
      }
    });
  }, 40);
}

async function enhanceVisibleMessages() {
  const roomId = currentRoomId();
  if (roomId === null) {
    return;
  }

  const articles = [...elements['message-list'].querySelectorAll('article.message')]
    .filter((article) => !article.classList.contains('deleted'));
  const messageIds = articles
    .map((article) => Number.parseInt(article.dataset.messageId ?? '', 10))
    .filter((id) => Number.isInteger(id) && id > 0);
  if (messageIds.length === 0) {
    return;
  }

  const generation = ++enhancementGeneration;
  const metadata = new Map();
  for (let offset = 0; offset < messageIds.length; offset += 100) {
    const chunk = messageIds.slice(offset, offset + 100);
    const parameters = new URLSearchParams({
      room_id: String(roomId),
      message_ids: chunk.join(','),
    });
    const response = await apiGet(`/api/v1/attachments/metadata.php?${parameters.toString()}`);
    for (const attachment of Array.isArray(response.attachments) ? response.attachments : []) {
      metadata.set(attachment.message_id, attachment);
    }
  }

  if (generation !== enhancementGeneration || currentRoomId() !== roomId) {
    return;
  }

  for (const article of articles) {
    article.querySelector('.attachment-card')?.remove();
    const messageId = Number.parseInt(article.dataset.messageId ?? '', 10);
    const attachment = metadata.get(messageId);
    if (attachment) {
      article.append(buildAttachmentCard(attachment));
    }
  }
}

function buildAttachmentCard(attachment) {
  const card = document.createElement('section');
  card.className = 'attachment-card';

  if (attachment.previewable) {
    const previewLink = document.createElement('a');
    previewLink.href = `/api/v1/attachments/download.php?id=${attachment.id}&inline=1`;
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
  download.className = 'attachment-download';
  download.href = `/api/v1/attachments/download.php?id=${attachment.id}`;
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
  elements['attachment-input'].disabled = busy;
  elements['attachment-clear'].disabled = busy;
  elements['composer-input'].disabled = busy;
  elements['send-button'].disabled = busy;
}

function formatBytes(bytes) {
  if (!Number.isFinite(bytes) || bytes < 0) {
    return 'unknown size';
  }
  if (bytes < 1024) {
    return `${bytes} B`;
  }
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
