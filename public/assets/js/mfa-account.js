import { apiGet, apiPost, setCsrfToken } from './api.js';
import { applicationName } from './application-name.js';
import { confirmAction } from './confirm-dialog.js';
import { withPrivilegedStepUp } from './step-up.js';
import { createPasskey, webAuthnSupported } from './webauthn.js';
import { formatDateTime } from './datetime.js';
import { createTotpBlock } from './totp-account.js';

let card = null;
let status = null;
// Whether the server offers authenticator apps (capabilities.totp, #204).
let totpOffered = false;

void initialize();

async function initialize() {
  const shell = document.getElementById('account-shell');
  // Sign-in and security (S5a) holds a slot for this card; older layouts put it before closure.
  const slot = document.getElementById('mfa-card-slot');
  const closure = document.querySelector('[aria-labelledby="account-closure-heading"]');
  if (!shell || (!slot && !closure)) return;
  card = buildCard();
  if (slot) slot.replaceWith(card.root);
  else closure.before(card.root);
  try {
    const session = await apiGet('/api/v1/session.php');
    setCsrfToken(session.csrf_token ?? '');
    if (!session.user) return;
    totpOffered = session.capabilities?.totp === true;
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
      <h3 id="mfa-heading">Passkeys and recovery codes</h3>
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
    <div id="mfa-totp-slot"></div>
    <div class="account-action-row">
      <button id="mfa-recovery-regenerate" class="secondary-button" type="button">Generate new recovery codes</button>
      <span id="mfa-recovery-status" class="account-muted"></span>
    </div>
    <p class="account-muted">Recovery codes stand in for your passkey. Your recovery key, under Password and recovery, resets a forgotten password. Keep them apart.</p>
    <div id="mfa-disable-row" class="account-action-row">
      <button id="mfa-disable" class="danger-button" type="button">Disable multi-factor authentication</button>
      <span class="account-muted">Signs you out everywhere else.</span>
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
    disableRow: root.querySelector('#mfa-disable-row'),
    error: root.querySelector('#mfa-error'),
  };
  // The authenticator app (#204) is its own block; it reports back new status and first recovery codes.
  result.totp = createTotpBlock({
    onStatus: (mfa) => {
      if (mfa) render(mfa);
      else void apiGet('/api/v1/account/mfa/status.php').then((response) => render(response.mfa)).catch(() => {});
    },
    onRecoveryCodes: (codes) => showRecoveryCodes(codes),
  });
  root.querySelector('#mfa-totp-slot').replaceWith(result.totp.root);
  result.addForm.addEventListener('submit', addPasskey);
  result.regenerate.addEventListener('click', regenerateRecoveryCodes);
  result.disable.addEventListener('click', disableMfa);
  return result;
}

function render(nextStatus) {
  status = nextStatus;
  const enabled = Boolean(status?.enabled);
  const available = Boolean(status?.available);
  const totpEnabled = Boolean(status?.totp?.enabled);
  card.summary.textContent = enabled
    ? `Multi-factor authentication is on. After your password, you confirm with ${totpEnabled ? 'a passkey, a code from your authenticator app' : 'a passkey'} or a recovery code.`
    : totpOffered
      ? 'Add a passkey or an authenticator app to confirm it’s you after your password.'
      : 'Add a passkey to confirm it’s you after your password.';
  card.policy.textContent = status?.required_by_admin_policy
    ? 'Your role on this server requires it.'
    : '';
  card.browser.textContent = available && !webAuthnSupported()
    ? 'This browser can’t create passkeys. You can add one from another browser.'
    : !available
      ? 'Passkeys aren’t set up on this server.'
      : '';
  card.addForm.classList.toggle('hidden', !available || !webAuthnSupported());
  card.regenerate.classList.toggle('hidden', !enabled);
  // The row's note only makes sense next to its button.
  card.disableRow.classList.toggle('hidden', !enabled);
  card.disable.disabled = Boolean(status?.required_by_admin_policy);
  card.recoveryStatus.textContent = enabled
    ? `${status.recovery_codes_remaining} unused recovery codes remain.`
    : '';
  renderCredentials(Array.isArray(status?.credentials) ? status.credentials : []);
  card.totp.render(status, totpOffered);
}

function renderCredentials(credentials) {
  card.credentials.replaceChildren();
  if (credentials.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'account-muted';
    empty.textContent = 'No passkeys yet.';
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
      ? credential.backup_state ? 'synced passkey' : 'can be synced'
      : 'may be on this device only';
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
    // With an authenticator app as well, the last passkey can go (#204).
    remove.disabled = credentials.length <= 1 && !status?.totp?.enabled;
    remove.title = remove.disabled ? 'Disable multi-factor authentication to remove your last passkey.' : '';
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
    const response = await withPrivilegedStepUp(async () => {
      const options = await apiPost('/api/v1/account/mfa/register-options.php');
      const credential = await createPasskey(options.public_key);
      return apiPost('/api/v1/account/mfa/register-finish.php', {
        label: card.label.value,
        credential,
      });
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
    const response = await withPrivilegedStepUp(() => apiPost('/api/v1/account/mfa/rename.php', {
      credential_id: credential.id,
      label: label.trim(),
    }));
    render(response.mfa);
  } catch (error) {
    card.error.textContent = message(error);
  } finally {
    setBusy(false);
  }
}

async function removePasskey(credential) {
  const confirmed = await confirmAction({
    title: `Remove the passkey “${credential.label}”?`,
    text: 'You can’t use it to sign in any more. Your other ways to confirm it’s you keep working.',
    confirmLabel: 'Remove passkey',
    announce: null,
  });
  if (!confirmed) return;
  card.error.textContent = '';
  setBusy(true);
  try {
    const response = await withPrivilegedStepUp(() => apiPost('/api/v1/account/mfa/remove.php', {
      credential_id: credential.id,
    }));
    render(response.mfa);
  } catch (error) {
    card.error.textContent = message(error);
  } finally {
    setBusy(false);
  }
}

async function regenerateRecoveryCodes() {
  const confirmed = await confirmAction({
    title: 'Generate new recovery codes?',
    text: 'Your unused codes stop working right away.',
    confirmLabel: 'Generate new codes',
    announce: null,
  });
  if (!confirmed) return;
  card.error.textContent = '';
  setBusy(true);
  try {
    const response = await withPrivilegedStepUp(() => apiPost('/api/v1/account/mfa/recovery-regenerate.php'));
    render(response.mfa);
    showRecoveryCodes(response.recovery_codes);
  } catch (error) {
    card.error.textContent = message(error);
  } finally {
    setBusy(false);
  }
}

async function disableMfa() {
  const confirmed = await confirmAction({
    title: 'Disable multi-factor authentication?',
    text: 'Your password alone signs you in again, and you’re signed out everywhere else.',
    confirmLabel: 'Disable',
    announce: null,
  });
  if (!confirmed) return;
  card.error.textContent = '';
  setBusy(true);
  try {
    await withPrivilegedStepUp(() => apiPost('/api/v1/account/mfa/disable.php'));
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
  explanation.textContent = `Each code works once. ${applicationName()} stores only hashes and cannot show this set again.`;
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
  return formatDateTime(value);
}

function message(error) {
  if (error?.name === 'NotAllowedError') return 'The passkey operation was cancelled or timed out.';
  return error instanceof Error ? error.message : 'The multi-factor authentication request failed.';
}
