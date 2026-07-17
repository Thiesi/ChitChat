import { apiGet, setCsrfToken } from './api.js';

const elements = {};

window.addEventListener('DOMContentLoaded', () => {
  for (const id of [
    'status-loading',
    'status-shell',
    'status-identity',
    'status-error',
    'status-generated',
    'status-refresh',
    'maintenance-card',
    'maintenance-state',
    'maintenance-latest',
    'maintenance-success',
    'maintenance-max-age',
    'maintenance-result',
    'database-name',
    'database-size',
    'database-latency',
    'sse-connections',
    'sse-users',
    'presence-leases',
    'presence-users',
    'retained-events',
    'attachment-active',
    'attachment-deleted',
    'attachment-bytes',
    'attachment-free',
    'attachment-used',
    'failed-logins',
    'rate-limit-rows',
    'rate-limit-policies',
    'metrics-enabled',
    'application-name',
    'application-version',
    'application-environment',
  ]) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`Missing system-status element: ${id}`);
    elements[id] = element;
  }

  elements['status-refresh'].addEventListener('click', () => refresh().catch(handleError));
  bootstrap().catch(handleFatal);
});

async function bootstrap() {
  const session = await apiGet('/api/v1/session.php');
  setCsrfToken(session.csrf_token);
  const roles = Array.isArray(session.user?.roles) ? session.user.roles : [];
  if (!session.user || (!roles.includes('super_admin') && !roles.includes('admin'))) {
    window.location.replace('/admin.php');
    return;
  }

  elements['status-identity'].textContent = `Signed in as ${session.user.username}`;
  elements['status-loading'].classList.add('hidden');
  elements['status-shell'].classList.remove('hidden');
  await refresh();
}

async function refresh() {
  setBusy(true);
  elements['status-error'].textContent = '';
  try {
    const response = await apiGet('/api/v1/admin/system-status.php');
    render(response.status);
  } finally {
    setBusy(false);
  }
}

function render(status) {
  const application = object(status.application);
  const database = object(status.database);
  const attachments = object(status.attachments);
  const realtime = object(status.realtime);
  const security = object(status.security);
  const maintenance = object(status.maintenance);
  const metrics = object(status.metrics);

  elements['status-generated'].textContent = `Measured ${formatDateTime(status.generated_at)}.`;
  elements['application-name'].textContent = text(application.name);
  elements['application-version'].textContent = text(application.version);
  elements['application-environment'].textContent = text(application.environment);

  elements['database-name'].textContent = text(database.name);
  elements['database-size'].textContent = formatBytes(database.size_bytes);
  elements['database-latency'].textContent = `${number(database.query_latency_ms).toFixed(3)} ms`;

  elements['sse-connections'].textContent = String(integer(realtime.active_sse_connections));
  elements['sse-users'].textContent = String(integer(realtime.active_sse_users));
  elements['presence-leases'].textContent = String(integer(realtime.active_presence_leases));
  elements['presence-users'].textContent = String(integer(realtime.active_presence_users));
  elements['retained-events'].textContent = String(integer(realtime.retained_events));

  elements['attachment-active'].textContent = String(integer(attachments.active_files));
  elements['attachment-deleted'].textContent = String(integer(attachments.deleted_files));
  elements['attachment-bytes'].textContent = formatBytes(attachments.tracked_bytes);
  elements['attachment-free'].textContent = attachments.storage_available
    ? `${formatBytes(attachments.disk_free_bytes)} of ${formatBytes(attachments.disk_total_bytes)}`
    : 'Unavailable';
  elements['attachment-used'].textContent = attachments.disk_used_percent === null
    ? 'Unavailable'
    : `${number(attachments.disk_used_percent).toFixed(2)}%`;

  elements['failed-logins'].textContent = String(integer(security.failed_logins_24h));
  elements['rate-limit-rows'].textContent = String(integer(security.rate_limit_rows));
  renderRateLimits(security.rate_limit_policies, security.rate_limit_decisions);
  elements['metrics-enabled'].textContent = metrics.enabled ? 'Enabled with bearer token' : 'Disabled';

  const latest = nullableObject(maintenance.latest_run);
  const latestSuccess = nullableObject(maintenance.latest_successful_destructive_run);
  const overdue = maintenance.overdue === true;
  elements['maintenance-state'].textContent = overdue ? 'Overdue' : 'Current';
  elements['maintenance-state'].className = `status-badge ${overdue ? 'warning' : 'ok'}`;
  elements['maintenance-card'].classList.toggle('status-warning-card', overdue);
  elements['maintenance-latest'].textContent = latest ? describeRun(latest) : 'Never';
  elements['maintenance-success'].textContent = latestSuccess
    ? `${formatDateTime(latestSuccess.finished_at)} (${formatAge(maintenance.latest_success_age_seconds)} ago)`
    : 'Never';
  elements['maintenance-max-age'].textContent = `${integer(maintenance.maximum_age_hours)} hours`;
  elements['maintenance-result'].textContent = latest?.result ? summarizeResult(latest.result) : text(latest?.error_message ?? '—');
}

function renderRateLimits(policiesValue, decisionsValue) {
  const policies = object(policiesValue);
  const decisions = new Map();
  if (Array.isArray(decisionsValue)) {
    for (const value of decisionsValue) {
      const decision = object(value);
      if (typeof decision.policy === 'string') decisions.set(decision.policy, decision);
    }
  }

  elements['rate-limit-policies'].replaceChildren();
  for (const [name, value] of Object.entries(policies).sort(([left], [right]) => left.localeCompare(right))) {
    const policy = object(value);
    const decision = decisions.get(name) ?? {};
    const row = document.createElement('tr');

    const nameCell = document.createElement('th');
    nameCell.scope = 'row';
    const code = document.createElement('code');
    code.textContent = name;
    nameCell.append(code);

    const limitCell = document.createElement('td');
    limitCell.textContent = `${integer(policy.maximum_attempts)} / ${formatWindow(policy.window_seconds)}`;

    const allowedCell = document.createElement('td');
    allowedCell.textContent = String(integer(decision.allowed));

    const rejectedCell = document.createElement('td');
    rejectedCell.textContent = String(integer(decision.rejected));

    const lastRejectedCell = document.createElement('td');
    lastRejectedCell.textContent = typeof decision.last_rejected_at === 'string'
      ? formatDateTime(decision.last_rejected_at)
      : 'Never';

    row.append(nameCell, limitCell, allowedCell, rejectedCell, lastRejectedCell);
    elements['rate-limit-policies'].append(row);
  }
}

function describeRun(run) {
  const mode = run.dry_run ? 'dry run' : 'cleanup';
  const completed = run.finished_at ? formatDateTime(run.finished_at) : 'still running';
  const duration = run.duration_ms === null ? '' : ` · ${number(run.duration_ms).toFixed(0)} ms`;
  return `${text(run.status)} ${mode} · ${completed}${duration}`;
}

function summarizeResult(result) {
  const removed = integer(result.files_removed);
  const failures = integer(result.file_removal_failures);
  const messages = integer(result.room_messages) + integer(result.direct_messages);
  const operational = integer(result.expired_sse_connections) + integer(result.expired_presence_leases);
  return `${messages} messages, ${removed} files, ${operational} expired leases; ${failures} file failures`;
}

function formatBytes(value) {
  let bytes = Math.max(0, number(value));
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
  let unit = 0;
  while (bytes >= 1024 && unit < units.length - 1) {
    bytes /= 1024;
    unit += 1;
  }
  return `${bytes.toFixed(unit === 0 ? 0 : 2)} ${units[unit]}`;
}

function formatWindow(value) {
  const seconds = integer(value);
  if (seconds % 3600 === 0) return `${seconds / 3600}h`;
  if (seconds % 60 === 0) return `${seconds / 60}m`;
  return `${seconds}s`;
}

function formatAge(value) {
  const seconds = integer(value);
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h`;
  return `${Math.floor(seconds / 86400)}d`;
}

function formatDateTime(value) {
  if (typeof value !== 'string' || value === '') return 'Unknown';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function object(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function nullableObject(value) {
  return value === null ? null : object(value);
}

function text(value) {
  return typeof value === 'string' && value !== '' ? value : '—';
}

function number(value) {
  return typeof value === 'number' && Number.isFinite(value) ? value : 0;
}

function integer(value) {
  return Math.trunc(number(value));
}

function setBusy(busy) {
  elements['status-refresh'].disabled = busy;
}

function handleError(error) {
  elements['status-error'].textContent = error instanceof Error ? error.message : 'The status request failed.';
}

function handleFatal(error) {
  handleError(error);
  elements['status-loading'].textContent = error instanceof Error ? error.message : 'System status could not be loaded.';
}
