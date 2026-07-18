import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';

const PAGE_SIZE = 25;
const state = {
  beforeId: null,
  loading: false,
  unreadCount: 0,
};

const elements = {
  loading: document.querySelector('#privacy-notifications-loading'),
  shell: document.querySelector('#privacy-notifications-shell'),
  identity: document.querySelector('#privacy-notifications-identity'),
  list: document.querySelector('#privacy-notifications-list'),
  empty: document.querySelector('#privacy-notifications-empty'),
  status: document.querySelector('#privacy-notifications-status'),
  error: document.querySelector('#privacy-notifications-error'),
  markAll: document.querySelector('#privacy-notifications-mark-all'),
  more: document.querySelector('#privacy-notifications-more'),
};

void initialize();

async function initialize() {
  try {
    const session = await apiGet('/api/v1/session.php');
    setCsrfToken(session.csrf_token ?? '');
    if (!session.user) {
      window.location.assign('/');
      return;
    }

    elements.identity.textContent = `Signed in as ${session.user.username}`;
    elements.markAll.addEventListener('click', markAllRead);
    elements.more.addEventListener('click', () => loadNotifications(false));
    elements.loading.classList.add('hidden');
    elements.shell.classList.remove('hidden');
    await loadNotifications(true);
  } catch (error) {
    elements.loading.textContent = error instanceof Error
      ? error.message
      : 'Unable to load privacy notifications.';
  }
}

async function loadNotifications(reset) {
  if (state.loading) {
    return;
  }
  state.loading = true;
  elements.error.textContent = '';
  elements.more.disabled = true;

  if (reset) {
    state.beforeId = null;
    elements.list.replaceChildren();
  }

  try {
    const query = new URLSearchParams({ limit: String(PAGE_SIZE) });
    if (state.beforeId !== null) {
      query.set('before_id', String(state.beforeId));
    }
    const payload = await apiGet(`/api/v1/account/notifications/list.php?${query}`);
    const notifications = Array.isArray(payload.notifications) ? payload.notifications : [];
    state.unreadCount = Number.isInteger(payload.unread_count) ? payload.unread_count : 0;

    for (const notification of notifications) {
      elements.list.append(renderNotification(notification));
    }

    if (notifications.length > 0) {
      const last = notifications.at(-1);
      state.beforeId = Number.isInteger(last?.id) ? last.id : state.beforeId;
    }
    elements.more.classList.toggle('hidden', notifications.length < PAGE_SIZE);
    elements.empty.classList.toggle('hidden', elements.list.children.length !== 0);
    updateUnreadState();
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      window.location.assign('/');
      return;
    }
    elements.error.textContent = error instanceof Error
      ? error.message
      : 'Unable to load privacy notifications.';
  } finally {
    state.loading = false;
    elements.more.disabled = false;
  }
}

function renderNotification(notification) {
  const item = document.createElement('li');
  item.className = 'privacy-notification';
  if (!notification.read) {
    item.classList.add('privacy-notification-unread');
  }
  item.dataset.notificationId = String(notification.id);

  const header = document.createElement('div');
  header.className = 'privacy-notification-header';

  const title = document.createElement('h3');
  title.textContent = typeof notification.title === 'string'
    ? notification.title
    : 'Privacy notification';

  const time = document.createElement('time');
  time.dateTime = typeof notification.created_at === 'string' ? notification.created_at : '';
  time.textContent = formatTimestamp(notification.created_at);
  header.append(title, time);

  const message = document.createElement('p');
  message.textContent = typeof notification.message === 'string'
    ? notification.message
    : 'A privacy- or security-relevant account event occurred.';

  item.append(header, message);

  if (Array.isArray(notification.details) && notification.details.length > 0) {
    const details = document.createElement('ul');
    details.className = 'privacy-notification-details';
    for (const detail of notification.details) {
      if (typeof detail !== 'string') {
        continue;
      }
      const row = document.createElement('li');
      row.textContent = detail;
      details.append(row);
    }
    if (details.children.length > 0) {
      item.append(details);
    }
  }

  if (!notification.read && Number.isInteger(notification.id)) {
    const actions = document.createElement('div');
    actions.className = 'privacy-notification-actions';
    const button = document.createElement('button');
    button.className = 'secondary-button';
    button.type = 'button';
    button.textContent = 'Mark as read';
    button.addEventListener('click', () => markOneRead(notification.id, item, button));
    actions.append(button);
    item.append(actions);
  }

  return item;
}

async function markOneRead(id, item, button) {
  elements.error.textContent = '';
  button.disabled = true;
  try {
    const payload = await apiPost('/api/v1/account/notifications/read.php', { ids: [id] });
    item.classList.remove('privacy-notification-unread');
    button.parentElement?.remove();
    state.unreadCount = Number.isInteger(payload.unread_count)
      ? payload.unread_count
      : Math.max(0, state.unreadCount - 1);
    updateUnreadState();
  } catch (error) {
    button.disabled = false;
    elements.error.textContent = error instanceof Error
      ? error.message
      : 'Unable to mark the notification as read.';
  }
}

async function markAllRead() {
  elements.error.textContent = '';
  elements.markAll.disabled = true;
  try {
    const payload = await apiPost('/api/v1/account/notifications/read.php', { all: true });
    for (const item of elements.list.querySelectorAll('.privacy-notification-unread')) {
      item.classList.remove('privacy-notification-unread');
      item.querySelector('.privacy-notification-actions')?.remove();
    }
    state.unreadCount = Number.isInteger(payload.unread_count) ? payload.unread_count : 0;
    updateUnreadState();
  } catch (error) {
    elements.error.textContent = error instanceof Error
      ? error.message
      : 'Unable to mark the notifications as read.';
    updateUnreadState();
  }
}

function updateUnreadState() {
  elements.markAll.disabled = state.unreadCount === 0;
  elements.status.textContent = state.unreadCount === 0
    ? 'You have no unread privacy notifications.'
    : `${state.unreadCount} unread privacy notification${state.unreadCount === 1 ? '' : 's'}.`;
}

function formatTimestamp(value) {
  if (typeof value !== 'string' || value === '') {
    return 'Unknown time';
  }
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}
