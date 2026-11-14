import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// #299, S7 (#173): leaving a room from the web. "Leave # Room…" sits at the
// foot of the room's Members panel, asks in the shared decision dialog (a
// private room says getting back in needs a new invitation), and afterwards
// the next room of yours opens without a reload. The owner is told the way out
// (Make owner…) instead, also when the server answers 409 owner_cannot_leave.
// Presentation only: the real page against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const member = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1, username_change: null };
const room = (id, name, extra = {}) => ({
  id, name, key: `room-${id}`, visibility: 'public', member_role: 'member', minimum_age: 0,
  inactivity_timeout_seconds: 0, info_line: '', unread_count: 0, guest_access: 'none', ...extra,
});

async function fixture(page, { rooms, overrides = {} }) {
  let current = structuredClone(rooms);
  const requests = [];
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1/', '');
    if (request.method() === 'POST') requests.push({ path, body: request.postDataJSON() });
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path in overrides) {
      const value = overrides[path];
      return Number.isInteger(value?.status) ? route.fulfill({ status: value.status, json: value.json }) : route.fulfill({ json: value });
    }
    if (path === 'rooms/leave.php') {
      const { room_id: id } = request.postDataJSON();
      // A public room stays listed, to join again; a private one goes.
      current = current.flatMap((entry) => (entry.id !== id ? [entry] : entry.visibility === 'public' ? [{ ...entry, member_role: null }] : []));
      return route.fulfill({ json: { status: 'left' } });
    }
    const responses = {
      'session.php': { user: member, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms: current },
      'rooms/messages.php': { messages: [] },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 0 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return requests;
}

const left = (requests) => requests.filter((entry) => entry.path === 'rooms/leave.php');
const panel = (page) => page.locator('#presence-panel');

async function openPanel(page, name, width = 1280) {
  await page.setViewportSize({ width, height: 800 });
  await page.goto('/');
  if (width < 768) await page.locator('#room-list .room-button').first().click();
  await expect(page.locator('#room-title')).toContainText(name);
  await page.locator('#members-toggle').click();
  await expect(panel(page)).toBeVisible();
}

test('a member leaves a public room: asked first, Cancel first; then the next room of theirs opens', async ({ page }) => {
  let native = 0;
  page.on('dialog', (dialog) => { native += 1; void dialog.dismiss(); });
  const requests = await fixture(page, { rooms: [room(1, 'Garden'), room(2, 'Kitchen')] });
  await openPanel(page, 'Garden');
  const leave = panel(page).getByRole('button', { name: 'Leave # Garden…' });
  await leave.click();
  const ask = page.getByRole('dialog', { name: 'Leave # Garden?' });
  await expect(ask).toContainText('You stop getting its messages. You can join again from the room list any time.');
  await expect(ask.getByRole('button', { name: 'Cancel' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(ask).toHaveCount(0);
  expect(left(requests)).toHaveLength(0);

  await leave.click();
  await page.getByRole('dialog', { name: 'Leave # Garden?' }).getByRole('button', { name: 'Leave room' }).click();
  await expect(page.locator('#toast-region')).toContainText('You left # Garden.');
  expect(left(requests)).toEqual([{ path: 'rooms/leave.php', body: { room_id: 1 } }]);
  await expect(page.locator('#room-title')).toContainText('Kitchen');
  await expect(panel(page).getByRole('button', { name: 'Leave # Kitchen…' })).toBeVisible();
  expect(native).toBe(0);
});

test('a private room says getting back in needs a new invitation, and drops out of the list', async ({ page }) => {
  await fixture(page, { rooms: [room(1, 'Back room', { visibility: 'private' }), room(2, 'Kitchen')] });
  await openPanel(page, 'Back room');
  await panel(page).getByRole('button', { name: 'Leave # Back room…' }).click();
  const ask = page.getByRole('dialog', { name: 'Leave # Back room?' });
  await expect(ask).toContainText('You stop getting its messages. Getting back in needs a new invitation.');
  await ask.getByRole('button', { name: 'Leave room' }).click();
  await expect(page.locator('#room-title')).toContainText('Kitchen');
  await expect(page.locator('#room-list')).not.toContainText('Back room');
});

test('the owner is told the way out instead of being asked, and nothing is sent', async ({ page }) => {
  const requests = await fixture(page, { rooms: [room(1, 'Garden', { member_role: 'owner' })] });
  await openPanel(page, 'Garden');
  await panel(page).getByRole('button', { name: 'Leave # Garden…' }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(page.locator('#room-leave-note')).toHaveText('You own # Garden, so you can’t leave it yet. Make someone else the owner first: open their name and choose Make owner…');
  expect(left(requests)).toHaveLength(0);
});

test('if the server says the member has just become the owner (409), the same sentence explains', async ({ page }) => {
  await fixture(page, {
    rooms: [room(1, 'Garden')],
    overrides: { 'rooms/leave.php': { status: 409, json: { error: { code: 'owner_cannot_leave', message: 'The room owner cannot leave the room.' } } } },
  });
  await openPanel(page, 'Garden');
  await panel(page).getByRole('button', { name: 'Leave # Garden…' }).click();
  await page.getByRole('dialog', { name: 'Leave # Garden?' }).getByRole('button', { name: 'Leave room' }).click();
  await expect(page.locator('#room-leave-note')).toHaveText(/^You own # Garden, so you can’t leave it yet\./);
  await expect(page.locator('#toast-region')).not.toContainText('The room owner cannot leave the room.');
});

test('a room you haven’t joined has nothing to leave', async ({ page }) => {
  await fixture(page, { rooms: [room(1, 'Garden', { member_role: null })] });
  await openPanel(page, 'Garden');
  await expect(page.locator('#room-leave')).toBeHidden();
});

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: the panel's Leave, the owner note and the dialog pass axe at 1280 and 360`, async ({ page }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page, { rooms: [room(1, 'Garden', { member_role: 'owner' }), room(2, 'Kitchen')] });
    for (const width of [1280, 360]) {
      await openPanel(page, 'Garden', width);
      await panel(page).getByRole('button', { name: 'Leave # Garden…' }).click();
      await expect(page.locator('#room-leave-note')).not.toBeEmpty();
      await page.mouse.move(0, 0);
      await page.waitForTimeout(250);
      let result = await new AxeBuilder({ page }).include('#presence-panel').withTags(WCAG).analyze();
      expect(result.violations, `panel ${width}: ${result.violations.map((violation) => violation.id).join(', ')}`).toEqual([]);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `panel ${width}`).toBe(true);
    }
    // The dialog, for a room that isn't yours.
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/');
    await page.locator('#room-list .room-button', { hasText: 'Kitchen' }).click();
    await page.locator('#members-toggle').click();
    await panel(page).getByRole('button', { name: 'Leave # Kitchen…' }).click();
    await expect(page.getByRole('dialog', { name: 'Leave # Kitchen?' })).toBeVisible();
    await page.waitForTimeout(250);
    const result = await new AxeBuilder({ page }).include('dialog').withTags(WCAG).analyze();
    expect(result.violations, `dialog: ${result.violations.map((violation) => violation.id).join(', ')}`).toEqual([]);
  });
}

test('an empty room says what to do next: join it first, or say hello (S7)', async ({ page }) => {
  await fixture(page, { rooms: [room(1, 'Garden', { member_role: null }), room(2, 'Kitchen')] });
  await page.goto('/');
  await page.locator('#room-list .room-button', { hasText: 'Garden' }).click();
  await expect(page.locator('#empty-state')).toHaveText('No messages yet. Join the room to start the conversation.');
  await page.locator('#room-list .room-button', { hasText: 'Kitchen' }).click();
  await expect(page.locator('#empty-state')).toHaveText('No messages yet. Say hello to start the conversation.');
});
