window.addEventListener('DOMContentLoaded', () => {
  const parameters = new URLSearchParams(window.location.search);
  const roomId = positiveInteger(parameters.get('room_id'));
  const userId = positiveInteger(parameters.get('user_id'));
  const messageId = positiveInteger(parameters.get('message_id'));
  const peerName = parameters.get('peer_name')?.trim() ?? '';

  if (roomId !== null && messageId !== null && document.getElementById('room-list')) {
    navigateToResult({
      findContext: () => document.querySelector(`#room-list [data-room-id="${roomId}"]`),
      loadButton: document.getElementById('load-older-button'),
      messageSelector: `.message[data-message-id="${messageId}"]`,
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

async function navigateToResult({ findContext, loadButton, messageSelector }) {
  if (!(loadButton instanceof HTMLButtonElement)) return;

  await waitForHistoryLoad(loadButton);
  const context = await waitForElement(findContext);
  if (!(context instanceof HTMLElement)) return;

  if (!context.classList.contains('active')) {
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
      message.classList.add('search-result-target');
      message.tabIndex = -1;
      message.focus({ preventScroll: true });
      message.scrollIntoView({ block: 'center', behavior: 'auto' });
      return;
    }

    if (loadButton.classList.contains('hidden')) return;
    if (loadButton.disabled) await waitUntilEnabled(loadButton);
    if (loadButton.classList.contains('hidden')) return;
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
