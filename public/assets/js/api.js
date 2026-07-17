let csrfToken = '';
let sessionRequest = null;

const SESSION_ENDPOINT = '/api/v1/session.php';
const STEP_UP_ENDPOINT = '/api/v1/step-up.php';
const SESSION_CHANGE_ENDPOINTS = new Set([
  '/api/v1/login.php',
  '/api/v1/register.php',
  '/api/v1/logout.php',
  '/api/v1/account/close.php',
  '/api/v1/account/restore.php',
]);

export class ApiError extends Error {
  constructor(status, code, message) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
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
    sessionRequest = request(path, { method: 'GET' }).finally(() => {
      sessionRequest = null;
    });
  }

  return sessionRequest;
}

export async function apiPost(path, body = {}) {
  const send = () => request(path, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify(body),
  });

  try {
    return await send();
  } catch (error) {
    if (
      path === STEP_UP_ENDPOINT
      || !(error instanceof ApiError)
      || error.code !== 'step_up_required'
    ) {
      throw error;
    }
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
    throw new ApiError(
      response.status,
      typeof error.code === 'string' ? error.code : 'request_failed',
      typeof error.message === 'string' ? error.message : `Request failed with HTTP ${response.status}.`,
    );
  }

  if (payload && typeof payload.csrf_token === 'string') {
    setCsrfToken(payload.csrf_token);
  }

  if (options.method === 'POST' && SESSION_CHANGE_ENDPOINTS.has(path)) {
    window.dispatchEvent(new CustomEvent('chitchat:session-changed', {
      detail: { path },
    }));
  }

  return payload ?? {};
}
