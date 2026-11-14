import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dayKey, dayLabel, directPeerId, kindPresentation } from '../../public/assets/js/activity.js';

// S4c (#170): the Activity feed's per-kind look and its day headings.

// Every kind PrivacyNotificationService::renderText knows, plus an unknown one.
const KINDS = [
  'revision_review', 'moderator_message_deleted', 'admin_password_reset', 'privacy_policy_updated',
  'system_policy_changed', 'direct_message_received', 'mentioned', 'muted', 'username_change_required',
  'avatar_removed', 'room_deleted', 'room_restored', 'room_ownership_received', 'room_ownership_removed', 'guest_welcome', 'pinged', 'something_new',
];

test('every kind has a mark, a lead and an action label (or none)', () => {
  for (const kind of KINDS) {
    const { icon, lead, action } = kindPresentation(kind);
    assert.ok(['mention', 'bell', 'pebble', 'shield', 'lantern', 'info'].includes(icon), `${kind}: ${icon}`);
    assert.ok(['message', 'title'].includes(lead), `${kind}: ${lead}`);
    assert.ok(action === null || (typeof action === 'string' && action.length > 0), `${kind}: ${action}`);
  }
});

test('the addendum’s table: marks and what leads', () => {
  assert.deepEqual(kindPresentation('mentioned'), { icon: 'mention', lead: 'message', action: 'View message', type: 'Mention' });
  assert.deepEqual(kindPresentation('pinged'), { icon: 'bell', lead: 'message', action: 'View ping', type: 'Ping' });
  assert.deepEqual(kindPresentation('direct_message_received'), { icon: 'pebble', lead: 'message', action: 'Open conversation', type: 'Direct message' });
  assert.deepEqual(kindPresentation('username_change_required'), { icon: 'shield', lead: 'title', action: 'Choose a username' });
  assert.equal(kindPresentation('avatar_removed').icon, 'shield');
  assert.equal(kindPresentation('moderator_message_deleted').icon, 'shield');
  assert.equal(kindPresentation('room_deleted').icon, 'lantern');
  assert.equal(kindPresentation('room_deleted').action, null);
  assert.equal(kindPresentation('room_restored').action, 'Open room');
  // #300: a room handed to you, or handed on: the server's sentence leads, and its link opens the room.
  for (const kind of ['room_ownership_received', 'room_ownership_removed']) {
    assert.deepEqual(kindPresentation(kind), { icon: 'lantern', lead: 'message', action: 'Open room', type: 'Room ownership' });
  }
  // S7h: the small label is the kind; kinds that lead with their title have none.
  assert.equal(kindPresentation('room_deleted').type, 'Room');
  assert.equal(kindPresentation('username_change_required').type, undefined);
  assert.equal(kindPresentation('system_policy_changed').icon, 'info');
  assert.equal(kindPresentation('something_new').icon, 'info');
});

test('a direct-message link names the person for the pebble’s hue', () => {
  assert.equal(directPeerId('/messages.php?with=7'), 7);
  assert.equal(directPeerId('/messages.php?user_id=3&peer_name=Ann&message_id=5&with=3'), 3);
  assert.equal(directPeerId('/messages.php'), null);
  assert.equal(directPeerId('/?room_id=4&message_id=7'), null);
  assert.equal(directPeerId(null), null);
});

test('day headings: Today, Yesterday, a weekday within the week, then the date', () => {
  const now = new Date(2026, 10, 12, 15, 0);
  assert.equal(dayLabel(new Date(2026, 10, 12, 1, 0).toISOString(), now), 'Today');
  assert.equal(dayLabel(new Date(2026, 10, 11, 23, 30).toISOString(), now), 'Yesterday');
  assert.equal(dayLabel(new Date(2026, 10, 8, 12, 0).toISOString(), now), new Intl.DateTimeFormat(undefined, { weekday: 'long' }).format(new Date(2026, 10, 8)));
  assert.equal(dayLabel(new Date(2026, 9, 1, 12, 0).toISOString(), now, () => 'October 1, 2026'), 'October 1, 2026');
  assert.equal(dayLabel('nonsense', now), 'Earlier');
  assert.equal(dayKey(new Date(2026, 10, 12, 1).toISOString()), dayKey(new Date(2026, 10, 12, 23).toISOString()));
  assert.notEqual(dayKey(new Date(2026, 10, 12, 1).toISOString()), dayKey(new Date(2026, 10, 11, 23).toISOString()));
});
