// Avatars shared by messages, members, conversations, the account menu and
// profile cards, so a person looks the same everywhere: their profile
// picture where they have one, otherwise decorative initials in a colour
// tone that is stable for their account.

// Accounts known to have no picture on this page, so they are asked for once.
const withoutPhoto = new Set();

export function initials(name) {
  return Array.from(name ?? 'System').slice(0, 2).join('').toUpperCase();
}

/** One of four colour tones, stable for an account ID (or a name when no ID is known). */
export function avatarTone(identity) {
  return Array.from(String(identity ?? 'System'))
    .reduce((value, character) => value + character.codePointAt(0), 0) % 4;
}

export function avatarUrl(userId) {
  return `/api/v1/avatars/show.php?user_id=${encodeURIComponent(userId)}`;
}

/**
 * Layers the account's picture over an initials element. The initials stay
 * underneath and remain visible until the picture has loaded, and for good
 * when there is none.
 */
export function attachPhoto(element, userId) {
  if (!Number.isInteger(userId) || withoutPhoto.has(userId)) return;
  element.querySelector('img.avatar-photo')?.remove();
  const photo = document.createElement('img');
  photo.className = 'avatar-photo';
  photo.alt = '';
  photo.decoding = 'async';
  photo.loading = 'lazy';
  photo.addEventListener('load', () => element.classList.add('has-photo'));
  photo.addEventListener('error', () => {
    withoutPhoto.add(userId);
    photo.remove();
    element.classList.remove('has-photo');
  });
  photo.src = avatarUrl(userId);
  element.append(photo);
}

/** After uploading or removing a picture, look it up afresh. */
export function refreshPhoto(userId) {
  withoutPhoto.delete(userId);
  for (const element of document.querySelectorAll(`[data-avatar-user="${userId}"]`)) {
    element.classList.remove('has-photo');
    element.querySelector('img.avatar-photo')?.remove();
    attachPhoto(element, userId);
  }
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
  if (Number.isInteger(identity)) {
    avatar.dataset.avatarUser = String(identity);
    attachPhoto(avatar, identity);
  }
  return avatar;
}
