// Files that are gone (#334, after #326): the server answers a deleted or hidden
// attachment's download with 404 (attachment_not_found) or 410 (deleted), which a
// plain link or <img> can't read. One request tells the page, so a preview
// says the file isn't available instead of a broken image, and a download link
// says so instead of opening the server's JSON error in the tab.
import { FILE_UNAVAILABLE, showNotice } from './unavailable.js';

/** Whether the file at this same-site download address is gone (404 or 410). */
export async function fileGone(url) {
  // A HEAD (#357): the download endpoints answer it with exactly GET's status and no body,
  // so nothing is streamed and nothing reads as a download. Only the status counts.
  try {
    const response = await fetch(url, { method: 'HEAD', credentials: 'same-origin', cache: 'no-store' });
    return response.status === 404 || response.status === 410;
  } catch {
    // No answer at all (offline, say): not proof that it's gone.
    return false;
  }
}

let installed = false;

/** A plain click on any attachment download link checks first; modified clicks are left alone. */
export function checkDownloadsBeforeOpening() {
  if (installed) return;
  installed = true;
  document.addEventListener('click', async (event) => {
    const link = event.target instanceof Element ? event.target.closest('a.attachment-download') : null;
    if (!(link instanceof HTMLAnchorElement) || event.defaultPrevented) return;
    // The second, checked click goes through as an ordinary link.
    if (link.dataset.checked === 'true') {
      delete link.dataset.checked;
      return;
    }
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (await fileGone(link.href)) {
      showNotice(FILE_UNAVAILABLE);
      return;
    }
    // There: let the link do what it always did (the server sends a download, so the
    // page stays where it is).
    link.dataset.checked = 'true';
    link.click();
  });
}
