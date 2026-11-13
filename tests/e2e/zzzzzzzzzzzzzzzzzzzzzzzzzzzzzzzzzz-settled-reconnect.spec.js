import { expect, test } from '@playwright/test';
import { attemptName, attemptText } from './support/attempt.js';
import { openRoom } from './support/rooms.js';

// #286: the web client resumes the event stream with resume=settled. Across a dropped
// connection, every message is shown once and counted once in the unread badge.
//
// Playwright can't cut a response that is already streaming, so the journey uses the
// server's own end of each stream (after 25 s) and holds the browser's reconnect while
// another account writes: the member is offline for that time. (It doesn't abort the
// reconnect: Firefox then closes the EventSource for good instead of retrying.)
//
// Chromium adds Last-Event-ID below the layer Playwright routes, so the header is only
// asserted in browsers that show it to the route.

const baseURL = process.env.CHITCHAT_BASE_URL ?? 'http://127.0.0.1:8080';
const root = {
  username: 'RootE2E',
  password: 'Correct Horse Battery Staple 2026!',
};
const member = {
  username: 'MemberE2E',
  password: 'Another Correct Horse Battery Staple 2026!',
};

async function login(page, account) {
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await page.locator('#login-username').fill(account.username);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.locator('#chat-shell')).toBeVisible();
}

async function send(page, text) {
  await page.locator('#composer-input').fill(text);
  await page.locator('#composer-input').press('Enter');
  await expect(page.locator('.message-body', { hasText: text })).toBeVisible();
}

test('messages sent while the event stream reconnects are shown once and counted once', async ({ browser, browserName }) => {
  test.setTimeout(150_000);
  const rootContext = await browser.newContext({ baseURL });
  const memberContext = await browser.newContext({ baseURL });
  // "… room" so the first attempt's name isn't a prefix of a retry's.
  const roomName = `Reconnect ${attemptName('Porch')} room`;

  // Every stream request the member's page makes. Once armed, the next reconnect waits for
  // the gate (the first stream may already have ended while the rooms were set up).
  const streams = [];
  let armed = false;
  let releaseHeld;
  const held = new Promise((resolve) => { releaseHeld = resolve; });
  await memberContext.route('**/api/v1/events/stream.php*', async (route) => {
    const request = route.request();
    const entry = { url: new URL(request.url()), lastEventId: request.headers()['last-event-id'] ?? null, outcome: 'continued' };
    streams.push(entry);
    if (armed && !streams.some((stream) => stream.outcome === 'held' || stream.outcome === 'resumed')) {
      entry.outcome = 'held';
      await held;
      entry.outcome = 'resumed';
    }
    return route.continue();
  });

  try {
    const rootPage = await rootContext.newPage();
    await login(rootPage, root);
    await rootPage.locator('#new-room-button').click();
    const roomDialog = rootPage.locator('#room-dialog');
    await roomDialog.locator('#room-key').fill(attemptName('reconnect-porch-e2e').toLowerCase());
    await roomDialog.locator('#room-name').fill(roomName);
    await roomDialog.getByRole('button', { name: 'Create room' }).click();
    await expect(rootPage.locator('#room-title')).toHaveText(`# ${roomName}`);

    // The member joins, then looks at another room, so new messages there are unread.
    const memberPage = await memberContext.newPage();
    await login(memberPage, member);
    const room = memberPage.locator('.room-button', { hasText: roomName });
    await openRoom(memberPage, roomName);
    const joined = memberPage.waitForResponse((response) => response.url().includes('/api/v1/rooms/join.php'));
    const refreshed = memberPage.waitForResponse((response) => response.url().includes('/api/v1/rooms/list.php'));
    await memberPage.locator('#join-button').click();
    expect((await joined).ok()).toBe(true);
    expect((await refreshed).ok()).toBe(true);
    await expect(memberPage.locator('#composer-input')).toBeVisible();
    const elsewhere = await memberPage.locator('.room-button').filter({ hasNotText: roomName }).first().locator('.room-name').textContent();
    await openRoom(memberPage, elsewhere.replace(/^#\s*/u, '').trim());
    await expect(room).not.toHaveClass(/active/);

    const before = attemptText('Live before the drop');
    await send(rootPage, before);
    await expect(room.locator('.unread-count')).toHaveText('1 unread');

    // The server ends the stream within 25 s; the browser's reconnect then waits.
    armed = true;
    await expect.poll(() => streams.some((stream) => stream.outcome === 'held'), { timeout: 60_000 }).toBe(true);
    const gap = [attemptText('Sent while offline one'), attemptText('Sent while offline two')];
    for (const text of gap) await send(rootPage, text);
    releaseHeld();
    await expect(room.locator('.unread-count')).toHaveText('3 unread');

    const after = attemptText('Sent after the reconnect');
    await send(rootPage, after);
    await expect(room.locator('.unread-count')).toHaveText('4 unread');

    // Every request asked to resume settled; the held reconnect, from a stored position.
    const resumed = streams.find((stream) => stream.outcome === 'resumed');
    expect(resumed).toBeDefined();
    expect(streams.indexOf(resumed)).toBeGreaterThan(0);
    for (const stream of streams) expect(stream.url.searchParams.get('resume')).toBe('settled');
    if (browserName !== 'chromium') expect(resumed.lastEventId).toMatch(/^\d+$/u);

    // Nothing arrives twice afterwards either.
    await memberPage.waitForTimeout(2_500);
    await expect(room.locator('.unread-count')).toHaveText('4 unread');
    await openRoom(memberPage, roomName);
    for (const text of [before, ...gap, after]) {
      await expect(memberPage.locator('#message-list .message-body', { hasText: text })).toHaveCount(1);
    }
  } finally {
    releaseHeld();
    await rootContext.close();
    await memberContext.close();
  }
});
