import assert from 'node:assert/strict';
import { test } from 'node:test';

// #270: only a request the page aborted on leaving is silenced, by its identity. A real error
// that happens to have no message (an empty server body, a bare network failure) still shows.

globalThis.window ??= Object.assign(new EventTarget(), { location: { pathname: '/', assign() {} } });
globalThis.document ??= { cookie: '' };

const { ApiError, PageLeftError, isPageLeft, logError } = await import('../../public/assets/js/api.js');
const { describeTotpError } = await import('../../public/assets/js/totp-common.js');

test('isPageLeft is about identity, not an empty message', () => {
  assert.equal(isPageLeft(new PageLeftError()), true);
  assert.equal(isPageLeft(new ApiError(500, 'request_failed', '')), false);
  assert.equal(isPageLeft(new TypeError('')), false);
  assert.equal(isPageLeft(null), false);
});

test('an ordinary error with an empty message still shows its fallback line', () => {
  assert.equal(describeTotpError(new ApiError(500, 'request_failed', '')), 'That didn’t work. Try again.');
  assert.equal(describeTotpError(new TypeError('')), 'That didn’t work. Try again.');
});

test('a request the page aborted on leaving shows no line at all', () => {
  assert.equal(describeTotpError(new PageLeftError()), '');
});

test('logError stays silent only for a request the page aborted on leaving', () => {
  const logged = [];
  const original = console.error;
  console.error = (error) => logged.push(error);
  try {
    logError(new PageLeftError());
    const empty = new ApiError(500, 'request_failed', '');
    logError(empty);
    assert.deepEqual(logged, [empty]);
  } finally {
    console.error = original;
  }
});
