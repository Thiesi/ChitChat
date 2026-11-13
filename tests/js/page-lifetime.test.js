import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createPageLifetime } from '../../public/assets/js/page-lifetime.js';

// #270: leaving the page aborts in-flight requests; a back/forward-cache restore starts afresh.

function pageEvent(type, persisted) {
  const event = new Event(type);
  Object.defineProperty(event, 'persisted', { value: persisted });
  return event;
}

test('pagehide aborts the signal; a persisted pageshow starts a fresh one', () => {
  const target = new EventTarget();
  const page = createPageLifetime(target);
  const first = page.signal();
  assert.equal(first.aborted, false);
  assert.equal(page.leaving(), false);

  target.dispatchEvent(pageEvent('pagehide', true));
  assert.equal(first.aborted, true);
  assert.equal(first.reason.name, 'AbortError');
  assert.equal(page.leaving(), true);

  target.dispatchEvent(pageEvent('pageshow', true));
  assert.equal(page.leaving(), false);
  assert.notEqual(page.signal(), first);
  assert.equal(page.signal().aborted, false);
});

test('a first-load pageshow changes nothing', () => {
  const target = new EventTarget();
  const page = createPageLifetime(target);
  const signal = page.signal();
  target.dispatchEvent(pageEvent('pageshow', false));
  assert.equal(page.signal(), signal);
  assert.equal(page.leaving(), false);
});

test('api.js: a request aborted by leaving rejects with PageLeftError, so finally still runs', async () => {
  const fakeWindow = new EventTarget();
  fakeWindow.location = { pathname: '/', assign() {} };
  globalThis.window = fakeWindow;
  globalThis.document = { cookie: '' };
  const calls = [];
  globalThis.fetch = (path, options) => new Promise((resolve, reject) => {
    calls.push(path);
    options.signal?.addEventListener('abort', () => reject(options.signal.reason));
    if (path === '/ok') resolve(new Response('{"done":true}', { headers: { 'content-type': 'application/json' } }));
  });
  const { apiGet, PageLeftError, isPageLeft, ApiError } = await import('../../public/assets/js/api.js');

  let finallyRan = false;
  const pending = apiGet('/slow').finally(() => { finallyRan = true; });
  fakeWindow.dispatchEvent(pageEvent('pagehide', true));
  await assert.rejects(pending, (error) => error instanceof PageLeftError && isPageLeft(error)
    && error instanceof ApiError && error.code === 'page_left' && error.message === '');
  assert.equal(finallyRan, true, 'A button waiting on it re-enables.');

  // Back from the back/forward cache: new requests work.
  fakeWindow.dispatchEvent(pageEvent('pageshow', true));
  assert.deepEqual(await apiGet('/ok'), { done: true });

  // Any other failure is still an ordinary error.
  globalThis.fetch = () => Promise.reject(new TypeError('Failed to fetch'));
  await assert.rejects(apiGet('/broken'), (error) => error instanceof TypeError);
});
