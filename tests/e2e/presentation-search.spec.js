import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S4d (#170, spec comments 6731513054 and 6815158178): search results as list
// rows (a lantern or pebble, the place as the link to the exact message, the
// time, the excerpt with matching words in <mark>, the sender), scope chips
// (All · Rooms · Direct, today's scope values), and an empty state that says
// what to try next. <mark> mirrors the server's websearch_to_tsquery('simple')
// and is built from text nodes, never HTML. Presentation only: the real page
// against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
// #295: the page's clock is fixed at noon (local time), so "an hour ago" is
// always today; on the real clock it was yesterday between 00:00 and 01:00.
const NOW = new Date(2026, 10, 12, 12, 0, 0);
const hoursAgo = (hours) => new Date(NOW.getTime() - hours * 3_600_000).toISOString();

const RESULTS = () => [
  {
    kind: 'room', message_id: 77, excerpt: 'Dice night on Friday: bring dice, not paradice.', sender: { id: 2, username: 'Rowan' },
    room: { id: 4, key: 'garden', name: 'Garden' }, peer: null, outgoing: null, created_at: hoursAgo(1), edited_at: null,
  },
  {
    kind: 'direct', message_id: 12, excerpt: 'Do you still have the <img src=x onerror=alert(1)> dice?', sender: { id: 3, username: 'Sam' },
    room: null, peer: { id: 3, username: 'Sam' }, outgoing: false, created_at: '2026-01-04T10:00:00Z', edited_at: '2026-01-04T10:05:00Z',
  },
];

async function fixture(page, { results = RESULTS() } = {}) {
  await page.clock.setFixedTime(NOW);
  const searches = [];
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (path === 'session.php') return route.fulfill({ json: { user: member, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] } });
    if (path === 'search/messages.php') {
      const body = request.postDataJSON();
      searches.push(body);
      const scoped = results.filter((result) => body.scope === 'all' || (body.scope === 'rooms' ? result.kind === 'room' : result.kind === 'direct'));
      return route.fulfill({ json: { results: scoped, has_more: false, next_offset: null } });
    }
    const responses = {
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return searches;
}

async function open(page, width = 1280, path = '/search.php') {
  await page.setViewportSize({ width, height: 900 });
  await page.goto(path);
  await expect(page.locator('#message-search-shell')).toBeVisible();
}

async function search(page, words) {
  await page.locator('#message-search-query').fill(words);
  await page.getByRole('button', { name: 'Search', exact: true }).click();
}

const results = (page) => page.locator('#message-search-results > li');
const scope = (page) => page.getByRole('group', { name: 'Search in' });

async function axe(page, label) {
  await page.mouse.move(0, 0);
  await page.waitForTimeout(200);
  const result = await new AxeBuilder({ page }).include('#message-search-shell').withTags(WCAG).analyze();
  expect(result.violations, `${label}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
}

test('result rows: the mark, the place as the link to the message, the time, the sender', async ({ page }) => {
  await fixture(page);
  await open(page);
  await search(page, 'dice');
  await expect(page.locator('#message-search-status')).toHaveText('2 results shown.');
  await expect(results(page)).toHaveCount(2);

  const room = page.getByRole('link', { name: '# Garden' });
  await expect(room).toHaveAttribute('href', '/?room_id=4&message_id=77');
  await expect(room).toHaveAccessibleDescription(/^Dice night on Friday: bring dice, not paradice\. Rowan · Room message$/);
  await expect(results(page).nth(0).locator('.search-mark.lantern')).toHaveAttribute('data-hue', '4');
  await expect(results(page).nth(0).locator('.message-search-time')).toHaveText(/\d/);

  const direct = page.getByRole('link', { name: 'Conversation with Sam' });
  await expect(direct).toHaveAttribute('href', '/messages.php?user_id=3&peer_name=Sam&message_id=12');
  await expect(results(page).nth(1).locator('.search-mark.pebble')).toHaveText('SA');
  await expect(results(page).nth(1).locator('.message-search-meta')).toContainText('Direct message · edited');
  // The sender's name keeps its profile menu, beside the link, not inside it.
  const sender = results(page).nth(0).getByRole('button', { name: 'Rowan' });
  await expect(sender).toBeVisible();
  expect(await sender.evaluate((button) => button.closest('a') === null)).toBe(true);
  // The words searched for stay out of the address.
  expect(page.url()).not.toContain('dice');
});

test('<mark> on whole-word matches only, and markup in a message stays text', async ({ page }) => {
  await fixture(page);
  await open(page);
  await search(page, 'Dice');
  const first = results(page).nth(0).locator('.message-search-excerpt');
  await expect(first.locator('mark')).toHaveText(['Dice', 'dice']);
  await expect(first).toHaveText('Dice night on Friday: bring dice, not paradice.');
  const second = results(page).nth(1).locator('.message-search-excerpt');
  await expect(second.locator('mark')).toHaveText(['dice']);
  await expect(second.locator('img')).toHaveCount(0);
  await expect(second).toContainText('<img src=x onerror=alert(1)>');
});

test('excluded and OR words are not marked; phrases mark their words', async ({ page }) => {
  await fixture(page);
  await open(page);
  await search(page, '"dice night" or -friday');
  await expect(results(page).nth(0).locator('.message-search-excerpt mark')).toHaveText(['Dice', 'night', 'dice']);
});

test('scope chips: All · Rooms · Direct, from the address, and changing one re-runs the search', async ({ page }) => {
  const searches = await fixture(page);
  await open(page, 1280, '/search.php?scope=direct');
  await expect(scope(page).getByRole('radio')).toHaveCount(3);
  await expect(scope(page).getByRole('radio', { name: 'Direct' })).toBeChecked();
  await search(page, 'dice');
  await expect.poll(() => searches.at(-1)?.scope).toBe('direct');
  await expect(page.locator('#message-search-status')).toHaveText('1 result shown.');

  await scope(page).getByRole('radio', { name: 'Rooms' }).check();
  await expect.poll(() => searches.at(-1)?.scope).toBe('rooms');
  await expect(page.getByRole('link', { name: '# Garden' })).toBeVisible();
  await expect(page).toHaveURL(/\/search\.php\?scope=rooms$/);
  await scope(page).getByRole('radio', { name: 'All' }).check();
  await expect.poll(() => searches.at(-1)?.scope).toBe('all');
  await expect(page).toHaveURL(/\/search\.php$/);
  await expect(results(page)).toHaveCount(2);
  // Arrow keys move between chips, as in any radio group.
  await scope(page).getByRole('radio', { name: 'All' }).focus();
  await page.keyboard.press('ArrowRight');
  await expect(scope(page).getByRole('radio', { name: 'Rooms' })).toBeChecked();
});

test('nothing found: say so, with what to try next', async ({ page }) => {
  await fixture(page, { results: [] });
  await open(page, 1280, '/search.php?scope=rooms');
  await search(page, 'zebra');
  await expect(page.locator('#message-search-status')).toHaveText('Nothing matches “zebra”. Try fewer words, or search All.');
  await scope(page).getByRole('radio', { name: 'All' }).check();
  await expect(page.locator('#message-search-status')).toHaveText('Nothing matches “zebra”. Try fewer or different words.');
});

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: search with results passes axe at 1280 and 360, and reflows at 320`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page);
    await open(page, 1280);
    await search(page, 'dice');
    await expect(results(page)).toHaveCount(2);
    await axe(page, 'search 1280');
    await page.setViewportSize({ width: 360, height: 800 });
    await axe(page, 'search 360');
    await page.setViewportSize({ width: 320, height: 700 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}
