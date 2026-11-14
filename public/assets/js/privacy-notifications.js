import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';
import { formatDateTime } from './datetime.js';
import { dayKey, dayLabel, directPeerId, kindPresentation } from './activity.js';
import { identityHueIndex } from './identity-hue.js';

const PAGE_SIZE = 25;
const state = {
  beforeId: null,
  loading: false,
  unreadCount: 0,
  // The day group the next item goes into (Load older continues the last day).
  lastDay: null,
  lastGroup: null,
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
      : 'Unable to load notifications.';
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
    state.lastDay = null;
    state.lastGroup = null;
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
      groupFor(notification.created_at).append(renderNotification(notification));
    }

    if (notifications.length > 0) {
      const last = notifications.at(-1);
      state.beforeId = Number.isInteger(last?.id) ? last.id : state.beforeId;
    }
    elements.more.classList.toggle('hidden', notifications.length < PAGE_SIZE);
    elements.empty.classList.toggle('hidden', elements.list.querySelector('.privacy-notification') !== null);
    updateUnreadState();
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      window.location.assign('/');
      return;
    }
    elements.error.textContent = error instanceof Error
      ? error.message
      : 'Unable to load notifications.';
  } finally {
    state.loading = false;
    elements.more.disabled = false;
  }
}

/**
 * Day headings like the transcript (S4c): each day is an item of the feed
 * holding its heading and its own list, so the feed stays one list and Load
 * older carries on in the last day.
 */
function groupFor(createdAt) {
  const key = dayKey(createdAt);
  if (state.lastGroup && key === state.lastDay) return state.lastGroup;
  const day = document.createElement('li');
  day.className = 'activity-day';
  const heading = document.createElement('h3');
  heading.className = 'activity-day-heading';
  heading.id = `activity-day-${elements.list.children.length + 1}`;
  heading.textContent = dayLabel(createdAt, new Date(), (date) => formatDateTime(date, { dateStyle: 'long', timeStyle: null }));
  const list = document.createElement('ol');
  list.className = 'activity-items';
  list.setAttribute('aria-labelledby', heading.id);
  day.append(heading, list);
  elements.list.append(day);
  state.lastDay = key;
  state.lastGroup = list;
  return list;
}

const SVG = 'http://www.w3.org/2000/svg';
const ICON_PATHS = {
  bell: ['M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9', 'M10.3 21a1.94 1.94 0 0 0 3.4 0'],
  shield: ['M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'],
  info: ['M12 16v-4', 'M12 8h.01', 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z'],
  person: ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z'],
};

function strokeIcon(name) {
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '2');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('focusable', 'false');
  for (const d of ICON_PATHS[name]) {
    const path = document.createElementNS(SVG, 'path');
    path.setAttribute('d', d);
    svg.append(path);
  }
  return svg;
}

/** The kind's mark: "@" in honey, a bell, a pebble, a shield in Dusk, a muted lantern, or an info sign. */
function kindIcon(icon, notification) {
  const mark = document.createElement('span');
  mark.className = `activity-icon activity-icon-${icon}`;
  mark.setAttribute('aria-hidden', 'true');
  if (icon === 'mention') {
    mark.textContent = '@';
  } else if (icon === 'lantern') {
    mark.textContent = '#';
  } else if (icon === 'pebble') {
    const hue = identityHueIndex(directPeerId(notification.link));
    if (hue !== null) mark.dataset.hue = String(hue);
    mark.append(strokeIcon('person'));
  } else {
    mark.append(strokeIcon(icon));
  }
  return mark;
}

function renderNotification(notification) {
  const presentation = kindPresentation(notification.kind);
  const titleText = typeof notification.title === 'string' ? notification.title : 'Notification';
  const messageText = typeof notification.message === 'string'
    ? notification.message
    : 'A privacy- or security-relevant account event occurred.';
  const leadsWithMessage = presentation.lead === 'message';

  const item = document.createElement('li');
  item.className = 'privacy-notification activity-item';
  item.dataset.kind = typeof notification.kind === 'string' ? notification.kind : '';
  if (!notification.read) {
    item.classList.add('privacy-notification-unread');
  }
  item.dataset.notificationId = String(notification.id);

  const body = document.createElement('div');
  body.className = 'activity-body';

  // The server's own words: a conversation kind's sentence leads with its
  // generic title as a small label above; other kinds lead with the title.
  if (leadsWithMessage) {
    const kindLabel = document.createElement('p');
    kindLabel.className = 'activity-kind';
    kindLabel.textContent = presentation.type ?? titleText;
    body.append(kindLabel);
  }

  const header = document.createElement('div');
  header.className = 'privacy-notification-header';
  const lead = document.createElement('p');
  lead.className = 'activity-lead';
  lead.id = `activity-lead-${notification.id}`;
  if (!notification.read) {
    const unread = document.createElement('span');
    unread.className = 'visually-hidden activity-unread-label';
    unread.textContent = 'Unread: ';
    lead.append(unread);
  }
  lead.append(leadsWithMessage ? messageText : titleText);
  const time = document.createElement('time');
  time.dateTime = typeof notification.created_at === 'string' ? notification.created_at : '';
  // The day is in the heading; the full date and time stay one hover away.
  time.textContent = formatDateTime(notification.created_at, { dateStyle: null, timeStyle: 'short' });
  time.title = formatTimestamp(notification.created_at);
  header.append(lead, time);
  body.append(header);

  if (!leadsWithMessage) {
    const message = document.createElement('p');
    message.className = 'activity-message';
    message.textContent = messageText;
    body.append(message);
  }

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
      body.append(details);
    }
  }

  const hasUnreadAction = !notification.read && Number.isInteger(notification.id);
  const hasLink = typeof notification.link === 'string' && notification.link !== '' && presentation.action !== null;
  if (hasUnreadAction || hasLink) {
    const actions = document.createElement('div');
    actions.className = 'privacy-notification-actions';
    if (hasLink) {
      const link = document.createElement('a');
      link.className = 'secondary-button';
      link.href = notification.link;
      link.textContent = presentation.action;
      // "View message" alone is ambiguous in a list of them; the lead says which.
      link.setAttribute('aria-describedby', lead.id);
      // Opening an unread item marks it read on the way, as the old bell preview did.
      if (hasUnreadAction) {
        link.addEventListener('click', async (event) => {
          // Ctrl/Cmd, Shift and middle clicks open a new tab as usual.
          if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
          event.preventDefault();
          try {
            await apiPost('/api/v1/account/notifications/read.php', { ids: [notification.id] });
          } catch {
            // The link still opens; the item stays unread until marked.
          }
          window.location.assign(notification.link);
        });
      }
      actions.append(link);
    }
    if (hasUnreadAction) {
      const button = document.createElement('button');
      button.className = 'secondary-button';
      button.type = 'button';
      button.textContent = 'Mark as read';
      button.setAttribute('aria-describedby', lead.id);
      button.addEventListener('click', () => markOneRead(notification.id, item, button));
      actions.append(button);
    }
    body.append(actions);
  }

  item.append(kindIcon(presentation.icon, notification), body);
  return item;
}

/** A read item loses its dot and its "Unread:" label; its link stays. */
function markItemRead(item) {
  item.classList.remove('privacy-notification-unread');
  item.querySelector('.activity-unread-label')?.remove();
  for (const button of item.querySelectorAll('.privacy-notification-actions button')) button.remove();
  const actions = item.querySelector('.privacy-notification-actions');
  if (actions && actions.childElementCount === 0) actions.remove();
}

async function markOneRead(id, item, button) {
  elements.error.textContent = '';
  // Read before disabling: a disabled button loses focus.
  const hadFocus = document.activeElement === button;
  button.disabled = true;
  try {
    const payload = await apiPost('/api/v1/account/notifications/read.php', { ids: [id] });
    markItemRead(item);
    // The button is gone; keep focus in the item, on its link when it has one.
    if (hadFocus) {
      const link = item.querySelector('.privacy-notification-actions a');
      if (link) {
        link.focus();
      } else {
        const lead = item.querySelector('.activity-lead');
        lead.tabIndex = -1;
        lead.focus();
      }
    }
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
      markItemRead(item);
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
    ? 'You have no unread notifications.'
    : `${state.unreadCount} unread notification${state.unreadCount === 1 ? '' : 's'}.`;
  // The shell's Activity badge (shell.js) follows this page's count.
  window.dispatchEvent(new CustomEvent('chitchat:activity-count', { detail: { count: state.unreadCount } }));
}

function formatTimestamp(value) {
  return formatDateTime(value);
}
