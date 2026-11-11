import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S2c (#168): the list pane and the conversation. Desktop shows them side by
// side; tablets open the list over the conversation (Escape and focus
// return); phones stack them (focus to the title, back to the opener, no
// bottom bar on the pushed screen). The conversation header has a lantern
// (room) or pebble (person) in its identity hue, a Bricolage title, and
// "N here now" with the glow only where presence is known; the Direct header
// keeps report and block and a one-line privacy notice with "Learn more".
// Presentation only: real templates and modules against intercepted API
// responses.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const guest = { id: 90, username: 'Guest 4821', roles: ['guest'], guest: true, session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const sam = { id: 3, username: 'Sam' };
const baseRoom = {
  unread_count: 0, last_read_message_id: 2, visibility: 'public', member_role: 'member', minimum_age: 0,
  inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'read_write',
};
const rooms = [
  { ...baseRoom, id: 1, key: 'room-1', name: 'The living room' },
  { ...baseRoom, id: 4, key: 'room-4', name: 'Garden', unread_count: 3 },
];
const roomMessages = [1, 2].map((id) => ({
  id, room_id: 1, sender_id: 2, username: 'Rowan', body: `Hello ${id}`, type: 'text', deleted: false,
  mentions: [], reactions: [], reply_to: null, attachment: null, created_at: `2026-10-04T10:0${id}:00Z`,
}));
const dmHistory = [1, 2].map((id) => ({
  id, body: `Direct ${id}`, created_at: `2026-10-04T10:0${id}:00Z`, mentions: [], reactions: [], reply_to: null,
  sender: id === 1 ? rowan : member, recipient: id === 1 ? member : rowan, outgoing: id === 2, read_at: null,
}));

async function fixture(page, { user = member, present = [] } = {}) {
  const requests = [];
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1/', '');
    requests.push(`${path}${url.search}`);
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'session.php') {
      return route.fulfill({ json: {
        user, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], guest_access: true,
        privacy: { direct_messages: { retention: 'for 30 days', admin_inspection_enabled: false } },
      } });
    }
    const responses = {
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: url.searchParams.get('room_id') === '1' ? roomMessages : [] },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: present },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 2 },
      'rooms/join.php': { room: rooms[0] },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [
        { user: rowan, unread_count: 0, last_message: { body: 'Direct 2', outgoing: true } },
        { user: sam, unread_count: 1, last_message: { body: 'See you', outgoing: false } },
      ] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: url.searchParams.get('user_id') === '2' ? dmHistory : [] },
      'direct-messages/message-mutations.php': { messages: [] },
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/read.php': {},
      'users/profile.php': { user: rowan },
      'rooms/create.php': { room: { ...baseRoom, id: 9, key: 'room-9', name: 'Porch' } },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

const list = (page) => page.getByRole('region', { name: 'Rooms' });
const toggle = (page) => page.locator('#drawer-toggle');
const bar = (page) => page.getByRole('navigation', { name: 'Main' });
const roomButton = (page, name) => page.getByRole('navigation', { name: 'Rooms' }).getByRole('button', { name: new RegExp(name) });

async function openRooms(page, width = 1280) {
  await page.setViewportSize({ width, height: 860 });
  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  // Attached, not visible: on tablets the list starts closed.
  await expect(page.locator('#room-list .room-button', { hasText: 'The living room' })).toBeAttached();
}

async function openDirect(page, width = 1280) {
  await page.setViewportSize({ width, height: 860 });
  await page.goto('/messages.php');
  await expect(page.locator('#messages-shell')).toBeVisible();
  await expect(page.locator('#dm-conversation-list .conversation-button')).toHaveCount(2);
}

/** axe over the visible page, after any pane transition has settled. */
async function axe(page, label) {
  await page.mouse.move(0, 0);
  await page.waitForTimeout(250);
  const result = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(result.violations, `${label}: ${result.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target).join(' ')}`).join('; ')}`).toEqual([]);
}

async function noSideScroll(page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

// ---- Rooms ----

test('desktop: the Rooms list sits beside the conversation, with lanterns and no direct messages', async ({ page }) => {
  await fixture(page);
  await openRooms(page);
  await expect(list(page)).toBeVisible();
  await expect(list(page).getByRole('heading', { level: 1, name: 'Rooms' })).toBeVisible();
  await expect(list(page).getByRole('link', { name: 'Search messages' })).toBeVisible();
  await expect(page.locator('#connection-status')).toBeVisible();
  // Direct messages moved to the Direct tab (parity: recent DMs, new DM, "All conversations").
  await expect(page.getByRole('heading', { name: 'Direct messages' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: /All conversations/ })).toHaveCount(0);
  await expect(toggle(page)).toBeHidden();
  // Lanterns in the room's identity hue; the text stays "# name".
  await expect(roomButton(page, 'Garden').locator('.lantern')).toHaveAttribute('data-hue', '4');
  await expect(roomButton(page, 'Garden')).toContainText('3 unread');
  await roomButton(page, 'The living room').click();
  await expect(page.locator('#room-title')).toHaveText('# The living room');
  await expect(page.locator('#room-title .lantern')).toHaveAttribute('data-hue', '1');
  // Bricolage title.
  expect(await page.locator('#room-title').evaluate((node) => getComputedStyle(node).fontFamily)).toMatch(/Bricolage/);
  // Nobody is known to be here: no "here now", no glow.
  await expect(page.locator('#room-here')).toBeHidden();
  await expect(page.locator('#room-title .lantern')).not.toHaveClass(/glow/);
});

test('the header says "N here now" with the lantern\'s glow once presence is known', async ({ page }) => {
  await fixture(page, { present: [{ id: 2, username: 'Rowan' }, { id: 3, username: 'Sam' }] });
  await openRooms(page);
  await roomButton(page, 'The living room').click();
  await expect(page.locator('#room-here')).toHaveText('2 here now');
  await expect(page.locator('#room-title .lantern')).toHaveClass(/glow/);
  // The glow is never the only signal, and the members button keeps its count.
  await expect(page.locator('#members-toggle')).toHaveAccessibleName('Members, 2 online');
});

test('tablet: the list opens over the conversation; Escape, close and the backdrop return focus', async ({ page }) => {
  await fixture(page);
  await openRooms(page, 900);
  await expect(list(page)).toBeHidden();
  await expect(toggle(page)).toBeVisible();
  await expect(toggle(page)).toHaveAccessibleName('Rooms');
  await expect(toggle(page)).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle(page)).toHaveAttribute('aria-controls', 'sidebar');

  await toggle(page).click();
  await expect(list(page)).toBeVisible();
  await expect(toggle(page)).toHaveAttribute('aria-expanded', 'true');
  await expect(list(page).locator(':focus')).toHaveCount(1);
  await page.keyboard.press('Escape');
  await expect(list(page)).toBeHidden();
  await expect(toggle(page)).toBeFocused();

  await toggle(page).click();
  await list(page).getByRole('button', { name: 'Close rooms' }).click();
  await expect(list(page)).toBeHidden();
  await expect(toggle(page)).toBeFocused();

  await toggle(page).click();
  await page.mouse.click(880, 400);
  await expect(list(page)).toBeHidden();
  await expect(toggle(page)).toBeFocused();

  // Choosing a room closes the list and moves to the conversation's title.
  await toggle(page).click();
  await roomButton(page, 'Garden').click();
  await expect(list(page)).toBeHidden();
  await expect(page.locator('#room-title')).toBeFocused();
  await expect(page.locator('#room-title')).toHaveText('# Garden');
});

test('phone: the list comes first; a room pushes the conversation and Back returns to its button', async ({ page }) => {
  await fixture(page);
  await openRooms(page, 360);
  await expect(list(page)).toBeVisible();
  await expect(page.locator('#conversation')).toBeHidden();
  await expect(bar(page)).toBeVisible();

  await roomButton(page, 'Garden').click();
  await expect(page.locator('#conversation')).toBeVisible();
  await expect(list(page)).toBeHidden();
  await expect(page.locator('#room-title')).toBeFocused();
  await expect(bar(page)).toBeHidden();
  const back = page.getByRole('button', { name: 'Back to rooms' });
  await expect(back).toBeVisible();
  await expect(back).not.toHaveAttribute('aria-expanded', /.*/);

  await back.click();
  await expect(list(page)).toBeVisible();
  await expect(page.locator('#conversation')).toBeHidden();
  await expect(roomButton(page, 'Garden')).toBeFocused();
  await expect(bar(page)).toBeVisible();

  // The browser's back does the same.
  await roomButton(page, 'The living room').click();
  await expect(page.locator('#room-title')).toBeFocused();
  await page.goBack();
  await expect(list(page)).toBeVisible();
  await expect(roomButton(page, 'The living room')).toBeFocused();
  await noSideScroll(page);
});

test('phone: a deep link to a room starts on its conversation', async ({ page }) => {
  await fixture(page);
  await page.setViewportSize({ width: 360, height: 760 });
  await page.goto('/?room_id=1&message=2');
  await expect(page.locator('#conversation')).toBeVisible();
  await expect(list(page)).toBeHidden();
  await expect(page.getByRole('button', { name: 'Back to rooms' })).toBeVisible();
});

test('the installation’s icon and name stay in view: the rail on wide screens, the list on phones', async ({ page }) => {
  await fixture(page);
  await openRooms(page, 1280);
  const railBrand = bar(page).locator('.app-nav-brand');
  await expect(railBrand).toBeVisible();
  await expect(railBrand).toHaveText('ChitChat');
  await expect(railBrand.locator('img')).toHaveAttribute('alt', '');
  await expect(page).toHaveTitle(/ChitChat/);
  // Plain text, not a link: the navigation's destinations are unchanged.
  await expect(railBrand.locator('a')).toHaveCount(0);
  await expect(list(page).locator('.list-pane-brand')).toBeHidden();

  await openRooms(page, 900);
  await expect(bar(page).locator('.app-nav-brand')).toBeVisible();

  await openRooms(page, 360);
  await expect(bar(page).locator('.app-nav-brand')).toBeHidden();
  await expect(list(page).locator('.list-pane-brand')).toBeVisible();
  await expect(list(page).locator('.list-pane-brand')).toHaveText('ChitChat');
  await openDirect(page, 360);
  await expect(page.locator('#dm-list-pane .list-pane-brand')).toHaveText('ChitChat');
  await expect(page).toHaveTitle(/ChitChat/);
});

test('guests see the members-only card in the Rooms list and no search', async ({ page }) => {
  await fixture(page, { user: guest });
  await openRooms(page);
  await expect(list(page).locator('.guest-dm-card')).toBeVisible();
  await expect(list(page).getByRole('link', { name: 'Search messages' })).toBeHidden();
});

// ---- Direct ----

test('desktop Direct: the conversation list with pebbles, then a header with report, block and the privacy line', async ({ page }) => {
  await fixture(page);
  await openDirect(page);
  const pane = page.getByRole('region', { name: 'Direct' });
  await expect(pane.getByRole('heading', { level: 1, name: 'Direct' })).toBeVisible();
  await expect(pane.getByRole('link', { name: 'Search messages' })).toHaveAttribute('href', '/search.php?scope=direct');
  await expect(pane.getByLabel('Start a conversation')).toBeVisible();
  await expect(pane.getByRole('navigation', { name: 'Conversations' })).toBeVisible();
  await expect(pane.locator('.conversation-button').first().locator('.pebble')).toHaveAttribute('data-hue', '2');
  await expect(page.getByRole('link', { name: /Back to chat/ })).toHaveCount(0);

  await pane.locator('.conversation-button', { hasText: 'Rowan' }).click();
  await expect(page.locator('#dm-peer-name')).toHaveText('Rowan');
  await expect(page.locator('#dm-peer-pebble')).toHaveAttribute('data-hue', '2');
  await expect(page.locator('#dm-peer-pebble')).toHaveText('RO');
  await expect(page.locator('#dm-message-list .dm-message')).toHaveCount(2);
  await expect(page.locator('#dm-report-person')).toBeVisible();
  await expect(page.locator('#dm-block-toggle')).toBeVisible();

  const privacy = page.locator('#dm-privacy-notice');
  await expect(privacy).toContainText('aren’t end-to-end encrypted');
  await expect(page.locator('#dm-privacy-text')).toBeHidden();
  await privacy.getByText('Learn more').click();
  await expect(page.locator('#dm-privacy-text')).toBeVisible();
  await expect(page.locator('#dm-privacy-text')).toContainText('not end-to-end encrypted');
});

test('phone Direct: a conversation pushes over the list and Back returns to it', async ({ page }) => {
  await fixture(page);
  await openDirect(page, 360);
  await expect(page.locator('#dm-conversation')).toBeHidden();
  await expect(bar(page)).toBeVisible();
  const rowanButton = page.locator('.conversation-button', { hasText: 'Rowan' });
  await rowanButton.click();
  await expect(page.locator('#dm-conversation')).toBeVisible();
  await expect(page.locator('#dm-peer-name')).toBeFocused();
  await expect(bar(page)).toBeHidden();
  await page.getByRole('button', { name: 'Back to conversations' }).click();
  await expect(page.locator('#dm-list-pane')).toBeVisible();
  await expect(page.locator('.conversation-button', { hasText: 'Rowan' })).toBeFocused();
  await noSideScroll(page);
});

test('tablet Direct: the conversations open over the conversation and Escape returns focus', async ({ page }) => {
  await fixture(page);
  await openDirect(page, 900);
  const toggleDirect = page.locator('#dm-list-toggle');
  await expect(page.locator('#dm-list-pane')).toBeHidden();
  await expect(toggleDirect).toHaveAccessibleName('Conversations');
  await toggleDirect.click();
  await expect(page.locator('#dm-list-pane')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('#dm-list-pane')).toBeHidden();
  await expect(toggleDirect).toBeFocused();
});

// ---- axe and reflow, in Lantern light, dark and one variant ----

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: both pages pass axe at 1280, 900 (list open) and 360 (list and conversation), and reflow at 320`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page, { present: [{ id: 2, username: 'Rowan' }] });

    await openRooms(page, 1280);
    await roomButton(page, 'The living room').click();
    await expect(page.locator('#room-here')).toHaveText('1 here now');
    await axe(page, 'rooms 1280');
    await openRooms(page, 900);
    await toggle(page).click();
    await expect(list(page)).toBeVisible();
    await axe(page, 'rooms 900 list open');
    await openRooms(page, 360);
    await axe(page, 'rooms 360 list');
    await roomButton(page, 'The living room').click();
    await expect(page.locator('#conversation')).toBeVisible();
    await axe(page, 'rooms 360 conversation');
    await page.setViewportSize({ width: 320, height: 700 });
    await noSideScroll(page);

    await openDirect(page, 1280);
    await page.locator('.conversation-button', { hasText: 'Rowan' }).click();
    await expect(page.locator('#dm-message-list .dm-message')).toHaveCount(2);
    await page.locator('#dm-privacy-notice summary').click();
    await axe(page, 'direct 1280');
    await openDirect(page, 900);
    await page.locator('#dm-list-toggle').click();
    await axe(page, 'direct 900 list open');
    await openDirect(page, 360);
    await axe(page, 'direct 360 list');
    await page.locator('.conversation-button', { hasText: 'Rowan' }).click();
    await expect(page.locator('#dm-conversation')).toBeVisible();
    await axe(page, 'direct 360 conversation');
    await page.setViewportSize({ width: 320, height: 700 });
    await noSideScroll(page);
  });
}

// ---- Review fixes (lead review of #239) ----

test('phone: nothing opens behind the Rooms list, so nothing is read until a room is chosen', async ({ page }) => {
  const requests = await fixture(page);
  await openRooms(page, 360);
  await page.waitForLoadState('networkidle');
  expect(requests.filter((path) => path.startsWith('rooms/read.php') || path.startsWith('rooms/messages.php'))).toEqual([]);
  await expect(page.locator('#room-title')).toHaveText('Choose a room');
  await expect(roomButton(page, 'Garden')).toContainText('3 unread');
  await expect(page.locator('.skip-link')).toBeHidden();

  await roomButton(page, 'The living room').click();
  await expect(page.locator('#message-list article.message')).toHaveCount(2);
  expect(requests.some((path) => path.startsWith('rooms/messages.php?room_id=1'))).toBe(true);
  expect(requests.some((path) => path.startsWith('rooms/messages.php?room_id=4'))).toBe(false);
});

test('phone: nothing opens behind the Direct list, so no conversation is marked read until chosen', async ({ page }) => {
  const requests = await fixture(page);
  await openDirect(page, 360);
  await page.waitForLoadState('networkidle');
  expect(requests.filter((path) => path.startsWith('direct-messages/read.php') || path.startsWith('direct-messages/history.php'))).toEqual([]);
  await expect(page.locator('.conversation-button', { hasText: 'Sam' }).locator('.unread-badge')).toHaveText('1');

  await page.locator('.conversation-button', { hasText: 'Rowan' }).click();
  await expect(page.locator('#dm-message-list .dm-message')).toHaveCount(2);
  expect(requests.some((path) => path.startsWith('direct-messages/history.php?user_id=2'))).toBe(true);
  expect(requests.some((path) => path.includes('user_id=3'))).toBe(false);
});

test('the rail\'s Direct tab carries the unread direct-message count, read with its name', async ({ page }) => {
  await fixture(page);
  await openRooms(page, 1280);
  const direct = bar(page).getByRole('link', { name: /^Direct/ });
  await expect(direct).toHaveAccessibleName('Direct, 1 unread');
  await expect(direct.locator('[data-badge="direct"]')).toHaveText('1');
  await openDirect(page, 1280);
  await expect(bar(page).getByRole('link', { name: /^Direct/ })).toHaveAccessibleName('Direct, 1 unread');
});

test('tablet: everything under the open list is inert, and comes back when it closes', async ({ page }) => {
  await fixture(page);
  await openRooms(page, 900);
  await toggle(page).click();
  await expect(list(page)).toBeVisible();
  for (const selector of ['#conversation', '#app-nav']) {
    expect(await page.locator(selector).evaluate((node) => node.inert), selector).toBe(true);
  }
  expect(await list(page).evaluate((node) => node.inert)).toBe(false);
  await page.keyboard.press('Escape');
  await expect(list(page)).toBeHidden();
  for (const selector of ['#conversation', '#app-nav']) {
    expect(await page.locator(selector).evaluate((node) => node.inert), selector).toBe(false);
  }
  await expect(toggle(page)).toBeFocused();
});

test('tablet: Escape in Create room closes the dialog, not the list; a created room closes the list', async ({ page }) => {
  await fixture(page, { user: { ...member, roles: ['admin'] } });
  await openRooms(page, 900);
  await toggle(page).click();
  await list(page).getByRole('button', { name: 'Create room' }).click();
  const dialog = page.locator('#room-dialog');
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(list(page)).toBeVisible();
  await expect(list(page).getByRole('button', { name: 'Create room' })).toBeFocused();
});
