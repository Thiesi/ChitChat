import { apiGet, apiPost, setCsrfToken } from './api.js';
import { getPasskey, webAuthnSupported } from './webauthn.js';

const form = document.querySelector('#restore-account-form');
const username = document.querySelector('#restore-username');
const password = document.querySelector('#restore-password');
const status = document.querySelector('#restore-account-status');
const error = document.querySelector('#restore-account-error');
const panel = buildMfaPanel();
form.after(panel.root);

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
    error.textContent = message(cause, 'Unable to initialize account restoration.');
  }
}

async function restoreAccount(event) {
  event.preventDefault();
  error.textContent = '';
  status.textContent = 'Restoring your account…';
  setFormBusy(true);

  try {
    const response = await apiPost('/api/v1/account/restore.php', {
      username: username.value,
      password: password.value,
    });
    form.reset();
    if (response.mfa_required) {
      status.textContent = 'The account is restored. Complete multi-factor authentication to sign in.';
      form.classList.add('hidden');
      panel.root.classList.remove('hidden');
      (panel.passkey.disabled ? panel.code : panel.passkey).focus();
      return;
    }
    finish();
  } catch (cause) {
    status.textContent = '';
    error.textContent = message(cause, 'Unable to restore the account.');
  } finally {
    setFormBusy(false);
  }
}

function buildMfaPanel() {
  const root = document.createElement('section');
  root.id = 'restore-mfa-panel';
  root.className = 'form-stack hidden';
  root.setAttribute('aria-labelledby', 'restore-mfa-title');
  const title = document.createElement('h2');
  title.id = 'restore-mfa-title';
  title.textContent = 'Complete restored sign-in';
  const explanation = document.createElement('p');
  explanation.textContent = 'Use a registered passkey or one of the account’s one-time recovery codes.';
  const passkey = document.createElement('button');
  passkey.type = 'button';
  passkey.className = 'primary-button';
  passkey.textContent = 'Use passkey';
  passkey.disabled = !webAuthnSupported();
  const unsupported = document.createElement('p');
  unsupported.className = 'optional-label';
  unsupported.textContent = webAuthnSupported()
    ? ''
    : 'Passkeys are unavailable in this browser or context. Use a recovery code.';
  const recoveryForm = document.createElement('form');
  recoveryForm.className = 'form-stack';
  const label = document.createElement('label');
  label.textContent = 'Recovery code';
  const code = document.createElement('input');
  code.type = 'text';
  code.autocomplete = 'one-time-code';
  code.spellcheck = false;
  code.maxLength = 40;
  code.required = true;
  label.append(code);
  const recoverySubmit = document.createElement('button');
  recoverySubmit.type = 'submit';
  recoverySubmit.className = 'secondary-button';
  recoverySubmit.textContent = 'Use recovery code';
  recoveryForm.append(label, recoverySubmit);
  const panelError = document.createElement('p');
  panelError.className = 'error-text';
  panelError.setAttribute('role', 'alert');
  root.append(title, explanation, passkey, unsupported, recoveryForm, panelError);

  const result = { root, passkey, recoveryForm, recoverySubmit, code, error: panelError };
  passkey.addEventListener('click', () => completeWithPasskey(result));
  recoveryForm.addEventListener('submit', (event) => completeWithRecovery(event, result));
  return result;
}

async function completeWithPasskey(mfaPanel) {
  mfaPanel.error.textContent = '';
  setMfaBusy(mfaPanel, true);
  try {
    const options = await apiPost('/api/v1/mfa/login-options.php');
    const credential = await getPasskey(options.public_key);
    await apiPost('/api/v1/mfa/login-finish.php', { credential });
    finish();
  } catch (cause) {
    mfaPanel.error.textContent = message(cause, 'Unable to verify the passkey.');
  } finally {
    setMfaBusy(mfaPanel, false);
  }
}

async function completeWithRecovery(event, mfaPanel) {
  event.preventDefault();
  mfaPanel.error.textContent = '';
  setMfaBusy(mfaPanel, true);
  try {
    await apiPost('/api/v1/mfa/login-recovery.php', {
      recovery_code: mfaPanel.code.value,
    });
    mfaPanel.recoveryForm.reset();
    finish();
  } catch (cause) {
    mfaPanel.error.textContent = message(cause, 'Unable to verify the recovery code.');
    mfaPanel.code.select();
  } finally {
    setMfaBusy(mfaPanel, false);
  }
}

function finish() {
  status.textContent = 'Your account has been restored. Redirecting…';
  window.location.assign('/');
}

function setFormBusy(busy) {
  for (const control of form.elements) control.disabled = busy;
}

function setMfaBusy(mfaPanel, busy) {
  mfaPanel.passkey.disabled = busy || !webAuthnSupported();
  mfaPanel.code.disabled = busy;
  mfaPanel.recoverySubmit.disabled = busy;
}

function message(cause, fallback) {
  if (cause?.name === 'NotAllowedError') return 'Passkey verification was cancelled or timed out.';
  return cause instanceof Error ? cause.message : fallback;
}
