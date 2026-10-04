// Decorative initials shared by messages, members, conversations, and the
// account menu, so a person keeps the same look everywhere.

export function initials(name) {
  return Array.from(name ?? 'System').slice(0, 2).join('').toUpperCase();
}

/** One of four colour tones, stable for an account ID (or a name when no ID is known). */
export function avatarTone(identity) {
  return Array.from(String(identity ?? 'System'))
    .reduce((value, character) => value + character.codePointAt(0), 0) % 4;
}

/** A small round avatar for lists; `status` is 'online', 'idle', or null. */
export function miniAvatar(name, identity, status = null) {
  const avatar = document.createElement('span');
  avatar.className = 'mini-avatar';
  if (status === 'online' || status === 'idle') {
    avatar.classList.add('online');
    if (status === 'idle') avatar.classList.add('idle');
  }
  avatar.setAttribute('aria-hidden', 'true');
  avatar.dataset.tone = String(avatarTone(identity));
  avatar.textContent = initials(name);
  return avatar;
}
