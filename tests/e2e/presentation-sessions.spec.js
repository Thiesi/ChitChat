import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// #205 on the web: "Where you're signed in" on You, and a revoked login's
// own message ("You were signed out from another device") whether it
// arrives as HTTP 401 session_revoked or the stream's forced_logout.
// Presentation only: real pages and modules against intercepted API responses.
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const REVOKED = 'You were signed out from another device. Sign in again.';
const minutesAgo = (minutes) => new Date(Date.now() - minutes * 60_000).toISOString();
const sessions = () => [
  { id: 'a'.repeat(43), current: true, created_at: '2026-11-01T09:00:00+00:00', last_active_at: minutesAgo(1), client_hint: 'Firefox on Windows', device_label: null },
  { id: 'b'.repeat(43), current: false, created_at: '2026-10-20T18:30:00+00:00', last_active_at: minutesAgo(42), client_hint: 'ChitChat app on Android', device_label: 'Pixel 8' },
  { id: 'c'.repeat(43), current: false, created_at: '2026-09-02T07:15:00+00:00', last_active_at: minutesAgo(60 * 24 * 9), client_hint: 'Safari on macOS', device_label: null },
];

async function stubEventSource(page) {
  await page.addInitScript(() => {
    class FakeEventSource extends EventTarget {
      constructor(url) {
        super();
        this.url = url;
        this.readyState = 1;
        (window.__sources ??= []).push(this);
        window.setTimeout(() => this.dispatchEvent(new Event('open')), 0);
      }

      close() { this.readyState = 2; }
    }
    window.EventSource = FakeEventSource;
    window.__emitRaw = (type, data) => {
      for (const source of window.__sources ?? []) {
        if (source.readyState !== 2) source.dispatchEvent(new MessageEvent(type, { data: JSON.stringify(data) }));
      }
    };
  });
}

async function fixture(page, { user = member, capability = true, revoke = null, roomsStatus = null } = {}) {
  const calls = [];
  const state = { list: sessions(), signedIn: true };
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') calls.push({ path, body: request.postDataJSON?.() ?? null });
    else calls.push({ path });
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'session.php') {
      return route.fulfill({ json: {
        user: state.signedIn ? user : null, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [],
        capabilities: capability ? { signed_in_sessions: true } : {}, web_push: { enabled: false },
      } });
    }
    if (path === 'rooms/list.php' && roomsStatus) {
      state.signedIn = false;
      return route.fulfill({ status: roomsStatus.status, json: { error: roomsStatus.error } });
    }
    if (path === 'account/sessions/list.php') return route.fulfill({ json: { sessions: state.list, maximum_sessions: 50 } });
    if (path === 'account/sessions/revoke.php') {
      const id = request.postDataJSON().id;
      if (revoke) return route.fulfill(revoke);
      state.list = state.list.filter((entry) => entry.id !== id);
      return route.fulfill({ json: { revoked: true } });
    }
    if (path === 'account/sessions/revoke-others.php') {
      const before = state.list.length - 1;
      state.list = state.list.filter((entry) => entry.current);
      return route.fulfill({ json: { revoked_count: before } });
    }
    const responses = {
      'rooms/list.php': { rooms: [] },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
      'direct-messages/history.php': { messages: [] },
      'push/preferences.php': { preferences: { mentioned_push_enabled: true, pinged_push_enabled: true, quiet_hours: null }, devices: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return { calls, state };
}

const card = (page) => page.locator('#sessions-card');
const rows = (page) => page.locator('#sessions-list > li');

test('the card lists sign-ins with this browser first, and no Sign out for it', async ({ page }) => {
  await fixture(page);
  await page.goto('/account.php');
  await expect(card(page).getByRole('heading', { name: 'Where you’re signed in' })).toBeVisible();
  await expect(rows(page)).toHaveCount(3);
  await expect(rows(page).nth(0)).toContainText('Firefox on Windows');
  await expect(rows(page).nth(0)).toContainText('This browser');
  await expect(rows(page).nth(0)).toContainText('Active in the last few minutes');
  await expect(rows(page).nth(0).getByRole('button')).toHaveCount(0);
  // A device label leads; the client hint follows it.
  await expect(rows(page).nth(1)).toContainText('Pixel 8');
  await expect(rows(page).nth(1)).toContainText('ChitChat app on Android · Signed in');
  await expect(rows(page).nth(1)).toContainText('Last active 42 minutes ago');
  await expect(rows(page).nth(1).getByRole('button', { name: 'Sign out Pixel 8' })).toBeVisible();
  await expect(card(page)).not.toContainText(/online/i);
  await expect(card(page)).toContainText('Up to 50 sign-ins are kept');
  await expect(page.getByRole('button', { name: 'Sign out everywhere else' })).toBeVisible();
});

test('without the server capability there is no card', async ({ page }) => {
  const { calls } = await fixture(page, { capability: false });
  await page.goto('/account.php');
  await expect(page.locator('#account-identity')).toHaveText('Signed in as Jamie');
  await expect(card(page)).toBeHidden();
  expect(calls.some((call) => call.path === 'account/sessions/list.php')).toBe(false);
});

test('signing out one sign-in removes it and keeps focus in the list', async ({ page }) => {
  const { calls } = await fixture(page);
  await page.goto('/account.php');
  await rows(page).nth(1).getByRole('button', { name: 'Sign out Pixel 8' }).click();
  await expect(rows(page)).toHaveCount(2);
  await expect(page.locator('#sessions-status')).toHaveText('Signed out Pixel 8.');
  expect(calls.find((call) => call.path === 'account/sessions/revoke.php')?.body).toEqual({ id: 'b'.repeat(43) });
  await expect(page.getByRole('button', { name: 'Sign out Safari on macOS' })).toBeFocused();
});

test('only session_not_found means the sign-in is gone; other errors keep the row and show the server', async ({ page }) => {
  await fixture(page, { revoke: { status: 404, json: { error: { code: 'session_not_found', message: 'That sign-in was not found.' } } } });
  await page.goto('/account.php');
  await rows(page).nth(1).getByRole('button').click();
  await expect(page.locator('#sessions-status')).toHaveText('That sign-in had already ended.');
  await expect(rows(page)).toHaveCount(2);
});

test('a malformed request keeps the row and shows the server’s message', async ({ page }) => {
  await fixture(page, { revoke: { status: 400, json: { error: { code: 'validation_error', message: 'That sign-in ID is not valid.' } } } });
  await page.goto('/account.php');
  await rows(page).nth(2).getByRole('button').click();
  await expect(page.locator('#sessions-error')).toHaveText('That sign-in ID is not valid.');
  await expect(rows(page)).toHaveCount(3);
});

test('signing out everywhere else leaves this browser and says how many', async ({ page }) => {
  await fixture(page);
  await page.goto('/account.php');
  await page.getByRole('button', { name: 'Sign out everywhere else' }).click();
  await expect(page.locator('#sessions-status')).toHaveText('Signed out everywhere else (2 sign-ins).');
  await expect(rows(page)).toHaveCount(1);
  await expect(page.getByRole('button', { name: 'Sign out everywhere else' })).toBeHidden();
  await expect(card(page).getByRole('heading', { name: 'Where you’re signed in' })).toBeFocused();
});

test('guests have no card and ask for no list', async ({ page }) => {
  const { calls } = await fixture(page, { user: { id: 9, username: 'Guest 4821', roles: [], session_version: 1, guest: true } });
  await page.goto('/account.php');
  await expect(page.getByRole('button', { name: 'Leave' })).toBeVisible();
  await expect(card(page)).toBeHidden();
  expect(calls.some((call) => call.path === 'account/sessions/list.php')).toBe(false);
});

test('a revoked login’s 401 says “signed out from another device” on the sign-in screen', async ({ page }) => {
  await stubEventSource(page);
  await fixture(page, { roomsStatus: { status: 401, error: { code: 'session_revoked', message: REVOKED } } });
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await expect(page.locator('#auth-notice')).toHaveText(REVOKED);
});

test('the stream’s forced_logout with session_revoked says the same, on Rooms', async ({ page }) => {
  await stubEventSource(page);
  await fixture(page);
  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  await page.evaluate(() => window.__emitRaw('forced_logout', { reason: 'session_revoked' }));
  await expect(page.locator('#auth-shell')).toBeVisible();
  await expect(page.locator('#auth-notice')).toHaveText(REVOKED);
});

test('on Direct, forced_logout with session_revoked leads to the sign-in screen with that line', async ({ page }) => {
  await stubEventSource(page);
  const { state } = await fixture(page);
  await page.goto('/messages.php');
  await expect(page.locator('#messages-shell')).toBeVisible();
  state.signedIn = false;
  await page.evaluate(() => window.__emitRaw('forced_logout', { reason: 'session_revoked' }));
  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator('#auth-shell')).toBeVisible();
  await expect(page.locator('#auth-notice')).toHaveText(REVOKED);
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: the card passes axe at 1280 and 360 px with a row focused`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page);
    for (const width of [1280, 360]) {
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/account.php');
      await expect(rows(page)).toHaveCount(3);
      await rows(page).nth(1).getByRole('button').focus();
      const result = await new AxeBuilder({ page }).include('#sessions-card').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
      expect(result.violations, `${width}`).toEqual([]);
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    }
  });
}
