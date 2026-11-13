import assert from 'node:assert/strict';
import { test } from 'node:test';
import { countLiveMessage } from '../../public/assets/js/unread-count.js';

// #268, #279: live room_message events add to the room list's unread snapshot exactly once.

test('a message the list snapshot already counted is not counted again by its stream event', () => {
  // The list was fetched after message 41 arrived: the server counted it.
  const room = { id: 1, unread_count: 1, last_read_message_id: 40, unread_through_message_id: 41 };
  assert.equal(countLiveMessage(room, 41), false);
  assert.equal(room.unread_count, 1);
  // A message after the snapshot counts.
  assert.equal(countLiveMessage(room, 42), true);
  assert.equal(room.unread_count, 2);
});

test('messages arriving out of id order all count (live counts never move the marks)', () => {
  const room = { id: 1, unread_count: 0, last_read_message_id: 40, unread_through_message_id: 40 };
  assert.equal(countLiveMessage(room, 43), true);
  assert.equal(countLiveMessage(room, 42), true, "A concurrent sender's earlier message, published later, still counts.");
  assert.equal(room.unread_count, 2);
  assert.equal(room.unread_through_message_id, 40);
});

test('a message at or below the read position never counts (a join replays the past)', () => {
  const room = { id: 1, unread_count: 0, last_read_message_id: 40, unread_through_message_id: null };
  assert.equal(countLiveMessage(room, 39), false);
  assert.equal(countLiveMessage(room, 40), false);
  assert.equal(room.unread_count, 0);
  assert.equal(countLiveMessage(room, 41), true);
  assert.equal(room.unread_count, 1);
});

test('without any watermark every message counts, up to the cap', () => {
  const room = { id: 1, unread_count: 99 };
  assert.equal(countLiveMessage(room, 5), true);
  assert.equal(countLiveMessage(room, 6), true);
  assert.equal(room.unread_count, 100);
});

// #286: with unread_settled_message_id and unread_counted_ids, late commits and resends count once.

test('settled fields: a late-committing lower message counts, a counted or settled one does not', () => {
  const room = { id: 1, unread_count: 2, last_read_message_id: 40, unread_through_message_id: 45, unread_settled_message_id: 42, unread_counted_ids: [43, 45] };
  assert.equal(countLiveMessage(room, 42), false, 'At or below the settled mark: already counted or read.');
  assert.equal(countLiveMessage(room, 45), false, 'Listed as counted.');
  assert.equal(countLiveMessage(room, 44), true, 'Below unread_through_message_id but committed late: it counts.');
  assert.equal(room.unread_count, 3);
  assert.equal(countLiveMessage(room, 44), false, 'A resent event for the same message does not count again.');
  assert.equal(room.unread_count, 3);
});

test('settled fields: the 0 fallback relies on the list', () => {
  const room = { id: 1, unread_count: 1, last_read_message_id: 40, unread_settled_message_id: 0, unread_counted_ids: [41] };
  assert.equal(countLiveMessage(room, 41), false);
  assert.equal(countLiveMessage(room, 40), false, 'Read.');
  assert.equal(countLiveMessage(room, 42), true);
  assert.equal(room.unread_count, 2);
});

test('settled fields: at 100 the count is saturated and live messages are not added', () => {
  const room = { id: 1, unread_count: 99, last_read_message_id: 0, unread_settled_message_id: 0, unread_counted_ids: [] };
  assert.equal(countLiveMessage(room, 500), true);
  assert.equal(room.unread_count, 100);
  assert.equal(countLiveMessage(room, 501), false);
  assert.equal(room.unread_count, 100);
});

test('settled fields: ids already read are dropped from the list as it grows', () => {
  const room = { id: 1, unread_count: 0, last_read_message_id: 50, unread_settled_message_id: 0, unread_counted_ids: [41, 42] };
  assert.equal(countLiveMessage(room, 51), true);
  assert.deepEqual(room.unread_counted_ids, [51]);
});
