// Operational settings → Authenticator apps (#204), for Super-Administrators.
// It shows where the key that unlocks members' authenticator secrets lives,
// and offers the convenience switch to keep that key inside ChitChat, with
// the plain warning that a database backup would then hold both the key and
// every secret. The server's own key always wins. No key material is ever
// shown here or sent by the server.
import { ApiError, apiGet, apiPost } from './api.js';
import { withPrivilegedStepUp } from './step-up.js';

const card = document.getElementById('totp-settings');
const mode = document.getElementById('totp-mode');
const warning = document.getElementById('totp-warning');
const toggle = document.getElementById('totp-database-key');
const note = document.getElementById('totp-switch-note');
const status = document.getElementById('totp-status');
const error = document.getElementById('totp-error');

const WARNING = 'A backup of this database would then contain both the key and every member’s authenticator secret, so anyone with a copy of a backup could make their sign-in codes. This suits small or family installations. Keeping the key on the server is safer: see the operations guide.';

if (card && mode && warning && toggle && note && status && error) {
  toggle.addEventListener('change', () => void change(toggle.checked));
  void load();
}

async function load() {
  try {
    const response = await apiGet('/api/v1/admin/settings/totp/get.php');
    render(response.totp);
    card.hidden = false;
  } catch (failure) {
    // Only Super-Administrators manage this; for everyone else the card stays away.
    if (failure instanceof ApiError && (failure.status === 403 || failure.status === 401)) return;
    card.hidden = false;
    error.textContent = failure instanceof Error ? failure.message : 'The authenticator settings couldn’t be loaded.';
  }
}

function render(totp) {
  if (!totp) return;
  warning.textContent = typeof totp.warning === 'string' && totp.warning.trim() ? totp.warning : WARNING;
  toggle.checked = totp.database_key_enabled === true;
  const references = Number.isInteger(totp.database_key_references) ? totp.database_key_references : 0;

  if (totp.mode === 'server') {
    mode.textContent = 'The key is kept on the server (recommended). Members can set up authenticator apps.';
  } else if (totp.mode === 'database') {
    mode.textContent = 'The key is kept inside ChitChat, in this database. Members can set up authenticator apps.';
  } else {
    mode.textContent = 'No key is set up, so members can’t use authenticator apps yet. Set a key on the server (recommended), or store one inside ChitChat below.';
  }

  if (totp.database_key_switch_available === false) {
    // A server key is configured: it always wins, so the switch can't be used.
    toggle.disabled = true;
    note.textContent = 'Unavailable while a key is configured on the server, which always takes precedence.';
  } else if (toggle.checked && references > 0) {
    // Turning it off is refused while secrets still depend on this key.
    toggle.disabled = true;
    note.textContent = `${references} authenticator ${references === 1 ? 'secret still uses' : 'secrets still use'} this key, so it can’t be turned off yet. Move them to a server key first (see the operations guide).`;
  } else {
    toggle.disabled = false;
    note.textContent = toggle.checked ? 'Turning this off is possible because no authenticator secret uses this key.' : '';
  }
}

async function change(enable) {
  error.textContent = '';
  status.textContent = '';
  if (enable && !window.confirm(`Store the authenticator key inside ChitChat?\n\n${warning.textContent}`)) {
    toggle.checked = false;
    return;
  }
  toggle.disabled = true;
  try {
    const response = await withPrivilegedStepUp(() => apiPost('/api/v1/admin/settings/totp/update.php', { database_key_enabled: enable }));
    render(response.totp);
    status.textContent = enable ? 'The key is now stored inside ChitChat.' : 'The key is no longer stored inside ChitChat.';
  } catch (failure) {
    toggle.checked = !enable;
    if (failure instanceof ApiError && failure.code === 'totp_key_in_use') {
      error.textContent = 'Authenticator secrets still use this key, so it can’t be turned off yet. Move them to a server key first.';
    } else if (failure instanceof ApiError && failure.code === 'totp_server_keys_configured') {
      error.textContent = 'A key is configured on the server, which always takes precedence, so this switch can’t be used.';
    } else {
      error.textContent = failure instanceof Error ? failure.message : 'The setting couldn’t be changed.';
    }
    toggle.disabled = false;
    void load();
  }
}
