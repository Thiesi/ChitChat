import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S4a (#170, spec comments 6731513054 and 6815158178): the Rooms list. Rows
// are links in lists (plain clicks switch in the page; modified clicks are
// the browser's), grouped as invitations, Your rooms (by recent activity)
// and Other rooms with Join; "N here now", the glow, the time and "@" come
// only from data; the newest unread mention is pinned as a card; phones
// reach Other rooms through Find rooms. Presentation only: real templates
// and modules against intercepted API responses.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const guest = { id: 90, username: 'Guest 4821', roles: ['guest'], guest: true, session_version: 1 };

const hoursAgo = (hours) => new Date(Date.now() - hours * 3_600_000).toISOString();
const yesterday = () => {
  const date = new Date();
  date.setDate(date.getDate() - 1);
  date.setHours(12, 0, 0, 0);
  return date.toISOString();
};
const base = {
  unread_count: 0, last_read_message_id: 2, visibility: 'public', member_role: 'member', minimum_age: 0,
  inactivity_timeout_seconds: 0, info_line: '', guest_access: 'write', invited: false, active_count: 0, last_activity_at: null,
};
const ROOMS = () => [
  { ...base, id: 1, key: 'room-1', name: 'The living room', info_line: 'Everyday talk.', last_activity_at: hoursAgo(0.5) },
  { ...base, id: 4, key: 'room-4', name: 'Garden', info_line: 'Seeds and soil.', unread_count: 3, active_count: 2, last_activity_at: yesterday() },
  { ...base, id: 6, key: 'room-6', name: 'Attic', unread_count: 140, last_activity_at: null },
  { ...base, id: 7, key: 'room-7', name: 'Lobby', member_role: null, active_count: null, info_line: 'Say hi.' },
  { ...base, id: 8, key: 'room-8', name: 'Workshop', member_role: null, active_count: null, visibility: 'unlisted', minimum_age: 18 },
  { ...base, id: 9, key: 'room-9', name: 'Board games night', member_role: null, active_count: null, invited: true, visibility: 'private', info_line: 'Fridays.' },
];
const MENTION = { id: 41, kind: 'mentioned', read: false, title: 'You were mentioned', message: 'Rowan mentioned you in “Garden”.', details: [], link: '/?room_id=4&message_id=77', created_at: hoursAgo(1) };
const messages = [1, 2].map((id) => ({
  id, room_id: 1, sender_id: 2, username: 'Rowan', body: `Hello ${id}`, type: 'text', deleted: false,
  mentions: [], reactions: [], reply_to: null, attachment: null, created_at: `2026-10-04T10:0${id}:00Z`,
}));

async function fixture(page, { user = member, rooms = ROOMS(), notifications = [MENTION] } = {}) {
  const posts = [];
  const state = { rooms, notifications };
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') posts.push({ path, body: request.postDataJSON?.() ?? null });
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'session.php') return route.fulfill({ json: { user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], guest_access: true } });
    if (path === 'rooms/join.php') {
      const id = request.postDataJSON().room_id;
      state.rooms = state.rooms.map((room) => (room.id === id ? { ...room, member_role: 'member', invited: false, active_count: 0 } : room));
      return route.fulfill({ json: { room: state.rooms.find((room) => room.id === id) } });
    }
    if (path === 'account/notifications/read.php') {
      const ids = request.postDataJSON().ids ?? [];
      state.notifications = state.notifications.map((item) => (ids.includes(item.id) ? { ...item, read: true } : item));
      return route.fulfill({ json: { updated: ids.length, unread_count: state.notifications.filter((item) => !item.read).length } });
    }
    const responses = {
      'rooms/list.php': { rooms: state.rooms },
      'rooms/messages.php': { messages: url.searchParams.get('room_id') === '1' ? messages : [] },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 2 },
      'account/notifications/list.php': { notifications: state.notifications, unread_count: state.notifications.filter((item) => !item.read).length },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return posts;
}

const rooms = (page) => page.getByRole('navigation', { name: 'Rooms' });
const row = (page, name) => rooms(page).getByRole('link', { name: new RegExp(`^${name}`) });
const group = (page, name) => rooms(page).getByRole('list', { name, exact: true });

async function open(page, width = 1280, path = '/') {
  await page.setViewportSize({ width, height: 860 });
  await page.goto(path);
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#room-list .room-button').first()).toBeAttached();
}

async function axe(page, label) {
  await page.mouse.move(0, 0);
  await page.waitForTimeout(250);
  const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(result.violations, `${label}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
}

test('rows are links in lists, grouped, with Your rooms by recent activity', async ({ page }) => {
  await fixture(page);
  await open(page);
  await expect(group(page, 'Invitations').getByRole('listitem')).toHaveCount(1);
  await expect(group(page, 'Your rooms').getByRole('link')).toHaveText([/The living room/, /Garden/, /Attic/]);
  await expect(group(page, 'Other rooms').getByRole('link')).toHaveText([/Lobby/, /Workshop/]);
  await expect(row(page, 'Garden')).toHaveAttribute('href', '/?room_id=4');
  // Order of headings: invitations above both groups.
  await expect(rooms(page).getByRole('heading', { level: 2 })).toHaveText(['Invitations', 'Your rooms', 'Other rooms']);
  // The journeys' hook: the visible text still reads "# name".
  await expect(page.locator('.room-button', { hasText: '# Garden' })).toHaveCount(1);
});

test('a row says what waits in it, from data only: unread, "@", here now with the glow, and the time', async ({ page }) => {
  await fixture(page);
  await open(page);
  await expect(row(page, 'Garden')).toHaveAccessibleName('Garden, 3 unread, mentions you, 2 here now');
  await expect(row(page, 'Garden').locator('.room-sub')).toHaveText('2 here now · Seeds and soil.');
  await expect(row(page, 'Garden').locator('.lantern')).toHaveClass(/glow/);
  await expect(row(page, 'Garden').locator('.room-time')).toHaveText('Yesterday');
  await expect(row(page, 'Garden').locator('.mention-badge')).toHaveText('@');
  // Nobody here, no activity yet: no glow, no "here now", no time.
  await expect(row(page, 'Attic').locator('.lantern')).not.toHaveClass(/glow/);
  await expect(row(page, 'Attic').locator('.room-time')).toHaveCount(0);
  // Over 99 the count goes quiet.
  await expect(row(page, 'Attic')).toHaveAccessibleName('Attic, 99+ unread');
  await expect(row(page, 'Attic').locator('.unread-count')).toHaveClass(/quiet/);
  await expect(row(page, 'Workshop')).toHaveAccessibleName('Workshop, 18+, unlisted');
  // The open room is the current page, and its own count and "@" don't call.
  await expect(row(page, 'The living room')).toHaveAttribute('aria-current', 'page');
  await expect(rooms(page).locator('[aria-current]')).toHaveCount(1);
});

test('a plain click switches in the page; a modified click is the browser’s', async ({ page }) => {
  await fixture(page, { notifications: [] });
  await open(page);
  await page.evaluate(() => { window.__stayed = true; });
  await row(page, 'Attic').click();
  await expect(page.locator('#room-title')).toHaveText('# Attic');
  await expect(row(page, 'Attic')).toHaveAttribute('aria-current', 'page');
  expect(await page.evaluate(() => window.__stayed)).toBe(true);
  await expect(page).toHaveURL(/\/$/);

  // Enter on a focused row is a plain activation too.
  await row(page, 'Garden').focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#room-title')).toHaveText('# Garden');
  expect(await page.evaluate(() => window.__stayed)).toBe(true);

  // Ctrl or Cmd: a new tab at the room's address, and this page stays put.
  const opened = page.context().waitForEvent('page');
  await row(page, 'The living room').click({ modifiers: ['ControlOrMeta'] });
  const tab = await opened;
  await expect.poll(() => tab.url()).toMatch(/\/\?room_id=1$/);
  await tab.close();
  await expect(page.locator('#room-title')).toHaveText('# Garden');
});

test('phones (320px): a row click or tap switches in the page, with no reload', async ({ browser }) => {
  // A real reload here would cost every phone room switch (Safari included) a full page load.
  const context = await browser.newContext({ hasTouch: true, viewport: { width: 320, height: 700 } });
  const page = await context.newPage();
  try {
    await fixture(page, { notifications: [] });
    await open(page, 320);
    await page.evaluate(() => { window.__stayed = true; });

    await row(page, 'Attic').click();
    await expect(page.locator('#room-title')).toHaveText('# Attic');
    await expect(page.locator('#room-title')).toBeFocused();
    expect(await page.evaluate(() => window.__stayed)).toBe(true);

    await page.getByRole('button', { name: 'Back to rooms' }).click();
    await expect(row(page, 'Garden')).toBeVisible();
    await row(page, 'Garden').tap();
    await expect(page.locator('#room-title')).toHaveText('# Garden');
    expect(await page.evaluate(() => window.__stayed)).toBe(true);
    // Only the phone stack's own history entry: the address is still the page's, not /?room_id=N.
    expect(new URL(page.url()).search).toBe('');
  } finally {
    await context.close();
  }
});

test('Join on an Other rooms row joins and opens it; it moves to Your rooms', async ({ page }) => {
  const posts = await fixture(page, { notifications: [] });
  await open(page);
  const join = group(page, 'Other rooms').getByRole('button', { name: 'Join Lobby' });
  await expect(join).toBeVisible();
  // Join is beside the link, not inside it.
  expect(await join.evaluate((button) => button.closest('a') === null && button.parentElement.tagName === 'LI')).toBe(true);
  await join.click();
  await expect.poll(() => posts.find((entry) => entry.path === 'rooms/join.php')?.body).toEqual({ room_id: 7 });
  await expect(page.locator('#room-title')).toHaveText('# Lobby');
  await expect(group(page, 'Your rooms').getByRole('link', { name: /^Lobby/ })).toHaveAttribute('aria-current', 'page');
  await expect(group(page, 'Other rooms').getByRole('link')).toHaveText([/Workshop/]);
});

test('an invitation is a card with Join (no Decline: the API has none)', async ({ page }) => {
  const posts = await fixture(page, { notifications: [] });
  await open(page);
  const invitations = group(page, 'Invitations');
  await expect(invitations.getByRole('link', { name: 'Board games night, invited, private' })).toBeVisible();
  await expect(invitations.locator('.room-sub')).toHaveText('Invitation · Fridays.');
  await expect(invitations.getByRole('button', { name: /Decline/ })).toHaveCount(0);
  await invitations.getByRole('button', { name: 'Join Board games night' }).click();
  await expect.poll(() => posts.find((entry) => entry.path === 'rooms/join.php')?.body).toEqual({ room_id: 9 });
  await expect(page.locator('#room-title')).toHaveText('# Board games night');
  await expect(group(page, 'Invitations')).toHaveCount(0);
});

test('the newest unread mention is pinned; Open marks it read and goes to the message', async ({ page }) => {
  const posts = await fixture(page);
  await open(page);
  const card = rooms(page).getByRole('complementary', { name: 'Rowan mentioned you in “Garden”.' });
  await expect(card).toBeVisible();
  const openLink = card.getByRole('link', { name: 'Open' });
  await expect(openLink).toHaveAttribute('href', '/?room_id=4&message_id=77');
  await openLink.click();
  await expect.poll(() => posts.find((entry) => entry.path === 'account/notifications/read.php')?.body).toEqual({ ids: [41] });
  await expect(page).toHaveURL(/\/\?room_id=4&message_id=77$|\/$/);
});

test('reading the room clears its "@" and the card', async ({ page }) => {
  const posts = await fixture(page);
  await open(page);
  await expect(row(page, 'Garden').locator('.mention-badge')).toBeVisible();
  await row(page, 'Garden').click();
  await expect.poll(() => posts.find((entry) => entry.path === 'account/notifications/read.php')?.body).toEqual({ ids: [41] });
  await expect(page.locator('.mention-card')).toHaveCount(0);
  await row(page, 'The living room').click();
  await expect(row(page, 'Garden').locator('.mention-badge')).toHaveCount(0);
  await expect(row(page, 'Garden')).not.toHaveAccessibleName(/mentions you/);
});

test('no unread mention: no card and no "@"', async ({ page }) => {
  await fixture(page, { notifications: [{ ...MENTION, read: true }] });
  await open(page);
  await expect(page.locator('.mention-card')).toHaveCount(0);
  await expect(page.locator('.mention-badge')).toHaveCount(0);
});

test('phones: Other rooms waits behind Find rooms at the end of Your rooms', async ({ page }) => {
  await fixture(page, { notifications: [] });
  await open(page, 360);
  const find = rooms(page).getByRole('button', { name: 'Find rooms' });
  await expect(find).toHaveAttribute('aria-expanded', 'false');
  await expect(group(page, 'Other rooms')).toBeHidden();
  await find.click();
  await expect(find).toHaveAttribute('aria-expanded', 'true');
  await expect(group(page, 'Other rooms')).toBeVisible();
  await expect(row(page, 'Lobby')).toBeFocused();
  // Wide again: the group is simply there.
  await page.setViewportSize({ width: 1280, height: 860 });
  await expect(rooms(page).getByRole('button', { name: 'Find rooms' })).toHaveCount(0);
  await expect(group(page, 'Other rooms')).toBeVisible();
});

test('phones with no joined room: Other rooms shows straight away, with a hint', async ({ page }) => {
  await fixture(page, { notifications: [], rooms: ROOMS().filter((room) => !room.member_role && !room.invited) });
  await open(page, 360);
  await expect(rooms(page)).toContainText('You haven’t joined a room yet. Pick one below.');
  await expect(rooms(page).getByRole('button', { name: 'Find rooms' })).toHaveCount(0);
  await expect(group(page, 'Other rooms')).toBeVisible();
});

test('a guest sees read only in the row and its name', async ({ page }) => {
  await fixture(page, {
    user: guest, notifications: [],
    rooms: [{ ...base, id: 1, key: 'room-1', name: 'News', guest_access: 'read' }, { ...base, id: 2, key: 'room-2', name: 'Lounge' }],
  });
  await open(page);
  await expect(row(page, 'News')).toHaveAccessibleName('News, read only');
  await expect(row(page, 'News')).toContainText('read only');
  await expect(row(page, 'Lounge')).toHaveAccessibleName('Lounge');
});

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: the list with every state passes axe at 1280 and 360, and reflows at 320`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page);
    await open(page, 1280);
    await expect(page.locator('.mention-card')).toBeVisible();
    await row(page, 'Garden').hover();
    await axe(page, 'rooms 1280');
    await open(page, 360);
    await rooms(page).getByRole('button', { name: 'Find rooms' }).click();
    await expect(group(page, 'Other rooms')).toBeVisible();
    await axe(page, 'rooms 360, Other rooms open');
    await page.setViewportSize({ width: 320, height: 700 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    // Rows keep their name and badges inside the pane at 320.
    const overflow = await page.locator('#room-list .room-button').evaluateAll((links) => links.filter((link) => link.scrollWidth > link.clientWidth + 1).length);
    expect(overflow).toBe(0);
  });
}
