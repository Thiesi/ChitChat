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
      // Stewarding, as on the shell's rail (shell.js): administrators and room
      // owners go to administration, moderators (global or room) to the queue.
      // On phones this menu is how staff get there until You has it (S2b).
      const roles = Array.isArray(session.user.roles) ? session.user.roles : [];
      let destination = roles.some((role) => ['super_admin', 'admin', 'chat_admin'].includes(role)) ? '/admin.php' : null;
      if (!destination) {
        const response = await apiGet('/api/v1/rooms/list.php');
        const rooms = Array.isArray(response.rooms) ? response.rooms : [];
        if (rooms.some((room) => room.member_role === 'owner')) destination = '/admin.php';
        else if (roles.includes('global_moderator') || rooms.some((room) => room.member_role === 'moderator')) destination = '/moderation.php';
      }
      if (destination) link.href = destination;
      link.classList.toggle('hidden', !destination);
    } catch {
      link.classList.add('hidden');
    } finally {
      refreshRunning = false;
      if (refreshRequested) schedule();
    }
  };

  window.addEventListener('chitchat:session-changed', schedule);
  new MutationObserver(schedule).observe(shell, { attributes: true, attributeFilter: ['class'] });
  new MutationObserver(schedule).observe(currentUser, { childList: true, subtree: true });
  schedule();
});
