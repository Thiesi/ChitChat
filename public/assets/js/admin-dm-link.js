import { apiGet } from './api.js';

window.addEventListener('DOMContentLoaded', async () => {
  const inspectionLink = document.getElementById('dm-inspection-link');
  const settingsLink = document.getElementById('system-settings-link');
  if (!inspectionLink && !settingsLink) return;

  try {
    const session = await apiGet('/api/v1/session.php');
    const policy = session.privacy?.direct_messages;
    const roles = Array.isArray(session.user?.roles) ? session.user.roles : [];
    const mayInspect = Boolean(policy?.admin_inspection_enabled) && (
      policy.admin_inspection_role === 'super_admin'
        ? roles.includes('super_admin')
        : roles.some((role) => ['super_admin', 'admin'].includes(role))
    );
    inspectionLink?.classList.toggle('hidden', !mayInspect);
    settingsLink?.classList.toggle('hidden', !roles.includes('super_admin'));
  } catch {
    inspectionLink?.classList.add('hidden');
    settingsLink?.classList.add('hidden');
  }
});
