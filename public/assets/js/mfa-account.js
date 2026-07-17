import { apiGet, apiPost, setCsrfToken } from './api.js';
import { createPasskey, webAuthnSupported } from './webauthn.js';

let card = null;
let status = null;

void initialize();

async function initialize() {
  const shell = document.getElementById('account-shell');
  const closure = document.querySelector('[aria-labelledby="account-closure-heading"]');
  if (!shell || !closure) return;
  card = buildCard();
  closure.before(card.root);
  try {
    const session = await apiGet('/api/v1/session.php');
    setCsrfToken(session.csrf_token ?? '');
    if (!session.user) return;
    const response = await apiGet('/api/v1/account/mfa/status.php');
    render(response.mfa);
  } catch (error) {
    card.error.textContent = message(error);
  }
}

function buildCard() {
  const root = document.createElement('section');
  root.className = 'account-card';
  root.setAttribute('aria-labelledby', 'mfa-heading');
  root.innerHTML = `
    <div>
      <p class="account-eyebrow">Account security</p>
      <h2 id="mfa-heading">Passkeys and recovery codes</h2>
    </div>
    <p id="mfa-summary"></p>
    <p id="mfa-policy" class="account-muted"></p>
    <form id="mfa-add-form" class="account-inline-form">
      <label>Passkey label
        <input id="mfa-label" name="label" type="text" maxlength="80" value="My passkey" required>
      </label>
      <button id="mfa-add" class="primary-button" type="submit">Add passkey</button>
    </form>
    <p id="mfa-browser" class="account-muted"></p>
    <div id="mfa-credentials" class="account-credential-list"></div>
    <div class="account-action-row">
      <button id="mfa-recovery-regenerate" class="secondary-button" type="button">Generate new recovery codes</button>
      <span id="mfa-recovery-status" class="account-muted"></span>
    </div>
    <div class="account-action-row">
      <button id="mfa-disable" class="danger-button" type="button">Disable multi-factor authentication</button>
      <span class="account-muted">Disabling MFA invalidates your other sessions.</span>
    </div>
    <p id="mfa-error" class="error-text" role="alert"></p>
  `;
  const result = {
    root,
    summary: root.querySelector('#mfa-summary'),
    policy: root.querySelector('#mfa-policy'),
    addForm: root.querySelector('#mfa-add-form'),
    label: root.querySelector('#mfa-label'),
    add: root.querySelector('#mfa-add'),
    browser: root.querySelector('#mfa-browser'),
    credentials: root.querySelector('#mfa-credentials'),
    regenerate: root.querySelector('#mfa-recovery-regenerate'),
    recoveryStatus: root.querySelector('#mfa-recovery-status'),
    disable: root.querySelector('#mfa-disable'),
    error: root.querySelector('#mfa-error'),
  };
  result.addForm.addEventListener('submit', addPasskey);
  result.regenerate.addEventListener('click', regenerateRecoveryCodes);
  result.disable.addEventListener('click', disableMfa);
  return result;
}

function render(nextStatus) {
  status = nextStatus;
  const enabled = Boolean(status?.enabled);
  const available = Boolean(status?.available);
  card.summary.textContent = enabled
    ? 'Multi-factor authentication is enabled. Your password is followed by a registered passkey or one-time recovery code.'
    : 'Add a passkey to require a second factor after your password.';
  card.policy.textContent = status?.required_by_admin_policy
    ? 'This account has an administrative role and installation policy requires MFA.'
    : '';
  card.browser.textContent = available && !webAuthnSupported()
    ? 'This browser or context cannot create passkeys. Recovery codes can still be used after enrollment from another supported browser.'
    : !available
      ? 'The operator has not configured a WebAuthn relying-party ID and origin for this installation.'
      : '';
  card.addForm.classList.toggle('hidden', !available || !webAuthnSupported());
  card.regenerate.classList.toggle('hidden', !enabled);
  card.disable.classList.toggle('hidden', !enabled);
  card.disable.disabled = Boolean(status?.required_by_admin_policy);
  card.recoveryStatus.textContent = enabled
    ? `${status.recovery_codes_remaining} unused recovery codes remain.`
    : '';
  renderCredentials(Array.isArray(status?.credentials) ? status.credentials : []);
}

function renderCredentials(credentials) {
  card.credentials.replaceChildren();
  if (credentials.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'account-muted';
    empty.textContent = 'No passkeys are registered.';
    card.credentials.append(empty);
    return;
  }
  for (const credential of credentials) {
    const article = document.createElement('article');
    article.className = 'account-credential';
    const heading = document.createElement('strong');
    heading.textContent = credential.label;
    const meta = document.createElement('p');
    meta.className = 'account-muted';
    const lastUsed = credential.last_used_at ? `Last used ${formatDate(credential.last_used_at)}` : 'Not used yet';
    const backup = credential.backup_eligible
      ? credential.backup_state ? 'synced passkey' : 'backup eligible'
      : 'device-bound or unknown backup capability';
    meta.textContent = `${credential.algorithm} · added ${formatDate(credential.created_at)} · ${lastUsed} · ${backup}`;
    const actions = document.createElement('div');
    actions.className = 'account-action-row';
    const rename = document.createElement('button');
    rename.type = 'button';
    rename.className = 'secondary-button';
    rename.textContent = 'Rename';
    rename.addEventListener('click', () => renamePasskey(credential));
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'danger-button';
    remove.textContent = 'Remove';
    remove.disabled = credentials.length <= 1;
    remove.title = remove.disabled ? 'Disable MFA to remove the final passkey.' : '';
    remove.addEventListener('click', () => removePasskey(credential));
    actions.append(rename, remove);
    article.append(heading, meta, actions);
    card.credentials.append(article);
  }
}

async function addPasskey(event) {
  event.preventDefault();
  card.error.textContent = '';
  setBusy(true);
  try {
    const options = await apiPost('/api/v1/account/mfa/register-options.php');
    const credential = await createPasskey(options.public_key);
    const response = await apiPost('/api/v1/account/mfa/register-finish.php', {
      label: card.label.value,
      credential,
    });
    card.addForm.reset();
    card.label.value = 'My passkey';
    render(response.mfa);
    if (Array.isArray(response.recovery_codes) && response.recovery_codes.length > 0) {
      showRecoveryCodes(response.recovery_codes);
    }
  } catch (error) {
    card.error.textContent = message(error);
  } finally {
    setBusy(false);
  }
}

async function renamePasskey(credential) {
  const label = window.prompt('New passkey label:', credential.label);
  if (label === null || label.trim() === '' || label.trim() === credential.label) return;
  card.error.textContent = '';
  setBusy(true);
  try {
    const response = await apiPost('/api/v1/account/mfa/rename.php', {
      credential_id: credential.id,
      label: label.trim(),
    });
    render(response.mfa);
  } catch (error) {
    card.error.textContent = message(error);
  } finally {
    setBusy(false);
  }
}

async function removePasskey(credential) {
  if (!window.confirm(`Remove the passkey “${credential.label}”?`)) return;
  card.error.textContent = '';
  setBusy(true);
  try {
    const response = await apiPost('/api/v1/account/mfa/remove.php', {
      credential_id: credential.id,
    });
    render(response.mfa);
  } catch (error) {
    card.error.textContent = message(error);
  } finally {
    setBusy(false);
  }
}

async function regenerateRecoveryCodes() {
  if (!window.confirm('Generate a new recovery-code set? Every existing unused recovery code will stop working.')) return;
  card.error.textContent = '';
  setBusy(true);
  try {
    const response = await apiPost('/api/v1/account/mfa/recovery-regenerate.php');
    render(response.mfa);
    showRecoveryCodes(response.recovery_codes);
  } catch (error) {
    card.error.textContent = message(error);
  } finally {
    setBusy(false);
  }
}

async function disableMfa() {
  if (!window.confirm('Disable multi-factor authentication and invalidate every other session?')) return;
  card.error.textContent = '';
  setBusy(true);
  try {
    await apiPost('/api/v1/account/mfa/disable.php');
    const response = await apiGet('/api/v1/account/mfa/status.php');
    render(response.mfa);
  } catch (error) {
    card.error.textContent = message(error);
  } finally {
    setBusy(false);
  }
}

function showRecoveryCodes(codes) {
  const dialog = document.createElement('dialog');
  dialog.className = 'step-up-dialog';
  dialog.setAttribute('aria-labelledby', 'recovery-code-title');
  const wrap = document.createElement('div');
  wrap.className = 'step-up-form';
  const title = document.createElement('h2');
  title.id = 'recovery-code-title';
  title.textContent = 'Save your recovery codes now';
  const explanation = document.createElement('p');
  explanation.textContent = 'Each code works once. ChitChat stores only hashes and cannot show this set again.';
  const pre = document.createElement('pre');
  pre.className = 'recovery-code-list';
  pre.textContent = `${codes.join('\n')}\n`;
  const actions = document.createElement('div');
  actions.className = 'account-action-row';
  const copy = document.createElement('button');
  copy.type = 'button';
  copy.className = 'secondary-button';
  copy.textContent = 'Copy codes';
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'primary-button';
  close.textContent = 'I saved them';
  const note = document.createElement('p');
  note.className = 'account-muted';
  copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(codes.join('\n'));
      note.textContent = 'Recovery codes copied.';
    } catch {
      note.textContent = 'Copy was unavailable. Select the codes manually.';
    }
  });
  close.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => dialog.remove());
  actions.append(copy, close);
  wrap.append(title, explanation, pre, actions, note);
  dialog.append(wrap);
  document.body.append(dialog);
  dialog.showModal();
  close.focus();
}

function setBusy(busy) {
  for (const control of card.root.querySelectorAll('button, input')) control.disabled = busy;
  if (!busy && status) render(status);
}

function formatDate(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function message(error) {
  if (error?.name === 'NotAllowedError') return 'The passkey operation was cancelled or timed out.';
  return error instanceof Error ? error.message : 'The multi-factor authentication request failed.';
}
