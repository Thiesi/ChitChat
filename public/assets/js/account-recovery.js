// Account → Password and recovery (#155): whether a recovery key exists, and
// creating or replacing one. Creating needs privileged step-up, which apiPost
// asks for when the server requires it. The key is shown once, in a dialog.
import { apiGet } from './api.js';
import { formatDateTime } from './datetime.js';
import { confirmAction } from './moderation-tools.js';
import { buildKeyStep } from './recovery-key-step.js';
import { createRecoveryKey, readRecoveryStatus } from './recovery.js';

let status = null;
let username = '';

window.addEventListener('DOMContentLoaded', async () => {
  const row = document.getElementById('recovery-row');
  const create = document.getElementById('recovery-create');
  if (!row || !create) return;
  let session;
  try {
    session = await apiGet('/api/v1/session.php');
  } catch {
    return;
  }
  if (!session.user || session.user.guest) return;
  username = session.user.username;
  status = readRecoveryStatus(session.user);
  render();
  create.addEventListener('click', () => void startCreation());

  const parameters = new URLSearchParams(window.location.search);
  if (parameters.get('recovery') === 'create') {
    parameters.delete('recovery');
    const query = parameters.toString();
    window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
    row.scrollIntoView({ block: 'center' });
    if (status && !status.exists) void startCreation();
  }
});

// Setting a first password turns a provider-only account into one that can recover.
window.addEventListener('chitchat:password-set', () => {
  if (status === null) {
    status = { exists: false, createdAt: null };
    render();
  }
});

function render() {
  const row = document.getElementById('recovery-row');
  const line = document.getElementById('recovery-row-status');
  const create = document.getElementById('recovery-create');
  row.classList.remove('hidden');
  if (status === null) {
    line.textContent = 'You sign in with Google or Twitch. There’s no password to forget.';
    create.classList.add('hidden');
    return;
  }
  create.classList.remove('hidden');
  if (status.exists) {
    line.textContent = status.createdAt
      ? `Recovery key · created ${formatDateTime(status.createdAt, { dateStyle: 'long', timeStyle: null })}`
      : 'You have a recovery key.';
    create.textContent = 'Create a new key';
    create.className = 'secondary-button';
  } else {
    line.textContent = 'No recovery key. If you forget your password, only an administrator can reset it.';
    create.textContent = 'Create recovery key';
    create.className = 'primary-button';
  }
}

async function startCreation() {
  const create = document.getElementById('recovery-create');
  if (status?.exists) {
    const confirmed = await confirmAction({
      title: 'Create a new recovery key?',
      text: 'Your current key stops working right away.',
      confirmLabel: 'Create new key',
      announce: null,
    });
    if (!confirmed) return;
  }
  create.disabled = true;
  const line = document.getElementById('recovery-row-status');
  try {
    const result = await createRecoveryKey();
    if (!result.key) throw new Error('The server did not return a key. Try again.');
    status = { exists: true, createdAt: result.createdAt ?? new Date().toISOString() };
    showKeyDialog(result.key);
  } catch (error) {
    line.textContent = error instanceof Error ? error.message : 'The key could not be created. Try again.';
  } finally {
    create.disabled = false;
  }
}

function showKeyDialog(key) {
  const dialog = document.createElement('dialog');
  dialog.className = 'room-dialog recovery-dialog';
  const appName = document.querySelector('meta[name="application-name"]')?.content || 'ChitChat';
  const step = buildKeyStep({
    eyebrow: 'Recovery key',
    title: 'Save your recovery key',
    intro: 'If you ever forget your password, this key lets you set a new one yourself. We can show it only this once.',
    key,
    username,
    appName,
    continueLabel: 'Done',
    onContinue: () => dialog.close(),
  });
  dialog.setAttribute('aria-labelledby', step.root.getAttribute('aria-labelledby'));
  // Closing without the tick would lose the key; Escape is ignored until it's saved.
  dialog.addEventListener('cancel', (event) => event.preventDefault());
  dialog.addEventListener('close', () => {
    dialog.remove();
    render();
    document.getElementById('recovery-create')?.focus();
  });
  dialog.append(step.root);
  document.body.append(dialog);
  dialog.showModal();
  step.focus();
}
