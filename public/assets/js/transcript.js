// The shared transcript for rooms and direct messages (S1, #166 §2).
// The list is an <ol> of <li> items: day headings, the "New messages" marker,
// the load-older control and messages. Rendering is incremental: an item whose
// key and signature are unchanged keeps its node (and so its focus, its
// decorations from other modules and the screen reader's place); a changed
// item is rebuilt, or patched in place when only its reactions changed.
// The list itself is never a live region; announcer.js speaks for it.

import { formatDateTime } from './datetime.js';

/**
 * @typedef {object} TranscriptItem
 * @property {string} key stable identity, such as "m:41", "ping:3" or "day:2026-11-09"
 * @property {string} signature everything the node depends on, except reactions
 * @property {() => HTMLElement} build creates the item's content
 * @property {string} [reactions] signature of the reactions alone
 * @property {(node: HTMLElement) => void} [patchReactions] updates only the reactions
 * @property {string} [className] class for the <li>
 */

/**
 * Brings the list in line with the items, touching only what changed.
 * @param {HTMLOListElement} list
 * @param {TranscriptItem[]} items
 */
export function reconcile(list, items) {
  const existing = new Map();
  for (const child of [...list.children]) {
    // A second node with a key already seen is a stray: drop it too.
    if (child instanceof HTMLLIElement && child.dataset.key && !existing.has(child.dataset.key)) existing.set(child.dataset.key, child);
    else child.remove();
  }

  let cursor = list.firstElementChild;
  const placed = new Set();
  for (const item of items) {
    // One node per key: a repeated item (for example a message delivered twice) is skipped.
    if (placed.has(item.key)) continue;
    placed.add(item.key);
    let li = existing.get(item.key);
    if (li) {
      existing.delete(item.key);
      if (li.dataset.signature !== item.signature) {
        li.replaceChildren(item.build());
        li.dataset.signature = item.signature;
        li.dataset.reactions = item.reactions ?? '';
      } else if ((item.reactions ?? '') !== (li.dataset.reactions ?? '')) {
        const node = li.firstElementChild;
        if (node instanceof HTMLElement && item.patchReactions) item.patchReactions(node);
        else li.replaceChildren(item.build());
        li.dataset.reactions = item.reactions ?? '';
      }
    } else {
      li = document.createElement('li');
      li.dataset.key = item.key;
      li.dataset.signature = item.signature;
      li.dataset.reactions = item.reactions ?? '';
      li.append(item.build());
    }
    li.className = item.className ?? 'transcript-item';
    if (li !== cursor) list.insertBefore(li, cursor);
    cursor = li.nextElementSibling;
  }
  for (const stale of existing.values()) stale.remove();
}

/** Local calendar day of a timestamp, as "YYYY-MM-DD". */
export function dayKey(value) {
  const date = new Date(value ?? '');
  if (Number.isNaN(date.getTime())) return 'unknown';
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** "Today", "Yesterday", or the date in the account's format. */
export function dayLabel(value, now = new Date()) {
  const key = dayKey(value);
  if (key === dayKey(now)) return 'Today';
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  if (key === dayKey(yesterday)) return 'Yesterday';
  return formatDateTime(value, { dateStyle: 'long', timeStyle: null });
}

/** A day heading item: an h3 inside the list, so H jumps between days in browse mode. */
export function dayItem(value) {
  const label = dayLabel(value);
  return {
    key: `day:${dayKey(value)}`,
    signature: label,
    className: 'transcript-day',
    build: () => {
      const heading = document.createElement('h3');
      heading.className = 'transcript-day-label';
      heading.textContent = label;
      return heading;
    },
  };
}

/** The "New messages" marker, a heading as well. */
export function newMessagesItem() {
  return {
    key: 'new-messages',
    signature: 'new-messages',
    className: 'transcript-marker',
    build: () => {
      const divider = document.createElement('div');
      divider.className = 'new-messages-divider';
      const heading = document.createElement('h3');
      heading.className = 'new-messages-label';
      heading.textContent = 'New messages';
      divider.append(heading);
      return divider;
    },
  };
}

/** Wraps an existing element (the load-older button) so it keeps its listeners. */
export function controlItem(key, element) {
  return { key, signature: key, className: 'transcript-control', build: () => element };
}

/**
 * Inserts day headings before the first entry of each day.
 * @template T
 * @param {T[]} entries in time order
 * @param {(entry: T) => string} dateOf
 * @param {(entry: T) => TranscriptItem} toItem
 * @returns {TranscriptItem[]}
 */
export function withDays(entries, dateOf, toItem) {
  const items = [];
  let lastDay = null;
  for (const entry of entries) {
    const day = dayKey(dateOf(entry));
    if (day !== lastDay) {
      items.push(dayItem(dateOf(entry)));
      lastDay = day;
    }
    items.push(toItem(entry));
  }
  return items;
}

/** A stable signature for a value; reactions are left out and passed separately. */
export function signatureOf(value) {
  return JSON.stringify(value);
}
