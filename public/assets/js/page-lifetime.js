// The page's lifetime as one AbortSignal (#270). Leaving the page (pagehide) aborts every
// request still in flight, so WebKit never reports a navigation-cancelled fetch as an error.
// A back/forward-cache restore (pageshow with persisted) starts a fresh signal, so requests
// after going back work again. No DOM of its own: node tests it with a fake target.
//
// Requests sent while leaving on purpose (none today) must use `keepalive: true`; api.js then
// doesn't attach this signal.

export function createPageLifetime(target) {
  let controller = new AbortController();
  let leaving = false;

  target.addEventListener('pagehide', () => {
    leaving = true;
    controller.abort(new DOMException('The page was left.', 'AbortError'));
  });
  target.addEventListener('pageshow', (event) => {
    if (!event?.persisted) return;
    controller = new AbortController();
    leaving = false;
  });

  return {
    /** The current page's signal; aborted once the page is left. */
    signal: () => controller.signal,
    /** Whether the page has been left (and not restored since). */
    leaving: () => leaving,
  };
}

let page = null;

/** The shared lifetime of this page (created on first use, in a browser). */
export function pageLifetime() {
  page ??= createPageLifetime(window);
  return page;
}
