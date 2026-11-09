import { expect, test } from '@playwright/test';

// The screen-reader contract for S1 (#166, scripts 6–9 of the replacement
// gate), asserted on the accessibility tree rather than on markup: the
// transcript is one Tab stop that ↑/↓/Home/End walk; each message's name reads
// who, reply context, text, time, then edited/file/reactions; the actions menu
// and Escape return focus; loading older history is announced with focus
// kept; and browse order (day headings, then messages) matches arrow order.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const priya = { id: 3, username: 'Priya' };
const rooms = [{
  id: 1, name: 'The living room', unread_count: 0, last_read_message_id: 6, key: 'room-0', visibility: 'public',
  member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'none',
}];

function roomMessage(id, sender, body, at, extra = {}) {
  return {
    id, room_id: 1, sender_id: sender.id, username: sender.username, body, type: 'text', deleted: false,
    mentions: [], reactions: [], reply_to: null, attachment: null, created_at: at, ...extra,
  };
}

const history = [
  roomMessage(1, rowan, 'Anyone else taking the slow route through Sunday?', '2026-10-03T21:14:00Z'),
  roomMessage(2, viewer, 'Coffee, a good playlist, and absolutely no plans.', '2026-10-03T21:20:00Z', { reactions: [{ emoji: '❤️', users: [rowan] }] }),
  roomMessage(3, priya, 'Here are the tickets.', '2026-10-04T09:02:00Z'),
  roomMessage(4, rowan, 'Found a tiny record shop yesterday.', '2026-10-04T09:05:00Z'),
  roomMessage(5, priya, 'Yes please!', '2026-10-04T09:07:00Z', { reply_to: { message_id: 1, available: true, message: { id: 1, sender: { id: 2, username: 'Rowan' }, body: 'Anyone else taking the slow route through Sunday?', deleted: false } } }),
  roomMessage(6, viewer, 'What are we listening to?', '2026-10-04T09:10:00Z'),
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
      'rooms/message-mutations.php': { messages: messages.map((entry) => ({
        ...entry, edited_at: entry.id === 4 ? '2026-10-04T09:06:00Z' : null,
        can_edit: entry.sender_id === 1, can_delete: entry.sender_id === 1, can_moderate: false,
      })) },
      'attachments/metadata.php': { attachments: messages.some((entry) => entry.id === 3) ? [{
        id: 9, message_id: 3, name: 'lisbon-tickets.pdf', mime_type: 'application/pdf', size_bytes: 240000, previewable: false, sha256: 'x',
      }] : [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 6 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [{ user: rowan, unread_count: 0, last_message: { body: 'Hi', outgoing: false } }] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: messages.slice(0, 2).map((entry) => ({
        id: entry.id, body: entry.body, created_at: entry.created_at, mentions: [], reactions: [], reply_to: null,
        sender: { id: entry.sender_id, username: entry.username }, recipient: entry.sender_id === 1 ? rowan : viewer,
        outgoing: entry.sender_id === 1, read_at: null,
      })) },
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
      'users/profile.php': { profile: { id: 2, username: 'Rowan', member_since: '2026-01-01T00:00:00Z', badge: null, has_avatar: false } },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

const list = (page) => page.locator('#message-list');
const message = (page, id) => page.locator(`#message-list article[data-message-id="${id}"]`);
const focusedId = (page) => page.evaluate(() => document.activeElement?.closest('article')?.dataset.messageId ?? null);

async function openRoom(page) {
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(6);
  // Wait for the decorators (edited state, file card, own-message controls) the names include.
  await expect(message(page, 4).locator('.message-edited-indicator')).toBeVisible();
  await expect(message(page, 3).locator('.attachment-card')).toBeVisible();
  await expect(message(page, 6).locator('.message-mutation-button').first()).toBeVisible();
}

// Times are locale- and time-zone-formatted; the contract is that one follows the text.
// Slashes are escaped because the pattern sits inside a /regex/ in the snapshot template.
const TIME = String.raw`\d{1,2}\/\d{1,2}\/\d{2}, \d{1,2}:\d{2}\s?[AP]M`;

/** The accessible role and name of whatever has focus, read from the accessibility tree. */
async function focusedNode(page) {
  const first = (await page.locator(':focus').ariaSnapshot()).split('\n')[0];
  const match = /^- '?(\w+)(?: "(.*?)")?/.exec(first);
  return { role: match?.[1] ?? null, name: match?.[2] ?? null };
}

/** Headings and article names in browse (tree) order. */
async function browseOrder(page) {
  return (await list(page).ariaSnapshot()).split('\n')
    .map((line) => /^ {4}- '?(heading|article) "(.*?)"/.exec(line))
    .filter(Boolean)
    .map(([, role, name]) => `${role}: ${name}`);
}

async function focusFromComposer(page) {
  await page.locator('#composer-input').focus();
  for (let step = 0; step < 4; step += 1) {
    await page.keyboard.press('Shift+Tab');
    if (await focusedId(page)) return;
  }
}

function recordAnnouncements(page) {
  return page.evaluate(() => {
    const region = document.getElementById('announcer');
    window.__spoken = [];
    new MutationObserver(() => {
      const text = region.textContent.trim();
      if (text !== '') window.__spoken.push(text);
    }).observe(region, { childList: true, characterData: true, subtree: true });
  });
}
const spoken = (page) => page.evaluate(() => window.__spoken);

test('scripts 6 and 9: a "Messages" list of day headings and named messages, in reading order', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await expect(list(page)).toMatchAriaSnapshot(`
    - list "Messages":
      - listitem:
        - heading "October 3, 2026" [level=3]
      - listitem:
        - 'article /^Rowan\\. Anyone else taking the slow route through Sunday\\? ${TIME}$/'
      - listitem:
        - 'article /^You\\. Coffee, a good playlist, and absolutely no plans\\. ${TIME} Reactions: ❤️ 1 person\\.$/'
      - listitem:
        - heading "October 4, 2026" [level=3]
      - listitem:
        - 'article /^Priya\\. Here are the tickets\\. ${TIME} File: lisbon-tickets\\.pdf\\.$/'
      - listitem:
        - 'article /^Rowan\\. Found a tiny record shop yesterday\\. ${TIME} Edited\\.$/'
      - listitem:
        - 'article /^Priya\\. Replying to Rowan: Anyone else taking the slow route through Sunday\\? Yes please! ${TIME}$/'
      - listitem:
        - 'article /^You\\. What are we listening to\\? ${TIME}$/'
  `);
  // Day headings sit between the days' messages, in browse order (NVDA's H).
  const order = await browseOrder(page);
  expect(order.map((entry) => entry.split(':')[0])).toEqual(['heading', 'article', 'article', 'heading', 'article', 'article', 'article', 'article']);
});

test('script 6: one Tab stop, and ↑/↓/Home/End read the same messages as browse order', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  const articles = (await browseOrder(page)).filter((entry) => entry.startsWith('article: ')).map((entry) => entry.slice(9));
  expect(articles).toHaveLength(6);
  await expect(list(page).locator('[tabindex="0"]')).toHaveCount(1);

  await focusFromComposer(page);
  expect(await focusedNode(page)).toEqual({ role: 'article', name: articles[5] });
  const upwards = [articles[5]];
  for (let step = 0; step < 5; step += 1) {
    await page.keyboard.press('ArrowUp');
    upwards.push((await focusedNode(page)).name);
  }
  expect(upwards).toEqual([...articles].reverse());

  await page.keyboard.press('End');
  expect((await focusedNode(page)).name).toBe(articles[5]);
  await page.keyboard.press('Home');
  expect((await focusedNode(page)).name).toBe(articles[0]);
  const downwards = [articles[0]];
  for (let step = 0; step < 5; step += 1) {
    await page.keyboard.press('ArrowDown');
    downwards.push((await focusedNode(page)).name);
  }
  expect(downwards).toEqual(articles);
  await expect(list(page).locator('[tabindex="0"]')).toHaveCount(1);

  // Shift+Tab leaves the transcript in one step: neither another message nor
  // the scrolling list itself is a Tab stop (Firefox makes scrollers one).
  await page.keyboard.press('Home');
  await page.keyboard.press('Shift+Tab');
  const landed = await page.evaluate(() => {
    const active = document.activeElement;
    return active?.closest('#message-list') ? `${active.tagName.toLowerCase()}#${active.id}` : null;
  });
  expect(landed).toBeNull();
});

test('script 7: Enter opens a named actions menu; Escape returns to the message, then to the message box', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await focusFromComposer(page);
  await page.keyboard.press('ArrowUp');
  const before = await focusedNode(page);
  expect(before.name).toMatch(/^Priya\. Replying to Rowan: /);

  await page.keyboard.press('Enter');
  const menu = page.getByRole('menu', { name: 'Message actions' });
  await expect(menu).toMatchAriaSnapshot(`
    - menu "Message actions":
      - menuitem "Reply"
      - menuitem "React…"
      - menuitem "Copy text"
      - menuitem "Go to the original message"
      - menuitem "Report"
      - menuitem "Open Priya’s profile"
  `);
  expect(await focusedNode(page)).toEqual({ role: 'menuitem', name: 'Reply' });
  await page.keyboard.press('ArrowDown');
  expect(await focusedNode(page)).toEqual({ role: 'menuitem', name: 'React…' });

  await page.keyboard.press('Escape');
  await expect(menu).toHaveCount(0);
  expect(await focusedNode(page)).toEqual(before);
  await page.keyboard.press('Escape');
  await expect(page.locator('#composer-input')).toBeFocused();

  // Shift+Tab comes back to the same message, not the newest; the context-menu
  // key opens the same menu; Reply moves to the message box with the reply named.
  await focusFromComposer(page);
  expect(await focusedNode(page)).toEqual(before);
  await page.keyboard.press('Shift+F10');
  await expect(menu).toBeVisible();
  await page.keyboard.press('Enter');
  await expect(page.locator('#composer-input')).toBeFocused();
  await expect(page.locator('#reply-banner')).toContainText('Replying to Priya');
});

test('script 8: ↑ on the oldest message announces loading, then the count, and focus stays on it', async ({ page }) => {
  const at = (id) => new Date(Date.UTC(2026, 9, 4, 8, 0, 0) + id * 60_000).toISOString();
  const recent = Array.from({ length: 100 }, (_, index) => roomMessage(101 + index, index % 2 ? rowan : priya, `Recent ${101 + index}`, at(101 + index)));
  const older = Array.from({ length: 20 }, (_, index) => roomMessage(81 + index, rowan, `Older ${81 + index}`, at(81 + index)));
  await fixture(page, { messages: recent });
  let release;
  const held = new Promise((resolve) => { release = resolve; });
  await page.route('**/api/v1/rooms/messages.php?**', async (route) => {
    const before = new URL(route.request().url()).searchParams.get('before_id');
    if (before) await held;
    await route.fulfill({ json: { messages: before ? older : recent } });
  });
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(100);
  await recordAnnouncements(page);

  await message(page, 200).focus();
  await page.keyboard.press('Home');
  const oldest = await focusedNode(page);
  expect(oldest.name).toMatch(/^Priya\. Recent 101 /);
  await page.keyboard.press('ArrowUp');
  await expect.poll(() => spoken(page)).toEqual(['Loading older messages…']);
  await expect(list(page)).toHaveAttribute('aria-busy', 'true');
  release();
  await expect(page.locator('#message-list article.message')).toHaveCount(120);
  await expect.poll(() => spoken(page)).toEqual(['Loading older messages…', '20 older messages loaded.']);
  await expect(list(page)).toHaveAttribute('aria-busy', 'false');
  await expect.poll(() => focusedNode(page)).toEqual(oldest);
  // The loaded messages come before it in browse order, and ↑ now reaches them.
  const order = (await browseOrder(page)).filter((entry) => entry.startsWith('article: '));
  expect(order.indexOf(`article: ${oldest.name}`)).toBe(20);
  await page.keyboard.press('ArrowUp');
  expect((await focusedNode(page)).name).toMatch(/^Rowan\. Older 100 /);
});

test('direct messages expose the same tree: day headings, one Tab stop, the same message names', async ({ page }) => {
  await fixture(page);
  await page.goto('/messages.php?user=2');
  const dms = page.locator('#dm-message-list');
  await expect(dms.locator('article.dm-message')).toHaveCount(2);
  await expect(dms.locator('[tabindex="0"]')).toHaveCount(1);
  await expect(dms).toMatchAriaSnapshot(`
    - list:
      - listitem:
        - heading "October 3, 2026" [level=3]
      - listitem:
        - 'article /^Rowan\\. Anyone else taking the slow route through Sunday\\? .+/'
      - listitem:
        - 'article /^You\\. Coffee, a good playlist, and absolutely no plans\\. .+/'
  `);
});
