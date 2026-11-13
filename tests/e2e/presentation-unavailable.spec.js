import { expect, test } from '@playwright/test';

// #326 on the web: the server answers a private room you can't see exactly like
// a missing one (404 room_not_found), and the same for its messages and files.
// The web says so in one plain sentence, matching CCM: when sending, and when a
// link points at a room or message that's no longer there. Presentation only:
// the real page against intercepted APIs.
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1, username_change: null };
const rooms = [{
  id: 1, name: 'The living room', key: 'room-0', visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', unread_count: 0, guest_access: 'none',
}];
const messages = [
  { id: 1, room_id: 1, sender_id: 2, user_id: 2, username: 'Rowan', body: 'Anyone around?', type: 'text', deleted: false, mentions: [], reactions: [], reply_to: null, attachment: null, created_at: '2026-11-12T10:00:00Z' },
];
const notFound = (code, message) => ({ status: 404, json: { error: { code, message } } });

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

async function fixture(page, { overrides = {} } = {}) {
  await stubEventSource(page);
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path in overrides) {
      const value = overrides[path];
      return Number.isInteger(value?.status) ? route.fulfill({ status: value.status, json: value.json }) : route.fulfill({ json: value });
    }
    const responses = {
      'session.php': { user: member, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: structuredClone(messages) },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 1 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

const toasts = (page) => page.locator('#toast-region');

test('sending to a room that is no longer there says so plainly', async ({ page }) => {
  await fixture(page, { overrides: { 'rooms/send.php': notFound('room_not_found', 'Room not found.') } });
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(1);
  await page.locator('#composer-input').fill('Hello?');
  await page.locator('#composer-input').press('Enter');
  // What happened to the draft, in CCM's words; and the draft is still there to send again.
  await expect(toasts(page)).toContainText('This room isn’t available any more. Your message wasn’t sent.');
  await expect(toasts(page)).not.toContainText('Room not found.');
  await expect(page.locator('#composer-input')).toHaveValue('Hello?');
});

test('uploading to a room that is no longer there says the file wasn’t sent, and keeps it', async ({ page }) => {
  await fixture(page, { overrides: { 'attachments/upload.php': notFound('room_not_found', 'Room not found.') } });
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(1);
  await page.locator('#attachment-input').setInputFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') });
  await page.locator('#composer-input').fill('The notes');
  await page.locator('#composer-input').press('Enter');
  await expect(toasts(page)).toContainText('This room isn’t available any more. Your file wasn’t sent.');
  await expect(page.locator('#attachment-name')).toContainText('notes.txt');
  await expect(page.locator('#composer-input')).toHaveValue('The notes');
});

test('a link to a room you can’t see says the room isn’t available', async ({ page }) => {
  test.setTimeout(60_000);
  await fixture(page);
  await page.goto('/?room_id=99&message_id=5');
  await expect(page.locator('#message-list article.message')).toHaveCount(1);
  await expect(toasts(page)).toContainText('This room isn’t available any more.', { timeout: 25_000 });
});

test('a link to a message that is no longer in the room says so', async ({ page }) => {
  await fixture(page);
  await page.goto('/?room_id=1&message_id=5');
  await expect(page.locator('#message-list article.message')).toHaveCount(1);
  await expect(toasts(page)).toContainText('That message isn’t available any more.', { timeout: 15_000 });
});

test('the room header’s search button searches this room (#318)', async ({ page }) => {
  await fixture(page);
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(1);
  const button = page.getByRole('link', { name: 'Search # The living room' });
  await expect(button).toHaveAttribute('href', '/search.php?room_id=1');
});
