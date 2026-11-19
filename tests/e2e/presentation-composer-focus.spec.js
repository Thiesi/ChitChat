import { expect, test } from '@playwright/test';

// #322: a send that settles late used to put focus back in the message box
// unconditionally, pulling it from wherever the person had gone since (the
// emoji picker's button, in the Firefox flake). Now focus returns to the
// composer only when it is still there or was lost to <body>.
// Presentation only: the real pages against intercepted APIs. The request each
// send awaits after send.php (Rooms: the presence heartbeat; Direct: the
// conversation list) is held until the test releases it.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const rooms = [{
  id: 1, name: 'The living room', unread_count: 0, key: 'room-0', visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'none',
}];

async function fixture(page, heldPath) {
  const hold = { armed: false, release: () => {} };
  const released = new Promise((resolve) => { hold.release = resolve; });
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === heldPath && hold.armed) await released;
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: [] },
      'rooms/message-mutations.php': { messages: [] },
      'rooms/send.php': {},
      'attachments/metadata.php': { attachments: [] },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 0 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [{ user: rowan, unread_count: 0, last_message: { body: 'Hi', outgoing: false } }] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: [] },
      'direct-messages/send.php': {},
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return hold;
}

const pages = [
  { name: 'Rooms', url: '/', held: 'presence/heartbeat.php', composer: '#composer-wrap', input: '#composer-input', send: '#send-button' },
  { name: 'Direct', url: '/messages.php?user=2', held: 'direct-messages/conversations.php', composer: '#dm-composer', input: '#dm-message-input', send: '#dm-send' },
];

for (const where of pages) {
  test(`${where.name}: a late send leaves focus on the emoji button the person moved to`, async ({ page }) => {
    const hold = await fixture(page, where.held);
    await page.goto(where.url);
    const input = page.locator(where.input);
    const send = page.locator(where.send);
    await expect(input).toBeEditable();

    hold.armed = true;
    await input.fill('Hello');
    await input.press('Enter');
    await expect(send).toBeDisabled();

    const button = page.locator(where.composer).getByRole('button', { name: 'Insert emoji' });
    await button.click();
    await expect(page.getByRole('dialog', { name: 'Emoji' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(button).toBeFocused();

    hold.release();
    await expect(send).toBeEnabled();
    await expect(button).toBeFocused();
  });

  test(`${where.name}: focus lost to the page while sending returns to the message box`, async ({ page }) => {
    const hold = await fixture(page, where.held);
    await page.goto(where.url);
    const input = page.locator(where.input);
    const send = page.locator(where.send);
    await expect(input).toBeEditable();

    hold.armed = true;
    await input.fill('Hello');
    await input.press('Enter');
    await expect(send).toBeDisabled();
    await page.evaluate(() => (document.activeElement instanceof HTMLElement ? document.activeElement.blur() : undefined));
    expect(await page.evaluate(() => document.activeElement === document.body)).toBe(true);

    hold.release();
    await expect(send).toBeEnabled();
    await expect(input).toBeFocused();
  });
}
