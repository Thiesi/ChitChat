import { ApiError, apiGet } from './api.js';
import { MESSAGE_UNAVAILABLE, ROOM_UNAVAILABLE, showNotice } from './unavailable.js';

window.addEventListener('DOMContentLoaded', () => {
  const parameters = new URLSearchParams(window.location.search);
  const roomId = positiveInteger(parameters.get('room_id'));
  const userId = positiveInteger(parameters.get('user_id'));
  const messageId = positiveInteger(parameters.get('message_id'));
  const pingId = positiveInteger(parameters.get('ping_id'));
  const peerName = parameters.get('peer_name')?.trim() ?? '';

  if (roomId !== null && messageId === null && pingId === null && document.getElementById('room-list')) {
    // A bare room link (#334): Activity's "room restored", a shared address. Open the room,
    // or say at once that it's gone.
    openRoomLink(roomId).catch(() => {});
  }

  if (roomId !== null && (messageId !== null || pingId !== null) && document.getElementById('room-list')) {
    navigateToResult({
      resolveContext: () => roomButtonOrNotice(roomId),
      loadButton: document.getElementById('load-older-button'),
      messageSelector: messageId !== null
        ? `.message[data-message-id="${messageId}"]`
        : `.ping-notice[data-ping-id="${pingId}"]`,
    }).catch(() => {});
  }

  if (
    userId !== null
    && messageId !== null
    && peerName !== ''
    && document.getElementById('dm-conversation-list')
  ) {
    navigateToResult({
      findContext: () => [...document.querySelectorAll('#dm-conversation-list .conversation-button')]
        .find((button) => button.querySelector('.conversation-name')?.textContent === peerName) ?? null,
      loadButton: document.getElementById('dm-load-older'),
      messageSelector: `.dm-message[data-message-id="${messageId}"]`,
    }).catch(() => {});
  }
});

const GONE = Symbol('gone');

/**
 * The room's button once the room list has drawn; GONE when it isn't listed and the
 * server says it doesn't exist or can't be seen (#326, #334): one check, no long wait.
 * Null otherwise (signed out, the list never drew, a visible room that isn't listed).
 */
async function findRoom(roomId) {
  const listed = () => document.querySelector(`#room-list [data-room-id="${roomId}"]`);
  const drawn = await waitForElement(() => document.querySelector('#room-list [data-room-id], #room-list .room-list-empty'));
  if (!(drawn instanceof HTMLElement)) return null;
  const button = listed();
  if (button instanceof HTMLElement) return button;
  try {
    await apiGet(`/api/v1/rooms/detail.php?room_id=${encodeURIComponent(roomId)}`);
    // Visible but not in the list: the list may still catch up.
    return await waitForElement(listed, 3_000);
  } catch (error) {
    return error instanceof ApiError && error.code === 'room_not_found' ? GONE : null;
  }
}

/** The room's button, or null after saying it isn't available any more. */
async function roomButtonOrNotice(roomId) {
  const room = await findRoom(roomId);
  if (room === GONE) {
    showNotice(ROOM_UNAVAILABLE);
    return null;
  }
  return room;
}

/**
 * Whether opening this room from a link would join it: for a guest, opening a room they
 * aren't in joins it (app.js selectRoom), and a link must never join without a click.
 * Members only ever get the room's preview with its Join button.
 */
function openingWouldJoin(button) {
  return Boolean(document.querySelector('#chat-shell.guest-mode')) && !button.closest('#room-group-yours');
}

/** Opens the linked room; for a guest's room they aren't in, only shows its row to choose. */
function openFromLink(button) {
  if (openingWouldJoin(button)) {
    button.scrollIntoView({ block: 'nearest' });
    button.focus({ preventScroll: true });
    return false;
  }
  if (!button.classList.contains('active')) button.click();
  return true;
}

async function openRoomLink(roomId) {
  const room = await roomButtonOrNotice(roomId);
  if (room instanceof HTMLElement) openFromLink(room);
}

async function navigateToResult({ findContext = null, resolveContext = null, loadButton, messageSelector }) {
  if (!(loadButton instanceof HTMLButtonElement)) return;

  await waitForHistoryLoad(loadButton);
  const context = resolveContext ? await resolveContext() : await waitForElement(findContext);
  if (!(context instanceof HTMLElement)) return;

  if (resolveContext && !context.classList.contains('active')) {
    // A room link: never joins a guest without a click (see openFromLink).
    if (!openFromLink(context)) return;
    await waitForHistoryLoad(loadButton);
  } else if (!context.classList.contains('active')) {
    context.click();
    await waitForHistoryLoad(loadButton);
  }
  context.scrollIntoView({ block: 'nearest' });

  await revealMessage(messageSelector, loadButton);
}

async function revealMessage(selector, loadButton) {
  for (let page = 0; page < 20; page += 1) {
    const message = document.querySelector(selector);
    if (message instanceof HTMLElement) {
      // The list re-applies this highlight to rebuilt nodes; see buildMessageElement().
      const list = message.closest('.message-list, .dm-message-list');
      if (list instanceof HTMLElement && message.dataset.messageId) list.dataset.highlightMessageId = message.dataset.messageId;
      if (list instanceof HTMLElement && message.dataset.pingId) list.dataset.highlightPingId = message.dataset.pingId;
      message.classList.add('search-result-target');
      message.tabIndex = -1;
      message.focus({ preventScroll: true });
      message.scrollIntoView({ block: 'center', behavior: 'auto' });
      return;
    }

    // All of the history is here and the message isn't: it's gone (#326).
    if (loadButton.classList.contains('hidden')) {
      showNotice(MESSAGE_UNAVAILABLE);
      return;
    }
    if (loadButton.disabled) await waitUntilEnabled(loadButton);
    if (loadButton.classList.contains('hidden')) {
      showNotice(MESSAGE_UNAVAILABLE);
      return;
    }
    loadButton.click();
    await waitForHistoryLoad(loadButton);
  }
}

function waitForHistoryLoad(loadButton, fallbackMilliseconds = 2_000) {
  return new Promise((resolve) => {
    let observedLoading = loadButton.disabled;
    let resolved = false;
    let observer;

    const finish = () => {
      if (resolved) return;
      resolved = true;
      observer?.disconnect();
      window.clearTimeout(timer);
      resolve();
    };
    const check = () => {
      if (loadButton.disabled) observedLoading = true;
      if (observedLoading && !loadButton.disabled) finish();
    };

    observer = new MutationObserver(check);
    observer.observe(loadButton, { attributes: true, attributeFilter: ['disabled', 'class'] });
    const timer = window.setTimeout(finish, fallbackMilliseconds);
    check();
  });
}

function waitUntilEnabled(button) {
  if (!button.disabled) return Promise.resolve();
  return new Promise((resolve) => {
    const observer = new MutationObserver(() => {
      if (!button.disabled) {
        observer.disconnect();
        resolve();
      }
    });
    observer.observe(button, { attributes: true, attributeFilter: ['disabled'] });
    window.setTimeout(() => {
      observer.disconnect();
      resolve();
    }, 5_000);
  });
}

function waitForElement(find, timeoutMilliseconds = 15_000) {
  const existing = find();
  if (existing instanceof HTMLElement) return Promise.resolve(existing);

  return new Promise((resolve) => {
    const observer = new MutationObserver(() => {
      const element = find();
      if (element instanceof HTMLElement) {
        observer.disconnect();
        window.clearTimeout(timer);
        resolve(element);
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
    const timer = window.setTimeout(() => {
      observer.disconnect();
      resolve(null);
    }, timeoutMilliseconds);
  });
}

function positiveInteger(value) {
  if (!/^\d+$/.test(value ?? '')) return null;
  const parsed = Number.parseInt(value, 10);
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null;
}
