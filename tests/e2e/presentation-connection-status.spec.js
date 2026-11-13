import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S7 (#173) status ladder, and #309 (Direct had no status line): Live is
// quiet; "Reconnecting…" after three seconds down (the timing is unit-tested
// in tests/js/connection-status.test.js, the restore path in
// presentation-stream-recovery); "Offline" whenever the browser has no
// network, and a send then says so honestly, keeping the draft. The line is
// not a live region. Presentation only: the real pages against intercepted APIs.
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const rooms = [{
  id: 1, name: 'The living room', unread_count: 0, key: 'room-0', visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'none',
}];
const OFFLINE = 'Offline. You can’t send until you’re back.';

async function fixture(page) {
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    // Offline emulation doesn't stop intercepted requests: a send fails as it would offline.
    if (path === 'rooms/send.php') return route.abort('internetdisconnected');
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: [] },
      'rooms/message-mutations.php': { messages: [] },
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
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

test('Rooms: Live, then Offline when the network goes, with an honest send failure that keeps the draft', async ({ page, context }) => {
  await fixture(page);
  await page.goto('/');
  const status = page.locator('#connection-status');
  await expect(status).toHaveText('Live');
  await expect(status).not.toHaveAttribute('aria-live', /./);

  await context.setOffline(true);
  await expect(status).toHaveText(OFFLINE);
  await expect(status).toHaveAttribute('data-state', 'offline');
  await page.locator('#composer-input').fill('Anyone around?');
  await page.locator('#composer-input').press('Enter');
  await expect(page.locator('#toast-region')).toContainText('You’re offline. Your message wasn’t sent.');
  await expect(page.locator('#composer-input')).toHaveValue('Anyone around?');

  await context.setOffline(false);
  await expect(status).not.toHaveText(OFFLINE);
});

test('Direct has the same status line (#309)', async ({ page, context }) => {
  await fixture(page);
  await page.goto('/messages.php?user=2');
  const status = page.locator('#dm-connection-status');
  await expect(status).toHaveText('Live');
  await expect(status).not.toHaveAttribute('aria-live', /./);
  await context.setOffline(true);
  await expect(status).toHaveText(OFFLINE);
  await context.setOffline(false);
  await expect(status).not.toHaveText(OFFLINE);
});

for (const [scheme, theme] of [['lantern', 'light'], ['lantern', 'dark'], ['dusk', 'dark']]) {
  test(`${scheme} ${theme}: the Offline line passes axe on Rooms and Direct, at 1280 and 360`, async ({ page, context }) => {
    await page.addInitScript(([chosenScheme, chosenTheme]) => {
      localStorage.setItem('chitchat-theme', chosenTheme);
      localStorage.setItem('chitchat-scheme', chosenScheme);
    }, [scheme, theme]);
    await fixture(page);
    for (const [path, id, pane] of [['/', '#connection-status', '#sidebar'], ['/messages.php', '#dm-connection-status', '#dm-list-pane']]) {
      for (const width of [1280, 360]) {
        await context.setOffline(false);
        await page.setViewportSize({ width, height: 800 });
        await page.goto(path);
        await expect(page.locator(id)).toHaveText('Live');
        await context.setOffline(true);
        await expect(page.locator(id)).toHaveText(OFFLINE);
        await page.mouse.move(0, 0);
        const result = await new AxeBuilder({ page }).include(pane).withTags(WCAG).analyze();
        expect(result.violations, `${path} ${width}: ${result.violations.map((violation) => violation.id).join(', ')}`).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${path} ${width}`).toBe(true);
      }
    }
    await context.setOffline(false);
  });
}
