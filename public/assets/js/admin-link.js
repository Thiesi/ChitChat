// Keeps the sign-in page's Register tab in step with the server's setting.
// (The account menu's Administration link it once managed is now the
// shell's Stewarding item, in shell.js and on You.)
import './attachments.js';
import { apiGet } from './api.js';

window.addEventListener('DOMContentLoaded', () => {
  const shell = document.getElementById('chat-shell');
  const currentUser = document.getElementById('current-user');
  const registerTab = document.getElementById('register-tab');
  const registerForm = document.getElementById('register-form');
  const loginTab = document.getElementById('login-tab');
  if (!shell || !currentUser) return;

  let refreshQueued = false;
  let refreshRunning = false;
  let refreshRequested = false;

  const schedule = () => {
    if (refreshRunning) {
      refreshRequested = true;
      return;
    }
    if (refreshQueued) return;

    refreshQueued = true;
    window.setTimeout(refresh, 0);
  };

  const refresh = async () => {
    refreshQueued = false;
    refreshRunning = true;
    refreshRequested = false;

    try {
      const session = await apiGet('/api/v1/session.php');
      const registrationEnabled = session.registration_enabled !== false;
      registerTab?.classList.toggle('hidden', !registrationEnabled);
      if (!registrationEnabled) {
        registerForm?.classList.add('hidden');
        if (registerTab?.getAttribute('aria-selected') === 'true') {
          loginTab?.click();
        }
      }

    } catch {
      // The tab keeps its last state; the server still enforces the setting.
    } finally {
      refreshRunning = false;
      if (refreshRequested) schedule();
    }
  };

  window.addEventListener('chitchat:session-changed', schedule);
  // Only signing in or out (the shell shown or hidden) can change the setting's answer. The
  // shell's layout classes change on every resize across a breakpoint (panes.js rewrites
  // list-open even when it's absent), and each refetch could be cut off by leaving the page,
  // which WebKit reports as an error (#367).
  let shellHidden = shell.classList.contains('hidden');
  new MutationObserver(() => {
    const hidden = shell.classList.contains('hidden');
    if (hidden === shellHidden) return;
    shellHidden = hidden;
    schedule();
  }).observe(shell, { attributes: true, attributeFilter: ['class'] });
  new MutationObserver(schedule).observe(currentUser, { childList: true, subtree: true });
  schedule();
});
