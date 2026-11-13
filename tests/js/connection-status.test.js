import assert from 'node:assert/strict';
import { test } from 'node:test';
import { LABELS, createConnectionStatus } from '../../public/assets/js/connection-status.js';

// S7 (#173) ladder: Live quiet, "Reconnecting…" only after three seconds down,
// "Offline" whenever the browser has no network.

function harness({ online = true } = {}) {
  const element = { dataset: {}, textContent: '' };
  const timers = new Map();
  const listeners = {};
  let next = 1;
  let network = online;
  const status = createConnectionStatus(element, {
    delay: 3000,
    online: () => network,
    setTimer: (handler) => { const id = next++; timers.set(id, handler); return id; },
    clearTimer: (id) => timers.delete(id),
    listen: (type, handler) => { listeners[type] = handler; },
  });
  return {
    element,
    status,
    elapse: () => { const due = [...timers.values()]; timers.clear(); due.forEach((run) => run()); },
    pending: () => timers.size,
    goOffline: () => { network = false; listeners.offline(); },
    goOnline: () => { network = true; listeners.online(); },
  };
}

test('starts connecting, then Live', () => {
  const h = harness();
  assert.equal(h.element.textContent, LABELS.connecting);
  h.status.set('connected');
  assert.deepEqual([h.element.dataset.state, h.element.textContent], ['connected', 'Live']);
});

test('a quick drop never shows Reconnecting', () => {
  const h = harness();
  h.status.set('connected');
  h.status.set('reconnecting');
  assert.equal(h.element.textContent, 'Live');
  h.status.set('connected');
  assert.equal(h.pending(), 0);
  h.elapse();
  assert.equal(h.element.textContent, 'Live');
});

test('three seconds down shows Reconnecting…, once, until it is back', () => {
  const h = harness();
  h.status.set('connected');
  h.status.set('reconnecting');
  h.status.set('reconnecting');
  assert.equal(h.pending(), 1);
  h.elapse();
  assert.deepEqual([h.element.dataset.state, h.element.textContent], ['reconnecting', 'Reconnecting…']);
  h.status.set('reconnecting');
  assert.equal(h.pending(), 0);
  h.status.set('connected');
  assert.equal(h.element.textContent, 'Live');
});

test('offline outranks the stream, and going online shows the stream again', () => {
  const h = harness();
  h.status.set('connected');
  h.goOffline();
  assert.deepEqual([h.element.dataset.state, h.element.textContent], ['offline', 'Offline. You can’t send until you’re back.']);
  h.status.set('reconnecting');
  h.elapse();
  assert.equal(h.element.dataset.state, 'offline');
  h.goOnline();
  assert.equal(h.element.textContent, 'Reconnecting…');
});

test('opening offline says so straight away', () => {
  assert.equal(harness({ online: false }).element.dataset.state, 'offline');
});
