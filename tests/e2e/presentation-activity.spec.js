import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S4c (#170, the addendum's Activity table, comment 6815158178): one feed
// with day headings; each kind has its mark, leads with the server's own
// words, and opens its target (marking it read on the way). Unread items
// carry a dot and say "Unread" to screen readers; Mark as read keeps the
// item's link; Load older continues the last day; the empty feed offers a
// way back. Presentation only: the real page against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };

const at = (daysAgo, hour = 10) => {
  const date = new Date();
  date.setDate(date.getDate() - daysAgo);
  date.setHours(hour, 5, 0, 0);
  return date.toISOString();
};
// The server's own words for each kind (PrivacyNotificationService::renderText).
const FEED = () => [
  { id: 60, kind: 'mentioned', title: 'You were mentioned', message: 'Jonas mentioned you in “Board games night”.', details: [], link: '/?room_id=4&message_id=77', read: false, created_at: at(0, 11) },
  { id: 59, kind: 'pinged', title: 'Rowan pinged you', message: 'Rowan pinged you in “Garden”.', details: [], link: '/?room_id=5&ping_id=3', read: false, created_at: at(0, 9) },
  { id: 58, kind: 'direct_message_received', title: 'New direct message', message: 'Rowan sent you a direct message.', details: [], link: '/messages.php?with=2', read: true, created_at: at(1) },
  { id: 57, kind: 'username_change_required', title: 'Choose a new username', message: 'A moderator replaced your username because: “Offensive”. Choose a new one to keep writing; you can still read.', details: [], link: '/choose-username.php', read: false, created_at: at(1) },
  { id: 56, kind: 'avatar_removed', title: 'Profile picture removed by a moderator', message: 'A moderator removed your profile picture.', details: [], link: '/account.php', read: true, created_at: at(1) },
  { id: 55, kind: 'moderator_message_deleted', title: 'Message deleted by a moderator', message: 'A moderator deleted one of your messages in “Garden”.', details: [], link: null, read: true, created_at: at(3) },
  { id: 54, kind: 'room_deleted', title: 'A room you belong to was deleted', message: '“Attic” was deleted.', details: [], link: null, read: true, created_at: at(3) },
  { id: 53, kind: 'room_restored', title: 'A room you belong to was restored', message: '“Attic” was restored and is available again.', details: [], link: '/?room_id=6', read: true, created_at: at(3) },
  { id: 52, kind: 'system_policy_changed', title: 'Installation policy changed', message: 'A Super-Administrator changed one or more installation policies.', details: ['Guest access: on'], link: null, read: true, created_at: at(20) },
];

async function fixture(page, { feed = FEED() } = {}) {
  const posts = [];
  let items = feed;
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') posts.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'session.php') return route.fulfill({ json: { user: member, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] } });
    if (path === 'account/notifications/read.php') {
      const body = request.postDataJSON();
      items = items.map((item) => (body.all || body.ids?.includes(item.id) ? { ...item, read: true } : item));
      return route.fulfill({ json: { updated: 1, unread_count: items.filter((item) => !item.read).length } });
    }
    if (path === 'account/notifications/list.php') {
      const before = Number(url.searchParams.get('before_id') ?? Infinity);
      const limit = Number(url.searchParams.get('limit') ?? 25);
      const page = items.filter((item) => item.id < before).slice(0, limit);
      return route.fulfill({ json: { notifications: page, unread_count: items.filter((item) => !item.read).length } });
    }
    const responses = { 'direct-messages/conversations.php': { conversations: [] } };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return posts;
}

const feed = (page) => page.getByRole('list', { name: 'Notifications' });
const item = (page, id) => page.locator(`.privacy-notification[data-notification-id="${id}"]`);

async function open(page, width = 1280) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto('/notifications.php');
  await expect(page.locator('#privacy-notifications-shell')).toBeVisible();
}

async function axe(page, label) {
  await page.mouse.move(0, 0);
  await page.waitForTimeout(200);
  const result = await new AxeBuilder({ page }).include('#privacy-notifications-shell').withTags(WCAG).analyze();
  expect(result.violations, `${label}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
}

test('day headings like the transcript: Today, Yesterday, a weekday, then the date', async ({ page }) => {
  await fixture(page);
  await open(page);
  const headings = feed(page).getByRole('heading', { level: 3 });
  await expect(headings).toHaveCount(4);
  await expect(headings.nth(0)).toHaveText('Today');
  await expect(headings.nth(1)).toHaveText('Yesterday');
  await expect(headings.nth(2)).toHaveText(new Intl.DateTimeFormat('en-US', { weekday: 'long' }).format(new Date(Date.now() - 3 * 86_400_000)));
  await expect(headings.nth(3)).not.toHaveText(/Today|Yesterday/);
  // Each day is a list named by its heading.
  await expect(page.getByRole('list', { name: 'Today' }).locator('.privacy-notification')).toHaveCount(2);
  await expect(page.getByRole('list', { name: 'Yesterday' }).locator('.privacy-notification')).toHaveCount(3);
});

test('every kind in the table has its mark and leads with the server’s words', async ({ page }) => {
  await fixture(page);
  await open(page);
  const expectations = [
    [60, 'mention', 'Jonas mentioned you in “Board games night”.', 'View message'],
    [59, 'bell', 'Rowan pinged you in “Garden”.', 'View ping'],
    [58, 'pebble', 'Rowan sent you a direct message.', 'Open conversation'],
    [57, 'shield', 'Choose a new username', 'Choose a username'],
    [56, 'shield', 'Profile picture removed by a moderator', 'Open You'],
    [55, 'shield', 'Message deleted by a moderator', null],
    [54, 'lantern', '“Attic” was deleted.', null],
    [53, 'lantern', '“Attic” was restored and is available again.', 'Open room'],
    [52, 'info', 'Installation policy changed', null],
  ];
  for (const [id, icon, lead, action] of expectations) {
    await expect(item(page, id).locator(`.activity-icon-${icon}`), `${id}`).toHaveCount(1);
    await expect(item(page, id).locator('.activity-lead'), `${id}`).toHaveText(new RegExp(`^(Unread: )?${lead.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
    const link = item(page, id).locator('.privacy-notification-actions a');
    if (action) await expect(link, `${id}`).toHaveText(action);
    else await expect(link, `${id}`).toHaveCount(0);
  }
  // A conversation kind keeps its generic title as a small label; others keep their message and details.
  await expect(item(page, 60).locator('.activity-kind')).toHaveText('You were mentioned');
  await expect(item(page, 52).locator('.activity-message')).toHaveText('A Super-Administrator changed one or more installation policies.');
  await expect(item(page, 52).locator('.privacy-notification-details li')).toHaveText(['Guest access: on']);
  // The pebble takes the person's hue from the server's own link.
  await expect(item(page, 58).locator('.activity-icon-pebble')).toHaveAttribute('data-hue', '2');
});

for (const [id, target] of [[60, '/?room_id=4&message_id=77'], [59, '/?room_id=5&ping_id=3'], [57, '/choose-username.php']]) {
  test(`opening unread item ${id} marks it read and goes to ${target}`, async ({ page }) => {
    const posts = await fixture(page);
    await page.route(`**${target}`, (route) => route.fulfill({ contentType: 'text/html', body: '<title>Opened</title><p>Opened</p>' }));
    await open(page);
    await item(page, id).locator('.privacy-notification-actions a').click();
    await expect(page).toHaveURL(new RegExp(`${target.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
    expect(posts.find((entry) => entry.path === 'account/notifications/read.php')?.body).toEqual({ ids: [id] });
  });
}

test('a read item opens its target without a read call', async ({ page }) => {
  const posts = await fixture(page);
  await page.route('**/messages.php?with=2', (route) => route.fulfill({ contentType: 'text/html', body: '<title>Opened</title>' }));
  await open(page);
  await item(page, 58).getByRole('link', { name: 'Open conversation' }).click();
  await expect(page).toHaveURL(/\/messages\.php\?with=2$/);
  expect(posts.some((entry) => entry.path === 'account/notifications/read.php')).toBe(false);
});

test('unread items carry a dot and say "Unread"; Mark as read keeps the link', async ({ page }) => {
  const posts = await fixture(page);
  await open(page);
  const mention = item(page, 60);
  await expect(mention).toHaveClass(/privacy-notification-unread/);
  await expect(mention.locator('.activity-lead')).toHaveText('Unread: Jonas mentioned you in “Board games night”.');
  // Actions are described by the item's words, so "View message" is never ambiguous.
  await expect(mention.getByRole('link', { name: 'View message' })).toHaveAccessibleDescription('Unread: Jonas mentioned you in “Board games night”.');
  await mention.getByRole('button', { name: 'Mark as read' }).click();
  await expect.poll(() => posts.find((entry) => entry.path === 'account/notifications/read.php')?.body).toEqual({ ids: [60] });
  await expect(mention).not.toHaveClass(/privacy-notification-unread/);
  await expect(mention.locator('.activity-lead')).toHaveText('Jonas mentioned you in “Board games night”.');
  await expect(mention.getByRole('link', { name: 'View message' })).toBeFocused();
  await expect(page.locator('#privacy-notifications-status')).toHaveText('2 unread notifications.');
});

test('Mark all as read clears every dot and keeps the links', async ({ page }) => {
  const posts = await fixture(page);
  await open(page);
  await page.getByRole('button', { name: 'Mark all as read' }).click();
  await expect.poll(() => posts.find((entry) => entry.path === 'account/notifications/read.php')?.body).toEqual({ all: true });
  await expect(page.locator('.privacy-notification-unread')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Mark as read' })).toHaveCount(0);
  await expect(item(page, 57).getByRole('link', { name: 'Choose a username' })).toBeVisible();
  await expect(page.locator('#privacy-notifications-status')).toHaveText('You have no unread notifications.');
});

test('Load older continues the last day instead of repeating its heading', async ({ page }) => {
  // 3 today and 24 yesterday: the first page of 25 ends inside Yesterday, the second finishes it.
  const long = Array.from({ length: 27 }, (_, index) => ({
    id: 200 - index, kind: 'pinged', title: 'Rowan pinged you', message: `Rowan pinged you in “Garden” (${index}).`,
    details: [], link: '/?room_id=5&ping_id=3', read: true, created_at: index < 3 ? at(0, 12 - index) : at(1, 22 - Math.floor(index / 2)),
  }));
  await fixture(page, { feed: long });
  await open(page);
  await expect(page.locator('.privacy-notification')).toHaveCount(25);
  await expect(feed(page).getByRole('heading', { level: 3 })).toHaveText(['Today', 'Yesterday']);
  await page.getByRole('button', { name: 'Load older notifications' }).click();
  await expect(page.locator('.privacy-notification')).toHaveCount(27);
  await expect(page.getByRole('list', { name: 'Yesterday' }).locator('.privacy-notification')).toHaveCount(24);
  await expect(feed(page).getByRole('heading', { name: 'Yesterday' })).toHaveCount(1);
  await expect(page.getByRole('button', { name: 'Load older notifications' })).toBeHidden();
});

test('an empty feed: "You’re all caught up," with a way back to Rooms', async ({ page }) => {
  await fixture(page, { feed: [] });
  await open(page);
  await expect(page.getByText('You’re all caught up.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Back to Rooms' })).toHaveAttribute('href', '/');
});

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: the feed passes axe at 1280 and 360, and reflows at 320`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page);
    await open(page, 1280);
    await expect(page.locator('.privacy-notification')).toHaveCount(9);
    await axe(page, 'activity 1280');
    await open(page, 360);
    await expect(page.locator('.privacy-notification')).toHaveCount(9);
    await axe(page, 'activity 360');
    await page.setViewportSize({ width: 320, height: 700 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}
