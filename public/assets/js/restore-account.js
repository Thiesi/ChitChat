import { apiGet, apiPost, setCsrfToken } from './api.js';

const form = document.querySelector('#restore-account-form');
const username = document.querySelector('#restore-username');
const password = document.querySelector('#restore-password');
const status = document.querySelector('#restore-account-status');
const error = document.querySelector('#restore-account-error');

void initialize();

async function initialize() {
  try {
    const session = await apiGet('/api/v1/session.php');
    setCsrfToken(session.csrf_token ?? '');
    if (session.user) {
      window.location.assign('/');
      return;
    }
    form.addEventListener('submit', restoreAccount);
  } catch (cause) {
    error.textContent = cause instanceof Error ? cause.message : 'Unable to initialize account restoration.';
  }
}

async function restoreAccount(event) {
  event.preventDefault();
  error.textContent = '';
  status.textContent = 'Restoring your account…';
  setBusy(true);

  try {
    await apiPost('/api/v1/account/restore.php', {
      username: username.value,
      password: password.value,
    });
    form.reset();
    status.textContent = 'Your account has been restored. Redirecting…';
    window.location.assign('/');
  } catch (cause) {
    status.textContent = '';
    error.textContent = cause instanceof Error ? cause.message : 'Unable to restore the account.';
  } finally {
    setBusy(false);
  }
}

function setBusy(busy) {
  for (const control of form.elements) {
    control.disabled = busy;
  }
}
