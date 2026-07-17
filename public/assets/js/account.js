import { ApiError, apiGet, apiPost, setCsrfToken } from './api.js';

const elements = {
  loading: document.querySelector('#account-loading'),
  shell: document.querySelector('#account-shell'),
  identity: document.querySelector('#account-identity'),
  exportButton: document.querySelector('#personal-data-export'),
  status: document.querySelector('#personal-data-status'),
  error: document.querySelector('#account-error'),
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
    elements.exportButton.addEventListener('click', downloadExport);
    elements.loading.classList.add('hidden');
    elements.shell.classList.remove('hidden');
  } catch (error) {
    elements.loading.textContent = error instanceof Error ? error.message : 'Unable to load the account page.';
  }
}

async function downloadExport() {
  elements.error.textContent = '';
  elements.status.textContent = 'Preparing your export…';
  elements.exportButton.disabled = true;

  try {
    const payload = await apiPost('/api/v1/account/export.php');
    if (!payload.export || typeof payload.filename !== 'string') {
      throw new ApiError(500, 'invalid_export', 'The server returned an invalid export.');
    }

    const json = `${JSON.stringify(payload.export, null, 2)}\n`;
    const blob = new Blob([json], { type: 'application/json;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = payload.filename;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 0);
    elements.status.textContent = `Downloaded ${payload.filename}.`;
  } catch (error) {
    if (error instanceof ApiError && error.code === 'step_up_cancelled') {
      elements.status.textContent = 'Export cancelled.';
      return;
    }
    elements.status.textContent = '';
    elements.error.textContent = error instanceof Error ? error.message : 'Unable to prepare your export.';
  } finally {
    elements.exportButton.disabled = false;
  }
}
