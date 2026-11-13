// Drops events a settled stream resends after a reconnect (#286). No DOM: node tests it directly.
//
// With ?resume=settled the SSE id: is a resume position, not the event's identity. A reconnect
// resumes from that position and may resend events already handled above it. Each event's own
// id is data.id. An id at or below the position is never sent again, so the set keeps only ids
// above it and forgets the rest once a dispatched event's lastEventId passes them.
//
// Bare id: lines (no event, no data) move EventSource's position but never reach JavaScript.
// That is fine: the next dispatched event's lastEventId carries the position too.
//
// This runs before the page's own handlers, alongside the announcer's `spoken` check, which
// is left as it is. Messages are also applied idempotently by message id as a backstop.
// Only addEventListener is guarded: handlers set as source.onmessage or on<type> would
// bypass it, so don't use them on a settled stream.

export const SEEN_CAP = 1000;

export class SeenEvents {
  #ids = new Set();

  constructor(cap = SEEN_CAP) {
    this.cap = cap;
  }

  /**
   * Records one dispatched event. Returns false for a resend (its id was already handled), true
   * otherwise, including events without a numeric id, which are never filtered.
   */
  accept(id, position) {
    const fresh = !Number.isInteger(id) || !this.#ids.has(id);
    if (fresh && Number.isInteger(id)) {
      this.#ids.add(id);
      // Oldest first: a Set iterates in insertion order.
      for (const old of this.#ids) {
        if (this.#ids.size <= this.cap) break;
        this.#ids.delete(old);
      }
    }
    if (Number.isInteger(position)) {
      for (const seen of [...this.#ids]) {
        if (seen <= position) this.#ids.delete(seen);
      }
    }
    return fresh;
  }

  get size() {
    return this.#ids.size;
  }
}

/** The position in an event's lastEventId, or null when it is missing or not a non-negative integer. */
export function eventPosition(lastEventId) {
  return typeof lastEventId === 'string' && /^\d+$/.test(lastEventId) ? Number(lastEventId) : null;
}

function dataId(data) {
  try {
    const id = JSON.parse(data)?.id;
    return Number.isInteger(id) ? id : null;
  } catch {
    return null;
  }
}

/**
 * Makes every event type registered on `source` pass through `seen` first. The check runs once
 * per event, ahead of the type's own listeners, and stops a resend before any of them runs
 * (including the realtime bridge's). Call it right after creating the source, before adding
 * listeners.
 */
export function dropResentEvents(source, seen = new SeenEvents()) {
  const add = source.addEventListener;
  const guarded = new Set();
  // An object listener: the realtime bridge wraps only function listeners.
  const guard = {
    handleEvent(event) {
      if (!seen.accept(dataId(event.data), eventPosition(event.lastEventId))) event.stopImmediatePropagation();
    },
  };
  source.addEventListener = function addGuardedEventListener(type, listener, options) {
    if (type !== 'open' && type !== 'error' && !guarded.has(type)) {
      guarded.add(type);
      add.call(this, type, guard);
    }
    return add.call(this, type, listener, options);
  };
  return seen;
}
