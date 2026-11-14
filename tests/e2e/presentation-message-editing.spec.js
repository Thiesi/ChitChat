import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S7 (#173): no browser prompts for messages. Editing happens in the bubble
// (Enter saves, Shift+Enter is a new line, Esc cancels, a failed save keeps
// the edit); deleting asks in a styled dialog with Cancel first. Room and
// direct messages alike. Presentation only: the real pages against
// intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const rooms = [{
  id: 1, name: 'The living room', unread_count: 0, last_read_message_id: 4, key: 'room-0', visibility: 'public',
  member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'none',
}];
const at = (id) => new Date(Date.UTC(2026, 9, 4, 8, 0, 0) + id * 60_000).toISOString();
const roomMessage = (id, sender, body) => ({
  id, room_id: 1, sender_id: sender.id, username: sender.username, body, type: 'text', deleted: false,
  mentions: [], reactions: [], reply_to: null, attachment: null, created_at: at(id),
});
const history = [
  roomMessage(1, rowan, 'Game night moves to Saturday.'),
  roomMessage(2, viewer, 'I can bring Catan.'),
];
// What the server knows now: an edit changes it, so the page's routine refresh agrees.
let saved = {};
const states = () => history.map((message) => ({
  ...message, edited_at: null, can_edit: message.sender_id === 1, can_delete: message.sender_id === 1, can_moderate: false, ...(saved[message.id] ?? {}),
}));

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
  saved = {};
  await stubEventSource(page);
  const requests = [];
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON() });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path in overrides) {
      const value = typeof overrides[path] === 'function' ? overrides[path](request) : overrides[path];
      return Number.isInteger(value?.status) ? route.fulfill({ status: value.status, json: value.json }) : route.fulfill({ json: value });
    }
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: structuredClone(history) },
      'rooms/message-mutations.php': { messages: states() },
      'rooms/edit-message.php': (() => {
        if (path !== 'rooms/edit-message.php') return null;
        saved[2] = { body: request.postDataJSON().body, edited_at: '2026-10-04T10:30:00Z' };
        return { message: states()[1] };
      })(),
      // The server knows it's gone, so the page's routine refresh agrees (no flicker back).
      'rooms/delete-own-message.php': (() => {
        if (path !== 'rooms/delete-own-message.php') return null;
        saved[2] = { body: null, deleted: true, deletion_kind: 'author', can_edit: false, can_delete: false };
        return { message: states()[1] };
      })(),
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 2 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [{ user: rowan, unread_count: 0, last_message: { body: 'Hi', outgoing: false } }] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: history.map((message) => ({
        id: message.id, body: message.body, created_at: message.created_at, mentions: [], reactions: [], reply_to: null,
        sender: { id: message.sender_id, username: message.username }, recipient: message.sender_id === 1 ? rowan : viewer,
        outgoing: message.sender_id === 1, read_at: null,
      })) },
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [{ id: 2, body: history[1].body, edited_at: null, deleted: false, can_edit: true, can_delete: true, mentions: [], ...(saved[2] ?? {}) }] },
      'direct-messages/edit.php': (() => {
        if (path !== 'direct-messages/edit.php') return null;
        saved[2] = { body: request.postDataJSON().body, edited_at: '2026-10-04T10:30:00Z' };
        return { message: { id: 2, edited_at: null, deleted: false, can_edit: true, can_delete: true, mentions: [], ...saved[2] } };
      })(),
      'direct-messages/delete.php': { message: { id: 2, body: null, edited_at: null, deleted: true, can_edit: false, can_delete: false, mentions: [] } },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

const mine = (page) => page.locator('#message-list article[data-message-id="2"]');
const myDirect = (page) => page.locator('#dm-message-list article[data-message-id="2"]');
const editBox = (scope) => scope.getByRole('textbox', { name: /^Edit/ });
const posted = (requests, path) => requests.filter((entry) => entry.path === path);

async function openRoom(page) {
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(2);
  await expect(mine(page).getByRole('button', { name: 'Edit' })).toBeAttached();
}

async function startEditing(scope) {
  await scope.locator('.message-bubble').hover();
  await scope.getByRole('button', { name: 'Edit' }).click();
  await expect(editBox(scope)).toBeFocused();
}

test('room: Edit opens a text box in the bubble; Enter saves, and focus returns to Edit', async ({ page }) => {
  let native = 0;
  page.on('dialog', (dialog) => { native += 1; void dialog.dismiss(); });
  const requests = await fixture(page);
  await openRoom(page);
  await startEditing(mine(page));
  await expect(editBox(mine(page))).toHaveValue('I can bring Catan.');
  await expect(editBox(mine(page))).toHaveAccessibleDescription('Enter saves · Shift+Enter adds a line · Esc cancels');
  // Shift+Enter is a new line, not a save.
  await editBox(mine(page)).press('End');
  await editBox(mine(page)).press('Shift+Enter');
  await editBox(mine(page)).pressSequentially('And Ticket to Ride.');
  expect(posted(requests, 'rooms/edit-message.php')).toHaveLength(0);
  await editBox(mine(page)).press('Enter');
  await expect.poll(() => posted(requests, 'rooms/edit-message.php').at(-1)?.body).toEqual({ message_id: 2, body: 'I can bring Catan.\nAnd Ticket to Ride.' });
  await expect(editBox(mine(page))).toHaveCount(0);
  await expect(mine(page).locator('.message-body')).toContainText('And Ticket to Ride.');
  await expect(mine(page).locator('.message-edited-indicator')).toHaveText('edited');
  await expect(page.locator('#toast-region')).toContainText('Message edited.');
  await expect(mine(page).getByRole('button', { name: 'Edit' })).toBeFocused();
  expect(native).toBe(0);
});

test('room: Esc cancels and puts the message back; unchanged text sends nothing', async ({ page }) => {
  const requests = await fixture(page);
  await openRoom(page);
  await startEditing(mine(page));
  await editBox(mine(page)).fill('Something else');
  await editBox(mine(page)).press('Escape');
  await expect(editBox(mine(page))).toHaveCount(0);
  await expect(mine(page).locator('.message-body')).toHaveText('I can bring Catan.');
  await expect(mine(page).getByRole('button', { name: 'Edit' })).toBeFocused();
  await startEditing(mine(page));
  await editBox(mine(page)).press('Enter');
  await expect(editBox(mine(page))).toHaveCount(0);
  expect(posted(requests, 'rooms/edit-message.php')).toHaveLength(0);
});

test('room: a failed save says so and keeps the edit open to try again', async ({ page }) => {
  const requests = await fixture(page, { overrides: {
    'rooms/edit-message.php': { status: 403, json: { error: { code: 'muted', message: 'You are muted in this room.' } } },
  } });
  await openRoom(page);
  await startEditing(mine(page));
  await editBox(mine(page)).fill('I can bring Catan and snacks.');
  await editBox(mine(page)).press('Enter');
  await expect(page.locator('#toast-region')).toContainText('You are muted in this room. Your edit wasn’t saved.');
  await expect(editBox(mine(page))).toHaveValue('I can bring Catan and snacks.');
  await expect(editBox(mine(page))).toBeFocused();
  expect(posted(requests, 'rooms/edit-message.php')).toHaveLength(1);
});

test('room: a live change to the message while it is being edited waits until the editor closes', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await startEditing(mine(page));
  await editBox(mine(page)).fill('Half-typed');
  await page.evaluate(() => window.__emit('message_reaction_changed', {
    message_kind: 'room', room_id: 1, message_id: 2, reactions: [{ emoji: '🎲', users: [{ id: 2, username: 'Rowan' }] }],
  }));
  await page.evaluate(() => window.__emit('room_message', { message: {
    id: 3, room_id: 1, sender_id: 2, username: 'Rowan', body: 'Nice!', type: 'text', deleted: false,
    mentions: [], reactions: [], reply_to: null, attachment: null, created_at: new Date().toISOString(),
  } }));
  await page.waitForTimeout(400);
  await expect(editBox(mine(page))).toHaveValue('Half-typed');
  await editBox(mine(page)).press('Escape');
  await expect(mine(page).locator('.message-body')).toHaveText('I can bring Catan.');
});

test('room: a message deleted elsewhere while being edited closes the editor and shows as deleted', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await startEditing(mine(page));
  await editBox(mine(page)).fill('Half-typed');
  // The server knows it's gone too, so the page's routine refresh agrees.
  saved[2] = { body: null, deleted: true, deletion_kind: 'moderator', can_edit: false, can_delete: false };
  await page.evaluate(() => window.__emit('message_deleted', { room_id: 1, message_id: 2 }));
  await expect(editBox(mine(page))).toHaveCount(0);
  await expect(mine(page)).toHaveClass(/deleted/);
  await expect(mine(page)).not.toContainText('Half-typed');
});

test('room: Delete asks in a styled dialog, Cancel first; deleting says so in the bubble', async ({ page }) => {
  let native = 0;
  page.on('dialog', (dialog) => { native += 1; void dialog.dismiss(); });
  const requests = await fixture(page);
  await openRoom(page);
  await mine(page).locator('.message-bubble').hover();
  await mine(page).getByRole('button', { name: 'Delete' }).click();
  const ask = page.getByRole('dialog', { name: 'Delete this message?' });
  await expect(ask).toContainText('It’s removed for everyone in the room. This can’t be undone.');
  await expect(ask.getByRole('button', { name: 'Cancel' })).toBeFocused();
  await ask.getByRole('button', { name: 'Cancel' }).click();
  await expect(ask).toHaveCount(0);
  expect(posted(requests, 'rooms/delete-own-message.php')).toHaveLength(0);

  await mine(page).locator('.message-bubble').hover();
  await mine(page).getByRole('button', { name: 'Delete' }).click();
  await page.getByRole('dialog', { name: 'Delete this message?' }).getByRole('button', { name: 'Delete message' }).click();
  await expect(mine(page).locator('.message-body')).toHaveText('Message deleted by its author.');
  expect(posted(requests, 'rooms/delete-own-message.php')).toEqual([{ path: 'rooms/delete-own-message.php', body: { message_id: 2 } }]);
  expect(native).toBe(0);
});

test('direct: edit in the bubble and delete in a styled dialog', async ({ page }) => {
  let native = 0;
  page.on('dialog', (dialog) => { native += 1; void dialog.dismiss(); });
  const requests = await fixture(page);
  await page.goto('/messages.php?user=2');
  await expect(myDirect(page).getByRole('button', { name: 'Edit' })).toBeAttached();
  await startEditing(myDirect(page));
  await editBox(myDirect(page)).fill('I can bring Catan tomorrow.');
  await editBox(myDirect(page)).press('Enter');
  await expect(myDirect(page).locator('.dm-message-body')).toHaveText('I can bring Catan tomorrow.');
  expect(posted(requests, 'direct-messages/edit.php').at(-1)?.body).toEqual({ message_id: 2, body: 'I can bring Catan tomorrow.' });
  await expect(myDirect(page).getByRole('button', { name: 'Edit' })).toBeFocused();

  await myDirect(page).getByRole('button', { name: 'Delete for everyone' }).click();
  const ask = page.getByRole('dialog', { name: 'Delete this direct message?' });
  await expect(ask).toContainText('It’s removed for both of you. This can’t be undone.');
  await expect(ask.getByRole('button', { name: 'Cancel' })).toBeFocused();
  await ask.getByRole('button', { name: 'Delete for both' }).click();
  await expect.poll(() => posted(requests, 'direct-messages/delete.php').length).toBe(1);
  expect(native).toBe(0);
});

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: the editor and the delete dialog pass axe at 1280 and 360`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page);
    for (const width of [1280, 360]) {
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/');
      // Phones open on the Rooms list (S2c): open the room as a person would.
      if (width < 768) await page.locator('#room-list .room-button').first().click();
      const scope = mine(page);
      await expect(scope.getByRole('button', { name: 'Edit' })).toBeAttached();
      await startEditing(scope);
      await page.waitForTimeout(250);
      let result = await new AxeBuilder({ page }).include('#message-list').withTags(WCAG).analyze();
      expect(result.violations, `editor ${width}: ${result.violations.map((violation) => violation.id).join(', ')}`).toEqual([]);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `editor ${width}`).toBe(true);
      await editBox(scope).press('Escape');
      await scope.locator('.message-bubble').hover();
      await scope.getByRole('button', { name: /^Delete/ }).click();
      await expect(page.getByRole('dialog')).toBeVisible();
      await page.waitForTimeout(250);
      result = await new AxeBuilder({ page }).include('dialog').withTags(WCAG).analyze();
      expect(result.violations, `dialog ${width}: ${result.violations.map((violation) => violation.id).join(', ')}`).toEqual([]);
      await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();
    }
  });
}
