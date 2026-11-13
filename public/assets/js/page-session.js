// One session answer for the whole page. api.js already shares a session
// request while it is in flight, but a module that asks after the answer
// arrived starts a new one, which can fail on its own. On You that left the
// list showing (account.js's answer) and the sections never started (you.js's
// failed one), so a chosen section stayed hidden. Modules that decide what
// the page shows ask here instead, so they succeed or fail together.
import { apiGet, isPageLeft } from './api.js';

let pending = null;

/**
 * The page's session, fetched once. A failure stays a failure until the page is
 * reloaded, except one from leaving the page (#270): that isn't kept, so a page
 * restored from the back/forward cache asks again instead of reporting it.
 */
export function pageSession() {
  if (pending === null) {
    const request = apiGet('/api/v1/session.php');
    pending = request;
    request.catch((error) => {
      if (isPageLeft(error) && pending === request) pending = null;
    });
  }
  return pending;
}

/** Runs `again` once when the page comes back from the back/forward cache. */
export function retryOnReturn(again) {
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) again();
  }, { once: true });
}
