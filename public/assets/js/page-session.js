// One session answer for the whole page. api.js already shares a session
// request while it is in flight, but a module that asks after the answer
// arrived starts a new one, which can fail on its own. On You that left the
// list showing (account.js's answer) and the sections never started (you.js's
// failed one), so a chosen section stayed hidden. Modules that decide what
// the page shows ask here instead, so they succeed or fail together.
import { apiGet } from './api.js';

let pending = null;

/** The page's session, fetched once. A failure stays a failure until the page is reloaded. */
export function pageSession() {
  pending ??= apiGet('/api/v1/session.php');
  return pending;
}
