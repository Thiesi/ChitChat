import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';

const state = {
  user: null,
  cases: [],
  beforeId: null,
  selectedCase: null,
  loading: false,
};
const elements = {};

window.addEventListener('DOMContentLoaded', () => {
  bindElements();
  bindEvents();
  bootstrap().catch(handleFatal);
});

function bindElements() {
  for (const id of [
    'moderation-loading',
    'moderation-shell',
    'moderation-identity',
    'moderation-error',
    'moderation-filter',
    'moderation-status',
    'moderation-case-list',
    'moderation-more',
    'moderation-detail',
    'moderation-empty',
    'moderation-case',
    'moderation-case-context',
    'moderation-detail-title',
    'moderation-case-meta',
    'moderation-claim',
    'moderation-release',
    'moderation-report-list',
    'moderation-resolution-form',
    'moderation-resolution-code',
    'moderation-resolution-note',
    'moderation-dismiss',
    'moderation-resolve',
    'moderation-closed',
    'moderation-closed-summary',
    'toast-region',
  ]) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`Missing moderation interface element: ${id}`);
    elements[id] = element;
  }
}

function bindEvents() {
  elements['moderation-filter'].addEventListener('change', () => loadCases(true));
  elements['moderation-more'].addEventListener('click', () => loadCases(false));
  elements['moderation-claim'].addEventListener('click', () => updateAssignment(true));
  elements['moderation-release'].addEventListener('click', () => updateAssignment(false));
  elements['moderation-resolution-form'].addEventListener('submit', resolveCase);
  elements['moderation-dismiss'].addEventListener('click', dismissCase);
}

async function bootstrap() {
  const session = await apiGet('/api/v1/session.php');
  setCsrfToken(session.csrf_token);
  if (!session.user) {
    window.location.replace('/');
    return;
  }

  state.user = session.user;
  elements['moderation-identity'].textContent = `Signed in as ${state.user.username}`;
  elements['moderation-loading'].classList.add('hidden');
  elements['moderation-shell'].classList.remove('hidden');
  await loadCases(true);
}

async function loadCases(reset) {
  if (state.loading) return;
  state.loading = true;
  clearError();
  elements['moderation-more'].disabled = true;
  elements['moderation-status'].textContent = reset ? 'Loading cases…' : 'Loading older cases…';
  try {
    const parameters = new URLSearchParams({
      status: elements['moderation-filter'].value,
      limit: '50',
    });
    if (!reset && state.beforeId !== null) parameters.set('before_id', String(state.beforeId));
    const response = await apiGet(`/api/v1/moderation/cases.php?${parameters.toString()}`);
    const incoming = Array.isArray(response.cases) ? response.cases : [];
    state.cases = reset ? incoming : [...state.cases, ...incoming];
    state.beforeId = Number.isInteger(response.next_before_id) ? response.next_before_id : null;
    renderCases();
    elements['moderation-more'].classList.toggle('hidden', !response.has_more);
    elements['moderation-status'].textContent = state.cases.length === 0
      ? 'No cases match this filter.'
      : `${state.cases.length} ${state.cases.length === 1 ? 'case' : 'cases'} shown.`;

    if (reset) {
      const selectedId = state.selectedCase?.id ?? null;
      const selected = selectedId === null ? null : state.cases.find((item) => item.id === selectedId);
      if (selected) {
        await selectCase(selected.id);
      } else if (state.cases[0]) {
        await selectCase(state.cases[0].id);
      } else {
        clearSelection();
      }
    }
  } catch (error) {
    handleFailure(error);
  } finally {
    state.loading = false;
    elements['moderation-more'].disabled = false;
  }
}

function renderCases() {
  const list = elements['moderation-case-list'];
  list.replaceChildren();
  for (const item of state.cases) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'moderation-case-button';
    button.classList.toggle('active', state.selectedCase?.id === item.id);
    button.setAttribute('aria-pressed', String(state.selectedCase?.id === item.id));

    const title = document.createElement('span');
    title.className = 'moderation-case-title';
    title.textContent = caseTitle(item);

    const meta = document.createElement('span');
    meta.className = 'moderation-case-meta';
    meta.textContent = `${formatStatus(item.status)} · ${item.report_count} ${item.report_count === 1 ? 'report' : 'reports'} · ${formatDateTime(item.last_reported_at)}`;

    const assignment = document.createElement('span');
    assignment.className = 'moderation-case-assignment';
    assignment.textContent = item.assigned_to
      ? `Assigned to ${item.assigned_to.username}`
      : 'Unassigned';

    button.append(title, meta, assignment);
    button.addEventListener('click', () => selectCase(item.id));
    list.append(button);
  }
}

async function selectCase(caseId) {
  clearError();
  try {
    const response = await apiGet(`/api/v1/moderation/case.php?case_id=${encodeURIComponent(caseId)}`);
    state.selectedCase = response.case ?? null;
    renderCases();
    renderDetail();
  } catch (error) {
    handleFailure(error);
  }
}

function clearSelection() {
  state.selectedCase = null;
  elements['moderation-empty'].classList.remove('hidden');
  elements['moderation-case'].classList.add('hidden');
  renderCases();
}

function renderDetail() {
  const item = state.selectedCase;
  if (!item) {
    clearSelection();
    return;
  }

  elements['moderation-empty'].classList.add('hidden');
  elements['moderation-case'].classList.remove('hidden');
  elements['moderation-case-context'].textContent = item.message_kind === 'room'
    ? `Room message · # ${item.room?.name ?? 'Deleted room'}`
    : 'Participant-submitted direct message';
  elements['moderation-detail-title'].textContent = `Case #${item.id}: ${item.subject.username}`;
  elements['moderation-case-meta'].textContent = `${formatStatus(item.status)} · message #${item.message_id} · first reported ${formatDateTime(item.first_reported_at)}`;

  const closed = ['resolved', 'dismissed'].includes(item.status);
  const assignedToMe = item.assigned_to?.id === state.user?.id;
  elements['moderation-claim'].classList.toggle('hidden', closed || Boolean(item.assigned_to));
  elements['moderation-release'].classList.toggle('hidden', closed || !assignedToMe);
  elements['moderation-resolution-form'].classList.toggle('hidden', closed);
  elements['moderation-closed'].classList.toggle('hidden', !closed);
  elements['moderation-resolution-note'].value = '';
  elements['moderation-resolution-code'].value = 'content_removed';

  if (closed) {
    const resolver = item.resolved_by?.username ? ` by ${item.resolved_by.username}` : '';
    const note = item.resolution_note ? ` Note: ${item.resolution_note}` : '';
    elements['moderation-closed-summary'].textContent = `${formatStatus(item.status)}${resolver} as ${formatResolution(item.resolution_code)}.${note}`;
  }

  renderReports(item.reports ?? []);
}

function renderReports(reports) {
  const list = elements['moderation-report-list'];
  list.replaceChildren();
  for (const report of reports) {
    const article = document.createElement('article');
    article.className = 'moderation-report-card';

    const header = document.createElement('header');
    const title = document.createElement('strong');
    title.textContent = `${formatCategory(report.category)} · reported by ${report.reporter.username}`;
    const time = document.createElement('time');
    time.dateTime = report.created_at;
    time.textContent = formatDateTime(report.created_at);
    header.append(title, time);

    const evidence = document.createElement('blockquote');
    evidence.className = 'moderation-evidence';
    evidence.textContent = report.evidence_body || '[Attachment without a text caption]';

    const evidenceMeta = document.createElement('p');
    evidenceMeta.className = 'moderation-evidence-meta';
    evidenceMeta.textContent = evidenceDescription(report.evidence ?? {});

    article.append(header, evidence, evidenceMeta);
    if (report.details) {
      const details = document.createElement('p');
      details.textContent = `Reporter details: ${report.details}`;
      article.append(details);
    }
    list.append(article);
  }
}

async function updateAssignment(claim) {
  const item = requireCase();
  const button = claim ? elements['moderation-claim'] : elements['moderation-release'];
  await withButton(button, async () => {
    const response = await apiPost('/api/v1/moderation/claim.php', {
      case_id: item.id,
      claim,
    });
    state.selectedCase = response.case;
    replaceSummary(response.case);
    renderDetail();
    toast(claim ? 'Case claimed.' : 'Case released.');
  });
}

async function resolveCase(event) {
  event.preventDefault();
  const item = requireCase();
  await withButton(elements['moderation-resolve'], async () => {
    const response = await apiPost('/api/v1/moderation/resolve.php', {
      case_id: item.id,
      status: 'resolved',
      resolution_code: elements['moderation-resolution-code'].value,
      resolution_note: elements['moderation-resolution-note'].value || null,
    });
    state.selectedCase = response.case;
    replaceSummary(response.case);
    renderDetail();
    toast('Case resolved.');
  });
}

async function dismissCase() {
  const item = requireCase();
  if (!window.confirm('Dismiss this case as no violation?')) return;
  await withButton(elements['moderation-dismiss'], async () => {
    const response = await apiPost('/api/v1/moderation/resolve.php', {
      case_id: item.id,
      status: 'dismissed',
      resolution_code: 'no_violation',
      resolution_note: elements['moderation-resolution-note'].value || null,
    });
    state.selectedCase = response.case;
    replaceSummary(response.case);
    renderDetail();
    toast('Case dismissed.');
  });
}

function replaceSummary(detail) {
  const index = state.cases.findIndex((item) => item.id === detail.id);
  if (index >= 0) state.cases[index] = { ...state.cases[index], ...detail };
  renderCases();
}

function requireCase() {
  if (!state.selectedCase) throw new Error('No moderation case is selected.');
  return state.selectedCase;
}

async function withButton(button, callback) {
  button.disabled = true;
  clearError();
  try {
    await callback();
  } catch (error) {
    handleFailure(error);
  } finally {
    button.disabled = false;
  }
}

function caseTitle(item) {
  const location = item.message_kind === 'room'
    ? `# ${item.room?.name ?? 'Deleted room'}`
    : 'Direct message';
  return `${location} · ${item.subject.username}`;
}

function evidenceDescription(evidence) {
  const parts = [];
  if (evidence.created_at) parts.push(`message sent ${formatDateTime(evidence.created_at)}`);
  if (evidence.edited_at) parts.push(`snapshot after edit ${formatDateTime(evidence.edited_at)}`);
  if (evidence.message_type) parts.push(`type ${String(evidence.message_type).replaceAll('_', ' ')}`);
  if (evidence.attachment) {
    const attachment = evidence.attachment;
    parts.push(`attachment ${attachment.name} · ${attachment.mime_type} · ${formatBytes(attachment.size_bytes)}`);
  }
  return parts.join(' · ') || 'Exact message snapshot submitted by the participant.';
}

function formatStatus(value) {
  return String(value ?? '').replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase());
}

function formatCategory(value) {
  const labels = {
    spam: 'Spam',
    harassment: 'Harassment',
    hate: 'Hate speech',
    threats: 'Threats or violence',
    sexual_content: 'Sexual content',
    privacy: 'Privacy violation',
    impersonation: 'Impersonation',
    other: 'Other',
  };
  return labels[value] ?? formatStatus(value);
}

function formatResolution(value) {
  const labels = {
    no_violation: 'no violation',
    content_removed: 'content removed',
    user_warned: 'user warned',
    account_restricted: 'account restricted',
    other: 'other action',
  };
  return labels[value] ?? String(value ?? 'unknown outcome');
}

function formatDateTime(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value ?? '') : new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}

function formatBytes(value) {
  const bytes = Number(value);
  if (!Number.isFinite(bytes) || bytes < 0) return 'unknown size';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KiB`;
  return `${(bytes / 1024 ** 2).toFixed(1)} MiB`;
}

function clearError() {
  elements['moderation-error'].textContent = '';
}

function handleFailure(error) {
  if (error instanceof ApiError && error.status === 401) {
    window.location.replace('/');
    return;
  }
  elements['moderation-error'].textContent = error instanceof Error ? error.message : 'The moderation request failed.';
}

function toast(message) {
  const node = document.createElement('div');
  node.className = 'toast info';
  node.textContent = message;
  elements['toast-region'].append(node);
  window.setTimeout(() => node.remove(), 5000);
}

function handleFatal(error) {
  elements['moderation-loading'].textContent = error instanceof Error ? error.message : 'Unable to load moderation queue.';
}
