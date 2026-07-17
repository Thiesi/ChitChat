let csrfToken = '';

const SESSION_CHANGE_ENDPOINTS = new Set([
  '/api/v1/login.php',
  '/api/v1/register.php',
  '/api/v1/logout.php',
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

export async function apiGet(path) {
  return request(path, { method: 'GET' });
}

export async function apiPost(path, body = {}) {
  return request(path, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify(body),
  });
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
