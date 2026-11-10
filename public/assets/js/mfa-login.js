import { apiPost } from './api.js';
import { apiGet } from './api.js';
import { getPasskey, webAuthnSupported } from './webauthn.js';
import { buildTotpForm, describeTotpError, pendingMethods } from './totp-common.js';

const SIGN_IN_TITLE = 'Complete sign-in';
const SIGN_IN_TEXT = 'Use a registered passkey. A one-time recovery code is available when your authenticator is unavailable.';
const SIGN_IN_TEXT_WITH_APP = 'Enter the code from your authenticator app, or use a passkey. A one-time recovery code works when neither is at hand.';
const SIGN_IN_ENDPOINTS = {
  options: '/api/v1/mfa/login-options.php',
  finish: '/api/v1/mfa/login-finish.php',
  recovery: '/api/v1/mfa/login-recovery.php',
  recoveryField: 'recovery_code',
  totp: '/api/v1/mfa/login-totp.php',
};
let sharedPanel = null;
// A password reset (#155) finishes through this same panel, but keeps the
// completion response (it carries the new recovery key) and handles cancel.
let continuation = null;

/**
 * Shows the second-factor step for a password reset. `onComplete` receives
 * the completing response; `onCancel` runs instead of signing out.
 */
export function continueResetWithMfa({ title, text, endpoints, methods, onComplete, onCancel, onUncertain }) {
  if (!sharedPanel) return false;
  continuation = { onComplete, onCancel, onUncertain, endpoints };
  sharedPanel.title.textContent = title;
  showMfaPanel(sharedPanel, methods);
  sharedPanel.explanation.textContent = text;
  return true;
}

export function hideMfaPanel() {
  continuation = null;
  if (!sharedPanel) return;
  sharedPanel.root.classList.add('hidden');
  sharedPanel.title.textContent = SIGN_IN_TITLE;
  sharedPanel.explanation.textContent = SIGN_IN_TEXT;
}

async function finish(response) {
  if (continuation) {
    const { onComplete } = continuation;
    continuation = null;
    await onComplete(response ?? {});
    return;
  }
  window.location.reload();
}

window.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.getElementById('login-form');
  const authCard = loginForm?.closest('.auth-card');
  const error = document.getElementById('auth-error');
  if (!loginForm || !authCard || !error) return;

  const panel = buildPanel();
  sharedPanel = panel;
  authCard.insertBefore(panel.root, error);
  loginForm.addEventListener('submit', (event) => submitPassword(event, panel, error), true);

  // A Google or Twitch sign-in on an account with multi-factor authentication continues here.
  const parameters = new URLSearchParams(window.location.search);
  if (parameters.get('mfa') === 'continue') {
    parameters.delete('mfa');
    const query = parameters.toString();
    window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
    // A provider sign-in brings no methods list; the session may say which apply.
    apiGet('/api/v1/session.php')
      .then((session) => showMfaPanel(panel, pendingMethods(null, session)))
      .catch(() => showMfaPanel(panel, null));
  }
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
      showMfaPanel(panel, response.methods);
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
  title.textContent = SIGN_IN_TITLE;
  title.tabIndex = -1;
  const explanation = document.createElement('p');
  explanation.textContent = SIGN_IN_TEXT;
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
  // An authenticator code (#204), offered first when the account has one: passkey and app are equal choices.
  const totp = buildTotpForm('mfa-login-totp-code', () => completeWithTotp(panel));
  root.append(title, explanation, totp.form, passkey, unsupported, recovery, cancel, status);

  const panel = { root, title, explanation, passkey, recovery, input, cancel, status, totp };
  passkey.addEventListener('click', () => completeWithPasskey(panel));
  recovery.addEventListener('submit', (event) => completeWithRecovery(event, panel));
  cancel.addEventListener('click', () => cancelPendingLogin(panel));
  return panel;
}

function showMfaPanel(panel, methods = null) {
  const withApp = Array.isArray(methods) && methods.includes('totp');
  panel.totp.form.hidden = !withApp;
  panel.explanation.textContent = withApp ? SIGN_IN_TEXT_WITH_APP : SIGN_IN_TEXT;
  document.querySelector('.auth-tabs')?.classList.add('hidden');
  document.getElementById('login-form')?.classList.add('hidden');
  document.getElementById('register-form')?.classList.add('hidden');
  document.getElementById('password-reset')?.classList.add('hidden');
  panel.root.classList.remove('hidden');
  panel.status.textContent = '';
  (withApp ? panel.totp.input : (panel.passkey.disabled ? panel.input : panel.passkey)).focus();
}

async function completeWithTotp(panel) {
  panel.status.textContent = '';
  setPanelBusy(panel, true);
  try {
    const endpoints = continuation?.endpoints ?? SIGN_IN_ENDPOINTS;
    const response = await apiPost(endpoints.totp, { code: panel.totp.input.value });
    panel.totp.form.reset();
    await finish(response);
  } catch (error) {
    if (uncertain(error)) return;
    panel.status.textContent = describeTotpError(error);
    panel.totp.input.select();
  } finally {
    setPanelBusy(panel, false);
  }
}

async function completeWithPasskey(panel) {
  panel.status.textContent = '';
  setPanelBusy(panel, true);
  try {
    const endpoints = continuation?.endpoints ?? SIGN_IN_ENDPOINTS;
    const options = await apiPost(endpoints.options);
    const credential = await getPasskey(options.public_key);
    await finish(await apiPost(endpoints.finish, { credential }));
  } catch (error) {
    if (uncertain(error)) return;
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
    const endpoints = continuation?.endpoints ?? SIGN_IN_ENDPOINTS;
    const response = await apiPost(endpoints.recovery, {
      [endpoints.recoveryField ?? 'recovery_code']: panel.input.value,
    });
    panel.recovery.reset();
    await finish(response);
  } catch (error) {
    if (uncertain(error)) return;
    panel.status.textContent = message(error);
    panel.input.select();
  } finally {
    setPanelBusy(panel, false);
  }
}

// The request never answered: it may or may not have completed a reset.
function uncertain(error) {
  if (!continuation?.onUncertain || !(error instanceof TypeError)) return false;
  const { onUncertain } = continuation;
  continuation = null;
  onUncertain();
  return true;
}

async function cancelPendingLogin(panel) {
  if (continuation) {
    const { onCancel } = continuation;
    setPanelBusy(panel, true);
    try {
      await onCancel();
    } finally {
      setPanelBusy(panel, false);
    }
    return;
  }
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
  for (const control of panel.totp.form.querySelectorAll('button, input')) control.disabled = busy;
  panel.cancel.disabled = busy;
}

function message(error) {
  if (error?.name === 'NotAllowedError') return 'Passkey verification was cancelled or timed out.';
  return error instanceof Error ? error.message : 'Unable to complete multi-factor sign-in.';
}
