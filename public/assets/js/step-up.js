import { ApiError, apiPost } from './api.js';

let verificationPromise = null;
let dialogElements = null;

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
  verificationPromise = showDialog().finally(() => {
    verificationPromise = null;
  });
  return verificationPromise;
}

function showDialog() {
  const elements = ensureDialog();
  elements.error.textContent = '';
  elements.password.value = '';
  elements.submit.disabled = false;
  elements.cancel.disabled = false;
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
      finish(() => reject(new ApiError(403, 'step_up_cancelled', 'Privileged authentication was cancelled.')));
    };

    const submit = async (event) => {
      event.preventDefault();
      const password = elements.password.value;
      if (password === '') {
        elements.error.textContent = 'Enter your current password.';
        elements.password.focus();
        return;
      }

      elements.error.textContent = '';
      elements.submit.disabled = true;
      elements.cancel.disabled = true;
      try {
        await apiPost('/api/v1/step-up.php', { password });
        finish(resolve);
      } catch (error) {
        elements.error.textContent = error instanceof Error ? error.message : 'Password verification failed.';
        elements.password.select();
      } finally {
        elements.submit.disabled = false;
        elements.cancel.disabled = false;
      }
    };

    elements.form.addEventListener('submit', submit);
    elements.cancel.addEventListener('click', cancel);
    elements.dialog.addEventListener('cancel', cancel);
  });
}

function ensureDialog() {
  if (dialogElements !== null) return dialogElements;
  ensureStylesheet();

  const dialog = document.createElement('dialog');
  dialog.className = 'step-up-dialog';
  dialog.setAttribute('aria-labelledby', 'step-up-title');

  const form = document.createElement('form');
  form.method = 'dialog';
  form.className = 'step-up-form';

  const title = document.createElement('h2');
  title.id = 'step-up-title';
  title.textContent = 'Confirm this sensitive action';

  const explanation = document.createElement('p');
  explanation.textContent = 'Re-enter your current password. Successful verification permits sensitive administrative actions for a short time in this browser session.';

  const label = document.createElement('label');
  label.htmlFor = 'step-up-password';
  label.textContent = 'Current password';

  const password = document.createElement('input');
  password.id = 'step-up-password';
  password.name = 'current_password';
  password.type = 'password';
  password.autocomplete = 'current-password';
  password.required = true;

  const error = document.createElement('p');
  error.className = 'error-text step-up-error';
  error.setAttribute('role', 'alert');
  error.setAttribute('aria-live', 'assertive');

  const actions = document.createElement('div');
  actions.className = 'action-row step-up-actions';
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'secondary-button';
  cancel.textContent = 'Cancel';
  const submit = document.createElement('button');
  submit.type = 'submit';
  submit.className = 'primary-button';
  submit.textContent = 'Verify password';
  actions.append(cancel, submit);

  form.append(title, explanation, label, password, error, actions);
  dialog.append(form);
  document.body.append(dialog);

  dialogElements = { dialog, form, password, error, cancel, submit };
  return dialogElements;
}

function ensureStylesheet() {
  if (document.querySelector('link[data-step-up-styles]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = '/assets/css/step-up.css';
  link.dataset.stepUpStyles = 'true';
  document.head.append(link);
}
