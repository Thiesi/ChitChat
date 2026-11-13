// What to say when something is gone, or can't be seen (#326). The server
// answers a private room you can't see exactly like a missing one (404
// room_not_found), and the same for its messages and files, so the web says
// the same honest thing for both, in the words CCM uses. No DOM at import
// time, so node can test it.

export const ROOM_UNAVAILABLE = 'This room isn’t available any more.';
export const MESSAGE_UNAVAILABLE = 'That message isn’t available any more.';
export const FILE_UNAVAILABLE = 'This file isn’t available any more.';

const BY_CODE = {
  room_not_found: ROOM_UNAVAILABLE,
  message_not_found: MESSAGE_UNAVAILABLE,
  attachment_not_found: FILE_UNAVAILABLE,
};

/**
 * The sentence for an error: ours for the "not available" codes, else the server's, else
 * `fallback`. A request cut short by leaving the page (#270, code page_left) says nothing.
 */
export const OFFLINE_REASON = 'You’re offline.';

export function errorText(error, fallback) {
  if (error?.code === 'page_left') return '';
  // No answer at all while the browser has no network (S7 ladder): say that, not "Failed to fetch".
  if (!(error?.status > 0) && globalThis.navigator?.onLine === false) return OFFLINE_REASON;
  const ours = BY_CODE[error?.code];
  if (ours) return ours;
  return error instanceof Error && error.message ? error.message : fallback;
}

// What happened to what you were sending, said after the reason (CCM's words). The draft or
// file stays where it was, so it can be sent again.
export const MESSAGE_NOT_SENT = 'Your message wasn’t sent.';
export const FILE_NOT_SENT = 'Your file wasn’t sent.';

/** `reason` then `then`; nothing at all when there's no reason (page_left). */
export function failedSending(reason, then) {
  return reason ? `${reason} ${then}` : '';
}

/** Says `text` once in the page's toast region, for pages whose own toast isn't reachable here. */
export function showNotice(text) {
  const region = document.getElementById('toast-region');
  if (!region || !text) return;
  const note = document.createElement('div');
  note.className = 'toast error';
  note.setAttribute('role', 'alert');
  note.textContent = text;
  region.append(note);
  window.setTimeout(() => note.remove(), 6000);
}
