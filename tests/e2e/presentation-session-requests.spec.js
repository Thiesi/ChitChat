import { expect, test } from '@playwright/test';

// #367: resizing across the phone, tablet and desktop breakpoints rewrites the chat shell's
// layout classes. The Register-tab watcher (admin-link.js) used to refetch session.php on every
// such change; a navigation that cut one off was reported by WebKit as a page error. Only
// signing in or out may ask again. Presentation only: the real page against intercepted APIs.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rooms = [{
  id: 1, name: 'The living room', unread_count: 0, key: 'room-0', visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'none',
}];

test('resizing across breakpoints asks the server for the session no more', async ({ page }) => {
  let sessionRequests = 0;
  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'session.php') sessionRequests += 1;
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
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/');
  await expect(page.locator('#chat-shell')).toBeVisible();
  await expect(page.locator('#composer-input')).toBeEditable();
  await page.waitForLoadState('networkidle');
  const settled = sessionRequests;

  for (const width of [900, 390, 320, 900, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    // Two frames, then a task: a watcher's MutationObserver callback and its setTimeout(0) have run.
    await page.evaluate(() => new Promise((resolve) => {
      requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(resolve, 50)));
    }));
  }
  expect(sessionRequests).toBe(settled);
});
