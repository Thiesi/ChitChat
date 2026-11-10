// One line for the sign-in screen, kept across the page change that ends a
// session: signing out on You, or being signed out from another device
// (#205: HTTP 401 session_revoked, or the stream's forced_logout with that
// reason). No imports, so api.js can use it without a cycle.

const KEY = 'chitchat-signed-out';

export const REVOKED_NOTICE = 'You were signed out from another device. Sign in again.';

export function rememberSignedOutNotice(message) {
  try {
    window.sessionStorage.setItem(KEY, message);
  } catch {
    // Without storage the sign-in screen simply shows no message.
  }
}

/** The stored line, once; null when there is none. */
export function takeSignedOutNotice() {
  try {
    const message = window.sessionStorage.getItem(KEY);
    window.sessionStorage.removeItem(KEY);
    return message;
  } catch {
    return null;
  }
}
