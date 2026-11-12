import assert from 'node:assert/strict';
import { test } from 'node:test';
import { groupRooms, isPlainActivation, listTime, roomRowLabel, sortByRecency, unreadRoomMentions } from '../../public/assets/js/room-list.js';

// S4a (#170): the Rooms list's ordering, grouping, times, mention parsing and row names.

const room = (id, name, extra = {}) => ({ id, name, member_role: 'member', invited: false, visibility: 'public', minimum_age: 0, ...extra });

test('joined rooms go newest activity first, then rooms without activity by name', () => {
  const sorted = sortByRecency([
    room(1, 'Zebra', { last_activity_at: null }),
    room(2, 'Garden', { last_activity_at: '2026-11-12T08:00:00Z' }),
    room(3, 'Apple', { last_activity_at: null }),
    room(4, 'Board games night', { last_activity_at: '2026-11-12T09:30:00Z' }),
  ]);
  assert.deepEqual(sorted.map((entry) => entry.name), ['Board games night', 'Garden', 'Apple', 'Zebra']);
});

test('rooms split into invitations, yours and others', () => {
  const { invitations, yours, others } = groupRooms([
    room(1, 'Mine'),
    room(2, 'Secret', { member_role: null, invited: true, visibility: 'private' }),
    room(3, 'Lobby', { member_role: null }),
    room(4, 'Attic', { member_role: null, visibility: 'unlisted' }),
  ]);
  assert.deepEqual(invitations.map((entry) => entry.name), ['Secret']);
  assert.deepEqual(yours.map((entry) => entry.name), ['Mine']);
  assert.deepEqual(others.map((entry) => entry.name), ['Attic', 'Lobby']);
});

test('a row time is the time today, Yesterday, a weekday within the week, then a date', () => {
  const now = new Date(2026, 10, 12, 15, 0);
  assert.match(listTime(new Date(2026, 10, 12, 9, 5).toISOString(), now), /9.05|09.05/);
  assert.equal(listTime(new Date(2026, 10, 11, 23, 0).toISOString(), now), 'Yesterday');
  const weekday = listTime(new Date(2026, 10, 8, 12, 0).toISOString(), now);
  assert.equal(weekday, new Intl.DateTimeFormat(undefined, { weekday: 'long' }).format(new Date(2026, 10, 8)));
  assert.match(listTime(new Date(2026, 9, 1, 12, 0).toISOString(), now), /2026|26/);
  assert.equal(listTime(null, now), '');
  assert.equal(listTime('not a date', now), '');
});

test('only unread room mentions with a room link count', () => {
  const mentions = unreadRoomMentions([
    { id: 9, kind: 'mentioned', read: false, link: '/?room_id=4&message_id=77', message: 'Jonas mentioned you in “Garden”.' },
    { id: 8, kind: 'mentioned', read: true, link: '/?room_id=5&message_id=70' },
    { id: 7, kind: 'mentioned', read: false, link: '/messages.php?user_id=3&peer_name=Jonas&message_id=5&with=3' },
    { id: 6, kind: 'pinged', read: false, link: '/?room_id=4&ping_id=2' },
    { id: 5, kind: 'mentioned', read: false, link: null },
  ]);
  assert.deepEqual(mentions, [{ id: 9, roomId: 4, messageId: 77, message: 'Jonas mentioned you in “Garden”.', link: '/?room_id=4&message_id=77' }]);
  assert.deepEqual(unreadRoomMentions(undefined), []);
});

test('a row name carries its badges and states in reading order', () => {
  assert.equal(roomRowLabel(room(1, 'Garden', { active_count: 2 }), { unread: 3, mentioned: true }), 'Garden, 3 unread, mentions you, 2 here now');
  assert.equal(roomRowLabel(room(1, 'Garden'), { unread: 150 }), 'Garden, 99+ unread');
  assert.equal(roomRowLabel(room(2, 'Secret', { member_role: null, invited: true, visibility: 'private', minimum_age: 18 })), 'Secret, invited, 18+, private');
  assert.equal(roomRowLabel(room(3, 'News'), { readOnly: true }), 'News, read only');
});

test('only an unmodified primary click stays in the page', () => {
  const click = (extra = {}) => ({ button: 0, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, ...extra });
  assert.equal(isPlainActivation(click()), true);
  for (const modified of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
    assert.equal(isPlainActivation(click(modified)), false, JSON.stringify(modified));
  }
});
