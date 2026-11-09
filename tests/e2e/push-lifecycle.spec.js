import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';

const user = { id: 7, username: 'Member', session_version: 3, guest: false };
const endpoint = 'https://push.example/device';

async function fixture(page, { pending = false } = {}) {
  const requests = [];
  let release;
  const blocked = new Promise((resolve) => { release = resolve; });
  await page.addInitScript(({ endpoint }) => {
    window.PushManager = function PushManager() {};
    Object.defineProperty(window, 'Notification', { configurable: true, value: { permission: 'granted' } });
    window.pushCalls = { unsubscribed: 0, closed: 0 };
    const subscription = {
      endpoint,
      toJSON: () => ({ endpoint, keys: { p256dh: 'fixture', auth: 'fixture' } }),
      unsubscribe: async () => { ++window.pushCalls.unsubscribed; return true; },
    };
    const registration = {
      pushManager: { getSubscription: async () => subscription },
      getNotifications: async () => [{ close: () => { ++window.pushCalls.closed; } }],
      unregister: async () => true,
    };
    Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: {
      register: async () => registration, getRegistration: async () => registration,
    } });
  }, { endpoint });
  const files = new Set(['push-state.js', 'push-lifecycle.js', 'api.js', 'datetime.js']);
  await page.route('http://localhost/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/fixture') return route.fulfill({
      contentType: 'text/html', body: '<!doctype html><title>Push contract fixture</title><script type="module" src="/assets/js/push-lifecycle.js"></script>',
    });
    const filename = url.pathname.split('/').pop();
    if (files.has(filename)) return route.fulfill({
      contentType: 'text/javascript', body: await readFile(new URL('../../public/assets/js/' + filename, import.meta.url), 'utf8'),
    });
    if (url.pathname === '/api/v1/session.php') return route.fulfill({ json: { user, csrf_token: 'csrf', web_push: { enabled: true } } });
    if (url.pathname === '/api/v1/push/subscribe.php') {
      requests.push(route.request().postDataJSON());
      if (pending) await blocked;
      return route.fulfill({ json: { subscribed: true, id: 42, binding: 'binding-for-seven' } });
    }
    if (['/api/v1/logout.php', '/api/v1/password-reset.php', '/api/v1/oidc/register.php'].includes(url.pathname)) return route.abort('internetdisconnected');
    return route.fulfill({ status: 404, body: '' });
  });
  await page.goto('http://localhost/fixture');
  return { requests, release };
}

test('existing subscriptions refresh on every authenticated page load without requesting permission', async ({ page }) => {
  const { requests } = await fixture(page);
  await expect.poll(() => requests.length).toBe(1);
  await expect.poll(() => page.evaluate(async () => (await ChitChatPushState.read())?.binding)).toBe('binding-for-seven');
  await page.reload();
  await expect.poll(() => requests.length).toBe(2);
  expect(requests[0]).toEqual({ endpoint, p256dh: 'fixture', auth: 'fixture' });
  expect(await page.evaluate(() => pushCalls.unsubscribed)).toBe(0);
});

for (const identityPath of ['/api/v1/logout.php', '/api/v1/password-reset.php', '/api/v1/oidc/register.php']) test('offline identity change fences in-flight push: ' + identityPath, async ({ page }) => {
  const { requests, release } = await fixture(page, { pending: true });
  await expect.poll(() => requests.length).toBe(1);
  await page.evaluate(async (path) => {
    const { apiPost } = await import('/assets/js/api.js');
    await apiPost(path).catch(() => {});
  }, identityPath);
  expect(await page.evaluate(async () => (await ChitChatPushState.read())?.binding)).toBeNull();
  expect(await page.evaluate(() => pushCalls.unsubscribed)).toBe(1);
  expect(await page.evaluate(() => pushCalls.closed)).toBeGreaterThan(0);
  release();
  // Wait for the completed response, then a second state read proves it did not revive the binding.
  await expect.poll(() => page.evaluate(async () => (await ChitChatPushState.read())?.user_id)).toBeNull();
  await page.waitForFunction(() => performance.getEntriesByType('resource').some((entry) => entry.name.endsWith('/api/v1/push/subscribe.php')));
  expect(await page.evaluate(async () => (await ChitChatPushState.read())?.binding)).toBeNull();
});

test('binding mismatches and replayed event IDs are rejected in durable worker state', async ({ page }) => {
  await fixture(page);
  await expect.poll(() => page.evaluate(async () => (await ChitChatPushState.read())?.binding)).toBe('binding-for-seven');
  const result = await page.evaluate(async () => {
    const state = ChitChatPushState;
    return [
      await state.claim('event-1', 'another-login'),
      await state.claim('event-1', 'binding-for-seven'),
      await state.claim('event-1', 'binding-for-seven'),
      await state.claim('event-2', null),
      await state.claim('x'.repeat(65), 'binding-for-seven'),
    ];
  });
  expect(result).toEqual([false, true, false, false, false]);
  await page.evaluate(() => ChitChatPushState.clear());
  expect(await page.evaluate(() => ChitChatPushState.claim('event-3', 'binding-for-seven'))).toBe(false);
});
test('worker drops stale pushes, uses event tags and checks the current account before following links', async ({ page }) => {
  await fixture(page);
  await expect.poll(() => page.evaluate(async () => (await ChitChatPushState.read())?.binding)).toBe('binding-for-seven');
  const source = await readFile(new URL('../../public/sw.js', import.meta.url), 'utf8');
  const result = await page.evaluate(async ({ source, user }) => {
    const handlers = {};
    const shown = [];
    const opened = [];
    let session = user;
    let closed = 0;
    const worker = {
      location: { origin: location.origin },
      addEventListener: (name, handler) => { handlers[name] = handler; },
      registration: {
        showNotification: async (title, options) => { shown.push({ title, ...options }); },
        getNotifications: async () => [],
      },
      clients: { matchAll: async () => [], openWindow: async (link) => { opened.push(link); } },
    };
    new Function('self', 'importScripts', 'fetch', source)(worker, () => {},
      async () => ({ ok: true, json: async () => ({ user: session }) }));
    async function fire(name, event) {
      let work;
      handlers[name]({ ...event, waitUntil: (promise) => { work = promise; } });
      await work;
    }
    const payload = { title: 'New mention', body: 'Privacy-safe text', link: '/?room=2',
      event_id: 'worker-event-1', binding: 'binding-for-seven' };
    await fire('push', { data: { json: () => ({ ...payload, binding: 'old-binding' }) } });
    await fire('push', { data: { json: () => payload } });
    await fire('push', { data: { json: () => payload } });
    const notification = { data: shown[0].data, close: () => { ++closed; } };
    await fire('notificationclick', { notification });
    session = { ...user, id: 99 };
    await fire('notificationclick', { notification });
    await ChitChatPushState.clear();
    await fire('push', { data: { json: () => ({ ...payload, event_id: 'worker-event-2' }) } });
    return { shown, opened, closed };
  }, { source, user });
  expect(result.shown).toHaveLength(1);
  expect(result.shown[0].tag).toBe('worker-event-1');
  expect(result.opened).toEqual(['/?room=2', '/']);
  expect(result.closed).toBe(2);
});