// A gentle, dismissible nudge for password accounts without a recovery key
// (#155). Dismissal is remembered on this device; Account keeps the
// permanent path. Never shown to guests, provider-only accounts, or during
// a maintenance lockdown.
import { readRecoveryStatus } from './recovery.js';

const DISMISSED_KEY = 'chitchat-recovery-nudge-dismissed';

export function renderRecoveryNudge(user, { lockdown = false } = {}) {
  const nudge = document.getElementById('recovery-nudge');
  if (!nudge) return;
  const status = readRecoveryStatus(user);
  const show = status !== null && !status.exists && !lockdown && !dismissedFor(user.id);
  nudge.classList.toggle('hidden', !show);
}

function dismissedFor(userId) {
  try {
    return window.localStorage.getItem(DISMISSED_KEY) === String(userId);
  } catch {
    return false;
  }
}

window.addEventListener('DOMContentLoaded', () => {
  document.getElementById('recovery-nudge-dismiss')?.addEventListener('click', (event) => {
    const nudge = event.currentTarget.closest('#recovery-nudge');
    nudge?.classList.add('hidden');
    try {
      const userId = nudge?.dataset.userId;
      if (userId) window.localStorage.setItem(DISMISSED_KEY, userId);
    } catch {
      // Without storage it returns on the next visit; Account still has the path.
    }
  });
});

/** Remembers whose nudge this is, so dismissal applies to that account only. */
export function setRecoveryNudgeUser(userId) {
  const nudge = document.getElementById('recovery-nudge');
  if (nudge) nudge.dataset.userId = String(userId);
}
