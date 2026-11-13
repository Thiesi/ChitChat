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

/** The sentence for an error: ours for the "not available" codes, else the server's, else `fallback`. */
export function errorText(error, fallback) {
  const ours = BY_CODE[error?.code];
  if (ours) return ours;
  return error instanceof Error && error.message ? error.message : fallback;
}

/** Says `text` once in the page's toast region, for pages whose own toast isn't reachable here. */
export function showNotice(text) {
  const region = document.getElementById('toast-region');
  if (!region) return;
  const note = document.createElement('div');
  note.className = 'toast error';
  note.setAttribute('role', 'alert');
  note.textContent = text;
  region.append(note);
  window.setTimeout(() => note.remove(), 6000);
}
