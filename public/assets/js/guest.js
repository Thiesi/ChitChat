// Guests look around without an account. A guest is named "Guest NNNN";
// usernames can never contain a space, so the name alone tells a guest
// apart wherever it appears (see docs/api/guest-access.md).

const GUEST_NAME = /^Guest \d{4,}$/;
const PERSON = ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z'];

/** Where every "Create an account" leads: the guest visit ends and registration opens. */
export const REGISTER_URL = '/?register=1';

export function isGuestName(name) {
  return typeof name === 'string' && GUEST_NAME.test(name);
}

/** The "Guest" pill shown after a guest's name. */
export function guestBadge(className = '') {
  const badge = document.createElement('span');
  badge.className = className ? `guest-badge ${className}` : 'guest-badge';
  badge.textContent = 'Guest';
  return badge;
}

/**
 * Turns an avatar into the dashed person outline guests wear; guests have no
 * pictures. Without `outline`, only the person goes in (when the element
 * around it carries the outline).
 */
export function markGuestAvatar(avatar, { outline = true } = {}) {
  if (outline) avatar.classList.add('guest-avatar');
  delete avatar.dataset.avatarUser;
  const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  icon.setAttribute('viewBox', '0 0 24 24');
  icon.setAttribute('aria-hidden', 'true');
  icon.setAttribute('focusable', 'false');
  for (const [key, value] of Object.entries({ fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })) {
    icon.setAttribute(key, value);
  }
  for (const d of PERSON) {
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', d);
    icon.append(path);
  }
  avatar.replaceChildren(icon);
  return avatar;
}
