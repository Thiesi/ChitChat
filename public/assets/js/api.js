import { setDateFormatPreference } from './datetime.js';

let csrfToken = '';
let sessionRequest = null;
let sessionGeneration = 0;

const SESSION_ENDPOINT = '/api/v1/session.php';
const STEP_UP_ENDPOINT = '/api/v1/step-up.php';
const SESSION_VERSION_MARKER = Symbol.for('chitchat.sessionVersion');
/** Pages a guest may stay on when an endpoint refuses guests. */
const GUEST_PAGES = new Set(['/', '/account.php']);

const SESSION_CHANGE_ENDPOINTS = new Set([
  '/api/v1/login.php',
  '/api/v1/register.php',
  '/api/v1/guest/start.php',
  '/api/v1/logout.php',
  '/api/v1/account/close.php',
  '/api/v1/account/restore.php',
]);

// These flows can stage/complete a different identity without a full page load.
const PUSH_IDENTITY_ENDPOINTS = new Set([
  ...SESSION_CHANGE_ENDPOINTS,
  '/api/v1/password-reset.php',
  '/api/v1/oidc/register.php',
  '/api/v1/mfa/login-finish.php',
  '/api/v1/mfa/login-recovery.php',
  '/api/v1/password-reset/mfa-finish.php',
  '/api/v1/password-reset/mfa-recovery.php',
]);

export class ApiError extends Error {
  constructor(status, code, message, retryAfterSeconds = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    // How long a throttled request (429) should wait, when the server says.
    this.retryAfterSeconds = Number.isInteger(retryAfterSeconds) && retryAfterSeconds > 0 ? retryAfterSeconds : null;
  }
}

export function setCsrfToken(token) {
  csrfToken = typeof token === 'string' ? token : '';
}

export function apiGet(path) {
  if (path !== SESSION_ENDPOINT) {
    return request(path, { method: 'GET' });
  }
  if (sessionRequest === null) {
    const pending = request(path, { method: 'GET' });
    const shared = pending.finally(() => {
      if (sessionRequest === shared) sessionRequest = null;
    });
    sessionRequest = shared;
  }

  return sessionRequest;
}

export async function apiPost(path, body = {}) {
  let lifecycle = null;
  let intent = null;
  if (PUSH_IDENTITY_ENDPOINTS.has(path)) {
    ++sessionGeneration;
    sessionRequest = null;
    lifecycle = await import('./push-lifecycle.js');
    // Fence local state before HTTP, including an offline or failed sign-in.
    intent = await lifecycle.beforePushIdentityChange(path).catch(() => null);
  }
  const send = async () => {
    const payload = await request(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(body),
    });
    if (lifecycle) {
      ++sessionGeneration;
      sessionRequest = null;
      void lifecycle.completePushIdentityChange(path, intent).catch(() => {});
    }
    return payload;
  };

  try {
    return await send();
  } catch (error) {
    if (path === STEP_UP_ENDPOINT || !(error instanceof ApiError) || error.code !== 'step_up_required') throw error;
  }
  const { verifyCurrentPassword } = await import('./step-up.js');
  await verifyCurrentPassword();
  return send();
}
export async function apiUpload(path, formData) {
  return request(path, {
    method: 'POST',
    headers: {
      'X-CSRF-Token': csrfToken,
    },
    body: formData,
  });
}

async function request(path, options) {
  const startedSessionGeneration = sessionGeneration;
  const response = await fetch(path, {
    credentials: 'same-origin',
    cache: 'no-store',
    ...options,
  });

  let payload = null;
  const contentType = response.headers.get('content-type') ?? '';
  if (contentType.includes('application/json')) {
    payload = await response.json();
  }

  if (!response.ok) {
    const error = payload?.error ?? {};
    // A guest who reaches a members-only page goes back to the chat. Rooms and
    // the short guest You (S2b) are theirs; on You the member cards stay hidden.
    if (error.code === 'guest_not_allowed' && !GUEST_PAGES.has(window.location.pathname)) {
      window.location.assign('/');
    }
    // A write paused until the person agrees to changed community rules:
    // one page-wide signal, so no feature needs its own handling (rules.js).
    if (response.status === 403 && error.code === 'rules_acceptance_required') {
      window.dispatchEvent(new CustomEvent('chitchat:rules-required'));
    }
    const headerRetry = Number.parseInt(response.headers.get('retry-after') ?? '', 10);
    throw new ApiError(
      response.status,
      typeof error.code === 'string' ? error.code : 'request_failed',
      typeof error.message === 'string' ? error.message : `Request failed with HTTP ${response.status}.`,
      Number.isInteger(error.retry_after_seconds) ? error.retry_after_seconds : (Number.isInteger(headerRetry) ? headerRetry : null),
    );
  }

  // An old bootstrap cannot restore stale CSRF or account markers after sign-in.
  if (path === SESSION_ENDPOINT && startedSessionGeneration !== sessionGeneration) return payload ?? {};

  if (payload && typeof payload.csrf_token === 'string') {
    setCsrfToken(payload.csrf_token);
  }

  // The session carries the account's date and time preference to every page.
  if (path === SESSION_ENDPOINT && payload && 'preferences' in payload) {
    setDateFormatPreference(payload.preferences);
  }

  if (Number.isInteger(payload?.user?.session_version)) {
    window[SESSION_VERSION_MARKER] = payload.user.session_version;
  } else if (options.method === 'POST' && SESSION_CHANGE_ENDPOINTS.has(path)) {
    window[SESSION_VERSION_MARKER] = null;
  }

  if (options.method === 'POST' && SESSION_CHANGE_ENDPOINTS.has(path)) {
    window.dispatchEvent(new CustomEvent('chitchat:session-changed', {
      detail: { path },
    }));
  }

  return payload ?? {};
}
