import { ApiError, apiGet, apiPost } from './api.js';
import { getPasskey, webAuthnSupported } from './webauthn.js';

let verificationPromise = null;
let passwordDialog = null;
let mfaDialog = null;

export async function withPrivilegedStepUp(operation) {
  try {
    return await operation();
  } catch (error) {
    if (!(error instanceof ApiError) || error.code !== 'step_up_required') throw error;
  }
  await verifyCurrentPassword();
  return operation();
}

export function verifyCurrentPassword() {
  if (verificationPromise !== null) return verificationPromise;
  verificationPromise = chooseMethod().finally(() => {
    verificationPromise = null;
  });
  return verificationPromise;
}

async function chooseMethod() {
  const [response, methods] = await Promise.all([
    apiGet('/api/v1/account/mfa/status.php'),
    apiGet('/api/v1/account/identities/list.php').catch(() => null),
  ]);
  if (response.mfa?.enabled) return showMfaDialog(response.mfa);
  // Connected Google or Twitch accounts can confirm too; accounts created
  // through one of them may have no password at all.
  const configured = new Set((methods?.providers ?? []).map((provider) => provider.id));
  const providers = (methods?.identities ?? [])
    .filter((identity) => configured.has(identity.provider))
    .map((identity) => ({ id: identity.provider, label: identity.label }));
  return showPasswordDialog(providers, methods?.has_password !== false);
}

function showPasswordDialog(providers = [], hasPassword = true) {
  const elements = ensurePasswordDialog();
  elements.error.textContent = '';
  elements.password.value = '';
  elements.passwordFields.classList.toggle('hidden', !hasPassword);
  elements.submit.classList.toggle('hidden', !hasPassword);
  elements.explanation.textContent = hasPassword
    ? 'Re-enter your current password. Successful verification permits sensitive actions for a short time in this browser session.'
    : 'Sign in again with the account you use for ChitChat. Successful verification permits sensitive actions for a short time in this browser session.';
  elements.providers.replaceChildren(...providers.map((provider) => {
    const node = button(`Confirm with ${provider.label}`, hasPassword ? 'secondary-button' : 'primary-button');
    node.dataset.provider = provider.id;
    return node;
  }));
  setPasswordBusy(elements, false);
  elements.dialog.showModal();
  (hasPassword ? elements.password : elements.providers.querySelector('button') ?? elements.cancel).focus();

  return new Promise((resolve, reject) => {
    let settled = false;
    let stopWaiting = null;
    const cleanup = () => {
      stopWaiting?.();
      elements.form.removeEventListener('submit', submit);
      elements.providers.removeEventListener('click', confirmWithProvider);
      elements.cancel.removeEventListener('click', cancel);
      elements.dialog.removeEventListener('cancel', cancel);
    };
    const finish = (callback) => {
      if (settled) return;
      settled = true;
      cleanup();
      if (elements.dialog.open) elements.dialog.close();
      callback();
    };
    const cancel = (event) => {
      event?.preventDefault();
      finish(() => reject(cancelled()));
    };
    const submit = async (event) => {
      event.preventDefault();
      if (elements.password.value === '') {
        elements.error.textContent = 'Enter your current password.';
        elements.password.focus();
        return;
      }
      elements.error.textContent = '';
      setPasswordBusy(elements, true);
      try {
        await apiPost('/api/v1/step-up.php', { password: elements.password.value });
        finish(resolve);
      } catch (error) {
        elements.error.textContent = message(error, 'Password verification failed.');
        elements.password.select();
      } finally {
        setPasswordBusy(elements, false);
      }
    };
    const confirmWithProvider = async (event) => {
      const target = event.target.closest('button[data-provider]');
      if (!target || stopWaiting) return;
      elements.error.textContent = '';
      // Open the window inside the click, or pop-up blockers stop it.
      const popup = window.open('about:blank', 'chitchat-step-up', 'popup,width=520,height=720');
      if (!popup) {
        elements.error.textContent = 'Your browser blocked the sign-in window. Allow pop-ups for this site and try again.';
        return;
      }
      setPasswordBusy(elements, true);
      elements.cancel.disabled = false;
      try {
        const { url } = await apiPost('/api/v1/oidc/begin.php', { provider: target.dataset.provider, purpose: 'step_up' });
        popup.location.href = url;
        const waiting = waitForProviderConfirmation();
        stopWaiting = waiting.stop;
        await waiting.done;
        finish(resolve);
      } catch (error) {
        popup.close();
        elements.error.textContent = message(error, 'The confirmation did not complete.');
      } finally {
        stopWaiting = null;
        setPasswordBusy(elements, false);
      }
    };
    elements.form.addEventListener('submit', submit);
    elements.providers.addEventListener('click', confirmWithProvider);
    elements.cancel.addEventListener('click', cancel);
    elements.dialog.addEventListener('cancel', cancel);
  });
}

// Resolves when the provider window reports success, or when the session
// shows the confirmation (in case the message cannot get through).
function waitForProviderConfirmation() {
  let stop = () => {};
  const done = new Promise((resolve, reject) => {
    const channel = 'BroadcastChannel' in window ? new BroadcastChannel('chitchat-step-up') : null;
    let polling = false;
    const settle = (callback) => {
      stop();
      callback();
    };
    channel?.addEventListener('message', (event) => {
      if (event.data?.confirmed) settle(resolve);
      else if (event.data?.error) settle(() => reject(new Error(event.data.error)));
    });
    const poll = window.setInterval(async () => {
      if (polling) return;
      polling = true;
      try {
        const session = await apiGet('/api/v1/session.php');
        if (session.security?.privileged_step_up?.active) settle(resolve);
      } catch {
        // Keep waiting; the window may still report back.
      } finally {
        polling = false;
      }
    }, 2_000);
    const timeout = window.setTimeout(() => settle(() => reject(new Error('The confirmation timed out. Please try again.'))), 600_000);
    stop = () => {
      channel?.close();
      window.clearInterval(poll);
      window.clearTimeout(timeout);
    };
  });
  return { done, stop: () => stop() };
}

function showMfaDialog(status) {
  const elements = ensureMfaDialog();
  elements.error.textContent = '';
  elements.recovery.value = '';
  elements.remaining.textContent = `${status.recovery_codes_remaining ?? 0} recovery codes remain.`;
  setMfaBusy(elements, false);
  elements.unsupported.textContent = webAuthnSupported()
    ? ''
    : 'Passkeys are unavailable here. Use a recovery code.';
  elements.dialog.showModal();
  (elements.passkey.disabled ? elements.recovery : elements.passkey).focus();

  return new Promise((resolve, reject) => {
    let settled = false;
    const cleanup = () => {
      elements.passkey.removeEventListener('click', usePasskey);
      elements.recoveryForm.removeEventListener('submit', useRecovery);
      elements.cancel.removeEventListener('click', cancel);
      elements.dialog.removeEventListener('cancel', cancel);
    };
    const finish = (callback) => {
      if (settled) return;
      settled = true;
      cleanup();
      if (elements.dialog.open) elements.dialog.close();
      callback();
    };
    const cancel = (event) => {
      event?.preventDefault();
      finish(() => reject(cancelled()));
    };
    const usePasskey = async () => {
      elements.error.textContent = '';
      setMfaBusy(elements, true);
      try {
        const options = await apiPost('/api/v1/mfa/step-up-options.php');
        const credential = await getPasskey(options.public_key);
        await apiPost('/api/v1/mfa/step-up-finish.php', { credential });
        finish(resolve);
      } catch (error) {
        elements.error.textContent = message(error, 'Passkey verification failed.');
      } finally {
        setMfaBusy(elements, false);
      }
    };
    const useRecovery = async (event) => {
      event.preventDefault();
      if (elements.recovery.value.trim() === '') {
        elements.error.textContent = 'Enter a recovery code.';
        elements.recovery.focus();
        return;
      }
      elements.error.textContent = '';
      setMfaBusy(elements, true);
      try {
        await apiPost('/api/v1/mfa/step-up-recovery.php', {
          recovery_code: elements.recovery.value,
        });
        finish(resolve);
      } catch (error) {
        elements.error.textContent = message(error, 'Recovery-code verification failed.');
        elements.recovery.select();
      } finally {
        setMfaBusy(elements, false);
      }
    };
    elements.passkey.addEventListener('click', usePasskey);
    elements.recoveryForm.addEventListener('submit', useRecovery);
    elements.cancel.addEventListener('click', cancel);
    elements.dialog.addEventListener('cancel', cancel);
  });
}

function ensurePasswordDialog() {
  if (passwordDialog) return passwordDialog;
  ensureStylesheet();
  const dialog = document.createElement('dialog');
  dialog.className = 'step-up-dialog';
  dialog.setAttribute('aria-labelledby', 'step-up-password-title');
  const form = document.createElement('form');
  form.method = 'dialog';
  form.className = 'step-up-form';
  const title = document.createElement('h2');
  title.id = 'step-up-password-title';
  title.textContent = 'Confirm this sensitive action';
  const explanation = document.createElement('p');
  const passwordFields = document.createElement('div');
  passwordFields.className = 'form-stack';
  const label = document.createElement('label');
  label.htmlFor = 'step-up-password';
  label.textContent = 'Current password';
  const password = document.createElement('input');
  password.id = 'step-up-password';
  password.type = 'password';
  password.autocomplete = 'current-password';
  label.append(password);
  passwordFields.append(label);
  const providers = document.createElement('div');
  providers.className = 'step-up-providers';
  const error = alertNode();
  const actions = document.createElement('div');
  actions.className = 'action-row step-up-actions';
  const cancel = button('Cancel', 'secondary-button');
  const submit = button('Verify password', 'primary-button', 'submit');
  actions.append(cancel, submit);
  form.append(title, explanation, passwordFields, providers, error, actions);
  dialog.append(form);
  document.body.append(dialog);
  passwordDialog = { dialog, form, explanation, passwordFields, password, providers, error, cancel, submit };
  return passwordDialog;
}

function ensureMfaDialog() {
  if (mfaDialog) return mfaDialog;
  ensureStylesheet();
  const dialog = document.createElement('dialog');
  dialog.className = 'step-up-dialog';
  dialog.setAttribute('aria-labelledby', 'step-up-mfa-title');
  const wrap = document.createElement('div');
  wrap.className = 'step-up-form';
  const title = document.createElement('h2');
  title.id = 'step-up-mfa-title';
  title.textContent = 'Confirm with multi-factor authentication';
  const explanation = document.createElement('p');
  explanation.textContent = 'Use a registered passkey. A one-time recovery code is available when your authenticator is unavailable.';
  const passkey = button('Use passkey', 'primary-button');
  const unsupported = document.createElement('p');
  unsupported.className = 'optional-label';
  const recoveryForm = document.createElement('form');
  recoveryForm.className = 'form-stack';
  const label = document.createElement('label');
  label.textContent = 'Recovery code';
  const recovery = document.createElement('input');
  recovery.type = 'text';
  recovery.autocomplete = 'one-time-code';
  recovery.spellcheck = false;
  recovery.maxLength = 40;
  recovery.required = true;
  label.append(recovery);
  const recoverySubmit = button('Use recovery code', 'secondary-button', 'submit');
  recoveryForm.append(label, recoverySubmit);
  const remaining = document.createElement('p');
  remaining.className = 'optional-label';
  const error = alertNode();
  const cancel = button('Cancel', 'secondary-button');
  wrap.append(title, explanation, passkey, unsupported, recoveryForm, remaining, error, cancel);
  dialog.append(wrap);
  document.body.append(dialog);
  mfaDialog = { dialog, passkey, unsupported, recoveryForm, recovery, recoverySubmit, remaining, error, cancel };
  return mfaDialog;
}

function button(text, className, type = 'button') {
  const node = document.createElement('button');
  node.type = type;
  node.className = className;
  node.textContent = text;
  return node;
}

function alertNode() {
  const node = document.createElement('p');
  node.className = 'error-text step-up-error';
  node.setAttribute('role', 'alert');
  node.setAttribute('aria-live', 'assertive');
  return node;
}

function setPasswordBusy(elements, busy) {
  elements.password.disabled = busy;
  elements.submit.disabled = busy;
  elements.cancel.disabled = busy;
  for (const node of elements.providers.querySelectorAll('button')) node.disabled = busy;
}

function setMfaBusy(elements, busy) {
  elements.passkey.disabled = busy || !webAuthnSupported();
  elements.recovery.disabled = busy;
  elements.recoverySubmit.disabled = busy;
  elements.cancel.disabled = busy;
}

function cancelled() {
  return new ApiError(403, 'step_up_cancelled', 'Privileged authentication was cancelled.');
}

function message(error, fallback) {
  if (error?.name === 'NotAllowedError') return 'Passkey verification was cancelled or timed out.';
  return error instanceof Error ? error.message : fallback;
}

function ensureStylesheet() {
  if (document.querySelector('link[data-step-up-styles]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = '/assets/css/step-up.css';
  link.dataset.stepUpStyles = 'true';
  document.head.append(link);
}
