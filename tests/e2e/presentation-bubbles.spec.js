import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// S1c (#166): the approved transcript look, plus #158's image box. Runs of one
// sender share one name and one pebble (identity hue) with the chit on the
// last bubble; your own messages sit at the end in the outgoing colour;
// actions appear on hover or focus only; images reserve their space from the
// server's width and height, use preview.url, and fall back once to the
// original, then say "Preview unavailable". Presentation only.
const viewer = { id: 1, username: 'Jamie', roles: ['user'], session_version: 1 };
const rowan = { id: 2, username: 'Rowan' };
const priya = { id: 3, username: 'Priya' };
const rooms = [{
  id: 1, name: 'The living room', unread_count: 0, last_read_message_id: 9, key: 'room-0', visibility: 'public',
  member_role: 'member', minimum_age: 0, inactivity_timeout_seconds: 0, info_line: 'Everyday talk.', guest_access: 'none',
}];
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

function roomMessage(id, sender, body, extra = {}) {
  return {
    id, room_id: 1, sender_id: sender.id, username: sender.username, body, type: 'text', deleted: false,
    mentions: [], reactions: [], reply_to: null, attachment: null,
    created_at: new Date(Date.UTC(2026, 9, 4, 8, 0, 0) + id * 60_000).toISOString(), ...extra,
  };
}

const history = [
  roomMessage(1, rowan, 'Heads-up: game night moves to Saturday.'),
  roomMessage(2, rowan, 'Same time, back room as usual.'),
  roomMessage(3, priya, 'Saturday works.'),
  roomMessage(4, viewer, 'I can bring Catan.'),
  roomMessage(5, viewer, 'And the Seafarers expansion.'),
  roomMessage(7, priya, ''),
  roomMessage(8, rowan, ''),
  roomMessage(9, priya, ''),
];

const attachments = [
  { message_id: 7, id: 70, name: 'board.jpg', mime_type: 'image/jpeg', size_bytes: 240000, sha256: 'a', previewable: true, width: 1600, height: 900,
    preview: { url: '/api/v1/attachments/download.php?id=70&variant=preview', width: 1024, height: 576, mime_type: 'image/webp', size_bytes: 42000, sha256: 'b' } },
  { message_id: 8, id: 80, name: 'dice.gif', mime_type: 'image/gif', size_bytes: 90000, sha256: 'c', previewable: true, width: 400, height: 400,
    preview: { url: '/api/v1/attachments/download.php?id=80&variant=preview', width: 400, height: 400, mime_type: 'image/webp', size_bytes: 9000, sha256: 'd' } },
  { message_id: 9, id: 90, name: 'old-scan.png', mime_type: 'image/png', size_bytes: 120000, sha256: 'e', previewable: true, width: null, height: null, preview: null },
];

async function fixture(page) {
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1/', '');
    if (path === 'events/stream.php') return route.fulfill({ contentType: 'text/event-stream', body: 'retry: 600000\n\n' });
    if (path === 'avatars/show.php') return route.fulfill({ status: 404, body: '' });
    if (path === 'attachments/download.php') {
      const id = url.searchParams.get('id');
      const preview = url.searchParams.get('variant') === 'preview';
      // 70: the rendition is missing, the original works. 80: the rendition works. 90: nothing loads.
      if ((id === '70' && preview) || id === '90') return route.fulfill({ status: 404, json: { error: { code: 'attachment_preview_unavailable' } } });
      return route.fulfill({ contentType: 'image/png', body: PNG });
    }
    const responses = {
      'session.php': { user: viewer, csrf_token: 'fixture', registration_enabled: true, sign_in_providers: [] },
      'rooms/list.php': { rooms },
      'rooms/messages.php': { messages: history },
      'rooms/message-mutations.php': { messages: history.map((message) => ({ ...message, can_edit: message.sender_id === 1, can_delete: message.sender_id === 1, can_moderate: false })) },
      'attachments/metadata.php': { attachments },
      'presence/heartbeat.php': { presence: { room_id: 1, expired: false } },
      'rooms/presence.php': { users: [] },
      'rooms/mentionable-users.php': { users: [] },
      'rooms/pings.php': { pings: [] },
      'rooms/read.php': { last_read_message_id: 9 },
      'account/notifications/list.php': { notifications: [], unread_count: 0 },
      'direct-messages/conversations.php': { conversations: [{ user: rowan, unread_count: 0, last_message: { body: 'Hi', outgoing: false } }] },
      'direct-messages/block-status.php': { relationship: { blocked_by_me: false, messaging_available: true } },
      'direct-messages/history.php': { messages: history.slice(0, 5).map((message) => ({
        id: message.id, body: message.body, created_at: message.created_at, mentions: [], reactions: [], reply_to: null,
        sender: { id: message.sender_id, username: message.username }, recipient: message.sender_id === 1 ? rowan : viewer,
        outgoing: message.sender_id === 1, read_at: null,
      })) },
      'direct-messages/read.php': {},
      'direct-messages/attachments/metadata.php': { attachments: [] },
      'direct-messages/message-mutations.php': { messages: [] },
    };
    await route.fulfill({ json: responses[path] ?? {} });
  });
}

const message = (page, id) => page.locator(`#message-list article[data-message-id="${id}"]`);

async function openRoom(page) {
  await page.goto('/');
  await expect(page.locator('#message-list article.message')).toHaveCount(8);
  await expect(message(page, 9).locator('.attachment-card')).toHaveCount(1);
}

test('runs share one name and one pebble; own messages sit at the end; the chit marks a run’s last bubble', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  await expect(message(page, 1)).toHaveAttribute('data-run', 'start');
  await expect(message(page, 2)).toHaveAttribute('data-run', 'end');
  await expect(message(page, 3)).toHaveAttribute('data-run', 'single');
  await expect(message(page, 1).locator('.message-author')).toBeVisible();
  await expect(message(page, 2).locator('.message-author')).toBeHidden();
  await expect(message(page, 1).locator('.message-avatar')).toHaveCSS('visibility', 'hidden');
  await expect(message(page, 2).locator('.message-avatar')).toHaveCSS('visibility', 'visible');
  await expect(message(page, 2).locator('.message-avatar')).toHaveAttribute('data-hue', '2');
  await expect(message(page, 4)).toHaveClass(/\bmine\b/);
  await expect(message(page, 4).locator('.message-author')).toBeHidden();
  const chit = (id) => message(page, id).locator('.message-bubble').evaluate((node) => getComputedStyle(node, '::after').content);
  expect(await chit(1)).toBe('none');
  expect(await chit(2)).toBe('""');
  expect(await chit(5)).toBe('""');
});

test('actions appear on hover; the toolbar’s React opens the picker', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  const reply = message(page, 3).locator('.message-reply-button');
  await expect(reply).toHaveCSS('opacity', '0');
  await message(page, 3).locator('.message-bubble').hover();
  await expect(reply).toHaveCSS('opacity', '1');
  await message(page, 3).locator('.message-react-button').click();
  await expect(message(page, 3).locator('.reaction-picker')).toBeVisible();
  // Review item 5: focus moves into the picker.
  await expect(message(page, 3).locator('.reaction-picker button').first()).toBeFocused();
});

test('images reserve their space, use the rendition, and fall back once to the original', async ({ page }) => {
  await fixture(page);
  await openRoom(page);
  const sized = message(page, 7).locator('.image-box');
  await expect(sized).toHaveAttribute('data-sized', 'true');
  const box = await sized.boundingBox();
  expect(Math.abs(box.width / box.height - 1600 / 900)).toBeLessThan(0.05);
  // The rendition 404s, so the original's inline link is used instead.
  await expect(sized.locator('img')).toHaveAttribute('src', /id=70&inline=1$/);
  await expect(message(page, 7).locator('.message-bubble .attachment-card')).toHaveCount(1);

  const gif = message(page, 8).locator('.image-box');
  await expect(gif.locator('img')).toHaveAttribute('src', /variant=preview/);
  await expect(gif.locator('.image-box-label')).toHaveText('GIF');
  await expect(gif).toHaveAttribute('aria-label', 'dice.gif, animated GIF, opens in a new tab');
  // Review item 2: an image's message names the file, not the preview's "GIF" label.
  await expect(message(page, 8)).toHaveAccessibleName(/ File: dice\.gif\.$/);
  await expect(message(page, 7)).toHaveAccessibleName(/ File: \S+\.\w+\.$/);

  const missing = message(page, 9).locator('.image-box');
  await expect(missing).not.toHaveAttribute('data-sized', /.*/);
  await expect(missing.locator('.image-box-unavailable')).toHaveText('Preview unavailable');
  const unsized = await missing.boundingBox();
  expect(Math.abs(unsized.width / unsized.height - 4 / 3)).toBeLessThan(0.05);

  // Review item 8: a rendition URL must be a path on this server.
  const accepted = await page.evaluate(async () => {
    const { isSameOriginPath } = await import('/assets/js/image-box.js');
    return ['/api/v1/x.png', '//evil.example/x.png', '/\\evil.example/x.png', 'https://evil.example/x.png', null].map(isSameOriginPath);
  });
  expect(accepted).toEqual([true, false, false, false, false]);
});

test('direct messages use the same bubbles', async ({ page }) => {
  await fixture(page);
  await page.goto('/messages.php?user=2');
  const list = page.locator('#dm-message-list');
  await expect(list.locator('article.dm-message .message-bubble')).toHaveCount(5);
  await expect(list.locator('article[data-message-id="4"]')).toHaveClass(/outgoing/);
  await expect(list.locator('article[data-message-id="1"]')).toHaveAttribute('data-run', 'start');
  await expect(list.locator('article[data-message-id="1"] .message-header')).toHaveCSS('opacity', '0');
});

for (const theme of ['light', 'dark']) {
  test(`${theme}: bubbles pass axe and reflow at 320px`, async ({ page }) => {
    await page.addInitScript((choice) => localStorage.setItem('chitchat-theme', choice), theme);
    await fixture(page);
    await page.setViewportSize({ width: 320, height: 720 });
    await openRoom(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    const result = await new AxeBuilder({ page }).include('#message-list').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations).toEqual([]);
  });
}

test('right-to-left: the transcript renders without overflow', async ({ page }) => {
  await page.addInitScript(() => document.addEventListener('DOMContentLoaded', () => document.documentElement.setAttribute('dir', 'rtl')));
  await fixture(page);
  await openRoom(page);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => window.innerWidth));
  await expect(message(page, 3).locator('.message-bubble')).toBeVisible();
});
