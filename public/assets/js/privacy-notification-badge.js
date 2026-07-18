import { ApiError, apiGet } from './api.js';

const REFRESH_INTERVAL_MS = 60_000;
let timer = null;

window.addEventListener('DOMContentLoaded', () => {
  const chatShell = document.querySelector('#chat-shell');
  const badge = document.querySelector('#privacy-notification-badge');
  const link = document.querySelector('#privacy-notifications-link');
  if (!chatShell || !badge || !link) {
    return;
  }

  const synchronize = () => {
    if (chatShell.classList.contains('hidden')) {
      stopRefreshing();
      renderCount(badge, link, 0);
      return;
    }
    void refreshCount(badge, link);
    if (timer === null) {
      timer = window.setInterval(() => refreshCount(badge, link), REFRESH_INTERVAL_MS);
    }
  };

  new MutationObserver(synchronize).observe(chatShell, {
    attributes: true,
    attributeFilter: ['class'],
  });
  synchronize();
});

async function refreshCount(badge, link) {
  try {
    const payload = await apiGet('/api/v1/account/notifications/list.php?limit=1');
    const count = Number.isInteger(payload.unread_count) ? payload.unread_count : 0;
    renderCount(badge, link, count);
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      renderCount(badge, link, 0);
      stopRefreshing();
    }
  }
}

function renderCount(badge, link, count) {
  badge.textContent = count > 99 ? '99+' : String(count);
  badge.classList.toggle('hidden', count === 0);
  link.setAttribute(
    'aria-label',
    count === 0
      ? 'Privacy notifications, none unread'
      : `Privacy notifications, ${count} unread`,
  );
}

function stopRefreshing() {
  if (timer !== null) {
    window.clearInterval(timer);
    timer = null;
  }
}
