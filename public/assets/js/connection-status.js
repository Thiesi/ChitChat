/*
 * The connection status line (S7, #173; Direct since #309), on CCM's status
 * ladder: Live is quiet; "Reconnecting…" only after the stream has been down
 * for three seconds, so a quick restore never flickers; "Offline" when the
 * browser itself has no network, which outranks the stream's own state.
 *
 * It isn't a live region: the announcer already says a long outage once
 * (realtime-stream.js, #308), and reading every change aloud was noise.
 * No DOM access at import time, so the logic can be unit-tested.
 */

export const RECONNECTING_DELAY_MS = 3000;

export const LABELS = {
  connecting: 'Connecting…',
  connected: 'Live',
  reconnecting: 'Reconnecting…',
  offline: 'Offline. You can’t send until you’re back.',
  disconnected: 'Not connected',
};

/**
 * @param {{ dataset: Record<string, string>, textContent: string } | null} element
 * @param {{ delay?: number, online?: () => boolean, setTimer?: Function, clearTimer?: Function, listen?: (type: string, handler: () => void) => void }} [options]
 */
export function createConnectionStatus(element, {
  delay = RECONNECTING_DELAY_MS,
  online = () => globalThis.navigator?.onLine !== false,
  setTimer = (handler, ms) => globalThis.setTimeout(handler, ms),
  clearTimer = (id) => globalThis.clearTimeout(id),
  listen = (type, handler) => globalThis.addEventListener?.(type, handler),
} = {}) {
  // What the stream says, and what the line shows for it right now.
  let stream = 'connecting';
  let shown = 'connecting';
  let timer = null;

  const paint = () => {
    const state = online() ? shown : 'offline';
    if (!element || (element.dataset.state === state && element.textContent === LABELS[state])) return;
    element.dataset.state = state;
    element.textContent = LABELS[state];
  };

  const set = (status) => {
    stream = status;
    if (status === 'reconnecting') {
      // Keep showing what it was (usually Live) for a moment; most drops come straight back.
      if (shown !== 'reconnecting' && timer === null) {
        timer = setTimer(() => {
          timer = null;
          if (stream === 'reconnecting') {
            shown = 'reconnecting';
            paint();
          }
        }, delay);
      }
      return;
    }
    if (timer !== null) {
      clearTimer(timer);
      timer = null;
    }
    shown = status;
    paint();
  };

  listen('online', paint);
  listen('offline', paint);
  paint();
  return { set, get state() { return element?.dataset.state ?? null; } };
}
