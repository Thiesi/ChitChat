import { expect, test } from '@playwright/test';

// #301: when the browser gives up on the event stream (a reconnect answered 503, as during an
// upgrade), the page checks the session and opens a new stream from its last position, keeps
// resume=settled, goes back to "Live", and handles each event once.

const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const LIVING_ROOM = 1;
const BOARD_GAMES = 2;
const roomDefaults = {
  visibility: 'public', member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0,
  info_line: '', guest_access: 'none', unread_count: 0, unread_settled_message_id: 50, unread_counted_ids: [],
};
const rooms = [
  { id: LIVING_ROOM, key: 'room-1', name: 'The living room', last_read_message_id: 5, ...roomDefaults },
  { id: BOARD_GAMES, key: 'room-2', name: 'Board games night', last_read_message_id: 50, ...roomDefaults },
];

function message(id, roomId, body) {
  return {
    id, room_id: roomId, sender_id: rowan.id, username: rowan.username, body, type: 'text', deleted: false,
    mentions: [], reactions: [], reply_to: null, attachment: null, edited_at: null,
    created_at: new Date(Date.UTC(2026, 9, 4, 10, id)).toISOString(),
  };
}
const history = [message(5, LIVING_ROOM, 'Morning, everyone.')];

/** One SSE frame as the server writes it in settled mode: id is the position, data.id the event. */
function frame(position, eventId, sent) {
  return `id: ${position}\nevent: room_message\ndata: ${JSON.stringify({ id: eventId, type: 'room_message', payload: { message: sent } })}\n\n`;
}

test('a stream answered 503 is opened again from its position and handles each event once', async ({ page }) => {
  const first = message(101, BOARD_GAMES, 'Who has the expansion?');
  const second = message(102, BOARD_GAMES, 'Found it under the sofa.');
  const streams = [];
  // The page opens the stream before it loads the room list; the first event waits for the list,
  // so the badge it counts towards exists.
  let listed;
  const roomsListed = new Promise((resolve) => { listed = resolve; });
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') {
      streams.push(url.search);
      if (streams.length === 1) {
        await roomsListed;
        await new Promise((resolve) => setTimeout(resolve, 300));
        // One event, then the stream ends and the browser reconnects by itself.
        return route.fulfill({ contentType: 'text/event-stream', body: `retry: 200\n\n${frame(1000, 1001, first)}` });
      }
      if (streams.length === 2) {
        // The upgrade drain: EventSource closes for good on a non-200 answer.
        return route.fulfill({ status: 503, contentType: 'text/html', body: 'Down for maintenance' });
      }
      // The new stream resends the event above its position, then the next one; no more reconnects.
      return route.fulfill({ contentType: 'text/event-stream', body: `retry: 600000\n\n${frame(1000, 1001, first)}${frame(1001, 1002, second)}` });
    }
    const roomId = Number(url.searchParams.get('room_id') ?? LIVING_ROOM);
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], capabilities: { realtime_settled_resume: true } },
      'rooms/list.php': { rooms: rooms.map((room) => ({ ...room, unread_counted_ids: [] })) },
      'rooms/messages.php': { messages: history.filter((entry) => entry.room_id === roomId) },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: roomId, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 5 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    await route.fulfill({ json: responses[path] ?? {} });
    if (path === 'rooms/list.php') listed();
  });

  // Every text the status line shows, in order (it is a polite live region).
  await page.addInitScript(() => {
    window.__statuses = [];
    document.addEventListener('DOMContentLoaded', () => {
      const node = document.getElementById('connection-status');
      if (!node) return;
      new MutationObserver(() => window.__statuses.push(node.textContent)).observe(node, { childList: true, characterData: true, subtree: true });
    });
  });
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(1);
  const badge = page.locator('.room-button', { hasText: 'Board games night' }).locator('.unread-count');

  // The first event counts; the 503 then leaves the page reconnecting until the new stream opens.
  await expect(badge).toHaveText('1 unread');
  await expect.poll(() => streams.length, { timeout: 15_000 }).toBeGreaterThanOrEqual(3);
  // A mocked response always ends, so the browser then retries by itself ("Reconnecting" again);
  // what matters is that the new stream opened: "Live" after the 503's "Reconnecting".
  await expect.poll(async () => {
    const seen = await page.evaluate(() => window.__statuses);
    const down = seen.indexOf('Reconnecting');
    return down >= 0 && seen.indexOf('Live', down) > down;
  }).toBe(true);
  const shown = await page.evaluate(() => window.__statuses);
  for (let i = 1; i < shown.length; i += 1) expect(shown[i], 'No status is set twice in a row.').not.toBe(shown[i - 1]);
  expect(streams[0]).toBe('?resume=settled');
  expect(streams[2]).toBe('?resume=settled&after_id=1000');

  // The resent event is dropped; the next one counts once.
  await expect(badge).toHaveText('2 unread');
  await page.waitForTimeout(1000);
  await expect(badge).toHaveText('2 unread');
  expect(streams).toHaveLength(3);
});

test('a stream whose session has ended is not reopened: the page signs out', async ({ page }) => {
  let signedIn = true;
  let streams = 0;
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') {
      streams += 1;
      return streams === 1
        ? route.fulfill({ contentType: 'text/event-stream', body: 'retry: 200\n\n' })
        : route.fulfill({ status: 401, json: { error: { code: 'unauthenticated', message: 'Sign in.' } } });
    }
    if (path === 'session.php') {
      return route.fulfill({ json: signedIn
        ? { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [], capabilities: { realtime_settled_resume: true } }
        : { user: null, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] } });
    }
    const responses = {
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: history },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    return route.fulfill({ json: responses[path] ?? {} });
  });

  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  signedIn = false;
  await expect(page.locator('#auth-shell')).toBeVisible({ timeout: 15_000 });
  const settled = streams;
  await page.waitForTimeout(2500);
  expect(streams).toBe(settled);
});
