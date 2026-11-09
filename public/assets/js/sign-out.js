// Signing out (or leaving a guest visit) from You (S2b, #168). The session
// ends on the server, then the Rooms page opens on its sign-in screen and
// shows one line saying what happened.
import { ApiError, apiPost } from './api.js';

const SIGNED_OUT_KEY = 'chitchat-signed-out';

export async function signOut({ guest = false } = {}) {
  try {
    await apiPost('/api/v1/logout.php');
  } catch (error) {
    // Already signed out (401) is fine; anything else still leaves this page.
    if (!(error instanceof ApiError && error.status === 401)) console.error(error);
  }
  try {
    window.sessionStorage.setItem(
      SIGNED_OUT_KEY,
      guest ? 'Thanks for visiting. Your guest visit has ended.' : 'You have been signed out.',
    );
  } catch {
    // Without storage the sign-in screen simply shows no message.
  }
  window.location.assign('/');
}

/** The line left by signOut(), once. */
export function takeSignedOutMessage() {
  try {
    const message = window.sessionStorage.getItem(SIGNED_OUT_KEY);
    window.sessionStorage.removeItem(SIGNED_OUT_KEY);
    return message;
  } catch {
    return null;
  }
}
