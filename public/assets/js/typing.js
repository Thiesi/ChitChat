/*
 * "Alex is typing…". The browser signals at most every few seconds while
 * someone types, each signal fades after a few seconds, and arriving
 * messages clear their sender at once. Nothing typed is ever sent along.
 */

/** How long one signal keeps a name on screen; the server lets it expire after 8 s. */
const SHOW_MS = 6_500;
/** How often a typing person signals; the server drops anything closer than 3 s. */
const SIGNAL_EVERY_MS = 4_000;

/**
 * Shows who is typing in `element`, one short line.
 *
 * @param {HTMLElement} element
 */
export function createTypingIndicator(element) {
  const typers = new Map();
  let timer = null;

  const render = () => {
    const now = Date.now();
    for (const [id, typer] of typers) {
      if (typer.until <= now) typers.delete(id);
    }
    const names = [...typers.values()].map((typer) => typer.username).sort((a, b) => a.localeCompare(b));
    element.textContent = names.length === 0 ? ''
      : names.length === 1 ? `${names[0]} is typing…`
        : names.length === 2 ? `${names[0]} and ${names[1]} are typing…`
          : 'Several people are typing…';
    element.classList.toggle('active', names.length > 0);
    if (names.length === 0 && timer !== null) {
      window.clearInterval(timer);
      timer = null;
    } else if (names.length > 0 && timer === null) {
      timer = window.setInterval(render, 1_000);
    }
  };

  return {
    /** Someone signalled that they are typing. */
    add(user) {
      if (!user || !Number.isInteger(user.id)) return;
      typers.set(user.id, { username: user.username ?? 'Someone', until: Date.now() + SHOW_MS });
      render();
    },
    /** Their message arrived, so they are done. */
    remove(userId) {
      if (typers.delete(userId)) render();
    },
    clear() {
      typers.clear();
      render();
    },
  };
}

/**
 * Calls `send` while someone types, at most every few seconds. Commands
 * ("/dm …") and an empty composer send nothing.
 *
 * @param {() => Promise<unknown>} send
 */
export function createTypingSignal(send) {
  let last = 0;
  return {
    input(value) {
      const text = String(value).trim();
      if (text === '' || text.startsWith('/')) return;
      const now = Date.now();
      if (now - last < SIGNAL_EVERY_MS) return;
      last = now;
      send().catch(() => {
        // A missed signal only means the indicator shows a moment later.
      });
    },
    /** After sending a message the next keystroke may signal again at once. */
    reset() {
      last = 0;
    },
  };
}
