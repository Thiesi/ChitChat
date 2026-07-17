import { apiGet } from './api.js';

window.addEventListener('DOMContentLoaded', async () => {
  const link = document.getElementById('dm-inspection-link');
  if (!link) return;

  try {
    const session = await apiGet('/api/v1/session.php');
    const policy = session.privacy?.direct_messages;
    const roles = Array.isArray(session.user?.roles) ? session.user.roles : [];
    const allowed = Boolean(policy?.admin_inspection_enabled) && (
      policy.admin_inspection_role === 'super_admin'
        ? roles.includes('super_admin')
        : roles.some((role) => ['super_admin', 'admin'].includes(role))
    );
    link.classList.toggle('hidden', !allowed);
  } catch {
    link.classList.add('hidden');
  }
});
