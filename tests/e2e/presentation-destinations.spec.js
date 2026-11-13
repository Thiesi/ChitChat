import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S2b (#168): menus become destinations. The bell popover and the account
// menu are gone; Activity carries the notification actions (open, mark one,
// mark all), You carries what the menu held (name and picture, alerts with
// Sounds, appearance, Stewarding for staff, Sign out), and guests get a
// short You with Leave. Presentation only: real pages and modules against
// intercepted API responses.
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const guest = { id: 9, username: 'Guest 4821', roles: [], session_version: 1, guest: true };
const room = {
  id: 1, key: 'room-1', name: 'The living room', unread_count: 0, last_read_message_id: 1, visibility: 'public',
  member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'read_write',
};
const notifications = [
  // Real kinds since S4c, which picks the mark and the action label by kind.
  { id: 31, kind: 'mentioned', title: 'You were mentioned', message: 'Rowan mentioned you in “The living room”.', link: '/?room=1&message=7', read: false, created_at: '2026-10-04T10:05:00Z' },
  { id: 30, kind: 'pinged', title: 'Priya pinged you', message: 'Priya pinged you in “The living room”.', link: '/?room=1&message=5', read: false, created_at: '2026-10-04T09:58:00Z' },
  { id: 29, kind: 'moderator_message_deleted', title: 'Your message was removed by a moderator', message: 'A moderator removed one of your messages.', read: true, created_at: '2026-10-03T18:00:00Z' },
];
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

async function fixture(page, { user = member, roomRole = 'member' } = {}) {
  const calls = [];
  let unread = notifications.filter((item) => !item.read).length;
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') calls.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'logout.php') return route.fulfill({ json: { signed_out: true } });
    if (path === 'account/notifications/read.php') {
      unread = 0;
      return route.fulfill({ json: { unread_count: 0 } });
    }
    if (user.guest && /^(account|push|direct-messages)\//.test(path)) {
      return route.fulfill({ status: 403, json: { error: { code: 'guest_not_allowed', message: 'Create an account to use this.' } } });
    }
    const responses = {
      'session.php': { user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], guest_access: true, web_push: { enabled: false } },
      'rooms/list.php': { rooms: [{ ...room, member_role: roomRole }] },
      'rooms/messages.php': { messages: [] },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 1 },
      'account/notifications/list.php': { notifications: url.searchParams.get('limit') === '1' ? notifications.slice(0, 1) : notifications, unread_count: unread },
      'direct-messages/conversations.php': { conversations: [] },
      'push/preferences.php': { preferences: { mentioned_push_enabled: true, pinged_push_enabled: true, quiet_hours: null }, devices: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return calls;
}

const nav = (page) => page.getByRole('navigation', { name: 'Main' });

test('the chat header has no bell and no account menu; members and search stay', async ({ page }) => {
  await fixture(page);
  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.getByRole('button', { name: /^Notifications/ })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Account menu' })).toHaveCount(0);
  await expect(page.locator('#members-toggle')).toBeVisible();
  await expect(page.locator('.room-header').getByRole('link', { name: /^Search (messages|# )/ })).toBeVisible();
  // The rail's Activity badge still follows the count.
  await expect(nav(page).getByRole('link', { name: 'Activity, 2 unread' })).toBeVisible();
});

test('Activity: opening an unread item marks it read on the way; one or all can be marked', async ({ page }) => {
  const calls = await fixture(page);
  await page.goto('/notifications.php');
  await expect(page.getByRole('heading', { level: 1, name: 'Activity' })).toBeVisible();
  // Alert settings moved to You.
  await expect(page.locator('#push-heading')).toHaveCount(0);
  // S4c: the feed's items sit inside day groups.
  const items = page.locator('#privacy-notifications-list .privacy-notification');
  await expect(items).toHaveCount(3);
  await expect(items.nth(0).getByRole('button', { name: 'Mark as read' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Mark all as read' })).toBeEnabled();

  await page.route('**/?room=1&message=7', (route) => route.fulfill({ contentType: 'text/html', body: '<title>Opened</title><p>Opened</p>' }));
  await items.nth(0).getByRole('link', { name: 'View message' }).click();
  await expect(page).toHaveURL(/\/\?room=1&message=7$/);
  expect(calls.find((call) => call.path === 'account/notifications/read.php')?.body).toEqual({ ids: [31] });
});

test('You: picture and name, alerts with Sounds, appearance, and Sign out last', async ({ page }) => {
  await fixture(page);
  await page.goto('/account.php');
  await expect(page.locator('#account-identity')).toHaveText('Signed in as Jamie');
  await expect(page.locator('#you-avatar')).toHaveText('JA');
  // S5a: one section at a time; Alerts and Appearance are sections of their own.
  await page.getByRole('navigation', { name: 'Settings' }).getByRole('link', { name: 'Alerts', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Alerts' })).toBeVisible();
  const sounds = page.getByRole('group', { name: 'Sounds on this device' });
  await expect(sounds.getByRole('checkbox')).toHaveCount(3);
  await sounds.getByRole('checkbox', { name: 'Mentions' }).uncheck();
  expect(await page.evaluate(() => JSON.parse(localStorage.getItem('chitchat.sounds')))).toMatchObject({ mention: false });
  await page.getByRole('navigation', { name: 'Settings' }).getByRole('link', { name: 'Appearance', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Theme and colours' })).toBeVisible();
  // Sign out is the last row of the list pane, after the sections (S5a).
  await expect(page.locator('#you-list > :last-child').getByRole('button', { name: 'Sign out' })).toBeVisible();
  await expect(page.locator('#you-steward-card')).toBeHidden();
  // The guest invitation and Leave are for guests only.
  await expect(page.getByRole('heading', { name: 'Want to stay?' })).toBeHidden();
  await expect(page.getByRole('button', { name: 'Leave' })).toHaveCount(0);
});

test('signing out from You ends the session and says so on the sign-in screen', async ({ page }) => {
  const calls = await fixture(page);
  await page.goto('/account.php');
  // The session has ended: the Rooms page now opens signed out.
  await page.route('**/api/v1/session.php', (route) => route.fulfill({ json: { user: null, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] } }));
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/\/$/);
  expect(calls.some((call) => call.path === 'logout.php')).toBe(true);
  await expect(page.locator('#auth-shell')).toBeVisible();
  await expect(page.locator('#auth-notice')).toHaveText('You have been signed out.');
});

for (const width of [1280, 360]) {
  test(`${width}px: Sign out is two steps from every page`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await fixture(page);
    for (const path of ['/', '/messages.php', '/notifications.php', '/search.php']) {
      await page.goto(path);
      const you = nav(page).getByRole('link', { name: 'You', exact: true });
      await expect(you, path).toBeVisible();
      await you.click();
      await expect(page.getByRole('button', { name: 'Sign out' }), path).toBeVisible();
    }
  });
}

test('staff find Stewarding on You', async ({ page }) => {
  await fixture(page, { user: { ...member, roles: ['admin'] } });
  await page.goto('/account.php');
  // S5a: the list's first row.
  await expect(page.getByRole('link', { name: 'Looking after this server' })).toHaveAttribute('href', '/admin.php');
});

test('guests get a short You: their name, appearance, an invitation, and Leave', async ({ page }) => {
  const calls = await fixture(page, { user: guest });
  await page.goto('/account.php');
  await expect(page.locator('#account-identity')).toHaveText('Visiting as Guest 4821');
  // S5a: the invitation in the list pane, Appearance's theme card in the main pane;
  // S5b: and the device-only Accessibility card (guests hear announcements too).
  const visible = page.locator('#you-frame .account-card:visible');
  await expect(visible).toHaveCount(3);
  await expect(page.getByRole('heading', { name: 'Want to stay?' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Create an account' })).toHaveAttribute('href', '/?register=1');
  await expect(page.getByRole('heading', { name: 'Theme and colours' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Accessibility' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Sign out' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Leave' }).click();
  await expect(page).toHaveURL(/\/$/);
  expect(calls.some((call) => call.path === 'logout.php')).toBe(true);
});

// Every state of the new destinations, in both themes and at phone width.
for (const theme of ['light', 'dark']) {
  test(`${theme}: You, guest You and Activity pass axe at 1280 and 360 px, and reflow at 320`, async ({ page }, testInfo) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page, { user: { ...member, roles: ['admin'] } });
    for (const width of [1280, 360]) {
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/account.php');
      await expect(page.locator('#you-steward-card')).toBeVisible();
      await page.getByRole('button', { name: 'Sign out' }).focus();
      const you = await new AxeBuilder({ page }).include('#you-frame').withTags(WCAG).analyze();
      expect(you.violations, `You ${width}`).toEqual([]);
      await page.screenshot({ path: testInfo.outputPath(`you-${theme}-${width}.png`), fullPage: true });

      await page.goto('/notifications.php');
      await expect(page.locator('#privacy-notifications-list .privacy-notification')).toHaveCount(3);
      const activity = await new AxeBuilder({ page }).include('#privacy-notifications-shell').withTags(WCAG).analyze();
      expect(activity.violations, `Activity ${width}`).toEqual([]);
      await page.screenshot({ path: testInfo.outputPath(`activity-${theme}-${width}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: 320, height: 700 });
    for (const path of ['/account.php', '/notifications.php']) {
      await page.goto(path);
      await page.waitForTimeout(300);
      expect(await page.evaluate(() => document.documentElement.scrollWidth), path).toBeLessThanOrEqual(320);
    }
  });

  test(`${theme}: guest You passes axe`, async ({ page }, testInfo) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page, { user: guest });
    await page.goto('/account.php');
    await expect(page.getByRole('button', { name: 'Leave' })).toBeVisible();
    const result = await new AxeBuilder({ page }).include('#you-frame').withTags(WCAG).analyze();
    expect(result.violations).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath(`you-guest-${theme}.png`), fullPage: true });
  });
}
