import './attachments.js';
import { apiGet } from './api.js';

window.addEventListener('DOMContentLoaded', () => {
  const link = document.getElementById('admin-link');
  const shell = document.getElementById('chat-shell');
  const currentUser = document.getElementById('current-user');
  const registerTab = document.getElementById('register-tab');
  const registerForm = document.getElementById('register-form');
  const loginTab = document.getElementById('login-tab');
  if (!link || !shell || !currentUser) return;

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

      if (!session.user) {
        link.classList.add('hidden');
        return;
      }
      const roles = Array.isArray(session.user.roles) ? session.user.roles : [];
      let allowed = roles.some((role) => ['super_admin', 'admin', 'chat_admin'].includes(role));
      if (!allowed) {
        const response = await apiGet('/api/v1/rooms/list.php');
        const rooms = Array.isArray(response.rooms) ? response.rooms : [];
        allowed = rooms.some((room) => room.member_role === 'owner');
      }
      link.classList.toggle('hidden', !allowed);
    } catch {
      link.classList.add('hidden');
    } finally {
      refreshRunning = false;
      if (refreshRequested) schedule();
    }
  };

  new MutationObserver(schedule).observe(shell, { attributes: true, attributeFilter: ['class'] });
  new MutationObserver(schedule).observe(currentUser, { childList: true, subtree: true });
  schedule();
});
