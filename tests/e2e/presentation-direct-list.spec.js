import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S4b (#170, spec comments 6731513054 and 6815158178): the Direct list. Rows
// are links in a list (/messages.php?with=N): plain clicks and Enter switch
// in the page, modified clicks are the browser's, the open one is the current
// page. A row shows the pebble, the name, the last message ("You: " for
// yours), its time and the unread count; nothing the payload doesn't carry.
// Also #270: re-rendering the list for the same conversation doesn't refetch
// message state. Presentation only: real templates and modules against
// intercepted API responses.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const sam = { id: 3, username: 'Sam' };
const placeholder = { id: 4, username: 'member-k7q2xm' };

const hoursAgo = (hours) => new Date(Date.now() - hours * 3_600_000).toISOString();
const yesterday = () => {
  const date = new Date();
  date.setDate(date.getDate() - 1);
  date.setHours(12, 0, 0, 0);
  return date.toISOString();
};
// #294: a modified click is the browser's, so the page must not cancel it.
// Opening a real tab to show that is racy (the context's new-page event can be
// lost, #296), so a last listener records whether anything cancelled the click
// and then cancels it itself: no tab opens. It is on window in the bubble
// phase, added after the page's scripts, so it runs after every app listener
// (those listen on elements or on document, never on window for clicks).
async function modifiedClick(page, target) {
  await page.evaluate(() => {
    window.__modifiedClicks = [];
    window.addEventListener('click', (event) => {
      window.__modifiedClicks.push({ prevented: event.defaultPrevented, href: event.target.closest('a')?.getAttribute('href') ?? null });
      event.preventDefault();
    }, { once: true });
  });
  await target.click({ modifiers: ['ControlOrMeta'] });
  return page.evaluate(() => window.__modifiedClicks);
}

const CONVERSATIONS = () => [
  { user: rowan, unread_count: 0, last_message: { id: 12, body: 'Direct 2', outgoing: true, created_at: hoursAgo(0.5) } },
  { user: sam, unread_count: 3, last_message: { id: 11, body: 'See you at the market', outgoing: false, created_at: yesterday() } },
  { user: placeholder, unread_count: 140, last_message: { id: 5, body: 'Hi', outgoing: false, created_at: '2026-01-04T10:00:00Z' } },
];
const history = (peer) => [1, 2].map((id) => ({
  id: peer.id * 10 + id, body: `Direct ${id}`, created_at: `2026-10-04T10:0${id}:00Z`, mentions: [], reactions: [], reply_to: null,
  sender: id === 1 ? peer : member, recipient: id === 1 ? member : peer, outgoing: id === 2, read_at: null,
}));

async function fixture(page, { conversations = CONVERSATIONS() } = {}) {
  const requests = [];
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1/', '');
    requests.push(`${path}${url.search}`);
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'session.php') {
      return route.fulfill({ json: {
        user: member, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [],
        privacy: { direct_messages: { retention: 'for 30 days', admin_inspection_enabled: false } },
      } });
    }
    const peerId = Number(url.searchParams.get('user_id'));
    const peer = [rowan, sam, placeholder].find((user) => user.id === peerId);
    const responses = {
      'direct-messages/conversations.php': { conversations },
      'direct-messages/block-status.php': { user: peer, relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: peer ? history(peer) : [] },
      'direct-messages/message-mutations.php': { messages: [] },
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/read.php': {},
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

const list = (page) => page.getByRole('navigation', { name: 'Conversations' });
const row = (page, name) => list(page).getByRole('link', { name: new RegExp(`^${name}`) });

async function open(page, width = 1280, path = '/messages.php') {
  await page.setViewportSize({ width, height: 860 });
  await page.goto(path);
  await expect(page.locator('#messages-shell')).toBeVisible();
}

async function axe(page, label) {
  await page.mouse.move(0, 0);
  await page.waitForTimeout(250);
  const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(result.violations, `${label}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
}

test('rows are links in a list, newest first, each with what the payload carries', async ({ page }) => {
  await fixture(page);
  await open(page);
  await expect(list(page).getByRole('list', { name: 'Conversations' }).getByRole('link')).toHaveText([/Rowan/, /Sam/, /member-k7q2xm/]);
  await expect(row(page, 'Sam')).toHaveAttribute('href', '/messages.php?with=3');
  await expect(row(page, 'Sam')).toHaveAccessibleName('Sam, 3 unread');
  await expect(row(page, 'Sam')).toHaveAccessibleDescription(/See you at the market.*Yesterday/);
  await expect(row(page, 'Sam').locator('.conversation-time')).toHaveText('Yesterday');
  await expect(row(page, 'Rowan').locator('.conversation-preview')).toHaveText('You: Direct 2');
  // Over 99 the count goes quiet; a pending placeholder name shows as it is (#164).
  await expect(row(page, 'member-k7q2xm')).toHaveAccessibleName('member-k7q2xm, 99+ unread');
  await expect(row(page, 'member-k7q2xm').locator('.unread-badge')).toHaveClass(/quiet/);
  await expect(row(page, 'member-k7q2xm').locator('.conversation-time')).toHaveText(/2026|26/);
  // The open conversation (the newest, on desktop) is the current page.
  await expect(row(page, 'Rowan')).toHaveAttribute('aria-current', 'page');
  await expect(list(page).locator('[aria-current]')).toHaveCount(1);
});

test('a plain click or Enter switches in the page; a modified click is the browser’s', async ({ page }) => {
  await fixture(page);
  await open(page);
  await expect(row(page, 'Rowan')).toHaveAttribute('aria-current', 'page');
  await page.evaluate(() => { window.__stayed = true; });
  await row(page, 'Sam').click();
  await expect(page.locator('#dm-peer-name')).toHaveText('Sam');
  await expect(row(page, 'Sam')).toHaveAttribute('aria-current', 'page');
  await row(page, 'Rowan').focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#dm-peer-name')).toHaveText('Rowan');
  expect(await page.evaluate(() => window.__stayed)).toBe(true);
  await expect(page).toHaveURL(/\/messages\.php$/);

  // Ctrl or Cmd: left to the browser (a new tab at the row's address); this page stays put.
  expect(await modifiedClick(page, row(page, 'Sam'))).toEqual([{ prevented: false, href: '/messages.php?with=3' }]);
  await expect(page.locator('#dm-peer-name')).toHaveText('Rowan');
  await expect(row(page, 'Rowan')).toHaveAttribute('aria-current', 'page');
  await expect(page).toHaveURL(/\/messages\.php$/);
});

test('phones (320px): a row click or tap switches in the page, with no reload', async ({ browser }) => {
  const context = await browser.newContext({ hasTouch: true, viewport: { width: 320, height: 700 } });
  const page = await context.newPage();
  try {
    await fixture(page);
    await open(page, 320);
    await expect(row(page, 'Sam')).toBeVisible();
    await page.evaluate(() => { window.__stayed = true; });
    await row(page, 'Sam').click();
    await expect(page.locator('#dm-peer-name')).toBeFocused();
    await expect(page.locator('#dm-peer-name')).toHaveText('Sam');
    expect(await page.evaluate(() => window.__stayed)).toBe(true);
    await page.getByRole('button', { name: 'Back to conversations' }).click();
    await row(page, 'Rowan').tap();
    await expect(page.locator('#dm-peer-name')).toHaveText('Rowan');
    expect(await page.evaluate(() => window.__stayed)).toBe(true);
    expect(new URL(page.url()).search).toBe('');
  } finally {
    await context.close();
  }
});

test('#274: blocked, unavailable and closed conversations say so instead of the last message', async ({ page }) => {
  const free = { blocked_by_me: false, messaging_available: true };
  await fixture(page, { conversations: [
    { user: { ...rowan, available: true }, relationship: free, unread_count: 0, last_message: { id: 12, body: 'Direct 2', outgoing: true, created_at: hoursAgo(0.5) } },
    { user: { ...sam, available: true }, relationship: { blocked_by_me: true, messaging_available: false }, unread_count: 0, last_message: { id: 11, body: 'Hidden', outgoing: false, created_at: hoursAgo(2) } },
    { user: { id: 5, username: 'Kit', available: true }, relationship: { blocked_by_me: false, messaging_available: false }, unread_count: 0, last_message: { id: 10, body: 'Hidden', outgoing: false, created_at: hoursAgo(3) } },
    { user: { id: 6, username: 'Closed account #6', available: false }, relationship: { blocked_by_me: false, messaging_available: false }, unread_count: 0, last_message: { id: 9, body: 'Hidden', outgoing: false, created_at: hoursAgo(4) } },
  ] });
  await open(page);
  await expect(row(page, 'Rowan').locator('.conversation-preview')).toHaveText('You: Direct 2');
  await expect(row(page, 'Sam')).toHaveAccessibleName('Sam, blocked');
  await expect(row(page, 'Sam').locator('.conversation-preview')).toHaveText('Blocked');
  // Someone else's block is never named.
  await expect(row(page, 'Kit')).toHaveAccessibleName('Kit, unavailable');
  await expect(row(page, 'Closed account #6')).toHaveAccessibleName('Closed account #6, account closed');
  await expect(list(page)).not.toContainText('Hidden');
  await axe(page, 'direct states');
});

test('no conversations: the door is open, with a way to Rooms', async ({ page }) => {
  await fixture(page, { conversations: [] });
  await open(page);
  await expect(list(page)).toContainText('No conversations yet. Say hello to someone from a room, or start one above.');
  await expect(list(page).getByRole('link', { name: 'Go to Rooms' })).toHaveAttribute('href', '/');
});

test('#270: re-rendering the list for the same conversation does not refetch message state', async ({ page }) => {
  const requests = await fixture(page);
  await open(page);
  await expect(page.locator('#dm-message-list .dm-message')).toHaveCount(2);
  await page.waitForTimeout(300);
  const fetches = () => requests.filter((entry) => entry.startsWith('direct-messages/message-mutations.php')).length;
  const before = fetches();
  // A resize to phones and back re-renders nothing about the messages.
  await page.setViewportSize({ width: 360, height: 800 });
  await page.setViewportSize({ width: 1280, height: 860 });
  await page.waitForTimeout(300);
  expect(fetches()).toBe(before);
  // Switching conversations does fetch them.
  await row(page, 'Sam').click();
  await expect.poll(fetches).toBeGreaterThan(before);
});

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: the Direct list passes axe at 1280 and 360, and reflows at 320`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page);
    await open(page, 1280);
    await expect(row(page, 'Rowan')).toHaveAttribute('aria-current', 'page');
    await row(page, 'Sam').hover();
    await axe(page, 'direct 1280');
    await open(page, 360);
    await expect(row(page, 'Sam')).toBeVisible();
    await axe(page, 'direct 360 list');
    await page.setViewportSize({ width: 320, height: 700 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const overflow = await page.locator('#dm-conversation-list .conversation-button').evaluateAll((links) => links.filter((link) => link.scrollWidth > link.clientWidth + 1).length);
    expect(overflow).toBe(0);
  });
}
