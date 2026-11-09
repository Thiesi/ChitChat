import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S2a (#168): the signed-in shell. One navigation landmark that is a rail on
// wide screens and CCM's bottom bar on phones; Rooms · Direct · Activity ·
// You for members, Rooms for guests, Stewarding (in Dusk) for staff; the
// Activity badge read with its tab; no "Back to chat". Presentation only:
// real templates and modules against intercepted API responses.
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const room = {
  id: 1, key: 'room-1', name: 'The living room', unread_count: 0, last_read_message_id: 2, visibility: 'public',
  member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'read_write',
};
const messages = [1, 2].map((id) => ({
  id, room_id: 1, sender_id: 2, username: 'Rowan', body: `Hello ${id}`, type: 'text', deleted: false,
  mentions: [], reactions: [], reply_to: null, attachment: null, created_at: `2026-10-04T10:0${id}:00Z`,
}));

const PAGES = [
  { path: '/', tab: 'Rooms' },
  { path: '/messages.php', tab: 'Direct' },
  { path: '/notifications.php', tab: 'Activity' },
  { path: '/account.php', tab: 'You' },
  { path: '/search.php', tab: 'Rooms' },
  { path: '/admin.php', tab: 'Stewarding' },
  { path: '/admin-settings.php', tab: 'Stewarding' },
  { path: '/admin-status.php', tab: 'Stewarding' },
  { path: '/admin-messages.php', tab: 'Stewarding' },
  { path: '/admin-message-revisions.php', tab: 'Stewarding' },
  { path: '/moderation.php', tab: 'Stewarding' },
];

async function fixture(page, { user = member, roomRole = 'member', unread = 0 } = {}) {
  const requests = [];
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1/', '');
    requests.push(`${path}${url.search}`);
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'session.php') {
      return route.fulfill({ json: { user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], guest_access: true } });
    }
    const responses = {
      'rooms/list.php': { rooms: [{ ...room, member_role: roomRole }] },
      'rooms/messages.php': { messages },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 2 },
      'account/notifications/list.php': { notifications: [], unread_count: unread },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

const nav = (page) => page.getByRole('navigation', { name: 'Main' });
const tab = (page, name) => nav(page).getByRole('link', { name, exact: true });
/** The navigation's link names in order, as the accessibility tree reads them (visible links only). */
async function linkNames(page) {
  return (await nav(page).ariaSnapshot()).split('\n')
    .map((line) => /- link "([^"]+)"/.exec(line)?.[1])
    .filter(Boolean);
}

async function openRooms(page) {
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(2);
  await expect(nav(page)).toBeVisible();
}

test('members get Rooms · Direct · Activity · You, with the current page marked', async ({ page }) => {
  await fixture(page);
  await openRooms(page);
  expect(await linkNames(page)).toEqual(['Rooms', 'Direct', 'Activity', 'You']);
  await expect(tab(page, 'Rooms')).toHaveAttribute('aria-current', 'page');
  await expect(nav(page).locator('[aria-current]')).toHaveCount(1);
  await expect(tab(page, 'Direct')).toHaveAttribute('href', '/messages.php');
  await expect(tab(page, 'Activity')).toHaveAttribute('href', '/notifications.php');
  await expect(tab(page, 'You')).toHaveAttribute('href', '/account.php');
  // The navigation comes before the page's main landmark.
  expect(await page.evaluate(() => {
    const navNode = document.getElementById('app-nav');
    const main = document.getElementById('chat-shell');
    return Boolean(navNode.compareDocumentPosition(main) & Node.DOCUMENT_POSITION_FOLLOWING);
  })).toBe(true);
});

test('every shell page marks its own tab and has no "Back to chat"', async ({ page }) => {
  await fixture(page, { user: { ...member, roles: ['admin'] } });
  for (const { path, tab: name } of PAGES) {
    await page.goto(path);
    await expect(nav(page), path).toBeVisible();
    await expect(nav(page).locator('[aria-current="page"]'), path).toHaveAccessibleName(new RegExp(`^${name}`));
    await expect(page.getByRole('link', { name: /^Back to (chat|administration)$/ }), path).toHaveCount(0);
  }
});

test('the skip link is the first stop and moves past the navigation', async ({ page }) => {
  await fixture(page);
  await openRooms(page);
  await page.locator('body').focus();
  await page.keyboard.press('Tab');
  const skip = page.getByRole('link', { name: 'Skip to conversation' });
  await expect(skip).toBeFocused();
  await expect(skip).toBeInViewport();
  await page.keyboard.press('Enter');
  await expect(page.locator('#conversation')).toBeFocused();

  await page.goto('/account.php');
  await expect(nav(page)).toBeVisible();
  await page.locator('body').focus();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('#account-shell')).toBeFocused();
});

test('the Activity badge is read with its tab, from the page’s count or one summary request', async ({ page }) => {
  const requests = await fixture(page, { unread: 2 });
  await openRooms(page);
  await expect(tab(page, 'Activity, 2 unread')).toBeVisible();
  await expect(nav(page).locator('[data-badge="activity"]')).toHaveText('2');
  // On the Rooms page the page's own notifications code reports it: the shell doesn't ask again.
  await page.waitForTimeout(300);
  const summaries = () => requests.filter((entry) => entry.startsWith('account/notifications/list.php?limit=1')).length;
  const onRooms = summaries();

  await page.goto('/account.php');
  await expect(tab(page, 'Activity, 2 unread')).toBeVisible();
  expect(summaries()).toBe(onRooms + 1);
});

test('no unread notifications: no badge and a plain name', async ({ page }) => {
  await fixture(page, { unread: 0 });
  await openRooms(page);
  await expect(tab(page, 'Activity')).toBeVisible();
  await expect(nav(page).locator('[data-badge="activity"]')).toBeHidden();
});

for (const [label, options, destination] of [
  ['an administrator', { user: { ...member, roles: ['admin'] } }, '/admin.php'],
  ['a room owner', { roomRole: 'owner' }, '/admin.php'],
  ['a global moderator', { user: { ...member, roles: ['global_moderator'] } }, '/moderation.php'],
  ['a room moderator', { roomRole: 'moderator' }, '/moderation.php'],
]) {
  test(`Stewarding appears for ${label} and leads to ${destination}`, async ({ page }) => {
    await fixture(page, options);
    await openRooms(page);
    const steward = tab(page, 'Stewarding');
    await expect(steward).toBeVisible();
    await expect(steward).toHaveAttribute('href', destination);
  });
}

test('members without a staff role don’t see Stewarding', async ({ page }) => {
  await fixture(page);
  await openRooms(page);
  await page.waitForTimeout(300);
  await expect(tab(page, 'Stewarding')).toHaveCount(0);
  await expect(nav(page).locator('.app-nav-steward')).toBeHidden();
});

test('guests get Rooms and a short You', async ({ page }) => {
  await fixture(page, { user: { id: 9, username: 'Guest 4821', roles: [], session_version: 1, guest: true } });
  await openRooms(page);
  expect(await linkNames(page)).toEqual(['Rooms', 'You']);
});

test('signed out: no navigation and no skip link', async ({ page }) => {
  await page.route('**/api/v1/**', (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    return route.fulfill({ json: path === 'session.php' ? { user: null, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] } : {} });
  });
  await page.goto('/');
  await expect(page.locator('#auth-shell')).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Main' })).toHaveCount(0);
  await expect(page.locator('.skip-link')).toBeHidden();
});

test.describe('desktop and tablet: a rail', () => {
  for (const width of [1280, 900]) {
    test(`${width}px: the rail sits beside the page, items 44px or more`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width, height: 800 });
      await fixture(page, { user: { ...member, roles: ['admin'] }, unread: 3 });
      await openRooms(page);
      const box = await nav(page).boundingBox();
      expect(box.x).toBe(0);
      expect(box.height).toBeGreaterThanOrEqual(799);
      expect(box.width).toBeLessThanOrEqual(90);
      for (const link of await nav(page).getByRole('link').all()) {
        const item = await link.boundingBox();
        expect(item.width).toBeGreaterThanOrEqual(44);
        expect(item.height).toBeGreaterThanOrEqual(44);
      }
      // Stewarding sits at the bottom of the rail.
      const steward = await tab(page, 'Stewarding').boundingBox();
      expect(steward.y + steward.height).toBeGreaterThan(box.height - 120);
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      await page.screenshot({ path: testInfo.outputPath(`shell-${width}.png`) });
    });
  }
});

test.describe('phones: the bottom bar', () => {
  test.use({ viewport: { width: 360, height: 760 } });

  test('a bar at the bottom with the four tabs; the chat fits above it', async ({ page }, testInfo) => {
    await fixture(page, { user: { ...member, roles: ['admin'] }, unread: 2 });
    await openRooms(page);
    const box = await nav(page).boundingBox();
    expect(box.y + box.height).toBeCloseTo(760, 0);
    expect(box.width).toBe(360);
    await expect(tab(page, 'Activity, 2 unread')).toBeVisible();
    expect(await linkNames(page)).toEqual(['Rooms', 'Direct', 'Activity, 2 unread', 'You']);
    // Stewarding is in You on phones (S2b); not in the bar.
    await expect(tab(page, 'Stewarding')).toBeHidden();
    const shell = await page.locator('#chat-shell').boundingBox();
    expect(shell.y + shell.height).toBeLessThanOrEqual(box.y + 1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
    await page.screenshot({ path: testInfo.outputPath('shell-360.png') });
  });

  // On phones Stewarding is on You, for every kind of staff (the bar has no room for it).
  for (const [label, options, destination] of [
    ['an administrator', { user: { ...member, roles: ['admin'] } }, '/admin.php'],
    ['a global moderator', { user: { ...member, roles: ['global_moderator'] } }, '/moderation.php'],
    ['a room moderator', { roomRole: 'moderator' }, '/moderation.php'],
  ]) {
    test(`${label} reaches Stewarding from You`, async ({ page }) => {
      await fixture(page, options);
      await page.goto('/account.php');
      await nav(page).getByRole('link', { name: 'You', exact: true }).waitFor();
      const link = page.getByRole('link', { name: 'Open Stewarding' });
      await expect(link).toBeVisible();
      await expect(link).toHaveAttribute('href', destination);
    });
  }

  test('the bar leaves while typing and comes back after', async ({ page }) => {
    await fixture(page);
    await openRooms(page);
    await page.locator('#composer-input').focus();
    await expect(nav(page)).toBeHidden();
    const shell = await page.locator('#chat-shell').boundingBox();
    expect(shell.y + shell.height).toBeCloseTo(760, 0);
    await page.locator('#composer-input').blur();
    await expect(nav(page)).toBeVisible();
  });
});

// Every state the shell can be in, in both themes: desktop rail and phone
// bar, a badge, Stewarding, the current item and a focused item.
for (const theme of ['light', 'dark']) {
  for (const width of [1280, 360]) {
    test(`${theme}, ${width}px: the shell passes axe and reflows`, async ({ page }) => {
      await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
      await page.setViewportSize({ width, height: 760 });
      await fixture(page, { user: { ...member, roles: ['admin'] }, unread: 12 });
      await openRooms(page);
      await expect(nav(page).locator('[data-badge="activity"]')).toBeVisible();
      await tab(page, 'Direct').focus();
      const result = await new AxeBuilder({ page }).include('#app-nav').include('.skip-link').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
      expect(result.violations).toEqual([]);

      await page.goto('/admin.php');
      await expect(nav(page)).toBeVisible();
      const steward = await new AxeBuilder({ page }).include('#app-nav').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
      expect(steward.violations).toEqual([]);
    });
  }
}

test('320px: the shell reflows without horizontal scrolling', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await fixture(page, { unread: 120 });
  await openRooms(page);
  await expect(nav(page).locator('[data-badge="activity"]')).toHaveText('99+');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
