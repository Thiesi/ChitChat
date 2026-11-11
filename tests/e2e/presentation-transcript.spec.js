import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S1a (#166): the shared transcript for rooms and direct messages. The list is
// an <ol> that never speaks (no aria-live); it updates in place by message id;
// day headings are h3s; one polite announcer speaks new messages from others,
// fed by realtime events. Presentation only: real templates and modules,
// intercepted API, and a stand-in EventSource the test can emit events from.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const priya = { id: 3, username: 'Priya' };
const rooms = [
  { id: 1, name: 'The living room', unread_count: 0, last_read_message_id: 5 },
  { id: 2, name: 'Board games night', unread_count: 3, last_read_message_id: 2 },
].map((room, index) => ({
  ...room, key: `room-${index}`, visibility: 'public', member_role: 'member', minimum_age: 0,
  inactivity_timeout_seconds: 0, info_line: 'A place for everyday conversations.', guest_access: 'none',
}));

function roomMessage(id, sender, body, extra = {}) {
  return {
    id, room_id: 1, sender_id: sender.id, username: sender.username, body, type: 'text', deleted: false,
    mentions: [], reactions: [], reply_to: null, attachment: null,
    // Minutes after 08:00 on the same day, so time order matches id order.
    created_at: new Date(Date.UTC(2026, 9, 4, 8, 0, 0) + id * 60_000).toISOString(), ...extra,
  };
}

const history = [
  roomMessage(1, rowan, 'Anyone else taking the slow route through Sunday?', { created_at: '2026-10-03T21:14:00Z' }),
  roomMessage(2, viewer, 'Coffee, a good playlist, and absolutely no plans.', { created_at: '2026-10-03T21:20:00Z' }),
  roomMessage(3, priya, 'That sounds like the right kind of afternoon.'),
  roomMessage(4, rowan, 'Found a tiny record shop yesterday.'),
  roomMessage(5, viewer, 'What are we listening to?'),
];

async function stubEventSource(page) {
  await page.addInitScript(() => {
    class FakeEventSource extends EventTarget {
      constructor(url) {
        super();
        this.url = url;
        this.readyState = 1;
        (window.__sources ??= []).push(this);
        window.setTimeout(() => this.dispatchEvent(new Event('open')), 0);
      }

      close() { this.readyState = 2; }
    }
    window.EventSource = FakeEventSource;
    window.__emit = (type, payload) => {
      for (const source of window.__sources ?? []) {
        if (source.readyState !== 2) source.dispatchEvent(new MessageEvent(type, { data: JSON.stringify({ type, payload }) }));
      }
    };
  });
}

async function fixture(page, { messages = history } = {}) {
  await stubEventSource(page);
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1/', '');
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    const dmHistory = messages.slice(0, 3).map((message) => ({
      id: message.id, body: message.body, created_at: message.created_at, mentions: [], reactions: [], reply_to: null,
      sender: { id: message.sender_id, username: message.username },
      recipient: message.sender_id === viewer.id ? rowan : viewer,
      outgoing: message.sender_id === viewer.id, read_at: null,
    }));
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 5 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [{ user: rowan, unread_count: 2, last_message: { body: 'Hi', outgoing: false } }] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: dmHistory },
      'direct-messages/read.php': {},
      'direct-messages/users.php': { users: [rowan] },
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
      'typing.php': { signalled: true },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

async function openRoom(page) {
  await page.goto('/');
  // Phones open on the Rooms list with nothing opened behind it (S2c): open the room as a person would.
  if ((page.viewportSize()?.width ?? 1280) < 768) await page.locator('#room-list .room-button').first().click();
  await expect(page.locator('#message-list article.message')).toHaveCount(5);
}

const announcer = (page) => page.locator('#announcer');
const emitRoom = (page, message) => page.evaluate((payload) => window.__emit('room_message', { message: payload }), message);

test('the transcript is a quiet list with day headings, in rooms and direct messages', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  const list = page.locator('#message-list');
  expect(await list.evaluate((node) => node.tagName)).toBe('OL');
  await expect(list).not.toHaveAttribute('aria-live', /.*/);
  await expect(list.locator('> li')).toHaveCount(7);
  await expect(list.getByRole('heading', { level: 3 })).toHaveText(['October 3, 2026', 'October 4, 2026']);
  await expect(announcer(page)).toHaveAttribute('role', 'status');

  await page.goto('/messages.php?user=2');
  const dms = page.locator('#dm-message-list');
  await expect(dms.locator('article.dm-message')).toHaveCount(3);
  expect(await dms.evaluate((node) => node.tagName)).toBe('OL');
  await expect(dms).not.toHaveAttribute('aria-live', /.*/);
  await expect(dms.getByRole('heading', { level: 3 }).first()).toHaveText('October 3, 2026');
});

test('arrivals and reactions update the list in place, keeping existing message nodes', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await page.evaluate(() => { window.__kept = document.querySelector('#message-list article[data-message-id="3"]'); });

  await emitRoom(page, roomMessage(6, rowan, 'Saturday works.'));
  await expect(page.locator('#message-list article.message')).toHaveCount(6);
  expect(await page.evaluate(() => document.querySelector('#message-list article[data-message-id="3"]') === window.__kept)).toBe(true);

  await page.evaluate(() => window.__emit('message_reaction_changed', {
    message_kind: 'room', room_id: 1, message_id: 3, reactions: [{ emoji: '👍', users: [{ id: 2, username: 'Rowan' }] }],
  }));
  await expect(page.locator('#message-list article[data-message-id="3"] .reaction-bar')).toContainText('👍');
  expect(await page.evaluate(() => document.querySelector('#message-list article[data-message-id="3"]') === window.__kept)).toBe(true);
});

test('new messages from others are announced once; own messages, edits and reactions stay silent', async ({ page }) => {
  await fixture(page);
  await openRoom(page);

  await emitRoom(page, roomMessage(6, rowan, "Saturday works. I'll bring Wingspan."));
  await expect(announcer(page)).toHaveText("Rowan: Saturday works. I'll bring Wingspan.");

  // The same message again (for example SSE plus history) is neither shown nor spoken twice.
  await emitRoom(page, roomMessage(6, rowan, "Saturday works. I'll bring Wingspan."));
  await emitRoom(page, roomMessage(7, viewer, 'My own message.'));
  await page.evaluate(() => window.__emit('message_reaction_changed', { message_kind: 'room', room_id: 1, message_id: 6, reactions: [{ emoji: '🎉', users: [{ id: 3, username: 'Priya' }] }] }));
  await page.waitForTimeout(1200);
  await expect(announcer(page)).toHaveText("Rowan: Saturday works. I'll bring Wingspan.");
  await expect(page.locator('#message-list article[data-message-id="6"]')).toHaveCount(1);

  await emitRoom(page, roomMessage(8, priya, '@Jamie could you bring the spare dice?', { mentions: [{ user_id: 1, username: 'Jamie' }] }));
  await expect(announcer(page)).toHaveText('Mention from Priya: @Jamie could you bring the spare dice?');

  await emitRoom(page, roomMessage(9, priya, '', { attachment: { id: 4, name: 'lisbon-tickets.pdf', mime_type: 'application/pdf', size_bytes: 240000 } }));
  await expect(announcer(page)).toHaveText('Priya sent a file: lisbon-tickets.pdf');

  const long = 'a'.repeat(200);
  await emitRoom(page, roomMessage(10, rowan, long));
  await expect(announcer(page)).toHaveText(`Rowan: ${'a'.repeat(140)}…`);
});

test('a burst is summarised, and messages that arrive while the tab is hidden are counted', async ({ page }) => {
  await fixture(page);
  await openRoom(page);

  for (const [id, sender] of [[6, rowan], [7, priya], [8, rowan], [9, priya], [10, rowan]]) {
    await emitRoom(page, roomMessage(id, sender, `Quick ${id}`));
  }
  await expect(announcer(page)).toHaveText('5 new messages from Rowan and Priya.');

  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await emitRoom(page, roomMessage(11, rowan, 'While away 1'));
  await emitRoom(page, roomMessage(12, priya, 'While away 2'));
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await expect(announcer(page)).toHaveText('2 new messages while you were away.');
});

test('entering a room or conversation with unread messages says how many', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await page.locator('.room-button', { hasText: 'Board games night' }).click();
  await expect(announcer(page)).toHaveText('Board games night. 3 new messages.');

  await page.goto('/messages.php?user=2');
  await expect(page.locator('#dm-message-list article.dm-message')).toHaveCount(3);
  await page.evaluate(() => window.__emit('direct_message', { message: {
    id: 40, body: 'See you there', created_at: '2026-10-04T11:00:00Z', mentions: [], reactions: [], reply_to: null,
    sender: { id: 2, username: 'Rowan' }, recipient: { id: 1, username: 'Jamie' }, outgoing: false, read_at: null,
  } }));
  await expect(announcer(page)).toHaveText('Rowan: See you there');
});

test('loading older history marks the list busy and says how many loaded', async ({ page }) => {
  const recent = Array.from({ length: 100 }, (_, index) => roomMessage(101 + index, index % 2 ? rowan : priya, `Recent ${101 + index}`));
  await fixture(page, { messages: recent });
  await page.route('**/api/v1/rooms/messages.php?**', async (route) => {
    const before = new URL(route.request().url()).searchParams.get('before_id');
    const page20 = Array.from({ length: 20 }, (_, index) => roomMessage(81 + index, rowan, `Older ${81 + index}`));
    await route.fulfill({ json: { messages: before ? page20 : recent } });
  });
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(100);
  await page.getByRole('button', { name: 'Load older messages' }).click();
  await expect(page.locator('#message-list article.message')).toHaveCount(120);
  await expect(announcer(page)).toHaveText('20 older messages loaded.');
  await expect(page.locator('#message-list')).toHaveAttribute('aria-busy', 'false');
});

test('direct messages announce older history and mark the list busy, like rooms', async ({ page }) => {
  const dm = (id, body) => ({
    id, body, created_at: new Date(Date.UTC(2026, 9, 4, 8, 0, 0) + id * 60_000).toISOString(), mentions: [], reactions: [], reply_to: null,
    sender: rowan, recipient: viewer, outgoing: false, read_at: null,
  });
  const recent = Array.from({ length: 100 }, (_, index) => dm(201 + index, `Recent ${201 + index}`));
  // A full page, so Load older stays available for the empty page after it.
  const older = Array.from({ length: 100 }, (_, index) => dm(101 + index, `Older ${101 + index}`));
  await fixture(page);
  let release;
  const held = new Promise((resolve) => { release = resolve; });
  let olderEmpty = false;
  await page.route('**/api/v1/direct-messages/history.php?**', async (route) => {
    const before = new URL(route.request().url()).searchParams.get('before_id');
    if (before && !olderEmpty) await held;
    await route.fulfill({ json: { messages: before ? (olderEmpty ? [] : older) : recent } });
  });
  await page.goto('/messages.php?user=2');
  const list = page.locator('#dm-message-list');
  await expect(list.locator('article.dm-message')).toHaveCount(100);
  await page.locator('#dm-load-older').click();
  await expect(announcer(page)).toHaveText('Loading older messages…');
  await expect(list).toHaveAttribute('aria-busy', 'true');
  release();
  await expect(list.locator('article.dm-message')).toHaveCount(200);
  await expect(announcer(page)).toHaveText('100 older messages loaded.');
  await expect(list).toHaveAttribute('aria-busy', 'false');

  // Nothing older the next time: says so.
  olderEmpty = true;
  await page.locator('#dm-load-older').click();
  await expect(announcer(page)).toHaveText('No older messages.');
  await expect(list).toHaveAttribute('aria-busy', 'false');
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: the transcript passes axe and reflows at 320px`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page);
    await page.setViewportSize({ width: 320, height: 720 });
    await openRoom(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    const result = await new AxeBuilder({ page }).include('#message-list').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations).toEqual([]);
  });
}

test('the profile card stays on screen when its content grows after opening', async ({ page }) => {
  await fixture(page);
  await page.setViewportSize({ width: 1280, height: 600 });
  await openRoom(page);
  await page.locator('#message-list article[data-message-id="4"] .message-author').click();
  const card = page.locator('#name-menu');
  await expect(card).toBeVisible();
  // The moderation part arrives later and makes the card taller; it must move to stay in view.
  await card.evaluate((node) => {
    const filler = document.createElement('div');
    filler.style.height = '420px';
    node.append(filler);
  });
  await expect.poll(async () => {
    const box = await card.boundingBox();
    return box !== null && box.y >= 0 && box.y + box.height <= 600;
  }).toBe(true);
});
