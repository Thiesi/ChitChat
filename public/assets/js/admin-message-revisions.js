import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';

const state = {
  user: null,
  policy: null,
};
const elements = {};

window.addEventListener('DOMContentLoaded', () => {
  bindElements();
  elements['revision-review-form'].addEventListener('submit', submitReview);
  bootstrap().catch(handleFatal);
});

function bindElements() {
  for (const id of [
    'revision-review-loading', 'revision-review-shell', 'revision-review-identity',
    'revision-review-error', 'revision-review-form', 'revision-review-kind',
    'revision-review-message-id', 'revision-review-reason', 'revision-review-submit',
    'revision-review-results', 'revision-review-summary', 'revision-review-context',
    'revision-review-list', 'toast-region',
  ]) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`Missing revision-review interface element: ${id}`);
    elements[id] = element;
  }
}

async function bootstrap() {
  const session = await apiGet('/api/v1/session.php');
  setCsrfToken(session.csrf_token);
  if (!session.user) {
    window.location.assign('/');
    return;
  }

  state.user = session.user;
  state.policy = session.privacy?.message_revisions ?? null;
  if (!state.policy?.admin_review_enabled || !canReview(state.user, state.policy.admin_review_role)) {
    elements['revision-review-loading'].textContent = state.policy?.admin_review_enabled
      ? 'Your account is not permitted to review message revisions.'
      : 'Administrative message revision review is disabled.';
    return;
  }

  elements['revision-review-identity'].textContent = `Signed in as ${state.user.username}`;
  elements['revision-review-loading'].classList.add('hidden');
  elements['revision-review-shell'].classList.remove('hidden');
  elements['revision-review-message-id'].focus();
}

async function submitReview(event) {
  event.preventDefault();
  clearError();

  const kind = elements['revision-review-kind'].value;
  const messageId = Number.parseInt(elements['revision-review-message-id'].value, 10);
  const reason = elements['revision-review-reason'].value.trim();
  if (!['room', 'direct'].includes(kind)) {
    setError('Choose a valid message kind.');
    return;
  }
  if (!Number.isInteger(messageId) || messageId < 1) {
    setError('Enter a positive message ID.');
    return;
  }
  if (reason.length < 10) {
    setError('Enter a meaningful review reason of at least ten characters.');
    return;
  }

  setBusy(true);
  try {
    const response = await apiPost('/api/v1/admin/message-revisions/review.php', {
      kind,
      message_id: messageId,
      reason,
    });
    renderReview(response, reason);
    toast(`Revision review audited for ${response.revisions.length} retained revision${response.revisions.length === 1 ? '' : 's'}.`, 'warning');
  } catch (error) {
    handleApiFailure(error);
  } finally {
    setBusy(false);
  }
}

function renderReview(review, reason) {
  const revisions = Array.isArray(review.revisions) ? review.revisions : [];
  elements['revision-review-summary'].textContent = `${formatKind(review.kind)} ${review.message.id} · ${revisions.length} retained revision${revisions.length === 1 ? '' : 's'} · audited reason: ${reason}`;
  renderContext(review);

  const list = elements['revision-review-list'];
  list.replaceChildren();
  revisions.forEach((revision, index) => list.append(buildRevisionCard(review.kind, revision, index + 1)));
  elements['revision-review-results'].classList.remove('hidden');
  elements['revision-review-results'].scrollIntoView({ block: 'start', behavior: 'smooth' });
}

function renderContext(review) {
  const message = review.message ?? {};
  const entries = [
    ['Message ID', String(message.id ?? '')],
    ['Kind', formatKind(review.kind)],
  ];

  if (review.kind === 'room') {
    entries.push(
      ['Room', message.room ? `# ${message.room.name} · ${message.room.key} · ID ${message.room.id}` : 'Unavailable'],
      ['Author', formatUser(message.author)],
      ['Message type', message.message_type ?? 'Unknown'],
    );
  } else {
    entries.push(
      ['Sender', formatUser(message.sender)],
      ['Recipient', formatUser(message.recipient)],
    );
  }

  entries.push(
    ['Created', formatDateTime(message.created_at)],
    ['Last edited', message.edited_at ? `${formatDateTime(message.edited_at)} by ${formatUser(message.last_editor)}` : 'Never'],
    ['Deleted', message.deleted_at ? `${formatDateTime(message.deleted_at)} by ${formatUser(message.deleted_by)}` : 'No'],
  );

  const context = elements['revision-review-context'];
  context.replaceChildren();
  for (const [label, value] of entries) {
    const term = document.createElement('dt');
    term.textContent = label;
    const description = document.createElement('dd');
    description.textContent = value;
    context.append(term, description);
  }
}

function buildRevisionCard(kind, revision, sequence) {
  const article = document.createElement('article');
  article.className = 'revision-card';

  const header = document.createElement('header');
  header.className = 'revision-card-header';
  const title = document.createElement('h3');
  title.textContent = `Revision ${sequence} · ${revision.action === 'delete' ? 'Deletion' : 'Edit'}`;
  const meta = document.createElement('span');
  const type = kind === 'room' && revision.message_type ? ` · ${revision.message_type}` : '';
  meta.textContent = `Ledger ID ${revision.id} · ${formatDateTime(revision.created_at)} · ${formatUser(revision.actor)}${type}`;
  header.append(title, meta);

  const grid = document.createElement('div');
  grid.className = 'revision-body-grid';
  grid.append(
    bodyPanel('Before', revision.body_before, false),
    bodyPanel('After', revision.body_after, revision.action === 'delete'),
  );
  article.append(header, grid);
  return article;
}

function bodyPanel(label, body, deleted) {
  const section = document.createElement('section');
  section.className = 'revision-body-panel';
  const heading = document.createElement('h4');
  heading.textContent = label;
  const content = document.createElement('pre');
  content.className = 'revision-body';
  if (deleted) {
    content.classList.add('deleted-state');
    content.textContent = 'Message deleted after this revision.';
  } else {
    content.textContent = body ?? '';
  }
  section.append(heading, content);
  return section;
}

function canReview(user, role) {
  const roles = Array.isArray(user?.roles) ? user.roles : [];
  return role === 'super_admin'
    ? roles.includes('super_admin')
    : roles.some((candidate) => ['super_admin', 'admin'].includes(candidate));
}

function formatKind(kind) {
  return kind === 'direct' ? 'Direct message' : 'Room message';
}

function formatUser(user) {
  if (user?.username) return `${user.username}${user.id ? ` · ID ${user.id}` : ''}`;
  if (user?.id) return `Deleted or unavailable user · ID ${user.id}`;
  return 'System or unavailable user';
}

function formatDateTime(value) {
  if (!value) return 'Unavailable';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium', timeStyle: 'short',
  }).format(date);
}

function setBusy(busy) {
  for (const control of elements['revision-review-form'].querySelectorAll('button, input, select, textarea')) {
    control.disabled = busy;
  }
}

function toast(message, kind = 'info') {
  const node = document.createElement('div');
  node.className = `toast ${kind}`;
  node.textContent = message;
  elements['toast-region'].append(node);
  window.setTimeout(() => node.remove(), 6000);
}

function clearError() { elements['revision-review-error'].textContent = ''; }
function setError(message) { elements['revision-review-error'].textContent = message; }
function handleApiFailure(error) {
  if (error instanceof ApiError && error.status === 401) {
    window.location.assign('/');
    return;
  }
  setError(error instanceof Error ? error.message : 'The revision-review request failed.');
}
function handleFatal(error) {
  elements['revision-review-loading'].textContent = error instanceof Error
    ? error.message
    : 'Unable to load revision-review controls.';
}
