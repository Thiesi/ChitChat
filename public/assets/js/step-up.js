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
  const response = await apiGet('/api/v1/account/mfa/status.php');
  return response.mfa?.enabled ? showMfaDialog(response.mfa) : showPasswordDialog();
}

function showPasswordDialog() {
  const elements = ensurePasswordDialog();
  elements.error.textContent = '';
  elements.password.value = '';
  setPasswordBusy(elements, false);
  elements.dialog.showModal();
  elements.password.focus();

  return new Promise((resolve, reject) => {
    let settled = false;
    const cleanup = () => {
      elements.form.removeEventListener('submit', submit);
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
    elements.form.addEventListener('submit', submit);
    elements.cancel.addEventListener('click', cancel);
    elements.dialog.addEventListener('cancel', cancel);
  });
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
  explanation.textContent = 'Re-enter your current password. Successful verification permits sensitive actions for a short time in this browser session.';
  const label = document.createElement('label');
  label.htmlFor = 'step-up-password';
  label.textContent = 'Current password';
  const password = document.createElement('input');
  password.id = 'step-up-password';
  password.type = 'password';
  password.autocomplete = 'current-password';
  password.required = true;
  label.append(password);
  const error = alertNode();
  const actions = document.createElement('div');
  actions.className = 'action-row step-up-actions';
  const cancel = button('Cancel', 'secondary-button');
  const submit = button('Verify password', 'primary-button', 'submit');
  actions.append(cancel, submit);
  form.append(title, explanation, label, password, error, actions);
  dialog.append(form);
  document.body.append(dialog);
  passwordDialog = { dialog, form, password, error, cancel, submit };
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
