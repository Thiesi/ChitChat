// The event stream for a page, kept open (#301). Shared by the chat and Direct pages so the
// two can't drift. No DOM of its own: the browser parts come in through `env`, so node tests
// it directly.
//
// EventSource retries a dropped connection itself, but closes for good when a reconnect gets
// a non-200 or non-SSE answer (the 503 every site serves during an upgrade, say). Then this
// checks the session and opens a new EventSource after a backoff, from the last position as
// after_id (a new EventSource sends no Last-Event-ID), keeping resume=settled and the same
// SeenEvents, so nothing is missed or handled twice.
//
// - Only a 401 (or a session without a user) from the check ends it, through onSignedOut.
//   A failed check (a 503 during a drain, a network error) keeps backing off.
// - stop() (leaving, signing out) and forced_logout end it for good. The server's own
//   `event: error` frames end that stream; the check then decides, like any closed stream.
// - A hidden tab checks at most every 30 s; becoming visible or going online retries now.
// - onLongOutage runs once per outage, when the wait reaches its 30 s cap.
// - Into the back/forward cache (pagehide with persisted) the stream is closed on purpose; on a
//   restore (pageshow with persisted) the status says "Reconnecting" at once, then the session
//   is checked and the stream reopened from its position (#323). Real restores can't be observed
//   in our test automation, which runs with the cache off, so this is defensive by design.

import { SeenEvents, dropResentEvents } from './realtime-seen.js';

const STREAM_URL = '/api/v1/events/stream.php';

/** Said once, politely, when an outage reaches the longest wait. */
export const LONG_OUTAGE_NOTICE = 'Still reconnecting. New messages will appear once the connection is back.';

/** A probe for keepEventStream from the page's apiGet: a 401 or no user means signed out. */
export function sessionProbe(get) {
  return async () => {
    try {
      const session = await get('/api/v1/session.php');
      return session?.user ? 'ok' : 'signed_out';
    } catch (error) {
      return error?.status === 401 ? 'signed_out' : 'unavailable';
    }
  };
}
const DELAYS = [1000, 2000, 5000, 10000, 20000, 30000];
export const LONGEST_DELAY = 30000;

/** The wait before reconnect attempt `attempt` (0-based): 1, 2, 5, 10, 20, then 30 s, ±20 %. */
export function reconnectDelay(attempt, random = Math.random) {
  const base = DELAYS[Math.min(Math.max(attempt, 0), DELAYS.length - 1)];
  return Math.round(base * (0.8 + 0.4 * random()));
}

/** The stream URL: settled resume when the server offers it, from `position` when known. */
export function streamUrl(settled, position) {
  const parameters = new URLSearchParams();
  if (settled) parameters.set('resume', 'settled');
  if (Number.isInteger(position) && position >= 0) parameters.set('after_id', String(position));
  const query = parameters.toString();
  return query === '' ? STREAM_URL : `${STREAM_URL}?${query}`;
}

const CLOSED = 2;

/**
 * Opens the stream and keeps it open until stop().
 *
 * @param {object} options
 * @param {boolean} options.settled whether the server offers resume=settled
 * @param {(source: EventSource) => void} options.attach adds the page's listeners to each new source
 * @param {() => Promise<'ok'|'signed_out'|'unavailable'>} options.probe checks the session before a new source
 * @param {(status: 'connecting'|'connected'|'reconnecting') => void} [options.onStatus]
 * @param {() => void} [options.onSignedOut]
 * @param {() => void} [options.onLongOutage]
 * @param {object} [options.env] browser parts, for tests
 */
export function keepEventStream({ settled, attach, probe, onStatus = () => {}, onSignedOut = () => {}, onLongOutage = () => {}, env = browserEnv() }) {
  const seen = new SeenEvents();
  let source = null;
  let timer = null;
  let attempt = 0;
  let announced = false;
  let stopped = false;
  let checking = false;
  // In the back/forward cache (#323): closed on purpose, reopened on a restore.
  let parked = false;

  function open() {
    if (stopped || parked) return;
    const current = new env.EventSource(streamUrl(settled, seen.position), { withCredentials: true });
    source = current;
    dropResentEvents(current, seen);
    current.addEventListener('open', () => {
      if (current !== source || stopped) return;
      attempt = 0;
      announced = false;
      onStatus('connected');
    });
    current.addEventListener('error', (event) => {
      if (current !== source || stopped) return;
      // A server `event: error` frame (it has data) ends that stream: treat it as closed.
      if (event?.data !== undefined) current.close();
      if (current.readyState === CLOSED) {
        schedule();
      } else {
        // EventSource is retrying by itself.
        onStatus('reconnecting');
      }
    });
    current.addEventListener('forced_logout', () => stop());
    attach(current);
  }

  function schedule() {
    if (stopped || parked || timer !== null || checking) return;
    onStatus('reconnecting');
    let delay = reconnectDelay(attempt, env.random);
    if (env.isHidden()) delay = Math.max(delay, LONGEST_DELAY);
    if (attempt >= DELAYS.length - 1 && !announced) {
      announced = true;
      onLongOutage();
    }
    attempt += 1;
    timer = env.setTimeout(retry, delay);
  }

  async function retry() {
    timer = null;
    if (stopped) return;
    checking = true;
    let result;
    try {
      result = await probe();
    } catch {
      result = 'unavailable';
    }
    checking = false;
    if (stopped || parked) return;
    if (result === 'signed_out') {
      stop();
      onSignedOut();
    } else if (result === 'ok') {
      open();
    } else {
      schedule();
    }
  }

  function retryNow() {
    if (stopped || timer === null) return;
    env.clearTimeout(timer);
    timer = null;
    retry();
  }

  // Into the back/forward cache: close the stream on purpose, so a restored page never sits on a
  // closed or stale one, and an open stream doesn't keep the page out of the cache (#323).
  function park() {
    if (stopped || parked) return;
    parked = true;
    if (timer !== null) env.clearTimeout(timer);
    timer = null;
    const current = source;
    source = null;
    current?.close();
  }

  // Back from the cache: say so at once, never a stale "Live", then check the session and reopen
  // from the stored position with the same SeenEvents, as after any closure.
  function resume() {
    if (stopped || !parked) return;
    parked = false;
    attempt = 0;
    announced = false;
    onStatus('reconnecting');
    retry();
  }

  function stop() {
    if (stopped) return;
    stopped = true;
    if (timer !== null) env.clearTimeout(timer);
    timer = null;
    source?.close();
    env.removeWakeListeners(retryNow);
    env.removePageListeners?.();
  }

  env.addWakeListeners(retryNow);
  env.addPageListeners?.(park, resume);
  onStatus('connecting');
  open();

  return {
    stop,
    get source() { return source; },
    get stopped() { return stopped; },
    seen,
  };
}

function browserEnv() {
  const onVisible = (callback) => () => {
    if (document.visibilityState === 'visible') callback();
  };
  const wake = new Map();
  let page = null;
  return {
    EventSource: window.EventSource,
    setTimeout: (callback, delay) => window.setTimeout(callback, delay),
    clearTimeout: (id) => window.clearTimeout(id),
    random: Math.random,
    isHidden: () => document.hidden === true,
    addWakeListeners(callback) {
      const visible = onVisible(callback);
      wake.set(callback, visible);
      document.addEventListener('visibilitychange', visible);
      window.addEventListener('online', callback);
    },
    removeWakeListeners(callback) {
      document.removeEventListener('visibilitychange', wake.get(callback));
      window.removeEventListener('online', callback);
      wake.delete(callback);
    },
    // Only the back/forward cache: an ordinary unload closes the stream anyway.
    addPageListeners(onHide, onShow) {
      page = {
        hide: (event) => { if (event.persisted) onHide(); },
        show: (event) => { if (event.persisted) onShow(); },
      };
      window.addEventListener('pagehide', page.hide);
      window.addEventListener('pageshow', page.show);
    },
    removePageListeners() {
      if (page === null) return;
      window.removeEventListener('pagehide', page.hide);
      window.removeEventListener('pageshow', page.show);
      page = null;
    },
  };
}
