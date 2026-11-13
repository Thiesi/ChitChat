import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  ANNOUNCE_KEY, announcePreference, setAnnouncePreference, shouldAnnounce,
} from '../../public/assets/js/announce-preference.js';

// S5b (#171): "Announce new messages" — All, Only messages to me, or Off, on this device.

function memoryStorage(initial = {}) {
  const values = new Map(Object.entries(initial));
  return {
    getItem: (key) => (values.has(key) ? values.get(key) : null),
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
    values,
  };
}

const blocked = {
  getItem() { throw new Error('SecurityError'); },
  setItem() { throw new Error('SecurityError'); },
  removeItem() { throw new Error('SecurityError'); },
};

test('the default is All, also for unknown values and blocked storage', () => {
  assert.equal(announcePreference(memoryStorage()), 'all');
  assert.equal(announcePreference(memoryStorage({ [ANNOUNCE_KEY]: 'loud' })), 'all');
  assert.equal(announcePreference(blocked), 'all');
});

test('a choice is saved and read back; All clears the key', () => {
  const storage = memoryStorage();
  setAnnouncePreference('to-me', storage);
  assert.equal(announcePreference(storage), 'to-me');
  setAnnouncePreference('off', storage);
  assert.equal(announcePreference(storage), 'off');
  setAnnouncePreference('all', storage);
  assert.equal(storage.values.has(ANNOUNCE_KEY), false);
  setAnnouncePreference('nonsense', storage);
  assert.equal(storage.values.has(ANNOUNCE_KEY), false);
  assert.doesNotThrow(() => setAnnouncePreference('off', blocked));
});

test('All reads everything; Off reads nothing incoming', () => {
  const kinds = [{ kind: 'message' }, { kind: 'notice' }, { kind: 'emote' }, { kind: 'ping' }, { mentionsMe: true }, { direct: true }];
  for (const message of kinds) {
    assert.equal(shouldAnnounce(message, 'all'), true);
    assert.equal(shouldAnnounce(message, 'off'), false);
  }
});

test('Only messages to me: mentions, pings and direct messages', () => {
  assert.equal(shouldAnnounce({ kind: 'message', mentionsMe: true }, 'to-me'), true);
  assert.equal(shouldAnnounce({ kind: 'ping' }, 'to-me'), true);
  assert.equal(shouldAnnounce({ direct: true }, 'to-me'), true);
  assert.equal(shouldAnnounce({ kind: 'message' }, 'to-me'), false);
  assert.equal(shouldAnnounce({ kind: 'emote' }, 'to-me'), false);
  assert.equal(shouldAnnounce({ kind: 'notice' }, 'to-me'), false);
});
