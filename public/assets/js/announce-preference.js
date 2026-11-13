// "Announce new messages" (S5, #166): All, Only messages to me, or Off.
// Saved on this device only; without storage it is All. announcer.js reads
// it for each incoming message, so a change applies in open tabs at once.
// Off covers incoming messages only: what you do yourself is still spoken.

export const ANNOUNCE_KEY = 'chitchat.announce';
export const ANNOUNCE_CHOICES = ['all', 'to-me', 'off'];

/** @returns {'all' | 'to-me' | 'off'} */
export function announcePreference(storage) {
  try {
    // Reading localStorage itself can throw (blocked site data), so it stays inside the try.
    const value = (storage ?? globalThis.localStorage)?.getItem(ANNOUNCE_KEY);
    return ANNOUNCE_CHOICES.includes(value) ? value : 'all';
  } catch {
    return 'all';
  }
}

export function setAnnouncePreference(value, storage) {
  if (!ANNOUNCE_CHOICES.includes(value)) return;
  try {
    const store = storage ?? globalThis.localStorage;
    if (value === 'all') store?.removeItem(ANNOUNCE_KEY);
    else store?.setItem(ANNOUNCE_KEY, value);
  } catch {
    // Private windows and blocked storage: nothing is kept, and announcing stays at All.
  }
}

/**
 * Whether an incoming message is spoken under a preference. "To me" means
 * mentions and pings addressed to you, and every direct message.
 *
 * @param {{ kind?: string, mentionsMe?: boolean, direct?: boolean }} message
 * @param {'all' | 'to-me' | 'off'} preference
 */
export function shouldAnnounce(message, preference) {
  if (preference === 'off') return false;
  if (preference === 'to-me') return message.kind === 'ping' || Boolean(message.mentionsMe) || Boolean(message.direct);
  return true;
}
