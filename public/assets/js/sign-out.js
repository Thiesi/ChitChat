// Signing out (or leaving a guest visit) from You (S2b, #168). The session
// ends on the server, then the Rooms page opens on its sign-in screen and
// shows one line saying what happened.
import { ApiError, apiPost } from './api.js';
import { rememberSignedOutNotice } from './signed-out-notice.js';

export async function signOut({ guest = false } = {}) {
  try {
    await apiPost('/api/v1/logout.php');
  } catch (error) {
    // Already signed out (401) is fine; anything else still leaves this page.
    if (!(error instanceof ApiError && error.status === 401)) console.error(error);
  }
  rememberSignedOutNotice(guest ? 'Thanks for visiting. Your guest visit has ended.' : 'You have been signed out.');
  window.location.assign('/');
}
