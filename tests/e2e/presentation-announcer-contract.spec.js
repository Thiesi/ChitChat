import { expect, test } from '@playwright/test';

// The screen-reader announcer contract for S1 (#166, scripts 1–5 of the
// replacement gate). A second participant, Rowan, is simulated through a
// stand-in EventSource and a stateful API mock, so history fetched later agrees
// with what was sent live. Every line the announcer speaks is recorded in
// order, so each test asserts the exact sequence, not just the last line: a
// line spoken twice, or an extra line, fails.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const priya = { id: 3, username: 'Priya' };

const LIVING_ROOM = 1;
const BOARD_GAMES = 2;
const roomDefaults = {
  visibility: 'public', member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0,
  info_line: 'A place for everyday conversations.', guest_access: 'none',
};

let clock = Date.UTC(2026, 9, 4, 10, 0, 0);
function message(id, roomId, sender, body, extra = {}) {
  clock += 60_000;
  return {
    id, room_id: roomId, sender_id: sender.id, username: sender.username, body, type: 'text', deleted: false,
    mentions: [], reactions: [], reply_to: null, attachment: null, edited_at: null,
    created_at: new Date(clock).toISOString(), ...extra,
  };
}

/** A small in-memory server: rooms, their messages, and what the mutation decorator reads. */
function createServer() {
  const rooms = [
    { id: LIVING_ROOM, key: 'room-1', name: 'The living room', unread_count: 0, last_read_message_id: 5, ...roomDefaults },
    { id: BOARD_GAMES, key: 'room-2', name: 'Board games night', unread_count: 0, last_read_message_id: 50, ...roomDefaults },
  ];
  const messages = [
    message(1, LIVING_ROOM, rowan, 'Anyone else taking the slow route through Sunday?'),
    message(2, LIVING_ROOM, viewer, 'Coffee, a good playlist, and absolutely no plans.'),
    message(3, LIVING_ROOM, priya, 'That sounds like the right kind of afternoon.'),
    message(4, LIVING_ROOM, rowan, 'Found a tiny record shop yesterday.'),
    message(5, LIVING_ROOM, viewer, 'What are we listening to?'),
    message(50, BOARD_GAMES, priya, 'Who has the expansion?'),
  ];
  return { rooms, messages, nextId: 100 };
}

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

/** Records every non-empty text the announcer speaks, in order. */
async function recordAnnouncements(page) {
  await page.evaluate(() => {
    const region = document.getElementById('announcer');
    window.__spoken = [];
    new MutationObserver(() => {
      const text = region.textContent.trim();
      if (text !== '') window.__spoken.push(text);
    }).observe(region, { childList: true, characterData: true, subtree: true });
  });
}
const spoken = (page) => page.evaluate(() => window.__spoken);
// Longer than the announcer's burst window (2 s) plus its 50 ms repeat delay,
// so anything still queued would have been spoken by then.
const settle = (page) => page.waitForTimeout(2300);

async function fixture(page) {
  const server = createServer();
  await stubEventSource(page);
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1/', '');
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    const roomId = Number(url.searchParams.get('room_id') ?? LIVING_ROOM);
    const inRoom = server.messages.filter((entry) => entry.room_id === roomId);
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms: server.rooms.map((room) => ({ ...room })) },
      'rooms/messages.php': { messages: inRoom },
      'rooms/message-mutations.php': { messages: inRoom.map((entry) => ({
        id: entry.id, body: entry.body, deleted: entry.deleted, edited_at: entry.edited_at,
        can_edit: entry.sender_id === viewer.id, can_delete: entry.sender_id === viewer.id, can_moderate: false,
      })) },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: roomId, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: inRoom.at(-1)?.id ?? 0 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
      'typing.php': { signalled: true },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return server;
}

/** The simulated second participant: everything Rowan does reaches the page as it would from the server. */
function participant(page, server, who) {
  return {
    async send(roomId, body, extra = {}) {
      const sent = message(server.nextId++, roomId, who, body, extra);
      server.messages.push(sent);
      await page.evaluate((payload) => window.__emit('room_message', { message: payload }), sent);
      return sent;
    },
    // An edit reaches other clients as a refreshed mutation state; the
    // decorator re-reads it whenever the room's stream moves.
    async edit(sent, body) {
      sent.body = body;
      sent.edited_at = new Date(clock += 30_000).toISOString();
      await page.evaluate((payload) => window.__emit('room_message', { message: payload }), sent);
    },
    async react(sent, emoji) {
      sent.reactions = [{ emoji, users: [who] }];
      await page.evaluate((payload) => window.__emit('message_reaction_changed', payload), {
        message_kind: 'room', room_id: sent.room_id, message_id: sent.id, reactions: sent.reactions,
      });
    },
  };
}

const article = (page, id) => page.locator(`#message-list article[data-message-id="${id}"]`);

async function openLivingRoom(page) {
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(5);
  await recordAnnouncements(page);
}

test('the page has one polite, atomic announcer, and the transcript is not a live region', async ({ page }) => {
  await fixture(page);
  await openLivingRoom(page);
  const region = page.locator('#announcer');
  await expect(region).toHaveAttribute('role', 'status');
  await expect(region).toHaveAttribute('aria-live', 'polite');
  await expect(region).toHaveAttribute('aria-atomic', 'true');
  expect(await page.locator('[id="announcer"]').count()).toBe(1);
  // Nothing in or around the transcript speaks on its own: no live region,
  // status or log role on the list, its messages or any ancestor.
  const speaking = await page.evaluate(() => {
    const list = document.getElementById('message-list');
    const live = (node) => (node.getAttribute('aria-live') ?? 'off') !== 'off' || ['status', 'log', 'alert'].includes(node.getAttribute('role'));
    const inside = [...list.querySelectorAll('*')].filter(live).length;
    const around = [];
    for (let node = list; node && node !== document.documentElement; node = node.parentElement) if (live(node)) around.push(node.id || node.tagName);
    return { inside, around };
  });
  expect(speaking).toEqual({ inside: 0, around: [] });
});

test('script 1: returning to a room says how many arrived, once, without reading them', async ({ page }) => {
  const server = await fixture(page);
  const helper = participant(page, server, rowan);
  await openLivingRoom(page);

  await page.locator('.room-button', { hasText: 'Board games night' }).click();
  await expect(page.locator('#message-list article.message')).toHaveCount(1);
  // Rowan writes in the room the viewer just left: nothing is spoken there.
  await helper.send(LIVING_ROOM, 'one');
  await helper.send(LIVING_ROOM, 'two');
  await helper.send(LIVING_ROOM, 'three');
  await settle(page);
  expect(await spoken(page)).toEqual([]);

  await page.locator('.room-button', { hasText: 'The living room' }).click();
  await expect(page.locator('#message-list article.message')).toHaveCount(8);
  await settle(page);
  expect(await spoken(page)).toEqual(['The living room. 3 new messages.']);
});

test('script 2: one message while typing is read once, and the draft and focus stay', async ({ page }) => {
  const server = await fixture(page);
  const helper = participant(page, server, rowan);
  await openLivingRoom(page);
  const composer = page.locator('#composer-input');
  await composer.click();
  await page.keyboard.type('hello');

  await helper.send(LIVING_ROOM, "Saturday works. I'll bring the board game.");
  await page.keyboard.type(' there');
  await settle(page);
  expect(await spoken(page)).toEqual(["Rowan: Saturday works. I'll bring the board game."]);
  await expect(composer).toBeFocused();
  await expect(composer).toHaveValue('hello there');
});

test('script 3: an edit and a reaction from someone else stay silent; the message shows them', async ({ page }) => {
  const server = await fixture(page);
  const helper = participant(page, server, rowan);
  await openLivingRoom(page);
  const sent = await helper.send(LIVING_ROOM, "Saturday works. I'll bring the board game.");
  await settle(page);
  expect(await spoken(page)).toEqual(["Rowan: Saturday works. I'll bring the board game."]);

  await helper.edit(sent, "Saturday works. I'll bring two board games.");
  await expect(article(page, sent.id).locator('.message-edited-indicator')).toHaveText('edited');
  await expect(article(page, sent.id)).toContainText('two board games');
  await helper.react(sent, '🎉');
  await expect(article(page, sent.id).locator('.reaction-bar')).toContainText('🎉');
  await settle(page);
  expect(await spoken(page)).toEqual(["Rowan: Saturday works. I'll bring the board game."]);
});

test('script 3: own messages are never spoken', async ({ page }) => {
  const server = await fixture(page);
  await openLivingRoom(page);
  await participant(page, server, viewer).send(LIVING_ROOM, 'My own message.');
  await expect(page.locator('#message-list article.message')).toHaveCount(6);
  await settle(page);
  expect(await spoken(page)).toEqual([]);
});

// #286: with resume=settled, a reconnect may resend events above the stored position.
// The page drops them by data.id before any handler, so nothing is spoken or counted twice.
test('settled resume: events resent after a reconnect are not spoken again or counted twice', async ({ page }) => {
  const server = await fixture(page);
  await page.route('**/api/v1/session.php', (route) => route.fulfill({ json: {
    user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [],
    capabilities: { realtime_settled_resume: true },
  } }));
  await page.route('**/api/v1/rooms/list.php', (route) => route.fulfill({ json: {
    rooms: server.rooms.map((room) => ({ ...room, unread_settled_message_id: room.last_read_message_id, unread_counted_ids: [] })),
  } }));
  await openLivingRoom(page);
  expect(await page.evaluate(() => window.__sources.map((source) => new URL(source.url, location.href).search))).toEqual(['?resume=settled']);

  // Each frame as the server sends it: data.id is the event, lastEventId the resume position.
  const frames = [];
  const emit = (type, payload, id, position) => page.evaluate(({ type, payload, id, position }) => {
    for (const source of window.__sources) {
      if (source.readyState !== 2) source.dispatchEvent(new MessageEvent(type, { data: JSON.stringify({ id, type, payload }), lastEventId: position }));
    }
  }, { type, payload, id, position });
  const send = async (roomId, body, eventId) => {
    const sent = message(server.nextId++, roomId, rowan, body);
    server.messages.push(sent);
    frames.push([{ message: sent }, eventId]);
    await emit('room_message', { message: sent }, eventId, '1000');
  };

  await send(LIVING_ROOM, 'Is the record shop open on Sundays?', 1001);
  await send(BOARD_GAMES, 'I found the expansion.', 1002);
  await expect(page.locator('#message-list article.message')).toHaveCount(6);
  const boardGames = page.locator('.room-button', { hasText: 'Board games night' }).locator('.unread-count');
  await expect(boardGames).toHaveText('1 unread');
  await settle(page);
  expect(await spoken(page)).toEqual(['Rowan: Is the record shop open on Sundays?']);

  // The connection drops; EventSource reconnects from position 1000 and both events come again.
  await page.evaluate(() => window.__sources[0].dispatchEvent(new Event('error')));
  await page.evaluate(() => window.__sources[0].dispatchEvent(new Event('open')));
  for (const [payload, eventId] of frames) await emit('room_message', payload, eventId, '1000');
  await settle(page);
  expect(await spoken(page)).toEqual(['Rowan: Is the record shop open on Sundays?']);
  await expect(page.locator('#message-list article.message')).toHaveCount(6);
  await expect(boardGames).toHaveText('1 unread');
});

test('script 4: a fast burst is one summary line, not one line per message', async ({ page }) => {
  const server = await fixture(page);
  const helper = participant(page, server, rowan);
  await openLivingRoom(page);
  for (const body of ['a', 'b', 'c', 'd', 'e']) await helper.send(LIVING_ROOM, body);
  await expect(page.locator('#message-list article.message')).toHaveCount(10);
  await settle(page);
  expect(await spoken(page)).toEqual(['5 new messages from Rowan.']);
});

test('script 4: up to three quick messages are read together in one line', async ({ page }) => {
  const server = await fixture(page);
  await openLivingRoom(page);
  await participant(page, server, rowan).send(LIVING_ROOM, 'Saturday?');
  await participant(page, server, priya).send(LIVING_ROOM, 'Works for me.');
  await settle(page);
  expect(await spoken(page)).toEqual(['Rowan: Saturday? Priya: Works for me.']);
});

test('script 5: messages that arrive while the tab is hidden are one line on return', async ({ page }) => {
  const server = await fixture(page);
  const helper = participant(page, server, rowan);
  await openLivingRoom(page);
  const setVisibility = (state) => page.evaluate((value) => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => value });
    document.dispatchEvent(new Event('visibilitychange'));
  }, state);

  await setVisibility('hidden');
  await helper.send(LIVING_ROOM, 'While you were out 1');
  await helper.send(LIVING_ROOM, 'While you were out 2');
  await settle(page);
  expect(await spoken(page)).toEqual([]);

  await setVisibility('visible');
  await settle(page);
  expect(await spoken(page)).toEqual(['2 new messages while you were away.']);
  // Those two are not read again afterwards, also when the stream repeats them.
  await page.evaluate((list) => list.forEach((payload) => window.__emit('room_message', { message: payload })), server.messages.slice(-2));
  await settle(page);
  expect(await spoken(page)).toEqual(['2 new messages while you were away.']);
});


// S5b (#171): "Announce new messages" on this device. All is the default
// (every script above); the other two filter incoming messages only.
const prefer = (page, value) => page.addInitScript((choice) => window.localStorage.setItem('chitchat.announce', choice), value);

test('only messages to me: a plain message stays silent; a mention and a ping are read', async ({ page }) => {
  const server = await fixture(page);
  await prefer(page, 'to-me');
  const helper = participant(page, server, rowan);
  await openLivingRoom(page);
  await helper.send(LIVING_ROOM, 'Anyone around?');
  await expect(page.locator('#message-list article.message')).toHaveCount(6);
  await settle(page);
  expect(await spoken(page)).toEqual([]);

  await helper.send(LIVING_ROOM, '@Jamie could you bring the spare dice?', { mentions: [{ user_id: viewer.id, username: viewer.username }] });
  await settle(page);
  expect(await spoken(page)).toEqual(['Mention from Rowan: @Jamie could you bring the spare dice?']);

  await page.evaluate((payload) => window.__emit('ping', { ping: payload }), {
    id: 7, room_id: LIVING_ROOM, sender: rowan, target: { id: viewer.id, username: viewer.username }, message: 'Dinner?', created_at: new Date(clock += 60_000).toISOString(),
  });
  await settle(page);
  expect(await spoken(page)).toEqual(['Mention from Rowan: @Jamie could you bring the spare dice?', 'Ping from Rowan: Dinner?']);
});

test('off: nothing incoming is read, also on return to the tab; opening a room still says its count', async ({ page }) => {
  const server = await fixture(page);
  await prefer(page, 'off');
  const helper = participant(page, server, rowan);
  await openLivingRoom(page);
  await helper.send(LIVING_ROOM, '@Jamie are you there?', { mentions: [{ user_id: viewer.id, username: viewer.username }] });
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await helper.send(LIVING_ROOM, 'While you were out');
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await settle(page);
  expect(await spoken(page)).toEqual([]);

  // What the viewer does is still spoken.
  await page.locator('.room-button', { hasText: 'Board games night' }).click();
  await expect(page.locator('#message-list article.message')).toHaveCount(1);
  await helper.send(LIVING_ROOM, 'one');
  await helper.send(LIVING_ROOM, 'two');
  await page.locator('.room-button', { hasText: 'The living room' }).click();
  await expect(page.locator('#message-list article.message')).toHaveCount(9);
  await settle(page);
  expect(await spoken(page)).toEqual(['The living room. 2 new messages.']);
});
