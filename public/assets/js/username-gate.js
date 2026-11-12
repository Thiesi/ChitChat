// A moderator required a new username (#164): the account keeps reading,
// but writing pauses until a new name is chosen on /choose-username.php.
// Like the rules gate (rules.js), one page-wide state shows the banner and the
// composer notice ([data-username-gate]) and hides the composers. When both
// apply, the rules come first (rules.css hides this gate's notices).

import { apiGet } from './api.js';

let gated = false;

/** Starts or clears the pause from the session user (session.php, sign-in, the chooser). */
export function startUsernameGate(user) {
  const change = user?.username_change;
  setGated(change?.required === true, typeof change?.reason === 'string' ? change.reason : '');
}

export function stopUsernameGate() {
  setGated(false, '');
}

/** Whether writing is paused for a new username right now. */
export function usernameChangeRequired() {
  return gated;
}

// A write refused with 403 username_change_required (api.js): the session
// says why, and the notices appear wherever the person tried to write.
window.addEventListener('chitchat:username-change-required', () => {
  apiGet('/api/v1/session.php')
    .then((session) => startUsernameGate(session.user))
    .catch(() => setGated(true, ''));
});

function setGated(value, reason) {
  gated = value;
  document.documentElement.classList.toggle('username-gated', value);
  for (const node of document.querySelectorAll('[data-username-gate]')) node.classList.toggle('hidden', !value);
  for (const node of document.querySelectorAll('[data-username-reason]')) {
    node.textContent = reason ? `The moderator’s reason: ${reason}` : '';
    node.classList.toggle('hidden', !reason);
  }
}
