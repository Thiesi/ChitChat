// The Activity feed's look per notification kind (S4c, #170; the addendum's
// table, comment 6815158178). The server writes every title, message and
// link (PrivacyNotificationService::renderText, privacy-safe by design);
// this only decides the icon, which of its words lead, and the action's
// label. Nothing is composed from parts the payload doesn't carry.
//
// No DOM access at import time, so it can be unit-tested.

const DAY_MS = 86_400_000;

// Conversation kinds: the server's message is already a plain sentence
// ("Rowan mentioned you in “Garden”."), so it leads and the generic title
// ("You were mentioned") steps back to a small label.
const KINDS = {
  mentioned: { icon: 'mention', lead: 'message', action: 'View message' },
  pinged: { icon: 'bell', lead: 'message', action: 'View ping' },
  direct_message_received: { icon: 'pebble', lead: 'message', action: 'Open conversation' },
  room_deleted: { icon: 'lantern', lead: 'message', action: null },
  room_restored: { icon: 'lantern', lead: 'message', action: 'Open room' },
  room_ownership_received: { icon: 'lantern', lead: 'message', action: 'Open room' },
  room_ownership_removed: { icon: 'lantern', lead: 'message', action: 'Open room' },
  username_change_required: { icon: 'shield', lead: 'title', action: 'Choose a username' },
  avatar_removed: { icon: 'shield', lead: 'title', action: 'Open You' },
  moderator_message_deleted: { icon: 'shield', lead: 'title', action: null },
  muted: { icon: 'shield', lead: 'title', action: null },
  guest_welcome: { icon: 'info', lead: 'title', action: 'Create an account' },
  privacy_policy_updated: { icon: 'info', lead: 'title', action: 'Read the policy' },
};

/** How a notification of this kind is shown: icon, leading words, action label. */
export function kindPresentation(kind) {
  return KINDS[kind] ?? { icon: 'info', lead: 'title', action: 'Open' };
}

/**
 * The person a direct-message notification points at, from the server's own
 * link (/messages.php?with=N), for the pebble's hue. Null when there is none.
 */
export function directPeerId(link) {
  if (typeof link !== 'string') return null;
  const match = /^\/messages\.php\?(?:.*&)?with=(\d+)(?:&|$)/.exec(link);
  return match ? Number(match[1]) : null;
}

/** A calendar day in the device's zone, as a comparable key. */
export function dayKey(value) {
  const date = new Date(value ?? '');
  if (Number.isNaN(date.getTime())) return '';
  return `${date.getFullYear()}-${date.getMonth() + 1}-${date.getDate()}`;
}

/** A day heading like the transcript's: Today, Yesterday, the weekday within a week, then the date. */
export function dayLabel(value, now = new Date(), format = defaultDate) {
  const date = new Date(value ?? '');
  if (Number.isNaN(date.getTime())) return 'Earlier';
  const day = (moment) => Math.round(new Date(moment.getFullYear(), moment.getMonth(), moment.getDate()).getTime() / DAY_MS);
  const days = day(now) - day(date);
  if (days <= 0) return 'Today';
  if (days === 1) return 'Yesterday';
  if (days < 7) return new Intl.DateTimeFormat(undefined, { weekday: 'long' }).format(date);
  return format(date);
}

function defaultDate(date) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(date);
}
