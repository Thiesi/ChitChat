import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isGuestName } from '../../public/assets/js/guest.js';

test('a guest is "Guest" and a number; no username can look like one', () => {
  assert.equal(isGuestName('Guest 0042'), true);
  assert.equal(isGuestName('Guest 12345'), true);
  // Usernames have no spaces, so these are members.
  assert.equal(isGuestName('Guest0042'), false);
  assert.equal(isGuestName('guest-0042'), false);
  assert.equal(isGuestName('Guest 42'), false);
  assert.equal(isGuestName('Closed account #7'), false);
  assert.equal(isGuestName(null), false);
});
