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
