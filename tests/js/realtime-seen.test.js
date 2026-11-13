import assert from 'node:assert/strict';
import { test } from 'node:test';
import { SEEN_CAP, SeenEvents, dropResentEvents, eventPosition } from '../../public/assets/js/realtime-seen.js';

// #286: a settled stream may resend events after a reconnect; each is handled once.

test('an event id is accepted once, and again only after the position has passed it and forgotten it', () => {
  const seen = new SeenEvents();
  assert.equal(seen.accept(101, 99), true);
  assert.equal(seen.accept(101, 99), false, 'Resent above the position: dropped.');
  assert.equal(seen.accept(100, 101), true, 'A lower event that committed late still arrives.');
  assert.equal(seen.size, 0, 'Both are at or below the position now, so neither is kept.');
});

test('the frame position can be below, at or above the event id', () => {
  const seen = new SeenEvents();
  assert.equal(seen.accept(205, 0), true, 'A fresh stream starts at position 0.');
  assert.equal(seen.accept(206, 204), true);
  assert.equal(seen.size, 2);
  assert.equal(seen.accept(207, 206), true);
  assert.equal(seen.size, 1, '205 and 206 are settled; 207 is kept.');
  assert.equal(seen.accept(207, 206), false);
});

test('events without a numeric id or position pass and change nothing they should not', () => {
  const seen = new SeenEvents();
  assert.equal(seen.accept(null, null), true);
  assert.equal(seen.accept(null, null), true);
  assert.equal(seen.accept(5, null), true);
  assert.equal(seen.accept(5, null), false);
  assert.equal(seen.size, 1);
});

test('at most the cap is kept, oldest first', () => {
  assert.equal(SEEN_CAP, 1000);
  const seen = new SeenEvents(3);
  for (const id of [10, 11, 12, 13]) assert.equal(seen.accept(id, 0), true);
  assert.equal(seen.size, 3);
  assert.equal(seen.accept(10, 0), true, 'The oldest was forgotten.');
  assert.equal(seen.accept(13, 0), false);
});

test('eventPosition reads only non-negative integers', () => {
  assert.equal(eventPosition('0'), 0);
  assert.equal(eventPosition('42'), 42);
  for (const value of ['', '-1', '1.5', 'abc', undefined, null]) assert.equal(eventPosition(value), null);
});

class FakeSource {
  listeners = new Map();

  addEventListener(type, listener) {
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(listener);
  }

  dispatch(type, data, lastEventId) {
    let stopped = false;
    const event = { type, data: JSON.stringify(data), lastEventId, stopImmediatePropagation: () => { stopped = true; } };
    for (const listener of this.listeners.get(type) ?? []) {
      if (stopped) break;
      if (typeof listener === 'function') listener(event);
      else listener.handleEvent(event);
    }
  }
}

test('dropResentEvents runs once per event, ahead of every listener of its type', () => {
  const source = new FakeSource();
  dropResentEvents(source);
  const calls = [];
  source.addEventListener('room_message', () => calls.push('first'));
  source.addEventListener('room_message', () => calls.push('second'));
  source.addEventListener('typing', () => calls.push('typing'));
  source.addEventListener('forced_logout', () => calls.push('logout'));

  source.dispatch('room_message', { id: 7 }, '6');
  assert.deepEqual(calls, ['first', 'second'], 'One check for both listeners: the second is not taken for a resend.');
  source.dispatch('room_message', { id: 7 }, '6');
  assert.deepEqual(calls, ['first', 'second'], 'The resend reaches no listener.');
  source.dispatch('typing', { id: 8 }, '6');
  source.dispatch('forced_logout', { reason: 'session_revoked' }, '6');
  source.dispatch('forced_logout', { reason: 'session_revoked' }, '6');
  assert.deepEqual(calls, ['first', 'second', 'typing', 'logout', 'logout'], 'Frames without data.id are never dropped.');
});

test('open and error get no check', () => {
  const source = new FakeSource();
  dropResentEvents(source);
  source.addEventListener('open', () => {});
  source.addEventListener('error', () => {});
  assert.equal(source.listeners.get('open').length, 1);
  assert.equal(source.listeners.get('error').length, 1);
});
