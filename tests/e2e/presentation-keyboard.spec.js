import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S1b (#166 §4): the transcript is one tab stop with a roving current message,
// arrow/Page/Home/End movement, Enter or the context menu key for actions,
// Tab into the current message's own controls only, Esc to the composer.
// Presentation only: real templates and modules against intercepted API responses.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const priya = { id: 3, username: 'Priya' };
const rooms = [{
  id: 1, name: 'The living room', unread_count: 0, last_read_message_id: 6, key: 'room-0', visibility: 'public',
  member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'none',
}];

function roomMessage(id, sender, body, extra = {}) {
  return {
    id, room_id: 1, sender_id: sender.id, username: sender.username, body, type: 'text', deleted: false,
    mentions: [], reactions: [], reply_to: null, attachment: null,
    // Minutes after 08:00 on the same day, so time order matches id order.
    created_at: new Date(Date.UTC(2026, 9, 4, 8, 0, 0) + id * 60_000).toISOString(), ...extra,
  };
}

const history = [
  roomMessage(1, rowan, 'Anyone else taking the slow route through Sunday?'),
  roomMessage(2, viewer, 'Coffee, a good playlist, and absolutely no plans.', { reactions: [{ emoji: '❤️', users: [rowan] }] }),
  roomMessage(3, priya, 'That sounds like the right kind of afternoon.'),
  roomMessage(4, rowan, 'Found a tiny record shop yesterday.'),
  roomMessage(5, priya, 'Yes please!', { reply_to: { message_id: 1, available: true, message: { id: 1, sender: { id: 2, username: 'Rowan' }, body: 'Anyone else taking the slow route through Sunday?', deleted: false } } }),
  roomMessage(6, viewer, 'What are we listening to?'),
];

async function fixture(page, { messages = history } = {}) {
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages },
      'rooms/message-mutations.php': { messages: messages.map((message) => ({ ...message, can_edit: message.sender_id === 1, can_delete: message.sender_id === 1, can_moderate: false })) },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 6 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [{ user: rowan, unread_count: 0, last_message: { body: 'Hi', outgoing: false } }] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: messages.slice(0, 4).map((message) => ({
        id: message.id, body: message.body, created_at: message.created_at, mentions: [], reactions: [], reply_to: null,
        sender: { id: message.sender_id, username: message.username }, recipient: message.sender_id === 1 ? rowan : viewer,
        outgoing: message.sender_id === 1, read_at: null,
      })) },
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
      'users/profile.php': { profile: { id: 2, username: 'Rowan', member_since: '2026-01-01T00:00:00Z', badge: null, has_avatar: false } },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

const message = (page, id) => page.locator(`#message-list article[data-message-id="${id}"]`);
const focusedId = (page) => page.evaluate(() => document.activeElement?.closest('article')?.dataset.messageId ?? null);

async function openRoom(page) {
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(6);
  // Decorators (edit/delete) arrive after rendering; wait for them so the tab-stop check is meaningful.
  await expect(message(page, 6).locator('.message-mutation-button').first()).toBeVisible();
}

async function focusFromComposer(page) {
  await page.locator('#composer-input').focus();
  for (let step = 0; step < 4; step += 1) {
    await page.keyboard.press('Shift+Tab');
    if (await focusedId(page)) return;
  }
}

test('the transcript is one tab stop; Shift+Tab from the composer reaches the newest message', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  const list = page.locator('#message-list');
  await expect(list.locator('article[tabindex="0"]')).toHaveCount(1);
  await expect(message(page, 6)).toHaveAttribute('tabindex', '0');
  // Controls of other messages are out of the tab order until their message becomes current.
  for (const control of await message(page, 3).locator('button:visible').all()) await expect(control).toHaveAttribute('tabindex', '-1');
  await expect(list).toHaveAttribute('aria-describedby', /transcript-help/);

  await focusFromComposer(page);
  expect(await focusedId(page)).toBe('6');
  expect(await page.evaluate(() => document.activeElement.tagName)).toBe('ARTICLE');
});

test('arrows, Page and Home/End move between messages; Esc goes to the composer', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await focusFromComposer(page);
  await page.keyboard.press('ArrowUp');
  expect(await focusedId(page)).toBe('5');
  await page.keyboard.press('ArrowUp');
  expect(await focusedId(page)).toBe('4');
  await page.keyboard.press('ArrowDown');
  expect(await focusedId(page)).toBe('5');
  await page.keyboard.press('Home');
  expect(await focusedId(page)).toBe('1');
  await page.keyboard.press('End');
  expect(await focusedId(page)).toBe('6');
  await page.keyboard.press('PageUp');
  expect(await focusedId(page)).toBe('1');
  await expect(page.locator('#message-list article[tabindex="0"]')).toHaveCount(1);
  // No single-letter shortcuts: they belong to the screen reader.
  await page.keyboard.press('r');
  expect(await focusedId(page)).toBe('1');
  await page.keyboard.press('Escape');
  await expect(page.locator('#composer-input')).toBeFocused();
});

test('Tab reaches only the current message’s controls, then leaves the transcript', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await focusFromComposer(page);
  await page.keyboard.press('ArrowUp');
  expect(await focusedId(page)).toBe('5');
  const visited = [];
  for (let step = 0; step < 12; step += 1) {
    await page.keyboard.press('Tab');
    const where = await page.evaluate(() => {
      const active = document.activeElement;
      return { id: active?.closest('article')?.dataset.messageId ?? null, inList: Boolean(active?.closest('#message-list')) };
    });
    if (!where.inList) break;
    visited.push(where.id);
  }
  expect(visited.length).toBeGreaterThan(0);
  expect(new Set(visited)).toEqual(new Set(['5']));
  // Esc from one of its controls returns to the message.
  await message(page, 5).focus();
  await page.keyboard.press('Tab');
  await page.keyboard.press('Escape');
  expect(await page.evaluate(() => document.activeElement.dataset.messageId)).toBe('5');
});

test('Enter opens the actions menu; Esc closes it and returns to the message; Reply goes to the composer', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await focusFromComposer(page);
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('Enter');
  const menu = page.getByRole('menu', { name: 'Message actions' });
  await expect(menu).toBeVisible();
  await expect(menu.getByRole('menuitem')).toHaveText(['Reply', 'React…', 'Copy text', 'Go to the original message', 'Report', 'Open Priya’s profile']);
  await expect(menu.getByRole('menuitem', { name: 'Reply' })).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem', { name: 'React…' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(menu).toHaveCount(0);
  expect(await focusedId(page)).toBe('5');

  await page.keyboard.press('Shift+F10');
  await expect(menu).toBeVisible();
  await menu.getByRole('menuitem', { name: 'Reply' }).press('Enter');
  await expect(page.locator('#composer-input')).toBeFocused();
  await expect(page.locator('#reply-banner')).toContainText('Replying to Priya');
});

test('going to the original moves focus there and says so', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await message(page, 5).locator('button.reply-preview').click();
  expect(await focusedId(page)).toBe('1');
  await expect(page.locator('#announcer')).toHaveText('Original message from Rowan');
  await expect(message(page, 1)).toHaveAttribute('tabindex', '0');
});

test('a focused message reads who, reply, text, time and extras; own messages say "You"', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await expect(message(page, 1)).toHaveAccessibleName(/^Rowan\. Anyone else taking the slow route through Sunday\? \S.+$/);
  await expect(message(page, 2)).toHaveAccessibleName(/^You\. Coffee, a good playlist, and absolutely no plans\. .+ Reactions: ❤️ 1 person\.$/);
  await expect(message(page, 5)).toHaveAccessibleName(/^Priya\. Replying to Rowan: Anyone else taking the slow route through Sunday\? Yes please! \S/);
});

test('↑ on the oldest loaded message loads older history and keeps focus on it', async ({ page }) => {
  const recent = Array.from({ length: 100 }, (_, index) => roomMessage(101 + index, index % 2 ? rowan : priya, `Recent ${101 + index}`));
  await fixture(page, { messages: recent });
  await page.route('**/api/v1/rooms/messages.php?**', async (route) => {
    const before = new URL(route.request().url()).searchParams.get('before_id');
    const older = Array.from({ length: 20 }, (_, index) => roomMessage(81 + index, rowan, `Older ${81 + index}`));
    await route.fulfill({ json: { messages: before ? older : recent } });
  });
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(100);
  await message(page, 200).focus();
  await page.keyboard.press('Home');
  expect(await focusedId(page)).toBe('101');
  await page.keyboard.press('ArrowUp');
  await expect(page.locator('#message-list article.message')).toHaveCount(120);
  await expect.poll(() => focusedId(page)).toBe('101');
  await expect(page.locator('#announcer')).toHaveText('20 older messages loaded.');
});

test('when the current message disappears, focus moves to the next one', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await message(page, 3).focus();
  await page.evaluate(() => document.querySelector('#message-list article[data-message-id="3"]').closest('li').remove());
  await expect.poll(() => focusedId(page)).toBe('4');
  await expect(page.locator('#message-list article[tabindex="0"]')).toHaveCount(1);
});

test('direct messages use the same keyboard model', async ({ page }) => {
  await fixture(page);
  await page.goto('/messages.php?user=2');
  const list = page.locator('#dm-message-list');
  await expect(list.locator('article.dm-message')).toHaveCount(4);
  await expect(list.locator('article[tabindex="0"]')).toHaveCount(1);
  await list.locator('article[tabindex="0"]').focus();
  await page.keyboard.press('Home');
  expect(await page.evaluate(() => document.activeElement.dataset.messageId)).toBe('1');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('menu', { name: 'Message actions' }).getByRole('menuitem', { name: 'Reply' })).toBeFocused();
  await page.keyboard.press('Escape');
  await page.keyboard.press('Escape');
  await expect(page.locator('#dm-message-input')).toBeFocused();
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: focus ring, menu and transcript pass axe`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page);
    await openRoom(page);
    await focusFromComposer(page);
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menu')).toBeVisible();
    const result = await new AxeBuilder({ page }).include('#message-list').include('.message-menu').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations).toEqual([]);
  });
}

test.describe('touch', () => {
  test.use({ hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 } });

  test('tapping a message opens its actions as a bottom sheet', async ({ page }) => {
    await fixture(page);
    await openRoom(page);
    await message(page, 4).locator('.message-body').tap();
    const sheet = page.locator('.message-menu-sheet');
    await expect(sheet).toBeVisible();
    await expect(sheet.getByRole('menuitem').first()).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  });
});
