// One polite announcer per page, fed by events and never by DOM changes
// (S1, #166 §5). The transcript itself carries no aria-live: re-rendering,
// edits, reactions and presence stay silent and are read when a message is
// reached. New messages from others are spoken once each (de-duplicated by
// id), bursts are summarised, and nothing is spoken while the tab is hidden;
// on return one line says how many arrived.

const BODY_LIMIT = 140;
// A burst is collected until it has been quiet this long, or this long since its first message.
const QUIET_MS = 600;
const WINDOW_MS = 2000;
const SUMMARY_ABOVE = 3;
const REMEMBERED = 1000;

let region = null;
let queue = [];
let flushTimer = null;
let firstQueuedAt = 0;
let awayCount = 0;
const spoken = new Set();

function ensureRegion() {
  if (region?.isConnected) return region;
  region = document.getElementById('announcer');
  if (!region) {
    region = document.createElement('div');
    region.id = 'announcer';
    region.className = 'visually-hidden';
    region.setAttribute('role', 'status');
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-atomic', 'true');
    document.body.append(region);
  }
  return region;
}

/** Speaks a line now, also when it repeats the previous one. */
export function announce(text) {
  const target = ensureRegion();
  target.textContent = '';
  // A new text node after a tick, so screen readers treat a repeat as new.
  window.setTimeout(() => { target.textContent = text; }, 50);
}

/**
 * A new message from someone else in the conversation on screen.
 * @param {{ key: string, sender?: string|null, body?: string|null, mentionsMe?: boolean,
 *           fileName?: string|null, kind?: 'message'|'emote'|'notice'|'ping' }} message
 */
export function announceIncoming(message) {
  if (!message?.key || spoken.has(message.key)) return;
  remember(message.key);
  if (document.visibilityState === 'hidden') {
    awayCount += 1;
    return;
  }
  queue.push(message);
  const now = Date.now();
  if (queue.length === 1) firstQueuedAt = now;
  window.clearTimeout(flushTimer);
  const wait = Math.max(0, Math.min(QUIET_MS, WINDOW_MS - (now - firstQueuedAt)));
  flushTimer = window.setTimeout(flush, wait);
}

/** Marks messages as known without speaking them (history, own sends, reconciled copies). */
export function markKnown(keys) {
  for (const key of keys) remember(key);
}

/** Drops pending speech when the conversation changes; what was spoken stays known. */
export function resetAnnouncements() {
  window.clearTimeout(flushTimer);
  queue = [];
}

function remember(key) {
  spoken.add(key);
  if (spoken.size > REMEMBERED) spoken.delete(spoken.values().next().value);
}

function flush() {
  const batch = queue;
  queue = [];
  if (batch.length === 0) return;
  if (batch.length > SUMMARY_ABOVE) {
    announce(`${batch.length} new messages from ${listNames(batch.map((message) => message.sender))}.`);
    return;
  }
  announce(batch.map(line).join(' '));
}

export function line(message) {
  const sender = message.sender || 'Someone';
  const body = shorten(message.body ?? '');
  if (message.kind === 'notice') return body;
  if (message.kind === 'ping') return body ? `Ping from ${sender}: ${body}` : `Ping from ${sender}.`;
  if (message.fileName) return `${sender} sent a file: ${message.fileName}`;
  if (message.kind === 'emote') return `${sender} ${body}`;
  if (message.mentionsMe) return `Mention from ${sender}: ${body}`;
  return body ? `${sender}: ${body}` : `${sender} sent a message.`;
}

export function shorten(text) {
  const collapsed = String(text).replace(/\s+/gu, ' ').trim();
  return collapsed.length > BODY_LIMIT ? `${collapsed.slice(0, BODY_LIMIT).trimEnd()}…` : collapsed;
}

export function listNames(names) {
  const unique = [...new Set(names.map((name) => name || 'someone'))];
  if (unique.length <= 1) return unique[0] ?? 'someone';
  if (unique.length === 2) return `${unique[0]} and ${unique[1]}`;
  const shown = unique.slice(0, 3);
  const rest = unique.length - shown.length;
  return rest > 0 ? `${shown.join(', ')} and ${rest} more` : `${shown.slice(0, -1).join(', ')} and ${shown.at(-1)}`;
}

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible' || awayCount === 0) return;
  const count = awayCount;
  awayCount = 0;
  announce(`${count} new ${count === 1 ? 'message' : 'messages'} while you were away.`);
});
