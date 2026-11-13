// Handing a room on (#300; the API is #273/#277): the questions, the request
// and the refusals, shared by the profile card and Administration. Who may
// hand over to whom is the server's call (can_transfer_ownership on a person,
// can_take_ownership on a room); nothing here infers it.
import { ApiError, apiPost, isPageLeft } from './api.js';
import { confirmAction } from './confirm-dialog.js';
import { withPrivilegedStepUp } from './step-up.js';

/** Asks before handing #room to someone. Resolves true to go ahead. */
export async function confirmHandOver(name, roomName) {
  const choice = await confirmAction({
    title: `Make ${name} the owner of #${roomName}?`,
    text: `${name} can then change the room’s details, members and roles. The current owner stays in the room as a moderator.`,
    confirmLabel: 'Hand over',
    announce: null,
  });
  return Boolean(choice);
}

/** Asks before an administrator takes a room over themselves. */
export async function confirmTakeOver(roomName) {
  const choice = await confirmAction({
    title: `Take ownership of #${roomName}?`,
    text: `You become its owner, and a member if you aren’t one yet; the room’s minimum age applies to you. A current owner stays in the room as a moderator.`,
    confirmLabel: 'Take ownership',
    announce: null,
  });
  return Boolean(choice);
}

/** The transfer itself, after a fresh sign-in check. Returns the room as the server now shows it. */
export async function transferOwnership(roomId, userId) {
  const response = await withPrivilegedStepUp(() => apiPost('/api/v1/rooms/transfer-ownership.php', { room_id: roomId, target_user_id: userId }));
  return response?.room ?? null;
}

/** A cancelled sign-in check is the person changing their mind: nothing to report. */
export function cancelledCheck(error) {
  return error instanceof ApiError && error.code === 'step_up_cancelled';
}

/** What a refused transfer means, in plain words; the server's text for anything else. */
export function transferError(error, name, roomName) {
  // A request the page aborted on leaving clears the line rather than showing a fallback (#270).
  if (isPageLeft(error)) return '';
  switch (error?.code) {
    case 'transfer_target_not_eligible': return `${name} can’t own this room right now (guests, closing or banned accounts can’t).`;
    case 'already_owner': return `${name} already owns #${roomName}.`;
    case 'ownership_changed': return `#${roomName} changed meanwhile. Reload and try again.`;
    case 'membership_not_found': return `${name} isn’t in #${roomName} any more.`;
    case 'forbidden': return `You can’t hand #${roomName} on.`;
    case 'birth_date_required': return `#${roomName} has a minimum age, and your account has no birth date.`;
    case 'minimum_age_not_met': return `You’re below #${roomName}’s minimum age.`;
    default: return error instanceof Error && error.message ? error.message : 'That did not work.';
  }
}
