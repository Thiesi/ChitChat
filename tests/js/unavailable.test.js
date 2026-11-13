import assert from 'node:assert/strict';
import { test } from 'node:test';
import { FILE_NOT_SENT, FILE_UNAVAILABLE, MESSAGE_NOT_SENT, MESSAGE_UNAVAILABLE, ROOM_UNAVAILABLE, errorText, failedSending } from '../../public/assets/js/unavailable.js';

// #326: a room, message or file that's gone or can't be seen gets one plain sentence.

class FakeApiError extends Error {
  constructor(status, code, message) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

test('the not-available codes get our sentences, not the server’s', () => {
  assert.equal(errorText(new FakeApiError(404, 'room_not_found', 'Room not found.'), 'x'), ROOM_UNAVAILABLE);
  assert.equal(errorText(new FakeApiError(404, 'message_not_found', 'Message not found.'), 'x'), MESSAGE_UNAVAILABLE);
  assert.equal(errorText(new FakeApiError(404, 'attachment_not_found', 'Attachment not found.'), 'x'), FILE_UNAVAILABLE);
  assert.equal(ROOM_UNAVAILABLE, 'This room isn’t available any more.');
});

test('a request cut short by leaving the page says nothing, not the fallback (#270)', () => {
  assert.equal(errorText(new FakeApiError(0, 'page_left', ''), 'The attachment request failed.'), '');
});

test('any other error keeps the server’s message, then the fallback', () => {
  assert.equal(errorText(new FakeApiError(403, 'muted', 'You are muted in this room.'), 'x'), 'You are muted in this room.');
  assert.equal(errorText(new FakeApiError(500, 'server_error', ''), 'Fallback.'), 'Fallback.');
  assert.equal(errorText(null, 'Fallback.'), 'Fallback.');
});

test('a failed send or upload says what happened to it, after the reason; nothing for page_left', () => {
  assert.equal(failedSending(ROOM_UNAVAILABLE, MESSAGE_NOT_SENT), 'This room isn’t available any more. Your message wasn’t sent.');
  assert.equal(failedSending(ROOM_UNAVAILABLE, FILE_NOT_SENT), 'This room isn’t available any more. Your file wasn’t sent.');
  assert.equal(failedSending(errorText({ code: 'page_left' }, 'x'), MESSAGE_NOT_SENT), '');
});

test('no answer while the browser is offline says so; a server answer keeps its own words', () => {
  const original = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
  Object.defineProperty(globalThis, 'navigator', { value: { onLine: false }, configurable: true });
  try {
    assert.equal(errorText(new TypeError('Failed to fetch'), 'x'), 'You’re offline.');
    assert.equal(errorText(new FakeApiError(403, 'muted', 'You are muted in this room.'), 'x'), 'You are muted in this room.');
    assert.equal(errorText({ code: 'page_left' }, 'x'), '');
  } finally {
    if (original) Object.defineProperty(globalThis, 'navigator', original);
    else delete globalThis.navigator;
  }
});
