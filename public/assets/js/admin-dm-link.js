import { apiGet } from './api.js';

window.addEventListener('DOMContentLoaded', async () => {
  const inspectionLink = document.getElementById('dm-inspection-link');
  const revisionLink = document.getElementById('revision-review-link');
  const settingsLink = document.getElementById('system-settings-link');
  if (!inspectionLink && !revisionLink && !settingsLink) return;

  try {
    const session = await apiGet('/api/v1/session.php');
    const inspectionPolicy = session.privacy?.direct_messages;
    const revisionPolicy = session.privacy?.message_revisions;
    const roles = Array.isArray(session.user?.roles) ? session.user.roles : [];
    const mayInspect = Boolean(inspectionPolicy?.admin_inspection_enabled) && mayUseRole(
      roles,
      inspectionPolicy.admin_inspection_role,
    );
    const mayReview = Boolean(revisionPolicy?.admin_review_enabled) && mayUseRole(
      roles,
      revisionPolicy.admin_review_role,
    );
    inspectionLink?.classList.toggle('hidden', !mayInspect);
    revisionLink?.classList.toggle('hidden', !mayReview);
    settingsLink?.classList.toggle('hidden', !roles.includes('super_admin'));
  } catch {
    inspectionLink?.classList.add('hidden');
    revisionLink?.classList.add('hidden');
    settingsLink?.classList.add('hidden');
  }
});

function mayUseRole(roles, role) {
  return role === 'super_admin'
    ? roles.includes('super_admin')
    : roles.some((candidate) => ['super_admin', 'admin'].includes(candidate));
}
