import { expect, test } from '@playwright/test';

// #334 (after #326): a file that's gone or can't be seen. The server answers its
// download with 404 (attachment_not_found) or 410 (deleted); a plain link or
// <img> can't read that. Now a preview says "This file isn't available any
// more." and stops linking to it, a download link says so instead of opening
// the server's JSON in the tab, and a file that's there still downloads.
// Presentation only: the real page against intercepted APIs.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const rooms = [{
  id: 1, name: 'The living room', unread_count: 0, key: 'room-0', visibility: 'public', member_role: 'member',
  minimum_age: 0, inactivity_timeout_seconds: 0, info_line: '', guest_access: 'none',
}];
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const message = (id, body) => ({
  id, room_id: 1, sender_id: 2, username: 'Rowan', body, type: 'text', deleted: false,
  mentions: [], reactions: [], reply_to: null, attachment: null, created_at: new Date(Date.UTC(2026, 10, 14, 9, id)).toISOString(),
});
const history = [message(1, 'The board'), message(2, 'Rules'), message(3, 'A broken preview'), message(4, 'Old notes')];
const attachments = [
  // 70: gone (an image, so its preview fails, then the check says why).
  { message_id: 1, id: 70, name: 'board.png', mime_type: 'image/png', size_bytes: 1000, sha256: 'a', previewable: true, width: 800, height: 600, preview: null },
  // 50: gone, not an image (only the download link).
  { message_id: 2, id: 50, name: 'rules.pdf', mime_type: 'application/pdf', size_bytes: 3000, sha256: 'b', previewable: false },
  // 80: still there, but its image doesn't decode: "Preview unavailable" stays.
  { message_id: 3, id: 80, name: 'scan.png', mime_type: 'image/png', size_bytes: 2000, sha256: 'c', previewable: true, width: 400, height: 300, preview: null },
  // 60: still there; it downloads.
  { message_id: 4, id: 60, name: 'notes.txt', mime_type: 'text/plain', size_bytes: 12, sha256: 'd', previewable: false },
];

async function fixture(page) {
  const downloads = [];
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'attachments/download.php') {
      // As the real endpoint (#357): GET and HEAD, the same status, and no body for HEAD.
      const method = route.request().method();
      if (method !== 'GET' && method !== 'HEAD') return route.fulfill({ status: 405, json: { error: { code: 'method_not_allowed' } } });
      const id = url.searchParams.get('id');
      downloads.push(`${method} ${id}`);
      const answer = (options) => route.fulfill(method === 'HEAD' ? { ...options, json: undefined, body: '' } : options);
      if (id === '70') return answer({ status: 404, json: { error: { code: 'attachment_not_found', message: 'Attachment not found.' } } });
      if (id === '50') return answer({ status: 410, json: { error: { code: 'attachment_deleted', message: 'Attachment deleted.' } } });
      if (id === '80') return answer({ contentType: 'image/png', body: 'not really a png' });
      return answer({ contentType: 'text/plain', headers: { 'Content-Disposition': 'attachment; filename="notes.txt"' }, body: 'hello there\n' });
    }
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: history },
      'rooms/message-mutations.php': { messages: [] },
      'attachments/metadata.php': { attachments },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 4 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
  return downloads;
}

const card = (page, id) => page.locator(`#message-list article[data-message-id="${id}"] .attachment-card`);

async function open(page) {
  const downloads = await fixture(page);
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(4);
  await expect(card(page, 4)).toHaveCount(1);
  return downloads;
}

test('a gone image’s preview says the file isn’t available, and stops linking to it', async ({ page }) => {
  await open(page);
  const box = card(page, 1).locator('.image-box');
  await expect(box.locator('.image-box-unavailable')).toHaveText('This file isn’t available any more.');
  await expect(box).not.toHaveAttribute('href', /./);
});

test('an image that’s there but doesn’t show keeps "Preview unavailable" and its link', async ({ page }) => {
  await open(page);
  const box = card(page, 3).locator('.image-box');
  await expect(box.locator('.image-box-unavailable')).toHaveText('Preview unavailable');
  await page.waitForTimeout(500);
  await expect(box.locator('.image-box-unavailable')).toHaveText('Preview unavailable');
  await expect(box).toHaveAttribute('href', /download\.php\?id=80/);
});

test('a gone file’s download link says so and the page stays put; a file that’s there downloads', async ({ page }) => {
  const downloads = await open(page);
  const before = page.url();
  await card(page, 2).getByRole('link', { name: 'rules.pdf' }).click();
  await expect(page.locator('#toast-region')).toContainText('This file isn’t available any more.');
  expect(page.url()).toBe(before);
  // The check is a HEAD (#357): a gone file is never fetched at all.
  expect(downloads).toContain('HEAD 50');
  expect(downloads).not.toContain('GET 50');
  await card(page, 1).getByRole('link', { name: 'board.png' }).click();
  await expect(page.locator('#toast-region')).toContainText('This file isn’t available any more.');

  // A file that's there: after its HEAD check, the browser asks for the file itself (a GET;
  // the server answers with a download). The request is what's engine-independent: a local
  // Windows WebKit build reports no download event even for a plain link.
  const fetched = page.waitForRequest((request) => request.method() === 'GET' && request.resourceType() === 'document' && /attachments\/download\.php\?id=60/.test(request.url()));
  await card(page, 4).getByRole('link', { name: 'notes.txt' }).click();
  await fetched;
  expect(page.url()).toBe(before);
  // Its check came first, as a HEAD (the GET itself is awaited above; the route may log it later).
  expect(downloads.find((entry) => entry.endsWith(' 60'))).toBe('HEAD 60');
});

