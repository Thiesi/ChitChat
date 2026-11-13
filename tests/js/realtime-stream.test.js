import assert from 'node:assert/strict';
import { test } from 'node:test';
import { LONGEST_DELAY, keepEventStream, reconnectDelay, sessionProbe, streamUrl } from '../../public/assets/js/realtime-stream.js';

// #301: the page's event stream is recreated when the browser gives up on it.

test('reconnectDelay: 1, 2, 5, 10, 20, then 30 s, within ±20 %', () => {
  const middle = () => 0.5;
  assert.deepEqual([0, 1, 2, 3, 4, 5, 6, 40].map((attempt) => reconnectDelay(attempt, middle)), [1000, 2000, 5000, 10000, 20000, 30000, 30000, 30000]);
  assert.equal(reconnectDelay(0, () => 0), 800);
  assert.equal(reconnectDelay(5, () => 0.999999), 36000);
  assert.equal(LONGEST_DELAY, 30000);
});

test('streamUrl keeps resume=settled and resumes from the position as after_id', () => {
  assert.equal(streamUrl(false, null), '/api/v1/events/stream.php');
  assert.equal(streamUrl(true, null), '/api/v1/events/stream.php?resume=settled');
  assert.equal(streamUrl(true, 0), '/api/v1/events/stream.php?resume=settled&after_id=0');
  assert.equal(streamUrl(false, 41), '/api/v1/events/stream.php?after_id=41', 'An older server: the last event id.');
});

test('sessionProbe: only a 401 or a session without a user means signed out', async () => {
  assert.equal(await sessionProbe(async () => ({ user: { id: 1 } }))(), 'ok');
  assert.equal(await sessionProbe(async () => ({ user: null }))(), 'signed_out');
  assert.equal(await sessionProbe(async () => { throw Object.assign(new Error('x'), { status: 401 }); })(), 'signed_out');
  assert.equal(await sessionProbe(async () => { throw Object.assign(new Error('x'), { status: 503 }); })(), 'unavailable');
  assert.equal(await sessionProbe(async () => { throw new TypeError('Failed to fetch'); })(), 'unavailable');
});

class FakeSource {
  static made = [];

  constructor(url) {
    this.url = url;
    this.readyState = 0;
    this.listeners = new Map();
    FakeSource.made.push(this);
  }

  addEventListener(type, listener) {
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(listener);
  }

  close() { this.readyState = 2; }

  emit(type, extra = {}) {
    let stopped = false;
    const event = { type, stopImmediatePropagation: () => { stopped = true; }, ...extra };
    for (const listener of this.listeners.get(type) ?? []) {
      if (stopped) break;
      if (typeof listener === 'function') listener(event); else listener.handleEvent(event);
    }
  }

  opened() { this.readyState = 1; this.emit('open'); }

  failedForGood() { this.readyState = 2; this.emit('error'); }

  message(type, id, position) { this.emit(type, { data: JSON.stringify({ id, type }), lastEventId: String(position) }); }
}

function harness({ probe = async () => 'ok', hidden = false, settled = true } = {}) {
  FakeSource.made = [];
  const timers = [];
  const statuses = [];
  const calls = { signedOut: 0, longOutage: 0, probes: 0, handled: [] };
  let wake = null;
  let pageListeners = null;
  const env = {
    EventSource: FakeSource,
    setTimeout: (callback, delay) => { timers.push({ callback, delay, cleared: false }); return timers.length - 1; },
    clearTimeout: (id) => { timers[id].cleared = true; },
    random: () => 0.5,
    isHidden: () => hidden,
    addWakeListeners: (callback) => { wake = callback; },
    removeWakeListeners: () => { wake = null; },
    addPageListeners: (onHide, onShow) => { pageListeners = { onHide, onShow }; },
    removePageListeners: () => { pageListeners = null; },
  };
  const keeper = keepEventStream({
    settled,
    attach: (source) => source.addEventListener('room_message', (event) => calls.handled.push(JSON.parse(event.data).id)),
    probe: async () => { calls.probes += 1; return probe(); },
    onStatus: (status) => statuses.push(status),
    onSignedOut: () => { calls.signedOut += 1; },
    onLongOutage: () => { calls.longOutage += 1; },
    env,
  });
  const pending = () => timers.filter((timer) => !timer.cleared && !timer.ran);
  const fire = async () => {
    const [timer] = pending();
    assert.ok(timer, 'A retry is scheduled.');
    timer.ran = true;
    await timer.callback();
  };
  return {
    keeper, timers, pending, fire, statuses, calls,
    wake: () => wake?.(),
    hidden: (value) => { hidden = value; },
    // The browser env only calls these for pagehide/pageshow with persisted (the bfcache).
    parkPage: () => pageListeners?.onHide(),
    restorePage: () => pageListeners?.onShow(),
    pageListening: () => pageListeners !== null,
  };
}

const settleAll = () => new Promise((resolve) => setImmediate(resolve));

test('into the back/forward cache the stream is closed on purpose, and a restore reopens it from its position (#323)', async () => {
  const h = harness();
  const first = FakeSource.made[0];
  first.opened();
  first.message('room_message', 101, 100);
  assert.equal(h.statuses.at(-1), 'connected');

  h.parkPage();
  assert.equal(first.readyState, 2, 'Closed on purpose.');
  first.emit('error');
  assert.equal(h.pending().length, 0, 'A parked stream schedules nothing.');

  h.restorePage();
  assert.equal(h.statuses.at(-1), 'reconnecting', 'Said at once, before the session check: never a stale "Live".');
  await settleAll();
  assert.equal(h.calls.probes, 1);
  const second = FakeSource.made[1];
  assert.equal(second.url, '/api/v1/events/stream.php?resume=settled&after_id=100');
  second.opened();
  assert.equal(h.statuses.at(-1), 'connected');
  second.message('room_message', 101, 100);
  second.message('room_message', 102, 101);
  assert.deepEqual(h.calls.handled, [101, 102], 'The resent event is dropped; the next one arrives once.');
  assert.equal(h.calls.longOutage, 0, 'A quick Back and forward never says "Still reconnecting".');
});

test('a restore after a long outage starts the backoff afresh: no announcement until the cap is reached again', async () => {
  const h = harness({ probe: async () => 'unavailable' });
  FakeSource.made[0].failedForGood();
  for (let i = 0; i < 6; i += 1) await h.fire();
  assert.equal(h.calls.longOutage, 1);
  h.parkPage();
  h.restorePage();
  await settleAll();
  // The failed check after the restore schedules the shortest wait, and says nothing.
  assert.deepEqual(h.pending().map((timer) => timer.delay), [1000]);
  assert.equal(h.calls.longOutage, 1);
});

test('parking cancels a pending retry, and a check in flight never reopens a parked stream', async () => {
  let release;
  const h = harness({ probe: () => new Promise((resolve) => { release = resolve; }) });
  FakeSource.made[0].failedForGood();
  assert.equal(h.pending().length, 1);
  h.parkPage();
  assert.equal(h.pending().length, 0, 'The retry timer is cancelled.');

  // A check that was already running when the page went into the cache.
  const g = harness({ probe: () => new Promise((resolve) => { release = resolve; }) });
  FakeSource.made[0].failedForGood();
  const firing = g.fire();
  g.parkPage();
  release('ok');
  await firing;
  assert.equal(FakeSource.made.length, 1, 'Nothing opened while parked.');
});

test('a restore whose session has ended signs out instead of reconnecting', async () => {
  const h = harness({ probe: async () => 'signed_out' });
  FakeSource.made[0].opened();
  h.parkPage();
  h.restorePage();
  await settleAll();
  assert.equal(h.calls.signedOut, 1);
  assert.equal(h.keeper.stopped, true);
  assert.equal(FakeSource.made.length, 1);
});

test('a restore without a park, or after stop(), does nothing; stop() removes the page listeners', async () => {
  const h = harness();
  FakeSource.made[0].opened();
  h.restorePage();
  await settleAll();
  assert.equal(FakeSource.made.length, 1, 'Only a parked stream is reopened.');
  assert.equal(h.calls.probes, 0);

  h.keeper.stop();
  assert.equal(h.pageListening(), false);
});

test('a stream that closes for good is recreated after the backoff, from its position, with the same dedupe', async () => {
  const h = harness();
  const first = FakeSource.made[0];
  assert.equal(first.url, '/api/v1/events/stream.php?resume=settled');
  first.opened();
  first.message('room_message', 101, 100);
  first.failedForGood();
  assert.deepEqual(h.pending().map((timer) => timer.delay), [1000]);
  assert.equal(h.statuses.at(-1), 'reconnecting');

  await h.fire();
  assert.equal(h.calls.probes, 1);
  const second = FakeSource.made[1];
  assert.equal(second.url, '/api/v1/events/stream.php?resume=settled&after_id=100');
  second.opened();
  assert.equal(h.statuses.at(-1), 'connected');
  second.message('room_message', 101, 100);
  second.message('room_message', 102, 101);
  assert.deepEqual(h.calls.handled, [101, 102], 'The resent event is dropped; the next one arrives once.');
});

test('the browser retrying by itself is left alone', () => {
  const h = harness();
  const first = FakeSource.made[0];
  first.opened();
  first.readyState = 0;
  first.emit('error');
  assert.equal(h.pending().length, 0);
  assert.equal(FakeSource.made.length, 1);
  assert.equal(h.statuses.at(-1), 'reconnecting');
});

test('a failed check keeps backing off; the wait grows to 30 s, said once; open resets it', async () => {
  let answer = 'unavailable';
  const h = harness({ probe: async () => answer });
  FakeSource.made[0].failedForGood();
  for (let i = 0; i < 7; i += 1) await h.fire();
  assert.deepEqual(h.timers.map((timer) => timer.delay), [1000, 2000, 5000, 10000, 20000, 30000, 30000, 30000]);
  assert.equal(h.calls.longOutage, 1, 'Once per outage.');
  assert.equal(FakeSource.made.length, 1, 'No new stream while the check fails.');

  answer = 'ok';
  await h.fire();
  FakeSource.made[1].opened();
  FakeSource.made[1].failedForGood();
  assert.equal(h.pending()[0].delay, 1000, 'Back to the shortest wait.');
});

test('a 401 from the check signs out and stops', async () => {
  const h = harness({ probe: async () => 'signed_out' });
  FakeSource.made[0].failedForGood();
  await h.fire();
  assert.equal(h.calls.signedOut, 1);
  assert.equal(h.keeper.stopped, true);
  assert.equal(FakeSource.made.length, 1);
  assert.equal(h.pending().length, 0);
});

test('no reconnect after stop() or forced_logout', () => {
  const h = harness();
  h.keeper.stop();
  assert.equal(FakeSource.made[0].readyState, 2);
  FakeSource.made[0].emit('error');
  assert.equal(h.pending().length, 0);

  const g = harness();
  g.keeper.source.emit('forced_logout', { data: '{"reason":"session_revoked"}' });
  assert.equal(g.keeper.stopped, true);
  g.keeper.source.failedForGood();
  assert.equal(g.pending().length, 0);
});

test("the server's own error frame ends that stream and goes through the check", async () => {
  const h = harness();
  const first = FakeSource.made[0];
  first.opened();
  first.emit('error', { data: '{"code":"stream_error"}' });
  assert.equal(first.readyState, 2);
  assert.equal(h.pending().length, 1);
  await h.fire();
  assert.equal(FakeSource.made.length, 2);
});

test('a hidden tab waits at least 30 s; becoming visible or online retries now', async () => {
  const h = harness({ hidden: true });
  FakeSource.made[0].failedForGood();
  assert.equal(h.pending()[0].delay, 30000);
  h.hidden(false);
  h.wake();
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(h.calls.probes, 1);
  assert.equal(FakeSource.made.length, 2);
  assert.equal(h.pending().length, 0, 'The waiting timer was cancelled.');
});

test('an older server: recreated from the last event id without resume', async () => {
  const h = harness({ settled: false });
  const first = FakeSource.made[0];
  assert.equal(first.url, '/api/v1/events/stream.php');
  first.message('room_message', 7, 7);
  first.failedForGood();
  await h.fire();
  assert.equal(FakeSource.made[1].url, '/api/v1/events/stream.php?after_id=7');
});
