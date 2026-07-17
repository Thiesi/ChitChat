import { apiPost } from './api.js';
import { getPasskey, webAuthnSupported } from './webauthn.js';

window.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.getElementById('login-form');
  const authCard = loginForm?.closest('.auth-card');
  const error = document.getElementById('auth-error');
  if (!loginForm || !authCard || !error) return;

  const panel = buildPanel();
  authCard.insertBefore(panel.root, error);
  loginForm.addEventListener('submit', (event) => submitPassword(event, panel, error), true);
});

async function submitPassword(event, panel, error) {
  event.preventDefault();
  event.stopImmediatePropagation();
  error.textContent = '';
  const form = event.currentTarget;
  setBusy(form, true);
  try {
    const response = await apiPost('/api/v1/login.php', {
      username: form.querySelector('#login-username').value,
      password: form.querySelector('#login-password').value,
    });
    form.reset();
    if (response.mfa_required) {
      showMfaPanel(panel);
      return;
    }
    window.location.reload();
  } catch (caught) {
    error.textContent = message(caught);
  } finally {
    setBusy(form, false);
  }
}

function buildPanel() {
  const root = document.createElement('section');
  root.id = 'mfa-login-panel';
  root.className = 'form-stack hidden';
  root.setAttribute('aria-labelledby', 'mfa-login-title');

  const title = document.createElement('h2');
  title.id = 'mfa-login-title';
  title.textContent = 'Complete sign-in';
  const explanation = document.createElement('p');
  explanation.textContent = 'Use a registered passkey. A one-time recovery code is available when your authenticator is unavailable.';
  const passkey = document.createElement('button');
  passkey.id = 'mfa-login-passkey';
  passkey.type = 'button';
  passkey.className = 'primary-button';
  passkey.textContent = 'Use passkey';
  passkey.disabled = !webAuthnSupported();
  const unsupported = document.createElement('p');
  unsupported.className = 'optional-label';
  unsupported.textContent = webAuthnSupported()
    ? ''
    : 'Passkeys are unavailable in this browser or context. Use a recovery code.';

  const recovery = document.createElement('form');
  recovery.id = 'mfa-login-recovery-form';
  recovery.className = 'form-stack';
  const label = document.createElement('label');
  label.textContent = 'Recovery code';
  const input = document.createElement('input');
  input.id = 'mfa-login-recovery-code';
  input.name = 'recovery_code';
  input.type = 'text';
  input.autocomplete = 'one-time-code';
  input.spellcheck = false;
  input.required = true;
  input.maxLength = 40;
  label.append(input);
  const recoveryButton = document.createElement('button');
  recoveryButton.type = 'submit';
  recoveryButton.className = 'secondary-button';
  recoveryButton.textContent = 'Use recovery code';
  recovery.append(label, recoveryButton);

  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'secondary-button';
  cancel.textContent = 'Start over';
  const status = document.createElement('p');
  status.className = 'error-text';
  status.setAttribute('role', 'alert');
  root.append(title, explanation, passkey, unsupported, recovery, cancel, status);

  const panel = { root, passkey, recovery, input, cancel, status };
  passkey.addEventListener('click', () => completeWithPasskey(panel));
  recovery.addEventListener('submit', (event) => completeWithRecovery(event, panel));
  cancel.addEventListener('click', () => cancelPendingLogin(panel));
  return panel;
}

function showMfaPanel(panel) {
  document.querySelector('.auth-tabs')?.classList.add('hidden');
  document.getElementById('login-form')?.classList.add('hidden');
  document.getElementById('register-form')?.classList.add('hidden');
  panel.root.classList.remove('hidden');
  panel.status.textContent = '';
  (panel.passkey.disabled ? panel.input : panel.passkey).focus();
}

async function completeWithPasskey(panel) {
  panel.status.textContent = '';
  setPanelBusy(panel, true);
  try {
    const options = await apiPost('/api/v1/mfa/login-options.php');
    const credential = await getPasskey(options.public_key);
    await apiPost('/api/v1/mfa/login-finish.php', { credential });
    window.location.reload();
  } catch (error) {
    panel.status.textContent = message(error);
  } finally {
    setPanelBusy(panel, false);
  }
}

async function completeWithRecovery(event, panel) {
  event.preventDefault();
  panel.status.textContent = '';
  setPanelBusy(panel, true);
  try {
    await apiPost('/api/v1/mfa/login-recovery.php', {
      recovery_code: panel.input.value,
    });
    panel.recovery.reset();
    window.location.reload();
  } catch (error) {
    panel.status.textContent = message(error);
    panel.input.select();
  } finally {
    setPanelBusy(panel, false);
  }
}

async function cancelPendingLogin(panel) {
  setPanelBusy(panel, true);
  try {
    await apiPost('/api/v1/logout.php');
  } catch {
    // Reload still clears the visible pending flow; the server context expires quickly.
  }
  window.location.reload();
}

function setBusy(form, busy) {
  for (const control of form.querySelectorAll('button, input')) control.disabled = busy;
}

function setPanelBusy(panel, busy) {
  panel.passkey.disabled = busy || !webAuthnSupported();
  for (const control of panel.recovery.querySelectorAll('button, input')) control.disabled = busy;
  panel.cancel.disabled = busy;
}

function message(error) {
  if (error?.name === 'NotAllowedError') return 'Passkey verification was cancelled or timed out.';
  return error instanceof Error ? error.message : 'Unable to complete multi-factor sign-in.';
}
